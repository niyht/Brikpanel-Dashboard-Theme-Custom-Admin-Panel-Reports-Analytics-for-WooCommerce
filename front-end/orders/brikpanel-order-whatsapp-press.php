<?php
/**
 * WhatsApp follow-ups: how long the press note lives.
 *
 * The first press of a WhatsApp button on an order whose status has a
 * follow-up is noted on the order (front-end/orders/brikpanel-order-whatsapp.php);
 * later presses open the follow-up. Any status change drops the note, so an
 * order that leaves a status and comes back to it opens that status's first
 * message again.
 *
 * Loaded on every request, not only in wp-admin: payments, checkout, cron and
 * REST change statuses too. The note leaves in the same write as the status,
 * from the save lifecycle rather than woocommerce_order_status_changed, which
 * a throwing third-party status hook can keep from running.
 *
 * @package BrikPanel
 * @since   3.3.25
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const BRIKPANEL_WHATSAPP_META_PRESSED = '_brikpanel_whatsapp_pressed';

add_action( 'woocommerce_before_order_object_save', 'brikpanel_whatsapp_forget_press' );

/**
 * Drop the press note when a save changes the order's status.
 *
 * Runs before the order is written: the removal goes out with the save itself,
 * no second write. A save that leaves the status alone keeps the note.
 *
 * @param WC_Order $order
 */
function brikpanel_whatsapp_forget_press( $order ) {
	if ( ! $order instanceof WC_Order || ! $order->get_id() ) {
		return;
	}
	$changes = $order->get_changes();
	if ( ! isset( $changes['status'] ) ) {
		return;
	}
	if ( '' !== $order->get_meta( BRIKPANEL_WHATSAPP_META_PRESSED ) ) {
		$order->delete_meta_data( BRIKPANEL_WHATSAPP_META_PRESSED );
	}
}
