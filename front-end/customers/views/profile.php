<?php
/**
 * BrikPanel Customers: one customer's page (?customer=u:<id> or e:<email>).
 *
 * Two columns: what the customer did (orders, products, abandoned carts) on
 * the left, who they are (contact, addresses, email marketing, coupons) on
 * the right; one column on a phone. Rendered by Brikpanel_Customers::render_page()
 * with $profile from Brikpanel_Customer_Profile::load() (null: not found).
 *
 * @package BrikPanel
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$bp_cpr_back = class_exists( 'Brikpanel_Customers' ) ? Brikpanel_Customers::url() : admin_url( 'admin.php?page=brikpanel-customers' );

if ( empty( $profile ) ) : ?>
	<div class="wrap brikpanel-shell__page">
		<div class="bp-cpr" id="bp-cpr">
			<a class="bp-cpr-back" href="<?php echo esc_url( $bp_cpr_back ); ?>"><span aria-hidden="true">&larr;</span> <?php esc_html_e( 'Customers', 'brikpanel' ); ?></a>
			<div class="bp-cpr-header" id="bp-cpr-header">
				<div class="bp-cpr-header-left"><h1><?php esc_html_e( 'Customer not found', 'brikpanel' ); ?></h1></div>
			</div>
			<?php brikpanel_header_end(); ?>
			<div class="bp-cpr-card">
				<p class="bp-cpr-note"><?php esc_html_e( 'This customer has no account or order in this store.', 'brikpanel' ); ?></p>
			</div>
		</div>
	</div>
	<?php
	return;
endif;

$bp_cpr_stats = $profile['stats'];
$bp_cpr_fit   = [
	'title'  => 'h1',
	'lines'  => [ '' ],
	'levels' => [
		'',
		'is-fold',
		[ 'cls' => 'is-fold is-two-rows', 'lines' => [ '.bp-cpr-header-right' ] ],
	],
];
$bp_cpr_days = '';
if ( '' !== $bp_cpr_stats['days_since_last'] ) {
	$bp_cpr_days = 0 === (int) $bp_cpr_stats['days_since_last']
		? __( 'Today', 'brikpanel' )
		/* translators: %s: number of days. */
		: sprintf( _n( '%s day ago', '%s days ago', (int) $bp_cpr_stats['days_since_last'], 'brikpanel' ), brikpanel_number( (int) $bp_cpr_stats['days_since_last'] ) );
}
$bp_cpr_place = trim( implode( ', ', array_filter( [ (string) $profile['city'], (string) ( $profile['country'] && function_exists( 'WC' ) && isset( WC()->countries->countries[ $profile['country'] ] ) ? WC()->countries->countries[ $profile['country'] ] : $profile['country'] ) ] ) ) );
$bp_cpr_address_tags = [ 'br' => [] ];
?>
<div class="wrap brikpanel-shell__page">
	<div class="bp-cpr" id="bp-cpr">

		<a class="bp-cpr-back" href="<?php echo esc_url( $bp_cpr_back ); ?>"><span aria-hidden="true">&larr;</span> <?php esc_html_e( 'Customers', 'brikpanel' ); ?></a>

		<div class="bp-cpr-header" id="bp-cpr-header" data-bp-fit-row="<?php echo esc_attr( wp_json_encode( $bp_cpr_fit ) ); ?>">
			<?php wp_print_inline_script_tag( 'if(window.brikpanelFitRow){window.brikpanelFitRow.auto(document.getElementById("bp-cpr-header"));}' ); ?>
			<div class="bp-cpr-header-left">
				<h1><?php echo esc_html( $profile['name'] ); ?></h1>
				<span class="brikpanel-badge bp-cpr-badge"><?php echo $profile['is_guest'] ? esc_html__( 'Guest', 'brikpanel' ) : esc_html__( 'Registered', 'brikpanel' ); ?></span>
				<?php if ( '' !== $profile['rfm']['label'] ) : ?>
					<span class="brikpanel-badge bp-cpr-badge"><?php echo esc_html( $profile['rfm']['label'] ); ?></span>
				<?php endif; ?>
			</div>
			<div class="bp-cpr-header-right">
				<div class="brikpanel-overflow">
					<?php
					if ( function_exists( 'brikpanel_overflow_trigger' ) ) {
						brikpanel_overflow_trigger( 'bp-cpr-more-menu' );
					}
					?>
					<div class="brikpanel-overflow__menu" id="bp-cpr-more-menu">
						<?php if ( '' !== $profile['email'] ) : ?>
							<a class="brikpanel-btn brikpanel-btn--secondary" href="<?php echo esc_url( 'mailto:' . $profile['email'] ); ?>"><?php esc_html_e( 'Email', 'brikpanel' ); ?></a>
						<?php endif; ?>
						<?php if ( '' !== $profile['whatsapp'] ) : ?>
							<a class="brikpanel-btn brikpanel-btn--secondary" href="<?php echo esc_url( $profile['whatsapp'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'WhatsApp', 'brikpanel' ); ?></a>
						<?php endif; ?>
						<?php if ( '' !== $profile['edit_user_url'] ) : ?>
							<a class="brikpanel-btn brikpanel-btn--secondary" href="<?php echo esc_url( $profile['edit_user_url'] ); ?>"><?php esc_html_e( 'WordPress profile', 'brikpanel' ); ?></a>
						<?php endif; ?>
					</div>
				</div>
				<a class="brikpanel-btn brikpanel-btn--primary" href="<?php echo esc_url( $profile['orders_url'] ); ?>"><?php esc_html_e( 'View orders', 'brikpanel' ); ?></a>
			</div>
		</div>
		<?php brikpanel_header_end(); ?>

		<div class="bp-cpr-stats" data-bp-tiles>
			<div class="bp-cpr-stat">
				<div class="bp-cpr-stat-label"><?php esc_html_e( 'Total spent', 'brikpanel' ); ?></div>
				<div class="bp-cpr-stat-value"><?php echo wp_kses_post( wc_price( $bp_cpr_stats['total_spent'] ) ); ?></div>
				<div class="bp-cpr-stat-sub">
					<?php
					/* translators: %s: number of paid orders. */
					echo esc_html( sprintf( _n( '%s paid order', '%s paid orders', $bp_cpr_stats['order_count'], 'brikpanel' ), brikpanel_number( $bp_cpr_stats['order_count'] ) ) );
					?>
				</div>
			</div>
			<div class="bp-cpr-stat">
				<div class="bp-cpr-stat-label"><?php esc_html_e( 'Orders', 'brikpanel' ); ?></div>
				<div class="bp-cpr-stat-value"><?php echo esc_html( brikpanel_number( $bp_cpr_stats['order_count'] ) ); ?></div>
				<div class="bp-cpr-stat-sub">
					<?php
					if ( $bp_cpr_stats['other_count'] > 0 ) {
						/* translators: %s: number of orders not paid (pending, failed, cancelled...). */
						echo esc_html( sprintf( _n( '+%s not paid', '+%s not paid', $bp_cpr_stats['other_count'], 'brikpanel' ), brikpanel_number( $bp_cpr_stats['other_count'] ) ) );
					} else {
						echo '&nbsp;';
					}
					?>
				</div>
			</div>
			<div class="bp-cpr-stat">
				<div class="bp-cpr-stat-label"><?php esc_html_e( 'Average order value', 'brikpanel' ); ?></div>
				<div class="bp-cpr-stat-value"><?php echo wp_kses_post( wc_price( $bp_cpr_stats['aov'] ) ); ?></div>
				<div class="bp-cpr-stat-sub">&nbsp;</div>
			</div>
			<div class="bp-cpr-stat">
				<div class="bp-cpr-stat-label"><?php esc_html_e( 'Last order', 'brikpanel' ); ?></div>
				<div class="bp-cpr-stat-value"><?php echo esc_html( '' !== $bp_cpr_stats['last_order'] ? brikpanel_local_date( $bp_cpr_stats['last_order'] ) : '—' ); ?></div>
				<div class="bp-cpr-stat-sub"><?php echo '' !== $bp_cpr_days ? esc_html( $bp_cpr_days ) : '&nbsp;'; ?></div>
			</div>
		</div>

		<div class="bp-cpr-cols">
			<div class="bp-cpr-main">

				<section class="bp-cpr-card" aria-labelledby="bp-cpr-orders-title">
					<h2 class="bp-cpr-card-title" id="bp-cpr-orders-title"><?php esc_html_e( 'Orders', 'brikpanel' ); ?></h2>
					<?php if ( $profile['orders'] ) : ?>
						<table class="bp-cpr-table brikpanel-fit-table" id="bp-cpr-orders">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Order', 'brikpanel' ); ?></th>
									<th><?php esc_html_e( 'Date', 'brikpanel' ); ?></th>
									<th><?php esc_html_e( 'Status', 'brikpanel' ); ?></th>
									<th class="bp-cpr-num"><?php esc_html_e( 'Items', 'brikpanel' ); ?></th>
									<th class="bp-cpr-num"><?php esc_html_e( 'Total', 'brikpanel' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $profile['orders'] as $bp_cpr_order ) : ?>
									<tr>
										<td class="brikpanel-fit-lead"><a class="bp-cpr-link" href="<?php echo esc_url( $bp_cpr_order['edit_url'] ); ?>">#<?php echo esc_html( $bp_cpr_order['number'] ); ?></a></td>
										<td><?php echo esc_html( $bp_cpr_order['date'] ); ?></td>
										<td><span class="bp-cpr-status is-<?php echo esc_attr( sanitize_html_class( $bp_cpr_order['status'] ) ); ?>"><?php echo esc_html( $bp_cpr_order['status_label'] ); ?></span></td>
										<td class="bp-cpr-num"><?php echo esc_html( brikpanel_number( $bp_cpr_order['items'] ) ); ?></td>
										<td class="bp-cpr-num brikpanel-fit-headline"><?php echo wp_kses_post( $bp_cpr_order['total_html'] ); ?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
						<?php if ( $profile['orders_total'] > count( $profile['orders'] ) ) : ?>
							<a class="bp-cpr-more" href="<?php echo esc_url( $profile['orders_url'] ); ?>">
								<?php
								/* translators: %s: number of orders. */
								echo esc_html( sprintf( _n( 'View all %s order', 'View all %s orders', $profile['orders_total'], 'brikpanel' ), brikpanel_number( $profile['orders_total'] ) ) );
								?>
								<span aria-hidden="true">&rarr;</span>
							</a>
						<?php endif; ?>
					<?php else : ?>
						<p class="bp-cpr-note"><?php esc_html_e( 'No orders yet.', 'brikpanel' ); ?></p>
					<?php endif; ?>
				</section>

				<section class="bp-cpr-card" aria-labelledby="bp-cpr-products-title">
					<h2 class="bp-cpr-card-title" id="bp-cpr-products-title"><?php esc_html_e( 'Products bought', 'brikpanel' ); ?></h2>
					<?php if ( $profile['products'] ) : ?>
						<ul class="bp-cpr-list">
							<?php foreach ( $profile['products'] as $bp_cpr_product ) : ?>
								<li>
									<?php if ( '' !== $bp_cpr_product['url'] ) : ?>
										<a class="bp-cpr-link" href="<?php echo esc_url( $bp_cpr_product['url'] ); ?>"><?php echo esc_html( $bp_cpr_product['name'] ); ?></a>
									<?php else : ?>
										<span><?php echo esc_html( $bp_cpr_product['name'] ); ?></span>
									<?php endif; ?>
									<span class="bp-cpr-list-value">
										<?php
										/* translators: %s: quantity bought. */
										echo esc_html( sprintf( _x( '× %s', 'quantity bought', 'brikpanel' ), brikpanel_number( $bp_cpr_product['qty'] ) ) );
										?>
									</span>
								</li>
							<?php endforeach; ?>
						</ul>
					<?php else : ?>
						<p class="bp-cpr-note"><?php esc_html_e( 'Nothing bought in a paid order yet.', 'brikpanel' ); ?></p>
					<?php endif; ?>
				</section>

				<?php if ( $profile['carts'] ) : ?>
					<section class="bp-cpr-card" aria-labelledby="bp-cpr-carts-title">
						<h2 class="bp-cpr-card-title" id="bp-cpr-carts-title"><?php esc_html_e( 'Abandoned carts', 'brikpanel' ); ?></h2>
						<ul class="bp-cpr-list">
							<?php foreach ( $profile['carts'] as $bp_cpr_cart ) : ?>
								<li>
									<span>
										<?php echo esc_html( $bp_cpr_cart['date'] ); ?>
										<span class="bp-cpr-muted">·
										<?php
										/* translators: %s: number of items in the cart. */
										echo esc_html( sprintf( _n( '%s item', '%s items', $bp_cpr_cart['items'], 'brikpanel' ), brikpanel_number( $bp_cpr_cart['items'] ) ) );
										?>
										</span>
									</span>
									<span class="bp-cpr-list-value">
										<span class="bp-cpr-status is-cart-<?php echo esc_attr( sanitize_html_class( $bp_cpr_cart['status'] ) ); ?>"><?php echo esc_html( $bp_cpr_cart['status_label'] ); ?></span>
										<?php echo wp_kses_post( $bp_cpr_cart['total_html'] ); ?>
									</span>
								</li>
							<?php endforeach; ?>
						</ul>
					</section>
				<?php endif; ?>
			</div>

			<div class="bp-cpr-side">
				<section class="bp-cpr-card" aria-labelledby="bp-cpr-contact-title">
					<h2 class="bp-cpr-card-title" id="bp-cpr-contact-title"><?php esc_html_e( 'Contact', 'brikpanel' ); ?></h2>
					<dl class="bp-cpr-dl">
						<?php if ( '' !== $profile['email'] ) : ?>
							<dt><?php esc_html_e( 'Email', 'brikpanel' ); ?></dt>
							<dd><span dir="ltr"><?php echo esc_html( $profile['email'] ); ?></span></dd>
						<?php endif; ?>
						<?php if ( '' !== $profile['phone'] ) : ?>
							<dt><?php esc_html_e( 'Phone', 'brikpanel' ); ?></dt>
							<dd><span dir="ltr"><?php echo esc_html( $profile['phone'] ); ?></span></dd>
						<?php endif; ?>
						<?php if ( '' !== $bp_cpr_place ) : ?>
							<dt><?php esc_html_e( 'Location', 'brikpanel' ); ?></dt>
							<dd><?php echo esc_html( $bp_cpr_place ); ?></dd>
						<?php endif; ?>
						<?php if ( '' !== $profile['since'] ) : ?>
							<dt><?php esc_html_e( 'Customer since', 'brikpanel' ); ?></dt>
							<dd><?php echo esc_html( brikpanel_local_date( $profile['since'] ) ); ?></dd>
						<?php endif; ?>
						<?php if ( '' !== $profile['username'] ) : ?>
							<dt><?php esc_html_e( 'Account', 'brikpanel' ); ?></dt>
							<dd><?php echo esc_html( $profile['username'] ); ?></dd>
						<?php endif; ?>
					</dl>
				</section>

				<?php if ( '' !== trim( wp_strip_all_tags( $profile['billing'] ) ) || '' !== trim( wp_strip_all_tags( $profile['shipping'] ) ) ) : ?>
					<section class="bp-cpr-card" aria-labelledby="bp-cpr-address-title">
						<h2 class="bp-cpr-card-title" id="bp-cpr-address-title"><?php esc_html_e( 'Addresses', 'brikpanel' ); ?></h2>
						<?php if ( '' !== trim( wp_strip_all_tags( $profile['billing'] ) ) ) : ?>
							<p class="bp-cpr-address"><strong><?php esc_html_e( 'Billing', 'brikpanel' ); ?></strong><?php echo wp_kses( $profile['billing'], $bp_cpr_address_tags ); ?></p>
						<?php endif; ?>
						<?php if ( '' !== trim( wp_strip_all_tags( $profile['shipping'] ) ) ) : ?>
							<p class="bp-cpr-address"><strong><?php esc_html_e( 'Shipping', 'brikpanel' ); ?></strong><?php echo wp_kses( $profile['shipping'], $bp_cpr_address_tags ); ?></p>
						<?php endif; ?>
					</section>
				<?php endif; ?>

				<?php if ( is_array( $profile['brikmentor'] ) ) : ?>
					<section class="bp-cpr-card" aria-labelledby="bp-cpr-marketing-title">
						<h2 class="bp-cpr-card-title" id="bp-cpr-marketing-title"><?php esc_html_e( 'Email marketing', 'brikpanel' ); ?></h2>
						<?php if ( 'subscribed' === $profile['brikmentor']['status'] ) : ?>
							<span class="brikpanel-badge bp-cpr-badge is-good"><?php esc_html_e( 'Subscribed', 'brikpanel' ); ?></span>
						<?php elseif ( 'unsubscribed' === $profile['brikmentor']['status'] ) : ?>
							<span class="brikpanel-badge bp-cpr-badge"><?php esc_html_e( 'Unsubscribed', 'brikpanel' ); ?></span>
						<?php else : ?>
							<span class="brikpanel-badge bp-cpr-badge"><?php esc_html_e( 'Not on the email list', 'brikpanel' ); ?></span>
						<?php endif; ?>
						<?php if ( '' !== $profile['brikmentor']['consent_date'] && 'none' !== $profile['brikmentor']['status'] ) : ?>
							<p class="bp-cpr-note">
								<?php
								/* translators: %s: date the customer agreed to receive emails. */
								echo esc_html( sprintf( __( 'Since %s.', 'brikpanel' ), brikpanel_local_date( $profile['brikmentor']['consent_date'] ) ) );
								?>
							</p>
						<?php endif; ?>
						<p class="bp-cpr-note">
							<?php
							if ( $profile['brikmentor']['orders'] > 0 ) {
								$bp_cpr_money = [];
								foreach ( $profile['brikmentor']['revenue'] as $bp_cpr_currency => $bp_cpr_amount ) {
									$bp_cpr_money[] = wp_strip_all_tags( wc_price( (float) $bp_cpr_amount, [ 'currency' => (string) $bp_cpr_currency ] ) );
								}
								echo esc_html( sprintf(
									/* translators: 1: number of orders, 2: their total (one amount per currency, comma separated). */
									_n( 'BrikMentor emails brought %1$s order, %2$s.', 'BrikMentor emails brought %1$s orders, %2$s.', $profile['brikmentor']['orders'], 'brikpanel' ),
									brikpanel_number( $profile['brikmentor']['orders'] ),
									implode( ', ', $bp_cpr_money )
								) );
							} else {
								esc_html_e( 'No orders from BrikMentor emails yet.', 'brikpanel' );
							}
							?>
						</p>
					</section>
				<?php endif; ?>

				<section class="bp-cpr-card" aria-labelledby="bp-cpr-coupons-title">
					<h2 class="bp-cpr-card-title" id="bp-cpr-coupons-title"><?php esc_html_e( 'Coupons used', 'brikpanel' ); ?></h2>
					<?php if ( $profile['coupons'] ) : ?>
						<ul class="bp-cpr-list">
							<?php foreach ( $profile['coupons'] as $bp_cpr_coupon ) : ?>
								<li>
									<code class="bp-cpr-code" dir="ltr"><?php echo esc_html( $bp_cpr_coupon['code'] ); ?></code>
									<span class="bp-cpr-list-value">
										<?php
										/* translators: %s: how many times the coupon was used. */
										echo esc_html( sprintf( _x( '× %s', 'times used', 'brikpanel' ), brikpanel_number( $bp_cpr_coupon['uses'] ) ) );
										?>
									</span>
								</li>
							<?php endforeach; ?>
						</ul>
					<?php else : ?>
						<p class="bp-cpr-note"><?php esc_html_e( 'No coupons used.', 'brikpanel' ); ?></p>
					<?php endif; ?>
				</section>
			</div>
		</div>
	</div>
</div>
