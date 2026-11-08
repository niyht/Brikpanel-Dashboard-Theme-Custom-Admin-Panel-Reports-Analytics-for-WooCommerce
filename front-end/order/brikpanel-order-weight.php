<?php
/**
 * Product weights on the order edit screen.
 *
 * WooCommerce keeps a weight on each product but shows none on an order, so a
 * merchant working out the postage opened every product and added the weights
 * up by hand. Under each product line of the items table this prints the
 * weight of one item and, for more than one, of the whole line
 * ("0.12 kg × 3 = 0.36 kg"). Under the totals: the order's total weight, and a
 * note when a product that ships has no weight, so a short total is not taken
 * for the real one.
 *
 * Only on orders where at least one product has a weight: a store that never
 * enters weights sees the screen as before. No setting (owner's call, 7 Oct
 * 2026, on a merchant's request).
 *
 * Printed from WooCommerce's own item and totals hooks, so it is there with
 * BrikPanel's order screen on or off, on HPOS and the posts store, and after
 * every reload of the items box, which WooCommerce rebuilds over admin-ajax
 * from the same views. That is also why nothing here checks the screen:
 * admin-ajax has none.
 *
 * The orders export (front-end/orders/brikpanel-order-export.php) writes the
 * same weights into its files, so a file and the order screen never disagree.
 *
 * @package BrikPanel
 * @since   3.3.32
 */

defined( 'ABSPATH' ) || exit;

add_action( 'woocommerce_before_order_itemmeta', 'brikpanel_order_weight_item_line', 10, 3 );
add_action( 'woocommerce_admin_order_totals_after_total', 'brikpanel_order_weight_total_rows' );
add_action( 'admin_enqueue_scripts', 'brikpanel_order_weight_enqueue' );

/**
 * Per-request memo: the items box asks once for every line and once more for
 * the total, and each answer needs every line's product.
 *
 * @return array Reference to the memo, keyed by order ID.
 */
function &brikpanel_order_weight_memo() {
	static $memo = array();
	return $memo;
}

/**
 * Per-request memo of one item's weight by product: the orders export reads
 * thousands of lines that share a few products, and building a product for
 * every line cost a quarter of an export row.
 *
 * @return array Reference to the memo, keyed "product ID:variation ID".
 */
function &brikpanel_order_weight_unit_memo() {
	static $memo = array();
	return $memo;
}

/**
 * Forget the worked-out weights (after products or orders changed within one
 * request, which only tests do).
 */
function brikpanel_order_weight_reset() {
	$memo  = &brikpanel_order_weight_memo();
	$memo  = array();
	$units = &brikpanel_order_weight_unit_memo();
	$units = array();
}

/**
 * Whether this request shows WooCommerce as it ships: BrikPanel switched off
 * with its master switch, or for this user.
 *
 * @return bool
 */
function brikpanel_order_weight_neutralized() {
	return function_exists( 'brikpanel_access_should_neutralize' ) && brikpanel_access_should_neutralize();
}

/**
 * The weight of one unit of a line's product: 0 when it has none, null when
 * the product does not ship and so takes no part.
 *
 * A variation without a weight of its own has its main product's
 * (WC_Product_Variation::get_weight). A variation deleted since the order was
 * placed falls back to its main product too; a product deleted outright has
 * no weight left to tell.
 *
 * The answer is remembered by product for the rest of the request, but only
 * while no plugin hands an order line a product of its own: lines with the
 * same product could then weigh differently.
 *
 * @param WC_Order_Item_Product $item Line.
 * @return float|null
 */
function brikpanel_order_weight_of_item( $item ) {
	$remember = ! has_filter( 'woocommerce_order_item_product' ) && ! has_filter( 'woocommerce_get_product_from_item' );
	$key      = (int) $item->get_product_id() . ':' . (int) $item->get_variation_id();
	$memo     = &brikpanel_order_weight_unit_memo();
	if ( $remember && array_key_exists( $key, $memo ) ) {
		return $memo[ $key ];
	}

	$unit    = 0.0;
	$product = $item->get_product();
	if ( ! $product instanceof WC_Product && $item->get_variation_id() ) {
		$product = wc_get_product( $item->get_product_id() );
	}
	if ( $product instanceof WC_Product ) {
		if ( ! $product->needs_shipping() ) {
			$unit = null;
		} else {
			// wc_format_decimal() reads a weight typed with the store's decimal comma.
			$weight = (float) wc_format_decimal( $product->get_weight() );
			$unit   = $weight > 0 ? $weight : 0.0;
		}
	}
	if ( $remember ) {
		$memo[ $key ] = $unit;
	}
	return $unit;
}

/**
 * An order's product lines that have a weight, their total, and how many
 * lines that ship have none, remembered for the rest of the request.
 *
 * @param WC_Abstract_Order|int $order Order or its ID.
 * @return array{lines: array<int, array{unit: float, qty: float, weight: float}>, total: float, missing: int}
 */
function brikpanel_order_weight_summary( $order ) {
	$memo = &brikpanel_order_weight_memo();
	$id   = $order instanceof WC_Abstract_Order ? (int) $order->get_id() : absint( $order );
	if ( $id && isset( $memo[ $id ] ) ) {
		return $memo[ $id ];
	}
	if ( ! $order instanceof WC_Abstract_Order ) {
		$order = $id ? wc_get_order( $id ) : null;
	}
	if ( ! $order instanceof WC_Abstract_Order ) {
		return brikpanel_order_weight_compute( null );
	}

	$summary = brikpanel_order_weight_compute( $order );
	if ( $id ) {
		$memo[ $id ] = $summary;
	}
	return $summary;
}

/**
 * The same as brikpanel_order_weight_summary(), worked out afresh: the orders
 * export runs it over thousands of orders and must not keep each one.
 *
 * Products are read as they are now: WooCommerce keeps no weight on the
 * order. Quantities are the ones ordered, refunds included, the way Items
 * Subtotal counts them.
 *
 * @param WC_Abstract_Order|null $order Order; anything else has no weight.
 * @return array{lines: array<int, array{unit: float, qty: float, weight: float}>, total: float, missing: int}
 */
function brikpanel_order_weight_compute( $order ) {
	$summary = array(
		'lines'   => array(),
		'total'   => 0.0,
		'missing' => 0,
	);
	if ( ! $order instanceof WC_Abstract_Order ) {
		return $summary;
	}

	foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
		if ( ! $item instanceof WC_Order_Item_Product ) {
			continue;
		}
		$unit = brikpanel_order_weight_of_item( $item );
		if ( null === $unit ) {
			continue;
		}
		if ( $unit <= 0 ) {
			++$summary['missing'];
			continue;
		}
		$qty    = (float) $item->get_quantity();
		$weight = $unit * $qty;

		$summary['lines'][ (int) $item_id ] = array(
			'unit'   => $unit,
			'qty'    => $qty,
			'weight' => $weight,
		);
		$summary['total'] += $weight;
	}
	return $summary;
}

/**
 * The store's unit of weight as WooCommerce labels it, in WooCommerce's own
 * translations (Russian "кг"); the unit's code on versions without labels.
 * The orders export puts it in its column header.
 *
 * @return string
 */
function brikpanel_order_weight_unit_label() {
	$unit = (string) get_option( 'woocommerce_weight_unit', 'kg' );
	$i18n = '\Automattic\WooCommerce\Utilities\I18nUtil';
	if ( class_exists( $i18n ) && method_exists( $i18n, 'get_weight_unit_label' ) ) {
		return (string) $i18n::get_weight_unit_label( $unit );
	}
	return $unit;
}

/**
 * A weight in the store's unit with the store's number marks: "0.36 kg",
 * "1.234,5 g". Up to three decimals, trailing zeros dropped.
 *
 * @param float $value Weight.
 * @return string
 */
function brikpanel_order_weight_text( $value ) {
	return brikpanel_safe_sprintf(
		/* translators: A weight, e.g. "0.36 kg". 1: the number, already formatted. 2: the unit of weight (kg, g, lbs or oz). */
		_x( '%1$s %2$s', 'weight value and unit', 'brikpanel' ),
		brikpanel_number( $value, 3, true ),
		brikpanel_order_weight_unit_label()
	);
}

/**
 * Under a product line's name, after its SKU and variation ID: its weight.
 *
 * @param int           $item_id Line ID.
 * @param WC_Order_Item $item    Line (shipping lines run this hook too).
 * @param mixed         $product The line's product, unused: a deleted variation still has a main product to ask.
 */
function brikpanel_order_weight_item_line( $item_id, $item, $product = null ) {
	unset( $product );
	if ( ! $item instanceof WC_Order_Item_Product || brikpanel_order_weight_neutralized() ) {
		return;
	}
	$summary = brikpanel_order_weight_summary( $item->get_order_id() );
	if ( ! isset( $summary['lines'][ (int) $item_id ] ) ) {
		return;
	}
	$line = $summary['lines'][ (int) $item_id ];

	// Each piece isolated: a right-to-left screen keeps "0.12 kg" in one piece,
	// and an untranslated "Weight:" keeps its colon at the end.
	$isolate = static function ( $text ) {
		return '<bdi>' . esc_html( $text ) . '</bdi>';
	};
	if ( abs( $line['qty'] - 1.0 ) < 0.0005 ) {
		$value = $isolate( brikpanel_order_weight_text( $line['unit'] ) );
	} else {
		$value = brikpanel_safe_sprintf(
			/* translators: A product line's weight, e.g. "0.12 kg × 3 = 0.36 kg". 1: the weight of one item. 2: the quantity. 3: the weight of the whole line. All three already formatted. */
			esc_html__( '%1$s × %2$s = %3$s', 'brikpanel' ),
			$isolate( brikpanel_order_weight_text( $line['unit'] ) ),
			$isolate( brikpanel_number( $line['qty'], 3, true ) ),
			$isolate( brikpanel_order_weight_text( $line['weight'] ) )
		);
	}

	printf(
		'<div class="brikpanel-order-item-weight"><strong>%1$s</strong> %2$s</div>',
		$isolate( __( 'Weight:', 'brikpanel' ) ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
		$value // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- every part is escaped above.
	);
}

/**
 * At the end of the totals, in the table WooCommerce leaves for this hook: the
 * order's total weight and, when products that ship have none, how many.
 *
 * @param int $order_id Order.
 */
function brikpanel_order_weight_total_rows( $order_id ) {
	if ( brikpanel_order_weight_neutralized() ) {
		return;
	}
	$summary = brikpanel_order_weight_summary( $order_id );
	if ( empty( $summary['lines'] ) ) {
		return;
	}
	// Isolated like the line above: an untranslated sentence on a right-to-left
	// screen would otherwise read "products have no weight 2".
	?>
	<tr class="brikpanel-order-weight-total">
		<td class="label"><bdi><?php esc_html_e( 'Total weight:', 'brikpanel' ); ?></bdi></td>
		<td width="1%"></td>
		<td class="total"><span class="brikpanel-order-weight-value"><bdi><?php echo esc_html( brikpanel_order_weight_text( $summary['total'] ) ); ?></bdi></span></td>
	</tr>
	<?php
	if ( $summary['missing'] > 0 ) {
		$note = brikpanel_safe_sprintf(
			/* translators: %s: how many products in the order have no weight, already formatted. */
			_n( '%s product has no weight', '%s products have no weight', $summary['missing'], 'brikpanel' ),
			brikpanel_number( $summary['missing'] )
		);
		?>
		<tr class="brikpanel-order-weight-note">
			<td><span class="description"><bdi><?php echo esc_html( $note ); ?></bdi></span></td>
			<td colspan="2"></td>
		</tr>
		<?php
	}
}

/**
 * The weight line's look, on the order edit and new order screens. A file of
 * its own: BrikPanel's order styles only load with its order screen on, and
 * the weights show without it too.
 */
function brikpanel_order_weight_enqueue() {
	if ( ! function_exists( 'brikpanel_order_screen_context' ) || ! brikpanel_order_screen_context() ) {
		return;
	}
	if ( brikpanel_order_weight_neutralized() ) {
		return;
	}
	$file = 'front-end/order/brikpanel-order-weight.css';
	$ver  = @filemtime( BRIKPANEL_PATH . $file ) ?: BRIKPANEL_VERSION; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a missing file falls back to the plugin version.
	wp_enqueue_style( 'brikpanel-order-weight', BRIKPANEL_URL . $file, array(), $ver );
}
