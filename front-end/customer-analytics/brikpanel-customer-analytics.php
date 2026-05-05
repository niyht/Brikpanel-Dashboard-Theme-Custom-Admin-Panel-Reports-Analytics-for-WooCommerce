<?php
/**
 * BrikPanel — Customer Analytics
 *
 * Dedicated admin page that surfaces precomputed customer metrics: LTV
 * (Phase 1), RFM segmentation (Phase 2), and cohort retention (Phase 3).
 *
 * Reads from {prefix}brikpanel_customer_metrics — populated nightly by
 * the brikpanel_recompute_customer_metrics Action Scheduler job. AJAX
 * endpoints never aggregate orders inline, so the page stays fast even
 * on stores with hundreds of thousands of customers.
 *
 * @package BrikPanel
 * @since   2.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Brikpanel_Customer_Analytics {

	const PAGE_SLUG       = 'brikpanel-customer-analytics';
	const NONCE_ACTION    = 'brikpanel_customer_analytics_nonce';
	const PAGE_SIZE       = 25;
	const CACHE_TTL       = HOUR_IN_SECONDS;
	const CACHE_VER_OPT   = 'brikpanel_ca_cache_ver';

	/**
	 * Read-through cache for read-only AJAX payloads. The metrics table is
	 * recomputed once a day (cron) or manually (Recompute now) — both paths
	 * bump the version, so caches never serve stale data.
	 *
	 * @param string $bucket Logical key, e.g. "ltv_summary".
	 * @param array  $args   Args that should affect the cache key.
	 * @param callable $compute Returns the value to cache.
	 */
	private function cached( $bucket, array $args, callable $compute ) {
		$ver = (int) get_option( self::CACHE_VER_OPT, 1 );
		$key = 'bp_ca_' . $ver . '_' . $bucket . '_' . md5( wp_json_encode( $args ) );
		$hit = get_transient( $key );
		if ( false !== $hit ) {
			return $hit;
		}
		$value = $compute();
		// Without external object cache, transients hit wp_options. Extend TTL
		// so the per-read cost amortises over many more hits.
		$ttl = function_exists( 'brikpanel_cache_ttl' )
			? brikpanel_cache_ttl( self::CACHE_TTL )
			: self::CACHE_TTL;
		set_transient( $key, $value, $ttl );
		return $value;
	}

	/**
	 * Invalidate every cached payload by bumping the version. Stale transients
	 * age out on their own; we don't iterate to delete them (cheap + scales).
	 */
	public static function bust_cache() {
		update_option( self::CACHE_VER_OPT, (int) get_option( self::CACHE_VER_OPT, 1 ) + 1, false );
	}

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'register_page' ] );

		add_action( 'wp_ajax_brikpanel_ca_ltv_summary',       [ $this, 'ajax_ltv_summary' ] );
		add_action( 'wp_ajax_brikpanel_ca_ltv_top_customers', [ $this, 'ajax_ltv_top_customers' ] );
		add_action( 'wp_ajax_brikpanel_ca_ltv_distribution',  [ $this, 'ajax_ltv_distribution' ] );
		add_action( 'wp_ajax_brikpanel_ca_ltv_export',        [ $this, 'ajax_ltv_export' ] );
		add_action( 'wp_ajax_brikpanel_ca_rfm_summary',       [ $this, 'ajax_rfm_summary' ] );
		add_action( 'wp_ajax_brikpanel_ca_rfm_customers',     [ $this, 'ajax_rfm_customers' ] );
		add_action( 'wp_ajax_brikpanel_ca_rfm_export',        [ $this, 'ajax_rfm_export' ] );
		add_action( 'wp_ajax_brikpanel_ca_cohort_matrix',     [ $this, 'ajax_cohort_matrix' ] );
		add_action( 'wp_ajax_brikpanel_ca_cohort_export',     [ $this, 'ajax_cohort_export' ] );
		add_action( 'wp_ajax_brikpanel_ca_recompute_now',     [ $this, 'ajax_recompute_now' ] );
	}

	// =========================================================================
	// PAGE REGISTRATION
	// =========================================================================

	public function register_page() {
		// Promoted to a top-level menu item; the modern-nav layer
		// (front-end/navigation/) re-positions it directly after Segments.
		$hook = add_menu_page(
			__( 'Customer Analytics', 'brikpanel' ),
			__( 'Customer Analytics', 'brikpanel' ),
			'manage_woocommerce',
			self::PAGE_SLUG,
			[ $this, 'render_page' ],
			'dashicons-businessperson',
			56.7
		);

		if ( $hook ) {
			add_action( 'load-' . $hook, function () {
				global $title;
				$title = __( 'Customer Analytics', 'brikpanel' );
			} );
		}
	}

	public function render_page() {
		$metrics = $this->compute_metrics_meta();
		include __DIR__ . '/views/page.php';
	}

	// =========================================================================
	// SECURITY
	// =========================================================================

	private function check_auth() {
		check_ajax_referer( self::NONCE_ACTION, '_ajax_nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'brikpanel' ) ], 403 );
		}
	}

	// =========================================================================
	// META: when was the table last computed?
	// =========================================================================

	private function compute_metrics_meta() {
		global $wpdb;
		$tbl = $wpdb->prefix . 'brikpanel_customer_metrics';
		$row = $wpdb->get_row( "SELECT MAX(computed_at) AS last_computed, COUNT(*) AS total FROM {$tbl}" ); // phpcs:ignore
		return [
			'last_computed'  => $row && $row->last_computed ? mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $row->last_computed ) : '',
			'last_computed_iso' => $row && $row->last_computed ? $row->last_computed : '',
			'total_customers'   => (int) ( $row->total ?? 0 ),
		];
	}

	// =========================================================================
	// AJAX: LTV summary metrics (4 top cards)
	// =========================================================================

	public function ajax_ltv_summary() {
		$this->check_auth();
		$payload = $this->cached( 'ltv_summary', [], function () {
			global $wpdb;
			$tbl = $wpdb->prefix . 'brikpanel_customer_metrics';

			$row = $wpdb->get_row( "SELECT
					COUNT(*) AS total_customers,
					COALESCE(AVG(total_spent), 0) AS avg_ltv,
					COALESCE(SUM(total_spent), 0) AS total_ltv,
					COALESCE(AVG(aov), 0) AS avg_aov,
					COALESCE(MAX(total_spent), 0) AS max_ltv,
					COUNT(CASE WHEN order_count > 1 THEN 1 END) AS repeat_customers
				FROM {$tbl}" ); // phpcs:ignore

			$median = (float) $wpdb->get_var( "
				SELECT AVG(total_spent) FROM (
					SELECT total_spent,
						ROW_NUMBER() OVER (ORDER BY total_spent) AS rn,
						COUNT(*) OVER () AS cnt
					FROM {$tbl}
				) t
				WHERE rn IN (FLOOR((cnt+1)/2), CEIL((cnt+1)/2))
			" ); // phpcs:ignore

			$total_customers = (int) ( $row->total_customers ?? 0 );
			$repeat          = (int) ( $row->repeat_customers ?? 0 );

			return [
				'total_customers'    => $total_customers,
				'repeat_customers'   => $repeat,
				'repeat_rate'        => $total_customers > 0 ? round( $repeat / $total_customers * 100, 1 ) : 0,
				'avg_ltv'            => (float) ( $row->avg_ltv ?? 0 ),
				'avg_ltv_display'    => $this->price( (float) ( $row->avg_ltv ?? 0 ) ),
				'median_ltv'         => $median,
				'median_ltv_display' => $this->price( $median ),
				'total_ltv'          => (float) ( $row->total_ltv ?? 0 ),
				'total_ltv_display'  => $this->price( (float) ( $row->total_ltv ?? 0 ) ),
				'avg_aov'            => (float) ( $row->avg_aov ?? 0 ),
				'avg_aov_display'    => $this->price( (float) ( $row->avg_aov ?? 0 ) ),
				'max_ltv'            => (float) ( $row->max_ltv ?? 0 ),
				'max_ltv_display'    => $this->price( (float) ( $row->max_ltv ?? 0 ) ),
			];
		} );
		wp_send_json_success( $payload );
	}

	// =========================================================================
	// AJAX: Top customers by LTV (paginated)
	// =========================================================================

	public function ajax_ltv_top_customers() {
		$this->check_auth();
		global $wpdb;
		$tbl = $wpdb->prefix . 'brikpanel_customer_metrics';

		$page     = max( 1, (int) ( $_POST['page'] ?? 1 ) );
		$per_page = min( 100, max( 5, (int) ( $_POST['per_page'] ?? self::PAGE_SIZE ) ) );
		$offset   = ( $page - 1 ) * $per_page;

		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tbl}" ); // phpcs:ignore

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT m.customer_key, m.user_id, m.customer_email, m.first_order_date, m.last_order_date,
				m.order_count, m.total_spent, m.aov, m.recency_days,
				u.display_name,
				bm_fn.meta_value AS billing_first_name,
				bm_ln.meta_value AS billing_last_name
			FROM {$tbl} m
			LEFT JOIN {$wpdb->users} u ON m.user_id = u.ID AND m.user_id > 0
			LEFT JOIN {$wpdb->usermeta} bm_fn ON bm_fn.user_id = u.ID AND bm_fn.meta_key = 'billing_first_name'
			LEFT JOIN {$wpdb->usermeta} bm_ln ON bm_ln.user_id = u.ID AND bm_ln.meta_key = 'billing_last_name'
			ORDER BY m.total_spent DESC
			LIMIT %d OFFSET %d",
			$per_page,
			$offset
		) ); // phpcs:ignore

		$items = [];
		foreach ( $rows as $r ) {
			$user_id = (int) $r->user_id;
			$name    = trim( trim( (string) $r->billing_first_name . ' ' . (string) $r->billing_last_name ) );
			if ( $name === '' ) {
				$name = (string) $r->display_name;
			}
			if ( $name === '' ) {
				$name = (string) $r->customer_email;
			}

			$items[] = [
				'user_id'             => $user_id,
				'name'                => $name,
				'email'               => (string) $r->customer_email,
				'order_count'         => (int) $r->order_count,
				'total_spent'         => (float) $r->total_spent,
				'total_spent_display' => $this->price( (float) $r->total_spent ),
				'aov'                 => (float) $r->aov,
				'aov_display'         => $this->price( (float) $r->aov ),
				'recency_days'        => $r->recency_days !== null ? (int) $r->recency_days : null,
				'first_order'         => $r->first_order_date ? mysql2date( get_option( 'date_format' ), $r->first_order_date ) : '',
				'last_order'          => $r->last_order_date ? mysql2date( get_option( 'date_format' ), $r->last_order_date ) : '',
				'edit_url'            => $user_id ? admin_url( 'user-edit.php?user_id=' . $user_id ) : '',
				'is_guest'            => $user_id === 0,
			];
		}

		wp_send_json_success( [
			'items'    => $items,
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per_page,
			'pages'    => $per_page > 0 ? (int) ceil( $total / $per_page ) : 0,
		] );
	}

	// =========================================================================
	// AJAX: LTV distribution (histogram bins for Chart.js)
	// =========================================================================

	public function ajax_ltv_distribution() {
		$this->check_auth();
		$payload = $this->cached( 'ltv_distribution', [], function () {
			global $wpdb;
			$tbl = $wpdb->prefix . 'brikpanel_customer_metrics';

			// Single round-trip for total/min/max instead of 3 separate queries.
			$bounds = $wpdb->get_row( "SELECT COUNT(*) AS total, MIN(total_spent) AS lo, MAX(total_spent) AS hi FROM {$tbl}" ); // phpcs:ignore
			$total  = (int) ( $bounds->total ?? 0 );
			$min    = (float) ( $bounds->lo ?? 0 );
			$max    = (float) ( $bounds->hi ?? 0 );
			if ( $total === 0 || $max <= 0 ) {
				return [ 'bins' => [], 'currency_symbol' => $this->currency_symbol() ];
			}

			$bin_count = min( 20, max( 5, (int) ceil( sqrt( $total ) ) ) );
			$bin_size  = ( $max - $min ) / $bin_count;
			if ( $bin_size <= 0 ) {
				$bin_size = max( 1, $max / $bin_count );
			}

			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT
					LEAST(%d, FLOOR((total_spent - %f) / %f)) AS bin_idx,
					COUNT(*) AS customers
				FROM {$tbl}
				GROUP BY bin_idx
				ORDER BY bin_idx ASC",
				$bin_count - 1,
				$min,
				$bin_size
			) ); // phpcs:ignore

			$by_idx = [];
			foreach ( $rows as $r ) {
				$by_idx[ (int) $r->bin_idx ] = (int) $r->customers;
			}
			$bins = [];
			for ( $i = 0; $i < $bin_count; $i++ ) {
				$lo = $min + ( $i * $bin_size );
				$hi = $lo + $bin_size;
				$bins[] = [
					'lo'         => $lo,
					'hi'         => $hi,
					'lo_display' => $this->price( $lo ),
					'hi_display' => $this->price( $hi ),
					'customers'  => $by_idx[ $i ] ?? 0,
				];
			}

			return [
				'bins'            => $bins,
				'currency_symbol' => $this->currency_symbol(),
			];
		} );
		wp_send_json_success( $payload );
	}

	private function currency_symbol() {
		return function_exists( 'get_woocommerce_currency_symbol' ) ? html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ) : '$';
	}

	/**
	 * Format a numeric amount as a plain-text price string with the
	 * decoded currency symbol. wc_price() returns HTML containing &#8378;
	 * style entities; we strip the markup and decode entities so the
	 * result renders correctly in JSON responses (which are escaped
	 * client-side without HTML entity awareness).
	 */
	private function price( $amount ) {
		return html_entity_decode( wp_strip_all_tags( wc_price( (float) $amount ) ), ENT_QUOTES, 'UTF-8' );
	}

	// =========================================================================
	// AJAX: CSV export (top customers by LTV)
	// =========================================================================

	public function ajax_ltv_export() {
		// GET-based export so the browser opens it in a new tab.
		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), self::NONCE_ACTION ) ) {
			wp_die( esc_html__( 'Invalid request.', 'brikpanel' ), 403 );
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'brikpanel' ), 403 );
		}

		global $wpdb;
		$tbl = $wpdb->prefix . 'brikpanel_customer_metrics';

		nocache_headers();
		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="customer-ltv-' . gmdate( 'Y-m-d' ) . '.csv"' );

		$out = fopen( 'php://output', 'w' );
		// UTF-8 BOM so Excel renders Turkish characters correctly.
		fwrite( $out, "\xEF\xBB\xBF" );

		fputcsv( $out, [
			__( 'User ID', 'brikpanel' ),
			__( 'Name', 'brikpanel' ),
			__( 'Email', 'brikpanel' ),
			__( 'Order count', 'brikpanel' ),
			__( 'Total spent (LTV)', 'brikpanel' ),
			__( 'Average order value', 'brikpanel' ),
			__( 'Recency (days)', 'brikpanel' ),
			__( 'First order', 'brikpanel' ),
			__( 'Last order', 'brikpanel' ),
		] );

		$batch_size = 1000;
		$offset = 0;
		do {
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT m.user_id, m.customer_email, m.order_count, m.total_spent, m.aov, m.recency_days,
					m.first_order_date, m.last_order_date,
					u.display_name,
					bm_fn.meta_value AS bf, bm_ln.meta_value AS bl
				FROM {$tbl} m
				LEFT JOIN {$wpdb->users} u ON m.user_id = u.ID AND m.user_id > 0
				LEFT JOIN {$wpdb->usermeta} bm_fn ON bm_fn.user_id = u.ID AND bm_fn.meta_key = 'billing_first_name'
				LEFT JOIN {$wpdb->usermeta} bm_ln ON bm_ln.user_id = u.ID AND bm_ln.meta_key = 'billing_last_name'
				ORDER BY m.total_spent DESC
				LIMIT %d OFFSET %d",
				$batch_size,
				$offset
			) ); // phpcs:ignore

			foreach ( $rows as $r ) {
				$name = trim( trim( (string) $r->bf . ' ' . (string) $r->bl ) );
				if ( $name === '' ) {
					$name = (string) $r->display_name;
				}
				fputcsv( $out, [
					(int) $r->user_id,
					$name,
					(string) $r->customer_email,
					(int) $r->order_count,
					number_format( (float) $r->total_spent, 2, '.', '' ),
					number_format( (float) $r->aov, 2, '.', '' ),
					$r->recency_days !== null ? (int) $r->recency_days : '',
					(string) $r->first_order_date,
					(string) $r->last_order_date,
				] );
			}
			$offset += $batch_size;
		} while ( count( $rows ) === $batch_size );

		fclose( $out );
		exit;
	}

	// =========================================================================
	// AJAX: RFM segment summary (counts, total LTV, avg LTV per segment)
	// =========================================================================

	public function ajax_rfm_summary() {
		$this->check_auth();
		$payload = $this->cached( 'rfm_summary', [], function () {
			global $wpdb;
			$tbl = $wpdb->prefix . 'brikpanel_customer_metrics';

			$rows = $wpdb->get_results( "SELECT
					rfm_segment,
					COUNT(*) AS customers,
					COALESCE(SUM(total_spent), 0) AS total_ltv,
					COALESCE(AVG(total_spent), 0) AS avg_ltv,
					COALESCE(AVG(order_count), 0) AS avg_orders,
					COALESCE(AVG(recency_days), 0) AS avg_recency
				FROM {$tbl}
				WHERE rfm_segment IS NOT NULL
				GROUP BY rfm_segment" ); // phpcs:ignore

			$labels          = brikpanel_ca_rfm_segment_labels();
			$total_customers = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tbl}" ); // phpcs:ignore

			$by_seg = [];
			foreach ( $rows as $r ) {
				$by_seg[ $r->rfm_segment ] = $r;
			}

			$segments = [];
			foreach ( $labels as $key => $meta ) {
				$row   = $by_seg[ $key ] ?? null;
				$count = $row ? (int) $row->customers : 0;
				$segments[] = [
					'key'               => $key,
					'label'             => $meta['label'],
					'description'       => $meta['description'],
					'color'             => $meta['color'],
					'customers'         => $count,
					'share'             => $total_customers > 0 ? round( $count / $total_customers * 100, 1 ) : 0,
					'total_ltv'         => $row ? (float) $row->total_ltv : 0,
					'total_ltv_display' => $this->price( $row ? (float) $row->total_ltv : 0 ),
					'avg_ltv'           => $row ? (float) $row->avg_ltv : 0,
					'avg_ltv_display'   => $this->price( $row ? (float) $row->avg_ltv : 0 ),
					'avg_orders'        => $row ? round( (float) $row->avg_orders, 1 ) : 0,
					'avg_recency'       => $row ? (int) round( (float) $row->avg_recency ) : 0,
				];
			}

			return [
				'segments'        => $segments,
				'total_customers' => $total_customers,
			];
		} );
		wp_send_json_success( $payload );
	}

	// =========================================================================
	// AJAX: Customers in a given RFM segment (paginated)
	// =========================================================================

	public function ajax_rfm_customers() {
		$this->check_auth();
		global $wpdb;
		$tbl = $wpdb->prefix . 'brikpanel_customer_metrics';

		$segment  = isset( $_POST['segment'] ) ? sanitize_key( $_POST['segment'] ) : '';
		$page     = max( 1, (int) ( $_POST['page'] ?? 1 ) );
		$per_page = min( 100, max( 5, (int) ( $_POST['per_page'] ?? self::PAGE_SIZE ) ) );
		$offset   = ( $page - 1 ) * $per_page;

		$valid_segments = array_keys( brikpanel_ca_rfm_segment_labels() );
		if ( ! in_array( $segment, $valid_segments, true ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid segment.', 'brikpanel' ) ], 400 );
		}

		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tbl} WHERE rfm_segment = %s", $segment ) ); // phpcs:ignore

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT m.customer_key, m.user_id, m.customer_email, m.first_order_date, m.last_order_date,
				m.order_count, m.total_spent, m.aov, m.recency_days,
				m.r_score, m.f_score, m.m_score,
				u.display_name,
				bm_fn.meta_value AS billing_first_name,
				bm_ln.meta_value AS billing_last_name
			FROM {$tbl} m
			LEFT JOIN {$wpdb->users} u ON m.user_id = u.ID AND m.user_id > 0
			LEFT JOIN {$wpdb->usermeta} bm_fn ON bm_fn.user_id = u.ID AND bm_fn.meta_key = 'billing_first_name'
			LEFT JOIN {$wpdb->usermeta} bm_ln ON bm_ln.user_id = u.ID AND bm_ln.meta_key = 'billing_last_name'
			WHERE m.rfm_segment = %s
			ORDER BY m.total_spent DESC
			LIMIT %d OFFSET %d",
			$segment,
			$per_page,
			$offset
		) ); // phpcs:ignore

		$items = [];
		foreach ( $rows as $r ) {
			$user_id = (int) $r->user_id;
			$name    = trim( trim( (string) $r->billing_first_name . ' ' . (string) $r->billing_last_name ) );
			if ( $name === '' ) {
				$name = (string) $r->display_name;
			}
			if ( $name === '' ) {
				$name = (string) $r->customer_email;
			}
			$items[] = [
				'user_id'             => $user_id,
				'name'                => $name,
				'email'               => (string) $r->customer_email,
				'order_count'         => (int) $r->order_count,
				'total_spent'         => (float) $r->total_spent,
				'total_spent_display' => $this->price( (float) $r->total_spent ),
				'aov'                 => (float) $r->aov,
				'aov_display'         => $this->price( (float) $r->aov ),
				'recency_days'        => $r->recency_days !== null ? (int) $r->recency_days : null,
				'r_score'             => (int) $r->r_score,
				'f_score'             => (int) $r->f_score,
				'm_score'             => (int) $r->m_score,
				'last_order'          => $r->last_order_date ? mysql2date( get_option( 'date_format' ), $r->last_order_date ) : '',
				'edit_url'            => $user_id ? admin_url( 'user-edit.php?user_id=' . $user_id ) : '',
				'is_guest'            => $user_id === 0,
			];
		}

		wp_send_json_success( [
			'items'    => $items,
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per_page,
			'pages'    => $per_page > 0 ? (int) ceil( $total / $per_page ) : 0,
		] );
	}

	// =========================================================================
	// AJAX: CSV export of customers in one (or all) RFM segments
	// =========================================================================

	public function ajax_rfm_export() {
		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), self::NONCE_ACTION ) ) {
			wp_die( esc_html__( 'Invalid request.', 'brikpanel' ), 403 );
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'brikpanel' ), 403 );
		}

		global $wpdb;
		$tbl = $wpdb->prefix . 'brikpanel_customer_metrics';

		$segment = isset( $_GET['segment'] ) ? sanitize_key( $_GET['segment'] ) : '';
		$valid   = array_keys( brikpanel_ca_rfm_segment_labels() );
		$where   = '';
		$params  = [];
		if ( in_array( $segment, $valid, true ) ) {
			$where = ' WHERE m.rfm_segment = %s';
			$params[] = $segment;
		} else {
			$where = ' WHERE m.rfm_segment IS NOT NULL';
			$segment = 'all';
		}

		nocache_headers();
		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="customer-rfm-' . $segment . '-' . gmdate( 'Y-m-d' ) . '.csv"' );

		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" );

		fputcsv( $out, [
			__( 'User ID', 'brikpanel' ),
			__( 'Name', 'brikpanel' ),
			__( 'Email', 'brikpanel' ),
			__( 'Segment', 'brikpanel' ),
			__( 'R', 'brikpanel' ),
			__( 'F', 'brikpanel' ),
			__( 'M', 'brikpanel' ),
			__( 'Order count', 'brikpanel' ),
			__( 'Total spent (LTV)', 'brikpanel' ),
			__( 'Recency (days)', 'brikpanel' ),
			__( 'Last order', 'brikpanel' ),
		] );

		$labels = brikpanel_ca_rfm_segment_labels();
		$batch_size = 1000;
		$offset = 0;
		do {
			$page_params = array_merge( $params, [ $batch_size, $offset ] );
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT m.user_id, m.customer_email, m.rfm_segment, m.r_score, m.f_score, m.m_score,
					m.order_count, m.total_spent, m.recency_days, m.last_order_date,
					u.display_name,
					bm_fn.meta_value AS bf, bm_ln.meta_value AS bl
				FROM {$tbl} m
				LEFT JOIN {$wpdb->users} u ON m.user_id = u.ID AND m.user_id > 0
				LEFT JOIN {$wpdb->usermeta} bm_fn ON bm_fn.user_id = u.ID AND bm_fn.meta_key = 'billing_first_name'
				LEFT JOIN {$wpdb->usermeta} bm_ln ON bm_ln.user_id = u.ID AND bm_ln.meta_key = 'billing_last_name'
				{$where}
				ORDER BY m.total_spent DESC
				LIMIT %d OFFSET %d",
				$page_params
			) ); // phpcs:ignore

			foreach ( $rows as $r ) {
				$name = trim( trim( (string) $r->bf . ' ' . (string) $r->bl ) );
				if ( $name === '' ) {
					$name = (string) $r->display_name;
				}
				$seg_label = isset( $labels[ $r->rfm_segment ] ) ? $labels[ $r->rfm_segment ]['label'] : $r->rfm_segment;
				fputcsv( $out, [
					(int) $r->user_id,
					$name,
					(string) $r->customer_email,
					$seg_label,
					(int) $r->r_score,
					(int) $r->f_score,
					(int) $r->m_score,
					(int) $r->order_count,
					number_format( (float) $r->total_spent, 2, '.', '' ),
					$r->recency_days !== null ? (int) $r->recency_days : '',
					(string) $r->last_order_date,
				] );
			}
			$offset += $batch_size;
		} while ( count( $rows ) === $batch_size );

		fclose( $out );
		exit;
	}

	// =========================================================================
	// AJAX: Cohort retention matrix
	// =========================================================================

	public function ajax_cohort_matrix() {
		$this->check_auth();
		global $wpdb;
		$tbl = $wpdb->prefix . 'brikpanel_cohort_retention';

		$months = isset( $_POST['months'] ) ? max( 3, min( 24, (int) $_POST['months'] ) ) : 12;
		$cutoff = gmdate( 'Y-m-01', strtotime( "-{$months} months" ) );

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT cohort_month, period_offset, cohort_size, retained_customers, retention_rate
			FROM {$tbl}
			WHERE cohort_month >= %s
			ORDER BY cohort_month ASC, period_offset ASC",
			$cutoff
		) ); // phpcs:ignore

		// Group by cohort_month, expose cohort_size + an array of {offset, rate, customers}.
		$cohorts = [];
		$max_offset = 0;
		foreach ( $rows as $r ) {
			$key = (string) $r->cohort_month;
			if ( ! isset( $cohorts[ $key ] ) ) {
				$cohorts[ $key ] = [
					'cohort_month'      => $key,
					'cohort_month_label' => date_i18n( 'M Y', strtotime( $key ) ),
					'cohort_size'       => (int) $r->cohort_size,
					'cells'             => [],
				];
			}
			$cohorts[ $key ]['cells'][ (int) $r->period_offset ] = [
				'rate'      => (float) $r->retention_rate,
				'customers' => (int) $r->retained_customers,
			];
			$max_offset = max( $max_offset, (int) $r->period_offset );
		}

		// Compute "average retention by period_offset" for the secondary line chart.
		$avg_by_offset = [];
		for ( $i = 0; $i <= $max_offset; $i++ ) {
			$rates = [];
			foreach ( $cohorts as $c ) {
				if ( isset( $c['cells'][ $i ] ) ) {
					$rates[] = $c['cells'][ $i ]['rate'];
				}
			}
			$avg_by_offset[] = [
				'offset' => $i,
				'avg'    => $rates ? round( array_sum( $rates ) / count( $rates ), 2 ) : 0,
				'cohorts_with_data' => count( $rates ),
			];
		}

		$last_computed = $wpdb->get_var( "SELECT MAX(computed_at) FROM {$tbl}" ); // phpcs:ignore

		wp_send_json_success( [
			'cohorts'        => array_values( $cohorts ),
			'max_offset'     => $max_offset,
			'avg_by_offset'  => $avg_by_offset,
			'last_computed'  => $last_computed ? mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $last_computed ) : '',
			'months_window'  => $months,
		] );
	}

	// =========================================================================
	// AJAX: Cohort matrix CSV export
	// =========================================================================

	public function ajax_cohort_export() {
		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), self::NONCE_ACTION ) ) {
			wp_die( esc_html__( 'Invalid request.', 'brikpanel' ), 403 );
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'brikpanel' ), 403 );
		}

		global $wpdb;
		$tbl = $wpdb->prefix . 'brikpanel_cohort_retention';

		nocache_headers();
		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="cohort-retention-' . gmdate( 'Y-m-d' ) . '.csv"' );

		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" );

		fputcsv( $out, [
			__( 'Cohort month', 'brikpanel' ),
			__( 'Period offset (months)', 'brikpanel' ),
			__( 'Cohort size', 'brikpanel' ),
			__( 'Retained customers', 'brikpanel' ),
			__( 'Retention rate (%)', 'brikpanel' ),
		] );

		$rows = $wpdb->get_results( "SELECT cohort_month, period_offset, cohort_size, retained_customers, retention_rate FROM {$tbl} ORDER BY cohort_month ASC, period_offset ASC" ); // phpcs:ignore
		foreach ( $rows as $r ) {
			fputcsv( $out, [
				(string) $r->cohort_month,
				(int) $r->period_offset,
				(int) $r->cohort_size,
				(int) $r->retained_customers,
				number_format( (float) $r->retention_rate, 2, '.', '' ),
			] );
		}
		fclose( $out );
		exit;
	}

	// =========================================================================
	// AJAX: Run recompute now (for "Refresh" button on the page)
	// =========================================================================

	public function ajax_recompute_now() {
		$this->check_auth();
		if ( ! class_exists( 'Brikpanel_Cron' ) || ! Brikpanel_Cron::is_available() ) {
			wp_send_json_error( [ 'message' => __( 'Action Scheduler is not available.', 'brikpanel' ) ], 500 );
		}
		// Run both handlers synchronously so the user gets immediate feedback.
		// On large stores this could be slow; the regular cron handles the
		// heavy lifting nightly.
		try {
			$ltv = brikpanel_recompute_customer_metrics_handler();
			$cohort = brikpanel_recompute_cohort_retention_handler();
			self::bust_cache();
			wp_send_json_success( [
				'message'      => __( 'Customer metrics recomputed.', 'brikpanel' ),
				'rows_written' => (int) ( $ltv['rows_written'] ?? 0 ),
				'cohort_rows'  => (int) ( $cohort['rows_written'] ?? 0 ),
				'duration'     => round( ( $ltv['duration'] ?? 0 ) + ( $cohort['duration'] ?? 0 ), 3 ),
			] );
		} catch ( \Throwable $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ], 500 );
		}
	}
}

new Brikpanel_Customer_Analytics();
