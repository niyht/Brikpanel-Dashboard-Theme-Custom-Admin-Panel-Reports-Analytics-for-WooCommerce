<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Brikpanel_Pro_Search {
	public function __construct() {
		// We also enqueue the scripts for the public side of WordPress because
		// for logged in admins, the admin bar shows at the top there too.
		add_action( 'admin_bar_menu', array( $this, 'add_search_to_admin_bar' ), 999 );
		add_action( 'wp_ajax_brikpanel_search', array( $this, 'handle_search_query' ) );
	}

	/**
	 * Verify that the request has a valid nonce and the user has the required capability.
	 */
	private function verify_request() {
		if ( ! isset( $_POST['security'] ) || ! wp_verify_nonce( sanitize_key( $_POST['security'] ), 'brikpanel_search_action' ) ) {
			wp_send_json_error( array( 'message' => 'Invalid nonce.' ) );
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized.' ) );
		}
	}



	public function add_search_to_admin_bar( WP_Admin_Bar $admin_bar ) {
		$admin_bar->add_menu(
			array(
				'id'    => 'brikpanel-search',
				'title' => $this->generate_search_html(),
			)
		);
	}

	private function generate_search_html() {
		$initial_order_list = $this->generate_initial_order_list_html();
	
		if (!file_exists(plugin_dir_path(__FILE__) . 'search.svg')) {
			return '<div class="brikpanel-search-menu-item">' . esc_html__('Search orders', 'brikpanel') . '</div>';
		}
	
		ob_start(); // Use output buffering for cleaner HTML management
		?>
		<div class="brikpanel-search-menu-item-mobile ab-icon"></div>
		<div class="brikpanel-search-menu-item">
			<span class="ab-icon"></span>
			<span class="placeholder"><?php echo esc_html__('Search orders', 'brikpanel'); ?></span>
			<span class="shortcut">
				<span id="shortcut-key"></span> + K
			</span>
		</div>
		<div class="brikpanel-search-overlay hidden">
			<div class="brikpanel-search-modal">
				<div class="input-container">
					<img src="<?php echo esc_url(plugins_url('search.svg', __FILE__)); ?>" class="icon" alt="Search Icon">
					<input placeholder="<?php echo esc_attr__('Search orders', 'brikpanel'); ?>">
				</div>
				<div class="results">
					<section>
						<div class="heading"><?php echo esc_html__('Orders', 'brikpanel'); ?></div>
						<div class="result-list">
							<p class="hint-text">
								<?php echo esc_html__('You can search orders by customer name, email, phone, order ID, or product SKUs within an order.', 'brikpanel'); ?>
							</p>
						</div>
					</section>
					<hr class="section-divider">
					<section>
						<div class="heading"><?php echo esc_html__('Recent orders', 'brikpanel'); ?></div>
						<?php echo wp_kses_post($initial_order_list); ?>
					</section>
				</div>
			</div>
		</div>
		<?php
		return ob_get_clean(); // Buffer içeriğini döndürerek HTML bozulmadan korunur
	}
	
	private function generate_initial_order_list_html() {
		$orders = $this->query_recent_orders( 3 );
		return $this->generate_order_results_html( $orders );
	}

	private function query_recent_orders( $limit ) {
		$query = new WC_Order_Query(
			array(
				'type'  => 'shop_order',
				'limit' => $limit,
			)
		);
		return $query->get_orders();
	}

	private function generate_order_status_badge_html($status) {
		$background = '#e5e5e5';
	
		switch ($status) {
			case 'Processing':
				$background = '#c6e1c6';
				break;
			case 'On hold':
				$background = '#f8dda7';
				break;
			case 'Completed':
				$background = '#c8d7e1';
				break;
			case 'Failed':
				$background = '#eba3a3';
				break;
		}
	
		// Kaçış işlemleri
		$background = esc_attr($background); // CSS içinde güvenli kullanım
		$status = esc_html($status); // HTML içinde güvenli kullanım
	
		return '<span class="status-badge text-sm" style="background: ' . $background . ';">' . $status . '</span>';
	}
	

	public function handle_search_query() {
		$this->verify_request();

		$query = '';

		if ( isset($_POST['query']) ) {
			$query = sanitize_text_field(wp_unslash($_POST['query']));
		}
		$no_results_found_html = '<p class="hint-text">No results found</p>';

		$order_ids = $this->search_order_ids( $query );
		$orders    = array();

		if ( ! empty( $order_ids ) ) {
			$orders = wc_get_orders( array( 'post__in' => $order_ids, 'limit' => 20 ) );
		}

		$orders = array_merge(
			$orders,
			$this->get_orders_by_product_sku( $query )
		);

		if ( empty( $orders ) ) {
			echo wp_kses_post( $no_results_found_html );
			wp_die();
		}
		
		echo wp_kses_post( $this->generate_order_results_html( $orders ) );
		wp_die();
		
	}

	/**
	 * Search order IDs by customer info, order number, email, phone — single SQL query.
	 *
	 * @param string $query The search term.
	 * @return array Order IDs.
	 */
	private function search_order_ids( $query ) {
		if ( empty( $query ) ) {
			return array();
		}

		global $wpdb;
		$is_hpos = 'yes' === get_option( 'woocommerce_custom_orders_table_enabled' );

		if ( $is_hpos ) {
			$orders_table = $wpdb->prefix . 'wc_orders';
			$meta_table   = $wpdb->prefix . 'wc_orders_meta';
			$addresses    = $wpdb->prefix . 'wc_order_addresses';

			$sql = "SELECT DISTINCT o.id FROM {$orders_table} o
				LEFT JOIN {$addresses} ba ON o.id = ba.order_id AND ba.address_type = 'billing'
				LEFT JOIN {$addresses} sa ON o.id = sa.order_id AND sa.address_type = 'shipping'
				LEFT JOIN {$meta_table} om ON o.id = om.order_id AND om.meta_key = '_order_number'
				WHERE o.type = 'shop_order' AND (
					o.id = %s
					OR om.meta_value = %s
					OR ba.email = %s
					OR ba.phone = %s
					OR ba.first_name = %s
					OR ba.last_name = %s
					OR sa.first_name = %s
					OR sa.last_name = %s";

			$args = array( $query, $query, $query, $query, $query, $query, $query, $query );

			if ( count( explode( ' ', $query ) ) === 2 ) {
				$parts = explode( ' ', $query );
				$sql .= "
					OR (ba.first_name = %s AND ba.last_name = %s)
					OR (ba.first_name = %s AND ba.last_name = %s)
					OR (sa.first_name = %s AND sa.last_name = %s)
					OR (sa.first_name = %s AND sa.last_name = %s)";
				$args = array_merge( $args, array(
					$parts[0], $parts[1], $parts[1], $parts[0],
					$parts[0], $parts[1], $parts[1], $parts[0],
				) );
			}

			$sql .= ") LIMIT 20";
		} else {
			$sql = "SELECT DISTINCT p.ID FROM {$wpdb->posts} p
				LEFT JOIN {$wpdb->postmeta} pm_on ON p.ID = pm_on.post_id AND pm_on.meta_key = '_order_number'
				LEFT JOIN {$wpdb->postmeta} pm_bf ON p.ID = pm_bf.post_id AND pm_bf.meta_key = '_billing_first_name'
				LEFT JOIN {$wpdb->postmeta} pm_bl ON p.ID = pm_bl.post_id AND pm_bl.meta_key = '_billing_last_name'
				LEFT JOIN {$wpdb->postmeta} pm_be ON p.ID = pm_be.post_id AND pm_be.meta_key = '_billing_email'
				LEFT JOIN {$wpdb->postmeta} pm_bp ON p.ID = pm_bp.post_id AND pm_bp.meta_key = '_billing_phone'
				LEFT JOIN {$wpdb->postmeta} pm_sf ON p.ID = pm_sf.post_id AND pm_sf.meta_key = '_shipping_first_name'
				LEFT JOIN {$wpdb->postmeta} pm_sl ON p.ID = pm_sl.post_id AND pm_sl.meta_key = '_shipping_last_name'
				WHERE p.post_type = 'shop_order' AND (
					p.ID = %s
					OR pm_on.meta_value = %s
					OR pm_be.meta_value = %s
					OR pm_bp.meta_value = %s
					OR pm_bf.meta_value = %s
					OR pm_bl.meta_value = %s
					OR pm_sf.meta_value = %s
					OR pm_sl.meta_value = %s";

			$args = array( $query, $query, $query, $query, $query, $query, $query, $query );

			if ( count( explode( ' ', $query ) ) === 2 ) {
				$parts = explode( ' ', $query );
				$sql .= "
					OR (pm_bf.meta_value = %s AND pm_bl.meta_value = %s)
					OR (pm_bf.meta_value = %s AND pm_bl.meta_value = %s)
					OR (pm_sf.meta_value = %s AND pm_sl.meta_value = %s)
					OR (pm_sf.meta_value = %s AND pm_sl.meta_value = %s)";
				$args = array_merge( $args, array(
					$parts[0], $parts[1], $parts[1], $parts[0],
					$parts[0], $parts[1], $parts[1], $parts[0],
				) );
			}

			$sql .= ") LIMIT 20";
		}

		$results = $wpdb->get_col( $wpdb->prepare( $sql, $args ) );
		return array_map( 'absint', $results );
	}

	/**
	 * Get WooCommerce orders by product SKU using SQL, excluding refunds and including product info.
	 *
	 * @param string $sku - The product SKU to search for.
	 * @return array - An array of arrays, each containing a WC_Order object, the matching WC_Product, and a flag indicating it was found by SKU.
	 */
	private function get_orders_by_product_sku( $sku ) {
		global $wpdb;

		$is_hpos = 'yes' === get_option('woocommerce_custom_orders_table_enabled');

		$orders_table      = $wpdb->prefix . 'wc_orders';
		$order_items_table = $wpdb->prefix . 'woocommerce_order_items';
		$order_itemmeta    = $wpdb->prefix . 'woocommerce_order_itemmeta';

		if ( $is_hpos ) {
			$results = $wpdb->get_results(
				$wpdb->prepare(
					"
					SELECT DISTINCT orders.ID as order_id, COALESCE(variations.ID, products.ID) as product_id
					FROM {$orders_table} AS orders
					JOIN {$order_items_table} AS order_items
						ON orders.ID = order_items.order_id
					JOIN {$order_itemmeta} AS order_item_meta_product
						ON order_items.order_item_id = order_item_meta_product.order_item_id
						AND order_item_meta_product.meta_key = '_product_id'
					LEFT JOIN {$order_itemmeta} AS order_item_meta_variation
						ON order_items.order_item_id = order_item_meta_variation.order_item_id
						AND order_item_meta_variation.meta_key = '_variation_id'
					JOIN {$wpdb->posts} AS products
						ON order_item_meta_product.meta_value = products.ID
					LEFT JOIN {$wpdb->posts} AS variations
						ON order_item_meta_variation.meta_value = variations.ID
						AND variations.post_type = 'product_variation'
					LEFT JOIN {$wpdb->postmeta} AS product_meta
						ON products.ID = product_meta.post_id
						AND product_meta.meta_key = '_sku'
					LEFT JOIN {$wpdb->postmeta} AS variation_meta
						ON variations.ID = variation_meta.post_id
						AND variation_meta.meta_key = '_sku'
					WHERE (product_meta.meta_value = %s OR variation_meta.meta_value = %s)
					AND orders.type = 'shop_order'
					",
					$sku,
					$sku
				)
			);
		} else {
			$results = $wpdb->get_results(
				$wpdb->prepare(
					"
					SELECT DISTINCT orders.ID as order_id, COALESCE(variations.ID, products.ID) as product_id
					FROM {$wpdb->posts} AS orders
					JOIN {$order_items_table} AS order_items
						ON orders.ID = order_items.order_id
					JOIN {$order_itemmeta} AS order_item_meta_product
						ON order_items.order_item_id = order_item_meta_product.order_item_id
						AND order_item_meta_product.meta_key = '_product_id'
					LEFT JOIN {$order_itemmeta} AS order_item_meta_variation
						ON order_items.order_item_id = order_item_meta_variation.order_item_id
						AND order_item_meta_variation.meta_key = '_variation_id'
					JOIN {$wpdb->posts} AS products
						ON order_item_meta_product.meta_value = products.ID
					LEFT JOIN {$wpdb->posts} AS variations
						ON order_item_meta_variation.meta_value = variations.ID
						AND variations.post_type = 'product_variation'
					LEFT JOIN {$wpdb->postmeta} AS product_meta
						ON products.ID = product_meta.post_id
						AND product_meta.meta_key = '_sku'
					LEFT JOIN {$wpdb->postmeta} AS variation_meta
						ON variations.ID = variation_meta.post_id
						AND variation_meta.meta_key = '_sku'
					WHERE (product_meta.meta_value = %s OR variation_meta.meta_value = %s)
					AND orders.post_type = 'shop_order'
					",
					$sku,
					$sku
				)
			);
		}

		if ( empty( $results ) ) {
			return array();
		}

		// Batch-load orders and products to avoid N+1 queries.
		$order_ids   = array_unique( wp_list_pluck( $results, 'order_id' ) );
		$product_ids = array_unique( wp_list_pluck( $results, 'product_id' ) );

		$orders_map = array();
		foreach ( wc_get_orders( array( 'post__in' => $order_ids, 'limit' => -1 ) ) as $o ) {
			$orders_map[ $o->get_id() ] = $o;
		}

		$products_map = array();
		foreach ( wc_get_products( array( 'include' => $product_ids, 'limit' => -1 ) ) as $p ) {
			$products_map[ $p->get_id() ] = $p;
		}

		$orders_with_products = array();

		foreach ( $results as $result ) {
			$order   = isset( $orders_map[ $result->order_id ] ) ? $orders_map[ $result->order_id ] : null;
			$product = isset( $products_map[ $result->product_id ] ) ? $products_map[ $result->product_id ] : null;

			if ( $order && ! is_a( $order, 'WC_Order_Refund' ) && $product ) {
				$orders_with_products[] = array(
					'order'           => $order,
					'matching_product'=> $product,
					'found_by_sku'    => true,
				);
			}
		}

		return $orders_with_products;
	}
	
	private function generate_order_results_html($orders) {
		$li = '';
	
		foreach ($orders as $order_data) {
			if (is_array($order_data) && isset($order_data['found_by_sku'])) {
				$order = $order_data['order'];
				$matching_product = $order_data['matching_product'];
			} else {
				$order = $order_data;
			}
	
			$edit_url = esc_url($order->get_edit_order_url()); // Güvenli URL
	
			$number = esc_html($order->get_order_number()); // Order numarası güvenli hale getirildi
			$status = $this->generate_order_status_badge_html(ucfirst(str_replace('-', ' ', esc_html($order->get_status() ?? ''))));

			$first_name = esc_html($order->get_shipping_first_name() ?: ($order->get_billing_first_name() ?? ''));
			$last_name  = esc_html($order->get_shipping_last_name() ?: ($order->get_billing_last_name() ?? ''));
			$name       = trim("$first_name $last_name");
	
			$divider = $name === '' ? '' : '<span class="text-sm"> • </span>';
	
			$date_format = esc_html(get_option('date_format', 'F j'));
			$time_format = esc_html(get_option('time_format', 'g:i a'));
			$format = "$date_format \a\\t $time_format";
	
			$date_created = $order->get_date_created();
			$date_created_attr = $date_created ? esc_attr($date_created->date('c')) : '';
			$date_created_formatted = $date_created ? esc_html($date_created->date_i18n($format)) : '';
	
			$product_html = '';
			if (isset($matching_product)) {
				$product_title = esc_html($matching_product->get_formatted_name());
				$product_html = '<div class="text-sm matching-order-product">' . $product_title . '</div>';
			}
	
			$li .= '<li>';
			$li .= '    <a href="' . $edit_url . '">';
			$li .= '        <div>';
			$li .= '            <span>#' . $number . '</span>';
			$li .=              $status;
			$li .= '        </div>';
			$li .= '        <div class="order-info">';
			$li .= '            <span class="text-sm">' . $name . '</span>';
			$li .=              $divider;
			$li .= '            <span class="text-sm">';
			$li .= '                Placed on <time class="order-date text-sm" datetime="' . $date_created_attr . '">' . $date_created_formatted . '</time>';
			$li .= '            </span>';
			$li .=              $product_html;
			$li .= '        </div>';
			$li .= '    </a>';
			$li .= '</li>';
		}
	
		return '<ul>' . $li . '</ul>';
	}
	
}
new Brikpanel_Pro_Search();