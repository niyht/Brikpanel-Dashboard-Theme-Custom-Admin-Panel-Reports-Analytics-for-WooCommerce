<?php
/**
 * BrikPanel - Short description HTML preservation (Frontend)
 *
 * Block (FSE) themes render the WooCommerce product short description through
 * the core "Post Excerpt" block. That block always runs the value through
 * wp_trim_words(), which calls wp_strip_all_tags() — so a formatted short
 * description (bullet lists, paragraphs, bold) collapses into one run-on line
 * of plain text on the storefront.
 *
 * This is not BrikPanel-specific (it happens with the native editor too), but
 * because BrikPanel ships a rich-text short description editor we keep the
 * output faithful: on a single product page only, we post-process that one
 * block and restore the real short-description HTML, keeping the block's own
 * wrapper classes so the theme's typography/spacing/color styles still apply.
 *
 * Deliberately narrow scope:
 *   - single product pages only (is_product()),
 *   - the core/post-excerpt block only,
 *   - only when the short description actually contains HTML,
 * so archive loops, the read-more link elsewhere, and SEO/schema meta
 * descriptions (which read get_the_excerpt() directly, not render_block) are
 * never touched.
 *
 * @package BrikPanel
 * @since 3.0.4
 */

if (!defined('ABSPATH')) {
    exit;
}

add_filter('render_block', function ($block_content, $block) {
    if (!is_array($block) || empty($block['blockName']) || $block['blockName'] !== 'core/post-excerpt') {
        return $block_content;
    }

    // Storefront single-product view only. Bail early everywhere else
    // (archives, cart, blog, REST, admin) so nothing else is affected.
    if (!function_exists('is_product') || !is_product()) {
        return $block_content;
    }

    $product_id = get_queried_object_id();
    if (!$product_id || get_post_type($product_id) !== 'product') {
        return $block_content;
    }

    $raw = get_post_field('post_excerpt', $product_id);
    if (!is_string($raw) || trim($raw) === '') {
        return $block_content;
    }

    // Only step in when the Post Excerpt block would have destroyed real
    // markup. Plain-text short descriptions render fine untouched.
    if (wp_strip_all_tags($raw) === $raw) {
        return $block_content;
    }

    // Same formatting pipeline WooCommerce's classic template uses, so the
    // result is identical to a non-block theme (wpautop, shortcodes, etc.).
    $formatted = apply_filters('woocommerce_short_description', $raw);
    if (!is_string($formatted) || trim($formatted) === '') {
        return $block_content;
    }

    // The block prints its text inside a <p class="…__excerpt">. A <p> can't
    // legally contain block-level markup like <ul>, so swap that element for a
    // <div> while preserving its exact attributes (classes added by block
    // supports: typography, text color, spacing…).
    $pattern = '#<(p|div)\b([^>]*\bclass="[^"]*wp-block-post-excerpt__excerpt[^"]*"[^>]*)>.*?</\1>#is';
    $count   = 0;
    $result  = preg_replace_callback(
        $pattern,
        static function ($m) use ($formatted) {
            return '<div' . $m[2] . '>' . $formatted . '</div>';
        },
        $block_content,
        1,
        $count
    );

    return ($count > 0 && is_string($result)) ? $result : $block_content;
}, 20, 2);
