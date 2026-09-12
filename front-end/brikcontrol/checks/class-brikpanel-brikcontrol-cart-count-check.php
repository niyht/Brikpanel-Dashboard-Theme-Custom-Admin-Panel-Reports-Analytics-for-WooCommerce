<?php
/**
 * BrikPanel — BrikControl check: inflated add-to-cart history.
 *
 * Until 3.3.1 the add-to-cart counters remembered "this visitor already counted
 * today" in something the visitor carried: a cookie for the store-wide figures,
 * the WooCommerce session for the per-product card. A client that arrives with
 * no memory therefore answered "never seen this" every single time, and the
 * guard that was supposed to catch that waved through anything holding a
 * cookie — which a script does, right up to the moment it drops the jar and
 * starts the next turn.
 *
 * The counters are fixed. The rows they already wrote are not, and they are
 * what the dashboard reads: a store measured for this feature carried 13,053
 * recorded add-to-carts in one month against a real baseline of 4-7 a day, all
 * of it aimed at a handful of products that consequently owned the "Most Added
 * to Cart" list.
 *
 * Two rules decide what is wrong, deliberately kept apart because they carry
 * very different certainty:
 *
 *   Impossible — the funnel says a visit precedes an add, and an add precedes
 *   a checkout. A day where one product was added by more people than visited
 *   the whole store, or where the store's add-to-carts outnumber its visitors,
 *   is not a judgement call. Capping to the step above it is arithmetic.
 *
 *   Extreme — a day many times the product's own normal, and large in absolute
 *   terms. This one is a heuristic and can in principle catch a real promotion,
 *   which is why the two are counted, listed and labelled separately and why
 *   nothing is written before the merchant looks at the list and confirms.
 *
 * Nothing is deleted. A flagged day is lowered to the highest value it could
 * honestly have had; zero would be as wrong as the inflated figure, and the
 * previous values are kept so one click puts them back.
 *
 * @package BrikPanel
 * @since   3.3.1
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Brikpanel_BrikControl_Cart_Count_Check extends Brikpanel_BrikControl_Check {

    /**
     * Whether this store's visitor figures are worth reasoning from.
     * Set once per scan by prime_visitor_credibility().
     *
     * @var bool
     */
    private $visitors_credible = false;

    /**
     * Days of history examined.
     */
    const DEFAULT_WINDOW_DAYS = 180;

    /**
     * A day must be at least this many times the series' own median before the
     * "extreme" rule fires.
     */
    const DEFAULT_MULTIPLIER = 10;

    /**
     * ...and at least this large in absolute terms, so a product whose normal
     * day is 1 does not get flagged for a day of 12.
     */
    const DEFAULT_FLOOR = 25;

    /**
     * Days of observed history a series needs before its median means anything.
     * Below this the series is left alone entirely.
     */
    const MIN_BASELINE_DAYS = 7;

    /**
     * Visitors a day needs before its visitor count is treated as a ceiling.
     *
     * The visitor counter runs from JavaScript; the add-to-cart counter runs on
     * a PHP hook. A shopper with JavaScript blocked, a script that never loaded
     * behind an aggressive cache, or a store that only turned visitor tracking
     * on last week all produce a visitor figure far below the truth — and then
     * "more adds than visitors" says nothing about robots and everything about
     * the tracker. Below this, and unless the window's own median clears it
     * too, the ceiling rule stands down entirely.
     */
    const MIN_CREDIBLE_VISITORS = 20;

    /**
     * How far past the visitor count a day must go before the ceiling fires.
     *
     * The two counters can disagree slightly for the reason above, so a bare
     * `>` would flag ordinary noise. A day has to be half again over the
     * visitor figure to count as broken.
     */
    const CEILING_TOLERANCE = 1.5;

    /**
     * Rows shown in the card's detail table.
     */
    const SAMPLE_LIMIT = 25;

    /**
     * Distinct products whose full daily history is pulled into memory in one
     * scan, worst first.
     *
     * The naive version of this check asked the database for every product's
     * every day and did the filtering in PHP. On a mid-size catalogue — 3,000
     * products over the 180-day window, 540,000 grouped rows — that measured
     * 12.3 seconds and a 360 MB peak, which is a fatal on any host with a
     * normal memory limit rather than a slow scan. So the database now does
     * the filtering (see the HAVING in candidate_days()) and only the handful
     * of products that could possibly be flagged are read in full.
     *
     * When more than this many products qualify, the worst are taken and the
     * card says so; a rescan after correcting them surfaces the next batch.
     */
    const MAX_CANDIDATE_PRODUCTS = 200;

    /**
     * Products whose daily series is read per query in the second pass, so
     * peak memory stays flat no matter how many candidates there are.
     */
    const SERIES_CHUNK = 25;

    /**
     * Day/series pairs corrected per click. Beyond this the fix reports
     * has_more and the merchant clicks again.
     */
    const FIX_CHUNK = 500;

    /**
     * Index option: how many rows the restore point holds and where they live.
     * Small and cheap, because every scan reads it to decide whether to offer
     * the undo button.
     */
    const BACKUP_OPTION = 'brikpanel_cart_count_cleanup_backup';

    /**
     * Prefix for the numbered options holding the rows themselves.
     */
    const BACKUP_CHUNK_PREFIX = 'brikpanel_cart_count_cleanup_backup_';

    /**
     * Rows per chunk option.
     *
     * The restore point is split rather than kept in one option for two
     * reasons: a single multi-megabyte row would be read in full by every
     * scan just to count it, and a hard cap on its size would eventually put
     * the repair in the position of either correcting data it cannot undo or
     * refusing to continue. Chunks make both problems go away — the scan reads
     * only the index, and the restore point grows with the work.
     */
    const BACKUP_CHUNK_ROWS = 5000;

    /**
     * Sanity ceiling so a runaway can never fill wp_options. Far beyond any
     * real store: one pass can only produce MAX_CANDIDATE_PRODUCTS x window
     * findings. Reaching it stops the repair with has_more rather than
     * correcting anything it could not restore.
     */
    const MAX_BACKUP_ROWS = 200000;

    /* ---------------------------------------------------------------------
     * Identity
     * ------------------------------------------------------------------ */

    /**
     * @return string
     */
    public function get_id() {
        return 'inflated_cart_counts';
    }

    /**
     * @return string
     */
    public function get_label() {
        return __( 'Add-to-Cart History', 'brikpanel' );
    }

    /**
     * @return string
     */
    public function get_category() {
        return 'content';
    }

    /**
     * @return int
     */
    public function get_priority() {
        return 25;
    }

    /**
     * Two grouped reads over indexed date columns.
     *
     * @return bool
     */
    public function supports_batching() {
        return false;
    }

    /**
     * @return bool
     */
    public function supports_fix() {
        return true;
    }

    /**
     * @return string
     */
    public function get_fix_label() {
        return __( 'Correct these days', 'brikpanel' );
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

        $scan = $this->scan();

        if ( null === $scan ) {
            $result['status']      = 'ok';
            $result['score']       = 100;
            $result['summary']     = __( 'No analytics history has been recorded yet.', 'brikpanel' );
            $result['duration_ms'] = (int) round( ( microtime( true ) - $started ) * 1000 );
            return $result;
        }

        $impossible = (int) $scan['impossible'];
        $extreme    = (int) $scan['extreme'];
        $flagged    = $impossible + $extreme;
        $overcount  = (int) $scan['overcount'];

        if ( $impossible > 0 ) {
            $result['status'] = 'critical';
            $result['score']  = 0;
            $result['summary'] = $this->safe_sprintf(
                /* translators: %s: number of days. */
                _n(
                    '%s day recorded more add-to-carts than the store had visitors',
                    '%s days recorded more add-to-carts than the store had visitors',
                    $impossible,
                    'brikpanel'
                ),
                number_format_i18n( $impossible )
            );
            $result['message'] = $this->safe_sprintf(
                /* translators: %s: number of add-to-cart events. */
                __( 'A visit has to happen before an add-to-cart, so these days cannot be real. Around %s recorded events are involved. Until 3.3.1 a script that dropped its cookies between turns was counted again on every turn, which is what produces this pattern. The counters no longer allow it; these are the rows written before the fix.', 'brikpanel' ),
                number_format_i18n( $overcount )
            );
        } elseif ( $extreme > 0 ) {
            $result['status'] = 'warning';
            $result['score']  = 55;
            $result['summary'] = $this->safe_sprintf(
                /* translators: %s: number of days. */
                _n(
                    '%s day sits far above its own normal',
                    '%s days sit far above their own normal',
                    $extreme,
                    'brikpanel'
                ),
                number_format_i18n( $extreme )
            );
            $result['message'] = $this->safe_sprintf(
                /* translators: 1: multiplier, 2: minimum event count. */
                __( 'Each of these days is at least %1$s times the typical day for that same product and counted at least %2$s events. That is the shape automated traffic leaves behind, but a genuine promotion can look like it too — open the list below and check before correcting anything.', 'brikpanel' ),
                number_format_i18n( $scan['multiplier'] ),
                number_format_i18n( $scan['floor'] )
            );
        } else {
            $result['status']  = 'ok';
            $result['score']   = 100;
            $result['summary'] = __( 'Your add-to-cart history looks consistent.', 'brikpanel' );
            $result['message'] = __( 'No day counted more add-to-carts than the store had visitors, and no day stands far above its own normal.', 'brikpanel' );
        }

        // Say so rather than letting the merchant read a partial figure as the
        // whole story: the scan takes the worst products first, so a truncated
        // list is a first instalment, not a wrong one.
        if ( ! empty( $scan['truncated'] ) && $flagged > 0 ) {
            $result['message'] = trim(
                $result['message'] . ' ' . $this->safe_sprintf(
                    /* translators: %s: number of products. */
                    __( 'More products are affected than one pass covers; these are the worst %s. Run the check again after correcting them to see the rest.', 'brikpanel' ),
                    number_format_i18n( self::MAX_CANDIDATE_PRODUCTS )
                )
            );
        }

        $result['recommendations'] = $this->build_recommendations( $impossible, $extreme );

        $backup = $this->read_backup_index();

        $result['metadata'] = [
            'fixable'      => $flagged,
            'undoable'     => $backup['count'],
            'undo_at'      => (int) $backup['time'],
            'fix_confirm'  => $this->fix_confirm_text( $flagged, $overcount ),
            'stats'        => [
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
            'samples_title' => __( 'Days that would change', 'brikpanel' ),
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
     * Build the confirmation sentence the card hands to the browser.
     *
     * Written here rather than in the shared localize array because the generic
     * one talks about index rows, and a merchant about to rewrite their own
     * analytics history deserves to be told exactly that.
     *
     * @param int $flagged   Day/series pairs that would change.
     * @param int $overcount Recorded events that would disappear.
     * @return string
     */
    private function fix_confirm_text( $flagged, $overcount ) {
        return $this->safe_sprintf(
            /* translators: 1: number of days, 2: number of events. */
            __( 'Lower %1$s day(s) of add-to-cart history, removing about %2$s recorded events? Your products, orders and customers are not touched, and the previous figures are kept so you can undo this.', 'brikpanel' ),
            number_format_i18n( (int) $flagged ),
            number_format_i18n( (int) $overcount )
        );
    }

    /**
     * Examine both counter tables and decide what is wrong.
     *
     * @return array|null Null when neither table exists.
     */
    private function scan() {
        global $wpdb;

        $visitors = $wpdb->prefix . 'brikpanel_visitors';
        $cart     = $wpdb->prefix . 'brikpanel_cart_tracking';

        $has_visitors = $this->table_exists( $visitors );
        $has_cart     = $this->table_exists( $cart );

        if ( ! $has_visitors && ! $has_cart ) {
            return null;
        }

        $window = (int) apply_filters( 'brikpanel_cart_count_window_days', self::DEFAULT_WINDOW_DAYS );
        $window = max( 14, min( 3650, $window ) );

        $multiplier = (float) apply_filters( 'brikpanel_cart_count_outlier_multiplier', self::DEFAULT_MULTIPLIER );
        $multiplier = max( 2, $multiplier );

        $floor = (int) apply_filters( 'brikpanel_cart_count_outlier_floor', self::DEFAULT_FLOOR );
        $floor = max( 5, $floor );

        // Window bounds are built in the site's timezone: the counters write
        // local dates, so a UTC-derived boundary would slice the wrong day off
        // either end of the range.
        $from = wp_date( 'Y-m-d', strtotime( '-' . $window . ' days', (int) current_time( 'timestamp' ) ) );

        $daily_visitors = $has_visitors ? $this->load_visitor_days( $visitors, $from ) : [];
        $this->prime_visitor_credibility( $daily_visitors );

        $findings = [];

        if ( $has_visitors ) {
            $findings = array_merge( $findings, $this->find_in_visitors( $daily_visitors, $multiplier, $floor ) );
        }
        $truncated = false;
        if ( $has_cart ) {
            $cart_scan = $this->find_in_cart_tracking( $cart, $from, $daily_visitors, $multiplier, $floor );
            $findings  = array_merge( $findings, $cart_scan['findings'] );
            $truncated = $cart_scan['truncated'];
        }

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

        // Worst first, so the sample table opens on the days that matter.
        usort(
            $findings,
            static function ( $a, $b ) {
                return ( $b['current'] - $b['target'] ) <=> ( $a['current'] - $a['target'] );
            }
        );

        return [
            'impossible' => $impossible,
            'extreme'    => $extreme,
            'overcount'  => $overcount,
            'window'     => $window,
            'multiplier' => $multiplier,
            'floor'      => $floor,
            'truncated'  => $truncated,
            'samples'    => $this->build_samples( $findings ),
        ];
    }

    /**
     * Daily store-wide figures, keyed by local date.
     *
     * @param string $table Fully prefixed visitors table.
     * @param string $from  Inclusive lower bound, Y-m-d.
     * @return array<string, array{id:int,visitors:int,atc:int,checkout:int}>
     */
    private function load_visitor_days( $table, $from ) {
        global $wpdb;

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- table name is internal; the bound value is prepared.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, date_column, visitor_count, add_to_cart_count, checkout_count
                   FROM {$table}
                  WHERE date_column >= %s
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
                'atc'      => (int) $row['add_to_cart_count'],
                'checkout' => (int) $row['checkout_count'],
            ];
        }

        return $out;
    }

    /**
     * Findings in the store-wide funnel columns.
     *
     * @param array $daily      Output of load_visitor_days().
     * @param float $multiplier Extreme-rule multiplier.
     * @param int   $floor      Extreme-rule absolute floor.
     * @return array
     */
    private function find_in_visitors( array $daily, $multiplier, $floor ) {
        $findings = [];

        $atc_series      = [];
        $checkout_series = [];
        foreach ( $daily as $day ) {
            $atc_series[]      = $day['atc'];
            $checkout_series[] = $day['checkout'];
        }

        $atc_median      = $this->median( $atc_series );
        $checkout_median = $this->median( $checkout_series );
        $enough_history  = count( $daily ) >= self::MIN_BASELINE_DAYS;

        foreach ( $daily as $date => $day ) {
            $atc_normal = ( $enough_history && $this->is_extreme( $day['atc'], $atc_median, $multiplier, $floor ) )
                ? (int) round( $atc_median )
                : null;

            $finding = $this->weigh(
                'visitors', $day['id'], 'add_to_cart_count', $date,
                __( 'Store add-to-carts', 'brikpanel' ),
                $day['atc'], $this->visitor_ceiling( $day['visitors'], $day['atc'] ), $atc_normal,
                __( 'More adds than visitors', 'brikpanel' )
            );
            if ( $finding ) {
                $findings[] = $finding;
            }

            // No invariant for checkout. "More checkouts than add-to-carts"
            // looks broken but is ordinary: a cart survives the day it was
            // filled, so a returning customer reaches checkout today having
            // added yesterday, and every abandoned-cart recovery link does the
            // same by design. Only the day's own normal is evidence here.
            $co_normal = ( $enough_history && $this->is_extreme( $day['checkout'], $checkout_median, $multiplier, $floor ) )
                ? (int) round( $checkout_median )
                : null;

            $finding = $this->weigh(
                'visitors', $day['id'], 'checkout_count', $date,
                __( 'Checkout visits', 'brikpanel' ),
                $day['checkout'], null, $co_normal,
                ''
            );
            if ( $finding ) {
                $findings[] = $finding;
            }
        }

        return $findings;
    }

    /**
     * Findings in the per-product table.
     *
     * Two passes on purpose. The first asks the database which product/day
     * pairs are even eligible — a total below the smallest figure either rule
     * could ever fire on cannot be a finding, and on a healthy store that
     * throws away essentially everything before it reaches PHP. The second
     * reads the full daily history of only those products, in chunks, because
     * the median needs the whole series and nothing else does.
     *
     * @param string $table      Fully prefixed cart tracking table.
     * @param string $from       Inclusive lower bound, Y-m-d.
     * @param array  $daily      Output of load_visitor_days().
     * @param float  $multiplier Extreme-rule multiplier.
     * @param int    $floor      Extreme-rule absolute floor.
     * @return array{findings:array,truncated:bool}
     */
    private function find_in_cart_tracking( $table, $from, array $daily, $multiplier, $floor ) {
        $candidates = $this->candidate_products( $table, $from, $floor );

        if ( empty( $candidates['ids'] ) ) {
            return [ 'findings' => [], 'truncated' => false ];
        }

        $findings = [];

        foreach ( array_chunk( $candidates['ids'], self::SERIES_CHUNK ) as $chunk ) {
            foreach ( $this->load_product_series( $table, $from, $chunk ) as $product_id => $days ) {
                $median         = $this->median( array_values( $days ) );
                $enough_history = count( $days ) >= self::MIN_BASELINE_DAYS;

                foreach ( $days as $date => $total ) {
                    $visitors = isset( $daily[ $date ] ) ? (int) $daily[ $date ]['visitors'] : 0;

                    // One product cannot be added by more people than visited
                    // the whole store that day.
                    $ceiling = $this->visitor_ceiling( $visitors, $total );
                    $normal  = ( $enough_history && $this->is_extreme( $total, $median, $multiplier, $floor ) )
                        ? (int) round( $median )
                        : null;

                    $finding = $this->weigh(
                        'cart', $product_id, 'cart_count', $date,
                        '', $total, $ceiling, $normal,
                        __( 'More adds than store visitors', 'brikpanel' )
                    );
                    if ( $finding ) {
                        $findings[] = $finding;
                    }
                }
            }
        }

        return [ 'findings' => $findings, 'truncated' => $candidates['truncated'] ];
    }

    /**
     * Products with at least one day large enough to be worth reading in full.
     *
     * The threshold is the smallest total either rule could ever fire on: the
     * extreme rule needs the absolute floor, and the ceiling rule cannot fire
     * below MIN_CREDIBLE_VISITORS x CEILING_TOLERANCE because a smaller
     * visitor count is never treated as evidence. Anything under the lower of
     * the two is provably not a finding, so the database drops it.
     *
     * @param string $table Fully prefixed cart tracking table.
     * @param string $from  Inclusive lower bound, Y-m-d.
     * @param int    $floor Extreme-rule absolute floor.
     * @return array{ids:int[],truncated:bool}
     */
    private function candidate_products( $table, $from, $floor ) {
        global $wpdb;

        $threshold = max(
            1,
            (int) min(
                $floor,
                (int) floor( self::MIN_CREDIBLE_VISITORS * self::CEILING_TOLERANCE ) + 1
            )
        );

        // One row per qualifying product, worst first. Reading one extra row
        // is how we learn the list was cut short without a second COUNT query.
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- table name is internal; bound values are prepared.
        $ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT product_id
                   FROM (
                        SELECT product_id, MAX(daily) AS worst
                          FROM (
                                SELECT product_id, DATE(date_column) AS day, SUM(cart_count) AS daily
                                  FROM {$table}
                                 WHERE date_column >= %s
                                 GROUP BY product_id, DATE(date_column)
                                HAVING daily >= %d
                               ) d
                         GROUP BY product_id
                        ) p
                  ORDER BY worst DESC
                  LIMIT %d",
                $from . ' 00:00:00',
                $threshold,
                self::MAX_CANDIDATE_PRODUCTS + 1
            )
        );

        $ids       = array_values( array_filter( array_map( 'absint', (array) $ids ) ) );
        $truncated = count( $ids ) > self::MAX_CANDIDATE_PRODUCTS;

        if ( $truncated ) {
            $ids = array_slice( $ids, 0, self::MAX_CANDIDATE_PRODUCTS );
        }

        return [ 'ids' => $ids, 'truncated' => $truncated ];
    }

    /**
     * Daily totals for a handful of products.
     *
     * @param string $table Fully prefixed cart tracking table.
     * @param string $from  Inclusive lower bound, Y-m-d.
     * @param int[]  $ids   Product ids, already absint()-mapped.
     * @return array<int, array<string,int>> product id => (day => total)
     */
    private function load_product_series( $table, $from, array $ids ) {
        global $wpdb;

        if ( empty( $ids ) ) {
            return [];
        }

        $list = implode( ',', array_map( 'absint', $ids ) );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- table name is internal, $list is absint()-mapped integers, the bound value is prepared.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT product_id, DATE(date_column) AS day, SUM(cart_count) AS total
                   FROM {$table}
                  WHERE product_id IN ({$list})
                    AND date_column >= %s
                  GROUP BY product_id, DATE(date_column)",
                $from . ' 00:00:00'
            ),
            ARRAY_A
        );

        $series = [];
        foreach ( (array) $rows as $row ) {
            $series[ (int) $row['product_id'] ][ (string) $row['day'] ] = (int) $row['total'];
        }

        return $series;
    }

    /**
     * Decide once whether this store's visitor figures can be used as evidence.
     *
     * A store that only tracks a handful of visitors a day is not a store with
     * a handful of visitors; it is a store whose tracker is not running for
     * most people. Asking such a figure to prove that an add-to-cart total is
     * impossible would turn a broken tracker into a stream of confident,
     * wrong accusations against real days.
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
     * The ceiling a day's visitor count puts on any add-to-cart figure.
     *
     * @param int $visitors Visitors recorded that day.
     * @param int $value    Add-to-cart figure being judged.
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
     *
     * Two rules can fire on the same day, and when they do the tighter of the
     * two answers is the right one. The invariant ceiling only proves the day
     * cannot have been larger than the step above it in the funnel; the day's
     * own normal is a far closer estimate of what actually happened. Taking
     * the lower of the two keeps the certainty of the first and the accuracy
     * of the second, and the day is still labelled by the strongest claim that
     * applies, because "this is arithmetically impossible" and "this looks
     * wrong" mean different things to whoever is about to press the button.
     *
     * @param string   $source        'visitors' or 'cart'.
     * @param int      $ref           Row id (visitors) or product id (cart).
     * @param string   $column        Column that would be corrected.
     * @param string   $date          Local Y-m-d.
     * @param string   $label         Human label for the series.
     * @param int      $current       Recorded value.
     * @param int|null $ceiling       Invariant ceiling, or null when unbroken.
     * @param int|null $normal        The series' own normal, or null when the day is not extreme.
     * @param string   $ceiling_reason Translated explanation for the invariant.
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

        $rule   = ( null !== $ceiling ) ? 'impossible' : 'extreme';
        $reason = ( null !== $ceiling ) ? $ceiling_reason : __( 'Far above its own normal', 'brikpanel' );

        return $this->finding(
            $source, $ref, $column, $date, $label,
            $current, min( $candidates ), $rule, $reason
        );
    }

    /**
     * Shape one finding.
     *
     * @param string $source  'visitors' or 'cart'.
     * @param int    $ref     Row id (visitors) or product id (cart).
     * @param string $column  Column being corrected.
     * @param string $date    Local Y-m-d.
     * @param string $label   Human label for the series.
     * @param int    $current Recorded value.
     * @param int    $target  Value it would be lowered to.
     * @param string $rule    'impossible' or 'extreme'.
     * @param string $reason  Translated explanation.
     * @return array
     */
    private function finding( $source, $ref, $column, $date, $label, $current, $target, $rule, $reason ) {
        return [
            'source'  => $source,
            'ref'     => (int) $ref,
            'column'  => $column,
            'date'    => (string) $date,
            'label'   => (string) $label,
            'current' => (int) $current,
            // Never raise a figure, whatever the arithmetic says.
            'target'  => (int) max( 0, min( (int) $current, (int) $target ) ),
            'rule'    => $rule,
            'reason'  => (string) $reason,
        ];
    }

    /**
     * Is this value far enough above the series' own normal to be suspect?
     *
     * @param int   $value      Day's figure.
     * @param float $median     Series median.
     * @param float $multiplier Required ratio.
     * @param int   $floor      Required absolute size.
     * @return bool
     */
    private function is_extreme( $value, $median, $multiplier, $floor ) {
        $value = (int) $value;

        if ( $value < $floor ) {
            return false;
        }

        // A median of zero means the product is normally not added at all; the
        // absolute floor alone then decides, which is why the floor exists.
        $ceiling = max( 1.0, (float) $median ) * (float) $multiplier;

        return $value >= $ceiling;
    }

    /**
     * Median of a list of integers.
     *
     * The mean would be pulled up by the very days being hunted, which is the
     * whole reason this is a median.
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
     * Readable name for a product row, falling back to its id.
     *
     * @param int $product_id
     * @return string
     */
    private function product_label( $product_id ) {
        $product_id = (int) $product_id;
        $title      = get_the_title( $product_id );

        if ( is_string( $title ) && '' !== trim( $title ) ) {
            return $title;
        }

        return $this->safe_sprintf(
            /* translators: %s: product ID of a product that no longer exists. */
            __( 'Product #%s', 'brikpanel' ),
            number_format_i18n( $product_id )
        );
    }

    /**
     * Detail rows for the card table.
     *
     * @param array $findings Sorted findings.
     * @return array
     */
    private function build_samples( array $findings ) {
        $shown = array_slice( $findings, 0, self::SAMPLE_LIMIT );

        // Product names are resolved here and nowhere else. Naming every
        // finding as it was created would mean a post lookup per row on a scan
        // that can produce thousands; only the couple of dozen rows actually
        // printed need a name, and priming them is one query for all of them.
        $ids = [];
        foreach ( $shown as $finding ) {
            if ( 'cart' === $finding['source'] ) {
                $ids[] = (int) $finding['ref'];
            }
        }
        if ( ! empty( $ids ) ) {
            _prime_post_caches( array_values( array_unique( $ids ) ), false, false );
        }

        $samples = [];

        foreach ( $shown as $finding ) {
            $label = ( 'cart' === $finding['source'] )
                ? $this->product_label( $finding['ref'] )
                : $finding['label'];

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

    /**
     * @param int $impossible Count of impossible days.
     * @param int $extreme    Count of extreme days.
     * @return array
     */
    private function build_recommendations( $impossible, $extreme ) {
        $recs = [];

        if ( $impossible > 0 || $extreme > 0 ) {
            $recs[] = [
                'text'     => __( 'Open the list below first. Correcting rewrites your own analytics history, and a real campaign day can look like a robot day.', 'brikpanel' ),
                'priority' => 'high',
            ];
            $recs[] = [
                'text'     => __( 'If a known crawler is behind it, add its user agent or address under WooCommerce → Settings → BrikPanel → Analytics so it stops being counted at all.', 'brikpanel' ),
                'priority' => 'medium',
            ];
        }

        return $recs;
    }

    /* ---------------------------------------------------------------------
     * Fix
     * ------------------------------------------------------------------ */

    /**
     * Lower every flagged day to the highest value it could honestly have had.
     *
     * Re-runs the scan instead of trusting whatever the card was showing: the
     * stored result can be hours old, and a repair must never act on a picture
     * of the data rather than the data.
     *
     * @param array $args Unused.
     * @return array { removed: int, has_more: bool, message: string }
     */
    public function run_fix( array $args = [] ) {
        global $wpdb;

        $scan = $this->scan_findings();

        if ( empty( $scan ) ) {
            return [ 'removed' => 0, 'has_more' => false, 'message' => '' ];
        }

        $has_more = count( $scan ) > self::FIX_CHUNK;
        $batch    = array_slice( $scan, 0, self::FIX_CHUNK );

        $visitors = $wpdb->prefix . 'brikpanel_visitors';
        $cart     = $wpdb->prefix . 'brikpanel_cart_tracking';

        $index   = $this->read_backup_index();
        $pending = [];
        $removed = 0;
        $full    = false;

        foreach ( $batch as $finding ) {
            // Never correct a row we could not put back. Hitting the ceiling
            // stops the run instead of quietly downgrading the undo promise.
            if ( $index['count'] + count( $pending ) >= self::MAX_BACKUP_ROWS ) {
                $full     = true;
                $has_more = true;
                break;
            }

            $written = ( 'visitors' === $finding['source'] )
                ? $this->correct_visitor_row( $visitors, $finding )
                : $this->correct_cart_day( $cart, $finding );

            if ( empty( $written ) ) {
                continue;
            }

            $removed += max( 0, $finding['current'] - $finding['target'] );
            $pending  = array_merge( $pending, $written );
        }

        if ( ! empty( $pending ) ) {
            $this->append_backup( $pending );
        }

        return [
            'removed'  => $removed,
            'has_more' => $has_more,
            'message'  => $full
                ? __( 'The restore point is full. Undo this batch or leave it in place, then run the check again.', 'brikpanel' )
                : '',
        ];
    }

    /**
     * Findings only, without the presentation work run() does around them.
     *
     * @return array
     */
    private function scan_findings() {
        global $wpdb;

        $visitors = $wpdb->prefix . 'brikpanel_visitors';
        $cart     = $wpdb->prefix . 'brikpanel_cart_tracking';

        $has_visitors = $this->table_exists( $visitors );
        $has_cart     = $this->table_exists( $cart );

        if ( ! $has_visitors && ! $has_cart ) {
            return [];
        }

        $window = (int) apply_filters( 'brikpanel_cart_count_window_days', self::DEFAULT_WINDOW_DAYS );
        $window = max( 14, min( 3650, $window ) );

        $multiplier = max( 2, (float) apply_filters( 'brikpanel_cart_count_outlier_multiplier', self::DEFAULT_MULTIPLIER ) );
        $floor      = max( 5, (int) apply_filters( 'brikpanel_cart_count_outlier_floor', self::DEFAULT_FLOOR ) );

        $from  = wp_date( 'Y-m-d', strtotime( '-' . $window . ' days', (int) current_time( 'timestamp' ) ) );
        $daily = $has_visitors ? $this->load_visitor_days( $visitors, $from ) : [];

        $this->prime_visitor_credibility( $daily );

        $findings = [];
        if ( $has_visitors ) {
            $findings = array_merge( $findings, $this->find_in_visitors( $daily, $multiplier, $floor ) );
        }
        if ( $has_cart ) {
            $cart_scan = $this->find_in_cart_tracking( $cart, $from, $daily, $multiplier, $floor );
            $findings  = array_merge( $findings, $cart_scan['findings'] );
        }

        return $findings;
    }

    /**
     * Write one corrected store-wide column.
     *
     * @param string $table   Fully prefixed visitors table.
     * @param array  $finding One finding.
     * @return array Backup entries actually written.
     */
    private function correct_visitor_row( $table, array $finding ) {
        global $wpdb;

        // Whitelisted: the value reaches $wpdb->update() as a column key.
        if ( ! in_array( $finding['column'], [ 'add_to_cart_count', 'checkout_count' ], true ) ) {
            return [];
        }

        $row_id = (int) $finding['ref'];
        if ( $row_id <= 0 ) {
            return [];
        }

        // Re-read under the same request rather than trusting the scan's copy,
        // so a concurrent counter write is not silently discarded.
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- table and column are internal whitelisted values.
        $current = $wpdb->get_var(
            $wpdb->prepare( "SELECT {$finding['column']} FROM {$table} WHERE id = %d", $row_id )
        );

        if ( null === $current || (int) $current <= (int) $finding['target'] ) {
            return [];
        }

        $updated = $wpdb->update(
            $table,
            [ $finding['column'] => (int) $finding['target'] ],
            [ 'id' => $row_id ],
            [ '%d' ],
            [ '%d' ]
        );

        if ( ! $updated ) {
            return [];
        }

        return [
            [
                't'   => 'v',
                'id'  => $row_id,
                'col' => $finding['column'],
                'old' => (int) $current,
                'new' => (int) $finding['target'],
            ],
        ];
    }

    /**
     * Write one corrected product/day in the per-product table.
     *
     * A day can span several rows, so the correction lands on the first row and
     * the rest are zeroed. Every touched row is backed up, which is what makes
     * the undo exact rather than approximate.
     *
     * @param string $table   Fully prefixed cart tracking table.
     * @param array  $finding One finding.
     * @return array Backup entries actually written.
     */
    private function correct_cart_day( $table, array $finding ) {
        global $wpdb;

        $product_id = (int) $finding['ref'];
        $date       = $finding['date'];

        if ( $product_id <= 0 || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $date ) ) {
            return [];
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- table name is internal; bound values are prepared.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, cart_count
                   FROM {$table}
                  WHERE product_id = %d
                    AND date_column BETWEEN %s AND %s
                  ORDER BY id ASC",
                $product_id,
                $date . ' 00:00:00',
                $date . ' 23:59:59'
            ),
            ARRAY_A
        );

        if ( empty( $rows ) ) {
            return [];
        }

        $written   = [];
        $remaining = (int) $finding['target'];

        foreach ( $rows as $index => $row ) {
            $old = (int) $row['cart_count'];
            $new = ( 0 === $index ) ? $remaining : 0;

            if ( $old === $new ) {
                continue;
            }

            $updated = $wpdb->update(
                $table,
                [ 'cart_count' => $new ],
                [ 'id' => (int) $row['id'] ],
                [ '%d' ],
                [ '%d' ]
            );

            if ( $updated ) {
                $written[] = [
                    't'   => 'c',
                    'id'  => (int) $row['id'],
                    'col' => 'cart_count',
                    'old' => $old,
                    'new' => $new,
                ];
            }
        }

        return $written;
    }

    /* ---------------------------------------------------------------------
     * Undo
     * ------------------------------------------------------------------ */

    /**
     * Put every backed-up value back exactly as it was.
     *
     * @since 3.3.1
     *
     * @return array { restored: int, message: string }
     */
    public function run_undo() {
        global $wpdb;

        $index = $this->read_backup_index();

        if ( $index['count'] < 1 ) {
            return [ 'restored' => 0, 'message' => __( 'There is nothing to undo.', 'brikpanel' ) ];
        }

        $visitors = $wpdb->prefix . 'brikpanel_visitors';
        $cart     = $wpdb->prefix . 'brikpanel_cart_tracking';

        $restored = 0;

        // Newest chunk first, so a run interrupted halfway has still undone the
        // most recent work rather than an arbitrary slice of it.
        for ( $i = $index['chunks'] - 1; $i >= 0; $i-- ) {
            $rows = get_option( self::BACKUP_CHUNK_PREFIX . $i, [] );

            foreach ( (array) $rows as $row ) {
                $table  = ( 'v' === ( $row['t'] ?? '' ) ) ? $visitors : $cart;
                $column = (string) ( $row['col'] ?? '' );

                // Whitelisted: the value reaches $wpdb->update() as a column key.
                if ( ! in_array( $column, [ 'add_to_cart_count', 'checkout_count', 'cart_count' ], true ) ) {
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

            delete_option( self::BACKUP_CHUNK_PREFIX . $i );

            // Shrink the index as each chunk lands, so a run cut short by a
            // timeout leaves a restore point that still describes exactly what
            // is left rather than one that promises rows already replayed.
            update_option(
                self::BACKUP_OPTION,
                [
                    'time'   => $index['time'],
                    'count'  => max( 0, $index['count'] - count( (array) $rows ) ),
                    'chunks' => $i,
                ],
                false
            );
            $index['count'] = max( 0, $index['count'] - count( (array) $rows ) );
        }

        // Cleared whatever the outcome: a half-applied restore point replayed
        // twice would be worse than none, and the rows it describes no longer
        // match what is in the table.
        delete_option( self::BACKUP_OPTION );

        return [ 'restored' => $restored, 'message' => '' ];
    }

    /**
     * The restore point's index: how many rows it holds, in how many chunks.
     *
     * Deliberately tiny. Every scan reads this to decide whether to offer the
     * undo button, and it must not drag megabytes of rows along to do it.
     *
     * @return array{time:int,count:int,chunks:int}
     */
    private function read_backup_index() {
        $stored = get_option( self::BACKUP_OPTION, [] );

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
     *
     * @param array $rows Backup entries.
     * @return void
     */
    private function append_backup( array $rows ) {
        $index = $this->read_backup_index();

        // A restore point that reports nothing to restore is starting fresh.
        // Sweep first: an undo interrupted by a timeout can leave chunk options
        // behind, and appending on top of them would mix two corrections into
        // one undo.
        if ( $index['count'] < 1 ) {
            $this->purge_backup_chunks( $index['chunks'] );
            $index['chunks'] = 0;
        }

        // Top up the last chunk before opening another, so a repair run over
        // several clicks does not leave a trail of near-empty options.
        $chunk_index = max( 0, $index['chunks'] - 1 );
        $current     = ( $index['chunks'] > 0 )
            ? (array) get_option( self::BACKUP_CHUNK_PREFIX . $chunk_index, [] )
            : [];

        foreach ( $rows as $row ) {
            if ( count( $current ) >= self::BACKUP_CHUNK_ROWS ) {
                // Never autoloaded: read only when the merchant undoes.
                update_option( self::BACKUP_CHUNK_PREFIX . $chunk_index, $current, false );
                $chunk_index++;
                $current = [];
            }
            $current[] = $row;
        }

        update_option( self::BACKUP_CHUNK_PREFIX . $chunk_index, $current, false );

        update_option(
            self::BACKUP_OPTION,
            [
                'time'   => time(),
                'count'  => $index['count'] + count( $rows ),
                'chunks' => $chunk_index + 1,
            ],
            false
        );
    }

    /**
     * Delete leftover restore-point chunks.
     *
     * @param int $known How many chunks the index claimed.
     * @return void
     */
    private function purge_backup_chunks( $known ) {
        global $wpdb;

        for ( $i = 0; $i < max( 0, (int) $known ); $i++ ) {
            delete_option( self::BACKUP_CHUNK_PREFIX . $i );
        }

        // Belt and braces: a chunk the index never knew about (a crash between
        // writing a chunk and writing the index) would otherwise sit in
        // wp_options forever.
        $stale = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
                $wpdb->esc_like( self::BACKUP_CHUNK_PREFIX ) . '%'
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

    /**
     * sprintf() that cannot be brought down by a bad translation.
     *
     * Same helper, and same reason, as the other checks: a translator who drops
     * a placeholder would otherwise throw inside the scan worker and abort the
     * whole sweep.
     *
     * @param string $format Translated format string.
     * @param mixed  ...$args printf arguments.
     * @return string
     */
    private function safe_sprintf( $format, ...$args ) {
        $format = (string) $format;
        try {
            return vsprintf( $format, $args );
        } catch ( \Throwable $e ) {
            $stripped = preg_replace(
                '/%(?:\d+\$)?[-+ 0#\']*\d*(?:\.\d+)?[bcdeEfFgGosuxX]/',
                '',
                $format
            );
            $stripped = str_replace( '%%', '%', (string) $stripped );
            return trim( (string) $stripped );
        }
    }
}
