<?php
/**
 * BrikPanel — Sheets expense sync (BrikPanel → Sheets push + Sheets → BrikPanel pull).
 *
 * Operational expenses are the one BrikPanel data set a merchant types in by
 * hand, one row at a time, and a store with a dozen recurring costs plus ad
 * hoc invoices makes that tedious fast. This flow turns a spreadsheet tab
 * into the bulk entry surface: paste a month of costs in, and they land in
 * BrikPanel as real expense rows that feed the Profit section.
 *
 * What lives in the tab
 * --------------------
 * One row per merchant-managed expense: every one-off entry, plus the
 * template row of each recurring expense. The dated copies BrikPanel
 * generates from a recurring template (recurring_parent > 0) are deliberately
 * left out — they are derived data, rebuilt from the template whenever it
 * changes, so a sheet edit to one of them would silently vanish on the next
 * rebuild. Change the template row and every generated copy follows.
 *
 * Directions
 * ----------
 * Push (BrikPanel → Sheets) is a full rebuild: the tab is cleared and
 * rewritten from the database, so deletions and edits made in BrikPanel are
 * reflected exactly and the sheet can never drift. That also means a row
 * typed into the sheet is only safe once it has been pulled in, which is why
 * the push refuses to run its rebuild until the ingest half has succeeded
 * whenever two-way sync is on.
 *
 * Pull (Sheets → BrikPanel) reads the tab and, per row:
 *   - blank ID + a usable date/title/amount  → create a new expense, then
 *     write the new ID straight back into the sheet so the next pass updates
 *     that row instead of creating a duplicate;
 *   - existing ID with changed cells         → update that expense;
 *   - anything else                          → left alone.
 * Deleting a row from the sheet does NOT delete the expense: a blank row is
 * indistinguishable from a row that has not loaded yet, and silently dropping
 * cost data would corrupt profit history. Deletions stay in the Expenses
 * screen.
 *
 * Conflict resolution is snapshot-based rather than clock-based. Each push
 * stores a hash of the exact row it wrote. On ingest a row whose hash still
 * matches its snapshot has not been touched in Sheets and is skipped; a row
 * that differs is applied unless the BrikPanel record ALSO differs from the
 * same snapshot, in which case both sides changed and BrikPanel wins (the
 * next push overwrites the stale cells). No timezone maths, and the two
 * halves of the comparison are rendered by the same code path.
 *
 * @package BrikPanel
 * @since   3.2.30
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Brikpanel_Sheets_Expenses_Sync {

	const HOOK_PUSH = 'brikpanel_gs_expenses_push';
	const HOOK_PULL = 'brikpanel_gs_expenses_pull';

	const OPT_ENABLED       = 'brikpanel_gs_expenses_enabled';
	const OPT_TAB_NAME      = 'brikpanel_gs_expenses_tab';
	const OPT_PULL_ENABLED  = 'brikpanel_gs_expenses_pull_enabled';
	const OPT_PULL_INTERVAL = 'brikpanel_gs_expenses_pull_interval'; // 2|5|15 (minutes)
	const OPT_LAST_PUSH     = 'brikpanel_gs_expenses_last_push';
	const OPT_LAST_PULL     = 'brikpanel_gs_expenses_last_pull';

	// { pushed_at:int, hashes:{ expense_id => sha256 } } — the snapshot of the
	// last row written for each expense, used for conflict detection.
	const OPT_STATE = 'brikpanel_gs_expenses_state';

	const PUSH_LOCK  = 'brikpanel_gs_expenses_push_lock';
	const PULL_LOCK  = 'brikpanel_gs_expenses_pull_lock';
	const LOCK_TTL   = 300;

	const BATCH_SIZE = 500;

	// Hard ceiling on rows handled in either direction. Expenses are a
	// hand-managed data set — a store with more than this has something else
	// going on, and an unbounded loop against a spreadsheet is not the place
	// to find out.
	const MAX_ROWS = 5000;

	/**
	 * True while an ingest is applying sheet edits. The expense save hooks
	 * consult it so writes that originated in the sheet do not immediately
	 * enqueue a push that would rebuild the tab under the merchant's cursor
	 * while they are still typing rows into it.
	 *
	 * @var bool
	 */
	private static $ingesting = false;

	public function __construct() {
		add_action( 'init',                    [ $this, 'maybe_attach_hooks' ], 30 );
		add_action( 'brikpanel_cron_register', [ $this, 'register_handlers' ] );
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
		$name = (string) get_option( self::OPT_TAB_NAME, 'Expenses' );
		return $name !== '' ? $name : 'Expenses';
	}

	/**
	 * Pull cadence in seconds, clamped to the values the UI offers so a stray
	 * option write cannot schedule a cadence the merchant never picked.
	 */
	public static function pull_interval_seconds() {
		switch ( (string) get_option( self::OPT_PULL_INTERVAL, '5' ) ) {
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

	private static function table() {
		global $wpdb;
		return $wpdb->prefix . Brikpanel_Expenses::TABLE;
	}

	/**
	 * Make sure the Operational Expenses module is in memory.
	 *
	 * The Sheets module loads outside the is_admin gate so Action Scheduler
	 * workers can resolve its handlers, but the Expenses module is admin-only.
	 * A scheduled pull therefore runs in a request where Brikpanel_Expenses
	 * does not exist yet, and this sync needs its table name and its recurring
	 * materialiser. Loading it here is cheap and side-effect free in a cron
	 * request: everything it registers hangs off admin_menu / wp_ajax_* hooks
	 * that never fire there.
	 *
	 * @return bool False when the module file is missing, in which case every
	 *              entry point bails instead of touching the database blind.
	 */
	private static function ensure_expenses_module() {
		if ( class_exists( 'Brikpanel_Expenses' ) ) {
			return true;
		}
		if ( function_exists( 'brikpanel_require' ) ) {
			brikpanel_require( 'front-end/expenses/brikpanel-expenses.php' );
		}
		return class_exists( 'Brikpanel_Expenses' );
	}

	// =========================================================================
	// Hook attachment
	// =========================================================================

	public function maybe_attach_hooks() {
		if ( ! self::is_enabled() || ! Brikpanel_Sheets_Tokens::is_connected() ) {
			return;
		}
		// Fired by Brikpanel_Expenses when a merchant adds, edits or deletes an
		// expense in wp-admin, so the tab follows the store without waiting for
		// a manual "Sync now".
		add_action( 'brikpanel_expense_saved',   [ $this, 'on_expense_changed' ] );
		add_action( 'brikpanel_expense_deleted', [ $this, 'on_expense_changed' ] );
	}

	public function register_handlers() {
		Brikpanel_Cron::register_handler(
			self::HOOK_PUSH,
			[ $this, 'handle_push' ],
			static function () { return [ 'label' => __( 'Sheets — push expenses to Google Sheets', 'brikpanel' ) ]; }
		);
		Brikpanel_Cron::register_handler(
			self::HOOK_PULL,
			[ $this, 'handle_pull' ],
			static function () { return [ 'label' => __( 'Sheets — pull expenses from Google Sheets', 'brikpanel' ) ]; }
		);

		// Only the pull runs on a schedule. The push is event-driven (expense
		// saved / deleted) plus the manual "Sync now" button — a periodic
		// rebuild would rewrite the tab while somebody is typing into it.
		if ( self::is_enabled() && self::pull_enabled() ) {
			Brikpanel_Cron::schedule_recurring( self::HOOK_PULL, self::pull_interval_seconds(), [] );
		} else {
			Brikpanel_Cron::cancel( self::HOOK_PULL );
		}
	}

	/**
	 * Queue a tab rebuild after an expense changed in wp-admin. Skipped while
	 * an ingest is running: those writes came FROM the sheet, and rebuilding
	 * mid-edit is exactly what we must not do.
	 */
	public function on_expense_changed() {
		if ( self::$ingesting || ! self::is_enabled() ) {
			return;
		}
		if ( ! class_exists( 'Brikpanel_Cron' ) || ! Brikpanel_Cron::is_available() ) {
			return;
		}
		Brikpanel_Cron::enqueue_async( self::HOOK_PUSH, [], [ 'unique' => true ] );
	}

	// =========================================================================
	// Push (BrikPanel → Sheets)
	// =========================================================================

	/**
	 * Rebuild the Expenses tab from the database.
	 *
	 * When two-way sync is on, the sheet is ingested FIRST: the rebuild clears
	 * the tab, so any row the merchant typed but that has not been pulled in
	 * yet would otherwise be wiped before it ever reached BrikPanel. If that
	 * ingest fails, the rebuild is abandoned rather than run blind.
	 *
	 * @param array $args
	 * @return array{rows:int, created:int, updated:int}
	 */
	public function handle_push( $args = [] ) {
		$empty = [ 'rows' => 0, 'created' => 0, 'updated' => 0 ];
		if ( ! self::is_enabled() || ! Brikpanel_Sheets_Tokens::is_connected() ) {
			return $empty;
		}
		if ( ! self::ensure_expenses_module() ) {
			return $empty;
		}
		$config = self::resolve_active_target();
		if ( ! $config ) {
			Brikpanel_Sheets_Logger::log( 'expenses', 'No active spreadsheet configured; skipping expenses push.' );
			return $empty;
		}
		if ( get_transient( self::PUSH_LOCK ) ) {
			return $empty;
		}
		set_transient( self::PUSH_LOCK, time(), self::LOCK_TTL );

		try {
			$columns = Brikpanel_Sheets_Mapping::get_columns( 'expenses' );
			$headers = self::headers( $columns );
			$client  = new Brikpanel_Sheets_Client();

			$client->ensure_tab(
				$config['spreadsheet_id'],
				$config['tab'],
				$headers,
				self::build_dropdown_validations( $columns )
			);

			$ingested = [ 'created' => 0, 'updated' => 0, 'pending' => [] ];
			if ( self::pull_enabled() ) {
				// Throws on API failure — deliberately not caught here, so a
				// failed read never reaches the clear below.
				$ingested = $this->ingest( $client, $config, $columns );
			}

			$rows = $this->rebuild( $client, $config, $columns, $headers, (array) $ingested['pending'] );

			update_option( self::OPT_LAST_PUSH, [ 'ts' => time(), 'rows' => $rows ], false );

			return [
				'rows'    => $rows,
				'created' => (int) $ingested['created'],
				'updated' => (int) $ingested['updated'],
			];
		} finally {
			delete_transient( self::PUSH_LOCK );
		}
	}

	/**
	 * Clear the tab and write header + every managed expense back into it.
	 *
	 * @param array $pending Rows the ingest could not turn into an expense yet
	 *                       (a missing title, an amount that is not a number).
	 *                       They are put back at the bottom so a half-typed
	 *                       entry survives the rebuild instead of disappearing.
	 * @return int Rows written (excluding the header).
	 */
	private function rebuild( $client, array $config, array $columns, array $headers, array $pending = [] ) {
		$sid = $config['spreadsheet_id'];
		$tab = Brikpanel_Sheets_Client::a1_quote_tab( $config['tab'] );

		try {
			$client->values_clear( $sid, $tab );
			$client->values_update( $sid, $tab . '!A1', [ $headers ], 'RAW' );
		} catch ( Brikpanel_Sheets_Exception $e ) {
			Brikpanel_Sheets_Logger::log( 'expenses', 'clear/header failed: ' . $e->getMessage(), $e->http_code );
			throw $e;
		}

		$snapshot_fields = self::comparable_fields( Brikpanel_Sheets_Mapping::column_index_map( $columns ) );
		$hashes  = [];
		$written = 0;
		$offset  = 0;

		while ( $written < self::MAX_ROWS ) {
			$expenses = $this->fetch_managed( self::BATCH_SIZE, $offset );
			if ( empty( $expenses ) ) {
				break;
			}

			$batch = [];
			foreach ( $expenses as $expense ) {
				$batch[] = $this->build_row( $expense, $columns );
				// Snapshot only the fields the sheet carries, so it stays
				// comparable with what the ingest reads back out of it.
				$hashes[ (int) $expense->id ] = self::signature( self::fields_of( $expense ), $snapshot_fields );
			}

			try {
				$client->append_rows( $sid, $config['tab'], $batch, 'RAW' );
			} catch ( Brikpanel_Sheets_Exception $e ) {
				Brikpanel_Sheets_Logger::log( 'expenses', 'append failed at offset ' . $offset . ': ' . $e->getMessage(), $e->http_code );
				throw $e;
			}

			$written += count( $batch );
			$offset  += self::BATCH_SIZE;
		}

		// Put half-finished rows back underneath the real ones, exactly as they
		// were typed, so the merchant can see what still needs fixing.
		if ( ! empty( $pending ) ) {
			try {
				$client->append_rows( $sid, $config['tab'], array_slice( $pending, 0, self::BATCH_SIZE ), 'RAW' );
			} catch ( Brikpanel_Sheets_Exception $e ) {
				Brikpanel_Sheets_Logger::log( 'expenses', 'pending row restore failed: ' . $e->getMessage(), $e->http_code );
			}
		}

		// values_clear() strips cell data validation, so the Type / Repeats
		// dropdowns have to be put back after every rebuild.
		$this->reapply_validations( $client, $config, self::build_dropdown_validations( $columns ) );

		update_option( self::OPT_STATE, [ 'pushed_at' => time(), 'hashes' => $hashes ], false );

		return $written;
	}

	/**
	 * The expenses that belong in the sheet: everything a merchant manages
	 * directly. Generated occurrences of a recurring template are excluded —
	 * see the class docblock.
	 *
	 * @return array<int, object>
	 */
	private function fetch_managed( $limit, $offset ) {
		global $wpdb;
		$table = self::table();
		return (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT id, expense_date, category, parent_category, description, amount, recurring, kind, created_at
			   FROM {$table}
			  WHERE recurring_parent = 0
			  ORDER BY expense_date DESC, id DESC
			  LIMIT %d OFFSET %d",
			(int) $limit,
			(int) $offset
		) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	private function fetch_one( $id ) {
		global $wpdb;
		$table = self::table();
		return $wpdb->get_row( $wpdb->prepare(
			"SELECT id, expense_date, category, parent_category, description, amount, recurring, kind, created_at
			   FROM {$table} WHERE id = %d AND recurring_parent = 0",
			(int) $id
		) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Render one expense as a sheet row in the merchant's column order.
	 *
	 * @param object   $e
	 * @param string[] $columns
	 * @return array
	 */
	private function build_row( $e, array $columns ) {
		$kind = isset( $e->kind ) && $e->kind === 'percent' ? 'percent' : 'fixed';
		$row  = [];
		foreach ( $columns as $col ) {
			switch ( $col ) {
				case 'expense_id':
					$row[] = (int) $e->id;
					break;
				case 'date':
					$row[] = substr( (string) $e->expense_date, 0, 10 );
					break;
				case 'title':
					$row[] = (string) $e->category;
					break;
				case 'category':
					$row[] = (string) ( $e->parent_category ?? '' );
					break;
				case 'description':
					$row[] = (string) ( $e->description ?? '' );
					break;
				case 'amount':
					// A percentage cost stores a rate here, not money. Both are
					// plain numbers so the sheet can still total a column.
					$row[] = round( (float) $e->amount, 4 );
					break;
				case 'type':
					$row[] = self::kind_label( $kind );
					break;
				case 'repeats':
					$row[] = self::recurring_label( (string) ( $e->recurring ?? 'none' ) );
					break;
				case 'created_at':
					$row[] = substr( (string) ( $e->created_at ?? '' ), 0, 16 );
					break;
				default:
					$row[] = '';
			}
		}
		return $row;
	}

	// =========================================================================
	// Pull (Sheets → BrikPanel)
	// =========================================================================

	/**
	 * Read the Expenses tab and apply new / changed rows to BrikPanel.
	 *
	 * @param array $args
	 * @return array{checked:int, created:int, updated:int, skipped:int, conflicts:int}
	 */
	public function handle_pull( $args = [] ) {
		$empty = [ 'checked' => 0, 'created' => 0, 'updated' => 0, 'skipped' => 0, 'conflicts' => 0, 'pending' => [] ];
		if ( ! self::is_enabled() || ! self::pull_enabled() ) {
			return $empty;
		}
		if ( ! Brikpanel_Sheets_Tokens::is_connected() ) {
			return $empty;
		}
		if ( ! self::ensure_expenses_module() ) {
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
			$columns = Brikpanel_Sheets_Mapping::get_columns( 'expenses' );
			$client  = new Brikpanel_Sheets_Client();
			$result  = $this->ingest( $client, $config, $columns );
			update_option( self::OPT_LAST_PULL, [
				'ts'   => time(),
				'rows' => (int) $result['created'] + (int) $result['updated'],
			], false );
			return $result;
		} finally {
			delete_transient( self::PULL_LOCK );
		}
	}

	/**
	 * The shared read-and-apply pass used by both the scheduled pull and the
	 * ingest half of a push.
	 *
	 * @return array{checked:int, created:int, updated:int, skipped:int, conflicts:int}
	 */
	private function ingest( $client, array $config, array $columns ) {
		$out     = [ 'checked' => 0, 'created' => 0, 'updated' => 0, 'skipped' => 0, 'conflicts' => 0, 'pending' => [] ];
		$col_map = Brikpanel_Sheets_Mapping::column_index_map( $columns );

		// Every one of these is mandatory in the catalogue, so a missing key
		// means a corrupted option rather than a merchant choice. Bail rather
		// than guess which column holds what.
		foreach ( [ 'expense_id', 'date', 'title', 'amount' ] as $required ) {
			if ( ! isset( $col_map[ $required ] ) ) {
				Brikpanel_Sheets_Logger::log( 'expenses', 'Column mapping is missing "' . $required . '"; ingest skipped.' );
				return $out;
			}
		}

		$range = Brikpanel_Sheets_Client::a1_quote_tab( $config['tab'] )
			. '!A2:' . self::col_letter( count( $columns ) ) . ( self::MAX_ROWS + 1 );

		try {
			$rows = $client->values_get( $config['spreadsheet_id'], $range, 'UNFORMATTED_VALUE' );
		} catch ( Brikpanel_Sheets_Exception $e ) {
			Brikpanel_Sheets_Logger::log( 'expenses', 'pull values_get failed: ' . $e->getMessage(), $e->http_code );
			throw $e;
		}

		$fields_in_sheet = self::comparable_fields( $col_map );
		$state       = self::state();
		$hashes      = $state['hashes'];
		$col_count   = count( $columns );
		$id_col      = (int) $col_map['expense_id'];
		$id_column   = [];   // row index => id, for the write-back below
		$new_ids     = false;

		self::$ingesting = true;
		try {
			foreach ( $rows as $i => $raw_row ) {
				// Sheets omits trailing empty cells; pad so column offsets are
				// always valid however far right the merchant stopped typing.
				$row = array_values( (array) $raw_row );
				if ( count( $row ) < $col_count ) {
					$row = array_pad( $row, $col_count, '' );
				}

				$id            = isset( $row[ $id_col ] ) ? (int) $row[ $id_col ] : 0;
				$id_column[ $i ] = $id > 0 ? $id : '';

				if ( self::row_is_blank( $row ) ) {
					continue;
				}

				$out['checked']++;

				if ( $id <= 0 ) {
					// New row typed into the sheet.
					$fields = $this->read_row( $row, $col_map );
					if ( ! $fields['valid'] ) {
						// Half-finished entry — a missing title, an amount that
						// is not a number, a date nobody filled in. It cannot
						// become an expense yet, but it is the merchant's typing:
						// hand it back so the rebuild can put it down again
						// instead of wiping it without a word.
						$out['skipped']++;
						$out['pending'][] = $row;
						continue;
					}
					$new_id = $this->create_expense( $fields );
					if ( $new_id > 0 ) {
						$out['created']++;
						$id_column[ $i ]   = $new_id;
						$new_ids           = true;
						$hashes[ $new_id ] = self::signature( $fields, $fields_in_sheet );
					} else {
						$out['skipped']++;
					}
					continue;
				}

				$current = $this->fetch_one( $id );
				if ( ! $current ) {
					// An ID that no longer exists (deleted in BrikPanel, or a
					// generated occurrence somebody pasted in). Never resurrect
					// it from a stale sheet row.
					$out['skipped']++;
					continue;
				}

				// Compare what the row MEANS, not how it is written. A date the
				// merchant typed arrives as a Sheets serial number and a Type
				// they picked may be a key or an alias, so comparing raw cells
				// would report a change on every single pass and rewrite rows
				// that nobody touched.
				$fields    = $this->read_row( $row, $col_map, $current );
				$sheet_sig = self::signature( $fields, $fields_in_sheet );
				$db_sig    = self::signature( self::fields_of( $current ), $fields_in_sheet );

				if ( $sheet_sig === $db_sig ) {
					// Already in agreement; refresh the snapshot in case it was
					// stale so later passes have a correct baseline.
					$hashes[ $id ] = $db_sig;
					continue;
				}

				$snapshot = isset( $hashes[ $id ] ) ? (string) $hashes[ $id ] : '';
				if ( $snapshot !== '' && $snapshot === $sheet_sig ) {
					continue; // untouched in Sheets; BrikPanel moved on its own
				}
				if ( $snapshot !== '' && $db_sig !== $snapshot ) {
					// Both sides moved since the last sync. BrikPanel wins; the
					// next rebuild overwrites the stale cells.
					$out['conflicts']++;
					continue;
				}
				// No snapshot at all means the sync state was reset. That only
				// happens via "Reset & re-push", which wipes the tab and rewrites
				// it from BrikPanel in the same step — so a row that is here AND
				// differs is a genuine edit made since, and the merchant's most
				// recent visible intent wins.

				if ( ! $fields['valid'] ) {
					$out['skipped']++;
					continue;
				}

				if ( $this->update_expense( $id, $fields, $fields_in_sheet ) ) {
					$out['updated']++;
					$hashes[ $id ] = $sheet_sig;
				} else {
					$out['skipped']++;
				}
			}
		} finally {
			self::$ingesting = false;
		}

		// Stamp the IDs of freshly created rows back into the sheet in one
		// write. Without this the same row would be read again on the next
		// pass with a blank ID and create a duplicate expense.
		if ( $new_ids && ! empty( $id_column ) ) {
			$this->write_back_ids( $client, $config, $id_col, $id_column );
		}

		if ( $hashes !== $state['hashes'] ) {
			$state['hashes'] = $hashes;
			update_option( self::OPT_STATE, $state, false );
		}

		if ( $out['created'] > 0 || $out['updated'] > 0 ) {
			if ( function_exists( 'brikpanel_bust_data_caches' ) ) {
				brikpanel_bust_data_caches();
			}
			// A recurring template that arrived from the sheet has to be
			// expanded into its dated copies right away, exactly as the
			// Expenses screen does when one is saved there.
			delete_transient( 'brikpanel_expenses_materialized' );
			Brikpanel_Expenses::materialize_due();
		}

		return $out;
	}

	/**
	 * Write the ID column back so every ingested row carries its expense ID.
	 *
	 * @param array $id_column row index (0-based, relative to row 2) => id|''
	 */
	private function write_back_ids( $client, array $config, $id_col, array $id_column ) {
		$letter = self::col_letter( $id_col + 1 );
		$last   = max( array_keys( $id_column ) );
		$values = [];
		for ( $i = 0; $i <= $last; $i++ ) {
			$values[] = [ isset( $id_column[ $i ] ) ? $id_column[ $i ] : '' ];
		}
		$range = Brikpanel_Sheets_Client::a1_quote_tab( $config['tab'] )
			. '!' . $letter . '2:' . $letter . ( $last + 2 );

		try {
			$client->values_update( $config['spreadsheet_id'], $range, $values, 'RAW' );
		} catch ( Brikpanel_Sheets_Exception $e ) {
			// The expenses themselves were created successfully; only the ID
			// stamp failed. Log loudly — until it lands, a second pull would
			// read those rows as new again.
			Brikpanel_Sheets_Logger::log( 'expenses', 'ID write-back failed: ' . $e->getMessage(), $e->http_code );
		}
	}

	// =========================================================================
	// Row parsing + writes
	// =========================================================================

	/**
	 * Turn a raw sheet row into validated expense fields.
	 *
	 * @param array       $row      Padded sheet row.
	 * @param array       $col_map  column key => 0-based index.
	 * @param object|null $existing The BrikPanel row this sheet row maps to, when
	 *                              updating. Used to fill in cells the merchant
	 *                              left blank rather than rejecting the row or
	 *                              inventing a value.
	 * @return array{valid:bool, date:string, category:string, description:string,
	 *               amount:float, kind:string, recurring:string}
	 */
	private function read_row( array $row, array $col_map, $existing = null ) {
		$cell = static function ( $key ) use ( $row, $col_map ) {
			return isset( $col_map[ $key ], $row[ $col_map[ $key ] ] ) ? $row[ $col_map[ $key ] ] : '';
		};

		// An unreadable Type or Repeats cell must never invent a value. It is
		// usually a label written while the admin was in another language, and
		// defaulting would silently turn a recurring cost into a one-off or a
		// commission rate into a flat fee.
		$kind = self::resolve_kind( $cell( 'type' ) );
		if ( null === $kind ) {
			$kind = $existing && 'percent' === $existing->kind ? 'percent' : 'fixed';
		}

		$date = self::parse_date_cell( $cell( 'date' ) );
		if ( $date === '' && $existing ) {
			// Keep the stored date rather than dropping the merchant's other
			// edits on the row (or silently re-dating it to today).
			$date = substr( (string) $existing->expense_date, 0, 10 );
		}
		// A percentage cost applies every period and has no meaningful single
		// date, so the Expenses screen stamps today when one is created
		// without it. Mirror that here instead of rejecting the row.
		if ( $date === '' && 'percent' === $kind ) {
			$date = current_time( 'Y-m-d' );
		}

		$category  = sanitize_text_field( (string) $cell( 'title' ) );
		$parent    = trim( sanitize_text_field( (string) $cell( 'category' ) ) );
		$amount    = self::parse_amount_cell( $cell( 'amount' ) );
		$recurring = self::resolve_recurring( $cell( 'repeats' ) );
		if ( null === $recurring ) {
			$recurring = $existing ? (string) ( $existing->recurring ?? 'none' ) : 'none';
		}
		if ( 'percent' === $kind ) {
			$recurring = 'none';
		}

		$valid = ( $date !== '' && $category !== '' && $amount !== null && $amount >= 0 );
		if ( $valid && 'percent' === $kind && $amount > 100 ) {
			$valid = false;
		}

		return [
			'valid'       => $valid,
			'date'        => $date,
			'category'    => $category,
			'parent_category' => $parent,
			'description' => sanitize_textarea_field( (string) $cell( 'description' ) ),
			'amount'      => $amount === null ? 0.0 : (float) $amount,
			'kind'        => $kind,
			'recurring'   => $recurring,
		];
	}

	private function create_expense( array $f ) {
		global $wpdb;
		$wpdb->insert(
			self::table(),
			[
				'expense_date'    => $f['date'],
				'category'        => $f['category'],
				'parent_category' => (string) ( $f['parent_category'] ?? '' ),
				'description'     => $f['description'],
				'amount'          => $f['amount'],
				'recurring'       => $f['recurring'],
				'kind'            => $f['kind'],
			],
			[ '%s', '%s', '%s', '%s', '%f', '%s', '%s' ]
		);
		if ( $wpdb->last_error ) {
			Brikpanel_Sheets_Logger::log( 'expenses', 'insert failed: ' . $wpdb->last_error );
			return 0;
		}
		return (int) $wpdb->insert_id;
	}

	/**
	 * @param string[] $fields The expense fields the sheet actually carries.
	 *                         Anything outside this list is left untouched.
	 */
	private function update_expense( $id, array $f, array $fields ) {
		global $wpdb;
		$table   = self::table();
		$columns = [
			'date'        => [ 'expense_date', '%s' ],
			'category'    => [ 'category',     '%s' ],
			'parent_category' => [ 'parent_category', '%s' ],
			'description' => [ 'description',  '%s' ],
			'amount'      => [ 'amount',       '%f' ],
			'recurring'   => [ 'recurring',    '%s' ],
			'kind'        => [ 'kind',         '%s' ],
		];

		$data   = [];
		$format = [];
		foreach ( $fields as $field ) {
			if ( ! isset( $columns[ $field ] ) ) {
				continue;
			}
			$data[ $columns[ $field ][0] ] = $f[ $field ];
			$format[]                      = $columns[ $field ][1];
		}
		// A percentage cost never repeats — the rate applies every period. Keep
		// that true even when the Repeats column is not in the sheet.
		if ( in_array( 'kind', $fields, true ) && 'percent' === $f['kind'] && ! isset( $data['recurring'] ) ) {
			$data['recurring'] = 'none';
			$format[]          = '%s';
		}
		if ( empty( $data ) ) {
			return false;
		}

		// Costs filed under this one point at its TITLE, so a rename has to carry
		// them across. Read the old title first: after the update it is gone, and
		// the children would be stranded under a name no expense answers to.
		$old_title = isset( $data['category'] )
			? (string) $wpdb->get_var( $wpdb->prepare( "SELECT category FROM {$table} WHERE id = %d", (int) $id ) ) // phpcs:ignore
			: '';

		$wpdb->update( $table, $data, [ 'id' => (int) $id ], $format, [ '%d' ] );
		if ( $wpdb->last_error ) {
			Brikpanel_Sheets_Logger::log( 'expenses', 'update failed for #' . (int) $id . ': ' . $wpdb->last_error );
			return false;
		}
		$new_title = isset( $data['category'] ) ? (string) $data['category'] : '';
		if ( '' !== $old_title && '' !== $new_title && $old_title !== $new_title ) {
			$wpdb->update(
				$table,
				[ 'parent_category' => $new_title ],
				[ 'parent_category' => $old_title ],
				[ '%s' ],
				[ '%s' ]
			);
		}
		// The generated copies of a recurring template are derived from the
		// values we just changed, so drop them; materialize_due() rebuilds the
		// series from the new start date / frequency / amount. Matches what
		// saving the same expense in wp-admin does.
		$wpdb->delete( $table, [ 'recurring_parent' => (int) $id ], [ '%d' ] );
		return true;
	}

	// =========================================================================
	// Cell parsing
	// =========================================================================

	/**
	 * Read a date cell as Y-m-d.
	 *
	 * Cells come back with valueRenderOption=UNFORMATTED_VALUE, so a date the
	 * merchant typed by hand arrives as a Sheets serial number (days since
	 * 1899-12-30) while a date this sync wrote arrives as the plain text it
	 * was written as. Both have to work.
	 *
	 * @param mixed $value
	 * @return string Y-m-d, or '' when unusable.
	 */
	public static function parse_date_cell( $value ) {
		if ( is_int( $value ) || is_float( $value ) || ( is_string( $value ) && preg_match( '/^\d+(\.\d+)?$/', trim( $value ) ) ) ) {
			$serial = (float) $value;
			// Guard rails: 1 = 1899-12-31, 2958465 = 9999-12-31. Anything
			// outside a sane store lifetime is a mistyped number, not a date.
			if ( $serial < 1 || $serial > 2958465 ) {
				return '';
			}
			$ts = ( $serial - 25569 ) * DAY_IN_SECONDS; // 25569 = 1970-01-01 as a Sheets serial
			return gmdate( 'Y-m-d', (int) round( $ts ) );
		}

		$raw = trim( (string) $value );
		if ( $raw === '' ) {
			return '';
		}
		if ( preg_match( '/^(\d{4})-(\d{2})-(\d{2})/', $raw, $m )
			&& checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
			return $m[1] . '-' . $m[2] . '-' . $m[3];
		}
		$ts = strtotime( $raw );
		return $ts ? gmdate( 'Y-m-d', $ts ) : '';
	}

	/**
	 * Read an amount cell as a float.
	 *
	 * Numeric cells arrive as real numbers. A merchant may still paste text
	 * ("1.250,50 TL", "$1,250.50"), so strip currency symbols and spaces and
	 * work out which separator is the decimal one from its position.
	 *
	 * @param mixed $value
	 * @return float|null null when the cell holds no usable number.
	 */
	public static function parse_amount_cell( $value ) {
		if ( is_int( $value ) || is_float( $value ) ) {
			return (float) $value;
		}
		$raw = trim( (string) $value );
		if ( $raw === '' ) {
			return null;
		}
		// Keep digits, separators and a leading sign; drop currency symbols,
		// non-breaking spaces and stray letters.
		$clean = preg_replace( '/[^0-9,.\-]/u', '', $raw );
		if ( $clean === '' || $clean === '-' ) {
			return null;
		}

		$last_comma = strrpos( $clean, ',' );
		$last_dot   = strrpos( $clean, '.' );
		if ( false !== $last_comma && false !== $last_dot ) {
			// Whichever comes last is the decimal separator; the other groups.
			if ( $last_comma > $last_dot ) {
				$clean = str_replace( '.', '', $clean );
				$clean = str_replace( ',', '.', $clean );
			} else {
				$clean = str_replace( ',', '', $clean );
			}
		} elseif ( false !== $last_comma ) {
			// A lone comma is a decimal separator unless it groups thousands
			// (exactly three digits after it and more than one group).
			$after = substr( $clean, $last_comma + 1 );
			$clean = ( strlen( $after ) === 3 && substr_count( $clean, ',' ) >= 1 && preg_match( '/^\d{1,3}(,\d{3})+$/', $clean ) )
				? str_replace( ',', '', $clean )
				: str_replace( ',', '.', $clean );
		}

		if ( ! is_numeric( $clean ) ) {
			return null;
		}
		return (float) $clean;
	}

	/**
	 * Run a callback with the SITE language active, whatever language the
	 * person who triggered the sync is using.
	 *
	 * The spreadsheet is one shared document, so its Type and Repeats cells
	 * must read the same no matter who pressed "Sync now" — and, more
	 * importantly, a scheduled pull (which runs in the site language) has to
	 * be able to read back what an admin push wrote. Pinning both directions
	 * to the site language is what makes that round trip stable. Only the
	 * spreadsheet content is pinned; the settings screen stays in each
	 * admin's own language.
	 *
	 * @param callable $cb
	 * @return mixed
	 */
	private static function in_site_locale( callable $cb ) {
		$switched = false;
		if ( function_exists( 'switch_to_locale' ) && function_exists( 'determine_locale' ) ) {
			$site = get_locale();
			if ( $site !== determine_locale() ) {
				$switched = switch_to_locale( $site );
			}
		}
		try {
			return $cb();
		} finally {
			if ( $switched && function_exists( 'restore_previous_locale' ) ) {
				restore_previous_locale();
			}
		}
	}

	/**
	 * Labels offered for the Type column, keyed by stored value. Always in the
	 * site language — see in_site_locale().
	 *
	 * @return array<string,string>
	 */
	public static function kind_labels() {
		return self::in_site_locale( static function () {
			return [
				'fixed'   => __( 'Fixed amount', 'brikpanel' ),
				'percent' => __( 'Percentage of revenue', 'brikpanel' ),
			];
		} );
	}

	public static function kind_label( $kind ) {
		$labels = self::kind_labels();
		return isset( $labels[ $kind ] ) ? $labels[ $kind ] : $labels['fixed'];
	}

	/**
	 * Labels offered for the Repeats column, keyed by stored value.
	 *
	 * @return array<string,string>
	 */
	public static function recurring_labels() {
		return self::in_site_locale( static function () {
			return [
				'none'    => __( 'One-time', 'brikpanel' ),
				'monthly' => __( 'Monthly', 'brikpanel' ),
				'weekly'  => __( 'Weekly', 'brikpanel' ),
				'yearly'  => __( 'Yearly', 'brikpanel' ),
			];
		} );
	}

	public static function recurring_label( $recurring ) {
		$labels = self::recurring_labels();
		return isset( $labels[ $recurring ] ) ? $labels[ $recurring ] : $labels['none'];
	}

	/**
	 * Resolve a Type cell to its stored value.
	 *
	 * Three things are matched, in order: the label in the site's CURRENT
	 * language, the untranslated English source label, and the stored key.
	 *
	 * The middle one is not optional. A push runs in whatever language the
	 * merchant's admin session is in, while a scheduled pull runs in a cron
	 * or CLI request that may resolve a different locale — so the cell can
	 * easily hold a label this process cannot translate back. That is exactly
	 * why an unresolvable value returns null instead of falling back to a
	 * default: the caller then keeps whatever is already stored, rather than
	 * quietly rewriting every recurring cost as one-time.
	 *
	 * @param mixed $value Raw cell value.
	 * @return string|null 'fixed' | 'percent' | null when not understood.
	 *                     An empty cell resolves to 'fixed' — blank is a
	 *                     deliberate "nothing special", not an unknown.
	 */
	public static function resolve_kind( $value ) {
		$v = self::fold( $value );
		if ( $v === '' ) {
			return 'fixed';
		}
		$source = [
			'fixed'   => 'Fixed amount',
			'percent' => 'Percentage of revenue',
		];
		foreach ( self::kind_labels() as $key => $label ) {
			if ( $v === self::fold( $label ) || $v === $key || $v === self::fold( $source[ $key ] ) ) {
				return $key;
			}
		}
		if ( in_array( $v, [ 'percent', 'percentage', '%', 'rate' ], true ) ) {
			return 'percent';
		}
		if ( in_array( $v, [ 'fixed', 'amount', 'flat' ], true ) ) {
			return 'fixed';
		}
		return null;
	}

	/**
	 * Resolve a Repeats cell to its stored value. Same current-label / English
	 * source / key matching and the same null-when-unreadable contract as
	 * resolve_kind().
	 *
	 * @param mixed $value
	 * @return string|null 'none' | 'monthly' | 'weekly' | 'yearly' | null.
	 */
	public static function resolve_recurring( $value ) {
		$v = self::fold( $value );
		if ( $v === '' ) {
			return 'none';
		}
		$source = [
			'none'    => 'One-time',
			'monthly' => 'Monthly',
			'weekly'  => 'Weekly',
			'yearly'  => 'Yearly',
		];
		foreach ( self::recurring_labels() as $key => $label ) {
			if ( $v === self::fold( $label ) || $v === $key || $v === self::fold( $source[ $key ] ) ) {
				return $key;
			}
		}
		$aliases = [
			'one time' => 'none',
			'onetime'  => 'none',
			'once'     => 'none',
			'no'       => 'none',
			'month'    => 'monthly',
			'week'     => 'weekly',
			'year'     => 'yearly',
			'annual'   => 'yearly',
			'annually' => 'yearly',
		];
		return isset( $aliases[ $v ] ) ? $aliases[ $v ] : null;
	}

	/**
	 * Normalise a cell for comparison: trimmed, lower-cased, and with the
	 * common separators flattened so "One-time", "one time" and "One Time"
	 * all land on the same string. Uses the multibyte lower-caser when it is
	 * available so accented labels fold correctly.
	 *
	 * @param mixed $value
	 * @return string
	 */
	private static function fold( $value ) {
		$v = trim( (string) $value );
		if ( $v === '' ) {
			return '';
		}
		$v = function_exists( 'mb_strtolower' ) ? mb_strtolower( $v, 'UTF-8' ) : strtolower( $v );
		$v = str_replace( [ '-', '_' ], ' ', $v );
		return trim( preg_replace( '/\s+/u', ' ', $v ) );
	}

	// =========================================================================
	// Sheet structure helpers
	// =========================================================================

	/**
	 * Header row for the tab, in the site language.
	 *
	 * Pinned for the same reason the Type / Repeats values are: otherwise the
	 * header would flip language every time a different admin pressed "Sync
	 * now", rewriting row 1 on every rebuild for no reason.
	 *
	 * @param string[] $columns
	 * @return string[]
	 */
	public static function headers( array $columns ) {
		return self::in_site_locale( static function () use ( $columns ) {
			return Brikpanel_Sheets_Mapping::headers_for( 'expenses', $columns );
		} );
	}

	/**
	 * Dropdowns for the Type and Repeats columns so a merchant filling rows in
	 * by hand picks a valid value instead of guessing the wording. Non-strict:
	 * the ingest accepts keys and aliases too, and a rejected paste would be
	 * more annoying than a value we can already resolve.
	 *
	 * @param string[] $columns
	 * @return array
	 */
	public static function build_dropdown_validations( array $columns ) {
		$col_map = Brikpanel_Sheets_Mapping::column_index_map( $columns );
		$out     = [];
		if ( isset( $col_map['type'] ) ) {
			$out[] = [
				'column_index' => (int) $col_map['type'],
				'values'       => array_values( self::kind_labels() ),
				'strict'       => false,
			];
		}
		if ( isset( $col_map['repeats'] ) ) {
			$out[] = [
				'column_index' => (int) $col_map['repeats'],
				'values'       => array_values( self::recurring_labels() ),
				'strict'       => false,
			];
		}
		return $out;
	}

	private function reapply_validations( $client, array $config, array $validations ) {
		if ( empty( $validations ) ) {
			return;
		}
		try {
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
			// Cosmetic only — the rows are already in the tab.
			Brikpanel_Sheets_Logger::log( 'expenses', 'Validation reapply failed: ' . $e->getMessage() );
		}
	}

	// =========================================================================
	// State + small utilities
	// =========================================================================

	/**
	 * @return array{pushed_at:int, hashes:array<int,string>}
	 */
	private static function state() {
		$state = get_option( self::OPT_STATE, [] );
		if ( ! is_array( $state ) ) {
			$state = [];
		}
		return [
			'pushed_at' => isset( $state['pushed_at'] ) ? (int) $state['pushed_at'] : 0,
			'hashes'    => isset( $state['hashes'] ) && is_array( $state['hashes'] ) ? $state['hashes'] : [],
		];
	}

	/**
	 * Forget every row snapshot. Called by "Reset & re-push" so the rebuilt tab
	 * is not compared against hashes from the old column layout.
	 */
	public static function reset_sync_state() {
		delete_option( self::OPT_STATE );
		delete_option( self::OPT_LAST_PUSH );
		delete_option( self::OPT_LAST_PULL );
	}

	/**
	 * The stored shape of one expense, in the same normalised form read_row()
	 * produces from a sheet row. Having both sides go through signature() is
	 * what lets a serial-number date cell and a 'Y-m-d' database column be
	 * recognised as the same value.
	 *
	 * @param object $e
	 * @return array
	 */
	private static function fields_of( $e ) {
		$kind = isset( $e->kind ) && $e->kind === 'percent' ? 'percent' : 'fixed';
		return [
			'date'        => substr( (string) $e->expense_date, 0, 10 ),
			'category'    => (string) $e->category,
			'parent_category' => (string) ( $e->parent_category ?? '' ),
			'description' => (string) ( $e->description ?? '' ),
			'amount'      => (float) $e->amount,
			'kind'        => $kind,
			'recurring'   => 'percent' === $kind ? 'none' : (string) ( $e->recurring ?? 'none' ),
		];
	}

	/**
	 * Which expense fields the sheet can currently speak for.
	 *
	 * Date, title and amount are mandatory columns, but description, type and
	 * repeats can be unticked in the column picker. A field with no column is
	 * simply not represented in the sheet, so it must be left out of both the
	 * change comparison and any write — otherwise unticking "Description"
	 * would make every row look changed and blank every stored description on
	 * the next pull.
	 *
	 * @param array $col_map column key => index.
	 * @return string[] Field names, in a fixed order.
	 */
	private static function comparable_fields( array $col_map ) {
		$map = [
			'date'        => 'date',
			'category'    => 'title',
			'parent_category' => 'category',
			'amount'      => 'amount',
			'description' => 'description',
			'kind'        => 'type',
			'recurring'   => 'repeats',
		];
		$out = [];
		foreach ( $map as $field => $column ) {
			if ( isset( $col_map[ $column ] ) ) {
				$out[] = $field;
			}
		}
		return $out;
	}

	/**
	 * Stable hash of a normalised expense, over the given fields only. The
	 * amount is formatted to the column's own precision so 1500, 1500.0 and
	 * "1500.00" all agree.
	 *
	 * @param array         $f      Output of read_row() or fields_of().
	 * @param string[]|null $fields Fields to include; null means all of them.
	 */
	public static function signature( array $f, ?array $fields = null ) {
		if ( null === $fields ) {
			$fields = [ 'date', 'category', 'amount', 'description', 'kind', 'recurring' ];
		}
		$parts = [];
		foreach ( $fields as $field ) {
			if ( 'amount' === $field ) {
				$parts[] = 'amount=' . rtrim( rtrim( sprintf( '%.4F', (float) ( $f['amount'] ?? 0 ) ), '0' ), '.' );
			} else {
				$parts[] = $field . '=' . trim( (string) ( $f[ $field ] ?? '' ) );
			}
		}
		return hash( 'sha256', implode( "\x1f", $parts ) );
	}

	private static function row_is_blank( array $row ) {
		foreach ( $row as $cell ) {
			if ( trim( (string) $cell ) !== '' ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * 1-based column number to its A1 letter (1 => A, 27 => AA).
	 */
	public static function col_letter( $n ) {
		$n = max( 1, (int) $n );
		$out = '';
		while ( $n > 0 ) {
			$rem = ( $n - 1 ) % 26;
			$out = chr( 65 + $rem ) . $out;
			$n   = (int) ( ( $n - $rem - 1 ) / 26 );
		}
		return $out;
	}
}
