<?php
/**
 * BrikPanel — BrikControl Runner
 *
 * Action Scheduler-driven orchestration for the BrikControl health scan.
 *
 * Two hooks:
 *   - brikpanel_brikcontrol_scan        : kick off a full sweep (recurring + manual).
 *   - brikpanel_brikcontrol_scan_batch  : process one batch of one batched check.
 *
 * Inline (non-batched) checks finish synchronously inside `handle_scan`. Batched
 * checks are kicked off as separate async actions so a single AS worker tick
 * never busts memory or wallclock limits, regardless of store size.
 *
 * Concurrency: the progress option acts as a soft lock, and each sweep cancels
 * leftover batch actions before queueing its own. Batch actions are never
 * queued as unique; see `enqueue_next_batch` for why that would break the chain.
 *
 * @package BrikPanel
 * @since   3.1.1
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Brikpanel_BrikControl_Runner {

    const HOOK_SCAN       = 'brikpanel_brikcontrol_scan';
    const HOOK_SCAN_BATCH = 'brikpanel_brikcontrol_scan_batch';

    /**
     * Payload that marks a scan as merchant-triggered rather than the nightly
     * refresh. One definition: the dedupe check in the AJAX handler and the one
     * inside trigger_manual_scan() have to hash to the same Action Scheduler
     * args or neither of them dedupes anything.
     */
    const SCAN_ARGS_MANUAL = [ 'manual' => 1 ];

    /**
     * How long progress may sit unchanged before `maybe_resume()` bothers to
     * ask Action Scheduler whether the chain is still alive. Generous enough
     * that a slow batch is never mistaken for a dead one.
     */
    const STALL_GRACE = 3 * MINUTE_IN_SECONDS;

    /**
     * Register AS handlers + the daily recurring schedule. Called from the
     * bootstrap on init priority 20 (after Brikpanel_Cron is loaded).
     */
    public static function register() {
        if ( ! class_exists( 'Brikpanel_Cron' ) ) {
            return;
        }

        Brikpanel_Cron::register_handler(
            self::HOOK_SCAN,
            [ __CLASS__, 'handle_scan' ],
            static function () {
                return [
                    'label'       => __( 'BrikControl: Full Health Scan', 'brikpanel' ),
                    'description' => __( 'Runs every BrikControl check and writes results to the dashboard.', 'brikpanel' ),
                ];
            }
        );

        Brikpanel_Cron::register_handler(
            self::HOOK_SCAN_BATCH,
            [ __CLASS__, 'handle_batch' ],
            static function () {
                return [
                    'label'       => __( 'BrikControl: Scan Batch', 'brikpanel' ),
                    'description' => __( 'Processes a single batch of a long-running BrikControl check.', 'brikpanel' ),
                ];
            }
        );

        // Schedule the daily refresh. First run six hours from install to
        // avoid a thundering scan as soon as the plugin activates.
        Brikpanel_Cron::schedule_recurring(
            self::HOOK_SCAN,
            DAY_IN_SECONDS,
            [],
            6 * HOUR_IN_SECONDS
        );
    }

    /**
     * AS handler for the kickoff hook.
     *
     * @param array $payload { manual?: int }
     * @return void
     */
    public static function handle_scan( $payload = [] ) {
        $payload = is_array( $payload ) ? $payload : [];

        $checks = Brikpanel_BrikControl_Registry::get_all();
        if ( empty( $checks ) ) {
            return;
        }

        $has_batched = false;

        foreach ( $checks as $check_id => $check ) {
            if ( ! $check->supports_batching() ) {
                $result = $check->run( [] );
                Brikpanel_BrikControl_Storage::save_check_result( $check_id, $result );
                continue;
            }
            $has_batched = true;
        }

        if ( $has_batched ) {
            // Drop any leftover batch actions from a previous (possibly
            // interrupted) sweep before starting fresh chains. Uniqueness
            // cannot do this for us; see the note in `enqueue_next_batch`.
            Brikpanel_Cron::cancel( self::HOOK_SCAN_BATCH );
        }

        foreach ( $checks as $check_id => $check ) {
            if ( ! $check->supports_batching() ) {
                continue;
            }

            // Reset progress + enqueue first batch.
            Brikpanel_BrikControl_Storage::set_progress( $check_id, 0, 0 );
            self::enqueue_next_batch( $check_id, 0 );
        }

        do_action( 'brikpanel_brikcontrol_scan_started', $payload );
    }

    /**
     * AS handler for one batch.
     *
     * @param array $payload { check_id: string, cursor: int }
     * @return void
     */
    public static function handle_batch( $payload = [] ) {
        $payload  = is_array( $payload ) ? $payload : [];
        $check_id = isset( $payload['check_id'] ) ? (string) $payload['check_id'] : '';
        $cursor   = isset( $payload['cursor'] ) ? max( 0, (int) $payload['cursor'] ) : 0;

        $check = Brikpanel_BrikControl_Registry::get( $check_id );
        if ( ! $check ) {
            // Unknown check id — clear any stuck progress lock and bail.
            Brikpanel_BrikControl_Storage::clear_progress();
            return;
        }

        $result = $check->run( [ 'cursor' => $cursor ] );
        $batch  = isset( $result['batch_state'] ) && is_array( $result['batch_state'] ) ? $result['batch_state'] : null;

        if ( $batch && empty( $batch['done'] ) ) {
            // More work to do — update progress + queue next slice.
            Brikpanel_BrikControl_Storage::set_progress(
                $check_id,
                isset( $batch['cursor'] ) ? (int) $batch['cursor'] : 0,
                isset( $batch['total'] ) ? (int) $batch['total'] : 0
            );
            // Persist the partial summary so the page UI can show "scanning…".
            Brikpanel_BrikControl_Storage::save_check_result( $check_id, $result );

            self::enqueue_next_batch(
                $check_id,
                isset( $batch['cursor'] ) ? (int) $batch['cursor'] : ( $cursor + $check->get_batch_size() )
            );
            return;
        }

        // Final result — persist + clear progress.
        Brikpanel_BrikControl_Storage::save_check_result( $check_id, $result );
        Brikpanel_BrikControl_Storage::clear_progress();

        do_action( 'brikpanel_brikcontrol_scan_complete', $check_id, $result );
    }

    /**
     * Watchdog: revive a scan whose batch chain died between slices.
     *
     * The progress option is a soft lock, and while it is held the page shows
     * a progress bar and disables "Scan now". If the worker that owned the
     * chain never queued its successor (a fatal inside a batch, a killed PHP
     * process, a queue runner that was switched off), nothing would ever clear
     * that lock and the store owner had no way back other than waiting for it
     * to age out.
     *
     * Staleness alone does not prove death: a big batch may legitimately run
     * for minutes. So staleness is only used as a cheap throttle, and the
     * verdict comes from Action Scheduler: if no scan action is pending or
     * in-progress, nobody is going to move this chain forward. In that case we
     * resume from the stored cursor rather than restarting, so the products
     * already scanned in this sweep are not scanned twice.
     *
     * Safe to call on every page load / progress poll.
     *
     * @return bool True when the watchdog intervened.
     */
    public static function maybe_resume() {
        if ( ! class_exists( 'Brikpanel_Cron' ) ) {
            return false;
        }

        $progress = Brikpanel_BrikControl_Storage::get_progress();
        if ( (int) $progress['started_at'] <= 0 ) {
            return false; // No scan in flight.
        }

        $idle_for = time() - (int) $progress['started_at'];
        if ( $idle_for < self::STALL_GRACE ) {
            return false; // Still moving, or too soon to tell.
        }

        if ( self::has_live_action() ) {
            return false; // A batch is queued or running; leave it alone.
        }

        $check_id = (string) $progress['check_id'];
        $check    = $check_id !== '' ? Brikpanel_BrikControl_Registry::get( $check_id ) : null;
        if ( ! $check ) {
            // Lock left behind by a check that no longer exists.
            Brikpanel_BrikControl_Storage::clear_progress();
            return true;
        }

        // Re-arm the lock so a second admin loading the page at the same moment
        // does not queue the same slice again, then resume where we stopped.
        Brikpanel_BrikControl_Storage::set_progress( $check_id, (int) $progress['cursor'], (int) $progress['total'] );
        self::enqueue_next_batch( $check_id, (int) $progress['cursor'] );

        return true;
    }

    /**
     * Whether a scan or scan-batch action is pending or already running.
     *
     * @return bool
     */
    private static function has_live_action() {
        if ( ! class_exists( 'ActionScheduler_Store' ) ) {
            return true; // Cannot tell: assume healthy rather than pile on.
        }

        $statuses = [ ActionScheduler_Store::STATUS_PENDING, ActionScheduler_Store::STATUS_RUNNING ];

        foreach ( [ self::HOOK_SCAN_BATCH, self::HOOK_SCAN ] as $hook ) {
            foreach ( $statuses as $status ) {
                $found = Brikpanel_Cron::query( [
                    'hook'     => $hook,
                    'status'   => $status,
                    'per_page' => 1,
                ] );
                if ( ! empty( $found ) ) {
                    // The daily recurring scan always sits in the queue as a
                    // pending action, so a far-future one does not count as a
                    // sign of life for the chain that is stuck right now.
                    if ( $hook === self::HOOK_SCAN && $status === ActionScheduler_Store::STATUS_PENDING
                        && ! self::is_due_soon( $found ) ) {
                        continue;
                    }
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Whether the first action in an AS result set is scheduled to run within
     * the stall grace window (i.e. it is a real successor, not tomorrow's
     * recurring sweep).
     *
     * @param array $actions Result of Brikpanel_Cron::query().
     * @return bool
     */
    private static function is_due_soon( array $actions ) {
        $action = reset( $actions );
        if ( ! $action || ! method_exists( $action, 'get_schedule' ) ) {
            return true;
        }
        $schedule = $action->get_schedule();
        if ( ! $schedule || ! method_exists( $schedule, 'get_date' ) ) {
            return true;
        }
        $date = $schedule->get_date();
        if ( ! $date ) {
            return true;
        }
        return ( $date->getTimestamp() - time() ) <= self::STALL_GRACE;
    }

    /**
     * Queue one batch slice of a batched check.
     *
     * Deliberately NOT unique. Action Scheduler's `unique` flag matches on
     * hook + group only: it ignores args and counts the currently running
     * action, so a batch that queues its own successor under `unique => true`
     * is silently refused and the chain dies at the first batch boundary.
     * Pile-up is prevented by design instead: each run produces at most one
     * successor, the cursor strictly advances, and `handle_scan` cancels
     * leftover batches before starting a new sweep.
     *
     * Note that "AS uniqueness is unusable here" does NOT imply "no dedupe".
     * See trigger_manual_scan(), where the same constraint holds but the
     * correct answer is an explicit args-aware as_has_scheduled_action()
     * check — dropping the check there is what let 21 kickoff actions queue
     * from a single bulk plugin operation.
     *
     * @param string $check_id
     * @param int    $cursor
     * @return int|false Action id, or false when nothing could be queued.
     */
    private static function enqueue_next_batch( $check_id, $cursor ) {
        $args = [ 'check_id' => (string) $check_id, 'cursor' => max( 0, (int) $cursor ) ];

        $action_id = Brikpanel_Cron::enqueue_async( self::HOOK_SCAN_BATCH, $args );
        if ( $action_id ) {
            return $action_id;
        }

        // Async enqueue refused (queue backpressure, store error). Fall back to
        // a delayed single so the chain resumes instead of stalling forever.
        $action_id = Brikpanel_Cron::schedule_single( time() + MINUTE_IN_SECONDS, self::HOOK_SCAN_BATCH, $args );
        if ( ! $action_id ) {
            // Nothing could be queued at all, so release the lock so the UI stops
            // reporting an in-flight scan and the next sweep can take over.
            Brikpanel_BrikControl_Storage::clear_progress();
            return false;
        }

        return $action_id;
    }

    /**
     * Trigger an out-of-band manual scan (called from the AJAX handler and
     * from the plugin activate/deactivate hook).
     *
     * `unique => true` is unusable here and always will be: Action Scheduler
     * matches uniqueness on hook + group only, and the daily recurring action
     * sits permanently in the pending queue with the `[]` payload, so a unique
     * enqueue of our `manual=1` payload would be refused forever.
     *
     * Dedupe is therefore done explicitly. Brikpanel_Cron::is_scheduled()
     * calls as_has_scheduled_action( $hook, [ $args ], GROUP ), which DOES
     * hash the args — so it tells our [{"manual":1}] apart from the recurring
     * [[]] — and it matches PENDING *and* RUNNING, so a scan a worker has
     * already picked up counts as covered too.
     *
     * Without this check the method piled up: WordPress fires
     * activated_plugin / deactivated_plugin once per plugin, so a single bulk
     * toggle of 20 plugins enqueued 20 identical scans in one request.
     *
     * @param int $delay Seconds to defer the run. 0 enqueues async (runs on
     *                   the next AS tick). Use a delay when the state being
     *                   scanned is still settling, e.g. a bulk plugin change.
     * @return int|false Action id, 0 when a scan is already covered, false
     *                   when Action Scheduler is unavailable.
     */
    public static function trigger_manual_scan( $delay = 0 ) {
        if ( ! class_exists( 'Brikpanel_Cron' ) || ! Brikpanel_Cron::is_available() ) {
            return false;
        }

        $args = self::SCAN_ARGS_MANUAL;

        if ( Brikpanel_Cron::is_scheduled( self::HOOK_SCAN, $args ) ) {
            return 0;
        }

        $delay = max( 0, (int) $delay );
        if ( $delay > 0 ) {
            return Brikpanel_Cron::schedule_single( time() + $delay, self::HOOK_SCAN, $args );
        }

        return Brikpanel_Cron::enqueue_async( self::HOOK_SCAN, $args, [ 'unique' => false ] );
    }

    /**
     * Push an already-queued manual scan further out.
     *
     * The plugin-change cooldown is a floor on how often we ENQUEUE, not a
     * reason to let a later plugin change go unmeasured. Installing three
     * plugins over ten minutes used to produce exactly one scan — the one
     * queued by the first install, which ran 90 seconds later and therefore
     * reported on a store that did not have the other two yet. Store Health
     * then stayed wrong until the nightly refresh, up to 24 hours.
     *
     * Rescheduling instead of enqueuing keeps the queue at one row (the whole
     * point of the cooldown) while making sure the row that does run sees the
     * finished plugin set. The replacement is scheduled BEFORE the old row is
     * cancelled: if the store refuses the insert we still have the original.
     *
     * @param int $delay Seconds from now the scan should run.
     * @return int|false New action id, 0 when there was nothing to move (or it
     *                   was already late enough), false when unavailable.
     */
    public static function defer_pending_manual_scan( $delay = 0 ) {
        if ( ! class_exists( 'Brikpanel_Cron' ) || ! Brikpanel_Cron::is_available() ) {
            return false;
        }
        if ( ! class_exists( 'ActionScheduler_Store' ) || ! class_exists( 'ActionScheduler' ) ) {
            return false;
        }

        $target  = time() + max( 0, (int) $delay );
        $manual  = [ self::SCAN_ARGS_MANUAL ];
        $pending = Brikpanel_Cron::query( [
            'hook'     => self::HOOK_SCAN,
            'status'   => ActionScheduler_Store::STATUS_PENDING,
            'per_page' => 50,
            'orderby'  => 'date',
            'order'    => 'ASC',
        ] );

        foreach ( $pending as $action_id => $action ) {
            $args = ( is_object( $action ) && method_exists( $action, 'get_args' ) )
                ? (array) $action->get_args()
                : Brikpanel_Cron::get_action_args( (int) $action_id );
            if ( $args !== $manual ) {
                continue;
            }

            // Already scheduled at or after the target: nothing to gain.
            if ( is_object( $action ) && method_exists( $action, 'get_schedule' ) ) {
                $schedule = $action->get_schedule();
                if ( is_object( $schedule ) && method_exists( $schedule, 'get_date' ) ) {
                    $date = $schedule->get_date();
                    if ( $date instanceof DateTime && $date->getTimestamp() >= $target ) {
                        return 0;
                    }
                }
            }

            $new_id = Brikpanel_Cron::schedule_single( $target, self::HOOK_SCAN, self::SCAN_ARGS_MANUAL );
            if ( ! $new_id ) {
                return false; // Keep the original row rather than end up with none.
            }
            try {
                ActionScheduler::store()->cancel_action( (int) $action_id );
            } catch ( \Throwable $e ) {
                // Worst case the duplicate stays pending; the AJAX dedupe and
                // the pile-up cleanup both cope with that.
            }
            return $new_id;
        }

        return 0;
    }

    /**
     * Cancel duplicate pending kickoff actions left behind by the pile-up bug
     * that shipped before 3.2.70.
     *
     * Two invariants:
     *  - The daily recurring action is never touched. Cancelling it would
     *    silently stop the nightly health refresh.
     *  - At most one `manual` action survives, so a scan the merchant genuinely
     *    asked for still runs.
     *
     * The test is POSITIVE — cancel only rows that are demonstrably manual —
     * and that is the whole point. An earlier revision asked the opposite
     * question, `$args !== [[]] ⇒ manual`, which quietly made "anything I could
     * not read" a cancellation candidate. Every failure path in
     * Brikpanel_Cron::get_action_args() returns `[]` (store missing,
     * fetch_action() throwing, the row claimed and purged between the query and
     * the fetch, a NullAction coming back), and `[] !== [[]]`, so a single
     * unlucky read of the recurring row cancelled the nightly scan for good.
     * A recurring row scheduled by an older build without the `[ $args ]`
     * wrapper reads back as `[]` too, and hits the same trapdoor.
     *
     * The args also come from the action object the query already returned
     * instead of a second fetch_action() per row — half the store reads, and
     * the re-fetch was what introduced the `[]`-on-failure path to begin with.
     *
     * Cancels by action id rather than as_unschedule_action( hook, args ):
     * that helper cancels the earliest match, which is ambiguous when every
     * row carries identical args.
     *
     * @return int Number of actions cancelled.
     */
    public static function cleanup_duplicate_scans() {
        if ( ! class_exists( 'Brikpanel_Cron' ) || ! Brikpanel_Cron::is_available() ) {
            return 0;
        }
        if ( ! class_exists( 'ActionScheduler_Store' ) || ! class_exists( 'ActionScheduler' ) ) {
            return 0;
        }

        $manual    = [ self::SCAN_ARGS_MANUAL ];
        $cancelled = 0;

        // Collect first, cancel afterwards. A store that piled up for weeks
        // holds more than one page, and cancelling mid-walk would move rows out
        // of the PENDING result set while we page through it, so every cancel
        // would shift the next offset and skip a row. The one-shot marker is
        // written straight after this returns, so a skipped row is never
        // revisited.
        $doomed = [];
        $offset = 0;
        do {
            $pending = Brikpanel_Cron::query( [
                'hook'     => self::HOOK_SCAN,
                'status'   => ActionScheduler_Store::STATUS_PENDING,
                'per_page' => 200,
                'offset'   => $offset,
                'orderby'  => 'date',
                'order'    => 'ASC',
            ] );

            foreach ( $pending as $action_id => $action ) {
                $args = ( is_object( $action ) && method_exists( $action, 'get_args' ) )
                    ? (array) $action->get_args()
                    : Brikpanel_Cron::get_action_args( (int) $action_id );

                if ( $args !== $manual ) {
                    continue; // Recurring row, unknown shape, unreadable — leave it alone.
                }
                $doomed[] = (int) $action_id;
            }

            $offset += 200;
        } while ( count( $pending ) === 200 );

        // Keep the earliest manual row so a scan the merchant asked for still
        // runs; the explicit orderby above is what makes "earliest" mean
        // anything (as_get_scheduled_actions has no documented default order).
        array_shift( $doomed );

        foreach ( $doomed as $action_id ) {
            try {
                ActionScheduler::store()->cancel_action( $action_id );
                $cancelled++;
            } catch ( \Throwable $e ) {
                // Row vanished between the query and the cancel; nothing to do.
            }
        }

        return $cancelled;
    }
}
