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
 * Stock is the only writable column. Other Sheets edits to product fields
 * are overwritten on the next push pass — by design, since two-way write
 * for price/name/etc. would explode the surface area (currency conversion,
 * tax recalculation, translation conflicts, etc.) for marginal value.
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
			[ 'label' => __( 'Sheets — push product changes to Google Sheets', 'brikpanel' ) ]
		);
		Brikpanel_Cron::register_handler(
			self::HOOK_PULL,
			[ $this, 'handle_pull' ],
			[ 'label' => __( 'Sheets — pull product stock changes from Google Sheets', 'brikpanel' ) ]
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

		if ( get_transient( self::PUSH_LOCK ) ) {
			Brikpanel_Sheets_Logger::log( 'products', 'Skipping push — another push is in progress.' );
			return $empty;
		}
		set_transient( self::PUSH_LOCK, time(), self::LOCK_TTL );

		try {
			return $this->push_locked( (array) $args, $config, $empty );
		} finally {
			delete_transient( self::PUSH_LOCK );
		}
	}

	private function push_locked( array $args, array $config, array $empty ) {
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

		$ids = [];
		if ( $force_all ) {
			$ids = $this->all_syncable_product_ids( self::BATCH_SIZE );
		} else {
			$queue = (array) get_option( self::OPT_PUSH_QUEUE, [] );
			$ids   = array_map( 'intval', array_keys( $queue ) );
			$ids   = array_slice( $ids, 0, self::BATCH_SIZE );
		}

		if ( empty( $ids ) ) {
			return $empty;
		}

		$products = $this->expand_to_syncable_products( $ids );

		$to_append = [];
		$to_update = []; // [ row_number => row_array ]
		$snapshot_assignments = []; // [ product_id => row_array ]

		foreach ( $products as $product ) {
			$row     = $this->build_row( $product, $columns );
			$existing_row = (int) $this->get_product_meta( $product, self::META_ROW );
			if ( $existing_row > 0 ) {
				$to_update[ $existing_row ] = [ 'pid' => $product->get_id(), 'row' => $row ];
			} else {
				$to_append[] = [ 'pid' => $product->get_id(), 'row' => $row ];
			}
			$snapshot_assignments[ $product->get_id() ] = $row;
		}

		$appended_count = 0;
		$updated_count  = 0;

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
					// Continue with the rest — don't let one bad row tank the batch.
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

		// Persist snapshot + synced_at on every successfully-attempted product
		// (we update even on update failures so a permanently broken row does
		// not retry forever; the user can use "Reset & re-push" to recover).
		$now_mysql = current_time( 'mysql', true );
		foreach ( $snapshot_assignments as $pid => $row ) {
			$product = wc_get_product( $pid );
			if ( ! $product ) { continue; }
			$this->set_product_meta( $product, self::META_SYNCED_AT, $now_mysql );
			$this->set_product_meta( $product, self::META_SNAPSHOT_HASH, self::hash_row( $row ) );
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
		if ( ! isset( $col_map['stock_status'] ) ) {
			return [];
		}
		// wc_get_product_stock_status_options() returns the canonical
		// instock/outofstock/onbackorder keys keyed by their labels. Newer WC
		// versions may add a fourth key; pulling dynamically keeps us in sync.
		$keys = function_exists( 'wc_get_product_stock_status_options' )
			? array_keys( wc_get_product_stock_status_options() )
			: [ 'instock', 'outofstock', 'onbackorder' ];
		return [
			[
				'column_index' => (int) $col_map['stock_status'],
				'values'       => $keys,
				'strict'       => true,
			],
		];
	}

	/**
	 * Public entry point for the "Sync now" / "Reset & re-push" admin button.
	 * Pages itself through AS if more rows remain.
	 */
	public function handle_push_bulk( $args = [] ) {
		$args = (array) $args;
		$args['force_all'] = true;
		$result = $this->handle_push( $args );
		if ( ! empty( $result['more'] ) ) {
			Brikpanel_Cron::enqueue_async( self::HOOK_PUSH_FLUSH, [ 'force_all' => true ], [ 'unique' => true ] );
		}
		return $result;
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
			$snapshot_hash = (string) $this->get_product_meta( $product, self::META_SNAPSHOT_HASH );
			$row_hash      = self::hash_row( array_values( $row ) );
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

	// =========================================================================
	// Product collection helpers
	// =========================================================================

	/**
	 * Return up to $limit product IDs that should appear in the Sheet. Used
	 * by the "Sync now" / re-push flow. Simple + variable + variation are all
	 * eligible; draft/trash products are skipped.
	 *
	 * @param int $limit
	 * @return int[]
	 */
	private function all_syncable_product_ids( $limit ) {
		$ids = wc_get_products( [
			'limit'   => (int) $limit,
			'status'  => [ 'publish', 'private' ],
			'type'    => [ 'simple', 'variable', 'grouped', 'external' ],
			'orderby' => 'ID',
			'order'   => 'ASC',
			'return'  => 'ids',
		] );
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
							$label = function_exists( 'mb_convert_case' )
								? mb_convert_case( $raw_name, MB_CASE_TITLE, 'UTF-8' )
								: ucfirst( $raw_name );
						}
						$attrs[] = $label . ': ' . (string) $v;
					}
					return implode( '; ', $attrs );
				}
				return '';
			case 'price':         return $product->get_price() === '' ? '' : (float) $product->get_price();
			case 'regular_price': return $product->get_regular_price() === '' ? '' : (float) $product->get_regular_price();
			case 'sale_price':    return $product->get_sale_price() === '' ? '' : (float) $product->get_sale_price();
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
		delete_transient( self::PUSH_LOCK );
		delete_transient( self::PULL_LOCK );
		Brikpanel_Sheets_Logger::log( 'products', 'Product sync state reset.' );
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
