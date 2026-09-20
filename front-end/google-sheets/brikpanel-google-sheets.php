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
/**
 * Tell Import / Export what the Google Sheets module owns.
 *
 * This registration sits ABOVE the module gate below, and that position is the
 * whole point: a store with Google Sheets switched off still owns these keys,
 * and an import is exactly the moment something else is about to write them.
 * Registering after the gate would leave them unclassified on precisely the
 * sites where the module is dormant.
 *
 * Option names are written as literals here rather than through the class
 * constants that define them, because those classes live behind the same gate.
 *
 * Three groups, three different answers:
 *
 *   - The flow settings and the column layouts travel. They are the part a
 *     merchant actually configured, and they are the part an agency wants to
 *     hand to the next store. A flow that arrives switched on with no
 *     connection behind it does nothing: every sync path checks the connection
 *     first, so there are no failing jobs and no error-log growth.
 *   - The connection itself never leaves the site. The tokens are credentials,
 *     and the spreadsheet id, url and title name one document owned by one
 *     Google account.
 *   - Row positions, queues, last-sync stamps and the discovered custom-field
 *     catalogue are working state. The catalogue in particular is rebuilt from
 *     the target's OWN checkout fields, which is the only place it can be
 *     right.
 *
 * @param array $map Registry so far.
 * @return array
 */
add_filter( 'brikpanel_exportable_option_keys', 'brikpanel_gs_register_export_keys' );
function brikpanel_gs_register_export_keys( $map ) {
	$portable = [
		// Orders flow.
		'brikpanel_gs_orders_enabled', 'brikpanel_gs_orders_realtime', 'brikpanel_gs_orders_tab',
		'brikpanel_gs_orders_bulk_interval', 'brikpanel_gs_orders_bulk_since',
		'brikpanel_gs_orders_bulk_statuses', 'brikpanel_gs_orders_shipping_methods',
		'brikpanel_gs_orders_pull_enabled', 'brikpanel_gs_orders_pull_interval',
		// Products flow.
		'brikpanel_gs_products_enabled', 'brikpanel_gs_products_tab',
		'brikpanel_gs_products_pull_enabled', 'brikpanel_gs_products_pull_interval',
		// Reports, customers, expenses flows.
		'brikpanel_gs_reports_enabled', 'brikpanel_gs_reports_interval',
		'brikpanel_gs_customers_enabled', 'brikpanel_gs_customers_tab',
		'brikpanel_gs_expenses_enabled', 'brikpanel_gs_expenses_tab',
		'brikpanel_gs_expenses_pull_enabled', 'brikpanel_gs_expenses_pull_interval',
	];
	foreach ( $portable as $key ) {
		$map[ $key ] = [
			'class'    => 'portable',
			'group'    => 'integrations',
			'sanitize' => 'brikpanel_gs_sanitize_import_scalar_or_list',
			'default'  => '',
		];
	}

	// The four column layouts — the reported gap. Each is a flat, ordered list
	// of column keys.
	foreach ( [ 'orders', 'customers', 'products', 'expenses' ] as $flow ) {
		$map[ 'brikpanel_gs_columns_' . $flow ] = [
			'class'    => 'portable',
			'group'    => 'integrations',
			'sanitize' => 'brikpanel_gs_sanitize_import_columns',
			'default'  => [],
		];
	}

	// Credentials.
	$map['brikpanel_gs_tokens'] = [ 'class' => 'secret', 'group' => 'integrations' ];
	// Ciphertext parked when a vault could not be decrypted. Secret like
	// the vault itself, and just as non-portable between sites.
	$map['brikpanel_gs_tokens_unreadable'] = [ 'class' => 'secret', 'group' => 'integrations' ];
	$map['brikpanel_gs_vault_alert'] = [ 'class' => 'internal', 'group' => 'integrations' ];

	// One Google document, owned by one account.
	foreach ( [ 'brikpanel_gs_spreadsheet_id', 'brikpanel_gs_spreadsheet_url', 'brikpanel_gs_spreadsheet_title', 'brikpanel_gs_products_categories', 'brikpanel_gs_orders_row_layout' ] as $key ) {
		$map[ $key ] = [ 'class' => 'site', 'group' => 'integrations' ];
	}

	// Working state.
	foreach ( [
		'brikpanel_gs_killswitch', 'brikpanel_gs_error_log', 'brikpanel_gs_orders_custom_fields',
		'brikpanel_gs_orders_last_sync', 'brikpanel_gs_orders_last_pull',
		'brikpanel_gs_products_last_push', 'brikpanel_gs_products_last_pull',
		'brikpanel_gs_products_push_queue', 'brikpanel_gs_products_rebuild',
		'brikpanel_gs_reports_last_sync', 'brikpanel_gs_customers_last_sync',
		'brikpanel_gs_expenses_last_push', 'brikpanel_gs_expenses_last_pull',
		'brikpanel_gs_expenses_state',
	] as $key ) {
		$map[ $key ] = [ 'class' => 'internal' ];
	}

	return $map;
}

/**
 * Clean an imported Google Sheets flow setting.
 *
 * The flow settings are a mix of 'yes'/'no' flags, intervals, tab names and
 * short lists (order statuses, shipping methods), so one cleaner covers them:
 * scalars become plain text, lists become lists of plain text, anything nested
 * is refused rather than flattened.
 *
 * @param mixed $value
 * @return mixed|null
 */
function brikpanel_gs_sanitize_import_scalar_or_list( $value ) {
	if ( is_scalar( $value ) ) {
		return sanitize_text_field( (string) $value );
	}
	if ( ! is_array( $value ) ) {
		return null;
	}
	$out = [];
	foreach ( $value as $item ) {
		if ( is_scalar( $item ) ) {
			$out[] = sanitize_text_field( (string) $item );
		}
	}
	return $out;
}

/**
 * Clean an imported column layout: an ordered list of column keys.
 *
 * Keys the target does not offer are kept. The mapping code re-prepends the
 * mandatory columns and ignores the rest, and a column that belongs to a flow
 * the target has not scanned yet would otherwise be dropped from the layout
 * the merchant is trying to reproduce.
 *
 * @param mixed $value
 * @return string[]
 */
function brikpanel_gs_sanitize_import_columns( $value ) {
	if ( ! is_array( $value ) ) {
		return [];
	}
	$out = [];
	foreach ( $value as $key ) {
		if ( ! is_string( $key ) ) {
			continue;
		}
		$key = sanitize_text_field( $key );
		if ( '' !== $key && ! in_array( $key, $out, true ) ) {
			$out[] = $key;
		}
	}
	return $out;
}

if ( ! brikpanel_gs_module_is_enabled() ) {
	// The short-circuit above is what makes "dormant" true for everything that
	// runs per-request — but the recurring Action Scheduler jobs were already
	// registered while the module was on, and nothing below this line loads to
	// cancel them. They stayed pending forever, waking every 2-15 minutes to
	// fire a hook with no listener, and came back at the OLD cadence the moment
	// the module was re-enabled. Sweep them once, here, where we know the
	// module is off. Hook names are inlined deliberately: the classes that own
	// the constants are exactly what we are refusing to load.
	add_action( 'brikpanel_cron_register', 'brikpanel_gs_unschedule_when_disabled' );
	return;
}

/**
 * Cancel the module's recurring jobs while it is switched off.
 *
 * Runs from the register hook, so Brikpanel_Cron::reconcile() only lets it
 * reach Action Scheduler when the job set changed or once an hour.
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
		// Through the wrapper, never as_* directly: the declared floor
		// (WooCommerce 4.0) ships Action Scheduler 3.1.2, where a direct call
		// from this `init` path was a fatal on every front-end page view.
		// cancel() is already a no-op when nothing is pending.
		Brikpanel_Cron::cancel( $hook );
	}
}

// =============================================================================
// Class loader (manual — no Composer)
// =============================================================================
require_once BRIKPANEL_PATH . 'includes/class-brikpanel-secret-vault.php';
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
