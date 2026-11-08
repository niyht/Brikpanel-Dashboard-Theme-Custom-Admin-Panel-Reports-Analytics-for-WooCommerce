<?php
/**
 * BrikPanel: the supplier's code under each order line.
 *
 * A dropshipper places every order again at the supplier, who knows the
 * product by its own code, not the store's SKU. The product editor keeps that
 * code (Supplier SKU) but no screen showed it, so it was looked up product by
 * product. Under each product line of an order's items, after WooCommerce's
 * SKU and variation ID, this prints "AliExpress SKU: AE-30418".
 *
 * Only with supplier management on (the class is only built then, see
 * brikpanel-vendors.php) and only for a line whose product has a supplier and
 * a code: every other order looks as before. No setting (owner's call, 30 Oct
 * 2026, on arcanetech's wp.org request).
 *
 * Printed from WooCommerce's own item hook, like the weight line
 * (front-end/order/brikpanel-order-weight.php): there with BrikPanel's order
 * screen on or off, on HPOS and the posts store, and after every reload of the
 * items box (adding an item, recalculating, a refund), which WooCommerce
 * builds over admin-ajax from the same views. That is also why nothing here
 * checks the screen: admin-ajax has none. The hook only runs in wp-admin, so
 * the shopper's emails and the shop never show the code.
 *
 * @package BrikPanel
 * @since   3.3.42
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Brikpanel_Vendor_Order_Items {

	public function __construct() {
		// Before the weight line (10), so the two codes stay together.
		add_action( 'woocommerce_before_order_itemmeta', [ $this, 'item_line' ], 5, 3 );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
	}

	/**
	 * Under a product line's name, after its SKU and variation ID: the
	 * supplier's name and code.
	 *
	 * @param int           $item_id Line ID.
	 * @param WC_Order_Item $item    Line (shipping and fee lines run this hook too).
	 * @param mixed         $product The line's product, unused: a deleted variation still has a main product to ask.
	 */
	public function item_line( $item_id, $item, $product = null ) {
		unset( $item_id, $product );
		if ( ! $item instanceof WC_Order_Item_Product || self::neutralized() ) {
			return;
		}
		$code = Brikpanel_Vendor_Product_Editor::resolve_vendor_sku( $item->get_product_id(), $item->get_variation_id() );
		if ( '' === $code['sku'] ) {
			return;
		}
		$label = Brikpanel_Vendor_Product_Editor::vendor_sku_label( $code['vendor_id'] );
		if ( '' === $label ) {
			return;
		}

		// Each piece isolated, like the weight line: a right-to-left screen
		// keeps the code in one piece and the label's colon at its end.
		printf(
			'<div class="brikpanel-order-item-supplier-sku"><strong><bdi>%1$s</bdi></strong> <bdi>%2$s</bdi></div>',
			esc_html( $label ),
			self::breakable( $code['sku'] ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- every piece is escaped.
		);
	}

	/**
	 * A code that may break at its own separators (AE-30418-BLK-XL) on a
	 * phone's item card, never inside a word.
	 *
	 * @param string $code Code.
	 * @return string Escaped HTML.
	 */
	public static function breakable( $code ) {
		$parts = preg_split( '/(?<=[\-_.\/])/', (string) $code, -1, PREG_SPLIT_NO_EMPTY );
		return implode( '<wbr>', array_map( 'esc_html', (array) $parts ) );
	}

	/**
	 * The line's look, on the order edit and new order screens.
	 */
	public function enqueue() {
		if ( ! function_exists( 'brikpanel_order_screen_context' ) || ! brikpanel_order_screen_context() || self::neutralized() ) {
			return;
		}
		$file = 'front-end/vendors/brikpanel-vendor-order-items.css';
		$ver  = @filemtime( BRIKPANEL_PATH . $file ) ?: BRIKPANEL_VERSION; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a missing file falls back to the plugin version.
		wp_enqueue_style( 'brikpanel-vendor-order-items', BRIKPANEL_URL . $file, [], $ver );
	}

	/**
	 * Whether this request shows WooCommerce as it ships: BrikPanel switched
	 * off with its master switch, or for this user.
	 *
	 * @return bool
	 */
	private static function neutralized() {
		return function_exists( 'brikpanel_access_should_neutralize' ) && brikpanel_access_should_neutralize();
	}
}
