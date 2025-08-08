<?php

if( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Sipariş tamamlandığında en son sipariş ID'sini kaydet
function brik82ad_notify_new_order($order_id) {
    update_option('brik82ad_last_new_order', $order_id);

}
add_action('woocommerce_order_status_processing', 'brik82ad_notify_new_order'); // "Hazırlanıyor" olan siparişler

// AJAX endpoint (JS buradan veri çekecek)
function brik82ad_get_last_completed_order() {
    $last_order_id = get_option('brik82ad_last_new_order', 0);
    wp_send_json_success(['last_order_id' => intval($last_order_id)]);
}
add_action('wp_ajax_brik82ad_get_last_completed_order', 'brik82ad_get_last_completed_order');
add_action('wp_ajax_nopriv_brik82ad_get_last_completed_order', 'brik82ad_get_last_completed_order'); // Giriş yapmamışlar için
