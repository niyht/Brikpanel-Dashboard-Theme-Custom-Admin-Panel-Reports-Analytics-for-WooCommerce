<?php
if( ! defined( 'ABSPATH' ) ) exit;

/**
 * Store-wide "Add to cart" figure: one per visitor per day.
 *
 * Runs on woocommerce_add_to_cart, which every add path fires (`?add-to-cart=`
 * links, the classic AJAX button, the block cart's Store API). Until 3.3.30 it
 * counted any client with a browser-like name, so a script that never ran
 * JavaScript, or a Store API loop, filled the funnel. Now the browser must
 * carry the "this is a person" mark (includes/brikpanel-human-proof.php) or be
 * a signed-in customer; otherwise the add waits in the shopper's session and
 * is counted the moment the same session's tracker proves itself.
 *
 * @param string $cart_item_key Unused.
 * @param int    $product_id    Unused here (see brikpanel_track_cart_addition()).
 * @return void
 */
function brikpanel_add_to_cart_counter( $cart_item_key = '', $product_id = 0 ) {
    // Master tracking switch and cookie-consent gate. This counter runs on a
    // pure PHP hook with no JavaScript involved, so the visitor's consent has
    // to be readable server-side — that is what BrikPanel's own consent
    // record cookie is for.
    if ( function_exists( 'brikpanel_frontend_tracking_allowed' ) && ! brikpanel_frontend_tracking_allowed( 'add_to_cart' ) ) {
        return;
    }

    // Store staff are never counted.
    if ( brikpanel_is_admin_user() ) {
        return;
    }

    if ( function_exists( '_brikpanel_is_bot_ua' ) && _brikpanel_is_bot_ua() ) {
        return;
    }

    if ( function_exists( 'brikpanel_request_is_human' ) && ! brikpanel_request_is_human() ) {
        if ( function_exists( 'brikpanel_pending_add' ) ) {
            brikpanel_pending_add( 'atc' );
        }
        return;
    }

    brikpanel_count_store_add_to_cart();
}
add_action( 'woocommerce_add_to_cart', 'brikpanel_add_to_cart_counter', 10, 2 );

/**
 * Count one store-wide add-to-cart for this browser today, unless it already
 * has one. Shared by the hook above and by the release of parked counts.
 *
 * Callers apply the tracking, staff, bot and person checks.
 *
 * @return bool Whether a count was written.
 */
function brikpanel_count_store_add_to_cart() {
    // Several adds can arrive in one request (a Store API batch, a shared
    // cart link), before any cookie this request sets could be read back.
    static $done = false;
    if ( $done ) {
        return false;
    }

    // Counted today already: the mark's flag, or the cookie earlier releases
    // set (still honoured on the day this release arrives).
    if ( ( function_exists( 'brikpanel_human_has_flag' ) && brikpanel_human_has_flag( 'a' ) )
        || isset( $_COOKIE['brikpanel_add_to_cart_count_cookie'] ) ) {
        $done = true;
        return false;
    }
    $done = true;

    global $wpdb;
    $table_name   = $wpdb->prefix . 'brikpanel_visitors';
    $current_date = wp_date( 'Y-m-d' );

    $updated = $wpdb->query( $wpdb->prepare(
        "UPDATE {$table_name} SET add_to_cart_count = add_to_cart_count + 1 WHERE date_column = %s",
        $current_date
    ) );

    if ( ! $updated ) {
        $wpdb->insert(
            $table_name,
            [ 'date_column' => $current_date, 'add_to_cart_count' => 1 ],
            [ '%s', '%d' ]
        );
    }

    if ( function_exists( 'brikpanel_human_mark' ) ) {
        brikpanel_human_mark( 'a' );
    }
    return true;
}


/**
 * ANA YARDIMCI FONKSİYON
 * Belirtilen tarih aralığındaki toplam sepete ekleme sayısını hesaplar.
 *
 * @param string|null $start_date Başlangıç tarihi (Y-m-d formatında).
 * @param string|null $end_date Bitiş tarihi (Y-m-d formatında).
 * @return int Toplam sepete ekleme sayısı.
 */
function brikpanel_get_add_to_cart_count($start_date = null, $end_date = null) {
    global $wpdb;
    $table_name = $wpdb->prefix . "brikpanel_visitors";

    $query = "SELECT SUM(add_to_cart_count) FROM {$table_name} WHERE 1=1";
    $query_args = array();

    if ($start_date && $end_date) {
        $query .= " AND date_column BETWEEN %s AND %s";
        $query_args[] = $start_date;
        $query_args[] = $end_date;
    } elseif ($start_date) {
        $query .= " AND date_column = %s";
        $query_args[] = $start_date;
    }

    if (!empty($query_args)) {
        $total_count = $wpdb->get_var($wpdb->prepare($query, $query_args));
    } else {
        $total_count = $wpdb->get_var($query);
    }

    return is_null($total_count) ? 0 : (int) $total_count;
}
