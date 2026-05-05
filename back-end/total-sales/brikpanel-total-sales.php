<?php
if( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * ANA YARDIMCI FONKSİYON
 * GÜNCELLENDİ: Sadece 'wc-processing' ve 'wc-completed' durumlarını toplar.
 */
function brikpanel_get_total_revenue( $start_date_gmt = null, $end_date_gmt = null, $exclude_marketplace = false ) {
    $cache_key = 'bp_rev_' . brikpanel_data_cache_ver() . '_' . md5( $start_date_gmt . $end_date_gmt . ( $exclude_marketplace ? '|nomp' : '' ) );
    $cached    = get_transient( $cache_key );
    if ( false !== $cached ) {
        return (float) $cached;
    }

    global $wpdb;

    // Sadece bu durumları dahil et
    $include_statuses = array( 'wc-processing', 'wc-completed' );
    
    // SQL için yer tutucuları hazırla (%s, %s)
    $status_placeholders = implode( ', ', array_fill( 0, count( $include_statuses ), '%s' ) );
    
    // Sorgu argümanlarını başlat
    $query_args = $include_statuses;

    $date_column_name = '';

    $is_hpos = get_option( 'woocommerce_custom_orders_table_enabled' ) === 'yes';

    if ( $is_hpos ) {
        // HPOS AKTİF (High Performance Order Storage)
        $table_name = $wpdb->prefix . 'wc_orders';
        $date_column_name = 'date_created_gmt';

        // 'IN' operatörü kullanılıyor
        $query_sql = "SELECT SUM(total_amount) FROM {$table_name} WHERE type = 'shop_order' AND status IN ({$status_placeholders})";
    } else {
        // HPOS AKTİF DEĞİL (Eski post tablosu)
        $table_name = $wpdb->posts;
        $date_column_name = 'p.post_date_gmt';

        // 'IN' operatörü kullanılıyor
        $query_sql = "SELECT SUM(pm.meta_value) FROM {$table_name} AS p
                      LEFT JOIN {$wpdb->postmeta} AS pm ON p.ID = pm.post_id
                      WHERE p.post_type = 'shop_order'
                      AND pm.meta_key = '_order_total'
                      AND p.post_status IN ({$status_placeholders})";
    }

    // Exclude orders placed by admin users
    $exclusion = brikpanel_admin_order_exclusion_sql( $is_hpos, 'p.ID' );
    $query_sql .= $exclusion['sql'];
    $query_args = array_merge( $query_args, $exclusion['args'] );

    // Optionally exclude marketplace-imported orders (BrikMarket)
    if ( $exclude_marketplace ) {
        $mp_exclusion = brikpanel_marketplace_order_exclusion_sql( $is_hpos, $is_hpos ? 'id' : 'p.ID' );
        $query_sql   .= $mp_exclusion['sql'];
        $query_args   = array_merge( $query_args, $mp_exclusion['args'] );
    }

    // Başlangıç tarihi filtresi
    if ( $start_date_gmt ) {
        $query_sql .= " AND {$date_column_name} >= %s";
        $query_args[] = $start_date_gmt;
    }
    // Bitiş tarihi filtresi
    if ( $end_date_gmt ) {
        $query_sql .= " AND {$date_column_name} <= %s";
        $query_args[] = $end_date_gmt;
    }

    $total_revenue = $wpdb->get_var($wpdb->prepare($query_sql, $query_args));
    $result = is_null($total_revenue) ? 0.0 : (float) $total_revenue;
    set_transient( $cache_key, $result, brikpanel_cache_ttl( 60 ) );
    return $result;
}


