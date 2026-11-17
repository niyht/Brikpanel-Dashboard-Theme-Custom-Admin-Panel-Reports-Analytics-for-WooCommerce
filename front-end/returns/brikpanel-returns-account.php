<?php
/**
 * Customer returns: the shopper's side, inside WooCommerce's My Account.
 *
 *   My Account > Orders       "Request return" (EU: "Withdraw from contract
 *                             here") next to "View"; "View return" while one
 *                             is open.
 *   My Account > View order   the order's returns, a cancel button while a
 *                             request waits, and the form (?bp-return=1).
 *   My Account ?bp-return=1   the returns link: logged out, an order number +
 *                             email form; logged in, the orders that can go
 *                             back.
 *
 * Every form posts back to the same page and is handled on template_redirect
 * (never through admin-ajax: on a multisite network BrikPanel's access gate
 * answers logged-in shoppers' brikpanel_* AJAX calls with a 403). A request
 * that succeeds redirects, so a refresh never sends it twice; the random
 * token in each form makes even a resend harmless.
 *
 * Nothing here runs outside the account pages.
 *
 * @package BrikPanel
 * @since   3.3.52
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'woocommerce_my_account_my_orders_actions', 'brikpanel_returns_order_actions', 20, 2 );
add_action( 'woocommerce_view_order', 'brikpanel_returns_view_order_section', 20 );
add_filter( 'wc_get_template', 'brikpanel_returns_swap_templates', 20, 2 );
add_action( 'template_redirect', 'brikpanel_returns_take_token', 5 );
add_action( 'template_redirect', 'brikpanel_returns_handle_post', 20 );
add_action( 'wp_enqueue_scripts', 'brikpanel_returns_account_assets' );

// =============================================================================
// WORDS
// =============================================================================

/**
 * The entry button's words.
 *
 * @return string
 */
function brikpanel_returns_entry_label() {
	return brikpanel_returns_eu() ? __( 'Withdraw from contract here', 'brikpanel' ) : __( 'Request return', 'brikpanel' );
}

/**
 * @return string Form title.
 */
function brikpanel_returns_form_title() {
	return brikpanel_returns_eu() ? __( 'Withdraw from contract', 'brikpanel' ) : __( 'Request a return', 'brikpanel' );
}

// =============================================================================
// ACCESS
// =============================================================================

/**
 * The guest token the browser carries, if any.
 *
 * @return string
 */
function brikpanel_returns_cookie_token() {
	return isset( $_COOKIE[ BRIKPANEL_RETURNS_COOKIE ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ BRIKPANEL_RETURNS_COOKIE ] ) ) : '';
}

/**
 * Whether the person in front of the screen may see and return this order:
 * its account holder, or anyone holding a valid token for it.
 *
 * @param WC_Order $order Order.
 * @return bool
 */
function brikpanel_returns_can_access( $order ) {
	if ( ! $order instanceof WC_Order || 'shop_order' !== $order->get_type() ) {
		return false;
	}
	if ( is_user_logged_in() && $order->get_customer_id() && current_user_can( 'view_order', $order->get_id() ) ) {
		return true;
	}
	$token = brikpanel_returns_cookie_token();
	return '' !== $token && false !== brikpanel_returns_token_order( $token, $order->get_id() );
}

/**
 * The page a form on this order goes back to.
 *
 * @param WC_Order $order Order.
 * @param array    $args  Extra query arguments.
 * @return string
 */
function brikpanel_returns_page_url( $order, array $args = array() ) {
	if ( is_user_logged_in() && $order->get_customer_id() && current_user_can( 'view_order', $order->get_id() ) ) {
		$base = $order->get_view_order_url();
	} else {
		$base = add_query_arg( 'order', $order->get_id(), brikpanel_returns_link() );
	}
	return add_query_arg( $args, $base );
}

/**
 * Put a guest's token in a cookie for the My Account path.
 *
 * @param string $token Token.
 * @return void
 */
function brikpanel_returns_set_cookie( $token ) {
	if ( headers_sent() ) {
		return;
	}
	$path = wp_parse_url( wc_get_page_permalink( 'myaccount' ), PHP_URL_PATH );
	$path = is_string( $path ) && '' !== $path ? $path : COOKIEPATH;
	$exp  = 0;
	$part = explode( '.', $token );
	if ( isset( $part[3] ) ) {
		$exp = (int) $part[3];
	}
	setcookie(
		BRIKPANEL_RETURNS_COOKIE,
		$token,
		array(
			'expires'  => $exp,
			'path'     => $path,
			'secure'   => is_ssl(),
			'httponly' => true,
			'samesite' => 'Lax',
		)
	);
	$_COOKIE[ BRIKPANEL_RETURNS_COOKIE ] = $token;
}

/**
 * A link from an email carries ?rt=: move it into the cookie and drop it
 * from the address, so it never stays in the history or a Referer header.
 *
 * @return void
 */
function brikpanel_returns_take_token() {
	if ( ! isset( $_GET['rt'] ) || ! is_account_page() ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a signed token, checked below.
		return;
	}
	header( 'Referrer-Policy: no-referrer' );
	$token = sanitize_text_field( wp_unslash( $_GET['rt'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$order = isset( $_GET['order'] ) ? absint( $_GET['order'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( $order && brikpanel_returns_token_order( $token, $order ) ) {
		brikpanel_returns_set_cookie( $token );
	}
	wp_safe_redirect( remove_query_arg( 'rt' ) );
	exit;
}

/**
 * Show a message on the next page. A visitor without an account has no
 * WooCommerce session until something starts one (a cart), and a message
 * added before a redirect would be lost; start the session cookie first,
 * the way WooCommerce does when a product goes into the cart.
 *
 * @param string $text Message.
 * @param string $type success|error|notice.
 * @return void
 */
function brikpanel_returns_notice( $text, $type = 'success' ) {
	if ( function_exists( 'WC' ) && WC()->session && ! WC()->session->has_session() && ! headers_sent() ) {
		WC()->session->set_customer_session_cookie( true );
	}
	wc_add_notice( $text, $type );
}

// =============================================================================
// MY ACCOUNT > ORDERS
// =============================================================================

/**
 * The current customer's returns, grouped by order, read once per request.
 *
 * @return array<int,array[]>
 */
function brikpanel_returns_customer_returns() {
	static $cache = null;
	if ( null === $cache ) {
		$cache = Brikpanel_Returns_Store::for_customer( get_current_user_id() );
	}
	return $cache;
}

/**
 * "Request return" / "View return" next to "View".
 *
 * @param array    $actions Actions.
 * @param WC_Order $order   Order.
 * @return array
 */
function brikpanel_returns_order_actions( $actions, $order ) {
	if ( ! $order instanceof WC_Order || ! is_user_logged_in() ) {
		return $actions;
	}
	// The order's own page shows the returns block with its own button; the
	// "Actions" row of its order table would only repeat it in a narrow cell.
	if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'view-order' ) ) {
		return $actions;
	}
	$mine    = brikpanel_returns_customer_returns();
	$returns = isset( $mine[ $order->get_id() ] ) ? $mine[ $order->get_id() ] : array();
	foreach ( $returns as $ret ) {
		if ( $ret['open_order_id'] ) {
			$actions['bp-return-view'] = array(
				'url'  => $order->get_view_order_url() . '#bp-return',
				'name' => __( 'View return', 'brikpanel' ),
				/* translators: %s: order number */
				'aria-label' => sprintf( __( 'View the return of order %s', 'brikpanel' ), $order->get_order_number() ),
			);
			return $actions;
		}
	}
	if ( true === brikpanel_returns_check_order( $order, null, $returns ) ) {
		$actions['bp-return'] = array(
			'url'  => add_query_arg( 'bp-return', '1', $order->get_view_order_url() ) . '#bp-return',
			'name' => brikpanel_returns_entry_label(),
			/* translators: %s: order number */
			'aria-label' => sprintf( __( 'Return items from order %s', 'brikpanel' ), $order->get_order_number() ),
		);
	}
	return $actions;
}

// =============================================================================
// MY ACCOUNT > VIEW ORDER
// =============================================================================

/**
 * @param int $order_id Order id.
 * @return void
 */
function brikpanel_returns_view_order_section( $order_id ) {
	$order = wc_get_order( $order_id );
	if ( $order && brikpanel_returns_can_access( $order ) ) {
		brikpanel_returns_render_section( $order );
	}
}

/**
 * The returns block of one order: its requests, then the entry button or the
 * form.
 *
 * @param WC_Order $order Order.
 * @return void
 */
function brikpanel_returns_render_section( $order ) {
	$returns = Brikpanel_Returns_Store::for_order( $order->get_id() );
	$check   = brikpanel_returns_check_order( $order, null, $returns );
	$asked   = isset( $_GET['bp-return'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only decides what to show.
	$review  = isset( $_GET['bp-review'] ) ? sanitize_key( wp_unslash( $_GET['bp-review'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( ! $returns && true !== $check && ! $asked ) {
		return;
	}
	echo '<section class="brikpanel-return" id="bp-return">';
	foreach ( array_reverse( $returns ) as $ret ) {
		brikpanel_returns_render_request( $order, $ret );
	}
	if ( true === $check ) {
		if ( '' !== $review && brikpanel_returns_eu() ) {
			brikpanel_returns_render_review( $order, $review );
		} elseif ( $asked ) {
			brikpanel_returns_render_form( $order );
		} else {
			echo '<p class="brikpanel-return__start"><a class="button wp-element-button" href="' . esc_url( brikpanel_returns_page_url( $order, array( 'bp-return' => '1' ) ) . '#bp-return' ) . '">' . esc_html( brikpanel_returns_entry_label() ) . '</a></p>';
		}
	} elseif ( $asked && ! $returns && is_wp_error( $check ) ) {
		echo '<p class="brikpanel-return__note">' . esc_html( $check->get_error_message() ) . '</p>';
	}
	echo '</section>';
}

/**
 * Date of a stored UTC moment in the store's format.
 *
 * @param string $utc DATETIME in UTC.
 * @return string
 */
function brikpanel_returns_date( $utc ) {
	$ts = $utc ? strtotime( $utc . ' UTC' ) : false;
	if ( ! $ts ) {
		return '';
	}
	return wp_date( function_exists( 'brikpanel_date_format' ) ? brikpanel_date_format() : get_option( 'date_format' ), $ts );
}

/**
 * One existing request as the customer sees it.
 *
 * @param WC_Order $order Order.
 * @param array    $ret   Return.
 * @return void
 */
function brikpanel_returns_render_request( $order, array $ret ) {
	$title = ! empty( $ret['withdrawal'] )
		/* translators: %s: return number */
		? sprintf( __( 'Withdrawal #%s', 'brikpanel' ), $ret['id'] )
		/* translators: %s: return number */
		: sprintf( __( 'Return #%s', 'brikpanel' ), $ret['id'] );
	?>
	<div class="brikpanel-return__card">
		<div class="brikpanel-return__head">
			<h3 class="brikpanel-return__title"><?php echo esc_html( $title ); ?></h3>
			<span class="brikpanel-return__status is-<?php echo esc_attr( $ret['status'] ); ?>"><?php echo esc_html( brikpanel_returns_status_label( $ret['status'] ) ); ?></span>
		</div>
		<p class="brikpanel-return__meta">
			<?php
			/* translators: %s: date */
			echo esc_html( sprintf( __( 'Requested on %s', 'brikpanel' ), brikpanel_returns_date( $ret['created_at'] ) ) );
			?>
		</p>
		<ul class="brikpanel-return__lines">
			<?php foreach ( $ret['items'] as $line ) : ?>
				<?php
				if ( ! empty( $line['parent'] ) ) {
					continue;
				}
				$item = $order->get_item( (int) $line['item_id'] );
				$name = $item ? $item->get_name() : (string) $line['name'];
				?>
				<li>
					<?php
					/* translators: 1: units, 2: product name */
					echo esc_html( sprintf( _x( '%1$s × %2$s', 'units × product', 'brikpanel' ), brikpanel_returns_number( (int) $line['qty'] ), $name ) );
					?>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php if ( '' !== $ret['reason'] ) : ?>
			<p class="brikpanel-return__meta"><?php echo esc_html( __( 'Reason:', 'brikpanel' ) . ' ' . $ret['reason'] ); ?></p>
		<?php endif; ?>
		<?php if ( '' !== $ret['admin_message'] && in_array( $ret['status'], array( 'approved', 'received', 'rejected', 'refunded' ), true ) ) : ?>
			<div class="brikpanel-return__message">
				<strong><?php esc_html_e( 'Message from the store', 'brikpanel' ); ?></strong>
				<div dir="auto"><?php echo wp_kses_post( wpautop( esc_html( $ret['admin_message'] ) ) ); ?></div>
			</div>
		<?php endif; ?>
		<?php if ( 'refunded' === $ret['status'] && $ret['amount'] > 0 ) : ?>
			<p class="brikpanel-return__meta">
				<?php
				/* translators: %s: refunded amount */
				echo esc_html( sprintf( __( 'Refunded: %s', 'brikpanel' ), brikpanel_returns_plain_price( $ret['amount'], $order->get_currency() ) ) );
				?>
			</p>
		<?php endif; ?>
		<?php if ( 'requested' === $ret['status'] ) : ?>
			<form method="post" class="brikpanel-return__cancel" action="<?php echo esc_url( brikpanel_returns_page_url( $order ) . '#bp-return' ); ?>">
				<input type="hidden" name="brikpanel_return_action" value="cancel">
				<input type="hidden" name="return_id" value="<?php echo esc_attr( (string) $ret['id'] ); ?>">
				<input type="hidden" name="version" value="<?php echo esc_attr( (string) $ret['version'] ); ?>">
				<?php wp_nonce_field( 'brikpanel_return_cancel_' . $ret['id'], 'brikpanel_return_nonce' ); ?>
				<button type="submit" class="brikpanel-return__link-button"><?php esc_html_e( 'Cancel this request', 'brikpanel' ); ?></button>
			</form>
		<?php endif; ?>
	</div>
	<?php
}

/**
 * Values the customer typed, kept when the form comes back with an error.
 *
 * @param string $key     Field.
 * @param mixed  $default Default.
 * @return mixed
 */
function brikpanel_returns_posted( $key, $default = '' ) {
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- only refills a form the handler already rejected.
	if ( ! isset( $_POST['brikpanel_return_action'], $_POST[ $key ] ) ) {
		return $default;
	}
	$value = wp_unslash( $_POST[ $key ] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- cleaned per type by the caller.
	return $value;
}

/**
 * The request form (EU mode: step one of the withdrawal).
 *
 * @param WC_Order $order Order.
 * @return void
 */
function brikpanel_returns_render_form( $order ) {
	$eu      = brikpanel_returns_eu();
	$lines   = array_filter(
		brikpanel_returns_lines( $order ),
		static function ( $line ) {
			return $line['max'] > 0;
		}
	);
	$reasons = brikpanel_returns_reasons();
	$photos  = $eu ? 'off' : brikpanel_returns_photos_mode();
	$posted_items = (array) brikpanel_returns_posted( 'bp_items', array() );
	$posted_qty   = (array) brikpanel_returns_posted( 'bp_qty', array() );
	$was_posted   = isset( $_POST['brikpanel_return_action'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
	$reason_now   = sanitize_text_field( (string) brikpanel_returns_posted( 'bp_reason', '' ) );
	?>
	<form method="post" enctype="multipart/form-data" class="woocommerce-form brikpanel-return__form" action="<?php echo esc_url( brikpanel_returns_page_url( $order, array( 'bp-return' => '1' ) ) . '#bp-return' ); ?>" data-bp-return-form>
		<h2 class="brikpanel-return__form-title"><?php echo esc_html( brikpanel_returns_form_title() ); ?></h2>
		<p class="brikpanel-return__lead">
			<?php
			echo esc_html(
				$eu
					? __( 'Choose the items you withdraw from. You do not have to give a reason.', 'brikpanel' )
					: __( 'Choose what you want to send back.', 'brikpanel' )
			);
			?>
		</p>

		<fieldset class="brikpanel-return__items">
			<legend class="brikpanel-return__legend"><?php esc_html_e( 'Items', 'brikpanel' ); ?></legend>
			<?php foreach ( $lines as $item_id => $line ) : ?>
				<?php
				$item    = $order->get_item( $item_id );
				$product = $item ? $item->get_product() : null;
				$checked = $was_posted ? isset( $posted_items[ $item_id ] ) : ( $eu || 1 === count( $lines ) );
				$qty_now = isset( $posted_qty[ $item_id ] ) ? (int) $posted_qty[ $item_id ] : ( $eu ? (int) $line['max'] : 1 );
				$field   = 'bp-ret-item-' . (int) $item_id;
				?>
				<div class="brikpanel-return__item">
					<input type="checkbox" class="brikpanel-return__check" id="<?php echo esc_attr( $field ); ?>" name="bp_items[<?php echo esc_attr( (string) $item_id ); ?>]" value="1" <?php checked( $checked ); ?> data-bp-return-check>
					<label class="brikpanel-return__item-label" for="<?php echo esc_attr( $field ); ?>">
						<?php if ( $product ) : ?>
							<span class="brikpanel-return__thumb"><?php echo wp_kses_post( $product->get_image( 'woocommerce_gallery_thumbnail' ) ); ?></span>
						<?php endif; ?>
						<span class="brikpanel-return__item-text">
							<span class="brikpanel-return__item-name"><?php echo esc_html( $line['name'] ); ?></span>
							<?php
							if ( $item ) {
								echo wp_kses_post(
									wc_display_item_meta(
										$item,
										array(
											'echo'         => false,
											'before'       => '<span class="brikpanel-return__item-meta">',
											'after'        => '</span>',
											'separator'    => ', ',
											'label_before' => '',
											'label_after'  => ': ',
											'autop'        => false,
										)
									)
								);
							}
							?>
						</span>
					</label>
					<label class="brikpanel-return__qty">
						<span class="screen-reader-text">
							<?php
							/* translators: %s: product name */
							echo esc_html( sprintf( __( 'Quantity of %s to return', 'brikpanel' ), $line['name'] ) );
							?>
						</span>
						<select name="bp_qty[<?php echo esc_attr( (string) $item_id ); ?>]" data-bp-return-qty>
							<?php for ( $n = 1; $n <= (int) $line['max']; $n++ ) : ?>
								<option value="<?php echo esc_attr( (string) $n ); ?>" <?php selected( $qty_now, $n ); ?>><?php echo esc_html( brikpanel_returns_number( $n ) ); ?></option>
							<?php endfor; ?>
						</select>
					</label>
				</div>
			<?php endforeach; ?>
		</fieldset>

		<?php if ( $eu ) : ?>
			<?php
			$name_now  = sanitize_text_field( (string) brikpanel_returns_posted( 'bp_name', trim( $order->get_formatted_billing_full_name() ) ) );
			$email_now = sanitize_email( (string) brikpanel_returns_posted( 'bp_email', $order->get_billing_email() ) );
			?>
			<p class="form-row form-row-wide">
				<label for="bp-ret-name"><?php esc_html_e( 'Your name', 'brikpanel' ); ?>&nbsp;<span class="required" aria-hidden="true">*</span></label>
				<input type="text" class="input-text" id="bp-ret-name" name="bp_name" value="<?php echo esc_attr( $name_now ); ?>" required autocomplete="name" maxlength="120">
			</p>
			<p class="form-row form-row-wide">
				<label for="bp-ret-email"><?php esc_html_e( 'Email for the confirmation', 'brikpanel' ); ?>&nbsp;<span class="required" aria-hidden="true">*</span></label>
				<input type="email" class="input-text" id="bp-ret-email" name="bp_email" value="<?php echo esc_attr( $email_now ); ?>" required autocomplete="email" maxlength="190">
			</p>
			<p class="form-row form-row-wide brikpanel-return__order-no">
				<?php
				/* translators: %s: order number */
				echo esc_html( sprintf( __( 'Order #%s', 'brikpanel' ), $order->get_order_number() ) );
				?>
			</p>
		<?php endif; ?>

		<fieldset class="brikpanel-return__reasons">
			<legend class="brikpanel-return__legend">
				<?php
				if ( $eu ) {
					esc_html_e( 'Reason (optional)', 'brikpanel' );
				} else {
					esc_html_e( 'Reason', 'brikpanel' );
					echo '&nbsp;<span class="required" aria-hidden="true">*</span>';
				}
				?>
			</legend>
			<?php foreach ( $reasons as $i => $reason ) : ?>
				<label class="brikpanel-return__reason">
					<input type="radio" name="bp_reason" value="<?php echo esc_attr( $reason ); ?>" <?php checked( $reason_now, $reason ); ?> <?php echo ( ! $eu && 0 === $i ) ? 'required' : ''; ?>>
					<span><?php echo esc_html( $reason ); ?></span>
				</label>
			<?php endforeach; ?>
		</fieldset>

		<p class="form-row form-row-wide">
			<label for="bp-ret-comment"><?php echo esc_html( $eu ? __( 'Message (optional)', 'brikpanel' ) : __( 'Note (optional)', 'brikpanel' ) ); ?></label>
			<textarea class="input-text" id="bp-ret-comment" name="bp_comment" rows="3" maxlength="1000" placeholder="<?php echo esc_attr( $eu ? '' : __( 'For an exchange, write the size or colour you want.', 'brikpanel' ) ); ?>"><?php echo esc_textarea( sanitize_textarea_field( (string) brikpanel_returns_posted( 'bp_comment', '' ) ) ); ?></textarea>
		</p>

		<?php if ( 'off' !== $photos ) : ?>
			<p class="form-row form-row-wide brikpanel-return__photos">
				<label for="bp-ret-photos">
					<?php
					if ( 'required' === $photos ) {
						esc_html_e( 'Photos', 'brikpanel' );
						echo '&nbsp;<span class="required" aria-hidden="true">*</span>';
					} else {
						esc_html_e( 'Photos (optional)', 'brikpanel' );
					}
					?>
				</label>
				<input type="file" id="bp-ret-photos" name="bp_photos[]" accept="image/jpeg,image/png,image/webp" multiple <?php echo 'required' === $photos ? 'required' : ''; ?> data-bp-return-photos>
				<span class="brikpanel-return__hint">
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: number of photos, 2: size such as 5 MB */
							__( 'Up to %1$s photos, %2$s each.', 'brikpanel' ),
							brikpanel_returns_number( BRIKPANEL_RETURNS_MAX_PHOTOS ),
							size_format( brikpanel_returns_photo_max_bytes() )
						)
					);
					?>
				</span>
			</p>
		<?php endif; ?>

		<input type="hidden" name="brikpanel_return_action" value="<?php echo esc_attr( $eu ? 'review' : 'submit' ); ?>">
		<input type="hidden" name="order_id" value="<?php echo esc_attr( (string) $order->get_id() ); ?>">
		<input type="hidden" name="bp_token" value="<?php echo esc_attr( (string) brikpanel_returns_posted( 'bp_token', wp_generate_password( 32, false, false ) ) ); ?>">
		<?php wp_nonce_field( 'brikpanel_return_submit_' . $order->get_id(), 'brikpanel_return_nonce' ); ?>
		<p class="brikpanel-return__actions">
			<button type="submit" class="button wp-element-button brikpanel-return__submit" data-bp-return-submit><?php echo esc_html( $eu ? __( 'Continue', 'brikpanel' ) : __( 'Send return request', 'brikpanel' ) ); ?></button>
		</p>
	</form>
	<?php
}

/**
 * EU step two: what will be sent, and the "Confirm withdrawal" button.
 *
 * @param WC_Order $order Order.
 * @param string   $key   Pending statement key.
 * @return void
 */
function brikpanel_returns_render_review( $order, $key ) {
	$pending = get_transient( 'bp_ret_pend_' . $key );
	if ( ! is_array( $pending ) || (int) $pending['order_id'] !== (int) $order->get_id() ) {
		echo '<p class="brikpanel-return__note">' . esc_html__( 'This page has expired. Please fill in the form again.', 'brikpanel' ) . '</p>';
		brikpanel_returns_render_form( $order );
		return;
	}
	$lines = brikpanel_returns_lines( $order );
	?>
	<form method="post" class="woocommerce-form brikpanel-return__form" action="<?php echo esc_url( brikpanel_returns_page_url( $order ) . '#bp-return' ); ?>" data-bp-return-form>
		<h2 class="brikpanel-return__form-title"><?php esc_html_e( 'Check and confirm your withdrawal', 'brikpanel' ); ?></h2>
		<table class="shop_table brikpanel-return__review">
			<tbody>
				<tr>
					<th scope="row"><?php esc_html_e( 'Name', 'brikpanel' ); ?></th>
					<td><?php echo esc_html( $pending['name'] ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Email', 'brikpanel' ); ?></th>
					<td><?php echo esc_html( $pending['email'] ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Order', 'brikpanel' ); ?></th>
					<td><?php echo esc_html( '#' . $order->get_order_number() ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Items', 'brikpanel' ); ?></th>
					<td>
						<?php foreach ( $pending['lines'] as $item_id => $n ) : ?>
							<?php if ( isset( $lines[ $item_id ] ) ) : ?>
								<span class="brikpanel-return__review-line">
									<?php
									/* translators: 1: units, 2: product name */
									echo esc_html( sprintf( _x( '%1$s × %2$s', 'units × product', 'brikpanel' ), brikpanel_returns_number( (int) $n ), $lines[ $item_id ]['name'] ) );
									?>
								</span>
							<?php endif; ?>
						<?php endforeach; ?>
					</td>
				</tr>
				<?php if ( '' !== $pending['reason'] ) : ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'Reason', 'brikpanel' ); ?></th>
						<td><?php echo esc_html( $pending['reason'] ); ?></td>
					</tr>
				<?php endif; ?>
				<?php if ( '' !== $pending['comment'] ) : ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'Message', 'brikpanel' ); ?></th>
						<td dir="auto"><?php echo esc_html( $pending['comment'] ); ?></td>
					</tr>
				<?php endif; ?>
			</tbody>
		</table>
		<p class="brikpanel-return__lead"><?php esc_html_e( 'I withdraw from my contract for the items listed above.', 'brikpanel' ); ?></p>
		<input type="hidden" name="brikpanel_return_action" value="confirm">
		<input type="hidden" name="order_id" value="<?php echo esc_attr( (string) $order->get_id() ); ?>">
		<input type="hidden" name="bp_pending" value="<?php echo esc_attr( $key ); ?>">
		<?php wp_nonce_field( 'brikpanel_return_confirm_' . $order->get_id(), 'brikpanel_return_nonce' ); ?>
		<p class="brikpanel-return__actions">
			<button type="submit" class="button wp-element-button brikpanel-return__submit" data-bp-return-submit><?php esc_html_e( 'Confirm withdrawal', 'brikpanel' ); ?></button>
			<a class="brikpanel-return__back" href="<?php echo esc_url( brikpanel_returns_page_url( $order, array( 'bp-return' => '1' ) ) . '#bp-return' ); ?>"><?php esc_html_e( 'Change', 'brikpanel' ); ?></a>
		</p>
	</form>
	<?php
}

// =============================================================================
// MY ACCOUNT ?bp-return=1 (the returns link)
// =============================================================================

/**
 * Show the returns page in place of the login form (logged out) or the
 * dashboard (logged in) when the address carries ?bp-return=1.
 *
 * @param string $template      Located template.
 * @param string $template_name Template name.
 * @return string
 */
function brikpanel_returns_swap_templates( $template, $template_name ) {
	if ( ! isset( $_GET['bp-return'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only decides what to show.
		return $template;
	}
	$logged = is_user_logged_in();
	if ( ( ! $logged && 'myaccount/form-login.php' === $template_name ) || ( $logged && 'myaccount/dashboard.php' === $template_name ) ) {
		return trailingslashit( BRIKPANEL_PATH ) . 'templates/myaccount/brikpanel-returns.php';
	}
	return $template;
}

/**
 * The returns page: one order (from the lookup or an email link), or the
 * lookup form and, for a logged-in customer, their returnable orders.
 *
 * @return void
 */
function brikpanel_returns_render_returns_page() {
	$order_id = isset( $_GET['order'] ) ? absint( $_GET['order'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- access is checked below.
	echo '<div class="brikpanel-return-page">';
	if ( function_exists( 'wc_print_notices' ) && ! is_user_logged_in() ) {
		wc_print_notices();
	}
	if ( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( $order && brikpanel_returns_can_access( $order ) ) {
			/* translators: %s: order number */
			echo '<h2 class="brikpanel-return-page__title">' . esc_html( sprintf( __( 'Order #%s', 'brikpanel' ), $order->get_order_number() ) ) . '</h2>';
			brikpanel_returns_render_section( $order );
			echo '<p><a href="' . esc_url( brikpanel_returns_link() ) . '">' . esc_html__( 'Return another order', 'brikpanel' ) . '</a></p>';
			echo '</div>';
			return;
		}
	}

	if ( is_user_logged_in() ) {
		brikpanel_returns_render_my_orders();
	}
	brikpanel_returns_render_lookup();
	if ( ! is_user_logged_in() ) {
		echo '<p class="brikpanel-return-page__login">' . esc_html__( 'Have an account?', 'brikpanel' ) . ' <a href="' . esc_url( wc_get_page_permalink( 'myaccount' ) ) . '">' . esc_html__( 'Log in', 'brikpanel' ) . '</a></p>';
	}
	echo '</div>';
}

/**
 * The logged-in customer's orders that can still be returned.
 *
 * @return void
 */
function brikpanel_returns_render_my_orders() {
	$statuses = array_map(
		static function ( $status ) {
			return 0 === strpos( $status, 'wc-' ) ? substr( $status, 3 ) : $status;
		},
		brikpanel_returns_eligible_statuses()
	);
	$orders   = wc_get_orders(
		array(
			'customer_id' => get_current_user_id(),
			'status'      => $statuses,
			'limit'       => 20,
			'orderby'     => 'date',
			'order'       => 'DESC',
			'type'        => 'shop_order',
		)
	);
	$mine     = brikpanel_returns_customer_returns();
	$rows     = array();
	foreach ( $orders as $order ) {
		$returns = isset( $mine[ $order->get_id() ] ) ? $mine[ $order->get_id() ] : array();
		if ( true === brikpanel_returns_check_order( $order, null, $returns ) ) {
			$rows[] = $order;
		}
	}
	echo '<h2 class="brikpanel-return-page__title">' . esc_html( brikpanel_returns_eu() ? __( 'Withdraw from contract', 'brikpanel' ) : __( 'Return an order', 'brikpanel' ) ) . '</h2>';
	if ( ! $rows ) {
		echo '<p>' . esc_html__( 'None of your orders can be returned right now.', 'brikpanel' ) . '</p>';
		return;
	}
	echo '<ul class="brikpanel-return-page__orders">';
	foreach ( $rows as $order ) {
		echo '<li>';
		/* translators: 1: order number, 2: date */
		echo '<span>' . esc_html( sprintf( __( 'Order #%1$s, %2$s', 'brikpanel' ), $order->get_order_number(), wc_format_datetime( $order->get_date_created() ) ) ) . '</span> ';
		echo '<a class="button wp-element-button" href="' . esc_url( add_query_arg( 'bp-return', '1', $order->get_view_order_url() ) . '#bp-return' ) . '">' . esc_html( brikpanel_returns_entry_label() ) . '</a>';
		echo '</li>';
	}
	echo '</ul>';
}

/**
 * The order number + email form.
 *
 * @return void
 */
function brikpanel_returns_render_lookup() {
	$logged = is_user_logged_in();
	?>
	<form method="post" class="woocommerce-form brikpanel-return__lookup" action="<?php echo esc_url( brikpanel_returns_link() ); ?>">
		<h2 class="brikpanel-return-page__title"><?php echo esc_html( $logged ? __( 'Ordered without logging in?', 'brikpanel' ) : ( brikpanel_returns_eu() ? __( 'Withdraw from contract', 'brikpanel' ) : __( 'Return an order', 'brikpanel' ) ) ); ?></h2>
		<p><?php esc_html_e( 'Enter your order number and the email address you used for the order.', 'brikpanel' ); ?></p>
		<p class="form-row form-row-first">
			<label for="bp-ret-number"><?php esc_html_e( 'Order number', 'brikpanel' ); ?>&nbsp;<span class="required" aria-hidden="true">*</span></label>
			<input type="text" class="input-text" id="bp-ret-number" name="order_number" required autocomplete="off" maxlength="40" value="<?php echo esc_attr( sanitize_text_field( (string) brikpanel_returns_posted( 'order_number', '' ) ) ); ?>">
		</p>
		<p class="form-row form-row-last">
			<label for="bp-ret-lookup-email"><?php esc_html_e( 'Email', 'brikpanel' ); ?>&nbsp;<span class="required" aria-hidden="true">*</span></label>
			<input type="email" class="input-text" id="bp-ret-lookup-email" name="order_email" required autocomplete="email" maxlength="190" value="<?php echo esc_attr( sanitize_email( (string) brikpanel_returns_posted( 'order_email', '' ) ) ); ?>">
		</p>
		<div class="clear"></div>
		<input type="hidden" name="brikpanel_return_action" value="lookup">
		<?php wp_nonce_field( 'brikpanel_return_lookup', 'brikpanel_return_nonce' ); ?>
		<p class="brikpanel-return__actions"><button type="submit" class="button wp-element-button"><?php esc_html_e( 'Find order', 'brikpanel' ); ?></button></p>
	</form>
	<?php
}

// =============================================================================
// FORM HANDLING
// =============================================================================

/**
 * Handle every returns form posted to an account page.
 *
 * @return void
 */
function brikpanel_returns_handle_post() {
	if ( ! is_account_page() || 'POST' !== ( isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '' ) ) {
		return;
	}
	// Photos larger than the server accepts: PHP drops the whole form.
	// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended -- only reports that nothing arrived.
	if ( empty( $_POST ) && isset( $_GET['bp-return'], $_SERVER['CONTENT_LENGTH'] ) && (int) $_SERVER['CONTENT_LENGTH'] > wp_convert_hr_to_bytes( (string) ini_get( 'post_max_size' ) ) ) {
		brikpanel_returns_notice( brikpanel_returns_photo_size_error()->get_error_message(), 'error' );
		return;
	}
	if ( empty( $_POST['brikpanel_return_action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- each action checks its own nonce.
		return;
	}
	$action = sanitize_key( wp_unslash( $_POST['brikpanel_return_action'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
	$nonce  = isset( $_POST['brikpanel_return_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['brikpanel_return_nonce'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

	if ( 'lookup' === $action ) {
		brikpanel_returns_handle_lookup( $nonce );
		return;
	}
	if ( 'cancel' === $action ) {
		brikpanel_returns_handle_cancel( $nonce );
		return;
	}

	$order = isset( $_POST['order_id'] ) ? wc_get_order( absint( $_POST['order_id'] ) ) : false; // phpcs:ignore WordPress.Security.NonceVerification.Missing
	if ( ! $order || ! brikpanel_returns_can_access( $order ) ) {
		brikpanel_returns_notice( __( 'We could not open this order. Please start again.', 'brikpanel' ), 'error' );
		return;
	}
	$nonce_action = 'confirm' === $action ? 'brikpanel_return_confirm_' . $order->get_id() : 'brikpanel_return_submit_' . $order->get_id();
	if ( ! wp_verify_nonce( $nonce, $nonce_action ) ) {
		brikpanel_returns_notice( __( 'This page has expired. Please send the form again.', 'brikpanel' ), 'error' );
		return;
	}

	if ( 'submit' === $action ) {
		brikpanel_returns_handle_submit( $order );
	} elseif ( 'review' === $action ) {
		brikpanel_returns_handle_review( $order );
	} elseif ( 'confirm' === $action ) {
		brikpanel_returns_handle_confirm( $order );
	}
}

/**
 * The lines the form chose: item id => units.
 *
 * @return array<int,int>
 */
function brikpanel_returns_posted_lines() {
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- the caller checked the nonce.
	$items = isset( $_POST['bp_items'] ) && is_array( $_POST['bp_items'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['bp_items'] ) ) : array();
	$qty   = isset( $_POST['bp_qty'] ) && is_array( $_POST['bp_qty'] ) ? array_map( 'absint', wp_unslash( $_POST['bp_qty'] ) ) : array();
	// phpcs:enable WordPress.Security.NonceVerification.Missing
	$out = array();
	foreach ( $items as $item_id => $on ) {
		$item_id = absint( $item_id );
		if ( $item_id && '1' === (string) $on ) {
			$out[ $item_id ] = isset( $qty[ $item_id ] ) ? max( 1, (int) $qty[ $item_id ] ) : 1;
		}
	}
	return $out;
}

/**
 * Store the uploaded photos; on any error delete what was stored.
 *
 * @return string[]|WP_Error
 */
function brikpanel_returns_handle_photos() {
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- the caller checked the nonce.
	if ( empty( $_FILES['bp_photos'] ) || ! is_array( $_FILES['bp_photos']['name'] ) ) {
		return array();
	}
	// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- every file is validated by brikpanel_returns_store_photo().
	$files  = $_FILES['bp_photos'];
	$stored = array();
	$count  = 0;
	foreach ( array_keys( $files['name'] ) as $i ) {
		if ( isset( $files['error'][ $i ] ) && UPLOAD_ERR_NO_FILE === (int) $files['error'][ $i ] ) {
			continue;
		}
		++$count;
		if ( $count > BRIKPANEL_RETURNS_MAX_PHOTOS ) {
			brikpanel_returns_delete_photos( $stored );
			return new WP_Error(
				'brikpanel_returns_photo_count',
				/* translators: %s: number of photos */
				sprintf( __( 'You can add up to %s photos.', 'brikpanel' ), brikpanel_returns_number( BRIKPANEL_RETURNS_MAX_PHOTOS ) )
			);
		}
		$name = brikpanel_returns_store_photo(
			array(
				'name'     => isset( $files['name'][ $i ] ) ? (string) $files['name'][ $i ] : '',
				'tmp_name' => isset( $files['tmp_name'][ $i ] ) ? (string) $files['tmp_name'][ $i ] : '',
				'size'     => isset( $files['size'][ $i ] ) ? (int) $files['size'][ $i ] : 0,
				'error'    => isset( $files['error'][ $i ] ) ? (int) $files['error'][ $i ] : UPLOAD_ERR_NO_FILE,
			)
		);
		if ( is_wp_error( $name ) ) {
			brikpanel_returns_delete_photos( $stored );
			return $name;
		}
		$stored[] = $name;
	}
	return $stored;
}

/**
 * Common fields of the request form.
 *
 * @return array{reason:string,comment:string,token:string}
 */
function brikpanel_returns_posted_common() {
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- the caller checked the nonce.
	$comment = isset( $_POST['bp_comment'] ) ? sanitize_textarea_field( wp_unslash( $_POST['bp_comment'] ) ) : '';
	$out     = array(
		'reason'  => isset( $_POST['bp_reason'] ) ? sanitize_text_field( wp_unslash( $_POST['bp_reason'] ) ) : '',
		'comment' => function_exists( 'mb_substr' ) ? mb_substr( $comment, 0, 1000 ) : substr( $comment, 0, 1000 ),
		'token'   => isset( $_POST['bp_token'] ) ? preg_replace( '/[^A-Za-z0-9]/', '', sanitize_text_field( wp_unslash( $_POST['bp_token'] ) ) ) : '',
	);
	// phpcs:enable WordPress.Security.NonceVerification.Missing
	return $out;
}

/**
 * A normal return request.
 *
 * @param WC_Order $order Order.
 * @return void
 */
function brikpanel_returns_handle_submit( $order ) {
	if ( brikpanel_returns_eu() ) {
		return; // EU mode goes through review and confirm.
	}
	$lines  = brikpanel_returns_posted_lines();
	$common = brikpanel_returns_posted_common();
	if ( ! $lines ) {
		brikpanel_returns_notice( __( 'Choose at least one item to return.', 'brikpanel' ), 'error' );
		return;
	}
	if ( '' === $common['reason'] ) {
		brikpanel_returns_notice( __( 'Please choose a reason for the return.', 'brikpanel' ), 'error' );
		return;
	}

	$photos = array();
	if ( 'off' !== brikpanel_returns_photos_mode() ) {
		$photos = brikpanel_returns_handle_photos();
		if ( is_wp_error( $photos ) ) {
			brikpanel_returns_notice( $photos->get_error_message(), 'error' );
			return;
		}
	}

	$id = brikpanel_returns_create(
		$order,
		array(
			'lines'       => $lines,
			'reason'      => $common['reason'],
			'comment'     => $common['comment'],
			'photos'      => $photos,
			'source'      => is_user_logged_in() && $order->get_customer_id() === get_current_user_id() ? 'account' : 'guest',
			'source_id'   => $common['token'],
			'customer_id' => (int) $order->get_customer_id(),
			'email'       => $order->get_billing_email(),
		)
	);
	if ( is_wp_error( $id ) ) {
		brikpanel_returns_delete_photos( $photos );
		brikpanel_returns_notice( $id->get_error_message(), 'error' );
		return;
	}
	brikpanel_returns_notice( __( 'Your return request has been sent. We will email you as soon as we have checked it.', 'brikpanel' ), 'success' );
	wp_safe_redirect( brikpanel_returns_page_url( $order ) . '#bp-return' );
	exit;
}

/**
 * EU step one: check and keep the statement for 30 minutes.
 *
 * @param WC_Order $order Order.
 * @return void
 */
function brikpanel_returns_handle_review( $order ) {
	if ( ! brikpanel_returns_eu() ) {
		return;
	}
	$lines  = brikpanel_returns_posted_lines();
	$common = brikpanel_returns_posted_common();
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- the caller checked the nonce.
	$name  = isset( $_POST['bp_name'] ) ? sanitize_text_field( wp_unslash( $_POST['bp_name'] ) ) : '';
	$email = isset( $_POST['bp_email'] ) ? sanitize_email( wp_unslash( $_POST['bp_email'] ) ) : '';
	// phpcs:enable WordPress.Security.NonceVerification.Missing
	if ( ! $lines ) {
		brikpanel_returns_notice( __( 'Choose at least one item to return.', 'brikpanel' ), 'error' );
		return;
	}
	if ( '' === $name || ! is_email( $email ) ) {
		brikpanel_returns_notice( __( 'Please enter your name and a valid email address.', 'brikpanel' ), 'error' );
		return;
	}
	$available = brikpanel_returns_lines( $order );
	$check     = brikpanel_returns_build_items( $available, $lines );
	if ( is_wp_error( $check ) ) {
		brikpanel_returns_notice( $check->get_error_message(), 'error' );
		return;
	}
	$reason = in_array( $common['reason'], brikpanel_returns_reasons(), true ) ? $common['reason'] : '';
	$key    = strtolower( wp_generate_password( 32, false, false ) );
	set_transient(
		'bp_ret_pend_' . $key,
		array(
			'order_id' => $order->get_id(),
			'lines'    => $lines,
			'reason'   => $reason,
			'comment'  => $common['comment'],
			'name'     => $name,
			'email'    => $email,
			'token'    => $common['token'],
		),
		30 * MINUTE_IN_SECONDS
	);
	wp_safe_redirect( brikpanel_returns_page_url( $order, array( 'bp-review' => $key ) ) . '#bp-return' );
	exit;
}

/**
 * EU step two: "Confirm withdrawal" creates the request and sends the
 * acknowledgement.
 *
 * @param WC_Order $order Order.
 * @return void
 */
function brikpanel_returns_handle_confirm( $order ) {
	$key     = isset( $_POST['bp_pending'] ) ? sanitize_key( wp_unslash( $_POST['bp_pending'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the caller checked the nonce.
	$pending = '' !== $key ? get_transient( 'bp_ret_pend_' . $key ) : false;
	if ( ! is_array( $pending ) || (int) $pending['order_id'] !== (int) $order->get_id() ) {
		brikpanel_returns_notice( __( 'This page has expired. Please fill in the form again.', 'brikpanel' ), 'error' );
		wp_safe_redirect( brikpanel_returns_page_url( $order, array( 'bp-return' => '1' ) ) . '#bp-return' );
		exit;
	}
	$id = brikpanel_returns_create(
		$order,
		array(
			'lines'       => $pending['lines'],
			'reason'      => $pending['reason'],
			'comment'     => $pending['comment'],
			'source'      => is_user_logged_in() && $order->get_customer_id() === get_current_user_id() ? 'account' : 'guest',
			'source_id'   => '' !== $pending['token'] ? $pending['token'] : $key,
			'customer_id' => (int) $order->get_customer_id(),
			'email'       => $pending['email'],
			'statement'   => array(
				'name'  => $pending['name'],
				'email' => $pending['email'],
				'text'  => $pending['comment'],
			),
		)
	);
	if ( is_wp_error( $id ) ) {
		brikpanel_returns_notice( $id->get_error_message(), 'error' );
		wp_safe_redirect( brikpanel_returns_page_url( $order ) . '#bp-return' );
		exit;
	}
	delete_transient( 'bp_ret_pend_' . $key );
	brikpanel_returns_notice(
		sprintf(
			/* translators: %s: email address */
			__( 'We received your withdrawal. The confirmation is on its way to %s.', 'brikpanel' ),
			$pending['email']
		),
		'success'
	);
	wp_safe_redirect( brikpanel_returns_page_url( $order ) . '#bp-return' );
	exit;
}

/**
 * The customer cancels a request that still waits.
 *
 * @param string $nonce Posted nonce.
 * @return void
 */
function brikpanel_returns_handle_cancel( $nonce ) {
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- the nonce is checked below.
	$id      = isset( $_POST['return_id'] ) ? absint( $_POST['return_id'] ) : 0;
	$version = isset( $_POST['version'] ) ? absint( $_POST['version'] ) : 0;
	// phpcs:enable WordPress.Security.NonceVerification.Missing
	$ret   = $id ? Brikpanel_Returns_Store::get( $id, true ) : null;
	$order = $ret ? wc_get_order( $ret['order_id'] ) : false;
	if ( ! $ret || ! $order || ! brikpanel_returns_can_access( $order ) || ! wp_verify_nonce( $nonce, 'brikpanel_return_cancel_' . $id ) ) {
		brikpanel_returns_notice( __( 'This page has expired. Please try again.', 'brikpanel' ), 'error' );
		return;
	}
	$done = brikpanel_returns_act( 'cancel', $id, $version );
	if ( is_wp_error( $done ) ) {
		brikpanel_returns_notice( $done->get_error_message(), 'error' );
	} else {
		brikpanel_returns_notice( __( 'Your return request is cancelled.', 'brikpanel' ), 'success' );
	}
	wp_safe_redirect( brikpanel_returns_page_url( $order ) . '#bp-return' );
	exit;
}

/**
 * Order number + email: on a match, a token in a cookie and the order's page.
 *
 * @param string $nonce Posted nonce.
 * @return void
 */
function brikpanel_returns_handle_lookup( $nonce ) {
	if ( ! wp_verify_nonce( $nonce, 'brikpanel_return_lookup' ) ) {
		brikpanel_returns_notice( __( 'This page has expired. Please send the form again.', 'brikpanel' ), 'error' );
		return;
	}
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked above.
	$number = isset( $_POST['order_number'] ) ? sanitize_text_field( wp_unslash( $_POST['order_number'] ) ) : '';
	$email  = isset( $_POST['order_email'] ) ? sanitize_email( wp_unslash( $_POST['order_email'] ) ) : '';
	// phpcs:enable WordPress.Security.NonceVerification.Missing
	if ( brikpanel_returns_lookup_blocked( $number ) ) {
		brikpanel_returns_notice( __( 'Too many attempts. Please wait a few minutes and try again.', 'brikpanel' ), 'error' );
		return;
	}
	$order = brikpanel_returns_lookup_order( $number, $email );
	if ( ! $order ) {
		brikpanel_returns_lookup_failed( $number );
		brikpanel_returns_notice( __( 'We could not find an order with this number and email address.', 'brikpanel' ), 'error' );
		return;
	}
	brikpanel_returns_set_cookie( brikpanel_returns_token( $order, 'form' ) );
	wp_safe_redirect( add_query_arg( 'order', $order->get_id(), brikpanel_returns_link() ) . '#bp-return' );
	exit;
}

// =============================================================================
// ASSETS
// =============================================================================

/**
 * Styles and the small script, on account pages only.
 *
 * @return void
 */
function brikpanel_returns_account_assets() {
	if ( ! is_account_page() ) {
		return;
	}
	$dir = BRIKPANEL_PATH . 'front-end/returns/';
	$url = BRIKPANEL_URL . 'front-end/returns/';
	wp_enqueue_style( 'brikpanel-returns-account', $url . 'brikpanel-returns-account.css', array(), (string) filemtime( $dir . 'brikpanel-returns-account.css' ) );
	wp_enqueue_script( 'brikpanel-returns-account', $url . 'brikpanel-returns-account.js', array(), (string) filemtime( $dir . 'brikpanel-returns-account.js' ), true );
	wp_localize_script(
		'brikpanel-returns-account',
		'brikpanelReturns',
		array(
			'maxPhotos' => BRIKPANEL_RETURNS_MAX_PHOTOS,
			'maxBytes'  => brikpanel_returns_photo_max_bytes(),
			'i18n'      => array(
				/* translators: %s: number of photos */
				'tooMany' => sprintf( __( 'You can add up to %s photos.', 'brikpanel' ), brikpanel_returns_number( BRIKPANEL_RETURNS_MAX_PHOTOS ) ),
				'tooBig'  => brikpanel_returns_photo_size_error()->get_error_message(),
				'sending' => __( 'Sending…', 'brikpanel' ),
				'pick'    => __( 'Choose at least one item to return.', 'brikpanel' ),
			),
		)
	);
}
