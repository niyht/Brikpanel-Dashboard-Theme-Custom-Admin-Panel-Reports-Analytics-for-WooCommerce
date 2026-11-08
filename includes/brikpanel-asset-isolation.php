<?php
/**
 * BrikPanel - Foreign Asset Isolation
 *
 * BrikPanel's own full-screen app pages (Dashboard, Segments, Customer
 * Analytics, Abandoned Carts, Expenses, BrikControl, Ad Platforms, Coupons,
 * Suppliers, …) are built from BrikPanel's own markup, styles and scripts.
 *
 * Yet WordPress fires `admin_enqueue_scripts` on *every* admin screen, and many
 * plugins enqueue their whole payload unconditionally. AI Engine, for example,
 * registers its ~1.5 MB React bundle (index.js + vendor.js) in the document
 * `<head>` with neither `defer` nor `async` on every admin page, so on a
 * BrikPanel app page the browser must download and execute those files, which
 * do nothing there, before it can paint the page. On a slow connection the
 * dashboard stays blank for seconds. That is the "you can't work through your
 * dashboard" symptom users hit when running BrikPanel alongside a plugin like
 * AI Engine.
 *
 * This layer removes that dead weight: on BrikPanel's own app pages it dequeues
 * the scripts served from other plugins and from the active theme.
 *
 * Stylesheets are left alone. Other plugins still draw on these pages through
 * the hooks WordPress runs on every admin screen: their admin notices (on the
 * page, or collected into the top bar's bell), their sidebar menu icons, the
 * WordPress dashboard widgets the dashboard can embed, and markup printed in the
 * footer. The sweep runs before any of that is printed, so it cannot know which
 * stylesheet will be needed, and markup without its stylesheet falls back to
 * WordPress's bare defaults. Field test B9: CURCY's notice kept its markup but
 * lost `villatheme-support.min.css`, so its "Dismiss" link took WordPress's
 * absolutely positioned 24px dismiss box, left the notice for the page's top
 * corner, sat on the dashboard's date buttons and was cut to "Dism" at the
 * window edge. The Porto theme's hidden "New Porto Builder" form (printed by the
 * porto-functionality plugin, hidden by the theme's Magnific Popup stylesheet)
 * had shown at the foot of the page the same way. A stylesheet runs no code and
 * never held the page back the way that script bundle did: keeping them costs
 * about 100 KB compressed on a heavy store (15 files, measured 2026-09-25),
 * cached after the first visit, and every other admin screen loads them anyway.
 *
 * Known limit: a foreign notice whose button needs its plugin's script still
 * finds that script missing here. `brikpanel_isolation_keep_handle` keeps one
 * handle (BrikMentor's update notice uses it), and BrikMentor's whole folder is
 * trusted, see brikpanel_isolation_trusted_dirs().
 *
 * It is deliberately conservative:
 *
 *   - Only local `wp-content/plugins/*` (except BrikPanel, the WooCommerce
 *     core platform and BrikMentor, the sibling product whose controls do
 *     appear on these screens) and `wp-content/themes/*` scripts are
 *     candidates. WordPress
 *     core (`wp-includes` / `wp-admin`, e.g. jQuery and wp-components) and any
 *     externally hosted asset (e.g. the Google API the Sheets page loads from
 *     apis.google.com) are always kept.
 *   - It only *dequeues*; it never *deregisters*. So if a kept BrikPanel asset
 *     genuinely lists a foreign handle as a dependency, WordPress' own
 *     dependency resolution re-adds it and nothing breaks.
 *   - Pages that intentionally embed third-party UI, the product editor (SEO
 *     metaboxes, product-data panels) and the products list (plugin columns),
 *     are excluded entirely.
 *
 * Everything is filterable so site owners and integrators can opt a page or a
 * handle back in.
 *
 * One exception to "core is always kept", on phones only: a short list of
 * desktop-only scripts from WordPress and WooCommerce (the Ctrl+K command
 * palette and the data layer it searches), see brikpanel_isolation_sweep_phone().
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
	 * keep foreign scripts (stylesheets are kept on every page). The product
	 * editor surfaces SEO metaboxes and product-data panels from other plugins;
	 * the products list renders their custom columns.
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

	// Plugin assets, minus the three trusted origins.
	if ( false !== strpos( $s, '/wp-content/plugins/' ) ) {
		foreach ( brikpanel_isolation_trusted_dirs( $bp_dirname ) as $dir ) {
			if ( false !== strpos( $s, '/wp-content/plugins/' . $dir . '/' ) ) {
				return false;
			}
		}
		return true;
	}

	// Core (wp-includes / wp-admin) and external hosts: keep.
	return false;
}

/**
 * The folder WordPress serves a plugin's files from, lower-cased, or ''.
 *
 * Read from the plugin's basename ('folder/main.php'), never from its path on
 * disk. The basename is what WordPress builds the plugin's URLs from: it maps a
 * symlink back to the name the site sees, while a path taken from __FILE__ has
 * already been resolved to the symlink's target. And its folder is whatever
 * this site called it: WooCommerce sits in `woocommerce/` only when it came
 * from wp.org.
 *
 * @param string $basename Plugin basename, e.g. 'wc-core/woocommerce.php'.
 * @return string e.g. 'wc-core'; '' for a single-file plugin or no basename.
 */
function brikpanel_isolation_plugin_dir( $basename ) {
	$dir = is_string( $basename ) && '' !== $basename ? dirname( $basename ) : '.';
	return '.' === $dir ? '' : strtolower( $dir );
}

/**
 * The plugin folders whose assets stay on BrikPanel's app pages, lower-cased.
 *
 * - BrikPanel itself.
 * - The WooCommerce core platform, never the extension plugins named
 *   `woocommerce-*`, which have no UI on BrikPanel pages (the trailing slash
 *   the caller matches with keeps them out).
 * - BrikMentor, the sibling product. It draws controls on our screens that
 *   need their script behind them - its "Update to X now" notice button for
 *   one, which this sweep left rendered but dead on the dashboard (measured
 *   2026-09-17: button present, script tag gone).
 *
 * Each plugin is trusted under both folder names its files can be addressed by:
 * - the folder WordPress serves it from, read from its basename constant, so a
 *   renamed or symlinked install still matches. WooCommerce used to be the
 *   literal `woocommerce`: with WooCommerce in `wc-core/`, every WooCommerce
 *   script and style was stripped from BrikPanel's pages;
 * - the folder it really lives in, read from its path on disk. Code that builds
 *   a URL from its own real path names that folder even behind a symlink: the
 *   abilities-api package WooCommerce bundles does exactly that, and so did this
 *   sweep for BrikPanel and BrikMentor before, so nothing it kept is lost.
 * The bare names are what a plugin that is not loaded falls back to; its assets
 * cannot be queued then anyway.
 *
 * @param string $bp_dirname BrikPanel's folder, as the sweep passes it.
 * @return string[]
 */
function brikpanel_isolation_trusted_dirs( $bp_dirname ) {
	$wc_loaded = defined( 'WC_PLUGIN_BASENAME' ) && defined( 'WC_PLUGIN_FILE' );
	$bm_loaded = defined( 'BRIKMENTOR_BASENAME' ) && defined( 'BRIKMENTOR_PATH' );

	$dirs = array(
		(string) $bp_dirname,
		defined( 'BRIKPANEL_PATH' ) ? basename( untrailingslashit( BRIKPANEL_PATH ) ) : '',
		$wc_loaded ? brikpanel_isolation_plugin_dir( WC_PLUGIN_BASENAME ) : 'woocommerce',
		$wc_loaded ? basename( dirname( WC_PLUGIN_FILE ) ) : '',
		$bm_loaded ? brikpanel_isolation_plugin_dir( BRIKMENTOR_BASENAME ) : 'brikmentor',
		$bm_loaded ? basename( untrailingslashit( BRIKMENTOR_PATH ) ) : '',
	);

	return array_values( array_unique( array_filter( array_map( 'strtolower', $dirs ), 'strlen' ) ) );
}

/**
 * Dequeue foreign plugin/theme scripts on BrikPanel's own app pages.
 *
 * Runs at PHP_INT_MAX so every plugin has already enqueued. Dequeue-only: the
 * dependency graph is left intact, and anything a kept asset truly needs is
 * re-added by WordPress at output time.
 *
 * Stylesheets are not swept: the notices, sidebar icons, dashboard widgets and
 * footer markup other plugins still print on these pages need them (field test
 * B9, see the file header).
 */
function brikpanel_isolation_sweep_assets() {
	if ( ! brikpanel_isolation_active() ) {
		return;
	}

	$scripts = wp_scripts();
	if ( ! $scripts instanceof WP_Scripts ) {
		return;
	}

	// From the basename, like the other trusted folders: BRIKPANEL_PATH is the
	// symlink target, so a symlinked install named differently from its target
	// had its own scripts and styles stripped here.
	$bp_dirname = brikpanel_isolation_plugin_dir(
		defined( 'BRIKPANEL_BASENAME' ) ? BRIKPANEL_BASENAME : plugin_basename( dirname( __DIR__ ) . '/brikpanel.php' )
	);

	// Snapshot: dequeue mutates $scripts->queue while we iterate.
	$queue = (array) $scripts->queue;
	foreach ( $queue as $handle ) {
		$dep = isset( $scripts->registered[ $handle ] ) ? $scripts->registered[ $handle ] : null;
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
		 * @param bool   $is_js  Always true: only scripts are swept (field test B9).
		 */
		if ( apply_filters( 'brikpanel_isolation_keep_handle', false, $handle, $src, true ) ) {
			continue;
		}

		wp_dequeue_script( $handle );
	}
}
add_action( 'admin_enqueue_scripts', 'brikpanel_isolation_sweep_assets', PHP_INT_MAX );

/**
 * Whether the current admin screen is WooCommerce's order list or order
 * screen (HPOS or the posts table): the phone app spends most of its time
 * there, and BrikPanel draws them although they are WooCommerce's pages.
 *
 * @return bool
 */
function brikpanel_isolation_is_orders_screen() {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( ! $screen || ! isset( $screen->id ) ) {
		return false;
	}
	$ids = array( 'woocommerce_page_wc-orders', 'edit-shop_order', 'shop_order' );
	if ( function_exists( 'wc_get_page_screen_id' ) ) {
		$ids[] = (string) wc_get_page_screen_id( 'shop-order' );
	}
	return in_array( (string) $screen->id, $ids, true );
}

/**
 * Phones: leave out the scripts a phone has no use for on BrikPanel's screens.
 *
 * WordPress 7 and WooCommerce load their Ctrl+K command palette on every admin
 * screen, and with it the data layer it searches (`wp-abilities`, `wc-entities`).
 * All of them lean on `wp-core-data`, which depends on the whole block editor:
 * on the BrikPanel dashboard of a live store that was 45 of its 113 files and
 * 3.3 MB of its 5.8 MB (measured 2026-11-07). A phone has no keyboard to open
 * the palette, yet it read all of that before the screen answered a tap: with a
 * phone's processor on 4G the dashboard was ready after 6 seconds and the product
 * screen after 15. The product screen also leaves out two scripts that cannot run
 * there: WooCommerce's guided tour of its classic product editor, which pulls in
 * WooCommerce's whole admin app, and Yoast SEO's AI content planner, a banner
 * drawn on the first block of the block editor that pulls in the whole block
 * editor (Yoast's SEO card keeps its own scripts). On a copy of that store, with
 * a phone's processor on 4G and an empty cache, the dashboard downloaded 1.7 MB
 * instead of 2.3 and was ready 2.3 seconds sooner; the product screen downloaded
 * 3.8 MB instead of 5.0, was ready 1.4 seconds sooner and had no block editor
 * left on it (measured 2026-11-08).
 *
 * Phones only (wp_is_mobile(); an iPad presents itself as a Mac and keeps
 * everything), on BrikPanel's own pages and the order screens. Dequeue only: a
 * script that needs one of these names it as a dependency and WordPress loads
 * it back. Owner decision (8 November 2026): no setting; the list is filterable.
 *
 * @return void
 */
function brikpanel_isolation_sweep_phone() {
	if ( ! is_admin() || wp_doing_ajax() || ! function_exists( 'wp_is_mobile' ) || ! wp_is_mobile() ) {
		return;
	}
	// People the BrikPanel interface is switched off for see the native admin.
	if ( function_exists( 'brikpanel_access_should_neutralize' ) && brikpanel_access_should_neutralize() ) {
		return;
	}
	$page = brikpanel_isolation_current_page();
	if ( '' === $page && ! brikpanel_isolation_is_orders_screen() ) {
		return;
	}

	$handles = array(
		'wp-core-commands',
		'wc-admin-command-palette',
		'wc-admin-command-palette-analytics',
		'wp-abilities',
		'wc-entities',
	);
	if ( 'brikpanel-product-editor' === $page ) {
		$handles[] = 'wc-admin-variable-product-tour';
		$handles[] = 'yoast-seo-ai-content-planner';
	}

	/**
	 * Scripts left out on a phone on BrikPanel's screens and the order screens.
	 *
	 * @since 3.3.48
	 *
	 * @param string[] $handles Script handles to dequeue.
	 * @param string   $page    BrikPanel page slug, '' on an order screen.
	 */
	$handles = apply_filters( 'brikpanel_phone_skip_scripts', $handles, $page );
	foreach ( (array) $handles as $handle ) {
		if ( is_string( $handle ) && '' !== $handle ) {
			wp_dequeue_script( $handle );
		}
	}
}
add_action( 'admin_enqueue_scripts', 'brikpanel_isolation_sweep_phone', PHP_INT_MAX );
