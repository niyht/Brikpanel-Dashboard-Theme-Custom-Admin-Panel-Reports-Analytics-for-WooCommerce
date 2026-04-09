<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Bir ürün sepete eklendiğinde sayacı günceller.
 * Not: Veriler Yerel Zaman (Local Time) olarak kaydediliyor.
 */
function brikpanel_track_cart_addition($cart_item_key, $product_id) {
    // Skip tracking for admin users.
    if ( brikpanel_is_admin_user() ) {
        return;
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'brikpanel_cart_tracking';

    // Bugünün tarihi (Yerel Zaman)
    $current_date = wp_date('Y-m-d');
    
    // O gün için bu ürün daha önce eklenmiş mi?
    // Performans için DATE() fonksiyonu yerine LIKE veya aralık kullanılabilir ama 
    // günlük kontrol için bu yapı kabul edilebilir.
    $existing_entry_id = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$table_name} WHERE product_id = %d AND date_column LIKE %s",
        $product_id,
        $current_date . '%' // '2023-10-25%' şeklinde arama yapar
    ));

    if ($existing_entry_id) {
        $wpdb->query($wpdb->prepare(
            "UPDATE {$table_name} SET cart_count = cart_count + 1 WHERE id = %d",
            $existing_entry_id
        ));
    } else {
        $wpdb->insert(
            $table_name,
            [
                'product_id'  => $product_id,
                'cart_count'  => 1,
                'date_column' => current_time('mysql') // Yerel zaman kaydı
            ],
            ['%d', '%d', '%s']
        );
    }
}
add_action('woocommerce_add_to_cart', 'brikpanel_track_cart_addition', 10, 2);