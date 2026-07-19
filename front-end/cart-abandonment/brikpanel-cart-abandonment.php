<?php
/**
 * BrikPanel — Cart Abandonment email capture.
 *
 * Collects customer emails the moment they are typed on the checkout page
 * (classic shortcode checkout, Gutenberg block checkout, and any third-party
 * checkout that renders a native email input) plus an optional site-wide
 * signup popup. Each captured email is stored in wp_brikpanel_abandoned_carts
 * together with a live cart snapshot, and rows move through a simple
 * lifecycle: active → abandoned (no activity for N minutes) → recovered
 * (an order was placed with that email / browser).
 *
 * This module only COLLECTS — it never sends emails. The follow-up sending
 * layer lives in the separate BrikMentor plugin, which consumes the data via
 * brikpanel_cartab_get_entries() and the brikpanel_cart_abandoned /
 * brikpanel_cart_recovered / brikpanel_cartab_email_captured hooks.
 *
 * The popup ↔ checkout bridge (Klaviyo-style): a visitor who leaves their
 * email in the popup gets a browser id cookie; when that same browser later
 * builds a cart and abandons it WITHOUT typing an email at checkout, the
 * woocommerce_cart_updated listener keeps their popup row's cart snapshot
 * fresh — so the abandoned cart is still attributable to a known email.
 *
 * Loaded OUTSIDE the is_admin() gate: capture endpoints, cart listeners and
 * order-recovery hooks all fire on front-end requests. Admin surfaces
 * (menu page, list AJAX, exports, settings fields) self-gate.
 *
 * @package BrikPanel
 * @since   3.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Brikpanel_Cart_Abandonment {

	const PAGE_SLUG    = 'brikpanel-abandoned-carts';
	const NONCE_ACTION = 'brikpanel_cartab_nonce';
	const TABLE        = 'brikpanel_abandoned_carts';
	const COOKIE       = 'brikpanel_vid'; // shared with the live-visitors tracker on purpose: one browser = one id across features.

	public function __construct() {
		// Admin page + management AJAX (registered unconditionally so the list
		// stays reachable even while collection is switched off — the merchant
		// still needs to see/export previously captured emails).
		add_action( 'admin_menu', [ $this, 'register_page' ], 11 );
		add_action( 'wp_ajax_brikpanel_cartab_list',         [ $this, 'ajax_list' ] );
		add_action( 'wp_ajax_brikpanel_cartab_delete',       [ $this, 'ajax_delete' ] );
		add_action( 'wp_ajax_brikpanel_cartab_popup_toggle', [ $this, 'ajax_popup_toggle' ] );
		add_action( 'wp_ajax_brikpanel_cartab_popup_discount', [ $this, 'ajax_popup_discount' ] );
		add_action( 'wp_ajax_brikpanel_cartab_export',       [ $this, 'ajax_export' ] );
		add_action( 'wp_ajax_brikpanel_cartab_import',       [ $this, 'ajax_import' ] );

		// Popup text options: WC's default text sanitizer (sanitize_text_field)
		// strips anything that looks like a percent-encoded octet, so a title
		// like "%10 indirim" would silently lose its "%10". Override with a
		// sanitizer that keeps the percent sign.
		foreach ( [ 'popup_title', 'popup_message', 'popup_button', 'popup_placeholder', 'popup_success', 'popup_teaser' ] as $opt ) {
			add_filter(
				'woocommerce_admin_settings_sanitize_option_brikpanel_cartab_' . $opt,
				[ __CLASS__, 'sanitize_popup_text' ],
				10,
				3
			);
		}

		// Settings tab wiring (section + group + icon + fields).
		add_filter( 'woocommerce_get_sections_brikpanel',      [ $this, 'settings_section' ] );
		add_filter( 'brikpanel_settings_section_groups',       [ $this, 'settings_group' ] );
		add_filter( 'brikpanel_settings_title_section_map',    [ $this, 'settings_title_map' ] );
		add_filter( 'brikpanel_settings_section_icons',        [ $this, 'settings_icon' ] );
		add_filter( 'brikpanel_settings_fields',               [ $this, 'settings_fields' ] );

		if ( ! self::is_enabled() ) {
			return;
		}

		// Public capture surface.
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_frontend' ] );
		add_action( 'wp_ajax_nopriv_brikpanel_cartab_capture', [ $this, 'ajax_capture' ] );
		add_action( 'wp_ajax_brikpanel_cartab_capture',        [ $this, 'ajax_capture' ] );

		// Server-side cart mirror: keeps snapshots fresh for every known email
		// (popup or checkout sourced) without relying on any front-end JS.
		add_action( 'woocommerce_cart_updated', [ $this, 'on_cart_updated' ] );

		// Recovery: classic checkout, block (Store API) checkout, plus a
		// status-change safety net for checkout flows that bypass both hooks
		// (express payment plugins, phone orders entered by staff, REST).
		add_action( 'woocommerce_checkout_order_processed',           [ $this, 'on_checkout_processed' ], 10, 3 );
		add_action( 'woocommerce_store_api_checkout_order_processed', [ $this, 'on_store_api_processed' ] );
		add_action( 'woocommerce_order_status_changed',               [ $this, 'on_order_status_changed' ], 10, 4 );
	}

	// =========================================================================
	// State helpers
	// =========================================================================

	/** Whether email collection is on (defaults to on; master-switch aware via the option gate). */
	public static function is_enabled() {
		return get_option( 'brikpanel_cartab_enabled', 'yes' ) !== 'no';
	}

	/** Configured popup discount percentage (0 disables the coupon). */
	public static function popup_discount() {
		return max( 0, min( 100, (int) get_option( 'brikpanel_cartab_popup_discount', 10 ) ) );
	}

	/** Selected offer-badge animation style, whitelisted against known styles. */
	public static function popup_style() {
		$style = (string) get_option( 'brikpanel_cartab_popup_style', 'envelope' );
		$known = [ 'pocket', 'scratch', 'slot', 'envelope', 'assembly', 'classic' ];
		return in_array( $style, $known, true ) ? $style : 'envelope';
	}

	/** Popup configuration with translatable fallbacks for unset options. */
	public static function popup_config() {
		$text = static function ( $key, $default ) {
			$v = get_option( $key, false );
			return ( $v === false || $v === '' ) ? $default : (string) $v;
		};
		$message = $text(
			'brikpanel_cartab_popup_message',
			__( 'Subscribe to get special offers and updates.', 'brikpanel' )
		);
		$discount = self::popup_discount();
		if ( $discount > 0 ) {
			/* translators: %d: discount percentage */
			$default_title = sprintf( __( 'Sign up and get %d%% off', 'brikpanel' ), $discount );
			/* translators: %d: discount percentage */
			$default_teaser = sprintf( __( 'Get %d%% off', 'brikpanel' ), $discount );
		} else {
			$default_title  = __( 'Sign up and save', 'brikpanel' );
			$default_teaser = __( 'Special offer', 'brikpanel' );
		}
		return [
			'enabled'     => get_option( 'brikpanel_cartab_popup_enabled', 'no' ) === 'yes',
			'delay'       => max( 0, (int) get_option( 'brikpanel_cartab_popup_delay', 5 ) ),
			'cooldown'    => max( 1, (int) get_option( 'brikpanel_cartab_popup_cooldown', 7 ) ),
			'discount'    => $discount,
			'style'       => self::popup_style(),
			'title'       => $text( 'brikpanel_cartab_popup_title', $default_title ),
			'message'     => (string) $message,
			'button'      => $text( 'brikpanel_cartab_popup_button', __( 'Subscribe', 'brikpanel' ) ),
			'placeholder' => $text( 'brikpanel_cartab_popup_placeholder', __( 'Enter your email', 'brikpanel' ) ),
			'success'     => $text( 'brikpanel_cartab_popup_success', __( 'Thanks for subscribing!', 'brikpanel' ) ),
			'teaser'      => $text( 'brikpanel_cartab_popup_teaser', $default_teaser ),
		];
	}

	/**
	 * Percent-preserving sanitizer for the popup text settings. Strips tags
	 * and control characters but keeps "%" intact (the popup renders these
	 * values via textContent, never as HTML).
	 *
	 * @param mixed  $value     WC-sanitized value (unused — recomputed from raw).
	 * @param array  $option    Field definition.
	 * @param string $raw_value Raw submitted value.
	 * @return string
	 */
	public static function sanitize_popup_text( $value, $option, $raw_value ) {
		$clean = wp_strip_all_tags( (string) $raw_value );
		$clean = preg_replace( '/[\x00-\x09\x0B\x0C\x0E-\x1F\x7F]/', '', $clean );
		return substr( trim( $clean ), 0, 500 );
	}

	private static function table() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	/**
	 * Resolve (and lazily create) the per-browser visitor id cookie. Returns
	 * an empty string when the cookie cannot be set (headers already sent) —
	 * rows then dedupe on email alone, so nothing is lost.
	 */
	private static function visitor_id() {
		if ( isset( $_COOKIE[ self::COOKIE ] ) ) {
			return substr( sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) ), 0, 64 );
		}
		$new_id = uniqid( 'bp_', true );
		if ( headers_sent() ) {
			return '';
		}
		setcookie( self::COOKIE, $new_id, time() + YEAR_IN_SECONDS, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true );
		$_COOKIE[ self::COOKIE ] = $new_id;
		return $new_id;
	}

	/** Cookie value if it already exists — never creates one (read-only paths). */
	private static function existing_visitor_id() {
		return isset( $_COOKIE[ self::COOKIE ] )
			? substr( sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) ), 0, 64 )
			: '';
	}

	/** Store staff never get tracked (their test checkouts would pollute the list). */
	private static function is_staff() {
		return is_user_logged_in() && current_user_can( 'manage_woocommerce' );
	}

	public static function status_labels() {
		return [
			'active'    => __( 'Active', 'brikpanel' ),
			'abandoned' => __( 'Abandoned', 'brikpanel' ),
			'recovered' => __( 'Recovered', 'brikpanel' ),
		];
	}

	public static function source_labels() {
		return [
			'checkout' => __( 'Checkout', 'brikpanel' ),
			'popup'    => __( 'Popup', 'brikpanel' ),
			'account'  => __( 'Account', 'brikpanel' ),
			'import'   => __( 'Imported', 'brikpanel' ),
		];
	}

	// =========================================================================
	// Cart snapshot
	// =========================================================================

	/**
	 * Serialize the current WooCommerce cart. Runs server-side only (the
	 * client never supplies cart data). Variation attributes are resolved to
	 * human labels (taxonomy label + term name) so exports and BrikMentor
	 * emails can render "Size: L" without a further lookup.
	 *
	 * @return array{items:array,count:int,total:float,currency:string}
	 */
	private static function cart_snapshot() {
		$out = [
			'items'    => [],
			'count'    => 0,
			'total'    => 0.0,
			'currency' => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '',
		];
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return $out;
		}
		foreach ( WC()->cart->get_cart() as $item ) {
			$product = ( isset( $item['data'] ) && $item['data'] instanceof WC_Product ) ? $item['data'] : null;
			$attrs   = [];
			if ( ! empty( $item['variation'] ) && is_array( $item['variation'] ) ) {
				foreach ( $item['variation'] as $key => $value ) {
					$taxonomy = str_replace( 'attribute_', '', (string) $key );
					$label    = function_exists( 'wc_attribute_label' ) ? wc_attribute_label( $taxonomy ) : $taxonomy;
					$value    = (string) $value;
					if ( taxonomy_exists( $taxonomy ) ) {
						$term = get_term_by( 'slug', $value, $taxonomy );
						if ( $term && ! is_wp_error( $term ) ) {
							$value = $term->name;
						}
					}
					$attrs[ $label ] = $value;
				}
			}
			$qty            = (float) $item['quantity'];
			$out['items'][] = [
				'product_id'   => (int) $item['product_id'],
				'variation_id' => isset( $item['variation_id'] ) ? (int) $item['variation_id'] : 0,
				'name'         => $product ? $product->get_name() : '',
				'sku'          => $product ? (string) $product->get_sku() : '',
				'qty'          => $qty,
				'line_total'   => (float) ( $item['line_total'] ?? 0 ) + (float) ( $item['line_tax'] ?? 0 ),
				'attributes'   => $attrs,
			];
			$out['count'] += (int) $qty;
		}
		$out['total'] = (float) WC()->cart->get_cart_contents_total() + (float) WC()->cart->get_cart_contents_tax();
		return $out;
	}

	// =========================================================================
	// Front-end assets (checkout capture + popup)
	// =========================================================================

	public function enqueue_frontend() {
		if ( is_admin() || self::is_staff() ) {
			return;
		}
		if ( function_exists( '_brikpanel_is_bot_ua' ) && _brikpanel_is_bot_ua() ) {
			return;
		}

		$is_order_received = function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'order-received' );
		$is_checkout       = function_exists( 'is_checkout' ) && is_checkout();
		if ( ! $is_checkout && is_singular() ) {
			// Custom checkout pages that are not the assigned WC checkout page
			// (e.g. a landing page embedding the checkout block or shortcode).
			$post = get_post();
			if ( $post && ( has_block( 'woocommerce/checkout', $post ) || has_shortcode( (string) $post->post_content, 'woocommerce_checkout' ) ) ) {
				$is_checkout = true;
			}
		}
		$is_checkout = $is_checkout && ! $is_order_received;
		$popup             = self::popup_config();
		$popup_here        = $popup['enabled'] && ! $is_checkout && ! $is_order_received;

		if ( ! $is_checkout && ! $popup_here ) {
			return;
		}

		$dir  = plugin_dir_path( __FILE__ );
		$url  = plugin_dir_url( __FILE__ );
		$css  = $dir . 'cart-abandonment.css';
		$js   = $dir . 'cart-abandonment.js';

		if ( $popup_here ) {
			wp_enqueue_style(
				'brikpanel_cartab_styles',
				$url . 'cart-abandonment.css',
				[],
				file_exists( $css ) ? (string) filemtime( $css ) : BRIKPANEL_VERSION
			);
		}
		wp_enqueue_script(
			'brikpanel_cartab_scripts',
			$url . 'cart-abandonment.js',
			[],
			file_exists( $js ) ? (string) filemtime( $js ) : BRIKPANEL_VERSION,
			true
		);

		// A logged-in customer's email is already known server-side; hand it to
		// the script so a capture ping fires even if they never touch the
		// (prefilled) email field.
		$known_email = '';
		if ( is_user_logged_in() ) {
			$user        = wp_get_current_user();
			$known_email = (string) $user->user_email;
		}

		wp_localize_script( 'brikpanel_cartab_scripts', 'brikpanelCartAb', [
			'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
			'isCheckout' => $is_checkout ? 1 : 0,
			'knownEmail' => $is_checkout ? $known_email : '',
			'popup'      => [
				'enabled'     => $popup_here ? 1 : 0,
				'delay'       => $popup['delay'],
				'cooldown'    => $popup['cooldown'],
				'discount'    => $popup['discount'],
				'style'       => $popup['style'],
				'title'       => $popup['title'],
				'message'     => $popup['message'],
				'button'      => $popup['button'],
				'placeholder' => $popup['placeholder'],
				'success'     => $popup['success'],
				'teaser'      => $popup['teaser'],
			],
			'i18n'       => [
				'invalidEmail' => __( 'Please enter a valid email address.', 'brikpanel' ),
				'error'        => __( 'Something went wrong. Please try again.', 'brikpanel' ),
				'close'        => __( 'Close', 'brikpanel' ),
				'emailLabel'   => __( 'Email address', 'brikpanel' ),
				'couponIntro'  => __( 'Your discount code', 'brikpanel' ),
				'couponHint'   => __( 'Apply it at checkout. Valid for 30 days.', 'brikpanel' ),
				/* translators: %s: the visitor's email address */
				'couponEmailed'     => __( 'We sent your discount code to %s', 'brikpanel' ),
				'couponEmailedHint' => __( 'Check your inbox — if it landed in the Promotions tab, drag it to Primary so you never miss it.', 'brikpanel' ),
				'copy'         => __( 'Copy', 'brikpanel' ),
				'copied'       => __( 'Copied!', 'brikpanel' ),
				'offBadge'     => __( 'OFF', 'brikpanel' ),
				'scratchMe'    => __( 'Scratch me', 'brikpanel' ),
			],
		] );
	}

	// =========================================================================
	// AJAX: public capture endpoint
	// =========================================================================

	/**
	 * Public (nopriv) capture endpoint. No nonce on purpose — the endpoint is
	 * reachable by cached pages / logged-out visitors where nonces go stale.
	 * Defenses instead: bot UA filter, strict email validation, tight length
	 * caps, and a per-visitor rate limit. Cart data is NEVER read from the
	 * request; the snapshot comes from the server-side session cart.
	 */
	public function ajax_capture() {
		if ( ! self::is_enabled() || self::is_staff() ) {
			wp_send_json_success( [ 'skipped' => true ] );
		}
		if ( function_exists( '_brikpanel_is_bot_ua' ) && _brikpanel_is_bot_ua() ) {
			wp_send_json_success( [ 'skipped' => true ] );
		}

		$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		if ( $email === '' || strlen( $email ) > 190 || ! is_email( $email ) ) {
			wp_send_json_error( [ 'message' => __( 'Please enter a valid email address.', 'brikpanel' ) ], 400 );
		}

		$source = isset( $_POST['source'] ) ? sanitize_key( wp_unslash( $_POST['source'] ) ) : 'checkout';
		if ( ! in_array( $source, [ 'checkout', 'popup' ], true ) ) {
			$source = 'checkout';
		}

		$visitor = self::visitor_id();

		// Rate limit: at most one accepted capture per 2s per browser (per IP
		// when the cookie could not be set). Popup submits are exempt — they
		// are deliberate one-off user actions and must not lose their coupon
		// to a racing checkout-poller ping.
		$rl_id  = $visitor !== '' ? $visitor : ( isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : 'unknown' );
		$rl_key = 'bp_cartab_rl_' . md5( $rl_id );
		if ( 'popup' !== $source ) {
			if ( get_transient( $rl_key ) ) {
				wp_send_json_success( [ 'throttled' => true ] );
			}
			set_transient( $rl_key, 1, 2 );
		}

		$extra = [];
		foreach ( [ 'first_name' => 100, 'last_name' => 100, 'phone' => 40 ] as $field => $max ) {
			if ( isset( $_POST[ $field ] ) ) {
				$value = sanitize_text_field( wp_unslash( $_POST[ $field ] ) );
				if ( $value !== '' ) {
					$extra[ $field ] = substr( $value, 0, $max );
				}
			}
		}

		$id       = $this->upsert( $visitor, strtolower( $email ), $source, $extra );
		$response = [ 'id' => (int) $id ];

		// Popup signups earn a personal discount coupon (when configured).
		if ( 'popup' === $source ) {
			self::remember_customer_email( strtolower( $email ) );
			$coupon = self::get_or_create_popup_coupon( strtolower( $email ) );
			if ( $coupon ) {
				/**
				 * How the popup coupon reaches the visitor.
				 *
				 * 'inline' (default) shows the code in the popup; 'email'
				 * suppresses it and tells the visitor to check their inbox —
				 * a companion plugin (e.g. BrikMentor) then delivers the code
				 * by email via the deferred action below.
				 *
				 * @param string $delivery 'inline' | 'email'.
				 * @param array  $coupon   {code, amount}.
				 * @param string $email    Lowercased captured email.
				 * @param int    $id       Cart-abandonment entry id.
				 */
				$delivery = apply_filters( 'brikpanel_cartab_popup_coupon_delivery', 'inline', $coupon, strtolower( $email ), (int) $id );
				if ( 'email' === $delivery ) {
					$response['coupon_emailed'] = true;
					$response['email']          = strtolower( $email );
					/**
					 * The popup coupon was deferred to email delivery — the
					 * companion plugin sends it from here.
					 *
					 * @param array  $coupon {code, amount}.
					 * @param string $email  Lowercased captured email.
					 * @param int    $id     Cart-abandonment entry id.
					 */
					do_action( 'brikpanel_cartab_popup_coupon_deferred', $coupon, strtolower( $email ), (int) $id );
				} else {
					$response['coupon']   = $coupon['code'];
					$response['discount'] = $coupon['amount'];
				}
			}
		}

		wp_send_json_success( $response );
	}

	/**
	 * Store the captured email on the WC session customer (guests only).
	 * The signup coupon is email-restricted and WooCommerce 8.6+ validates
	 * that restriction at APPLY time against the session customer — without
	 * a known email, a guest applying their own coupon at the cart gets
	 * "please enter a valid email at checkout", which reads as "invalid".
	 * As a bonus the checkout email field comes prefilled.
	 *
	 * @param string $email Validated, lowercased email.
	 */
	private static function remember_customer_email( $email ) {
		if ( is_user_logged_in() || ! function_exists( 'WC' ) || ! WC()->customer ) {
			return;
		}
		try {
			// A guest browsing content pages usually has no WC session cookie
			// yet — without one, the customer data saved below evaporates at
			// the end of this AJAX request. Force the cookie first.
			if ( WC()->session && is_callable( [ WC()->session, 'set_customer_session_cookie' ] ) && ! headers_sent() ) {
				WC()->session->set_customer_session_cookie( true );
			}
			if ( strtolower( (string) WC()->customer->get_billing_email() ) !== $email ) {
				WC()->customer->set_billing_email( $email );
				WC()->customer->save();
			}
		} catch ( Throwable $e ) {
			// Best-effort session write; the coupon still works once the
			// customer types their email at checkout.
			unset( $e );
		}
	}

	/**
	 * Return (or lazily create) the signup coupon for an email: percent
	 * discount at the configured rate, single use, restricted to that email,
	 * valid for 30 days. One live coupon per email — a fresh one is only
	 * generated when the previous one was used or expired.
	 *
	 * @param string $email Validated, lowercased email.
	 * @return array{code:string,amount:int}|null
	 */
	private static function get_or_create_popup_coupon( $email ) {
		$discount = self::popup_discount();
		if ( $discount <= 0 || ! class_exists( 'WC_Coupon' ) ) {
			return null;
		}

		// Reuse a still-valid coupon issued earlier to this email.
		$existing_ids = get_posts( [
			'post_type'   => 'shop_coupon',
			'post_status' => 'publish',
			'numberposts' => 3,
			'orderby'     => 'ID',
			'order'       => 'DESC',
			'fields'      => 'ids',
			'meta_key'    => '_brikpanel_cartab_email', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'meta_value'  => $email, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		] );
		foreach ( $existing_ids as $coupon_id ) {
			$coupon = new WC_Coupon( $coupon_id );
			if ( ! $coupon->get_id() ) {
				continue;
			}
			$expires = $coupon->get_date_expires();
			$usable  = $coupon->get_usage_count() < max( 1, (int) $coupon->get_usage_limit() )
				&& ( ! $expires || $expires->getTimestamp() > time() );
			if ( $usable ) {
				return [ 'code' => $coupon->get_code(), 'amount' => (int) $coupon->get_amount() ];
			}
		}

		// Generate a unique, readable code.
		$code = '';
		for ( $i = 0; $i < 5; $i++ ) {
			$candidate = 'BRIK-' . strtoupper( wp_generate_password( 6, false, false ) );
			if ( ! wc_get_coupon_id_by_code( $candidate ) ) {
				$code = $candidate;
				break;
			}
		}
		if ( $code === '' ) {
			return null;
		}

		try {
			$coupon = new WC_Coupon();
			$coupon->set_code( $code );
			$coupon->set_discount_type( 'percent' );
			$coupon->set_amount( $discount );
			$coupon->set_usage_limit( 1 );
			$coupon->set_usage_limit_per_user( 1 );
			$coupon->set_email_restrictions( [ $email ] );
			$coupon->set_date_expires( time() + 30 * DAY_IN_SECONDS );
			$coupon->set_description( __( 'Signup popup coupon (BrikPanel cart abandonment)', 'brikpanel' ) );
			$coupon->save();
			update_post_meta( $coupon->get_id(), '_brikpanel_cartab_email', $email );
		} catch ( Throwable $e ) {
			return null;
		}

		return [ 'code' => $code, 'amount' => $discount ];
	}

	/**
	 * Insert or refresh the (visitor, email) row. The cart snapshot is only
	 * overwritten when the current session actually has items, so a popup
	 * signup on a blog page never wipes a snapshot captured at checkout.
	 *
	 * @param string $visitor Browser id ('' when cookieless).
	 * @param string $email   Validated, lowercased email.
	 * @param string $source  checkout|popup|account.
	 * @param array  $extra   Optional first_name/last_name/phone.
	 * @return int Row id.
	 */
	private function upsert( $visitor, $email, $source, array $extra = [] ) {
		global $wpdb;
		$table    = self::table();
		$snapshot = self::cart_snapshot();
		$user_id  = ( get_current_user_id() && ! self::is_staff() ) ? get_current_user_id() : 0;
		$now      = current_time( 'mysql', true );

		$update = function () use ( $wpdb, $table, $visitor, $email, $snapshot, $extra, $user_id, $now ) {
			$existing = $wpdb->get_row( $wpdb->prepare(
				"SELECT id FROM {$table}
				 WHERE visitor_id = %s AND email = %s AND status IN ('active','abandoned')
				 ORDER BY id DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$visitor,
				$email
			) );
			if ( ! $existing ) {
				return 0;
			}
			$data = [
				'status'     => 'active',
				'updated_at' => $now,
			];
			if ( $user_id ) {
				$data['user_id'] = $user_id;
			}
			foreach ( [ 'first_name', 'last_name', 'phone' ] as $field ) {
				if ( ! empty( $extra[ $field ] ) ) {
					$data[ $field ] = $extra[ $field ];
				}
			}
			// Only overwrite the snapshot when this session actually has items,
			// so a popup signup on a blog page never wipes a checkout snapshot.
			if ( $snapshot['count'] > 0 ) {
				$data['cart_items'] = wp_json_encode( $snapshot['items'] );
				$data['item_count'] = $snapshot['count'];
				$data['cart_total'] = $snapshot['total'];
				$data['currency']   = $snapshot['currency'];
			}
			$wpdb->update( $table, $data, [ 'id' => (int) $existing->id ] );
			return (int) $existing->id;
		};

		$id = $update();
		if ( $id ) {
			return $id;
		}

		// Atomic insert-if-absent: two near-simultaneous captures from the
		// same browser (e.g. popup submit racing the checkout poller) must
		// not create two rows, so the existence check runs inside the INSERT.
		$inserted = $wpdb->query( $wpdb->prepare(
			"INSERT INTO {$table}
				(visitor_id, email, first_name, last_name, phone, user_id, source, status,
				 cart_items, item_count, cart_total, currency, created_at, updated_at)
			 SELECT %s, %s, %s, %s, %s, %d, %s, 'active', %s, %d, %f, %s, %s, %s
			 FROM DUAL
			 WHERE NOT EXISTS (
				SELECT 1 FROM {$table}
				WHERE visitor_id = %s AND email = %s AND status IN ('active','abandoned')
			 )", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$visitor,
			$email,
			isset( $extra['first_name'] ) ? $extra['first_name'] : '',
			isset( $extra['last_name'] ) ? $extra['last_name'] : '',
			isset( $extra['phone'] ) ? $extra['phone'] : '',
			$user_id,
			$source,
			wp_json_encode( $snapshot['items'] ),
			$snapshot['count'],
			$snapshot['total'],
			$snapshot['currency'],
			$now,
			$now,
			$visitor,
			$email
		) );

		if ( $inserted ) {
			$id = (int) $wpdb->insert_id;

			/**
			 * Fires when a NEW email lands in the abandoned-cart list.
			 *
			 * @param array $entry Formatted row (see brikpanel_cartab_get_entries()).
			 */
			do_action( 'brikpanel_cartab_email_captured', self::get_entry( $id ) );

			return $id;
		}

		// Lost the race — another request inserted the row a moment ago.
		return $update();
	}

	// =========================================================================
	// Server-side cart mirror
	// =========================================================================

	/**
	 * woocommerce_cart_updated can fire several times per request — including
	 * once on the initial (still empty) session load, BEFORE add-to-cart has
	 * been processed. So the hook itself only schedules a single mirror pass
	 * on shutdown, where the cart reflects every mutation of the request.
	 */
	public function on_cart_updated() {
		static $registered = false;
		if ( $registered || ( is_admin() && ! wp_doing_ajax() ) ) {
			return;
		}
		if ( ! self::is_enabled() || self::is_staff() ) {
			return;
		}
		if ( function_exists( '_brikpanel_is_bot_ua' ) && _brikpanel_is_bot_ua() ) {
			return;
		}
		$registered = true;
		// Priority 5: before WC's own session save (shutdown @20), so the
		// change-detection hash written below still gets persisted.
		add_action( 'shutdown', [ $this, 'mirror_cart' ], 5 );
	}

	/**
	 * Keep cart snapshots fresh for every known email attached to this
	 * browser/user, and auto-capture logged-in customers who never type
	 * anything (their email is already on the account). Skips silently when
	 * the cart is unchanged since the last write (hash stored in the WC
	 * session), so browsing pages costs nothing.
	 */
	public function mirror_cart() {
		$visitor    = self::existing_visitor_id();
		$user_email = is_user_logged_in() ? strtolower( (string) wp_get_current_user()->user_email ) : '';
		if ( $visitor === '' && $user_email === '' ) {
			return;
		}
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return;
		}

		$snapshot = self::cart_snapshot();
		$hash     = md5( wp_json_encode( $snapshot['items'] ) . '|' . $snapshot['total'] );
		$session  = WC()->session;
		if ( $session && $session->get( 'brikpanel_cartab_hash' ) === $hash ) {
			return;
		}

		global $wpdb;
		$table = self::table();

		// Two single-column indexed lookups merged in PHP (an OR across two
		// columns would defeat both indexes).
		$ids = [];
		if ( $visitor !== '' ) {
			$ids = $wpdb->get_col( $wpdb->prepare(
				"SELECT id FROM {$table} WHERE visitor_id = %s AND status IN ('active','abandoned')", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$visitor
			) );
		}
		if ( $user_email !== '' ) {
			$ids = array_merge( $ids, $wpdb->get_col( $wpdb->prepare(
				"SELECT id FROM {$table} WHERE email = %s AND status IN ('active','abandoned')", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$user_email
			) ) );
		}
		$ids = array_unique( array_map( 'intval', $ids ) );

		if ( $ids ) {
			// A returning visitor resets the abandonment clock: back to active.
			$in = implode( ',', $ids );
			$wpdb->query( $wpdb->prepare(
				"UPDATE {$table}
				 SET cart_items = %s, item_count = %d, cart_total = %f, currency = %s,
				     status = 'active', updated_at = %s
				 WHERE id IN ({$in})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				wp_json_encode( $snapshot['items'] ),
				$snapshot['count'],
				$snapshot['total'],
				$snapshot['currency'],
				current_time( 'mysql', true )
			) );
		} elseif ( $user_email !== '' && $snapshot['count'] > 0 && is_email( $user_email ) ) {
			// No row yet — a logged-in customer with a real cart is capturable
			// immediately (source: account). Guests without a captured email
			// are intentionally NOT stored: no email, nothing to follow up.
			$user = wp_get_current_user();
			$this->upsert( $visitor !== '' ? $visitor : self::visitor_id(), $user_email, 'account', [
				'first_name' => (string) $user->first_name,
				'last_name'  => (string) $user->last_name,
			] );
		}

		if ( $session ) {
			$session->set( 'brikpanel_cartab_hash', $hash );
		}
	}

	// =========================================================================
	// Recovery
	// =========================================================================

	public function on_checkout_processed( $order_id, $posted_data, $order ) {
		$this->mark_recovered( $order );
	}

	public function on_store_api_processed( $order ) {
		$this->mark_recovered( $order );
	}

	/**
	 * Safety net for orders created outside the two checkout hooks. Skips
	 * transitions into non-order states (drafts, failed, cancelled) so a
	 * failed payment attempt does not count as a recovery.
	 */
	public function on_order_status_changed( $order_id, $from, $to, $order ) {
		if ( in_array( $to, [ 'checkout-draft', 'failed', 'cancelled', 'trash' ], true ) ) {
			return;
		}
		static $seen = [];
		if ( isset( $seen[ $order_id ] ) ) {
			return;
		}
		$seen[ $order_id ] = true;
		$this->mark_recovered( $order );
	}

	/**
	 * Flip every matching non-recovered row to recovered. Matches by billing
	 * email OR (on front-end checkouts) the browser id cookie — the cookie
	 * path is what links a popup signup with email A to an order placed with
	 * email B in the same browser.
	 */
	private function mark_recovered( $order ) {
		if ( ! $order instanceof WC_Order ) {
			$order = wc_get_order( $order );
		}
		if ( ! $order ) {
			return;
		}

		$email   = strtolower( (string) $order->get_billing_email() );
		$visitor = self::existing_visitor_id();
		if ( $email === '' && $visitor === '' ) {
			return;
		}

		global $wpdb;
		$table = self::table();

		// Two single-column indexed lookups merged in PHP (an OR across two
		// columns would defeat both indexes).
		$rows = [];
		if ( $email !== '' ) {
			$rows = $wpdb->get_col( $wpdb->prepare(
				"SELECT id FROM {$table} WHERE email = %s AND status IN ('active','abandoned')", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$email
			) );
		}
		if ( $visitor !== '' ) {
			$rows = array_merge( $rows, $wpdb->get_col( $wpdb->prepare(
				"SELECT id FROM {$table} WHERE visitor_id = %s AND status IN ('active','abandoned')", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$visitor
			) ) );
		}
		$rows = array_unique( array_map( 'intval', $rows ) );
		if ( ! $rows ) {
			return;
		}

		$ids = implode( ',', $rows );
		$wpdb->query( $wpdb->prepare(
			"UPDATE {$table}
			 SET status = 'recovered', order_id = %d, recovered_at = %s, updated_at = %s
			 WHERE id IN ({$ids})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$order->get_id(),
			current_time( 'mysql', true ),
			current_time( 'mysql', true )
		) );

		foreach ( $rows as $row_id ) {
			/**
			 * Fires when a captured cart converts into an order.
			 *
			 * @param array $entry    Formatted row (status already 'recovered').
			 * @param int   $order_id The recovering order id.
			 */
			do_action( 'brikpanel_cart_recovered', self::get_entry( (int) $row_id ), $order->get_id() );
		}
	}

	// =========================================================================
	// Abandonment sweep (cron + lazy)
	// =========================================================================

	/**
	 * Flip stale active rows (with items, no activity for the configured
	 * number of minutes) to abandoned and fire brikpanel_cart_abandoned for
	 * each. Idempotent; cheap when nothing qualifies (one indexed SELECT).
	 *
	 * @param int $limit Max rows per sweep.
	 * @return int Rows flipped.
	 */
	public static function flip_abandoned( $limit = 500 ) {
		if ( ! self::is_enabled() ) {
			return 0;
		}
		global $wpdb;
		$table   = self::table();
		$minutes = max( 5, (int) get_option( 'brikpanel_cartab_abandon_minutes', 60 ) );
		$cutoff  = gmdate( 'Y-m-d H:i:s', time() - $minutes * MINUTE_IN_SECONDS );

		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT id FROM {$table}
			 WHERE status = 'active' AND item_count > 0 AND updated_at < %s
			 ORDER BY updated_at ASC LIMIT %d",
			$cutoff,
			max( 1, (int) $limit )
		) );
		if ( ! $ids ) {
			return 0;
		}

		$in = implode( ',', array_map( 'intval', $ids ) );
		$wpdb->query( $wpdb->prepare(
			"UPDATE {$table} SET status = 'abandoned', abandoned_at = %s WHERE id IN ({$in}) AND status = 'active'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			current_time( 'mysql', true )
		) );

		foreach ( $ids as $row_id ) {
			/**
			 * Fires when a cart with a known email is marked abandoned.
			 * BrikMentor subscribes here to queue follow-up emails.
			 *
			 * @param array $entry Formatted row (status 'abandoned').
			 */
			do_action( 'brikpanel_cart_abandoned', self::get_entry( (int) $row_id ) );
		}

		return count( $ids );
	}

	/** Action Scheduler handler wrapper (see cron registration below). */
	public static function cron_flip() {
		$flipped = self::flip_abandoned();
		return [ 'flipped' => $flipped ];
	}

	// =========================================================================
	// Public read API (used by exports, list AJAX, and BrikMentor)
	// =========================================================================

	/** Fetch one row formatted for the public API. */
	public static function get_entry( $id ) {
		global $wpdb;
		$table = self::table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ? self::format_row( $row ) : null;
	}

	/**
	 * Query captured entries.
	 *
	 * @param array $args {
	 *     @type string $status  active|abandoned|recovered ('' = all).
	 *     @type string $source  checkout|popup|account ('' = all).
	 *     @type string $search  Substring match on email / first / last name.
	 *     @type string $from    Y-m-d creation-date lower bound.
	 *     @type string $to      Y-m-d creation-date upper bound.
	 *     @type string $since   Y-m-d H:i:s updated_at lower bound (delta reads).
	 *     @type int    $limit   Default 25, max 500.
	 *     @type int    $offset  Default 0.
	 *     @type bool   $count   Return ['total' =>, 'rows' =>] instead of rows only.
	 * }
	 * @return array
	 */
	public static function query_entries( array $args = [] ) {
		global $wpdb;
		$table = self::table();

		$where  = [ '1=1' ];
		$params = [];

		$statuses = self::status_labels();
		if ( ! empty( $args['status'] ) && isset( $statuses[ $args['status'] ] ) ) {
			$where[]  = 'status = %s';
			$params[] = $args['status'];
		}
		$sources = self::source_labels();
		if ( ! empty( $args['source'] ) && isset( $sources[ $args['source'] ] ) ) {
			$where[]  = 'source = %s';
			$params[] = $args['source'];
		}
		if ( ! empty( $args['search'] ) ) {
			$like     = '%' . $wpdb->esc_like( (string) $args['search'] ) . '%';
			$where[]  = '(email LIKE %s OR first_name LIKE %s OR last_name LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}
		if ( ! empty( $args['from'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $args['from'] ) ) {
			$where[]  = 'created_at >= %s';
			$params[] = $args['from'] . ' 00:00:00';
		}
		if ( ! empty( $args['to'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $args['to'] ) ) {
			$where[]  = 'created_at <= %s';
			$params[] = $args['to'] . ' 23:59:59';
		}
		if ( ! empty( $args['since'] ) ) {
			$where[]  = 'updated_at >= %s';
			$params[] = (string) $args['since'];
		}

		$where_sql = implode( ' AND ', $where );
		$limit     = isset( $args['limit'] ) ? min( 500, max( 1, (int) $args['limit'] ) ) : 25;
		$offset    = isset( $args['offset'] ) ? max( 0, (int) $args['offset'] ) : 0;

		$sql = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY updated_at DESC, id DESC LIMIT %d OFFSET %d";
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $params, [ $limit, $offset ] ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = array_map( [ __CLASS__, 'format_row' ], $rows ?: [] );

		if ( empty( $args['count'] ) ) {
			return $rows;
		}
		$total_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
		$total     = $params
			? (int) $wpdb->get_var( $wpdb->prepare( $total_sql, $params ) ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			: (int) $wpdb->get_var( $total_sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return [ 'total' => $total, 'rows' => $rows ];
	}

	/** Normalize a DB row into the public array shape. */
	private static function format_row( $r ) {
		$items = json_decode( (string) $r->cart_items, true );
		return [
			'id'           => (int) $r->id,
			'email'        => (string) $r->email,
			'first_name'   => (string) $r->first_name,
			'last_name'    => (string) $r->last_name,
			'phone'        => (string) $r->phone,
			'user_id'      => (int) $r->user_id,
			'visitor_id'   => (string) $r->visitor_id,
			'source'       => (string) $r->source,
			'status'       => (string) $r->status,
			'cart_items'   => is_array( $items ) ? $items : [],
			'item_count'   => (int) $r->item_count,
			'cart_total'   => (float) $r->cart_total,
			'currency'     => (string) $r->currency,
			'order_id'     => (int) $r->order_id,
			'created_at'   => (string) $r->created_at,
			'updated_at'   => (string) $r->updated_at,
			'abandoned_at' => (string) ( $r->abandoned_at ?? '' ),
			'recovered_at' => (string) ( $r->recovered_at ?? '' ),
		];
	}

	/** "2 × Hoodie (Size: L, Color: Blue); 1 × Mug" — plain-text cart summary. */
	public static function items_summary( array $items ) {
		$parts = [];
		foreach ( $items as $item ) {
			$name = isset( $item['name'] ) ? (string) $item['name'] : '';
			$qty  = isset( $item['qty'] ) ? (float) $item['qty'] : 0;
			$line = rtrim( rtrim( number_format( $qty, 2, '.', '' ), '0' ), '.' ) . ' × ' . $name;
			if ( ! empty( $item['attributes'] ) && is_array( $item['attributes'] ) ) {
				$attrs = [];
				foreach ( $item['attributes'] as $label => $value ) {
					$attrs[] = $label . ': ' . $value;
				}
				$line .= ' (' . implode( ', ', $attrs ) . ')';
			}
			$parts[] = $line;
		}
		return implode( '; ', $parts );
	}

	/**
	 * Format a currency => amount map into a single plain-text money string.
	 * Multi-currency stores get every currency listed ("$1,240.00 + €310.00")
	 * because cart totals are stored as captured and must not be added across
	 * currencies. Entities are decoded so JS can drop the string into
	 * textContent without showing "&#36;".
	 *
	 * @param array<string,float> $totals
	 * @return string
	 */
	private static function format_amounts( array $totals ) {
		$totals = array_filter( $totals, static function ( $amount ) {
			return (float) $amount > 0;
		} );
		if ( ! $totals ) {
			return html_entity_decode(
				wp_strip_all_tags( wc_price( 0 ) ),
				ENT_QUOTES,
				'UTF-8'
			);
		}
		arsort( $totals );
		$parts = [];
		foreach ( $totals as $currency => $amount ) {
			$parts[] = html_entity_decode(
				wp_strip_all_tags( wc_price( (float) $amount, [ 'currency' => $currency ] ) ),
				ENT_QUOTES,
				'UTF-8'
			);
		}
		return implode( ' + ', $parts );
	}

	// =========================================================================
	// Admin page
	// =========================================================================

	public function register_page() {
		// Standalone top-level entry (mirrors Expenses / Customer Analytics).
		// The modern-nav layer swaps in its own icon and pins it into the
		// store cluster in the sidebar.
		$hook = add_menu_page(
			__( 'Abandoned Carts', 'brikpanel' ),
			__( 'Abandoned Carts', 'brikpanel' ),
			'manage_woocommerce',
			self::PAGE_SLUG,
			[ $this, 'render_page' ],
			'dashicons-cart',
			56.8
		);
		if ( $hook ) {
			add_action( 'load-' . $hook, function () {
				global $title;
				$title = __( 'Abandoned Carts', 'brikpanel' );
			} );
		}
	}

	private function check_auth() {
		check_ajax_referer( self::NONCE_ACTION, '_ajax_nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'brikpanel' ) ], 403 );
		}
	}

	public function render_page() {
		$nonce         = wp_create_nonce( self::NONCE_ACTION );
		$ajax_url      = admin_url( 'admin-ajax.php' );
		$popup_enabled = get_option( 'brikpanel_cartab_popup_enabled', 'no' ) === 'yes';
		$collection_on = self::is_enabled();
		$settings_url  = admin_url( 'admin.php?page=wc-settings&tab=brikpanel&section=cart-abandonment' );
		?>
		<div class="wrap brikpanel-cartab-wrap" id="brikpanel-cartab">
			<div class="brikpanel-cartab-header">
				<div class="brikpanel-cartab-header-left">
					<h1><?php esc_html_e( 'Abandoned Carts', 'brikpanel' ); ?></h1>
					<p class="brikpanel-cartab-subtitle"><?php esc_html_e( 'Emails collected from checkout and the signup popup, with a live snapshot of each cart.', 'brikpanel' ); ?></p>
				</div>
				<div class="brikpanel-cartab-header-right">
					<label class="brikpanel-cartab-toggle" title="<?php esc_attr_e( 'Show an email signup popup to store visitors.', 'brikpanel' ); ?>">
						<span class="brikpanel-cartab-toggle-label"><?php esc_html_e( 'Email popup', 'brikpanel' ); ?></span>
						<span class="brikpanel-cartab-switch">
							<input type="checkbox" id="brikpanel-cartab-popup-toggle" <?php checked( $popup_enabled ); ?> />
							<span class="brikpanel-cartab-slider"></span>
						</span>
					</label>
					<label class="brikpanel-cartab-discount" id="brikpanel-cartab-discount-wrap"
						title="<?php esc_attr_e( 'Discount percentage for the coupon each popup signup receives. 0 disables the coupon.', 'brikpanel' ); ?>"
						<?php echo $popup_enabled ? '' : 'style="display:none"'; ?>>
						<span class="brikpanel-cartab-toggle-label"><?php esc_html_e( 'Coupon', 'brikpanel' ); ?></span>
						<span class="brikpanel-cartab-discount-group">
							<input type="number" id="brikpanel-cartab-popup-discount" min="0" max="100" step="1"
								value="<?php echo esc_attr( self::popup_discount() ); ?>" />
							<span class="brikpanel-cartab-discount-suffix">%</span>
						</span>
					</label>
					<button type="button" class="brikpanel-cartab-btn brikpanel-cartab-btn-secondary" id="brikpanel-cartab-export-csv">
						<?php esc_html_e( 'Export CSV', 'brikpanel' ); ?>
					</button>
					<button type="button" class="brikpanel-cartab-btn brikpanel-cartab-btn-secondary" id="brikpanel-cartab-export-xlsx">
						<?php esc_html_e( 'Export Excel', 'brikpanel' ); ?>
					</button>
					<button type="button" class="brikpanel-cartab-btn brikpanel-cartab-btn-secondary" id="brikpanel-cartab-import"
						title="<?php esc_attr_e( 'Import leads from a CSV file. Columns: email (required), first name, last name, phone.', 'brikpanel' ); ?>">
						<?php esc_html_e( 'Import CSV', 'brikpanel' ); ?>
					</button>
					<input type="file" id="brikpanel-cartab-import-file" accept=".csv,text/csv" style="display:none" />
					<a class="brikpanel-cartab-btn brikpanel-cartab-btn-primary" href="<?php echo esc_url( $settings_url ); ?>">
						<?php esc_html_e( 'Settings', 'brikpanel' ); ?>
					</a>
				</div>
			</div>

			<?php if ( ! $collection_on ) : ?>
				<div class="brikpanel-cartab-card brikpanel-cartab-disabled-note">
					<?php esc_html_e( 'Email collection is currently turned off, so no new emails are being captured. You can re-enable it in the settings.', 'brikpanel' ); ?>
				</div>
			<?php endif; ?>

			<!-- Stats -->
			<div class="brikpanel-cartab-summary" id="brikpanel-cartab-summary">
				<div class="brikpanel-cartab-summary-card">
					<div class="brikpanel-cartab-summary-label"><?php esc_html_e( 'Emails collected', 'brikpanel' ); ?></div>
					<div class="brikpanel-cartab-summary-value" id="brikpanel-cartab-stat-total">—</div>
					<div class="brikpanel-cartab-summary-meta">
						<span class="brikpanel-cartab-summary-meta-label"><?php esc_html_e( 'Total cart value', 'brikpanel' ); ?></span>
						<span class="brikpanel-cartab-summary-meta-value" id="brikpanel-cartab-amount-total">—</span>
					</div>
				</div>
				<div class="brikpanel-cartab-summary-card">
					<div class="brikpanel-cartab-summary-label"><?php esc_html_e( 'Active carts', 'brikpanel' ); ?></div>
					<div class="brikpanel-cartab-summary-value" id="brikpanel-cartab-stat-active">—</div>
					<div class="brikpanel-cartab-summary-meta">
						<span class="brikpanel-cartab-summary-meta-label"><?php esc_html_e( 'Value in cart', 'brikpanel' ); ?></span>
						<span class="brikpanel-cartab-summary-meta-value" id="brikpanel-cartab-amount-active">—</span>
					</div>
				</div>
				<div class="brikpanel-cartab-summary-card">
					<div class="brikpanel-cartab-summary-label"><?php esc_html_e( 'Abandoned', 'brikpanel' ); ?></div>
					<div class="brikpanel-cartab-summary-value" id="brikpanel-cartab-stat-abandoned">—</div>
					<div class="brikpanel-cartab-summary-meta">
						<span class="brikpanel-cartab-summary-meta-label"><?php esc_html_e( 'Recoverable value', 'brikpanel' ); ?></span>
						<span class="brikpanel-cartab-summary-meta-value" id="brikpanel-cartab-amount-abandoned">—</span>
					</div>
				</div>
				<div class="brikpanel-cartab-summary-card">
					<div class="brikpanel-cartab-summary-label"><?php esc_html_e( 'Recovered', 'brikpanel' ); ?></div>
					<div class="brikpanel-cartab-summary-value" id="brikpanel-cartab-stat-recovered">—</div>
					<div class="brikpanel-cartab-summary-meta">
						<span class="brikpanel-cartab-summary-meta-label"><?php esc_html_e( 'Recovered value', 'brikpanel' ); ?></span>
						<span class="brikpanel-cartab-summary-meta-value" id="brikpanel-cartab-amount-recovered">—</span>
					</div>
				</div>
			</div>

			<!-- Filters -->
			<div class="brikpanel-cartab-card brikpanel-cartab-filters">
				<div class="brikpanel-cartab-filter-row">
					<div class="brikpanel-cartab-field brikpanel-cartab-field-grow">
						<label for="brikpanel-cartab-search"><?php esc_html_e( 'Search', 'brikpanel' ); ?></label>
						<input type="search" id="brikpanel-cartab-search" placeholder="<?php esc_attr_e( 'Email or name…', 'brikpanel' ); ?>" />
					</div>
					<div class="brikpanel-cartab-field">
						<label for="brikpanel-cartab-status"><?php esc_html_e( 'Status', 'brikpanel' ); ?></label>
						<select id="brikpanel-cartab-status">
							<option value=""><?php esc_html_e( 'All statuses', 'brikpanel' ); ?></option>
							<?php foreach ( self::status_labels() as $key => $label ) : ?>
								<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<div class="brikpanel-cartab-field">
						<label for="brikpanel-cartab-source"><?php esc_html_e( 'Source', 'brikpanel' ); ?></label>
						<select id="brikpanel-cartab-source">
							<option value=""><?php esc_html_e( 'All sources', 'brikpanel' ); ?></option>
							<?php foreach ( self::source_labels() as $key => $label ) : ?>
								<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<div class="brikpanel-cartab-field">
						<label for="brikpanel-cartab-from"><?php esc_html_e( 'From', 'brikpanel' ); ?></label>
						<input type="date" id="brikpanel-cartab-from" />
					</div>
					<div class="brikpanel-cartab-field">
						<label for="brikpanel-cartab-to"><?php esc_html_e( 'To', 'brikpanel' ); ?></label>
						<input type="date" id="brikpanel-cartab-to" />
					</div>
					<div class="brikpanel-cartab-filter-actions">
						<button type="button" class="brikpanel-cartab-btn brikpanel-cartab-btn-secondary" id="brikpanel-cartab-apply">
							<?php esc_html_e( 'Apply', 'brikpanel' ); ?>
						</button>
					</div>
				</div>
			</div>

			<!-- Table -->
			<div class="brikpanel-cartab-card brikpanel-cartab-table-card">
				<div class="brikpanel-cartab-table-wrap">
					<table class="brikpanel-cartab-table" id="brikpanel-cartab-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Email', 'brikpanel' ); ?></th>
								<th><?php esc_html_e( 'Name', 'brikpanel' ); ?></th>
								<th><?php esc_html_e( 'Cart', 'brikpanel' ); ?></th>
								<th><?php esc_html_e( 'Source', 'brikpanel' ); ?></th>
								<th><?php esc_html_e( 'Status', 'brikpanel' ); ?></th>
								<th><?php esc_html_e( 'Last activity', 'brikpanel' ); ?></th>
								<th class="brikpanel-cartab-actions-th"></th>
							</tr>
						</thead>
						<tbody id="brikpanel-cartab-tbody">
							<tr><td colspan="7" class="brikpanel-cartab-empty"><?php esc_html_e( 'Loading…', 'brikpanel' ); ?></td></tr>
						</tbody>
					</table>
				</div>
				<div class="brikpanel-cartab-pagination" id="brikpanel-cartab-pagination" style="display:none">
					<button type="button" class="brikpanel-cartab-btn brikpanel-cartab-btn-secondary" id="brikpanel-cartab-prev">&larr; <?php esc_html_e( 'Previous', 'brikpanel' ); ?></button>
					<span class="brikpanel-cartab-page-info" id="brikpanel-cartab-page-info">1 / 1</span>
					<button type="button" class="brikpanel-cartab-btn brikpanel-cartab-btn-secondary" id="brikpanel-cartab-next"><?php esc_html_e( 'Next', 'brikpanel' ); ?> &rarr;</button>
				</div>
			</div>
		</div>

		<script>
		window.brikpanelCartAbAdmin = {
			ajax_url: <?php echo wp_json_encode( esc_url_raw( $ajax_url ) ); ?>,
			nonce:    <?php echo wp_json_encode( $nonce ); ?>,
			statuses: <?php echo wp_json_encode( self::status_labels() ); ?>,
			sources:  <?php echo wp_json_encode( self::source_labels() ); ?>,
			i18n: {
				error:          <?php echo wp_json_encode( __( 'Something went wrong.', 'brikpanel' ) ); ?>,
				empty:          <?php echo wp_json_encode( __( 'No emails captured yet.', 'brikpanel' ) ); ?>,
				confirm_delete: <?php echo wp_json_encode( __( 'Delete this entry?', 'brikpanel' ) ); ?>,
				delete:         <?php echo wp_json_encode( __( 'Delete', 'brikpanel' ) ); ?>,
				details:        <?php echo wp_json_encode( __( 'Details', 'brikpanel' ) ); ?>,
				empty_cart:     <?php echo wp_json_encode( __( 'No cart items recorded.', 'brikpanel' ) ); ?>,
				order:          <?php echo wp_json_encode( __( 'Order', 'brikpanel' ) ); ?>,
				item:           <?php echo wp_json_encode( __( 'item', 'brikpanel' ) ); ?>,
				items:          <?php echo wp_json_encode( __( 'items', 'brikpanel' ) ); ?>,
				popup_on:       <?php echo wp_json_encode( __( 'Popup enabled.', 'brikpanel' ) ); ?>,
				popup_off:      <?php echo wp_json_encode( __( 'Popup disabled.', 'brikpanel' ) ); ?>,
				sku:            <?php echo wp_json_encode( __( 'SKU', 'brikpanel' ) ); ?>,
				importing:      <?php echo wp_json_encode( __( 'Importing…', 'brikpanel' ) ); ?>,
				import_label:   <?php echo wp_json_encode( __( 'Import CSV', 'brikpanel' ) ); ?>,
				/* translators: %s: number of imported emails */
				import_done:    <?php echo wp_json_encode( __( '%s emails imported.', 'brikpanel' ) ); ?>,
				/* translators: %s: number of skipped rows */
				import_dupes:   <?php echo wp_json_encode( __( '%s skipped (already in the list).', 'brikpanel' ) ); ?>,
				/* translators: %s: number of invalid rows */
				import_invalid: <?php echo wp_json_encode( __( '%s skipped (invalid email).', 'brikpanel' ) ); ?>,
				import_none:    <?php echo wp_json_encode( __( 'Nothing new to import.', 'brikpanel' ) ); ?>,
				import_truncated: <?php echo wp_json_encode( __( 'File was larger than the 20,000 row limit; the rest was skipped.', 'brikpanel' ) ); ?>,
			}
		};
		</script>
		<?php
	}

	// =========================================================================
	// AJAX: admin list / delete / popup toggle / export
	// =========================================================================

	public function ajax_list() {
		$this->check_auth();

		// Lazy sweep so statuses on screen are correct even if the scheduled
		// job has not run yet (fresh installs, Action Scheduler unavailable).
		self::flip_abandoned();

		$page     = isset( $_POST['page'] ) ? max( 1, (int) $_POST['page'] ) : 1;
		$per_page = 25;
		$args     = [
			'status' => isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : '',
			'source' => isset( $_POST['source'] ) ? sanitize_key( wp_unslash( $_POST['source'] ) ) : '',
			'search' => isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '',
			'from'   => isset( $_POST['from'] ) ? sanitize_text_field( wp_unslash( $_POST['from'] ) ) : '',
			'to'     => isset( $_POST['to'] ) ? sanitize_text_field( wp_unslash( $_POST['to'] ) ) : '',
			'limit'  => $per_page,
			'offset' => ( $page - 1 ) * $per_page,
			'count'  => true,
		];

		$result = self::query_entries( $args );

		$date_format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		$items       = [];
		foreach ( $result['rows'] as $row ) {
			$row['updated_h'] = $row['updated_at'] !== ''
				? wp_date( $date_format, strtotime( $row['updated_at'] . ' +00:00' ) )
				: '';
			$row['created_h'] = $row['created_at'] !== ''
				? wp_date( $date_format, strtotime( $row['created_at'] . ' +00:00' ) )
				: '';
			$row['total_h'] = html_entity_decode(
				wp_strip_all_tags( wc_price( $row['cart_total'], [ 'currency' => $row['currency'] ] ) ),
				ENT_QUOTES,
				'UTF-8'
			);
			// HPOS-aware edit link (legacy storage uses post.php).
			$row['order_url'] = '';
			if ( $row['order_id'] > 0 ) {
				$hpos = class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
					&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
				$row['order_url'] = $hpos
					? admin_url( 'admin.php?page=wc-orders&action=edit&id=' . $row['order_id'] )
					: admin_url( 'post.php?post=' . $row['order_id'] . '&action=edit' );
			}
			unset( $row['visitor_id'] ); // browser id is server-side detail; not useful in the UI
			$items[] = $row;
		}

		// Status breakdown + cart value per status for the stat cards
		// (unfiltered, whole table). Cart totals are stored in the currency
		// that was active when the cart was captured, so they are summed per
		// currency and rendered side by side instead of blindly added up.
		global $wpdb;
		$table   = self::table();
		$counts  = [ 'total' => 0, 'active' => 0, 'abandoned' => 0, 'recovered' => 0 ];
		$by_curr = [ 'total' => [], 'active' => [], 'abandoned' => [], 'recovered' => [] ];
		$rows    = $wpdb->get_results( "SELECT status, currency, COUNT(*) AS c, SUM(cart_total) AS amount FROM {$table} GROUP BY status, currency" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( $rows as $r ) {
			$status = (string) $r->status;
			if ( isset( $counts[ $status ] ) ) {
				$counts[ $status ] += (int) $r->c;
			}
			$counts['total'] += (int) $r->c;

			$currency = $r->currency ? (string) $r->currency : get_woocommerce_currency();
			$amount   = (float) $r->amount;
			foreach ( [ $status, 'total' ] as $bucket ) {
				if ( ! isset( $by_curr[ $bucket ] ) ) {
					continue;
				}
				if ( ! isset( $by_curr[ $bucket ][ $currency ] ) ) {
					$by_curr[ $bucket ][ $currency ] = 0.0;
				}
				$by_curr[ $bucket ][ $currency ] += $amount;
			}
		}

		$amounts = [];
		foreach ( $by_curr as $status => $totals ) {
			$amounts[ $status ] = self::format_amounts( $totals );
		}

		wp_send_json_success( [
			'items'   => $items,
			'total'   => (int) $result['total'],
			'page'    => $page,
			'pages'   => max( 1, (int) ceil( $result['total'] / $per_page ) ),
			'counts'  => $counts,
			'amounts' => $amounts,
		] );
	}

	public function ajax_delete() {
		$this->check_auth();
		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		if ( $id <= 0 ) {
			wp_send_json_error( [ 'message' => __( 'Invalid entry.', 'brikpanel' ) ], 400 );
		}
		global $wpdb;
		$wpdb->delete( self::table(), [ 'id' => $id ], [ '%d' ] );
		wp_send_json_success();
	}

	public function ajax_popup_toggle() {
		$this->check_auth();
		$enable = isset( $_POST['enable'] ) && '1' === $_POST['enable'];
		update_option( 'brikpanel_cartab_popup_enabled', $enable ? 'yes' : 'no' );
		wp_send_json_success( [ 'enabled' => $enable ] );
	}

	public function ajax_popup_discount() {
		$this->check_auth();
		$discount = isset( $_POST['discount'] ) ? max( 0, min( 100, (int) $_POST['discount'] ) ) : 10;
		update_option( 'brikpanel_cartab_popup_discount', (string) $discount );
		wp_send_json_success( [ 'discount' => $discount ] );
	}

	/**
	 * Streamed export (GET link with nonce). format=csv streams line by line;
	 * format=xlsx builds a workbook via Brikpanel_XLSX_Writer.
	 */
	public function ajax_export() {
		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			wp_die( esc_html__( 'Link expired. Refresh the page and try again.', 'brikpanel' ), '', [ 'response' => 403 ] );
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'brikpanel' ), '', [ 'response' => 403 ] );
		}

		self::flip_abandoned();

		$format = isset( $_GET['format'] ) && 'xlsx' === $_GET['format'] ? 'xlsx' : 'csv';
		$args   = [
			'status' => isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '',
			'source' => isset( $_GET['source'] ) ? sanitize_key( wp_unslash( $_GET['source'] ) ) : '',
			'search' => isset( $_GET['search'] ) ? sanitize_text_field( wp_unslash( $_GET['search'] ) ) : '',
			'from'   => isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : '',
			'to'     => isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( $_GET['to'] ) ) : '',
		];

		$statuses = self::status_labels();
		$sources  = self::source_labels();
		$header   = [
			__( 'Email', 'brikpanel' ),
			__( 'First name', 'brikpanel' ),
			__( 'Last name', 'brikpanel' ),
			__( 'Phone', 'brikpanel' ),
			__( 'Source', 'brikpanel' ),
			__( 'Status', 'brikpanel' ),
			__( 'Items', 'brikpanel' ),
			__( 'Cart total', 'brikpanel' ),
			__( 'Currency', 'brikpanel' ),
			__( 'Cart contents', 'brikpanel' ),
			__( 'Created (UTC)', 'brikpanel' ),
			__( 'Last activity (UTC)', 'brikpanel' ),
			__( 'Abandoned at (UTC)', 'brikpanel' ),
			__( 'Recovered at (UTC)', 'brikpanel' ),
			__( 'Order ID', 'brikpanel' ),
		];

		$to_line = static function ( array $row ) use ( $statuses, $sources ) {
			return [
				$row['email'],
				$row['first_name'],
				$row['last_name'],
				$row['phone'],
				$sources[ $row['source'] ] ?? $row['source'],
				$statuses[ $row['status'] ] ?? $row['status'],
				$row['item_count'],
				$row['cart_total'],
				$row['currency'],
				self::items_summary( $row['cart_items'] ),
				$row['created_at'],
				$row['updated_at'],
				$row['abandoned_at'],
				$row['recovered_at'],
				$row['order_id'] > 0 ? $row['order_id'] : '',
			];
		};

		$filename = 'abandoned-carts-' . gmdate( 'Y-m-d' );

		if ( 'csv' === $format ) {
			nocache_headers();
			header( 'Content-Type: text/csv; charset=UTF-8' );
			header( 'Content-Disposition: attachment; filename="' . $filename . '.csv"' );
			$out = fopen( 'php://output', 'w' );
			fwrite( $out, "\xEF\xBB\xBF" ); // UTF-8 BOM so Excel opens Turkish/accented characters correctly
			fputcsv( $out, $header );
			$offset = 0;
			do {
				$rows = self::query_entries( array_merge( $args, [ 'limit' => 500, 'offset' => $offset ] ) );
				foreach ( $rows as $row ) {
					fputcsv( $out, $to_line( $row ) );
				}
				$offset += 500;
			} while ( count( $rows ) === 500 );
			fclose( $out );
			exit;
		}

		// XLSX
		require_once BRIKPANEL_PATH . 'includes/brikpanel-xlsx-writer.php';
		if ( ! class_exists( 'Brikpanel_XLSX_Writer' ) ) {
			wp_die( esc_html__( 'Export engine unavailable.', 'brikpanel' ) );
		}
		$H     = Brikpanel_XLSX_Writer::S_HEADER;
		$sheet = [ array_map( static function ( $h ) use ( $H ) { return [ $h, $H ]; }, $header ) ];

		$offset = 0;
		do {
			$rows = self::query_entries( array_merge( $args, [ 'limit' => 500, 'offset' => $offset ] ) );
			foreach ( $rows as $row ) {
				$sheet[] = $to_line( $row );
			}
			$offset += 500;
		} while ( count( $rows ) === 500 );

		$writer = new Brikpanel_XLSX_Writer();
		$writer->add_sheet(
			__( 'Abandoned carts', 'brikpanel' ),
			$sheet,
			[ 1 => 30, 2 => 14, 3 => 14, 4 => 16, 5 => 10, 6 => 11, 7 => 7, 8 => 11, 9 => 9, 10 => 50, 11 => 19, 12 => 19, 13 => 19, 14 => 19, 15 => 9 ],
			true
		);
		$xlsx = $writer->build();
		if ( false === $xlsx ) {
			wp_die( esc_html__( 'Could not generate the Excel file.', 'brikpanel' ) );
		}
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		nocache_headers();
		header( 'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '.xlsx"' );
		header( 'Content-Length: ' . strlen( $xlsx ) );
		echo $xlsx; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binary file body
		exit;
	}

	/**
	 * CSV lead import. Accepts a small uploaded CSV (comma or semicolon
	 * separated) and inserts each valid, not-yet-known email as a new entry
	 * with source "import". Column mapping is resolved from the header row
	 * (email / first name / last name / phone, common variants included);
	 * a headerless file whose first cell is an email address works too.
	 */
	public function ajax_import() {
		$this->check_auth();

		if ( empty( $_FILES['file'] ) || ! is_uploaded_file( $_FILES['file']['tmp_name'] ?? '' ) || ! empty( $_FILES['file']['error'] ) ) {
			wp_send_json_error( [ 'message' => __( 'No file received. Please choose a CSV file.', 'brikpanel' ) ], 400 );
		}
		if ( (int) $_FILES['file']['size'] > 5 * MB_IN_BYTES ) {
			wp_send_json_error( [ 'message' => __( 'File is too large. The maximum size is 5 MB.', 'brikpanel' ) ], 400 );
		}

		$handle = fopen( $_FILES['file']['tmp_name'], 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $handle ) {
			wp_send_json_error( [ 'message' => __( 'The file could not be read.', 'brikpanel' ) ], 400 );
		}

		// Delimiter sniff on the first line (Excel exports “;” in many locales).
		$first_line = (string) fgets( $handle );
		$first_line = preg_replace( '/^\xEF\xBB\xBF/', '', $first_line ); // strip UTF-8 BOM
		$delimiter  = substr_count( $first_line, ';' ) > substr_count( $first_line, ',' ) ? ';' : ',';
		$first_row  = str_getcsv( $first_line, $delimiter, '"', '\\' );

		// Column mapping from the header row.
		$map     = [ 'email' => null, 'first_name' => null, 'last_name' => null, 'phone' => null ];
		$aliases = [
			'email'      => [ 'email', 'e-mail', 'mail', 'email address', 'e-mail address', 'eposta', 'e-posta' ],
			'first_name' => [ 'first name', 'first_name', 'firstname', 'name', 'ad', 'isim' ],
			'last_name'  => [ 'last name', 'last_name', 'lastname', 'surname', 'soyad', 'soyisim' ],
			'phone'      => [ 'phone', 'telephone', 'phone number', 'tel', 'telefon' ],
		];
		$has_header = false;
		foreach ( $first_row as $idx => $cell ) {
			$cell = strtolower( trim( (string) $cell ) );
			foreach ( $aliases as $field => $names ) {
				if ( null === $map[ $field ] && in_array( $cell, $names, true ) ) {
					$map[ $field ] = $idx;
					$has_header    = true;
				}
			}
		}
		if ( ! $has_header ) {
			// Headerless file: assume the conventional column order
			// (email, first name, last name, phone); row 1 is data.
			$map = [ 'email' => 0, 'first_name' => 1, 'last_name' => 2, 'phone' => 3 ];
		}
		if ( null === $map['email'] ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			wp_send_json_error( [ 'message' => __( 'No email column found. Add an "Email" header or put emails in the first column.', 'brikpanel' ) ], 400 );
		}

		// Parse + validate + dedupe inside the file.
		$rows       = [];
		$invalid    = 0;
		$file_dupes = 0;
		$max_rows   = 20000;
		$truncated  = false;
		$add_row    = static function ( $line ) use ( &$rows, &$invalid, &$file_dupes, $map ) {
			$email = strtolower( sanitize_email( trim( (string) ( $line[ $map['email'] ] ?? '' ) ) ) );
			if ( $email === '' || strlen( $email ) > 190 || ! is_email( $email ) ) {
				$invalid++;
				return;
			}
			if ( isset( $rows[ $email ] ) ) {
				$file_dupes++;
				return; // duplicate inside the file — first occurrence wins
			}
			$get = static function ( $field ) use ( $line, $map ) {
				if ( null === $map[ $field ] || ! isset( $line[ $map[ $field ] ] ) ) {
					return '';
				}
				return sanitize_text_field( (string) $line[ $map[ $field ] ] );
			};
			$rows[ $email ] = [
				'first_name' => substr( $get( 'first_name' ), 0, 100 ),
				'last_name'  => substr( $get( 'last_name' ), 0, 100 ),
				'phone'      => substr( $get( 'phone' ), 0, 40 ),
			];
		};
		if ( ! $has_header ) {
			$add_row( $first_row );
		}
		while ( ( $line = fgetcsv( $handle, 0, $delimiter, '"', '\\' ) ) !== false ) {
			if ( null === $line || ( count( $line ) === 1 && trim( (string) $line[0] ) === '' ) ) {
				continue; // blank line
			}
			if ( count( $rows ) >= $max_rows ) {
				$truncated = true;
				break;
			}
			$add_row( $line );
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		if ( ! $rows ) {
			wp_send_json_error( [ 'message' => __( 'No valid email addresses found in the file.', 'brikpanel' ) ], 400 );
		}

		global $wpdb;
		$table = self::table();

		// Drop emails already in the list (chunked IN() on the email index).
		$emails = array_keys( $rows );
		foreach ( array_chunk( $emails, 500 ) as $chunk ) {
			$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
			$existing     = $wpdb->get_col( $wpdb->prepare(
				"SELECT email FROM {$table} WHERE email IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$chunk
			) );
			foreach ( $existing as $known ) {
				unset( $rows[ $known ] );
			}
		}
		$duplicates = $file_dupes + ( count( $emails ) - count( $rows ) );

		// Batched inserts.
		$now      = current_time( 'mysql', true );
		$imported = 0;
		foreach ( array_chunk( $rows, 200, true ) as $chunk ) {
			$values = [];
			$params = [];
			foreach ( $chunk as $email => $extra ) {
				$values[] = '(%s, %s, %s, %s, %s, 0, %s, %s, %s, 0, 0, %s, %s, %s)';
				array_push(
					$params,
					'', // visitor_id — imported leads have no browser yet
					$email,
					$extra['first_name'],
					$extra['last_name'],
					$extra['phone'],
					'import',
					'active',
					'[]',
					'',
					$now,
					$now
				);
			}
			$imported += (int) $wpdb->query( $wpdb->prepare(
				"INSERT INTO {$table}
					(visitor_id, email, first_name, last_name, phone, user_id, source, status,
					 cart_items, item_count, cart_total, currency, created_at, updated_at)
				 VALUES " . implode( ',', $values ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
				$params
			) );
		}

		/**
		 * Fires once after a CSV lead import (NOT per row — high-volume safe).
		 *
		 * @param int $imported Number of new rows inserted.
		 */
		do_action( 'brikpanel_cartab_emails_imported', $imported );

		wp_send_json_success( [
			'imported'   => $imported,
			'duplicates' => $duplicates,
			'invalid'    => $invalid,
			'truncated'  => $truncated ? 1 : 0,
		] );
	}

	// =========================================================================
	// Settings tab wiring
	// =========================================================================

	public function settings_section( $sections ) {
		$sections['cart-abandonment'] = __( 'Cart abandonment', 'brikpanel' );
		return $sections;
	}

	public function settings_group( $groups ) {
		if ( isset( $groups['store']['sections'] ) && is_array( $groups['store']['sections'] ) ) {
			$pos = array_search( 'analytics', $groups['store']['sections'], true );
			if ( false === $pos ) {
				$groups['store']['sections'][] = 'cart-abandonment';
			} else {
				array_splice( $groups['store']['sections'], $pos + 1, 0, 'cart-abandonment' );
			}
		}
		return $groups;
	}

	public function settings_title_map( $map ) {
		$map['brk_cartab_title'] = 'cart-abandonment';
		return $map;
	}

	public function settings_icon( $paths ) {
		// i18n-ignore: inline SVG path data, not user-facing text
		$paths['cart-abandonment'] = '<circle cx="8" cy="21" r="1"/><circle cx="19" cy="21" r="1"/><path d="M2.05 2.05h2l2.66 12.42a2 2 0 0 0 2 1.58h9.78a2 2 0 0 0 1.95-1.57l1.65-7.43H5.12"/><path d="m16.5 3.5 4 4"/><path d="m20.5 3.5-4 4"/>';
		return $paths;
	}

	public function settings_fields( $fields ) {
		$fields[] = [
			'title' => __( 'Cart abandonment', 'brikpanel' ),
			'type'  => 'title',
			'id'    => 'brk_cartab_title',
			'desc'  => __( 'Collect customer emails from the checkout page and an optional signup popup, so abandoned carts can be followed up. Collected emails are listed on the Abandoned Carts page.', 'brikpanel' ),
		];
		$fields[] = [
			'title'   => __( 'Email collection', 'brikpanel' ),
			'desc'    => __( 'Capture emails typed at checkout (and from the popup) together with a cart snapshot', 'brikpanel' ),
			'id'      => 'brikpanel_cartab_enabled',
			'type'    => 'checkbox',
			'default' => 'yes',
		];
		$fields[] = [
			'title'             => __( 'Mark cart as abandoned after', 'brikpanel' ),
			'desc'              => __( 'minutes of inactivity', 'brikpanel' ),
			'desc_tip'          => __( 'A cart with a known email and no activity for this long is marked as abandoned.', 'brikpanel' ),
			'id'                => 'brikpanel_cartab_abandon_minutes',
			'type'              => 'number',
			'default'           => '60',
			'custom_attributes' => [ 'min' => 5, 'step' => 5 ],
			'css'               => 'width:90px;',
		];
		$fields[] = [
			'title'   => __( 'Email popup', 'brikpanel' ),
			'desc'    => __( 'Show a signup popup to store visitors (not shown on the checkout page)', 'brikpanel' ),
			'desc_tip' => __( 'Visitors who leave their email here are matched with their cart later, even if they never type an email at checkout. After closing, the popup stays available as a small floating tab.', 'brikpanel' ),
			'id'      => 'brikpanel_cartab_popup_enabled',
			'type'    => 'checkbox',
			'default' => 'no',
		];
		$fields[] = [
			'title'             => __( 'Popup discount', 'brikpanel' ),
			'desc'              => __( '% off coupon for popup signups', 'brikpanel' ),
			'desc_tip'          => __( 'Each signup gets a personal single-use percentage coupon restricted to their email, valid for 30 days. Set to 0 to collect emails without a coupon.', 'brikpanel' ),
			'id'                => 'brikpanel_cartab_popup_discount',
			'type'              => 'number',
			'default'           => '10',
			'custom_attributes' => [ 'min' => 0, 'max' => 100, 'step' => 1 ],
			'css'               => 'width:90px;',
		];
		$fields[] = [
			'title'    => __( 'Offer animation', 'brikpanel' ),
			'desc_tip' => __( 'Visual style of the animated discount badge at the top of the popup. Only shown while a popup discount is set.', 'brikpanel' ),
			'id'       => 'brikpanel_cartab_popup_style',
			'type'     => 'select',
			'default'  => 'envelope',
			'options'  => [
				'envelope' => __( 'Sealed envelope: the seal pops and the coupon rises', 'brikpanel' ),
				'pocket'   => __( 'Pocket card: coupon springs out of a pocket', 'brikpanel' ),
				'scratch'  => __( 'Scratch card: a coin scratches the foil away', 'brikpanel' ),
				'slot'     => __( 'Slot machine: reels spin and land on the discount', 'brikpanel' ),
				'assembly' => __( 'Magnetic assembly: pieces fly in and form the coupon', 'brikpanel' ),
				'classic'  => __( 'Classic ticket: floating coupon with a shine sweep', 'brikpanel' ),
			],
			'css'      => 'width:340px;',
		];
		$fields[] = [
			'title'       => __( 'Popup title', 'brikpanel' ),
			'id'          => 'brikpanel_cartab_popup_title',
			'type'        => 'text',
			'placeholder' => __( 'Sign up and save', 'brikpanel' ),
			'css'         => 'width:340px;',
		];
		$fields[] = [
			'title'       => __( 'Popup message', 'brikpanel' ),
			'id'          => 'brikpanel_cartab_popup_message',
			'type'        => 'textarea',
			'placeholder' => __( 'Subscribe to get special offers and updates.', 'brikpanel' ),
			'css'         => 'width:340px;height:60px;',
		];
		$fields[] = [
			'title'       => __( 'Popup button text', 'brikpanel' ),
			'id'          => 'brikpanel_cartab_popup_button',
			'type'        => 'text',
			'placeholder' => __( 'Subscribe', 'brikpanel' ),
			'css'         => 'width:340px;',
		];
		$fields[] = [
			'title'       => __( 'Email placeholder', 'brikpanel' ),
			'id'          => 'brikpanel_cartab_popup_placeholder',
			'type'        => 'text',
			'placeholder' => __( 'Enter your email', 'brikpanel' ),
			'css'         => 'width:340px;',
		];
		$fields[] = [
			'title'       => __( 'Success message', 'brikpanel' ),
			'id'          => 'brikpanel_cartab_popup_success',
			'type'        => 'text',
			'placeholder' => __( 'Thanks for subscribing!', 'brikpanel' ),
			'css'         => 'width:340px;',
		];
		$fields[] = [
			'title'       => __( 'Floating tab text', 'brikpanel' ),
			'desc_tip'    => __( 'Shown on the small tab that stays on the left edge after the popup is closed. Leave empty to use the discount-based default.', 'brikpanel' ),
			'id'          => 'brikpanel_cartab_popup_teaser',
			'type'        => 'text',
			/* translators: %d: discount percentage */
			'placeholder' => sprintf( __( 'Get %d%% off', 'brikpanel' ), 10 ),
			'css'         => 'width:340px;',
		];
		$fields[] = [
			'title'             => __( 'Popup delay', 'brikpanel' ),
			'desc'              => __( 'seconds after the page loads', 'brikpanel' ),
			'id'                => 'brikpanel_cartab_popup_delay',
			'type'              => 'number',
			'default'           => '5',
			'custom_attributes' => [ 'min' => 0, 'step' => 1 ],
			'css'               => 'width:90px;',
		];
		$fields[] = [
			'title'             => __( 'Do not show again for', 'brikpanel' ),
			'desc'              => __( 'days after a visitor closes the floating tab', 'brikpanel' ),
			'desc_tip'          => __( 'Closing the popup itself only collapses it into the floating tab; this cooldown applies when the visitor dismisses the tab too.', 'brikpanel' ),
			'id'                => 'brikpanel_cartab_popup_cooldown',
			'type'              => 'number',
			'default'           => '7',
			'custom_attributes' => [ 'min' => 1, 'step' => 1 ],
			'css'               => 'width:90px;',
		];
		$fields[] = [
			'type' => 'sectionend',
			'id'   => 'brk_cartab_title',
		];
		return $fields;
	}
}

new Brikpanel_Cart_Abandonment();

// =============================================================================
// PUBLIC API (BrikMentor and other integrations)
// =============================================================================

if ( ! function_exists( 'brikpanel_cartab_get_entries' ) ) {
	/**
	 * Query captured cart-abandonment entries. Runs the lazy abandonment
	 * sweep first so returned statuses are always current.
	 *
	 * @param array $args See Brikpanel_Cart_Abandonment::query_entries().
	 * @return array List of entries (or ['total','rows'] when $args['count']).
	 */
	function brikpanel_cartab_get_entries( array $args = [] ) {
		Brikpanel_Cart_Abandonment::flip_abandoned();
		return Brikpanel_Cart_Abandonment::query_entries( $args );
	}
}

// =============================================================================
// CRON REGISTRATION — abandonment sweep every 10 minutes
// =============================================================================
add_action( 'brikpanel_cron_register', function () {
	if ( ! class_exists( 'Brikpanel_Cron' ) || ! Brikpanel_Cart_Abandonment::is_enabled() ) {
		return;
	}

	Brikpanel_Cron::register_handler(
		'brikpanel_cartab_flip_abandoned',
		[ 'Brikpanel_Cart_Abandonment', 'cron_flip' ],
		[
			'label'       => __( 'Mark abandoned carts', 'brikpanel' ),
			'description' => __( 'Flips inactive captured carts to abandoned and notifies integrations.', 'brikpanel' ),
		]
	);

	Brikpanel_Cron::schedule_recurring( 'brikpanel_cartab_flip_abandoned', 10 * MINUTE_IN_SECONDS );
} );
