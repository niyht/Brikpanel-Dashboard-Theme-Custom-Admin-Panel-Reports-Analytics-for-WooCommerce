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

class Brikpanel_Segments {

	const PAGE_SLUG   = 'brikpanel-segments';
	const NONCE_ACTION = 'brikpanel_segments_nonce';
	const PAGE_SIZE   = 25;

	/** @var bool|null Cached HPOS detection. */
	private $is_hpos = null;

	public function __construct() {
		if ( get_option( 'brikpanel_modern_segments', 'yes' ) !== 'yes' ) {
			return;
		}

		add_action( 'admin_menu', [ $this, 'register_page' ] );

		add_action( 'wp_ajax_brikpanel_segments_filter_options', [ $this, 'ajax_filter_options' ] );
		add_action( 'wp_ajax_brikpanel_segments_query_orders', [ $this, 'ajax_query_orders' ] );
		add_action( 'wp_ajax_brikpanel_segments_query_customers', [ $this, 'ajax_query_customers' ] );
		add_action( 'wp_ajax_brikpanel_segments_export', [ $this, 'ajax_export_csv' ] );
		add_action( 'wp_ajax_brikpanel_segments_search_products', [ $this, 'ajax_search_products' ] );
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

	public function register_page() {
		// Promoted to a top-level menu item; the modern-nav layer
		// (front-end/navigation/) re-positions it directly after Products.
		$hook = add_menu_page(
			__( 'Segments', 'brikpanel' ),
			__( 'Segments', 'brikpanel' ),
			'manage_woocommerce',
			self::PAGE_SLUG,
			[ $this, 'render_page' ],
			'dashicons-chart-bar',
			56.6
		);

		if ( $hook ) {
			add_action( 'load-' . $hook, function () {
				global $title;
				$title = __( 'Segments', 'brikpanel' );
			} );
		}
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
					'label' => $name,
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
					'label' => $term->name,
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
				'label' => $product->get_name() . ' (#' . $pid . ')',
			];
		}

		wp_send_json_success( [ 'products' => $products ] );
	}

	// =========================================================================
	// FILTER PARSING (shared by orders & customers)
	// =========================================================================

	private function parse_filters( array $input ) {
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
			'category_ids'    => [],
			'coupon'          => '',
			'search'          => '',
			'order_count_min' => null,
			'order_count_max' => null,
			'spent_min'       => null,
			'spent_max'       => null,
			'last_order_from' => '',
			'last_order_to'   => '',
			'registered_from' => '',
			'registered_to'   => '',
			'rfm_segments'    => [],
			'preset'          => '',
			'page'            => 1,
			'per_page'        => self::PAGE_SIZE,
			'sort'            => '',
			'order'           => 'desc',
		];

		foreach ( [ 'date_from', 'date_to', 'city', 'coupon', 'search', 'last_order_from', 'last_order_to', 'registered_from', 'registered_to', 'preset', 'sort', 'order' ] as $k ) {
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

		$f['page']     = max( 1, absint( $input['page'] ?? 1 ) );
		$f['per_page'] = min( 200, max( 10, absint( $input['per_page'] ?? self::PAGE_SIZE ) ) );
		$f['order']    = strtolower( $f['order'] ) === 'asc' ? 'ASC' : 'DESC';

		$f = $this->apply_preset( $f );

		return $f;
	}

	/**
	 * Translate a preset key into concrete filter values. Presets are purely
	 * a UX shortcut — the server applies them on top of whatever other
	 * filters the client sent, so combining "Last 30 days" with an explicit
	 * status filter still works the way a user expects.
	 */
	private function apply_preset( array $f ) {
		switch ( $f['preset'] ) {
			case 'today':
				$f['date_from'] = gmdate( 'Y-m-d' );
				$f['date_to']   = gmdate( 'Y-m-d' );
				break;
			case 'last7':
				$f['date_from'] = gmdate( 'Y-m-d', strtotime( '-6 days' ) );
				$f['date_to']   = gmdate( 'Y-m-d' );
				break;
			case 'last30':
				$f['date_from'] = gmdate( 'Y-m-d', strtotime( '-29 days' ) );
				$f['date_to']   = gmdate( 'Y-m-d' );
				break;
			case 'last90':
				$f['date_from'] = gmdate( 'Y-m-d', strtotime( '-89 days' ) );
				$f['date_to']   = gmdate( 'Y-m-d' );
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
			case 'new_customers':
				if ( $f['registered_from'] === '' ) {
					$f['registered_from'] = gmdate( 'Y-m-d', strtotime( '-29 days' ) );
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
					$f['last_order_to'] = gmdate( 'Y-m-d', strtotime( '-90 days' ) );
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
		$result  = $this->query_orders( $filters );

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
	private function query_orders( array $f ) {
		global $wpdb;

		$hpos   = $this->is_hpos();
		$params = [];
		$where  = [];

		if ( $hpos ) {
			$from = "{$wpdb->prefix}wc_orders o";
			$where[] = "o.type = 'shop_order'";
			$where[] = "o.status NOT IN ('trash','auto-draft','checkout-draft')";
		} else {
			$from = "{$wpdb->posts} o";
			$where[] = "o.post_type = 'shop_order'";
			$where[] = "o.post_status NOT IN ('trash','auto-draft')";
		}

		// Date range
		if ( $f['date_from'] !== '' ) {
			if ( $hpos ) {
				$where[]  = 'o.date_created_gmt >= %s';
				$params[] = gmdate( 'Y-m-d 00:00:00', strtotime( $f['date_from'] ) );
			} else {
				$where[]  = 'o.post_date_gmt >= %s';
				$params[] = gmdate( 'Y-m-d 00:00:00', strtotime( $f['date_from'] ) );
			}
		}
		if ( $f['date_to'] !== '' ) {
			if ( $hpos ) {
				$where[]  = 'o.date_created_gmt <= %s';
				$params[] = gmdate( 'Y-m-d 23:59:59', strtotime( $f['date_to'] ) );
			} else {
				$where[]  = 'o.post_date_gmt <= %s';
				$params[] = gmdate( 'Y-m-d 23:59:59', strtotime( $f['date_to'] ) );
			}
		}

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

		// Product filter (any of the given product IDs appear in the order).
		// Uses wp_wc_order_product_lookup which is populated by WC analytics.
		if ( ! empty( $f['product_ids'] ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $f['product_ids'] ), '%d' ) );
			$order_col = $hpos ? 'o.id' : 'o.ID';
			$where[] = "EXISTS (SELECT 1 FROM {$wpdb->prefix}wc_order_product_lookup opl WHERE opl.order_id = {$order_col} AND (opl.product_id IN ($placeholders) OR opl.variation_id IN ($placeholders)))";
			$params = array_merge( $params, $f['product_ids'], $f['product_ids'] );
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

		// Free-text search: order id, billing email, billing name
		if ( $f['search'] !== '' ) {
			$like = '%' . $wpdb->esc_like( $f['search'] ) . '%';
			if ( ctype_digit( $f['search'] ) ) {
				$order_col = $hpos ? 'o.id' : 'o.ID';
				$where[]  = "($order_col = %d OR " . ( $hpos
						? "o.billing_email LIKE %s OR EXISTS (SELECT 1 FROM {$wpdb->prefix}wc_order_addresses oas WHERE oas.order_id = o.id AND oas.address_type='billing' AND (oas.first_name LIKE %s OR oas.last_name LIKE %s OR CONCAT_WS(' ', oas.first_name, oas.last_name) LIKE %s))"
						: "EXISTS (SELECT 1 FROM {$wpdb->postmeta} pm_s WHERE pm_s.post_id=o.ID AND pm_s.meta_key IN ('_billing_email','_billing_first_name','_billing_last_name') AND pm_s.meta_value LIKE %s)"
					) . ")";
				$params[] = (int) $f['search'];
				$params[] = $like;
				if ( $hpos ) {
					$params[] = $like; $params[] = $like; $params[] = $like;
				}
			} else {
				if ( $hpos ) {
					$where[]  = "(o.billing_email LIKE %s OR EXISTS (SELECT 1 FROM {$wpdb->prefix}wc_order_addresses oas WHERE oas.order_id = o.id AND oas.address_type='billing' AND (oas.first_name LIKE %s OR oas.last_name LIKE %s OR CONCAT_WS(' ', oas.first_name, oas.last_name) LIKE %s)))";
					$params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
				} else {
					$where[]  = "EXISTS (SELECT 1 FROM {$wpdb->postmeta} pm_s WHERE pm_s.post_id=o.ID AND pm_s.meta_key IN ('_billing_email','_billing_first_name','_billing_last_name') AND pm_s.meta_value LIKE %s)";
					$params[] = $like;
				}
			}
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

		// Aggregates (revenue, aov)
		if ( $hpos ) {
			$agg_sql_raw = "SELECT COALESCE(SUM(o.total_amount),0) AS revenue FROM {$from} WHERE {$where_sql}";
		} else {
			$agg_sql_raw = "SELECT COALESCE(SUM(CAST(pm_agg.meta_value AS DECIMAL(20,4))),0) AS revenue FROM {$from} LEFT JOIN {$wpdb->postmeta} pm_agg ON pm_agg.post_id = o.ID AND pm_agg.meta_key = '_order_total' WHERE {$where_sql}";
		}
		$revenue = (float) $wpdb->get_var( $params ? $wpdb->prepare( $agg_sql_raw, $params ) : $agg_sql_raw ); // phpcs:ignore

		// Page
		$offset = ( $f['page'] - 1 ) * $f['per_page'];

		if ( $hpos ) {
			$select = "SELECT o.id AS order_id, o.date_created_gmt, o.status, o.total_amount, o.currency, o.customer_id,
				o.billing_email, o.payment_method_title, o.payment_method,
				(SELECT CONCAT_WS(' ', oas.first_name, oas.last_name) FROM {$wpdb->prefix}wc_order_addresses oas WHERE oas.order_id = o.id AND oas.address_type='billing' LIMIT 1) AS billing_name,
				(SELECT oas.country FROM {$wpdb->prefix}wc_order_addresses oas WHERE oas.order_id = o.id AND oas.address_type='billing' LIMIT 1) AS billing_country,
				(SELECT oas.city FROM {$wpdb->prefix}wc_order_addresses oas WHERE oas.order_id = o.id AND oas.address_type='billing' LIMIT 1) AS billing_city
				FROM {$from}
				WHERE {$where_sql}
				ORDER BY {$sort_col} {$direction}
				LIMIT %d OFFSET %d";
		} else {
			$select = "SELECT o.ID AS order_id, o.post_date_gmt AS date_created_gmt, o.post_status AS status,
				(SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id=o.ID AND meta_key='_order_total' LIMIT 1) AS total_amount,
				(SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id=o.ID AND meta_key='_order_currency' LIMIT 1) AS currency,
				(SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id=o.ID AND meta_key='_customer_user' LIMIT 1) AS customer_id,
				(SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id=o.ID AND meta_key='_billing_email' LIMIT 1) AS billing_email,
				(SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id=o.ID AND meta_key='_payment_method_title' LIMIT 1) AS payment_method_title,
				(SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id=o.ID AND meta_key='_payment_method' LIMIT 1) AS payment_method,
				(SELECT CONCAT_WS(' ', (SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id=o.ID AND meta_key='_billing_first_name' LIMIT 1), (SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id=o.ID AND meta_key='_billing_last_name' LIMIT 1))) AS billing_name,
				(SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id=o.ID AND meta_key='_billing_country' LIMIT 1) AS billing_country,
				(SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id=o.ID AND meta_key='_billing_city' LIMIT 1) AS billing_city
				FROM {$from}
				WHERE {$where_sql}
				ORDER BY {$sort_col} {$direction}
				LIMIT %d OFFSET %d";
		}

		$page_params = array_merge( $params, [ $f['per_page'], $offset ] );
		$rows        = $wpdb->get_results( $wpdb->prepare( $select, $page_params ) ); // phpcs:ignore

		$items = [];
		foreach ( $rows as $r ) {
			$status_slug = preg_replace( '/^wc-/', '', $r->status );
			$items[] = [
				'id'            => (int) $r->order_id,
				'date'          => $r->date_created_gmt ? mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $r->date_created_gmt ) : '',
				'date_iso'      => $r->date_created_gmt,
				'status'        => $status_slug,
				'status_label'  => wc_get_order_status_name( $status_slug ),
				'total'         => (float) $r->total_amount,
				'total_display' => wp_strip_all_tags( wc_price( (float) $r->total_amount, [ 'currency' => $r->currency ?: null ] ) ),
				'customer_id'   => (int) $r->customer_id,
				'name'          => trim( (string) $r->billing_name ),
				'email'         => (string) $r->billing_email,
				'country'       => (string) $r->billing_country,
				'city'          => (string) $r->billing_city,
				'payment'       => (string) ( $r->payment_method_title ?: $r->payment_method ),
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
	 * Build customer segmentation from per-customer order aggregates.
	 *
	 * Strategy: group HPOS orders (or legacy posts + _customer_user meta) to
	 * compute order_count / spent / last_order per customer_id, then join
	 * to wp_users to resolve identities. Guest orders are aggregated by
	 * billing_email (customer_id = 0) so shops with mostly-guest checkouts
	 * still get meaningful segmentation.
	 */
	private function query_customers( array $f ) {
		global $wpdb;

		$hpos = $this->is_hpos();

		// Build the inner per-customer aggregate query so all customer-level
		// filters (spent range, order count, last order date, recency
		// filters that depend on orders) are applied consistently.
		$params = [];
		$order_where = [];
		if ( $hpos ) {
			$order_from = "{$wpdb->prefix}wc_orders o";
			$order_where[] = "o.type = 'shop_order'";
			$order_where[] = "o.status IN ('wc-completed','wc-processing','wc-on-hold','wc-pending','wc-refunded')";
		} else {
			$order_from = "{$wpdb->posts} o";
			$order_where[] = "o.post_type = 'shop_order'";
			$order_where[] = "o.post_status IN ('wc-completed','wc-processing','wc-on-hold','wc-pending','wc-refunded')";
		}

		// Country filter at order level (applies only to aggregated orders)
		if ( ! empty( $f['countries'] ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $f['countries'] ), '%s' ) );
			if ( $hpos ) {
				$order_where[] = "EXISTS (SELECT 1 FROM {$wpdb->prefix}wc_order_addresses oa WHERE oa.order_id = o.id AND oa.address_type='billing' AND oa.country IN ($placeholders))";
			} else {
				$order_where[] = "EXISTS (SELECT 1 FROM {$wpdb->postmeta} pm_co WHERE pm_co.post_id = o.ID AND pm_co.meta_key='_billing_country' AND pm_co.meta_value IN ($placeholders))";
			}
			$params = array_merge( $params, $f['countries'] );
		}
		if ( $f['city'] !== '' ) {
			if ( $hpos ) {
				$order_where[] = "EXISTS (SELECT 1 FROM {$wpdb->prefix}wc_order_addresses oa2 WHERE oa2.order_id = o.id AND oa2.address_type='billing' AND oa2.city LIKE %s)";
			} else {
				$order_where[] = "EXISTS (SELECT 1 FROM {$wpdb->postmeta} pm_ci WHERE pm_ci.post_id = o.ID AND pm_ci.meta_key='_billing_city' AND pm_ci.meta_value LIKE %s)";
			}
			$params[] = '%' . $wpdb->esc_like( $f['city'] ) . '%';
		}

		// Product / category filter on the orders the customer has placed
		if ( ! empty( $f['product_ids'] ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $f['product_ids'] ), '%d' ) );
			$order_col    = $hpos ? 'o.id' : 'o.ID';
			$order_where[] = "EXISTS (SELECT 1 FROM {$wpdb->prefix}wc_order_product_lookup opl WHERE opl.order_id = {$order_col} AND (opl.product_id IN ($placeholders) OR opl.variation_id IN ($placeholders)))";
			$params = array_merge( $params, $f['product_ids'], $f['product_ids'] );
		}
		if ( ! empty( $f['category_ids'] ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $f['category_ids'] ), '%d' ) );
			$order_col    = $hpos ? 'o.id' : 'o.ID';
			$order_where[] = "EXISTS (
				SELECT 1 FROM {$wpdb->prefix}wc_order_product_lookup opl2
				INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = opl2.product_id
				INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'product_cat'
				WHERE opl2.order_id = {$order_col} AND tt.term_id IN ($placeholders)
			)";
			$params = array_merge( $params, $f['category_ids'] );
		}

		// Date range on orders (if user wants "customers who ordered in period")
		if ( $f['date_from'] !== '' ) {
			$order_where[] = $hpos ? 'o.date_created_gmt >= %s' : 'o.post_date_gmt >= %s';
			$params[]      = gmdate( 'Y-m-d 00:00:00', strtotime( $f['date_from'] ) );
		}
		if ( $f['date_to'] !== '' ) {
			$order_where[] = $hpos ? 'o.date_created_gmt <= %s' : 'o.post_date_gmt <= %s';
			$params[]      = gmdate( 'Y-m-d 23:59:59', strtotime( $f['date_to'] ) );
		}

		$order_where_sql = implode( ' AND ', $order_where );

		// Build aggregate per customer key. HPOS uses customer_id; for guests
		// (customer_id = 0) we fall back to LOWER(billing_email).
		if ( $hpos ) {
			$customer_key   = "IF(o.customer_id > 0, CONCAT('u:', o.customer_id), CONCAT('e:', LOWER(o.billing_email)))";
			$user_id_expr   = 'MAX(o.customer_id)';
			$email_expr     = "MAX(o.billing_email)";
			$total_expr     = 'COALESCE(SUM(o.total_amount), 0)';
			$last_expr      = 'MAX(o.date_created_gmt)';
			$first_expr     = 'MIN(o.date_created_gmt)';
		} else {
			$customer_key   = "IFNULL((SELECT CASE WHEN pm_u.meta_value IS NOT NULL AND pm_u.meta_value+0 > 0 THEN CONCAT('u:', pm_u.meta_value) ELSE CONCAT('e:', LOWER((SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id=o.ID AND meta_key='_billing_email' LIMIT 1))) END FROM {$wpdb->postmeta} pm_u WHERE pm_u.post_id=o.ID AND pm_u.meta_key='_customer_user' LIMIT 1), CONCAT('e:', LOWER((SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id=o.ID AND meta_key='_billing_email' LIMIT 1))))";
			$user_id_expr   = "MAX((SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id=o.ID AND meta_key='_customer_user' LIMIT 1))";
			$email_expr     = "MAX((SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id=o.ID AND meta_key='_billing_email' LIMIT 1))";
			$total_expr     = "COALESCE(SUM((SELECT CAST(meta_value AS DECIMAL(20,4)) FROM {$wpdb->postmeta} WHERE post_id=o.ID AND meta_key='_order_total' LIMIT 1)), 0)";
			$last_expr      = 'MAX(o.post_date_gmt)';
			$first_expr     = 'MIN(o.post_date_gmt)';
		}

		// HAVING clauses for post-aggregation filters (spent, count, last order, registered)
		$having = [];
		$having_params = [];
		if ( $f['spent_min'] !== null ) {
			$having[] = 'total_spent >= %f';
			$having_params[] = $f['spent_min'];
		}
		if ( $f['spent_max'] !== null ) {
			$having[] = 'total_spent <= %f';
			$having_params[] = $f['spent_max'];
		}
		if ( $f['order_count_min'] !== null ) {
			$having[] = 'order_count >= %d';
			$having_params[] = $f['order_count_min'];
		}
		if ( $f['order_count_max'] !== null ) {
			$having[] = 'order_count <= %d';
			$having_params[] = $f['order_count_max'];
		}
		if ( $f['last_order_from'] !== '' ) {
			$having[] = 'last_order_date >= %s';
			$having_params[] = gmdate( 'Y-m-d 00:00:00', strtotime( $f['last_order_from'] ) );
		}
		if ( $f['last_order_to'] !== '' ) {
			$having[] = 'last_order_date <= %s';
			$having_params[] = gmdate( 'Y-m-d 23:59:59', strtotime( $f['last_order_to'] ) );
		}

		$having_sql = $having ? ( 'HAVING ' . implode( ' AND ', $having ) ) : '';

		// Inner aggregate subquery
		$inner_sql = "SELECT
				{$customer_key} AS customer_key,
				{$user_id_expr} AS user_id,
				{$email_expr} AS email,
				COUNT(*) AS order_count,
				{$total_expr} AS total_spent,
				{$last_expr} AS last_order_date,
				{$first_expr} AS first_order_date
			FROM {$order_from}
			WHERE {$order_where_sql}
			GROUP BY customer_key
			{$having_sql}";

		$inner_params = array_merge( $params, $having_params );

		// Search + registration date filter wrap the aggregate
		$outer_where = [];
		$outer_params = [];
		if ( $f['search'] !== '' ) {
			$like = '%' . $wpdb->esc_like( $f['search'] ) . '%';
			$outer_where[] = '(agg.email LIKE %s OR u.display_name LIKE %s OR u.user_email LIKE %s OR CONCAT_WS(" ", bm_fn.meta_value, bm_ln.meta_value) LIKE %s)';
			$outer_params[] = $like; $outer_params[] = $like; $outer_params[] = $like; $outer_params[] = $like;
		}
		if ( $f['registered_from'] !== '' ) {
			$outer_where[] = 'u.user_registered >= %s';
			$outer_params[] = gmdate( 'Y-m-d 00:00:00', strtotime( $f['registered_from'] ) );
		}
		if ( $f['registered_to'] !== '' ) {
			$outer_where[] = 'u.user_registered <= %s';
			$outer_params[] = gmdate( 'Y-m-d 23:59:59', strtotime( $f['registered_to'] ) );
		}

		// RFM segment filter — relies on the precomputed customer_metrics
		// table which is keyed by customer_key (matching the aggregate
		// expression above). Customers without a row in that table are
		// excluded when an RFM filter is active.
		$rfm_join = '';
		if ( ! empty( $f['rfm_segments'] ) ) {
			$rfm_join = " INNER JOIN {$wpdb->prefix}brikpanel_customer_metrics rfm ON rfm.customer_key = agg.customer_key ";
			$placeholders = implode( ',', array_fill( 0, count( $f['rfm_segments'] ), '%s' ) );
			$outer_where[] = "rfm.rfm_segment IN ({$placeholders})";
			$outer_params = array_merge( $outer_params, $f['rfm_segments'] );
		}
		$outer_where_sql = $outer_where ? ( 'WHERE ' . implode( ' AND ', $outer_where ) ) : '';

		// Sort
		$sort_col = 'agg.last_order_date';
		if ( $f['sort'] === 'spent' ) {
			$sort_col = 'agg.total_spent';
		} elseif ( $f['sort'] === 'count' ) {
			$sort_col = 'agg.order_count';
		} elseif ( $f['sort'] === 'first_order' ) {
			$sort_col = 'agg.first_order_date';
		}
		$direction = $f['order'] === 'ASC' ? 'ASC' : 'DESC';

		// Count (wraps the aggregate)
		$count_sql = "SELECT COUNT(*) FROM ({$inner_sql}) agg
			{$rfm_join}
			LEFT JOIN {$wpdb->users} u ON CAST(agg.user_id AS UNSIGNED) = u.ID
			LEFT JOIN {$wpdb->usermeta} bm_fn ON bm_fn.user_id = u.ID AND bm_fn.meta_key = 'billing_first_name'
			LEFT JOIN {$wpdb->usermeta} bm_ln ON bm_ln.user_id = u.ID AND bm_ln.meta_key = 'billing_last_name'
			{$outer_where_sql}";
		$count_params = array_merge( $inner_params, $outer_params );
		$total = (int) $wpdb->get_var( $count_params ? $wpdb->prepare( $count_sql, $count_params ) : $count_sql ); // phpcs:ignore

		// Summary
		$sum_sql = "SELECT COALESCE(SUM(agg.total_spent),0) AS total_spent, COALESCE(SUM(agg.order_count),0) AS orders FROM ({$inner_sql}) agg
			{$rfm_join}
			LEFT JOIN {$wpdb->users} u ON CAST(agg.user_id AS UNSIGNED) = u.ID
			LEFT JOIN {$wpdb->usermeta} bm_fn ON bm_fn.user_id = u.ID AND bm_fn.meta_key = 'billing_first_name'
			LEFT JOIN {$wpdb->usermeta} bm_ln ON bm_ln.user_id = u.ID AND bm_ln.meta_key = 'billing_last_name'
			{$outer_where_sql}";
		$sum_row = $wpdb->get_row( $count_params ? $wpdb->prepare( $sum_sql, $count_params ) : $sum_sql ); // phpcs:ignore
		$sum_spent  = (float) ( $sum_row->total_spent ?? 0 );
		$sum_orders = (int) ( $sum_row->orders ?? 0 );

		// Page
		$offset = ( $f['page'] - 1 ) * $f['per_page'];

		$page_sql = "SELECT agg.*,
				u.display_name,
				u.user_email AS registered_email,
				u.user_registered,
				bm_fn.meta_value AS billing_first_name,
				bm_ln.meta_value AS billing_last_name
			FROM ({$inner_sql}) agg
			{$rfm_join}
			LEFT JOIN {$wpdb->users} u ON CAST(agg.user_id AS UNSIGNED) = u.ID
			LEFT JOIN {$wpdb->usermeta} bm_fn ON bm_fn.user_id = u.ID AND bm_fn.meta_key = 'billing_first_name'
			LEFT JOIN {$wpdb->usermeta} bm_ln ON bm_ln.user_id = u.ID AND bm_ln.meta_key = 'billing_last_name'
			{$outer_where_sql}
			ORDER BY {$sort_col} {$direction}
			LIMIT %d OFFSET %d";
		$page_params = array_merge( $count_params, [ $f['per_page'], $offset ] );
		$rows = $wpdb->get_results( $wpdb->prepare( $page_sql, $page_params ) ); // phpcs:ignore

		$items = [];
		foreach ( $rows as $r ) {
			$user_id = (int) $r->user_id;
			$name = trim( trim( (string) $r->billing_first_name . ' ' . (string) $r->billing_last_name ) );
			if ( $name === '' ) {
				$name = (string) $r->display_name;
			}
			if ( $name === '' ) {
				$name = (string) ( $r->registered_email ?: $r->email );
			}

			$items[] = [
				'user_id'              => $user_id,
				'name'                 => $name,
				'email'                => (string) ( $r->registered_email ?: $r->email ),
				'registered'           => $r->user_registered ? mysql2date( get_option( 'date_format' ), $r->user_registered ) : ( $user_id ? '' : __( 'Guest', 'brikpanel' ) ),
				'registered_iso'       => (string) $r->user_registered,
				'order_count'          => (int) $r->order_count,
				'total_spent'          => (float) $r->total_spent,
				'total_spent_display'  => wp_strip_all_tags( wc_price( (float) $r->total_spent ) ),
				'last_order'           => $r->last_order_date ? mysql2date( get_option( 'date_format' ), $r->last_order_date ) : '',
				'last_order_iso'       => (string) $r->last_order_date,
				'first_order'          => $r->first_order_date ? mysql2date( get_option( 'date_format' ), $r->first_order_date ) : '',
				'aov'                  => $r->order_count > 0 ? ( (float) $r->total_spent / (int) $r->order_count ) : 0,
				'aov_display'          => wp_strip_all_tags( wc_price( $r->order_count > 0 ? ( (float) $r->total_spent / (int) $r->order_count ) : 0 ) ),
				'edit_url'             => $user_id ? admin_url( 'user-edit.php?user_id=' . $user_id ) : '',
				'is_guest'             => $user_id === 0,
			];
		}

		return [
			'items'   => $items,
			'total'   => $total,
			'page'    => $f['page'],
			'per_page' => $f['per_page'],
			'pages'   => (int) ceil( $total / $f['per_page'] ),
			'summary' => [
				'count'           => $total,
				'revenue'         => $sum_spent,
				'revenue_display' => wp_strip_all_tags( wc_price( $sum_spent ) ),
				'orders'          => $sum_orders,
				'aov'             => $sum_orders > 0 ? $sum_spent / $sum_orders : 0,
				'aov_display'     => wp_strip_all_tags( wc_price( $sum_orders > 0 ? $sum_spent / $sum_orders : 0 ) ),
			],
		];
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
		header( 'Content-Disposition: attachment; filename="brikpanel-segments-' . $tab . '-' . gmdate( 'Ymd-His' ) . '.csv"' );

		$out = fopen( 'php://output', 'w' );
		// UTF-8 BOM so Excel opens non-ASCII characters cleanly.
		fwrite( $out, "\xEF\xBB\xBF" );

		if ( $tab === 'orders' ) {
			$result = $this->query_orders( $filters );
			fputcsv( $out, [
				__( 'Order ID', 'brikpanel' ),
				__( 'Date', 'brikpanel' ),
				__( 'Status', 'brikpanel' ),
				__( 'Customer', 'brikpanel' ),
				__( 'Email', 'brikpanel' ),
				__( 'Country', 'brikpanel' ),
				__( 'City', 'brikpanel' ),
				__( 'Payment', 'brikpanel' ),
				__( 'Total', 'brikpanel' ),
			] );
			foreach ( $result['items'] as $item ) {
				fputcsv( $out, [
					$item['id'],
					$item['date_iso'],
					$item['status_label'],
					$item['name'],
					$item['email'],
					$item['country'],
					$item['city'],
					$item['payment'],
					$item['total'],
				] );
			}
		} else {
			$result = $this->query_customers( $filters );
			fputcsv( $out, [
				__( 'User ID', 'brikpanel' ),
				__( 'Name', 'brikpanel' ),
				__( 'Email', 'brikpanel' ),
				__( 'Registered', 'brikpanel' ),
				__( 'Orders', 'brikpanel' ),
				__( 'Total spent', 'brikpanel' ),
				__( 'Average order value', 'brikpanel' ),
				__( 'First order', 'brikpanel' ),
				__( 'Last order', 'brikpanel' ),
			] );
			foreach ( $result['items'] as $item ) {
				fputcsv( $out, [
					$item['user_id'] ?: '',
					$item['name'],
					$item['email'],
					$item['registered_iso'],
					$item['order_count'],
					$item['total_spent'],
					$item['aov'],
					$item['first_order'],
					$item['last_order_iso'],
				] );
			}
		}

		fclose( $out );
		exit;
	}
}

new Brikpanel_Segments();
