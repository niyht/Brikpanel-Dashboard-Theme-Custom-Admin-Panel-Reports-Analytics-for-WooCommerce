<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Highest number of distinct products one visitor can contribute to the
 * per-product card in a single day.
 *
 * A shopper never comes close. Something that does is not shopping, and the
 * ceiling also keeps the per-visitor ledger below from growing without bound
 * inside the WooCommerce session.
 */
if ( ! defined( 'BRIKPANEL_MAX_DAILY_CART_PRODUCTS' ) ) {
    define( 'BRIKPANEL_MAX_DAILY_CART_PRODUCTS', 100 );
}

/**
 * Whether this visitor has already been counted for this product today.
 *
 * The "Most Added to Cart" card used to store raw events, so every repeat of
 * the same add counted again: a shopper reloading an `?add-to-cart=` URL, a
 * cart restored from a shared link, a crawler working through the shop
 * archive. That is what made the card disagree with the "Add to cart" figure
 * printed next to it on the same dashboard, which has always been unique
 * visitors per day. Applying the same rule per product makes the two agree
 * and makes the ranking reflect how many people wanted a product rather than
 * how many times a URL was hit.
 *
 * The ledger lives in the WooCommerce session, so it follows the visitor
 * across page loads without adding a cookie of our own and without being
 * defeated by a full-page cache.
 *
 * @param int $product_id Product being added.
 * @return bool True when this add is the visitor's first for the product today.
 */
function brikpanel_cart_addition_is_new_today( $product_id ) {
    $product_id = (int) $product_id;
    if ( $product_id <= 0 ) {
        return false;
    }

    if ( ! function_exists( 'WC' ) ) {
        return true;
    }
    $wc = WC();
    if ( ! $wc || empty( $wc->session ) || ! is_object( $wc->session ) ) {
        // No session to remember with. The cookieless daily gate the caller
        // applies is the backstop for exactly this case.
        return true;
    }

    $today = wp_date( 'Y-m-d' );
    $state = $wc->session->get( 'brikpanel_counted_cart_adds' );

    // A stale day is discarded rather than merged: the cap is per day.
    if ( ! is_array( $state ) || ! isset( $state['day'] ) || $state['day'] !== $today ) {
        $state = [ 'day' => $today, 'ids' => [] ];
    }

    $ids = ( isset( $state['ids'] ) && is_array( $state['ids'] ) ) ? $state['ids'] : [];

    if ( in_array( $product_id, $ids, true ) ) {
        return false;
    }
    if ( count( $ids ) >= BRIKPANEL_MAX_DAILY_CART_PRODUCTS ) {
        return false;
    }

    $ids[]        = $product_id;
    $state['ids'] = $ids;
    $wc->session->set( 'brikpanel_counted_cart_adds', $state );

    return true;
}

/**
 * Bir ürün sepete eklendiğinde sayacı günceller.
 * Not: Veriler Yerel Zaman (Local Time) olarak kaydediliyor.
 *
 * @param string $cart_item_key Cart line key (unused).
 * @param int    $product_id    Parent product ID — variations roll up to their
 *                              parent, which is what the card ranks.
 */
function brikpanel_track_cart_addition( $cart_item_key, $product_id ) {
    // Master tracking switch.
    if ( function_exists( 'brikpanel_frontend_tracking_enabled' ) && ! brikpanel_frontend_tracking_enabled() ) {
        return;
    }
    if ( brikpanel_is_admin_user() ) {
        return;
    }
    // Bot user agents and, since 3.2.41, speculative prefetch/prerender hits:
    // a preloader following an `?add-to-cart=` link fills the cart for real,
    // and it does so for every product on the page it is preloading.
    if ( function_exists( '_brikpanel_is_bot_ua' ) && _brikpanel_is_bot_ua() ) {
        return;
    }

    // Carts filled by something other than a shopper on the storefront: WP-CLI
    // scripts, cron jobs and the admin-side cart builder. They are real cart
    // contents but they are not demand, so they must not rank products.
    if ( wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
        return;
    }
    if ( is_admin() && ! wp_doing_ajax() ) {
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

    // One add per visitor, per product, per day — the same rule the store-wide
    // "Add to cart" KPI uses, so the two numbers can be read together.
    if ( ! brikpanel_cart_addition_is_new_today( $product_id ) ) {
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
