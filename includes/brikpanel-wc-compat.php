<?php
/**
 * BrikPanel WooCommerce version compatibility layer.
 *
 * ONE ADDRESS for every WooCommerce API that is NEWER than the oldest
 * WooCommerce this plugin can still be running on.
 *
 * WHY THIS FILE EXISTS. Three times now a feature has shipped calling a
 * WooCommerce method that simply does not exist on an older store. A missing
 * method is a PHP `Error`, not an `Exception`, so it sails straight through
 * the `try { } catch ( \Exception $e )` blocks that look like they would
 * contain it, and the merchant gets a white screen instead of a broken field:
 *
 *   - 3.2.82: `as_has_scheduled_action()` (Action Scheduler 3.3.0, absent from
 *     the 3.1.2 that WooCommerce 4.0 bundles). It sat on `init`, so it took
 *     the whole SITE down, storefront, login page and wp-admin, and WP-CLI
 *     could not load WordPress either, so the plugin could not be switched
 *     off. See includes/cron/class-brikpanel-cron.php::has_scheduled().
 *   - 3.3.20: `WC_Product::get_global_unique_id()` / `set_global_unique_id()`
 *     (WooCommerce 9.2.0). Nine call sites: the product editor, both product
 *     lists and the command palette.
 *   - 3.3.20: `WC_Coupon::set_status()` (WooCommerce 6.2.0). Two call sites,
 *     creating and duplicating a coupon.
 *   - 3.3.20: `OrderUtil::custom_orders_table_usage_is_enabled()`
 *     (WooCommerce 6.9.0). Two ungated static calls in the Sheets order sync,
 *     while five sibling call sites elsewhere were correctly guarded.
 *
 * Each one was guarded individually, by hand, which is exactly why each one
 * was also forgotten individually. Routing them through this file gives
 * tools/wc-floor-audit.php a single place to assert against: a raw call to one
 * of these symbols anywhere ELSE in the plugin is a finding.
 *
 * DO NOT trust WooCommerce's own `@since` tags. `abstract-wc-product.php`
 * documents the GTIN accessors as `@since 9.1.0`, but the 9.1.0 release does
 * not contain them, they shipped in 9.2.0. Every version number in this file
 * and in the audit tool's map was established by downloading the releases and
 * looking, never by reading a docblock.
 *
 * @package BrikPanel
 * @since   3.3.20
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The oldest WooCommerce this plugin is SUPPORTED on.
 *
 * Kept in sync with the `WC requires at least` header in brikpanel.php ,
 * tools/wc-floor-audit.php fails when the two drift apart.
 *
 * This is a support statement, not a kill switch. Below it BrikPanel still
 * loads, still works and must still never fatal; it only says so in a notice.
 * That distinction is deliberate: a merchant who already has the plugin
 * installed should not lose their admin because they are behind on updates.
 */
if ( ! defined( 'BRIKPANEL_WC_MIN' ) ) {
    define( 'BRIKPANEL_WC_MIN', '9.2' );
}

/**
 * WooCommerce's post meta key for the GTIN / UPC / EAN / ISBN field.
 *
 * Both products and variations use this same key on their own post, in every
 * WooCommerce that has the feature, verified in
 * includes/data-stores/class-wc-product-data-store-cpt.php and
 * includes/data-stores/class-wc-product-variation-data-store-cpt.php. Writing
 * it on an older store is therefore forward compatible: the day the merchant
 * updates WooCommerce, the values they typed are already where WooCommerce
 * looks for them, with no migration step.
 */
const BRIKPANEL_GTIN_META_KEY = '_global_unique_id';

// -----------------------------------------------------------------------------
// GTIN / UPC / EAN / ISBN  (WC_Product::get|set_global_unique_id, WooCommerce 9.2.0)
// -----------------------------------------------------------------------------

/**
 * Whether this WooCommerce carries the native GTIN accessors.
 *
 * Probing the abstract base is enough: the two methods live on `WC_Product`
 * itself and no subclass overrides them, so one answer covers simple, variable
 * and variation objects alike. `method_exists()` on a class that does not
 * exist returns false rather than fatalling, so this is also safe to call when
 * WooCommerce is missing entirely.
 *
 * @since 3.3.20
 * @return bool
 */
function brikpanel_wc_supports_gtin() {
    static $supported = null;

    if ( null !== $supported ) {
        return $supported;
    }

    $supported = method_exists( 'WC_Product', 'get_global_unique_id' )
        && method_exists( 'WC_Product', 'set_global_unique_id' );

    return $supported;
}

/**
 * The GTIN stored on ONE product or variation.
 *
 * NO PARENT FALLBACK, deliberately. `_global_unique_id` does appear in the
 * variation data store's `$parent_meta_key_to_props`, but that array only
 * feeds `set_parent_data()`, and `WC_Product_Variation` does not override
 * `get_global_unique_id()` the way it overrides `get_sku()`, `get_weight()`
 * and friends. Nothing in WooCommerce ever reads the parent's value back out.
 * So a variation's GTIN is its own or nothing, and this mirrors that exactly ,
 * inheriting from the parent here would invent behaviour WooCommerce does not
 * have and would make the fallback disagree with the native path.
 *
 * @since 3.3.20
 * @param WC_Product|int|null $product Product, variation, or a post ID.
 * @return string GTIN, or '' when none is on file.
 */
function brikpanel_wc_gtin( $product ) {
    if ( is_numeric( $product ) ) {
        $id = (int) $product;

        return $id > 0 ? (string) get_post_meta( $id, BRIKPANEL_GTIN_META_KEY, true ) : '';
    }

    if ( ! is_object( $product ) ) {
        return '';
    }

    if ( brikpanel_wc_supports_gtin() ) {
        return (string) $product->get_global_unique_id();
    }

    // get_meta() rather than get_post_meta(): it also returns a value written
    // by update_meta_data() earlier in the same request but not yet saved,
    // which is what makes a write-then-read inside one save path behave the
    // same on both floors. Context 'edit' skips the display filters.
    if ( method_exists( $product, 'get_meta' ) ) {
        return (string) $product->get_meta( BRIKPANEL_GTIN_META_KEY, true, 'edit' );
    }

    return '';
}

/**
 * Strip everything a GTIN can never contain.
 *
 * Byte for byte what WooCommerce does in
 * `WC_Product::set_global_unique_id()`: digits, hyphens, and X/x for the
 * ISBN-10 check digit. Note that this is silent, an alphanumeric barcode is
 * mangled rather than rejected, on every WooCommerce version. Matching that
 * exactly is the point: the two floors must not disagree about what gets
 * stored.
 *
 * @since 3.3.20
 * @param string $gtin Raw user input.
 * @return string
 */
function brikpanel_wc_gtin_sanitize( $gtin ) {
    return (string) preg_replace( '/[^0-9Xx\-]/', '', (string) $gtin );
}

/**
 * The product or variation that already owns this GTIN, if any.
 *
 * WooCommerce answers this from `wc_product_meta_lookup.global_unique_id`, a
 * column that only exists once the feature does, the WooCommerce 4.0 lookup
 * table has fourteen columns and that is not one of them. So the fallback asks
 * post meta instead, with the same clauses WooCommerce uses: products and
 * variations, trash excluded, the product itself excluded.
 *
 * The same join already runs in the command palette's product source
 * (front-end/search/brikpanel-search.php), and it rides the meta_key index.
 * It only runs on save, and only for a non-empty value.
 *
 * WooCommerce wraps its value in wp_slash() before prepare() to work around
 * core ticket #27421; we do not, because the value reaching us has already
 * been unslashed by the caller and, after the sanitiser above, cannot contain
 * a backslash at all.
 *
 * @since 3.3.20
 * @param string $gtin       Sanitised GTIN.
 * @param int    $exclude_id Product/variation ID to ignore (itself).
 * @return int Owning post ID, or 0 when the GTIN is free.
 */
function brikpanel_wc_gtin_owner( $gtin, $exclude_id = 0 ) {
    global $wpdb;

    $gtin = (string) $gtin;

    if ( '' === $gtin ) {
        return 0;
    }

    return (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT p.ID
               FROM {$wpdb->posts} p
               INNER JOIN {$wpdb->postmeta} pm
                       ON p.ID = pm.post_id
                      AND pm.meta_key = %s
              WHERE p.post_type IN ( 'product', 'product_variation' )
                AND p.post_status != 'trash'
                AND pm.meta_value = %s
                AND p.ID <> %d
              LIMIT 1",
            BRIKPANEL_GTIN_META_KEY,
            $gtin,
            (int) $exclude_id
        )
    );
}

/**
 * Write (or clear) the GTIN on ONE product or variation.
 *
 * On a WooCommerce that has the feature this is `set_global_unique_id()` and
 * nothing else, so the native path, including the lookup-table mirror and the
 * `WC_Data_Exception` the callers already handle, is completely unchanged.
 *
 * On an older store it reproduces WooCommerce's own rules: the same sanitiser,
 * the same "X only as the final ISBN-10 check digit" test, the same duplicate
 * check, and the same two error codes with the same HTTP status and the same
 * `resource_id` payload, so a caller reading `$e->getErrorCode()` cannot tell
 * the two floors apart.
 *
 * THE WRITE USES update_meta_data(), NOT update_post_meta(). On WooCommerce
 * 4.0 `_global_unique_id` is not in the product data store's
 * `$internal_meta_keys`, so the product object loads it as ordinary meta ,
 * which means a raw update_post_meta() before `$product->save()` is promptly
 * overwritten by the stale copy the object is still holding, and the barcode
 * the merchant just typed disappears on the next page load. Going through the
 * object also makes brand-new products and brand-new variations work:
 * WooCommerce's `create()` assigns the post ID before it flushes meta, whereas
 * update_post_meta() at that moment would write against ID 0.
 *
 * @since 3.3.20
 * @param WC_Product $product Product or variation. Not yet saved is fine.
 * @param string     $gtin    Raw user input; '' clears the field.
 * @throws WC_Data_Exception On an invalid format or a duplicate.
 * @return string The value actually stored.
 */
function brikpanel_wc_set_gtin( $product, $gtin ) {
    if ( ! is_object( $product ) ) {
        return '';
    }

    if ( brikpanel_wc_supports_gtin() ) {
        $product->set_global_unique_id( $gtin );

        return (string) $product->get_global_unique_id( 'edit' );
    }

    $value = brikpanel_wc_gtin_sanitize( $gtin );

    // WooCommerce gates both checks on get_object_read() so that a product
    // being hydrated from the database never validates against itself. Mirror
    // that, and tolerate its absence on an object that predates the method.
    $is_read = ! method_exists( $product, 'get_object_read' ) || $product->get_object_read();

    if ( $is_read && '' !== $value ) {
        if ( ! preg_match( '/^[0-9\-]*[0-9Xx]?$/', $value ) ) {
            throw new WC_Data_Exception(
                'product_invalid_global_unique_id_format',
                __( 'Invalid GTIN, UPC, EAN, or ISBN. The letter X is only valid as the final ISBN-10 check digit.', 'brikpanel' ),
                400
            );
        }

        $owner = brikpanel_wc_gtin_owner( $value, $product->get_id() );

        if ( $owner ) {
            throw new WC_Data_Exception(
                'product_invalid_global_unique_id',
                __( 'Invalid or duplicated GTIN, UPC, EAN or ISBN.', 'brikpanel' ),
                400,
                array( 'resource_id' => $owner )
            );
        }
    }

    // '' is written rather than deleted, matching what the native path leaves
    // behind when the merchant clears the field, and keeping the command
    // palette's `meta_value <> ''` filter meaningful.
    $product->update_meta_data( BRIKPANEL_GTIN_META_KEY, $value );

    return $value;
}

// -----------------------------------------------------------------------------
// Coupon status  (WC_Coupon::set_status, WooCommerce 6.2.0)
// -----------------------------------------------------------------------------

/**
 * Set a coupon's status BEFORE it is saved.
 *
 * Coupon status is genuinely a two-step problem on an older store: there is no
 * setter to stage the value on the object, and no post to update either,
 * because a brand-new coupon has no ID until `save()` runs. So this pair
 * splits along that seam, stage it here when we can, and let
 * brikpanel_wc_coupon_sync_status() finish the job afterwards when we could
 * not.
 *
 * `WC_Coupon::get_status()` is missing on those stores too, so nothing here
 * may ask the object what its status is.
 *
 * @since 3.3.20
 * @param WC_Coupon $coupon Coupon object.
 * @param string    $status Target post status.
 * @return bool True when the status was staged on the object.
 */
function brikpanel_wc_coupon_set_status( $coupon, $status ) {
    if ( is_object( $coupon ) && method_exists( $coupon, 'set_status' ) ) {
        $coupon->set_status( $status );

        return true;
    }

    return false;
}

/**
 * Make a saved coupon's status match, for stores that could not stage it.
 *
 * Runs AFTER `$coupon->save()`. On a modern WooCommerce the post was just
 * written and is warm in cache, so the comparison short-circuits and this
 * costs zero queries. On WooCommerce 4.0 the 'publish' case short-circuits
 * too, because that store's coupon data store hardcodes
 * `'post_status' => 'publish'` in its `wp_insert_post()` call, only the
 * duplicate flow's 'draft' actually writes.
 *
 * wp_update_post() rather than a direct $wpdb->update() so post counts,
 * caches and status transitions stay honest; that is what WooCommerce's own
 * coupon data store does.
 *
 * @since 3.3.20
 * @param int    $coupon_id Saved coupon ID.
 * @param string $status    Target post status.
 * @return bool True when a write actually happened.
 */
function brikpanel_wc_coupon_sync_status( $coupon_id, $status ) {
    $coupon_id = (int) $coupon_id;

    if ( $coupon_id <= 0 || '' === $status ) {
        return false;
    }

    if ( get_post_status( $coupon_id ) === $status ) {
        return false;
    }

    wp_update_post(
        array(
            'ID'          => $coupon_id,
            'post_status' => $status,
        )
    );

    return true;
}

// -----------------------------------------------------------------------------
// HPOS  (OrderUtil::custom_orders_table_usage_is_enabled, WooCommerce 6.9.0)
// -----------------------------------------------------------------------------

/**
 * Whether orders live in WooCommerce's own tables rather than in posts.
 *
 * Seven call sites across the plugin asked this question; five guarded the
 * class and two did not, which is the entire bug. One expression, one answer.
 *
 * The answer is cached per request because HPOS cannot be switched mid-request
 *, but only once WooCommerce itself has loaded. Caching earlier would freeze
 * a "no" produced merely because the autoloader was not registered yet:
 * plugins load alphabetically and BrikPanel comes before WooCommerce.
 *
 * @since 3.3.20
 * @return bool
 */
function brikpanel_wc_hpos_enabled() {
    static $enabled = null;

    if ( null !== $enabled ) {
        return $enabled;
    }

    $answer = class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
        && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();

    if ( class_exists( 'WooCommerce' ) ) {
        $enabled = $answer;
    }

    return $answer;
}

// -----------------------------------------------------------------------------
// Analytics  (FeaturesUtil 7.x, Admin\Features\Features 5.x, Admin\Loader 4.x)
// -----------------------------------------------------------------------------

/**
 * Whether WooCommerce Analytics (the wc-admin reports app) is switched on.
 *
 * A link into Analytics is only worth showing when the screen behind it exists,
 * so every surface that points there asks this one function: the top bar's
 * "New customers" row and the toolbar's Analytics shortcut.
 *
 * The answer has moved between three WooCommerce APIs over the years, and each
 * is only trusted where it is the real source:
 *
 *  1. The `woocommerce_admin_disabled` filter is checked first and on its own.
 *     Only WooCommerce 11.1+ folds it into the features engine, so on older
 *     releases FeaturesUtil would answer "enabled" for a store where the whole
 *     wc-admin app, Customers report included, is switched off.
 *  2. FeaturesUtil, not the older WC Admin feature-flag shim: WooCommerce 11.1.0
 *     retired the `analytics` flag on Features::is_enabled(), so every admin
 *     page load on 11.1+ pushed a deprecation line into the error log (and into
 *     error_log() outright on ajax/REST requests). FeaturesUtil reads the very
 *     same option and filter without the notice. The presence check matters:
 *     feature_is_enabled() answers false for a feature it does not know, which
 *     would silently hide a working screen (WooCommerce 7.0-7.4 ship the
 *     features engine without an `analytics` entry).
 *  3. Admin\Features\Features for the releases before the features engine,
 *     where the shim is not deprecated either.
 *  4. Admin\Loader::is_feature_enabled() for WooCommerce 4.x, which has neither
 *     of the above but does ship the Analytics app. Without this branch the
 *     answer on a 4.x store was "off" although the reports worked.
 *
 * Cached once `init` has run, for the same reason as the HPOS check above:
 * BrikPanel loads before WooCommerce, and an early "no" must not stick.
 *
 * @since 3.3.20
 * @return bool
 */
function brikpanel_wc_analytics_enabled() {
    static $cached = null;

    if ( null !== $cached ) {
        return $cached;
    }

    /**
     * Filter allowing WooCommerce Admin features to be disabled.
     *
     * @param bool $disabled False.
     */
    $on = apply_filters( 'woocommerce_admin_disabled', false ) ? false : null;

    if ( null === $on
        && function_exists( 'wc_get_container' )
        && class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' )
        && method_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil', 'get_features' )
        && method_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil', 'feature_is_enabled' ) ) {
        try {
            $features = \Automattic\WooCommerce\Utilities\FeaturesUtil::get_features( true );
            if ( is_array( $features ) && isset( $features['analytics'] ) ) {
                $on = (bool) \Automattic\WooCommerce\Utilities\FeaturesUtil::feature_is_enabled( 'analytics' );
            }
        } catch ( \Throwable $e ) {
            $on = null;
        }
    }

    if ( null === $on ) {
        if ( class_exists( '\Automattic\WooCommerce\Admin\Features\Features' )
            && method_exists( '\Automattic\WooCommerce\Admin\Features\Features', 'is_enabled' ) ) {
            $on = (bool) \Automattic\WooCommerce\Admin\Features\Features::is_enabled( 'analytics' );
        } elseif ( class_exists( '\Automattic\WooCommerce\Admin\Loader' )
            && method_exists( '\Automattic\WooCommerce\Admin\Loader', 'is_feature_enabled' ) ) {
            $on = (bool) \Automattic\WooCommerce\Admin\Loader::is_feature_enabled( 'analytics' );
        }
    }

    $on = (bool) $on;

    if ( did_action( 'init' ) ) {
        $cached = $on;
    }

    return $on;
}

// -----------------------------------------------------------------------------
// Cost of Goods Sold  (WC_Product::set_cogs_value, absent from WooCommerce 4.0.1)
// -----------------------------------------------------------------------------

/**
 * Whether WooCommerce's own cost setter will actually store a cost.
 *
 * `WC_Product::set_cogs_value()` is not only missing on older stores. Where it
 * exists but the "Cost of Goods Sold" feature is switched off, it stores
 * nothing and calls wc_doing_it_wrong() instead, which on AJAX and REST
 * requests (every BrikPanel save) goes straight to error_log(): one line per
 * product or variation, on every save. So every caller asks this first.
 *
 * Skipping the setter while the feature is off loses nothing. The callers write
 * the cost meta themselves beforehand (brikpanel_set_product_cogs_raw()), and
 * WooCommerce's product data store only writes `_cogs_total_value` on save
 * while the feature is on. The reverse is NOT safe: skipping it while
 * WooCommerce says "on" lets save() write the object's old cost back over the
 * one just typed. Hence every doubt answers true, and the answer comes from the
 * same features engine WooCommerce's own check reads
 * (CogsAwareTrait::cogs_is_enabled()), never from a guess of ours.
 *
 * Not cached: brikpanel_enable_cogs_default() can switch the option on earlier
 * in the same request, and the check is only an autoloaded option read.
 *
 * @since 3.3.25
 * @param WC_Product|null $product Product or variation about to receive a
 *                                 cost; null asks about the store only.
 * @return bool True when set_cogs_value() exists and should be called.
 */
function brikpanel_wc_cogs_enabled( $product = null ) {
    if ( null !== $product && ( ! is_object( $product ) || ! method_exists( $product, 'set_cogs_value' ) ) ) {
        return false;
    }

    if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' )
        && method_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil', 'feature_is_enabled' ) ) {
        try {
            return (bool) \Automattic\WooCommerce\Utilities\FeaturesUtil::feature_is_enabled( 'cost_of_goods_sold' );
        } catch ( \Throwable $e ) {
            // Unsure means "call the setter": a stray log line is the lesser
            // harm next to a cost that silently reverts on save.
            return true;
        }
    }

    return 'yes' === get_option( 'woocommerce_feature_cost_of_goods_sold_enabled' );
}

// -----------------------------------------------------------------------------
// Admin screens that moved  (wc-orders 7.1, Analytics Overview 4.2 / WC Admin 1.2)
// -----------------------------------------------------------------------------

/**
 * The orders list, optionally narrowed to one status, on the screen this store
 * really has.
 *
 * `admin.php?page=wc-orders` only exists where orders live in WooCommerce's own
 * tables (HPOS). Below WooCommerce 7.1 there is no such page and WordPress
 * answers "Sorry, you are not allowed to access this page". On a newer store
 * that keeps its orders in posts, WooCommerce redirects the page to edit.php but
 * passes `status` along unchanged, and the posts list only filters on
 * `post_status`, so a "Processing orders" link opened every order.
 *
 * The HPOS form is exactly the one the top bar always used.
 *
 * @since 3.3.22
 * @param string $status Order status without the `wc-` prefix (e.g. 'processing'), or '' for all.
 * @return string Absolute admin URL.
 */
function brikpanel_wc_orders_list_url( $status = '' ) {
    $status = sanitize_key( (string) $status );
    if ( 0 === strpos( $status, 'wc-' ) ) {
        $status = substr( $status, 3 );
    }

    if ( brikpanel_wc_hpos_enabled() ) {
        return admin_url( 'admin.php?page=wc-orders' . ( '' !== $status ? '&status=' . $status : '' ) );
    }

    return admin_url( 'edit.php?post_type=shop_order' . ( '' !== $status ? '&post_status=wc-' . $status : '' ) );
}

/**
 * The report WooCommerce opens from its own Analytics menu.
 *
 * WooCommerce Admin 1.2 (bundled from WooCommerce 4.2.0) added the Overview
 * report and made it the landing page. Before that, WooCommerce 4.0 and 4.1 open
 * Revenue and have no `/analytics/overview` route at all: their wc-admin app
 * draws an empty screen for it. Verified against the 4.0.1, 4.1.0, 4.2.0 and
 * 4.3.0 release packages.
 *
 * In wp-admin the menu WooCommerce actually registered decides. The toolbar is
 * also drawn on the storefront, where no admin menu exists, so the WooCommerce
 * Admin version decides there (it also covers a standalone WooCommerce Admin
 * plugin running on an older WooCommerce); Revenue is the safe answer below the
 * first release checked to have Overview, since every version has it.
 *
 * @since 3.3.22
 * @return string Absolute admin URL.
 */
function brikpanel_wc_analytics_landing_url() {
    $slug = '';
    if ( function_exists( 'brikpanel_nav_wc_analytics_slug' ) && isset( $GLOBALS['menu'] ) && is_array( $GLOBALS['menu'] ) ) {
        $slug = brikpanel_nav_wc_analytics_slug( $GLOBALS['menu'] );
    }

    if ( '' === $slug ) {
        if ( defined( 'WC_ADMIN_VERSION_NUMBER' ) ) {
            $old = version_compare( (string) WC_ADMIN_VERSION_NUMBER, '1.2.3', '<' );
        } else {
            $old = defined( 'WC_VERSION' ) && version_compare( WC_VERSION, '4.2.0', '<' );
        }
        $slug = 'wc-admin&path=' . ( $old ? '/analytics/revenue' : '/analytics/overview' );
    }

    return admin_url( 'admin.php?page=' . $slug );
}

// -----------------------------------------------------------------------------
// Unsupported-version notice
// -----------------------------------------------------------------------------

/**
 * Tell administrators when the store is below the supported WooCommerce.
 *
 * Advisory only. Nothing is switched off and nothing behaves differently, the
 * compatibility layer above keeps every affected feature working. The notice
 * exists because the `WC requires at least` header does not: WooCommerce reads
 * that header in via `wc_enable_wc_plugin_headers()` and then never looks at
 * it again, and WordPress's own `Requires Plugins` gate only checks that
 * WooCommerce is installed and active, never which version. So the header
 * alone would warn nobody.
 *
 * This cannot run at file scope. Plugins load alphabetically, BrikPanel loads
 * before WooCommerce, and `WC_VERSION` is not defined yet at that point.
 *
 * @since 3.3.20
 * @return void
 */
function brikpanel_wc_min_version_notice() {
    if ( ! defined( 'WC_VERSION' ) || ! current_user_can( 'activate_plugins' ) ) {
        return;
    }

    if ( version_compare( WC_VERSION, BRIKPANEL_WC_MIN, '>=' ) ) {
        return;
    }

    // Dismissal is remembered per user AND per WooCommerce version, so a store
    // that drops back onto an older WooCommerce is told again rather than
    // silently keeping a dismissal that referred to a different version.
    $dismissed = (string) get_user_meta( get_current_user_id(), 'brikpanel_wc_min_notice_dismissed', true );

    if ( $dismissed === (string) WC_VERSION ) {
        return;
    }

    $nonce = wp_create_nonce( 'brikpanel_wc_min_notice' );

    echo '<div class="notice notice-warning is-dismissible brikpanel-notice brikpanel-wc-min-notice" data-nonce="' . esc_attr( $nonce ) . '"><p><strong>BrikPanel:</strong> '
        . esc_html(
            sprintf(
                /* translators: 1: the WooCommerce version installed on this site, 2: the oldest WooCommerce version BrikPanel supports. */
                __( 'This store runs WooCommerce %1$s, which is older than the %2$s BrikPanel is supported on. Everything still works, but updating WooCommerce is recommended.', 'brikpanel' ),
                WC_VERSION,
                BRIKPANEL_WC_MIN
            )
        )
        . '</p></div>';
    ?>
    <script>
        (function () {
            var notice = document.querySelector('.brikpanel-wc-min-notice');
            if (!notice) return;
            notice.addEventListener('click', function (e) {
                if (!e.target.classList.contains('notice-dismiss')) return;
                var fd = new FormData();
                fd.append('action', 'brikpanel_dismiss_wc_min_notice');
                fd.append('_ajax_nonce', notice.getAttribute('data-nonce'));
                try {
                    fetch(<?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>, { method: 'POST', body: fd, credentials: 'same-origin' });
                } catch (err) {}
            });
        })();
    </script>
    <?php
}
add_action( 'admin_notices', 'brikpanel_wc_min_version_notice' );

/**
 * Record that this user dismissed the unsupported-version notice.
 *
 * @since 3.3.20
 * @return void
 */
function brikpanel_wc_min_notice_dismiss() {
    check_ajax_referer( 'brikpanel_wc_min_notice' );

    if ( ! current_user_can( 'activate_plugins' ) ) {
        wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
    }

    update_user_meta(
        get_current_user_id(),
        'brikpanel_wc_min_notice_dismissed',
        defined( 'WC_VERSION' ) ? WC_VERSION : '1'
    );

    wp_send_json_success();
}
add_action( 'wp_ajax_brikpanel_dismiss_wc_min_notice', 'brikpanel_wc_min_notice_dismiss' );
