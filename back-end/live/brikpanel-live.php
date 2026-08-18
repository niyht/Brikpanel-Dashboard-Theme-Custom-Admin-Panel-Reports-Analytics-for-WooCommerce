<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Seconds after which a visitor with no ping is considered inactive.
// Derived from the configurable ping interval (default 30s) with a 2.5x
// tolerance, and never below the historical 75s so the default behaviour
// is unchanged for existing installs.
if ( ! defined( 'BRIKPANEL_VISITOR_TIMEOUT' ) ) {
    $brikpanel_live_interval = function_exists( 'brikpanel_live_ping_interval' ) ? brikpanel_live_ping_interval() : 30;
    define( 'BRIKPANEL_VISITOR_TIMEOUT', max( 75, (int) ceil( $brikpanel_live_interval * 2.5 ) ) );
    unset( $brikpanel_live_interval );
}

// Hard cap on the number of visitors stored in the transient. Prevents the
// option row from ballooning under bot traffic on weak hosts. The dashboard
// only renders the active subset anyway.
if ( ! defined( 'BRIKPANEL_VISITOR_MAX' ) ) {
    define( 'BRIKPANEL_VISITOR_MAX', 500 );
}

// Minimum seconds between accepted pings from the same visitor. Drops
// duplicate / spammy pings cheaply before we touch the transient. Kept
// comfortably below the ping interval so legitimate pings are never eaten
// (matters when the merchant lowers the interval to its 10s floor).
if ( ! defined( 'BRIKPANEL_VISITOR_PING_INTERVAL' ) ) {
    $brikpanel_live_interval = function_exists( 'brikpanel_live_ping_interval' ) ? brikpanel_live_ping_interval() : 30;
    define( 'BRIKPANEL_VISITOR_PING_INTERVAL', min( 10, max( 5, $brikpanel_live_interval - 5 ) ) );
    unset( $brikpanel_live_interval );
}

// Bot detection (_brikpanel_is_bot_ua / brikpanel_is_bot_request) moved to
// includes/brikpanel-bot-filter.php in 3.2.30, where every tracker in the
// plugin shares one list plus the merchant's own exclusions. It used to live
// here, matching a handful of tokens that missed most of Google's non-
// "Googlebot" crawlers.

/* ----------------------------------------------------------
 * 1) Ziyaretçi ID (Cookie)
 * ---------------------------------------------------------- */
function _brikpanel_get_visitor_id() {
    if ( is_user_logged_in() && current_user_can( 'manage_options' ) ) {
        return false; 
    }
    $cookie_name = 'brikpanel_vid';
    if ( isset( $_COOKIE[ $cookie_name ] ) ) {
        return sanitize_text_field( wp_unslash( $_COOKIE[ $cookie_name ] ) );
    }
    $new_id = uniqid( 'bp_', true );
    setcookie( $cookie_name, $new_id, time() + YEAR_IN_SECONDS, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true );
    $_COOKIE[ $cookie_name ] = $new_id;
    return $new_id;
}


/* ----------------------------------------------------------
 * 2) Ziyaretçiyi Kaydet VEYA Sil (kayıt çekirdeği + legacy AJAX)
 * ---------------------------------------------------------- */
/**
 * Records (or removes) a live visitor in the transient store.
 *
 * Core write path shared by the unified tracker endpoint and the legacy
 * standalone AJAX action (kept for cached storefront pages that still carry
 * the pre-3.2.20 tracker JS). Callers are expected to have applied the
 * master-switch and bot-UA guards already.
 *
 * @param string $page_url Current page URL as reported by the tracker.
 * @param bool   $is_exit  True when the visitor's tab reported an exit.
 * @return string Status keyword: Tracked | Removed | Skipped | Throttled.
 */
function brikpanel_record_live_visitor( $page_url, $is_exit = false ) {
    $visitor_id = _brikpanel_get_visitor_id();
    if ( ! $visitor_id ) {
        return 'Skipped';
    }

    // Per-visitor rate limit. Use the object cache when available (in-memory,
    // O(1)). On hosts without one, wp_cache_* is per-request only — fall back
    // to a transient so the limit actually persists between requests.
    $rl_key = 'bp_lv_' . md5( $visitor_id );
    if ( function_exists( 'brikpanel_has_object_cache' ) && brikpanel_has_object_cache() ) {
        if ( wp_cache_get( $rl_key, 'brikpanel_live' ) ) {
            return 'Throttled';
        }
        wp_cache_set( $rl_key, 1, 'brikpanel_live', BRIKPANEL_VISITOR_PING_INTERVAL );
    } else {
        if ( get_transient( $rl_key ) ) {
            return 'Throttled';
        }
        set_transient( $rl_key, 1, BRIKPANEL_VISITOR_PING_INTERVAL );
    }

    // Visitor status detection: browsing / cart / order_received
    $visitor_status = 'browsing';
    $cart_count     = 0;

    if ( class_exists( 'WC_Cart' ) && function_exists( 'WC' ) && WC()->cart ) {
        $cart_count = WC()->cart->get_cart_contents_count();
        if ( $cart_count > 0 ) {
            $visitor_status = 'cart';
        }
    }

    // Check if visitor is on order-received (thank you) page
    if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'order-received' ) ) {
        $visitor_status = 'order_received';
    }

    // Collect customer info if logged in — only when the merchant keeps the
    // "Customer details in Live view" setting on. When off, the visit is
    // still counted but no personal fields are cached at all (data
    // minimisation for stores that want a fully anonymous Live view).
    $customer_name  = '';
    $customer_email = '';
    $customer_phone = '';

    $details_enabled = ! function_exists( 'brikpanel_live_customer_details_enabled' )
        || brikpanel_live_customer_details_enabled();

    if ( $details_enabled && is_user_logged_in() ) {
        $user  = wp_get_current_user();
        $first = get_user_meta( $user->ID, 'billing_first_name', true );
        $last  = get_user_meta( $user->ID, 'billing_last_name', true );
        $customer_name = trim( $first . ' ' . $last );
        if ( empty( $customer_name ) ) {
            $customer_name = $user->display_name;
        }
        $customer_email = $user->user_email;
        $customer_phone = get_user_meta( $user->ID, 'billing_phone', true );
    }

    // Visitor IP, one-way hashed. The raw address is never stored.
    //
    // This is a salted SHA-256 (HMAC), not a bare digest: the IPv4 space is
    // only ~4 billion addresses, so an unsalted hash of an IP is a rainbow
    // table away from being reversible no matter which algorithm is used. The
    // per-site salt (wp_salt) is what actually makes it one-way here, since an
    // attacker would need this site's salt to precompute anything.
    //
    // Only the first 10 characters are kept: the value is used purely as a
    // short, stable per-visitor label in the Live Visitors widget, never
    // compared against anything, so a longer digest would store more
    // identifying material for no benefit.
    $raw_ip    = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';
    $hashed_ip = '' === $raw_ip ? '' : substr( hash_hmac( 'sha256', $raw_ip, wp_salt( 'brikpanel_visitor_ip' ) ), 0, 10 );

    $visitors = get_transient( 'brikpanel_live_visitors' );
    if ( ! is_array( $visitors ) ) {
        $visitors = [];
    }

    if ( $is_exit ) {
        if ( isset( $visitors[ $visitor_id ] ) ) {
            unset( $visitors[ $visitor_id ] );
        }
    } else {
        // Device type, derived from the ping's own User-Agent.
        //
        // The ping is a real HTTP request made by the visitor's browser, so the
        // UA header belongs to that visitor: nothing extra has to be sent from
        // the client and no additional request is opened. Only the derived
        // keyword is stored (mobile | tablet | desktop) and never the raw UA,
        // which would be far more identifying than the widget needs.
        //
        // Same detector the daily mobile/tablet/desktop counters use, so the
        // Live card and the Devices breakdown can never disagree. Guarded like
        // every other cross-module call in this file: a missing module degrades
        // to no icon, never a fatal. Resolved here rather than above so exit
        // pings, which only delete a row, skip the UA match entirely.
        $device = function_exists( 'brikpanel_detect_device_type' ) ? brikpanel_detect_device_type() : '';

        $visitors[ $visitor_id ] = [
            'id'             => $visitor_id,
            'ip_address'     => $hashed_ip,
            'device'         => $device,
            'page_url'       => $page_url,
            'has_cart_item'  => $cart_count > 0 ? 'Yes' : 'No',
            'visitor_status' => $visitor_status,
            'cart_count'     => $cart_count,
            'customer_name'  => $customer_name,
            'customer_email' => $customer_email,
            'customer_phone' => $customer_phone,
            'last_active'    => time(),
        ];
    }

    // Cleanup: drop stale entries first so the cap below preserves recent
    // visitors. If the transient still exceeds the cap (bot flood), keep only
    // the most-recently-active entries — bounded memory beats completeness.
    $limit_time = time() - BRIKPANEL_VISITOR_TIMEOUT;
    foreach ( $visitors as $vid => $data ) {
        if ( ! isset( $data['last_active'] ) || $data['last_active'] < $limit_time ) {
            unset( $visitors[ $vid ] );
        }
    }

    if ( count( $visitors ) > BRIKPANEL_VISITOR_MAX ) {
        uasort( $visitors, static function ( $a, $b ) {
            return ( $b['last_active'] ?? 0 ) <=> ( $a['last_active'] ?? 0 );
        } );
        $visitors = array_slice( $visitors, 0, BRIKPANEL_VISITOR_MAX, true );
    }

    set_transient( 'brikpanel_live_visitors', $visitors, 120 );

    return $is_exit ? 'Removed' : 'Tracked';
}

/**
 * Legacy standalone AJAX action (pre-3.2.20 tracker JS).
 *
 * New pages ship the unified tracker (single combined request); this action
 * stays registered because page caches keep serving the old inline JS until
 * they expire. No nonce: this is a public endpoint reachable from any
 * frontend visitor — abuse is bounded by the bot UA filter, the per-visitor
 * rate limit and the hard transient cap inside the record function.
 */
function brikpanel_track_live_visitor() {
    // Master tracking switch plus the cookie-consent gate. Cached storefront
    // pages keep firing the old JS until the page cache expires — so the
    // endpoint must refuse too, and it must do so on server-side state
    // rather than on anything the stale script did or did not send.
    if ( function_exists( 'brikpanel_frontend_tracking_allowed' ) && ! brikpanel_frontend_tracking_allowed( 'endpoint' ) ) {
        wp_send_json_success( 'Disabled' );
    }

    // Guarded like every other call site: the detector moved to
    // includes/brikpanel-bot-filter.php in 3.2.30, and this is a public
    // endpoint — a missing module file must degrade, never fatal.
    if ( function_exists( '_brikpanel_is_bot_ua' ) && _brikpanel_is_bot_ua() ) {
        wp_send_json_success( 'Skipped' );
    }

    $page_url = isset( $_POST['page_url'] ) ? esc_url_raw( wp_unslash( $_POST['page_url'] ) ) : '';
    $is_exit  = isset( $_POST['is_exit'] ) && $_POST['is_exit'] === 'true';

    wp_send_json_success( brikpanel_record_live_visitor( $page_url, $is_exit ) );
}
add_action( 'wp_ajax_nopriv_brikpanel_track_live_visitor', 'brikpanel_track_live_visitor' );
add_action( 'wp_ajax_brikpanel_track_live_visitor', 'brikpanel_track_live_visitor' );


/* ----------------------------------------------------------
 * 3) AJAX: Veriyi Oku (Admin Dashboard)
 * ---------------------------------------------------------- */
function brikpanel_get_live_data() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Unauthorized' );
    }

    $visitors = get_transient( 'brikpanel_live_visitors' );
    if ( ! is_array( $visitors ) ) {
        $visitors = [];
    }

    // Gösterirken de 25 saniyeden eski olanları filtrele
    $limit_time = time() - BRIKPANEL_VISITOR_TIMEOUT;
    $active_visitors = [];
    
    foreach ( $visitors as $vid => $data ) {
        if ( isset($data['last_active']) && $data['last_active'] >= $limit_time ) {
            $active_visitors[] = $data;
        }
    }

    wp_send_json_success( $active_visitors );
}
add_action( 'wp_ajax_brikpanel_get_live_data', 'brikpanel_get_live_data' );


/* ----------------------------------------------------------
 * 4) Frontend tracker JS
 * ----------------------------------------------------------
 * The inline live tracker that used to be printed here moved into the
 * unified tracker (back-end/tracking/brikpanel-unified-tracker.php) in
 * 3.2.20: one combined request per page view instead of separate live +
 * page-view + visitor + product calls, and no per-navigation exit beacon
 * (visitors now expire via BRIKPANEL_VISITOR_TIMEOUT instead).
 * ---------------------------------------------------------- */