<?php
/**
 * Customer returns: rules and actions. No screens live here.
 *
 *   - Which lines of an order can go back, and how many units.
 *   - Creating a request, and every status change after it.
 *   - The refund (WooCommerce's own wc_create_refund()).
 *   - Photos: checked, stripped of their location data, kept private.
 *   - Guest access: a signed token instead of a login.
 *   - Personal data: export, erase, and the order being deleted.
 *
 * @package BrikPanel
 * @since   3.3.52
 */

defined( 'ABSPATH' ) || exit;

// =============================================================================
// LINES
// =============================================================================

/**
 * Item meta that marks a line as part of another line (a bundle or a
 * composite product). The first four come from
 * front-end/orders/brikpanel-order-print.php; Bopo and Easy Product Bundles
 * are added here.
 *
 * @return string[]
 */
function brikpanel_returns_child_keys() {
	return (array) apply_filters(
		'brikpanel_returns_child_item_keys',
		array( '_bundled_by', '_woosb_parent_id', '_wooco_parent_id', '_composite_parent', '_bopobb_parent_id', '_asnp_wepb_parent_id' )
	);
}

/**
 * @param WC_Order_Item $item Order line.
 * @return bool Whether the line belongs to another line.
 */
function brikpanel_returns_is_child_item( $item ) {
	foreach ( brikpanel_returns_child_keys() as $key ) {
		$value = $item->get_meta( $key, true );
		if ( '' !== $value && null !== $value && false !== $value && array() !== $value && 0 !== $value && '0' !== $value ) {
			return true;
		}
	}
	return false;
}

/**
 * The order's lines as a customer sees them: one entry per top-level line,
 * bundle parts folded under their parent (they always follow it in the
 * order), with how many units can still go back.
 *
 * max = ordered − refunded in WooCommerce − held by this order's open or
 * waiting returns. A virtual or downloadable line has nothing to send back;
 * a bundle can go back when the parent or any of its parts is physical.
 *
 * @param WC_Order   $order      Order.
 * @param int        $exclude_id A return whose own reservation is left out.
 * @param array|null $returns    The order's returns when already read.
 * @return array<int,array>
 */
function brikpanel_returns_lines( $order, $exclude_id = 0, $returns = null ) {
	if ( is_array( $returns ) ) {
		$held = Brikpanel_Returns_Store::held_from( $returns, $exclude_id );
	} else {
		$held = Brikpanel_Returns_Store::held_qty( $order->get_id(), $exclude_id );
	}
	$lines  = array();
	$parent = 0;

	foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
		$item_id = (int) $item_id;
		$qty     = (int) $item->get_quantity();
		$product = $item->get_product();
		$physical = ! ( $product && ( $product->is_virtual() || $product->is_downloadable() ) );

		if ( $parent && brikpanel_returns_is_child_item( $item ) && isset( $lines[ $parent ] ) ) {
			$per = $qty / max( 1, $lines[ $parent ]['qty'] );
			$lines[ $parent ]['children'][ $item_id ] = $per;
			if ( $physical ) {
				$lines[ $parent ]['physical'] = true;
			}
			continue;
		}

		$refunded = abs( (int) $order->get_qty_refunded_for_item( $item_id ) );
		$left     = $qty - $refunded - ( isset( $held[ $item_id ] ) ? (int) $held[ $item_id ] : 0 );
		$paid     = $qty > 0 ? ( (float) $item->get_total() + (float) $item->get_total_tax() ) / $qty : 0.0;

		$lines[ $item_id ] = array(
			'item_id'      => $item_id,
			'name'         => $item->get_name(),
			'qty'          => $qty,
			'left'         => max( 0, $left ),
			'max'          => 0,
			'product_id'   => (int) $item->get_product_id(),
			'variation_id' => (int) $item->get_variation_id(),
			'unit'         => $paid,
			'physical'     => $physical,
			'children'     => array(),
		);
		$parent = $item_id;
	}

	foreach ( $lines as $item_id => $line ) {
		$item       = $order->get_item( $item_id );
		$returnable = $line['left'] > 0 && $line['physical'];
		/**
		 * Whether a line may be offered for return.
		 *
		 * @param bool          $returnable Default decision.
		 * @param WC_Order_Item $item       The line.
		 * @param WC_Order      $order      The order.
		 */
		$returnable                 = (bool) apply_filters( 'brikpanel_returns_item_returnable', $returnable, $item, $order );
		$lines[ $item_id ]['max'] = $returnable ? $line['left'] : 0;
	}

	return $lines;
}

/**
 * Turn "n units of these top-level lines" into the stored item list: the
 * lines themselves plus their bundle parts at the same proportion.
 *
 * @param array $lines   brikpanel_returns_lines().
 * @param array $chosen  item id => units.
 * @return array[]|WP_Error
 */
function brikpanel_returns_build_items( array $lines, array $chosen ) {
	$items = array();
	foreach ( $chosen as $item_id => $n ) {
		$item_id = (int) $item_id;
		$n       = (int) $n;
		if ( $n <= 0 ) {
			continue;
		}
		if ( ! isset( $lines[ $item_id ] ) || $n > (int) $lines[ $item_id ]['max'] ) {
			return new WP_Error( 'brikpanel_returns_qty', __( 'One of the quantities is no longer available to return. Please check the form again.', 'brikpanel' ) );
		}
		$line    = $lines[ $item_id ];
		$items[] = array(
			'item_id'      => $item_id,
			'product_id'   => $line['product_id'],
			'variation_id' => $line['variation_id'],
			'qty'          => $n,
			'name'         => $line['name'],
			'unit'         => round( (float) $line['unit'], 4 ),
			'parent'       => 0,
			'restocked'    => 0,
		);
		foreach ( $line['children'] as $child_id => $per ) {
			$child_qty = (int) round( $per * $n );
			if ( $child_qty > 0 ) {
				$items[] = array(
					'item_id'      => (int) $child_id,
					'product_id'   => 0,
					'variation_id' => 0,
					'qty'          => $child_qty,
					'name'         => '',
					'unit'         => 0,
					'parent'       => $item_id,
					'restocked'    => 0,
				);
			}
		}
	}
	if ( ! $items ) {
		return new WP_Error( 'brikpanel_returns_empty', __( 'Choose at least one item to return.', 'brikpanel' ) );
	}
	return $items;
}

// =============================================================================
// ELIGIBILITY
// =============================================================================

/**
 * When the return window of an order closes.
 *
 * EU mode: no end while the order is not completed (a consumer may withdraw
 * before delivery) and never earlier than completion + 14 days.
 *
 * @param WC_Order $order Order.
 * @return int Unix time, 0 for no limit.
 */
function brikpanel_returns_window_end( $order ) {
	$days      = brikpanel_returns_window_days();
	$eu        = brikpanel_returns_eu();
	$completed = $order->get_date_completed();
	if ( $eu && ! $completed ) {
		return 0;
	}
	if ( 0 === $days ) {
		return 0;
	}
	if ( $eu ) {
		$days = max( $days, BRIKPANEL_RETURNS_EU_DAYS );
	}
	$start = $completed ? $completed : $order->get_date_paid();
	if ( ! $start ) {
		$start = $order->get_date_created();
	}
	return $start ? $start->getTimestamp() + $days * DAY_IN_SECONDS : 0;
}

/**
 * Statuses that may start a request (EU mode adds paid, not yet completed).
 *
 * @return string[] With the wc- prefix.
 */
function brikpanel_returns_eligible_statuses() {
	$statuses = brikpanel_returns_order_statuses();
	if ( brikpanel_returns_eu() ) {
		$statuses = array_merge( $statuses, array( 'wc-processing', 'wc-on-hold', 'wc-completed' ) );
	}
	return array_values( array_unique( $statuses ) );
}

/**
 * Whether a new request can be started for this order now.
 *
 * @param WC_Order   $order   Order.
 * @param array|null $lines   Lines when already worked out.
 * @param array|null $returns The order's returns when already read.
 * @return true|WP_Error
 */
function brikpanel_returns_check_order( $order, $lines = null, $returns = null ) {
	if ( ! brikpanel_returns_enabled() ) {
		return new WP_Error( 'brikpanel_returns_off', __( 'Returns are not available in this store.', 'brikpanel' ) );
	}
	if ( ! $order instanceof WC_Order || 'shop_order' !== $order->get_type() ) {
		return new WP_Error( 'brikpanel_returns_order', __( 'This order cannot be returned.', 'brikpanel' ) );
	}
	if ( ! in_array( 'wc-' . $order->get_status(), brikpanel_returns_eligible_statuses(), true ) ) {
		return new WP_Error( 'brikpanel_returns_status', __( 'This order cannot be returned yet.', 'brikpanel' ) );
	}
	$end = brikpanel_returns_window_end( $order );
	if ( $end && (int) apply_filters( 'brikpanel_returns_now', time() ) > $end ) {
		return new WP_Error( 'brikpanel_returns_window', __( 'The return period for this order has ended.', 'brikpanel' ) );
	}
	if ( ! is_array( $returns ) ) {
		$returns = Brikpanel_Returns_Store::for_order( $order->get_id() );
	}
	foreach ( $returns as $ret ) {
		if ( $ret['open_order_id'] ) {
			return new WP_Error( 'brikpanel_returns_open', __( 'There is already an open return for this order.', 'brikpanel' ) );
		}
	}
	if ( null === $lines ) {
		$lines = brikpanel_returns_lines( $order, 0, $returns );
	}
	foreach ( $lines as $line ) {
		if ( $line['max'] > 0 ) {
			return true;
		}
	}
	return new WP_Error( 'brikpanel_returns_nothing', __( 'Everything in this order has already been returned.', 'brikpanel' ) );
}

// =============================================================================
// LOCKS
// =============================================================================

/**
 * @param int $order_id Order id.
 * @return string Lock name, unique per database, prefix and order.
 */
function brikpanel_returns_lock_name( $order_id ) {
	global $wpdb;
	return 'bp_ret_' . substr( md5( ( defined( 'DB_NAME' ) ? DB_NAME : '' ) . '|' . $wpdb->prefix ), 0, 12 ) . '_' . (int) $order_id;
}

/**
 * Hold a named lock on the order for the length of a write.
 *
 * @param int $order_id Order id.
 * @return bool False when another request holds it.
 */
function brikpanel_returns_lock( $order_id ) {
	global $wpdb;
	$suppress = $wpdb->suppress_errors( true );
	$got      = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 3)', brikpanel_returns_lock_name( $order_id ) ) );
	$wpdb->suppress_errors( $suppress );
	// A database without named locks: the UNIQUE keys still stop a double write.
	return null === $got || '1' === (string) $got;
}

/**
 * @param int $order_id Order id.
 * @return void
 */
function brikpanel_returns_unlock( $order_id ) {
	global $wpdb;
	$suppress = $wpdb->suppress_errors( true );
	$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', brikpanel_returns_lock_name( $order_id ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->suppress_errors( $suppress );
}

// =============================================================================
// CREATE
// =============================================================================

/**
 * Create a request.
 *
 * @param WC_Order $order Order.
 * @param array    $input lines (item id => units), reason, comment, photos
 *                        (stored names), source (account|guest|admin),
 *                        source_id (form token), customer_id, email,
 *                        statement (EU only: name, email, text).
 * @return int|WP_Error Return id.
 */
function brikpanel_returns_create( $order, array $input ) {
	$order_id = $order->get_id();
	if ( ! brikpanel_returns_lock( $order_id ) ) {
		return new WP_Error( 'brikpanel_returns_busy', __( 'This order is being updated. Please try again in a moment.', 'brikpanel' ) );
	}

	try {
		$eu = brikpanel_returns_eu();

		// The same form sent twice answers with the first request.
		$source    = isset( $input['source'] ) ? sanitize_key( $input['source'] ) : 'account';
		$source_id = isset( $input['source_id'] ) ? (string) $input['source_id'] : '';
		if ( '' !== $source_id ) {
			foreach ( Brikpanel_Returns_Store::for_order( $order_id ) as $ret ) {
				if ( $ret['source'] === $source && $ret['source_id'] === $source_id ) {
					return (int) $ret['id'];
				}
			}
		}

		$lines = brikpanel_returns_lines( $order );
		$check = brikpanel_returns_check_order( $order, $lines );
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		$items = brikpanel_returns_build_items( $lines, isset( $input['lines'] ) ? (array) $input['lines'] : array() );
		if ( is_wp_error( $items ) ) {
			return $items;
		}

		$reasons = brikpanel_returns_reasons();
		$reason  = isset( $input['reason'] ) ? trim( sanitize_text_field( $input['reason'] ) ) : '';
		if ( '' !== $reason && ! in_array( $reason, $reasons, true ) ) {
			$reason = '';
		}
		// A withdrawal needs no reason (Art. 11a: no extra hurdle).
		if ( '' === $reason && ! $eu ) {
			return new WP_Error( 'brikpanel_returns_reason', __( 'Please choose a reason for the return.', 'brikpanel' ) );
		}

		$photos = isset( $input['photos'] ) ? array_values( array_filter( (array) $input['photos'], 'brikpanel_returns_photo_name_ok' ) ) : array();
		if ( ! $eu && 'required' === brikpanel_returns_photos_mode() && ! $photos ) {
			return new WP_Error( 'brikpanel_returns_photo', __( 'Please add at least one photo of the item.', 'brikpanel' ) );
		}

		$statement = null;
		if ( $eu ) {
			$statement = array(
				'name'  => isset( $input['statement']['name'] ) ? sanitize_text_field( $input['statement']['name'] ) : '',
				'email' => isset( $input['statement']['email'] ) ? sanitize_email( $input['statement']['email'] ) : '',
				'order' => $order->get_order_number(),
				'text'  => isset( $input['statement']['text'] ) ? sanitize_textarea_field( $input['statement']['text'] ) : '',
			);
		}

		$now = Brikpanel_Returns_Store::now();
		$id  = Brikpanel_Returns_Store::insert(
			array(
				'order_id'     => $order_id,
				'customer_id'  => isset( $input['customer_id'] ) ? (int) $input['customer_id'] : (int) $order->get_customer_id(),
				'email'        => ( $statement && $statement['email'] ) ? $statement['email'] : ( isset( $input['email'] ) ? $input['email'] : $order->get_billing_email() ),
				'items'        => $items,
				'reason'       => $reason,
				'comment'      => isset( $input['comment'] ) ? $input['comment'] : '',
				'photos'       => $eu ? array() : $photos,
				'currency'     => $order->get_currency(),
				'withdrawal'   => $eu ? 1 : 0,
				'statement'    => $statement,
				'submitted_at' => $now,
				'source'       => $source,
				'source_id'    => $source_id,
				'history'      => array(
					array(
						's' => 'requested',
						't' => $now,
						'u' => get_current_user_id(),
						'n' => '',
						'f' => '',
					),
				),
			)
		);
		if ( is_wp_error( $id ) ) {
			return $id;
		}

		$order->add_order_note( brikpanel_returns_note_text( (int) $id, $items, $reason, $eu ), 0, false );

		/**
		 * A return request was created (the admin and customer emails listen).
		 *
		 * @param int $id Return id.
		 */
		do_action( 'brikpanel_return_created', (int) $id );
		return (int) $id;
	} finally {
		brikpanel_returns_unlock( $order_id );
	}
}

/**
 * The private order note written when a request arrives.
 *
 * @param int    $id     Return id.
 * @param array  $items  Stored items.
 * @param string $reason Reason.
 * @param bool   $eu     Withdrawal.
 * @return string
 */
function brikpanel_returns_note_text( $id, array $items, $reason, $eu ) {
	$parts = array();
	foreach ( $items as $line ) {
		if ( empty( $line['parent'] ) ) {
			/* translators: 1: units, 2: product name */
			$parts[] = sprintf( _x( '%1$s × %2$s', 'units × product', 'brikpanel' ), brikpanel_returns_number( $line['qty'] ), $line['name'] );
		}
	}
	$list = implode( ', ', $parts );
	if ( $eu ) {
		/* translators: 1: return number, 2: items */
		$text = sprintf( __( 'Withdrawal #%1$s received from the customer: %2$s.', 'brikpanel' ), $id, $list );
	} else {
		/* translators: 1: return number, 2: items */
		$text = sprintf( __( 'Return #%1$s requested by the customer: %2$s.', 'brikpanel' ), $id, $list );
	}
	if ( '' !== $reason ) {
		/* translators: %s: reason */
		$text .= ' ' . sprintf( __( 'Reason: %s', 'brikpanel' ), $reason );
	}
	return $text;
}

/**
 * A price as plain text (no markup, no HTML entities), for order notes and
 * messages that are escaped where they are printed.
 *
 * @param float  $amount   Amount.
 * @param string $currency Currency code.
 * @return string
 */
function brikpanel_returns_plain_price( $amount, $currency = '' ) {
	$html = wc_price( (float) $amount, $currency ? array( 'currency' => $currency ) : array() );
	return trim( html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
}

/**
 * Whole number in the store's format.
 *
 * @param int|float $n Number.
 * @return string
 */
function brikpanel_returns_number( $n ) {
	return function_exists( 'brikpanel_number' ) ? brikpanel_number( $n ) : (string) (int) $n;
}

// =============================================================================
// STATUS CHANGES
// =============================================================================

/**
 * The error every action gives when somebody else changed the return first.
 *
 * @return WP_Error
 */
function brikpanel_returns_stale_error() {
	return new WP_Error( 'brikpanel_returns_stale', __( 'This return was changed in the meantime. The page shows the latest state now.', 'brikpanel' ) );
}

/**
 * Statuses an action may start from.
 *
 * @param string $action approve|reject|receive|refund|cancel.
 * @return string[]
 */
function brikpanel_returns_action_from( $action ) {
	$map = array(
		'approve' => array( 'requested' ),
		'reject'  => array( 'requested', 'approved', 'received' ),
		'receive' => array( 'requested', 'approved' ),
		'refund'  => array( 'requested', 'approved', 'received' ),
		'cancel'  => array( 'requested' ),
	);
	return isset( $map[ $action ] ) ? $map[ $action ] : array();
}

/**
 * Run one of the simple actions (everything but the refund).
 *
 * @param string $action  approve|reject|receive|cancel.
 * @param int    $id      Return id.
 * @param int    $version Version the person saw.
 * @param string $message Message for the customer (approve, reject).
 * @return true|WP_Error
 */
function brikpanel_returns_act( $action, $id, $version, $message = '' ) {
	$ret = Brikpanel_Returns_Store::get( $id, true );
	if ( ! $ret ) {
		return new WP_Error( 'brikpanel_returns_missing', __( 'This return no longer exists.', 'brikpanel' ) );
	}
	if ( (int) $ret['version'] !== (int) $version || ! in_array( $ret['status'], brikpanel_returns_action_from( $action ), true ) ) {
		return brikpanel_returns_stale_error();
	}
	$message = sanitize_textarea_field( (string) $message );
	$targets = array(
		'approve' => 'approved',
		'reject'  => 'rejected',
		'receive' => 'received',
		'cancel'  => 'cancelled',
	);
	if ( ! isset( $targets[ $action ] ) ) {
		return new WP_Error( 'brikpanel_returns_action', __( 'Unknown action.', 'brikpanel' ) );
	}
	if ( 'reject' === $action && '' === trim( $message ) ) {
		return new WP_Error( 'brikpanel_returns_message', __( 'Please tell the customer why the return is rejected.', 'brikpanel' ) );
	}
	$changes = array();
	if ( in_array( $action, array( 'approve', 'reject' ), true ) ) {
		$changes['admin_message'] = $message;
	}
	if ( ! Brikpanel_Returns_Store::transition( $id, $version, $targets[ $action ], $changes, array( 'note' => $message ) ) ) {
		return brikpanel_returns_stale_error();
	}

	$order = wc_get_order( $ret['order_id'] );
	if ( $order ) {
		$notes = array(
			/* translators: %s: return number */
			'approve' => __( 'Return #%s approved.', 'brikpanel' ),
			/* translators: %s: return number */
			'reject'  => __( 'Return #%s rejected.', 'brikpanel' ),
			/* translators: %s: return number */
			'receive' => __( 'Return #%s: the items arrived back.', 'brikpanel' ),
			/* translators: %s: return number */
			'cancel'  => __( 'Return #%s cancelled by the customer.', 'brikpanel' ),
		);
		$order->add_order_note( sprintf( $notes[ $action ], (int) $id ), 0, false );
	}

	$hooks = array(
		'approve' => 'brikpanel_return_approved',
		'reject'  => 'brikpanel_return_rejected',
		'receive' => 'brikpanel_return_received',
		'cancel'  => 'brikpanel_return_cancelled',
	);
	/**
	 * A return changed status (emails listen to approved and rejected).
	 *
	 * @param int $id Return id.
	 */
	do_action( $hooks[ $action ], (int) $id );
	return true;
}

// =============================================================================
// REFUND
// =============================================================================

/**
 * Whether the order's gateway can send money back by itself.
 *
 * @param WC_Order $order Order.
 * @return bool
 */
function brikpanel_returns_gateway_can_refund( $order ) {
	$gateway = wc_get_payment_gateway_by_order( $order );
	return $gateway && is_callable( array( $gateway, 'can_refund_order' ) ) && $gateway->can_refund_order( $order );
}

/**
 * The lines and amount of a refund for a return.
 *
 * Per line: the line's total after discounts and its taxes, pro rata to the
 * units; the last units of a line take what is left, so cents reconcile with
 * WooCommerce's own figures. Shipping and fees are optional; a deduction
 * scales every line so the lines always add up to the amount.
 *
 * @param WC_Order $order Order.
 * @param array    $ret   Return.
 * @param array    $opts  shipping (bool), fees (int[] fee line ids), deduction (float).
 * @return array{line_items:array,amount:float,goods:float}
 */
function brikpanel_returns_refund_plan( $order, array $ret, array $opts = array() ) {
	$dp         = wc_get_price_decimals();
	$line_items = array();
	$sum        = 0.0;
	$goods      = 0.0;
	$round      = static function ( $value ) use ( $dp ) {
		return (float) wc_format_decimal( $value, $dp );
	};

	foreach ( $ret['items'] as $line ) {
		$item_id = isset( $line['item_id'] ) ? (int) $line['item_id'] : 0;
		$n       = isset( $line['qty'] ) ? (int) $line['qty'] : 0;
		$item    = $item_id ? $order->get_item( $item_id ) : null;
		if ( ! $item || ! $item->is_type( 'line_item' ) || $n <= 0 ) {
			continue;
		}
		$qty       = max( 1, (int) $item->get_quantity() );
		$remaining = $qty - abs( (int) $order->get_qty_refunded_for_item( $item_id ) );
		$n         = min( $n, max( 0, $remaining ) );
		if ( $n <= 0 ) {
			continue;
		}
		$taxes = $item->get_taxes();
		$rates = isset( $taxes['total'] ) && is_array( $taxes['total'] ) ? $taxes['total'] : array();
		$tax   = array();
		if ( $n >= $remaining ) {
			$total = (float) $item->get_total() - abs( (float) $order->get_total_refunded_for_item( $item_id ) );
			foreach ( $rates as $rate_id => $amount ) {
				$tax[ $rate_id ] = $round( (float) $amount - abs( (float) $order->get_tax_refunded_for_item( $item_id, $rate_id ) ) );
			}
		} else {
			$total = (float) $item->get_total() / $qty * $n;
			foreach ( $rates as $rate_id => $amount ) {
				$tax[ $rate_id ] = $round( (float) $amount / $qty * $n );
			}
		}
		$total                  = $round( max( 0, $total ) );
		$line_items[ $item_id ] = array(
			'qty'          => $n,
			'refund_total' => $total,
			'refund_tax'   => $tax,
		);
		$sum   += $total + array_sum( $tax );
		$goods += $total + array_sum( $tax );
	}

	$extra_types = array();
	if ( ! empty( $opts['shipping'] ) ) {
		$extra_types['shipping'] = array_keys( $order->get_items( 'shipping' ) );
	}
	if ( ! empty( $opts['fees'] ) ) {
		$extra_types['fee'] = array_map( 'intval', (array) $opts['fees'] );
	}
	foreach ( $extra_types as $type => $ids ) {
		foreach ( $order->get_items( $type ) as $item_id => $item ) {
			if ( ! in_array( (int) $item_id, array_map( 'intval', $ids ), true ) ) {
				continue;
			}
			$total = (float) $item->get_total() - abs( (float) $order->get_total_refunded_for_item( $item_id, $type ) );
			if ( 'fee' === $type && (float) $item->get_total() <= 0 ) {
				continue; // A discount fee is never "refunded" upward.
			}
			$taxes = $item->get_taxes();
			$tax   = array();
			foreach ( ( isset( $taxes['total'] ) && is_array( $taxes['total'] ) ) ? $taxes['total'] : array() as $rate_id => $amount ) {
				$tax[ $rate_id ] = $round( (float) $amount - abs( (float) $order->get_tax_refunded_for_item( $item_id, $rate_id, $type ) ) );
			}
			$total = $round( max( 0, $total ) );
			if ( $total <= 0 && array_sum( $tax ) <= 0 ) {
				continue;
			}
			$line_items[ (int) $item_id ] = array(
				'qty'          => 0,
				'refund_total' => $total,
				'refund_tax'   => $tax,
			);
			$sum += $total + array_sum( $tax );
		}
	}

	$deduction = isset( $opts['deduction'] ) ? max( 0.0, (float) $opts['deduction'] ) : 0.0;
	if ( $deduction > 0 && $sum > 0 ) {
		$target = $round( max( 0, $sum - min( $deduction, $sum ) ) );
		$factor = $target / $sum;
		$new    = 0.0;
		foreach ( $line_items as $item_id => $li ) {
			$line_items[ $item_id ]['refund_total'] = $round( $li['refund_total'] * $factor );
			foreach ( $li['refund_tax'] as $rate_id => $amount ) {
				$line_items[ $item_id ]['refund_tax'][ $rate_id ] = $round( $amount * $factor );
			}
			$new += $line_items[ $item_id ]['refund_total'] + array_sum( $line_items[ $item_id ]['refund_tax'] );
		}
		// Rounding: the first line takes the last cent so lines equal the amount.
		$diff = $round( $target - $new );
		if ( 0.0 !== $diff && $line_items ) {
			$first                                 = array_key_first( $line_items );
			$line_items[ $first ]['refund_total'] = $round( max( 0, $line_items[ $first ]['refund_total'] + $diff ) );
		}
		$sum = $target;
	}

	return array(
		'line_items' => $line_items,
		'amount'     => $round( $sum ),
		'goods'      => $round( $goods ),
	);
}

/** @var int Return being refunded in this request (tags the WooCommerce refund). */
$GLOBALS['brikpanel_returns_resolving'] = 0;

/**
 * Tag the refund WooCommerce is about to save with the return it belongs to,
 * so a crash between the payment and our status write can be repaired.
 *
 * @param WC_Order_Refund $refund Refund.
 * @return void
 */
function brikpanel_returns_tag_refund( $refund ) {
	$id = isset( $GLOBALS['brikpanel_returns_resolving'] ) ? (int) $GLOBALS['brikpanel_returns_resolving'] : 0;
	if ( $id && is_object( $refund ) && is_callable( array( $refund, 'update_meta_data' ) ) ) {
		$refund->update_meta_data( '_brikpanel_return_id', $id );
	}
}

/**
 * Refund a return through WooCommerce.
 *
 * @param int   $id      Return id.
 * @param int   $version Version the person saw.
 * @param array $opts    shipping, fees, deduction, restock (bool), gateway
 *                       (bool: through the payment gateway), refunded_total
 *                       (the order's refunded total the person saw).
 * @return true|WP_Error
 */
function brikpanel_returns_refund( $id, $version, array $opts = array() ) {
	$ret = Brikpanel_Returns_Store::get( $id, true );
	if ( ! $ret ) {
		return new WP_Error( 'brikpanel_returns_missing', __( 'This return no longer exists.', 'brikpanel' ) );
	}
	if ( (int) $ret['version'] !== (int) $version || ! in_array( $ret['status'], brikpanel_returns_action_from( 'refund' ), true ) ) {
		return brikpanel_returns_stale_error();
	}
	$order = wc_get_order( $ret['order_id'] );
	if ( ! $order ) {
		return new WP_Error( 'brikpanel_returns_order', __( 'The order of this return no longer exists.', 'brikpanel' ) );
	}
	if ( isset( $opts['refunded_total'] ) && abs( (float) $order->get_total_refunded() - (float) $opts['refunded_total'] ) > 0.001 ) {
		return new WP_Error( 'brikpanel_returns_stale', __( 'This order was refunded in the meantime. Check the amount again.', 'brikpanel' ) );
	}

	$plan = brikpanel_returns_refund_plan( $order, $ret, $opts );
	if ( $plan['amount'] <= 0 && ! $plan['line_items'] ) {
		return new WP_Error( 'brikpanel_returns_nothing', __( 'There is nothing left to refund for this return.', 'brikpanel' ) );
	}
	$remaining = (float) $order->get_remaining_refund_amount();
	if ( $plan['amount'] > $remaining + 0.001 ) {
		return new WP_Error(
			'brikpanel_returns_amount',
			/* translators: %s: amount that can still be refunded */
			sprintf( __( 'Only %s can still be refunded on this order.', 'brikpanel' ), brikpanel_returns_plain_price( $remaining, $order->get_currency() ) )
		);
	}

	$previous = $ret['status'];
	if ( ! Brikpanel_Returns_Store::transition( $id, $version, 'resolving' ) ) {
		return brikpanel_returns_stale_error();
	}
	$claimed = Brikpanel_Returns_Store::get( $id, true );

	$restock     = ! empty( $opts['restock'] );
	$via_gateway = ! empty( $opts['gateway'] ) && brikpanel_returns_gateway_can_refund( $order );
	$reason      = $ret['reason'] ? sprintf(
		/* translators: 1: return number, 2: reason */
		__( 'Return #%1$s: %2$s', 'brikpanel' ),
		(int) $id,
		$ret['reason']
	) : sprintf(
		/* translators: %s: return number */
		__( 'Return #%s', 'brikpanel' ),
		(int) $id
	);

	$GLOBALS['brikpanel_returns_resolving'] = (int) $id;
	add_action( 'woocommerce_create_refund', 'brikpanel_returns_tag_refund', 10, 1 );
	$refund = wc_create_refund(
		array(
			'amount'         => $plan['amount'],
			'reason'         => $reason,
			'order_id'       => $order->get_id(),
			'line_items'     => $plan['line_items'],
			'refund_payment' => $via_gateway,
			'restock_items'  => $restock,
		)
	);
	remove_action( 'woocommerce_create_refund', 'brikpanel_returns_tag_refund', 10 );
	$GLOBALS['brikpanel_returns_resolving'] = 0;

	if ( is_wp_error( $refund ) || ! $refund ) {
		$error = is_wp_error( $refund ) ? $refund : new WP_Error( 'brikpanel_returns_refund', __( 'WooCommerce could not create the refund.', 'brikpanel' ) );
		Brikpanel_Returns_Store::transition( $id, $claimed['version'], $previous, array(), array( 'note' => $error->get_error_message() ) );
		return $error;
	}

	$items = $ret['items'];
	if ( $restock ) {
		foreach ( $items as $i => $line ) {
			$item_id = isset( $line['item_id'] ) ? (int) $line['item_id'] : 0;
			if ( isset( $plan['line_items'][ $item_id ] ) ) {
				$items[ $i ]['restocked'] = (int) ( isset( $line['restocked'] ) ? $line['restocked'] : 0 ) + (int) $plan['line_items'][ $item_id ]['qty'];
			}
		}
	}

	Brikpanel_Returns_Store::transition(
		$id,
		$claimed['version'],
		'refunded',
		array(
			'refund_id' => (int) $refund->get_id(),
			'amount'    => (float) $refund->get_amount(),
			'items'     => $items,
		)
	);

	$order->add_order_note(
		sprintf(
			/* translators: 1: return number, 2: amount */
			__( 'Return #%1$s refunded: %2$s.', 'brikpanel' ),
			(int) $id,
			brikpanel_returns_plain_price( $refund->get_amount(), $order->get_currency() )
		),
		0,
		false
	);

	/**
	 * A return was refunded through WooCommerce.
	 *
	 * @param int $id        Return id.
	 * @param int $refund_id WooCommerce refund id.
	 */
	do_action( 'brikpanel_return_refunded', (int) $id, (int) $refund->get_id() );
	return true;
}

/**
 * A request that is "refunding" for minutes means the request died between
 * the payment and our write. The tag on WooCommerce's refund tells which way
 * it went.
 *
 * @return void
 */
function brikpanel_returns_repair_stuck() {
	foreach ( Brikpanel_Returns_Store::stuck_resolving( 300 ) as $ret ) {
		$order     = wc_get_order( $ret['order_id'] );
		$refund_id = 0;
		$amount    = 0.0;
		if ( $order ) {
			foreach ( $order->get_refunds() as $refund ) {
				if ( (int) $refund->get_meta( '_brikpanel_return_id', true ) === (int) $ret['id'] ) {
					$refund_id = (int) $refund->get_id();
					$amount    = (float) $refund->get_amount();
					break;
				}
			}
		}
		if ( $refund_id ) {
			Brikpanel_Returns_Store::transition(
				$ret['id'],
				$ret['version'],
				'refunded',
				array(
					'refund_id' => $refund_id,
					'amount'    => $amount,
				),
				array( 'by' => 0 )
			);
			continue;
		}
		$last = end( $ret['history'] );
		$back = ( is_array( $last ) && ! empty( $last['f'] ) && 'resolving' !== $last['f'] ) ? $last['f'] : 'received';
		Brikpanel_Returns_Store::transition( $ret['id'], $ret['version'], $back, array(), array( 'by' => 0 ) );
	}
}

add_action( 'woocommerce_refund_deleted', 'brikpanel_returns_on_refund_deleted', 10, 2 );
/**
 * A refund deleted in WooCommerce puts its return back to "Item received",
 * so the store can refund it again (units reserved again).
 *
 * @param int $refund_id Refund id.
 * @param int $order_id  Order id.
 * @return void
 */
function brikpanel_returns_on_refund_deleted( $refund_id, $order_id ) {
	unset( $order_id );
	$ret = Brikpanel_Returns_Store::by_refund( $refund_id );
	if ( ! $ret || 'refunded' !== $ret['status'] ) {
		return;
	}
	Brikpanel_Returns_Store::transition(
		$ret['id'],
		$ret['version'],
		'received',
		array(
			'refund_id' => 0,
			'amount'    => 0.0,
		),
		array( 'note' => __( 'The refund was deleted in WooCommerce.', 'brikpanel' ) )
	);
}

// =============================================================================
// PHOTOS
// =============================================================================

/** Photos per request. */
const BRIKPANEL_RETURNS_MAX_PHOTOS = 5;

/** Largest side of a stored photo, in pixels. */
const BRIKPANEL_RETURNS_PHOTO_PX = 2000;

/**
 * Largest accepted upload in bytes: 5 MB, or less when the server allows less.
 *
 * @return int
 */
function brikpanel_returns_photo_max_bytes() {
	$limit = 5 * MB_IN_BYTES;
	$wp    = function_exists( 'wp_max_upload_size' ) ? (int) wp_max_upload_size() : 0;
	return $wp > 0 ? min( $limit, $wp ) : $limit;
}

/**
 * The private photo folder, created and protected on first use.
 *
 * @param bool $create Create it when missing.
 * @return string|false
 */
function brikpanel_returns_photo_dir( $create = true ) {
	$uploads = wp_upload_dir( null, false );
	if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
		return false;
	}
	$dir = untrailingslashit( $uploads['basedir'] ) . '/brikpanel-returns';
	if ( ! is_dir( $dir ) ) {
		if ( ! $create || ! wp_mkdir_p( $dir ) ) {
			return false;
		}
	}
	if ( $create ) {
		if ( ! file_exists( $dir . '/index.php' ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a fixed file in our own folder.
			file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" );
		}
		if ( ! file_exists( $dir . '/.htaccess' ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a fixed file in our own folder.
			file_put_contents(
				$dir . '/.htaccess',
				"# BrikPanel: return photos are shown through wp-admin only.\n"
				. "<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n"
				. "<IfModule !mod_authz_core.c>\n\tOrder deny,allow\n\tDeny from all\n</IfModule>\n"
			);
		}
		if ( ! wp_is_writable( $dir ) ) {
			return false;
		}
	}
	return $dir;
}

/**
 * Whether a stored photo name is one this module wrote.
 *
 * @param mixed $name File name.
 * @return bool
 */
function brikpanel_returns_photo_name_ok( $name ) {
	return is_string( $name ) && 1 === preg_match( '/^[a-f0-9]{32}\.(jpg|png|webp)$/', $name );
}

/**
 * Image editors for return photos: GD first because it writes a new file
 * without the camera's metadata; Imagick through a subclass that strips it
 * (WordPress' own Imagick editor keeps the EXIF profile on purpose).
 *
 * @param string[] $editors Editor classes.
 * @return string[]
 */
function brikpanel_returns_photo_editors( $editors ) {
	$out = array( 'WP_Image_Editor_GD' );
	if ( brikpanel_returns_define_imagick_editor() ) {
		$out[] = 'Brikpanel_Returns_Image_Editor_Imagick';
	}
	return $out;
}

/**
 * Load the Imagick subclass when WordPress' Imagick editor exists.
 *
 * @return bool
 */
function brikpanel_returns_define_imagick_editor() {
	if ( class_exists( 'Brikpanel_Returns_Image_Editor_Imagick', false ) ) {
		return true;
	}
	if ( ! class_exists( 'WP_Image_Editor', false ) ) {
		require_once ABSPATH . WPINC . '/class-wp-image-editor.php';
	}
	if ( ! class_exists( 'WP_Image_Editor_Imagick', false ) ) {
		require_once ABSPATH . WPINC . '/class-wp-image-editor-imagick.php';
	}
	if ( ! class_exists( 'WP_Image_Editor_Imagick', false ) ) {
		return false;
	}
	require_once __DIR__ . '/class-brikpanel-returns-image-editor-imagick.php';
	return class_exists( 'Brikpanel_Returns_Image_Editor_Imagick', false );
}

/**
 * Check one uploaded photo, re-encode it without metadata and store it.
 *
 * @param array $file One entry of $_FILES (name, tmp_name, size, error).
 * @param bool  $trusted Skip is_uploaded_file() (tests hand in a local file).
 * @return string|WP_Error Stored name.
 */
function brikpanel_returns_store_photo( array $file, $trusted = false ) {
	$bad = new WP_Error( 'brikpanel_returns_photo', __( 'One of the photos could not be used. Please send JPG, PNG or WebP images.', 'brikpanel' ) );
	if ( ! isset( $file['error'], $file['tmp_name'] ) || UPLOAD_ERR_OK !== (int) $file['error'] ) {
		if ( isset( $file['error'] ) && in_array( (int) $file['error'], array( UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE ), true ) ) {
			return brikpanel_returns_photo_size_error();
		}
		return $bad;
	}
	$tmp = (string) $file['tmp_name'];
	if ( ! $trusted && ! is_uploaded_file( $tmp ) ) {
		return $bad;
	}
	$size = (int) ( isset( $file['size'] ) ? $file['size'] : filesize( $tmp ) );
	if ( $size <= 0 || $size > brikpanel_returns_photo_max_bytes() ) {
		return brikpanel_returns_photo_size_error();
	}

	$mimes = array(
		'jpg|jpeg|jpe' => 'image/jpeg',
		'png'          => 'image/png',
		'webp'         => 'image/webp',
	);
	$check = wp_check_filetype_and_ext( $tmp, isset( $file['name'] ) ? sanitize_file_name( (string) $file['name'] ) : 'photo.jpg', $mimes );
	$info  = @getimagesize( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a broken image is a normal rejection, not a warning.
	$mime  = is_array( $info ) && isset( $info['mime'] ) ? (string) $info['mime'] : '';
	if ( empty( $check['type'] ) || ! in_array( $mime, array_values( $mimes ), true ) ) {
		return $bad;
	}

	$dir = brikpanel_returns_photo_dir( true );
	if ( ! $dir ) {
		return new WP_Error( 'brikpanel_returns_photo_dir', __( 'Photos cannot be saved in this store right now. Please send the request without photos.', 'brikpanel' ) );
	}

	add_filter( 'wp_image_editors', 'brikpanel_returns_photo_editors', 999 );
	$editor = wp_get_image_editor( $tmp, array( 'mime_type' => $mime ) );
	if ( is_wp_error( $editor ) ) {
		remove_filter( 'wp_image_editors', 'brikpanel_returns_photo_editors', 999 );
		return $bad;
	}
	if ( is_callable( array( $editor, 'maybe_exif_rotate' ) ) ) {
		$editor->maybe_exif_rotate();
	}
	$dims = $editor->get_size();
	if ( isset( $dims['width'], $dims['height'] ) && max( (int) $dims['width'], (int) $dims['height'] ) > BRIKPANEL_RETURNS_PHOTO_PX ) {
		$editor->resize( BRIKPANEL_RETURNS_PHOTO_PX, BRIKPANEL_RETURNS_PHOTO_PX, false );
	}
	if ( is_callable( array( $editor, 'brikpanel_strip' ) ) ) {
		$editor->brikpanel_strip();
	}
	$editor->set_quality( 85 );
	$ext   = 'image/png' === $mime ? 'png' : ( 'image/webp' === $mime ? 'webp' : 'jpg' );
	$name  = bin2hex( random_bytes( 16 ) ) . '.' . $ext;
	$saved = $editor->save( $dir . '/' . $name, $mime );
	remove_filter( 'wp_image_editors', 'brikpanel_returns_photo_editors', 999 );
	if ( is_wp_error( $saved ) || empty( $saved['path'] ) || ! file_exists( $dir . '/' . $name ) ) {
		return $bad;
	}
	return $name;
}

/**
 * @return WP_Error The "photo too large" error with the limit in it.
 */
function brikpanel_returns_photo_size_error() {
	return new WP_Error(
		'brikpanel_returns_photo_size',
		sprintf(
			/* translators: %s: size such as 5 MB */
			__( 'Each photo can be at most %s.', 'brikpanel' ),
			size_format( brikpanel_returns_photo_max_bytes() )
		)
	);
}

/**
 * @param string $name Stored name.
 * @return string Absolute path, '' when the name is not ours.
 */
function brikpanel_returns_photo_path( $name ) {
	if ( ! brikpanel_returns_photo_name_ok( $name ) ) {
		return '';
	}
	$dir = brikpanel_returns_photo_dir( false );
	return $dir ? $dir . '/' . $name : '';
}

/**
 * @param string[] $names Stored names.
 * @return void
 */
function brikpanel_returns_delete_photos( array $names ) {
	foreach ( $names as $name ) {
		$path = brikpanel_returns_photo_path( $name );
		if ( $path && file_exists( $path ) ) {
			wp_delete_file( $path );
		}
	}
}

/**
 * The address an admin's browser loads a photo from.
 *
 * @param int    $id   Return id.
 * @param string $name Stored name.
 * @return string
 */
function brikpanel_returns_photo_url( $id, $name ) {
	return add_query_arg(
		array(
			'action'   => 'brikpanel_return_photo',
			'return'   => (int) $id,
			'file'     => $name,
			'_wpnonce' => wp_create_nonce( 'brikpanel_return_photo_' . (int) $id ),
		),
		admin_url( 'admin-post.php' )
	);
}

add_action( 'admin_post_brikpanel_return_photo', 'brikpanel_returns_serve_photo' );
/**
 * Send a photo to someone who may handle orders.
 *
 * @return void
 */
function brikpanel_returns_serve_photo() {
	$id   = isset( $_GET['return'] ) ? absint( $_GET['return'] ) : 0;
	$name = isset( $_GET['file'] ) ? sanitize_file_name( wp_unslash( $_GET['file'] ) ) : '';
	if ( ! current_user_can( 'edit_shop_orders' ) || ! check_admin_referer( 'brikpanel_return_photo_' . $id ) ) {
		wp_die( esc_html__( 'You are not allowed to see this file.', 'brikpanel' ), '', array( 'response' => 403 ) );
	}
	$ret  = Brikpanel_Returns_Store::get( $id );
	$path = brikpanel_returns_photo_path( $name );
	if ( ! $ret || ! in_array( $name, $ret['photos'], true ) || ! $path || ! file_exists( $path ) ) {
		wp_die( esc_html__( 'This file no longer exists.', 'brikpanel' ), '', array( 'response' => 404 ) );
	}
	$types = array(
		'jpg'  => 'image/jpeg',
		'png'  => 'image/png',
		'webp' => 'image/webp',
	);
	$ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
	nocache_headers();
	header( 'Content-Type: ' . ( isset( $types[ $ext ] ) ? $types[ $ext ] : 'application/octet-stream' ) );
	header( 'X-Content-Type-Options: nosniff' );
	header( 'Content-Disposition: inline; filename="return-' . $id . '.' . $ext . '"' );
	header( 'Content-Length: ' . (string) filesize( $path ) );
	readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
	exit;
}

// =============================================================================
// GUEST ACCESS
// =============================================================================

/** Name of the cookie that carries a guest's access to one order. */
const BRIKPANEL_RETURNS_COOKIE = 'brikpanel_return_access';

/**
 * @param WC_Order $order   Order.
 * @param string   $purpose form|view.
 * @param int      $exp     Expiry (Unix time).
 * @return string Signature.
 */
function brikpanel_returns_token_sig( $order, $purpose, $exp ) {
	$data = get_current_blog_id() . '|' . $order->get_id() . '|' . $purpose . '|' . (int) $exp . '|' . strtolower( (string) $order->get_billing_email() ) . '|' . $order->get_order_key();
	return substr( hash_hmac( 'sha256', $data, wp_salt( 'brikpanel_returns' ) ), 0, 32 );
}

/**
 * A signed, expiring proof that the holder knows this order's number and
 * email. Changing the billing email or the order key voids it.
 *
 * @param WC_Order $order   Order.
 * @param string   $purpose form (2 hours, from the lookup) or view (30 days, in emails).
 * @return string
 */
function brikpanel_returns_token( $order, $purpose = 'form' ) {
	$ttl = 'view' === $purpose ? 30 * DAY_IN_SECONDS : 2 * HOUR_IN_SECONDS;
	$exp = (int) apply_filters( 'brikpanel_returns_now', time() ) + $ttl;
	return 'v1.' . $order->get_id() . '.' . $purpose . '.' . $exp . '.' . brikpanel_returns_token_sig( $order, $purpose, $exp );
}

/**
 * @param string $token    Token.
 * @param int    $order_id When given, the token must be for this order.
 * @return WC_Order|false
 */
function brikpanel_returns_token_order( $token, $order_id = 0 ) {
	$parts = explode( '.', (string) $token );
	if ( 5 !== count( $parts ) || 'v1' !== $parts[0] || ! in_array( $parts[2], array( 'form', 'view' ), true ) ) {
		return false;
	}
	$oid = absint( $parts[1] );
	$exp = (int) $parts[3];
	if ( ! $oid || ( $order_id && $oid !== (int) $order_id ) || $exp < (int) apply_filters( 'brikpanel_returns_now', time() ) ) {
		return false;
	}
	$order = wc_get_order( $oid );
	if ( ! $order instanceof WC_Order || 'shop_order' !== $order->get_type() ) {
		return false;
	}
	return hash_equals( brikpanel_returns_token_sig( $order, $parts[2], $exp ), (string) $parts[4] ) ? $order : false;
}

/**
 * Find a guest's order the way WooCommerce's own order tracking form does:
 * the typed number goes through woocommerce_shortcode_order_tracking_order_id
 * (BrikPanel's sequential numbers read order_email from the request to tell
 * duplicates apart), then the billing email must match.
 *
 * @param string $number Typed order number.
 * @param string $email  Typed email.
 * @return WC_Order|false
 */
function brikpanel_returns_lookup_order( $number, $email ) {
	$number = ltrim( wc_clean( (string) $number ), '#' );
	$email  = strtolower( sanitize_email( (string) $email ) );
	if ( '' === $number || '' === $email ) {
		return false;
	}
	$order_id = apply_filters( 'woocommerce_shortcode_order_tracking_order_id', $number ); // phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- WooCommerce's own hook, same use as its tracking form.
	$order    = wc_get_order( $order_id );
	if ( ! $order instanceof WC_Order || 'shop_order' !== $order->get_type() ) {
		return false;
	}
	return strtolower( (string) $order->get_billing_email() ) === $email ? $order : false;
}

/**
 * Fixed-window counters for failed lookups.
 *
 * @param string $number Typed order number.
 * @return array<string,array{key:string,limit:int,window:int}>
 */
function brikpanel_returns_lookup_buckets( $number ) {
	$buckets = array();
	$client  = function_exists( 'brikpanel_client_bucket_key' ) ? brikpanel_client_bucket_key( 'returns_lookup' ) : '';
	if ( '' !== $client ) {
		$buckets['client'] = array(
			'key'    => $client,
			'limit'  => 5,
			'window' => 15 * MINUTE_IN_SECONDS,
		);
	}
	$buckets['number'] = array(
		'key'    => 'bp_ret_ln_' . substr( md5( strtolower( (string) $number ) ), 0, 20 ),
		'limit'  => 10,
		'window' => HOUR_IN_SECONDS,
	);
	$buckets['store'] = array(
		'key'    => 'bp_ret_lall',
		'limit'  => 200,
		'window' => HOUR_IN_SECONDS,
	);
	return $buckets;
}

/**
 * Whether failed lookups have used up a window.
 *
 * @param string $number Typed order number.
 * @return bool
 */
function brikpanel_returns_lookup_blocked( $number ) {
	foreach ( brikpanel_returns_lookup_buckets( $number ) as $bucket ) {
		$state = get_transient( $bucket['key'] );
		if ( is_array( $state ) && isset( $state['n'] ) && (int) $state['n'] >= $bucket['limit'] ) {
			return true;
		}
	}
	return false;
}

/**
 * Count one failed lookup. Successful ones are never counted, so a customer
 * who types correctly is never locked out.
 *
 * @param string $number Typed order number.
 * @return void
 */
function brikpanel_returns_lookup_failed( $number ) {
	$now = (int) apply_filters( 'brikpanel_returns_now', time() );
	foreach ( brikpanel_returns_lookup_buckets( $number ) as $bucket ) {
		$state = get_transient( $bucket['key'] );
		if ( ! is_array( $state ) || ! isset( $state['n'], $state['s'] ) ) {
			$state = array(
				'n' => 0,
				's' => $now,
			);
		}
		$state['n'] = (int) $state['n'] + 1;
		$left       = max( 1, $bucket['window'] - ( $now - (int) $state['s'] ) );
		set_transient( $bucket['key'], $state, $left );
	}
}

// =============================================================================
// PRIVACY
// =============================================================================

add_filter( 'wp_privacy_personal_data_exporters', 'brikpanel_returns_register_exporter' );
/**
 * @param array $exporters Exporters.
 * @return array
 */
function brikpanel_returns_register_exporter( $exporters ) {
	$exporters['brikpanel-returns'] = array(
		'exporter_friendly_name' => __( 'Return requests', 'brikpanel' ),
		'callback'               => 'brikpanel_returns_privacy_export',
	);
	return $exporters;
}

add_filter( 'wp_privacy_personal_data_erasers', 'brikpanel_returns_register_eraser' );
/**
 * @param array $erasers Erasers.
 * @return array
 */
function brikpanel_returns_register_eraser( $erasers ) {
	$erasers['brikpanel-returns'] = array(
		'eraser_friendly_name' => __( 'Return requests', 'brikpanel' ),
		'callback'             => 'brikpanel_returns_privacy_erase',
	);
	return $erasers;
}

/**
 * @param string $email Email address.
 * @param int    $page  Page.
 * @return array
 */
function brikpanel_returns_privacy_export( $email, $page = 1 ) {
	$user = get_user_by( 'email', $email );
	$rows = Brikpanel_Returns_Store::for_person( $email, $user ? (int) $user->ID : 0, $page, 50 );
	$data = array();
	foreach ( $rows as $ret ) {
		$order = wc_get_order( $ret['order_id'] );
		$items = array();
		foreach ( $ret['items'] as $line ) {
			if ( empty( $line['parent'] ) ) {
				/* translators: 1: units, 2: product name */
				$items[] = sprintf( _x( '%1$s × %2$s', 'units × product', 'brikpanel' ), (int) $line['qty'], $line['name'] );
			}
		}
		$fields = array(
			array(
				'name'  => __( 'Return', 'brikpanel' ),
				'value' => '#' . $ret['id'],
			),
			array(
				'name'  => __( 'Order', 'brikpanel' ),
				'value' => $order ? '#' . $order->get_order_number() : '#' . $ret['order_id'],
			),
			array(
				'name'  => __( 'Status', 'brikpanel' ),
				'value' => brikpanel_returns_status_label( $ret['status'] ),
			),
			array(
				'name'  => __( 'Requested on', 'brikpanel' ),
				'value' => $ret['created_at'],
			),
			array(
				'name'  => __( 'Items', 'brikpanel' ),
				'value' => implode( ', ', $items ),
			),
			array(
				'name'  => __( 'Reason', 'brikpanel' ),
				'value' => $ret['reason'],
			),
			array(
				'name'  => __( 'Note', 'brikpanel' ),
				'value' => $ret['comment'],
			),
			array(
				'name'  => __( 'Email', 'brikpanel' ),
				'value' => $ret['email'],
			),
		);
		if ( ! empty( $ret['statement'] ) ) {
			$fields[] = array(
				'name'  => __( 'Withdrawal statement', 'brikpanel' ),
				'value' => trim( ( isset( $ret['statement']['name'] ) ? $ret['statement']['name'] : '' ) . ' ' . ( isset( $ret['statement']['text'] ) ? $ret['statement']['text'] : '' ) ),
			);
		}
		$data[] = array(
			'group_id'    => 'brikpanel-returns',
			'group_label' => __( 'Return requests', 'brikpanel' ),
			'item_id'     => 'brikpanel-return-' . $ret['id'],
			'data'        => $fields,
		);
	}
	return array(
		'data' => $data,
		'done' => count( $rows ) < 50,
	);
}

/**
 * Remove a person's details from a return, keep the business record.
 *
 * @param array $ret Return.
 * @return bool
 */
function brikpanel_returns_anonymise( array $ret ) {
	brikpanel_returns_delete_photos( $ret['photos'] );
	$statement = $ret['statement'];
	if ( $statement ) {
		$statement['name']  = '';
		$statement['email'] = '';
		$statement['text']  = '';
	}
	return Brikpanel_Returns_Store::patch(
		$ret['id'],
		$ret['version'],
		array(
			'email'       => function_exists( 'wp_privacy_anonymize_data' ) ? wp_privacy_anonymize_data( 'email', $ret['email'] ) : '',
			'comment'     => '',
			'photos'      => array(),
			'statement'   => $statement,
			'customer_id' => 0,
		)
	);
}

/**
 * @param string $email Email address.
 * @param int    $page  Page.
 * @return array
 */
function brikpanel_returns_privacy_erase( $email, $page = 1 ) {
	unset( $page );
	$user    = get_user_by( 'email', $email );
	$rows    = Brikpanel_Returns_Store::for_person( $email, $user ? (int) $user->ID : 0, 1, 50 );
	$removed = false;
	foreach ( $rows as $ret ) {
		$removed = brikpanel_returns_anonymise( $ret ) || $removed;
	}
	return array(
		'items_removed'  => $removed,
		'items_retained' => false,
		'messages'       => array(),
		'done'           => count( $rows ) < 50,
	);
}

add_action( 'woocommerce_privacy_remove_order_personal_data', 'brikpanel_returns_privacy_order' );
/**
 * WooCommerce anonymises an order: its returns follow.
 *
 * @param WC_Order $order Order.
 * @return void
 */
function brikpanel_returns_privacy_order( $order ) {
	if ( ! $order instanceof WC_Order ) {
		return;
	}
	foreach ( Brikpanel_Returns_Store::for_order( $order->get_id() ) as $ret ) {
		brikpanel_returns_anonymise( $ret );
	}
}

add_action( 'woocommerce_before_delete_order', 'brikpanel_returns_on_order_deleted', 10, 1 );
/**
 * An order deleted for good takes its returns and their photos along
 * (moving it to the trash keeps them).
 *
 * @param int $order_id Order id.
 * @return void
 */
function brikpanel_returns_on_order_deleted( $order_id ) {
	foreach ( Brikpanel_Returns_Store::delete_for_order( (int) $order_id ) as $ret ) {
		brikpanel_returns_delete_photos( $ret['photos'] );
	}
}
