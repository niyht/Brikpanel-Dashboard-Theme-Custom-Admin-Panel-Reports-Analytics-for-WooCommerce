<?php
/**
 * BrikPanel - Segments
 *
 * Advanced filtering / segmentation UI for orders and customers.
 * Lets shop managers narrow orders and customers by spending range,
 * product, location, date, status, payment method, and more — with
 * CSV export.
 *
 * @package BrikPanel
 * @since 2.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Saved segments are filters naming this store's own products, categories and
 * payment methods by id, meaningless on another store: they never travel with
 * a settings export. Registered here, above the class, so the export knows the
 * key even while the Segments screen is switched off.
 *
 * @param array $map Registry so far.
 * @return array
 */
add_filter( 'brikpanel_exportable_option_keys', 'brikpanel_segments_register_export_keys' );
function brikpanel_segments_register_export_keys( $map ) {
	$map['brikpanel_segments_saved'] = [ 'class' => 'site' ];
	return $map;
}

class Brikpanel_Segments {

	const PAGE_SLUG   = 'brikpanel-segments';
	const NONCE_ACTION = 'brikpanel_segments_nonce';
	const PAGE_SIZE   = 25;

	/** Option holding the segments people saved, shared by everyone who opens Segments. */
	const SAVED_OPTION = 'brikpanel_segments_saved';

	/** Saved segments kept per tab. */
	const SAVED_MAX = 30;

	/** @var bool|null Cached HPOS detection. */
	private $is_hpos = null;

	public function __construct() {
		// The switch hides this screen and nothing else. The Customers page
		// reads its customer list through the same AJAX actions below, and
		// every one of them still checks the nonce and manage_woocommerce.
		if ( get_option( 'brikpanel_modern_segments', 'yes' ) === 'yes' ) {
			// After WooCommerce builds its menu: the screen goes under it.
			add_action( 'admin_menu', [ $this, 'register_page' ], 30 );
			// Its way in: a link after the orders list's filters.
			add_action( 'woocommerce_order_list_table_extra_tablenav', [ $this, 'print_orders_list_link_hpos' ], 10, 2 );
			add_action( 'manage_posts_extra_tablenav', [ $this, 'print_orders_list_link_legacy' ] );
		}

		add_action( 'wp_ajax_brikpanel_segments_filter_options', [ $this, 'ajax_filter_options' ] );
		add_action( 'wp_ajax_brikpanel_segments_query_orders', [ $this, 'ajax_query_orders' ] );
		add_action( 'wp_ajax_brikpanel_segments_query_customers', [ $this, 'ajax_query_customers' ] );
		add_action( 'wp_ajax_brikpanel_segments_export', [ $this, 'ajax_export_csv' ] );
		add_action( 'wp_ajax_brikpanel_segments_search_products', [ $this, 'ajax_search_products' ] );
		add_action( 'wp_ajax_brikpanel_segments_save_segment', [ $this, 'ajax_save_segment' ] );
		add_action( 'wp_ajax_brikpanel_segments_delete_segment', [ $this, 'ajax_delete_segment' ] );
	}

	// =========================================================================
	// HPOS
	// =========================================================================

	private function is_hpos() {
		if ( $this->is_hpos === null ) {
			$this->is_hpos = get_option( 'woocommerce_custom_orders_table_enabled' ) === 'yes';
		}
		return $this->is_hpos;
	}

	// =========================================================================
	// PAGE REGISTRATION
	// =========================================================================

	/**
	 * The Orders explorer: a screen under WooCommerce's menu with no sidebar
	 * row of its own (Orders stays highlighted, brikpanel_screen_menu_current_row()),
	 * opened from the Orders list. Its address keeps the old Segments slug, so
	 * links and bookmarks to Segments still open the order list.
	 *
	 * @return void
	 */
	public function register_page() {
		$title = __( 'Orders explorer', 'brikpanel' );
		$hooks = function_exists( 'brikpanel_add_screen_page' )
			? brikpanel_add_screen_page( 'woocommerce', $title, 'manage_woocommerce', self::PAGE_SLUG, [ $this, 'render_page' ] )
			: array_filter( [ add_submenu_page( '', $title, '', 'manage_woocommerce', self::PAGE_SLUG, [ $this, 'render_page' ] ) ] );

		foreach ( $hooks as $hook ) {
			add_action( 'load-' . $hook, static function () {
				// Set before admin-header.php runs, or WordPress strips a null title.
				$GLOBALS['title'] = __( 'Orders explorer', 'brikpanel' );
			} );
		}
	}

	// =========================================================================
	// LINK FROM THE ORDERS LIST
	// =========================================================================

	/**
	 * "Orders explorer" after the Filter button of WooCommerce's order list
	 * (HPOS). The screen has no sidebar row, so this link is how people find
	 * it. BrikPanel's own list moves it next to the search button
	 * (brikpanelOrdersExplorerLink() in brikpanel-orders.js).
	 *
	 * @param string $order_type Order type of the list.
	 * @param string $which      'top' or 'bottom'.
	 * @return void
	 */
	public function print_orders_list_link_hpos( $order_type, $which ) {
		if ( 'shop_order' === $order_type && 'top' === $which ) {
			$this->print_orders_list_link( false );
		}
	}

	/**
	 * The same link on the order list of posts storage, where the hook fires
	 * after the filters' box closes: it gets a box of its own.
	 *
	 * @param string $which 'top' or 'bottom'.
	 * @return void
	 */
	public function print_orders_list_link_legacy( $which ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( 'top' === $which && $screen && 'edit-shop_order' === $screen->id ) {
			$this->print_orders_list_link( true );
		}
	}

	/**
	 * Printed only for someone who can open the screen: not while it is off,
	 * nor for a user BrikPanel or its access rules keep out of it.
	 *
	 * @param bool $own_box Wrap the link in a box of the list's filter row.
	 * @return void
	 */
	private function print_orders_list_link( $own_box ) {
		$url = function_exists( 'brikpanel_module_url' ) ? brikpanel_module_url( self::PAGE_SLUG, [], '', false ) : '';
		if ( '' === $url ) {
			return;
		}
		if ( function_exists( 'brikpanel_nav_url_blocked_for_current_user' ) && brikpanel_nav_url_blocked_for_current_user( $url ) ) {
			return;
		}
		$link = sprintf(
			'<a class="button bp-orders-explorer" href="%1$s">%2$s</a>',
			esc_url( $url ),
			esc_html__( 'Orders explorer', 'brikpanel' )
		);
		if ( $own_box ) {
			$link = '<div class="alignleft actions bp-orders-explorer-wrap">' . $link . '</div>';
		}
		echo $link; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from esc_url() and esc_html__() above.
	}

	// =========================================================================
	// RENDER PAGE
	// =========================================================================

	public function render_page() {
		$currency_symbol = function_exists( 'get_woocommerce_currency_symbol' ) ? get_woocommerce_currency_symbol() : '$';
		include __DIR__ . '/views/page.php';
	}

	// =========================================================================
	// SECURITY
	// =========================================================================

	private function check_auth() {
		check_ajax_referer( self::NONCE_ACTION, '_ajax_nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'brikpanel' ) ], 403 );
		}
	}

	// =========================================================================
	// AJAX: filter option lookups (statuses, countries, payment methods, categories)
	// =========================================================================

	public function ajax_filter_options() {
		$this->check_auth();

		$order_statuses = [];
		if ( function_exists( 'wc_get_order_statuses' ) ) {
			foreach ( wc_get_order_statuses() as $slug => $label ) {
				// The block checkout's draft is never listed (query_orders()),
				// so a "Draft" choice could only ever show an empty list.
				if ( 'wc-checkout-draft' === $slug ) {
					continue;
				}
				$order_statuses[] = [
					'value' => preg_replace( '/^wc-/', '', $slug ),
					'label' => $label,
				];
			}
		}

		$payment_methods = [];
		if ( function_exists( 'WC' ) ) {
			$gateways = WC()->payment_gateways()->payment_gateways();
			foreach ( $gateways as $id => $gw ) {
				$payment_methods[] = [
					'value' => $id,
					'label' => $gw->get_method_title() ?: $id,
				];
			}
		}

		$countries = [];
		if ( class_exists( 'WC_Countries' ) ) {
			$wc_countries = new WC_Countries();
			foreach ( $wc_countries->get_countries() as $code => $name ) {
				$countries[] = [
					'value' => $code,
					// WooCommerce's list holds entities ("Cura&ccedil;ao").
					'label' => brikpanel_plain_name( $name ),
				];
			}
		}

		$categories = [];
		$terms = get_terms( [
			'taxonomy'   => 'product_cat',
			'hide_empty' => false,
			'number'     => 500,
		] );
		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $term ) {
				$categories[] = [
					'value' => (int) $term->term_id,
					'label' => brikpanel_plain_name( $term->name ),
				];
			}
		}

		// RFM segments — sourced from the canonical labels function so
		// the dropdown order + colors match the Customer Analytics page.
		$rfm_segments = [];
		if ( function_exists( 'brikpanel_ca_rfm_segment_labels' ) ) {
			foreach ( brikpanel_ca_rfm_segment_labels() as $key => $meta ) {
				$rfm_segments[] = [
					'value' => $key,
					'label' => $meta['label'],
					'color' => $meta['color'],
				];
			}
		}

		wp_send_json_success( [
			'statuses'        => $order_statuses,
			'payment_methods' => $payment_methods,
			'countries'       => $countries,
			'categories'      => $categories,
			'rfm_segments'    => $rfm_segments,
			'currency_symbol' => function_exists( 'get_woocommerce_currency_symbol' ) ? get_woocommerce_currency_symbol() : '$',
		] );
	}

	// =========================================================================
	// AJAX: product search for filter autocomplete
	// =========================================================================

	public function ajax_search_products() {
		$this->check_auth();

		$term = isset( $_POST['q'] ) ? sanitize_text_field( wp_unslash( $_POST['q'] ) ) : '';
		if ( strlen( $term ) < 2 ) {
			wp_send_json_success( [ 'products' => [] ] );
		}

		$query = new WP_Query( [
			'post_type'      => [ 'product' ],
			'post_status'    => 'publish',
			's'              => $term,
			'posts_per_page' => 20,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		] );

		$products = [];
		foreach ( $query->posts as $pid ) {
			$product = function_exists( 'wc_get_product' ) ? wc_get_product( $pid ) : null;
			if ( ! $product ) {
				continue;
			}
			$products[] = [
				'value' => (int) $pid,
				'label' => brikpanel_plain_label( $product->get_name() ) . ' (#' . $pid . ')',
			];
		}

		wp_send_json_success( [ 'products' => $products ] );
	}

	// =========================================================================
	// FILTER PARSING (shared by orders & customers)
	// =========================================================================

	private function parse_filters( array $input ) {
		$f = $this->sanitize_filter_input( $input );

		$f['page']     = max( 1, absint( $input['page'] ?? 1 ) );
		$f['per_page'] = min( 200, max( 10, absint( $input['per_page'] ?? self::PAGE_SIZE ) ) );
		$f['order']    = strtolower( $f['order'] ) === 'asc' ? 'ASC' : 'DESC';

		$f = $this->apply_preset( $f );

		return $f;
	}

	/**
	 * The filter values a request (or a saved segment) carries, cleaned, with
	 * no preset applied: a saved "Last 30 days" has to stay relative and be
	 * turned into dates on the day it is used, not on the day it was saved.
	 *
	 * @param array $input Raw request values.
	 * @return array
	 */
	private function sanitize_filter_input( array $input ) {
		$f = [
			'date_from'       => '',
			'date_to'         => '',
			'statuses'        => [],
			'payment_methods' => [],
			'countries'       => [],
			'city'            => '',
			'total_min'       => null,
			'total_max'       => null,
			'shipping_min'    => null,
			'shipping_max'    => null,
			'product_ids'     => [],
			'exclude_product_ids' => [],
			'category_ids'    => [],
			'coupon'          => '',
			'search'          => '',
			'order_count_min' => null,
			'order_count_max' => null,
			'spent_min'       => null,
			'spent_max'       => null,
			'last_order_from' => '',
			'last_order_to'   => '',
			'first_order_from' => '',
			'first_order_to'  => '',
			'registered_from' => '',
			'registered_to'   => '',
			'rfm_segments'    => [],
			'preset'          => '',
			'page'            => 1,
			'per_page'        => self::PAGE_SIZE,
			'sort'            => '',
			'order'           => 'desc',
		];

		foreach ( [ 'date_from', 'date_to', 'city', 'coupon', 'search', 'last_order_from', 'last_order_to', 'first_order_from', 'first_order_to', 'registered_from', 'registered_to', 'preset', 'sort', 'order' ] as $k ) {
			if ( isset( $input[ $k ] ) ) {
				$f[ $k ] = sanitize_text_field( wp_unslash( $input[ $k ] ) );
			}
		}

		if ( ! empty( $input['statuses'] ) && is_array( $input['statuses'] ) ) {
			$f['statuses'] = array_map( 'sanitize_key', array_map( 'wp_unslash', $input['statuses'] ) );
		}
		if ( ! empty( $input['payment_methods'] ) && is_array( $input['payment_methods'] ) ) {
			$f['payment_methods'] = array_map( 'sanitize_key', array_map( 'wp_unslash', $input['payment_methods'] ) );
		}
		if ( ! empty( $input['countries'] ) && is_array( $input['countries'] ) ) {
			$f['countries'] = array_map( 'sanitize_text_field', array_map( 'wp_unslash', $input['countries'] ) );
			$f['countries'] = array_filter( array_map( 'strtoupper', $f['countries'] ) );
		}
		if ( ! empty( $input['product_ids'] ) && is_array( $input['product_ids'] ) ) {
			$f['product_ids'] = array_filter( array_map( 'absint', $input['product_ids'] ) );
		}
		if ( ! empty( $input['exclude_product_ids'] ) && is_array( $input['exclude_product_ids'] ) ) {
			$f['exclude_product_ids'] = array_filter( array_map( 'absint', $input['exclude_product_ids'] ) );
		}
		if ( ! empty( $input['category_ids'] ) && is_array( $input['category_ids'] ) ) {
			$f['category_ids'] = array_filter( array_map( 'absint', $input['category_ids'] ) );
		}
		if ( ! empty( $input['rfm_segments'] ) && is_array( $input['rfm_segments'] ) ) {
			$f['rfm_segments'] = array_filter( array_map( 'sanitize_key', array_map( 'wp_unslash', $input['rfm_segments'] ) ) );
		}

		foreach ( [ 'total_min', 'total_max', 'shipping_min', 'shipping_max', 'spent_min', 'spent_max' ] as $k ) {
			if ( isset( $input[ $k ] ) && $input[ $k ] !== '' ) {
				$f[ $k ] = (float) $input[ $k ];
			}
		}
		foreach ( [ 'order_count_min', 'order_count_max' ] as $k ) {
			if ( isset( $input[ $k ] ) && $input[ $k ] !== '' ) {
				$f[ $k ] = (int) $input[ $k ];
			}
		}

		return $f;
	}

	/**
	 * SQL that is true when an order has a line of any of the given products.
	 *
	 * Read from the order's own line items, the same two tables on HPOS and
	 * legacy storage, written in the same request as the order. WooCommerce's
	 * analytics table (wc_order_product_lookup) is filled later in the
	 * background, sometimes hours later: an order placed in the meantime was
	 * missing from "bought X", and an "exclude X" built on that table would have
	 * let it through. A variable product matches each of its variations: a
	 * variation's line keeps its parent in _product_id.
	 *
	 * @param string $order_col Order id column of the outer query (o.id, o.ID).
	 * @param int[]  $ids       Product or variation ids.
	 * @param string $alias     Alias prefix, unique within the query.
	 * @return array{0: string, 1: string[]} [ SQL, params ]
	 */
	private function product_line_exists_sql( $order_col, array $ids, $alias ) {
		return Brikpanel_Customers_Engine::product_line_exists_sql( $order_col, $ids, $alias );
	}

	/**
	 * Append a half-open store-day window to a WHERE/HAVING clause list.
	 *
	 * All four date filters on this screen compare a store-local Y-m-d the
	 * merchant picked against a column stored in UTC. Until 3.3.19 each of the
	 * four wrote the conversion by hand as
	 * gmdate( 'Y-m-d 00:00:00', strtotime( $day ) ), which looks like a
	 * conversion but is a no-op round trip: WordPress pins PHP's default
	 * timezone to UTC, so the bound ended up being UTC midnight. Measured on a
	 * UTC+3 store for the local day 2026-08-10, the old bounds pulled in an
	 * order belonging to 9 August and missed the one belonging to 10 August:
	 * the same row count, entirely the wrong rows.
	 *
	 * The upper bound is EXCLUSIVE, so this emits `<` and never `<=`. Adjacent
	 * day windows then tile with no gap and no overlap, which is what stops a
	 * range from quietly growing an extra day the way the Store Summary
	 * "Yesterday" row once did.
	 *
	 * @param string $column  UTC column or aggregate alias, already resolved for
	 *                        the active order storage (HPOS or legacy posts).
	 * @param string $from    Local 'Y-m-d', or '' for no lower bound.
	 * @param string $to      Local 'Y-m-d' INCLUSIVE, or '' for no upper bound.
	 * @param array  $clauses By reference. Receives the SQL fragments.
	 * @param array  $params  By reference. Receives the UTC bound values.
	 * @return void
	 */
	private function add_day_window( $column, $from, $to, array &$clauses, array &$params ) {
		Brikpanel_Customers_Engine::day_window( $column, $from, $to, $clauses, $params );
	}

	/**
	 * Translate a preset key into concrete filter values. Presets are purely
	 * a UX shortcut — the server applies them on top of whatever other
	 * filters the client sent, so combining "Last 30 days" with an explicit
	 * status filter still works the way a user expects.
	 */
	private function apply_preset( array $f ) {
		switch ( $f['preset'] ) {
			// Whole store days, today included, so "Last 7 days" is seven buckets
			// and not a rolling 168 hours. gmdate() here meant the UTC day, which
			// on a UTC+3 store is still yesterday until 03:00 local.
			case 'today':
				$range          = brikpanel_store_days_range( 1 );
				$f['date_from'] = $range['from'];
				$f['date_to']   = $range['to'];
				break;
			case 'last7':
				$range          = brikpanel_store_days_range( 7 );
				$f['date_from'] = $range['from'];
				$f['date_to']   = $range['to'];
				break;
			case 'last30':
				$range          = brikpanel_store_days_range( 30 );
				$f['date_from'] = $range['from'];
				$f['date_to']   = $range['to'];
				break;
			case 'last90':
				$range          = brikpanel_store_days_range( 90 );
				$f['date_from'] = $range['from'];
				$f['date_to']   = $range['to'];
				break;
			case 'completed':
				if ( empty( $f['statuses'] ) ) {
					$f['statuses'] = [ 'completed' ];
				}
				break;
			case 'processing':
				if ( empty( $f['statuses'] ) ) {
					$f['statuses'] = [ 'processing' ];
				}
				break;
			case 'pending':
				if ( empty( $f['statuses'] ) ) {
					$f['statuses'] = [ 'pending' ];
				}
				break;
			case 'on_hold':
				if ( empty( $f['statuses'] ) ) {
					$f['statuses'] = [ 'on-hold' ];
				}
				break;
			case 'refunded':
				if ( empty( $f['statuses'] ) ) {
					$f['statuses'] = [ 'refunded' ];
				}
				break;
			case 'cancelled':
				if ( empty( $f['statuses'] ) ) {
					$f['statuses'] = [ 'cancelled', 'failed' ];
				}
				break;
			case 'returns':
				if ( empty( $f['statuses'] ) ) {
					$f['statuses'] = [ 'return-draft', 'return-complete' ];
				}
				break;
			case 'free_shipping':
				$f['shipping_max'] = 0;
				break;
			case 'paid_shipping':
				$f['shipping_min'] = 0.01;
				break;
			case 'high_value':
				if ( $f['total_min'] === null ) {
					$f['total_min'] = 500;
				}
				if ( $f['spent_min'] === null ) {
					$f['spent_min'] = 1000;
				}
				break;
			case 'vip':
				if ( $f['order_count_min'] === null ) {
					$f['order_count_min'] = 5;
				}
				break;
			// A new customer is someone whose first paid order falls in the window,
			// guests included. It used to read the sign-up date, which no guest
			// has, so a store of guest checkouts never had a "new" customer.
			case 'new_customers':
				if ( $f['first_order_from'] === '' ) {
					$f['first_order_from'] = brikpanel_store_date( 'Y-m-d', '-29 days' );
				}
				break;
			// The bell's "New customers today": the same rule for one store day.
			case 'new_today':
				if ( $f['first_order_from'] === '' && $f['first_order_to'] === '' ) {
					$f['first_order_from'] = brikpanel_store_date( 'Y-m-d' );
					$f['first_order_to']   = $f['first_order_from'];
				}
				break;
			case 'one_time':
				$f['order_count_min'] = 1;
				$f['order_count_max'] = 1;
				break;
			case 'repeat':
				if ( $f['order_count_min'] === null ) {
					$f['order_count_min'] = 2;
				}
				break;
			case 'dormant':
				if ( $f['last_order_to'] === '' ) {
					$f['last_order_to'] = brikpanel_store_date( 'Y-m-d', '-90 days' );
				}
				break;
		}
		return $f;
	}

	// =========================================================================
	// AJAX: query orders
	// =========================================================================

	public function ajax_query_orders() {
		$this->check_auth();

		$filters = $this->parse_filters( $_POST );
		$result  = $this->query_orders( $filters, true );

		wp_send_json_success( $result );
	}

	/**
	 * Build and run the orders segmentation query.
	 *
	 * HPOS: queries {prefix}wc_orders directly, joining wc_orders_meta for
	 * coupon, wc_order_addresses for country/city, and wc_order_product_lookup
	 * for product/category filters.
	 *
	 * Legacy: queries posts + postmeta. Product/category filters still use
	 * wc_order_product_lookup when available (WC populates it for both stores).
	 */
	/**
	 * @param array $f            Filters.
	 * @param bool  $with_numbers Resolve each row's displayed order number. Only
	 *                            the on-screen table needs it; the CSV export
	 *                            prints the raw "Order ID" and asks for every
	 *                            matching order at once, so making it resolve
	 *                            numbers it then discards would load the whole
	 *                            result set as order objects.
	 */
	private function query_orders( array $f, $with_numbers = false ) {
		global $wpdb;

		$hpos   = $this->is_hpos();
		$params = [];
		$where  = [];

		// Both stores keep a block checkout draft as 'wc-checkout-draft'
		// ('checkout-draft' is only what WC_Order::get_status() returns). Up to
		// 3.3.40 it was left in: a cart left on the checkout page topped this
		// list as a "Draft" order and counted in its totals and CSV.
		if ( $hpos ) {
			$from = "{$wpdb->prefix}wc_orders o";
			$where[] = "o.type = 'shop_order'";
			$where[] = "o.status NOT IN ('trash','auto-draft','draft','checkout-draft','wc-checkout-draft')";
		} else {
			$from = "{$wpdb->posts} o";
			$where[] = "o.post_type = 'shop_order'";
			$where[] = "o.post_status NOT IN ('trash','auto-draft','draft','checkout-draft','wc-checkout-draft')";
		}

		// Date range. The merchant picks store days; the column is UTC.
		$this->add_day_window( $hpos ? 'o.date_created_gmt' : 'o.post_date_gmt', $f['date_from'], $f['date_to'], $where, $params );

		// Statuses
		if ( ! empty( $f['statuses'] ) ) {
			// Accept both 'completed' and 'wc-completed'; always persist with the 'wc-' prefix.
			$status_slugs = array_map( function ( $s ) {
				$s = (string) $s;
				return strpos( $s, 'wc-' ) === 0 ? $s : 'wc-' . $s;
			}, $f['statuses'] );
			$placeholders = implode( ',', array_fill( 0, count( $status_slugs ), '%s' ) );
			if ( $hpos ) {
				$where[] = "o.status IN ($placeholders)";
			} else {
				$where[] = "o.post_status IN ($placeholders)";
			}
			$params = array_merge( $params, $status_slugs );
		}

		// Total range
		if ( $f['total_min'] !== null ) {
			if ( $hpos ) {
				$where[]  = 'o.total_amount >= %f';
				$params[] = $f['total_min'];
			} else {
				$where[]  = "EXISTS (SELECT 1 FROM {$wpdb->postmeta} pm_tm WHERE pm_tm.post_id = o.ID AND pm_tm.meta_key = '_order_total' AND CAST(pm_tm.meta_value AS DECIMAL(20,4)) >= %f)";
				$params[] = $f['total_min'];
			}
		}
		if ( $f['total_max'] !== null ) {
			if ( $hpos ) {
				$where[]  = 'o.total_amount <= %f';
				$params[] = $f['total_max'];
			} else {
				$where[]  = "EXISTS (SELECT 1 FROM {$wpdb->postmeta} pm_tx WHERE pm_tx.post_id = o.ID AND pm_tx.meta_key = '_order_total' AND CAST(pm_tx.meta_value AS DECIMAL(20,4)) <= %f)";
				$params[] = $f['total_max'];
			}
		}

		// Shipping total range — HPOS stores shipping in wp_wc_order_operational_data.
		if ( $f['shipping_min'] !== null ) {
			if ( $hpos ) {
				$where[]  = "EXISTS (SELECT 1 FROM {$wpdb->prefix}wc_order_operational_data opd WHERE opd.order_id = o.id AND opd.shipping_total_amount >= %f)";
				$params[] = $f['shipping_min'];
			} else {
				$where[]  = "EXISTS (SELECT 1 FROM {$wpdb->postmeta} pm_sm WHERE pm_sm.post_id = o.ID AND pm_sm.meta_key = '_order_shipping' AND CAST(pm_sm.meta_value AS DECIMAL(20,4)) >= %f)";
				$params[] = $f['shipping_min'];
			}
		}
		if ( $f['shipping_max'] !== null ) {
			if ( $hpos ) {
				$where[]  = "EXISTS (SELECT 1 FROM {$wpdb->prefix}wc_order_operational_data opd2 WHERE opd2.order_id = o.id AND opd2.shipping_total_amount <= %f)";
				$params[] = $f['shipping_max'];
			} else {
				$where[]  = "EXISTS (SELECT 1 FROM {$wpdb->postmeta} pm_sx WHERE pm_sx.post_id = o.ID AND pm_sx.meta_key = '_order_shipping' AND CAST(pm_sx.meta_value AS DECIMAL(20,4)) <= %f)";
				$params[] = $f['shipping_max'];
			}
		}

		// Payment methods
		if ( ! empty( $f['payment_methods'] ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $f['payment_methods'] ), '%s' ) );
			if ( $hpos ) {
				$where[] = "o.payment_method IN ($placeholders)";
			} else {
				$where[] = "EXISTS (SELECT 1 FROM {$wpdb->postmeta} pm_pm WHERE pm_pm.post_id = o.ID AND pm_pm.meta_key = '_payment_method' AND pm_pm.meta_value IN ($placeholders))";
			}
			$params = array_merge( $params, $f['payment_methods'] );
		}

		// Country / city
		if ( ! empty( $f['countries'] ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $f['countries'] ), '%s' ) );
			if ( $hpos ) {
				$where[] = "EXISTS (SELECT 1 FROM {$wpdb->prefix}wc_order_addresses oa WHERE oa.order_id = o.id AND oa.address_type = 'billing' AND oa.country IN ($placeholders))";
			} else {
				$where[] = "EXISTS (SELECT 1 FROM {$wpdb->postmeta} pm_co WHERE pm_co.post_id = o.ID AND pm_co.meta_key = '_billing_country' AND pm_co.meta_value IN ($placeholders))";
			}
			$params = array_merge( $params, $f['countries'] );
		}
		if ( $f['city'] !== '' ) {
			if ( $hpos ) {
				$where[]  = "EXISTS (SELECT 1 FROM {$wpdb->prefix}wc_order_addresses oa2 WHERE oa2.order_id = o.id AND oa2.address_type = 'billing' AND oa2.city LIKE %s)";
				$params[] = '%' . $wpdb->esc_like( $f['city'] ) . '%';
			} else {
				$where[]  = "EXISTS (SELECT 1 FROM {$wpdb->postmeta} pm_ci WHERE pm_ci.post_id = o.ID AND pm_ci.meta_key = '_billing_city' AND pm_ci.meta_value LIKE %s)";
				$params[] = '%' . $wpdb->esc_like( $f['city'] ) . '%';
			}
		}

		// Coupon
		if ( $f['coupon'] !== '' ) {
			if ( $hpos ) {
				$where[] = "EXISTS (SELECT 1 FROM {$wpdb->prefix}woocommerce_order_items oi WHERE oi.order_id = o.id AND oi.order_item_type = 'coupon' AND oi.order_item_name = %s)";
			} else {
				$where[] = "EXISTS (SELECT 1 FROM {$wpdb->prefix}woocommerce_order_items oi WHERE oi.order_id = o.ID AND oi.order_item_type = 'coupon' AND oi.order_item_name = %s)";
			}
			$params[] = $f['coupon'];
		}

		// Products: the order has a line of any of them, and (exclude) none of
		// the other list, in the same order. See product_line_exists_sql().
		if ( ! empty( $f['product_ids'] ) ) {
			list( $pl_sql, $pl_params ) = $this->product_line_exists_sql( $hpos ? 'o.id' : 'o.ID', $f['product_ids'], 'inc' );
			$where[] = $pl_sql;
			$params  = array_merge( $params, $pl_params );
		}
		if ( ! empty( $f['exclude_product_ids'] ) ) {
			list( $pl_sql, $pl_params ) = $this->product_line_exists_sql( $hpos ? 'o.id' : 'o.ID', $f['exclude_product_ids'], 'exc' );
			$where[] = 'NOT ' . $pl_sql;
			$params  = array_merge( $params, $pl_params );
		}

		// Category filter: any product in the order belongs to any of the categories
		if ( ! empty( $f['category_ids'] ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $f['category_ids'] ), '%d' ) );
			$order_col    = $hpos ? 'o.id' : 'o.ID';
			$where[]      = "EXISTS (
				SELECT 1 FROM {$wpdb->prefix}wc_order_product_lookup opl2
				INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = opl2.product_id
				INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'product_cat'
				WHERE opl2.order_id = {$order_col} AND tt.term_id IN ($placeholders)
			)";
			$params = array_merge( $params, $f['category_ids'] );
		}

		// Free-text search: order number, billing email, billing name
		if ( $f['search'] !== '' ) {
			// "#1001" is how the shop writes a number; the '#' is not part of it.
			$term = ltrim( $f['search'], '#' );
			if ( '' === $term ) {
				$term = $f['search'];
			}
			$like      = '%' . $wpdb->esc_like( $term ) . '%';
			$order_col = $hpos ? 'o.id' : 'o.ID';
			$meta_t    = $hpos ? "{$wpdb->prefix}wc_orders_meta" : $wpdb->postmeta;
			$meta_c    = $hpos ? 'order_id' : 'post_id';
			$parts     = [];

			// A number a sequential-number plugin stored (BrikPanel's own
			// included), typed with or without its prefix.
			$numbers = function_exists( 'brikpanel_order_numbers_candidates' ) ? brikpanel_order_numbers_candidates( $term ) : [];
			$keys    = function_exists( 'brikpanel_order_numbers_display_keys' ) ? brikpanel_order_numbers_display_keys() : [ '_order_number' ];
			$key_in  = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
			if ( $numbers ) {
				$parts[] = "EXISTS (SELECT 1 FROM {$meta_t} onm WHERE onm.{$meta_c} = {$order_col} AND onm.meta_key IN ({$key_in}) AND onm.meta_value IN (" . implode( ',', array_fill( 0, count( $numbers ), '%s' ) ) . '))';
				$params  = array_merge( $params, $keys, $numbers );
			}

			if ( ctype_digit( $term ) ) {
				if ( has_filter( 'woocommerce_order_number' ) ) {
					// The ID only counts for an order that shows it: one with no
					// stored number. Otherwise 1001 would also bring up the order
					// whose ID is 1001 but whose number is something else.
					$parts[] = "({$order_col} = %d AND NOT EXISTS (SELECT 1 FROM {$meta_t} onx WHERE onx.{$meta_c} = {$order_col} AND onx.meta_key IN ({$key_in}) AND onx.meta_value <> ''))";
					$params[] = (int) $term;
					$params   = array_merge( $params, $keys );
				} else {
					$parts[]  = "{$order_col} = %d";
					$params[] = (int) $term;
				}
			}

			if ( $hpos ) {
				$parts[]  = "o.billing_email LIKE %s OR EXISTS (SELECT 1 FROM {$wpdb->prefix}wc_order_addresses oas WHERE oas.order_id = o.id AND oas.address_type='billing' AND (oas.first_name LIKE %s OR oas.last_name LIKE %s OR CONCAT_WS(' ', oas.first_name, oas.last_name) LIKE %s))";
				$params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
			} else {
				$parts[]  = "EXISTS (SELECT 1 FROM {$wpdb->postmeta} pm_s WHERE pm_s.post_id=o.ID AND pm_s.meta_key IN ('_billing_email','_billing_first_name','_billing_last_name') AND pm_s.meta_value LIKE %s)";
				$params[] = $like;
			}
			$where[] = '(' . implode( ' OR ', $parts ) . ')';
		}

		// Sort
		$sort_col = $hpos ? 'o.date_created_gmt' : 'o.post_date_gmt';
		if ( $f['sort'] === 'total' ) {
			$sort_col = $hpos ? 'o.total_amount' : '(SELECT CAST(meta_value AS DECIMAL(20,4)) FROM ' . $wpdb->postmeta . ' WHERE post_id = o.ID AND meta_key = "_order_total" LIMIT 1)';
		}
		$direction = $f['order'] === 'ASC' ? 'ASC' : 'DESC';

		$where_sql = implode( ' AND ', $where );

		// Count — skip prepare() when no params, to avoid WP's "no placeholder" notice.
		$count_sql_raw = "SELECT COUNT(*) FROM {$from} WHERE {$where_sql}";
		$total         = (int) $wpdb->get_var( $params ? $wpdb->prepare( $count_sql_raw, $params ) : $count_sql_raw ); // phpcs:ignore

		// Aggregates (revenue, aov) — converted to the store base currency so a
		// multi-currency store's Total Revenue and AOV are never a raw mix of
		// currencies. Each order uses its stored base-total snapshot (CURCY
		// day-of-sale rate, or the manual fallback); COALESCE falls back to the
		// raw total for base-currency orders. Per-order rows below still show
		// their own currency.
		if ( $hpos ) {
			$fx_agg      = brikpanel_base_total_sql( true, 'o.id', 'o.total_amount', 'bpfxseg' );
			$agg_sql_raw = "SELECT COALESCE(SUM({$fx_agg['expr']}),0) AS revenue FROM {$from}{$fx_agg['join']} WHERE {$where_sql}";
		} else {
			$fx_agg      = brikpanel_base_total_sql( false, 'o.ID', 'CAST(pm_agg.meta_value AS DECIMAL(20,4))', 'bpfxseg' );
			$agg_sql_raw = "SELECT COALESCE(SUM({$fx_agg['expr']}),0) AS revenue FROM {$from} LEFT JOIN {$wpdb->postmeta} pm_agg ON pm_agg.post_id = o.ID AND pm_agg.meta_key = '_order_total' AND " . brikpanel_sql_first_meta_guard( 'post', 'pm_agg' ) . "{$fx_agg['join']} WHERE {$where_sql}";
		}
		$revenue = (float) $wpdb->get_var( $params ? $wpdb->prepare( $agg_sql_raw, $params ) : $agg_sql_raw ); // phpcs:ignore

		// Page
		$offset = ( $f['page'] - 1 ) * $f['per_page'];

		if ( $hpos ) {
			$select = "SELECT o.id AS order_id, o.date_created_gmt, o.status, o.total_amount, o.currency, o.customer_id,
				o.billing_email, o.payment_method_title, o.payment_method,
				(SELECT oas.phone FROM {$wpdb->prefix}wc_order_addresses oas WHERE oas.order_id = o.id AND oas.address_type='billing' LIMIT 1) AS billing_phone,
				(SELECT CONCAT_WS(' ', oas.first_name, oas.last_name) FROM {$wpdb->prefix}wc_order_addresses oas WHERE oas.order_id = o.id AND oas.address_type='billing' LIMIT 1) AS billing_name,
				(SELECT oas.country FROM {$wpdb->prefix}wc_order_addresses oas WHERE oas.order_id = o.id AND oas.address_type='billing' LIMIT 1) AS billing_country,
				(SELECT oas.city FROM {$wpdb->prefix}wc_order_addresses oas WHERE oas.order_id = o.id AND oas.address_type='billing' LIMIT 1) AS billing_city
				FROM {$from}
				WHERE {$where_sql}
				ORDER BY {$sort_col} {$direction}
				LIMIT %d OFFSET %d";
		} else {
			$select = "SELECT o.ID AS order_id, o.post_date_gmt AS date_created_gmt, o.post_status AS status,
				(SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id=o.ID AND meta_key='_order_total'  ORDER BY meta_id LIMIT 1) AS total_amount,
				(SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id=o.ID AND meta_key='_order_currency'  ORDER BY meta_id LIMIT 1) AS currency,
				(SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id=o.ID AND meta_key='_customer_user'  ORDER BY meta_id LIMIT 1) AS customer_id,
				(SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id=o.ID AND meta_key='_billing_email'  ORDER BY meta_id LIMIT 1) AS billing_email,
				(SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id=o.ID AND meta_key='_billing_phone'  ORDER BY meta_id LIMIT 1) AS billing_phone,
				(SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id=o.ID AND meta_key='_payment_method_title'  ORDER BY meta_id LIMIT 1) AS payment_method_title,
				(SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id=o.ID AND meta_key='_payment_method'  ORDER BY meta_id LIMIT 1) AS payment_method,
				(SELECT CONCAT_WS(' ', (SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id=o.ID AND meta_key='_billing_first_name'  ORDER BY meta_id LIMIT 1), (SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id=o.ID AND meta_key='_billing_last_name'  ORDER BY meta_id LIMIT 1))) AS billing_name,
				(SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id=o.ID AND meta_key='_billing_country'  ORDER BY meta_id LIMIT 1) AS billing_country,
				(SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id=o.ID AND meta_key='_billing_city'  ORDER BY meta_id LIMIT 1) AS billing_city
				FROM {$from}
				WHERE {$where_sql}
				ORDER BY {$sort_col} {$direction}
				LIMIT %d OFFSET %d";
		}

		$page_params = array_merge( $params, [ $f['per_page'], $offset ] );
		$rows        = $wpdb->get_results( $wpdb->prepare( $select, $page_params ) ); // phpcs:ignore

		// The rows come straight from SQL, so they carry the raw ID. The orders
		// list shows whatever `woocommerce_order_number` returns, and this table
		// has to match it. Resolved for the whole page at once, and for free on
		// the stores where nothing renumbers orders.
		$numbers = $with_numbers
			? brikpanel_order_numbers_for_ids( wp_list_pluck( $rows, 'order_id' ) )
			: [];

		$items = [];
		foreach ( $rows as $r ) {
			$status_slug = preg_replace( '/^wc-/', '', $r->status );
			$items[] = [
				'id'            => (int) $r->order_id,
				'number'        => (string) ( $numbers[ (int) $r->order_id ] ?? $r->order_id ),
				// date_created_gmt is UTC. mysql2date() would read it as store time
				// and print the London clock under the store's own format.
				'date'          => brikpanel_local_datetime( $r->date_created_gmt ),
				'date_export'   => brikpanel_local_datetime( $r->date_created_gmt, 'Y-m-d H:i:s' ),
				'status'        => $status_slug,
				'status_label'  => wc_get_order_status_name( $status_slug ),
				'total'         => (float) $r->total_amount,
				'total_display' => wp_strip_all_tags( wc_price( (float) $r->total_amount, [ 'currency' => $r->currency ?: null ] ) ),
				'customer_id'   => (int) $r->customer_id,
				'name'          => trim( (string) $r->billing_name ),
				'email'         => (string) $r->billing_email,
				'phone'         => (string) $r->billing_phone,
				'country'       => (string) $r->billing_country,
				'city'          => (string) $r->billing_city,
				'payment'       => brikpanel_plain_label( (string) ( $r->payment_method_title ?: $r->payment_method ) ),
				'edit_url'      => admin_url( $hpos ? 'admin.php?page=wc-orders&action=edit&id=' . (int) $r->order_id : 'post.php?post=' . (int) $r->order_id . '&action=edit' ),
			];
		}

		return [
			'items'   => $items,
			'total'   => $total,
			'page'    => $f['page'],
			'per_page' => $f['per_page'],
			'pages'   => (int) ceil( $total / $f['per_page'] ),
			'summary' => [
				'count'             => $total,
				'revenue'           => $revenue,
				'revenue_display'   => wp_strip_all_tags( wc_price( $revenue ) ),
				'aov'               => $total > 0 ? $revenue / $total : 0,
				'aov_display'       => wp_strip_all_tags( wc_price( $total > 0 ? $revenue / $total : 0 ) ),
			],
		];
	}

	// =========================================================================
	// AJAX: query customers
	// =========================================================================

	public function ajax_query_customers() {
		$this->check_auth();
		$filters = $this->parse_filters( $_POST );
		$result  = $this->query_customers( $filters );
		wp_send_json_success( $result );
	}

	/**
	 * The customer list: one page, its total and its sums, by the Customers
	 * engine's rules (front-end/customers/class-brikpanel-customers-engine.php).
	 *
	 * @param array $f Filters.
	 * @return array
	 */
	private function query_customers( array $f ) {
		return Brikpanel_Customers_Engine::query( $f );
	}

	// =========================================================================
	// SAVED SEGMENTS
	// =========================================================================

	/**
	 * The preset keys a tab offers. Plain keys, never labels: checking a saved
	 * segment must not translate anything.
	 *
	 * @param string $tab 'orders' or 'customers'.
	 * @return string[]
	 */
	private static function preset_keys( $tab ) {
		if ( 'customers' === $tab ) {
			return [ '', 'new_customers', 'new_today', 'repeat', 'vip', 'one_time', 'dormant', 'high_value' ];
		}
		return [ '', 'today', 'last7', 'last30', 'last90', 'processing', 'completed', 'pending', 'on_hold', 'refunded', 'cancelled', 'returns', 'free_shipping', 'paid_shipping', 'high_value' ];
	}

	/**
	 * The filters a saved segment keeps: the ones its tab shows.
	 *
	 * @param string $tab 'orders' or 'customers'.
	 * @return string[]
	 */
	private static function saved_filter_keys( $tab ) {
		$both = [ 'date_from', 'date_to', 'countries', 'city', 'product_ids', 'exclude_product_ids', 'category_ids', 'search' ];
		if ( 'customers' === $tab ) {
			return array_merge( $both, [ 'spent_min', 'spent_max', 'order_count_min', 'order_count_max', 'last_order_from', 'last_order_to', 'first_order_from', 'first_order_to', 'registered_from', 'registered_to', 'rfm_segments' ] );
		}
		return array_merge( $both, [ 'statuses', 'total_min', 'total_max', 'payment_methods', 'coupon' ] );
	}

	/**
	 * Filters as a saved segment stores them: this tab's keys only, no empty
	 * values, dates that are dates, lists of a sane length, and no preset
	 * applied (see sanitize_filter_input()).
	 *
	 * @param array  $input Raw request values.
	 * @param string $tab   'orders' or 'customers'.
	 * @return array
	 */
	private function saved_filters_from_input( array $input, $tab ) {
		$f   = $this->sanitize_filter_input( $input );
		$out = [];
		foreach ( self::saved_filter_keys( $tab ) as $key ) {
			$value = isset( $f[ $key ] ) ? $f[ $key ] : null;
			if ( is_array( $value ) ) {
				$value = array_slice( array_values( array_unique( $value ) ), 0, 'countries' === $key ? 250 : 100 );
				if ( $value ) {
					$out[ $key ] = $value;
				}
			} elseif ( is_int( $value ) || is_float( $value ) ) {
				$out[ $key ] = $value;
			} elseif ( is_string( $value ) && '' !== $value ) {
				if ( preg_match( '/_(?:from|to)$/', $key ) && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
					continue;
				}
				$out[ $key ] = brikpanel_substr( $value, 0, 200 );
			}
		}
		return $out;
	}

	/**
	 * Saved segments in the order they were saved, malformed rows dropped.
	 *
	 * @return array[]
	 */
	private static function saved_list() {
		$list = get_option( self::SAVED_OPTION, [] );
		if ( ! is_array( $list ) ) {
			return [];
		}
		$out = [];
		foreach ( $list as $seg ) {
			if ( ! is_array( $seg ) || empty( $seg['id'] ) || ! isset( $seg['name'] ) || '' === (string) $seg['name'] || ! isset( $seg['tab'] ) || ! in_array( $seg['tab'], [ 'orders', 'customers' ], true ) ) {
				continue;
			}
			$seg['filters'] = isset( $seg['filters'] ) && is_array( $seg['filters'] ) ? $seg['filters'] : [];
			$seg['preset']  = isset( $seg['preset'] ) && is_string( $seg['preset'] ) ? $seg['preset'] : '';
			$out[]          = $seg;
		}
		return $out;
	}

	/**
	 * Saved segments as the screen script reads them, with product names
	 * looked up now, so a renamed product shows its new name. A product that no
	 * longer exists keeps its place under its id: dropping it would quietly turn
	 * "bought X" into "every order".
	 *
	 * @return array[]
	 */
	public static function saved_for_js() {
		$list = self::saved_list();
		$ids  = [];
		foreach ( $list as $seg ) {
			foreach ( [ 'product_ids', 'exclude_product_ids' ] as $key ) {
				if ( ! empty( $seg['filters'][ $key ] ) && is_array( $seg['filters'][ $key ] ) ) {
					$ids = array_merge( $ids, array_map( 'absint', $seg['filters'][ $key ] ) );
				}
			}
		}
		$ids = array_values( array_unique( array_filter( $ids ) ) );
		if ( $ids && function_exists( '_prime_post_caches' ) ) {
			_prime_post_caches( $ids, false, false );
		}

		$out = [];
		foreach ( $list as $seg ) {
			$entry = [
				'id'      => (string) $seg['id'],
				'tab'     => $seg['tab'],
				'name'    => (string) $seg['name'],
				'preset'  => $seg['preset'],
				'filters' => $seg['filters'],
			];
			foreach ( [ 'product_ids' => 'products', 'exclude_product_ids' => 'exclude_products' ] as $key => $js_key ) {
				$entry[ $js_key ] = [];
				$pids             = ! empty( $seg['filters'][ $key ] ) && is_array( $seg['filters'][ $key ] ) ? $seg['filters'][ $key ] : [];
				foreach ( $pids as $pid ) {
					$pid = absint( $pid );
					if ( ! $pid ) {
						continue;
					}
					$product = function_exists( 'wc_get_product' ) ? wc_get_product( $pid ) : null;
					$entry[ $js_key ][] = [
						'value' => $pid,
						'label' => $product
							? brikpanel_plain_label( $product->get_name() ) . ' (#' . $pid . ')'
							/* translators: %d: product ID. */
							: sprintf( __( 'Deleted product #%d', 'brikpanel' ), $pid ),
					];
				}
			}
			$out[] = $entry;
		}
		return $out;
	}

	/**
	 * Save the current tab's filters under a name, for everyone who opens
	 * Segments. A name already used on the tab is replaced only when the
	 * request says so (the screen asks first: the list is shared).
	 */
	public function ajax_save_segment() {
		$this->check_auth();

		$name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$name = brikpanel_substr( trim( (string) preg_replace( '/\s+/u', ' ', $name ) ), 0, 60 );
		if ( '' === $name ) {
			wp_send_json_error( [ 'code' => 'name' ] );
		}

		$tab    = ( isset( $_POST['tab'] ) && 'customers' === $_POST['tab'] ) ? 'customers' : 'orders';
		$preset = isset( $_POST['preset'] ) ? sanitize_key( wp_unslash( $_POST['preset'] ) ) : '';
		if ( ! in_array( $preset, self::preset_keys( $tab ), true ) ) {
			$preset = '';
		}
		$filters = $this->saved_filters_from_input( $_POST, $tab );

		$list   = self::saved_list();
		$found  = null;
		$in_tab = 0;
		foreach ( $list as $i => $seg ) {
			if ( $seg['tab'] !== $tab ) {
				continue;
			}
			$in_tab++;
			if ( brikpanel_strtolower( (string) $seg['name'] ) === brikpanel_strtolower( $name ) ) {
				$found = $i;
			}
		}
		if ( null !== $found && empty( $_POST['replace'] ) ) {
			wp_send_json_error( [ 'code' => 'exists' ] );
		}
		if ( null === $found && $in_tab >= self::SAVED_MAX ) {
			wp_send_json_error( [ 'code' => 'limit' ] );
		}

		$entry = [
			'id'      => null !== $found ? (string) $list[ $found ]['id'] : wp_generate_uuid4(),
			'tab'     => $tab,
			'name'    => $name,
			'preset'  => $preset,
			'filters' => $filters,
			'by'      => get_current_user_id(),
			'at'      => time(),
		];
		if ( null !== $found ) {
			$list[ $found ] = $entry;
		} else {
			$list[] = $entry;
		}
		update_option( self::SAVED_OPTION, array_values( $list ), false );

		wp_send_json_success( [
			'id'    => $entry['id'],
			'saved' => self::saved_for_js(),
		] );
	}

	/** Delete a saved segment, for everyone. */
	public function ajax_delete_segment() {
		$this->check_auth();

		$id   = isset( $_POST['id'] ) ? sanitize_text_field( wp_unslash( $_POST['id'] ) ) : '';
		$list = self::saved_list();
		$kept = array_values( array_filter( $list, static function ( $seg ) use ( $id ) {
			return (string) $seg['id'] !== $id;
		} ) );
		if ( count( $kept ) !== count( $list ) ) {
			update_option( self::SAVED_OPTION, $kept, false );
		}

		wp_send_json_success( [ 'saved' => self::saved_for_js() ] );
	}

	// =========================================================================
	// AJAX: CSV export
	// =========================================================================

	public function ajax_export_csv() {
		// CSV export is a GET (opens in a new tab) so it supports bookmark /
		// share. Still require the nonce — same action as the AJAX endpoints —
		// and the WC capability.
		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), self::NONCE_ACTION ) ) {
			wp_die( esc_html__( 'Invalid request.', 'brikpanel' ), 403 );
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'brikpanel' ), 403 );
		}

		$tab = isset( $_GET['tab'] ) && $_GET['tab'] === 'customers' ? 'customers' : 'orders';
		$filters = $this->parse_filters( $_GET );
		$filters['per_page'] = 10000;
		$filters['page']     = 1;

		nocache_headers();
		header( 'Content-Type: text/csv; charset=UTF-8' );
		$file = 'customers' === $tab ? 'brikpanel-customers-' : 'brikpanel-segments-orders-';
		header( 'Content-Disposition: attachment; filename="' . $file . brikpanel_store_date( 'Ymd-His' ) . '.csv"' );

		$out = fopen( 'php://output', 'w' );
		// UTF-8 BOM so Excel opens non-ASCII characters cleanly.
		fwrite( $out, "\xEF\xBB\xBF" );

		// Every date cell below is the store's own wall clock, written as
		// 'Y-m-d H:i:s' so a spreadsheet sorts it chronologically in any locale.
		// The header names the zone, because a column of bare timestamps that does
		// not say which clock it is on is exactly how this screen got reported.
		$tz_label = wp_timezone_string();

		// Every data row below goes through brikpanel_csv_safe_row(), which
		// neutralises CSV formula injection across the whole row. This used to
		// guard the phone column alone, which left the billing name, email,
		// city and payment title beside it — all of them typed by whoever
		// placed the order, guest checkout included — free to carry a formula
		// into the merchant's spreadsheet.
		if ( $tab === 'orders' ) {
			$result = $this->query_orders( $filters );
			fputcsv( $out, [
				__( 'Order ID', 'brikpanel' ),
				/* translators: %s: the store's timezone, for example Europe/Istanbul. */
				sprintf( __( 'Date (%s)', 'brikpanel' ), $tz_label ),
				__( 'Status', 'brikpanel' ),
				__( 'Customer', 'brikpanel' ),
				__( 'Email', 'brikpanel' ),
				__( 'Phone', 'brikpanel' ),
				__( 'Country', 'brikpanel' ),
				__( 'City', 'brikpanel' ),
				__( 'Payment', 'brikpanel' ),
				__( 'Total', 'brikpanel' ),
				// Last, so spreadsheets that read the columns by position keep
				// working. The number the shop shows, which a sequential-number
				// plugin can make different from the ID.
				__( 'Order number', 'brikpanel' ),
			] );
			$numbers = brikpanel_order_numbers_for_ids( array_column( $result['items'], 'id' ) );
			foreach ( $result['items'] as $item ) {
				fputcsv( $out, brikpanel_csv_safe_row( [
					$item['id'],
					$item['date_export'],
					$item['status_label'],
					$item['name'],
					$item['email'],
					$item['phone'],
					$item['country'],
					$item['city'],
					$item['payment'],
					$item['total'],
					$numbers[ (int) $item['id'] ] ?? (string) $item['id'],
				] ) );
			}
		} else {
			// The first ten columns keep their old order (spreadsheets and
			// mailing tools read them by position); new ones go at the end.
			fputcsv( $out, [
				__( 'User ID', 'brikpanel' ),
				__( 'Name', 'brikpanel' ),
				__( 'Email', 'brikpanel' ),
				__( 'Phone', 'brikpanel' ),
				/* translators: %s: the store's timezone, for example Europe/Istanbul. */
				sprintf( __( 'Registered (%s)', 'brikpanel' ), $tz_label ),
				__( 'Orders', 'brikpanel' ),
				__( 'Total spent', 'brikpanel' ),
				__( 'Average order value', 'brikpanel' ),
				/* translators: %s: the store's timezone, for example Europe/Istanbul. */
				sprintf( __( 'First order (%s)', 'brikpanel' ), $tz_label ),
				/* translators: %s: the store's timezone, for example Europe/Istanbul. */
				sprintf( __( 'Last order (%s)', 'brikpanel' ), $tz_label ),
				__( 'Country', 'brikpanel' ),
				__( 'City', 'brikpanel' ),
				__( 'RFM segment', 'brikpanel' ),
				/* translators: %s: the store's timezone, for example Europe/Istanbul. */
				sprintf( __( 'Customer since (%s)', 'brikpanel' ), $tz_label ),
			] );
			// Every matching customer, read in batches: the old export asked for
			// one page of 10,000 and silently cut a larger list there.
			Brikpanel_Customers_Engine::export( $filters, static function ( $item ) use ( $out ) {
				fputcsv( $out, brikpanel_csv_safe_row( [
					$item['user_id'] ?: '',
					$item['name'],
					$item['email'],
					$item['phone'],
					$item['registered_export'],
					$item['order_count'],
					$item['total_spent'],
					$item['aov'],
					$item['first_order_export'],
					$item['last_order_export'],
					$item['country'],
					$item['city'],
					$item['rfm_label'],
					$item['since_export'],
				] ) );
			} );
		}

		fclose( $out );
		exit;
	}
}

new Brikpanel_Segments();
