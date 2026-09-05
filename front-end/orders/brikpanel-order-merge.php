<?php
/**
 * BrikPanel — Order merging.
 *
 * Combines two or more orders into a single order from the orders list. Manual
 * by design: the merchant ticks the orders, a preview screen spells out exactly
 * what will move and what the merged order will cost, and only the confirm
 * button writes anything.
 *
 * WHY THE ORDER OF OPERATIONS IS WHAT IT IS
 * -----------------------------------------
 * Line items are moved to the target FIRST and the source is cancelled LAST.
 * That single ordering is what keeps three separate WooCommerce ledgers honest,
 * because all three walk the cancelled order's own items:
 *
 *   - wc_maybe_increase_stock_levels() → wc_increase_stock_levels() loops over
 *     $order->get_items(). An emptied source restocks nothing, so stock is not
 *     double-counted. The per-item `_reduced_stock` meta travels with the row,
 *     so the target restocks correctly if it is ever cancelled.
 *   - wc_update_total_sales_counts() likewise loops over the order's items, so
 *     product total_sales is not decremented for items that did not go away.
 *   - wc_update_coupon_usage_counts() reads the order's coupon codes.
 *
 * THE TRAP THIS MODULE EXISTS TO AVOID
 * ------------------------------------
 * WC_Abstract_Order::save_items() re-asserts ownership of every item it holds
 * in memory: `$item->set_order_id( $this->get_id() ); $item->save();`. A source
 * order object that was read BEFORE the move still holds the moved items, so
 * saving it — which update_status() does — drags them straight back and silently
 * undoes the merge. Clearing the item cache is not enough on its own; the order
 * object itself has to be dropped and re-read. brikpanel_order_merge_flush_order()
 * does both, and it is called on the source before the source is ever saved.
 *
 * Nothing is deleted. Sources are cancelled and annotated with the target they
 * went into, so the order number a customer quotes still resolves.
 *
 * @package BrikPanel
 * @since   3.2.96
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// =============================================================================
// KEYS
// =============================================================================
const BRIKPANEL_ORDER_MERGE_OPTION       = 'brikpanel_order_merge';
const BRIKPANEL_ORDER_MERGE_ACTION       = 'brikpanel_merge_orders';
const BRIKPANEL_ORDER_MERGE_PAGE         = 'brikpanel-merge-orders';
const BRIKPANEL_ORDER_MERGE_META_INTO    = '_brikpanel_merged_into';
const BRIKPANEL_ORDER_MERGE_META_FROM    = '_brikpanel_merged_from';
const BRIKPANEL_ORDER_MERGE_META_NUMBERS = '_brikpanel_merged_from_numbers';
const BRIKPANEL_ORDER_MERGE_META_PENDING = '_brikpanel_merge_pending';
const BRIKPANEL_ORDER_MERGE_TRANSIENT    = 'brikpanel_merge_';

/**
 * Upper bound on one merge. Not a technical limit — a guard rail. Merging forty
 * orders in one click is far more likely to be a mis-click than an intention,
 * and every source order is a one-way cancellation.
 */
const BRIKPANEL_ORDER_MERGE_MAX = 20;

// =============================================================================
// GATES
// =============================================================================

/**
 * Whether the feature is switched on for this store.
 *
 * @return bool
 */
function brikpanel_order_merge_enabled() {
	return get_option( BRIKPANEL_ORDER_MERGE_OPTION, 'yes' ) !== 'no';
}

/**
 * Whether the current user may merge orders. Merging cancels orders and moves
 * money between them, so it rides on the same capability WooCommerce uses for
 * editing an order rather than a read-level one.
 *
 * @return bool
 */
function brikpanel_order_merge_user_can() {
	return current_user_can( 'edit_shop_orders' );
}

/**
 * Both gates, plus BrikPanel's own access rules.
 *
 * The master switch and per-user "personal mode" hand a merchant the stock
 * WooCommerce admin back. They neutralize the gated interface OPTIONS on their
 * own, but this feature has no entry in that list: like the extra order-list
 * columns, it is an unconditional addition to a WooCommerce screen, so it has
 * to check `brikpanel_access_should_neutralize()` itself or a merged-away
 * BrikPanel would still be putting its bulk action on the orders list.
 *
 * @return bool
 */
function brikpanel_order_merge_active() {
	if ( function_exists( 'brikpanel_access_should_neutralize' ) && brikpanel_access_should_neutralize() ) {
		return false;
	}
	return brikpanel_order_merge_enabled() && brikpanel_order_merge_user_can();
}

// =============================================================================
// SMALL HELPERS
// =============================================================================

/**
 * Drop every cached trace of an order AND hand back a freshly read object.
 *
 * Three caches hold order state and they are not cleared by the same call:
 *   - 'order-items-{id}' in the 'orders' group is what WC_Order_Data_Store_CPT
 *     reads items from. The item data store's own clear_cache() only busts the
 *     key for the item's CURRENT order id, which after a move is the TARGET, so
 *     the source's key survives with the moved items still in it.
 *   - 'order-needs-processing-{id}', same group, derived from the items.
 *   - The HPOS OrderCache, which can hand back the very same object instance.
 *
 * @param int $order_id Order to flush.
 * @return WC_Order|null Freshly read order, or null when it no longer exists.
 */
function brikpanel_order_merge_flush_order( $order_id ) {
	$order_id = absint( $order_id );
	if ( ! $order_id ) {
		return null;
	}

	wp_cache_delete( 'order-items-' . $order_id, 'orders' );
	wp_cache_delete( 'order-needs-processing-' . $order_id, 'orders' );

	if ( function_exists( 'wc_get_container' ) && class_exists( '\Automattic\WooCommerce\Caches\OrderCache' ) ) {
		try {
			wc_get_container()->get( \Automattic\WooCommerce\Caches\OrderCache::class )->remove( $order_id );
		} catch ( \Throwable $e ) {
			// A container without the cache service is fine — nothing to drop.
			unset( $e );
		}
	}

	$order = wc_get_order( $order_id );
	return $order instanceof WC_Abstract_Order ? $order : null;
}

/**
 * Order number, isolated for right-to-left admin languages so a numeric order
 * number never reorders itself inside a translated sentence.
 *
 * @param WC_Abstract_Order $order Order.
 * @return string
 */
function brikpanel_order_merge_number( $order ) {
	$number = (string) $order->get_order_number();
	return function_exists( 'brikpanel_bidi_isolate_ltr' )
		? brikpanel_bidi_isolate_ltr( $number )
		: $number;
}

/**
 * Load orders by id, oldest first. The oldest is the default merge target: it
 * carries the order number the customer has known the longest.
 *
 * @param int[] $ids Order ids.
 * @return array<int,WC_Order> Keyed by order id, oldest first.
 */
function brikpanel_order_merge_load( array $ids ) {
	$out = [];
	foreach ( $ids as $id ) {
		$order = wc_get_order( absint( $id ) );
		if ( $order instanceof WC_Order && 'shop_order' === $order->get_type() ) {
			$out[ $order->get_id() ] = $order;
		}
	}

	uasort(
		$out,
		static function ( $a, $b ) {
			$da = $a->get_date_created();
			$db = $b->get_date_created();
			$ta = $da ? $da->getTimestamp() : 0;
			$tb = $db ? $db->getTimestamp() : 0;
			if ( $ta === $tb ) {
				return $a->get_id() <=> $b->get_id();
			}
			return $ta <=> $tb;
		}
	);

	return $out;
}

/**
 * Whether an order counts as a realised sale under the merchant's own analytics
 * settings. Never hardcode processing/completed here — stores that take offline
 * payments or run shipment-tracking statuses widen this list.
 *
 * @param WC_Abstract_Order $order Order.
 * @return bool
 */
function brikpanel_order_merge_is_paid( $order ) {
	$paid = function_exists( 'brikpanel_paid_order_statuses' )
		? brikpanel_paid_order_statuses()
		: [ 'wc-processing', 'wc-completed' ];
	return in_array( 'wc-' . $order->get_status(), $paid, true );
}

/**
 * Why this order cannot take part in a merge, or '' when it can.
 *
 * @param WC_Abstract_Order $order Order.
 * @return string Translated reason, empty when the order is eligible.
 */
function brikpanel_order_merge_block_reason( $order ) {
	if ( $order->get_meta( BRIKPANEL_ORDER_MERGE_META_INTO ) ) {
		return __( 'Already merged into another order.', 'brikpanel' );
	}

	if ( 'trash' === $order->get_status() ) {
		return __( 'Order is in the trash.', 'brikpanel' );
	}

	// A refund pins money to this specific order: the refund rows reference item
	// ids that are about to move, and the amount refunded no longer matches what
	// the items are worth. There is no safe automatic answer, so refuse.
	if ( (float) $order->get_total_refunded() > 0 || count( $order->get_refunds() ) > 0 ) {
		return __( 'Order has a refund on it.', 'brikpanel' );
	}

	$refunded = function_exists( 'brikpanel_refunded_order_statuses' )
		? brikpanel_refunded_order_statuses()
		: [ 'wc-refunded' ];
	if ( in_array( 'wc-' . $order->get_status(), $refunded, true ) ) {
		return __( 'Order is refunded.', 'brikpanel' );
	}

	return '';
}

/**
 * The coupon codes already present on an order, lowercased for comparison.
 *
 * @param WC_Abstract_Order $order Order.
 * @return string[]
 */
function brikpanel_order_merge_coupon_codes( $order ) {
	$codes = [];
	foreach ( $order->get_items( 'coupon' ) as $coupon ) {
		$code = strtolower( trim( (string) $coupon->get_code() ) );
		if ( '' !== $code ) {
			$codes[] = $code;
		}
	}
	return $codes;
}

// =============================================================================
// ANALYSIS — everything the preview screen and the executor both need to know.
// =============================================================================

/**
 * Work out which shipping line survives the merge.
 *
 * "single" keeps one shipping charge because one merged order ships as one
 * parcel. The keeper is the most expensive line across every order, so the
 * merchant never silently loses the express rate a customer paid for. "sum"
 * keeps every line, which is the honest choice when the customer really was
 * charged twice and is not being refunded the difference.
 *
 * @param WC_Abstract_Order      $target  Target order.
 * @param array<int,WC_Order>    $sources Source orders keyed by id.
 * @param string                 $mode    'single' or 'sum'.
 * @return array{keep_source_item:int,drop_target_items:int[],move_all:bool}
 */
function brikpanel_order_merge_shipping_plan( $target, array $sources, $mode ) {
	$plan = [
		'keep_source_item'  => 0,
		'drop_target_items' => [],
		'move_all'          => ( 'sum' === $mode ),
	];

	if ( 'sum' === $mode ) {
		return $plan;
	}

	$best_total  = null;
	$best_item   = 0;
	$best_on_tgt = true;

	foreach ( $target->get_items( 'shipping' ) as $item ) {
		$total = (float) $item->get_total();
		if ( null === $best_total || $total > $best_total ) {
			$best_total  = $total;
			$best_item   = $item->get_id();
			$best_on_tgt = true;
		}
	}

	foreach ( $sources as $source ) {
		foreach ( $source->get_items( 'shipping' ) as $item ) {
			$total = (float) $item->get_total();
			if ( null === $best_total || $total > $best_total ) {
				$best_total  = $total;
				$best_item   = $item->get_id();
				$best_on_tgt = false;
			}
		}
	}

	if ( ! $best_on_tgt && $best_item ) {
		// A source line won: it moves over and the target's own lines go.
		$plan['keep_source_item'] = $best_item;
		foreach ( $target->get_items( 'shipping' ) as $item ) {
			$plan['drop_target_items'][] = $item->get_id();
		}
	}

	return $plan;
}

/**
 * Full pre-flight report for a set of orders: what is blocked, what deserves a
 * warning, and what the merged order will look like.
 *
 * Runs identically from the preview screen and from the confirm handler — the
 * handler never trusts the preview, because minutes can pass in between and an
 * order can be refunded or trashed in that window.
 *
 * @param int[]  $ids           Selected order ids.
 * @param int    $target_id     Chosen target, 0 to default to the oldest.
 * @param string $shipping_mode 'single' or 'sum'.
 * @return array
 */
function brikpanel_order_merge_analyse( array $ids, $target_id = 0, $shipping_mode = 'single' ) {
	$orders  = brikpanel_order_merge_load( $ids );
	$report  = [
		'orders'        => $orders,
		'blocked'       => [],
		'fatal'         => [],
		'warnings'      => [],
		'target_id'     => 0,
		'source_ids'    => [],
		'shipping_mode' => ( 'sum' === $shipping_mode ? 'sum' : 'single' ),
		'new_total'     => 0.0,
		'item_count'    => 0,
		'currency'      => '',
	];

	if ( count( $orders ) < 2 ) {
		$report['fatal'][] = __( 'Pick at least two orders that still exist.', 'brikpanel' );
		return $report;
	}

	// --- Per-order blockers ------------------------------------------------
	foreach ( $orders as $id => $order ) {
		$reason = brikpanel_order_merge_block_reason( $order );
		if ( '' !== $reason ) {
			$report['blocked'][ $id ] = $reason;
		}
	}

	// --- Cross-order blockers ----------------------------------------------
	$currencies = [];
	$tax_modes  = [];
	foreach ( $orders as $order ) {
		$currencies[] = $order->get_currency();
		$tax_modes[]  = $order->get_prices_include_tax() ? 'inc' : 'exc';
	}
	$report['currency'] = $currencies ? $currencies[0] : get_woocommerce_currency();

	if ( count( array_unique( $currencies ) ) > 1 ) {
		$report['fatal'][] = __( 'These orders are in different currencies. Merging them would produce a meaningless total.', 'brikpanel' );
	}
	if ( count( array_unique( $tax_modes ) ) > 1 ) {
		// One order stores prices with tax folded in and the other stores them
		// without. Adding those line totals together is arithmetic nonsense and
		// it fails silently, so this is a hard stop rather than a warning.
		$report['fatal'][] = __( 'These orders do not agree on whether prices include tax, so their totals cannot be added together.', 'brikpanel' );
	}

	if ( $report['blocked'] ) {
		$report['fatal'][] = __( 'Remove the orders listed below from the selection, then try again.', 'brikpanel' );
	}
	if ( $report['fatal'] ) {
		return $report;
	}

	// --- Target ------------------------------------------------------------
	$ordered_ids = array_keys( $orders );
	$target_id   = absint( $target_id );
	if ( ! $target_id || ! isset( $orders[ $target_id ] ) ) {
		$target_id = $ordered_ids[0]; // oldest
	}
	$report['target_id']  = $target_id;
	$report['source_ids'] = array_values( array_diff( $ordered_ids, [ $target_id ] ) );

	$target  = $orders[ $target_id ];
	$sources = [];
	foreach ( $report['source_ids'] as $sid ) {
		$sources[ $sid ] = $orders[ $sid ];
	}

	// --- Projected result ---------------------------------------------------
	$plan  = brikpanel_order_merge_shipping_plan( $target, $sources, $report['shipping_mode'] );
	$total = 0.0;
	$count = 0;

	foreach ( $orders as $id => $order ) {
		foreach ( $order->get_items( [ 'line_item', 'fee' ] ) as $item ) {
			$total += (float) $item->get_total() + (float) $item->get_total_tax();
			if ( $item->is_type( 'line_item' ) ) {
				$count += (int) $item->get_quantity();
			}
		}
		foreach ( $order->get_items( 'shipping' ) as $item ) {
			$keep = $plan['move_all']
				|| ( $id === $target_id && ! $plan['keep_source_item'] )
				|| ( $item->get_id() === $plan['keep_source_item'] );
			if ( $keep ) {
				$total += (float) $item->get_total() + (float) $item->get_total_tax();
			}
		}
	}

	$report['new_total']  = $total;
	$report['item_count'] = $count;
	$report['plan']       = $plan;

	// --- Warnings -----------------------------------------------------------
	$paid_sources = [];
	foreach ( $sources as $sid => $source ) {
		if ( brikpanel_order_merge_is_paid( $source ) ) {
			$paid_sources[] = $sid;
		}
	}

	if ( $paid_sources ) {
		$report['warnings'][] = __( 'A paid order is being merged away. Its payment record stays on the cancelled order, and only the products move.', 'brikpanel' );

		if ( ! brikpanel_order_merge_is_paid( $target ) ) {
			$report['warnings'][] = __( 'The main order you picked is not a paid one, so your revenue figures will drop by the amount of the paid orders being cancelled. Picking a paid order as the main order avoids this.', 'brikpanel' );
			$report['suggest_paid_status'] = true;
		}
	}

	// Revenue is dated by the order, so merging into a newer order moves an
	// older day's sales onto a newer day.
	$target_date = $target->get_date_created();
	foreach ( $sources as $source ) {
		$sdate = $source->get_date_created();
		if ( $target_date && $sdate && $sdate->getTimestamp() < $target_date->getTimestamp()
			&& brikpanel_order_merge_is_paid( $source ) ) {
			$report['warnings'][] = __( 'Revenue from an older order will be re-dated to the main order, so past-day reports will shift.', 'brikpanel' );
			break;
		}
	}

	// Customer mismatch. A warning rather than a stop: the same person often
	// has two accounts, or checked out as a guest once. But merging genuinely
	// different customers puts one person's items on another's order, which
	// they can then see in My Account, so it is called out loudly.
	$mismatch = brikpanel_order_merge_customer_mismatch( $orders );
	if ( $mismatch ) {
		$report['warnings'][] = sprintf(
			/* translators: %s: comma-separated list of customer detail names that differ, e.g. "email, phone". */
			__( 'These orders do not look like the same customer. Different: %s. Check before you merge, because the merged order keeps only the main order\'s customer details.', 'brikpanel' ),
			implode( ', ', $mismatch )
		);
	}

	// Coupons that exist on a source but not on the target.
	$target_codes = brikpanel_order_merge_coupon_codes( $target );
	$extra_coupon = false;
	foreach ( $sources as $source ) {
		foreach ( brikpanel_order_merge_coupon_codes( $source ) as $code ) {
			if ( in_array( $code, $target_codes, true ) ) {
				$extra_coupon = true;
			}
		}
	}
	if ( $extra_coupon ) {
		$report['warnings'][] = __( 'The same coupon is on more than one of these orders. Each discount is already baked into its own products and stays correct; the coupon is counted once on the merged order.', 'brikpanel' );
	}

	return $report;
}

/**
 * Which customer details differ across the selected orders.
 *
 * @param array<int,WC_Order> $orders Orders.
 * @return string[] Translated field names that differ.
 */
function brikpanel_order_merge_customer_mismatch( array $orders ) {
	$emails    = [];
	$phones    = [];
	$customers = [];
	$addresses = [];

	foreach ( $orders as $order ) {
		$emails[] = strtolower( trim( (string) $order->get_billing_email() ) );

		$raw   = (string) $order->get_billing_phone();
		$phone = function_exists( 'brikpanel_phone_to_e164' )
			? brikpanel_phone_to_e164( $raw, (string) $order->get_billing_country() )
			: preg_replace( '/\D+/', '', $raw );
		$phones[] = (string) $phone;

		$customers[] = (int) $order->get_customer_id();

		$addresses[] = strtolower(
			trim(
				implode(
					'|',
					[
						$order->get_shipping_address_1() ?: $order->get_billing_address_1(),
						$order->get_shipping_postcode() ?: $order->get_billing_postcode(),
						$order->get_shipping_city() ?: $order->get_billing_city(),
					]
				)
			)
		);
	}

	$differs = static function ( array $values ) {
		$values = array_values( array_filter( $values, static fn( $v ) => '' !== $v && 0 !== $v ) );
		return count( $values ) > 1 && count( array_unique( $values ) ) > 1;
	};

	$out = [];
	if ( $differs( $customers ) ) {
		$out[] = __( 'account', 'brikpanel' );
	}
	if ( $differs( $emails ) ) {
		$out[] = __( 'email', 'brikpanel' );
	}
	if ( $differs( $phones ) ) {
		$out[] = __( 'phone', 'brikpanel' );
	}
	if ( $differs( $addresses ) ) {
		$out[] = __( 'address', 'brikpanel' );
	}

	return $out;
}

// =============================================================================
// EXECUTION
// =============================================================================

/**
 * Bring the target's order-level stock flag in line with the items it now holds.
 *
 * `_reduced_stock` is per item and travels with the row, but `_order_stock_reduced`
 * is per order and does not. Left wrong, a target that inherits already-reduced
 * items but has no flag would fail to restock them if it were ever cancelled.
 *
 * @param WC_Abstract_Order $target Target order (already saved).
 * @return void
 */
function brikpanel_order_merge_sync_stock_flag( $target ) {
	$has_reduced   = false;
	$has_unreduced = false;

	foreach ( $target->get_items( 'line_item' ) as $item ) {
		$product = $item->get_product();
		if ( ! $product || ! $product->managing_stock() ) {
			continue;
		}
		if ( $item->get_meta( '_reduced_stock', true ) ) {
			$has_reduced = true;
		} else {
			$has_unreduced = true;
		}
	}

	if ( ! $has_reduced ) {
		return; // Nothing here ever came out of stock; leave the flag alone.
	}

	if ( ! $has_unreduced ) {
		$target->get_data_store()->set_stock_reduced( $target->get_id(), true );
		return;
	}

	// Mixed: some items already came out of stock, some never did. Leaving the
	// flag off and asking WooCommerce to reduce is the safe move — it skips the
	// items that already carry `_reduced_stock`, takes only the missing ones,
	// and sets the flag itself once every item is accounted for.
	if ( in_array( $target->get_status(), [ 'processing', 'completed', 'on-hold' ], true ) ) {
		wc_maybe_reduce_stock_levels( $target->get_id() );
	}
}

/**
 * Silence the transactional mail a merge would otherwise generate.
 *
 * Cancelling a source order is bookkeeping, not a cancellation the customer
 * asked for. Letting WooCommerce mail them "your order is cancelled" while
 * their goods are on their way in the merged order would be actively wrong.
 *
 * @param bool $on True to mute, false to restore.
 * @return void
 */
function brikpanel_order_merge_mute_mail( $on ) {
	// The optional "keep the paid status" promotion moves the target into
	// processing, which is why the processing/new-order pair is muted too: the
	// customer already had that mail when they first paid.
	//
	// Deliberately NOT __return_false: other plugins mute the very same filters
	// with it (BrikMarket does, on three of these), and remove_filter() matches
	// on the callback, so sharing one would let this module tear down another
	// plugin's suppression for the rest of the request. Our own callback can
	// only ever remove our own hook.
	$filters = [
		'woocommerce_email_enabled_cancelled_order',
		'woocommerce_email_enabled_customer_processing_order',
		'woocommerce_email_enabled_new_order',
		'brikpanel_status_email_should_send',
	];

	foreach ( $filters as $filter ) {
		if ( $on ) {
			add_filter( $filter, 'brikpanel_order_merge_suppress', 99 );
		} else {
			remove_filter( $filter, 'brikpanel_order_merge_suppress', 99 );
		}
	}
}

/**
 * Filter callback used only while a merge is running. Owned by this module so
 * removing it can never disturb another plugin's identical hook.
 *
 * @return bool Always false.
 */
function brikpanel_order_merge_suppress() {
	return false;
}

/**
 * Perform the merge.
 *
 * Sequence, and why it is this way, is documented at the top of the file. In
 * short: move, verify, flush, cancel — per source, so a failure part way through
 * leaves earlier sources properly merged and later ones completely untouched.
 * The one irreversible step (cancelling) never runs for a source whose items did
 * not verifiably move.
 *
 * @param int    $target_id     Order that survives.
 * @param int[]  $source_ids    Orders merged into it.
 * @param string $shipping_mode 'single' or 'sum'.
 * @param bool   $keep_paid     Promote an unpaid target to the sources' paid status.
 * @return array{merged:int[],failed:array<int,string>,error:string}
 */
function brikpanel_order_merge_execute( $target_id, array $source_ids, $shipping_mode, $keep_paid = false ) {
	global $wpdb;

	$result = [ 'merged' => [], 'failed' => [], 'error' => '' ];

	$target = wc_get_order( absint( $target_id ) );
	if ( ! $target instanceof WC_Order ) {
		$result['error'] = __( 'The main order could not be loaded.', 'brikpanel' );
		return $result;
	}

	$sources = [];
	foreach ( $source_ids as $sid ) {
		$sid = absint( $sid );
		if ( ! $sid || $sid === $target->get_id() ) {
			continue;
		}
		$order = wc_get_order( $sid );
		if ( $order instanceof WC_Order ) {
			$sources[ $sid ] = $order;
		} else {
			$result['failed'][ $sid ] = __( 'Order no longer exists.', 'brikpanel' );
		}
	}

	if ( ! $sources ) {
		$result['error'] = __( 'There was nothing left to merge.', 'brikpanel' );
		return $result;
	}

	$shipping_mode = ( 'sum' === $shipping_mode ) ? 'sum' : 'single';
	$plan          = brikpanel_order_merge_shipping_plan( $target, $sources, $shipping_mode );
	$items_table   = $wpdb->prefix . 'woocommerce_order_items';

	// A breadcrumb, so a request that dies half way through leaves something an
	// admin notice can point at rather than a silently odd-looking order.
	$target->update_meta_data(
		BRIKPANEL_ORDER_MERGE_META_PENDING,
		[ 'sources' => array_keys( $sources ), 'time' => time() ]
	);
	$target->save();

	brikpanel_order_merge_mute_mail( true );

	$target_codes  = brikpanel_order_merge_coupon_codes( $target );
	$dropped_ship  = [];
	$moved_numbers = [];

	try {
		foreach ( $sources as $sid => $source ) {
			try {
				$expected = [];

				// --- Products and fees: everything moves. ---------------------
				foreach ( $source->get_items( [ 'line_item', 'fee' ] ) as $item ) {
					$target->add_item( $item );
					$expected[] = (int) $item->get_id();
				}

				// --- Shipping: per the chosen mode. ---------------------------
				foreach ( $source->get_items( 'shipping' ) as $item ) {
					if ( $plan['move_all'] || (int) $item->get_id() === (int) $plan['keep_source_item'] ) {
						$target->add_item( $item );
						$expected[] = (int) $item->get_id();
					}
				}

				// --- Coupons -------------------------------------------------
				// A code the target does not have moves across, so it stays
				// counted once and the merged order shows the discount it really
				// carries. A code the target ALREADY has stays behind, so the
				// source's cancellation decrements the usage count exactly once
				// and the target keeps holding its own single count. Its amount
				// is folded into the target's line so the figure on screen still
				// matches the money.
				foreach ( $source->get_items( 'coupon' ) as $item ) {
					$code = strtolower( trim( (string) $item->get_code() ) );
					if ( '' === $code ) {
						continue;
					}
					if ( ! in_array( $code, $target_codes, true ) ) {
						$target->add_item( $item );
						$expected[]     = (int) $item->get_id();
						$target_codes[] = $code;
						continue;
					}
					foreach ( $target->get_items( 'coupon' ) as $existing ) {
						if ( strtolower( trim( (string) $existing->get_code() ) ) !== $code ) {
							continue;
						}
						$existing->set_discount( (float) $existing->get_discount() + (float) $item->get_discount() );
						$existing->set_discount_tax( (float) $existing->get_discount_tax() + (float) $item->get_discount_tax() );
						break;
					}
				}

				// Target's own shipping lines lose to a more expensive source one.
				if ( $plan['drop_target_items'] ) {
					foreach ( $plan['drop_target_items'] as $drop_id ) {
						$target->remove_item( $drop_id );
						$dropped_ship[] = (int) $drop_id;
					}
					$plan['drop_target_items'] = [];
				}

				$target->save();

				// --- Verify the move actually landed --------------------------
				// WC_Abstract_Order::save() catches and logs exceptions instead
				// of rethrowing, so a successful return proves nothing. Ask the
				// table directly whether anything is still on the source.
				if ( $expected ) {
					$placeholders = implode( ',', array_fill( 0, count( $expected ), '%d' ) );
					$params       = array_merge( [ $sid ], $expected );
					// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
					$stuck = $wpdb->get_col(
						$wpdb->prepare(
							// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
							"SELECT order_item_id FROM {$items_table} WHERE order_id = %d AND order_item_id IN ({$placeholders})",
							$params
						)
					);
					if ( ! empty( $stuck ) ) {
						$result['failed'][ $sid ] = __( 'Some items would not move, so this order was left untouched.', 'brikpanel' );
						continue;
					}
				}

				// --- Now, and only now, cancel ---------------------------------
				// Re-read the source first. The object above still holds the
				// items in memory and saving it would hand them straight back.
				$fresh = brikpanel_order_merge_flush_order( $sid );
				if ( ! $fresh ) {
					$result['failed'][ $sid ] = __( 'Order could not be re-read after moving its items.', 'brikpanel' );
					continue;
				}

				$moved_numbers[] = $fresh->get_order_number();

				$fresh->update_meta_data( BRIKPANEL_ORDER_MERGE_META_INTO, $target->get_id() );
				$fresh->add_order_note(
					sprintf(
						/* translators: %s: order number of the order this one was merged into. */
						__( 'Merged into order %s by BrikPanel. Its items and totals now live on that order.', 'brikpanel' ),
						brikpanel_order_merge_number( $target )
					)
				);
				$fresh->save();
				$fresh->update_status( 'cancelled' );

				$result['merged'][] = $sid;
			} catch ( \Throwable $e ) {
				$result['failed'][ $sid ] = $e->getMessage();
			}
		}

		// --- Close the target out -------------------------------------------
		// Its own try/catch: by this point the sources are already cancelled, so
		// a throw here would leave a white screen on top of a half-finished job.
		// Reported instead, with the `_brikpanel_merge_pending` breadcrumb left
		// in place so the order screen says so too.
		try {
		if ( $result['merged'] ) {
			// Rebuild the tax rows from the items' OWN stored tax data, then sum
			// without recalculating: calculate_totals( true ) would price the tax
			// off today's rates and quietly restate a historical order.
			$target->update_taxes();
			$target->calculate_totals( false );

			if ( $keep_paid && ! brikpanel_order_merge_is_paid( $target ) ) {
				$target->set_status( 'processing' );
			}

			// get_meta() hands back '' rather than [] for a key that is not there
			// yet, so cast, then drop anything that is not a real order id.
			$existing_from = array_filter( array_map( 'absint', (array) $target->get_meta( BRIKPANEL_ORDER_MERGE_META_FROM ) ) );
			$target->update_meta_data(
				BRIKPANEL_ORDER_MERGE_META_FROM,
				array_values( array_unique( array_merge( $existing_from, $result['merged'] ) ) )
			);
			$target->update_meta_data( BRIKPANEL_ORDER_MERGE_META_NUMBERS, implode( ', ', $moved_numbers ) );
			$target->delete_meta_data( BRIKPANEL_ORDER_MERGE_META_PENDING );

			$note = sprintf(
				/* translators: 1: comma-separated list of merged order numbers, 2: formatted order total. */
				__( 'Merged in order(s) %1$s. New order total: %2$s.', 'brikpanel' ),
				implode( ', ', $moved_numbers ),
				function_exists( 'brikpanel_money_text' )
					? brikpanel_money_text( $target->get_total(), [ 'currency' => $target->get_currency() ] )
					: wp_strip_all_tags( wc_price( $target->get_total(), [ 'currency' => $target->get_currency() ] ) )
			);
			if ( $dropped_ship ) {
				$note .= ' ' . __( 'Duplicate shipping charges were removed; one shipping line was kept.', 'brikpanel' );
			}
			$target->add_order_note( $note );
			$target->save();

			brikpanel_order_merge_sync_stock_flag( $target );
			brikpanel_order_merge_flush_order( $target->get_id() );
		} else {
			$target->delete_meta_data( BRIKPANEL_ORDER_MERGE_META_PENDING );
			$target->save();
		}
		} catch ( \Throwable $e ) {
			$result['error'] = sprintf(
				/* translators: %s: error message from the failure. */
				__( 'The orders were merged, but totalling up the merged order failed: %s. Open it and press Recalculate.', 'brikpanel' ),
				$e->getMessage()
			);
		}
	} finally {
		brikpanel_order_merge_mute_mail( false );
	}

	if ( function_exists( 'brikpanel_bust_data_caches' ) ) {
		// The source cancellations bust this by themselves, but the target's
		// totals changed without any status transition, so bust it explicitly.
		brikpanel_bust_data_caches();
	}

	/**
	 * Fires once a merge has finished.
	 *
	 * @param int    $target_id Order that survived.
	 * @param int[]  $merged    Order ids merged into it.
	 * @param array  $result    Full result payload.
	 */
	do_action( 'brikpanel_orders_merged', $target->get_id(), $result['merged'], $result );

	return $result;
}

// =============================================================================
// BULK ACTION
// =============================================================================

/**
 * Offer "Merge orders" in the orders-list bulk menu.
 *
 * BrikPanel's own orders screen rebuilds its action buttons straight from these
 * dropdown options, so adding the entry here is all that is needed for it to
 * appear in the Shopify-style bar that slides up when rows are ticked.
 *
 * @param array $actions Existing bulk actions.
 * @return array
 */
function brikpanel_order_merge_add_bulk_action( $actions ) {
	if ( ! is_array( $actions ) || ! brikpanel_order_merge_active() ) {
		return $actions;
	}

	// On the Trash view WooCommerce drops every mark_* action and leaves only
	// untrash/delete. Nothing there should be mergeable, so take the absence of
	// mark_* as the signal to stay out — the same test the custom-status module
	// uses.
	$has_mark = false;
	foreach ( $actions as $key => $unused ) {
		if ( 0 === strpos( (string) $key, 'mark_' ) ) {
			$has_mark = true;
			break;
		}
	}
	if ( ! $has_mark ) {
		return $actions;
	}

	$actions[ BRIKPANEL_ORDER_MERGE_ACTION ] = __( 'Merge orders', 'brikpanel' );
	return $actions;
}
add_filter( 'bulk_actions-edit-shop_order', 'brikpanel_order_merge_add_bulk_action', 25 );
add_filter( 'bulk_actions-woocommerce_page_wc-orders', 'brikpanel_order_merge_add_bulk_action', 25 );
add_filter( 'bulk_actions-admin_page_wc-orders', 'brikpanel_order_merge_add_bulk_action', 25 );

/**
 * Take the selection to the preview screen.
 *
 * Both storage back-ends hand the ids in as the third argument and redirect to
 * whatever non-empty URL comes back, so one callback serves both. The ids ride
 * in a short-lived transient rather than the URL: the classic screen strips
 * `action` and `post` off the redirect target, and a large selection would blow
 * the URL out anyway.
 *
 * @param string $redirect_to Default redirect.
 * @param string $action      Chosen bulk action.
 * @param array  $ids         Selected order ids.
 * @return string
 */
function brikpanel_order_merge_handle_bulk( $redirect_to, $action, $ids ) {
	if ( BRIKPANEL_ORDER_MERGE_ACTION !== $action ) {
		return $redirect_to;
	}
	if ( ! brikpanel_order_merge_active() ) {
		return add_query_arg( 'brikpanel_merge_error', 'denied', $redirect_to );
	}

	$ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $ids ) ) ) );

	if ( count( $ids ) < 2 ) {
		return add_query_arg( 'brikpanel_merge_error', 'too_few', $redirect_to );
	}
	if ( count( $ids ) > BRIKPANEL_ORDER_MERGE_MAX ) {
		return add_query_arg( 'brikpanel_merge_error', 'too_many', $redirect_to );
	}

	$token = wp_generate_password( 24, false );
	set_transient(
		BRIKPANEL_ORDER_MERGE_TRANSIENT . $token,
		[ 'ids' => $ids, 'user' => get_current_user_id() ],
		15 * MINUTE_IN_SECONDS
	);

	return admin_url( 'admin.php?page=' . BRIKPANEL_ORDER_MERGE_PAGE . '&merge=' . rawurlencode( $token ) );
}
add_filter( 'handle_bulk_actions-edit-shop_order', 'brikpanel_order_merge_handle_bulk', 20, 3 );
add_filter( 'handle_bulk_actions-woocommerce_page_wc-orders', 'brikpanel_order_merge_handle_bulk', 20, 3 );
add_filter( 'handle_bulk_actions-admin_page_wc-orders', 'brikpanel_order_merge_handle_bulk', 20, 3 );

/**
 * Read a merge token back, refusing one that belongs to somebody else.
 *
 * @param string $token Token from the URL.
 * @return int[] Order ids, empty when the token is unusable.
 */
function brikpanel_order_merge_read_token( $token ) {
	$token = sanitize_text_field( (string) $token );
	if ( '' === $token ) {
		return [];
	}

	$data = get_transient( BRIKPANEL_ORDER_MERGE_TRANSIENT . $token );
	if ( ! is_array( $data ) || empty( $data['ids'] ) ) {
		return [];
	}
	if ( (int) ( $data['user'] ?? 0 ) !== get_current_user_id() ) {
		return [];
	}

	return array_values( array_filter( array_map( 'absint', (array) $data['ids'] ) ) );
}

// =============================================================================
// PREVIEW SCREEN
// =============================================================================

/**
 * Register the preview screen as a hidden page: it is only ever reached from the
 * bulk action, never from the menu.
 *
 * @return void
 */
function brikpanel_order_merge_register_page() {
	$hook = add_submenu_page(
		'',
		__( 'Merge orders', 'brikpanel' ),
		'',
		'edit_shop_orders',
		BRIKPANEL_ORDER_MERGE_PAGE,
		'brikpanel_order_merge_render_page'
	);

	if ( ! $hook ) {
		return;
	}

	add_action(
		'load-' . $hook,
		static function () {
			// Set before admin-header.php runs, or WordPress strips a null title.
			$GLOBALS['title'] = __( 'Merge orders', 'brikpanel' );
			brikpanel_order_merge_maybe_run();
		}
	);
}
add_action( 'admin_menu', 'brikpanel_order_merge_register_page', 30 );

/**
 * Handle the confirm submit. Runs on `load-{hook}`, before a single byte of the
 * page is echoed, so the redirect afterwards still has headers to work with.
 *
 * Nothing the preview screen concluded is trusted here: minutes can pass between
 * rendering it and pressing the button, and an order can be refunded, trashed or
 * merged elsewhere in that window. The whole analysis runs again.
 *
 * @return void
 */
function brikpanel_order_merge_maybe_run() {
	if ( empty( $_POST['bpm_action'] ) || 'merge' !== sanitize_key( wp_unslash( $_POST['bpm_action'] ) ) ) {
		return;
	}

	$token = isset( $_POST['merge'] ) ? sanitize_text_field( wp_unslash( $_POST['merge'] ) ) : '';
	check_admin_referer( 'brikpanel_merge_' . $token, 'brikpanel_merge_nonce' );

	if ( ! brikpanel_order_merge_active() ) {
		wp_die( esc_html__( 'You do not have permission to merge orders.', 'brikpanel' ), '', [ 'response' => 403 ] );
	}

	$ids = brikpanel_order_merge_read_token( $token );
	if ( count( $ids ) < 2 ) {
		wp_die( esc_html__( 'This merge has expired. Select the orders again.', 'brikpanel' ), '', [ 'response' => 400 ] );
	}

	$target_id     = isset( $_POST['bpm_target'] ) ? absint( wp_unslash( $_POST['bpm_target'] ) ) : 0;
	$shipping_mode = isset( $_POST['bpm_shipping'] ) && 'sum' === sanitize_key( wp_unslash( $_POST['bpm_shipping'] ) ) ? 'sum' : 'single';
	$keep_paid     = ! empty( $_POST['bpm_keep_paid'] );

	$report = brikpanel_order_merge_analyse( $ids, $target_id, $shipping_mode );
	if ( $report['fatal'] ) {
		wp_die( esc_html( implode( ' ', $report['fatal'] ) ), '', [ 'response' => 400 ] );
	}

	// Every order has to still be individually editable by this user.
	foreach ( $report['orders'] as $id => $unused ) {
		if ( ! current_user_can( 'edit_shop_order', $id ) && ! current_user_can( 'edit_shop_orders' ) ) {
			wp_die( esc_html__( 'You do not have permission to edit one of these orders.', 'brikpanel' ), '', [ 'response' => 403 ] );
		}
	}

	// Everything checks out, so spend the token now: a double-click, an
	// impatient refresh or a browser retry then lands on the "expired" branch
	// above rather than merging the same orders twice. The short lock closes the
	// remaining window where two requests both read the token before either got
	// to delete it. Both come AFTER validation, so a merge that is refused
	// leaves the selection intact and the merchant can go back and fix it.
	$lock = BRIKPANEL_ORDER_MERGE_TRANSIENT . 'lock_' . $token;
	if ( get_transient( $lock ) ) {
		wp_die( esc_html__( 'This merge is already running. Give it a moment, then check the order.', 'brikpanel' ), '', [ 'response' => 409 ] );
	}
	set_transient( $lock, 1, 2 * MINUTE_IN_SECONDS );
	delete_transient( BRIKPANEL_ORDER_MERGE_TRANSIENT . $token );

	$result = brikpanel_order_merge_execute(
		$report['target_id'],
		$report['source_ids'],
		$report['shipping_mode'],
		$keep_paid
	);

	delete_transient( $lock );

	set_transient(
		'brikpanel_merge_result_' . get_current_user_id(),
		[
			'merged' => $result['merged'],
			'failed' => $result['failed'],
			'error'  => $result['error'],
		],
		60
	);

	$target = wc_get_order( $report['target_id'] );
	$back   = $target instanceof WC_Order
		? $target->get_edit_order_url()
		: admin_url( 'admin.php?page=wc-orders' );

	wp_safe_redirect( $back );
	exit;
}

/**
 * Render the preview.
 *
 * @return void
 */
function brikpanel_order_merge_render_page() {
	// Rendered as a card rather than wp_die(): the admin header has already been
	// printed by the time a page callback runs, so wp_die() drops a bare line of
	// text at the very bottom of the screen where nobody reads it.
	if ( ! brikpanel_order_merge_active() ) {
		if ( ! brikpanel_order_merge_user_can() ) {
			$why = __( 'You do not have permission to merge orders.', 'brikpanel' );
		} elseif ( ! brikpanel_order_merge_enabled() ) {
			$why = __( 'Order merging is switched off. Turn it back on under WooCommerce, Settings, BrikPanel, Orders List.', 'brikpanel' );
		} else {
			// Capable user, feature on: the master switch or a per-user rule has
			// handed this account the stock WooCommerce admin.
			$why = __( 'The BrikPanel interface is switched off for this account, so order merging is unavailable.', 'brikpanel' );
		}

		echo '<div class="wrap brikpanel-merge">';
		echo '<h1 class="bpm-title">' . esc_html__( 'Merge orders', 'brikpanel' ) . '</h1>';
		echo '<div class="bpm-card bpm-card--error"><p>' . esc_html( $why ) . '</p></div>';
		echo '<p><a class="bpm-btn bpm-btn--sec" href="' . esc_url( admin_url( 'admin.php?page=wc-orders' ) ) . '">'
			. esc_html__( 'Back to orders', 'brikpanel' ) . '</a></p>';
		echo '</div>';
		return;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only preview; the token is the capability check and the merge itself is nonced.
	$token = isset( $_REQUEST['merge'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['merge'] ) ) : '';
	$ids   = brikpanel_order_merge_read_token( $token );

	echo '<div class="wrap brikpanel-merge">';

	if ( count( $ids ) < 2 ) {
		echo '<h1 class="bpm-title">' . esc_html__( 'Merge orders', 'brikpanel' ) . '</h1>';
		echo '<div class="bpm-card bpm-card--error"><p>'
			. esc_html__( 'This merge has expired or was already used. Go back to the orders list and select the orders again.', 'brikpanel' )
			. '</p></div>';
		echo '<p><a class="bpm-btn bpm-btn--sec" href="' . esc_url( admin_url( 'admin.php?page=wc-orders' ) ) . '">'
			. esc_html__( 'Back to orders', 'brikpanel' ) . '</a></p>';
		echo '</div>';
		return;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- preview only; re-posting the form re-renders it and changes nothing.
	$target_id = isset( $_POST['bpm_target'] ) ? absint( wp_unslash( $_POST['bpm_target'] ) ) : 0;
	// phpcs:ignore WordPress.Security.NonceVerification.Missing
	$shipping_mode = isset( $_POST['bpm_shipping'] ) && 'sum' === sanitize_key( wp_unslash( $_POST['bpm_shipping'] ) ) ? 'sum' : 'single';
	// phpcs:ignore WordPress.Security.NonceVerification.Missing
	$keep_paid = ! empty( $_POST['bpm_keep_paid'] );

	$report   = brikpanel_order_merge_analyse( $ids, $target_id, $shipping_mode );
	$currency = $report['currency'];

	echo '<h1 class="bpm-title">' . esc_html__( 'Merge orders', 'brikpanel' ) . '</h1>';
	echo '<p class="bpm-lede">' . esc_html__( 'Products from the other orders move onto the main order. The others are cancelled with a note pointing here, and nothing is deleted.', 'brikpanel' ) . '</p>';

	if ( $report['fatal'] ) {
		echo '<div class="bpm-card bpm-card--error"><h2>' . esc_html__( 'These orders cannot be merged', 'brikpanel' ) . '</h2><ul>';
		foreach ( $report['fatal'] as $line ) {
			echo '<li>' . esc_html( $line ) . '</li>';
		}
		foreach ( $report['blocked'] as $id => $reason ) {
			$order = $report['orders'][ $id ] ?? null;
			echo '<li><strong>'
				. esc_html( $order ? '#' . $order->get_order_number() : '#' . $id )
				. '</strong>: ' . esc_html( $reason ) . '</li>';
		}
		echo '</ul></div>';
		echo '<p><a class="bpm-btn bpm-btn--sec" href="' . esc_url( admin_url( 'admin.php?page=wc-orders' ) ) . '">'
			. esc_html__( 'Back to orders', 'brikpanel' ) . '</a></p>';
		echo '</div>';
		return;
	}

	$self = admin_url( 'admin.php?page=' . BRIKPANEL_ORDER_MERGE_PAGE . '&merge=' . rawurlencode( $token ) );

	echo '<form method="post" action="' . esc_url( $self ) . '" class="bpm-form">';
	wp_nonce_field( 'brikpanel_merge_' . $token, 'brikpanel_merge_nonce' );
	echo '<input type="hidden" name="merge" value="' . esc_attr( $token ) . '" />';

	// --- Which order stays -------------------------------------------------
	echo '<div class="bpm-card">';
	echo '<h2>' . esc_html__( 'Which order stays?', 'brikpanel' ) . '</h2>';
	echo '<p class="bpm-hint">' . esc_html__( 'This order keeps its number, its customer details and its payment. Everything else moves into it.', 'brikpanel' ) . '</p>';
	echo '<ul class="bpm-orders">';
	foreach ( $report['orders'] as $id => $order ) {
		$checked = ( $id === $report['target_id'] );
		$date    = $order->get_date_created();
		$items   = 0;
		foreach ( $order->get_items() as $item ) {
			$items += (int) $item->get_quantity();
		}

		echo '<li class="bpm-order' . ( $checked ? ' is-main' : '' ) . '">';
		echo '<label>';
		echo '<input type="radio" name="bpm_target" value="' . esc_attr( (string) $id ) . '" ' . checked( $checked, true, false ) . ' />';
		echo '<span class="bpm-order-main">';
		echo '<span class="bpm-order-num">#' . esc_html( $order->get_order_number() ) . '</span>';
		echo '<span class="bpm-order-meta">'
			. esc_html( $date ? wc_format_datetime( $date ) : '' )
			. ' · ' . esc_html( wc_get_order_status_name( $order->get_status() ) )
			/* translators: %d: number of items in an order. */
			. ' · ' . esc_html( sprintf( _n( '%d item', '%d items', $items, 'brikpanel' ), $items ) )
			. '</span>';
		echo '</span>';
		echo '<span class="bpm-order-total">' . wp_kses_post( wc_price( $order->get_total(), [ 'currency' => $currency ] ) ) . '</span>';
		if ( brikpanel_order_merge_is_paid( $order ) ) {
			echo '<span class="bpm-badge">' . esc_html__( 'Paid', 'brikpanel' ) . '</span>';
		}
		echo '</label>';
		echo '</li>';
	}
	echo '</ul>';
	echo '</div>';

	// --- Shipping ----------------------------------------------------------
	echo '<div class="bpm-card">';
	echo '<h2>' . esc_html__( 'Shipping charge', 'brikpanel' ) . '</h2>';
	echo '<p class="bpm-hint">' . esc_html__( 'You are sending one parcel, but the customer may have paid shipping more than once.', 'brikpanel' ) . '</p>';
	echo '<ul class="bpm-choices">';
	echo '<li><label><input type="radio" name="bpm_shipping" value="single" ' . checked( 'single', $report['shipping_mode'], false ) . ' /> '
		. '<span><strong>' . esc_html__( 'Charge shipping once', 'brikpanel' ) . '</strong>'
		. '<em>' . esc_html__( 'Keeps the most expensive shipping line and drops the rest. Refund the difference to the customer yourself if you owe it.', 'brikpanel' ) . '</em></span></label></li>';
	echo '<li><label><input type="radio" name="bpm_shipping" value="sum" ' . checked( 'sum', $report['shipping_mode'], false ) . ' /> '
		. '<span><strong>' . esc_html__( 'Add every shipping charge up', 'brikpanel' ) . '</strong>'
		. '<em>' . esc_html__( 'Matches what the customer actually paid, so your books stay exact.', 'brikpanel' ) . '</em></span></label></li>';
	echo '</ul>';
	echo '<p><button type="submit" name="bpm_action" value="preview" class="bpm-btn bpm-btn--sec bpm-refresh">'
		. esc_html__( 'Update preview', 'brikpanel' ) . '</button></p>';
	echo '</div>';

	// --- Warnings ----------------------------------------------------------
	if ( $report['warnings'] ) {
		echo '<div class="bpm-card bpm-card--warn">';
		echo '<h2>' . esc_html__( 'Before you continue', 'brikpanel' ) . '</h2>';
		echo '<ul>';
		foreach ( $report['warnings'] as $line ) {
			echo '<li>' . esc_html( $line ) . '</li>';
		}
		echo '</ul>';

		if ( ! empty( $report['suggest_paid_status'] ) ) {
			echo '<label class="bpm-check"><input type="checkbox" name="bpm_keep_paid" value="1" ' . checked( $keep_paid, true, false ) . ' /> '
				. esc_html__( 'Mark the merged order as Processing so the sale still counts as paid.', 'brikpanel' )
				. '</label>';
		}
		echo '</div>';
	}

	// --- Result ------------------------------------------------------------
	$target = $report['orders'][ $report['target_id'] ];
	echo '<div class="bpm-card bpm-card--result">';
	echo '<h2>' . esc_html__( 'After merging', 'brikpanel' ) . '</h2>';
	echo '<dl class="bpm-summary">';
	echo '<div><dt>' . esc_html__( 'Order kept', 'brikpanel' ) . '</dt><dd>#' . esc_html( $target->get_order_number() ) . '</dd></div>';
	echo '<div><dt>' . esc_html__( 'Orders cancelled', 'brikpanel' ) . '</dt><dd>' . esc_html( (string) count( $report['source_ids'] ) ) . '</dd></div>';
	echo '<div><dt>' . esc_html__( 'Items in total', 'brikpanel' ) . '</dt><dd>' . esc_html( (string) $report['item_count'] ) . '</dd></div>';
	echo '<div><dt>' . esc_html__( 'New order total', 'brikpanel' ) . '</dt><dd class="bpm-big">'
		. wp_kses_post( wc_price( $report['new_total'], [ 'currency' => $currency ] ) ) . '</dd></div>';
	echo '</dl>';
	echo '<p class="bpm-hint">' . esc_html__( 'This total is a projection. Tax is carried over exactly as each order recorded it, never recalculated at today\'s rates.', 'brikpanel' ) . '</p>';
	echo '</div>';

	// --- Actions -----------------------------------------------------------
	echo '<div class="bpm-actions">';
	echo '<a class="bpm-btn bpm-btn--sec" href="' . esc_url( admin_url( 'admin.php?page=wc-orders' ) ) . '">'
		. esc_html__( 'Cancel', 'brikpanel' ) . '</a>';
	echo '<button type="submit" name="bpm_action" value="merge" class="bpm-btn bpm-btn--pri">'
		. esc_html(
			sprintf(
				/* translators: %d: number of orders that will be merged into the main order. */
				_n( 'Merge %d order', 'Merge %d orders', count( $report['source_ids'] ), 'brikpanel' ),
				count( $report['source_ids'] )
			)
		)
		. '</button>';
	echo '</div>';

	echo '</form>';
	echo '</div>';

	// Auto-refresh the projection when a choice changes. No user-facing text
	// lives here, so nothing to translate.
	?>
	<script>
	(function () {
		var form = document.querySelector('.bpm-form');
		if (!form) { return; }
		form.addEventListener('change', function (event) {
			var el = event.target;
			if (!el || el.name !== 'bpm_target' && el.name !== 'bpm_shipping') { return; }
			form.requestSubmit ? form.requestSubmit() : form.submit();
		});
		/* The button only exists so the choices still work without scripting. */
		var refresh = form.querySelector('.bpm-refresh');
		if (refresh) { refresh.hidden = true; }
	})();
	</script>
	<?php
}

// =============================================================================
// FEEDBACK — notices and the trail a merge leaves on both orders
// =============================================================================

/**
 * Whether the current screen is a WooCommerce orders list or order edit screen,
 * under either storage back-end.
 *
 * Guards the notice below. Without it every admin page in the site would pay for
 * a transient lookup (and a `notoptions` miss costs the same as a hit) just in
 * case a merge had happened.
 *
 * @return bool
 */
function brikpanel_order_merge_is_order_screen() {
	if ( ! function_exists( 'get_current_screen' ) ) {
		return false;
	}
	$screen = get_current_screen();
	if ( ! $screen ) {
		return false;
	}
	$hpos = function_exists( 'wc_get_page_screen_id' ) ? wc_get_page_screen_id( 'shop-order' ) : 'woocommerce_page_wc-orders';

	return in_array( $screen->id, [ $hpos, 'admin_page_wc-orders', 'shop_order', 'edit-shop_order' ], true );
}

/**
 * Result and error notices.
 *
 * Every notice carries the `brikpanel-notice` class: BrikPanel's foreign-notice
 * suppressor whitelists that class, and without it the notice would be swallowed
 * before the merchant ever saw it.
 *
 * @return void
 */
function brikpanel_order_merge_notices() {
	if ( ! brikpanel_order_merge_user_can() || ! brikpanel_order_merge_is_order_screen() ) {
		return;
	}

	// --- Bulk-action refusals ---------------------------------------------
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flag on a redirect we issued.
	$error = isset( $_GET['brikpanel_merge_error'] ) ? sanitize_key( wp_unslash( $_GET['brikpanel_merge_error'] ) ) : '';
	if ( $error ) {
		$messages = [
			'too_few'  => __( 'Select at least two orders to merge.', 'brikpanel' ),
			'too_many' => sprintf(
				/* translators: %d: maximum number of orders that can be merged at once. */
				__( 'You can merge up to %d orders at once.', 'brikpanel' ),
				BRIKPANEL_ORDER_MERGE_MAX
			),
			'denied'   => __( 'You do not have permission to merge orders.', 'brikpanel' ),
		];
		if ( isset( $messages[ $error ] ) ) {
			echo '<div class="notice notice-error brikpanel-notice is-dismissible"><p>'
				. esc_html( $messages[ $error ] ) . '</p></div>';
		}
	}

	// --- Outcome of a merge -------------------------------------------------
	$key    = 'brikpanel_merge_result_' . get_current_user_id();
	$result = get_transient( $key );
	if ( is_array( $result ) ) {
		delete_transient( $key );

		if ( ! empty( $result['merged'] ) ) {
			$count = count( $result['merged'] );
			echo '<div class="notice notice-success brikpanel-notice is-dismissible"><p>'
				. esc_html(
					sprintf(
						/* translators: %d: number of orders merged into this one. */
						_n( '%d order was merged into this one.', '%d orders were merged into this one.', $count, 'brikpanel' ),
						$count
					)
				)
				. '</p></div>';
		}

		if ( ! empty( $result['failed'] ) ) {
			echo '<div class="notice notice-error brikpanel-notice is-dismissible"><p>'
				. esc_html__( 'Some orders were left alone:', 'brikpanel' ) . '</p><ul>';
			foreach ( $result['failed'] as $id => $reason ) {
				$order = wc_get_order( $id );
				echo '<li><strong>' . esc_html( $order ? '#' . $order->get_order_number() : '#' . $id ) . '</strong>: '
					. esc_html( $reason ) . '</li>';
			}
			echo '</ul></div>';
		}

		if ( ! empty( $result['error'] ) ) {
			echo '<div class="notice notice-error brikpanel-notice is-dismissible"><p>'
				. esc_html( $result['error'] ) . '</p></div>';
		}
	}
}
add_action( 'admin_notices', 'brikpanel_order_merge_notices' );

/**
 * Show where an order went, or what came into it, right in the order details
 * panel. A cancelled order that was merged away looks alarming otherwise, and
 * the customer's old order number has to keep resolving to something useful.
 *
 * @param WC_Order $order Order being edited.
 * @return void
 */
function brikpanel_order_merge_order_banner( $order ) {
	if ( ! $order instanceof WC_Abstract_Order ) {
		return;
	}

	// Printed inline rather than in the merge screen's stylesheet: this banner
	// lives on the order edit screen, which never loads that file. Same approach
	// the shipping-cost box takes for its own small block of styles.
	static $styled = false;
	if ( ! $styled ) {
		$styled = true;
		// display:block, not flex: the sentence wraps around an inline link, and a
		// flex container would make each text node its own column.
		echo '<style>'
			. '.brikpanel-merge-banner{display:block;margin:.75rem 0 0;padding:.5rem .625rem;'
			. 'border:1px solid #e3e3e3;border-radius:.5rem;background:#f7f7f7;font-size:.8125rem;color:#616161;line-height:1.5}'
			. '.brikpanel-merge-banner a{color:#303030;font-weight:600}'
			. '.brikpanel-merge-banner .dashicons{font-size:16px;width:16px;height:16px;'
			. 'vertical-align:-3px;margin-inline-end:.25rem;color:#8a8a8a}'
			. '.brikpanel-merge-banner--warn{border-color:#e8d48a;background:#fdf7e3;color:#6b5a17}'
			. '</style>';
	}

	$into = absint( $order->get_meta( BRIKPANEL_ORDER_MERGE_META_INTO ) );
	if ( $into ) {
		$parent = wc_get_order( $into );
		if ( $parent instanceof WC_Order ) {
			echo '<p class="brikpanel-merge-banner"><span class="dashicons dashicons-migrate"></span> ';
			printf(
				/* translators: %s: link to the order this one was merged into. */
				esc_html__( 'This order was merged into %s. Its items and money live there now.', 'brikpanel' ),
				'<a href="' . esc_url( $parent->get_edit_order_url() ) . '">#'
					. esc_html( $parent->get_order_number() ) . '</a>'
			);
			echo '</p>';
		}
	}

	$from = array_filter( array_map( 'absint', (array) $order->get_meta( BRIKPANEL_ORDER_MERGE_META_FROM ) ) );
	if ( $from ) {
		$links = [];
		foreach ( $from as $id ) {
			$src = wc_get_order( absint( $id ) );
			if ( $src instanceof WC_Order ) {
				$links[] = '<a href="' . esc_url( $src->get_edit_order_url() ) . '">#'
					. esc_html( $src->get_order_number() ) . '</a>';
			}
		}
		if ( $links ) {
			echo '<p class="brikpanel-merge-banner"><span class="dashicons dashicons-migrate"></span> ';
			printf(
				/* translators: %s: comma-separated links to the orders merged into this one. */
				esc_html__( 'Merged in: %s', 'brikpanel' ),
				implode( ', ', $links ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- links escaped above.
			);
			echo '</p>';
		}
	}

	// A merge that died mid-request leaves this behind. Say so rather than let
	// the merchant puzzle over an order whose totals moved for no visible reason.
	if ( $order->get_meta( BRIKPANEL_ORDER_MERGE_META_PENDING ) ) {
		echo '<p class="brikpanel-merge-banner brikpanel-merge-banner--warn">'
			. esc_html__( 'A merge into this order did not finish. Check its items and the orders you were merging before trying again.', 'brikpanel' )
			. '</p>';
	}
}
add_action( 'woocommerce_admin_order_data_after_order_details', 'brikpanel_order_merge_order_banner', 20 );

// =============================================================================
// ASSETS
// =============================================================================

add_action(
	'admin_enqueue_scripts',
	static function ( $hook ) {
		// Matches the hook for our hidden page, whatever prefix WordPress gives it.
		if ( false === strpos( (string) $hook, BRIKPANEL_ORDER_MERGE_PAGE ) ) {
			return;
		}
		$file = BRIKPANEL_PATH . 'front-end/orders/brikpanel-order-merge.css';
		wp_enqueue_style(
			'brikpanel-order-merge',
			BRIKPANEL_URL . 'front-end/orders/brikpanel-order-merge.css',
			[],
			@filemtime( $file ) ?: BRIKPANEL_VERSION // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		);
	}
);
