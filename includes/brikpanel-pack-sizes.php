<?php
/**
 * Pack sizes: a variation that sells from its product's shared stock can take
 * more than one unit per sale.
 *
 * A soap shop sells the same bar as a 1-pack, a 2-pack and a 5-pack, all from
 * one shared quantity (WooCommerce's product-level stock, BrikPanel's "Shared
 * stock" switch). WooCommerce takes one unit for every item sold, whatever the
 * pack holds: a 5-pack sale turned 20 bars into 19 (zmayyem, wp.org,
 * 2026-10-17). With the product's "Pack sizes" switch on, each variation
 * carries the units one sale takes, and every stock movement counts units:
 *
 * - orders: stock taken and reserved at checkout, put back on cancel, put back
 *   by a refund, and changed by editing an item in the order screen or the
 *   REST API;
 * - the shop: a pack the stock cannot fill is out of stock, "N in stock" and
 *   the quantity limits count whole packs, and carts are checked in units
 *   (classic and block cart, checkout, paying for a failed order).
 *
 * Every order line keeps the units it was sold with (UNITS_META on the item),
 * so changing a pack size later never changes an old order. The variation's
 * own stock getter is left alone on purpose: WooCommerce adds up the cart
 * lines of one stock as plain item counts, and packs counted there would turn
 * a valid mixed cart down. The final guard is the stock reservation at
 * checkout, which counts units through woocommerce_order_item_quantity.
 *
 * Off by default, per product. Without the switch, and for a variation that
 * counts its own stock, every function here leaves WooCommerce as it is.
 *
 * @package BrikPanel
 * @since   3.3.36
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Brikpanel_Pack_Sizes {

	/** Product meta: "yes" while the product's "Pack sizes" switch is on. */
	const SWITCH_META = '_brikpanel_pack_sizes';

	/**
	 * Variation meta: the units one sale of the variation takes (absent = 1).
	 * The same key on an order line: the units that line was sold with.
	 */
	const UNITS_META = '_brikpanel_pack_units';

	/** Largest pack size the product editor accepts. */
	const MAX_UNITS = 9999;

	/** True while WooCommerce restocks the refunded lines that hold no packs. */
	private static $restocking = false;

	public static function register() {
		// Orders.
		add_filter( 'woocommerce_order_item_quantity', [ __CLASS__, 'order_item_quantity' ], 10, 3 );
		add_filter( 'woocommerce_prevent_adjust_line_item_product_stock', [ __CLASS__, 'adjust_line_item' ], 10, 3 );
		add_filter( 'woocommerce_can_restock_refunded_items', [ __CLASS__, 'restock_refund' ], 10, 3 );

		// The shop.
		add_filter( 'woocommerce_product_is_in_stock', [ __CLASS__, 'is_in_stock' ], 10, 2 );
		add_filter( 'woocommerce_format_stock_quantity', [ __CLASS__, 'format_stock_quantity' ], 10, 2 );
		add_filter( 'woocommerce_quantity_input_max', [ __CLASS__, 'quantity_input_max' ], 10, 2 );
		add_filter( 'woocommerce_add_to_cart_validation', [ __CLASS__, 'validate_add_to_cart' ], 20, 5 );
		add_filter( 'woocommerce_update_cart_validation', [ __CLASS__, 'validate_cart_update' ], 20, 4 );
		add_filter( 'woocommerce_cart_item_required_stock_is_not_enough', [ __CLASS__, 'cart_stock_not_enough' ], 10, 3 );
		add_action( 'woocommerce_store_api_validate_cart_item', [ __CLASS__, 'store_api_validate_cart_item' ], 10, 2 );
		add_filter( 'woocommerce_store_api_product_quantity_maximum', [ __CLASS__, 'store_api_quantity_maximum' ], 10, 3 );
		add_filter( 'woocommerce_pay_order_product_has_enough_stock', [ __CLASS__, 'pay_order_has_enough_stock' ], 10, 3 );
	}

	/* ------------------------------------------------------------------
	 * Units
	 * ------------------------------------------------------------------ */

	/**
	 * Units one sale of this product takes from its stock. Above 1 only for a
	 * variation that sells from its product's shared stock while the
	 * product's "Pack sizes" switch is on. The meta is read first: it comes
	 * with the product object, so a product without packs costs no query.
	 *
	 * @param WC_Product|mixed $product Product or variation.
	 * @return int At least 1.
	 */
	public static function units( $product ) {
		if ( ! $product instanceof WC_Product_Variation ) {
			return 1;
		}
		$units = (int) $product->get_meta( self::UNITS_META, true );
		if ( $units < 2 ) {
			return 1;
		}
		// "parent": the variation counts nothing of its own and the product
		// counts the stock it sells from.
		if ( 'parent' !== $product->get_manage_stock() || ! self::switched_on( $product->get_parent_id() ) ) {
			return 1;
		}
		return min( $units, self::MAX_UNITS );
	}

	/**
	 * Whether the product's "Pack sizes" switch is on.
	 *
	 * @param int $product_id Product (parent) ID.
	 * @return bool
	 */
	public static function switched_on( $product_id ) {
		$product_id = (int) $product_id;
		return $product_id > 0 && 'yes' === get_post_meta( $product_id, self::SWITCH_META, true );
	}

	/**
	 * A pack size as typed: a whole number from 1 to MAX_UNITS (empty = 1).
	 *
	 * @param mixed $raw Submitted value.
	 * @return int
	 */
	public static function sanitize_units( $raw ) {
		if ( ! is_scalar( $raw ) || ! is_numeric( trim( (string) $raw ) ) ) {
			return 1;
		}
		$units = (int) floor( (float) $raw );
		return max( 1, min( self::MAX_UNITS, $units ) );
	}

	/**
	 * Units one item of an order line takes: the units the line was sold
	 * with; 1 for a line whose stock was taken without them (an order from
	 * before the switch, or a pack of one); otherwise, for a line whose stock
	 * is still to be taken, the variation's pack size now.
	 *
	 * @param WC_Order_Item|mixed $item Order item.
	 * @return int At least 1.
	 */
	public static function item_units( $item ) {
		if ( ! $item instanceof WC_Order_Item_Product ) {
			return 1;
		}
		$stamped = (int) $item->get_meta( self::UNITS_META, true );
		if ( $stamped > 0 ) {
			return min( $stamped, self::MAX_UNITS );
		}
		if ( '' !== (string) $item->get_meta( '_reduced_stock', true ) ) {
			return 1;
		}
		return self::units( $item->get_product() );
	}

	/**
	 * Whole packs the stock fills after $taken units are set aside.
	 *
	 * @param WC_Product $product Variation that sells packs.
	 * @param int        $units   Units per pack.
	 * @param float      $taken   Units already spoken for.
	 * @return int
	 */
	private static function packs_left( $product, $units, $taken = 0 ) {
		$free = (float) $product->get_stock_quantity() - (float) $taken;
		return $free > 0 ? (int) floor( $free / max( 1, $units ) ) : 0;
	}

	/* ------------------------------------------------------------------
	 * Orders
	 * ------------------------------------------------------------------ */

	/**
	 * Stock taken for an order line (wc_reduce_stock_levels) and reserved at
	 * checkout (ReserveStock): items times units. The line keeps its units;
	 * WooCommerce saves the line right after taking the stock.
	 *
	 * @param int|float     $quantity Items.
	 * @param WC_Order      $order    Order.
	 * @param WC_Order_Item $item     Line.
	 * @return int|float
	 */
	public static function order_item_quantity( $quantity, $order, $item ) {
		$units = self::item_units( $item );
		if ( $units < 2 ) {
			return $quantity;
		}
		if ( (int) $item->get_meta( self::UNITS_META, true ) !== $units ) {
			$item->update_meta_data( self::UNITS_META, $units );
		}
		return wc_stock_amount( $quantity ) * $units;
	}

	/**
	 * An item changed or removed in the order screen or the REST API
	 * (wc_maybe_adjust_line_item_product_stock). WooCommerce compares the
	 * item count with the units already taken and would hand most of a pack
	 * back, so a pack line is adjusted here in units and WooCommerce is told
	 * to leave it.
	 *
	 * @param bool          $prevent       Whether another plugin handles it.
	 * @param WC_Order_Item $item          Line.
	 * @param int           $item_quantity New item count, -1 = the line's own.
	 * @return bool
	 */
	public static function adjust_line_item( $prevent, $item, $item_quantity ) {
		if ( $prevent || ! $item instanceof WC_Order_Item_Product ) {
			return $prevent;
		}
		$units = self::item_units( $item );
		if ( $units < 2 ) {
			return $prevent;
		}
		$product = $item->get_product();
		if ( ! $product || ! $product->managing_stock() ) {
			return $prevent;
		}

		$count     = wc_stock_amount( $item_quantity >= 0 ? $item_quantity : $item->get_quantity() );
		$taken     = wc_stock_amount( $item->get_meta( '_reduced_stock', true ) );
		$restocked = wc_stock_amount( $item->get_meta( '_restock_refunded_items', true ) );
		// What the line should hold now; 0 removes the line.
		$target = $count > 0 ? max( 0, $count - $restocked ) * $units : 0;
		$diff   = $target - $taken;
		if ( 0 == $diff ) { // phpcs:ignore Universal.Operators.StrictComparisons -- int or float stock amounts.
			return true;
		}

		$new_stock = wc_update_product_stock( $product, abs( $diff ), $diff > 0 ? 'decrease' : 'increase' );
		if ( is_wp_error( $new_stock ) ) {
			return true;
		}
		$item->update_meta_data( '_reduced_stock', $target );
		$item->update_meta_data( self::UNITS_META, $units );
		$item->save();

		$order_id = $item->get_order_id();
		if ( $count > 0 && $order_id ) {
			// As WooCommerce does: cancelling the order puts this stock back.
			$data_store = WC_Data_Store::load( 'order' );
			if ( ! $data_store->get_stock_reduced( $order_id ) ) {
				$data_store->set_stock_reduced( $order_id, true );
			}
		}

		$order = $order_id ? wc_get_order( $order_id ) : false;
		if ( $order ) {
			$change = $item->get_name() . ' (' . ( $new_stock + $diff ) . '&rarr;' . $new_stock . ')';
			/* translators: %s: item name and its stock change, e.g. "Soap - 5 pack (20→15)" */
			$order->add_order_note( sprintf( __( 'Adjusted stock: %s', 'brikpanel' ), $change ), 0, true, self::note_meta() );
		}
		return true;
	}

	/**
	 * A refund that puts items back in stock (wc_restock_refunded_items).
	 * WooCommerce adds back one unit per refunded item; a pack line is put
	 * back in units here. The other lines of the same refund still go
	 * through WooCommerce's own function.
	 *
	 * @param bool     $can   Whether WooCommerce restocks.
	 * @param WC_Order $order Order.
	 * @param array    $lines Refunded lines: item ID => [ 'qty' => … ].
	 * @return bool
	 */
	public static function restock_refund( $can, $order, $lines ) {
		if ( ! $can || self::$restocking || ! $order instanceof WC_Order || ! is_array( $lines ) ) {
			return $can;
		}
		$packs = [];
		$plain = [];
		foreach ( $lines as $item_id => $line ) {
			$item = $order->get_item( $item_id );
			if ( $item instanceof WC_Order_Item_Product && self::item_units( $item ) > 1 ) {
				$packs[ $item_id ] = $line;
			} else {
				$plain[ $item_id ] = $line;
			}
		}
		if ( ! $packs ) {
			return $can;
		}
		if ( $plain ) {
			self::$restocking = true;
			try {
				wc_restock_refunded_items( $order, $plain );
			} finally {
				self::$restocking = false;
			}
		}
		foreach ( $packs as $item_id => $line ) {
			self::restock_refunded_line( $order, $order->get_item( $item_id ), is_array( $line ) && isset( $line['qty'] ) ? $line['qty'] : 0 );
		}
		return false;
	}

	/**
	 * One refunded pack line back in stock, the way WooCommerce does it for
	 * a plain line, in units. The line keeps the refunded item count in
	 * _restock_refunded_items (items, as WooCommerce counts them) and the
	 * units still taken in _reduced_stock.
	 *
	 * @param WC_Order      $order    Order.
	 * @param WC_Order_Item $item     Line.
	 * @param mixed         $refunded Refunded items.
	 */
	private static function restock_refunded_line( $order, $item, $refunded ) {
		if ( ! $item instanceof WC_Order_Item_Product ) {
			return;
		}
		$product   = $item->get_product();
		$taken     = wc_stock_amount( $item->get_meta( '_reduced_stock', true ) );
		$restocked = wc_stock_amount( $item->get_meta( '_restock_refunded_items', true ) );
		$count     = wc_stock_amount( $refunded );
		if ( ! $taken || ! $count || ! $product || ! $product->managing_stock() ) {
			return;
		}
		$amount    = min( $count * self::item_units( $item ), $taken );
		$old_stock = $product->get_stock_quantity();
		$new_stock = wc_update_product_stock( $product, $amount, 'increase' );
		if ( is_wp_error( $new_stock ) ) {
			return;
		}
		$item->update_meta_data( '_reduced_stock', $taken - $amount );
		$item->update_meta_data( '_restock_refunded_items', $count + $restocked );

		/* translators: 1: product ID 2: old stock level 3: new stock level */
		$note = sprintf( __( 'Item #%1$s stock increased from %2$s to %3$s.', 'brikpanel' ), $product->get_id(), $old_stock, $new_stock );
		// WooCommerce's own filter and action for a restocked line, so code
		// that follows refunds sees pack lines too.
		$note = apply_filters( 'woocommerce_refund_restock_note', $note, $old_stock, $new_stock, $order, $product );
		if ( $note ) {
			$order->add_order_note( $note, 0, false, self::note_meta() );
		}
		$item->save();
		do_action( 'woocommerce_restock_refunded_item', $product->get_id(), $old_stock, $new_stock, $order, $product );
	}

	/**
	 * The note group WooCommerce gives its own stock notes (WooCommerce 9.9+).
	 *
	 * @return array
	 */
	private static function note_meta() {
		$group = 'Automattic\WooCommerce\Enums\OrderNoteGroup';
		return class_exists( $group ) && defined( $group . '::PRODUCT_STOCK' )
			? [ 'note_group' => constant( $group . '::PRODUCT_STOCK' ) ]
			: [];
	}

	/* ------------------------------------------------------------------
	 * The shop
	 * ------------------------------------------------------------------ */

	/**
	 * A pack the stock cannot fill is out of stock, unless backorders are on.
	 *
	 * @param bool       $in_stock WooCommerce's answer.
	 * @param WC_Product $product  Product.
	 * @return bool
	 */
	public static function is_in_stock( $in_stock, $product ) {
		if ( ! $in_stock ) {
			return $in_stock;
		}
		$units = self::units( $product );
		if ( $units < 2 || $product->backorders_allowed() ) {
			return $in_stock;
		}
		return self::packs_left( $product, $units ) >= 1;
	}

	/**
	 * Stock shown to a shopper ("4 in stock", "Only 4 left in stock", "(4
	 * available)"): whole packs, not units.
	 *
	 * @param int|float  $quantity Units.
	 * @param WC_Product $product  Product.
	 * @return int|float|mixed
	 */
	public static function format_stock_quantity( $quantity, $product ) {
		$units = self::units( $product );
		if ( $units < 2 || ! is_numeric( $quantity ) ) {
			return $quantity;
		}
		return $quantity > 0 ? (int) floor( $quantity / $units ) : 0;
	}

	/**
	 * Most packs one purchase may take (product page, variation form, cart).
	 *
	 * @param int|string $max     WooCommerce's limit, -1 or '' = none.
	 * @param WC_Product $product Product.
	 * @return int|string
	 */
	public static function quantity_input_max( $max, $product ) {
		$units = self::units( $product );
		if ( $units < 2 || $product->backorders_allowed() ) {
			return $max;
		}
		$packs = self::packs_left( $product, $units );
		if ( '' === $max || (int) $max < 0 ) {
			return $packs;
		}
		return min( (int) $max, $packs );
	}

	/**
	 * Adding to the cart (classic form, AJAX and the Store API, which runs
	 * this filter too): the pack, the other lines of the same stock and the
	 * stock held by unpaid orders must fit.
	 *
	 * @param bool  $passed       Earlier validation.
	 * @param int   $product_id   Product ID.
	 * @param mixed $quantity     Items to add.
	 * @param int   $variation_id Variation ID.
	 * @return bool
	 */
	public static function validate_add_to_cart( $passed, $product_id, $quantity, $variation_id = 0 ) {
		if ( ! $passed ) {
			return $passed;
		}
		$product = wc_get_product( $variation_id ? $variation_id : $product_id );
		if ( ! $product instanceof WC_Product || ! $product->managing_stock() || $product->backorders_allowed() ) {
			return $passed;
		}
		$managed = $product->get_stock_managed_by_id();
		$units   = self::units( $product );
		// A single unit counts too once the cart holds packs of the same stock.
		if ( $units < 2 && ! self::cart_has_packs( $managed ) ) {
			return $passed;
		}
		$held  = self::held_stock( $product );
		$other = self::cart_units( $managed );
		$need  = wc_stock_amount( $quantity ) * $units;
		if ( $need <= (float) $product->get_stock_quantity() - $held - $other ) {
			return $passed;
		}
		self::notice_not_enough( $product, $held + $other );
		return false;
	}

	/**
	 * A quantity changed on the classic cart page.
	 *
	 * @param bool   $passed   Earlier validation.
	 * @param string $key      Cart line key.
	 * @param array  $values   Cart line.
	 * @param mixed  $quantity New item count.
	 * @return bool
	 */
	public static function validate_cart_update( $passed, $key, $values, $quantity ) {
		if ( ! $passed || empty( $values['data'] ) || ! $values['data'] instanceof WC_Product ) {
			return $passed;
		}
		$product = $values['data'];
		$managed = $product->get_stock_managed_by_id();
		$units   = self::units( $product );
		if ( ( $units < 2 && ! self::cart_has_packs( $managed, $key ) ) || ! $product->managing_stock() || $product->backorders_allowed() ) {
			return $passed;
		}
		$held  = self::held_stock( $product );
		$other = self::cart_units( $managed, $key );
		if ( wc_stock_amount( $quantity ) * $units <= (float) $product->get_stock_quantity() - $held - $other ) {
			return $passed;
		}
		self::notice_not_enough( $product, $held + $other );
		return false;
	}

	/**
	 * The classic cart and checkout check every line against its stock.
	 * WooCommerce adds the lines of one stock up as item counts; when packs
	 * are among them, the units decide.
	 *
	 * @param bool       $not_enough WooCommerce's answer.
	 * @param WC_Product $product    Line's product.
	 * @param array      $values     Cart line.
	 * @return bool
	 */
	public static function cart_stock_not_enough( $not_enough, $product, $values ) {
		if ( ! $product instanceof WC_Product ) {
			return $not_enough;
		}
		$managed = $product->get_stock_managed_by_id();
		if ( ! self::cart_has_packs( $managed ) ) {
			return $not_enough;
		}
		return (float) $product->get_stock_quantity() < self::held_stock( $product ) + self::cart_units( $managed );
	}

	/**
	 * The block cart and checkout (Store API) check every line; WooCommerce's
	 * own count there is in items.
	 *
	 * @param WC_Product $product   Line's product.
	 * @param array      $cart_item Cart line.
	 * @throws \Automattic\WooCommerce\StoreApi\Exceptions\RouteException When the units do not fit.
	 */
	public static function store_api_validate_cart_item( $product, $cart_item ) {
		if ( ! $product instanceof WC_Product || ! $product->managing_stock() || $product->backorders_allowed() ) {
			return;
		}
		$managed = $product->get_stock_managed_by_id();
		if ( ! self::cart_has_packs( $managed ) ) {
			return;
		}
		$held = self::held_stock( $product );
		if ( (float) $product->get_stock_quantity() >= $held + self::cart_units( $managed ) ) {
			return;
		}
		$exception = 'Automattic\WooCommerce\StoreApi\Exceptions\RouteException';
		if ( ! class_exists( $exception ) ) {
			return;
		}
		$key   = is_array( $cart_item ) && isset( $cart_item['key'] ) ? (string) $cart_item['key'] : '';
		$other = self::cart_units( $managed, $key );
		throw new $exception(
			'woocommerce_rest_product_partially_out_of_stock',
			esc_html( self::not_enough_text( $product, $held + $other ) ),
			400
		);
	}

	/**
	 * The quantity limit of a pack in the block cart and on block product
	 * pages: the whole packs the stock fills, as WooCommerce limits any
	 * product by its stock. The other lines of the same stock are checked
	 * when the cart is validated (store_api_validate_cart_item): counting them
	 * here made the Store API quietly lower a pack line whenever another line
	 * of the same stock grew.
	 *
	 * @param mixed      $value     WooCommerce's limit.
	 * @param WC_Product $product   Product.
	 * @param array|null $cart_item Cart line, null on a product page.
	 * @return mixed
	 */
	public static function store_api_quantity_maximum( $value, $product, $cart_item ) {
		$units = self::units( $product );
		if ( $units < 2 || $product->backorders_allowed() ) {
			return $value;
		}
		$packs = self::packs_left( $product, $units, self::held_stock( $product ) );
		return is_numeric( $value ) ? min( (int) $value, $packs ) : $packs;
	}

	/**
	 * Paying for a failed order again (classic and block): its lines of one
	 * stock are added up in units.
	 *
	 * @param bool       $enough  WooCommerce's answer.
	 * @param WC_Product $product Line's product.
	 * @param WC_Order   $order   Order being paid.
	 * @return bool
	 */
	public static function pay_order_has_enough_stock( $enough, $product, $order ) {
		if ( ! $product instanceof WC_Product || ! $order instanceof WC_Order ) {
			return $enough;
		}
		$managed  = $product->get_stock_managed_by_id();
		$required = 0;
		$packs    = false;
		foreach ( $order->get_items() as $item ) {
			$line_product = $item instanceof WC_Order_Item_Product ? $item->get_product() : null;
			if ( ! $line_product || (int) $line_product->get_stock_managed_by_id() !== (int) $managed ) {
				continue;
			}
			$units     = self::item_units( $item );
			$packs     = $packs || $units > 1;
			$required += wc_stock_amount( $item->get_quantity() ) * $units;
		}
		if ( ! $packs ) {
			return $enough;
		}
		return (float) $product->get_stock_quantity() >= wc_get_held_stock_quantity( $product, $order->get_id() ) + $required;
	}

	/* ------------------------------------------------------------------
	 * Cart helpers
	 * ------------------------------------------------------------------ */

	/**
	 * Units the cart asks of one stock: every line that sells from it, a pack
	 * counted with its size.
	 *
	 * @param int    $managed_id Product whose stock is counted.
	 * @param string $skip_key   Cart line to leave out.
	 * @return float
	 */
	private static function cart_units( $managed_id, $skip_key = '' ) {
		$units = 0;
		foreach ( self::cart_lines() as $key => $line ) {
			if ( '' !== $skip_key && $key === $skip_key ) {
				continue;
			}
			$product = isset( $line['data'] ) ? $line['data'] : null;
			if ( ! $product instanceof WC_Product || (int) $product->get_stock_managed_by_id() !== (int) $managed_id ) {
				continue;
			}
			$units += wc_stock_amount( isset( $line['quantity'] ) ? $line['quantity'] : 0 ) * self::units( $product );
		}
		return $units;
	}

	/**
	 * Whether a cart line other than $skip_key sells packs from this stock.
	 *
	 * @param int    $managed_id Product whose stock is counted.
	 * @param string $skip_key   Cart line to leave out.
	 * @return bool
	 */
	private static function cart_has_packs( $managed_id, $skip_key = '' ) {
		foreach ( self::cart_lines() as $key => $line ) {
			if ( '' !== $skip_key && $key === $skip_key ) {
				continue;
			}
			$product = isset( $line['data'] ) ? $line['data'] : null;
			if ( $product instanceof WC_Product && (int) $product->get_stock_managed_by_id() === (int) $managed_id && self::units( $product ) > 1 ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The cart's lines, or none when there is no cart (admin, cron, REST).
	 *
	 * @return array
	 */
	private static function cart_lines() {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return [];
		}
		$lines = WC()->cart->get_cart();
		return is_array( $lines ) ? $lines : [];
	}

	/**
	 * Stock held by other unpaid orders, leaving out this shopper's own (as
	 * WooCommerce's cart check does).
	 *
	 * @param WC_Product $product Product.
	 * @return float
	 */
	private static function held_stock( $product ) {
		$own = 0;
		if ( function_exists( 'WC' ) && WC()->session ) {
			$own = isset( WC()->session->order_awaiting_payment )
				? absint( WC()->session->order_awaiting_payment )
				: absint( WC()->session->get( 'store_api_draft_order', 0 ) );
		}
		return (float) wc_get_held_stock_quantity( $product, $own );
	}

	/**
	 * "There is not enough stock" with the packs still free for this one.
	 *
	 * @param WC_Product $product Pack.
	 * @param float      $taken   Units already spoken for.
	 * @return string
	 */
	private static function not_enough_text( $product, $taken ) {
		// Units in, packs out: wc_format_stock_quantity_for_display() runs
		// format_stock_quantity() above, as on the product page.
		$left = wc_format_stock_quantity_for_display( max( 0, (float) $product->get_stock_quantity() - (float) $taken ), $product );
		return sprintf(
			/* translators: 1: product name 2: how many more of it the stock can fill */
			__( 'You cannot add that amount of "%1$s" to the cart because there is not enough stock (%2$s remaining).', 'brikpanel' ),
			wp_strip_all_tags( $product->get_name() ),
			$left
		);
	}

	/**
	 * The same as a cart notice.
	 *
	 * @param WC_Product $product Pack.
	 * @param float      $taken   Units already spoken for.
	 */
	private static function notice_not_enough( $product, $taken ) {
		if ( function_exists( 'wc_add_notice' ) ) {
			wc_add_notice( self::not_enough_text( $product, $taken ), 'error' );
		}
	}
}

/**
 * Units one sale of a product takes from its stock (1 unless it is a pack).
 * For other plugins, such as a marketplace sync that sends a pack's stock.
 *
 * @since 3.3.36
 * @param WC_Product|mixed $product Product or variation.
 * @return int
 */
function brikpanel_pack_units( $product ) {
	return Brikpanel_Pack_Sizes::units( $product );
}

Brikpanel_Pack_Sizes::register();
