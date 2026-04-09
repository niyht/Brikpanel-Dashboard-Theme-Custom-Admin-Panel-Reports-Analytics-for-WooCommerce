<?php
/**
 * Plugin Name: BrikPanel: WooCommerce Admin Dashboard Theme
 * Description: Beautiful and modern admin panel dashboard for WooCommerce plugin premium version.
 * Version: 2.0.1
 * Author: Brksoft
 * Author URI: https://brksoft.com/
 * Text Domain: brikpanel
 * Domain Path: /languages
 * Requires Plugins: woocommerce
 * WC requires at least: 4.0
 * WC tested up to: 9.4
 * Requires PHP: 7.4
 * License: GPL-2.0+
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
**/

if (!defined('ABSPATH')) {
    exit;
}

// =============================================================================
// CONSTANTS
// =============================================================================
define('BRIKPANEL_VERSION', '2.0.1');
define('BRIKPANEL_PATH', plugin_dir_path(__FILE__));
define('BRIKPANEL_URL', plugin_dir_url(__FILE__));
define('BRIKPANEL_BASENAME', plugin_basename(__FILE__));

// =============================================================================
// WOOCOMMERCE HPOS COMPATIBILITY
// =============================================================================
add_action('before_woocommerce_init', function () {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

// =============================================================================
// CUSTOM ORDER STATUSES (must register globally, not just in admin)
// =============================================================================
add_action('init', function () {
    register_post_status('wc-return-draft', [
        'label'                     => _x('Return Draft', 'Order status', 'brikpanel'),
        'public'                    => true,
        'exclude_from_search'       => false,
        'show_in_admin_all_list'    => true,
        'show_in_admin_status_list' => true,
        'label_count'               => _n_noop(
            'Return Draft <span class="count">(%s)</span>',
            'Return Draft <span class="count">(%s)</span>',
            'brikpanel'
        ),
    ]);

    register_post_status('wc-change', [
        'label'                     => _x('Change', 'Order status', 'brikpanel'),
        'public'                    => true,
        'exclude_from_search'       => false,
        'show_in_admin_all_list'    => true,
        'show_in_admin_status_list' => true,
        'label_count'               => _n_noop(
            'Change <span class="count">(%s)</span>',
            'Change <span class="count">(%s)</span>',
            'brikpanel'
        ),
    ]);
});

add_filter('wc_order_statuses', function ($statuses) {
    $statuses['wc-return-draft'] = _x('Return Draft', 'Order status', 'brikpanel');
    $statuses['wc-change']       = _x('Change', 'Order status', 'brikpanel');
    return $statuses;
});

// =============================================================================
// LOAD TEXT DOMAIN
// =============================================================================
function brikpanel_load_textdomain() {
    load_plugin_textdomain('brikpanel', false, dirname(BRIKPANEL_BASENAME) . '/languages');
}
add_action('plugins_loaded', 'brikpanel_load_textdomain');

// =============================================================================
// ADMIN SIDE FILES - Load on init (same timing as 1.4.0)
// =============================================================================
function brikpanel_init_admin() {
    if (!is_admin()) {
        return;
    }

    // Front-end files (for admin)
    require_once BRIKPANEL_PATH . 'front-end/dashboard/brikpanel-dashboard.php';
    require_once BRIKPANEL_PATH . 'front-end/navigation/brikpanel-navigation.php';
    require_once BRIKPANEL_PATH . 'front-end/search/brikpanel-search.php';
    require_once BRIKPANEL_PATH . 'front-end/orders/brikpanel-orders.php';
    require_once BRIKPANEL_PATH . 'front-end/orders/brikpanel-orders-stats.php';
    require_once BRIKPANEL_PATH . 'front-end/products/brikpanel-product-editor.php';
    require_once BRIKPANEL_PATH . 'front-end/products/brikpanel-products-list.php';
    require_once BRIKPANEL_PATH . 'front-end/products/brikpanel-category-enhancements.php';
    require_once BRIKPANEL_PATH . 'front-end/coupons/brikpanel-coupons.php';

    // Back-end files
    require_once BRIKPANEL_PATH . 'back-end/total-sales/brikpanel-total-sales.php';
    require_once BRIKPANEL_PATH . 'back-end/conversion-count/brikpanel-total-orders.php';
    require_once BRIKPANEL_PATH . 'back-end/order-value/brikpanel-order-value.php';
    require_once BRIKPANEL_PATH . 'back-end/product-sales/brikpanel-product-sales.php';
    require_once BRIKPANEL_PATH . 'back-end/order-rates/brikpanel-order-rates.php';
}
add_action('init', 'brikpanel_init_admin');

// =============================================================================
// LOGIN PAGE CUSTOMIZATION
// =============================================================================
require_once BRIKPANEL_PATH . 'front-end/login/brikpanel-login.php';

// =============================================================================
// FRONT-END & GENERAL FILES
// =============================================================================
function brikpanel_init_other() {
    require_once BRIKPANEL_PATH . 'front-end/products/brikpanel-variation-gallery.php';
    require_once BRIKPANEL_PATH . 'front-end/brikpanel-site-submenu.php';
    require_once BRIKPANEL_PATH . 'front-end/sound/brikpanel-sound.php';
    require_once BRIKPANEL_PATH . 'back-end/conversion-count/brikpanel-conversion-count.php';
    require_once BRIKPANEL_PATH . 'back-end/conversion-count/brikpanel-product-count.php';
    require_once BRIKPANEL_PATH . 'back-end/conversion-count/brikpanel-checkout-count.php';
    require_once BRIKPANEL_PATH . 'back-end/conversion-count/brikpanel-add-to-cart-count.php';
    require_once BRIKPANEL_PATH . 'back-end/most-count/most-add-to-cart/brikpanel-most-add-to-cart.php';
    require_once BRIKPANEL_PATH . 'back-end/most-count/most-sale/brikpanel-most-sale.php';
    require_once BRIKPANEL_PATH . 'back-end/most-count/most-view/brikpanel-most-view.php';
    require_once BRIKPANEL_PATH . 'back-end/live/brikpanel-live.php';
}
add_action('init', 'brikpanel_init_other');

// =============================================================================
// WELCOME / FEATURE SHOWCASE POPUP
// =============================================================================
require_once BRIKPANEL_PATH . 'front-end/welcome/brikpanel-welcome.php';

// =============================================================================
// HELPER FUNCTIONS
// =============================================================================
require_once BRIKPANEL_PATH . 'includes/brikpanel-helpers.php';

// =============================================================================
// ENQUEUE SCRIPTS & STYLES
// =============================================================================
require_once BRIKPANEL_PATH . 'includes/brikpanel-enqueue.php';

// =============================================================================
// DATABASE TABLE CREATION
// =============================================================================
function brikpanel_create_table() {
    global $wpdb;

    $visitors_table       = $wpdb->prefix . "brikpanel_visitors";
    $cart_tracking_table  = $wpdb->prefix . "brikpanel_cart_tracking";
    $visited_pages_table  = $wpdb->prefix . "brikpanel_visited_pages";
    $charset_collate = $wpdb->get_charset_collate();

    $sql_visitors = "CREATE TABLE $visitors_table (
        id BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        date_column DATE NOT NULL,
        visitor_count INT DEFAULT 0,
        product_count INT DEFAULT 0,
        add_to_cart_count INT DEFAULT 0,
        checkout_count INT DEFAULT 0,
        KEY idx_date (date_column)
    ) $charset_collate;";

    $sql_cart_tracking = "CREATE TABLE $cart_tracking_table (
        id BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        product_id BIGINT(20) UNSIGNED NOT NULL,
        cart_count INT DEFAULT 0,
        date_column DATETIME DEFAULT CURRENT_TIMESTAMP,
        KEY product_id (product_id),
        KEY idx_date (date_column),
        KEY idx_product_date (product_id, date_column)
    ) $charset_collate;";

    $sql_visited_pages = "CREATE TABLE $visited_pages_table (
        id BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        page_id BIGINT(20) UNSIGNED NOT NULL,
        visit_count INT DEFAULT 0,
        date_column DATETIME DEFAULT CURRENT_TIMESTAMP,
        KEY page_id (page_id),
        KEY idx_date (date_column),
        KEY idx_page_date (page_id, date_column)
    ) $charset_collate;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql_visitors);
    dbDelta($sql_cart_tracking);
    dbDelta($sql_visited_pages);
}
register_activation_hook(__FILE__, 'brikpanel_create_table');


// =============================================================================
// CONFLICT WARNING
// =============================================================================
function brik82ad_show_conflict_warning() {
    $free_plugin = 'brikpanel-admin-panel-dashboard-for-woocommerce/brikpanel-admin-panel-dashboard-for-woocommerce.php';

    if (!function_exists('is_plugin_active')) {
        include_once(ABSPATH . 'wp-admin/includes/plugin.php');
    }

    if (is_plugin_active($free_plugin)) {
        echo '<div class="notice notice-error" style="border-left-color:#dc3232 !important;">
<p><strong>Warning:</strong> Both <code>BrikPanel Free</code> and <code>BrikPanel Premium</code> versions are active. Please leave only one active to avoid conflicts.</p></div>';
    }
}
add_action('admin_notices', 'brik82ad_show_conflict_warning');
