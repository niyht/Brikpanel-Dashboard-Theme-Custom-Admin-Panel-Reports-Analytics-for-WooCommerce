<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function brik82ad_customize_admin_bar($wp_admin_bar) {
    // 📌 Varsayılan menüleri kaldır
    $wp_admin_bar->remove_node('themes');    // Themes
    $wp_admin_bar->remove_node('menus');     // Menus
    $wp_admin_bar->remove_node('plugins');   // Plugins

    // 🛒 "Siparişler" Menüsü (Özel SVG İkonlu)
    $wp_admin_bar->add_node([
        'id'     => 'brik82ad_orders',
        'title'  => __('Orders', 'brikpanel-admin-panel-dashboard-for-woocommerce'),
        'parent' => 'site-name',
        'href'   => admin_url('edit.php?post_type=shop_order')
    ]);

    // "Ürünler" Menüsü
    $wp_admin_bar->add_node([
        'id'     => 'brik82ad_products',
        'title'  => __('Products', 'brikpanel-admin-panel-dashboard-for-woocommerce'),
        'parent' => 'site-name',
        'href'   => admin_url('edit.php?post_type=product')
    ]);

    // 📊 "Analiz" Menüsü
    $wp_admin_bar->add_node([
        'id'     => 'brik82ad_analytics',
        'title'  => __('Analytics', 'brikpanel-admin-panel-dashboard-for-woocommerce'),
        'parent' => 'site-name',
        'href'   => admin_url('admin.php?page=wc-admin&path=/analytics/overview')
    ]);

}
add_action('admin_bar_menu', 'brik82ad_customize_admin_bar', 100);
