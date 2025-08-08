<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Brik82ad_Pro_Search {
	public function __construct() {
		// We also enqueue the scripts for the public side of WordPress because
		// for logged in admins, the admin bar shows at the top there too.
		add_action( 'admin_bar_menu', array( $this, 'add_search_to_admin_bar' ), 999 );
		add_action( 'wp_ajax_brik82ad_search', array( $this, 'handle_search_query' ) );
	}



	public function add_search_to_admin_bar( WP_Admin_Bar $admin_bar ) {
		$admin_bar->add_menu(
			array(
				'id'    => 'brik82ad-search',
				'title' => $this->generate_search_html(),
			)
		);
	}

	private function generate_search_html() {
		$initial_order_list = $this->generate_initial_order_list_html();
	
		if (!file_exists(plugin_dir_path(__FILE__) . 'search.svg')) {
			return '<div class="brik82ad-search-menu-item">' . esc_html__('Search orders', 'brikpanel-admin-panel-dashboard-for-woocommerce') . '</div>';
		}
	
		ob_start(); // Output buffering kullanarak HTML'yi daha temiz yönetelim
		?>
		<div class="brik82ad-search-menu-item-mobile ab-icon"></div>
		<div class="brik82ad-search-menu-item">
			<span class="ab-icon"></span>
			<span class="placeholder"><?php echo esc_html__('Search orders', 'brikpanel-admin-panel-dashboard-for-woocommerce'); ?></span>
			<span class="shortcut">
				<span id="shortcut-key"></span> + K
			</span>
		</div>
		<div class="brik82ad-search-overlay hidden">
			<div class="brik82ad-search-modal">
				<div class="input-container">
					<img src="<?php echo esc_url(plugins_url('search.svg', __FILE__)); ?>" class="icon" alt="Search Icon">
					<input placeholder="<?php echo esc_attr__('Search orders', 'brikpanel-admin-panel-dashboard-for-woocommerce'); ?>">
				</div>
				<div class="results">
					<section>
						<div class="heading"><?php echo esc_html__('Orders', 'brikpanel-admin-panel-dashboard-for-woocommerce'); ?></div>
						<div class="result-list">
							<p class="hint-text">
								<?php echo esc_html__('You can search orders by customer name, email, phone, order ID, or product SKUs within an order.', 'brikpanel-admin-panel-dashboard-for-woocommerce'); ?>
							</p>
						</div>
					</section>
					<hr class="section-divider">
					<section>
						<div class="heading"><?php echo esc_html__('Recent orders', 'brikpanel-admin-panel-dashboard-for-woocommerce'); ?></div>
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
			// Nonce kontrolü
	if (
		!isset($_GET['security']) ||
		!wp_verify_nonce(
			sanitize_text_field( wp_unslash( $_GET['security'] ) ),
			'brik82ad_nonce'
		)
	) {
		wp_send_json_error(['message' => 'Güvenlik doğrulaması başarısız.']);
		return;
	}


	// Yetki kontrolü
	if (!current_user_can('manage_woocommerce')) {
		wp_send_json_error(['message' => 'Bu işlemi yapma yetkiniz yok.']);
		wp_die();
	}


		$query = '';

		if ( isset($_GET['query']) ) {
			$query = sanitize_text_field(wp_unslash($_GET['query']));
		}
		$no_results_found_html = '<p class="hint-text">No results found</p>';

		$orders = array();

		if ( is_numeric( $query ) ) {
			$order = wc_get_order( intval( $query ) );
			if ( $order && ! $order instanceof WC_Order_Refund ) {
				$orders[] = $order;
			}
		}

		$orders = array_unique(
			array_merge(
				$orders,
				wc_get_orders(
					array(
						'meta_key' => '_order_number',
						'meta_value' => $query,
					)
				),
				wc_get_orders(
					array(
						// Email or customer ID
						'customer' => $query,
					)
				),
				wc_get_orders(
					array(
						'billing_phone' => $query,
					)
				),
				wc_get_orders(
					array(
						'billing_first_name' => $query,
					)
				),
				wc_get_orders(
					array(
						'billing_last_name' => $query,
					)
				),
				wc_get_orders(
					array(
						'shipping_first_name' => $query,
					)
				),
				wc_get_orders(
					array(
						'shipping_last_name' => $query,
					)
				)
			)
		);

		if ( count( explode( ' ', $query ) ) === 2 ) {
			$parts  = explode( ' ', $query );
			$orders = array_unique(
				array_merge(
					$orders,
					wc_get_orders(
						array(
							'billing_first_name' => $parts[0],
							'billing_last_name'  => $parts[1],
						)
					),
					wc_get_orders(
						array(
							'shipping_first_name' => $parts[0],
							'shipping_last_name'  => $parts[1],
						)
					),
					wc_get_orders(
						array(
							'billing_last_name'  => $parts[0],
							'billing_first_name' => $parts[1],
						)
					),
					wc_get_orders(
						array(
							'shipping_last_name'  => $parts[0],
							'shipping_first_name' => $parts[1],
						)
					)
				)
			);
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
	 * Get WooCommerce orders by product SKU using SQL, excluding refunds and including product info.
	 *
	 * @param string $sku - The product SKU to search for.
	 * @return array - An array of arrays, each containing a WC_Order object, the matching WC_Product, and a flag indicating it was found by SKU.
	 */
	private function get_orders_by_product_sku( $sku ) {
		global $wpdb;
	
		$is_hpos = 'yes' === get_option('woocommerce_custom_orders_table_enabled');
	
		$select = 'SELECT DISTINCT orders.ID as order_id, COALESCE(variations.ID, products.ID) as product_id';
	
		$where = 'WHERE (product_meta.meta_value = %s OR variation_meta.meta_value = %s)';
	
		if ($is_hpos) {
			$from = "FROM {$wpdb->prefix}wc_orders AS orders";
			$where .= " AND orders.type = 'shop_order'"; // HPOS için refunds hariç
		} else {
			$from = "FROM {$wpdb->posts} AS orders";
			$where .= " AND orders.post_type = 'shop_order'"; // Non-HPOS için refunds hariç
		}
	
		$join = "
			JOIN {$wpdb->prefix}woocommerce_order_items AS order_items
				ON orders.ID = order_items.order_id
			JOIN {$wpdb->prefix}woocommerce_order_itemmeta AS order_item_meta_product
				ON order_items.order_item_id = order_item_meta_product.order_item_id
				AND order_item_meta_product.meta_key = '_product_id'
			LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta AS order_item_meta_variation
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
		";
			
		$cache_key = 'custom_query_' . md5($sku); // Önbellek anahtarı
		$results = wp_cache_get($cache_key, 'custom_queries');
		
		if ($results === false) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Güvenli bir şekilde veritabanı sorgusu yapılmaktadır.
			$results = $wpdb->get_results($wpdb->prepare("$select $from $join $where", $sku, $sku));
		
			// Önbelleğe kaydet
			wp_cache_set($cache_key, $results, 'custom_queries', 3600); // 1 saat önbellekte tut
		}
		
		// Kullanım
		return $results;
		
			
		if (empty($results)) {
			return array();
		}
	
		$orders_with_products = array();
	
		foreach ($results as $result) {
			$order = wc_get_order($result->order_id);
			$product = wc_get_product($result->product_id);
	
			// WooCommerce’in sipariş saklama veya sorgulama mekanizmasındaki değişikliklere karşı ek güvenlik önlemi
			if ($order && !is_a($order, 'WC_Order_Refund') && $product) {
				$orders_with_products[] = array(
					'order' => $order,
					'matching_product' => $product,
					'found_by_sku' => true,
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
			$status = $this->generate_order_status_badge_html(ucfirst(str_replace('-', ' ', esc_html($order->get_status()))));
	
			$first_name = esc_html($order->get_shipping_first_name() ?: $order->get_billing_first_name());
			$last_name  = esc_html($order->get_shipping_last_name() ?: $order->get_billing_last_name());
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
new Brik82ad_Pro_Search();