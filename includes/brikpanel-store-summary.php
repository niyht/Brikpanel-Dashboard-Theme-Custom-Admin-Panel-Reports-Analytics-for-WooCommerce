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
		$parts = [
			'identity'                  => $this->section_identity(),
			'catalog'                   => $this->section_catalog_composition(),
			'sales_periods'             => $this->section_sales_periods(),
			'yearly'                    => $this->section_yearly_sales(),
			'monthly'                   => $this->section_monthly_sales(),
			'new_vs_returning'          => $this->section_new_vs_returning(),
			'best_worst_times'          => $this->section_best_worst_times(),
			'order_status'              => $this->section_order_status(),
			'failed_orders'             => $this->section_failed_orders(),
			'refund_metrics'            => $this->section_refund_metrics(),
			'top_products'              => $this->section_top_products(),
			'top_customers'             => $this->section_top_customers(),
			'customer_concentration'    => $this->section_customer_concentration(),
			'repeat_purchase_rate'      => $this->section_repeat_purchase_rate(),
			'time_to_first_purchase'    => $this->section_time_to_first_purchase(),
			'rfm'                       => $this->section_rfm_segments(),
			'cohort'                    => $this->section_cohort_retention(),
			'funnel'                    => $this->section_funnel(),
			'cart_abandonment'          => $this->section_cart_abandonment(),
			'devices'                   => $this->section_devices(),
			'geography'                 => $this->section_geography(),
			'shipping'                  => $this->section_shipping(),
			'order_attribution'         => $this->section_order_attribution(),
			'coupons'                   => $this->section_coupons(),
			'coupon_performance'        => $this->section_coupon_performance(),
			'expenses'                  => $this->section_expenses(),
			'profitability'             => $this->section_profitability(),
			'subscriptions'             => $this->section_subscriptions(),
			'customer_lifespan'         => $this->section_customer_lifespan(),
			'modules'                   => $this->section_modules(),
		];

		// TL;DR is computed last (so every register_tldr() has fired) but
		// inserted right after the identity card so the reader sees the
		// headline numbers immediately.
		$tldr = $this->section_tldr();
		$ordered = [];
		foreach ( $parts as $key => $body ) {
			$ordered[ $key ] = $body;
			if ( 'identity' === $key && $tldr !== '' ) {
				$ordered['tldr'] = $tldr;
			}
		}

		// Filter empty sections, join with blank line.
		$ordered = array_filter( $ordered, function ( $p ) { return is_string( $p ) && $p !== ''; } );
		return implode( "\n\n", $ordered ) . "\n";
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

	/** Escape pipe characters that would break Markdown table cells. */
	private function md_cell( $value ) {
		$value = (string) $value;
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
				 WHERE type='shop_order' AND status IN ('wc-processing','wc-completed')
				   AND date_created_gmt >= %s AND date_created_gmt <= %s",
				$start_gmt, $end_gmt
			) ); // phpcs:ignore
		}
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(DISTINCT IFNULL(NULLIF(pm_email.meta_value,''), pm_uid.meta_value))
			 FROM {$wpdb->posts} p
			 LEFT JOIN {$wpdb->postmeta} pm_email ON pm_email.post_id=p.ID AND pm_email.meta_key='_billing_email'
			 LEFT JOIN {$wpdb->postmeta} pm_uid   ON pm_uid.post_id=p.ID   AND pm_uid.meta_key='_customer_user'
			 WHERE p.post_type='shop_order' AND p.post_status IN ('wc-processing','wc-completed')
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
			        WHERE type='shop_order' AND status IN ('wc-processing','wc-completed') AND currency <> ''";
			$args = [];
			if ( $start_gmt ) { $sql .= ' AND date_created_gmt >= %s'; $args[] = $start_gmt; }
			if ( $end_gmt )   { $sql .= ' AND date_created_gmt <= %s'; $args[] = $end_gmt; }
		} else {
			$sql = "SELECT DISTINCT pm.meta_value AS currency FROM {$wpdb->posts} p
			        LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id=p.ID AND pm.meta_key='_order_currency'
			        WHERE p.post_type='shop_order' AND p.post_status IN ('wc-processing','wc-completed')
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
			        WHERE type='shop_order' AND status IN ('wc-processing','wc-completed') AND currency <> ''";
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
			        WHERE p.post_type='shop_order' AND p.post_status IN ('wc-processing','wc-completed')";
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
				'wc_cogs'          => __( 'Source: WooCommerce Cost of Goods Sold — per-line _cogs_value written when an order is placed.', 'brikpanel' ),
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
				 WHERE type = 'shop_order' AND status IN ('wc-processing','wc-completed')"
			); // phpcs:ignore
		} else {
			$row = $wpdb->get_row(
				"SELECT MIN(post_date_gmt) AS first_dt, MAX(post_date_gmt) AS last_dt
				 FROM {$wpdb->posts}
				 WHERE post_type = 'shop_order' AND post_status IN ('wc-processing','wc-completed')"
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
				   AND status IN ('wc-processing','wc-completed')
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
				   AND p.post_status IN ('wc-processing','wc-completed')
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
				   AND status IN ('wc-processing','wc-completed')
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
				   AND p.post_status IN ('wc-processing','wc-completed')
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

	private function section_top_products() {
		global $wpdb;

		$start_dt = $this->months_ago_gmt( 12 );

		// Order items live in {prefix}woocommerce_order_items + {prefix}woocommerce_order_itemmeta.
		// On HPOS, order_items.order_id references {prefix}wc_orders.id; on legacy,
		// it references {prefix}posts.ID. The product_id meta on a variation line
		// item is the PARENT product id — grouping by it correctly rolls up
		// variations into their parent for "top products" rankings.
		$items_tbl   = $wpdb->prefix . 'woocommerce_order_items';
		$itemmeta    = $wpdb->prefix . 'woocommerce_order_itemmeta';

		if ( $this->is_hpos() ) {
			$orders_tbl = $wpdb->prefix . 'wc_orders';
			$join_status = "INNER JOIN {$orders_tbl} o ON o.id = oi.order_id AND o.type='shop_order' AND o.status IN ('wc-processing','wc-completed') AND o.date_created_gmt >= %s";
		} else {
			$join_status = "INNER JOIN {$wpdb->posts} o ON o.ID = oi.order_id AND o.post_type='shop_order' AND o.post_status IN ('wc-processing','wc-completed') AND o.post_date_gmt >= %s";
		}

		$sql = "SELECT
					CAST(im_pid.meta_value AS UNSIGNED) AS product_id,
					SUM(CAST(im_qty.meta_value AS DECIMAL(20,4))) AS qty,
					SUM(CAST(im_total.meta_value AS DECIMAL(20,4))) AS revenue
				FROM {$items_tbl} oi
				{$join_status}
				INNER JOIN {$itemmeta} im_pid   ON im_pid.order_item_id = oi.order_item_id   AND im_pid.meta_key='_product_id'
				INNER JOIN {$itemmeta} im_qty   ON im_qty.order_item_id = oi.order_item_id   AND im_qty.meta_key='_qty'
				INNER JOIN {$itemmeta} im_total ON im_total.order_item_id = oi.order_item_id AND im_total.meta_key='_line_total'
				WHERE oi.order_item_type = 'line_item'
				GROUP BY product_id
				HAVING revenue > 0
				ORDER BY revenue DESC
				LIMIT 10";

		// One query, ordered by revenue DESC. Units shown alongside in the
		// same table (the user requested merging the previous two-table
		// layout — same data, half the visual noise).
		$rows_rev = $wpdb->get_results( $wpdb->prepare( $sql, $start_dt ) ); // phpcs:ignore

		if ( empty( $rows_rev ) ) {
			return '';
		}

		$out = [];
		$out[] = '## ' . __( 'Top Products (last 12 months)', 'brikpanel' );
		$out[] = '*' . __( 'Variations are rolled up to their parent product. Sorted by revenue; the Units column lets you spot volume-vs-value differences.', 'brikpanel' ) . '*';
		$out[] = '';
		$out[] = '| # | ' . __( 'Product', 'brikpanel' ) . ' | ' . __( 'SKU', 'brikpanel' ) . ' | ' . __( 'Units', 'brikpanel' ) . ' | ' . __( 'Revenue', 'brikpanel' ) . ' | ' . __( 'Avg Price', 'brikpanel' ) . ' |';
		$out[] = '|---:|---|---|---:|---:|---:|';
		$i = 1;
		foreach ( $rows_rev as $r ) {
			$pid  = (int) $r->product_id;
			$prod = $pid ? wc_get_product( $pid ) : null;
			$name = $prod ? $prod->get_name() : sprintf( __( '(deleted #%d)', 'brikpanel' ), $pid );
			$sku  = $prod ? $prod->get_sku() : '';
			$qty  = (float) $r->qty;
			$rev  = (float) $r->revenue;
			$avg  = $qty > 0 ? $rev / $qty : 0;
			$out[] = '| ' . $i . ' | ' . $this->md_cell( $name ) . ' | ' . $this->md_cell( $sku ?: '—' ) . ' | ' . number_format_i18n( $qty ) . ' | ' . $this->money( $rev ) . ' | ' . $this->money( $avg ) . ' |';
			$i++;
		}

		$fn = $this->footnote( 'wc_orders' );
		if ( $fn ) { $out[] = ''; $out[] = $fn; }

		return implode( "\n", $out );
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
		$success   = brikpanel_get_successful_order_count( $start_gmt, $end_gmt );

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

		$start = gmdate( 'Y-m-d', strtotime( '-12 months', current_time( 'timestamp', true ) ) );

		$total_12m = (float) $wpdb->get_var( $wpdb->prepare(
			"SELECT COALESCE(SUM(amount),0) FROM {$tbl} WHERE expense_date >= %s",
			$start
		) ); // phpcs:ignore

		$total_all = (float) $wpdb->get_var( "SELECT COALESCE(SUM(amount),0) FROM {$tbl}" ); // phpcs:ignore

		$by_cat = $wpdb->get_results( $wpdb->prepare(
			"SELECT IF(category='', 'uncategorized', category) AS category,
					COALESCE(SUM(amount),0) AS total,
					COUNT(*) AS entries
			 FROM {$tbl}
			 WHERE expense_date >= %s
			 GROUP BY category
			 ORDER BY total DESC",
			$start
		) ); // phpcs:ignore

		$lines = [];
		$lines[] = '## ' . __( 'Expenses', 'brikpanel' );
		$lines[] = '- **' . __( 'Last 12 months total', 'brikpanel' ) . ':** ' . $this->money( $total_12m );
		$lines[] = '- **' . __( 'All-time recorded total', 'brikpanel' ) . ':** ' . $this->money( $total_all );

		if ( ! empty( $by_cat ) ) {
			$lines[] = '';
			$lines[] = '### ' . __( 'By Category (last 12 months)', 'brikpanel' );
			$lines[] = '| ' . __( 'Category', 'brikpanel' ) . ' | ' . __( 'Entries', 'brikpanel' ) . ' | ' . __( 'Total', 'brikpanel' ) . ' | ' . __( 'Share', 'brikpanel' ) . ' |';
			$lines[] = '|---|---:|---:|---:|';
			foreach ( $by_cat as $r ) {
				$lines[] = '| ' . $this->md_cell( $r->category ) . ' | ' . number_format_i18n( $r->entries ) . ' | ' . $this->money( $r->total ) . ' | ' . $this->pct( $r->total, $total_12m ) . ' |';
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
		$inv_row = $wpdb->get_row(
			"SELECT
				COALESCE(SUM(CAST(stock.meta_value AS DECIMAL(20,4))), 0)                                                       AS units,
				COALESCE(SUM(CAST(stock.meta_value AS DECIMAL(20,4)) * CAST(price.meta_value AS DECIMAL(20,4))), 0)             AS retail_value,
				COALESCE(SUM(CAST(stock.meta_value AS DECIMAL(20,4)) * CAST(IFNULL(cogs.meta_value,'0') AS DECIMAL(20,4))), 0)  AS cogs_value
			 FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} stock ON stock.post_id=p.ID AND stock.meta_key='_stock' AND stock.meta_value <> ''
			 LEFT JOIN  {$wpdb->postmeta} price ON price.post_id=p.ID AND price.meta_key='_price'
			 LEFT JOIN  {$wpdb->postmeta} cogs  ON cogs.post_id=p.ID  AND cogs.meta_key='_cogs_total_value'
			 WHERE p.post_type IN ('product','product_variation') AND p.post_status='publish'"
		); // phpcs:ignore

		$products_with_cogs = (int) $wpdb->get_var(
			"SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id=p.ID
			 WHERE p.post_type IN ('product','product_variation') AND p.post_status='publish'
			   AND pm.meta_key='_cogs_total_value' AND pm.meta_value <> '' AND CAST(pm.meta_value AS DECIMAL(20,4)) > 0"
		); // phpcs:ignore

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

		$fn = $this->footnote( 'wc_cogs' );
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
				 WHERE o.type='shop_order' AND o.status IN ('wc-processing','wc-completed')
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
				 WHERE p.post_type='shop_order' AND p.post_status IN ('wc-processing','wc-completed')
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
				   AND o.type='shop_order' AND o.status IN ('wc-processing','wc-completed')
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
				   AND p.post_type='shop_order' AND p.post_status IN ('wc-processing','wc-completed')
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
				 WHERE o.type='shop_order' AND o.status IN ('wc-processing','wc-completed')
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
				 WHERE p.post_type='shop_order' AND p.post_status IN ('wc-processing','wc-completed')
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

	private function section_profitability() {
		global $wpdb;

		$start_dt = $this->months_ago_gmt( 12 );

		// Monthly revenue
		if ( $this->is_hpos() ) {
			$rev_rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT DATE_FORMAT(date_created_gmt, '%%Y-%%m') AS ym,
				        SUM(total_amount) AS revenue
				 FROM {$wpdb->prefix}wc_orders
				 WHERE type='shop_order'
				   AND status IN ('wc-processing','wc-completed')
				   AND date_created_gmt >= %s
				 GROUP BY ym",
				$start_dt
			) ); // phpcs:ignore

			$ref_rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT DATE_FORMAT(date_created_gmt, '%%Y-%%m') AS ym,
				        SUM(ABS(total_amount)) AS refunds
				 FROM {$wpdb->prefix}wc_orders
				 WHERE type='shop_order_refund'
				   AND date_created_gmt >= %s
				 GROUP BY ym",
				$start_dt
			) ); // phpcs:ignore

			// Monthly COGS — sum line-item _cogs_value across processing/completed orders.
			$cogs_rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT DATE_FORMAT(o.date_created_gmt, '%%Y-%%m') AS ym,
				        COALESCE(SUM(CAST(im.meta_value AS DECIMAL(20,4))),0) AS cogs
				 FROM {$wpdb->prefix}wc_orders o
				 INNER JOIN {$wpdb->prefix}woocommerce_order_items oi ON oi.order_id=o.id AND oi.order_item_type='line_item'
				 INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta im ON im.order_item_id=oi.order_item_id AND im.meta_key='_cogs_value'
				 WHERE o.type='shop_order' AND o.status IN ('wc-processing','wc-completed')
				   AND o.date_created_gmt >= %s
				 GROUP BY ym",
				$start_dt
			) ); // phpcs:ignore
		} else {
			$rev_rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT DATE_FORMAT(p.post_date_gmt, '%%Y-%%m') AS ym,
				        SUM(pm.meta_value) AS revenue
				 FROM {$wpdb->posts} p
				 LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id=p.ID AND pm.meta_key='_order_total'
				 WHERE p.post_type='shop_order'
				   AND p.post_status IN ('wc-processing','wc-completed')
				   AND p.post_date_gmt >= %s
				 GROUP BY ym",
				$start_dt
			) ); // phpcs:ignore

			$ref_rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT DATE_FORMAT(p.post_date_gmt, '%%Y-%%m') AS ym,
				        SUM(ABS(pm.meta_value)) AS refunds
				 FROM {$wpdb->posts} p
				 LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id=p.ID AND pm.meta_key='_order_total'
				 WHERE p.post_type='shop_order_refund'
				   AND p.post_date_gmt >= %s
				 GROUP BY ym",
				$start_dt
			) ); // phpcs:ignore

			$cogs_rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT DATE_FORMAT(p.post_date_gmt, '%%Y-%%m') AS ym,
				        COALESCE(SUM(CAST(im.meta_value AS DECIMAL(20,4))),0) AS cogs
				 FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->prefix}woocommerce_order_items oi ON oi.order_id=p.ID AND oi.order_item_type='line_item'
				 INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta im ON im.order_item_id=oi.order_item_id AND im.meta_key='_cogs_value'
				 WHERE p.post_type='shop_order' AND p.post_status IN ('wc-processing','wc-completed')
				   AND p.post_date_gmt >= %s
				 GROUP BY ym",
				$start_dt
			) ); // phpcs:ignore
		}

		$exp_rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT DATE_FORMAT(expense_date, '%%Y-%%m') AS ym,
			        SUM(amount) AS expenses
			 FROM {$wpdb->prefix}brikpanel_expenses
			 WHERE expense_date >= %s
			 GROUP BY ym",
			gmdate( 'Y-m-d', strtotime( $start_dt ) )
		) ); // phpcs:ignore

		// Build a 12-month axis
		$axis = [];
		for ( $i = 11; $i >= 0; $i-- ) {
			$key = gmdate( 'Y-m', strtotime( '-' . $i . ' months', current_time( 'timestamp', true ) ) );
			$axis[ $key ] = [ 'rev' => 0.0, 'ref' => 0.0, 'cogs' => 0.0, 'exp' => 0.0 ];
		}
		foreach ( $rev_rows as $r )  { if ( isset( $axis[ $r->ym ] ) ) { $axis[ $r->ym ]['rev']  = (float) $r->revenue; } }
		foreach ( $ref_rows as $r )  { if ( isset( $axis[ $r->ym ] ) ) { $axis[ $r->ym ]['ref']  = (float) $r->refunds; } }
		foreach ( $cogs_rows as $r ) { if ( isset( $axis[ $r->ym ] ) ) { $axis[ $r->ym ]['cogs'] = (float) $r->cogs; } }
		foreach ( $exp_rows as $r )  { if ( isset( $axis[ $r->ym ] ) ) { $axis[ $r->ym ]['exp']  = (float) $r->expenses; } }

		$tot_rev = 0.0; $tot_ref = 0.0; $tot_cogs = 0.0; $tot_exp = 0.0;
		foreach ( $axis as $r ) { $tot_rev += $r['rev']; $tot_ref += $r['ref']; $tot_cogs += $r['cogs']; $tot_exp += $r['exp']; }
		$has_cogs = $tot_cogs > 0;
		$net_rev  = $tot_rev - $tot_ref;
		$gross    = $net_rev - $tot_cogs;
		$net      = $gross - $tot_exp;

		$lines = [];
		$lines[] = '## ' . __( 'Profitability (last 12 months)', 'brikpanel' );
		if ( $has_cogs ) {
			$lines[] = '*' . __( 'Gross = Revenue − Refunds − COGS. Net = Gross − Tracked expenses. COGS comes from per-line `_cogs_value` written by WooCommerce when an order is placed; products without a configured cost contribute zero.', 'brikpanel' ) . '*';
		} else {
			$lines[] = '*' . __( 'COGS not tracked on any line item — Net = Revenue − Refunds − Tracked expenses (operating contribution, not full P&L).', 'brikpanel' ) . '*';
		}
		$lines[] = '';
		$lines[] = '- **' . __( 'Total revenue', 'brikpanel' ) . ':** ' . $this->money( $tot_rev );
		$lines[] = '- **' . __( 'Total refunds', 'brikpanel' ) . ':** ' . $this->money( $tot_ref );
		if ( $has_cogs ) {
			$lines[] = '- **' . __( 'Total COGS', 'brikpanel' ) . ':** ' . $this->money( $tot_cogs ) . ( $net_rev > 0 ? ' (' . number_format_i18n( ( $tot_cogs / $net_rev ) * 100, 1 ) . '% ' . __( 'of net revenue', 'brikpanel' ) . ')' : '' );
			$lines[] = '- **' . __( 'Gross profit', 'brikpanel' ) . ':** ' . $this->money( $gross ) . ( $net_rev > 0 ? ' (' . __( 'gross margin', 'brikpanel' ) . ' ' . number_format_i18n( ( $gross / $net_rev ) * 100, 1 ) . '%)' : '' );
		}
		$lines[] = '- **' . __( 'Total expenses', 'brikpanel' ) . ':** ' . $this->money( $tot_exp );
		$lines[] = '- **' . __( 'Net contribution', 'brikpanel' ) . ':** ' . $this->money( $net ) . ( $tot_rev > 0 ? ' (' . __( 'net margin', 'brikpanel' ) . ' ' . number_format_i18n( ( $net / $tot_rev ) * 100, 1 ) . '%)' : '' );
		$lines[] = '';

		// Strict collapse: drop EVERY all-zero month, leading or interior.
		// The earlier "leading-only" rule produced inconsistent tables
		// (some interior zero months hidden, others kept depending on
		// where they sat). All-or-nothing is easier to explain and
		// matches the behaviour of section_monthly_sales().
		$active_ym  = [];
		$silent_run = 0;
		foreach ( $axis as $ym => $r ) {
			if ( $this->is_zero_row( [ $r['rev'], $r['ref'], $r['cogs'], $r['exp'] ] ) ) {
				$silent_run++;
				continue;
			}
			$active_ym[ $ym ] = $r;
		}
		// Fully-dormant store: keep the most recent month so the reader
		// at least sees the table structure.
		if ( empty( $active_ym ) && ! empty( $axis ) ) {
			$last_key = array_key_last( $axis );
			$active_ym[ $last_key ] = $axis[ $last_key ];
		}

		if ( $has_cogs ) {
			$lines[] = '| ' . __( 'Month', 'brikpanel' ) . ' | ' . __( 'Revenue', 'brikpanel' ) . ' | ' . __( 'Refunds', 'brikpanel' ) . ' | ' . __( 'COGS', 'brikpanel' ) . ' | ' . __( 'Gross', 'brikpanel' ) . ' | ' . __( 'Expenses', 'brikpanel' ) . ' | ' . __( 'Net', 'brikpanel' ) . ' |';
			$lines[] = '|---|---:|---:|---:|---:|---:|---:|';
			foreach ( $active_ym as $ym => $r ) {
				$g = $r['rev'] - $r['ref'] - $r['cogs'];
				$n = $g - $r['exp'];
				$lines[] = '| ' . $ym . ' | ' . $this->money( $r['rev'] ) . ' | ' . $this->money( $r['ref'] ) . ' | ' . $this->money( $r['cogs'] ) . ' | ' . $this->money( $g ) . ' | ' . $this->money( $r['exp'] ) . ' | ' . $this->money( $n ) . ' |';
			}
		} else {
			$lines[] = '| ' . __( 'Month', 'brikpanel' ) . ' | ' . __( 'Revenue', 'brikpanel' ) . ' | ' . __( 'Refunds', 'brikpanel' ) . ' | ' . __( 'Expenses', 'brikpanel' ) . ' | ' . __( 'Net', 'brikpanel' ) . ' |';
			$lines[] = '|---|---:|---:|---:|---:|';
			foreach ( $active_ym as $ym => $r ) {
				$n = $r['rev'] - $r['ref'] - $r['exp'];
				$lines[] = '| ' . $ym . ' | ' . $this->money( $r['rev'] ) . ' | ' . $this->money( $r['ref'] ) . ' | ' . $this->money( $r['exp'] ) . ' | ' . $this->money( $n ) . ' |';
			}
		}

		// Tell the reader how many months were collapsed so they can
		// reconstruct the timeline without showing the empty rows.
		$shown = count( $active_ym );
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

		$fn_cogs = $has_cogs ? $this->footnote( 'wc_cogs' ) : '';
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
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT DATE_FORMAT(o.date_created_gmt, '%%Y-%%m') AS ym,
				        SUM(CASE WHEN o.date_created_gmt = m.first_order_date THEN o.total_amount ELSE 0 END) AS new_rev,
				        SUM(CASE WHEN o.date_created_gmt > m.first_order_date THEN o.total_amount ELSE 0 END) AS ret_rev,
				        SUM(CASE WHEN o.date_created_gmt = m.first_order_date THEN 1 ELSE 0 END) AS new_orders,
				        SUM(CASE WHEN o.date_created_gmt > m.first_order_date THEN 1 ELSE 0 END) AS ret_orders
				 FROM {$wpdb->prefix}wc_orders o
				 INNER JOIN {$tbl_metrics} m
				   ON ( ( o.customer_id > 0 AND m.user_id = o.customer_id ) OR ( o.customer_id = 0 AND m.customer_email = o.billing_email ) )
				 WHERE o.type='shop_order' AND o.status IN ('wc-processing','wc-completed')
				   AND o.date_created_gmt >= %s
				 GROUP BY ym
				 ORDER BY ym ASC",
				$start_dt
			) ); // phpcs:ignore
		} else {
			// Legacy postmeta path (rarely hit since most stores are HPOS now).
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT DATE_FORMAT(p.post_date_gmt, '%%Y-%%m') AS ym,
				        SUM(CASE WHEN p.post_date_gmt = m.first_order_date THEN CAST(pm_t.meta_value AS DECIMAL(20,4)) ELSE 0 END) AS new_rev,
				        SUM(CASE WHEN p.post_date_gmt > m.first_order_date THEN CAST(pm_t.meta_value AS DECIMAL(20,4)) ELSE 0 END) AS ret_rev,
				        SUM(CASE WHEN p.post_date_gmt = m.first_order_date THEN 1 ELSE 0 END) AS new_orders,
				        SUM(CASE WHEN p.post_date_gmt > m.first_order_date THEN 1 ELSE 0 END) AS ret_orders
				 FROM {$wpdb->posts} p
				 LEFT JOIN {$wpdb->postmeta} pm_t ON pm_t.post_id=p.ID AND pm_t.meta_key='_order_total'
				 LEFT JOIN {$wpdb->postmeta} pm_e ON pm_e.post_id=p.ID AND pm_e.meta_key='_billing_email'
				 LEFT JOIN {$wpdb->postmeta} pm_c ON pm_c.post_id=p.ID AND pm_c.meta_key='_customer_user'
				 INNER JOIN {$tbl_metrics} m
				   ON ( ( CAST(pm_c.meta_value AS UNSIGNED) > 0 AND m.user_id = CAST(pm_c.meta_value AS UNSIGNED) )
				        OR ( CAST(IFNULL(pm_c.meta_value,'0') AS UNSIGNED) = 0 AND m.customer_email = pm_e.meta_value ) )
				 WHERE p.post_type='shop_order' AND p.post_status IN ('wc-processing','wc-completed')
				   AND p.post_date_gmt >= %s
				 GROUP BY ym
				 ORDER BY ym ASC",
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
				 WHERE type='shop_order' AND status IN ('wc-processing','wc-completed')
				   AND date_created_gmt >= %s
				 GROUP BY dow",
				$start_dt
			) ); // phpcs:ignore
			$hr_rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT HOUR(date_created_gmt) AS hr, COUNT(*) AS orders, COALESCE(SUM(total_amount),0) AS revenue
				 FROM {$wpdb->prefix}wc_orders
				 WHERE type='shop_order' AND status IN ('wc-processing','wc-completed')
				   AND date_created_gmt >= %s
				 GROUP BY hr",
				$start_dt
			) ); // phpcs:ignore
		} else {
			$dow_rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT DAYOFWEEK(post_date_gmt) AS dow, COUNT(*) AS orders, COALESCE(SUM(CAST(pm.meta_value AS DECIMAL(20,4))),0) AS revenue
				 FROM {$wpdb->posts} p
				 LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id=p.ID AND pm.meta_key='_order_total'
				 WHERE p.post_type='shop_order' AND p.post_status IN ('wc-processing','wc-completed')
				   AND p.post_date_gmt >= %s
				 GROUP BY dow",
				$start_dt
			) ); // phpcs:ignore
			$hr_rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT HOUR(post_date_gmt) AS hr, COUNT(*) AS orders, COALESCE(SUM(CAST(pm.meta_value AS DECIMAL(20,4))),0) AS revenue
				 FROM {$wpdb->posts} p
				 LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id=p.ID AND pm.meta_key='_order_total'
				 WHERE p.post_type='shop_order' AND p.post_status IN ('wc-processing','wc-completed')
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
				 WHERE type='shop_order' AND status IN ('wc-processing','wc-completed') AND date_created_gmt >= %s",
				$start_dt
			) ); // phpcs:ignore
			$total_revenue = (float) $wpdb->get_var( $wpdb->prepare(
				"SELECT COALESCE(SUM(total_amount),0) FROM {$wpdb->prefix}wc_orders
				 WHERE type='shop_order' AND status IN ('wc-processing','wc-completed') AND date_created_gmt >= %s",
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
				 WHERE post_type='shop_order' AND post_status IN ('wc-processing','wc-completed') AND post_date_gmt >= %s",
				$start_dt
			) ); // phpcs:ignore
			$total_revenue = (float) $wpdb->get_var( $wpdb->prepare(
				"SELECT COALESCE(SUM(CAST(pm.meta_value AS DECIMAL(20,4))),0) FROM {$wpdb->posts} p
				 LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id=p.ID AND pm.meta_key='_order_total'
				 WHERE p.post_type='shop_order' AND p.post_status IN ('wc-processing','wc-completed') AND p.post_date_gmt >= %s",
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
				 WHERE type='shop_order' AND status IN ('wc-processing','wc-completed')
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
				 WHERE p.post_type='shop_order' AND p.post_status IN ('wc-processing','wc-completed')
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
				   AND o.type='shop_order' AND o.status IN ('wc-processing','wc-completed')
				   AND o.date_created_gmt >= %s
				 GROUP BY code
				 ORDER BY uses DESC LIMIT 10",
				$start_dt
			) ); // phpcs:ignore
			$with_coupon = $wpdb->get_row( $wpdb->prepare(
				"SELECT COUNT(DISTINCT o.id) AS cnt, COALESCE(SUM(o.total_amount),0) AS rev
				 FROM {$wpdb->prefix}wc_orders o
				 INNER JOIN {$wpdb->prefix}woocommerce_order_items oi ON oi.order_id=o.id AND oi.order_item_type='coupon'
				 WHERE o.type='shop_order' AND o.status IN ('wc-processing','wc-completed')
				   AND o.date_created_gmt >= %s",
				$start_dt
			) ); // phpcs:ignore
			$all = $wpdb->get_row( $wpdb->prepare(
				"SELECT COUNT(*) AS cnt, COALESCE(SUM(total_amount),0) AS rev
				 FROM {$wpdb->prefix}wc_orders
				 WHERE type='shop_order' AND status IN ('wc-processing','wc-completed')
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
				   AND p.post_type='shop_order' AND p.post_status IN ('wc-processing','wc-completed')
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
				 WHERE p.post_type='shop_order' AND p.post_status IN ('wc-processing','wc-completed')
				   AND p.post_date_gmt >= %s",
				$start_dt
			) ); // phpcs:ignore
			$all = $wpdb->get_row( $wpdb->prepare(
				"SELECT COUNT(*) AS cnt, COALESCE(SUM(CAST(pm.meta_value AS DECIMAL(20,4))),0) AS rev
				 FROM {$wpdb->posts} p
				 LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id=p.ID AND pm.meta_key='_order_total'
				 WHERE p.post_type='shop_order' AND p.post_status IN ('wc-processing','wc-completed')
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
				 WHERE o.type='shop_order' AND o.status IN ('wc-processing','wc-completed')
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
				 WHERE p.post_type='shop_order' AND p.post_status IN ('wc-processing','wc-completed')
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
				 WHERE o.type='shop_order' AND o.status IN ('wc-processing','wc-completed')
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
				 WHERE p.post_type='shop_order' AND p.post_status IN ('wc-processing','wc-completed')
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
					 WHERE o.type='shop_order' AND o.status IN ('wc-processing','wc-completed')
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
					 WHERE p.post_type='shop_order' AND p.post_status IN ('wc-processing','wc-completed')
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
			WHERE o.type='shop_order' AND o.status IN ('wc-processing','wc-completed')
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
