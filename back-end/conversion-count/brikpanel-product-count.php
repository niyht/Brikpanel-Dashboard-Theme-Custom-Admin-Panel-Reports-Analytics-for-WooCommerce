<?php
if( ! defined( 'ABSPATH' ) ) exit;

/**
 * Ürün sayfası ziyaretini veritabanına kaydeder (kayıt çekirdeği).
 *
 * Shared by the unified tracker endpoint and the legacy standalone AJAX
 * action below. Callers apply the master-switch / admin / bot guards.
 */
function brikpanel_record_product_view() {
    global $wpdb;
    $table = $wpdb->prefix . 'brikpanel_visitors';
    $today = wp_date( 'Y-m-d' );

    $updated = $wpdb->query( $wpdb->prepare(
        "UPDATE {$table} SET product_count = product_count + 1 WHERE date_column = %s",
        $today
    ) );

    if ( ! $updated ) {
        $wpdb->insert(
            $table,
            [ 'date_column' => $today, 'product_count' => 1 ],
            [ '%s', '%d' ]
        );
    }
}

/**
 * Legacy standalone AJAX action (pre-3.2.20 tracker JS). New pages ship the
 * unified tracker; this stays registered for cached pages still carrying the
 * old inline JS.
 */
function brikpanel_product_view() {
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

    if ( ! isset( $_POST['security'] ) || ! wp_verify_nonce( sanitize_key( $_POST['security'] ), 'brikpanel_nonce_action' ) ) {
        wp_send_json_error( [ 'message' => 'Invalid nonce.' ] );
    }

    if ( empty( $_POST['is_product'] ) || $_POST['is_product'] !== '1' ) {
        wp_send_json_error();
    }

    brikpanel_record_product_view();

    wp_send_json_success();
}
add_action( 'wp_ajax_nopriv_brikpanel_product_view', 'brikpanel_product_view' );
add_action( 'wp_ajax_brikpanel_product_view', 'brikpanel_product_view' );

// The inline product-view tracker JS moved into the unified tracker
// (back-end/tracking/brikpanel-unified-tracker.php) in 3.2.20.


/**
 * ANA YARDIMCI FONKSİYON
 * Belirtilen tarih aralığındaki toplam ürün görüntülenme sayısını hesaplar.
 *
 * @param string|null $start_date Başlangıç tarihi (Y-m-d formatında).
 * @param string|null $end_date Bitiş tarihi (Y-m-d formatında).
 * @return int Toplam ürün görüntülenme sayısı.
 */
function brikpanel_get_product_view_count( $start_date = null, $end_date = null ) {
    global $wpdb;
    $table_name = $wpdb->prefix . "brikpanel_visitors";

    // SQL sorgusunu ve argümanları dinamik olarak oluşturalım
    $query = "SELECT SUM(product_count) FROM {$table_name} WHERE 1=1";
    $query_args = array();

    if ( $start_date && $end_date ) {
        $query .= " AND date_column BETWEEN %s AND %s";
        $query_args[] = $start_date;
        $query_args[] = $end_date;
    } elseif ( $start_date ) {
        $query .= " AND date_column = %s";
        $query_args[] = $start_date;
    }

    // Eğer argüman varsa, sorguyu hazırla
    if ( ! empty( $query_args ) ) {
        $total_views = $wpdb->get_var( $wpdb->prepare( $query, $query_args ) );
    } else {
        // Eğer tarih aralığı yoksa (tüm zamanlar), basit sorgu çalıştır
        $total_views = $wpdb->get_var( $query );
    }

    return is_null($total_views) ? 0 : (int) $total_views;
}
