<?php
/**
 * BrikPanel — Order Custom Fields panel.
 *
 * Surfaces a chosen list of order meta ("custom fields") as a clean, read-only
 * card on the single-order edit screen. The store owner picks which meta keys
 * to show from an auto-detected list (extra checkout fields, third-party meta,
 * …) and may add further keys / friendly labels by hand. Works on both the HPOS
 * order screen and the legacy `shop_order` post screen, and for any order type.
 *
 * Nothing is shown until at least one configured field actually has a value on
 * the order being viewed, so the card never appears empty.
 *
 * @package BrikPanel
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// =============================================================================
// OPTION KEYS
// =============================================================================
const BRIKPANEL_ORDER_FIELDS_OPT_KEYS   = 'brikpanel_order_fields_keys';   // multiselect of meta keys
const BRIKPANEL_ORDER_FIELDS_OPT_MANUAL = 'brikpanel_order_fields_manual'; // textarea: key | Label per line

// =============================================================================
// META-KEY HELPERS
// =============================================================================

/**
 * Turn a raw meta key into a human-friendly default label.
 * `_billing_vat_number` → "Billing Vat Number".
 *
 * @param string $key
 * @return string
 */
function brikpanel_order_fields_humanize( $key ) {
	$label = ltrim( (string) $key, '_' );
	$label = str_replace( [ '_', '-' ], ' ', $label );
	$label = trim( preg_replace( '/\s+/', ' ', $label ) );
	return $label !== '' ? ucwords( $label ) : (string) $key;
}

/**
 * WooCommerce / WordPress core bookkeeping meta keys that are never useful as a
 * "custom field" and would only add noise to the picker. Custom and third-party
 * keys (including custom `_billing_*` checkout fields) are intentionally kept.
 *
 * @param string $key
 * @return bool True when the key should be excluded from the auto-detected list.
 */
function brikpanel_order_fields_is_internal_key( $key ) {
	$key = (string) $key;

	$exact = [
		'_order_key', '_order_currency', '_order_version', '_order_total',
		'_order_tax', '_order_shipping', '_order_shipping_tax',
		'_cart_discount', '_cart_discount_tax', '_prices_include_tax',
		'_payment_method', '_payment_method_title', '_transaction_id',
		'_customer_user', '_customer_ip_address', '_customer_user_agent',
		'_created_via', '_date_completed', '_date_paid', '_paid_date',
		'_completed_date', '_cart_hash', '_order_stock_reduced',
		'_recorded_sales', '_recorded_coupon_usage_counts',
		'_download_permissions_granted', '_new_order_email_sent',
		'_billing_address_index', '_shipping_address_index',
		'_edit_lock', '_edit_last', '_billing_email', '_billing_phone',
		'_billing_first_name', '_billing_last_name', '_billing_company',
		'_billing_address_1', '_billing_address_2', '_billing_city',
		'_billing_state', '_billing_postcode', '_billing_country',
		'_shipping_first_name', '_shipping_last_name', '_shipping_company',
		'_shipping_address_1', '_shipping_address_2', '_shipping_city',
		'_shipping_state', '_shipping_postcode', '_shipping_country',
		'_shipping_phone',
	];
	if ( in_array( $key, $exact, true ) ) {
		return true;
	}

	// BrikPanel's own merge bookkeeping. `_brikpanel_merge_pending` only exists
	// while a merge is half-finished and holds an id list plus a timestamp, so it
	// is noise in the picker and gibberish on the card. The merge result keys
	// (`_brikpanel_merged_into`, `_brikpanel_merged_from_numbers`) are left
	// selectable on purpose: a merchant may well want them on the order.
	if ( '_brikpanel_merge_pending' === $key || '_brikpanel_merged_from' === $key ) {
		return true;
	}

	$prefixes = [ '_wc_order_attribution', '_wp_', '_woocommerce_', '_wcpay', '_stripe_' ];
	foreach ( $prefixes as $prefix ) {
		if ( strpos( $key, $prefix ) === 0 ) {
			return true;
		}
	}
	return false;
}

/**
 * Auto-detect distinct order meta keys for the settings picker, excluding core
 * bookkeeping keys. Cached for an hour so the settings page stays fast on stores
 * with many orders. Reads the HPOS meta table when present, the post-meta table
 * otherwise.
 *
 * @return array<string,string> meta_key => default label.
 */
function brikpanel_order_fields_detect_keys() {
	$cached = get_transient( 'brikpanel_order_meta_keys' );
	if ( is_array( $cached ) ) {
		return $cached;
	}

	global $wpdb;
	$rows       = [];
	$hpos_table = $wpdb->prefix . 'wc_orders_meta';
	$has_hpos   = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $hpos_table ) ) === $hpos_table; // phpcs:ignore WordPress.DB

	if ( $has_hpos ) {
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_col( "SELECT DISTINCT meta_key FROM {$hpos_table} ORDER BY meta_key LIMIT 400" );
	} else {
		$rows = $wpdb->get_col( $wpdb->prepare(
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"SELECT DISTINCT pm.meta_key FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE p.post_type = %s ORDER BY pm.meta_key LIMIT 400",
			'shop_order'
		) );
	}

	$out = [];
	foreach ( (array) $rows as $key ) {
		if ( ! is_string( $key ) || $key === '' ) {
			continue;
		}
		if ( brikpanel_order_fields_is_internal_key( $key ) ) {
			continue;
		}
		$out[ $key ] = brikpanel_order_fields_humanize( $key );
	}

	set_transient( 'brikpanel_order_meta_keys', $out, HOUR_IN_SECONDS );
	return $out;
}

/**
 * Resolve the final ordered list of fields to display: the picked meta keys
 * first, then any manual entries (which may add new keys and/or override
 * labels).
 *
 * @return array<string,string> meta_key => label, in display order.
 */
function brikpanel_order_fields_config() {
	$out = [];

	$selected = get_option( BRIKPANEL_ORDER_FIELDS_OPT_KEYS, [] );
	if ( is_array( $selected ) ) {
		foreach ( $selected as $key ) {
			$key = trim( (string) $key );
			if ( $key !== '' && ! isset( $out[ $key ] ) ) {
				$out[ $key ] = brikpanel_order_fields_humanize( $key );
			}
		}
	}

	$manual = (string) get_option( BRIKPANEL_ORDER_FIELDS_OPT_MANUAL, '' );
	foreach ( preg_split( '/\r\n|\r|\n/', $manual ) as $line ) {
		$line = trim( (string) $line );
		if ( $line === '' ) {
			continue;
		}
		$label = '';
		if ( strpos( $line, '|' ) !== false ) {
			list( $key, $label ) = array_map( 'trim', explode( '|', $line, 2 ) );
		} else {
			$key = $line;
		}
		if ( $key === '' ) {
			continue;
		}
		if ( $label !== '' ) {
			$out[ $key ] = $label;
		} elseif ( ! isset( $out[ $key ] ) ) {
			$out[ $key ] = brikpanel_order_fields_humanize( $key );
		}
	}

	return $out;
}

/**
 * Collect the displayable rows (label + stringified value) for one order,
 * skipping any configured field with no value on that order.
 *
 * @param WC_Abstract_Order   $order
 * @param array<string,string> $config meta_key => label.
 * @return array<int,array{label:string,value:string}>
 */
function brikpanel_order_fields_collect_rows( $order, $config ) {
	$rows = [];
	foreach ( $config as $key => $label ) {
		$value = $order->get_meta( $key, true );
		if ( $value === '' || $value === null ) {
			continue;
		}
		if ( is_array( $value ) ) {
			$flat = [];
			array_walk_recursive( $value, static function ( $v ) use ( &$flat ) {
				if ( ! is_object( $v ) ) {
					$flat[] = (string) $v;
				}
			} );
			$value = implode( ', ', array_filter( $flat, static function ( $v ) {
				return trim( $v ) !== '';
			} ) );
		} elseif ( is_object( $value ) ) {
			continue;
		}
		$value = trim( (string) $value );
		if ( $value === '' ) {
			continue;
		}
		$rows[] = [
			'label' => (string) $label,
			'value' => $value,
		];
	}
	return $rows;
}

/**
 * Resolve a WC order from whatever the meta-box hook hands us (WP_Post on the
 * legacy screen, the order object on HPOS) with a request-id fallback.
 *
 * @param mixed $context
 * @return WC_Abstract_Order|null
 */
function brikpanel_order_fields_resolve_order( $context ) {
	if ( $context instanceof WC_Abstract_Order ) {
		return $context;
	}
	if ( $context instanceof WP_Post && function_exists( 'wc_get_order' ) ) {
		$order = wc_get_order( $context->ID );
		return $order instanceof WC_Abstract_Order ? $order : null;
	}
	if ( isset( $_GET['id'] ) && function_exists( 'wc_get_order' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$order = wc_get_order( absint( wp_unslash( $_GET['id'] ) ) );
		return $order instanceof WC_Abstract_Order ? $order : null;
	}
	if ( isset( $_GET['post'] ) && function_exists( 'wc_get_order' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$order = wc_get_order( absint( wp_unslash( $_GET['post'] ) ) );
		return $order instanceof WC_Abstract_Order ? $order : null;
	}
	return null;
}

// =============================================================================
// META BOX
// =============================================================================

/**
 * Register the panel on the single-order screen, but only when there is
 * something to show for this order.
 *
 * @param string $screen_or_type Screen id (HPOS) or post type (legacy).
 * @param mixed  $context        Order object (HPOS) or WP_Post (legacy).
 */
function brikpanel_order_fields_register_metabox( $screen_or_type, $context ) {
	$config = brikpanel_order_fields_config();
	if ( empty( $config ) ) {
		return;
	}
	$order = brikpanel_order_fields_resolve_order( $context );
	if ( ! $order ) {
		return;
	}
	if ( empty( brikpanel_order_fields_collect_rows( $order, $config ) ) ) {
		return;
	}

	add_meta_box(
		'brikpanel_order_fields',
		__( 'Additional details', 'brikpanel' ),
		'brikpanel_order_fields_render_metabox',
		$screen_or_type,
		'side',
		'high'
	);
}
add_action( 'add_meta_boxes', 'brikpanel_order_fields_register_metabox', 40, 2 );

/**
 * Render the panel body.
 *
 * @param mixed $context Order object (HPOS) or WP_Post (legacy).
 */
function brikpanel_order_fields_render_metabox( $context ) {
	$order = brikpanel_order_fields_resolve_order( $context );
	if ( ! $order ) {
		return;
	}
	$rows = brikpanel_order_fields_collect_rows( $order, brikpanel_order_fields_config() );
	if ( empty( $rows ) ) {
		echo '<p class="brikpanel-of-empty">' . esc_html__( 'No additional details for this order.', 'brikpanel' ) . '</p>';
		return;
	}
	echo '<div class="brikpanel-of-list">';
	foreach ( $rows as $row ) {
		echo '<div class="brikpanel-of-row">';
		echo '<span class="brikpanel-of-label">' . esc_html( $row['label'] ) . '</span>';
		echo '<span class="brikpanel-of-value">' . nl2br( esc_html( $row['value'] ) ) . '</span>';
		echo '</div>';
	}
	echo '</div>';
}

// =============================================================================
// ASSETS
// =============================================================================

/**
 * Whether the current admin screen is a single-order edit screen.
 *
 * @return bool
 */
function brikpanel_order_fields_is_edit_screen() {
	if ( ! function_exists( 'get_current_screen' ) ) {
		return false;
	}
	$screen = get_current_screen();
	if ( ! $screen ) {
		return false;
	}
	$hpos = function_exists( 'wc_get_page_screen_id' ) ? wc_get_page_screen_id( 'shop-order' ) : 'woocommerce_page_wc-orders';
	return in_array( $screen->id, [ $hpos, 'shop_order' ], true );
}

add_action( 'admin_enqueue_scripts', function () {
	if ( ! brikpanel_order_fields_is_edit_screen() ) {
		return;
	}
	if ( empty( brikpanel_order_fields_config() ) ) {
		return;
	}
	$ver = @filemtime( BRIKPANEL_PATH . 'front-end/order/brikpanel-order-fields.css' ) ?: BRIKPANEL_VERSION;
	wp_enqueue_style(
		'brikpanel-order-fields',
		BRIKPANEL_URL . 'front-end/order/brikpanel-order-fields.css',
		[],
		$ver
	);
} );

// =============================================================================
// SETTINGS (Orders section)
// =============================================================================

/**
 * Map the panel's settings title row to the Orders sub-section.
 */
add_filter( 'brikpanel_settings_title_section_map', function ( $map ) {
	if ( is_array( $map ) ) {
		$map['brk_order_fields_title'] = 'orders';
	}
	return $map;
} );

/**
 * Register the settings fields.
 */
add_filter( 'brikpanel_settings_fields', function ( $fields ) {
	if ( ! is_array( $fields ) ) {
		return $fields;
	}

	$detected = brikpanel_order_fields_detect_keys();

	$block = [
		[
			'name' => __( 'Order custom fields', 'brikpanel' ),
			'type' => 'title',
			'id'   => 'brk_order_fields_title',
			'desc' => __( 'Show a chosen list of order custom fields (extra checkout fields and other order meta) as a clean "Additional details" card on the order page. The card only appears for orders that actually have one of these fields filled in.', 'brikpanel' ),
		],
		[
			'name'     => __( 'Fields to display', 'brikpanel' ),
			'id'       => BRIKPANEL_ORDER_FIELDS_OPT_KEYS,
			'type'     => 'multiselect',
			'class'    => 'wc-enhanced-select',
			'desc'     => __( 'Pick which order custom fields to show. The list is detected automatically from your existing orders. Leave empty to show none.', 'brikpanel' ),
			'desc_tip' => true,
			'options'  => $detected,
			'default'  => [],
		],
		[
			'name'        => __( 'Additional fields (advanced)', 'brikpanel' ),
			'id'          => BRIKPANEL_ORDER_FIELDS_OPT_MANUAL,
			'type'        => 'textarea',
			'css'         => 'min-height:90px;width:100%;max-width:520px;',
			'desc'        => __( 'Add custom fields by hand when a key is not in the list above, or to set a friendly label. One field per line, as the meta key, optionally followed by a vertical bar and a label. Example: _billing_vat_number | VAT number', 'brikpanel' ),
			'desc_tip'    => false,
			'placeholder' => "_billing_vat_number | VAT number",
			'default'     => '',
		],
		[
			'type' => 'sectionend',
			'id'   => 'brk_order_fields_title',
		],
	];

	return array_merge( $fields, $block );
}, 8 );

/**
 * Refresh the auto-detected key cache whenever BrikPanel settings are saved, so
 * a newly-added field shows up in the picker without an hour's wait.
 */
add_action( 'woocommerce_update_options_brikpanel', function () {
	delete_transient( 'brikpanel_order_meta_keys' );
}, 20 );
