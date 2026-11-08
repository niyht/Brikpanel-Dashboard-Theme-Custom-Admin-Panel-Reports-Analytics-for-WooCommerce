<?php
/**
 * Variation titles for attributes named outside ASCII.
 *
 * WooCommerce titles a variation "Hoodie - Option, Option"
 * (WC_Product_Variation_Data_Store_CPT::generate_product_title()) with
 * wc_get_formatted_variation(), which finds an attribute's taxonomy by the
 * attribute's key. Outside ASCII that key is percent-encoded (`pa_вид` is keyed
 * `pa_%d0%b2%d0%b8%d0%b4`), taxonomy_exists() misses it, and the title gets
 * the term's slug instead of its name: "Hoodie - спортен" for "Спортен",
 * "спортен-плюс" for "Спортен Плюс", "μεγάλο" for "Μεγάλο". The title is
 * stored and order items are named after it, so the classic cart, the
 * checkout, the order screens and every email showed it, and the cart and the
 * emails listed "Вид: Спортен" under it again because WooCommerce could not
 * find the option in the name.
 *
 * Here the options in the title are read the way brikpanel_formatted_variation()
 * reads them. A title without such a key is WooCommerce's, byte for byte, and
 * costs no lookup.
 *
 * A filter, not a one-time rewrite of the stored titles: WooCommerce rebuilds
 * the title each time it reads a variation and writes it back when it differs
 * (WC_Product_Variation_Data_Store_CPT::read()), so a rewritten title would be
 * undone by the next read. The same rebuild corrects the titles saved before
 * this, each on its next read. Order items keep the name they were sold with.
 *
 * @package BrikPanel
 * @since   3.3.37
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A variation's title with its options read by name.
 *
 * Only WooCommerce's own options are swapped, at the last place they stand in
 * the title: TranslatePress rebuilds the title from them at priority 8 with its
 * own separator, and a filter may add text after them. A title another plugin
 * built without them is left alone. When an attribute's taxonomy is not
 * registered in this request (a deleted attribute, another site's after
 * switch_to_blog()), the option is read from its slug as WooCommerce reads it,
 * so the title stays WooCommerce's and the stored one is not rewritten back
 * and forth.
 *
 * @param string     $title        Title as built so far.
 * @param WC_Product $product      Variation.
 * @param string     $title_base   The parent product's title.
 * @param string     $title_suffix The options as WooCommerce read them; '' when
 *                                 the title carries none (3+ attributes, or 2
 *                                 with a multi-word attribute name).
 * @return string
 */
function brikpanel_variation_title( $title, $product, $title_base = '', $title_suffix = '' ) {
	$suffix = (string) $title_suffix;
	if ( '' === $suffix || ! $product instanceof WC_Product || ! function_exists( 'brikpanel_formatted_variation' ) ) {
		return $title;
	}

	// Only a taxonomy named outside ASCII is misread.
	$misread = false;
	foreach ( array_keys( (array) $product->get_attributes() ) as $key ) {
		if ( 0 === strpos( (string) $key, 'pa_' ) && false !== strpos( (string) $key, '%' ) ) {
			$misread = true;
			break;
		}
	}
	if ( ! $misread ) {
		return $title;
	}

	$options = brikpanel_formatted_variation( $product, false );
	$title   = (string) $title;
	$at      = strrpos( $title, $suffix );
	if ( '' === $options || $options === $suffix || false === $at ) {
		return $title;
	}

	return substr_replace( $title, $options, $at, strlen( $suffix ) );
}
add_filter( 'woocommerce_product_variation_title', 'brikpanel_variation_title', 20, 4 );
