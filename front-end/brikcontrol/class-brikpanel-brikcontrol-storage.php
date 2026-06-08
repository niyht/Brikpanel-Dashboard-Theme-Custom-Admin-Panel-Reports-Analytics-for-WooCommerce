<?php
/**
 * BrikPanel — BrikControl Storage
 *
 * Thin persistence wrapper around options / transients / user meta for the
 * BrikControl health panel. Centralises the storage keys so callers never
 * touch raw option names — that lets us version the schema and migrate in
 * one place.
 *
 * Stored shapes:
 *   - results       (option, autoload=no): full per-check report bundle.
 *   - topbar cache  (transient, 5 min):    lean payload the topbar JS reads.
 *   - progress      (option, autoload=no): in-flight scan cursor / total.
 *   - dismissed     (user meta):           per-user banner dismissal map.
 *
 * @package BrikPanel
 * @since   3.1.1
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Brikpanel_BrikControl_Storage {

    const OPT_RESULTS      = 'brikpanel_brikcontrol_results';
    const OPT_PROGRESS     = 'brikpanel_brikcontrol_progress';
    const TRANSIENT_TOPBAR = 'brikpanel_brikcontrol_topbar_v1';
    const USER_META_KEY    = 'brikpanel_brikcontrol_dismissed';

    const TRANSIENT_TTL    = 300;        // 5 minutes
    const DISMISS_TTL      = 7 * DAY_IN_SECONDS;

    // =========================================================================
    // RESULTS
    // =========================================================================

    /**
     * Default empty bundle returned when no scan has run yet. Keeping the
     * shape stable means consumers (topbar, banner, page) can render a
     * "never scanned" state without null-checks scattered everywhere.
     *
     * @return array
     */
    public static function default_results() {
        return [
            'last_scan'      => 0,
            'status_summary' => [
                'ok'       => 0,
                'warning'  => 0,
                'critical' => 0,
                'unknown'  => 0,
            ],
            'checks'         => [],
        ];
    }

    /**
     * @return array
     */
    public static function get_results() {
        $stored = get_option( self::OPT_RESULTS, null );
        if ( ! is_array( $stored ) ) {
            return self::default_results();
        }
        return wp_parse_args( $stored, self::default_results() );
    }

    /**
     * Replace the entire result bundle and recompute the summary chip counts
     * so the topbar/banner never have to walk every check on render.
     *
     * @param array $checks Map of check_id => CheckResult.
     * @return void
     */
    public static function save_results( array $checks ) {
        $summary = [ 'ok' => 0, 'warning' => 0, 'critical' => 0, 'unknown' => 0 ];
        foreach ( $checks as $result ) {
            $status = isset( $result['status'] ) ? (string) $result['status'] : 'unknown';
            if ( ! isset( $summary[ $status ] ) ) {
                $status = 'unknown';
            }
            $summary[ $status ]++;
        }

        $bundle = [
            'last_scan'      => time(),
            'status_summary' => $summary,
            'checks'         => $checks,
        ];

        update_option( self::OPT_RESULTS, $bundle, false );
        delete_transient( self::TRANSIENT_TOPBAR );
    }

    /**
     * Update a single check's result without touching the rest. Used by the
     * batched runner so each completed batch publishes a partial-state result
     * without waiting for every check to finish.
     *
     * @param string $check_id
     * @param array  $result
     * @return void
     */
    public static function save_check_result( $check_id, array $result ) {
        $bundle               = self::get_results();
        $bundle['checks'][ $check_id ] = $result;

        $summary = [ 'ok' => 0, 'warning' => 0, 'critical' => 0, 'unknown' => 0 ];
        foreach ( $bundle['checks'] as $r ) {
            $s = isset( $r['status'] ) ? (string) $r['status'] : 'unknown';
            if ( ! isset( $summary[ $s ] ) ) {
                $s = 'unknown';
            }
            $summary[ $s ]++;
        }
        $bundle['status_summary'] = $summary;
        $bundle['last_scan']      = time();

        update_option( self::OPT_RESULTS, $bundle, false );
        delete_transient( self::TRANSIENT_TOPBAR );
    }

    // =========================================================================
    // TOPBAR CACHE
    // =========================================================================

    /**
     * Lean payload for the topbar dropdown. Cached for 5 min so a page load
     * doesn't pay for the option read + payload build on every request.
     *
     * @return array
     */
    public static function get_topbar_payload() {
        $cached = get_transient( self::TRANSIENT_TOPBAR );
        if ( is_array( $cached ) ) {
            return $cached;
        }

        $bundle  = self::get_results();
        $payload = [
            'last_scan' => (int) $bundle['last_scan'],
            'summary'   => $bundle['status_summary'],
            'checks'    => [],
        ];
        foreach ( $bundle['checks'] as $id => $result ) {
            $payload['checks'][] = [
                'id'      => (string) $id,
                'label'   => isset( $result['label'] ) ? (string) $result['label'] : (string) $id,
                'status'  => isset( $result['status'] ) ? (string) $result['status'] : 'unknown',
                'summary' => isset( $result['summary'] ) ? (string) $result['summary'] : '',
            ];
        }

        set_transient( self::TRANSIENT_TOPBAR, $payload, self::TRANSIENT_TTL );
        return $payload;
    }

    // =========================================================================
    // PROGRESS (in-flight scan)
    // =========================================================================

    /**
     * @return array { check_id, cursor, total, started_at }
     */
    public static function get_progress() {
        $stored = get_option( self::OPT_PROGRESS, null );
        if ( ! is_array( $stored ) ) {
            return [
                'check_id'   => '',
                'cursor'     => 0,
                'total'      => 0,
                'started_at' => 0,
            ];
        }
        return wp_parse_args( $stored, [
            'check_id'   => '',
            'cursor'     => 0,
            'total'      => 0,
            'started_at' => 0,
        ] );
    }

    public static function set_progress( $check_id, $cursor, $total ) {
        update_option( self::OPT_PROGRESS, [
            'check_id'   => (string) $check_id,
            'cursor'     => (int) $cursor,
            'total'      => (int) $total,
            'started_at' => time(),
        ], false );
    }

    public static function clear_progress() {
        delete_option( self::OPT_PROGRESS );
    }

    /**
     * A scan is considered active if progress was written within the last
     * hour. The hour cap protects against a worker dying mid-batch and
     * leaving the lock in place forever.
     *
     * @return bool
     */
    public static function is_scan_active() {
        $progress = self::get_progress();
        if ( $progress['started_at'] <= 0 ) {
            return false;
        }
        return ( time() - (int) $progress['started_at'] ) < HOUR_IN_SECONDS;
    }

    // =========================================================================
    // DISMISSALS (per-user, 7 day TTL)
    // =========================================================================

    /**
     * @param int|null $user_id Defaults to current user.
     * @return array<string, int> check_id => unix timestamp
     */
    public static function get_dismissals( $user_id = null ) {
        $user_id = $user_id ?: get_current_user_id();
        if ( $user_id <= 0 ) {
            return [];
        }
        $stored = get_user_meta( $user_id, self::USER_META_KEY, true );
        return is_array( $stored ) ? $stored : [];
    }

    public static function dismiss( $check_id, $user_id = null ) {
        $user_id = $user_id ?: get_current_user_id();
        if ( $user_id <= 0 ) {
            return;
        }
        $stored                       = self::get_dismissals( $user_id );
        $stored[ (string) $check_id ] = time();
        update_user_meta( $user_id, self::USER_META_KEY, $stored );
    }

    /**
     * Whether a dismissal is still inside the 7-day suppression window.
     *
     * @param string   $check_id
     * @param int|null $user_id
     * @return bool
     */
    public static function is_dismissed( $check_id, $user_id = null ) {
        $stored = self::get_dismissals( $user_id );
        if ( ! isset( $stored[ $check_id ] ) ) {
            return false;
        }
        return ( time() - (int) $stored[ $check_id ] ) < self::DISMISS_TTL;
    }
}
