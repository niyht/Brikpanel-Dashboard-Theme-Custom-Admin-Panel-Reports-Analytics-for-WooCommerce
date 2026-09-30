<?php
/**
 * Trakoo (Orders Tracking for WooCommerce) on the orders screens.
 *
 * Trakoo keeps a tracking number per order line item and edits it in a window
 * it only prints on the single order screen. Two things build on that here:
 *
 *   - the WhatsApp draft learns {tracking_number}, {carrier_name} and
 *     {tracking_url} (through brikpanel_whatsapp_order_tokens at priority 5, so
 *     a developer's own filter at 10 still has the last word), and
 *   - the compact orders list gets an "Add tracking number" button in each
 *     order's panel. It opens a small window of BrikPanel's own and saves
 *     through Trakoo's AJAX handler, so Trakoo's email, its "change the order
 *     status" option and the choices it remembers behave exactly as in its own
 *     window (front-end/orders/brikpanel-order-tracking.js).
 *
 * The button only exists where Trakoo's save handler is actually loaded: the
 * free plugin skips its admin code while its Premium edition is active. The
 * placeholders are read straight from the item meta, so they keep resolving
 * (to an empty value at worst) after Trakoo is switched off, and a saved draft
 * never sends "{tracking_number}" to a customer.
 *
 * @package BrikPanel
 * @since   3.3.25
 */

defined( 'ABSPATH' ) || exit;

// Where Trakoo 1.3 keeps an item's tracking history, and its orders list column.
const BRIKPANEL_TRAKOO_ITEM_META = '_vi_wot_order_item_tracking_data';
const BRIKPANEL_TRAKOO_COLUMN    = 'vi_wot_tracking_code';

add_filter( 'brikpanel_whatsapp_order_tokens', 'brikpanel_order_tracking_whatsapp_tokens', 5, 3 );
add_filter( 'brikpanel_whatsapp_order_placeholder_help', 'brikpanel_order_tracking_placeholder_help' );
add_action( 'admin_enqueue_scripts', 'brikpanel_order_tracking_enqueue', 100 );
add_action( 'wp_ajax_brikpanel_order_tracking_refresh', 'brikpanel_order_tracking_ajax_refresh' );

/**
 * Per-request memo shared by the helpers below.
 *
 * The orders list asks for every visible row, often twice (the WhatsApp icon
 * and the panel both build a draft), so answers are kept for the request.
 *
 * @return array Reference to the memo.
 */
function &brikpanel_order_tracking_memo() {
	static $memo = array();
	return $memo;
}

/**
 * Forget every remembered answer (after the current user or Trakoo's data
 * changed within one request, which only tests do).
 */
function brikpanel_order_tracking_reset() {
	$memo = &brikpanel_order_tracking_memo();
	$memo = array();
}

/**
 * Whether Trakoo is installed, whichever edition is running.
 *
 * Enough to offer the placeholders: they read the item meta, not Trakoo's code.
 *
 * @return bool
 */
function brikpanel_order_tracking_installed() {
	return defined( 'VI_WOO_ORDERS_TRACKING_VERSION' ) || brikpanel_order_tracking_available();
}

/**
 * Whether Trakoo's own save handler is loaded in this request.
 *
 * The free plugin defines its constants and data class even while its Premium
 * edition is active, and only then leaves out its admin code, including the
 * handler the button posts to. Trakoo registers it on plugins_loaded, before
 * BrikPanel's admin files run.
 *
 * @return bool
 */
function brikpanel_order_tracking_available() {
	$memo = &brikpanel_order_tracking_memo();
	if ( ! isset( $memo['available'] ) ) {
		$memo['available'] = class_exists( 'VI_WOO_ORDERS_TRACKING_ADMIN_ORDERS_EDIT_TRACKING', false )
			&& false !== has_action( 'wp_ajax_wotv_save_track_info_all_item' );
	}
	return $memo['available'];
}

/**
 * Whether the current user may add tracking numbers from the orders list.
 *
 * Checked once, not per order: on HPOS a per-order check loads a post per row.
 * Trakoo checks the order itself when it saves.
 *
 * @return bool
 */
function brikpanel_order_tracking_user_can() {
	$memo = &brikpanel_order_tracking_memo();
	if ( ! isset( $memo['can'] ) ) {
		$memo['can'] = current_user_can( 'edit_shop_orders' );
	}
	return $memo['can'];
}

/**
 * Trakoo's current entry of every line item of an order, one per number and
 * carrier, in item order.
 *
 * Trakoo keeps a history per item (a JSON list whose last entry is the one in
 * force) and writes the same number to every item when it is set for the whole
 * order. Simple and variable products are line items alike.
 *
 * @param WC_Order $order Order.
 * @return array<int, array{number:string, slug:string, name:string, url:string}>
 */
function brikpanel_order_tracking_entries( $order ) {
	if ( ! $order instanceof WC_Order ) {
		return array();
	}
	$memo = &brikpanel_order_tracking_memo();
	$id   = (int) $order->get_id();
	if ( isset( $memo['entries'][ $id ] ) ) {
		return $memo['entries'][ $id ];
	}

	$text    = static function ( $row, $key ) {
		return isset( $row[ $key ] ) && is_scalar( $row[ $key ] ) ? trim( (string) $row[ $key ] ) : '';
	};
	$entries = array();
	try {
		foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
			$raw = wc_get_order_item_meta( $item_id, BRIKPANEL_TRAKOO_ITEM_META, true );
			if ( ! is_string( $raw ) || '' === $raw ) {
				continue;
			}
			$history = json_decode( $raw, true, 512, JSON_INVALID_UTF8_SUBSTITUTE );
			if ( ! is_array( $history ) || ! $history ) {
				continue;
			}
			$last = end( $history );
			if ( ! is_array( $last ) ) {
				continue;
			}
			$entry = array(
				'number' => $text( $last, 'tracking_number' ),
				'slug'   => $text( $last, 'carrier_slug' ),
				'name'   => $text( $last, 'carrier_name' ),
				'url'    => $text( $last, 'carrier_url' ),
			);
			// Digital delivery keeps the carrier and no number; an entry with
			// neither is Trakoo's blank default.
			if ( '' === $entry['number'] && '' === $entry['slug'] ) {
				continue;
			}
			$key = $entry['number'] . "\0" . $entry['slug'];
			if ( ! isset( $entries[ $key ] ) ) {
				$entries[ $key ] = $entry;
			}
		}
	} catch ( Throwable $e ) {
		// A broken item costs the tracking details, not the orders screen.
		unset( $e );
	}

	$memo['entries'][ $id ] = array_values( $entries );
	return $memo['entries'][ $id ];
}

/**
 * The distinct tracking numbers of an order.
 *
 * @param WC_Order $order Order.
 * @return string[]
 */
function brikpanel_order_tracking_numbers( $order ) {
	$numbers = array();
	foreach ( brikpanel_order_tracking_entries( $order ) as $entry ) {
		if ( '' !== $entry['number'] ) {
			$numbers[ $entry['number'] ] = true;
		}
	}
	return array_map( 'strval', array_keys( $numbers ) );
}

/**
 * Trakoo's current definition of a carrier (its name and link can be edited
 * in Trakoo's settings after a number was saved).
 *
 * @param string $slug Carrier slug.
 * @return array|null Carrier with at least a name, or null.
 */
function brikpanel_order_tracking_carrier( $slug ) {
	$slug = (string) $slug;
	if ( '' === $slug || ! class_exists( 'VI_WOO_ORDERS_TRACKING_DATA' ) || ! is_callable( array( 'VI_WOO_ORDERS_TRACKING_DATA', 'get_instance' ) ) ) {
		return null;
	}
	$memo = &brikpanel_order_tracking_memo();
	if ( ! isset( $memo['carriers'] ) || ! array_key_exists( $slug, $memo['carriers'] ) ) {
		$carrier = null;
		try {
			// Decodes Trakoo's whole carrier list on every call, hence the memo.
			$found = VI_WOO_ORDERS_TRACKING_DATA::get_instance()->get_shipping_carrier_by_slug( $slug );
			if ( is_array( $found ) && isset( $found['name'] ) && is_scalar( $found['name'] ) && '' !== trim( (string) $found['name'] ) ) {
				$carrier = $found;
			}
		} catch ( Throwable $e ) {
			unset( $e );
		}
		$memo['carriers'][ $slug ] = $carrier;
	}
	return $memo['carriers'][ $slug ];
}

/**
 * Link a customer can follow for one tracking number.
 *
 * The same link Trakoo puts in its WooCommerce emails: the carrier's page, or
 * the store's own tracking page when Trakoo's tracking service is on, without
 * the admin's one-time key Trakoo adds for its own screens.
 *
 * @param string   $pattern Carrier link with {tracking_number} / {postal_code}.
 * @param string   $number  Tracking number.
 * @param string   $slug    Carrier slug.
 * @param WC_Order $order   Order.
 * @return string
 */
function brikpanel_order_tracking_url( $pattern, $number, $slug, $order ) {
	$postcode = (string) $order->get_shipping_postcode();
	$url      = '';
	try {
		if ( class_exists( 'VI_WOO_ORDERS_TRACKING_DATA' ) && is_callable( array( 'VI_WOO_ORDERS_TRACKING_DATA', 'get_instance' ) ) ) {
			$url = (string) VI_WOO_ORDERS_TRACKING_DATA::get_instance()->get_url_tracking( $pattern, $number, $slug, $postcode, false, false, $order->get_id() );
		} elseif ( '' !== $pattern ) {
			$url = str_replace( array( '{tracking_number}', '{postal_code}' ), array( rawurlencode( $number ), rawurlencode( $postcode ) ), $pattern );
		}
	} catch ( Throwable $e ) {
		$url = '';
	}
	return '' !== $url ? esc_url_raw( $url ) : '';
}

/**
 * The trackable shipments of an order: number, carrier name and link.
 *
 * Entries without a number (digital delivery) have nothing to track and are
 * left out.
 *
 * @param WC_Order $order Order.
 * @return array<int, array{number:string, carrier:string, url:string}>
 */
function brikpanel_order_tracking_shipments( $order ) {
	$shipments = array();
	foreach ( brikpanel_order_tracking_entries( $order ) as $entry ) {
		if ( '' === $entry['number'] ) {
			continue;
		}
		$name    = $entry['name'];
		$pattern = $entry['url'];
		$carrier = brikpanel_order_tracking_carrier( $entry['slug'] );
		if ( $carrier ) {
			$name    = (string) $carrier['name'];
			$pattern = isset( $carrier['url'] ) && is_scalar( $carrier['url'] ) ? (string) $carrier['url'] : $pattern;
		}
		$shipments[] = array(
			'number'  => $entry['number'],
			'carrier' => trim( html_entity_decode( wp_strip_all_tags( $name ), ENT_QUOTES, 'UTF-8' ) ),
			'url'     => brikpanel_order_tracking_url( $pattern, $entry['number'], $entry['slug'], $order ),
		);
	}
	return $shipments;
}

/**
 * Fill {tracking_number}, {carrier_name} and {tracking_url} in a WhatsApp draft.
 *
 * Only the tokens the template uses are looked up. Each one always resolves,
 * to an empty value when the order has no tracking yet, so the draft module
 * drops a line holding only that token instead of sending it as written.
 * Numbers are isolated left-to-right like the order number (an Arabic draft
 * reorders them otherwise); links stay bare so WhatsApp can recognise them,
 * one per line when an order ships in more than one parcel.
 *
 * @param array    $tokens   Token => plain-text value.
 * @param WC_Order $order    Order the draft is about.
 * @param string   $template Template being filled.
 * @return array
 */
function brikpanel_order_tracking_whatsapp_tokens( $tokens, $order, $template ) {
	if ( ! is_array( $tokens ) || ! $order instanceof WC_Order ) {
		return $tokens;
	}
	$template = (string) $template;
	$wanted   = array();
	foreach ( array( '{tracking_number}', '{carrier_name}', '{tracking_url}' ) as $token ) {
		if ( false !== strpos( $template, $token ) ) {
			$wanted[] = $token;
		}
	}
	if ( ! $wanted ) {
		return $tokens;
	}

	$numbers  = array();
	$carriers = array();
	$urls     = array();
	foreach ( brikpanel_order_tracking_shipments( $order ) as $shipment ) {
		$numbers[ $shipment['number'] ] = brikpanel_bidi_isolate_ltr( $shipment['number'] );
		if ( '' !== $shipment['carrier'] ) {
			$carriers[ $shipment['carrier'] ] = $shipment['carrier'];
		}
		if ( '' !== $shipment['url'] ) {
			$urls[ $shipment['url'] ] = $shipment['url'];
		}
	}
	$values = array(
		'{tracking_number}' => implode( ', ', $numbers ),
		'{carrier_name}'    => implode( ', ', $carriers ),
		'{tracking_url}'    => implode( "\n", $urls ),
	);
	foreach ( $wanted as $token ) {
		$tokens[ $token ] = $values[ $token ];
	}
	return $tokens;
}

/**
 * Offer the tracking placeholders in the WhatsApp settings while Trakoo is
 * installed; without it they would only ever be empty.
 *
 * @param array $help Token => description.
 * @return array
 */
function brikpanel_order_tracking_placeholder_help( $help ) {
	if ( ! is_array( $help ) || ! brikpanel_order_tracking_installed() ) {
		return $help;
	}
	$help['{tracking_number}'] = __( 'Tracking number', 'brikpanel' );
	$help['{carrier_name}']    = __( 'Shipping carrier', 'brikpanel' );
	$help['{tracking_url}']    = __( 'Tracking link', 'brikpanel' );
	return $help;
}

/**
 * The "Add tracking number" button for an order's panel on the compact list.
 *
 * Left out for trashed orders and for orders without products: Trakoo stores
 * numbers on product lines, and on an order without any it would still apply
 * the status change while saving nothing.
 *
 * @param WC_Order $order Order.
 * @return string HTML, or '' when the button does not apply.
 */
function brikpanel_order_tracking_button_html( $order ) {
	if ( ! $order instanceof WC_Order || 'trash' === $order->get_status() || ! brikpanel_order_tracking_available() || ! brikpanel_order_tracking_user_can() ) {
		return '';
	}
	if ( ! $order->get_items( 'line_item' ) ) {
		return '';
	}

	$entries = brikpanel_order_tracking_entries( $order );
	$numbers = brikpanel_order_tracking_numbers( $order );
	$single  = 1 === count( $numbers ) ? $numbers[0] : '';
	$carrier = '';
	foreach ( $entries as $entry ) {
		if ( ( '' !== $single && $entry['number'] === $single ) || ( ! $numbers && '' !== $entry['slug'] ) ) {
			$carrier = $entry['slug'];
			break;
		}
	}

	return sprintf(
		'<button type="button" class="bp-od-btn bp-od-btn-secondary bp-od-track" aria-haspopup="dialog" data-order-id="%1$d" data-order-number="%2$s" data-number="%3$s" data-carrier="%4$s" data-count="%5$d" data-has="%6$s">%7$s</button>',
		absint( $order->get_id() ),
		esc_attr( (string) $order->get_order_number() ),
		esc_attr( $single ),
		esc_attr( $carrier ),
		count( $numbers ),
		$entries ? '1' : '',
		$entries ? esc_html__( 'Edit tracking number', 'brikpanel' ) : esc_html__( 'Add tracking number', 'brikpanel' )
	);
}

/**
 * Trakoo's tracking cell of the orders list for one order, rendered the way
 * the list renders it, so the row can show a new number without a reload.
 *
 * The column hooks are fired as the list table fires them for the order store
 * in use. Only the column key is passed (never one from the request), and the
 * output goes through wp_kses_post().
 *
 * @param WC_Order $order Order.
 * @return string|null HTML, or null when rendering failed.
 */
function brikpanel_order_tracking_column_html( $order ) {
	if ( ! $order instanceof WC_Order ) {
		return null;
	}
	$level = ob_get_level();
	$html  = null;
	ob_start();
	try {
		if ( get_option( 'woocommerce_custom_orders_table_enabled' ) === 'yes' ) {
			$screen_id = function_exists( 'wc_get_page_screen_id' ) ? wc_get_page_screen_id( 'shop-order' ) : 'woocommerce_page_wc-orders';
			do_action( 'woocommerce_shop_order_list_table_custom_column', BRIKPANEL_TRAKOO_COLUMN, $order );
			do_action( "manage_{$screen_id}_custom_column", BRIKPANEL_TRAKOO_COLUMN, $order );
		} else {
			global $post;
			$previous = $post;
			// The posts list sets the global for its column hooks; put back below.
			$post = get_post( $order->get_id() ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			try {
				do_action( 'manage_posts_custom_column', BRIKPANEL_TRAKOO_COLUMN, $order->get_id() );
				do_action( 'manage_shop_order_posts_custom_column', BRIKPANEL_TRAKOO_COLUMN, $order->get_id() );
			} finally {
				$post = $previous; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			}
		}
		$html = (string) ob_get_contents();
	} catch ( Throwable $e ) {
		$html = null;
	}
	while ( ob_get_level() > $level ) {
		ob_end_clean();
	}
	return null === $html ? null : wp_kses_post( $html );
}

/**
 * What the orders list needs after a tracking number was saved for an order.
 *
 * @param WC_Order $order Order.
 * @return array
 */
function brikpanel_order_tracking_refresh_payload( $order ) {
	$entries = brikpanel_order_tracking_entries( $order );
	$numbers = brikpanel_order_tracking_numbers( $order );
	$single  = 1 === count( $numbers ) ? $numbers[0] : '';
	$carrier = '';
	foreach ( $entries as $entry ) {
		if ( ( '' !== $single && $entry['number'] === $single ) || ( ! $numbers && '' !== $entry['slug'] ) ) {
			$carrier = $entry['slug'];
			break;
		}
	}

	// Only the shape the WhatsApp module builds; a link a filter rewrote into
	// something else is left out, and the page keeps the link it has.
	$whatsapp = function_exists( 'brikpanel_whatsapp_order_state' ) ? brikpanel_whatsapp_order_state( $order ) : array();

	$status = (string) $order->get_status();
	return array(
		'tracking'          => array(
			'has'     => (bool) $entries,
			'number'  => $single,
			'carrier' => $carrier,
			'count'   => count( $numbers ),
		),
		'column'            => brikpanel_order_tracking_column_html( $order ),
		'whatsapp'          => isset( $whatsapp['whatsapp'] ) ? $whatsapp['whatsapp'] : '',
		// Trakoo can change the status, which drops a noted WhatsApp press.
		'whatsapp_followup' => ! empty( $whatsapp['whatsapp_followup'] ),
		'status'            => array(
			'slug'  => sanitize_html_class( $status ),
			'label' => function_exists( 'wc_get_order_status_name' ) ? wp_strip_all_tags( (string) wc_get_order_status_name( $status ) ) : $status,
		),
	);
}

/**
 * AJAX: the row's new state after a save (see the payload above).
 */
function brikpanel_order_tracking_ajax_refresh() {
	check_ajax_referer( 'brikpanel_order_tracking', 'nonce' );
	$order_id = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;
	if ( ! $order_id || ! current_user_can( 'edit_shop_orders' ) || ! current_user_can( 'edit_shop_order', $order_id ) ) {
		wp_send_json_error( null, 403 );
	}
	$order = wc_get_order( $order_id );
	// Refunds are orders of their own type; they have no tracking.
	if ( ! $order instanceof WC_Order ) {
		wp_send_json_error( null, 404 );
	}
	wp_send_json_success( brikpanel_order_tracking_refresh_payload( $order ) );
}

/**
 * Trakoo's carriers for the window's select, sorted by name.
 *
 * Each row is [slug, name, digital delivery, shown]. "Shown" follows Trakoo's
 * own window: only the carriers ticked in its settings, or all of them when
 * none is ticked. The window still adds an order's current carrier.
 *
 * @return array<int, array{0:string, 1:string, 2:int, 3:int}>
 */
function brikpanel_order_tracking_carriers_for_js() {
	$rows = array();
	try {
		if ( ! class_exists( 'VI_WOO_ORDERS_TRACKING_DATA' ) || ! is_callable( array( 'VI_WOO_ORDERS_TRACKING_DATA', 'get_carriers' ) ) ) {
			return $rows;
		}
		$active = VI_WOO_ORDERS_TRACKING_DATA::get_instance()->get_params( 'active_carriers' );
		$active = is_array( $active ) ? array_map( 'strval', $active ) : array();
		foreach ( (array) VI_WOO_ORDERS_TRACKING_DATA::get_carriers() as $carrier ) {
			if ( ! is_array( $carrier ) || empty( $carrier['slug'] ) || ! is_scalar( $carrier['slug'] ) || empty( $carrier['name'] ) || ! is_scalar( $carrier['name'] ) ) {
				continue;
			}
			$slug   = (string) $carrier['slug'];
			$name   = trim( html_entity_decode( wp_strip_all_tags( (string) $carrier['name'] ), ENT_QUOTES, 'UTF-8' ) );
			$rows[] = array(
				$slug,
				'' !== $name ? $name : $slug,
				isset( $carrier['digital_delivery'] ) && 1 === (int) $carrier['digital_delivery'] ? 1 : 0,
				! $active || in_array( $slug, $active, true ) ? 1 : 0,
			);
		}
	} catch ( Throwable $e ) {
		return array();
	}
	usort(
		$rows,
		static function ( $a, $b ) {
			return strcasecmp( $a[1], $b[1] );
		}
	);
	return $rows;
}

/**
 * Order statuses the window offers, as [key, label] with WooCommerce's "wc-"
 * prefix, which Trakoo's handler expects and strips without checking.
 *
 * Statuses that undo or abandon an order are left out: saving a tracking
 * number with "Refunded" would make WooCommerce record a refund.
 *
 * @return array<int, array{0:string, 1:string}>
 */
function brikpanel_order_tracking_statuses_for_js() {
	$skip = array( 'wc-pending', 'wc-failed', 'wc-cancelled', 'wc-refunded', 'wc-checkout-draft' );
	$rows = array();
	foreach ( wc_get_order_statuses() as $key => $label ) {
		$key = (string) $key;
		if ( 0 !== strpos( $key, 'wc-' ) || in_array( $key, $skip, true ) ) {
			continue;
		}
		$rows[] = array( $key, wp_strip_all_tags( (string) $label ) );
	}
	return $rows;
}

/**
 * Load the window on the compact orders list, and the WhatsApp link refresh on
 * the single order screen.
 *
 * Runs after brikpanel_enqueue_woo_assets() (priority 99), so the orders list
 * script it depends on is already queued.
 */
function brikpanel_order_tracking_enqueue() {
	if ( ! brikpanel_order_tracking_available() || ! brikpanel_order_tracking_user_can() ) {
		return;
	}
	$js_ver = @filemtime( BRIKPANEL_PATH . 'front-end/orders/brikpanel-order-tracking.js' ) ?: BRIKPANEL_VERSION; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

	$is_list = function_exists( 'brikpanel_orders_compact_is_list_screen' ) && brikpanel_orders_compact_is_list_screen()
		&& function_exists( 'brikpanel_orders_compact_enabled' ) && brikpanel_orders_compact_enabled()
		&& wp_script_is( 'brikpanel_orders_scripts', 'enqueued' );

	if ( $is_list ) {
		$css_ver = @filemtime( BRIKPANEL_PATH . 'front-end/orders/brikpanel-order-tracking.css' ) ?: BRIKPANEL_VERSION; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		wp_enqueue_style(
			'brikpanel_order_tracking',
			BRIKPANEL_URL . 'front-end/orders/brikpanel-order-tracking.css',
			array_merge( array( 'brikpanel_orders_styles' ), function_exists( 'brikpanel_narrow_dep' ) ? brikpanel_narrow_dep( 'ui', 'style' ) : array() ),
			$css_ver
		);
		wp_enqueue_script( 'brikpanel_order_tracking', BRIKPANEL_URL . 'front-end/orders/brikpanel-order-tracking.js', array(), $js_ver, true );

		$settings = VI_WOO_ORDERS_TRACKING_DATA::get_instance();
		$statuses = brikpanel_order_tracking_statuses_for_js();
		$status   = (string) $settings->get_params( 'order_status' );
		wp_localize_script(
			'brikpanel_order_tracking',
			'brikpanelOrderTracking',
			array(
				'screen'          => 'list',
				'ajax_url'        => admin_url( 'admin-ajax.php' ),
				'nonce'           => wp_create_nonce( 'brikpanel_order_tracking' ),
				// Trakoo prints its key only on the single order screen; the
				// window posts to its handler from the list, as the same user.
				'trakoo_nonce'    => wp_create_nonce( 'vi_wot_item_action_nonce' ),
				'column'          => BRIKPANEL_TRAKOO_COLUMN,
				'carriers'        => brikpanel_order_tracking_carriers_for_js(),
				'default_carrier' => (string) $settings->get_params( 'shipping_carrier_default' ),
				'statuses'        => $statuses,
				// Trakoo remembers the last choice of its own window; so does this one.
				'default_status'  => in_array( $status, wp_list_pluck( $statuses, 0 ), true ) ? $status : '',
				'email_default'   => (bool) $settings->get_params( 'email_enable' ),
				'i18n'            => array(
					'add'          => __( 'Add tracking number', 'brikpanel' ),
					'edit'         => __( 'Edit tracking number', 'brikpanel' ),
					/* translators: %s: order number. */
					'order'        => __( 'Order #%s', 'brikpanel' ),
					'number'       => __( 'Tracking number', 'brikpanel' ),
					'carrier'      => __( 'Shipping carrier', 'brikpanel' ),
					'choose'       => __( 'Choose a carrier', 'brikpanel' ),
					'status'       => __( 'Change order status to', 'brikpanel' ),
					'no_change'    => __( 'Do not change', 'brikpanel' ),
					'email'        => __( 'Email the tracking details to the customer', 'brikpanel' ),
					'several'      => __( 'The items in this order have different tracking numbers. Saving puts this one on every item.', 'brikpanel' ),
					'digital'      => __( 'This carrier needs no tracking number.', 'brikpanel' ),
					'need_number'  => __( 'Enter a tracking number.', 'brikpanel' ),
					'save'         => __( 'Save', 'brikpanel' ),
					'saving'       => __( 'Saving…', 'brikpanel' ),
					'cancel'       => __( 'Cancel', 'brikpanel' ),
					'close'        => __( 'Close', 'brikpanel' ),
					'saved'        => __( 'Tracking number saved.', 'brikpanel' ),
					'saved_reload' => __( 'Saved. Reload the page to see the change.', 'brikpanel' ),
					'failed'       => __( 'Could not save. Please try again.', 'brikpanel' ),
					'expired'      => __( 'Your session expired. Reload the page and try again.', 'brikpanel' ),
				),
			)
		);
		return;
	}

	// The single order screen: Trakoo saves from its own window there, and the
	// WhatsApp buttons were drawn before the number existed.
	$context = function_exists( 'brikpanel_order_screen_context' ) ? brikpanel_order_screen_context() : null;
	if ( ! $context || ! empty( $context['new'] ) || empty( $context['order_id'] ) ) {
		return;
	}
	if ( ! function_exists( 'brikpanel_whatsapp_visible_for_user' ) || ! brikpanel_whatsapp_visible_for_user() ) {
		return;
	}
	wp_enqueue_script( 'brikpanel_order_tracking', BRIKPANEL_URL . 'front-end/orders/brikpanel-order-tracking.js', array( 'jquery' ), $js_ver, true );
	wp_localize_script(
		'brikpanel_order_tracking',
		'brikpanelOrderTracking',
		array(
			'screen'   => 'edit',
			'ajax_url' => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( 'brikpanel_order_tracking' ),
			'order_id' => (int) $context['order_id'],
		)
	);
}
