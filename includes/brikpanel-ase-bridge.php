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
     * baseline anchors are filtered out). Labels are returned as plain
     * text (HTML stripped) so they render safely in the Columns dropdown
     * and the table header without relying on the originating plugin's
     * CSS.
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

        // Mirror WP_Posts_List_Table::get_columns(): taxonomy columns
        // registered with `show_admin_column => true` are injected
        // BEFORE the `manage_{post_type}_posts_columns` filter fires.
        // Plugins like WooCommerce Brands rely on this — their filter
        // callback only reorders `taxonomy-product_brand`, it never
        // creates it. Replaying the filter against a bare baseline
        // would leave that callback reordering a NULL value, producing
        // an empty-label "Brands" column with empty cells.
        $taxonomy_columns = self::taxonomy_columns_for( $post_type );
        foreach ( $taxonomy_columns as $col_key => $col_label ) {
            $columns[ $col_key ] = $col_label;
        }

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
            // Some plugins (Yoast) put icon markup in their label. Strip
            // tags so the visible label is a clean string; screen-reader
            // text inside the markup is preserved verbatim, which is the
            // human-readable column name.
            $plain = wp_strip_all_tags( (string) $label );
            $plain = trim( html_entity_decode( $plain, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );

            // Fall back to the taxonomy's localised label if a filter
            // callback wiped it (e.g. WC brands when it can't find the
            // pre-existing column key — defence-in-depth alongside the
            // baseline injection above).
            if ( '' === $plain && isset( $taxonomy_columns[ $key ] ) ) {
                $plain = $taxonomy_columns[ $key ];
            }

            $extra[ $key ] = $plain;
        }

        $cache[ $post_type ] = $extra;
        return $extra;
    }

    /**
     * Returns the taxonomy columns WP core would inject for the given
     * post type — i.e. taxonomies attached to it with
     * `show_admin_column => true`. Keyed by the column id WP would use
     * (`categories`, `tags`, or `taxonomy-{slug}`); values are the
     * taxonomy's plural label.
     */
    private static function taxonomy_columns_for( $post_type ) {
        $taxonomies = get_object_taxonomies( $post_type, 'objects' );
        $taxonomies = wp_filter_object_list( $taxonomies, [ 'show_admin_column' => true ], 'and', 'name' );

        /** This filter is documented in wp-admin/includes/class-wp-posts-list-table.php */
        $taxonomies = apply_filters( "manage_taxonomies_for_{$post_type}_columns", $taxonomies, $post_type );
        $taxonomies = array_filter( $taxonomies, 'taxonomy_exists' );

        $out = [];
        foreach ( $taxonomies as $taxonomy ) {
            if ( 'category' === $taxonomy ) {
                $column_key = 'categories';
            } elseif ( 'post_tag' === $taxonomy ) {
                $column_key = 'tags';
            } else {
                $column_key = 'taxonomy-' . $taxonomy;
            }
            $tax_object = get_taxonomy( $taxonomy );
            $out[ $column_key ] = $tax_object && isset( $tax_object->labels->name )
                ? (string) $tax_object->labels->name
                : $taxonomy;
        }
        return $out;
    }

    /**
     * Captures the HTML emitted by `manage_{post_type}_posts_custom_column`
     * (and the generic `manage_posts_custom_column`) for a single column
     * and post id. Returns sanitised HTML safe for direct insertion.
     *
     * Taxonomy columns (`categories`, `tags`, `taxonomy-{slug}`) are an
     * exception: WP_Posts_List_Table::column_default() renders them
     * inline, without firing the custom-column action. We mirror that
     * path so taxonomies registered with `show_admin_column => true`
     * (e.g. WooCommerce Brands) display term labels rather than blanks.
     */
    public static function render_cell( $post_type, $column_id, $post_id ) {
        $taxonomy = self::taxonomy_for_column_id( $column_id );
        if ( $taxonomy && taxonomy_exists( $taxonomy ) ) {
            return self::render_taxonomy_cell( $taxonomy, $post_id );
        }

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
     * Maps a list-table column id to the taxonomy slug it represents,
     * or false if the column is not a taxonomy column.
     */
    private static function taxonomy_for_column_id( $column_id ) {
        if ( 'categories' === $column_id ) {
            return 'category';
        }
        if ( 'tags' === $column_id ) {
            return 'post_tag';
        }
        if ( 0 === strpos( $column_id, 'taxonomy-' ) ) {
            return substr( $column_id, 9 );
        }
        return false;
    }

    /**
     * Renders the cell content for a taxonomy column — a comma-separated
     * (locale-aware) list of term names. Mirrors the behaviour of
     * WP_Posts_List_Table::column_default() for taxonomy columns, minus
     * the per-term edit links (BrikPanel does not expose the edit.php
     * filtered view those links would target).
     */
    private static function render_taxonomy_cell( $taxonomy, $post_id ) {
        $terms = get_the_terms( $post_id, $taxonomy );

        if ( empty( $terms ) || is_wp_error( $terms ) ) {
            $tax_object = get_taxonomy( $taxonomy );
            $no_terms   = ( $tax_object && isset( $tax_object->labels->no_terms ) )
                ? (string) $tax_object->labels->no_terms
                : '';
            if ( '' === $no_terms ) {
                return '';
            }
            return '<span aria-hidden="true">' . esc_html( $no_terms ) . '</span>';
        }

        $labels = [];
        foreach ( $terms as $term ) {
            $labels[] = esc_html( sanitize_term_field( 'name', $term->name, $term->term_id, $taxonomy, 'display' ) );
        }

        /** This filter is documented in wp-admin/includes/class-wp-posts-list-table.php */
        $labels = apply_filters( 'post_column_taxonomy_links', $labels, $taxonomy, $terms );

        $separator = function_exists( 'wp_get_list_item_separator' ) ? wp_get_list_item_separator() : ', ';
        return implode( $separator, $labels );
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
