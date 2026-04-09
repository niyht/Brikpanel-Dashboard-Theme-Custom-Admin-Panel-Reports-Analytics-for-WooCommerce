<?php
if (!defined('ABSPATH')) { exit; }

/**
 * ANA YARDIMCI FONKSİYON
 * GÜNCELLENDİ: Sadece 'wc-processing' ve 'wc-completed' durumlarını hesaplar.
 */
function brikpanel_get_average_order_value( $start_date_gmt = null, $end_date_gmt = null ) {
    global $wpdb;

    // 1. DEĞİŞİKLİK: Sadece dahil edilecek durumlar
    $include_statuses = array('wc-processing', 'wc-completed');
    $status_placeholders = implode( ', ', array_fill( 0, count( $include_statuses ), '%s' ) );

    $query_args = $include_statuses;
    $date_column_name = '';
    $is_hpos = get_option( 'woocommerce_custom_orders_table_enabled' ) === 'yes';

    if ( $is_hpos ) {
        // HPOS AKTİF
        $table_name = $wpdb->prefix . 'wc_orders';
        $date_column_name = 'date_created_gmt';

        // 'IN' operatörü kullanılıyor
        $query_sql = "SELECT COUNT(id) as order_count, SUM(total_amount) as total_revenue
                      FROM {$table_name}
                      WHERE type = 'shop_order' AND status IN ({$status_placeholders})";
    } else {
        // HPOS AKTİF DEĞİL
        $table_name = $wpdb->posts;
        $date_column_name = 'p.post_date_gmt';

        // 'IN' operatörü kullanılıyor
        $query_sql = "SELECT COUNT(p.ID) as order_count, SUM(pm.meta_value) as total_revenue
                      FROM {$table_name} AS p
                      LEFT JOIN {$wpdb->postmeta} AS pm ON p.ID = pm.post_id
                      WHERE p.post_type = 'shop_order'
                      AND pm.meta_key = '_order_total'
                      AND p.post_status IN ({$status_placeholders})";
    }

    // Exclude orders placed by admin users
    $exclusion = brikpanel_admin_order_exclusion_sql( $is_hpos, 'p.ID' );
    $query_sql .= $exclusion['sql'];
    $query_args = array_merge( $query_args, $exclusion['args'] );

    if ( $start_date_gmt ) {
        $query_sql .= " AND {$date_column_name} >= %s";
        $query_args[] = $start_date_gmt;
    }
    if ( $end_date_gmt ) {
        $query_sql .= " AND {$date_column_name} <= %s";
        $query_args[] = $end_date_gmt;
    }

    $result = $wpdb->get_row($wpdb->prepare($query_sql, $query_args));

    if ( $result && $result->order_count > 0 ) {
        return (float) $result->total_revenue / (int) $result->order_count;
    }

    return 0.0;
}


