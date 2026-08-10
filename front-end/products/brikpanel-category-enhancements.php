<?php
/**
 * BrikPanel - Category Enhancements
 *
 * WordPress renders hierarchical product taxonomies as one flat list where the
 * only hint of nesting is an em-dash prefix ("— Child", "—— Grandchild"), and
 * it paginates that list by *row* count, so a branch is regularly cut in half
 * across two pages. This module turns that screen into a folder-style tree:
 *
 * - Collapsible parent rows with indent guides and child counts (JS side)
 * - The whole tree on a single page, so a branch is never split by pagination
 * - AJAX drag-and-drop re-parenting
 * - "Add subcategory" / "Move to top level" row actions
 * - A clean, non-broken term image column
 *
 * @package BrikPanel
 * @since 1.7.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Brikpanel_Category_Enhancements {

    /**
     * Taxonomy of the term screen currently being loaded, when it is one we
     * enhance. Set from the load-edit-tags.php hook.
     *
     * @var string
     */
    private $screen_taxonomy = '';

    public function __construct() {
        // Per-page override + tree bootstrapping happen once we know which
        // taxonomy screen is being rendered.
        add_action('load-edit-tags.php', [$this, 'boot_terms_screen']);

        // AJAX: re-parent a term (drag & drop, "Move to top level").
        add_action('wp_ajax_brikpanel_set_category_parent', [$this, 'ajax_set_parent']);

        // Taxonomy-specific filters are registered on admin_init: third-party
        // product taxonomies can still be registering on `init` while this
        // constructor runs, and both hooks below only fire while a list table
        // renders, long after admin_init.
        add_action('admin_init', [$this, 'register_taxonomy_filters'], 20);
    }

    /**
     * Register the per-taxonomy list table filters.
     *
     * @return void
     */
    public function register_taxonomy_filters() {
        // Hierarchy-aware row actions on every supported taxonomy.
        foreach (self::supported_taxonomies() as $taxonomy) {
            add_filter("{$taxonomy}_row_actions", [$this, 'row_actions'], 10, 2);
        }

        // Clean up the term image column. WooCommerce always prints an
        // <img> tag in the "Image" column and falls back to its global
        // placeholder when a term has no picture. When that placeholder
        // file is missing, or the linked attachment was deleted, the row
        // shows an ugly broken-image icon. Run after WooCommerce (its
        // filters are at priority 10) and replace any non-displayable
        // image with a neutral, on-brand placeholder. Applies to every
        // product taxonomy that exposes a thumb column (categories,
        // brands, and any third-party taxonomy following the same
        // convention).
        foreach ($this->image_column_taxonomies() as $taxonomy) {
            add_filter("manage_{$taxonomy}_custom_column", [$this, 'fix_term_image_column'], 99, 3);
        }
    }

    // =========================================================================
    // SCREEN BOOTSTRAP
    // =========================================================================

    /**
     * Taxonomies whose term screens BrikPanel enhances.
     *
     * Hierarchical product taxonomies (categories, brands) get the folder
     * tree; product tags are kept for the per-page override they already had.
     *
     * @return string[]
     */
    public static function supported_taxonomies() {
        static $list = null;

        if (null !== $list) {
            return $list;
        }

        $list = ['product_cat', 'product_tag'];

        // get_object_taxonomies() is only meaningful once taxonomies are
        // registered; before that we still have the two defaults above.
        foreach (get_object_taxonomies('product', 'objects') as $taxonomy) {
            if (!empty($taxonomy->show_ui) && !empty($taxonomy->hierarchical)) {
                $list[] = $taxonomy->name;
            }
        }

        /**
         * Filters the taxonomies that receive BrikPanel's term-screen tools.
         *
         * @since 3.2.36
         *
         * @param string[] $list Taxonomy names.
         */
        $list = apply_filters('brikpanel_term_screen_taxonomies', array_values(array_unique($list)));
        $list = array_values(array_filter(array_map('sanitize_key', (array) $list)));

        return $list;
    }

    /**
     * Whether the term screen being rendered should be drawn as a tree.
     *
     * WordPress falls back to a flat, non-hierarchical listing whenever the
     * table is searched or sorted by a column, so there is no hierarchy left
     * to draw on those views.
     *
     * @param string $taxonomy Taxonomy name.
     * @return bool
     */
    public static function tree_is_enabled($taxonomy) {
        static $cache = [];

        $taxonomy = (string) $taxonomy;

        if (isset($cache[$taxonomy])) {
            return $cache[$taxonomy];
        }

        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only display decision.
        $is_flat = !empty($_REQUEST['s']) || !empty($_REQUEST['orderby']);
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        $cache[$taxonomy] = (
            $taxonomy
            && !$is_flat
            && taxonomy_exists($taxonomy)
            && is_taxonomy_hierarchical($taxonomy)
            && in_array($taxonomy, self::supported_taxonomies(), true)
            && 'no' !== get_option('brikpanel_category_tree', 'yes')
        );

        return $cache[$taxonomy];
    }

    /**
     * Whether the whole tree is rendered on a single page.
     *
     * Collapsing replaces pagination, so a branch is never cut in half. Very
     * large taxonomies keep native pagination instead, because rendering every
     * single row at once would be the slower trade.
     *
     * @param string $taxonomy Taxonomy name.
     * @return bool
     */
    public static function tree_is_active($taxonomy) {
        static $cache = [];

        $taxonomy = (string) $taxonomy;

        if (isset($cache[$taxonomy])) {
            return $cache[$taxonomy];
        }

        $active = false;

        if (self::tree_is_enabled($taxonomy)) {
            $total = self::term_total($taxonomy);

            /**
             * Filters the largest taxonomy that is rendered as a single,
             * un-paginated tree. Above it, native pagination is kept.
             *
             * The ceiling exists because the cost is the row count itself, not
             * the tree: on a plugin-heavy admin, rendering ~460 term rows in
             * one page measured ~5s slower than 20 rows, of which under a
             * second was this module's own work. 300 keeps the whole tree on
             * one page for the great majority of stores while bounding that
             * worst case; raise it on a fast host if the catalogue is deeper.
             *
             * @since 3.2.36
             *
             * @param int    $max      Maximum number of terms.
             * @param string $taxonomy Taxonomy name.
             */
            $max = (int) apply_filters('brikpanel_category_tree_max_terms', 300, $taxonomy);

            $active = ($total > 0 && $total <= $max);
        }

        $cache[$taxonomy] = $active;

        return $active;
    }

    /**
     * Total number of terms in a taxonomy, empty ones included.
     *
     * @param string $taxonomy Taxonomy name.
     * @return int
     */
    private static function term_total($taxonomy) {
        static $cache = [];

        if (isset($cache[$taxonomy])) {
            return $cache[$taxonomy];
        }

        $count = wp_count_terms([
            'taxonomy'   => $taxonomy,
            'hide_empty' => false,
        ]);

        $cache[$taxonomy] = is_wp_error($count) ? 0 : (int) $count;

        return $cache[$taxonomy];
    }

    /**
     * Prepare a term list screen: per-page override and tree pagination reset.
     *
     * @return void
     */
    public function boot_terms_screen() {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- reading the screen being displayed.
        $taxonomy = isset($_REQUEST['taxonomy']) ? sanitize_key(wp_unslash($_REQUEST['taxonomy'])) : '';
        $paged    = isset($_REQUEST['paged']) ? absint($_REQUEST['paged']) : 1;
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        if (!$taxonomy || !in_array($taxonomy, self::supported_taxonomies(), true)) {
            return;
        }

        $this->screen_taxonomy = $taxonomy;

        add_filter("edit_{$taxonomy}_per_page", [$this, 'filter_per_page']);

        // In tree mode the whole taxonomy lives on page one. An old bookmark
        // pointing at page two would otherwise render an empty table. Only
        // plain page views are redirected: this hook fires before edit-tags.php
        // handles a submitted action, so bouncing a POST would swallow it.
        $is_plain_view = empty($_POST)
            && empty($_GET['action'])
            && empty($_GET['action2'])
            && isset($_SERVER['REQUEST_METHOD'])
            && 'GET' === strtoupper(sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD'])));

        if ($paged > 1 && $is_plain_view && self::tree_is_active($taxonomy) && !headers_sent()) {
            $target = remove_query_arg('paged');
            wp_safe_redirect($target);
            exit;
        }
    }

    /**
     * Number of term rows per page.
     *
     * In tree mode every term is rendered at once so that no branch is ever
     * cut in half by pagination; collapsing does the job pagination used to.
     *
     * @param int $per_page Value coming from Screen Options.
     * @return int
     */
    public function filter_per_page($per_page) {
        if ($this->screen_taxonomy && self::tree_is_active($this->screen_taxonomy)) {
            return max(1, self::term_total($this->screen_taxonomy));
        }

        $custom = absint(get_option('brikpanel_categories_per_page', 20));

        return $custom ? $custom : $per_page;
    }

    // =========================================================================
    // ROW ACTIONS
    // =========================================================================

    /**
     * Add hierarchy actions to each term row.
     *
     * The JavaScript side turns both into one-click operations; the markup is
     * produced here so the labels stay translatable and the parent state comes
     * from the database instead of being guessed from the rendered name.
     *
     * @param string[] $actions Row actions.
     * @param WP_Term  $term    Term object.
     * @return string[]
     */
    public function row_actions($actions, $term) {
        if (!is_object($term) || empty($term->taxonomy) || !is_taxonomy_hierarchical($term->taxonomy)) {
            return $actions;
        }

        $taxonomy_object = get_taxonomy($term->taxonomy);

        if (!$taxonomy_object || !current_user_can($taxonomy_object->cap->edit_terms)) {
            return $actions;
        }

        $extra = [];

        // "Subcategory" only reads right on the category screen; every other
        // hierarchical product taxonomy (brands, custom ones) gets a neutral
        // label rather than a composed string translators cannot inflect.
        $add_label = ('product_cat' === $term->taxonomy)
            ? __('Add subcategory', 'brikpanel')
            : __('Add child', 'brikpanel');

        $extra['brikpanel-add-child'] = sprintf(
            '<a href="#" class="brikpanel-add-child" data-term="%1$d" data-name="%2$s">%3$s</a>',
            (int) $term->term_id,
            esc_attr($term->name),
            esc_html($add_label)
        );

        if ((int) $term->parent > 0) {
            $extra['brikpanel-toplevel'] = sprintf(
                '<a href="#" class="brikpanel-toplevel" data-term="%1$d">%2$s</a>',
                (int) $term->term_id,
                esc_html__('Move to top level', 'brikpanel')
            );
        }

        // Keep "Delete" last, where WordPress users expect it.
        $delete = [];
        if (isset($actions['delete'])) {
            $delete['delete'] = $actions['delete'];
            unset($actions['delete']);
        }

        return array_merge($actions, $extra, $delete);
    }

    // =========================================================================
    // TERM IMAGE COLUMN
    // =========================================================================

    /**
     * Product taxonomies whose list table renders a term image column.
     *
     * @return string[]
     */
    private function image_column_taxonomies() {
        return apply_filters('brikpanel_image_column_taxonomies', ['product_cat', 'product_brand']);
    }

    /**
     * Replace a broken / missing term image with a clean placeholder.
     *
     * WooCommerce appends `<img class="wp-post-image" ...>` to the thumb
     * column. We strip exactly that tag (keeping anything else WC added,
     * e.g. the default-category help tip) and re-render: a tidy thumbnail
     * when the term has a real, resolvable image, otherwise a neutral
     * placeholder box that matches the BrikPanel design system.
     *
     * @param string $columns Column HTML built so far.
     * @param string $column  Column key.
     * @param int    $term_id Term ID.
     * @return string
     */
    public function fix_term_image_column($columns, $column, $term_id) {
        if ('thumb' !== $column) {
            return $columns;
        }

        // Drop WooCommerce's own image tag, whatever its src is.
        $columns = preg_replace(
            '/<img\b[^>]*\bclass="[^"]*\bwp-post-image\b[^"]*"[^>]*>/i',
            '',
            (string) $columns
        );

        $image_url    = '';
        $thumbnail_id = absint(get_term_meta($term_id, 'thumbnail_id', true));

        if ($thumbnail_id) {
            // Only treat it as valid if the attachment still exists and
            // resolves to a URL. A dangling meta value (deleted media)
            // would otherwise render as a broken image again.
            $attachment = get_post($thumbnail_id);
            if ($attachment && 'attachment' === $attachment->post_type) {
                $resolved = wp_get_attachment_image_url($thumbnail_id, 'thumbnail');
                if ($resolved) {
                    $image_url = $resolved;
                }
            }
        }

        if ($image_url) {
            $columns .= '<img src="' . esc_url($image_url) . '" alt="" loading="lazy" decoding="async" class="bpl-tax-thumb" width="40" height="40" />';
        } else {
            // Neutral, monochrome placeholder. aria-hidden + empty alt
            // because an absent image carries no information.
            $columns .= '<span class="bpl-tax-noimg" aria-hidden="true" title="' . esc_attr__('No image', 'brikpanel') . '">'
                . '<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" focusable="false" aria-hidden="true">'
                . '<rect x="3" y="3" width="18" height="18" rx="2.5" stroke="currentColor" stroke-width="1.6"/>'
                . '<circle cx="9" cy="9" r="1.6" fill="currentColor"/>'
                . '<path d="M4 16.5L9 12l4 3.5L16 13l4 4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>'
                . '</svg>'
                . '</span>';
        }

        return $columns;
    }

    // =========================================================================
    // AJAX: RE-PARENT A TERM
    // =========================================================================

    public function ajax_set_parent() {
        check_ajax_referer('brikpanel_category_nesting', 'security');

        $term_id   = isset($_POST['term_id']) ? absint($_POST['term_id']) : 0;
        $parent_id = isset($_POST['parent_id']) ? absint($_POST['parent_id']) : 0;
        $taxonomy  = isset($_POST['taxonomy']) ? sanitize_key(wp_unslash($_POST['taxonomy'])) : '';

        if (
            !$term_id
            || !$taxonomy
            || !taxonomy_exists($taxonomy)
            || !is_taxonomy_hierarchical($taxonomy)
            || !in_array($taxonomy, self::supported_taxonomies(), true)
        ) {
            wp_send_json_error(['message' => __('Invalid request.', 'brikpanel')]);
        }

        $taxonomy_object = get_taxonomy($taxonomy);

        if (!$taxonomy_object || !current_user_can($taxonomy_object->cap->edit_terms)) {
            wp_send_json_error(['message' => __('Permission denied.', 'brikpanel')]);
        }

        $term = get_term($term_id, $taxonomy);
        if (!$term || is_wp_error($term)) {
            wp_send_json_error(['message' => __('Category not found.', 'brikpanel')]);
        }

        if ($parent_id === $term_id) {
            wp_send_json_error(['message' => __('A category cannot be its own parent.', 'brikpanel')]);
        }

        if ($parent_id > 0) {
            $parent = get_term($parent_id, $taxonomy);
            if (!$parent || is_wp_error($parent)) {
                wp_send_json_error(['message' => __('Parent category not found.', 'brikpanel')]);
            }

            // A term cannot be moved under one of its own descendants: that
            // would detach the whole branch from the tree.
            $ancestors = get_ancestors($parent_id, $taxonomy, 'taxonomy');
            if (in_array($term_id, array_map('intval', $ancestors), true)) {
                wp_send_json_error(['message' => __('Cannot set a child category as parent.', 'brikpanel')]);
            }
        }

        if ((int) $term->parent === $parent_id) {
            wp_send_json_error(['message' => __('That category is already in this position.', 'brikpanel')]);
        }

        $result = wp_update_term($term_id, $taxonomy, ['parent' => $parent_id]);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        if ($parent_id > 0) {
            $parent_term = get_term($parent_id, $taxonomy);
            $parent_name = ($parent_term && !is_wp_error($parent_term)) ? $parent_term->name : '';

            $message = sprintf(
                /* translators: %1$s: category name, %2$s: parent category name */
                __('"%1$s" moved under "%2$s"', 'brikpanel'),
                $term->name,
                $parent_name
            );
        } else {
            $message = sprintf(
                /* translators: %s: category name */
                __('"%s" moved to the top level', 'brikpanel'),
                $term->name
            );
        }

        wp_send_json_success([
            'message' => $message,
        ]);
    }
}

new Brikpanel_Category_Enhancements();
