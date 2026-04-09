<?php

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Returns the count of successful orders (processing + completed) within a given date range.
 *
 * @param string|null $start_date_gmt Start date in GMT (Y-m-d H:i:s format).
 * @param string|null $end_date_gmt   End date in GMT (Y-m-d H:i:s format).
 * @return int Successful order count.
 */
function brikpanel_get_successful_order_count($start_date_gmt = null, $end_date_gmt = null) {
    global $wpdb;

    $include_statuses = ['wc-processing', 'wc-completed'];

    $status_placeholders = implode(', ', array_fill(0, count($include_statuses), '%s'));
    $query_args = $include_statuses;

    $date_column_name = '';
    $is_hpos = get_option('woocommerce_custom_orders_table_enabled') === 'yes';

    if ($is_hpos) {
        // HPOS enabled
        $table_name = $wpdb->prefix . 'wc_orders';
        $date_column_name = 'date_created_gmt';
        $query_sql = "SELECT COUNT(id) FROM {$table_name} WHERE type = 'shop_order' AND status IN ({$status_placeholders})";
    } else {
        // HPOS disabled (legacy)
        $table_name = $wpdb->posts;
        $date_column_name = 'post_date_gmt';
        $query_sql = "SELECT COUNT(ID) FROM {$table_name} WHERE post_type = 'shop_order' AND post_status IN ({$status_placeholders})";
    }

    // Exclude orders placed by admin users
    $exclusion = brikpanel_admin_order_exclusion_sql( $is_hpos, 'ID' );
    $query_sql .= $exclusion['sql'];
    $query_args = array_merge( $query_args, $exclusion['args'] );

    if ($start_date_gmt) {
        $query_sql .= " AND {$date_column_name} >= %s";
        $query_args[] = $start_date_gmt;
    }
    if ($end_date_gmt) {
        $query_sql .= " AND {$date_column_name} <= %s";
        $query_args[] = $end_date_gmt;
    }

    $result = $wpdb->get_var($wpdb->prepare($query_sql, $query_args));
    return is_null($result) ? 0 : (int) $result;
}

/**
 * Returns the count of orders matching specific statuses within a given date range.
 * Used for cancelled, refunded, failed, etc.
 *
 * @param array       $statuses       Array of order statuses (e.g. ['wc-cancelled', 'wc-failed']).
 * @param string|null $start_date_gmt Start date in GMT (Y-m-d H:i:s format).
 * @param string|null $end_date_gmt   End date in GMT (Y-m-d H:i:s format).
 * @return int Order count matching the given statuses.
 */
function brikpanel_get_order_count_by_status($statuses = [], $start_date_gmt = null, $end_date_gmt = null) {
    global $wpdb;

    if (empty($statuses)) {
        return 0;
    }

    $status_placeholders = implode(', ', array_fill(0, count($statuses), '%s'));
    $query_args = $statuses;
    $date_column_name = '';
    $is_hpos = get_option('woocommerce_custom_orders_table_enabled') === 'yes';

    if ($is_hpos) {
        // HPOS enabled
        $date_column_name = 'date_created_gmt';
        $query_sql = "SELECT COUNT(id) FROM {$wpdb->prefix}wc_orders WHERE status IN ({$status_placeholders})";
    } else {
        // HPOS disabled (legacy)
        $date_column_name = 'post_date_gmt';
        $query_sql = "SELECT COUNT(ID) FROM {$wpdb->posts} WHERE post_type = 'shop_order' AND post_status IN ({$status_placeholders})";
    }

    // Exclude orders placed by admin users
    $exclusion = brikpanel_admin_order_exclusion_sql( $is_hpos, 'ID' );
    $query_sql .= $exclusion['sql'];
    $query_args = array_merge( $query_args, $exclusion['args'] );

    if ($start_date_gmt) {
        $query_sql .= " AND {$date_column_name} >= %s";
        $query_args[] = $start_date_gmt;
    }
    if ($end_date_gmt) {
        $query_sql .= " AND {$date_column_name} <= %s";
        $query_args[] = $end_date_gmt;
    }

    $order_count = $wpdb->get_var($wpdb->prepare($query_sql, $query_args));

    return is_null($order_count) ? 0 : (int)$order_count;
}

/**
 * Returns the total order count excluding trash and auto-draft statuses.
 *
 * @param string|null $start_date_gmt Start date in GMT (Y-m-d H:i:s format).
 * @param string|null $end_date_gmt   End date in GMT (Y-m-d H:i:s format).
 * @return int Total order count.
 */
function brikpanel_get_total_orders_count($start_date_gmt = null, $end_date_gmt = null) {
    global $wpdb;

    $exclude_statuses = ['trash', 'auto-draft'];
    $status_placeholders = implode(', ', array_fill(0, count($exclude_statuses), '%s'));
    $query_args = $exclude_statuses;

    $date_column_name = '';
    $is_hpos = get_option('woocommerce_custom_orders_table_enabled') === 'yes';

    if ($is_hpos) {
        // HPOS enabled
        $date_column_name = 'date_created_gmt';
        $query_sql = "SELECT COUNT(id) FROM {$wpdb->prefix}wc_orders WHERE type = 'shop_order' AND status NOT IN ({$status_placeholders})";
    } else {
        // HPOS disabled (legacy)
        $date_column_name = 'post_date_gmt';
        $query_sql = "SELECT COUNT(ID) FROM {$wpdb->posts} WHERE post_type = 'shop_order' AND post_status NOT IN ({$status_placeholders})";
    }

    // Exclude orders placed by admin users
    $exclusion = brikpanel_admin_order_exclusion_sql( $is_hpos, 'ID' );
    $query_sql .= $exclusion['sql'];
    $query_args = array_merge( $query_args, $exclusion['args'] );

    if ($start_date_gmt) {
        $query_sql .= " AND {$date_column_name} >= %s";
        $query_args[] = $start_date_gmt;
    }
    if ($end_date_gmt) {
        $query_sql .= " AND {$date_column_name} <= %s";
        $query_args[] = $end_date_gmt;
    }

    $total_count = $wpdb->get_var($wpdb->prepare($query_sql, $query_args));

    return is_null($total_count) ? 0 : (int)$total_count;
}
