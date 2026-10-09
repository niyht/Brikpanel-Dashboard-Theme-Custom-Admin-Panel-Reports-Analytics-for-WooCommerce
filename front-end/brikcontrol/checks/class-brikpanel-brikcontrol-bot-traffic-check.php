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

    /** Nightly job that cleans bot traffic without a click (3.3.30). */
    const AUTO_HOOK = 'brikpanel_bot_traffic_autoclean';

    /** What the last nightly run did: { time, figures, entries, removed, left }. */
    const AUTO_LOG_OPTION = 'brikpanel_bot_traffic_auto';

    /** Findings every cleanup changed since the last undo, as keys. */
    const APPLIED_OPTION = 'brikpanel_bot_traffic_applied';

    /** Findings the merchant put back with Undo: the nightly run never touches them again. */
    const KEPT_OPTION = 'brikpanel_bot_traffic_kept';

    /** Upper bound for either key list. */
    const KEYS_CAP = 5000;

    /** Rounds one nightly run may clean (each a FIX_CHUNK batch). */
    const AUTO_ROUNDS = 5;

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

    /**
     * Store-wide days of the last scan (load_visitor_days()), kept for the
     * nightly run's page rule.
     *
     * @var array
     */
    private $last_daily = [];

    /* ---------------------------------------------------------------------
     * Identity
     * ------------------------------------------------------------------ */

    public function get_id() {
        return 'bot_traffic';
    }

    public function get_label() {
        return __( 'Bot traffic', 'brikpanel' );
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

    /**
     * Figures only; the card writes the sentences (see bc_present()).
     *
     * 3: scripted abandoned-cart entries no longer set the status; the
     *    "Abandoned cart entries" check grades them. A stored schema 2 result
     *    could still be Critical for them alone, so it is rescanned once
     *    (Storage::maybe_heal()).
     *
     * @return int
     */
    public function bc_schema() {
        return 3;
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

        $scan     = $this->scan();
        $backup   = $this->read_backup_index();
        $legacy   = $this->read_index( self::LEGACY_BACKUP_OPTION );
        $undoable = $backup['count'] + $legacy['count'];
        $undo_at  = max( (int) $backup['time'], (int) $legacy['time'] );

        if ( null === $scan ) {
            $result['status']      = 'ok';
            $result['score']       = 100;
            $result['facts']       = [ 'no_history' => true, 'undoable' => $undoable, 'undo_at' => $undo_at ];
            $result['duration_ms'] = (int) round( ( microtime( true ) - $started ) * 1000 );
            return $result;
        }

        $impossible = (int) $scan['impossible'];
        $extreme    = (int) $scan['extreme'];
        $cartab     = (int) $scan['cartab'];

        // Only the daily figures grade this card. Scripted abandoned-cart
        // entries are graded by their own check; counting them here as well
        // made one problem show as two Critical cards (field test F2). The
        // card still lists them, because its cleanup deletes them.
        if ( $impossible > 0 ) {
            $result['status'] = 'critical';
            $result['score']  = 0;
        } elseif ( $extreme > 0 ) {
            $result['status'] = 'warning';
            $result['score']  = 55;
        } else {
            $result['status'] = 'ok';
            $result['score']  = 100;
        }

        $result['facts'] = [
            // The last nightly cleanup (3.3.30): { time, figures, entries, removed, left }.
            'auto'       => $this->auto_log(),
            'impossible' => $impossible,
            'extreme'    => $extreme,
            'cartab'     => $cartab,
            'overcount'  => (int) $scan['overcount'],
            'window'     => (int) $scan['window'],
            'multiplier' => (float) $scan['multiplier'],
            'floor'      => (int) $scan['floor'],
            'truncated'  => ! empty( $scan['truncated'] ),
            'max_series' => self::MAX_CANDIDATE_SERIES,
            'undoable'   => $undoable,
            'undo_at'    => $undo_at,
            // The worst day/series pairs as data (source, id, column, local
            // date, recorded, corrected, rule); names come at render time.
            'samples'    => $scan['samples'],
        ];

        $result['duration_ms'] = (int) round( ( microtime( true ) - $started ) * 1000 );

        return $result;
    }

    /**
     * What the last nightly cleanup did.
     *
     * @return array{time:int,figures:int,entries:int,removed:int,left:int}
     */
    private function auto_log() {
        $log = get_option( self::AUTO_LOG_OPTION, [] );
        $log = is_array( $log ) ? $log : [];
        return [
            'time'    => (int) ( $log['time'] ?? 0 ),
            'figures' => (int) ( $log['figures'] ?? 0 ),
            'entries' => (int) ( $log['entries'] ?? 0 ),
            'removed' => (int) ( $log['removed'] ?? 0 ),
            'left'    => (int) ( $log['left'] ?? 0 ),
        ];
    }

    /**
     * One sentence about the nightly cleanup, '' before it ever ran.
     *
     * @param array $auto auto_log() as stored in the result.
     * @return string
     */
    private function auto_sentence( array $auto ) {
        $time = (int) ( $auto['time'] ?? 0 );
        if ( $time <= 0 ) {
            return __( 'Bot traffic is cleaned up automatically every night.', 'brikpanel' );
        }
        $when    = wp_date( brikpanel_datetime_format(), $time );
        $figures = (int) ( $auto['figures'] ?? 0 );
        $entries = (int) ( $auto['entries'] ?? 0 );
        $done    = [];
        if ( $figures > 0 ) {
            $done[] = brikpanel_safe_sprintf(
                /* translators: %s: number of daily figures lowered. */
                _n( '%s daily figure lowered', '%s daily figures lowered', $figures, 'brikpanel' ),
                brikpanel_number( $figures )
            );
        }
        if ( $entries > 0 ) {
            $done[] = brikpanel_safe_sprintf(
                /* translators: %s: number of abandoned-cart entries deleted. */
                _n( '%s abandoned-cart entry deleted', '%s abandoned-cart entries deleted', $entries, 'brikpanel' ),
                brikpanel_number( $entries )
            );
        }
        if ( empty( $done ) ) {
            return brikpanel_safe_sprintf(
                /* translators: %s: date and time of the last automatic cleanup. */
                __( 'Bot traffic is cleaned up automatically every night. Last run %s: nothing needed cleaning.', 'brikpanel' ),
                $when
            );
        }
        return brikpanel_safe_sprintf(
            /* translators: 1: date and time of the last automatic cleanup, 2: what it did, e.g. "3 daily figures lowered and 10 abandoned-cart entries deleted". */
            __( 'Bot traffic is cleaned up automatically every night. Last run %1$s: %2$s.', 'brikpanel' ),
            $when,
            wp_sprintf_l( '%l', $done )
        );
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
        if ( empty( $f ) || (int) ( $r['schema'] ?? 1 ) < 2 ) {
            return $r; // Written before 3.3.25: show what was stored.
        }

        $undoable = (int) ( $f['undoable'] ?? 0 );
        $undo     = [
            'undoable'     => $undoable,
            'undo_at'      => (int) ( $f['undo_at'] ?? 0 ),
            'undo_confirm' => brikpanel_safe_sprintf(
                /* translators: %s: number of database rows to put back. */
                _n(
                    'Put the previous figures and deleted entries back? This restores %s row exactly as it was before the cleanup.',
                    'Put the previous figures and deleted entries back? This restores %s rows exactly as they were before the cleanup.',
                    $undoable,
                    'brikpanel'
                ),
                brikpanel_number( $undoable )
            ),
        ];

        $r['message']         = '';
        $r['recommendations'] = [];
        $r['metadata']        = 'page' === $context ? $undo : [];

        if ( ! empty( $f['no_history'] ) ) {
            $r['summary'] = __( 'No analytics history has been recorded yet.', 'brikpanel' );
            return $r;
        }

        $impossible = (int) ( $f['impossible'] ?? 0 );
        $extreme    = (int) ( $f['extreme'] ?? 0 );
        $cartab     = (int) ( $f['cartab'] ?? 0 );
        $overcount  = (int) ( $f['overcount'] ?? 0 );
        $window     = (int) ( $f['window'] ?? self::DEFAULT_WINDOW_DAYS );
        $flagged    = $impossible + $extreme;
        $total      = $flagged + $cartab;

        // Scripted abandoned-cart entries alone leave the card OK (their own
        // check grades them), so they are mentioned as something the cleanup
        // can also take care of, not as bot traffic found here (field test F2).
        $cartab_check = ( $cartab > 0 && class_exists( 'Brikpanel_BrikControl_Registry' ) ) ? Brikpanel_BrikControl_Registry::get( 'cartab_bot_rows' ) : null;

        if ( $flagged > 0 ) {
            // Only what was found, each with its own plural: this used to read
            // "0 day(s) of figures and 10 abandoned-cart entries look like bot
            // traffic" (field test E7).
            $found = [
                brikpanel_safe_sprintf(
                    /* translators: %s: number of daily figures (one figure on one day, e.g. that day's visitors). */
                    _n( '%s daily figure', '%s daily figures', $flagged, 'brikpanel' ),
                    brikpanel_number( $flagged )
                ),
            ];
            if ( $cartab > 0 ) {
                $found[] = brikpanel_safe_sprintf(
                    /* translators: %s: number of abandoned-cart entries. */
                    _n( '%s abandoned-cart entry', '%s abandoned-cart entries', $cartab, 'brikpanel' ),
                    brikpanel_number( $cartab )
                );
            }
            $r['summary'] = brikpanel_safe_sprintf(
                /* translators: %s: what was found, e.g. "3 daily figures and 10 abandoned-cart entries". */
                __( 'Looks like bot traffic: %s', 'brikpanel' ),
                wp_sprintf_l( '%l', $found )
            );
        } elseif ( $cartab > 0 ) {
            $r['summary'] = brikpanel_safe_sprintf(
                /* translators: %s: number of abandoned-cart entries written by a script. */
                _n(
                    'Your daily figures look real. %s scripted abandoned-cart entry can be cleaned up.',
                    'Your daily figures look real. %s scripted abandoned-cart entries can be cleaned up.',
                    $cartab,
                    'brikpanel'
                ),
                brikpanel_number( $cartab )
            );
        } else {
            $r['summary'] = brikpanel_safe_sprintf(
                /* translators: %s: number of days examined. */
                _n( 'No bot traffic found in the last %s day.', 'No bot traffic found in the last %s days.', $window, 'brikpanel' ),
                brikpanel_number( $window )
            );
        }

        if ( 'page' !== $context ) {
            return $r;
        }

        if ( $flagged > 0 ) {
            $parts = [];
            if ( $impossible > 0 ) {
                $parts[] = brikpanel_safe_sprintf(
                    /* translators: %s: number of daily figures. */
                    _n(
                        '%s daily figure counted more add-to-carts than the store had visitors that day, which cannot be real.',
                        '%s daily figures counted more add-to-carts than the store had visitors that day, which cannot be real.',
                        $impossible,
                        'brikpanel'
                    ),
                    brikpanel_number( $impossible )
                );
            }
            if ( $extreme > 0 ) {
                $parts[] = brikpanel_safe_sprintf(
                    /* translators: 1: number of daily figures, 2: how many times above normal, e.g. "10", 3: smallest daily count the rule looks at. */
                    _n(
                        '%1$s daily figure sits at least %2$s times above its own normal and counted at least %3$s events. Automated traffic leaves this shape behind, though a real promotion can look like it too.',
                        '%1$s daily figures sit at least %2$s times above their own normal and counted at least %3$s events. Automated traffic leaves this shape behind, though a real promotion can look like it too.',
                        $extreme,
                        'brikpanel'
                    ),
                    brikpanel_number( $extreme ),
                    brikpanel_number( (float) ( $f['multiplier'] ?? self::DEFAULT_MULTIPLIER ), 1, true ),
                    brikpanel_number( (int) ( $f['floor'] ?? self::DEFAULT_FLOOR ) )
                );
            }
            if ( $cartab > 0 ) {
                $parts[] = $cartab_check
                    ? brikpanel_safe_sprintf(
                        /* translators: 1: number of entries, 2: name of another check on the Store Health page, e.g. "Abandoned cart entries". */
                        _n(
                            '%1$s abandoned-cart entry was almost certainly written by a script (the "%2$s" check explains why).',
                            '%1$s abandoned-cart entries were almost certainly written by a script (the "%2$s" check explains why).',
                            $cartab,
                            'brikpanel'
                        ),
                        brikpanel_number( $cartab ),
                        $cartab_check->get_label()
                    )
                    : brikpanel_safe_sprintf(
                        /* translators: %s: number of entries. */
                        _n(
                            '%s abandoned-cart entry was almost certainly written by a script.',
                            '%s abandoned-cart entries were almost certainly written by a script.',
                            $cartab,
                            'brikpanel'
                        ),
                        brikpanel_number( $cartab )
                    );
            }
            $parts[]      = __( 'The cleanup lowers each flagged figure to the highest value it could honestly have had, scales that day\'s traffic sources and device counts to match, deletes the flagged entries, and keeps everything it changed so one click puts it back.', 'brikpanel' );
            $r['message'] = implode( ' ', $parts );
        } elseif ( $cartab > 0 ) {
            $r['message'] = $cartab_check
                ? brikpanel_safe_sprintf(
                    /* translators: %s: name of another check on the Store Health page, e.g. "Abandoned cart entries". */
                    __( 'The "%s" check explains how a scripted entry is recognised. The cleanup deletes scripted entries and keeps a copy of each, so one click puts them back.', 'brikpanel' ),
                    $cartab_check->get_label()
                )
                : __( 'The cleanup deletes scripted entries and keeps a copy of each, so one click puts them back.', 'brikpanel' );
        } else {
            $r['message'] = __( 'No day counted more add-to-carts than the store had visitors, no figure stands far above its own normal, and no abandoned-cart entry looks scripted.', 'brikpanel' );
        }

        // The nightly cleanup leads (3.3.30): most of what this card used to
        // ask about is now done without a click.
        $auto_line = $this->auto_sentence( isset( $f['auto'] ) && is_array( $f['auto'] ) ? $f['auto'] : [] );
        if ( $flagged > 0 ) {
            $auto_line .= ' ' . __( 'Days still being counted, days you put back with Undo and days when sales rose too, which can be a real promotion, are left for you to decide.', 'brikpanel' );
        }
        $r['message'] = trim( $auto_line . ' ' . $r['message'] );

        if ( ! empty( $f['truncated'] ) && $flagged > 0 ) {
            $r['message'] = trim(
                $r['message'] . ' ' . brikpanel_safe_sprintf(
                    /* translators: %s: number of products or pages. */
                    __( 'More products or pages are affected than one pass covers; these are the worst %s. Run the check again after cleaning them to see the rest.', 'brikpanel' ),
                    brikpanel_number( (int) ( $f['max_series'] ?? self::MAX_CANDIDATE_SERIES ) )
                )
            );
        }

        // About flagged days only: a red "open the list first" beside an OK
        // card with nothing but scripted cart entries would raise an alarm
        // the status does not.
        if ( $flagged > 0 ) {
            $r['recommendations'][] = [
                'text'     => __( 'Open the list below first. The cleanup rewrites your own analytics history, and a real campaign day can look like a robot day. You can undo it afterwards.', 'brikpanel' ),
                'priority' => 'high',
            ];
            if ( class_exists( 'Brikpanel_BrikControl' ) ) {
                $r['recommendations'][] = Brikpanel_BrikControl::exclusion_recommendation();
            }
        }

        // Four figures, one line each: a fifth tile ("Days scanned") pushed two
        // labels onto a second line and left the row ragged (field test E7).
        // The window now sits in the card's footer.
        $r['metadata'] = array_merge(
            $undo,
            [
                'fixable'       => $total,
                'fix_confirm'   => $this->fix_confirm_text( $flagged, $overcount, $cartab ),
                'scope'         => brikpanel_safe_sprintf(
                    /* translators: %s: number of days of analytics history examined. */
                    _n( 'Last %s day checked', 'Last %s days checked', $window, 'brikpanel' ),
                    brikpanel_number( $window )
                ),
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
                        // Never louder than the card: these do not grade it.
                        'tone'  => ( $cartab > 0 && $flagged > 0 ) ? 'warn' : '',
                    ],
                    [
                        'label' => __( 'Events to be removed', 'brikpanel' ),
                        'value' => $overcount,
                        'tone'  => $overcount > 0 ? 'warn' : '',
                    ],
                ],
                'samples'       => $this->build_samples( (array) ( $f['samples'] ?? [] ), $cartab ),
                'samples_title' => __( 'What would change', 'brikpanel' ),
                'samples_cols'  => [
                    __( 'Date', 'brikpanel' ),
                    __( 'What', 'brikpanel' ),
                    __( 'Recorded', 'brikpanel' ),
                    __( 'Corrected to', 'brikpanel' ),
                    __( 'Why', 'brikpanel' ),
                ],
            ]
        );

        return $r;
    }

    /**
     * @param int $flagged   Daily figures that would change.
     * @param int $overcount Recorded events that would disappear.
     * @param int $cartab    Abandoned-cart entries that would be deleted.
     * @return string
     */
    private function fix_confirm_text( $flagged, $overcount, $cartab ) {
        $parts = [ __( 'Clean up bot traffic?', 'brikpanel' ) ];
        if ( $flagged > 0 ) {
            $parts[] = brikpanel_safe_sprintf(
                /* translators: %s: number of daily figures. */
                _n( '%s daily figure is lowered.', '%s daily figures are lowered.', (int) $flagged, 'brikpanel' ),
                brikpanel_number( (int) $flagged )
            );
            if ( $overcount > 0 ) {
                $parts[] = brikpanel_safe_sprintf(
                    /* translators: %s: number of recorded events. */
                    _n( 'That removes about %s recorded event.', 'That removes about %s recorded events.', (int) $overcount, 'brikpanel' ),
                    brikpanel_number( (int) $overcount )
                );
            }
        }
        if ( $cartab > 0 ) {
            $parts[] = brikpanel_safe_sprintf(
                /* translators: %s: number of abandoned-cart entries. */
                _n( '%s abandoned-cart entry is deleted.', '%s abandoned-cart entries are deleted.', (int) $cartab, 'brikpanel' ),
                brikpanel_number( (int) $cartab )
            );
        }
        $parts[] = __( 'Your products, orders and customers are not touched. Everything changed is kept in a restore point so you can undo it at any time.', 'brikpanel' );
        return implode( ' ', $parts );
    }

    /**
     * @param array $outcome run_fix() result.
     * @return string
     */
    public function bc_fix_done( array $outcome ) {
        $figures = (int) ( $outcome['figures'] ?? 0 );
        $entries = (int) ( $outcome['entries'] ?? 0 );
        $done    = [];
        if ( $figures > 0 ) {
            $done[] = brikpanel_safe_sprintf(
                /* translators: %s: number of daily figures lowered. */
                _n( '%s daily figure lowered', '%s daily figures lowered', $figures, 'brikpanel' ),
                brikpanel_number( $figures )
            );
        }
        if ( $entries > 0 ) {
            $done[] = brikpanel_safe_sprintf(
                /* translators: %s: number of abandoned-cart entries deleted. */
                _n( '%s abandoned-cart entry deleted', '%s abandoned-cart entries deleted', $entries, 'brikpanel' ),
                brikpanel_number( $entries )
            );
        }
        if ( empty( $done ) ) {
            return __( 'Nothing needed cleaning up.', 'brikpanel' );
        }
        return brikpanel_safe_sprintf(
            /* translators: %s: what the cleanup did, e.g. "3 daily figures lowered and 10 abandoned-cart entries deleted". */
            __( 'Done: %s.', 'brikpanel' ),
            wp_sprintf_l( '%l', $done )
        );
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
            'samples'    => $this->sample_findings( $findings ),
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
        $this->last_daily = $daily;

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
            'visitors' => [ 'col' => 'visitor_count' ],
            'product'  => [ 'col' => 'product_count' ],
            'atc'      => [ 'col' => 'add_to_cart_count' ],
            'checkout' => [ 'col' => 'checkout_count' ],
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
                    'visitors', $day['id'], $meta['col'], $date,
                    $day[ $key ], $ceiling, $normal
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

        $is_page = ( 'page' === $source );
        if ( $is_page ) {
            $s['multiplier'] *= self::PAGE_MULTIPLIER_FACTOR;
            $s['floor']      *= self::PAGE_FLOOR_FACTOR;
        }

        // A page series is a post OR a term: the same number can be both, and
        // until 3.3.30 their days were added up into one series. The series
        // key carries the object type for pages ("post:12", "term:12").
        $series_expr = $is_page ? "CONCAT(object_type, ':', {$id_col})" : $id_col;
        $group_expr  = $is_page ? "{$id_col}, object_type" : $id_col;

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
        $keys = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT sid
                   FROM (
                        SELECT sid, MAX(daily) AS worst
                          FROM (
                                SELECT {$series_expr} AS sid, DATE(date_column) AS day, SUM({$val_col}) AS daily
                                  FROM {$table}
                                 WHERE date_column >= %s
                                 GROUP BY {$group_expr}, DATE(date_column)
                                HAVING daily >= %d
                               ) d
                         GROUP BY sid
                        ) p
                  ORDER BY worst DESC
                  LIMIT %d",
                $s['from'] . ' 00:00:00',
                $threshold,
                self::MAX_CANDIDATE_SERIES + 1
            )
        );

        // Normalised series keys: "type:id" for pages, the bare id otherwise.
        $wanted = [];
        foreach ( (array) $keys as $key ) {
            if ( $is_page ) {
                if ( preg_match( '/^(post|term):([1-9][0-9]*)$/', (string) $key, $m ) ) {
                    $wanted[ $m[1] . ':' . (int) $m[2] ] = (int) $m[2];
                }
            } elseif ( absint( $key ) > 0 ) {
                $wanted[ (string) absint( $key ) ] = absint( $key );
            }
        }

        $truncated = count( $wanted ) > self::MAX_CANDIDATE_SERIES;
        if ( $truncated ) {
            $wanted = array_slice( $wanted, 0, self::MAX_CANDIDATE_SERIES, true );
        }
        if ( empty( $wanted ) ) {
            return [ 'findings' => [], 'truncated' => false ];
        }

        $findings = [];
        foreach ( array_chunk( $wanted, self::SERIES_CHUNK, true ) as $chunk ) {
            $list = implode( ',', array_unique( array_map( 'absint', array_values( $chunk ) ) ) );

            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- internal names, absint()-mapped list, bound value prepared.
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT {$series_expr} AS sid, DATE(date_column) AS day, SUM({$val_col}) AS total
                       FROM {$table}
                      WHERE {$id_col} IN ({$list})
                        AND date_column >= %s
                      GROUP BY {$group_expr}, DATE(date_column)",
                    $s['from'] . ' 00:00:00'
                ),
                ARRAY_A
            );

            $series = [];
            foreach ( (array) $rows as $row ) {
                $sid = (string) $row['sid'];
                if ( ! isset( $chunk[ $sid ] ) ) {
                    continue; // The other object type under the same number.
                }
                $series[ $sid ][ (string) $row['day'] ] = (int) $row['total'];
            }

            foreach ( $series as $sid => $days ) {
                $ref   = (int) $chunk[ $sid ];
                $otype = $is_page ? strtok( $sid, ':' ) : '';

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
                        $source, $ref, $val_col, $date,
                        $total, $ceiling, $normal
                    );
                    if ( $finding ) {
                        if ( $is_page ) {
                            $finding['otype'] = $otype;
                        }
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
    private function weigh( $source, $ref, $column, $date, $current, $ceiling, $normal ) {
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
            'current' => (int) $current,
            // Never raise a figure, whatever the arithmetic says.
            'target'  => $target,
            'rule'    => ( null !== $ceiling ) ? 'impossible' : 'extreme',
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
     *
     * @param string $source 'cart' or 'page'.
     * @param int    $id     Product, post or term id.
     * @param string $otype  For pages: 'post', 'term', or '' when unknown.
     */
    private function object_label( $source, $id, $otype = '' ) {
        $id = (int) $id;
        if ( 'page' === $source && 'term' === $otype ) {
            $term = get_term( $id );
            if ( $term instanceof WP_Term ) {
                return brikpanel_plain_name( $term->name );
            }
        }
        $title = 'term' === $otype ? '' : get_the_title( $id );
        if ( is_string( $title ) && '' !== trim( $title ) ) {
            return brikpanel_plain_label( $title );
        }
        if ( 'page' === $source ) {
            $term = '' === $otype ? get_term( $id ) : null;
            if ( $term instanceof WP_Term ) {
                return $term->name;
            }
            return brikpanel_safe_sprintf(
                /* translators: %s: page ID. */
                __( 'Page #%s', 'brikpanel' ),
                (string) $id
            );
        }
        return brikpanel_safe_sprintf(
            /* translators: %s: product ID of a product that no longer exists. */
            __( 'Product #%s', 'brikpanel' ),
            (string) $id
        );
    }

    /**
     * The findings the card lists, worst first, as data.
     *
     * @param array $findings Sorted findings.
     * @return array
     */
    private function sample_findings( array $findings ) {
        $out = [];
        foreach ( array_slice( $findings, 0, self::SAMPLE_LIMIT ) as $finding ) {
            $out[] = [
                'source'  => (string) $finding['source'],
                'ref'     => (int) $finding['ref'],
                'otype'   => (string) ( $finding['otype'] ?? '' ),
                'column'  => (string) $finding['column'],
                'date'    => (string) $finding['date'],
                'current' => (int) $finding['current'],
                'target'  => (int) $finding['target'],
                'rule'    => (string) $finding['rule'],
            ];
        }
        return $out;
    }

    /**
     * Detail rows for the card table, in the viewer's language.
     *
     * @param array $findings sample_findings() output.
     * @param int   $cartab   Scripted abandoned-cart entries.
     * @return array
     */
    private function build_samples( array $findings, $cartab ) {
        $ids = [];
        foreach ( $findings as $finding ) {
            if ( is_array( $finding ) && 'visitors' !== ( $finding['source'] ?? '' ) ) {
                $ids[] = (int) ( $finding['ref'] ?? 0 );
            }
        }
        $ids = array_values( array_filter( array_unique( $ids ) ) );
        if ( ! empty( $ids ) ) {
            _prime_post_caches( $ids, false, false );
        }

        $series = [
            'visitor_count'     => __( 'Store visitors', 'brikpanel' ),
            'product_count'     => __( 'Product views', 'brikpanel' ),
            'add_to_cart_count' => __( 'Store add-to-carts', 'brikpanel' ),
            'checkout_count'    => __( 'Checkout visits', 'brikpanel' ),
        ];

        $samples = [];
        if ( $cartab > 0 ) {
            $cartab_check = class_exists( 'Brikpanel_BrikControl_Registry' ) ? Brikpanel_BrikControl_Registry::get( 'cartab_bot_rows' ) : null;
            $samples[]    = [
                '',
                __( 'Abandoned-cart entries written by scripts', 'brikpanel' ),
                brikpanel_number( $cartab ),
                __( 'Deleted', 'brikpanel' ),
                $cartab_check
                    ? brikpanel_safe_sprintf(
                        /* translators: %s: name of another check on the Store Health page, e.g. "Abandoned cart entries". */
                        __( 'Certain, by the "%s" check', 'brikpanel' ),
                        $cartab_check->get_label()
                    )
                    : __( 'Certain', 'brikpanel' ),
            ];
        }

        foreach ( $findings as $finding ) {
            if ( ! is_array( $finding ) ) {
                continue;
            }
            $source = (string) ( $finding['source'] ?? '' );
            $ref    = (int) ( $finding['ref'] ?? 0 );
            switch ( $source ) {
                case 'cart':
                    $label = brikpanel_safe_sprintf(
                        /* translators: %s: product name. */
                        __( 'Add-to-carts: %s', 'brikpanel' ),
                        $this->object_label( 'cart', $ref )
                    );
                    break;
                case 'page':
                    $label = brikpanel_safe_sprintf(
                        /* translators: %s: page or product name. */
                        __( 'Page views: %s', 'brikpanel' ),
                        $this->object_label( 'page', $ref, (string) ( $finding['otype'] ?? '' ) )
                    );
                    break;
                default:
                    $label = $series[ (string) ( $finding['column'] ?? '' ) ] ?? (string) ( $finding['column'] ?? '' );
            }

            if ( 'impossible' === ( $finding['rule'] ?? '' ) ) {
                $why = ( 'visitors' === $source )
                    ? __( 'More adds than visitors', 'brikpanel' )
                    : __( 'More adds than store visitors', 'brikpanel' );
            } else {
                $why = __( 'Far above its own normal', 'brikpanel' );
            }

            $samples[] = [
                $this->local_day_label( (string) ( $finding['date'] ?? '' ) ),
                $label,
                brikpanel_number( (int) ( $finding['current'] ?? 0 ) ),
                brikpanel_number( (int) ( $finding['target'] ?? 0 ) ),
                $why,
            ];
        }

        return $samples;
    }

    /**
     * A store-local calendar day (the counters write local dates) in the
     * store's short date format with the viewer's month names.
     *
     * @param string $day 'Y-m-d'.
     * @return string
     */
    private function local_day_label( $day ) {
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $day ) ) {
            return $day;
        }
        try {
            // Noon, so no timezone shift can move it onto another day.
            $dt = new DateTimeImmutable( $day . ' 12:00:00', wp_timezone() );
        } catch ( Exception $e ) {
            return $day;
        }
        return (string) wp_date( brikpanel_short_date_format(), $dt->getTimestamp() );
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

        return $this->apply_findings( (array) $this->scan_findings() );
    }

    /**
     * Correct the given findings and delete the certainly scripted
     * abandoned-cart entries, writing the restore point first. Shared by the
     * button and the nightly run.
     *
     * @param array $findings Findings from scan_findings(), worst first.
     * @return array { removed: int, figures: int, entries: int, has_more: bool, message: string }
     */
    private function apply_findings( array $findings ) {
        $has_more = count( $findings ) > self::FIX_CHUNK;
        $batch    = array_slice( $findings, 0, self::FIX_CHUNK );

        $removed = 0;
        $figures = 0;
        $entries = 0;
        $full    = false;
        $applied = [];

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
                $this->remember_applied( $applied );
                return [
                    'removed'  => $removed,
                    'figures'  => $figures,
                    'entries'  => $entries,
                    'has_more' => true,
                    'message'  => __( 'The restore point could not be written, so nothing more was changed.', 'brikpanel' ),
                ];
            }
            foreach ( $planned as $entry ) {
                $this->apply_value( $entry );
            }
            $removed  += max( 0, $finding['current'] - $finding['target'] );
            $applied[] = $this->finding_key( $finding );
            $figures++;
        }
        $this->remember_applied( $applied );

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
                    $entries += $deleted;
                }
            }
        }

        // The Live view is a 2-minute transient of whoever pinged last; a
        // crawl that is still running simply refills it, and one that stopped
        // should not linger. Left alone when nothing changed: the nightly run
        // must not empty the Live list just to find there was nothing to do.
        if ( $figures + $entries > 0 ) {
            delete_transient( 'brikpanel_live_visitors' );
            if ( function_exists( 'brikpanel_bust_data_caches' ) ) {
                brikpanel_bust_data_caches();
            }
        }

        return [
            'removed'  => $removed,
            'figures'  => $figures,
            'entries'  => $entries,
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

        // A page finding names its object type (3.3.30), so a post and a term
        // that share a number are never corrected together. Findings stored
        // before that carry none and fall back to the old, untyped match.
        $otype = ( ! $is_cart && isset( $finding['otype'] ) && in_array( $finding['otype'], [ 'post', 'term' ], true ) ) ? $finding['otype'] : '';
        $where = '' !== $otype ? $wpdb->prepare( ' AND object_type = %s', $otype ) : '';

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- internal names; bound values prepared ($where is prepared above).
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, {$val_col} AS v
                   FROM {$table}
                  WHERE {$id_col} = %d
                    AND date_column BETWEEN %s AND %s{$where}
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
     * Nightly cleanup (3.3.30)
     * ------------------------------------------------------------------ */

    /**
     * Register the nightly job. Hooked to brikpanel_cron_register, which only
     * runs while Store Health is on (its bootstrap stands the hook down
     * otherwise).
     *
     * @return void
     */
    public static function register_autoclean() {
        if ( ! class_exists( 'Brikpanel_Cron' ) ) {
            return;
        }
        Brikpanel_Cron::register_handler(
            self::AUTO_HOOK,
            [ __CLASS__, 'cron_autoclean' ],
            static function () {
                return [
                    'label'       => __( 'Bot traffic cleanup', 'brikpanel' ),
                    'description' => __( 'Every night, lowers the analytics figures bots inflated and deletes scripted abandoned-cart entries, keeping a restore point.', 'brikpanel' ),
                ];
            }
        );
        // About 04:00 store time, every day. Only the offset may carry time:
        // the reconcile fingerprint must not change from one request to the next.
        try {
            $next = ( new DateTimeImmutable( 'tomorrow 04:00', wp_timezone() ) )->getTimestamp();
        } catch ( Exception $e ) {
            $next = time() + DAY_IN_SECONDS;
        }
        Brikpanel_Cron::schedule_recurring( self::AUTO_HOOK, DAY_IN_SECONDS, [], max( 60, $next - time() ) );
    }

    /**
     * Action Scheduler handler: clean, then save fresh cards for this check
     * and the abandoned-cart one, so the page and the top bar shield show
     * what is left.
     *
     * @param array $payload Unused.
     * @return void
     */
    public static function cron_autoclean( $payload = [] ) {
        if ( ! class_exists( 'Brikpanel_BrikControl_Registry' ) ) {
            return;
        }
        $check = Brikpanel_BrikControl_Registry::get( 'bot_traffic' );
        if ( ! $check instanceof self ) {
            return;
        }
        $check->run_auto_cleanup();

        if ( class_exists( 'Brikpanel_BrikControl_Storage' ) ) {
            Brikpanel_BrikControl_Storage::save_check_result( 'bot_traffic', $check->run( [] ) );
            $linked = Brikpanel_BrikControl_Registry::get( 'cartab_bot_rows' );
            if ( $linked && ! $linked->supports_batching() ) {
                Brikpanel_BrikControl_Storage::save_check_result( 'cartab_bot_rows', $linked->run( [] ) );
            }
        }
    }

    /**
     * Clean what can be cleaned without asking.
     *
     * Only days that are over (today is still being counted), never a day the
     * merchant put back with Undo, and only what cannot be a real promotion:
     * an "impossible" day, a store-wide spike that sales did not follow, a
     * product added to carts far above normal but hardly bought that day, or
     * a page viewed more than three times per visitor the whole store had.
     * Everything else stays listed for the merchant, as before. Every change
     * goes through the same restore point as the button.
     *
     * @return array { figures, entries, removed, left } or { busy: true }.
     */
    public function run_auto_cleanup() {
        $id = $this->get_id();
        if ( get_transient( 'brikpanel_bc_undo_' . $id ) || get_transient( 'brikpanel_bc_fix_' . $id ) ) {
            return [ 'busy' => true ];
        }
        set_transient( 'brikpanel_bc_fix_' . $id, 1, 10 * MINUTE_IN_SECONDS );

        $totals = [ 'figures' => 0, 'entries' => 0, 'removed' => 0, 'left' => 0 ];
        try {
            $today  = wp_date( 'Y-m-d' );
            $orders = null;
            for ( $round = 0; $round < self::AUTO_ROUNDS; $round++ ) {
                $findings = (array) $this->scan_findings();
                $kept     = $this->read_keys( self::KEPT_OPTION );
                $safe     = [];
                foreach ( $findings as $finding ) {
                    if ( (string) $finding['date'] >= $today ) {
                        continue;
                    }
                    if ( isset( $kept[ $this->finding_key( $finding ) ] ) ) {
                        continue;
                    }
                    if ( $this->is_auto_safe( $finding, $orders ) ) {
                        $safe[] = $finding;
                    }
                }
                $totals['left'] = count( $findings ) - count( $safe );

                $outcome = $this->apply_findings( $safe );
                $totals['figures'] += (int) ( $outcome['figures'] ?? 0 );
                $totals['entries'] += (int) ( $outcome['entries'] ?? 0 );
                $totals['removed'] += (int) ( $outcome['removed'] ?? 0 );

                // Stop when nothing more can move: the batch was the last one,
                // or the restore point refused.
                if ( empty( $outcome['has_more'] ) || '' !== (string) ( $outcome['message'] ?? '' ) ) {
                    break;
                }
            }
        } finally {
            delete_transient( 'brikpanel_bc_fix_' . $id );
        }

        update_option( self::AUTO_LOG_OPTION, [ 'time' => time() ] + $totals, false );
        return $totals;
    }

    /**
     * Whether a finding may be corrected without the merchant looking first.
     *
     * @param array      $finding Finding.
     * @param array|null $orders  Paid orders per day, loaded on first need
     *                            (false when they could not be read).
     * @return bool
     */
    private function is_auto_safe( array $finding, &$orders ) {
        if ( 'impossible' === $finding['rule'] ) {
            return true;
        }

        switch ( $finding['source'] ) {
            case 'visitors':
                // A real campaign day brings orders with it; a bot day does not.
                if ( null === $orders ) {
                    $orders = $this->paid_orders_by_day();
                }
                if ( ! is_array( $orders ) ) {
                    return false;
                }
                $day = (int) ( $orders['days'][ (string) $finding['date'] ] ?? 0 );
                return $day < max( 3, 3 * (float) $orders['median'] );

            case 'cart':
                $sold = $this->product_orders_on_day( (int) $finding['ref'], (string) $finding['date'] );
                return null !== $sold && $sold <= 2;

            case 'page':
                $visitors = isset( $this->last_daily[ (string) $finding['date'] ] ) ? (int) $this->last_daily[ (string) $finding['date'] ]['visitors'] : 0;
                return $visitors >= self::MIN_CREDIBLE_VISITORS && (int) $finding['current'] > $visitors * 3;
        }

        return false;
    }

    /**
     * Paid orders per store day over the scan window, and their median.
     *
     * Read from WooCommerce's own order table (HPOS or posts), grouped by
     * hour in UTC and folded into store days here, so a day boundary in the
     * store's timezone (and its daylight saving changes) lands where the
     * counters put it.
     *
     * @return array{days:array<string,int>,median:float}|false
     */
    private function paid_orders_by_day() {
        global $wpdb;

        $statuses = function_exists( 'brikpanel_paid_order_statuses' ) ? array_values( (array) brikpanel_paid_order_statuses() ) : [ 'wc-processing', 'wc-completed' ];
        if ( empty( $statuses ) ) {
            return false;
        }

        $s    = $this->settings();
        $from = get_gmt_from_date( $s['from'] . ' 00:00:00' );
        $in   = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );

        if ( 'yes' === get_option( 'woocommerce_custom_orders_table_enabled' ) ) {
            $table = $wpdb->prefix . 'wc_orders';
            if ( ! $this->table_exists( $table ) ) {
                return false;
            }
            $sql = "SELECT DATE_FORMAT(date_created_gmt, '%%Y-%%m-%%d %%H') AS h, COUNT(*) AS c
                      FROM {$table}
                     WHERE type = 'shop_order' AND status IN ({$in}) AND date_created_gmt >= %s
                     GROUP BY h";
        } else {
            $sql = "SELECT DATE_FORMAT(post_date_gmt, '%%Y-%%m-%%d %%H') AS h, COUNT(*) AS c
                      FROM {$wpdb->posts}
                     WHERE post_type = 'shop_order' AND post_status IN ({$in}) AND post_date_gmt >= %s
                     GROUP BY h";
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- internal table names; statuses and date are placeholders.
        $rows = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $statuses, [ $from ] ) ), ARRAY_A );
        if ( ! is_array( $rows ) || '' !== (string) $wpdb->last_error ) {
            return false;
        }

        $days = [];
        foreach ( $rows as $row ) {
            $ts = strtotime( $row['h'] . ':00:00 UTC' );
            if ( false === $ts ) {
                continue;
            }
            $day          = wp_date( 'Y-m-d', $ts );
            $days[ $day ] = ( $days[ $day ] ?? 0 ) + (int) $row['c'];
        }

        // Median over every day of the window, days without an order included.
        $values = [];
        try {
            $cursor = new DateTimeImmutable( $s['from'] . ' 12:00:00', wp_timezone() );
            $end    = wp_date( 'Y-m-d' );
            for ( $i = 0; $i < 3660; $i++ ) {
                $day = $cursor->format( 'Y-m-d' );
                if ( $day >= $end ) {
                    break;
                }
                $values[] = (int) ( $days[ $day ] ?? 0 );
                $cursor   = $cursor->modify( '+1 day' );
            }
        } catch ( Exception $e ) {
            $values = array_values( $days );
        }

        return [ 'days' => $days, 'median' => $this->median( $values ) ];
    }

    /**
     * Paid orders that contained a product on one store day, from
     * WooCommerce Analytics' product lookup table.
     *
     * @param int    $product_id Parent product.
     * @param string $date       Y-m-d (store day).
     * @return int|null Null when the lookup table is missing or was never
     *                  filled (then a count of zero would prove nothing).
     */
    private function product_orders_on_day( $product_id, $date ) {
        global $wpdb;
        static $usable = null;

        $lookup = $wpdb->prefix . 'wc_order_product_lookup';
        if ( null === $usable ) {
            $usable = $this->table_exists( $lookup )
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- internal table name.
                && (bool) $wpdb->get_var( "SELECT 1 FROM {$lookup} LIMIT 1" );
        }
        if ( ! $usable || $product_id <= 0 || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
            return null;
        }

        $statuses = function_exists( 'brikpanel_paid_order_statuses' ) ? array_values( (array) brikpanel_paid_order_statuses() ) : [ 'wc-processing', 'wc-completed' ];
        if ( empty( $statuses ) ) {
            return null;
        }
        $in = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );

        if ( 'yes' === get_option( 'woocommerce_custom_orders_table_enabled' ) ) {
            $join = "INNER JOIN {$wpdb->prefix}wc_orders o ON o.id = l.order_id AND o.status IN ({$in})";
        } else {
            $join = "INNER JOIN {$wpdb->posts} o ON o.ID = l.order_id AND o.post_status IN ({$in})";
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- internal table names; values are placeholders.
        $count = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(DISTINCT l.order_id)
                   FROM {$lookup} l
                   {$join}
                  WHERE l.product_id = %d
                    AND l.date_created BETWEEN %s AND %s",
                array_merge( $statuses, [ $product_id, $date . ' 00:00:00', $date . ' 23:59:59' ] )
            )
        );

        return null === $count ? null : (int) $count;
    }

    /**
     * A finding's stable identity: what was corrected on which day.
     *
     * @param array $finding Finding.
     * @return string
     */
    private function finding_key( array $finding ) {
        $date = (string) ( $finding['date'] ?? '' );
        if ( 'visitors' === ( $finding['source'] ?? '' ) ) {
            return 'visitors|' . (string) ( $finding['column'] ?? '' ) . '|' . $date;
        }
        $otype = (string) ( $finding['otype'] ?? '' );
        return (string) ( $finding['source'] ?? '' ) . '|' . ( '' !== $otype ? $otype . ':' : '' ) . (int) ( $finding['ref'] ?? 0 ) . '|' . $date;
    }

    /**
     * A key list option as a set, without keys older than the scan window
     * (those days are never looked at again).
     *
     * @param string $option Option name.
     * @return array<string, true>
     */
    private function read_keys( $option ) {
        $stored = get_option( $option, [] );
        if ( ! is_array( $stored ) ) {
            return [];
        }
        $from = $this->settings()['from'];
        $set  = [];
        foreach ( $stored as $key ) {
            $key  = (string) $key;
            $date = substr( $key, -10 );
            if ( $date >= $from ) {
                $set[ $key ] = true;
            }
        }
        return $set;
    }

    /**
     * Store a key set, newest last, capped.
     *
     * @param string $option Option name.
     * @param array  $set    Keys as array keys.
     * @return void
     */
    private function write_keys( $option, array $set ) {
        $keys = array_keys( $set );
        if ( count( $keys ) > self::KEYS_CAP ) {
            $keys = array_slice( $keys, -self::KEYS_CAP );
        }
        if ( empty( $keys ) ) {
            delete_option( $option );
            return;
        }
        update_option( $option, $keys, false );
    }

    /**
     * Note which findings a cleanup changed, so an undo knows what the
     * merchant chose to keep.
     *
     * @param string[] $keys finding_key() values.
     * @return void
     */
    private function remember_applied( array $keys ) {
        if ( empty( $keys ) ) {
            return;
        }
        $set = $this->read_keys( self::APPLIED_OPTION );
        foreach ( $keys as $key ) {
            $set[ (string) $key ] = true;
        }
        $this->write_keys( self::APPLIED_OPTION, $set );
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

        // What was just put back is what the merchant wants to keep: the
        // nightly run must not lower those days again (3.3.30).
        $applied = $this->read_keys( self::APPLIED_OPTION );
        if ( ! empty( $applied ) ) {
            $this->write_keys( self::KEPT_OPTION, $this->read_keys( self::KEPT_OPTION ) + $applied );
            delete_option( self::APPLIED_OPTION );
        }

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
}
