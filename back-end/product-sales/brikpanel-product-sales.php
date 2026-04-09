<?php

if( !defined('ABSPATH') ) {
    exit; // Exit if accessed directly.
}

/**
 * Returns the total quantity of products sold within a given date range.
 * Only counts orders with 'wc-processing' and 'wc-completed' statuses.
 *
 * @param string|null $start_date_gmt Start date in GMT (Y-m-d H:i:s format).
 * @param string|null $end_date_gmt   End date in GMT (Y-m-d H:i:s format).
 * @return int Total product sales count.
 */
function brikpanel_get_product_sales_count( $start_date_gmt = null, $end_date_gmt = null ) {
    global $wpdb;

    $include_statuses = array('wc-processing', 'wc-completed');
    $status_placeholders = implode( ', ', array_fill( 0, count( $include_statuses ), '%s' ) );

    $query_args = $include_statuses;
    $date_column_name = '';
    $is_hpos = get_option( 'woocommerce_custom_orders_table_enabled' ) === 'yes';

    if ( $is_hpos ) {
        // HPOS enabled
        $table_orders = $wpdb->prefix . 'wc_orders';
        $table_lookup = $wpdb->prefix . 'wc_order_product_lookup';
        $date_column_name = 'o.date_created_gmt';

        $query_sql = "SELECT SUM(p.product_qty)
                      FROM {$table_lookup} p
                      INNER JOIN {$table_orders} o ON p.order_id = o.id
                      WHERE o.status IN ({$status_placeholders})";
    } else {
        // HPOS disabled (legacy)
        $date_column_name = 'p.post_date_gmt';

        $query_sql = "SELECT SUM(m1.meta_value)
                      FROM {$wpdb->posts} AS p
                      INNER JOIN {$wpdb->prefix}woocommerce_order_items AS oi ON p.ID = oi.order_id
                      INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta AS m1 ON oi.order_item_id = m1.order_item_id AND m1.meta_key = '_qty'
                      WHERE p.post_type = 'shop_order' AND p.post_status IN ({$status_placeholders})";
    }

    // Exclude orders placed by admin users
    $exclusion = brikpanel_admin_order_exclusion_sql( $is_hpos, $is_hpos ? 'o.id' : 'p.ID' );
    if ( $is_hpos ) {
        $query_sql .= str_replace( 'customer_id', 'o.customer_id', $exclusion['sql'] );
    } else {
        $query_sql .= $exclusion['sql'];
    }
    $query_args = array_merge( $query_args, $exclusion['args'] );

    if ( $start_date_gmt ) {
        $query_sql .= " AND {$date_column_name} >= %s";
        $query_args[] = $start_date_gmt;
    }
    if ( $end_date_gmt ) {
        $query_sql .= " AND {$date_column_name} <= %s";
        $query_args[] = $end_date_gmt;
    }

    $product_count = $wpdb->get_var($wpdb->prepare($query_sql, $query_args));

    return is_null($product_count) ? 0 : (int) $product_count;
}
