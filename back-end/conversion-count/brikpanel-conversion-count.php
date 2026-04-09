<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Ziyaretçi sayısını veritabanına kaydeden AJAX fonksiyonu.
 */
function brikpanel_visitor_view() {
    // Skip tracking for admin users.
    if ( brikpanel_is_admin_user() ) {
        wp_send_json_success();
    }

    global $wpdb;
    $table = $wpdb->prefix . 'brikpanel_visitors';

    // --- DÜZELTME: gmdate() yerine wp_date() kullanıyoruz ---
    // Bu, sitenin saat dilimine göre doğru günü kaydeder.
    $today = wp_date('Y-m-d');

    $count = $wpdb->get_var($wpdb->prepare(
        "SELECT visitor_count FROM {$table} WHERE date_column = %s",
        $today
    ));

    if ($count !== null) {
        $wpdb->update($table, ['visitor_count' => $count + 1], ['date_column' => $today], ['%d'], ['%s']);
    } else {
        $wpdb->insert($table, ['date_column' => $today, 'visitor_count' => 1], ['%s', '%d']);
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
    $cache_key = 'brikpanel_vc_' . md5( $start_date . $end_date );
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
    set_transient( $cache_key, $result, 60 );
    return $result;
}
