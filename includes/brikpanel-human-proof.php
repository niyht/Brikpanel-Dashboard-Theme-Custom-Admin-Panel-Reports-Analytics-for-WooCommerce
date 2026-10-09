<?php
/**
 * "This is a person" mark for storefront analytics (3.3.30).
 *
 * Every earlier defence tried to recognise a robot: by its name (3.2.30), by
 * a prefetch header (3.2.41), by a client that forgets its cookies (3.3.1,
 * 3.3.11). Each wave got past the previous one, because a script can call
 * itself Chrome, rent a new address per request and run JavaScript. And the
 * add-to-cart and checkout counters, which run in PHP, counted a client that
 * never ran any JavaScript at all.
 *
 * So the question is turned around. Nothing a browser does is counted until
 * it has behaved like a person once: the tracker waits for a real pointer
 * movement, touch, wheel or key press on a page that is actually on screen,
 * and only then asks the server for this mark. The server adds its own
 * checks (cloud provider address, cross-site request, a memoryless client's
 * daily lock) and answers with a signed cookie:
 *
 *     brikpanel_human = 1.<store day>.<flags>.<signature>
 *
 * The signature (site salt, tied to the browser's brikpanel_vid) means the
 * value cannot be made up. Any valid mark from the last 30 days says "this
 * browser is a person"; the flags say what was already counted on the store
 * day it names: v visitor, p product view, a add-to-cart, c checkout. The day
 * is the server's own, so a page served from a cache built days ago cannot
 * make one person count again (Chris, October 2026), and because the cookie
 * arrives with the server's answer even when the page that asked is already
 * gone, a visitor who moves on quickly is not counted twice either.
 *
 * The PHP counters (add-to-cart, checkout) count a browser that carries the
 * mark (or a signed-in customer) at once. Anyone else is parked in the
 * WooCommerce session and counted when the same session's tracker proves
 * itself; a client that never does (no JavaScript, a Store API loop, a link
 * preloader) is never counted.
 *
 * @package BrikPanel
 * @since   3.3.30
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Cookie holding the mark. Not HttpOnly: the tracker reads that it exists; the server decides. */
if ( ! defined( 'BRIKPANEL_HUMAN_COOKIE' ) ) {
    define( 'BRIKPANEL_HUMAN_COOKIE', 'brikpanel_human' );
}

/** How long one proof stands: a browser that proved itself recently is not asked again. */
if ( ! defined( 'BRIKPANEL_HUMAN_TTL_DAYS' ) ) {
    define( 'BRIKPANEL_HUMAN_TTL_DAYS', 30 );
}

/** Days after this release during which pages cached with the old tracker are still counted the old way. */
if ( ! defined( 'BRIKPANEL_LEGACY_TRACKER_DAYS' ) ) {
    define( 'BRIKPANEL_LEGACY_TRACKER_DAYS', 14 );
}

/** WooCommerce session key for counts waiting for the browser's proof. */
if ( ! defined( 'BRIKPANEL_PENDING_KEY' ) ) {
    define( 'BRIKPANEL_PENDING_KEY', 'brikpanel_pending_counts' );
}

/**
 * The mark's cookie name on this site.
 *
 * Sites of a multisite network that live in folders of one domain share
 * every cookie set on the root path, while each keeps its own counters. One
 * shared mark would carry one site's "counted today" flags to the others, so
 * a visitor counted on /shop2/ would never be counted on the main site that
 * day. Each site after the first gets its own name.
 *
 * @return string
 */
function brikpanel_human_cookie_name() {
    $blog = ( function_exists( 'is_multisite' ) && is_multisite() ) ? (int) get_current_blog_id() : 1;
    return $blog > 1 ? BRIKPANEL_HUMAN_COOKIE . '_' . $blog : BRIKPANEL_HUMAN_COOKIE;
}

/**
 * The store's calendar day, the one every counter writes.
 *
 * @return string Y-m-d.
 */
function brikpanel_human_today() {
    return wp_date( 'Y-m-d' );
}

/**
 * Signature of one mark.
 *
 * @param string $day   Y-m-d.
 * @param string $flags Counted-today flags.
 * @param string $vid   The browser's brikpanel_vid ('' when it has none).
 * @return string 20 hex characters.
 */
function brikpanel_human_sign( $day, $flags, $vid ) {
    $blog = ( function_exists( 'is_multisite' ) && is_multisite() ) ? (int) get_current_blog_id() : 1;
    return substr( hash_hmac( 'sha256', $day . '|' . $flags . '|' . $vid . '|' . $blog, wp_salt( 'brikpanel_human' ) ), 0, 20 );
}

/**
 * Flags in canonical order, unknown letters dropped.
 *
 * @param string $flags
 * @return string
 */
function brikpanel_human_flags( $flags ) {
    $out = '';
    foreach ( [ 'v', 'p', 'a', 'c' ] as $flag ) {
        if ( false !== strpos( (string) $flags, $flag ) ) {
            $out .= $flag;
        }
    }
    return $out;
}

/**
 * The mark this request carries, verified, plus anything this request
 * already changed (a mark issued a moment ago counts for the rest of it).
 *
 * @param bool $reset Forget the cached answer (tests, and after an issue).
 * @return array{valid:bool,day:string,flags:string,today:bool}
 */
function brikpanel_human_state( $reset = false ) {
    static $state = null;
    if ( $reset ) {
        $state = null;
    }
    if ( null !== $state ) {
        return $state;
    }

    $state = [ 'valid' => false, 'day' => '', 'flags' => '', 'today' => false ];

    $name = brikpanel_human_cookie_name();
    $raw  = isset( $_COOKIE[ $name ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ $name ] ) ) : '';
    if ( ! preg_match( '/^1\.(\d{4}-\d{2}-\d{2})\.([vpac]{0,4})\.([0-9a-f]{20})$/D', $raw, $m ) ) {
        return $state;
    }

    $vid = function_exists( 'brikpanel_visitor_id_from_cookie' ) ? brikpanel_visitor_id_from_cookie() : '';
    if ( ! hash_equals( brikpanel_human_sign( $m[1], $m[2], $vid ), $m[3] ) ) {
        return $state;
    }

    // Older than the proof window, or dated in the future (a clock or salt
    // change): not evidence any more.
    $today = brikpanel_human_today();
    $age   = ( strtotime( $today . ' 00:00:00 UTC' ) - strtotime( $m[1] . ' 00:00:00 UTC' ) ) / DAY_IN_SECONDS;
    if ( $age < 0 || $age > BRIKPANEL_HUMAN_TTL_DAYS ) {
        return $state;
    }

    $state = [
        'valid' => true,
        'day'   => $m[1],
        'flags' => brikpanel_human_flags( $m[2] ),
        'today' => ( $m[1] === $today ),
    ];
    return $state;
}

/**
 * Issue (or refresh) the mark for today with the given flags. Also mints the
 * browser id when there is none, since the signature is tied to it.
 *
 * Safe after headers went out: the rest of this request still sees the new
 * state, only the cookie is skipped.
 *
 * @param string $flags Flags counted today.
 * @return void
 */
function brikpanel_human_issue( $flags ) {
    $vid = function_exists( '_brikpanel_get_visitor_id' ) ? (string) _brikpanel_get_visitor_id() : '';
    if ( '' === $vid && function_exists( 'brikpanel_visitor_id_from_cookie' ) ) {
        $vid = brikpanel_visitor_id_from_cookie();
    }

    $day   = brikpanel_human_today();
    $flags = brikpanel_human_flags( $flags );
    $value = '1.' . $day . '.' . $flags . '.' . brikpanel_human_sign( $day, $flags, $vid );

    $name    = brikpanel_human_cookie_name();
    $current = isset( $_COOKIE[ $name ] ) ? (string) $_COOKIE[ $name ] : '';
    $_COOKIE[ $name ] = $value;
    brikpanel_human_state( true );

    if ( $current === $value || headers_sent() ) {
        return;
    }
    setcookie(
        $name,
        $value,
        [
            'expires'  => time() + BRIKPANEL_HUMAN_TTL_DAYS * DAY_IN_SECONDS,
            'path'     => COOKIEPATH ? COOKIEPATH : '/',
            'domain'   => COOKIE_DOMAIN,
            'secure'   => is_ssl(),
            'httponly' => false,
            'samesite' => 'Lax',
        ]
    );
}

/**
 * Whether something was already counted for this browser today.
 *
 * @param string $flag One of v, p, a, c.
 * @return bool
 */
function brikpanel_human_has_flag( $flag ) {
    $state = brikpanel_human_state();
    return $state['valid'] && $state['today'] && false !== strpos( $state['flags'], (string) $flag );
}

/**
 * Record that something was counted for this browser today.
 *
 * @param string $flag One of v, p, a, c.
 * @return void
 */
function brikpanel_human_mark( $flag ) {
    $state = brikpanel_human_state();
    $flags = ( $state['valid'] && $state['today'] ) ? $state['flags'] : '';
    if ( false !== strpos( $flags, (string) $flag ) ) {
        return;
    }
    brikpanel_human_issue( $flags . $flag );
}

/**
 * Whether this request comes from a person as far as the counters are
 * concerned: a signed-in customer, or a browser carrying a valid mark.
 * (Staff accounts never reach a counter at all.)
 *
 * @return bool
 */
function brikpanel_request_is_human() {
    if ( is_user_logged_in() ) {
        return true;
    }
    return brikpanel_human_state()['valid'];
}

/**
 * Server-side reasons to turn down a browser that says it just saw a person.
 *
 * The tracker only claims that after a real input event, but the claim
 * itself is a form field anyone can send. What the server can check on its
 * own is where the request comes from.
 *
 * @return string Reason ('' when nothing speaks against it).
 */
function brikpanel_human_claim_refused() {
    // Browsers say where a request was started. The tracker posts from the
    // store's own pages; another site posting on a visitor's behalf is not a
    // visit. (Old browsers send nothing, which passes.)
    $fetch_site = isset( $_SERVER['HTTP_SEC_FETCH_SITE'] ) ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_SEC_FETCH_SITE'] ) ) ) : '';
    if ( 'cross-site' === $fetch_site ) {
        return 'cross_site';
    }

    if ( function_exists( 'brikpanel_ip_is_datacenter' ) && brikpanel_datacenter_rule_active() ) {
        $ip = function_exists( 'brikpanel_client_ip' ) ? brikpanel_client_ip() : '';
        $dc = '' !== $ip && brikpanel_ip_is_datacenter( $ip );
        brikpanel_datacenter_note( $ip, $dc );
        if ( $dc ) {
            return 'datacenter';
        }
    }

    /**
     * Filters the decision to refuse a browser's first "this is a person" request.
     *
     * Return a non-empty reason to refuse (nothing from that request is
     * counted and no mark is issued).
     *
     * @since 3.3.30
     *
     * @param string $reason '' to allow.
     */
    return (string) apply_filters( 'brikpanel_human_claim_refused', '' );
}

/* ---------------------------------------------------------------------------
 * Counts waiting for proof
 * ------------------------------------------------------------------------- */

/**
 * The WooCommerce session, when this request has one.
 *
 * @return WC_Session|null
 */
function brikpanel_pending_session() {
    if ( ! function_exists( 'WC' ) ) {
        return null;
    }
    $wc = WC();
    return ( $wc && ! empty( $wc->session ) && is_object( $wc->session ) ) ? $wc->session : null;
}

/**
 * Park a count until this session's browser proves it is a person.
 *
 * @param string $kind       'atc' (store add-to-cart), 'product' (one product's add-to-cart) or 'checkout'.
 * @param int    $product_id Product for 'product'.
 * @return void
 */
function brikpanel_pending_add( $kind, $product_id = 0 ) {
    $session = brikpanel_pending_session();
    if ( ! $session ) {
        return; // No session, nothing to remember with: not counted.
    }

    $today   = brikpanel_human_today();
    $pending = $session->get( BRIKPANEL_PENDING_KEY );
    if ( ! is_array( $pending ) || ( $pending['day'] ?? '' ) !== $today ) {
        $pending = [ 'day' => $today ];
    }
    $before = $pending;

    if ( 'product' === $kind ) {
        $product_id = (int) $product_id;
        $ids        = isset( $pending['p'] ) && is_array( $pending['p'] ) ? $pending['p'] : [];
        $max        = defined( 'BRIKPANEL_MAX_DAILY_CART_PRODUCTS' ) ? (int) BRIKPANEL_MAX_DAILY_CART_PRODUCTS : 100;
        if ( $product_id > 0 && ! in_array( $product_id, $ids, true ) && count( $ids ) < $max ) {
            $ids[] = $product_id;
        }
        $pending['p'] = $ids;
    } elseif ( 'atc' === $kind ) {
        $pending['atc'] = 1;
    } elseif ( 'checkout' === $kind ) {
        $pending['co'] = 1;
    }

    if ( $pending !== $before ) {
        $session->set( BRIKPANEL_PENDING_KEY, $pending );
    }
}

/**
 * Count what this session parked, now that its browser proved itself.
 * Called by the tracker endpoint after the mark was confirmed; the counters'
 * own once-a-day rules still apply.
 *
 * @return int How many counts were released.
 */
function brikpanel_pending_flush() {
    $session = brikpanel_pending_session();
    if ( ! $session ) {
        return 0;
    }
    $pending = $session->get( BRIKPANEL_PENDING_KEY );
    if ( ! is_array( $pending ) || empty( $pending ) ) {
        return 0;
    }
    $session->set( BRIKPANEL_PENDING_KEY, null );

    // A count parked on an earlier day stays uncounted: it would land on the
    // wrong day, and a real shopper who came back proves their next add anyway.
    if ( ( $pending['day'] ?? '' ) !== brikpanel_human_today() ) {
        return 0;
    }

    $released = 0;
    if ( ! empty( $pending['atc'] ) && function_exists( 'brikpanel_count_store_add_to_cart' ) ) {
        $released += brikpanel_count_store_add_to_cart() ? 1 : 0;
    }
    if ( ! empty( $pending['p'] ) && is_array( $pending['p'] ) && function_exists( 'brikpanel_count_product_cart_addition' ) ) {
        foreach ( $pending['p'] as $product_id ) {
            $released += brikpanel_count_product_cart_addition( (int) $product_id ) ? 1 : 0;
        }
    }
    if ( ! empty( $pending['co'] ) && function_exists( 'brikpanel_count_checkout_visit' ) ) {
        $released += brikpanel_count_checkout_visit() ? 1 : 0;
    }
    return $released;
}

/* ---------------------------------------------------------------------------
 * Pages cached before this release
 * ------------------------------------------------------------------------- */

/**
 * When this site started running the person check (first request of the
 * release that brought it).
 *
 * @return int Unix time.
 */
function brikpanel_human_proof_since() {
    $since = (int) get_option( 'brikpanel_human_proof_since', 0 );
    if ( $since <= 0 ) {
        $since = time();
        add_option( 'brikpanel_human_proof_since', $since, '', 'yes' );
    }
    return $since;
}

/**
 * Whether a request from a tracker printed before this release may still be
 * counted the old way. Page caches keep serving that script for a while; for
 * BRIKPANEL_LEGACY_TRACKER_DAYS days it is counted as before, so a store with
 * a long-lived cache does not see its visitors drop to zero overnight. After
 * that, the old script is refused like any unproven client.
 *
 * @return bool
 */
function brikpanel_legacy_tracker_allowed() {
    $allowed = ( time() - brikpanel_human_proof_since() ) < BRIKPANEL_LEGACY_TRACKER_DAYS * DAY_IN_SECONDS;

    /**
     * Filters whether requests from a pre-3.3.30 cached tracker are still counted.
     *
     * @since 3.3.30
     *
     * @param bool $allowed True during the transition window.
     */
    return (bool) apply_filters( 'brikpanel_legacy_tracker_allowed', $allowed );
}

/**
 * First request with this release: start the transition clock, then ask
 * page caches to drop pages carrying the old tracker and let the bot
 * traffic cleanup look at the history soon instead of at its next nightly
 * run. Runs as a background job so no visitor waits for a cache purge.
 *
 * @return void
 */
function brikpanel_human_proof_rollout_register() {
    if ( ! class_exists( 'Brikpanel_Cron' ) ) {
        return;
    }
    Brikpanel_Cron::register_handler(
        'brikpanel_human_proof_rollout',
        'brikpanel_human_proof_rollout',
        static function () {
            return [
                'label'       => __( 'Visitor counting update', 'brikpanel' ),
                'description' => __( 'Clears page caches once so every page carries the new visitor tracker.', 'brikpanel' ),
            ];
        }
    );
}
add_action( 'brikpanel_cron_register', 'brikpanel_human_proof_rollout_register' );

/**
 * Starts the transition once, on the first request that has Action
 * Scheduler ready (init:30, after the cron registration at 20).
 *
 * @return void
 */
function brikpanel_human_proof_maybe_roll_out() {
    if ( false !== get_option( 'brikpanel_human_proof_since', false ) ) {
        return;
    }
    if ( ! class_exists( 'Brikpanel_Cron' ) || ! Brikpanel_Cron::is_available() ) {
        return;
    }
    brikpanel_human_proof_since();
    Brikpanel_Cron::enqueue_async( 'brikpanel_human_proof_rollout', [], [ 'unique' => true ] );
}
add_action( 'init', 'brikpanel_human_proof_maybe_roll_out', 30 );

/**
 * Background job: purge page caches and bring the bot traffic cleanup forward.
 *
 * @return void
 */
function brikpanel_human_proof_rollout() {
    if ( ! class_exists( 'Brikpanel_Cache_Clear' ) && defined( 'BRIKPANEL_PATH' ) && is_readable( BRIKPANEL_PATH . 'includes/brikpanel-cache-clear.php' ) ) {
        require_once BRIKPANEL_PATH . 'includes/brikpanel-cache-clear.php';
    }
    if ( class_exists( 'Brikpanel_Cache_Clear' ) && method_exists( 'Brikpanel_Cache_Clear', 'purge_all' ) ) {
        // Page caches only: the object cache holds nothing from the tracker.
        Brikpanel_Cache_Clear::purge_all( [ 'redis-object-cache' ] );
    }
    /** This action is documented in includes/brikpanel-consent.php */
    do_action( 'brikpanel_flush_page_cache' );

    if ( class_exists( 'Brikpanel_Cron' ) && Brikpanel_Cron::is_available() ) {
        Brikpanel_Cron::schedule_single( time() + 15 * MINUTE_IN_SECONDS, 'brikpanel_bot_traffic_autoclean', [], [ 'unique' => true ] );
    }
}
