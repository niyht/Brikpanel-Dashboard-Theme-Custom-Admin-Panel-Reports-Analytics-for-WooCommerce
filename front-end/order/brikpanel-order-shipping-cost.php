<?php
/**
 * BrikPanel — per-order shipping cost.
 *
 * WooCommerce records what the customer was CHARGED for delivery, never what
 * the merchant paid the courier. The Profit section falls back to the charged
 * amount, which makes shipping profit-neutral; this panel is where a merchant
 * puts the real figure for an order that differs — most importantly an order
 * shipped free of charge, where nothing was charged and so nothing would
 * otherwise be deducted.
 *
 * Stored on the order as BRIKPANEL_SHIPPING_COST_META in the ORDER's currency,
 * matching every other money field on this screen; the Profit query converts it
 * to the store currency alongside the rest of the order.
 *
 * Only rendered when the merchant has opted into shipping costs
 * (brikpanel_shipping_cost_enabled), so stores that never use the feature never
 * see the box.
 *
 * @package BrikPanel
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Guard for every entry point here: the feature must be on, WooCommerce loaded
 * and the profit module (which owns the meta key) available.
 *
 * @return bool
 */
function brikpanel_order_shipping_cost_active() {
	return function_exists( 'brikpanel_shipping_cost_enabled' )
		&& defined( 'BRIKPANEL_SHIPPING_COST_META' )
		&& brikpanel_shipping_cost_enabled();
}

/**
 * Resolve the order behind the current screen. HPOS hands the order object
 * straight to the metabox callbacks; the legacy post screen hands a WP_Post.
 *
 * @param mixed $context Order object or WP_Post.
 * @return WC_Abstract_Order|null
 */
function brikpanel_order_shipping_cost_resolve( $context ) {
	if ( $context instanceof WC_Abstract_Order ) {
		return $context;
	}
	if ( $context instanceof WP_Post && function_exists( 'wc_get_order' ) ) {
		$order = wc_get_order( $context->ID );
		return $order instanceof WC_Abstract_Order ? $order : null;
	}
	return null;
}

/**
 * Register the box on the single-order screen.
 *
 * @param string $screen_or_type Screen id (HPOS) or post type (legacy).
 * @param mixed  $context        Order object (HPOS) or WP_Post (legacy).
 */
function brikpanel_order_shipping_cost_register_metabox( $screen_or_type, $context ) {
	if ( ! brikpanel_order_shipping_cost_active() ) {
		return;
	}
	if ( ! current_user_can( 'edit_shop_orders' ) ) {
		return;
	}
	if ( ! brikpanel_order_shipping_cost_resolve( $context ) ) {
		return;
	}

	add_meta_box(
		'brikpanel_order_shipping_cost',
		__( 'Shipping cost', 'brikpanel' ),
		'brikpanel_order_shipping_cost_render_metabox',
		$screen_or_type,
		'side',
		'default'
	);
}
add_action( 'add_meta_boxes', 'brikpanel_order_shipping_cost_register_metabox', 41, 2 );

/**
 * Render the box: what the customer paid (read-only context) and the field for
 * what the courier actually cost.
 *
 * @param mixed $context Order object (HPOS) or WP_Post (legacy).
 */
function brikpanel_order_shipping_cost_render_metabox( $context ) {
	$order = brikpanel_order_shipping_cost_resolve( $context );
	if ( ! $order ) {
		return;
	}

	$charged  = (float) $order->get_shipping_total();
	$stored   = $order->get_meta( BRIKPANEL_SHIPPING_COST_META );
	$currency = $order->get_currency();
	$symbol   = function_exists( 'get_woocommerce_currency_symbol' )
		? html_entity_decode( get_woocommerce_currency_symbol( $currency ), ENT_QUOTES, 'UTF-8' )
		: $currency;

	wp_nonce_field( 'brikpanel_order_shipping_cost', 'brikpanel_order_shipping_cost_nonce' );
	?>
	<div class="brikpanel-osc">
		<p class="brikpanel-osc-charged">
			<span><?php esc_html_e( 'Charged to customer', 'brikpanel' ); ?></span>
			<strong><?php echo wp_kses_post( wc_price( $charged, [ 'currency' => $currency ] ) ); ?></strong>
		</p>

		<label class="brikpanel-osc-label" for="brikpanel_order_shipping_cost_field">
			<?php esc_html_e( 'Paid to the courier', 'brikpanel' ); ?>
		</label>
		<div class="brikpanel-osc-input-group">
			<span class="brikpanel-osc-prefix"><?php echo esc_html( $symbol ); ?></span>
			<input
				type="number"
				step="0.01"
				min="0"
				inputmode="decimal"
				id="brikpanel_order_shipping_cost_field"
				name="brikpanel_order_shipping_cost"
				value="<?php echo esc_attr( '' === $stored || null === $stored ? '' : (string) $stored ); ?>"
				placeholder="<?php echo esc_attr( wc_format_localized_price( $charged ) ); ?>"
				autocomplete="off"
			>
		</div>
		<p class="brikpanel-osc-hint">
			<?php esc_html_e( 'Leave empty to use the amount charged to the customer. Enter 0 if this order cost you nothing to ship.', 'brikpanel' ); ?>
		</p>
	</div>
	<style>
		.brikpanel-osc-charged{display:flex;justify-content:space-between;align-items:baseline;gap:.5rem;margin:0 0 .75rem;font-size:.8125rem;color:#616161}
		.brikpanel-osc-charged strong{color:#303030}
		.brikpanel-osc-label{display:block;margin:0 0 .375rem;font-size:.8125rem;font-weight:600;color:#303030}
		.brikpanel-osc-input-group{display:flex;align-items:stretch;border:1px solid #8a8a8a;border-radius:.5rem;overflow:hidden;background:#fff}
		.brikpanel-osc-input-group:focus-within{border-color:#303030;box-shadow:0 0 0 1px #303030}
		/* border-inline-end, not border-right: in RTL the currency prefix sits on
		   the other side of the input and a physical border would land outside. */
		.brikpanel-osc-prefix{display:flex;align-items:center;padding:0 .625rem;background:#f7f7f7;border-inline-end:1px solid #e3e3e3;font-size:.875rem;color:#616161}
		.brikpanel-osc-input-group input{flex:1;min-width:0;border:0;box-shadow:none;outline:none;padding:.5rem .625rem;font-size:.875rem;background:transparent}
		.brikpanel-osc-input-group input:focus{border:0;box-shadow:none;outline:none}
		.brikpanel-osc-hint{margin:.5rem 0 0;font-size:.75rem;line-height:1.5;color:#8a8a8a}
	</style>
	<?php
}

/**
 * Persist the field.
 *
 * An empty value REMOVES the meta rather than storing '': the two mean
 * different things to the Profit query ("use what was charged" vs "this order
 * cost 0"), so a blank box must not leave a value behind that reads as zero.
 *
 * @param int $order_id Order being saved.
 */
function brikpanel_order_shipping_cost_save( $order_id ) {
	if ( ! brikpanel_order_shipping_cost_active() ) {
		return;
	}
	if ( ! isset( $_POST['brikpanel_order_shipping_cost_nonce'] ) ) {
		return; // our box was not on the submitted form (e.g. a bulk/status action)
	}
	if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['brikpanel_order_shipping_cost_nonce'] ) ), 'brikpanel_order_shipping_cost' ) ) {
		return;
	}
	if ( ! current_user_can( 'edit_shop_orders' ) ) {
		return;
	}

	$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
	if ( ! $order instanceof WC_Abstract_Order ) {
		return;
	}

	$raw = isset( $_POST['brikpanel_order_shipping_cost'] )
		? trim( sanitize_text_field( wp_unslash( $_POST['brikpanel_order_shipping_cost'] ) ) )
		: '';

	$before = (string) $order->get_meta( BRIKPANEL_SHIPPING_COST_META );

	if ( '' === $raw ) {
		$order->delete_meta_data( BRIKPANEL_SHIPPING_COST_META );
	} else {
		// Accept a comma decimal separator, which is what most non-English
		// keyboards produce, and never store a negative cost.
		$value = (float) str_replace( ',', '.', $raw );
		if ( $value < 0 ) {
			$value = 0.0;
		}
		$order->update_meta_data( BRIKPANEL_SHIPPING_COST_META, wc_format_decimal( $value, wc_get_price_decimals() ) );
	}

	$order->save();

	// This figure feeds Net profit, and the dashboard caches its whole payload
	// against order EVENTS (new order, status change, refund). Editing only this
	// box is none of those, so without an explicit bust the new cost would not
	// show up until the transient expired. Only fires on a real change, so
	// re-saving an untouched order costs nothing.
	if ( $before !== (string) $order->get_meta( BRIKPANEL_SHIPPING_COST_META )
		&& function_exists( 'brikpanel_bust_data_caches' ) ) {
		brikpanel_bust_data_caches();
	}
}
add_action( 'woocommerce_process_shop_order_meta', 'brikpanel_order_shipping_cost_save', 60 );
