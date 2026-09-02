<?php
/**
 * BrikPanel — Google Sheets Integration bootstrap.
 *
 * Loads the Sheets connector module: OAuth proxy handshake, encrypted token
 * vault, Sheets API client, and the four sync flows (real-time orders,
 * scheduled bulk export, BrikPanel reports snapshots, customer + RFM
 * snapshots).
 *
 * Architecture is documented in /front-end/google-sheets/README-internal.md
 * (developer notes only — not shipped to wp.org).
 *
 * @package BrikPanel
 * @since   2.9.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// =============================================================================
// Module constants
// =============================================================================
if ( ! defined( 'BRIKPANEL_GS_DIR' ) ) {
	define( 'BRIKPANEL_GS_DIR', BRIKPANEL_PATH . 'front-end/google-sheets/' );
}
if ( ! defined( 'BRIKPANEL_GS_URL' ) ) {
	define( 'BRIKPANEL_GS_URL', BRIKPANEL_URL . 'front-end/google-sheets/' );
}

// Proxy endpoint base. Override via define('BRIKPANEL_GS_PROXY_BASE', '...')
// in wp-config.php — used to point staging installs at a non-production proxy.
if ( ! defined( 'BRIKPANEL_GS_PROXY_BASE' ) ) {
	define( 'BRIKPANEL_GS_PROXY_BASE', 'https://brksoft.com/wp-json/brikpanel-proxy/v1' );
}

// =============================================================================
// Module enable toggle — registered BEFORE the disabled-state early return so
// the admin can always flip the integration back on from the BrikPanel WC
// settings tab, even after disabling it.
// =============================================================================

/**
 * Whether the Google Sheets integration is enabled in BrikPanel settings.
 * Defaults to "yes" so existing installs keep working untouched; admins can
 * turn it off from WooCommerce → Settings → BrikPanel → Google Sheets.
 */
function brikpanel_gs_module_is_enabled() {
	return get_option( 'brikpanel_gs_module_enabled', 'yes' ) === 'yes';
}

/**
 * Inject the Google Sheets master toggle into the BrikPanel WC settings tab.
 * The section is spliced in just before the "Developers" block so it sits at
 * the bottom of the feature toggles, matching the visual flow of the page.
 */
add_filter( 'brikpanel_settings_fields', 'brikpanel_gs_register_module_setting', 6 );
function brikpanel_gs_register_module_setting( $fields ) {
	if ( ! is_array( $fields ) ) {
		return $fields;
	}

	$section = [
		[
			'name' => __( 'Google Sheets integration', 'brikpanel' ),
			'type' => 'title',
			'id'   => 'brk_google_sheets_title',
			'desc' => __( 'Sync your orders, customers, and analytics straight to Google Sheets. Turn it off below to hide the admin page and stop all background sync jobs. Your connection and per-flow settings stay saved, so turning it back on restores everything.', 'brikpanel' ),
		],
		[
			'name'    => __( 'Enable Google Sheets integration', 'brikpanel' ),
			'id'      => 'brikpanel_gs_module_enabled',
			'type'    => 'checkbox',
			'desc'    => __( 'When on, the Google Sheets admin menu, sync flows and AJAX endpoints are active. When off, the entire module is dormant: no menu item, no scheduled jobs, no order-write listeners.', 'brikpanel' ),
			'default' => 'yes',
		],
		[
			'type' => 'sectionend',
			'id'   => 'brk_google_sheets_title',
		],
	];

	$insert_at = null;
	foreach ( $fields as $i => $field ) {
		if ( isset( $field['id'], $field['type'] )
			&& $field['id'] === 'brk_developers_title'
			&& $field['type'] === 'title' ) {
			$insert_at = $i;
			break;
		}
	}
	if ( $insert_at === null ) {
		return array_merge( $fields, $section );
	}
	array_splice( $fields, $insert_at, 0, $section );
	return $fields;
}

/**
 * Surface Google Sheets on the shared "Integrations" sub-nav section rather
 * than the catch-all General page. The Ad Platforms module registers the same
 * section id (idempotent), so both integrations share one page.
 */
add_filter( 'woocommerce_get_sections_brikpanel', 'brikpanel_gs_register_settings_section' );
function brikpanel_gs_register_settings_section( $sections ) {
	if ( ! isset( $sections['integrations'] ) ) {
		$sections['integrations'] = __( 'Integrations', 'brikpanel' );
	}
	return $sections;
}
add_filter( 'brikpanel_settings_title_section_map', 'brikpanel_gs_map_settings_title' );
function brikpanel_gs_map_settings_title( $map ) {
	$map['brk_google_sheets_title'] = 'integrations';
	return $map;
}
// =============================================================================
// Hard short-circuit when the integration is disabled. Nothing below loads:
// no admin page, no sync classes, no OAuth handlers, no order-write listeners,
// no Action Scheduler handler registration.
// =============================================================================
if ( ! brikpanel_gs_module_is_enabled() ) {
	// The short-circuit above is what makes "dormant" true for everything that
	// runs per-request — but the recurring Action Scheduler jobs were already
	// registered while the module was on, and nothing below this line loads to
	// cancel them. They stayed pending forever, waking every 2-15 minutes to
	// fire a hook with no listener, and came back at the OLD cadence the moment
	// the module was re-enabled. Sweep them once, here, where we know the
	// module is off. Hook names are inlined deliberately: the classes that own
	// the constants are exactly what we are refusing to load.
	add_action( 'init', 'brikpanel_gs_unschedule_when_disabled', 30 );
	return;
}

/**
 * Cancel the module's recurring jobs while it is switched off.
 *
 * Runs once per request but only ever touches Action Scheduler when something
 * is actually still pending, so the disabled path stays cheap.
 */
function brikpanel_gs_unschedule_when_disabled() {
	if ( ! class_exists( 'Brikpanel_Cron' ) || ! Brikpanel_Cron::is_available() ) {
		return;
	}
	$hooks = [
		'brikpanel_gs_order_bulk_export',
		'brikpanel_gs_order_pull',
		'brikpanel_gs_products_push',
		'brikpanel_gs_products_pull',
		'brikpanel_gs_expenses_push',
		'brikpanel_gs_expenses_pull',
		'brikpanel_gs_reports_snapshot',
	];
	foreach ( $hooks as $hook ) {
		// Asked through the wrapper, never as_has_scheduled_action() directly:
		// that function arrived in Action Scheduler 3.3.0 and this plugin's
		// declared floor (WooCommerce 4.0) ships 3.1.2, where calling it from
		// this `init` hook was a fatal on every front-end page view.
		if ( Brikpanel_Cron::has_any_scheduled( $hook ) ) {
			Brikpanel_Cron::cancel( $hook );
		}
	}
}

// =============================================================================
// Class loader (manual — no Composer)
// =============================================================================
require_once BRIKPANEL_PATH . 'includes/class-brikpanel-proxy-envelope.php';
require_once BRIKPANEL_GS_DIR . 'class-brikpanel-sheets-logger.php';
require_once BRIKPANEL_GS_DIR . 'class-brikpanel-sheets-tokens.php';
require_once BRIKPANEL_GS_DIR . 'class-brikpanel-sheets-proxy.php';
require_once BRIKPANEL_GS_DIR . 'class-brikpanel-sheets-oauth.php';
require_once BRIKPANEL_GS_DIR . 'class-brikpanel-sheets-client.php';
require_once BRIKPANEL_GS_DIR . 'class-brikpanel-sheets-mapping.php';
require_once BRIKPANEL_GS_DIR . 'class-brikpanel-sheets-order-sync.php';
require_once BRIKPANEL_GS_DIR . 'class-brikpanel-sheets-products-sync.php';
require_once BRIKPANEL_GS_DIR . 'class-brikpanel-sheets-expenses-sync.php';
require_once BRIKPANEL_GS_DIR . 'class-brikpanel-sheets-reports-sync.php';
require_once BRIKPANEL_GS_DIR . 'class-brikpanel-sheets-customers-sync.php';
require_once BRIKPANEL_GS_DIR . 'class-brikpanel-sheets-settings.php';

// =============================================================================
// Boot
// =============================================================================
new Brikpanel_Sheets_Settings();
new Brikpanel_Sheets_OAuth();
new Brikpanel_Sheets_Order_Sync();
new Brikpanel_Sheets_Products_Sync();
new Brikpanel_Sheets_Expenses_Sync();
new Brikpanel_Sheets_Reports_Sync();
new Brikpanel_Sheets_Customers_Sync();
