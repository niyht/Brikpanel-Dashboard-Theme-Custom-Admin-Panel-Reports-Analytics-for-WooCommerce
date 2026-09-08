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
 *   - dismissed     (user meta):           per-user dismissal map, each entry
 *                                          { at: timestamp, ids: [check ids
 *                                          that were failing when dismissed] }.
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
    // DISMISSALS (per-user, permanent, scoped to what was failing)
    // =========================================================================

    /**
     * Sorted ids of every check currently reporting "critical". This is the
     * scope a dashboard-banner dismissal is recorded against: the merchant is
     * saying "I know about these", not "hide this banner forever".
     *
     * @param array|null $bundle Result bundle, to save callers that already
     *                           hold one a second option read.
     * @return string[]
     */
    public static function critical_check_ids( array $bundle = null ) {
        if ( $bundle === null ) {
            $bundle = self::get_results();
        }
        $ids = [];
        foreach ( (array) ( isset( $bundle['checks'] ) ? $bundle['checks'] : [] ) as $check_id => $result ) {
            $status = isset( $result['status'] ) ? (string) $result['status'] : '';
            if ( $status === 'critical' ) {
                $ids[] = (string) $check_id;
            }
        }
        sort( $ids );
        return $ids;
    }

    /**
     * User meta key for the CURRENT site.
     *
     * User meta is global on multisite, so a single shared key meant that
     * dismissing the banner on one store silenced it on every other store in
     * the network — even though each store has its own products and therefore
     * its own critical findings. Prefixing per blog (the same convention core
     * uses for capabilities) keeps each store's dismissals to itself.
     *
     * Single-site installs keep the original unprefixed key, so nothing there
     * has to migrate.
     *
     * @return string
     */
    private static function meta_key() {
        if ( ! is_multisite() ) {
            return self::USER_META_KEY;
        }
        global $wpdb;
        return $wpdb->get_blog_prefix() . self::USER_META_KEY;
    }

    /**
     * @param int|null $user_id Defaults to current user.
     * @return array<string, array{at:int, ids:string[]}|int> Legacy rows are
     *         still plain ints; callers must tolerate both shapes.
     */
    public static function get_dismissals( $user_id = null ) {
        $user_id = $user_id ?: get_current_user_id();
        if ( $user_id <= 0 ) {
            return [];
        }

        $stored = get_user_meta( $user_id, self::meta_key(), true );
        if ( is_array( $stored ) ) {
            return $stored;
        }

        // Network installs that dismissed under the old shared key: hand that
        // value to the main site only. Adopting it everywhere would just
        // recreate the cross-store bleed this prefix exists to stop.
        if ( is_multisite() && is_main_site() ) {
            $legacy = get_user_meta( $user_id, self::USER_META_KEY, true );
            if ( is_array( $legacy ) ) {
                return $legacy;
            }
        }

        return [];
    }

    /**
     * Record a dismissal permanently.
     *
     * The scope ids are UNIONed with whatever was already stored rather than
     * replacing it. Union is what makes the banner stay quiet in the two cases
     * merchants actually hit: an issue that gets fixed and later regresses is
     * not a new problem, and a background scan that lands between the render
     * and the click must not silently widen the set the user thought they were
     * dismissing.
     *
     * @param string   $check_id  Dismissal key (e.g. 'dashboard_banner').
     * @param string[] $scope_ids Check ids that were failing at dismiss time.
     * @param int|null $user_id
     * @return void
     */
    public static function dismiss( $check_id, array $scope_ids = [], $user_id = null ) {
        $user_id = $user_id ?: get_current_user_id();
        if ( $user_id <= 0 ) {
            return;
        }

        $check_id = (string) $check_id;
        $stored   = self::get_dismissals( $user_id );
        $existing = isset( $stored[ $check_id ] ) ? $stored[ $check_id ] : null;

        $ids = [];
        if ( is_array( $existing ) && isset( $existing['ids'] ) && is_array( $existing['ids'] ) ) {
            $ids = array_map( 'strval', $existing['ids'] );
        }
        $ids = array_values( array_unique( array_merge( $ids, array_map( 'strval', $scope_ids ) ) ) );
        sort( $ids );

        $stored[ $check_id ] = [
            'at'  => time(),
            'ids' => $ids,
        ];
        update_user_meta( $user_id, self::meta_key(), $stored );
    }

    /**
     * Whether a dismissal still suppresses the notice.
     *
     * Dismissals no longer expire. Suppression lifts only when something the
     * user has never dismissed shows up: if every id in $scope_ids is already
     * covered by the stored set, stay hidden.
     *
     * @param string        $check_id
     * @param string[]|null $scope_ids Current failing ids. Null means the
     *                                 caller has no scope, so any stored
     *                                 dismissal suppresses unconditionally.
     * @param int|null      $user_id
     * @return bool
     */
    public static function is_dismissed( $check_id, array $scope_ids = null, $user_id = null ) {
        $stored = self::get_dismissals( $user_id );
        if ( ! isset( $stored[ $check_id ] ) ) {
            return false;
        }
        if ( $scope_ids === null ) {
            return true;
        }

        $entry = $stored[ $check_id ];

        // Legacy rows are a bare timestamp with no record of what was wrong at
        // the time. The user did click the X, so honour that — adopt today's
        // scope so a genuinely new problem can still surface later.
        if ( ! is_array( $entry ) ) {
            self::dismiss( $check_id, $scope_ids, $user_id );
            return true;
        }

        $known = ( isset( $entry['ids'] ) && is_array( $entry['ids'] ) ) ? array_map( 'strval', $entry['ids'] ) : [];
        return array_diff( array_map( 'strval', $scope_ids ), $known ) === [];
    }
}
