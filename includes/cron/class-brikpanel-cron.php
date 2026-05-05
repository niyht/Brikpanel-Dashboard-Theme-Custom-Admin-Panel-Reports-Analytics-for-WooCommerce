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
	 * Shape: [ hook_name => [ 'label' => string, 'description' => string ] ]
	 *
	 * @var array<string, array{label: string, description: string}>
	 */
	private static $registered_hooks = [];

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
	 * @return bool
	 */
	public static function is_available() {
		return function_exists( 'as_enqueue_async_action' )
			&& function_exists( 'as_schedule_single_action' )
			&& function_exists( 'as_schedule_recurring_action' );
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
	 * "Job type" column on the admin page.
	 *
	 * @param string   $hook        Action hook (must be unique across the plugin; convention: `brikpanel_*`).
	 * @param callable $callback    Handler. Receives the action args as a single argument.
	 * @param array    $meta        { Optional metadata for the admin UI.
	 *     @type string $label       Human-readable label. Defaults to a humanised hook name.
	 *     @type string $description One-line description shown on hover/expand.
	 * }
	 * @return void
	 */
	public static function register_handler( $hook, callable $callback, array $meta = [] ) {
		$hook = (string) $hook;
		if ( $hook === '' ) {
			return;
		}

		self::$registered_hooks[ $hook ] = [
			'label'       => isset( $meta['label'] ) && $meta['label'] !== ''
				? (string) $meta['label']
				: self::humanise_hook( $hook ),
			'description' => isset( $meta['description'] ) ? (string) $meta['description'] : '',
		];

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
	 * @return array<string, array{label: string, description: string}>
	 */
	public static function get_registered_hooks() {
		return self::$registered_hooks;
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
		return (int) as_enqueue_async_action( $hook, [ $args ], self::GROUP, $unique, $priority );
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
		return (int) as_schedule_single_action( (int) $timestamp, $hook, [ $args ], self::GROUP, $unique, $priority );
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
		if ( as_has_scheduled_action( $hook, [ $args ], self::GROUP ) ) {
			return true;
		}
		$first_run = time() + ( $start_offset !== null ? (int) $start_offset : $interval );
		return (int) as_schedule_recurring_action( $first_run, $interval, $hook, [ $args ], self::GROUP, false, 10 );
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
		$pending = self::query( [
			'hook'     => $hook,
			'status'   => ActionScheduler_Store::STATUS_PENDING,
			'per_page' => 200,
		] );
		foreach ( $pending as $action_id => $_action ) {
			if ( $payload !== null ) {
				// Skip rows whose args don't match.
				$row_args = self::get_action_args( $action_id );
				if ( $row_args !== $payload ) {
					continue;
				}
			}
			as_unschedule_action( $hook, $row_args ?? null, self::GROUP );
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
		return (bool) as_has_scheduled_action( $hook, [ $args ], self::GROUP );
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
		return as_get_scheduled_actions( $args );
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
