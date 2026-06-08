<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * BrikPanel Cmd/Ctrl+K command palette.
 *
 * Searches multiple sources (orders, products, customers, and admin
 * navigation) and is extensible by third parties through the
 * `brikpanel_search_sources` filter. Each source is independently
 * toggleable from BrikPanel settings (WooCommerce > BrikPanel > Search),
 * so the palette can be narrowed back to orders-only or widened to a
 * full site search.
 */
class Brikpanel_Pro_Search {

	/**
	 * Transient key prefix for the per-user admin navigation index.
	 * The index is captured on real admin page loads (where $menu /
	 * $submenu are populated) and read back during the AJAX request,
	 * because admin-ajax.php never builds the admin menu itself.
	 */
	const NAV_INDEX_PREFIX = 'brikpanel_nav_index_';

	public function __construct() {
		// We also enqueue the scripts for the public side of WordPress because
		// for logged in admins, the admin bar shows at the top there too.
		add_action( 'admin_bar_menu', array( $this, 'add_search_to_admin_bar' ), 999 );
		add_action( 'wp_ajax_brikpanel_search', array( $this, 'handle_search_query' ) );

		// Capture the resolved admin menu (including every third-party
		// plugin's pages) on normal admin loads so navigation search works
		// inside admin-ajax, which never runs wp-admin/menu.php.
		add_action( 'admin_menu', array( $this, 'capture_navigation_index' ), PHP_INT_MAX );

		// Settings UI: a dedicated "Search" section with one toggle per
		// source so the palette scope is fully controllable.
		add_filter( 'brikpanel_settings_fields', array( $this, 'register_settings_fields' ), 6 );
		add_filter( 'woocommerce_get_sections_brikpanel', array( $this, 'register_settings_section' ) );
		add_filter( 'brikpanel_settings_title_section_map', array( $this, 'register_settings_section_map' ) );
	}

	// =========================================================================
	// Request guard
	// =========================================================================

	/**
	 * Verify that the request has a valid nonce and the user has the required
	 * capability. `manage_woocommerce` is the baseline gate (the palette only
	 * renders for shop managers / admins); finer per-source capability checks
	 * are applied again before each source runs.
	 */
	private function verify_request() {
		if ( ! isset( $_POST['security'] ) || ! wp_verify_nonce( sanitize_key( $_POST['security'] ), 'brikpanel_search_action' ) ) {
			wp_send_json_error( array( 'message' => 'Invalid nonce.' ) );
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized.' ) );
		}
	}

	// =========================================================================
	// Source registry
	// =========================================================================

	/**
	 * Build the ordered list of active search sources.
	 *
	 * Each source is an array:
	 *  - id         (string)   unique key, also the settings toggle suffix
	 *  - label      (string)   section heading shown in the palette
	 *  - capability (string)   capability required to run/see this source
	 *  - callback   (callable) fn( string $query ): string  -> result <li> markup
	 *
	 * Third parties can add, remove or reorder sources via the
	 * `brikpanel_search_sources` filter. The filtered list is still
	 * capability-checked and the per-source enable toggle still applies to
	 * built-in sources (custom sources may opt out by using their own id).
	 *
	 * @return array<int,array>
	 */
	private function get_sources() {
		$sources = array(
			array(
				'id'         => 'orders',
				'label'      => __( 'Orders', 'brikpanel' ),
				'capability' => 'manage_woocommerce',
				'callback'   => array( $this, 'source_orders' ),
			),
			array(
				'id'         => 'products',
				'label'      => __( 'Products', 'brikpanel' ),
				'capability' => 'edit_products',
				'callback'   => array( $this, 'source_products' ),
			),
			array(
				'id'         => 'customers',
				'label'      => __( 'Customers', 'brikpanel' ),
				'capability' => 'list_users',
				'callback'   => array( $this, 'source_customers' ),
			),
			array(
				'id'         => 'navigation',
				'label'      => __( 'Navigate to', 'brikpanel' ),
				'capability' => 'read',
				'callback'   => array( $this, 'source_navigation' ),
			),
		);

		/**
		 * Filter the BrikPanel command palette search sources.
		 *
		 * @param array  $sources Ordered list of source definitions.
		 * @param string $context Always 'admin' for now (reserved).
		 */
		$sources = apply_filters( 'brikpanel_search_sources', $sources, 'admin' );

		return is_array( $sources ) ? $sources : array();
	}

	/**
	 * Whether a built-in source is enabled in settings. Defaults to 'yes'
	 * (broad search) so the palette is useful out of the box; admins can
	 * narrow it from WooCommerce > BrikPanel > Search. Custom sources added
	 * through the filter use their own id; if no option exists they are
	 * treated as enabled.
	 */
	private function is_source_enabled( $source_id ) {
		return 'no' !== get_option( 'brikpanel_search_' . sanitize_key( $source_id ), 'yes' );
	}

	// =========================================================================
	// Admin bar UI
	// =========================================================================

	public function add_search_to_admin_bar( WP_Admin_Bar $admin_bar ) {
		$admin_bar->add_menu(
			array(
				'id'    => 'brikpanel-search',
				'title' => $this->generate_search_html(),
			)
		);
	}

	private function generate_search_html() {
		if ( ! file_exists( plugin_dir_path( __FILE__ ) . 'search.svg' ) ) {
			return '<div class="brikpanel-search-menu-item">' . esc_html__( 'Search', 'brikpanel' ) . '</div>';
		}

		$placeholder = $this->get_placeholder_text();

		ob_start();
		?>
		<div class="brikpanel-search-menu-item-mobile ab-icon"></div>
		<div class="brikpanel-search-menu-item">
			<span class="ab-icon"></span>
			<span class="placeholder"><?php echo esc_html( $placeholder ); ?></span>
			<span class="shortcut">
				<span id="shortcut-key"></span> + K
			</span>
		</div>
		<div class="brikpanel-search-overlay hidden">
			<div class="brikpanel-search-modal">
				<div class="input-container">
					<img src="<?php echo esc_url( plugins_url( 'search.svg', __FILE__ ) ); ?>" class="icon" alt="<?php esc_attr_e( 'Search', 'brikpanel' ); ?>">
					<input placeholder="<?php echo esc_attr( $placeholder ); ?>" aria-label="<?php echo esc_attr( $placeholder ); ?>">
				</div>
				<div class="results">
					<?php echo wp_kses_post( $this->generate_initial_html() ); ?>
				</div>
				<div class="bp-modal-footer">
					<span><kbd>&#8593;</kbd><kbd>&#8595;</kbd> <?php esc_html_e( 'Navigate', 'brikpanel' ); ?></span>
					<span><kbd>&#8629;</kbd> <?php esc_html_e( 'Open', 'brikpanel' ); ?></span>
					<span><kbd>Esc</kbd> <?php esc_html_e( 'Close', 'brikpanel' ); ?></span>
				</div>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Placeholder / menu label. Stays "Search orders" when orders is the
	 * only active source so existing users see no change, otherwise a
	 * generic label that reflects the wider scope.
	 */
	private function get_placeholder_text() {
		$active = 0;
		$only   = '';
		foreach ( $this->get_sources() as $source ) {
			if ( empty( $source['id'] ) || ! $this->is_source_enabled( $source['id'] ) ) {
				continue;
			}
			if ( ! empty( $source['capability'] ) && ! current_user_can( $source['capability'] ) ) {
				continue;
			}
			$active++;
			$only = $source['id'];
		}

		if ( 1 === $active && 'orders' === $only ) {
			return __( 'Search orders', 'brikpanel' );
		}

		return __( 'Search BrikPanel', 'brikpanel' );
	}

	/**
	 * Initial palette body shown before the user types: a context hint plus
	 * the most recent orders for quick access. The JS caches this and
	 * restores it whenever the query is cleared.
	 */
	private function generate_initial_html() {
		$hint = '<p class="hint-text">' . esc_html( $this->get_hint_text() ) . '</p>';

		$orders_enabled = $this->is_source_enabled( 'orders' ) && current_user_can( 'manage_woocommerce' );
		if ( ! $orders_enabled ) {
			return $hint;
		}

		$recent = $this->generate_order_results_html( $this->query_recent_orders( 3 ) );

		return $hint . $this->render_section( __( 'Recent orders', 'brikpanel' ), 'orders', $recent );
	}

	/**
	 * Context-aware hint text describing what the palette can find given the
	 * currently active sources.
	 */
	private function get_hint_text() {
		$names = array();
		foreach ( $this->get_sources() as $source ) {
			if ( empty( $source['id'] ) || ! $this->is_source_enabled( $source['id'] ) ) {
				continue;
			}
			if ( ! empty( $source['capability'] ) && ! current_user_can( $source['capability'] ) ) {
				continue;
			}
			$names[] = $source['label'];
		}

		if ( empty( $names ) ) {
			return __( 'Type to search.', 'brikpanel' );
		}

		/* translators: %s: comma-separated list of searchable areas, e.g. "Orders, Products, Customers". */
		return sprintf( __( 'Search across: %s. Orders match customer name, email, phone, order ID or product SKU.', 'brikpanel' ), implode( ', ', $names ) );
	}

	// =========================================================================
	// AJAX handler
	// =========================================================================

	public function handle_search_query() {
		$this->verify_request();

		$query = isset( $_POST['query'] ) ? sanitize_text_field( wp_unslash( $_POST['query'] ) ) : '';
		$query = trim( $query );

		if ( '' === $query ) {
			echo wp_kses_post( $this->generate_initial_html() );
			wp_die();
		}

		$html = '';
		$divider = '<hr class="section-divider">';

		foreach ( $this->get_sources() as $source ) {
			if ( empty( $source['id'] ) || empty( $source['callback'] ) || ! is_callable( $source['callback'] ) ) {
				continue;
			}
			if ( ! $this->is_source_enabled( $source['id'] ) ) {
				continue;
			}
			if ( ! empty( $source['capability'] ) && ! current_user_can( $source['capability'] ) ) {
				continue;
			}

			$section = (string) call_user_func( $source['callback'], $query );
			if ( '' === trim( $section ) ) {
				continue;
			}

			$label = isset( $source['label'] ) ? (string) $source['label'] : '';
			$icon  = $this->get_source_icon_mod( $source['id'] );
			$html .= ( '' === $html ? '' : $divider )
				. $this->render_section( $label, $icon, $section );
		}

		if ( '' === $html ) {
			echo wp_kses_post(
				'<div class="bp-empty"><span class="bp-empty-ic"></span>'
				. '<p>' . esc_html__( 'No results found', 'brikpanel' ) . '</p></div>'
			);
			wp_die();
		}

		echo wp_kses_post( $html );
		wp_die();
	}

	// =========================================================================
	// Source: Orders (unchanged search logic, now wrapped as a source)
	// =========================================================================

	private function source_orders( $query ) {
		$order_ids = $this->search_order_ids( $query );
		$orders    = array();

		if ( ! empty( $order_ids ) ) {
			$orders = wc_get_orders( array( 'post__in' => $order_ids, 'limit' => 20 ) );
		}

		$orders = array_merge( $orders, $this->get_orders_by_product_sku( $query ) );

		if ( empty( $orders ) ) {
			return '';
		}

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

	private function generate_order_status_badge_html( $status ) {
		$background = '#e5e5e5';

		switch ( $status ) {
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

		$background = esc_attr( $background );
		$status     = esc_html( $status );

		return '<span class="status-badge text-sm" style="background: ' . $background . ';">' . $status . '</span>';
	}

	/**
	 * Search order IDs by customer info, order number, email, phone — single
	 * SQL query. HPOS-aware.
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
				$sql  .= "
					OR (ba.first_name = %s AND ba.last_name = %s)
					OR (ba.first_name = %s AND ba.last_name = %s)
					OR (sa.first_name = %s AND sa.last_name = %s)
					OR (sa.first_name = %s AND sa.last_name = %s)";
				$args  = array_merge( $args, array(
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
				$sql  .= "
					OR (pm_bf.meta_value = %s AND pm_bl.meta_value = %s)
					OR (pm_bf.meta_value = %s AND pm_bl.meta_value = %s)
					OR (pm_sf.meta_value = %s AND pm_sl.meta_value = %s)
					OR (pm_sf.meta_value = %s AND pm_sl.meta_value = %s)";
				$args  = array_merge( $args, array(
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
	 * Get WooCommerce orders by product SKU using SQL, excluding refunds and
	 * including product info. Handles simple products and variations.
	 *
	 * @param string $sku The product SKU to search for.
	 * @return array Array of arrays: ['order','matching_product','found_by_sku'].
	 */
	private function get_orders_by_product_sku( $sku ) {
		global $wpdb;

		$is_hpos = 'yes' === get_option( 'woocommerce_custom_orders_table_enabled' );

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
					LIMIT 50
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
					LIMIT 50
					",
					$sku,
					$sku
				)
			);
		}

		if ( empty( $results ) ) {
			return array();
		}

		// Batch-load orders and products to avoid N+1 queries. Hard cap to
		// prevent OOM on weak hosts.
		$order_ids   = array_slice( array_unique( wp_list_pluck( $results, 'order_id' ) ), 0, 50 );
		$product_ids = array_slice( array_unique( wp_list_pluck( $results, 'product_id' ) ), 0, 50 );

		$orders_map = array();
		if ( ! empty( $order_ids ) ) {
			foreach ( wc_get_orders( array( 'post__in' => $order_ids, 'limit' => 50 ) ) as $o ) {
				$orders_map[ $o->get_id() ] = $o;
			}
		}

		// wc_get_products() defaults to post_type=product and silently drops
		// product_variation IDs from the include list — so a search match on
		// a variation SKU would never resolve here. wc_get_product() handles
		// both types transparently, which is what we need.
		$products_map = array();
		foreach ( $product_ids as $pid ) {
			$prod = wc_get_product( (int) $pid );
			if ( $prod ) {
				$products_map[ $prod->get_id() ] = $prod;
			}
		}

		$orders_with_products = array();

		foreach ( $results as $result ) {
			$order   = isset( $orders_map[ $result->order_id ] ) ? $orders_map[ $result->order_id ] : null;
			$product = isset( $products_map[ $result->product_id ] ) ? $products_map[ $result->product_id ] : null;

			if ( $order && ! is_a( $order, 'WC_Order_Refund' ) && $product ) {
				$orders_with_products[] = array(
					'order'            => $order,
					'matching_product' => $product,
					'found_by_sku'     => true,
				);
			}
		}

		return $orders_with_products;
	}

	private function generate_order_results_html( $orders ) {
		$li = '';

		foreach ( $orders as $order_data ) {
			if ( is_array( $order_data ) && isset( $order_data['found_by_sku'] ) ) {
				$order            = $order_data['order'];
				$matching_product = $order_data['matching_product'];
			} else {
				$order = $order_data;
			}

			$edit_url = esc_url( $order->get_edit_order_url() );

			$number = esc_html( $order->get_order_number() );
			$status = $this->generate_order_status_badge_html( ucfirst( str_replace( '-', ' ', esc_html( $order->get_status() ?? '' ) ) ) );

			$first_name = esc_html( $order->get_shipping_first_name() ?: ( $order->get_billing_first_name() ?? '' ) );
			$last_name  = esc_html( $order->get_shipping_last_name() ?: ( $order->get_billing_last_name() ?? '' ) );
			$name       = trim( "$first_name $last_name" );

			$divider = '' === $name ? '' : '<span class="text-sm"> • </span>';

			$date_format = esc_html( get_option( 'date_format', 'F j' ) );
			$time_format = esc_html( get_option( 'time_format', 'g:i a' ) );
			$format      = "$date_format \a\\t $time_format";

			$date_created           = $order->get_date_created();
			$date_created_attr      = $date_created ? esc_attr( $date_created->date( 'c' ) ) : '';
			$date_created_formatted = $date_created ? esc_html( $date_created->date_i18n( $format ) ) : '';

			$product_html = '';
			if ( isset( $matching_product ) ) {
				$product_title = esc_html( $matching_product->get_formatted_name() );
				$product_html  = '<div class="text-sm matching-order-product">' . $product_title . '</div>';
			}

			$li .= '<li>';
			$li .= '    <a href="' . $edit_url . '">';
			$li .= '        <div>';
			$li .= '            <span>#' . $number . '</span>';
			$li .=                  $status;
			$li .= '        </div>';
			$li .= '        <div class="order-info">';
			$li .= '            <span class="text-sm">' . $name . '</span>';
			$li .=                  $divider;
			$li .= '            <span class="text-sm">';
			$li .= '                Placed on <time class="order-date text-sm" datetime="' . $date_created_attr . '">' . $date_created_formatted . '</time>';
			$li .= '            </span>';
			$li .=                  $product_html;
			$li .= '        </div>';
			$li .= '    </a>';
			$li .= '</li>';
		}

		return '<ul>' . $li . '</ul>';
	}

	/**
	 * Map a source id to its heading icon modifier (CSS-masked SVG). Unknown
	 * / third-party sources fall back to a neutral mark.
	 */
	private function get_source_icon_mod( $source_id ) {
		$known = array( 'orders', 'products', 'customers', 'navigation' );
		return in_array( $source_id, $known, true ) ? $source_id : 'default';
	}

	/**
	 * Render a result section: icon + uppercased label heading, then body.
	 */
	private function render_section( $label, $icon_mod, $body ) {
		return '<section><div class="heading">'
			. '<span class="bp-sec-ic bp-sec-ic--' . esc_attr( $icon_mod ) . '"></span>'
			. '<span class="bp-sec-label">' . esc_html( $label ) . '</span></div>'
			. '<div class="result-list">' . $body . '</div></section>';
	}

	// =========================================================================
	// Generic result row (products / customers / navigation)
	// =========================================================================

	/**
	 * Render a flat list of simple result rows.
	 *
	 * @param array $items Each: ['url','title','subtitle'(opt),'badge'(opt raw html)].
	 * @return string
	 */
	private function generate_generic_results_html( $items ) {
		if ( empty( $items ) ) {
			return '';
		}

		$li = '';
		foreach ( $items as $item ) {
			if ( empty( $item['url'] ) || empty( $item['title'] ) ) {
				continue;
			}

			$badge    = ! empty( $item['badge'] ) ? $item['badge'] : '';
			$subtitle = '';
			if ( ! empty( $item['subtitle'] ) ) {
				$subtitle = '<div class="order-info"><span class="text-sm">' . esc_html( $item['subtitle'] ) . '</span></div>';
			}

			$li .= '<li><a href="' . esc_url( $item['url'] ) . '">'
				. '<div class="result-row"><span class="result-title">' . esc_html( $item['title'] ) . '</span>' . $badge . '</div>'
				. $subtitle
				. '</a></li>';
		}

		return '' === $li ? '' : '<ul>' . $li . '</ul>';
	}

	// =========================================================================
	// Source: Products (simple + variations)
	// =========================================================================

	private function source_products( $query ) {
		global $wpdb;

		$limit       = 12;
		$product_ids = array();

		// Name match (covers simple + variable parent products).
		$by_name = wc_get_products(
			array(
				'status'  => array( 'publish', 'draft', 'pending', 'private' ),
				's'       => $query,
				'limit'   => $limit,
				'return'  => 'ids',
				'orderby' => 'relevance',
			)
		);
		if ( ! empty( $by_name ) ) {
			$product_ids = array_merge( $product_ids, $by_name );
		}

		// SKU match (partial), including variation SKUs which resolve to the
		// variation object so we can show the exact variant but link to the
		// editable parent.
		$like      = '%' . $wpdb->esc_like( $query ) . '%';
		$sku_match = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p
				JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_sku'
				WHERE p.post_type IN ('product','product_variation')
				AND p.post_status != 'trash'
				AND pm.meta_value LIKE %s
				LIMIT %d",
				$like,
				$limit
			)
		);
		if ( ! empty( $sku_match ) ) {
			$product_ids = array_merge( $product_ids, array_map( 'absint', $sku_match ) );
		}

		$product_ids = array_slice( array_unique( array_filter( $product_ids ) ), 0, $limit );
		if ( empty( $product_ids ) ) {
			return '';
		}

		$items = array();
		foreach ( $product_ids as $pid ) {
			$product = wc_get_product( (int) $pid );
			if ( ! $product ) {
				continue;
			}

			// Variations are edited from their parent product screen.
			$edit_id = $product->get_parent_id() ? $product->get_parent_id() : $product->get_id();
			$url     = get_edit_post_link( $edit_id, 'raw' );
			if ( ! $url ) {
				continue;
			}

			$title = $product->get_formatted_name(); // Includes ID, SKU, and variation attributes.
			$sku   = $product->get_sku();

			$parts = array();
			if ( $sku ) {
				/* translators: %s: product SKU. */
				$parts[] = sprintf( __( 'SKU: %s', 'brikpanel' ), $sku );
			}
			$price_html = $product->get_price_html();
			if ( $price_html ) {
				// Drop the visually-hidden a11y text WooCommerce injects
				// ("Original price was…", "Price range…") so the subtitle
				// stays a tight "$18.00 – $94.00".
				$price_html = preg_replace( '/<span class="screen-reader-text">.*?<\/span>/is', '', $price_html );
				$price      = trim( preg_replace( '/\s+/', ' ', html_entity_decode( wp_strip_all_tags( $price_html ), ENT_QUOTES, get_bloginfo( 'charset' ) ) ) );
				if ( '' !== $price ) {
					$parts[] = $price;
				}
			}
			$stock_options = wc_get_product_stock_status_options();
			$parts[]       = isset( $stock_options[ $product->get_stock_status() ] ) ? $stock_options[ $product->get_stock_status() ] : '';
			$subtitle      = implode( ' • ', array_filter( array_map( 'trim', $parts ) ) );

			$items[] = array(
				'url'      => $url,
				'title'    => $title,
				'subtitle' => $subtitle,
			);
		}

		return $this->generate_generic_results_html( $items );
	}

	// =========================================================================
	// Source: Customers (registered users / WooCommerce customers)
	// =========================================================================

	private function source_customers( $query ) {
		$limit = 10;
		$found = array();

		// Core column search: login, email, display name, nicename.
		$by_core = get_users(
			array(
				'search'         => '*' . $query . '*',
				'search_columns' => array( 'user_login', 'user_email', 'user_nicename', 'display_name' ),
				'number'         => $limit,
				'fields'         => array( 'ID', 'display_name', 'user_email' ),
			)
		);
		foreach ( $by_core as $u ) {
			$found[ $u->ID ] = $u;
		}

		// Real-name search via first/last name meta (and WooCommerce billing
		// names) so "John Doe" resolves even when display_name is a username.
		if ( count( $found ) < $limit ) {
			$by_meta = get_users(
				array(
					'number'     => $limit,
					'fields'     => array( 'ID', 'display_name', 'user_email' ),
					'meta_query' => array(
						'relation' => 'OR',
						array(
							'key'     => 'first_name',
							'value'   => $query,
							'compare' => 'LIKE',
						),
						array(
							'key'     => 'last_name',
							'value'   => $query,
							'compare' => 'LIKE',
						),
						array(
							'key'     => 'billing_first_name',
							'value'   => $query,
							'compare' => 'LIKE',
						),
						array(
							'key'     => 'billing_last_name',
							'value'   => $query,
							'compare' => 'LIKE',
						),
					),
				)
			);
			foreach ( $by_meta as $u ) {
				$found[ $u->ID ] = $u;
			}
		}

		if ( empty( $found ) ) {
			return '';
		}

		$items = array();
		foreach ( array_slice( $found, 0, $limit, true ) as $user ) {
			$url = get_edit_user_link( $user->ID );
			if ( ! $url ) {
				continue;
			}

			$name = trim( get_user_meta( $user->ID, 'first_name', true ) . ' ' . get_user_meta( $user->ID, 'last_name', true ) );
			if ( '' === $name ) {
				$name = $user->display_name;
			}

			$items[] = array(
				'url'      => $url,
				'title'    => $name,
				'subtitle' => $user->user_email,
			);
		}

		return $this->generate_generic_results_html( $items );
	}

	// =========================================================================
	// Source: Navigation (admin menu + every third-party plugin page)
	// =========================================================================

	/**
	 * Capture the fully-resolved admin menu into a per-user transient. WP has
	 * already filtered $menu / $submenu by the current user's capabilities by
	 * the time this runs (PHP_INT_MAX on admin_menu), and every plugin that
	 * registers admin pages is included automatically — which is exactly the
	 * "search the whole site, including third-party plugins" behaviour
	 * customers expect from a command palette.
	 *
	 * admin-ajax.php never builds the admin menu, so we read this snapshot
	 * back during the search request instead of rebuilding it.
	 */
	public function capture_navigation_index() {
		if ( wp_doing_ajax() || is_network_admin() || is_user_admin() ) {
			return;
		}

		global $menu, $submenu;
		if ( empty( $menu ) || ! is_array( $menu ) ) {
			return;
		}

		$index = array();

		foreach ( $menu as $top ) {
			if ( empty( $top[0] ) || empty( $top[2] ) ) {
				continue;
			}
			// Skip separators.
			if ( ! empty( $top[4] ) && false !== strpos( $top[4], 'wp-menu-separator' ) ) {
				continue;
			}

			$parent_slug  = $top[2];
			$parent_label = $this->clean_menu_title( $top[0] );

			$has_sub = ! empty( $submenu[ $parent_slug ] ) && is_array( $submenu[ $parent_slug ] );

			// Top-level entry itself (only when it has no children, to avoid
			// duplicating the first submenu item which points to the same page).
			if ( ! $has_sub && $parent_label ) {
				$index[] = array(
					'label'  => $parent_label,
					'parent' => '',
					'url'    => $this->resolve_menu_url( $parent_slug, '' ),
				);
				continue;
			}

			if ( $has_sub ) {
				foreach ( $submenu[ $parent_slug ] as $sub ) {
					if ( empty( $sub[0] ) || empty( $sub[2] ) ) {
						continue;
					}
					$label = $this->clean_menu_title( $sub[0] );
					if ( '' === $label ) {
						continue;
					}
					$index[] = array(
						'label'  => $label,
						'parent' => $parent_label,
						'url'    => $this->resolve_menu_url( $sub[2], $parent_slug ),
					);
				}
			}
		}

		// Hard cap keeps the transient small even on plugin-heavy sites.
		$index = array_slice( $index, 0, 400 );

		set_transient( self::NAV_INDEX_PREFIX . get_current_user_id(), $index, 12 * HOUR_IN_SECONDS );
	}

	/**
	 * Strip update/notification bubbles and markup from a menu title.
	 */
	private function clean_menu_title( $title ) {
		$title = preg_replace( '/<span[^>]*>.*?<\/span>/is', '', (string) $title );
		$title = wp_strip_all_tags( $title );
		// Collapse leftover whitespace and trailing pending counts (e.g. "Comments 3").
		$title = preg_replace( '/\s+\d+$/', '', trim( $title ) );
		return trim( (string) $title );
	}

	/**
	 * Resolve an admin menu slug to a full admin URL, mirroring WordPress
	 * core's menu-header.php logic closely enough for core, CPT submenus
	 * and plugin pages (admin.php?page=…).
	 */
	private function resolve_menu_url( $slug, $parent_slug ) {
		$slug = (string) $slug;

		if ( preg_match( '/^https?:\/\//i', $slug ) ) {
			return $slug;
		}

		// A real PHP file (core screens, CPT lists like edit.php?post_type=x).
		$is_php_file = ( false !== strpos( $slug, '.php' ) );

		if ( $is_php_file ) {
			return admin_url( $slug );
		}

		// Plugin page registered under a .php parent (e.g. a submenu of
		// edit.php?post_type=product) keeps that parent as the base.
		if ( $parent_slug && false !== strpos( $parent_slug, '.php' ) ) {
			$sep = ( false !== strpos( $parent_slug, '?' ) ) ? '&' : '?';
			return admin_url( $parent_slug . $sep . 'page=' . rawurlencode( $slug ) );
		}

		// Standard plugin page.
		return admin_url( 'admin.php?page=' . rawurlencode( $slug ) );
	}

	private function source_navigation( $query ) {
		$index = get_transient( self::NAV_INDEX_PREFIX . get_current_user_id() );
		if ( empty( $index ) || ! is_array( $index ) ) {
			return '';
		}

		$needle = function_exists( 'mb_strtolower' ) ? mb_strtolower( $query ) : strtolower( $query );
		$items  = array();

		foreach ( $index as $entry ) {
			if ( empty( $entry['label'] ) || empty( $entry['url'] ) ) {
				continue;
			}

			$haystack = $entry['label'] . ' ' . ( isset( $entry['parent'] ) ? $entry['parent'] : '' );
			$haystack = function_exists( 'mb_strtolower' ) ? mb_strtolower( $haystack ) : strtolower( $haystack );

			if ( false === strpos( $haystack, $needle ) ) {
				continue;
			}

			$title = $entry['label'];
			if ( ! empty( $entry['parent'] ) && 0 !== strcasecmp( $entry['parent'], $entry['label'] ) ) {
				$title = $entry['parent'] . ' › ' . $entry['label'];
			}

			$items[] = array(
				'url'      => $entry['url'],
				'title'    => $title,
				'subtitle' => '',
			);

			if ( count( $items ) >= 12 ) {
				break;
			}
		}

		return $this->generate_generic_results_html( $items );
	}

	// =========================================================================
	// Settings: dedicated "Search" section (one toggle per source)
	// =========================================================================

	public function register_settings_section( $sections ) {
		if ( ! is_array( $sections ) ) {
			return $sections;
		}
		// Insert "Search" right after "Orders" for a natural grouping.
		$out = array();
		foreach ( $sections as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'orders' === $key ) {
				$out['search'] = __( 'Search', 'brikpanel' );
			}
		}
		if ( ! isset( $out['search'] ) ) {
			$out['search'] = __( 'Search', 'brikpanel' );
		}
		return $out;
	}

	public function register_settings_section_map( $map ) {
		if ( ! is_array( $map ) ) {
			return $map;
		}
		$map['brk_search_title'] = 'search';
		return $map;
	}

	public function register_settings_fields( $fields ) {
		if ( ! is_array( $fields ) ) {
			return $fields;
		}

		$search = array(
			array(
				'name' => __( 'Command palette (Cmd/Ctrl + K)', 'brikpanel' ),
				'type' => 'title',
				'id'   => 'brk_search_title',
				'desc' => __( 'Pick which areas the Cmd/Ctrl + K palette searches. Turn sources off to keep it focused, or leave them on for a full admin-wide search. Navigation also covers third-party plugin pages automatically.', 'brikpanel' ),
			),
			array(
				'name'    => __( 'Search orders', 'brikpanel' ),
				'id'      => 'brikpanel_search_orders',
				'type'    => 'checkbox',
				'desc'    => __( 'Find orders by customer name, email, phone, order ID or a product SKU inside the order.', 'brikpanel' ),
				'default' => 'yes',
			),
			array(
				'name'    => __( 'Search products', 'brikpanel' ),
				'id'      => 'brikpanel_search_products',
				'type'    => 'checkbox',
				'desc'    => __( 'Find simple and variable products by name or SKU (variations included).', 'brikpanel' ),
				'default' => 'yes',
			),
			array(
				'name'    => __( 'Search customers', 'brikpanel' ),
				'id'      => 'brikpanel_search_customers',
				'type'    => 'checkbox',
				'desc'    => __( 'Find registered customers by name, email or username.', 'brikpanel' ),
				'default' => 'yes',
			),
			array(
				'name'    => __( 'Search navigation', 'brikpanel' ),
				'id'      => 'brikpanel_search_navigation',
				'type'    => 'checkbox',
				'desc'    => __( 'Jump to any admin page or settings screen, including pages added by other plugins.', 'brikpanel' ),
				'default' => 'yes',
			),
			array(
				'type' => 'sectionend',
				'id'   => 'brk_search_title',
			),
		);

		// Insert just before the "Developers" section, matching how the
		// Appearance module injects its fields.
		$insert_at = null;
		foreach ( $fields as $i => $field ) {
			if ( isset( $field['id'], $field['type'] ) && 'brk_developers_title' === $field['id'] && 'title' === $field['type'] ) {
				$insert_at = $i;
				break;
			}
		}

		if ( null === $insert_at ) {
			return array_merge( $fields, $search );
		}

		array_splice( $fields, $insert_at, 0, $search );
		return $fields;
	}
}

new Brikpanel_Pro_Search();
