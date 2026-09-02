<?php
/**
 * BrikPanel — Cron / Background Jobs API
 *
 * Thin wrapper around WooCommerce's bundled Action Scheduler. All BrikPanel
 * background jobs are tagged with the `brikpanel` group so the dedicated
 * "Scheduled Tasks" admin page can isolate them from WooCommerce's own
 * actions (sync, email queue, analytics, etc.) without showing the user
 * the entire AS firehose.
 *
 * Why Action Scheduler instead of a custom queue:
 *  - Already loaded by WooCommerce (no new dependency).
 *  - Battle-tested at scale: claim-based locking, retry on Throwable,
 *    persistent storage, per-action logs, supports async + scheduled +
 *    recurring out of the box.
 *  - Built-in store of last-run / next-run / last-error per action.
 *
 * @package BrikPanel
 * @since   3.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Public façade for scheduling BrikPanel background work.
 *
 * Usage:
 *   Brikpanel_Cron::register_handler( 'brikpanel_sheets_push', function( $payload ) {
 *       Brikpanel_Sheets_Sync::push( (array) $payload );
 *   } );
 *
 *   Brikpanel_Cron::enqueue_async( 'brikpanel_sheets_push', [ 'spreadsheet_id' => 'abc' ] );
 *   Brikpanel_Cron::schedule_recurring( 'brikpanel_oauth_token_refresh', HOUR_IN_SECONDS );
 */
class Brikpanel_Cron {

	/** Group used to isolate BrikPanel actions from other AS clients. */
	const GROUP = 'brikpanel';

	/**
	 * Every Action Scheduler function this class calls WITHOUT a fallback.
	 *
	 * THE RULE THIS LIST ENFORCES: a new as_* call either joins this list or
	 * ships with a fallback of its own. Nothing else is allowed, because the
	 * failure mode is not a broken feature — it is a fatal on `init`, which
	 * takes the whole site down and locks the merchant out of WP-CLI too.
	 *
	 * `as_has_scheduled_action` is deliberately ABSENT: it does not exist on
	 * the Action Scheduler that this plugin's own declared WooCommerce floor
	 * ships, and has_scheduled() answers the question without it. Listing it
	 * here would close the gate on those stores instead of serving them.
	 *
	 * @since 3.2.82
	 */
	const REQUIRED_FUNCTIONS = [
		'as_enqueue_async_action',
		'as_schedule_single_action',
		'as_schedule_recurring_action',
		'as_get_scheduled_actions',
		'as_unschedule_action',
	];

	/**
	 * The two statuses that mean "this job still has work to do". Written as
	 * literals rather than as ActionScheduler_Store constants because this
	 * array is read in has_scheduled(), which runs precisely when the store
	 * class may not be the one we expect. The values are frozen in the AS
	 * schema and have not changed across any version this plugin supports.
	 *
	 * @since 3.2.82
	 */
	const LIVE_STATUSES = [ 'pending', 'in-progress' ];

	/** Default per-action retry budget (Action Scheduler is unaware of this; we
	 * track it ourselves via last_error logging — AS itself retries
	 * indefinitely on Throwable, so we cap by tracking attempts). */
	const DEFAULT_MAX_RETRIES = 3;

	/** Backoff schedule (seconds) used when re-enqueueing after a failure.
	 * Index = attempt number (0-based). Anything past the array length uses
	 * the last entry. */
	const BACKOFF_SECONDS = [ 60, 300, 900 ];

	/**
	 * Hooks registered via register_handler() so the admin page can list
	 * known job types even when no row currently exists in the AS table.
	 *
	 * Values are stored exactly as the caller passed them — either a literal
	 * metadata array or a callable that returns one. Callables stay unresolved
	 * until get_registered_hooks() actually needs the labels, which keeps the
	 * `__()` calls inside them off every front-end and AJAX request. See
	 * register_handler() for why that matters.
	 *
	 * Shape: [ hook_name => array|callable ]
	 *
	 * @var array<string, array|callable>
	 */
	private static $registered_hooks = [];

	/**
	 * Memoised output of get_registered_hooks(), so lazy metadata callables
	 * run at most once per request.
	 *
	 * @var array<string, array{label: string, description: string}>|null
	 */
	private static $resolved_hooks = null;

	// =========================================================================
	// Availability
	// =========================================================================

	/**
	 * Whether the Action Scheduler API is currently loaded.
	 *
	 * Action Scheduler is bundled with WooCommerce and loaded during
	 * plugins_loaded. Anything that schedules work must run on `init` or
	 * later — calling these methods before AS bootstraps is a no-op that
	 * returns false.
	 *
	 * The list is what matters here, not the check. This used to name three
	 * functions and the class called seven; the four it did not name were the
	 * whole bug (see has_scheduled()). REQUIRED_FUNCTIONS now carries every
	 * one of them that has no fallback, so the next as_* call added to this
	 * class is a one-line entry rather than another outage.
	 *
	 * @return bool
	 */
	public static function is_available() {
		foreach ( self::REQUIRED_FUNCTIONS as $function ) {
			if ( ! function_exists( $function ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Whether a job is pending OR in progress for this hook + args, asked in a
	 * way that works on every Action Scheduler this plugin claims to support.
	 *
	 * WHY THIS WRAPPER EXISTS. `as_has_scheduled_action()` arrived in Action
	 * Scheduler 3.3.0. This plugin's header declares `WC requires at least:
	 * 4.0`, and WooCommerce 4.0 ships Action Scheduler 3.1.2, where the
	 * function does not exist. Calling it unguarded from a hook that runs on
	 * `init` took the whole SITE down with a fatal — not the plugin, the site —
	 * and because the fatal fired before WP-CLI could load WordPress, the
	 * merchant could not even switch the plugin off. Measured on WP 5.8.3 /
	 * WC 4.0.0 / PHP 7.4: front page HTTP 500, and `wp plugin deactivate`
	 * died with the same error. The only way out was deleting the folder
	 * over FTP.
	 *
	 * is_available() did not catch it because it checked three as_* functions
	 * and all three exist in 3.1.2. That is the shape of the bug worth
	 * remembering: the gate was not wrong, it was INCOMPLETE.
	 *
	 * ANSWERING "true" ON A THROW IS DELIBERATE. A store that cannot answer
	 * the question is a store we must not pile work onto: schedule_recurring()
	 * treats true as "already there, leave it alone" and runs again on the
	 * next request (these registrations live on `init`), while is_scheduled()
	 * treats true as "one is already queued", which is the safe answer for the
	 * one job in this plugin that duplicates badly (the BrikControl manual
	 * scan). This matches how the rest of the plugin already fails —
	 * recurring_interval_matches() below and the runner's has_live_action().
	 *
	 * @since 3.2.82
	 * @param string     $hook
	 * @param array|null $args Already wrapped by the caller, as AS expects, or
	 *                         null to match the hook regardless of args.
	 * @return bool
	 */
	private static function has_scheduled( $hook, $args ) {
		try {
			if ( function_exists( 'as_has_scheduled_action' ) ) {
				return (bool) as_has_scheduled_action( $hook, $args, self::GROUP );
			}
			return self::has_scheduled_fallback( $hook, $args );
		} catch ( \Throwable $e ) {
			return true;
		}
	}

	/**
	 * The same question, answered without as_has_scheduled_action().
	 *
	 * Split out rather than inlined above so it can be exercised on a modern
	 * Action Scheduler, where the dispatcher would never reach it. A fallback
	 * only the oldest supported store ever runs is a fallback nobody tests, and
	 * that is how it comes to be broken on the day it is needed. See
	 * tools/test-as-floor.php, which calls this method directly.
	 *
	 * A status filter is not optional. Asking without one also matches
	 * COMPLETE and FAILED rows, which would report a finished job as still
	 * scheduled and stop the recurring registration from ever renewing.
	 *
	 * @since 3.2.82
	 * @param string     $hook
	 * @param array|null $args
	 * @return bool
	 */
	private static function has_scheduled_fallback( $hook, $args ) {
		if ( function_exists( 'as_get_scheduled_actions' ) ) {
			// ONE STATUS PER CALL. Action Scheduler 3.1.2 throws
			// InvalidArgumentException on an array of statuses ("Invalid action
			// status: \"Array\""), measured, so the obvious single query would
			// have swapped one fatal for another on the same stores.
			foreach ( self::LIVE_STATUSES as $status ) {
				$query = [
					'hook'     => $hook,
					'group'    => self::GROUP,
					'status'   => $status,
					'per_page' => 1,
					'return'   => 'ids',
				];
				// Omitted rather than passed as null: "any args" is expressed
				// by the key being absent, and 3.1.2 does not read null the
				// way the modern function does.
				if ( $args !== null ) {
					$query['args'] = $args;
				}
				$found = as_get_scheduled_actions( $query );
				if ( ! empty( $found ) ) {
					return true;
				}
			}
			return false;
		}

		// Last resort, and unreachable through every public entry point on this
		// class: as_get_scheduled_actions is in REQUIRED_FUNCTIONS, so if it is
		// missing then is_available() already returned false and no caller got
		// this far. It stays because it costs nothing and it is the honest
		// answer if a future caller ever asks without the gate. Note what it
		// gives up: it sees PENDING only, so a recurring job that is mid-run
		// reads as absent and could be registered twice.
		if ( function_exists( 'as_next_scheduled_action' ) ) {
			return false !== as_next_scheduled_action( $hook, $args, self::GROUP );
		}

		return false;
	}

	/**
	 * Run an Action Scheduler write and turn a THROW into "not scheduled".
	 *
	 * The gate above answers "can Action Scheduler take work", and it is right
	 * as far as it goes — but it cannot see one state, and that state fatals.
	 * On a store whose AS is mid-migration between its post store and its table
	 * store, ActionScheduler_HybridStore raises
	 * `RuntimeException: Error saving action: Incorrect table name ''`.
	 * Measured in the WC 4.0 container: during that window Action Scheduler
	 * cannot even schedule its OWN migration action, so this is genuinely its
	 * bootstrap and not a defect of ours. What IS ours is that the exception
	 * travelled up through `init` and took the request with it — HTTP 500 on
	 * every page of a brand new store, for something that resolves itself
	 * seconds later.
	 *
	 * Returning false is the honest answer and one every caller already
	 * handles: it is what the gate itself returns when AS cannot take work.
	 * The job is picked up by the next request, because these registrations are
	 * idempotent and run on `init`.
	 *
	 * @since 3.2.82
	 * @param callable $write
	 * @return int|false
	 */
	private static function guarded( callable $write ) {
		try {
			return $write();
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	// =========================================================================
	// Handler registration
	// =========================================================================

	/**
	 * Register a callback for a job hook.
	 *
	 * Wraps the callback in a thin error guard so a Throwable inside the
	 * handler is surfaced to Action Scheduler's failure path (which marks
	 * the action as `failed` and records the message) rather than fatal-ing
	 * the worker request.
	 *
	 * The metadata (label/description) is purely cosmetic — it powers the
	 * "Job type" column on the admin page and is read nowhere else.
	 *
	 * Handlers register on every request (a front-end page view can be the one
	 * that runs the Action Scheduler queue), so anything evaluated here runs on
	 * every request too. Passing the metadata as a literal array means its
	 * `__()` calls execute on front-end page loads and public AJAX requests
	 * that will never render the admin page — wasted work, and translation
	 * plugins that harvest gettext on the front end (TranslatePress, Loco,
	 * WPML's String Translation) then record these admin-only strings as if
	 * they were part of the storefront.
	 *
	 * Prefer passing a callable that returns the metadata array. It is invoked
	 * only when get_registered_hooks() is called — i.e. on the Scheduled Tasks
	 * admin page. The `__()` literals still sit in the source, so `make-pot`
	 * extraction is unaffected. Literal arrays remain accepted for
	 * compatibility.
	 *
	 * @param string         $hook     Action hook (must be unique across the plugin; convention: `brikpanel_*`).
	 * @param callable       $callback Handler. Receives the action args as a single argument.
	 * @param array|callable $meta     { Optional metadata for the admin UI, or a callable returning it.
	 *     @type string $label       Human-readable label. Defaults to a humanised hook name.
	 *     @type string $description One-line description shown on hover/expand.
	 * }
	 * @return void
	 */
	public static function register_handler( $hook, callable $callback, $meta = [] ) {
		$hook = (string) $hook;
		if ( $hook === '' ) {
			return;
		}

		self::$registered_hooks[ $hook ] = ( is_array( $meta ) || is_callable( $meta ) ) ? $meta : [];
		self::$resolved_hooks            = null;

		add_action( $hook, function ( ...$args ) use ( $callback, $hook ) {
			try {
				call_user_func( $callback, ...$args );
			} catch ( \Throwable $e ) {
				// Re-throw so Action Scheduler marks the action as failed and
				// writes the message to the action log. We log to PHP error
				// log too in case the worker is being introspected.
				error_log( sprintf(
					'[BrikPanel Cron] Handler "%s" threw: %s in %s:%d',
					$hook,
					$e->getMessage(),
					$e->getFile(),
					$e->getLine()
				) );
				throw $e;
			}
		}, 10, PHP_INT_MAX );
	}

	/**
	 * Whether a hook has been registered as a known job type.
	 *
	 * @param string $hook
	 * @return bool
	 */
	public static function has_handler( $hook ) {
		return isset( self::$registered_hooks[ $hook ] );
	}

	/**
	 * All registered job types, keyed by hook.
	 *
	 * Resolves any lazy metadata callables (see register_handler()) and
	 * memoises the result, so the translation calls inside them run at most
	 * once per request — and only on requests that actually ask for labels.
	 *
	 * @return array<string, array{label: string, description: string}>
	 */
	public static function get_registered_hooks() {
		if ( self::$resolved_hooks !== null ) {
			return self::$resolved_hooks;
		}

		$resolved = [];
		foreach ( self::$registered_hooks as $hook => $meta ) {
			// Test is_array() first, never is_callable() first: PHP reports a
			// two-element [class, method] array as callable, so a literal meta
			// array that happened to take that shape would be *invoked* instead
			// of read. Arrays are always data here; only non-arrays can be lazy.
			if ( ! is_array( $meta ) && is_callable( $meta ) ) {
				$meta = call_user_func( $meta );
			}
			if ( ! is_array( $meta ) ) {
				$meta = [];
			}

			$resolved[ $hook ] = [
				'label'       => isset( $meta['label'] ) && $meta['label'] !== ''
					? (string) $meta['label']
					: self::humanise_hook( $hook ),
				'description' => isset( $meta['description'] ) ? (string) $meta['description'] : '',
			];
		}

		return self::$resolved_hooks = $resolved;
	}

	// =========================================================================
	// Scheduling
	// =========================================================================

	/**
	 * Enqueue a job to run as soon as the next AS worker tick fires.
	 *
	 * @param string $hook
	 * @param array  $args   Positional arguments. Action Scheduler unpacks
	 *                       array entries as separate handler arguments.
	 * @param array  $opts   {
	 *     @type bool $unique When true, no duplicate is enqueued if the same
	 *                        hook+args is already pending. Defaults to false.
	 *     @type int  $priority Ordering hint for AS workers (lower = sooner).
	 * }
	 * @return int|false Action ID on success, false if AS unavailable.
	 */
	public static function enqueue_async( $hook, array $args = [], array $opts = [] ) {
		if ( ! self::is_available() ) {
			return false;
		}
		$unique   = ! empty( $opts['unique'] );
		$priority = isset( $opts['priority'] ) ? (int) $opts['priority'] : 10;
		// AS expects args wrapped as a positional list; we always pass a
		// single payload so handlers can use `function( $payload )`.
		return self::guarded( static function () use ( $hook, $args, $unique, $priority ) {
			return (int) as_enqueue_async_action( $hook, [ $args ], self::GROUP, $unique, $priority );
		} );
	}

	/**
	 * Run a job once at a specific time.
	 *
	 * @param int    $timestamp Unix timestamp.
	 * @param string $hook
	 * @param array  $args
	 * @param array  $opts See enqueue_async().
	 * @return int|false
	 */
	public static function schedule_single( $timestamp, $hook, array $args = [], array $opts = [] ) {
		if ( ! self::is_available() ) {
			return false;
		}
		$unique   = ! empty( $opts['unique'] );
		$priority = isset( $opts['priority'] ) ? (int) $opts['priority'] : 10;
		return self::guarded( static function () use ( $timestamp, $hook, $args, $unique, $priority ) {
			return (int) as_schedule_single_action( (int) $timestamp, $hook, [ $args ], self::GROUP, $unique, $priority );
		} );
	}

	/**
	 * Schedule a job to recur every N seconds.
	 *
	 * Idempotent — if the same hook+args is already scheduled in the
	 * BrikPanel group, this is a no-op. This is the safe pattern for
	 * registering recurring jobs from `init` callbacks (which run on every
	 * request).
	 *
	 * @param string $hook
	 * @param int    $interval_seconds
	 * @param array  $args
	 * @param int    $start_offset Seconds from now for the first run.
	 *                             Defaults to one interval (no immediate run).
	 * @return int|false Action ID, true if already scheduled (no-op), false on failure.
	 */
	public static function schedule_recurring( $hook, $interval_seconds, array $args = [], $start_offset = null ) {
		if ( ! self::is_available() ) {
			return false;
		}
		$interval = max( 60, (int) $interval_seconds );
		if ( self::has_scheduled( $hook, [ $args ] ) ) {
			// as_has_scheduled_action matches on hook + args + group only — the
			// recurrence is NOT part of that identity. Returning early on a
			// match therefore pinned the cadence forever: a merchant moving a
			// sync from every 15 minutes to every 2 kept the 15-minute action,
			// with the UI happily showing the new setting. Compare the live
			// recurrence and re-create the action when it no longer matches.
			if ( self::recurring_interval_matches( $hook, $args, $interval ) ) {
				return true;
			}
			self::cancel( $hook, $args );
		}
		$first_run = time() + ( $start_offset !== null ? (int) $start_offset : $interval );
		return self::guarded( static function () use ( $first_run, $interval, $hook, $args ) {
			return (int) as_schedule_recurring_action( $first_run, $interval, $hook, [ $args ], self::GROUP, false, 10 );
		} );
	}

	/**
	 * Whether the pending recurring action for this hook already runs at the
	 * given interval. Returns true when we cannot tell, so an unexpected
	 * Action Scheduler shape degrades to the old "leave it alone" behaviour
	 * rather than rescheduling on every request.
	 *
	 * @param string $hook
	 * @param array  $args
	 * @param int    $interval Seconds.
	 * @return bool
	 */
	private static function recurring_interval_matches( $hook, array $args, $interval ) {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			return true;
		}
		// Guarded for the same reason the writes are: this runs on `init`, and a
		// store mid-migration throws rather than answers. "Cannot tell" already
		// means "leave it alone" everywhere else in this method, so a throw
		// joins that path instead of taking the request down.
		try {
			$actions = as_get_scheduled_actions(
				[
					'hook'     => $hook,
					'args'     => [ $args ],
					'group'    => self::GROUP,
					'status'   => 'pending',
					'per_page' => 1,
				],
				OBJECT
			);
		} catch ( \Throwable $e ) {
			return true;
		}
		if ( empty( $actions ) || ! is_array( $actions ) ) {
			return true;
		}
		$action = reset( $actions );
		if ( ! is_object( $action ) || ! method_exists( $action, 'get_schedule' ) ) {
			return true;
		}
		$schedule = $action->get_schedule();
		if ( ! is_object( $schedule ) || ! method_exists( $schedule, 'get_recurrence' ) ) {
			return true;
		}
		$current = $schedule->get_recurrence();
		if ( ! is_numeric( $current ) ) {
			return true; // cron-expression schedule: not ours to second-guess
		}
		return (int) $current === (int) $interval;
	}

	/**
	 * Cancel all pending occurrences of a hook (optionally narrowed by args).
	 *
	 * @param string     $hook
	 * @param array|null $args If null, cancels every pending action for the hook.
	 * @return int Number of actions cancelled.
	 */
	public static function cancel( $hook, $args = null ) {
		if ( ! self::is_available() ) {
			return 0;
		}
		$payload = $args === null ? null : [ $args ];
		$count   = 0;
		// as_unschedule_all_actions returns no count, so we query first to
		// produce a useful number for callers/tests.
		// 'pending' as a literal, not ActionScheduler_Store::STATUS_PENDING:
		// cancel() is reachable from front-end `init` (every disabled Sheets
		// sync calls it) and only the function gate stands in front of it, so
		// it must not depend on a class reference resolving. Same constant
		// value in every AS release. See LIVE_STATUSES.
		$pending = self::query( [
			'hook'     => $hook,
			'status'   => 'pending',
			'per_page' => 200,
		] );
		foreach ( $pending as $action_id => $_action ) {
			$row_args = null;
			if ( $payload !== null ) {
				// Skip rows whose args don't match.
				$row_args = self::get_action_args( $action_id );
				if ( $row_args !== $payload ) {
					continue;
				}
			}
			$unscheduled = self::guarded( static function () use ( $hook, $row_args ) {
				as_unschedule_action( $hook, $row_args ?? null, self::GROUP );
				return 1;
			} );
			if ( $unscheduled === false ) {
				// A store that cannot delete is a store mid-migration; the next
				// request finds the row still pending and tries again.
				continue;
			}
			$count++;
		}
		return $count;
	}

	/**
	 * Whether a job is currently pending for the given hook (+args).
	 *
	 * @param string $hook
	 * @param array  $args
	 * @return bool
	 */
	public static function is_scheduled( $hook, array $args = [] ) {
		if ( ! self::is_available() ) {
			return false;
		}
		return self::has_scheduled( $hook, [ $args ] );
	}

	/**
	 * Whether ANY job is pending or in progress for this hook, whatever its
	 * args.
	 *
	 * The args-blind counterpart to is_scheduled(). Exists because a caller
	 * that is sweeping a hook away does not know — and must not have to know —
	 * which payloads are sitting in the queue. Its one caller today is the
	 * Google Sheets module's disabled-path sweep, which used to ask Action
	 * Scheduler directly and was one of the three calls that took a WC 4.0
	 * store offline.
	 *
	 * @since 3.2.82
	 * @param string $hook
	 * @return bool
	 */
	public static function has_any_scheduled( $hook ) {
		if ( ! self::is_available() ) {
			return false;
		}
		return self::has_scheduled( $hook, null );
	}

	// =========================================================================
	// Querying — for the admin page
	// =========================================================================

	/**
	 * Query BrikPanel actions from the AS store.
	 *
	 * Wraps `as_get_scheduled_actions` with `group => self::GROUP` pre-set so
	 * we can never accidentally surface unrelated WC actions in the UI.
	 *
	 * PASS A `status`. On the Action Scheduler bundled with WooCommerce 4.0
	 * (3.1.2) a CANCELLED row has no schedule date, and hydrating it throws
	 * `TypeError: Argument 1 passed to ActionScheduler_Abstract_Schedule::
	 * __construct() must be an instance of DateTime, null given` — measured,
	 * five of nineteen rows on a store that had switched one sync off. An
	 * unfiltered query returns those rows and therefore throws. Every caller
	 * in this plugin filters (all of them on `pending`), which is why no
	 * shipped path hits it; a new caller that does not, would.
	 *
	 * @param array $args See as_get_scheduled_actions(). Anything passed
	 *                    overrides the defaults except `group`, which is
	 *                    always forced to `self::GROUP`.
	 * @return array<int, ActionScheduler_Action>
	 */
	public static function query( array $args = [] ) {
		if ( ! self::is_available() || ! function_exists( 'as_get_scheduled_actions' ) ) {
			return [];
		}
		$args['group'] = self::GROUP;
		if ( ! isset( $args['per_page'] ) ) {
			$args['per_page'] = 50;
		}
		// cancel() calls this on front-end `init` for every switched-off Google
		// Sheets sync, so a store that throws instead of answering would take
		// the page with it. An empty result is what every caller already gets
		// when Action Scheduler cannot be reached at all.
		try {
			return as_get_scheduled_actions( $args );
		} catch ( \Throwable $e ) {
			return [];
		}
	}

	/**
	 * Count BrikPanel actions matching a status filter.
	 *
	 * @param string|array $status Single status or array of statuses (matches AS constants).
	 * @return int
	 */
	public static function count( $status = '' ) {
		if ( ! class_exists( 'ActionScheduler' ) ) {
			return 0;
		}
		try {
			$store = ActionScheduler::store();
		} catch ( \Throwable $e ) {
			return 0;
		}
		$query = [ 'group' => self::GROUP ];
		if ( $status !== '' && $status !== [] ) {
			$query['status'] = $status;
		}
		return (int) $store->query_actions( $query, 'count' );
	}

	/**
	 * Pull the args array for a stored action, normalised to a plain array.
	 *
	 * @param int $action_id
	 * @return array
	 */
	public static function get_action_args( $action_id ) {
		if ( ! class_exists( 'ActionScheduler' ) ) {
			return [];
		}
		try {
			$action = ActionScheduler::store()->fetch_action( (int) $action_id );
		} catch ( \Throwable $e ) {
			return [];
		}
		if ( ! $action || ! method_exists( $action, 'get_args' ) ) {
			return [];
		}
		return (array) $action->get_args();
	}

	// =========================================================================
	// Manual actions (admin UI)
	// =========================================================================

	/**
	 * Execute a single pending action immediately, bypassing the worker.
	 *
	 * Used by the "Run now" button on the admin page. Acquires the action
	 * via the store's claim mechanism so a concurrent worker can't pick up
	 * the same row.
	 *
	 * @param int $action_id
	 * @return array{ok: bool, message: string}
	 */
	public static function run_now( $action_id ) {
		if ( ! class_exists( 'ActionScheduler' ) ) {
			return [ 'ok' => false, 'message' => __( 'Action Scheduler is not loaded.', 'brikpanel' ) ];
		}
		$action_id = (int) $action_id;
		if ( $action_id <= 0 ) {
			return [ 'ok' => false, 'message' => __( 'Invalid action ID.', 'brikpanel' ) ];
		}
		try {
			$store  = ActionScheduler::store();
			$action = $store->fetch_action( $action_id );
			if ( ! $action ) {
				return [ 'ok' => false, 'message' => __( 'Action not found.', 'brikpanel' ) ];
			}
			// Confirm it belongs to our group — never run something we don't own.
			$group = method_exists( $action, 'get_group' ) ? $action->get_group() : '';
			if ( $group !== self::GROUP ) {
				return [ 'ok' => false, 'message' => __( 'Action does not belong to BrikPanel.', 'brikpanel' ) ];
			}
			ActionScheduler::runner()->process_action( $action_id, 'BrikPanel' );
			// Action Scheduler swallows handler exceptions internally and
			// marks the row as `failed`. process_action() therefore returns
			// normally even on failure — we have to re-read the status to
			// surface the outcome to the UI/tests.
			$post_status = $store->get_status( $action_id );
			if ( $post_status === 'failed' ) {
				$last_msg = self::last_log_message( $action_id );
				return [
					'ok'      => false,
					'message' => $last_msg !== '' ? $last_msg : __( 'Action failed.', 'brikpanel' ),
				];
			}
			return [ 'ok' => true, 'message' => __( 'Action executed.', 'brikpanel' ) ];
		} catch ( \Throwable $e ) {
			return [ 'ok' => false, 'message' => $e->getMessage() ];
		}
	}

	/**
	 * Best-effort retrieval of the most recent log entry message for an
	 * action — used to surface the failure reason in `run_now()`.
	 *
	 * @param int $action_id
	 * @return string
	 */
	private static function last_log_message( $action_id ) {
		$logs = self::get_logs( $action_id );
		if ( empty( $logs ) ) {
			return '';
		}
		// AS appends entries chronologically; the failure note is at the end.
		$last = end( $logs );
		return is_array( $last ) && isset( $last['message'] ) ? (string) $last['message'] : '';
	}

	/**
	 * Re-enqueue a failed/cancelled action with the same hook + args, fresh
	 * status. Used by the "Retry" button.
	 *
	 * @param int $action_id Original (failed/cancelled) action.
	 * @return array{ok: bool, message: string, new_id?: int}
	 */
	public static function retry( $action_id ) {
		if ( ! self::is_available() || ! class_exists( 'ActionScheduler' ) ) {
			return [ 'ok' => false, 'message' => __( 'Action Scheduler is not loaded.', 'brikpanel' ) ];
		}
		try {
			$store  = ActionScheduler::store();
			$action = $store->fetch_action( (int) $action_id );
			if ( ! $action ) {
				return [ 'ok' => false, 'message' => __( 'Action not found.', 'brikpanel' ) ];
			}
			$group = method_exists( $action, 'get_group' ) ? $action->get_group() : '';
			if ( $group !== self::GROUP ) {
				return [ 'ok' => false, 'message' => __( 'Action does not belong to BrikPanel.', 'brikpanel' ) ];
			}
			$hook  = $action->get_hook();
			$args  = (array) $action->get_args();
			// args is already wrapped (see enqueue_async); pass through.
			$new_id = (int) as_enqueue_async_action( $hook, $args, self::GROUP, false, 10 );
			return [ 'ok' => true, 'message' => __( 'Re-queued.', 'brikpanel' ), 'new_id' => $new_id ];
		} catch ( \Throwable $e ) {
			return [ 'ok' => false, 'message' => $e->getMessage() ];
		}
	}

	/**
	 * Cancel a pending action by ID.
	 *
	 * @param int $action_id
	 * @return array{ok: bool, message: string}
	 */
	public static function cancel_by_id( $action_id ) {
		if ( ! class_exists( 'ActionScheduler' ) ) {
			return [ 'ok' => false, 'message' => __( 'Action Scheduler is not loaded.', 'brikpanel' ) ];
		}
		try {
			$store  = ActionScheduler::store();
			$action = $store->fetch_action( (int) $action_id );
			if ( ! $action ) {
				return [ 'ok' => false, 'message' => __( 'Action not found.', 'brikpanel' ) ];
			}
			$group = method_exists( $action, 'get_group' ) ? $action->get_group() : '';
			if ( $group !== self::GROUP ) {
				return [ 'ok' => false, 'message' => __( 'Action does not belong to BrikPanel.', 'brikpanel' ) ];
			}
			$store->cancel_action( (int) $action_id );
			return [ 'ok' => true, 'message' => __( 'Cancelled.', 'brikpanel' ) ];
		} catch ( \Throwable $e ) {
			return [ 'ok' => false, 'message' => $e->getMessage() ];
		}
	}

	// =========================================================================
	// Logs
	// =========================================================================

	/**
	 * Fetch the AS log entries for an action.
	 *
	 * @param int $action_id
	 * @return array<int, array{date: string, message: string}>
	 */
	public static function get_logs( $action_id ) {
		if ( ! class_exists( 'ActionScheduler' ) ) {
			return [];
		}
		try {
			$logger = ActionScheduler::logger();
		} catch ( \Throwable $e ) {
			return [];
		}
		$entries = $logger->get_logs( (int) $action_id );
		$out     = [];
		foreach ( (array) $entries as $entry ) {
			if ( ! is_object( $entry ) || ! method_exists( $entry, 'get_date' ) ) {
				continue;
			}
			$date = $entry->get_date();
			$out[] = [
				'date'    => $date ? $date->format( 'Y-m-d H:i:s' ) : '',
				'message' => method_exists( $entry, 'get_message' ) ? (string) $entry->get_message() : '',
			];
		}
		return $out;
	}

	// =========================================================================
	// Helpers
	// =========================================================================

	/**
	 * Convert a hook slug (`brikpanel_oauth_token_refresh`) into a label
	 * (`OAuth Token Refresh`) for the admin UI when no explicit label is
	 * provided in register_handler().
	 *
	 * @param string $hook
	 * @return string
	 */
	private static function humanise_hook( $hook ) {
		$hook = preg_replace( '/^brikpanel[_\-]/', '', (string) $hook );
		$hook = str_replace( [ '_', '-' ], ' ', (string) $hook );
		$hook = ucwords( strtolower( $hook ) );
		return $hook;
	}

	/**
	 * Map an AS status string to a {label, tone} pair for the UI badge.
	 *
	 * @param string $status
	 * @return array{label: string, tone: 'pending'|'running'|'success'|'error'|'neutral'}
	 */
	public static function describe_status( $status ) {
		switch ( $status ) {
			case 'pending':
			case ActionScheduler_Store::STATUS_PENDING:
				return [ 'label' => __( 'Pending', 'brikpanel' ), 'tone' => 'pending' ];
			case 'in-progress':
			case ActionScheduler_Store::STATUS_RUNNING:
				return [ 'label' => __( 'Running', 'brikpanel' ), 'tone' => 'running' ];
			case 'complete':
			case ActionScheduler_Store::STATUS_COMPLETE:
				return [ 'label' => __( 'Done', 'brikpanel' ), 'tone' => 'success' ];
			case 'failed':
			case ActionScheduler_Store::STATUS_FAILED:
				return [ 'label' => __( 'Failed', 'brikpanel' ), 'tone' => 'error' ];
			case 'canceled':
			case 'cancelled':
			case ActionScheduler_Store::STATUS_CANCELED:
				return [ 'label' => __( 'Cancelled', 'brikpanel' ), 'tone' => 'neutral' ];
			default:
				return [ 'label' => ucfirst( (string) $status ), 'tone' => 'neutral' ];
		}
	}
}
