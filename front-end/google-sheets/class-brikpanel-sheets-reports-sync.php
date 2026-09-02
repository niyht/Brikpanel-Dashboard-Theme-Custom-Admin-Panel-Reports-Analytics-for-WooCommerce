<?php
/**
 * BrikPanel — Sheets reports snapshot sync.
 *
 * Pushes four "as-of-last-sync" report tabs to the target spreadsheet:
 *
 *   • BrikPanel - Sales Summary  — single row, multi-window aggregates
 *   • BrikPanel - Daily KPIs     — last 90 days, one row per day
 *   • BrikPanel - Top Products   — top 50 by units sold (last 90d)
 *   • BrikPanel - Funnel         — daily visitor/cart/checkout/order
 *
 * Snapshot semantics: each push CLEARS the tab and rewrites it (header
 * + freshly computed rows). This keeps the sheet "what the dashboard
 * shows right now" rather than an append log with ambiguous time windows.
 *
 * @package BrikPanel
 * @since   2.9.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Brikpanel_Sheets_Reports_Sync {

	const HOOK = 'brikpanel_gs_reports_snapshot';

	const OPT_ENABLED  = 'brikpanel_gs_reports_enabled';
	const OPT_INTERVAL = 'brikpanel_gs_reports_interval'; // hourly|every_6h|daily
	const OPT_LAST     = 'brikpanel_gs_reports_last_sync';

	const TAB_SUMMARY = 'BrikPanel - Sales Summary';
	const TAB_KPIS    = 'BrikPanel - Daily KPIs';
	const TAB_TOP     = 'BrikPanel - Top Products';
	const TAB_FUNNEL  = 'BrikPanel - Funnel';
	const TAB_PROFIT  = 'BrikPanel - Profit';

	public function __construct() {
		add_action( 'brikpanel_cron_register', [ $this, 'register' ] );
	}

	public static function is_enabled() {
		return get_option( self::OPT_ENABLED, 'no' ) === 'yes';
	}

	public static function interval_seconds() {
		switch ( (string) get_option( self::OPT_INTERVAL, 'every_6h' ) ) {
			case 'hourly':   return HOUR_IN_SECONDS;
			case 'daily':    return DAY_IN_SECONDS;
			case 'every_6h':
			default:         return 6 * HOUR_IN_SECONDS;
		}
	}

	public function register() {
		Brikpanel_Cron::register_handler(
			self::HOOK,
			[ $this, 'handle' ],
			static function () { return [ 'label' => __( 'Sheets — reports snapshot', 'brikpanel' ) ]; }
		);
		if ( self::is_enabled() ) {
			Brikpanel_Cron::schedule_recurring( self::HOOK, self::interval_seconds(), [] );
		} else {
			Brikpanel_Cron::cancel( self::HOOK );
		}
	}

	public static function trigger_manual() {
		Brikpanel_Cron::enqueue_async( self::HOOK, [], [ 'unique' => true ] );
	}

	/**
	 * Pull in the analytics helper files. Idempotent — each file is gated by
	 * function_exists so re-loading is free. Necessary because Action Scheduler
	 * workers run in a non-admin request context where brikpanel_init_admin()
	 * (which normally requires these) bails early.
	 */
	private static function ensure_helpers_loaded() {
		$files = [
			'back-end/total-sales/brikpanel-total-sales.php'             => 'brikpanel_get_total_revenue',
			'back-end/conversion-count/brikpanel-total-orders.php'        => 'brikpanel_get_order_count',
			'back-end/order-value/brikpanel-order-value.php'              => 'brikpanel_get_average_order_value',
			'back-end/order-rates/brikpanel-order-rates.php'              => 'brikpanel_get_successful_order_count',
			'back-end/conversion-count/brikpanel-conversion-count.php'    => 'brikpanel_get_visitor_count',
		];
		foreach ( $files as $rel => $fn ) {
			if ( ! function_exists( $fn ) ) {
				$path = BRIKPANEL_PATH . $rel;
				if ( file_exists( $path ) ) {
					require_once $path;
				}
			}
		}
	}

	// =========================================================================
	// Handler
	// =========================================================================

	public function handle( $args = [] ) {
		if ( ! self::is_enabled() || ! Brikpanel_Sheets_Tokens::is_connected() ) {
			return [ 'tabs' => 0, 'rows' => 0 ];
		}
		$target = Brikpanel_Sheets_Order_Sync::resolve_active_target();
		if ( ! $target ) {
			Brikpanel_Sheets_Logger::log( 'reports', 'No active spreadsheet configured; skipping reports sync.' );
			return [ 'tabs' => 0, 'rows' => 0 ];
		}

		// AS workers don't run brikpanel_init_admin / brikpanel_init_other, so
		// the analytics helper files aren't loaded by default. Ensure they are
		// available before we call them.
		self::ensure_helpers_loaded();
		$client = new Brikpanel_Sheets_Client();
		$sid    = $target['spreadsheet_id'];

		$total_rows = 0;
		foreach ( [
			[ self::TAB_SUMMARY, $this->build_sales_summary() ],
			[ self::TAB_KPIS,    $this->build_daily_kpis( 90 ) ],
			[ self::TAB_TOP,     $this->build_top_products( 50, 90 ) ],
			[ self::TAB_FUNNEL,  $this->build_funnel( 90 ) ],
			[ self::TAB_PROFIT,  $this->build_profit() ],
		] as $pair ) {
			$this->snapshot_tab( $client, $sid, $pair[0], $pair[1] );
			$total_rows += count( (array) ( $pair[1]['rows'] ?? [] ) );
		}

		update_option( self::OPT_LAST, [ 'ts' => time(), 'rows' => $total_rows ], false );
		return [ 'tabs' => 5, 'rows' => $total_rows ];
	}

	/**
	 * Replace the contents of one tab: clear + ensure-tab + bulk update.
	 *
	 * @param Brikpanel_Sheets_Client $client
	 * @param string                  $sid
	 * @param string                  $tab
	 * @param array{header:string[], rows:array<int, array<int, scalar>>} $data
	 */
	private function snapshot_tab( $client, $sid, $tab, $data ) {
		$header = (array) ( $data['header'] ?? [] );
		$rows   = (array) ( $data['rows'] ?? [] );
		try {
			$client->ensure_tab( $sid, $tab, $header );
			// Clear everything, then write header+rows.
			$client->values_clear( $sid, Brikpanel_Sheets_Client::a1_quote_tab( $tab ) );
			$payload = empty( $rows ) ? [ $header ] : array_merge( [ $header ], $rows );
			$client->values_update( $sid, Brikpanel_Sheets_Client::a1_quote_tab( $tab ) . '!A1', $payload, 'RAW' );
		} catch ( Brikpanel_Sheets_Exception $e ) {
			Brikpanel_Sheets_Logger::log( 'reports', $tab . ': ' . $e->getMessage(), $e->http_code );
			throw $e;
		}
	}

	// =========================================================================
	// Tab builders
	// =========================================================================

	private function build_sales_summary() {
		$windows = [
			'7d'  => 7 * DAY_IN_SECONDS,
			'30d' => 30 * DAY_IN_SECONDS,
			'90d' => 90 * DAY_IN_SECONDS,
			'all' => null,
		];
		$header = [
			__( 'Window',           'brikpanel' ),
			__( 'Revenue',          'brikpanel' ),
			__( 'Orders',           'brikpanel' ),
			__( 'AOV',              'brikpanel' ),
			__( 'Successful orders','brikpanel' ),
			__( 'Conversion rate %','brikpanel' ),
		];
		$rows = [];
		foreach ( $windows as $label => $seconds ) {
			$end_gmt   = gmdate( 'Y-m-d H:i:s' );
			$start_gmt = $seconds === null ? null : gmdate( 'Y-m-d H:i:s', time() - $seconds );

			$revenue       = function_exists( 'brikpanel_get_total_revenue' ) ? (float) brikpanel_get_total_revenue( $start_gmt, $end_gmt ) : 0.0;
			$order_count   = function_exists( 'brikpanel_get_order_count' )   ? (int)   brikpanel_get_order_count( $start_gmt, $end_gmt )   : 0;
			$aov           = function_exists( 'brikpanel_get_average_order_value' ) ? (float) brikpanel_get_average_order_value( $start_gmt, $end_gmt ) : 0.0;
			$successful    = function_exists( 'brikpanel_get_successful_order_count' ) ? (int) brikpanel_get_successful_order_count( $start_gmt, $end_gmt ) : 0;

			// Visitors / conversion: use the visitor table which is dated, not gmt-timestamped.
			$visitors = 0;
			if ( function_exists( 'brikpanel_get_visitor_count' ) ) {
				if ( $seconds === null ) {
					$visitors = (int) brikpanel_get_visitor_count();
				} else {
					$start_date = gmdate( 'Y-m-d', time() - $seconds );
					$end_date   = gmdate( 'Y-m-d' );
					$visitors   = (int) brikpanel_get_visitor_count( $start_date, $end_date );
				}
			}
			$conversion = $visitors > 0 ? round( $order_count / $visitors * 100, 2 ) : 0;

			$rows[] = [
				$label === 'all' ? __( 'All time', 'brikpanel' ) : $label,
				$revenue,
				$order_count,
				round( $aov, 4 ),
				$successful,
				$conversion,
			];
		}

		return [ 'header' => $header, 'rows' => $rows ];
	}

	/**
	 * Profit snapshot per rolling window. Uses the shared profit helper so
	 * the sheet always matches the dashboard's Profit section exactly.
	 */
	private function build_profit() {
		$header = [
			__( 'Window',          'brikpanel' ),
			__( 'Revenue',         'brikpanel' ),
			__( 'Cost of goods',   'brikpanel' ),
			__( 'COGS %',          'brikpanel' ),
			__( 'Ad spend',        'brikpanel' ),
			__( 'Tax',             'brikpanel' ),
			// Position must match the row array below exactly; the two are
			// mirrored positionally, so inserting here without inserting there
			// silently shifts every column to the right.
			__( 'Payment fees',    'brikpanel' ),
			__( 'Shipping cost',   'brikpanel' ),
			// Without this column the components stop summing to Total expenses
			// for any store using per-order costs, the same reason Shipping cost
			// was added here. This tab is cleared and rewritten in full on every
			// sync (write_tab), so a new column needs no migration.
			__( 'Per-order costs', 'brikpanel' ),
			__( 'Supplier / stock',  'brikpanel' ),
			__( 'Other expenses',  'brikpanel' ),
			__( 'Total expenses',  'brikpanel' ),
			__( 'Expenses %',      'brikpanel' ),
			__( 'Net profit',      'brikpanel' ),
			__( 'Net margin %',    'brikpanel' ),
		];

		if ( ! function_exists( 'brikpanel_profit_snapshot' ) || ! function_exists( 'brikpanel_get_total_revenue' ) ) {
			return [ 'header' => $header, 'rows' => [] ];
		}

		$windows = [
			'7d'  => 7 * DAY_IN_SECONDS,
			'30d' => 30 * DAY_IN_SECONDS,
			'90d' => 90 * DAY_IN_SECONDS,
			'all' => null,
		];
		$rows = [];
		foreach ( $windows as $label => $seconds ) {
			$end_gmt     = gmdate( 'Y-m-d H:i:s' );
			$end_local   = wp_date( 'Y-m-d H:i:s' );
			if ( $seconds === null ) {
				$start_gmt   = '1970-01-01 00:00:00';
				$start_local = '1970-01-01 00:00:00';
				$rev_start   = null; // brikpanel_get_total_revenue: null = all time
			} else {
				$start_gmt   = gmdate( 'Y-m-d H:i:s', time() - $seconds );
				$start_local = wp_date( 'Y-m-d H:i:s', time() - $seconds );
				$rev_start   = $start_gmt;
			}

			// Combined basis (site + marketplace), matching the dashboard's
			// headline Revenue / Net profit. Marketplace turnover belongs in the
			// money figures, and Cost of goods is computed on the SAME set
			// (exclude_marketplace = false) so revenue and cost reconcile and the
			// margin is correct. Single-channel stores are unaffected.
			$revenue = (float) brikpanel_get_total_revenue( $rev_start, $end_gmt, false );
			$s       = brikpanel_profit_snapshot( $revenue, $start_gmt, $end_gmt, $start_local, $end_local, false );

			$rows[] = [
				$label === 'all' ? __( 'All time', 'brikpanel' ) : $label,
				round( $s['revenue_raw'], 2 ),
				round( $s['cogs_raw'], 2 ),
				$s['cogs_pct'],
				round( $s['ad_spend_raw'], 2 ),
				round( $s['tax_raw'], 2 ),
				round( (float) ( $s['payment_fees_raw'] ?? 0 ), 2 ),
				round( (float) ( $s['shipping_cost_raw'] ?? 0 ), 2 ),
				round( (float) ( $s['per_order_total_raw'] ?? 0 ), 2 ),
				round( $s['exp_inventory_raw'], 2 ),
				round( $s['exp_other_raw'], 2 ),
				round( $s['expenses_total_raw'], 2 ),
				$s['expenses_pct'],
				round( $s['net_raw'], 2 ),
				$s['margin'],
			];
		}

		return [ 'header' => $header, 'rows' => $rows ];
	}

	private function build_daily_kpis( $days ) {
		$header = [
			__( 'Date',            'brikpanel' ),
			__( 'Revenue',         'brikpanel' ),
			__( 'Orders',          'brikpanel' ),
			__( 'AOV',             'brikpanel' ),
			__( 'Visitors',        'brikpanel' ),
			__( 'Conversion %',    'brikpanel' ),
		];

		global $wpdb;
		$rows = [];
		$visitors_tbl = $wpdb->prefix . 'brikpanel_visitors';
		$is_hpos = get_option( 'woocommerce_custom_orders_table_enabled' ) === 'yes';
		// Match the dashboard's revenue definition (Total Sales / Order Value /
		// Orders count all use processing+completed). Anything else would make
		// the Sheets snapshot disagree with what the user sees in BrikPanel.
		$statuses = brikpanel_paid_order_statuses();
		$status_in = "'" . implode( "','", array_map( 'esc_sql', $statuses ) ) . "'";

		for ( $i = $days - 1; $i >= 0; $i-- ) {
			$date     = gmdate( 'Y-m-d', time() - ( $i * DAY_IN_SECONDS ) );
			$day_lo   = $date . ' 00:00:00';
			$day_hi   = $date . ' 23:59:59';

			if ( $is_hpos ) {
				$sql = $wpdb->prepare(
					"SELECT COALESCE(SUM(total_amount),0) AS rev, COUNT(*) AS cnt
					 FROM {$wpdb->prefix}wc_orders
					 WHERE type='shop_order' AND status IN ({$status_in})
					   AND date_created_gmt BETWEEN %s AND %s",
					$day_lo, $day_hi
				);
			} else {
				$sql = $wpdb->prepare(
					"SELECT COALESCE(SUM(meta_total.meta_value+0),0) AS rev, COUNT(*) AS cnt
					 FROM {$wpdb->posts} p
					 LEFT JOIN {$wpdb->postmeta} meta_total ON meta_total.post_id = p.ID AND meta_total.meta_key='_order_total'
					 WHERE p.post_type='shop_order' AND p.post_status IN ({$status_in})
					   AND p.post_date_gmt BETWEEN %s AND %s",
					$day_lo, $day_hi
				);
			}
			$r = $wpdb->get_row( $sql ); // phpcs:ignore
			$rev = $r ? (float) $r->rev : 0.0;
			$cnt = $r ? (int)   $r->cnt : 0;

			$vis = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COALESCE(SUM(visitor_count),0) FROM {$visitors_tbl} WHERE date_column = %s",
				$date
			) );
			$aov  = $cnt > 0 ? round( $rev / $cnt, 4 ) : 0;
			$conv = $vis > 0 ? round( $cnt / $vis * 100, 2 ) : 0;

			$rows[] = [ $date, $rev, $cnt, $aov, $vis, $conv ];
		}

		return [ 'header' => $header, 'rows' => $rows ];
	}

	private function build_top_products( $limit, $days ) {
		$header = [
			__( 'Product ID',    'brikpanel' ),
			__( 'Product name',  'brikpanel' ),
			__( 'SKU',           'brikpanel' ),
			__( 'Units sold',    'brikpanel' ),
			__( 'Revenue',       'brikpanel' ),
		];

		global $wpdb;
		$is_hpos = get_option( 'woocommerce_custom_orders_table_enabled' ) === 'yes';
		$since_gmt = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
		// Keep status filter aligned with the dashboard (processing+completed
		// only) so Top Products units/revenue match what BrikPanel displays.
		$statuses = brikpanel_paid_order_statuses();
		$status_in = "'" . implode( "','", array_map( 'esc_sql', $statuses ) ) . "'";

		if ( $is_hpos ) {
			$sql = $wpdb->prepare(
				"SELECT oim_pid.meta_value AS product_id,
						SUM(oim_qty.meta_value+0) AS units,
						SUM(oim_total.meta_value+0) AS revenue
				 FROM {$wpdb->prefix}woocommerce_order_items oi
				 INNER JOIN {$wpdb->prefix}wc_orders o ON o.id = oi.order_id
				 INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim_pid
				   ON oim_pid.order_item_id = oi.order_item_id AND oim_pid.meta_key='_product_id'
				 LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim_qty
				   ON oim_qty.order_item_id = oi.order_item_id AND oim_qty.meta_key='_qty'
				 LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim_total
				   ON oim_total.order_item_id = oi.order_item_id AND oim_total.meta_key='_line_total'
				 WHERE o.type='shop_order' AND o.status IN ({$status_in})
				   AND o.date_created_gmt >= %s
				   AND oi.order_item_type='line_item'
				 GROUP BY oim_pid.meta_value
				 ORDER BY units DESC
				 LIMIT %d",
				$since_gmt, $limit
			);
		} else {
			$sql = $wpdb->prepare(
				"SELECT oim_pid.meta_value AS product_id,
						SUM(oim_qty.meta_value+0) AS units,
						SUM(oim_total.meta_value+0) AS revenue
				 FROM {$wpdb->prefix}woocommerce_order_items oi
				 INNER JOIN {$wpdb->posts} p ON p.ID = oi.order_id
				 INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim_pid
				   ON oim_pid.order_item_id = oi.order_item_id AND oim_pid.meta_key='_product_id'
				 LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim_qty
				   ON oim_qty.order_item_id = oi.order_item_id AND oim_qty.meta_key='_qty'
				 LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim_total
				   ON oim_total.order_item_id = oi.order_item_id AND oim_total.meta_key='_line_total'
				 WHERE p.post_type='shop_order' AND p.post_status IN ({$status_in})
				   AND p.post_date_gmt >= %s
				   AND oi.order_item_type='line_item'
				 GROUP BY oim_pid.meta_value
				 ORDER BY units DESC
				 LIMIT %d",
				$since_gmt, $limit
			);
		}
		$rows_raw = $wpdb->get_results( $sql ); // phpcs:ignore

		$rows = [];
		foreach ( (array) $rows_raw as $r ) {
			$pid = (int) $r->product_id;
			$product = $pid ? wc_get_product( $pid ) : null;
			$rows[] = [
				$pid,
				$product ? wp_strip_all_tags( $product->get_name() ) : '',
				$product ? (string) $product->get_sku() : '',
				(float) $r->units,
				round( (float) $r->revenue, 4 ),
			];
		}
		return [ 'header' => $header, 'rows' => $rows ];
	}

	private function build_funnel( $days ) {
		$header = [
			__( 'Date',                'brikpanel' ),
			__( 'Visitors',            'brikpanel' ),
			__( 'Product views',       'brikpanel' ),
			__( 'Add to cart',         'brikpanel' ),
			__( 'Checkout started',    'brikpanel' ),
		];

		global $wpdb;
		$tbl = $wpdb->prefix . 'brikpanel_visitors';
		$since_date = gmdate( 'Y-m-d', time() - ( $days * DAY_IN_SECONDS ) );

		$rows_raw = $wpdb->get_results( $wpdb->prepare(
			"SELECT date_column, visitor_count, product_count, add_to_cart_count, checkout_count
			 FROM {$tbl}
			 WHERE date_column >= %s
			 ORDER BY date_column ASC",
			$since_date
		) ); // phpcs:ignore

		$rows = [];
		foreach ( (array) $rows_raw as $r ) {
			$rows[] = [
				(string) $r->date_column,
				(int) $r->visitor_count,
				(int) $r->product_count,
				(int) $r->add_to_cart_count,
				(int) $r->checkout_count,
			];
		}
		return [ 'header' => $header, 'rows' => $rows ];
	}
}
