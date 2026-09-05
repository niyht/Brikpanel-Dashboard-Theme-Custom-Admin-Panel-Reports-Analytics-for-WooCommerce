<?php
/**
 * BrikPanel — Store Summary
 *
 * Generates a comprehensive but concise Markdown digest of every analytics
 * surface the plugin tracks (sales, products, customers, RFM, cohort,
 * funnel, devices, coupons, expenses, profitability) so the user can paste
 * the result into an LLM, hand it to a data analyst, or share with an
 * investor.
 *
 * Strictly on-demand: only computes when the dashboard "Copy everything"
 * button fires the AJAX call. No cron, no warm cache.
 *
 * @package BrikPanel
 * @since   2.6.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Brikpanel_Store_Summary {

	const NONCE_ACTION = 'brikpanel_dashboard_nonce';

	/**
	 * Side-effect bag populated by section_*() methods so the TL;DR block
	 * (rendered first but computed last) can read headline numbers. Keys are
	 * documented in section_tldr().
	 *
	 * @var array<string, mixed>
	 */
	private $tldr_inputs = [];

	/** Per-request memo for tracking_start_date(). */
	private $tracking_start_date_cached = null;

	/** Per-request memo for currencies_in_use(). */
	private $currencies_cached = [];

	/** Per-request memo for customer_aggregates(). */
	private $customer_aggregates_cached = null;

	public function __construct() {
		add_action( 'wp_ajax_brikpanel_generate_store_summary', [ $this, 'ajax_generate' ] );
	}

	// =========================================================================
	// AJAX ENTRY
	// =========================================================================

	public function ajax_generate() {
		check_ajax_referer( self::NONCE_ACTION, 'security' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'brikpanel' ) ], 403 );
		}

		// Larger stores can take a few seconds to aggregate. Don't kill the
		// request mid-flight on shared hosts with strict execution caps.
		@set_time_limit( 60 );

		$markdown = $this->build_markdown();

		wp_send_json_success( [
			'markdown'     => $markdown,
			'generated_at' => gmdate( 'c' ),
			'bytes'        => strlen( $markdown ),
		] );
	}

	// =========================================================================
	// MASTER BUILDER
	// =========================================================================

	private function build_markdown() {
		// Reset side-effect register at the start of every build (the same
		// instance lives across AJAX dispatch + class autoload, so a fresh
		// state guards against test-runner replays).
		$this->tldr_inputs = [];

		// Two-pass: render every other section first so they can register
		// TL;DR inputs, then prepend a freshly formatted TL;DR.
		//
		// The list is in FINAL DISPLAY ORDER — TL;DR is the only thing spliced
		// in afterwards. Sections run through safe_section(): a store where one
		// subsystem is broken or missing still gets every other section instead
		// of a failed copy with nothing in the clipboard.
		$section_methods = [
			'identity'                  => 'section_identity',
			'data_quality'              => 'section_data_quality',
			'catalog'                   => 'section_catalog_composition',
			'sales_periods'             => 'section_sales_periods',
			'yearly'                    => 'section_yearly_sales',
			'monthly'                   => 'section_monthly_sales',
			'new_vs_returning'          => 'section_new_vs_returning',
			'best_worst_times'          => 'section_best_worst_times',
			'order_status'              => 'section_order_status',
			'failed_orders'             => 'section_failed_orders',
			'refund_metrics'            => 'section_refund_metrics',
			'top_products'              => 'section_top_products',
			'category_profit'           => 'section_category_profit',
			'top_customers'             => 'section_top_customers',
			'customer_concentration'    => 'section_customer_concentration',
			'repeat_purchase_rate'      => 'section_repeat_purchase_rate',
			'time_to_first_purchase'    => 'section_time_to_first_purchase',
			'rfm'                       => 'section_rfm_segments',
			'cohort'                    => 'section_cohort_retention',
			'funnel'                    => 'section_funnel',
			'cart_abandonment'          => 'section_cart_abandonment',
			'devices'                   => 'section_devices',
			'geography'                 => 'section_geography',
			'shipping'                  => 'section_shipping',
			'order_attribution'         => 'section_order_attribution',
			'coupons'                   => 'section_coupons',
			'coupon_performance'        => 'section_coupon_performance',
			'ad_spend'                  => 'section_ad_spend',
			'expenses'                  => 'section_expenses',
			'profitability'             => 'section_profitability',
			'subscriptions'             => 'section_subscriptions',
			'customer_lifespan'         => 'section_customer_lifespan',
			'modules'                   => 'section_modules',
		];

		$parts = [];
		foreach ( $section_methods as $key => $method ) {
			$parts[ $key ] = $this->safe_section( $method );
		}

		// TL;DR is computed last (so every register_tldr() has fired) but
		// inserted right after the identity card so the reader sees the
		// headline numbers immediately.
		$tldr = $this->safe_section( 'section_tldr' );
		$ordered = [];
		foreach ( $parts as $key => $body ) {
			if ( 'data_quality' === $key && $tldr !== '' ) {
				$ordered['tldr'] = $tldr;
			}
			$ordered[ $key ] = $body;
		}
		if ( ! isset( $ordered['tldr'] ) && $tldr !== '' ) {
			$ordered['tldr'] = $tldr;
		}

		// Filter empty sections, join with blank line.
		$ordered = array_filter( $ordered, function ( $p ) { return is_string( $p ) && $p !== ''; } );
		return implode( "\n\n", $ordered ) . "\n";
	}

	/**
	 * Run one section, swallowing anything it throws.
	 *
	 * This report reaches into a dozen optional subsystems (ad platforms,
	 * expenses, subscriptions, the tracking tables, third-party cost plugins).
	 * Before this guard a single missing function anywhere in that surface
	 * turned the whole "Copy everything" click into "Failed — try again" and
	 * the merchant got an empty clipboard, losing thirty working sections to
	 * one broken one.
	 *
	 * @param string $method Private section method name.
	 * @return string Markdown, or '' when the section failed or had no data.
	 */
	private function safe_section( $method ) {
		try {
			$body = $this->{$method}();
			return is_string( $body ) ? $body : '';
		} catch ( Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[BrikPanel] Store summary section ' . $method . ' failed: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
			return '';
		}
	}

	// =========================================================================
	// HELPERS
	// =========================================================================

	private function is_hpos() {
		static $hpos = null;
		if ( null === $hpos ) {
			$hpos = get_option( 'woocommerce_custom_orders_table_enabled' ) === 'yes';
		}
		return $hpos;
	}

	private function currency_code() {
		return function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'USD';
	}

	private function currency_symbol() {
		return function_exists( 'get_woocommerce_currency_symbol' )
			? html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' )
			: '$';
	}

	/**
	 * Format an amount as `<symbol><number>` for compact, copy-paste-friendly
	 * Markdown. Avoids HTML entities like wc_price() emits.
	 */
	private function money( $amount ) {
		$amount = (float) $amount;
		$decimals = function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 2;
		return $this->currency_symbol() . number_format_i18n( $amount, $decimals );
	}

	private function pct( $part, $whole, $decimals = 1 ) {
		$whole = (float) $whole;
		if ( $whole <= 0 ) {
			return '0%';
		}
		return number_format_i18n( ( (float) $part / $whole ) * 100, $decimals ) . '%';
	}

	/**
	 * Escape pipe characters that would break Markdown table cells.
	 *
	 * Values also get their HTML entities decoded first: term names, product
	 * titles and coupon codes are stored encoded ("Home &amp; Living"), and
	 * this report is plain text, so the raw entity would be read literally by
	 * whoever (or whatever) the Markdown is pasted into.
	 */
	private function md_cell( $value ) {
		$value = html_entity_decode( (string) $value, ENT_QUOTES, 'UTF-8' );
		$value = str_replace( [ "\n", "\r", "|" ], [ ' ', '', '\\|' ], $value );
		return trim( $value );
	}

	/**
	 * GMT date string for "now - $months months, midnight". Used as $start_date_gmt
	 * for brikpanel_get_total_revenue() etc. Returns null for "all time".
	 */
	private function months_ago_gmt( $months ) {
		if ( $months === null ) {
			return null;
		}
		$ts = strtotime( '-' . (int) $months . ' months', current_time( 'timestamp', true ) );
		return gmdate( 'Y-m-d 00:00:00', $ts );
	}

	private function days_ago_gmt( $days ) {
		$ts = strtotime( '-' . (int) $days . ' days', current_time( 'timestamp', true ) );
		return gmdate( 'Y-m-d 00:00:00', $ts );
	}

	private function today_start_gmt() {
		// Today in site timezone, converted to GMT.
		return get_gmt_from_date( wp_date( 'Y-m-d 00:00:00' ) );
	}

	private function now_gmt() {
		return gmdate( 'Y-m-d H:i:s' );
	}

	// =========================================================================
	// V2 HELPERS — tracking, multi-currency, deltas, sparklines, TL;DR register
	// =========================================================================

	/**
	 * Earliest day BrikPanel started recording analytics — returns a
	 * `Y-m-d` date or null when no rows exist. Used to (a) caption the
	 * report and (b) clamp the conversion funnel window so its denominator
	 * doesn't include orders from before tracking began.
	 *
	 * Cached per-request: callers in 4–5 sections all hit this.
	 */
	private function tracking_start_date() {
		if ( $this->tracking_start_date_cached !== null ) {
			return $this->tracking_start_date_cached === false ? null : $this->tracking_start_date_cached;
		}
		global $wpdb;
		$date = $wpdb->get_var( "SELECT MIN(date_column) FROM {$wpdb->prefix}brikpanel_visitors" ); // phpcs:ignore
		$this->tracking_start_date_cached = $date ?: false;
		return $date ?: null;
	}

	/**
	 * Whether WooCommerce Subscriptions is loaded AND active. The plugin's
	 * autoloader can satisfy `class_exists('WC_Subscriptions')` even after
	 * deactivation, so check `is_plugin_active()` first.
	 */
	private function is_subscriptions_active() {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( is_plugin_active( 'woocommerce-subscriptions/woocommerce-subscriptions.php' ) ) {
			return true;
		}
		return class_exists( 'WC_Subscriptions' ) && post_type_exists( 'shop_subscription' );
	}

	/**
	 * Detect "subscription-aware" mode: either the dedicated WC Subscriptions
	 * plugin is active, OR the catalog uses period markers in product names
	 * (e.g. "Premium - Yıllık", "Pro Monthly"). The latter covers stores like
	 * Brksoft that sell renewing access without WC Subs installed — which
	 * happens to be the majority of "SaaS on WooCommerce" merchants.
	 *
	 * @return array{enabled: bool, source: string}
	 */
	private function subscription_mode() {
		if ( $this->is_subscriptions_active() ) {
			return [ 'enabled' => true, 'source' => 'wc_subscriptions' ];
		}
		// Heuristic: scan for period markers in product names. A single match
		// is enough — the section's own queries handle empty-result safely.
		global $wpdb;
		$found = (int) $wpdb->get_var(
			"SELECT 1 FROM {$wpdb->posts}
			 WHERE post_type='product' AND post_status='publish'
			   AND ( post_title LIKE '%Yıllık%' COLLATE utf8mb4_general_ci
			      OR post_title LIKE '%Yıllik%' COLLATE utf8mb4_general_ci
			      OR post_title LIKE '%Aylık%'  COLLATE utf8mb4_general_ci
			      OR post_title LIKE '%Aylik%'  COLLATE utf8mb4_general_ci
			      OR post_title LIKE '%Yearly%' COLLATE utf8mb4_general_ci
			      OR post_title LIKE '%Annual%' COLLATE utf8mb4_general_ci
			      OR post_title LIKE '%Monthly%' COLLATE utf8mb4_general_ci
			      OR post_title LIKE '%/year%' COLLATE utf8mb4_general_ci
			      OR post_title LIKE '%/yıl%'  COLLATE utf8mb4_general_ci
			      OR post_title LIKE '%/month%' COLLATE utf8mb4_general_ci
			      OR post_title LIKE '%/ay%'   COLLATE utf8mb4_general_ci )
			 LIMIT 1"
		); // phpcs:ignore
		if ( $found > 0 ) {
			return [ 'enabled' => true, 'source' => 'product_name_pattern' ];
		}
		return [ 'enabled' => false, 'source' => '' ];
	}

	/**
	 * Count of distinct customers (by email) with at least one paid order in
	 * the given window. Used by Identity + TL;DR to surface the
	 * active-vs-tracked distinction the user asked for.
	 */
	private function active_customers_count( $start_gmt, $end_gmt = null ) {
		global $wpdb;
		$end_gmt = $end_gmt ?: $this->now_gmt();
		if ( $this->is_hpos() ) {
			return (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(DISTINCT IFNULL(NULLIF(billing_email,''), CAST(customer_id AS CHAR)))
				 FROM {$wpdb->prefix}wc_orders
				 WHERE type='shop_order' AND status IN (" . brikpanel_paid_statuses_sql() . ")
				   AND date_created_gmt >= %s AND date_created_gmt <= %s",
				$start_gmt, $end_gmt
			) ); // phpcs:ignore
		}
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(DISTINCT IFNULL(NULLIF(pm_email.meta_value,''), pm_uid.meta_value))
			 FROM {$wpdb->posts} p
			 LEFT JOIN {$wpdb->postmeta} pm_email ON pm_email.post_id=p.ID AND pm_email.meta_key='_billing_email'
			 LEFT JOIN {$wpdb->postmeta} pm_uid   ON pm_uid.post_id=p.ID   AND pm_uid.meta_key='_customer_user'
			 WHERE p.post_type='shop_order' AND p.post_status IN (" . brikpanel_paid_statuses_sql() . ")
			   AND p.post_date_gmt >= %s AND p.post_date_gmt <= %s",
			$start_gmt, $end_gmt
		) ); // phpcs:ignore
	}

	/**
	 * Detect whether WooCommerce Order Attribution data is being captured.
	 * WC 8.5+ enables it by default; older installs lack the feature
	 * entirely. Cheap heuristic: a single matching meta row.
	 */
	private function is_order_attribution_active() {
		global $wpdb;
		if ( $this->is_hpos() ) {
			$exists = (int) $wpdb->get_var(
				"SELECT 1 FROM {$wpdb->prefix}wc_orders_meta
				 WHERE meta_key='_wc_order_attribution_source_type' LIMIT 1"
			); // phpcs:ignore
		} else {
			$exists = (int) $wpdb->get_var(
				"SELECT 1 FROM {$wpdb->postmeta}
				 WHERE meta_key='_wc_order_attribution_source_type' LIMIT 1"
			); // phpcs:ignore
		}
		return $exists > 0;
	}

	/**
	 * List of currencies actually used by paid orders in the given window.
	 * Cached per (start, end) tuple to avoid repeating the round-trip when
	 * Sales-by-Period iterates 8 windows.
	 *
	 * @return string[] e.g. ['TRY', 'USD']. Empty when no orders match.
	 */
	private function currencies_in_use( $start_gmt = null, $end_gmt = null ) {
		$key = (string) $start_gmt . '|' . (string) $end_gmt;
		if ( isset( $this->currencies_cached[ $key ] ) ) {
			return $this->currencies_cached[ $key ];
		}
		global $wpdb;
		if ( $this->is_hpos() ) {
			$sql = "SELECT DISTINCT currency FROM {$wpdb->prefix}wc_orders
			        WHERE type='shop_order' AND status IN (" . brikpanel_paid_statuses_sql() . ") AND currency <> ''";
			$args = [];
			if ( $start_gmt ) { $sql .= ' AND date_created_gmt >= %s'; $args[] = $start_gmt; }
			if ( $end_gmt )   { $sql .= ' AND date_created_gmt <= %s'; $args[] = $end_gmt; }
		} else {
			$sql = "SELECT DISTINCT pm.meta_value AS currency FROM {$wpdb->posts} p
			        LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id=p.ID AND pm.meta_key='_order_currency'
			        WHERE p.post_type='shop_order' AND p.post_status IN (" . brikpanel_paid_statuses_sql() . ")
			          AND pm.meta_value IS NOT NULL AND pm.meta_value <> ''";
			$args = [];
			if ( $start_gmt ) { $sql .= ' AND p.post_date_gmt >= %s'; $args[] = $start_gmt; }
			if ( $end_gmt )   { $sql .= ' AND p.post_date_gmt <= %s'; $args[] = $end_gmt; }
		}
		$rows = $args ? $wpdb->get_col( $wpdb->prepare( $sql, $args ) ) : $wpdb->get_col( $sql ); // phpcs:ignore
		$rows = array_values( array_filter( array_map( 'strval', (array) $rows ), 'strlen' ) );
		$this->currencies_cached[ $key ] = $rows;
		return $rows;
	}

	/**
	 * Per-currency revenue + order count in a window. Replaces the
	 * currency-blind brikpanel_get_total_revenue() / get_successful_order_count
	 * for any section that wants to be honest about mixed currencies.
	 *
	 * @return array<string, array{revenue: float, orders: int, aov: float}>
	 */
	private function revenue_by_currency( $start_gmt = null, $end_gmt = null ) {
		global $wpdb;
		if ( $this->is_hpos() ) {
			$sql = "SELECT currency, COALESCE(SUM(total_amount),0) AS revenue, COUNT(*) AS orders
			        FROM {$wpdb->prefix}wc_orders
			        WHERE type='shop_order' AND status IN (" . brikpanel_paid_statuses_sql() . ") AND currency <> ''";
			$args = [];
			if ( $start_gmt ) { $sql .= ' AND date_created_gmt >= %s'; $args[] = $start_gmt; }
			if ( $end_gmt )   { $sql .= ' AND date_created_gmt <= %s'; $args[] = $end_gmt; }
			$sql .= ' GROUP BY currency';
		} else {
			$sql = "SELECT pm_c.meta_value AS currency,
			               COALESCE(SUM(CAST(pm_t.meta_value AS DECIMAL(20,4))),0) AS revenue,
			               COUNT(p.ID) AS orders
			        FROM {$wpdb->posts} p
			        LEFT JOIN {$wpdb->postmeta} pm_t ON pm_t.post_id=p.ID AND pm_t.meta_key='_order_total'
			        LEFT JOIN {$wpdb->postmeta} pm_c ON pm_c.post_id=p.ID AND pm_c.meta_key='_order_currency'
			        WHERE p.post_type='shop_order' AND p.post_status IN (" . brikpanel_paid_statuses_sql() . ")";
			$args = [];
			if ( $start_gmt ) { $sql .= ' AND p.post_date_gmt >= %s'; $args[] = $start_gmt; }
			if ( $end_gmt )   { $sql .= ' AND p.post_date_gmt <= %s'; $args[] = $end_gmt; }
			$sql .= ' GROUP BY pm_c.meta_value';
		}
		$rows = $args ? $wpdb->get_results( $wpdb->prepare( $sql, $args ) ) : $wpdb->get_results( $sql ); // phpcs:ignore
		$out = [];
		foreach ( $rows as $r ) {
			$ccy = (string) $r->currency;
			if ( $ccy === '' ) { continue; }
			$rev = (float) $r->revenue;
			$ord = (int) $r->orders;
			$out[ $ccy ] = [
				'revenue' => $rev,
				'orders'  => $ord,
				'aov'     => $ord > 0 ? $rev / $ord : 0.0,
			];
		}
		return $out;
	}

	/**
	 * Render `[CCY => ['revenue'=>x,'orders'=>n,…]]` as a compact cell
	 * string: `"1,234 TRY · 56 USD"` (sorted by revenue desc, all-zero
	 * currencies dropped). When no rows, returns `—`.
	 */
	private function format_currency_cell( $by_ccy, $field = 'revenue', $decimals = null ) {
		if ( $decimals === null ) {
			$decimals = function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 2;
		}
		$parts = [];
		// Sort by descending field value so the largest currency leads.
		uasort( $by_ccy, function ( $a, $b ) use ( $field ) {
			$av = isset( $a[ $field ] ) ? (float) $a[ $field ] : 0;
			$bv = isset( $b[ $field ] ) ? (float) $b[ $field ] : 0;
			if ( $av == $bv ) { return 0; }
			return $bv > $av ? 1 : -1;
		} );
		foreach ( $by_ccy as $ccy => $vals ) {
			$v = isset( $vals[ $field ] ) ? (float) $vals[ $field ] : 0;
			if ( $v <= 0 && $field === 'revenue' ) { continue; }
			$parts[] = number_format_i18n( $v, $field === 'orders' ? 0 : $decimals ) . ' ' . $ccy;
		}
		return $parts ? implode( ' · ', $parts ) : '—';
	}

	/**
	 * Format a percentage delta `+12.3%` / `-4.1%` / `—` (when prev=0 and
	 * current=0) / `+∞` (when prev=0 but current>0). Used by Sales-by-Period.
	 */
	private function mom_yoy_delta_label( $current, $previous ) {
		$current  = (float) $current;
		$previous = (float) $previous;
		if ( $previous == 0 ) {
			if ( $current == 0 ) { return '—'; }
			return $current > 0 ? '+∞' : '-∞';
		}
		$delta = ( ( $current - $previous ) / $previous ) * 100;
		return ( $delta >= 0 ? '+' : '' ) . number_format_i18n( $delta, 1 ) . '%';
	}

	/**
	 * Map a 0–100 percentage to one of `▁▂▃▄▅▆▇█` for compact heatmap-ish
	 * sparklines that render in any monospace markdown viewer.
	 */
	private function unicode_spark( $pct ) {
		$pct = max( 0, min( 100, (float) $pct ) );
		$blocks = [ '▁', '▂', '▃', '▄', '▅', '▆', '▇', '█' ];
		$idx = (int) min( 7, floor( $pct / 12.5 ) );
		return $blocks[ $idx ];
	}

	/**
	 * Predicate: row is "all-zero" when every numeric field is zero. Used
	 * to filter dead years/months from yearly/monthly tables.
	 */
	private function is_zero_row( array $numeric_values ) {
		foreach ( $numeric_values as $v ) {
			if ( (float) $v != 0 ) { return false; }
		}
		return true;
	}

	/**
	 * One-shot aggregate of the customer_metrics table — feeds 4 sections
	 * (concentration, repeat rate, lifespan, time-to-first) without
	 * re-querying. Returns null when the table is empty.
	 *
	 * @return array{
	 *   total: int, total_ltv: float, top1: float, top3: float, top5: float, top10: float,
	 *   repeat_count: int, avg_lifespan_days: float|null, ttf_avg_days: float|null,
	 *   ttf_sample: int
	 * }|null
	 */
	private function customer_aggregates() {
		if ( $this->customer_aggregates_cached !== null ) {
			return $this->customer_aggregates_cached === false ? null : $this->customer_aggregates_cached;
		}
		global $wpdb;
		$tbl = $wpdb->prefix . 'brikpanel_customer_metrics';
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tbl}" ); // phpcs:ignore
		if ( $total === 0 ) {
			$this->customer_aggregates_cached = false;
			return null;
		}

		// Headline stats in a single round-trip.
		$row = $wpdb->get_row( "SELECT
				COALESCE(SUM(total_spent),0) AS total_ltv,
				COALESCE(MAX(total_spent),0) AS top1,
				COUNT(CASE WHEN order_count >= 2 THEN 1 END) AS repeat_count,
				COALESCE(AVG(CASE WHEN order_count >= 2
				                   AND last_order_date IS NOT NULL
				                   AND first_order_date IS NOT NULL
				                   AND last_order_date > first_order_date
				              THEN DATEDIFF(last_order_date, first_order_date) END), 0) AS avg_lifespan_days
			FROM {$tbl}" ); // phpcs:ignore

		// Top-N share via window function (MySQL 8+ guaranteed by Customer
		// Analytics module already requiring it). Falls back to repeat
		// queries on older MySQL — but BrikPanel requires 8.0+ elsewhere.
		$top_rows = $wpdb->get_results(
			"SELECT total_spent FROM {$tbl} ORDER BY total_spent DESC LIMIT 10"
		); // phpcs:ignore
		$top3 = 0.0; $top5 = 0.0; $top10 = 0.0;
		foreach ( $top_rows as $i => $r ) {
			$v = (float) $r->total_spent;
			if ( $i < 3 )  { $top3  += $v; }
			if ( $i < 5 )  { $top5  += $v; }
			if ( $i < 10 ) { $top10 += $v; }
		}

		// Time-to-first-purchase (only valid for users with both registration
		// date and a first order date, and where the order came AFTER signup).
		$ttf = $wpdb->get_row(
			"SELECT
				AVG(DATEDIFF(m.first_order_date, u.user_registered)) AS avg_days,
				COUNT(*) AS sample
			 FROM {$tbl} m
			 INNER JOIN {$wpdb->users} u ON u.ID = m.user_id
			 WHERE m.user_id > 0
			   AND m.first_order_date IS NOT NULL
			   AND u.user_registered IS NOT NULL
			   AND m.first_order_date > u.user_registered"
		); // phpcs:ignore

		$out = [
			'total'             => $total,
			'total_ltv'         => (float) $row->total_ltv,
			'top1'              => (float) $row->top1,
			'top3'              => $top3,
			'top5'              => $top5,
			'top10'             => $top10,
			'repeat_count'      => (int) $row->repeat_count,
			'avg_lifespan_days' => (float) $row->avg_lifespan_days,
			'ttf_avg_days'      => $ttf && $ttf->sample > 0 ? (float) $ttf->avg_days : null,
			'ttf_sample'        => $ttf ? (int) $ttf->sample : 0,
		];
		$this->customer_aggregates_cached = $out;
		return $out;
	}

	/**
	 * Centralized data-source footnote registry. Used at the end of each
	 * section body (italic line) so a downstream reader knows where the
	 * numbers came from. Returning an empty string skips the footnote.
	 */
	private function footnote( $key ) {
		static $map = null;
		if ( $map === null ) {
			$map = [
				'wc_orders'        => __( 'Source: WooCommerce orders (statuses: processing, completed).', 'brikpanel' ),
				'wc_orders_all'    => __( 'Source: WooCommerce orders, all statuses.', 'brikpanel' ),
				'bp_visitors'      => __( 'Source: BrikPanel daily visitor rollup. Available only after tracking start date.', 'brikpanel' ),
				'bp_metrics'       => __( 'Source: BrikPanel customer_metrics table (recomputed nightly).', 'brikpanel' ),
				'bp_cohort'        => __( 'Source: BrikPanel cohort_retention table (recomputed nightly).', 'brikpanel' ),
				'wc_attribution'   => __( 'Source: WooCommerce Order Attribution (introduced in WC 8.5).', 'brikpanel' ),
				'wc_subscriptions' => __( 'Source: WooCommerce Subscriptions plugin.', 'brikpanel' ),
				'wc_addresses'     => __( 'Source: order shipping addresses (HPOS wc_order_addresses or postmeta on legacy).', 'brikpanel' ),
				'wc_op_data'       => __( 'Source: HPOS wc_order_operational_data — captures fulfillment timestamps and origin (created_via).', 'brikpanel' ),
				'bp_expenses'      => __( 'Source: BrikPanel expenses table (manually entered by the merchant).', 'brikpanel' ),
				'wc_coupons'       => __( 'Source: WooCommerce shop_coupon posts and order line items of type=coupon.', 'brikpanel' ),
				'bp_cogs'          => __( 'Source: product cost of goods — WooCommerce native COGS, BrikPanel\'s own cost field, or a detected third-party cost plugin. Resolved per line as the variation\'s cost with a fallback to the parent product, the same way the Dashboard profit card resolves it.', 'brikpanel' ),
				'bp_ads'           => __( 'Source: BrikPanel Ad Platforms — daily spend imported from the connected Google Ads / Meta Ads accounts. Account-level only: no campaign, ad-set, keyword or product breakdown is imported.', 'brikpanel' ),
			];
		}
		$msg = $map[ $key ] ?? '';
		return $msg ? '*' . $msg . '*' : '';
	}

	/**
	 * Side-effect register consumed by section_tldr() at the end of the
	 * build. Sections may register multiple keys; callers must keep keys
	 * stable since section_tldr() reads them by name.
	 */
	private function register_tldr( $key, $value ) {
		$this->tldr_inputs[ $key ] = $value;
	}

	/**
	 * Format two GMT dates into a [start, end] ISO pair for the conversion
	 * funnel, clamped to tracking_start_date so the denominator never
	 * counts pre-tracking days. Returns null when no overlap exists
	 * (window ends before tracking began).
	 *
	 * @return array{start: string, end: string}|null
	 */
	private function clamp_to_tracking_window( $start_date, $end_date ) {
		$start = $this->tracking_start_date();
		if ( $start === null ) { return null; }
		// $start_date and $end_date are 'Y-m-d' strings; tracking_start is too.
		$effective_start = ( $start_date && $start_date > $start ) ? $start_date : $start;
		if ( $effective_start > $end_date ) { return null; }
		return [ 'start' => $effective_start, 'end' => $end_date ];
	}

	// =========================================================================
	// SECTION: STORE IDENTITY
	// =========================================================================

	private function section_identity() {
		global $wp_version;
		$wc_version = defined( 'WC_VERSION' ) ? WC_VERSION : ( defined( 'WOOCOMMERCE_VERSION' ) ? WOOCOMMERCE_VERSION : __( 'unknown', 'brikpanel' ) );

		$site_name = get_bloginfo( 'name' );
		$site_url  = get_bloginfo( 'url' );
		$tagline   = get_bloginfo( 'description' );

		$timezone   = wp_timezone_string();
		$language   = get_locale();
		$generated  = wp_date( 'Y-m-d H:i' ) . ' (' . $timezone . ')';

		$country_setting = get_option( 'woocommerce_default_country', '' );
		$country = $country_setting ? explode( ':', $country_setting )[0] : '';

		$tax_inc = get_option( 'woocommerce_prices_include_tax', 'no' ) === 'yes';
		$calc_tax = get_option( 'woocommerce_calc_taxes', 'no' ) === 'yes';

		// First / last order dates + total counts (cheap roll-up)
		$bounds = $this->all_time_bounds();

		$lines = [];
		$lines[] = '# ' . sprintf( __( 'Store Summary — %s', 'brikpanel' ), $site_name );
		$lines[] = '';
		$lines[] = '> ' . sprintf( __( 'Generated %s by BrikPanel %s.', 'brikpanel' ), $generated, BRIKPANEL_VERSION );
		$lines[] = '';
		$lines[] = '## ' . __( 'Store Identity', 'brikpanel' );
		$lines[] = '- **' . __( 'Name', 'brikpanel' ) . ':** ' . $this->md_cell( $site_name );
		if ( $tagline ) {
			$lines[] = '- **' . __( 'Tagline', 'brikpanel' ) . ':** ' . $this->md_cell( $tagline );
		}
		$lines[] = '- **' . __( 'URL', 'brikpanel' ) . ':** ' . $this->md_cell( $site_url );
		$lines[] = '- **' . __( 'Locale', 'brikpanel' ) . ':** ' . $language . ' | **' . __( 'Timezone', 'brikpanel' ) . ':** ' . $timezone;
		$lines[] = '- **' . __( 'Currency', 'brikpanel' ) . ':** ' . $this->currency_code() . ' (' . $this->currency_symbol() . ')';
		if ( $country ) {
			$lines[] = '- **' . __( 'Default country', 'brikpanel' ) . ':** ' . $country;
		}
		$lines[] = '- **' . __( 'Taxes', 'brikpanel' ) . ':** ' . ( $calc_tax ? __( 'enabled', 'brikpanel' ) : __( 'disabled', 'brikpanel' ) ) . '; ' . ( $tax_inc ? __( 'prices include tax', 'brikpanel' ) : __( 'prices exclude tax', 'brikpanel' ) );
		$lines[] = '- **' . __( 'WooCommerce', 'brikpanel' ) . ':** ' . $wc_version . ' | **WordPress:** ' . $wp_version . ' | **PHP:** ' . PHP_VERSION . ' | **HPOS:** ' . ( $this->is_hpos() ? __( 'Yes', 'brikpanel' ) : __( 'No', 'brikpanel' ) );
		$lines[] = '- **BrikPanel:** ' . BRIKPANEL_VERSION;

		// Catalogue size
		$product_counts = wp_count_posts( 'product' );
		$published_products = isset( $product_counts->publish ) ? (int) $product_counts->publish : 0;
		$lines[] = '- **' . __( 'Published products', 'brikpanel' ) . ':** ' . number_format_i18n( $published_products );

		if ( $bounds ) {
			$lines[] = '- **' . __( 'First order', 'brikpanel' ) . ':** ' . $bounds['first'] . ' | **' . __( 'Last order', 'brikpanel' ) . ':** ' . $bounds['last'] . ' | **' . __( 'Lifetime span', 'brikpanel' ) . ':** ' . $bounds['span_label'];
			$active_12m = $this->active_customers_count( $this->months_ago_gmt( 12 ) );
			$active_30d = $this->active_customers_count( $this->days_ago_gmt( 30 ) );
			$total_customers = (int) $bounds['customers'];
			$lines[] = '- **' . __( 'Customers tracked (all-time)', 'brikpanel' ) . ':** ' . number_format_i18n( $total_customers )
				. ' | **' . __( 'active last 12m', 'brikpanel' ) . ':** ' . number_format_i18n( $active_12m )
				. ' (' . $this->pct( $active_12m, $total_customers ) . ')'
				. ' | **' . __( 'active last 30d', 'brikpanel' ) . ':** ' . number_format_i18n( $active_30d );
			$this->register_tldr( 'active_customers_12m', $active_12m );
			$this->register_tldr( 'active_customers_30d', $active_30d );
			$this->register_tldr( 'total_customers_alltime', $total_customers );
		}

		// Active currencies — surfaces multi-currency stores explicitly so
		// the reader knows the per-period tables aren't denominated in one
		// figure.
		$active_ccys = $this->currencies_in_use();
		if ( count( $active_ccys ) > 0 ) {
			$lines[] = '- **' . __( 'Active currencies on paid orders', 'brikpanel' ) . ':** ' . implode( ', ', $active_ccys ) . ( count( $active_ccys ) > 1 ? ' — *' . __( 'mixed-currency store; per-period tables show each currency separately, no conversion is applied', 'brikpanel' ) . '*' : '' );
		}

		// BrikPanel tracking start. Pre-tracking orders (from WooCommerce
		// alone) don't have funnel/device/visitor data attached.
		$track_start = $this->tracking_start_date();
		if ( $track_start ) {
			$lines[] = '- **' . __( 'BrikPanel analytics tracking active since', 'brikpanel' ) . ':** ' . $track_start . ' — *' . __( 'orders before this date come from WooCommerce only; visitor / funnel / device metrics apply to this date forward', 'brikpanel' ) . '*';
		} else {
			$lines[] = '- **' . __( 'BrikPanel analytics tracking', 'brikpanel' ) . ':** ' . __( 'no visitor data captured yet', 'brikpanel' ) . '*';
		}

		return implode( "\n", $lines );
	}

	/**
	 * Single-pass bounds query — earliest/latest order dates, distinct
	 * customer count. Used to caption the identity section and sized the
	 * monthly/yearly history windows.
	 *
	 * @return array{first: string, last: string, span_months: int, span_label: string, customers: int}|null
	 */
	private function all_time_bounds() {
		global $wpdb;
		if ( $this->is_hpos() ) {
			$row = $wpdb->get_row(
				"SELECT
					MIN(date_created_gmt) AS first_dt,
					MAX(date_created_gmt) AS last_dt
				 FROM {$wpdb->prefix}wc_orders
				 WHERE type = 'shop_order' AND status IN (" . brikpanel_paid_statuses_sql() . ")"
			); // phpcs:ignore
		} else {
			$row = $wpdb->get_row(
				"SELECT MIN(post_date_gmt) AS first_dt, MAX(post_date_gmt) AS last_dt
				 FROM {$wpdb->posts}
				 WHERE post_type = 'shop_order' AND post_status IN (" . brikpanel_paid_statuses_sql() . ")"
			); // phpcs:ignore
		}
		if ( ! $row || empty( $row->first_dt ) ) {
			return null;
		}

		$customers_table = $wpdb->prefix . 'brikpanel_customer_metrics';
		$customers = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$customers_table}" ); // phpcs:ignore

		$first_ts = strtotime( $row->first_dt );
		$last_ts  = strtotime( $row->last_dt );
		$months   = max( 1, (int) round( ( $last_ts - $first_ts ) / ( 30 * DAY_IN_SECONDS ) ) );
		$years    = floor( $months / 12 );
		$rem_m    = $months - $years * 12;
		$span     = $years > 0
			? sprintf( _n( '%dy %dm', '%dy %dm', $years, 'brikpanel' ), $years, $rem_m )
			: sprintf( _n( '%d month', '%d months', $months, 'brikpanel' ), $months );

		return [
			'first'       => mysql2date( 'Y-m-d', $row->first_dt ),
			'last'        => mysql2date( 'Y-m-d', $row->last_dt ),
			'span_months' => $months,
			'span_label'  => $span,
			'customers'   => $customers,
		];
	}

	// =========================================================================
	// SECTION: SALES BY PERIOD (today / yesterday / 7d / 30d / 90d / 12m / 24m / all-time)
	// =========================================================================

	private function section_sales_periods() {
		$now_gmt    = $this->now_gmt();
		$today_gmt  = $this->today_start_gmt();
		$y_start    = gmdate( 'Y-m-d 00:00:00', strtotime( '-1 day', strtotime( $today_gmt ) ) );

		// Each entry: label, current-window [start, end], previous-window [start, end].
		// Previous-window is the equivalent prior period for MoM/YoY delta. Today
		// has no meaningful "yesterday" companion (we already show yesterday as a
		// separate row), so its delta column shows the same yesterday revenue.
		$periods = [
			[ __( 'Today', 'brikpanel' ),         $today_gmt,                  $now_gmt,    $y_start,                          $today_gmt ],
			[ __( 'Yesterday', 'brikpanel' ),     $y_start,                    $today_gmt,  gmdate( 'Y-m-d 00:00:00', strtotime( '-2 days', strtotime( $today_gmt ) ) ), $y_start ],
			[ __( 'Last 7 days', 'brikpanel' ),   $this->days_ago_gmt( 7 ),    $now_gmt,    $this->days_ago_gmt( 14 ),         $this->days_ago_gmt( 7 ) ],
			[ __( 'Last 30 days', 'brikpanel' ),  $this->days_ago_gmt( 30 ),   $now_gmt,    $this->days_ago_gmt( 60 ),         $this->days_ago_gmt( 30 ) ],
			[ __( 'Last 90 days', 'brikpanel' ),  $this->days_ago_gmt( 90 ),   $now_gmt,    $this->days_ago_gmt( 180 ),        $this->days_ago_gmt( 90 ) ],
			[ __( 'Last 12 months', 'brikpanel' ), $this->months_ago_gmt( 12 ), $now_gmt,    $this->months_ago_gmt( 24 ),       $this->months_ago_gmt( 12 ) ],
			[ __( 'Last 24 months', 'brikpanel' ), $this->months_ago_gmt( 24 ), $now_gmt,    $this->months_ago_gmt( 48 ),       $this->months_ago_gmt( 24 ) ],
			[ __( 'All-time', 'brikpanel' ),       null,                        null,        null,                              null ],
		];

		$multi_ccy = count( $this->currencies_in_use() ) > 1;

		$lines = [];
		$lines[] = '## ' . __( 'Sales by Period', 'brikpanel' );
		if ( $multi_ccy ) {
			$lines[] = '*' . __( 'Multi-currency store — revenue and AOV columns list every active currency.', 'brikpanel' ) . '*';
			$lines[] = '';
		}
		$lines[] = '| ' . __( 'Period', 'brikpanel' ) . ' | ' . __( 'Revenue', 'brikpanel' ) . ' | ' . __( 'Orders', 'brikpanel' ) . ' | ' . __( 'AOV', 'brikpanel' ) . ' | ' . __( 'Δ vs prev', 'brikpanel' ) . ' |';
		$lines[] = '|---|---:|---:|---:|---:|';

		// For TL;DR we'll register Last 30 days revenue (per currency) and orders.
		foreach ( $periods as $cfg ) {
			list( $label, $start, $end, $prev_start, $prev_end ) = $cfg;

			$current = $this->revenue_by_currency( $start, $end );
			$cur_rev = 0.0; $cur_orders = 0;
			foreach ( $current as $r ) { $cur_rev += $r['revenue']; $cur_orders += $r['orders']; }

			$delta_label = '—';
			if ( $prev_start !== null ) {
				$prev = $this->revenue_by_currency( $prev_start, $prev_end );
				$prev_rev = 0.0;
				foreach ( $prev as $r ) { $prev_rev += $r['revenue']; }
				// Delta is across the *primary* currency only when multi-currency,
				// otherwise blended. In multi-currency we still compute on the
				// summed nominal — analyst sees the trend direction; we caveat in
				// the table preamble.
				$delta_label = $this->mom_yoy_delta_label( $cur_rev, $prev_rev );
			}

			$rev_cell = $multi_ccy ? $this->format_currency_cell( $current, 'revenue' ) : $this->money( $cur_rev );
			$aov_cell = $multi_ccy ? $this->format_currency_cell( $current, 'aov' )     : $this->money( $cur_orders > 0 ? $cur_rev / $cur_orders : 0 );

			$lines[] = '| ' . $label . ' | ' . $rev_cell . ' | ' . number_format_i18n( $cur_orders ) . ' | ' . $aov_cell . ' | ' . $delta_label . ' |';

			// Stash the headline number for TL;DR.
			if ( $start === $this->days_ago_gmt( 30 ) ) {
				$this->register_tldr( 'last_30d_revenue_cell', $rev_cell );
				$this->register_tldr( 'last_30d_orders', $cur_orders );
			}
			if ( $start === $this->months_ago_gmt( 12 ) ) {
				$this->register_tldr( 'last_12m_revenue_cell', $rev_cell );
				$this->register_tldr( 'last_12m_orders', $cur_orders );
			}
		}

		$fn = $this->footnote( 'wc_orders' );
		if ( $fn ) { $lines[] = ''; $lines[] = $fn; }

		return implode( "\n", $lines );
	}

	// =========================================================================
	// SECTION: YEARLY SALES (last 5 years + YoY)
	// =========================================================================

	private function section_yearly_sales() {
		global $wpdb;

		// Single grouped query: last 5 calendar years.
		$current_year = (int) gmdate( 'Y' );
		$start_year   = $current_year - 4;
		$start_dt     = $start_year . '-01-01 00:00:00';

		if ( $this->is_hpos() ) {
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT YEAR(date_created_gmt) AS y,
				        SUM(total_amount) AS revenue,
				        COUNT(*) AS orders
				 FROM {$wpdb->prefix}wc_orders
				 WHERE type='shop_order'
				   AND status IN (" . brikpanel_paid_statuses_sql() . ")
				   AND date_created_gmt >= %s
				 GROUP BY y ORDER BY y ASC",
				$start_dt
			) ); // phpcs:ignore
		} else {
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT YEAR(p.post_date_gmt) AS y,
				        SUM(pm.meta_value) AS revenue,
				        COUNT(p.ID) AS orders
				 FROM {$wpdb->posts} p
				 LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id=p.ID AND pm.meta_key='_order_total'
				 WHERE p.post_type='shop_order'
				   AND p.post_status IN (" . brikpanel_paid_statuses_sql() . ")
				   AND p.post_date_gmt >= %s
				 GROUP BY y ORDER BY y ASC",
				$start_dt
			) ); // phpcs:ignore
		}

		if ( empty( $rows ) ) {
			return '';
		}

		// Index by year + fill gaps so a missing year shows zeros for clarity.
		$by_year = [];
		foreach ( $rows as $r ) {
			$by_year[ (int) $r->y ] = [
				'revenue' => (float) $r->revenue,
				'orders'  => (int) $r->orders,
			];
		}

		$lines = [];
		$lines[] = '## ' . __( 'Yearly Sales (last 5 years)', 'brikpanel' );
		$lines[] = '| ' . __( 'Year', 'brikpanel' ) . ' | ' . __( 'Revenue', 'brikpanel' ) . ' | ' . __( 'Orders', 'brikpanel' ) . ' | ' . __( 'AOV', 'brikpanel' ) . ' | ' . __( 'YoY Δ Revenue', 'brikpanel' ) . ' |';
		$lines[] = '|---|---:|---:|---:|---:|';

		$prev_rev = null;
		$rendered_any = false;
		for ( $y = $start_year; $y <= $current_year; $y++ ) {
			$row = $by_year[ $y ] ?? [ 'revenue' => 0, 'orders' => 0 ];
			$rev = $row['revenue'];
			$ord = $row['orders'];

			// Hide rows where revenue + order count are both zero (rev 4) —
			// but only after the first rendered row (so leading dead years
			// disappear, internal zero years still show YoY context).
			if ( $this->is_zero_row( [ $rev, $ord ] ) && ! $rendered_any ) {
				continue;
			}
			$rendered_any = true;

			$aov = $ord > 0 ? $rev / $ord : 0;

			$yoy = $prev_rev === null ? '—' : $this->mom_yoy_delta_label( $rev, $prev_rev );

			$lines[] = '| ' . $y . ' | ' . $this->money( $rev ) . ' | ' . number_format_i18n( $ord ) . ' | ' . $this->money( $aov ) . ' | ' . $yoy . ' |';
			$prev_rev = $rev;
		}

		$fn = $this->footnote( 'wc_orders' );
		if ( $fn ) { $lines[] = ''; $lines[] = $fn; }

		return implode( "\n", $lines );
	}

	// =========================================================================
	// SECTION: MONTHLY SALES (last 24 months, compact)
	// =========================================================================

	private function section_monthly_sales() {
		global $wpdb;

		$start_dt = $this->months_ago_gmt( 24 );

		if ( $this->is_hpos() ) {
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT DATE_FORMAT(date_created_gmt, '%%Y-%%m') AS ym,
				        SUM(total_amount) AS revenue,
				        COUNT(*) AS orders
				 FROM {$wpdb->prefix}wc_orders
				 WHERE type='shop_order'
				   AND status IN (" . brikpanel_paid_statuses_sql() . ")
				   AND date_created_gmt >= %s
				 GROUP BY ym ORDER BY ym ASC",
				$start_dt
			) ); // phpcs:ignore
		} else {
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT DATE_FORMAT(p.post_date_gmt, '%%Y-%%m') AS ym,
				        SUM(pm.meta_value) AS revenue,
				        COUNT(p.ID) AS orders
				 FROM {$wpdb->posts} p
				 LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id=p.ID AND pm.meta_key='_order_total'
				 WHERE p.post_type='shop_order'
				   AND p.post_status IN (" . brikpanel_paid_statuses_sql() . ")
				   AND p.post_date_gmt >= %s
				 GROUP BY ym ORDER BY ym ASC",
				$start_dt
			) ); // phpcs:ignore
		}

		if ( empty( $rows ) ) {
			return '';
		}

		// Build full 24-month axis so missing months render as zero.
		$axis = [];
		for ( $i = 23; $i >= 0; $i-- ) {
			$ts  = strtotime( '-' . $i . ' months', current_time( 'timestamp', true ) );
			$key = gmdate( 'Y-m', $ts );
			$axis[ $key ] = [ 'revenue' => 0.0, 'orders' => 0 ];
		}
		foreach ( $rows as $r ) {
			$axis[ $r->ym ] = [ 'revenue' => (float) $r->revenue, 'orders' => (int) $r->orders ];
		}

		// Split: last 12 months render in detail, months 13–24 collapse into a
		// single summary line so the table stays scannable.
		$detailed_count = 12;
		$total_months   = count( $axis );
		$keys           = array_keys( $axis );
		$older_keys     = array_slice( $keys, 0, max( 0, $total_months - $detailed_count ) );
		$recent_keys    = array_slice( $keys, $total_months - $detailed_count );

		$older_rev = 0.0; $older_ord = 0; $older_nonzero = 0;
		foreach ( $older_keys as $k ) {
			$older_rev += $axis[ $k ]['revenue'];
			$older_ord += $axis[ $k ]['orders'];
			if ( ! $this->is_zero_row( [ $axis[ $k ]['revenue'], $axis[ $k ]['orders'] ] ) ) {
				$older_nonzero++;
			}
		}

		$lines = [];
		$lines[] = '## ' . __( 'Monthly Sales (last 24 months)', 'brikpanel' );
		$lines[] = '| ' . __( 'Month', 'brikpanel' ) . ' | ' . __( 'Revenue', 'brikpanel' ) . ' | ' . __( 'Orders', 'brikpanel' ) . ' | ' . __( 'AOV', 'brikpanel' ) . ' |';
		$lines[] = '|---|---:|---:|---:|';

		// Older summary row — only when we actually have older months to show.
		if ( ! empty( $older_keys ) && ! $this->is_zero_row( [ $older_rev, $older_ord ] ) ) {
			$older_aov = $older_ord > 0 ? $older_rev / $older_ord : 0;
			$older_label = sprintf( __( '%s → %s (%d months, %d active)', 'brikpanel' ), reset( $older_keys ), end( $older_keys ), count( $older_keys ), $older_nonzero );
			$lines[] = '| *' . $older_label . '* | ' . $this->money( $older_rev ) . ' | ' . number_format_i18n( $older_ord ) . ' | ' . $this->money( $older_aov ) . ' |';
		}

		// Strict collapse: hide every all-zero month (leading, interior, or
		// trailing). The implicit gap between consecutive shown months
		// already telegraphs "no activity in between" without spending row
		// space on empty data. Mirrors the behaviour of section_profitability().
		$shown = 0;
		foreach ( $recent_keys as $ym ) {
			$r = $axis[ $ym ];
			if ( $this->is_zero_row( [ $r['revenue'], $r['orders'] ] ) ) {
				continue;
			}
			$shown++;
			$aov = $r['orders'] > 0 ? $r['revenue'] / $r['orders'] : 0;
			$lines[] = '| ' . $ym . ' | ' . $this->money( $r['revenue'] ) . ' | ' . number_format_i18n( $r['orders'] ) . ' | ' . $this->money( $aov ) . ' |';
		}
		$total_recent = count( $recent_keys );
		if ( $shown < $total_recent ) {
			$lines[] = '';
			$lines[] = '*' . sprintf(
				/* translators: 1: months shown, 2: total months in detail window */
				__( 'Showing %1$d active month(s) out of %2$d in the recent-12 window — months with zero revenue and zero orders are hidden.', 'brikpanel' ),
				$shown,
				$total_recent
			) . '*';
		}

		$fn = $this->footnote( 'wc_orders' );
		if ( $fn ) { $lines[] = ''; $lines[] = $fn; }

		return implode( "\n", $lines );
	}

	// =========================================================================
	// SECTION: ORDER STATUS BREAKDOWN (all-time + last 12m)
	// =========================================================================

	private function section_order_status() {
		global $wpdb;

		// wc_get_order_statuses() already returns keys with the 'wc-' prefix
		// (e.g. 'wc-completed' => 'Completed'), so we can use it directly.
		$status_labels = function_exists( 'wc_get_order_statuses' ) ? wc_get_order_statuses() : [];

		$tbl = $this->is_hpos() ? $wpdb->prefix . 'wc_orders' : $wpdb->posts;

		if ( $this->is_hpos() ) {
			$all_time = $wpdb->get_results(
				"SELECT status, COUNT(*) AS cnt, COALESCE(SUM(total_amount),0) AS rev
				 FROM {$tbl} WHERE type='shop_order'
				 GROUP BY status"
			); // phpcs:ignore
		} else {
			$all_time = $wpdb->get_results(
				"SELECT p.post_status AS status, COUNT(p.ID) AS cnt, COALESCE(SUM(pm.meta_value),0) AS rev
				 FROM {$wpdb->posts} p
				 LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id=p.ID AND pm.meta_key='_order_total'
				 WHERE p.post_type='shop_order'
				 GROUP BY p.post_status"
			); // phpcs:ignore
		}

		if ( empty( $all_time ) ) {
			return '';
		}

		$total_orders = 0;
		foreach ( $all_time as $r ) {
			$total_orders += (int) $r->cnt;
		}

		$lines = [];
		$lines[] = '## ' . __( 'Order Status Breakdown (all-time)', 'brikpanel' );
		$lines[] = '| ' . __( 'Status', 'brikpanel' ) . ' | ' . __( 'Orders', 'brikpanel' ) . ' | ' . __( 'Share', 'brikpanel' ) . ' | ' . __( 'Revenue', 'brikpanel' ) . ' |';
		$lines[] = '|---|---:|---:|---:|';

		// Sort descending by count
		usort( $all_time, function ( $a, $b ) { return (int) $b->cnt - (int) $a->cnt; } );

		foreach ( $all_time as $r ) {
			$label = $status_labels[ $r->status ] ?? $r->status;
			$lines[] = '| ' . $this->md_cell( $label ) . ' | ' . number_format_i18n( $r->cnt ) . ' | ' . $this->pct( $r->cnt, $total_orders ) . ' | ' . $this->money( $r->rev ) . ' |';
		}

		$fn = $this->footnote( 'wc_orders_all' );
		if ( $fn ) { $lines[] = ''; $lines[] = $fn; }

		return implode( "\n", $lines );
	}

	// =========================================================================
	// SECTION: TOP PRODUCTS (last 12 months) — handles variations correctly
	// =========================================================================

	/**
	 * Per-product sales and cost for the report window, computed once.
	 *
	 * Both the Top Products table and the per-category profit table need the
	 * same thing — revenue, units and resolved cost per product — and each
	 * cost-resolving join over the period's order lines costs about half a
	 * second on a mid-size store. Running it once and pivoting in PHP halves
	 * that, and guarantees the two tables can never disagree.
	 *
	 * Rows are ordered by revenue descending. `costed_revenue` is the slice of
	 * the product's revenue whose line had a cost on file, which is what lets
	 * a caller tell "zero cost" apart from "no cost recorded".
	 *
	 * Capped at five thousand products by revenue: the whole set is held in
	 * PHP to be pivoted by category, and a catalogue that sold more distinct
	 * SKUs than that in a year has a tail no reader of this report will act on.
	 *
	 * @return array<int, object>|null Null when cost resolution is unavailable.
	 */
	private function product_profit_rows() {
		global $wpdb;

		static $rows = null;
		if ( null !== $rows ) {
			return false === $rows ? null : $rows;
		}

		$cost = $this->cogs_line_sql();
		if ( null === $cost ) {
			$rows = false;
			return null;
		}

		$w    = $this->profit_window();
		$pred = $this->paid_order_predicate( 'o' );
		$ord  = $this->is_hpos() ? "{$wpdb->prefix}wc_orders" : $wpdb->posts;
		$oid  = $this->is_hpos() ? 'o.id' : 'o.ID';

		// The `_product_id` meta on a variation line is the PARENT product id,
		// so grouping by it rolls variations up into their parent — while the
		// cost joins still resolve each line against its own variation. That
		// pairing is what makes the margin right on a variable product whose
		// variations carry different costs.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = "SELECT
					CAST(pid.meta_value AS UNSIGNED) AS product_id,
					SUM(CAST(qtym.meta_value AS DECIMAL(20,4))) AS qty,
					SUM(CAST(totm.meta_value AS DECIMAL(20,4))) AS revenue,
					SUM(CAST(qtym.meta_value AS DECIMAL(20,4)) * ({$cost['unit']})) AS cogs,
					SUM(CASE WHEN {$cost['has_cost']} THEN CAST(totm.meta_value AS DECIMAL(20,4)) ELSE 0 END) AS costed_revenue
				FROM {$wpdb->prefix}woocommerce_order_items oi
				INNER JOIN {$ord} o ON {$oid} = oi.order_id
				INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta qtym
						ON qtym.order_item_id = oi.order_item_id AND qtym.meta_key = '_qty'
				INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta totm
						ON totm.order_item_id = oi.order_item_id AND totm.meta_key = '_line_total'
				{$cost['joins']}
				WHERE oi.order_item_type = 'line_item'
				  AND CAST(pid.meta_value AS UNSIGNED) > 0
				  AND {$pred['where']}
				  AND {$pred['date_col']} >= %s AND {$pred['date_col']} <= %s
				GROUP BY product_id
				HAVING revenue > 0
				ORDER BY revenue DESC
				LIMIT 5000";
		$args = array_merge( $pred['args'], [ $w['start_gmt'], $w['end_gmt'] ] );
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ) ); // phpcs:ignore
		// phpcs:enable

		return $rows;
	}

	/**
	 * Titles and SKUs for a set of product ids, in one query.
	 *
	 * wc_get_product() per row costs a full product load each; the report only
	 * needs a name and a SKU, and asks for up to fifty of them.
	 *
	 * @param int[] $ids
	 * @return array<int, array{name:string,sku:string}>
	 */
	private function product_labels( array $ids ) {
		global $wpdb;

		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		if ( empty( $ids ) ) {
			return [];
		}
		$in = implode( ',', $ids );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			"SELECT p.ID, p.post_title, sku.meta_value AS sku
			 FROM {$wpdb->posts} p
			 LEFT JOIN {$wpdb->postmeta} sku ON sku.post_id = p.ID AND sku.meta_key = '_sku'
			 WHERE p.ID IN ({$in})"
		); // phpcs:ignore
		// phpcs:enable

		$out = [];
		foreach ( (array) $rows as $r ) {
			$out[ (int) $r->ID ] = [
				'name' => (string) $r->post_title,
				'sku'  => (string) $r->sku,
			];
		}
		return $out;
	}

	private function section_top_products() {
		$rows = $this->product_profit_rows();
		$has_cost_column = ( null !== $rows );

		// Cost resolution unavailable (a very old install, or the helper file
		// missing): fall back to the sales-only shape rather than the section
		// disappearing.
		if ( null === $rows ) {
			$rows = $this->product_sales_rows_no_cost();
		}
		if ( empty( $rows ) ) {
			return '';
		}

		// Fifty is the working set: ten are printed, and the lowest-margin
		// table below picks from the same window of real sellers rather than
		// from the long tail where a single unit distorts the margin.
		$rows   = array_slice( $rows, 0, 50 );
		$ids    = [];
		foreach ( $rows as $r ) { $ids[] = (int) $r->product_id; }
		$labels = $this->product_labels( $ids );

		$enriched = [];
		foreach ( $rows as $r ) {
			$pid  = (int) $r->product_id;
			$qty  = (float) $r->qty;
			$rev  = (float) $r->revenue;
			$cogs = $has_cost_column ? (float) $r->cogs : 0.0;
			$cov  = $has_cost_column ? (float) $r->costed_revenue : 0.0;

			$enriched[] = [
				'name'      => isset( $labels[ $pid ] ) && '' !== $labels[ $pid ]['name']
					? $labels[ $pid ]['name']
					: sprintf( __( '(deleted #%d)', 'brikpanel' ), $pid ),
				'sku'       => isset( $labels[ $pid ] ) ? $labels[ $pid ]['sku'] : '',
				'qty'       => $qty,
				'revenue'   => $rev,
				'cogs'      => $cogs,
				'gross'     => $rev - $cogs,
				'margin'    => $rev > 0 ? ( ( $rev - $cogs ) / $rev ) : 0.0,
				'has_cost'  => $cov > 0,
				// "Complete" allows half a cent of rounding: line totals are
				// stored to four decimals and the sum of the costed slice will
				// not land exactly on the revenue total.
				'full_cost' => $rev > 0 && $cov >= ( $rev - 0.005 ),
			];
		}

		$out   = [];
		$out[] = '## ' . __( 'Top Products (last 12 months)', 'brikpanel' );
		$out[] = '*' . __( 'Variations are rolled up to their parent product, while cost is resolved per variation. Sorted by revenue; the Units column lets you spot volume-vs-value differences.', 'brikpanel' ) . '*';
		$out[] = '';

		if ( $has_cost_column ) {
			$out[] = '| # | ' . __( 'Product', 'brikpanel' ) . ' | ' . __( 'SKU', 'brikpanel' ) . ' | ' . __( 'Units', 'brikpanel' ) . ' | ' . __( 'Revenue', 'brikpanel' ) . ' | ' . __( 'Avg Price', 'brikpanel' ) . ' | ' . __( 'COGS', 'brikpanel' ) . ' | ' . __( 'Gross profit', 'brikpanel' ) . ' | ' . __( 'Margin', 'brikpanel' ) . ' | ' . __( 'Cost on file', 'brikpanel' ) . ' |';
			$out[] = '|---:|---|---|---:|---:|---:|---:|---:|---:|---|';
		} else {
			$out[] = '| # | ' . __( 'Product', 'brikpanel' ) . ' | ' . __( 'SKU', 'brikpanel' ) . ' | ' . __( 'Units', 'brikpanel' ) . ' | ' . __( 'Revenue', 'brikpanel' ) . ' | ' . __( 'Avg Price', 'brikpanel' ) . ' |';
			$out[] = '|---:|---|---|---:|---:|---:|';
		}

		$i = 1;
		foreach ( array_slice( $enriched, 0, 10 ) as $p ) {
			$avg = $p['qty'] > 0 ? $p['revenue'] / $p['qty'] : 0;
			$row = '| ' . $i . ' | ' . $this->md_cell( $p['name'] ) . ' | ' . $this->md_cell( '' !== $p['sku'] ? $p['sku'] : '—' )
				. ' | ' . number_format_i18n( $p['qty'] )
				. ' | ' . $this->money( $p['revenue'] )
				. ' | ' . $this->money( $avg );
			if ( $has_cost_column ) {
				// The honesty column: a product with no cost on file shows a
				// 100% margin that is an artefact of the gap, not a result.
				$flag = $p['full_cost']
					? __( 'yes', 'brikpanel' )
					: ( $p['has_cost'] ? __( 'partial', 'brikpanel' ) : __( 'NO', 'brikpanel' ) );
				$row .= ' | ' . ( $p['has_cost'] ? $this->money( $p['cogs'] ) : '—' )
					. ' | ' . ( $p['has_cost'] ? $this->money( $p['gross'] ) : '—' )
					. ' | ' . ( $p['has_cost'] ? number_format_i18n( $p['margin'] * 100, 1 ) . '%' : '—' )
					. ' | ' . $flag;
			}
			$out[] = $row . ' |';
			$i++;
		}

		// Lowest-margin sellers — the most actionable table in the report, and
		// only meaningful where the cost is complete.
		if ( $has_cost_column ) {
			$costed = array_values( array_filter( $enriched, function ( $p ) {
				return $p['full_cost'] && $p['revenue'] > 0;
			} ) );
			if ( count( $costed ) >= 3 ) {
				usort( $costed, function ( $a, $b ) { return $a['margin'] <=> $b['margin']; } );

				$out[] = '';
				$out[] = '### ' . __( 'Lowest-Margin Sellers', 'brikpanel' );
				$out[] = '*' . __( 'Among the period\'s 50 biggest sellers that have a complete cost on file. A negative margin means the product is sold below its recorded cost.', 'brikpanel' ) . '*';
				$out[] = '| ' . __( 'Product', 'brikpanel' ) . ' | ' . __( 'SKU', 'brikpanel' ) . ' | ' . __( 'Units', 'brikpanel' ) . ' | ' . __( 'Revenue', 'brikpanel' ) . ' | ' . __( 'COGS', 'brikpanel' ) . ' | ' . __( 'Gross profit', 'brikpanel' ) . ' | ' . __( 'Margin', 'brikpanel' ) . ' |';
				$out[] = '|---|---|---:|---:|---:|---:|---:|';
				foreach ( array_slice( $costed, 0, 5 ) as $p ) {
					$out[] = '| ' . $this->md_cell( $p['name'] ) . ' | ' . $this->md_cell( '' !== $p['sku'] ? $p['sku'] : '—' )
						. ' | ' . number_format_i18n( $p['qty'] )
						. ' | ' . $this->money( $p['revenue'] )
						. ' | ' . $this->money( $p['cogs'] )
						. ' | ' . $this->money( $p['gross'] )
						. ' | ' . number_format_i18n( $p['margin'] * 100, 1 ) . '% |';
				}
			}
		}

		$fn = $this->footnote( $has_cost_column ? 'bp_cogs' : 'wc_orders' );
		if ( $fn ) { $out[] = ''; $out[] = $fn; }

		return implode( "\n", $out );
	}

	/**
	 * Units and revenue per product with no cost columns — the fallback shape
	 * for an install where cost resolution is unavailable.
	 *
	 * @return array<int, object>
	 */
	private function product_sales_rows_no_cost() {
		global $wpdb;

		$w    = $this->profit_window();
		$pred = $this->paid_order_predicate( 'o' );
		$ord  = $this->is_hpos() ? "{$wpdb->prefix}wc_orders" : $wpdb->posts;
		$oid  = $this->is_hpos() ? 'o.id' : 'o.ID';

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = "SELECT CAST(pid.meta_value AS UNSIGNED) AS product_id,
					SUM(CAST(qtym.meta_value AS DECIMAL(20,4))) AS qty,
					SUM(CAST(totm.meta_value AS DECIMAL(20,4))) AS revenue
				FROM {$wpdb->prefix}woocommerce_order_items oi
				INNER JOIN {$ord} o ON {$oid} = oi.order_id
				INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta qtym
						ON qtym.order_item_id = oi.order_item_id AND qtym.meta_key = '_qty'
				INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta totm
						ON totm.order_item_id = oi.order_item_id AND totm.meta_key = '_line_total'
				INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta pid
						ON pid.order_item_id = oi.order_item_id AND pid.meta_key = '_product_id'
				WHERE oi.order_item_type = 'line_item'
				  AND {$pred['where']}
				  AND {$pred['date_col']} >= %s AND {$pred['date_col']} <= %s
				GROUP BY product_id
				HAVING revenue > 0
				ORDER BY revenue DESC
				LIMIT 50";
		$args = array_merge( $pred['args'], [ $w['start_gmt'], $w['end_gmt'] ] );
		return (array) $wpdb->get_results( $wpdb->prepare( $sql, $args ) ); // phpcs:ignore
		// phpcs:enable
	}

	// =========================================================================
	// SECTION: TOP CUSTOMERS (all-time LTV from precomputed table)
	// =========================================================================

	private function section_top_customers() {
		global $wpdb;
		$tbl = $wpdb->prefix . 'brikpanel_customer_metrics';

		// Skip silently if the metrics table hasn't been populated yet.
		$has_data = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tbl}" ); // phpcs:ignore
		if ( $has_data === 0 ) {
			return '';
		}

		$rows = $wpdb->get_results(
			"SELECT m.user_id, m.customer_email, m.order_count, m.total_spent, m.aov, m.recency_days,
					m.first_order_date, m.last_order_date,
					u.display_name,
					bm_fn.meta_value AS bf, bm_ln.meta_value AS bl
			 FROM {$tbl} m
			 LEFT JOIN {$wpdb->users} u ON m.user_id = u.ID AND m.user_id > 0
			 LEFT JOIN {$wpdb->usermeta} bm_fn ON bm_fn.user_id = u.ID AND bm_fn.meta_key='billing_first_name'
			 LEFT JOIN {$wpdb->usermeta} bm_ln ON bm_ln.user_id = u.ID AND bm_ln.meta_key='billing_last_name'
			 ORDER BY m.total_spent DESC
			 LIMIT 10"
		); // phpcs:ignore

		if ( empty( $rows ) ) {
			return '';
		}

		$lines = [];
		$lines[] = '## ' . __( 'Top 10 Customers by Lifetime Value', 'brikpanel' );
		$lines[] = '| # | ' . __( 'Customer', 'brikpanel' ) . ' | ' . __( 'Email', 'brikpanel' ) . ' | ' . __( 'Orders', 'brikpanel' ) . ' | ' . __( 'LTV', 'brikpanel' ) . ' | ' . __( 'AOV', 'brikpanel' ) . ' | ' . __( 'Recency', 'brikpanel' ) . ' |';
		$lines[] = '|---:|---|---|---:|---:|---:|---:|';
		$i = 1;
		$top1_name = '';
		foreach ( $rows as $r ) {
			$name = trim( trim( (string) $r->bf . ' ' . (string) $r->bl ) );
			if ( $name === '' ) {
				$name = (string) $r->display_name;
			}
			if ( $name === '' ) {
				$name = __( '(guest)', 'brikpanel' );
			}
			if ( $i === 1 ) {
				$top1_name = $name;
				$this->register_tldr( 'top1_customer_ltv', (float) $r->total_spent );
				$this->register_tldr( 'top1_customer_name', $name );
			}
			$rec = $r->recency_days !== null ? sprintf( __( '%d days ago', 'brikpanel' ), (int) $r->recency_days ) : '—';
			$lines[] = '| ' . $i . ' | ' . $this->md_cell( $name ) . ' | ' . $this->md_cell( $r->customer_email ) . ' | ' . number_format_i18n( $r->order_count ) . ' | ' . $this->money( $r->total_spent ) . ' | ' . $this->money( $r->aov ) . ' | ' . $rec . ' |';
			$i++;
		}

		$fn = $this->footnote( 'bp_metrics' );
		if ( $fn ) { $lines[] = ''; $lines[] = $fn; }

		return implode( "\n", $lines );
	}

	// =========================================================================
	// SECTION: RFM SEGMENTS
	// =========================================================================

	private function section_rfm_segments() {
		global $wpdb;
		$tbl = $wpdb->prefix . 'brikpanel_customer_metrics';

		$rows = $wpdb->get_results(
			"SELECT rfm_segment,
					COUNT(*) AS customers,
					COALESCE(SUM(total_spent),0) AS total_ltv,
					COALESCE(AVG(total_spent),0) AS avg_ltv,
					COALESCE(AVG(order_count),0) AS avg_orders,
					COALESCE(AVG(recency_days),0) AS avg_recency
			 FROM {$tbl}
			 WHERE rfm_segment IS NOT NULL
			 GROUP BY rfm_segment"
		); // phpcs:ignore

		if ( empty( $rows ) ) {
			return '';
		}

		$labels = function_exists( 'brikpanel_ca_rfm_segment_labels' ) ? brikpanel_ca_rfm_segment_labels() : [];

		$by_seg = [];
		$total = 0;
		foreach ( $rows as $r ) {
			$by_seg[ $r->rfm_segment ] = $r;
			$total += (int) $r->customers;
		}

		$lines = [];
		$lines[] = '## ' . __( 'RFM Customer Segments', 'brikpanel' );
		$lines[] = '| ' . __( 'Segment', 'brikpanel' ) . ' | ' . __( 'Customers', 'brikpanel' ) . ' | ' . __( 'Share', 'brikpanel' ) . ' | ' . __( 'Avg LTV', 'brikpanel' ) . ' | ' . __( 'Total LTV', 'brikpanel' ) . ' | ' . __( 'Avg Orders', 'brikpanel' ) . ' | ' . __( 'Avg Recency (days)', 'brikpanel' ) . ' |';
		$lines[] = '|---|---:|---:|---:|---:|---:|---:|';

		// Render in canonical order from labels list (best→worst); append unknowns last.
		$ordered_keys = array_keys( $labels );
		foreach ( $by_seg as $k => $_ ) {
			if ( ! in_array( $k, $ordered_keys, true ) ) {
				$ordered_keys[] = $k;
			}
		}
		foreach ( $ordered_keys as $k ) {
			if ( ! isset( $by_seg[ $k ] ) ) {
				continue;
			}
			$r = $by_seg[ $k ];
			$label = $labels[ $k ]['label'] ?? $k;
			$lines[] = '| ' . $this->md_cell( $label ) . ' | ' . number_format_i18n( $r->customers ) . ' | ' . $this->pct( $r->customers, $total ) . ' | ' . $this->money( $r->avg_ltv ) . ' | ' . $this->money( $r->total_ltv ) . ' | ' . number_format_i18n( $r->avg_orders, 1 ) . ' | ' . number_format_i18n( (int) round( $r->avg_recency ) ) . ' |';
		}

		$fn = $this->footnote( 'bp_metrics' );
		if ( $fn ) { $lines[] = ''; $lines[] = $fn; }

		return implode( "\n", $lines );
	}

	// =========================================================================
	// SECTION: COHORT RETENTION
	// =========================================================================

	private function section_cohort_retention() {
		global $wpdb;
		$tbl = $wpdb->prefix . 'brikpanel_cohort_retention';

		$rows = $wpdb->get_results(
			"SELECT cohort_month, period_offset, cohort_size, retained_customers, retention_rate
			 FROM {$tbl}
			 WHERE cohort_month >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
			   AND period_offset <= 6
			 ORDER BY cohort_month DESC, period_offset ASC"
		); // phpcs:ignore

		if ( empty( $rows ) ) {
			return '';
		}

		// Pivot rows into matrix: cohort → [size, m0..m6 retention %]
		$matrix = [];
		foreach ( $rows as $r ) {
			$ck = mysql2date( 'Y-m', $r->cohort_month );
			if ( ! isset( $matrix[ $ck ] ) ) {
				$matrix[ $ck ] = [ 'size' => (int) $r->cohort_size, 'm' => array_fill( 0, 7, null ) ];
			}
			$matrix[ $ck ]['m'][ (int) $r->period_offset ] = (float) $r->retention_rate;
		}

		$lines = [];
		$lines[] = '## ' . __( 'Cohort Retention (last 12 months)', 'brikpanel' );
		$lines[] = '*' . __( 'Each row: % of the cohort that placed an order N months after their first order. M0 is always 100%. The Trend column visualizes the row using Unicode block characters (▁→0%, █→100%).', 'brikpanel' ) . '*';
		$lines[] = '';
		$lines[] = '| ' . __( 'Cohort', 'brikpanel' ) . ' | ' . __( 'Size', 'brikpanel' ) . ' | M0 | M1 | M2 | M3 | M4 | M5 | M6 | ' . __( 'Trend', 'brikpanel' ) . ' |';
		$lines[] = '|---|---:|---:|---:|---:|---:|---:|---:|---:|:---:|';

		foreach ( $matrix as $cohort => $data ) {
			$row = '| ' . $cohort . ' | ' . number_format_i18n( $data['size'] );
			$spark = '';
			for ( $m = 0; $m <= 6; $m++ ) {
				$v = $data['m'][ $m ];
				$row .= ' | ' . ( $v === null ? '—' : number_format_i18n( $v, 1 ) . '%' );
				$spark .= ( $v === null ? ' ' : $this->unicode_spark( $v ) );
			}
			$row .= ' | ' . $spark . ' |';
			$lines[] = $row;
		}

		$fn = $this->footnote( 'bp_cohort' );
		if ( $fn ) { $lines[] = ''; $lines[] = $fn; }

		return implode( "\n", $lines );
	}

	// =========================================================================
	// SECTION: CONVERSION FUNNEL (30d + 12m)
	// =========================================================================

	private function section_funnel() {
		// If tracking has never started, the entire section is meaningless.
		if ( $this->tracking_start_date() === null ) {
			return '';
		}

		$out = [];
		$out[] = '## ' . __( 'Conversion Funnel', 'brikpanel' );
		$out[] = '*' . __( 'Add-to-cart count can exceed product views due to bot traffic, direct add-to-cart links, listing-page tracking, and same-product re-adds. Successful orders include WooCommerce-imported orders that may pre-date BrikPanel tracking; the funnel windows below are clamped to dates after tracking started so the rates stay meaningful.', 'brikpanel' ) . '*';
		$out[] = '';
		$out[] = $this->funnel_window( __( 'Last 30 days', 'brikpanel' ), 30 );
		$out[] = $this->funnel_window( __( 'Last 12 months', 'brikpanel' ), 365 );
		$out = array_filter( $out );

		$fn = $this->footnote( 'bp_visitors' );
		if ( $fn ) { $out[] = $fn; }

		return implode( "\n\n", $out );
	}

	private function funnel_window( $label, $days_back ) {
		$end_date   = gmdate( 'Y-m-d' );
		$raw_start  = gmdate( 'Y-m-d', strtotime( '-' . (int) $days_back . ' days', current_time( 'timestamp', true ) ) );

		// Clamp to tracking_start_date — without this, "successful orders"
		// pulls historical WC orders while visitor counts remain zero,
		// producing nonsensical >100% rates.
		$window = $this->clamp_to_tracking_window( $raw_start, $end_date );
		if ( $window === null ) {
			return '';
		}
		$start_date = $window['start'];

		$visitors  = function_exists( 'brikpanel_get_visitor_count' )       ? (int) brikpanel_get_visitor_count( $start_date, $end_date ) : 0;
		$products  = function_exists( 'brikpanel_get_product_view_count' )  ? (int) brikpanel_get_product_view_count( $start_date, $end_date ) : 0;
		$add_cart  = function_exists( 'brikpanel_get_add_to_cart_count' )   ? (int) brikpanel_get_add_to_cart_count( $start_date, $end_date ) : 0;
		$checkout  = function_exists( 'brikpanel_get_checkout_count' )      ? (int) brikpanel_get_checkout_count( $start_date, $end_date ) : 0;

		$start_gmt = $start_date . ' 00:00:00';
		$end_gmt   = $this->now_gmt();
		$success   = function_exists( 'brikpanel_get_successful_order_count' ) ? (int) brikpanel_get_successful_order_count( $start_gmt, $end_gmt ) : 0;

		if ( ( $visitors + $products + $add_cart + $checkout + $success ) === 0 ) {
			return '';
		}

		// Caveat the label when clamping kicked in (window shorter than asked).
		$effective_days = (int) round( ( strtotime( $end_date ) - strtotime( $start_date ) ) / DAY_IN_SECONDS );
		if ( $effective_days < $days_back ) {
			$label .= ' — ' . sprintf( __( 'clamped to %d days since tracking start (%s)', 'brikpanel' ), $effective_days, $start_date );
		}

		$lines = [];
		$lines[] = '### ' . $label;
		$lines[] = '| ' . __( 'Stage', 'brikpanel' ) . ' | ' . __( 'Count', 'brikpanel' ) . ' | ' . __( 'Conv. from Visitor', 'brikpanel' ) . ' |';
		$lines[] = '|---|---:|---:|';
		$lines[] = '| ' . __( 'Visitors', 'brikpanel' ) . ' | ' . number_format_i18n( $visitors ) . ' | 100% |';
		$lines[] = '| ' . __( 'Product views', 'brikpanel' ) . ' | ' . number_format_i18n( $products ) . ' | ' . $this->pct( $products, $visitors ) . ' |';
		$lines[] = '| ' . __( 'Add to cart', 'brikpanel' ) . ' | ' . number_format_i18n( $add_cart ) . ' | ' . $this->pct( $add_cart, $visitors ) . ' |';
		$lines[] = '| ' . __( 'Checkout reached', 'brikpanel' ) . ' | ' . number_format_i18n( $checkout ) . ' | ' . $this->pct( $checkout, $visitors ) . ' |';
		$lines[] = '| ' . __( 'Successful orders', 'brikpanel' ) . ' | ' . number_format_i18n( $success ) . ' | ' . $this->pct( $success, $visitors ) . ' |';

		return implode( "\n", $lines );
	}

	// =========================================================================
	// SECTION: DEVICE SPLIT (last 12 months)
	// =========================================================================

	private function section_devices() {
		global $wpdb;
		$tbl = $wpdb->prefix . 'brikpanel_visitors';

		$start_date = gmdate( 'Y-m-d', strtotime( '-12 months', current_time( 'timestamp', true ) ) );
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT
				COALESCE(SUM(mobile_count),0)  AS mobile,
				COALESCE(SUM(tablet_count),0)  AS tablet,
				COALESCE(SUM(desktop_count),0) AS desktop,
				COALESCE(SUM(visitor_count),0) AS total
			 FROM {$tbl}
			 WHERE date_column >= %s",
			$start_date
		) ); // phpcs:ignore

		if ( ! $row || (int) $row->total === 0 ) {
			return '';
		}

		$mobile  = (int) $row->mobile;
		$tablet  = (int) $row->tablet;
		$desktop = (int) $row->desktop;
		$total   = (int) $row->total;
		// "Unknown" = visitors counted by the rollup but not classified by
		// the device sniffer (typically: tracking that ran before device
		// detection landed, or user agents the regex missed). Keeps the
		// table totals honest.
		$unknown = max( 0, $total - $mobile - $tablet - $desktop );

		$lines = [];
		$lines[] = '## ' . __( 'Device Split (last 12 months)', 'brikpanel' );
		$lines[] = '| ' . __( 'Device', 'brikpanel' ) . ' | ' . __( 'Visitors', 'brikpanel' ) . ' | ' . __( 'Share', 'brikpanel' ) . ' |';
		$lines[] = '|---|---:|---:|';
		$lines[] = '| ' . __( 'Mobile', 'brikpanel' ) . ' | ' . number_format_i18n( $mobile ) . ' | ' . $this->pct( $mobile, $total ) . ' |';
		$lines[] = '| ' . __( 'Tablet', 'brikpanel' ) . ' | ' . number_format_i18n( $tablet ) . ' | ' . $this->pct( $tablet, $total ) . ' |';
		$lines[] = '| ' . __( 'Desktop', 'brikpanel' ) . ' | ' . number_format_i18n( $desktop ) . ' | ' . $this->pct( $desktop, $total ) . ' |';
		if ( $unknown > 0 ) {
			$lines[] = '| ' . __( 'Unknown / pre-tracking', 'brikpanel' ) . ' | ' . number_format_i18n( $unknown ) . ' | ' . $this->pct( $unknown, $total ) . ' |';
		}
		$lines[] = '| **' . __( 'Total', 'brikpanel' ) . '** | **' . number_format_i18n( $total ) . '** | 100% |';

		$fn = $this->footnote( 'bp_visitors' );
		if ( $fn ) { $lines[] = ''; $lines[] = $fn; }

		return implode( "\n", $lines );
	}

	// =========================================================================
	// SECTION: COUPON USAGE (top 10 by usage_count, all-time)
	// =========================================================================

	private function section_coupons() {
		global $wpdb;

		// Coupons live as wp_posts.post_type='shop_coupon'; usage stored in
		// _usage_count meta. Discount totals would need order_item joins —
		// usage count alone is enough for an executive summary.
		// `usage` is a reserved word in MySQL — alias the postmeta join as
		// `mu_use` to avoid a syntax error.
		$rows = $wpdb->get_results(
			"SELECT p.post_title AS code,
					CAST(IFNULL(mu_use.meta_value, 0) AS UNSIGNED) AS usage_count,
					IFNULL(dtype.meta_value, '') AS discount_type,
					CAST(IFNULL(amt.meta_value, 0) AS DECIMAL(20,4)) AS amount,
					p.post_status AS status,
					IFNULL(expiry.meta_value, '') AS date_expires
			 FROM {$wpdb->posts} p
			 LEFT JOIN {$wpdb->postmeta} mu_use ON mu_use.post_id = p.ID AND mu_use.meta_key='_usage_count'
			 LEFT JOIN {$wpdb->postmeta} dtype  ON dtype.post_id  = p.ID AND dtype.meta_key='discount_type'
			 LEFT JOIN {$wpdb->postmeta} amt    ON amt.post_id    = p.ID AND amt.meta_key='coupon_amount'
			 LEFT JOIN {$wpdb->postmeta} expiry ON expiry.post_id = p.ID AND expiry.meta_key='date_expires'
			 WHERE p.post_type='shop_coupon' AND p.post_status IN ('publish','expired')
			 ORDER BY usage_count DESC
			 LIMIT 10"
		); // phpcs:ignore

		if ( empty( $rows ) ) {
			return '';
		}

		// Drop coupons that were never used (otherwise table is meaningless)
		$rows = array_filter( $rows, function ( $r ) { return (int) $r->usage_count > 0; } );
		if ( empty( $rows ) ) {
			return '';
		}

		$lines = [];
		$lines[] = '## ' . __( 'Top Coupons by Usage', 'brikpanel' );
		$lines[] = '| ' . __( 'Code', 'brikpanel' ) . ' | ' . __( 'Type', 'brikpanel' ) . ' | ' . __( 'Amount', 'brikpanel' ) . ' | ' . __( 'Times Used', 'brikpanel' ) . ' | ' . __( 'Status', 'brikpanel' ) . ' |';
		$lines[] = '|---|---|---:|---:|---|';
		foreach ( $rows as $r ) {
			$amount_str = '';
			if ( strpos( (string) $r->discount_type, 'percent' ) !== false ) {
				$amount_str = number_format_i18n( (float) $r->amount, 0 ) . '%';
			} else {
				$amount_str = $this->money( $r->amount );
			}
			$lines[] = '| ' . $this->md_cell( $r->code ) . ' | ' . $this->md_cell( $r->discount_type ?: '—' ) . ' | ' . $amount_str . ' | ' . number_format_i18n( $r->usage_count ) . ' | ' . $this->md_cell( $r->status ) . ' |';
		}

		$fn = $this->footnote( 'wc_coupons' );
		if ( $fn ) { $lines[] = ''; $lines[] = $fn; }

		return implode( "\n", $lines );
	}

	// =========================================================================
	// PROFIT / COST / ADS HELPERS
	//
	// Everything money-related in this report goes through the plugin's own
	// profit engine (includes/brikpanel-profit.php) rather than bespoke SQL.
	// The report used to hand-roll its own COGS and expense queries and ended
	// up contradicting the Dashboard by a factor of a hundred on real stores:
	// it read the frozen `_cogs_value` line snapshot (only ever written when
	// WooCommerce's native COGS feature is on at checkout time) and dropped
	// ad spend, payment fees, shipping cost, percentage and per-order costs
	// entirely, then presented the result as a net margin. An LLM reading that
	// gives advice built on a number that is not the merchant's.
	// =========================================================================

	/**
	 * The single 12-month window every money section shares.
	 *
	 * brikpanel_profit_snapshot() needs BOTH bases: order-derived components
	 * (COGS, tax, fees, returns) are queried against UTC datetimes, while
	 * expenses and ad spend are stored as site-local dates. Deriving all four
	 * strings from one instant keeps them describing the same period.
	 *
	 * @return array{start_gmt:string,end_gmt:string,start_local:string,end_local:string,start_date:string,end_date:string}
	 */
	private function profit_window() {
		static $w = null;
		if ( null === $w ) {
			$ts_local    = current_time( 'timestamp' );
			$start_local = gmdate( 'Y-m-d 00:00:00', strtotime( '-12 months', $ts_local ) );
			$end_local   = gmdate( 'Y-m-d H:i:s', $ts_local );
			$w = [
				'start_local' => $start_local,
				'end_local'   => $end_local,
				'start_gmt'   => get_gmt_from_date( $start_local ),
				'end_gmt'     => get_gmt_from_date( $end_local ),
				'start_date'  => substr( $start_local, 0, 10 ),
				'end_date'    => substr( $end_local, 0, 10 ),
			];
		}
		return $w;
	}

	/**
	 * Memoised full profit snapshot for the 12-month window — the same call
	 * the Dashboard profit card makes, with the same arguments, so the two
	 * surfaces can never disagree.
	 *
	 * `$exclude_marketplace` is false to match Brikpanel_Dashboard: the
	 * revenue handed in already includes marketplace orders, so netting it
	 * against a site-only cost would manufacture a permanent loss.
	 *
	 * @return array|null Null when the profit engine is not loaded.
	 */
	private function profit_snapshot() {
		static $snap = null;
		if ( null !== $snap ) {
			return false === $snap ? null : $snap;
		}
		if ( ! function_exists( 'brikpanel_profit_snapshot' ) || ! function_exists( 'brikpanel_get_total_revenue' ) ) {
			$snap = false;
			return null;
		}

		// Recurring expense templates are materialised into real dated rows
		// lazily. Without this the current month's rent/salary occurrence may
		// not exist yet and every expense total in the report is short by it.
		if ( class_exists( 'Brikpanel_Expenses' ) && method_exists( 'Brikpanel_Expenses', 'materialize_due' ) ) {
			try {
				Brikpanel_Expenses::materialize_due();
			} catch ( Throwable $e ) {
				// A stale materialisation is a wrong total, not a broken
				// report — carry on with what is already in the table.
				unset( $e );
			}
		}

		$w       = $this->profit_window();
		$revenue = (float) brikpanel_get_total_revenue( $w['start_gmt'], $w['end_gmt'], false );
		$snap    = brikpanel_profit_snapshot(
			$revenue,
			$w['start_gmt'],
			$w['end_gmt'],
			$w['start_local'],
			$w['end_local'],
			false
		);

		return $snap;
	}

	/**
	 * Order-line cost resolution as SQL, identical to brikpanel_profit_cogs().
	 *
	 * Copied in shape rather than value because this report needs the cost
	 * broken down per product and per category, which the scalar helper
	 * cannot express. The rules that matter are the ones that make variable
	 * products correct: read the VARIATION's cost first, fall back to the
	 * parent product when the variation has none, and when WooCommerce's
	 * `_cogs_value_is_additive` flag is set add the two instead of replacing.
	 * Cost meta keys come from brikpanel_cogs_meta_keys() via the shared join
	 * builder, so a store keeping costs in a third-party cost plugin is not
	 * reported as zero-cost.
	 *
	 * Expects the caller's query to expose the order-items alias as `oi`.
	 *
	 * @return array{joins:string,unit:string,has_cost:string}|null Null when helpers are missing.
	 */
	private function cogs_line_sql() {
		global $wpdb;

		static $set = null;
		if ( null !== $set ) {
			return false === $set ? null : $set;
		}
		if ( ! function_exists( 'brikpanel_cogs_sql_join_set' ) ) {
			$set = false;
			return null;
		}

		$vcost = brikpanel_cogs_sql_join_set( 'vc', 'CAST(vid.meta_value AS UNSIGNED)', 'CAST(vid.meta_value AS UNSIGNED) > 0' );
		$pcost = brikpanel_cogs_sql_join_set( 'pc', 'CAST(pid.meta_value AS UNSIGNED)' );
		$vval  = $vcost['value'];
		$pval  = $pcost['value'];

		$set = [
			'joins' => "
				LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta pid
						ON pid.order_item_id = oi.order_item_id AND pid.meta_key = '_product_id'
				LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta vid
						ON vid.order_item_id = oi.order_item_id AND vid.meta_key = '_variation_id'
				LEFT JOIN {$wpdb->postmeta} vadd
						ON vadd.post_id = CAST(vid.meta_value AS UNSIGNED)
					   AND vadd.meta_key = '_cogs_value_is_additive'
					   AND CAST(vid.meta_value AS UNSIGNED) > 0" . $vcost['joins'] . $pcost['joins'],
			'unit' => "CASE WHEN vadd.meta_value = 'yes'
					THEN CAST(COALESCE({$vval}, '0') AS DECIMAL(20,4)) + CAST(COALESCE({$pval}, '0') AS DECIMAL(20,4))
					ELSE CAST(COALESCE({$vval}, {$pval}, '0') AS DECIMAL(20,4)) END",
			// Distinguishes "cost is zero" from "no cost on file" — the report
			// must never present an uncosted product as a 100% margin.
			'has_cost' => "COALESCE({$vval}, {$pval}) IS NOT NULL",
		];

		return $set;
	}

	/**
	 * Paid-order predicate shared by the per-product and per-category profit
	 * queries, matching the basis brikpanel_profit_cogs() uses so their
	 * numbers add up to the headline: paid statuses, admin-placed orders
	 * excluded, marketplace orders kept (combined basis).
	 *
	 * @param string $alias Order table alias.
	 * @return array{where:string,args:array,date_col:string}
	 */
	private function paid_order_predicate( $alias = 'o' ) {
		$is_hpos  = $this->is_hpos();
		$statuses = function_exists( 'brikpanel_paid_order_statuses' ) ? brikpanel_paid_order_statuses() : [ 'wc-processing', 'wc-completed' ];
		$sp       = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );
		$date_col = $is_hpos ? "{$alias}.date_created_gmt" : "{$alias}.post_date_gmt";

		if ( $is_hpos ) {
			$where = "{$alias}.type = 'shop_order' AND {$alias}.status IN ({$sp})";
		} else {
			$where = "{$alias}.post_type = 'shop_order' AND {$alias}.post_status IN ({$sp})";
		}
		$args = $statuses;

		if ( function_exists( 'brikpanel_admin_order_exclusion_sql' ) ) {
			$excl = $is_hpos
				? brikpanel_admin_order_exclusion_sql( true )
				: brikpanel_admin_order_exclusion_sql( false, "{$alias}.ID" );
			if ( ! empty( $excl['sql'] ) ) {
				// The HPOS helper emits a bare `customer_id`; qualify it so a
				// query joining two order-ish tables stays unambiguous.
				$where .= $is_hpos ? str_replace( 'customer_id', "{$alias}.customer_id", $excl['sql'] ) : $excl['sql'];
				$args   = array_merge( $args, $excl['args'] );
			}
		}

		return [ 'where' => $where, 'args' => $args, 'date_col' => $date_col ];
	}

	/**
	 * Store-currency ad spend rolled up per month for the report window.
	 * Foreign-currency rows are dropped rather than converted, exactly as
	 * brikpanel_profit_ad_spend_by_platform() does; the count of dropped rows
	 * is reported back so the caveat section can disclose it.
	 *
	 * @return array{months:array<string,array<string,float>>,foreign:array<string,float>,platforms:string[],first:string,last:string}
	 */
	private function ads_monthly() {
		static $out = null;
		if ( null !== $out ) {
			return $out;
		}

		$out = [ 'months' => [], 'foreign' => [], 'platforms' => [], 'first' => '', 'last' => '' ];
		if ( ! class_exists( 'Brikpanel_Ads_Store' ) ) {
			return $out;
		}

		$w         = $this->profit_window();
		$store_cur = $this->currency_code();
		$rows      = Brikpanel_Ads_Store::daily_breakdown( $w['start_date'], $w['end_date'] );

		foreach ( (array) $rows as $r ) {
			$date     = isset( $r['date'] ) ? (string) $r['date'] : '';
			$platform = isset( $r['platform'] ) ? (string) $r['platform'] : '';
			$cur      = isset( $r['currency'] ) && '' !== $r['currency'] ? (string) $r['currency'] : $store_cur;
			$spend    = isset( $r['spend'] ) ? (float) $r['spend'] : 0.0;
			if ( '' === $date || '' === $platform ) {
				continue;
			}
			if ( $cur !== $store_cur ) {
				if ( ! isset( $out['foreign'][ $cur ] ) ) {
					$out['foreign'][ $cur ] = 0.0;
				}
				$out['foreign'][ $cur ] += $spend;
				continue;
			}
			$ym = substr( $date, 0, 7 );
			if ( ! isset( $out['months'][ $ym ] ) ) {
				$out['months'][ $ym ] = [];
			}
			if ( ! isset( $out['months'][ $ym ][ $platform ] ) ) {
				$out['months'][ $ym ][ $platform ] = 0.0;
			}
			$out['months'][ $ym ][ $platform ] += $spend;
			if ( ! in_array( $platform, $out['platforms'], true ) ) {
				$out['platforms'][] = $platform;
			}
			if ( '' === $out['first'] || $date < $out['first'] ) {
				$out['first'] = $date;
			}
			if ( '' === $out['last'] || $date > $out['last'] ) {
				$out['last'] = $date;
			}
		}

		return $out;
	}

	/** Human label for an ad platform slug, matching the Dashboard profit card. */
	private function ad_platform_label( $slug ) {
		$map = [
			'google_ads' => __( 'Google Ads', 'brikpanel' ),
			'meta_ads'   => __( 'Meta Ads', 'brikpanel' ),
		];
		return isset( $map[ $slug ] ) ? $map[ $slug ] : ucwords( str_replace( '_', ' ', (string) $slug ) );
	}

	/**
	 * Render a ratio as "N.Nx", or an em dash when the denominator is unusable.
	 */
	private function ratio( $numerator, $denominator, $decimals = 2 ) {
		$denominator = (float) $denominator;
		if ( $denominator <= 0 ) {
			return '—';
		}
		return number_format_i18n( (float) $numerator / $denominator, $decimals ) . 'x';
	}

	// =========================================================================
	// SECTION: DATA QUALITY & CAVEATS
	//
	// Sits directly under the TL;DR on purpose. Every number below it is
	// computed from what the merchant has actually filled in, and the gaps are
	// silent by nature: a product with no cost contributes zero COGS, an order
	// paid by bank transfer carries no processor fee, ad spend billed in a
	// foreign currency is dropped rather than converted. Stated plainly here,
	// those become caveats a reader can reason about; left implicit, they read
	// as a healthy margin.
	// =========================================================================

	private function section_data_quality() {
		$lines = [];
		$notes = [];

		$snap = $this->profit_snapshot();

		if ( is_array( $snap ) ) {
			// --- Cost of goods coverage ---
			$coverage = isset( $snap['cogs_coverage_pct'] ) ? (float) $snap['cogs_coverage_pct'] : 0.0;
			$missing  = isset( $snap['cogs_missing_lines'] ) ? (int) $snap['cogs_missing_lines'] : 0;
			$any_cogs = isset( $snap['cogs_raw'] ) && (float) $snap['cogs_raw'] > 0;
			// "Incomplete" only describes a store that HAS costs and is missing
			// some. A store with none at all needs the blunter wording below,
			// or the reader is told a 100% margin is merely "overstated".
			if ( $any_cogs && $missing > 0 ) {
				$notes[] = '- **' . __( 'Cost of goods is incomplete', 'brikpanel' ) . ':** ' . sprintf(
					/* translators: 1: coverage percentage, 2: number of order lines with no cost */
					__( 'only %1$s of the period\'s revenue has a product cost on file (%2$s order line(s) have none). Lines without a cost count as zero, so the reported COGS is a FLOOR and gross margin is overstated.', 'brikpanel' ),
					number_format_i18n( $coverage, 1 ) . '%',
					number_format_i18n( $missing )
				);
				$missing_products = isset( $snap['cogs_missing_products'] ) && is_array( $snap['cogs_missing_products'] )
					? array_slice( $snap['cogs_missing_products'], 0, 10 )
					: [];
				if ( ! empty( $missing_products ) ) {
					$names = [];
					foreach ( $missing_products as $p ) {
						$names[] = $this->md_cell( isset( $p['name'] ) ? $p['name'] : '' );
					}
					$notes[] = '  - ' . __( 'Biggest uncosted sellers', 'brikpanel' ) . ': ' . implode( '; ', array_filter( $names ) );
				}
			} elseif ( $any_cogs ) {
				$notes[] = '- **' . __( 'Cost of goods', 'brikpanel' ) . ':** ' . __( 'every sold line has a cost on file — margins below are complete.', 'brikpanel' );
			} else {
				$notes[] = '- **' . __( 'Cost of goods', 'brikpanel' ) . ':** ' . __( 'no product has a cost on file, so COGS is zero and every margin below is really a revenue figure. Treat gross margin as unknown, not as 100%.', 'brikpanel' );
			}

			// --- Payment processing fees ---
			if ( function_exists( 'brikpanel_payment_fees_enabled' ) && ! brikpanel_payment_fees_enabled() ) {
				$notes[] = '- **' . __( 'Payment fees', 'brikpanel' ) . ':** ' . __( 'not tracked (the setting is off), so gateway commission is NOT deducted anywhere in this report.', 'brikpanel' );
			} else {
				$fee_missing = isset( $snap['payment_fees_missing'] ) ? (int) $snap['payment_fees_missing'] : 0;
				$fee_unconv  = isset( $snap['payment_fees_unconverted'] ) ? (int) $snap['payment_fees_unconverted'] : 0;
				if ( $fee_unconv > 0 ) {
					$notes[] = '- **' . __( 'Payment fees are understated', 'brikpanel' ) . ':** ' . sprintf(
						/* translators: %s: number of orders */
						__( '%s order(s) carry a fee in a currency with no conversion rate on file, so those fees are missing from the total.', 'brikpanel' ),
						number_format_i18n( $fee_unconv )
					);
				}
				if ( $fee_missing > 0 ) {
					$notes[] = '- ' . sprintf(
						/* translators: 1: number of orders, 2: coverage percentage */
						__( 'Payment fees: %1$s order(s) carry no processor fee (normal for bank transfer / cash on delivery). Coverage %2$s.', 'brikpanel' ),
						number_format_i18n( $fee_missing ),
						number_format_i18n( isset( $snap['payment_fees_coverage_pct'] ) ? (float) $snap['payment_fees_coverage_pct'] : 0, 1 ) . '%'
					);
				}
			}

			// --- Shipping cost ---
			if ( function_exists( 'brikpanel_shipping_cost_enabled' ) && ! brikpanel_shipping_cost_enabled() ) {
				$notes[] = '- **' . __( 'Shipping cost', 'brikpanel' ) . ':** ' . __( 'not tracked (the setting is off). WooCommerce stores what the customer was CHARGED, never what the courier billed, so shipping is treated as profit-neutral here.', 'brikpanel' );
			}
		} else {
			$notes[] = '- **' . __( 'Profit engine unavailable', 'brikpanel' ) . ':** ' . __( 'cost and expense figures could not be computed for this report.', 'brikpanel' );
		}

		// --- Advertising ---
		if ( class_exists( 'Brikpanel_Ads_Store' ) ) {
			$ads = $this->ads_monthly();
			if ( '' !== $ads['first'] ) {
				$notes[] = '- **' . __( 'Ad spend window', 'brikpanel' ) . ':** ' . sprintf(
					/* translators: 1: first date, 2: last date */
					__( 'imported spend covers %1$s to %2$s only. Months outside that range show zero advertising cost because nothing was imported, not because nothing was spent.', 'brikpanel' ),
					$ads['first'],
					$ads['last']
				);
			}
			if ( ! empty( $ads['foreign'] ) ) {
				$parts = [];
				foreach ( $ads['foreign'] as $cur => $amount ) {
					$parts[] = number_format_i18n( $amount, 2 ) . ' ' . $this->md_cell( $cur );
				}
				$notes[] = '- **' . __( 'Foreign-currency ad spend is excluded', 'brikpanel' ) . ':** ' . sprintf(
					/* translators: 1: list of amounts and currencies, 2: store currency code */
					__( '%1$s was billed in a currency other than the store currency (%2$s) and is NOT converted or counted. Real advertising cost is higher than reported.', 'brikpanel' ),
					implode( ' + ', $parts ),
					$this->currency_code()
				);
			}
			// Only worth saying when spend was actually imported — on a store
			// with the module on but nothing connected it is pure noise.
			if ( '' !== $ads['first'] ) {
				$notes[] = '- ' . __( 'Ad spend is imported at account level per day. There is no campaign, ad-set, keyword or product breakdown, and no click-to-order attribution — any cost-per-order below is blended, not attributed.', 'brikpanel' );
			}
		}

		// --- Expenses ---
		$notes[] = '- ' . __( 'Percentage-based costs (card commission) and per-order costs (packaging, courier surcharge) are stored as a RATE and a UNIT PRICE, not as money. They are excluded from the expense money totals and listed in their own tables; the profit headline already includes their computed amounts.', 'brikpanel' );

		// --- Timezone basis ---
		$notes[] = '- ' . __( 'Basis note: expenses and ad spend are keyed on site-local dates, while order-derived figures are queried in UTC. The same "last 12 months" therefore differs by up to a day at each edge.', 'brikpanel' );

		if ( count( $notes ) < 1 ) {
			return '';
		}

		$lines[] = '## ' . __( 'Data Quality & Caveats', 'brikpanel' );
		$lines[] = '*' . __( 'Read this before drawing conclusions from the money sections — these are the gaps that are otherwise silent.', 'brikpanel' ) . '*';
		$lines[] = '';
		foreach ( $notes as $n ) {
			$lines[] = $n;
		}

		return implode( "\n", $lines );
	}

	// =========================================================================
	// SECTION: ADVERTISING (last 12 months)
	// =========================================================================

	private function section_ad_spend() {
		if ( ! class_exists( 'Brikpanel_Ads_Store' ) ) {
			return '';
		}

		$w    = $this->profit_window();
		$rows = Brikpanel_Ads_Store::totals_for_range( $w['start_date'], $w['end_date'] );
		if ( empty( $rows ) ) {
			return '';
		}

		$store_cur   = $this->currency_code();
		$total_store = 0.0;
		$currencies  = [];

		$lines   = [];
		$lines[] = '## ' . __( 'Advertising (last 12 months)', 'brikpanel' );
		$lines[] = '';
		$lines[] = '### ' . __( 'By Platform', 'brikpanel' );
		$lines[] = '| ' . __( 'Platform', 'brikpanel' ) . ' | ' . __( 'Currency', 'brikpanel' ) . ' | ' . __( 'Spend', 'brikpanel' ) . ' | ' . __( 'Impressions', 'brikpanel' ) . ' | ' . __( 'Clicks', 'brikpanel' ) . ' | ' . __( 'CTR', 'brikpanel' ) . ' | ' . __( 'CPC', 'brikpanel' ) . ' | ' . __( 'CPM', 'brikpanel' ) . ' |';
		$lines[] = '|---|---|---:|---:|---:|---:|---:|---:|';

		foreach ( $rows as $r ) {
			$cur    = '' !== $r['currency'] ? $r['currency'] : $store_cur;
			$spend  = (float) $r['spend'];
			$imp    = (int) $r['impressions'];
			$clicks = (int) $r['clicks'];
			$ctr    = $imp > 0 ? number_format_i18n( ( $clicks / $imp ) * 100, 2 ) . '%' : '—';
			$cpc    = $clicks > 0 ? number_format_i18n( $spend / $clicks, 2 ) . ' ' . $cur : '—';
			$cpm    = $imp > 0 ? number_format_i18n( ( $spend / $imp ) * 1000, 2 ) . ' ' . $cur : '—';

			$currencies[ $cur ] = true;
			if ( $cur === $store_cur ) {
				$total_store += $spend;
			}

			$lines[] = '| ' . $this->md_cell( $this->ad_platform_label( $r['platform'] ) )
				. ' | ' . $this->md_cell( $cur )
				. ' | ' . number_format_i18n( $spend, 2 )
				. ' | ' . number_format_i18n( $imp )
				. ' | ' . number_format_i18n( $clicks )
				. ' | ' . $ctr . ' | ' . $cpc . ' | ' . $cpm . ' |';
		}

		// Revenue and blended efficiency. ROAS only when every imported row is
		// billed in the store currency — mixing an unconverted USD spend into a
		// TRY revenue would produce a confident, wrong ratio.
		$single_currency = ( 1 === count( $currencies ) && isset( $currencies[ $store_cur ] ) );
		$revenue         = function_exists( 'brikpanel_get_total_revenue' )
			? (float) brikpanel_get_total_revenue( $w['start_gmt'], $w['end_gmt'], false )
			: 0.0;
		$orders = function_exists( 'brikpanel_get_successful_order_count' )
			? (int) brikpanel_get_successful_order_count( $w['start_gmt'], $w['end_gmt'], false )
			: 0;

		$lines[] = '';
		$lines[] = '- **' . __( 'Total ad spend (store currency)', 'brikpanel' ) . ':** ' . $this->money( $total_store );
		if ( $single_currency ) {
			$lines[] = '- **' . __( 'ROAS (blended)', 'brikpanel' ) . ':** ' . $this->ratio( $revenue, $total_store )
				. ' — *' . __( 'total revenue divided by total ad spend across the whole store, not attributed to ads.', 'brikpanel' ) . '*';
			$lines[] = '- **' . __( 'Ad cost as share of revenue', 'brikpanel' ) . ':** ' . $this->pct( $total_store, $revenue );
			if ( $orders > 0 ) {
				$lines[] = '- **' . __( 'Ad spend per paid order (blended)', 'brikpanel' ) . ':** ' . $this->money( $total_store / $orders )
					. ' — *' . __( 'this is NOT cost per acquisition: no click-to-order attribution is imported.', 'brikpanel' ) . '*';
			}
		} else {
			$lines[] = '- **' . __( 'ROAS', 'brikpanel' ) . ':** — *' . sprintf(
				/* translators: %s: store currency code */
				__( 'not calculated: spend is billed in more than one currency, or in a currency other than the store currency (%s), and BrikPanel does not convert ad spend.', 'brikpanel' ),
				$this->currency_code()
			) . '*';
		}

		// Monthly trend, store-currency rows only.
		$ads = $this->ads_monthly();
		if ( ! empty( $ads['months'] ) ) {
			$platforms = $ads['platforms'];
			sort( $platforms );

			$header = '| ' . __( 'Month', 'brikpanel' );
			foreach ( $platforms as $p ) {
				$header .= ' | ' . $this->md_cell( $this->ad_platform_label( $p ) );
			}
			$header .= ' | ' . __( 'Total', 'brikpanel' ) . ' |';
			$divider = '|---' . str_repeat( '|---:', count( $platforms ) + 1 ) . '|';

			$lines[] = '';
			$lines[] = '### ' . __( 'Monthly Ad Spend', 'brikpanel' );
			$lines[] = $header;
			$lines[] = $divider;

			$months = array_keys( $ads['months'] );
			sort( $months );
			foreach ( $months as $ym ) {
				$row   = '| ' . $ym;
				$total = 0.0;
				foreach ( $platforms as $p ) {
					$v      = isset( $ads['months'][ $ym ][ $p ] ) ? (float) $ads['months'][ $ym ][ $p ] : 0.0;
					$total += $v;
					$row   .= ' | ' . $this->money( $v );
				}
				$row .= ' | ' . $this->money( $total ) . ' |';
				$lines[] = $row;
			}
		}

		$this->register_tldr( 'ad_spend_12m', $total_store );
		if ( $single_currency && $total_store > 0 ) {
			$this->register_tldr( 'roas_12m', $revenue / $total_store );
		}

		$fn = $this->footnote( 'bp_ads' );
		if ( $fn ) { $lines[] = ''; $lines[] = $fn; }

		return implode( "\n", $lines );
	}

	// =========================================================================
	// SECTION: PROFIT BY PRODUCT CATEGORY (last 12 months)
	// =========================================================================

	private function section_category_profit() {
		global $wpdb;

		$rows = $this->product_profit_rows();
		if ( empty( $rows ) ) {
			return '';
		}

		$ids = [];
		foreach ( $rows as $r ) {
			$ids[] = (int) $r->product_id;
		}
		$ids = array_values( array_unique( array_filter( $ids ) ) );
		if ( empty( $ids ) ) {
			return '';
		}
		$in = implode( ',', $ids );

		// Term lookup only — the sales and cost aggregation already happened
		// once in product_profit_rows(). Re-running the cost joins with the
		// taxonomy tables bolted on was the single slowest query in the report.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$terms = $wpdb->get_results(
			"SELECT tr.object_id AS product_id, t.name AS category
			 FROM {$wpdb->term_relationships} tr
			 INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'product_cat'
			 INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
			 WHERE tr.object_id IN ({$in})"
		); // phpcs:ignore
		// phpcs:enable
		if ( empty( $terms ) ) {
			return '';
		}

		$by_product = [];
		foreach ( $rows as $r ) {
			$by_product[ (int) $r->product_id ] = $r;
		}

		$totals        = [];
		$total_revenue = 0.0;
		foreach ( $terms as $t ) {
			$pid = (int) $t->product_id;
			if ( ! isset( $by_product[ $pid ] ) ) {
				continue;
			}
			$r   = $by_product[ $pid ];
			$cat = (string) $t->category;
			if ( ! isset( $totals[ $cat ] ) ) {
				$totals[ $cat ] = [ 'units' => 0.0, 'revenue' => 0.0, 'cogs' => 0.0, 'costed' => 0.0 ];
			}
			$totals[ $cat ]['units']   += (float) $r->qty;
			$totals[ $cat ]['revenue'] += (float) $r->revenue;
			$totals[ $cat ]['cogs']    += (float) $r->cogs;
			$totals[ $cat ]['costed']  += (float) $r->costed_revenue;
		}
		if ( empty( $totals ) ) {
			return '';
		}

		// A product filed under several categories counts in each of them, so
		// the share denominator is the sum of the category rows rather than
		// store revenue — otherwise the column would silently exceed 100%
		// without saying why.
		uasort( $totals, function ( $a, $b ) { return $b['revenue'] <=> $a['revenue']; } );
		foreach ( $totals as $t ) {
			$total_revenue += $t['revenue'];
		}

		$lines   = [];
		$lines[] = '## ' . __( 'Profit by Product Category (last 12 months)', 'brikpanel' );
		$lines[] = '*' . __( 'A product filed under several categories contributes its full revenue to each of them, so these rows overlap and their sum exceeds store revenue. Margin is blank where no product in the category has a cost on file.', 'brikpanel' ) . '*';
		$lines[] = '';
		$lines[] = '| ' . __( 'Category', 'brikpanel' ) . ' | ' . __( 'Units', 'brikpanel' ) . ' | ' . __( 'Revenue', 'brikpanel' ) . ' | ' . __( 'Share', 'brikpanel' ) . ' | ' . __( 'COGS', 'brikpanel' ) . ' | ' . __( 'Gross profit', 'brikpanel' ) . ' | ' . __( 'Margin', 'brikpanel' ) . ' | ' . __( 'Cost coverage', 'brikpanel' ) . ' |';
		$lines[] = '|---|---:|---:|---:|---:|---:|---:|---:|';

		$shown = 0;
		foreach ( $totals as $cat => $t ) {
			if ( $shown >= 15 ) {
				break;
			}
			$revenue  = $t['revenue'];
			$gross    = $revenue - $t['cogs'];
			$has_cost = $t['costed'] > 0;

			$lines[] = '| ' . $this->md_cell( $cat )
				. ' | ' . number_format_i18n( $t['units'] )
				. ' | ' . $this->money( $revenue )
				. ' | ' . $this->pct( $revenue, $total_revenue )
				. ' | ' . ( $has_cost ? $this->money( $t['cogs'] ) : '—' )
				. ' | ' . ( $has_cost ? $this->money( $gross ) : '—' )
				. ' | ' . ( $has_cost && $revenue > 0 ? $this->pct( $gross, $revenue ) : '—' )
				. ' | ' . $this->pct( $t['costed'], $revenue ) . ' |';
			$shown++;
		}

		if ( count( $totals ) > $shown ) {
			$lines[] = '';
			$lines[] = '*' . sprintf(
				/* translators: 1: categories shown, 2: total categories with sales */
				__( 'Showing the top %1$d of %2$d categories with sales in the period.', 'brikpanel' ),
				$shown,
				count( $totals )
			) . '*';
		}

		$fn = $this->footnote( 'bp_cogs' );
		if ( $fn ) { $lines[] = ''; $lines[] = $fn; }

		return implode( "\n", $lines );
	}

	// =========================================================================
	// SECTION: EXPENSES (last 12 months by category + monthly)
	// =========================================================================

	private function section_expenses() {
		global $wpdb;
		$tbl = $wpdb->prefix . 'brikpanel_expenses';

		// Skip if module hasn't been used.
		$exists = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tbl}" ); // phpcs:ignore
		if ( $exists === 0 ) {
			return '';
		}

		// Materialise any due recurring occurrence before reading. Without it
		// the current month's rent/salary row may not exist yet and every
		// total below is short by it.
		if ( class_exists( 'Brikpanel_Expenses' ) && method_exists( 'Brikpanel_Expenses', 'materialize_due' ) ) {
			try {
				Brikpanel_Expenses::materialize_due();
			} catch ( Throwable $e ) {
				unset( $e );
			}
		}

		$w     = $this->profit_window();
		$start = $w['start_date'];

		// Money rows only. A percentage row's `amount` holds a RATE and a
		// per-order row's a UNIT PRICE; summing either turned a 2.9% commission
		// into £2.90 of reported spend. The COUNT(*) probe above deliberately
		// keeps them — a store whose only expense is a commission HAS used the
		// module. Both kinds get their own tables further down.
		$kinds = brikpanel_expense_money_kinds_sql();

		$total_12m = (float) $wpdb->get_var( $wpdb->prepare(
			"SELECT COALESCE(SUM(amount),0) FROM {$tbl} WHERE expense_date >= %s{$kinds}",
			$start
		) ); // phpcs:ignore

		$total_all = (float) $wpdb->get_var( "SELECT COALESCE(SUM(amount),0) FROM {$tbl} WHERE 1=1{$kinds}" ); // phpcs:ignore

		$by_cat = $wpdb->get_results( $wpdb->prepare(
			"SELECT IF(category='', 'uncategorized', category) AS category,
					COALESCE(SUM(amount),0) AS total,
					COUNT(*) AS entries
			 FROM {$tbl}
			 WHERE expense_date >= %s{$kinds}
			 GROUP BY category
			 ORDER BY total DESC",
			$start
		) ); // phpcs:ignore

		$lines = [];
		$lines[] = '## ' . __( 'Expenses', 'brikpanel' );
		$lines[] = '*' . __( 'Recorded money expenses only. Percentage-based and per-order costs are stored as a rate and a unit price, so they are listed separately below and never summed into these totals.', 'brikpanel' ) . '*';
		$lines[] = '';
		$lines[] = '- **' . __( 'Last 12 months total', 'brikpanel' ) . ':** ' . $this->money( $total_12m );
		$lines[] = '- **' . __( 'All-time recorded total', 'brikpanel' ) . ':** ' . $this->money( $total_all );

		if ( ! empty( $by_cat ) ) {
			$lines[] = '';
			$lines[] = '### ' . __( 'By Title (last 12 months)', 'brikpanel' );
			$lines[] = '| ' . __( 'Title', 'brikpanel' ) . ' | ' . __( 'Entries', 'brikpanel' ) . ' | ' . __( 'Total', 'brikpanel' ) . ' | ' . __( 'Share', 'brikpanel' ) . ' |';
			$lines[] = '|---|---:|---:|---:|';
			foreach ( $by_cat as $r ) {
				$lines[] = '| ' . $this->md_cell( $r->category ) . ' | ' . number_format_i18n( $r->entries ) . ' | ' . $this->money( $r->total ) . ' | ' . $this->pct( $r->total, $total_12m ) . ' |';
			}
		}

		// --- Grouped by parent category ------------------------------------
		// The `parent_category` column can hold a stable internal key such as
		// `__brikpanel:shipping` (an expense filed under a COMPUTED profit
		// line). Rendering it raw leaks the key into the report, so every
		// value goes through the module's own display-label resolver.
		if ( function_exists( 'brikpanel_profit_manual_expense_lines' ) ) {
			$expense_lines = brikpanel_profit_manual_expense_lines( $w['start_local'], $w['end_local'] );
			if ( ! empty( $expense_lines ) ) {
				$by_parent = [];
				foreach ( $expense_lines as $l ) {
					$parent = isset( $l['parent'] ) ? (string) $l['parent'] : '';
					$label  = ( '' !== $parent && class_exists( 'Brikpanel_Expenses' ) && method_exists( 'Brikpanel_Expenses', 'parent_display_label' ) )
						? (string) Brikpanel_Expenses::parent_display_label( $parent )
						: $parent;
					if ( '' === $label ) {
						$label = __( 'Ungrouped', 'brikpanel' );
					}
					if ( ! isset( $by_parent[ $label ] ) ) {
						$by_parent[ $label ] = 0.0;
					}
					$by_parent[ $label ] += isset( $l['amount'] ) ? (float) $l['amount'] : 0.0;
				}
				arsort( $by_parent );

				$lines[] = '';
				$lines[] = '### ' . __( 'By Category Group (last 12 months)', 'brikpanel' );
				$lines[] = '| ' . __( 'Group', 'brikpanel' ) . ' | ' . __( 'Total', 'brikpanel' ) . ' | ' . __( 'Share', 'brikpanel' ) . ' |';
				$lines[] = '|---|---:|---:|';
				foreach ( $by_parent as $label => $amount ) {
					$lines[] = '| ' . $this->md_cell( $label ) . ' | ' . $this->money( $amount ) . ' | ' . $this->pct( $amount, $total_12m ) . ' |';
				}

				// Line-level detail, capped so a store with hundreds of rows
				// does not push the report past what an LLM will read.
				$detail = $expense_lines;
				usort( $detail, function ( $a, $b ) {
					$av = isset( $a['amount'] ) ? (float) $a['amount'] : 0.0;
					$bv = isset( $b['amount'] ) ? (float) $b['amount'] : 0.0;
					return $bv <=> $av;
				} );
				$shown = array_slice( $detail, 0, 25 );

				$lines[] = '';
				$lines[] = '### ' . __( 'Expense Lines (last 12 months)', 'brikpanel' );
				$lines[] = '| ' . __( 'Title', 'brikpanel' ) . ' | ' . __( 'Group', 'brikpanel' ) . ' | ' . __( 'Total', 'brikpanel' ) . ' | ' . __( 'Share', 'brikpanel' ) . ' |';
				$lines[] = '|---|---|---:|---:|';
				foreach ( $shown as $l ) {
					$parent = isset( $l['parent'] ) ? (string) $l['parent'] : '';
					$label  = ( '' !== $parent && class_exists( 'Brikpanel_Expenses' ) && method_exists( 'Brikpanel_Expenses', 'parent_display_label' ) )
						? (string) Brikpanel_Expenses::parent_display_label( $parent )
						: $parent;
					$amount = isset( $l['amount'] ) ? (float) $l['amount'] : 0.0;
					$lines[] = '| ' . $this->md_cell( isset( $l['title'] ) ? $l['title'] : '' )
						. ' | ' . $this->md_cell( '' !== $label ? $label : '—' )
						. ' | ' . $this->money( $amount )
						. ' | ' . $this->pct( $amount, $total_12m ) . ' |';
				}
				$remaining = count( $detail ) - count( $shown );
				if ( $remaining > 0 ) {
					$lines[] = '';
					$lines[] = '*' . sprintf(
						/* translators: %s: number of expense lines not listed */
						__( '%s further expense line(s) not listed — they are included in every total above.', 'brikpanel' ),
						number_format_i18n( $remaining )
					) . '*';
				}
			}
		}

		// --- Percentage-based costs ----------------------------------------
		// Read off the memoised snapshot rather than re-querying: that is the
		// same array the Profitability section prints its total from, so the
		// two sections cannot drift apart on a store whose marketplace basis
		// would change the answer — and it saves the repeated schema probes
		// these helpers do on every call.
		$snap = $this->profit_snapshot();
		if ( is_array( $snap ) ) {
			$percent = [
				'items' => (array) ( $snap['percent_expenses'] ?? [] ),
				'total' => 0.0,
			];
			foreach ( $percent['items'] as $item ) {
				$percent['total'] += isset( $item['amount'] ) ? (float) $item['amount'] : 0.0;
			}
			if ( ! empty( $percent['items'] ) ) {
				$lines[] = '';
				$lines[] = '### ' . __( 'Percentage-Based Costs (card commission etc.)', 'brikpanel' );
				$lines[] = '*' . __( 'These carry no date of their own: the rate applies to every period, and the amount is that rate applied to the period\'s revenue.', 'brikpanel' ) . '*';
				$lines[] = '| ' . __( 'Title', 'brikpanel' ) . ' | ' . __( 'Rate', 'brikpanel' ) . ' | ' . __( 'Amount (last 12 months)', 'brikpanel' ) . ' |';
				$lines[] = '|---|---:|---:|';
				foreach ( $percent['items'] as $item ) {
					$lines[] = '| ' . $this->md_cell( isset( $item['title'] ) ? $item['title'] : '' )
						. ' | ' . number_format_i18n( isset( $item['rate'] ) ? (float) $item['rate'] : 0, 2 ) . '%'
						. ' | ' . $this->money( isset( $item['amount'] ) ? $item['amount'] : 0 ) . ' |';
				}
				$lines[] = '| **' . __( 'Total', 'brikpanel' ) . '** | | **' . $this->money( isset( $percent['total'] ) ? $percent['total'] : 0 ) . '** |';
			}
		}

		// --- Per-order costs ------------------------------------------------
		// Same reasoning as the percentage table above.
		if ( is_array( $snap ) ) {
			$per_order = [
				'items' => (array) ( $snap['per_order_expenses'] ?? [] ),
				'total' => (float) ( $snap['per_order_total_raw'] ?? 0 ),
			];
			if ( ! empty( $per_order['items'] ) ) {
				$lines[] = '';
				$lines[] = '### ' . __( 'Per-Order Costs (packaging, courier surcharge etc.)', 'brikpanel' );
				$lines[] = '*' . __( 'Amount is the unit price multiplied by the number of matching paid orders. Each order is claimed by at most one scoped rule, so these rows cannot be re-derived by multiplying independently.', 'brikpanel' ) . '*';
				$lines[] = '| ' . __( 'Title', 'brikpanel' ) . ' | ' . __( 'Applies to', 'brikpanel' ) . ' | ' . __( 'Unit', 'brikpanel' ) . ' | ' . __( 'Orders', 'brikpanel' ) . ' | ' . __( 'Amount (last 12 months)', 'brikpanel' ) . ' |';
				$lines[] = '|---|---|---:|---:|---:|';
				foreach ( $per_order['items'] as $item ) {
					$lines[] = '| ' . $this->md_cell( isset( $item['title'] ) ? $item['title'] : '' )
						. ' | ' . $this->md_cell( isset( $item['scope_label'] ) && '' !== $item['scope_label'] ? $item['scope_label'] : __( 'Every order', 'brikpanel' ) )
						. ' | ' . $this->money( isset( $item['unit'] ) ? $item['unit'] : 0 )
						. ' | ' . number_format_i18n( isset( $item['orders'] ) ? (int) $item['orders'] : 0 )
						. ' | ' . $this->money( isset( $item['amount'] ) ? $item['amount'] : 0 ) . ' |';
				}
				$lines[] = '| **' . __( 'Total', 'brikpanel' ) . '** | | | | **' . $this->money( isset( $per_order['total'] ) ? $per_order['total'] : 0 ) . '** |';
			}
		}

		// --- Monthly trend ---------------------------------------------------
		$monthly = $wpdb->get_results( $wpdb->prepare(
			"SELECT DATE_FORMAT(expense_date, '%%Y-%%m') AS ym, COALESCE(SUM(amount),0) AS total
			 FROM {$tbl}
			 WHERE expense_date >= %s{$kinds}
			 GROUP BY ym
			 ORDER BY ym ASC",
			$start
		) ); // phpcs:ignore

		if ( ! empty( $monthly ) ) {
			$lines[] = '';
			$lines[] = '### ' . __( 'Monthly Expenses (last 12 months)', 'brikpanel' );
			$lines[] = '| ' . __( 'Month', 'brikpanel' ) . ' | ' . __( 'Total', 'brikpanel' ) . ' |';
			$lines[] = '|---|---:|';
			foreach ( $monthly as $r ) {
				$lines[] = '| ' . $this->md_cell( $r->ym ) . ' | ' . $this->money( $r->total ) . ' |';
			}
		}

		$fn = $this->footnote( 'bp_expenses' );
		if ( $fn ) { $lines[] = ''; $lines[] = $fn; }

		return implode( "\n", $lines );
	}

	// =========================================================================
	// SECTION: CATALOG COMPOSITION (product types, virtual/downloadable, stock, COGS)
	// =========================================================================

	private function section_catalog_composition() {
		global $wpdb;

		// Product type taxonomy distribution (simple/variable/grouped/external)
		$type_rows = $wpdb->get_results(
			"SELECT t.name, COUNT(DISTINCT p.ID) AS c
			 FROM {$wpdb->terms} t
			 INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id
			 INNER JOIN {$wpdb->term_relationships} tr ON tr.term_taxonomy_id = tt.term_taxonomy_id
			 INNER JOIN {$wpdb->posts} p ON p.ID = tr.object_id
			 WHERE tt.taxonomy = 'product_type' AND p.post_type='product' AND p.post_status='publish'
			 GROUP BY t.name"
		); // phpcs:ignore
		$by_type = [];
		$total_published = 0;
		foreach ( $type_rows as $r ) {
			$by_type[ $r->name ] = (int) $r->c;
			$total_published   += (int) $r->c;
		}

		// Variation count (only variations of published variable products)
		$variations_count = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts}
			 WHERE post_type='product_variation' AND post_status='publish'"
		); // phpcs:ignore

		// Virtual / downloadable counts (parent products only — variations
		// inherit unless overridden, but counting parents reflects catalog
		// composition more honestly).
		$virtual = (int) $wpdb->get_var(
			"SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id=p.ID
			 WHERE p.post_type='product' AND p.post_status='publish'
			   AND pm.meta_key='_virtual' AND pm.meta_value='yes'"
		); // phpcs:ignore
		$downloadable = (int) $wpdb->get_var(
			"SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id=p.ID
			 WHERE p.post_type='product' AND p.post_status='publish'
			   AND pm.meta_key='_downloadable' AND pm.meta_value='yes'"
		); // phpcs:ignore
		$physical = max( 0, $total_published - $virtual );

		// Stock states (parent products + variations)
		$stock_rows = $wpdb->get_results(
			"SELECT pm.meta_value AS stock_status, COUNT(*) AS c
			 FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id=p.ID AND pm.meta_key='_stock_status'
			 WHERE p.post_type IN ('product','product_variation') AND p.post_status='publish'
			 GROUP BY pm.meta_value"
		); // phpcs:ignore
		$stock_by_state = [];
		foreach ( $stock_rows as $r ) {
			$stock_by_state[ $r->stock_status ] = (int) $r->c;
		}

		// Inventory totals: stock units, retail value, COGS value.
		// Sum across both parent products *and* variations — the actual
		// stock/cost typically lives on the variation row for variable
		// products (parent _stock is NULL). Using both covers the simple
		// product case (cost on parent) and the variable case (cost per
		// variation) without double-counting because variable parents
		// usually carry no _stock value.
		// Cost joins come from the central key list so a store keeping its
		// costs in a third-party cost plugin is not summarised as zero-cost.
		$cost      = brikpanel_cogs_sql_join_set( 'sc', 'p.ID' );
		$cost_keys = brikpanel_cogs_meta_keys();
		$key_in    = "'" . implode( "','", array_map( 'esc_sql', $cost_keys ) ) . "'";

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$inv_row = $wpdb->get_row(
			"SELECT
				COALESCE(SUM(CAST(stock.meta_value AS DECIMAL(20,4))), 0)                                                       AS units,
				COALESCE(SUM(CAST(stock.meta_value AS DECIMAL(20,4)) * CAST(price.meta_value AS DECIMAL(20,4))), 0)             AS retail_value,
				COALESCE(SUM(CAST(stock.meta_value AS DECIMAL(20,4)) * CAST(IFNULL({$cost['value']},'0') AS DECIMAL(20,4))), 0) AS cogs_value
			 FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} stock ON stock.post_id=p.ID AND stock.meta_key='_stock' AND stock.meta_value <> ''
			 LEFT JOIN  {$wpdb->postmeta} price ON price.post_id=p.ID AND price.meta_key='_price'
			 {$cost['joins']}
			 WHERE p.post_type IN ('product','product_variation') AND p.post_status='publish'"
		); // phpcs:ignore

		$products_with_cogs = (int) $wpdb->get_var(
			"SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id=p.ID
			 WHERE p.post_type IN ('product','product_variation') AND p.post_status='publish'
			   AND pm.meta_key IN ({$key_in}) AND pm.meta_value <> '' AND CAST(pm.meta_value AS DECIMAL(20,4)) > 0"
		); // phpcs:ignore
		// phpcs:enable

		// Average price per published product (handy proxy for AOV/positioning)
		$avg_price = (float) $wpdb->get_var(
			"SELECT AVG(CAST(price.meta_value AS DECIMAL(20,4)))
			 FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} price ON price.post_id=p.ID AND price.meta_key='_price' AND price.meta_value <> ''
			 WHERE p.post_type='product' AND p.post_status='publish'"
		); // phpcs:ignore

		$lines = [];
		$lines[] = '## ' . __( 'Catalog Composition', 'brikpanel' );

		// By product type
		$lines[] = '### ' . __( 'By Product Type', 'brikpanel' );
		$lines[] = '| ' . __( 'Type', 'brikpanel' ) . ' | ' . __( 'Count', 'brikpanel' ) . ' | ' . __( 'Share', 'brikpanel' ) . ' |';
		$lines[] = '|---|---:|---:|';
		foreach ( $by_type as $type => $count ) {
			$lines[] = '| ' . $this->md_cell( ucfirst( $type ) ) . ' | ' . number_format_i18n( $count ) . ' | ' . $this->pct( $count, $total_published ) . ' |';
		}
		$lines[] = '| ' . __( 'Variations (across variable products)', 'brikpanel' ) . ' | ' . number_format_i18n( $variations_count ) . ' | — |';
		$lines[] = '';

		// Physical vs digital
		$lines[] = '### ' . __( 'Physical vs Digital', 'brikpanel' );
		$lines[] = '- **' . __( 'Physical products', 'brikpanel' ) . ':** ' . number_format_i18n( $physical ) . ' (' . $this->pct( $physical, $total_published ) . ')';
		$lines[] = '- **' . __( 'Virtual products', 'brikpanel' ) . ':** ' . number_format_i18n( $virtual ) . ' (' . $this->pct( $virtual, $total_published ) . ') — ' . __( 'no shipping required', 'brikpanel' );
		$lines[] = '- **' . __( 'Downloadable products', 'brikpanel' ) . ':** ' . number_format_i18n( $downloadable );
		$lines[] = '- **' . __( 'Average product price', 'brikpanel' ) . ':** ' . $this->money( $avg_price );
		$lines[] = '';

		// Stock
		$lines[] = '### ' . __( 'Stock & Inventory', 'brikpanel' );
		$lines[] = '- **' . __( 'In stock', 'brikpanel' ) . ':** ' . number_format_i18n( $stock_by_state['instock'] ?? 0 );
		$lines[] = '- **' . __( 'Out of stock', 'brikpanel' ) . ':** ' . number_format_i18n( $stock_by_state['outofstock'] ?? 0 );
		$lines[] = '- **' . __( 'On backorder', 'brikpanel' ) . ':** ' . number_format_i18n( $stock_by_state['onbackorder'] ?? 0 );
		$lines[] = '- **' . __( 'Total stock units', 'brikpanel' ) . ':** ' . number_format_i18n( (float) $inv_row->units );
		$lines[] = '- **' . __( 'Inventory retail value', 'brikpanel' ) . ':** ' . $this->money( $inv_row->retail_value );

		if ( $products_with_cogs > 0 ) {
			$lines[] = '- **' . __( 'Inventory at cost (COGS)', 'brikpanel' ) . ':** ' . $this->money( $inv_row->cogs_value ) . ' — *' . sprintf( __( 'across %d products with cost set', 'brikpanel' ), $products_with_cogs ) . '*';
			if ( (float) $inv_row->retail_value > 0 ) {
				$potential_margin = ( (float) $inv_row->retail_value - (float) $inv_row->cogs_value ) / (float) $inv_row->retail_value * 100;
				$lines[] = '- **' . __( 'Implied catalog margin', 'brikpanel' ) . ':** ' . number_format_i18n( $potential_margin, 1 ) . '%';
			}
		} else {
			$lines[] = '- *' . __( 'COGS not configured for any product — sell-through margin cannot be inferred.', 'brikpanel' ) . '*';
		}

		$fn = $this->footnote( 'bp_cogs' );
		if ( $fn ) { $lines[] = ''; $lines[] = $fn; }

		return implode( "\n", $lines );
	}

	// =========================================================================
	// SECTION: SHIPPING & FULFILLMENT (last 12 months)
	// =========================================================================

	private function section_shipping() {
		global $wpdb;

		$start_dt = $this->months_ago_gmt( 12 );

		// Total shipping revenue: HPOS reads from wc_order_operational_data;
		// legacy reads `_order_shipping` from postmeta.
		if ( $this->is_hpos() ) {
			$ship_row = $wpdb->get_row( $wpdb->prepare(
				"SELECT COALESCE(SUM(od.shipping_total_amount),0) AS revenue,
				        COALESCE(SUM(od.shipping_tax_amount),0)   AS tax,
				        COUNT(*) AS orders
				 FROM {$wpdb->prefix}wc_orders o
				 INNER JOIN {$wpdb->prefix}wc_order_operational_data od ON od.order_id = o.id
				 WHERE o.type='shop_order' AND o.status IN (" . brikpanel_paid_statuses_sql() . ")
				   AND o.date_created_gmt >= %s",
				$start_dt
			) ); // phpcs:ignore
		} else {
			$ship_row = $wpdb->get_row( $wpdb->prepare(
				"SELECT COALESCE(SUM(CAST(pm.meta_value AS DECIMAL(20,4))),0) AS revenue,
				        0 AS tax,
				        COUNT(DISTINCT p.ID) AS orders
				 FROM {$wpdb->posts} p
				 LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id=p.ID AND pm.meta_key='_order_shipping'
				 WHERE p.post_type='shop_order' AND p.post_status IN (" . brikpanel_paid_statuses_sql() . ")
				   AND p.post_date_gmt >= %s",
				$start_dt
			) ); // phpcs:ignore
		}

		$ship_revenue = (float) ( $ship_row->revenue ?? 0 );
		$ship_orders  = (int) ( $ship_row->orders ?? 0 );

		// Shipping methods used (line items of type 'shipping')
		if ( $this->is_hpos() ) {
			$method_rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT oi.order_item_name AS method, COUNT(*) AS uses,
				        COALESCE(SUM(CAST(im.meta_value AS DECIMAL(20,4))),0) AS revenue
				 FROM {$wpdb->prefix}woocommerce_order_items oi
				 INNER JOIN {$wpdb->prefix}wc_orders o ON o.id=oi.order_id
				 LEFT JOIN  {$wpdb->prefix}woocommerce_order_itemmeta im ON im.order_item_id=oi.order_item_id AND im.meta_key='cost'
				 WHERE oi.order_item_type='shipping'
				   AND o.type='shop_order' AND o.status IN (" . brikpanel_paid_statuses_sql() . ")
				   AND o.date_created_gmt >= %s
				 GROUP BY oi.order_item_name
				 ORDER BY uses DESC LIMIT 10",
				$start_dt
			) ); // phpcs:ignore
		} else {
			$method_rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT oi.order_item_name AS method, COUNT(*) AS uses,
				        COALESCE(SUM(CAST(im.meta_value AS DECIMAL(20,4))),0) AS revenue
				 FROM {$wpdb->prefix}woocommerce_order_items oi
				 INNER JOIN {$wpdb->posts} p ON p.ID=oi.order_id
				 LEFT JOIN  {$wpdb->prefix}woocommerce_order_itemmeta im ON im.order_item_id=oi.order_item_id AND im.meta_key='cost'
				 WHERE oi.order_item_type='shipping'
				   AND p.post_type='shop_order' AND p.post_status IN (" . brikpanel_paid_statuses_sql() . ")
				   AND p.post_date_gmt >= %s
				 GROUP BY oi.order_item_name
				 ORDER BY uses DESC LIMIT 10",
				$start_dt
			) ); // phpcs:ignore
		}

		// Top destinations by orders (uses HPOS wc_order_addresses if HPOS, else postmeta)
		if ( $this->is_hpos() ) {
			$dest_rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT oa.country, COUNT(*) AS orders, COALESCE(SUM(o.total_amount),0) AS revenue
				 FROM {$wpdb->prefix}wc_orders o
				 INNER JOIN {$wpdb->prefix}wc_order_addresses oa ON oa.order_id=o.id AND oa.address_type='shipping'
				 WHERE o.type='shop_order' AND o.status IN (" . brikpanel_paid_statuses_sql() . ")
				   AND o.date_created_gmt >= %s
				   AND oa.country IS NOT NULL AND oa.country <> ''
				 GROUP BY oa.country
				 ORDER BY orders DESC LIMIT 10",
				$start_dt
			) ); // phpcs:ignore
		} else {
			$dest_rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT pm_country.meta_value AS country, COUNT(*) AS orders,
				        COALESCE(SUM(CAST(pm_total.meta_value AS DECIMAL(20,4))),0) AS revenue
				 FROM {$wpdb->posts} p
				 LEFT JOIN {$wpdb->postmeta} pm_country ON pm_country.post_id=p.ID AND pm_country.meta_key='_shipping_country'
				 LEFT JOIN {$wpdb->postmeta} pm_total   ON pm_total.post_id=p.ID   AND pm_total.meta_key='_order_total'
				 WHERE p.post_type='shop_order' AND p.post_status IN (" . brikpanel_paid_statuses_sql() . ")
				   AND p.post_date_gmt >= %s
				   AND pm_country.meta_value IS NOT NULL AND pm_country.meta_value <> ''
				 GROUP BY pm_country.meta_value
				 ORDER BY orders DESC LIMIT 10",
				$start_dt
			) ); // phpcs:ignore
		}

		// Average fulfillment time (created → completed). Uses
		// wc_order_operational_data.date_completed_gmt on HPOS for accuracy;
		// legacy falls back to post_modified_gmt of completed orders.
		if ( $this->is_hpos() ) {
			$avg_hours = (float) $wpdb->get_var( $wpdb->prepare(
				"SELECT AVG(TIMESTAMPDIFF(HOUR, o.date_created_gmt, od.date_completed_gmt))
				 FROM {$wpdb->prefix}wc_orders o
				 INNER JOIN {$wpdb->prefix}wc_order_operational_data od ON od.order_id=o.id
				 WHERE o.type='shop_order' AND o.status='wc-completed'
				   AND o.date_created_gmt >= %s
				   AND od.date_completed_gmt IS NOT NULL AND od.date_completed_gmt > o.date_created_gmt",
				$start_dt
			) ); // phpcs:ignore
		} else {
			$avg_hours = (float) $wpdb->get_var( $wpdb->prepare(
				"SELECT AVG(TIMESTAMPDIFF(HOUR, post_date_gmt, post_modified_gmt))
				 FROM {$wpdb->posts}
				 WHERE post_type='shop_order' AND post_status='wc-completed'
				   AND post_date_gmt >= %s
				   AND post_modified_gmt > post_date_gmt",
				$start_dt
			) ); // phpcs:ignore
		}

		// Configured shipping zones (from native WC tables — quick health check).
		$zone_count   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}woocommerce_shipping_zones" ); // phpcs:ignore
		$method_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}woocommerce_shipping_zone_methods WHERE is_enabled=1" ); // phpcs:ignore

		// If the store is digital-only, skip the section gracefully.
		$has_any_signal = ( $ship_revenue > 0 ) || ! empty( $method_rows ) || ! empty( $dest_rows ) || $zone_count > 0;
		if ( ! $has_any_signal ) {
			return '';
		}

		$lines = [];
		$lines[] = '## ' . __( 'Shipping & Fulfillment (last 12 months)', 'brikpanel' );
		$lines[] = '- **' . __( 'Shipping revenue', 'brikpanel' ) . ':** ' . $this->money( $ship_revenue ) . ( $ship_orders > 0 ? ' (' . __( 'avg', 'brikpanel' ) . ' ' . $this->money( $ship_revenue / $ship_orders ) . ' / ' . __( 'order', 'brikpanel' ) . ')' : '' );
		if ( ! empty( $ship_row->tax ) ) {
			$lines[] = '- **' . __( 'Shipping tax collected', 'brikpanel' ) . ':** ' . $this->money( $ship_row->tax );
		}
		if ( $avg_hours > 0 ) {
			$lines[] = '- **' . __( 'Avg fulfillment time (created → completed)', 'brikpanel' ) . ':** ' . number_format_i18n( $avg_hours, 1 ) . ' ' . __( 'hours', 'brikpanel' ) . ' (' . number_format_i18n( $avg_hours / 24, 1 ) . ' ' . __( 'days', 'brikpanel' ) . ')';
		}
		$lines[] = '- **' . __( 'Configured shipping zones', 'brikpanel' ) . ':** ' . number_format_i18n( $zone_count ) . ' (' . sprintf( _n( '%d enabled method', '%d enabled methods', $method_count, 'brikpanel' ), $method_count ) . ')';

		if ( ! empty( $method_rows ) ) {
			$lines[] = '';
			$lines[] = '### ' . __( 'Shipping Methods Used', 'brikpanel' );
			$lines[] = '| ' . __( 'Method', 'brikpanel' ) . ' | ' . __( 'Times Used', 'brikpanel' ) . ' | ' . __( 'Total Charged', 'brikpanel' ) . ' |';
			$lines[] = '|---|---:|---:|';
			foreach ( $method_rows as $r ) {
				$lines[] = '| ' . $this->md_cell( $r->method ?: '—' ) . ' | ' . number_format_i18n( $r->uses ) . ' | ' . $this->money( $r->revenue ) . ' |';
			}
		}

		if ( ! empty( $dest_rows ) ) {
			$lines[] = '';
			$lines[] = '### ' . __( 'Top Shipping Destinations', 'brikpanel' );
			$lines[] = '| ' . __( 'Country', 'brikpanel' ) . ' | ' . __( 'Orders', 'brikpanel' ) . ' | ' . __( 'Revenue', 'brikpanel' ) . ' | ' . __( 'AOV', 'brikpanel' ) . ' |';
			$lines[] = '|---|---:|---:|---:|';
			foreach ( $dest_rows as $r ) {
				$aov = $r->orders > 0 ? (float) $r->revenue / (int) $r->orders : 0;
				$country_label = function_exists( 'WC' ) && WC()->countries ? ( WC()->countries->get_countries()[ $r->country ] ?? $r->country ) : $r->country;
				$lines[] = '| ' . $this->md_cell( $country_label ) . ' (' . $r->country . ') | ' . number_format_i18n( $r->orders ) . ' | ' . $this->money( $r->revenue ) . ' | ' . $this->money( $aov ) . ' |';
			}
		}

		$fn = $this->footnote( 'wc_op_data' );
		if ( $fn ) { $lines[] = ''; $lines[] = $fn; }

		return implode( "\n", $lines );
	}

	// =========================================================================
	// SECTION: PROFITABILITY (revenue − refunds − COGS − expenses, last 12 months)
	// =========================================================================

	/**
	 * Month-by-month trend for the components that group cleanly by calendar
	 * month. Deliberately NOT twelve brikpanel_profit_snapshot() calls: that
	 * would be a hundred-plus queries for a table, and the exact totals are
	 * already in the headline block above it.
	 *
	 * Bases match the headline so the columns reconcile: revenue uses the KPI
	 * status set with multi-currency conversion (as brikpanel_get_total_revenue
	 * does), refunds are attributed to the PARENT order's month (as
	 * brikpanel_profit_returns does, not the refund's own date), and COGS
	 * resolves cost from the product exactly as brikpanel_profit_cogs does.
	 *
	 * @return array<string, array{rev:float,ref:float,cogs:float,ads:float,exp:float}>
	 */
	private function monthly_profit_axis() {
		global $wpdb;

		$w       = $this->profit_window();
		$is_hpos = $this->is_hpos();

		$axis = [];
		for ( $i = 11; $i >= 0; $i-- ) {
			$key = gmdate( 'Y-m', strtotime( '-' . $i . ' months', current_time( 'timestamp' ) ) );
			$axis[ $key ] = [ 'rev' => 0.0, 'ref' => 0.0, 'cogs' => 0.0, 'ads' => 0.0, 'exp' => 0.0 ];
		}

		$kpi_statuses = function_exists( 'brikpanel_kpi_revenue_statuses' )
			? brikpanel_kpi_revenue_statuses()
			: [ 'wc-processing', 'wc-completed' ];
		$kpi_sp = implode( ', ', array_fill( 0, count( $kpi_statuses ), '%s' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// --- Revenue -------------------------------------------------------
		if ( $is_hpos ) {
			$fx  = brikpanel_base_total_sql( true, 'o.id', 'o.total_amount' );
			$sql = "SELECT DATE_FORMAT(o.date_created_gmt, '%%Y-%%m') AS ym, COALESCE(SUM({$fx['expr']}),0) AS v
					FROM {$wpdb->prefix}wc_orders o{$fx['join']}
					WHERE o.type='shop_order' AND o.status IN ({$kpi_sp})
					  AND o.date_created_gmt >= %s AND o.date_created_gmt <= %s";
			$excl = brikpanel_admin_order_exclusion_sql( true );
			if ( ! empty( $excl['sql'] ) ) {
				$sql .= str_replace( 'customer_id', 'o.customer_id', $excl['sql'] );
			}
		} else {
			$fx  = brikpanel_base_total_sql( false, 'o.ID', 'pm.meta_value' );
			$sql = "SELECT DATE_FORMAT(o.post_date_gmt, '%%Y-%%m') AS ym, COALESCE(SUM({$fx['expr']}),0) AS v
					FROM {$wpdb->posts} o
					LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id=o.ID AND pm.meta_key='_order_total'{$fx['join']}
					WHERE o.post_type='shop_order' AND o.post_status IN ({$kpi_sp})
					  AND o.post_date_gmt >= %s AND o.post_date_gmt <= %s";
			$excl = brikpanel_admin_order_exclusion_sql( false, 'o.ID' );
			if ( ! empty( $excl['sql'] ) ) {
				$sql .= $excl['sql'];
			}
		}
		$args = array_merge( $kpi_statuses, [ $w['start_gmt'], $w['end_gmt'] ], empty( $excl['sql'] ) ? [] : $excl['args'] );
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( $sql . ' GROUP BY ym', $args ) ) as $r ) { // phpcs:ignore
			if ( isset( $axis[ $r->ym ] ) ) { $axis[ $r->ym ]['rev'] = (float) $r->v; }
		}

		// --- Refunds (bucketed by the parent order's month) ------------------
		$pred = $this->paid_order_predicate( 'o' );
		if ( $is_hpos ) {
			$sql = "SELECT DATE_FORMAT(o.date_created_gmt, '%%Y-%%m') AS ym, COALESCE(SUM(ABS(r.total_amount)),0) AS v
					FROM {$wpdb->prefix}wc_orders r
					INNER JOIN {$wpdb->prefix}wc_orders o ON o.id = r.parent_order_id
					WHERE r.type='shop_order_refund' AND {$pred['where']}
					  AND {$pred['date_col']} >= %s AND {$pred['date_col']} <= %s
					GROUP BY ym";
		} else {
			$sql = "SELECT DATE_FORMAT(o.post_date_gmt, '%%Y-%%m') AS ym, COALESCE(SUM(CAST(IFNULL(ra.meta_value,'0') AS DECIMAL(20,4))),0) AS v
					FROM {$wpdb->posts} r
					INNER JOIN {$wpdb->posts} o ON o.ID = r.post_parent
					LEFT JOIN {$wpdb->postmeta} ra ON ra.post_id = r.ID AND ra.meta_key='_refund_amount'
					WHERE r.post_type='shop_order_refund' AND {$pred['where']}
					  AND {$pred['date_col']} >= %s AND {$pred['date_col']} <= %s
					GROUP BY ym";
		}
		$args = array_merge( $pred['args'], [ $w['start_gmt'], $w['end_gmt'] ] );
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( $sql, $args ) ) as $r ) { // phpcs:ignore
			if ( isset( $axis[ $r->ym ] ) ) { $axis[ $r->ym ]['ref'] = (float) $r->v; }
		}

		// --- COGS ------------------------------------------------------------
		$cost = $this->cogs_line_sql();
		if ( null !== $cost ) {
			$ord = $is_hpos ? "{$wpdb->prefix}wc_orders" : $wpdb->posts;
			$oid = $is_hpos ? 'o.id' : 'o.ID';
			$sql = "SELECT DATE_FORMAT({$pred['date_col']}, '%%Y-%%m') AS ym,
						COALESCE(SUM(CAST(qtym.meta_value AS DECIMAL(20,4)) * ({$cost['unit']})),0) AS v
					FROM {$wpdb->prefix}woocommerce_order_items oi
					INNER JOIN {$ord} o ON {$oid} = oi.order_id
					INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta qtym
							ON qtym.order_item_id = oi.order_item_id AND qtym.meta_key='_qty'
					{$cost['joins']}
					WHERE oi.order_item_type='line_item' AND {$pred['where']}
					  AND {$pred['date_col']} >= %s AND {$pred['date_col']} <= %s
					GROUP BY ym";
			$args = array_merge( $pred['args'], [ $w['start_gmt'], $w['end_gmt'] ] );
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( $sql, $args ) ) as $r ) { // phpcs:ignore
				if ( isset( $axis[ $r->ym ] ) ) { $axis[ $r->ym ]['cogs'] = (float) $r->v; }
			}
		}
		// phpcs:enable

		// --- Recorded money expenses -----------------------------------------
		if ( function_exists( 'brikpanel_expense_money_kinds_sql' ) ) {
			$kinds = brikpanel_expense_money_kinds_sql();
			$rows  = $wpdb->get_results( $wpdb->prepare(
				"SELECT DATE_FORMAT(expense_date, '%%Y-%%m') AS ym, COALESCE(SUM(amount),0) AS v
				 FROM {$wpdb->prefix}brikpanel_expenses
				 WHERE expense_date >= %s{$kinds}
				 GROUP BY ym",
				$w['start_date']
			) ); // phpcs:ignore
			foreach ( (array) $rows as $r ) {
				if ( isset( $axis[ $r->ym ] ) ) { $axis[ $r->ym ]['exp'] = (float) $r->v; }
			}
		}

		// --- Ad spend (store currency only) ----------------------------------
		$ads = $this->ads_monthly();
		foreach ( $ads['months'] as $ym => $per_platform ) {
			if ( isset( $axis[ $ym ] ) ) {
				$axis[ $ym ]['ads'] = (float) array_sum( $per_platform );
			}
		}

		return $axis;
	}

	private function section_profitability() {
		$snap = $this->profit_snapshot();
		if ( ! is_array( $snap ) ) {
			return '';
		}

		$revenue      = (float) ( $snap['revenue_raw'] ?? 0 );
		$revenue_net  = (float) ( $snap['revenue_net_raw'] ?? 0 );
		$returns      = (float) ( $snap['returns_raw'] ?? 0 );
		$cogs         = (float) ( $snap['cogs_raw'] ?? 0 );
		$expenses     = (float) ( $snap['expenses_total_raw'] ?? 0 );
		$net          = (float) ( $snap['net_raw'] ?? 0 );
		$ads          = (float) ( $snap['ad_spend_raw'] ?? 0 );
		$gross        = $revenue_net - $cogs;
		$has_cogs     = ! empty( $snap['has_cogs'] );

		$lines   = [];
		$lines[] = '## ' . __( 'Profitability (last 12 months)', 'brikpanel' );
		$lines[] = '*' . __( 'These are the same figures the BrikPanel Dashboard profit card shows. Net = Revenue − Refunds − Cost of goods − every tracked cost (advertising, tax, payment fees, shipping cost, recorded expenses, percentage and per-order costs).', 'brikpanel' ) . '*';
		$lines[] = '';
		$lines[] = '- **' . __( 'Gross sales', 'brikpanel' ) . ':** ' . $this->money( $revenue );
		$lines[] = '- **' . __( 'Refunds', 'brikpanel' ) . ':** ' . $this->money( $returns );
		$lines[] = '- **' . __( 'Net revenue', 'brikpanel' ) . ':** ' . $this->money( $revenue_net );
		if ( $has_cogs ) {
			$lines[] = '- **' . __( 'Cost of goods', 'brikpanel' ) . ':** ' . $this->money( $cogs ) . ' (' . $this->pct( $cogs, $revenue_net ) . ' ' . __( 'of net revenue', 'brikpanel' ) . ')';
			$lines[] = '- **' . __( 'Gross profit', 'brikpanel' ) . ':** ' . $this->money( $gross ) . ' (' . __( 'gross margin', 'brikpanel' ) . ' ' . $this->pct( $gross, $revenue_net ) . ')';
		} else {
			$lines[] = '- **' . __( 'Cost of goods', 'brikpanel' ) . ':** ' . __( 'not available — no sold product has a cost on file, so gross margin cannot be stated.', 'brikpanel' );
		}
		$lines[] = '- **' . __( 'Total costs', 'brikpanel' ) . ':** ' . $this->money( $expenses ) . ' (' . $this->pct( $expenses, $revenue_net ) . ' ' . __( 'of net revenue', 'brikpanel' ) . ')';
		$lines[] = '- **' . __( 'Net profit', 'brikpanel' ) . ':** ' . $this->money( $net ) . ' (' . __( 'net margin', 'brikpanel' ) . ' ' . $this->pct( $net, $revenue_net ) . ')';

		$this->register_tldr( 'net_profit_12m', $net );
		$this->register_tldr( 'net_margin_12m', $revenue_net > 0 ? $net / $revenue_net : 0.0 );
		// Registered unconditionally: a store with NO costs on file is exactly
		// the one whose TL;DR net margin most needs the caveat attached, and
		// gating this on has_cogs dropped it precisely there.
		$this->register_tldr( 'cogs_coverage_pct', (float) ( $snap['cogs_coverage_pct'] ?? 0 ) );

		// --- Cost breakdown --------------------------------------------------
		// `breakdown` is the mutually exclusive component split. Percentage and
		// per-order costs are deliberately NOT in it (they are separate keys on
		// the snapshot), so they are appended as their own rows rather than
		// being folded in twice.
		$fixed_labels = [
			'google_ads'   => __( 'Google Ads', 'brikpanel' ),
			'meta_ads'     => __( 'Meta Ads', 'brikpanel' ),
			'tax'          => __( 'Tax', 'brikpanel' ),
			'payment_fees' => __( 'Payment fees', 'brikpanel' ),
			'shipping'     => __( 'Shipping cost', 'brikpanel' ),
			'inventory'    => __( 'Supplier / stock', 'brikpanel' ),
			'other'        => __( 'Other recorded expenses', 'brikpanel' ),
		];

		$rows = [];
		foreach ( (array) ( $snap['breakdown'] ?? [] ) as $key => $amount ) {
			$amount = (float) $amount;
			if ( $amount <= 0 ) {
				continue;
			}
			$rows[] = [ isset( $fixed_labels[ $key ] ) ? $fixed_labels[ $key ] : $key, $amount ];
		}
		$percent_total = 0.0;
		foreach ( (array) ( $snap['percent_expenses'] ?? [] ) as $item ) {
			$percent_total += isset( $item['amount'] ) ? (float) $item['amount'] : 0.0;
		}
		if ( $percent_total > 0 ) {
			$rows[] = [ __( 'Percentage-based costs', 'brikpanel' ), $percent_total ];
		}
		$per_order_total = (float) ( $snap['per_order_total_raw'] ?? 0 );
		if ( $per_order_total > 0 ) {
			$rows[] = [ __( 'Per-order costs', 'brikpanel' ), $per_order_total ];
		}

		if ( ! empty( $rows ) ) {
			usort( $rows, function ( $a, $b ) { return $b[1] <=> $a[1]; } );
			$lines[] = '';
			$lines[] = '### ' . __( 'Where the money went (last 12 months)', 'brikpanel' );
			$lines[] = '| ' . __( 'Cost', 'brikpanel' ) . ' | ' . __( 'Amount', 'brikpanel' ) . ' | ' . __( 'Share of costs', 'brikpanel' ) . ' | ' . __( 'Share of net revenue', 'brikpanel' ) . ' |';
			$lines[] = '|---|---:|---:|---:|';
			foreach ( $rows as $row ) {
				$lines[] = '| ' . $this->md_cell( $row[0] ) . ' | ' . $this->money( $row[1] ) . ' | ' . $this->pct( $row[1], $expenses ) . ' | ' . $this->pct( $row[1], $revenue_net ) . ' |';
			}
		}

		// Recorded expenses split by their own category, so a reader sees
		// "Salaries / Rent / Ads agency" rather than one "Other" lump.
		$by_category = (array) ( $snap['expense_categories'] ?? [] );
		if ( ! empty( $by_category ) ) {
			$lines[] = '';
			$lines[] = '### ' . __( 'Recorded expenses by category', 'brikpanel' );
			$lines[] = '| ' . __( 'Category', 'brikpanel' ) . ' | ' . __( 'Amount', 'brikpanel' ) . ' |';
			$lines[] = '|---|---:|';
			foreach ( $by_category as $label => $amount ) {
				$lines[] = '| ' . $this->md_cell( $label ) . ' | ' . $this->money( $amount ) . ' |';
			}
		}

		// --- Monthly trend ---------------------------------------------------
		$axis = $this->monthly_profit_axis();

		// Strict collapse: drop EVERY all-zero month, leading or interior.
		$active_ym = [];
		foreach ( $axis as $ym => $r ) {
			if ( $this->is_zero_row( [ $r['rev'], $r['ref'], $r['cogs'], $r['ads'], $r['exp'] ] ) ) {
				continue;
			}
			$active_ym[ $ym ] = $r;
		}
		if ( empty( $active_ym ) && ! empty( $axis ) ) {
			$last_key = array_key_last( $axis );
			$active_ym[ $last_key ] = $axis[ $last_key ];
		}

		if ( ! empty( $active_ym ) ) {
			$lines[] = '';
			$lines[] = '### ' . __( 'Monthly Trend', 'brikpanel' );
			$lines[] = '*' . __( 'Trend only. Costs that are computed for the period as a whole — percentage-based costs, per-order costs, payment fees, shipping cost and tax — are not split per month, so the monthly Net is higher than the true one. The headline figures above are the complete ones.', 'brikpanel' ) . '*';
			$lines[] = '| ' . __( 'Month', 'brikpanel' ) . ' | ' . __( 'Revenue', 'brikpanel' ) . ' | ' . __( 'Refunds', 'brikpanel' ) . ' | ' . __( 'COGS', 'brikpanel' ) . ' | ' . __( 'Ad spend', 'brikpanel' ) . ' | ' . __( 'Expenses', 'brikpanel' ) . ' | ' . __( 'Gross', 'brikpanel' ) . ' | ' . __( 'Net (partial)', 'brikpanel' ) . ' |';
			$lines[] = '|---|---:|---:|---:|---:|---:|---:|---:|';
			foreach ( $active_ym as $ym => $r ) {
				$g = $r['rev'] - $r['ref'] - $r['cogs'];
				$n = $g - $r['ads'] - $r['exp'];
				$lines[] = '| ' . $ym
					. ' | ' . $this->money( $r['rev'] )
					. ' | ' . $this->money( $r['ref'] )
					. ' | ' . $this->money( $r['cogs'] )
					. ' | ' . $this->money( $r['ads'] )
					. ' | ' . $this->money( $r['exp'] )
					. ' | ' . $this->money( $g )
					. ' | ' . $this->money( $n ) . ' |';
			}

			$shown        = count( $active_ym );
			$total_months = count( $axis );
			if ( $shown < $total_months ) {
				$lines[] = '';
				$lines[] = '*' . sprintf(
					/* translators: 1: months shown, 2: total months in window */
					__( 'Showing %1$d active month(s) out of %2$d in the 12-month window — months with zero across every column are hidden.', 'brikpanel' ),
					$shown,
					$total_months
				) . '*';
			}
		}

		$fn_cogs = $has_cogs ? $this->footnote( 'bp_cogs' ) : '';
		$fn_exp  = $this->footnote( 'bp_expenses' );
		if ( $fn_cogs || $fn_exp ) {
			$lines[] = '';
			if ( $fn_cogs ) { $lines[] = $fn_cogs; }
			if ( $fn_exp )  { $lines[] = $fn_exp;  }
		}

		return implode( "\n", $lines );
	}

	// =========================================================================
	// SECTION: NEW VS RETURNING REVENUE SPLIT (last 12 months, monthly)
	// =========================================================================

	private function section_new_vs_returning() {
		global $wpdb;
		$tbl_metrics = $wpdb->prefix . 'brikpanel_customer_metrics';

		// Skip silently if customer_metrics is empty.
		$has_data = (int) $wpdb->get_var( "SELECT 1 FROM {$tbl_metrics} LIMIT 1" ); // phpcs:ignore
		if ( ! $has_data ) {
			return '';
		}

		$start_dt = $this->months_ago_gmt( 12 );

		// Classify each order: "first" if its date matches the customer's
		// first_order_date in the metrics table, else "returning". The join
		// key mirrors how `customer_metrics` is keyed (user_id when > 0,
		// otherwise email).
		if ( $this->is_hpos() ) {
			// Split the customer<->order match into two single-column equi-joins
			// wrapped in a UNION ALL, instead of a single OR across two columns.
			// An OR in the ON clause defeats index usage (MySQL falls back to a
			// block-nested-loop full scan of the metrics table for every order),
			// which times out on large stores. Each branch below is a plain
			// equi-join that can use idx_user_id / idx_customer_email.
			$paid = brikpanel_paid_statuses_sql();
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT ym,
				        SUM(new_rev) AS new_rev, SUM(ret_rev) AS ret_rev,
				        SUM(new_orders) AS new_orders, SUM(ret_orders) AS ret_orders
				 FROM (
				   SELECT DATE_FORMAT(o.date_created_gmt, '%%Y-%%m') AS ym,
				          SUM(CASE WHEN o.date_created_gmt = m.first_order_date THEN o.total_amount ELSE 0 END) AS new_rev,
				          SUM(CASE WHEN o.date_created_gmt > m.first_order_date THEN o.total_amount ELSE 0 END) AS ret_rev,
				          SUM(CASE WHEN o.date_created_gmt = m.first_order_date THEN 1 ELSE 0 END) AS new_orders,
				          SUM(CASE WHEN o.date_created_gmt > m.first_order_date THEN 1 ELSE 0 END) AS ret_orders
				   FROM {$wpdb->prefix}wc_orders o
				   INNER JOIN {$tbl_metrics} m ON m.user_id = o.customer_id
				   WHERE o.type='shop_order' AND o.status IN ({$paid})
				     AND o.customer_id > 0
				     AND o.date_created_gmt >= %s
				   GROUP BY ym
				   UNION ALL
				   SELECT DATE_FORMAT(o.date_created_gmt, '%%Y-%%m') AS ym,
				          SUM(CASE WHEN o.date_created_gmt = m.first_order_date THEN o.total_amount ELSE 0 END) AS new_rev,
				          SUM(CASE WHEN o.date_created_gmt > m.first_order_date THEN o.total_amount ELSE 0 END) AS ret_rev,
				          SUM(CASE WHEN o.date_created_gmt = m.first_order_date THEN 1 ELSE 0 END) AS new_orders,
				          SUM(CASE WHEN o.date_created_gmt > m.first_order_date THEN 1 ELSE 0 END) AS ret_orders
				   FROM {$wpdb->prefix}wc_orders o
				   INNER JOIN {$tbl_metrics} m ON m.customer_email = o.billing_email
				   WHERE o.type='shop_order' AND o.status IN ({$paid})
				     AND o.customer_id = 0 AND o.billing_email <> ''
				     AND o.date_created_gmt >= %s
				   GROUP BY ym
				 ) u
				 GROUP BY ym
				 ORDER BY ym ASC",
				$start_dt,
				$start_dt
			) ); // phpcs:ignore
		} else {
			// Legacy postmeta path (rarely hit since most stores are HPOS now).
			// Same OR-join rewrite as the HPOS branch: one UNION ALL leg keyed on
			// the registered user id, one on the guest billing email, so each leg
			// is a plain equi-join against an indexed metrics column.
			$paid = brikpanel_paid_statuses_sql();
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT ym,
				        SUM(new_rev) AS new_rev, SUM(ret_rev) AS ret_rev,
				        SUM(new_orders) AS new_orders, SUM(ret_orders) AS ret_orders
				 FROM (
				   SELECT DATE_FORMAT(p.post_date_gmt, '%%Y-%%m') AS ym,
				          SUM(CASE WHEN p.post_date_gmt = m.first_order_date THEN CAST(pm_t.meta_value AS DECIMAL(20,4)) ELSE 0 END) AS new_rev,
				          SUM(CASE WHEN p.post_date_gmt > m.first_order_date THEN CAST(pm_t.meta_value AS DECIMAL(20,4)) ELSE 0 END) AS ret_rev,
				          SUM(CASE WHEN p.post_date_gmt = m.first_order_date THEN 1 ELSE 0 END) AS new_orders,
				          SUM(CASE WHEN p.post_date_gmt > m.first_order_date THEN 1 ELSE 0 END) AS ret_orders
				   FROM {$wpdb->posts} p
				   INNER JOIN {$wpdb->postmeta} pm_c ON pm_c.post_id=p.ID AND pm_c.meta_key='_customer_user' AND CAST(pm_c.meta_value AS UNSIGNED) > 0
				   INNER JOIN {$tbl_metrics} m ON m.user_id = CAST(pm_c.meta_value AS UNSIGNED)
				   LEFT JOIN {$wpdb->postmeta} pm_t ON pm_t.post_id=p.ID AND pm_t.meta_key='_order_total'
				   WHERE p.post_type='shop_order' AND p.post_status IN ({$paid})
				     AND p.post_date_gmt >= %s
				   GROUP BY ym
				   UNION ALL
				   SELECT DATE_FORMAT(p.post_date_gmt, '%%Y-%%m') AS ym,
				          SUM(CASE WHEN p.post_date_gmt = m.first_order_date THEN CAST(pm_t.meta_value AS DECIMAL(20,4)) ELSE 0 END) AS new_rev,
				          SUM(CASE WHEN p.post_date_gmt > m.first_order_date THEN CAST(pm_t.meta_value AS DECIMAL(20,4)) ELSE 0 END) AS ret_rev,
				          SUM(CASE WHEN p.post_date_gmt = m.first_order_date THEN 1 ELSE 0 END) AS new_orders,
				          SUM(CASE WHEN p.post_date_gmt > m.first_order_date THEN 1 ELSE 0 END) AS ret_orders
				   FROM {$wpdb->posts} p
				   INNER JOIN {$wpdb->postmeta} pm_e ON pm_e.post_id=p.ID AND pm_e.meta_key='_billing_email' AND pm_e.meta_value <> ''
				   INNER JOIN {$tbl_metrics} m ON m.customer_email = pm_e.meta_value
				   LEFT JOIN {$wpdb->postmeta} pm_c ON pm_c.post_id=p.ID AND pm_c.meta_key='_customer_user'
				   LEFT JOIN {$wpdb->postmeta} pm_t ON pm_t.post_id=p.ID AND pm_t.meta_key='_order_total'
				   WHERE p.post_type='shop_order' AND p.post_status IN ({$paid})
				     AND CAST(IFNULL(pm_c.meta_value,'0') AS UNSIGNED) = 0
				     AND p.post_date_gmt >= %s
				   GROUP BY ym
				 ) u
				 GROUP BY ym
				 ORDER BY ym ASC",
				$start_dt,
				$start_dt
			) ); // phpcs:ignore
		}

		if ( empty( $rows ) ) {
			return '';
		}

		$tot_new_rev = 0.0; $tot_ret_rev = 0.0; $tot_new_o = 0; $tot_ret_o = 0;
		foreach ( $rows as $r ) {
			$tot_new_rev += (float) $r->new_rev;
			$tot_ret_rev += (float) $r->ret_rev;
			$tot_new_o   += (int) $r->new_orders;
			$tot_ret_o   += (int) $r->ret_orders;
		}
		$grand_rev = $tot_new_rev + $tot_ret_rev;
		$grand_o   = $tot_new_o + $tot_ret_o;
		if ( $grand_rev <= 0 ) { return ''; }

		$lines = [];
		$lines[] = '## ' . __( 'New vs Returning Revenue (last 12 months)', 'brikpanel' );
		$lines[] = '- **' . __( 'New customer revenue', 'brikpanel' ) . ':** ' . $this->money( $tot_new_rev ) . ' (' . $this->pct( $tot_new_rev, $grand_rev ) . '), ' . number_format_i18n( $tot_new_o ) . ' ' . __( 'orders', 'brikpanel' );
		$lines[] = '- **' . __( 'Returning customer revenue', 'brikpanel' ) . ':** ' . $this->money( $tot_ret_rev ) . ' (' . $this->pct( $tot_ret_rev, $grand_rev ) . '), ' . number_format_i18n( $tot_ret_o ) . ' ' . __( 'orders', 'brikpanel' );
		$lines[] = '';
		$lines[] = '| ' . __( 'Month', 'brikpanel' ) . ' | ' . __( 'New revenue', 'brikpanel' ) . ' | ' . __( 'Returning revenue', 'brikpanel' ) . ' | ' . __( 'New orders', 'brikpanel' ) . ' | ' . __( 'Returning orders', 'brikpanel' ) . ' | ' . __( 'Returning %', 'brikpanel' ) . ' |';
		$lines[] = '|---|---:|---:|---:|---:|---:|';
		foreach ( $rows as $r ) {
			$tot_rev_m = (float) $r->new_rev + (float) $r->ret_rev;
			$ret_pct = $tot_rev_m > 0 ? number_format_i18n( ( (float) $r->ret_rev / $tot_rev_m ) * 100, 1 ) . '%' : '—';
			$lines[] = '| ' . $r->ym . ' | ' . $this->money( $r->new_rev ) . ' | ' . $this->money( $r->ret_rev ) . ' | ' . number_format_i18n( $r->new_orders ) . ' | ' . number_format_i18n( $r->ret_orders ) . ' | ' . $ret_pct . ' |';
		}

		$this->register_tldr( 'returning_revenue_share_12m', $grand_rev > 0 ? $tot_ret_rev / $grand_rev : 0 );

		$fn = $this->footnote( 'bp_metrics' );
		if ( $fn ) { $lines[] = ''; $lines[] = $fn; }

		return implode( "\n", $lines );
	}

	// =========================================================================
	// SECTION: BEST / WORST SALES TIMES (day-of-week + hour-of-day)
	// =========================================================================

	private function section_best_worst_times() {
		global $wpdb;
		$start_dt = $this->months_ago_gmt( 12 );

		if ( $this->is_hpos() ) {
			$dow_rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT DAYOFWEEK(date_created_gmt) AS dow, COUNT(*) AS orders, COALESCE(SUM(total_amount),0) AS revenue
				 FROM {$wpdb->prefix}wc_orders
				 WHERE type='shop_order' AND status IN (" . brikpanel_paid_statuses_sql() . ")
				   AND date_created_gmt >= %s
				 GROUP BY dow",
				$start_dt
			) ); // phpcs:ignore
			$hr_rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT HOUR(date_created_gmt) AS hr, COUNT(*) AS orders, COALESCE(SUM(total_amount),0) AS revenue
				 FROM {$wpdb->prefix}wc_orders
				 WHERE type='shop_order' AND status IN (" . brikpanel_paid_statuses_sql() . ")
				   AND date_created_gmt >= %s
				 GROUP BY hr",
				$start_dt
			) ); // phpcs:ignore
		} else {
			$dow_rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT DAYOFWEEK(post_date_gmt) AS dow, COUNT(*) AS orders, COALESCE(SUM(CAST(pm.meta_value AS DECIMAL(20,4))),0) AS revenue
				 FROM {$wpdb->posts} p
				 LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id=p.ID AND pm.meta_key='_order_total'
				 WHERE p.post_type='shop_order' AND p.post_status IN (" . brikpanel_paid_statuses_sql() . ")
				   AND p.post_date_gmt >= %s
				 GROUP BY dow",
				$start_dt
			) ); // phpcs:ignore
			$hr_rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT HOUR(post_date_gmt) AS hr, COUNT(*) AS orders, COALESCE(SUM(CAST(pm.meta_value AS DECIMAL(20,4))),0) AS revenue
				 FROM {$wpdb->posts} p
				 LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id=p.ID AND pm.meta_key='_order_total'
				 WHERE p.post_type='shop_order' AND p.post_status IN (" . brikpanel_paid_statuses_sql() . ")
				   AND p.post_date_gmt >= %s
				 GROUP BY hr",
				$start_dt
			) ); // phpcs:ignore
		}

		if ( empty( $dow_rows ) || empty( $hr_rows ) ) {
			return '';
		}

		// MySQL DAYOFWEEK: 1=Sunday, 2=Monday, …, 7=Saturday.
		$dow_names = [
			1 => __( 'Sun', 'brikpanel' ), 2 => __( 'Mon', 'brikpanel' ), 3 => __( 'Tue', 'brikpanel' ),
			4 => __( 'Wed', 'brikpanel' ), 5 => __( 'Thu', 'brikpanel' ), 6 => __( 'Fri', 'brikpanel' ),
			7 => __( 'Sat', 'brikpanel' ),
		];

		$dow_data = [];
		foreach ( $dow_rows as $r ) {
			$dow_data[ (int) $r->dow ] = [ 'orders' => (int) $r->orders, 'revenue' => (float) $r->revenue ];
		}
		$hr_data = [];
		foreach ( $hr_rows as $r ) {
			$hr_data[ (int) $r->hr ] = [ 'orders' => (int) $r->orders, 'revenue' => (float) $r->revenue ];
		}

		// "Best/worst" can rank by revenue OR by orders — these can disagree
		// (e.g. Saturday has more orders but lower AOV → Mon wins by revenue).
		// Showing both removes ambiguity.
		$rank = function ( $data, $field, $direction = 'desc' ) {
			$best = null;
			foreach ( $data as $key => $d ) {
				if ( $best === null
					|| ( $direction === 'desc' && $d[ $field ] > $data[ $best ][ $field ] )
					|| ( $direction === 'asc'  && $d[ $field ] < $data[ $best ][ $field ] )
				) { $best = $key; }
			}
			return $best;
		};

		$best_dow_rev    = $rank( $dow_data, 'revenue', 'desc' );
		$best_dow_orders = $rank( $dow_data, 'orders',  'desc' );
		$worst_dow_rev   = $rank( $dow_data, 'revenue', 'asc'  );
		$best_hr_rev     = $rank( $hr_data,  'revenue', 'desc' );
		$best_hr_orders  = $rank( $hr_data,  'orders',  'desc' );
		$worst_hr_rev    = $rank( $hr_data,  'revenue', 'asc'  );

		$lines = [];
		$lines[] = '## ' . __( 'Best & Worst Sales Times (last 12 months)', 'brikpanel' );
		$lines[] = '- **' . __( 'Best day by revenue', 'brikpanel' ) . ':** ' . ( $dow_names[ $best_dow_rev ] ?? '?' ) . ' — ' . $this->money( $dow_data[ $best_dow_rev ]['revenue'] ) . ' (' . number_format_i18n( $dow_data[ $best_dow_rev ]['orders'] ) . ' ' . __( 'orders', 'brikpanel' ) . ')';
		$lines[] = '- **' . __( 'Best day by order count', 'brikpanel' ) . ':** ' . ( $dow_names[ $best_dow_orders ] ?? '?' ) . ' — ' . number_format_i18n( $dow_data[ $best_dow_orders ]['orders'] ) . ' ' . __( 'orders', 'brikpanel' ) . ' (' . $this->money( $dow_data[ $best_dow_orders ]['revenue'] ) . ')';
		$lines[] = '- **' . __( 'Worst day by revenue', 'brikpanel' ) . ':** ' . ( $dow_names[ $worst_dow_rev ] ?? '?' ) . ' — ' . $this->money( $dow_data[ $worst_dow_rev ]['revenue'] );
		$lines[] = '- **' . __( 'Peak hour (UTC) by revenue', 'brikpanel' ) . ':** ' . sprintf( '%02d:00', $best_hr_rev ) . ' — ' . $this->money( $hr_data[ $best_hr_rev ]['revenue'] ) . ' (' . number_format_i18n( $hr_data[ $best_hr_rev ]['orders'] ) . ' ' . __( 'orders', 'brikpanel' ) . ')';
		if ( $best_hr_orders !== $best_hr_rev ) {
			$lines[] = '- **' . __( 'Peak hour (UTC) by order count', 'brikpanel' ) . ':** ' . sprintf( '%02d:00', $best_hr_orders ) . ' — ' . number_format_i18n( $hr_data[ $best_hr_orders ]['orders'] ) . ' ' . __( 'orders', 'brikpanel' );
		}
		$lines[] = '- **' . __( 'Quietest hour (UTC) by revenue', 'brikpanel' ) . ':** ' . sprintf( '%02d:00', $worst_hr_rev ) . ' — ' . $this->money( $hr_data[ $worst_hr_rev ]['revenue'] );
		$lines[] = '';
		$lines[] = '### ' . __( 'Day of week breakdown', 'brikpanel' );
		$lines[] = '| ' . __( 'Day', 'brikpanel' ) . ' | ' . __( 'Orders', 'brikpanel' ) . ' | ' . __( 'Revenue', 'brikpanel' ) . ' |';
		$lines[] = '|---|---:|---:|';
		for ( $d = 2; $d <= 7; $d++ ) { // Mon..Sat
			$row = $dow_data[ $d ] ?? [ 'orders' => 0, 'revenue' => 0 ];
			$lines[] = '| ' . $dow_names[ $d ] . ' | ' . number_format_i18n( $row['orders'] ) . ' | ' . $this->money( $row['revenue'] ) . ' |';
		}
		$row = $dow_data[ 1 ] ?? [ 'orders' => 0, 'revenue' => 0 ];
		$lines[] = '| ' . $dow_names[ 1 ] . ' | ' . number_format_i18n( $row['orders'] ) . ' | ' . $this->money( $row['revenue'] ) . ' |';

		$fn = $this->footnote( 'wc_orders' );
		if ( $fn ) { $lines[] = ''; $lines[] = $fn; }

		return implode( "\n", $lines );
	}

	// =========================================================================
	// SECTION: FAILED ORDERS (count + payment method breakdown)
	// =========================================================================

	private function section_failed_orders() {
		global $wpdb;
		$start_dt = $this->months_ago_gmt( 12 );

		if ( $this->is_hpos() ) {
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT IFNULL(NULLIF(payment_method_title,''), payment_method) AS method,
				        COUNT(*) AS cnt,
				        COALESCE(SUM(total_amount),0) AS at_risk
				 FROM {$wpdb->prefix}wc_orders
				 WHERE type='shop_order' AND status='wc-failed' AND date_created_gmt >= %s
				 GROUP BY method
				 ORDER BY cnt DESC",
				$start_dt
			) ); // phpcs:ignore
		} else {
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT IFNULL(NULLIF(pm_title.meta_value,''), pm_method.meta_value) AS method,
				        COUNT(*) AS cnt,
				        COALESCE(SUM(CAST(pm_total.meta_value AS DECIMAL(20,4))),0) AS at_risk
				 FROM {$wpdb->posts} p
				 LEFT JOIN {$wpdb->postmeta} pm_method ON pm_method.post_id=p.ID AND pm_method.meta_key='_payment_method'
				 LEFT JOIN {$wpdb->postmeta} pm_title  ON pm_title.post_id=p.ID  AND pm_title.meta_key='_payment_method_title'
				 LEFT JOIN {$wpdb->postmeta} pm_total  ON pm_total.post_id=p.ID  AND pm_total.meta_key='_order_total'
				 WHERE p.post_type='shop_order' AND p.post_status='wc-failed'
				   AND p.post_date_gmt >= %s
				 GROUP BY method
				 ORDER BY cnt DESC",
				$start_dt
			) ); // phpcs:ignore
		}

		if ( empty( $rows ) ) {
			return '';
		}

		$total = 0; $at_risk_total = 0.0;
		foreach ( $rows as $r ) { $total += (int) $r->cnt; $at_risk_total += (float) $r->at_risk; }

		$lines = [];
		$lines[] = '## ' . __( 'Failed Orders (last 12 months)', 'brikpanel' );

		// When all failures are concentrated on a single payment method, a
		// table is overkill — render a one-liner that reads naturally.
		if ( count( $rows ) === 1 ) {
			$only = $rows[0];
			$lines[] = sprintf(
				/* translators: 1: failed count, 2: payment method, 3: revenue */
				__( '%1$s orders failed, all via **%2$s**. **%3$s** in revenue at risk.', 'brikpanel' ),
				number_format_i18n( $total ),
				$this->md_cell( $only->method ?: __( '(none)', 'brikpanel' ) ),
				$this->money( $at_risk_total )
			);
		} else {
			$lines[] = '- **' . __( 'Total failed', 'brikpanel' ) . ':** ' . number_format_i18n( $total ) . ' ' . __( 'orders', 'brikpanel' );
			$lines[] = '- **' . __( 'Revenue at risk', 'brikpanel' ) . ':** ' . $this->money( $at_risk_total );
			$lines[] = '- *' . __( 'WooCommerce does not store a structured failure reason; the table below groups failures by payment method (the only reliable signal). Look for one gateway dominating to spot integration issues.', 'brikpanel' ) . '*';
			$lines[] = '';
			$lines[] = '| ' . __( 'Payment method', 'brikpanel' ) . ' | ' . __( 'Failed', 'brikpanel' ) . ' | ' . __( 'Share', 'brikpanel' ) . ' | ' . __( 'Revenue at risk', 'brikpanel' ) . ' |';
			$lines[] = '|---|---:|---:|---:|';
			foreach ( $rows as $r ) {
				$lines[] = '| ' . $this->md_cell( $r->method ?: __( '(none)', 'brikpanel' ) ) . ' | ' . number_format_i18n( $r->cnt ) . ' | ' . $this->pct( $r->cnt, $total ) . ' | ' . $this->money( $r->at_risk ) . ' |';
			}
		}

		$fn = $this->footnote( 'wc_orders_all' );
		if ( $fn ) { $lines[] = ''; $lines[] = $fn; }

		return implode( "\n", $lines );
	}

	// =========================================================================
	// SECTION: REFUND METRICS (12m count, amount, monthly trend)
	// =========================================================================

	private function section_refund_metrics() {
		global $wpdb;
		$start_dt = $this->months_ago_gmt( 12 );

		if ( $this->is_hpos() ) {
			$summary = $wpdb->get_row( $wpdb->prepare(
				"SELECT COUNT(*) AS cnt, COALESCE(SUM(ABS(total_amount)),0) AS amt
				 FROM {$wpdb->prefix}wc_orders
				 WHERE type='shop_order_refund' AND date_created_gmt >= %s",
				$start_dt
			) ); // phpcs:ignore
			$monthly = $wpdb->get_results( $wpdb->prepare(
				"SELECT DATE_FORMAT(date_created_gmt, '%%Y-%%m') AS ym,
				        COUNT(*) AS cnt, COALESCE(SUM(ABS(total_amount)),0) AS amt
				 FROM {$wpdb->prefix}wc_orders
				 WHERE type='shop_order_refund' AND date_created_gmt >= %s
				 GROUP BY ym ORDER BY ym ASC",
				$start_dt
			) ); // phpcs:ignore
			$total_orders = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders
				 WHERE type='shop_order' AND status IN (" . brikpanel_paid_statuses_sql() . ") AND date_created_gmt >= %s",
				$start_dt
			) ); // phpcs:ignore
			$total_revenue = (float) $wpdb->get_var( $wpdb->prepare(
				"SELECT COALESCE(SUM(total_amount),0) FROM {$wpdb->prefix}wc_orders
				 WHERE type='shop_order' AND status IN (" . brikpanel_paid_statuses_sql() . ") AND date_created_gmt >= %s",
				$start_dt
			) ); // phpcs:ignore
		} else {
			$summary = $wpdb->get_row( $wpdb->prepare(
				"SELECT COUNT(*) AS cnt, COALESCE(SUM(ABS(CAST(pm.meta_value AS DECIMAL(20,4)))),0) AS amt
				 FROM {$wpdb->posts} p
				 LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id=p.ID AND pm.meta_key='_order_total'
				 WHERE p.post_type='shop_order_refund' AND p.post_date_gmt >= %s",
				$start_dt
			) ); // phpcs:ignore
			$monthly = $wpdb->get_results( $wpdb->prepare(
				"SELECT DATE_FORMAT(p.post_date_gmt, '%%Y-%%m') AS ym,
				        COUNT(*) AS cnt, COALESCE(SUM(ABS(CAST(pm.meta_value AS DECIMAL(20,4)))),0) AS amt
				 FROM {$wpdb->posts} p
				 LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id=p.ID AND pm.meta_key='_order_total'
				 WHERE p.post_type='shop_order_refund' AND p.post_date_gmt >= %s
				 GROUP BY ym ORDER BY ym ASC",
				$start_dt
			) ); // phpcs:ignore
			$total_orders = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts}
				 WHERE post_type='shop_order' AND post_status IN (" . brikpanel_paid_statuses_sql() . ") AND post_date_gmt >= %s",
				$start_dt
			) ); // phpcs:ignore
			$total_revenue = (float) $wpdb->get_var( $wpdb->prepare(
				"SELECT COALESCE(SUM(CAST(pm.meta_value AS DECIMAL(20,4))),0) FROM {$wpdb->posts} p
				 LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id=p.ID AND pm.meta_key='_order_total'
				 WHERE p.post_type='shop_order' AND p.post_status IN (" . brikpanel_paid_statuses_sql() . ") AND p.post_date_gmt >= %s",
				$start_dt
			) ); // phpcs:ignore
		}

		$cnt = (int) ( $summary->cnt ?? 0 );
		$amt = (float) ( $summary->amt ?? 0 );

		// If no refunds AND no orders, the section is uninformative.
		if ( $cnt === 0 && $total_orders === 0 ) {
			return '';
		}

		$lines = [];
		$lines[] = '## ' . __( 'Refund Metrics (last 12 months)', 'brikpanel' );
		$lines[] = '- **' . __( 'Refund count', 'brikpanel' ) . ':** ' . number_format_i18n( $cnt ) . ' (' . $this->pct( $cnt, $total_orders ) . ' ' . __( 'of paid orders', 'brikpanel' ) . ')';
		$lines[] = '- **' . __( 'Refunded amount', 'brikpanel' ) . ':** ' . $this->money( $amt ) . ' (' . $this->pct( $amt, $total_revenue ) . ' ' . __( 'of revenue', 'brikpanel' ) . ')';

		if ( ! empty( $monthly ) ) {
			$lines[] = '';
			$lines[] = '| ' . __( 'Month', 'brikpanel' ) . ' | ' . __( 'Refunds', 'brikpanel' ) . ' | ' . __( 'Amount', 'brikpanel' ) . ' |';
			$lines[] = '|---|---:|---:|';
			foreach ( $monthly as $r ) {
				$lines[] = '| ' . $r->ym . ' | ' . number_format_i18n( $r->cnt ) . ' | ' . $this->money( $r->amt ) . ' |';
			}
		}

		$this->register_tldr( 'refund_rate_12m', $total_orders > 0 ? $cnt / $total_orders : 0 );

		$fn = $this->footnote( 'wc_orders_all' );
		if ( $fn ) { $lines[] = ''; $lines[] = $fn; }

		return implode( "\n", $lines );
	}

	// =========================================================================
	// SECTION: CUSTOMER CONCENTRATION (Top N share of LTV)
	// =========================================================================

	private function section_customer_concentration() {
		$agg = $this->customer_aggregates();
		if ( ! $agg || $agg['total_ltv'] <= 0 ) {
			return '';
		}

		// Last-12-months window — investors usually care more about
		// concentration of *recent* revenue than all-time. We compute it
		// inline with one round-trip and no caching: the per-customer
		// 12m-revenue list is small (top 10 only).
		$conc_12m = $this->concentration_window( $this->months_ago_gmt( 12 ) );

		$lines = [];
		$lines[] = '## ' . __( 'Customer Concentration', 'brikpanel' );
		$lines[] = '*' . __( 'Share of revenue held by top customers — a high number means the business is fragile to losing a few accounts. We show two windows because all-time and last-12m can diverge sharply when a SaaS-style customer mix is rotating.', 'brikpanel' ) . '*';
		$lines[] = '';
		$lines[] = '| ' . __( 'Cohort', 'brikpanel' ) . ' | ' . __( 'All-time LTV share', 'brikpanel' ) . ' | ' . __( 'Last 12m revenue share', 'brikpanel' ) . ' |';
		$lines[] = '|---|---:|---:|';
		$lines[] = '| ' . __( 'Top customer', 'brikpanel' ) . ' | ' . $this->pct( $agg['top1'], $agg['total_ltv'] )  . ' | ' . ( $conc_12m['total'] > 0 ? $this->pct( $conc_12m['top1'],  $conc_12m['total'] ) : '—' ) . ' |';
		$lines[] = '| ' . __( 'Top 3', 'brikpanel' )         . ' | ' . $this->pct( $agg['top3'], $agg['total_ltv'] )  . ' | ' . ( $conc_12m['total'] > 0 ? $this->pct( $conc_12m['top3'],  $conc_12m['total'] ) : '—' ) . ' |';
		$lines[] = '| ' . __( 'Top 5', 'brikpanel' )         . ' | ' . $this->pct( $agg['top5'], $agg['total_ltv'] )  . ' | ' . ( $conc_12m['total'] > 0 ? $this->pct( $conc_12m['top5'],  $conc_12m['total'] ) : '—' ) . ' |';
		$lines[] = '| ' . __( 'Top 10', 'brikpanel' )        . ' | ' . $this->pct( $agg['top10'], $agg['total_ltv'] ) . ' | ' . ( $conc_12m['total'] > 0 ? $this->pct( $conc_12m['top10'], $conc_12m['total'] ) : '—' ) . ' |';
		$lines[] = '| ' . __( 'Total revenue', 'brikpanel' ) . ' | ' . $this->money( $agg['total_ltv'] ) . ' | ' . $this->money( $conc_12m['total'] ) . ' |';

		$this->register_tldr( 'top1_share', $agg['total_ltv'] > 0 ? $agg['top1']  / $agg['total_ltv'] : 0 );
		$this->register_tldr( 'top10_share', $agg['total_ltv'] > 0 ? $agg['top10'] / $agg['total_ltv'] : 0 );
		$this->register_tldr( 'top1_share_12m', $conc_12m['total'] > 0 ? $conc_12m['top1'] / $conc_12m['total'] : 0 );
		$this->register_tldr( 'top10_share_12m', $conc_12m['total'] > 0 ? $conc_12m['top10'] / $conc_12m['total'] : 0 );
		$this->register_tldr( 'total_customers', $agg['total'] );

		$fn = $this->footnote( 'bp_metrics' );
		if ( $fn ) { $lines[] = ''; $lines[] = $fn; }

		return implode( "\n", $lines );
	}

	/**
	 * Helper for section_customer_concentration: compute the top-10
	 * customer revenue + total revenue in a window (defaults to last 12
	 * months). One query.
	 *
	 * @return array{total: float, top1: float, top3: float, top5: float, top10: float}
	 */
	private function concentration_window( $start_gmt ) {
		global $wpdb;
		if ( $this->is_hpos() ) {
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT IFNULL(NULLIF(billing_email,''), CAST(customer_id AS CHAR)) AS ck,
				        SUM(total_amount) AS rev
				 FROM {$wpdb->prefix}wc_orders
				 WHERE type='shop_order' AND status IN (" . brikpanel_paid_statuses_sql() . ")
				   AND date_created_gmt >= %s
				 GROUP BY ck
				 ORDER BY rev DESC",
				$start_gmt
			) ); // phpcs:ignore
		} else {
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT IFNULL(NULLIF(pm_e.meta_value,''), pm_u.meta_value) AS ck,
				        SUM(CAST(pm_t.meta_value AS DECIMAL(20,4))) AS rev
				 FROM {$wpdb->posts} p
				 LEFT JOIN {$wpdb->postmeta} pm_e ON pm_e.post_id=p.ID AND pm_e.meta_key='_billing_email'
				 LEFT JOIN {$wpdb->postmeta} pm_u ON pm_u.post_id=p.ID AND pm_u.meta_key='_customer_user'
				 LEFT JOIN {$wpdb->postmeta} pm_t ON pm_t.post_id=p.ID AND pm_t.meta_key='_order_total'
				 WHERE p.post_type='shop_order' AND p.post_status IN (" . brikpanel_paid_statuses_sql() . ")
				   AND p.post_date_gmt >= %s
				 GROUP BY ck
				 ORDER BY rev DESC",
				$start_gmt
			) ); // phpcs:ignore
		}
		$total = 0.0; $top1 = 0.0; $top3 = 0.0; $top5 = 0.0; $top10 = 0.0;
		foreach ( $rows as $i => $r ) {
			$rev = (float) $r->rev;
			$total += $rev;
			if ( $i === 0 )  { $top1  += $rev; }
			if ( $i <  3 )   { $top3  += $rev; }
			if ( $i <  5 )   { $top5  += $rev; }
			if ( $i < 10 )   { $top10 += $rev; }
		}
		return [ 'total' => $total, 'top1' => $top1, 'top3' => $top3, 'top5' => $top5, 'top10' => $top10 ];
	}

	// =========================================================================
	// SECTION: REPEAT PURCHASE RATE
	// =========================================================================

	private function section_repeat_purchase_rate() {
		$agg = $this->customer_aggregates();
		if ( ! $agg ) { return ''; }

		$rate = $agg['total'] > 0 ? $agg['repeat_count'] / $agg['total'] : 0;

		$lines = [];
		$lines[] = '## ' . __( 'Repeat Purchase Rate', 'brikpanel' );
		$lines[] = '- **' . __( 'Repeat customers', 'brikpanel' ) . ':** ' . number_format_i18n( $agg['repeat_count'] ) . ' / ' . number_format_i18n( $agg['total'] ) . ' (' . number_format_i18n( $rate * 100, 1 ) . '%)';
		$lines[] = '- *' . __( 'Customers who placed at least 2 orders. A higher rate means lower acquisition pressure on growth.', 'brikpanel' ) . '*';

		$this->register_tldr( 'repeat_rate', $rate );

		$fn = $this->footnote( 'bp_metrics' );
		if ( $fn ) { $lines[] = ''; $lines[] = $fn; }

		return implode( "\n", $lines );
	}

	// =========================================================================
	// SECTION: TIME TO FIRST PURCHASE (registered users only)
	// =========================================================================

	private function section_time_to_first_purchase() {
		$agg = $this->customer_aggregates();
		if ( ! $agg || $agg['ttf_avg_days'] === null || $agg['ttf_sample'] === 0 ) {
			return '';
		}

		$days = (float) $agg['ttf_avg_days'];

		$lines = [];
		$lines[] = '## ' . __( 'Time to First Purchase', 'brikpanel' );
		$lines[] = '- **' . __( 'Average time from registration to first order', 'brikpanel' ) . ':** ' . number_format_i18n( $days, 1 ) . ' ' . __( 'days', 'brikpanel' ) . ' (' . __( 'sample', 'brikpanel' ) . ': ' . number_format_i18n( $agg['ttf_sample'] ) . ' ' . __( 'registered customers', 'brikpanel' ) . ')';
		$lines[] = '- *' . __( 'Limited to customers with a WordPress account. Most stores have many guest checkouts (user_id = 0) which are excluded — this metric reflects the registered-account funnel only.', 'brikpanel' ) . '*';

		$fn = $this->footnote( 'bp_metrics' );
		if ( $fn ) { $lines[] = ''; $lines[] = $fn; }

		return implode( "\n", $lines );
	}

	// =========================================================================
	// SECTION: CART ABANDONMENT RATE (tracking-window scoped)
	// =========================================================================

	private function section_cart_abandonment() {
		// Needs both BrikPanel checkout tracking AND order data; clamp window.
		$track = $this->tracking_start_date();
		if ( $track === null ) { return ''; }
		$end_date = gmdate( 'Y-m-d' );

		// Compute against the larger of "last 12 months" and "since tracking start".
		$ideal_start = gmdate( 'Y-m-d', strtotime( '-12 months', current_time( 'timestamp', true ) ) );
		$start_date  = $ideal_start > $track ? $ideal_start : $track;

		$checkout = function_exists( 'brikpanel_get_checkout_count' )
			? (int) brikpanel_get_checkout_count( $start_date, $end_date )
			: 0;
		$success  = brikpanel_get_successful_order_count( $start_date . ' 00:00:00', $this->now_gmt() );

		if ( $checkout === 0 ) {
			return '';
		}

		// "Successful orders" can technically exceed checkout_count when the
		// user reaches /checkout via direct link without first appearing on
		// any other tracked page; clamp the abandonment rate at zero.
		$abandoned = max( 0, $checkout - $success );
		$rate      = $checkout > 0 ? $abandoned / $checkout : 0;

		$lines = [];
		$lines[] = '## ' . __( 'Cart Abandonment Rate', 'brikpanel' );
		$lines[] = '- **' . __( 'Checkout reached', 'brikpanel' ) . ':** ' . number_format_i18n( $checkout );
		$lines[] = '- **' . __( 'Successful orders', 'brikpanel' ) . ':** ' . number_format_i18n( $success );
		$lines[] = '- **' . __( 'Abandonment rate', 'brikpanel' ) . ':** ' . number_format_i18n( $rate * 100, 1 ) . '%';
		$lines[] = '- *' . sprintf( __( 'Window: %s → %s. Abandonment = (checkout reached − successful orders) / checkout reached.', 'brikpanel' ), $start_date, $end_date ) . '*';

		$fn = $this->footnote( 'bp_visitors' );
		if ( $fn ) { $lines[] = ''; $lines[] = $fn; }

		return implode( "\n", $lines );
	}

	// =========================================================================
	// SECTION: COUPON PERFORMANCE (with vs without, total discount)
	// =========================================================================

	private function section_coupon_performance() {
		global $wpdb;
		$start_dt = $this->months_ago_gmt( 12 );

		// Per-coupon usage + total discount aggregated from order line items.
		// Uses LEFT JOIN to allow coupons with the meta missing (treats as 0).
		if ( $this->is_hpos() ) {
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT oi.order_item_name AS code,
				        COUNT(DISTINCT oi.order_id) AS uses,
				        COALESCE(SUM(CAST(im.meta_value AS DECIMAL(20,4))),0) AS total_discount
				 FROM {$wpdb->prefix}woocommerce_order_items oi
				 INNER JOIN {$wpdb->prefix}wc_orders o ON o.id=oi.order_id
				 LEFT JOIN  {$wpdb->prefix}woocommerce_order_itemmeta im ON im.order_item_id=oi.order_item_id AND im.meta_key='discount_amount'
				 WHERE oi.order_item_type='coupon'
				   AND o.type='shop_order' AND o.status IN (" . brikpanel_paid_statuses_sql() . ")
				   AND o.date_created_gmt >= %s
				 GROUP BY code
				 ORDER BY uses DESC LIMIT 10",
				$start_dt
			) ); // phpcs:ignore
			$with_coupon = $wpdb->get_row( $wpdb->prepare(
				"SELECT COUNT(DISTINCT o.id) AS cnt, COALESCE(SUM(o.total_amount),0) AS rev
				 FROM {$wpdb->prefix}wc_orders o
				 INNER JOIN {$wpdb->prefix}woocommerce_order_items oi ON oi.order_id=o.id AND oi.order_item_type='coupon'
				 WHERE o.type='shop_order' AND o.status IN (" . brikpanel_paid_statuses_sql() . ")
				   AND o.date_created_gmt >= %s",
				$start_dt
			) ); // phpcs:ignore
			$all = $wpdb->get_row( $wpdb->prepare(
				"SELECT COUNT(*) AS cnt, COALESCE(SUM(total_amount),0) AS rev
				 FROM {$wpdb->prefix}wc_orders
				 WHERE type='shop_order' AND status IN (" . brikpanel_paid_statuses_sql() . ")
				   AND date_created_gmt >= %s",
				$start_dt
			) ); // phpcs:ignore
		} else {
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT oi.order_item_name AS code,
				        COUNT(DISTINCT oi.order_id) AS uses,
				        COALESCE(SUM(CAST(im.meta_value AS DECIMAL(20,4))),0) AS total_discount
				 FROM {$wpdb->prefix}woocommerce_order_items oi
				 INNER JOIN {$wpdb->posts} p ON p.ID=oi.order_id
				 LEFT JOIN  {$wpdb->prefix}woocommerce_order_itemmeta im ON im.order_item_id=oi.order_item_id AND im.meta_key='discount_amount'
				 WHERE oi.order_item_type='coupon'
				   AND p.post_type='shop_order' AND p.post_status IN (" . brikpanel_paid_statuses_sql() . ")
				   AND p.post_date_gmt >= %s
				 GROUP BY code
				 ORDER BY uses DESC LIMIT 10",
				$start_dt
			) ); // phpcs:ignore
			$with_coupon = $wpdb->get_row( $wpdb->prepare(
				"SELECT COUNT(DISTINCT p.ID) AS cnt, COALESCE(SUM(CAST(pm.meta_value AS DECIMAL(20,4))),0) AS rev
				 FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->prefix}woocommerce_order_items oi ON oi.order_id=p.ID AND oi.order_item_type='coupon'
				 LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id=p.ID AND pm.meta_key='_order_total'
				 WHERE p.post_type='shop_order' AND p.post_status IN (" . brikpanel_paid_statuses_sql() . ")
				   AND p.post_date_gmt >= %s",
				$start_dt
			) ); // phpcs:ignore
			$all = $wpdb->get_row( $wpdb->prepare(
				"SELECT COUNT(*) AS cnt, COALESCE(SUM(CAST(pm.meta_value AS DECIMAL(20,4))),0) AS rev
				 FROM {$wpdb->posts} p
				 LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id=p.ID AND pm.meta_key='_order_total'
				 WHERE p.post_type='shop_order' AND p.post_status IN (" . brikpanel_paid_statuses_sql() . ")
				   AND p.post_date_gmt >= %s",
				$start_dt
			) ); // phpcs:ignore
		}

		$with_cnt = (int) ( $with_coupon->cnt ?? 0 );
		if ( $with_cnt === 0 ) {
			return ''; // No coupon usage in window — section uninformative.
		}

		$with_rev = (float) ( $with_coupon->rev ?? 0 );
		$all_cnt  = (int) ( $all->cnt ?? 0 );
		$all_rev  = (float) ( $all->rev ?? 0 );
		$without_cnt = max( 0, $all_cnt - $with_cnt );
		$without_rev = max( 0, $all_rev - $with_rev );
		$with_aov    = $with_cnt > 0    ? $with_rev / $with_cnt    : 0;
		$without_aov = $without_cnt > 0 ? $without_rev / $without_cnt : 0;

		// Discount aggressiveness: how big a chunk of the would-be-charged
		// AOV does the average coupon shave off?
		$total_discount_12m = 0.0;
		foreach ( $rows as $r ) { $total_discount_12m += (float) $r->total_discount; }
		$avg_discount_per_use = $with_cnt > 0 ? $total_discount_12m / $with_cnt : 0;
		// Pre-discount AOV ≈ AOV after coupon + average discount per coupon.
		$pre_discount_aov = $with_aov + $avg_discount_per_use;
		$discount_pct = $pre_discount_aov > 0 ? $avg_discount_per_use / $pre_discount_aov : 0;

		$lines = [];
		$lines[] = '## ' . __( 'Coupon Performance (last 12 months)', 'brikpanel' );
		$lines[] = '- **' . __( 'Orders with a coupon', 'brikpanel' ) . ':** ' . number_format_i18n( $with_cnt ) . ' (' . $this->pct( $with_cnt, $all_cnt ) . ')';
		$lines[] = '- **' . __( 'AOV with coupon', 'brikpanel' ) . ':** ' . $this->money( $with_aov ) . ' | **' . __( 'AOV without coupon', 'brikpanel' ) . ':** ' . $this->money( $without_aov );
		if ( $avg_discount_per_use > 0 ) {
			$lines[] = '- **' . __( 'Avg discount per coupon use', 'brikpanel' ) . ':** ' . $this->money( $avg_discount_per_use )
				. ' (' . number_format_i18n( $discount_pct * 100, 1 ) . '% '
				. __( 'off the pre-discount AOV', 'brikpanel' ) . ')';
		}

		if ( ! empty( $rows ) ) {
			$lines[] = '';
			$lines[] = '### ' . __( 'Top Coupons by Usage', 'brikpanel' );
			$lines[] = '| ' . __( 'Code', 'brikpanel' ) . ' | ' . __( 'Uses', 'brikpanel' ) . ' | ' . __( 'Total discount', 'brikpanel' ) . ' | ' . __( 'Avg discount', 'brikpanel' ) . ' | ' . __( 'Discount %', 'brikpanel' ) . ' |';
			$lines[] = '|---|---:|---:|---:|---:|';
			foreach ( $rows as $r ) {
				$avg = $r->uses > 0 ? (float) $r->total_discount / (int) $r->uses : 0;
				// Per-coupon discount % uses the same denominator as the
				// global figure so the column adds up cleanly.
				$pct_off = $pre_discount_aov > 0 ? $avg / $pre_discount_aov : 0;
				$lines[] = '| ' . $this->md_cell( $r->code ) . ' | ' . number_format_i18n( $r->uses ) . ' | ' . $this->money( $r->total_discount ) . ' | ' . $this->money( $avg ) . ' | ' . number_format_i18n( $pct_off * 100, 1 ) . '% |';
			}
		}

		$fn = $this->footnote( 'wc_coupons' );
		if ( $fn ) { $lines[] = ''; $lines[] = $fn; }

		return implode( "\n", $lines );
	}

	// =========================================================================
	// SECTION: GEOGRAPHY (top cities + countries, last 12 months)
	// =========================================================================

	private function section_geography() {
		global $wpdb;
		$start_dt = $this->months_ago_gmt( 12 );

		// Many digital-only stores (e.g. Brksoft selling annual marketplace
		// premium licences) have no shipping addresses at all — the only
		// reliable geo signal is the billing address. We coalesce: prefer
		// shipping when present, fall back to billing per row.
		if ( $this->is_hpos() ) {
			$cities = $wpdb->get_results( $wpdb->prepare(
				"SELECT
					COALESCE(NULLIF(s.country,''), b.country) AS country,
					COALESCE(NULLIF(s.state,''),   b.state)   AS state,
					COALESCE(NULLIF(s.city,''),    b.city)    AS city,
					COUNT(*) AS orders,
					COALESCE(SUM(o.total_amount),0) AS revenue
				 FROM {$wpdb->prefix}wc_orders o
				 LEFT JOIN {$wpdb->prefix}wc_order_addresses s ON s.order_id=o.id AND s.address_type='shipping'
				 LEFT JOIN {$wpdb->prefix}wc_order_addresses b ON b.order_id=o.id AND b.address_type='billing'
				 WHERE o.type='shop_order' AND o.status IN (" . brikpanel_paid_statuses_sql() . ")
				   AND o.date_created_gmt >= %s
				   AND COALESCE(NULLIF(s.city,''), b.city) IS NOT NULL
				   AND COALESCE(NULLIF(s.city,''), b.city) <> ''
				 GROUP BY country, state, city
				 ORDER BY orders DESC LIMIT 10",
				$start_dt
			) ); // phpcs:ignore
		} else {
			$cities = $wpdb->get_results( $wpdb->prepare(
				"SELECT
					COALESCE(NULLIF(pm_sc.meta_value,''), pm_bc.meta_value) AS country,
					COALESCE(NULLIF(pm_ss.meta_value,''), pm_bs.meta_value) AS state,
					COALESCE(NULLIF(pm_sci.meta_value,''), pm_bci.meta_value) AS city,
					COUNT(*) AS orders,
					COALESCE(SUM(CAST(pm_total.meta_value AS DECIMAL(20,4))),0) AS revenue
				 FROM {$wpdb->posts} p
				 LEFT JOIN {$wpdb->postmeta} pm_sci ON pm_sci.post_id=p.ID AND pm_sci.meta_key='_shipping_city'
				 LEFT JOIN {$wpdb->postmeta} pm_sc  ON pm_sc.post_id=p.ID  AND pm_sc.meta_key='_shipping_country'
				 LEFT JOIN {$wpdb->postmeta} pm_ss  ON pm_ss.post_id=p.ID  AND pm_ss.meta_key='_shipping_state'
				 LEFT JOIN {$wpdb->postmeta} pm_bci ON pm_bci.post_id=p.ID AND pm_bci.meta_key='_billing_city'
				 LEFT JOIN {$wpdb->postmeta} pm_bc  ON pm_bc.post_id=p.ID  AND pm_bc.meta_key='_billing_country'
				 LEFT JOIN {$wpdb->postmeta} pm_bs  ON pm_bs.post_id=p.ID  AND pm_bs.meta_key='_billing_state'
				 LEFT JOIN {$wpdb->postmeta} pm_total ON pm_total.post_id=p.ID AND pm_total.meta_key='_order_total'
				 WHERE p.post_type='shop_order' AND p.post_status IN (" . brikpanel_paid_statuses_sql() . ")
				   AND p.post_date_gmt >= %s
				   AND COALESCE(NULLIF(pm_sci.meta_value,''), pm_bci.meta_value) IS NOT NULL
				   AND COALESCE(NULLIF(pm_sci.meta_value,''), pm_bci.meta_value) <> ''
				 GROUP BY country, state, city
				 ORDER BY orders DESC LIMIT 10",
				$start_dt
			) ); // phpcs:ignore
		}

		if ( empty( $cities ) ) {
			return '';
		}

		$lines = [];
		$lines[] = '## ' . __( 'Geographic Split (last 12 months)', 'brikpanel' );
		$lines[] = '### ' . __( 'Top Cities', 'brikpanel' );
		$lines[] = '| ' . __( 'City', 'brikpanel' ) . ' | ' . __( 'State', 'brikpanel' ) . ' | ' . __( 'Country', 'brikpanel' ) . ' | ' . __( 'Orders', 'brikpanel' ) . ' | ' . __( 'Revenue', 'brikpanel' ) . ' | ' . __( 'AOV', 'brikpanel' ) . ' |';
		$lines[] = '|---|---|---|---:|---:|---:|';
		foreach ( $cities as $r ) {
			$aov = $r->orders > 0 ? (float) $r->revenue / (int) $r->orders : 0;
			$lines[] = '| ' . $this->md_cell( $r->city ?: '—' ) . ' | ' . $this->md_cell( $r->state ?: '—' ) . ' | ' . $this->md_cell( $r->country ?: '—' ) . ' | ' . number_format_i18n( $r->orders ) . ' | ' . $this->money( $r->revenue ) . ' | ' . $this->money( $aov ) . ' |';
		}

		$fn = $this->footnote( 'wc_addresses' );
		if ( $fn ) { $lines[] = ''; $lines[] = $fn; }

		return implode( "\n", $lines );
	}

	// =========================================================================
	// SECTION: ORDER ATTRIBUTION (WC 8.5+ source/medium + created_via)
	// =========================================================================

	private function section_order_attribution() {
		global $wpdb;
		$start_dt = $this->months_ago_gmt( 12 );

		$has_attribution = $this->is_order_attribution_active();

		// `created_via` lives on wc_order_operational_data and is populated for
		// every store; report it even when attribution is off.
		if ( $this->is_hpos() ) {
			$cv_rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT od.created_via AS source, COUNT(*) AS orders, COALESCE(SUM(o.total_amount),0) AS revenue
				 FROM {$wpdb->prefix}wc_orders o
				 INNER JOIN {$wpdb->prefix}wc_order_operational_data od ON od.order_id=o.id
				 WHERE o.type='shop_order' AND o.status IN (" . brikpanel_paid_statuses_sql() . ")
				   AND o.date_created_gmt >= %s
				 GROUP BY od.created_via
				 ORDER BY orders DESC",
				$start_dt
			) ); // phpcs:ignore
		} else {
			$cv_rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT pm.meta_value AS source, COUNT(*) AS orders,
				        COALESCE(SUM(CAST(pm_t.meta_value AS DECIMAL(20,4))),0) AS revenue
				 FROM {$wpdb->posts} p
				 LEFT JOIN {$wpdb->postmeta} pm   ON pm.post_id=p.ID   AND pm.meta_key='_created_via'
				 LEFT JOIN {$wpdb->postmeta} pm_t ON pm_t.post_id=p.ID AND pm_t.meta_key='_order_total'
				 WHERE p.post_type='shop_order' AND p.post_status IN (" . brikpanel_paid_statuses_sql() . ")
				   AND p.post_date_gmt >= %s
				 GROUP BY pm.meta_value
				 ORDER BY orders DESC",
				$start_dt
			) ); // phpcs:ignore
		}

		if ( empty( $cv_rows ) && ! $has_attribution ) {
			return '';
		}

		// Build a flat one-line summary of created_via shares so the table
		// below can stay focused on actual marketing attribution.
		$cv_summary = [];
		$cv_total = 0;
		foreach ( $cv_rows as $r ) { $cv_total += (int) $r->orders; }
		foreach ( $cv_rows as $r ) {
			$cv_summary[] = ( $r->source ?: __( '(unknown)', 'brikpanel' ) )
				. ' ' . number_format_i18n( $r->orders )
				. ' (' . $this->pct( $r->orders, $cv_total ) . ')';
		}

		$lines = [];
		$lines[] = '## ' . __( 'Channel Mix (last 12 months)', 'brikpanel' );
		if ( $cv_summary ) {
			$lines[] = '- **' . __( 'Created via', 'brikpanel' ) . ':** ' . implode( ' · ', array_slice( $cv_summary, 0, 6 ) );
		}

		if ( $has_attribution ) {
			if ( $this->is_hpos() ) {
				$attr = $wpdb->get_results( $wpdb->prepare(
					"SELECT
						(SELECT meta_value FROM {$wpdb->prefix}wc_orders_meta WHERE order_id=o.id AND meta_key='_wc_order_attribution_source_type' LIMIT 1) AS source_type,
						(SELECT meta_value FROM {$wpdb->prefix}wc_orders_meta WHERE order_id=o.id AND meta_key='_wc_order_attribution_utm_source'  LIMIT 1) AS utm_source,
						(SELECT meta_value FROM {$wpdb->prefix}wc_orders_meta WHERE order_id=o.id AND meta_key='_wc_order_attribution_utm_medium'  LIMIT 1) AS utm_medium,
						o.total_amount AS revenue
					 FROM {$wpdb->prefix}wc_orders o
					 WHERE o.type='shop_order' AND o.status IN (" . brikpanel_paid_statuses_sql() . ")
					   AND o.date_created_gmt >= %s",
					$start_dt
				) ); // phpcs:ignore
			} else {
				$attr = $wpdb->get_results( $wpdb->prepare(
					"SELECT
						(SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id=p.ID AND meta_key='_wc_order_attribution_source_type' LIMIT 1) AS source_type,
						(SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id=p.ID AND meta_key='_wc_order_attribution_utm_source'  LIMIT 1) AS utm_source,
						(SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id=p.ID AND meta_key='_wc_order_attribution_utm_medium'  LIMIT 1) AS utm_medium,
						CAST(IFNULL((SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id=p.ID AND meta_key='_order_total' LIMIT 1),'0') AS DECIMAL(20,4)) AS revenue
					 FROM {$wpdb->posts} p
					 WHERE p.post_type='shop_order' AND p.post_status IN (" . brikpanel_paid_statuses_sql() . ")
					   AND p.post_date_gmt >= %s",
					$start_dt
				) ); // phpcs:ignore
			}

			// Collapse 3 dimensions into a single hierarchical roll-up:
			// source_type → utm_source → utm_medium combination keys, then
			// take top 10 by orders. This single table answers "where did
			// the money come from" without forcing the reader to flip
			// between three separate tables.
			$by_combo = [];
			$rows_with_data = 0;
			foreach ( $attr as $r ) {
				if ( $r->source_type || $r->utm_source ) { $rows_with_data++; }
				$key = ( $r->source_type ?: '(unknown)' )
					. '||' . ( $r->utm_source ?: '(direct)' )
					. '||' . ( $r->utm_medium ?: '—' );
				$by_combo[ $key ] = ( $by_combo[ $key ] ?? [ 'orders' => 0, 'revenue' => 0 ] );
				$by_combo[ $key ]['orders']++;
				$by_combo[ $key ]['revenue'] += (float) $r->revenue;
			}
			if ( $rows_with_data > 0 ) {
				uasort( $by_combo, function ( $a, $b ) {
					if ( $a['revenue'] == $b['revenue'] ) { return $b['orders'] - $a['orders']; }
					return $b['revenue'] > $a['revenue'] ? 1 : -1;
				} );

				$lines[] = '';
				$lines[] = '| ' . __( 'Source type', 'brikpanel' ) . ' | ' . __( 'utm_source', 'brikpanel' ) . ' | ' . __( 'utm_medium', 'brikpanel' ) . ' | ' . __( 'Orders', 'brikpanel' ) . ' | ' . __( 'Revenue', 'brikpanel' ) . ' |';
				$lines[] = '|---|---|---|---:|---:|';
				foreach ( array_slice( $by_combo, 0, 10, true ) as $key => $v ) {
					list( $st, $utm, $med ) = explode( '||', $key );
					$lines[] = '| ' . $this->md_cell( $st ) . ' | ' . $this->md_cell( $utm ) . ' | ' . $this->md_cell( $med ) . ' | ' . number_format_i18n( $v['orders'] ) . ' | ' . $this->money( $v['revenue'] ) . ' |';
				}
			}
		}

		$fn = $this->footnote( $has_attribution ? 'wc_attribution' : 'wc_op_data' );
		if ( $fn ) { $lines[] = ''; $lines[] = $fn; }

		return implode( "\n", $lines );
	}

	// =========================================================================
	// SECTION: SUBSCRIPTIONS (only when WC Subscriptions is active)
	// =========================================================================

	private function section_subscriptions() {
		$mode = $this->subscription_mode();
		if ( ! $mode['enabled'] ) {
			return '';
		}

		if ( $mode['source'] === 'wc_subscriptions' ) {
			return $this->section_subscriptions_native();
		}
		return $this->section_subscriptions_inferred();
	}

	/**
	 * Native WC Subscriptions path: pulls the actual `shop_subscription`
	 * post type. Most accurate when the merchant uses the official plugin.
	 */
	private function section_subscriptions_native() {
		global $wpdb;

		$rows = $wpdb->get_results(
			"SELECT p.ID,
					CAST(IFNULL(pm_total.meta_value,'0') AS DECIMAL(20,4)) AS total,
					IFNULL(pm_period.meta_value,'') AS billing_period,
					CAST(IFNULL(pm_interval.meta_value,'1') AS UNSIGNED) AS billing_interval
			 FROM {$wpdb->posts} p
			 LEFT JOIN {$wpdb->postmeta} pm_total    ON pm_total.post_id=p.ID    AND pm_total.meta_key='_order_total'
			 LEFT JOIN {$wpdb->postmeta} pm_period   ON pm_period.post_id=p.ID   AND pm_period.meta_key='_billing_period'
			 LEFT JOIN {$wpdb->postmeta} pm_interval ON pm_interval.post_id=p.ID AND pm_interval.meta_key='_billing_interval'
			 WHERE p.post_type='shop_subscription' AND p.post_status='wc-active'"
		); // phpcs:ignore

		$active_count = count( $rows );
		$mrr = 0.0;
		foreach ( $rows as $r ) {
			$amt = (float) $r->total;
			$int = max( 1, (int) $r->billing_interval );
			$amt_per_period = $amt / $int;
			switch ( $r->billing_period ) {
				case 'day':   $mrr += $amt_per_period * 30.4; break;
				case 'week':  $mrr += $amt_per_period * 4.33; break;
				case 'month': $mrr += $amt_per_period;        break;
				case 'year':  $mrr += $amt_per_period / 12;   break;
			}
		}
		$arr = $mrr * 12;

		$cancelled_12m = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts}
			 WHERE post_type='shop_subscription' AND post_status='wc-cancelled'
			   AND post_modified_gmt >= DATE_SUB(NOW(), INTERVAL 12 MONTH)"
		); // phpcs:ignore

		$logo_churn = ( $active_count + $cancelled_12m ) > 0 ? $cancelled_12m / ( $active_count + $cancelled_12m ) : 0;

		$lines = [];
		$lines[] = '## ' . __( 'Subscriptions (WooCommerce Subscriptions)', 'brikpanel' );
		$lines[] = '- **' . __( 'Active subscriptions', 'brikpanel' ) . ':** ' . number_format_i18n( $active_count );
		$lines[] = '- **MRR:** ' . $this->money( $mrr ) . ' | **ARR:** ' . $this->money( $arr );
		$lines[] = '- **' . __( 'Cancellations (last 12m)', 'brikpanel' ) . ':** ' . number_format_i18n( $cancelled_12m );
		$lines[] = '- **' . __( 'Logo churn (12m)', 'brikpanel' ) . ':** ' . number_format_i18n( $logo_churn * 100, 1 ) . '%';
		$lines[] = '- *' . __( 'MRR normalized: yearly ÷ 12, weekly × 4.33, daily × 30.4. Revenue churn / NRR require expansion-revenue tracking BrikPanel does not capture yet.', 'brikpanel' ) . '*';

		$this->register_tldr( 'arr', $arr );
		$this->register_tldr( 'mrr', $mrr );
		$this->register_tldr( 'subs_active', $active_count );
		$this->register_tldr( 'subs_logo_churn', $logo_churn );

		$fn = $this->footnote( 'wc_subscriptions' );
		if ( $fn ) { $lines[] = ''; $lines[] = $fn; }

		return implode( "\n", $lines );
	}

	/**
	 * Inferred path: WC Subscriptions is NOT installed but the catalog uses
	 * period markers in product names (the Brksoft / "annual marketplace
	 * licence" pattern). We detect period from the product title and treat
	 * each line item as one billing cycle to estimate ARR/MRR/renewal.
	 */
	private function section_subscriptions_inferred() {
		global $wpdb;

		// Build a SQL CASE that classifies a product title into a period.
		// Order matters: yearly first to avoid 'Aylık abonelik yıllık paket'
		// being matched as monthly. Day/week added for completeness.
		$period_case = "CASE
			WHEN p.post_title LIKE '%Yıllık%' COLLATE utf8mb4_general_ci OR p.post_title LIKE '%Yıllik%' COLLATE utf8mb4_general_ci
			  OR p.post_title LIKE '%Yearly%' COLLATE utf8mb4_general_ci OR p.post_title LIKE '%Annual%' COLLATE utf8mb4_general_ci
			  OR p.post_title LIKE '%/yıl%'   COLLATE utf8mb4_general_ci OR p.post_title LIKE '%/year%'  COLLATE utf8mb4_general_ci
			  THEN 'year'
			WHEN p.post_title LIKE '%Aylık%'  COLLATE utf8mb4_general_ci OR p.post_title LIKE '%Aylik%'  COLLATE utf8mb4_general_ci
			  OR p.post_title LIKE '%Monthly%' COLLATE utf8mb4_general_ci
			  OR p.post_title LIKE '%/ay%'    COLLATE utf8mb4_general_ci OR p.post_title LIKE '%/month%' COLLATE utf8mb4_general_ci
			  THEN 'month'
			WHEN p.post_title LIKE '%Haftalık%' COLLATE utf8mb4_general_ci OR p.post_title LIKE '%Weekly%' COLLATE utf8mb4_general_ci THEN 'week'
			WHEN p.post_title LIKE '%Günlük%'   COLLATE utf8mb4_general_ci OR p.post_title LIKE '%Daily%'  COLLATE utf8mb4_general_ci THEN 'day'
			ELSE NULL
		END";

		// Window: orders in the last 12 months. We treat every paid line
		// item on a "subscription" product as one billing cycle. The
		// active-subscription proxy is "did this customer buy this period
		// of product within its last cycle from now?". For yearly: bought
		// within last 365 days. For monthly: last 35 days (small slack).
		$now = $this->now_gmt();
		$last_12m = $this->months_ago_gmt( 12 );
		$last_24m = $this->months_ago_gmt( 24 );
		$last_year = $this->days_ago_gmt( 365 );
		$last_month = $this->days_ago_gmt( 35 );

		if ( ! $this->is_hpos() ) {
			return ''; // Inferred path requires HPOS for clean joins; legacy stores fall back to no-op.
		}

		// Per-line subscription rows: one per (order, product) line item with a
		// detected period. We sum line totals to get ARR.
		$lines_sql = "
			SELECT o.id AS order_id,
			       o.date_created_gmt AS placed,
			       IFNULL(NULLIF(o.billing_email,''), CAST(o.customer_id AS CHAR)) AS customer_key,
			       oi.order_item_id,
			       p.ID AS product_id,
			       p.post_title AS product_title,
			       {$period_case} AS period,
			       CAST(IFNULL(im_total.meta_value,'0') AS DECIMAL(20,4)) AS line_total
			FROM {$wpdb->prefix}wc_orders o
			INNER JOIN {$wpdb->prefix}woocommerce_order_items oi ON oi.order_id=o.id AND oi.order_item_type='line_item'
			LEFT JOIN  {$wpdb->prefix}woocommerce_order_itemmeta im_pid ON im_pid.order_item_id=oi.order_item_id AND im_pid.meta_key='_product_id'
			LEFT JOIN  {$wpdb->prefix}woocommerce_order_itemmeta im_total ON im_total.order_item_id=oi.order_item_id AND im_total.meta_key='_line_total'
			INNER JOIN {$wpdb->posts} p ON p.ID = CAST(im_pid.meta_value AS UNSIGNED)
			WHERE o.type='shop_order' AND o.status IN (" . brikpanel_paid_statuses_sql() . ")
			  AND o.date_created_gmt >= %s
			  AND ({$period_case}) IS NOT NULL
		";

		$rows_24m = $wpdb->get_results( $wpdb->prepare( $lines_sql, $last_24m ) ); // phpcs:ignore
		if ( empty( $rows_24m ) ) {
			return ''; // No subscription-pattern products actually sold.
		}

		// Compute active subscriptions and ARR/MRR from rows in the
		// "still active" window per period.
		$active_keys = []; // unique customer × product pairs currently active
		$active_arr = 0.0;
		// Renewal-rate inputs: who bought in 13–24m? who re-bought in 0–12m?
		$prior_year_buyers = []; // customer_key => true (bought a yearly product in 13–24m)
		$current_year_buyers = []; // customer_key => true (bought a yearly product in 0–12m)
		$revenue_active = 0.0;
		$revenue_prior = 0.0;
		$annualized_per_period = [
			'year'  => 1,
			'month' => 12,
			'week'  => 52,
			'day'   => 365,
		];

		foreach ( $rows_24m as $r ) {
			$placed = $r->placed;
			$period = $r->period;
			$ck     = (string) $r->customer_key;
			$line   = (float) $r->line_total;

			$is_recent = ( $placed >= $last_12m );
			$is_prior  = ( $placed < $last_12m );

			// "Active" definition by period.
			if ( $period === 'year' && $placed >= $last_year ) {
				$key = $ck . '|y|' . $r->product_id;
				if ( ! isset( $active_keys[ $key ] ) ) {
					$active_keys[ $key ] = true;
					$active_arr += $line;
				}
			} elseif ( $period === 'month' && $placed >= $last_month ) {
				$key = $ck . '|m|' . $r->product_id;
				if ( ! isset( $active_keys[ $key ] ) ) {
					$active_keys[ $key ] = true;
					$active_arr += $line * 12;
				}
			} elseif ( $period === 'week' && $placed >= $this->days_ago_gmt( 8 ) ) {
				$key = $ck . '|w|' . $r->product_id;
				if ( ! isset( $active_keys[ $key ] ) ) {
					$active_keys[ $key ] = true;
					$active_arr += $line * 52;
				}
			}

			// Renewal-rate cohorts (yearly products only — these are the
			// SaaS-style commitments the user cares about).
			if ( $period === 'year' ) {
				if ( $is_prior ) { $prior_year_buyers[ $ck ] = true; }
				if ( $is_recent ) {
					$current_year_buyers[ $ck ] = true;
					$revenue_active += $line;
				}
			}
			// All-period revenue split (MRR change).
			if ( in_array( $period, [ 'year', 'month', 'week', 'day' ], true ) ) {
				$ann = $annualized_per_period[ $period ];
				if ( $is_recent ) { $revenue_active += ( $period === 'year' ? 0 : $line * $ann ); }
				if ( $is_prior )  { $revenue_prior  += $line * $ann; }
			}
		}

		$active_count = count( $active_keys );
		$arr          = $active_arr;
		$mrr          = $arr / 12;

		// Renewal rate (yearly): of customers who bought a yearly product
		// 13–24m ago, how many bought any yearly product 0–12m ago?
		$prior_total = count( $prior_year_buyers );
		$renewed = 0;
		foreach ( $prior_year_buyers as $ck => $_ ) {
			if ( isset( $current_year_buyers[ $ck ] ) ) { $renewed++; }
		}
		$renewal_rate = $prior_total > 0 ? $renewed / $prior_total : null;
		$logo_churn = $renewal_rate === null ? null : 1 - $renewal_rate;

		$lines = [];
		$lines[] = '## ' . __( 'Subscriptions (inferred from product names)', 'brikpanel' );
		$lines[] = '*' . __( 'WC Subscriptions plugin not detected — these metrics are inferred from period markers in product titles (Yıllık / Aylık / Yearly / Monthly etc.). Each paid line item is treated as one billing cycle. Numbers are estimates, not contracts.', 'brikpanel' ) . '*';
		$lines[] = '';
		$lines[] = '- **' . __( 'Active subscription lines', 'brikpanel' ) . ':** ' . number_format_i18n( $active_count );
		$lines[] = '- **ARR:** ' . $this->money( $arr ) . ' | **MRR:** ' . $this->money( $mrr );
		if ( $renewal_rate !== null ) {
			$lines[] = '- **' . __( 'Yearly renewal rate (current vs 13–24m ago cohort)', 'brikpanel' ) . ':** ' . number_format_i18n( $renewal_rate * 100, 1 ) . '% (' . number_format_i18n( $renewed ) . ' / ' . number_format_i18n( $prior_total ) . ')';
			$lines[] = '- **' . __( 'Logo churn (12m, yearly cohort)', 'brikpanel' ) . ':** ' . number_format_i18n( $logo_churn * 100, 1 ) . '%';
		} else {
			$lines[] = '- *' . __( 'Renewal rate cannot be computed yet — no prior-year cohort exists (store is younger than 12 months or has no yearly products purchased before that window).', 'brikpanel' ) . '*';
		}
		$lines[] = '- *' . __( 'NRR is intentionally omitted — it requires per-account expansion / contraction tracking which BrikPanel does not capture.', 'brikpanel' ) . '*';

		$this->register_tldr( 'arr', $arr );
		$this->register_tldr( 'mrr', $mrr );
		$this->register_tldr( 'subs_active', $active_count );
		if ( $renewal_rate !== null ) {
			$this->register_tldr( 'subs_renewal_rate', $renewal_rate );
			$this->register_tldr( 'subs_logo_churn', $logo_churn );
		}

		$lines[] = '';
		$lines[] = '*' . __( 'Source: paid order line items, period inferred from product title pattern.', 'brikpanel' ) . '*';

		return implode( "\n", $lines );
	}

	// =========================================================================
	// SECTION: AVERAGE CUSTOMER LIFESPAN (first → last order, repeats only)
	// =========================================================================

	private function section_customer_lifespan() {
		$agg = $this->customer_aggregates();
		if ( ! $agg || $agg['avg_lifespan_days'] <= 0 ) {
			return '';
		}

		$days   = (float) $agg['avg_lifespan_days'];
		$sample = (int)   $agg['repeat_count'];
		$small  = $sample < 10;

		$lines = [];
		$lines[] = '## ' . __( 'Average Customer Lifespan', 'brikpanel' );
		$lines[] = '- **' . __( 'Average days from first to last order', 'brikpanel' ) . ':** ' . number_format_i18n( $days, 1 ) . ' ' . __( 'days', 'brikpanel' ) . ' (' . number_format_i18n( $days / 30.4, 1 ) . ' ' . __( 'months', 'brikpanel' ) . ')';
		$lines[] = '- **' . __( 'Sample size', 'brikpanel' ) . ':** N = ' . number_format_i18n( $sample ) . ' ' . __( 'customers with ≥2 orders', 'brikpanel' ) . ( $small ? ' — ⚠ ' . __( 'too small for a confident average; treat as a directional indicator only', 'brikpanel' ) : '' );
		$lines[] = '- *' . __( 'For subscription-style stores expect this to converge near 365 days (annual renewals) once the cohort is mature; an unexpectedly short lifespan suggests one-and-done buyers dominate.', 'brikpanel' ) . '*';

		$fn = $this->footnote( 'bp_metrics' );
		if ( $fn ) { $lines[] = ''; $lines[] = $fn; }

		return implode( "\n", $lines );
	}

	// =========================================================================
	// SECTION: TL;DR — assembled last from register_tldr() side-effects
	// =========================================================================

	private function section_tldr() {
		$lines = [];
		$lines[] = '## TL;DR';
		$lines[] = '*' . __( 'Headline numbers — paste this block alone into an AI prompt for a quick-take read.', 'brikpanel' ) . '*';

		$bullet = function ( $label, $value ) use ( &$lines ) {
			$lines[] = '- **' . $label . ':** ' . $value;
		};

		$is_saas = isset( $this->tldr_inputs['arr'] ) && $this->tldr_inputs['arr'] > 0;

		// SaaS-leading layout when ARR is non-zero — investors expect ARR
		// up top. Otherwise fall back to transactional-store ordering.
		if ( $is_saas ) {
			$bullet( 'ARR', $this->money( $this->tldr_inputs['arr'] ) . ' (MRR ' . $this->money( $this->tldr_inputs['mrr'] ?? 0 ) . ')' );
			if ( isset( $this->tldr_inputs['subs_active'] ) ) {
				$bullet( __( 'Active subscription lines', 'brikpanel' ), number_format_i18n( $this->tldr_inputs['subs_active'] ) );
			}
			if ( isset( $this->tldr_inputs['subs_renewal_rate'] ) ) {
				$bullet( __( 'Yearly renewal rate', 'brikpanel' ), number_format_i18n( $this->tldr_inputs['subs_renewal_rate'] * 100, 1 ) . '%' );
			}
			if ( isset( $this->tldr_inputs['subs_logo_churn'] ) ) {
				$bullet( __( 'Logo churn (12m)', 'brikpanel' ), number_format_i18n( $this->tldr_inputs['subs_logo_churn'] * 100, 1 ) . '%' );
			}
		}

		if ( isset( $this->tldr_inputs['last_30d_revenue_cell'] ) ) {
			$bullet(
				__( 'Last 30 days', 'brikpanel' ),
				$this->tldr_inputs['last_30d_revenue_cell'] . ' / ' . number_format_i18n( $this->tldr_inputs['last_30d_orders'] ?? 0 ) . ' ' . __( 'orders', 'brikpanel' )
			);
		}
		if ( isset( $this->tldr_inputs['last_12m_revenue_cell'] ) ) {
			$bullet(
				__( 'Last 12 months', 'brikpanel' ),
				$this->tldr_inputs['last_12m_revenue_cell'] . ' / ' . number_format_i18n( $this->tldr_inputs['last_12m_orders'] ?? 0 ) . ' ' . __( 'orders', 'brikpanel' )
			);
		}

		// Active vs total customers — surfaces the rotating-base reality
		// the user flagged.
		if ( isset( $this->tldr_inputs['active_customers_12m'], $this->tldr_inputs['total_customers_alltime'] ) ) {
			$active = (int) $this->tldr_inputs['active_customers_12m'];
			$total  = (int) $this->tldr_inputs['total_customers_alltime'];
			$bullet(
				__( 'Customers', 'brikpanel' ),
				sprintf(
					/* translators: 1: active count, 2: total tracked, 3: percentage, 4: 30d active */
					__( '%1$s active in last 12m / %2$s tracked all-time (%3$s); %4$s active in last 30d', 'brikpanel' ),
					number_format_i18n( $active ),
					number_format_i18n( $total ),
					$this->pct( $active, $total ),
					number_format_i18n( $this->tldr_inputs['active_customers_30d'] ?? 0 )
				)
			);
		}

		if ( isset( $this->tldr_inputs['repeat_rate'] ) ) {
			$bullet( __( 'Repeat purchase rate', 'brikpanel' ), number_format_i18n( $this->tldr_inputs['repeat_rate'] * 100, 1 ) . '%' );
		}

		if ( isset( $this->tldr_inputs['returning_revenue_share_12m'] ) ) {
			$bullet( __( 'Returning revenue share (12m)', 'brikpanel' ), number_format_i18n( $this->tldr_inputs['returning_revenue_share_12m'] * 100, 1 ) . '%' );
		}

		// Concentration: prefer 12m share when available — it's what
		// investors actually ask about. Fall back to all-time if 12m is
		// missing or zero.
		if ( isset( $this->tldr_inputs['top1_share_12m'] ) && $this->tldr_inputs['top1_share_12m'] > 0 ) {
			$top1_pct = number_format_i18n( $this->tldr_inputs['top1_share_12m'] * 100, 1 ) . '%';
			$top10_pct = isset( $this->tldr_inputs['top10_share_12m'] ) ? number_format_i18n( $this->tldr_inputs['top10_share_12m'] * 100, 1 ) . '%' : '?';
			$bullet( __( 'Customer concentration (last 12m revenue)', 'brikpanel' ), sprintf( __( 'top customer = %s; top 10 = %s', 'brikpanel' ), $top1_pct, $top10_pct ) );
		} elseif ( isset( $this->tldr_inputs['top1_share'] ) ) {
			$top1_pct = number_format_i18n( $this->tldr_inputs['top1_share'] * 100, 1 ) . '%';
			$top10_pct = isset( $this->tldr_inputs['top10_share'] ) ? number_format_i18n( $this->tldr_inputs['top10_share'] * 100, 1 ) . '%' : '?';
			$top1_name = $this->tldr_inputs['top1_customer_name'] ?? '';
			$bullet( __( 'Customer concentration (all-time LTV)', 'brikpanel' ), sprintf( __( 'top customer = %s%s; top 10 = %s', 'brikpanel' ), $top1_pct, $top1_name ? ' (' . $top1_name . ')' : '', $top10_pct ) );
		}

		if ( isset( $this->tldr_inputs['refund_rate_12m'] ) && $this->tldr_inputs['refund_rate_12m'] > 0 ) {
			$bullet( __( 'Refund rate (12m)', 'brikpanel' ), number_format_i18n( $this->tldr_inputs['refund_rate_12m'] * 100, 1 ) . '%' );
		}

		// Money lines last so the reader ends on the bottom line. Net profit is
		// the whole point of pasting this into an AI, and it must never appear
		// without the caveat that uncosted products are counted as free.
		if ( isset( $this->tldr_inputs['ad_spend_12m'] ) && $this->tldr_inputs['ad_spend_12m'] > 0 ) {
			$ads_line = $this->money( $this->tldr_inputs['ad_spend_12m'] );
			if ( isset( $this->tldr_inputs['roas_12m'] ) ) {
				$ads_line .= ' (' . __( 'blended ROAS', 'brikpanel' ) . ' ' . number_format_i18n( $this->tldr_inputs['roas_12m'], 2 ) . 'x)';
			}
			$bullet( __( 'Ad spend (12m)', 'brikpanel' ), $ads_line );
		}

		if ( isset( $this->tldr_inputs['net_profit_12m'] ) ) {
			$net_line = $this->money( $this->tldr_inputs['net_profit_12m'] );
			if ( isset( $this->tldr_inputs['net_margin_12m'] ) ) {
				$net_line .= ' (' . __( 'net margin', 'brikpanel' ) . ' ' . number_format_i18n( $this->tldr_inputs['net_margin_12m'] * 100, 1 ) . '%)';
			}
			if ( isset( $this->tldr_inputs['cogs_coverage_pct'] ) && $this->tldr_inputs['cogs_coverage_pct'] < 99.5 ) {
				$net_line .= ' — ' . sprintf(
					/* translators: %s: percentage of revenue with a product cost on file */
					__( 'overstated: only %s of revenue has a product cost on file', 'brikpanel' ),
					number_format_i18n( $this->tldr_inputs['cogs_coverage_pct'], 1 ) . '%'
				);
			}
			$bullet( __( 'Net profit (12m)', 'brikpanel' ), $net_line );
		}

		// Need at least the headline revenue line to render meaningfully.
		if ( count( $lines ) < 4 ) {
			return '';
		}

		return implode( "\n", $lines );
	}

	// =========================================================================
	// SECTION: ACTIVE BRIKPANEL MODULES
	// =========================================================================

	private function section_modules() {
		$modules = [
			'brikpanel_modern_dashboard'      => __( 'Modern Dashboard', 'brikpanel' ),
			'brikpanel_modern_navigation'     => __( 'Modern Navigation', 'brikpanel' ),
			'brikpanel_modern_login'          => __( 'Modern Login', 'brikpanel' ),
			'brikpanel_modern_segments'       => __( 'Customer Segments', 'brikpanel' ),
			'brikpanel_modern_coupons'        => __( 'Modern Coupons UI', 'brikpanel' ),
			'brikpanel_simple_product_editor' => __( 'Simple Product Editor', 'brikpanel' ),
			'brikpanel_modern_products_list'  => __( 'Modern Products List', 'brikpanel' ),
			'brikpanel_modern_order_edit'     => __( 'Modern Order Edit', 'brikpanel' ),
			'brikpanel_orders_enhancements'   => __( 'Orders List Enhancements', 'brikpanel' ),
			'brikpanel_hide_foreign_notices'  => __( 'Hide Foreign Notices', 'brikpanel' ),
			'brikpanel_order_notify_popup'    => __( 'Order Notification Popup', 'brikpanel' ),
		];

		$lines = [];
		$lines[] = '## ' . __( 'Active BrikPanel Modules', 'brikpanel' );
		foreach ( $modules as $opt => $label ) {
			$enabled = get_option( $opt, 'yes' ) === 'yes';
			$lines[] = ( $enabled ? '- [x] ' : '- [ ] ' ) . $label;
		}

		// BrikMarket (multichannel) presence
		if ( function_exists( 'brikpanel_brikmarket_active' ) ) {
			$lines[] = ( brikpanel_brikmarket_active() ? '- [x] ' : '- [ ] ' ) . __( 'BrikMarket multichannel integration', 'brikpanel' );
		}

		return implode( "\n", $lines );
	}
}

new Brikpanel_Store_Summary();
