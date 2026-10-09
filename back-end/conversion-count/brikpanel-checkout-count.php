<?php
if( ! defined( 'ABSPATH' ) ) exit;

/**
 * Checkout visits for the funnel: one per visitor per day.
 *
 * Counted on the checkout page itself (template_redirect, after WooCommerce
 * sent an empty cart back), so only a client that reached checkout with
 * something in its cart gets here. Since 3.3.30 it must also be a browser
 * carrying the "this is a person" mark or a signed-in customer; anyone else
 * waits in the session until the checkout page's own tracker proves it.
 *
 * @return void
 */
function brikpanel_checkout_counter() {
    // Sadece sitenin ön yüzünde ve ana ödeme sayfasında çalışır.
    if ( is_admin() || wp_doing_ajax() || ! is_checkout() || is_wc_endpoint_url() ) {
        return;
    }

    // Master tracking switch and cookie-consent gate. Server-side hook, so
    // the consent state is read from BrikPanel's own consent record cookie
    // (or the WP Consent API), never from the tracker script.
    if ( function_exists( 'brikpanel_frontend_tracking_allowed' ) && ! brikpanel_frontend_tracking_allowed( 'checkout' ) ) {
        return;
    }

    // Store staff are never counted.
    if ( brikpanel_is_admin_user() ) {
        return;
    }

    if ( function_exists( '_brikpanel_is_bot_ua' ) && _brikpanel_is_bot_ua() ) {
        return;
    }

    if ( function_exists( 'brikpanel_request_is_human' ) && ! brikpanel_request_is_human() ) {
        if ( function_exists( 'brikpanel_pending_add' ) ) {
            brikpanel_pending_add( 'checkout' );
        }
        return;
    }

    brikpanel_count_checkout_visit();
}
add_action('template_redirect', 'brikpanel_checkout_counter');

/**
 * Count one checkout visit for this browser today, unless it already has one.
 * Shared by the hook above and by the release of parked counts.
 *
 * Callers apply the tracking, staff, bot and person checks.
 *
 * @return bool Whether a count was written.
 */
function brikpanel_count_checkout_visit() {
    static $done = false;
    if ( $done ) {
        return false;
    }
    $done = true;

    // Counted today already: the mark's flag, or the cookie earlier releases
    // set (still honoured on the day this release arrives).
    if ( ( function_exists( 'brikpanel_human_has_flag' ) && brikpanel_human_has_flag( 'c' ) )
        || isset( $_COOKIE['brikpanel_checkout_count_cookie'] ) ) {
        return false;
    }

    global $wpdb;
    $table_name   = $wpdb->prefix . 'brikpanel_visitors';
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

    if ( function_exists( 'brikpanel_human_mark' ) ) {
        brikpanel_human_mark( 'c' );
    }
    return true;
}


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
