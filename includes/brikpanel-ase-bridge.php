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
        //
        // Third-party column callbacks run here on a screen they were not
        // written for (the BrikPanel list / admin-ajax, not edit.php). A
        // callback that assumes the native edit-screen baseline — or whose
        // plugin throws on a non-edit screen (e.g. SmartCrawl Pro's analysis
        // module) — would otherwise bubble a fatal up through the products
        // list AJAX and surface to the merchant as a blank "couldn't load"
        // grid. Isolate the filter pass so a single faulty plugin degrades to
        // "no extra columns" instead of taking the whole screen down. The
        // result is still memoised below, so we never re-run a callback that
        // already blew up this request.
        try {
            $filtered = apply_filters( "manage_{$post_type}_posts_columns", $columns );
            if ( is_array( $filtered ) ) {
                $columns = $filtered;
            }
            // Generic filter (ASE registers ID column here).
            $filtered = apply_filters( 'manage_posts_columns', $columns );
            if ( is_array( $filtered ) ) {
                $columns = $filtered;
            }
        } catch ( \Throwable $e ) {
            // Fall back to the baseline + taxonomy columns gathered so far.
        }

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
     * Saved globals for each open render pass. A stack (LIFO) rather than a
     * single slot purely as defence against nesting — the callers below never
     * nest today.
     */
    private static $loop_stack = [];

    /**
     * Publishes a locally-built WP_Query as the global loop for the duration
     * of a third-party cell-render pass, then restores every global verbatim.
     *
     * WP_Posts_List_Table renders inside the global loop, so column callbacks
     * are written against it. Rank Math's Post_Columns::get_post_ids() is the
     * canonical example: it reads `global $wp_query`->posts to bulk-prefetch
     * every visible row's meta in ONE query. BrikPanel's lists build a LOCAL
     * WP_Query, so that prefetch used to see an empty loop and bail out before
     * it ever queried — every SEO cell rendered "N/A" with "Keyword: Not Set",
     * whatever the product actually had on file.
     *
     * `$wp_the_query` is deliberately NOT aliased: that would make
     * is_main_query() true for a synthetic admin query, which is exactly the
     * signal front-end plugins use to rewrite queries and issue canonical
     * redirects. Read-only column callbacks never need it.
     *
     * @param WP_Query $query The list's own query.
     * @return bool True when the context was opened (caller must close it).
     */
    public static function begin_loop_context( $query ) {
        if ( ! $query instanceof WP_Query ) {
            return false;
        }
        self::$loop_stack[] = [
            'wp_query' => [ array_key_exists( 'wp_query', $GLOBALS ), isset( $GLOBALS['wp_query'] ) ? $GLOBALS['wp_query'] : null ],
            'posts'    => [ array_key_exists( 'posts', $GLOBALS ),    isset( $GLOBALS['posts'] ) ? $GLOBALS['posts'] : null ],
            'post'     => [ array_key_exists( 'post', $GLOBALS ),     isset( $GLOBALS['post'] ) ? $GLOBALS['post'] : null ],
            'per_page' => [ array_key_exists( 'per_page', $GLOBALS ), isset( $GLOBALS['per_page'] ) ? $GLOBALS['per_page'] : null ],
        ];
        $GLOBALS['wp_query'] = $query;
        // Some callbacks read `global $posts` instead of the query object, and
        // Rank Math reads `global $per_page` on its hierarchical-post branch.
        $GLOBALS['posts']    = $query->posts;
        $GLOBALS['per_page'] = (int) $query->get( 'posts_per_page' );
        return true;
    }

    /**
     * Publishes the row currently being rendered, mirroring what
     * WP_Posts_List_Table::single_row() does before firing the column action.
     * No-op unless a loop context is open.
     */
    public static function set_loop_post( $post ) {
        if ( ! empty( self::$loop_stack ) ) {
            $GLOBALS['post'] = $post;
        }
    }

    /**
     * Restores every global exactly as found, including the "was not set at
     * all" case — assigning null where the key never existed would itself be
     * a change.
     */
    public static function end_loop_context() {
        if ( empty( self::$loop_stack ) ) {
            return;
        }
        foreach ( array_pop( self::$loop_stack ) as $key => $saved ) {
            list( $existed, $value ) = $saved;
            if ( $existed ) {
                $GLOBALS[ $key ] = $value;
            } else {
                unset( $GLOBALS[ $key ] );
            }
        }
    }

    /**
     * Allowed HTML for a replayed third-party list-table cell.
     *
     * wp_kses_post() is the wrong tool here: `input`, `textarea`, `select`,
     * `option` and `button` are absent from $allowedposttags, so a plugin's
     * inline-edit field gets stripped while its Save/Cancel <a> links survive.
     * The cell then renders half a control and lies about what it can do.
     *
     * This is an allowlist, not a denylist — wp_kses drops every tag and every
     * attribute not named below, which is what keeps `on*` handlers, <script>,
     * <style>, <iframe>, <object>, <embed> and <form> out without enumerating
     * them. <form> stays out on purpose: a nested form inside BrikPanel's page
     * is a CSRF and behaviour hazard, and no list cell needs one. `style` is
     * not granted to the added tags (wp_kses_post's own tags keep their
     * safecss_filter_attr-checked style attribute). href/src still run through
     * wp_kses_bad_protocol, so `javascript:` remains blocked.
     *
     * The HTML is plugin-authored server output rather than user input, so
     * this is defence in depth against a plugin echoing unescaped post meta.
     *
     * @return array
     */
    private static function allowed_cell_html() {
        static $allowed = null;
        if ( null !== $allowed ) {
            return $allowed;
        }

        $common = [
            'class'            => true,
            'id'               => true,
            'title'            => true,
            'tabindex'         => true,
            'hidden'           => true,
            'dir'              => true,
            'lang'             => true,
            'role'             => true,
            'aria-label'       => true,
            'aria-labelledby'  => true,
            'aria-describedby' => true,
            'aria-hidden'      => true,
            'aria-expanded'    => true,
            'aria-live'        => true,
            'data-*'           => true,
        ];

        $allowed = wp_kses_allowed_html( 'post' );

        /**
         * Union, never replace. Some of the tags below (button, label,
         * textarea) already exist in $allowedposttags with the full global
         * attribute set — style, xml:lang, the rest of the aria-* family — and
         * assigning over them would silently NARROW what already worked. Start
         * from whatever core allows for the tag and only add to it.
         */
        $extend = static function ( $tag, array $attrs ) use ( &$allowed, $common ) {
            $existing = isset( $allowed[ $tag ] ) && is_array( $allowed[ $tag ] ) ? $allowed[ $tag ] : [];
            $allowed[ $tag ] = $existing + $common + $attrs;
        };

        $extend( 'input', [
            'type'         => true,
            'name'         => true,
            'value'        => true,
            'placeholder'  => true,
            'checked'      => true,
            'disabled'     => true,
            'readonly'     => true,
            'maxlength'    => true,
            'min'          => true,
            'max'          => true,
            'step'         => true,
            'size'         => true,
            'list'         => true,
            'autocomplete' => true,
        ] );
        $extend( 'textarea', [
            'name'        => true,
            'rows'        => true,
            'cols'        => true,
            'placeholder' => true,
            'disabled'    => true,
            'readonly'    => true,
            'maxlength'   => true,
        ] );
        $extend( 'select', [
            'name'     => true,
            'multiple' => true,
            'size'     => true,
            'disabled' => true,
        ] );
        $extend( 'option', [
            'value'    => true,
            'selected' => true,
            'disabled' => true,
        ] );
        $extend( 'optgroup', [
            'label'    => true,
            'disabled' => true,
        ] );
        $extend( 'button', [
            'type'     => true,
            'name'     => true,
            'value'    => true,
            'disabled' => true,
        ] );
        $extend( 'label', [ 'for' => true ] );

        return $allowed;
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
        try {
            // Fire specific action first (this is the order WP core uses).
            do_action( "manage_{$post_type}_posts_custom_column", $column_id, $post_id );
            do_action( 'manage_posts_custom_column', $column_id, $post_id );
        } catch ( \Throwable $e ) {
            // A third-party cell renderer threw on our (non-edit) screen.
            // Discard whatever partial output it buffered and render the cell
            // empty rather than corrupting the JSON the products list AJAX is
            // streaming back — a corrupted body is what shows the merchant a
            // "page couldn't load" grid.
            ob_end_clean();
            return '';
        }
        $html = ob_get_clean();

        if ( '' === trim( $html ) ) {
            return '';
        }

        return wp_kses( $html, self::allowed_cell_html() );
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

        try {
            $actions = apply_filters( 'post_row_actions', [], $post );
        } catch ( \Throwable $e ) {
            // A third-party row-actions callback threw on our non-edit screen;
            // degrade to no extra actions rather than failing the list row.
            return [];
        }
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
        try {
            do_action( 'restrict_manage_posts', $post_type, '' );
            $html = ob_get_clean();
        } catch ( \Throwable $e ) {
            // A filter-bar handler threw; discard partial markup and render
            // no extra filters rather than breaking the toolbar.
            ob_end_clean();
            $html = '';
        }

        if ( null === $previous ) {
            unset( $_GET['post_type'] );
        } else {
            $_GET['post_type'] = $previous;
        }
        $GLOBALS['typenow'] = $previous_typenow;

        // Same allowlist the replayed cells use — filter-bar handlers emit the
        // very same form controls, and keeping one list means a tag added for
        // one surface can never go missing on the other.
        return wp_kses( $html, self::allowed_cell_html() );
    }
}
