<?php
/**
 * BrikPanel — consent-aware storefront tracking.
 *
 * Until 3.2.47 the storefront tracker created its identifiers the moment a
 * page loaded: the `brikpanel_vid` cookie (one year), two localStorage
 * latches and two daily counter cookies, all before any consent banner had
 * been answered. A wp.org report pointed out that under GDPR/ePrivacy a
 * persistent analytics identifier needs consent even when nothing leaves the
 * site, and the only remedy on offer was switching tracking off entirely —
 * which also emptied the Visitors, Live Visitors and conversion-funnel cards.
 *
 * This file is the middle ground. With "Wait for cookie consent" ticked
 * (WooCommerce ▸ Settings ▸ BrikPanel ▸ Analytics) BrikPanel writes nothing
 * at all until the visitor allows analytics, then starts without a reload,
 * and on withdrawal stops and deletes what it wrote. Consent is accepted
 * from any of three sources so agencies can wire up whatever CMP they run:
 *
 *   1. The WordPress Consent API   — wp_has_consent( 'statistics' )
 *   2. A JS call from any banner   — window.brikpanel_start_tracking()
 *   3. A PHP filter                — brikpanel_frontend_tracking_allowed
 *
 * Loaded at file scope (before `init`) because the counters that consult it
 * fire on very early hooks: woocommerce_add_to_cart and template_redirect.
 *
 * @package BrikPanel
 * @since   3.2.48
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Name of BrikPanel's own consent-record cookie.
 *
 * Holds the literal string "1" and nothing else — no id, no timestamp, no
 * link to a visitor. It records that this browser answered "yes", which is
 * exactly what a consent mechanism is supposed to remember, and it is only
 * ever written AFTER an affirmative grant.
 */
if ( ! defined( 'BRIKPANEL_CONSENT_COOKIE' ) ) {
    define( 'BRIKPANEL_CONSENT_COOKIE', 'brikpanel_consent' );
}

/**
 * Lifetime of the consent record. Mirrors the WP Consent API's own 30-day
 * default so a site running both does not end up with two consent records
 * that expire on different days.
 */
if ( ! defined( 'BRIKPANEL_CONSENT_COOKIE_TTL' ) ) {
    define( 'BRIKPANEL_CONSENT_COOKIE_TTL', 30 * DAY_IN_SECONDS );
}

/**
 * Whether the merchant asked BrikPanel to wait for consent before tracking.
 *
 * Defaults to off, so an existing install behaves exactly as it did before
 * this feature landed. Turning it on is what arms every gate below.
 *
 * @return bool
 */
function brikpanel_consent_required() {
    return 'yes' === get_option( 'brikpanel_tracking_require_consent', 'no' );
}

/**
 * Consent category BrikPanel's tracking belongs to.
 *
 * "statistics" rather than "statistics-anonymous": `brikpanel_vid` is a
 * unique per-browser identifier kept for a year, so the anonymous bucket
 * would be a false claim. Filterable for the rare site whose CMP models
 * categories differently — the same value is used server-side and printed
 * into the tracker script, so the two can never disagree.
 *
 * @return string
 */
function brikpanel_consent_category() {
    $category = apply_filters( 'brikpanel_consent_category', 'statistics' );
    return is_string( $category ) && '' !== $category ? $category : 'statistics';
}

/**
 * Whether this visitor has allowed analytics.
 *
 * Only consulted when the merchant turned "Wait for cookie consent" on.
 *
 * Deliberate divergence from the WP Consent API: `wp_has_consent()` returns
 * TRUE when no CMP has defined a consent type at all (its documented
 * "nothing is asking, so nothing is denied" stance). Honouring that here
 * would mean a merchant who explicitly ticked "wait for consent" still gets
 * tracked without being asked — the exact behaviour this feature exists to
 * fix. So an answer of "yes" always has to rest on something a visitor
 * actually did, in this order:
 *
 *   1. BrikPanel's own consent record.
 *   2. The Consent API's own decision cookie, whatever its value. Several
 *      popular banners (CookieYes, Moove GDPR Cookie Compliance) call
 *      wp_set_consent() from JavaScript without ever declaring a consent
 *      type in PHP, so the cookie is the only durable evidence they leave.
 *      A cookie that exists is a decision the visitor made, so it is
 *      trusted in both directions.
 *   3. wp_has_consent(), but only once a real CMP has declared a consent
 *      type. Opt-out regions keep working, because their consent type is
 *      non-empty and the API grants by default there.
 *
 * @return bool
 */
function brikpanel_consent_granted() {
    // 1) BrikPanel's own record. Written only after an affirmative grant and
    //    deleted on every withdrawal, so its presence always means "yes,
    //    right now". This is the only signal available when the site's CMP
    //    lives purely in JavaScript, and the only one the PHP-side counters
    //    (add-to-cart, checkout) can read at all.
    if ( isset( $_COOKIE[ BRIKPANEL_CONSENT_COOKIE ] )
        && '1' === sanitize_text_field( wp_unslash( $_COOKIE[ BRIKPANEL_CONSENT_COOKIE ] ) ) ) {
        return true;
    }

    if ( ! function_exists( 'wp_has_consent' ) ) {
        return false;
    }

    // 2) The Consent API's decision cookie for our category, if the banner
    //    has written one. Present means the visitor answered, so its value
    //    is authoritative even on banners that never declare a consent type.
    $cookie = brikpanel_consent_api_cookie_value();
    if ( null !== $cookie ) {
        return 'allow' === $cookie;
    }

    // 3) No cookie yet: fall back to the API, but only when a CMP is really
    //    driving it. Without that check "no banner configured" would read as
    //    "consent granted".
    if ( function_exists( 'wp_get_consent_type' ) ) {
        $consent_type = wp_get_consent_type();
        if ( is_string( $consent_type ) && '' !== $consent_type ) {
            return (bool) wp_has_consent( brikpanel_consent_category(), BRIKPANEL_BASENAME );
        }
    }

    return false;
}

/**
 * Value of the WP Consent API's decision cookie for BrikPanel's category.
 *
 * @return string|null 'allow' / 'deny' / other raw value, or null when the
 *                     banner has not written a decision for this visitor.
 */
function brikpanel_consent_api_cookie_value() {
    // The API exposes the prefix through a config object, not a plain
    // function. Prefer that when it is loaded; otherwise apply the very
    // filter it applies, which yields the same answer and keeps working on
    // a site that filters the prefix while the API itself is inactive.
    $prefix = '';
    if ( class_exists( 'WP_Consent_API' )
        && isset( WP_Consent_API::$config )
        && method_exists( WP_Consent_API::$config, 'consent_cookie_prefix' ) ) {
        $prefix = (string) WP_Consent_API::$config->consent_cookie_prefix();
    }
    if ( '' === $prefix ) {
        $prefix = (string) apply_filters( 'wp_consent_cookie_prefix', 'wp_consent' );
    }
    if ( '' === $prefix ) {
        $prefix = 'wp_consent';
    }

    $name = $prefix . '_' . brikpanel_consent_category();

    return isset( $_COOKIE[ $name ] )
        ? sanitize_text_field( wp_unslash( $_COOKIE[ $name ] ) )
        : null;
}

/**
 * Whether a consent platform is actively refusing analytics for this visitor.
 *
 * An explicit refusal has to outrank a client-supplied grant flag: a page
 * cached while the visitor was still allowing analytics would otherwise keep
 * asserting consent after they revoked it.
 *
 * The decision cookie is read even when the Consent API plugin is not
 * installed. Refusing on a "deny" we cannot fully validate can only ever make
 * BrikPanel more conservative, and it keeps the answer stable if the API is
 * deactivated while visitors still carry cookies it wrote. A "deny" never
 * overrides the brikpanel_frontend_tracking_allowed filter, which stays the
 * final word for sites that bridge their own platform.
 *
 * @return bool
 */
function brikpanel_consent_api_denies() {
    // An explicit decision cookie wins, whatever the banner declared.
    $cookie = brikpanel_consent_api_cookie_value();
    if ( null !== $cookie ) {
        return 'allow' !== $cookie;
    }

    if ( ! function_exists( 'wp_has_consent' ) || ! function_exists( 'wp_get_consent_type' ) ) {
        return false;
    }
    $consent_type = wp_get_consent_type();
    if ( ! is_string( $consent_type ) || '' === $consent_type ) {
        return false;
    }
    return ! wp_has_consent( brikpanel_consent_category(), BRIKPANEL_BASENAME );
}

/**
 * Whether BrikPanel may track this visitor right now.
 *
 * The single gate every storefront counter, beacon and endpoint asks. It
 * composes three things, in this order:
 *
 *   1. The master switch (Visitor tracking). When that is off nothing may
 *      turn tracking back on — not the consent state, not the filter.
 *   2. The "Wait for cookie consent" setting. Off (the default) means the
 *      answer is yes, exactly as it was before 3.2.48.
 *   3. The visitor's consent, from any of the three supported sources.
 *
 * Runs on hooks as hot as `woocommerce_add_to_cart`, so the option/cookie
 * work is cached per request. The filter itself is re-applied on every call
 * so an integration that registers late is never silently ignored.
 *
 * @param string $context Where the question comes from: 'endpoint',
 *                        'add_to_cart', 'checkout', or '' when unspecified.
 * @return bool
 */
function brikpanel_frontend_tracking_allowed( $context = '' ) {
    // The master switch is absolute: a filter must not be able to resurrect
    // tracking a merchant switched off.
    if ( function_exists( 'brikpanel_frontend_tracking_enabled' )
        && ! brikpanel_frontend_tracking_enabled() ) {
        return false;
    }

    static $base  = null;
    static $stamp = -1;

    $current = brikpanel_consent_cache_stamp();
    if ( null === $base || $stamp !== $current ) {
        $base  = ! brikpanel_consent_required() || brikpanel_consent_granted();
        $stamp = $current;
    }

    /**
     * Filter whether BrikPanel may track the current visitor.
     *
     * The integration point for cookie-consent platforms that do not speak
     * the WordPress Consent API: return false to hold tracking back, true to
     * release it, without touching plugin files.
     *
     * Called on storefront hooks and on the public tracking endpoint, so
     * keep the callback cheap. Returning true cannot switch tracking on
     * again when the merchant has turned Visitor tracking off entirely.
     *
     * @since 3.2.48
     *
     * @param bool   $allowed Whether tracking is currently allowed.
     * @param string $context 'endpoint' | 'add_to_cart' | 'checkout' | ''.
     */
    return (bool) apply_filters( 'brikpanel_frontend_tracking_allowed', $base, $context );
}

/**
 * Version counter for the per-request cache in
 * brikpanel_frontend_tracking_allowed().
 *
 * A plain `static $base` would be wrong: the tracking endpoint can record a
 * grant mid-request, after the gate has already answered "no" once, and a
 * withdrawal deletes the record the same way. Stamping the cache and bumping
 * the stamp on every consent change keeps the gate cheap without letting it
 * answer from a state that no longer exists.
 *
 * @param bool $bump True to invalidate.
 * @return int Current stamp.
 */
function brikpanel_consent_cache_stamp( $bump = false ) {
    static $stamp = 0;
    if ( $bump ) {
        $stamp++;
    }
    return $stamp;
}

/**
 * Invalidate the cached tracking decision for the rest of this request.
 *
 * @return void
 */
function brikpanel_consent_flush_cache() {
    brikpanel_consent_cache_stamp( true );
}

/**
 * Record an affirmative consent for this browser.
 *
 * Writes the consent-record cookie and mirrors it into $_COOKIE so the rest
 * of the current request already sees the new state. Safe to call more than
 * once; safe to call after headers are sent (the in-request mirror still
 * applies, only the cookie is skipped).
 *
 * Public on purpose: an integration whose consent logic lives entirely in
 * PHP can call this instead of implementing the filter.
 *
 * @return void
 */
function brikpanel_consent_record_grant() {
    $already = isset( $_COOKIE[ BRIKPANEL_CONSENT_COOKIE ] )
        && '1' === sanitize_text_field( wp_unslash( $_COOKIE[ BRIKPANEL_CONSENT_COOKIE ] ) );

    $_COOKIE[ BRIKPANEL_CONSENT_COOKIE ] = '1';
    brikpanel_consent_flush_cache();

    if ( $already || headers_sent() ) {
        return;
    }

    // Not HttpOnly, deliberately: the storefront tracker has to read this
    // from document.cookie on every subsequent page view, otherwise a CMP
    // that calls brikpanel_start_tracking() once at grant time would leave
    // every later page armed and silent. The value is the literal "1" — it
    // holds no secret and identifies nobody.
    brikpanel_consent_setcookie( BRIKPANEL_CONSENT_COOKIE, '1', time() + BRIKPANEL_CONSENT_COOKIE_TTL );
}

/**
 * Erase the consent record for this browser.
 *
 * @return void
 */
function brikpanel_consent_record_revoke() {
    unset( $_COOKIE[ BRIKPANEL_CONSENT_COOKIE ] );
    brikpanel_consent_flush_cache();

    if ( headers_sent() ) {
        return;
    }
    brikpanel_consent_setcookie( BRIKPANEL_CONSENT_COOKIE, '', time() - YEAR_IN_SECONDS );
}

/**
 * setcookie() wrapper that applies BrikPanel's cookie policy consistently.
 *
 * PHP 7.3+ accepts an options array (the only way to set SameSite); older
 * builds fall back to the positional signature. The plugin supports PHP 7.4
 * upward, so the array form is always available in practice — the fallback
 * exists because a wrong SameSite is a broken cookie, not a warning.
 *
 * @param string $name    Cookie name.
 * @param string $value   Cookie value.
 * @param int    $expires Absolute expiry timestamp.
 * @return void
 */
function brikpanel_consent_setcookie( $name, $value, $expires ) {
    if ( PHP_VERSION_ID >= 70300 ) {
        setcookie( $name, $value, [
            'expires'  => $expires,
            'path'     => COOKIEPATH ? COOKIEPATH : '/',
            'domain'   => COOKIE_DOMAIN,
            'secure'   => is_ssl(),
            'httponly' => false,
            'samesite' => 'Lax',
        ] );
        return;
    }
    setcookie( $name, $value, $expires, COOKIEPATH ? COOKIEPATH : '/', COOKIE_DOMAIN, is_ssl(), false );
}

/**
 * Every cookie BrikPanel's storefront tracking may create.
 *
 * One list, used by the forget endpoint and by the WP Consent API cookie
 * disclosure below, so a cookie can never be declared in one place and
 * forgotten in the other.
 *
 * @return string[]
 */
function brikpanel_tracking_cookie_names() {
    return [
        'brikpanel_vid',
        BRIKPANEL_CONSENT_COOKIE,
        'brikpanel_add_to_cart_count_cookie',
        'brikpanel_checkout_count_cookie',
    ];
}

/* ---------------------------------------------------------------------------
 * Forget-me endpoint
 * ------------------------------------------------------------------------- */

/**
 * Public endpoint: erase BrikPanel's identifiers for the calling browser.
 *
 * Called by window.brikpanel_stop_tracking() and automatically when a CMP
 * reports that analytics consent was withdrawn.
 *
 * What it deletes, and what it deliberately does not:
 *
 * - Cookies: only those present in the caller's OWN request, expired in the
 *   caller's OWN response. There is no way to expire another browser's
 *   cookie, so the blast radius is exactly one browser: the one asking.
 * - Live visitors: one row, keyed on the `brikpanel_vid` the caller sent.
 *   Evicting somebody else would mean already knowing their id, which is
 *   HttpOnly, generated by uniqid('bp_', true) and never echoed anywhere —
 *   and the prize would be removing a row from a store that self-expires in
 *   two minutes regardless.
 * - NOT the analytics tables. wp_brikpanel_visitors, _visited_pages,
 *   _referrers and _cart_tracking hold daily integer totals with no link to
 *   any visitor: there is nothing personal in them to erase, and making them
 *   decrementable from an unauthenticated endpoint would hand anyone a
 *   button that zeroes a merchant's analytics. This is the single most
 *   important security decision in this file.
 * - NOT the abandoned-cart table. That row exists because the visitor typed
 *   their email on purpose; it is a different lawful basis, and erasing it
 *   from a public endpoint would let a flood delete a merchant's recovery
 *   list.
 *
 * Not gated on the master switch: a visitor withdrawing consent after the
 * merchant turned tracking off must still get cleaned up. Not gated on the
 * bot filter either — a withdrawal is honoured whoever asks.
 *
 * No nonce, consistent with the other public endpoints in this module: a
 * nonce baked into cached storefront HTML goes stale, and forging a
 * "forget me" for yourself achieves what clearing your own cookies does.
 *
 * @return void
 */
function brikpanel_ajax_consent_forget() {
    $vid = isset( $_COOKIE['brikpanel_vid'] )
        ? substr( sanitize_text_field( wp_unslash( $_COOKIE['brikpanel_vid'] ) ), 0, 64 )
        : '';

    // Free and always safe: expire whatever the caller sent us.
    foreach ( brikpanel_tracking_cookie_names() as $cookie ) {
        if ( ! isset( $_COOKIE[ $cookie ] ) ) {
            continue;
        }
        unset( $_COOKIE[ $cookie ] );
        if ( ! headers_sent() ) {
            setcookie( $cookie, '', time() - YEAR_IN_SECONDS, COOKIEPATH, COOKIE_DOMAIN );
        }
    }
    brikpanel_consent_flush_cache();

    // No id means nothing is stored server-side for this browser. Returning
    // here is also what keeps the endpoint cheap under abuse: reaching any
    // storage at all requires the caller to supply a cookie first.
    if ( '' === $vid ) {
        wp_send_json_success( [ 'forgotten' => true ] );
    }

    // Same rate-limit shape as brikpanel_record_live_visitor(): object cache
    // when the host has one, transient otherwise so the limit survives
    // between requests.
    $rl_key   = 'bp_fg_' . md5( $vid );
    $has_oc   = function_exists( 'brikpanel_has_object_cache' ) && brikpanel_has_object_cache();
    $throttled = $has_oc ? wp_cache_get( $rl_key, 'brikpanel_live' ) : get_transient( $rl_key );
    if ( $throttled ) {
        wp_send_json_success( [ 'forgotten' => true, 'throttled' => true ] );
    }
    if ( $has_oc ) {
        wp_cache_set( $rl_key, 1, 'brikpanel_live', 10 );
    } else {
        set_transient( $rl_key, 1, 10 );
    }

    $visitors = get_transient( 'brikpanel_live_visitors' );
    if ( is_array( $visitors ) && isset( $visitors[ $vid ] ) ) {
        unset( $visitors[ $vid ] );
        set_transient( 'brikpanel_live_visitors', $visitors, 120 );
    }

    wp_send_json_success( [ 'forgotten' => true ] );
}
add_action( 'wp_ajax_nopriv_brikpanel_forget', 'brikpanel_ajax_consent_forget' );
add_action( 'wp_ajax_brikpanel_forget', 'brikpanel_ajax_consent_forget' );

/* ---------------------------------------------------------------------------
 * WordPress Consent API integration
 * ------------------------------------------------------------------------- */

/**
 * Declare BrikPanel to the WP Consent API — but only truthfully.
 *
 * The `wp_consent_api_registered_*` filter puts a green tick next to the
 * plugin in Site Health, meaning "this plugin honours consent". Claiming it
 * unconditionally would decorate an install that tracks without asking, so
 * it is claimed only while BrikPanel actually gates on consent (or tracks
 * nothing at all).
 *
 * Also discloses every cookie and localStorage key the tracking creates, so
 * a CMP can list them in the site's cookie policy automatically.
 *
 * @return void
 */
function brikpanel_consent_api_register() {
    if ( ! function_exists( 'wp_add_cookie_info' ) ) {
        return;
    }

    $tracking_on = ! function_exists( 'brikpanel_frontend_tracking_enabled' )
        || brikpanel_frontend_tracking_enabled();

    if ( brikpanel_consent_required() || ! $tracking_on ) {
        add_filter( 'wp_consent_api_registered_' . BRIKPANEL_BASENAME, '__return_true' );
    }

    if ( ! $tracking_on ) {
        return;
    }

    $category = brikpanel_consent_category();

    wp_add_cookie_info(
        'brikpanel_vid',
        'BrikPanel',
        $category,
        __( '1 year', 'brikpanel' ),
        __( 'Recognises a returning browser so a visit is counted once instead of once per page.', 'brikpanel' )
    );
    wp_add_cookie_info(
        BRIKPANEL_CONSENT_COOKIE,
        'BrikPanel',
        'functional',
        __( '30 days', 'brikpanel' ),
        __( 'Remembers that this visitor allowed analytics, so the choice survives the next page load.', 'brikpanel' )
    );
    wp_add_cookie_info(
        'brikpanel_add_to_cart_count_cookie',
        'BrikPanel',
        $category,
        __( 'Until midnight', 'brikpanel' ),
        __( 'Counts an add-to-cart once per day per visitor for the conversion funnel.', 'brikpanel' )
    );
    wp_add_cookie_info(
        'brikpanel_checkout_count_cookie',
        'BrikPanel',
        $category,
        __( 'Until midnight', 'brikpanel' ),
        __( 'Counts a checkout visit once per day per visitor for the conversion funnel.', 'brikpanel' )
    );
    wp_add_cookie_info(
        'brikpanel_visitor_viewed_*',
        'BrikPanel',
        $category,
        __( 'Until cleared', 'brikpanel' ),
        __( 'Marks that this browser has already been counted as a visitor today.', 'brikpanel' ),
        '',
        false,
        false,
        'LOCALSTORAGE'
    );
    wp_add_cookie_info(
        'brikpanel_product_viewed_*',
        'BrikPanel',
        $category,
        __( 'Until cleared', 'brikpanel' ),
        __( 'Marks that this browser has already been counted as a product viewer today.', 'brikpanel' ),
        '',
        false,
        false,
        'LOCALSTORAGE'
    );
}
add_action( 'init', 'brikpanel_consent_api_register', 9 );

/* ---------------------------------------------------------------------------
 * Page-cache invalidation
 * ------------------------------------------------------------------------- */

/**
 * Purge page caches when the consent setting is flipped.
 *
 * The tracker script is baked into cached storefront HTML, and the setting
 * decides which of its two shapes gets printed. Without a purge the change
 * fails in both directions: turning the setting ON leaves the old
 * unconditional script live on cached pages (the endpoint refuses those
 * pings, so nothing is actually tracked, but the requests keep firing), and
 * turning it OFF leaves the armed-and-silent script in place, which reads as
 * "tracking is dead" until the cache expires on its own.
 *
 * @param mixed $old Previous option value.
 * @param mixed $new New option value.
 * @return void
 */
function brikpanel_consent_purge_page_cache( $old, $new ) {
    if ( $old === $new ) {
        return;
    }
    if ( class_exists( 'Brikpanel_Cache_Clear' )
        && method_exists( 'Brikpanel_Cache_Clear', 'purge_all' ) ) {
        Brikpanel_Cache_Clear::purge_all();
    }

    /**
     * Fires after BrikPanel asked every page cache it recognises to purge.
     *
     * Escape hatch for hosts whose cache BrikPanel cannot detect.
     *
     * @since 3.2.48
     */
    do_action( 'brikpanel_flush_page_cache' );
}
add_action( 'update_option_brikpanel_tracking_require_consent', 'brikpanel_consent_purge_page_cache', 10, 2 );
add_action( 'update_option_brikpanel_frontend_tracking', 'brikpanel_consent_purge_page_cache', 10, 2 );
