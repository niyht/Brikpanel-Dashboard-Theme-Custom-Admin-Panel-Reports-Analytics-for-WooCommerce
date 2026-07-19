<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Check if the current user is a site administrator.
 * Used to skip tracking for admin actions (cart, checkout, visits, etc.).
 *
 * @return bool
 */
function brikpanel_is_admin_user() {
    return is_user_logged_in() && current_user_can( 'manage_options' );
}

/**
 * Whether a persistent external object cache (Redis / Memcached / etc.) is
 * active. On shared hosts without one, every wp_cache_set is per-request
 * only and every transient round-trips to wp_options — so we extend cache
 * TTLs and pick storage backends accordingly.
 *
 * @return bool
 */
function brikpanel_has_object_cache() {
    static $has = null;
    if ( null === $has ) {
        $has = function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache();
    }
    return $has;
}

/**
 * Adjust a cache TTL for the host's storage backend. With external object
 * cache present, the base TTL is fine. Without it, transients hit
 * wp_options on every read/write; we extend the TTL so the same operation
 * costs less per unit time.
 *
 * @param int $base_ttl Seconds when an external object cache is present.
 * @param int $multiplier How many times longer to cache when there isn't one.
 * @return int
 */
function brikpanel_cache_ttl( $base_ttl, $multiplier = 5 ) {
    return brikpanel_has_object_cache() ? (int) $base_ttl : (int) ( $base_ttl * $multiplier );
}

/**
 * Shared data version used by every order-derived cache key (revenue,
 * order count, visitor count, dashboard payload, …). Bumping this value
 * via brikpanel_bust_data_caches() invalidates every keyed transient at
 * once — no need to enumerate or delete individual keys.
 *
 * @return int
 */
function brikpanel_data_cache_ver() {
    static $ver = null;
    if ( null === $ver ) {
        $ver = (int) get_option( 'brikpanel_data_cache_ver', 1 );
    }
    return $ver;
}

/**
 * Invalidate every order-derived cache by bumping the shared version.
 * Hooked to woocommerce_new_order / status_changed / refunded so dashboard
 * KPIs stay live without manual cache wiring per metric.
 */
function brikpanel_bust_data_caches() {
    update_option( 'brikpanel_data_cache_ver', (int) get_option( 'brikpanel_data_cache_ver', 1 ) + 1, false );
}
add_action( 'woocommerce_new_order',            'brikpanel_bust_data_caches' );
add_action( 'woocommerce_order_status_changed', 'brikpanel_bust_data_caches' );
add_action( 'woocommerce_order_refunded',       'brikpanel_bust_data_caches' );

/**
 * Bust the shared data cache when a product's cost of goods changes so the
 * dashboard Profit section reflects new costs immediately instead of after
 * the cache TTL. Fires for every write path (BrikPanel editor, import,
 * programmatic update) since it hooks the meta change itself.
 *
 * @param int    $meta_id    Unused.
 * @param int    $object_id  Unused.
 * @param string $meta_key   Meta key that changed.
 */
function brikpanel_bust_data_caches_on_cogs_meta( $meta_id, $object_id, $meta_key ) {
    if ( '_brikpanel_cogs' === $meta_key || '_cogs_total_value' === $meta_key || '_cogs_value_is_additive' === $meta_key ) {
        brikpanel_bust_data_caches();
    }
}
add_action( 'added_post_meta',   'brikpanel_bust_data_caches_on_cogs_meta', 10, 3 );
add_action( 'updated_post_meta', 'brikpanel_bust_data_caches_on_cogs_meta', 10, 3 );
add_action( 'deleted_post_meta', 'brikpanel_bust_data_caches_on_cogs_meta', 10, 3 );

/**
 * Keep BrikPanel's own cost meta in lockstep with WooCommerce's native
 * Cost of Goods Sold field (WooCommerce 9.5+).
 *
 * BrikPanel reads product cost from its own `_brikpanel_cogs` meta on every
 * surface: the dashboard Profit math, the product-list Cost column, Quick
 * Edit and the Google Sheets export. The product editor already mirrors
 * BrikPanel -> WC native on save, but a merchant can instead fill the cost
 * from WooCommerce's own product screen, which writes only `_cogs_total_value`.
 * Without the reverse mirror that cost stays invisible to BrikPanel, so the
 * dashboard COGS reads as empty even though the value is clearly set on the
 * product. This hook closes the gap live for BOTH simple products and
 * variations: WooCommerce stores the per-object value in `_cogs_total_value`
 * and deletes the row when a variation inherits its parent, which is exactly
 * the present/absent semantics `_brikpanel_cogs` already uses, so a straight
 * value copy keeps the two perfectly in step (existing data is backfilled once
 * on upgrade by brikpanel_backfill_native_cogs()).
 *
 * Only products and variations are mirrored: WooCommerce also stores
 * `_cogs_total_value` on orders, which must never bleed into product cost meta.
 *
 * @param int    $meta_id    Unused (an array of ids on the delete hook).
 * @param int    $object_id  Product or variation ID whose meta changed.
 * @param string $meta_key   Meta key that changed.
 * @param mixed  $meta_value New value ('' on delete / clear).
 */
function brikpanel_mirror_wc_native_cogs( $meta_id, $object_id, $meta_key, $meta_value = '' ) {
    if ( '_cogs_total_value' !== $meta_key ) {
        return;
    }
    if ( ! in_array( get_post_type( $object_id ), array( 'product', 'product_variation' ), true ) ) {
        return;
    }

    $native  = (string) $meta_value;
    $current = (string) get_post_meta( $object_id, '_brikpanel_cogs', true );

    // Cleared on the native side (variation now inherits its parent, or the
    // merchant emptied the field) -> drop BrikPanel's mirrored copy so the
    // variation->parent fallback kicks back in instead of a stale cost.
    if ( '' === $native ) {
        if ( '' !== $current ) {
            delete_post_meta( $object_id, '_brikpanel_cogs' );
        }
        return;
    }

    // Normalise both sides through wc_format_decimal so 12.5 and 12.50 do not
    // ping-pong, and only write on a real change (a no-op update_post_meta
    // would not fire, but guarding also skips the cache bust for free).
    $formatted = wc_format_decimal( $native );
    if ( '' === $current || (float) $current !== (float) $formatted ) {
        update_post_meta( $object_id, '_brikpanel_cogs', $formatted );
    }
}
add_action( 'added_post_meta',   'brikpanel_mirror_wc_native_cogs', 10, 4 );
add_action( 'updated_post_meta', 'brikpanel_mirror_wc_native_cogs', 10, 4 );
add_action( 'deleted_post_meta', 'brikpanel_mirror_wc_native_cogs', 10, 4 );

/**
 * Reverse mirror: propagate BrikPanel's legacy `_brikpanel_cogs` writes into
 * WooCommerce's native `_cogs_total_value`.
 *
 * Every BrikPanel write path pairs its legacy-meta write with
 * WC_Product::set_cogs_value(), but that setter is a silent no-op whenever
 * the WC Cost of Goods feature flag is off — which would leave the native
 * key (the read side's source of truth) stale. Mirroring at the meta layer
 * closes that gap for all writers at once, flag on or off.
 *
 * No ping-pong with the forward mirror above: both mirrors normalise through
 * wc_format_decimal and only write on a real value change, so the second hop
 * always sees equal values and stops.
 *
 * @param int    $meta_id    Unused (an array of ids on the delete hook).
 * @param int    $object_id  Product or variation ID whose meta changed.
 * @param string $meta_key   Meta key that changed.
 * @param mixed  $meta_value New value ('' on delete / clear).
 */
function brikpanel_mirror_legacy_cogs_to_native( $meta_id, $object_id, $meta_key, $meta_value = '' ) {
    if ( '_brikpanel_cogs' !== $meta_key ) {
        return;
    }
    if ( ! in_array( get_post_type( $object_id ), array( 'product', 'product_variation' ), true ) ) {
        return;
    }

    $legacy = (string) $meta_value;
    $native = (string) get_post_meta( $object_id, '_cogs_total_value', true );

    if ( '' === $legacy ) {
        if ( '' !== $native ) {
            delete_post_meta( $object_id, '_cogs_total_value' );
        }
        return;
    }

    $formatted = wc_format_decimal( $legacy );
    if ( '' === $native || (float) $native !== (float) $formatted ) {
        update_post_meta( $object_id, '_cogs_total_value', $formatted );
    }
}
add_action( 'added_post_meta',   'brikpanel_mirror_legacy_cogs_to_native', 10, 4 );
add_action( 'updated_post_meta', 'brikpanel_mirror_legacy_cogs_to_native', 10, 4 );
add_action( 'deleted_post_meta', 'brikpanel_mirror_legacy_cogs_to_native', 10, 4 );

/**
 * The cost defined directly on ONE product or variation post — no parent
 * fallback, no additive math. WooCommerce's native `_cogs_total_value` meta
 * is the source of truth; BrikPanel's legacy `_brikpanel_cogs` covers values
 * that only ever existed on the legacy key. Reading the raw meta (instead of
 * WC_Product::get_cogs_value()) keeps this working even when the merchant
 * has switched WooCommerce's Cost of Goods Sold feature off — the data stays
 * in the database either way.
 *
 * @param int $post_id Product or variation ID.
 * @return string Decimal string, or '' when no cost is on file.
 */
function brikpanel_product_cogs_raw( $post_id ) {
    $native = (string) get_post_meta( (int) $post_id, '_cogs_total_value', true );
    if ( '' !== $native ) {
        return $native;
    }
    return (string) get_post_meta( (int) $post_id, '_brikpanel_cogs', true );
}

/**
 * The EFFECTIVE unit cost of a product line, resolved the same way the
 * profit engine's SQL does: variation cost first, parent product cost as
 * fallback, and WooCommerce's additive-variation flag honoured (variation
 * cost added on top of the parent's when `_cogs_value_is_additive` is yes).
 *
 * This is the single integration point for cost: the result runs through the
 * `brikpanel_product_cogs` filter, so a store that keeps cost in another
 * plugin's field can point every BrikPanel per-product read at it from one
 * hook. (The dashboard profit aggregates read the same two meta keys in SQL
 * for performance, so a filter-based override only affects per-product
 * surfaces; writing `_cogs_total_value` covers everything.)
 *
 * @param int $product_id   Parent (or simple) product ID.
 * @param int $variation_id Variation ID, 0 for simple products.
 * @return float|null Unit cost, or null when no cost is on file at all.
 */
function brikpanel_product_cogs( $product_id, $variation_id = 0 ) {
    $product_id   = (int) $product_id;
    $variation_id = (int) $variation_id;

    $pval = '' !== ( $p = brikpanel_product_cogs_raw( $product_id ) ) ? (float) $p : null;
    $cost = $pval;

    if ( $variation_id > 0 ) {
        $vval = '' !== ( $v = brikpanel_product_cogs_raw( $variation_id ) ) ? (float) $v : null;
        if ( 'yes' === get_post_meta( $variation_id, '_cogs_value_is_additive', true ) ) {
            $cost = ( null === $vval && null === $pval ) ? null : (float) $vval + (float) $pval;
        } else {
            $cost = ( null !== $vval ) ? $vval : $pval;
        }
    }

    /**
     * Filter the resolved unit cost BrikPanel uses for a product line.
     *
     * @param float|null $cost         Resolved cost (null = no cost on file).
     * @param int        $product_id   Parent (or simple) product ID.
     * @param int        $variation_id Variation ID (0 for simple products).
     */
    return apply_filters( 'brikpanel_product_cogs', $cost, $product_id, $variation_id );
}

/**
 * Whether BrikPanel's own front-end visitor tracking is enabled.
 *
 * Gates every storefront beacon and counter: the daily visitor / page-view /
 * product-view scripts, the live-visitor ping, the checkout counter and both
 * add-to-cart counters. Stores that already run a dedicated analytics plugin
 * can switch all of it off from WooCommerce ▸ Settings ▸ BrikPanel ▸
 * Analytics; the dashboard cards those counters feed then simply stop
 * receiving new data. Defaults to on so existing installs are unaffected.
 *
 * @return bool
 */
function brikpanel_frontend_tracking_enabled() {
    return get_option( 'brikpanel_frontend_tracking', 'yes' ) !== 'no';
}

/**
 * Interval, in seconds, between live-visitor pings from an open storefront tab.
 *
 * Clamped to 10–300: below 10 s the ping would defeat its own server-side
 * rate limit, above 5 minutes a visitor would be considered gone (and drop
 * off the Live widget) between two legitimate pings of the same open tab.
 *
 * @return int
 */
function brikpanel_live_ping_interval() {
    $interval = (int) get_option( 'brikpanel_live_ping_interval', 30 );
    return max( 10, min( 300, $interval > 0 ? $interval : 30 ) );
}

/**
 * Whether the Live widget may store/show logged-in customer details
 * (name, e-mail, phone) alongside the anonymous visit data.
 *
 * When off, live tracking still counts the visitor and shows the page and
 * cart state, but no personal fields are cached at all — useful for stores
 * that prefer strict data minimisation. Defaults to on (existing behaviour).
 *
 * @return bool
 */
function brikpanel_live_customer_details_enabled() {
    return get_option( 'brikpanel_live_customer_details', 'yes' ) !== 'no';
}

/**
 * Option name storing the order statuses a merchant counts as valid,
 * realised sales (revenue, order count, AOV, profit, lifetime value).
 */
const BRIKPANEL_PAID_STATUSES_OPTION = 'brikpanel_paid_statuses';

/**
 * Option name storing the order statuses a merchant treats as refunds.
 */
const BRIKPANEL_REFUNDED_STATUSES_OPTION = 'brikpanel_refunded_statuses';

/**
 * Factory defaults for the "valid sales" bucket. Processing + completed is
 * what WooCommerce itself treats as paid for its native reports.
 *
 * @return string[]
 */
function brikpanel_default_paid_statuses() {
    return [ 'wc-processing', 'wc-completed' ];
}

/**
 * Factory default for the "refunded" bucket — WooCommerce's native fully
 * refunded status.
 *
 * @return string[]
 */
function brikpanel_default_refunded_statuses() {
    return [ 'wc-refunded' ];
}

/**
 * Validate a raw status list: keep only non-empty strings, normalise each to
 * the wc- prefix WooCommerce uses internally, drop duplicates. Falls back to
 * the supplied default when nothing usable remains so analytics never query
 * an empty status set (which would zero out every revenue figure).
 *
 * @param mixed    $list    Raw value (option payload or filter return).
 * @param string[] $default Fallback list.
 * @return string[]
 */
function brikpanel_normalise_statuses( $list, array $default ) {
    if ( ! is_array( $list ) ) {
        return $default;
    }
    $out = [];
    foreach ( $list as $status ) {
        if ( ! is_string( $status ) ) {
            continue;
        }
        $status = trim( $status );
        if ( '' === $status ) {
            continue;
        }
        if ( 0 !== strpos( $status, 'wc-' ) ) {
            $status = 'wc-' . $status;
        }
        $out[] = $status;
    }
    $out = array_values( array_unique( $out ) );
    return empty( $out ) ? $default : $out;
}

/**
 * Order statuses that count as realised, valid sales across every BrikPanel
 * analytic: the headline KPI cards (Total Sales, Orders, AOV), Profit, the
 * store summary, marketplace breakdowns and the lifetime-value engine.
 *
 * Merchants pick the set in BrikPanel ▸ Settings ▸ Analytics. Stores that use
 * a shipment-tracking plugin (custom "Partially shipped" / "Delivered"
 * statuses) or take a lot of offline payments add those statuses there so
 * their orders stop being silently dropped from the figures.
 *
 * The legacy brikpanel_kpi_statuses filter still runs last for developers who
 * set the list in code.
 *
 * @return string[]
 */
function brikpanel_paid_order_statuses() {
    $default = brikpanel_default_paid_statuses();
    $stored  = get_option( BRIKPANEL_PAID_STATUSES_OPTION, null );
    $base    = ( null === $stored ) ? $default : brikpanel_normalise_statuses( $stored, $default );

    /**
     * Filter the order statuses counted as valid sales. Back-compat hook —
     * the same list is now editable from the Analytics settings screen.
     *
     * @param string[] $statuses Resolved status list.
     */
    $filtered = apply_filters( 'brikpanel_kpi_statuses', $base );

    return brikpanel_normalise_statuses( $filtered, $base );
}

/**
 * Order statuses a merchant treats as refunds. Used by the refund counters
 * and folded into the lifetime-value set so refunded customers still register
 * as having ordered.
 *
 * @return string[]
 */
function brikpanel_refunded_order_statuses() {
    $default = brikpanel_default_refunded_statuses();
    $stored  = get_option( BRIKPANEL_REFUNDED_STATUSES_OPTION, null );
    $base    = ( null === $stored ) ? $default : brikpanel_normalise_statuses( $stored, $default );

    /**
     * Filter the order statuses counted as refunds.
     *
     * @param string[] $statuses Resolved status list.
     */
    $filtered = apply_filters( 'brikpanel_refunded_statuses', $base );

    return brikpanel_normalise_statuses( $filtered, $base );
}

/**
 * Statuses counted by the lifetime-value / RFM engine: every valid sale plus
 * every refund. A refunded order still represents a customer who bought, so it
 * belongs in the lifetime history even though its money nets out.
 *
 * @return string[]
 */
function brikpanel_ltv_counted_statuses() {
    return array_values( array_unique( array_merge(
        brikpanel_paid_order_statuses(),
        brikpanel_refunded_order_statuses()
    ) ) );
}

/**
 * Backwards-compatible alias kept for the headline KPI callers. Delegates to
 * the unified valid-sales set.
 *
 * @return string[]
 */
function brikpanel_kpi_revenue_statuses() {
    return brikpanel_paid_order_statuses();
}

/**
 * Render a status list as a SQL-safe, quoted, comma-separated fragment for an
 * `IN (...)` clause — e.g. "'wc-processing','wc-completed'". Values originate
 * from a fixed whitelist (registered WooCommerce statuses) and are still run
 * through esc_sql() as defence in depth.
 *
 * @param string[] $statuses Status keys.
 * @return string
 */
function brikpanel_statuses_sql_in( array $statuses ) {
    $quoted = array_map(
        static function ( $s ) {
            return "'" . esc_sql( $s ) . "'";
        },
        $statuses
    );
    return implode( ',', $quoted );
}

/**
 * Quoted IN-list of the valid-sales statuses, ready to interpolate into a
 * double-quoted SQL string.
 *
 * @return string
 */
function brikpanel_paid_statuses_sql() {
    return brikpanel_statuses_sql_in( brikpanel_paid_order_statuses() );
}

/**
 * When the merchant changes either status bucket, invalidate every
 * order-derived cache and queue a lifetime-value / cohort recompute so the
 * precomputed customer table reflects the new definition without waiting for
 * the nightly cron.
 */
function brikpanel_on_status_buckets_changed() {
    brikpanel_bust_data_caches();
    if ( ! wp_next_scheduled( 'brikpanel_recompute_customer_metrics' ) ) {
        wp_schedule_single_event( time() + 5, 'brikpanel_recompute_customer_metrics' );
    }
    if ( ! wp_next_scheduled( 'brikpanel_recompute_cohort_retention' ) ) {
        wp_schedule_single_event( time() + 10, 'brikpanel_recompute_cohort_retention' );
    }
}
add_action( 'update_option_' . BRIKPANEL_PAID_STATUSES_OPTION, 'brikpanel_on_status_buckets_changed' );
add_action( 'update_option_' . BRIKPANEL_REFUNDED_STATUSES_OPTION, 'brikpanel_on_status_buckets_changed' );
add_action( 'add_option_' . BRIKPANEL_PAID_STATUSES_OPTION, 'brikpanel_on_status_buckets_changed' );
add_action( 'add_option_' . BRIKPANEL_REFUNDED_STATUSES_OPTION, 'brikpanel_on_status_buckets_changed' );

/**
 * Get all administrator user IDs (users with manage_options capability).
 * Cached in object cache for 5 minutes to avoid repeated DB queries.
 *
 * @return int[] Array of admin user IDs.
 */
function brikpanel_get_admin_user_ids() {
    $key = 'brikpanel_admin_user_ids';

    // Persistent backend selection: object cache when available, transient
    // otherwise. Without this fallback the cache is per-request only on
    // shared hosts → every dashboard call re-runs get_users().
    $has_oc = brikpanel_has_object_cache();
    $cached = $has_oc ? wp_cache_get( $key ) : get_transient( $key );
    if ( false !== $cached ) {
        return $cached;
    }

    $admins = get_users( [
        'capability' => 'manage_options',
        'fields'     => 'ID',
    ] );
    $admin_ids = array_map( 'intval', $admins );

    if ( $has_oc ) {
        wp_cache_set( $key, $admin_ids, '', 300 );
    } else {
        set_transient( $key, $admin_ids, brikpanel_cache_ttl( 300 ) );
    }
    return $admin_ids;
}

/**
 * Build SQL fragments to exclude orders placed by admin users.
 * Returns an array with 'sql' (the WHERE clause fragment) and 'args' (values for prepare).
 * If there are no admins, returns empty sql/args so queries work unchanged.
 *
 * @param bool $hpos Whether HPOS is active.
 * @param string $id_column The column/expression referencing the order ID (e.g. 'id', 'o.id', 'p.ID').
 * @return array{sql: string, args: int[]}
 */
function brikpanel_admin_order_exclusion_sql( $hpos, $id_column = '' ) {
    $admin_ids = brikpanel_get_admin_user_ids();
    if ( empty( $admin_ids ) ) {
        return [ 'sql' => '', 'args' => [] ];
    }

    $placeholders = implode( ', ', array_fill( 0, count( $admin_ids ), '%d' ) );

    if ( $hpos ) {
        return [
            'sql'  => " AND customer_id NOT IN ({$placeholders})",
            'args' => $admin_ids,
        ];
    }

    // Legacy: exclude by _customer_user meta
    global $wpdb;
    $col = $id_column ?: 'ID';
    return [
        'sql'  => " AND {$col} NOT IN (SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_customer_user' AND meta_value IN ({$placeholders}))",
        'args' => $admin_ids,
    ];
}

/**
 * Whether the BrikMarket plugin (multichannel marketplace integration) is
 * loaded and ready. Cached per-request: dashboard counters can call this
 * many times in a single render.
 *
 * @return bool
 */
function brikpanel_brikmarket_active() {
    static $active = null;
    if ( null === $active ) {
        $active = defined( 'BRIKMARKET_VERSION' ) && class_exists( 'BrikMarket_Marketplace_Registry' );
    }
    return $active;
}

/**
 * Order meta key BrikMarket sets on every imported marketplace order.
 *
 * Single source of truth — used by exclusion SQL and analytics breakdowns.
 *
 * @return string
 */
function brikpanel_marketplace_meta_key() {
    return '_brksoft_marketplace';
}

/**
 * Build SQL fragments to exclude marketplace orders (orders imported via
 * BrikMarket) from a query. Returns empty fragment when BrikMarket is
 * inactive — callers can append unconditionally.
 *
 * @param bool   $hpos      Whether HPOS is active.
 * @param string $id_column Column referencing the order ID (e.g. 'id', 'o.id', 'p.ID').
 * @return array{sql: string, args: array}
 */
function brikpanel_marketplace_order_exclusion_sql( $hpos, $id_column = '' ) {
    if ( ! brikpanel_brikmarket_active() ) {
        return [ 'sql' => '', 'args' => [] ];
    }
    global $wpdb;
    $meta_key = brikpanel_marketplace_meta_key();
    // Correlated NOT EXISTS rather than `NOT IN (subquery)`. NOT IN is a double
    // footgun here: (a) if the meta subquery ever yields a NULL it makes the
    // whole predicate UNKNOWN and silently drops EVERY row (revenue → 0), and
    // (b) when the outer query already LEFT JOINs the very same meta table
    // (e.g. the multi-currency base-total join in brikpanel_get_total_revenue
    // aliases wc_orders_meta), MySQL mis-resolves the un-aliased subquery and
    // zeroes the result. NOT EXISTS with its own alias is NULL-safe, immune to
    // that collision, and typically faster. Equivalent meaning: exclude orders
    // that HAVE the marketplace marker meta.
    if ( $hpos ) {
        $col = $id_column ?: 'id';
        // The correlated subquery reads wc_orders_meta, which ALSO owns an `id`
        // column. A bare/unqualified `id` from the caller binds to the
        // subquery's OWN row id (inner scope wins), so the correlation becomes
        // `bpmpx.order_id = bpmpx.id` — almost never true — making NOT EXISTS
        // always pass and silently excluding NOTHING. On HPOS the orders table
        // is always {$prefix}wc_orders, so qualify a bare id to it. Callers that
        // alias the table (e.g. 'o.id') already pass a qualified column and are
        // left untouched. This was a silent no-op on every marketplace-excluding
        // KPI on HPOS stores (revenue, orders, AOV, conversion, charts).
        if ( 'id' === $col ) {
            $col = $wpdb->prefix . 'wc_orders.id';
        }
        return [
            'sql'  => " AND NOT EXISTS (SELECT 1 FROM {$wpdb->prefix}wc_orders_meta bpmpx WHERE bpmpx.order_id = {$col} AND bpmpx.meta_key = %s)",
            'args' => [ $meta_key ],
        ];
    }
    $col = $id_column ?: 'ID';
    return [
        'sql'  => " AND NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} bpmpx WHERE bpmpx.post_id = {$col} AND bpmpx.meta_key = %s)",
        'args' => [ $meta_key ],
    ];
}

/**
 * Static palette + label fallbacks for the marketplaces BrikMarket ships
 * with. Used when the marketplace registry is empty (e.g. the dashboard
 * runs before brikmarket_init has registered modules) or for branded UI
 * accents (charts, badges).
 *
 * @return array<string, array{label: string, color: string}>
 */
function brikpanel_marketplace_palette() {
    return [
        'amazon'      => [ 'label' => 'Amazon',      'color' => '#ff9900' ],
        'trendyol'    => [ 'label' => 'Trendyol',    'color' => '#f27a1a' ],
        'hepsiburada' => [ 'label' => 'Hepsiburada', 'color' => '#ff6000' ],
        'n11'         => [ 'label' => 'n11',         'color' => '#71BC44' ],
        'ozon'        => [ 'label' => 'Ozon',        'color' => '#005bff' ],
    ];
}

/**
 * Resolve display metadata (label, brand color, optional logo URL) for a
 * marketplace ID. Prefers the live registry value, falls back to the
 * static palette for unknowns.
 *
 * @param string $marketplace_id e.g. 'trendyol', 'amazon'.
 * @return array{label: string, color: string, logo?: string}
 */
function brikpanel_marketplace_meta( $marketplace_id ) {
    $palette = brikpanel_marketplace_palette();
    $meta    = $palette[ $marketplace_id ] ?? [
        'label' => ucfirst( $marketplace_id ),
        'color' => '#666666',
    ];

    if ( class_exists( 'BrikMarket_Marketplace_Registry' ) ) {
        $mp = BrikMarket_Marketplace_Registry::get( $marketplace_id );
        if ( $mp ) {
            $name = (string) $mp->get_name();
            if ( '' !== $name ) {
                $meta['label'] = $name;
            }
            if ( method_exists( $mp, 'get_logo_url' ) ) {
                $logo = (string) $mp->get_logo_url();
                if ( '' !== $logo ) {
                    $meta['logo'] = $logo;
                }
            }
        }
    }
    return $meta;
}

/**
 * Force ACF to register its per-field-group metaboxes against the given
 * product post object. ACF only hooks `add_meta_boxes` from inside
 * `load-post.php` / `load-post-new.php`, neither of which fires on the
 * BrikPanel custom product editor page — so without this bridge any ACF
 * field group targeting `product` is silently dropped from the metabox
 * picker and from the rendered editor.
 *
 * Calls ACF_Form_Post::add_meta_boxes() directly so we do not add a
 * duplicate listener on `add_meta_boxes` (which would re-emit boxes on
 * every subsequent call during the same request).
 *
 * @param WP_Post|object $post A product post (or stub with ID/post_type).
 * @param string         $post_type The post type to register boxes against. Default 'product'.
 * @return void
 */
function brikpanel_bootstrap_acf_post_metaboxes( $post, $post_type = 'product' ) {
    if ( ! function_exists( 'acf_get_instance' ) ) {
        return;
    }
    $form_post = acf_get_instance( 'ACF_Form_Post' );
    if ( ! $form_post || ! method_exists( $form_post, 'add_meta_boxes' ) ) {
        return;
    }
    try {
        $form_post->add_meta_boxes( $post_type, $post );
    } catch ( \Throwable $e ) {
        // Swallow — ACF misconfiguration should not break the editor page.
    }
}

/**
 * Resolve the ACF field group metabox IDs whose Location Rules match the
 * given product (or the generic `product` post type when no product id is
 * available, e.g. on the Add New screen). ACF field groups encode their
 * own target context via Location Rules, so once an admin has configured
 * a group for products their intent is unambiguous — the user shouldn't
 * also have to add it manually to the BrikPanel metabox multiselect.
 * This helper returns the matching IDs so the editor + enqueue paths can
 * fold them into the rendered + asset list automatically.
 *
 * ACF's own metabox id pattern is `acf-{group_key}` (see
 * ACF_Form_Post::add_meta_boxes()). The helper returns the same pattern
 * so callers can merge it directly into $selected_metaboxes.
 *
 * Site owners can disable auto-inclusion via the
 * `brikpanel_pe_acf_auto` option (set to 'no') or via the
 * `brikpanel_pe_auto_include_acf` filter (return false).
 *
 * @param int    $product_id The product id, or 0 for new products.
 * @param string $post_type  The post type whose ACF groups should resolve. Default 'product'.
 * @return string[] Array of metabox IDs (e.g. ['acf-group_my_specs']) or empty array.
 */
function brikpanel_resolve_auto_acf_metabox_ids( $product_id = 0, $post_type = 'product' ) {
    if ( ! function_exists( 'acf_get_field_groups' ) ) {
        return array();
    }
    if ( 'yes' !== get_option( 'brikpanel_pe_acf_auto', 'yes' ) ) {
        return array();
    }
    if ( ! apply_filters( 'brikpanel_pe_auto_include_acf', true, (int) $product_id, $post_type ) ) {
        return array();
    }

    try {
        $args = array( 'post_type' => $post_type );
        if ( $product_id ) {
            $args['post_id'] = (int) $product_id;
        }
        $groups = acf_get_field_groups( $args );
    } catch ( \Throwable $e ) {
        return array();
    }

    if ( empty( $groups ) || ! is_array( $groups ) ) {
        return array();
    }

    $ids = array();
    foreach ( $groups as $group ) {
        if ( empty( $group['key'] ) ) {
            continue;
        }
        $ids[] = 'acf-' . $group['key'];
    }
    return $ids;
}

/**
 * Enqueue ACF's input + uploader assets on a page that is not the native
 * post edit screen. ACF's `admin_enqueue_scripts` callback runs at
 * priority 20; BrikPanel's product-editor enqueue runs at priority 99,
 * so by the time we call `acf_enqueue_scripts()` the native enqueue hook
 * has already fired. We therefore also force the assets class to run
 * enqueue_scripts() synchronously.
 *
 * @return void
 */
function brikpanel_bootstrap_acf_assets() {
    if ( ! function_exists( 'acf_enqueue_scripts' ) ) {
        return;
    }
    acf_enqueue_scripts( array( 'uploader' => true ) );
    if ( function_exists( 'acf_get_instance' ) ) {
        $assets = acf_get_instance( 'ACF_Assets' );
        if ( $assets && method_exists( $assets, 'enqueue_scripts' ) ) {
            try {
                $assets->enqueue_scripts();
            } catch ( \Throwable $e ) {
                // Skip — asset loading failure should not break the editor page.
            }
        }
    }
}

/**
 * Collect downloadable-file access data for every line item in an order, keyed
 * by order_item_id so the front-end can render per-line download counts inline
 * without re-hitting the server.
 *
 * Returns an associative array of the shape:
 *   [
 *     (int) order_item_id => [
 *       [
 *         'name'      => (string) file display name,
 *         'count'     => (int)    download_count,
 *         'remaining' => (int|null) remaining downloads (null = unlimited),
 *         'expires'   => (string|null) localized expiry date (null = never),
 *         'expires_iso' => (string|null) YYYY-MM-DD for client-side sorting,
 *       ],
 *       ...
 *     ],
 *   ]
 *
 * Matches permissions to line items by the product_id column, which WooCommerce
 * sets to the variation_id for variable products — so this handles both simple
 * and variable downloadables. If two line items reference the same product_id
 * (rare — WooCommerce normally merges them), the same permission list is
 * surfaced against each item, matching the WooCommerce default behavior.
 *
 * @param WC_Order $order
 * @return array<int, array<int, array<string, mixed>>>
 */
function brikpanel_collect_order_item_downloads( $order ) {
    if ( ! $order instanceof WC_Order ) {
        return [];
    }

    try {
        $store   = WC_Data_Store::load( 'customer-download' );
        $records = $store->get_downloads( [ 'order_id' => $order->get_id() ] );
    } catch ( \Throwable $e ) {
        return [];
    }
    if ( empty( $records ) ) {
        return [];
    }

    // Group permission records by product_id so multiple files for the same
    // variation land together against the same line item.
    $by_product = [];
    $product_cache = [];
    $date_format   = get_option( 'date_format' );

    foreach ( $records as $record ) {
        if ( ! $record instanceof WC_Customer_Download ) {
            continue;
        }
        $pid = (int) $record->get_product_id();
        if ( ! $pid ) {
            continue;
        }

        if ( ! array_key_exists( $pid, $product_cache ) ) {
            $product_cache[ $pid ] = wc_get_product( $pid );
        }
        $product = $product_cache[ $pid ];

        $file_name = '';
        if ( $product ) {
            $file = $product->get_file( $record->get_download_id() );
            if ( $file ) {
                $file_name = $file->get_name();
            }
        }

        $remaining_raw = $record->get_downloads_remaining();
        $remaining     = ( $remaining_raw === '' || $remaining_raw === null ) ? null : (int) $remaining_raw;

        $expires_obj = $record->get_access_expires();
        $expires     = $expires_obj ? $expires_obj->date_i18n( $date_format ) : null;
        $expires_iso = $expires_obj ? $expires_obj->date( 'Y-m-d' ) : null;

        $by_product[ $pid ][] = [
            'name'        => $file_name,
            'count'       => (int) $record->get_download_count(),
            'remaining'   => $remaining,
            'expires'     => $expires,
            'expires_iso' => $expires_iso,
        ];
    }

    if ( empty( $by_product ) ) {
        return [];
    }

    $out = [];
    foreach ( $order->get_items() as $item_id => $item ) {
        if ( ! $item instanceof WC_Order_Item_Product ) {
            continue;
        }
        $target_pid = (int) ( $item->get_variation_id() ?: $item->get_product_id() );
        if ( $target_pid && ! empty( $by_product[ $target_pid ] ) ) {
            $out[ (int) $item_id ] = $by_product[ $target_pid ];
        }
    }

    return $out;
}

/**
 * Detect whether any WooCommerce-extending plugin is active that introduces
 * new product types beyond the core simple/variable/grouped/external set.
 *
 * Known signals:
 *  - Any class/constant shipped by the major subscription/booking plugins.
 *  - A non-core key in `wc_get_product_types()` — catches any plugin that
 *    registers its own type via the `product_type_selector` filter without
 *    needing us to hardcode its class names.
 *
 * Used to pick the *default* for `brikpanel_enable_product_types`. Admins
 * can override the result from the settings screen.
 *
 * @return bool
 */
function brikpanel_has_custom_product_types() {
    static $cached = null;
    if ( null !== $cached ) {
        return $cached;
    }

    $known_classes = [
        'WC_Subscriptions',                 // WooCommerce Subscriptions (Automattic)
        'WC_Subscriptions_Plugin',
        'WCS_ATT',                          // All Products for Subscriptions
        'Wps_Subscription',                 // Subscriptions for WooCommerce (WP Swings)
        'Wps_Sfw_Woocommerce',
        'YITH_WC_Subscription',             // YITH WooCommerce Subscription
        'YITH_YWSBS_Subscription',
        'SUMOSubscriptions',                // SUMO Subscriptions
        'RNSSubscription',
        'WT_WC_Subscriptions',              // WebToffee Subscriptions for WooCommerce
        'WT_Subscription',
        'WC_Bookings',                      // WooCommerce Bookings (adds 'booking' type)
        'WC_Product_Bundle',                // WooCommerce Product Bundles
        'WC_Memberships',                   // WC Memberships doesn't add product types,
                                            // but admins with it installed still want the
                                            // extra product-data sections surfaced here.
    ];
    foreach ( $known_classes as $cls ) {
        if ( class_exists( $cls ) ) {
            return $cached = true;
        }
    }

    $known_constants = [
        'WCS_INIT_TIMESTAMP',               // WC Subscriptions
        'WPS_SFW_VERSION',                  // WP Swings
        'YITH_YWSBS_VERSION',
        'YITH_YWSBS_INIT',
        'RNSSUBSCRIPTION_VERSION',          // SUMO
        'WT_WCSBS_VERSION',                 // WebToffee Subscriptions
    ];
    foreach ( $known_constants as $const ) {
        if ( defined( $const ) ) {
            return $cached = true;
        }
    }

    if ( function_exists( 'wc_get_product_types' ) ) {
        $core = [ 'simple', 'variable', 'grouped', 'external' ];
        $all  = array_keys( (array) wc_get_product_types() );
        foreach ( $all as $type ) {
            if ( ! in_array( $type, $core, true ) ) {
                return $cached = true;
            }
        }
    }

    return $cached = false;
}

/**
 * Resolve the effective value of the "Enable product type selector" setting.
 *
 * Option default auto-switches based on whether any plugin has registered
 * custom product types. Admins can flip it on/off explicitly — once saved,
 * their explicit value wins over the auto-default.
 *
 * @return bool
 */
function brikpanel_product_type_selector_enabled() {
    $default = brikpanel_has_custom_product_types() ? 'yes' : 'no';
    return get_option( 'brikpanel_enable_product_types', $default ) === 'yes';
}

/**
 * Return the product-type choices that should appear in the BrikPanel
 * editor dropdown. Starts from `wc_get_product_types()` (so any plugin
 * registering a type is included automatically) and strips 'grouped' /
 * 'external' — the BrikPanel simplified editor does not yet provide
 * first-class UI for those two core types.
 *
 * @return array<string, string> type_slug => label
 */
function brikpanel_editor_product_types() {
    if ( ! function_exists( 'wc_get_product_types' ) ) {
        return [
            'simple'   => __( 'Simple product', 'brikpanel' ),
            'variable' => __( 'Variable product', 'brikpanel' ),
        ];
    }

    $types = (array) wc_get_product_types();
    unset( $types['grouped'], $types['external'] );

    if ( empty( $types['simple'] ) ) {
        $types = array_merge( [ 'simple' => __( 'Simple product', 'brikpanel' ) ], $types );
    }
    if ( empty( $types['variable'] ) ) {
        $types['variable'] = __( 'Variable product', 'brikpanel' );
    }

    return $types;
}

/**
 * Whether the given product-type slug should be treated as a variation-
 * capable parent (UI shows the variations card, save path persists
 * variations, etc.). Covers core `variable` plus any `variable-*` type
 * registered by subscription/booking/bundle plugins.
 *
 * @param string $type
 * @return bool
 */
function brikpanel_is_variable_product_type( $type ) {
    $type = (string) $type;
    if ( $type === '' ) {
        return false;
    }
    if ( $type === 'variable' ) {
        return true;
    }
    return strpos( $type, 'variable-' ) === 0 || strpos( $type, 'variable_' ) === 0;
}

/**
 * Option keys for the customer-analytics exclusion list. A store owner who
 * also rings up in-person sales through one or two staff/POS accounts can
 * exclude those accounts so their hundreds of orders don't distort
 * per-customer analytics (LTV averages, RFM segments, cohort retention,
 * the Segments "Customers" tab). Shop-wide revenue and order-count totals
 * are NOT affected — those still read every order.
 */
const BRIKPANEL_EXCLUDED_USERS_OPTION = 'brikpanel_excluded_user_ids';
const BRIKPANEL_EXCLUDED_ROLES_OPTION = 'brikpanel_excluded_roles';

/**
 * Translated display label for a role slug (e.g. 'shop_manager' →
 * "Shop manager"). Falls back to a humanised slug if the role is unknown.
 *
 * @param string $slug
 * @return string
 */
function brikpanel_role_display_name( $slug ) {
    $slug  = (string) $slug;
    $roles = wp_roles()->roles;
    if ( isset( $roles[ $slug ]['name'] ) ) {
        return translate_user_role( $roles[ $slug ]['name'] );
    }
    return ucwords( str_replace( [ '_', '-' ], ' ', $slug ) );
}

/**
 * Role slugs explicitly chosen for exclusion from customer-level analytics.
 *
 * @return string[]
 */
function brikpanel_excluded_roles() {
    $roles = get_option( BRIKPANEL_EXCLUDED_ROLES_OPTION, [] );
    if ( ! is_array( $roles ) ) {
        return [];
    }
    return array_values( array_unique( array_filter( array_map( 'sanitize_key', $roles ) ) ) );
}

/**
 * User IDs explicitly chosen for exclusion (independent of any role).
 *
 * @return int[]
 */
function brikpanel_excluded_user_ids_raw() {
    $ids = get_option( BRIKPANEL_EXCLUDED_USERS_OPTION, [] );
    if ( ! is_array( $ids ) ) {
        return [];
    }
    return array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
}

/**
 * Resolved set of user IDs to exclude from customer-level analytics:
 * the explicitly chosen IDs plus every user holding an excluded role.
 *
 * Memoised per request. Role resolution is the only potentially expensive
 * part, so it's skipped entirely when no roles are selected.
 *
 * @return int[] Sorted, unique, positive user IDs.
 */
function brikpanel_excluded_customer_ids() {
    static $resolved = null;
    if ( $resolved !== null ) {
        return $resolved;
    }

    $ids   = brikpanel_excluded_user_ids_raw();
    $roles = brikpanel_excluded_roles();

    if ( ! empty( $roles ) ) {
        $role_user_ids = get_users( [
            'role__in' => $roles,
            'fields'   => 'ID',
            'number'   => -1,
        ] );
        $ids = array_merge( $ids, array_map( 'absint', (array) $role_user_ids ) );
    }

    $ids = array_values( array_unique( array_filter( $ids ) ) );
    sort( $ids, SORT_NUMERIC );

    /**
     * Filter the final list of user IDs excluded from customer-level analytics.
     *
     * @param int[] $ids Resolved excluded user IDs.
     */
    $resolved = array_map( 'absint', (array) apply_filters( 'brikpanel_excluded_customer_ids', $ids ) );
    return $resolved;
}

/**
 * Build a SQL fragment that removes excluded customers from an order-level
 * query. Returns '' when nothing is excluded, so call sites can append it
 * unconditionally.
 *
 * The list is a vetted set of integers (absint'd), so direct interpolation
 * is safe and avoids dragging an ever-growing placeholder list through
 * every $wpdb->prepare() call site.
 *
 * @param string $customer_id_expr SQL expression evaluating to the order's
 *                                  customer user ID (0 for guests), e.g.
 *                                  'o.customer_id' on HPOS.
 * @return string Leading-space ' AND (<expr>) NOT IN (1,2,3)' or ''.
 */
function brikpanel_excluded_customer_sql( $customer_id_expr ) {
    $ids = brikpanel_excluded_customer_ids();
    if ( empty( $ids ) ) {
        return '';
    }
    $list = implode( ',', array_map( 'absint', $ids ) );
    return " AND ( {$customer_id_expr} ) NOT IN ({$list})";
}
