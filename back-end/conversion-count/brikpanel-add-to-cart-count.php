<?php
if( ! defined( 'ABSPATH' ) ) exit;

/**
 * Sepete ekleme olayını sayar ve veritabanına kaydeder.
 */
function brikpanel_add_to_cart_counter() {
    // Master tracking switch and cookie-consent gate. This counter runs on a
    // pure PHP hook with no JavaScript involved, so the visitor's consent has
    // to be readable server-side — that is what BrikPanel's own consent
    // record cookie is for.
    if ( function_exists( 'brikpanel_frontend_tracking_allowed' ) && ! brikpanel_frontend_tracking_allowed( 'add_to_cart' ) ) {
        return;
    }

    // Skip tracking for admin users.
    if ( brikpanel_is_admin_user() ) {
        return;
    }

    // Cookie varsa, bu kullanıcı bugün zaten sayılmış demektir.
    if ( isset( $_COOKIE['brikpanel_add_to_cart_count_cookie'] ) ) {
        return;
    }

    if ( function_exists( '_brikpanel_is_bot_ua' ) && _brikpanel_is_bot_ua() ) {
        return;
    }

    // Reaching this line means the client did not present our daily cookie, so
    // as far as this counter can tell it remembers nothing about today. That is
    // either a real shopper's first add — counted, exactly as before — or a
    // client that discards its memory between turns, which would otherwise be
    // counted once per `?add-to-cart=` URL it follows. A server-side lock keyed
    // on its address applies the same one-per-day cap the cookie gives everyone
    // else. Holding cookies is not proof of memory, so this no longer waves a
    // client through just because it sent some.
    //
    // Signed-in customers are exempt: their identity is durable and their
    // cookie demonstrably works, so guessing at an address would only make two
    // colleagues behind one office IP cancel each other out.
    if ( ! is_user_logged_in()
        && function_exists( 'brikpanel_client_daily_lock' )
        && ! brikpanel_client_daily_lock( 'atc' ) ) {
        return;
    }

    global $wpdb;
    $table_name   = $wpdb->prefix . "brikpanel_visitors";
    $current_date = wp_date( 'Y-m-d' );

    $updated = $wpdb->query( $wpdb->prepare(
        "UPDATE {$table_name} SET add_to_cart_count = add_to_cart_count + 1 WHERE date_column = %s",
        $current_date
    ) );

    if ( ! $updated ) {
        $wpdb->insert(
            $table_name,
            [ 'date_column' => $current_date, 'add_to_cart_count' => 1 ],
            [ '%s', '%d' ]
        );
    }

    // --- DÜZELTME: Cookie'yi gün sonuna kadar geçerli yapıyoruz ---
    // Bu, saat dilimi ne olursa olsun, cookie'nin tam olarak gece yarısı dolmasını sağlar.
    $seconds_until_midnight = strtotime('tomorrow', current_time('timestamp')) - current_time('timestamp');
    setcookie('brikpanel_add_to_cart_count_cookie', '1', time() + $seconds_until_midnight, COOKIEPATH, COOKIE_DOMAIN);
}
add_action( 'woocommerce_add_to_cart', 'brikpanel_add_to_cart_counter');


/**
 * ANA YARDIMCI FONKSİYON
 * Belirtilen tarih aralığındaki toplam sepete ekleme sayısını hesaplar.
 *
 * @param string|null $start_date Başlangıç tarihi (Y-m-d formatında).
 * @param string|null $end_date Bitiş tarihi (Y-m-d formatında).
 * @return int Toplam sepete ekleme sayısı.
 */
function brikpanel_get_add_to_cart_count($start_date = null, $end_date = null) {
    global $wpdb;
    $table_name = $wpdb->prefix . "brikpanel_visitors";

    $query = "SELECT SUM(add_to_cart_count) FROM {$table_name} WHERE 1=1";
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
        $total_count = $wpdb->get_var($wpdb->prepare($query, $query_args));
    } else {
        $total_count = $wpdb->get_var($query);
    }

    return is_null($total_count) ? 0 : (int) $total_count;
}
