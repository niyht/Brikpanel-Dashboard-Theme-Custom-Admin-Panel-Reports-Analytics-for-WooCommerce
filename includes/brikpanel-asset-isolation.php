<?php
/**
 * BrikPanel - Foreign Asset Isolation
 *
 * BrikPanel's own full-screen app pages (Dashboard, Segments, Customer
 * Analytics, Abandoned Carts, Expenses, BrikControl, Ad Platforms, Coupons,
 * Suppliers, …) are self-contained: they render entirely from BrikPanel's own
 * markup, styles and scripts. Third-party plugins have no UI to render there.
 *
 * Yet WordPress fires `admin_enqueue_scripts` on *every* admin screen, and many
 * plugins enqueue their whole payload unconditionally. AI Engine, for example,
 * registers its ~1.5 MB React bundle (index.js + vendor.js) in the document
 * `<head>` with neither `defer` nor `async` on every admin page — so on a
 * BrikPanel app page the browser must download and execute those files, which
 * do nothing there, before it can paint the page. On a slow connection the
 * dashboard stays blank for seconds. That is the "you can't work through your
 * dashboard" symptom users hit when running BrikPanel alongside a plugin like
 * AI Engine.
 *
 * This layer removes that dead weight: on BrikPanel's own app pages it dequeues
 * scripts/styles served from other plugins and from the active theme. It is
 * deliberately conservative:
 *
 *   - Only local `wp-content/plugins/*` (except BrikPanel and the WooCommerce
 *     core platform) and `wp-content/themes/*` assets are candidates. WordPress
 *     core (`wp-includes` / `wp-admin`, e.g. jQuery and wp-components) and any
 *     externally hosted asset (e.g. the Google API the Sheets page loads from
 *     apis.google.com) are always kept.
 *   - It only *dequeues*; it never *deregisters*. So if a kept BrikPanel asset
 *     genuinely lists a foreign handle as a dependency, WordPress' own
 *     dependency resolution re-adds it and nothing breaks.
 *   - Pages that intentionally embed third-party UI — the product editor (SEO
 *     metaboxes, product-data panels) and the products list (plugin columns) —
 *     are excluded entirely.
 *
 * Everything is filterable so site owners and integrators can opt a page or a
 * handle back in.
 *
 * @package BrikPanel
 * @since 3.2.11
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The current BrikPanel-owned admin page slug, or '' when the request is not on
 * one of BrikPanel's own `admin.php?page=brikpanel-*` app pages.
 *
 * WooCommerce-settings tabs (`page=wc-settings&tab=…`), the native post editors
 * and every non-BrikPanel screen never match, so they are left untouched.
 *
 * @return string
 */
function brikpanel_isolation_current_page() {
	if ( ! is_admin() || wp_doing_ajax() ) {
		return '';
	}
	$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	return ( strpos( $page, 'brikpanel-' ) === 0 ) ? $page : '';
}

/**
 * Whether foreign-asset isolation should run for the current request.
 *
 * @return bool
 */
function brikpanel_isolation_active() {
	$page = brikpanel_isolation_current_page();
	if ( '' === $page ) {
		return false;
	}

	/**
	 * BrikPanel pages that intentionally host third-party UI and therefore must
	 * keep foreign assets. The product editor surfaces SEO metaboxes and
	 * product-data panels from other plugins; the products list renders their
	 * custom columns.
	 *
	 * @param string[] $pages Excluded page slugs.
	 */
	$excluded = apply_filters(
		'brikpanel_isolation_excluded_pages',
		array(
			'brikpanel-product-editor',
			'brikpanel-products',
		)
	);
	if ( in_array( $page, (array) $excluded, true ) ) {
		return false;
	}

	/**
	 * Master switch for the whole feature (per page).
	 *
	 * @param bool   $enabled Whether to isolate foreign assets on this page.
	 * @param string $page    Current BrikPanel page slug.
	 */
	return (bool) apply_filters( 'brikpanel_isolate_foreign_assets', true, $page );
}

/**
 * The active theme directory names (parent + child) so their assets can be
 * matched regardless of the site's stylesheet/template layout.
 *
 * @return string[] Lower-cased directory names, e.g. ['hello-elementor'].
 */
function brikpanel_isolation_theme_dirs() {
	$dirs = array();
	foreach ( array( get_stylesheet(), get_template() ) as $dir ) {
		$dir = strtolower( (string) $dir );
		if ( '' !== $dir ) {
			$dirs[ $dir ] = true;
		}
	}
	return array_keys( $dirs );
}

/**
 * Decide whether an asset `src` belongs to another plugin or the theme and so
 * should be stripped on a BrikPanel app page.
 *
 * Matching is done on path segments (`/wp-content/plugins/…`, `/wp-content/
 * themes/…`) which are present whether the src is absolute, protocol-relative
 * or root-relative. Core assets and externally hosted assets contain neither
 * segment and are therefore always kept.
 *
 * @param string $src        Registered asset URL.
 * @param string $bp_dirname BrikPanel plugin directory name.
 * @return bool True when the asset is foreign (safe to dequeue).
 */
function brikpanel_isolation_is_foreign_src( $src, $bp_dirname ) {
	if ( ! is_string( $src ) || '' === $src ) {
		return false;
	}
	$s = strtolower( $src );

	// Theme assets.
	if ( false !== strpos( $s, '/wp-content/themes/' ) ) {
		return true;
	}

	// Plugin assets, minus the two trusted origins.
	if ( false !== strpos( $s, '/wp-content/plugins/' ) ) {
		// BrikPanel's own assets.
		if ( '' !== $bp_dirname && false !== strpos( $s, '/wp-content/plugins/' . $bp_dirname . '/' ) ) {
			return false;
		}
		// WooCommerce core platform (never the extension plugins named
		// `woocommerce-*`, which have no UI on BrikPanel pages).
		if ( false !== strpos( $s, '/wp-content/plugins/woocommerce/' ) ) {
			return false;
		}
		return true;
	}

	// Core (wp-includes / wp-admin) and external hosts: keep.
	return false;
}

/**
 * Dequeue foreign plugin/theme scripts and styles on BrikPanel's own app pages.
 *
 * Runs at PHP_INT_MAX so every plugin has already enqueued. Dequeue-only: the
 * dependency graph is left intact, and anything a kept asset truly needs is
 * re-added by WordPress at output time.
 */
function brikpanel_isolation_sweep_assets() {
	if ( ! brikpanel_isolation_active() ) {
		return;
	}

	$bp_dirname = strtolower( basename( untrailingslashit( defined( 'BRIKPANEL_PATH' ) ? BRIKPANEL_PATH : __DIR__ ) ) );

	foreach ( array( wp_scripts(), wp_styles() ) as $assets ) {
		if ( ! $assets instanceof WP_Dependencies ) {
			continue;
		}
		$is_scripts = ( $assets instanceof WP_Scripts );

		// Snapshot: dequeue mutates $assets->queue while we iterate.
		$queue = (array) $assets->queue;
		foreach ( $queue as $handle ) {
			$dep = isset( $assets->registered[ $handle ] ) ? $assets->registered[ $handle ] : null;
			if ( ! $dep ) {
				continue;
			}
			$src = isset( $dep->src ) ? (string) $dep->src : '';
			if ( '' === $src ) {
				// Inline-only / core alias handle (e.g. 'jquery'): leave alone.
				continue;
			}
			if ( ! brikpanel_isolation_is_foreign_src( $src, $bp_dirname ) ) {
				continue;
			}

			/**
			 * Force-keep a specific handle that would otherwise be stripped.
			 *
			 * @param bool   $keep   Whether to keep the asset. Default false.
			 * @param string $handle Asset handle.
			 * @param string $src    Asset src.
			 * @param bool   $is_js  True for scripts, false for styles.
			 */
			if ( apply_filters( 'brikpanel_isolation_keep_handle', false, $handle, $src, $is_scripts ) ) {
				continue;
			}

			if ( $is_scripts ) {
				wp_dequeue_script( $handle );
			} else {
				wp_dequeue_style( $handle );
			}
		}
	}
}
add_action( 'admin_enqueue_scripts', 'brikpanel_isolation_sweep_assets', PHP_INT_MAX );
