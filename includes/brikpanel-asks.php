<?php
/**
 * BrikPanel: one ask at a time.
 *
 * An "ask" is anything BrikPanel puts in front of a merchant that is not the
 * result of their own action: the welcome tour, the new-store guide, the
 * survey card, the review request, BrikMentor's announcement and its cards.
 * Each of them used to decide on its own, so a merchant could close the tour
 * and meet the BrikMentor announcement on the very next page, or find the
 * review request, a BrikMentor card and the newsletter card on one dashboard.
 *
 * The rules (owner decisions, 6 October 2026):
 *  1. At most one ask on screen, anywhere in the plugin.
 *  2. The welcome tour comes first. While it waits, nothing else shows.
 *  3. The tour and the new-store guide are the first-run pair: the guide may
 *     follow the tour straight away. Every other ask waits 7 days after the
 *     previous one closed, and a BrikMentor ask waits 30 days after the
 *     previous BrikMentor ask.
 *  4. A shown ask stays until it is closed; a more important one never pushes
 *     it away. One nobody closes retires 30 days after it was first shown. One
 *     that was picked but never shown for 7 days (its screen was never opened)
 *     steps aside without starting a quiet period.
 *  5. Order: guide, phone notifications card (only on a phone), survey,
 *     review, BrikMentor announcement, BrikMentor dashboard card, BrikMentor
 *     Customer Analytics card, BrikMentor card at the bottom of the dashboard.
 *  6. When another notice box is on screen (an error, a "saved" message,
 *     BrikPanel's own system notices) no ask shows on that page view.
 *  7. No asks for users BrikPanel is switched off for, in Network Admin, on
 *     plugin, update and editor screens, or during AJAX, cron and REST.
 *
 * Every surface asks brikpanel_ask_allows( $id ) before it prints, calls
 * brikpanel_ask_shown( $id ) when it does print, and calls
 * brikpanel_ask_closed( $id ) from the handler that closes it. A new surface
 * joins by adding one row to brikpanel_asks_registry() and those three calls.
 *
 * State lives per user and per site in one user option (brikpanel_asks) and
 * follows the person across a multisite network in one user meta
 * (brikpanel_asks_person): the quiet period and the one-shot survey. The two
 * names differ on purpose: get_user_option() falls back to the unprefixed key,
 * so a global value under the same name would leak into every site's read.
 * A normal page view reads both from the user meta cache WordPress already
 * holds and writes nothing.
 *
 * @package BrikPanel
 * @since   3.3.32
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Days between two asks (rule 3). */
if ( ! defined( 'BRIKPANEL_ASKS_QUIET_DAYS' ) ) {
    define( 'BRIKPANEL_ASKS_QUIET_DAYS', 7 );
}

/** Days between two BrikMentor asks (rule 3). */
if ( ! defined( 'BRIKPANEL_ASKS_BM_QUIET_DAYS' ) ) {
    define( 'BRIKPANEL_ASKS_BM_QUIET_DAYS', 30 );
}

/** Days a shown ask may stay without being closed (rule 4). */
if ( ! defined( 'BRIKPANEL_ASKS_SHOWN_DAYS' ) ) {
    define( 'BRIKPANEL_ASKS_SHOWN_DAYS', 30 );
}

/** Days a picked ask may wait for its screen before it steps aside (rule 4). */
if ( ! defined( 'BRIKPANEL_ASKS_UNSEEN_DAYS' ) ) {
    define( 'BRIKPANEL_ASKS_UNSEEN_DAYS', 7 );
}

/** Days a closed ask stays out of the running before it may be picked again. */
if ( ! defined( 'BRIKPANEL_ASKS_REST_DAYS' ) ) {
    define( 'BRIKPANEL_ASKS_REST_DAYS', 30 );
}

/** Where the survey card leads. The survey page picks its language from ?lang. */
if ( ! defined( 'BRIKPANEL_SURVEY_URL' ) ) {
    define( 'BRIKPANEL_SURVEY_URL', 'https://brksoft.com/survey/' );
}

/* ── Registry ───────────────────────────────────────────────────────────────── */

/**
 * Every ask except the welcome tour, in the order they are offered (rule 5).
 *
 * - kind: first_run (may follow the tour without waiting), one_shot (never
 *   offered again once closed, anywhere on a network) or ask.
 * - family: 'bm' for BrikMentor, whose asks also keep 30 days from each other.
 * - cap: who the ask is for.
 * - module: the BrikPanel screen it is drawn on, when that screen can be off.
 * - scope: 'site' for a one-shot that is closed on this site only (phone
 *   notifications are turned on per site; a network has many).
 *
 * @return array<string,array{kind:string,family:string,cap:string,module:string,scope?:string}>
 */
function brikpanel_asks_registry() {
    return array(
        'guide'         => array( 'kind' => 'first_run', 'family' => '', 'cap' => 'manage_woocommerce', 'module' => 'brikpanel-dashboard' ),
        'push'          => array( 'kind' => 'one_shot', 'family' => '', 'cap' => 'manage_woocommerce', 'module' => 'brikpanel-dashboard', 'scope' => 'site' ),
        'survey'        => array( 'kind' => 'one_shot', 'family' => '', 'cap' => 'manage_options', 'module' => 'brikpanel-dashboard' ),
        'review'        => array( 'kind' => 'ask', 'family' => '', 'cap' => 'manage_options', 'module' => '' ),
        'bm_announce'   => array( 'kind' => 'ask', 'family' => 'bm', 'cap' => 'manage_woocommerce', 'module' => '' ),
        'bm_pitch_dash' => array( 'kind' => 'ask', 'family' => 'bm', 'cap' => 'manage_woocommerce', 'module' => 'brikpanel-dashboard' ),
        'bm_pitch_ca'   => array( 'kind' => 'ask', 'family' => 'bm', 'cap' => 'manage_woocommerce', 'module' => 'brikpanel-customer-analytics' ),
        'bm_live'       => array( 'kind' => 'ask', 'family' => 'bm', 'cap' => 'manage_options', 'module' => 'brikpanel-dashboard' ),
    );
}

/**
 * The clock every ask decision reads. Tests move it with the filter.
 *
 * @return int Unix time.
 */
function brikpanel_asks_now() {
    return (int) apply_filters( 'brikpanel_asks_now', time() );
}

/* ── Per-request working copy ───────────────────────────────────────────────── */

/**
 * The request's working state, by reference.
 *
 * @return array
 */
function &brikpanel_asks_request() {
    static $request = null;
    if ( null === $request ) {
        $request = brikpanel_asks_blank_request();
    }
    return $request;
}

/** @return array A fresh per-request state. */
function brikpanel_asks_blank_request() {
    return array(
        'loaded'     => false,
        'site'       => array(),
        'person'     => array(),
        'loaded_rev' => 0,
        'current'    => null,
        'dirty'      => false,
        'hold'       => false,
        'rendered'   => array(),
        'modules'    => array(),
    );
}

/**
 * Forget everything worked out in this request (tests run many "requests" in
 * one process).
 *
 * @return void
 */
function brikpanel_asks_reset_request() {
    $request = &brikpanel_asks_request();
    $request = brikpanel_asks_blank_request();
}

/* ── Stored state ───────────────────────────────────────────────────────────── */

/**
 * Site state with every key present and typed.
 *
 * @param mixed $raw Stored value.
 * @return array
 */
function brikpanel_asks_normalize_site( $raw ) {
    $raw  = is_array( $raw ) ? $raw : array();
    $ids  = array_keys( brikpanel_asks_registry() );
    $site = array(
        'cur'      => isset( $raw['cur'] ) && in_array( $raw['cur'], $ids, true ) ? $raw['cur'] : '',
        'sel'      => isset( $raw['sel'] ) ? (int) $raw['sel'] : 0,
        'seen'     => isset( $raw['seen'] ) ? (int) $raw['seen'] : 0,
        'quiet'    => isset( $raw['quiet'] ) ? (int) $raw['quiet'] : 0,
        'bm_quiet' => isset( $raw['bm_quiet'] ) ? (int) $raw['bm_quiet'] : 0,
        'skip'     => array(),
        'done'     => array(),
        'rev'      => isset( $raw['rev'] ) ? (int) $raw['rev'] : 0,
    );
    foreach ( array( 'skip', 'done' ) as $list ) {
        if ( isset( $raw[ $list ] ) && is_array( $raw[ $list ] ) ) {
            foreach ( $raw[ $list ] as $id => $when ) {
                if ( in_array( $id, $ids, true ) ) {
                    $site[ $list ][ $id ] = (int) $when;
                }
            }
        }
    }
    if ( '' === $site['cur'] ) {
        $site['sel']  = 0;
        $site['seen'] = 0;
    }
    return $site;
}

/**
 * Person state (network-wide) with every key present and typed.
 *
 * @param mixed $raw Stored value.
 * @return array
 */
function brikpanel_asks_normalize_person( $raw ) {
    $raw    = is_array( $raw ) ? $raw : array();
    $person = array(
        'quiet' => isset( $raw['quiet'] ) ? (int) $raw['quiet'] : 0,
        'done'  => array(),
    );
    if ( isset( $raw['done'] ) && is_array( $raw['done'] ) ) {
        foreach ( $raw['done'] as $id => $when ) {
            if ( 'survey' === $id ) {
                $person['done'][ $id ] = (int) $when;
            }
        }
    }
    return $person;
}

/**
 * A quiet period worked out from what the merchant closed before this rule
 * existed, so the first page after the update does not open with a new ask
 * the day after they closed an old one. Held in memory only; it is written the
 * first time something is picked.
 *
 * @param int $user_id User.
 * @param int $now     Unix time.
 * @return array{quiet:int,bm_quiet:int}
 */
function brikpanel_asks_seed( $user_id, $now ) {
    $week   = BRIKPANEL_ASKS_QUIET_DAYS * DAY_IN_SECONDS;
    $quiet  = (int) get_option( 'brikpanel_review_snooze_until', 0 );
    $pitch  = (int) get_user_meta( $user_id, '_brikpanel_bm_pitch_snoozed_until', true );
    $closed = $pitch > 0 ? $pitch - ( 30 * DAY_IN_SECONDS ) : 0; // The pitch snooze is 30 days long.
    if ( $closed > 0 ) {
        $quiet = max( $quiet, $closed + $week );
    }
    $installed = (int) get_option( 'brikpanel_activated_at', 0 );
    if ( $installed > 0 ) {
        $quiet = max( $quiet, $installed + $week );
    }
    return array(
        'quiet'    => min( $quiet, $now + $week ),
        'bm_quiet' => $pitch > $now ? $pitch : 0,
    );
}

/**
 * Load the stored state into the request once.
 *
 * @return void
 */
function brikpanel_asks_load() {
    $request = &brikpanel_asks_request();
    if ( $request['loaded'] ) {
        return;
    }
    $user_id = get_current_user_id();
    $raw     = $user_id ? get_user_option( 'brikpanel_asks', $user_id ) : false;
    $site    = brikpanel_asks_normalize_site( $raw );
    if ( ! is_array( $raw ) && $user_id ) {
        $seed             = brikpanel_asks_seed( $user_id, brikpanel_asks_now() );
        $site['quiet']    = $seed['quiet'];
        $site['bm_quiet'] = $seed['bm_quiet'];
    }
    $request['site']       = $site;
    $request['person']     = brikpanel_asks_normalize_person( $user_id ? get_user_meta( $user_id, 'brikpanel_asks_person', true ) : array() );
    $request['loaded_rev'] = $site['rev'];
    $request['loaded']     = true;
}

/**
 * Read the stored state straight from the database, past the meta cache.
 * Only the write paths use this: another tab may have closed an ask since the
 * page loaded.
 *
 * @param int $user_id User.
 * @return array{0:array,1:array} Site and person state.
 */
function brikpanel_asks_read_fresh( $user_id ) {
    wp_cache_delete( $user_id, 'user_meta' );
    return array(
        brikpanel_asks_normalize_site( get_user_option( 'brikpanel_asks', $user_id ) ),
        brikpanel_asks_normalize_person( get_user_meta( $user_id, 'brikpanel_asks_person', true ) ),
    );
}

/**
 * @param int   $user_id User.
 * @param array $site    Site state.
 * @param array $person  Person state.
 * @return void
 */
function brikpanel_asks_store( $user_id, array $site, array $person ) {
    update_user_option( $user_id, 'brikpanel_asks', $site );
    update_user_meta( $user_id, 'brikpanel_asks_person', $person );
}

add_action( 'shutdown', 'brikpanel_asks_write' );
/**
 * Write what this page view changed (an ask picked, first shown, retired),
 * unless another request wrote in the meantime: a close from another tab
 * always wins over a page view that started before it.
 *
 * @return void
 */
function brikpanel_asks_write() {
    $request = &brikpanel_asks_request();
    if ( empty( $request['dirty'] ) ) {
        return;
    }
    $request['dirty'] = false;
    $user_id = get_current_user_id();
    if ( ! $user_id ) {
        return;
    }
    list( $fresh ) = brikpanel_asks_read_fresh( $user_id );
    if ( (int) $fresh['rev'] !== (int) $request['loaded_rev'] ) {
        return;
    }
    $site        = $request['site'];
    $site['rev'] = (int) $request['loaded_rev'] + 1;
    brikpanel_asks_store( $user_id, $site, $request['person'] );
    $request['site']       = $site;
    $request['loaded_rev'] = $site['rev'];
}

/* ── Who and where (rule 7) ─────────────────────────────────────────────────── */

/**
 * Whether asks may be offered on this request at all.
 *
 * @return bool
 */
function brikpanel_asks_in_scope() {
    if ( ! is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
        return false;
    }
    if ( is_network_admin() || is_user_admin() || ! get_current_user_id() ) {
        return false;
    }
    if ( function_exists( 'brikpanel_master_enabled' ) && ! brikpanel_master_enabled() ) {
        return false;
    }
    if ( function_exists( 'brikpanel_access_is_disabled_for_user' ) && brikpanel_access_is_disabled_for_user() ) {
        return false;
    }
    global $pagenow;
    $busy_screens = array( 'plugins.php', 'plugin-install.php', 'plugin-editor.php', 'theme-editor.php', 'update.php', 'update-core.php', 'customize.php', 'site-editor.php', 'widgets.php' );
    if ( in_array( (string) $pagenow, $busy_screens, true ) ) {
        return false;
    }
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen check.
    if ( isset( $_GET['page'] ) && 'brikpanel-brikmentor' === sanitize_key( wp_unslash( $_GET['page'] ) ) ) {
        return false;
    }
    if ( function_exists( 'get_current_screen' ) ) {
        $screen = get_current_screen();
        if ( $screen && method_exists( $screen, 'is_block_editor' ) && $screen->is_block_editor() ) {
            return false;
        }
    }
    /**
     * Last word on whether asks may show on this request.
     *
     * @param bool $in_scope
     */
    return (bool) apply_filters( 'brikpanel_asks_in_scope', true );
}

/**
 * Whether a BrikPanel screen is on and open to this user. Worked out once per
 * request per screen: the module list is rebuilt on every call.
 *
 * @param string $slug Page slug.
 * @return bool
 */
function brikpanel_asks_module_on( $slug ) {
    $request = &brikpanel_asks_request();
    if ( ! isset( $request['modules'][ $slug ] ) ) {
        $request['modules'][ $slug ] = function_exists( 'brikpanel_module_available' ) ? (bool) brikpanel_module_available( $slug ) : true;
    }
    return $request['modules'][ $slug ];
}

/* ── Holding back (rule 6) ──────────────────────────────────────────────────── */

/**
 * Whether another notice box is on screen on this page view. The notice
 * collector in brikpanel.php sets it for every box it leaves visible; when the
 * collector is switched off, brikpanel_asks_sniff_end() does.
 *
 * @param bool|null $set Pass true to hold; null only reads.
 * @return bool
 */
function brikpanel_asks_hold( $set = null ) {
    $request = &brikpanel_asks_request();
    if ( null !== $set ) {
        $request['hold'] = (bool) $set;
    }
    return ! empty( $request['hold'] );
}

/**
 * Whether the address carries the result of something the merchant just did
 * ("Settings saved.", "Order updated."). Screens print those messages after
 * the notice hooks, where no buffer sees them, so the address is the signal.
 *
 * @return bool
 */
function brikpanel_asks_url_hold() {
    /**
     * Query arguments that mean a result message is on screen.
     *
     * @param string[] $args
     */
    $args = (array) apply_filters( 'brikpanel_asks_hold_query_args', array( 'settings-updated', 'updated', 'message', 'deleted', 'trashed', 'activated', 'error', 'brikpanel_merge_error' ) );
    foreach ( $args as $arg ) {
        if ( isset( $_GET[ $arg ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only presence check.
            return true;
        }
    }
    return false;
}

add_action( 'admin_init', 'brikpanel_asks_maybe_sniff', 20 );
/**
 * With the notice collector switched off nobody looks at the notice area, so
 * a light buffer does, only to learn whether a box is on screen. Registered
 * only then, so the two buffers never nest.
 *
 * @return void
 */
function brikpanel_asks_maybe_sniff() {
    if ( is_network_admin() || 'yes' === get_option( 'brikpanel_hide_foreign_notices', 'yes' ) ) {
        return;
    }
    foreach ( array( 'admin_notices', 'all_admin_notices' ) as $hook ) {
        add_action( $hook, 'brikpanel_asks_sniff_start', -PHP_INT_MAX );
        add_action( $hook, 'brikpanel_asks_sniff_end', PHP_INT_MAX );
    }
}

/** @return void */
function brikpanel_asks_sniff_start() {
    ob_start();
}

/** @return void */
function brikpanel_asks_sniff_end() {
    $html = (string) ob_get_clean();
    if ( '' !== $html && brikpanel_asks_html_has_notice( $html ) ) {
        brikpanel_asks_hold( true );
    }
    echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- other code's notice markup, passed through unchanged.
}

/**
 * Whether markup holds a notice box a person can see: not a hidden control
 * notice (the "connection lost" banner core reveals by script), not an ask.
 *
 * @param string $html Markup.
 * @return bool
 */
function brikpanel_asks_html_has_notice( $html ) {
    if ( ! preg_match_all( '#<div\b[^>]*\bclass=(["\'])([^"\']*)\1[^>]*>#i', $html, $tags, PREG_SET_ORDER ) ) {
        return false;
    }
    foreach ( $tags as $tag ) {
        $classes = preg_split( '#\s+#', trim( $tag[2] ) );
        if ( ! array_intersect( $classes, array( 'notice', 'updated', 'error', 'update-nag' ) ) ) {
            continue;
        }
        if ( in_array( 'hidden', $classes, true ) || in_array( 'brikpanel-ask', $classes, true ) ) {
            continue;
        }
        if ( preg_match( '#\bid=(["\'])(?:lost-connection-notice|local-storage-notice)\1#i', $tag[0] ) ) {
            continue;
        }
        return true;
    }
    return false;
}

/* ── Who may be asked what ──────────────────────────────────────────────────── */

/**
 * Whether an ask applies to this user and store right now. Reads options and
 * the user's own meta only: the store figures some asks show are checked by
 * the surface when it draws (brikpanel_ask_skip() / brikpanel_ask_retire()).
 *
 * @param string $id      Ask id.
 * @param int    $now     Unix time.
 * @param bool   $picking True while choosing a new ask; false when the ask
 *                        already on stage is checked. The phone card is only
 *                        picked on a page view from the phone, but a look
 *                        from the computer does not take its turn away.
 * @return bool
 */
function brikpanel_asks_eligible( $id, $now, $picking = true ) {
    $registry = brikpanel_asks_registry();
    if ( ! isset( $registry[ $id ] ) ) {
        return false;
    }
    $def = $registry[ $id ];
    if ( ! current_user_can( $def['cap'] ) ) {
        return false;
    }
    if ( '' !== $def['module'] && ! brikpanel_asks_module_on( $def['module'] ) ) {
        return false;
    }
    $promo = function_exists( 'brikpanel_brikmentor_promo_active' ) && brikpanel_brikmentor_promo_active();
    switch ( $id ) {
        case 'guide':
            if ( get_user_option( 'brikpanel_new_store_guide_dismissed', get_current_user_id() ) ) {
                return false;
            }
            // A store with completed orders is not new. The dashboard checks
            // every other order when it draws the guide.
            return 0 === (int) get_option( 'brikpanel_completed_orders_count', 0 );
        case 'push':
            return function_exists( 'brikpanel_push_card_eligible' ) && brikpanel_push_card_eligible( $now, $picking );
        case 'survey':
            return true;
        case 'review':
            return function_exists( 'brikpanel_review_get_active_notice' ) && (bool) brikpanel_review_get_active_notice( $now );
        case 'bm_announce':
            return $promo && function_exists( 'brikpanel_brikmentor_announce_eligible' ) && brikpanel_brikmentor_announce_eligible( $now );
        case 'bm_pitch_dash':
        case 'bm_pitch_ca':
            return $promo && function_exists( 'brikpanel_brikmentor_pitch_snoozed' ) && ! brikpanel_brikmentor_pitch_snoozed( $now );
        case 'bm_live':
            return $promo && ! get_option( 'brikpanel_bm_live_card_dismissed' );
    }
    return false;
}

/* ── The decision ───────────────────────────────────────────────────────────── */

/**
 * Close an ask in a state pair: it leaves the stage and, when it was seen,
 * starts the quiet period (rule 3).
 *
 * @param array  $site        Site state.
 * @param array  $person      Person state.
 * @param string $id          Ask id ('welcome' for the tour).
 * @param int    $now         Unix time.
 * @param bool   $start_quiet Whether to start the quiet period.
 * @return array{0:array,1:array}
 */
function brikpanel_asks_apply_close( array $site, array $person, $id, $now, $start_quiet ) {
    $registry = brikpanel_asks_registry();
    $def      = isset( $registry[ $id ] ) ? $registry[ $id ] : array( 'kind' => 'first_run', 'family' => '' );
    if ( $start_quiet ) {
        $quiet           = $now + BRIKPANEL_ASKS_QUIET_DAYS * DAY_IN_SECONDS;
        $site['quiet']   = max( (int) $site['quiet'], $quiet );
        $person['quiet'] = max( (int) $person['quiet'], $quiet );
        if ( 'bm' === $def['family'] ) {
            $site['bm_quiet'] = max( (int) $site['bm_quiet'], $now + BRIKPANEL_ASKS_BM_QUIET_DAYS * DAY_IN_SECONDS );
        }
    }
    if ( isset( $registry[ $id ] ) ) {
        $site['done'][ $id ] = $now;
        if ( 'one_shot' === $def['kind'] && ( empty( $def['scope'] ) || 'site' !== $def['scope'] ) ) {
            $person['done'][ $id ] = $now;
        }
    }
    if ( $site['cur'] === $id ) {
        $site['cur']  = '';
        $site['sel']  = 0;
        $site['seen'] = 0;
    }
    return array( $site, $person );
}

/**
 * Decide which ask is on stage. Pure: the state comes in, the new state goes
 * out, and the eligibility test is passed in, so it can be tested without a
 * store.
 *
 * @param array    $site     Site state.
 * @param array    $person   Person state.
 * @param int      $now      Unix time.
 * @param callable $eligible fn( string $id, int $now, bool $picking ): bool.
 * @return array{0:string,1:array,2:array,3:bool} Ask id ('' for none), site, person, changed.
 */
function brikpanel_asks_decide( array $site, array $person, $now, $eligible ) {
    $registry = brikpanel_asks_registry();
    $changed  = false;
    $day      = DAY_IN_SECONDS;

    // The ask already on stage keeps it (rule 4) unless it ended.
    if ( '' !== $site['cur'] ) {
        $id = $site['cur'];
        if ( ! isset( $registry[ $id ] ) || ! call_user_func( $eligible, $id, $now, false ) ) {
            // It no longer applies. If the merchant saw it, that still counts
            // as one ask and the quiet period starts; if not, it just leaves.
            if ( $site['seen'] > 0 ) {
                list( $site, $person ) = brikpanel_asks_apply_close( $site, $person, $id, $now, true );
            } else {
                $site['cur'] = '';
                $site['sel'] = 0;
            }
            $changed = true;
        } elseif ( $site['seen'] > 0 && $now - $site['seen'] >= BRIKPANEL_ASKS_SHOWN_DAYS * $day ) {
            list( $site, $person ) = brikpanel_asks_apply_close( $site, $person, $id, $now, true );
            $changed               = true;
        } elseif ( 0 === $site['seen'] && $now - $site['sel'] >= BRIKPANEL_ASKS_UNSEEN_DAYS * $day ) {
            $site['skip'][ $id ] = $now + BRIKPANEL_ASKS_UNSEEN_DAYS * $day;
            $site['cur']         = '';
            $site['sel']         = 0;
            $changed             = true;
        } else {
            return array( $id, $site, $person, $changed );
        }
    }

    $quiet = max( (int) $site['quiet'], (int) $person['quiet'] );
    foreach ( $registry as $id => $def ) {
        if ( 'first_run' !== $def['kind'] && $now < $quiet ) {
            continue;
        }
        if ( 'bm' === $def['family'] && $now < (int) $site['bm_quiet'] ) {
            continue;
        }
        if ( isset( $site['skip'][ $id ] ) && $now < $site['skip'][ $id ] ) {
            continue;
        }
        if ( 'one_shot' === $def['kind'] && ( isset( $site['done'][ $id ] ) || isset( $person['done'][ $id ] ) ) ) {
            continue;
        }
        if ( 'first_run' === $def['kind'] && isset( $site['done'][ $id ] ) ) {
            continue;
        }
        if ( 'ask' === $def['kind'] && isset( $site['done'][ $id ] ) && $now - $site['done'][ $id ] < BRIKPANEL_ASKS_REST_DAYS * $day ) {
            continue;
        }
        if ( ! call_user_func( $eligible, $id, $now, true ) ) {
            continue;
        }
        $site['cur']  = $id;
        $site['sel']  = $now;
        $site['seen'] = 0;
        return array( $id, $site, $person, true );
    }
    return array( '', $site, $person, $changed );
}

/**
 * The ask on stage for this request: 'welcome', a registry id, or ''.
 *
 * @return string
 */
function brikpanel_ask_current() {
    $request = &brikpanel_asks_request();
    if ( null !== $request['current'] ) {
        return $request['current'];
    }
    if ( ! brikpanel_asks_in_scope() ) {
        return '';
    }
    if ( function_exists( 'brikpanel_should_show_welcome' ) && brikpanel_should_show_welcome() ) {
        $request['current'] = 'welcome';
        return 'welcome';
    }
    brikpanel_asks_load();
    list( $id, $site, $person, $changed ) = brikpanel_asks_decide( $request['site'], $request['person'], brikpanel_asks_now(), 'brikpanel_asks_eligible' );
    $request['site']    = $site;
    $request['person']  = $person;
    $request['current'] = $id;
    if ( $changed ) {
        $request['dirty'] = true;
    }
    return $id;
}

/**
 * Whether a surface may draw its ask now. Every surface's first test.
 *
 * @param string $id Ask id.
 * @return bool
 */
function brikpanel_ask_allows( $id ) {
    if ( ! brikpanel_asks_in_scope() || brikpanel_asks_hold() || brikpanel_asks_url_hold() ) {
        return false;
    }
    return brikpanel_ask_current() === $id;
}

/**
 * Record that a surface drew its ask. Only a surface that really printed calls
 * this: the 30-day clock starts here and an unseen ask may step aside.
 *
 * @param string $id Ask id.
 * @return void
 */
function brikpanel_ask_shown( $id ) {
    $request = &brikpanel_asks_request();
    if ( brikpanel_ask_current() !== $id || 'welcome' === $id ) {
        return;
    }
    $request['rendered'][ $id ] = true;
    if ( empty( $request['site']['seen'] ) ) {
        $request['site']['seen'] = brikpanel_asks_now();
        $request['dirty']        = true;
    }
}

/**
 * Whether a surface drew this ask earlier in this request.
 *
 * @param string $id Ask id.
 * @return bool
 */
function brikpanel_ask_rendered( $id ) {
    $request = &brikpanel_asks_request();
    return ! empty( $request['rendered'][ $id ] );
}

/**
 * The merchant closed an ask (X, "Not now", the button that answers it).
 * Writes straight away: AJAX handlers end the request right after.
 *
 * @param string $id Ask id, or 'welcome'.
 * @return void
 */
function brikpanel_ask_closed( $id ) {
    $user_id = get_current_user_id();
    $id      = sanitize_key( (string) $id );
    if ( ! $user_id || ( 'welcome' !== $id && ! isset( brikpanel_asks_registry()[ $id ] ) ) ) {
        return;
    }
    $now                   = brikpanel_asks_now();
    list( $site, $person ) = brikpanel_asks_read_fresh( $user_id );
    list( $site, $person ) = brikpanel_asks_apply_close( $site, $person, $id, $now, true );
    $site['rev']++;
    brikpanel_asks_store( $user_id, $site, $person );

    $request               = &brikpanel_asks_request();
    $request['site']       = $site;
    $request['person']     = $person;
    $request['loaded_rev'] = $site['rev'];
    $request['loaded']     = true;
    $request['current']    = null;
    $request['dirty']      = false;
}

/**
 * The ask on stage ended by itself while its surface was drawing (the store
 * got its first order, the figure a card shows is gone). The next ask is
 * worked out at once, so a surface further down the same page may take over.
 *
 * @param string $id Ask id.
 * @return void
 */
function brikpanel_ask_retire( $id ) {
    $request = &brikpanel_asks_request();
    if ( brikpanel_ask_current() !== $id ) {
        return;
    }
    $now = brikpanel_asks_now();
    if ( ! empty( $request['site']['seen'] ) ) {
        list( $request['site'], $request['person'] ) = brikpanel_asks_apply_close( $request['site'], $request['person'], $id, $now, true );
    } else {
        $request['site']['cur'] = '';
        $request['site']['sel'] = 0;
    }
    $request['dirty']   = true;
    $request['current'] = null;
}

/**
 * The ask on stage has nothing to show yet (no abandoned carts for the
 * BrikMentor card). It steps aside for $seconds without starting a quiet
 * period, and the next ask is worked out at once. One already seen retires.
 *
 * @param string $id      Ask id.
 * @param int    $seconds How long to leave it out.
 * @return void
 */
function brikpanel_ask_skip( $id, $seconds ) {
    $request = &brikpanel_asks_request();
    if ( brikpanel_ask_current() !== $id ) {
        return;
    }
    if ( ! empty( $request['site']['seen'] ) ) {
        brikpanel_ask_retire( $id );
        return;
    }
    $request['site']['skip'][ $id ] = brikpanel_asks_now() + max( 0, (int) $seconds );
    $request['site']['cur']         = '';
    $request['site']['sel']         = 0;
    $request['dirty']               = true;
    $request['current']             = null;
}

/* ── The survey card (top of the dashboard) ─────────────────────────────────── */

/**
 * Address of the survey, in the admin's own language. The survey page falls
 * back to the browser's language for any it does not have.
 *
 * @return string
 */
function brikpanel_survey_url() {
    /**
     * Where the survey card leads.
     *
     * @param string $url
     */
    $base = (string) apply_filters( 'brikpanel_survey_url', BRIKPANEL_SURVEY_URL );
    return add_query_arg(
        array(
            'src'  => 'panel',
            'lang' => rawurlencode( get_user_locale() ),
        ),
        $base
    );
}

add_action( 'brikpanel_dashboard_before_sections', 'brikpanel_survey_card_render' );
/**
 * "What bothers you most about WooCommerce?", above the dashboard cards.
 * Answering or closing it puts it away for this admin for good.
 *
 * @return void
 */
function brikpanel_survey_card_render() {
    if ( ! brikpanel_ask_allows( 'survey' ) ) {
        return;
    }
    brikpanel_ask_shown( 'survey' );
    $nonce = wp_create_nonce( 'brikpanel_ask_close' );
    ?>
    <div class="brikpanel-ea-card brikpanel-ea-card--ask brikpanel-ask" data-bp-ask="survey" data-nonce="<?php echo esc_attr( $nonce ); ?>">
        <div class="brikpanel-ea-card__badge" aria-hidden="true">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" xmlns="http://www.w3.org/2000/svg" focusable="false"><rect x="5" y="4" width="14" height="17" rx="2"/><path d="M9 3h6v3H9z"/><path d="M9 11.5h6M9 15.5h4"/></svg>
        </div>
        <div class="brikpanel-ea-card__text">
            <?php // dir="auto": a sentence still in English on a right-to-left admin keeps its punctuation at its own end. ?>
            <p class="brikpanel-ea-card__title" dir="auto"><?php esc_html_e( 'What bothers you most about WooCommerce?', 'brikpanel' ); ?></p>
            <p class="brikpanel-ea-card__body" dir="auto"><?php esc_html_e( 'A 2-minute survey. Your answers decide what we build next.', 'brikpanel' ); ?></p>
        </div>
        <a class="brikpanel-ea-card__cta" href="<?php echo esc_url( brikpanel_survey_url() ); ?>" target="_blank" rel="noopener noreferrer" data-bp-ask-go><?php esc_html_e( 'Take the survey', 'brikpanel' ); ?><span class="screen-reader-text"> <?php esc_html_e( '(opens in a new tab)', 'brikpanel' ); ?></span></a>
        <button type="button" class="brikpanel-ea-card__close" data-bp-ask-close aria-label="<?php esc_attr_e( 'Dismiss', 'brikpanel' ); ?>">
            <svg width="13" height="13" viewBox="0 0 14 14" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false"><path d="M1 1l12 12M13 1L1 13" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
        </button>
    </div>
    <?php
    if ( function_exists( 'brikpanel_ea_print_card_styles' ) ) {
        brikpanel_ea_print_card_styles();
    }
    ?>
    <style>
        /* At the top of the dashboard the card takes the spacing of the guide
           and the cards below it, not the bottom card's top margin. */
        .brikpanel-ea-card.brikpanel-ea-card--ask { margin: 0 0 1.25rem; }
        .brikpanel-ea-card--ask .brikpanel-ea-card__cta:focus,
        .brikpanel-ea-card--ask .brikpanel-ea-card__close:focus { outline: none; box-shadow: none; }
        .brikpanel-ea-card--ask .brikpanel-ea-card__cta:focus-visible,
        .brikpanel-ea-card--ask .brikpanel-ea-card__close:focus-visible { outline: 2px solid #303030; outline-offset: 2px; }
        /* Phone: icon, words and X on the first row, the button alone and full
           width under them. The base card wraps the button above the title. */
        @media (max-width: 600px) {
            .brikpanel-ea-card.brikpanel-ea-card--ask {
                display: grid; grid-template-columns: auto minmax(0, 1fr) auto;
                gap: 0.875rem; align-items: start;
            }
            .brikpanel-ea-card--ask .brikpanel-ea-card__badge { grid-area: 1 / 1; }
            .brikpanel-ea-card--ask .brikpanel-ea-card__text { grid-area: 1 / 2; order: 0; flex-basis: auto; }
            .brikpanel-ea-card--ask .brikpanel-ea-card__close { grid-area: 1 / 3; }
            .brikpanel-ea-card--ask .brikpanel-ea-card__cta {
                grid-area: 2 / 1 / 3 / 4; display: flex; justify-content: center;
                text-align: center; padding: 0.625rem 1rem;
            }
        }
    </style>
    <script>
    (function () {
        var card = document.querySelector('[data-bp-ask="survey"]');
        if (!card) { return; }
        var sent = false;
        function close() {
            if (sent) { return; }
            sent = true;
            var fd = new FormData();
            fd.append('action', 'brikpanel_ask_close');
            fd.append('_ajax_nonce', card.getAttribute('data-nonce'));
            fd.append('ask', 'survey');
            try {
                fetch(<?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>, { method: 'POST', body: fd, credentials: 'same-origin', keepalive: true }).catch(function () {});
            } catch (err) {}
            if (card.parentNode) { card.parentNode.removeChild(card); }
        }
        card.querySelector('[data-bp-ask-close]').addEventListener('click', close);
        // Let the link open its tab first, then put the card away.
        card.querySelector('[data-bp-ask-go]').addEventListener('click', function () { setTimeout(close, 0); });
    })();
    </script>
    <?php
}

/* ── The survey row in WooCommerce > Settings > BrikPanel (General) ────────── */

add_filter( 'brikpanel_settings_fields', 'brikpanel_survey_settings_field', 1000 );
/**
 * A quiet, permanent place for the survey in the General settings, right above
 * the Newsletter section (owner's choice, 6 October 2026). It is a row in the
 * settings list, not an ask: it never pops up and does not take turns. Runs
 * after the newsletter section was added (priority 999) and slots in before
 * it; on a list without that section it goes last.
 *
 * The title id is unmapped on purpose, so brikpanel_settings_section_for_title()
 * places it under General, next to the newsletter.
 *
 * @param array $fields Settings fields.
 * @return array
 */
function brikpanel_survey_settings_field( $fields ) {
    $fields  = array_values( (array) $fields );
    $section = array(
        array(
            'type'  => 'title',
            'id'    => 'brk_survey_title',
            'title' => _x( 'Survey', 'settings section title', 'brikpanel' ),
        ),
        array(
            'type' => 'brikpanel_survey',
            'id'   => 'brikpanel_survey_settings_field',
        ),
        array(
            'type' => 'sectionend',
            'id'   => 'brk_survey_title',
        ),
    );
    foreach ( $fields as $index => $field ) {
        if ( isset( $field['type'], $field['id'] ) && 'title' === $field['type'] && 'brk_newsletter_title' === $field['id'] ) {
            array_splice( $fields, $index, 0, $section );
            return $fields;
        }
    }
    return array_merge( $fields, $section );
}

add_action( 'woocommerce_admin_field_brikpanel_survey', 'brikpanel_survey_render_settings_field' );
/**
 * The survey row: the question, one line on what it is, and the button. The
 * button opens the same survey as the dashboard card; pressing it also puts
 * the dashboard card away for this admin, so nobody is asked twice.
 *
 * @param array $field Field definition (unused).
 * @return void
 */
function brikpanel_survey_render_settings_field( $field ) {
    $nonce = wp_create_nonce( 'brikpanel_ask_close' );
    ?>
    <tr valign="top">
        <th scope="row" class="titledesc">
            <label><?php esc_html_e( 'WooCommerce survey', 'brikpanel' ); ?></label>
        </th>
        <td class="forminp">
            <p class="brikpanel-survey-desc">
                <?php esc_html_e( 'What bothers you most about WooCommerce?', 'brikpanel' ); ?>
                <?php esc_html_e( 'A 2-minute survey. Your answers decide what we build next.', 'brikpanel' ); ?>
            </p>
            <a class="brikpanel-btn brikpanel-btn--secondary brikpanel-survey-btn" href="<?php echo esc_url( brikpanel_survey_url() ); ?>" target="_blank" rel="noopener noreferrer" data-bp-ask-settings data-nonce="<?php echo esc_attr( $nonce ); ?>"><?php esc_html_e( 'Take the survey', 'brikpanel' ); ?><span class="screen-reader-text"> <?php esc_html_e( '(opens in a new tab)', 'brikpanel' ); ?></span></a>
            <style>
                .brikpanel-survey-desc { max-width: 640px; color: #616161; margin: 0 0 0.75rem; }
            </style>
            <script>
            (function () {
                var link = document.querySelector('[data-bp-ask-settings]');
                if (!link) { return; }
                link.addEventListener('click', function () {
                    var fd = new FormData();
                    fd.append('action', 'brikpanel_ask_close');
                    fd.append('_ajax_nonce', link.getAttribute('data-nonce'));
                    fd.append('ask', 'survey');
                    try {
                        fetch(<?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>, { method: 'POST', body: fd, credentials: 'same-origin', keepalive: true }).catch(function () {});
                    } catch (err) {}
                });
            })();
            </script>
        </td>
    </tr>
    <?php
}

add_action( 'wp_ajax_brikpanel_ask_close', 'brikpanel_ask_ajax_close' );
/**
 * AJAX: the merchant answered or closed an ask that has no handler of its own.
 *
 * @return void
 */
function brikpanel_ask_ajax_close() {
    check_ajax_referer( 'brikpanel_ask_close' );
    $ask      = isset( $_POST['ask'] ) ? sanitize_key( wp_unslash( $_POST['ask'] ) ) : '';
    $registry = brikpanel_asks_registry();
    // Who may close it is who it is for: administrators for the survey, store
    // managers too for the phone card.
    $cap = in_array( $ask, array( 'survey', 'push' ), true ) ? $registry[ $ask ]['cap'] : 'manage_options';
    if ( ! current_user_can( $cap ) ) {
        wp_send_json_error( array( 'reason' => 'forbidden' ), 403 );
    }
    if ( ! in_array( $ask, array( 'survey', 'push' ), true ) ) {
        wp_send_json_error( array( 'reason' => 'unknown_ask' ), 400 );
    }
    brikpanel_ask_closed( $ask );
    wp_send_json_success();
}
