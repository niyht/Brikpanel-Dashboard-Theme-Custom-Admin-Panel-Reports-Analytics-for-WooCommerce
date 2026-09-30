<?php
/**
 * Personal discount codes and WooCommerce's reservation of used codes.
 *
 * A single-use code that belongs to one email address (the signup popup's
 * BRIK-… code, a BrikMentor flow code) can be blocked by the very shopper it
 * belongs to. The moment checkout creates the order, before any money moves:
 *
 * - classic checkout "holds" the code for that order for
 *   woocommerce_hold_stock_minutes (at least one minute), and
 *   WC_Discounts::validate_coupon_usage_limit() counts the hold even against
 *   the shopper's own unpaid order;
 * - block checkout (Store API) moves the draft to "pending", which records the
 *   use outright, so an abandoned payment spends the code until the order is
 *   cancelled.
 *
 * A shopper who leaves the bank page and comes back then reads "Usage limit
 * for coupon … has been reached"; the classic checkout page refuses to open
 * ("There are some issues with the items in your cart") and the block checkout
 * silently drops the discount (lovrabyzie.com support report, 2026-09-27).
 *
 * The reservation exists so that two orders cannot race for one limited code.
 * A personal code can only be used by one address, so the only order it can
 * race with is another order of the same shopper. When that shopper's own
 * session loads its cart again, their personal codes are freed from their own
 * unpaid order. WooCommerce then resumes the same order (same cart) and counts
 * the code once, when it is paid. Store coupons are never touched.
 *
 * Why woocommerce_cart_loaded_from_session: every calculate_totals() re-checks
 * coupons silently (a held code simply gets no discount), and
 * WC_Checkout::process_checkout() computes the totals before it fires
 * woocommerce_check_cart_items; the Store API also validates coupons before
 * that hook. Loading the cart precedes all of them on front-end, AJAX and
 * Store API requests.
 *
 * Known limits: a shopper who comes back in another browser has no session
 * link and waits for WooCommerce's own timeout as before; a block checkout
 * order that also carries a store coupon is left to WooCommerce; if the cart
 * changes, checkout opens a new order and the old unpaid one keeps the code
 * line, so paying both would use the code twice (same person, two sales).
 *
 * @package BrikPanel
 * @since   3.3.25
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Brikpanel_Coupon_Hold {

	/** WC session key: unpaid orders of this session already checked. */
	const SEEN = 'brikpanel_code_hold_seen';

	/** Order meta: which personal codes were freed, and when (support trail). */
	const TRAIL = '_brikpanel_personal_code_freed';

	public static function register() {
		add_action( 'woocommerce_cart_loaded_from_session', [ __CLASS__, 'on_cart_loaded' ], 0 );

		// A new reservation or a newly recorded use: look again on the next
		// request. Early priority, so a failing callback after us cannot skip it.
		add_action( 'woocommerce_checkout_order_created', [ __CLASS__, 'forget_seen' ], -9999 );
		add_action( 'woocommerce_store_api_checkout_order_processed', [ __CLASS__, 'forget_seen' ], -9999 );
		add_action( 'woocommerce_order_status_pending', [ __CLASS__, 'forget_seen' ], -9999 );
	}

	public static function on_cart_loaded() {
		try {
			self::check_session();
		} catch ( \Throwable $e ) {
			// A discount code is a courtesy; it never gets to break loading the cart.
			unset( $e );
		}
	}

	public static function forget_seen() {
		try {
			if ( function_exists( 'WC' ) && WC()->session && null !== WC()->session->get( self::SEEN, null ) ) {
				WC()->session->set( self::SEEN, null );
			}
		} catch ( \Throwable $e ) {
			unset( $e );
		}
	}

	private static function check_session() {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return;
		}
		$session = WC()->session;

		// Only the orders WooCommerce itself keeps for this session, the same two
		// keys WC_Cart uses to find "the order being paid". Nothing is read from
		// the request, so no one can point this at another shopper's order.
		$candidates = array_values( array_unique( array_filter( [
			absint( $session->get( 'order_awaiting_payment' ) ),
			absint( $session->get( 'store_api_draft_order' ) ),
		] ) ) );
		$seen = array_map( 'absint', (array) $session->get( self::SEEN, [] ) );

		if ( ! $candidates ) {
			if ( $seen ) {
				$session->set( self::SEEN, null );
			}
			return;
		}

		$todo = array_diff( $candidates, $seen );
		if ( ! $todo ) {
			return;
		}

		$done = [];
		foreach ( $todo as $order_id ) {
			// The gateway's return, the thank-you page and the order-pay page all
			// carry the order key: the payment may be landing right now. Not
			// marked as seen, so the next request looks again.
			if ( self::is_payment_request_for( $order_id ) ) {
				continue;
			}
			self::free_own_codes( $order_id );
			$done[] = $order_id;
		}

		if ( $done ) {
			$session->set( self::SEEN, array_values( array_intersect( array_merge( $seen, $done ), $candidates ) ) );
		}
	}

	private static function is_payment_request_for( $order_id ) {
		if ( empty( $_GET['key'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return false;
		}
		$key = wc_clean( wp_unslash( $_GET['key'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! is_string( $key ) || '' === $key ) {
			return false;
		}
		$order = wc_get_order( $order_id );

		return $order instanceof WC_Order && hash_equals( (string) $order->get_order_key(), $key );
	}

	/**
	 * Free the personal codes an unpaid order is holding or has spent.
	 *
	 * Public for tools/test-coupon-hold.php; the runtime only ever passes an
	 * order id taken from the shopper's own WooCommerce session.
	 *
	 * @param int $order_id Order id.
	 * @return array{held:int[],usage:int[]} Coupon ids freed from a hold and from a recorded use.
	 */
	public static function free_own_codes( $order_id ) {
		global $wpdb;

		$result   = [ 'held' => [], 'usage' => [] ];
		$order_id = absint( $order_id );
		if ( ! $order_id ) {
			return $result;
		}

		// One writer per order. Two tabs, a link prefetch or parallel Store API
		// calls must not both give the same use back: the usage count has no
		// floor in SQL and would go negative.
		$lock = 'bp_cfree_' . substr( md5( $wpdb->prefix ), 0, 8 ) . '_' . $order_id;
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK( %s, 0 )', $lock ) ) ) {
			return $result;
		}

		try {
			// Read only after the lock, so a parallel pass's changes are seen.
			$order = wc_get_order( $order_id );
			if ( ! $order instanceof WC_Order || ! $order->has_status( [ 'pending', 'failed' ] ) ) {
				return $result;
			}

			$result['held'] = self::free_holds( $order );

			// WooCommerce gives a failed order's use back by itself.
			if ( $order->has_status( 'pending' ) ) {
				$result['usage'] = self::give_back_recorded_use( $order );
			}

			$freed = array_unique( array_merge( $result['held'], $result['usage'] ) );
			if ( $freed ) {
				$trail = $order->get_meta( self::TRAIL );
				$trail = is_array( $trail ) ? $trail : [];
				foreach ( $freed as $coupon_id ) {
					$trail[ $coupon_id ] = time();
				}
				$order->update_meta_data( self::TRAIL, $trail );
				// Meta only, the way WooCommerce's own release saves.
				$order->save_meta_data();
			}

			if ( $result['usage'] ) {
				self::recount_if_paid_meanwhile( $order_id );
			}
		} finally {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK( %s )', $lock ) );
		}

		return $result;
	}

	/**
	 * Classic checkout: delete the reservation rows of the order's personal
	 * codes. Other coupons keep theirs, which is why WooCommerce's
	 * release_held_coupons() (it releases every coupon) is not used.
	 *
	 * @param WC_Order $order Unpaid order.
	 * @return int[] Coupon ids freed.
	 */
	private static function free_holds( WC_Order $order ) {
		$freed = [];

		foreach ( [ '_coupon_held_keys', '_coupon_held_keys_for_users' ] as $order_key ) {
			$held = $order->get_meta( $order_key );
			if ( ! is_array( $held ) || ! $held ) {
				continue;
			}

			$changed = false;
			foreach ( $held as $coupon_id => $row_key ) {
				$coupon = self::personal_coupon( (int) $coupon_id, $order );
				if ( ! $coupon ) {
					continue;
				}
				// The row lives on the coupon (a post in every storage mode).
				if ( is_string( $row_key ) && preg_match( '/^_(?:coupon_held|maybe_used_by)_\d+_[A-Za-z0-9]+$/', $row_key ) ) {
					delete_post_meta( $coupon->get_id(), $row_key );
				}
				unset( $held[ $coupon_id ] );
				$changed = true;
				$freed[] = (int) $coupon_id;
			}

			if ( $changed ) {
				// Written back even when empty: deleting the row would leave a
				// parallel checkout request updating a meta id that is gone,
				// and its new reservation keys would be lost.
				$order->update_meta_data( $order_key, $held );
			}
		}

		return array_values( array_unique( $freed ) );
	}

	/**
	 * Block checkout: the pending order has already recorded the use. Replay
	 * WooCommerce's own "reduce" from wc_update_coupon_usage_counts(), in its
	 * order (flag first, then the counts), so the rest of its state machine
	 * stays right: the use is counted again when the order is paid, and a
	 * cancellation has nothing left to give back.
	 *
	 * @param WC_Order $order Pending order.
	 * @return int[] Coupon ids given back.
	 */
	private static function give_back_recorded_use( WC_Order $order ) {
		global $wpdb;

		$data_store = $order->get_data_store();
		if ( ! $data_store || ! is_callable( [ $data_store, 'get_recorded_coupon_usage_counts' ] )
			|| ! $data_store->get_recorded_coupon_usage_counts( $order ) ) {
			return [];
		}

		$codes = array_filter( array_map( 'trim', (array) $order->get_coupon_codes() ), 'strlen' );
		if ( ! $codes ) {
			return [];
		}

		// The value WooCommerce records the use under, exactly as it computes it.
		$used_by = $order->get_user_id();
		if ( ! $used_by ) {
			$used_by = $order->get_billing_email();
		}

		$coupons = [];
		foreach ( $codes as $code ) {
			try {
				$coupon = new WC_Coupon( $code );
			} catch ( \Exception $e ) {
				return [];
			}
			// The recorded flag belongs to the whole order: a store coupon on the
			// same order leaves the order entirely to WooCommerce.
			if ( ! $coupon->get_id() || ! self::is_personal( $coupon, $order ) ) {
				return [];
			}
			// If the recorded use cannot be found under that value (the customer
			// id changed since), do not guess: take nothing back.
			$row = $wpdb->get_var( $wpdb->prepare(
				"SELECT meta_id FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_used_by' AND meta_value = %s LIMIT 1",
				$coupon->get_id(),
				(string) $used_by
			) );
			if ( ! $row ) {
				return [];
			}
			$coupons[] = $coupon;
		}

		$data_store->set_recorded_coupon_usage_counts( $order, false );

		$given_back = [];
		foreach ( $coupons as $coupon ) {
			$coupon->decrease_usage_count( $used_by );
			$given_back[] = (int) $coupon->get_id();
		}

		return $given_back;
	}

	/**
	 * A payment notice can mark the order paid between our read and our write.
	 * WooCommerce then saw "already counted" and did nothing, and we took the
	 * use back: count it again, the way WooCommerce would have.
	 *
	 * @param int $order_id Order id.
	 */
	private static function recount_if_paid_meanwhile( $order_id ) {
		global $wpdb;

		// Straight from the table: the order objects in memory may be stale.
		$status = brikpanel_wc_hpos_enabled()
			? $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$wpdb->prefix}wc_orders WHERE id = %d", $order_id ) )
			: $wpdb->get_var( $wpdb->prepare( "SELECT post_status FROM {$wpdb->posts} WHERE ID = %d", $order_id ) );
		$status = preg_replace( '/^wc-/', '', (string) $status );

		if ( '' === $status || in_array( $status, [ 'pending', 'failed', 'cancelled', 'trash', 'checkout-draft' ], true ) ) {
			return;
		}
		wc_update_coupon_usage_counts( $order_id );
	}

	/**
	 * @param int      $coupon_id Coupon id from the order's reservation list.
	 * @param WC_Order $order     The unpaid order.
	 * @return WC_Coupon|null The coupon when it is a personal code.
	 */
	private static function personal_coupon( $coupon_id, WC_Order $order ) {
		if ( $coupon_id <= 0 ) {
			return null;
		}
		try {
			$coupon = new WC_Coupon( $coupon_id );
		} catch ( \Exception $e ) {
			return null; // Deleted since the order reserved it.
		}

		return ( $coupon->get_id() && self::is_personal( $coupon, $order ) ) ? $coupon : null;
	}

	/**
	 * A code BrikPanel's popup or BrikMentor issued to one address.
	 *
	 * @param WC_Coupon $coupon Coupon.
	 * @param WC_Order  $order  The unpaid order it sits on.
	 * @return bool
	 */
	private static function is_personal( WC_Coupon $coupon, WC_Order $order ) {
		$marked = '' !== (string) $coupon->get_meta( '_brikpanel_cartab_email' )
			|| '' !== (string) $coupon->get_meta( '_brikmentor_email' );
		$personal = $marked && [] !== array_filter( (array) $coupon->get_email_restrictions() );

		/**
		 * Whether BrikPanel may free this code from the shopper's own unpaid order.
		 *
		 * @since 3.3.25
		 * @param bool      $personal Default: popup and BrikMentor codes restricted to an email.
		 * @param WC_Coupon $coupon   The coupon.
		 * @param WC_Order  $order    The shopper's own unpaid order.
		 */
		return (bool) apply_filters( 'brikpanel_release_own_coupon_hold', $personal, $coupon, $order );
	}
}

Brikpanel_Coupon_Hold::register();
