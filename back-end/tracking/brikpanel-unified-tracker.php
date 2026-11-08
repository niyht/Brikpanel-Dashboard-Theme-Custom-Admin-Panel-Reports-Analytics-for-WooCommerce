<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Unified frontend tracker (3.2.20, person check 3.3.30).
 *
 * Before 3.2.20 every storefront page view fired up to four separate
 * admin-ajax requests (page view, live-visitor ping, daily visitor count,
 * product view) plus an exit beacon on every navigation. Now a single
 * combined request per page view carries all of those signals, the recurring
 * live ping reuses the same endpoint with only the live part, and the exit
 * beacon is gone (live visitors expire via BRIKPANEL_VISITOR_TIMEOUT).
 *
 * The recurring ping stops once nobody has touched the page for
 * BRIKPANEL_VISITOR_IDLE_TIMEOUT (30 minutes), and resumes on the next
 * interaction.
 *
 * Since 3.3.30 nothing is sent for a browser until it has shown it is a
 * person (includes/brikpanel-human-proof.php): the page must be on screen
 * (not a prerender, not a background tab) and the visitor must move the
 * pointer, touch, scroll with the wheel or press a key. A browser that
 * already carries the "this is a person" mark sends at once. The server
 * counts the visitor and the product view once per store day from the mark
 * itself, so a page served from an old cache, or a page left before the
 * server answered, can no longer count one person twice.
 *
 * The old standalone AJAX actions stay registered for pages cached with
 * older trackers, and are counted the old way only during the transition
 * window (brikpanel_legacy_tracker_allowed()). Merchants can also disable
 * all tracking (WooCommerce ▸ Settings ▸ BrikPanel ▸ Analytics) or stretch
 * the live ping interval up to 300 s.
 */

/**
 * Combined AJAX endpoint: records every tracking signal of a page view in
 * one request.
 *
 * POST fields (all optional, each part runs only when its flag is present):
 *   tv=2                    — sent by the 3.3.30 tracker. Without it the
 *                             request comes from a page cached with an older
 *                             tracker (brikpanel_unified_track_legacy()).
 *   human=1                 — the tracker saw a real person on this page
 *                             (first input event). Asks for the mark.
 *   open=1                  : the page is on screen and nobody touched it
 *                             yet (3.3.41). Counted only while "Count
 *                             visitors when the page opens" is on, and never
 *                             earns the mark.
 *   live=1, page_url        — live-visitor ping (rate-limited server-side).
 *   idle                    — with live: seconds since the visitor last
 *                             touched the page (0 on a page load).
 *   src_ref, src_url        — with live: where the visit began (referring
 *                             page, landing address). Only sent while
 *                             "Traffic source in Live view" is on.
 *   live_page               — with live: the page as "post:ID", "term:ID"
 *                             or "front", so the Live list can name it.
 *   page_id, page_type      — page-view counter for "Most visited pages".
 *   visitor=1, ref, url     — daily visitor + device + traffic-source count.
 *   product=1               — daily product-view counter.
 *   campaign                : the landing address's campaign (utm_campaign),
 *                             once per campaign a day: visits per campaign for
 *                             the dashboard's "Top campaigns".
 *   consent=1               — the visitor has allowed analytics (only sent
 *                             while the "Wait for cookie consent" setting is
 *                             on, and only after the site's own consent code
 *                             said yes).
 *
 * Reply `human`: 'ok' (counted as a person; the mark cookie travels with the
 * reply), 'need' (nothing recorded; ask again after a real input event),
 * 'open' (counted on page open; no mark, so the tracker keeps waiting for a
 * person's input).
 *
 * No nonce: this is a public endpoint reachable from any frontend visitor,
 * and nonces printed into cached storefront HTML go stale anyway. Abuse is
 * bounded by the person check, the bot filter, the per-visitor rate limit
 * on the live part and hard caps on stored data, and every write is an
 * anonymous counter increment.
 */
function brikpanel_ajax_unified_track() {
    // Master tracking switch — also refuses pings from cached pages that
    // still carry the tracker after the merchant turned tracking off.
    if ( function_exists( 'brikpanel_frontend_tracking_enabled' ) && ! brikpanel_frontend_tracking_enabled() ) {
        wp_send_json_success( [ 'disabled' => true ] );
    }
    // Store staff (administrators, shop managers) are never counted.
    if ( function_exists( 'brikpanel_is_admin_user' ) && brikpanel_is_admin_user() ) {
        wp_send_json_success( [ 'skipped' => true ] );
    }
    // Named crawlers, self-declared agents, prefetches, excluded addresses.
    if ( function_exists( '_brikpanel_is_bot_ua' ) && _brikpanel_is_bot_ua() ) {
        wp_send_json_success( [ 'skipped' => true ] );
    }

    // Promote an explicit client-side grant into a server-readable record, so
    // the PHP-only counters (add-to-cart, checkout) can see it too.
    //
    // Placed after the admin and bot guards on purpose: a crawler must never
    // be able to mint a consent record for itself. And a consent platform
    // that is actively refusing always outranks the flag — a page cached
    // while the visitor still allowed analytics would otherwise keep
    // asserting consent after they revoked it.
    if ( function_exists( 'brikpanel_consent_required' ) && brikpanel_consent_required()
        && ! empty( $_POST['consent'] )
        && function_exists( 'brikpanel_consent_record_grant' )
        && ! ( function_exists( 'brikpanel_consent_api_denies' ) && brikpanel_consent_api_denies() ) ) {
        brikpanel_consent_record_grant();
    }

    // Consent gate. Everything below this line can create cookies, so
    // nothing may run until the visitor has allowed analytics.
    //
    // The answer comes from server-side state, never from "the request did
    // not carry a consent flag". A page cached BEFORE the merchant enabled
    // the setting still ships the old unconditional script, which sends no
    // flag — indistinguishable from a visitor who declined, and both must be
    // refused.
    if ( function_exists( 'brikpanel_frontend_tracking_allowed' )
        && ! brikpanel_frontend_tracking_allowed( 'endpoint' ) ) {
        wp_send_json_success( [ 'consent_required' => true ] );
    }

    $tracker_version = isset( $_POST['tv'] ) ? sanitize_key( wp_unslash( $_POST['tv'] ) ) : '';
    if ( '2' !== $tracker_version || ! function_exists( 'brikpanel_human_state' ) ) {
        brikpanel_unified_track_legacy();
        return;
    }

    // The person check. A browser carrying a valid mark (or a signed-in
    // customer) is a person; anyone else must have reported a real input
    // event and pass the server's own checks. With "Count visitors when the
    // page opens" on (3.3.41), a page that opened on screen passes the same
    // checks and is counted, but gets no person mark (see "opened" below).
    $state  = brikpanel_human_state();
    $opened = false;
    if ( ! $state['valid'] && ! is_user_logged_in() ) {
        $opened = empty( $_POST['human'] ) && ! empty( $_POST['open'] )
            && function_exists( 'brikpanel_count_on_open_enabled' ) && brikpanel_count_on_open_enabled()
            && function_exists( 'brikpanel_open_issue' );
        if ( empty( $_POST['human'] ) && ! $opened ) {
            wp_send_json_success( [ 'human' => 'need' ] );
        }
        if ( '' !== brikpanel_human_claim_refused() ) {
            wp_send_json_success( [ 'skipped' => true ] );
        }
        // A browser that brought none of our cookies back keeps no memory we
        // could rely on, so its address and browser get one mark a day. The
        // id cookie is still handed out: a real visitor who shares both with
        // someone already counted (an office, a mobile carrier) brings it
        // back on the next input event and is counted then. A client that
        // never keeps cookies never is.
        if ( function_exists( 'brikpanel_request_carried_visitor_id' )
            && '' === brikpanel_request_carried_visitor_id()
            && function_exists( 'brikpanel_client_daily_lock' )
            && ! brikpanel_client_daily_lock( 'human' ) ) {
            if ( function_exists( '_brikpanel_get_visitor_id' ) ) {
                _brikpanel_get_visitor_id();
            }
            wp_send_json_success( [ 'human' => 'need' ] );
        }
    }

    $today = brikpanel_human_today();
    if ( $state['valid'] && $state['today'] ) {
        $flags = $state['flags'];
    } else {
        // What a page that opened earlier today already counted (3.3.41), so
        // the touch that follows does not count the same visitor again.
        $flags = function_exists( 'brikpanel_open_state' ) ? brikpanel_open_state()['flags'] : '';
    }
    $done = [ 'human' => $opened ? 'open' : 'ok', 'day' => $today ];

    // 1) Live-visitor ping (rate limit + transient cap live inside). Not for
    //    a page counted on open: the Live list stays the people who touched.
    if ( ! $opened && ! empty( $_POST['live'] ) && function_exists( 'brikpanel_record_live_visitor' ) ) {
        $done['live'] = brikpanel_unified_track_live();
    }

    // 2) Page-view counter (every page view, only for a page a visitor can
    //    actually be looking at).
    $page_id = isset( $_POST['page_id'] ) ? absint( wp_unslash( $_POST['page_id'] ) ) : 0;
    if ( $page_id > 0 && function_exists( 'brikpanel_record_page_view' ) ) {
        $page_type = isset( $_POST['page_type'] ) ? sanitize_key( wp_unslash( $_POST['page_type'] ) ) : 'post';
        if ( ! function_exists( 'brikpanel_view_target_is_public' ) || brikpanel_view_target_is_public( $page_id, $page_type ) ) {
            brikpanel_record_page_view( $page_id, $page_type );
            $done['pv'] = true;
        }
    }

    // 3) Daily visitor + traffic source: once per store day, decided by the
    //    mark's own flag rather than by anything the page carries.
    if ( ! empty( $_POST['visitor'] ) && false === strpos( $flags, 'v' ) && function_exists( 'brikpanel_record_visitor_view' ) ) {
        $referrer    = isset( $_POST['ref'] ) ? esc_url_raw( wp_unslash( $_POST['ref'] ) ) : '';
        $landing_url = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';
        brikpanel_record_visitor_view( $referrer, $landing_url, false );
        $flags .= 'v';
    }

    // 4) Daily product-view counter, same rule.
    if ( ! empty( $_POST['product'] ) && false === strpos( $flags, 'p' ) && function_exists( 'brikpanel_record_product_view' ) ) {
        brikpanel_record_product_view( false );
        $flags .= 'p';
    }

    // 5) A visit from a campaign link (the landing address's utm_campaign),
    //    sent once per campaign a day. The flag in the reply arms that latch,
    //    also when the server's own limits declined to count it, so the
    //    browser does not send it again on every page.
    if ( isset( $_POST['campaign'] ) && is_string( $_POST['campaign'] ) && function_exists( 'brikpanel_record_campaign_visit' ) ) {
        brikpanel_record_campaign_visit( wp_unslash( $_POST['campaign'] ) );
        $done['campaign'] = true;
    }

    // Counted on open, not proved: no person mark, and the session's parked
    // add-to-carts and checkout visits keep waiting for a real input.
    if ( $opened ) {
        brikpanel_open_issue( $flags );
        wp_send_json_success( $done );
    }

    // The mark goes out with this reply, so it arrives even when the page
    // that asked is already gone.
    brikpanel_human_issue( $flags );

    // Add-to-carts and checkout visits this session made before it proved
    // itself are counted now.
    if ( function_exists( 'brikpanel_pending_flush' ) ) {
        brikpanel_pending_flush();
    }

    wp_send_json_success( $done );
}
add_action( 'wp_ajax_nopriv_brikpanel_unified_track', 'brikpanel_ajax_unified_track' );
add_action( 'wp_ajax_brikpanel_unified_track', 'brikpanel_ajax_unified_track' );

/**
 * The live part of a tracker request.
 *
 * @return string Status from brikpanel_record_live_visitor().
 */
function brikpanel_unified_track_live() {
    $page_url = isset( $_POST['page_url'] ) ? esc_url_raw( wp_unslash( $_POST['page_url'] ) ) : '';
    // The visit's entry source. Absent from pages cached with an older
    // tracker, and ignored by the recorder while the setting is off.
    $entry = [];
    foreach ( [ 'src_ref' => 'ref', 'src_url' => 'url' ] as $field => $key ) {
        if ( isset( $_POST[ $field ] ) && is_string( $_POST[ $field ] ) ) {
            $entry[ $key ] = esc_url_raw( substr( wp_unslash( $_POST[ $field ] ), 0, 2000 ) );
        }
    }
    // Seconds since the last interaction. A tracker from before the idle
    // limit never sends it: its page-load request (the only one carrying
    // page_id / visitor / product) still means someone just opened a
    // page, while its recurring pings say nothing and pass null.
    if ( isset( $_POST['idle'] ) ) {
        $idle = absint( wp_unslash( $_POST['idle'] ) );
    } elseif ( ! empty( $_POST['page_id'] ) || ! empty( $_POST['visitor'] ) || ! empty( $_POST['product'] ) ) {
        $idle = 0;
    } else {
        $idle = null;
    }
    // The page's post or term, named on the dashboard. Absent from pages
    // cached with an older tracker, which then show their address.
    $page_ref = '';
    if ( isset( $_POST['live_page'] ) && is_string( $_POST['live_page'] ) ) {
        $raw_ref = wp_unslash( $_POST['live_page'] );
        if ( preg_match( '/^(?:(?:post|term):[1-9][0-9]{0,18}|front)$/', $raw_ref ) ) {
            $page_ref = $raw_ref;
        }
    }
    return brikpanel_record_live_visitor( $page_url, false, $entry, $idle, $page_ref );
}

/**
 * A request from a page cached with a tracker older than 3.3.30.
 *
 * Counted the way that tracker expects (its own localStorage latches plus
 * the 3.3.11 server caps) during the transition window, so a store whose
 * cache keeps old pages for days does not lose its visitors overnight; after
 * the window nothing from it is recorded. Ends the request.
 *
 * @return void
 */
function brikpanel_unified_track_legacy() {
    if ( function_exists( 'brikpanel_legacy_tracker_allowed' ) && ! brikpanel_legacy_tracker_allowed() ) {
        wp_send_json_success( [ 'human' => 'need' ] );
    }

    $done = [];

    if ( ! empty( $_POST['live'] ) && function_exists( 'brikpanel_record_live_visitor' ) ) {
        $done['live'] = brikpanel_unified_track_live();
    }
    if ( ! empty( $_POST['page_id'] ) && function_exists( 'brikpanel_record_page_view' ) ) {
        $page_type = isset( $_POST['page_type'] ) ? sanitize_key( wp_unslash( $_POST['page_type'] ) ) : 'post';
        brikpanel_record_page_view( intval( $_POST['page_id'] ), $page_type );
        $done['pv'] = true;
    }
    if ( ! empty( $_POST['visitor'] ) && function_exists( 'brikpanel_record_visitor_view' ) ) {
        $referrer    = isset( $_POST['ref'] ) ? esc_url_raw( wp_unslash( $_POST['ref'] ) ) : '';
        $landing_url = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';
        brikpanel_record_visitor_view( $referrer, $landing_url );
        $done['visitor'] = true;
    }
    if ( ! empty( $_POST['product'] ) && function_exists( 'brikpanel_record_product_view' ) ) {
        brikpanel_record_product_view();
        $done['product'] = true;
    }
    if ( isset( $_POST['campaign'] ) && is_string( $_POST['campaign'] ) && function_exists( 'brikpanel_record_campaign_visit' ) ) {
        brikpanel_record_campaign_visit( wp_unslash( $_POST['campaign'] ) );
        $done['campaign'] = true;
    }
    // Counted as before during the window, so the session's parked
    // add-to-carts are too.
    if ( function_exists( 'brikpanel_pending_flush' ) ) {
        brikpanel_pending_flush();
    }

    wp_send_json_success( $done );
}

/**
 * The storefront visit counter (8 November 2026): the file
 * assets/js/brikpanel-pulse.js in the footer, plus this page's values in a
 * JSON block the browser never runs. Before 3.3.48 the whole script was
 * printed inside every page, 26 KB the browser could not keep between pages;
 * now the code comes from the browser cache from the second page on. The
 * file name avoids "track", which ad blockers drop by name.
 *
 * Printed for every visitor except store staff, bots included: page caches
 * hand this HTML to everyone, so a page first generated for a crawler would
 * otherwise reach real visitors without the tracker (3.3.30). A crawler
 * never moves a pointer, so the script never sends anything for it anyway.
 *
 * Deliberately gated on the master switch and NOT on
 * brikpanel_frontend_tracking_allowed(): this markup is baked into HTML that
 * page caches hand to every visitor, so it must never vary with one
 * visitor's consent state. When the merchant asks BrikPanel to wait for
 * consent, the script is still printed for everyone — armed, silent, and
 * carrying no visitor-specific value — and decides for itself, in the
 * browser, whether it may run.
 *
 * Person check: nothing is sent until the page is on screen (not a
 * prerender, not a background tab) and, unless the browser already carries
 * the "this is a person" mark, until a real input event (pointer moved,
 * pointer down, touch, wheel, key). A browser that reports itself as
 * automated (navigator.webdriver, HeadlessChrome, no languages) sends nothing.
 *
 * Count on open (3.3.41): with "Count visitors when the page opens" on, a page
 * on screen without the mark also sends one request at once (open=1). It
 * counts the day's visitor, the page view, the product view and the campaign,
 * but earns no mark, so the script keeps waiting for a real input. A touch
 * made while that request is on its way is sent when it answers, carrying
 * what it counted, so one visitor is never counted twice.
 *
 * Speed plugins (3.3.40): WP Rocket, LiteSpeed and others can hold every
 * script back until the visitor's first touch, and that touch is spent
 * waking them, so a visitor who touched once was never counted. The file tag
 * and the JSON block carry the markers those plugins honour to leave a script
 * alone (brikpanel_unified_tracker_tag()), and a script started late anyway
 * asks the browser whether the page was already touched
 * (navigator.userActivation) instead of waiting for a second touch. The
 * handle stays "brikpanel-tracker": WP Rocket's exclusion
 * (brikpanel_tracker_skip_rocket_delay()) and rules site owners keyed on that
 * name still match the tag id "brikpanel-tracker-js". A plugin that bundles
 * the file or moves it above the JSON block anyway does not break it: the
 * file finds the block by id and, if it ran first, looks again once the page
 * is parsed.
 *
 * Idle limit: the script remembers when someone last touched the page and
 * sends that age with every live ping. After BRIKPANEL_VISITOR_IDLE_TIMEOUT
 * without any, it stops pinging. The next interaction pings at once and
 * restarts the interval.
 */

/**
 * Whether this storefront request gets the visit counter.
 *
 * @return bool
 */
function brikpanel_unified_tracker_wanted() {
    if ( is_admin() || wp_doing_ajax() ) {
        return false;
    }
    if ( function_exists( 'brikpanel_frontend_tracking_enabled' ) && ! brikpanel_frontend_tracking_enabled() ) {
        return false;
    }
    if ( function_exists( 'brikpanel_is_admin_user' ) && brikpanel_is_admin_user() ) {
        return false;
    }
    return true;
}

/**
 * The page's values for the visit counter: site-level settings and the
 * page's own identity, nothing about the visitor, because page caches hand
 * the same HTML to everyone. Read by assets/js/brikpanel-pulse.js; a key it
 * does not find falls back to the old default, so a page cached with an
 * older shape keeps working with a newer file. Raise 'v' when a key changes
 * meaning.
 *
 * @return array
 */
function brikpanel_unified_tracker_config() {
    $is_product = function_exists( 'is_singular' ) && is_singular( 'product' );
    // Resolved from the queried object, not from get_the_ID(): in the footer
    // that returns whatever post the loop stopped on, which credited every
    // archive view to an arbitrary product listed on it.
    $view      = function_exists( 'brikpanel_current_view_target' )
        ? brikpanel_current_view_target()
        : [ 'id' => (int) get_the_ID(), 'type' => 'post' ];
    $page_id   = (int) $view['id'];
    $page_type = (string) $view['type'];
    return [
        'v'              => 1,
        'endpoint'       => admin_url( 'admin-ajax.php' ),
        'isProduct'      => (bool) $is_product,
        'pageId'         => $page_id,
        'pageType'       => $page_type,
        // What the Live list calls this page: its post or term, or the front
        // page when that is a list of latest posts with no post of its own.
        'livePage'       => $page_id > 0 ? $page_type . ':' . $page_id : ( is_front_page() ? 'front' : '' ),
        // The day this page was built. Only a fallback: a cached page can be
        // days old, so the store's real day comes from the person mark.
        'day'            => wp_date( 'Y-m-d' ),
        'humanCookie'    => function_exists( 'brikpanel_human_cookie_name' ) ? brikpanel_human_cookie_name() : 'brikpanel_human',
        // "Traffic source in Live view": site-level, so safe in cached HTML.
        'liveSource'     => ! function_exists( 'brikpanel_live_traffic_source_enabled' ) || brikpanel_live_traffic_source_enabled(),
        'requireConsent' => function_exists( 'brikpanel_consent_required' ) && brikpanel_consent_required(),
        'consentCat'     => function_exists( 'brikpanel_consent_category' ) ? brikpanel_consent_category() : 'statistics',
        'consentCookie'  => defined( 'BRIKPANEL_CONSENT_COOKIE' ) ? BRIKPANEL_CONSENT_COOKIE : 'brikpanel_consent',
        // "Count visitors when the page opens" (3.3.41): site-level as well.
        'countOnOpen'    => function_exists( 'brikpanel_count_on_open_enabled' ) && brikpanel_count_on_open_enabled(),
        'interval'       => ( function_exists( 'brikpanel_live_ping_interval' ) ? brikpanel_live_ping_interval() : 30 ) * 1000,
        'idle'           => ( function_exists( 'brikpanel_live_idle_timeout' ) ? brikpanel_live_idle_timeout() : 30 * MINUTE_IN_SECONDS ) * 1000,
    ];
}

/**
 * Puts the visit counter in the storefront footer.
 *
 * @return void
 */
function brikpanel_unified_tracker_enqueue() {
    if ( ! brikpanel_unified_tracker_wanted() ) {
        return;
    }
    wp_enqueue_script( 'brikpanel-tracker', BRIKPANEL_URL . 'assets/js/brikpanel-pulse.js', [], BRIKPANEL_VERSION, true );
}
add_action( 'wp_enqueue_scripts', 'brikpanel_unified_tracker_enqueue', 20 );

/**
 * Adds the page's values (a JSON block right before the file) and the
 * speed-plugin markers to the counter's tag. script_loader_tag is the one
 * filter every supported WordPress (6.0 and up) runs for this tag.
 *
 * @param string $tag    The script tag.
 * @param string $handle Its handle.
 * @return string
 */
function brikpanel_unified_tracker_tag( $tag, $handle ) {
    if ( 'brikpanel-tracker' !== $handle || false !== strpos( (string) $tag, 'brikpanel-tracker-cfg' ) ) {
        return $tag;
    }
    // JSON_HEX_* turn < > & ' " into \u escapes: the block cannot close its own tag.
    $json = wp_json_encode( brikpanel_unified_tracker_config(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES );
    if ( ! is_string( $json ) ) {
        return $tag;
    }
    // data-no-defer and data-no-optimize keep LiteSpeed Cache from holding
    // the file back, data-cfasync does the same for Cloudflare's Rocket Loader.
    $marks = ' data-no-defer="1" data-no-optimize="1" data-cfasync="false"';
    $tag   = preg_replace( '/<script(?=[\s>])/i', '<script' . $marks, (string) $tag, 1 );
    return '<script type="application/json" id="brikpanel-tracker-cfg"' . $marks . '>' . $json . "</script>\n" . $tag;
}
add_filter( 'script_loader_tag', 'brikpanel_unified_tracker_tag', 10, 2 );

/**
 * Keeps the tracker out of WP Rocket's "Delay JavaScript execution" (3.3.40).
 *
 * WP Rocket matches each entry against the whole script tag, so the
 * tracker's id is enough. The tracker only starts listening on load and
 * sends nothing before a person's input, so leaving it undelayed costs a
 * page almost nothing, while delaying it lost every visitor who touched
 * the page once.
 *
 * @param mixed $exclusions Patterns WP Rocket leaves undelayed.
 * @return array
 */
function brikpanel_tracker_skip_rocket_delay( $exclusions ) {
    $exclusions   = (array) $exclusions;
    $exclusions[] = 'brikpanel-tracker';
    $exclusions[] = 'brikpanel-pulse';
    return $exclusions;
}
add_filter( 'rocket_delay_js_exclusions', 'brikpanel_tracker_skip_rocket_delay' );
