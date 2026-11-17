<?php
/**
 * BrikPanel return emails (plain text).
 *
 * Override it by copying it to
 * yourtheme/woocommerce/emails/plain/brikpanel-return.php.
 *
 * @package BrikPanel
 * @since   3.3.52
 *
 * @var string   $email_heading      Heading.
 * @var string   $additional_content Text the store added in the email settings.
 * @var WC_Order $order              Order.
 * @var array    $ret                Return.
 * @var string   $intro              First paragraph.
 * @var string   $message            The store's message.
 * @var string   $view_url           Where the customer follows the return.
 * @var string   $admin_url          Where the store opens it.
 * @var string   $moment             When the request was sent.
 */

defined( 'ABSPATH' ) || exit;

echo "=====================\n";
echo esc_html( wp_strip_all_tags( $email_heading ) );
echo "\n=====================\n\n";

echo esc_html( wp_strip_all_tags( $intro ) ) . "\n\n";

if ( '' !== trim( (string) $message ) ) {
	echo esc_html( wp_strip_all_tags( $message ) ) . "\n\n";
}

if ( $order instanceof WC_Order ) {
	/* translators: 1: return number, 2: order number */
	echo esc_html( sprintf( __( 'Return #%1$s for order #%2$s', 'brikpanel' ), (int) $ret['id'], $order->get_order_number() ) ) . "\n";
	foreach ( (array) $ret['items'] as $brikpanel_line ) {
		if ( ! empty( $brikpanel_line['parent'] ) ) {
			continue;
		}
		$brikpanel_item = $order->get_item( (int) $brikpanel_line['item_id'] );
		$brikpanel_name = $brikpanel_item ? $brikpanel_item->get_name() : (string) $brikpanel_line['name'];
		$brikpanel_meta = $brikpanel_item ? wp_strip_all_tags(
			wc_display_item_meta(
				$brikpanel_item,
				array(
					'echo'      => false,
					'before'    => ' (',
					'after'     => ')',
					'separator' => ', ',
					'autop'     => false,
				)
			)
		) : '';
		/* translators: 1: units, 2: product name */
		echo esc_html( sprintf( _x( '%1$s × %2$s', 'units × product', 'brikpanel' ), (int) $brikpanel_line['qty'], $brikpanel_name ) . $brikpanel_meta ) . "\n";
	}
	echo "\n";
}

if ( ! empty( $ret['reason'] ) ) {
	echo esc_html( __( 'Reason:', 'brikpanel' ) . ' ' . $ret['reason'] ) . "\n";
}
if ( ! empty( $ret['comment'] ) ) {
	echo esc_html( __( 'Note:', 'brikpanel' ) . ' ' . $ret['comment'] ) . "\n";
}

if ( ! empty( $ret['withdrawal'] ) ) {
	$brikpanel_statement = is_array( $ret['statement'] ) ? $ret['statement'] : array();
	echo "\n";
	echo esc_html( __( 'Withdrawal statement', 'brikpanel' ) . ': ' . __( 'I withdraw from my contract for the items listed above.', 'brikpanel' ) ) . "\n";
	echo esc_html( __( 'Name', 'brikpanel' ) . ': ' . ( isset( $brikpanel_statement['name'] ) ? $brikpanel_statement['name'] : '' ) ) . "\n";
	echo esc_html( __( 'Email', 'brikpanel' ) . ': ' . ( isset( $brikpanel_statement['email'] ) ? $brikpanel_statement['email'] : '' ) ) . "\n";
	echo esc_html( __( 'Order', 'brikpanel' ) . ': #' . ( isset( $brikpanel_statement['order'] ) ? $brikpanel_statement['order'] : '' ) ) . "\n";
	if ( ! empty( $brikpanel_statement['text'] ) ) {
		echo esc_html( __( 'Message', 'brikpanel' ) . ': ' . $brikpanel_statement['text'] ) . "\n";
	}
	echo esc_html( __( 'Sent on', 'brikpanel' ) . ': ' . $moment ) . "\n";
}

if ( $view_url ) {
	echo "\n" . esc_html__( 'View your return', 'brikpanel' ) . ': ' . esc_url_raw( $view_url ) . "\n";
}
if ( $admin_url ) {
	echo "\n" . esc_html__( 'Open the return', 'brikpanel' ) . ': ' . esc_url_raw( $admin_url ) . "\n";
}

echo "\n\n----------------------------------------\n\n";

if ( $additional_content ) {
	echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) );
	echo "\n\n----------------------------------------\n\n";
}

echo wp_kses_post( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) ); // phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- WooCommerce's own filter.
