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
	 * Non-autoloaded option holding the per-user admin navigation index.
	 * The index is captured on real admin page loads (where $menu / $submenu
	 * are populated) and read back during the AJAX request, because
	 * admin-ajax.php never builds the admin menu itself.
	 *
	 * Deliberately NOT a transient. The payload is ~30 KB on a plugin-heavy
	 * store and change detection (see capture_navigation_index) means it is
	 * only rewritten when the menu actually changes; a TTL on top of that
	 * would open a window where the signature still matches but the payload
	 * has already expired, silently killing navigation search until the
	 * user's menu happened to change. No TTL also means no
	 * `_transient_timeout_` row, halving both the storage rows and the write
	 * cost. This is derived per-user cache, not configuration — keep it out
	 * of the import/export map.
	 */
	const NAV_INDEX_OPTION_PREFIX = 'brikpanel_nav_index_';

	/**
	 * User-meta key holding the hash of the last index written for this blog.
	 * WordPress primes every meta row for the logged-in user while resolving
	 * their capabilities, so reading this costs no query — which is the whole
	 * point: the steady state must be zero SELECTs and zero UPDATEs.
	 *
	 * The blog id is part of the key because on multisite options are
	 * per-blog but user meta is per-NETWORK: one shared signature would make
	 * every subsite after the first believe its index was already current,
	 * and each subsite has a completely different admin menu.
	 */
	const NAV_INDEX_SIG_META = 'brikpanel_nav_index_sig';

	/** Bump to force every user's index to be rebuilt after a schema change. */
	const NAV_INDEX_SCHEMA = 'v3';

	public function __construct() {
		// We also enqueue the scripts for the public side of WordPress because
		// for logged in admins, the admin bar shows at the top there too.
		add_action( 'admin_bar_menu', array( $this, 'add_search_to_admin_bar' ), 999 );
		add_action( 'wp_ajax_brikpanel_search', array( $this, 'handle_search_query' ) );

		// Capture the resolved admin menu (including every third-party
		// plugin's pages) on normal admin loads so navigation search works
		// inside admin-ajax, which never runs wp-admin/menu.php.
		add_action( 'admin_menu', array( $this, 'capture_navigation_index' ), PHP_INT_MAX );

		// The per-user index rows have no TTL, so they are reclaimed
		// explicitly instead of ageing out. Those hooks are NOT registered
		// here: this class is only instantiated on admin requests, and the
		// deletion paths that matter most (`wp user delete`, the REST users
		// endpoint, a membership plugin's bulk purge) are not admin requests.
		// See the file-scope registration at the bottom of this file.

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

	/**
	 * Add the Cmd+K palette trigger to the WordPress admin bar.
	 *
	 * Gated on the SAME capability that ships the palette's CSS/JS in
	 * brikpanel_enqueue_global_assets() and that verify_request() enforces on
	 * every AJAX call. Without this check the trigger was still printed for
	 * users below the bar — an unstyled, dead "Search BrikPanel / Ctrl+K" strip
	 * in the admin bar whose scripts never loaded, visible in the 3.2.36
	 * non-admin bug report alongside the duplicated menu.
	 */
	public function add_search_to_admin_bar( WP_Admin_Bar $admin_bar ) {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
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

		// GTIN match (partial) on the WooCommerce native global unique id
		// (GTIN/UPC/EAN/ISBN), covering both simple products and variations.
		$gtin_match = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p
				JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_global_unique_id'
				WHERE p.post_type IN ('product','product_variation')
				AND p.post_status != 'trash'
				AND pm.meta_value <> ''
				AND pm.meta_value LIKE %s
				LIMIT %d",
				$like,
				$limit
			)
		);
		if ( ! empty( $gtin_match ) ) {
			$product_ids = array_merge( $product_ids, array_map( 'absint', $gtin_match ) );
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
			$gtin = trim( (string) $product->get_global_unique_id() );
			if ( '' !== $gtin ) {
				/* translators: %s: product GTIN/UPC/EAN/ISBN code. */
				$parts[] = sprintf( __( 'GTIN: %s', 'brikpanel' ), $gtin );
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

		// Gate the WRITE on exactly what gates the READ. Without this the
		// index was captured for people who can never open the palette — the
		// read path in source_navigation() needs manage_woocommerce, and the
		// whole source can be switched off — so an author, an editor or a
		// subscriber loading profile.php was left with a permanent option row
		// nothing would ever look at. It has no TTL now, so "written and never
		// read" means "stored forever".
		if ( 'no' === get_option( 'brikpanel_search_navigation', 'yes' ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return;
		}

		global $menu, $submenu;
		if ( empty( $menu ) || ! is_array( $menu ) ) {
			return;
		}

		// Pure PHP over globals WordPress has already built — no queries.
		$index = $this->build_navigation_index( $menu, $submenu );
		if ( empty( $index ) ) {
			return;
		}

		$signature = self::navigation_signature( $index );

		$stored = (string) get_user_meta( $user_id, self::nav_index_sig_key(), true );
		if ( $stored === $signature ) {
			// Steady state. Until 3.2.70 this method wrote ~30 KB to
			// wp_options on EVERY admin page load, plus a timeout row whose
			// value changed every second, so neither write could ever be
			// short-circuited by update_option()'s identical-value check.
			return;
		}

		// Write the payload FIRST and only claim the signature if it landed.
		// The signature is the only thing that stops the next page load from
		// rebuilding, so a signature written next to a failed payload write
		// (disk full, a pre_update_option filter refusing it) leaves navigation
		// search permanently dead for that user with nothing to notice it.
		//
		// update_option() also returns false when the value did not change,
		// which is the normal state right after a NAV_INDEX_SCHEMA bump: the
		// menu is the same, only the fingerprint format moved. Treating that
		// as a failure would rebuild on every single admin page load, so the
		// two cases are told apart by reading the row back — one query, and
		// only on the path that is already not the steady state.
		if ( ! update_option( self::nav_index_option( $user_id ), $index, false ) ) {
			$current = get_option( self::nav_index_option( $user_id ), null );
			if ( $current !== $index ) {
				return;
			}
		}
		update_user_meta( $user_id, self::nav_index_sig_key(), $signature );
	}

	/**
	 * Fingerprint the menu so the payload is only rewritten when it really
	 * changed.
	 *
	 * The signature MUST be context-free — identical on every admin screen —
	 * or the payload ping-pongs between two values and we are back to writing
	 * ~30 KB on every navigation. That rules out hashing the entries verbatim,
	 * because menu URLs are not context-free: plugins build them from the live
	 * request. Measured on a real store, the same menu produced a different
	 * index on each screen because of two entries alone:
	 *
	 *   - core's Customize link carries `?return=<current admin URL>`
	 *   - Elementor's "Website Templates" inherits the current screen's query
	 *     (`&post_type=product` on a product taxonomy screen, and its own
	 *     `return_to=<current admin URL>`)
	 *
	 * Stripping known parameter names does not close this: any plugin can leak
	 * any request parameter into its own menu URL, and the leaked names differ
	 * per site. So the query string is excluded from the fingerprint entirely
	 * and only the label, the parent and the URL *path* are hashed. Those are
	 * the parts a genuine menu change moves.
	 *
	 * The full URL still goes into the stored payload, so navigation is exact.
	 *
	 * ONE query parameter is hashed as well, and only one: `page`. On the
	 * `admin.php?page=<slug>` family that value is not request state at all —
	 * it is the slug the plugin registered its screen under, fixed at
	 * registration time, and it is the only thing separating two entries that
	 * share a path. Without it a plugin renaming `page=old` to `page=new` while
	 * keeping the label produced no signature change, so the index kept a dead
	 * link forever: the payload no longer expires, so unlike the old 12-hour
	 * transient there was nothing left to heal it. Everything else in the query
	 * string stays excluded, which is what keeps the leaked `return` /
	 * `return_to` / inherited `post_type` values from re-introducing the churn.
	 *
	 * clean_menu_title() has already stripped the update / comment count
	 * bubbles from the labels, so the fingerprint also stays stable while
	 * those numbers tick.
	 *
	 * @param array $index
	 * @return string
	 */
	private static function navigation_signature( array $index ) {
		$parts = array();

		foreach ( $index as $entry ) {
			$url  = isset( $entry['url'] ) ? (string) $entry['url'] : '';
			$path = '';
			$page = '';
			if ( '' !== $url ) {
				$bits = wp_parse_url( $url );
				$path = isset( $bits['path'] ) ? $bits['path'] : '';
				if ( ! empty( $bits['query'] ) ) {
					$args = array();
					wp_parse_str( $bits['query'], $args );
					if ( isset( $args['page'] ) && is_scalar( $args['page'] ) ) {
						$page = (string) $args['page'];
					}
				}
			}

			$parts[] = ( isset( $entry['label'] ) ? $entry['label'] : '' )
				. "\x1f" . ( isset( $entry['parent'] ) ? $entry['parent'] : '' )
				. "\x1f" . $path
				. "\x1f" . $page;
		}

		return self::NAV_INDEX_SCHEMA . ':' . md5( implode( "\x1e", $parts ) );
	}

	/** Option name holding one user's navigation index on this blog. */
	private static function nav_index_option( $user_id ) {
		return self::NAV_INDEX_OPTION_PREFIX . (int) $user_id;
	}

	/** Blog-scoped user-meta key for the navigation index signature. */
	private static function nav_index_sig_key( $blog_id = 0 ) {
		$blog_id = $blog_id ?: ( is_multisite() ? get_current_blog_id() : 0 );
		return self::NAV_INDEX_SIG_META . '_' . (int) $blog_id;
	}

	/**
	 * Drop a user's navigation index everywhere it could exist.
	 *
	 * The network walk is paginated rather than capped: an earlier revision
	 * passed `number => 500` under a comment claiming there was no limit, so
	 * every site past the 500th leaked both the option row and the signature
	 * meta. delete_user is rare and interactive, so the extra pages are free.
	 *
	 * @param int $user_id
	 */
	public static function purge_nav_index_for_user( $user_id ) {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) {
			return;
		}

		if ( is_multisite() ) {
			// Options are per-blog; the row lives on every site the user
			// could reach wp-admin on. The signature meta is per-network but
			// blog-scoped by key, so both have to be walked.
			$page = 0;
			do {
				$blog_ids = get_sites( array(
					'fields' => 'ids',
					'number' => 250,
					'offset' => $page * 250,
				) );
				foreach ( $blog_ids as $blog_id ) {
					switch_to_blog( (int) $blog_id );
					delete_option( self::nav_index_option( $user_id ) );
					restore_current_blog();
					delete_user_meta( $user_id, self::nav_index_sig_key( (int) $blog_id ) );
				}
				$page++;
			} while ( count( $blog_ids ) === 250 );
			return;
		}

		delete_option( self::nav_index_option( $user_id ) );
		delete_user_meta( $user_id, self::nav_index_sig_key( 0 ) );
	}

	/**
	 * @param int $user_id
	 * @param int $blog_id
	 */
	public static function purge_nav_index_for_blog_user( $user_id, $blog_id ) {
		$user_id = (int) $user_id;
		$blog_id = (int) $blog_id;
		if ( $user_id <= 0 || $blog_id <= 0 ) {
			return;
		}
		switch_to_blog( $blog_id );
		delete_option( self::nav_index_option( $user_id ) );
		restore_current_blog();
		delete_user_meta( $user_id, self::nav_index_sig_key( $blog_id ) );
	}

	/**
	 * A deleted subsite takes its own option rows with its tables, but the
	 * signature meta lives on the shared network user-meta table and would
	 * otherwise leak one row per user of that subsite.
	 *
	 * @param WP_Site $old_site
	 */
	public static function purge_nav_index_for_site( $old_site ) {
		if ( ! is_object( $old_site ) || empty( $old_site->blog_id ) ) {
			return;
		}
		delete_metadata( 'user', 0, self::nav_index_sig_key( (int) $old_site->blog_id ), '', true );
	}

	/**
	 * Flatten the resolved admin menu into searchable {label, parent, url}
	 * entries. Reads only the globals WordPress has already populated, so it
	 * issues no queries and is safe to run on every admin page load.
	 *
	 * @param array $menu
	 * @param array $submenu
	 * @return array
	 */
	private function build_navigation_index( $menu, $submenu ) {
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
					'url'    => self::normalize_menu_url( $this->resolve_menu_url( $parent_slug, '' ) ),
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
						'url'    => self::normalize_menu_url( $this->resolve_menu_url( $sub[2], $parent_slug ) ),
					);
				}
			}
		}

		// Hard cap keeps the stored payload small even on plugin-heavy sites.
		return array_slice( $index, 0, 400 );
	}

	/**
	 * Strip "come back to where I was" parameters out of a stored menu URL.
	 *
	 * Some menu entries embed a pointer to the screen the merchant happens to
	 * be on: core's Customize link carries `?return=<current admin URL>`, and
	 * Elementor's "Website Templates" carries `&return_to=<current admin URL>`.
	 * That pointer is only a hint about where "Back" goes, never the
	 * destination, and the screen it names is whichever one happened to be open
	 * when the index was captured — arbitrary, and usually not where the
	 * merchant is when they use the palette. Dropping it makes the stored entry
	 * honest and slightly tidier.
	 *
	 * This is cosmetic, not the churn fix: the fingerprint in
	 * navigation_signature() already ignores the whole query string, precisely
	 * because a name-based list like this one can never cover every plugin that
	 * leaks request state into its menu URL.
	 *
	 * Matching is by parameter NAME only. An earlier revision also dropped any
	 * parameter whose value appeared in the current REQUEST_URI, which ate
	 * `page=wc-orders` on the Orders screen — the slug is a substring of the
	 * request — and silently rewrote that entry to a bare admin.php.
	 *
	 * @param string $url
	 * @return string
	 */
	private static function normalize_menu_url( $url ) {
		$url = (string) $url;
		if ( '' === $url || false === strpos( $url, '?' ) ) {
			return $url;
		}

		$parts = wp_parse_url( $url );
		if ( empty( $parts['query'] ) ) {
			return $url;
		}

		$args = array();
		wp_parse_str( $parts['query'], $args );
		if ( ! is_array( $args ) || ! $args ) {
			return $url;
		}

		$named = array(
			'return', 'return_to', 'returnurl', 'return_url',
			'redirect', 'redirect_to', 'wp_http_referer', '_wp_http_referer',
		);

		$changed = false;
		foreach ( array_keys( $args ) as $key ) {
			if ( in_array( (string) $key, $named, true ) ) {
				unset( $args[ $key ] );
				$changed = true;
			}
		}

		// Rebuilding re-encodes every remaining parameter, so only pay that
		// cost (and that visible churn) when something was actually removed.
		if ( ! $changed ) {
			return $url;
		}

		$base = '';
		if ( ! empty( $parts['scheme'] ) && ! empty( $parts['host'] ) ) {
			$base = $parts['scheme'] . '://' . $parts['host'];
			if ( ! empty( $parts['port'] ) ) {
				$base .= ':' . (int) $parts['port'];
			}
		}
		$base .= isset( $parts['path'] ) ? $parts['path'] : '';

		$query = http_build_query( $args );
		if ( '' !== $query ) {
			$base .= '?' . $query;
		}
		if ( ! empty( $parts['fragment'] ) ) {
			$base .= '#' . $parts['fragment'];
		}

		return $base;
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
		$user_id = get_current_user_id();
		$index   = get_option( self::nav_index_option( $user_id ), array() );

		if ( empty( $index ) || ! is_array( $index ) ) {
			// The payload is gone but the signature would keep every future
			// admin page load from rebuilding it, so navigation search would
			// stay dead forever rather than for one TTL. Drop the signature so
			// the next admin page load re-captures. Reachable via a cache /
			// cleanup plugin, a manual option delete, or a partial restore.
			if ( $user_id > 0 ) {
				delete_user_meta( $user_id, self::nav_index_sig_key() );
			}
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
				'desc'    => __( 'Find simple and variable products by name, SKU or GTIN (variations included).', 'brikpanel' ),
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

// The palette itself is an admin surface: keep instantiation gated exactly as
// before so no storefront request pays for its hooks.
if ( is_admin() ) {
	new Brikpanel_Pro_Search();
}

/**
 * Reclaim the per-user navigation index rows.
 *
 * Registered at FILE SCOPE, not in the class constructor, because this file is
 * required outside the is_admin() gate while the class is only instantiated
 * inside it. Every deletion path that is not a wp-admin pageview — `wp user
 * delete`, `wp site delete`, `DELETE /wp/v2/users/<id>`, a membership plugin
 * purging accounts from a cron job — used to leave the ~30 KB option row and
 * the signature meta behind permanently, because the payload has no TTL any
 * more. The callbacks touch nothing but options and user meta, so they are
 * safe to register on every request.
 */
add_action( 'delete_user',           array( 'Brikpanel_Pro_Search', 'purge_nav_index_for_user' ) );
add_action( 'wpmu_delete_user',      array( 'Brikpanel_Pro_Search', 'purge_nav_index_for_user' ) );
add_action( 'remove_user_from_blog', array( 'Brikpanel_Pro_Search', 'purge_nav_index_for_blog_user' ), 10, 2 );
add_action( 'wp_delete_site',        array( 'Brikpanel_Pro_Search', 'purge_nav_index_for_site' ) );

/**
 * One-time cleanup of the legacy navigation-index transients.
 *
 * Until 3.2.70 the index lived in a per-user transient that was rewritten on
 * EVERY admin page load — a ~30 KB serialized UPDATE plus a timeout-row UPDATE
 * whose value changed every second, so neither could be short-circuited. On a
 * store without a persistent object cache that single write was measured at
 * 1.2 s per admin pageview. The payload now lives in a non-expiring option
 * written only when the menu actually changes, leaving the old rows orphaned.
 *
 * No payload migration: the first admin page load after the upgrade finds no
 * signature and writes a fresh index. Guarded by its own marker rather than
 * brikpanel_db_version, because a site already on this version still needs the
 * sweep and a version gate would skip exactly the site that needed it.
 */
function brikpanel_search_migrate_nav_index_transients() {
	if ( '1' === get_option( 'brikpanel_nav_index_transients_cleared' ) ) {
		return;
	}

	global $wpdb;

	// Underscores are LIKE wildcards. Without the escapes this pattern would
	// match far more than the intended rows.
	$wpdb->query(
		"DELETE FROM {$wpdb->options}
		  WHERE option_name LIKE '\\_transient\\_brikpanel\\_nav\\_index\\_%'
		     OR option_name LIKE '\\_transient\\_timeout\\_brikpanel\\_nav\\_index\\_%'"
	);

	// autoload=on: this marker is read on every admin request until it exists,
	// and it is a single byte.
	update_option( 'brikpanel_nav_index_transients_cleared', '1', true );
}
add_action( 'admin_init', 'brikpanel_search_migrate_nav_index_transients' );
