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
