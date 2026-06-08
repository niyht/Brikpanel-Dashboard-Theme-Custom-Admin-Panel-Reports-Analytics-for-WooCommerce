<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function brikpanel_customize_admin_bar($wp_admin_bar) {
    // 📌 Remove default menus
    $wp_admin_bar->remove_node('themes');    // Themes
    $wp_admin_bar->remove_node('menus');     // Menus
    $wp_admin_bar->remove_node('plugins');   // Plugins

    // 🛒 "Orders" Menu (with custom SVG icon)
    $wp_admin_bar->add_node([
        'id'     => 'brikpanel_orders',
        'title'  => __('Orders', 'brikpanel'),
        'parent' => 'site-name',
        'href'   => admin_url('edit.php?post_type=shop_order')
    ]);

    // "Products" Menu
    $wp_admin_bar->add_node([
        'id'     => 'brikpanel_products',
        'title'  => __('Products', 'brikpanel'),
        'parent' => 'site-name',
        'href'   => admin_url('edit.php?post_type=product')
    ]);

    // 📊 "Analytics" Menu
    $wp_admin_bar->add_node([
        'id'     => 'brikpanel_analytics',
        'title'  => __('Analytics', 'brikpanel'),
        'parent' => 'site-name',
        'href'   => admin_url('admin.php?page=wc-admin&path=/analytics/overview')
    ]);

}
add_action('admin_bar_menu', 'brikpanel_customize_admin_bar', 100);
