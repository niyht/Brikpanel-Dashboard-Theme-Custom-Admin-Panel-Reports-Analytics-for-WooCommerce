<?php
/**
 * Compact order list.
 *
 * Turns the WooCommerce orders list into one short row per order. The billing
 * address, ship-to details, phone and items move out of the row into a detail
 * panel that opens under it (front-end/orders/brikpanel-orders.js,
 * brikpanelCompactRows).
 *
 * WooCommerce's own list table stays in charge: search, pagination, bulk
 * actions, Screen Options and third-party columns keep working. There is no
 * hook after a row's closing </tr>, so the panel markup is printed once per
 * order as an inert <template> inside the Customer cell and the script moves
 * it into its own row on demand. On HPOS the orders, addresses, items and item
 * meta are already batch-loaded before the table renders, so the panel costs
 * no extra queries; the legacy screen is primed below.
 *
 * @package BrikPanel
 */

defined( 'ABSPATH' ) || exit;

/**
 * Whether the compact layout applies to this request.
 *
 * Needs the orders list enhancements (the panel is driven by their script)
 * and stays off for accounts BrikPanel is neutralized for, so they keep the
 * stock WooCommerce list.
 *
 * @return bool
 */
function brikpanel_orders_compact_enabled() {
	static $enabled = null;
	if ( null !== $enabled ) {
		return $enabled;
	}
	$enabled = get_option( 'brikpanel_orders_enhancements', 'yes' ) !== 'no'
		&& get_option( 'brikpanel_orders_compact_list', 'yes' ) !== 'no'
		&& ! ( function_exists( 'brikpanel_access_should_neutralize' ) && brikpanel_access_should_neutralize() );
	return $enabled;
}

/**
 * Whether the current admin screen is the orders list (HPOS or legacy), not
 * the single order editor.
 *
 * @return bool
 */
function brikpanel_orders_compact_is_list_screen() {
	if ( ! is_admin() || ! function_exists( 'get_current_screen' ) ) {
		return false;
	}
	$screen = get_current_screen();
	if ( ! $screen ) {
		return false;
	}
	if ( 'edit-shop_order' === $screen->id ) {
		return true;
	}
	$hpos = function_exists( 'wc_get_page_screen_id' ) ? wc_get_page_screen_id( 'shop-order' ) : 'woocommerce_page_wc-orders';
	if ( $screen->id !== $hpos ) {
		return false;
	}
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen detection.
	$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
	return ! in_array( $action, array( 'edit', 'new' ), true );
}

if ( get_option( 'woocommerce_custom_orders_table_enabled' ) === 'yes' ) {
	add_filter( 'manage_woocommerce_page_wc-orders_columns', 'brikpanel_orders_compact_columns', 30 );
	add_action( 'manage_woocommerce_page_wc-orders_custom_column', 'brikpanel_orders_compact_fill_column', 20, 2 );
} else {
	add_filter( 'manage_edit-shop_order_columns', 'brikpanel_orders_compact_columns', 30 );
	add_action( 'manage_shop_order_posts_custom_column', 'brikpanel_orders_compact_fill_column_legacy', 20, 2 );
	add_filter( 'the_posts', 'brikpanel_orders_compact_prime_legacy', 10, 2 );
}
add_filter( 'woocommerce_admin_order_buyer_name', 'brikpanel_orders_compact_buyer_name', PHP_INT_MAX, 2 );
add_filter( 'admin_body_class', 'brikpanel_orders_compact_body_class' );
add_filter( 'screen_settings', 'brikpanel_orders_compact_screen_settings', 10, 2 );
add_action( 'wp_ajax_brikpanel_orders_row_columns', 'brikpanel_orders_compact_save_row_columns' );

/**
 * Columns the short row always shows, in the order they are laid out. Every
 * other column opens in the detail panel unless the user picks it in Screen
 * Options (see brikpanel_orders_compact_user_row_columns()). Mirrors
 * ROW_COLUMNS in brikpanel-orders.js, and brikpanel_orders_compact_columns()
 * builds the table from it, so the amount keeps its place ahead of columns
 * other plugins add.
 *
 * @return string[]
 */
function brikpanel_orders_compact_base_row_columns() {
	return array( 'cb', 'order_number', 'brikpanel_whatsapp', 'brikpanel_customer', 'order_date', 'order_status', 'payment_method', 'brikpanel_shipping_method', 'order_total' );
}

/**
 * Clean a column key for storage.
 *
 * sanitize_key() would lowercase it, but column keys are matched exactly
 * against the table's "column-KEY" classes, and other plugins do use capitals.
 *
 * @param mixed $key Raw key.
 * @return string Key, or '' when unusable.
 */
function brikpanel_orders_compact_clean_column_key( $key ) {
	if ( ! is_string( $key ) && ! is_int( $key ) ) {
		return '';
	}
	return (string) preg_replace( '/[^A-Za-z0-9_\-.:]/', '', substr( (string) $key, 0, 100 ) );
}

/**
 * Extra columns the current user keeps in the short row (e.g. a tracking
 * number or profit column from another plugin). Stored per user and per site,
 * like WordPress's own hidden columns; empty by default.
 *
 * @return string[]
 */
function brikpanel_orders_compact_user_row_columns() {
	$keys = get_user_option( 'brikpanel_orders_row_columns' );
	if ( ! is_array( $keys ) ) {
		return array();
	}
	return array_values( array_unique( array_filter( array_map( 'brikpanel_orders_compact_clean_column_key', $keys ), 'strlen' ) ) );
}

/**
 * Screen Options: a "Show in the row" group listing the columns that open in
 * the detail panel, so a user can keep any of them in the short row.
 *
 * @param string    $settings Screen settings HTML.
 * @param WP_Screen $screen   Current screen.
 * @return string
 */
function brikpanel_orders_compact_screen_settings( $settings, $screen ) {
	if ( ! $screen instanceof WP_Screen || ! brikpanel_orders_compact_enabled() || ! brikpanel_orders_compact_is_list_screen() ) {
		return $settings;
	}

	$columns = get_column_headers( $screen );
	if ( ! is_array( $columns ) || ! $columns ) {
		return $settings;
	}

	$base    = brikpanel_orders_compact_base_row_columns();
	$picked  = brikpanel_orders_compact_user_row_columns();
	$options = '';
	foreach ( $columns as $key => $title ) {
		$key   = (string) $key;
		$title = trim( wp_strip_all_tags( (string) $title ) );
		// A key that cannot be stored as-is could never stay ticked; leave it out.
		if ( '' === $title || '_title' === $key || in_array( $key, $base, true ) || brikpanel_orders_compact_clean_column_key( $key ) !== $key ) {
			continue;
		}
		$options .= '<label><input type="checkbox" class="bp-row-column-tog" value="' . esc_attr( $key ) . '"' . checked( in_array( $key, $picked, true ), true, false ) . ' />' . esc_html( $title ) . '</label>';
	}
	if ( '' === $options ) {
		return $settings;
	}

	return $settings
		. '<fieldset class="metabox-prefs bp-row-columns" data-nonce="' . esc_attr( wp_create_nonce( 'brikpanel_orders_row_columns' ) ) . '">'
		. '<legend>' . esc_html__( 'Show in the row', 'brikpanel' ) . '</legend>'
		. '<p class="bp-row-columns__hint">' . esc_html__( 'Ticked columns stay in the order row instead of opening under the order.', 'brikpanel' ) . '</p>'
		. $options
		. '</fieldset>';
}

/**
 * Clean an imported "keep in the row" column list for one person.
 *
 * Runs the same key cleaner the AJAX save uses, and strips the base columns the
 * row always shows — the stored list is the EXTRA columns only, so letting a
 * base column in would duplicate it in the row.
 *
 * @param mixed $value
 * @return string[]|null
 */
function brikpanel_orders_sanitize_import_row_columns( $value ) {
	if ( ! is_array( $value ) ) {
		return null;
	}
	$keys = array();
	foreach ( array_slice( $value, 0, 100 ) as $key ) {
		$key = brikpanel_orders_compact_clean_column_key( $key );
		if ( '' !== $key ) {
			$keys[ $key ] = true;
		}
	}
	$keys = array_values( array_diff( array_keys( $keys ), brikpanel_orders_compact_base_row_columns() ) );
	return $keys ? $keys : null;
}

/**
 * AJAX: save the columns the current user keeps in the short row.
 */
function brikpanel_orders_compact_save_row_columns() {
	check_ajax_referer( 'brikpanel_orders_row_columns', 'nonce' );
	if ( ! current_user_can( 'edit_shop_orders' ) ) {
		wp_send_json_error( null, 403 );
	}

	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each key is sanitized below.
	$raw  = isset( $_POST['columns'] ) && is_array( $_POST['columns'] ) ? wp_unslash( $_POST['columns'] ) : array();
	$keys = array();
	foreach ( array_slice( $raw, 0, 100 ) as $key ) {
		$key = brikpanel_orders_compact_clean_column_key( $key );
		if ( '' !== $key ) {
			$keys[ $key ] = true;
		}
	}
	$keys = array_diff( array_keys( $keys ), brikpanel_orders_compact_base_row_columns() );

	if ( $keys ) {
		update_user_option( get_current_user_id(), 'brikpanel_orders_row_columns', array_values( $keys ) );
	} else {
		delete_user_option( get_current_user_id(), 'brikpanel_orders_row_columns' );
	}
	wp_send_json_success( array( 'columns' => array_values( $keys ) ) );
}

/**
 * Rebuild the column set: a short row with the customer and shipping method,
 * and the address/items columns moved into the detail panel.
 *
 * Priority 30: after BrikPanel's own columns (20) and the WhatsApp column (21),
 * before the access-control strip (99).
 *
 * @param array $columns Column key => label.
 * @return array
 */
function brikpanel_orders_compact_columns( $columns ) {
	if ( ! is_array( $columns ) || ! brikpanel_orders_compact_enabled() || ! isset( $columns['order_number'] ) ) {
		return $columns;
	}

	unset( $columns['billing_address'], $columns['shipping_address'], $columns['order_items'], $columns['tax_total'] );

	$columns['brikpanel_customer']        = __( 'Customer', 'brikpanel' );
	$columns['brikpanel_shipping_method'] = __( 'Shipping', 'brikpanel' );

	// WhatsApp sits right after the order number, next to the quick preview
	// button, so the two small actions read as one group.
	//
	// The short row ends with the amount, exactly as WooCommerce orders its own
	// columns: `order_total` closes the lead group, so a column another plugin
	// added (a profit or tracking number column) reads after the amount, both
	// in the detail panel and when the user keeps it in the row through
	// "Show in the row". Only Actions stays behind everything.
	$lead = brikpanel_orders_compact_base_row_columns();
	$tail = array( 'wc_actions' );

	$out = array();
	foreach ( $lead as $key ) {
		if ( isset( $columns[ $key ] ) ) {
			$out[ $key ] = $columns[ $key ];
		}
	}
	foreach ( $columns as $key => $label ) {
		if ( ! isset( $out[ $key ] ) && ! in_array( $key, $tail, true ) ) {
			$out[ $key ] = $label;
		}
	}
	foreach ( $tail as $key ) {
		if ( isset( $columns[ $key ] ) ) {
			$out[ $key ] = $columns[ $key ];
		}
	}
	return $out;
}

/**
 * Buyer names captured from the order column, keyed by order ID.
 *
 * @param int         $order_id Order ID.
 * @param string|null $name     Name to store; omit to read.
 * @return string
 */
function brikpanel_orders_compact_buyer_store( $order_id, $name = null ) {
	static $names = array();
	if ( null !== $name ) {
		$names[ $order_id ] = (string) $name;
	}
	return $names[ $order_id ] ?? '';
}

/**
 * Keep the order link down to "#1234": the name gets its own Customer column.
 *
 * Runs last so the stored name already carries any other plugin's changes.
 *
 * @param string   $buyer Buyer name.
 * @param WC_Order $order Order.
 * @return string
 */
function brikpanel_orders_compact_buyer_name( $buyer, $order ) {
	if ( ! $order instanceof WC_Abstract_Order || ! brikpanel_orders_compact_enabled() || ! brikpanel_orders_compact_is_list_screen() ) {
		return $buyer;
	}
	brikpanel_orders_compact_buyer_store( $order->get_id(), $buyer );
	return '';
}

/**
 * The customer name shown in the Customer column.
 *
 * Mirrors WooCommerce's order column: billing name, then company, then the
 * account's display name.
 *
 * @param WC_Order $order Order.
 * @return string
 */
function brikpanel_orders_compact_customer_name( $order ) {
	$stored = brikpanel_orders_compact_buyer_store( $order->get_id() );
	if ( '' !== $stored ) {
		return $stored;
	}
	$name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
	if ( '' === $name ) {
		$name = trim( (string) $order->get_billing_company() );
	}
	if ( '' === $name && $order->get_customer_id() ) {
		$user = get_user_by( 'id', $order->get_customer_id() );
		$name = $user ? ucwords( $user->display_name ) : '';
	}
	return $name;
}

/**
 * Render a compact column cell (HPOS: receives the order).
 *
 * @param string   $column Column key.
 * @param WC_Order $order  Order.
 */
function brikpanel_orders_compact_fill_column( $column, $order ) {
	if ( ! $order instanceof WC_Abstract_Order ) {
		return;
	}
	switch ( $column ) {
		case 'brikpanel_customer':
			$name = brikpanel_orders_compact_customer_name( $order );
			echo '<span class="bp-order-customer">' . ( '' !== $name ? esc_html( $name ) : '&ndash;' ) . '</span>';
			echo brikpanel_orders_compact_detail_html( $order ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- every value is escaped while the markup is built.
			break;

		case 'brikpanel_shipping_method':
			$method = $order->get_shipping_method();
			echo '' !== $method
				? '<span class="bp-order-method bp-order-method--shipping">' . esc_html( $method ) . '</span>'
				: '<span class="bp-order-muted">' . esc_html__( 'None', 'brikpanel' ) . '</span>';
			break;
	}
}

/**
 * Render a compact column cell (legacy: receives the post ID).
 *
 * @param string $column  Column key.
 * @param int    $post_id Order ID.
 */
function brikpanel_orders_compact_fill_column_legacy( $column, $post_id ) {
	if ( 'brikpanel_customer' !== $column && 'brikpanel_shipping_method' !== $column ) {
		return;
	}
	$order = wc_get_order( $post_id );
	if ( $order ) {
		brikpanel_orders_compact_fill_column( $column, $order );
	}
}

/**
 * Batch-load order meta and items for the legacy orders list.
 *
 * The posts-based screen loads each order one by one, so every panel would
 * otherwise query its items and their meta per row.
 *
 * @param WP_Post[] $posts Posts found.
 * @param WP_Query  $query Query.
 * @return WP_Post[]
 */
function brikpanel_orders_compact_prime_legacy( $posts, $query ) {
	if ( empty( $posts ) || ! $query instanceof WP_Query || ! $query->is_main_query() || ! brikpanel_orders_compact_is_list_screen() || ! brikpanel_orders_compact_enabled() ) {
		return $posts;
	}
	if ( ! class_exists( 'WC_Data_Store' ) ) {
		return $posts;
	}
	try {
		$store = WC_Data_Store::load( 'order' );
		if ( is_callable( array( $store, 'prime_caches_for_orders' ) ) ) {
			$store->prime_caches_for_orders( wp_list_pluck( $posts, 'ID' ), array( 'type' => 'shop_order' ) );
		}
	} catch ( Throwable $e ) {
		// Priming only saves queries; the list renders the same without it.
		unset( $e );
	}
	return $posts;
}

/**
 * Mark the orders list body so the stylesheet and script switch layouts.
 *
 * @param string $classes Space-separated body classes.
 * @return string
 */
function brikpanel_orders_compact_body_class( $classes ) {
	if ( brikpanel_orders_compact_is_list_screen() && brikpanel_orders_compact_enabled() ) {
		$classes .= ' bp-orders-compact';
	}
	return $classes;
}

/**
 * Escaped text isolated from the surrounding direction.
 *
 * Names, addresses, phone numbers and emails are often left-to-right inside a
 * right-to-left admin (and the other way round); without isolation a phone
 * number such as "+90 532 000 11 22" renders scrambled.
 *
 * @param string $text Plain text.
 * @return string HTML.
 */
function brikpanel_orders_compact_bdi( $text ) {
	return '<bdi>' . esc_html( $text ) . '</bdi>';
}

/**
 * Plain text from a WooCommerce formatted fragment.
 *
 * @param string $text Text that may carry tags or entities.
 * @return string
 */
function brikpanel_orders_compact_plain( $text ) {
	return trim( html_entity_decode( wp_strip_all_tags( (string) $text ), ENT_QUOTES, 'UTF-8' ) );
}

/**
 * Turn a WooCommerce formatted address into short readable lines.
 *
 * Street lines (company, address line 1 and 2) stay on their own lines; city,
 * state, postcode and country, which many country formats spread over one
 * line each, are joined into a single line. The country's own order is kept.
 *
 * The name line is left out because the panel shows the name (and phone) on
 * its own. Some shipping plugins decorate that line, e.g. "Name (Phone: +62…)",
 * so a line that merely starts with the name followed by punctuation is treated
 * as the name line too; otherwise the name would appear twice.
 *
 * @param string   $formatted Address with <br/> separators.
 * @param string   $skip      A line to leave out (the name, shown separately).
 * @param string[] $street    Company and address line values of this address.
 * @return string[]
 */
function brikpanel_orders_compact_address_lines( $formatted, $skip = '', $street = array() ) {
	$lower = static function ( $value ) {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $value, 'UTF-8' ) : strtolower( $value );
	};

	$street_values = array();
	foreach ( $street as $value ) {
		$value = brikpanel_orders_compact_plain( $value );
		if ( '' !== $value ) {
			$street_values[] = $lower( $value );
		}
	}

	$skip       = brikpanel_orders_compact_plain( $skip );
	$skip_lower = '' !== $skip ? $lower( $skip ) : '';
	$skip_len   = '' !== $skip ? strlen( $skip_lower ) : 0;
	// "Name (Phone: …)", "Name, phone" and similar third-party decorations.
	$is_decorated_name = static function ( $line_lower ) use ( $skip_lower, $skip_len ) {
		return '' !== $skip_lower
			&& strlen( $line_lower ) > $skip_len
			&& 0 === strncmp( $line_lower, $skip_lower, $skip_len )
			&& 1 === preg_match( '/^[\s\p{P}]/u', substr( $line_lower, $skip_len ) );
	};

	$street_lines = array();
	$place_parts  = array();
	foreach ( preg_split( '#<br\s*/?>#i', (string) $formatted ) as $line ) {
		$line = brikpanel_orders_compact_plain( $line );
		if ( '' === $line ) {
			continue;
		}
		$line_lower = $lower( $line );
		if ( '' !== $skip_lower && $line_lower === $skip_lower ) {
			continue;
		}
		if ( in_array( $line_lower, $street_values, true ) ) {
			$street_lines[] = $line;
		} elseif ( ! $is_decorated_name( $line_lower ) ) {
			$place_parts[] = $line;
		}
	}

	if ( $place_parts ) {
		$street_lines[] = implode( ', ', $place_parts );
	}
	return $street_lines;
}

/**
 * Whether the current user sees WhatsApp shortcuts (checked once per request).
 *
 * @return bool
 */
function brikpanel_orders_compact_whatsapp_visible() {
	static $visible = null;
	if ( null === $visible ) {
		$visible = function_exists( 'brikpanel_whatsapp_visible_for_user' ) && brikpanel_whatsapp_visible_for_user();
	}
	return $visible;
}

/**
 * One item line: quantity, name and the options chosen (variation
 * attributes as plain values, any other visible meta as "Label: value").
 *
 * @param WC_Order_Item_Product $item Line item.
 * @return string HTML.
 */
function brikpanel_orders_compact_item_html( $item ) {
	$attribute_keys = array();
	$variation_id   = (int) $item->get_variation_id();
	if ( $variation_id && function_exists( 'wc_get_product_variation_attributes' ) ) {
		foreach ( array_keys( (array) wc_get_product_variation_attributes( $variation_id ) ) as $key ) {
			$attribute_keys[] = substr( $key, strlen( 'attribute_' ) );
		}
	}

	// Some themes and plugins put markup into variation titles, which then
	// ends up in the stored item name.
	$name = brikpanel_orders_compact_plain( $item->get_name() );

	$options = array();
	foreach ( $item->get_formatted_meta_data() as $meta ) {
		$value = brikpanel_orders_compact_plain( $meta->display_value );
		if ( '' === $value ) {
			continue;
		}
		if ( in_array( $meta->key, $attribute_keys, true ) || 0 === strpos( (string) $meta->key, 'pa_' ) ) {
			// WooCommerce already skips attributes that are part of the name,
			// but compares against the raw name, which markup can defeat.
			if ( function_exists( 'wc_is_attribute_in_product_name' ) && wc_is_attribute_in_product_name( $value, $name ) ) {
				continue;
			}
			$options[] = $value;
		} else {
			$label     = brikpanel_orders_compact_plain( $meta->display_key );
			$options[] = '' !== $label ? $label . ': ' . $value : $value;
		}
	}

	$html  = '<li><span class="bp-od-qty">' . esc_html( sprintf(
		/* translators: %s: item quantity. Shown before the product name, e.g. "2 ×". */
		__( '%s ×', 'brikpanel' ),
		wc_stock_amount( $item->get_quantity() )
	) ) . '</span><span class="bp-od-item">' . brikpanel_orders_compact_bdi( $name );
	if ( $options ) {
		$html .= '<span class="bp-od-options">' . brikpanel_orders_compact_bdi( implode( ' / ', $options ) ) . '</span>';
	}
	$html .= '</span></li>';
	return $html;
}

/**
 * Detail panel markup for one order, wrapped in an inert <template>.
 *
 * @param WC_Order $order Order.
 * @return string HTML.
 */
function brikpanel_orders_compact_detail_html( $order ) {
	$via = static function ( $method ) {
		/* translators: %s: payment or shipping method name. */
		return '<div class="bp-od-via">' . sprintf( esc_html__( 'via %s', 'brikpanel' ), brikpanel_orders_compact_bdi( $method ) ) . '</div>';
	};

	// Billing.
	$billing_name  = trim( (string) $order->get_formatted_billing_full_name() );
	$billing_lines = brikpanel_orders_compact_address_lines(
		$order->get_formatted_billing_address(),
		$billing_name,
		array( $order->get_billing_company(), $order->get_billing_address_1(), $order->get_billing_address_2() )
	);
	$billing       = '';
	if ( '' !== $billing_name ) {
		$billing .= '<div class="bp-od-name">' . brikpanel_orders_compact_bdi( $billing_name ) . '</div>';
	}
	foreach ( $billing_lines as $line ) {
		$billing .= '<div>' . brikpanel_orders_compact_bdi( $line ) . '</div>';
	}
	$email = (string) $order->get_billing_email();
	if ( '' !== $email ) {
		$billing .= '<div class="bp-od-contact"><a href="' . esc_url( 'mailto:' . $email ) . '">' . brikpanel_orders_compact_bdi( $email ) . '</a></div>';
	}
	$billing_phone = (string) $order->get_billing_phone();
	if ( '' !== $billing_phone ) {
		$billing .= '<div class="bp-od-contact">' . brikpanel_orders_compact_bdi( $billing_phone ) . '</div>';
	}
	if ( '' === $billing ) {
		$billing = '<div class="bp-order-muted">' . esc_html__( 'No billing address', 'brikpanel' ) . '</div>';
	}
	if ( $order->get_payment_method_title() ) {
		$billing .= $via( $order->get_payment_method_title() );
	}

	// Ship to.
	$shipping_method = (string) $order->get_shipping_method();
	$shipping_lines  = array();
	$shipping_name   = '';
	if ( $order->has_shipping_address() ) {
		$shipping_name  = trim( (string) $order->get_formatted_shipping_full_name() );
		$shipping_lines = brikpanel_orders_compact_address_lines(
			$order->get_formatted_shipping_address(),
			$shipping_name,
			array( $order->get_shipping_company(), $order->get_shipping_address_1(), $order->get_shipping_address_2() )
		);
		if ( '' === $shipping_name ) {
			$shipping_name = $billing_name;
		}
	}
	$shipping_phone = method_exists( $order, 'get_shipping_phone' ) ? (string) $order->get_shipping_phone() : '';
	if ( '' === $shipping_phone ) {
		$shipping_phone = $billing_phone;
	}
	$ship = '';
	if ( $shipping_lines ) {
		if ( '' !== $shipping_name ) {
			$ship .= '<div class="bp-od-name">' . brikpanel_orders_compact_bdi( $shipping_name ) . '</div>';
		}
		if ( '' !== $shipping_phone ) {
			/* translators: %s: phone number. */
			$ship .= '<div class="bp-od-contact">' . sprintf( esc_html__( '(Phone: %s)', 'brikpanel' ), brikpanel_orders_compact_bdi( $shipping_phone ) ) . '</div>';
		}
		foreach ( $shipping_lines as $line ) {
			$ship .= '<div>' . brikpanel_orders_compact_bdi( $line ) . '</div>';
		}
	} elseif ( '' !== $shipping_method ) {
		$ship .= '<div class="bp-order-muted">' . esc_html__( 'No shipping address', 'brikpanel' ) . '</div>';
	} else {
		$ship .= '<div class="bp-order-muted">' . esc_html__( 'No shipping needed', 'brikpanel' ) . '</div>';
	}
	if ( '' !== $shipping_method ) {
		$ship .= $via( $shipping_method );
	}

	// Items.
	$items     = $order->get_items( 'line_item' );
	$limit     = 10;
	$shown     = 0;
	$qty_total = 0;
	$list      = '';
	foreach ( $items as $item ) {
		$qty_total += (float) $item->get_quantity();
		if ( $shown < $limit ) {
			$list .= brikpanel_orders_compact_item_html( $item );
		}
		++$shown;
	}
	$hidden = count( $items ) - min( count( $items ), $limit );
	if ( $hidden > 0 ) {
		$list .= '<li class="bp-od-more">' . esc_html( sprintf(
			/* translators: %d: number of item lines not listed. */
			_n( '+ %d more item', '+ %d more items', $hidden, 'brikpanel' ),
			$hidden
		) ) . '</li>';
	}
	$items_label = sprintf(
		/* translators: %s: total quantity of items in the order. */
		__( 'Items (%s)', 'brikpanel' ),
		wc_stock_amount( $qty_total )
	);
	$items_html = '' !== $list
		? '<ul class="bp-od-items">' . $list . '</ul>'
		: '<div class="bp-order-muted">' . esc_html__( 'No items', 'brikpanel' ) . '</div>';

	$note = trim( (string) $order->get_customer_note() );
	if ( '' !== $note ) {
		$items_html .= '<div class="bp-od-note"><span class="bp-od-label">' . esc_html__( 'Customer note', 'brikpanel' ) . '</span>' . esc_html( wp_html_excerpt( $note, 280, '…' ) ) . '</div>';
	}

	// Actions.
	$actions = '<a class="bp-od-btn bp-od-btn-primary bp-open-order" href="' . esc_url( $order->get_edit_order_url() ) . '">' . esc_html__( 'Open order', 'brikpanel' ) . '</a>';
	$wa_url  = ( brikpanel_orders_compact_whatsapp_visible() && function_exists( 'brikpanel_order_whatsapp_url' ) ) ? brikpanel_order_whatsapp_url( $order ) : '';
	if ( '' !== $wa_url ) {
		$actions .= '<a class="bp-od-btn bp-od-btn-secondary bp-od-wa" href="' . brikpanel_whatsapp_esc_url( $wa_url ) . '" target="_blank" rel="noopener">'
			. ( function_exists( 'brikpanel_order_whatsapp_icon_svg' ) ? brikpanel_order_whatsapp_icon_svg( 16 ) : '' )
			. esc_html__( 'WhatsApp', 'brikpanel' ) . '</a>';
	}
	// WooCommerce's quick preview, which the short row no longer shows. Its
	// click handler is delegated on the document, so it works from here too.
	if ( 'trash' !== $order->get_status() ) {
		$actions .= '<button type="button" class="bp-od-btn bp-od-btn-secondary order-preview" data-order-id="' . absint( $order->get_id() ) . '">' . esc_html__( 'Preview', 'brikpanel' ) . '</button>';
	}

	return '<template class="bp-order-detail-tpl"><div class="bp-od">'
		. '<div class="bp-od-col"><div class="bp-od-label">' . esc_html__( 'Billing', 'brikpanel' ) . '</div>' . $billing . '</div>'
		. '<div class="bp-od-col"><div class="bp-od-label">' . esc_html__( 'Ship to', 'brikpanel' ) . '</div>' . $ship . '</div>'
		. '<div class="bp-od-col bp-od-col-items"><div class="bp-od-label">' . esc_html( $items_label ) . '</div>' . $items_html . '</div>'
		. '<div class="bp-od-actions">' . $actions . '</div>'
		. '</div></template>';
}
