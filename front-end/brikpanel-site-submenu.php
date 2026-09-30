<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function brikpanel_customize_admin_bar($wp_admin_bar) {
    // 📌 Remove default menus
    $wp_admin_bar->remove_node('themes');    // Themes
    $wp_admin_bar->remove_node('menus');     // Menus
    $wp_admin_bar->remove_node('plugins');   // Plugins

    // The shortcuts below sit in the toolbar every logged-in user with a toolbar
    // sees, in wp-admin and on the storefront: editors, authors and contributors
    // included. Each one is added only when the user can open the screen it
    // points to, using the capability that screen itself checks. Added
    // unconditionally they were three links that answered 403 for everyone
    // below shop manager. A post type that is not registered (WooCommerce not
    // active on this site of a network) means the screen does not exist, so no
    // fallback capability is assumed.
    $brikpanel_orders_type = get_post_type_object( 'shop_order' );
    $brikpanel_product_type = get_post_type_object( 'product' );

    // With "Block pages hidden from the menu" on, a screen the Navigation rules
    // close for this user gets no shortcut either: it would only open the
    // "not available" card. The check exists in wp-admin only; the storefront
    // has no sidebar to hide anything.
    $brikpanel_closed = static function ( $url ) {
        return function_exists( 'brikpanel_nav_url_blocked_for_current_user' ) && brikpanel_nav_url_blocked_for_current_user( $url );
    };

    // 🛒 "Orders" Menu (with custom SVG icon)
    if ( $brikpanel_orders_type && current_user_can( $brikpanel_orders_type->cap->edit_posts ) && ! $brikpanel_closed( admin_url( 'edit.php?post_type=shop_order' ) ) ) {
        $wp_admin_bar->add_node([
            'id'     => 'brikpanel_orders',
            'title'  => __('Orders', 'brikpanel'),
            'parent' => 'site-name',
            'href'   => admin_url('edit.php?post_type=shop_order')
        ]);
    }

    // "Products" Menu
    if ( $brikpanel_product_type && current_user_can( $brikpanel_product_type->cap->edit_posts ) && ! $brikpanel_closed( admin_url( 'edit.php?post_type=product' ) ) ) {
        $wp_admin_bar->add_node([
            'id'     => 'brikpanel_products',
            'title'  => __('Products', 'brikpanel'),
            'parent' => 'site-name',
            'href'   => admin_url('edit.php?post_type=product')
        ]);
    }

    // 📊 "Analytics" Menu. The capability is checked first, so a visitor without
    // it never pays for the WooCommerce feature lookup; the lookup then keeps
    // the link away from stores where WooCommerce Analytics is switched off.
    // The target is the report WooCommerce itself opens from its Analytics
    // menu: WooCommerce 4.0 and 4.1 have no Overview report and drew an empty
    // screen for the old fixed `/analytics/overview` link.
    if ( current_user_can( 'view_woocommerce_reports' )
        && function_exists( 'brikpanel_wc_analytics_enabled' )
        && brikpanel_wc_analytics_enabled()
        && ! $brikpanel_closed( brikpanel_wc_analytics_landing_url() ) ) {
        $wp_admin_bar->add_node([
            'id'     => 'brikpanel_analytics',
            'title'  => __('Analytics', 'brikpanel'),
            'parent' => 'site-name',
            'href'   => brikpanel_wc_analytics_landing_url()
        ]);
    }

}
add_action('admin_bar_menu', 'brikpanel_customize_admin_bar', 100);
