<?php
/**
 * BrikPanel Customers: the Customers page.
 *
 * One page for everything about customers, in the sidebar right under Orders:
 *   All customers     the customer list (the Customers engine, with Segments'
 *                     presets, filters, saved segments and CSV export);
 *   RFM segments, Lifetime value, Cohort retention
 *                     the three tabs of the old Customer Analytics screen;
 *   ?customer=<key>   one customer's page, registered or guest.
 * It took the sidebar places of WooCommerce's Customers report (which stays
 * under More as "Customers report"), Segments and Customer Analytics.
 *
 * @package BrikPanel
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Brikpanel_Customers {

	const PAGE_SLUG = 'brikpanel-customers';

	/** Tabs, in strip order. */
	const TABS = [ 'all', 'rfm', 'ltv', 'cohort' ];

	/** @var array|null|false The customer page's data: false until loaded. */
	private static $profile = false;

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'register_page' ] );
		// The retired Customer Analytics address opens its tab here. WordPress
		// decides "no such page" before admin_init, so this is the one hook
		// that still runs; priority 4, before the module-off card (5).
		add_action( 'admin_page_access_denied', [ $this, 'redirect_retired_page' ], 4 );
	}

	/**
	 * The tab the request asks for.
	 *
	 * @return string
	 */
	public static function tab() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view switch.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'all';
		return in_array( $tab, self::TABS, true ) ? $tab : 'all';
	}

	/**
	 * Address of a tab, with extra query arguments.
	 *
	 * @param string $tab  Tab key.
	 * @param array  $args Extra arguments (already plain values).
	 * @return string
	 */
	public static function url( $tab = 'all', array $args = [] ) {
		$query = [ 'page' => self::PAGE_SLUG ];
		if ( 'all' !== $tab && in_array( $tab, self::TABS, true ) ) {
			$query['tab'] = $tab;
		}
		return add_query_arg( array_merge( $query, $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * Whether this request is a customer's page (?customer=…).
	 *
	 * @return bool
	 */
	public static function is_profile_request() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view switch.
		return isset( $_GET['customer'] );
	}

	/**
	 * The customer page's data, loaded once per request; null when the key is
	 * malformed or the person is not this site's customer.
	 *
	 * @return array|null
	 */
	public static function profile() {
		if ( false === self::$profile ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view; the key is validated by parse_key().
			$raw           = isset( $_GET['customer'] ) ? (string) wp_unslash( $_GET['customer'] ) : '';
			self::$profile = Brikpanel_Customer_Profile::load( $raw );
		}
		return self::$profile;
	}

	public function register_page() {
		$hook = add_menu_page(
			__( 'Customers', 'brikpanel' ),
			__( 'Customers', 'brikpanel' ),
			'manage_woocommerce',
			self::PAGE_SLUG,
			[ $this, 'render_page' ],
			'dashicons-groups',
			// Right after WooCommerce (55.5) when the modern sidebar is off; the
			// modern sidebar pins it under Orders (brikpanel_nav_apply_store_order()).
			55.6
		);
		if ( $hook ) {
			add_action( 'load-' . $hook, [ $this, 'on_load' ] );
		}
	}

	/**
	 * Page title before admin-header.php prints it.
	 *
	 * @return void
	 */
	public function on_load() {
		$GLOBALS['title'] = __( 'Customers', 'brikpanel' );
		if ( self::is_profile_request() ) {
			$profile = self::profile();
			if ( $profile ) {
				$GLOBALS['title'] = (string) $profile['name'];
			}
		}
	}

	public function render_page() {
		if ( self::is_profile_request() ) {
			$profile = self::profile();
			include __DIR__ . '/views/profile.php';
			return;
		}
		$bp_cus_tab      = self::tab();
		$currency_symbol = function_exists( 'get_woocommerce_currency_symbol' ) ? get_woocommerce_currency_symbol() : '$';
		include __DIR__ . '/views/page.php';
	}

	/**
	 * Old Customer Analytics address → its Lifetime value tab here.
	 *
	 * @return void
	 */
	public function redirect_retired_page() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing on the requested page slug.
		$slug = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( 'brikpanel-customer-analytics' !== $slug ) {
			return;
		}
		if ( is_network_admin() || is_user_admin() || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		if ( function_exists( 'brikpanel_user_can_access' ) && ! brikpanel_user_can_access() ) {
			return;
		}
		wp_safe_redirect( self::url( 'ltv' ) );
		exit;
	}
}

new Brikpanel_Customers();
