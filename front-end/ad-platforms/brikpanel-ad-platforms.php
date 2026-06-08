<?php
/**
 * BrikPanel — Ad Platforms Integration bootstrap.
 *
 * Pulls daily total ad spend from Google Ads and Meta Ads into a local table
 * so the BrikPanel dashboard can show real ROAS and Net Profit alongside store
 * revenue. Intentionally narrow scope: spend + impressions + clicks per day
 * per account, no per-campaign / per-creative breakdown.
 *
 * Architecture mirrors the Google Sheets module (token vault, WPCode proxy
 * handshake, Action Scheduler cron). Both Google and Meta share one storage
 * table and one multi-platform token vault keyed by platform slug.
 *
 * @package BrikPanel
 * @since   3.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// =============================================================================
// Module constants
// =============================================================================
if ( ! defined( 'BRIKPANEL_ADS_DIR' ) ) {
	define( 'BRIKPANEL_ADS_DIR', BRIKPANEL_PATH . 'front-end/ad-platforms/' );
}
if ( ! defined( 'BRIKPANEL_ADS_URL' ) ) {
	define( 'BRIKPANEL_ADS_URL', BRIKPANEL_URL . 'front-end/ad-platforms/' );
}

/**
 * Proxy endpoint base. Override via define('BRIKPANEL_ADS_PROXY_BASE', '...')
 * in wp-config.php to point staging installs at a non-production proxy.
 *
 * Path lives under a distinct namespace ("brikpanel-ads-proxy") so it can
 * coexist with the Google Sheets proxy snippet without colliding on routes.
 */
if ( ! defined( 'BRIKPANEL_ADS_PROXY_BASE' ) ) {
	define( 'BRIKPANEL_ADS_PROXY_BASE', 'https://brksoft.com/wp-json/brikpanel-ads-proxy/v1' );
}

/**
 * Maximum days of historical data fetched on first connect.
 *
 * Set to 1095 (3 years) because Meta Marketing API caps insights history at
 * ~37 months — pulling 5 years from Google but only 3 from Meta would create
 * lopsided ROAS comparisons in earlier periods. Symmetric history is the
 * safer default.
 */
if ( ! defined( 'BRIKPANEL_ADS_BACKFILL_DAYS' ) ) {
	define( 'BRIKPANEL_ADS_BACKFILL_DAYS', 1095 );
}

/**
 * How many trailing days the daily sync re-fetches. Ad platforms revise
 * recent-day numbers for 24–48h after the fact; pulling the last 7 days every
 * run catches those corrections without re-pulling the whole history.
 */
if ( ! defined( 'BRIKPANEL_ADS_REFRESH_WINDOW_DAYS' ) ) {
	define( 'BRIKPANEL_ADS_REFRESH_WINDOW_DAYS', 7 );
}

// =============================================================================
// Module enable toggle — registered BEFORE the disabled-state early return so
// the admin can always flip the integration back on from the BrikPanel WC
// settings tab, even after disabling it.
// =============================================================================

/**
 * Whether the Ad Platforms integration is enabled in BrikPanel settings.
 * Defaults to "yes" so the feature shows up post-install; admins can disable
 * it from WooCommerce → Settings → BrikPanel → Ad Platforms.
 */
function brikpanel_ads_module_is_enabled() {
	return get_option( 'brikpanel_ads_module_enabled', 'yes' ) === 'yes';
}

/**
 * Whether the Google Ads connection is locked (greyed out, not clickable).
 *
 * Google Ads is LIVE by default — the Developer Token is approved and the
 * proxy returns a valid accounts.google.com authorize URL, so the Connect
 * button works and the full normal flow (account picker, MCC, Sync now,
 * Disconnect) is available. The lock plumbing is kept only as an operator
 * escape hatch: if Google ever needs to be taken down (token suspended,
 * API access revoked) it can be re-locked from a single place without a
 * code change. Same contract as Meta below.
 *
 * Re-lock by either:
 *   - define( 'BRIKPANEL_ADS_GOOGLE_LOCKED', true ) in wp-config.php, or
 *   - update_option( 'brikpanel_ads_google_unlocked', 'no' )
 *
 * The constant wins if defined, so the operator can hard-lock or hard-unlock
 * everywhere from a single place.
 */
function brikpanel_ads_google_locked() {
	if ( defined( 'BRIKPANEL_ADS_GOOGLE_LOCKED' ) ) {
		return (bool) BRIKPANEL_ADS_GOOGLE_LOCKED;
	}
	return get_option( 'brikpanel_ads_google_unlocked', 'yes' ) !== 'yes';
}

/**
 * Whether the Google Ads card wears the "Coming soon" skin while still being
 * fully functional underneath. Independent of the hard lock above: a hard lock
 * still wins and disables the button.
 *
 * Defaults to OFF: Google approved the adwords sensitive-scope verification
 * for OAuth project 432658392951 (brikpanel-ads-proxy), so the plain
 * Connected/Not-connected card is the correct out-of-the-box look. The skin
 * is kept only as an operator escape hatch: if Google ever revokes the
 * verification, ship a release that flips the default back without code
 * changes by either:
 *   - define( 'BRIKPANEL_ADS_GOOGLE_DISGUISE', true ) in wp-config.php, or
 *   - update_option( 'brikpanel_ads_google_disguise', 'yes' )
 */
function brikpanel_ads_google_disguised() {
	if ( defined( 'BRIKPANEL_ADS_GOOGLE_DISGUISE' ) ) {
		return (bool) BRIKPANEL_ADS_GOOGLE_DISGUISE;
	}
	return get_option( 'brikpanel_ads_google_disguise', 'no' ) === 'yes';
}

/**
 * Whether the Meta Ads connection is locked (greyed out, not clickable).
 *
 * Meta Ads is LIVE by default — its App Review is cleared, so the Connect
 * button works and the full normal flow (account picker, Sync now,
 * Disconnect) is available. The lock plumbing is kept only as an operator
 * escape hatch: if Meta ever needs to be taken down (e.g. app suspended,
 * scope review pending again) it can be re-locked from a single place
 * without a code change.
 *
 * Re-lock by either:
 *   - define( 'BRIKPANEL_ADS_META_LOCKED', true ) in wp-config.php, or
 *   - update_option( 'brikpanel_ads_meta_unlocked', 'no' )
 *
 * The constant wins if defined, so the operator can hard-lock or hard-unlock
 * everywhere from one place — identical contract to Google above (both
 * platforms ship open by default).
 */
function brikpanel_ads_meta_locked() {
	if ( defined( 'BRIKPANEL_ADS_META_LOCKED' ) ) {
		return (bool) BRIKPANEL_ADS_META_LOCKED;
	}
	return get_option( 'brikpanel_ads_meta_unlocked', 'yes' ) !== 'yes';
}

/**
 * Whether the Meta Ads card wears the "Coming soon" skin while still being
 * fully functional underneath.
 *
 * Product decision: the card should read as "Coming soon / Pending Meta
 * approval" so the public listing stays clean and we don't field "it doesn't
 * work" reports — but the Connect button is live, the OAuth handshake runs,
 * and once connected the normal account picker / Sync now / Disconnect body
 * renders so the integration genuinely works for whoever connects it.
 *
 * This is purely cosmetic and independent of brikpanel_ads_meta_locked():
 * a hard lock (constant / option) still wins and disables the button.
 *
 * Drop the disguise (show the plain Connected/Not-connected card) by either:
 *   - define( 'BRIKPANEL_ADS_META_DISGUISE', false ) in wp-config.php, or
 *   - update_option( 'brikpanel_ads_meta_disguise', 'no' )
 */
function brikpanel_ads_meta_disguised() {
	if ( defined( 'BRIKPANEL_ADS_META_DISGUISE' ) ) {
		return (bool) BRIKPANEL_ADS_META_DISGUISE;
	}
	return get_option( 'brikpanel_ads_meta_disguise', 'yes' ) === 'yes';
}

/**
 * Lock state for an arbitrary platform slug. Single dispatcher used by the
 * settings page and the OAuth guard so the rule lives in exactly one place.
 * Unknown platforms are treated as unlocked (no spurious blocking).
 */
function brikpanel_ads_platform_locked( $platform ) {
	if ( $platform === Brikpanel_Ads_Tokens::PLATFORM_GOOGLE ) {
		return brikpanel_ads_google_locked();
	}
	if ( $platform === Brikpanel_Ads_Tokens::PLATFORM_META ) {
		return brikpanel_ads_meta_locked();
	}
	return false;
}

/**
 * Inject the Ad Platforms master toggle into the BrikPanel WC settings tab.
 * Placed just before the Google Sheets section so the two integration toggles
 * sit side by side.
 */
add_filter( 'brikpanel_settings_fields', 'brikpanel_ads_register_module_setting', 5 );
function brikpanel_ads_register_module_setting( $fields ) {
	if ( ! is_array( $fields ) ) {
		return $fields;
	}

	$section = [
		[
			'name' => __( 'Ad Platforms integration', 'brikpanel' ),
			'type' => 'title',
			'id'   => 'brk_ad_platforms_title',
			'desc' => __( 'Pull daily ad spend from Google Ads and Meta Ads to calculate true ROAS and Net Profit on the dashboard. Currently in beta. Turn it off below to hide the admin page and stop all background sync jobs. Your connections stay saved.', 'brikpanel' ),
		],
		[
			'name'    => __( 'Enable Ad Platforms integration', 'brikpanel' ),
			'id'      => 'brikpanel_ads_module_enabled',
			'type'    => 'checkbox',
			'desc'    => __( 'When on, the Ad Platforms admin menu, daily sync, and dashboard ROAS / Net Profit cards are active. When off, the module is dormant: no menu item, no scheduled sync, no dashboard cards.', 'brikpanel' ),
			'default' => 'yes',
		],
		[
			'type' => 'sectionend',
			'id'   => 'brk_ad_platforms_title',
		],
	];

	$insert_at = null;
	foreach ( $fields as $i => $field ) {
		if ( isset( $field['id'], $field['type'] )
			&& $field['id'] === 'brk_google_sheets_title'
			&& $field['type'] === 'title' ) {
			$insert_at = $i;
			break;
		}
	}
	if ( $insert_at === null ) {
		// Fall back to inserting before the Developers section if Sheets toggle
		// hasn't loaded yet (filter ordering edge case on multisite).
		foreach ( $fields as $i => $field ) {
			if ( isset( $field['id'], $field['type'] )
				&& $field['id'] === 'brk_developers_title'
				&& $field['type'] === 'title' ) {
				$insert_at = $i;
				break;
			}
		}
	}
	if ( $insert_at === null ) {
		return array_merge( $fields, $section );
	}
	array_splice( $fields, $insert_at, 0, $section );
	return $fields;
}

/**
 * Add a Beta badge next to the section <h2> in WC settings, matching the
 * Google Sheets section treatment.
 */
add_action( 'woocommerce_settings_brk_ad_platforms_title', 'brikpanel_ads_inject_beta_badge_in_settings' );
function brikpanel_ads_inject_beta_badge_in_settings() {
	$label = esc_js( __( 'Beta', 'brikpanel' ) );
	echo "<script>(function(){"
		. "var d=document.getElementById('brk_ad_platforms_title-description');"
		. "var h=d?d.previousElementSibling:document.querySelector('h2');"
		. "if(!h||h.tagName.toLowerCase()!=='h2'||h.querySelector('.brikpanel-beta-badge'))return;"
		. "var s=document.createElement('span');"
		. "s.className='brikpanel-beta-badge';"
		. "s.textContent='{$label}';"
		. "h.appendChild(s);"
		. "})();</script>";
}

// =============================================================================
// Hard short-circuit when the integration is disabled.
// =============================================================================
if ( ! brikpanel_ads_module_is_enabled() ) {
	return;
}

// =============================================================================
// Class loader (manual — matches Sheets module convention; no Composer)
// =============================================================================
require_once BRIKPANEL_PATH . 'includes/class-brikpanel-proxy-envelope.php';
require_once BRIKPANEL_ADS_DIR . 'class-brikpanel-ads-logger.php';
require_once BRIKPANEL_ADS_DIR . 'class-brikpanel-ads-tokens.php';
require_once BRIKPANEL_ADS_DIR . 'class-brikpanel-ads-proxy.php';
require_once BRIKPANEL_ADS_DIR . 'class-brikpanel-ads-oauth.php';
require_once BRIKPANEL_ADS_DIR . 'class-brikpanel-ads-google-client.php';
require_once BRIKPANEL_ADS_DIR . 'class-brikpanel-ads-meta-client.php';
require_once BRIKPANEL_ADS_DIR . 'class-brikpanel-ads-store.php';
require_once BRIKPANEL_ADS_DIR . 'class-brikpanel-ads-sync.php';
require_once BRIKPANEL_ADS_DIR . 'class-brikpanel-ads-settings.php';
require_once BRIKPANEL_ADS_DIR . 'class-brikpanel-ads-dashboard.php';

// =============================================================================
// Boot
// =============================================================================
new Brikpanel_Ads_Settings();
new Brikpanel_Ads_OAuth();
new Brikpanel_Ads_Sync();
new Brikpanel_Ads_Dashboard();
