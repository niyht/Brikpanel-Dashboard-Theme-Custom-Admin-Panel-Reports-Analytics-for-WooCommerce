<?php
if( ! defined( 'ABSPATH' ) ) exit;

/**
 * Returns the count of orders that count as realised sales within a given
 * date range. Statuses come from brikpanel_kpi_revenue_statuses() — default
 * processing + completed, extensible via the brikpanel_kpi_statuses filter
 * (e.g. to include wc-on-hold for offline-payment-heavy merchants).
 *
 * @param string|null $start_date_gmt Start date in GMT (Y-m-d H:i:s format).
 * @param string|null $end_date_gmt   End date in GMT (Y-m-d H:i:s format).
 * @return int Order count.
 */
function brikpanel_get_order_count( $start_date_gmt = null, $end_date_gmt = null, $exclude_marketplace = false ) {
    global $wpdb;

    $include_statuses = brikpanel_kpi_revenue_statuses();

    $status_placeholders = implode( ', ', array_fill( 0, count( $include_statuses ), '%s' ) );
    $query_args = $include_statuses;

    $date_column_name = '';
    $is_hpos = get_option( 'woocommerce_custom_orders_table_enabled' ) === 'yes';

    if ( $is_hpos ) {
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

    if ( $exclude_marketplace ) {
        $mp_exclusion = brikpanel_marketplace_order_exclusion_sql( $is_hpos );
        $query_sql   .= $mp_exclusion['sql'];
        $query_args   = array_merge( $query_args, $mp_exclusion['args'] );
    }

    if ( $start_date_gmt ) {
        $query_sql .= " AND {$date_column_name} >= %s";
        $query_args[] = $start_date_gmt;
    }
    if ( $end_date_gmt ) {
        $query_sql .= " AND {$date_column_name} <= %s";
        $query_args[] = $end_date_gmt;
    }

    $query = $wpdb->prepare($query_sql, $query_args);
    return (int) $wpdb->get_var($query);
}
