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
 * Records a visitor hit including device-type breakdown.
 *
 * Atomic INSERT...ON DUPLICATE KEY UPDATE replaces the previous SELECT-then-
 * UPDATE pattern. That avoids:
 *   - the race condition where two concurrent visitors read the same count
 *     and both write count+1, losing one increment;
 *   - the extra SELECT round-trip on every page view.
 *
 * Requires a UNIQUE key on date_column for ON DUPLICATE KEY UPDATE to fire.
 */
function brikpanel_visitor_view() {
    if ( brikpanel_is_admin_user() ) {
        wp_send_json_success();
    }
    if ( function_exists( '_brikpanel_is_bot_ua' ) && _brikpanel_is_bot_ua() ) {
        wp_send_json_success();
    }

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

    wp_send_json_success();
}
add_action('wp_ajax_nopriv_brikpanel_visitor_view', 'brikpanel_visitor_view');
add_action('wp_ajax_brikpanel_visitor_view', 'brikpanel_visitor_view');

/**
 * Ziyaretçiyi saymak için JS kodunu siteye ekler.
 */
function brikpanel_visitor_view_script() {
    if (is_admin() || wp_doing_ajax() || brikpanel_is_admin_user()) return;
    // Skip the script entirely for bots — saves the localStorage check + fetch.
    if ( function_exists( '_brikpanel_is_bot_ua' ) && _brikpanel_is_bot_ua() ) return;

    // --- DÜZELTME: gmdate() yerine wp_date() kullanıyoruz ---
    // Bu, localStorage anahtarının sitenin saat dilimine göre doğru gün için üretilmesini sağlar.
    $key = 'brikpanel_visitor_viewed_' . wp_date('Y-m-d');
    $ajax = esc_url(admin_url('admin-ajax.php'));
    ?>
    <script>
    (function() {
        const KEY = '<?php echo esc_js($key); ?>';
        if (localStorage.getItem(KEY)) return;
        const fd = new FormData();
        fd.append('action', 'brikpanel_visitor_view');
        fetch('<?php echo esc_url_raw($ajax); ?>', {
            method: 'POST',
            credentials: 'same-origin',
            body: fd
        }).then(function(res) {
            if (res.ok) {
                localStorage.setItem(KEY, '1');
            }
        });
    })();
    </script>
    <?php
}
add_action('wp_footer', 'brikpanel_visitor_view_script', 20);

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
