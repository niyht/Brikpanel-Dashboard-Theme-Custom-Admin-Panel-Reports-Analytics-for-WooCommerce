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
 * Concurrency: progress option acts as a soft lock. `Brikpanel_Cron::enqueue_async`
 * is called with unique=true so duplicate scans coalesce automatically.
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
            [
                'label'       => __( 'BrikControl: Full Health Scan', 'brikpanel' ),
                'description' => __( 'Runs every BrikControl check and writes results to the dashboard.', 'brikpanel' ),
            ]
        );

        Brikpanel_Cron::register_handler(
            self::HOOK_SCAN_BATCH,
            [ __CLASS__, 'handle_batch' ],
            [
                'label'       => __( 'BrikControl: Scan Batch', 'brikpanel' ),
                'description' => __( 'Processes a single batch of a long-running BrikControl check.', 'brikpanel' ),
            ]
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

        foreach ( $checks as $check_id => $check ) {
            if ( ! $check->supports_batching() ) {
                $result = $check->run( [] );
                Brikpanel_BrikControl_Storage::save_check_result( $check_id, $result );
                continue;
            }

            // Reset progress + enqueue first batch.
            Brikpanel_BrikControl_Storage::set_progress( $check_id, 0, 0 );
            Brikpanel_Cron::enqueue_async(
                self::HOOK_SCAN_BATCH,
                [ 'check_id' => $check_id, 'cursor' => 0 ],
                [ 'unique' => true ]
            );
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

            Brikpanel_Cron::enqueue_async(
                self::HOOK_SCAN_BATCH,
                [
                    'check_id' => $check_id,
                    'cursor'   => isset( $batch['cursor'] ) ? (int) $batch['cursor'] : ( $cursor + $check->get_batch_size() ),
                ],
                [ 'unique' => true ]
            );
            return;
        }

        // Final result — persist + clear progress.
        Brikpanel_BrikControl_Storage::save_check_result( $check_id, $result );
        Brikpanel_BrikControl_Storage::clear_progress();

        do_action( 'brikpanel_brikcontrol_scan_complete', $check_id, $result );
    }

    /**
     * Trigger an out-of-band manual scan (called from the AJAX handler).
     * Returns the AS action id so the caller can surface "queued".
     *
     * Important: unique is intentionally false here. The recurring daily
     * action sits in the AS pending queue with the no-op `[]` payload, and
     * `as_enqueue_async_action` would refuse a unique enqueue while it sits
     * there even though our payload differs. Tagging the manual run with
     * `manual=1` keeps it distinct in the AS log without colliding with the
     * recurring schedule.
     *
     * @return int|false
     */
    public static function trigger_manual_scan() {
        if ( ! class_exists( 'Brikpanel_Cron' ) ) {
            return false;
        }
        return Brikpanel_Cron::enqueue_async(
            self::HOOK_SCAN,
            [ 'manual' => 1 ],
            [ 'unique' => false ]
        );
    }
}
