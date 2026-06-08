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

	const OPT_ENABLED         = 'brikpanel_gs_orders_enabled';
	const OPT_REALTIME        = 'brikpanel_gs_orders_realtime';
	const OPT_TAB_NAME        = 'brikpanel_gs_orders_tab';
	const OPT_BULK_INTERVAL   = 'brikpanel_gs_orders_bulk_interval'; // off|hourly|every_4h|daily
	const OPT_BULK_SINCE      = 'brikpanel_gs_orders_bulk_since';
	const OPT_BULK_STATUSES   = 'brikpanel_gs_orders_bulk_statuses';
	const OPT_LAST_SYNC       = 'brikpanel_gs_orders_last_sync';

	// Two-way pull (Sheets → Woo). Only status is writable. See class docblock
	// in class-brikpanel-sheets-products-sync.php for why we limit writable
	// fields to one per entity instead of opening price/customer/etc.
	const OPT_PULL_ENABLED    = 'brikpanel_gs_orders_pull_enabled';
	const OPT_PULL_INTERVAL   = 'brikpanel_gs_orders_pull_interval'; // 2|5|15 (minutes)
	const OPT_LAST_PULL       = 'brikpanel_gs_orders_last_pull';

	const BATCH_SIZE          = 250;
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

	public static function bulk_statuses() {
		$opt = get_option( self::OPT_BULK_STATUSES, [ 'wc-processing', 'wc-completed' ] );
		if ( ! is_array( $opt ) || empty( $opt ) ) {
			$opt = [ 'wc-processing', 'wc-completed' ];
		}
		return array_values( array_unique( array_map( 'sanitize_key', $opt ) ) );
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
			[ 'label' => __( 'Sheets — flush new orders to Google Sheets', 'brikpanel' ) ]
		);
		Brikpanel_Cron::register_handler(
			self::HOOK_BULK_FLUSH,
			[ $this, 'handle_flush_bulk' ],
			[ 'label' => __( 'Sheets — scheduled bulk order export', 'brikpanel' ) ]
		);
		Brikpanel_Cron::register_handler(
			self::HOOK_UPDATE_ROWS,
			[ $this, 'handle_update_rows' ],
			[ 'label' => __( 'Sheets — update changed-status order rows', 'brikpanel' ) ]
		);
		Brikpanel_Cron::register_handler(
			self::HOOK_PULL,
			[ $this, 'handle_pull' ],
			[ 'label' => __( 'Sheets — pull order status changes from Google Sheets', 'brikpanel' ) ]
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
			'orderby'      => 'date',
			'order'        => 'ASC',
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
		$result = $this->flush( [
			'limit'        => self::BATCH_SIZE,
			'date_after'   => '@' . self::bulk_since_timestamp(),
			'orderby'      => 'date',
			'order'        => 'ASC',
			'statuses'     => self::bulk_statuses(),
		] );

		if ( ! empty( $result['more'] ) ) {
			// More rows pending — keep going. Use 'unique' so a second click
			// on "Sync now" while the first batch is still draining doesn't
			// pile up duplicate paging jobs.
			Brikpanel_Cron::enqueue_async( self::HOOK_BULK_FLUSH, [], [ 'unique' => true ] );
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

		foreach ( $ids as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				continue;
			}
			$row_map = $order->get_meta( self::META_ROW_MAP );
			$row_map = is_array( $row_map ) ? $row_map : [];
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
			$rows = $client->values_get( $config['spreadsheet_id'], $range, 'FORMATTED_VALUE' );
		} catch ( Brikpanel_Sheets_Exception $e ) {
			Brikpanel_Sheets_Logger::log( 'orders', 'pull values_get failed: ' . $e->getMessage(), $e->http_code );
			throw $e;
		}

		$checked    = 0;
		$applied    = 0;
		$conflicts  = 0;
		$seen_order = [];

		$valid_statuses = function_exists( 'wc_get_order_statuses' ) ? wc_get_order_statuses() : [];
		// wc_get_order_statuses returns keys with "wc-" prefix and labels as
		// values. We need a quick lookup that accepts both "processing" and
		// "wc-processing", and that maps a translated label back to the key
		// (merchants may have edited the sheet in a localised admin).
		$status_lookup = [];
		foreach ( $valid_statuses as $wc_key => $label ) {
			$bare = preg_replace( '/^wc-/', '', (string) $wc_key );
			$status_lookup[ strtolower( $bare ) ]       = $bare;
			$status_lookup[ strtolower( (string) $wc_key ) ] = $bare;
			$status_lookup[ strtolower( (string) $label ) ]  = $bare;
		}

		foreach ( $rows as $row ) {
			$order_id = isset( $row[ $pid_col ] ) ? (int) $row[ $pid_col ] : 0;
			if ( $order_id <= 0 ) { continue; }
			// One order = many rows (one per line item); only act on the
			// first occurrence — the status column repeats across the rest
			// and processing them again would just no-op or double-apply on
			// a freshly-changed status. First-row-wins matches what the
			// merchant sees when they scroll down the sheet.
			if ( isset( $seen_order[ $order_id ] ) ) { continue; }
			$seen_order[ $order_id ] = true;

			$sheet_status_raw = isset( $row[ $status_col ] ) ? (string) $row[ $status_col ] : '';
			$sheet_status     = trim( $sheet_status_raw );
			if ( $sheet_status === '' ) { continue; }

			// Normalise: accept "Processing", "wc-processing", "processing".
			$key = strtolower( $sheet_status );
			if ( isset( $status_lookup[ $key ] ) ) {
				$sheet_status = $status_lookup[ $key ];
			} else {
				// Unknown status — log once and skip (avoid spamming the log
				// on every poll for the same bad value, the user will see
				// the row in the sheet still showing their bad text).
				Brikpanel_Sheets_Logger::log( 'orders', 'Pull skipped order ' . $order_id . ': unknown status "' . $sheet_status_raw . '"' );
				continue;
			}

			$order = wc_get_order( $order_id );
			if ( ! $order ) { continue; }

			$checked++;

			$current_status = (string) $order->get_status();
			if ( $current_status === $sheet_status ) {
				continue; // already in sync
			}

			$last_pushed = (string) $order->get_meta( self::META_LAST_PUSHED_STATUS );
			if ( $last_pushed === '' ) {
				// Order was pushed before this version of the plugin (no
				// snapshot). Fall back to "treat any divergence as a Sheets
				// edit IF the sheet value isn't equal to the current Woo
				// value" — already handled by the != check above. Continue.
			} elseif ( $sheet_status === $last_pushed ) {
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

			// Apply. update_status() fires woocommerce_order_status_changed
			// which (a) triggers WC native side-effects (stock decrement,
			// email, etc.) and (b) calls our on_status_changed handler →
			// which queues HOOK_UPDATE_ROWS → which would race with the pull
			// trying to update the same row. To break that loop, set
			// META_LAST_PUSHED_STATUS to the NEW value first; the update_rows
			// pass that fires immediately after will see "already pushed
			// this status" and no-op on the sheet (but still update meta to
			// keep timestamps fresh).
			$order->update_meta_data( self::META_LAST_PUSHED_STATUS, $sheet_status );
			$order->update_meta_data( self::META_SYNCED_AT, current_time( 'mysql', true ) );
			$order->save_meta_data();

			$note = __( 'Status changed via Google Sheets sync.', 'brikpanel' );
			$order->update_status( $sheet_status, $note );
			$applied++;
		}

		update_option( self::OPT_LAST_PULL, [
			'ts'        => time(),
			'checked'   => $checked,
			'applied'   => $applied,
			'conflicts' => $conflicts,
		], false );

		return [ 'checked' => $checked, 'applied' => $applied, 'conflicts' => $conflicts ];
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
			return $empty;
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
		try {
			$headers     = Brikpanel_Sheets_Mapping::headers_for( 'orders', $columns );
			$validations = self::build_dropdown_validations( $columns );
			$client->ensure_tab( $config['spreadsheet_id'], $config['tab'], $headers, $validations );
		} catch ( Brikpanel_Sheets_Exception $e ) {
			Brikpanel_Sheets_Logger::log( 'orders', 'ensure_tab failed: ' . $e->getMessage(), $e->http_code );
			throw $e;
		}

		$wc_query = [
			'limit'        => (int) ( $query_args['limit'] ?? self::BATCH_SIZE ),
			'orderby'      => (string) ( $query_args['orderby'] ?? 'date' ),
			'order'        => (string) ( $query_args['order'] ?? 'ASC' ),
			'status'       => $query_args['statuses'] ?? self::bulk_statuses(),
			'paginate'     => false,
			'return'       => 'objects',
			'meta_query'   => [
				[ 'key' => self::META_SYNCED_AT, 'compare' => 'NOT EXISTS' ],
			],
		];
		if ( ! empty( $query_args['date_after'] ) ) {
			$wc_query['date_created'] = '>=' . $query_args['date_after'];
		}

		$orders = wc_get_orders( $wc_query );
		if ( empty( $orders ) ) {
			return $empty;
		}

		// Build a flat row list and remember per-order row spans so we can
		// record returned row numbers back to order meta.
		$flat_rows   = [];
		$order_spans = []; // [ order_id => [ count, line_item_ids[] ] ]
		foreach ( $orders as $order ) {
			$rows = $this->build_rows( $order, $columns );
			if ( empty( $rows ) ) {
				continue;
			}
			$order_spans[ $order->get_id() ] = [
				'count'         => count( $rows ),
				'line_item_ids' => array_keys( $rows ),
			];
			foreach ( $rows as $row ) {
				$flat_rows[] = $row;
			}
		}

		if ( empty( $flat_rows ) ) {
			return [ 'orders' => count( $orders ), 'rows' => 0, 'more' => false ];
		}

		try {
			$resp = $client->append_rows( $config['spreadsheet_id'], $config['tab'], $flat_rows );
		} catch ( Brikpanel_Sheets_Exception $e ) {
			Brikpanel_Sheets_Logger::log( 'orders', 'append_rows failed: ' . $e->getMessage(), $e->http_code );
			throw $e;
		}

		// values:append with INSERT_ROWS strips data-validation rules from
		// the freshly inserted rows. Reapply our order_status dropdown so
		// every appended row has it available. Best-effort: log and continue
		// on failure since the row data has already landed successfully.
		$validations_for_reapply = self::build_dropdown_validations( $columns );
		if ( ! empty( $validations_for_reapply ) ) {
			try {
				$sheet_id_for_reapply = null;
				foreach ( $client->list_sheets( $config['spreadsheet_id'] ) as $sid => $name ) {
					if ( strcasecmp( $name, $config['tab'] ) === 0 ) { $sheet_id_for_reapply = (int) $sid; break; }
				}
				if ( $sheet_id_for_reapply !== null ) {
					$client->apply_data_validation( $config['spreadsheet_id'], $sheet_id_for_reapply, $validations_for_reapply );
				}
			} catch ( \Throwable $e ) {
				Brikpanel_Sheets_Logger::log( 'orders', 'Validation reapply failed: ' . $e->getMessage() );
			}
		}

		// Parse updates.updatedRange — "Tab!A12:F19" — to get the starting row
		// number; assume the rows were inserted in our submitted order.
		$start_row = $this->extract_start_row( $resp );
		$row_cursor = $start_row > 0 ? $start_row : 0;

		foreach ( $order_spans as $order_id => $span ) {
			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				continue;
			}
			$row_map = [];
			if ( $row_cursor > 0 ) {
				foreach ( $span['line_item_ids'] as $i => $line_item_id ) {
					$row_map[ (string) $line_item_id ] = $row_cursor + $i;
				}
				$row_cursor += (int) $span['count'];
			}
			$order->update_meta_data( self::META_SYNCED_AT, current_time( 'mysql', true ) );
			$order->update_meta_data( self::META_ROW_MAP, $row_map );
			$order->update_meta_data( self::META_SPREADSHEET, $config['spreadsheet_id'] );
			$order->update_meta_data( self::META_TAB, $config['tab'] );
			$order->update_meta_data( self::META_LAST_PUSHED_STATUS, (string) $order->get_status() );
			$order->save();
		}

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
			case 'line_item_id':         return $item ? (int) $item->get_id() : 0;
			case 'product_id':           return $item ? (int) $item->get_product_id() : 0;
			case 'variation_id':         return $item ? (int) $item->get_variation_id() : 0;
			case 'product_sku':          return $product ? (string) $product->get_sku() : '';
			case 'product_name':         return $item ? (string) $item->get_name() : '';
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
			case 'quantity':             return $item ? (float) $item->get_quantity() : 0;
			case 'unit_price':           return ( $item && $item->get_quantity() > 0 ) ? round( (float) $item->get_subtotal() / (float) $item->get_quantity(), 4 ) : 0;
			case 'line_subtotal':        return $item ? (float) $item->get_subtotal() : 0;
			case 'line_tax':             return $item ? (float) $item->get_total_tax() : 0;
			case 'line_total':           return $item ? (float) $item->get_total() : 0;
		}
		return '';
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
