<?php
/**
 * BrikPanel — Sheets order sync (real-time + bulk + row builder).
 *
 * Flow 1 (real-time): WC order hooks enqueue an Action Scheduler job that
 * defers the actual Sheets write to a worker tick. The handler queries for
 * unsynced orders (`_brikpanel_gs_synced_at` meta absent), batches up to
 * BATCH_SIZE of them into one `values.append` call, records the returned
 * row indices into order meta for later updates, and re-enqueues itself if
 * more rows remain. This keeps checkout TTFB unaffected and coalesces
 * traffic bursts into one API call.
 *
 * Flow 2 (bulk): the recurring `brikpanel_gs_order_bulk_export` action and
 * the manual "Sync now" button both invoke the same handler with an
 * explicit date-range filter and a higher limit.
 *
 * Status-change updates: when an already-synced order changes status, we
 * call `values_update` on the stored row range instead of appending a new
 * row — preserving idempotency.
 *
 * Variation handling: each WC order line item produces its own row. For
 * variable products, the variation_attributes column is a comma-joined
 * "attr: value" string so the user can split it in Sheets if they want.
 *
 * @package BrikPanel
 * @since   2.9.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Brikpanel_Sheets_Order_Sync {

	const HOOK_REALTIME_FLUSH = 'brikpanel_gs_order_realtime_flush';
	const HOOK_BULK_FLUSH     = 'brikpanel_gs_order_bulk_export';
	const HOOK_UPDATE_ROWS    = 'brikpanel_gs_order_update_rows';
	const HOOK_PULL           = 'brikpanel_gs_order_pull';

	const META_SYNCED_AT      = '_brikpanel_gs_synced_at';
	const META_ROW_MAP        = '_brikpanel_gs_row_map'; // map: line_item_id => sheet row number
	const META_SPREADSHEET    = '_brikpanel_gs_spreadsheet_id';
	const META_TAB            = '_brikpanel_gs_tab';
	// Status we last pushed to Sheets — used by the reverse pull to detect
	// whether a divergence between the sheet and Woo originated in the sheet
	// (sheet != last_pushed) or is just a stale row awaiting our update_rows
	// catch-up (sheet == last_pushed but != woo).
	const META_LAST_PUSHED_STATUS = '_brikpanel_gs_last_pushed_status';

	/**
	 * Row layout for the Orders tab: 'order' = one row per order (line items
	 * summarised into a single cell), 'line_item' = one row per line item
	 * (the original layout, kept for merchants doing item-level analysis).
	 */
	const OPT_ROW_LAYOUT = 'brikpanel_gs_orders_row_layout';

	/**
	 * Set on an order whose rows could not be built. The export query skips
	 * these so a single unmappable order cannot be re-fetched on every pass and
	 * wedge the run in a loop. "Reset & re-push everything" clears it, so a
	 * fixed order gets another chance.
	 */
	const META_SKIPPED = '_brikpanel_gs_skipped';

	const OPT_ENABLED         = 'brikpanel_gs_orders_enabled';
	const OPT_REALTIME        = 'brikpanel_gs_orders_realtime';
	const OPT_TAB_NAME        = 'brikpanel_gs_orders_tab';
	const OPT_BULK_INTERVAL   = 'brikpanel_gs_orders_bulk_interval'; // off|hourly|every_4h|daily
	const OPT_BULK_SINCE      = 'brikpanel_gs_orders_bulk_since';
	const OPT_BULK_STATUSES   = 'brikpanel_gs_orders_bulk_statuses';
	const OPT_SHIPPING_METHODS = 'brikpanel_gs_orders_shipping_methods'; // string[] method ids; [] = no filter
	const OPT_LAST_SYNC       = 'brikpanel_gs_orders_last_sync';

	// Two-way pull (Sheets → Woo). Only status is writable; it carries
	// 'writable' => true in the mapping catalogue so the sheet header gets the
	// ⇅ glyph and the column mapper shows the two-way badge. See class docblock
	// in class-brikpanel-sheets-products-sync.php for why we limit writable
	// fields to one per entity instead of opening price/customer/etc.
	const OPT_PULL_ENABLED    = 'brikpanel_gs_orders_pull_enabled';
	const OPT_PULL_INTERVAL   = 'brikpanel_gs_orders_pull_interval'; // 2|5|15 (minutes)
	const OPT_LAST_PULL       = 'brikpanel_gs_orders_last_pull';

	const BATCH_SIZE          = 250;

	/**
	 * Orders per pass when a human is waiting on the response. Small enough
	 * that one pass stays a few seconds even on a slow host, large enough that
	 * a long export does not need so many passes that its one append call per
	 * pass runs into Google's per-minute write quota. The interactive handler
	 * runs as many passes as its time budget allows.
	 */
	const INTERACTIVE_BATCH_SIZE = 150;
	const PULL_LOCK           = 'brikpanel_gs_orders_pull_lock';
	const PULL_LOCK_TTL       = 300;

	public function __construct() {
		// WC hooks — only attach when sync is enabled + connected.
		add_action( 'init', [ $this, 'maybe_attach_hooks' ], 30 );

		// Action Scheduler handler registration.
		add_action( 'brikpanel_cron_register', [ $this, 'register_handlers' ] );
	}

	// =========================================================================
	// Configuration helpers
	// =========================================================================

	public static function is_enabled() {
		return get_option( self::OPT_ENABLED, 'no' ) === 'yes';
	}

	public static function realtime_enabled() {
		return get_option( self::OPT_REALTIME, 'yes' ) === 'yes';
	}

	public static function tab_name() {
		$name = (string) get_option( self::OPT_TAB_NAME, 'Orders' );
		return $name !== '' ? $name : 'Orders';
	}

	public static function bulk_interval_seconds() {
		switch ( (string) get_option( self::OPT_BULK_INTERVAL, 'off' ) ) {
			case 'hourly':   return HOUR_IN_SECONDS;
			case 'every_4h': return 4 * HOUR_IN_SECONDS;
			case 'daily':    return DAY_IN_SECONDS;
		}
		return 0;
	}

	public static function bulk_since_timestamp() {
		$raw = (string) get_option( self::OPT_BULK_SINCE, '' );
		if ( $raw === '' ) {
			return (int) strtotime( '-90 days' );
		}
		$ts = strtotime( $raw . ' 00:00:00 UTC' );
		return $ts ? (int) $ts : (int) strtotime( '-90 days' );
	}

	/**
	 * Resolve the Orders row layout with grandfathering.
	 *
	 * Fresh installs get 'order' (one row per order — what a merchant naturally
	 * expects an "Orders" tab to be). Installs that were already syncing before
	 * this option existed get 'line_item', because their sheet is already built
	 * that way and silently switching would corrupt it with mixed layouts. The
	 * resolved value is persisted so the (cheap) grandfathering probe runs at
	 * most once.
	 *
	 * @return string 'order' | 'line_item'
	 */
	public static function row_layout() {
		$saved = (string) get_option( self::OPT_ROW_LAYOUT, '' );
		if ( 'order' === $saved || 'line_item' === $saved ) {
			return $saved;
		}

		global $wpdb;
		$is_hpos = get_option( 'woocommerce_custom_orders_table_enabled' ) === 'yes';
		$table   = $is_hpos ? $wpdb->prefix . 'wc_orders_meta' : $wpdb->postmeta;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from fixed pair above.
		$has_synced = (bool) $wpdb->get_var( $wpdb->prepare(
			"SELECT 1 FROM {$table} WHERE meta_key = %s LIMIT 1",
			self::META_SYNCED_AT
		) );

		$resolved = $has_synced ? 'line_item' : 'order';
		update_option( self::OPT_ROW_LAYOUT, $resolved, false );
		return $resolved;
	}

	public static function bulk_statuses() {
		$opt = get_option( self::OPT_BULK_STATUSES, [ 'wc-processing', 'wc-completed' ] );
		if ( ! is_array( $opt ) || empty( $opt ) ) {
			$opt = [ 'wc-processing', 'wc-completed' ];
		}
		return array_values( array_unique( array_map( 'sanitize_key', $opt ) ) );
	}

	/**
	 * Shipping-method ids the merchant chose to restrict the export to. Empty
	 * array (the default) means "export orders regardless of shipping method".
	 * Stored as the base WooCommerce method ids (e.g. flat_rate, local_pickup),
	 * which is what each shipping line item reports via get_method_id().
	 *
	 * @return string[]
	 */
	public static function shipping_method_filter() {
		$ids = get_option( self::OPT_SHIPPING_METHODS, [] );
		if ( ! is_array( $ids ) ) {
			return [];
		}
		return array_values( array_unique( array_filter( array_map( 'sanitize_text_field', $ids ) ) ) );
	}

	/**
	 * How many orders the current settings would export, with and without the
	 * shipping-method filter applied.
	 *
	 * Mirrors the flush() query on purpose (same statuses, same date floor, same
	 * post__in resolution) minus the "not yet synced" meta clause, because the
	 * question this answers is "how big is the export scope", not "how much is
	 * still pending". Used by the settings screen to warn before a merchant
	 * syncs: ticking every shipping box is NOT the same as ticking none, since
	 * orders with no shipping line at all (virtual/downloadable, most imports)
	 * carry no method id and are excluded the moment the filter is non-empty.
	 *
	 * @param string[] $statuses      wc- prefixed status keys.
	 * @param int      $since_ts      Unix timestamp floor for date_created.
	 * @param string[] $ship_methods  Shipping method ids; [] = no filter.
	 * @return array{matched:int, total:int}
	 */
	public static function count_export_scope( array $statuses, $since_ts, array $ship_methods ) {
		$base = [
			'limit'        => 1,
			'paginate'     => true,
			'return'       => 'ids',
			'type'         => 'shop_order',
			'status'       => $statuses ? $statuses : self::bulk_statuses(),
			'date_created' => '>=' . (int) $since_ts,
		];

		$total_q = wc_get_orders( $base );
		$total   = isset( $total_q->total ) ? (int) $total_q->total : 0;

		$ship_methods = array_values( array_filter( array_map( 'sanitize_text_field', $ship_methods ) ) );
		if ( empty( $ship_methods ) ) {
			// No filter: scope is the full status/date window.
			return [ 'matched' => $total, 'total' => $total ];
		}

		$match_ids = self::order_ids_for_shipping_methods( $ship_methods );
		if ( empty( $match_ids ) ) {
			// Same short-circuit as flush(): an empty post__in would be ignored
			// by WooCommerce and silently count every order instead of none.
			return [ 'matched' => 0, 'total' => $total ];
		}

		$matched_q = wc_get_orders( $base + [ 'post__in' => $match_ids ] );
		$matched   = isset( $matched_q->total ) ? (int) $matched_q->total : 0;

		return [ 'matched' => $matched, 'total' => $total ];
	}

	/**
	 * Order ids that used one of the given shipping methods. One direct query on
	 * the order-items tables (shared by HPOS and legacy storage) keyed on the
	 * shipping line's `method_id` meta. Returns [] when nothing matches so the
	 * caller can short-circuit instead of querying with an empty post__in (which
	 * WooCommerce treats as "no restriction" and would export everything).
	 *
	 * @param string[] $method_ids
	 * @return int[]
	 */
	private static function order_ids_for_shipping_methods( array $method_ids ) {
		$method_ids = array_values( array_filter( array_map( 'strval', $method_ids ) ) );
		if ( empty( $method_ids ) ) {
			return [];
		}
		global $wpdb;
		$ph = implode( ',', array_fill( 0, count( $method_ids ), '%s' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = $wpdb->prepare(
			"SELECT DISTINCT oi.order_id
			 FROM {$wpdb->prefix}woocommerce_order_items oi
			 INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta im
			   ON im.order_item_id = oi.order_item_id AND im.meta_key = 'method_id'
			 WHERE oi.order_item_type = 'shipping'
			   AND im.meta_value IN ($ph)",
			$method_ids
		);
		$ids = $wpdb->get_col( $sql );
		return is_array( $ids ) ? array_values( array_filter( array_map( 'intval', $ids ) ) ) : [];
	}

	public static function pull_enabled() {
		return get_option( self::OPT_PULL_ENABLED, 'no' ) === 'yes';
	}

	/**
	 * Pull cadence in seconds. Same offered values as products: 2 / 5 / 15
	 * minutes. Clamped so a stray option write can't schedule a sub-minute or
	 * multi-hour cadence the user never picked.
	 */
	public static function pull_interval_seconds() {
		$raw = (string) get_option( self::OPT_PULL_INTERVAL, '5' );
		switch ( $raw ) {
			case '2':  return 2 * MINUTE_IN_SECONDS;
			case '15': return 15 * MINUTE_IN_SECONDS;
			case '5':
			default:   return 5 * MINUTE_IN_SECONDS;
		}
	}

	// =========================================================================
	// Hook attachment
	// =========================================================================

	public function maybe_attach_hooks() {
		if ( ! self::is_enabled() ) {
			return;
		}
		if ( ! Brikpanel_Sheets_Tokens::is_connected() ) {
			return;
		}

		if ( self::realtime_enabled() ) {
			add_action( 'woocommerce_new_order',                  [ $this, 'on_order_event' ], 10, 1 );
			add_action( 'woocommerce_checkout_order_processed',   [ $this, 'on_order_event' ], 10, 1 );
		}
		// Status changes always tracked so we can update the row even if
		// the order originally synced via bulk.
		add_action( 'woocommerce_order_status_changed', [ $this, 'on_status_changed' ], 10, 4 );
	}

	public function register_handlers() {
		Brikpanel_Cron::register_handler(
			self::HOOK_REALTIME_FLUSH,
			[ $this, 'handle_flush_realtime' ],
			static function () { return [ 'label' => __( 'Sheets — flush new orders to Google Sheets', 'brikpanel' ) ]; }
		);
		Brikpanel_Cron::register_handler(
			self::HOOK_BULK_FLUSH,
			[ $this, 'handle_flush_bulk' ],
			static function () { return [ 'label' => __( 'Sheets — scheduled bulk order export', 'brikpanel' ) ]; }
		);
		Brikpanel_Cron::register_handler(
			self::HOOK_UPDATE_ROWS,
			[ $this, 'handle_update_rows' ],
			static function () { return [ 'label' => __( 'Sheets — update changed-status order rows', 'brikpanel' ) ]; }
		);
		Brikpanel_Cron::register_handler(
			self::HOOK_PULL,
			[ $this, 'handle_pull' ],
			static function () { return [ 'label' => __( 'Sheets — pull order status changes from Google Sheets', 'brikpanel' ) ]; }
		);

		// Schedule recurring bulk export if user picked an interval.
		$interval = self::bulk_interval_seconds();
		if ( $interval > 0 ) {
			Brikpanel_Cron::schedule_recurring( self::HOOK_BULK_FLUSH, $interval, [] );
		} else {
			Brikpanel_Cron::cancel( self::HOOK_BULK_FLUSH );
		}

		// Schedule pull only when flow + pull half are both enabled.
		if ( self::is_enabled() && self::pull_enabled() ) {
			Brikpanel_Cron::schedule_recurring( self::HOOK_PULL, self::pull_interval_seconds(), [] );
		} else {
			Brikpanel_Cron::cancel( self::HOOK_PULL );
		}
	}

	// =========================================================================
	// WC hook entry points (cheap)
	// =========================================================================

	public function on_order_event( $order_id ) {
		$order_id = (int) $order_id;
		if ( $order_id <= 0 ) {
			return;
		}
		// Defer everything to AS — never block checkout request.
		Brikpanel_Cron::schedule_single( time() + 5, self::HOOK_REALTIME_FLUSH, [], [ 'unique' => true ] );
	}

	public function on_status_changed( $order_id, $from, $to, $order ) {
		$order_id = (int) $order_id;
		if ( $order_id <= 0 ) {
			return;
		}
		$synced = $order && method_exists( $order, 'get_meta' )
			? (string) $order->get_meta( self::META_SYNCED_AT )
			: (string) get_post_meta( $order_id, self::META_SYNCED_AT, true );

		if ( $synced === '' ) {
			// Not synced yet — defer to the realtime flusher.
			if ( self::realtime_enabled() ) {
				Brikpanel_Cron::schedule_single( time() + 5, self::HOOK_REALTIME_FLUSH, [], [ 'unique' => true ] );
			}
			return;
		}
		// Already synced — schedule a targeted row update.
		Brikpanel_Cron::enqueue_async( self::HOOK_UPDATE_ROWS, [ 'order_ids' => [ $order_id ] ] );
	}

	// =========================================================================
	// AS handlers
	// =========================================================================

	/**
	 * Realtime flush: find recently unsynced orders, append them.
	 *
	 * @param array $args (unused)
	 * @return array{orders:int, rows:int, more:bool}
	 */
	public function handle_flush_realtime( $args = [] ) {
		$args = (array) $args;
		return $this->flush( [
			'limit'        => 200,
			'date_after'   => null, // recent only — let WC return newest unsynced
			// id ASC as tie-break: orders sharing a creation second (imports,
			// bursts) would otherwise land in the sheet in whatever order the
			// DB felt like, differing between passes.
			'orderby'      => [ 'date_created' => 'ASC', 'id' => 'ASC' ],
			'statuses'     => self::bulk_statuses(),
		] );
	}

	/**
	 * Bulk flush: respect user date filter + interval. Re-queues itself if
	 * more rows remain (paging).
	 *
	 * @param array $args (unused — config comes from options)
	 * @return array{orders:int, rows:int, more:bool}
	 */
	public function handle_flush_bulk( $args = [] ) {
		$args = (array) $args;

		$result = $this->flush( [
			'limit'        => isset( $args['limit'] ) ? max( 1, (int) $args['limit'] ) : self::BATCH_SIZE,
			'date_after'   => '@' . self::bulk_since_timestamp(),
			// Chronological with id ASC as a deterministic tie-break for
			// orders created in the same second (imports, bursts).
			'orderby'      => [ 'date_created' => 'ASC', 'id' => 'ASC' ],
			'statuses'     => self::bulk_statuses(),
		] );

		// The interactive "Sync now" path drives its own continuation from the
		// browser so the merchant sees progress, and passes defer=false to keep
		// a background job from racing it for the flush lock.
		if ( isset( $args['defer'] ) && ! $args['defer'] ) {
			return $result;
		}

		if ( ! empty( $result['more'] ) ) {
			// More rows pending, keep going. NOT unique: Action Scheduler's
			// uniqueness test matches on hook + group and counts the action
			// that is running right now, so a unique enqueue from inside this
			// handler always lost to the handler itself and the background
			// chain stopped after a single batch. Stacking is not a risk:
			// each run enqueues at most one successor, and the flush lock
			// keeps two of them from writing at the same time.
			Brikpanel_Cron::enqueue_async( self::HOOK_BULK_FLUSH, [], [ 'unique' => false ] );
		}
		return $result;
	}

	/**
	 * Update rows for already-synced orders that changed status.
	 *
	 * @param array $args { order_ids: int[] }
	 */
	public function handle_update_rows( $args = [] ) {
		$args = (array) $args;
		$ids  = isset( $args['order_ids'] ) && is_array( $args['order_ids'] ) ? array_map( 'intval', $args['order_ids'] ) : [];
		if ( empty( $ids ) ) {
			return;
		}
		$config = self::resolve_active_target();
		if ( ! $config ) {
			return;
		}

		$client  = new Brikpanel_Sheets_Client();
		$columns = Brikpanel_Sheets_Mapping::get_columns( 'orders' );

		// Stored row numbers are absolute and go stale the moment the merchant
		// sorts the tab or inserts/deletes a row. Writing through a stale number
		// silently overwrites a different order's row, so re-derive the true
		// rows from the sheet's own order_id column once per batch and treat
		// that as authoritative. One extra read per batch, and it is the only
		// thing standing between a sorted sheet and cross-order corruption.
		$live_rows = $this->existing_rows_by_order_id( $client, $config, $columns );

		foreach ( $ids as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				continue;
			}
			$row_map = $order->get_meta( self::META_ROW_MAP );
			$row_map = is_array( $row_map ) ? $row_map : [];

			if ( ! empty( $live_rows ) ) {
				$expected = isset( $live_rows[ (int) $order_id ] ) ? $live_rows[ (int) $order_id ] : [];
				sort( $expected );
				$stored = array_values( array_map( 'intval', $row_map ) );
				sort( $stored );

				if ( $expected !== $stored ) {
					if ( empty( $expected ) ) {
						// The order is no longer anywhere in the tab (row deleted,
						// tab wiped by hand). Writing to its old numbers would
						// clobber whoever sits there now. Drop the mapping and let
						// the normal flush re-append it.
						Brikpanel_Sheets_Logger::log(
							'orders',
							'Order ' . $order_id . ' is no longer in the sheet; clearing its stored rows so the next sync re-adds it instead of overwriting another order.'
						);
						$order->delete_meta_data( self::META_ROW_MAP );
						$order->delete_meta_data( self::META_SYNCED_AT );
						$order->save();
						continue;
					}
					// Rows moved. Re-map this order's line items onto where they
					// actually live now, in the same order build_rows emits them.
					$rebuilt = [];
					$i       = 0;
					foreach ( array_keys( $row_map ) as $line_item_id ) {
						if ( isset( $expected[ $i ] ) ) {
							$rebuilt[ (string) $line_item_id ] = (int) $expected[ $i ];
						}
						$i++;
					}
					if ( ! empty( $rebuilt ) ) {
						$row_map = $rebuilt;
						$order->update_meta_data( self::META_ROW_MAP, $row_map );
						$order->save();
					}
				}
			}
			if ( empty( $row_map ) ) {
				continue;
			}
			$tab = (string) $order->get_meta( self::META_TAB );
			if ( $tab === '' ) {
				$tab = self::tab_name();
			}
			$rows = $this->build_rows( $order, $columns );

			foreach ( $rows as $line_item_id => $row ) {
				// Only update rows we actually pushed in the original sync.
				// If a line item was added after the initial sync, it has no
				// row_map entry — we skip it here (the realtime/bulk flow
				// will pick it up on its next pass). Index-based fallback is
				// a footgun: if any item was deleted the indexes drift and we
				// overwrite the wrong order's rows.
				$sheet_row = (int) ( $row_map[ (string) $line_item_id ] ?? 0 );
				if ( $sheet_row <= 0 ) {
					continue;
				}
				$end_col = self::col_letter( count( $row ) );
				$range = Brikpanel_Sheets_Client::a1_quote_tab( $tab ) . '!A' . $sheet_row . ':' . $end_col . $sheet_row;
				try {
					$client->values_update( $config['spreadsheet_id'], $range, [ $row ] );
				} catch ( Brikpanel_Sheets_Exception $e ) {
					Brikpanel_Sheets_Logger::log( 'orders', 'Update failed for order ' . $order_id . ': ' . $e->getMessage(), $e->http_code );
					throw $e; // surface to AS so retry kicks in
				}
			}
			// Record what we just pushed so the reverse pull can distinguish
			// "sheet edit happened" from "sheet still shows stale value waiting
			// for us to update_rows". Update timestamp too so the conflict
			// guard knows the sheet is now in sync with this Woo state.
			$order->update_meta_data( self::META_LAST_PUSHED_STATUS, (string) $order->get_status() );
			$order->update_meta_data( self::META_SYNCED_AT, current_time( 'mysql', true ) );
			$order->save();
		}
	}

	// =========================================================================
	// Reverse direction: PULL (Sheets → Woo) — status writeback only
	// =========================================================================

	/**
	 * Poll the Orders tab, look for status cells that the merchant edited in
	 * Sheets, and apply each change to Woo via $order->update_status().
	 *
	 * Conflict rule (last-write-wins):
	 *   - If Woo's _date_modified is newer than our last push for that order,
	 *     Woo wins. Re-push the row so the sheet catches up and ignore the
	 *     pending Sheets edit (it was clobbered by a Woo-side change).
	 *   - Otherwise, if the sheet's status differs from BOTH our snapshot AND
	 *     the current Woo status, apply the Sheets value.
	 *
	 * Only the `order_status` column is read back — every other column on the
	 * Orders tab is display-only. Edits to those cells are silently
	 * overwritten on the next push pass.
	 *
	 * @param array $args (unused)
	 * @return array{checked:int, applied:int, conflicts:int}
	 */
	public function handle_pull( $args = [] ) {
		$empty = [ 'checked' => 0, 'applied' => 0, 'conflicts' => 0 ];
		if ( ! self::is_enabled() || ! self::pull_enabled() ) {
			return $empty;
		}
		if ( ! Brikpanel_Sheets_Tokens::is_connected() ) {
			return $empty;
		}
		$config = self::resolve_active_target();
		if ( ! $config ) {
			return $empty;
		}

		// Reuse a transient lock to keep two pull jobs from colliding (manual
		// + scheduled, or two AS workers picking up the same recurring action
		// after a worker restart).
		if ( get_transient( self::PULL_LOCK ) ) {
			return $empty;
		}
		set_transient( self::PULL_LOCK, time(), self::PULL_LOCK_TTL );

		try {
			return $this->pull_locked( $config, $empty );
		} finally {
			delete_transient( self::PULL_LOCK );
		}
	}

	private function pull_locked( array $config, array $empty ) {
		$columns = Brikpanel_Sheets_Mapping::get_columns( 'orders' );
		$col_map = Brikpanel_Sheets_Mapping::column_index_map( $columns );

		if ( ! isset( $col_map['order_id'] ) || ! isset( $col_map['order_status'] ) ) {
			// Without these two columns the pull has nothing to key on.
			// Quietly skip — the user may have deliberately removed
			// order_status from their mapping (though order_id is mandatory).
			return $empty;
		}
		$pid_col    = (int) $col_map['order_id'];
		$status_col = (int) $col_map['order_status'];

		$client = new Brikpanel_Sheets_Client();
		$range  = Brikpanel_Sheets_Client::a1_quote_tab( $config['tab'] )
			. '!A2:' . self::col_letter( count( $columns ) );

		try {
			// UNFORMATTED_VALUE, matching the products pull: cell formatting must
			// not change what we read. Under FORMATTED_VALUE an order id styled
			// with a thousands separator comes back as "12,345" and casts to 12.
			// Status cells are text either way, so nothing is lost here.
			$rows = $client->values_get( $config['spreadsheet_id'], $range, 'UNFORMATTED_VALUE' );
		} catch ( Brikpanel_Sheets_Exception $e ) {
			Brikpanel_Sheets_Logger::log( 'orders', 'pull values_get failed: ' . $e->getMessage(), $e->http_code );
			throw $e;
		}

		$checked    = 0;
		$applied    = 0;
		$conflicts  = 0;
		$valid_statuses = function_exists( 'wc_get_order_statuses' ) ? wc_get_order_statuses() : [];
		// wc_get_order_statuses returns keys with "wc-" prefix and labels as
		// values. We need a quick lookup that accepts both "processing" and
		// "wc-processing", and that maps a translated label back to the key
		// (merchants may have edited the sheet in a localised admin).
		// Keys are registered first and never overwritten, labels only fill slots
		// the keys left free. Both matter:
		//   - The dropdown writes bare keys, so "processing" MUST resolve to
		//     processing. A custom status labelled "Processing" would otherwise
		//     claim that slot and silently set orders to the wrong status.
		//   - mb_strtolower, not strtolower: strtolower is byte-wise, so the
		//     Turkish "İşleniyor" or Cyrillic "ЗАКАЗ" never match what a merchant
		//     actually types, and the pull rejects a perfectly valid status.
		$status_lookup = [];
		$fold          = function ( $s ) {
			return function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $s, 'UTF-8' ) : strtolower( (string) $s );
		};
		foreach ( $valid_statuses as $wc_key => $label ) {
			$bare = preg_replace( '/^wc-/', '', (string) $wc_key );
			$status_lookup[ $fold( $bare ) ]    = $bare;
			$status_lookup[ $fold( $wc_key ) ]  = $bare;
		}
		foreach ( $valid_statuses as $wc_key => $label ) {
			$bare      = preg_replace( '/^wc-/', '', (string) $wc_key );
			$label_key = $fold( $label );
			if ( $label_key !== '' && ! isset( $status_lookup[ $label_key ] ) ) {
				$status_lookup[ $label_key ] = $bare;
			}
		}

		// Collect the WHOLE row span of each order before deciding anything.
		// An order occupies one row per line item, and a merchant edits the row
		// their eye lands on — not necessarily the first. Reading only the first
		// row meant an edit on any later row was silently dropped: no apply, no
		// log, and the sheet left showing a status the store never received.
		$by_order = []; // order_id => [ 'statuses' => set, 'unknown' => set ]

		// While walking the sheet anyway, watch for duplicated
		// (order_id, line_item_id) pairs. A pair can only appear twice if an
		// append was double-sent or a reset re-pushed onto stale rows —
		// always corruption, never merchant intent. Detection here is free
		// (the pull already reads every row); repair happens after the pull.
		$li_col    = isset( $col_map['line_item_id'] ) ? (int) $col_map['line_item_id'] : -1;
		$pair_seen = [];
		$dupe_rows = [];

		foreach ( $rows as $row_i => $row ) {
			// The rows come back UNFORMATTED_VALUE (see the values_get above), so
			// a real order id arrives as a bare number regardless of any cell
			// formatting the merchant applied. Require exactly that: a whole
			// positive number. Under FORMATTED_VALUE this cell could arrive as
			// "12,345" and a bare (int) cast would yield 12 — a status applied
			// to a completely unrelated order. Rejecting non-integers also stops
			// a mis-mapped column (a price like 249.99) resolving to an order.
			$id_cell = isset( $row[ $pid_col ] ) ? $row[ $pid_col ] : '';
			if ( is_string( $id_cell ) ) {
				$id_cell = trim( $id_cell );
			}
			if ( ! is_numeric( $id_cell ) ) { continue; }
			$order_id = (int) $id_cell;
			if ( $order_id <= 0 || (float) $order_id !== (float) $id_cell ) { continue; }

			// Duplicate-pair tracking — before the empty-status skip below, so
			// a duplicated row with a blank status cell is still caught.
			if ( $li_col >= 0 ) {
				$li_cell = isset( $row[ $li_col ] ) ? $row[ $li_col ] : '';
				if ( is_string( $li_cell ) ) {
					$li_cell = trim( $li_cell );
				}
				// >= 0, not > 0: itemless orders are exported as a single row
				// with a sentinel line_item_id of 0, which is still unique per
				// order — their duplicates deserve repair too.
				if ( is_numeric( $li_cell ) && (int) $li_cell >= 0 && (float) (int) $li_cell === (float) $li_cell ) {
					$pair = $order_id . '|' . (int) $li_cell;
					if ( isset( $pair_seen[ $pair ] ) ) {
						$dupe_rows[] = $row_i + 2; // range starts at A2
					} else {
						$pair_seen[ $pair ] = $row_i + 2;
					}
				}
			} else {
				// No line_item_id column — the one-row-per-order layout. Every
				// row of an order shares sentinel key 0, and since the layout
				// allows exactly one row per order, a second sighting of the
				// same order id IS a duplicate.
				$pair = $order_id . '|0';
				if ( isset( $pair_seen[ $pair ] ) ) {
					$dupe_rows[] = $row_i + 2;
				} else {
					$pair_seen[ $pair ] = $row_i + 2;
				}
			}

			$raw = isset( $row[ $status_col ] ) ? trim( (string) $row[ $status_col ] ) : '';
			if ( $raw === '' ) { continue; }

			if ( ! isset( $by_order[ $order_id ] ) ) {
				$by_order[ $order_id ] = [ 'statuses' => [], 'unknown' => [] ];
			}
			// Normalise: accept "Processing", "wc-processing", "processing".
			$key = $fold( $raw );
			if ( isset( $status_lookup[ $key ] ) ) {
				$by_order[ $order_id ]['statuses'][ $status_lookup[ $key ] ] = true;
			} else {
				$by_order[ $order_id ]['unknown'][ $raw ] = true;
			}
		}

		foreach ( $by_order as $order_id => $seen ) {
			$order = wc_get_order( $order_id );
			if ( ! $order ) { continue; }

			// The id column is merchant-editable, so it can point at something
			// that is not a plain order. Two of those are actively dangerous:
			//   - A refund id returns WC_Order_Refund, which does NOT implement
			//     update_status() (it is declared on WC_Order only). Calling it
			//     raises a fatal Error that kills the whole pull mid-batch, so
			//     every order after it in the sheet is skipped too.
			//   - A trashed order still loads, and set_status() happily
			//     transitions OUT of trash — a stale row would silently
			//     resurrect an order the merchant deleted.
			if ( ! $order instanceof WC_Order || $order->has_status( 'trash' ) ) {
				continue;
			}

			$current_status = (string) $order->get_status();
			$last_pushed    = (string) $order->get_meta( self::META_LAST_PUSHED_STATUS );
			$candidates     = array_keys( $seen['statuses'] );

			if ( empty( $candidates ) ) {
				// Every row of this order carries something WooCommerce does not
				// recognise. Surface it: the merchant sees their text sitting in
				// the sheet and needs to know why nothing happened.
				Brikpanel_Sheets_Logger::log(
					'orders',
					'Pull skipped order ' . $order_id . ': unknown status "'
						. implode( '", "', array_keys( $seen['unknown'] ) ) . '"'
				);
				continue;
			}

			// Rows still carrying what we last pushed are untouched siblings,
			// not edits. Drop them so one edited row is not out-voted by the
			// rest of its span.
			if ( count( $candidates ) > 1 && $last_pushed !== '' ) {
				$edited = array_values( array_diff( $candidates, [ $last_pushed ] ) );
				if ( ! empty( $edited ) ) {
					$candidates = $edited;
				}
			}

			if ( count( $candidates ) > 1 ) {
				// Two different statuses typed on two rows of the same order (or
				// a legacy order with no snapshot to compare against). Picking
				// one would be a coin flip that silently changes the store, so
				// refuse and tell the merchant instead.
				$conflicts++;
				Brikpanel_Sheets_Logger::log(
					'orders',
					'Pull skipped order ' . $order_id . ': rows disagree ("'
						. implode( '", "', $candidates ) . '"). Make every row of the order show the same status.'
				);
				continue;
			}

			$sheet_status = (string) $candidates[0];

			$checked++;

			if ( $current_status === $sheet_status ) {
				continue; // already in sync
			}
			if ( $last_pushed !== '' && $sheet_status === $last_pushed ) {
				// Sheet still shows what we last pushed; this is a Woo-side
				// change that update_rows hasn't caught up to. Don't apply.
				continue;
			}

			// Conflict guard: did Woo modify after our last push?
			// META_SYNCED_AT is stored as current_time('mysql', true) which is
			// already UTC; using get_gmt_from_date() would re-convert and shift
			// by the site's timezone offset, giving false "Woo modified hours
			// after push" conflicts on every poll. Parse as UTC directly.
			$last_push_mysql = (string) $order->get_meta( self::META_SYNCED_AT );
			$last_push_ts    = $last_push_mysql !== '' ? (int) strtotime( $last_push_mysql . ' UTC' ) : 0;
			$woo_modified    = $order->get_date_modified();
			$woo_modified_ts = $woo_modified ? (int) $woo_modified->getTimestamp() : 0;

			// Allow a small grace window — our own META_SYNCED_AT update and
			// the order's _date_modified can land in different seconds during
			// a flush, giving false-positive conflicts on freshly-pushed
			// orders. 10s is enough to absorb that without letting real
			// concurrent edits slip past.
			if ( $last_push_ts > 0 && $woo_modified_ts > ( $last_push_ts + 10 ) ) {
				$conflicts++;
				Brikpanel_Sheets_Logger::log( 'orders', 'Pull conflict for order ' . $order_id . ' — Woo modified after last push; re-pushing row.' );
				// Re-push this row so the sheet catches up to Woo.
				Brikpanel_Cron::enqueue_async( self::HOOK_UPDATE_ROWS, [ 'order_ids' => [ $order_id ] ] );
				continue;
			}

			// Apply. update_status() fires woocommerce_order_status_changed,
			// which triggers WC's native side-effects (stock, emails) and our
			// own on_status_changed → HOOK_UPDATE_ROWS, re-pushing every row of
			// this order so the whole span shows the new status.
			//
			// It does NOT throw on failure: WC_Order::update_status() wraps the
			// body in try/catch and returns false when a third-party hook throws
			// or the save fails (see WC_Order::update_status). Writing our
			// snapshot meta BEFORE the call, as this used to, meant a failed
			// apply left META_LAST_PUSHED_STATUS claiming a status the store
			// never took — and the next poll then matched that lie and skipped
			// the order forever, leaving the sheet and the store permanently
			// out of sync with nothing logged. So: call first, commit only on
			// a true return, and surface the failure otherwise.
			$note    = __( 'Status changed via Google Sheets sync.', 'brikpanel' );
			$updated = $order->update_status( $sheet_status, $note );

			// Verify against the database instead of trusting the call. Neither
			// signal is sufficient on its own:
			//   - update_status() never throws; it catches and returns false.
			//   - but WC_Abstract_Order::save() has its OWN try/catch, so a
			//     plugin that throws during save is swallowed there and
			//     update_status() still returns true while nothing persisted.
			//     (Reproduced: a hook throwing on woocommerce_before_order_object_save
			//     returns true with the status unchanged.)
			// Re-reading is also required for correctness regardless: our
			// in-memory copy is stale after the save, and committing meta on it
			// would clobber what WooCommerce just wrote.
			$saved      = wc_get_order( $order_id );
			$new_status = $saved ? (string) $saved->get_status() : '';

			if ( ! $updated || $new_status !== $sheet_status ) {
				// Leave the snapshot meta untouched so the next poll retries
				// instead of recording a status the store never took (which
				// would make us skip this order forever).
				$conflicts++;
				Brikpanel_Sheets_Logger::log(
					'orders',
					'Pull could not apply status "' . $sheet_status . '" to order ' . $order_id
						. '; the store still reports "' . ( $new_status !== '' ? $new_status : 'unknown' )
						. '". A plugin hooked into the status change most likely blocked it.'
						. ' Will retry on the next pull.'
				);
				continue;
			}

			$saved->update_meta_data( self::META_LAST_PUSHED_STATUS, $new_status );
			$saved->update_meta_data( self::META_SYNCED_AT, current_time( 'mysql', true ) );
			$saved->save_meta_data();
			$applied++;
		}

		update_option( self::OPT_LAST_PULL, [
			'ts'        => time(),
			'checked'   => $checked,
			'applied'   => $applied,
			'conflicts' => $conflicts,
		], false );

		// Repair AFTER the pull work: deleting rows shifts everything below
		// them, and the status reads above were taken against the pre-delete
		// positions. Failure here must never fail the pull — the next poll
		// simply detects the same duplicates and tries again.
		if ( ! empty( $dupe_rows ) ) {
			try {
				$this->repair_duplicate_rows( $client, $config, $columns, $dupe_rows );
			} catch ( \Throwable $e ) {
				Brikpanel_Sheets_Logger::log( 'orders', 'Duplicate-row repair failed: ' . $e->getMessage() );
			}
		}

		return [ 'checked' => $checked, 'applied' => $applied, 'conflicts' => $conflicts ];
	}

	/**
	 * Delete duplicated line-item rows from the sheet and rebuild every stored
	 * row map from the post-delete layout.
	 *
	 * The first occurrence of each (order_id, line_item_id) pair is kept — it
	 * is the row the merchant has been looking at (and possibly editing) the
	 * longest; later copies are the accidental re-appends. Deletions are sent
	 * bottom-up as merged contiguous ranges in one batchUpdate so earlier
	 * deletes never shift the indices of later ones.
	 *
	 * Because removing rows shifts every row below them, ALL stored row maps
	 * are rebuilt afterwards from the sheet itself (order_id + line_item_id
	 * columns are both mandatory, so the mapping is exact, not positional
	 * guesswork).
	 *
	 * @param Brikpanel_Sheets_Client $client
	 * @param array $config  { spreadsheet_id, tab }
	 * @param array $columns Active column keys.
	 * @param int[] $rows_to_delete Absolute sheet row numbers of the extra copies.
	 * @return int Rows actually deleted.
	 */
	private function repair_duplicate_rows( $client, array $config, array $columns, array $rows_to_delete ) {
		$rows_to_delete = array_values( array_unique( array_map( 'intval', $rows_to_delete ) ) );
		$rows_to_delete = array_filter( $rows_to_delete, function ( $r ) { return $r >= 2; } ); // never the header
		if ( empty( $rows_to_delete ) ) {
			return 0;
		}
		sort( $rows_to_delete );

		// Merge consecutive row numbers into [start, end] ranges.
		$ranges = [];
		$start  = $prev = array_shift( $rows_to_delete );
		foreach ( $rows_to_delete as $r ) {
			if ( $r === $prev + 1 ) {
				$prev = $r;
				continue;
			}
			$ranges[] = [ $start, $prev ];
			$start    = $prev = $r;
		}
		$ranges[] = [ $start, $prev ];

		// Safety valve for a pathological sheet (thousands of interleaved
		// duplicates after a sort): cap one repair pass, let the next pull
		// finish the job. Deleting the BOTTOM ranges first keeps the kept
		// (first-occurrence) rows and all not-yet-deleted ranges stable.
		$total_ranges = count( $ranges );
		if ( $total_ranges > 500 ) {
			$ranges = array_slice( $ranges, -500 );
			Brikpanel_Sheets_Logger::log(
				'orders',
				'Duplicate repair capped at 500 of ' . $total_ranges . ' ranges this pass; the next pull continues.'
			);
		}

		$sheet_id = null;
		foreach ( $client->list_sheets( $config['spreadsheet_id'] ) as $sid => $name ) {
			if ( strcasecmp( $name, $config['tab'] ) === 0 ) {
				$sheet_id = (int) $sid;
				break;
			}
		}
		if ( $sheet_id === null ) {
			return 0;
		}

		// Bottom-up within the one batchUpdate: requests apply sequentially,
		// so deleting the highest range first leaves lower indices untouched.
		usort( $ranges, function ( $a, $b ) { return $b[0] <=> $a[0]; } );
		$requests = [];
		$deleted  = 0;
		foreach ( $ranges as $range ) {
			$requests[] = [
				'deleteDimension' => [
					'range' => [
						'sheetId'    => $sheet_id,
						'dimension'  => 'ROWS',
						'startIndex' => $range[0] - 1, // 0-based, inclusive
						'endIndex'   => $range[1],     // 0-based, exclusive
					],
				],
			];
			$deleted += ( $range[1] - $range[0] + 1 );
		}
		$client->batch_update( $config['spreadsheet_id'], $requests );

		// Row positions changed for everything below the deleted ranges.
		self::invalidate_sheet_index();

		Brikpanel_Sheets_Logger::log(
			'orders',
			'Removed ' . $deleted . ' duplicated row(s) from the sheet (kept each pair\'s first copy). Rebuilding row maps.'
		);

		$this->rebuild_row_maps_from_sheet( $client, $config, $columns );

		return $deleted;
	}

	/**
	 * Re-read the sheet and rewrite META_ROW_MAP for every synced order from
	 * what is actually there. Exact (keyed on the mandatory order_id +
	 * line_item_id columns), so it also straightens maps skewed by a merchant
	 * sorting the sheet.
	 *
	 * @param Brikpanel_Sheets_Client $client
	 * @param array $config  { spreadsheet_id, tab }
	 * @param array $columns
	 * @return void
	 */
	private function rebuild_row_maps_from_sheet( $client, array $config, array $columns ) {
		$col_map = Brikpanel_Sheets_Mapping::column_index_map( $columns );
		if ( ! isset( $col_map['order_id'] ) ) {
			return;
		}
		$pid_col = (int) $col_map['order_id'];
		// No line_item_id column = one-row-per-order layout; every map keys on
		// the sentinel 0 (same convention as itemless orders).
		$li_col  = isset( $col_map['line_item_id'] ) ? (int) $col_map['line_item_id'] : -1;
		$last    = self::col_letter( max( $pid_col, $li_col ) + 1 );
		$range   = Brikpanel_Sheets_Client::a1_quote_tab( $config['tab'] ) . '!A2:' . $last;

		$rows = $client->values_get( $config['spreadsheet_id'], $range, 'UNFORMATTED_VALUE' );

		$maps = []; // order_id => [ line_item_id => row ]
		foreach ( (array) $rows as $i => $row ) {
			$oid = isset( $row[ $pid_col ] ) ? $row[ $pid_col ] : '';
			$li  = $li_col >= 0 && isset( $row[ $li_col ] ) ? $row[ $li_col ] : 0;
			if ( is_string( $oid ) ) { $oid = trim( $oid ); }
			if ( is_string( $li ) ) { $li = trim( $li ); }
			// line_item_id 0 is the sentinel for itemless orders — a valid key.
			if ( ! is_numeric( $oid ) || (int) $oid <= 0 || ! is_numeric( $li ) || (int) $li < 0 ) {
				continue;
			}
			$maps[ (int) $oid ][ (string) (int) $li ] = $i + 2;
		}
		if ( empty( $maps ) ) {
			return;
		}

		// Only orders already marked synced get their map rewritten — an
		// unmarked order in the sheet is the adoption path's job, and giving
		// it a map without the synced flag would leave inconsistent state.
		global $wpdb;
		$is_hpos = Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
		$table   = $is_hpos ? $wpdb->prefix . 'wc_orders_meta' : $wpdb->postmeta;
		$id_col  = $is_hpos ? 'order_id' : 'post_id';
		$ids     = array_map( 'intval', array_keys( $maps ) );
		$synced  = [];
		foreach ( array_chunk( $ids, 1000 ) as $chunk ) {
			$ph     = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders built from counts, values bound below.
			$found  = $wpdb->get_col( $wpdb->prepare(
				"SELECT {$id_col} FROM {$table} WHERE meta_key = %s AND {$id_col} IN ({$ph})",
				array_merge( [ self::META_SYNCED_AT ], $chunk )
			) );
			$synced = array_merge( $synced, array_map( 'intval', $found ) );
		}

		$pending = [];
		foreach ( $synced as $oid ) {
			if ( isset( $maps[ $oid ] ) ) {
				$pending[ $oid ] = [ self::META_ROW_MAP => $maps[ $oid ] ];
			}
		}
		if ( ! empty( $pending ) ) {
			self::persist_sync_meta( $pending, [ self::META_ROW_MAP ] );
			Brikpanel_Sheets_Logger::log( 'orders', 'Rebuilt row maps for ' . count( $pending ) . ' order(s) from the sheet.' );
		}
	}

	// =========================================================================
	// Core flush
	// =========================================================================

	/**
	 * Append rows for orders matching the given query (and not yet synced).
	 *
	 * @param array $query_args wc_get_orders-shaped args plus our extras.
	 * @return array{orders:int, rows:int, more:bool} Stats — orders processed,
	 *         rows pushed to Sheets, and whether more pages remain to be
	 *         drained by a re-enqueue.
	 */
	private function flush( array $query_args ) {
		$empty = [ 'orders' => 0, 'rows' => 0, 'more' => false ];
		if ( ! self::is_enabled() || ! Brikpanel_Sheets_Tokens::is_connected() ) {
			return $empty;
		}
		$config = self::resolve_active_target();
		if ( ! $config ) {
			Brikpanel_Sheets_Logger::log( 'orders', 'No active target spreadsheet/tab configured; skipping flush.' );
			return $empty;
		}

		// Mutual-exclusion lock so a recurring bulk export AS job firing during
		// a manual "Reset & re-push" doesn't race with the inline ajax_sync_now
		// flush and create duplicate rows. Without this, both workers see the
		// same set of "unsynced" orders after the reset wipes the meta and each
		// push their own copy of every row.
		//
		// Implementation: time-stamped transient with a 5-minute TTL — if a
		// previous flush crashed before releasing, the lock auto-expires so we
		// don't end up permanently blocked.
		$lock_key = self::FLUSH_LOCK;
		$held_since = get_transient( $lock_key );
		if ( $held_since && ( time() - (int) $held_since ) < self::FLUSH_LOCK_TTL ) {
			Brikpanel_Sheets_Logger::log( 'orders', 'Skipping flush — another flush is in progress (lock held).' );
			// Flag it: a plain empty result reads as "everything is synced" to
			// the interactive drain, which would then stop early and report
			// success while a background job is still mid-export. The drain
			// retries on this flag instead.
			return $empty + [ 'locked' => true ];
		}
		set_transient( $lock_key, time(), self::FLUSH_LOCK_TTL );

		try {
			return $this->flush_locked( $query_args, $config, $empty );
		} finally {
			delete_transient( $lock_key );
		}
	}

	const FLUSH_LOCK     = 'brikpanel_gs_orders_flush_lock';
	const FLUSH_LOCK_TTL = 300; // seconds

	/**
	 * Inner body of flush() — runs under the FLUSH_LOCK transient. See flush()
	 * for the locking rationale. Extracted to keep the lock acquisition logic
	 * readable and the release reliable via try/finally.
	 *
	 * @param array $query_args
	 * @param array $config     { spreadsheet_id, tab }
	 * @param array $empty      Empty-result sentinel to return on early exits.
	 * @return array{orders:int, rows:int, more:bool}
	 */
	private function flush_locked( array $query_args, array $config, array $empty ) {

		$columns = Brikpanel_Sheets_Mapping::get_columns( 'orders' );
		$client  = new Brikpanel_Sheets_Client();

		// Ensure target tab exists, write header on first creation, attach a
		// dropdown to the order_status column so merchants can pick a valid
		// status from a list (and Sheets rejects free-text typos).
		//
		// Verified once per request, not once per pass. The interactive export
		// runs many passes back to back and the tab cannot appear or vanish
		// between them, so re-checking each time only burned Google's read
		// quota — enough passes in a minute and the export died on
		// "Read requests per minute per user".
		static $tab_verified = [];
		$tab_key = $config['spreadsheet_id'] . '|' . $config['tab'];
		if ( ! isset( $tab_verified[ $tab_key ] ) ) {
			try {
				$headers     = Brikpanel_Sheets_Mapping::headers_for( 'orders', $columns );
				$validations = self::build_dropdown_validations( $columns );
				$client->ensure_tab( $config['spreadsheet_id'], $config['tab'], $headers, $validations );
				$tab_verified[ $tab_key ] = true;
			} catch ( Brikpanel_Sheets_Exception $e ) {
				Brikpanel_Sheets_Logger::log( 'orders', 'ensure_tab failed: ' . $e->getMessage(), $e->http_code );
				throw $e;
			}
		}

		$orderby  = $query_args['orderby'] ?? 'date';
		$wc_query = [
			'limit'        => (int) ( $query_args['limit'] ?? self::BATCH_SIZE ),
			// Array form ({field: direction}) carries its own directions; the
			// legacy string form still pairs with 'order'.
			'orderby'      => is_array( $orderby ) ? $orderby : (string) $orderby,
			'order'        => (string) ( $query_args['order'] ?? 'ASC' ),
			// Pin the type. Refunds are stored as shop_order_refund rows whose
			// status is "completed", so an unpinned query hands us OrderRefund
			// objects alongside real orders. Those have no get_order_number(),
			// which threw and took the whole batch down with it — one refund
			// anywhere in the range stopped the entire export.
			'type'         => 'shop_order',
			'status'       => $query_args['statuses'] ?? self::bulk_statuses(),
			'paginate'     => false,
			'return'       => 'objects',
			'meta_query'   => [
				'relation' => 'AND',
				[ 'key' => self::META_SYNCED_AT, 'compare' => 'NOT EXISTS' ],
				[ 'key' => self::META_SKIPPED, 'compare' => 'NOT EXISTS' ],
			],
		];
		if ( ! empty( $query_args['date_after'] ) ) {
			$wc_query['date_created'] = '>=' . $query_args['date_after'];
		}

		// Shipping-method filter: restrict to the orders that used one of the
		// chosen methods. We resolve matching order ids up front and pass them as
		// post__in so the existing unsynced-meta / status / date / paging logic
		// keeps working unchanged. If the filter is set but nothing matches we
		// bail early — an empty post__in would be ignored by WooCommerce and
		// export every order instead.
		$ship_methods = self::shipping_method_filter();
		if ( ! empty( $ship_methods ) ) {
			$match_ids = self::order_ids_for_shipping_methods( $ship_methods );
			if ( empty( $match_ids ) ) {
				return $empty;
			}
			$wc_query['post__in'] = $match_ids;
		}

		$orders = wc_get_orders( $wc_query );
		if ( empty( $orders ) ) {
			return $empty;
		}

		// Which orders already occupy sheet rows? An order can be "in the sheet
		// but not marked synced" whenever a previous run appended its rows and
		// then died before the meta write-back (fatal, timeout, deploy). The
		// old behaviour re-appended those orders — a full duplicate block per
		// crash. Instead, ADOPT them: recover their row numbers from the sheet
		// and only mark the meta, appending nothing.
		$sheet_index = $this->sheet_order_index( $client, $config, $columns );

		// Build a flat row list and remember per-order row spans so we can
		// record returned row numbers back to order meta.
		$flat_rows   = [];
		$order_spans = []; // [ order_id => [ count, line_item_ids[] ] ]
		$skipped     = [];
		$adopted     = [];
		foreach ( $orders as $order ) {
			// Never let one unmappable order abort the run. Anything that
			// throws here would otherwise stall the export permanently: the
			// order never gets marked synced, so every later attempt reloads it
			// and dies at the same place, and nothing behind it is ever
			// exported. Skip it, note it in the log, keep going.
			try {
				$rows = $this->build_rows( $order, $columns );
			} catch ( \Throwable $e ) {
				$skipped[] = $order->get_id();
				Brikpanel_Sheets_Logger::log(
					'orders',
					'Skipped order ' . $order->get_id() . ' — could not build its rows: ' . $e->getMessage()
				);
				continue;
			}
			if ( empty( $rows ) ) {
				continue;
			}

			$order_id = $order->get_id();
			if ( ! empty( $sheet_index[ $order_id ] ) ) {
				// Rows already present — adopt them instead of re-appending.
				$rows_for_order = $sheet_index[ $order_id ];
				sort( $rows_for_order );
				$row_map       = [];
				$line_item_ids = array_keys( $rows );
				foreach ( $line_item_ids as $i => $line_item_id ) {
					if ( isset( $rows_for_order[ $i ] ) ) {
						$row_map[ (string) $line_item_id ] = (int) $rows_for_order[ $i ];
					}
				}
				$adopted[ $order_id ] = [
					self::META_SYNCED_AT          => current_time( 'mysql', true ),
					self::META_ROW_MAP            => $row_map,
					self::META_SPREADSHEET        => $config['spreadsheet_id'],
					self::META_TAB                => $config['tab'],
					self::META_LAST_PUSHED_STATUS => (string) $order->get_status(),
				];
				continue;
			}

			$order_spans[ $order_id ] = [
				'count'         => count( $rows ),
				'line_item_ids' => array_keys( $rows ),
			];
			foreach ( $rows as $row ) {
				$flat_rows[] = $row;
			}
		}

		if ( ! empty( $skipped ) ) {
			self::mark_skipped( $skipped );
		}
		if ( ! empty( $adopted ) ) {
			self::persist_sync_meta( $adopted );
			Brikpanel_Sheets_Logger::log(
				'orders',
				'Adopted ' . count( $adopted ) . ' order(s) whose rows were already in the sheet (recovered from an interrupted earlier push) instead of appending duplicates.'
			);
		}

		if ( empty( $flat_rows ) ) {
			// Nothing to append, but if the pass was full there may still be
			// exportable orders behind the ones we skipped or adopted — both
			// are marked above, so the next pass moves past them instead of
			// refetching the same page forever.
			return [
				'orders' => count( $orders ),
				'rows'   => 0,
				'more'   => count( $orders ) >= (int) ( $query_args['limit'] ?? self::BATCH_SIZE ),
			];
		}

		// Append with explicit idempotency handling. Ambiguous transport
		// failures (timeout after the POST was sent, 5xx) mean Google MAY have
		// written the rows even though we saw an error; blindly re-sending in
		// that state is exactly how the same order block ends up in the sheet
		// twice. So: no internal retry for those. On an ambiguous failure we
		// re-read the sheet — if our first order's id is now present, the rows
		// landed and we recover their positions from the sheet; if not, the
		// append genuinely failed and re-sending is safe.
		$resp            = null;
		$forced_recon    = null;
		$append_attempts = 0;
		$probe_order_id  = array_key_first( $order_spans );
		while ( true ) {
			try {
				$resp = $client->append_rows( $config['spreadsheet_id'], $config['tab'], $flat_rows, 'USER_ENTERED', false );
				break;
			} catch ( Brikpanel_Sheets_Exception $e ) {
				if ( 'ambiguous_failure' !== $e->api_reason ) {
					Brikpanel_Sheets_Logger::log( 'orders', 'append_rows failed: ' . $e->getMessage(), $e->http_code );
					throw $e;
				}
				$fresh = $this->sheet_order_index( $client, $config, $columns, true );
				if ( $probe_order_id !== null && ! empty( $fresh[ $probe_order_id ] ) ) {
					// The rows landed despite the error (the append call is
					// atomic: all rows or none). Recover positions from the
					// sheet instead of re-sending.
					$forced_recon = $fresh;
					Brikpanel_Sheets_Logger::log(
						'orders',
						'Append reported "' . $e->getMessage() . '" but the rows landed; recovered their positions from the sheet instead of re-appending.'
					);
					break;
				}
				if ( $append_attempts >= 2 ) {
					Brikpanel_Sheets_Logger::log( 'orders', 'append_rows failed after retries: ' . $e->getMessage(), $e->http_code );
					throw $e;
				}
				$append_attempts++;
				sleep( 2 * $append_attempts );
			}
		}

		// values:append with INSERT_ROWS strips data-validation rules from
		// the freshly inserted rows. Reapply our order_status dropdown so
		// every appended row has it available. Best-effort: log and continue
		// on failure since the row data has already landed successfully.
		// Queue rather than issue it now. The validation range covers the whole
		// status column with no lower bound, so one call at the end of the
		// request protects every row appended during it. Issuing it per pass
		// doubled our write calls against Google's 60-per-minute write quota,
		// which is what killed a long export part-way through.
		self::queue_validation_reapply( $config, $columns );

		// Parse updates.updatedRange — "Tab!A12:F19" — to get the starting row
		// number; assume the rows were inserted in our submitted order.
		$start_row  = is_array( $resp ) ? $this->extract_start_row( $resp ) : 0;
		$row_cursor = $start_row > 0 ? $start_row : 0;

		// Google did not tell us where the append landed (no range in the
		// response, or the ambiguous-failure recovery above already read the
		// sheet). The rows ARE in the sheet, so re-appending would duplicate
		// them, but marking the orders synced with an empty row map would
		// strand them forever: the flush query skips anything carrying
		// META_SYNCED_AT and update_rows skips an empty map, so their status
		// would never reach the sheet again. Recover the row numbers from the
		// sheet instead.
		$reconciled = [];
		if ( $row_cursor <= 0 ) {
			$reconciled = is_array( $forced_recon )
				? $forced_recon
				: $this->existing_rows_by_order_id( $client, $config, $columns );
			Brikpanel_Sheets_Logger::log(
				'orders',
				'Append response carried no range; recovered row numbers for '
					. count( $reconciled ) . ' order(s) by reading the sheet.'
			);
		} else {
			// Normal path — teach the per-request index where this batch went
			// so a later pass in the same request never re-reads (or worse,
			// re-appends) these orders.
			$cursor_preview = $row_cursor;
			$index_add      = [];
			foreach ( $order_spans as $span_order_id => $span ) {
				$span_rows = [];
				for ( $i = 0; $i < (int) $span['count']; $i++ ) {
					$span_rows[] = $cursor_preview + $i;
				}
				$cursor_preview            += (int) $span['count'];
				$index_add[ $span_order_id ] = $span_rows;
			}
			self::sheet_order_index_merge( $config, $index_add );
		}

		// Index the orders we already loaded so the write-back never re-reads
		// them. Reloading with wc_get_order() here used to cost a second full
		// hydrate per order on top of a full WC_Order::save(), which is what
		// made a 250-order batch take ~13s of pure write-back and blow past
		// the request time limit on real stores.
		$loaded = [];
		foreach ( $orders as $order ) {
			$loaded[ $order->get_id() ] = $order;
		}

		$synced_at = current_time( 'mysql', true );
		$pending   = [];

		foreach ( $order_spans as $order_id => $span ) {
			$order = isset( $loaded[ $order_id ] ) ? $loaded[ $order_id ] : null;
			if ( ! $order ) {
				// The order vanished between the query and the write-back, but
				// its rows were still appended above. Advance past them anyway
				// or every following order in this batch inherits a row map
				// shifted up by this span and later overwrites its neighbour.
				if ( $row_cursor > 0 ) {
					$row_cursor += (int) $span['count'];
				}
				continue;
			}
			$row_map = [];
			if ( $row_cursor > 0 ) {
				foreach ( $span['line_item_ids'] as $i => $line_item_id ) {
					$row_map[ (string) $line_item_id ] = $row_cursor + $i;
				}
				$row_cursor += (int) $span['count'];
			} elseif ( ! empty( $reconciled[ $order_id ] ) ) {
				$rows_for_order = $reconciled[ $order_id ];
				sort( $rows_for_order );
				foreach ( $span['line_item_ids'] as $i => $line_item_id ) {
					if ( isset( $rows_for_order[ $i ] ) ) {
						$row_map[ (string) $line_item_id ] = (int) $rows_for_order[ $i ];
					}
				}
			}
			$pending[ $order_id ] = [
				self::META_SYNCED_AT          => $synced_at,
				self::META_ROW_MAP            => $row_map,
				self::META_SPREADSHEET        => $config['spreadsheet_id'],
				self::META_TAB                => $config['tab'],
				self::META_LAST_PUSHED_STATUS => (string) $order->get_status(),
			];
		}

		self::persist_sync_meta( $pending );

		update_option( self::OPT_LAST_SYNC, [
			'ts'        => time(),
			'rows'      => count( $flat_rows ),
			'orders'    => count( $orders ),
		], false );

		$limit = (int) ( $query_args['limit'] ?? self::BATCH_SIZE );
		return [
			'orders' => count( $orders ),
			'rows'   => count( $flat_rows ),
			'more'   => count( $orders ) >= $limit,
		];
	}

	/**
	 * Write the bookkeeping meta for a whole batch of just-exported orders in
	 * one round-trip per storage table.
	 *
	 * These five keys are pure sync bookkeeping — nothing in WooCommerce needs
	 * to react to them. Routing them through WC_Order::save() ran the entire
	 * order save pipeline (recalculation, status hooks, every third-party
	 * listener) once per order, which measured ~13s for a 250-order batch on a
	 * normal install. That is what pushed the "Sync now" request past the PHP
	 * time limit: the rows had already been appended to the sheet, so the
	 * request died part-way through the write-back leaving most orders
	 * unmarked, and the next click re-exported them as duplicates.
	 *
	 * Writing the rows directly costs ~0.03s for the same batch. Because that
	 * bypasses the data store we drop each order's cache entry afterwards so
	 * later get_meta() reads see the new values.
	 *
	 * @param array<int, array<string, mixed>> $pending order_id => [ meta_key => value ].
	 * @return void
	 */
	/**
	 * Schedule one data-validation reapply for the end of the request.
	 *
	 * `values.append` with INSERT_ROWS strips the order_status dropdown from
	 * the rows it inserts, so it has to be reapplied after appending. The rule
	 * spans the entire column though, so a single call once the request has
	 * finished appending covers every pass it ran. Best-effort throughout: the
	 * row data is already safely in the sheet, only the dropdown is at stake.
	 *
	 * @param array $config  { spreadsheet_id, tab }
	 * @param array $columns Active column set.
	 * @return void
	 */
	private static function queue_validation_reapply( array $config, array $columns ) {
		static $queued = [];

		$key = $config['spreadsheet_id'] . '|' . $config['tab'];
		if ( isset( $queued[ $key ] ) ) {
			return;
		}
		$queued[ $key ] = true;

		register_shutdown_function( function () use ( $config, $columns ) {
			try {
				$validations = self::build_dropdown_validations( $columns );
				if ( empty( $validations ) ) {
					return;
				}
				$client   = new Brikpanel_Sheets_Client();
				$sheet_id = null;
				foreach ( $client->list_sheets( $config['spreadsheet_id'] ) as $sid => $name ) {
					if ( strcasecmp( $name, $config['tab'] ) === 0 ) {
						$sheet_id = (int) $sid;
						break;
					}
				}
				if ( $sheet_id !== null ) {
					$client->apply_data_validation( $config['spreadsheet_id'], $sheet_id, $validations );
				}
			} catch ( \Throwable $e ) {
				Brikpanel_Sheets_Logger::log( 'orders', 'Validation reapply failed: ' . $e->getMessage() );
			}
		} );
	}

	/**
	 * Flag orders whose rows could not be built so the export query stops
	 * returning them. Without this a permanently unmappable order is refetched
	 * on every pass, and a pass made entirely of such orders would loop.
	 *
	 * @param int[] $order_ids
	 * @return void
	 */
	private static function mark_skipped( array $order_ids ) {
		$pending = [];
		$stamp   = current_time( 'mysql', true );
		foreach ( $order_ids as $order_id ) {
			$pending[ (int) $order_id ] = [ self::META_SKIPPED => $stamp ];
		}
		self::persist_sync_meta( $pending, [ self::META_SKIPPED ] );
	}

	private static function persist_sync_meta( array $pending, array $keys = null ) {
		if ( empty( $pending ) ) {
			return;
		}

		global $wpdb;

		$hpos   = Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
		$table  = $hpos ? $wpdb->prefix . 'wc_orders_meta' : $wpdb->postmeta;
		$id_col = $hpos ? 'order_id' : 'post_id';

		$order_ids = array_map( 'intval', array_keys( $pending ) );
		$keys      = $keys !== null ? $keys : [
			self::META_SYNCED_AT,
			self::META_ROW_MAP,
			self::META_SPREADSHEET,
			self::META_TAB,
			self::META_LAST_PUSHED_STATUS,
		];

		// Clear any previous values for these keys so re-exported orders end up
		// with exactly one row per key instead of accumulating duplicates.
		$id_ph  = implode( ',', array_fill( 0, count( $order_ids ), '%d' ) );
		$key_ph = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders built from counts, values bound below.
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$table} WHERE {$id_col} IN ({$id_ph}) AND meta_key IN ({$key_ph})",
			array_merge( $order_ids, $keys )
		) );

		$values = [];
		foreach ( $pending as $order_id => $meta ) {
			foreach ( $meta as $key => $value ) {
				$values[] = $wpdb->prepare( '(%d,%s,%s)', (int) $order_id, $key, maybe_serialize( $value ) );
			}
		}

		// Chunked so a large batch cannot exceed max_allowed_packet.
		foreach ( array_chunk( $values, 500 ) as $chunk ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- each tuple prepared above.
			$wpdb->query( "INSERT INTO {$table} ({$id_col},meta_key,meta_value) VALUES " . implode( ',', $chunk ) );
		}

		// Direct writes bypass the data store, so evict the cached orders.
		foreach ( $order_ids as $order_id ) {
			if ( $hpos ) {
				wp_cache_delete( $order_id, 'orders' );
				wp_cache_delete( $order_id, 'orders-meta' );
			} else {
				wp_cache_delete( $order_id, 'post_meta' );
			}
		}
	}

	// =========================================================================
	// Row builder
	// =========================================================================

	/**
	 * Build the rows for an order, one per line item, keyed by line_item_id.
	 *
	 * @param WC_Order $order
	 * @param string[] $columns
	 * @return array<int|string, array<int, scalar>>
	 */
	public function build_rows( $order, array $columns ) {
		// One-row-per-order layout: the whole order collapses into a single
		// row keyed by the same sentinel 0 that itemless orders always used,
		// so row maps, dedup pairs and the pull all keep working unchanged.
		// Item-level cells aggregate across the items (see the null-item
		// branches in resolve_column_value).
		if ( 'order' === self::row_layout() ) {
			return [ 0 => $this->build_one_row( $order, null, $columns ) ];
		}

		$items = $order->get_items( 'line_item' );
		if ( empty( $items ) ) {
			// Order with no line items (rare). Emit a single row with a sentinel
			// line_item_id of 0 so the order still appears.
			return [ 0 => $this->build_one_row( $order, null, $columns ) ];
		}
		$out = [];
		foreach ( $items as $item_id => $item ) {
			$out[ (int) $item_id ] = $this->build_one_row( $order, $item, $columns );
		}
		return $out;
	}

	/**
	 * Build a single row for one (order, line_item) pair.
	 *
	 * @param WC_Order              $order
	 * @param WC_Order_Item_Product $item
	 * @param string[]              $columns
	 * @return array<int, scalar>
	 */
	private function build_one_row( $order, $item, array $columns ) {
		$product   = $item ? $item->get_product() : null;
		$is_var    = $product && $product->is_type( 'variation' );
		$row = [];
		foreach ( $columns as $col ) {
			$row[] = $this->resolve_column_value( $col, $order, $item, $product, $is_var );
		}
		return $row;
	}

	private function resolve_column_value( $col, $order, $item, $product, $is_var ) {
		switch ( $col ) {
			// Order-level
			case 'order_id':             return (int) $order->get_id();
			case 'order_number':         return (string) $order->get_order_number();
			case 'order_date':           return $order->get_date_created() ? $order->get_date_created()->format( 'Y-m-d H:i:s' ) : '';
			case 'order_status':         return (string) $order->get_status();
			case 'currency':             return (string) $order->get_currency();
			case 'subtotal':             return (float) $order->get_subtotal();
			case 'tax_total':            return (float) $order->get_total_tax();
			case 'shipping_total':       return (float) $order->get_shipping_total();
			case 'discount_total':       return (float) $order->get_total_discount();
			case 'total':                return (float) $order->get_total();
			case 'payment_method':       return (string) $order->get_payment_method();
			case 'payment_method_title': return (string) $order->get_payment_method_title();
			case 'transaction_id':       return (string) $order->get_transaction_id();
			case 'coupon_codes':         return implode( ', ', $order->get_coupon_codes() );
			case 'customer_note':        return (string) $order->get_customer_note();
			case 'customer_id':          return (int) $order->get_customer_id();
			case 'shipping_method':      return (string) $order->get_shipping_method(); // comma-joined titles
			case 'order_cogs_total':     return round( $this->order_cogs_total( $order ), 2 );

			// Billing
			case 'billing_first_name':   return (string) $order->get_billing_first_name();
			case 'billing_last_name':    return (string) $order->get_billing_last_name();
			case 'billing_email':        return (string) $order->get_billing_email();
			case 'billing_phone':        return (string) $order->get_billing_phone();
			case 'billing_address_1':    return (string) $order->get_billing_address_1();
			case 'billing_address_2':    return (string) $order->get_billing_address_2();
			case 'billing_city':         return (string) $order->get_billing_city();
			case 'billing_state':        return (string) $order->get_billing_state();
			case 'billing_postcode':     return (string) $order->get_billing_postcode();
			case 'billing_country':      return (string) $order->get_billing_country();

			// Shipping
			case 'shipping_first_name':  return (string) $order->get_shipping_first_name();
			case 'shipping_last_name':   return (string) $order->get_shipping_last_name();
			case 'shipping_address_1':   return (string) $order->get_shipping_address_1();
			case 'shipping_address_2':   return (string) $order->get_shipping_address_2();
			case 'shipping_city':        return (string) $order->get_shipping_city();
			case 'shipping_state':       return (string) $order->get_shipping_state();
			case 'shipping_postcode':    return (string) $order->get_shipping_postcode();
			case 'shipping_country':     return (string) $order->get_shipping_country();

			// Line item
			// Line-item columns. With a null $item (one-row-per-order layout,
			// or an itemless order) they aggregate across the order's items so
			// the single row still carries the information.
			case 'line_item_id':         return $item ? (int) $item->get_id() : 0;
			case 'product_id':
				if ( $item ) { return (int) $item->get_product_id(); }
				return implode( ', ', $this->aggregate_item_values( $order, function ( $it ) {
					return (string) (int) $it->get_product_id();
				} ) );
			case 'variation_id':
				if ( $item ) { return (int) $item->get_variation_id(); }
				return implode( ', ', $this->aggregate_item_values( $order, function ( $it ) {
					$vid = (int) $it->get_variation_id();
					return $vid > 0 ? (string) $vid : '';
				} ) );
			case 'product_sku':
				if ( $item ) { return $product ? (string) $product->get_sku() : ''; }
				return implode( ', ', $this->aggregate_item_values( $order, function ( $it ) {
					$p = $it->get_product();
					return $p ? (string) $p->get_sku() : '';
				} ) );
			case 'product_name':
				if ( $item ) { return (string) $item->get_name(); }
				return $this->order_items_summary( $order );
			case 'items_summary':
				return $item ? $this->format_item_summary( $item ) : $this->order_items_summary( $order );
			case 'variation_attributes':
				if ( $is_var && $product ) {
					$attrs = [];
					foreach ( $product->get_variation_attributes() as $k => $v ) {
						if ( $v === '' || ! is_scalar( $v ) ) { continue; }
						$raw_name = str_replace( 'attribute_', '', $k );
						$label    = wc_attribute_label( $raw_name );
						// wc_attribute_label returns the taxonomy label for
						// global attributes ("pa_color" → "Color"), but for
						// local attributes it just echoes the raw name as-is
						// — which usually looks ugly when the user typed it
						// in lowercase. Title-case as a sensible default.
						if ( $label === '' || $label === $raw_name ) {
							$label = function_exists( 'mb_convert_case' )
								? mb_convert_case( $raw_name, MB_CASE_TITLE, 'UTF-8' )
								: ucfirst( $raw_name );
						}
						$attrs[] = $label . ': ' . (string) $v;
					}
					return implode( '; ', $attrs );
				}
				if ( $item && method_exists( $item, 'get_meta_data' ) ) {
					$meta_strs = [];
					foreach ( $item->get_meta_data() as $m ) {
						$key = (string) $m->key;
						if ( $key === '' || $key[0] === '_' ) { continue; }
						$value = $m->value;
						if ( is_array( $value ) ) {
							// Flatten arrays of scalars; skip nested arrays.
							$flat = array_filter( $value, 'is_scalar' );
							$value = $flat ? implode( ', ', array_map( 'strval', $flat ) ) : '';
						} elseif ( ! is_scalar( $value ) ) {
							continue;
						}
						$meta_strs[] = wp_strip_all_tags( wc_attribute_label( $key ) ) . ': ' . wp_strip_all_tags( (string) $value );
					}
					return implode( '; ', $meta_strs );
				}
				return '';
			case 'quantity':
				if ( $item ) { return (float) $item->get_quantity(); }
				$sum = 0.0;
				foreach ( $order->get_items( 'line_item' ) as $it ) { $sum += (float) $it->get_quantity(); }
				return $sum;
			case 'unit_price':
				if ( $item ) {
					return $item->get_quantity() > 0 ? round( (float) $item->get_subtotal() / (float) $item->get_quantity(), 4 ) : 0;
				}
				return ''; // a single "unit price" is meaningless across mixed items
			case 'line_subtotal':
				if ( $item ) { return (float) $item->get_subtotal(); }
				$sum = 0.0;
				foreach ( $order->get_items( 'line_item' ) as $it ) { $sum += (float) $it->get_subtotal(); }
				return $sum;
			case 'line_tax':
				if ( $item ) { return (float) $item->get_total_tax(); }
				$sum = 0.0;
				foreach ( $order->get_items( 'line_item' ) as $it ) { $sum += (float) $it->get_total_tax(); }
				return $sum;
			case 'line_total':
				if ( $item ) { return (float) $item->get_total(); }
				$sum = 0.0;
				foreach ( $order->get_items( 'line_item' ) as $it ) { $sum += (float) $it->get_total(); }
				return $sum;
			case 'line_cogs':
				if ( ! $item ) { return round( $this->order_cogs_total( $order ), 2 ); }
				$unit = self::unit_cost( (int) $item->get_product_id(), (int) $item->get_variation_id() );
				return round( $unit * (float) $item->get_quantity(), 2 );
		}

		// Custom checkout / order fields (synthetic "cf_*" columns) resolve to
		// order meta. Try each stored key candidate and use the first non-empty.
		if ( strpos( (string) $col, 'cf_' ) === 0 ) {
			foreach ( Brikpanel_Sheets_Mapping::order_custom_field_keys( $col ) as $meta_key ) {
				$value = $order->get_meta( $meta_key, true );
				if ( $value === '' || $value === null ) {
					continue;
				}
				if ( is_array( $value ) ) {
					$flat = array_filter( $value, 'is_scalar' );
					return $flat ? implode( ', ', array_map( 'strval', $flat ) ) : '';
				}
				return is_scalar( $value ) ? wp_strip_all_tags( (string) $value ) : '';
			}
			return '';
		}
		return '';
	}

	/**
	 * "2× Merino Crew Sweater (XL / Navy)" — one item, human-readable.
	 * WC_Order_Item_Product::get_name() already carries variation attributes,
	 * so variable products stay distinguishable in the summary.
	 *
	 * @param WC_Order_Item_Product $item
	 * @return string
	 */
	private function format_item_summary( $item ) {
		$qty     = (float) $item->get_quantity();
		$qty_str = ( $qty === (float) (int) $qty ) ? (string) (int) $qty : (string) $qty;
		return $qty_str . '× ' . (string) $item->get_name();
	}

	/**
	 * All items of an order as one cell: "2× A (XL / Navy) | 1× B".
	 *
	 * @param WC_Order $order
	 * @return string
	 */
	private function order_items_summary( $order ) {
		$parts = [];
		foreach ( $order->get_items( 'line_item' ) as $it ) {
			$parts[] = $this->format_item_summary( $it );
		}
		return implode( ' | ', $parts );
	}

	/**
	 * Map every line item through $fn, dropping empty results. Used by the
	 * aggregated (one-row-per-order) cell renderers.
	 *
	 * @param WC_Order $order
	 * @param callable $fn ( WC_Order_Item_Product ): string
	 * @return string[]
	 */
	private function aggregate_item_values( $order, $fn ) {
		$out = [];
		foreach ( $order->get_items( 'line_item' ) as $it ) {
			$v = (string) call_user_func( $fn, $it );
			if ( $v !== '' ) {
				$out[] = $v;
			}
		}
		return $out;
	}

	/**
	 * Total cost of goods for an order: sum of (unit cost × quantity) across its
	 * line items. Memoised per order id so emitting one row per line item does
	 * not recompute the sum on every row. Reads the same `_brikpanel_cogs`
	 * per-unit cost (variation → parent fallback) the profit reports use.
	 *
	 * @param WC_Order $order
	 * @return float
	 */
	private function order_cogs_total( $order ) {
		$oid = (int) $order->get_id();
		if ( isset( $this->cogs_total_cache[ $oid ] ) ) {
			return $this->cogs_total_cache[ $oid ];
		}
		$total = 0.0;
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			$unit   = self::unit_cost( (int) $item->get_product_id(), (int) $item->get_variation_id() );
			$total += $unit * (float) $item->get_quantity();
		}
		$this->cogs_total_cache[ $oid ] = $total;
		return $total;
	}

	/** @var array<int,float> Per-order COGS-total memo for the current request. */
	private $cogs_total_cache = [];

	/**
	 * Per-unit cost of goods for a product/variation. Delegates to the central
	 * accessor so the sheet always agrees with the profit reports: WC-native
	 * cost first with the legacy `_brikpanel_cogs` fallback, variation →
	 * parent fallback, and additive variations handled.
	 *
	 * @param int $product_id
	 * @param int $variation_id
	 * @return float
	 */
	private static function unit_cost( $product_id, $variation_id = 0 ) {
		$cost = brikpanel_product_cogs( $product_id, $variation_id );
		return null === $cost ? 0.0 : (float) $cost;
	}

	// =========================================================================
	// Target resolution
	// =========================================================================

	/**
	 * Active spreadsheet + tab. Returns null if not configured.
	 *
	 * @return array{spreadsheet_id:string, tab:string}|null
	 */
	public static function resolve_active_target() {
		$id = (string) get_option( 'brikpanel_gs_spreadsheet_id', '' );
		if ( $id === '' ) {
			return null;
		}
		return [
			'spreadsheet_id' => $id,
			'tab'            => self::tab_name(),
		];
	}

	/**
	 * Public entry point for the "Sync now" admin button: enqueue a bulk
	 * flush immediately.
	 */
	public static function trigger_manual_sync() {
		Brikpanel_Cron::enqueue_async( self::HOOK_BULK_FLUSH, [], [ 'unique' => true ] );
	}

	/**
	 * Build the validation rules passed to ensure_tab so the order_status
	 * column gets a dropdown of all WooCommerce-known statuses (default WC
	 * statuses + anything custom plugins added via wc_register_order_status).
	 *
	 * Returns [] when order_status is not in the user's mapping, so the
	 * mapping UI can still hide the column without us trying to validate a
	 * column that does not exist.
	 *
	 * @param string[] $columns Active column selection in display order.
	 * @return array Validation entries for Brikpanel_Sheets_Client::ensure_tab.
	 */
	public static function build_dropdown_validations( array $columns ) {
		$col_map = Brikpanel_Sheets_Mapping::column_index_map( $columns );
		if ( ! isset( $col_map['order_status'] ) || ! function_exists( 'wc_get_order_statuses' ) ) {
			return [];
		}
		$keys = [];
		foreach ( wc_get_order_statuses() as $wc_key => $_label ) {
			// wc_get_order_statuses keys are prefixed "wc-"; WC's get_status()
			// returns the bare key, and that is what we write to the sheet on
			// push and read back on pull. The dropdown should match.
			$keys[] = preg_replace( '/^wc-/', '', (string) $wc_key );
		}
		return [
			[
				'column_index' => (int) $col_map['order_status'],
				'values'       => $keys,
				'strict'       => true,
			],
		];
	}

	// =========================================================================
	// Small utilities
	// =========================================================================

	/**
	 * Parse the start row from a Sheets append response. The API returns
	 * `updates.updatedRange` like "Orders!A12:F19" — we want 12.
	 *
	 * @param array $resp
	 * @return int
	 */
	/**
	 * Map order_id → the sheet rows that currently hold it, read straight from
	 * the tab's order_id column.
	 *
	 * Stored row numbers (META_ROW_MAP) are absolute and nothing re-validates
	 * them, so any merchant action that shifts rows — sorting the tab, deleting
	 * a row, inserting one — silently makes every stored number below the edit
	 * point to the wrong order. Writing through a stale number overwrites an
	 * unrelated order's row. The products flow has had this defence since day
	 * one (existing_rows_by_product_id); orders never did.
	 *
	 * @param Brikpanel_Sheets_Client $client
	 * @param array                   $config  Resolved target (spreadsheet_id, tab).
	 * @param string[]                $columns Saved column keys.
	 * @return array<int, int[]> order_id => ascending 1-based sheet rows.
	 */
	/**
	 * Per-request cache of "which order ids occupy which sheet rows".
	 * Key: "<spreadsheet_id>|<tab>", value: [ 'ts' => int, 'map' => array ].
	 *
	 * @var array<string, array{ts:int, map:array<int,int[]>}>
	 */
	private static $sheet_index_cache = [];

	/**
	 * Cached view of the sheet's order_id column, used by the flush to decide
	 * append-vs-adopt. One Sheets read per request (refreshed after 120s for
	 * long-lived Action Scheduler workers); the flush merges its own appends
	 * into the cache so multi-pass requests stay read-free.
	 *
	 * A failed or empty read never overwrites a previously populated map: the
	 * only legitimate way the sheet empties mid-process is our own reset,
	 * which calls invalidate_sheet_index() explicitly. Keeping the last good
	 * map on a flaky read errs on the side of adoption — and a wrongly adopted
	 * row map self-heals through update_rows' sheet verification — whereas
	 * trusting a flaky empty read errs on the side of appending duplicates,
	 * which nothing heals automatically.
	 *
	 * @param Brikpanel_Sheets_Client $client
	 * @param array                   $config  { spreadsheet_id, tab }
	 * @param array                   $columns
	 * @param bool                    $refresh Force a fresh read.
	 * @return array<int, int[]> order_id => sheet row numbers.
	 */
	private function sheet_order_index( $client, array $config, array $columns, $refresh = false ) {
		$key = $config['spreadsheet_id'] . '|' . $config['tab'];
		$now = time();
		if ( ! $refresh
			&& isset( self::$sheet_index_cache[ $key ] )
			&& ( $now - self::$sheet_index_cache[ $key ]['ts'] ) < 120 ) {
			return self::$sheet_index_cache[ $key ]['map'];
		}
		$map = $this->existing_rows_by_order_id( $client, $config, $columns );
		if ( empty( $map ) && ! empty( self::$sheet_index_cache[ $key ]['map'] ) ) {
			self::$sheet_index_cache[ $key ]['ts'] = $now;
			return self::$sheet_index_cache[ $key ]['map'];
		}
		self::$sheet_index_cache[ $key ] = [ 'ts' => $now, 'map' => $map ];
		return $map;
	}

	/**
	 * Teach the cached index about rows this request just appended, so later
	 * passes neither re-read the sheet nor mistake those orders for missing.
	 *
	 * @param array $config { spreadsheet_id, tab }
	 * @param array<int, int[]> $add order_id => row numbers.
	 * @return void
	 */
	private static function sheet_order_index_merge( array $config, array $add ) {
		$key = $config['spreadsheet_id'] . '|' . $config['tab'];
		if ( ! isset( self::$sheet_index_cache[ $key ] ) ) {
			return;
		}
		foreach ( $add as $oid => $rows ) {
			self::$sheet_index_cache[ $key ]['map'][ (int) $oid ] = array_map( 'intval', (array) $rows );
		}
	}

	/**
	 * Drop the cached sheet index. Must be called whenever sheet rows move or
	 * vanish outside the flush's own appends — reset (tab wipe) and duplicate
	 * repair (row deletion) — or a long-lived worker would adopt against
	 * positions that no longer exist.
	 *
	 * @return void
	 */
	public static function invalidate_sheet_index() {
		self::$sheet_index_cache = [];
	}

	private function existing_rows_by_order_id( $client, array $config, array $columns ) {
		$idx = Brikpanel_Sheets_Mapping::column_index_map( $columns );
		if ( ! isset( $idx['order_id'] ) ) {
			return [];
		}
		$col   = self::col_letter( (int) $idx['order_id'] + 1 );
		$range = Brikpanel_Sheets_Client::a1_quote_tab( $config['tab'] ) . '!' . $col . '1:' . $col;

		try {
			$values = $client->values_get( $config['spreadsheet_id'], $range, 'UNFORMATTED_VALUE' );
		} catch ( Brikpanel_Sheets_Exception $e ) {
			// Best effort: a failed reconcile must not block the push. The
			// caller falls back to its stored numbers.
			Brikpanel_Sheets_Logger::log( 'orders', 'Row reconcile failed: ' . $e->getMessage(), $e->http_code );
			return [];
		}

		$map = [];
		foreach ( (array) $values as $i => $row ) {
			$cell = $row[0] ?? '';
			if ( is_string( $cell ) ) {
				$cell = trim( $cell );
			}
			if ( ! is_numeric( $cell ) ) {
				continue; // header row and any stray text
			}
			$oid = (int) $cell;
			if ( $oid <= 0 || (float) $oid !== (float) $cell ) {
				continue;
			}
			$map[ $oid ][] = $i + 1; // values_get starts at row 1
		}
		return $map;
	}

	private function extract_start_row( $resp ) {
		$range = (string) ( $resp['updates']['updatedRange'] ?? '' );
		if ( $range === '' ) {
			return 0;
		}
		// Strip tab prefix (everything up to and including the first !).
		$bang = strpos( $range, '!' );
		if ( $bang !== false ) {
			$range = substr( $range, $bang + 1 );
		}
		// "A12:F19" — pull first row number.
		if ( preg_match( '/^[A-Z]+(\d+)/', $range, $m ) ) {
			return (int) $m[1];
		}
		return 0;
	}

	/**
	 * Convert a 1-based column index to the corresponding A1 column letter.
	 *
	 * @param int $n
	 * @return string
	 */
	public static function col_letter( $n ) {
		$n = max( 1, (int) $n );
		$s = '';
		while ( $n > 0 ) {
			$n--;
			$s = chr( 65 + ( $n % 26 ) ) . $s;
			$n = (int) ( $n / 26 );
		}
		return $s;
	}
}
