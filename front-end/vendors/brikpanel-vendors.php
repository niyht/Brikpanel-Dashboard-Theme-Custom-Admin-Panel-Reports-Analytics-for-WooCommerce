<?php
/**
 * BrikPanel — Vendors / Procurement bootstrap.
 *
 * Loads the vendor management feature (suppliers, purchase orders, COGS by
 * vendor) when enabled in WooCommerce → Settings → BrikPanel → Vendors.
 *
 * The module is organised into focused classes so each surface stays
 * testable in isolation:
 *
 *   - Brikpanel_Vendors                — supplier CRUD page + AJAX
 *   - Brikpanel_Stock_Orders           — purchase order CRUD page + receive flow
 *   - Brikpanel_Vendor_Settings        — WC settings tab section + field filters
 *   - Brikpanel_Vendor_Product_Editor  — vendor selector inside the BrikPanel
 *                                        product editor (simple + variations)
 *
 * The settings class always loads so the toggles appear in WC settings even
 * before the user enables the master switch. The other classes self-gate on
 * the master toggle to avoid registering admin menus / AJAX hooks for a
 * disabled feature.
 *
 * @package BrikPanel
 * @since   2.7.3
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-brikpanel-vendor-settings.php';
require_once __DIR__ . '/class-brikpanel-vendors.php';
require_once __DIR__ . '/class-brikpanel-stock-orders.php';
require_once __DIR__ . '/class-brikpanel-vendor-product-editor.php';

new Brikpanel_Vendor_Settings();

// Master toggle. Default: no — feature is off out of the box, admins opt in
// from WC → Settings → BrikPanel → Vendors. Once enabled, the top-level
// "Vendors" menu appears in the WP admin sidebar.
if ( 'yes' === get_option( 'brikpanel_vendors_enabled', 'no' ) ) {
	new Brikpanel_Vendors();
	new Brikpanel_Stock_Orders();
	new Brikpanel_Vendor_Product_Editor();
}
