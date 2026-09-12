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
 * Session key holding this visitor's per-product ledger for the day.
 */
if ( ! defined( 'BRIKPANEL_CART_LEDGER_KEY' ) ) {
    define( 'BRIKPANEL_CART_LEDGER_KEY', 'brikpanel_counted_cart_adds' );
}

/**
 * Read and advance this visitor's per-product ledger for today.
 *
 * The "Most Added to Cart" card used to store raw events, so every repeat of
 * the same add counted again: a shopper reloading an `?add-to-cart=` URL, a
 * cart restored from a shared link, a crawler working through the shop
 * archive. Counting one add per visitor per product per day is what makes the
 * card agree with the "Add to cart" figure printed next to it and makes the
 * ranking reflect how many people wanted a product rather than how many times
 * a URL was hit.
 *
 * The ledger lives in the WooCommerce session, so it follows the visitor
 * across page loads without adding a cookie of our own and without being
 * defeated by a full-page cache.
 *
 * What the caller must not assume is that an empty ledger means "not counted
 * yet". A session that was born this request has an empty ledger because it
 * has no past, not because the visitor has none — and WooCommerce hands out
 * exactly that to a guest whose cart went empty, on every single turn of an
 * add/remove loop. So this reports `continuing` alongside the answer: true
 * only when the session already carried our stamp, i.e. when its silence
 * about a product actually means something.
 *
 * The stamp is written even when the add is not counted. That matters for a
 * shopper the address lock (the caller's fallback) turned away: their next
 * add in the same session takes the ledger path and is counted normally, so
 * one product is lost instead of the whole visit.
 *
 * @since 3.3.1 Returns the full state instead of a bare boolean.
 *
 * @param int $product_id Product being added.
 * @return array{available:bool,continuing:bool,first_today:bool}
 */
function brikpanel_cart_addition_ledger( $product_id ) {
    $product_id = (int) $product_id;
    $unknown    = [ 'available' => false, 'continuing' => false, 'first_today' => false ];

    if ( $product_id <= 0 ) {
        return $unknown;
    }

    if ( ! function_exists( 'WC' ) ) {
        return $unknown;
    }
    $wc = WC();
    if ( ! $wc || empty( $wc->session ) || ! is_object( $wc->session ) ) {
        // No session to remember with. The caller's address lock is the
        // backstop for exactly this case.
        return $unknown;
    }

    $today = wp_date( 'Y-m-d' );
    $raw   = $wc->session->get( BRIKPANEL_CART_LEDGER_KEY );

    // Our stamp, not the day: a session carried over from yesterday belongs to
    // a client that genuinely keeps state, so it is trusted and simply rolls
    // over to a fresh day rather than being sent to the address lock.
    $continuing = ( is_array( $raw ) && isset( $raw['day'] ) );

    // A stale day is discarded rather than merged: the cap is per day.
    $state = ( $continuing && $raw['day'] === $today )
        ? $raw
        : [ 'day' => $today, 'ids' => [] ];

    $ids = ( isset( $state['ids'] ) && is_array( $state['ids'] ) ) ? $state['ids'] : [];

    $first_today = ! in_array( $product_id, $ids, true )
        && count( $ids ) < BRIKPANEL_MAX_DAILY_CART_PRODUCTS;

    if ( $first_today ) {
        $ids[] = $product_id;
    }
    $state['ids'] = $ids;

    // Also plants the stamp on a session that had none. Skipped when nothing
    // changed, so a repeat add does not dirty the session for no reason.
    if ( $state !== $raw ) {
        $wc->session->set( BRIKPANEL_CART_LEDGER_KEY, $state );
    }

    return [
        'available'   => true,
        'continuing'  => $continuing,
        'first_today' => $first_today,
    ];
}

/**
 * Whether this visitor has already been counted for this product today.
 *
 * Thin wrapper kept for third-party callers that predate the ledger contract.
 * New code should call brikpanel_cart_addition_ledger() and read `continuing`
 * as well, because this boolean alone cannot tell "first add of the day" from
 * "session with no past".
 *
 * @param int $product_id Product being added.
 * @return bool True when this add is the visitor's first for the product today.
 */
function brikpanel_cart_addition_is_new_today( $product_id ) {
    $ledger = brikpanel_cart_addition_ledger( $product_id );

    return $ledger['available'] ? $ledger['first_today'] : true;
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
    // Master tracking switch and cookie-consent gate. Server-side hook, so
    // the consent state is read from BrikPanel's own consent record cookie
    // (or the WP Consent API), never from the tracker script.
    if ( function_exists( 'brikpanel_frontend_tracking_allowed' ) && ! brikpanel_frontend_tracking_allowed( 'add_to_cart' ) ) {
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

    // One add per visitor, per product, per day — the same rule the store-wide
    // "Add to cart" KPI uses, so the two numbers can be read together.
    $ledger = brikpanel_cart_addition_ledger( $product_id );

    if ( $ledger['available'] && $ledger['continuing'] ) {
        // The session already carried our stamp, so it is a client that keeps
        // state and its ledger is the accurate answer.
        if ( ! $ledger['first_today'] ) {
            return;
        }
    } elseif ( ! ( $ledger['available'] && is_user_logged_in() ) ) {
        // A fresh session's empty ledger proves nothing, because a client that
        // throws its memory away every turn always arrives looking like this.
        // Fall back to a lock the client cannot discard.
        //
        // The one exemption is a signed-in customer whose session is working:
        // that session is durable and keyed to the account, so it will remember
        // from the next request on, and guessing at their address would only
        // make two colleagues behind one office IP cancel each other out. The
        // exemption deliberately requires `available` — when there is no
        // session at all there is no memory to inherit, signed in or not, and
        // an ungated counter there is exactly the unbounded count this whole
        // guard exists to prevent.
        if ( function_exists( 'brikpanel_client_daily_lock' )
            && ! brikpanel_client_daily_lock( 'atc_product_' . (int) $product_id ) ) {
            return;
        }
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
