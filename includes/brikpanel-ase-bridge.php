<?php
/**
 * BrikPanel ↔ Admin and Site Enhancements (ASE) bridge.
 *
 * BrikPanel ships its own AJAX-rendered Products and Coupons lists, so the
 * standard WP_List_Table column hooks (manage_{type}_posts_columns,
 * manage_{type}_posts_custom_column, post_row_actions) never fire. This
 * bridge replays those hooks against a synthetic baseline column set so
 * any plugin that hooks them — ASE in particular — can contribute extra
 * columns and row actions to BrikPanel's lists.
 *
 * @package BrikPanel
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Brikpanel_ASE_Bridge {

    /**
     * Anchor keys that ASE / other plugins look for when inserting columns.
     * `cb`, `thumb`, `title` and `date` are the well-known anchors used by
     * core and WooCommerce list tables.
     */
    private static function baseline_columns( $post_type ) {
        if ( 'product' === $post_type ) {
            return [
                'cb'    => '',
                'thumb' => '',
                'title' => 'Title',
                'date'  => 'Date',
            ];
        }

        return [
            'cb'    => '',
            'title' => 'Title',
            'date'  => 'Date',
        ];
    }

    /**
     * Returns the extra columns contributed by ASE / other plugins to the
     * given post type's list table. Result is an ordered map of
     * `column_id => label` containing only the *added* columns (the
     * baseline anchors are filtered out).
     *
     * Memoised per-request and per post type since the filters are
     * deterministic given current options.
     */
    public static function get_extra_columns( $post_type ) {
        static $cache = [];
        if ( isset( $cache[ $post_type ] ) ) {
            return $cache[ $post_type ];
        }

        $baseline = self::baseline_columns( $post_type );
        $columns  = $baseline;

        // Specific filter (ASE registers featured image / excerpt / last
        // modified per post type here).
        $columns = apply_filters( "manage_{$post_type}_posts_columns", $columns );
        // Generic filter (ASE registers ID column here).
        $columns = apply_filters( 'manage_posts_columns', $columns );

        $extra = [];
        foreach ( $columns as $key => $label ) {
            if ( array_key_exists( $key, $baseline ) ) {
                continue;
            }
            $extra[ $key ] = (string) $label;
        }

        $cache[ $post_type ] = $extra;
        return $extra;
    }

    /**
     * Captures the HTML emitted by `manage_{post_type}_posts_custom_column`
     * (and the generic `manage_posts_custom_column`) for a single column
     * and post id. Returns sanitised HTML safe for direct insertion.
     */
    public static function render_cell( $post_type, $column_id, $post_id ) {
        ob_start();
        // Fire specific action first (this is the order WP core uses).
        do_action( "manage_{$post_type}_posts_custom_column", $column_id, $post_id );
        do_action( 'manage_posts_custom_column', $column_id, $post_id );
        $html = ob_get_clean();

        if ( '' === trim( $html ) ) {
            return '';
        }

        return wp_kses_post( $html );
    }

    /**
     * Returns the row actions contributed by plugins for a given post via
     * the `post_row_actions` filter, as an ordered list of `[id, html]`
     * pairs. Empty list when no plugin contributes anything.
     */
    public static function get_row_actions( $post ) {
        if ( ! is_object( $post ) ) {
            return [];
        }

        $actions = apply_filters( 'post_row_actions', [], $post );
        if ( empty( $actions ) || ! is_array( $actions ) ) {
            return [];
        }

        $out = [];
        foreach ( $actions as $key => $html ) {
            $out[] = [
                'id'   => sanitize_html_class( (string) $key ),
                'html' => wp_kses_post( (string) $html ),
            ];
        }
        return $out;
    }

    /**
     * Captures any extra UI emitted via `restrict_manage_posts` (e.g. ASE's
     * Custom Taxonomy Filters). Caller is responsible for placing the
     * markup inside a form or filter bar.
     */
    public static function render_restrict_manage_posts( $post_type ) {
        // Many handlers introspect $_GET['post_type']; expose it temporarily
        // so they can target the right list. We do not pollute $_REQUEST.
        $previous = isset( $_GET['post_type'] ) ? $_GET['post_type'] : null;
        $_GET['post_type'] = $post_type;

        $previous_typenow = isset( $GLOBALS['typenow'] ) ? $GLOBALS['typenow'] : '';
        $GLOBALS['typenow'] = $post_type;

        ob_start();
        do_action( 'restrict_manage_posts', $post_type, '' );
        $html = ob_get_clean();

        if ( null === $previous ) {
            unset( $_GET['post_type'] );
        } else {
            $_GET['post_type'] = $previous;
        }
        $GLOBALS['typenow'] = $previous_typenow;

        return wp_kses(
            $html,
            wp_kses_allowed_html( 'post' ) + [
                'select' => [ 'name' => true, 'id' => true, 'class' => true, 'multiple' => true ],
                'option' => [ 'value' => true, 'selected' => true, 'class' => true ],
                'input'  => [ 'type' => true, 'name' => true, 'id' => true, 'class' => true, 'value' => true, 'placeholder' => true ],
                'label'  => [ 'for' => true, 'class' => true ],
            ]
        );
    }
}
