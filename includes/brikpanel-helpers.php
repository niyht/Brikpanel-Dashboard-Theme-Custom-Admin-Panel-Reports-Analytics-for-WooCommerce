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
    if ( $hpos ) {
        $col = $id_column ?: 'id';
        return [
            'sql'  => " AND {$col} NOT IN (SELECT order_id FROM {$wpdb->prefix}wc_orders_meta WHERE meta_key = %s)",
            'args' => [ $meta_key ],
        ];
    }
    $col = $id_column ?: 'ID';
    return [
        'sql'  => " AND {$col} NOT IN (SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s)",
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
