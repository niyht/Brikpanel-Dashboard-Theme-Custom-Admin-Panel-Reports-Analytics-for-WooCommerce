<?php
/**
 * Plugin Name: BrikPanel: WooCommerce Admin Dashboard Theme
 * Description: Beautiful and modern admin panel dashboard for WooCommerce plugin premium version.
 * Version: 2.7.2
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
define('BRIKPANEL_VERSION', '2.7.2');
define('BRIKPANEL_PATH', plugin_dir_path(__FILE__));
define('BRIKPANEL_URL', plugin_dir_url(__FILE__));
define('BRIKPANEL_BASENAME', plugin_basename(__FILE__));

// =============================================================================
// SEO PLUGIN COMPATIBILITY BOOTSTRAP (must run before plugins_loaded listeners)
// =============================================================================
/**
 * When the current request targets our simplified product editor page,
 * masquerade as the native `/wp-admin/post.php?post=X&action=edit` flow so
 * third-party SEO plugins (Yoast, Rank Math, AIOSEO, SEOPress) bootstrap
 * their metabox / Screen classes with the correct post + screen context.
 *
 * Rank Math evaluates `$pagenow === 'post.php'` synchronously inside its
 * Metabox bootstrap on `admin_init`; spoofing later (e.g. in our own
 * admin_enqueue_scripts callback) is too late. We override the bare minimum
 * — only on our page — so other admin screens stay untouched.
 *
 * Runs at plugins_loaded priority 0, before any plugin has registered its
 * own listeners, but late enough for $_GET / $_POST to be populated.
 */
add_action('plugins_loaded', function () {
    if (!is_admin()) {
        return;
    }
    $page   = isset($_GET['page']) ? sanitize_key($_GET['page']) : '';
    $action = isset($_POST['action']) ? sanitize_key($_POST['action']) : '';
    $is_editor_page = ($page === 'brikpanel-product-editor');
    $is_editor_save = (defined('DOING_AJAX') && DOING_AJAX && $action === 'brikpanel_save_product');
    if (!$is_editor_page && !$is_editor_save) {
        return;
    }

    // SEO plugins gate their metabox registration on $pagenow === 'post.php'.
    $GLOBALS['pagenow'] = 'post.php';

    // Yoast SEO — its should_load_meta_boxes() filter needs to be true.
    add_filter('wpseo_always_register_metaboxes_on_admin', '__return_true');

    if ($is_editor_page) {
        $product_id = isset($_GET['product_id']) ? absint($_GET['product_id']) : 0;
        if ($product_id) {
            $_GET['post']       = $product_id;
            $_REQUEST['post']   = $product_id;
            $_GET['action']     = 'edit';
            $_REQUEST['action'] = 'edit';
            $_GET['post_type']  = 'product';
        }
    }

    if ($is_editor_save && !empty($_POST['product_id'])) {
        $pid = absint($_POST['product_id']);
        // Yoast's save_postdata() bails if $_POST['ID'] !== $post_id.
        $_POST['ID']        = $pid;
        $_REQUEST['ID']     = $pid;
        $_POST['post_ID']   = $pid;
        $_POST['post_type'] = 'product';
    }
}, 0);

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
    require_once BRIKPANEL_PATH . 'includes/brikpanel-cache-clear.php';
    require_once BRIKPANEL_PATH . 'front-end/dashboard/brikpanel-dashboard.php';
    require_once BRIKPANEL_PATH . 'front-end/dashboard/brikpanel-dashboard-topbar.php';
    if ( get_option( 'brikpanel_modern_navigation', 'yes' ) !== 'no' ) {
        require_once BRIKPANEL_PATH . 'front-end/navigation/brikpanel-navigation.php';
    }
    // Sidebar customizer (settings UI + render-time application). Loaded even
    // when modern navigation is off so admins can pre-configure the layout
    // before flipping the toggle.
    require_once BRIKPANEL_PATH . 'front-end/navigation/brikpanel-nav-customizer.php';
    require_once BRIKPANEL_PATH . 'front-end/search/brikpanel-search.php';
    require_once BRIKPANEL_PATH . 'front-end/orders/brikpanel-orders.php';
    require_once BRIKPANEL_PATH . 'front-end/orders/brikpanel-orders-stats.php';
    require_once BRIKPANEL_PATH . 'front-end/products/brikpanel-product-editor.php';
    require_once BRIKPANEL_PATH . 'front-end/products/brikpanel-products-list.php';
    require_once BRIKPANEL_PATH . 'front-end/products/brikpanel-category-enhancements.php';
    require_once BRIKPANEL_PATH . 'front-end/coupons/brikpanel-coupons.php';
    require_once BRIKPANEL_PATH . 'front-end/segments/brikpanel-segments.php';
    require_once BRIKPANEL_PATH . 'front-end/customer-analytics/brikpanel-customer-analytics.php';
    require_once BRIKPANEL_PATH . 'front-end/expenses/brikpanel-expenses.php';

    // Back-end files
    require_once BRIKPANEL_PATH . 'back-end/total-sales/brikpanel-total-sales.php';
    require_once BRIKPANEL_PATH . 'back-end/conversion-count/brikpanel-total-orders.php';
    require_once BRIKPANEL_PATH . 'back-end/order-value/brikpanel-order-value.php';
    require_once BRIKPANEL_PATH . 'back-end/order-rates/brikpanel-order-rates.php';
}
add_action('init', 'brikpanel_init_admin');

// =============================================================================
// SUPPRESS THIRD-PARTY ADMIN NOTICES (opt-out via settings)
// =============================================================================
/**
 * Hide admin notices from other plugins/themes while keeping BrikPanel's own
 * notices visible. Controlled by the `brikpanel_hide_foreign_notices` option,
 * defaulting to enabled.
 *
 * Implementation: captures the admin_notices / all_admin_notices output in
 * an output buffer, then filters out anything whose rendered markup does not
 * include the `brikpanel-notice` class. BrikPanel must mark its own notices
 * with that class to stay visible.
 *
 * Additionally injects CSS to hide any leftover notice markup that bypasses
 * the output-buffer hook (e.g. notices rendered via admin_head or printed
 * inside .wrap after the hook closes).
 */
function brikpanel_suppress_foreign_notices() {
    if (!is_admin()) {
        return;
    }
    if (get_option('brikpanel_hide_foreign_notices', 'yes') !== 'yes') {
        return;
    }
    // Notices *are* suppressed on our own WC settings tab too. The tab
    // replaces WooCommerce's generic "Your settings have been saved" with
    // a branded `.brikpanel-notice` variant (registered below) so the
    // admin still gets save confirmation without seeing every other
    // plugin's marketing banners bleed through.

    $capture = function () {
        ob_start();
    };
    $flush = function () {
        $html = ob_get_clean();
        if ($html === false || $html === '') {
            return;
        }
        // Remove foreign notice blocks while preserving correct DOM nesting.
        // The old .*?</div> regex broke when notices contained nested <div>
        // elements (e.g. wp-fail2ban promotional banners) — it captured only
        // up to the first inner </div>, leaving orphaned closing tags that
        // collapsed parent containers (#wpbody-content, #wpbody, #wpcontent)
        // and pushed the page .wrap out of the normal hierarchy.
        //
        // New approach: find each notice opening tag, then count nested
        // <div>…</div> pairs to locate the *matching* closing tag before
        // deciding whether to strip the entire block.
        $opener = '#<div\b[^>]*class="[^"]*\b(?:notice|updated|error)\b[^"]*"[^>]*>#is';
        $offset = 0;
        $out = '';
        while (preg_match($opener, $html, $m, PREG_OFFSET_CAPTURE, $offset)) {
            $start = $m[0][1];
            // Emit everything before this notice as-is.
            $out .= substr($html, $offset, $start - $offset);

            // Walk forward from the end of the opening tag, counting nested
            // <div> opens against </div> closes until we reach depth 0.
            $pos   = $start + strlen($m[0][0]);
            $depth = 1;
            while ($depth > 0 && preg_match('#<(/?)div\b[^>]*>#i', $html, $tag, PREG_OFFSET_CAPTURE, $pos)) {
                $pos = $tag[0][1] + strlen($tag[0][0]);
                if ($tag[1][0] === '/') {
                    $depth--;
                } else {
                    $depth++;
                }
            }
            // $pos is now right after the matching </div>.
            $block = substr($html, $start, $pos - $start);

            // BrikPanel's own notices stay visible.
            if (strpos($block, 'brikpanel-notice') !== false) {
                $out .= $block;
            }
            // Everything else is silently dropped.

            $offset = $pos;
        }
        // Append any remaining content after the last notice.
        $out .= substr($html, $offset);
        echo $out;
    };

    foreach (['admin_notices', 'all_admin_notices', 'user_admin_notices', 'network_admin_notices'] as $hook) {
        add_action($hook, $capture, -PHP_INT_MAX);
        add_action($hook, $flush, PHP_INT_MAX);
    }

    // Belt and suspenders: any notice markup that slips past the output
    // buffer (e.g. printed inside .wrap after the hook) is hidden via CSS.
    add_action('admin_head', function () {
        echo '<style>
            .wp-admin #wpbody-content > .notice:not(.brikpanel-notice),
            .wp-admin #wpbody-content > .updated:not(.brikpanel-notice),
            .wp-admin #wpbody-content > .error:not(.brikpanel-notice),
            .wp-admin .wrap > .notice:not(.brikpanel-notice):not(.inline):not(.below-h2),
            .wp-admin .wrap > .updated:not(.brikpanel-notice):not(.inline):not(.below-h2),
            .wp-admin .wrap > .error:not(.brikpanel-notice):not(.inline):not(.below-h2) {
                display: none !important;
            }
        </style>';
    }, 9999);
}
add_action('admin_init', 'brikpanel_suppress_foreign_notices');

// =============================================================================
// LOGIN PAGE CUSTOMIZATION
// =============================================================================
require_once BRIKPANEL_PATH . 'front-end/login/brikpanel-login.php';

// =============================================================================
// APPEARANCE CUSTOMIZATION (font + accent color)
// Loaded globally so the override CSS applies to both wp-admin and the
// modern login page. Registers its own hooks (settings field, admin_head,
// login_head, admin_enqueue_scripts).
// =============================================================================
require_once BRIKPANEL_PATH . 'front-end/appearance/brikpanel-appearance.php';

// =============================================================================
// FRONT-END & GENERAL FILES
// =============================================================================
function brikpanel_init_other() {
    require_once BRIKPANEL_PATH . 'front-end/products/brikpanel-variation-gallery.php';
    if ( get_option( 'brikpanel_modern_navigation', 'yes' ) !== 'no' ) {
        require_once BRIKPANEL_PATH . 'front-end/brikpanel-site-submenu.php';
    }
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
// THIRD-PARTY COMPATIBILITY: ASE (Admin and Site Enhancements) bridge
// =============================================================================
require_once BRIKPANEL_PATH . 'includes/brikpanel-ase-bridge.php';

// =============================================================================
// ENQUEUE SCRIPTS & STYLES
// =============================================================================
require_once BRIKPANEL_PATH . 'includes/brikpanel-enqueue.php';

// =============================================================================
// DEVELOPER HOOKS API (public actions/filters + docs modal)
// =============================================================================
require_once BRIKPANEL_PATH . 'includes/brikpanel-hooks-api.php';

// =============================================================================
// REVIEW REQUEST NOTICES (14 days / 50 completed orders)
// =============================================================================
require_once BRIKPANEL_PATH . 'includes/brikpanel-review-notices.php';

// =============================================================================
// CRON / BACKGROUND JOBS (Action Scheduler wrapper + admin page)
// =============================================================================
require_once BRIKPANEL_PATH . 'includes/cron/brikpanel-cron.php';
require_once BRIKPANEL_PATH . 'includes/cron/customer-analytics-jobs.php';

// =============================================================================
// BRIKCONTROL — Store Health panel (loaded outside the is_admin gate so the
// Action Scheduler worker can resolve registered handlers when running over
// WP-Cron / CLI). Admin menu + AJAX hooks self-gate to admin context.
// =============================================================================
require_once BRIKPANEL_PATH . 'front-end/brikcontrol/brikpanel-brikcontrol.php';

// =============================================================================
// STORE SUMMARY (on-demand Markdown digest, triggered from dashboard "Copy
// everything" button — no cron, generated on click only)
// =============================================================================
if ( is_admin() ) {
    require_once BRIKPANEL_PATH . 'includes/brikpanel-store-summary.php';
}

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
        mobile_count INT DEFAULT 0,
        tablet_count INT DEFAULT 0,
        desktop_count INT DEFAULT 0,
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

    $expenses_table = $wpdb->prefix . "brikpanel_expenses";
    $sql_expenses = "CREATE TABLE $expenses_table (
        id BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        expense_date DATE NOT NULL,
        category VARCHAR(100) NOT NULL DEFAULT '',
        description TEXT,
        amount DECIMAL(20,4) NOT NULL DEFAULT 0,
        recurring VARCHAR(20) NOT NULL DEFAULT 'none',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_date (expense_date),
        KEY idx_category (category)
    ) $charset_collate;";

    // Cohort retention — monthly cohort × period_offset matrix (populated nightly)
    $cohort_table = $wpdb->prefix . "brikpanel_cohort_retention";
    $sql_cohort = "CREATE TABLE $cohort_table (
        id BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        cohort_month DATE NOT NULL,
        period_offset TINYINT UNSIGNED NOT NULL,
        cohort_size INT UNSIGNED NOT NULL DEFAULT 0,
        retained_customers INT UNSIGNED NOT NULL DEFAULT 0,
        retention_rate DECIMAL(5,2) NOT NULL DEFAULT 0,
        computed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_cohort_period (cohort_month, period_offset),
        KEY idx_cohort_month (cohort_month)
    ) $charset_collate;";

    // Customer metrics — precomputed per-customer LTV + RFM (populated nightly by Action Scheduler)
    $customer_metrics_table = $wpdb->prefix . "brikpanel_customer_metrics";
    $sql_customer_metrics = "CREATE TABLE $customer_metrics_table (
        id BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_key VARCHAR(191) NOT NULL,
        user_id BIGINT(20) UNSIGNED DEFAULT 0,
        customer_email VARCHAR(190) NOT NULL DEFAULT '',
        first_order_date DATETIME NULL DEFAULT NULL,
        last_order_date DATETIME NULL DEFAULT NULL,
        order_count INT UNSIGNED NOT NULL DEFAULT 0,
        total_spent DECIMAL(20,4) NOT NULL DEFAULT 0,
        aov DECIMAL(20,4) NOT NULL DEFAULT 0,
        recency_days INT UNSIGNED NULL DEFAULT NULL,
        r_score TINYINT UNSIGNED NOT NULL DEFAULT 0,
        f_score TINYINT UNSIGNED NOT NULL DEFAULT 0,
        m_score TINYINT UNSIGNED NOT NULL DEFAULT 0,
        rfm_segment VARCHAR(40) NULL DEFAULT NULL,
        computed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_customer_key (customer_key),
        KEY idx_user_id (user_id),
        KEY idx_total_spent (total_spent),
        KEY idx_rfm_segment (rfm_segment),
        KEY idx_last_order (last_order_date)
    ) $charset_collate;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql_visitors);
    dbDelta($sql_cart_tracking);
    dbDelta($sql_visited_pages);
    dbDelta($sql_expenses);
    dbDelta($sql_customer_metrics);
    dbDelta($sql_cohort);
}
register_activation_hook(__FILE__, 'brikpanel_create_table');

/**
 * Enable WooCommerce's "Cost of Goods Sold" feature by default on activation.
 *
 * COGS ships disabled in WooCommerce (enabled_by_default => false). BrikPanel
 * surfaces COGS data throughout its analytics, so we flip the flag to 'yes' on
 * first activation. A one-shot marker (brikpanel_cogs_default_applied) ensures
 * we only apply this default once — if the user later disables COGS in WC
 * settings, we won't re-enable it on reactivation.
 */
function brikpanel_enable_cogs_default() {
    if (get_option('brikpanel_cogs_default_applied') === 'yes') {
        return;
    }
    update_option('woocommerce_feature_cost_of_goods_sold_enabled', 'yes');
    update_option('brikpanel_cogs_default_applied', 'yes');
}
register_activation_hook(__FILE__, 'brikpanel_enable_cogs_default');

/**
 * Safety net: also run the COGS default once on `plugins_loaded` so existing
 * BrikPanel installs (upgraded from a version that pre-dates this default)
 * pick up the change without requiring a manual deactivate/reactivate. The
 * one-shot flag still gates this — runs exactly once per site.
 */
add_action('plugins_loaded', 'brikpanel_enable_cogs_default', 20);

/**
 * Run table creation on plugin upgrade so existing installs pick up new
 * tables without requiring a manual deactivate/reactivate. dbDelta is
 * idempotent — re-running on every version bump only emits ALTERs when
 * the schema actually drifted.
 *
 * On version transitions that introduce the customer_metrics table, also
 * enqueue an async Action Scheduler job so the user sees populated
 * analytics immediately rather than having to wait for the nightly cron.
 */
function brikpanel_maybe_upgrade_db() {
    $stored = get_option('brikpanel_db_version');
    if ($stored === BRIKPANEL_VERSION) {
        return;
    }
    brikpanel_create_table();
    update_option('brikpanel_db_version', BRIKPANEL_VERSION);

    // Trigger an immediate first computation of customer metrics + cohort
    // retention. Both handlers are idempotent (UPSERT keyed on unique cols),
    // so kicking them off async is safe even if the recurring jobs also
    // run soon after.
    if (class_exists('Brikpanel_Cron')) {
        add_action('init', function () {
            if (Brikpanel_Cron::is_available()) {
                Brikpanel_Cron::enqueue_async('brikpanel_recompute_customer_metrics', [], ['unique' => true]);
                Brikpanel_Cron::enqueue_async('brikpanel_recompute_cohort_retention', [], ['unique' => true]);
            }
        }, 25);
    }
}
add_action('plugins_loaded', 'brikpanel_maybe_upgrade_db', 5);


// =============================================================================
// PLUGIN ACTION LINKS — add "Settings" next to the Deactivate link
// =============================================================================
add_filter('plugin_action_links_' . BRIKPANEL_BASENAME, function ($links) {
    $settings_url = admin_url('admin.php?page=wc-settings&tab=brikpanel');
    $settings_link = '<a href="' . esc_url($settings_url) . '">' . esc_html__('Settings', 'brikpanel') . '</a>';
    $links[] = $settings_link;
    return $links;
});

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
