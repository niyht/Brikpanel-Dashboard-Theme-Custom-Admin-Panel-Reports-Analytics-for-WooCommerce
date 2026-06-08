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
			'desc' => __( 'Sync your orders, customers, and analytics straight to Google Sheets. The integration is currently in beta — turn it off below to hide the admin page and stop all background sync jobs. Your connection and per-flow settings stay saved, so turning it back on restores everything.', 'brikpanel' ),
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
 * After WC renders the section title, inject a Beta badge next to the <h2>
 * heading. Plain JS append because WC esc_html()'s the title — we can't put
 * markup in the `name` field itself.
 */
add_action( 'woocommerce_settings_brk_google_sheets_title', 'brikpanel_gs_inject_beta_badge_in_settings' );
function brikpanel_gs_inject_beta_badge_in_settings() {
	$label = esc_js( __( 'Beta', 'brikpanel' ) );
	echo "<script>(function(){"
		. "var d=document.getElementById('brk_google_sheets_title-description');"
		. "var h=d?d.previousElementSibling:document.querySelector('h2');"
		. "if(!h||h.tagName.toLowerCase()!=='h2'||h.querySelector('.brikpanel-beta-badge'))return;"
		. "var s=document.createElement('span');"
		. "s.className='brikpanel-beta-badge';"
		. "s.textContent='{$label}';"
		. "h.appendChild(s);"
		. "})();</script>";
}

/**
 * Print the inline CSS for the Beta badge. Loaded admin-wide because the
 * badge is used in the sidebar menu item (which renders on every admin page)
 * and on the BrikPanel WC settings tab title. The rule is tiny enough that a
 * dedicated stylesheet would cost more than it saves.
 */
add_action( 'admin_head', 'brikpanel_gs_print_beta_badge_styles', 9999 );
function brikpanel_gs_print_beta_badge_styles() {
	// Excluded users (access control) get the stock admin with no BrikPanel
	// menu, so the badge it styles never renders — skip the inline CSS too.
	if ( function_exists( 'brikpanel_access_should_neutralize' ) && brikpanel_access_should_neutralize() ) {
		return;
	}
	echo '<style id="brikpanel-beta-badge-css">'
		. '.brikpanel-beta-badge{'
			. 'display:inline-flex;align-items:center;'
			. 'margin-left:0.4rem;padding:0.05rem 0.45rem;'
			. 'background:#f1f1f1;color:#616161;'
			. 'border:1px solid #e3e3e3;border-radius:999px;'
			. 'font-size:0.625rem;font-weight:600;letter-spacing:0.04em;'
			. 'line-height:1.6;text-transform:uppercase;'
			. 'vertical-align:middle;'
		. '}'
		. '#adminmenu .brikpanel-beta-badge{'
			. 'background:rgba(255,255,255,0.14);color:#fff;'
			. 'border-color:rgba(255,255,255,0.22);'
			. 'font-size:0.6rem;padding:0.02rem 0.38rem;line-height:1.5;'
		. '}'
		. '.brikpanel-menu-icon-title-container .brikpanel-beta-badge{'
			. 'background:#fff;color:#303030;border-color:#e3e3e3;'
		. '}'
		. '</style>';
}

// =============================================================================
// Hard short-circuit when the integration is disabled. Nothing below loads:
// no admin page, no sync classes, no OAuth handlers, no order-write listeners,
// no Action Scheduler handler registration.
// =============================================================================
if ( ! brikpanel_gs_module_is_enabled() ) {
	return;
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
new Brikpanel_Sheets_Reports_Sync();
new Brikpanel_Sheets_Customers_Sync();
