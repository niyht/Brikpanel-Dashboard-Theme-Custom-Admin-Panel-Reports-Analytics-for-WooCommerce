<?php
/**
 * BrikPanel: automatic coupons, item count rules and coupon links.
 *
 * Three additions to WooCommerce coupons, set in BrikPanel's coupon window
 * (front-end/coupons/brikpanel-coupons.php):
 *
 * - "Apply automatically": the coupon is added to a cart that meets its
 *   rules, with no code typed, and taken off again when the cart stops
 *   meeting them. When several automatic coupons fit, only the one with the
 *   biggest discount stays (a coupon that only gives free shipping is
 *   applied next to it). Tiers like 1-5 items 10%, 6-10 items 15% are one
 *   coupon per tier, each with an item range.
 * - "Minimum items" / "Maximum items": the coupon is valid only while the
 *   cart holds that many items it can discount. Checked inside WooCommerce's
 *   own coupon validation, so a typed code and a coupon added to an order in
 *   wp-admin follow it too.
 * - Coupon links: `?bp-coupon=CODE` on any store page adds the code (the
 *   cart share link uses the same parameter, with products). A cart that
 *   does not qualify yet keeps the code for the visit and gets it as soon as
 *   it does.
 *
 * WooCommerce does the validation and the discount math; this class only
 * decides which automatic coupons belong on the cart. It never silences
 * WooCommerce's notices (that swallowed other plugins' messages once): every
 * coupon is checked first, so WooCommerce has nothing to complain about,
 * and only the "applied successfully" line of a coupon it adds by itself is
 * left out. Personal codes (the popup's, BrikMentor's) are never pushed
 * off: an automatic coupon that may not be combined is not added next to
 * other codes, and none is added next to a code that may not be combined.
 *
 * When to sync: after every totals pass (woocommerce_after_calculate_totals;
 * the block cart's Store API response validates the coupons after its totals
 * pass, the classic cart and checkout pages do it before theirs, so a normal
 * page request whose cart changed also gets a totals pass when the cart
 * loads). A fingerprint of the cart in the session skips both when nothing
 * changed. A store with no automatic coupon and no saved link code pays one
 * autoloaded option read.
 *
 * @package BrikPanel
 * @since   3.3.43
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Brikpanel_Auto_Coupons {

	/** Coupon meta: 'yes' when the coupon applies itself. */
	const META_AUTO = '_brikpanel_auto_apply';

	/** Coupon meta: the fewest items the coupon needs in the cart. */
	const META_MIN = '_brikpanel_min_items';

	/** Coupon meta: the most items the coupon allows in the cart. */
	const META_MAX = '_brikpanel_max_items';

	/** Option: [coupon id => code] of the published automatic coupons. */
	const INDEX_OPTION = 'brikpanel_auto_coupons';

	/** Session: automatic codes the shopper took off the cart. */
	const SESSION_DECLINED = 'brikpanel_auto_declined';

	/** Session: a code from a coupon link, waiting for the cart to qualify. */
	const SESSION_LINK = 'brikpanel_link_coupon';

	/** Session: the cart as it was after the last sync. */
	const SESSION_FP = 'brikpanel_auto_fp';

	/** Query parameter of coupon links (the cart share link's coupon too). */
	const LINK_VAR = 'bp-coupon';

	/** True while this class adds or removes a coupon. */
	private static $engine = false;

	/** True while a sync runs (applying a coupon recalculates the totals). */
	private static $busy = false;

	/**
	 * How many totals passes had run when this class last took a coupon off
	 * (-1: none in this sync). Applying a coupon runs a pass, removing one
	 * does not.
	 */
	private static $removed_at = -1;

	/**
	 * The code the block cart was asked to remove ('*' for all of them), ''
	 * outside such a request.
	 */
	private static $store_api_removal = '';

	/** True while the block checkout places an order. */
	private static $store_api_checkout = false;

	public static function register() {
		add_filter( 'woocommerce_coupon_is_valid', array( __CLASS__, 'validate_items' ), 10, 3 );

		add_action( 'woocommerce_cart_loaded_from_session', array( __CLASS__, 'on_cart_loaded' ), 30 );
		add_action( 'woocommerce_after_calculate_totals', array( __CLASS__, 'sync' ), 20 );
		add_action( 'woocommerce_removed_coupon', array( __CLASS__, 'on_removed' ) );
		add_action( 'woocommerce_applied_coupon', array( __CLASS__, 'on_applied' ) );
		add_filter( 'rest_request_before_callbacks', array( __CLASS__, 'mark_store_api_removal' ), 10, 3 );
		add_filter( 'rest_request_after_callbacks', array( __CLASS__, 'unmark_store_api_removal' ), 10, 3 );
		add_action( 'wp_loaded', array( __CLASS__, 'handle_link' ), 25 );

		// The index follows every way a coupon changes: WooCommerce's own
		// saves (after the meta is written), status changes made with
		// wp_update_post (BrikPanel's coupon list), trash and delete, and
		// direct meta writes.
		add_action( 'woocommerce_new_coupon', array( __CLASS__, 'on_coupon_saved' ), 10, 2 );
		add_action( 'woocommerce_update_coupon', array( __CLASS__, 'on_coupon_saved' ), 10, 2 );
		add_action( 'transition_post_status', array( __CLASS__, 'on_status' ), 10, 3 );
		add_action( 'trashed_post', array( __CLASS__, 'on_post_change' ) );
		add_action( 'untrashed_post', array( __CLASS__, 'on_post_change' ) );
		add_action( 'deleted_post', array( __CLASS__, 'on_post_change' ) );
		add_action( 'added_post_meta', array( __CLASS__, 'on_meta_change' ), 10, 3 );
		add_action( 'updated_post_meta', array( __CLASS__, 'on_meta_change' ), 10, 3 );
		add_action( 'deleted_post_meta', array( __CLASS__, 'on_meta_change' ), 10, 3 );
	}

	/* ── Coupon settings ──────────────────────────────────────────────────── */

	/**
	 * @param WC_Coupon $coupon Coupon.
	 * @return bool Whether the coupon applies itself.
	 */
	public static function is_auto( $coupon ) {
		return $coupon instanceof WC_Coupon && 'yes' === $coupon->get_meta( self::META_AUTO, true, 'edit' );
	}

	/**
	 * @param WC_Coupon $coupon Coupon.
	 * @param string    $which  'min' or 'max'.
	 * @return int|string The limit, '' when there is none.
	 */
	public static function item_limit( $coupon, $which ) {
		if ( ! $coupon instanceof WC_Coupon ) {
			return '';
		}
		return self::sanitize_item_limit( $coupon->get_meta( 'max' === $which ? self::META_MAX : self::META_MIN, true, 'edit' ) );
	}

	/**
	 * A whole number of items, '' for none (empty, zero, negative, not a number).
	 *
	 * @param mixed $raw Value as stored or posted.
	 * @return int|string
	 */
	public static function sanitize_item_limit( $raw ) {
		if ( ! is_scalar( $raw ) ) {
			return '';
		}
		$raw = trim( (string) $raw );
		if ( '' === $raw || ! preg_match( '/^\d{1,7}$/', $raw ) ) {
			return '';
		}
		$n = (int) $raw;
		return $n > 0 ? $n : '';
	}

	/**
	 * The item count rule, inside WooCommerce's coupon validation.
	 *
	 * Counts the cart (or order) items the coupon can discount: for a product
	 * coupon what WooCommerce itself discounts (is_valid_for_product()), for
	 * a cart coupon the items its product and category lists allow.
	 *
	 * @param bool         $valid     WooCommerce's answer so far.
	 * @param WC_Coupon    $coupon    Coupon.
	 * @param WC_Discounts $discounts The cart or order being checked.
	 * @return bool
	 * @throws Exception With the shopper-facing reason (WC_Discounts catches it).
	 */
	public static function validate_items( $valid, $coupon, $discounts = null ) {
		if ( ! $valid || ! $coupon instanceof WC_Coupon ) {
			return $valid;
		}
		$min = self::item_limit( $coupon, 'min' );
		$max = self::item_limit( $coupon, 'max' );
		if ( '' === $min && '' === $max ) {
			return $valid;
		}
		// Only WC_Discounts::is_coupon_valid() passes a WC_Discounts here and
		// catches what this throws. Anyone else asking gets a plain answer
		// about the cart.
		if ( ! $discounts instanceof WC_Discounts ) {
			if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
				return $valid;
			}
			$count = self::eligible_item_count( $coupon, new WC_Discounts( WC()->cart ) );
			return ( '' === $min || $count >= $min ) && ( '' === $max || $count <= $max );
		}
		$count = self::eligible_item_count( $coupon, $discounts );
		if ( '' !== $min && $count < $min ) {
			throw new Exception(
				esc_html(
					sprintf(
						/* translators: %s: the fewest items the cart needs for the coupon. */
						_n( 'This coupon needs at least %s item in the cart.', 'This coupon needs at least %s items in the cart.', $min, 'brikpanel' ),
						brikpanel_number( $min )
					)
				)
			);
		}
		if ( '' !== $max && $count > $max ) {
			throw new Exception(
				esc_html(
					sprintf(
						/* translators: %s: the most items the cart may hold for the coupon. */
						_n( 'This coupon is for up to %s item in the cart.', 'This coupon is for up to %s items in the cart.', $max, 'brikpanel' ),
						brikpanel_number( $max )
					)
				)
			);
		}
		return $valid;
	}

	/**
	 * @param WC_Coupon    $coupon    Coupon.
	 * @param WC_Discounts $discounts Cart or order items.
	 * @return int
	 */
	private static function eligible_item_count( WC_Coupon $coupon, WC_Discounts $discounts ) {
		$product_coupon = $coupon->is_type( wc_get_product_coupon_types() );
		$count          = 0;
		foreach ( (array) $discounts->get_items() as $item ) {
			$product = isset( $item->product ) ? $item->product : null;
			if ( ! $product instanceof WC_Product ) {
				continue;
			}
			if ( $product_coupon ) {
				if ( ! $coupon->is_valid_for_product( $product, isset( $item->object ) ? $item->object : array() ) ) {
					continue;
				}
			} elseif ( ! self::matches_lists( $coupon, $product ) ) {
				continue;
			}
			$count += max( 0, (int) $item->quantity );
		}
		return $count;
	}

	/**
	 * A cart coupon's product and category lists (WooCommerce's
	 * is_valid_for_product() answers no for every cart coupon).
	 *
	 * @param WC_Coupon  $coupon  Coupon.
	 * @param WC_Product $product Cart item product.
	 * @return bool
	 */
	private static function matches_lists( WC_Coupon $coupon, WC_Product $product ) {
		$ids  = array_filter( array( $product->get_id(), $product->get_parent_id() ) );
		$cats = wc_get_product_cat_ids( $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id() );
		$in_ids  = array_map( 'intval', (array) $coupon->get_product_ids() );
		$in_cats = array_map( 'intval', (array) $coupon->get_product_categories() );
		if ( ( $in_ids || $in_cats )
			&& ! array_intersect( $ids, $in_ids )
			&& ! array_intersect( $cats, $in_cats ) ) {
			return false;
		}
		if ( array_intersect( $ids, array_map( 'intval', (array) $coupon->get_excluded_product_ids() ) )
			|| array_intersect( $cats, array_map( 'intval', (array) $coupon->get_excluded_product_categories() ) ) ) {
			return false;
		}
		return true;
	}

	/* ── The index of automatic coupons ───────────────────────────────────── */

	/**
	 * @return array<int,string> [coupon id => code], published only.
	 */
	public static function index() {
		$index = get_option( self::INDEX_OPTION, array() );
		return is_array( $index ) ? $index : array();
	}

	public static function rebuild_index() {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID, p.post_title FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
				 WHERE pm.meta_key = %s AND pm.meta_value = %s
				   AND p.post_type = %s AND p.post_status = %s
				 ORDER BY p.ID ASC",
				self::META_AUTO,
				'yes',
				'shop_coupon',
				'publish'
			)
		);
		$index = array();
		foreach ( (array) $rows as $row ) {
			$code = wc_format_coupon_code( (string) $row->post_title );
			if ( '' !== $code ) {
				$index[ (int) $row->ID ] = $code;
			}
		}
		if ( self::index() !== $index || false === get_option( self::INDEX_OPTION, false ) ) {
			update_option( self::INDEX_OPTION, $index, true );
		}
	}

	/**
	 * Only an automatic coupon (or one that was) changes the index: BrikMentor
	 * and the popup mint coupons all day long.
	 *
	 * @param int            $coupon_id Coupon id.
	 * @param WC_Coupon|null $coupon    Coupon.
	 */
	public static function on_coupon_saved( $coupon_id, $coupon = null ) {
		if ( self::is_auto( $coupon instanceof WC_Coupon ? $coupon : new WC_Coupon( (int) $coupon_id ) )
			|| array_key_exists( (int) $coupon_id, self::index() ) ) {
			self::rebuild_index();
		}
	}

	public static function on_status( $new_status, $old_status, $post ) {
		if ( $new_status !== $old_status && $post instanceof WP_Post && 'shop_coupon' === $post->post_type
			&& ( 'yes' === get_post_meta( $post->ID, self::META_AUTO, true ) || array_key_exists( (int) $post->ID, self::index() ) ) ) {
			self::rebuild_index();
		}
	}

	public static function on_post_change( $post_id ) {
		if ( 'shop_coupon' === get_post_type( $post_id ) || array_key_exists( (int) $post_id, self::index() ) ) {
			self::rebuild_index();
		}
	}

	public static function on_meta_change( $meta_ids, $object_id, $meta_key ) {
		if ( self::META_AUTO === $meta_key ) {
			self::rebuild_index();
		}
	}

	/* ── Keeping the cart in step ─────────────────────────────────────────── */

	/**
	 * A normal page request whose cart changed since the last sync: run the
	 * totals now. The classic cart and checkout pages validate the coupons
	 * (and remove an invalid one with an error) before their own totals pass.
	 * Not on AJAX or REST requests: they run the totals themselves, and an
	 * early pass would leave the block cart's add-item answer with old
	 * totals (it skips its own pass once one ran).
	 *
	 * @param WC_Cart $cart Cart.
	 */
	public static function on_cart_loaded( $cart ) {
		if ( self::$busy || is_admin() || wp_doing_ajax() || self::is_rest() || self::placing_order() ) {
			return;
		}
		if ( ! $cart instanceof WC_Cart || ! self::has_work() || $cart->is_empty() ) {
			return;
		}
		if ( WC()->session->get( self::SESSION_FP ) === self::fingerprint( $cart ) ) {
			return;
		}
		$cart->calculate_totals();
	}

	/**
	 * Puts the right automatic coupons (and a waiting link code) on the cart.
	 *
	 * @param WC_Cart $cart Cart.
	 */
	public static function sync( $cart = null ) {
		if ( self::$busy ) {
			return;
		}
		if ( ! $cart instanceof WC_Cart ) {
			$cart = function_exists( 'WC' ) && WC()->cart ? WC()->cart : null;
		}
		if ( ! $cart || ( is_admin() && ! wp_doing_ajax() ) || ! self::has_work() ) {
			return;
		}
		// Placing the order: WooCommerce checks the coupons itself and says
		// why one cannot stay. A coupon changed now would change the price the
		// shopper agreed to without a word.
		if ( $cart->is_empty() || ! wc_coupons_enabled() || self::placing_order() ) {
			return;
		}
		$fingerprint = self::fingerprint( $cart );
		if ( WC()->session->get( self::SESSION_FP ) === $fingerprint ) {
			return;
		}

		self::$busy       = true;
		self::$removed_at = -1;
		try {
			self::sync_automatic( $cart );
			// A link code that went on is a code the shopper chose: the
			// automatic ones are lined up again around it.
			if ( self::apply_link_code( $cart ) ) {
				self::sync_automatic( $cart );
			}
			// A coupon taken off with no totals pass after it: the totals
			// still count its discount.
			if ( self::$removed_at >= 0 && did_action( 'woocommerce_after_calculate_totals' ) === self::$removed_at ) {
				$cart->calculate_totals();
			}
		} finally {
			self::$busy = false;
		}
		WC()->session->set( self::SESSION_FP, self::fingerprint( $cart ) );
	}

	private static function sync_automatic( WC_Cart $cart ) {
		$index = self::index();
		if ( ! $index ) {
			return;
		}
		$declined = self::declined();
		$applied  = array_map( 'wc_format_coupon_code', (array) $cart->get_applied_coupons() );

		$discounts = array();
		$shipping  = array();
		foreach ( $index as $code ) {
			if ( in_array( $code, $declined, true ) ) {
				continue;
			}
			$coupon = new WC_Coupon( $code );
			if ( ! self::coupon_fits( $coupon ) ) {
				continue;
			}
			if ( $coupon->get_free_shipping() && (float) $coupon->get_amount() <= 0 ) {
				$shipping[ $code ] = $coupon;
			} else {
				$discounts[ $code ] = $coupon;
			}
		}

		// The biggest discount stays. A tie keeps the one already on the cart,
		// else the oldest coupon (the index is in id order): no back and forth.
		$ranked = array();
		$order  = 0;
		foreach ( $discounts as $code => $coupon ) {
			$ranked[] = array(
				'code'    => $code,
				'coupon'  => $coupon,
				'value'   => self::discount_value( $coupon, $cart ),
				'applied' => in_array( $code, $applied, true ) ? 1 : 0,
				'order'   => $order++,
			);
		}
		usort(
			$ranked,
			static function ( $a, $b ) {
				if ( abs( $a['value'] - $b['value'] ) > 0.0001 ) {
					return $a['value'] < $b['value'] ? 1 : -1;
				}
				if ( $a['applied'] !== $b['applied'] ) {
					return $b['applied'] - $a['applied'];
				}
				return $a['order'] - $b['order'];
			}
		);

		// The codes this class does not manage: typed ones, the popup's,
		// BrikMentor's. One of them that may not be combined keeps every
		// automatic coupon off; an automatic coupon that may not be combined
		// does not go on next to them. The winner is the biggest discount
		// that can be on the cart, so one that cannot gives way to the next.
		$foreign      = array_values( array_diff( $applied, $index ) );
		$foreign_solo = false;
		foreach ( $foreign as $code ) {
			$other = new WC_Coupon( $code );
			if ( $other->get_individual_use() ) {
				$foreign_solo = true;
				break;
			}
		}
		$winner = null;
		if ( ! $foreign_solo ) {
			foreach ( $ranked as $candidate ) {
				if ( $candidate['applied'] || ! $foreign || ! $candidate['coupon']->get_individual_use() ) {
					$winner = $candidate;
					break;
				}
			}
		}
		// A winner that may not be combined stands alone: no free shipping
		// coupon next to it (WooCommerce would push them off anyway).
		$keep = ( $foreign_solo || ( $winner && $winner['coupon']->get_individual_use() ) ) ? array() : array_keys( $shipping );
		$want = $keep;
		if ( $winner ) {
			$want[] = $winner['code'];
		}

		foreach ( $applied as $code ) {
			if ( in_array( $code, $index, true ) && ! in_array( $code, $want, true ) ) {
				self::quiet_remove( $cart, $code );
			}
		}
		if ( $winner && ! $cart->has_discount( $winner['code'] ) ) {
			self::quiet_apply( $cart, $winner['code'], false );
		}
		foreach ( $keep as $code ) {
			if ( ! $cart->has_discount( $code ) ) {
				self::quiet_apply( $cart, $code, false );
			}
		}
	}

	/**
	 * What the coupon would take off this cart, worked out by WooCommerce's
	 * own discount class without touching the cart.
	 */
	private static function discount_value( WC_Coupon $coupon, WC_Cart $cart ) {
		try {
			$discounts = new WC_Discounts( $cart );
			$result    = $discounts->apply_coupon( $coupon, false );
			if ( is_wp_error( $result ) ) {
				return 0.0;
			}
			return (float) array_sum( (array) $discounts->get_discounts_by_coupon() );
		} catch ( \Throwable $e ) {
			return 0.0;
		}
	}

	/**
	 * A code waiting from a coupon link goes on once the cart qualifies.
	 *
	 * @return bool Whether it went on now.
	 */
	private static function apply_link_code( WC_Cart $cart ) {
		$code = (string) WC()->session->get( self::SESSION_LINK, '' );
		if ( '' === $code ) {
			return false;
		}
		$coupon = new WC_Coupon( $code );
		if ( $cart->has_discount( $code ) || ! $coupon->get_id() || '' !== self::dead_coupon_error( $coupon ) ) {
			WC()->session->set( self::SESSION_LINK, null );
			return false;
		}
		if ( self::quiet_apply( $cart, $code, true ) ) {
			WC()->session->set( self::SESSION_LINK, null );
			self::drop_saved_notice( $code );
			return true;
		}
		return false;
	}

	/** The line a shopper reads when a link code waits for the cart. */
	private static function saved_notice( $code ) {
		return sprintf(
			/* translators: %s: coupon code. */
			__( 'Coupon %s is saved and will be applied when your cart qualifies.', 'brikpanel' ),
			esc_html( $code )
		);
	}

	/**
	 * The code went on before the shopper saw that it waits (a block theme's
	 * home page prints no notices): that line would now stand next to
	 * WooCommerce's "applied successfully". Only this class's own line goes.
	 */
	private static function drop_saved_notice( $code ) {
		$all = wc_get_notices();
		if ( empty( $all['notice'] ) || ! is_array( $all['notice'] ) ) {
			return;
		}
		$text = self::saved_notice( $code );
		$kept = array();
		foreach ( $all['notice'] as $notice ) {
			$message = is_array( $notice ) && isset( $notice['notice'] ) ? $notice['notice'] : $notice;
			if ( $message !== $text ) {
				$kept[] = $notice;
			}
		}
		if ( count( $kept ) !== count( $all['notice'] ) ) {
			$all['notice'] = $kept;
			wc_set_notices( $all );
		}
	}

	/**
	 * Applies a coupon only when WooCommerce would accept it without a word:
	 * the same checks its apply_coupon() makes, first.
	 *
	 * @param WC_Cart $cart      Cart.
	 * @param string  $code      Coupon code.
	 * @param bool    $from_link A code from a coupon link: the shopper chose
	 *                           it, so it keeps WooCommerce's "applied
	 *                           successfully" and may push automatic coupons
	 *                           off when it takes off more than they do. An
	 *                           automatic coupon goes on quietly and pushes
	 *                           nothing off.
	 * @return bool Whether the cart has the coupon now.
	 */
	private static function quiet_apply( WC_Cart $cart, $code, $from_link ) {
		$coupon = new WC_Coupon( $code );
		if ( wc_format_coupon_code( $coupon->get_code() ) !== $code || ! self::coupon_fits( $coupon ) ) {
			return false;
		}
		$others = array_diff( array_map( 'wc_format_coupon_code', (array) $cart->get_applied_coupons() ), array( $code ) );
		$mine   = $from_link ? array_values( array_intersect( $others, self::index() ) ) : array();
		$others = array_diff( $others, $mine );
		// Never push a code off: WooCommerce removes every other coupon when a
		// coupon that may not be combined goes on.
		if ( $others && $coupon->get_individual_use() ) {
			return false;
		}
		foreach ( $others as $other ) {
			$applied = new WC_Coupon( $other );
			if ( $applied->get_individual_use() ) {
				return false;
			}
		}
		// The automatic coupons the link code cannot stand next to. They give
		// way only to a bigger discount: the shopper never loses money by
		// opening a link. A smaller code waits until they are gone.
		$pushed = array();
		foreach ( $mine as $other ) {
			$applied = new WC_Coupon( $other );
			if ( $coupon->get_individual_use() || $applied->get_individual_use() ) {
				$pushed[ $other ] = $applied;
			}
		}
		if ( $pushed ) {
			$lost = 0.0;
			foreach ( $pushed as $applied ) {
				$lost += self::discount_value( $applied, $cart );
			}
			if ( self::discount_value( $coupon, $cart ) < $lost ) {
				return false;
			}
			foreach ( array_keys( $pushed ) as $other ) {
				self::quiet_remove( $cart, $other );
			}
		}

		$mute = static function ( $message, $message_code, $subject ) use ( $code ) {
			if ( WC_Coupon::WC_COUPON_SUCCESS === (int) $message_code && $subject instanceof WC_Coupon
				&& wc_format_coupon_code( $subject->get_code() ) === $code ) {
				return '';
			}
			return $message;
		};
		if ( ! $from_link ) {
			add_filter( 'woocommerce_coupon_message', $mute, PHP_INT_MAX, 3 );
		}
		self::$engine = true;
		try {
			$cart->apply_coupon( $code );
		} finally {
			self::$engine = false;
			if ( ! $from_link ) {
				remove_filter( 'woocommerce_coupon_message', $mute, PHP_INT_MAX );
			}
		}
		return $cart->has_discount( $code );
	}

	private static function quiet_remove( WC_Cart $cart, $code ) {
		self::$engine = true;
		try {
			$cart->remove_coupon( $code );
		} finally {
			self::$engine = false;
		}
		self::$removed_at = did_action( 'woocommerce_after_calculate_totals' );
	}

	/**
	 * WooCommerce's check plus what it checks only when the order is placed:
	 * the coupon's email list and, for a guest, its uses per customer by
	 * billing email. A coupon put on now and refused at checkout would come
	 * back after every refusal.
	 */
	private static function coupon_fits( WC_Coupon $coupon ) {
		if ( ! $coupon->get_id() || ! $coupon->is_valid() ) {
			return false;
		}
		$user    = wp_get_current_user();
		$billing = WC()->customer ? strtolower( sanitize_email( (string) WC()->customer->get_billing_email() ) ) : '';
		$emails  = array_values( array_unique( array_filter( array( strtolower( sanitize_email( (string) $user->user_email ) ), $billing ) ) ) );

		$allowed = array_filter( (array) $coupon->get_email_restrictions() );
		if ( $allowed ) {
			if ( ! $emails ) {
				return false;
			}
			if ( class_exists( '\Automattic\WooCommerce\Utilities\DiscountsUtil' ) ) {
				$ok = \Automattic\WooCommerce\Utilities\DiscountsUtil::is_coupon_emails_allowed( $emails, $allowed );
			} else {
				$ok = WC()->cart && WC()->cart->is_coupon_emails_allowed( $emails, $allowed );
			}
			if ( ! $ok ) {
				return false;
			}
		}

		$per_customer = (int) $coupon->get_usage_limit_per_user();
		if ( $per_customer > 0 && ! $user->exists() && '' !== $billing ) {
			$store = $coupon->get_data_store();
			if ( $store && is_callable( array( $store, 'get_usage_by_email' ) ) && $store->get_usage_by_email( $coupon, $billing ) >= $per_customer ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Why a coupon can never go on, whatever the cart holds: its date has
	 * passed or it is used up. The shopper reads WooCommerce's own words.
	 *
	 * @return string '' when the coupon can still go on.
	 */
	private static function dead_coupon_error( WC_Coupon $coupon ) {
		$expires = $coupon->get_date_expires();
		if ( $expires && function_exists( 'WC' ) && WC()->cart
			&& apply_filters( 'woocommerce_coupon_validate_expiry_date', time() > $expires->getTimestamp(), $coupon, new WC_Discounts( WC()->cart ) ) ) {
			return (string) $coupon->get_coupon_error( WC_Coupon::E_WC_COUPON_EXPIRED );
		}
		$limit = (int) $coupon->get_usage_limit();
		if ( $limit > 0 && (int) $coupon->get_usage_count() >= $limit ) {
			return (string) $coupon->get_coupon_error( WC_Coupon::E_WC_COUPON_USAGE_LIMIT_REACHED );
		}
		return '';
	}

	/* ── The shopper's own choices ────────────────────────────────────────── */

	/**
	 * A shopper who takes an automatic coupon off keeps it off for the visit.
	 * WooCommerce's own removals (an invalid coupon, one pushed off by a
	 * coupon that may not be combined) and this class's are not choices.
	 *
	 * @param string $code Coupon code.
	 */
	public static function on_removed( $code ) {
		if ( self::$engine || ! function_exists( 'WC' ) || ! WC()->session ) {
			return;
		}
		$code = wc_format_coupon_code( (string) $code );
		if ( ! in_array( $code, self::index(), true ) || ! self::shopper_is_removing( $code ) ) {
			return;
		}
		$declined = self::declined();
		if ( ! in_array( $code, $declined, true ) ) {
			$declined[] = $code;
			WC()->session->set( self::SESSION_DECLINED, array_slice( $declined, -50 ) );
		}
	}

	/**
	 * Typing a declined automatic code again takes it back.
	 *
	 * @param string $code Coupon code.
	 */
	public static function on_applied( $code ) {
		if ( self::$engine || ! function_exists( 'WC' ) || ! WC()->session ) {
			return;
		}
		$code     = wc_format_coupon_code( (string) $code );
		$declined = self::declined();
		if ( in_array( $code, $declined, true ) ) {
			WC()->session->set( self::SESSION_DECLINED, array_values( array_diff( $declined, array( $code ) ) ) );
		}
	}

	public static function mark_store_api_removal( $response, $handler, $request ) {
		if ( $request instanceof WP_REST_Request ) {
			$route  = (string) $request->get_route();
			$method = (string) $request->get_method();
			if ( preg_match( '#^/wc/store(?:/v\d+)?/cart/#', $route ) ) {
				if ( 'POST' === $method && preg_match( '#/cart/remove-coupon$#', $route ) ) {
					self::$store_api_removal = wc_format_coupon_code( (string) $request->get_param( 'code' ) );
				} elseif ( 'DELETE' === $method && preg_match( '#/cart/coupons/(.+)$#', $route, $m ) ) {
					$code                    = $request->get_param( 'code' );
					self::$store_api_removal = wc_format_coupon_code( is_scalar( $code ) && '' !== (string) $code ? (string) $code : rawurldecode( $m[1] ) );
				} elseif ( 'DELETE' === $method && preg_match( '#/cart/coupons$#', $route ) ) {
					self::$store_api_removal = '*';
				}
			} elseif ( 'GET' !== $method && preg_match( '#^/wc/store(?:/v\d+)?/checkout(?:/|$)#', $route ) ) {
				self::$store_api_checkout = true;
			}
		}
		return $response;
	}

	public static function unmark_store_api_removal( $response, $handler, $request ) {
		self::$store_api_removal  = '';
		self::$store_api_checkout = false;
		return $response;
	}

	/**
	 * Whether this request is the shopper taking exactly this code off: in the
	 * same request WooCommerce may drop another coupon that stopped being
	 * valid, which is not the shopper's choice.
	 *
	 * @param string $code Coupon code being removed.
	 * @return bool
	 */
	private static function shopper_is_removing( $code ) {
		if ( '' !== self::$store_api_removal ) {
			return '*' === self::$store_api_removal || $code === self::$store_api_removal;
		}
		// phpcs:disable WordPress.Security.NonceVerification -- reads which coupon this request removes, changes nothing.
		$asked = '';
		if ( isset( $_GET['remove_coupon'] ) && is_scalar( $_GET['remove_coupon'] ) ) {
			$asked = urldecode( (string) wp_unslash( $_GET['remove_coupon'] ) );
		} else {
			$wc_ajax = isset( $_GET['wc-ajax'] ) && is_scalar( $_GET['wc-ajax'] ) ? sanitize_key( wp_unslash( $_GET['wc-ajax'] ) ) : '';
			$action  = isset( $_REQUEST['action'] ) && is_scalar( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
			if ( ( 'remove_coupon' === $wc_ajax || 'woocommerce_remove_coupon' === $action )
				&& isset( $_POST['coupon'] ) && is_scalar( $_POST['coupon'] ) ) {
				$asked = (string) wp_unslash( $_POST['coupon'] );
			}
		}
		// phpcs:enable
		return '' !== $asked && wc_format_coupon_code( $asked ) === $code;
	}

	/* ── Coupon links ─────────────────────────────────────────────────────── */

	/**
	 * `?bp-coupon=CODE` on a store page. The cart share link (with products,
	 * `bp-cart`) is handled earlier on wp_loaded and ends that request; this
	 * is the code on its own.
	 */
	public static function handle_link() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- a coupon link is a public URL; it only adds a code anyone may type.
		if ( empty( $_GET[ self::LINK_VAR ] ) || ! is_scalar( $_GET[ self::LINK_VAR ] ) ) {
			return;
		}
		// A page the shopper opens: not a form post, an API call (the REST
		// flags are not set yet on wp_loaded) or a crawler.
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		if ( 'GET' !== $method || is_admin() || wp_doing_ajax() || self::is_rest() || ! empty( $_GET['rest_route'] )
			|| ( function_exists( 'WC' ) && is_callable( array( WC(), 'is_rest_api_request' ) ) && WC()->is_rest_api_request() )
			|| ( function_exists( 'wp_is_json_request' ) && wp_is_json_request() ) ) {
			return;
		}
		$raw  = (string) wp_unslash( $_GET[ self::LINK_VAR ] );
		// phpcs:enable
		$code = wc_format_coupon_code( $raw );
		if ( function_exists( '_brikpanel_is_bot_ua' ) && _brikpanel_is_bot_ua() ) {
			return;
		}
		if ( '' === $code || strlen( $code ) > 200 || ! function_exists( 'WC' ) || ! WC()->session || ! WC()->cart ) {
			self::redirect_without_link();
			return;
		}
		if ( ! wc_coupons_enabled() || ! wc_get_coupon_id_by_code( $code ) || WC()->cart->has_discount( $code ) ) {
			self::redirect_without_link();
			return;
		}

		WC()->session->set_customer_session_cookie( true );
		// The code ran out (date, uses): WooCommerce's own words, nothing kept.
		$dead = self::dead_coupon_error( new WC_Coupon( $code ) );
		if ( '' !== $dead ) {
			wc_add_notice( $dead, 'error' );
			self::redirect_without_link();
			return;
		}
		// The shopper asks for it: an automatic coupon they took off earlier
		// may come back.
		$declined = self::declined();
		if ( in_array( $code, $declined, true ) ) {
			WC()->session->set( self::SESSION_DECLINED, array_values( array_diff( $declined, array( $code ) ) ) );
		}
		$applied = ! WC()->cart->is_empty() && self::quiet_apply( WC()->cart, $code, true );
		if ( ! $applied ) {
			WC()->session->set( self::SESSION_LINK, $code );
			wc_add_notice( self::saved_notice( $code ), 'notice' );
		}
		self::redirect_without_link();
	}

	private static function redirect_without_link() {
		$url = remove_query_arg( self::LINK_VAR );
		if ( $url && wp_safe_redirect( $url ) ) {
			exit;
		}
	}

	/* ── Helpers ──────────────────────────────────────────────────────────── */

	/** Anything to do at all: an automatic coupon exists or a link code waits. */
	private static function has_work() {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return false;
		}
		return (bool) self::index() || '' !== (string) WC()->session->get( self::SESSION_LINK, '' );
	}

	/** @return string[] */
	private static function declined() {
		$declined = WC()->session->get( self::SESSION_DECLINED, array() );
		return is_array( $declined ) ? array_values( array_filter( array_map( 'strval', $declined ) ) ) : array();
	}

	/**
	 * Whether this request places the order: the classic checkout (its form
	 * or its AJAX call) or the block checkout.
	 */
	private static function placing_order() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- only asks whether the checkout form was posted; WooCommerce checks its nonce.
		return self::$store_api_checkout || did_action( 'woocommerce_before_checkout_process' ) || isset( $_POST['woocommerce-process-checkout-nonce'] );
	}

	/**
	 * The cart's lines and quantities, its coupons and subtotal, the declined
	 * codes, a waiting link code and who is shopping (email lists, uses per
	 * customer): a sync with the same answer is skipped.
	 */
	private static function fingerprint( WC_Cart $cart ) {
		$lines = array();
		foreach ( (array) $cart->get_cart() as $key => $item ) {
			$lines[ $key ] = isset( $item['quantity'] ) ? (float) $item['quantity'] : 0;
		}
		ksort( $lines );
		$coupons = array_map( 'wc_format_coupon_code', (array) $cart->get_applied_coupons() );
		sort( $coupons );
		return md5(
			wp_json_encode(
				array(
					$lines,
					$coupons,
					(string) $cart->get_subtotal(),
					self::declined(),
					(string) WC()->session->get( self::SESSION_LINK, '' ),
					self::index(),
					get_current_user_id(),
					WC()->customer ? strtolower( (string) WC()->customer->get_billing_email() ) : '',
				)
			)
		);
	}

	private static function is_rest() {
		if ( function_exists( 'wp_is_serving_rest_request' ) ) {
			return wp_is_serving_rest_request();
		}
		return defined( 'REST_REQUEST' ) && REST_REQUEST;
	}

	/**
	 * Tell Import / Export how to carry the index: it is rebuilt from the
	 * coupons, whose own switch and item limits travel with them.
	 *
	 * @param array $map Registry so far.
	 * @return array
	 */
	public static function register_export_keys( $map ) {
		$map[ self::INDEX_OPTION ] = array( 'class' => 'internal' );
		return $map;
	}
}

add_filter( 'brikpanel_exportable_option_keys', array( 'Brikpanel_Auto_Coupons', 'register_export_keys' ) );

Brikpanel_Auto_Coupons::register();
