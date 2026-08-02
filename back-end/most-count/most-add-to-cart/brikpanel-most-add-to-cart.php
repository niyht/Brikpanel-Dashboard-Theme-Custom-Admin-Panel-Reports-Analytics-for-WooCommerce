<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Bir ürün sepete eklendiğinde sayacı günceller.
 * Not: Veriler Yerel Zaman (Local Time) olarak kaydediliyor.
 */
function brikpanel_track_cart_addition( $cart_item_key, $product_id ) {
    // Master tracking switch.
    if ( function_exists( 'brikpanel_frontend_tracking_enabled' ) && ! brikpanel_frontend_tracking_enabled() ) {
        return;
    }
    if ( brikpanel_is_admin_user() ) {
        return;
    }
    if ( function_exists( '_brikpanel_is_bot_ua' ) && _brikpanel_is_bot_ua() ) {
        return;
    }

    // Backstop for crawlers with a browser-shaped user agent: cap cookieless
    // clients at one recorded add per product per day. A real shopper is not
    // affected — WooCommerce sets its session cookie on the first add, so
    // only that first event can ever be cookieless, and it is still counted.
    if ( function_exists( 'brikpanel_cookieless_daily_gate' )
        && ! brikpanel_cookieless_daily_gate( 'atc_product_' . (int) $product_id ) ) {
        return;
    }

    global $wpdb;
    $table_name   = $wpdb->prefix . 'brikpanel_cart_tracking';
    $current_date = wp_date( 'Y-m-d' );
    $day_start    = $current_date . ' 00:00:00';
    $day_end      = $current_date . ' 23:59:59';

    // BETWEEN on the indexed datetime column lets MySQL use idx_product_date,
    // unlike the previous LIKE wildcard that forced a full index scan.
    $updated = $wpdb->query( $wpdb->prepare(
        "UPDATE {$table_name}
            SET cart_count = cart_count + 1
          WHERE product_id = %d
            AND date_column BETWEEN %s AND %s
          ORDER BY date_column DESC
          LIMIT 1",
        $product_id,
        $day_start,
        $day_end
    ) );

    if ( ! $updated ) {
        $wpdb->insert(
            $table_name,
            [
                'product_id'  => $product_id,
                'cart_count'  => 1,
                'date_column' => current_time( 'mysql' ),
            ],
            [ '%d', '%d', '%s' ]
        );
    }
}
add_action('woocommerce_add_to_cart', 'brikpanel_track_cart_addition', 10, 2);