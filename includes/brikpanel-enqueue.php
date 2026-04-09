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


// =============================================================================
// CUSTOM DASHBOARD PAGE ASSETS
// =============================================================================
function brikpanel_enqueue_custom_dashboard_assets($hook) {
    if ('admin_page_brikpanel-dashboard' !== $hook) {
        return;
    }

    // Flatpickr
    wp_enqueue_style(
        'brikpanel_flatpickr_styles',
        BRIKPANEL_URL . 'assets/css/flatpickr.min.css',
        [],
        BRIKPANEL_VERSION
    );

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

    // Dashboard styles
    wp_enqueue_style(
        'brikpanel_dashboard_styles',
        BRIKPANEL_URL . 'front-end/dashboard/brikpanel-dashboard.css',
        [],
        BRIKPANEL_VERSION
    );

    // Dashboard scripts
    wp_enqueue_script(
        'brikpanel_dashboard_scripts',
        BRIKPANEL_URL . 'front-end/dashboard/brikpanel-dashboard.js',
        [ 'flatpickr-js', 'chart-js', 'cobe-globe' ],
        BRIKPANEL_VERSION,
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
        'currency' => function_exists('get_woocommerce_currency_symbol') ? get_woocommerce_currency_symbol() : '$',
        'i18n'     => [
            'revenue'       => __('Revenue', 'brikpanel'),
            'orders'        => __('Orders', 'brikpanel'),
            'visitors'      => __('Visitors', 'brikpanel'),
            'product_views' => __('Product Views', 'brikpanel'),
            'add_to_cart'   => __('Add to Cart', 'brikpanel'),
            'checkout'      => __('Checkout', 'brikpanel'),
            'successful'    => __('Successful', 'brikpanel'),
            'failed'        => __('Failed', 'brikpanel'),
            'refunded'      => __('Refunded', 'brikpanel'),
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
        ],
    ]);
}
add_action('admin_enqueue_scripts', 'brikpanel_enqueue_custom_dashboard_assets');

// =============================================================================
// GLOBAL ADMIN STYLES & SCRIPTS
// =============================================================================
function brikpanel_enqueue_global_assets() {
    
    // -----------------------------------------
    // FREE - always load
    // -----------------------------------------
    wp_enqueue_script(
        'brikpanel_search_scripts',
        BRIKPANEL_URL . 'front-end/search/brikpanel-search.js',
        [],
        BRIKPANEL_VERSION,
        true
    );
    wp_localize_script('brikpanel_search_scripts', 'brikpanelSearchAjax', [
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce'    => wp_create_nonce('brikpanel_search_action'),
        'i18n'     => [
            'hint_text' => __('You can search orders by customer name, email, phone, order ID, or product SKUs within an order.', 'brikpanel'),
        ],
    ]);

    wp_enqueue_style(
        'brikpanel_navigation_styles',
        BRIKPANEL_URL . 'front-end/navigation/brikpanel-navigation.css',
        [],
        BRIKPANEL_VERSION
    );

    wp_enqueue_style(
        'brikpanel_search_styles',
        BRIKPANEL_URL . 'front-end/search/brikpanel-search.css',
        [],
        BRIKPANEL_VERSION
    );

}
add_action('admin_enqueue_scripts', 'brikpanel_enqueue_global_assets');

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
            ],
        ]);

        // ── Order edit page assets (styles + JS) ──────────────────────
        if ( $is_order_edit && get_option( 'brikpanel_modern_order_edit', 'yes' ) !== 'no' ) {
            wp_enqueue_style(
                'brikpanel_order_styles',
                BRIKPANEL_URL . 'front-end/order/brikpanel-order.css',
                ['woocommerce_admin_styles'],
                BRIKPANEL_VERSION
            );
            $order_id = absint( $_GET['id'] ?? 0 );
            $order    = $order_id ? wc_get_order( $order_id ) : null;

            wp_enqueue_script(
                'brikpanel_order_edit',
                BRIKPANEL_URL . 'front-end/order/brikpanel-order.js',
                [],
                BRIKPANEL_VERSION,
                true
            );

            $current_status = $order ? $order->get_status() : '';
            $all_statuses   = wc_get_order_statuses();
            $status_label   = $order ? ( $all_statuses[ 'wc-' . $current_status ] ?? $current_status ) : '';

            wp_localize_script( 'brikpanel_order_edit', 'brikpanelOrderEdit', [
                'ajax_url'       => admin_url( 'admin-ajax.php' ),
                'nonce'          => wp_create_nonce( 'brikpanel_order_status_nonce' ),
                'order_id'       => $order_id,
                'current_status' => $current_status,
                'status_label'   => $status_label,
                'order_date'     => ($order && $order->get_date_created()) ? $order->get_date_created()->date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) : '',
                'statuses'       => $all_statuses,
                'orders_url'     => admin_url( 'admin.php?page=wc-orders' ),
                'i18n'           => [
                    'orders'         => __( 'Orders', 'brikpanel' ),
                    'save'           => __( 'Save', 'brikpanel' ),
                    'copy'           => __( 'Copy', 'brikpanel' ),
                    'copied'         => __( 'Copied!', 'brikpanel' ),
                    'address_copied' => __( 'Address copied to clipboard', 'brikpanel' ),
                    'status_changed' => __( 'Status changed to %s', 'brikpanel' ),
                    'note_added'     => __( 'Note added', 'brikpanel' ),
                    'error'          => __( 'An error occurred. Please try again.', 'brikpanel' ),
                ],
            ] );
        }

        // ── Enhanced orders page (conditional, skip on edit page) ──
        if (!$is_order_edit && get_option('brikpanel_orders_enhancements', 'yes') !== 'no') {
            wp_enqueue_script(
                'brikpanel_orders_scripts',
                BRIKPANEL_URL . 'front-end/orders/brikpanel-orders.js',
                ['jquery', 'wc-enhanced-select'],
                BRIKPANEL_VERSION,
                true
            );

            wp_enqueue_style(
                'brikpanel_orders_styles',
                BRIKPANEL_URL . 'front-end/orders/brikpanel-orders.css',
                ['woocommerce_admin_styles'],
                BRIKPANEL_VERSION
            );

            wp_localize_script( 'brikpanel_orders_scripts', 'brikpanelOrdersOverview', [
                'ajax_url' => admin_url( 'admin-ajax.php' ),
                'nonce'    => wp_create_nonce( 'brikpanel_nonce_action' ),
                'i18n'     => [
                    'last_30_days' => __( 'Last 30 days', 'brikpanel' ),
                    'orders'       => __( 'Orders', 'brikpanel' ),
                    'completed'    => __( 'Completed', 'brikpanel' ),
                    'refunded'     => __( 'Refunded', 'brikpanel' ),
                    'cancelled'    => __( 'Cancelled', 'brikpanel' ),
                    'revenue'      => __( 'Revenue', 'brikpanel' ),
                    'marketplaces' => __( 'Marketplaces', 'brikpanel' ),
                    'products'     => __( 'products', 'brikpanel' ),
                    'orders_low'   => __( 'orders', 'brikpanel' ),
                    'show_all'     => __( 'Show all', 'brikpanel' ),
                    'show_less'    => __( 'Show less', 'brikpanel' ),
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

    if ($is_product_taxonomy || $is_attributes_page) {
        wp_enqueue_style(
            'brikpanel_taxonomy_styles',
            BRIKPANEL_URL . 'front-end/products/brikpanel-taxonomy.css',
            [],
            BRIKPANEL_VERSION . '.2'
        );
    }

    // Category enhancements JS (drag-drop nesting, search reposition)
    if ($is_product_taxonomy) {
        $tax = sanitize_key($_GET['taxonomy'] ?? 'product_cat');
        wp_enqueue_script(
            'brikpanel_category_enhancements',
            BRIKPANEL_URL . 'front-end/products/brikpanel-category-enhancements.js',
            ['jquery'],
            BRIKPANEL_VERSION . '.1',
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
        wp_enqueue_style(
            'brikpanel_products_list_styles',
            BRIKPANEL_URL . 'front-end/products/brikpanel-products-list.css',
            [],
            BRIKPANEL_VERSION
        );

        wp_enqueue_script(
            'brikpanel_products_list_scripts',
            BRIKPANEL_URL . 'front-end/products/brikpanel-products-list.js',
            ['jquery'],
            BRIKPANEL_VERSION,
            true
        );

        $per_page = get_option('brikpanel_products_per_page', 20);

        wp_localize_script('brikpanel_products_list_scripts', 'brikpanelPL', [
            'ajax_url'  => admin_url('admin-ajax.php'),
            'nonce'     => wp_create_nonce('brikpanel_products_list_nonce'),
            'nonce_pe'  => wp_create_nonce('brikpanel_product_editor_nonce'),
            'currency'  => get_woocommerce_currency_symbol(),
            'per_page'  => (int) $per_page,
            'i18n'     => [
                'no_products'         => __('No products found.', 'brikpanel'),
                'error'               => __('An error occurred. Please try again.', 'brikpanel'),
                'saved'               => __('Saved!', 'brikpanel'),
                'saving'              => __('Saving...', 'brikpanel'),
                'save_changes'        => __('Save changes', 'brikpanel'),
                'published'           => __('Published', 'brikpanel'),
                'draft'               => __('Draft', 'brikpanel'),
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
                'click_to_toggle'     => __('Click to toggle status', 'brikpanel'),
                'showing'             => __('Showing %1$d of %2$d products', 'brikpanel'),
                'showing_range'       => __('Showing %1$d–%2$d of %3$d products', 'brikpanel'),
                'bulk_confirm'        => __('Apply this action to the selected products? This cannot be undone.', 'brikpanel'),
                'bulk_cat_confirm'    => __('Apply this action to all products in the selected category? This cannot be undone.', 'brikpanel'),
                'bulk_select_cat'     => __('Please select a category.', 'brikpanel'),
                'bulk_no_selection'   => __('No products selected. Select products from the table first.', 'brikpanel'),
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
                'price_placeholder'   => __('0', 'brikpanel'),
                'sale_placeholder'    => __('Sale', 'brikpanel'),
                'no_variations'       => __('No variations found.', 'brikpanel'),
                'view'                => __('View', 'brikpanel'),
                'cancel'              => __('Cancel', 'brikpanel'),
                'delete_confirm_1'    => __('Are you sure you want to delete these products?', 'brikpanel'),
                'delete_confirm_all'  => __('Are you sure you want to delete ALL products in the store? This is extremely dangerous!', 'brikpanel'),
                'delete_confirm_2'    => __('PERMANENT DELETE — This cannot be undone. Are you absolutely sure?', 'brikpanel'),
            ],
        ]);
    }

    // Simplified Product Editor
    if ('admin_page_brikpanel-product-editor' === $hook && get_option('brikpanel_simple_product_editor', 'yes') === 'yes') {
        wp_enqueue_media();
        wp_enqueue_script('jquery-ui-sortable');

        wp_enqueue_style(
            'brikpanel_product_editor_styles',
            BRIKPANEL_URL . 'front-end/products/brikpanel-product-editor.css',
            [],
            BRIKPANEL_VERSION
        );

        wp_enqueue_script(
            'brikpanel_product_editor_scripts',
            BRIKPANEL_URL . 'front-end/products/brikpanel-product-editor.js',
            ['jquery', 'jquery-ui-sortable'],
            BRIKPANEL_VERSION,
            true
        );

        wp_localize_script('brikpanel_product_editor_scripts', 'brikpanelPE', [
            'ajax_url'    => admin_url('admin-ajax.php'),
            'admin_url'   => admin_url(),
            'nonce'       => wp_create_nonce('brikpanel_product_editor_nonce'),
            'currency'    => get_woocommerce_currency_symbol(),
            'decimal_sep' => wc_get_price_decimal_separator(),
            'i18n'        => [
                'product_saved'  => __('Product saved!', 'brikpanel'),
                'fill_required'  => __('Please fill in the required fields', 'brikpanel'),
                'fill_name'      => __('Please fill in the product name', 'brikpanel'),
                'fill_price'     => __('Please fill in the price field', 'brikpanel'),
                'saving'         => __('Saving...', 'brikpanel'),
                'error'          => __('An error occurred. Please try again.', 'brikpanel'),
                'featured'       => __('Featured', 'brikpanel'),
                'add_images'     => __('Add images', 'brikpanel'),
                'select'         => __('Select', 'brikpanel'),
                'select_image'   => __('Select image', 'brikpanel'),
                'type_enter'     => __('Type and press Enter...', 'brikpanel'),
                'attribute_name' => __('Attribute name (e.g.: Material)', 'brikpanel'),
                'add_attribute'  => __('Add', 'brikpanel'),
                'category_added'   => __('Category added', 'brikpanel'),
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
            ],
        ]);
    }

    // Coupons List (AJAX)
    if ('admin_page_brikpanel-coupons' === $hook && get_option('brikpanel_modern_coupons', 'yes') === 'yes') {
        wp_enqueue_style(
            'brikpanel_coupons_styles',
            BRIKPANEL_URL . 'front-end/coupons/brikpanel-coupons.css',
            [],
            BRIKPANEL_VERSION
        );

        wp_enqueue_script(
            'brikpanel_coupons_scripts',
            BRIKPANEL_URL . 'front-end/coupons/brikpanel-coupons.js',
            ['jquery'],
            BRIKPANEL_VERSION,
            true
        );

        wp_localize_script('brikpanel_coupons_scripts', 'brikpanelCP', [
            'ajax_url'  => admin_url('admin-ajax.php'),
            'nonce'     => wp_create_nonce('brikpanel_coupons_nonce'),
            'currency'  => get_woocommerce_currency_symbol(),
            'per_page'  => 20,
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

    return $classes;
}
add_filter( 'admin_body_class', 'brikpanel_admin_body_class' );