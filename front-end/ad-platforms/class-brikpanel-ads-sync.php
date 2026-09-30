<?php
/**
 * BrikPanel — Ad Platforms Sync orchestrator.
 *
 * Responsible for:
 *   - Registering the daily sync Action Scheduler job.
 *   - Splitting the historical backfill of every selected ad account into
 *     90-day chunks so a single AS worker tick can complete each chunk well
 *     within PHP max_execution_time.
 *   - Running an inline "Sync now" from the settings page button.
 *
 * A platform pulls spend from every account the merchant ticked (up to
 * Brikpanel_Ads_Tokens::MAX_ACCOUNTS). Each account is fetched on its own and
 * one account's failure never stops the others.
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
	/**
	 * Written by builds that knew only one account, when a chunk found the
	 * selected account had changed. Never written any more (a removed account's
	 * chunks are skipped instead), but a stored record can still carry it.
	 */
	const HALT_ACCOUNT_CHANGED = 'account_changed';

	/** Daily sync runs once every 24 hours. */
	const DAILY_INTERVAL_SECONDS = DAY_IN_SECONDS;

	/**
	 * How long an inline pull (Sync now, the dashboard's update button) keeps
	 * starting new accounts. Those run inside a browser request, and twenty
	 * accounts one after another could outlast the web server's timeout. What
	 * is left is picked up by the next daily sync.
	 */
	const INLINE_BUDGET_SECONDS = 45;

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
	 * After OAuth, load whatever history the selected accounts are missing.
	 * Reads the flag set by the OAuth handler.
	 *
	 * Re-authorize keeps the selection, so this used to re-import three full
	 * years for the account on every click. queue_history() only queues what
	 * is actually missing.
	 */
	public function maybe_schedule_backfill_from_flag() {
		foreach ( [ Brikpanel_Ads_Tokens::PLATFORM_GOOGLE, Brikpanel_Ads_Tokens::PLATFORM_META ] as $platform ) {
			$flag_key = 'brikpanel_ads_needs_backfill_' . $platform;
			if ( get_option( $flag_key ) !== 'yes' ) {
				continue;
			}
			delete_option( $flag_key );

			$accounts = Brikpanel_Ads_Tokens::describe( $platform )['accounts'];
			if ( empty( $accounts ) ) {
				// Nothing ticked yet: saving the selection on the settings page
				// queues the history instead. No-op here.
				continue;
			}
			self::queue_history( $platform, $accounts );
		}
	}

	/**
	 * Queue the full history of the given accounts.
	 *
	 * Kept for callers that predate queue_history(); it does not look at what
	 * is already stored or queued.
	 *
	 * @param string          $platform
	 * @param string|string[] $account_ids
	 */
	public function schedule_backfill( $platform, $account_ids ) {
		$ids = array_values( array_filter( array_map( 'strval', (array) $account_ids ), 'strlen' ) );
		if ( ! $ids ) {
			return;
		}
		$ranges = self::history_ranges( self::history_start(), self::today() );
		$queued = self::queue_chunks( $platform, array_fill_keys( $ids, $ranges ) );
		self::record_queued( $platform, $queued, [] );
	}

	/**
	 * Make the queue match the selected accounts, and load what they miss.
	 *
	 * - Chunks still queued for an account that is no longer selected are
	 *   cancelled.
	 * - A selected account with nothing stored, or whose import stopped half
	 *   way (connection lost, or cancelled when it was unticked), gets its full
	 *   history queued again. Writes are idempotent, so the months it already
	 *   has are simply refreshed.
	 * - A selected account that was last pulled before the daily refresh
	 *   window gets the days in between: an account whose connection was down
	 *   for weeks would otherwise keep that hole for good, since the daily sync
	 *   only ever looks back seven days. "Last pulled" is the newest stored day
	 *   or the last day a pull covered, whichever is later, so a paused account
	 *   the daily sync keeps checking is not queued again.
	 * - An account that already has chunks on their way is left alone.
	 *
	 * Chunks are queued under the current generation, never a new one: a new
	 * generation would turn every other account's queued chunks into no-ops
	 * and restart their imports from zero.
	 *
	 * @param string   $platform
	 * @param string[] $selected
	 * @return array<string, int> Account => chunks queued.
	 */
	public static function queue_history( $platform, array $selected ) {
		$selected = array_values( array_unique( array_filter( array_map( 'strval', $selected ), 'strlen' ) ) );

		$pending = self::pending_backfill_chunks( $platform );
		$by_account = [];
		foreach ( $pending as $action_id => $payload ) {
			$by_account[ (string) ( $payload['account_id'] ?? '' ) ][] = $action_id;
		}
		// A chunk a worker is running right now counts as on its way too.
		foreach ( self::pending_backfill_chunks( $platform, 'in-progress' ) as $payload ) {
			$by_account[ (string) ( $payload['account_id'] ?? '' ) ][] = 0;
		}

		foreach ( $pending as $action_id => $payload ) {
			$account_id = (string) ( $payload['account_id'] ?? '' );
			if ( ! in_array( $account_id, $selected, true ) ) {
				Brikpanel_Cron::cancel_by_id( $action_id );
			}
		}
		foreach ( array_keys( $by_account ) as $account_id ) {
			if ( ! in_array( (string) $account_id, $selected, true ) ) {
				unset( $by_account[ $account_id ] );
			}
		}

		$legacy_owner = '';
		if ( $pending ) {
			$first        = reset( $pending );
			$legacy_owner = (string) ( $first['account_id'] ?? '' );
		}
		if ( $legacy_owner === '' ) {
			$legacy_owner = (string) ( $selected[0] ?? '' );
		}
		$status = self::normalize_status( self::fresh_option( self::status_key( $platform ), [] ), $legacy_owner );

		$stored     = Brikpanel_Ads_Store::account_summaries( $platform );
		$covered    = self::covered( $platform, $selected );
		$full_start = self::history_start();
		$today      = self::today();
		$gap_before = brikpanel_store_date( 'Y-m-d', '-' . (int) BRIKPANEL_ADS_REFRESH_WINDOW_DAYS . ' days' );

		$plan = [];
		foreach ( $selected as $account_id ) {
			if ( ! empty( $by_account[ $account_id ] ) ) {
				continue;
			}
			$entry      = $status['accounts'][ $account_id ] ?? null;
			$unfinished = is_array( $entry ) && $entry['completed'] < $entry['total'];
			$last       = isset( $stored[ $account_id ] ) ? (string) $stored[ $account_id ]['last_date'] : '';

			if ( $last === '' || $unfinished ) {
				$plan[ $account_id ] = self::history_ranges( $full_start, $today );
				continue;
			}
			// A paused account stores no new rows while the daily sync keeps
			// looking at it, so the last day a pull covered counts too.
			if ( isset( $covered[ $account_id ] ) && strcmp( $covered[ $account_id ], $last ) > 0 ) {
				$last = $covered[ $account_id ];
			}
			if ( strcmp( $last, $gap_before ) < 0 ) {
				// Re-fetch from a week before the newest stored day: platforms
				// still revise the last few days they reported.
				$from = gmdate( 'Y-m-d', strtotime( $last . ' 00:00:00 UTC' ) - (int) BRIKPANEL_ADS_REFRESH_WINDOW_DAYS * DAY_IN_SECONDS );
				if ( strcmp( $from, $full_start ) < 0 ) {
					$from = $full_start;
				}
				$plan[ $account_id ] = self::history_ranges( $from, $today );
			}
		}

		$queued = $plan ? self::queue_chunks( $platform, $plan ) : [];
		self::record_queued( $platform, $queued, $selected, $by_account, $legacy_owner );
		return $queued;
	}

	/**
	 * Bring the progress record in line with what was just queued.
	 *
	 * @param string              $platform
	 * @param array<string, int>  $queued       Account => chunks queued now.
	 * @param string[]            $selected     Selected accounts; [] keeps every other entry.
	 * @param array<string,array> $on_the_way   Accounts that still have chunks queued.
	 * @param string              $legacy_owner See normalize_status().
	 */
	private static function record_queued( $platform, array $queued, array $selected, array $on_the_way = [], $legacy_owner = '' ) {
		$generation = self::backfill_generation( $platform );
		self::update_backfill_status( $platform, static function ( $s ) use ( $queued, $selected, $on_the_way, $generation ) {
			foreach ( $s['accounts'] as $id => $entry ) {
				$id = (string) $id;
				if ( $selected && ! in_array( $id, $selected, true ) ) {
					unset( $s['accounts'][ $id ] ); // no longer selected
				} elseif ( $selected && empty( $on_the_way[ $id ] ) && ! isset( $queued[ $id ] ) ) {
					unset( $s['accounts'][ $id ] ); // finished, or nothing left of it in the queue
				}
			}
			foreach ( $queued as $id => $count ) {
				$s['accounts'][ (string) $id ] = [ 'total' => (int) $count, 'completed' => 0, 'last_error' => '' ];
			}
			$s['generation']  = $generation;
			$s['halted']      = false;
			$s['halt_reason'] = '';
			return $s;
		}, $legacy_owner );
	}

	/**
	 * Schedule history chunks for several accounts.
	 *
	 * Round-robin: the newest 90 days of every account first, then the next
	 * slice of each, so a merchant who ticks five accounts sees today's spend
	 * of all five within minutes instead of waiting for the first account's
	 * three years. One chunk every 30 seconds overall, as before.
	 *
	 * @param string                                        $platform
	 * @param array<string, array<int, array{0:string,1:string}>> $plan Account => ranges, newest first.
	 * @return array<string, int> Account => chunks actually queued.
	 */
	private static function queue_chunks( $platform, array $plan ) {
		if ( ! $plan || ! class_exists( 'Brikpanel_Cron' ) || ! Brikpanel_Cron::is_available() ) {
			return [];
		}

		// Generation 0 means "queued before the generation guard existed", which
		// that guard lets through. Make sure new chunks can be superseded.
		$generation = self::backfill_generation( $platform );
		if ( $generation < 1 ) {
			$generation = self::bump_backfill_generation( $platform );
		}

		$queued = [];
		$offset = 0;
		$now    = time();
		$rounds = max( array_map( 'count', $plan ) );
		for ( $i = 0; $i < $rounds; $i++ ) {
			foreach ( $plan as $account_id => $ranges ) {
				if ( ! isset( $ranges[ $i ] ) ) {
					continue;
				}
				$ok = Brikpanel_Cron::schedule_single(
					$now + $offset,
					self::HOOK_BACKFILL,
					[
						'platform'   => $platform,
						'account_id' => (string) $account_id,
						'start'      => $ranges[ $i ][0],
						'end'        => $ranges[ $i ][1],
						'chunk'      => $i + 1,
						'total'      => count( $ranges ),
						'generation' => $generation,
					]
				);
				if ( $ok ) {
					$queued[ (string) $account_id ] = ( $queued[ (string) $account_id ] ?? 0 ) + 1;
				}
				$offset += self::BACKFILL_CHUNK_INTERVAL_SECONDS;
			}
		}
		return $queued;
	}

	/** First day of the historical import, as a store date. */
	private static function history_start() {
		// self::today() is wp_date()-based, so the start has to be a store day
		// too or the two ends of one range sit on different clocks.
		return brikpanel_store_date( 'Y-m-d', '-' . (int) BRIKPANEL_ADS_BACKFILL_DAYS . ' days' );
	}

	/**
	 * 90-day ranges covering $start..$end, the most recent first, so today's
	 * spend reaches the dashboard within minutes and the history fills in
	 * behind it.
	 *
	 * @return array<int, array{0:string, 1:string}>
	 */
	private static function history_ranges( $start, $end ) {
		return array_reverse( self::date_chunks( $start, $end, self::BACKFILL_CHUNK_DAYS ) );
	}

	/** Option key: account => the last day a pull covered, for one platform. */
	private static function covered_key( $platform ) {
		return 'brikpanel_ads_covered_' . $platform;
	}

	/**
	 * The last day each account was pulled up to, whether or not it spent.
	 *
	 * A paused account stores no rows, so its newest row says nothing about
	 * whether the daily sync still looks at it. Judged by rows alone, every
	 * paused account looked like one with a hole, and its months were queued
	 * again on every save and every Re-authorize.
	 *
	 * @param string        $platform
	 * @param string[]|null $keep When given, entries of other accounts are
	 *                            dropped (the account left the selection).
	 * @return array<string, string> Account => Y-m-d.
	 */
	private static function covered( $platform, $keep = null ) {
		$key = self::covered_key( $platform );
		$map = self::fresh_option( $key, [] );
		$map = is_array( $map ) ? $map : [];
		if ( is_array( $keep ) ) {
			$kept = array_intersect_key( $map, array_flip( $keep ) );
			if ( count( $kept ) !== count( $map ) ) {
				$kept ? update_option( $key, $kept, false ) : delete_option( $key );
			}
			$map = $kept;
		}
		return array_map( 'strval', $map );
	}

	/**
	 * Record the days just pulled. Only ever moves an account forward.
	 *
	 * @param string                $platform
	 * @param array<string, string> $days Account => last day covered.
	 */
	private static function mark_covered( $platform, array $days ) {
		if ( ! $days ) {
			return;
		}
		$key     = self::covered_key( $platform );
		$map     = self::fresh_option( $key, [] );
		$map     = is_array( $map ) ? $map : [];
		$changed = false;
		foreach ( $days as $account_id => $day ) {
			if ( ! isset( $map[ $account_id ] ) || strcmp( (string) $day, (string) $map[ $account_id ] ) > 0 ) {
				$map[ $account_id ] = (string) $day;
				$changed            = true;
			}
		}
		if ( $changed ) {
			update_option( $key, $map, false );
		}
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

	/**
	 * "platform|account" pairs whose "no longer selected" skip was noted in
	 * this process.
	 *
	 * @var array<string, bool>
	 */
	private static $unselected_noted = [];

	/** Option key holding the current backfill generation for a platform. */
	private static function backfill_generation_key( $platform ) {
		return 'brikpanel_ads_backfill_gen_' . $platform;
	}

	/** Option key holding the backfill progress record for a platform. */
	private static function status_key( $platform ) {
		return 'brikpanel_ads_backfill_status_' . $platform;
	}

	/** Current backfill generation (0 when a backfill has never been queued). */
	public static function backfill_generation( $platform ) {
		return (int) self::fresh_option( self::backfill_generation_key( $platform ), 0 );
	}

	/** Invalidate every queued chunk for this platform and return the new generation. */
	private static function bump_backfill_generation( $platform ) {
		$next = self::backfill_generation( $platform ) + 1;
		update_option( self::backfill_generation_key( $platform ), $next, false );
		return $next;
	}

	/**
	 * get_option() past this process's caches.
	 *
	 * A batch worker keeps options it read minutes ago; the browser may have
	 * changed them since. Same treatment as the vault's own fresh read,
	 * including the "notoptions" half: an option recorded as missing stays
	 * missing in the cache after another process creates it.
	 *
	 * @param string $name
	 * @param mixed  $default
	 * @return mixed
	 */
	private static function fresh_option( $name, $default = false ) {
		wp_cache_delete( $name, 'options' );
		$notoptions = wp_cache_get( 'notoptions', 'options' );
		if ( is_array( $notoptions ) && isset( $notoptions[ $name ] ) ) {
			unset( $notoptions[ $name ] );
			wp_cache_set( 'notoptions', $notoptions, 'options' );
		}
		return get_option( $name, $default );
	}

	/**
	 * The progress record in its current shape.
	 *
	 * Shape: [
	 *   'generation'  => int,    // generation the queued chunks carry
	 *   'halted'      => bool,   // the whole import stopped (connection lost)
	 *   'halt_reason' => string, // see halt_reason_text()
	 *   'accounts'    => [ id => [ 'total' => int, 'completed' => int, 'last_error' => string ] ],
	 * ]
	 *
	 * Records written by builds that knew one account hold a single
	 * platform-wide counter (total_chunks / completed_chunks). It belongs to
	 * the account those chunks carry, which the caller passes in.
	 *
	 * @param mixed  $raw
	 * @param string $legacy_owner
	 * @return array
	 */
	private static function normalize_status( $raw, $legacy_owner = '' ) {
		$raw = is_array( $raw ) ? $raw : [];
		$out = [
			'generation'  => (int) ( $raw['generation'] ?? 0 ),
			'halted'      => ! empty( $raw['halted'] ),
			'halt_reason' => (string) ( $raw['halt_reason'] ?? '' ),
			'accounts'    => [],
		];
		if ( isset( $raw['accounts'] ) && is_array( $raw['accounts'] ) ) {
			foreach ( $raw['accounts'] as $id => $entry ) {
				if ( ! is_array( $entry ) || (string) $id === '' ) {
					continue;
				}
				$total = max( 0, (int) ( $entry['total'] ?? 0 ) );
				$out['accounts'][ (string) $id ] = [
					'total'      => $total,
					'completed'  => min( $total, max( 0, (int) ( $entry['completed'] ?? 0 ) ) ),
					'last_error' => (string) ( $entry['last_error'] ?? '' ),
				];
			}
		} elseif ( isset( $raw['total_chunks'] ) && (string) $legacy_owner !== '' ) {
			$total = max( 0, (int) $raw['total_chunks'] );
			$out['accounts'][ (string) $legacy_owner ] = [
				'total'      => $total,
				'completed'  => min( $total, max( 0, (int) ( $raw['completed_chunks'] ?? 0 ) ) ),
				'last_error' => (string) ( $raw['last_error'] ?? '' ),
			];
		}
		return $out;
	}

	/**
	 * The only writer of the progress record.
	 *
	 * Chunks finish in background workers while the merchant saves the
	 * selection in the browser, and both rewrite the same option. A plain
	 * read-change-write lost counts that way, and a worker holding an old
	 * copy put back entries the browser had just removed. So: a database lock
	 * per platform (the table prefix keeps multisite sites apart), a fresh
	 * read, then the change.
	 *
	 * @param string   $platform
	 * @param callable $change       Gets the normalised record; returns the new
	 *                               one, or null to leave it untouched. A record
	 *                               with no accounts and no halt is deleted.
	 * @param string   $legacy_owner See normalize_status().
	 */
	private static function update_backfill_status( $platform, callable $change, $legacy_owner = '' ) {
		global $wpdb;
		$lock   = 'bp_ads_bf_' . substr( md5( $wpdb->prefix ), 0, 8 ) . '_' . $platform;
		$locked = '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK( %s, 5 )', $lock ) );
		try {
			$key  = self::status_key( $platform );
			$next = $change( self::normalize_status( self::fresh_option( $key, [] ), $legacy_owner ) );
			if ( ! is_array( $next ) ) {
				return;
			}
			if ( empty( $next['accounts'] ) && empty( $next['halted'] ) ) {
				delete_option( $key );
			} else {
				update_option( $key, $next, false );
			}
		} finally {
			if ( $locked ) {
				$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK( %s )', $lock ) );
			}
		}
	}

	/**
	 * Progress of the history import for the settings card, summed over the
	 * selected accounts only (an unticked account drops out at once).
	 *
	 * @param string   $platform
	 * @param string[] $selected
	 * @return array{total_chunks:int, completed_chunks:int, halted:bool, halt_reason:string, last_error:string}
	 */
	public static function backfill_progress( $platform, array $selected ) {
		$status = self::normalize_status( get_option( self::status_key( $platform ), [] ), (string) ( $selected[0] ?? '' ) );
		$total  = 0;
		$done   = 0;
		$error  = '';
		foreach ( $status['accounts'] as $id => $entry ) {
			if ( ! in_array( (string) $id, $selected, true ) ) {
				continue;
			}
			$total += $entry['total'];
			$done  += $entry['completed'];
			if ( $error === '' && $entry['last_error'] !== '' && $entry['completed'] < $entry['total'] ) {
				$error = count( $selected ) > 1 ? self::account_error( (string) $id, $entry['last_error'] ) : $entry['last_error'];
			}
		}
		return [
			'total_chunks'     => $total,
			'completed_chunks' => $done,
			'halted'           => $status['halted'] && $done < $total,
			'halt_reason'      => $status['halt_reason'],
			'last_error'       => $error,
		];
	}

	/**
	 * "Account <id>: <message>", for errors that belong to one of several accounts.
	 *
	 * @param string $account_id
	 * @param string $message
	 * @return string
	 */
	public static function account_error( $account_id, $message ) {
		/* translators: 1: ad account ID, 2: error message from the ad platform */
		return sprintf( __( 'Account %1$s: %2$s', 'brikpanel' ), $account_id, $message );
	}

	/**
	 * Backfill chunks of one platform in the queue, across every page.
	 *
	 * The single query this used to run stopped at 200, and twenty accounts
	 * of thirteen chunks on two platforms is 520. Collected first, acted on
	 * afterwards: cancelling while paging moves the offsets and skips rows.
	 *
	 * @param string $platform
	 * @param string $status Action Scheduler status ('pending' or 'in-progress').
	 * @return array<int, array> Action ID => chunk payload.
	 */
	private static function pending_backfill_chunks( $platform, $status = 'pending' ) {
		$out = [];
		if ( ! class_exists( 'Brikpanel_Cron' ) || ! Brikpanel_Cron::is_available() ) {
			return $out;
		}
		$per_page = 200;
		for ( $page = 0; $page < 25; $page++ ) {
			$batch = Brikpanel_Cron::query( [
				'hook'     => self::HOOK_BACKFILL,
				'status'   => $status,
				'per_page' => $per_page,
				'offset'   => $page * $per_page,
				'orderby'  => 'date',
				'order'    => 'ASC',
			] );
			foreach ( $batch as $action_id => $action ) {
				$args    = is_object( $action ) && method_exists( $action, 'get_args' ) ? (array) $action->get_args() : [];
				$payload = isset( $args[0] ) && is_array( $args[0] ) ? $args[0] : [];
				if ( (string) ( $payload['platform'] ?? '' ) === $platform ) {
					$out[ (int) $action_id ] = $payload;
				}
			}
			if ( count( $batch ) < $per_page ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * Stop a backfill that is still queued: settle the progress record and
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
	 *                            removed: the connection died on its own, the
	 *                            merchant is still looking at the card, and the
	 *                            per-account progress is what lets
	 *                            queue_history() resume the unfinished accounts
	 *                            after they reconnect. When empty (an explicit
	 *                            disconnect) the record is removed outright.
	 * @return int Number of queued chunks cancelled.
	 */
	public static function cancel_backfill( $platform, $reason_code = '' ) {
		if ( $reason_code === '' ) {
			// An explicit disconnect deletes the spend, so what was covered goes
			// too. A lost connection keeps it: that is what dates the hole the
			// reconnect has to fill.
			delete_option( self::covered_key( $platform ) );
		}
		self::update_backfill_status( $platform, static function ( $s ) use ( $reason_code ) {
			if ( $reason_code === '' || empty( $s['accounts'] ) ) {
				return [ 'generation' => 0, 'halted' => false, 'halt_reason' => '', 'accounts' => [] ];
			}
			$s['halted']      = true;
			$s['halt_reason'] = $reason_code;
			return $s;
		} );

		if ( ! class_exists( 'Brikpanel_Cron' ) || ! Brikpanel_Cron::is_available() ) {
			return 0;
		}

		self::bump_backfill_generation( $platform );

		// Cancel only this platform's chunks. The other platform may be
		// mid-backfill and must not lose its queue because this one was
		// disconnected, and Brikpanel_Cron::cancel() matches the whole
		// argument list — which differs per chunk — so filter by payload here.
		$cancelled = 0;
		foreach ( array_keys( self::pending_backfill_chunks( $platform ) ) as $action_id ) {
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
	 * guards returned without touching the progress and without writing an
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

		$first = true;
		self::update_backfill_status( $platform, static function ( $s ) use ( $generation, $reason_code, &$first ) {
			if ( $generation > 0 && $s['generation'] > 0 && $s['generation'] !== (int) $generation ) {
				$first = false;
				return null;
			}
			if ( empty( $s['accounts'] ) ) {
				// No progress record to annotate (an old queue outliving its
				// backfill). Still worth one note, never thirteen.
				return null;
			}
			$first            = ! ( $s['halted'] && $s['halt_reason'] === $reason_code );
			$s['halted']      = true;
			$s['halt_reason'] = $reason_code;
			return $s;
		} );

		self::$halt_noted[ $platform ] = true;
		return $first;
	}

	/**
	 * Clear a recorded halt, keeping the per-account progress.
	 *
	 * A halt says "the import stopped and here is why". The moment the platform
	 * is connected again that sentence is no longer true, and without this the
	 * card kept announcing a stopped import forever. The progress itself stays:
	 * a connection that died drops the selection with it, and when the
	 * merchant ticks the accounts again queue_history() uses it to resume the
	 * ones that had not finished.
	 *
	 * @param string $platform
	 * @return bool Whether a halt was cleared.
	 */
	public static function clear_halted_backfill( $platform ) {
		$cleared = false;
		self::update_backfill_status( $platform, static function ( $s ) use ( &$cleared ) {
			if ( ! $s['halted'] ) {
				return null;
			}
			$cleared          = true;
			$s['halted']      = false;
			$s['halt_reason'] = '';
			return $s;
		} );
		unset( self::$halt_noted[ $platform ] );
		return $cleared;
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
	 * Daily sync — re-fetch the last 7 days for every selected account of
	 * every connected platform. Cheap, idempotent, catches the late revisions
	 * ad platforms apply to recent-day numbers.
	 */
	public function handle_daily( array $payload = [] ) {
		// Every connected platform must get its turn even when an earlier one
		// blows up. Rethrowing from inside the loop (which is what this used to
		// do) meant a broken Google connection permanently starved Meta of its
		// daily pull, because Google is iterated first and the exception left
		// the loop before Meta was ever touched. Collect failures instead and
		// raise a single aggregate at the end, so Action Scheduler still marks
		// the run failed and shows the reason on the Scheduled Tasks page. The
		// same holds between the accounts of one platform.
		$failures = [];

		foreach ( [ Brikpanel_Ads_Tokens::PLATFORM_GOOGLE, Brikpanel_Ads_Tokens::PLATFORM_META ] as $platform ) {
			$accounts = Brikpanel_Ads_Tokens::selected_accounts_fresh( $platform );
			if ( ! $accounts ) {
				continue;
			}

			$end   = self::today();
			$start = brikpanel_store_date( 'Y-m-d', '-' . (int) ( BRIKPANEL_ADS_REFRESH_WINDOW_DAYS - 1 ) . ' days' );

			$result = $this->pull_accounts( $platform, $accounts, $start, $end );
			self::record_last_sync( $platform, $start, $end, $result );

			foreach ( $result['failed'] as $account_id => $message ) {
				$failures[] = $platform . ' ' . $account_id . ': ' . $message;
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

		// Everything below decides on data another process may have changed
		// since this worker started, so read it fresh. This also refreshes the
		// vault cache is_connected() answers from.
		$selected = Brikpanel_Ads_Tokens::selected_accounts_fresh( $platform );

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

		// Superseded: the connection was dropped or disconnected after this
		// chunk was queued. Generation 0 means the chunk predates this guard.
		// Never halt here: whatever runs now owns the progress record.
		$current_gen = self::backfill_generation( $platform );
		if ( $generation > 0 && $current_gen > 0 && $generation !== $current_gen ) {
			// Once per process, for the same ring-buffer reason as the halt note.
			if ( ! isset( self::$superseded_noted[ $platform ] ) ) {
				self::$superseded_noted[ $platform ] = true;
				Brikpanel_Ads_Logger::note( 'sync', 'Backfill chunk ' . $platform . ' skipped: superseded by a newer backfill.' );
			}
			return;
		}

		// Never write rows for an account that is no longer selected. Those
		// rows would be invisible on the settings card yet still counted by
		// the dashboard totals. The other accounts' imports carry on.
		if ( ! in_array( $account_id, $selected, true ) ) {
			$this->note_unselected( $platform, $account_id );
			return;
		}

		try {
			$rows = $this->fetch_window( $platform, $account_id, $start, $end );

			// The fetch takes seconds; the merchant may have unticked the
			// account meanwhile, and its rows were deleted when they did.
			if ( ! self::still_selected( $platform, $account_id ) ) {
				$this->note_unselected( $platform, $account_id );
				return;
			}
			Brikpanel_Ads_Store::bulk_upsert( $platform, $account_id, $rows );
			self::refresh_dashboard( $rows );
			self::mark_covered( $platform, [ $account_id => $end ] );

			self::update_backfill_status( $platform, static function ( $s ) use ( $account_id, $generation ) {
				if ( ! isset( $s['accounts'][ $account_id ] ) ) {
					return null;
				}
				if ( $generation > 0 && $s['generation'] > 0 && $s['generation'] !== $generation ) {
					return null; // counts belong to a newer run
				}
				$entry               = $s['accounts'][ $account_id ];
				$entry['completed']  = min( $entry['total'], $entry['completed'] + 1 );
				$entry['last_error'] = '';
				$s['accounts'][ $account_id ] = $entry;

				// Every account imported: nothing left to report.
				foreach ( $s['accounts'] as $other ) {
					if ( $other['completed'] < $other['total'] ) {
						return $s;
					}
				}
				return [ 'generation' => $s['generation'], 'halted' => false, 'halt_reason' => '', 'accounts' => [] ];
			}, $account_id );
		} catch ( \Throwable $e ) {
			Brikpanel_Ads_Logger::log( 'sync', 'Backfill chunk ' . $chunk . '/' . $total . ' ' . $platform . ' ' . $account_id . ' failed: ' . $e->getMessage() );
			$message = $e->getMessage();
			self::update_backfill_status( $platform, static function ( $s ) use ( $account_id, $message ) {
				if ( ! isset( $s['accounts'][ $account_id ] ) ) {
					return null;
				}
				$s['accounts'][ $account_id ]['last_error'] = $message;
				return $s;
			}, $account_id );
			throw $e;
		}
	}

	/**
	 * Inline sync for the "Sync now" button on the settings page and the
	 * dashboard's update button. Re-fetches the last 7 days for every selected
	 * account of one platform.
	 *
	 * @param string $platform
	 * @param int    $deadline Unix time after which no new account is started (0 = none).
	 * @return array{rows:int, days:int, accounts:int, failed:array<string,string>, deferred:string[]}
	 *         days = distinct days returned; failed = account => message;
	 *         deferred = accounts left for the next daily sync.
	 * @throws \Throwable When nothing is connected or selected, or every account that was tried failed.
	 */
	public function run_inline( $platform, $deadline = 0 ) {
		$desc = Brikpanel_Ads_Tokens::describe( $platform );
		if ( ! $desc['connected'] ) {
			throw new \RuntimeException( __( 'Not connected.', 'brikpanel' ) );
		}
		$accounts = $desc['accounts'];
		if ( empty( $accounts ) ) {
			throw new \RuntimeException( __( 'Choose at least one ad account first.', 'brikpanel' ) );
		}

		$end   = self::today();
		$start = brikpanel_store_date( 'Y-m-d', '-' . (int) ( BRIKPANEL_ADS_REFRESH_WINDOW_DAYS - 1 ) . ' days' );

		$result = $this->pull_accounts( $platform, $accounts, $start, $end, (int) $deadline );
		self::record_last_sync( $platform, $start, $end, $result );

		$tried = count( $accounts ) - count( $result['deferred'] );
		if ( $tried > 0 && count( $result['failed'] ) >= $tried ) {
			$account_id = (string) array_key_first( $result['failed'] );
			$message    = $result['failed'][ $account_id ];
			throw new \RuntimeException( count( $accounts ) > 1 ? self::account_error( $account_id, $message ) : $message );
		}
		return $result;
	}

	// =========================================================================
	// Internal worker
	// =========================================================================

	/**
	 * Pull one date window for several accounts, one after another.
	 *
	 * An error that belongs to one account (the platform refused that
	 * account) is recorded and the next account is tried. An error that would
	 * repeat for every account (no connection, rate limit, the proxy or the
	 * platform down) stops the platform at once: trying the rest would only
	 * cost each of them three attempts with back-off, and more rate limiting.
	 *
	 * @param string   $platform
	 * @param string[] $accounts
	 * @param string   $start
	 * @param string   $end
	 * @param int      $deadline Unix time after which no new account is started (0 = none).
	 * @return array{rows:int, days:int, accounts:int, failed:array<string,string>, deferred:string[]}
	 */
	private function pull_accounts( $platform, array $accounts, $start, $end, $deadline = 0 ) {
		$dates    = [];
		$rows     = 0;
		$done     = 0;
		$failed   = [];
		$deferred = [];
		$covered  = [];

		foreach ( array_values( $accounts ) as $i => $account_id ) {
			if ( $deadline > 0 && time() >= $deadline ) {
				$deferred = array_slice( array_values( $accounts ), $i );
				break;
			}
			// Resets the counter, so each account gets its own allowance.
			@set_time_limit( 90 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			try {
				$fetched = $this->fetch_window( $platform, $account_id, $start, $end );
				if ( ! self::still_selected( $platform, $account_id ) ) {
					continue; // unticked while we fetched; its rows are gone
				}
				$rows += Brikpanel_Ads_Store::bulk_upsert( $platform, $account_id, $fetched );
				self::refresh_dashboard( $fetched );
				foreach ( $fetched as $row ) {
					if ( isset( $row['date'] ) ) {
						$dates[ (string) $row['date'] ] = true;
					}
				}
				$covered[ $account_id ] = $end;
				$done++;
			} catch ( \Throwable $e ) {
				$failed[ $account_id ] = $e->getMessage();
				Brikpanel_Ads_Logger::log( 'sync', 'Sync ' . $platform . ' ' . $account_id . ' failed: ' . $e->getMessage() );
				if ( self::is_platform_wide_failure( $e ) ) {
					foreach ( array_slice( array_values( $accounts ), $i + 1 ) as $rest ) {
						$failed[ $rest ] = $e->getMessage();
					}
					break;
				}
			}
		}

		self::mark_covered( $platform, $covered );

		return [
			'rows'     => $rows,
			'days'     => count( $dates ),
			'accounts' => $done,
			'failed'   => $failed,
			'deferred' => $deferred,
		];
	}

	/**
	 * Whether a pull failure would repeat for every account of the platform.
	 *
	 * @param \Throwable $e
	 * @return bool
	 */
	private static function is_platform_wide_failure( \Throwable $e ) {
		if ( $e instanceof Brikpanel_Ads_Meta_Exception ) {
			// Rate limits (4, 17, 32, 613) and a dead session (102, 190).
			if ( in_array( (int) $e->meta_code, [ 4, 17, 32, 102, 190, 613 ], true ) ) {
				return true;
			}
			$code = (int) $e->http_code;
		} elseif ( $e instanceof Brikpanel_Ads_Google_Exception ) {
			if ( in_array( $e->api_reason, [ 'killswitch', 'not_connected', 'UNAUTHENTICATED', 'RESOURCE_EXHAUSTED' ], true ) ) {
				return true;
			}
			$code = (int) $e->http_code;
		} else {
			return true; // not an answer from the platform at all
		}
		// 0: no answer or no connection; 2xx: an answer that failed verification.
		return $code === 0 || $code === 401 || $code === 429 || $code >= 500 || ( $code >= 200 && $code < 300 );
	}

	/**
	 * Fetch one window of daily rows for one account.
	 *
	 * @return array<int, array>
	 * @throws \Throwable
	 */
	private function fetch_window( $platform, $account_id, $start, $end ) {
		if ( $platform === Brikpanel_Ads_Tokens::PLATFORM_GOOGLE ) {
			return ( new Brikpanel_Ads_Google_Client() )->fetch_spend( $account_id, $start, $end );
		}
		if ( $platform === Brikpanel_Ads_Tokens::PLATFORM_META ) {
			return ( new Brikpanel_Ads_Meta_Client() )->fetch_spend( $account_id, $start, $end );
		}
		throw new \InvalidArgumentException( 'Unknown platform: ' . $platform );
	}

	/**
	 * Let the dashboard show newly written spend now.
	 *
	 * bulk_upsert() bumps only the ads module's own "is there any data" key,
	 * so the dashboard kept its ROAS and Net profit for up to ten minutes
	 * after an import: a merchant who had just ticked a second account saw
	 * the figures of one. Coalesced per request, so a batch of chunks costs
	 * at most two option writes.
	 *
	 * @param array $rows What was written; nothing to refresh when empty.
	 */
	private static function refresh_dashboard( array $rows ) {
		if ( $rows && function_exists( 'brikpanel_bust_data_caches' ) ) {
			brikpanel_bust_data_caches();
		}
	}

	/** Whether the account is still ticked, read past this process's cache. */
	private static function still_selected( $platform, $account_id ) {
		return in_array( (string) $account_id, Brikpanel_Ads_Tokens::selected_accounts_fresh( $platform ), true );
	}

	/** One log note per account and process for chunks of an unticked account. */
	private function note_unselected( $platform, $account_id ) {
		$key = $platform . '|' . $account_id;
		if ( isset( self::$unselected_noted[ $key ] ) ) {
			return;
		}
		self::$unselected_noted[ $key ] = true;
		Brikpanel_Ads_Logger::note( 'sync', 'Backfill chunk ' . $platform . ' ' . $account_id . ' skipped: account no longer selected.' );
	}

	/**
	 * Remember the outcome of a daily or inline pull for the settings card.
	 *
	 * The failed account and the platform's own message are stored as they
	 * are and put into a sentence when shown, in the language of whoever looks.
	 *
	 * @param string $platform
	 * @param string $start
	 * @param string $end
	 * @param array  $result From pull_accounts().
	 */
	private static function record_last_sync( $platform, $start, $end, array $result ) {
		$record = [ 'ts' => time(), 'start' => $start, 'end' => $end, 'ok' => empty( $result['failed'] ) ];
		if ( ! empty( $result['failed'] ) ) {
			$account_id              = (string) array_key_first( $result['failed'] );
			$record['error']         = (string) $result['failed'][ $account_id ];
			$record['error_account'] = $account_id;
		}
		update_option( 'brikpanel_ads_last_sync_' . $platform, $record, false );
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
