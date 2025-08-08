<?php

if(!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// HPOS aktif mi kontrolü
$is_hpos = get_option('woocommerce_custom_orders_table_enabled') === 'yes';

// Tüm kolon başlıklarını çeviriye uygun yap
if ($is_hpos) {
    // HPOS ekranı için (wc-orders)
    add_filter('manage_woocommerce_page_wc-orders_columns', function ($columns) {
        $columns['delivery_type']  = esc_html__( 'Delivery Type', 'brikpanel-admin-panel-dashboard-for-woocommerce' );
        $columns['order_items']    = esc_html__( 'Items', 'brikpanel-admin-panel-dashboard-for-woocommerce' );
        $columns['tax_total']      = esc_html__( 'Tax Total', 'brikpanel-admin-panel-dashboard-for-woocommerce' );
        return $columns;
    }, 20);

    add_action('manage_woocommerce_page_wc-orders_custom_column', function ($column, $order) {
        switch ($column) {
            case 'delivery_type':
                $shipping_method = $order->get_shipping_method();
                echo esc_html(
                    stripos($shipping_method, 'pickup') !== false
                        ? __( 'Store Pickup', 'brikpanel-admin-panel-dashboard-for-woocommerce' )
                        : __( 'Shipping', 'brikpanel-admin-panel-dashboard-for-woocommerce' )
                );
                break;

            case 'order_items':
                foreach ($order->get_items() as $item) {
                    echo esc_html($item->get_name()) . ' x ' . esc_html($item->get_quantity()) . '<br>';
                }
                break;

            case 'tax_total':
                echo wc_price($order->get_total_tax());
                break;
        }
    }, 20, 2);
} else {
    // Klasik Woo sipariş ekranı için (edit.php?post_type=shop_order)
    add_filter('manage_edit-shop_order_columns', function ($columns) {
        $columns['delivery_type']  = esc_html__( 'Delivery Type', 'brikpanel-admin-panel-dashboard-for-woocommerce' );
        $columns['order_items']    = esc_html__( 'Items', 'brikpanel-admin-panel-dashboard-for-woocommerce' );
        $columns['tax_total']      = esc_html__( 'Tax Total', 'brikpanel-admin-panel-dashboard-for-woocommerce' );
        return $columns;
    }, 20);

    add_action('manage_shop_order_posts_custom_column', function ($column, $post_id) {
        $order = wc_get_order($post_id);
        if (!$order) return;
        switch ($column) {
            case 'delivery_type':
                $shipping_method = $order->get_shipping_method();
                echo esc_html(
                    stripos($shipping_method, 'pickup') !== false
                        ? __( 'Store Pickup', 'brikpanel-admin-panel-dashboard-for-woocommerce' )
                        : __( 'Shipping', 'brikpanel-admin-panel-dashboard-for-woocommerce' )
                );
                break;

            case 'order_items':
                foreach ($order->get_items() as $item) {
                    echo esc_html($item->get_name()) . ' x ' . esc_html($item->get_quantity()) . '<br>';
                }
                break;

            case 'tax_total':
                echo wc_price($order->get_total_tax());
                break;
        }
    }, 20, 2);
}
