<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Unified frontend tracker (3.2.20).
 *
 * Before this file existed every storefront page view fired up to four
 * separate admin-ajax requests (page view, live-visitor ping, daily visitor
 * count, product view) plus an exit beacon on every navigation — up to 3-4
 * full WordPress boots per page view. On large stores that multiplies into
 * real server load (the wp.org report that prompted this change showed each
 * call taking 0.5-3.4 s under traffic spikes).
 *
 * Now a single combined request per page view carries all of those signals,
 * the recurring live ping reuses the same endpoint with only the live part,
 * and the per-navigation exit beacon is gone entirely (live visitors expire
 * via BRIKPANEL_VISITOR_TIMEOUT instead, at most ~75 s later). Net effect:
 * ~3 requests per navigation drop to 1.
 *
 * The old standalone AJAX actions stay registered in their original files so
 * page caches still serving pre-3.2.20 inline JS keep working until they
 * expire. Merchants can also disable all tracking (WooCommerce ▸ Settings ▸
 * BrikPanel ▸ Analytics) or stretch the live ping interval up to 300 s.
 */

/**
 * Combined AJAX endpoint: records every tracking signal of a page view in
 * one request.
 *
 * POST flags (all optional, each part runs only when its flag is present):
 *   live=1, page_url        — live-visitor ping (rate-limited server-side).
 *   page_id                 — page-view counter for "Most visited pages".
 *   visitor=1, ref, url     — daily visitor + device + traffic-source count.
 *   product=1               — daily product-view counter.
 *   consent=1               — the visitor has allowed analytics (only sent
 *                             while the "Wait for cookie consent" setting is
 *                             on, and only after the site's own consent code
 *                             said yes).
 *
 * No nonce: this is a public endpoint reachable from any frontend visitor,
 * and nonces printed into cached storefront HTML go stale anyway. Abuse is
 * bounded the same way the previous endpoints were — bot UA filter, admin
 * skip, per-visitor rate limit on the live part, hard caps on stored data —
 * and every write is an anonymous counter increment.
 */
function brikpanel_ajax_unified_track() {
    // Master tracking switch — also refuses pings from cached pages that
    // still carry the tracker after the merchant turned tracking off.
    if ( function_exists( 'brikpanel_frontend_tracking_enabled' ) && ! brikpanel_frontend_tracking_enabled() ) {
        wp_send_json_success( [ 'disabled' => true ] );
    }
    if ( function_exists( 'brikpanel_is_admin_user' ) && brikpanel_is_admin_user() ) {
        wp_send_json_success( [ 'skipped' => true ] );
    }
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

    // Consent gate. Everything below this line can create the brikpanel_vid
    // cookie (via brikpanel_record_live_visitor), so nothing may run until
    // the visitor has allowed analytics.
    //
    // The answer comes from server-side state, never from "the request did
    // not carry a consent flag". A page cached BEFORE the merchant enabled
    // the setting still ships the old unconditional script, which sends no
    // flag — indistinguishable from a visitor who declined, and both must be
    // refused. Because the reply then carries no `visitor` / `product` key,
    // that old script also leaves its localStorage latches unset, so the
    // visitor is still counted properly once they do consent.
    if ( function_exists( 'brikpanel_frontend_tracking_allowed' )
        && ! brikpanel_frontend_tracking_allowed( 'endpoint' ) ) {
        wp_send_json_success( [ 'consent_required' => true ] );
    }

    $done = [];

    // 1) Live-visitor ping (rate limit + transient cap live inside).
    if ( ! empty( $_POST['live'] ) && function_exists( 'brikpanel_record_live_visitor' ) ) {
        $page_url       = isset( $_POST['page_url'] ) ? esc_url_raw( wp_unslash( $_POST['page_url'] ) ) : '';
        $done['live']   = brikpanel_record_live_visitor( $page_url, false );
    }

    // 2) Page-view counter (fires on every page view by design).
    if ( ! empty( $_POST['page_id'] ) && function_exists( 'brikpanel_record_page_view' ) ) {
        $page_type = isset( $_POST['page_type'] ) ? sanitize_key( wp_unslash( $_POST['page_type'] ) ) : 'post';
        brikpanel_record_page_view( intval( $_POST['page_id'] ), $page_type );
        $done['pv'] = true;
    }

    // 3) Daily visitor + traffic source (client sends it once per day via
    //    localStorage; the flag in the response is what arms that latch).
    if ( ! empty( $_POST['visitor'] ) && function_exists( 'brikpanel_record_visitor_view' ) ) {
        $referrer    = isset( $_POST['ref'] ) ? esc_url_raw( wp_unslash( $_POST['ref'] ) ) : '';
        $landing_url = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';
        brikpanel_record_visitor_view( $referrer, $landing_url );
        $done['visitor'] = true;
    }

    // 4) Daily product-view counter (same once-per-day latch as above).
    if ( ! empty( $_POST['product'] ) && function_exists( 'brikpanel_record_product_view' ) ) {
        brikpanel_record_product_view();
        $done['product'] = true;
    }

    wp_send_json_success( $done );
}
add_action( 'wp_ajax_nopriv_brikpanel_unified_track', 'brikpanel_ajax_unified_track' );
add_action( 'wp_ajax_brikpanel_unified_track', 'brikpanel_ajax_unified_track' );

/**
 * Prints the single combined tracker script in the storefront footer.
 *
 * Replaces the four separate inline scripts (live ping, page view, visitor
 * view, product view) that each fired their own admin-ajax request.
 *
 * Deliberately gated on the master switch and NOT on
 * brikpanel_frontend_tracking_allowed(): this markup is baked into HTML that
 * page caches hand to every visitor, so it must never vary with one
 * visitor's consent state. When the merchant asks BrikPanel to wait for
 * consent, the script is still printed for everyone — armed, silent, and
 * carrying no visitor-specific value — and decides for itself, in the
 * browser, whether it may run. The two values it needs for that are
 * site-level settings, identical for every visitor of the page.
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
    // Skip the script entirely for bots — saves the network round-trip.
    //
    // Deliberately asks the variant that does NOT treat a prefetch as a bot.
    // The endpoint above refuses to record a speculative hit, which is the
    // part that was inflating the counters; the script itself must still be
    // printed, because the browser serves this very HTML when the visitor
    // clicks the prefetched link and a page cache may hand it to everyone
    // else. Omitting it here would silently switch tracking off.
    if ( function_exists( 'brikpanel_is_bot_request' ) && brikpanel_is_bot_request( false ) ) {
        return;
    }

    $day              = wp_date( 'Y-m-d' );
    $visitor_key      = 'brikpanel_visitor_viewed_' . $day;
    $product_key      = 'brikpanel_product_viewed_' . $day;
    $is_product       = function_exists( 'is_singular' ) && is_singular( 'product' );
    // Resolved from the queried object, not from get_the_ID(): in the footer
    // that returns whatever post the loop stopped on, which credited every
    // archive view to an arbitrary product listed on it.
    $view             = function_exists( 'brikpanel_current_view_target' )
        ? brikpanel_current_view_target()
        : [ 'id' => (int) get_the_ID(), 'type' => 'post' ];
    $page_id          = (int) $view['id'];
    $page_type        = (string) $view['type'];
    $ping_interval_ms = ( function_exists( 'brikpanel_live_ping_interval' ) ? brikpanel_live_ping_interval() : 30 ) * 1000;
    // Site-level settings, not visitor state — safe to bake into cached HTML.
    $require_consent  = function_exists( 'brikpanel_consent_required' ) && brikpanel_consent_required();
    $consent_cat      = function_exists( 'brikpanel_consent_category' ) ? brikpanel_consent_category() : 'statistics';
    $consent_cookie   = defined( 'BRIKPANEL_CONSENT_COOKIE' ) ? BRIKPANEL_CONSENT_COOKIE : 'brikpanel_consent';
    ?>
    <script>
    (function() {
        var endpoint   = "<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>";
        var VISITOR_KEY = "<?php echo esc_js( $visitor_key ); ?>";
        var PRODUCT_KEY = "<?php echo esc_js( $product_key ); ?>";
        var isProduct   = <?php echo $is_product ? 'true' : 'false'; ?>;
        var pageId      = <?php echo (int) $page_id; ?>;
        var pageType    = "<?php echo esc_js( $page_type ); ?>";

        // Consent gate. When false this whole block behaves exactly as it did
        // before 3.2.48; when true nothing is sent, read or written until the
        // visitor allows analytics.
        var REQUIRE_CONSENT = <?php echo $require_consent ? 'true' : 'false'; ?>;
        var CONSENT_CAT     = "<?php echo esc_js( $consent_cat ); ?>";
        var CONSENT_COOKIE  = "<?php echo esc_js( $consent_cookie ); ?>";

        var running   = false;
        var timer     = null;
        var forgotten = false;

        function buildLive(fd) {
            fd.append('live', '1');
            fd.append('page_url', window.location.href);
            if (REQUIRE_CONSENT) fd.append('consent', '1');
        }

        function readCookie(name) {
            var parts = document.cookie ? document.cookie.split(';') : [];
            for (var i = 0; i < parts.length; i++) {
                var p = parts[i].trim();
                if (p.indexOf(name + '=') === 0) return p.slice(name.length + 1);
            }
            return '';
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

        // One combined request per page view: live ping + page view + the
        // once-per-day visitor / product signals when not yet latched.
        function sendCombined() {
            var fd = new FormData();
            fd.append('action', 'brikpanel_unified_track');
            buildLive(fd);
            if (pageId) {
                fd.append('page_id', pageId);
                fd.append('page_type', pageType);
            }
            var wantVisitor = false, wantProduct = false;
            try {
                wantVisitor = !localStorage.getItem(VISITOR_KEY);
                wantProduct = isProduct && !localStorage.getItem(PRODUCT_KEY);
            } catch (e) {}
            if (wantVisitor) {
                fd.append('visitor', '1');
                try {
                    fd.append('ref', document.referrer || '');
                    fd.append('url', window.location.href || '');
                } catch (e) {}
            }
            if (wantProduct) fd.append('product', '1');

            fetch(endpoint, {
                method: 'POST',
                credentials: 'same-origin',
                body: fd,
                keepalive: true
            }).then(function(res) {
                return res.ok ? res.json() : null;
            }).then(function(json) {
                if (!json || !json.success || !json.data) return;
                try {
                    if (json.data.visitor) localStorage.setItem(VISITOR_KEY, '1');
                    if (json.data.product) localStorage.setItem(PRODUCT_KEY, '1');
                } catch (e) {}
            }).catch(function() {});
        }

        // Recurring live ping for tabs that stay open — live part only.
        // No exit beacon: the Live widget expires idle visitors on its own.
        function pingLive() {
            if (document.visibilityState === 'hidden') return;
            var fd = new FormData();
            fd.append('action', 'brikpanel_unified_track');
            buildLive(fd);
            fetch(endpoint, {
                method: 'POST',
                credentials: 'same-origin',
                body: fd,
                keepalive: true
            }).catch(function() {});
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
        // never created without one of the two signals below also being
        // created, so checking these is equivalent in practice.
        function hasFootprint() {
            if (readCookie(CONSENT_COOKIE) === '1') return true;
            try {
                for (var i = 0; i < localStorage.length; i++) {
                    var k = localStorage.key(i);
                    if (k && /^brikpanel_(visitor|product)_viewed_/.test(k)) return true;
                }
            } catch (e) {}
            return false;
        }

        // Drop every latch this browser owns, not just today's: after a
        // withdrawal nothing BrikPanel wrote may survive.
        function clearLatches() {
            try {
                var keys = [];
                for (var i = 0; i < localStorage.length; i++) {
                    var k = localStorage.key(i);
                    if (k && /^brikpanel_(visitor|product)_viewed_/.test(k)) keys.push(k);
                }
                for (var j = 0; j < keys.length; j++) localStorage.removeItem(keys[j]);
            } catch (e) {}
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

        // Idempotent: consent platforms routinely fire their change event more
        // than once, and the jQuery fallback below can double up with the
        // native listener.
        function start() {
            if (running) return;
            running   = true;
            forgotten = false;
            sendCombined();
            timer = setInterval(pingLive, <?php echo (int) $ping_interval_ms; ?>);
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
