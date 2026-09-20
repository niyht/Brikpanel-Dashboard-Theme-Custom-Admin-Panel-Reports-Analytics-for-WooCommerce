<?php
/**
 * ORDER SCREEN — WHICH BOXES BELONG IN THE RIGHT COLUMN
 * =============================================================================
 * The modern order screen puts WooCommerce's own boxes into Items / Customer /
 * Notes tabs and sweeps everything else into "More"
 * (see buildTabs() in front-end/order/brikpanel-order.js).
 *
 * That is right for a box you open once a month and wrong for the ones a
 * merchant touches on every single order: the invoice, the shipping label, the
 * tracking number. Before the tabbed layout those sat in WooCommerce's narrow
 * right column, one glance away; afterwards they cost a click each. A merchant
 * with nine such boxes reported exactly that.
 *
 * So a curated list of box ids goes back to the right column, and every person
 * can move any box either way from Screen Options. The list is deliberately
 * NOT "every box registered in the `side` context": plenty of side boxes are
 * promotional banners or things nobody opens, and one plugin can register both
 * a narrow action box and a wide data form (WP Overnight does). Placement is
 * decided per box, never per plugin.
 *
 * The test a box has to pass is "do you DO something with it on this order?",
 * not "is it short?". A box you act on — print the invoice, book the courier,
 * type the tracking number — earns the column. A box that only tells you
 * something you can already read elsewhere does not, however small it is, and
 * stays under More where it costs one click and no attention.
 *
 * Every id below was read from the plugin's own source, never guessed.
 *
 * @package BrikPanel
 */

defined( 'ABSPATH' ) || exit;

/**
 * Box ids that belong in the right column, grouped by the plugin that owns them.
 *
 * Only short boxes are listed. A box registered in `normal` or `advanced`, or a
 * `side` box that is really a table or a long form, stays under More, where it
 * has the full width it was written for.
 *
 * @return array<string, string[]> Plugin slug => box ids.
 */
function brikpanel_order_sidebar_box_map() {
	return array(
		// --- Invoices and documents ---------------------------------------

		// PDF Invoices & Packing Slips (WP Overnight). includes/Admin.php:509-549.
		// `wpo_wcpdf-data-input-box` is deliberately absent: it is the `normal`
		// context grid of number/date fields, one block per document type.
		'woocommerce-pdf-invoices-packing-slips'            => array(
			'wpo_wcpdf_send_emails',
			'wpo_wcpdf-box',
			'wpo_ips-edi-box',
		),

		// WebToffee PDF Invoices. The debug box is URL-triggered and wide, and
		// `wt_pdf_invoice_pro` is an upsell banner, so neither is promoted.
		'print-invoices-packing-slip-labels-for-woocommerce' => array(
			'woocommerce-packinglist-box',
		),

		// Print Invoice & Delivery Notes.
		'woocommerce-delivery-notes'                        => array(
			'woocommerce-delivery-notes-box',
		),

		// Challan (WebAppick).
		'webappick-pdf-invoice-for-woocommerce'             => array(
			'wpifw-meta-box',
		),

		// Flexible Invoices (WP Desk).
		'flexible-invoices'                                 => array(
			'flexible-invoices',
		),

		// Pepro Ultimate Invoice.
		'pepro-ultimate-invoice'                            => array(
			'pepro-ultimate-invoice',
		),

		// PDF Builder (RedNao).
		'woo-pdf-invoice-builder'                           => array(
			'rednao_order_invoice',
		),

		// PDF Invoices and Packing Slips (apifw).
		'pdf-invoices-and-packing-slips-for-woocommerce'    => array(
			'apifw-order-metabox',
		),

		// --- Shipping, labels and tracking --------------------------------

		// WooCommerce Shipping and WooCommerce Tax both register the same
		// tracking box id and are commonly installed together. Their
		// `woocommerce-order-label` box is the full label-purchase app and
		// stays under More.
		'woocommerce-services'                              => array(
			'woocommerce-order-shipment-tracking',
		),

		// Germanized.
		'woocommerce-germanized'                            => array(
			'woocommerce-gzd-order-checkboxes',
			'eu-owb-order-withdrawals',
		),

		// Advanced Shipment Tracking (zorem).
		'woo-advanced-shipment-tracking'                    => array(
			'woocommerce-advanced-shipment-tracking',
		),

		// Packlink PRO.
		'packlink-pro-shipping'                             => array(
			'packlink-shipping-modal',
		),

		// Trakoo.
		'woo-orders-tracking'                               => array(
			'vi_wotv_edit_order_tracking',
		),

		// InPost. Which of the two ids renders depends on the shipping method,
		// and a static guard means only one ever appears, so both are listed.
		'inpost-for-woocommerce'                            => array(
			'easypack_parcel_machines',
			'easypack_shipment_changed',
		),

		// Boxtal Connect. Its tracking box is `normal` and wide.
		'boxtal-connect'                                    => array(
			'boxtal-order-parcelpoint',
		),

		// MyParcel.
		'woocommerce-myparcel'                              => array(
			'myparcel',
		),

		// ParcelPanel / CWILL.
		'parcelpanel'                                       => array(
			'pp-wc-shop_order-shipment-tracking',
		),

		// YITH Order & Shipment Tracking.
		'yith-woocommerce-order-tracking'                   => array(
			'yith-order-tracking-information',
		),

		// AfterShip.
		'aftership-woocommerce-tracking'                    => array(
			'woocommerce-aftership',
		),

		// SmartyParcel.
		'wc-ukr-shipping'                                   => array(
			'wcus_edit_order_ttn_metabox',
		),

		// Hungarian Pickup Points.
		'hungarian-pickup-points-for-woocommerce'           => array(
			'vp_woo_pont_metabox',
			'vp_woo_pont_metabox_tracking',
			'vp_woo_pont_metabox_kvikk',
		),

		// TrackShip.
		'trackship-for-woocommerce'                         => array(
			'trackship',
		),

		// WC Multishipping.
		'wc-multishipping'                                  => array(
			'wms_meta_box',
		),

		// DHL for WooCommerce. The Internetmarke box id is a class constant
		// that could not be read from the source, so it is left out.
		'dhl-for-woocommerce'                               => array(
			'woocommerce-shipment-dhl-label',
			'woocommerce-dhl-dp-order',
		),

		// PostNL.
		'woo-postnl'                                        => array(
			'woocommerce-shipment-postnl-label',
		),

		// GLS Shipping.
		'gls-shipping-for-woocommerce'                      => array(
			'gls_shipping_info_meta_box',
		),

		// DHL eCommerce Benelux.
		'dhlpwc'                                            => array(
			'dhlpwc-label',
		),

		// Shipment Tracker. Its message history box is `normal` and wide.
		'shipment-tracker-for-woocommerce'                  => array(
			'bt_sync-box',
		),

		// La Poste Pro Expeditions. Its tracking box is `normal` and wide.
		'la-poste-pro-expeditions-woocommerce'              => array(
			'lpfr-eco-order-parcelpoint',
		),

		// Cargus. The AWB detail form is `normal` and long.
		'cargus'                                            => array(
			'cargus_woocommerce_actiuni_awb',
		),

		// --- Payments, currency and the rest ------------------------------

		// WooPayments. Its bundled subscriptions "Related Orders" box is a
		// table in the `normal` context.
		'woocommerce-payments'                              => array(
			'wcpay-order-fraud-and-risk-meta-box',
		),

		// PayPal Payments. `ppcp_recaptcha_status` is `normal`, and the RatePay
		// instructions box is a list of bank details to read, not to act on.
		'woocommerce-paypal-payments'                       => array(
			'ppcp_order-tracking',
			'ppcp_oxxo_payer_action',
		),

		// Booster for WooCommerce. Its `wcj-admin-tools-order-meta` box dumps the
		// whole order meta table, and the country-by-IP and acquisition-source
		// boxes only report a line each, so all three stay under More.
		'woocommerce-jetpack'                               => array(
			'wc-booster-pdf-invoicing',
			'wc-jetpack-orders-navigation',
			'wc-jetpack-order_numbers',
			'wc-jetpack-eu_vat_number',
			'wc-jetpack-checkout_files_upload',
			'wc-jetpack-payment_gateways',
		),

		// Klarna Order Management.
		'klarna-order-management-for-woocommerce'           => array(
			'kom_meta_box',
		),
	);
}

/**
 * The flat list of box ids that default to the right column.
 *
 * @return string[]
 */
function brikpanel_order_sidebar_default_boxes() {
	static $ids = null;

	if ( null === $ids ) {
		$ids = array();
		foreach ( brikpanel_order_sidebar_box_map() as $boxes ) {
			foreach ( $boxes as $box_id ) {
				$ids[ $box_id ] = true;
			}
		}
		$ids = array_keys( $ids );
	}

	/**
	 * Filter which order metaboxes open in the right column instead of the
	 * "More" tab.
	 *
	 * BrikPanel ships a list of well known invoice, shipping label and tracking
	 * boxes. Use this to teach it about one it does not know, or to drop one it
	 * places wrongly for your shop. Each person can still override the result
	 * for themselves under Screen Options.
	 *
	 * @param string[] $ids Metabox ids.
	 */
	return (array) apply_filters( 'brikpanel_order_sidebar_boxes', $ids );
}

/**
 * Clean one metabox id.
 *
 * Deliberately not `sanitize_key()`: metabox ids are echoed verbatim by
 * WordPress and routinely carry capitals and dots, so lowercasing would stop a
 * stored id from ever matching again. Same rule as the orders list keeps for
 * column keys.
 *
 * @param mixed $box_id Raw id.
 * @return string Cleaned id, or '' when it cannot be stored as-is.
 */
function brikpanel_order_clean_box_id( $box_id ) {
	if ( ! is_string( $box_id ) && ! is_int( $box_id ) ) {
		return '';
	}

	return (string) preg_replace( '/[^A-Za-z0-9_\-.:]/', '', substr( (string) $box_id, 0, 100 ) );
}

/**
 * Box ids this screen places itself, which are therefore never offered as a
 * choice and never moved.
 *
 * Kept in step with TAB_BOXES / SIDE_BOXES / HIDDEN_BOXES in
 * front-end/order/brikpanel-order.js.
 *
 * @return string[]
 */
function brikpanel_order_pinned_box_ids() {
	return array(
		// Items / Customer / Notes tabs.
		'woocommerce-order-items',
		'woocommerce-order-data',
		'brikpanel_order_fields',
		'woocommerce-customer-history',
		'woocommerce-order-notes',
		// Always the first card in the right column.
		'woocommerce-order-actions',
		// Replaced by BrikPanel's own "Additional details" card.
		'order_custom',
	);
}

/**
 * Every box on the current order screen that a person may place themselves.
 *
 * Reads the registered metaboxes rather than the printed page, which is safe
 * because WooCommerce registers them on `load-{page}` (HPOS) or inside
 * edit-form-advanced.php before the admin header (legacy) — both before
 * `admin_enqueue_scripts` and before the Screen Options panel is rendered.
 *
 * @param WP_Screen|null $screen Screen to read, or the current one.
 * @return array<string, string> Box id => plain-text title, in the order WordPress prints them.
 */
function brikpanel_order_sidebar_candidates( $screen = null ) {
	if ( null === $screen && function_exists( 'get_current_screen' ) ) {
		$screen = get_current_screen();
	}
	if ( ! $screen instanceof WP_Screen ) {
		return array();
	}

	$registered = isset( $GLOBALS['wp_meta_boxes'][ $screen->id ] ) ? $GLOBALS['wp_meta_boxes'][ $screen->id ] : null;
	if ( ! is_array( $registered ) ) {
		return array();
	}

	$pinned     = brikpanel_order_pinned_box_ids();
	$candidates = array();

	// Only these three contexts are printed on the order screen, so a box
	// registered under a made-up context has no node to move and is skipped.
	foreach ( array( 'side', 'normal', 'advanced' ) as $context ) {
		if ( empty( $registered[ $context ] ) || ! is_array( $registered[ $context ] ) ) {
			continue;
		}

		foreach ( array( 'high', 'sorted', 'core', 'default', 'low' ) as $priority ) {
			if ( empty( $registered[ $context ][ $priority ] ) || ! is_array( $registered[ $context ][ $priority ] ) ) {
				continue;
			}

			foreach ( $registered[ $context ][ $priority ] as $box ) {
				// remove_meta_box() leaves `false` behind; reading ['id'] on it is fatal.
				if ( ! is_array( $box ) || empty( $box['id'] ) || empty( $box['title'] ) ) {
					continue;
				}

				$box_id = brikpanel_order_clean_box_id( $box['id'] );
				// An id that cannot be stored as-is could never stay chosen.
				if ( '' === $box_id || $box_id !== $box['id'] ) {
					continue;
				}
				if ( in_array( $box_id, $pinned, true ) || isset( $candidates[ $box_id ] ) ) {
					continue;
				}

				$title = trim( wp_strip_all_tags( (string) $box['title'] ) );
				if ( '' === $title ) {
					continue;
				}

				$candidates[ $box_id ] = $title;
			}
		}
	}

	return $candidates;
}

/**
 * This person's explicit placement choices.
 *
 * Only boxes they moved AWAY from the shipped default are stored, so a later
 * release that recognises a new plugin still reaches people who have already
 * customised something else.
 *
 * @return array<string, string> Box id => 'side'|'more'.
 */
function brikpanel_order_sidebar_user_overrides() {
	$stored = get_user_option( 'brikpanel_order_side_boxes' );
	if ( ! is_array( $stored ) ) {
		return array();
	}

	$clean = array();
	foreach ( $stored as $box_id => $where ) {
		$box_id = brikpanel_order_clean_box_id( $box_id );
		if ( '' === $box_id || ! in_array( $where, array( 'side', 'more' ), true ) ) {
			continue;
		}
		$clean[ $box_id ] = $where;
	}

	return $clean;
}

/**
 * Whether one box opens in the right column, for this person, on this screen.
 *
 * @param string $box_id    Metabox id.
 * @param array  $defaults  Flipped default list, for a cheap lookup.
 * @param array  $overrides Result of brikpanel_order_sidebar_user_overrides().
 * @return bool
 */
function brikpanel_order_box_is_sidebar( $box_id, array $defaults, array $overrides ) {
	if ( isset( $overrides[ $box_id ] ) ) {
		return 'side' === $overrides[ $box_id ];
	}

	return isset( $defaults[ $box_id ] );
}

/**
 * The box ids that open in the right column on the current screen.
 *
 * Always a list, never a map: the value is handed to JSON and the order script
 * would throw on anything else, which would cost the whole tabbed layout.
 *
 * @param WP_Screen|null $screen Screen to read, or the current one.
 * @return string[]
 */
function brikpanel_order_sidebar_resolved( $screen = null ) {
	$defaults  = array_flip( brikpanel_order_sidebar_default_boxes() );
	$overrides = brikpanel_order_sidebar_user_overrides();
	$resolved  = array();

	foreach ( brikpanel_order_sidebar_candidates( $screen ) as $box_id => $title ) {
		if ( brikpanel_order_box_is_sidebar( $box_id, $defaults, $overrides ) ) {
			$resolved[] = $box_id;
		}
	}

	return array_values( $resolved );
}

/**
 * Whether the placement chooser applies to the current request.
 *
 * @return bool
 */
function brikpanel_order_sidebar_screen_active() {
	static $active = null;

	if ( null === $active ) {
		$active = function_exists( 'brikpanel_order_screen_context' )
			&& null !== brikpanel_order_screen_context()
			&& get_option( 'brikpanel_modern_order_edit', 'yes' ) !== 'no';
	}

	return $active;
}

/**
 * Add a "Show in the sidebar" group to Screen Options on the order screen.
 *
 * Mirrors the orders list "Show in the row" group. WordPress prints this
 * straight after its own "Screen elements" checkboxes, so no repositioning is
 * needed here. The inputs carry no `name`, because the surrounding Screen
 * Options form posts to options.php and these are saved over AJAX instead.
 *
 * @param string    $settings Existing markup.
 * @param WP_Screen $screen   Current screen.
 * @return string
 */
function brikpanel_order_sidebar_screen_settings( $settings, $screen ) {
	if ( ! $screen instanceof WP_Screen || ! brikpanel_order_sidebar_screen_active() ) {
		return $settings;
	}

	$candidates = brikpanel_order_sidebar_candidates( $screen );
	if ( ! $candidates ) {
		return $settings;
	}

	$defaults  = array_flip( brikpanel_order_sidebar_default_boxes() );
	$overrides = brikpanel_order_sidebar_user_overrides();
	$options   = '';

	foreach ( $candidates as $box_id => $title ) {
		$checked  = brikpanel_order_box_is_sidebar( $box_id, $defaults, $overrides );
		$options .= '<label><input type="checkbox" class="bp-side-box-tog" value="' . esc_attr( $box_id ) . '"'
			. checked( $checked, true, false ) . ' />' . esc_html( $title ) . '</label>';
	}

	return $settings
		. '<fieldset class="metabox-prefs bp-side-boxes" data-nonce="' . esc_attr( wp_create_nonce( 'brikpanel_order_side_boxes' ) ) . '">'
		. '<legend>' . esc_html__( 'Show in the sidebar', 'brikpanel' ) . '</legend>'
		. '<p class="bp-side-boxes__hint">' . esc_html__( 'Ticked boxes open in the right column instead of the More tab.', 'brikpanel' ) . '</p>'
		. $options
		. '<p class="bp-side-boxes__foot"><button type="button" class="button-link bp-side-boxes__reset">'
		. esc_html__( 'Reset to defaults', 'brikpanel' ) . '</button></p>'
		. '<p class="bp-side-boxes__error" role="alert" hidden></p>'
		. '</fieldset>';
}
add_filter( 'screen_settings', 'brikpanel_order_sidebar_screen_settings', 10, 2 );

/**
 * Save one person's placement choices.
 *
 * Only boxes that differ from the shipped default are stored, so a later
 * release that recognises a new plugin still reaches people who have already
 * moved something else. An empty result removes the row entirely, which is what
 * makes "no row" mean "defaults" with no ambiguity.
 */
function brikpanel_order_sidebar_save() {
	check_ajax_referer( 'brikpanel_order_side_boxes', 'nonce' );

	if ( ! current_user_can( 'edit_shop_orders' ) ) {
		wp_send_json_error( null, 403 );
	}

	$raw = isset( $_POST['boxes'] ) && is_array( $_POST['boxes'] ) ? wp_unslash( $_POST['boxes'] ) : array();
	if ( isset( $_POST['reset'] ) && '1' === $_POST['reset'] ) {
		$raw = array();
		delete_user_option( get_current_user_id(), 'brikpanel_order_side_boxes' );
		wp_send_json_success( array( 'overrides' => (object) array() ) );
	}

	$defaults  = array_flip( brikpanel_order_sidebar_default_boxes() );
	$pinned    = brikpanel_order_pinned_box_ids();
	$overrides = array();

	foreach ( array_slice( $raw, 0, 200, true ) as $raw_id => $where ) {
		$box_id = brikpanel_order_clean_box_id( $raw_id );
		// An id that does not survive cleaning unchanged is not a real box id;
		// storing the cleaned version would only leave junk that matches
		// nothing and never gets tidied away.
		if ( '' === $box_id || (string) $raw_id !== $box_id || in_array( $box_id, $pinned, true ) ) {
			continue;
		}
		if ( ! in_array( $where, array( 'side', 'more' ), true ) ) {
			continue;
		}
		// Agreeing with the default is not a choice worth keeping: dropping it
		// is what lets a future default change reach this person.
		$is_default_side = isset( $defaults[ $box_id ] );
		if ( ( 'side' === $where ) === $is_default_side ) {
			continue;
		}
		$overrides[ $box_id ] = $where;
	}

	if ( $overrides ) {
		update_user_option( get_current_user_id(), 'brikpanel_order_side_boxes', $overrides );
	} else {
		delete_user_option( get_current_user_id(), 'brikpanel_order_side_boxes' );
	}

	wp_send_json_success( array( 'overrides' => (object) $overrides ) );
}
add_action( 'wp_ajax_brikpanel_order_side_boxes', 'brikpanel_order_sidebar_save' );

/**
 * Clean an imported placement map for one person.
 *
 * @param mixed $value Raw value from a settings file.
 * @return array<string,string>|null
 */
function brikpanel_order_sanitize_import_side_boxes( $value ) {
	if ( ! is_array( $value ) ) {
		return null;
	}

	$pinned = brikpanel_order_pinned_box_ids();
	$clean  = array();

	foreach ( array_slice( $value, 0, 200, true ) as $raw_id => $where ) {
		$box_id = brikpanel_order_clean_box_id( $raw_id );
		if ( '' === $box_id || (string) $raw_id !== $box_id || in_array( $box_id, $pinned, true ) ) {
			continue;
		}
		if ( ! in_array( $where, array( 'side', 'more' ), true ) ) {
			continue;
		}
		$clean[ $box_id ] = $where;
	}

	return $clean ? $clean : null;
}
