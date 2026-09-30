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

// Seconds an open page may go without any interaction (pointer, key, wheel,
// touch, page scroll) before its visitor stops counting as live. A page pings
// for as long as it is open and on screen, so without this limit a tab left
// open on a desk, or a program parked on a page, stayed "live" for days.
// Thirty minutes is the inactivity limit Microsoft Clarity ends a session at.
if ( ! defined( 'BRIKPANEL_VISITOR_IDLE_TIMEOUT' ) ) {
    define( 'BRIKPANEL_VISITOR_IDLE_TIMEOUT', 30 * MINUTE_IN_SECONDS );
}

/**
 * The idle limit in seconds.
 *
 * Never shorter than the ping timeout: a visitor cannot count as idle before
 * they could even count as gone.
 *
 * @return int
 */
function brikpanel_live_idle_timeout() {
    return max( (int) BRIKPANEL_VISITOR_IDLE_TIMEOUT, (int) BRIKPANEL_VISITOR_TIMEOUT );
}

/**
 * Lifetime of the stored Live list, for every writer of it.
 *
 * At least one ping timeout. With a long ping interval (up to 300 s) a fixed
 * 120 s let the whole list expire between two pings of the same open tab on a
 * quiet store: the visitor blinked out, and a tab running an older tracker
 * restarted its idle clock every time.
 *
 * @return int
 */
function brikpanel_live_store_lifetime() {
    return max( 120, (int) BRIKPANEL_VISITOR_TIMEOUT );
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
    // A value this plugin never minted counts as no cookie: it is overwritten
    // below instead of becoming a transient key the client chose (since
    // 3.3.2; see brikpanel_visitor_id_is_valid()).
    if ( function_exists( 'brikpanel_visitor_id_from_cookie' ) ) {
        $known = brikpanel_visitor_id_from_cookie();
        if ( '' !== $known ) {
            return $known;
        }
    } elseif ( isset( $_COOKIE[ $cookie_name ] ) ) {
        return sanitize_text_field( wp_unslash( $_COOKIE[ $cookie_name ] ) );
    }
    $new_id = uniqid( 'bp_', true );
    setcookie( $cookie_name, $new_id, time() + YEAR_IN_SECONDS, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true );
    $_COOKIE[ $cookie_name ] = $new_id;
    return $new_id;
}


/**
 * Where a live visitor came from, for the Live visitors list.
 *
 * Channel and source reuse brikpanel_classify_traffic_source(), the same rules
 * behind the dashboard's Traffic Sources tab, so the two never disagree. The
 * campaign fields come from the landing address's utm_* tags. Only the
 * landing page's path is kept, never its query string or the full referring
 * address, which can carry personal details a site put into a link.
 *
 * @param string $referrer    Referring page of the visit's entry ('' when none).
 * @param string $landing_url Address the visit landed on.
 * @return array|null { channel, name, medium, campaign, term, landing }, or
 *                    null when there is nothing to classify.
 */
function brikpanel_live_visitor_source( $referrer, $landing_url ) {
    $referrer    = is_string( $referrer ) ? $referrer : '';
    $landing_url = is_string( $landing_url ) ? $landing_url : '';
    if ( '' === $landing_url || ! function_exists( 'brikpanel_classify_traffic_source' ) ) {
        return null;
    }

    $site_host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
    $class     = brikpanel_classify_traffic_source( $referrer, $landing_url, $site_host );

    $params = [];
    $query  = (string) wp_parse_url( $landing_url, PHP_URL_QUERY );
    if ( '' !== $query ) {
        wp_parse_str( $query, $params );
    }
    $clip = static function ( $value, $max ) {
        $value = trim( sanitize_text_field( (string) $value ) );
        return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $max ) : substr( $value, 0, $max );
    };
    $tag = static function ( $key ) use ( $params, $clip ) {
        return ( isset( $params[ $key ] ) && is_string( $params[ $key ] ) ) ? $clip( $params[ $key ], 80 ) : '';
    };

    // The name shown after the channel: the referring site when the browser
    // sent one, else the campaign's source tag, else the ad network whose
    // click tag is on the link (an ad click with the referrer stripped).
    $name = $clip( $class['host'] ?? '', 80 );
    if ( '' === $name ) {
        $name = $tag( 'utm_source' );
    }
    if ( '' === $name ) {
        $click_tags = [
            'gclid'   => 'google',
            'gbraid'  => 'google',
            'wbraid'  => 'google',
            'gclsrc'  => 'google',
            'msclkid' => 'bing',
            'fbclid'  => 'facebook',
            'ttclid'  => 'tiktok',
        ];
        foreach ( $click_tags as $param => $network ) {
            if ( isset( $params[ $param ] ) ) {
                $name = $network;
                break;
            }
        }
    }

    // Decoded first: sanitize_text_field() drops %XX sequences, which would
    // turn a path like /%C3%BCr%C3%BCn/ into gibberish instead of /ürün/.
    $landing = rawurldecode( (string) wp_parse_url( $landing_url, PHP_URL_PATH ) );

    return [
        'channel'  => sanitize_key( (string) ( $class['channel'] ?? 'direct' ) ),
        'name'     => $name,
        'medium'   => $tag( 'utm_medium' ),
        'campaign' => $tag( 'utm_campaign' ),
        'term'     => $tag( 'utm_term' ),
        'landing'  => $clip( '' === $landing ? '/' : $landing, 200 ),
    ];
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
 * Each row also keeps `interacted_at`, the last moment a person touched one of
 * the visitor's pages, which brikpanel_live_active_visitors() compares with
 * the idle limit. The current tracker reports it as `$idle`. A tracker from
 * before the idle limit (a page cached earlier, or a tab opened before the
 * update, which keeps running the old script until it is reloaded) cannot
 * see interaction: its recurring pings pass null and leave the stored clock
 * where it is, so the clock only moves on a page load. That is what makes a
 * tab forgotten before the update drop out, instead of pinging itself back.
 *
 * @param string   $page_url Current page URL as reported by the tracker.
 * @param bool     $is_exit  True when the visitor's tab reported an exit.
 * @param array    $entry    Optional entry source from the tracker: 'ref' (the
 *                           referring page) and 'url' (the landing address).
 * @param int|null $idle     Seconds since the visitor last interacted with the
 *                           page (0 on a page load), or null when the tracker
 *                           cannot tell.
 * @param string   $page_ref The page as "post:ID", "term:ID" or "front" (see
 *                           brikpanel_live_page_names()), '' when unknown.
 * @return string Status keyword: Tracked | Removed | Skipped | Throttled.
 */
function brikpanel_record_live_visitor( $page_url, $is_exit = false, $entry = [], $idle = null, $page_ref = '' ) {
    // Burst lock for a client that did not bring our cookie back (3.3.11).
    //
    // Each row here is keyed on the brikpanel_vid cookie, and a client that
    // never returns it is handed a fresh id on every ping — so every ping was
    // a new "live visitor" for the next BRIKPANEL_VISITOR_TIMEOUT seconds. A
    // crawler running JavaScript with a blank profile per page put forty
    // people on a Live view of a store that sees a hundred a day.
    //
    // So before minting an id, the passive identity (address + request
    // headers, hashed, never stored raw) is locked for one timeout window: a
    // memoryless client gets at most one row at a time. A real first-time
    // visitor is unaffected in practice: their first ping mints the row and
    // the lock, their second ping carries the cookie and updates that same
    // row. Two brand-new visitors behind one address with byte-identical
    // headers inside one window: the second one's first ping writes no row
    // but still receives a cookie, so its next ping is a returning client
    // with a row of its own. Exit pings never mint, so they skip this.
    //
    // Decided BEFORE _brikpanel_get_visitor_id(), which writes the new id into
    // $_COOKIE; the raw-header reader is what tells a returning client apart.
    $burst_locked = false;
    if ( ! $is_exit
        && function_exists( 'brikpanel_request_carried_visitor_id' )
        && '' === brikpanel_request_carried_visitor_id()
        && function_exists( 'brikpanel_client_bucket_key' ) ) {
        $burst_key = brikpanel_client_bucket_key( 'live' );
        if ( '' !== $burst_key ) {
            if ( function_exists( 'brikpanel_has_object_cache' ) && brikpanel_has_object_cache() ) {
                $burst_locked = (bool) wp_cache_get( $burst_key, 'brikpanel_live' );
                if ( ! $burst_locked ) {
                    wp_cache_set( $burst_key, 1, 'brikpanel_live', BRIKPANEL_VISITOR_TIMEOUT );
                }
            } else {
                $burst_locked = (bool) get_transient( $burst_key );
                if ( ! $burst_locked ) {
                    set_transient( $burst_key, 1, BRIKPANEL_VISITOR_TIMEOUT );
                }
            }
        }
    }

    // Mints and sets the cookie even when the burst lock refuses the row:
    // a memoryless client discards it anyway, and a real visitor caught in a
    // collision needs it so their very next ping stands on its own.
    $visitor_id = _brikpanel_get_visitor_id();
    if ( ! $visitor_id ) {
        return 'Skipped';
    }
    if ( $burst_locked ) {
        return 'Throttled';
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
            // Stored and later written as text; display names are kept HTML-encoded.
            $customer_name = brikpanel_plain_name( $user->display_name );
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
    $now = time();

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

        // Where the visit came from, while "Traffic source in Live view" is
        // on. The row below is rebuilt on every ping, so a ping without a
        // usable entry (a page cached with an older tracker) keeps the source
        // an earlier ping established instead of wiping it.
        $source = null;
        if ( function_exists( 'brikpanel_live_traffic_source_enabled' ) && brikpanel_live_traffic_source_enabled() ) {
            $source = is_array( $entry ) ? brikpanel_live_visitor_source( $entry['ref'] ?? '', $entry['url'] ?? '' ) : null;
            if ( null === $source && isset( $visitors[ $visitor_id ]['source'] ) && is_array( $visitors[ $visitor_id ]['source'] ) ) {
                $source = $visitors[ $visitor_id ]['source'];
            }
        }

        // Last interaction. The stored clock only counts while the row is
        // still live; a stale row left behind until the next write starts
        // over. max() because two tabs of one browser share this row: a tab
        // about to go idle must not hide a visitor busy in the other one.
        $previous = 0;
        if ( isset( $visitors[ $visitor_id ]['last_active'], $visitors[ $visitor_id ]['interacted_at'] )
            && (int) $visitors[ $visitor_id ]['last_active'] >= $now - BRIKPANEL_VISITOR_TIMEOUT ) {
            $previous = (int) $visitors[ $visitor_id ]['interacted_at'];
        }
        if ( null === $idle ) {
            $interacted_at = $previous > 0 ? $previous : $now;
        } else {
            $interacted_at = max( $previous, $now - min( max( 0, (int) $idle ), DAY_IN_SECONDS ) );
        }

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
            'last_active'    => $now,
            'interacted_at'  => $interacted_at,
        ];
        if ( null !== $source ) {
            $visitors[ $visitor_id ]['source'] = $source;
        }
        // Belongs to this ping's page only, so unlike the source it is never
        // carried over: a page from an older tracker shows its address.
        if ( is_string( $page_ref ) && '' !== $page_ref ) {
            $visitors[ $visitor_id ]['page_ref'] = $page_ref;
        }
    }

    // Cleanup: drop stale entries first so the cap below preserves recent
    // visitors. If the transient still exceeds the cap (bot flood), keep only
    // the most-recently-active entries — bounded memory beats completeness.
    $limit_time = $now - BRIKPANEL_VISITOR_TIMEOUT;
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

    set_transient( 'brikpanel_live_visitors', $visitors, brikpanel_live_store_lifetime() );

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
 *
 * That script cannot report interaction, so its pings leave the idle clock
 * alone; it deletes its row on every navigation (the exit beacon), which
 * starts a fresh clock on the next page.
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
/**
 * The visitors the Live list shows right now.
 *
 * One rule for every place that reads the list (the dashboard card, the top
 * bar's live count and the legacy read action), so they can never disagree:
 * a visitor is live while their page still pings (BRIKPANEL_VISITOR_TIMEOUT)
 * and somebody touched it within the idle limit. An idle row stays stored and
 * is only left out here: dropping it would let the next ping of a tab that
 * runs an older tracker come back with a fresh clock.
 *
 * @return array[] Rows as stored, without the traffic source while "Traffic
 *                 source in Live view" is off.
 */
function brikpanel_live_active_visitors() {
    $visitors = get_transient( 'brikpanel_live_visitors' );
    if ( ! is_array( $visitors ) ) {
        return [];
    }

    $now        = time();
    $seen_after = $now - BRIKPANEL_VISITOR_TIMEOUT;
    $idle_limit = brikpanel_live_idle_timeout();
    // Rows recorded before "Traffic source in Live view" was switched off
    // can still carry a source for a minute or two; do not show it.
    $show_source = ! function_exists( 'brikpanel_live_traffic_source_enabled' ) || brikpanel_live_traffic_source_enabled();

    $active = [];
    foreach ( $visitors as $data ) {
        if ( ! is_array( $data ) || ! isset( $data['last_active'] ) || (int) $data['last_active'] < $seen_after ) {
            continue;
        }
        // Rows written before the idle limit existed have no clock yet; the
        // next ping gives them one.
        if ( isset( $data['interacted_at'] ) && $now - (int) $data['interacted_at'] >= $idle_limit ) {
            continue;
        }
        if ( ! $show_source ) {
            unset( $data['source'] );
        }
        $active[] = $data;
    }

    return $active;
}

/**
 * Adds 'page_title' to Live list rows: the name of the page each visitor is
 * on (product, page, category), read at display time from the post or term
 * the tracker reported, so a renamed product shows its new name.
 *
 * The id comes from the visitor's browser, so only something a visitor can
 * actually be looking at gets a name: a publicly viewable post or a term of a
 * public taxonomy. Anything else (a draft or private id sent by hand, a
 * deleted product), pages with no object of their own (search results, 404)
 * and rows from an older tracker keep '' and the list shows the address.
 *
 * @param array[] $rows Rows from brikpanel_live_active_visitors().
 * @return array[] The same rows, each with 'page_title'.
 */
function brikpanel_live_page_names( $rows ) {
    $refs  = [];
    $posts = [];
    $terms = [];
    foreach ( $rows as $i => $row ) {
        $ref = ( isset( $row['page_ref'] ) && is_string( $row['page_ref'] ) ) ? $row['page_ref'] : '';
        if ( 'front' === $ref ) {
            $refs[ $i ] = [ 'front', 0 ];
        } elseif ( preg_match( '/^(post|term):([1-9][0-9]{0,18})$/', $ref, $m ) ) {
            $refs[ $i ] = [ $m[1], (int) $m[2] ];
            if ( 'post' === $m[1] ) {
                $posts[ (int) $m[2] ] = (int) $m[2];
            } else {
                $terms[ (int) $m[2] ] = (int) $m[2];
            }
        }
    }

    // One query each, however many visitors are live.
    if ( $posts ) {
        _prime_post_caches( array_values( $posts ), false, false );
    }
    if ( $terms ) {
        _prime_term_caches( array_values( $terms ), false );
    }

    foreach ( $rows as $i => $row ) {
        $name = '';
        if ( isset( $refs[ $i ] ) ) {
            list( $type, $id ) = $refs[ $i ];
            if ( 'front' === $type ) {
                $name = __( 'Home page', 'brikpanel' );
            } elseif ( 'post' === $type ) {
                $post = get_post( $id );
                // get_the_title() is display HTML ("&#038;", "&#8211;"); the
                // list writes the name as text. Same as Most Viewed Pages.
                if ( $post instanceof WP_Post && is_post_publicly_viewable( $post ) ) {
                    $name = brikpanel_plain_label( get_the_title( $post ) );
                }
            } else {
                $term = get_term( $id );
                if ( $term instanceof WP_Term && is_taxonomy_viewable( $term->taxonomy ) ) {
                    $name = brikpanel_plain_name( $term->name );
                }
            }
        }
        $rows[ $i ]['page_title'] = $name;
    }

    return $rows;
}

function brikpanel_get_live_data() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Unauthorized' );
    }

    wp_send_json_success( brikpanel_live_active_visitors() );
}
add_action( 'wp_ajax_brikpanel_get_live_data', 'brikpanel_get_live_data' );


/* ----------------------------------------------------------
 * 4) Frontend tracker JS
 * ----------------------------------------------------------
 * The inline live tracker that used to be printed here moved into the
 * unified tracker (back-end/tracking/brikpanel-unified-tracker.php) in
 * 3.2.20: one combined request per page view instead of separate live +
 * page-view + visitor + product calls, and no per-navigation exit beacon
 * (visitors now expire via BRIKPANEL_VISITOR_TIMEOUT instead). It stops
 * pinging once its page has gone BRIKPANEL_VISITOR_IDLE_TIMEOUT without any
 * interaction, and pings again the moment someone touches the page.
 * ---------------------------------------------------------- */