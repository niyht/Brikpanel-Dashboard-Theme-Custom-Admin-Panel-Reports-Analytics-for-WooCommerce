<?php
/**
 * BrikPanel: BrikControl check for product costs saved more than once.
 *
 * A product's cost is a single value, but nothing in WordPress stops the meta
 * row that holds it from being written twice: an importer calling
 * add_post_meta(), another plugin, two saves racing each other, and (before
 * 3.3.6) WooCommerce's own "Duplicate" action combined with BrikPanel's cost
 * mirror. Reports used to add every copy up, so a cost of 8.99 on five units
 * showed as 89.90.
 *
 * Reports now read one row per product, the same row WordPress hands to every
 * product screen, so this check is housekeeping rather than a correction: the
 * figures are already right. The extra rows still confuse exports and any
 * other plugin that reads all of them, so the merchant can remove them.
 *
 * The repair keeps the FIRST row of each product (the one in use) and deletes
 * only the later copies, which is why no visible cost can change. Every removed
 * row is kept in a restore point so one click puts it back.
 *
 * @package BrikPanel
 * @since   3.3.6
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Brikpanel_BrikControl_Cost_Duplicates_Check extends Brikpanel_BrikControl_Check {

    /**
     * Duplicate groups (one product + one key) cleaned per click.
     */
    const FIX_GROUPS = 500;

    /**
     * Rows shown in the card's detail table.
     */
    const SAMPLE_LIMIT = 20;

    /**
     * Restore point index option. Read by every scan, so it stays tiny.
     */
    const BACKUP_OPTION = 'brikpanel_cost_dupes_backup';

    /**
     * Prefix for the numbered options that hold the removed rows.
     */
    const BACKUP_CHUNK_PREFIX = 'brikpanel_cost_dupes_backup_';

    /**
     * Rows per chunk option.
     */
    const BACKUP_CHUNK_ROWS = 2000;

    /**
     * Ceiling so a runaway can never fill wp_options. Reaching it stops the
     * repair instead of deleting rows it could not restore.
     */
    const MAX_BACKUP_ROWS = 100000;

    /**
     * A restore point older than this is dropped at the next cleanup, so it
     * can never fill up for good and "undo" never reaches back months.
     */
    const BACKUP_TTL = 30 * DAY_IN_SECONDS;

    /* ---------------------------------------------------------------------
     * Identity
     * ------------------------------------------------------------------ */

    /**
     * @return string
     */
    public function get_id() {
        return 'duplicate_cost_meta';
    }

    /**
     * @return string
     */
    public function get_label() {
        return __( 'Product cost records', 'brikpanel' );
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
        return 26;
    }

    /**
     * One grouped read over the meta_key index.
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
        return __( 'Remove extra copies', 'brikpanel' );
    }

    /**
     * Figures only; the card writes the sentences (see bc_present()).
     *
     * @return int
     */
    public function bc_schema() {
        return 2;
    }

    /* ---------------------------------------------------------------------
     * Scan
     * ------------------------------------------------------------------ */

    /**
     * @param array $state Unused; this check is not batched.
     * @return array CheckResult
     */
    public function run( array $state = [] ) {
        global $wpdb;

        $started = microtime( true );
        $result  = $this->make_result_skeleton();

        $base = $this->groups_sql();

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- built from whitelisted keys and internal table names.
        $totals = $wpdb->get_row(
            "SELECT COUNT(*) AS `groups`,
                    COALESCE(SUM(d.n - 1), 0) AS extra,
                    COUNT(DISTINCT d.post_id) AS products,
                    COALESCE(SUM(CASE WHEN d.nvals > 1 THEN 1 ELSE 0 END), 0) AS differing
               FROM ( {$base} ) d"
        );

        $extra     = (int) ( $totals->extra ?? 0 );
        $products  = (int) ( $totals->products ?? 0 );
        $differing = (int) ( $totals->differing ?? 0 );
        $backup    = $this->read_backup_index();

        if ( $extra < 1 ) {
            $result['status'] = 'ok';
            $result['score']  = 100;
        } else {
            $result['status'] = 'warning';
            $result['score']  = 79;
        }

        $result['facts'] = [
            'extra'     => $extra,
            'products'  => $products,
            'differing' => $differing,
            'undoable'  => $backup['count'],
            'undo_at'   => (int) $backup['time'],
            'samples'   => $extra > 0 ? $this->build_samples( $base ) : [],
        ];

        $result['duration_ms'] = (int) round( ( microtime( true ) - $started ) * 1000 );

        return $result;
    }

    /**
     * Sentences for the stored figures, in the viewer's language.
     *
     * @param array  $r       Stored result.
     * @param string $context 'page' or 'summary'.
     * @return array
     */
    public function bc_present( array $r, $context = 'page' ) {
        $r = parent::bc_present( $r, $context );

        $f = isset( $r['facts'] ) && is_array( $r['facts'] ) ? $r['facts'] : [];
        if ( empty( $f ) || (int) ( $r['schema'] ?? 1 ) < 2 ) {
            return $r; // Written before 3.3.25: show what was stored.
        }

        $extra     = (int) ( $f['extra'] ?? 0 );
        $products  = (int) ( $f['products'] ?? 0 );
        $differing = (int) ( $f['differing'] ?? 0 );
        $undoable  = (int) ( $f['undoable'] ?? 0 );

        $r['recommendations'] = [];
        $r['metadata']        = [];

        if ( $extra < 1 ) {
            $r['summary'] = __( 'Every product cost is saved once.', 'brikpanel' );
            $r['message'] = __( 'No product or variation has a copy of its cost stored more than once.', 'brikpanel' );
        } else {
            $r['summary'] = brikpanel_safe_sprintf(
                /* translators: %s: number of products and variations. */
                _n(
                    '%s product has its cost saved more than once',
                    '%s products have their cost saved more than once',
                    $products,
                    'brikpanel'
                ),
                brikpanel_number( $products )
            );
            $r['message'] = __( 'Your reports already count each cost once, so your profit figures are correct. The extra copies can still confuse exports and other plugins. Removing them keeps the copy your product pages show today, so no cost changes.', 'brikpanel' );

            if ( $differing > 0 ) {
                $r['message'] .= ' ' . brikpanel_safe_sprintf(
                    /* translators: %s: number of cost fields. */
                    _n(
                        '%s of these holds different numbers in its copies; the first saved number is the one in use and the one that stays.',
                        '%s of these hold different numbers in their copies; the first saved number is the one in use and the one that stays.',
                        $differing,
                        'brikpanel'
                    ),
                    brikpanel_number( $differing )
                );
            }
        }

        if ( 'page' !== $context ) {
            return $r;
        }

        if ( $extra > 0 ) {
            $r['recommendations'] = [
                [
                    'text'     => __( 'Open the list below to see which products are affected. Products are often copied this way by an import or by duplicating a product.', 'brikpanel' ),
                    'priority' => 'medium',
                ],
            ];
        }

        $r['metadata'] = [
            'fixable'       => $extra,
            'undoable'      => $undoable,
            'undo_at'       => (int) ( $f['undo_at'] ?? 0 ),
            'fix_confirm'   => brikpanel_safe_sprintf(
                /* translators: %s: number of extra cost copies. */
                _n(
                    'Remove %s extra cost copy? The cost each product shows today does not change, and the removed copy is kept so you can undo this.',
                    'Remove %s extra cost copies? The cost each product shows today does not change, and the removed copies are kept so you can undo this.',
                    $extra,
                    'brikpanel'
                ),
                brikpanel_number( $extra )
            ),
            'undo_confirm'  => brikpanel_safe_sprintf(
                /* translators: %s: number of rows to put back. */
                _n(
                    'Put the removed cost copies back? This restores %s row exactly as it was.',
                    'Put the removed cost copies back? This restores %s rows exactly as they were.',
                    $undoable,
                    'brikpanel'
                ),
                brikpanel_number( $undoable )
            ),
            'stats'         => [
                [
                    'label' => __( 'Products affected', 'brikpanel' ),
                    'value' => $products,
                    'tone'  => $products > 0 ? 'warn' : 'good',
                ],
                [
                    'label' => __( 'Extra copies', 'brikpanel' ),
                    'value' => $extra,
                    'tone'  => $extra > 0 ? 'warn' : '',
                ],
                [
                    'label' => __( 'Copies with different numbers', 'brikpanel' ),
                    'value' => $differing,
                    'tone'  => $differing > 0 ? 'warn' : '',
                ],
            ],
            'samples'       => $this->present_samples( (array) ( $f['samples'] ?? [] ) ),
            'samples_title' => __( 'Affected products', 'brikpanel' ),
            'samples_cols'  => [
                __( 'Product', 'brikpanel' ),
                __( 'Field', 'brikpanel' ),
                __( 'Copies', 'brikpanel' ),
                __( 'Saved numbers (first one stays)', 'brikpanel' ),
            ],
        ];

        return $r;
    }

    /**
     * @param array $outcome run_fix() result.
     * @return string
     */
    public function bc_fix_done( array $outcome ) {
        $removed = (int) ( $outcome['removed'] ?? 0 );
        return brikpanel_safe_sprintf(
            /* translators: %s: number of extra cost copies removed. */
            _n( '%s extra cost copy removed.', '%s extra cost copies removed.', $removed, 'brikpanel' ),
            brikpanel_number( $removed )
        );
    }

    /**
     * @param array $outcome run_undo() result.
     * @return string
     */
    public function bc_undo_done( array $outcome ) {
        $restored = (int) ( $outcome['restored'] ?? 0 );
        return brikpanel_safe_sprintf(
            /* translators: %s: number of cost copies put back. */
            _n( '%s cost copy put back.', '%s cost copies put back.', $restored, 'brikpanel' ),
            brikpanel_number( $restored )
        );
    }

    /**
     * Meta keys this check looks after: the two cost keys BrikPanel writes
     * (WooCommerce native + its own) and WooCommerce's additive flag, which
     * feeds the same cost calculation.
     *
     * Deliberately NOT the keys of a detected third-party cost plugin or a key
     * added through the `brikpanel_cogs_meta_keys` filter: those belong to
     * another plugin, which may keep several rows on purpose, and BrikPanel
     * treats them as read-only. Reports already count them once. Never
     * `_price` either: WooCommerce stores one row per variation price.
     *
     * @return string[]
     */
    private function keys() {
        $keys   = function_exists( 'brikpanel_cogs_owned_meta_keys' ) ? brikpanel_cogs_owned_meta_keys() : [ '_cogs_total_value', '_brikpanel_cogs' ];
        $keys[] = '_cogs_value_is_additive';

        return array_values( array_unique( array_filter( $keys, static function ( $key ) {
            return is_string( $key ) && preg_match( '/^[A-Za-z0-9_\-]{1,255}$/', $key ) && '_price' !== $key;
        } ) ) );
    }

    /**
     * SELECT listing every product/key pair stored more than once.
     *
     * @return string
     */
    private function groups_sql() {
        global $wpdb;

        $in = "'" . implode( "','", array_map( 'esc_sql', $this->keys() ) ) . "'";

        // "Different numbers" compares numbers, not text: 4 and 4.00 are the
        // same cost and must not be reported as a conflict.
        return "SELECT pm.post_id, pm.meta_key, COUNT(*) AS n,
                       COUNT(DISTINCT CASE WHEN pm.meta_value REGEXP '^-?[0-9]*[.]?[0-9]+$'
                                           THEN CAST(CAST(pm.meta_value AS DECIMAL(20,4)) AS CHAR)
                                           ELSE pm.meta_value END) AS nvals
                  FROM {$wpdb->postmeta} pm
                  INNER JOIN {$wpdb->posts} p
                          ON p.ID = pm.post_id
                         AND p.post_type IN ('product', 'product_variation')
                 WHERE pm.meta_key IN ({$in})
                 GROUP BY pm.post_id, pm.meta_key
                HAVING COUNT(*) > 1";
    }

    /**
     * Detail rows, groups with conflicting numbers first: product id, meta
     * key, number of rows and the raw saved values, oldest first. Names and
     * labels are added when the card is drawn (present_samples()).
     *
     * @param string $base Output of groups_sql().
     * @return array
     */
    private function build_samples( $base ) {
        global $wpdb;

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- built from whitelisted keys; the limit is a class constant.
        $groups = $wpdb->get_results(
            "SELECT d.post_id, d.meta_key, d.n
               FROM ( {$base} ) d
              ORDER BY ( d.nvals > 1 ) DESC, d.n DESC, d.post_id ASC
              LIMIT " . (int) self::SAMPLE_LIMIT
        );

        if ( empty( $groups ) ) {
            return [];
        }

        $ids  = array_values( array_unique( array_map( static function ( $g ) { return (int) $g->post_id; }, $groups ) ) );
        $keys = array_values( array_unique( array_map( static function ( $g ) { return (string) $g->meta_key; }, $groups ) ) );

        $id_list  = implode( ',', array_map( 'absint', $ids ) );
        $key_list = "'" . implode( "','", array_map( 'esc_sql', $keys ) ) . "'";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- absint ids and esc_sql'd whitelisted keys.
        $rows = $wpdb->get_results(
            "SELECT post_id, meta_key, meta_value
               FROM {$wpdb->postmeta}
              WHERE post_id IN ({$id_list}) AND meta_key IN ({$key_list})
              ORDER BY meta_id ASC"
        );

        $values = [];
        foreach ( (array) $rows as $row ) {
            $values[ (int) $row->post_id ][ (string) $row->meta_key ][] = (string) $row->meta_value;
        }

        $samples = [];
        foreach ( $groups as $group ) {
            $pid = (int) $group->post_id;
            $key = (string) $group->meta_key;

            $samples[] = [
                'post_id' => $pid,
                'key'     => $key,
                'n'       => (int) $group->n,
                'values'  => $values[ $pid ][ $key ] ?? [],
            ];
        }

        return $samples;
    }

    /**
     * Stored sample rows as table cells, in the viewer's language.
     *
     * @param array $rows build_samples() output.
     * @return array
     */
    private function present_samples( array $rows ) {
        $ids = [];
        foreach ( $rows as $row ) {
            if ( is_array( $row ) && ! empty( $row['post_id'] ) ) {
                $ids[] = (int) $row['post_id'];
            }
        }
        if ( ! empty( $ids ) ) {
            _prime_post_caches( array_values( array_unique( $ids ) ), false, false );
        }

        $out = [];
        foreach ( $rows as $row ) {
            if ( ! is_array( $row ) || empty( $row['post_id'] ) ) {
                continue;
            }
            $values = [];
            foreach ( (array) ( $row['values'] ?? [] ) as $value ) {
                $values[] = '' === (string) $value ? __( '(empty)', 'brikpanel' ) : (string) $value;
            }
            $out[] = [
                $this->product_label( (int) $row['post_id'] ),
                $this->field_label( (string) ( $row['key'] ?? '' ) ),
                brikpanel_number( (int) ( $row['n'] ?? 0 ) ),
                implode( ' / ', $values ),
            ];
        }

        return $out;
    }

    /**
     * @param string $key Meta key.
     * @return string
     */
    private function field_label( $key ) {
        switch ( $key ) {
            case '_cogs_total_value':
                return __( 'Cost of goods (WooCommerce)', 'brikpanel' );
            case '_brikpanel_cogs':
                return __( 'Cost of goods (BrikPanel)', 'brikpanel' );
            case '_cogs_value_is_additive':
                return __( 'Adds to parent cost', 'brikpanel' );
            default:
                return brikpanel_safe_sprintf(
                    /* translators: %s: meta key used by another cost plugin. */
                    __( 'Cost of goods (%s)', 'brikpanel' ),
                    $key
                );
        }
    }

    /**
     * @param int $post_id Product or variation ID.
     * @return string
     */
    private function product_label( $post_id ) {
        $title = get_the_title( (int) $post_id );

        if ( is_string( $title ) && '' !== trim( $title ) ) {
            // get_the_title() is texturized (a variation's dash arrives as
            // &#8211;); the card escapes on output, so hand it plain text.
            return brikpanel_plain_label( $title );
        }

        return brikpanel_safe_sprintf(
            /* translators: %s: product ID. */
            __( 'Product #%s', 'brikpanel' ),
            (string) (int) $post_id
        );
    }

    /* ---------------------------------------------------------------------
     * Fix
     * ------------------------------------------------------------------ */

    /**
     * Delete the later copies, keeping the first row of every product/key.
     *
     * Re-reads the data rather than trusting the stored card. Deletes by raw
     * query on purpose: delete_metadata_by_mid() fires deleted_post_meta with
     * the REMOVED row's value, and BrikPanel's cost mirror would copy that value
     * (or its emptiness) onto the product's other cost key.
     *
     * @param array $args Unused.
     * @return array { removed: int, has_more: bool, message: string }
     */
    public function run_fix( array $args = [] ) {
        global $wpdb;

        // Fix and undo use separate locks in the framework on purpose, but for
        // this check they must not overlap: an undo deleting the restore point
        // while a cleanup appends to it would lose the new rows for good.
        if ( get_transient( 'brikpanel_bc_undo_' . $this->get_id() ) ) {
            return [
                'removed'  => 0,
                'has_more' => true,
                'message'  => __( 'An undo is running for this check. Try again in a moment.', 'brikpanel' ),
            ];
        }

        $this->expire_backup();

        $base = $this->groups_sql();

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- built from whitelisted keys; the limit is a class constant.
        $groups = $wpdb->get_results(
            "SELECT d.post_id, d.meta_key FROM ( {$base} ) d ORDER BY d.post_id ASC LIMIT " . (int) ( self::FIX_GROUPS + 1 )
        );

        if ( empty( $groups ) ) {
            return [ 'removed' => 0, 'has_more' => false, 'message' => '' ];
        }

        $has_more = count( $groups ) > self::FIX_GROUPS;
        $groups   = array_slice( $groups, 0, self::FIX_GROUPS );

        $wanted = [];
        foreach ( $groups as $group ) {
            $wanted[ (int) $group->post_id ][ (string) $group->meta_key ] = true;
        }

        $id_list  = implode( ',', array_map( 'absint', array_keys( $wanted ) ) );
        $key_list = "'" . implode( "','", array_map( 'esc_sql', $this->keys() ) ) . "'";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- absint ids and esc_sql'd whitelisted keys.
        $rows = $wpdb->get_results(
            "SELECT meta_id, post_id, meta_key, meta_value
               FROM {$wpdb->postmeta}
              WHERE post_id IN ({$id_list}) AND meta_key IN ({$key_list})
              ORDER BY post_id ASC, meta_key ASC, meta_id ASC"
        );

        $first      = [];
        $candidates = [];
        foreach ( (array) $rows as $row ) {
            $pid = (int) $row->post_id;
            $key = (string) $row->meta_key;
            if ( empty( $wanted[ $pid ][ $key ] ) ) {
                continue;
            }
            if ( ! isset( $first[ $pid ][ $key ] ) ) {
                // The first row is the one in use. It is never a candidate.
                $first[ $pid ][ $key ] = [ (int) $row->meta_id, (string) $row->meta_value ];
                continue;
            }
            $candidates[] = [
                'id' => (int) $row->meta_id,
                'p'  => $pid,
                'k'  => $key,
                'v'  => (string) $row->meta_value,
                // The row this was a copy of, as it was at cleanup time. Undo
                // only puts the copy back while that row is still unchanged.
                'f'  => $first[ $pid ][ $key ][0],
                'fv' => $first[ $pid ][ $key ][1],
            ];
        }

        if ( empty( $candidates ) ) {
            return [ 'removed' => 0, 'has_more' => $has_more, 'message' => '' ];
        }

        // Never delete a row we could not put back.
        $index = $this->read_backup_index();
        $room  = self::MAX_BACKUP_ROWS - $index['count'];
        $full  = false;
        if ( count( $candidates ) > $room ) {
            $candidates = array_slice( $candidates, 0, max( 0, $room ) );
            $has_more   = true;
            $full       = true;
        }
        if ( empty( $candidates ) ) {
            return [
                'removed'  => 0,
                'has_more' => true,
                'message'  => __( 'The restore point is full. Undo the last correction or leave it in place, then run the check again.', 'brikpanel' ),
            ];
        }

        // Backup first: a request that dies between the two steps leaves a
        // restore point for rows that still exist, which undo skips harmlessly.
        $this->append_backup( $candidates );

        $removed = 0;
        foreach ( array_chunk( $candidates, 500 ) as $chunk ) {
            $ids = implode( ',', array_map( static function ( $c ) { return (int) $c['id']; }, $chunk ) );

            // The self-join makes it impossible to remove the first row of a
            // product even if another request changed the data meanwhile: a
            // row is only deleted while an older row of the same key exists.
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- integer id list.
            $deleted = $wpdb->query(
                "DELETE d FROM {$wpdb->postmeta} d
                  INNER JOIN {$wpdb->postmeta} k
                          ON k.post_id = d.post_id
                         AND k.meta_key = d.meta_key
                         AND k.meta_id < d.meta_id
                  WHERE d.meta_id IN ({$ids})"
            );
            $removed += max( 0, (int) $deleted );
        }

        $this->flush_post_caches( array_keys( $wanted ) );

        return [
            'removed'  => $removed,
            'has_more' => $has_more,
            'message'  => $full
                ? __( 'The restore point is full. Undo the last correction or leave it in place, then run the check again.', 'brikpanel' )
                : '',
        ];
    }

    /* ---------------------------------------------------------------------
     * Undo
     * ------------------------------------------------------------------ */

    /**
     * Put every removed copy back with its original meta_id, so row order (and
     * therefore which copy is "first") is exactly what it was.
     *
     * A copy only goes back while the row it was a copy OF is still the first
     * row and still holds the same value. If the merchant changed or cleared
     * that cost after the cleanup, putting the old copy back would either make
     * it the first row again (silently restoring the old cost) or leave a
     * stale conflicting copy behind the new one.
     *
     * @return array { restored: int, message: string }
     */
    public function run_undo() {
        global $wpdb;

        if ( get_transient( 'brikpanel_bc_fix_' . $this->get_id() ) ) {
            return [ 'restored' => 0, 'message' => __( 'A cleanup is running for this check. Try again in a moment.', 'brikpanel' ) ];
        }

        $index = $this->read_backup_index();

        if ( $index['count'] < 1 ) {
            return [ 'restored' => 0, 'message' => __( 'There is nothing to undo.', 'brikpanel' ) ];
        }

        $restored = 0;
        $skipped  = 0;
        $touched  = [];
        $allowed  = $this->keys();

        // Newest chunk first, so an interrupted run has undone the latest work.
        for ( $i = $index['chunks'] - 1; $i >= 0; $i-- ) {
            $rows   = (array) get_option( self::BACKUP_CHUNK_PREFIX . $i, [] );
            $parsed = [];

            foreach ( $rows as $row ) {
                $id  = isset( $row['id'] ) ? (int) $row['id'] : 0;
                $pid = isset( $row['p'] ) ? (int) $row['p'] : 0;
                $key = isset( $row['k'] ) ? (string) $row['k'] : '';

                if ( $id <= 0 || $pid <= 0 || ! in_array( $key, $allowed, true ) ) {
                    continue;
                }
                $parsed[] = [
                    $id,
                    $pid,
                    $key,
                    isset( $row['v'] ) ? (string) $row['v'] : '',
                    isset( $row['f'] ) ? (int) $row['f'] : 0,
                    isset( $row['fv'] ) ? (string) $row['fv'] : null,
                ];
            }

            foreach ( array_chunk( $parsed, 200 ) as $part ) {
                $ids = array_values( array_unique( array_map( static function ( $r ) { return (int) $r[1]; }, $part ) ) );
                $in  = implode( ',', array_map( 'absint', $ids ) );

                // One query per batch instead of get_post_type() per row: a
                // product deleted since the cleanup gets nothing back.
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- absint id list.
                $live = array_flip( array_map( 'intval', (array) $wpdb->get_col(
                    "SELECT ID FROM {$wpdb->posts} WHERE ID IN ({$in}) AND post_type IN ('product', 'product_variation')"
                ) ) );

                // The first row of each product/key as it stands right now.
                $key_in = "'" . implode( "','", array_map( 'esc_sql', $allowed ) ) . "'";
                $guard  = brikpanel_sql_first_meta_guard( 'post', 'm' );
                $firsts = [];
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- absint ids, esc_sql'd whitelisted keys, internal guard.
                foreach ( (array) $wpdb->get_results(
                    "SELECT m.post_id, m.meta_key, m.meta_id, m.meta_value FROM {$wpdb->postmeta} m
                      WHERE m.post_id IN ({$in}) AND m.meta_key IN ({$key_in}) AND {$guard}"
                ) as $f ) {
                    $firsts[ (int) $f->post_id ][ (string) $f->meta_key ] = [ (int) $f->meta_id, (string) $f->meta_value ];
                }

                $chunk = [];
                foreach ( $part as $r ) {
                    $now = $firsts[ $r[1] ][ $r[2] ] ?? null;
                    $ok  = isset( $live[ $r[1] ] ) && null !== $now && $now[0] < $r[0];
                    // Backups written with the keeper recorded must still find
                    // that exact row with that exact value.
                    if ( $ok && $r[4] > 0 ) {
                        $ok = ( $now[0] === $r[4] ) && ( $now[1] === $r[5] );
                    }
                    if ( ! $ok ) {
                        $skipped++;
                        continue;
                    }
                    $chunk[] = $r;
                }
                if ( empty( $chunk ) ) {
                    continue;
                }

                $holders = [];
                $params  = [];
                foreach ( $chunk as $r ) {
                    $holders[] = '(%d, %d, %s, %s)';
                    array_push( $params, (int) $r[0], (int) $r[1], $r[2], $r[3] );
                    $touched[ $r[1] ] = true;
                }
                // IGNORE: a row that was never actually deleted is left alone.
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- placeholders only.
                $inserted  = $wpdb->query( $wpdb->prepare(
                    "INSERT IGNORE INTO {$wpdb->postmeta} (meta_id, post_id, meta_key, meta_value) VALUES " . implode( ', ', $holders ),
                    $params
                ) );
                $restored += max( 0, (int) $inserted );
            }

            delete_option( self::BACKUP_CHUNK_PREFIX . $i );

            $index['count'] = max( 0, $index['count'] - count( $rows ) );
            update_option(
                self::BACKUP_OPTION,
                [
                    'time'   => $index['time'],
                    'count'  => $index['count'],
                    'chunks' => $i,
                ],
                false
            );
        }

        delete_option( self::BACKUP_OPTION );

        $this->flush_post_caches( array_keys( $touched ) );

        $message = '';
        if ( $skipped > 0 ) {
            $message = brikpanel_safe_sprintf(
                /* translators: %s: number of cost copies that were not restored. */
                _n(
                    '%s copy was not put back because that cost was changed or cleared after the cleanup.',
                    '%s copies were not put back because those costs were changed or cleared after the cleanup.',
                    $skipped,
                    'brikpanel'
                ),
                brikpanel_number( $skipped )
            );
        }

        return [ 'restored' => $restored, 'message' => $message ];
    }

    /**
     * Drop a restore point that has outlived BACKUP_TTL.
     *
     * @return void
     */
    private function expire_backup() {
        $index = $this->read_backup_index();

        if ( $index['count'] > 0 && $index['time'] > 0 && ( time() - $index['time'] ) > self::BACKUP_TTL ) {
            $this->purge_backup_chunks( $index['chunks'] );
            delete_option( self::BACKUP_OPTION );
        }
    }

    /* ---------------------------------------------------------------------
     * Helpers
     * ------------------------------------------------------------------ */

    /**
     * Drop every cache that could still hold the old rows, then refresh reports.
     *
     * @param int[] $post_ids Products and variations whose meta changed.
     * @return void
     */
    private function flush_post_caches( array $post_ids ) {
        foreach ( $post_ids as $post_id ) {
            wp_cache_delete( (int) $post_id, 'post_meta' );
            if ( class_exists( 'WC_Cache_Helper' ) ) {
                WC_Cache_Helper::invalidate_cache_group( 'object_' . (int) $post_id );
            }
        }

        if ( function_exists( 'brikpanel_bust_data_caches' ) ) {
            brikpanel_bust_data_caches();
        }
    }

    /**
     * The restore point's index.
     *
     * @return array{time:int,count:int,chunks:int}
     */
    private function read_backup_index() {
        $stored = get_option( self::BACKUP_OPTION, [] );

        if ( ! is_array( $stored ) ) {
            return [ 'time' => 0, 'count' => 0, 'chunks' => 0 ];
        }

        return [
            'time'   => isset( $stored['time'] ) ? (int) $stored['time'] : 0,
            'count'  => isset( $stored['count'] ) ? max( 0, (int) $stored['count'] ) : 0,
            'chunks' => isset( $stored['chunks'] ) ? max( 0, (int) $stored['chunks'] ) : 0,
        ];
    }

    /**
     * Add rows to the restore point, spilling into a new chunk when full.
     *
     * @param array $rows Backup entries.
     * @return void
     */
    private function append_backup( array $rows ) {
        $index = $this->read_backup_index();

        // Starting fresh: sweep chunks an interrupted undo may have left, so two
        // cleanups are never mixed into one undo.
        if ( $index['count'] < 1 ) {
            $this->purge_backup_chunks( $index['chunks'] );
            $index['chunks'] = 0;
        }

        $chunk_index = max( 0, $index['chunks'] - 1 );
        $current     = ( $index['chunks'] > 0 )
            ? (array) get_option( self::BACKUP_CHUNK_PREFIX . $chunk_index, [] )
            : [];

        foreach ( $rows as $row ) {
            if ( count( $current ) >= self::BACKUP_CHUNK_ROWS ) {
                // Never autoloaded: read only when the merchant undoes.
                update_option( self::BACKUP_CHUNK_PREFIX . $chunk_index, $current, false );
                $chunk_index++;
                $current = [];
            }
            $current[] = $row;
        }

        update_option( self::BACKUP_CHUNK_PREFIX . $chunk_index, $current, false );

        update_option(
            self::BACKUP_OPTION,
            [
                'time'   => time(),
                'count'  => $index['count'] + count( $rows ),
                'chunks' => $chunk_index + 1,
            ],
            false
        );
    }

    /**
     * Delete leftover restore-point chunks.
     *
     * @param int $known How many chunks the index claimed.
     * @return void
     */
    private function purge_backup_chunks( $known ) {
        global $wpdb;

        for ( $i = 0; $i < max( 0, (int) $known ); $i++ ) {
            delete_option( self::BACKUP_CHUNK_PREFIX . $i );
        }

        $stale = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
                $wpdb->esc_like( self::BACKUP_CHUNK_PREFIX ) . '%'
            )
        );

        foreach ( (array) $stale as $name ) {
            delete_option( $name );
        }
    }
}
