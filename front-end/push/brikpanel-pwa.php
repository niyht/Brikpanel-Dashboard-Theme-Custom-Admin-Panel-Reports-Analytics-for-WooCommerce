<?php
/**
 * BrikPanel - the admin as a phone app (Add to Home Screen).
 *
 * - a Web App Manifest: named after the store with its Site Icon (owner
 *   decision, 30 October 2026, after two customers asked: "BrikPanel" on the
 *   phone confused their staff and stores with two shops got two identical
 *   apps), BrikPanel's own icon when the site has none; opens full screen on
 *   the admin (WordPress sends store managers on to the BrikPanel dashboard)
 * - the service worker of the phone notifications (brikpanel-push-sw.js)
 * - the head tags: manifest link, theme colour, Apple touch icon and title
 * - the Apple touch icon on white (brikpanel_pwa_serve_app_icon()): an iPhone
 *   paints the transparent pixels of a Home Screen icon black
 * - in the installed app, the login keeps "Remember me" ticked: iPhones drop
 *   session cookies of Home Screen apps now and then (WebKit bug 272325)
 * - the way back in after the login ends, also when a security plugin hides
 *   the login page: the entrance the app starts on, the login address the
 *   phone remembers and the app cookie (brikpanel-pwa-entry.php)
 *
 * Both files are served by admin-ajax.php. It lives in the admin folder, so
 * the worker may control the admin pages without any server header, no
 * rewrite rule is needed, and caching plugins, CDNs and "no PHP in plugin
 * folders" security settings leave it alone. Both are cached for a day: the
 * worker registers with updateViaCache "all", so a phone asks for it about
 * once a day instead of on every admin page.
 *
 * iPhones never refresh an installed app's name or icon (a new name reaches an
 * iPhone only when the app is added again); Chrome on Android asks the person
 * to accept a new name or icon. The manifest id never changes: it is what makes
 * the renamed app the same app.
 *
 * @package BrikPanel
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Raised only when the icon files change. An installed app keeps its old icon when the new one
// differs by more than about 10% (Chrome asks nobody and changes nothing); iPhone never refreshes.
// 2 (27 October 2026): 8-bit colour files (the old maskable icon was 16-bit grey), the grid at 48%
// of the maskable canvas instead of 56%, a 192 px maskable icon, a larger two-tone badge.
if ( ! defined( 'BRIKPANEL_PWA_ICON_VERSION' ) ) {
	define( 'BRIKPANEL_PWA_ICON_VERSION', '2' );
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

/**
 * @param string $text Text.
 * @param int    $max  Characters.
 * @return string
 */
function brikpanel_pwa_clip( $text, $max ) {
	if ( brikpanel_strlen( $text ) <= $max ) {
		return $text;
	}
	return rtrim( brikpanel_substr( $text, 0, $max - 1 ) ) . '…';
}

/**
 * The app's name: the store's Site Title (Settings > General), so the phone
 * shows the store, as the store's staff look for it. "BrikPanel" only when
 * the site has no title.
 *
 * @return string Plain text.
 */
function brikpanel_pwa_app_name() {
	$name = html_entity_decode( wp_strip_all_tags( wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ) ), ENT_QUOTES, 'UTF-8' );
	$name = trim( (string) preg_replace( '/\s+/u', ' ', $name ) );
	if ( '' === $name ) {
		return 'BrikPanel'; // i18n-ignore: product name
	}
	return brikpanel_pwa_clip( $name, 60 );
}

/**
 * The name under the icon: a phone shows about twelve characters there, so a
 * title like "Northwind Goods – Outdoor Gear" gives "Northwind Goods" (the
 * store's name comes before the separator). The full name stays in the app's
 * details and its install sheet. Not cut any shorter than the name: the phone
 * shortens it under the icon by itself, and a cut here would stay in the
 * steps and on an iPhone's suggested name.
 *
 * @return string Plain text.
 */
function brikpanel_pwa_app_short_name() {
	$name = brikpanel_pwa_app_name();
	$cut  = null;
	foreach ( array( " \u{2013} ", " \u{2014} ", ' - ', ' | ', " \u{00B7} ", ': ' ) as $separator ) {
		$at = strpos( $name, $separator );
		if ( false !== $at && ( null === $cut || $at < $cut ) ) {
			$cut = $at;
		}
	}
	if ( null !== $cut ) {
		$head = trim( substr( $name, 0, $cut ) );
		if ( brikpanel_strlen( $head ) >= 2 ) {
			$name = $head;
		}
	}
	return $name;
}

/**
 * The app's icons: the store's Site Icon (Settings > General, which WordPress
 * keeps at 512, 270, 192, 180 and 32 pixels), else BrikPanel's own set.
 * 'store' says which. The notification badge is never the store's: Android
 * draws only its transparency, and a logo would be a white square.
 *
 * Not cached here: WordPress keeps the attachment in its object cache, and
 * its own head tags make the same calls on every admin page.
 *
 * @return array{store:bool,192:string,512:string,180:string}
 */
function brikpanel_pwa_app_icons() {
	$id   = (int) get_option( 'site_icon' );
	$s192 = $id > 0 ? (string) get_site_icon_url( 192 ) : '';
	$s512 = $id > 0 ? (string) get_site_icon_url( 512 ) : '';
	return ( '' !== $s192 && '' !== $s512 )
		? array(
			'store' => true,
			'192'   => $s192,
			'512'   => $s512,
			'180'   => brikpanel_pwa_apple_icon_url( $id ),
		)
		: array(
			'store' => false,
			'192'   => brikpanel_pwa_icon_url( 'brikpanel-192.png' ),
			'512'   => brikpanel_pwa_icon_url( 'brikpanel-512.png' ),
			'180'   => brikpanel_pwa_icon_url( 'brikpanel-180.png' ),
		);
	$cache[ $blog ] = $icons;
	return $icons;
}

/**
 * The Apple touch icon for a Site Icon: on white when this server can draw
 * images (brikpanel_pwa_serve_app_icon()), else WordPress's own 180 px copy.
 * A new Site Icon is a new attachment, so its id alone makes a new address.
 *
 * @param int $id Site Icon attachment id.
 * @return string
 */
function brikpanel_pwa_apple_icon_url( $id ) {
	if ( ! function_exists( 'imagecreatefromstring' ) || ! function_exists( 'imagepng' ) ) {
		return (string) get_site_icon_url( 180 );
	}
	return admin_url( 'admin-ajax.php?action=brikpanel_app_icon&v=' . (int) $id );
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
 * Changes when the worker code, BrikPanel's version, the site language or the
 * app's icon (the notification's picture) changes.
 *
 * @return string
 */
function brikpanel_pwa_worker_version() {
	$file  = __DIR__ . '/brikpanel-push-sw.js';
	$icons = brikpanel_pwa_app_icons();
	return substr( md5( BRIKPANEL_VERSION . '|' . ( file_exists( $file ) ? filemtime( $file ) : 0 ) . '|' . get_locale() . '|' . $icons['192'] ), 0, 10 );
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
	$icons  = brikpanel_pwa_app_icons();
	$config = array(
		'receipt' => admin_url( 'admin-ajax.php' ),
		'start'   => admin_url(),
		'icon'    => $icons['192'],
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
 * The manifest's icon list. A Site Icon has no maskable copy: Android puts a
 * plain icon on a white shape by itself. Its file type is left to the browser
 * (a Site Icon can be a JPEG or WebP).
 *
 * @return array[]
 */
function brikpanel_pwa_manifest_icons() {
	$icons = brikpanel_pwa_app_icons();
	if ( $icons['store'] ) {
		return array(
			array(
				'src'     => $icons['192'],
				'sizes'   => '192x192',
				'purpose' => 'any',
			),
			array(
				'src'     => $icons['512'],
				'sizes'   => '512x512',
				'purpose' => 'any',
			),
		);
	}
	return array(
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
			'src'     => brikpanel_pwa_icon_url( 'brikpanel-maskable-192.png' ),
			'sizes'   => '192x192',
			'type'    => 'image/png',
			'purpose' => 'maskable',
		),
		array(
			'src'     => brikpanel_pwa_icon_url( 'brikpanel-maskable-512.png' ),
			'sizes'   => '512x512',
			'type'    => 'image/png',
			'purpose' => 'maskable',
		),
	);
}

/**
 * The Web App Manifest of this site.
 *
 * @return array
 */
function brikpanel_pwa_manifest() {
	return array(
		'id'               => brikpanel_pwa_admin_path() . 'brikpanel-app',
		'name'             => brikpanel_pwa_app_name(),
		'short_name'       => brikpanel_pwa_app_short_name(),
		// The entrance: on to the admin when logged in, to this phone's login address when not.
		'start_url'        => brikpanel_pwa_entry_url(),
		'scope'            => brikpanel_pwa_scope(),
		'display'          => 'standalone',
		'background_color' => '#f1f1f1',
		'theme_color'      => brikpanel_pwa_theme_color(),
		'lang'             => str_replace( '_', '-', get_locale() ),
		'dir'              => is_rtl() ? 'rtl' : 'ltr',
		'icons'            => brikpanel_pwa_manifest_icons(),
		// The app names itself, so a browser tab can ask whether it is installed on this
		// phone (navigator.getInstalledRelatedApps(), Chrome on Android): back in the tab
		// the phone button's panel then says "open the app" only while it really is there.
		'related_applications'        => array(
			array(
				'platform' => 'webapp',
				'url'      => brikpanel_pwa_manifest_url(),
			),
		),
		'prefer_related_applications' => false,
	);
}

/**
 * admin-ajax.php?action=brikpanel_manifest: the Web App Manifest. Asked for
 * without cookies, so it answers logged-out requests too; it holds nothing
 * private (the site's title and icon are public).
 *
 * @return void
 */
function brikpanel_pwa_serve_manifest() {
	brikpanel_pwa_cache_headers( 'application/manifest+json; charset=utf-8' );
	echo wp_json_encode( brikpanel_pwa_manifest(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON document.
	exit;
}
add_action( 'wp_ajax_brikpanel_manifest', 'brikpanel_pwa_serve_manifest' );
add_action( 'wp_ajax_nopriv_brikpanel_manifest', 'brikpanel_pwa_serve_manifest' );

/**
 * A square image file drawn on white at $size pixels, as PNG bytes; '' when
 * this server cannot read it. A file that is not square is centred.
 *
 * @param string $file Image file.
 * @param int    $size Pixels.
 * @return string
 */
function brikpanel_pwa_flat_icon( $file, $size ) {
	if ( ! function_exists( 'imagecreatefromstring' ) || ! function_exists( 'imagepng' ) || ! is_readable( $file ) ) {
		return '';
	}
	$bytes = (int) @filesize( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- unreadable means no icon.
	if ( $bytes <= 0 || $bytes > 8 * MB_IN_BYTES ) {
		return '';
	}
	$data = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local upload.
	$src  = '' === $data ? false : @imagecreatefromstring( $data ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a broken image gives false.
	if ( ! $src ) {
		return '';
	}
	$w = imagesx( $src );
	$h = imagesy( $src );
	if ( $w < 1 || $h < 1 ) {
		return '';
	}
	$scale = min( $size / $w, $size / $h );
	$dw    = max( 1, (int) round( $w * $scale ) );
	$dh    = max( 1, (int) round( $h * $scale ) );
	$dst   = imagecreatetruecolor( $size, $size );
	if ( ! $dst ) {
		return '';
	}
	imagefilledrectangle( $dst, 0, 0, $size - 1, $size - 1, (int) imagecolorallocate( $dst, 255, 255, 255 ) );
	imagealphablending( $dst, true );
	imagecopyresampled( $dst, $src, (int) floor( ( $size - $dw ) / 2 ), (int) floor( ( $size - $dh ) / 2 ), 0, 0, $dw, $dh, $w, $h );
	ob_start();
	imagepng( $dst, null, 9 );
	return (string) ob_get_clean();
}

/**
 * admin-ajax.php?action=brikpanel_app_icon: the Site Icon at 180 px on white,
 * for an iPhone's Home Screen. Public like the manifest; the address carries
 * the icon's id, so a new icon is fetched anew. Anything it cannot draw goes
 * to WordPress's own 180 px copy.
 *
 * @return void
 */
function brikpanel_pwa_serve_app_icon() {
	$id   = (int) get_option( 'site_icon' );
	$file = $id > 0 ? (string) get_attached_file( $id ) : '';
	$png  = '' !== $file ? brikpanel_pwa_flat_icon( $file, 180 ) : '';
	if ( '' === $png ) {
		$fallback = $id > 0 ? (string) get_site_icon_url( 180 ) : '';
		wp_redirect( esc_url_raw( '' !== $fallback ? $fallback : brikpanel_pwa_icon_url( 'brikpanel-180.png' ) ), 302 ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- WordPress's own Site Icon address, maybe on a media host.
		exit;
	}
	$etag = md5( $png );
	brikpanel_pwa_cache_headers( 'image/png', $etag );
	if ( ! headers_sent() ) {
		header( 'X-Content-Type-Options: nosniff' );
	}
	$sent = isset( $_SERVER['HTTP_IF_NONE_MATCH'] ) ? trim( sanitize_text_field( wp_unslash( $_SERVER['HTTP_IF_NONE_MATCH'] ) ), '" ' ) : '';
	if ( $sent === $etag ) {
		status_header( 304 );
		exit;
	}
	echo $png; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- PNG image.
	exit;
}
add_action( 'wp_ajax_brikpanel_app_icon', 'brikpanel_pwa_serve_app_icon' );
add_action( 'wp_ajax_nopriv_brikpanel_app_icon', 'brikpanel_pwa_serve_app_icon' );

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
	$icons = brikpanel_pwa_app_icons();
	printf( '<link rel="manifest" href="%s">' . "\n", esc_url( brikpanel_pwa_manifest_url() ) );
	printf( '<meta name="theme-color" content="%s">' . "\n", esc_attr( brikpanel_pwa_theme_color() ) );
	printf( '<link rel="apple-touch-icon" href="%s">' . "\n", esc_url( $icons['180'] ) );
	// The name an iPhone suggests in "Add to Home Screen" (the person can still change it there).
	printf( '<meta name="apple-mobile-web-app-title" content="%s">' . "\n", esc_attr( brikpanel_pwa_app_short_name() ) );
	echo '<meta name="apple-mobile-web-app-status-bar-style" content="default">' . "\n";
	// A browser install bar that opens by itself would jump the one-ask-at-a-
	// time line (includes/brikpanel-asks.php). The offer is kept for the phone
	// card's "Install BrikPanel" button (Android: the app first, then
	// notifications inside it), which also hears about an offer that comes late.
	echo "<script>window.addEventListener('beforeinstallprompt',function(e){e.preventDefault();window.brikpanelInstallPrompt=e;try{window.dispatchEvent(new Event('brikpanel-install-ready'));}catch(x){}});</script>\n"; // i18n-ignore: event names, not user text
}

/** @return void */
function brikpanel_pwa_admin_head() {
	if ( brikpanel_pwa_for_current_user() ) {
		brikpanel_pwa_print_tags();
		brikpanel_pwa_entry_print_app_script( false );
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
 * BrikPanel prints its own (brikpanel_pwa_print_tags(): the Site Icon on white,
 * or BrikPanel's when the site has none), so a phone sees only one. The
 * browser tab's favicon stays WordPress's.
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
 * decision, 7 October 2026), and a page that shows the login form keeps its
 * own address for the entrance (brikpanel-pwa-entry.php). A normal browser
 * tab is left as it is.
 *
 * @return void
 */
function brikpanel_pwa_login_footer() {
	echo "<script>(function(){try{if(!(window.matchMedia('(display-mode: standalone)').matches||window.navigator.standalone===true))return;var r=document.getElementById('rememberme');if(r){r.checked=true;}}catch(e){}})();</script>\n"; // i18n-ignore: media query, not user text
	brikpanel_pwa_entry_print_app_script( true );
}
add_action( 'login_footer', 'brikpanel_pwa_login_footer' );

require_once __DIR__ . '/brikpanel-pwa-entry.php';
