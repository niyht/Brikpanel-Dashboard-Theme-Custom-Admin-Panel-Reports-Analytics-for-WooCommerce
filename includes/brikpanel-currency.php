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
 *   2. A flat per-currency rate the merchant enters in
 *      BrikPanel ▸ Settings ▸ Currency (fallback for stores whose plugin does
 *      not snapshot a rate, or for orders that predate the plugin).
 *   3. None — the order is left at its raw total (current behaviour).
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
 * Resolve how to express an order's total in the store base currency.
 *
 * @param WC_Order $order Order object.
 * @return array{base_total: float|null, factor: float, source: string}
 *               base_total is null when the order is in a foreign currency for
 *               which no rate could be resolved (caller should leave it raw).
 *               source is one of 'base' | 'plugin' | 'manual' | 'none'.
 */
function brikpanel_resolve_order_base_total( $order ) {
    $base           = brikpanel_base_currency();
    $order_currency = $order->get_currency();
    $total          = (float) $order->get_total();

    if ( ! $order_currency || strtoupper( $order_currency ) === strtoupper( $base ) ) {
        return [ 'base_total' => $total, 'factor' => 1.0, 'source' => 'base' ];
    }

    // 1) Day-of-sale rate snapshotted by a multi-currency plugin.
    $factor = brikpanel_plugin_fx_factor( $order, $order_currency );
    $source = 'plugin';

    // 2) Merchant's flat fallback rate.
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
        ];
    }

    // 3) Foreign currency, no rate available — leave raw.
    return [ 'base_total' => null, 'factor' => 0.0, 'source' => 'none' ];
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
        $val = wc_format_decimal( $resolved['base_total'], wc_get_price_decimals() );
        if ( (string) $existing !== (string) $val ) {
            $order->update_meta_data( BRIKPANEL_BASE_TOTAL_META, $val );
            $order->save_meta_data();
        }
    } elseif ( '' !== $existing && null !== $existing ) {
        // Currency reverted to base, or rate withdrawn — drop a stale snapshot.
        $order->delete_meta_data( BRIKPANEL_BASE_TOTAL_META );
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

/* -------------------------------------------------------------------------
 * Backfill existing foreign-currency orders in the background.
 * ---------------------------------------------------------------------- */

/**
 * IDs of every order whose currency differs from the store base currency.
 * These are the only orders that ever need a conversion snapshot.
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
                    "SELECT id FROM {$wpdb->prefix}wc_orders WHERE type = 'shop_order' AND currency <> '' AND currency <> %s",
                    $base
                )
            )
        );
    }

    return array_map(
        'intval',
        $wpdb->get_col(
            $wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_order_currency' AND meta_value <> '' AND meta_value <> %s",
                $base
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
