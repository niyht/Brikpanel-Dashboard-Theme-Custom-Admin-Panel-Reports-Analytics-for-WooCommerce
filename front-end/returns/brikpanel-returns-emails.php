<?php
/**
 * Customer returns: the emails.
 *
 * Four WooCommerce emails, listed and editable in WooCommerce > Settings >
 * Emails like WooCommerce's own:
 *
 *   New return request   to the store, when a request arrives.
 *   Return request received  to the customer: the acknowledgement. In EU mode
 *                        it is the withdrawal acknowledgement on a durable
 *                        medium (content, date and time) and cannot be
 *                        turned off for a withdrawal.
 *   Return approved      to the customer, with the store's message (for
 *                        example where to send the items).
 *   Return rejected      to the customer, with the store's reason.
 *
 * A refund needs no email of ours: WooCommerce sends its own "Refunded
 * order" email when the refund is made.
 *
 * WC_Email only exists once WooCommerce's mailer has loaded, so the classes
 * are defined inside the woocommerce_email_classes callback. The actions are
 * on woocommerce_email_actions, which makes WooCommerce load the mailer and
 * fire "<action>_notification" for us.
 *
 * @package BrikPanel
 * @since   3.3.52
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'woocommerce_email_classes', 'brikpanel_returns_email_classes' );
add_filter( 'woocommerce_email_actions', 'brikpanel_returns_email_actions' );
add_filter( 'woocommerce_email_groups', 'brikpanel_returns_email_groups' );

/**
 * @param array $emails WooCommerce email objects.
 * @return array
 */
function brikpanel_returns_email_classes( $emails ) {
	if ( ! class_exists( 'WC_Email', false ) ) {
		return $emails;
	}
	require_once __DIR__ . '/class-brikpanel-returns-emails.php';
	$emails['Brikpanel_Email_Return_New']      = new Brikpanel_Email_Return_New();
	$emails['Brikpanel_Email_Return_Received'] = new Brikpanel_Email_Return_Received();
	$emails['Brikpanel_Email_Return_Approved'] = new Brikpanel_Email_Return_Approved();
	$emails['Brikpanel_Email_Return_Rejected'] = new Brikpanel_Email_Return_Rejected();
	return $emails;
}

/**
 * @param string[] $actions Actions WooCommerce turns into emails.
 * @return string[]
 */
function brikpanel_returns_email_actions( $actions ) {
	$actions[] = 'brikpanel_return_created';
	$actions[] = 'brikpanel_return_approved';
	$actions[] = 'brikpanel_return_rejected';
	return $actions;
}

/**
 * A "Returns" group in WooCommerce's email list.
 *
 * @param array $groups Group slug => title.
 * @return array
 */
function brikpanel_returns_email_groups( $groups ) {
	$groups['brikpanel-returns'] = __( 'Returns', 'brikpanel' );
	return $groups;
}

/**
 * Where a customer follows a return from an email: the order page for an
 * account holder, a signed link for a guest.
 *
 * @param array    $ret   Return.
 * @param WC_Order $order Order.
 * @return string
 */
function brikpanel_returns_customer_url( array $ret, $order ) {
	if ( $order instanceof WC_Order && $order->get_customer_id() ) {
		return $order->get_view_order_url() . '#bp-return';
	}
	if ( ! $order instanceof WC_Order || ! function_exists( 'brikpanel_returns_link' ) ) {
		return '';
	}
	return add_query_arg(
		array(
			'order' => $order->get_id(),
			'rt'    => brikpanel_returns_token( $order, 'view' ),
		),
		brikpanel_returns_link()
	) . '#bp-return';
}

/**
 * A request's date and time for the customer: store time zone and its offset
 * to UTC, as the EU acknowledgement needs.
 *
 * @param string $utc DATETIME in UTC.
 * @return string
 */
function brikpanel_returns_format_moment( $utc ) {
	$ts = $utc ? strtotime( $utc . ' UTC' ) : false;
	if ( ! $ts ) {
		return '';
	}
	$format = function_exists( 'brikpanel_datetime_format' ) ? brikpanel_datetime_format() : get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
	/* translators: 1: date and time, 2: offset to UTC such as +03:00 */
	return sprintf( __( '%1$s (UTC%2$s)', 'brikpanel' ), wp_date( $format, $ts ), wp_date( 'P', $ts ) );
}
