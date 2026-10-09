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
 * reply), 'need' (nothing recorded; ask again after a real input event).
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
    // event and pass the server's own checks.
    $state = brikpanel_human_state();
    if ( ! $state['valid'] && ! is_user_logged_in() ) {
        if ( empty( $_POST['human'] ) ) {
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
    $flags = ( $state['valid'] && $state['today'] ) ? $state['flags'] : '';
    $done  = [ 'human' => 'ok', 'day' => $today ];

    // 1) Live-visitor ping (rate limit + transient cap live inside).
    if ( ! empty( $_POST['live'] ) && function_exists( 'brikpanel_record_live_visitor' ) ) {
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
 * Prints the single combined tracker script in the storefront footer.
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
 * Idle limit: the script remembers when someone last touched the page and
 * sends that age with every live ping. After BRIKPANEL_VISITOR_IDLE_TIMEOUT
 * without any, it stops pinging. The next interaction pings at once and
 * restarts the interval.
 */
function brikpanel_unified_tracker_js() {
    if ( is_admin() || wp_doing_ajax() ) {
        return;
    }
    if ( function_exists( 'brikpanel_frontend_tracking_enabled' ) && ! brikpanel_frontend_tracking_enabled() ) {
        return;
    }
    if ( function_exists( 'brikpanel_is_admin_user' ) && brikpanel_is_admin_user() ) {
        return;
    }

    $day              = wp_date( 'Y-m-d' );
    $is_product       = function_exists( 'is_singular' ) && is_singular( 'product' );
    // Resolved from the queried object, not from get_the_ID(): in the footer
    // that returns whatever post the loop stopped on, which credited every
    // archive view to an arbitrary product listed on it.
    $view             = function_exists( 'brikpanel_current_view_target' )
        ? brikpanel_current_view_target()
        : [ 'id' => (int) get_the_ID(), 'type' => 'post' ];
    $page_id          = (int) $view['id'];
    $page_type        = (string) $view['type'];
    // What the Live list calls this page: its post or term, or the front page
    // when that is a list of latest posts with no post of its own.
    $live_page        = $page_id > 0 ? $page_type . ':' . $page_id : ( is_front_page() ? 'front' : '' );
    $ping_interval_ms = ( function_exists( 'brikpanel_live_ping_interval' ) ? brikpanel_live_ping_interval() : 30 ) * 1000;
    $idle_ms          = ( function_exists( 'brikpanel_live_idle_timeout' ) ? brikpanel_live_idle_timeout() : 30 * MINUTE_IN_SECONDS ) * 1000;
    // Site-level settings, not visitor state — safe to bake into cached HTML.
    $require_consent  = function_exists( 'brikpanel_consent_required' ) && brikpanel_consent_required();
    $consent_cat      = function_exists( 'brikpanel_consent_category' ) ? brikpanel_consent_category() : 'statistics';
    $consent_cookie   = defined( 'BRIKPANEL_CONSENT_COOKIE' ) ? BRIKPANEL_CONSENT_COOKIE : 'brikpanel_consent';
    $human_cookie     = function_exists( 'brikpanel_human_cookie_name' ) ? brikpanel_human_cookie_name() : 'brikpanel_human';
    // "Traffic source in Live view": site-level, so safe in cached HTML too.
    $live_source      = ! function_exists( 'brikpanel_live_traffic_source_enabled' ) || brikpanel_live_traffic_source_enabled();
    ?>
    <script>
    (function() {
        var endpoint   = "<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>";
        var isProduct  = <?php echo $is_product ? 'true' : 'false'; ?>;
        var pageId     = <?php echo (int) $page_id; ?>;
        var pageType   = "<?php echo esc_js( $page_type ); ?>";
        var LIVE_PAGE  = "<?php echo esc_js( $live_page ); ?>";

        // The day this page was built. Only a fallback: a cached page can be
        // days old, so the store's real day comes from the person mark.
        var PAGE_DAY     = "<?php echo esc_js( $day ); ?>";
        var CAMPAIGN_KEY = 'brikpanel_campaign_viewed';
        var HUMAN_COOKIE = "<?php echo esc_js( $human_cookie ); ?>";

        // Where this visit came from, for the Live visitors list.
        var LIVE_SOURCE = <?php echo $live_source ? 'true' : 'false'; ?>;
        var ENTRY_KEY   = 'brikpanel_entry_src';
        var entry       = null;

        // Consent gate. When true nothing is sent, read or written until the
        // visitor allows analytics.
        var REQUIRE_CONSENT = <?php echo $require_consent ? 'true' : 'false'; ?>;
        var CONSENT_CAT     = "<?php echo esc_js( $consent_cat ); ?>";
        var CONSENT_COOKIE  = "<?php echo esc_js( $consent_cookie ); ?>";

        var running   = false;
        var timer     = null;
        var forgotten = false;
        var proven    = false;   // the server counted this page as a person's
        var asking    = false;   // waiting for a person's first input event
        var shownWait = false;   // waiting for the page to be on screen

        // Idle limit: see the PHP docblock of brikpanel_unified_tracker_js().
        var INTERVAL_MS = <?php echo (int) $ping_interval_ms; ?>;
        var IDLE_MS     = <?php echo (int) $idle_ms; ?>;
        var listening   = false;
        var lastActive  = Date.now();
        var lastX = null, lastY = null;

        function idleFor() {
            return Date.now() - lastActive;
        }

        function readCookie(name) {
            var parts = document.cookie ? document.cookie.split(';') : [];
            for (var i = 0; i < parts.length; i++) {
                var p = parts[i].trim();
                if (p.indexOf(name + '=') === 0) return p.slice(name.length + 1);
            }
            return '';
        }

        // The person mark, when this browser has one. Its shape only: the
        // server checks the signature.
        function humanMark() {
            var v = readCookie(HUMAN_COOKIE);
            return /^1\.\d{4}-\d{2}-\d{2}\.[vpac]{0,4}\.[0-9a-f]{20}$/.test(v) ? v : '';
        }

        // The store's day as the server last said it, else the page's.
        function storeDay() {
            var m = humanMark();
            return m ? m.split('.')[1] : PAGE_DAY;
        }

        // A browser driven by automation says so itself; a person's browser
        // never does.
        function automated() {
            try {
                if (navigator.webdriver === true) return true;
                if (/HeadlessChrome/.test(navigator.userAgent || '')) return true;
                var brands = navigator.userAgentData && navigator.userAgentData.brands;
                if (brands && brands.length) {
                    for (var i = 0; i < brands.length; i++) {
                        if (/Headless/i.test((brands[i] && brands[i].brand) || '')) return true;
                    }
                }
                if (navigator.languages && navigator.languages.length === 0) return true;
            } catch (e) {}
            return false;
        }

        function buildLive(fd) {
            fd.append('live', '1');
            fd.append('page_url', window.location.href);
            fd.append('idle', String(Math.max(0, Math.floor(idleFor() / 1000))));
            // Which page this is, so the Live list can show its name. Not
            // page_id: that one counts a page view, once per page load.
            if (LIVE_PAGE) fd.append('live_page', LIVE_PAGE);
            if (REQUIRE_CONSENT) fd.append('consent', '1');
            // Sent with every ping, not once: the live row is rewritten on each
            // ping and can expire while the tab sits in the background.
            if (entry) {
                fd.append('src_ref', entry.r || '');
                fd.append('src_url', entry.u || '');
            }
        }

        // The visit's entry: the referring page and the address it landed on
        // (which carries any campaign tags). Remembered for this tab, so every
        // later page can still say where the visitor came from. A new entry
        // starts when there is none yet, when the address carries a campaign
        // or ad-click tag, or when the visitor comes back from another site
        // after half an hour away. A mid-visit return from another site (a
        // payment page, say) keeps the original source. With nothing stored
        // and a referrer from this very site (consent given on a later page)
        // the source is unknown, so nothing is sent. Runs from start() only,
        // so nothing is stored before the visitor allows analytics.
        function captureEntry() {
            if (!LIVE_SOURCE) {
                try { sessionStorage.removeItem(ENTRY_KEY); } catch (e) {}
                return null;
            }
            var now = Date.now(), saved = null;
            try { saved = JSON.parse(sessionStorage.getItem(ENTRY_KEY) || 'null'); } catch (e) {}
            if (!saved || typeof saved !== 'object') saved = null;
            var ref = '', refHost = '', here = '';
            try {
                ref = document.referrer || '';
                here = window.location.hostname.replace(/^www\./, '');
                if (ref) refHost = new URL(ref).hostname.replace(/^www\./, '');
            } catch (e) {}
            var external = !!refHost && refHost !== here && refHost.slice(-(here.length + 1)) !== '.' + here;
            var tagged = /[?&](utm_[a-z]+|gclid|gclsrc|gbraid|wbraid|msclkid|fbclid|ttclid)=/i.test(window.location.search || '');
            var idle = !saved || !saved.t || (now - saved.t) > 1800000;
            if (!saved || tagged || (external && idle)) {
                if (!saved && !tagged && ref && !external) return null;
                saved = {
                    r: external ? String(ref).slice(0, 1000) : '',
                    u: String(window.location.href).slice(0, 2000)
                };
            }
            saved.t = now;
            try { sessionStorage.setItem(ENTRY_KEY, JSON.stringify(saved)); } catch (e) {}
            return saved;
        }

        // Whether a consent platform is actually driving the Consent API.
        // With the API installed but no banner configured this is empty, and
        // wp_has_consent() then answers "allow" for everything — its
        // documented "nobody is asking, so nothing is denied" stance. Mirrors
        // the same test on the PHP side so the two can never disagree.
        function consentTypeDefined() {
            var type = '';
            try {
                if (typeof consent_api !== 'undefined' && consent_api && consent_api.consent_type) {
                    type = consent_api.consent_type;
                }
            } catch (e) {}
            return !!(type || window.wp_consent_type || window.wp_fallback_consent_type);
        }

        // The Consent API's own decision cookie for our category, or '' when
        // the banner has not written one yet. Several popular banners set it
        // from JavaScript without ever declaring a consent type, so it is the
        // only durable evidence they leave behind.
        function consentApiCookie() {
            var prefix = 'wp_consent';
            try {
                if (typeof consent_api !== 'undefined' && consent_api && consent_api.cookie_prefix) {
                    prefix = consent_api.cookie_prefix;
                }
            } catch (e) {}
            return readCookie(prefix + '_' + CONSENT_CAT);
        }

        // Same order as brikpanel_consent_granted() in PHP: our own record,
        // then the banner's decision cookie, then the API itself but only
        // while a real banner is driving it.
        function consentGranted() {
            if (readCookie(CONSENT_COOKIE) === '1') return true;
            if (typeof wp_has_consent !== 'function') return false;
            var decided = consentApiCookie();
            if (decided) return decided === 'allow';
            if (consentTypeDefined()) {
                try { return !!wp_has_consent(CONSENT_CAT); } catch (e) {}
            }
            return false;
        }

        // The campaign of this address, read the way WooCommerce's own order
        // attribution reads it, so a visit and the order it leads to carry the
        // same name: utm_campaign, else google_cpc for a Google Ads click id and
        // yandex_cpc for a Yandex one; decoded once, a "+" kept as it is.
        function landingCampaign() {
            var q = window.location.search || '';
            if (q.length < 2) return '';
            var found = Object.create(null);
            var pairs = q.slice(1).split('&');
            for (var i = 0; i < pairs.length; i++) {
                var eq = pairs[i].indexOf('=');
                var key = eq === -1 ? pairs[i] : pairs[i].slice(0, eq);
                if (key && !(key in found)) found[key] = eq === -1 ? '' : pairs[i].slice(eq + 1);
            }
            var raw = found.utm_campaign || '';
            if (!raw && found.gclid) raw = 'google_cpc';
            if (!raw && found.yclid) raw = 'yandex_cpc';
            try { raw = decodeURIComponent(raw); } catch (e) {}
            raw = String(raw).replace(/\s+/g, ' ').trim();
            return raw === '(none)' ? '' : raw.slice(0, 100);
        }

        // Each campaign counts once a day per browser. One key holds the
        // store day's list (at most 20 names), so yesterday's names go when
        // the day turns instead of piling up a key each.
        function campaignDue(name) {
            if (!name) return false;
            try {
                var seen = JSON.parse(localStorage.getItem(CAMPAIGN_KEY) || 'null');
                if (!seen || seen.d !== storeDay() || !Array.isArray(seen.c)) return true;
                return seen.c.indexOf(name.toLowerCase()) === -1;
            } catch (e) {
                return true;
            }
        }

        function campaignCounted(name, day) {
            try {
                var seen = JSON.parse(localStorage.getItem(CAMPAIGN_KEY) || 'null');
                var list = seen && seen.d === day && Array.isArray(seen.c) ? seen.c : [];
                list.push(name.toLowerCase());
                localStorage.setItem(CAMPAIGN_KEY, JSON.stringify({ d: day, c: list.slice(-20) }));
            } catch (e) {}
        }

        // Latches of trackers before 3.3.30 (one key per day, never cleaned).
        function dropOldLatches() {
            try {
                var keys = [];
                for (var i = 0; i < localStorage.length; i++) {
                    var k = localStorage.key(i);
                    if (k && /^brikpanel_(?:visitor_viewed_|product_viewed_)/.test(k)) keys.push(k);
                }
                for (var j = 0; j < keys.length; j++) localStorage.removeItem(keys[j]);
            } catch (e) {}
        }

        function startPings() {
            if (timer || !running) return;
            timer = setInterval(pingLive, INTERVAL_MS);
        }

        // One combined request per page view: live ping, page view, visitor,
        // product view and campaign. `human`: sent because a person just
        // touched the page (asks the server for the mark).
        function sendCombined(human) {
            if (!running) return;
            var fd = new FormData();
            fd.append('action', 'brikpanel_unified_track');
            fd.append('tv', '2');
            if (human) fd.append('human', '1');
            buildLive(fd);
            if (pageId) {
                fd.append('page_id', pageId);
                fd.append('page_type', pageType);
            }
            // Always asked; the server counts each once per store day.
            fd.append('visitor', '1');
            try {
                fd.append('ref', document.referrer || '');
                fd.append('url', window.location.href || '');
            } catch (e) {}
            if (isProduct) fd.append('product', '1');
            var campaign = landingCampaign();
            var wantCampaign = campaignDue(campaign);
            if (wantCampaign) fd.append('campaign', campaign);

            fetch(endpoint, {
                method: 'POST',
                credentials: 'same-origin',
                body: fd,
                keepalive: true
            }).then(function(res) {
                return res.ok ? res.json() : null;
            }).then(function(json) {
                if (!json || !json.success || !json.data) return;
                var d = json.data;
                if (d.human === 'need') {
                    proven = false;
                    askPerson();
                    return;
                }
                if (d.human !== 'ok') return;
                proven = true;
                startPings();
                if (wantCampaign && d.campaign) campaignCounted(campaign, d.day || storeDay());
            }).catch(function() {});
        }

        // Recurring live ping for tabs that stay open — live part only, and
        // only for a page already counted as a person's.
        function pingLive() {
            if (!proven) return;
            if (document.visibilityState === 'hidden') return;
            if (idleFor() >= IDLE_MS) return;
            var fd = new FormData();
            fd.append('action', 'brikpanel_unified_track');
            fd.append('tv', '2');
            buildLive(fd);
            fetch(endpoint, {
                method: 'POST',
                credentials: 'same-origin',
                body: fd,
                keepalive: true
            }).catch(function() {});
        }

        // An add-to-cart made before this page was counted as a person's (the
        // add button was the very first touch) waits in the shopper's session
        // on the server. Any counted request releases it, so after an add the
        // next one is sent shortly instead of at the next 30-second ping.
        var flushTries = 0;
        function flushSoon() {
            if (!running) return;
            if (!proven) {
                if (++flushTries <= 6) setTimeout(flushSoon, 1500);
                return;
            }
            flushTries = 0;
            pingLive();
        }
        function onCartAdd() {
            flushTries = 0;
            setTimeout(flushSoon, 1200);
        }
        function listenCart() {
            // Classic and theme AJAX buttons fire WooCommerce's jQuery event;
            // the block cart fires its own DOM event.
            if (window.jQuery) {
                window.jQuery(document.body).on('added_to_cart', onCartAdd);
            }
            document.body.addEventListener('wc-blocks_added_to_cart', onCartAdd);
        }

        // Wait for the first real input of a person, then send once.
        // Scripted events (isTrusted false) and a pointer that did not move
        // do not count. Scroll is left out on purpose: a page can scroll
        // itself, while a person scrolls with a wheel, a touch or a key.
        var PERSON_EVENTS = ['pointerdown', 'pointermove', 'touchstart', 'wheel', 'keydown'];
        var px = null, py = null;
        function onPerson(e) {
            if (!asking || !e || e.isTrusted === false) return;
            if (e.type === 'pointermove') {
                if (px === null) { px = e.screenX; py = e.screenY; return; }
                if (e.screenX === px && e.screenY === py) return;
            }
            asking = false;
            for (var i = 0; i < PERSON_EVENTS.length; i++) {
                document.removeEventListener(PERSON_EVENTS[i], onPerson, true);
            }
            sendCombined(true);
        }
        function askPerson() {
            if (asking || !running) return;
            asking = true;
            px = null; py = null;
            for (var i = 0; i < PERSON_EVENTS.length; i++) {
                document.addEventListener(PERSON_EVENTS[i], onPerson, { capture: true, passive: true });
            }
        }

        // Nothing is sent for a page nobody is looking at: a prerender the
        // visitor has not opened, or a tab still in the background.
        function onShown() {
            if (!shownWait || !running) return;
            if (document.prerendering) return;
            if (document.visibilityState === 'hidden') return;
            shownWait = false;
            document.removeEventListener('visibilitychange', onShown);
            document.removeEventListener('prerenderingchange', onShown);
            if (humanMark()) {
                sendCombined(false);
            } else {
                askPerson();
            }
        }
        function whenShown() {
            shownWait = true;
            document.addEventListener('visibilitychange', onShown);
            document.addEventListener('prerenderingchange', onShown);
            onShown();
        }

        // Whether this browser carries anything of ours worth erasing.
        //
        // Several banners (CookieYes among them) announce "deny" for every
        // category on the very first page load, before the visitor has
        // touched anything. Treating that as a withdrawal would fire an
        // erase request on every page view of every non-consenting visitor —
        // the exact "no requests before consent" promise this feature makes.
        // A deny with nothing to erase is a no-op.
        //
        // brikpanel_vid is HttpOnly and therefore invisible here, but it is
        // never created without one of the signals below also being created,
        // so checking these is equivalent in practice.
        function hasFootprint() {
            if (readCookie(CONSENT_COOKIE) === '1') return true;
            if (readCookie(HUMAN_COOKIE)) return true;
            try {
                for (var i = 0; i < localStorage.length; i++) {
                    var k = localStorage.key(i);
                    if (k && /^brikpanel_(?:visitor_viewed_|product_viewed_|campaign_viewed$)/.test(k)) return true;
                }
            } catch (e) {}
            try { if (sessionStorage.getItem(ENTRY_KEY)) return true; } catch (e) {}
            return false;
        }

        // Drop every latch this browser owns: after a withdrawal nothing
        // BrikPanel wrote may survive. (The cookies go with the erase request.)
        function clearLatches() {
            try {
                var keys = [];
                for (var i = 0; i < localStorage.length; i++) {
                    var k = localStorage.key(i);
                    if (k && /^brikpanel_(?:visitor_viewed_|product_viewed_|campaign_viewed$)/.test(k)) keys.push(k);
                }
                for (var j = 0; j < keys.length; j++) localStorage.removeItem(keys[j]);
            } catch (e) {}
            entry = null;
            try { sessionStorage.removeItem(ENTRY_KEY); } catch (e) {}
        }

        // Ask the server to expire our cookies and drop this browser from the
        // live-visitor list. It only ever touches identifiers the request
        // itself carries — the daily totals are anonymous and stay put.
        function sendForget() {
            var fd = new FormData();
            fd.append('action', 'brikpanel_forget');
            fetch(endpoint, {
                method: 'POST',
                credentials: 'same-origin',
                body: fd,
                keepalive: true
            }).catch(function() {});
        }

        // Someone touched the page: back from idle, ping now and restart the interval.
        function markActive(e) {
            if (e && e.isTrusted === false) return;
            if (e && e.type === 'pointermove') {
                if (e.screenX === lastX && e.screenY === lastY) return;
                lastX = e.screenX; lastY = e.screenY;
            }
            var wasIdle = idleFor() >= IDLE_MS;
            lastActive = Date.now();
            if (wasIdle && running && proven && document.visibilityState !== 'hidden') {
                pingLive();
                if (timer) clearInterval(timer);
                timer = setInterval(pingLive, INTERVAL_MS);
            }
        }

        function markShown(e) {
            if (document.visibilityState === 'hidden') return;
            if (e && e.type === 'pageshow' && !e.persisted) return;
            markActive(e);
        }

        function listen() {
            if (listening) return;
            listening = true;
            listenCart();
            var types = ['pointerdown', 'pointermove', 'keydown', 'wheel', 'touchstart', 'click'];
            for (var i = 0; i < types.length; i++) {
                document.addEventListener(types[i], markActive, { capture: true, passive: true });
            }
            // Page scroll only: a box that scrolls itself must not count.
            window.addEventListener('scroll', markActive, { passive: true });
            document.addEventListener('visibilitychange', markShown);
            document.addEventListener('prerenderingchange', markShown);
            window.addEventListener('pageshow', markShown);
        }

        // Idempotent: consent platforms routinely fire their change event more
        // than once, and the jQuery fallback below can double up with the
        // native listener.
        function start() {
            if (running) return;
            // Puppeteer, Playwright, Selenium and headless browsers: nothing is
            // sent, so a scripted crawl cannot become a visitor, a live entry
            // or a product view.
            if (automated()) return;
            running    = true;
            forgotten  = false;
            lastActive = Date.now();
            listen();
            dropOldLatches();
            entry      = captureEntry();
            whenShown();
        }

        // Clearing the interval is the point: without it a withdrawal would
        // keep pinging for as long as the tab stays open. The `forgotten`
        // latch matters just as much — binding both the native and the jQuery
        // consent event means a single "deny" can arrive twice, and erasing
        // twice would fire a second pointless request every time.
        function stop(forget) {
            if (timer) { clearInterval(timer); timer = null; }
            var wasRunning = running;
            running = false;
            proven  = false;
            asking  = false;
            if (!forget || forgotten) return;
            // Nothing was ever started and nothing of ours is stored: this is
            // a banner announcing its default state, not a withdrawal.
            if (!wasRunning && !hasFootprint()) return;
            forgotten = true;
            clearLatches();
            sendForget();
        }

        /**
         * Public API for cookie-consent platforms.
         *
         * Call brikpanel_start_tracking() once the visitor allows analytics
         * and brikpanel_stop_tracking() when they withdraw. Both are safe to
         * call repeatedly and neither needs a page reload.
         */
        window.brikpanel_start_tracking = function() { start(); };
        window.brikpanel_stop_tracking  = function() { stop(true); };

        function onReady(fn) {
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', fn);
            } else {
                fn();
            }
        }

        // The WP Consent API payload is built as an array with a string
        // property, so hasOwnProperty is the only safe way to test it — a
        // bare lookup can hit Array.prototype members.
        function onConsentChange(e, extra) {
            var detail = (e && e.detail) || extra;
            if (!detail || !Object.prototype.hasOwnProperty.call(detail, CONSENT_CAT)) return;
            if (detail[CONSENT_CAT] === 'allow') { start(); } else { stop(true); }
        }

        if (!REQUIRE_CONSENT) {
            onReady(start);
        } else {
            // Already-consented revisit: start without waiting for an event.
            onReady(function() { if (consentGranted()) start(); });

            // Fired by the CMP once it knows which regime applies (geo-ip
            // lookups resolve after page load).
            document.addEventListener('wp_consent_type_defined', function() {
                if (consentGranted()) { start(); } else { stop(false); }
            });

            // Current WP Consent API dispatches a native CustomEvent. Older
            // and forked builds trigger it through jQuery, which never reaches
            // addEventListener, so bind both — start()/stop() are idempotent.
            document.addEventListener('wp_listen_for_consent_change', onConsentChange);
            if (window.jQuery) {
                window.jQuery(document).on('wp_listen_for_consent_change', onConsentChange);
            }
        }
    })();
    </script>
    <?php
}
add_action( 'wp_footer', 'brikpanel_unified_tracker_js', 20 );
