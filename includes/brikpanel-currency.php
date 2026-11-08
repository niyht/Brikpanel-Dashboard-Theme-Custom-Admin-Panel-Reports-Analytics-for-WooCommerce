<?php
/**
 * Multi-currency conversion engine.
 *
 * WooCommerce stores each order in the currency it was placed in (the
 * `currency` column on HPOS, the `_order_currency` meta on the legacy post
 * store). A store that accepts more than one currency therefore ends up with
 * order totals expressed in a mix of currencies. BrikPanel's analytics SUM
 * those totals, so without conversion a 100 TRY order and a 100 USD order are
 * added together as "200" — meaningless.
 *
 * This module resolves a per-order conversion factor to the store base
 * currency and snapshots the converted total onto the order as the
 * `_brikpanel_base_total` meta. Aggregation SQL then sums that snapshot
 * (falling back to the raw total when no snapshot exists), so every figure is
 * reported in a single, comparable currency.
 *
 * Conversion factor resolution order (first match wins):
 *   1. The day-of-sale rate a multi-currency plugin already stored on the
 *      order. CURCY (woocommerce-multi-currency) writes a `wmc_order_info`
 *      snapshot of every currency's rate at checkout time, which gives us the
 *      historically correct rate for that order for free. Other plugins can
 *      plug in via the brikpanel_order_base_factor filter.
 *   2. The rate BrikPanel kept on the order (BRIKPANEL_FX_RATE_META) the
 *      first time it converted it with WooCommerce Multilingual's rates.
 *   3. WooCommerce Multilingual's current rate (it stores none on the
 *      order), which step 2 keeps from then on.
 *   4. A flat per-currency rate the merchant enters in
 *      BrikPanel ▸ Settings ▸ Currency (fallback for stores whose plugin does
 *      not snapshot a rate, or for orders that predate the plugin).
 *   5. None — the order is left at its raw total (current behaviour).
 *
 * @package BrikPanel
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Option name storing the merchant's manual fallback exchange rates.
 * Shape: [ 'TRY' => 0.031, 'EUR' => 1.08, ... ] where the value is the factor
 * that converts one unit of that currency into the store base currency
 * (base_amount = order_amount * factor).
 */
const BRIKPANEL_FX_RATES_OPTION = 'brikpanel_fx_rates';

/**
 * Order meta key holding the order total converted to the store base currency.
 * Only written when an actual conversion happened (order currency differs from
 * base AND a rate was resolved); base-currency orders are left without it so
 * the aggregation COALESCE falls straight through to the raw total.
 */
const BRIKPANEL_BASE_TOTAL_META = '_brikpanel_base_total';

/**
 * Order meta key holding the exchange rate an order was converted at, when
 * that rate came from WooCommerce Multilingual's rate table. WCML stores no
 * rate on the order (CURCY does, in wmc_order_info), so BrikPanel keeps the
 * one in force when it first converts the order, and uses it from then on: a
 * later rate change, typed or from WCML's automatic rates, no longer converts
 * orders that were already placed again. Shape: [ 'currency' => 'USD',
 * 'rate' => 0.9090909 ] (store-currency value of one unit of that currency).
 */
const BRIKPANEL_FX_RATE_META = '_brikpanel_fx_rate';

/**
 * Store base (reporting) currency.
 *
 * @return string ISO currency code, e.g. 'USD'.
 */
function brikpanel_base_currency() {
    return get_option( 'woocommerce_currency' );
}

/**
 * Merchant-entered fallback rates, sanitised to [ CODE => positive float ].
 *
 * @return array<string,float>
 */
function brikpanel_manual_fx_rates() {
    $raw = get_option( BRIKPANEL_FX_RATES_OPTION, [] );
    if ( ! is_array( $raw ) ) {
        return [];
    }
    $out = [];
    foreach ( $raw as $code => $rate ) {
        $code = strtoupper( sanitize_text_field( (string) $code ) );
        $rate = (float) $rate;
        if ( '' !== $code && $rate > 0 ) {
            $out[ $code ] = $rate;
        }
    }
    return $out;
}

/**
 * Manual fallback factor for a single currency (0.0 when none configured).
 *
 * @param string $currency ISO currency code.
 * @return float
 */
function brikpanel_manual_fx_factor( $currency ) {
    $rates = brikpanel_manual_fx_rates();
    $code  = strtoupper( (string) $currency );
    return isset( $rates[ $code ] ) ? (float) $rates[ $code ] : 0.0;
}

/**
 * Short fingerprint of the manual rate table, for cache keys.
 *
 * A cached copy that holds converted figures must not outlive a rate change.
 * The update_option hook below already busts the caches, but a delete_option()
 * fires nothing, so the rates themselves go into the key as well.
 *
 * @return string '0' when no rate is set.
 */
function brikpanel_fx_rates_signature() {
    $rates = brikpanel_manual_fx_rates();
    if ( empty( $rates ) ) {
        return '0';
    }
    ksort( $rates );
    return substr( md5( (string) wp_json_encode( $rates ) ), 0, 8 );
}

/**
 * "1 USD = 0.76 GBP": how a rate from the Currency settings reads on screen.
 *
 * @param string $currency Currency code the rate converts from.
 * @param float  $rate     Store-currency value of one unit of it.
 * @param string $store    Store currency code; the WooCommerce one when empty.
 * @return string Plain text.
 */
function brikpanel_fx_rate_label( $currency, $rate, $store = '' ) {
    $store = '' !== (string) $store ? (string) $store : (string) brikpanel_base_currency();
    return sprintf(
        /* translators: an exchange rate. 1: a currency code, 2: what one unit of it is worth, 3: the store currency code. Example: 1 USD = 0.76 GBP */
        __( '1 %1$s = %2$s %3$s', 'brikpanel' ),
        strtoupper( (string) $currency ),
        function_exists( 'brikpanel_number' ) ? brikpanel_number( (float) $rate, 6, true ) : (string) (float) $rate,
        strtoupper( $store )
    );
}

/**
 * Ad spend expressed in the store currency.
 *
 * An ad account bills in the currency it was opened with, and neither Meta nor
 * Google lets the merchant change it, so a store selling in GBP can run every
 * Meta account in USD. Spend in another currency is converted with the rate
 * the merchant entered under Settings > Currency, the same table that converts
 * orders and payment fees, at that one rate for every day. Spend in a currency
 * with no rate is left out rather than added at face value (dollars added to
 * pounds are a wrong number, not a smaller one) and comes back in
 * `unconverted`, so every surface can say what it did not count.
 *
 * Works on any rows that carry `platform`, `currency` and `spend`, which is the
 * shape of Brikpanel_Ads_Store::totals_for_range() and daily_breakdown(). A row
 * with no currency is store currency, as it always was.
 *
 * @param array  $rows  Ad spend rows.
 * @param string $store Store currency code; the WooCommerce one when empty.
 * @return array{
 *     by_platform: array<string,float>,
 *     total: float,
 *     unconverted: array<string,float>,
 *     converted: array<string,array<string,array{amount:float,rate:float}>>
 * } `by_platform` and `total` are in the store currency. `unconverted` is
 *   currency => spend left out. `converted` is platform => currency =>
 *   the original spend and the rate it was converted at.
 */
function brikpanel_ad_spend_to_store_currency( array $rows, $store = '' ) {
    $store = strtoupper( '' !== (string) $store ? (string) $store : (string) brikpanel_base_currency() );
    $rates = brikpanel_manual_fx_rates();
    $out   = [
        'by_platform' => [],
        'total'       => 0.0,
        'unconverted' => [],
        'converted'   => [],
    ];

    foreach ( $rows as $row ) {
        $platform = isset( $row['platform'] ) ? (string) $row['platform'] : '';
        if ( '' === $platform ) {
            continue;
        }
        $spend    = isset( $row['spend'] ) ? (float) $row['spend'] : 0.0;
        $currency = isset( $row['currency'] ) ? strtoupper( trim( (string) $row['currency'] ) ) : '';

        if ( '' === $currency || $currency === $store ) {
            $amount = $spend;
        } elseif ( isset( $rates[ $currency ] ) ) {
            $amount = $spend * $rates[ $currency ];
            if ( ! isset( $out['converted'][ $platform ][ $currency ] ) ) {
                $out['converted'][ $platform ][ $currency ] = [ 'amount' => 0.0, 'rate' => (float) $rates[ $currency ] ];
            }
            $out['converted'][ $platform ][ $currency ]['amount'] += $spend;
        } else {
            // A zero-spend day leaves nothing out, so it must not hold ROAS back.
            if ( $spend > 0 ) {
                $out['unconverted'][ $currency ] = ( $out['unconverted'][ $currency ] ?? 0.0 ) + $spend;
            }
            continue;
        }

        $out['by_platform'][ $platform ] = ( $out['by_platform'][ $platform ] ?? 0.0 ) + $amount;
        $out['total']                   += $amount;
    }

    return $out;
}

/**
 * Pull the day-of-sale conversion factor for an order out of a multi-currency
 * plugin's own snapshot. Returns 0.0 when nothing usable is found so callers
 * fall through to the manual rate.
 *
 * Currently understands CURCY's `wmc_order_info` array: a map of
 * currency => [ 'rate' => float, ('is_main' => 1) ]. CURCY's rate is "units of
 * this currency per one base unit", so an order amount is converted back to
 * base with: base = amount * (base_rate / order_rate).
 *
 * @param WC_Order $order        Order object.
 * @param string   $order_currency Order currency code.
 * @return float Factor (amount * factor = base amount), or 0.0 if unknown.
 */
function brikpanel_plugin_fx_factor( $order, $order_currency ) {
    $info = $order->get_meta( 'wmc_order_info', true );
    if ( is_array( $info ) && isset( $info[ $order_currency ]['rate'] ) ) {
        $order_rate = (float) $info[ $order_currency ]['rate'];

        // Rate of the currency flagged as the store's main currency in the
        // snapshot (defaults to 1 — the usual CURCY reference rate).
        $base_rate = 1.0;
        foreach ( $info as $row ) {
            if ( ! empty( $row['is_main'] ) && isset( $row['rate'] ) && (float) $row['rate'] > 0 ) {
                $base_rate = (float) $row['rate'];
                break;
            }
        }

        if ( $order_rate > 0 ) {
            return $base_rate / $order_rate;
        }
    }

    return 0.0;
}

/**
 * WooCommerce Multilingual's current rate for a currency, as a factor
 * (amount * factor = store-currency amount); 0.0 when WCML's multi-currency
 * is off or has no rate for it.
 *
 * WCML keeps its rate table only in the `_wcml_settings` option ("units of
 * this currency per one store-currency unit", so base = amount / rate) and
 * never on the order. So the rate read here is only right for an order
 * converted now: brikpanel_resolve_order_base_total() keeps it on the order
 * the first time and uses that from then on. Works the same when WCML runs
 * without WPML (its multi-currency-only mode).
 *
 * @param string $order_currency Order currency code.
 * @return float
 */
function brikpanel_wcml_fx_factor( $order_currency ) {
    if ( ! defined( 'WCML_VERSION' ) && ! class_exists( 'woocommerce_wpml' ) ) {
        return 0.0;
    }
    $wcml        = get_option( '_wcml_settings' );
    $independent = defined( 'WCML_MULTI_CURRENCIES_INDEPENDENT' ) ? WCML_MULTI_CURRENCIES_INDEPENDENT : 2;
    if ( is_array( $wcml )
        && isset( $wcml['enable_multi_currency'] )
        && (int) $wcml['enable_multi_currency'] === (int) $independent
        && isset( $wcml['currency_options'][ $order_currency ]['rate'] ) ) {
        $rate = (float) $wcml['currency_options'][ $order_currency ]['rate'];
        if ( $rate > 0 ) {
            return 1.0 / $rate;
        }
    }
    return 0.0;
}

/**
 * The rate kept on an order (BRIKPANEL_FX_RATE_META) for its currency; 0.0
 * when none is kept or it was kept for another currency (an order whose
 * currency was edited afterwards).
 *
 * @param WC_Order $order          Order.
 * @param string   $order_currency Order currency code.
 * @return float
 */
function brikpanel_order_kept_fx_rate( $order, $order_currency ) {
    $kept = $order->get_meta( BRIKPANEL_FX_RATE_META, true );
    if ( is_array( $kept )
        && isset( $kept['currency'], $kept['rate'] )
        && strtoupper( (string) $kept['currency'] ) === strtoupper( (string) $order_currency )
        && (float) $kept['rate'] > 0 ) {
        return (float) $kept['rate'];
    }
    return 0.0;
}

/**
 * Resolve how to express an order's total in the store base currency.
 *
 * @param WC_Order $order Order object.
 * @return array{base_total: float|null, factor: float, source: string, keep: bool}
 *               base_total is null when the order is in a foreign currency for
 *               which no rate could be resolved (caller should leave it raw).
 *               source is one of 'base' | 'plugin' | 'manual' | 'none'.
 *               keep is true when the factor came from WooCommerce
 *               Multilingual's table and is not kept on the order yet.
 */
function brikpanel_resolve_order_base_total( $order ) {
    $base           = brikpanel_base_currency();
    $order_currency = $order->get_currency();
    $total          = (float) $order->get_total();

    if ( ! $order_currency || strtoupper( $order_currency ) === strtoupper( $base ) ) {
        return [ 'base_total' => $total, 'factor' => 1.0, 'source' => 'base', 'keep' => false ];
    }

    // 1) Day-of-sale rate snapshotted by a multi-currency plugin (CURCY).
    $factor = brikpanel_plugin_fx_factor( $order, $order_currency );
    $source = 'plugin';
    $keep   = false;

    // 2) The rate kept on the order when it was first converted with
    //    WooCommerce Multilingual's rate table.
    if ( $factor <= 0 ) {
        $factor = brikpanel_order_kept_fx_rate( $order, $order_currency );
    }

    // 3) WooCommerce Multilingual's rate, kept with the order from now on.
    //    An order already converted before it was kept (BrikPanel 3.3.44 and
    //    older re-converted every order at each rate change) keeps the rate it
    //    shows now, read off its stored total, rather than today's.
    if ( $factor <= 0 ) {
        $factor = brikpanel_wcml_fx_factor( $order_currency );
        if ( $factor > 0 ) {
            $keep    = true;
            $shown   = $order->get_meta( BRIKPANEL_BASE_TOTAL_META, true );
            if ( '' !== (string) $shown && is_numeric( $shown ) && (float) $shown > 0 && $total > 0 ) {
                $factor = (float) $shown / $total;
            }
        }
    }

    // 4) Merchant's flat fallback rate. Not kept: changing it in Settings >
    //    Currency converts the orders that use it again, as that screen says.
    if ( $factor <= 0 ) {
        $factor = brikpanel_manual_fx_factor( $order_currency );
        $source = 'manual';
    }

    /**
     * Filter the resolved order → base-currency factor. Lets other
     * multi-currency integrations supply a rate when neither CURCY's snapshot
     * nor a manual rate is present.
     *
     * @param float    $factor         0 when unresolved.
     * @param WC_Order $order          Order being converted.
     * @param string   $order_currency Order currency code.
     * @param string   $base           Store base currency code.
     */
    $factor = (float) apply_filters( 'brikpanel_order_base_factor', $factor, $order, $order_currency, $base );

    if ( $factor > 0 ) {
        $decimals = function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 2;
        return [
            'base_total' => round( $total * $factor, $decimals ),
            'factor'     => $factor,
            'source'     => $source,
            'keep'       => $keep,
        ];
    }

    // 5) Foreign currency, no rate available — leave raw.
    return [ 'base_total' => null, 'factor' => 0.0, 'source' => 'none', 'keep' => false ];
}

/**
 * Compute and persist the base-currency total snapshot for one order.
 *
 * Writes BRIKPANEL_BASE_TOTAL_META only when a real conversion applies (the
 * order is in a non-base currency and a rate resolved). Base-currency orders
 * and unresolved foreign orders are left without the meta so the aggregation
 * falls back to the raw stored total. A re-entry guard keeps the meta save
 * from recursing through order-save hooks.
 *
 * @param int|WC_Order $order Order id or object.
 * @return void
 */
function brikpanel_refresh_order_base_total( $order ) {
    static $busy = [];

    if ( ! ( $order instanceof WC_Order ) ) {
        $order = wc_get_order( $order );
    }
    if ( ! $order instanceof WC_Order ) {
        return;
    }

    $id = $order->get_id();
    if ( isset( $busy[ $id ] ) ) {
        return;
    }
    $busy[ $id ] = true;

    $resolved = brikpanel_resolve_order_base_total( $order );
    $existing = $order->get_meta( BRIKPANEL_BASE_TOTAL_META, true );

    // Only store a snapshot when the order is genuinely in a foreign currency
    // (source 'plugin' or 'manual'). Base-currency orders never need it.
    if ( null !== $resolved['base_total'] && in_array( $resolved['source'], [ 'plugin', 'manual' ], true ) ) {
        $val   = wc_format_decimal( $resolved['base_total'], wc_get_price_decimals() );
        $dirty = false;
        if ( (string) $existing !== (string) $val ) {
            $order->update_meta_data( BRIKPANEL_BASE_TOTAL_META, $val );
            $dirty = true;
        }
        // A rate read from WooCommerce Multilingual's table stays with the
        // order (see BRIKPANEL_FX_RATE_META).
        if ( ! empty( $resolved['keep'] ) ) {
            $order->update_meta_data( BRIKPANEL_FX_RATE_META, [
                'currency' => strtoupper( (string) $order->get_currency() ),
                'rate'     => (float) $resolved['factor'],
            ] );
            $dirty = true;
        }
        if ( $dirty ) {
            $order->save_meta_data();
        }
    } elseif ( '' !== $existing && null !== $existing ) {
        // Currency reverted to base, or rate withdrawn — drop a stale snapshot.
        $order->delete_meta_data( BRIKPANEL_BASE_TOTAL_META );
        $order->delete_meta_data( BRIKPANEL_FX_RATE_META );
        $order->save_meta_data();
    }

    unset( $busy[ $id ] );
}

/**
 * Build the LEFT JOIN + SUM expression that converts order revenue to the
 * base currency inside an aggregation query.
 *
 * The expression is COALESCE(snapshot, raw_amount): orders with a stored
 * base-total snapshot use it, every other order (base currency, or foreign
 * without a resolved rate) falls back to its raw stored total, so the query
 * never loses rows and behaves exactly as before for single-currency stores.
 *
 * @param bool   $is_hpos     Whether HPOS is active.
 * @param string $id_expr     SQL reference to the order id column (e.g. 'o.id',
 *                            'wp_wc_orders.id', 'p.ID').
 * @param string $amount_expr SQL reference to the raw total (e.g. 'total_amount',
 *                            'o.total_amount', 'pm.meta_value').
 * @param string $alias       Unique table alias for the join.
 * @return array{join: string, expr: string}
 */
function brikpanel_base_total_sql( $is_hpos, $id_expr, $amount_expr, $alias = 'bpfx' ) {
    global $wpdb;
    $meta_key = BRIKPANEL_BASE_TOTAL_META;

    if ( $is_hpos ) {
        $join = " LEFT JOIN {$wpdb->prefix}wc_orders_meta {$alias} ON {$alias}.order_id = {$id_expr} AND {$alias}.meta_key = '{$meta_key}'";
    } else {
        $join = " LEFT JOIN {$wpdb->postmeta} {$alias} ON {$alias}.post_id = {$id_expr} AND {$alias}.meta_key = '{$meta_key}'";
    }

    // One snapshot row per order: a second copy of the meta row would
    // otherwise count the order twice in every revenue total built on this.
    if ( function_exists( 'brikpanel_sql_first_meta_guard' ) ) {
        $join .= ' AND ' . brikpanel_sql_first_meta_guard( $is_hpos ? 'order' : 'post', $alias );
    }

    return [
        'join' => $join,
        'expr' => "COALESCE(NULLIF({$alias}.meta_value, ''), {$amount_expr})",
    ];
}

/**
 * Whether the store has more than one order currency in its history. Used to
 * decide whether to surface the currency settings / hints at all. Cached for
 * the request.
 *
 * @return bool
 */
function brikpanel_store_is_multicurrency() {
    static $cached = null;
    if ( null !== $cached ) {
        return $cached;
    }
    $cached = count( brikpanel_order_currencies_in_use() ) > 1;
    return $cached;
}

/**
 * Distinct currencies that appear in the store's order history.
 *
 * @return string[] Upper-case ISO codes.
 */
function brikpanel_order_currencies_in_use() {
    global $wpdb;
    $is_hpos = get_option( 'woocommerce_custom_orders_table_enabled' ) === 'yes';

    $cache_key = 'brikpanel_order_currencies';
    $cached    = get_transient( $cache_key );
    if ( is_array( $cached ) ) {
        return $cached;
    }

    if ( $is_hpos ) {
        $rows = $wpdb->get_col(
            "SELECT DISTINCT currency FROM {$wpdb->prefix}wc_orders WHERE type = 'shop_order' AND currency <> ''"
        );
    } else {
        $rows = $wpdb->get_col(
            "SELECT DISTINCT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_order_currency' AND meta_value <> ''"
        );
    }

    $codes = array_values( array_unique( array_map( 'strtoupper', (array) $rows ) ) );
    if ( empty( $codes ) ) {
        $codes = [ strtoupper( (string) brikpanel_base_currency() ) ];
    }
    set_transient( $cache_key, $codes, brikpanel_cache_ttl( 3600 ) );
    return $codes;
}

/* -------------------------------------------------------------------------
 * Keep the per-order snapshot fresh across the order lifecycle.
 * ---------------------------------------------------------------------- */

/**
 * Recompute the snapshot when an order is created, updated, or changes status.
 * Runs late (priority 99) so a multi-currency plugin has already written its
 * own per-order rate snapshot first.
 *
 * @param int $order_id Order id.
 * @return void
 */
function brikpanel_fx_on_order_write( $order_id ) {
    if ( $order_id ) {
        brikpanel_refresh_order_base_total( $order_id );
    }
}
add_action( 'woocommerce_new_order', 'brikpanel_fx_on_order_write', 99 );
add_action( 'woocommerce_update_order', 'brikpanel_fx_on_order_write', 99 );
add_action( 'woocommerce_order_status_changed', 'brikpanel_fx_on_order_write', 99, 1 );

/**
 * Keep the rate of an order converted before rates were kept (BrikPanel
 * 3.3.44 and older), just before a save changes its total. Its stored total
 * and the total it was stored for give that rate; once the new total is
 * saved the pair no longer does, and the refresh after the save would read a
 * wrong rate off them. Every other save returns before reading any meta.
 *
 * @param WC_Order $order Order about to be saved.
 * @return void
 */
function brikpanel_fx_keep_rate_before_total_change( $order ) {
    if ( ! $order instanceof WC_Order || ! $order->get_id() ) {
        return;
    }
    $changes = $order->get_changes();
    if ( ! array_key_exists( 'total', $changes ) || array_key_exists( 'currency', $changes ) ) {
        return;
    }
    $currency = strtoupper( (string) $order->get_currency() );
    if ( '' === $currency || strtoupper( (string) brikpanel_base_currency() ) === $currency ) {
        return;
    }
    if ( brikpanel_order_kept_fx_rate( $order, $currency ) > 0
        || brikpanel_plugin_fx_factor( $order, $currency ) > 0
        || brikpanel_wcml_fx_factor( $currency ) <= 0 ) {
        return;
    }
    $stored = $order->get_data(); // Values as saved: the pending changes are not applied yet.
    $old    = isset( $stored['total'] ) ? (float) $stored['total'] : 0.0;
    $shown  = $order->get_meta( BRIKPANEL_BASE_TOTAL_META, true );
    if ( $old > 0 && is_numeric( $shown ) && (float) $shown > 0 ) {
        $order->update_meta_data( BRIKPANEL_FX_RATE_META, [
            'currency' => $currency,
            'rate'     => (float) $shown / $old,
        ] );
    }
}
add_action( 'woocommerce_before_order_object_save', 'brikpanel_fx_keep_rate_before_total_change', 5 );

/**
 * Once, after updating to 3.3.45: every order WooCommerce Multilingual's
 * rates converted keeps the rate it shows now, in the background, before a
 * rate change can reach it. Guarded by its own marker, not the version, so a
 * site that took 3.3.45 before this landed still gets it. Queues only when
 * WCML's multi-currency is on; elsewhere it just sets the marker.
 *
 * init:40, after WooCommerce and WCML have loaded.
 */
add_action( 'init', function () {
    if ( get_option( 'brikpanel_fx_rates_kept' ) ) {
        return;
    }
    $wcml = get_option( '_wcml_settings' );
    if ( is_array( $wcml ) && 2 === (int) ( $wcml['enable_multi_currency'] ?? 0 ) && function_exists( 'wc_get_order' ) ) {
        brikpanel_fx_queue_backfill();
    }
    update_option( 'brikpanel_fx_rates_kept', 1, true );
}, 40 );

/* -------------------------------------------------------------------------
 * Backfill existing foreign-currency orders in the background.
 * ---------------------------------------------------------------------- */

/**
 * IDs of every order whose currency differs from the store base currency and
 * that does not keep its own rate yet. These are the only orders a rate
 * change can still convert: an order keeping its WooCommerce Multilingual
 * rate (BRIKPANEL_FX_RATE_META) is left alone, also by a backfill.
 *
 * @return int[]
 */
function brikpanel_foreign_currency_order_ids() {
    global $wpdb;
    $is_hpos = get_option( 'woocommerce_custom_orders_table_enabled' ) === 'yes';
    $base    = brikpanel_base_currency();

    if ( $is_hpos ) {
        return array_map(
            'intval',
            $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT o.id FROM {$wpdb->prefix}wc_orders o
                     WHERE o.type = 'shop_order' AND o.currency <> '' AND o.currency <> %s
                       AND NOT EXISTS (
                           SELECT 1 FROM {$wpdb->prefix}wc_orders_meta k
                           WHERE k.order_id = o.id AND k.meta_key = %s
                       )",
                    $base,
                    BRIKPANEL_FX_RATE_META
                )
            )
        );
    }

    return array_map(
        'intval',
        $wpdb->get_col(
            $wpdb->prepare(
                "SELECT pm.post_id FROM {$wpdb->postmeta} pm
                 WHERE pm.meta_key = '_order_currency' AND pm.meta_value <> '' AND pm.meta_value <> %s
                   AND NOT EXISTS (
                       SELECT 1 FROM {$wpdb->postmeta} k
                       WHERE k.post_id = pm.post_id AND k.meta_key = %s
                   )",
                $base,
                BRIKPANEL_FX_RATE_META
            )
        )
    );
}

/**
 * Process one batch of the backfill queue (a transient list of order ids),
 * then reschedule itself until the queue is empty. Busts the analytics caches
 * once the last batch completes so the dashboard reflects converted figures.
 *
 * @return void
 */
function brikpanel_fx_backfill_batch() {
    $queue = get_transient( 'brikpanel_fx_backfill_queue' );
    if ( ! is_array( $queue ) ) {
        return;
    }

    $batch = array_splice( $queue, 0, 50 );
    foreach ( $batch as $order_id ) {
        brikpanel_refresh_order_base_total( (int) $order_id );
    }

    if ( ! empty( $queue ) ) {
        set_transient( 'brikpanel_fx_backfill_queue', $queue, DAY_IN_SECONDS );
        wp_schedule_single_event( time() + 30, 'brikpanel_fx_backfill_batch' );
    } else {
        delete_transient( 'brikpanel_fx_backfill_queue' );
        if ( function_exists( 'brikpanel_bust_data_caches' ) ) {
            brikpanel_bust_data_caches();
        }
    }
}
add_action( 'brikpanel_fx_backfill_batch', 'brikpanel_fx_backfill_batch' );

/**
 * Queue every foreign-currency order for (re)conversion and kick off the first
 * batch. Idempotent — safe to call after a rate change or plugin upgrade.
 *
 * @return int Number of orders queued.
 */
function brikpanel_fx_queue_backfill() {
    $ids = brikpanel_foreign_currency_order_ids();
    if ( empty( $ids ) ) {
        delete_transient( 'brikpanel_order_currencies' );
        return 0;
    }
    set_transient( 'brikpanel_fx_backfill_queue', array_values( $ids ), DAY_IN_SECONDS );
    delete_transient( 'brikpanel_order_currencies' );
    if ( ! wp_next_scheduled( 'brikpanel_fx_backfill_batch' ) ) {
        wp_schedule_single_event( time() + 5, 'brikpanel_fx_backfill_batch' );
    }
    return count( $ids );
}

/**
 * When the merchant edits the manual rate table, re-run the backfill (the
 * fallback factor changed) and clear analytics caches.
 *
 * @return void
 */
function brikpanel_fx_on_rates_changed() {
    brikpanel_fx_queue_backfill();
    if ( function_exists( 'brikpanel_bust_data_caches' ) ) {
        brikpanel_bust_data_caches();
    }
}
add_action( 'update_option_' . BRIKPANEL_FX_RATES_OPTION, 'brikpanel_fx_on_rates_changed' );
add_action( 'add_option_' . BRIKPANEL_FX_RATES_OPTION, 'brikpanel_fx_on_rates_changed' );

/**
 * When WCML's settings are saved (rates edited by hand or by WCML's automatic
 * rates, a currency added, multi-currency toggled), convert the orders that
 * have no rate yet: a currency WCML just started to rate. Orders already
 * converted keep the rate they were converted at (BRIKPANEL_FX_RATE_META);
 * until 3.3.44 this pass converted every past order again at the new rate,
 * so a 20 USD order sold at 1.10 shrank when the rate went to 2.20 (found by
 * WPML's compatibility review). No cache bust here: the backfill busts once
 * it has converted something. Gated on multi-currency being (or having been)
 * enabled so unrelated WCML settings saves on single-currency stores don't
 * queue a pointless pass.
 *
 * @param mixed $old_value Previous option value (null on first add).
 * @param mixed $value     New option value.
 * @return void
 */
function brikpanel_fx_on_wcml_settings_changed( $old_value, $value = null ) {
    $was = is_array( $old_value ) && 2 === (int) ( $old_value['enable_multi_currency'] ?? 0 );
    $now = is_array( $value ) && 2 === (int) ( $value['enable_multi_currency'] ?? 0 );
    if ( $was || $now ) {
        brikpanel_fx_queue_backfill();
    }
}
add_action( 'update_option__wcml_settings', 'brikpanel_fx_on_wcml_settings_changed', 10, 2 );
add_action( 'add_option__wcml_settings', function ( $option, $value ) {
    brikpanel_fx_on_wcml_settings_changed( null, $value );
}, 10, 2 );
