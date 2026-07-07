<?php
/**
 * BrikPanel - Asset Enqueue
 * 
 * Handles all script and style enqueueing
 * 
 * @package BrikPanel
 * @since 1.4.6
 */

if (!defined('ABSPATH')) {
    exit;
}


/**
 * Inline CSS that realigns the flatpickr calendar header (month dropdown +
 * year input). WordPress admin applies global box-model rules to every
 * <input>/<select> (min-height, line-height, padding, box-shadow), which pushes
 * flatpickr's year number below the month label. We center both controls and
 * neutralize that interference. Attached wherever flatpickr styles are enqueued.
 *
 * @return string
 */
function brikpanel_flatpickr_header_align_css() {
    return <<<CSS
.flatpickr-calendar .flatpickr-current-month{display:flex;align-items:center;justify-content:center;gap:.25ch;padding-top:0;height:34px;}
.flatpickr-calendar .flatpickr-current-month .flatpickr-monthDropdown-months,
.flatpickr-calendar .flatpickr-current-month .numInputWrapper,
.flatpickr-calendar .flatpickr-current-month input.cur-year{min-height:0;height:auto;line-height:1;margin:0;padding-top:0;padding-bottom:0;box-shadow:none;vertical-align:middle;}
.flatpickr-calendar .flatpickr-current-month .numInputWrapper{display:inline-flex;align-items:center;}
CSS;
}

// =============================================================================
// CUSTOM DASHBOARD PAGE ASSETS
// =============================================================================
function brikpanel_enqueue_custom_dashboard_assets($hook) {
    if ('admin_page_brikpanel-dashboard' !== $hook) {
        return;
    }

    // Embedded WP dashboard widget dependencies — only enqueued if the user
    // has selected any widgets, so we don't pay the cost on every load.
    // WP core dashboard.js + site-health.js target widgets by id selector
    // (e.g. `#dashboard_primary div.inside`) and use the JS global `pagenow`
    // for AJAX refresh calls. On our custom admin page `pagenow` resolves to
    // `admin_page_brikpanel-dashboard`, which server-side ajax handlers
    // reject. We override it to the string `dashboard` **before** dashboard.js
    // runs so ajaxPopulateWidgets() / quickPressLoad() / site-health init all
    // work unchanged.
    $embedded_widgets = (array) get_option('brikpanel_dashboard_wp_widgets', []);
    if (!empty($embedded_widgets)) {
        wp_enqueue_style('dashboard');
        wp_enqueue_style('site-health');
        wp_enqueue_script('common');
        wp_enqueue_script('postbox');
        wp_enqueue_script('dashboard');
        wp_enqueue_script('site-health');
        wp_enqueue_script('wp-a11y');

        // Override pagenow before dashboard.js init fires. dashboard.js reads
        // the global at jQuery(function($){...}) time, so we set it in a
        // `before` inline script attached to the `dashboard` handle.
        wp_add_inline_script(
            'dashboard',
            'window.pagenow = "dashboard"; window.adminpage = "index-php";',
            'before'
        );

        // WP_Site_Health::enqueue_scripts() short-circuits when the current
        // screen is not `dashboard` / `site-health`, so the `SiteHealth` JS
        // global is never localized on our custom page. Replicate the minimal
        // localization so site-health.js can read cached issue counts from
        // the transient and update the progress ring.
        $site_status_counts = array(
            'good'        => 0,
            'recommended' => 0,
            'critical'    => 0,
        );
        $cached_counts = get_transient('health-check-site-status-result');
        if (false !== $cached_counts) {
            $decoded = json_decode($cached_counts, true);
            if (is_array($decoded)) {
                $site_status_counts = array_merge($site_status_counts, $decoded);
            }
        }
        wp_localize_script('site-health', 'SiteHealth', array(
            'screen' => 'dashboard',
            'nonce'  => array(
                'site_status'        => wp_create_nonce('health-check-site-status'),
                'site_status_result' => wp_create_nonce('health-check-site-status-result'),
            ),
            'site_status' => array(
                'direct' => array(),
                'async'  => array(),
                'issues' => $site_status_counts,
            ),
        ));

        // wp_localize_community_events() is only hooked to
        // `admin_print_scripts-index.php`, so on our custom admin page the
        // `communityEventsData` JS global (with the community_events nonce)
        // is never set. Without it, dashboard.js hits the REST endpoint with
        // no nonce → 403. Call the helper directly; it no-ops unless the
        // `dashboard` script is enqueued, which we just did above.
        if (function_exists('wp_localize_community_events')) {
            wp_localize_community_events();
        }
    }

    // Flatpickr
    wp_enqueue_style(
        'brikpanel_flatpickr_styles',
        BRIKPANEL_URL . 'assets/css/flatpickr.min.css',
        [],
        BRIKPANEL_VERSION
    );
    wp_add_inline_style( 'brikpanel_flatpickr_styles', brikpanel_flatpickr_header_align_css() );

    wp_enqueue_script(
        'flatpickr-js',
        BRIKPANEL_URL . 'assets/js/flatpickr.js',
        [],
        BRIKPANEL_VERSION,
        true
    );

    // Chart.js
    wp_enqueue_script(
        'chart-js',
        BRIKPANEL_URL . 'assets/js/chart.js',
        [],
        BRIKPANEL_VERSION,
        true
    );

    // Cobe (interactive globe)
    wp_enqueue_script(
        'cobe-globe',
        BRIKPANEL_URL . 'assets/js/cobe.js',
        [],
        BRIKPANEL_VERSION,
        true
    );

    // Dashboard styles (filemtime version → edits bust the browser cache)
    $dash_css_ver = @filemtime( BRIKPANEL_PATH . 'front-end/dashboard/brikpanel-dashboard.css' ) ?: BRIKPANEL_VERSION;
    $dash_js_ver  = @filemtime( BRIKPANEL_PATH . 'front-end/dashboard/brikpanel-dashboard.js' ) ?: BRIKPANEL_VERSION;
    wp_enqueue_style(
        'brikpanel_dashboard_styles',
        BRIKPANEL_URL . 'front-end/dashboard/brikpanel-dashboard.css',
        [],
        $dash_css_ver
    );

    // Dashboard scripts
    wp_enqueue_script(
        'brikpanel_dashboard_scripts',
        BRIKPANEL_URL . 'front-end/dashboard/brikpanel-dashboard.js',
        [ 'flatpickr-js', 'chart-js', 'cobe-globe' ],
        $dash_js_ver,
        true
    );

    // Localization data for legacy backend chart JS files (conversion-count, order-rates, etc.)
    wp_localize_script('brikpanel_dashboard_scripts', 'brikpanelConversionCount', [
        'i18n' => [
            'visitor'         => __('Visitor', 'brikpanel'),
            'product'         => __('Product', 'brikpanel'),
            'add_to_cart'     => __('Add to Cart', 'brikpanel'),
            'checkout'        => __('Checkout', 'brikpanel'),
            'order'           => __('Order', 'brikpanel'),
            'customers'       => __('Customers', 'brikpanel'),
            'calculating'     => __('Calculating...', 'brikpanel'),
            'error'           => __('Error', 'brikpanel'),
            'conversion_rate' => __('Conversion Rate', 'brikpanel'),
            'select_date'     => __('Please select a valid custom date range.', 'brikpanel'),
        ],
    ]);

    wp_localize_script('brikpanel_dashboard_scripts', 'brikpanelOrderRates', [
        'i18n' => [
            'successful'      => __('Successful', 'brikpanel'),
            'failed'          => __('Failed', 'brikpanel'),
            'refunded'        => __('Refunded', 'brikpanel'),
            'cancelled'       => __('Cancelled', 'brikpanel'),
            'order_statuses'  => __('Order Statuses', 'brikpanel'),
            'of_total_orders' => __('% of total orders', 'brikpanel'),
        ],
    ]);

    wp_localize_script('brikpanel_dashboard_scripts', 'brikpanelMostAddtocart', [
        'i18n' => [
            'label'       => __('Add To Cart Count', 'brikpanel'),
            'select_date' => __('Please select a valid date range.', 'brikpanel'),
        ],
    ]);

    wp_localize_script('brikpanel_dashboard_scripts', 'brikpanelMostSale', [
        'i18n' => [
            'label'       => __('Total Sales Count', 'brikpanel'),
            'select_date' => __('Please select a valid date range.', 'brikpanel'),
        ],
    ]);

    wp_localize_script('brikpanel_dashboard_scripts', 'brikpanelMostView', [
        'i18n' => [
            'label'       => __('View Count', 'brikpanel'),
            'select_date' => __('Please select a valid date range.', 'brikpanel'),
        ],
    ]);

    wp_localize_script('brikpanel_dashboard_scripts', 'brikpanelDashboard', [
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce'    => wp_create_nonce('brikpanel_dashboard_nonce'),
        'export_url'   => admin_url('admin-post.php'),
        'export_nonce' => wp_create_nonce('brikpanel_dashboard_export'),
        'currency' => function_exists('get_woocommerce_currency_symbol') ? get_woocommerce_currency_symbol() : '$',
        // Quick "Add expense" from the Profit > Expenses card. Posts straight to
        // the Expenses module's own save endpoint so recurring entries are
        // materialised identically to the full Operational Expenses page.
        'expenses' => [
            'action' => 'brikpanel_expenses_save',
            'nonce'  => wp_create_nonce('brikpanel_expenses_nonce'),
        ],
        'i18n'     => [
            'revenue'       => __('Revenue', 'brikpanel'),
            'orders'        => __('Orders', 'brikpanel'),
            'visitors'      => __('Visitors', 'brikpanel'),
            'product_views' => __('Product Views', 'brikpanel'),
            'add_to_cart'   => __('Add to Cart', 'brikpanel'),
            'checkout'      => __('Checkout', 'brikpanel'),
            'successful'    => __('Successful', 'brikpanel'),
            'failed'        => __('Failed', 'brikpanel'),
            'refunded'      => __('Returns & Refunds', 'brikpanel'),
            'cancelled'     => __('Cancelled', 'brikpanel'),
            'no_orders'     => __('No orders', 'brikpanel'),
            'no_data'       => __('No data for this period', 'brikpanel'),
            'no_visitors'   => __('No active visitors', 'brikpanel'),
            'product'       => __('Product', 'brikpanel'),
            'qty_sold'      => __('Qty Sold', 'brikpanel'),
            'order'         => __('Order', 'brikpanel'),
            'customer'      => __('Customer', 'brikpanel'),
            'source'        => __('Source', 'brikpanel'),
            'status'        => __('Status', 'brikpanel'),
            'total'         => __('Total', 'brikpanel'),
            'country'       => __('Country', 'brikpanel'),
            'city'          => __('City', 'brikpanel'),
            'page'          => __('Page', 'brikpanel'),
            'views'         => __('Views', 'brikpanel'),
            'cart_count'    => __('Cart Adds', 'brikpanel'),
            'has_cart'       => __('Cart', 'brikpanel'),
            'browsing'       => __('Browsing', 'brikpanel'),
            'added_to_cart'  => __('Added to Cart', 'brikpanel'),
            'order_received' => __('Order Received', 'brikpanel'),
            'aov'            => __('AOV', 'brikpanel'),
            'category'       => __('Category', 'brikpanel'),
            'share'          => __('Share', 'brikpanel'),
            'vs_prev'        => __('vs. prev.', 'brikpanel'),
            'mp_of_total'    => __('of', 'brikpanel'),
            'mp_combined'    => __('combined revenue', 'brikpanel'),
            'mp_no_categories' => __('No category data — marketplace items must be linked to your WooCommerce catalog (by product or SKU) for category breakdown to appear.', 'brikpanel'),
            'device_desktop'   => __('Desktop', 'brikpanel'),
            'device_mobile'    => __('Mobile', 'brikpanel'),
            'device_tablet'    => __('Tablet', 'brikpanel'),
            'device_title_visitors' => __('Visitors by Device', 'brikpanel'),
            'device_title_orders'   => __('Orders by Device', 'brikpanel'),
            'src_title'        => __('Traffic Sources', 'brikpanel'),
            'src_direct'       => __('Direct', 'brikpanel'),
            'src_search'       => __('Organic Search', 'brikpanel'),
            'src_social'       => __('Social', 'brikpanel'),
            'src_referral'     => __('Referral', 'brikpanel'),
            'src_paid'         => __('Paid', 'brikpanel'),
            'src_email'        => __('Email', 'brikpanel'),
            'src_empty'        => __('No visits with a known source yet for this period.', 'brikpanel'),
            'src_no_referrers' => __('No external referrers yet for this period.', 'brikpanel'),
            'ctype_new'        => __('New customers', 'brikpanel'),
            'ctype_repeat'     => __('Repeat customers', 'brikpanel'),
            'all_stocked'      => __('All products are sufficiently stocked', 'brikpanel'),
            'return_rate'      => __('return & refund rate', 'brikpanel'),
            'returns_refunds'  => __('Returns & refunds', 'brikpanel'),
            'total_orders'     => __('Total orders', 'brikpanel'),
            'average_ltv'      => __('Average customer LTV', 'brikpanel'),
            'total_customers'  => __('Total customers', 'brikpanel'),
            'repeat_customers' => __('Repeat customers', 'brikpanel'),
            'total_lifetime_value' => __('Total lifetime value', 'brikpanel'),
            'top_customer_ltv' => __('Top customer LTV', 'brikpanel'),
            'ltv_empty'        => __('Customer metrics will appear after the nightly recompute.', 'brikpanel'),
            'sku'                  => __('SKU', 'brikpanel'),
            'stock'                => __('Remaining', 'brikpanel'),
            'total_subscriptions'  => __('total subscriptions', 'brikpanel'),
            'loc_orders'           => __('Orders', 'brikpanel'),
            'loc_customers'        => __('Customers', 'brikpanel'),
            'loc_order_locations'  => __('Order Locations', 'brikpanel'),
            'loc_cust_locations'   => __('Customer Locations', 'brikpanel'),
            'loc_top_countries_orders'    => __('Top Countries by Orders', 'brikpanel'),
            'loc_top_countries_customers' => __('Top Countries by Customers', 'brikpanel'),
            'loc_top_cities_orders'       => __('Top Cities by Orders', 'brikpanel'),
            'loc_top_cities_customers'    => __('Top Cities by Customers', 'brikpanel'),
            'customers'            => __('customers', 'brikpanel'),
            'summary_button'        => __('Copy everything', 'brikpanel'),
            'summary_collecting'    => __('Collecting data…', 'brikpanel'),
            'summary_copied'        => __('Copied to clipboard!', 'brikpanel'),
            'summary_failed'        => __('Failed — try again', 'brikpanel'),
            'profit_margin'         => __('margin', 'brikpanel'),
            'profit_loss'           => __('Loss', 'brikpanel'),
            'profit_cogs_hint'      => __('Set “Cost of goods” on products', 'brikpanel'),
            'profit_cogs_partial'   => __('cost missing on %d items — profit overstated', 'brikpanel'),
            'profit_cogs_missing_title' => __('Products without a cost', 'brikpanel'),
            'profit_cogs_missing_aria'  => __('%d products are missing a cost', 'brikpanel'),
            'profit_cogs_missing_unlinked' => __('no longer in catalog', 'brikpanel'),
            'profit_estimate_tip'   => __('%d sold items have no cost set. Add their “Cost of goods” so Net profit is accurate.', 'brikpanel'),
            'profit_revenue_note'   => __('Same as Total Sales', 'brikpanel'),
            'profit_revenue_net_note' => __('Net of returns', 'brikpanel'),
            'profit_net_revenue'    => __('Net revenue', 'brikpanel'),
            'profit_of_revenue'     => __('of revenue', 'brikpanel'),
            'exp_saving'            => __('Saving…', 'brikpanel'),
            'exp_saved'             => __('Expense added', 'brikpanel'),
            'exp_error'             => __('Could not save. Please try again.', 'brikpanel'),
            'exp_required'          => __('Enter an amount and a category.', 'brikpanel'),
            'delta_new'             => __('New', 'brikpanel'),
            'export_button'         => __('Export Excel', 'brikpanel'),
            'export_preparing'      => __('Preparing…', 'brikpanel'),
            'export_select_dates'   => __('Pick a custom date range first.', 'brikpanel'),
            'period_loading'        => __('Loading…', 'brikpanel'),
            'globe_alt'             => __('Order Locations Globe', 'brikpanel'),
        ],
    ]);
}
add_action('admin_enqueue_scripts', 'brikpanel_enqueue_custom_dashboard_assets');

// =============================================================================
// SEGMENTS PAGE ASSETS
// =============================================================================
function brikpanel_enqueue_segments_assets($hook) {
    // WP registers submenu pages of WooCommerce under the hook
    // "woocommerce_page_<slug>".
    if ($hook !== 'toplevel_page_brikpanel-segments') {
        return;
    }

    $seg_css_ver = @filemtime( BRIKPANEL_PATH . 'front-end/segments/brikpanel-segments.css' ) ?: BRIKPANEL_VERSION;
    $seg_js_ver  = @filemtime( BRIKPANEL_PATH . 'front-end/segments/brikpanel-segments.js' ) ?: BRIKPANEL_VERSION;

    wp_enqueue_style(
        'brikpanel_segments_styles',
        BRIKPANEL_URL . 'front-end/segments/brikpanel-segments.css',
        [],
        $seg_css_ver
    );

    wp_enqueue_script(
        'brikpanel_segments_scripts',
        BRIKPANEL_URL . 'front-end/segments/brikpanel-segments.js',
        [],
        $seg_js_ver,
        true
    );

    wp_localize_script('brikpanel_segments_scripts', 'brikpanelSegments', [
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce'    => wp_create_nonce('brikpanel_segments_nonce'),
        'currency' => function_exists('get_woocommerce_currency_symbol') ? get_woocommerce_currency_symbol() : '$',
        'i18n'     => [
            'error'              => __('Something went wrong.', 'brikpanel'),
            'no_results'         => __('No orders match these filters.', 'brikpanel'),
            'no_customers'       => __('No customers match these filters.', 'brikpanel'),
            'no_products'        => __('No products found.', 'brikpanel'),
            'guest'              => __('Guest', 'brikpanel'),
            'total_revenue'      => __('Total revenue', 'brikpanel'),
            'total_spent'        => __('Total spent', 'brikpanel'),
            'col_order'          => __('Order', 'brikpanel'),
            'col_date'           => __('Date', 'brikpanel'),
            'col_status'         => __('Status', 'brikpanel'),
            'col_customer'       => __('Customer', 'brikpanel'),
            'col_email'          => __('Email', 'brikpanel'),
            'col_phone'          => __('Phone', 'brikpanel'),
            'col_location'       => __('Location', 'brikpanel'),
            'col_payment'        => __('Payment', 'brikpanel'),
            'col_total'          => __('Total', 'brikpanel'),
            'col_registered'     => __('Registered', 'brikpanel'),
            'col_orders'         => __('Orders', 'brikpanel'),
            'col_spent'          => __('Total spent', 'brikpanel'),
            'col_aov'            => __('AOV', 'brikpanel'),
            'col_last_order'     => __('Last order', 'brikpanel'),
            'preset_all'           => __('All', 'brikpanel'),
            'preset_today'         => __('Today', 'brikpanel'),
            'preset_last7'         => __('Last 7 days', 'brikpanel'),
            'preset_last30'        => __('Last 30 days', 'brikpanel'),
            'preset_last90'        => __('Last 90 days', 'brikpanel'),
            'preset_completed'     => __('Completed', 'brikpanel'),
            'preset_processing'    => __('Processing', 'brikpanel'),
            'preset_pending'       => __('Pending payment', 'brikpanel'),
            'preset_on_hold'       => __('On hold', 'brikpanel'),
            'preset_refunded'      => __('Refunded', 'brikpanel'),
            'preset_cancelled'     => __('Cancelled', 'brikpanel'),
            'preset_returns'       => __('Returns', 'brikpanel'),
            'preset_free_shipping' => __('Free shipping', 'brikpanel'),
            'preset_paid_shipping' => __('Paid shipping', 'brikpanel'),
            'preset_high_value'    => __('High value', 'brikpanel'),
            'preset_vip'           => __('VIP (5+ orders)', 'brikpanel'),
            'preset_new_customers' => __('New (30 days)', 'brikpanel'),
            'preset_one_time'      => __('One-time buyers', 'brikpanel'),
            'preset_dormant'       => __('Dormant (90+ days)', 'brikpanel'),
            'preset_repeat'        => __('Repeat buyers', 'brikpanel'),
            'remove'               => __('Remove', 'brikpanel'),
        ],
    ]);
}
add_action('admin_enqueue_scripts', 'brikpanel_enqueue_segments_assets');

// =============================================================================
// CUSTOMER ANALYTICS PAGE ASSETS
// =============================================================================
function brikpanel_enqueue_customer_analytics_assets($hook) {
    if ($hook !== 'toplevel_page_brikpanel-customer-analytics') {
        return;
    }

    // Chart.js — register with the same handle the dashboard uses so a
    // user navigating between Dashboard ↔ Customer Analytics doesn't fetch
    // the bundle twice.
    wp_enqueue_script(
        'chart-js',
        BRIKPANEL_URL . 'assets/js/chart.js',
        [],
        BRIKPANEL_VERSION,
        true
    );

    wp_enqueue_style(
        'brikpanel_customer_analytics_styles',
        BRIKPANEL_URL . 'front-end/customer-analytics/brikpanel-customer-analytics.css',
        [],
        BRIKPANEL_VERSION
    );

    wp_enqueue_script(
        'brikpanel_customer_analytics_scripts',
        BRIKPANEL_URL . 'front-end/customer-analytics/brikpanel-customer-analytics.js',
        [ 'chart-js' ],
        BRIKPANEL_VERSION,
        true
    );

    $nonce = wp_create_nonce('brikpanel_customer_analytics_nonce');
    wp_localize_script('brikpanel_customer_analytics_scripts', 'brikpanelCA', [
        'ajax_url'       => admin_url('admin-ajax.php'),
        'nonce'          => $nonce,
        'export_url'     => add_query_arg([
            'action'   => 'brikpanel_ca_ltv_export',
            '_wpnonce' => $nonce,
        ], admin_url('admin-ajax.php')),
        'rfm_export_url' => add_query_arg([
            'action'   => 'brikpanel_ca_rfm_export',
            '_wpnonce' => $nonce,
        ], admin_url('admin-ajax.php')),
        'cohort_export_url' => add_query_arg([
            'action'   => 'brikpanel_ca_cohort_export',
            '_wpnonce' => $nonce,
        ], admin_url('admin-ajax.php')),
        'currency'       => function_exists('get_woocommerce_currency_symbol') ? get_woocommerce_currency_symbol() : '$',
        'i18n'           => [
            'loading'           => __('Loading…', 'brikpanel'),
            'error'             => __('Something went wrong.', 'brikpanel'),
            'empty'             => __('No customers yet.', 'brikpanel'),
            'guest'             => __('Guest', 'brikpanel'),
            'customers'         => __('Customers', 'brikpanel'),
            'repeat_customers'  => __('repeat', 'brikpanel'),
            'today'             => __('Today', 'brikpanel'),
            'yesterday'         => __('1 day', 'brikpanel'),
            'days_ago'          => _x('d', 'short for days', 'brikpanel'),
            'refreshing'        => __('Refreshing…', 'brikpanel'),
            'recomputed'        => __('Customer metrics recomputed', 'brikpanel'),
            'rows'              => __('rows', 'brikpanel'),
            'avg_ltv_short'     => __('LTV', 'brikpanel'),
            'avg_orders_short'  => __('Orders', 'brikpanel'),
            'cohort_month'      => __('Cohort', 'brikpanel'),
            'cohort_size'       => __('Size', 'brikpanel'),
            'cohort_empty'      => __('Not enough order history to build cohorts yet.', 'brikpanel'),
            'avg_retention'     => __('Avg retention', 'brikpanel'),
            'rfm_recency'       => __('Recency', 'brikpanel'),
            'rfm_frequency'     => __('Frequency', 'brikpanel'),
            'rfm_monetary'      => __('Monetary', 'brikpanel'),
            'excl_title'        => __('Exclude customers from analytics', 'brikpanel'),
            'excl_intro'        => __('Pick the accounts you do not want counted as customers, for example a point-of-sale or staff login that places many orders. Their orders still count toward your shop revenue and order totals, but they are left out of customer averages, segments, and retention so the numbers reflect your real customers.', 'brikpanel'),
            'excl_people'       => __('People', 'brikpanel'),
            'excl_search_ph'    => __('Search by name or email…', 'brikpanel'),
            'excl_no_results'   => __('No matching users.', 'brikpanel'),
            'excl_none_people'  => __('No people excluded yet.', 'brikpanel'),
            'excl_roles'        => __('Roles', 'brikpanel'),
            'excl_roles_hint'   => __('Everyone with a checked role is excluded too.', 'brikpanel'),
            'excl_remove'       => __('Remove', 'brikpanel'),
            'excl_save'         => __('Save changes', 'brikpanel'),
            'excl_saving'       => __('Saving…', 'brikpanel'),
            'excl_cancel'       => __('Cancel', 'brikpanel'),
            'excl_saved'        => __('Exclusions saved', 'brikpanel'),
            'excl_button'       => __('Exclude customers', 'brikpanel'),
            /* translators: %d: number of excluded accounts */
            'excl_count'        => __('%d account(s) excluded', 'brikpanel'),
            'members'           => __('members', 'brikpanel'),
        ],
    ]);
}
add_action('admin_enqueue_scripts', 'brikpanel_enqueue_customer_analytics_assets');

// =============================================================================
// GLOBAL ADMIN STYLES & SCRIPTS
// =============================================================================
function brikpanel_enqueue_global_assets() {
    // The admin-bar search and the BrikPanel navigation are only useful to
    // users who can actually manage WooCommerce. Skipping the enqueue for
    // shop managers / authors / subscribers saves a JS+CSS round-trip on
    // every admin page load — meaningful on weak hosts where every avoided
    // 304 still costs a PHP boot.
    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        return;
    }
    // brikpanel-navigation.css hides the native #adminmenu so our custom nav
    // can replace it. Network Admin and User Admin don't render the custom
    // nav, so loading the CSS there blanks out the super-admin sidebar.
    if ( is_network_admin() || is_user_admin() ) {
        return;
    }
    // Under the Desktop Mode shell the custom nav and top-bar search are both
    // suppressed (the dock and Cmd+K palette replace them). navigation.css
    // also offsets #wpcontent by the sidebar width, which would orphan inside
    // a chromeless window — so skip these assets entirely here.
    if ( function_exists( 'brikpanel_is_desktop_mode' ) && brikpanel_is_desktop_mode() ) {
        return;
    }

    // filemtime-based version so any edit to the palette assets busts the
    // browser cache immediately, even between releases (BRIKPANEL_VERSION
    // alone would keep stale JS/CSS for returning users).
    $search_js_ver  = @filemtime( BRIKPANEL_PATH . 'front-end/search/brikpanel-search.js' ) ?: BRIKPANEL_VERSION;
    $search_css_ver = @filemtime( BRIKPANEL_PATH . 'front-end/search/brikpanel-search.css' ) ?: BRIKPANEL_VERSION;

    wp_enqueue_script(
        'brikpanel_search_scripts',
        BRIKPANEL_URL . 'front-end/search/brikpanel-search.js',
        [],
        $search_js_ver,
        true
    );
    wp_localize_script('brikpanel_search_scripts', 'brikpanelSearchAjax', [
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce'    => wp_create_nonce('brikpanel_search_action'),
        'i18n'     => [
            'hint_text' => __('You can search orders by customer name, email, phone, order ID, or product SKUs within an order.', 'brikpanel'),
        ],
    ]);

    // filemtime version → CSS edits (e.g. the classic-nav re-skin) bust the
    // browser cache immediately, matching the dashboard/segments assets above.
    $nav_css_ver = @filemtime( BRIKPANEL_PATH . 'front-end/navigation/brikpanel-navigation.css' ) ?: BRIKPANEL_VERSION;
    wp_enqueue_style(
        'brikpanel_navigation_styles',
        BRIKPANEL_URL . 'front-end/navigation/brikpanel-navigation.css',
        [],
        $nav_css_ver
    );

    wp_enqueue_style(
        'brikpanel_search_styles',
        BRIKPANEL_URL . 'front-end/search/brikpanel-search.css',
        [],
        $search_css_ver
    );

}
add_action('admin_enqueue_scripts', 'brikpanel_enqueue_global_assets');

/**
 * Detect whether an admin_enqueue_scripts callback would raise an uncatchable
 * "Cannot redeclare" fatal on a second invocation because its body declares a
 * named (non-anonymous) function in the global/function scope.
 *
 * Some plugins declare a global helper *inside* their enqueue method, e.g.
 * WooCommerce Bookings-adjacent product designers ship
 * `function getRandomDate() {...}` inside `admin_scripts()`. That is legal on
 * the first call but fatals on the second. BrikPanel's product editor re-fires
 * admin_enqueue_scripts (so screen-gated SEO/metabox plugins enqueue with the
 * post.php hook suffix), which would trip that fatal and white-screen the whole
 * editor. We read the callback's source body and detach such callbacks before
 * the re-fire; their assets were already registered by the natural fire, so
 * skipping the second run is safe.
 *
 * Handles every callback shape WordPress stores: Closure, [object, method],
 * [class, method], "Class::method", and plain "function_name". The callback's
 * own signature line is excluded from the scan so a normal method/function name
 * never counts as a nested declaration; only genuinely nested named functions
 * (the redeclare hazard) match. Anonymous functions and arrow functions carry
 * no name and are always treated as safe.
 *
 * @param mixed $function Hook callback as stored in $wp_filter.
 * @return bool True when re-firing the callback risks a redeclare fatal.
 */
function brikpanel_aes_callback_redeclares_function($function) {
    static $cache = [];

    try {
        $ref = null;

        if ($function instanceof Closure) {
            $ref = new ReflectionFunction($function);
        } elseif (is_string($function)) {
            if (strpos($function, '::') !== false) {
                list($cls, $method) = explode('::', $function, 2);
                if (class_exists($cls) && method_exists($cls, $method)) {
                    $ref = new ReflectionMethod($cls, $method);
                }
            } elseif (function_exists($function)) {
                $ref = new ReflectionFunction($function);
            }
        } elseif (is_array($function) && count($function) === 2) {
            list($obj, $method) = array_values($function);
            $cls = is_object($obj) ? get_class($obj) : (string) $obj;
            if ($cls !== '' && is_string($method) && method_exists($cls, $method)) {
                $ref = new ReflectionMethod($cls, $method);
            }
        } elseif (is_object($function) && method_exists($function, '__invoke')) {
            $ref = new ReflectionMethod($function, '__invoke');
        }

        if (!$ref) {
            return false;
        }

        $file  = $ref->getFileName();
        $start = $ref->getStartLine();
        $end   = $ref->getEndLine();
        // Internal (PHP-defined) callbacks have no file/lines: never a hazard.
        if (!$file || !$start || !$end || $end <= $start) {
            return false;
        }

        $key = $file . ':' . $start . '-' . $end;
        if (isset($cache[$key])) {
            return $cache[$key];
        }

        $lines = @file($file, FILE_IGNORE_NEW_LINES);
        if (!is_array($lines)) {
            return $cache[$key] = false;
        }

        // Skip the first line (the callback's own signature) so its own name is
        // never matched — only the body lines are scanned for nested function
        // declarations.
        $body = implode("\n", array_slice($lines, $start, $end - $start));

        // A named function declaration -> redeclare risk on a second call.
        // Anonymous `function (`/`function(` and arrow `fn(` carry no name.
        $has = (bool) preg_match(
            '/\bfunction\s+[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*\s*\(/',
            $body
        );

        return $cache[$key] = $has;
    } catch (\Throwable $e) {
        // Reflection or file read failed — assume safe, leave callback in place.
        return false;
    }
}

// =============================================================================
// WOOCOMMERCE PAGE SPECIFIC ASSETS (PREMIUM)
// =============================================================================
function brikpanel_enqueue_woo_assets($hook) {
    
    // Detect WooCommerce orders pages
    $is_hpos_orders = ($hook === 'woocommerce_page_wc-orders');
    $is_legacy_orders = (isset($_GET['post_type']) && sanitize_key($_GET['post_type']) === 'shop_order' && $hook === 'edit.php');

    // Detect order edit page
    $is_order_edit = ($is_hpos_orders && isset($_GET['action']) && sanitize_key($_GET['action']) === 'edit');

    // Orders page assets
    if (($is_hpos_orders || $is_legacy_orders)) {

        // ── Inline status change (always loaded on orders list) ──────
        wp_enqueue_script(
            'brikpanel_order_status_inline',
            BRIKPANEL_URL . 'front-end/orders/brikpanel-order-status-inline.js',
            [],
            BRIKPANEL_VERSION,
            true
        );

        wp_enqueue_style(
            'brikpanel_order_status_inline_styles',
            BRIKPANEL_URL . 'front-end/orders/brikpanel-order-status-inline.css',
            [],
            BRIKPANEL_VERSION
        );

        wp_localize_script('brikpanel_order_status_inline', 'brikpanelStatusInline', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('brikpanel_order_status_nonce'),
            'statuses' => wc_get_order_statuses(),
            'i18n'     => [
                'change_status' => __('Change status', 'brikpanel'),
                'error'         => __('An error occurred. Please try again.', 'brikpanel'),
                /* translators: 1: order number, 2: original status label, 3: new status label */
                'pending_text'  => __('Order #%1$s: %2$s → %3$s', 'brikpanel'),
                'save'          => __('Save', 'brikpanel'),
                'discard'       => __('Discard', 'brikpanel'),
                'saving'        => __('Saving…', 'brikpanel'),
            ],
        ]);

        // ── Order edit page assets (styles + JS) ──────────────────────
        if ( $is_order_edit && get_option( 'brikpanel_modern_order_edit', 'yes' ) !== 'no' ) {
            // filemtime-based version so any edit to the order assets busts
            // the browser cache (falls back to the plugin version).
            $order_css_ver = @filemtime( BRIKPANEL_PATH . 'front-end/order/brikpanel-order.css' ) ?: BRIKPANEL_VERSION;
            $order_js_ver  = @filemtime( BRIKPANEL_PATH . 'front-end/order/brikpanel-order.js' ) ?: BRIKPANEL_VERSION;

            wp_enqueue_style(
                'brikpanel_order_styles',
                BRIKPANEL_URL . 'front-end/order/brikpanel-order.css',
                ['woocommerce_admin_styles'],
                $order_css_ver
            );
            $order_id = absint( $_GET['id'] ?? 0 );
            $order    = $order_id ? wc_get_order( $order_id ) : null;

            wp_enqueue_script(
                'brikpanel_order_edit',
                BRIKPANEL_URL . 'front-end/order/brikpanel-order.js',
                [],
                $order_js_ver,
                true
            );

            $current_status = $order ? $order->get_status() : '';
            $all_statuses   = wc_get_order_statuses();
            $status_label   = $order ? ( $all_statuses[ 'wc-' . $current_status ] ?? $current_status ) : '';

            $item_downloads = $order ? brikpanel_collect_order_item_downloads( $order ) : [];

            wp_localize_script( 'brikpanel_order_edit', 'brikpanelOrderEdit', [
                'ajax_url'       => admin_url( 'admin-ajax.php' ),
                'nonce'          => wp_create_nonce( 'brikpanel_order_status_nonce' ),
                'order_id'       => $order_id,
                'current_status' => $current_status,
                'status_label'   => $status_label,
                'order_date'     => ($order && $order->get_date_created()) ? $order->get_date_created()->date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) : '',
                'statuses'       => $all_statuses,
                'orders_url'     => admin_url( 'admin.php?page=wc-orders' ),
                'item_downloads' => (object) $item_downloads,
                'i18n'           => [
                    'orders'              => __( 'Orders', 'brikpanel' ),
                    'save'                => __( 'Save', 'brikpanel' ),
                    'copy'                => __( 'Copy', 'brikpanel' ),
                    'copied'              => __( 'Copied!', 'brikpanel' ),
                    'address_copied'      => __( 'Address copied to clipboard', 'brikpanel' ),
                    'note_added'          => __( 'Note added', 'brikpanel' ),
                    'error'               => __( 'An error occurred. Please try again.', 'brikpanel' ),
                    'downloads'           => __( 'Downloads', 'brikpanel' ),
                    'download_one'        => __( '%d download', 'brikpanel' ),
                    'download_many'       => __( '%d downloads', 'brikpanel' ),
                    'remaining'           => __( '%s left', 'brikpanel' ),
                    'unlimited'           => __( 'Unlimited', 'brikpanel' ),
                    'expires'             => __( 'Expires %s', 'brikpanel' ),
                    'never_expires'       => __( 'Never expires', 'brikpanel' ),
                    'never_downloaded'    => __( 'Never downloaded', 'brikpanel' ),
                ],
            ] );
        }

        // ── Enhanced orders page (conditional, skip on edit page) ──
        if (!$is_order_edit && get_option('brikpanel_orders_enhancements', 'yes') !== 'no') {
            $orders_css_ver = @filemtime( BRIKPANEL_PATH . 'front-end/orders/brikpanel-orders.css' ) ?: BRIKPANEL_VERSION;
            $orders_js_ver  = @filemtime( BRIKPANEL_PATH . 'front-end/orders/brikpanel-orders.js' ) ?: BRIKPANEL_VERSION;
            wp_enqueue_script(
                'brikpanel_orders_scripts',
                BRIKPANEL_URL . 'front-end/orders/brikpanel-orders.js',
                ['jquery', 'wc-enhanced-select'],
                $orders_js_ver,
                true
            );

            wp_enqueue_style(
                'brikpanel_orders_styles',
                BRIKPANEL_URL . 'front-end/orders/brikpanel-orders.css',
                ['woocommerce_admin_styles'],
                $orders_css_ver
            );

            wp_localize_script( 'brikpanel_orders_scripts', 'brikpanelOrdersOverview', [
                'ajax_url'      => admin_url( 'admin-ajax.php' ),
                'nonce'         => wp_create_nonce( 'brikpanel_nonce_action' ),
                'hide_overview' => function_exists( 'brikpanel_orders_overview_hidden_for_user' )
                    && brikpanel_orders_overview_hidden_for_user(),
                'i18n'     => [
                    'today'             => __( 'Today', 'brikpanel' ),
                    'last_24_hours'     => __( 'Last 24 hours', 'brikpanel' ),
                    'last_7_days'       => __( 'Last 7 days', 'brikpanel' ),
                    'last_30_days'      => __( 'Last 30 days', 'brikpanel' ),
                    'date_range'        => __( 'Date range', 'brikpanel' ),
                    'total_orders'      => __( 'Total orders', 'brikpanel' ),
                    'orders'            => __( 'Orders', 'brikpanel' ),
                    'completed'         => __( 'Completed', 'brikpanel' ),
                    'refunded'          => __( 'Refunded', 'brikpanel' ),
                    'cancelled'         => __( 'Cancelled', 'brikpanel' ),
                    'revenue'           => __( 'Revenue', 'brikpanel' ),
                    'marketplaces'      => __( 'Marketplaces', 'brikpanel' ),
                    'products'          => __( 'products', 'brikpanel' ),
                    'orders_low'        => __( 'orders', 'brikpanel' ),
                    'show_all'          => __( 'Show all', 'brikpanel' ),
                    'show_less'         => __( 'Show less', 'brikpanel' ),
                    'search'            => __( 'Search', 'brikpanel' ),
                    'searching_by'      => __( 'Searching by', 'brikpanel' ),
                    'search_and_filter' => __( 'Search and filter (F)', 'brikpanel' ),
                    'cancel'            => __( 'Cancel', 'brikpanel' ),
                    'filter_by'         => __( 'Filter by', 'brikpanel' ),
                    'clear_filters'     => __( 'Clear filters', 'brikpanel' ),
                    'filter_order_tag'  => __( 'order tag', 'brikpanel' ),
                    'filter_shipping_method' => __( 'shipping method', 'brikpanel' ),
                ],
            ] );
        }
    }

    // Products page assets (FREE)
    if ('edit.php' === $hook && isset($_GET['post_type']) && 'product' === sanitize_key($_GET['post_type'])) {
        wp_enqueue_style(
            'brikpanel_products_styles',
            BRIKPANEL_URL . 'front-end/products/brikpanel-products.css',
            [],
            BRIKPANEL_VERSION
        );
    }

    // Taxonomy pages (Categories, Tags, Attributes)
    $is_product_taxonomy = in_array($hook, ['edit-tags.php', 'term.php'], true)
        && isset($_GET['taxonomy'])
        && in_array(sanitize_key($_GET['taxonomy']), ['product_cat', 'product_tag'], true);
    $is_attributes_page = $hook === 'product_page_product_attributes';

    // Any product taxonomy term screen (Categories, Tags, Brands, attribute
    // terms, and other registered product taxonomies) should receive the
    // BrikPanel admin styling so every taxonomy screen stays visually
    // consistent. The styling is purely cosmetic; the category nesting JS
    // below stays limited to $is_product_taxonomy on purpose.
    $is_styled_taxonomy = in_array($hook, ['edit-tags.php', 'term.php'], true)
        && isset($_GET['taxonomy'])
        && in_array(sanitize_key($_GET['taxonomy']), get_object_taxonomies('product'), true);

    if ($is_styled_taxonomy || $is_attributes_page) {
        $tax_css_ver = @filemtime( BRIKPANEL_PATH . 'front-end/products/brikpanel-taxonomy.css' ) ?: BRIKPANEL_VERSION;
        wp_enqueue_style(
            'brikpanel_taxonomy_styles',
            BRIKPANEL_URL . 'front-end/products/brikpanel-taxonomy.css',
            [],
            $tax_css_ver
        );
    }

    // Category enhancements JS (drag-drop nesting, search reposition)
    if ($is_product_taxonomy) {
        $tax = sanitize_key($_GET['taxonomy'] ?? 'product_cat');
        wp_enqueue_script(
            'brikpanel_category_enhancements',
            BRIKPANEL_URL . 'front-end/products/brikpanel-category-enhancements.js',
            ['jquery'],
            BRIKPANEL_VERSION . '.5',
            true
        );

        wp_localize_script('brikpanel_category_enhancements', 'brikpanelCE', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('brikpanel_category_nesting'),
            'taxonomy' => $tax,
            'i18n'     => [
                'drag_to_nest'     => __('Drag onto another category to make it a sub-category', 'brikpanel'),
                'confirm_nest'     => __('Move "%1$s" as a sub-category of "%2$s"?', 'brikpanel'),
                'make_top_level'   => __('Make top level', 'brikpanel'),
                'error'            => __('An error occurred. Please try again.', 'brikpanel'),
            ],
        ]);
    }

    // Products List (AJAX)
    if ('admin_page_brikpanel-products' === $hook && get_option('brikpanel_modern_products_list', 'yes') === 'yes') {
        // Quick-edit drawer's "Digital product" section uses the WP media
        // library to attach downloadable files, same as the product editor.
        wp_enqueue_media();

        // filemtime-based version so any edit to the products-list assets
        // busts the browser cache (falls back to the plugin version).
        $pl_css_ver = @filemtime( BRIKPANEL_PATH . 'front-end/products/brikpanel-products-list.css' ) ?: BRIKPANEL_VERSION;
        $pl_js_ver  = @filemtime( BRIKPANEL_PATH . 'front-end/products/brikpanel-products-list.js' ) ?: BRIKPANEL_VERSION;

        wp_enqueue_style(
            'brikpanel_products_list_styles',
            BRIKPANEL_URL . 'front-end/products/brikpanel-products-list.css',
            [],
            $pl_css_ver
        );

        wp_enqueue_script(
            'brikpanel_products_list_scripts',
            BRIKPANEL_URL . 'front-end/products/brikpanel-products-list.js',
            ['jquery', 'jquery-ui-sortable'],
            $pl_js_ver,
            true
        );

        $per_page = get_option('brikpanel_products_per_page', 20);

        wp_localize_script('brikpanel_products_list_scripts', 'brikpanelPL', [
            'ajax_url'      => admin_url('admin-ajax.php'),
            'nonce'         => wp_create_nonce('brikpanel_products_list_nonce'),
            'nonce_pe'      => wp_create_nonce('brikpanel_product_editor_nonce'),
            'export_url'    => wp_nonce_url(admin_url('admin-post.php?action=brikpanel_export_selected_products'), 'brikpanel_export_selected'),
            'currency'      => get_woocommerce_currency_symbol(),
            'per_page'      => (int) $per_page,
            'open_in_new_tab'    => get_option('brikpanel_open_edit_in_new_tab', 'yes') !== 'no',
            'show_featured_star' => function_exists('brikpanel_qe_is_field_visible') && brikpanel_qe_is_field_visible('featured'),
            'qe_cogs_visible'    => function_exists('brikpanel_qe_is_field_visible') && brikpanel_qe_is_field_visible('cogs'),
            'weight_unit'        => get_option('woocommerce_weight_unit', 'kg'),
            'dimension_unit'     => get_option('woocommerce_dimension_unit', 'cm'),
            'i18n'     => [
                'no_products'         => __('No products found.', 'brikpanel'),
                'error'               => __('An error occurred. Please try again.', 'brikpanel'),
                'saved'               => __('Saved!', 'brikpanel'),
                'saving'              => __('Saving...', 'brikpanel'),
                'save_changes'        => __('Save changes', 'brikpanel'),
                'published'           => __('Published', 'brikpanel'),
                'draft'               => __('Draft', 'brikpanel'),
                'private_status'      => __('Private', 'brikpanel'),
                'trashed'             => __('Trash', 'brikpanel'),
                'trashed_tab'         => __('Trash', 'brikpanel'),
                'variable'            => __('Variable', 'brikpanel'),
                'quick_edit'          => __('Quick edit', 'brikpanel'),
                'duplicate'           => __('Duplicate', 'brikpanel'),
                'duplicating'         => __('Duplicating...', 'brikpanel'),
                'duplicated'          => __('Product duplicated!', 'brikpanel'),
                'trash'               => __('Move to trash', 'brikpanel'),
                'restore'             => __('Restore', 'brikpanel'),
                'restored'            => __('Product restored!', 'brikpanel'),
                'delete_permanently'  => __('Delete permanently', 'brikpanel'),
                'deleted_permanently' => __('Product permanently deleted.', 'brikpanel'),
                'confirm_delete'      => __('Are you sure you want to trash "%s"?', 'brikpanel'),
                'confirm_permanent_delete' => __('Are you sure? This cannot be undone.', 'brikpanel'),
                'confirm_bulk'        => __('Are you sure you want to update %d products?', 'brikpanel'),
                'confirm_bulk_trash'  => __('Are you sure you want to trash %d products?', 'brikpanel'),
                'confirm_bulk_delete_perm' => __('Are you sure you want to permanently delete %d products? This cannot be undone.', 'brikpanel'),
                'confirm_bulk_delete_perm_2' => __('FINAL WARNING: This will permanently delete the selected products. Are you absolutely sure?', 'brikpanel'),
                'click_to_toggle'     => __('Click to toggle status', 'brikpanel'),
                'product_id'          => __('Product ID', 'brikpanel'),
                'mark_featured'       => __('Mark as featured', 'brikpanel'),
                'unmark_featured'     => __('Featured — click to remove', 'brikpanel'),
                'showing'             => __('Showing %1$d of %2$d products', 'brikpanel'),
                'showing_range'       => __('Showing %1$d–%2$d of %3$d products', 'brikpanel'),
                'bulk_confirm'        => __('Apply this action to the selected products? This cannot be undone.', 'brikpanel'),
                'bulk_cat_confirm'    => __('Apply this action to all products in the selected category? This cannot be undone.', 'brikpanel'),
                'bulk_all_confirm'    => __('Apply this action to ALL products in the store? This cannot be undone.', 'brikpanel'),
                'bulk_select_cat'     => __('Please select a category.', 'brikpanel'),
                'bulk_no_selection'   => __('No products selected. Select products from the table first.', 'brikpanel'),
                'bulk_select_terms'   => __('Please select at least one item to add.', 'brikpanel'),
                'bulk_selected_count' => __('%d products selected', 'brikpanel'),
                'applying'            => __('Applying...', 'brikpanel'),
                'apply'               => __('Apply', 'brikpanel'),
                'loading_attrs'       => __('Loading attributes...', 'brikpanel'),
                'all_variations'      => __('All products / variations', 'brikpanel'),
                'select_attr_first'   => __('Select attribute first', 'brikpanel'),
                'stock_by_variation'  => __('Stock by variation', 'brikpanel'),
                'price_by_variation'  => __('Price by variation', 'brikpanel'),
                'price_label'         => __('Price', 'brikpanel'),
                'sale_label'          => __('Sale', 'brikpanel'),
                'stock_label'         => __('Stock', 'brikpanel'),
                'cogs_label'          => __('Cost', 'brikpanel'),
                'stock_status_label'  => __('Availability', 'brikpanel'),
                'in_stock'            => __('In stock', 'brikpanel'),
                'out_of_stock'        => __('Out of stock', 'brikpanel'),
                'on_backorder'        => __('On backorder', 'brikpanel'),
                'price_placeholder'   => __('0', 'brikpanel'),
                'sale_placeholder'    => __('Sale', 'brikpanel'),
                'no_variations'       => __('No variations found.', 'brikpanel'),
                'view'                => __('View', 'brikpanel'),
                'cancel'              => __('Cancel', 'brikpanel'),
                'delete_confirm_1'    => __('Are you sure you want to delete these products?', 'brikpanel'),
                'delete_confirm_all'  => __('Are you sure you want to delete ALL products in the store? This is extremely dangerous!', 'brikpanel'),
                'delete_confirm_2'    => __('PERMANENT DELETE — This cannot be undone. Are you absolutely sure?', 'brikpanel'),
                'bulk_preparing'      => __('Preparing...', 'brikpanel'),
                'bulk_progress'       => __('%1$d / %2$d processed', 'brikpanel'),
                'bulk_title_update'   => __('Bulk update in progress', 'brikpanel'),
                'bulk_title_delete'   => __('Bulk delete in progress', 'brikpanel'),
                'bulk_complete_update' => __('Update complete: %d items.', 'brikpanel'),
                'bulk_complete_delete' => __('Delete complete: %d items.', 'brikpanel'),
                'bulk_cancelled'      => __('Cancelled.', 'brikpanel'),
                'bulk_cancel_btn'     => __('Cancel', 'brikpanel'),
                'bulk_done_btn'       => __('Done', 'brikpanel'),
                'bulk_errors_count'   => __('%d errors occurred.', 'brikpanel'),
                'bulk_retrying'       => __('Network error, retrying...', 'brikpanel'),
                'bulk_failed'         => __('Bulk job failed.', 'brikpanel'),
                'bulk_confirm_cancel' => __('Cancel the running bulk job? Items processed so far will remain changed.', 'brikpanel'),
                'fast_delete_confirm' => __('FAST DELETE — This bypasses all plugin hooks and is IRREVERSIBLE. Image files will remain on disk. SEO/search/cache plugins will show stale data until re-indexed. Continue?', 'brikpanel'),
                'tax_only_confirm'    => __('Wipe the selected taxonomies (categories/tags/attributes/brands)? This cannot be undone.', 'brikpanel'),
                'export_starting'     => __('Preparing export...', 'brikpanel'),
                'export_no_selection' => __('No products selected for export.', 'brikpanel'),
                'more_categories'     => __('%d more categories', 'brikpanel'),
                'plugin_columns'      => __('Plugin columns', 'brikpanel'),
                'cogs_partial'        => __('%d variations have no cost on file', 'brikpanel'),
                // Digital product (downloadable) — quick edit drawer
                'select_file'         => __('Select downloadable file', 'brikpanel'),
                'select'              => __('Select', 'brikpanel'),
                'no_files'            => __('No files added yet.', 'brikpanel'),
                'file_name'           => __('File name', 'brikpanel'),
                'choose_file'         => __('Choose file', 'brikpanel'),
                'remove'              => __('Remove', 'brikpanel'),
                // Sortable mode — drag-to-reorder products by menu_order
                'sort_btn'            => __('Sort', 'brikpanel'),
                'sort_done'           => __('Done sorting', 'brikpanel'),
                'sort_saving'         => __('Saving order...', 'brikpanel'),
                'sort_saved'          => __('Order saved', 'brikpanel'),
                'sort_save_failed'    => __('Could not save order. Please try again.', 'brikpanel'),
                'sort_drag_handle'    => __('Drag to reorder', 'brikpanel'),
                'sort_active_label'   => __('Custom order', 'brikpanel'),
                'popup_close'         => __('Close', 'brikpanel'),
            ],
        ]);
    }

    // Simplified Product Editor
    if ('admin_page_brikpanel-product-editor' === $hook && get_option('brikpanel_simple_product_editor', 'yes') === 'yes') {
        // Run the screen-spoof + asset bootstrap whenever EITHER:
        //   - the admin picked at least one 3rd-party metabox in settings, OR
        //   - a supported SEO plugin (Yoast, Rank Math, AIOSEO, SEOPress) is
        //     active — the BrikPanel SEO card renders its native metabox and
        //     relies on the plugin's own JS/CSS bundles to drive it.
        $selected_metaboxes = (array) get_option('brikpanel_pe_selected_metaboxes', []);
        // 3rd-party WooCommerce product-data tabs the admin chose to surface
        // (keyed `tab:<panel_id>`). Many of these panels are JS-driven mount
        // points — e.g. AcoWebs "Custom Product Addons" (`wcpa_product-meta-tab`)
        // renders an empty `<div id="wcpa_product_meta">` and hydrates it from
        // its `product.js`, which only enqueues when the screen is `product`.
        // Selecting such a tab must therefore also trigger the screen-spoof +
        // admin_enqueue_scripts re-fire below, otherwise the panel renders but
        // stays inert. Only custom `tab:` keys count — core sections need no
        // 3rd-party assets.
        $selected_wc_tabs = array_filter(
            (array) get_option('brikpanel_pe_wc_tabs_selected', []),
            static function ($k) { return is_string($k) && strpos($k, 'tab:') === 0; }
        );
        $auto_seo = class_exists('Brikpanel_Product_Editor')
            ? Brikpanel_Product_Editor::get_active_seo_plugin()
            : null;
        $visible_sections = get_option('brikpanel_pe_visible_sections');
        if (!is_array($visible_sections)) {
            $visible_sections = ['images', 'pricing', 'inventory', 'category', 'tags', 'short_desc', 'description', 'digital', 'weight', 'dimensions', 'seo', 'variations'];
        }
        $seo_auto_rendered = $auto_seo && in_array('seo', $visible_sections, true);
        if ($seo_auto_rendered) {
            // Merge the auto-rendered SEO plugin's metabox IDs into the list
            // the bootstrap walks, so ACF / taxonomy helpers below still
            // behave correctly and SEO plugin assets (Yoast analysis JS,
            // Rank Math editor bundle, etc.) get enqueued.
            $selected_metaboxes = array_values(array_unique(array_merge(
                $selected_metaboxes,
                (array) $auto_seo['metabox_ids']
            )));
        }
        // ACF — auto-include field group metaboxes whose Location Rules
        // resolve to the current product so ACF "just works" without
        // forcing the admin to enable each group via the metabox picker.
        // Merging here also triggers the asset-enqueue path below.
        if (function_exists('brikpanel_resolve_auto_acf_metabox_ids')) {
            $auto_acf_product_id = isset($_GET['product_id']) ? absint($_GET['product_id']) : 0;
            $auto_acf_ids = brikpanel_resolve_auto_acf_metabox_ids($auto_acf_product_id, 'product');
            if (!empty($auto_acf_ids)) {
                $selected_metaboxes = array_values(array_unique(array_merge(
                    $selected_metaboxes,
                    $auto_acf_ids
                )));
            }
        }
        if (!empty($selected_metaboxes) || !empty($selected_wc_tabs)) {
            // Spoof screen + post globals as if we were on /wp-admin/post.php
            // so SEO plugins (Yoast, Rank Math, AIOSEO, SEOPress) register
            // their metabox + enqueue scripts the way they do natively.
            global $current_screen, $post, $post_type, $typenow, $pagenow;
            $saved_screen    = $current_screen;
            $saved_post      = $post;
            $saved_post_type = $post_type;
            $saved_typenow   = $typenow;
            $saved_pagenow   = $pagenow;
            set_current_screen('product');
            $spoof_product_id = isset($_GET['product_id']) ? absint($_GET['product_id']) : 0;
            if ($spoof_product_id) {
                $spoof_post = get_post($spoof_product_id);
                if ($spoof_post && $spoof_post->post_type === 'product') {
                    $post      = $spoof_post;
                    $post_type = 'product';
                    $typenow   = 'product';
                    $pagenow   = 'post.php';
                    $GLOBALS['post']      = $spoof_post;
                    $GLOBALS['post_type'] = 'product';
                    $GLOBALS['typenow']   = 'product';
                    $GLOBALS['pagenow']   = 'post.php';
                }
            }

            // Force-instantiate SEO metabox classes that gate on pagenow so
            // their admin_enqueue_scripts hooks register before we re-fire.
            if (class_exists('WPSEO_Metabox') && empty($GLOBALS['wpseo_metabox'])) {
                $GLOBALS['wpseo_metabox'] = new WPSEO_Metabox();
            }
            // Rank Math — Metabox::hooks() short-circuits unless its Screen
            // class resolved a loader, which it does only when $pagenow is
            // post.php / post-new.php (we've spoofed that above). Instantiate
            // and call hooks() so add_meta_boxes + rank_math/admin/enqueue_scripts
            // listeners attach before we fire those actions.
            $rm_metabox = null;
            if (class_exists('\\RankMath\\Admin\\Metabox\\Metabox')) {
                try {
                    $rm_metabox = $GLOBALS['brikpanel_rm_metabox_instance'] ?? null;
                    if (!$rm_metabox) {
                        $rm_metabox = new \RankMath\Admin\Metabox\Metabox();
                        if (method_exists($rm_metabox, 'hooks')) {
                            $rm_metabox->hooks();
                        }
                        $GLOBALS['brikpanel_rm_metabox_instance'] = $rm_metabox;
                    }
                } catch (\Throwable $e) { /* skip */ }
            }

            // Re-fire admin_enqueue_scripts with the post.php hook suffix so
            // listeners that guard on hook/screen will now register their
            // assets. wp_enqueue_script dedupes, so double-fires are safe
            // for plugins that use core's enqueue API. Two classes of plugin
            // do NOT tolerate a second invocation and must be temporarily
            // detached before re-firing (their assets are already registered
            // by the natural admin_enqueue_scripts fire, so skipping is safe):
            //
            //   (a) Plugins shipping their own asset registry that throws
            //       InvalidAsset on duplicate handles (e.g. Google Listings &
            //       Ads) — matched by namespace on Closure callbacks.
            //   (b) Plugins that declare a named function *inside* their
            //       enqueue callback (e.g. some WooCommerce product-designer
            //       add-ons). A second call raises an uncatchable "Cannot
            //       redeclare" fatal that try/catch cannot stop, white-screening
            //       the editor — matched by scanning the callback body, for any
            //       callback shape (Closure, [object, method], string).
            global $wp_filter;
            $brikpanel_detached_aes = [];
            $brikpanel_unsafe_namespaces = [
                'GoogleListingsAndAds',
            ];
            if (isset($wp_filter['admin_enqueue_scripts']) && is_object($wp_filter['admin_enqueue_scripts']) && !empty($wp_filter['admin_enqueue_scripts']->callbacks)) {
                $aes_hook = $wp_filter['admin_enqueue_scripts'];
                foreach ($aes_hook->callbacks as $aes_priority => $aes_callbacks) {
                    if (!is_array($aes_callbacks)) {
                        continue;
                    }
                    foreach ($aes_callbacks as $aes_key => $aes_cb) {
                        if (empty($aes_cb['function'])) {
                            continue;
                        }
                        $aes_fn     = $aes_cb['function'];
                        $aes_detach = false;

                        // (a) Closure in an asset-registry namespace that
                        //     rejects duplicate handles.
                        if ($aes_fn instanceof Closure) {
                            try {
                                $aes_ref   = new ReflectionFunction($aes_fn);
                                $aes_scope = $aes_ref->getClosureScopeClass();
                                if ($aes_scope) {
                                    $aes_class = $aes_scope->getName();
                                    foreach ($brikpanel_unsafe_namespaces as $aes_ns) {
                                        if (strpos($aes_class, $aes_ns) !== false) {
                                            $aes_detach = true;
                                            break;
                                        }
                                    }
                                }
                            } catch (\Throwable $aes_e) {
                                // Reflection failed — leave callback in place.
                            }
                        }

                        // (b) Any callback whose body declares a named function
                        //     would fatal on the re-fire.
                        if (!$aes_detach && brikpanel_aes_callback_redeclares_function($aes_fn)) {
                            $aes_detach = true;
                        }

                        if ($aes_detach) {
                            $brikpanel_detached_aes[] = [$aes_priority, $aes_key, $aes_cb];
                            unset($aes_hook->callbacks[$aes_priority][$aes_key]);
                        }
                    }
                    if (isset($aes_hook->callbacks[$aes_priority]) && empty($aes_hook->callbacks[$aes_priority])) {
                        unset($aes_hook->callbacks[$aes_priority]);
                    }
                }
            }

            try {
                do_action('admin_enqueue_scripts', 'post.php');
            } catch (\Throwable $aes_fire_e) {
                error_log('[BrikPanel] admin_enqueue_scripts re-fire halted: ' . $aes_fire_e->getMessage());
            }

            // Restore detached callbacks so any later do_action consumers
            // (rare on the same request, but possible) still see the full set.
            if (!empty($brikpanel_detached_aes) && isset($aes_hook)) {
                foreach ($brikpanel_detached_aes as $aes_detached) {
                    list($aes_priority, $aes_key, $aes_cb) = $aes_detached;
                    if (!isset($aes_hook->callbacks[$aes_priority]) || !is_array($aes_hook->callbacks[$aes_priority])) {
                        $aes_hook->callbacks[$aes_priority] = [];
                    }
                    $aes_hook->callbacks[$aes_priority][$aes_key] = $aes_cb;
                }
                ksort($aes_hook->callbacks);
            }

            // Rank Math — the natural + re-fired admin_enqueue_scripts above
            // may still miss enqueueing the metabox assets because the Assets
            // class checks `get_admin_screen_ids()` and the Metabox enqueue()
            // has further Gutenberg/Elementor guards. Drive the enqueue
            // pipeline directly so the editor bundle ships.
            if (function_exists('rank_math') && !empty($rm_metabox) && method_exists($rm_metabox, 'enqueue')) {
                try {
                    // Rank Math's full Admin_Init (and thus its Assets runner)
                    // only bootstraps when the site has completed its Rank Math
                    // "connect account" registration OR the admin dismissed it
                    // (rank_math_registration_skip). When invalid, admin_assets
                    // is null — the metabox bundle's dependencies (rank-math-common,
                    // rank-math-app) never get registered, so `wp_enqueue_script`
                    // emits the enqueue-with-missing-deps notice and ships nothing.
                    // If we're rendering the metabox, we've already committed to
                    // surfacing Rank Math's UI, so instantiate Assets directly
                    // when the plugin hasn't.
                    if (empty(rank_math()->admin_assets) && class_exists('\\RankMath\\Admin\\Assets')) {
                        rank_math()->admin_assets = new \RankMath\Admin\Assets();
                    }
                    $aa = rank_math()->admin_assets;
                    if ($aa) {
                        if (method_exists($aa, 'register')) {
                            $aa->register();
                        }
                        if (method_exists($aa, 'enqueue')) {
                            $aa->enqueue();
                        }
                    }
                    // Rank Math's Metabox::enqueue() calls rank_math()->variables->setup().
                    // On some admin pages (including our custom page) the
                    // plugin skips registering the Replace_Variables Manager,
                    // so variables resolves to null and enqueue() fatals.
                    // Re-create it via the plugin's __set magic (which stores
                    // the value into its private container array).
                    if (empty(rank_math()->variables) && class_exists('\\RankMath\\Replace_Variables\\Manager')) {
                        rank_math()->variables = new \RankMath\Replace_Variables\Manager();
                    }
                    $rm_metabox->enqueue();
                } catch (\Throwable $e) {
                    error_log('[BrikPanel] Rank Math metabox enqueue failed: ' . $e->getMessage());
                }
            }

            // ACF — if any `acf-*` metabox is picked, enqueue ACF's input +
            // uploader assets so file/image/gallery/repeater fields actually
            // function inside the BrikPanel editor. ACF's own enqueue runs at
            // priority 20 which has already fired by the time this block runs
            // at priority 99; brikpanel_bootstrap_acf_assets() therefore calls
            // the assets instance synchronously.
            $has_acf_selected = false;
            foreach ($selected_metaboxes as $sid) {
                if (is_string($sid) && strpos($sid, 'acf-') === 0) {
                    $has_acf_selected = true;
                    break;
                }
            }
            if ($has_acf_selected && function_exists('brikpanel_bootstrap_acf_assets')) {
                brikpanel_bootstrap_acf_assets();
            }

            // Restore real page context so the rest of our own enqueues and
            // the normal page lifecycle are not affected.
            $current_screen = $saved_screen;
            $post           = $saved_post;
            $post_type      = $saved_post_type;
            $typenow        = $saved_typenow;
            $pagenow        = $saved_pagenow;
            $GLOBALS['post']      = $saved_post;
            $GLOBALS['post_type'] = $saved_post_type;
            $GLOBALS['typenow']   = $saved_typenow;
            $GLOBALS['pagenow']   = $saved_pagenow;
        }

        wp_enqueue_media();
        wp_enqueue_script('jquery-ui-sortable');

        // wp-pointer is auto-loaded on the native post.php editor but not on
        // this custom admin page. Third-party scripts pulled in by the
        // post.php enqueue re-fire above (WooCommerce admin pointers/tours,
        // core feature pointers, SEO plugin notifications) call
        // jQuery(...).pointer(...) and assume it exists. Without it the page
        // throws "$(...).pointer is not a function", which aborts the rest of
        // that inline script and cascades into the SEO plugin's own errors.
        // Enqueueing the core handle (script + style) restores the native
        // environment those scripts expect.
        wp_enqueue_script('wp-pointer');
        wp_enqueue_style('wp-pointer');

        // Product taxonomy metaboxes picked by the admin use WP core's
        // `post_tags_meta_box` (non-hierarchical) or `post_categories_meta_box`
        // (hierarchical) as their render callback. That markup is inert on
        // our custom admin page because the core JS bundles that drive it
        // (`tags-box.js`, `suggest.js`, `wp-lists`) are only auto-loaded on
        // `post.php` / `post-new.php`. Detect the two id patterns WP uses
        // for these boxes and enqueue what each one needs.
        $has_flat_tax_box = false;
        $has_hier_tax_box = false;
        foreach ($selected_metaboxes as $sid) {
            if (!is_string($sid)) continue;
            if (strpos($sid, 'tagsdiv-') === 0) {
                $has_flat_tax_box = true;
            } elseif (substr($sid, -3) === 'div'
                && $sid !== 'product_catdiv'
                && $sid !== 'tagsdiv-product_tag') {
                // Hierarchical taxonomy metaboxes WP registers with the id
                // pattern `{taxonomy}div`. Built-in product_cat / product_tag
                // are filtered out elsewhere; everything else is a plugin
                // taxonomy we need to wire up.
                $has_hier_tax_box = true;
            }
        }
        if ($has_flat_tax_box) {
            wp_enqueue_script('tags-box');
            wp_enqueue_script('suggest');
            // tags-box.js ships its own `jQuery(function(){ tagBox.init(); })`
            // bootstrap, but on our custom admin page that internal ready
            // callback fires without binding — the click / keypress handlers
            // never attach to the taxonomy metabox. Re-fire init after DOM
            // ready, guarded so the script's own bootstrap (if it also runs)
            // doesn't cause double-bound handlers and double-added tags.
            wp_add_inline_script(
                'tags-box',
                'jQuery(function(){ if (!window.tagBox || window.__bpeTagBoxInited) return; window.__bpeTagBoxInited = true; window.tagBox.init(); });'
            );
        }
        if ($has_hier_tax_box) {
            // post_categories_meta_box is driven on core post.php by
            // wp-admin/js/post.js (tab switching, "+ Add New" toggle, wpList-
            // backed AJAX submission to `add-{taxonomy}`). We don't enqueue
            // post.js because it also binds to #post / #submitdiv / #visibility
            // which would collide with BrikPanel's own status + visibility UI.
            // Ship a self-contained shim that only drives `.categorydiv` markup
            // and parses the WP_Ajax_Response XML via DOMParser (no wp-lists
            // dependency). Scoped to `.brikpanel-pe-metaboxes-wrap` so it
            // never interferes with other screens.
            wp_enqueue_script(
                'brikpanel_pe_hier_tax_shim',
                BRIKPANEL_URL . 'front-end/products/brikpanel-pe-hier-tax.js',
                ['jquery'],
                BRIKPANEL_VERSION,
                true
            );
            wp_localize_script('brikpanel_pe_hier_tax_shim', 'brikpanelPEHierTax', [
                'ajax_url' => admin_url('admin-ajax.php'),
            ]);
        }

        wp_enqueue_style(
            'brikpanel_flatpickr_styles',
            BRIKPANEL_URL . 'assets/css/flatpickr.min.css',
            [],
            BRIKPANEL_VERSION
        );
        wp_add_inline_style( 'brikpanel_flatpickr_styles', brikpanel_flatpickr_header_align_css() );

        wp_enqueue_script(
            'flatpickr-js',
            BRIKPANEL_URL . 'assets/js/flatpickr.js',
            [],
            BRIKPANEL_VERSION,
            true
        );

        $pe_css_ver = @filemtime( BRIKPANEL_PATH . 'front-end/products/brikpanel-product-editor.css' ) ?: BRIKPANEL_VERSION;
        $pe_js_ver  = @filemtime( BRIKPANEL_PATH . 'front-end/products/brikpanel-product-editor.js' ) ?: BRIKPANEL_VERSION;
        wp_enqueue_style(
            'brikpanel_product_editor_styles',
            BRIKPANEL_URL . 'front-end/products/brikpanel-product-editor.css',
            [],
            $pe_css_ver
        );

        wp_enqueue_script(
            'brikpanel_product_editor_scripts',
            BRIKPANEL_URL . 'front-end/products/brikpanel-product-editor.js',
            ['jquery', 'jquery-ui-sortable', 'flatpickr-js'],
            $pe_js_ver,
            true
        );

        wp_localize_script('brikpanel_product_editor_scripts', 'brikpanelPE', [
            'ajax_url'    => admin_url('admin-ajax.php'),
            'admin_url'   => admin_url(),
            'nonce'       => wp_create_nonce('brikpanel_product_editor_nonce'),
            'currency'    => get_woocommerce_currency_symbol(),
            'decimal_sep' => wc_get_price_decimal_separator(),
            'variation_gallery_enabled' => get_option('brikpanel_variation_gallery_enabled', 'yes') === 'yes' ? '1' : '0',
            'i18n'        => [
                'product_saved'  => __('Product saved!', 'brikpanel'),
                'fill_required'  => __('Please fill in the required fields', 'brikpanel'),
                'fill_name'      => __('Please fill in the product name', 'brikpanel'),
                'fill_price'     => __('Please fill in the price field', 'brikpanel'),
                'saving'         => __('Saving...', 'brikpanel'),
                'error'          => __('An error occurred. Please try again.', 'brikpanel'),
                'link_title'     => __('Insert link', 'brikpanel'),
                'link_url'       => __('URL', 'brikpanel'),
                'link_new_tab'   => __('Open in a new tab', 'brikpanel'),
                'link_insert'    => __('Insert link', 'brikpanel'),
                'link_cancel'    => __('Cancel', 'brikpanel'),
                'image_title'    => __('Insert image', 'brikpanel'),
                'image_edit_title' => __('Image settings', 'brikpanel'),
                'image_choose'   => __('Choose image', 'brikpanel'),
                'image_replace'  => __('Replace image', 'brikpanel'),
                'image_none'     => __('No image selected', 'brikpanel'),
                'image_alt'      => __('Alt text (description)', 'brikpanel'),
                'image_alt_ph'   => __('Describe the image for SEO and accessibility', 'brikpanel'),
                'image_align'    => __('Alignment', 'brikpanel'),
                'align_none'     => __('None', 'brikpanel'),
                'align_left'     => __('Left', 'brikpanel'),
                'align_center'   => __('Center', 'brikpanel'),
                'align_right'    => __('Right', 'brikpanel'),
                /* translators: image dimensions in the editor, not apparel size */
                'image_size'     => _x('Size', 'image dimensions', 'brikpanel'),
                'size_small'     => __('Small', 'brikpanel'),
                'size_medium'    => __('Medium', 'brikpanel'),
                'size_large'     => __('Large', 'brikpanel'),
                'size_full'      => __('Full', 'brikpanel'),
                'image_lightbox' => __('Open in a lightbox when clicked', 'brikpanel'),
                'image_insert'   => __('Insert image', 'brikpanel'),
                'image_update'   => __('Update image', 'brikpanel'),
                'image_required' => __('Please choose an image first.', 'brikpanel'),
                'featured'       => __('Featured', 'brikpanel'),
                'add_images'     => __('Add images', 'brikpanel'),
                'select'         => __('Select', 'brikpanel'),
                'select_image'   => __('Select image', 'brikpanel'),
                'type_enter'     => __('Type and press Enter...', 'brikpanel'),
                'type_enter_value' => __('Press Enter to add...', 'brikpanel'),
                'attribute_name' => __('Attribute name (e.g.: Material)', 'brikpanel'),
                'add_attribute'  => __('Add', 'brikpanel'),
                'category_added'   => __('Category added', 'brikpanel'),
                'brand_added'      => __('Brand added', 'brikpanel'),
                'field_required'   => __('This field is required', 'brikpanel'),
                'update'           => __('Update', 'brikpanel'),
                'view_product'     => __('View product', 'brikpanel'),
                'select_attribute' => __('Select existing attribute...', 'brikpanel'),
                'or_create_new'    => __('or create new', 'brikpanel'),
                'duplicate'        => __('Duplicate', 'brikpanel'),
                'duplicating'      => __('Duplicating...', 'brikpanel'),
                'product_title'    => __('Product title', 'brikpanel'),
                'select_images'    => __('Select images', 'brikpanel'),
                'auto_saved'       => __('Auto-saved', 'brikpanel'),
                'select_file'      => __('Select downloadable file', 'brikpanel'),
                'no_files'         => __('No files added yet.', 'brikpanel'),
                'file_name'        => __('File name', 'brikpanel'),
                'choose_file'      => __('Choose from media library', 'brikpanel'),
                'remove'           => __('Remove', 'brikpanel'),
                'tag_placeholder'  => __('Type and press Enter to add a tag...', 'brikpanel'),
                'in_stock'         => __('In stock', 'brikpanel'),
                'out_of_stock'     => __('Out of stock', 'brikpanel'),
                'on_backorder'     => __('On backorder', 'brikpanel'),
                'backorder_label'  => __('Backorder', 'brikpanel'),
                'backorder_silent' => __('Allow without notification', 'brikpanel'),
                'backorder_notify' => __('Allow and notify customer', 'brikpanel'),
                /* translators: date input hint, must stay a 4-2-2 digit mask */
                'date_placeholder' => __('YYYY-MM-DD', 'brikpanel'),
                /* translators: %d is the maximum number of variations */
                'too_many_variations' => __('That combination would create more than %d variations. Reduce the number of attribute values, then try again.', 'brikpanel'),
                'preview_failed'   => __('Could not load extra variation fields. Saving still works.', 'brikpanel'),
                'reorder_attribute' => __('Drag to reorder', 'brikpanel'),
                'cost_of_goods'    => __('Cost of goods', 'brikpanel'),
                'cogs'             => __('COGS', 'brikpanel'),
                'inherit_parent'        => __('(parent)', 'brikpanel'),
                'inherit_parent_named'  => __('(parent: %s)', 'brikpanel'),
                'add_new'          => __('Add new', 'brikpanel'),
                'publish'          => __('Publish', 'brikpanel'),
                'save'             => __('Save', 'brikpanel'),
                'schedule_start'   => __('Schedule start', 'brikpanel'),
                'schedule_end'     => __('Schedule end', 'brikpanel'),
                'schedule_hint'    => __('Optional — leave empty to keep the sale active indefinitely.', 'brikpanel'),
                'size'             => __('Size', 'brikpanel'),
                'color'            => __('Color', 'brikpanel'),
                'use_for_variations' => __('Use for variations', 'brikpanel'),
                'need_variation_attr' => __('Switch on “Use for variations” for at least one attribute, then add its values.', 'brikpanel'),
                'delete_variation' => __('Delete variation', 'brikpanel'),
                'confirm_delete_variation' => __('Delete this variation? This change is applied when you save the product.', 'brikpanel'),
                'chip_remove'      => __('Remove', 'brikpanel'),
                'more_fields'      => __('More fields', 'brikpanel'),
                'analyze'          => __('Re-analyze', 'brikpanel'),
                'analyzing'        => __('Analyzing...', 'brikpanel'),
            ],
        ]);
    }

    // Coupons List (AJAX)
    if ('admin_page_brikpanel-coupons' === $hook && get_option('brikpanel_modern_coupons', 'yes') === 'yes') {
        wp_enqueue_style(
            'brikpanel_coupons_styles',
            BRIKPANEL_URL . 'front-end/coupons/brikpanel-coupons.css',
            [],
            BRIKPANEL_VERSION . '.1'
        );

        wp_enqueue_script(
            'brikpanel_coupons_scripts',
            BRIKPANEL_URL . 'front-end/coupons/brikpanel-coupons.js',
            ['jquery'],
            BRIKPANEL_VERSION . '.1',
            true
        );

        wp_localize_script('brikpanel_coupons_scripts', 'brikpanelCP', [
            'ajax_url'        => admin_url('admin-ajax.php'),
            'nonce'           => wp_create_nonce('brikpanel_coupons_nonce'),
            'currency'        => get_woocommerce_currency_symbol(),
            'per_page'        => 20,
            'enabled_fields'  => class_exists('Brikpanel_Coupons') ? Brikpanel_Coupons::enabled_fields() : [],
            'i18n'      => [
                'no_coupons'              => __('No coupons found.', 'brikpanel'),
                'error'                   => __('An error occurred. Please try again.', 'brikpanel'),
                'saved'                   => __('Saved!', 'brikpanel'),
                'saving'                  => __('Saving...', 'brikpanel'),
                'save_changes'            => __('Save changes', 'brikpanel'),
                'create'                  => __('Create coupon', 'brikpanel'),
                'published'               => __('Published', 'brikpanel'),
                'draft'                   => __('Draft', 'brikpanel'),
                'trashed'                 => __('Trash', 'brikpanel'),
                'edit'                    => __('Edit', 'brikpanel'),
                'duplicate'               => __('Duplicate', 'brikpanel'),
                'duplicating'             => __('Duplicating...', 'brikpanel'),
                'duplicated'              => __('Coupon duplicated!', 'brikpanel'),
                'trash'                   => __('Move to trash', 'brikpanel'),
                'restore'                 => __('Restore', 'brikpanel'),
                'restored'                => __('Coupon restored!', 'brikpanel'),
                'delete_permanently'      => __('Delete permanently', 'brikpanel'),
                'confirm_delete'          => __('Are you sure you want to trash "%s"?', 'brikpanel'),
                'confirm_permanent_delete' => __('Are you sure? This cannot be undone.', 'brikpanel'),
                'confirm_bulk'            => __('Are you sure you want to update %d coupons?', 'brikpanel'),
                'confirm_bulk_trash'      => __('Are you sure you want to trash %d coupons?', 'brikpanel'),
                'bulk_updated'            => __('Coupons updated!', 'brikpanel'),
                'bulk_trashed'            => __('Coupons moved to trash!', 'brikpanel'),
                'click_to_toggle'         => __('Click to toggle status', 'brikpanel'),
                'showing'                 => __('Showing %1$d of %2$d coupons', 'brikpanel'),
                'showing_range'           => __('Showing %1$d-%2$d of %3$d coupons', 'brikpanel'),
                'add_coupon'              => __('Add coupon', 'brikpanel'),
                'edit_coupon'             => __('Edit coupon', 'brikpanel'),
                'code_required'           => __('Coupon code is required.', 'brikpanel'),
                'type_percent'            => __('Percentage', 'brikpanel'),
                'type_fixed_cart'         => __('Fixed cart', 'brikpanel'),
                'type_fixed_product'      => __('Fixed product', 'brikpanel'),
                'no_results'              => __('No matches found.', 'brikpanel'),
                'remove'                  => __('Remove', 'brikpanel'),
            ],
        ]);
    }
}
add_action('admin_enqueue_scripts', 'brikpanel_enqueue_woo_assets', 99);

// =============================================================================
// BODY CLASS FOR MODERN ORDER EDIT
// =============================================================================
function brikpanel_admin_body_class( $classes ) {
    $screen = get_current_screen();
    if ( ! $screen ) return $classes;

    $is_order_edit = (
        $screen->id === 'woocommerce_page_wc-orders'
        && isset( $_GET['action'] ) && sanitize_key( $_GET['action'] ) === 'edit'
    );

    if ( $is_order_edit && get_option( 'brikpanel_modern_order_edit', 'yes' ) !== 'no' ) {
        $classes .= ' brikpanel-modern-edit';
    }

    if ( get_option( 'brikpanel_modern_navigation', 'yes' ) === 'no' ) {
        // Modern nav off: keep the native WordPress menu either re-skinned to
        // match BrikPanel (default) or left in its original WordPress look.
        if ( get_option( 'brikpanel_native_menu_styled', 'yes' ) === 'no' ) {
            $classes .= ' brikpanel-native-nav';
        } else {
            $classes .= ' brikpanel-classic-nav';
        }
    }

    // Single shared hook for every styled product taxonomy term screen
    // (Categories, Tags, Brands, attribute terms, ...) plus the WooCommerce
    // Attributes page. The brikpanel-taxonomy.css selectors key off this
    // class so all of these screens look identical.
    $is_styled_tax_screen = (
        in_array( $screen->base, array( 'edit-tags', 'term' ), true )
        && ! empty( $screen->taxonomy )
        && in_array( $screen->taxonomy, get_object_taxonomies( 'product' ), true )
    ) || $screen->id === 'product_page_product_attributes';

    if ( $is_styled_tax_screen ) {
        $classes .= ' brikpanel-tax';
    }

    return $classes;
}
add_filter( 'admin_body_class', 'brikpanel_admin_body_class' );

// =============================================================================
// EXPENSES PAGE ASSETS
// =============================================================================
function brikpanel_enqueue_expenses_assets( $hook ) {
    // The hook prefix depends on where Expenses is registered:
    //   - toplevel_page_brikpanel-expenses     (vendor master toggle off)
    //   - vendors_page_brikpanel-expenses      (registered as a Vendors submenu)
    //   - woocommerce_page_brikpanel-expenses  (legacy WC submenu — pre-relocation)
    // Match any of them with a tail check.
    if ( strpos( (string) $hook, 'brikpanel-expenses' ) === false ) {
        return;
    }

    // filemtime-based version so any edit to the expenses assets busts the
    // browser cache immediately, even between releases (BRIKPANEL_VERSION alone
    // would serve a stale copy whenever the file changes without a version
    // bump). Matches how the dashboard / segments / search assets are versioned.
    $exp_css_ver = @filemtime( BRIKPANEL_PATH . 'front-end/expenses/brikpanel-expenses.css' ) ?: BRIKPANEL_VERSION;
    $exp_js_ver  = @filemtime( BRIKPANEL_PATH . 'front-end/expenses/brikpanel-expenses.js' ) ?: BRIKPANEL_VERSION;

    wp_enqueue_style(
        'brikpanel_expenses_styles',
        BRIKPANEL_URL . 'front-end/expenses/brikpanel-expenses.css',
        [],
        $exp_css_ver
    );

    wp_enqueue_script(
        'brikpanel_expenses_scripts',
        BRIKPANEL_URL . 'front-end/expenses/brikpanel-expenses.js',
        [],
        $exp_js_ver,
        true
    );
}
add_action( 'admin_enqueue_scripts', 'brikpanel_enqueue_expenses_assets' );

// =============================================================================
// ABANDONED CARTS PAGE ASSETS
// =============================================================================
function brikpanel_enqueue_cartab_assets( $hook ) {
    if ( strpos( (string) $hook, 'brikpanel-abandoned-carts' ) === false ) {
        return;
    }

    // filemtime-based version so asset edits bust the browser cache
    // immediately (same rationale as the expenses assets above).
    $css_ver = @filemtime( BRIKPANEL_PATH . 'front-end/cart-abandonment/cart-abandonment-admin.css' ) ?: BRIKPANEL_VERSION;
    $js_ver  = @filemtime( BRIKPANEL_PATH . 'front-end/cart-abandonment/cart-abandonment-admin.js' ) ?: BRIKPANEL_VERSION;

    wp_enqueue_style(
        'brikpanel_cartab_admin_styles',
        BRIKPANEL_URL . 'front-end/cart-abandonment/cart-abandonment-admin.css',
        [],
        $css_ver
    );

    wp_enqueue_script(
        'brikpanel_cartab_admin_scripts',
        BRIKPANEL_URL . 'front-end/cart-abandonment/cart-abandonment-admin.js',
        [],
        $js_ver,
        true
    );
}
add_action( 'admin_enqueue_scripts', 'brikpanel_enqueue_cartab_assets' );

// =============================================================================
// SCHEDULED TASKS (CRON) PAGE ASSETS
// =============================================================================
function brikpanel_enqueue_cron_assets( $hook ) {
    if ( $hook !== 'woocommerce_page_brikpanel-cron' ) {
        return;
    }

    wp_enqueue_style(
        'brikpanel_cron_styles',
        BRIKPANEL_URL . 'includes/cron/brikpanel-cron-page.css',
        [],
        BRIKPANEL_VERSION
    );

    wp_enqueue_script(
        'brikpanel_cron_scripts',
        BRIKPANEL_URL . 'includes/cron/brikpanel-cron-page.js',
        [],
        BRIKPANEL_VERSION,
        true
    );
}
add_action( 'admin_enqueue_scripts', 'brikpanel_enqueue_cron_assets' );

// =============================================================================
// RTL OVERRIDES — single stylesheet that flips directional rules on RTL sites
// =============================================================================
/**
 * Loads `assets/css/brikpanel-rtl.css` only when WordPress reports an RTL
 * locale (Arabic, Hebrew, Persian, Urdu …). All BrikPanel CSS handles use
 * the prefix `brikpanel_` so the override file is enqueued at priority 999
 * to guarantee it cascades after every page-specific stylesheet.
 *
 * Restricted to `manage_woocommerce` to avoid an extra HTTP request for
 * roles that never see the BrikPanel UI.
 */
function brikpanel_enqueue_rtl_overrides() {
    if ( ! is_rtl() ) {
        return;
    }
    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        return;
    }
    wp_enqueue_style(
        'brikpanel_rtl_overrides',
        BRIKPANEL_URL . 'assets/css/brikpanel-rtl.css',
        [],
        BRIKPANEL_VERSION . '.r2'
    );
}
add_action( 'admin_enqueue_scripts', 'brikpanel_enqueue_rtl_overrides', 999 );