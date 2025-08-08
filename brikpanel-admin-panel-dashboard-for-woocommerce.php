<?php
/**
 * Plugin Name: Brikpanel – Dashboard Theme | Custom Admin Panel, Reports & Analytics for WooCommerce
 * Description: Beautiful and modern admin panel dashboard for WooCommerce
 * Version: 1.3.2
 * Author: Brksoft
 * Author URI: https://brksoft.com/
 * Text Domain: brikpanel-admin-panel-dashboard-for-woocommerce
 * Domain Path: /languages
 * Requires Plugins: woocommerce
 * WC requires at least: 4.0
 * WC tested up to: 9.4
 * License: GPL-2.0+
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
**/

if (!defined('ABSPATH')) {
    exit;
}

// WooCommerce compatibility
add_action('before_woocommerce_init', function () {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

// Load text domain
function brik82ad_load_textdomain() {
    load_plugin_textdomain('brikpanel-admin-panel-dashboard-for-woocommerce', false, dirname(plugin_basename(__FILE__)) . '/languages');
}
add_action('plugins_loaded', 'brik82ad_load_textdomain');

// Ana sabitlerimizi tanımlayalım
define( 'BRIK82AD_PATH', plugin_dir_path( __FILE__ ) );
define( 'BRIK82AD_URL', plugin_dir_url( __FILE__ ) );

/**
 * Admin tarafında çalışması gereken dosyaları yükler
 */
function brik82ad_init_admin() {
    // Sadece admin tarafında çalışsın
    if ( ! is_admin() ) {
        return;
    }

    // Front-end (aslında admin tarafında da kullanılabilecek) dosyalar
    require_once BRIK82AD_PATH . 'front-end/navigation/brik82ad-navigation.php';
    require_once BRIK82AD_PATH . 'front-end/search/brik82ad-search.php';
    require_once BRIK82AD_PATH . 'front-end/orders/brik82ad-orders.php';

    // Back-end dosyalar
    require_once BRIK82AD_PATH . 'back-end/total-sales/brik82ad-total-sales.php';
    require_once BRIK82AD_PATH . 'back-end/order-value/brik82ad-order-value.php';
    // Back-end dosyalar

    if ( is_admin() && isset($GLOBALS['pagenow']) && $GLOBALS['pagenow'] === 'index.php' ) {
        require_once BRIK82AD_PATH . 'back-end/live/brik82ad-live.php'; // Kapalı tutulmuş
        require_once BRIK82AD_PATH . 'back-end/conversion-count/brik82ad-conversion-count.php';
        require_once BRIK82AD_PATH . 'back-end/most-count/most-add-to-cart/brik82ad-most-add-to-cart.php';
        require_once BRIK82AD_PATH . 'back-end/most-count/most-sale/brik82ad-most-sale.php';
        require_once BRIK82AD_PATH . 'back-end/most-count/most-view/brik82ad-most-view.php';
    }

}
add_action('init', 'brik82ad_init_admin');


/**
 * Diğer tarafta (front-end veya genel) çalışması gereken dosyaları yükler
 */
function brik82ad_init_other() {
    // Front-end dosyalar
    require_once BRIK82AD_PATH . 'front-end/brik82ad-site-submenu.php';
    require_once BRIK82AD_PATH . 'front-end/sound/brik82ad-sound.php';

}
add_action('init', 'brik82ad_init_other');


/**
 * Admin panelde genel olarak ihtiyaç duyulan CSS ve JS dosyalarını enqueue eder
 */

/**
 * BrikPanel: Sadece Admin Paneli Dashboard'da script'leri enqueue eder.
 */
function brik82ad_enqueue_dashboard_scripts( $hook ) {
    if ( 'index.php' !== $hook ) {
        return;
    }

    
    // Total Sales
    wp_enqueue_script(
        'brik82ad_total_sales_scripts',
        BRIK82AD_URL . 'back-end/total-sales/brik82ad-total-sales.js',
        [],
        '1.3.2',
        true
    );
    wp_localize_script('brik82ad_total_sales_scripts', 'brik82adData', array(
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce'    => wp_create_nonce('brik82ad_nonce')
    ));

    // Order Value
    wp_enqueue_script(
        'brik82ad_order_value_scripts',
        BRIK82AD_URL . 'back-end/order-value/brik82ad-order-value.js',
        [],
        '1.3.2',
        true
    );
    wp_localize_script('brik82ad_order_value_scripts', 'brik82adData', array(
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce'    => wp_create_nonce('brik82ad_nonce')
    ));
    
}
add_action( 'admin_enqueue_scripts', 'brik82ad_enqueue_dashboard_scripts' );

function brik82ad_enqueue_files_all() {

    // Search
    wp_enqueue_script(
        'brik82ad_search_scripts',
        BRIK82AD_URL . 'front-end/search/brik82ad-search.js',
        [],
        '1.3.2',
        true
    );
    wp_localize_script('brik82ad_search_scripts', 'brik82adSearchData', array(
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce'    => wp_create_nonce('brik82ad_nonce')
    ));


    wp_enqueue_script(
        'brik82ad_ding_scripts',
        BRIK82AD_URL . 'front-end/sound/brik82ad-sound.js',
        [],
        '1.3.2',
        true
    );

    // CSS dosyaları
    wp_enqueue_style(
        'brik82ad_navigation_styles',
        BRIK82AD_URL . 'front-end/navigation/brik82ad-navigation.css',
        [],
        '1.3.2'
    );

    wp_enqueue_style(
        'brik82ad_search_styles',
        BRIK82AD_URL . 'front-end/search/brik82ad-search.css',
        [],
        '1.3.2'
    );

    wp_enqueue_style(
        'brik82ad_back_end_styles',
        BRIK82AD_URL . 'back-end/brik82ad-back-end.css',
        [],
        '1.3.2'
    );
}
add_action('admin_enqueue_scripts', 'brik82ad_enqueue_files_all');

/**
 * Sadece belirli WooCommerce yönetim sayfalarında ihtiyaç duyulan dosyaları enqueue eder
 *
 * @param string $hook WordPress ekran kimliği
 */
function brik82ad_enqueue_files_justwoo( $hook ) {

    $is_hpos_orders = ($hook === 'woocommerce_page_wc-orders');
    // Klasik sipariş ekranı (edit.php?post_type=shop_order)
    $is_legacy_orders = (isset($_GET['post_type']) && $_GET['post_type'] === 'shop_order' && $hook === 'edit.php');
    // NOT: $hook bazen "edit.php" döner!

    if ( $is_hpos_orders || $is_legacy_orders ) {
        wp_enqueue_script(
            'brik82ad_orders_scripts',
            BRIK82AD_URL . 'front-end/orders/brik82ad-orders.js',
            ['jquery', 'wc-enhanced-select'],
            '1.3.2',
            true
        );
        wp_enqueue_style(
            'brik82ad_orders_styles',
            BRIK82AD_URL . 'front-end/orders/brik82ad-orders.css',
            [],
            '1.3.2'
        );
    }


    // Ürünler sayfasında
    if ( 'edit.php' === $hook && isset($_GET['post_type']) && 'product' === $_GET['post_type'] ) {
        wp_enqueue_style(
            'brik82ad_products_styles',
            BRIK82AD_URL . 'front-end/products/brik82ad-products.css',
            [],
            '1.3.2'
        );
    }

    // Navigation için js
    wp_enqueue_script(
        'brik82ad_navigation_scripts',
        BRIK82AD_URL . 'front-end/navigation/brik82ad-navigation.js',
        [],
        '1.3.2',
        true
    );

    // flatpickr için css
    wp_enqueue_style(
        'brik82ad_flatpickr_styles',
        BRIK82AD_URL . 'back-end/flatpickr/flatpickr.min.css',
        [],
        '1.3.2'
    );

    // flatpickr için js
    wp_enqueue_script(
        'brik82ad_flatpickr_scripts',
        BRIK82AD_URL . 'back-end/flatpickr/flatpickr.js',
        [],
        '1.3.2',
        true
    );
}
add_action('admin_enqueue_scripts', 'brik82ad_enqueue_files_justwoo');

// İlk etkinleşmede metaboxların dizilimi
function brik82ad_plugin_activate() {
    // 1) Görünür olmasını istediğiniz metabox ID'leri
    $allowed_boxes = array(
        'brik82ad_metabox_visitor_count',
        'brik82ad_metabox_live_visitors',
        'brik82ad_metabox_most_add_to_cart',
        'brik82ad_metabox_most_sale',
        'brik82ad_metabox_most_view',
        'brik82ad_order_value_metabox',
        'brik82ad_total_sales_metabox',
    );

    // 2) Gizlemek istediğiniz tüm bilinen/kullanılan Dashboard widget ID’lerini listeleyin
    //    (WordPress çekirdek + WooCommerce vb. eklentiler)
    $known_wp_boxes = array(
        // WordPress Core
        'dashboard_right_now',
        'dashboard_activity',
        'dashboard_quick_press',
        'dashboard_recent_drafts',
        'dashboard_primary',
        'dashboard_site_health',
        'dashboard_recent_comments',
    
        // WooCommerce
        'wc_admin_dashboard_setup',
        'woocommerce_dashboard_status',
        'woocommerce_dashboard_recent_reviews',
    
        // SEO Eklentileri
        'yith_dashboard_products_news', // YITH WooCommerce
        'yith_dashboard_blog_news',   // YITH WooCommerce
        'wpseo-dashboard-overview',                 // Yoast SEO
        'wpseo-wincher-dashboard-overview' ,        // Yoast SEO
        'rank_math_dashboard_widget',     // Rank Math SEO
        'aioseo-rss-feed',        // All in One SEO Pack
        'aioseo-overview',       // All in One SEO Pack
        'aioseo-seo-setup', // All in One SEO Pack
        'vg_sheet_editor_usage_stats', // WP Sheet Editor
        // Güvenlik Eklentileri
        'itsec_dashboard_widget',         // iThemes Security
        'wordfence_activity_report_widget', // Wordfence Security
    
        // Performans & Önbellek Eklentileri
        'w3tc_dashboard_widget',          // W3 Total Cache
        'sg_cachepress_dashboard_widget', // SiteGround Optimizer
        'wp_rocket_dashboard_widget',     // WP Rocket
        'litespeed_dashboard_widget',     // LiteSpeed Cache
    
        // Sayfa Oluşturucular
        'e-dashboard-overview',   // Elementor
        'siteorigin_panels_overview_widget', // SiteOrigin Page Builder
    
        // Backup & Migration
        'updraft_dashboard_widget',       // UpdraftPlus
        'duplicator_news_widget',         // Duplicator
    
        // Güvenlik & Bakım
        'mainwp_child_dashboard_widget',  // MainWP
        'wpvivid_backup_widget',          // WPvivid Backup
    
        // İstatistik / Analytics
        'monsterinsights_reports_widget',  // MonsterInsights (Google Analytics)
        'exactmetrics_reports_widget',       // ExactMetrics
        'jetpack_dashboard_widget',          // Jetpack
    
        // E-Posta / Pazarlama
        'mc4wp_news_widget',             // Mailchimp for WP
        'newsletter_widget',            // Newsletter Plugin
    
        // Form Eklentileri
        'wpforms_reports_widget_lite',       // WPForms
        'gravityforms_dashboard_widget',  // Gravity Forms
        'ninja_forms_dashboard_widget',   // Ninja Forms
        'forminator_dashboard_widget',    // Forminator
        'wp_mail_smtp_reports_widget_lite', // WP Mail SMTP
    
        // Çeviri
        'wpml_dashboard_widget',          // WPML
        'polylang_dashboard_widget',      // Polylang
    
        // Diğer Popüler Eklentiler
        'wordfence_dashboard_widget',     // Wordfence
        'shortpixel_dashboard_widget',    // ShortPixel
        'aioseo_news_widget',             // All in One SEO News
        'bbpress_dashboard_widget',       // bbPress
        'buddyboss_dashboard_widget',     // BuddyBoss
        'give_dashboard_widget',          // GiveWP (bağış eklentisi)
    );
    

    // 3) Gizlenecek metabox listesi = ($known_wp_boxes) - ($allowed_boxes)
    $hidden = array_diff($known_wp_boxes, $allowed_boxes);

    // 4) Tüm kullanıcıların Dashboard meta-box ayarlarını güncelleyelim
    $users = get_users();
    foreach ($users as $user) {
        update_user_option($user->ID, 'metaboxhidden_dashboard', array_values($hidden), true);
    }
}
register_activation_hook(__FILE__, 'brik82ad_plugin_activate');

