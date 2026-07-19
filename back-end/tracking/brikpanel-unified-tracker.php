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

    $done = [];

    // 1) Live-visitor ping (rate limit + transient cap live inside).
    if ( ! empty( $_POST['live'] ) && function_exists( 'brikpanel_record_live_visitor' ) ) {
        $page_url       = isset( $_POST['page_url'] ) ? esc_url_raw( wp_unslash( $_POST['page_url'] ) ) : '';
        $done['live']   = brikpanel_record_live_visitor( $page_url, false );
    }

    // 2) Page-view counter (fires on every page view by design).
    if ( ! empty( $_POST['page_id'] ) && function_exists( 'brikpanel_record_page_view' ) ) {
        brikpanel_record_page_view( intval( $_POST['page_id'] ) );
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
    if ( function_exists( '_brikpanel_is_bot_ua' ) && _brikpanel_is_bot_ua() ) {
        return;
    }

    $day              = wp_date( 'Y-m-d' );
    $visitor_key      = 'brikpanel_visitor_viewed_' . $day;
    $product_key      = 'brikpanel_product_viewed_' . $day;
    $is_product       = function_exists( 'is_singular' ) && is_singular( 'product' );
    $page_id          = (int) get_the_ID();
    $ping_interval_ms = ( function_exists( 'brikpanel_live_ping_interval' ) ? brikpanel_live_ping_interval() : 30 ) * 1000;
    ?>
    <script>
    (function() {
        var endpoint   = "<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>";
        var VISITOR_KEY = "<?php echo esc_js( $visitor_key ); ?>";
        var PRODUCT_KEY = "<?php echo esc_js( $product_key ); ?>";
        var isProduct   = <?php echo $is_product ? 'true' : 'false'; ?>;
        var pageId      = <?php echo (int) $page_id; ?>;

        function buildLive(fd) {
            fd.append('live', '1');
            fd.append('page_url', window.location.href);
        }

        // One combined request per page view: live ping + page view + the
        // once-per-day visitor / product signals when not yet latched.
        function sendCombined() {
            var fd = new FormData();
            fd.append('action', 'brikpanel_unified_track');
            buildLive(fd);
            if (pageId) fd.append('page_id', pageId);
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

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', sendCombined);
        } else {
            sendCombined();
        }
        setInterval(pingLive, <?php echo (int) $ping_interval_ms; ?>);
    })();
    </script>
    <?php
}
add_action( 'wp_footer', 'brikpanel_unified_tracker_js', 20 );
