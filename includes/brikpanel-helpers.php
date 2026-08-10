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
    if ( '_cogs_value_is_additive' === $meta_key || in_array( $meta_key, brikpanel_cogs_meta_keys(), true ) ) {
        brikpanel_bust_data_caches();
    }
}
add_action( 'added_post_meta',   'brikpanel_bust_data_caches_on_cogs_meta', 10, 3 );
add_action( 'updated_post_meta', 'brikpanel_bust_data_caches_on_cogs_meta', 10, 3 );
add_action( 'deleted_post_meta', 'brikpanel_bust_data_caches_on_cogs_meta', 10, 3 );

/**
 * Third-party cost-of-goods plugins BrikPanel knows how to read from.
 *
 * Plenty of stores filled in their product costs long before they installed
 * BrikPanel, using a dedicated cost plugin that keeps the value in its own
 * meta key. Without this registry every one of those costs is invisible:
 * the product-list Cost column stays blank and the dashboard reports the
 * whole catalogue as zero-cost, which silently overstates Net profit.
 *
 * Detection is by class/function signature rather than the plugin file path
 * so renamed folders and white-labelled builds still resolve, and it stays a
 * pure in-memory check (no option read, no filesystem hit) because the key
 * list is consulted on hot paths.
 *
 * Each entry: `key` (meta key holding the unit cost), `post_key` (the name of
 * the input that plugin injects into WooCommerce's Product data panel, or ''
 * when it has none), `label` (human name, kept untranslated on purpose — it is
 * a product name) and `active`.
 *
 * `post_key` exists because BrikPanel's editor renders WooCommerce's Product
 * data panels inline, which drags the cost plugin's OWN cost input into the
 * form alongside BrikPanel's. That input is never hydrated from what the
 * merchant types in BrikPanel's Cost field, so on save the plugin persists its
 * stale value (usually 0) over the real one. Knowing the input name lets the
 * save handler hand the plugin the correct number instead of fighting it.
 *
 * @return array<string,array{key:string,post_key:string,label:string,active:bool}>
 */
function brikpanel_cogs_third_party_sources() {
    $sources = array(
        // Cost of Goods for WooCommerce (WPFactory, formerly Algoride).
        // Class renamed WPFCOGS in 4.x; the Alg_* names cover 2.x/3.x.
        'wpfactory' => array(
            'key'                => '_alg_wc_cog_cost',
            'post_key'           => '_alg_wc_cog_cost',
            'variation_post_key' => 'variable_wpfcogs_cost',
            'label'              => 'Cost of Goods for WooCommerce',
            'active'             => class_exists( 'WPFCOGS' )
                || function_exists( 'wpfcogs' )
                || class_exists( 'Alg_WC_Cost_of_Goods' )
                || function_exists( 'alg_wc_cost_of_goods' ),
        ),
        // WooCommerce Cost of Goods (SkyVerge / woocommerce.com extension).
        // No variation input name here: it is unverified, and guessing wrong
        // would write a cost into an unrelated field. The post-save re-assert
        // covers the variation case for it.
        'skyverge' => array(
            'key'                => '_wc_cog_cost',
            'post_key'           => '_wc_cog_cost',
            'variation_post_key' => '',
            'label'              => 'WooCommerce Cost of Goods',
            'active'             => class_exists( 'WC_COG' ) || function_exists( 'wc_cost_of_goods' ),
        ),
    );

    /**
     * Filter the registry of third-party cost-of-goods plugins.
     *
     * Use this to teach BrikPanel about a cost plugin it does not ship
     * support for; set `active` to true to switch the entry on.
     *
     * @param array<string,array{key:string,label:string,active:bool}> $sources
     */
    return (array) apply_filters( 'brikpanel_cogs_third_party_sources', $sources );
}

/**
 * The two cost meta keys BrikPanel OWNS — the ones it writes on every save
 * and keeps mirrored to each other.
 *
 * Deliberately excludes third-party keys. Those are read-only inputs: a cost
 * plugin's own screen saves through a WC_Product object, and WooCommerce
 * rewrites `_cogs_total_value` from that object's (stale) cogs_value prop at
 * the very end of save(). Mirroring into the native key mid-save therefore
 * gets clobbered and, worse, the clobbered value propagates straight back out
 * — reverting the cost the merchant just typed into their own plugin. Reading
 * their key instead of writing near it keeps BrikPanel out of that fight.
 *
 * @return string[]
 */
function brikpanel_cogs_owned_meta_keys() {
    return array( '_cogs_total_value', '_brikpanel_cogs' );
}

/**
 * Every meta key BrikPanel treats as a product's unit cost, in priority
 * order (first non-empty wins).
 *
 * A detected third-party cost plugin leads, because that plugin's own field
 * is the screen the merchant actually types costs into — it is their source
 * of truth, and deferring to it is what makes an existing costed catalogue
 * show up in BrikPanel at all. WooCommerce's native `_cogs_total_value` and
 * BrikPanel's legacy `_brikpanel_cogs` follow. In practice the ordering
 * rarely bites: BrikPanel's own save writes the whole set (see
 * brikpanel_set_product_cogs_raw()), so an edit made here lands in the cost
 * plugin's field too and the keys stay equal in both directions.
 *
 * A store whose cost lives somewhere else entirely can point every BrikPanel
 * surface — per-product AND the dashboard aggregates — at it in one line:
 *
 *     add_filter( 'brikpanel_cogs_meta_keys', function ( $keys ) {
 *         array_unshift( $keys, '_my_cost_meta' );
 *         return $keys;
 *     } );
 *
 * @return string[] Ordered, de-duplicated meta keys (never empty).
 */
function brikpanel_cogs_meta_keys() {
    static $cache = null;
    static $cached_late = false;

    // The list is read per product row, so it is memoised — but only once the
    // stack is fully up. BrikPanel loads alphabetically before most cost
    // plugins, and integrators register their filter on plugins_loaded/init,
    // so caching an early call would freeze a detection result taken before
    // anyone had a chance to declare themselves.
    $late = did_action( 'init' ) > 0;
    if ( null !== $cache && $cached_late && $late ) {
        return $cache;
    }

    $keys = array();

    foreach ( brikpanel_cogs_third_party_sources() as $source ) {
        if ( ! empty( $source['active'] ) && ! empty( $source['key'] ) ) {
            $keys[] = (string) $source['key'];
        }
    }

    $keys = array_merge( $keys, brikpanel_cogs_owned_meta_keys() );

    /**
     * Filter the ordered list of meta keys BrikPanel reads product cost from.
     *
     * @param string[] $keys Meta keys, highest priority first.
     */
    $keys = (array) apply_filters( 'brikpanel_cogs_meta_keys', $keys );

    // Harden: these strings are interpolated into SQL by the join builder, so
    // anything that is not a well-formed meta key is dropped outright rather
    // than escaped — a filter cannot smuggle SQL in through this list. If a
    // filter leaves nothing usable we fall back to BrikPanel's own keys, so
    // the accessors and the aggregates always have something to read.
    $clean = array();
    foreach ( $keys as $key ) {
        $key = is_string( $key ) ? trim( $key ) : '';
        if ( '' !== $key && preg_match( '/^[A-Za-z0-9_\-]{1,255}$/', $key ) ) {
            $clean[] = $key;
        }
    }
    $clean = array_values( array_unique( $clean ) );
    if ( empty( $clean ) ) {
        $clean = brikpanel_cogs_owned_meta_keys();
    }

    $cache       = $clean;
    $cached_late = $late;

    return $cache;
}

/**
 * SQL fragments that resolve a product's cost across every known cost meta
 * key, for the aggregate queries that cannot afford a per-row PHP lookup.
 *
 * Returns one LEFT JOIN per key plus a COALESCE expression picking the first
 * non-empty value in the same priority order brikpanel_product_cogs_raw()
 * uses, so the dashboard totals and the per-product surfaces can never
 * disagree. Stores without a third-party cost plugin get exactly the two
 * joins they always had — the extra cost is opt-in by installation.
 *
 * @param string $alias_prefix Short unique alias stem (e.g. 'vc', 'pc').
 * @param string $post_id_expr SQL expression for the post id to join on.
 * @param string $extra_on     Optional extra ON condition (already safe SQL).
 * @return array{joins:string,value:string}
 */
function brikpanel_cogs_sql_join_set( $alias_prefix, $post_id_expr, $extra_on = '' ) {
    global $wpdb;

    $joins  = '';
    $values = array();
    $i      = 0;

    foreach ( brikpanel_cogs_meta_keys() as $key ) {
        $alias   = $alias_prefix . $i;
        $joins  .= "\n\t\tLEFT JOIN {$wpdb->postmeta} {$alias}"
            . "\n\t\t\tON {$alias}.post_id = {$post_id_expr}"
            . "\n\t\t   AND {$alias}.meta_key = '" . esc_sql( $key ) . "'"
            . ( '' !== $extra_on ? "\n\t\t   AND {$extra_on}" : '' );
        $values[] = "NULLIF({$alias}.meta_value, '')";
        $i++;
    }

    return array(
        'joins' => $joins,
        'value' => 'COALESCE(' . implode( ', ', $values ) . ')',
    );
}

/**
 * Keep the cost meta keys BrikPanel owns in lockstep on products and
 * variations.
 *
 * BrikPanel's own `_brikpanel_cogs` and WooCommerce's native
 * `_cogs_total_value` (WC 9.5+) describe the same number but are written by
 * different screens — BrikPanel's editor and Quick Edit on one side, the
 * WooCommerce product screen on the other. Whichever the merchant uses, this
 * copies the value across so the cost shows up everywhere at once and the
 * dashboard never reports a costed catalogue as zero-cost. It also covers the
 * case where WC's Cost of Goods feature flag is off, which makes
 * WC_Product::set_cogs_value() a silent no-op.
 *
 * Scope is deliberately brikpanel_cogs_owned_meta_keys(), NOT every key
 * BrikPanel can read — see that function for why writing near a third-party
 * cost plugin's key is harmful.
 *
 * Semantics match on both sides: an empty value / deleted row means "no cost
 * on file" (a variation then inherits its parent), so a clear propagates as a
 * delete rather than a stored empty string.
 *
 * Only products and variations are mirrored: WooCommerce also stores
 * `_cogs_total_value` on ORDERS, which must never bleed into product cost meta.
 *
 * Loop safety comes from a re-entrancy guard plus a real-change test on every
 * write, so the ring settles in a single pass instead of bouncing.
 *
 * @param int    $meta_id    Unused (an array of ids on the delete hook).
 * @param int    $object_id  Product or variation ID whose meta changed.
 * @param string $meta_key   Meta key that changed.
 * @param mixed  $meta_value New value ('' on delete / clear).
 */
function brikpanel_mirror_cogs_meta( $meta_id, $object_id, $meta_key, $meta_value = '' ) {
    static $mirroring = false;

    if ( $mirroring ) {
        return;
    }

    $keys = brikpanel_cogs_owned_meta_keys();
    if ( ! in_array( (string) $meta_key, $keys, true ) ) {
        return;
    }
    if ( ! in_array( get_post_type( $object_id ), array( 'product', 'product_variation' ), true ) ) {
        return;
    }

    $source    = (string) $meta_value;
    $formatted = '' === $source ? '' : wc_format_decimal( $source );

    $mirroring = true;
    try {
        foreach ( $keys as $key ) {
            if ( $key === $meta_key ) {
                continue;
            }
            $current = (string) get_post_meta( $object_id, $key, true );

            // Cleared on the source side (variation now inherits its parent,
            // or the merchant emptied the field) -> drop the mirrored copies
            // so the parent fallback kicks back in instead of a stale cost.
            if ( '' === $formatted ) {
                if ( '' !== $current ) {
                    delete_post_meta( $object_id, $key );
                }
                continue;
            }

            // Compare as floats so 12.5 and 12.50 do not ping-pong, and only
            // write on a real change (which also skips a cache bust for free).
            if ( '' === $current || (float) $current !== (float) $formatted ) {
                update_post_meta( $object_id, $key, $formatted );
            }
        }
    } finally {
        $mirroring = false;
    }
}
add_action( 'added_post_meta',   'brikpanel_mirror_cogs_meta', 10, 4 );
add_action( 'updated_post_meta', 'brikpanel_mirror_cogs_meta', 10, 4 );
add_action( 'deleted_post_meta', 'brikpanel_mirror_cogs_meta', 10, 4 );

/**
 * The cost defined directly on ONE product or variation post — no parent
 * fallback, no additive math. Walks brikpanel_cogs_meta_keys() in priority
 * order (WooCommerce native, then BrikPanel's legacy key, then any detected
 * third-party cost plugin) and returns the first value on file. Reading raw
 * meta instead of WC_Product::get_cogs_value() keeps this working even when
 * the merchant has switched WooCommerce's Cost of Goods Sold feature off —
 * the data stays in the database either way.
 *
 * @param int $post_id Product or variation ID.
 * @return string Decimal string, or '' when no cost is on file.
 */
function brikpanel_product_cogs_raw( $post_id ) {
    $post_id = (int) $post_id;

    foreach ( brikpanel_cogs_meta_keys() as $key ) {
        $value = (string) get_post_meta( $post_id, $key, true );
        if ( '' !== $value ) {
            return $value;
        }
    }

    return '';
}

/**
 * Write (or clear) the cost on ONE product or variation post across every
 * known cost meta key.
 *
 * The live mirror already propagates a single write to the other keys, but it
 * can only react to a write that actually happened: clearing a cost that
 * exists ONLY in a third-party plugin's key would delete nothing on
 * BrikPanel's own key, fire no hook, and leave the old cost in place — the
 * field would helpfully repopulate itself on the next page load. Writing the
 * whole set explicitly makes "cleared" mean cleared everywhere.
 *
 * @param int         $post_id Product or variation ID.
 * @param string|null $value   Raw user input; '' or null clears the cost.
 * @return string Normalised decimal actually stored ('' when cleared).
 */
function brikpanel_set_product_cogs_raw( $post_id, $value ) {
    $post_id = (int) $post_id;
    $decimal = ( null === $value || '' === $value ) ? '' : wc_format_decimal( $value );

    foreach ( brikpanel_cogs_meta_keys() as $key ) {
        if ( '' === $decimal ) {
            delete_post_meta( $post_id, $key );
            continue;
        }
        $current = (string) get_post_meta( $post_id, $key, true );
        if ( '' === $current || (float) $current !== (float) $decimal ) {
            update_post_meta( $post_id, $key, $decimal );
        }
    }

    return $decimal;
}

/**
 * Hand any cost plugin whose input is riding along in THIS submission the
 * value the merchant actually typed into BrikPanel's Cost field.
 *
 * BrikPanel's editor renders WooCommerce's Product data panels inline for
 * compatibility, so a cost plugin's own cost input is submitted with the form
 * even though the merchant never sees or edits it — it still holds whatever it
 * rendered with (0 on a product that had no cost in that plugin yet). The
 * plugin's save handler then writes that stale number over the real one, and
 * because its key is read first the cost the merchant just typed vanishes on
 * the next page load.
 *
 * Correcting `$_POST` rather than racing the write means the plugin persists
 * the right number through its OWN pipeline, so its derived fields (profit,
 * margin) come out right too. Only inputs actually present in the submission
 * are touched: injecting a key that was never posted would make a plugin save
 * a cost the merchant did not ask it to.
 *
 * Variations work the same way, one level deeper: the plugin reads
 * `$_POST['<field>'][$loop]`, so pass the loop index the editor is about to
 * hand `woocommerce_save_product_variation`.
 *
 * @param string   $decimal    Normalised cost ('' when the merchant cleared it).
 * @param int|null $loop_index Variation loop index, or null for the parent/simple product.
 */
function brikpanel_cogs_sync_posted_third_party_inputs( $decimal, $loop_index = null ) {
    foreach ( brikpanel_cogs_third_party_sources() as $source ) {
        if ( empty( $source['active'] ) ) {
            continue;
        }

        if ( null === $loop_index ) {
            $key = ! empty( $source['post_key'] ) ? $source['post_key'] : '';
            if ( '' === $key || ! isset( $_POST[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
                continue;
            }
            $_POST[ $key ] = $decimal; // phpcs:ignore WordPress.Security.NonceVerification
            continue;
        }

        $key = ! empty( $source['variation_post_key'] ) ? $source['variation_post_key'] : '';
        if ( '' === $key || ! isset( $_POST[ $key ][ $loop_index ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
            continue;
        }
        $_POST[ $key ][ $loop_index ] = $decimal; // phpcs:ignore WordPress.Security.NonceVerification
    }
}

/**
 * The EFFECTIVE unit cost of a product line, resolved the same way the
 * profit engine's SQL does: variation cost first, parent product cost as
 * fallback, and WooCommerce's additive-variation flag honoured (variation
 * cost added on top of the parent's when `_cogs_value_is_additive` is yes).
 *
 * This is the single integration point for cost: the result runs through the
 * `brikpanel_product_cogs` filter, so a store that computes cost rather than
 * storing it can point every BrikPanel per-product read at its own logic from
 * one hook. (The dashboard aggregates resolve cost in SQL for performance, so
 * this filter only reaches per-product surfaces — to move EVERY surface,
 * including the aggregates, add the meta key to `brikpanel_cogs_meta_keys`
 * instead.)
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
