<?php
/**
 * Guard against orphaned rows in WooCommerce's product lookup tables.
 *
 * WooCommerce keeps a denormalised index of every product in
 * `wc_product_meta_lookup` (and its attribute equivalent). Rows are supposed to
 * disappear with the product: `WC_Post_Data::delete_post_data()` runs on
 * `delete_post` and calls `delete_from_lookup_table()`.
 *
 * One WooCommerce code path puts a row BACK after the product is gone.
 * `WC_Product::delete()` on a variation calls `maybe_defer_product_sync()`,
 * which pushes the variation's PARENT id into the `$wc_deferred_product_sync`
 * global. On `shutdown` at priority 10, `WC_Post_Data::do_deferred_product_sync()`
 * walks that list and runs `WC_Product_Variable::sync( $parent_id )`, ending in a
 * `REPLACE INTO wc_product_meta_lookup`. If the parent was ALSO removed during
 * the same request through a path that skipped WordPress's `delete_post` hook
 * (raw SQL in this plugin's fast delete, or any third-party importer / cleanup
 * tool doing the same), `wc_get_product()` still hands back a phantom product
 * assembled from the warm object cache, and the deleted product's SKU is written
 * back into the index for a post that no longer exists.
 *
 * Why an orphan is worse than it looks: `is_existing_sku()` and
 * `get_product_id_by_sku()` both `INNER JOIN wp_posts`, so wp-admin reports the
 * SKU as free. Only `obtain_lock_on_sku_for_concurrent_requests()` queries the
 * lookup table on its own, and only when `WC()->is_rest_api_request()` is true.
 * The result is a SKU that can be typed into the admin but can never again be
 * created over the REST API — which is exactly how ERP and marketplace
 * integrations push products. Upstream report:
 * https://github.com/woocommerce/woocommerce/issues/57312
 *
 * This guard works in two layers:
 *
 *   1. PREVENTION (`shutdown`, priority 9, before WooCommerce's 10). Drop ids
 *      whose `wp_posts` row is gone from the deferred-sync queue, so the bad
 *      write is never issued and the pointless sync is skipped.
 *   2. CURE (`shutdown`, priority 9999). Sweep any lookup row left behind for a
 *      product this request deleted. Covers ids queued between 9 and 10, another
 *      plugin's late write, and this plugin's fast delete (which fires no hooks
 *      at all and hands its ids over explicitly).
 *
 * Silent by design: removing a row that nothing can reference is not a failure,
 * and logging it would flood debug.log during a bulk delete. Integrators can
 * observe via the `brikpanel_sku_lookup_guard_cleaned` action.
 *
 * @package BrikPanel
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('Brikpanel_Sku_Lookup_Guard')) {

    /**
     * Tracks product deletions and removes lookup rows they leave behind.
     */
    final class Brikpanel_Sku_Lookup_Guard {

        /**
         * Runs before WooCommerce's own `do_deferred_product_sync` (priority 10).
         */
        const SHUTDOWN_PRUNE = 9;

        /**
         * Runs far past every WooCommerce shutdown callback (the latest in core
         * is 100) so a late `REPLACE` from any source is still caught.
         */
        const SHUTDOWN_SWEEP = 9999;

        /**
         * Ids per DELETE statement. Keeps the IN() list inside every sane
         * max_allowed_packet without needing to know the server's limit.
         */
        const CHUNK = 500;

        /**
         * Memory backstop. A single fast-delete batch is ~500 posts, so this is
         * roughly forty batches' worth; past it we stop growing the set.
         */
        const MAX_TRACKED = 20000;

        /**
         * Above this many queued sync ids, skip the prune rather than build a
         * huge IN() list. The cure layer still covers the request.
         */
        const MAX_PRUNE = 5000;

        /**
         * Product / variation ids deleted this request, keyed by blog id.
         *
         * Keyed by blog because `wpdb::set_blog_id()` re-maps
         * `$wpdb->wc_product_meta_lookup` to the new site's table: a request
         * that calls switch_to_blog() between the delete and shutdown would
         * otherwise sweep the wrong site's index.
         *
         * @var array<int, array<int, bool>>
         */
        private static $tracked = [];

        /**
         * @var bool
         */
        private static $booted = false;

        /**
         * Memoised `SHOW TABLES LIKE` results, keyed by table name.
         *
         * @var array<string, bool>
         */
        private static $table_exists = [];

        /**
         * Register the listeners. Idempotent.
         *
         * Deliberately not gated on is_admin(): products are deleted from
         * WP-Cron, WP-CLI, the REST API and third-party importers, and the REST
         * case is the one that actually hurts. The cost on a request that
         * deletes nothing is two array appends into $wp_filter — every callback
         * below returns immediately when there is nothing to do.
         *
         * @return void
         */
        public static function init() {
            if (self::$booted) {
                return;
            }
            self::$booted = true;

            // `deleted_post` fires after the wp_posts row is gone and passes the
            // WP_Post (WP 5.5+). Preferred over `before_delete_post`, where the
            // deletion has not happened yet and can still be aborted downstream.
            add_action('deleted_post', [__CLASS__, 'on_deleted_post'], 10, 2);

            // Redundant on the normal path (WooCommerce fires these right after
            // wp_delete_post) but cheap insurance against a caller that fires
            // them standalone.
            add_action('woocommerce_delete_product', [__CLASS__, 'on_wc_delete'], 10, 1);
            add_action('woocommerce_delete_product_variation', [__CLASS__, 'on_wc_delete'], 10, 1);

            add_action('shutdown', [__CLASS__, 'prune_deferred_sync'], self::SHUTDOWN_PRUNE);
            add_action('shutdown', [__CLASS__, 'sweep'], self::SHUTDOWN_SWEEP);
        }

        /* -----------------------------------------------------------------
         * Collection
         * -------------------------------------------------------------- */

        /**
         * Record ids to sweep at the end of the request.
         *
         * @param int|int[] $ids One id or a list of them.
         * @return void
         */
        public static function track($ids) {
            if (!is_array($ids)) {
                $ids = [$ids];
            }

            $blog = self::blog_key();
            if (!isset(self::$tracked[$blog])) {
                self::$tracked[$blog] = [];
            }

            if (count(self::$tracked[$blog]) >= self::MAX_TRACKED) {
                return;
            }

            foreach ($ids as $id) {
                $id = absint($id);
                if ($id > 0) {
                    self::$tracked[$blog][$id] = true;
                }
                if (count(self::$tracked[$blog]) >= self::MAX_TRACKED) {
                    return;
                }
            }
        }

        /**
         * `deleted_post` listener. Only products and variations are of interest.
         *
         * @param int          $post_id Deleted post id.
         * @param WP_Post|null $post    The post that was deleted, when supplied.
         * @return void
         */
        public static function on_deleted_post($post_id, $post = null) {
            $type = ($post instanceof WP_Post) ? $post->post_type : get_post_type($post_id);

            if ($type === 'product' || $type === 'product_variation') {
                self::track($post_id);
            }
        }

        /**
         * `woocommerce_delete_product` / `..._variation` listener.
         *
         * Tracked without a type check: these hooks only ever carry product ids,
         * and a false positive is inert anyway because the sweep's
         * `WHERE p.ID IS NULL` can never match a post that still exists.
         *
         * @param int $product_id Product or variation id.
         * @return void
         */
        public static function on_wc_delete($product_id) {
            self::track($product_id);
        }

        /* -----------------------------------------------------------------
         * Layer 1 - prevention
         * -------------------------------------------------------------- */

        /**
         * Drop dead ids from WooCommerce's deferred-sync queue before it runs.
         *
         * Inspects every queued id, not just the ones we tracked: the dangerous
         * id is a PARENT queued by a deleted child, and that parent may have been
         * removed by code we never observed. An id with no `wp_posts` row cannot
         * be legitimately synced, so pruning it can never affect a live product.
         *
         * @return void
         */
        public static function prune_deferred_sync() {
            global $wc_deferred_product_sync, $wpdb;

            if (empty($wc_deferred_product_sync) || !is_array($wc_deferred_product_sync)) {
                return;
            }

            $ids = array_values(array_unique(array_filter(array_map('absint', $wc_deferred_product_sync))));
            if (empty($ids) || count($ids) > self::MAX_PRUNE) {
                return;
            }

            $list = implode(',', $ids);

            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $list is absint()-filtered integers only.
            $alive = $wpdb->get_col("SELECT ID FROM {$wpdb->posts} WHERE ID IN ({$list})");
            $alive = array_map('intval', (array) $alive);

            // Fast path: everything queued still exists, which is the norm.
            if (count($alive) === count($ids)) {
                return;
            }

            $wc_deferred_product_sync = array_values(array_intersect($ids, $alive));
        }

        /* -----------------------------------------------------------------
         * Layer 2 - cure
         * -------------------------------------------------------------- */

        /**
         * Remove lookup rows left behind for products deleted this request.
         *
         * @return void
         */
        public static function sweep() {
            if (empty(self::$tracked)) {
                return;
            }

            $tracked        = self::$tracked;
            self::$tracked  = [];
            $current        = self::blog_key();
            $can_switch     = function_exists('switch_to_blog') && function_exists('is_multisite') && is_multisite();

            // Fold in whatever is still sitting in WooCommerce's deferred-sync
            // queue. Those ids are exactly the ones a sync could have just
            // written a row for, and a parent removed by a hook-free path we
            // never observed (a third-party importer's raw DELETE) reaches us
            // only this way. Live ids in the list cost nothing: the sweep's
            // `WHERE p.ID IS NULL` can never match a post that still exists.
            // Only done on a request that already deleted something, so an
            // ordinary storefront order never pays for it.
            global $wc_deferred_product_sync;
            if (!empty($wc_deferred_product_sync) && is_array($wc_deferred_product_sync)) {
                $queued = array_slice(
                    array_unique(array_filter(array_map('absint', $wc_deferred_product_sync))),
                    0,
                    self::MAX_PRUNE
                );
                foreach ($queued as $queued_id) {
                    if (!isset($tracked[$current][$queued_id])) {
                        $tracked[$current][$queued_id] = true;
                    }
                }
            }

            foreach ($tracked as $blog_id => $ids) {
                $ids = array_keys($ids);
                if (empty($ids)) {
                    continue;
                }

                if ($blog_id !== $current && $can_switch) {
                    switch_to_blog($blog_id);
                    try {
                        self::sweep_current_blog($ids, $blog_id);
                    } finally {
                        // Balance the switch no matter what. This runs on
                        // shutdown, where an unbalanced blog stack would leave
                        // every later callback pointed at the wrong site.
                        restore_current_blog();
                    }
                    continue;
                }

                self::sweep_current_blog($ids, $blog_id);
            }
        }

        /**
         * Sweep the lookup tables of whichever blog is currently active.
         *
         * @param int[] $ids     Product / variation ids.
         * @param int   $blog_id Blog the ids belong to (for the action payload).
         * @return void
         */
        private static function sweep_current_blog(array $ids, $blog_id) {
            global $wpdb;

            $meta_table = self::table_name('wc_product_meta_lookup');
            $attr_table = self::table_name('wc_product_attributes_lookup');

            $has_meta = self::table_exists($meta_table);
            $has_attr = self::table_exists($attr_table);

            if (!$has_meta && !$has_attr) {
                return;
            }

            $removed = 0;

            foreach (array_chunk($ids, self::CHUNK) as $chunk) {
                $list = implode(',', array_map('absint', $chunk));
                if ($list === '') {
                    continue;
                }

                if ($has_meta) {
                    // LEFT JOIN … IS NULL rather than NOT IN (subquery): the join
                    // is a covering PRIMARY range on the lookup side and an
                    // eq_ref "Not exists" probe on posts, so nothing is scanned.
                    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $list is absint()-mapped integers only.
                    $wpdb->query(
                        "DELETE l FROM {$meta_table} l
                         LEFT JOIN {$wpdb->posts} p ON p.ID = l.product_id
                         WHERE l.product_id IN ({$list}) AND p.ID IS NULL"
                    );
                    $removed += max(0, (int) $wpdb->rows_affected);
                }

                if ($has_attr) {
                    // Two single-column statements instead of one OR: an OR
                    // across two columns cannot use either index. Same bug class
                    // as the meta lookup, because ProductAttributesLookupDataStore
                    // rebuilds rows from `woocommerce_after_product_object_save`.
                    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $list is absint()-mapped integers only.
                    $wpdb->query(
                        "DELETE a FROM {$attr_table} a
                         LEFT JOIN {$wpdb->posts} p ON p.ID = a.product_id
                         WHERE a.product_id IN ({$list}) AND p.ID IS NULL"
                    );
                    $removed += max(0, (int) $wpdb->rows_affected);

                    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $list is absint()-mapped integers only.
                    $wpdb->query(
                        "DELETE a FROM {$attr_table} a
                         LEFT JOIN {$wpdb->posts} p ON p.ID = a.product_or_parent_id
                         WHERE a.product_or_parent_id IN ({$list}) AND p.ID IS NULL"
                    );
                    $removed += max(0, (int) $wpdb->rows_affected);
                }
            }

            if ($removed > 0) {
                /**
                 * Fires after orphaned product lookup rows were removed.
                 *
                 * @since 3.2.76
                 *
                 * @param int   $removed Rows removed across both lookup tables.
                 * @param int[] $ids     Product ids inspected.
                 * @param int   $blog_id Blog the sweep ran against.
                 */
                do_action('brikpanel_sku_lookup_guard_cleaned', $removed, $ids, (int) $blog_id);
            }
        }

        /* -----------------------------------------------------------------
         * Helpers
         * -------------------------------------------------------------- */

        /**
         * Resolve a lookup table name.
         *
         * WooCommerce registers `wc_product_meta_lookup` on $wpdb in
         * define_tables(), which keeps it correct across switch_to_blog(). It
         * never registers the attribute lookup, so that one always falls back to
         * the current prefix.
         *
         * @param string $suffix Table name without prefix.
         * @return string
         */
        private static function table_name($suffix) {
            global $wpdb;

            return isset($wpdb->$suffix) && is_string($wpdb->$suffix) && $wpdb->$suffix !== ''
                ? $wpdb->$suffix
                : $wpdb->prefix . $suffix;
        }

        /**
         * Does the table exist? Memoised per table name per request.
         *
         * @param string $table Fully prefixed table name.
         * @return bool
         */
        private static function table_exists($table) {
            global $wpdb;

            if (isset(self::$table_exists[$table])) {
                return self::$table_exists[$table];
            }

            self::$table_exists[$table] = ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table);

            return self::$table_exists[$table];
        }

        /**
         * Current blog id, or 0 on a single site.
         *
         * @return int
         */
        private static function blog_key() {
            return function_exists('get_current_blog_id') ? (int) get_current_blog_id() : 0;
        }
    }

    Brikpanel_Sku_Lookup_Guard::init();
}

if (!function_exists('brikpanel_sku_guard_track_ids')) {
    /**
     * Register product / variation ids removed by a hook-free code path.
     *
     * BrikPanel's fast delete deletes rows with raw SQL and fires no WordPress
     * hooks at all, so the guard's `deleted_post` listener never sees those ids.
     * Callers hand them over explicitly instead.
     *
     * @since 3.2.76
     *
     * @param int[] $ids Product / variation ids.
     * @return void
     */
    function brikpanel_sku_guard_track_ids(array $ids) {
        if (class_exists('Brikpanel_Sku_Lookup_Guard')) {
            Brikpanel_Sku_Lookup_Guard::track($ids);
        }
    }
}
