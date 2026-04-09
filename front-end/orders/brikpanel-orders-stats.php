<?php
/**
 * BrikPanel - Orders Overview Stats
 *
 * AJAX endpoint for 30-day order summary and BrikMarket marketplace stats.
 *
 * @package BrikPanel
 * @since 1.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'wp_ajax_brikpanel_orders_overview', 'brikpanel_orders_overview_handler' );

/**
 * AJAX handler for orders overview data.
 */
function brikpanel_orders_overview_handler() {
    check_ajax_referer( 'brikpanel_nonce_action' );

    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        wp_send_json_error( [ 'message' => 'Unauthorized' ] );
    }

    $data = [
        'summary'      => brikpanel_get_30day_summary(),
        'marketplaces' => brikpanel_get_marketplace_stats(),
    ];

    wp_send_json_success( $data );
}

/**
 * Get order summary for the last 30 days.
 *
 * @return array
 */
function brikpanel_get_30day_summary() {
    global $wpdb;

    $date_30 = gmdate( 'Y-m-d H:i:s', strtotime( '-30 days' ) );
    $is_hpos = get_option( 'woocommerce_custom_orders_table_enabled' ) === 'yes';

    if ( $is_hpos ) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $results = $wpdb->get_results( $wpdb->prepare(
            "SELECT status, COUNT(*) AS cnt, COALESCE(SUM(total_amount), 0) AS revenue
             FROM {$wpdb->prefix}wc_orders
             WHERE date_created_gmt >= %s
               AND type = 'shop_order'
             GROUP BY status",
            $date_30
        ) );
    } else {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $results = $wpdb->get_results( $wpdb->prepare(
            "SELECT p.post_status AS status, COUNT(*) AS cnt,
                    COALESCE(SUM(CAST(pm.meta_value AS DECIMAL(10,2))), 0) AS revenue
             FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_order_total'
             WHERE p.post_date_gmt >= %s
               AND p.post_type = 'shop_order'
             GROUP BY p.post_status",
            $date_30
        ) );
    }

    $summary = [
        'total'     => 0,
        'completed' => 0,
        'refunded'  => 0,
        'cancelled' => 0,
        'revenue'   => 0,
    ];

    $countable_statuses  = [ 'wc-completed', 'wc-processing', 'wc-on-hold', 'wc-pending', 'wc-refunded', 'wc-cancelled', 'wc-failed' ];
    $successful_statuses = [ 'wc-completed', 'wc-processing' ];

    foreach ( $results as $row ) {
        $status = $row->status;

        if ( ! in_array( $status, $countable_statuses, true ) ) {
            continue;
        }

        $summary['total'] += (int) $row->cnt;

        if ( in_array( $status, $successful_statuses, true ) ) {
            $summary['completed'] += (int) $row->cnt;
            $summary['revenue']   += (float) $row->revenue;
        }

        if ( 'wc-refunded' === $status ) {
            $summary['refunded'] += (int) $row->cnt;
        }

        if ( 'wc-cancelled' === $status ) {
            $summary['cancelled'] += (int) $row->cnt;
        }
    }

    $summary['revenue_formatted'] = wp_strip_all_tags( wc_price( $summary['revenue'] ) );

    return $summary;
}

/**
 * Get marketplace stats if BrikMarket is active.
 *
 * @return array|null Null if BrikMarket is not active.
 */
function brikpanel_get_marketplace_stats() {
    if ( ! class_exists( 'BrikMarket_Marketplace_Registry' ) ) {
        return null;
    }

    $active = BrikMarket_Marketplace_Registry::get_active();
    if ( empty( $active ) ) {
        return null;
    }

    global $wpdb;

    $date_30 = gmdate( 'Y-m-d H:i:s', strtotime( '-30 days' ) );
    $is_hpos = get_option( 'woocommerce_custom_orders_table_enabled' ) === 'yes';

    // Product counts per marketplace.
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $product_rows = $wpdb->get_results(
        "SELECT marketplace_id, COUNT(DISTINCT wc_product_id) AS cnt
         FROM {$wpdb->prefix}brksoft_product_map
         GROUP BY marketplace_id",
        OBJECT_K
    );

    // Order counts and revenue per marketplace (last 30 days).
    if ( $is_hpos ) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $order_rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT om.marketplace_id,
                    COUNT(*) AS order_count,
                    COALESCE(SUM(o.total_amount), 0) AS revenue
             FROM {$wpdb->prefix}brksoft_order_map om
             INNER JOIN {$wpdb->prefix}wc_orders o ON om.wc_order_id = o.id
             WHERE o.date_created_gmt >= %s
               AND o.type = 'shop_order'
               AND o.status NOT IN ('wc-cancelled','wc-refunded','wc-failed','trash')
             GROUP BY om.marketplace_id",
            $date_30
        ), OBJECT_K );
    } else {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $order_rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT om.marketplace_id,
                    COUNT(*) AS order_count,
                    COALESCE(SUM(CAST(pm.meta_value AS DECIMAL(10,2))), 0) AS revenue
             FROM {$wpdb->prefix}brksoft_order_map om
             INNER JOIN {$wpdb->posts} p ON om.wc_order_id = p.ID
             LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_order_total'
             WHERE p.post_date_gmt >= %s
               AND p.post_type = 'shop_order'
               AND p.post_status NOT IN ('wc-cancelled','wc-refunded','wc-failed','trash')
             GROUP BY om.marketplace_id",
            $date_30
        ), OBJECT_K );
    }

    $marketplace_data = [];

    foreach ( $active as $id => $marketplace ) {
        $products = isset( $product_rows[ $id ] ) ? (int) $product_rows[ $id ]->cnt : 0;
        $orders   = isset( $order_rows[ $id ] ) ? (int) $order_rows[ $id ]->order_count : 0;
        $revenue  = isset( $order_rows[ $id ] ) ? (float) $order_rows[ $id ]->revenue : 0;

        $marketplace_data[] = [
            'id'          => $id,
            'name'        => $marketplace->get_name(),
            'logo'        => $marketplace->get_logo_url(),
            'products'    => $products,
            'orders'      => $orders,
            'revenue'     => wp_strip_all_tags( wc_price( $revenue ) ),
            'revenue_raw' => $revenue,
        ];
    }

    return $marketplace_data;
}
