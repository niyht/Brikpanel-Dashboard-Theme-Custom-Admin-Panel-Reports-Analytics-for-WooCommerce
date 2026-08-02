<?php
/**
 * BrikPanel — WhatsApp quick-contact for orders
 *
 * Adds a one-click WhatsApp shortcut wherever a store manager fulfils orders:
 *   - a small WhatsApp icon in front of the order number on the Orders list
 *     (its own column, so Screen Options can hide it), and
 *   - a "Message on WhatsApp" button on the single order edit screen, right
 *     under the billing address.
 *
 * Clicking opens https://wa.me/<number> — WhatsApp Web on desktop, the app on
 * mobile — pre-targeting the customer's billing phone from that order. The
 * number is normalised to the international digits WhatsApp expects: any
 * non-digits are stripped and, when the customer typed a local number, the
 * order's billing-country dialing code is prepended so the chat actually opens.
 *
 * Works for HPOS and the legacy posts screen, and for simple and variable
 * product orders alike (the phone lives on the order, not the products).
 *
 * @package BrikPanel
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// =============================================================================
// MODULE SWITCH + ROLE SCOPING
// =============================================================================
//
// The WhatsApp shortcut is opt-out per store and opt-out per role, mirroring the
// Access control options (e.g. brikpanel_orders_overview_hidden_roles):
//
//   brikpanel_whatsapp_enabled       yes/no, default 'yes' — global on/off.
//   brikpanel_whatsapp_hidden_roles  array of role slugs   — roles that do NOT
//                                                            see the shortcut.
//   brikpanel_whatsapp_optin (user meta) 'yes' — a per-user override that lets a
//     user whose role is hidden switch the shortcut back on for their own account
//     (the common ask: hide it from administrators by default, but let an admin
//     re-enable it just for themselves from their profile page).
//
// Defaults keep existing behaviour identical: the global switch is on and no role
// is hidden, so every user with WooCommerce's `manage_woocommerce` capability
// still sees the shortcut exactly as before.

const BRIKPANEL_WHATSAPP_OPT_ENABLED      = 'brikpanel_whatsapp_enabled';
const BRIKPANEL_WHATSAPP_OPT_HIDDEN_ROLES = 'brikpanel_whatsapp_hidden_roles';
const BRIKPANEL_WHATSAPP_USER_OPTIN_META  = 'brikpanel_whatsapp_optin';

/**
 * Whether the WhatsApp order module is switched on store-wide.
 *
 * A missing option counts as on, so a fresh install behaves exactly as before.
 *
 * @return bool
 */
function brikpanel_whatsapp_module_enabled() {
	return get_option( BRIKPANEL_WHATSAPP_OPT_ENABLED, 'yes' ) !== 'no';
}

/**
 * Whether a given user's role is currently in the "hidden roles" list.
 *
 * @param WP_User|null $user
 * @return bool
 */
function brikpanel_whatsapp_user_is_hidden_by_role( $user ) {
	if ( ! $user instanceof WP_User || ! $user->ID ) {
		return false;
	}
	$hidden_roles = array_map( 'strval', (array) get_option( BRIKPANEL_WHATSAPP_OPT_HIDDEN_ROLES, [] ) );
	if ( ! $hidden_roles ) {
		return false;
	}
	return (bool) array_intersect( array_map( 'strval', (array) $user->roles ), $hidden_roles );
}

/**
 * Whether the WhatsApp shortcut should render for a user.
 *
 * Resolution (any earlier rule wins):
 *   1. Global switch off  → hidden for everyone.
 *   2. No resolvable user → hidden (front-end, cron, logged-out).
 *   3. User lacks `manage_woocommerce` → hidden (the baseline this feature has
 *      always honoured — only order handlers ever saw it).
 *   4. User's role is in the hidden-roles list → hidden, UNLESS the user has
 *      opted back in for their own account (per-user meta).
 *   5. Otherwise → shown.
 *
 * @param WP_User|null $user Optional user; defaults to the current user.
 * @return bool
 */
function brikpanel_whatsapp_visible_for_user( $user = null ) {
	if ( ! brikpanel_whatsapp_module_enabled() ) {
		return false;
	}
	if ( ! function_exists( 'wp_get_current_user' ) ) {
		return false;
	}
	if ( null === $user ) {
		$user = wp_get_current_user();
	}
	if ( ! $user instanceof WP_User || ! $user->ID ) {
		return false;
	}

	// Baseline WooCommerce order-management capability — unchanged from before.
	if ( ! user_can( $user, 'manage_woocommerce' ) ) {
		return false;
	}

	$visible = true;
	if ( brikpanel_whatsapp_user_is_hidden_by_role( $user ) ) {
		// Hidden by role, unless this user re-enabled it for their own account.
		$visible = get_user_meta( $user->ID, BRIKPANEL_WHATSAPP_USER_OPTIN_META, true ) === 'yes';
	}

	/**
	 * Filter the final per-user visibility of the WhatsApp order shortcut.
	 *
	 * @param bool    $visible
	 * @param WP_User $user
	 */
	return (bool) apply_filters( 'brikpanel_whatsapp_visible_for_user', $visible, $user );
}

/**
 * ISO 3166-1 alpha-2 country code → international dialing code (digits only,
 * no leading "+"). Used to complete a local phone number into the E.164-style
 * form WhatsApp needs. Filterable so a store can correct or extend it.
 *
 * @param string $cc Two-letter uppercase country code.
 * @return string Dialing code digits, or '' when unknown.
 */
function brikpanel_country_dialing_code( $cc ) {
	$cc  = strtoupper( (string) $cc );
	$map = array(
		'AF' => '93', 'AL' => '355', 'DZ' => '213', 'AS' => '1', 'AD' => '376', 'AO' => '244', 'AI' => '1', 'AG' => '1',
		'AR' => '54', 'AM' => '374', 'AW' => '297', 'AU' => '61', 'AT' => '43', 'AZ' => '994', 'BS' => '1', 'BH' => '973',
		'BD' => '880', 'BB' => '1', 'BY' => '375', 'BE' => '32', 'BZ' => '501', 'BJ' => '229', 'BM' => '1', 'BT' => '975',
		'BO' => '591', 'BA' => '387', 'BW' => '267', 'BR' => '55', 'BN' => '673', 'BG' => '359', 'BF' => '226', 'BI' => '257',
		'KH' => '855', 'CM' => '237', 'CA' => '1', 'CV' => '238', 'KY' => '1', 'CF' => '236', 'TD' => '235', 'CL' => '56',
		'CN' => '86', 'CO' => '57', 'KM' => '269', 'CG' => '242', 'CD' => '243', 'CR' => '506', 'CI' => '225', 'HR' => '385',
		'CU' => '53', 'CY' => '357', 'CZ' => '420', 'DK' => '45', 'DJ' => '253', 'DM' => '1', 'DO' => '1', 'EC' => '593',
		'EG' => '20', 'SV' => '503', 'GQ' => '240', 'ER' => '291', 'EE' => '372', 'SZ' => '268', 'ET' => '251', 'FJ' => '679',
		'FI' => '358', 'FR' => '33', 'GF' => '594', 'PF' => '689', 'GA' => '241', 'GM' => '220', 'GE' => '995', 'DE' => '49',
		'GH' => '233', 'GI' => '350', 'GR' => '30', 'GL' => '299', 'GD' => '1', 'GP' => '590', 'GU' => '1', 'GT' => '502',
		'GG' => '44', 'GN' => '224', 'GW' => '245', 'GY' => '592', 'HT' => '509', 'HN' => '504', 'HK' => '852', 'HU' => '36',
		'IS' => '354', 'IN' => '91', 'ID' => '62', 'IR' => '98', 'IQ' => '964', 'IE' => '353', 'IM' => '44', 'IL' => '972',
		'IT' => '39', 'JM' => '1', 'JP' => '81', 'JE' => '44', 'JO' => '962', 'KZ' => '7', 'KE' => '254', 'KI' => '686',
		'KW' => '965', 'KG' => '996', 'LA' => '856', 'LV' => '371', 'LB' => '961', 'LS' => '266', 'LR' => '231', 'LY' => '218',
		'LI' => '423', 'LT' => '370', 'LU' => '352', 'MO' => '853', 'MG' => '261', 'MW' => '265', 'MY' => '60', 'MV' => '960',
		'ML' => '223', 'MT' => '356', 'MH' => '692', 'MQ' => '596', 'MR' => '222', 'MU' => '230', 'MX' => '52', 'FM' => '691',
		'MD' => '373', 'MC' => '377', 'MN' => '976', 'ME' => '382', 'MS' => '1', 'MA' => '212', 'MZ' => '258', 'MM' => '95',
		'NA' => '264', 'NR' => '674', 'NP' => '977', 'NL' => '31', 'NC' => '687', 'NZ' => '64', 'NI' => '505', 'NE' => '227',
		'NG' => '234', 'MK' => '389', 'NO' => '47', 'OM' => '968', 'PK' => '92', 'PW' => '680', 'PS' => '970', 'PA' => '507',
		'PG' => '675', 'PY' => '595', 'PE' => '51', 'PH' => '63', 'PL' => '48', 'PT' => '351', 'PR' => '1', 'QA' => '974',
		'RE' => '262', 'RO' => '40', 'RU' => '7', 'RW' => '250', 'KN' => '1', 'LC' => '1', 'VC' => '1', 'WS' => '685',
		'SM' => '378', 'ST' => '239', 'SA' => '966', 'SN' => '221', 'RS' => '381', 'SC' => '248', 'SL' => '232', 'SG' => '65',
		'SK' => '421', 'SI' => '386', 'SB' => '677', 'SO' => '252', 'ZA' => '27', 'KR' => '82', 'SS' => '211', 'ES' => '34',
		'LK' => '94', 'SD' => '249', 'SR' => '597', 'SE' => '46', 'CH' => '41', 'SY' => '963', 'TW' => '886', 'TJ' => '992',
		'TZ' => '255', 'TH' => '66', 'TL' => '670', 'TG' => '228', 'TO' => '676', 'TT' => '1', 'TN' => '216', 'TR' => '90',
		'TM' => '993', 'TC' => '1', 'TV' => '688', 'UG' => '256', 'UA' => '380', 'AE' => '971', 'GB' => '44', 'US' => '1',
		'UY' => '598', 'UZ' => '998', 'VU' => '678', 'VA' => '39', 'VE' => '58', 'VN' => '84', 'VG' => '1', 'VI' => '1',
		'YE' => '967', 'ZM' => '260', 'ZW' => '263',
	);
	$code = isset( $map[ $cc ] ) ? $map[ $cc ] : '';

	/**
	 * Filter the dialing code resolved for a country. Return '' to skip
	 * prepending (the raw digits are then used as-is).
	 */
	return (string) apply_filters( 'brikpanel_country_dialing_code', $code, $cc );
}

/**
 * Normalise an order's billing phone into the international digits WhatsApp
 * expects. Returns '' when the order has no usable phone.
 *
 * @param WC_Order $order
 * @return string Digits only (no "+"), or ''.
 */
function brikpanel_order_whatsapp_number( $order ) {
	if ( ! is_a( $order, 'WC_Order' ) ) {
		return '';
	}
	$raw = trim( (string) $order->get_billing_phone() );
	if ( $raw === '' ) {
		return '';
	}

	$has_plus   = strpos( $raw, '+' ) === 0;
	$digits     = preg_replace( '/\D+/', '', $raw );
	$has_double = strpos( $digits, '00' ) === 0;

	if ( $has_plus ) {
		// Already international, e.g. "+55 11…".
		$number = $digits;
	} elseif ( $has_double ) {
		// International access prefix "00" — drop it.
		$number = substr( $digits, 2 );
	} else {
		// Local number. Prepend the billing country's dialing code, dropping a
		// single national trunk "0" first (UK "07…" → 44 7…, and a Brazilian
		// carrier "0" is harmless to strip). If the customer already typed the
		// country code, keep it as-is.
		$number = $digits;
		$code   = brikpanel_country_dialing_code( $order->get_billing_country() );
		if ( $code !== '' ) {
			$local = ( strlen( $digits ) > 1 && $digits[0] === '0' ) ? substr( $digits, 1 ) : $digits;
			$number = ( strpos( $local, $code ) === 0 ) ? $local : ( $code . $local );
		}
	}

	$number = preg_replace( '/\D+/', '', (string) $number );
	if ( strlen( $number ) < 6 ) {
		// Too short to be a real dialable number.
		return '';
	}

	/**
	 * Filter the final WhatsApp number (digits only, no "+") for an order.
	 */
	return (string) apply_filters( 'brikpanel_order_whatsapp_number', $number, $order );
}

/**
 * Full https://wa.me/ URL for an order, or '' when no phone is available.
 *
 * @param WC_Order $order
 * @return string
 */
function brikpanel_order_whatsapp_url( $order ) {
	$number = brikpanel_order_whatsapp_number( $order );
	if ( $number === '' ) {
		return '';
	}
	return apply_filters( 'brikpanel_order_whatsapp_url', 'https://wa.me/' . $number, $order );
}

/**
 * The WhatsApp glyph as inline SVG (brand green). Static, trusted markup.
 *
 * @param int $size Pixel size.
 * @return string
 */
function brikpanel_order_whatsapp_icon_svg( $size = 18 ) {
	$size = (int) $size;
	return '<svg class="brikpanel-wa-glyph" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.019-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51l-.57-.01c-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.71.306 1.263.489 1.694.625.712.227 1.36.195 1.872.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>';
}

// =============================================================================
// ORDERS LIST — WhatsApp column (rendered in front of the order number)
// =============================================================================

$brikpanel_wa_hpos = get_option( 'woocommerce_custom_orders_table_enabled' ) === 'yes';
if ( $brikpanel_wa_hpos ) {
	add_filter( 'manage_woocommerce_page_wc-orders_columns', 'brikpanel_wa_add_order_column', 21 );
	add_action( 'manage_woocommerce_page_wc-orders_custom_column', 'brikpanel_wa_fill_order_column', 21, 2 );
} else {
	add_filter( 'manage_edit-shop_order_columns', 'brikpanel_wa_add_order_column', 21 );
	add_action( 'manage_shop_order_posts_custom_column', 'brikpanel_wa_fill_order_column_legacy', 21, 2 );
}

/**
 * Insert the WhatsApp column immediately before the order-number column so the
 * icon reads as sitting "in front of" the order. Falls back to prepending after
 * the checkbox when the order-number column can't be found.
 *
 * @param array $columns
 * @return array
 */
function brikpanel_wa_add_order_column( $columns ) {
	if ( ! is_array( $columns ) || isset( $columns['brikpanel_whatsapp'] ) ) {
		return $columns;
	}
	if ( ! brikpanel_whatsapp_visible_for_user() ) {
		return $columns;
	}
	$label  = __( 'WhatsApp', 'brikpanel' );
	$anchor = isset( $columns['order_number'] ) ? 'order_number' : ( isset( $columns['order_title'] ) ? 'order_title' : '' );

	if ( $anchor === '' ) {
		return array_merge( array( 'brikpanel_whatsapp' => $label ), $columns );
	}
	$out = array();
	foreach ( $columns as $key => $value ) {
		if ( $key === $anchor ) {
			$out['brikpanel_whatsapp'] = $label;
		}
		$out[ $key ] = $value;
	}
	return $out;
}

/**
 * Render the icon cell (HPOS — receives the order object).
 *
 * @param string   $column
 * @param WC_Order $order
 */
function brikpanel_wa_fill_order_column( $column, $order ) {
	if ( $column !== 'brikpanel_whatsapp' ) {
		return;
	}
	brikpanel_wa_render_list_icon( $order );
}

/**
 * Render the icon cell (legacy — receives the post ID).
 *
 * @param string $column
 * @param int    $post_id
 */
function brikpanel_wa_fill_order_column_legacy( $column, $post_id ) {
	if ( $column !== 'brikpanel_whatsapp' ) {
		return;
	}
	$order = wc_get_order( $post_id );
	if ( $order ) {
		brikpanel_wa_render_list_icon( $order );
	}
}

/**
 * Echo the list-cell WhatsApp link for an order (nothing when no phone).
 *
 * @param WC_Order $order
 */
function brikpanel_wa_render_list_icon( $order ) {
	if ( ! brikpanel_whatsapp_visible_for_user() ) {
		return;
	}
	$url = brikpanel_order_whatsapp_url( $order );
	if ( $url === '' ) {
		return;
	}
	$title = __( 'Message the customer on WhatsApp', 'brikpanel' );
	printf(
		'<a href="%1$s" target="_blank" rel="noopener" class="brikpanel-wa-list-link" title="%2$s" aria-label="%2$s">%3$s</a>',
		esc_url( $url ),
		esc_attr( $title ),
		brikpanel_order_whatsapp_icon_svg( 18 ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static inline SVG.
	);
}

// =============================================================================
// SINGLE ORDER SCREEN — "Message on WhatsApp" button under the billing address
// =============================================================================

add_action( 'woocommerce_admin_order_data_after_billing_address', 'brikpanel_wa_render_order_screen_button', 20 );

/**
 * Render the WhatsApp button on the order edit screen.
 *
 * @param WC_Order $order
 */
function brikpanel_wa_render_order_screen_button( $order ) {
	if ( ! brikpanel_whatsapp_visible_for_user() ) {
		return;
	}
	$url = brikpanel_order_whatsapp_url( $order );
	if ( $url === '' ) {
		return;
	}
	printf(
		'<p class="brikpanel-wa-order-screen"><a href="%1$s" target="_blank" rel="noopener" class="button brikpanel-wa-btn">%2$s<span>%3$s</span></a></p>',
		esc_url( $url ),
		brikpanel_order_whatsapp_icon_svg( 16 ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static inline SVG.
		esc_html__( 'Message on WhatsApp', 'brikpanel' )
	);
}

// =============================================================================
// STYLES (orders list + order edit screen only)
// =============================================================================

add_action( 'admin_head', function () {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( ! $screen ) {
		return;
	}
	$id       = (string) $screen->id;
	$is_list  = ( $id === 'woocommerce_page_wc-orders' || $id === 'edit-shop_order' );
	$is_edit  = ( $id === 'woocommerce_page_wc-orders' && ( isset( $_GET['action'] ) && $_GET['action'] === 'edit' ) )
		|| $id === 'shop_order';
	if ( ! $is_list && ! $is_edit ) {
		return;
	}
	if ( ! brikpanel_whatsapp_visible_for_user() ) {
		return;
	}
	?>
	<style id="brikpanel-wa-style">
		/* Orders list — tight icon column in front of the order number. */
		.wp-list-table th.column-brikpanel_whatsapp,
		.wp-list-table td.column-brikpanel_whatsapp {
			width: 34px;
			text-align: center;
			padding-left: 6px;
			padding-right: 6px;
		}
		.brikpanel-wa-list-link {
			display: inline-flex;
			align-items: center;
			justify-content: center;
			width: 26px;
			height: 26px;
			border-radius: 6px;
			color: #25d366;
			text-decoration: none;
			transition: background-color .15s ease, transform .15s ease;
		}
		.brikpanel-wa-list-link:hover {
			background: rgba(37, 211, 102, .12);
			transform: translateY(-1px);
		}
		.brikpanel-wa-list-link .brikpanel-wa-glyph { display: block; }
		/* Order edit screen — button under the billing address. */
		.brikpanel-wa-order-screen { margin: .75rem 0 0; }
		.brikpanel-wa-btn.brikpanel-wa-btn {
			display: inline-flex;
			align-items: center;
			gap: .4rem;
			height: auto;
			padding: .4rem .75rem;
			color: #fff;
			background: #25d366;
			border-color: #1da851;
			border-radius: 6px;
			box-shadow: none;
			text-shadow: none;
			font-weight: 600;
		}
		.brikpanel-wa-btn.brikpanel-wa-btn:hover {
			color: #fff;
			background: #1da851;
			border-color: #178a43;
		}
		.brikpanel-wa-btn .brikpanel-wa-glyph { color: #fff; }
	</style>
	<?php
} );

// =============================================================================
// SETTINGS — WhatsApp on/off + role scoping (grouped under the Orders section)
// =============================================================================

/**
 * All registered roles as slug => translated display name, for the role
 * multiselect. Reuses the Access control collector when available so the two
 * role pickers stay identical; falls back to a local copy otherwise.
 *
 * @return array<string,string>
 */
function brikpanel_whatsapp_collect_roles() {
	if ( function_exists( 'brikpanel_access_collect_roles' ) ) {
		return brikpanel_access_collect_roles();
	}
	$out = [];
	if ( function_exists( 'wp_roles' ) ) {
		foreach ( wp_roles()->roles as $slug => $info ) {
			$out[ $slug ] = isset( $info['name'] ) ? translate_user_role( $info['name'] ) : $slug;
		}
	}
	return $out;
}

/**
 * Place the WhatsApp settings title under the existing "Orders" sub-nav section.
 */
add_filter( 'brikpanel_settings_title_section_map', function ( $map ) {
	if ( is_array( $map ) ) {
		$map['brk_whatsapp_title'] = 'orders';
	}
	return $map;
} );

/**
 * Register the WhatsApp module settings (global switch + hidden roles).
 */
add_filter( 'brikpanel_settings_fields', function ( $fields ) {
	if ( ! is_array( $fields ) ) {
		return $fields;
	}

	$block = [
		[
			'name' => __( 'WhatsApp', 'brikpanel' ),
			'type' => 'title',
			'id'   => 'brk_whatsapp_title',
			'desc' => __( 'A one-click WhatsApp shortcut for reaching a customer about their order: a small WhatsApp icon on the orders list and a "Message on WhatsApp" button under the billing address on the order edit screen. It opens a chat pre-targeting the order\'s billing phone. Shown to everyone who can manage WooCommerce orders by default.', 'brikpanel' ),
		],
		[
			'name'    => __( 'WhatsApp order shortcut', 'brikpanel' ),
			'id'      => BRIKPANEL_WHATSAPP_OPT_ENABLED,
			'type'    => 'checkbox',
			'desc'    => __( 'Show the WhatsApp icon on the orders list and the "Message on WhatsApp" button on the order edit screen. Turn this off to remove both everywhere. On by default.', 'brikpanel' ),
			'default' => 'yes',
		],
		[
			'name'     => __( 'Hide WhatsApp shortcut from roles', 'brikpanel' ),
			'id'       => BRIKPANEL_WHATSAPP_OPT_HIDDEN_ROLES,
			'type'     => 'multiselect',
			'class'    => 'wc-enhanced-select',
			'desc'     => __( 'Users with any of the selected roles no longer see the WhatsApp shortcut, while everyone else keeps it. A user hidden this way can switch it back on for their own account from their WordPress profile page. Leave empty to show it to everyone who can manage WooCommerce orders.', 'brikpanel' ),
			'desc_tip' => true,
			'options'  => brikpanel_whatsapp_collect_roles(),
			'default'  => [],
		],
		[
			'type' => 'sectionend',
			'id'   => 'brk_whatsapp_title',
		],
	];

	return array_merge( $fields, $block );
}, 8 );

// =============================================================================
// PER-USER OPT-IN — profile checkbox for a user whose role is hidden by default
// =============================================================================

/**
 * Render the "show it for my account" checkbox on the profile screen — but only
 * for a user who could otherwise see the shortcut (has `manage_woocommerce`) and
 * whose role is currently in the hidden list, so there is something to opt into.
 *
 * @param WP_User $user The profile being viewed/edited.
 */
function brikpanel_whatsapp_render_profile_field( $user ) {
	if ( ! brikpanel_whatsapp_module_enabled() ) {
		return;
	}
	if ( ! $user instanceof WP_User || ! user_can( $user, 'manage_woocommerce' ) ) {
		return;
	}
	if ( ! brikpanel_whatsapp_user_is_hidden_by_role( $user ) ) {
		return;
	}
	$optin = get_user_meta( $user->ID, BRIKPANEL_WHATSAPP_USER_OPTIN_META, true ) === 'yes';
	?>
	<h2><?php esc_html_e( 'WhatsApp order shortcut', 'brikpanel' ); ?></h2>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><?php esc_html_e( 'WhatsApp shortcut', 'brikpanel' ); ?></th>
			<td>
				<label>
					<input type="checkbox" name="brikpanel_whatsapp_optin" value="yes" <?php checked( $optin ); ?> />
					<?php esc_html_e( 'Show the WhatsApp order shortcut for my account', 'brikpanel' ); ?>
				</label>
				<p class="description"><?php esc_html_e( 'Your role has the WhatsApp order shortcut turned off. Tick this to show the WhatsApp icon on the orders list and the button on the order edit screen for your account only.', 'brikpanel' ); ?></p>
			</td>
		</tr>
	</table>
	<?php
}
add_action( 'show_user_profile', 'brikpanel_whatsapp_render_profile_field' );
add_action( 'edit_user_profile', 'brikpanel_whatsapp_render_profile_field' );

/**
 * Save the per-user opt-in. WordPress core verifies the profile-update nonce
 * before these hooks fire. The opt-in is only stored for a user who is actually
 * hidden by role, so a stale POST can never leave an orphan meta behind.
 *
 * @param int $user_id
 */
function brikpanel_whatsapp_save_profile_field( $user_id ) {
	if ( ! current_user_can( 'edit_user', $user_id ) ) {
		return;
	}
	$user = get_user_by( 'id', $user_id );
	if ( ! $user || ! user_can( $user, 'manage_woocommerce' ) || ! brikpanel_whatsapp_user_is_hidden_by_role( $user ) ) {
		return;
	}
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- core verifies the update-user nonce before these hooks run.
	$opt_in = isset( $_POST['brikpanel_whatsapp_optin'] ) && $_POST['brikpanel_whatsapp_optin'] === 'yes';
	if ( $opt_in ) {
		update_user_meta( $user_id, BRIKPANEL_WHATSAPP_USER_OPTIN_META, 'yes' );
	} else {
		delete_user_meta( $user_id, BRIKPANEL_WHATSAPP_USER_OPTIN_META );
	}
}
add_action( 'personal_options_update', 'brikpanel_whatsapp_save_profile_field' );
add_action( 'edit_user_profile_update', 'brikpanel_whatsapp_save_profile_field' );
