<?php
/**
 * BrikPanel - the phone app's way back in after its login ends.
 *
 * Why (8 November 2026 speed research, the "phone app is locked" case): a
 * store that hides its login page with a security plugin (Defender "Mask
 * Login Area", WPS Hide Login, Solid Security "Hide Backend") answers a
 * logged-out /wp-admin/ with a 403, a 404 or its home page. A Home Screen app
 * has no address bar, so once the phone drops the login (iPhones do, WebKit
 * bug 272325) the app stays on that page for good: the owner cannot type the
 * hidden address anywhere. Deleting and adding the app again was the only way
 * out.
 *
 * The way in:
 * - The entrance, admin-ajax.php?action=brikpanel_app. Login hiders leave
 *   admin-ajax.php open (shops need it logged out), page caches skip it and
 *   it is not a front-end page, so "coming soon" modes do not cover it. Logged
 *   in: on to the admin. Logged out: a small page of its own that goes to the
 *   login address this phone remembers, or asks for it, and offers the plain
 *   wp-login.php. The server never prints a hidden login address: only the
 *   phone knows it.
 * - Remembering the address: inside the app, a login page that shows the
 *   login form keeps its own address in this phone's storage (without
 *   redirect_to and the other one-off parts). Read back only when it is on
 *   this site.
 * - New installs and Android: the manifest's start_url is the entrance
 *   (brikpanel_pwa_manifest(); the app's id does not change).
 * - iPhones never read the manifest again, so an app added before keeps
 *   /wp-admin/ as its start. Inside the app a page asks the server once for an
 *   app cookie (HttpOnly, a year, only for the admin folder: Safari deletes
 *   cookies a script writes after 7 days). A logged-out GET of an admin page
 *   that carries it is sent to the entrance at plugins_loaded priority 1,
 *   before the login hiders act (Defender at init 99, WPS Hide Login at
 *   plugins_loaded 9999). The same check of the login as auth_redirect() is
 *   used here and at the entrance, so the two never send a request back and
 *   forth. Without the cookie (any normal browser) nothing changes.
 * - Limit: an iPhone app whose login ended before this version never got the
 *   cookie. It has to be added to the Home Screen again, once.
 *
 * The service worker is not touched: a fetch handler there would sit in front
 * of every admin file.
 *
 * @package BrikPanel
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Name of the app cookie (its path is this site's admin folder). */
if ( ! defined( 'BRIKPANEL_PWA_APP_COOKIE' ) ) {
	define( 'BRIKPANEL_PWA_APP_COOKIE', 'brikpanel_app' );
}

/**
 * The entrance address, optionally with the admin page to open after it.
 *
 * @param string $to Admin page address (absolute, from brikpanel_pwa_entry_target()).
 * @return string
 */
function brikpanel_pwa_entry_url( $to = '' ) {
	$url = admin_url( 'admin-ajax.php?action=brikpanel_app' );
	if ( '' !== $to ) {
		$url .= '&to=' . rawurlencode( (string) wp_make_link_relative( $to ) );
	}
	return $url;
}

/**
 * An admin page address on this site, from a path such as
 * "/wp-admin/post.php?post=12&action=edit", or '' when it is anything else:
 * another site, another folder, an endpoint that is not a page.
 *
 * @param string $raw Path and query.
 * @return string Absolute address.
 */
function brikpanel_pwa_entry_target( $raw ) {
	$raw = (string) $raw;
	if ( '' === $raw || strlen( $raw ) > 2000 || preg_match( '/[\x00-\x1f\x7f\\\\]/', $raw ) ) {
		return '';
	}
	$parts = wp_parse_url( $raw );
	if ( ! is_array( $parts ) || isset( $parts['scheme'] ) || isset( $parts['host'] ) || empty( $parts['path'] ) ) {
		return '';
	}
	$path  = (string) $parts['path'];
	$admin = brikpanel_pwa_admin_path();
	if ( 0 !== strpos( $path, $admin ) || false !== strpos( $path, '..' ) || false !== strpos( $path, '//' ) ) {
		return '';
	}
	$file = substr( $path, strlen( $admin ) );
	if ( '' !== $file && ( ! preg_match( '/^[a-z0-9_-]+\.php$/', $file ) || in_array( $file, brikpanel_pwa_entry_skip_files(), true ) ) ) {
		return '';
	}
	$origin = (string) preg_replace( '#^(https?://[^/]+).*$#i', '$1', admin_url() );
	$url    = $origin . $path . ( isset( $parts['query'] ) && '' !== $parts['query'] ? '?' . $parts['query'] : '' );
	return (string) wp_validate_redirect( $url, '' );
}

/**
 * Files in the admin folder that are not pages a person opens.
 *
 * @return string[]
 */
function brikpanel_pwa_entry_skip_files() {
	return array( 'admin-ajax.php', 'admin-post.php', 'async-upload.php', 'load-scripts.php', 'load-styles.php', 'media-upload.php', 'upgrade.php', 'install.php', 'setup-config.php', 'update.php' );
}

/**
 * Whether this request opens the admin logged in, by the same check
 * auth_redirect() makes (the admin cookie, not only the logged-in one).
 *
 * @return bool
 */
function brikpanel_pwa_entry_logged_in() {
	return (bool) wp_validate_auth_cookie( '', (string) apply_filters( 'auth_redirect_scheme', '' ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress's own filter, read the way auth_redirect() reads it.
}

/**
 * An app that was added before the entrance existed still starts on the
 * admin. Logged out, with the app cookie, it is sent to the entrance instead
 * of the page a login hider would answer with.
 *
 * @return void
 */
function brikpanel_pwa_entry_catch() {
	if ( empty( $_COOKIE[ BRIKPANEL_PWA_APP_COOKIE ] ) || ! isset( $_SERVER['REQUEST_METHOD'] ) || 'GET' !== $_SERVER['REQUEST_METHOD'] ) {
		return;
	}
	if ( wp_doing_ajax() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
		return;
	}
	$uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
	$to  = brikpanel_pwa_entry_target( $uri );
	if ( '' === $to || brikpanel_pwa_entry_logged_in() ) {
		return;
	}
	nocache_headers();
	wp_safe_redirect( brikpanel_pwa_entry_url( $to ), 302 );
	exit;
}
add_action( 'plugins_loaded', 'brikpanel_pwa_entry_catch', 1 );

/**
 * admin-ajax.php?action=brikpanel_app: the entrance.
 *
 * @return void
 */
function brikpanel_pwa_entry_serve() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a page to open, it changes nothing; the address is checked against this site's admin pages.
	$to = isset( $_GET['to'] ) ? brikpanel_pwa_entry_target( esc_url_raw( wp_unslash( $_GET['to'] ) ) ) : '';
	if ( brikpanel_pwa_entry_logged_in() ) {
		nocache_headers();
		wp_safe_redirect( '' !== $to ? $to : admin_url(), 302 );
		exit;
	}
	brikpanel_pwa_entry_page( '' !== $to ? $to : admin_url() );
	exit;
}
add_action( 'wp_ajax_brikpanel_app', 'brikpanel_pwa_entry_serve' );
add_action( 'wp_ajax_nopriv_brikpanel_app', 'brikpanel_pwa_entry_serve' );

/**
 * Keys of this site's entries in the phone's storage.
 *
 * @return array{login:string,tries:string,mark:string}
 */
function brikpanel_pwa_entry_keys() {
	$blog = (int) get_current_blog_id();
	return array(
		'login' => 'brikpanel.app.login.' . $blog,
		'tries' => 'brikpanel.app.entry.' . $blog,
		'mark'  => 'brikpanel.app.mark.' . $blog,
	);
}

/**
 * The logged-out entrance page. Self-contained: no theme, no admin styles,
 * nothing of the store but its name and icon.
 *
 * @param string $target Admin page to open after the login.
 * @return void
 */
function brikpanel_pwa_entry_page( $target ) {
	if ( ! headers_sent() ) {
		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'X-Frame-Options: DENY' );
		header( "Content-Security-Policy: frame-ancestors 'none'" );
		header( 'Referrer-Policy: same-origin' );
		header( 'X-Robots-Tag: noindex, nofollow' );
	}
	$icons = brikpanel_pwa_app_icons();
	$host  = (string) wp_parse_url( home_url(), PHP_URL_HOST );
	// The plain wp-login.php, put together after the filters: login hiders rewrite
	// site_url( 'wp-login.php' ) to their hidden address (Defender does), and this
	// page is public.
	$def   = add_query_arg( 'redirect_to', rawurlencode( $target ), untrailingslashit( site_url( '', 'login' ) ) . '/wp-login.php' );
	$cfg   = array(
		'keys'   => brikpanel_pwa_entry_keys(),
		'target' => $target,
	);
	// Printed inline through WordPress's own printers: this page loads no other file.
	$css = (string) file_get_contents( __DIR__ . '/brikpanel-pwa-entry.css' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local plugin file.
	$js  = (string) file_get_contents( __DIR__ . '/brikpanel-pwa-entry.js' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local plugin file.
	wp_register_style( 'brikpanel-pwa-entry', false, array(), BRIKPANEL_VERSION );
	wp_add_inline_style( 'brikpanel-pwa-entry', $css );
	?>
<!DOCTYPE html>
<html lang="<?php echo esc_attr( str_replace( '_', '-', get_locale() ) ); ?>" dir="<?php echo esc_attr( is_rtl() ? 'rtl' : 'ltr' ); ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<title><?php echo esc_html( sprintf( /* translators: %s: the store's name */ __( 'Log in: %s', 'brikpanel' ), brikpanel_pwa_app_name() ) ); ?></title>
	<?php
	brikpanel_pwa_print_tags();
	wp_print_styles( 'brikpanel-pwa-entry' );
	?>
</head>
<body class="bp-entry-body">
<main class="bp-entry">
	<img class="bp-entry__icon" src="<?php echo esc_url( $icons['192'] ); ?>" width="64" height="64" alt="">
	<p class="bp-entry__store"><?php echo esc_html( brikpanel_pwa_app_name() ); ?></p>
	<?php // header-end-ok: a page of its own outside the admin screens; no admin notices, no jQuery. ?>
	<h1 class="bp-entry__title"><?php esc_html_e( 'Log in to continue', 'brikpanel' ); ?></h1>
	<p class="bp-entry__text"><?php esc_html_e( 'The login on this phone has ended. Log in again to open your store panel.', 'brikpanel' ); ?></p>
	<a class="bp-entry__btn" id="bp-entry-login" href="<?php echo esc_url( $def ); ?>"><?php esc_html_e( 'Log in', 'brikpanel' ); ?></a>
	<details class="bp-entry__other" id="bp-entry-other">
		<summary><?php esc_html_e( 'My site has its own login address', 'brikpanel' ); ?></summary>
		<form class="bp-entry__form" id="bp-entry-form" novalidate>
			<label class="bp-entry__label" for="bp-entry-url"><?php esc_html_e( 'Login page address', 'brikpanel' ); ?></label>
			<input class="bp-entry__input" id="bp-entry-url" type="url" inputmode="url" autocomplete="url" autocapitalize="none" spellcheck="false" dir="ltr" placeholder="<?php echo esc_attr( trailingslashit( home_url() ) ); ?>…" aria-describedby="bp-entry-hint">
			<p class="bp-entry__hint" id="bp-entry-hint"><?php esc_html_e( 'The address you open to log in, also when a security plugin hides the usual one. This phone remembers it.', 'brikpanel' ); ?></p>
			<p class="bp-entry__error" id="bp-entry-error" role="alert" hidden><?php echo esc_html( sprintf( /* translators: %s: the site's domain, e.g. shop.example.com */ __( 'Use an address on %s.', 'brikpanel' ), $host ) ); ?></p>
			<button class="bp-entry__btn bp-entry__btn--secondary" type="submit"><?php esc_html_e( 'Open', 'brikpanel' ); ?></button>
		</form>
	</details>
</main>
	<?php wp_print_inline_script_tag( 'window.brikpanelEntry=' . wp_json_encode( $cfg, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES ) . ";\n" . $js, array( 'id' => 'brikpanel-entry' ) ); ?>
</body>
</html>
	<?php
}

/**
 * admin-ajax.php?action=brikpanel_app_mark: the app cookie. Asked for once a
 * month by a page that runs inside the installed app.
 *
 * @return void
 */
function brikpanel_pwa_entry_mark() {
	check_ajax_referer( 'brikpanel_app_mark' );
	if ( ! headers_sent() ) {
		setcookie(
			BRIKPANEL_PWA_APP_COOKIE,
			'1',
			array(
				'expires'  => time() + YEAR_IN_SECONDS,
				'path'     => brikpanel_pwa_admin_path(),
				'domain'   => COOKIE_DOMAIN,
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
	}
	wp_send_json_success();
}
add_action( 'wp_ajax_brikpanel_app_mark', 'brikpanel_pwa_entry_mark' );
add_action( 'wp_ajax_nopriv_brikpanel_app_mark', 'brikpanel_pwa_entry_mark' );

/**
 * The script a page inside the installed app runs: on a login page that shows
 * the login form it remembers the address; on every page it asks for the app
 * cookie at most once a month. Outside the app it does nothing.
 *
 * @param bool $login A login page.
 * @return void
 */
function brikpanel_pwa_entry_print_app_script( $login ) {
	$cfg = array(
		'keys'  => brikpanel_pwa_entry_keys(),
		'login' => (bool) $login,
		'ajax'  => admin_url( 'admin-ajax.php' ),
		'nonce' => wp_create_nonce( 'brikpanel_app_mark' ),
	);
	// One-off parts of a login address: never part of the address to remember.
	$drop = array( 'redirect_to', 'reauth', 'loggedout', 'interim-login', 'customize-login', 'wp_lang', 'checkemail', 'registration' );
	wp_print_inline_script_tag(
		'(function(C,D){try{' .
		"var app=(window.matchMedia&&window.matchMedia('(display-mode: standalone)').matches)||window.navigator.standalone===true;if(!app)return;" . // i18n-ignore: media query, not user text
		'var ls=window.localStorage,K=C.keys;' .
		"if(C.login&&document.getElementById('loginform')){var u=new URL(location.href);D.forEach(function(p){u.searchParams.delete(p);});if(u.searchParams.get('action')==='login'){u.searchParams.delete('action');}u.hash='';ls.setItem(K.login,u.href);ls.removeItem(K.tries);}" . // i18n-ignore: element id and parameter names
		"var m=parseInt(ls.getItem(K.mark)||'0',10);if(Date.now()-m<2592e6)return;" .
		"var b=new URLSearchParams();b.append('action','brikpanel_app_mark');b.append('_ajax_nonce',C.nonce);" . // i18n-ignore: request fields
		"fetch(C.ajax,{method:'POST',credentials:'same-origin',body:b}).then(function(r){if(r.ok){ls.setItem(K.mark,String(Date.now()));}},function(){});" . // i18n-ignore: request options
		'}catch(e){}})(' . wp_json_encode( $cfg, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES ) . ',' . wp_json_encode( $drop ) . ');',
		array( 'id' => 'brikpanel-app-entry' )
	);
}
