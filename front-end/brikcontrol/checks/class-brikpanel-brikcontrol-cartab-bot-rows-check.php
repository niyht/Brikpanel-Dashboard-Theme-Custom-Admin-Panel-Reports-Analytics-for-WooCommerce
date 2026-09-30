<?php
/**
 * BrikPanel - BrikControl check: abandoned-cart entries that look automated.
 *
 * The abandoned-cart capture endpoint has to accept unauthenticated requests
 * (it runs on fully cached pages), so a script can post addresses at it the
 * same way a shopper's browser does. A store measured for the 3.3.1 counter
 * fix carried a script that looped add-to-cart for a month; whether the same
 * script also filled this table was never measured, and this check exists to
 * measure it. It reports. It deletes nothing and offers no button: a rule
 * written before the pattern has been seen on a real store would delete real
 * shoppers on the stores it has not seen, and a deleted entry is a recovery
 * email that never goes out.
 *
 * Four patterns, split into two tiers that are counted, listed and labelled
 * apart:
 *
 *   Certain - a browser id this plugin never issued (the cookie is HttpOnly
 *   and minted only here, so a foreign value was typed by the client); one
 *   browser id typing ten or more different addresses within minutes; one
 *   address arriving from five or more browser ids within minutes; twenty or
 *   more empty checkout signups inside a single minute.
 *
 *   Likely - the same shapes over a longer span or at lower volume. A shared
 *   family computer, a shopper on three devices, or a busy promotion can look
 *   like this, which is why the tier exists.
 *
 * Only entries that are still open and belong to nobody are examined:
 * recovered carts are order history, logged-in shoppers have a real account,
 * and imported rows carry whatever the source system gave them.
 *
 * Every figure comes out of GROUP BY / HAVING on the database; PHP receives a
 * few hundred group rows at most, so peak memory does not move with the size
 * of the table. The scan does read the whole open set several times, which
 * is fine at hundreds of thousands of rows and would want batching at
 * millions.
 *
 * @package BrikPanel
 * @since   3.3.2
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Brikpanel_BrikControl_Cartab_Bot_Rows_Check extends Brikpanel_BrikControl_Check {

    /**
     * Rows shown in the card's detail table.
     */
    const SAMPLE_LIMIT = 25;

    /**
     * Groups read per pattern. One more is asked for so the scan can tell it
     * was cut short without a second COUNT query; the card then says the
     * figures are lower bounds.
     */
    const MAX_GROUPS = 200;

    /**
     * Foreign browser ids listed by example (they are counted in full).
     */
    const MALFORMED_SAMPLE_ROWS = 10;

    /**
     * What uniqid( 'bp_', true ) produces: `bp_`, thirteen lowercase hex
     * digits, one or two decimal digits, a dot, eight decimal digits.
     * Always bound as a %s parameter, never interpolated. Compared against a
     * BINARY cast because the table collation is case-insensitive and would
     * let an upper-case forgery through.
     */
    const ID_REGEXP = '^bp_[0-9a-f]{13}[0-9]{1,2}[.][0-9]{8}$';

    /**
     * Entries examined: still open, credited to no order, owned by no
     * account, written by the public endpoint.
     */
    const SCOPE_SQL = "status IN ('active','abandoned') AND order_id = 0 AND user_id = 0 AND source IN ('checkout','popup')";

    /**
     * An entry that only ever held an address: no items, no name, no phone,
     * never touched after it was written.
     */
    const SHELL_SQL = "item_count = 0 AND first_name = '' AND last_name = '' AND phone = '' AND updated_at = created_at";

    /**
     * Minute bucket of created_at (UTC, hard-edged: a burst straddling :59/:00
     * lands in two buckets). The doubled percent signs are for
     * $wpdb->prepare(), so this fragment must only ever be used inside one.
     */
    const MINUTE_SQL = "DATE_FORMAT(created_at, '%%Y-%%m-%%d %%H:%%i')";

    /**
     * @return string
     */
    public function get_id() {
        return 'cartab_bot_rows';
    }

    /**
     * @return string
     */
    public function get_label() {
        return __( 'Abandoned cart entries', 'brikpanel' );
    }

    /**
     * @return string
     */
    public function get_category() {
        return 'content';
    }

    /**
     * Right after the add-to-cart history check it belongs with.
     *
     * @return int
     */
    public function get_priority() {
        return 26;
    }

    /**
     * A handful of aggregate queries; runs inline in the scan worker.
     *
     * @return bool
     */
    public function supports_batching() {
        return false;
    }

    /**
     * Figures only; the card writes the sentences (see bc_present()).
     *
     * @return int
     */
    public function bc_schema() {
        return 2;
    }

    /* ---------------------------------------------------------------------
     * Run
     * ------------------------------------------------------------------ */

    /**
     * @param array $state Unused; the check is not batched.
     * @return array CheckResult
     */
    public function run( array $state = [] ) {
        global $wpdb;

        $started = microtime( true );
        $result  = $this->make_result_skeleton();
        $table   = $wpdb->prefix . 'brikpanel_abandoned_carts';

        if ( ! $this->table_exists( $table ) ) {
            $result['status']      = 'unknown';
            $result['facts']       = [ 'state' => 'no_table' ];
            $result['duration_ms'] = (int) round( ( microtime( true ) - $started ) * 1000 );
            return $result;
        }

        $thresholds = $this->thresholds();
        $scan       = $this->scan( $table, $thresholds );

        if ( null === $scan ) {
            $result['status']      = 'unknown';
            // The database's own words, shown as they are: not a translation.
            $result['facts']       = [ 'state' => 'query_failed', 'db_error' => (string) $wpdb->last_error ];
            $result['duration_ms'] = (int) round( ( microtime( true ) - $started ) * 1000 );
            return $result;
        }

        $counts        = $scan['counts'];
        $scoped        = (int) $counts['scoped'];
        $certain_rows  = (int) $counts['certain_rows'];
        $flagged_rows  = (int) $counts['flagged_rows'];

        if ( $scoped < 1 ) {
            $result['status'] = 'ok';
            $result['score']  = 100;
        } elseif ( $certain_rows > 0 ) {
            $result['status'] = 'critical';
            $result['score']  = 0;
        } elseif ( $flagged_rows > 0 ) {
            $result['status'] = 'warning';
            $result['score']  = 55;
        } else {
            $result['status'] = 'ok';
            $result['score']  = 100;
        }

        $result['facts'] = [
            'state'            => 'scanned',
            'scoped'           => $scoped,
            'certain_rows'     => $certain_rows,
            'flagged_rows'     => $flagged_rows,
            'certain_groups'   => (int) $counts['r1_ids'] + $scan['groups_certain'],
            'likely_groups'    => $scan['groups_likely'],
            'truncated'        => (bool) $scan['truncated'],
            'max_groups'       => self::MAX_GROUPS,
            'coupons_flagged'  => $scan['coupons_flagged'],
            'coupons_orphaned' => $scan['coupons_orphaned'],
            'thresholds'       => $thresholds,
            // The worst groups as data (rule, tier, key, counts, UTC times).
            'samples'          => array_slice( $scan['findings'], 0, self::SAMPLE_LIMIT ),
        ];

        $result['duration_ms'] = (int) round( ( microtime( true ) - $started ) * 1000 );

        return $result;
    }

    /**
     * Sentences for the stored figures, in the viewer's language.
     *
     * @param array  $r       Stored result.
     * @param string $context 'page' or 'summary'.
     * @return array
     */
    public function bc_present( array $r, $context = 'page' ) {
        $r = parent::bc_present( $r, $context );

        $f = isset( $r['facts'] ) && is_array( $r['facts'] ) ? $r['facts'] : [];
        if ( empty( $f['state'] ) || (int) ( $r['schema'] ?? 1 ) < 2 ) {
            return $r; // Written before 3.3.25: show what was stored.
        }

        $r['message']         = '';
        $r['recommendations'] = [];
        $r['metadata']        = [];

        if ( 'no_table' === $f['state'] ) {
            $r['summary'] = __( 'The abandoned-carts table could not be found.', 'brikpanel' );
            $r['message'] = __( 'BrikPanel creates it when the plugin is activated. Deactivate and reactivate BrikPanel, then run this check again.', 'brikpanel' );
            return $r;
        }
        if ( 'query_failed' === $f['state'] ) {
            $r['summary'] = __( 'The abandoned-cart entries could not be read.', 'brikpanel' );
            $r['message'] = brikpanel_safe_sprintf(
                /* translators: %s: database error text. */
                __( 'The database refused one of the scan queries: %s', 'brikpanel' ),
                (string) ( $f['db_error'] ?? '' )
            );
            return $r;
        }

        $scoped       = (int) ( $f['scoped'] ?? 0 );
        $certain_rows = (int) ( $f['certain_rows'] ?? 0 );
        $flagged_rows = (int) ( $f['flagged_rows'] ?? 0 );
        $likely_rows  = max( 0, $flagged_rows - $certain_rows );
        $truncated    = ! empty( $f['truncated'] );
        $bot_check    = class_exists( 'Brikpanel_BrikControl_Registry' ) ? Brikpanel_BrikControl_Registry::get( 'bot_traffic' ) : null;

        if ( $scoped < 1 ) {
            $r['summary'] = __( 'No abandoned-cart entries to check yet.', 'brikpanel' );
            $r['message'] = __( 'Entries appear here once a shopper types an email at checkout or in the signup popup.', 'brikpanel' );
        } elseif ( $certain_rows > 0 ) {
            $r['summary'] = brikpanel_safe_sprintf(
                /* translators: %s: number of entries. */
                _n(
                    '%s entry was almost certainly written by a script, not a shopper',
                    '%s entries were almost certainly written by a script, not a shopper',
                    $certain_rows,
                    'brikpanel'
                ),
                brikpanel_number( $certain_rows )
            );
            $r['message'] = __( 'These entries carry a browser id this plugin never issued, or arrive in shapes a browser cannot produce: one browser id typing many different addresses within minutes, one address arriving from many browser ids within minutes, or dozens of empty signups inside a single minute.', 'brikpanel' );
            $r['message'] .= ' ' . ( $bot_check
                ? brikpanel_safe_sprintf(
                    /* translators: %s: name of another check on the Store Health page, e.g. "Bot traffic". */
                    __( 'This check only reports; the "%s" check on this page deletes the certain ones, with a restore point.', 'brikpanel' ),
                    $bot_check->get_label()
                )
                : __( 'This check only reports and changes nothing.', 'brikpanel' ) );
            $r['message'] .= ' ' . __( 'The capture endpoint now refuses foreign browser ids and rate-limits by connection, so new entries of this kind should stop.', 'brikpanel' );
            if ( $likely_rows > 0 ) {
                $r['message'] .= ' ' . brikpanel_safe_sprintf(
                    /* translators: %s: number of entries. */
                    _n(
                        '%s further entry repeats in a way that is suspicious but not conclusive.',
                        '%s further entries repeat in ways that are suspicious but not conclusive.',
                        $likely_rows,
                        'brikpanel'
                    ),
                    brikpanel_number( $likely_rows )
                );
            }
        } elseif ( $flagged_rows > 0 ) {
            $r['summary'] = brikpanel_safe_sprintf(
                /* translators: %s: number of entries. */
                _n(
                    '%s entry repeats in a way a script would produce',
                    '%s entries repeat in ways a script would produce',
                    $flagged_rows,
                    'brikpanel'
                ),
                brikpanel_number( $flagged_rows )
            );
            $r['message'] = __( 'One browser id with several addresses, one address across several browser ids, or a burst of empty signups. A shared computer, a shopper on several devices or a busy promotion can look exactly the same, so open the list below and check before deleting anything. Nothing has been changed by this check.', 'brikpanel' );
        } else {
            $r['summary'] = __( 'Your abandoned-cart entries look like real shoppers.', 'brikpanel' );
            $r['message'] = __( 'Every browser id has the shape this plugin issues, no browser id juggles many addresses, no address is spread across many browser ids, and no minute holds a burst of empty signups.', 'brikpanel' );
        }

        if ( $truncated && $flagged_rows > 0 ) {
            $r['message'] = trim(
                $r['message'] . ' ' . brikpanel_safe_sprintf(
                    /* translators: %s: number of groups. */
                    __( 'More groups matched than one pass counts; only the %s largest groups per pattern are included, so these figures are lower bounds.', 'brikpanel' ),
                    brikpanel_number( (int) ( $f['max_groups'] ?? self::MAX_GROUPS ) )
                )
            );
        }

        if ( 'page' !== $context ) {
            return $r;
        }

        $certain_grps = (int) ( $f['certain_groups'] ?? 0 );
        $likely_grps  = (int) ( $f['likely_groups'] ?? 0 );

        $stats = [
            [
                'label' => __( 'Certain groups', 'brikpanel' ),
                'value' => $certain_grps,
                'tone'  => $certain_grps > 0 ? 'error' : 'good',
            ],
            [
                'label' => __( 'Likely groups', 'brikpanel' ),
                'value' => $likely_grps,
                'tone'  => $likely_grps > 0 ? 'warn' : '',
            ],
            [
                'label' => $truncated ? __( 'Entries involved (at least)', 'brikpanel' ) : __( 'Entries involved', 'brikpanel' ),
                'value' => $flagged_rows,
                'tone'  => $flagged_rows > 0 ? 'warn' : '',
            ],
            [
                'label' => __( 'Entries checked', 'brikpanel' ),
                'value' => $scoped,
                'tone'  => '',
            ],
        ];
        // The coupon figures only mean something next to flagged entries; on a
        // clean store a fifth tile only pushed the row into a list.
        if ( $flagged_rows > 0 && isset( $f['coupons_flagged'] ) && null !== $f['coupons_flagged'] ) {
            $stats[] = [
                'label' => __( 'Coupons for flagged emails', 'brikpanel' ),
                'value' => (int) $f['coupons_flagged'],
                'tone'  => (int) $f['coupons_flagged'] > 0 ? 'warn' : '',
            ];
        }
        if ( $flagged_rows > 0 && isset( $f['coupons_orphaned'] ) && null !== $f['coupons_orphaned'] ) {
            $stats[] = [
                'label' => __( 'Coupons with no cart entry', 'brikpanel' ),
                'value' => (int) $f['coupons_orphaned'],
                'tone'  => '',
            ];
        }

        $r['recommendations'] = $this->build_recommendations( $flagged_rows, $f['coupons_flagged'] ?? null, $bot_check );
        $r['metadata']        = [
            'stats'         => $stats,
            'samples'       => $this->build_samples( (array) ( $f['samples'] ?? [] ) ),
            'samples_title' => __( 'Entries that look automated', 'brikpanel' ),
            'samples_cols'  => [
                __( 'Pattern', 'brikpanel' ),
                __( 'Certainty', 'brikpanel' ),
                __( 'Identifier', 'brikpanel' ),
                __( 'Entries', 'brikpanel' ),
                __( 'Example email', 'brikpanel' ),
                __( 'First seen', 'brikpanel' ),
            ],
        ];

        return $r;
    }

    /* ---------------------------------------------------------------------
     * Scan
     * ------------------------------------------------------------------ */

    /**
     * Thresholds, filterable for the rare store whose honest traffic crosses
     * them, clamped so a filter cannot switch a pattern off by accident.
     *
     * @return array
     */
    private function thresholds() {
        $defaults = [
            'visitor_emails_certain'   => 10,
            'visitor_emails_likely'    => 5,
            'email_visitors_certain'   => 5,
            'email_visitors_likely'    => 5,
            'burst_seconds'            => 600,
            'shell_per_minute_certain' => 20,
            'shell_per_minute_likely'  => 5,
        ];

        /**
         * Filters the thresholds that decide when abandoned-cart entries look
         * automated. Keys and defaults as in the array above; every count
         * clamps to at least 2 and every "certain" to at least its "likely".
         *
         * @since 3.3.2
         *
         * @param array $thresholds
         */
        $t = apply_filters( 'brikpanel_cartab_bot_rows_thresholds', $defaults );
        if ( ! is_array( $t ) ) {
            $t = $defaults;
        }
        $t = array_merge( $defaults, array_intersect_key( $t, $defaults ) );

        foreach ( [ 'visitor_emails', 'email_visitors', 'shell_per_minute' ] as $pair ) {
            $t[ $pair . '_likely' ]  = max( 2, (int) $t[ $pair . '_likely' ] );
            $t[ $pair . '_certain' ] = max( $t[ $pair . '_likely' ], (int) $t[ $pair . '_certain' ] );
        }
        $t['burst_seconds'] = min( DAY_IN_SECONDS, max( 60, (int) $t['burst_seconds'] ) );

        return $t;
    }

    /**
     * Everything the card needs, computed on the database side.
     *
     * @param string $table Prefixed table name.
     * @param array  $t     Thresholds.
     * @return array|null Null when a query failed ($wpdb->last_error says why).
     */
    private function scan( $table, array $t ) {
        global $wpdb;

        $findings  = [];
        $truncated = false;
        $keys      = $this->group_keys( $table, $t, $findings, $truncated );
        if ( null === $keys ) {
            return null;
        }

        // Every count in one pass over the open set. The group keys found above
        // go back in as IN lists over indexed columns; rows never come out.
        $params      = [];
        $r1_pred     = $this->malformed_predicate( $params );
        $certain_sql = $this->union_predicate( $keys, [ 'certain' ], $params );
        $any_sql     = $this->union_predicate( $keys, [ 'certain', 'likely' ], $params );

        $sql = "SELECT COUNT(*) AS scoped,
                       COUNT(DISTINCT CASE WHEN {$r1_pred} THEN visitor_id END) AS r1_ids,
                       SUM(CASE WHEN {$certain_sql} THEN 1 ELSE 0 END) AS certain_rows,
                       SUM(CASE WHEN {$any_sql} THEN 1 ELSE 0 END) AS flagged_rows
                  FROM {$table}
                 WHERE " . self::SCOPE_SQL;
        // The regexp bound for r1_ids comes first; union_predicate() appended
        // its own parameters in the order its fragments appear in the SQL.
        $counts = $wpdb->get_row( $wpdb->prepare( $sql, $params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        if ( ! is_array( $counts ) ) {
            return null;
        }

        $groups_certain = 0;
        $groups_likely  = 0;
        foreach ( $findings as $finding ) {
            if ( 'malformed' === $finding['rule'] ) {
                continue; // Counted from the database, not from the ten examples.
            }
            if ( 'certain' === $finding['tier'] ) {
                $groups_certain++;
            } else {
                $groups_likely++;
            }
        }

        // Coupons are only relevant when something is flagged, and both coupon
        // figures are optional: a migrated site can carry mixed collations
        // between postmeta and this table, and a stat that reads 0 because the
        // query failed would be a lie. suppress_errors() has no precedent in
        // this codebase; it is used here so a collation clash on one store
        // does not surface as a database error on every scan.
        $coupons_flagged  = null;
        $coupons_orphaned = null;
        if ( (int) $counts['flagged_rows'] > 0 ) {
            $coupon_params   = [ '_brikpanel_cartab_email' ];
            $flagged_sql     = $this->union_predicate( $keys, [ 'certain', 'likely' ], $coupon_params );
            $coupons_flagged = $this->count_or_null(
                "SELECT COUNT(DISTINCT pm.post_id)
                   FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_type = 'shop_coupon' AND p.post_status <> 'trash'
                  WHERE pm.meta_key = %s
                    AND pm.meta_value IN ( SELECT email FROM {$table} WHERE " . self::SCOPE_SQL . " AND email <> '' AND ( {$flagged_sql} ) )",
                $coupon_params
            );
        }
        $coupons_orphaned = $this->count_or_null(
            "SELECT COUNT(DISTINCT pm.post_id)
               FROM {$wpdb->postmeta} pm
         INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_type = 'shop_coupon' AND p.post_status <> 'trash'
          LEFT JOIN {$table} ac ON ac.email = pm.meta_value
              WHERE pm.meta_key = %s AND ac.id IS NULL",
            [ '_brikpanel_cartab_email' ]
        );

        // Certain first, then the biggest groups, so the table opens on what
        // matters.
        usort( $findings, static function ( $a, $b ) {
            if ( $a['tier'] !== $b['tier'] ) {
                return ( 'certain' === $a['tier'] ) ? -1 : 1;
            }
            return $b['rows'] <=> $a['rows'];
        } );

        return [
            'findings'         => $findings,
            'counts'           => $counts,
            'groups_certain'   => $groups_certain,
            'groups_likely'    => $groups_likely,
            'truncated'        => $truncated,
            'coupons_flagged'  => $coupons_flagged,
            'coupons_orphaned' => $coupons_orphaned,
        ];
    }

    /**
     * The three group patterns, as findings plus the key lists the count and
     * delete predicates are built from. Shared by the report and by the Bot
     * Traffic cleanup, so the entries that check deletes are exactly the ones
     * this card calls certain.
     *
     * @since 3.3.11
     *
     * @param string $table     Prefixed table name.
     * @param array  $t         Thresholds.
     * @param array  $findings  By reference.
     * @param bool   $truncated By reference.
     * @return array|null Null when a query failed.
     */
    private function group_keys( $table, array $t, array &$findings, &$truncated ) {
        global $wpdb;

        $keys = [
            'visitor' => [ 'certain' => [], 'likely' => [] ],
            'email'   => [ 'certain' => [], 'likely' => [] ],
            'burst'   => [ 'certain' => [], 'likely' => [] ],
        ];

        // One browser id, many addresses.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT visitor_id AS grp, COUNT(DISTINCT email) AS distinct_n, COUNT(*) AS rows_n,
                        MIN(created_at) AS first_at,
                        TIMESTAMPDIFF(SECOND, MIN(created_at), MAX(created_at)) AS span_s,
                        MIN(email) AS example_email
                   FROM {$table}
                  WHERE " . self::SCOPE_SQL . " AND visitor_id <> ''
               GROUP BY visitor_id
                 HAVING distinct_n >= %d
               ORDER BY distinct_n DESC, rows_n DESC
                  LIMIT %d",
                $t['visitor_emails_likely'],
                self::MAX_GROUPS + 1
            ),
            ARRAY_A
        );
        if ( '' !== $wpdb->last_error ) {
            return null;
        }
        $truncated = $this->collect( $findings, $keys, 'visitor', (array) $rows, $t['visitor_emails_certain'], $t['burst_seconds'] ) || $truncated;

        // One address, many browser ids. An empty id is not a browser.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT email AS grp, COUNT(DISTINCT NULLIF(visitor_id, '')) AS distinct_n, COUNT(*) AS rows_n,
                        MIN(created_at) AS first_at,
                        TIMESTAMPDIFF(SECOND, MIN(created_at), MAX(created_at)) AS span_s,
                        MIN(email) AS example_email
                   FROM {$table}
                  WHERE " . self::SCOPE_SQL . " AND email <> ''
               GROUP BY email
                 HAVING distinct_n >= %d
               ORDER BY distinct_n DESC, rows_n DESC
                  LIMIT %d",
                $t['email_visitors_likely'],
                self::MAX_GROUPS + 1
            ),
            ARRAY_A
        );
        if ( '' !== $wpdb->last_error ) {
            return null;
        }
        $truncated = $this->collect( $findings, $keys, 'email', (array) $rows, $t['email_visitors_certain'], $t['burst_seconds'] ) || $truncated;

        // Bursts of empty signups, per minute and per source. Popup entries
        // are empty by nature (the visitor has not shopped yet), so a popup
        // burst never rates higher than "likely": a campaign minute must not
        // read as certain.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT " . self::MINUTE_SQL . " AS grp, source, COUNT(*) AS rows_n, COUNT(DISTINCT email) AS distinct_n,
                        MIN(created_at) AS first_at, MIN(email) AS example_email
                   FROM {$table}
                  WHERE " . self::SCOPE_SQL . " AND " . self::SHELL_SQL . "
               GROUP BY grp, source
                 HAVING rows_n >= %d
               ORDER BY rows_n DESC
                  LIMIT %d",
                $t['shell_per_minute_likely'],
                self::MAX_GROUPS + 1
            ),
            ARRAY_A
        );
        if ( '' !== $wpdb->last_error ) {
            return null;
        }
        $rows = (array) $rows;
        if ( count( $rows ) > self::MAX_GROUPS ) {
            $truncated = true;
            $rows      = array_slice( $rows, 0, self::MAX_GROUPS );
        }
        foreach ( $rows as $row ) {
            $source  = ( 'popup' === $row['source'] ) ? 'popup' : 'checkout';
            $certain = ( 'checkout' === $source ) && ( (int) $row['rows_n'] >= $t['shell_per_minute_certain'] );
            $tier    = $certain ? 'certain' : 'likely';

            $keys['burst'][ $tier ][ $source ][] = (string) $row['grp'];
            $findings[]                          = [
                'rule'     => 'burst',
                'tier'     => $tier,
                'key'      => (string) $row['grp'],
                'source'   => $source,
                'rows'     => (int) $row['rows_n'],
                'distinct' => (int) $row['distinct_n'],
                'span'     => 0,
                'example'  => (string) $row['example_email'],
                'first_at' => (string) $row['first_at'],
            ];
        }

        // Browser ids this plugin never issued: a few by example, all by count.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT visitor_id, email, created_at
                   FROM {$table}
                  WHERE " . self::SCOPE_SQL . " AND visitor_id <> '' AND CAST(visitor_id AS BINARY) NOT REGEXP %s
               ORDER BY id DESC
                  LIMIT %d",
                self::ID_REGEXP,
                self::MALFORMED_SAMPLE_ROWS
            ),
            ARRAY_A
        );
        if ( '' !== $wpdb->last_error ) {
            return null;
        }
        foreach ( (array) $rows as $row ) {
            $findings[] = [
                'rule'     => 'malformed',
                'tier'     => 'certain',
                'key'      => (string) $row['visitor_id'],
                'source'   => '',
                'rows'     => 1,
                'distinct' => 1,
                'span'     => 0,
                'example'  => (string) $row['email'],
                'first_at' => (string) $row['created_at'],
            ];
        }

        return $keys;
    }

    /**
     * Entries the certain tier covers: their total, and up to $limit ids
     * (oldest first). What the Bot Traffic cleanup deletes.
     *
     * @since 3.3.11
     *
     * @param int $limit Ids to return; 0 for the count only.
     * @return array{ids:int[],total:int}|null Null when the table is missing or a query failed.
     */
    public static function certain_rows( $limit = 0 ) {
        global $wpdb;

        $self  = new self();
        $table = $wpdb->prefix . 'brikpanel_abandoned_carts';
        if ( ! $self->table_exists( $table ) ) {
            return null;
        }

        $findings  = [];
        $truncated = false;
        $keys      = $self->group_keys( $table, $self->thresholds(), $findings, $truncated );
        if ( null === $keys ) {
            return null;
        }

        $params = [];
        $pred   = $self->union_predicate( $keys, [ 'certain' ], $params );
        $where  = self::SCOPE_SQL . " AND {$pred}";

        $total = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where}", $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        if ( '' !== $wpdb->last_error ) {
            return null;
        }

        $ids = [];
        if ( (int) $limit > 0 && (int) $total > 0 ) {
            $ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE {$where} ORDER BY id ASC LIMIT %d", array_merge( $params, [ (int) $limit ] ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        }

        return [ 'ids' => array_map( 'intval', (array) $ids ), 'total' => (int) $total ];
    }

    /**
     * Turn the group rows of the visitor / email patterns into findings and
     * key lists. Reports whether the list was cut short.
     *
     * @param array  $findings By reference.
     * @param array  $keys     By reference.
     * @param string $rule     'visitor' or 'email'.
     * @param array  $rows     Group rows (grp, distinct_n, rows_n, first_at, span_s, example_email).
     * @param int    $certain  Distinct count that makes the group certain (with the span).
     * @param int    $span     Seconds within which the certain count must occur.
     * @return bool True when more groups existed than were read.
     */
    private function collect( array &$findings, array &$keys, $rule, array $rows, $certain, $span ) {
        $truncated = count( $rows ) > self::MAX_GROUPS;
        if ( $truncated ) {
            $rows = array_slice( $rows, 0, self::MAX_GROUPS );
        }

        foreach ( $rows as $row ) {
            $distinct = (int) $row['distinct_n'];
            $span_s   = (int) $row['span_s'];
            $tier     = ( $distinct >= $certain && $span_s <= $span ) ? 'certain' : 'likely';

            $keys[ $rule ][ $tier ][] = (string) $row['grp'];
            $findings[]               = [
                'rule'     => $rule,
                'tier'     => $tier,
                'key'      => (string) $row['grp'],
                'source'   => '',
                'rows'     => (int) $row['rows_n'],
                'distinct' => $distinct,
                'span'     => $span_s,
                'example'  => (string) $row['example_email'],
                'first_at' => (string) $row['first_at'],
            ];
        }

        return $truncated;
    }

    /**
     * SQL for "browser id not issued by this plugin"; appends its parameter.
     *
     * @param array $params By reference.
     * @return string
     */
    private function malformed_predicate( array &$params ) {
        $params[] = self::ID_REGEXP;
        return "(visitor_id <> '' AND CAST(visitor_id AS BINARY) NOT REGEXP %s)";
    }

    /**
     * SQL matching every row that belongs to one of the given tiers, built
     * from the group keys already found. Parameters are appended in the order
     * the fragments appear, so the caller passes the same array to prepare().
     *
     * @param array    $keys   Group keys per rule and tier.
     * @param string[] $tiers  'certain' and/or 'likely'.
     * @param array    $params By reference.
     * @return string A predicate; literal 0 when nothing could match.
     */
    private function union_predicate( array $keys, array $tiers, array &$params ) {
        $parts = [];

        if ( in_array( 'certain', $tiers, true ) ) {
            $parts[] = $this->malformed_predicate( $params );
        }

        foreach ( [ 'visitor' => 'visitor_id', 'email' => 'email' ] as $rule => $column ) {
            $list = [];
            foreach ( $tiers as $tier ) {
                $list = array_merge( $list, $keys[ $rule ][ $tier ] );
            }
            $in = $this->in_list( $list, $params );
            if ( '' !== $in ) {
                $parts[] = "{$column} IN ({$in})";
            }
        }

        foreach ( [ 'checkout', 'popup' ] as $source ) {
            $list = [];
            foreach ( $tiers as $tier ) {
                if ( ! empty( $keys['burst'][ $tier ][ $source ] ) ) {
                    $list = array_merge( $list, $keys['burst'][ $tier ][ $source ] );
                }
            }
            $in = $this->in_list( $list, $params );
            if ( '' !== $in ) {
                $parts[] = "(source = '{$source}' AND " . self::SHELL_SQL . ' AND ' . self::MINUTE_SQL . " IN ({$in}))";
            }
        }

        if ( empty( $parts ) ) {
            return '0';
        }

        return '(' . implode( ' OR ', $parts ) . ')';
    }

    /**
     * Placeholder list for an IN clause; appends the values.
     *
     * @param string[] $values
     * @param array    $params By reference.
     * @return string '' when the list is empty (IN () is a syntax error).
     */
    private function in_list( array $values, array &$params ) {
        $values = array_values( array_unique( array_map( 'strval', $values ) ) );
        if ( empty( $values ) ) {
            return '';
        }
        foreach ( $values as $value ) {
            $params[] = $value;
        }
        return implode( ',', array_fill( 0, count( $values ), '%s' ) );
    }

    /**
     * A single integer from the database, or null when the query failed.
     *
     * @param string $sql    Query with placeholders.
     * @param array  $params Parameters.
     * @return int|null
     */
    private function count_or_null( $sql, array $params ) {
        global $wpdb;

        $was_suppressed   = $wpdb->suppress_errors( true );
        $wpdb->last_error = '';
        $value            = $wpdb->get_var( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $failed           = ( '' !== $wpdb->last_error );
        $wpdb->suppress_errors( $was_suppressed );

        if ( $failed || null === $value ) {
            return null;
        }

        return (int) $value;
    }

    /* ---------------------------------------------------------------------
     * Presentation
     * ------------------------------------------------------------------ */

    /**
     * Detail rows for the card table, from the stored findings. Plain
     * scalars only; the card escapes.
     *
     * @param array $findings Stored findings, sorted.
     * @return array
     */
    private function build_samples( array $findings ) {
        $samples = [];

        foreach ( array_slice( $findings, 0, self::SAMPLE_LIMIT ) as $f ) {
            if ( ! is_array( $f ) ) {
                continue;
            }
            $rule     = (string) ( $f['rule'] ?? '' );
            $key      = (string) ( $f['key'] ?? '' );
            $rows     = (int) ( $f['rows'] ?? 0 );
            $distinct = (int) ( $f['distinct'] ?? 0 );
            $span     = (int) ( $f['span'] ?? 0 );

            switch ( $rule ) {
                case 'visitor':
                    $pattern    = brikpanel_safe_sprintf(
                        /* translators: 1: number of email addresses, 2: time span such as "3 mins". */
                        _n(
                            'One browser id, %1$s address in %2$s',
                            'One browser id, %1$s addresses in %2$s',
                            $distinct,
                            'brikpanel'
                        ),
                        brikpanel_number( $distinct ),
                        $this->span_label( $span )
                    );
                    $identifier = substr( $key, 0, 40 );
                    break;
                case 'email':
                    $pattern    = brikpanel_safe_sprintf(
                        /* translators: 1: number of browser ids, 2: time span such as "3 mins". */
                        _n(
                            'One address, %1$s browser id in %2$s',
                            'One address, %1$s browser ids in %2$s',
                            $distinct,
                            'brikpanel'
                        ),
                        brikpanel_number( $distinct ),
                        $this->span_label( $span )
                    );
                    $identifier = $key;
                    break;
                case 'burst':
                    $pattern    = ( 'popup' === ( $f['source'] ?? '' ) )
                        ? brikpanel_safe_sprintf(
                            /* translators: %s: number of entries. */
                            _n( '%s empty popup signup in one minute', '%s empty popup signups in one minute', $rows, 'brikpanel' ),
                            brikpanel_number( $rows )
                        )
                        : brikpanel_safe_sprintf(
                            /* translators: %s: number of entries. */
                            _n( '%s empty checkout signup in one minute', '%s empty checkout signups in one minute', $rows, 'brikpanel' ),
                            brikpanel_number( $rows )
                        );
                    // The minute the burst happened, in the store's clock and format.
                    $identifier = brikpanel_local_datetime( $key . ':00', brikpanel_short_date_format() . ' ' . brikpanel_time_format(), $key );
                    break;
                default:
                    $pattern    = __( 'Browser id not issued by this plugin', 'brikpanel' );
                    $identifier = substr( $key, 0, 40 );
                    break;
            }

            $samples[] = [
                $pattern,
                ( 'certain' === ( $f['tier'] ?? '' ) ) ? __( 'Certain', 'brikpanel' ) : __( 'Likely', 'brikpanel' ),
                $identifier,
                brikpanel_number( $rows ),
                (string) ( $f['example'] ?? '' ),
                brikpanel_local_datetime( (string) ( $f['first_at'] ?? '' ), brikpanel_short_date_format() . ' ' . brikpanel_time_format() ),
            ];
        }

        return $samples;
    }

    /**
     * "3 mins" / "2 days" for a span in seconds; "under a minute" below one.
     *
     * @param int $seconds
     * @return string
     */
    private function span_label( $seconds ) {
        $seconds = (int) $seconds;
        if ( $seconds < MINUTE_IN_SECONDS ) {
            return __( 'under a minute', 'brikpanel' );
        }
        return human_time_diff( 0, $seconds );
    }

    /**
     * @param int                                $flagged_rows    Entries involved.
     * @param int|null                           $coupons_flagged Coupons handed to flagged addresses, when known.
     * @param Brikpanel_BrikControl_Check|null   $bot_check       The check whose cleanup removes the certain entries.
     * @return array
     */
    private function build_recommendations( $flagged_rows, $coupons_flagged, $bot_check = null ) {
        $recs = [];

        if ( $flagged_rows > 0 ) {
            $carts_url = function_exists( 'brikpanel_module_url' )
                ? brikpanel_module_url( 'brikpanel-abandoned-carts' )
                : admin_url( 'admin.php?page=brikpanel-abandoned-carts' );
            $recs[] = [
                'text'     => $bot_check
                    ? brikpanel_safe_sprintf(
                        /* translators: %s: label of the cleanup button of another check, e.g. "Clean up bot traffic". */
                        __( 'Open Abandoned Carts and search for the example addresses below. This check changed nothing. "%s" on this page removes the certain entries; delete a likely one by hand only once you are sure it is not a shopper.', 'brikpanel' ),
                        $bot_check->get_fix_label()
                    )
                    : __( 'Open Abandoned Carts and search for the example addresses below. This check changed nothing; delete an entry by hand only once you are sure it is not a shopper.', 'brikpanel' ),
                'priority' => 'high',
                'link'     => [
                    'url'   => $carts_url,
                    'label' => __( 'Open Abandoned Carts', 'brikpanel' ),
                ],
            ];
            if ( class_exists( 'Brikpanel_BrikControl' ) ) {
                $recs[] = Brikpanel_BrikControl::exclusion_recommendation();
            }
        }

        if ( (int) $coupons_flagged > 0 ) {
            $coupons_url = function_exists( 'brikpanel_module_url' )
                ? brikpanel_module_url( 'brikpanel-coupons' )
                : admin_url( 'edit.php?post_type=shop_coupon' );
            $recs[] = [
                'text'     => __( 'Popup signups among these entries were handed real coupons (codes starting with BRIK-). Each is single-use and locked to the address it was issued to, so nobody else can redeem it; review them in your coupon list if you want them gone.', 'brikpanel' ),
                'priority' => 'low',
                'link'     => [
                    'url'   => $coupons_url,
                    'label' => __( 'Open coupons', 'brikpanel' ),
                ],
            ];
        }

        return $recs;
    }

    /* ---------------------------------------------------------------------
     * Helpers
     * ------------------------------------------------------------------ */

    /**
     * Does the table exist? Memoised per request.
     *
     * @param string $table Fully prefixed table name.
     * @return bool
     */
    private function table_exists( $table ) {
        global $wpdb;

        static $cache = [];

        if ( isset( $cache[ $table ] ) ) {
            return $cache[ $table ];
        }

        $cache[ $table ] = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table );

        return $cache[ $table ];
    }
}
