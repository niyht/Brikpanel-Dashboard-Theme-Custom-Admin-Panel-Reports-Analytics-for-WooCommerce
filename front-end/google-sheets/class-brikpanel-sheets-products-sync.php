<?php
/**
 * BrikPanel — Sheets product sync (Woo → Sheets push + Sheets → Woo pull).
 *
 * Two-way sync for products. The forward direction (Woo → Sheets) keeps a
 * Products tab populated with id/sku/name/price/stock/status; the reverse
 * direction (Sheets → Woo) polls the same tab every N minutes and writes any
 * detected stock changes back to WooCommerce.
 *
 * Conflict resolution: last-write-wins. We snapshot the row each push and
 * stash a hash on product meta. On pull we treat a cell as "changed in
 * Sheets" only if (a) the sheet value differs from the snapshot AND (b) the
 * sheet's edit is newer than Woo's _date_modified for that product. The
 * second clause keeps a stale poll from clobbering a fresh Woo change.
 *
 * Variation handling: every variation = its own row, parent_id column points
 * to the parent product. Simple products only emit one row. Variable parents
 * (without their own stock) emit one read-only summary row so the merchant
 * sees the product even if all stock lives on variations.
 *
 * Reverse-writable columns (Sheets → Woo): stock, stock_status,
 * regular_price, sale_price, cogs and status. A cell is only written back
 * when the merchant has mapped that column AND the last-write-wins guard
 * above confirms the sheet edit is both different from the snapshot and
 * newer than Woo's _date_modified. Every other product field (name,
 * descriptions, structural data) is deliberately one-way: it is rebuilt
 * from Woo on the next push pass and any Sheets edit to it is overwritten,
 * since two-way write there would explode the surface area (currency
 * conversion, tax recalculation, translation/HTML conflicts) for marginal
 * value. The Google Sheet is an admin-configured, OAuth-authenticated
 * trusted source; granting a collaborator edit access to that sheet grants
 * them control over the reverse-writable columns above.
 *
 * @package BrikPanel
 * @since   3.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Brikpanel_Sheets_Products_Sync {

	const HOOK_PUSH_FLUSH = 'brikpanel_gs_products_push';
	const HOOK_PULL       = 'brikpanel_gs_products_pull';

	const META_ROW           = '_brikpanel_gs_product_row';        // sheet row number (int, 1-based)
	const META_SYNCED_AT     = '_brikpanel_gs_product_synced_at';  // last push UTC mysql ts
	const META_SNAPSHOT_HASH = '_brikpanel_gs_product_snapshot';   // sha256 of last-pushed row

	const OPT_ENABLED       = 'brikpanel_gs_products_enabled';
	const OPT_TAB_NAME      = 'brikpanel_gs_products_tab';
	const OPT_PULL_ENABLED  = 'brikpanel_gs_products_pull_enabled';
	const OPT_PULL_INTERVAL = 'brikpanel_gs_products_pull_interval'; // 2|5|15 (minutes)
	const OPT_LAST_PUSH     = 'brikpanel_gs_products_last_push';
	const OPT_LAST_PULL     = 'brikpanel_gs_products_last_pull';
	const OPT_PUSH_QUEUE    = 'brikpanel_gs_products_push_queue';   // {product_id => 1} pending push
	const OPT_CATEGORIES    = 'brikpanel_gs_products_categories';   // int[] product_cat term IDs; [] = sync everything
	const OPT_REBUILD       = 'brikpanel_gs_products_rebuild';      // { offset:int, ts:int } in-flight full rebuild cursor

	/** A rebuild cursor older than this is treated as abandoned, so the next
	 *  "Sync now" starts a clean rebuild instead of resuming a dead run. */
	const REBUILD_STATE_TTL = 900;

	const BATCH_SIZE        = 250;
	const PUSH_DEBOUNCE_SEC = 5;
	const PUSH_LOCK         = 'brikpanel_gs_products_push_lock';
	const PULL_LOCK         = 'brikpanel_gs_products_pull_lock';
	const LOCK_TTL          = 300;

	public function __construct() {
		add_action( 'init',                     [ $this, 'maybe_attach_hooks' ], 30 );
		add_action( 'brikpanel_cron_register',  [ $this, 'register_handlers' ] );
	}

	// =========================================================================
	// Configuration helpers
	// =========================================================================

	public static function is_enabled() {
		return get_option( self::OPT_ENABLED, 'no' ) === 'yes';
	}

	public static function pull_enabled() {
		return get_option( self::OPT_PULL_ENABLED, 'no' ) === 'yes';
	}

	public static function tab_name() {
		$name = (string) get_option( self::OPT_TAB_NAME, 'Products' );
		return $name !== '' ? $name : 'Products';
	}

	/**
	 * Pull cadence in seconds. Clamped to one of the offered values so a stray
	 * option write can't accidentally schedule a sub-minute or multi-hour
	 * cadence the user never picked.
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

	/**
	 * Product-category term IDs the merchant chose to sync. An empty array is
	 * the historical default and means "no filter" — every product is eligible.
	 * Stored as term IDs (stable across category renames).
	 *
	 * @return int[]
	 */
	public static function category_filter_ids() {
		$ids = get_option( self::OPT_CATEGORIES, [] );
		if ( ! is_array( $ids ) ) {
			return [];
		}
		return array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
	}

	/**
	 * The selected categories expanded to include every descendant category, so
	 * picking a parent category also pulls in products filed under its
	 * sub-categories. This mirrors the include_children behaviour of the
	 * tax_query the bulk product query runs, keeping the event-driven push and
	 * the "Sync now" bulk push in agreement about what belongs in the sheet.
	 *
	 * @return int[]
	 */
	private function category_filter_match_ids() {
		$base = self::category_filter_ids();
		if ( empty( $base ) ) {
			return [];
		}
		$all = $base;
		foreach ( $base as $cat_id ) {
			$children = get_term_children( $cat_id, 'product_cat' );
			if ( ! is_wp_error( $children ) && ! empty( $children ) ) {
				foreach ( $children as $child_id ) {
					$all[] = (int) $child_id;
				}
			}
		}
		return array_values( array_unique( $all ) );
	}

	/**
	 * Whether a product should be synced under the active category filter.
	 * Variations carry no category terms of their own, so we test the parent's
	 * categories for them. With no filter set, every product matches.
	 *
	 * @param WC_Product $product
	 * @return bool
	 */
	private function product_matches_category( $product ) {
		$match_ids = $this->category_filter_match_ids();
		if ( empty( $match_ids ) ) {
			return true;
		}
		$parent_id = (int) $product->get_parent_id();
		$check_id  = $parent_id > 0 ? $parent_id : (int) $product->get_id();
		if ( $check_id <= 0 ) {
			return false;
		}
		$terms = wc_get_product_term_ids( $check_id, 'product_cat' );
		if ( empty( $terms ) ) {
			return false;
		}
		return (bool) array_intersect( $match_ids, array_map( 'intval', $terms ) );
	}

	/**
	 * Cursor of a full rebuild that is still draining, or null when no
	 * rebuild is in flight.
	 *
	 * A rebuild wipes the target tab and re-appends the whole catalogue one
	 * batch at a time. Those batches are spread over several short requests
	 * (the browser asks for the next one) and, as a fallback, over background
	 * jobs. Both paths need to know where the previous batch stopped:
	 * without that, every pass would wipe the tab and re-push the same first
	 * batch forever.
	 *
	 * The cursor also records WHICH spreadsheet and tab it was paging into. A
	 * cursor is only ever resumed against that same destination: disconnecting
	 * Google, picking a different spreadsheet or renaming the target tab all
	 * point it at a sheet whose contents it never wrote, where resuming would
	 * skip every product before the stored offset.
	 *
	 * @return array{offset:int, ts:int}|null
	 */
	public static function rebuild_state() {
		$state = get_option( self::OPT_REBUILD, null );
		if ( ! is_array( $state ) || ! isset( $state['offset'], $state['ts'] ) ) {
			return null;
		}
		if ( ( time() - (int) $state['ts'] ) > self::REBUILD_STATE_TTL ) {
			// Abandoned run (tab closed, worker died). Forget it so the next
			// manual sync rebuilds from scratch rather than resuming into a
			// tab whose contents we can no longer reason about.
			delete_option( self::OPT_REBUILD );
			return null;
		}
		$target = self::resolve_active_target();
		if ( ! $target
			|| ! Brikpanel_Sheets_Tokens::is_connected()
			|| (string) ( $state['sheet'] ?? '' ) !== (string) $target['spreadsheet_id']
			|| (string) ( $state['tab'] ?? '' ) !== (string) $target['tab'] ) {
			delete_option( self::OPT_REBUILD );
			return null;
		}
		return [ 'offset' => (int) $state['offset'], 'ts' => (int) $state['ts'] ];
	}

	private static function set_rebuild_state( $offset ) {
		$target = self::resolve_active_target();
		update_option( self::OPT_REBUILD, [
			'offset' => max( 0, (int) $offset ),
			'ts'     => time(),
			'sheet'  => $target ? (string) $target['spreadsheet_id'] : '',
			'tab'    => $target ? (string) $target['tab'] : '',
		], false );
	}

	private static function clear_rebuild_state() {
		delete_option( self::OPT_REBUILD );
	}

	/**
	 * Retire the rebuild cursor once the whole catalogue is in the tab.
	 *
	 * Product edits that happened while the rebuild was running were parked in
	 * the push queue rather than written into a half-built tab, so hand them to
	 * a normal push now. Without this they would sit in the queue until the
	 * merchant happened to edit another product, and the sheet would show a
	 * stale value for exactly the item they had just changed.
	 */
	private static function finish_rebuild() {
		self::clear_rebuild_state();
		$queue = (array) get_option( self::OPT_PUSH_QUEUE, [] );
		if ( ! empty( $queue ) && class_exists( 'Brikpanel_Cron' ) ) {
			Brikpanel_Cron::enqueue_async( self::HOOK_PUSH_FLUSH, [], [ 'unique' => false ] );
		}
	}

	/**
	 * Give up on a rebuild that is still draining, and stop its paging jobs.
	 *
	 * Called when something the rebuild depends on changes underneath it (the
	 * category filter, the column layout, a manual reset). Resuming after such
	 * a change would fill the rest of the tab with rows built to different
	 * rules than the ones already in it, so the next "Sync now" has to start
	 * from a clean tab instead.
	 */
	public static function abandon_rebuild() {
		if ( self::rebuild_state() === null ) {
			return;
		}
		self::clear_rebuild_state();
		if ( class_exists( 'Brikpanel_Cron' ) && Brikpanel_Cron::is_available() ) {
			Brikpanel_Cron::cancel( self::HOOK_PUSH_FLUSH );
		}
		Brikpanel_Sheets_Logger::log( 'products', 'Settings changed mid-rebuild, the paging cursor was dropped so the next sync rebuilds the tab from scratch.' );
	}

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

		// Stock change hooks for both simple and variation products.
		// `woocommerce_product_set_stock` fires for both; we also wire the
		// `_stock` meta hook as a belt-and-braces catch for direct meta
		// writes from admin/REST that bypass the WC setter.
		add_action( 'woocommerce_product_set_stock',           [ $this, 'on_product_stock_changed' ], 10, 1 );
		add_action( 'woocommerce_variation_set_stock',         [ $this, 'on_product_stock_changed' ], 10, 1 );
		add_action( 'woocommerce_product_set_stock_status',    [ $this, 'on_product_stock_changed' ], 10, 1 );
		add_action( 'woocommerce_variation_set_stock_status',  [ $this, 'on_product_stock_changed' ], 10, 1 );
		// New product / general save — re-push so newly added items appear.
		add_action( 'woocommerce_new_product',     [ $this, 'on_product_event' ], 10, 1 );
		add_action( 'woocommerce_update_product',  [ $this, 'on_product_event' ], 10, 1 );
		add_action( 'woocommerce_new_product_variation',    [ $this, 'on_product_event' ], 10, 1 );
		add_action( 'woocommerce_update_product_variation', [ $this, 'on_product_event' ], 10, 1 );
	}

	public function register_handlers() {
		Brikpanel_Cron::register_handler(
			self::HOOK_PUSH_FLUSH,
			[ $this, 'handle_push' ],
			static function () { return [ 'label' => __( 'Sheets — push product changes to Google Sheets', 'brikpanel' ) ]; }
		);
		Brikpanel_Cron::register_handler(
			self::HOOK_PULL,
			[ $this, 'handle_pull' ],
			static function () { return [ 'label' => __( 'Sheets — pull product changes from Google Sheets', 'brikpanel' ) ]; }
		);

		// Schedule the pull only when both the flow AND the pull half are on.
		// Push has no standalone schedule — it's event-driven via product hooks
		// (debounced through enqueue_async) plus the manual "Sync now" button.
		if ( self::is_enabled() && self::pull_enabled() ) {
			Brikpanel_Cron::schedule_recurring( self::HOOK_PULL, self::pull_interval_seconds(), [] );
		} else {
			Brikpanel_Cron::cancel( self::HOOK_PULL );
		}
	}

	// =========================================================================
	// WC hook entry points
	// =========================================================================

	public function on_product_stock_changed( $product ) {
		$id = $this->extract_product_id( $product );
		if ( $id > 0 ) {
			$this->queue_push( $id );
		}
	}

	public function on_product_event( $product_id ) {
		$product_id = (int) $product_id;
		if ( $product_id > 0 ) {
			$this->queue_push( $product_id );
		}
	}

	private function extract_product_id( $maybe_product ) {
		if ( is_object( $maybe_product ) && method_exists( $maybe_product, 'get_id' ) ) {
			return (int) $maybe_product->get_id();
		}
		return (int) $maybe_product;
	}

	/**
	 * Add a product to the pending-push queue and schedule a debounced flush.
	 *
	 * The queue is a simple {product_id => 1} option so bursts of stock
	 * changes (e.g. a bulk-update from an inventory CSV importer) coalesce
	 * into a single Sheets write instead of N individual API calls.
	 */
	private function queue_push( $product_id ) {
		$product_id = (int) $product_id;
		if ( $product_id <= 0 ) {
			return;
		}
		$queue = (array) get_option( self::OPT_PUSH_QUEUE, [] );
		$queue[ (string) $product_id ] = 1;
		update_option( self::OPT_PUSH_QUEUE, $queue, false );

		Brikpanel_Cron::schedule_single(
			time() + self::PUSH_DEBOUNCE_SEC,
			self::HOOK_PUSH_FLUSH,
			[],
			[ 'unique' => true ]
		);
	}

	// =========================================================================
	// Forward direction: PUSH (Woo → Sheets)
	// =========================================================================

	/**
	 * Push pending product rows to Sheets.
	 *
	 * Two cases:
	 *  - Product has no prior `_brikpanel_gs_product_row` → append.
	 *  - Product already has a row → values_update in place.
	 *
	 * Append vs update is decided per-product, but we batch each kind into one
	 * API call to keep within Sheets quota even on large product catalogues.
	 *
	 * @param array $args { force_all: bool } — bulk export sets force_all=true.
	 * @return array{appended:int, updated:int, more:bool}
	 */
	public function handle_push( $args = [] ) {
		$empty = [ 'appended' => 0, 'updated' => 0, 'more' => false ];
		if ( ! self::is_enabled() || ! Brikpanel_Sheets_Tokens::is_connected() ) {
			return $empty;
		}
		$config = self::resolve_active_target();
		if ( ! $config ) {
			return $empty;
		}

		// A rebuild takes the lock before it wipes the tab and hands it to us
		// still held, so no other push can slip rows into the tab between the
		// wipe and the first append. Re-taking it here would be wrong twice
		// over: we would refuse our own caller, and the inner release would
		// free the lock while the rebuild is still writing.
		if ( ! empty( $args['_lock_held'] ) ) {
			return $this->push_locked( (array) $args, $config, $empty );
		}

		if ( get_transient( self::PUSH_LOCK ) ) {
			Brikpanel_Sheets_Logger::log( 'products', 'Skipping push, another push is in progress.' );
			// Flagged, not silently empty: an interactive drain must be able to
			// tell "the catalogue is fully synced" apart from "someone else is
			// holding the lock", or it would stop paging half-way through a
			// rebuild and report success.
			return $empty + [ 'locked' => true ];
		}
		set_transient( self::PUSH_LOCK, time(), self::LOCK_TTL );

		try {
			return $this->push_locked( (array) $args, $config, $empty );
		} finally {
			delete_transient( self::PUSH_LOCK );
		}
	}

	/**
	 * Claim the next slice of a rebuild and push it.
	 *
	 * The offset a rebuild batch works on is taken from the shared cursor
	 * HERE, inside the push lock, and reserved before any work starts. Two
	 * runners can legitimately be alive at once (the browser drives the
	 * interactive drain while a background paging job is still winding down),
	 * and if both had trusted the offset in their own arguments they would
	 * append the SAME slice twice, and the sheet then holds duplicate rows for
	 * every product in it. Claiming under the lock makes that impossible:
	 * whoever gets the lock second sees the advanced cursor.
	 */
	private function push_locked( array $args, array $config, array $empty ) {
		if ( empty( $args['rebuild'] ) ) {
			if ( empty( $args['force_all'] ) && self::rebuild_state() !== null ) {
				// A full rebuild is re-appending the whole catalogue right now,
				// and the tab is only half built. Pushing a queued product on
				// top of that would reconcile against an incomplete sheet, not
				// find the product, and append a row the rebuild is about to
				// append again. Leave it in the queue: the rebuild drains it
				// once the tab is whole.
				return $empty + [ 'deferred' => true ];
			}
			return $this->push_slice( $args, $config, $empty );
		}

		$state = self::rebuild_state();
		if ( $state === null ) {
			// The rebuild this batch belongs to already finished (or was
			// abandoned). Pushing its slice now would append rows a completed
			// rebuild has already written.
			return $empty;
		}

		$offset         = (int) $state['offset'];
		$args['offset'] = $offset;
		self::set_rebuild_state( $offset + self::BATCH_SIZE );

		try {
			return $this->push_slice( $args, $config, $empty );
		} catch ( \Throwable $e ) {
			// Hand the slice back so the retry re-pushes it instead of
			// silently skipping a page of the catalogue.
			self::set_rebuild_state( $offset );
			throw $e;
		}
	}

	private function push_slice( array $args, array $config, array $empty ) {
		$client  = new Brikpanel_Sheets_Client();
		$columns = Brikpanel_Sheets_Mapping::get_columns( 'products' );
		$headers = Brikpanel_Sheets_Mapping::headers_for( 'products', $columns );

		try {
			$validations = self::build_dropdown_validations( $columns );
			$client->ensure_tab( $config['spreadsheet_id'], $config['tab'], $headers, $validations );
		} catch ( Brikpanel_Sheets_Exception $e ) {
			Brikpanel_Sheets_Logger::log( 'products', 'ensure_tab failed: ' . $e->getMessage(), $e->http_code );
			throw $e;
		}

		$force_all = ! empty( $args['force_all'] );
		// A rebuild ("Sync now") re-appends every product into a freshly cleared
		// tab so the result is contiguous and matches the current filter exactly.
		// We page through the catalogue by offset and always append (never reuse
		// a product's old absolute row), which is what prevents the sparse "rows
		// 1-59 empty, 60-68 filled" layout left behind by a narrowed filter.
		$rebuild = ! empty( $args['rebuild'] );
		$offset  = isset( $args['offset'] ) ? max( 0, (int) $args['offset'] ) : 0;

		$ids = [];
		if ( $force_all ) {
			$ids = $this->all_syncable_product_ids( self::BATCH_SIZE, $offset );
		} else {
			$queue = (array) get_option( self::OPT_PUSH_QUEUE, [] );
			$ids   = array_map( 'intval', array_keys( $queue ) );
			$ids   = array_slice( $ids, 0, self::BATCH_SIZE );
		}

		if ( empty( $ids ) ) {
			// Nothing left to page through: the rebuild is finished (or the
			// catalogue is empty). Retire the cursor so the next "Sync now"
			// starts a fresh rebuild instead of resuming past the end.
			if ( $rebuild ) {
				self::finish_rebuild();
			}
			return $empty;
		}

		$products = $this->expand_to_syncable_products( $ids );

		// Reconcile against the rows already in the sheet so a product whose
		// stored row number was lost still updates its existing row instead of
		// appending a duplicate. This is what prevents the "same variation twice"
		// rows that show up when an append's HTTP response is lost to a timeout
		// (Google wrote the row, we never recorded its number) or when a rebuild
		// was interrupted mid-way. Skipped on a rebuild — the tab was just
		// cleared, so every product is genuinely new and there is nothing to
		// reconcile against. Variations and their parents are keyed the same way
		// (by product_id), so this protects variable products as well as simple.
		$id_to_row = $rebuild ? [] : $this->existing_rows_by_product_id( $client, $config, $columns );

		$to_append = [];
		$to_update = []; // [ row_number => row_array ]
		$snapshot_assignments = []; // [ product_id => row_array ]

		foreach ( $products as $product ) {
			$pid     = (int) $product->get_id();
			$row     = $this->build_row( $product, $columns );
			// On a rebuild the tab was just cleared, so any stored row number is
			// stale — force an append and let the product pick up a fresh,
			// contiguous row below.
			$existing_row = $rebuild ? 0 : (int) $this->get_product_meta( $product, self::META_ROW );

			// The sheet is authoritative, not our stored number. A stored row
			// only stayed correct while nobody touched the tab; sorting it or
			// inserting/deleting a row shifts everything below and the stored
			// number then points at a DIFFERENT product, which we would happily
			// overwrite. Consulting the reconcile map only when the stored row
			// was missing (as this used to) left exactly that case unguarded.
			// $id_to_row is empty on a rebuild (tab just cleared) or if the
			// reconcile read failed, so only trust it when we actually have it.
			if ( ! $rebuild && ! empty( $id_to_row ) ) {
				if ( isset( $id_to_row[ $pid ] ) ) {
					if ( $existing_row !== (int) $id_to_row[ $pid ] ) {
						$existing_row = (int) $id_to_row[ $pid ];
						$this->set_product_meta( $product, self::META_ROW, $existing_row );
					}
				} elseif ( $existing_row > 0 ) {
					// We think it has a row but the sheet says it is not there.
					// Append it fresh rather than write over the current tenant.
					$existing_row = 0;
					$this->set_product_meta( $product, self::META_ROW, 0 );
				}
			} elseif ( $existing_row <= 0 && isset( $id_to_row[ $pid ] ) ) {
				$existing_row = (int) $id_to_row[ $pid ];
				$this->set_product_meta( $product, self::META_ROW, $existing_row );
			}
			if ( $existing_row > 0 ) {
				$to_update[ $existing_row ] = [ 'pid' => $pid, 'row' => $row ];
			} else {
				$to_append[] = [ 'pid' => $pid, 'row' => $row ];
			}
			$snapshot_assignments[ $pid ] = $row;
		}

		$appended_count = 0;
		$updated_count  = 0;
		$failed_pids    = []; // [ product_id => true ] rows whose write threw

		// Updates first (single batchUpdate via values:batchUpdate isn't
		// strictly needed — per-row writes are cheap when batched by AS).
		if ( ! empty( $to_update ) ) {
			$end_col = self::col_letter( count( $columns ) );
			foreach ( $to_update as $row_num => $info ) {
				$range = Brikpanel_Sheets_Client::a1_quote_tab( $config['tab'] )
					. '!A' . (int) $row_num . ':' . $end_col . (int) $row_num;
				try {
					$client->values_update( $config['spreadsheet_id'], $range, [ $info['row'] ] );
					$updated_count++;
				} catch ( Brikpanel_Sheets_Exception $e ) {
					Brikpanel_Sheets_Logger::log( 'products', 'Row update failed for product ' . $info['pid'] . ': ' . $e->getMessage(), $e->http_code );
					// Continue with the rest — don't let one bad row tank the
					// batch — but remember the failure so we do not record a
					// snapshot for a row that never reached the sheet.
					$failed_pids[ (int) $info['pid'] ] = true;
				}
			}
		}

		// Appends in one call, then map returned start_row back to per-product
		// row numbers (same pattern as the order sync).
		if ( ! empty( $to_append ) ) {
			$flat = [];
			foreach ( $to_append as $info ) { $flat[] = $info['row']; }
			try {
				$resp = $client->append_rows( $config['spreadsheet_id'], $config['tab'], $flat );
				$start = $this->extract_start_row( $resp );
				$cursor = $start > 0 ? $start : 0;
				if ( $cursor > 0 ) {
					foreach ( $to_append as $i => $info ) {
						$product = wc_get_product( $info['pid'] );
						if ( ! $product ) { continue; }
						$this->set_product_meta( $product, self::META_ROW, (int) ( $cursor + $i ) );
					}
					$appended_count = count( $to_append );
				}
				// values:append with insertDataOption=INSERT_ROWS strips any
				// existing data-validation rules from the newly inserted rows
				// (the new rows fall outside the rule's frozen range). Reapply
				// our dropdowns so the merchant always gets a working dropdown
				// in the freshly-pushed rows. Only paid on append, not on the
				// (more common) update-in-place path.
				if ( ! empty( $validations ) ) {
					$this->reapply_validations( $client, $config, $validations );
				}
			} catch ( Brikpanel_Sheets_Exception $e ) {
				Brikpanel_Sheets_Logger::log( 'products', 'append_rows failed: ' . $e->getMessage(), $e->http_code );
				throw $e;
			}
		}

		// Persist snapshot + synced_at only for rows that actually landed.
		// Recording a snapshot for a write that failed (a 429 that exhausted
		// its retries, a 403) tells the reverse pull "the sheet holds this row"
		// when it does not: the pull then compares the merchant's real cell
		// against a snapshot that never matched it, flags a false conflict, and
		// the sheet stays permanently out of step with Woo. Leaving the failed
		// products without a snapshot makes the next push retry them, which is
		// the behaviour a merchant expects from a transient API error.
		$now_mysql = current_time( 'mysql', true );
		foreach ( $snapshot_assignments as $pid => $row ) {
			if ( isset( $failed_pids[ (int) $pid ] ) ) { continue; }
			$product = wc_get_product( $pid );
			if ( ! $product ) { continue; }
			$this->set_product_meta( $product, self::META_SYNCED_AT, $now_mysql );
			$this->set_product_meta( $product, self::META_SNAPSHOT_HASH, self::hash_row( $row ) );
		}
		if ( ! empty( $failed_pids ) ) {
			Brikpanel_Sheets_Logger::log(
				'products',
				count( $failed_pids ) . ' product row(s) failed to write and were left unsynced so the next push retries them.'
			);
		}

		if ( ! $force_all ) {
			// Drop processed IDs from queue.
			$queue = (array) get_option( self::OPT_PUSH_QUEUE, [] );
			foreach ( $ids as $pid ) {
				unset( $queue[ (string) $pid ] );
			}
			update_option( self::OPT_PUSH_QUEUE, $queue, false );
			$more = ! empty( $queue );
		} else {
			$more = count( $ids ) >= self::BATCH_SIZE;

			// Record where the next slice starts so the interactive "Sync now"
			// passes (and any background continuation) resume instead of
			// restarting the rebuild from the top.
			if ( $rebuild ) {
				if ( $more ) {
					self::set_rebuild_state( $offset + self::BATCH_SIZE );
				} else {
					self::finish_rebuild();
				}
			}

			// Chain the next slice ourselves so a catalogue larger than one batch
			// keeps draining instead of stalling after the first page. The offset
			// advances by a full batch; the rebuild flag rides along so every
			// page stays append-only and contiguous.
			//
			// The interactive path drives its own continuation from the browser
			// and passes defer=false, so a background job cannot race it for the
			// push lock and write the same slice twice.
			$defer = ! isset( $args['defer'] ) || ! empty( $args['defer'] );
			if ( $more && $defer && class_exists( 'Brikpanel_Cron' ) ) {
				// NOT unique. Action Scheduler's uniqueness test matches on
				// hook + group only, it ignores args, and it counts the
				// currently in-progress action. A unique enqueue from inside a
				// running batch therefore always lost to the batch itself and
				// the chain died after one page, which is what capped a large
				// catalogue at a single batch of rows. Runaway is not a risk
				// here: each run enqueues at most one successor and the offset
				// strictly increases until a short page ends the chain.
				Brikpanel_Cron::enqueue_async(
					self::HOOK_PUSH_FLUSH,
					[ 'force_all' => true, 'rebuild' => $rebuild, 'offset' => $offset + self::BATCH_SIZE ],
					[ 'unique' => false ]
				);
			}
		}

		update_option( self::OPT_LAST_PUSH, [
			'ts'       => time(),
			'rows'     => $appended_count + $updated_count,
			'appended' => $appended_count,
			'updated'  => $updated_count,
		], false );

		return [ 'appended' => $appended_count, 'updated' => $updated_count, 'more' => $more ];
	}

	/**
	 * Re-apply data validation rules to the target tab. Used after
	 * append_rows + INSERT_ROWS, which strips per-cell validation from the
	 * newly inserted rows. Best-effort: failures here are logged but do not
	 * block the push (the row data is already in place).
	 *
	 * @param Brikpanel_Sheets_Client $client
	 * @param array                   $config       { spreadsheet_id, tab }
	 * @param array                   $validations  As returned by build_dropdown_validations.
	 */
	private function reapply_validations( $client, array $config, array $validations ) {
		try {
			$sheet_id = null;
			foreach ( $client->list_sheets( $config['spreadsheet_id'] ) as $sid => $name ) {
				if ( strcasecmp( $name, $config['tab'] ) === 0 ) { $sheet_id = (int) $sid; break; }
			}
			if ( $sheet_id !== null ) {
				$client->apply_data_validation( $config['spreadsheet_id'], $sheet_id, $validations );
			}
		} catch ( \Throwable $e ) {
			Brikpanel_Sheets_Logger::log( 'products', 'Validation reapply failed: ' . $e->getMessage() );
		}
	}

	/**
	 * Build the validation rules passed to ensure_tab so the stock_status
	 * column gets a dropdown of WooCommerce's three stock states. Skipped if
	 * stock_status is not in the user's column mapping.
	 *
	 * @param string[] $columns Active column selection in display order.
	 * @return array Validation entries for Brikpanel_Sheets_Client::ensure_tab.
	 */
	public static function build_dropdown_validations( array $columns ) {
		$col_map = Brikpanel_Sheets_Mapping::column_index_map( $columns );
		$out     = [];

		if ( isset( $col_map['stock_status'] ) ) {
			// wc_get_product_stock_status_options() returns the canonical
			// instock/outofstock/onbackorder keys keyed by their labels. Newer WC
			// versions may add a fourth key; pulling dynamically keeps us in sync.
			$keys = function_exists( 'wc_get_product_stock_status_options' )
				? array_keys( wc_get_product_stock_status_options() )
				: [ 'instock', 'outofstock', 'onbackorder' ];
			$out[] = [
				'column_index' => (int) $col_map['stock_status'],
				'values'       => $keys,
				'strict'       => true,
			];
		}

		if ( isset( $col_map['status'] ) ) {
			// Helper dropdown for the publish state. Non-strict: a store may run
			// custom post statuses (subscriptions, 3rd-party workflows) that we
			// still push faithfully, so the sheet must accept values outside the
			// list rather than reject them. The reverse pull validates anyway.
			$out[] = [
				'column_index' => (int) $col_map['status'],
				'values'       => [ 'publish', 'draft', 'pending', 'private' ],
				'strict'       => false,
			];
		}

		return $out;
	}

	/**
	 * Public entry point for the "Sync now" / "Reset & re-push" admin button.
	 * Pages itself through AS if more rows remain.
	 */
	public function handle_push_bulk( $args = [] ) {
		$args = (array) $args;
		$args['force_all'] = true;

		$can_rebuild = self::is_enabled()
			&& Brikpanel_Sheets_Tokens::is_connected()
			&& self::resolve_active_target();

		if ( ! $can_rebuild ) {
			return $this->handle_push( $args );
		}

		// A rebuild that is still draining must be RESUMED, never restarted.
		// "Sync now" runs in short passes (the browser asks for the next one),
		// and each pass lands here. Wiping the tab on every pass would throw
		// away the rows the previous pass just wrote and the sync could never
		// get past its first batch.
		$state = self::rebuild_state();
		if ( $state !== null ) {
			$args['rebuild'] = true;
			$args['offset']  = (int) $state['offset'];
			return $this->handle_push( $args );
		}

		// Never wipe a tab we are not going to refill. Another push holding the
		// lock (a background batch, or a stale lock left by a request that was
		// killed mid-flight) used to be discovered only AFTER the clear: the
		// merchant was left with an empty sheet and a "0 rows" message, and
		// every retry inside the lock window wiped it again. Check first, then
		// HOLD the lock across the wipe and the first slice. Checking without
		// holding left a window between the wipe and the first append in which
		// a queued event-driven push could reconcile against the empty tab and
		// append rows the rebuild was about to append again, so the sheet came
		// out with every product listed twice.
		if ( get_transient( self::PUSH_LOCK ) ) {
			return [ 'appended' => 0, 'updated' => 0, 'more' => true, 'locked' => true ];
		}
		set_transient( self::PUSH_LOCK, time(), self::LOCK_TTL );

		try {
			return $this->start_rebuild( $args );
		} finally {
			delete_transient( self::PUSH_LOCK );
		}
	}

	/**
	 * Wipe the target tab and push the first slice of a fresh rebuild.
	 * Runs with the push lock already held by handle_push_bulk().
	 *
	 * @param array $args
	 * @return array
	 */
	private function start_rebuild( array $args ) {
		// Drop any paging job left over from an earlier run so it cannot append
		// its old offset into the tab we are about to rebuild.
		if ( class_exists( 'Brikpanel_Cron' ) ) {
			Brikpanel_Cron::cancel( self::HOOK_PUSH_FLUSH );
		}

		// "Sync now" is a full rebuild. Wipe the target tab and forget every
		// stored row number first, so the push re-appends each product into a
		// clean, contiguous sheet that reflects the current category filter and
		// column choice exactly. Without this, products keep the absolute rows
		// they were given on an earlier (wider) sync, leaving large empty gaps
		// once the synced set shrinks.
		if ( ! Brikpanel_Sheets_Settings::clear_products_target_tab() ) {
			// The wipe failed (rate limit, connection). Appending now would
			// duplicate every row that is still sitting in the tab, so stop.
			return [ 'appended' => 0, 'updated' => 0, 'more' => false, 'clear_failed' => true ];
		}
		self::clear_row_tracking_meta();
		delete_option( self::OPT_PUSH_QUEUE );
		$args['rebuild'] = true;
		$args['offset']  = 0;
		self::set_rebuild_state( 0 );

		// handle_push() chains its own continuation batches via Action Scheduler
		// unless the caller drives the paging itself (defer=false). The lock is
		// ours and stays ours until handle_push_bulk() releases it.
		$args['_lock_held'] = true;
		return $this->handle_push( $args );
	}

	// =========================================================================
	// Reverse direction: PULL (Sheets → Woo)
	// =========================================================================

	/**
	 * Read the Products tab, find rows where stock differs from current Woo
	 * state AND from our stored snapshot (i.e. the change happened in Sheets,
	 * not Woo), and apply each one as a Woo stock update.
	 *
	 * Last-write-wins: if Woo's `_date_modified` is newer than our last push
	 * for that product, we assume Woo is the source of truth, ignore the
	 * Sheets edit, and re-push to overwrite the stale cell next pass.
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

		if ( get_transient( self::PULL_LOCK ) ) {
			return $empty;
		}
		set_transient( self::PULL_LOCK, time(), self::LOCK_TTL );

		try {
			return $this->pull_locked( $config, $empty );
		} finally {
			delete_transient( self::PULL_LOCK );
		}
	}

	private function pull_locked( array $config, array $empty ) {
		$columns = Brikpanel_Sheets_Mapping::get_columns( 'products' );
		$col_map = Brikpanel_Sheets_Mapping::column_index_map( $columns );

		// product_id is the join key — without it we cannot match rows back
		// to a Woo product. Both stock and stock_status are writable; we need
		// at least one of them in the mapping to do anything useful.
		if ( ! isset( $col_map['product_id'] ) ) {
			return $empty;
		}
		$has_stock_col        = isset( $col_map['stock'] );
		$has_stock_status_col = isset( $col_map['stock_status'] );
		if ( ! $has_stock_col && ! $has_stock_status_col ) {
			return $empty;
		}

		$client = new Brikpanel_Sheets_Client();
		$range  = Brikpanel_Sheets_Client::a1_quote_tab( $config['tab'] ) . '!A2:' . self::col_letter( count( $columns ) );

		try {
			$rows = $client->values_get( $config['spreadsheet_id'], $range, 'UNFORMATTED_VALUE' );
		} catch ( Brikpanel_Sheets_Exception $e ) {
			Brikpanel_Sheets_Logger::log( 'products', 'pull values_get failed: ' . $e->getMessage(), $e->http_code );
			throw $e;
		}

		$checked   = 0;
		$applied   = 0;
		$conflicts = 0;
		$pid_col   = (int) $col_map['product_id'];

		$valid_stock_statuses = function_exists( 'wc_get_product_stock_status_options' )
			? array_keys( wc_get_product_stock_status_options() )
			: [ 'instock', 'outofstock', 'onbackorder' ];

		foreach ( $rows as $row_index => $row ) {
			$product_id = isset( $row[ $pid_col ] ) ? (int) $row[ $pid_col ] : 0;
			if ( $product_id <= 0 ) { continue; }

			$product = wc_get_product( $product_id );
			if ( ! $product ) { continue; }

			$checked++;

			// Snapshot check first — if nothing in the row differs from what
			// we last pushed, there's nothing to do; bail out before paying
			// for the conflict-detection meta reads.
			//
			// Pad the pulled row back to the full column count before hashing:
			// the Sheets API omits trailing empty cells, so a row whose last
			// column(s) are blank (e.g. a product with no COGS, when COGS is the
			// rightmost column) comes back shorter than the snapshot we hashed at
			// push time. Without this, every such row hashes differently on every
			// pull and gets needlessly re-queued for push — constant churn on a
			// catalogue with many blank trailing cells.
			$snapshot_hash = (string) $this->get_product_meta( $product, self::META_SNAPSHOT_HASH );
			$row_values    = array_values( $row );
			$col_count     = count( $columns );
			if ( count( $row_values ) < $col_count ) {
				$row_values = array_pad( $row_values, $col_count, '' );
			}
			$row_hash      = self::hash_row( $row_values );
			if ( $snapshot_hash !== '' && $snapshot_hash === $row_hash ) {
				continue;
			}

			// Conflict guard: did Woo modify after our last push?
			// META_SYNCED_AT is stored as current_time('mysql', true) which is
			// already UTC; using get_gmt_from_date() would re-convert and shift
			// by the site's timezone offset, giving false conflicts on every poll.
			$last_push_mysql = (string) $this->get_product_meta( $product, self::META_SYNCED_AT );
			$last_push_ts    = $last_push_mysql !== '' ? (int) strtotime( $last_push_mysql . ' UTC' ) : 0;
			$woo_modified    = $product->get_date_modified();
			$woo_modified_ts = $woo_modified ? (int) $woo_modified->getTimestamp() : 0;

			if ( $last_push_ts > 0 && $woo_modified_ts > ( $last_push_ts + 10 ) ) {
				// Woo moved after our last push — Woo wins. Re-queue the
				// product so the next push pass overwrites the stale cell.
				$conflicts++;
				$this->queue_push( $product_id );
				continue;
			}

			$row_changed     = false;
			$needs_sheet_refresh = false;

			// ---- Stock quantity writeback ----
			if ( $has_stock_col ) {
				$sheet_stock_raw = $row[ (int) $col_map['stock'] ] ?? '';
				// Empty cell = "do not touch" (e.g. variable parent rows
				// where stock lives on variations). Non-numeric = ignore.
				if ( $sheet_stock_raw !== '' && $sheet_stock_raw !== null && is_numeric( $sheet_stock_raw ) ) {
					$sheet_stock = (int) $sheet_stock_raw;
					if ( $product->get_manage_stock() ) {
						$current_stock = (int) $product->get_stock_quantity();
						if ( $current_stock !== $sheet_stock ) {
							$result = wc_update_product_stock( $product, $sheet_stock, 'set' );
							if ( is_wp_error( $result ) ) {
								Brikpanel_Sheets_Logger::log( 'products', 'Stock apply failed for product ' . $product_id . ': ' . $result->get_error_message() );
							} else {
								$row_changed = true;
								$product = wc_get_product( $product_id );
							}
						}
					}
				}
			}

			// ---- Stock status writeback ----
			if ( $has_stock_status_col ) {
				$sheet_status = trim( (string) ( $row[ (int) $col_map['stock_status'] ] ?? '' ) );
				if ( $sheet_status !== '' && in_array( $sheet_status, $valid_stock_statuses, true ) ) {
					$current_status = (string) $product->get_stock_status();
					if ( $current_status !== $sheet_status ) {
						if ( ! $product->get_manage_stock() ) {
							// Unmanaged stock: set_stock_status() is honored
							// directly. Save, then reload to verify (variable
							// parents and a few WC plugins can roll it back).
							$product->set_stock_status( $sheet_status );
							$product->save();
							$reloaded = wc_get_product( $product_id );
							if ( $reloaded && (string) $reloaded->get_stock_status() === $sheet_status ) {
								$row_changed = true;
								$product = $reloaded;
							} else {
								Brikpanel_Sheets_Logger::log( 'products', 'Stock status apply did not stick for product ' . $product_id . ' (target ' . $sheet_status . '); WC reverted on save.' );
							}
						} else {
							// Managed stock: WC auto-derives stock_status from
							// stock_quantity (qty>0=instock, qty<=0 + backorders
							// allowed=onbackorder, qty<=0 + no backorders=
							// outofstock). set_stock_status() is silently
							// reverted on save. Instead, nudge the qty/backorders
							// so WC's auto-derive produces the user's chosen
							// status. Matches Shopify's "tracked quantity"
							// model where status follows qty.
							$current_qty   = (int) $product->get_stock_quantity();
							$target_qty    = $current_qty;
							$touch_backord = false;
							if ( $sheet_status === 'outofstock' ) {
								if ( $current_qty > 0 ) { $target_qty = 0; }
								// Backorders=no is required for outofstock at qty<=0.
								if ( $product->get_backorders() !== 'no' ) {
									$product->set_backorders( 'no' );
									$touch_backord = true;
								}
							} elseif ( $sheet_status === 'instock' ) {
								if ( $current_qty <= 0 ) { $target_qty = 1; }
							} elseif ( $sheet_status === 'onbackorder' ) {
								if ( $current_qty > 0 ) { $target_qty = 0; }
								if ( $product->get_backorders() === 'no' ) {
									$product->set_backorders( 'notify' );
									$touch_backord = true;
								}
							}

							$qty_changed = ( $target_qty !== $current_qty );
							if ( $qty_changed || $touch_backord ) {
								if ( $qty_changed ) {
									$product->set_stock_quantity( $target_qty );
								}
								$product->save();
								$reloaded = wc_get_product( $product_id );
								if ( $reloaded && (string) $reloaded->get_stock_status() === $sheet_status ) {
									$row_changed = true;
									$product     = $reloaded;
									if ( $qty_changed && $has_stock_col ) {
										// Our qty adjust diverged the sheet
										// (still shows old qty) from Woo (new
										// qty). Write the new qty to the stock
										// cell immediately so subsequent pulls
										// see sheet==Woo and don't ping-pong
										// (apply qty from sheet -> auto-flip
										// status -> re-apply status via qty).
										$sheet_row_num = (int) $this->get_product_meta( $product, self::META_ROW );
										if ( $sheet_row_num > 0 ) {
											$stock_cell = self::col_letter( (int) $col_map['stock'] + 1 ) . $sheet_row_num;
											try {
												$client->values_update( $config['spreadsheet_id'], Brikpanel_Sheets_Client::a1_quote_tab( $config['tab'] ) . '!' . $stock_cell, [ [ $target_qty ] ] );
											} catch ( Brikpanel_Sheets_Exception $e ) {
												// Best-effort: if the write fails,
												// queue_push will refresh the row
												// on the next push pass instead.
												$needs_sheet_refresh = true;
												Brikpanel_Sheets_Logger::log( 'products', 'Stock cell refresh failed for product ' . $product_id . ': ' . $e->getMessage(), $e->http_code );
											}
										} else {
											$needs_sheet_refresh = true;
										}
									}
									Brikpanel_Sheets_Logger::log( 'products', 'Stock status set to ' . $sheet_status . ' for product ' . $product_id . ' via qty adjust (' . $current_qty . ' to ' . $target_qty . ')' . ( $touch_backord ? ' + backorders flag' : '' ) . '.' );
								} else {
									Brikpanel_Sheets_Logger::log( 'products', 'Stock status apply via qty adjust did not stick for product ' . $product_id . ' (target ' . $sheet_status . ', tried qty ' . $target_qty . ').' );
								}
							}
						}
					}
				}
			}

			// ---- Price / cost / status writeback ----
			// Plain scalar setters batched into a single save(). Each guards on
			// "cell present, valid, and actually different" so an untouched or
			// blank cell is left alone (blank = "do not change", same convention
			// as stock — a price cannot be *cleared* from the sheet). Works for
			// simple products and variations alike; variations carry their own
			// price/cost/status independent of the parent.
			$setter_dirty = false;

			// Variable parents derive their price from their variations and do
			// not store a usable regular/sale price of their own — WC discards a
			// value set on the parent, which would otherwise look "changed" on
			// every pull and churn. Skip price writeback for them; the merchant
			// edits each variation row instead.
			$is_variable_parent = $product->is_type( 'variable' );

			if ( isset( $col_map['regular_price'] ) && ! $is_variable_parent ) {
				$raw = $row[ (int) $col_map['regular_price'] ] ?? '';
				if ( $raw !== '' && $raw !== null && is_numeric( $raw ) && (float) $raw >= 0 ) {
					$new = wc_format_decimal( $raw );
					if ( self::decimal_changed( $new, $product->get_regular_price( 'edit' ) ) ) {
						$product->set_regular_price( $new );
						$setter_dirty = true;
					}
				}
			}

			if ( isset( $col_map['sale_price'] ) && ! $is_variable_parent ) {
				$raw = $row[ (int) $col_map['sale_price'] ] ?? '';
				if ( $raw !== '' && $raw !== null && is_numeric( $raw ) && (float) $raw >= 0 ) {
					$new = wc_format_decimal( $raw );
					if ( self::decimal_changed( $new, $product->get_sale_price( 'edit' ) ) ) {
						$product->set_sale_price( $new );
						$setter_dirty = true;
					}
				}
			}

			if ( isset( $col_map['cogs'] ) ) {
				$raw = $row[ (int) $col_map['cogs'] ] ?? '';
				if ( $raw !== '' && $raw !== null && is_numeric( $raw ) && (float) $raw >= 0 ) {
					$new = wc_format_decimal( $raw );

					// Compare against the EFFECTIVE cost, i.e. exactly what the
					// push wrote into this cell. Comparing against the row's own
					// raw meta (as this used to) is an asymmetry that corrupted
					// Woo data without anyone touching the cost column:
					//   - Inherited cost: variation has none, parent has 5. Push
					//     writes 5; raw is '' so 5 !== '' looked like an edit and
					//     froze 5 onto the variation. Later parent changes then
					//     silently stopped applying to it.
					//   - Additive: parent 5 + variation 2 = 7 pushed, raw 2, so
					//     7 !== 2 looked like an edit and wrote raw = 7, making
					//     the effective cost 12. Reproduced live by editing only
					//     the STOCK cell, which is what drags the row into this
					//     branch in the first place.
					$is_variation = $product->is_type( 'variation' );
					$cogs_parent  = $is_variation ? (int) $product->get_parent_id() : (int) $product->get_id();
					$cogs_var     = $is_variation ? (int) $product->get_id() : 0;
					$effective    = brikpanel_product_cogs( $cogs_parent, $cogs_var );
					$cur          = ( null === $effective ) ? '' : wc_format_decimal( (string) $effective );

					if ( self::decimal_changed( $new, $cur ) ) {
						// The merchant means "this line's unit cost is $new". For
						// an additive variation the stored value is the amount ON
						// TOP of the parent, so store the difference and keep the
						// effective cost equal to what they typed.
						$store   = $new;
						$skip    = false;
						$additive = $is_variation
							&& 'yes' === get_post_meta( $cogs_var, '_cogs_value_is_additive', true );

						if ( $additive ) {
							$parent_raw  = brikpanel_product_cogs_raw( $cogs_parent );
							$parent_cost = ( '' === $parent_raw ) ? 0.0 : (float) $parent_raw;
							$delta       = (float) $new - $parent_cost;
							if ( $delta < 0 ) {
								// Below the parent's own cost there is no additive
								// value that produces it. Refuse rather than write
								// a negative surcharge the merchant never intended.
								$skip = true;
								Brikpanel_Sheets_Logger::log(
									'products',
									'Pull skipped cost for variation ' . $cogs_var . ': ' . $new
										. ' is below the parent cost (' . $parent_cost . ') and this'
										. ' variation adds to the parent, so no additive value matches.'
								);
							} else {
								$store = wc_format_decimal( (string) $delta );
							}
						}

						if ( ! $skip ) {
							// Write the WHOLE cost-key set, exactly like the editor
							// and Quick Edit do. Writing only BrikPanel's own key
							// would be silently ignored on a store running a cost
							// plugin: that plugin's key is read first, so the sheet
							// edit would appear to save and change nothing.
							if ( method_exists( $product, 'set_cogs_value' ) ) {
								$product->set_cogs_value( $store !== '' ? $store : null );
							}
							foreach ( brikpanel_cogs_meta_keys() as $cogs_key ) {
								// `_cogs_total_value` is a WC_Product PROP, not plain
								// meta: pushing it through the generic meta API trips
								// WooCommerce's "doing it wrong" notice on every pulled
								// row. set_cogs_value() above owns it, and the legacy-key
								// mirror covers the case where WC's COGS feature flag
								// makes that setter a no-op.
								if ( '_cogs_total_value' === $cogs_key ) {
									continue;
								}
								$product->update_meta_data( $cogs_key, $store );
							}
							$setter_dirty = true;
						}
					}
				}
			}

			if ( isset( $col_map['status'] ) ) {
				$raw = trim( (string) ( $row[ (int) $col_map['status'] ] ?? '' ) );
				if ( $raw !== '' ) {
					// Whitelist guards against trash/auto-draft and typos.
					// Variations only meaningfully support enabled (publish) /
					// disabled (private).
					$allowed = $product->is_type( 'variation' )
						? [ 'publish', 'private' ]
						: [ 'publish', 'draft', 'pending', 'private' ];
					if ( in_array( $raw, $allowed, true ) && $raw !== (string) $product->get_status() ) {
						$product->set_status( $raw );
						$setter_dirty = true;
					}
				}
			}

			if ( $setter_dirty ) {
				$product->save();
				$row_changed = true;
				// Regular/sale edits move the derived 'price' column too, so push
				// the freshly-built row back to the sheet to keep every dependent
				// cell in step with Woo.
				$needs_sheet_refresh = true;
				$product = wc_get_product( $product_id );
			}

			if ( $row_changed ) {
				$applied++;
				$fresh_row = $this->build_row( $product, $columns );
				$this->set_product_meta( $product, self::META_SNAPSHOT_HASH, self::hash_row( $fresh_row ) );
				$this->set_product_meta( $product, self::META_SYNCED_AT, current_time( 'mysql', true ) );
				if ( $needs_sheet_refresh ) {
					$this->queue_push( $product_id );
				}
			} else {
				// Row hash differed but no writable field changed — the user
				// must have edited a read-only column (price, name, etc.).
				// Refresh the snapshot so this row doesn't re-flag as
				// "changed" on every subsequent pull, and re-queue the
				// product so the next push overwrites the stray edit with
				// the canonical Woo value.
				$fresh_row = $this->build_row( $product, $columns );
				$this->set_product_meta( $product, self::META_SNAPSHOT_HASH, self::hash_row( $fresh_row ) );
				$this->queue_push( $product_id );
			}
		}

		update_option( self::OPT_LAST_PULL, [
			'ts'        => time(),
			'checked'   => $checked,
			'applied'   => $applied,
			'conflicts' => $conflicts,
		], false );

		return [ 'checked' => $checked, 'applied' => $applied, 'conflicts' => $conflicts ];
	}

	/**
	 * Map { product_id => 1-based sheet row } for the rows currently in the
	 * target tab. Used to reconcile an event-driven push against the live sheet
	 * so a product with a missing/stale stored row number updates its existing
	 * row instead of appending a duplicate.
	 *
	 * Reads only the Product ID column (always present — it is a mandatory
	 * column) so the call stays cheap even on large catalogues. On any read
	 * failure we return an empty map and the caller falls back to its previous
	 * append-only behaviour, never worse than before.
	 *
	 * @param Brikpanel_Sheets_Client $client
	 * @param array                   $config  { spreadsheet_id, tab }
	 * @param string[]                $columns Active column selection in order.
	 * @return array<int,int> product_id => row number (first match wins)
	 */
	private function existing_rows_by_product_id( $client, array $config, array $columns ) {
		$idx = Brikpanel_Sheets_Mapping::column_index_map( $columns );
		if ( ! isset( $idx['product_id'] ) ) {
			return [];
		}
		$col = self::col_letter( (int) $idx['product_id'] + 1 );
		$range = Brikpanel_Sheets_Client::a1_quote_tab( $config['tab'] )
			. '!' . $col . '1:' . $col;

		try {
			$values = $client->values_get( $config['spreadsheet_id'], $range );
		} catch ( \Throwable $e ) {
			Brikpanel_Sheets_Logger::log( 'products', 'Reconcile read failed: ' . $e->getMessage() );
			return [];
		}

		$map = [];
		foreach ( (array) $values as $i => $cells ) {
			// Skip the header row (row 1) and blank cells (cleared/orphan rows).
			$raw = isset( $cells[0] ) ? trim( (string) $cells[0] ) : '';
			if ( $raw === '' || ! ctype_digit( $raw ) ) {
				continue;
			}
			$pid = (int) $raw;
			// First occurrence wins; a later duplicate row is left to be cleaned
			// by the next "Sync now" rebuild rather than fought over here.
			if ( $pid > 0 && ! isset( $map[ $pid ] ) ) {
				$map[ $pid ] = $i + 1; // values_get is 0-indexed from row 1.
			}
		}
		return $map;
	}

	// =========================================================================
	// Product collection helpers
	// =========================================================================

	/**
	 * Return up to $limit product IDs that should appear in the Sheet. Used
	 * by the "Sync now" / re-push flow. Simple + variable + variation are all
	 * eligible; draft/trash products are skipped.
	 *
	 * @param int $limit
	 * @param int $offset Skip this many products — drives batch pagination on a
	 *                    full rebuild so each pass takes the next slice instead
	 *                    of repeating the first one.
	 * @return int[]
	 */
	private function all_syncable_product_ids( $limit, $offset = 0 ) {
		$args = [
			'limit'   => (int) $limit,
			'offset'  => max( 0, (int) $offset ),
			'status'  => [ 'publish', 'private' ],
			'type'    => [ 'simple', 'variable', 'grouped', 'external' ],
			'orderby' => 'ID',
			'order'   => 'ASC',
			'return'  => 'ids',
		];

		// Restrict to the chosen categories (and their sub-categories) when the
		// merchant set a filter. wc_get_products takes category slugs; resolve
		// the stored term IDs to slugs and drop any that no longer exist.
		$cat_ids = self::category_filter_ids();
		if ( ! empty( $cat_ids ) ) {
			$slugs = [];
			foreach ( $cat_ids as $cid ) {
				$term = get_term( $cid, 'product_cat' );
				if ( $term && ! is_wp_error( $term ) ) {
					$slugs[] = $term->slug;
				}
			}
			if ( ! empty( $slugs ) ) {
				$args['category'] = $slugs;
			}
		}

		$ids = wc_get_products( $args );
		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Expand the input ID list into the actual products that should be
	 * pushed: for variable parents, replace with all child variations. This
	 * keeps every row in the sheet at the SKU/stock level the merchant
	 * actually manages.
	 *
	 * @param int[] $ids
	 * @return WC_Product[]
	 */
	private function expand_to_syncable_products( array $ids ) {
		$out = [];
		foreach ( $ids as $id ) {
			$id = (int) $id;
			if ( $id <= 0 ) { continue; }
			$product = wc_get_product( $id );
			if ( ! $product ) { continue; }

			// Honour the category filter on the event-driven path too. A stock
			// edit on a product outside the chosen categories gets dropped from
			// the queue (see handle_push) and never reaches the sheet. Variable
			// parents are tested here; their variations inherit the parent's
			// verdict below, so they are not re-checked.
			if ( ! $this->product_matches_category( $product ) ) { continue; }

			if ( $product->is_type( 'variable' ) ) {
				// Push the parent itself too — gives the merchant a summary
				// row even though stock lives on variations.
				$out[] = $product;
				$children = $product->get_children();
				foreach ( $children as $child_id ) {
					$child = wc_get_product( (int) $child_id );
					if ( $child ) { $out[] = $child; }
				}
			} else {
				$out[] = $product;
			}
		}
		// De-dup by ID — a queue with both a parent and one of its variations
		// might otherwise push the variation twice.
		$seen = [];
		$final = [];
		foreach ( $out as $p ) {
			$pid = $p->get_id();
			if ( isset( $seen[ $pid ] ) ) { continue; }
			$seen[ $pid ] = 1;
			$final[] = $p;
		}
		return $final;
	}

	// =========================================================================
	// Row builder + meta access
	// =========================================================================

	private function build_row( $product, array $columns ) {
		$row = [];
		foreach ( $columns as $col ) {
			$row[] = $this->resolve_column_value( $col, $product );
		}
		return $row;
	}

	private function resolve_column_value( $col, $product ) {
		switch ( $col ) {
			case 'product_id':    return (int) $product->get_id();
			case 'parent_id':     return (int) $product->get_parent_id();
			case 'type':          return (string) $product->get_type();
			case 'sku':           return (string) $product->get_sku();
			case 'name':          return (string) $product->get_name();
			case 'variation_attributes':
				if ( $product->is_type( 'variation' ) ) {
					$attrs = [];
					foreach ( $product->get_variation_attributes() as $k => $v ) {
						if ( $v === '' || ! is_scalar( $v ) ) { continue; }
						$raw_name = str_replace( 'attribute_', '', $k );
						$label    = wc_attribute_label( $raw_name );
						if ( $label === '' || $label === $raw_name ) {
							$label = brikpanel_title_case( $raw_name );
						}
						$attrs[] = $label . ': ' . (string) $v;
					}
					return implode( '; ', $attrs );
				}
				return '';
			case 'short_description':
			case 'description':
				// Descriptions hold HTML; flatten to plain text so the cell stays
				// readable. Variations rarely carry their own copy, so fall back
				// to the parent's text (same pattern as COGS) to keep variation
				// rows informative rather than blank.
				$getter = ( $col === 'short_description' ) ? 'get_short_description' : 'get_description';
				$text   = $product->$getter( 'view' );
				if ( ( $text === '' || $text === null ) && $product->is_type( 'variation' ) ) {
					$parent = wc_get_product( (int) $product->get_parent_id() );
					if ( $parent ) {
						$text = $parent->$getter( 'view' );
					}
				}
				$text = wp_strip_all_tags( (string) $text, true );
				// Decode entities (&amp; &nbsp; …) so the cell shows the real text
				// instead of escaped markup, then collapse runs of whitespace.
				$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				return trim( preg_replace( '/\s+/u', ' ', $text ) );
			case 'price':         return $product->get_price() === '' ? '' : (float) $product->get_price();
			case 'regular_price': return $product->get_regular_price() === '' ? '' : (float) $product->get_regular_price();
			case 'sale_price':    return $product->get_sale_price() === '' ? '' : (float) $product->get_sale_price();
			case 'cogs':
				// Per-unit cost via the central accessor (native-first with
				// legacy fallback, variation → parent fallback, additive
				// variations). An unset cost shows as blank so the merchant
				// can tell "no cost configured" from "free / cost 0".
				$cogs = $product->is_type( 'variation' )
					? brikpanel_product_cogs( (int) $product->get_parent_id(), (int) $product->get_id() )
					: brikpanel_product_cogs( (int) $product->get_id() );
				return null === $cogs ? '' : (float) $cogs;
			case 'stock':
				if ( ! $product->get_manage_stock() ) { return ''; }
				$q = $product->get_stock_quantity();
				return $q === null ? '' : (int) $q;
			case 'stock_status':  return (string) $product->get_stock_status();
			case 'status':        return (string) $product->get_status();
			case 'permalink':     return (string) get_permalink( $product->get_id() );
		}
		return '';
	}

	/**
	 * WC product meta accessors. Variations use the parent post type
	 * `product_variation` so plain get_post_meta works for both, but routing
	 * via the product object lets WC handle CRUD caches consistently.
	 */
	private function get_product_meta( $product, $key ) {
		if ( ! $product ) { return ''; }
		$v = $product->get_meta( $key, true );
		return $v;
	}

	private function set_product_meta( $product, $key, $value ) {
		if ( ! $product ) { return; }
		$product->update_meta_data( $key, $value );
		$product->save_meta_data();
	}

	// =========================================================================
	// Reset / clear support
	// =========================================================================

	/**
	 * Wipe per-product sync state. Called by the "Reset & re-push" admin
	 * action. Does NOT clear the Sheets tab itself — that's done by
	 * clear_products_target_tab() in the Settings class.
	 */
	public static function reset_sync_state() {
		global $wpdb;

		if ( class_exists( 'Brikpanel_Cron' ) && Brikpanel_Cron::is_available() ) {
			Brikpanel_Cron::cancel( self::HOOK_PUSH_FLUSH );
			Brikpanel_Cron::cancel( self::HOOK_PULL );
		}

		$keys = [
			self::META_ROW,
			self::META_SYNCED_AT,
			self::META_SNAPSHOT_HASH,
		];
		$placeholders = implode( ',', array_fill( 0, count( $keys ), '%s' ) );

		// Products live in postmeta only — no HPOS split.
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ($placeholders)",
			$keys
		) );

		delete_option( self::OPT_LAST_PUSH );
		delete_option( self::OPT_LAST_PULL );
		delete_option( self::OPT_PUSH_QUEUE );
		// The tab this cursor was paging into has just been wiped, so it now
		// points into empty space. Leaving it would make the next "Sync now"
		// resume from the middle of a rebuild that no longer exists, skipping
		// every product before that offset.
		delete_option( self::OPT_REBUILD );
		delete_transient( self::PUSH_LOCK );
		delete_transient( self::PULL_LOCK );
		Brikpanel_Sheets_Logger::log( 'products', 'Product sync state reset.' );
	}

	/**
	 * Drop only the per-product row-tracking meta (row number, snapshot hash,
	 * synced-at), without touching cron schedules, the pull cursor, or options.
	 * Used at the start of a "Sync now" rebuild so every product is treated as
	 * brand new and re-appended contiguously. A direct bulk delete keeps this
	 * cheap on large catalogues; the rebuild that follows always appends and
	 * overwrites the row meta, so a stale per-object cache cannot misplace a row.
	 */
	private static function clear_row_tracking_meta() {
		global $wpdb;
		$keys = [ self::META_ROW, self::META_SYNCED_AT, self::META_SNAPSHOT_HASH ];
		$placeholders = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ($placeholders)",
			$keys
		) );
	}

	// =========================================================================
	// Small utilities
	// =========================================================================

	private function extract_start_row( $resp ) {
		$range = (string) ( $resp['updates']['updatedRange'] ?? '' );
		if ( $range === '' ) {
			return 0;
		}
		$bang = strpos( $range, '!' );
		if ( $bang !== false ) {
			$range = substr( $range, $bang + 1 );
		}
		if ( preg_match( '/^[A-Z]+(\d+)/', $range, $m ) ) {
			return (int) $m[1];
		}
		return 0;
	}

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

	/**
	 * Whether a sheet-supplied decimal differs from the current stored value.
	 * Compared on a zero-trimmed canonical form so "159" == "159.00" (no
	 * needless re-write or pull/push ping-pong) while "0" != "" — an explicit
	 * zero (free product / zero cost) still applies over a previously blank
	 * field, which a plain (float) compare would swallow.
	 */
	private static function decimal_changed( $new, $current ) {
		return wc_format_decimal( $new, false, true ) !== wc_format_decimal( (string) $current, false, true );
	}

	/**
	 * Hash a row for snapshot comparison. Normalised so floats vs int-strings
	 * (Sheets returns 12 vs 12.0 unpredictably) don't false-positive as
	 * changed.
	 */
	public static function hash_row( array $row ) {
		$normalized = [];
		foreach ( $row as $cell ) {
			if ( is_numeric( $cell ) ) {
				$normalized[] = (string) ( (float) $cell + 0 ); // collapses 12.0 → "12"
			} else {
				$normalized[] = (string) $cell;
			}
		}
		return hash( 'sha256', implode( "\x1f", $normalized ) );
	}
}
