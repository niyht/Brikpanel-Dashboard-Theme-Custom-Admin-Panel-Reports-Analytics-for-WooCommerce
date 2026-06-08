<?php
/**
 * BrikPanel - Category Enhancements
 *
 * - Categories per page from BrikPanel settings
 * - AJAX drag-and-drop parent/child nesting
 *
 * @package BrikPanel
 * @since 1.7.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Brikpanel_Category_Enhancements {

    public function __construct() {
        // Override categories per page
        add_filter('edit_product_cat_per_page', [$this, 'categories_per_page']);
        add_filter('edit_product_tag_per_page', [$this, 'categories_per_page']);

        // AJAX: update category parent
        add_action('wp_ajax_brikpanel_set_category_parent', [$this, 'ajax_set_parent']);

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

    public function categories_per_page($per_page) {
        $custom = get_option('brikpanel_categories_per_page', 20);
        return $custom ? absint($custom) : $per_page;
    }

    public function ajax_set_parent() {
        check_ajax_referer('brikpanel_category_nesting', 'security');

        if (!current_user_can('manage_product_terms')) {
            wp_send_json_error(['message' => __('Permission denied.', 'brikpanel')]);
        }

        $term_id   = intval($_POST['term_id'] ?? 0);
        $parent_id = intval($_POST['parent_id'] ?? 0);
        $taxonomy  = sanitize_key($_POST['taxonomy'] ?? 'product_cat');

        if (!$term_id || !in_array($taxonomy, ['product_cat', 'product_tag'], true)) {
            wp_send_json_error(['message' => __('Invalid request.', 'brikpanel')]);
        }

        $term = get_term($term_id, $taxonomy);
        if (!$term || is_wp_error($term)) {
            wp_send_json_error(['message' => __('Category not found.', 'brikpanel')]);
        }

        // Prevent circular reference
        if ($parent_id > 0) {
            $parent = get_term($parent_id, $taxonomy);
            if (!$parent || is_wp_error($parent)) {
                wp_send_json_error(['message' => __('Parent category not found.', 'brikpanel')]);
            }

            // Check if parent_id is a descendant of term_id
            $ancestors = get_ancestors($parent_id, $taxonomy, 'taxonomy');
            if (in_array($term_id, $ancestors, true)) {
                wp_send_json_error(['message' => __('Cannot set a child category as parent.', 'brikpanel')]);
            }
        }

        $result = wp_update_term($term_id, $taxonomy, ['parent' => $parent_id]);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        $parent_term = $parent_id > 0 ? get_term($parent_id, $taxonomy) : null;
        $parent_name = ($parent_term && !is_wp_error($parent_term)) ? $parent_term->name : __('None (top level)', 'brikpanel');

        wp_send_json_success([
            'message' => sprintf(
                /* translators: %1$s: category name, %2$s: parent name */
                __('"%1$s" moved under "%2$s"', 'brikpanel'),
                $term->name,
                $parent_name
            ),
        ]);
    }
}

new Brikpanel_Category_Enhancements();
