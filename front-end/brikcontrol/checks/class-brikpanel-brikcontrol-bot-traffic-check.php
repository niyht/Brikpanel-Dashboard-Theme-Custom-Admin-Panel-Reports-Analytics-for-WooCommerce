<?php
/**
 * BrikPanel: BrikControl check for bot traffic left in the analytics tables.
 *
 * Every storefront counter BrikPanel keeps has, at some point, been inflated by
 * scripted traffic before its server-side cap existed: add-to-cart and
 * checkout until 3.3.1, daily visitors, product views, page views, traffic
 * sources and device counts until 3.3.11, and the abandoned-cart capture
 * endpoint until 3.3.2. The counters are fixed. The rows they already wrote
 * are not, and they are what the dashboard reads: one store with under a
 * hundred real visitors a day showed eleven thousand.
 *
 * This check looks at all of it in one place and cleans all of it with one
 * button. Two rules decide what is wrong, kept apart because they carry very
 * different certainty:
 *
 *   Impossible: a visit precedes an add-to-cart, so a day where the store's
 *   add-to-carts (or one product's) outnumber its visitors is arithmetic, not
 *   a judgement call. Capping to the visitor figure is the correction.
 *
 *   Extreme: a day many times the series' own normal, and large in absolute
 *   terms. A heuristic that can in principle catch a real promotion, which is
 *   why nothing is written before the merchant looks at the list and confirms.
 *
 *   Certain: an abandoned-cart entry written by a script, by the rules of the
 *   "Abandoned Cart Entries" check (a browser id this plugin never issued, one
 *   id typing many addresses within minutes, one address from many ids within
 *   minutes, bursts of empty checkout signups). Only the certain tier is
 *   deleted; the "likely" tier stays reported.
 *
 * Nothing is lost. A flagged day is lowered to the highest value it could
 * honestly have had (never zero: that would be as wrong as the inflated
 * figure), a flagged entry is copied whole before it is deleted, and every
 * previous value is kept in a restore point that one click replays. The
 * restore point never expires on its own; the merchant decides when it goes.
 *
 * @package BrikPanel
 * @since   3.3.11
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Brikpanel_BrikControl_Bot_Traffic_Check extends Brikpanel_BrikControl_Check {

    /** Days of history examined. */
    const DEFAULT_WINDOW_DAYS = 180;

    /** A day must be at least this many times the series' own median before the "extreme" rule fires. */
    const DEFAULT_MULTIPLIER = 10;

    /** ...and at least this large in absolute terms. */
    const DEFAULT_FLOOR = 25;

    /** Days of observed history a series needs before its median means anything. */
    const MIN_BASELINE_DAYS = 7;

    /**
     * Visitors a day needs before its visitor count is treated as a ceiling
     * for add-to-carts. The visitor counter runs from JavaScript and the
     * add-to-cart counter from a PHP hook, so a store whose tracker barely
     * runs would otherwise turn "more adds than visitors" into a stream of
     * wrong accusations against real days.
     */
    const MIN_CREDIBLE_VISITORS = 20;

    /** How far past the visitor count a day must go before the ceiling fires. */
    const CEILING_TOLERANCE = 1.5;

    /**
     * Page views are far noisier than the other figures: one shared blog post
     * or a newsletter can multiply a page's day many times over. The extreme
     * rule therefore asks for a wider margin there, both as a ratio and in
     * absolute terms, so that only the thousands a crawl leaves behind qualify.
     */
    const PAGE_MULTIPLIER_FACTOR = 2;
    const PAGE_FLOOR_FACTOR      = 8;

    /** Rows shown in the card's detail table. */
    const SAMPLE_LIMIT = 25;

    /**
     * Distinct series (products, pages) whose full daily history is pulled
     * into memory in one scan, worst first. The database filters candidates
     * first (HAVING), so on a healthy store almost nothing reaches PHP.
     */
    const MAX_CANDIDATE_SERIES = 200;

    /** Series read per query in the second pass, so peak memory stays flat. */
    const SERIES_CHUNK = 25;

    /** Day/series pairs corrected per click. */
    const FIX_CHUNK = 500;

    /** Abandoned-cart entries deleted per click. */
    const CARTAB_CHUNK = 1000;

    /** Index option: how many rows the restore point holds and where they live. */
    const BACKUP_OPTION = 'brikpanel_bot_traffic_backup';

    /** Prefix for the numbered options holding the rows themselves. */
    const BACKUP_CHUNK_PREFIX = 'brikpanel_bot_traffic_backup_';

    /** Rows per chunk option. */
    const BACKUP_CHUNK_ROWS = 5000;

    /** Ceiling so a runaway can never fill wp_options. Reaching it stops the repair rather than touching rows it could not restore. */
    const MAX_BACKUP_ROWS = 200000;

    /**
     * Restore point written by the "Add-to-Cart History" check this one
     * replaced (3.3.1 to 3.3.10). Same row shape for the visitors and cart
     * tables, so undo here replays it too and a merchant who corrected days
     * before upgrading keeps their way back.
     */
    const LEGACY_BACKUP_OPTION = 'brikpanel_cart_count_cleanup_backup';
    const LEGACY_CHUNK_PREFIX  = 'brikpanel_cart_count_cleanup_backup_';

    /** Columns the undo may write, per backup row type. */
    const UNDO_COLUMNS = [
        'v' => [ 'visitor_count', 'product_count', 'add_to_cart_count', 'checkout_count', 'mobile_count', 'tablet_count', 'desktop_count' ],
        'c' => [ 'cart_count' ],
        'r' => [ 'hits' ],
        'p' => [ 'visit_count' ],
    ];

    /**
     * Whether this store's visitor figures are worth reasoning from. Set once
     * per scan by prime_visitor_credibility().
     *
     * @var bool
     */
    private $visitors_credible = false;

    /* ---------------------------------------------------------------------
     * Identity
     * ------------------------------------------------------------------ */

    public function get_id() {
        return 'bot_traffic';
    }

    public function get_label() {
        return __( 'Bot Traffic', 'brikpanel' );
    }

    public function get_category() {
        return 'content';
    }

    public function get_priority() {
        return 25;
    }

    public function supports_batching() {
        return false;
    }

    public function supports_fix() {
        return true;
    }

    public function get_fix_label() {
        return __( 'Clean up bot traffic', 'brikpanel' );
    }

    /* ---------------------------------------------------------------------
     * Scan
     * ------------------------------------------------------------------ */

    /**
     * @param array $state Unused; this check is not batched.
     * @return array CheckResult
     */
    public function run( array $state = [] ) {
        $started = microtime( true );
        $result  = $this->make_result_skeleton();

        $scan   = $this->scan();
        $backup = $this->read_backup_index();
        $legacy = $this->read_index( self::LEGACY_BACKUP_OPTION );
        $undoable = $backup['count'] + $legacy['count'];
        $undo_at  = max( (int) $backup['time'], (int) $legacy['time'] );

        if ( null === $scan ) {
            $result['status']      = 'ok';
            $result['score']       = 100;
            $result['summary']     = __( 'No analytics history has been recorded yet.', 'brikpanel' );
            $result['metadata']    = [ 'undoable' => $undoable, 'undo_at' => $undo_at, 'undo_confirm' => $this->undo_confirm_text() ];
            $result['duration_ms'] = (int) round( ( microtime( true ) - $started ) * 1000 );
            return $result;
        }

        $impossible = (int) $scan['impossible'];
        $extreme    = (int) $scan['extreme'];
        $cartab     = (int) $scan['cartab'];
        $flagged    = $impossible + $extreme;
        $overcount  = (int) $scan['overcount'];
        $total      = $flagged + $cartab;

        if ( $impossible > 0 || $cartab > 0 ) {
            $result['status'] = 'critical';
            $result['score']  = 0;
        } elseif ( $extreme > 0 ) {
            $result['status'] = 'warning';
            $result['score']  = 55;
        } else {
            $result['status'] = 'ok';
            $result['score']  = 100;
        }

        if ( $total > 0 ) {
            $result['summary'] = $this->safe_sprintf(
                /* translators: 1: number of days, 2: number of abandoned-cart entries. */
                __( '%1$s day(s) of figures and %2$s abandoned-cart entries look like bot traffic', 'brikpanel' ),
                number_format_i18n( $flagged ),
                number_format_i18n( $cartab )
            );
            $parts = [];
            if ( $impossible > 0 ) {
                $parts[] = $this->safe_sprintf(
                    /* translators: %s: number of days. */
                    _n(
                        '%s day recorded more add-to-carts than the store had visitors, which cannot be real.',
                        '%s days recorded more add-to-carts than the store had visitors, which cannot be real.',
                        $impossible,
                        'brikpanel'
                    ),
                    number_format_i18n( $impossible )
                );
            }
            if ( $extreme > 0 ) {
                $parts[] = $this->safe_sprintf(
                    /* translators: 1: number of days, 2: multiplier, 3: minimum event count. */
                    _n(
                        '%1$s day sits at least %2$s times above the normal for that same figure and counted at least %3$s events: the shape automated traffic leaves behind, though a real promotion can look like it too.',
                        '%1$s days sit at least %2$s times above the normal for that same figure and counted at least %3$s events: the shape automated traffic leaves behind, though a real promotion can look like it too.',
                        $extreme,
                        'brikpanel'
                    ),
                    number_format_i18n( $extreme ),
                    number_format_i18n( $scan['multiplier'] ),
                    number_format_i18n( $scan['floor'] )
                );
            }
            if ( $cartab > 0 ) {
                $parts[] = $this->safe_sprintf(
                    /* translators: %s: number of entries. */
                    _n(
                        '%s abandoned-cart entry was almost certainly written by a script (see the Abandoned Cart Entries check for the reasons).',
                        '%s abandoned-cart entries were almost certainly written by a script (see the Abandoned Cart Entries check for the reasons).',
                        $cartab,
                        'brikpanel'
                    ),
                    number_format_i18n( $cartab )
                );
            }
            $parts[] = __( 'The cleanup lowers each flagged day to the highest figure it could honestly have had, scales that day\'s traffic sources and device counts to match, deletes the flagged entries, and keeps everything it changed so one click puts it back.', 'brikpanel' );
            $result['message'] = implode( ' ', $parts );
        } else {
            $result['summary'] = __( 'No bot traffic found in your analytics history.', 'brikpanel' );
            $result['message'] = __( 'No day counted more add-to-carts than the store had visitors, no figure stands far above its own normal, and no abandoned-cart entry looks scripted.', 'brikpanel' );
        }

        if ( ! empty( $scan['truncated'] ) && $flagged > 0 ) {
            $result['message'] = trim(
                $result['message'] . ' ' . $this->safe_sprintf(
                    /* translators: %s: number of series. */
                    __( 'More products or pages are affected than one pass covers; these are the worst %s. Run the check again after cleaning them to see the rest.', 'brikpanel' ),
                    number_format_i18n( self::MAX_CANDIDATE_SERIES )
                )
            );
        }

        $result['recommendations'] = $this->build_recommendations( $total );

        $result['metadata'] = [
            'fixable'       => $total,
            'undoable'      => $undoable,
            'undo_at'       => $undo_at,
            'fix_confirm'   => $this->fix_confirm_text( $flagged, $overcount, $cartab ),
            'undo_confirm'  => $this->undo_confirm_text(),
            'stats'         => [
                [
                    'label' => __( 'Impossible days', 'brikpanel' ),
                    'value' => $impossible,
                    'tone'  => $impossible > 0 ? 'error' : 'good',
                ],
                [
                    'label' => __( 'Extreme days', 'brikpanel' ),
                    'value' => $extreme,
                    'tone'  => $extreme > 0 ? 'warn' : '',
                ],
                [
                    'label' => __( 'Scripted cart entries', 'brikpanel' ),
                    'value' => $cartab,
                    'tone'  => $cartab > 0 ? 'error' : '',
                ],
                [
                    'label' => __( 'Events to be removed', 'brikpanel' ),
                    'value' => $overcount,
                    'tone'  => $overcount > 0 ? 'warn' : '',
                ],
                [
                    'label' => __( 'Days scanned', 'brikpanel' ),
                    'value' => (int) $scan['window'],
                    'tone'  => '',
                ],
            ],
            'samples'       => $scan['samples'],
            'samples_title' => __( 'What would change', 'brikpanel' ),
            'samples_cols'  => [
                __( 'Date', 'brikpanel' ),
                __( 'What', 'brikpanel' ),
                __( 'Recorded', 'brikpanel' ),
                __( 'Corrected to', 'brikpanel' ),
                __( 'Why', 'brikpanel' ),
            ],
        ];

        $result['duration_ms'] = (int) round( ( microtime( true ) - $started ) * 1000 );

        return $result;
    }

    /**
     * @param int $flagged   Day/series pairs that would change.
     * @param int $overcount Recorded events that would disappear.
     * @param int $cartab    Abandoned-cart entries that would be deleted.
     * @return string
     */
    private function fix_confirm_text( $flagged, $overcount, $cartab ) {
        return $this->safe_sprintf(
            /* translators: 1: number of days, 2: number of events, 3: number of abandoned-cart entries. */
            __( 'Clean up bot traffic? This lowers %1$s day(s) of figures (about %2$s recorded events) and deletes %3$s abandoned-cart entries. Your products, orders and customers are not touched. Everything changed is kept in a restore point so you can undo it at any time.', 'brikpanel' ),
            number_format_i18n( (int) $flagged ),
            number_format_i18n( (int) $overcount ),
            number_format_i18n( (int) $cartab )
        );
    }

    /**
     * @return string
     */
    private function undo_confirm_text() {
        /* translators: {count} is replaced in the browser with the number of rows to restore. Keep it as is. */
        return __( 'Put the previous figures and deleted entries back? This restores {count} row(s) exactly as they were before the cleanup.', 'brikpanel' );
    }

    /**
     * @param int $total Flagged items.
     * @return array
     */
    private function build_recommendations( $total ) {
        if ( $total < 1 ) {
            return [];
        }
        return [
            [
                'text'     => __( 'Open the list below first. The cleanup rewrites your own analytics history, and a real campaign day can look like a robot day. You can undo it afterwards.', 'brikpanel' ),
                'priority' => 'high',
            ],
            [
                'text'     => __( 'If a known crawler is behind it, add its user agent or address under WooCommerce → Settings → BrikPanel → Analytics so it stops being counted at all.', 'brikpanel' ),
                'priority' => 'medium',
            ],
        ];
    }

    /**
     * Thresholds, filterable. The older filter names of the check this one
     * replaced are honoured first so a store that tuned them keeps its tuning.
     *
     * @return array{window:int,multiplier:float,floor:int,from:string}
     */
    private function settings() {
        $window = (int) apply_filters( 'brikpanel_cart_count_window_days', self::DEFAULT_WINDOW_DAYS );
        /**
         * Filters how many days of history the bot-traffic check examines.
         *
         * @since 3.3.11
         * @param int $window Days, clamped to 14..3650.
         */
        $window = (int) apply_filters( 'brikpanel_bot_traffic_window_days', $window );
        $window = max( 14, min( 3650, $window ) );

        $multiplier = (float) apply_filters( 'brikpanel_cart_count_outlier_multiplier', self::DEFAULT_MULTIPLIER );
        /**
         * Filters how many times above its own median a day must be to count as extreme.
         *
         * @since 3.3.11
         * @param float $multiplier At least 2.
         */
        $multiplier = max( 2, (float) apply_filters( 'brikpanel_bot_traffic_outlier_multiplier', $multiplier ) );

        $floor = (int) apply_filters( 'brikpanel_cart_count_outlier_floor', self::DEFAULT_FLOOR );
        /**
         * Filters the smallest daily figure the extreme rule can fire on.
         *
         * @since 3.3.11
         * @param int $floor At least 5.
         */
        $floor = max( 5, (int) apply_filters( 'brikpanel_bot_traffic_outlier_floor', $floor ) );

        // Window bounds are built in the site's timezone: the counters write
        // local dates, so a UTC-derived boundary would slice the wrong day off.
        $from = wp_date( 'Y-m-d', strtotime( '-' . $window . ' days', (int) current_time( 'timestamp' ) ) );

        return [ 'window' => $window, 'multiplier' => $multiplier, 'floor' => $floor, 'from' => $from ];
    }

    /**
     * Examine every table and decide what is wrong.
     *
     * @return array|null Null when nothing has been recorded yet.
     */
    private function scan() {
        $findings = $this->scan_findings( $truncated );
        $cartab   = $this->count_cartab();

        if ( null === $findings ) {
            return null;
        }

        $s = $this->settings();

        $impossible = 0;
        $extreme    = 0;
        $overcount  = 0;
        foreach ( $findings as $finding ) {
            if ( 'impossible' === $finding['rule'] ) {
                $impossible++;
            } else {
                $extreme++;
            }
            $overcount += max( 0, $finding['current'] - $finding['target'] );
        }

        return [
            'impossible' => $impossible,
            'extreme'    => $extreme,
            'cartab'     => $cartab,
            'overcount'  => $overcount,
            'window'     => $s['window'],
            'multiplier' => $s['multiplier'],
            'floor'      => $s['floor'],
            'truncated'  => $truncated,
            'samples'    => $this->build_samples( $findings, $cartab ),
        ];
    }

    /**
     * Findings only, worst first, without the presentation work run() does
     * around them. Shared by the scan and the repair, so a repair never acts
     * on a picture of the data that is hours old.
     *
     * @param bool $truncated Out: whether a candidate list was cut short.
     * @return array|null Null when none of the tables exist.
     */
    private function scan_findings( &$truncated = false ) {
        global $wpdb;

        $truncated = false;

        $visitors = $wpdb->prefix . 'brikpanel_visitors';
        $cart     = $wpdb->prefix . 'brikpanel_cart_tracking';
        $pages    = $wpdb->prefix . 'brikpanel_visited_pages';

        $has_visitors = $this->table_exists( $visitors );
        $has_cart     = $this->table_exists( $cart );
        $has_pages    = $this->table_exists( $pages );

        if ( ! $has_visitors && ! $has_cart && ! $has_pages ) {
            return null;
        }

        $s     = $this->settings();
        $daily = $has_visitors ? $this->load_visitor_days( $visitors, $s['from'] ) : [];
        $this->prime_visitor_credibility( $daily );

        $findings = [];
        if ( $has_visitors ) {
            $findings = array_merge( $findings, $this->find_in_visitors( $daily, $s['multiplier'], $s['floor'] ) );
        }
        if ( $has_cart ) {
            $part      = $this->find_in_series( 'cart', $cart, 'product_id', 'cart_count', $s, $daily );
            $findings  = array_merge( $findings, $part['findings'] );
            $truncated = $truncated || $part['truncated'];
        }
        if ( $has_pages ) {
            $part      = $this->find_in_series( 'page', $pages, 'page_id', 'visit_count', $s, [] );
            $findings  = array_merge( $findings, $part['findings'] );
            $truncated = $truncated || $part['truncated'];
        }

        usort(
            $findings,
            static function ( $a, $b ) {
                return ( $b['current'] - $b['target'] ) <=> ( $a['current'] - $a['target'] );
            }
        );

        return $findings;
    }

    /**
     * Daily store-wide figures, keyed by local date.
     *
     * @param string $table Fully prefixed visitors table.
     * @param string $from  Inclusive lower bound, Y-m-d.
     * @return array<string, array>
     */
    private function load_visitor_days( $table, $from ) {
        global $wpdb;

        // A day can hold more than one row after a race in the counter, and
        // every report sums them, so the day is judged and corrected as a sum.
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- table name is internal; the bound value is prepared.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT date_column, MIN(id) AS id,
                        SUM(visitor_count) AS visitor_count, SUM(product_count) AS product_count,
                        SUM(add_to_cart_count) AS add_to_cart_count, SUM(checkout_count) AS checkout_count
                   FROM {$table}
                  WHERE date_column >= %s
                  GROUP BY date_column
                  ORDER BY date_column ASC",
                $from
            ),
            ARRAY_A
        );

        $out = [];
        foreach ( (array) $rows as $row ) {
            $out[ (string) $row['date_column'] ] = [
                'id'       => (int) $row['id'],
                'visitors' => (int) $row['visitor_count'],
                'product'  => (int) $row['product_count'],
                'atc'      => (int) $row['add_to_cart_count'],
                'checkout' => (int) $row['checkout_count'],
            ];
        }

        return $out;
    }

    /**
     * Findings in the store-wide daily table.
     *
     * @param array $daily      Output of load_visitor_days().
     * @param float $multiplier Extreme-rule multiplier.
     * @param int   $floor      Extreme-rule absolute floor.
     * @return array
     */
    private function find_in_visitors( array $daily, $multiplier, $floor ) {
        $findings = [];

        $series = [
            'visitors' => [ 'col' => 'visitor_count',     'label' => __( 'Store visitors', 'brikpanel' ) ],
            'product'  => [ 'col' => 'product_count',     'label' => __( 'Product views', 'brikpanel' ) ],
            'atc'      => [ 'col' => 'add_to_cart_count', 'label' => __( 'Store add-to-carts', 'brikpanel' ) ],
            'checkout' => [ 'col' => 'checkout_count',    'label' => __( 'Checkout visits', 'brikpanel' ) ],
        ];

        $medians = [];
        foreach ( $series as $key => $meta ) {
            $values = [];
            foreach ( $daily as $day ) {
                $values[] = $day[ $key ];
            }
            $medians[ $key ] = $this->median( $values );
        }
        $enough_history = count( $daily ) >= self::MIN_BASELINE_DAYS;

        foreach ( $daily as $date => $day ) {
            foreach ( $series as $key => $meta ) {
                $normal = ( $enough_history && $this->is_extreme( $day[ $key ], $medians[ $key ], $multiplier, $floor ) )
                    ? (int) round( $medians[ $key ] )
                    : null;

                // The funnel says a visit precedes an add. No such invariant
                // for product views (once per visitor, but a different day
                // boundary is possible) or checkout (a cart survives the day it
                // was filled, and recovery links reach checkout directly).
                $ceiling = ( 'atc' === $key ) ? $this->visitor_ceiling( $day['visitors'], $day['atc'] ) : null;

                $finding = $this->weigh(
                    'visitors', $day['id'], $meta['col'], $date, $meta['label'],
                    $day[ $key ], $ceiling, $normal,
                    __( 'More adds than visitors', 'brikpanel' )
                );
                if ( $finding ) {
                    $findings[] = $finding;
                }
            }
        }

        return $findings;
    }

    /**
     * Findings in a per-series table (one row per product or page per hit
     * bucket): the per-product cart table and the visited-pages table.
     *
     * Two passes on purpose. The first asks the database which series/day
     * pairs are even eligible (HAVING on the smallest figure either rule could
     * fire on), the second reads the full daily history of only those series,
     * in chunks, because the median needs the whole series and nothing else.
     *
     * @param string $source 'cart' or 'page'.
     * @param string $table  Fully prefixed table.
     * @param string $id_col Series column.
     * @param string $val_col Value column.
     * @param array  $s      settings().
     * @param array  $daily  Store-wide days (for the cart ceiling), or [].
     * @return array{findings:array,truncated:bool}
     */
    private function find_in_series( $source, $table, $id_col, $val_col, array $s, array $daily ) {
        global $wpdb;

        if ( 'page' === $source ) {
            $s['multiplier'] *= self::PAGE_MULTIPLIER_FACTOR;
            $s['floor']      *= self::PAGE_FLOOR_FACTOR;
        }

        $threshold = max(
            1,
            (int) min(
                $s['floor'],
                (int) floor( self::MIN_CREDIBLE_VISITORS * self::CEILING_TOLERANCE ) + 1
            )
        );

        // One row per qualifying series, worst first. Reading one extra row is
        // how we learn the list was cut short without a second COUNT query.
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- table and columns are internal; bound values are prepared.
        $ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT {$id_col}
                   FROM (
                        SELECT {$id_col}, MAX(daily) AS worst
                          FROM (
                                SELECT {$id_col}, DATE(date_column) AS day, SUM({$val_col}) AS daily
                                  FROM {$table}
                                 WHERE date_column >= %s
                                 GROUP BY {$id_col}, DATE(date_column)
                                HAVING daily >= %d
                               ) d
                         GROUP BY {$id_col}
                        ) p
                  ORDER BY worst DESC
                  LIMIT %d",
                $s['from'] . ' 00:00:00',
                $threshold,
                self::MAX_CANDIDATE_SERIES + 1
            )
        );

        $ids       = array_values( array_filter( array_map( 'absint', (array) $ids ) ) );
        $truncated = count( $ids ) > self::MAX_CANDIDATE_SERIES;
        if ( $truncated ) {
            $ids = array_slice( $ids, 0, self::MAX_CANDIDATE_SERIES );
        }
        if ( empty( $ids ) ) {
            return [ 'findings' => [], 'truncated' => false ];
        }

        $findings = [];
        foreach ( array_chunk( $ids, self::SERIES_CHUNK ) as $chunk ) {
            $list = implode( ',', array_map( 'absint', $chunk ) );

            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- internal names, absint()-mapped list, bound value prepared.
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT {$id_col} AS sid, DATE(date_column) AS day, SUM({$val_col}) AS total
                       FROM {$table}
                      WHERE {$id_col} IN ({$list})
                        AND date_column >= %s
                      GROUP BY {$id_col}, DATE(date_column)",
                    $s['from'] . ' 00:00:00'
                ),
                ARRAY_A
            );

            $series = [];
            foreach ( (array) $rows as $row ) {
                $series[ (int) $row['sid'] ][ (string) $row['day'] ] = (int) $row['total'];
            }

            foreach ( $series as $sid => $days ) {
                $median         = $this->median( array_values( $days ) );
                $enough_history = count( $days ) >= self::MIN_BASELINE_DAYS;

                foreach ( $days as $date => $total ) {
                    $ceiling = null;
                    if ( 'cart' === $source ) {
                        // One product cannot be added by more people than
                        // visited the whole store that day.
                        $visitors = isset( $daily[ $date ] ) ? (int) $daily[ $date ]['visitors'] : 0;
                        $ceiling  = $this->visitor_ceiling( $visitors, $total );
                    }
                    $normal = ( $enough_history && $this->is_extreme( $total, $median, $s['multiplier'], $s['floor'] ) )
                        ? (int) round( $median )
                        : null;

                    $finding = $this->weigh(
                        $source, $sid, $val_col, $date, '',
                        $total, $ceiling, $normal,
                        __( 'More adds than store visitors', 'brikpanel' )
                    );
                    if ( $finding ) {
                        $findings[] = $finding;
                    }
                }
            }
        }

        return [ 'findings' => $findings, 'truncated' => $truncated ];
    }

    /**
     * Abandoned-cart entries the sibling check rates as certainly scripted.
     *
     * @return int
     */
    private function count_cartab() {
        if ( ! class_exists( 'Brikpanel_BrikControl_Cartab_Bot_Rows_Check' ) ) {
            return 0;
        }
        $rows = Brikpanel_BrikControl_Cartab_Bot_Rows_Check::certain_rows( 0 );
        return is_array( $rows ) ? (int) $rows['total'] : 0;
    }

    /**
     * Decide once whether this store's visitor figures can be used as evidence.
     *
     * @param array $daily Output of load_visitor_days().
     * @return void
     */
    private function prime_visitor_credibility( array $daily ) {
        $counts = [];
        foreach ( $daily as $day ) {
            $counts[] = (int) $day['visitors'];
        }
        $this->visitors_credible = $this->median( $counts ) >= self::MIN_CREDIBLE_VISITORS;
    }

    /**
     * The ceiling a day's visitor count puts on an add-to-cart figure.
     *
     * @param int $visitors Visitors recorded that day.
     * @param int $value    Figure being judged.
     * @return int|null Null when the visitor figure proves nothing.
     */
    private function visitor_ceiling( $visitors, $value ) {
        $visitors = (int) $visitors;
        if ( ! $this->visitors_credible || $visitors < self::MIN_CREDIBLE_VISITORS ) {
            return null;
        }
        return ( (float) $value > $visitors * self::CEILING_TOLERANCE ) ? $visitors : null;
    }

    /**
     * Decide whether a day is wrong and, if so, what it should say instead.
     * When both rules fire, the tighter answer wins and the day is labelled by
     * the strongest claim that applies.
     *
     * @return array|null Null when the day is fine.
     */
    private function weigh( $source, $ref, $column, $date, $label, $current, $ceiling, $normal, $ceiling_reason ) {
        if ( null === $ceiling && null === $normal ) {
            return null;
        }

        $candidates = [];
        if ( null !== $ceiling ) {
            $candidates[] = (int) $ceiling;
        }
        if ( null !== $normal ) {
            $candidates[] = (int) $normal;
        }

        $target = (int) max( 0, min( (int) $current, min( $candidates ) ) );
        if ( $target >= (int) $current ) {
            return null;
        }

        return [
            'source'  => $source,
            'ref'     => (int) $ref,
            'column'  => $column,
            'date'    => (string) $date,
            'label'   => (string) $label,
            'current' => (int) $current,
            // Never raise a figure, whatever the arithmetic says.
            'target'  => $target,
            'rule'    => ( null !== $ceiling ) ? 'impossible' : 'extreme',
            'reason'  => ( null !== $ceiling ) ? $ceiling_reason : __( 'Far above its own normal', 'brikpanel' ),
        ];
    }

    /**
     * Is this value far enough above the series' own normal to be suspect?
     */
    private function is_extreme( $value, $median, $multiplier, $floor ) {
        $value = (int) $value;
        if ( $value < $floor ) {
            return false;
        }
        // A median of zero means the series is normally empty; the absolute
        // floor alone then decides, which is why the floor exists.
        return $value >= max( 1.0, (float) $median ) * (float) $multiplier;
    }

    /**
     * Median of a list of integers. The mean would be pulled up by the very
     * days being hunted.
     *
     * @param int[] $values
     * @return float
     */
    private function median( array $values ) {
        $values = array_values( array_map( 'intval', $values ) );
        $count  = count( $values );
        if ( 0 === $count ) {
            return 0.0;
        }
        sort( $values, SORT_NUMERIC );
        $middle = intdiv( $count, 2 );
        return ( 0 === $count % 2 )
            ? ( ( $values[ $middle - 1 ] + $values[ $middle ] ) / 2 )
            : (float) $values[ $middle ];
    }

    /**
     * Readable name for a product or page row, falling back to its id.
     */
    private function object_label( $source, $id ) {
        $id    = (int) $id;
        $title = get_the_title( $id );
        if ( is_string( $title ) && '' !== trim( $title ) ) {
            return html_entity_decode( wp_strip_all_tags( $title ), ENT_QUOTES, get_bloginfo( 'charset' ) );
        }
        if ( 'page' === $source ) {
            $term = get_term( $id );
            if ( $term instanceof WP_Term ) {
                return $term->name;
            }
            return $this->safe_sprintf(
                /* translators: %s: page ID. */
                __( 'Page #%s', 'brikpanel' ),
                number_format_i18n( $id )
            );
        }
        return $this->safe_sprintf(
            /* translators: %s: product ID of a product that no longer exists. */
            __( 'Product #%s', 'brikpanel' ),
            number_format_i18n( $id )
        );
    }

    /**
     * Detail rows for the card table.
     *
     * @param array $findings Sorted findings.
     * @param int   $cartab   Scripted abandoned-cart entries.
     * @return array
     */
    private function build_samples( array $findings, $cartab ) {
        $shown = array_slice( $findings, 0, self::SAMPLE_LIMIT );

        $ids = [];
        foreach ( $shown as $finding ) {
            if ( 'visitors' !== $finding['source'] ) {
                $ids[] = (int) $finding['ref'];
            }
        }
        if ( ! empty( $ids ) ) {
            _prime_post_caches( array_values( array_unique( $ids ) ), false, false );
        }

        $samples = [];
        if ( $cartab > 0 ) {
            $samples[] = [
                '',
                __( 'Abandoned-cart entries written by scripts', 'brikpanel' ),
                number_format_i18n( $cartab ),
                __( 'deleted', 'brikpanel' ),
                __( 'Certain by the Abandoned Cart Entries check', 'brikpanel' ),
            ];
        }
        foreach ( $shown as $finding ) {
            switch ( $finding['source'] ) {
                case 'cart':
                    $label = $this->safe_sprintf(
                        /* translators: %s: product name. */
                        __( 'Add-to-carts: %s', 'brikpanel' ),
                        $this->object_label( 'cart', $finding['ref'] )
                    );
                    break;
                case 'page':
                    $label = $this->safe_sprintf(
                        /* translators: %s: page or product name. */
                        __( 'Page views: %s', 'brikpanel' ),
                        $this->object_label( 'page', $finding['ref'] )
                    );
                    break;
                default:
                    $label = $finding['label'];
            }
            $samples[] = [
                $finding['date'],
                $label,
                number_format_i18n( $finding['current'] ),
                number_format_i18n( $finding['target'] ),
                $finding['reason'],
            ];
        }

        return $samples;
    }

    /* ---------------------------------------------------------------------
     * Fix
     * ------------------------------------------------------------------ */

    /**
     * Lower every flagged day to the highest value it could honestly have had
     * and delete every certainly scripted abandoned-cart entry, keeping a
     * copy of everything first.
     *
     * @param array $args Unused.
     * @return array { removed: int, has_more: bool, message: string }
     */
    public function run_fix( array $args = [] ) {
        global $wpdb;

        // Fix and undo use separate locks in the framework, but for this check
        // they must not overlap: an undo deleting the restore point while a
        // cleanup appends to it would lose the new rows for good.
        if ( get_transient( 'brikpanel_bc_undo_' . $this->get_id() ) ) {
            return [
                'removed'  => 0,
                'has_more' => true,
                'message'  => __( 'An undo is running for this check. Try again in a moment.', 'brikpanel' ),
            ];
        }

        $findings = (array) $this->scan_findings();
        $has_more = count( $findings ) > self::FIX_CHUNK;
        $batch    = array_slice( $findings, 0, self::FIX_CHUNK );

        $removed = 0;
        $full    = false;

        // 1) Figures. Backup entries are collected per finding and written
        //    before the row changes, so a request that dies between the two
        //    leaves a restore point for values that are still in place, which
        //    undo replays harmlessly.
        foreach ( $batch as $finding ) {
            $planned = ( 'visitors' === $finding['source'] )
                ? $this->plan_visitor_day( $finding )
                : $this->plan_series_day( $finding );

            if ( empty( $planned ) ) {
                continue;
            }
            if ( ! $this->room_for( count( $planned ) ) ) {
                $full     = true;
                $has_more = true;
                break;
            }
            if ( ! $this->append_backup( $planned ) ) {
                return [
                    'removed'  => $removed,
                    'has_more' => true,
                    'message'  => __( 'The restore point could not be written, so nothing more was changed.', 'brikpanel' ),
                ];
            }
            foreach ( $planned as $entry ) {
                $this->apply_value( $entry );
            }
            $removed += max( 0, $finding['current'] - $finding['target'] );
        }

        // 2) Abandoned-cart entries: whole rows, copied before deletion.
        if ( ! $full && class_exists( 'Brikpanel_BrikControl_Cartab_Bot_Rows_Check' ) ) {
            $certain = Brikpanel_BrikControl_Cartab_Bot_Rows_Check::certain_rows( self::CARTAB_CHUNK + 1 );
            $ids     = is_array( $certain ) ? array_map( 'intval', (array) $certain['ids'] ) : [];
            if ( count( $ids ) > self::CARTAB_CHUNK ) {
                $has_more = true;
                $ids      = array_slice( $ids, 0, self::CARTAB_CHUNK );
            }
            if ( ! empty( $ids ) ) {
                if ( ! $this->room_for( count( $ids ) ) ) {
                    $full     = true;
                    $has_more = true;
                } else {
                    $deleted  = $this->delete_cartab_rows( $ids );
                    $removed += $deleted;
                }
            }
        }

        // The Live view is a 2-minute transient of whoever pinged last; a
        // crawl that is still running simply refills it, and one that stopped
        // should not linger.
        delete_transient( 'brikpanel_live_visitors' );
        if ( function_exists( 'brikpanel_bust_data_caches' ) ) {
            brikpanel_bust_data_caches();
        }

        return [
            'removed'  => $removed,
            'has_more' => $has_more,
            'message'  => $full
                ? __( 'The restore point is full. Undo the last cleanup or leave it in place, then run the check again.', 'brikpanel' )
                : '',
        ];
    }

    /**
     * Backup entries for one store-wide column on one day, plus the cascade a
     * lowered visitor figure implies: that day's device counts and
     * traffic-source hits are scaled by the same ratio so the cards that break
     * visitors down keep adding up.
     *
     * A day can span several rows; the correction lands on the first and the
     * rest are zeroed, exactly as the per-series tables are handled.
     *
     * @param array $finding
     * @return array Backup entries (not yet written).
     */
    private function plan_visitor_day( array $finding ) {
        global $wpdb;

        $table  = $wpdb->prefix . 'brikpanel_visitors';
        $column = (string) $finding['column'];
        $date   = (string) $finding['date'];

        if ( ! in_array( $column, self::UNDO_COLUMNS['v'], true ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
            return [];
        }

        // Re-read under the same request rather than trusting the scan's copy.
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- internal whitelisted names; bound value prepared.
        $rows = $wpdb->get_results(
            $wpdb->prepare( "SELECT * FROM {$table} WHERE date_column = %s ORDER BY id ASC", $date ),
            ARRAY_A
        );
        if ( empty( $rows ) ) {
            return [];
        }

        $current = 0;
        foreach ( $rows as $row ) {
            $current += (int) $row[ $column ];
        }
        $target = (int) $finding['target'];
        if ( $current <= $target ) {
            return [];
        }

        $planned = [];
        foreach ( $rows as $index => $row ) {
            $old = (int) $row[ $column ];
            $new = ( 0 === $index ) ? $target : 0;
            if ( $old !== $new ) {
                $planned[] = [ 't' => 'v', 'id' => (int) $row['id'], 'col' => $column, 'old' => $old, 'new' => $new ];
            }
        }

        if ( 'visitor_count' !== $column ) {
            return $planned;
        }

        $ratio = $target / max( 1, $current );

        foreach ( $rows as $row ) {
            foreach ( [ 'mobile_count', 'tablet_count', 'desktop_count' ] as $device ) {
                $old = (int) $row[ $device ];
                $new = (int) floor( $old * $ratio );
                if ( $old !== $new ) {
                    $planned[] = [ 't' => 'v', 'id' => (int) $row['id'], 'col' => $device, 'old' => $old, 'new' => $new ];
                }
            }
        }

        $referrers = $wpdb->prefix . 'brikpanel_referrers';
        if ( $this->table_exists( $referrers ) ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- internal table; bound value prepared.
            $hits = $wpdb->get_results(
                $wpdb->prepare( "SELECT id, hits FROM {$referrers} WHERE date_column = %s", $date ),
                ARRAY_A
            );
            foreach ( (array) $hits as $hit ) {
                $old = (int) $hit['hits'];
                $new = (int) floor( $old * $ratio );
                if ( $old !== $new ) {
                    $planned[] = [ 't' => 'r', 'id' => (int) $hit['id'], 'col' => 'hits', 'old' => $old, 'new' => $new ];
                }
            }
        }

        return $planned;
    }

    /**
     * Backup entries for one product/day or page/day. A day can span several
     * rows, so the correction lands on the first row and the rest are zeroed.
     *
     * @param array $finding
     * @return array Backup entries (not yet written).
     */
    private function plan_series_day( array $finding ) {
        global $wpdb;

        $is_cart = ( 'cart' === $finding['source'] );
        $table   = $wpdb->prefix . ( $is_cart ? 'brikpanel_cart_tracking' : 'brikpanel_visited_pages' );
        $id_col  = $is_cart ? 'product_id' : 'page_id';
        $val_col = $is_cart ? 'cart_count' : 'visit_count';
        $type    = $is_cart ? 'c' : 'p';
        $sid     = (int) $finding['ref'];
        $date    = (string) $finding['date'];

        if ( $sid <= 0 || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
            return [];
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- internal names; bound values prepared.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, {$val_col} AS v
                   FROM {$table}
                  WHERE {$id_col} = %d
                    AND date_column BETWEEN %s AND %s
                  ORDER BY id ASC",
                $sid,
                $date . ' 00:00:00',
                $date . ' 23:59:59'
            ),
            ARRAY_A
        );
        if ( empty( $rows ) ) {
            return [];
        }

        $planned   = [];
        $remaining = (int) $finding['target'];
        foreach ( $rows as $index => $row ) {
            $old = (int) $row['v'];
            $new = ( 0 === $index ) ? $remaining : 0;
            if ( $old !== $new ) {
                $planned[] = [ 't' => $type, 'id' => (int) $row['id'], 'col' => $val_col, 'old' => $old, 'new' => $new ];
            }
        }

        return $planned;
    }

    /**
     * Write one planned value. The column is re-checked against the whitelist
     * because it reaches $wpdb->update() as a key.
     *
     * @param array $entry Backup entry.
     * @return bool
     */
    private function apply_value( array $entry ) {
        $table = $this->table_for( $entry['t'] );
        if ( '' === $table || ! in_array( $entry['col'], self::UNDO_COLUMNS[ $entry['t'] ] ?? [], true ) ) {
            return false;
        }
        global $wpdb;
        return (bool) $wpdb->update(
            $table,
            [ $entry['col'] => (int) $entry['new'] ],
            [ 'id' => (int) $entry['id'] ],
            [ '%d' ],
            [ '%d' ]
        );
    }

    /**
     * Copy, then delete, abandoned-cart entries.
     *
     * @param int[] $ids
     * @return int Rows deleted.
     */
    private function delete_cartab_rows( array $ids ) {
        global $wpdb;

        $table = $wpdb->prefix . 'brikpanel_abandoned_carts';
        $list  = implode( ',', array_map( 'absint', $ids ) );
        if ( '' === $list ) {
            return 0;
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- absint id list.
        $rows = $wpdb->get_results( "SELECT * FROM {$table} WHERE id IN ({$list})", ARRAY_A );
        if ( empty( $rows ) ) {
            return 0;
        }

        $planned = [];
        $emails  = [];
        foreach ( $rows as $row ) {
            $planned[] = [ 't' => 'a', 'id' => (int) $row['id'], 'row' => $row ];
            if ( '' !== (string) $row['email'] ) {
                $emails[] = (string) $row['email'];
            }
        }

        // Backup BEFORE deleting, and abort when it could not be written: a
        // deleted entry with no copy is a recovery email that never goes out.
        if ( ! $this->append_backup( $planned ) ) {
            return 0;
        }

        $kept = array_map( static function ( $r ) { return (int) $r['id']; }, $rows );
        $in   = implode( ',', $kept );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- absint id list.
        $deleted = (int) $wpdb->query( "DELETE FROM {$table} WHERE id IN ({$in})" );

        if ( $deleted > 0 ) {
            /**
             * Fires after scripted abandoned-cart entries were deleted by the
             * Store Health cleanup, so companion plugins can drop what they
             * built from them (queued reminders, contacts).
             *
             * @since 3.3.11
             *
             * @param int[]    $ids    Deleted entry ids.
             * @param string[] $emails Their email addresses.
             */
            do_action( 'brikpanel_cartab_entries_deleted', $kept, array_values( array_unique( $emails ) ) );
        }

        return max( 0, $deleted );
    }

    /* ---------------------------------------------------------------------
     * Undo
     * ------------------------------------------------------------------ */

    /**
     * Put every backed-up value and every deleted entry back exactly as it
     * was, newest chunk first, then replay the restore point of the check
     * this one replaced.
     *
     * @return array { restored: int, message: string }
     */
    public function run_undo() {
        if ( get_transient( 'brikpanel_bc_fix_' . $this->get_id() ) ) {
            return [ 'restored' => 0, 'message' => __( 'A cleanup is running for this check. Try again in a moment.', 'brikpanel' ) ];
        }

        $restored  = 0;
        $restored += $this->replay( self::BACKUP_OPTION, self::BACKUP_CHUNK_PREFIX );
        $restored += $this->replay( self::LEGACY_BACKUP_OPTION, self::LEGACY_CHUNK_PREFIX );

        if ( function_exists( 'brikpanel_bust_data_caches' ) ) {
            brikpanel_bust_data_caches();
        }

        if ( $restored < 1 ) {
            return [ 'restored' => 0, 'message' => __( 'There is nothing to undo.', 'brikpanel' ) ];
        }

        return [ 'restored' => $restored, 'message' => '' ];
    }

    /**
     * Replay one restore point and remove it.
     *
     * @param string $option Index option.
     * @param string $prefix Chunk prefix.
     * @return int Rows restored.
     */
    private function replay( $option, $prefix ) {
        global $wpdb;

        $index = $this->read_index( $option );
        if ( $index['count'] < 1 && $index['chunks'] < 1 ) {
            return 0;
        }

        $restored     = 0;
        $restored_ids = [];

        for ( $i = $index['chunks'] - 1; $i >= 0; $i-- ) {
            $rows = (array) get_option( $prefix . $i, [] );

            foreach ( $rows as $row ) {
                $type = (string) ( $row['t'] ?? '' );

                if ( 'a' === $type ) {
                    $data = isset( $row['row'] ) && is_array( $row['row'] ) ? $row['row'] : [];
                    if ( empty( $data['id'] ) ) {
                        continue;
                    }
                    // Same id, so anything keyed on it (BrikMentor queues,
                    // links in emails already sent) points at the entry again.
                    // INSERT IGNORE: an entry that was never actually deleted,
                    // or has since been recreated, is left alone.
                    $cols = array_map( static function ( $c ) { return '`' . str_replace( '`', '', $c ) . '`'; }, array_keys( $data ) );
                    $vals = [];
                    $args = [];
                    foreach ( $data as $value ) {
                        if ( null === $value ) {
                            $vals[] = 'NULL';
                        } else {
                            $vals[] = '%s';
                            $args[] = (string) $value;
                        }
                    }
                    $table = $wpdb->prefix . 'brikpanel_abandoned_carts';
                    $sql   = "INSERT IGNORE INTO {$table} (" . implode( ',', $cols ) . ') VALUES (' . implode( ',', $vals ) . ')';
                    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- column names come from our own backup of our own table; values are placeholders.
                    $done = $wpdb->query( empty( $args ) ? $sql : $wpdb->prepare( $sql, $args ) );
                    if ( $done ) {
                        $restored++;
                        $restored_ids[] = (int) $data['id'];
                    }
                    continue;
                }

                $table  = $this->table_for( $type );
                $column = (string) ( $row['col'] ?? '' );
                if ( '' === $table || ! in_array( $column, self::UNDO_COLUMNS[ $type ] ?? [], true ) ) {
                    continue;
                }
                $updated = $wpdb->update(
                    $table,
                    [ $column => (int) ( $row['old'] ?? 0 ) ],
                    [ 'id' => (int) ( $row['id'] ?? 0 ) ],
                    [ '%d' ],
                    [ '%d' ]
                );
                if ( $updated ) {
                    $restored++;
                }
            }

            delete_option( $prefix . $i );

            // Shrink the index as each chunk lands, so a run cut short by a
            // timeout leaves a restore point that still describes exactly what
            // is left rather than one that promises rows already replayed.
            $index['count'] = max( 0, $index['count'] - count( $rows ) );
            update_option( $option, [ 'time' => $index['time'], 'count' => $index['count'], 'chunks' => $i ], false );
        }

        // Cleared whatever the outcome: a half-applied restore point replayed
        // twice would be worse than none.
        delete_option( $option );
        $this->purge_chunks( $prefix, 0 );

        if ( ! empty( $restored_ids ) ) {
            /**
             * Fires after deleted abandoned-cart entries were put back by an
             * undo of the Store Health cleanup.
             *
             * @since 3.3.11
             *
             * @param int[] $ids Restored entry ids.
             */
            do_action( 'brikpanel_cartab_entries_restored', $restored_ids );
        }

        return $restored;
    }

    /* ---------------------------------------------------------------------
     * Restore point
     * ------------------------------------------------------------------ */

    /**
     * Whether the restore point can take this many more rows.
     */
    private function room_for( $rows ) {
        $index = $this->read_backup_index();
        return ( $index['count'] + (int) $rows ) <= self::MAX_BACKUP_ROWS;
    }

    /**
     * @param string $type Backup row type.
     * @return string Fully prefixed table, or '' for an unknown type.
     */
    private function table_for( $type ) {
        global $wpdb;
        switch ( $type ) {
            case 'v':
                return $wpdb->prefix . 'brikpanel_visitors';
            case 'c':
                return $wpdb->prefix . 'brikpanel_cart_tracking';
            case 'r':
                return $wpdb->prefix . 'brikpanel_referrers';
            case 'p':
                return $wpdb->prefix . 'brikpanel_visited_pages';
        }
        return '';
    }

    /**
     * The restore point's index. Deliberately tiny: every scan reads it to
     * decide whether to offer the undo button.
     *
     * @return array{time:int,count:int,chunks:int}
     */
    private function read_backup_index() {
        return $this->read_index( self::BACKUP_OPTION );
    }

    /**
     * @param string $option Index option name.
     * @return array{time:int,count:int,chunks:int}
     */
    private function read_index( $option ) {
        $stored = get_option( $option, [] );
        if ( ! is_array( $stored ) ) {
            return [ 'time' => 0, 'count' => 0, 'chunks' => 0 ];
        }
        return [
            'time'   => isset( $stored['time'] ) ? (int) $stored['time'] : 0,
            'count'  => isset( $stored['count'] ) ? max( 0, (int) $stored['count'] ) : 0,
            'chunks' => isset( $stored['chunks'] ) ? max( 0, (int) $stored['chunks'] ) : 0,
        ];
    }

    /**
     * Add rows to the restore point, spilling into a new chunk when full.
     * Returns false when the rows could not be verified on disk, in which
     * case the caller must not change anything.
     *
     * @param array $rows Backup entries.
     * @return bool
     */
    private function append_backup( array $rows ) {
        if ( empty( $rows ) ) {
            return true;
        }

        $index = $this->read_backup_index();

        // A restore point that reports nothing to restore is starting fresh.
        // Sweep first: an undo interrupted by a timeout can leave chunk options
        // behind, and appending on top of them would mix two cleanups.
        if ( $index['count'] < 1 ) {
            $this->purge_chunks( self::BACKUP_CHUNK_PREFIX, $index['chunks'] );
            $index['chunks'] = 0;
        }

        // Top up the last chunk before opening another.
        $chunk_index = max( 0, $index['chunks'] - 1 );
        $current     = ( $index['chunks'] > 0 )
            ? (array) get_option( self::BACKUP_CHUNK_PREFIX . $chunk_index, [] )
            : [];

        $written = [];
        foreach ( $rows as $row ) {
            if ( count( $current ) >= self::BACKUP_CHUNK_ROWS ) {
                // Never autoloaded: read only when the merchant undoes.
                update_option( self::BACKUP_CHUNK_PREFIX . $chunk_index, $current, false );
                $written[] = $chunk_index;
                $chunk_index++;
                $current = [];
            }
            $current[] = $row;
        }
        update_option( self::BACKUP_CHUNK_PREFIX . $chunk_index, $current, false );
        $written[] = $chunk_index;

        // Verify the last chunk reads back with the rows in it before anything
        // is changed on the strength of it.
        wp_cache_delete( self::BACKUP_CHUNK_PREFIX . $chunk_index, 'options' );
        $check = get_option( self::BACKUP_CHUNK_PREFIX . $chunk_index, null );
        if ( ! is_array( $check ) || count( $check ) !== count( $current ) ) {
            return false;
        }

        update_option(
            self::BACKUP_OPTION,
            [
                'time'   => time(),
                'count'  => $index['count'] + count( $rows ),
                'chunks' => $chunk_index + 1,
            ],
            false
        );

        return true;
    }

    /**
     * Delete leftover restore-point chunks.
     *
     * @param string $prefix Chunk prefix.
     * @param int    $known  How many chunks the index claimed.
     * @return void
     */
    private function purge_chunks( $prefix, $known ) {
        global $wpdb;

        for ( $i = 0; $i < max( 0, (int) $known ); $i++ ) {
            delete_option( $prefix . $i );
        }

        // Belt and braces: a chunk the index never knew about (a crash between
        // writing a chunk and writing the index) would otherwise sit in
        // wp_options forever.
        $stale = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
                $wpdb->esc_like( $prefix ) . '%'
            )
        );
        foreach ( (array) $stale as $name ) {
            delete_option( $name );
        }
    }

    /* ---------------------------------------------------------------------
     * Helpers
     * ------------------------------------------------------------------ */

    /**
     * Does the table exist? Memoised per request.
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

    /**
     * sprintf() that cannot be brought down by a bad translation: a translator
     * who drops a placeholder would otherwise throw inside the scan worker.
     *
     * @param string $format Translated format string.
     * @param mixed  ...$args printf arguments.
     * @return string
     */
    private function safe_sprintf( $format, ...$args ) {
        $format = (string) $format;
        try {
            $out = @vsprintf( $format, $args );
            if ( false !== $out ) {
                return $out;
            }
        } catch ( \Throwable $e ) {
            unset( $e );
        }
        $stripped = preg_replace( '/%(?:\d+\$)?[-+ 0#\']*\d*(?:\.\d+)?[bcdeEfFgGosuxX]/', '', $format );
        $stripped = str_replace( '%%', '%', (string) $stripped );
        return trim( (string) $stripped );
    }
}
