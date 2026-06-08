<?php
/**
 * BrikPanel - Variation Gallery (Frontend)
 *
 * Injects extra variation gallery images into WooCommerce's variation data
 * and handles the gallery swap on the product page when a variation is selected.
 *
 * @package BrikPanel
 * @since 1.6.0
 */

if (!defined('ABSPATH')) {
    exit;
}

if (get_option('brikpanel_simple_product_editor', 'yes') !== 'yes') {
    return;
}

if (get_option('brikpanel_variation_gallery_enabled', 'yes') !== 'yes') {
    return;
}

/**
 * Add variation gallery image data to the variation JSON sent to the frontend.
 */
add_filter('woocommerce_available_variation', function ($data, $product, $variation) {
    $gallery_ids = get_post_meta($variation->get_id(), '_brikpanel_variation_gallery', true);

    if (empty($gallery_ids) || !is_array($gallery_ids)) {
        $data['brikpanel_gallery_images'] = [];
        return $data;
    }

    $images = [];
    foreach ($gallery_ids as $id) {
        $id = (int) $id;
        if (!$id) continue;

        $src       = wp_get_attachment_image_url($id, 'woocommerce_single');
        $full      = wp_get_attachment_image_url($id, 'full');
        $thumb     = wp_get_attachment_image_url($id, 'woocommerce_gallery_thumbnail');
        $srcset    = wp_get_attachment_image_srcset($id, 'woocommerce_single');
        $sizes     = wp_get_attachment_image_sizes($id, 'woocommerce_single');
        $alt       = get_post_meta($id, '_wp_attachment_image_alt', true);

        if (!$src) continue;

        $images[] = [
            'src'           => $src,
            'full_src'      => $full ?: $src,
            'thumbnail_src' => $thumb ?: $src,
            'srcset'        => $srcset ?: '',
            'sizes'         => $sizes ?: '',
            'alt'           => $alt ?: '',
        ];
    }

    $data['brikpanel_gallery_images'] = $images;
    return $data;
}, 10, 3);

/**
 * Enqueue the frontend gallery swap script on single product pages.
 */
add_action('wp_enqueue_scripts', function () {
    if (!is_product()) {
        return;
    }

    wp_enqueue_script(
        'brikpanel_variation_gallery',
        BRIKPANEL_URL . 'front-end/products/brikpanel-variation-gallery.js',
        ['jquery'],
        BRIKPANEL_VERSION,
        true
    );
});
