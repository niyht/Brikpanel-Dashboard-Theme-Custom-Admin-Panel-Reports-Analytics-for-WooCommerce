<?php
if( ! defined( 'ABSPATH' ) ) exit;

/**
 * Ödeme sayfası ziyaretlerini sayar ve veritabanına kaydeder.
 */
function brikpanel_checkout_counter() {
    // Sadece sitenin ön yüzünde ve ana ödeme sayfasında çalışır.
    if ( is_admin() || wp_doing_ajax() || ! is_checkout() || is_wc_endpoint_url() ) {
        return;
    }

    // Skip tracking for admin users.
    if ( brikpanel_is_admin_user() ) {
        return;
    }

    // Cookie varsa, bu kullanıcı bugün zaten sayılmış demektir.
    if ( isset( $_COOKIE['brikpanel_checkout_count_cookie'] ) ) {
        return;
    }

    if ( function_exists( '_brikpanel_is_bot_ua' ) && _brikpanel_is_bot_ua() ) {
        return;
    }

    global $wpdb;
    $table_name   = $wpdb->prefix . "brikpanel_visitors";
    $current_date = wp_date( 'Y-m-d' );

    $updated = $wpdb->query( $wpdb->prepare(
        "UPDATE {$table_name} SET checkout_count = checkout_count + 1 WHERE date_column = %s",
        $current_date
    ) );

    if ( ! $updated ) {
        $wpdb->insert(
            $table_name,
            [ 'date_column' => $current_date, 'checkout_count' => 1 ],
            [ '%s', '%d' ]
        );
    }

    // --- DÜZELTME: Cookie'yi gün sonuna kadar geçerli yapıyoruz ---
    // Bu, saat dilimi ne olursa olsun, cookie'nin tam olarak gece yarısı dolmasını sağlar.
    $seconds_until_midnight = strtotime('tomorrow', current_time('timestamp')) - current_time('timestamp');
    setcookie('brikpanel_checkout_count_cookie', '1', time() + $seconds_until_midnight, COOKIEPATH, COOKIE_DOMAIN);
}
add_action('template_redirect', 'brikpanel_checkout_counter');


/**
 * ANA YARDIMCI FONKSİYON
 * Belirtilen tarih aralığındaki toplam ödeme sayfası ziyaretini hesaplar.
 *
 * @param string|null $start_date Başlangıç tarihi (Y-m-d formatında).
 * @param string|null $end_date Bitiş tarihi (Y-m-d formatında).
 * @return int Toplam ziyaretçi sayısı.
 */
function brikpanel_get_checkout_count($start_date = null, $end_date = null) {
    global $wpdb;
    $table_name = $wpdb->prefix . "brikpanel_visitors";

    $query = "SELECT SUM(checkout_count) FROM {$table_name} WHERE 1=1";
    $query_args = array();

    if ($start_date && $end_date) {
        $query .= " AND date_column BETWEEN %s AND %s";
        $query_args[] = $start_date;
        $query_args[] = $end_date;
    } elseif ($start_date) {
        $query .= " AND date_column = %s";
        $query_args[] = $start_date;
    }

    if (!empty($query_args)) {
        $total_visitors = $wpdb->get_var($wpdb->prepare($query, $query_args));
    } else {
        $total_visitors = $wpdb->get_var($query);
    }

    return is_null($total_visitors) ? 0 : (int) $total_visitors;
}
