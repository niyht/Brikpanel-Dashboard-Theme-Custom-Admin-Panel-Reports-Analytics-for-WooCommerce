<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pure sidebar-icon helpers, extracted from brikpanel-navigation.php so they are
 * available even when the modern navigation override is turned OFF.
 *
 * The navigation module (brikpanel-navigation.php) is only loaded when the
 * "modern navigation" toggle is on, because it registers the hooks that rebuild
 * the admin sidebar. The navigation customizer settings screen, however, is
 * loaded unconditionally (admins can pre-configure the layout before flipping
 * the toggle) and needs these icon helpers to render its icon picker. Keeping
 * them here — in a file required on every admin request — prevents a fatal
 * "call to undefined function" when the toggle is off.
 *
 * These functions are wrapped in function_exists guards so navigation.php (which
 * used to own them) can keep working regardless of include order.
 */

if ( ! function_exists( 'brikpanel_nav_icon_style' ) ) {
	/**
	 * Resolve the sidebar icon style preference ('solid' | 'line'). 'solid' is the
	 * default (the original filled/dark icons that match Shopify's admin); 'line'
	 * swaps in the thin outline set. Set from Settings > BrikPanel > Appearance.
	 * Statically cached — read once per request.
	 *
	 * @return string
	 */
	function brikpanel_nav_icon_style() {
		static $style = null;
		if ( $style === null ) {
			$saved = get_option( 'brikpanel_nav_icon_style', 'solid' );
			$style = ( $saved === 'line' ) ? 'line' : 'solid';
		}
		return $style;
	}
}

if ( ! function_exists( 'brikpanel_nav_icon_src' ) ) {
	/**
	 * Build the URL for a built-in sidebar icon, appending the plugin version as a
	 * cache-busting query. The icon SVGs are referenced with stable filenames, so
	 * without a version query a browser keeps serving a previously-cached copy —
	 * which means an icon redesign shipped in an update would stay invisible to
	 * existing users until their cache expired. The version bump on every release
	 * forces a fresh fetch.
	 *
	 * When the 'line' style is active the thin variant under icons/line/ is used,
	 * falling back to the solid icon whenever a line variant doesn't exist (so a
	 * brand icon without a line version still renders).
	 *
	 * @param string $file  Icon slug (filename without extension).
	 * @param string $style Optional explicit style ('solid'|'line'); defaults to the saved preference.
	 * @return string
	 */
	function brikpanel_nav_icon_src( $file, $style = null ) {
		if ( $style === null ) {
			$style = brikpanel_nav_icon_style();
		}
		$rel = 'icons/' . $file . '.svg';
		if ( $style === 'line' && file_exists( __DIR__ . '/icons/line/' . $file . '.svg' ) ) {
			$rel = 'icons/line/' . $file . '.svg';
		}
		return plugins_url( $rel, __FILE__ ) . '?ver=' . BRIKPANEL_VERSION;
	}
}

if ( ! function_exists( 'brikpanel_nav_line_icon_slugs' ) ) {
	/**
	 * List of icon slugs that ship a thin "line" variant under icons/line/. The
	 * customizer JS uses it to build the right icon URL for client-created rows
	 * when the line style is active. Statically cached.
	 *
	 * @return string[]
	 */
	function brikpanel_nav_line_icon_slugs() {
		static $slugs = null;
		if ( $slugs === null ) {
			$slugs = [];
			foreach ( (array) glob( __DIR__ . '/icons/line/*.svg' ) as $path ) {
				$slugs[] = basename( $path, '.svg' );
			}
		}
		return $slugs;
	}
}
