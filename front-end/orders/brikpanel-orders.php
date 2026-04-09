<?php

if(!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// Translation-friendly tab title
add_filter('woocommerce_settings_tabs_array', function ($settings) {
    $settings['brikpanel'] = __('BrikPanel', 'brikpanel');
    return $settings;
}, 50);

// Settings page fields
function brikpanel_settings_fields() {
    return [
        [
            'name' => __('Orders List', 'brikpanel'),
            'type' => 'title',
            'id'   => 'brk_orders_title',
        ],
        [
            'name'    => __('Enhanced orders page', 'brikpanel'),
            'id'      => 'brikpanel_orders_enhancements',
            'type'    => 'checkbox',
            'desc'    => __('Enhance the orders list page with modern search, filters, overview section, and styling', 'brikpanel'),
            'default' => 'yes',
        ],
        [
            'type' => 'sectionend',
            'id'   => 'brk_orders_title',
        ],
        [
            'name' => __('Order Edit Page', 'brikpanel'),
            'type' => 'title',
            'id'   => 'brk_order_edit_title',
        ],
        [
            'name'    => __('Modern order edit', 'brikpanel'),
            'id'      => 'brikpanel_modern_order_edit',
            'type'    => 'checkbox',
            'desc'    => __('Clean up the order edit page — hide unnecessary metaboxes and apply modern styling', 'brikpanel'),
            'default' => 'yes',
        ],
        [
            'type' => 'sectionend',
            'id'   => 'brk_order_edit_title',
        ],
        [
            'name' => __('Products', 'brikpanel'),
            'type' => 'title',
            'id'   => 'brk_products_title',
        ],
        [
            'name'    => __('Simplified product editor', 'brikpanel'),
            'id'      => 'brikpanel_simple_product_editor',
            'type'    => 'checkbox',
            'desc'    => __('Replace the default WooCommerce product editor with a simplified, easy-to-use interface', 'brikpanel'),
            'default' => 'yes',
        ],
        [
            'name'    => __('Modern products list', 'brikpanel'),
            'id'      => 'brikpanel_modern_products_list',
            'type'    => 'checkbox',
            'desc'    => __('Replace the default products list with a modern, AJAX-powered interface with inline editing', 'brikpanel'),
            'default' => 'yes',
        ],
        [
            'name'              => __('Products per page', 'brikpanel'),
            'id'                => 'brikpanel_products_per_page',
            'type'              => 'number',
            'desc'              => __('Number of products to display per page in the products list', 'brikpanel'),
            'default'           => '20',
            'css'               => 'width: 80px;',
            'custom_attributes' => [
                'min'  => '5',
                'max'  => '100',
                'step' => '1',
            ],
        ],
        [
            'name'              => __('Categories per page', 'brikpanel'),
            'id'                => 'brikpanel_categories_per_page',
            'type'              => 'number',
            'desc'              => __('Number of categories to display per page', 'brikpanel'),
            'default'           => '20',
            'css'               => 'width: 80px;',
            'custom_attributes' => [
                'min'  => '5',
                'max'  => '200',
                'step' => '1',
            ],
        ],
        [
            'type' => 'sectionend',
            'id'   => 'brk_products_title',
        ],
        [
            'name' => __('Dashboard', 'brikpanel'),
            'type' => 'title',
            'id'   => 'brk_dashboard_title',
        ],
        [
            'name'    => __('Modern dashboard', 'brikpanel'),
            'id'      => 'brikpanel_modern_dashboard',
            'type'    => 'checkbox',
            'desc'    => __('Replace the default WordPress dashboard with a modern, Shopify-inspired analytics dashboard', 'brikpanel'),
            'default' => 'yes',
        ],
        [
            'type' => 'sectionend',
            'id'   => 'brk_dashboard_title',
        ],
        [
            'name' => __('Coupons', 'brikpanel'),
            'type' => 'title',
            'id'   => 'brk_coupons_title',
        ],
        [
            'name'    => __('Modern coupons page', 'brikpanel'),
            'id'      => 'brikpanel_modern_coupons',
            'type'    => 'checkbox',
            'desc'    => __('Replace the default coupons list with a modern, AJAX-powered interface', 'brikpanel'),
            'default' => 'yes',
        ],
        [
            'type' => 'sectionend',
            'id'   => 'brk_coupons_title',
        ],
    ];
}

// Tab content
add_action('woocommerce_settings_tabs_brikpanel', function () {
    woocommerce_admin_fields(brikpanel_settings_fields());
});

// Update tab settings
add_action('woocommerce_update_options_brikpanel', function () {
    woocommerce_update_options(brikpanel_settings_fields());
});

// Custom order statuses are registered in the main brikpanel.php file
// so they are available everywhere (not just admin context).

// ── Additional Columns ────────────────────────────────────────────────
$is_hpos = get_option('woocommerce_custom_orders_table_enabled') === 'yes';

if ($is_hpos) {
    add_filter('manage_woocommerce_page_wc-orders_columns', 'brikpanel_set_order_columns', 20);
    add_action('manage_woocommerce_page_wc-orders_custom_column', 'brikpanel_fill_order_column', 20, 2);
} else {
    add_filter('manage_edit-shop_order_columns', 'brikpanel_set_order_columns', 20);
    add_action('manage_shop_order_posts_custom_column', 'brikpanel_fill_order_column_legacy', 20, 2);
}

function brikpanel_set_order_columns($columns) {
    $columns['payment_method'] = __('Payment Method', 'brikpanel');
    $columns['order_items']    = __('Items', 'brikpanel');
    $columns['tax_total']      = __('Tax Total', 'brikpanel');
    return $columns;
}

function brikpanel_fill_order_column($column, $order) {
    brikpanel_fill_order_column_content($column, $order);
}

function brikpanel_fill_order_column_legacy($column, $post_id) {
    $order = wc_get_order($post_id);
    if (!$order) return;
    brikpanel_fill_order_column_content($column, $order);
}

function brikpanel_fill_order_column_content($column, $order) {
    switch ($column) {
        case 'payment_method':
            echo esc_html($order->get_payment_method_title() ?? '—');
            break;

        case 'order_items':
            foreach ($order->get_items() as $item) {
                echo esc_html($item->get_name() ?? '') . ' x ' . esc_html($item->get_quantity()) . '<br>';
            }
            break;

        case 'tax_total':
            echo wp_kses_post( wc_price( $order->get_total_tax() ) );
            break;
    }
}

// ── AJAX: Inline Order Status Change ──────────────────────────────────
add_action('wp_ajax_brikpanel_change_order_status', function () {
    check_ajax_referer('brikpanel_order_status_nonce', '_ajax_nonce');

    if (!current_user_can('edit_shop_orders')) {
        wp_send_json_error(['message' => __('Permission denied.', 'brikpanel')], 403);
    }

    $order_id   = absint($_POST['order_id'] ?? 0);
    $new_status = sanitize_key($_POST['new_status'] ?? '');

    if (!$order_id || !$new_status) {
        wp_send_json_error(['message' => __('Invalid request.', 'brikpanel')]);
    }

    $order = wc_get_order($order_id);
    if (!$order) {
        wp_send_json_error(['message' => __('Order not found.', 'brikpanel')]);
    }

    $valid_statuses = array_keys(wc_get_order_statuses());
    $new_status_key = (strpos($new_status, 'wc-') === 0) ? $new_status : 'wc-' . $new_status;
    if (!in_array($new_status_key, $valid_statuses)) {
        wp_send_json_error(['message' => __('Invalid status.', 'brikpanel')]);
    }

    $slug = str_replace('wc-', '', $new_status_key);
    $order->update_status($slug);

    $statuses = wc_get_order_statuses();
    $label    = $statuses[$new_status_key] ?? $slug;

    wp_send_json_success([
        'status' => $slug,
        'label'  => $label,
    ]);
});

