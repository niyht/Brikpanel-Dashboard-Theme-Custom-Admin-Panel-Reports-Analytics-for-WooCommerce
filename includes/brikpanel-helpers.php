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
 * Meta keys the BrikPanel product search scans alongside the post title and
 * content.
 *
 * WooCommerce keeps the SKU in `_sku`, which is all BrikPanel looks at out of
 * the box. Stores that also stamp a supplier code, a manufacturer part number
 * or an EAN onto their products can let warehouse staff find a product by any
 * of them in one line:
 *
 *     add_filter( 'brikpanel_product_search_meta_keys', function ( $keys, $term ) {
 *         $keys[] = '_manufacturer_sku';
 *         return $keys;
 *     }, 10, 2 );
 *
 * The same list drives BOTH halves of the search clause: a product's own meta
 * and its variations' meta, where a hit returns the parent product. Simple and
 * variable products therefore behave identically.
 *
 * Not memoised on purpose: this runs once per search query, so a cache would
 * save nothing, and the $term argument means a site may legitimately return a
 * different list per term. Do not add a static cache here.
 *
 * @param string $term The raw search term being run, so a site can widen the
 *                     scan only for terms that look like its own identifiers.
 * @return string[] Ordered, de-duplicated meta keys (never empty, at most 10).
 */
function brikpanel_product_search_meta_keys( $term = '' ) {
    $keys = array( '_sku' );

    /**
     * Filter the meta keys the BrikPanel product search scans.
     *
     * @param string[] $keys Meta keys to scan. Defaults to array( '_sku' ).
     * @param string   $term The raw search term being run.
     */
    $keys = (array) apply_filters( 'brikpanel_product_search_meta_keys', $keys, (string) $term );

    // Harden: these strings reach a meta_key position in the search SQL, so
    // anything that is not a well-formed meta key is dropped outright rather
    // than escaped. A filter cannot smuggle SQL in through this list. If a
    // filter leaves nothing usable we fall back to WooCommerce's own SKU key,
    // so the caller always has something to build a clause from and `IN ()`
    // can never be emitted.
    $clean = array();
    foreach ( $keys as $key ) {
        $key = is_string( $key ) ? trim( $key ) : '';
        if ( '' !== $key && preg_match( '/^[A-Za-z0-9_\-]{1,255}$/', $key ) ) {
            $clean[] = $key;
        }
    }
    $clean = array_values( array_unique( $clean ) );
    if ( empty( $clean ) ) {
        $clean = array( '_sku' );
    }

    // Hard cap. Every extra key adds its whole meta_key range to a
    // `LIKE '%term%'` scan that no index can help, and it does so twice (own
    // meta plus variation meta). Ten covers SKU, supplier code, MPN, EAN and a
    // handful of legacy fields; beyond that a single filter could turn every
    // keystroke in the search box into a full postmeta scan. Deliberately not
    // filterable: a store that needs more identifiers should use the $term
    // argument to widen the scan only for terms that warrant it.
    if ( count( $clean ) > 10 ) {
        $clean = array_slice( $clean, 0, 10 );
    }

    return $clean;
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

// =============================================================================
// PHONE NUMBERS — one normaliser for every WhatsApp link in the plugin
// =============================================================================
//
// Both the Abandoned Carts screen and the Orders screen turn a customer's phone
// into a wa.me link, and until 3.2.63 each did it with its own copy of the
// logic, its own country-code source and its own length rules. They disagreed
// often enough to matter: the same number could open two different chats.
//
// This lives in helpers.php because it is needed on the front end too (the
// Orders module is only loaded inside wp-admin), and helpers.php is required at
// file scope, outside that gate.

/**
 * ISO 3166-1 alpha-2 country code → international dialing code (digits only,
 * no leading "+"). Used to complete a local phone number into the E.164-style
 * form WhatsApp needs. Filterable so a store can correct or extend it.
 *
 * @param string $cc Two-letter uppercase country code.
 * @return string Dialing code digits, or '' when unknown.
 */
function brikpanel_country_dialing_code( $cc ) {
	$cc  = strtoupper( (string) $cc );
	$map = array(
		'AF' => '93', 'AL' => '355', 'DZ' => '213', 'AS' => '1', 'AD' => '376', 'AO' => '244', 'AI' => '1', 'AG' => '1',
		'AR' => '54', 'AM' => '374', 'AW' => '297', 'AU' => '61', 'AT' => '43', 'AZ' => '994', 'BS' => '1', 'BH' => '973',
		'BD' => '880', 'BB' => '1', 'BY' => '375', 'BE' => '32', 'BZ' => '501', 'BJ' => '229', 'BM' => '1', 'BT' => '975',
		'BO' => '591', 'BA' => '387', 'BW' => '267', 'BR' => '55', 'BN' => '673', 'BG' => '359', 'BF' => '226', 'BI' => '257',
		'KH' => '855', 'CM' => '237', 'CA' => '1', 'CV' => '238', 'KY' => '1', 'CF' => '236', 'TD' => '235', 'CL' => '56',
		'CN' => '86', 'CO' => '57', 'KM' => '269', 'CG' => '242', 'CD' => '243', 'CR' => '506', 'CI' => '225', 'HR' => '385',
		'CU' => '53', 'CY' => '357', 'CZ' => '420', 'DK' => '45', 'DJ' => '253', 'DM' => '1', 'DO' => '1', 'EC' => '593',
		'EG' => '20', 'SV' => '503', 'GQ' => '240', 'ER' => '291', 'EE' => '372', 'SZ' => '268', 'ET' => '251', 'FJ' => '679',
		'FI' => '358', 'FR' => '33', 'GF' => '594', 'PF' => '689', 'GA' => '241', 'GM' => '220', 'GE' => '995', 'DE' => '49',
		'GH' => '233', 'GI' => '350', 'GR' => '30', 'GL' => '299', 'GD' => '1', 'GP' => '590', 'GU' => '1', 'GT' => '502',
		'GG' => '44', 'GN' => '224', 'GW' => '245', 'GY' => '592', 'HT' => '509', 'HN' => '504', 'HK' => '852', 'HU' => '36',
		'IS' => '354', 'IN' => '91', 'ID' => '62', 'IR' => '98', 'IQ' => '964', 'IE' => '353', 'IM' => '44', 'IL' => '972',
		'IT' => '39', 'JM' => '1', 'JP' => '81', 'JE' => '44', 'JO' => '962', 'KZ' => '7', 'KE' => '254', 'KI' => '686',
		'KW' => '965', 'KG' => '996', 'LA' => '856', 'LV' => '371', 'LB' => '961', 'LS' => '266', 'LR' => '231', 'LY' => '218',
		'LI' => '423', 'LT' => '370', 'LU' => '352', 'MO' => '853', 'MG' => '261', 'MW' => '265', 'MY' => '60', 'MV' => '960',
		'ML' => '223', 'MT' => '356', 'MH' => '692', 'MQ' => '596', 'MR' => '222', 'MU' => '230', 'MX' => '52', 'FM' => '691',
		'MD' => '373', 'MC' => '377', 'MN' => '976', 'ME' => '382', 'MS' => '1', 'MA' => '212', 'MZ' => '258', 'MM' => '95',
		'NA' => '264', 'NR' => '674', 'NP' => '977', 'NL' => '31', 'NC' => '687', 'NZ' => '64', 'NI' => '505', 'NE' => '227',
		'NG' => '234', 'MK' => '389', 'NO' => '47', 'OM' => '968', 'PK' => '92', 'PW' => '680', 'PS' => '970', 'PA' => '507',
		'PG' => '675', 'PY' => '595', 'PE' => '51', 'PH' => '63', 'PL' => '48', 'PT' => '351', 'PR' => '1', 'QA' => '974',
		'RE' => '262', 'RO' => '40', 'RU' => '7', 'RW' => '250', 'KN' => '1', 'LC' => '1', 'VC' => '1', 'WS' => '685',
		'SM' => '378', 'ST' => '239', 'SA' => '966', 'SN' => '221', 'RS' => '381', 'SC' => '248', 'SL' => '232', 'SG' => '65',
		'SK' => '421', 'SI' => '386', 'SB' => '677', 'SO' => '252', 'ZA' => '27', 'KR' => '82', 'SS' => '211', 'ES' => '34',
		'LK' => '94', 'SD' => '249', 'SR' => '597', 'SE' => '46', 'CH' => '41', 'SY' => '963', 'TW' => '886', 'TJ' => '992',
		'TZ' => '255', 'TH' => '66', 'TL' => '670', 'TG' => '228', 'TO' => '676', 'TT' => '1', 'TN' => '216', 'TR' => '90',
		'TM' => '993', 'TC' => '1', 'TV' => '688', 'UG' => '256', 'UA' => '380', 'AE' => '971', 'GB' => '44', 'US' => '1',
		'UY' => '598', 'UZ' => '998', 'VU' => '678', 'VA' => '39', 'VE' => '58', 'VN' => '84', 'VG' => '1', 'VI' => '1',
		'YE' => '967', 'ZM' => '260', 'ZW' => '263',
	);
	$code = isset( $map[ $cc ] ) ? $map[ $cc ] : '';

	/**
	 * Filter the dialing code resolved for a country. Return '' to skip
	 * prepending (the raw digits are then used as-is).
	 */
	return (string) apply_filters( 'brikpanel_country_dialing_code', $code, $cc );
}

/**
 * Every dialing code the map knows, longest first, for prefix detection.
 *
 * Codes are one to three digits, so a plain "does the number start with a code"
 * test has to try the longest match first or "1" would shadow "12" and "123".
 *
 * @return array<int,string> Unique codes, sorted longest-first.
 */
function brikpanel_known_dialing_codes() {
	static $codes = null;
	if ( null !== $codes ) {
		return $codes;
	}

	// Reuse the map itself rather than keeping a second list in sync: ask the
	// resolver for every ISO country WooCommerce knows about.
	$countries = array();
	if ( function_exists( 'WC' ) && WC()->countries && method_exists( WC()->countries, 'get_countries' ) ) {
		$countries = array_keys( (array) WC()->countries->get_countries() );
	}
	if ( ! $countries ) {
		// WooCommerce not ready (cron, early boot). Fall back to the ISO list the
		// map itself is keyed by, obtained by asking for a code we know exists.
		$countries = array( 'US', 'GB', 'DE', 'FR', 'TR', 'IN', 'BR', 'RU', 'CN', 'JP' );
	}

	$out = array();
	foreach ( $countries as $cc ) {
		$code = brikpanel_country_dialing_code( $cc );
		if ( '' !== $code ) {
			$out[ $code ] = true;
		}
	}
	$out = array_keys( $out );
	usort( $out, static function ( $a, $b ) {
		return strlen( $b ) <=> strlen( $a );
	} );

	$codes = $out;
	return $codes;
}

/**
 * The dialing code a number already starts with, or '' when it starts with none.
 *
 * @param string $digits Digits only.
 * @return string
 */
function brikpanel_leading_dialing_code( $digits ) {
	foreach ( brikpanel_known_dialing_codes() as $code ) {
		if ( 0 === strpos( $digits, $code ) ) {
			return $code;
		}
	}
	return '';
}

/**
 * Whether a digit string is a plausible international number.
 *
 * The shortest real E.164 numbers are 8 digits including the country code and
 * the standard caps the whole thing at 15. Outside that it is a typo, an
 * extension or a note, and a wrong wa.me link is worse than none.
 *
 * @param string $digits
 * @return bool
 */
function brikpanel_is_dialable_e164( $digits ) {
	$len = strlen( (string) $digits );
	return $len >= 8 && $len <= 15;
}

/**
 * Turn a customer-typed phone number into the international digits WhatsApp
 * expects (no "+"), or '' when it cannot be dialled.
 *
 * The hard part is not formatting, it is deciding whether a number that does
 * not start with "+" is a local number needing a country code in front, or an
 * international number the shopper typed without the plus. Guessing wrong
 * produces a link to a stranger, so the decision is made from the strongest
 * evidence available:
 *
 *   A. The number already starts with the country's own code. Then it is
 *      already international; prepending again gives "9090532…".
 *   B. The country is only a GUESS (nothing was captured for this shopper, so
 *      we fell back to the store's own country) and the number starts with some
 *      other country's code. Here the number's own prefix is better evidence
 *      than the store's address: a Turkish number in a US-based store is a
 *      customer, not a typo. Deliberately limited to the guessed case — when
 *      the country is known, a leading digit means very little (a German mobile
 *      starts with "1", which is also the NANP code).
 *   C. Prepending would push the number past E.164's 15-digit ceiling, which
 *      is proof on its own that the code is already there.
 *
 * @param string $raw     Number as the customer typed it.
 * @param string $country Two-letter country the number belongs to, '' when unknown.
 * @return string Digits only (no "+"), or ''.
 */
function brikpanel_phone_to_e164( $raw, $country = '' ) {
	$raw = trim( (string) $raw );
	if ( '' === $raw ) {
		return '';
	}

	$digits = preg_replace( '/\\D+/', '', $raw );
	if ( '' === $digits ) {
		return '';
	}

	// --- Explicitly international: the shopper already told us -------------
	// "+" is unambiguous. "00" and the North American "011" are the two dial-out
	// prefixes in use; both mean "what follows is a full international number".
	$number = '';
	if ( 0 === strpos( $raw, '+' ) ) {
		$number = $digits;
	} elseif ( 0 === strpos( $digits, '011' ) && strlen( $digits ) > 11 ) {
		// Length guard: "011…" is only a dial-out prefix when what follows is
		// long enough to be a whole international number. Otherwise it is a
		// local number that happens to start 011 (a UK 0113… landline).
		$number = substr( $digits, 3 );
	} elseif ( 0 === strpos( $digits, '00' ) ) {
		$number = substr( $digits, 2 );
	}

	if ( '' !== $number ) {
		$number = ltrim( $number, '0' );
		return brikpanel_is_dialable_e164( $number )
			? (string) apply_filters( 'brikpanel_phone_to_e164', $number, $raw, $country )
			: '';
	}

	// --- Local form: decide whether a country code has to go in front ------
	$country = strtoupper( trim( (string) $country ) );
	$guessed = ( '' === $country );
	if ( $guessed ) {
		$base    = function_exists( 'wc_get_base_location' ) ? wc_get_base_location() : array();
		$country = strtoupper( (string) ( $base['country'] ?? '' ) );
	}

	// A single national trunk zero is written locally and never dialled from
	// abroad (UK "07…" -> 44 7…). Only one: a number written "00…" already took
	// the international branch above, so a second zero here is part of the number.
	$local = ( strlen( $digits ) > 1 && '0' === $digits[0] ) ? substr( $digits, 1 ) : $digits;

	$code = brikpanel_country_dialing_code( $country );
	if ( '' === $code ) {
		// No country at all — not the customer's, not even the store's. A
		// nationally-written number cannot be completed, and using it as typed
		// would dial whoever owns those digits under some other country's code
		// ("532 111 22 33" reads as +53 2111 22 33, Cuba). Almost any digit
		// string begins with some assigned code, so there is nothing to check
		// against; no link is the only safe answer.
		return '';
	}

	if ( 0 === strpos( $local, $code ) && brikpanel_is_dialable_e164( $local ) ) {
		$number = $local;                                   // rule A
	} elseif ( $guessed && brikpanel_is_dialable_e164( $local ) && '' !== brikpanel_leading_dialing_code( $local ) ) {
		$number = $local;                                   // rule B
	} elseif ( ! brikpanel_is_dialable_e164( $code . $local ) && brikpanel_is_dialable_e164( $local ) ) {
		$number = $local;                                   // rule C
	} else {
		$number = $code . $local;
	}

	if ( ! brikpanel_is_dialable_e164( $number ) ) {
		return '';
	}

	/**
	 * Filter the final international number (digits only, no "+").
	 *
	 * @param string $number  Resolved E.164 digits.
	 * @param string $raw     Number as the customer typed it.
	 * @param string $country Two-letter country used to resolve it.
	 */
	return (string) apply_filters( 'brikpanel_phone_to_e164', $number, $raw, $country );
}

/*
 * ---------------------------------------------------------------------------
 * Plain text and bidirectional isolation
 * ---------------------------------------------------------------------------
 *
 * Prices, dates and phone numbers are written left to right even inside a
 * right-to-left sentence, and the Unicode Bidirectional Algorithm works that
 * out from the characters alone - until a separator gets in the way. In
 * "15.402,50 EGP" the comma is a neutral character, and a renderer that does
 * not resolve it as part of the number treats the amount as two separate runs,
 * which an Arabic paragraph then lays out in the opposite order: the merchant
 * sends 15.402,50 and the shopper reads "50,15.402".
 *
 * WooCommerce knows this, which is why wc_price() wraps its output in <bdi>.
 * Every "price as plain text" path throws that wrapper away with
 * wp_strip_all_tags(), so the isolation has to be put back by hand, as the
 * characters U+2066 (LRI) and U+2069 (PDI) - the plain-text equivalent of the
 * <bdi> element, invisible in a left-to-right context and understood by
 * WhatsApp, e-mail clients and browsers alike.
 */

/**
 * Reduce an HTML fragment to the plain text a message or a table cell shows.
 *
 * Order matters: wc_price() emits `&#36;`/`&nbsp;` and wc_display_item_meta()
 * emits `&times;`, so entities are decoded *before* the tags come off.
 * Stripping first would leave the entity text sitting in the output verbatim.
 *
 * The whitespace collapse deliberately spares the no-break spaces. The `u`
 * modifier turns on PCRE's Unicode properties, where `\S` counts U+00A0 as
 * whitespace, so the obvious pattern quietly rewrites the no-break space
 * wc_price() puts between the symbol and the amount into an ordinary one -
 * and then WhatsApp is free to break the line straight through the price.
 *
 * @param string $html
 * @return string
 */
function brikpanel_plain_text_from_html( $html ) {
	$text = html_entity_decode( (string) $html, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	$text = wp_strip_all_tags( $text );
	$text = preg_replace( '/[^\S\n\x{00A0}\x{202F}]+/u', ' ', $text );

	return trim( (string) $text );
}

/**
 * Strip any bidirectional control character a string arrived with.
 *
 * Runs before this file adds its own isolation, for two reasons. A stray
 * U+2069 in merchant- or filter-supplied text would close our isolate early
 * and hand the rest of the amount back to the surrounding paragraph, and
 * U+202E (right-to-left override) reverses everything after it - the trick
 * used to disguise filenames, and here enough to make a total read as a
 * different number. Stripping first also guarantees isolates never nest, which
 * is what lets brikpanel_bidi_close_isolates() count them exactly.
 *
 * @param string $text
 * @return string
 */
function brikpanel_bidi_strip_controls( $text ) {
	static $controls = null;

	if ( null === $controls ) {
		$controls = array(
			"\u{200E}" => '', // LRM
			"\u{200F}" => '', // RLM
			"\u{202A}" => '', // LRE
			"\u{202B}" => '', // RLE
			"\u{202C}" => '', // PDF
			"\u{202D}" => '', // LRO
			"\u{202E}" => '', // RLO
			"\u{2066}" => '', // LRI
			"\u{2067}" => '', // RLI
			"\u{2068}" => '', // FSI
			"\u{2069}" => '', // PDI
		);
	}

	return strtr( (string) $text, $controls );
}

/**
 * Wrap a whole string in a left-to-right isolate.
 *
 * For values that are left-to-right from end to end: a phone number, a
 * formatted date, an order number. Anything mixing a number with a currency
 * symbol wants brikpanel_bidi_isolate_numbers() instead, which leaves the
 * symbol where the merchant configured it.
 *
 * An empty string is returned untouched. Both message builders decide whether
 * to drop a whole line by comparing a token against '', and two invisible
 * characters would quietly turn every optional line into a permanent one.
 *
 * @param string $text
 * @return string
 */
function brikpanel_bidi_isolate_ltr( $text ) {
	$text = (string) $text;

	if ( '' === trim( $text ) || ( defined( 'BRIKPANEL_NO_BIDI_ISOLATION' ) && BRIKPANEL_NO_BIDI_ISOLATION ) ) {
		return $text;
	}

	return "\u{2066}" . brikpanel_bidi_strip_controls( $text ) . "\u{2069}";
}

/**
 * Wrap every number inside a string in its own left-to-right isolate.
 *
 * Isolating the number rather than the whole price is what keeps the currency
 * where the merchant put it. Wrapping "ر.س 15.402,50" as one unit fixes the
 * digits but moves the symbol to the other side of the amount, because the
 * isolate forces one direction on both; isolating only "15.402,50" leaves the
 * symbol to the paragraph, which places it exactly as woocommerce_currency_pos
 * asked. Verified against every position with a Latin code, a "$" and an
 * Arabic symbol.
 *
 * A number is a run of digits plus the separators that appear *between*
 * digits - the decimal mark, the thousands mark and their Arabic-Indic
 * equivalents, including the no-break and thin spaces used as group
 * separators in several locales. A separator with no digit after it is left
 * outside, so "15.402,50 EGP" isolates the amount and not the trailing code.
 *
 * A leading sign joins the number, so a refund reads "-15,00" and not
 * "15,00-". Only a sign that starts the run counts: the lookbehind keeps the
 * hyphens of "2026-08-17" from being read as minus signs.
 *
 * @param string $text
 * @return string
 */
function brikpanel_bidi_isolate_numbers( $text ) {
	$text = (string) $text;

	if ( '' === trim( $text ) || ( defined( 'BRIKPANEL_NO_BIDI_ISOLATION' ) && BRIKPANEL_NO_BIDI_ISOLATION ) ) {
		return $text;
	}

	$text = brikpanel_bidi_strip_controls( $text );

	$separator = '[.,\'\x{2019}\x{066B}\x{066C}\x{00A0}\x{202F}\x{2009} ]';
	$number    = '/(?<!\p{Nd})[+\-\x{2212}]?\p{Nd}+(?:' . $separator . '\p{Nd}+)*/u';

	$isolated = preg_replace( $number, "\u{2066}$0\u{2069}", $text );

	return null === $isolated ? $text : $isolated;
}

/**
 * A monetary amount as isolated plain text, ready to drop into a message.
 *
 * @param float|int|string $amount Amount to format.
 * @param array            $args   Passed straight to wc_price(), e.g. currency.
 * @return string
 */
function brikpanel_money_text( $amount, $args = array() ) {
	if ( ! function_exists( 'wc_price' ) ) {
		return brikpanel_bidi_isolate_numbers( number_format_i18n( (float) $amount, 2 ) );
	}

	return brikpanel_bidi_isolate_numbers(
		brikpanel_plain_text_from_html( wc_price( (float) $amount, (array) $args ) )
	);
}

/**
 * The same isolation for a price WooCommerce has already formatted.
 *
 * Used where the markup itself carries meaning that reformatting would lose -
 * a refunded order's struck-through original beside the new total, say. The
 * markup is kept, so the per-number pass is skipped: an isolate landing inside
 * `&#36;` or between a tag's angle brackets would corrupt the fragment. The
 * outer isolate alone is enough there, because an e-mail client renders the
 * `<del>`/`<ins>` structure with its own directionality anyway.
 *
 * @param string $html Price HTML, e.g. from get_formatted_order_total().
 * @return string
 */
function brikpanel_money_text_from_html( $html ) {
	$html = (string) $html;

	if ( false !== strpbrk( $html, '<&' ) ) {
		return brikpanel_bidi_isolate_ltr( $html );
	}

	return brikpanel_bidi_isolate_numbers( $html );
}

/**
 * Close any isolate a truncation left open.
 *
 * A message cut to a maximum length can land between an LRI and its PDI, and
 * an unclosed isolate swallows everything after it into the wrong direction.
 * Only the opening direction can go missing, since every cut takes the tail,
 * but the closing ones are counted too in case a filter injected its own.
 *
 * @param string $text
 * @return string
 */
function brikpanel_bidi_close_isolates( $text ) {
	$text = (string) $text;

	$unclosed = substr_count( $text, "\u{2066}" )
		+ substr_count( $text, "\u{2067}" )
		+ substr_count( $text, "\u{2068}" )
		- substr_count( $text, "\u{2069}" );

	if ( $unclosed > 0 ) {
		$text .= str_repeat( "\u{2069}", $unclosed );
	}

	return $text;
}

