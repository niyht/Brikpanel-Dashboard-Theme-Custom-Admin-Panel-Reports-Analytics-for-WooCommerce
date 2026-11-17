<?php
/**
 * BrikPanel return emails (HTML).
 *
 * Shared by "New return request", "Return request received", "Return
 * approved" and "Return rejected". Override it by copying it to
 * yourtheme/woocommerce/emails/brikpanel-return.php.
 *
 * @package BrikPanel
 * @since   3.3.52
 *
 * @var string        $email_heading      Heading.
 * @var string        $additional_content Text the store added in the email settings.
 * @var bool          $sent_to_admin      To the store.
 * @var WC_Email      $email              Email object.
 * @var WC_Order      $order              Order.
 * @var array         $ret                Return.
 * @var string        $intro              First paragraph.
 * @var string        $message            The store's message (approved, rejected).
 * @var string        $view_url           Where the customer follows the return.
 * @var string        $admin_url          Where the store opens it.
 * @var string        $moment             When the request was sent, with the UTC offset.
 */

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_email_header', $email_heading, $email ); // phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- WooCommerce's own email hook.

$brikpanel_items = array();
if ( ! empty( $ret['items'] ) && $order instanceof WC_Order ) {
	foreach ( $ret['items'] as $brikpanel_line ) {
		if ( ! empty( $brikpanel_line['parent'] ) ) {
			continue;
		}
		$brikpanel_item    = $order->get_item( (int) $brikpanel_line['item_id'] );
		$brikpanel_items[] = array(
			'name' => $brikpanel_item ? $brikpanel_item->get_name() : (string) $brikpanel_line['name'],
			'meta' => $brikpanel_item ? wc_display_item_meta(
				$brikpanel_item,
				array(
					'echo'      => false,
					'before'    => '<br><small>',
					'after'     => '</small>',
					'separator' => ', ',
					'autop'     => false,
				)
			) : '',
			'qty'  => (int) $brikpanel_line['qty'],
		);
	}
}
$brikpanel_withdrawal = ! empty( $ret['withdrawal'] );
$brikpanel_statement  = ! empty( $ret['statement'] ) && is_array( $ret['statement'] ) ? $ret['statement'] : array();
?>

<p><?php echo esc_html( $intro ); ?></p>

<?php if ( '' !== trim( (string) $message ) ) : ?>
	<div dir="auto" style="margin:0 0 20px;padding:12px 16px;border-inline-start:3px solid #e3e3e3;">
		<?php echo wp_kses_post( wpautop( esc_html( $message ) ) ); ?>
	</div>
<?php endif; ?>

<h2>
	<?php
	if ( $order instanceof WC_Order ) {
		/* translators: 1: return number, 2: order number */
		echo esc_html( sprintf( __( 'Return #%1$s for order #%2$s', 'brikpanel' ), (int) $ret['id'], $order->get_order_number() ) );
	}
	?>
</h2>

<table class="td" cellspacing="0" cellpadding="6" border="1" style="width:100%;margin-bottom:20px;">
	<thead>
		<tr>
			<th class="td" scope="col" style="text-align:start;"><?php esc_html_e( 'Product', 'brikpanel' ); ?></th>
			<th class="td" scope="col" style="text-align:start;"><?php esc_html_e( 'Quantity', 'brikpanel' ); ?></th>
		</tr>
	</thead>
	<tbody>
		<?php foreach ( $brikpanel_items as $brikpanel_row ) : ?>
			<tr>
				<td class="td" style="text-align:start;vertical-align:middle;"><?php echo esc_html( $brikpanel_row['name'] ); ?><?php echo wp_kses_post( $brikpanel_row['meta'] ); ?></td>
				<td class="td" style="text-align:start;vertical-align:middle;"><?php echo esc_html( function_exists( 'brikpanel_number' ) ? brikpanel_number( $brikpanel_row['qty'] ) : (string) $brikpanel_row['qty'] ); ?></td>
			</tr>
		<?php endforeach; ?>
	</tbody>
</table>

<?php if ( ! empty( $ret['reason'] ) ) : ?>
	<p><strong><?php esc_html_e( 'Reason:', 'brikpanel' ); ?></strong> <?php echo esc_html( $ret['reason'] ); ?></p>
<?php endif; ?>
<?php if ( ! empty( $ret['comment'] ) ) : ?>
	<p><strong><?php esc_html_e( 'Note:', 'brikpanel' ); ?></strong> <span dir="auto"><?php echo esc_html( $ret['comment'] ); ?></span></p>
<?php endif; ?>

<?php if ( $brikpanel_withdrawal ) : ?>
	<table class="td" cellspacing="0" cellpadding="6" border="1" style="width:100%;margin-bottom:20px;">
		<tbody>
			<tr>
				<th class="td" scope="row" style="text-align:start;"><?php esc_html_e( 'Withdrawal statement', 'brikpanel' ); ?></th>
				<td class="td" style="text-align:start;"><?php esc_html_e( 'I withdraw from my contract for the items listed above.', 'brikpanel' ); ?></td>
			</tr>
			<tr>
				<th class="td" scope="row" style="text-align:start;"><?php esc_html_e( 'Name', 'brikpanel' ); ?></th>
				<td class="td" style="text-align:start;"><?php echo esc_html( isset( $brikpanel_statement['name'] ) ? $brikpanel_statement['name'] : '' ); ?></td>
			</tr>
			<tr>
				<th class="td" scope="row" style="text-align:start;"><?php esc_html_e( 'Email', 'brikpanel' ); ?></th>
				<td class="td" style="text-align:start;"><?php echo esc_html( isset( $brikpanel_statement['email'] ) ? $brikpanel_statement['email'] : '' ); ?></td>
			</tr>
			<tr>
				<th class="td" scope="row" style="text-align:start;"><?php esc_html_e( 'Order', 'brikpanel' ); ?></th>
				<td class="td" style="text-align:start;"><?php echo esc_html( isset( $brikpanel_statement['order'] ) ? '#' . $brikpanel_statement['order'] : '' ); ?></td>
			</tr>
			<?php if ( ! empty( $brikpanel_statement['text'] ) ) : ?>
				<tr>
					<th class="td" scope="row" style="text-align:start;"><?php esc_html_e( 'Message', 'brikpanel' ); ?></th>
					<td class="td" style="text-align:start;"><?php echo esc_html( $brikpanel_statement['text'] ); ?></td>
				</tr>
			<?php endif; ?>
			<tr>
				<th class="td" scope="row" style="text-align:start;"><?php esc_html_e( 'Sent on', 'brikpanel' ); ?></th>
				<td class="td" style="text-align:start;"><?php echo esc_html( $moment ); ?></td>
			</tr>
		</tbody>
	</table>
<?php endif; ?>

<?php if ( $view_url ) : ?>
	<p><a href="<?php echo esc_url( $view_url ); ?>"><?php esc_html_e( 'View your return', 'brikpanel' ); ?></a></p>
<?php endif; ?>
<?php if ( $admin_url ) : ?>
	<p><a href="<?php echo esc_url( $admin_url ); ?>"><?php esc_html_e( 'Open the return', 'brikpanel' ); ?></a></p>
<?php endif; ?>

<?php
if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

do_action( 'woocommerce_email_footer', $email ); // phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- WooCommerce's own email hook.
