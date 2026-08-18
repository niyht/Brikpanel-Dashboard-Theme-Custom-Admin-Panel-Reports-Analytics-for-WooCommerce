<?php
/**
 * BrikPanel — Cart Abandonment email capture.
 *
 * Collects customer emails the moment they are typed on the checkout page
 * (classic shortcode checkout, Gutenberg block checkout, and any third-party
 * checkout that renders a native email input) plus an optional site-wide
 * signup popup. Each captured email is stored in wp_brikpanel_abandoned_carts
 * together with a live cart snapshot, and rows move through a simple
 * lifecycle: active → abandoned (no activity for N minutes) → recovered (an
 * order placed with that email / browser reached a status that represents a
 * sale). Recovery is reversible: an order that is later declined, cancelled or
 * deleted hands the cart back to active/abandoned.
 *
 * This module only COLLECTS — it never sends emails. The follow-up sending
 * layer lives in the separate BrikMentor plugin, which consumes the data via
 * brikpanel_cartab_get_entries() and the brikpanel_cart_abandoned /
 * brikpanel_cart_recovered / brikpanel_cart_recovery_reverted /
 * brikpanel_cartab_email_captured hooks.
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

	/**
	 * Order meta holding the shopper's browser id, stamped at checkout. The
	 * cookie itself is only readable on that one front-end request; recovery
	 * happens later (gateway callback, admin status change) on a request that
	 * carries somebody else's cookie or none at all.
	 */
	const ORDER_VISITOR_META = '_brikpanel_cartab_vid';

	/**
	 * Orders already credited this request. woocommerce_order_status_changed
	 * can fire several times for one order in a single save.
	 *
	 * @var array<int,bool>
	 */
	private $recovered_this_request = [];

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
		add_action( 'wp_ajax_brikpanel_cartab_save_columns', [ $this, 'ajax_save_columns' ] );

		// Popup text options: WC's default text sanitizer (sanitize_text_field)
		// strips anything that looks like a percent-encoded octet, so a title
		// like "%10 indirim" would silently lose its "%10". Override with a
		// sanitizer that keeps the percent sign.
		foreach ( [ 'popup_title', 'popup_message', 'popup_button', 'popup_placeholder', 'popup_success', 'popup_teaser', 'whatsapp_template' ] as $opt ) {
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

		// Popup "edit email" correction: lets a visitor who mistyped their
		// address fix it and have the coupon re-delivered to the right inbox.
		add_action( 'wp_ajax_nopriv_brikpanel_cartab_popup_update_email', [ $this, 'ajax_update_popup_email' ] );
		add_action( 'wp_ajax_brikpanel_cartab_popup_update_email',        [ $this, 'ajax_update_popup_email' ] );

		// Server-side cart mirror: keeps snapshots fresh for every known email
		// (popup or checkout sourced) without relying on any front-end JS.
		add_action( 'woocommerce_cart_updated', [ $this, 'on_cart_updated' ] );

		// Checkout submitted: record who the shopper is on the order. This is
		// not the recovery — the gateway has not run yet (see
		// stamp_checkout_visitor).
		add_action( 'woocommerce_checkout_order_processed',           [ $this, 'on_checkout_processed' ], 10, 3 );
		add_action( 'woocommerce_store_api_checkout_order_processed', [ $this, 'on_store_api_processed' ] );

		// Recovery, and its withdrawal, both hang off the status the order
		// actually reached.
		add_action( 'woocommerce_order_status_changed',               [ $this, 'on_order_status_changed' ], 10, 4 );
		add_action( 'woocommerce_before_delete_order',                [ $this, 'on_order_deleted' ] );
		add_action( 'woocommerce_before_trash_order',                 [ $this, 'on_order_deleted' ] );
		add_action( 'before_delete_post',                             [ $this, 'on_legacy_order_deleted' ] );
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

	/**
	 * Whether the email-capture popup is switched on.
	 *
	 * Split out from popup_config() so callers can test the flag without
	 * building the whole config — the config resolves a dozen translatable
	 * defaults, and running those on requests that will never show the popup
	 * is both wasted work and a way for admin-side strings to reach front-end
	 * translation scanners.
	 *
	 * @return bool
	 */
	public static function popup_enabled() {
		return get_option( 'brikpanel_cartab_popup_enabled', 'no' ) === 'yes';
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
		// The WhatsApp draft is a whole message and can carry a recovery link,
		// so it gets more room than the popup's one-liners.
		$max   = ( isset( $option['id'] ) && 'brikpanel_cartab_whatsapp_template' === $option['id'] ) ? 1000 : 500;
		$clean = trim( $clean );
		// Count characters, not bytes: a byte-wise cut lands mid-character in
		// Arabic, Turkish or any other non-ASCII text and corrupts the tail.
		return function_exists( 'mb_substr' ) ? mb_substr( $clean, 0, $max ) : substr( $clean, 0, $max );
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

	/**
	 * The shopper's browser id as recorded on an order, never from the current
	 * request's cookie — recovery runs long after checkout, often in wp-admin.
	 *
	 * @param WC_Order $order
	 * @return string Empty when the order predates the stamp or came in
	 *                without a cookie; matching then falls back to email alone.
	 */
	private static function order_visitor_id( $order ) {
		return substr( sanitize_text_field( (string) $order->get_meta( self::ORDER_VISITOR_META ) ), 0, 64 );
	}

	/**
	 * Ranking used wherever one row out of several has to be picked as "the"
	 * cart: a cart with items beats a bare email signup, one that really was
	 * abandoned beats one still being shopped, and the most recent wins among
	 * equals. Shared so crediting and de-duplication can never disagree.
	 */
	private static function best_row_order_by() {
		return 'ORDER BY (item_count > 0) DESC, (abandoned_at IS NOT NULL) DESC, updated_at DESC, id DESC';
	}

	/**
	 * Whether this session handed an order to a gateway recently enough that an
	 * empty cart means "checkout in progress" rather than "shopper emptied it".
	 * Generous window: off-site gateways can keep a shopper on the bank's 3-D
	 * Secure page for a while.
	 */
	private static function checkout_in_flight() {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return false;
		}
		$stamp = (int) WC()->session->get( 'brikpanel_cartab_order_pending' );
		return $stamp > 0 && ( time() - $stamp ) < HOUR_IN_SECONDS;
	}

	/** Store staff never get tracked (their test checkouts would pollute the list). */
	private static function is_staff() {
		return is_user_logged_in() && current_user_can( 'manage_woocommerce' );
	}

	/** Status slugs, without resolving labels — safe on the query path. */
	public static function status_keys() {
		return [ 'active', 'abandoned', 'recovered' ];
	}

	/** Source slugs, without resolving labels — safe on the query path. */
	public static function source_keys() {
		return [ 'checkout', 'popup', 'account', 'import' ];
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
			// Legacy: CSV import was removed, but rows captured by it before
			// the removal still need a readable label in the list and export.
			'import'   => __( 'Imported', 'brikpanel' ),
		];
	}

	// =========================================================================
	// Display statuses
	//
	// The three stored statuses are too coarse to report on. Two of them cover
	// rows a merchant reads very differently:
	//
	//   - 'active' with an empty cart is not a cart at all, it is a bare email
	//     signup. flip_abandoned() skips those rows (it requires item_count>0),
	//     so they sit in 'active' forever and would otherwise inflate a card
	//     that is meant to say "carts being shopped right now".
	//   - 'recovered' is written for any order matching the shopper, including
	//     one placed seconds after the capture without the cart ever having
	//     been abandoned. That is a plain conversion, not a recovery.
	//
	// So the list and the stat cards both speak in *display* statuses, derived
	// from columns the row already carries. Nothing here is stored: the DB keeps
	// its three slugs, and status_labels()/status_keys() stay untouched for the
	// public brikpanel_cartab_get_entries() contract.
	// =========================================================================

	/** Display-status slugs, without resolving labels — safe on the query path. */
	public static function display_status_keys() {
		return [ 'email_only', 'active', 'abandoned', 'recovered', 'converted' ];
	}

	public static function display_status_labels() {
		// The three stored statuses reuse their existing labels so every
		// shipped translation keeps applying; only the two new ones are added.
		$base = self::status_labels();
		return [
			'email_only' => _x( 'Email only', 'cart status', 'brikpanel' ),
			'active'     => $base['active'],
			'abandoned'  => $base['abandoned'],
			'recovered'  => $base['recovered'],
			'converted'  => _x( 'Converted', 'cart status', 'brikpanel' ),
		];
	}

	/** True when a DATETIME column actually holds a date (NULL and the all-zero date do not). */
	private static function has_datetime( $value ) {
		$value = trim( (string) $value );
		return '' !== $value && '0000-00-00 00:00:00' !== $value;
	}

	/**
	 * Derive the display status of a row from its stored columns.
	 *
	 * @param string $status       Stored status slug.
	 * @param int    $item_count   Items in the captured cart.
	 * @param string $abandoned_at Sticky "was abandoned at least once" stamp:
	 *                             mirror_cart() flips a row back to 'active'
	 *                             but never clears this, which is exactly what
	 *                             lets a real recovery be told from a plain sale.
	 * @param int    $order_id     Credited order, 0 on rows closed alongside it.
	 * @return string One of display_status_keys(), or the stored status verbatim
	 *                if it is one this build does not know about.
	 */
	public static function derive_display_status( $status, $item_count, $abandoned_at, $order_id ) {
		return self::display_status_from_flags(
			$status,
			(int) $item_count > 0,
			self::has_datetime( $abandoned_at ),
			(int) $order_id > 0
		);
	}

	/**
	 * Same derivation from the three booleans it actually depends on, so the
	 * stat-card query can group by those expressions and hand the aggregated
	 * flags straight over (grouping by the raw columns would both explode the
	 * result set and break under ONLY_FULL_GROUP_BY).
	 *
	 * @param string $status        Stored status slug.
	 * @param bool   $has_items     item_count > 0.
	 * @param bool   $was_abandoned abandoned_at is set.
	 * @param bool   $has_order     order_id > 0 (this row is the credited one).
	 * @return string
	 */
	public static function display_status_from_flags( $status, $has_items, $was_abandoned, $has_order ) {
		if ( 'active' === $status ) {
			return $has_items ? 'active' : 'email_only';
		}
		if ( 'recovered' === $status ) {
			return ( $was_abandoned && $has_order ) ? 'recovered' : 'converted';
		}
		return (string) $status;
	}

	/**
	 * SQL condition selecting the rows of one display status. Returned without
	 * outer parentheses; every caller wraps it before ANDing it into a WHERE.
	 *
	 * @param string $key A display_status_keys() slug.
	 * @return string SQL fragment, or '' for an unknown key.
	 */
	public static function display_status_where( $key ) {
		switch ( (string) $key ) {
			case 'email_only':
				return "status = 'active' AND item_count = 0";
			case 'active':
				return "status = 'active' AND item_count > 0";
			case 'abandoned':
				return "status = 'abandoned'";
			case 'recovered':
				return "status = 'recovered' AND abandoned_at IS NOT NULL AND order_id > 0";
			case 'converted':
				return "status = 'recovered' AND ( abandoned_at IS NULL OR order_id = 0 )";
		}
		return '';
	}

	// =========================================================================
	// List columns (per-user visibility + order) and row sorting
	// =========================================================================

	/**
	 * Per-user column preferences, stored as
	 * [ 'visible' => [ id => bool ], 'order' => [ id, id, … ] ].
	 */
	const USER_COLUMNS_META = 'brikpanel_cartab_columns';

	/**
	 * Ordered column definition shared by the table renderer and the "Columns"
	 * popover. Keys are persisted per-user, so keep them stable.
	 *
	 * The trailing actions column is deliberately absent: it is structural,
	 * always visible and always last, so it is neither toggleable nor movable.
	 *
	 * @return array id => [ label, default, locked ]
	 */
	public static function get_column_defs() {
		$defs = [
			// Locked: the row needs at least one identifying cell, and the
			// email is the only field guaranteed to be filled on every row.
			'email'   => [ 'label' => __( 'Email', 'brikpanel' ), 'default' => true, 'locked' => true ],
			'name'    => [ 'label' => __( 'Name', 'brikpanel' ), 'default' => true ],
			'phone'   => [ 'label' => __( 'Phone', 'brikpanel' ), 'default' => true ],
			'cart'    => [ 'label' => __( 'Cart', 'brikpanel' ), 'default' => true ],
			// Off by default: the amount already rides along inside the Cart
			// cell. This is the standalone version for people who want to scan
			// a column of numbers.
			'total'   => [ 'label' => __( 'Cart total', 'brikpanel' ), 'default' => false ],
			'mail'    => [ 'label' => __( 'Follow-ups', 'brikpanel' ), 'default' => true ],
			'source'  => [ 'label' => __( 'Source', 'brikpanel' ), 'default' => true ],
			'status'  => [ 'label' => __( 'Status', 'brikpanel' ), 'default' => true ],
			'created' => [ 'label' => __( 'Created', 'brikpanel' ), 'default' => false ],
			'updated' => [ 'label' => __( 'Last activity', 'brikpanel' ), 'default' => true ],
		];

		// Phone / WhatsApp and the follow-up counter ride along with BrikMentor;
		// without it there is nothing to put in those cells, so they must not
		// reach the table or the Columns popover at all.
		if ( ! self::mentor_active() ) {
			unset( $defs['phone'], $defs['mail'] );
		}

		$defs = apply_filters( 'brikpanel_cartab_columns', $defs, get_current_user_id() );

		// Ids end up in an HTML attribute *name* (data-hide-<id>), a CSS class
		// and a querySelector, none of which escaping can make safe after the
		// fact. So anything a filter adds that is not a plain slug is dropped.
		$clean = [];
		foreach ( (array) $defs as $id => $def ) {
			$id = (string) $id;
			if ( is_array( $def ) && isset( $def['label'] ) && preg_match( '/^[a-z0-9_-]+$/', $id ) ) {
				$clean[ $id ] = $def;
			}
		}

		return $clean;
	}

	/**
	 * Resolved column preferences for one user: the display order plus a
	 * visibility map, both reconciled against the current definition so that
	 * stale ids (BrikMentor deactivated) and newly added columns are handled
	 * without the saved value having to be rewritten.
	 *
	 * @param int $user_id Defaults to the current user.
	 * @return array [ 'order' => string[], 'visible' => array<string,bool> ]
	 */
	public static function get_user_columns( $user_id = 0 ) {
		if ( ! $user_id ) {
			$user_id = get_current_user_id();
		}
		$defs  = self::get_column_defs();
		$saved = get_user_meta( $user_id, self::USER_COLUMNS_META, true );
		if ( ! is_array( $saved ) ) {
			$saved = [];
		}
		$saved_order   = isset( $saved['order'] ) && is_array( $saved['order'] ) ? $saved['order'] : [];
		$saved_visible = isset( $saved['visible'] ) && is_array( $saved['visible'] ) ? $saved['visible'] : [];

		// Saved order first (dropping ids that no longer exist), then any
		// column the saved order never knew about, in definition order.
		$order = [];
		foreach ( $saved_order as $id ) {
			$id = (string) $id;
			if ( isset( $defs[ $id ] ) && ! in_array( $id, $order, true ) ) {
				$order[] = $id;
			}
		}
		foreach ( $defs as $id => $def ) {
			if ( ! in_array( $id, $order, true ) ) {
				$order[] = $id;
			}
		}

		$visible = [];
		foreach ( $order as $id ) {
			if ( ! empty( $defs[ $id ]['locked'] ) ) {
				$visible[ $id ] = true;
				continue;
			}
			$visible[ $id ] = array_key_exists( $id, $saved_visible )
				? (bool) $saved_visible[ $id ]
				: ! empty( $defs[ $id ]['default'] );
		}

		return [ 'order' => $order, 'visible' => $visible ];
	}

	/**
	 * ORDER BY fragment per sort key. Deliberately free of translations: this
	 * runs on every query, including any front-end call through the public
	 * brikpanel_cartab_get_entries() helper, and admin-only strings must not
	 * be resolved (nor exposed to front-end translation scanners) there.
	 * sort_options() adds the labels for the one place that renders them.
	 *
	 * Every fragment ends with the primary key so paging stays stable: cart
	 * totals and timestamps repeat across rows, and MySQL is free to return
	 * tied rows in any order, which would otherwise duplicate or skip rows
	 * between LIMIT/OFFSET pages.
	 *
	 * @return array key => ORDER BY fragment
	 */
	private static function sort_map() {
		return [
			'updated-desc' => 'updated_at DESC, id DESC',
			'total-desc'   => 'cart_total DESC, id DESC',
			'total-asc'    => 'cart_total ASC, id ASC',
			'created-asc'  => 'created_at ASC, id ASC',
			'created-desc' => 'created_at DESC, id DESC',
		];
	}

	/**
	 * Sort keys with their human labels, for the picker on the list screen.
	 *
	 * @return array key => label
	 */
	public static function sort_options() {
		return [
			'updated-desc' => __( 'Last activity', 'brikpanel' ),
			'total-desc'   => __( 'Highest cart value', 'brikpanel' ),
			'total-asc'    => __( 'Lowest cart value', 'brikpanel' ),
			'created-asc'  => __( 'Oldest cart', 'brikpanel' ),
			'created-desc' => __( 'Newest cart', 'brikpanel' ),
		];
	}

	/** Default sort key — matches the ordering the list has always used. */
	const DEFAULT_SORT = 'updated-desc';

	/**
	 * Recognised date-range preset keys, in display order. Numeric keys are a
	 * count of days; 'custom' reveals the two date inputs and '' means no date
	 * filter at all.
	 *
	 * Kept apart from the labels because resolve_date_bounds() validates
	 * against this on every query — see the note on sort_map().
	 *
	 * Deliberately a flat list of strings rather than array_keys() of the
	 * labelled map: PHP casts numeric array keys to int, so the labelled map's
	 * keys come back as 7/30/90 and a strict in_array() against the string
	 * coming out of sanitize_key() would never match.
	 *
	 * @return string[]
	 */
	private static function date_range_keys() {
		return [ '', 'today', '7', '30', '90', 'custom' ];
	}

	/**
	 * Date-range presets with their human labels, for the picker on the list
	 * screen.
	 *
	 * @return array key => label
	 */
	public static function date_range_options() {
		return [
			''       => __( 'All time', 'brikpanel' ),
			'today'  => __( 'Today', 'brikpanel' ),
			'7'      => __( 'Last 7 days', 'brikpanel' ),
			'30'     => __( 'Last 30 days', 'brikpanel' ),
			'90'     => __( 'Last 90 days', 'brikpanel' ),
			'custom' => __( 'Custom range', 'brikpanel' ),
		];
	}

	/**
	 * Turn a range preset (or a custom from/to pair) into UTC bounds for
	 * created_at.
	 *
	 * created_at is stored in UTC while the merchant thinks in store time, so
	 * every boundary is built in the store's timezone and converted. Without
	 * that step "Today" means a window shifted by the UTC offset, which on a
	 * UTC+3 store hides the first three hours of carts and shows three hours
	 * of yesterday's.
	 *
	 * A recognised preset wins over from/to. When no preset is set the two
	 * dates still apply, so callers of the public brikpanel_cartab_get_entries()
	 * helper that pass plain from/to keep working unchanged.
	 *
	 * @param string $range Preset key.
	 * @param string $from  Y-m-d lower bound (custom range).
	 * @param string $to    Y-m-d upper bound (custom range).
	 * @return array [ 'from' => string, 'to' => string ] UTC 'Y-m-d H:i:s' or ''.
	 */
	private static function resolve_date_bounds( $range, $from, $to ) {
		$bounds = [ 'from' => '', 'to' => '' ];
		$tz     = wp_timezone();
		$utc    = new \DateTimeZone( 'UTC' );

		if ( '' !== $range && 'custom' !== $range && in_array( $range, self::date_range_keys(), true ) ) {
			// Counted in whole store-days including today, so "Last 7 days"
			// is seven day-buckets rather than a rolling 168 hours.
			$days  = ( 'today' === $range ) ? 1 : max( 1, (int) $range );
			$start = new \DateTime( 'now', $tz );
			if ( $days > 1 ) {
				$start->modify( '-' . ( $days - 1 ) . ' days' );
			}
			$start->setTime( 0, 0, 0 );
			$start->setTimezone( $utc );
			$bounds['from'] = $start->format( 'Y-m-d H:i:s' );
			return $bounds; // open ended at the top: everything up to now
		}

		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $from ) ) {
			$start = \DateTime::createFromFormat( 'Y-m-d H:i:s', $from . ' 00:00:00', $tz );
			if ( $start ) {
				$start->setTimezone( $utc );
				$bounds['from'] = $start->format( 'Y-m-d H:i:s' );
			}
		}
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $to ) ) {
			$end = \DateTime::createFromFormat( 'Y-m-d H:i:s', $to . ' 23:59:59', $tz );
			if ( $end ) {
				$end->setTimezone( $utc );
				$bounds['to'] = $end->format( 'Y-m-d H:i:s' );
			}
		}

		return $bounds;
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
		// Asks the variant that does NOT treat a prefetch as a bot: this method
		// emits assets, and the browser reuses prefetched HTML verbatim for the
		// real navigation (a page cache may reuse it for everyone). Dropping the
		// capture script and popup here would make them vanish for the visitor
		// who actually lands on the page. The capture endpoint keeps its own
		// filter, and an AJAX POST is never a prefetch.
		if ( function_exists( 'brikpanel_is_bot_request' ) && brikpanel_is_bot_request( false ) ) {
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
		$popup_here        = self::popup_enabled() && ! $is_checkout && ! $is_order_received;

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

		$data = [
			'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
			'isCheckout' => $is_checkout ? 1 : 0,
			'knownEmail' => $is_checkout ? $known_email : '',
			'popup'      => [ 'enabled' => 0 ],
			'i18n'       => [],
		];

		// Every `cfg.popup.*` / `cfg.i18n.*` read in cart-abandonment.js sits
		// inside its `popup.enabled === 1` branch — the checkout capture path
		// uses none of them. So on checkout, or anywhere the popup is off, we
		// skip the whole block rather than resolving a dozen translated
		// defaults that nothing will render.
		if ( $popup_here ) {
			$popup = self::popup_config();

			$data['popup'] = [
				'enabled'     => 1,
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
			];

			$data['i18n'] = [
				'invalidEmail' => __( 'Please enter a valid email address.', 'brikpanel' ),
				'error'        => __( 'Something went wrong. Please try again.', 'brikpanel' ),
				'close'        => __( 'Close', 'brikpanel' ),
				'emailLabel'   => __( 'Email address', 'brikpanel' ),
				'couponIntro'  => __( 'Your discount code', 'brikpanel' ),
				'couponHint'   => __( 'Apply it at checkout. Valid for 30 days.', 'brikpanel' ),
				/* translators: %s: the visitor's email address */
				'couponEmailed'     => __( 'We sent your discount code to %s', 'brikpanel' ),
				'couponEmailedHint' => __( 'Check your inbox — if it landed in the Promotions tab, drag it to Primary so you never miss it.', 'brikpanel' ),
				'editEmail'    => __( 'Wrong address? Edit it', 'brikpanel' ),
				'editSave'     => __( 'Update & resend', 'brikpanel' ),
				'editCancel'   => __( 'Cancel', 'brikpanel' ),
				'editDone'     => __( 'Sent to your new address', 'brikpanel' ),
				'copy'         => __( 'Copy', 'brikpanel' ),
				'copied'       => __( 'Copied!', 'brikpanel' ),
				'offBadge'     => __( 'OFF', 'brikpanel' ),
				'scratchMe'    => __( 'Scratch me', 'brikpanel' ),
			];
		}

		wp_localize_script( 'brikpanel_cartab_scripts', 'brikpanelCartAb', $data );
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

		// Read the id the request actually ARRIVED with before minting one:
		// visitor_id() both creates a new id and writes it into $_COOKIE, so
		// after that call there is no way left to tell a returning browser from
		// a client that sends no cookie at all - and that distinction is what
		// the popup rate limit below is keyed on.
		$known_visitor = self::existing_visitor_id();
		$visitor       = self::visitor_id();

		// Rate limit: at most one accepted capture per 2s per browser (per IP
		// when the cookie could not be set). Popup submits are exempt from THIS
		// bucket — they are deliberate one-off user actions and must not lose
		// their coupon to a racing checkout-poller ping — but they are not
		// exempt from rate limiting as such: they get their own, stricter
		// bucket below, because the popup branch is the expensive one (it mints
		// a coupon and, with a companion plugin installed, sends mail).
		$rl_id  = $visitor !== '' ? $visitor : ( isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : 'unknown' );
		$rl_key = 'bp_cartab_rl_' . md5( $rl_id );
		if ( 'popup' !== $source ) {
			if ( get_transient( $rl_key ) ) {
				wp_send_json_success( [ 'throttled' => true ] );
			}
			set_transient( $rl_key, 1, 2 );
		} elseif ( self::popup_capture_throttled( $known_visitor ) ) {
			// Answer exactly like the checkout throttle does: the visitor still
			// gets the popup's thank-you state, just without a coupon. Telling
			// an abusive client that it hit a limit only helps it tune.
			wp_send_json_success( [ 'throttled' => true ] );
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

		// The billing country the phone was typed under. Stored so the WhatsApp
		// link never has to guess a country code from the store's own address.
		// Anything that is not a plain ISO 3166-1 alpha-2 code is dropped rather
		// than stored and puzzled over later.
		if ( isset( $_POST['phone_country'] ) ) {
			$cc = strtoupper( sanitize_text_field( wp_unslash( $_POST['phone_country'] ) ) );
			if ( 1 === preg_match( '/^[A-Z]{2}$/', $cc ) ) {
				$extra['phone_country'] = $cc;
			}
		}

		$id       = $this->upsert( $visitor, strtolower( $email ), $source, $extra );
		$response = [ 'id' => (int) $id ];

		// Popup signups earn a personal discount coupon (when configured).
		if ( 'popup' === $source ) {
			$response = array_merge( $response, self::deliver_popup_coupon( strtolower( $email ), (int) $id ) );
		}

		wp_send_json_success( $response );
	}

	/**
	 * Volume guard for the popup branch of the capture endpoint.
	 *
	 * The endpoint is deliberately nonce-less (it has to work on fully cached
	 * pages), so the only honest brake left is volume. Two details matter:
	 *
	 * - The hourly cap is keyed on the IP, never on the visitor cookie. A
	 *   client that simply never sends the cookie is handed a brand new id on
	 *   every request, so a cookie-keyed cap is no cap at all — which is the
	 *   exact shape of an automated spray.
	 * - The burst brake prefers the cookie and only falls back to the IP. Real
	 *   browsers each carry their own id, so two genuine shoppers behind one
	 *   office NAT never throttle each other; a cookie-less client lands in the
	 *   shared IP bucket, which is where it belongs.
	 *
	 * A store-wide ceiling sits on top for the same spray arriving from
	 * rotating addresses. All three limits are sized so a real signup rate
	 * never reaches them (a visitor submits the popup once), and all are
	 * filterable for the rare shop that genuinely exceeds them.
	 *
	 * @param string $visitor Id the request ARRIVED with; '' when it sent no
	 *                        cookie. Must be the pre-mint value: visitor_id()
	 *                        hands a cookie-less client a brand new id (and
	 *                        writes it into $_COOKIE), so keying on the minted
	 *                        value gives that client a fresh bucket on every
	 *                        request - a limiter that never limits the one
	 *                        client it exists for.
	 * @return bool True when this capture should be dropped.
	 */
	private static function popup_capture_throttled( $visitor ) {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : 'unknown';

		// Burst brake: one popup submit per 10s per browser (per IP when the
		// browser sends no id). Cheapest check, and on its own it already turns
		// a flood into a trickle.
		$burst = 'bp_cartab_pop_b_' . md5( $visitor !== '' ? 'v:' . $visitor : 'i:' . $ip );
		if ( get_transient( $burst ) ) {
			return true;
		}
		set_transient( $burst, 1, 10 );

		/**
		 * Popup signups accepted per hour from a single address. 0 disables it.
		 *
		 * @param int $cap
		 */
		$per_ip = (int) apply_filters( 'brikpanel_cartab_popup_ip_hourly_cap', 30 );

		/**
		 * Popup signups accepted per hour store-wide. 0 disables it.
		 *
		 * @param int $cap
		 */
		$global = (int) apply_filters( 'brikpanel_cartab_popup_hourly_cap', 100 );

		return self::popup_quota_spent( 'bp_cartab_pop_ip_' . md5( $ip ), $per_ip )
			|| self::popup_quota_spent( 'bp_cartab_pop_all', $global );
	}

	/**
	 * Consume one unit from a fixed hourly window and report whether it was
	 * already empty.
	 *
	 * Fixed window rather than a rolling one on purpose: a rolling counter
	 * whose expiry is pushed forward on every hit never releases while an
	 * abusive client keeps knocking, which would let an attacker lock a shared
	 * office IP out of the coupon for as long as it cared to keep trying. This
	 * window always reopens one hour after its first hit.
	 *
	 * @param string $key Transient key.
	 * @param int    $cap Allowance per hour; 0 or less disables the limit.
	 * @return bool True when the allowance is already used up.
	 */
	private static function popup_quota_spent( $key, $cap ) {
		if ( $cap <= 0 ) {
			return false;
		}

		$bucket = get_transient( $key );
		if ( ! is_array( $bucket ) || empty( $bucket['start'] )
			|| ( time() - (int) $bucket['start'] ) >= HOUR_IN_SECONDS ) {
			$bucket = [ 'n' => 0, 'start' => time() ];
		}
		if ( (int) $bucket['n'] >= $cap ) {
			return true;
		}

		$bucket['n'] = (int) $bucket['n'] + 1;
		set_transient( $key, $bucket, HOUR_IN_SECONDS );
		return false;
	}
	/**
	 * Mint (or reuse) the signup coupon for a popup email and decide how it
	 * reaches the visitor. Returns the response fragment the popup consumes:
	 * either an inline code or an "emailed" notice. Shared by the initial
	 * capture and the "edit email" correction so both behave identically.
	 *
	 * @param string $email Validated, lowercased email.
	 * @param int    $id    Cart-abandonment entry id.
	 * @return array{coupon?:string,discount?:int,coupon_emailed?:bool,email?:string}
	 */
	private static function deliver_popup_coupon( $email, $id ) {
		// A popup coupon only exists while the merchant actually runs the
		// popup. `source` is just a POST field on a public, nonce-less
		// endpoint, so without this check anyone could mint coupons — and
		// trigger a companion plugin's delivery email — on a store that never
		// switched the popup on. Front-end callers are unaffected: the popup
		// is only rendered under the same option.
		if ( get_option( 'brikpanel_cartab_popup_enabled', 'no' ) !== 'yes' ) {
			return [];
		}

		self::remember_customer_email( $email );
		$coupon = self::get_or_create_popup_coupon( $email );
		if ( ! $coupon ) {
			return [];
		}

		/**
		 * How the popup coupon reaches the visitor.
		 *
		 * 'inline' (default) shows the code in the popup; 'email' suppresses it
		 * and tells the visitor to check their inbox — a companion plugin (e.g.
		 * BrikMentor) then delivers the code by email via the deferred action.
		 *
		 * @param string $delivery 'inline' | 'email'.
		 * @param array  $coupon   {code, amount}.
		 * @param string $email    Lowercased captured email.
		 * @param int    $id       Cart-abandonment entry id.
		 */
		$delivery = apply_filters( 'brikpanel_cartab_popup_coupon_delivery', 'inline', $coupon, $email, (int) $id );
		if ( 'email' === $delivery ) {
			/**
			 * The popup coupon was deferred to email delivery — the companion
			 * plugin sends it from here.
			 *
			 * @param array  $coupon {code, amount}.
			 * @param string $email  Lowercased captured email.
			 * @param int    $id     Cart-abandonment entry id.
			 */
			do_action( 'brikpanel_cartab_popup_coupon_deferred', $coupon, $email, (int) $id );
			return [ 'coupon_emailed' => true, 'email' => $email ];
		}

		return [ 'coupon' => $coupon['code'], 'discount' => $coupon['amount'] ];
	}

	/**
	 * Public (nopriv) endpoint: correct the email on a just-made popup signup
	 * and re-deliver the coupon to the fixed address. A visitor who mistypes
	 * their inbox never receives the emailed code, so this is the escape hatch.
	 *
	 * Authorization mirrors the capture endpoint's cache-safe model (no nonce):
	 * the row must belong to THIS browser's visitor cookie, be a recent popup
	 * signup, and still be open, plus a throttle and a per-entry edit cap so a
	 * single signup can never be turned into a coupon-email spray.
	 */
	public function ajax_update_popup_email() {
		if ( ! self::is_enabled() || self::is_staff()
			|| ( function_exists( '_brikpanel_is_bot_ua' ) && _brikpanel_is_bot_ua() ) ) {
			wp_send_json_error( [ 'message' => __( 'Editing is not available right now.', 'brikpanel' ) ], 403 );
		}

		$id    = isset( $_POST['id'] ) ? absint( wp_unslash( $_POST['id'] ) ) : 0;
		$email = isset( $_POST['email'] ) ? strtolower( sanitize_email( wp_unslash( $_POST['email'] ) ) ) : '';
		if ( ! $id || $email === '' || strlen( $email ) > 190 || ! is_email( $email ) ) {
			wp_send_json_error( [ 'message' => __( 'Please enter a valid email address.', 'brikpanel' ) ], 400 );
		}

		// Without a visitor cookie there is nothing to authorize the edit
		// against, so it is refused (the popup submit itself always sets one).
		$visitor = self::existing_visitor_id();
		if ( $visitor === '' ) {
			wp_send_json_error( [ 'message' => __( 'Editing is not available right now.', 'brikpanel' ) ], 403 );
		}

		global $wpdb;
		$table = self::table();
		$row   = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, email, source, visitor_id, created_at FROM {$table} WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$id
		) );
		if ( ! $row
			|| 'popup' !== $row->source
			|| ! hash_equals( (string) $row->visitor_id, $visitor )
			|| strtotime( (string) $row->created_at . ' UTC' ) < time() - HOUR_IN_SECONDS
		) {
			wp_send_json_error( [ 'message' => __( 'Editing is not available right now.', 'brikpanel' ) ], 403 );
		}

		// Throttle (one correction per 3s per browser) + hard cap (5 per entry
		// per hour) so re-delivery can never be abused to spam an inbox.
		$rl_key = 'bp_cartab_edit_rl_' . md5( $visitor );
		if ( get_transient( $rl_key ) ) {
			wp_send_json_error( [ 'message' => __( 'Please wait a moment before trying again.', 'brikpanel' ) ], 429 );
		}
		set_transient( $rl_key, 1, 3 );
		$cnt_key = 'bp_cartab_edit_cnt_' . (int) $id;
		$count   = (int) get_transient( $cnt_key );
		if ( $count >= 5 ) {
			wp_send_json_error( [ 'message' => __( 'You have changed the address too many times. Please try later.', 'brikpanel' ) ], 429 );
		}
		set_transient( $cnt_key, $count + 1, HOUR_IN_SECONDS );

		// Apply the corrected address to the row (only when it actually changed).
		if ( $email !== strtolower( (string) $row->email ) ) {
			$wpdb->update(
				$table,
				[ 'email' => $email, 'updated_at' => current_time( 'mysql', true ) ],
				[ 'id' => (int) $id ]
			);
		}

		$response = array_merge( [ 'id' => (int) $id ], self::deliver_popup_coupon( $email, (int) $id ) );
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
	 * Whether this cart is one the shopper has just bought, rather than a new
	 * one worth chasing.
	 *
	 * Recovery closes every row a shopper owns, which means that seconds later
	 * they own no OPEN row at all — and upsert() reads that as "new shopper,
	 * start a cart". That is fine when the cart is genuinely empty afterwards,
	 * but off-site and iframe gateways (PayTR, and every 3-D Secure redirect)
	 * complete the order in a server-side callback: the customer's own session,
	 * and the persistent cart WooCommerce keeps for logged-in customers, still
	 * hold the items unless they land back on the thank-you page. The next
	 * mirror_cart() then opens a fresh row for a cart that has already been paid
	 * for, and an hour later it is sitting in the list marked "Abandoned" — the
	 * one row a follow-up tool would happily email to a paying customer.
	 *
	 * Matching on the cart signature rather than time alone keeps this narrow:
	 * a shopper who really does build a different cart an hour after buying is
	 * still captured normally.
	 *
	 * @param string $email    Validated, lowercased email.
	 * @param array  $snapshot Current cart snapshot.
	 * @return int Id of the recovered row this cart duplicates, 0 when it is a
	 *             genuinely new cart.
	 */
	private static function just_repurchased( $email, array $snapshot ) {
		// An email-only capture (popup signup, empty cart) is never a repeat of
		// a purchase and must always be allowed through — including its coupon.
		if ( (int) $snapshot['count'] < 1 ) {
			return 0;
		}

		/**
		 * How long after a recovery an identical cart is read as the same
		 * purchase instead of a new one.
		 *
		 * @param int $seconds Defaults to 6 hours.
		 */
		$window = (int) apply_filters( 'brikpanel_cartab_repurchase_window', 6 * HOUR_IN_SECONDS );
		if ( $window < 1 ) {
			return 0;
		}

		if ( $email === '' ) {
			return 0;
		}

		global $wpdb;
		$table  = self::table();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $window );

		// Identity here is the email, deliberately not the browser id. The
		// cookie is what links one shopper's several addresses elsewhere in this
		// module, but borrowing it here would let a genuinely new popup signup
		// from the same browser be swallowed as "already bought". A shopper who
		// bought is identified by the address they bought with.
		//
		// An exact signature match is the point: if the total is a cent off, the
		// guard simply does not fire and the row is created as before. Failing
		// that way round is the safe one.
		// Currency is part of the signature: on a multi-currency store the same
		// number in two currencies is two different carts.
		$id = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$table}
			 WHERE email = %s AND status = 'recovered' AND recovered_at >= %s
			   AND item_count = %d AND cart_total = %f AND currency = %s
			 ORDER BY id DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$email,
			$cutoff,
			(int) $snapshot['count'],
			(float) $snapshot['total'],
			(string) $snapshot['currency']
		) );

		return (int) $id;
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
			foreach ( [ 'first_name', 'last_name', 'phone', 'phone_country' ] as $field ) {
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

		// Nothing open to refresh, so this would start a new cart. Make sure it
		// is not the cart the shopper just paid for coming back to haunt them.
		$repurchase = self::just_repurchased( $email, $snapshot );
		if ( $repurchase ) {
			return $repurchase;
		}

		// …nor a half-typed version of the address that is arriving right now.
		// See supersede_same_shopper_row(): this renames the earlier row instead
		// of opening a second one for the same shopper.
		$superseded = self::supersede_same_shopper_row( $visitor, $email, $source );
		if ( $superseded ) {
			// Re-run the refresh now that a row carries this address, so the
			// snapshot/extras land on it exactly as they would have on an
			// ordinary returning capture.
			$id = $update();
			$id = $id ? $id : $superseded;

			/**
			 * Fires when a NEW email lands in the abandoned-cart list.
			 *
			 * Announced on a correction too, carrying the same row id as the
			 * address it replaces. Subscribers keep the ESP contract they had
			 * before this row was collapsed: back when the half-typed address
			 * opened a second row, they were told about the corrected one as a
			 * fresh capture. Staying silent here would leave them holding the
			 * typo — the one address the shopper is definitely not reachable at.
			 *
			 * @param array $entry Formatted row (see brikpanel_cartab_get_entries()).
			 */
			do_action( 'brikpanel_cartab_email_captured', self::get_entry( $id ) );

			return $id;
		}

		// Atomic insert-if-absent: two near-simultaneous captures from the
		// same browser (e.g. popup submit racing the checkout poller) must
		// not create two rows, so the existence check runs inside the INSERT.
		$inserted = $wpdb->query( $wpdb->prepare(
			"INSERT INTO {$table}
				(visitor_id, email, first_name, last_name, phone, phone_country, user_id, source, status,
				 cart_items, item_count, cart_total, currency, created_at, updated_at)
			 SELECT %s, %s, %s, %s, %s, %s, %d, %s, 'active', %s, %d, %f, %s, %s, %s
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
			isset( $extra['phone_country'] ) ? $extra['phone_country'] : '',
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

	/**
	 * Rename the row a shopper opened moments ago with a half-typed or
	 * mistyped version of the address that is arriving now, instead of opening
	 * a second row beside it.
	 *
	 * The capture endpoint is fed by an `input` listener and a 3s poller, and
	 * neither can tell "done typing" from "paused typing". Nothing in a partial
	 * address marks it as partial either: `beyza@gmail.co` is a perfectly valid
	 * address (.co is Colombia), so no amount of validation rejects it — it is
	 * only wrong in hindsight, once `…@gmail.com` follows three seconds later.
	 * The same holds for a genuine slip the shopper then corrects (`@gail.com`,
	 * `@gmail.con`). Both leave a row nobody can ever be reached at, counted as
	 * an abandoned cart and queued for follow-up mail that hard-bounces.
	 *
	 * Identity here is the browser id plus the local part — the half of the
	 * address the shopper had already finished typing when the first capture
	 * fired, identical in every case observed. That is deliberately narrower
	 * than "any two addresses from one browser": a shared machine, or one
	 * person genuinely using two of their own addresses, keeps both rows.
	 *
	 * Only checkout rows are absorbed. A popup signup may already have had an
	 * email-restricted coupon minted against its address
	 * (see deliver_popup_coupon()), and renaming the row would leave the coupon
	 * locked to an inbox no longer on file; those have their own correction
	 * path in ajax_update_popup_email().
	 *
	 * @param string $visitor Browser id ('' when cookieless — never matches).
	 * @param string $email   Validated, lowercased incoming address.
	 * @param string $source  Source of the incoming capture.
	 * @return int Renamed row id, or 0 when nothing qualified.
	 */
	private static function supersede_same_shopper_row( $visitor, $email, $source ) {
		if ( 'checkout' !== $source || '' === $visitor ) {
			return 0;
		}

		$at = strpos( $email, '@' );
		if ( false === $at || $at < 1 ) {
			return 0;
		}
		$local = substr( $email, 0, $at );

		/**
		 * How long after a capture a further address from the same browser and
		 * the same local part is read as the shopper still correcting the one
		 * address, rather than as a second shopper or a second inbox.
		 *
		 * @param int $seconds Defaults to 15 minutes.
		 */
		$window = (int) apply_filters( 'brikpanel_cartab_typo_window', 15 * MINUTE_IN_SECONDS );
		if ( $window < 1 ) {
			return 0;
		}

		global $wpdb;
		$table  = self::table();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $window );

		// LIKE rather than SUBSTRING_INDEX so the (visitor_id, email) index still
		// drives the lookup; the visitor id alone already narrows this to a
		// handful of rows, and esc_like keeps a local part containing _ or %
		// from matching its neighbours.
		$order_by = self::best_row_order_by();
		$ids      = $wpdb->get_col( $wpdb->prepare(
			"SELECT id FROM {$table}
			 WHERE visitor_id = %s
			   AND email LIKE %s
			   AND email <> %s
			   AND status IN ('active','abandoned')
			   AND source = 'checkout'
			   AND created_at >= %s
			 {$order_by}", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$visitor,
			$wpdb->esc_like( $local ) . '@%',
			$email,
			$cutoff
		) );
		$ids = array_map( 'intval', (array) $ids );
		if ( ! $ids ) {
			return 0;
		}

		// The best of them takes the corrected address. Any others are further
		// keystrokes from the same burst; they are dropped outright rather than
		// handed to merge_open_duplicates(), which groups by (visitor, email)
		// and would read each wrong address as a shopper of its own.
		$keep = (int) array_shift( $ids );
		$wpdb->update( $table, [ 'email' => $email ], [ 'id' => $keep ] );

		if ( $ids ) {
			$wpdb->query( 'DELETE FROM ' . $table . ' WHERE id IN (' . implode( ',', $ids ) . ')' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		return $keep;
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

		if ( $ids && $snapshot['count'] === 0 && self::checkout_in_flight() ) {
			// WooCommerce empties the cart the moment a checkout is handed to
			// the gateway, so the very next mirror would blank the snapshot of
			// a cart that is mid-payment. Recovery now waits for the gateway's
			// answer, which means the row is still open when that happens: on a
			// redirect gateway the shopper who gives up at the bank would have
			// been left as a zero-item row that flip_abandoned() (item_count >
			// 0) can never age into an abandoned cart. Leave the row untouched
			// — including updated_at, so its abandonment clock keeps running.
			// The hash is still recorded so the following requests short-circuit
			// at the top instead of re-running the lookups above; refilling the
			// cart changes it and the mirror resumes.
			if ( $session ) {
				$session->set( 'brikpanel_cartab_hash', $hash );
			}
			return;
		}

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

	/**
	 * How far back a cart may have been active and still be credited to an
	 * incoming order. A shopper returning a month later is a new sale, not a
	 * recovery, and counting it as one is what made the card meaningless.
	 *
	 * @return string GMT datetime; rows last touched before it are left alone.
	 */
	private static function recovery_window_cutoff() {
		$days = max( 1, (int) get_option( 'brikpanel_cartab_recovery_window_days', 7 ) );
		return gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
	}

	/**
	 * Statuses that must never be read as "this cart converted into a sale".
	 *
	 * 'pending' is on the list because it is the status every checkout order is
	 * born with, before the gateway has been asked for anything.
	 */
	private static function non_sale_statuses() {
		return [ 'checkout-draft', 'pending', 'failed', 'cancelled', 'refunded', 'trash' ];
	}

	/** Statuses that positively undo a recovery already credited to the order. */
	private static function lost_sale_statuses() {
		return [ 'failed', 'cancelled', 'trash' ];
	}

	/**
	 * Checkout submitted. This is NOT a recovery: both checkout hooks fire from
	 * inside WC_Checkout/the Store API right after the order row is written and
	 * *before* the gateway's process_payment() runs, so the order is still
	 * 'pending' and the money has not moved. Treating this moment as a recovery
	 * is what marked declined payments as recovered, cancelled the shopper's
	 * follow-up sequence, and — because upsert() only dedupes against open rows
	 * — let the very next capture ping insert a duplicate row for the same
	 * cart, leaving one "Recovered" and one "Abandoned" row a minute apart.
	 *
	 * All this does is hand the later status transition what it cannot get on
	 * its own: the shopper's browser id. That cookie is only readable here, on
	 * the front-end request; by the time the gateway calls back (or an admin
	 * flips the status) the request belongs to someone else entirely.
	 *
	 * @param WC_Order $order Freshly created order.
	 */
	private function stamp_checkout_visitor( $order ) {
		if ( ! $order instanceof WC_Order ) {
			$order = wc_get_order( $order );
		}
		if ( ! $order ) {
			return;
		}

		$visitor = self::existing_visitor_id();
		if ( $visitor !== '' ) {
			$order->update_meta_data( self::ORDER_VISITOR_META, $visitor );
			// Meta only: a full save() here would push a possibly stale status
			// prop back over the row WC_Checkout just wrote.
			$order->save_meta_data();
		}

		// Tells mirror_cart() that this session's cart is about to be emptied
		// by a checkout in flight, so an empty snapshot must not overwrite the
		// captured one. See mirror_cart().
		if ( function_exists( 'WC' ) && WC()->session ) {
			WC()->session->set( 'brikpanel_cartab_order_pending', time() );
		}
	}

	public function on_checkout_processed( $order_id, $posted_data, $order ) {
		$this->stamp_checkout_visitor( $order );
	}

	public function on_store_api_processed( $order ) {
		$this->stamp_checkout_visitor( $order );
	}

	/**
	 * The single gate into and out of recovery.
	 *
	 * A cart counts as recovered the first time its order reaches a status that
	 * represents an actual sale, and stops counting the moment that order is
	 * declined, cancelled or binned. The list is a deny-list rather than a
	 * paid-statuses whitelist on purpose: bank transfer ('on-hold') and cash on
	 * delivery ('processing') are real sales that are not yet paid, and stores
	 * running custom order statuses (see the order-statuses module) would drop
	 * out of the report entirely under a whitelist.
	 *
	 * Cookie matching is switched off on this path: it also runs inside
	 * wp-admin, where the brikpanel_vid cookie on the request belongs to
	 * whoever changed the status — an admin editing an order would otherwise
	 * credit it with their own captured carts. The shopper's own id comes off
	 * the order meta stamped at checkout instead, which works no matter how
	 * many requests later the gateway confirms.
	 */
	public function on_order_status_changed( $order_id, $from, $to, $order ) {
		if ( in_array( $to, self::lost_sale_statuses(), true ) ) {
			$this->unmark_recovered( $order );
			return;
		}
		if ( in_array( $to, self::non_sale_statuses(), true ) ) {
			return;
		}
		if ( isset( $this->recovered_this_request[ $order_id ] ) ) {
			return;
		}
		$this->recovered_this_request[ $order_id ] = true;
		$this->mark_recovered( $order );
	}

	/**
	 * An order row disappearing takes its recovery with it, otherwise the cart
	 * keeps a dangling order_id and stays in the recovered figures forever.
	 *
	 * @param int $order_id Order about to be deleted (HPOS or post id).
	 */
	public function on_order_deleted( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( $order ) {
			$this->unmark_recovered( $order );
		}
	}

	/**
	 * Same, for stores still on post-table orders, where deleting an order is
	 * just deleting a post. Cheap guard first: before_delete_post fires for
	 * every post type on the site.
	 *
	 * @param int $post_id
	 */
	public function on_legacy_order_deleted( $post_id ) {
		if ( ! in_array( get_post_type( $post_id ), [ 'shop_order', 'shop_order_placehold' ], true ) ) {
			return;
		}
		$this->on_order_deleted( $post_id );
	}

	/**
	 * Close out every matching open row against an order that has become a sale,
	 * crediting exactly one of them with it. Matches by billing email OR by the
	 * browser id stamped on the order at checkout — that id is what links a
	 * popup signup with email A to an order placed with email B in the same
	 * browser.
	 *
	 * Two rules keep the numbers honest:
	 *
	 * 1. Only carts last active inside the recovery window are touched at all.
	 * 2. One order credits one cart. A shopper can easily have several open
	 *    rows (a popup signup, a checkout capture, an older abandoned cart);
	 *    stamping the order id on all of them counted one sale several times
	 *    and added every one of those cart totals to the recovered value.
	 *
	 * The uncredited rows are still moved to 'recovered' rather than left open.
	 * brikpanel_cart_recovered is how outreach providers (BrikMentor) cancel a
	 * queued follow-up sequence, so leaving those rows behind would keep
	 * emailing a customer who has already bought. They carry order_id = 0, and
	 * that is what the stat cards filter on.
	 *
	 * @param WC_Order|int $order Order that closed the carts.
	 */
	private function mark_recovered( $order ) {
		if ( ! $order instanceof WC_Order ) {
			$order = wc_get_order( $order );
		}
		if ( ! $order ) {
			return;
		}

		$email   = strtolower( (string) $order->get_billing_email() );
		$visitor = self::order_visitor_id( $order );
		if ( $email === '' && $visitor === '' ) {
			return;
		}

		global $wpdb;
		$table  = self::table();
		$cutoff = self::recovery_window_cutoff();

		// Two single-column indexed lookups merged in PHP (an OR across two
		// columns would defeat both indexes).
		$rows = [];
		if ( $email !== '' ) {
			$rows = $wpdb->get_col( $wpdb->prepare(
				"SELECT id FROM {$table}
				 WHERE email = %s AND status IN ('active','abandoned') AND updated_at >= %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$email,
				$cutoff
			) );
		}
		if ( $visitor !== '' ) {
			$rows = array_merge( $rows, $wpdb->get_col( $wpdb->prepare(
				"SELECT id FROM {$table}
				 WHERE visitor_id = %s AND status IN ('active','abandoned') AND updated_at >= %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$visitor,
				$cutoff
			) ) );
		}
		$rows = array_unique( array_map( 'intval', $rows ) );
		if ( ! $rows ) {
			return;
		}

		$ids = implode( ',', $rows );

		// Credit the row that best represents what was actually recovered.
		$order_by  = self::best_row_order_by();
		$credit_id = (int) $wpdb->get_var(
			"SELECT id FROM {$table}
			 WHERE id IN ({$ids})
			 {$order_by}
			 LIMIT 1" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		$now = current_time( 'mysql', true );
		$wpdb->query( $wpdb->prepare(
			"UPDATE {$table}
			 SET status = 'recovered',
			     order_id = CASE WHEN id = %d THEN %d ELSE 0 END,
			     recovered_at = %s, updated_at = %s
			 WHERE id IN ({$ids})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$credit_id,
			$order->get_id(),
			$now,
			$now
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

	/**
	 * Undo a recovery whose order turned out not to be a sale after all: a bank
	 * transfer that was never paid and got cancelled, a capture that failed on
	 * a later attempt, an order that was binned.
	 *
	 * Every row closed by one order carries the identical recovered_at stamp
	 * (mark_recovered() writes them in a single UPDATE), which is what lets the
	 * uncredited siblings — they hold order_id = 0 and are otherwise untraceable
	 * back to the order — be re-opened alongside the credited one.
	 *
	 * Rows return to 'abandoned' when they had been abandoned before, otherwise
	 * to 'active'. abandoned_at is deliberately left in place: it is the sticky
	 * "this was abandoned at least once" stamp that later tells a real recovery
	 * from a plain sale (see derive_display_status()).
	 *
	 * @param WC_Order|int $order Order that stopped being a sale.
	 */
	private function unmark_recovered( $order ) {
		if ( ! $order instanceof WC_Order ) {
			$order = wc_get_order( $order );
		}
		if ( ! $order ) {
			return;
		}

		global $wpdb;
		$table = self::table();

		// Re-arm the order. Whatever took the sale away, putting it back into a
		// selling status has to credit again — including the trash → untrash
		// round trip, which reaches this method through the delete hooks rather
		// than a status transition.
		unset( $this->recovered_this_request[ $order->get_id() ] );

		$credited = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, email, visitor_id, recovered_at FROM {$table}
			 WHERE order_id = %d AND status = 'recovered'
			 LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$order->get_id()
		) );
		if ( ! $credited ) {
			return;
		}

		$rows = [ (int) $credited->id ];

		// Siblings closed in the same sweep. Two single-column indexed lookups
		// merged in PHP (an OR across two columns would defeat both indexes).
		if ( self::has_datetime( $credited->recovered_at ) ) {
			if ( (string) $credited->email !== '' ) {
				$rows = array_merge( $rows, $wpdb->get_col( $wpdb->prepare(
					"SELECT id FROM {$table}
					 WHERE email = %s AND status = 'recovered' AND order_id = 0 AND recovered_at = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$credited->email,
					$credited->recovered_at
				) ) );
			}
			if ( (string) $credited->visitor_id !== '' ) {
				$rows = array_merge( $rows, $wpdb->get_col( $wpdb->prepare(
					"SELECT id FROM {$table}
					 WHERE visitor_id = %s AND status = 'recovered' AND order_id = 0 AND recovered_at = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$credited->visitor_id,
					$credited->recovered_at
				) ) );
			}
		}
		$rows = array_unique( array_map( 'intval', $rows ) );
		$ids  = implode( ',', $rows );

		$wpdb->query( $wpdb->prepare(
			"UPDATE {$table}
			 SET status = CASE WHEN abandoned_at IS NOT NULL THEN 'abandoned' ELSE 'active' END,
			     order_id = 0, recovered_at = NULL, updated_at = %s
			 WHERE id IN ({$ids})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			current_time( 'mysql', true )
		) );

		// Re-opening can leave the shopper holding two open rows for the same
		// cart (the reverted one plus whatever was captured while they retried
		// payment). Collapse them, or the merchant reads one abandoned cart as
		// two.
		$rows = self::merge_open_duplicates( $rows );

		foreach ( $rows as $row_id ) {
			/**
			 * Fires when a recovery is withdrawn because its order stopped
			 * being a sale. The cart is open again and eligible for follow-up,
			 * so an outreach provider that cancelled a sequence on
			 * brikpanel_cart_recovered can re-queue it here.
			 *
			 * @param array $entry    Formatted row (back to active/abandoned).
			 * @param int   $order_id The order that lost the sale.
			 */
			do_action( 'brikpanel_cart_recovery_reverted', self::get_entry( (int) $row_id ), $order->get_id() );
		}
	}

	/**
	 * Collapse open rows that describe the same shopper's same cart down to one,
	 * keeping the best per (visitor_id, email) pair — the same ranking used to
	 * pick the credited row.
	 *
	 * @param int[] $ids Candidate row ids (any status; only open rows merge).
	 * @return int[] Ids that survived, in no particular order.
	 */
	private static function merge_open_duplicates( array $ids ) {
		$ids = array_values( array_unique( array_map( 'intval', $ids ) ) );
		if ( ! $ids ) {
			return [];
		}

		global $wpdb;
		$table = self::table();
		$in    = implode( ',', $ids );

		// The shoppers touched here, then every open row those shoppers own —
		// the duplicate is usually a row that was never in $ids at all.
		$keys = $wpdb->get_results(
			"SELECT DISTINCT visitor_id, email FROM {$table} WHERE id IN ({$in})" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		$order_by = self::best_row_order_by();
		$survivors = [];
		$drop      = [];
		foreach ( $keys as $key ) {
			$owned = $wpdb->get_col( $wpdb->prepare(
				"SELECT id FROM {$table}
				 WHERE visitor_id = %s AND email = %s AND status IN ('active','abandoned')
				 {$order_by}", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$key->visitor_id,
				$key->email
			) );
			$owned = array_map( 'intval', $owned );
			if ( ! $owned ) {
				continue;
			}
			$survivors[] = array_shift( $owned );
			$drop        = array_merge( $drop, $owned );
		}

		$drop = array_values( array_unique( $drop ) );
		if ( $drop ) {
			$wpdb->query( 'DELETE FROM ' . $table . ' WHERE id IN (' . implode( ',', $drop ) . ')' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		// Rows that never merged (already recovered, or belonging to nobody
		// open) still need reporting back to the caller.
		return array_values( array_unique( array_merge( $survivors, array_diff( $ids, $drop ) ) ) );
	}

	// =========================================================================
	// Abandonment sweep (cron + lazy)
	// =========================================================================

	/**
	 * Flip stale active rows (with items, no activity for the configured
	 * number of minutes) to abandoned and fire brikpanel_cart_abandoned for
	 * each. Idempotent; cheap when nothing qualifies (one indexed SELECT).
	 *
	 * A row can reach here more than once: unmark_recovered() re-opens a cart
	 * whose order later failed, and the repair pass re-opens one that was
	 * wrongly closed. Only the FIRST abandonment is a fact about the shopper,
	 * so abandoned_at is never overwritten and the announcement fires only for
	 * rows that had none. Without that, a cart abandoned weeks ago is restamped
	 * with today's date and every subscriber is told it was abandoned again —
	 * a follow-up mail to someone who has long since moved on, and on a stale
	 * row, to an address that will hard-bounce.
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

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, abandoned_at FROM {$table}
			 WHERE status = 'active' AND item_count > 0 AND updated_at < %s
			 ORDER BY updated_at ASC LIMIT %d",
			$cutoff,
			max( 1, (int) $limit )
		) );
		if ( ! $rows ) {
			return 0;
		}

		// Read BEFORE the write: afterwards every row carries a stamp and there
		// is no way left to tell a first abandonment from a repeat one.
		$ids   = [];
		$fresh = [];
		foreach ( $rows as $row ) {
			$ids[] = (int) $row->id;
			$had   = (string) $row->abandoned_at;
			if ( '' === $had || '0000-00-00 00:00:00' === $had ) {
				$fresh[] = (int) $row->id;
			}
		}

		$in = implode( ',', $ids );
		$wpdb->query( $wpdb->prepare(
			"UPDATE {$table}
			 SET status = 'abandoned', abandoned_at = COALESCE(abandoned_at, %s)
			 WHERE id IN ({$in}) AND status = 'active'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			current_time( 'mysql', true )
		) );

		foreach ( $fresh as $row_id ) {
			/**
			 * Fires when a cart with a known email is marked abandoned.
			 *
			 * Fires once per row, on its first abandonment only.
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
	 *     @type string $status  active|abandoned|recovered ('' = all). The
	 *                           stored slug; unchanged since the module shipped.
	 *     @type string $display_status  A display_status_keys() slug ('' = all):
	 *                           email_only|active|abandoned|recovered|converted.
	 *                           Narrower than $status for 'active' (items only)
	 *                           and 'recovered' (was abandoned, and is the row
	 *                           credited with the order). ANDs with $status.
	 *     @type string $source  checkout|popup|account ('' = all).
	 *     @type string $search  Substring match on email / first / last name.
	 *     @type string $range   A date_range_options() preset key; when set to
	 *                           anything but '' or 'custom' it wins over from/to.
	 *     @type string $from    Y-m-d creation-date lower bound (store timezone).
	 *     @type string $to      Y-m-d creation-date upper bound (store timezone).
	 *     @type string $since   Y-m-d H:i:s updated_at lower bound (delta reads).
	 *     @type string $sort    A sort_options() key; anything else falls back
	 *                           to DEFAULT_SORT.
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

		// Validated against the key lists rather than the labelled maps: this
		// runs on every query and must not resolve admin-only translations.
		if ( ! empty( $args['status'] ) && in_array( $args['status'], self::status_keys(), true ) ) {
			$where[]  = 'status = %s';
			$params[] = $args['status'];
		}
		// Derived view over the same columns — see display_status_where(). Kept
		// independent of 'status' on purpose: 'active' and 'recovered' mean
		// narrower things here than in the stored vocabulary, and that stored
		// one is public API through brikpanel_cartab_get_entries().
		if ( ! empty( $args['display_status'] ) ) {
			$fragment = self::display_status_where( (string) $args['display_status'] );
			if ( '' !== $fragment ) {
				$where[] = '(' . $fragment . ')';
			}
		}
		if ( ! empty( $args['source'] ) && in_array( $args['source'], self::source_keys(), true ) ) {
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
		$bounds = self::resolve_date_bounds(
			isset( $args['range'] ) ? (string) $args['range'] : '',
			isset( $args['from'] ) ? (string) $args['from'] : '',
			isset( $args['to'] ) ? (string) $args['to'] : ''
		);
		if ( '' !== $bounds['from'] ) {
			$where[]  = 'created_at >= %s';
			$params[] = $bounds['from'];
		}
		if ( '' !== $bounds['to'] ) {
			$where[]  = 'created_at <= %s';
			$params[] = $bounds['to'];
		}
		if ( ! empty( $args['since'] ) ) {
			$where[]  = 'updated_at >= %s';
			$params[] = (string) $args['since'];
		}

		$where_sql = implode( ' AND ', $where );
		$limit     = isset( $args['limit'] ) ? min( 500, max( 1, (int) $args['limit'] ) ) : 25;
		$offset    = isset( $args['offset'] ) ? max( 0, (int) $args['offset'] ) : 0;

		// The ORDER BY fragment comes from the whitelist, never from the
		// request: the request only picks a key, and an unknown key silently
		// falls back to the default ordering.
		$sorts     = self::sort_map();
		$sort_key  = isset( $args['sort'] ) ? (string) $args['sort'] : '';
		$order_sql = isset( $sorts[ $sort_key ] ) ? $sorts[ $sort_key ] : $sorts[ self::DEFAULT_SORT ];

		$sql = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY {$order_sql} LIMIT %d OFFSET %d";
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

	/**
	 * Reduce the names in a cart snapshot to plain text.
	 *
	 * Done on the way out rather than on the way in, so rows captured before
	 * this was noticed are cleaned too. A variation's stored name can carry the
	 * markup WooCommerce and its extensions use to set the attributes apart
	 * ("Hoodie<span> - </span>M"), and every consumer here - the table, a
	 * WhatsApp draft, a CSV cell - shows plain text, where those tags would
	 * appear verbatim.
	 *
	 * @param array[] $items Snapshot rows.
	 * @return array[]
	 */
	private static function plain_item_names( array $items ) {
		// format_row() runs on the front end too - get_entry() feeds the
		// checkout-time brikpanel_cart_abandoned / _recovered actions - so this
		// cannot assume the helpers module is on disk. Same guard the WhatsApp
		// number takes on brikpanel_phone_to_e164() a few methods down: if the
		// file is missing the names simply stay as they were captured.
		if ( ! function_exists( 'brikpanel_plain_text_from_html' ) ) {
			return $items;
		}

		foreach ( $items as &$item ) {
			if ( isset( $item['name'] ) ) {
				$item['name'] = brikpanel_plain_text_from_html( $item['name'] );
			}
			if ( ! empty( $item['attributes'] ) && is_array( $item['attributes'] ) ) {
				$clean = [];
				foreach ( $item['attributes'] as $label => $value ) {
					$clean[ brikpanel_plain_text_from_html( $label ) ] = brikpanel_plain_text_from_html( $value );
				}
				$item['attributes'] = $clean;
			}
		}
		unset( $item );

		return $items;
	}

	/** Normalize a DB row into the public array shape. */
	private static function format_row( $r ) {
		$items = json_decode( (string) $r->cart_items, true );
		$items = is_array( $items ) ? self::plain_item_names( $items ) : [];
		return [
			'id'           => (int) $r->id,
			'email'        => (string) $r->email,
			'first_name'   => (string) $r->first_name,
			'last_name'    => (string) $r->last_name,
			'phone'        => (string) $r->phone,
			// Recorded at checkout since 3.2.63; '' on rows captured before that.
			'phone_country' => (string) ( $r->phone_country ?? '' ),
			'user_id'      => (int) $r->user_id,
			'visitor_id'   => (string) $r->visitor_id,
			'source'       => (string) $r->source,
			// Stored slug, kept verbatim for brikpanel_cartab_get_entries()
			// consumers; display_status is what the UI badges and filters on.
			'status'       => (string) $r->status,
			'display_status' => self::derive_display_status(
				$r->status,
				$r->item_count,
				$r->abandoned_at ?? '',
				$r->order_id
			),
			'cart_items'   => $items,
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

	/**
	 * Plain-text cart summary, one entry per line item.
	 *
	 * The glue is the caller's choice because the two consumers want opposite
	 * things: a WhatsApp draft reads best with each product on its own line
	 * ("2 × Hoodie (Size: L, Color: Blue)\n1 × Mug"), while a CSV/JSON export
	 * needs the whole cart to stay inside one cell ("…; 1 × Mug"). The default
	 * keeps the single-line form so the export path is unaffected.
	 *
	 * @param array  $items Cart snapshot rows (name, qty, attributes).
	 * @param string $glue  Separator placed between items.
	 * @return string
	 */
	public static function items_summary( array $items, $glue = '; ' ) {
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
		return implode( $glue, $parts );
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
			return brikpanel_money_text( 0 );
		}
		arsort( $totals );
		$parts = [];
		foreach ( $totals as $currency => $amount ) {
			// Isolated per amount, not once around the join: each amount is the
			// unit that must not be reordered, and the " + " between them then
			// falls to the surrounding paragraph where it belongs.
			$parts[] = brikpanel_money_text( (float) $amount, [ 'currency' => $currency ] );
		}
		return implode( ' + ', $parts );
	}

	// =========================================================================
	// Outreach columns (BrikMentor only)
	//
	// Phone / WhatsApp / reminder count are shown only while BrikMentor is
	// active. The phone lives in this table and the WhatsApp link is built
	// here, but the reminder counts belong to BrikMentor, which answers
	// `brikpanel_cartab_message_stats`. With BrikMentor gone the filter has no
	// listener, the whole block switches off and the table is exactly what it
	// was before.
	// =========================================================================

	/** Is the follow-up plugin on this install? Mirrors the promo notice's probe. */
	public static function mentor_active() {
		return defined( 'BRIKMENTOR_VERSION' )
			|| class_exists( 'Brikmentor_Install', false )
			|| class_exists( 'Brikmentor_Flows', false );
	}

	/**
	 * Resolve, for one page of rows, the two things the WhatsApp link needs:
	 * a phone number and the country it was written in.
	 *
	 * The checkout capture only stores a phone when the shopper typed one
	 * before leaving, which is the minority of rows. So both are looked up
	 * here, in one pass, in order of how much the answer can be trusted:
	 *   0. what checkout itself recorded on the row (phone + phone_country),
	 *   1. the account's billing fields, for a row belonging to a user,
	 *   2. the newest past order under the same address, for everyone else.
	 *
	 * Source 0 is the only one that is a fact rather than an inference, so a
	 * row that already carries a country is left alone entirely. Source 2 is
	 * what makes the column worth having: a repeat customer who abandons as a
	 * guest still has a phone on file from the order before.
	 *
	 * A row that already has a phone still needs the country lookup unless the
	 * number is written internationally, because a locally-typed number with
	 * the wrong country code in front of it is a link to a stranger. Both
	 * lookups are batched, so the page costs the same at 25 rows as at 1.
	 *
	 * @param array[] $rows Formatted rows, by reference.
	 */
	private static function resolve_contacts( array &$rows ) {
		$wanted = [];
		foreach ( $rows as $i => $row ) {
			$phone = trim( (string) $row['phone'] );
			// An international number carries its own country; nothing to find.
			if ( '' !== $phone && ( 0 === strpos( $phone, '+' ) || 0 === strpos( $phone, '00' ) ) ) {
				continue;
			}
			// Checkout already told us both. Guessing over a recorded answer is
			// exactly how a Turkish number ends up dialled as +1.
			if ( '' !== $phone && '' !== trim( (string) $row['phone_country'] ) ) {
				continue;
			}
			$wanted[ $i ] = $row;
		}
		if ( ! $wanted ) {
			return;
		}

		// 1. The account. cache_users() primes the meta cache for the whole
		//    page in one query, so get_user_meta() below hits memory.
		$user_ids = array_values( array_unique( array_filter( wp_list_pluck( $wanted, 'user_id' ) ) ) );
		if ( $user_ids ) {
			cache_users( $user_ids );
			foreach ( $wanted as $i => $row ) {
				$uid = (int) $row['user_id'];
				if ( ! $uid ) {
					continue;
				}
				$country = (string) get_user_meta( $uid, 'billing_country', true );
				// Never over-write what checkout actually recorded for this row.
				if ( '' !== trim( $country ) && '' === trim( (string) $rows[ $i ]['phone_country'] ) ) {
					$rows[ $i ]['phone_country'] = $country;
				}
				if ( '' !== trim( (string) $rows[ $i ]['phone'] ) ) {
					// Had a phone already; the country was all this row needed.
					unset( $wanted[ $i ] );
					continue;
				}
				$phone = (string) get_user_meta( $uid, 'billing_phone', true );
				if ( '' !== trim( $phone ) ) {
					$rows[ $i ]['phone']        = $phone;
					$rows[ $i ]['phone_source'] = 'account';
					unset( $wanted[ $i ] );
				}
			}
		}
		if ( ! $wanted ) {
			return;
		}

		// 2. The newest past order for the address, read as two flat queries -
		//    ids first, then the billing columns for exactly those ids. Loading
		//    order objects instead would be one query per row, which is the only
		//    part of this screen that would otherwise grow with the page size.
		$emails = array_values( array_unique( array_filter( array_map(
			static function ( $row ) {
				return strtolower( trim( (string) $row['email'] ) );
			},
			$wanted
		) ) ) );
		if ( ! $emails ) {
			return;
		}

		$order_ids = self::latest_order_ids_by_email( $emails );
		if ( ! $order_ids ) {
			return;
		}

		$billing = self::billing_by_order_id( array_values( $order_ids ) );

		foreach ( $wanted as $i => $row ) {
			$key = strtolower( trim( (string) $row['email'] ) );
			if ( empty( $order_ids[ $key ] ) ) {
				continue;
			}
			$found = $billing[ (int) $order_ids[ $key ] ] ?? null;
			if ( ! $found ) {
				continue;
			}

			if ( '' !== trim( $found['country'] ) && '' === trim( (string) $rows[ $i ]['phone_country'] ) ) {
				$rows[ $i ]['phone_country'] = $found['country'];
			}
			if ( '' !== trim( (string) $rows[ $i ]['phone'] ) ) {
				continue;
			}
			if ( '' !== trim( $found['phone'] ) ) {
				$rows[ $i ]['phone']        = $found['phone'];
				$rows[ $i ]['phone_source'] = 'order';
			}
		}
	}

	/**
	 * Billing phone + country for a set of order ids, in one query.
	 *
	 * Reads the storage directly rather than hydrating order objects: this runs
	 * once per admin page and the alternative costs a query per row. Only two
	 * scalar columns are wanted, and an order object would be built and thrown
	 * away to read them.
	 *
	 * @param int[] $order_ids
	 * @return array order id => { phone, country }
	 */
	private static function billing_by_order_id( array $order_ids ) {
		global $wpdb;

		$order_ids = array_values( array_unique( array_filter( array_map( 'intval', $order_ids ) ) ) );
		if ( ! $order_ids ) {
			return [];
		}

		$in   = implode( ', ', array_fill( 0, count( $order_ids ), '%d' ) );
		$out  = [];
		$hpos = class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();

		if ( $hpos ) {
			$rows = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				"SELECT order_id, phone, country
				   FROM {$wpdb->prefix}wc_order_addresses
				  WHERE address_type = 'billing' AND order_id IN ({$in})",
				$order_ids
			) );
			foreach ( (array) $rows as $r ) {
				$out[ (int) $r->order_id ] = [
					'phone'   => (string) $r->phone,
					'country' => (string) $r->country,
				];
			}
			return $out;
		}

		$rows = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			"SELECT post_id, meta_key, meta_value
			   FROM {$wpdb->postmeta}
			  WHERE meta_key IN ( '_billing_phone', '_billing_country' )
			    AND post_id IN ({$in})",
			$order_ids
		) );
		foreach ( (array) $rows as $r ) {
			$id = (int) $r->post_id;
			if ( ! isset( $out[ $id ] ) ) {
				$out[ $id ] = [ 'phone' => '', 'country' => '' ];
			}
			$field                = '_billing_phone' === $r->meta_key ? 'phone' : 'country';
			$out[ $id ][ $field ] = (string) $r->meta_value;
		}
		return $out;
	}

	/**
	 * Newest order id per billing email. HPOS and legacy post storage keep
	 * orders in different places, so this is the dual path.
	 *
	 * Ordered by id rather than date: on both storages the id is monotonic per
	 * order and already the primary key, and the caller only wants "the most
	 * recent one" to read a phone off.
	 *
	 * @param string[] $emails Lowercased addresses.
	 * @return array email => order id
	 */
	private static function latest_order_ids_by_email( array $emails ) {
		global $wpdb;

		$placeholders = implode( ', ', array_fill( 0, count( $emails ), '%s' ) );
		$hpos         = class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();

		if ( $hpos ) {
			$sql = "SELECT LOWER(billing_email) AS em, MAX(id) AS oid
			          FROM {$wpdb->prefix}wc_orders
			         WHERE type = 'shop_order' AND LOWER(billing_email) IN ({$placeholders})
			      GROUP BY LOWER(billing_email)";
		} else {
			$sql = "SELECT LOWER(pm.meta_value) AS em, MAX(pm.post_id) AS oid
			          FROM {$wpdb->postmeta} pm
			    INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			         WHERE pm.meta_key = '_billing_email'
			           AND p.post_type = 'shop_order'
			           AND LOWER(pm.meta_value) IN ({$placeholders})
			      GROUP BY LOWER(pm.meta_value)";
		}

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $emails ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		$out = [];
		foreach ( (array) $rows as $r ) {
			$out[ (string) $r->em ] = (int) $r->oid;
		}
		return $out;
	}

	/**
	 * Turn a stored phone number into the international digits WhatsApp needs,
	 * or '' when it cannot be dialled.
	 *
	 * The decision itself lives in brikpanel_phone_to_e164() (includes/
	 * brikpanel-helpers.php), shared with the Orders screen so the same customer
	 * can never get two different links. Passing an empty $country is meaningful
	 * there: it means "we never captured one", which lets the normaliser trust a
	 * number that already carries its own country code rather than putting the
	 * store's code in front of it.
	 *
	 * Nothing is sent from here: the link opens WhatsApp with the merchant's
	 * own account and a draft they can still edit or delete.
	 *
	 * @param string $phone   Raw stored number.
	 * @param string $country Two-letter billing country, '' when unknown.
	 * @return string Digits only (no "+"), or ''.
	 */
	public static function whatsapp_number( $phone, $country = '' ) {
		if ( ! function_exists( 'brikpanel_phone_to_e164' ) ) {
			return '';
		}
		return brikpanel_phone_to_e164( $phone, $country );
	}

	/**
	 * The draft message the WhatsApp button opens with.
	 *
	 * Deliberately a question and not an offer: it is the merchant typing, in
	 * their own voice, and a discount pasted in by us would undercut whatever
	 * the follow-up emails are already offering.
	 *
	 * @param array $row Formatted row.
	 * @return string
	 */
	public static function whatsapp_message( array $row ) {
		$name = trim( (string) $row['first_name'] );

		$values = [
			'{customer_name}' => $name,
			'{store_name}'    => get_bloginfo( 'name' ),
			// One product per line: a semicolon-joined run of four or five items
			// arrives as an unreadable paragraph on a phone screen.
			'{items}'         => self::items_summary( (array) ( $row['cart_items'] ?? [] ), "\n" ),
			// Always a figure, never empty: zero is a real answer here, and a
			// merchant who writes "Total: {cart_total}" would otherwise be left
			// with a dangling label rather than a dropped line - the line-drop
			// below only fires on a placeholder standing entirely alone.
			'{cart_total}'    => brikpanel_money_text( (float) ( $row['cart_total'] ?? 0 ), [ 'currency' => $row['currency'] ?? '' ] ),
			'{cart_url}'      => self::cart_page_url(),
			'{recovery_url}'  => self::cart_recovery_url( $row ),
		];

		$template = self::whatsapp_template();

		// A placeholder given a line of its own — the natural way to write
		// {items} — would leave a blank line behind when it resolves to
		// nothing. Drop the whole line instead of substituting emptiness into
		// it. Only a line that is *nothing but* the placeholder is removed, so a
		// merchant's own blank lines are left alone.
		foreach ( $values as $token => $value ) {
			if ( '' === $value ) {
				$template = preg_replace( '/^[ \t]*' . preg_quote( $token, '/' ) . '[ \t]*\R?/m', '', $template );
			}
		}

		$text = strtr( $template, $values );

		// A greeting written for a name has to survive not having one. Rather
		// than keep a second copy of every sentence, drop the placeholder and
		// tidy up after it, so "Hi {customer_name}, this is X" reads as
		// "Hi, this is X" instead of "Hi , this is X". Only commas are closed
		// up: French and other locales legitimately put a space before ; : ! ?
		// and a merchant's own spacing is not ours to rewrite.
		if ( in_array( '', $values, true ) ) {
			$text = preg_replace( '/[ \t]+([,،])/u', '$1', $text );
			$text = preg_replace( '/[ \t]{2,}/', ' ', $text );
		}

		/**
		 * Filter the pre-filled WhatsApp draft for an abandoned cart.
		 *
		 * @param string $text
		 * @param array  $row  Formatted cart row.
		 */
		return (string) apply_filters( 'brikpanel_cartab_whatsapp_message', trim( $text ), $row );
	}

	/**
	 * The message template the draft is built from: whatever the merchant
	 * wrote, or a translatable default. Kept as a __() string so a store that
	 * never opens the setting still gets the message in its own language.
	 *
	 * @return string
	 */
	public static function whatsapp_template() {
		$saved = get_option( 'brikpanel_cartab_whatsapp_template', '' );
		if ( is_string( $saved ) && '' !== trim( $saved ) ) {
			// Browsers post textarea line breaks as CRLF. WhatsApp only needs
			// the LF, and a stray CR survives URL-encoding as a visible %0D.
			return str_replace( [ "\r\n", "\r" ], "\n", $saved );
		}
		return __( 'Hi {customer_name}, this is {store_name}. You left a few items in your cart - can I help you finish the order? {recovery_url}', 'brikpanel' );
	}

	/** The store's cart page, or '' when WooCommerce cannot resolve one. */
	private static function cart_page_url() {
		if ( ! function_exists( 'wc_get_cart_url' ) ) {
			return '';
		}
		return esc_url_raw( (string) wc_get_cart_url() );
	}

	/**
	 * A link that puts this exact cart back together for the shopper - the same
	 * one-click restore the Share cart feature builds, so a customer opening it
	 * on another phone still lands on a full cart rather than an empty one.
	 * Falls back to the plain cart page whenever a restore link cannot be made.
	 *
	 * @param array $row Formatted cart row.
	 * @return string
	 */
	private static function cart_recovery_url( array $row ) {
		$items = (array) ( $row['cart_items'] ?? [] );

		if ( $items && class_exists( 'Brikpanel_Cart_Share' ) && Brikpanel_Cart_Share::is_enabled() ) {
			$share = [];
			foreach ( $items as $item ) {
				$product_id = isset( $item['product_id'] ) ? (int) $item['product_id'] : 0;
				if ( $product_id <= 0 ) {
					continue;
				}
				$share[] = [
					'product_id' => $product_id,
					'quantity'   => max( 1, (int) round( (float) ( $item['qty'] ?? 1 ) ) ),
					// Carried through so a variable product comes back as the
					// variation they picked, not the first one on the page.
					'variation_id' => isset( $item['variation_id'] ) ? (int) $item['variation_id'] : 0,
				];
			}
			// The link's own reader stops at 100 items, so there is nothing to
			// gain by writing more than that into the URL.
			$share = array_slice( $share, 0, 100 );
			$link  = $share ? (string) Brikpanel_Cart_Share::build_link( $share ) : '';
			if ( '' !== $link ) {
				return esc_url_raw( $link );
			}
		}

		return self::cart_page_url();
	}

	/**
	 * Attach the BrikMentor-only columns to one page of rows: a usable phone
	 * number, the WhatsApp draft, and how many reminders this cart has had.
	 *
	 * Every user-facing string is composed here rather than in the browser, so
	 * the plural forms and the date format follow the site's locale instead of
	 * being glued together from fragments in JS.
	 *
	 * @param array[] $items       Rows, by reference.
	 * @param string  $date_format Site date+time format.
	 */
	private function add_outreach( array &$items, $date_format ) {
		self::resolve_contacts( $items );

		/**
		 * Filter: how many follow-up emails has each cart row had?
		 *
		 * BrikMentor answers this. Providers add
		 * entry_id => { sent, pending, next, last } with GMT datetimes.
		 *
		 * @param array $stats     entry_id => stats.
		 * @param int[] $entry_ids Cart row ids on the visible page.
		 */
		$stats = (array) apply_filters(
			'brikpanel_cartab_message_stats',
			[],
			wp_list_pluck( $items, 'id' )
		);

		foreach ( $items as &$row ) {
			$row['wa_number'] = self::whatsapp_number( $row['phone'], $row['phone_country'] );
			$row['wa_text']   = '' !== $row['wa_number'] ? self::whatsapp_message( $row ) : '';

			// Spell out the number the link will actually dial. A phone typed
			// without a country code has one guessed for it, and this is where
			// the merchant sees which one - a shopper abroad at a store whose
			// customers are mostly local is the one case the guess gets wrong,
			// and it is theirs to catch, not ours to hide. When even the country
			// was a guess (nothing recorded for this shopper, so the store's own
			// country stood in), say so outright.
			if ( '' === $row['wa_number'] ) {
				$row['wa_title'] = '';
			} elseif ( '' === trim( (string) $row['phone_country'] ) ) {
				$row['wa_title'] = sprintf(
					/* translators: %s: full international phone number the link opens. */
					__( 'Message on WhatsApp: +%s (country guessed from your store address)', 'brikpanel' ),
					$row['wa_number']
				);
			} else {
				$row['wa_title'] = sprintf(
					/* translators: %s: full international phone number the link opens. */
					__( 'Message on WhatsApp: +%s', 'brikpanel' ),
					$row['wa_number']
				);
			}

			$stat    = $stats[ (int) $row['id'] ] ?? [];
			$sent    = (int) ( $stat['sent'] ?? 0 );
			$pending = (int) ( $stat['pending'] ?? 0 );

			$note = '';
			if ( $pending > 0 && ! empty( $stat['next'] ) ) {
				$note = sprintf(
					/* translators: %s: date and time of the next scheduled reminder. */
					__( 'Next: %s', 'brikpanel' ),
					wp_date( $date_format, strtotime( $stat['next'] . ' +00:00' ) )
				);
			} elseif ( $sent > 0 && ! empty( $stat['last'] ) ) {
				$note = sprintf(
					/* translators: %s: date and time of the last reminder sent. */
					__( 'Last: %s', 'brikpanel' ),
					wp_date( $date_format, strtotime( $stat['last'] . ' +00:00' ) )
				);
			}

			if ( $sent > 0 ) {
				$text = sprintf(
					/* translators: %s: number of follow-up emails already sent. */
					_n( '%s email sent', '%s emails sent', $sent, 'brikpanel' ),
					number_format_i18n( $sent )
				);
			} elseif ( $pending > 0 ) {
				$text = __( 'Scheduled', 'brikpanel' );
			} else {
				$text = '';
			}

			$row['mail'] = [
				'sent'    => $sent,
				'pending' => $pending,
				'text'    => $text,
				'note'    => $note,
			];
		}
		unset( $row );
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
		// Phone / WhatsApp / Follow-ups ride along with BrikMentor; without it
		// those columns are not defined at all.
		$outreach = self::mentor_active();

		// Column definition plus this user's saved order and visibility. The
		// header is rendered from the resolved order; the body is rendered by
		// the same order client-side, so the two can never drift apart.
		$column_defs  = self::get_column_defs();
		$column_prefs = self::get_user_columns();
		$column_order = $column_prefs['order'];
		$column_vis   = $column_prefs['visible'];

		// Hidden columns are switched off with an attribute on the table so a
		// toggle costs one attribute write instead of a re-render.
		// Ids are slug-validated in get_column_defs(), so they are safe in an
		// attribute-name position, where escaping would not help.
		$hide_attrs = '';
		foreach ( $column_vis as $col_id => $is_visible ) {
			if ( ! $is_visible ) {
				$hide_attrs .= ' data-hide-' . $col_id . '="1"';
			}
		}

		$sort_options  = self::sort_options();
		$range_options = self::date_range_options();

		// Chevron shared by every select in the filter bar. Inlined once as a
		// closure so the markup below stays readable.
		$select_arrow = static function () {
			echo '<svg class="brikpanel-cartab-select-arrow" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><polyline points="6 9 12 15 18 9"/></svg>';
		};
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
					<a class="brikpanel-cartab-btn brikpanel-cartab-btn-primary" href="<?php echo esc_url( $settings_url ); ?>">
						<?php esc_html_e( 'Settings', 'brikpanel' ); ?>
					</a>
				</div>
			</div>

			<?php
			/**
			 * Fires directly under the Abandoned Carts header. Used to render the
			 * dismissible BrikMentor early-access card.
			 *
			 * @since 3.2.13
			 */
			do_action( 'brikpanel_cartab_after_header' );
			?>

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
					<div class="brikpanel-cartab-search">
						<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
						<input type="search" id="brikpanel-cartab-search"
							placeholder="<?php esc_attr_e( 'Search email or name…', 'brikpanel' ); ?>"
							aria-label="<?php esc_attr_e( 'Search email or name', 'brikpanel' ); ?>" />
					</div>

					<div class="brikpanel-cartab-select">
						<select id="brikpanel-cartab-status" aria-label="<?php esc_attr_e( 'Filter by status', 'brikpanel' ); ?>">
							<option value=""><?php esc_html_e( 'All statuses', 'brikpanel' ); ?></option>
							<?php foreach ( self::display_status_labels() as $key => $label ) : ?>
								<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
						<?php $select_arrow(); ?>
					</div>

					<div class="brikpanel-cartab-select">
						<select id="brikpanel-cartab-source" aria-label="<?php esc_attr_e( 'Filter by source', 'brikpanel' ); ?>">
							<option value=""><?php esc_html_e( 'All sources', 'brikpanel' ); ?></option>
							<?php foreach ( self::source_labels() as $key => $label ) : ?>
								<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
						<?php $select_arrow(); ?>
					</div>

					<div class="brikpanel-cartab-select brikpanel-cartab-select-icon">
						<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><rect x="3" y="4" width="18" height="17" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
						<select id="brikpanel-cartab-range" aria-label="<?php esc_attr_e( 'Filter by date range', 'brikpanel' ); ?>">
							<?php foreach ( $range_options as $range_key => $range_label ) : ?>
								<option value="<?php echo esc_attr( $range_key ); ?>"><?php echo esc_html( $range_label ); ?></option>
							<?php endforeach; ?>
						</select>
						<?php $select_arrow(); ?>
					</div>

					<div class="brikpanel-cartab-select brikpanel-cartab-select-icon">
						<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><polyline points="3 7 7 3 11 7"/><line x1="7" y1="3" x2="7" y2="16"/><polyline points="13 17 17 21 21 17"/><line x1="17" y1="21" x2="17" y2="8"/></svg>
						<select id="brikpanel-cartab-sort" aria-label="<?php esc_attr_e( 'Sort by', 'brikpanel' ); ?>">
							<?php foreach ( $sort_options as $sort_key => $sort_label ) : ?>
								<option value="<?php echo esc_attr( $sort_key ); ?>" <?php selected( $sort_key, self::DEFAULT_SORT ); ?>>
									<?php echo esc_html( $sort_label ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<?php $select_arrow(); ?>
					</div>

					<button type="button" class="brikpanel-cartab-clear" id="brikpanel-cartab-clear" hidden>
						<?php esc_html_e( 'Clear', 'brikpanel' ); ?>
					</button>

					<div class="brikpanel-cartab-filter-actions">
						<div class="brikpanel-cartab-columns-menu">
							<button type="button" class="brikpanel-cartab-btn brikpanel-cartab-btn-secondary"
								id="brikpanel-cartab-columns-btn" aria-haspopup="true" aria-expanded="false"
								aria-controls="brikpanel-cartab-columns-popover">
								<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><rect x="3" y="3" width="18" height="18" rx="2"/><line x1="9" y1="3" x2="9" y2="21"/><line x1="15" y1="3" x2="15" y2="21"/></svg>
								<?php esc_html_e( 'Columns', 'brikpanel' ); ?>
							</button>
							<div class="brikpanel-cartab-columns-popover" id="brikpanel-cartab-columns-popover" role="group"
								aria-label="<?php esc_attr_e( 'Show, hide and reorder table columns', 'brikpanel' ); ?>" hidden>
								<p class="brikpanel-cartab-columns-hint"><?php esc_html_e( 'Drag to reorder, or hold Alt and press the arrow keys.', 'brikpanel' ); ?></p>
								<div class="brikpanel-cartab-columns-list" id="brikpanel-cartab-columns-list">
									<?php foreach ( $column_order as $col_id ) :
										$locked = ! empty( $column_defs[ $col_id ]['locked'] ); ?>
										<div class="brikpanel-cartab-columns-item<?php echo $locked ? ' is-locked' : ''; ?>"
											data-col="<?php echo esc_attr( $col_id ); ?>"
											draggable="true" tabindex="0"
											aria-label="<?php
												/* translators: %s: table column name. */
												echo esc_attr( sprintf( __( 'Reorder column: %s', 'brikpanel' ), $column_defs[ $col_id ]['label'] ) );
											?>">
											<span class="brikpanel-cartab-columns-handle" aria-hidden="true">
												<svg width="12" height="12" viewBox="0 0 24 24" fill="currentColor" focusable="false"><circle cx="9" cy="5" r="1.8"/><circle cx="15" cy="5" r="1.8"/><circle cx="9" cy="12" r="1.8"/><circle cx="15" cy="12" r="1.8"/><circle cx="9" cy="19" r="1.8"/><circle cx="15" cy="19" r="1.8"/></svg>
											</span>
											<label class="brikpanel-cartab-columns-label">
												<input type="checkbox" data-col="<?php echo esc_attr( $col_id ); ?>"
													<?php checked( ! empty( $column_vis[ $col_id ] ) ); ?>
													<?php disabled( $locked ); ?> />
												<span><?php echo esc_html( $column_defs[ $col_id ]['label'] ); ?></span>
											</label>
										</div>
									<?php endforeach; ?>
								</div>
							</div>
						</div>
					</div>
				</div>

				<!-- Only revealed by the "Custom range" preset. -->
				<div class="brikpanel-cartab-custom-range" id="brikpanel-cartab-custom-range" hidden>
					<label for="brikpanel-cartab-from"><?php esc_html_e( 'From', 'brikpanel' ); ?></label>
					<input type="date" id="brikpanel-cartab-from" />
					<span class="brikpanel-cartab-range-sep" aria-hidden="true">&rarr;</span>
					<label for="brikpanel-cartab-to"><?php esc_html_e( 'To', 'brikpanel' ); ?></label>
					<input type="date" id="brikpanel-cartab-to" />
				</div>
			</div>

			<!-- Table -->
			<div class="brikpanel-cartab-card brikpanel-cartab-table-card">
				<div class="brikpanel-cartab-table-wrap">
					<table class="brikpanel-cartab-table" id="brikpanel-cartab-table"<?php echo $hide_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built above from esc_attr'd column ids ?>>
						<thead>
							<tr id="brikpanel-cartab-thead-row">
								<?php foreach ( $column_order as $col_id ) : ?>
									<th class="brikpanel-cartab-col-<?php echo esc_attr( $col_id ); ?>" data-col="<?php echo esc_attr( $col_id ); ?>">
										<?php echo esc_html( $column_defs[ $col_id ]['label'] ); ?>
									</th>
								<?php endforeach; ?>
								<th class="brikpanel-cartab-actions-th"></th>
							</tr>
						</thead>
						<tbody id="brikpanel-cartab-tbody">
							<tr><td colspan="<?php echo esc_attr( count( array_filter( $column_vis ) ) + 1 ); ?>" class="brikpanel-cartab-empty"><?php esc_html_e( 'Loading…', 'brikpanel' ); ?></td></tr>
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
			statuses: <?php echo wp_json_encode( self::display_status_labels() ); ?>,
			sources:  <?php echo wp_json_encode( self::source_labels() ); ?>,
			outreach: <?php echo wp_json_encode( $outreach ); ?>,
			// Resolved column order + visibility for this user. The body cells
			// are built from columnOrder, so the header and the rows always
			// agree, including after a drag-and-drop reorder.
			columnOrder: <?php echo wp_json_encode( array_values( $column_order ) ); ?>,
			columnVisible: <?php echo wp_json_encode( (object) $column_vis ); ?>,
			defaultSort: <?php echo wp_json_encode( self::DEFAULT_SORT ); ?>,
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
				whatsapp:       <?php echo wp_json_encode( __( 'Message on WhatsApp', 'brikpanel' ) ); ?>,
				no_phone:       <?php echo wp_json_encode( __( 'No phone number on file.', 'brikpanel' ) ); ?>,
				phone_account:  <?php echo wp_json_encode( __( 'From their account', 'brikpanel' ) ); ?>,
				phone_order:    <?php echo wp_json_encode( __( 'From a past order', 'brikpanel' ) ); ?>,
				no_followups:   <?php echo wp_json_encode( __( 'No reminders sent.', 'brikpanel' ) ); ?>,
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
			// The list filter speaks display statuses (the dropdown offers
			// "Email only" and "Converted" alongside the stored three), so it
			// goes in as display_status. The raw 'status' arg stays free for
			// brikpanel_cartab_get_entries() callers.
			'display_status' => isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : '',
			'source' => isset( $_POST['source'] ) ? sanitize_key( wp_unslash( $_POST['source'] ) ) : '',
			'search' => isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '',
			'from'   => isset( $_POST['from'] ) ? sanitize_text_field( wp_unslash( $_POST['from'] ) ) : '',
			'to'     => isset( $_POST['to'] ) ? sanitize_text_field( wp_unslash( $_POST['to'] ) ) : '',
			'range'  => isset( $_POST['range'] ) ? sanitize_key( wp_unslash( $_POST['range'] ) ) : '',
			'sort'   => isset( $_POST['sort'] ) ? sanitize_key( wp_unslash( $_POST['sort'] ) ) : '',
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
			$row['total_h'] = brikpanel_money_text( $row['cart_total'], [ 'currency' => $row['currency'] ] );
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
			$row['phone_source']  = '';
			// Keep whatever checkout recorded; only older rows need the default.
			if ( ! isset( $row['phone_country'] ) ) {
				$row['phone_country'] = '';
			}
			$items[]              = $row;
		}

		if ( self::mentor_active() ) {
			$this->add_outreach( $items, $date_format );
		}

		// Status breakdown + cart value per status for the stat cards
		// (unfiltered, whole table). Cart totals are stored in the currency
		// that was active when the cart was captured, so they are summed per
		// currency and rendered side by side instead of blindly added up.
		//
		// The cards group by *display* status, not the stored one: "Active
		// carts" must not count bare email signups with an empty cart, and
		// "Recovered" must not count a cart that was bought without ever
		// having been abandoned, nor the extra rows closed alongside the one
		// order actually credited. See display_status_where().
		global $wpdb;
		$table   = self::table();
		$counts  = [ 'total' => 0, 'active' => 0, 'abandoned' => 0, 'recovered' => 0 ];
		$by_curr = [ 'total' => [], 'active' => [], 'abandoned' => [], 'recovered' => [] ];
		$rows    = $wpdb->get_results(
			"SELECT status,
			        (item_count > 0)           AS has_items,
			        (abandoned_at IS NOT NULL) AS was_abandoned,
			        (order_id > 0)             AS has_order,
			        currency,
			        COUNT(*) AS c, SUM(cart_total) AS amount
			 FROM {$table}
			 GROUP BY status, has_items, was_abandoned, has_order, currency" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
		foreach ( $rows as $r ) {
			$status = self::display_status_from_flags( $r->status, (bool) $r->has_items, (bool) $r->was_abandoned, (bool) $r->has_order );
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

	/**
	 * Persist the current user's column visibility and column order.
	 *
	 * Both arrays are rebuilt from the definition rather than trusted as sent,
	 * so unknown ids are dropped and locked columns cannot be switched off.
	 */
	public function ajax_save_columns() {
		$this->check_auth();

		$defs = self::get_column_defs();

		$raw_visible = isset( $_POST['visible'] ) && is_array( $_POST['visible'] )
			? wp_unslash( $_POST['visible'] )
			: [];
		$raw_order = isset( $_POST['order'] ) && is_array( $_POST['order'] )
			? wp_unslash( $_POST['order'] )
			: [];

		$order = [];
		foreach ( $raw_order as $id ) {
			$id = sanitize_key( (string) $id );
			if ( isset( $defs[ $id ] ) && ! in_array( $id, $order, true ) ) {
				$order[] = $id;
			}
		}
		foreach ( $defs as $id => $def ) {
			if ( ! in_array( $id, $order, true ) ) {
				$order[] = $id;
			}
		}

		$visible = [];
		foreach ( $defs as $id => $def ) {
			if ( ! empty( $def['locked'] ) ) {
				continue; // never stored; always resolved to visible on read
			}
			$value        = isset( $raw_visible[ $id ] ) ? (string) $raw_visible[ $id ] : '';
			$visible[ $id ] = ( '' !== $value && '0' !== $value && 'false' !== $value );
		}

		update_user_meta(
			get_current_user_id(),
			self::USER_COLUMNS_META,
			[ 'visible' => $visible, 'order' => $order ]
		);

		wp_send_json_success( self::get_user_columns() );
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
			// display_status, matching ajax_list(): the status dropdown this URL
			// mirrors speaks display statuses, so reading it as the raw 'status'
			// would quietly export a different set of rows than the screen shows.
			'display_status' => isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '',
			'source' => isset( $_GET['source'] ) ? sanitize_key( wp_unslash( $_GET['source'] ) ) : '',
			'search' => isset( $_GET['search'] ) ? sanitize_text_field( wp_unslash( $_GET['search'] ) ) : '',
			'from'   => isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : '',
			'to'     => isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( $_GET['to'] ) ) : '',
			// Mirror the range and sort shown on screen so the file matches the list.
			'range'  => isset( $_GET['range'] ) ? sanitize_key( wp_unslash( $_GET['range'] ) ) : '',
			'sort'   => isset( $_GET['sort'] ) ? sanitize_key( wp_unslash( $_GET['sort'] ) ) : '',
		];

		// Same wording as the list on screen, so an exported "Recovered" means
		// what the Recovered card counted.
		$statuses = self::display_status_labels();
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
				$statuses[ $row['display_status'] ] ?? $row['display_status'],
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
			'title'             => __( 'Count as recovered within', 'brikpanel' ),
			'desc'              => __( 'days', 'brikpanel' ),
			'desc_tip'          => __( 'An order only counts as a recovery when the matching cart was last active within this many days. Older carts are left untouched, so a customer coming back a month later is recorded as a new sale rather than a recovered cart.', 'brikpanel' ),
			'id'                => 'brikpanel_cartab_recovery_window_days',
			'type'              => 'number',
			'default'           => '7',
			'custom_attributes' => [ 'min' => 1, 'step' => 1 ],
			'css'               => 'width:90px;',
		];
		$fields[] = [
			'title'       => __( 'WhatsApp message', 'brikpanel' ),
			'desc'        => sprintf(
				/* translators: %s: comma-separated list of placeholder tags, e.g. {customer_name}, {store_name}. */
				__( 'Placeholders: %s', 'brikpanel' ),
				'{customer_name}, {store_name}, {cart_url}, {recovery_url}, {items}, {cart_total}'
			),
			'desc_tip'    => __( 'Opens as an editable draft in WhatsApp, so nothing is sent until you press send. Write it in any language, and use line breaks freely - they are kept. {items} lists every product on its own line, so give it a line of its own. {recovery_url} rebuilds the shopper\'s cart in one tap; {cart_url} is just your cart page.', 'brikpanel' ),
			'id'          => 'brikpanel_cartab_whatsapp_template',
			'type'        => 'textarea',
			'placeholder' => __( 'Hi {customer_name}, this is {store_name}. You left a few items in your cart - can I help you finish the order? {recovery_url}', 'brikpanel' ),
			'css'         => 'width:340px;height:90px;',
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
		static function () {
			return [
				'label'       => __( 'Mark abandoned carts', 'brikpanel' ),
				'description' => __( 'Flips inactive captured carts to abandoned and notifies integrations.', 'brikpanel' ),
			];
		}
	);

	Brikpanel_Cron::schedule_recurring( 'brikpanel_cartab_flip_abandoned', 10 * MINUTE_IN_SECONDS );
} );
