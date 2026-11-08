<?php
/**
 * Printable order summary.
 *
 * A plain summary of one or more orders to pick and pack from: the store's logo
 * and address, both customer addresses with the extra fields the store shows on
 * the order page (a Brazilian CPF, a VAT number), the items with their pictures,
 * SKUs, quantities and prices, the shipping method, coupons and totals. It is
 * not an invoice: no invoice number, no country tax rules (wp.org, Lincoln
 * Cristiano, "[Feature request] Order summary in PDF", 31 Oct 2026).
 *
 * Three ways in, one page out:
 *   - the orders list bulk action "Print order summary", on both order storages;
 *   - the "Print" button in the order page header (front-end/order/brikpanel-order.js,
 *     its address comes from brikpanel_order_print_url() through brikpanelOrderEdit);
 *   - the "Print" column of the orders list: one print button per order, and the
 *     same button in the compact list's order panel (phones show no row icons).
 *     Hidden for everyone until the person ticks "Print" under Screen Options ›
 *     Columns (owner's decision, 7 Nov 2026; Lincoln asked for a button per row).
 * All open admin.php?page=brikpanel-print-orders&ids=…&_wpnonce=… in a new tab.
 *
 * That page is a hidden admin screen whose load- hook prints a whole HTML
 * document and exits before WordPress draws any admin chrome. Not an admin-post
 * handler on purpose: the classic orders screen strips `action` from the URL a
 * bulk action redirects to (wp-admin/edit.php), and `action` is admin-post's
 * routing parameter.
 *
 * "Download as PDF" is the browser's own "Save as PDF" printer, so no PDF
 * library ships: every script and right-to-left language prints exactly as the
 * browser draws it. (The most used invoice plugin needs separate add-ons for
 * Arabic and for Chinese, Japanese and Korean fonts.)
 *
 * No setting (owner's decision, 6 Nov 2026): it is on for everyone who may edit
 * orders, and off with the rest of BrikPanel when the master switch or personal
 * mode hands the user the stock WooCommerce admin.
 *
 * @package BrikPanel
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// =============================================================================
// KEYS
// =============================================================================
const BRIKPANEL_ORDER_PRINT_ACTION = 'brikpanel_print_orders';
const BRIKPANEL_ORDER_PRINT_PAGE   = 'brikpanel-print-orders';
const BRIKPANEL_ORDER_PRINT_NONCE  = 'brikpanel_print_orders';

/**
 * Most orders on one print run. Keeps the address short (the ids ride in it) and
 * the page light enough for a browser's print preview; a store printing more
 * runs a second batch, and the page says so.
 */
const BRIKPANEL_ORDER_PRINT_MAX = 250;

/**
 * Item meta that marks a line as a part of another line (a bundle or a
 * composite product). Such lines print indented under their parent. Keys from
 * the plugins' own code: WooCommerce Product Bundles, WPC Product Bundles,
 * WPC Composite Products, WooCommerce Composite Products.
 */
const BRIKPANEL_ORDER_PRINT_CHILD_KEYS = array( '_bundled_by', '_woosb_parent_id', '_wooco_parent_id', '_composite_parent' );

// =============================================================================
// GATES
// =============================================================================

/**
 * Whether the current user may print order summaries: the capability
 * WooCommerce's own order screens ask for.
 *
 * @return bool
 */
function brikpanel_order_print_user_can() {
	return current_user_can( 'edit_shop_orders' );
}

/**
 * The capability plus BrikPanel's own access rules. Like the merge action this
 * is an addition to a WooCommerce screen, not one of the gated interface
 * options, so it checks `brikpanel_access_should_neutralize()` itself: a user
 * BrikPanel is switched off for gets the stock orders list, without our action.
 *
 * @return bool
 */
function brikpanel_order_print_active() {
	if ( function_exists( 'brikpanel_access_should_neutralize' ) && brikpanel_access_should_neutralize() ) {
		return false;
	}
	return brikpanel_order_print_user_can();
}

// =============================================================================
// ADDRESS OF THE PRINT PAGE
// =============================================================================

/**
 * Order ids from a list or a comma separated string: positive, unique, in the
 * order given.
 *
 * @param mixed $ids Ids.
 * @return int[]
 */
function brikpanel_order_print_clean_ids( $ids ) {
	if ( is_string( $ids ) ) {
		$ids = explode( ',', $ids );
	}
	return array_values( array_unique( array_filter( array_map( 'absint', (array) $ids ) ) ) );
}

/**
 * Address of the print page for these orders. The print window opens by
 * itself there; a selection larger than BRIKPANEL_ORDER_PRINT_MAX keeps its
 * first orders and passes the full count along for the page to mention.
 *
 * @param int[]|string $ids Order ids.
 * @return string Empty when there is nothing to print.
 */
function brikpanel_order_print_url( $ids ) {
	$ids = brikpanel_order_print_clean_ids( $ids );
	if ( ! $ids ) {
		return '';
	}
	$args = array(
		'page'     => BRIKPANEL_ORDER_PRINT_PAGE,
		'ids'      => implode( ',', array_slice( $ids, 0, BRIKPANEL_ORDER_PRINT_MAX ) ),
		'print'    => '1',
		'_wpnonce' => wp_create_nonce( BRIKPANEL_ORDER_PRINT_NONCE ),
	);
	if ( count( $ids ) > BRIKPANEL_ORDER_PRINT_MAX ) {
		$args['of'] = (string) count( $ids );
	}
	return add_query_arg( $args, admin_url( 'admin.php' ) );
}

// =============================================================================
// BULK ACTION
// =============================================================================

/**
 * Offer "Print order summary" first in the orders-list bulk menu. BrikPanel's
 * own bar copies its buttons from this menu (brikpanelBulkActions() in
 * brikpanel-orders.js), so this one entry serves both looks of the list.
 *
 * @param array $actions Existing bulk actions.
 * @return array
 */
function brikpanel_order_print_add_bulk_action( $actions ) {
	if ( ! is_array( $actions ) || ! brikpanel_order_print_active() ) {
		return $actions;
	}

	// The Trash view has no mark_* actions and nothing there to print: the same
	// test the merge action and the custom statuses use.
	$has_mark = false;
	foreach ( $actions as $key => $unused ) {
		if ( 0 === strpos( (string) $key, 'mark_' ) ) {
			$has_mark = true;
			break;
		}
	}
	if ( ! $has_mark ) {
		return $actions;
	}

	return array( BRIKPANEL_ORDER_PRINT_ACTION => __( 'Print order summary', 'brikpanel' ) ) + $actions;
}
add_filter( 'bulk_actions-edit-shop_order', 'brikpanel_order_print_add_bulk_action', 26 );
add_filter( 'bulk_actions-woocommerce_page_wc-orders', 'brikpanel_order_print_add_bulk_action', 26 );
add_filter( 'bulk_actions-admin_page_wc-orders', 'brikpanel_order_print_add_bulk_action', 26 );

/**
 * Send the selection to the print page. WooCommerce (HPOS) and WordPress (the
 * classic screen) have checked their bulk nonce before this filter runs, and
 * redirect to whatever non-empty address comes back.
 *
 * @param string $redirect_to Default redirect.
 * @param string $action      Chosen bulk action.
 * @param array  $ids         Selected order ids.
 * @return string
 */
function brikpanel_order_print_handle_bulk( $redirect_to, $action, $ids ) {
	if ( BRIKPANEL_ORDER_PRINT_ACTION !== $action || ! brikpanel_order_print_active() ) {
		return $redirect_to;
	}
	$url = brikpanel_order_print_url( $ids );
	return '' !== $url ? $url : $redirect_to;
}
add_filter( 'handle_bulk_actions-edit-shop_order', 'brikpanel_order_print_handle_bulk', 20, 3 );
add_filter( 'handle_bulk_actions-woocommerce_page_wc-orders', 'brikpanel_order_print_handle_bulk', 20, 3 );
add_filter( 'handle_bulk_actions-admin_page_wc-orders', 'brikpanel_order_print_handle_bulk', 20, 3 );

/**
 * On the orders list, open the print page in a new tab so the list stays where
 * it was (front-end/orders/brikpanel-order-print-list.js). Without the script
 * the page opens in the same tab and its "Orders" link leads back.
 *
 * @return void
 */
function brikpanel_order_print_enqueue_list() {
	// The list on either storage, never the single order (the screen test the
	// compact list uses; it does not depend on that list being switched on).
	if ( ! brikpanel_order_print_active() || ! function_exists( 'brikpanel_orders_compact_is_list_screen' ) || ! brikpanel_orders_compact_is_list_screen() ) {
		return;
	}
	$file = 'front-end/orders/brikpanel-order-print-list.js';
	wp_enqueue_script( 'brikpanel_order_print_list', BRIKPANEL_URL . $file, array(), brikpanel_order_print_asset_version( $file ), true );
}
add_action( 'admin_enqueue_scripts', 'brikpanel_order_print_enqueue_list', 20 );

// =============================================================================
// PRINT COLUMN ON THE ORDERS LIST
// =============================================================================

/** Key of the orders list column that holds one print button per order. */
const BRIKPANEL_ORDER_PRINT_COLUMN = 'brikpanel_print';

/**
 * User meta set to "on" once the person ticks "Print" under Screen Options ›
 * Columns. Global per user, like WordPress's own list of hidden columns.
 */
const BRIKPANEL_ORDER_PRINT_COLUMN_META = 'brikpanel_orders_print_column';

/**
 * Screen ids of the orders list on both order storages.
 *
 * @return string[]
 */
function brikpanel_order_print_list_screen_ids() {
	$ids = array( 'edit-shop_order', 'woocommerce_page_wc-orders' );
	if ( function_exists( 'wc_get_page_screen_id' ) ) {
		$ids[] = (string) wc_get_page_screen_id( 'shop-order' );
	}
	return array_values( array_unique( array_filter( $ids ) ) );
}

/**
 * Whether the current user turned the Print column on.
 *
 * @return bool
 */
function brikpanel_order_print_column_on() {
	$user_id = get_current_user_id();
	return $user_id > 0 && 'on' === get_user_meta( $user_id, BRIKPANEL_ORDER_PRINT_COLUMN_META, true );
}

/**
 * Whether the Print column shows on the current orders list: the user turned it
 * on and has not hidden it since. Outside a screen (a test, WP-CLI) the user's
 * choice alone answers.
 *
 * @return bool
 */
function brikpanel_order_print_column_shown() {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	return $screen instanceof WP_Screen
		? ! in_array( BRIKPANEL_ORDER_PRINT_COLUMN, get_hidden_columns( $screen ), true )
		: brikpanel_order_print_column_on();
}

/**
 * The Print column, right after the order number. The compact list moves it
 * next to the WhatsApp column (brikpanel_orders_compact_base_row_columns()).
 *
 * @param array $columns Column key => heading.
 * @return array
 */
function brikpanel_order_print_add_column( $columns ) {
	if ( ! is_array( $columns ) || isset( $columns[ BRIKPANEL_ORDER_PRINT_COLUMN ] ) || ! isset( $columns['order_number'] ) || ! brikpanel_order_print_active() ) {
		return $columns;
	}
	$out = array();
	foreach ( $columns as $key => $label ) {
		$out[ $key ] = $label;
		if ( 'order_number' === $key ) {
			$out[ BRIKPANEL_ORDER_PRINT_COLUMN ] = _x( 'Print', 'button that prints an order summary', 'brikpanel' );
		}
	}
	return $out;
}

/**
 * Fill the Print cell. HPOS hands over the order, the classic screen its id.
 *
 * @param string       $column Column key.
 * @param WC_Order|int $order  Order or order id.
 * @return void
 */
function brikpanel_order_print_fill_column( $column, $order ) {
	if ( BRIKPANEL_ORDER_PRINT_COLUMN !== $column ) {
		return;
	}
	if ( ! $order instanceof WC_Order ) {
		$order = wc_get_order( $order );
	}
	$url = brikpanel_order_print_row_url( $order );
	if ( '' === $url ) {
		return;
	}
	printf(
		'<a href="%1$s" target="_blank" rel="noopener" class="brikpanel-print-list-link" title="%2$s" aria-label="%2$s">%3$s</a>',
		esc_url( $url ),
		esc_attr__( 'Print order summary', 'brikpanel' ),
		wp_kses( brikpanel_order_print_icon_svg( 16 ), brikpanel_order_print_svg_kses() )
	);
}

if ( get_option( 'woocommerce_custom_orders_table_enabled' ) === 'yes' ) {
	add_filter( 'manage_woocommerce_page_wc-orders_columns', 'brikpanel_order_print_add_column', 22 );
	add_action( 'manage_woocommerce_page_wc-orders_custom_column', 'brikpanel_order_print_fill_column', 22, 2 );
} else {
	add_filter( 'manage_edit-shop_order_columns', 'brikpanel_order_print_add_column', 22 );
	add_action( 'manage_shop_order_posts_custom_column', 'brikpanel_order_print_fill_column', 22, 2 );
}

/**
 * Keep the Print column hidden until the person turns it on.
 *
 * WordPress's `default_hidden_columns` only applies to people who never saved a
 * column choice on this screen; everyone who ever ticked a box in Screen
 * Options would see the new column straight away. This filter runs for both.
 *
 * @param string[]  $hidden Hidden column keys.
 * @param WP_Screen $screen Current screen.
 * @return string[]
 */
function brikpanel_order_print_hide_column( $hidden, $screen ) {
	if ( ! is_array( $hidden ) || ! $screen instanceof WP_Screen || ! in_array( $screen->id, brikpanel_order_print_list_screen_ids(), true ) ) {
		return $hidden;
	}
	if ( ! brikpanel_order_print_column_on() && ! in_array( BRIKPANEL_ORDER_PRINT_COLUMN, $hidden, true ) ) {
		$hidden[] = BRIKPANEL_ORDER_PRINT_COLUMN;
	}
	return $hidden;
}
add_filter( 'hidden_columns', 'brikpanel_order_print_hide_column', 10, 2 );

/**
 * Remember the Print column's tick when Screen Options saves the columns.
 *
 * Runs before WordPress's own handler (wp_ajax_hidden_columns(), priority 1),
 * which saves the same list and ends the request. That list names every column
 * left unticked, so "Print" missing from it means the person ticked it.
 *
 * @return void
 */
function brikpanel_order_print_remember_column() {
	// WordPress's handler checks this nonce too and stops the request when it fails.
	if ( ! check_ajax_referer( 'screen-options-nonce', 'screenoptionnonce', false ) || ! brikpanel_order_print_active() ) {
		return;
	}
	$page = isset( $_POST['page'] ) ? sanitize_key( wp_unslash( $_POST['page'] ) ) : '';
	if ( ! in_array( $page, brikpanel_order_print_list_screen_ids(), true ) ) {
		return;
	}
	$raw    = isset( $_POST['hidden'] ) ? sanitize_text_field( wp_unslash( $_POST['hidden'] ) ) : '';
	$hidden = array_map( 'sanitize_key', explode( ',', $raw ) );
	if ( in_array( BRIKPANEL_ORDER_PRINT_COLUMN, $hidden, true ) ) {
		delete_user_meta( get_current_user_id(), BRIKPANEL_ORDER_PRINT_COLUMN_META );
	} else {
		update_user_meta( get_current_user_id(), BRIKPANEL_ORDER_PRINT_COLUMN_META, 'on' );
	}
}
add_action( 'wp_ajax_hidden-columns', 'brikpanel_order_print_remember_column', 0 );

/**
 * The printer glyph of the list buttons, the same as on the print page's own
 * Print button.
 *
 * @param int $size Pixel size.
 * @return string
 */
function brikpanel_order_print_icon_svg( $size = 16 ) {
	$size = (int) $size;
	return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M6 9V3h12v6"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><path d="M6 14h12v7H6z"/></svg>';
}

/**
 * What wp_kses() lets through of that glyph.
 *
 * @return array
 */
function brikpanel_order_print_svg_kses() {
	return array(
		'svg'  => array(
			'width'           => true,
			'height'          => true,
			'viewbox'         => true,
			'fill'            => true,
			'stroke'          => true,
			'stroke-width'    => true,
			'stroke-linecap'  => true,
			'stroke-linejoin' => true,
			'aria-hidden'     => true,
			'focusable'       => true,
		),
		'path' => array( 'd' => true ),
	);
}

/**
 * Address of the print page for one order on the list, or '' when there is
 * nothing to print from here (no permission, an order in the Trash: the bulk
 * action leaves the Trash out as well).
 *
 * @param mixed $order Order.
 * @return string
 */
function brikpanel_order_print_row_url( $order ) {
	if ( ! $order instanceof WC_Order || 'shop_order' !== $order->get_type() || 'trash' === $order->get_status() || ! brikpanel_order_print_active() ) {
		return '';
	}
	return brikpanel_order_print_url( array( $order->get_id() ) );
}

/**
 * The same button with its name, for the compact list's order panel: phones
 * show no row icons, so this is where they find it. It carries the column's
 * class and `hidden`, so Screen Options shows and hides it with the column.
 *
 * @param mixed $order Order.
 * @return string
 */
function brikpanel_order_print_panel_button( $order ) {
	$url = brikpanel_order_print_row_url( $order );
	if ( '' === $url ) {
		return '';
	}
	$class = 'bp-od-btn bp-od-btn-secondary bp-od-print column-' . BRIKPANEL_ORDER_PRINT_COLUMN . ( brikpanel_order_print_column_shown() ? '' : ' hidden' );
	return '<a class="' . esc_attr( $class ) . '" href="' . esc_url( $url ) . '" target="_blank" rel="noopener">'
		. wp_kses( brikpanel_order_print_icon_svg( 16 ), brikpanel_order_print_svg_kses() )
		. '<span>' . esc_html_x( 'Print', 'button that prints an order summary', 'brikpanel' ) . '</span></a>';
}

// =============================================================================
// THE PRINT PAGE
// =============================================================================

/**
 * Register the hidden screen. brikpanel_add_screen_page() keeps it out of the
 * menu and names it for access plugins (includes/brikpanel-screen-menu.php).
 *
 * @return void
 */
function brikpanel_order_print_register_page() {
	$title = __( 'Order summary', 'brikpanel' );
	$hooks = function_exists( 'brikpanel_add_screen_page' )
		? brikpanel_add_screen_page( 'woocommerce', $title, 'edit_shop_orders', BRIKPANEL_ORDER_PRINT_PAGE, 'brikpanel_order_print_render_fallback' )
		: array_filter( array( add_submenu_page( '', $title, '', 'edit_shop_orders', BRIKPANEL_ORDER_PRINT_PAGE, 'brikpanel_order_print_render_fallback' ) ) );

	foreach ( $hooks as $hook ) {
		add_action( 'load-' . $hook, 'brikpanel_order_print_output' );
	}
}
add_action( 'admin_menu', 'brikpanel_order_print_register_page', 30 );

/**
 * The menu callback. Never reached in practice: the load- hook prints the
 * document and exits before WordPress gets here.
 *
 * @return void
 */
function brikpanel_order_print_render_fallback() {
	echo '<div class="wrap"><p>' . esc_html__( 'No orders to print.', 'brikpanel' ) . '</p></div>';
}

/**
 * The page itself, on load-{hook}: checks, then the whole document, then exit.
 *
 * @return void
 */
function brikpanel_order_print_output() {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- the nonce is verified right below.
	$nonce     = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
	$raw_ids   = isset( $_GET['ids'] ) ? sanitize_text_field( wp_unslash( $_GET['ids'] ) ) : '';
	$of        = isset( $_GET['of'] ) ? absint( wp_unslash( $_GET['of'] ) ) : 0;
	$autoprint = isset( $_GET['print'] ) && '1' === sanitize_key( wp_unslash( $_GET['print'] ) );
	// phpcs:enable WordPress.Security.NonceVerification.Recommended

	$args = array(
		'orders_url' => function_exists( 'brikpanel_wc_orders_list_url' ) ? brikpanel_wc_orders_list_url( '' ) : admin_url( 'admin.php?page=wc-orders' ),
		'autoprint'  => $autoprint,
	);

	brikpanel_order_print_start_response();

	if ( ! wp_verify_nonce( $nonce, BRIKPANEL_ORDER_PRINT_NONCE ) ) {
		brikpanel_order_print_render_message( __( 'This print link has expired. Go back to the orders and print again.', 'brikpanel' ), $args );
		exit;
	}
	if ( ! brikpanel_order_print_active() ) {
		brikpanel_order_print_render_message( __( 'You are not allowed to print orders.', 'brikpanel' ), $args );
		exit;
	}

	$ids    = brikpanel_order_print_clean_ids( $raw_ids );
	$total  = max( count( $ids ), $of );
	$orders = brikpanel_order_print_load_orders( array_slice( $ids, 0, BRIKPANEL_ORDER_PRINT_MAX ) );

	if ( ! $orders ) {
		brikpanel_order_print_render_message( __( 'No orders to print.', 'brikpanel' ), $args );
		exit;
	}

	$args['requested'] = $total;
	$args['asked']     = min( count( $ids ), BRIKPANEL_ORDER_PRINT_MAX );
	brikpanel_order_print_render( $orders, $args );
	exit;
}

/**
 * Clear anything an earlier hook buffered and send the headers of a private,
 * uncached HTML page.
 *
 * @return void
 */
function brikpanel_order_print_start_response() {
	while ( ob_get_level() > 0 ) {
		$status = ob_get_status();
		// A buffer started without the removable flag (zlib compression, for one)
		// cannot be dropped; stop there rather than raise a notice.
		if ( empty( $status['flags'] ) || ! ( $status['flags'] & PHP_OUTPUT_HANDLER_REMOVABLE ) ) {
			break;
		}
		ob_end_clean();
	}
	if ( ! headers_sent() ) {
		nocache_headers();
		header( 'Content-Type: text/html; charset=' . get_option( 'blog_charset' ) );
		header( 'X-Robots-Tag: noindex, nofollow' );
	}
}

/**
 * The orders to print, oldest first: the order they came in, which is the
 * order they are picked in. Not the order of the ids: WooCommerce's orders
 * screen hands a bulk action its ids reversed and the classic screen does not,
 * so the same ticks would print in two different orders.
 *
 * Each one has to be a real order (not a refund, not a subscription) the user
 * may edit.
 *
 * @param int[] $ids Order ids.
 * @return WC_Order[]
 */
function brikpanel_order_print_load_orders( array $ids ) {
	$orders = array();
	foreach ( $ids as $id ) {
		$order = wc_get_order( $id );
		if ( ! $order instanceof WC_Order || 'shop_order' !== $order->get_type() ) {
			continue;
		}
		if ( ! current_user_can( 'edit_shop_order', $order->get_id() ) ) {
			continue;
		}
		$orders[] = $order;
	}

	usort(
		$orders,
		static function ( $a, $b ) {
			$time_a = $a->get_date_created() ? $a->get_date_created()->getTimestamp() : 0;
			$time_b = $b->get_date_created() ? $b->get_date_created()->getTimestamp() : 0;
			if ( $time_a !== $time_b ) {
				return $time_a < $time_b ? -1 : 1;
			}
			return $a->get_id() <=> $b->get_id();
		}
	);

	brikpanel_order_print_prime( $orders );
	return $orders;
}

/**
 * Load the products, their meta and their pictures in a few queries instead of
 * a few per line.
 *
 * @param WC_Order[] $orders Orders.
 * @return void
 */
function brikpanel_order_print_prime( array $orders ) {
	$product_ids = array();
	foreach ( $orders as $order ) {
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}
			$product_ids[] = (int) $item->get_product_id();
			$product_ids[] = (int) $item->get_variation_id();
		}
	}
	$product_ids = array_values( array_unique( array_filter( $product_ids ) ) );
	if ( ! $product_ids || ! function_exists( '_prime_post_caches' ) ) {
		return;
	}
	_prime_post_caches( $product_ids, false, true );

	$image_ids = array();
	foreach ( $product_ids as $product_id ) {
		$image_ids[] = (int) get_post_meta( $product_id, '_thumbnail_id', true );
	}
	$image_ids = array_values( array_unique( array_filter( $image_ids ) ) );
	if ( $image_ids ) {
		_prime_post_caches( $image_ids, false, true );
	}
}

// =============================================================================
// WHAT A SUMMARY SHOWS
// =============================================================================

/**
 * The store's name, address and logo, read once per page.
 *
 * @return array{name: string, address: string, logo: string}
 */
function brikpanel_order_print_store() {
	static $store = null;
	if ( null !== $store ) {
		return $store;
	}

	$address = '';
	if ( function_exists( 'WC' ) && WC()->countries ) {
		$countries = WC()->countries;
		// Only when the store filled in a street or a city: the country alone
		// is not an address worth printing.
		if ( '' !== trim( (string) $countries->get_base_address() ) || '' !== trim( (string) $countries->get_base_city() ) ) {
			$address = (string) $countries->get_formatted_address(
				array(
					'address_1' => $countries->get_base_address(),
					'address_2' => $countries->get_base_address_2(),
					'city'      => $countries->get_base_city(),
					'state'     => $countries->get_base_state(),
					'postcode'  => $countries->get_base_postcode(),
					'country'   => $countries->get_base_country(),
				)
			);
		}
	}

	$store = array(
		'name'    => wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
		'address' => $address,
		'logo'    => brikpanel_order_print_logo_url(),
	);
	return $store;
}

/**
 * The logo to print: the store's own, never BrikPanel's brand logo. That one
 * dresses the admin (the top bar, the login page), and agencies set their own
 * logo there on every client store, so a summary going out with an order would
 * carry the agency's logo instead of the shop's (wp.org, Lincoln Cristiano,
 * 6 Nov 2026).
 *
 * The theme's logo first, else the picture the store put on top of its
 * WooCommerce emails. Empty means the store name prints in its place.
 *
 * @return string
 */
function brikpanel_order_print_logo_url() {
	$logo_id = (int) get_theme_mod( 'custom_logo' );
	if ( ! $logo_id ) {
		$logo_id = (int) get_option( 'site_logo' ); // Block themes keep it here.
	}
	if ( $logo_id ) {
		$src = wp_get_attachment_image_url( $logo_id, 'full' );
		if ( $src ) {
			return (string) $src;
		}
	}

	return (string) get_option( 'woocommerce_email_header_image', '' );
}

/**
 * A quantity as the store writes numbers; fractions only when there are any
 * (measurement plugins sell 1.5 metres).
 *
 * @param float $qty Quantity.
 * @return string
 */
function brikpanel_order_print_qty( $qty ) {
	$qty = (float) $qty;
	if ( function_exists( 'brikpanel_number' ) ) {
		return (string) brikpanel_number( $qty, floor( $qty ) === $qty ? 0 : 2, true );
	}
	return (string) $qty;
}

/**
 * The picture of a line: the variation's, else its product's (WooCommerce falls
 * back on its own), and the product's when the variation was deleted.
 *
 * @param WC_Order_Item_Product $item Item line.
 * @return string Thumbnail address, empty when there is none.
 */
function brikpanel_order_print_item_image( $item ) {
	$product = $item->get_product();
	if ( ! $product instanceof WC_Product && $item->get_variation_id() ) {
		$product = wc_get_product( $item->get_product_id() );
	}
	if ( ! $product instanceof WC_Product ) {
		return '';
	}
	$image_id = (int) $product->get_image_id();
	if ( ! $image_id ) {
		return '';
	}
	$src = wp_get_attachment_image_url( $image_id, 'thumbnail' );
	return $src ? (string) $src : '';
}

/**
 * The item lines of an order, ready to print. Prices follow the store's own
 * "Display prices during cart and checkout" choice, as WooCommerce's order
 * emails do.
 *
 * @param WC_Order $order Order.
 * @return array<int, array<string, mixed>>
 */
function brikpanel_order_print_lines( WC_Order $order ) {
	$incl  = 'incl' === get_option( 'woocommerce_tax_display_cart' );
	$money = array( 'currency' => $order->get_currency() );
	$lines = array();

	foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
		if ( ! $item instanceof WC_Order_Item_Product ) {
			continue;
		}

		$parts = function_exists( 'brikpanel_orders_compact_item_parts' )
			? brikpanel_orders_compact_item_parts( $item )
			: array(
				'name'    => trim( wp_strip_all_tags( (string) $item->get_name() ) ),
				'options' => array(),
			);

		$child = false;
		foreach ( BRIKPANEL_ORDER_PRINT_CHILD_KEYS as $key ) {
			if ( '' !== (string) $item->get_meta( $key, true ) ) {
				$child = true;
				break;
			}
		}

		$lines[] = array(
			'name'     => (string) $parts['name'],
			'options'  => (array) $parts['options'],
			'sku'      => function_exists( 'brikpanel_order_export_sku' ) ? (string) brikpanel_order_export_sku( $item ) : '',
			'qty'      => (float) $item->get_quantity(),
			'refunded' => abs( (float) $order->get_qty_refunded_for_item( $item_id ) ),
			'unit'     => wc_price( (float) $order->get_item_subtotal( $item, $incl, true ), $money ),
			'total'    => wc_price( (float) $order->get_line_subtotal( $item, $incl, true ), $money ),
			'image'    => brikpanel_order_print_item_image( $item ),
			'child'    => $child,
		);
	}

	return $lines;
}

/**
 * The totals block. Built from the order's own amounts with wc_price(), never
 * from get_formatted_order_total() or get_order_item_totals(): currency
 * plugins write into that filter (CURCY adds "Currency: USD" on admin requests,
 * see front-end/sound/brikpanel-sound.php).
 *
 * @param WC_Order $order Order.
 * @return array<int, array{label: string, value: string, note?: string, total?: bool}>
 */
function brikpanel_order_print_totals( WC_Order $order ) {
	$incl  = 'incl' === get_option( 'woocommerce_tax_display_cart' );
	$money = array( 'currency' => $order->get_currency() );
	$rows  = array();

	$subtotal = 0.0;
	foreach ( $order->get_items( 'line_item' ) as $item ) {
		$subtotal += (float) $order->get_line_subtotal( $item, $incl, false );
	}
	$rows[] = array(
		'label' => __( 'Subtotal', 'brikpanel' ),
		'value' => wc_price( $subtotal, $money ),
	);

	$discount = (float) $order->get_total_discount( ! $incl );
	if ( $discount > 0 ) {
		$codes  = array_filter( array_map( 'strval', (array) $order->get_coupon_codes() ) );
		$rows[] = array(
			'label' => $codes
				/* translators: %s: the coupon codes used on the order, separated by commas. */
				? sprintf( __( 'Discount (%s)', 'brikpanel' ), implode( ', ', $codes ) )
				: __( 'Discount', 'brikpanel' ),
			'value' => wc_price( -1 * $discount, $money ),
		);
	}

	if ( $order->get_shipping_methods() ) {
		$shipping = (float) $order->get_shipping_total() + ( $incl ? (float) $order->get_shipping_tax() : 0.0 );
		$rows[]   = array(
			'label' => __( 'Shipping', 'brikpanel' ),
			'value' => wc_price( $shipping, $money ),
			'note'  => (string) $order->get_shipping_method(),
		);
	}

	foreach ( $order->get_fees() as $fee ) {
		$amount = (float) $fee->get_total() + ( $incl ? (float) $fee->get_total_tax() : 0.0 );
		$rows[] = array(
			'label' => trim( wp_strip_all_tags( (string) $fee->get_name() ) ),
			'value' => wc_price( $amount, $money ),
		);
	}

	if ( ! $incl && function_exists( 'wc_tax_enabled' ) && wc_tax_enabled() ) {
		foreach ( (array) $order->get_tax_totals() as $tax ) {
			if ( ! is_object( $tax ) || ! isset( $tax->label, $tax->amount ) ) {
				continue;
			}
			$rows[] = array(
				'label' => trim( wp_strip_all_tags( (string) $tax->label ) ),
				'value' => wc_price( (float) $tax->amount, $money ),
			);
		}
	}

	$total = array(
		'label' => __( 'Total', 'brikpanel' ),
		'value' => wc_price( (float) $order->get_total(), $money ),
		'total' => true,
	);
	$tax_total = (float) $order->get_total_tax();
	if ( $incl && $tax_total > 0 ) {
		/* translators: %s: tax amount included in the order total. */
		$total['note'] = sprintf( __( 'Includes %s tax', 'brikpanel' ), wc_price( $tax_total, $money ) );
	}
	$rows[] = $total;

	$refunded = (float) $order->get_total_refunded();
	if ( $refunded > 0 ) {
		$rows[] = array(
			'label' => __( 'Refunded', 'brikpanel' ),
			'value' => wc_price( -1 * $refunded, $money ),
		);
	}

	return $rows;
}

/**
 * Extra fields printed under the billing address: the ones the store chose to
 * show on the order page (Settings › BrikPanel › Orders › Order custom fields).
 *
 * @param WC_Order $order Order.
 * @return array<int, array{label: string, value: string}>
 */
function brikpanel_order_print_extra_fields( WC_Order $order ) {
	if ( ! function_exists( 'brikpanel_order_fields_config' ) || ! function_exists( 'brikpanel_order_fields_collect_rows' ) ) {
		return array();
	}
	$config = brikpanel_order_fields_config();
	return $config ? (array) brikpanel_order_fields_collect_rows( $order, $config ) : array();
}

// =============================================================================
// THE DOCUMENT
// =============================================================================

/**
 * Register the page's own stylesheet and script. The stylesheet depends on the
 * shared UI parts for the toolbar buttons (front-end/shared/brikpanel-ui.css).
 *
 * @return void
 */
function brikpanel_order_print_register_assets() {
	$css = 'front-end/orders/brikpanel-order-print.css';
	$js  = 'front-end/orders/brikpanel-order-print.js';
	wp_register_style(
		'brikpanel_order_print',
		BRIKPANEL_URL . $css,
		function_exists( 'brikpanel_narrow_dep' ) ? brikpanel_narrow_dep( 'ui', 'style' ) : array(),
		brikpanel_order_print_asset_version( $css )
	);
	wp_register_script( 'brikpanel_order_print', BRIKPANEL_URL . $js, array(), brikpanel_order_print_asset_version( $js ), true );
}

/**
 * Cache-busting version of one of the plugin's files: its modification time,
 * or the plugin version when the file is missing.
 *
 * @param string $file Path inside the plugin.
 * @return string
 */
function brikpanel_order_print_asset_version( $file ) {
	$path = BRIKPANEL_PATH . $file;
	return file_exists( $path ) ? (string) filemtime( $path ) : (string) BRIKPANEL_VERSION;
}

/**
 * Opening of the document, up to and including the toolbar.
 *
 * @param string $title Page title.
 * @param array  $args  orders_url, autoprint, print_button.
 * @return void
 */
function brikpanel_order_print_render_head( $title, array $args ) {
	brikpanel_order_print_register_assets();
	?>
<!DOCTYPE html>
<html lang="<?php echo esc_attr( str_replace( '_', '-', determine_locale() ) ); ?>" dir="<?php echo esc_attr( is_rtl() ? 'rtl' : 'ltr' ); ?>">
<head>
<meta charset="<?php echo esc_attr( get_option( 'blog_charset' ) ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?php echo esc_html( $title ); ?></title>
	<?php wp_print_styles( array( 'brikpanel_order_print' ) ); ?>
</head>
<body class="brikpanel-slip-page" data-bp-autoprint="<?php echo esc_attr( ! empty( $args['autoprint'] ) ? '1' : '0' ); ?>">
<header class="brikpanel-slip-toolbar">
	<div class="brikpanel-slip-toolbar__in">
		<a class="brikpanel-btn brikpanel-btn--secondary brikpanel-slip-toolbar__back" href="<?php echo esc_url( (string) $args['orders_url'] ); ?>">
			<svg width="16" height="16" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true" focusable="false"><path class="brikpanel-slip-toolbar__arrow" d="M12 4l-6 6 6 6"/></svg>
			<span><?php echo esc_html__( 'Orders', 'brikpanel' ); ?></span>
		</a>
		<p class="brikpanel-slip-toolbar__title"><?php echo esc_html( $title ); ?></p>
		<?php if ( ! empty( $args['print_button'] ) ) : ?>
			<?php // dir="auto": an untranslated sentence in a right-to-left admin keeps its full stop at its end. ?>
			<p class="brikpanel-slip-toolbar__hint" dir="auto"><?php echo esc_html__( 'To save a PDF, choose "Save as PDF" in the print window.', 'brikpanel' ); ?></p>
			<button type="button" class="brikpanel-btn brikpanel-btn--primary brikpanel-slip-toolbar__print" data-bp-print>
				<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M6 9V3h12v6"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><path d="M6 14h12v7H6z"/></svg>
				<span><?php echo esc_html_x( 'Print', 'button that prints an order summary', 'brikpanel' ); ?></span>
			</button>
		<?php endif; ?>
	</div>
</header>
	<?php
}

/**
 * Close of the document.
 *
 * @return void
 */
function brikpanel_order_print_render_foot() {
	wp_print_scripts( array( 'brikpanel_order_print' ) );
	?>
</body>
</html>
	<?php
}

/**
 * A page that only says something (expired link, no access, nothing found).
 *
 * @param string $message Sentence to show.
 * @param array  $args    orders_url.
 * @return void
 */
function brikpanel_order_print_render_message( $message, array $args ) {
	$args['autoprint']    = false;
	$args['print_button'] = false;
	brikpanel_order_print_render_head( __( 'Order summary', 'brikpanel' ), $args );
	?>
<main class="brikpanel-slip-stack">
	<p class="brikpanel-slip-message"><?php echo esc_html( $message ); ?></p>
</main>
	<?php
	brikpanel_order_print_render_foot();
}

/**
 * The whole document for these orders: one sheet each.
 *
 * @param WC_Order[] $orders Orders, already checked.
 * @param array      $args   orders_url, autoprint, requested (selected count), asked (ids given, capped).
 * @return void
 */
function brikpanel_order_print_render( array $orders, array $args = array() ) {
	$args = wp_parse_args(
		$args,
		array(
			'orders_url' => admin_url( 'admin.php?page=wc-orders' ),
			'autoprint'  => false,
			'requested'  => count( $orders ),
			'asked'      => count( $orders ),
		)
	);
	$args['print_button'] = true;

	$count = count( $orders );
	if ( 1 === $count ) {
		/* translators: %s: order number. */
		$title = sprintf( __( 'Order summary #%s', 'brikpanel' ), (string) $orders[0]->get_order_number() );
	} else {
		/* translators: %s: number of orders on the page. */
		$title = sprintf( _n( '%s order summary', '%s order summaries', $count, 'brikpanel' ), brikpanel_order_print_qty( $count ) );
	}

	brikpanel_order_print_render_head( $title, $args );
	?>
<main class="brikpanel-slip-stack">
	<?php
	$requested = (int) $args['requested'];
	if ( $requested > BRIKPANEL_ORDER_PRINT_MAX ) {
		?>
	<p class="brikpanel-slip-notice"><?php
		echo esc_html(
			sprintf(
				/* translators: 1: number of orders on this page, 2: number of orders selected. */
				__( 'Showing the first %1$s of %2$s selected orders. Print the rest in another batch.', 'brikpanel' ),
				brikpanel_order_print_qty( BRIKPANEL_ORDER_PRINT_MAX ),
				brikpanel_order_print_qty( $requested )
			)
		);
	?></p>
		<?php
	}
	$missing = (int) $args['asked'] - $count;
	if ( $missing > 0 ) {
		?>
	<p class="brikpanel-slip-notice"><?php
		echo esc_html(
			sprintf(
				/* translators: %s: number of selected orders left out. */
				_n( '%s selected order is not here: it was deleted or you cannot open it.', '%s selected orders are not here: they were deleted or you cannot open them.', $missing, 'brikpanel' ),
				brikpanel_order_print_qty( $missing )
			)
		);
	?></p>
		<?php
	}

	$store = brikpanel_order_print_store();
	foreach ( $orders as $order ) {
		brikpanel_order_print_render_sheet( $order, $store );
	}
	?>
</main>
	<?php
	brikpanel_order_print_render_foot();
}

/**
 * One order on one sheet.
 *
 * @param WC_Order $order Order.
 * @param array    $store From brikpanel_order_print_store().
 * @return void
 */
function brikpanel_order_print_render_sheet( WC_Order $order, array $store ) {
	$number  = (string) $order->get_order_number();
	$created = $order->get_date_created();
	$date    = $created ? wp_date( brikpanel_datetime_format(), $created->getTimestamp() ) : '';

	$billing  = (string) $order->get_formatted_billing_address();
	$shipping = $order->has_shipping_address() ? (string) $order->get_formatted_shipping_address() : '';
	$phone    = trim( (string) $order->get_billing_phone() );
	$email    = trim( (string) $order->get_billing_email() );
	$ship_tel = is_callable( array( $order, 'get_shipping_phone' ) ) ? trim( (string) $order->get_shipping_phone() ) : '';
	if ( $ship_tel === $phone ) {
		$ship_tel = '';
	}
	$extra = brikpanel_order_print_extra_fields( $order );

	$details = array_filter(
		array(
			__( 'Payment method', 'brikpanel' )  => trim( wp_strip_all_tags( (string) $order->get_payment_method_title() ) ),
			__( 'Shipping method', 'brikpanel' ) => trim( wp_strip_all_tags( (string) $order->get_shipping_method() ) ),
			__( 'Status', 'brikpanel' )          => (string) wc_get_order_status_name( $order->get_status() ),
		),
		'strlen'
	);

	$lines  = brikpanel_order_print_lines( $order );
	$totals = brikpanel_order_print_totals( $order );
	$note   = trim( (string) $order->get_customer_note() );
	$kicker = __( 'Order summary', 'brikpanel' );
	?>
	<article class="brikpanel-slip" aria-label="<?php echo esc_attr( $kicker . ' #' . $number ); ?>">
		<header class="brikpanel-slip__head">
			<div class="brikpanel-slip__store">
				<?php if ( '' !== $store['logo'] ) : ?>
					<img class="brikpanel-slip__logo" src="<?php echo esc_url( $store['logo'] ); ?>" alt="<?php echo esc_attr( $store['name'] ); ?>" loading="eager">
				<?php else : ?>
					<p class="brikpanel-slip__store-name"><?php echo esc_html( $store['name'] ); ?></p>
				<?php endif; ?>
				<?php if ( '' !== $store['address'] ) : ?>
					<p class="brikpanel-slip__store-address"><?php echo wp_kses( $store['address'], array( 'br' => array() ) ); ?></p>
				<?php endif; ?>
			</div>
			<div class="brikpanel-slip__doc">
				<p class="brikpanel-slip__kicker"><?php echo esc_html( $kicker ); ?></p>
				<?php // header-end-ok: a printed sheet on a page of its own, not an admin screen; WordPress prints no notices here. ?>
				<h1 class="brikpanel-slip__number"><bdi><?php echo esc_html( '#' . $number ); ?></bdi></h1>
				<?php if ( '' !== $date ) : ?>
					<p class="brikpanel-slip__date"><?php echo esc_html( $date ); ?></p>
				<?php endif; ?>
			</div>
		</header>

		<div class="brikpanel-slip__parties">
			<section class="brikpanel-slip__party">
				<h2 class="brikpanel-slip__label"><?php echo esc_html__( 'Billing address', 'brikpanel' ); ?></h2>
				<?php if ( '' !== $billing ) : ?>
					<address class="brikpanel-slip__address"><?php echo wp_kses( $billing, array( 'br' => array() ) ); ?></address>
				<?php endif; ?>
				<?php if ( $extra ) : ?>
					<dl class="brikpanel-slip__facts">
						<?php foreach ( $extra as $row ) : ?>
							<div class="brikpanel-slip__fact">
								<dt><?php echo esc_html( (string) $row['label'] ); ?></dt>
								<dd><bdi><?php echo nl2br( esc_html( (string) $row['value'] ) ); ?></bdi></dd>
							</div>
						<?php endforeach; ?>
					</dl>
				<?php endif; ?>
				<?php if ( '' !== $phone || '' !== $email ) : ?>
					<p class="brikpanel-slip__contact">
						<?php if ( '' !== $phone ) : ?>
							<bdi><?php echo esc_html( $phone ); ?></bdi><br>
						<?php endif; ?>
						<?php if ( '' !== $email ) : ?>
							<bdi><?php echo esc_html( $email ); ?></bdi>
						<?php endif; ?>
					</p>
				<?php endif; ?>
			</section>

			<?php if ( '' !== $shipping ) : ?>
				<section class="brikpanel-slip__party">
					<h2 class="brikpanel-slip__label"><?php echo esc_html__( 'Shipping address', 'brikpanel' ); ?></h2>
					<address class="brikpanel-slip__address"><?php echo wp_kses( $shipping, array( 'br' => array() ) ); ?></address>
					<?php if ( '' !== $ship_tel ) : ?>
						<p class="brikpanel-slip__contact"><bdi><?php echo esc_html( $ship_tel ); ?></bdi></p>
					<?php endif; ?>
				</section>
			<?php endif; ?>

			<?php if ( $details ) : ?>
				<section class="brikpanel-slip__party">
					<h2 class="brikpanel-slip__label"><?php echo esc_html__( 'Details', 'brikpanel' ); ?></h2>
					<dl class="brikpanel-slip__facts">
						<?php foreach ( $details as $label => $value ) : ?>
							<div class="brikpanel-slip__fact">
								<dt><?php echo esc_html( (string) $label ); ?></dt>
								<dd><?php echo esc_html( (string) $value ); ?></dd>
							</div>
						<?php endforeach; ?>
					</dl>
				</section>
			<?php endif; ?>
		</div>

		<table class="brikpanel-slip__items">
			<thead>
				<tr>
					<th scope="col" class="brikpanel-slip__pick"><span class="brikpanel-slip__sr"><?php echo esc_html_x( 'Picked', 'order summary column for ticking off picked items', 'brikpanel' ); ?></span></th>
					<th scope="col" class="brikpanel-slip__product-col"><?php echo esc_html__( 'Product', 'brikpanel' ); ?></th>
					<th scope="col" class="brikpanel-slip__sku-col"><?php echo esc_html__( 'SKU', 'brikpanel' ); ?></th>
					<th scope="col" class="brikpanel-slip__num"><?php echo esc_html__( 'Quantity', 'brikpanel' ); ?></th>
					<th scope="col" class="brikpanel-slip__num brikpanel-slip__price-col"><?php echo esc_html__( 'Price', 'brikpanel' ); ?></th>
					<th scope="col" class="brikpanel-slip__num"><?php echo esc_html__( 'Total', 'brikpanel' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $lines as $line ) : ?>
					<?php
					$left  = max( 0.0, $line['qty'] - $line['refunded'] );
					$class = 'brikpanel-slip__line' . ( $line['child'] ? ' is-child' : '' ) . ( $line['refunded'] > 0 && $left <= 0 ? ' is-refunded' : '' );
					?>
					<tr class="<?php echo esc_attr( $class ); ?>">
						<td class="brikpanel-slip__pick"><span class="brikpanel-slip__box" aria-hidden="true"></span></td>
						<td class="brikpanel-slip__product-col">
							<div class="brikpanel-slip__product">
								<?php if ( '' !== $line['image'] ) : ?>
									<img class="brikpanel-slip__thumb" src="<?php echo esc_url( $line['image'] ); ?>" alt="" width="44" height="44" loading="eager">
								<?php else : ?>
									<span class="brikpanel-slip__thumb is-empty" aria-hidden="true"></span>
								<?php endif; ?>
								<div class="brikpanel-slip__product-text">
									<p class="brikpanel-slip__name"><bdi><?php echo esc_html( $line['name'] ); ?></bdi></p>
									<?php if ( $line['options'] ) : ?>
										<p class="brikpanel-slip__options"><bdi><?php echo esc_html( implode( ' · ', $line['options'] ) ); ?></bdi></p>
									<?php endif; ?>
									<?php if ( '' !== $line['sku'] ) : ?>
										<p class="brikpanel-slip__sku-inline"><bdi><?php echo esc_html( $line['sku'] ); ?></bdi></p>
									<?php endif; ?>
								</div>
							</div>
						</td>
						<td class="brikpanel-slip__sku-col"><bdi><?php echo esc_html( $line['sku'] ); ?></bdi></td>
						<td class="brikpanel-slip__num brikpanel-slip__qty">
							<strong><?php echo esc_html( brikpanel_order_print_qty( $left ) ); ?></strong>
							<?php if ( $line['refunded'] > 0 ) : ?>
								<small>
									<?php
									echo esc_html(
										sprintf(
											/* translators: 1: quantity ordered, 2: quantity refunded. */
											__( '%1$s ordered, %2$s refunded', 'brikpanel' ),
											brikpanel_order_print_qty( $line['qty'] ),
											brikpanel_order_print_qty( $line['refunded'] )
										)
									);
									?>
								</small>
							<?php endif; ?>
						</td>
						<td class="brikpanel-slip__num brikpanel-slip__price-col"><?php echo wp_kses_post( $line['unit'] ); ?></td>
						<td class="brikpanel-slip__num"><?php echo wp_kses_post( $line['total'] ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<div class="brikpanel-slip__bottom">
			<?php if ( '' !== $note ) : ?>
				<section class="brikpanel-slip__note">
					<h2 class="brikpanel-slip__label"><?php echo esc_html__( 'Customer note', 'brikpanel' ); ?></h2>
					<p><?php echo nl2br( esc_html( $note ) ); ?></p>
				</section>
			<?php endif; ?>
			<table class="brikpanel-slip__totals">
				<tbody>
					<?php foreach ( $totals as $row ) : ?>
						<tr class="<?php echo esc_attr( ! empty( $row['total'] ) ? 'brikpanel-slip__sum is-total' : 'brikpanel-slip__sum' ); ?>">
							<th scope="row"><bdi><?php echo esc_html( $row['label'] ); ?></bdi></th>
							<td>
								<?php echo wp_kses_post( $row['value'] ); ?>
								<?php if ( ! empty( $row['note'] ) ) : ?>
									<small><?php echo wp_kses_post( $row['note'] ); ?></small>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	</article>
	<?php
}
