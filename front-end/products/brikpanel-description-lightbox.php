<?php
/**
 * BrikPanel - Product description image lightbox (front-end)
 *
 * Images inserted through the BrikPanel product editor with the "Open in a
 * lightbox" option carry the `brikpanel-lightbox` class. On the single product
 * page this enqueues a tiny, dependency-free overlay that enlarges such images
 * on click. Assets load only when the current product actually contains a
 * lightbox image, so ordinary product pages pay nothing.
 *
 * @package BrikPanel
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_enqueue_scripts', function () {
	if ( ! function_exists( 'is_product' ) || ! is_product() ) {
		return;
	}

	$product_id = get_queried_object_id();
	if ( ! $product_id ) {
		return;
	}

	// Both the long description (post_content) and the short description
	// (post_excerpt) can hold editor images, so scan both for the marker.
	$haystack = get_post_field( 'post_content', $product_id ) . get_post_field( 'post_excerpt', $product_id );
	if ( strpos( (string) $haystack, 'brikpanel-lightbox' ) === false ) {
		return;
	}

	$css = BRIKPANEL_PATH . 'front-end/products/brikpanel-description-lightbox.css';
	$js  = BRIKPANEL_PATH . 'front-end/products/brikpanel-description-lightbox.js';

	wp_enqueue_style(
		'brikpanel_description_lightbox',
		BRIKPANEL_URL . 'front-end/products/brikpanel-description-lightbox.css',
		[],
		@filemtime( $css ) ?: BRIKPANEL_VERSION
	);

	wp_enqueue_script(
		'brikpanel_description_lightbox',
		BRIKPANEL_URL . 'front-end/products/brikpanel-description-lightbox.js',
		[],
		@filemtime( $js ) ?: BRIKPANEL_VERSION,
		true
	);

	wp_localize_script( 'brikpanel_description_lightbox', 'brikpanelLightbox', [
		'i18n' => [
			'close' => __( 'Close', 'brikpanel' ),
		],
	] );
} );
