<?php
/**
 * BrikPanel - Orders Overview Stats
 *
 * AJAX endpoint for the orders overview cards (Total orders, Completed,
 * Refunded, Revenue) with per-metric sparkline series over a selectable date
 * range, plus BrikMarket marketplace stats.
 *
 * @package BrikPanel
 * @since 1.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'wp_ajax_brikpanel_orders_overview', 'brikpanel_orders_overview_handler' );

/**
 * Allowed overview date ranges.
 *
 * @return string[]
 */
function brikpanel_overview_ranges() {
    return [ 'today', '24h', '7d', '30d' ];
}

/**
 * AJAX handler for orders overview data.
 */
function brikpanel_orders_overview_handler() {
    check_ajax_referer( 'brikpanel_nonce_action' );

    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        wp_send_json_error( [ 'message' => 'Unauthorized' ] );
    }

    // Defense in depth: a user whose role hides the overview must not be able to
    // read store-wide totals by calling this endpoint directly.
    if ( function_exists( 'brikpanel_orders_overview_hidden_for_user' )
        && brikpanel_orders_overview_hidden_for_user() ) {
        wp_send_json_error( [ 'message' => 'Unauthorized' ] );
    }

    $range = isset( $_POST['range'] ) ? sanitize_key( wp_unslash( $_POST['range'] ) ) : '30d';
    if ( ! in_array( $range, brikpanel_overview_ranges(), true ) ) {
        $range = '30d';
    }

    $config = brikpanel_overview_range_config( $range );

    $data = [
        'range'        => $range,
        'summary'      => brikpanel_get_range_summary( $config ),
        'marketplaces' => brikpanel_get_marketplace_stats( $config['start_gmt'] ),
    ];

    wp_send_json_success( $data );
}

/**
 * Build the bucketing configuration for a given range.
 *
 * Returns the ordered list of time-bucket keys, the MySQL DATE_FORMAT mask used
 * to map a row's local creation time onto a bucket, the numeric timezone offset
 * passed to CONVERT_TZ, and the GMT window start used in the WHERE clause.
 *
 * Bucket keys are generated in the site timezone so day/hour boundaries match
 * what the merchant sees. The SQL side uses a fixed numeric offset (no named
 * timezone tables required); across a DST transition this can misplace at most
 * one bucket, which is acceptable for a sparkline.
 *
 * @param string $range One of brikpanel_overview_ranges().
 * @return array{keys:string[],php_fmt:string,mysql_fmt:string,offset:string,start_gmt:string}
 */
function brikpanel_overview_range_config( $range ) {
    $tz  = wp_timezone();
    $now = new DateTime( 'now', $tz );

    $offset_secs = $tz->getOffset( $now );
    $sign        = $offset_secs < 0 ? '-' : '+';
    $abs         = abs( $offset_secs );
    $offset_str  = sprintf( '%s%02d:%02d', $sign, intdiv( $abs, 3600 ), intdiv( $abs % 3600, 60 ) );

    $keys = [];

    if ( 'today' === $range || '24h' === $range ) {
        $php_fmt   = 'Y-m-d H';
        $mysql_fmt = '%Y-%m-%d %H';

        if ( 'today' === $range ) {
            $cursor = ( clone $now )->setTime( 0, 0, 0 );
            $count  = (int) $now->format( 'G' ) + 1; // Hours 00 .. current.
        } else {
            $cursor = ( clone $now )->setTime( (int) $now->format( 'G' ), 0, 0 )->modify( '-23 hours' );
            $count  = 24;
        }

        for ( $i = 0; $i < $count; $i++ ) {
            $keys[] = $cursor->format( $php_fmt );
            $cursor->modify( '+1 hour' );
        }
    } else {
        $php_fmt   = 'Y-m-d';
        $mysql_fmt = '%Y-%m-%d';
        $days      = ( '7d' === $range ) ? 7 : 30;

        $cursor = ( clone $now )->setTime( 0, 0, 0 )->modify( '-' . ( $days - 1 ) . ' days' );
        for ( $i = 0; $i < $days; $i++ ) {
            $keys[] = $cursor->format( $php_fmt );
            $cursor->modify( '+1 day' );
        }
    }

    // Window start in GMT = local start of the first bucket. The leading "!"
    // resets every field not present in the format to zero, so a day key
    // ("Y-m-d") lands on local 00:00:00 and an hour key ("Y-m-d H") lands on
    // HH:00:00 — without it createFromFormat would keep the current minutes,
    // pushing the window start forward and dropping early orders.
    $start_local = DateTime::createFromFormat( '!' . $php_fmt, $keys[0], $tz );
    if ( false === $start_local ) {
        $start_local = ( clone $now )->setTime( 0, 0, 0 );
    }
    $start_gmt = ( clone $start_local )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );

    return [
        'keys'      => $keys,
        'php_fmt'   => $php_fmt,
        'mysql_fmt' => $mysql_fmt,
        'offset'    => $offset_str,
        'start_gmt' => $start_gmt,
    ];
}

/**
 * Get the order summary and per-metric sparkline series for a range.
 *
 * @param array $config Output of brikpanel_overview_range_config().
 * @return array
 */
function brikpanel_get_range_summary( $config ) {
    global $wpdb;

    $is_hpos = get_option( 'woocommerce_custom_orders_table_enabled' ) === 'yes';

    if ( $is_hpos ) {
        // Revenue is converted to the store base currency per order (CURCY
        // day-of-sale snapshot or manual fallback), so a multi-currency store's
        // overview totals are not a raw mix of currencies. COALESCE falls back
        // to the raw total for base-currency orders.
        $fx = brikpanel_base_total_sql( true, "{$wpdb->prefix}wc_orders.id", 'total_amount', 'bpfxos' );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $results = $wpdb->get_results( $wpdb->prepare(
            "SELECT DATE_FORMAT(CONVERT_TZ(date_created_gmt, '+00:00', %s), %s) AS bucket,
                    status,
                    COUNT(*) AS cnt,
                    COALESCE(SUM({$fx['expr']}), 0) AS revenue
             FROM {$wpdb->prefix}wc_orders{$fx['join']}
             WHERE date_created_gmt >= %s
               AND type = 'shop_order'
             GROUP BY bucket, status",
            $config['offset'],
            $config['mysql_fmt'],
            $config['start_gmt']
        ) );
    } else {
        $fx = brikpanel_base_total_sql( false, 'p.ID', 'CAST(pm.meta_value AS DECIMAL(10,2))', 'bpfxos' );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $results = $wpdb->get_results( $wpdb->prepare(
            "SELECT DATE_FORMAT(CONVERT_TZ(p.post_date_gmt, '+00:00', %s), %s) AS bucket,
                    p.post_status AS status,
                    COUNT(*) AS cnt,
                    COALESCE(SUM({$fx['expr']}), 0) AS revenue
             FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_order_total'{$fx['join']}
             WHERE p.post_date_gmt >= %s
               AND p.post_type = 'shop_order'
             GROUP BY bucket, status",
            $config['offset'],
            $config['mysql_fmt'],
            $config['start_gmt']
        ) );
    }

    $successful_statuses = brikpanel_paid_order_statuses();
    $refunded_statuses   = brikpanel_refunded_order_statuses();
    $countable_statuses  = array_values( array_unique( array_merge(
        $successful_statuses,
        $refunded_statuses,
        [ 'wc-on-hold', 'wc-pending', 'wc-cancelled', 'wc-failed' ]
    ) ) );

    // Seed the series with a zero for every bucket so the sparkline has a fixed,
    // gap-free shape regardless of which buckets had orders.
    $zero   = array_fill_keys( $config['keys'], 0 );
    $series = [
        'total'     => $zero,
        'completed' => $zero,
        'refunded'  => $zero,
        'revenue'   => array_fill_keys( $config['keys'], 0.0 ),
    ];

    foreach ( $results as $row ) {
        $bucket = $row->bucket;
        $status = $row->status;

        if ( null === $bucket || ! isset( $zero[ $bucket ] ) ) {
            continue;
        }
        if ( ! in_array( $status, $countable_statuses, true ) ) {
            continue;
        }

        $cnt = (int) $row->cnt;

        $series['total'][ $bucket ] += $cnt;

        if ( in_array( $status, $successful_statuses, true ) ) {
            $series['completed'][ $bucket ] += $cnt;
            $series['revenue'][ $bucket ]   += (float) $row->revenue;
        }

        if ( in_array( $status, $refunded_statuses, true ) ) {
            $series['refunded'][ $bucket ] += $cnt;
        }
    }

    $total_count     = array_sum( $series['total'] );
    $completed_count = array_sum( $series['completed'] );
    $refunded_count  = array_sum( $series['refunded'] );
    $revenue_sum     = array_sum( $series['revenue'] );

    return [
        'total'             => (int) $total_count,
        'completed'         => (int) $completed_count,
        'refunded'          => (int) $refunded_count,
        'revenue'           => (float) $revenue_sum,
        // wc_price() HTML-encodes the currency symbol (e.g. "&#36;"); the JS
        // renders this via textContent, so decode the entities here.
        'revenue_formatted' => html_entity_decode(
            wp_strip_all_tags( wc_price( $revenue_sum ) ),
            ENT_QUOTES,
            'UTF-8'
        ),
        'series'            => [
            'total'     => array_values( $series['total'] ),
            'completed' => array_values( $series['completed'] ),
            'refunded'  => array_values( $series['refunded'] ),
            'revenue'   => array_map( 'floatval', array_values( $series['revenue'] ) ),
        ],
    ];
}

/**
 * Get marketplace stats if BrikMarket is active.
 *
 * @param string $start_gmt Window start (GMT, 'Y-m-d H:i:s') matching the
 *                          currently selected overview range.
 * @return array|null Null if BrikMarket is not active.
 */
function brikpanel_get_marketplace_stats( $start_gmt ) {
    if ( ! class_exists( 'BrikMarket_Marketplace_Registry' ) ) {
        return null;
    }

    $active = BrikMarket_Marketplace_Registry::get_active();
    if ( empty( $active ) ) {
        return null;
    }

    global $wpdb;

    $is_hpos = get_option( 'woocommerce_custom_orders_table_enabled' ) === 'yes';

    // Product counts per marketplace.
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $product_rows = $wpdb->get_results(
        "SELECT marketplace_id, COUNT(DISTINCT wc_product_id) AS cnt
         FROM {$wpdb->prefix}brksoft_product_map
         GROUP BY marketplace_id",
        OBJECT_K
    );

    // Order counts and revenue per marketplace within the selected window.
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
            $start_gmt
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
            $start_gmt
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
