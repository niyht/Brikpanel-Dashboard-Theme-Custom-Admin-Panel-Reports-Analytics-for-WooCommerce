<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Detect device type from a User-Agent string.
 *
 * @param string|null $ua Optional UA string. Falls back to $_SERVER['HTTP_USER_AGENT']
 *                        for live visitor tracking when omitted/null.
 * @return string 'mobile' | 'tablet' | 'desktop'
 */
function brikpanel_detect_device_type( $ua = null ) {
    if ( $ua === null ) {
        $ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';
    }
    $ua = (string) $ua;

    if ( $ua === '' ) {
        return 'desktop';
    }
    if ( preg_match( '/iPad|Android(?!.*Mobile)|Tablet|Kindle|PlayBook/i', $ua ) ) {
        return 'tablet';
    }
    if ( preg_match( '/Mobile|Android|iPhone|iPod|BlackBerry|IEMobile|Opera Mini|Windows Phone/i', $ua ) ) {
        return 'mobile';
    }
    return 'desktop';
}

/**
 * Classify a visit into a traffic-source channel from its referrer + landing URL.
 *
 * Channels: 'direct' | 'search' | 'social' | 'referral' | 'paid' | 'email'.
 * These mirror the buckets shown on the dashboard "Traffic Sources" card.
 *
 * Resolution order (most specific first):
 *   1. Campaign signals on the landing URL (UTM tags + ad click ids).
 *   2. Empty referrer  -> direct.
 *   3. Referrer on this same site (internal navigation) -> direct.
 *   4. Referrer host brand lookup -> search / social, else referral.
 *
 * @param string $referrer    document.referrer reported by the tracker (may be empty).
 * @param string $landing_url location.href of the landing page (carries UTM / click ids).
 * @param string $site_host   Host of this site (treat internal navigation as direct).
 * @return array{channel:string,host:string} `host` is the cleaned referrer domain,
 *               '' for direct / no external host.
 */
function brikpanel_classify_traffic_source( $referrer, $landing_url = '', $site_host = '' ) {
    $referrer    = is_string( $referrer ) ? trim( $referrer ) : '';
    $landing_url = is_string( $landing_url ) ? $landing_url : '';

    // --- Parse landing-URL query for campaign signals (UTM + ad click ids). ---
    $utm_medium     = '';
    $utm_source     = '';
    $has_paid_click = false;
    $has_fb_click   = false;
    $query = (string) wp_parse_url( $landing_url, PHP_URL_QUERY );
    if ( '' !== $query ) {
        $params = [];
        wp_parse_str( $query, $params );
        $utm_medium     = isset( $params['utm_medium'] ) ? strtolower( trim( (string) $params['utm_medium'] ) ) : '';
        $utm_source     = isset( $params['utm_source'] ) ? strtolower( trim( (string) $params['utm_source'] ) ) : '';
        $has_paid_click = isset( $params['gclid'] ) || isset( $params['gclsrc'] ) || isset( $params['wbraid'] )
            || isset( $params['gbraid'] ) || isset( $params['msclkid'] ) || isset( $params['ttclid'] );
        $has_fb_click   = isset( $params['fbclid'] );
    }

    // Clean referrer host: lowercase, drop a leading "www.".
    $ref_host = '';
    if ( '' !== $referrer ) {
        $ref_host = strtolower( (string) wp_parse_url( $referrer, PHP_URL_HOST ) );
        if ( 0 === strpos( $ref_host, 'www.' ) ) {
            $ref_host = substr( $ref_host, 4 );
        }
    }
    $site_host = strtolower( (string) $site_host );
    if ( 0 === strpos( $site_host, 'www.' ) ) {
        $site_host = substr( $site_host, 4 );
    }

    // Host reported for grouping. Prefer the real referrer host; fall back to
    // utm_source when campaign traffic arrived with no referrer (e.g. an app).
    $host = $ref_host;
    if ( '' === $host && '' !== $utm_source && false !== strpos( $utm_source, '.' ) ) {
        $host = $utm_source;
    }

    // --- 1) Explicit campaign signals win. ---
    $paid_mediums = [ 'cpc', 'ppc', 'paid', 'paidsearch', 'paid-search', 'cpm', 'cpv', 'display', 'banner', 'retargeting', 'remarketing' ];
    if ( $has_paid_click || in_array( $utm_medium, $paid_mediums, true ) ) {
        return [ 'channel' => 'paid', 'host' => $host ];
    }
    if ( in_array( $utm_medium, [ 'email', 'e-mail', 'newsletter' ], true )
        || in_array( $utm_source, [ 'email', 'e-mail', 'newsletter', 'mailchimp', 'klaviyo', 'sendgrid' ], true ) ) {
        return [ 'channel' => 'email', 'host' => $host ];
    }
    $social_mediums = [ 'social', 'social-media', 'social_media', 'social-network', 'social_network', 'sm', 'social-paid' ];
    if ( in_array( $utm_medium, $social_mediums, true ) || $has_fb_click ) {
        if ( '' === $host && $has_fb_click ) {
            $host = 'facebook.com';
        }
        return [ 'channel' => 'social', 'host' => $host ];
    }
    if ( 'referral' === $utm_medium ) {
        return [ 'channel' => 'referral', 'host' => $host ];
    }
    if ( 'organic' === $utm_medium ) {
        return [ 'channel' => 'search', 'host' => $host ];
    }

    // --- 2) No referrer and no campaign -> direct. ---
    if ( '' === $ref_host ) {
        return [ 'channel' => 'direct', 'host' => '' ];
    }

    // --- 3) Internal navigation -> direct. ---
    if ( '' !== $site_host
        && ( $ref_host === $site_host || substr( $ref_host, - strlen( '.' . $site_host ) ) === '.' . $site_host ) ) {
        return [ 'channel' => 'direct', 'host' => '' ];
    }

    // --- 4) Referrer host brand lookup. ---
    $labels = explode( '.', $ref_host );

    $search_brands = [ 'google', 'bing', 'yahoo', 'duckduckgo', 'yandex', 'baidu', 'ecosia', 'ask', 'aol', 'naver', 'seznam', 'startpage', 'qwant', 'brave' ];
    foreach ( $labels as $label ) {
        if ( in_array( $label, $search_brands, true ) ) {
            return [ 'channel' => 'search', 'host' => $host ];
        }
    }

    // Short / link-shortener social domains matched by exact suffix so they
    // never collide with unrelated hosts (e.g. "foot.com" must not match "t.co").
    $social_domains = [ 't.co', 'x.com', 'fb.me', 'wa.me', 't.me', 'youtu.be', 'lnkd.in' ];
    foreach ( $social_domains as $d ) {
        if ( $ref_host === $d || substr( $ref_host, - strlen( '.' . $d ) ) === '.' . $d ) {
            return [ 'channel' => 'social', 'host' => $host ];
        }
    }
    $social_brands = [ 'facebook', 'fb', 'instagram', 'twitter', 'linkedin', 'pinterest', 'reddit', 'youtube', 'tiktok', 'whatsapp', 'telegram', 'vk', 'snapchat', 'tumblr', 'quora', 'discord', 'threads' ];
    foreach ( $labels as $label ) {
        if ( in_array( $label, $social_brands, true ) ) {
            return [ 'channel' => 'social', 'host' => $host ];
        }
    }

    return [ 'channel' => 'referral', 'host' => $host ];
}

/**
 * Records a single traffic-source hit for today.
 *
 * Upsert into wp_brikpanel_referrers keyed on (date, channel, host). The table
 * carries a UNIQUE key so INSERT ... ON DUPLICATE KEY UPDATE is atomic and
 * race-free. `direct` and campaign-without-referrer rows store host = ''.
 *
 * @param string $channel One of the brikpanel_classify_traffic_source channels.
 * @param string $host    Cleaned referrer domain ('' for direct).
 */
function brikpanel_record_traffic_source( $channel, $host ) {
    global $wpdb;
    $table = $wpdb->prefix . 'brikpanel_referrers';
    $today = wp_date( 'Y-m-d' );

    // Keep the indexed host column within bounds (190 chars, utf8mb4-safe).
    if ( strlen( $host ) > 190 ) {
        $host = substr( $host, 0, 190 );
    }

    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $wpdb->query( $wpdb->prepare(
        "INSERT INTO {$table} (date_column, channel, host, hits)
         VALUES (%s, %s, %s, 1)
         ON DUPLICATE KEY UPDATE hits = hits + 1",
        $today,
        $channel,
        $host
    ) );
}

/**
 * Records a visitor hit including device-type breakdown.
 *
 * Atomic INSERT...ON DUPLICATE KEY UPDATE replaces the previous SELECT-then-
 * UPDATE pattern. That avoids:
 *   - the race condition where two concurrent visitors read the same count
 *     and both write count+1, losing one increment;
 *   - the extra SELECT round-trip on every page view.
 *
 * Requires a UNIQUE key on date_column for ON DUPLICATE KEY UPDATE to fire.
 *
 * Shared by the unified tracker endpoint and the legacy standalone AJAX
 * action below. Callers apply the master-switch / admin / bot guards.
 *
 * @param string $referrer    document.referrer reported by the tracker.
 * @param string $landing_url location.href of the landing page.
 */
function brikpanel_record_visitor_view( $referrer = '', $landing_url = '' ) {
    global $wpdb;
    $table       = $wpdb->prefix . 'brikpanel_visitors';
    $today       = wp_date( 'Y-m-d' );
    $device      = brikpanel_detect_device_type();
    $device_col  = $device . '_count';
    $valid_cols  = [ 'mobile_count', 'tablet_count', 'desktop_count' ];
    if ( ! in_array( $device_col, $valid_cols, true ) ) {
        $device_col = 'desktop_count';
    }

    // UPDATE first; if no row exists for today, INSERT. One DB round-trip in
    // the common case (existing row) instead of the previous SELECT+UPDATE.
    // The schema has a non-unique KEY on date_column, so we can't use ON
    // DUPLICATE KEY UPDATE without a migration.
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $updated = $wpdb->query( $wpdb->prepare(
        "UPDATE {$table}
            SET visitor_count = visitor_count + 1,
                {$device_col} = {$device_col} + 1
          WHERE date_column = %s",
        $today
    ) );

    if ( ! $updated ) {
        $wpdb->insert(
            $table,
            [
                'date_column'   => $today,
                'visitor_count' => 1,
                $device_col     => 1,
            ],
            [ '%s', '%d', '%d' ]
        );
    }

    // Record the traffic-source channel for the same visit. The tracker fires
    // once per visitor per day (localStorage), so this captures the first-touch
    // referrer of the day, matching the visitor_count semantics above.
    $site_host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
    $source    = brikpanel_classify_traffic_source( $referrer, $landing_url, $site_host );
    brikpanel_record_traffic_source( $source['channel'], $source['host'] );
}

/**
 * Legacy standalone AJAX action (pre-3.2.20 tracker JS). New pages ship the
 * unified tracker; this stays registered for cached pages still carrying the
 * old inline JS.
 */
function brikpanel_visitor_view() {
    // Master tracking switch — also guards pings from cached pages that still
    // carry the old tracker JS after the merchant turned tracking off.
    if ( function_exists( 'brikpanel_frontend_tracking_enabled' ) && ! brikpanel_frontend_tracking_enabled() ) {
        wp_send_json_success();
    }
    if ( brikpanel_is_admin_user() ) {
        wp_send_json_success();
    }
    if ( function_exists( '_brikpanel_is_bot_ua' ) && _brikpanel_is_bot_ua() ) {
        wp_send_json_success();
    }

    $referrer    = isset( $_POST['ref'] ) ? esc_url_raw( wp_unslash( $_POST['ref'] ) ) : '';
    $landing_url = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';
    brikpanel_record_visitor_view( $referrer, $landing_url );

    wp_send_json_success();
}
add_action('wp_ajax_nopriv_brikpanel_visitor_view', 'brikpanel_visitor_view');
add_action('wp_ajax_brikpanel_visitor_view', 'brikpanel_visitor_view');

// The inline visitor-view tracker JS moved into the unified tracker
// (back-end/tracking/brikpanel-unified-tracker.php) in 3.2.20.

/**
 * ANA YARDIMCI FONKSİYON
 * Belirtilen tarih aralığındaki toplam ziyaretçi sayısını hesaplar.
 *
 * @param string|null $start_date Başlangıç tarihi (Y-m-d formatında).
 * @param string|null $end_date Bitiş tarihi (Y-m-d formatında).
 * @return int Toplam ziyaretçi sayısı.
 */
function brikpanel_get_visitor_count($start_date = null, $end_date = null) {
    $cache_key = 'bp_vc_' . brikpanel_data_cache_ver() . '_' . md5( $start_date . $end_date );
    $cached    = get_transient( $cache_key );
    if ( false !== $cached ) {
        return (int) $cached;
    }

    global $wpdb;
    $table_name = $wpdb->prefix . "brikpanel_visitors";

    if ($start_date && $end_date) {
        $total_visitors = $wpdb->get_var($wpdb->prepare(
            "SELECT SUM(visitor_count) FROM {$table_name} WHERE date_column BETWEEN %s AND %s",
            $start_date,
            $end_date
        ));
    } elseif ($start_date) {
        $total_visitors = $wpdb->get_var($wpdb->prepare(
            "SELECT SUM(visitor_count) FROM {$table_name} WHERE date_column = %s",
            $start_date
        ));
    } else {
        $total_visitors = $wpdb->get_var("SELECT SUM(visitor_count) FROM {$table_name}");
    }

    $result = is_null($total_visitors) ? 0 : (int) $total_visitors;
    set_transient( $cache_key, $result, brikpanel_cache_ttl( 60 ) );
    return $result;
}
