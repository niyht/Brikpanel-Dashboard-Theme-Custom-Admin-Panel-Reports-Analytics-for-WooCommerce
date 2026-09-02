<?php
/**
 * BrikPanel — BrikControl check: WooCommerce product index integrity.
 *
 * WooCommerce keeps a denormalised copy of every product in
 * `wc_product_meta_lookup` so SKU lookups, price sorting and stock filters do
 * not have to join postmeta. Rows are meant to vanish with the product, but a
 * deleted product can leave one behind (see includes/brikpanel-sku-lookup-guard.php
 * for the mechanism and the upstream report).
 *
 * A leftover row matters for one specific reason. `is_existing_sku()` and
 * `get_product_id_by_sku()` both INNER JOIN wp_posts, so the WordPress admin
 * reports the SKU as free. Only `obtain_lock_on_sku_for_concurrent_requests()`
 * queries the lookup table on its own, and only when the request came through
 * the REST API. The merchant therefore sees a SKU that can be typed into the
 * product editor but can never again be created by their ERP, marketplace
 * connector or import tool, with the unhelpful error "already present in the
 * lookup table".
 *
 * That asymmetry is why this check grades on the SKU-bearing subset only:
 * leftover rows with an empty SKU are inert (nothing looks a product up by
 * blank SKU), while a single row carrying a SKU permanently blocks that SKU.
 *
 * @package BrikPanel
 * @since   3.2.76
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Brikpanel_BrikControl_Product_Lookup_Check extends Brikpanel_BrikControl_Check {

    /**
     * How many blocked SKUs to show in the card's detail table.
     */
    const SAMPLE_LIMIT = 20;

    /**
     * Rows selected per cleanup pass. A multi-table DELETE cannot take a LIMIT,
     * so the fix selects ids first and deletes them single-table.
     */
    const SELECT_CHUNK = 2000;

    /**
     * Hard ceiling per click: 50 x 2000 = 100k rows. Beyond that the fix
     * reports has_more and the merchant clicks again, so one request can never
     * run away.
     */
    const MAX_FIX_ITERATIONS = 50;

    /**
     * Leftover rows (with no SKU) tolerated before the card warns.
     */
    const DEFAULT_WARN_ORPHANS = 500;

    /**
     * @return string
     */
    public function get_id() {
        return 'product_lookup_integrity';
    }

    /**
     * @return string
     */
    public function get_label() {
        return __( 'Product SKU Index', 'brikpanel' );
    }

    /**
     * @return string
     */
    public function get_category() {
        return 'content';
    }

    /**
     * @return int
     */
    public function get_priority() {
        return 20;
    }

    /**
     * Two indexed COUNT queries. Runs inline in the scan worker.
     *
     * @return bool
     */
    public function supports_batching() {
        return false;
    }

    /**
     * @return bool
     */
    public function supports_fix() {
        return true;
    }

    /**
     * @return string
     */
    public function get_fix_label() {
        return __( 'Clean up index', 'brikpanel' );
    }

    /* ---------------------------------------------------------------------
     * Scan
     * ------------------------------------------------------------------ */

    /**
     * @param array $state Unused; this check is not batched.
     * @return array CheckResult
     */
    public function run( array $state = [] ) {
        $started = microtime( true );
        $result  = $this->make_result_skeleton();

        $scan = $this->scan();

        if ( $scan === null ) {
            // WooCommerce's tables are not present (WooCommerce inactive or a
            // partial install). Nothing to assert either way.
            $result['status']      = 'ok';
            $result['score']       = 100;
            $result['summary']     = __( 'WooCommerce product tables were not found.', 'brikpanel' );
            $result['duration_ms'] = (int) round( ( microtime( true ) - $started ) * 1000 );
            return $result;
        }

        $meta_orphans = (int) $scan['meta_orphans'];
        $with_sku     = (int) $scan['with_sku'];
        $attr_orphans = (int) $scan['attr_orphans'];
        $total        = $meta_orphans + $attr_orphans;

        $warn_at = (int) apply_filters(
            'brikpanel_brikcontrol_lookup_warn_orphans',
            self::DEFAULT_WARN_ORPHANS
        );

        if ( $with_sku > 0 ) {
            // No gradient here: one stale SKU blocks that product from ever
            // being created over the REST API again.
            $result['status'] = 'critical';
            $result['score']  = 0;
            $result['summary'] = $this->safe_sprintf(
                /* translators: %s: number of SKUs. */
                _n(
                    '%s SKU is reserved by a product that no longer exists',
                    '%s SKUs are reserved by products that no longer exist',
                    $with_sku,
                    'brikpanel'
                ),
                number_format_i18n( $with_sku )
            );
            $result['message'] = $this->safe_sprintf(
                /* translators: %s: number of leftover index entries. */
                __( 'WooCommerce keeps a fast SKU index alongside your products. %s entries in it point at products that were deleted. The WordPress admin will tell you those SKUs are free, but the REST API refuses to create a product with them, so ERP, import and sync integrations fail with "already present in the lookup table".', 'brikpanel' ),
                number_format_i18n( $with_sku )
            );
        } elseif ( $total >= $warn_at ) {
            $result['status'] = 'warning';
            $result['score']  = 60;
            $result['summary'] = $this->safe_sprintf(
                /* translators: %s: number of leftover index rows. */
                __( '%s leftover index rows from deleted products', 'brikpanel' ),
                number_format_i18n( $total )
            );
            $result['message'] = __( 'None of them hold a SKU, so nothing is blocked today, but the index has drifted far enough from your catalogue that a delete path is leaking rows.', 'brikpanel' );
        } elseif ( $total > 0 ) {
            $result['status'] = 'ok';
            $result['score']  = max( 80, 100 - (int) ceil( $total / 10 ) );
            $result['summary'] = $this->safe_sprintf(
                /* translators: %s: number of leftover index rows. */
                _n(
                    '%s leftover index row, harmless',
                    '%s leftover index rows, harmless',
                    $total,
                    'brikpanel'
                ),
                number_format_i18n( $total )
            );
            $result['message'] = __( 'These rows carry no SKU, so they block nothing and slow nothing down. You can clear them whenever you like.', 'brikpanel' );
        } else {
            $result['status']  = 'ok';
            $result['score']   = 100;
            $result['summary'] = __( 'Every index entry matches a real product.', 'brikpanel' );
        }

        $result['recommendations'] = $this->build_recommendations( $with_sku, $total, $warn_at );

        $result['metadata'] = [
            'fixable' => $total,
            'stats'   => [
                [
                    'label' => __( 'Leftover index rows', 'brikpanel' ),
                    'value' => $meta_orphans,
                    'tone'  => $meta_orphans > 0 ? 'warn' : '',
                ],
                [
                    'label' => __( 'Blocked SKUs', 'brikpanel' ),
                    'value' => $with_sku,
                    'tone'  => $with_sku > 0 ? 'error' : '',
                ],
                [
                    'label' => __( 'Leftover attribute rows', 'brikpanel' ),
                    'value' => $attr_orphans,
                    'tone'  => $attr_orphans > 0 ? 'warn' : '',
                ],
                [
                    'label' => __( 'Indexed products', 'brikpanel' ),
                    'value' => (int) $scan['indexed'],
                    'tone'  => 'good',
                ],
            ],
            'samples'       => $scan['samples'],
            'samples_title' => __( 'Blocked SKUs', 'brikpanel' ),
            'samples_cols'  => [
                __( 'Product ID', 'brikpanel' ),
                __( 'SKU', 'brikpanel' ),
            ],
        ];

        $result['duration_ms'] = (int) round( ( microtime( true ) - $started ) * 1000 );

        return $result;
    }

    /**
     * Count orphaned rows across both lookup tables.
     *
     * @return array|null Null when neither lookup table exists.
     */
    private function scan() {
        global $wpdb;

        $meta_table = $this->table_name( 'wc_product_meta_lookup' );
        $attr_table = $this->table_name( 'wc_product_attributes_lookup' );

        $has_meta = $this->table_exists( $meta_table );
        $has_attr = $this->table_exists( $attr_table );

        if ( ! $has_meta && ! $has_attr ) {
            return null;
        }

        $meta_orphans = 0;
        $with_sku     = 0;
        $indexed      = 0;
        $samples      = [];

        if ( $has_meta ) {
            // One pass answers both figures. Covering index scan on the lookup
            // side, eq_ref "Not exists" probe on posts.
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- table name is internal, no user input.
            // `product_id > 0` keeps the count honest: the repair below builds
            // its delete list with absint(), so a 0 / NULL row could never be
            // removed and would otherwise be reported forever as a problem
            // nobody can fix.
            $row = $wpdb->get_row(
                "SELECT COUNT(*) AS total,
                        SUM( CASE WHEN l.sku IS NOT NULL AND l.sku <> '' THEN 1 ELSE 0 END ) AS with_sku
                 FROM {$meta_table} l
                 LEFT JOIN {$wpdb->posts} p ON p.ID = l.product_id
                 WHERE p.ID IS NULL AND l.product_id > 0"
            );

            $meta_orphans = $row ? (int) $row->total : 0;
            $with_sku     = $row ? (int) $row->with_sku : 0;

            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- table name is internal, no user input.
            $indexed = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$meta_table}" );

            if ( $with_sku > 0 ) {
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- table name is internal; LIMIT is a class constant.
                $rows = $wpdb->get_results(
                    "SELECT l.product_id, l.sku
                     FROM {$meta_table} l
                     LEFT JOIN {$wpdb->posts} p ON p.ID = l.product_id
                     WHERE p.ID IS NULL AND l.product_id > 0 AND l.sku <> ''
                     ORDER BY l.product_id
                     LIMIT " . (int) self::SAMPLE_LIMIT
                );

                foreach ( (array) $rows as $sample ) {
                    $samples[] = [
                        (int) $sample->product_id,
                        (string) $sample->sku,
                    ];
                }
            }
        }

        $attr_orphans = 0;

        // The attribute lookup needs an OR across two columns, which cannot use
        // either index. Filterable so a very large store can opt out.
        if ( $has_attr && apply_filters( 'brikpanel_brikcontrol_lookup_scan_attributes', true ) ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- table name is internal, no user input.
            $attr_orphans = (int) $wpdb->get_var(
                "SELECT COUNT(*)
                 FROM {$attr_table} a
                 LEFT JOIN {$wpdb->posts} p1 ON p1.ID = a.product_id
                 LEFT JOIN {$wpdb->posts} p2 ON p2.ID = a.product_or_parent_id
                 WHERE ( p1.ID IS NULL AND a.product_id > 0 )
                    OR ( p2.ID IS NULL AND a.product_or_parent_id > 0 )"
            );
        }

        return [
            'meta_orphans' => $meta_orphans,
            'with_sku'     => $with_sku,
            'attr_orphans' => $attr_orphans,
            'indexed'      => $indexed,
            'samples'      => $samples,
        ];
    }

    /**
     * @param int $with_sku Blocked SKUs.
     * @param int $total    All orphaned rows.
     * @param int $warn_at  Warning threshold.
     * @return array<int, array>
     */
    private function build_recommendations( $with_sku, $total, $warn_at ) {
        $recs = [];

        if ( $total > 0 ) {
            $recs[] = [
                'text'     => __( 'Use "Clean up index" to remove them. Nothing in your catalogue is touched: only rows whose product no longer exists are deleted.', 'brikpanel' ),
                'priority' => $with_sku > 0 ? 'high' : 'low',
            ];
        }

        if ( $with_sku > 0 ) {
            $recs[] = [
                'text'     => __( 'After cleaning up, ask your integration to send the affected products again. The SKUs will be accepted.', 'brikpanel' ),
                'priority' => 'high',
            ];
            $recs[] = [
                'text'     => __( 'A product sitting in the trash also keeps its SKU reserved. Empty the trash before reusing a SKU.', 'brikpanel' ),
                'priority' => 'medium',
            ];
        } elseif ( $total >= $warn_at ) {
            $recs[] = [
                'text'     => __( 'Rows keep piling up when products are removed by direct database queries. If you use a bulk-delete or cleanup plugin, prefer its standard mode over any "fast" or "direct SQL" option.', 'brikpanel' ),
                'priority' => 'medium',
            ];
        }

        return $recs;
    }

    /* ---------------------------------------------------------------------
     * Fix
     * ------------------------------------------------------------------ */

    /**
     * Delete every lookup row whose product no longer exists.
     *
     * Select-then-delete because a multi-table DELETE cannot take a LIMIT.
     * Every DELETE is single-table and hits the primary key or a dedicated
     * index, so the work is bounded and resumable on a million-row table.
     *
     * @param array $args Unused.
     * @return array { removed: int, has_more: bool, message: string }
     */
    public function run_fix( array $args = [] ) {
        global $wpdb;

        $removed  = 0;
        $has_more = false;

        $meta_table = $this->table_name( 'wc_product_meta_lookup' );
        $attr_table = $this->table_name( 'wc_product_attributes_lookup' );

        if ( $this->table_exists( $meta_table ) ) {
            $outcome  = $this->purge_column( $meta_table, 'product_id' );
            $removed += $outcome['removed'];
            $has_more = $has_more || $outcome['has_more'];
        }

        if ( $this->table_exists( $attr_table ) ) {
            foreach ( [ 'product_id', 'product_or_parent_id' ] as $column ) {
                $outcome  = $this->purge_column( $attr_table, $column );
                $removed += $outcome['removed'];
                $has_more = $has_more || $outcome['has_more'];
            }
        }

        return [
            'removed'  => $removed,
            'has_more' => $has_more,
            'message'  => '',
        ];
    }

    /**
     * Chunked delete of orphaned rows keyed on one column.
     *
     * @param string $table  Fully prefixed table name (internal, never user input).
     * @param string $column `product_id` or `product_or_parent_id`.
     * @return array { removed: int, has_more: bool }
     */
    private function purge_column( $table, $column ) {
        global $wpdb;

        // Whitelist rather than trust the caller: this value is interpolated.
        if ( ! in_array( $column, [ 'product_id', 'product_or_parent_id' ], true ) ) {
            return [ 'removed' => 0, 'has_more' => false ];
        }

        $removed = 0;

        for ( $i = 0; $i < self::MAX_FIX_ITERATIONS; $i++ ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- table/column are internal whitelisted values; LIMIT is a class constant.
            $ids = $wpdb->get_col(
                "SELECT DISTINCT a.{$column}
                 FROM {$table} a
                 LEFT JOIN {$wpdb->posts} p ON p.ID = a.{$column}
                 WHERE p.ID IS NULL AND a.{$column} > 0
                 LIMIT " . (int) self::SELECT_CHUNK
            );

            $ids = array_values( array_filter( array_map( 'absint', (array) $ids ) ) );
            if ( empty( $ids ) ) {
                return [ 'removed' => $removed, 'has_more' => false ];
            }

            $list = implode( ',', $ids );

            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- $list is absint()-mapped integers only.
            $wpdb->query( "DELETE FROM {$table} WHERE {$column} IN ({$list})" );
            $removed += max( 0, (int) $wpdb->rows_affected );
        }

        // Hit the ceiling: there may still be more.
        return [ 'removed' => $removed, 'has_more' => true ];
    }

    /* ---------------------------------------------------------------------
     * Helpers
     * ------------------------------------------------------------------ */

    /**
     * Resolve a lookup table name.
     *
     * WooCommerce registers `wc_product_meta_lookup` on $wpdb, which keeps it
     * correct across switch_to_blog(). It never registers the attribute lookup,
     * so that one falls back to the current prefix.
     *
     * @param string $suffix Table name without prefix.
     * @return string
     */
    private function table_name( $suffix ) {
        global $wpdb;

        return isset( $wpdb->$suffix ) && is_string( $wpdb->$suffix ) && $wpdb->$suffix !== ''
            ? $wpdb->$suffix
            : $wpdb->prefix . $suffix;
    }

    /**
     * Does the table exist? Memoised per request.
     *
     * @param string $table Fully prefixed table name.
     * @return bool
     */
    private function table_exists( $table ) {
        global $wpdb;

        static $cache = [];

        if ( isset( $cache[ $table ] ) ) {
            return $cache[ $table ];
        }

        $cache[ $table ] = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table );

        return $cache[ $table ];
    }

    /**
     * sprintf() that cannot be brought down by a bad translation.
     *
     * A translator who drops or mistypes a placeholder would otherwise throw
     * ArgumentCountError inside the scan worker and abort the whole sweep. Same
     * helper as the image health check, for the same reason.
     *
     * @param string $format Translated format string.
     * @param mixed  ...$args printf arguments.
     * @return string
     */
    private function safe_sprintf( $format, ...$args ) {
        $format = (string) $format;
        try {
            return vsprintf( $format, $args );
        } catch ( \Throwable $e ) {
            $stripped = preg_replace(
                '/%(?:\d+\$)?[-+ 0#\']*\d*(?:\.\d+)?[bcdeEfFgGosuxX]/',
                '',
                $format
            );
            $stripped = str_replace( '%%', '%', (string) $stripped );
            return trim( (string) $stripped );
        }
    }
}
