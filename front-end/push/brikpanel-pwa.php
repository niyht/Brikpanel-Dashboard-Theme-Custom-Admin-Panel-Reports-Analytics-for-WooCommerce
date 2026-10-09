<?php
/**
 * BrikPanel - the admin as a phone app (Add to Home Screen).
 *
 * - a Web App Manifest: named "BrikPanel" with the BrikPanel icon, opens
 *   full screen on the admin (WordPress sends store managers on to the
 *   BrikPanel dashboard)
 * - the service worker of the phone notifications (brikpanel-push-sw.js)
 * - the head tags: manifest link, theme colour, Apple touch icon and title
 * - in the installed app, the login keeps "Remember me" ticked: iPhones drop
 *   session cookies of Home Screen apps now and then (WebKit bug 272325)
 *
 * Both files are served by admin-ajax.php. It lives in the admin folder, so
 * the worker may control the admin pages without any server header, no
 * rewrite rule is needed, and caching plugins, CDNs and "no PHP in plugin
 * folders" security settings leave it alone. Both are cached for a day: the
 * worker registers with updateViaCache "all", so a phone asks for it about
 * once a day instead of on every admin page.
 *
 * iPhones never refresh an installed app's name or icon: the manifest id,
 * name and icons below stay as they are.
 *
 * @package BrikPanel
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Raised only when the icon files change (Android refreshes installed icons; iPhone never does).
if ( ! defined( 'BRIKPANEL_PWA_ICON_VERSION' ) ) {
	define( 'BRIKPANEL_PWA_ICON_VERSION', '1' );
}

/**
 * The WordPress install's path, with a trailing slash: the app's scope. It
 * holds both /wp-admin/ and wp-login.php, so logging in again (and a
 * two-factor step) stays inside the app instead of a browser sheet.
 *
 * @return string
 */
function brikpanel_pwa_scope() {
	$path = (string) wp_parse_url( site_url( '/' ), PHP_URL_PATH );
	return '/' . ltrim( trailingslashit( $path ), '/' );
}

/** @return string The admin folder's path, e.g. /wp-admin/. */
function brikpanel_pwa_admin_path() {
	$path = (string) wp_parse_url( admin_url( '/' ), PHP_URL_PATH );
	return '/' . ltrim( trailingslashit( $path ), '/' );
}

/**
 * @param string $file File in assets/pwa/.
 * @return string
 */
function brikpanel_pwa_icon_url( $file ) {
	return BRIKPANEL_URL . 'assets/pwa/' . $file . '?v=' . BRIKPANEL_PWA_ICON_VERSION;
}

/** @return string */
function brikpanel_pwa_theme_color() {
	$color = function_exists( 'brikpanel_appearance_get_primary_color' ) ? (string) brikpanel_appearance_get_primary_color() : '';
	return preg_match( '/^#[0-9a-fA-F]{3,8}$/', $color ) ? $color : '#303030';
}

/** @return string */
function brikpanel_pwa_manifest_url() {
	return admin_url( 'admin-ajax.php?action=brikpanel_manifest' );
}

/**
 * The PWA feature plugin owns the admin folder's service worker: BrikPanel's
 * notification handlers are added to its worker instead of a second one.
 *
 * @return bool
 */
function brikpanel_pwa_shared_worker() {
	return function_exists( 'wp_register_service_worker_script' );
}

/**
 * Changes when the worker code, BrikPanel's version or the site language changes.
 *
 * @return string
 */
function brikpanel_pwa_worker_version() {
	$file = __DIR__ . '/brikpanel-push-sw.js';
	return substr( md5( BRIKPANEL_VERSION . '|' . ( file_exists( $file ) ? filemtime( $file ) : 0 ) . '|' . get_locale() ), 0, 10 );
}

/** @return string */
function brikpanel_pwa_worker_url() {
	return admin_url( 'admin-ajax.php?action=brikpanel_push_sw&v=' . brikpanel_pwa_worker_version() );
}

/**
 * The worker's code: its settings, then the static file. Always in the
 * site's language, so every person gets the same bytes and the worker does
 * not reinstall per visitor.
 *
 * @param bool $shared Next to the PWA plugin's worker.
 * @return string
 */
function brikpanel_pwa_worker_source( $shared = false ) {
	$switched = false;
	if ( function_exists( 'determine_locale' ) && determine_locale() !== get_locale() ) {
		$switched = switch_to_locale( get_locale() );
	}
	$config = array(
		'receipt' => admin_url( 'admin-ajax.php' ),
		'start'   => admin_url(),
		'icon'    => brikpanel_pwa_icon_url( 'brikpanel-192.png' ),
		'badge'   => brikpanel_pwa_icon_url( 'brikpanel-badge-96.png' ),
		// Only shown when a message cannot be read: it still must show something.
		'title'   => __( 'New order!', 'brikpanel' ),
		'shared'  => (bool) $shared,
	);
	if ( $switched ) {
		restore_previous_locale();
	}
	$code = (string) file_get_contents( __DIR__ . '/brikpanel-push-sw.js' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local plugin file.
	return 'self.BRIKPANEL_PUSH = ' . wp_json_encode( $config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . ";\n" . $code;
}

/**
 * Replaces admin-ajax.php's no-cache headers with a day of private caching.
 *
 * @param string $type Content type.
 * @param string $etag Optional ETag.
 * @return void
 */
function brikpanel_pwa_cache_headers( $type, $etag = '' ) {
	if ( headers_sent() ) {
		return;
	}
	header_remove( 'Expires' );
	header_remove( 'Pragma' );
	header_remove( 'Last-Modified' );
	header( 'Content-Type: ' . $type );
	header( 'Cache-Control: private, max-age=86400' );
	if ( '' !== $etag ) {
		header( 'ETag: "' . $etag . '"' );
	}
}

/**
 * admin-ajax.php?action=brikpanel_manifest: the Web App Manifest. Asked for
 * without cookies, so it answers logged-out requests too; it holds nothing
 * private.
 *
 * @return void
 */
function brikpanel_pwa_serve_manifest() {
	$manifest = array(
		'id'               => brikpanel_pwa_admin_path() . 'brikpanel-app',
		'name'             => 'BrikPanel',
		'short_name'       => 'BrikPanel',
		'start_url'        => admin_url(),
		'scope'            => brikpanel_pwa_scope(),
		'display'          => 'standalone',
		'background_color' => '#f1f1f1',
		'theme_color'      => brikpanel_pwa_theme_color(),
		'lang'             => str_replace( '_', '-', get_locale() ),
		'dir'              => is_rtl() ? 'rtl' : 'ltr',
		'icons'            => array(
			array(
				'src'     => brikpanel_pwa_icon_url( 'brikpanel-192.png' ),
				'sizes'   => '192x192',
				'type'    => 'image/png',
				'purpose' => 'any',
			),
			array(
				'src'     => brikpanel_pwa_icon_url( 'brikpanel-512.png' ),
				'sizes'   => '512x512',
				'type'    => 'image/png',
				'purpose' => 'any',
			),
			array(
				'src'     => brikpanel_pwa_icon_url( 'brikpanel-maskable-512.png' ),
				'sizes'   => '512x512',
				'type'    => 'image/png',
				'purpose' => 'maskable',
			),
		),
	);
	brikpanel_pwa_cache_headers( 'application/manifest+json; charset=utf-8' );
	echo wp_json_encode( $manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON document.
	exit;
}
add_action( 'wp_ajax_brikpanel_manifest', 'brikpanel_pwa_serve_manifest' );
add_action( 'wp_ajax_nopriv_brikpanel_manifest', 'brikpanel_pwa_serve_manifest' );

/**
 * admin-ajax.php?action=brikpanel_push_sw: the service worker. The browser
 * fetches a worker for its update checks with or without cookies, so this
 * also answers logged-out requests; it holds nothing private.
 *
 * @return void
 */
function brikpanel_pwa_serve_worker() {
	$body = brikpanel_pwa_worker_source( false );
	$etag = md5( $body );
	brikpanel_pwa_cache_headers( 'text/javascript; charset=utf-8', $etag );
	$sent = isset( $_SERVER['HTTP_IF_NONE_MATCH'] ) ? trim( sanitize_text_field( wp_unslash( $_SERVER['HTTP_IF_NONE_MATCH'] ) ), '" ' ) : '';
	if ( $sent === $etag ) {
		status_header( 304 );
		exit;
	}
	echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JavaScript file.
	exit;
}
add_action( 'wp_ajax_brikpanel_push_sw', 'brikpanel_pwa_serve_worker' );
add_action( 'wp_ajax_nopriv_brikpanel_push_sw', 'brikpanel_pwa_serve_worker' );

/**
 * Next to the PWA plugin: BrikPanel's handlers join its admin worker.
 *
 * @param object $scripts WP_Service_Worker_Scripts.
 * @return void
 */
function brikpanel_pwa_register_shared_worker( $scripts ) {
	if ( ! is_object( $scripts ) || ! method_exists( $scripts, 'register' ) ) {
		return;
	}
	$scripts->register(
		'brikpanel-push',
		array(
			'src' => static function () {
				return brikpanel_pwa_worker_source( true );
			},
		)
	);
}
add_action( 'wp_admin_service_worker', 'brikpanel_pwa_register_shared_worker' );

/**
 * Whether this person sees BrikPanel's screens here (the app is theirs).
 *
 * @return bool
 */
function brikpanel_pwa_for_current_user() {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		return false;
	}
	return ! ( function_exists( 'brikpanel_access_should_neutralize' ) && brikpanel_access_should_neutralize() );
}

/**
 * The tags that make "Add to Home Screen" install BrikPanel.
 *
 * @return void
 */
function brikpanel_pwa_print_tags() {
	printf( '<link rel="manifest" href="%s">' . "\n", esc_url( brikpanel_pwa_manifest_url() ) );
	printf( '<meta name="theme-color" content="%s">' . "\n", esc_attr( brikpanel_pwa_theme_color() ) );
	printf( '<link rel="apple-touch-icon" href="%s">' . "\n", esc_url( brikpanel_pwa_icon_url( 'brikpanel-180.png' ) ) );
	echo '<meta name="apple-mobile-web-app-title" content="BrikPanel">' . "\n";
	echo '<meta name="apple-mobile-web-app-status-bar-style" content="default">' . "\n";
	// A browser install bar that opens by itself would jump the one-ask-at-a-
	// time line (includes/brikpanel-asks.php). The offer is kept for the
	// "Install BrikPanel" button the phone card shows after notifications are on.
	echo "<script>window.addEventListener('beforeinstallprompt',function(e){e.preventDefault();window.brikpanelInstallPrompt=e;});</script>\n";
}

/** @return void */
function brikpanel_pwa_admin_head() {
	if ( brikpanel_pwa_for_current_user() ) {
		brikpanel_pwa_print_tags();
	}
}
add_action( 'admin_head', 'brikpanel_pwa_admin_head', 2 );

/**
 * The login page carries the same tags: re-logging in inside the installed
 * app must not look like another site.
 *
 * @return void
 */
function brikpanel_pwa_login_head() {
	brikpanel_pwa_print_tags();
}
add_action( 'login_head', 'brikpanel_pwa_login_head', 2 );

/** @return bool */
function brikpanel_pwa_is_login_page() {
	if ( function_exists( 'is_login' ) ) {
		return (bool) is_login();
	}
	return isset( $GLOBALS['pagenow'] ) && 'wp-login.php' === $GLOBALS['pagenow'];
}

/**
 * WordPress prints the Site Icon as the Apple touch icon in the admin too.
 * The installed app is BrikPanel (owner decision, 7 October 2026): there the
 * BrikPanel icon is used. The browser tab's favicon stays the site's.
 *
 * @param string[] $tags Meta tags.
 * @return string[]
 */
function brikpanel_pwa_site_icon_tags( $tags ) {
	if ( ! is_admin() && ! brikpanel_pwa_is_login_page() ) {
		return $tags;
	}
	if ( is_admin() && ! brikpanel_pwa_for_current_user() ) {
		return $tags;
	}
	return array_values(
		array_filter(
			(array) $tags,
			static function ( $tag ) {
				return false === stripos( (string) $tag, 'apple-touch-icon' ) && false === stripos( (string) $tag, 'msapplication-TileImage' );
			}
		)
	);
}
add_filter( 'site_icon_meta_tags', 'brikpanel_pwa_site_icon_tags' );

/**
 * Inside the installed app the login keeps "Remember me" ticked (owner
 * decision, 7 October 2026). A normal browser tab is left as it is.
 *
 * @return void
 */
function brikpanel_pwa_login_footer() {
	echo "<script>(function(){try{if(!(window.matchMedia('(display-mode: standalone)').matches||window.navigator.standalone===true))return;var r=document.getElementById('rememberme');if(r){r.checked=true;}}catch(e){}})();</script>\n"; // i18n-ignore: media query, not user text
}
add_action( 'login_footer', 'brikpanel_pwa_login_footer' );
