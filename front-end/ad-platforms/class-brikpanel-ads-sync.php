<?php
/**
 * BrikPanel — Ad Platforms Sync orchestrator.
 *
 * Responsible for:
 *   - Registering the daily sync Action Scheduler job.
 *   - Splitting an initial historical backfill into 90-day chunks so a
 *     single AS worker tick can complete each chunk well within PHP
 *     max_execution_time.
 *   - Running an inline "Sync now" from the settings page button.
 *
 * Hooks:
 *   - brikpanel_ads_daily_sync          (recurring; fires once per day)
 *   - brikpanel_ads_backfill_chunk      (single-shot; one chunk per scheduled action)
 *
 * @package BrikPanel
 * @since   3.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Brikpanel_Ads_Sync {

	const HOOK_DAILY    = 'brikpanel_ads_daily_sync';
	const HOOK_BACKFILL = 'brikpanel_ads_backfill_chunk';

	/** Spacing between chunked backfill jobs so we don't overload the API. */
	const BACKFILL_CHUNK_INTERVAL_SECONDS = 30;

	/** Days per chunk during backfill. 90 = Meta's max insights window. */
	const BACKFILL_CHUNK_DAYS = 90;

	/** Halt reason codes stored in brikpanel_ads_backfill_status_<platform>. */
	const HALT_CONNECTION_LOST = 'connection_lost';
	const HALT_ACCOUNT_CHANGED = 'account_changed';

	/** Daily sync runs once every 24 hours. */
	const DAILY_INTERVAL_SECONDS = DAY_IN_SECONDS;

	public function __construct() {
		// Register handlers with the BrikPanel Action Scheduler wrapper so the
		// scheduled tasks admin page can list them with friendly labels.
		//
		// `brikpanel_cron_register` fires on `init` priority 20 on *every*
		// request whenever Action Scheduler is available (CLI / WP-Cron /
		// admin / front-end alike), which is before AS dispatches any due
		// action — so this single hook is sufficient for worker contexts to
		// resolve the handler. Calling register_handlers() directly here
		// instead would run `__()` during plugin load (before `init`), which
		// trips WP 6.7's "_load_textdomain_just_in_time was called
		// incorrectly" notice. This matches the Sheets module convention.
		add_action( 'brikpanel_cron_register', [ $this, 'register_handlers' ] );

		// Schedule the recurring daily sync (idempotent; AS dedupes by group+hook).
		// On the register hook so the check joins Brikpanel_Cron::reconcile()
		// instead of querying Action Scheduler on every request.
		add_action( 'brikpanel_cron_register', [ $this, 'schedule_daily' ] );

		// Hook OAuth completion → kick off backfill for the just-connected
		// platform. The OAuth handler sets a `brikpanel_ads_needs_backfill_*`
		// flag we read on the next admin request.
		add_action( 'admin_init', [ $this, 'maybe_schedule_backfill_from_flag' ] );
	}

	// =========================================================================
	// Action Scheduler wiring
	// =========================================================================

	public function register_handlers() {
		if ( ! class_exists( 'Brikpanel_Cron' ) ) {
			return;
		}

		Brikpanel_Cron::register_handler( self::HOOK_DAILY, function ( $payload ) {
			( new self() )->handle_daily( (array) $payload );
		}, static function () {
			return [
				'label'       => __( 'Ad spend daily sync', 'brikpanel' ),
				'description' => __( 'Pulls yesterday plus the last 7 days of spend from each connected ad platform.', 'brikpanel' ),
			];
		} );

		Brikpanel_Cron::register_handler( self::HOOK_BACKFILL, function ( $payload ) {
			( new self() )->handle_backfill_chunk( (array) $payload );
		}, static function () {
			return [
				'label'       => __( 'Ad spend backfill chunk', 'brikpanel' ),
				'description' => __( 'One 90-day slice of the initial historical pull when an ad platform is first connected.', 'brikpanel' ),
			];
		} );
	}

	public function schedule_daily() {
		if ( ! class_exists( 'Brikpanel_Cron' ) || ! Brikpanel_Cron::is_available() ) {
			return;
		}

		// Passive until connected: the daily sync only has something to do once
		// at least one ad platform is connected. With no connection we make sure
		// no recurring job is sitting in the queue — this both avoids a pointless
		// daily tick and cleans up a job left over from an older build that
		// scheduled unconditionally on install. The schedule is (re)created the
		// moment a platform is connected, since connecting hits an admin request
		// and this runs on `init`.
		$connected = Brikpanel_Ads_Tokens::is_connected( Brikpanel_Ads_Tokens::PLATFORM_GOOGLE )
			|| Brikpanel_Ads_Tokens::is_connected( Brikpanel_Ads_Tokens::PLATFORM_META );

		if ( ! $connected ) {
			// cancel() is already a no-op when nothing is pending.
			Brikpanel_Cron::cancel( self::HOOK_DAILY );
			return;
		}

		Brikpanel_Cron::schedule_recurring( self::HOOK_DAILY, self::DAILY_INTERVAL_SECONDS, [], HOUR_IN_SECONDS );
	}

	/**
	 * After OAuth, kick off a historical backfill if the platform doesn't
	 * have any data yet. Reads the flag set by the OAuth handler.
	 */
	public function maybe_schedule_backfill_from_flag() {
		foreach ( [ Brikpanel_Ads_Tokens::PLATFORM_GOOGLE, Brikpanel_Ads_Tokens::PLATFORM_META ] as $platform ) {
			$flag_key = 'brikpanel_ads_needs_backfill_' . $platform;
			if ( get_option( $flag_key ) !== 'yes' ) {
				continue;
			}
			delete_option( $flag_key );

			$desc = Brikpanel_Ads_Tokens::describe( $platform );
			if ( empty( $desc['primary_account'] ) ) {
				// User hasn't picked an account yet — the settings page sets
				// the flag again after they choose one. No-op here.
				continue;
			}
			$this->schedule_backfill( $platform, $desc['primary_account'] );
		}
	}

	/**
	 * Split a (3-year) backfill into 90-day chunks and schedule each as a
	 * separate AS job. Cheaper than running one long job because each chunk
	 * completes inside a normal PHP timeout, and a failure in one chunk
	 * doesn't lose the work already done in earlier chunks.
	 *
	 * @param string $platform
	 * @param string $account_id
	 */
	public function schedule_backfill( $platform, $account_id ) {
		if ( ! class_exists( 'Brikpanel_Cron' ) || ! Brikpanel_Cron::is_available() ) {
			return;
		}

		// Bump the generation counter so any chunk still sitting in the queue
		// from an earlier account selection becomes a no-op the moment it runs.
		// Cancelling the queued actions directly is not enough: Action
		// Scheduler may already have claimed a batch, and a chunk that lands
		// after we wiped the previous account's rows would silently resurrect
		// them under an account the merchant no longer uses. Those rows then
		// fed the dashboard's Ad Spend / ROAS / Net Profit with no way for the
		// merchant to see where the numbers came from.
		$generation = self::bump_backfill_generation( $platform );

		$end       = self::today();
		$start     = gmdate( 'Y-m-d', time() - BRIKPANEL_ADS_BACKFILL_DAYS * DAY_IN_SECONDS );
		$chunks    = self::date_chunks( $start, $end, self::BACKFILL_CHUNK_DAYS );
		$offset    = 0;
		$total     = count( $chunks );

		// Reverse the chunks so the most-recent days arrive first — the user
		// sees today's spend appear on the dashboard within minutes, and the
		// 3-year history fills in behind it over the next half hour.
		$chunks = array_reverse( $chunks );

		foreach ( $chunks as $i => $chunk ) {
			Brikpanel_Cron::schedule_single(
				time() + $offset,
				self::HOOK_BACKFILL,
				[
					'platform'   => $platform,
					'account_id' => $account_id,
					'start'      => $chunk[0],
					'end'        => $chunk[1],
					'chunk'      => $i + 1,
					'total'      => $total,
					'generation' => $generation,
				]
			);
			$offset += self::BACKFILL_CHUNK_INTERVAL_SECONDS;
		}

		update_option( 'brikpanel_ads_backfill_status_' . $platform, [
			'started_at'      => time(),
			'total_chunks'    => $total,
			'completed_chunks'=> 0,
			'last_error'      => '',
			'halted'          => false,
			'halt_reason'     => '',
			'generation'      => $generation,
		], false );
	}

	/**
	 * Platforms whose halt has already been noted in this PHP process.
	 *
	 * @var array<string, bool>
	 */
	private static $halt_noted = [];

	/**
	 * Platforms whose "superseded" skip has already been noted in this process.
	 *
	 * @var array<string, bool>
	 */
	private static $superseded_noted = [];

	/** Option key holding the current backfill generation for a platform. */
	private static function backfill_generation_key( $platform ) {
		return 'brikpanel_ads_backfill_gen_' . $platform;
	}

	/** Current backfill generation (0 when a backfill has never been queued). */
	public static function backfill_generation( $platform ) {
		return (int) get_option( self::backfill_generation_key( $platform ), 0 );
	}

	/** Invalidate every queued chunk for this platform and return the new generation. */
	private static function bump_backfill_generation( $platform ) {
		$next = self::backfill_generation( $platform ) + 1;
		update_option( self::backfill_generation_key( $platform ), $next, false );
		return $next;
	}

	/**
	 * Stop a backfill that is still queued: drop the progress record and
	 * cancel every chunk this platform still has in the Action Scheduler
	 * queue.
	 *
	 * Disconnecting used to leave up to 13 chunks behind. Each woke up, found
	 * no connection, wrote "skipped: not connected." into the 100-entry log
	 * ring and reported success — so one disconnect buried the genuine errors
	 * under a dozen identical notes and left the progress bar frozen for good.
	 * Cancel the work instead of letting it drain.
	 *
	 * The generation bump is the belt to the cancellation's braces: Action
	 * Scheduler may already have claimed a batch, and a claimed chunk can no
	 * longer be unscheduled. Those land on the guards in
	 * handle_backfill_chunk() instead.
	 *
	 * @param string $platform
	 * @param string $reason_code Optional halt reason code (see halt_reason_text).
	 *                            When given, the progress record is kept and
	 *                            marked halted with this code instead of being
	 *                            removed: the connection died on its own and
	 *                            the merchant is still looking at the card.
	 *                            When empty (an explicit disconnect) the record
	 *                            is removed outright.
	 * @return int Number of queued chunks cancelled.
	 */
	public static function cancel_backfill( $platform, $reason_code = '' ) {
		$status_key = 'brikpanel_ads_backfill_status_' . $platform;
		$status     = (array) get_option( $status_key, [] );

		if ( $reason_code === '' || empty( $status ) ) {
			delete_option( $status_key );
		} else {
			$status['halted']      = true;
			$status['halt_reason'] = $reason_code;
			update_option( $status_key, $status, false );
		}

		if ( ! class_exists( 'Brikpanel_Cron' ) || ! Brikpanel_Cron::is_available() ) {
			return 0;
		}

		self::bump_backfill_generation( $platform );

		// Cancel only this platform's chunks. The other platform may be
		// mid-backfill and must not lose its queue because this one was
		// disconnected, and Brikpanel_Cron::cancel() matches the whole
		// argument list — which differs per chunk — so filter by payload here.
		$cancelled = 0;
		$pending   = Brikpanel_Cron::query( [
			'hook'     => self::HOOK_BACKFILL,
			'status'   => 'pending',
			'per_page' => 200,
		] );
		foreach ( array_keys( $pending ) as $action_id ) {
			$args    = Brikpanel_Cron::get_action_args( $action_id );
			$payload = isset( $args[0] ) && is_array( $args[0] ) ? $args[0] : [];
			if ( ! isset( $payload['platform'] ) || (string) $payload['platform'] !== $platform ) {
				continue;
			}
			$result = Brikpanel_Cron::cancel_by_id( $action_id );
			if ( ! empty( $result['ok'] ) ) {
				$cancelled++;
			}
		}

		return $cancelled;
	}

	/**
	 * Record why a backfill stopped early, so the settings card can say so.
	 *
	 * Without this the bar sat at "Loading history… 2 of 13" forever: the skip
	 * guards returned without touching completed_chunks and without writing an
	 * error, leaving the rendered card and the JS poll with nothing to show but
	 * a frozen bar and no way for the merchant to learn what went wrong.
	 *
	 * Scoped to the generation that owns the status record — a stale chunk must
	 * never stamp a halt onto the backfill that replaced it.
	 *
	 * @param string $platform
	 * @param int    $generation  Generation carried by the chunk.
	 * @param string $reason_code Halt reason code (see halt_reason_text). A code
	 *                            rather than a sentence: this runs in a
	 *                            background worker whose locale is not the one
	 *                            the merchant is reading the card in, and a
	 *                            sentence frozen into the database would also
	 *                            stay in the old language after a site language
	 *                            change. Translate at render time.
	 * @return bool True the first time this halt is recorded, false when the
	 *              backfill was already halted for the same reason or the
	 *              record belongs to a newer backfill. Callers use it to log
	 *              the note once instead of once per queued chunk.
	 */
	private static function halt_backfill( $platform, $generation, $reason_code ) {
		// Action Scheduler hands a worker a whole batch at once, so without
		// this the same note landed in the 100-entry ring thirteen times over
		// and pushed out the errors that actually needed reading.
		if ( isset( self::$halt_noted[ $platform ] ) ) {
			return false;
		}

		$key    = 'brikpanel_ads_backfill_status_' . $platform;
		$status = (array) get_option( $key, [] );
		$owner  = (int) ( $status['generation'] ?? 0 );
		if ( ! empty( $status ) && $generation > 0 && $owner > 0 && $owner !== $generation ) {
			return false;
		}

		self::$halt_noted[ $platform ] = true;

		if ( empty( $status ) ) {
			// No progress record to annotate (an old queue outliving its
			// backfill). Still worth one note, never thirteen.
			return true;
		}

		$already = ! empty( $status['halted'] ) && (string) ( $status['halt_reason'] ?? '' ) === $reason_code;

		$status['halted']      = true;
		$status['halt_reason'] = $reason_code;
		update_option( $key, $status, false );

		return ! $already;
	}

	/**
	 * Drop a halted progress record, leaving a live one alone.
	 *
	 * A halt says "the import stopped and here is why". The moment the platform
	 * is connected again that sentence is no longer true, and without this the
	 * card kept announcing a stopped import forever: reconnecting wipes
	 * primary_account, so the post-OAuth flag finds no account, schedules no
	 * backfill, and never overwrites the record that would have cleared it.
	 *
	 * @param string $platform
	 * @return bool Whether a record was removed.
	 */
	public static function clear_halted_backfill( $platform ) {
		$key    = 'brikpanel_ads_backfill_status_' . $platform;
		$status = (array) get_option( $key, [] );
		if ( empty( $status ) || empty( $status['halted'] ) ) {
			return false;
		}
		delete_option( $key );
		unset( self::$halt_noted[ $platform ] );
		return true;
	}

	/**
	 * Merchant-facing sentence for a stored halt reason code.
	 *
	 * @param string $code
	 * @return string Empty when the code is unknown or absent.
	 */
	public static function halt_reason_text( $code ) {
		switch ( (string) $code ) {
			case self::HALT_CONNECTION_LOST:
				return __( 'The history import stopped because the connection to this platform was lost. Connect again to resume it.', 'brikpanel' );
			case self::HALT_ACCOUNT_CHANGED:
				return __( 'The history import stopped because the selected ad account changed. Pick the account again to restart it.', 'brikpanel' );
		}
		return '';
	}

	// =========================================================================
	// Handlers
	// =========================================================================

	/**
	 * Daily sync — re-fetch the last 7 days for every connected platform
	 * with a primary account selected. Cheap, idempotent, catches the late
	 * revisions ad platforms apply to recent-day numbers.
	 */
	public function handle_daily( array $payload = [] ) {
		// Every connected platform must get its turn even when an earlier one
		// blows up. Rethrowing from inside the loop (which is what this used to
		// do) meant a broken Google connection permanently starved Meta of its
		// daily pull, because Google is iterated first and the exception left
		// the loop before Meta was ever touched. Collect failures instead and
		// raise a single aggregate at the end, so Action Scheduler still marks
		// the run failed and shows the reason on the Scheduled Tasks page.
		$failures = [];

		foreach ( [ Brikpanel_Ads_Tokens::PLATFORM_GOOGLE, Brikpanel_Ads_Tokens::PLATFORM_META ] as $platform ) {
			$desc = Brikpanel_Ads_Tokens::describe( $platform );
			if ( ! $desc['connected'] || empty( $desc['primary_account'] ) ) {
				continue;
			}
			$account_id = (string) $desc['primary_account'];

			$end   = self::today();
			$start = gmdate( 'Y-m-d', time() - ( BRIKPANEL_ADS_REFRESH_WINDOW_DAYS - 1 ) * DAY_IN_SECONDS );

			try {
				$this->pull_window( $platform, $account_id, $start, $end );
				update_option(
					'brikpanel_ads_last_sync_' . $platform,
					[ 'ts' => time(), 'start' => $start, 'end' => $end, 'ok' => true ],
					false
				);
			} catch ( \Throwable $e ) {
				Brikpanel_Ads_Logger::log( 'sync', 'Daily sync ' . $platform . ' failed: ' . $e->getMessage() );
				update_option(
					'brikpanel_ads_last_sync_' . $platform,
					[ 'ts' => time(), 'start' => $start, 'end' => $end, 'ok' => false, 'error' => $e->getMessage() ],
					false
				);
				$failures[] = $platform . ': ' . $e->getMessage();
			}
		}

		if ( ! empty( $failures ) ) {
			throw new \RuntimeException( implode( ' | ', $failures ) );
		}
	}

	/**
	 * One backfill chunk. Each call covers up to 90 days for a single
	 * (platform, account_id) tuple.
	 */
	public function handle_backfill_chunk( array $payload ) {
		$platform   = isset( $payload['platform'] ) ? (string) $payload['platform'] : '';
		$account_id = isset( $payload['account_id'] ) ? (string) $payload['account_id'] : '';
		$start      = isset( $payload['start'] ) ? (string) $payload['start'] : '';
		$end        = isset( $payload['end'] ) ? (string) $payload['end'] : '';
		$chunk      = isset( $payload['chunk'] ) ? (int) $payload['chunk'] : 0;
		$total      = isset( $payload['total'] ) ? (int) $payload['total'] : 0;
		$generation = isset( $payload['generation'] ) ? (int) $payload['generation'] : 0;

		if ( $platform === '' || $account_id === '' || $start === '' || $end === '' ) {
			Brikpanel_Ads_Logger::log( 'sync', 'Backfill chunk missing required args; skipped.' );
			return;
		}

		// The connection went away between scheduling and execution: the
		// merchant disconnected, or a 401 forced a refresh that came back
		// permanently rejected. Not a failure of this chunk, so it is a note
		// rather than an error — but the merchant is now staring at a progress
		// bar that will never move again, so record why it stopped.
		if ( ! Brikpanel_Ads_Tokens::is_connected( $platform ) ) {
			$first = self::halt_backfill( $platform, $generation, self::HALT_CONNECTION_LOST );
			if ( $first ) {
				Brikpanel_Ads_Logger::note( 'sync', 'Backfill chunk ' . $platform . ' skipped: not connected. Remaining chunks will skip too.' );
			}
			return;
		}

		// Superseded by a newer backfill (the merchant re-picked an account
		// while this chunk was queued). Generation 0 means the chunk predates
		// this guard, so fall through to the account check below instead.
		// Never halt here: the newer backfill owns the progress record and is
		// running fine — stamping a halt on it would report a failure that is
		// not happening.
		$current_gen = self::backfill_generation( $platform );
		if ( $generation > 0 && $current_gen > 0 && $generation !== $current_gen ) {
			// Once per process, for the same ring-buffer reason as the halt note.
			if ( ! isset( self::$superseded_noted[ $platform ] ) ) {
				self::$superseded_noted[ $platform ] = true;
				Brikpanel_Ads_Logger::note( 'sync', 'Backfill chunk ' . $platform . ' skipped: superseded by a newer backfill.' );
			}
			return;
		}

		// Never write rows for an account that is no longer the selected one.
		// Those rows are invisible in the settings UI (which only reports the
		// primary account) yet still counted by the dashboard totals.
		$primary = (string) Brikpanel_Ads_Tokens::describe( $platform )['primary_account'];
		if ( $primary !== '' && $primary !== $account_id ) {
			$first = self::halt_backfill( $platform, $generation, self::HALT_ACCOUNT_CHANGED );
			if ( $first ) {
				Brikpanel_Ads_Logger::note( 'sync', 'Backfill chunk ' . $platform . ' skipped: account no longer selected. Remaining chunks will skip too.' );
			}
			return;
		}

		try {
			$this->pull_window( $platform, $account_id, $start, $end );
			$status = (array) get_option( 'brikpanel_ads_backfill_status_' . $platform, [] );
			$status['completed_chunks'] = (int) ( $status['completed_chunks'] ?? 0 ) + 1;
			$status['last_error']  = '';
			$status['halted']      = false;
			$status['halt_reason'] = '';
			update_option( 'brikpanel_ads_backfill_status_' . $platform, $status, false );
		} catch ( \Throwable $e ) {
			Brikpanel_Ads_Logger::log( 'sync', 'Backfill chunk ' . $chunk . '/' . $total . ' ' . $platform . ' failed: ' . $e->getMessage() );
			$status = (array) get_option( 'brikpanel_ads_backfill_status_' . $platform, [] );
			$status['last_error'] = $e->getMessage();
			update_option( 'brikpanel_ads_backfill_status_' . $platform, $status, false );
			throw $e;
		}
	}

	/**
	 * Inline sync for the "Sync now" button on the settings page. Re-fetches
	 * the last 7 days for one platform and returns the row count.
	 *
	 * @param string $platform
	 * @return array{rows:int, days:int}
	 * @throws \Throwable
	 */
	public function run_inline( $platform ) {
		$desc = Brikpanel_Ads_Tokens::describe( $platform );
		if ( ! $desc['connected'] ) {
			throw new \RuntimeException( __( 'Not connected.', 'brikpanel' ) );
		}
		if ( empty( $desc['primary_account'] ) ) {
			throw new \RuntimeException( __( 'Pick a primary account first.', 'brikpanel' ) );
		}

		$account_id = (string) $desc['primary_account'];
		$end   = self::today();
		$start = gmdate( 'Y-m-d', time() - ( BRIKPANEL_ADS_REFRESH_WINDOW_DAYS - 1 ) * DAY_IN_SECONDS );

		$result = $this->pull_window( $platform, $account_id, $start, $end );

		update_option(
			'brikpanel_ads_last_sync_' . $platform,
			[ 'ts' => time(), 'start' => $start, 'end' => $end, 'ok' => true ],
			false
		);
		return $result;
	}

	// =========================================================================
	// Internal worker
	// =========================================================================

	/**
	 * Dispatch to the right client and upsert the returned rows.
	 *
	 * @return array{rows:int, days:int}
	 * @throws \Throwable
	 */
	private function pull_window( $platform, $account_id, $start, $end ) {
		if ( $platform === Brikpanel_Ads_Tokens::PLATFORM_GOOGLE ) {
			$client = new Brikpanel_Ads_Google_Client();
			$rows   = $client->fetch_spend( $account_id, $start, $end );
		} elseif ( $platform === Brikpanel_Ads_Tokens::PLATFORM_META ) {
			$client = new Brikpanel_Ads_Meta_Client();
			$rows   = $client->fetch_spend( $account_id, $start, $end );
		} else {
			throw new \InvalidArgumentException( 'Unknown platform: ' . $platform );
		}

		$written = Brikpanel_Ads_Store::bulk_upsert( $platform, $account_id, $rows );
		return [
			'rows' => $written,
			'days' => count( $rows ),
		];
	}

	// =========================================================================
	// Helpers
	// =========================================================================

	/**
	 * Build [start, end] inclusive 90-day chunks across the given range.
	 *
	 * @return array<int, array{0:string, 1:string}>
	 */
	private static function date_chunks( $start, $end, $chunk_days ) {
		$chunks = [];
		$cursor = strtotime( $start . ' 00:00:00 UTC' );
		$last   = strtotime( $end . ' 00:00:00 UTC' );
		if ( $cursor === false || $last === false || $cursor > $last ) {
			return $chunks;
		}
		while ( $cursor <= $last ) {
			$slice_end = min( $last, $cursor + ( $chunk_days - 1 ) * DAY_IN_SECONDS );
			$chunks[] = [ gmdate( 'Y-m-d', $cursor ), gmdate( 'Y-m-d', $slice_end ) ];
			$cursor = $slice_end + DAY_IN_SECONDS;
		}
		return $chunks;
	}

	/** Today as YYYY-MM-DD in site-local time. */
	private static function today() {
		return wp_date( 'Y-m-d' );
	}
}
