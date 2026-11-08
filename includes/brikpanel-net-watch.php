<?php
/**
 * BrikPanel - page arrival watch (8 November 2026).
 *
 * Why: an admin page can stop arriving in one browser while the server builds
 * it in under a second. vensakimya.com (November 2026): for a month Edge on the
 * owner's PC showed the tab title and a blank, spinning page; the cause was
 * Edge's HTTP/3 (QUIC) connection to the Hostinger CDN, which stalled after it
 * was set up. Chromium falls back to HTTP/2 only when a connection cannot be
 * set up or reports an error, never when a working one goes quiet. Nothing on
 * the screen said so, and the owner blamed the panel because the panel was
 * what had changed.
 *
 * What it does:
 *  - A small script printed at the very top of every admin <head>, before any
 *    stylesheet or script, so it runs even when those are stuck. It keeps the
 *    last 40 page arrivals of this browser in localStorage
 *    (brikpanel.net.<blog id>): when, which screen ($pagenow and its page
 *    parameter, never an address), the PHP time printed at the end of the page
 *    (brikpanel_net_watch_footer()), time to first byte, HTML download, DOM
 *    ready, load, the protocol (h2/h3) and whether the page got stuck. Nothing
 *    leaves the browser, except as a summary in the deactivation survey when
 *    the owner presses Send there (includes/brikpanel-exit-survey.php).
 *  - When the page is still being read after 15 seconds on screen it shows a
 *    small box: how long it waits, how fast the server built the last pages,
 *    the protocol, and what to try. While nothing can be drawn yet (a
 *    stylesheet in <head> is stuck) it changes the tab title instead. When
 *    the page arrives, the box says how long it took and how much of that the
 *    server used (this page's own PHP time), then closes itself. A page that
 *    never came is explained on the next page that does.
 *  - "Stuck" means DOMContentLoaded has not fired: `load` also waits for every
 *    image and frame, which would raise false alarms on media screens.
 *
 * Who sees it: people who see BrikPanel's interface. On wp-login.php only the
 * plain login form, and the box only in a browser that already used the admin
 * (it has samples). Never inside frames. Not on screens that print their work
 * bit by bit (updates, importers). Filter brikpanel_net_watch_enabled.
 *
 * Self-contained on purpose: the JS and CSS are real files under
 * front-end/net-watch/ (the i18n and CSS audits read them) printed inline, and
 * the box uses its own class prefix, no WordPress notice classes (rule B4).
 * Not in the one-ask-at-a-time line (includes/brikpanel-asks.php): it answers
 * the person's own page load and is not an ask; the "arrived" box closes
 * itself and is not shown while a dialog is open.
 *
 * @package BrikPanel
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Seconds on screen before a page counts as stuck.
 */
if ( ! defined( 'BRIKPANEL_NET_WATCH_STALL_MS' ) ) {
	define( 'BRIKPANEL_NET_WATCH_STALL_MS', 15000 );
}

/**
 * The screen as the samples name it: WordPress's screen id (never an address).
 *
 * @return string
 */
function brikpanel_net_watch_screen() {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	$id     = $screen && ! empty( $screen->id ) ? (string) $screen->id : ( isset( $GLOBALS['pagenow'] ) ? (string) $GLOBALS['pagenow'] : '' );
	return substr( sanitize_key( str_replace( '.php', '', $id ) ), 0, 80 );
}

/**
 * Whether this admin screen prints its work bit by bit and may take long on
 * purpose (core and plugin updates, importers, the Customizer).
 *
 * @return bool
 */
function brikpanel_net_watch_slow_by_design() {
	$now = isset( $GLOBALS['pagenow'] ) ? (string) $GLOBALS['pagenow'] : '';
	if ( in_array( $now, [ 'update.php', 'update-core.php', 'upgrade.php', 'import.php', 'customize.php' ], true ) ) {
		return true;
	}
	// An importer runs on admin.php?import=<slug>.
	return 'admin.php' === $now && isset( $_GET['import'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only checks that the parameter is there.
}

/**
 * Whether the watch runs on this request.
 *
 * @param string $where 'admin' or 'login'.
 * @return bool
 */
function brikpanel_net_watch_enabled( $where = 'admin' ) {
	if ( 'login' === $where ) {
		// wp-login.php keeps both in globals before it prints the form.
		$action = isset( $GLOBALS['action'] ) && is_string( $GLOBALS['action'] ) ? $GLOBALS['action'] : 'login';
		$on     = 'login' === $action && empty( $GLOBALS['interim_login'] );
	} else {
		$on = is_admin()
			&& ! wp_doing_ajax()
			&& ! brikpanel_net_watch_slow_by_design()
			&& function_exists( 'brikpanel_user_can_use_interface' ) && brikpanel_user_can_use_interface()
			&& ! ( function_exists( 'brikpanel_access_should_neutralize' ) && brikpanel_access_should_neutralize() );
	}
	/**
	 * Turns the page arrival watch on or off for this request.
	 *
	 * @param bool   $on     Whether it runs.
	 * @param string $where  'admin' or 'login'.
	 * @param string $screen The screen as the samples name it.
	 */
	return (bool) apply_filters( 'brikpanel_net_watch_enabled', $on, $where, brikpanel_net_watch_screen() );
}

/**
 * What the script needs: storage key, screen, threshold, the store's decimal
 * separator, the words (translated here: the script may run before anything
 * else of BrikPanel arrives) and the box's stylesheet.
 *
 * @param bool $login Whether this is the login page.
 * @return array
 */
function brikpanel_net_watch_config( $login ) {
	$sep = function_exists( 'brikpanel_number_separators' ) ? brikpanel_number_separators() : [ 'decimal' => '.' ];
	$css = (string) file_get_contents( BRIKPANEL_PATH . 'front-end/net-watch/brikpanel-net-watch.css' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local plugin file.
	$css = trim( preg_replace( '#/\*.*?\*/#s', '', wp_strip_all_tags( $css ) ) );
	return [
		'k'     => 'brikpanel.net.' . get_current_blog_id(),
		'p'     => $login ? 'wp-login' : brikpanel_net_watch_screen(),
		't'     => (int) BRIKPANEL_NET_WATCH_STALL_MS,
		'dec'   => isset( $sep['decimal'] ) ? (string) $sep['decimal'] : '.',
		'login' => (bool) $login,
		'css'   => $css,
		's'     => [
			/* translators: %s: how long the page has been loading, for example "18 s". */
			'title'         => __( 'This page has been loading for %s', 'brikpanel' ),
			/* translators: %s: how long the page took, for example "21 s". */
			'arrived'       => __( 'This page arrived after %s', 'brikpanel' ),
			/* translators: %s: how long the previous page was waited for, for example "40 s". */
			'prevStuck'     => __( 'The previous page did not arrive within %s', 'brikpanel' ),
			/* translators: %s: how long the page has been loading, for example "18 s". Shown in the browser tab. */
			'tabTitle'      => __( 'Connection stuck · %s', 'brikpanel' ),
			/* translators: %s: a number of seconds. Short unit, as on a stopwatch. */
			'sec'           => _x( '%s s', 'seconds', 'brikpanel' ),
			/* translators: %s: average time the server needed per page, for example "0.6 s". */
			'fastServer'    => __( 'Your server built the last pages in %s on average. A wait this long usually means the connection between this computer and your site is stuck.', 'brikpanel' ),
			/* translators: %s: average time the server needed per page, for example "9 s". */
			'slowServer'    => __( 'Your server needs about %s to build a page, a large part of this wait.', 'brikpanel' ),
			'unknown'       => __( 'A wait this long usually means the connection between this computer and your site is stuck, or the server is overloaded.', 'brikpanel' ),
			/* translators: %s: time the server needed for this page, for example "0.6 s". */
			'arrivedFast'   => __( 'Your server built it in %s, so the rest of the wait was the connection between this computer and your site.', 'brikpanel' ),
			/* translators: %s: time the server needed for this page, for example "14 s". */
			'arrivedSlow'   => __( 'Your server took %s to build it, most of the wait.', 'brikpanel' ),
			'h3'            => __( 'This browser talks to your site over HTTP/3 (QUIC). Some antivirus programs, routers and networks break it in a way the browser does not notice.', 'brikpanel' ),
			'stepsTitle'    => __( 'What can I do?', 'brikpanel' ),
			'stepHostH3'    => __( 'Ask your hosting company to turn off HTTP/3 (QUIC) for your site.', 'brikpanel' ),
			/* translators: %s: a browser settings address such as chrome://flags/#enable-quic, shown with a Copy button. */
			'stepBrowser'   => __( 'Or turn it off in this browser: open %s in the address bar, set it to Disabled and restart the browser.', 'brikpanel' ),
			'stepFirefox'   => __( 'Or turn it off in Firefox: open about:config in the address bar and set network.http.http3.enable to false.', 'brikpanel' ),
			'stepAntivirus' => __( 'If you use an antivirus program, turn off its HTTP/3 or QUIC scanning, or pause it and reload the page.', 'brikpanel' ),
			'stepNetwork'   => __( 'Try another network, such as your phone\'s hotspot. If the page opens there, the problem is this network or its router.', 'brikpanel' ),
			'stepHost'      => __( 'If it keeps happening, send your hosting company the time and the address of this page.', 'brikpanel' ),
			'stepSlow'      => __( 'Ask your hosting company why the server needs this long, and whether your plan has enough PHP workers and memory.', 'brikpanel' ),
			'copy'          => __( 'Copy', 'brikpanel' ),
			'copied'        => __( 'Copied', 'brikpanel' ),
			'close'         => __( 'Close', 'brikpanel' ),
		],
	];
}

/**
 * Prints the watch: the settings and the script in one inline tag.
 *
 * @param bool $login Whether this is the login page.
 * @return void
 */
function brikpanel_net_watch_print( $login ) {
	$js = (string) file_get_contents( BRIKPANEL_PATH . 'front-end/net-watch/brikpanel-net-watch.js' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local plugin file.
	if ( '' === $js ) {
		return;
	}
	// JSON_HEX_TAG: no "<" survives, so the settings cannot close the tag.
	$cfg = wp_json_encode( brikpanel_net_watch_config( $login ), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	if ( ! is_string( $cfg ) ) {
		return;
	}
	$GLOBALS['brikpanel_net_watch_printed'] = true;
	wp_print_inline_script_tag( 'window.brikpanelNetCfg=' . $cfg . ";\n" . $js, [ 'id' => 'brikpanel-net-watch' ] );
}

/**
 * Admin: right behind the early viewport meta, before WordPress prints any
 * stylesheet or script (same placement and guards as
 * brikpanel_print_early_viewport_meta(), on every admin screen).
 *
 * @return void
 */
function brikpanel_net_watch_admin_head() {
	static $done = false;
	if ( $done || ! did_action( 'admin_xml_ns' ) || did_action( 'admin_head' ) ) {
		return;
	}
	$done = true;
	if ( brikpanel_net_watch_enabled( 'admin' ) ) {
		brikpanel_net_watch_print( false );
	}
}
add_action( 'admin_enqueue_scripts', 'brikpanel_net_watch_admin_head', PHP_INT_MAX );

/**
 * Login page: before its stylesheets.
 *
 * @return void
 */
function brikpanel_net_watch_login_head() {
	static $done = false;
	if ( $done || ! brikpanel_net_watch_enabled( 'login' ) ) {
		return;
	}
	$done = true;
	brikpanel_net_watch_print( true );
}
add_action( 'login_enqueue_scripts', 'brikpanel_net_watch_login_head', PHP_INT_MAX );

/**
 * The PHP time of this page, as late as possible: how long the server worked
 * on it (it does not include time the request waited for a free PHP worker).
 *
 * @return void
 */
function brikpanel_net_watch_footer() {
	if ( empty( $GLOBALS['brikpanel_net_watch_printed'] ) ) {
		return;
	}
	$start = isset( $_SERVER['REQUEST_TIME_FLOAT'] ) ? (float) $_SERVER['REQUEST_TIME_FLOAT'] : 0.0;
	if ( $start <= 0 ) {
		return;
	}
	$ms = max( 0, (int) round( ( microtime( true ) - $start ) * 1000 ) );
	wp_print_inline_script_tag( 'window.brikpanelNetSrv=' . $ms . ';' );
}
add_action( 'admin_print_footer_scripts', 'brikpanel_net_watch_footer', PHP_INT_MAX );
add_action( 'login_footer', 'brikpanel_net_watch_footer', PHP_INT_MAX );
