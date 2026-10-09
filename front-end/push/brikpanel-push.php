<?php
/**
 * BrikPanel - phone notifications (Web Push).
 *
 * A notification on the store owner's phone for every new order, also when
 * the phone is locked: sent by the store's own server, straight to Apple's,
 * Google's, Mozilla's or Microsoft's push service, encrypted end to end. No
 * BrikPanel server and no paid service in between.
 *
 * Parked until tested on real phones: brikpanel.php loads this file only when
 * wp-config.php has define( 'BRIKPANEL_PHONE_APP', true ). Then it loads on
 * every request (orders change status at checkout, in webhooks and in cron
 * jobs too): this file holds the setting, the order hooks, the delivery
 * receipt, the logout and the background jobs. The export keys and the jobs'
 * stand-down live in brikpanel-push-gate.php (loaded either way), the sending
 * in class-brikpanel-push-sender.php, the screens in brikpanel-push-admin.php,
 * the Home Screen app in brikpanel-pwa.php.
 *
 * Setting: WooCommerce > Settings > BrikPanel > Notifications > "Phone
 * notifications" (brikpanel_push_enabled, on by default; each person turns
 * notifications on per phone).
 *
 * @package BrikPanel
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-brikpanel-webpush.php';
require_once __DIR__ . '/class-brikpanel-push-store.php';
require_once __DIR__ . '/class-brikpanel-push-sender.php';
require_once __DIR__ . '/brikpanel-pwa.php';

/**
 * "Phone notifications": on unless switched off.
 *
 * @return bool
 */
function brikpanel_push_enabled() {
	return 'no' !== get_option( 'brikpanel_push_enabled', 'yes' );
}

/**
 * Whether this site can offer phone notifications at all: the setting is on
 * and the server can do the crypto. HTTPS is checked where it matters (the
 * browser refuses push on http pages).
 *
 * @return bool
 */
function brikpanel_push_available() {
	return brikpanel_push_enabled() && Brikpanel_Push_Store::crypto_ok();
}

/**
 * What this browser said it is, through brikpanel-push.js: a = can turn
 * notifications on, i = an iPhone browser tab (Add to Home Screen first),
 * o = on, d = blocked, u = cannot. '' when the browser is not a phone or the
 * script has not run yet.
 *
 * @return string
 */
function brikpanel_push_phone_cookie() {
	$name = 'brikpanel_phone_' . get_current_blog_id();
	if ( empty( $_COOKIE[ $name ] ) ) {
		return '';
	}
	$value = sanitize_key( wp_unslash( $_COOKIE[ $name ] ) );
	return in_array( $value, array( 'a', 'i', 'o', 'd', 'u' ), true ) ? $value : '';
}

/**
 * Whether the request itself is https: browsers allow push only there.
 * Tests on http dev sites pass true through the filter.
 *
 * @return bool
 */
function brikpanel_push_secure_request() {
	/**
	 * Whether this request counts as https for phone notifications.
	 *
	 * @since 3.3.32
	 * @param bool $secure is_ssl().
	 */
	return (bool) apply_filters( 'brikpanel_push_secure_request', is_ssl() );
}

/**
 * Whether the "Turn on order notifications" card may take its turn
 * (includes/brikpanel-asks.php, id "push"): the store can send, this person
 * has a phone that can turn them on (seen in the last 30 days), and, when a
 * new ask is being picked, this page view comes from that phone.
 *
 * @param int  $now     Unix time (the asks clock).
 * @param bool $picking A new ask is being picked.
 * @return bool
 */
function brikpanel_push_card_eligible( $now, $picking ) {
	if ( ! brikpanel_push_available() || ! brikpanel_push_secure_request() || ! Brikpanel_Push_Store::site_matches() ) {
		return false;
	}
	if ( $picking || brikpanel_push_phone_view() ) {
		return brikpanel_push_phone_view();
	}
	$stamp = get_user_option( 'brikpanel_push_phone', get_current_user_id() );
	return is_array( $stamp ) && isset( $stamp['s'], $stamp['t'] ) && in_array( $stamp['s'], array( 'a', 'i' ), true ) && (int) $stamp['t'] >= (int) $now - 30 * DAY_IN_SECONDS;
}

/**
 * Whether this page view comes from a phone that can turn notifications on.
 * The cookie answers once brikpanel-push.js has run on the phone; before
 * that (a new phone, or the Home Screen app, which keeps its own cookies)
 * the browser's name does, so the very first visit already gets the card
 * instead of another ask.
 *
 * @return bool
 */
function brikpanel_push_phone_view() {
	$cookie = brikpanel_push_phone_cookie();
	if ( '' !== $cookie ) {
		return in_array( $cookie, array( 'a', 'i' ), true );
	}
	$agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- only matched against a pattern.
	return (bool) preg_match( '/iPhone|iPod|Android.+Mobile/i', $agent );
}

/**
 * A person who leaves the site (user deleted, or removed from this site of a
 * network) stops getting the store's orders on their phones at once.
 *
 * @param int $user_id User id.
 * @return void
 */
function brikpanel_push_forget_user( $user_id ) {
	Brikpanel_Push_Store::delete_user( (int) $user_id );
}
add_action( 'deleted_user', 'brikpanel_push_forget_user' );
add_action( 'remove_user_from_blog', 'brikpanel_push_forget_user' );

// -----------------------------------------------------------------------------
// New orders. Three ways in, one notification (the sender keeps a mark on the
// order): the status change itself, an order created straight into a "new
// order" status, and the save pair below, which also works when another
// plugin's status hook throws and WooCommerce skips the status-change action
// (same pattern as front-end/order-statuses/brikpanel-status-emails.php).
// Each returns at once on stores where nobody turned notifications on.
// -----------------------------------------------------------------------------

/**
 * @param int           $order_id Order id.
 * @param string        $from     Old status.
 * @param string        $to       New status.
 * @param WC_Order|null $order    Order.
 * @return void
 */
function brikpanel_push_on_status_changed( $order_id, $from, $to, $order = null ) {
	if ( ! Brikpanel_Push_Sender::accepting() ) {
		return;
	}
	$statuses = brikpanel_new_order_statuses();
	if ( in_array( (string) $to, $statuses, true ) && ! in_array( (string) $from, $statuses, true ) ) {
		Brikpanel_Push_Sender::queue( $order_id, $order );
	}
}
add_action( 'woocommerce_order_status_changed', 'brikpanel_push_on_status_changed', 20, 4 );

/**
 * @param int           $order_id Order id.
 * @param WC_Order|null $order    Order.
 * @return void
 */
function brikpanel_push_on_new_order( $order_id, $order = null ) {
	if ( Brikpanel_Push_Sender::accepting() ) {
		Brikpanel_Push_Sender::queue( $order_id, $order );
	}
}
add_action( 'woocommerce_new_order', 'brikpanel_push_on_new_order', 20, 2 );

/**
 * Before a save that moves an order into a "new order" status, keeps the
 * status still in storage.
 *
 * @param WC_Order $order Order about to be saved.
 * @return void
 */
function brikpanel_push_before_save( $order ) {
	if ( ! $order instanceof WC_Order || ! $order->get_id() ) {
		return;
	}
	$changes = $order->get_changes();
	if ( ! isset( $changes['status'] ) || ! Brikpanel_Push_Sender::accepting() || ! Brikpanel_Push_Sender::is_new_order( $order ) ) {
		return;
	}
	if ( '' !== (string) $order->get_meta( Brikpanel_Push_Sender::META, true, 'edit' ) ) {
		return;
	}
	Brikpanel_Push_Sender::stash( $order->get_id(), Brikpanel_Push_Sender::stored_status( $order->get_id() ) );
}
add_action( 'woocommerce_before_order_object_save', 'brikpanel_push_before_save', 1 );

/**
 * @param WC_Order $order Order that was saved.
 * @return void
 */
function brikpanel_push_after_save( $order ) {
	if ( ! $order instanceof WC_Order ) {
		return;
	}
	$from = Brikpanel_Push_Sender::stash( $order->get_id(), null, true );
	if ( null === $from ) {
		return;
	}
	$statuses = brikpanel_new_order_statuses();
	if ( ! in_array( $from, $statuses, true ) && in_array( (string) $order->get_status(), $statuses, true ) ) {
		Brikpanel_Push_Sender::queue( $order->get_id(), $order );
	}
}
add_action( 'woocommerce_after_order_object_save', 'brikpanel_push_after_save', 1 );

// -----------------------------------------------------------------------------
// Delivery receipts
// -----------------------------------------------------------------------------

/**
 * The phone's service worker reports a notification arrived. It has no
 * login session (and must not have one), so there is no nonce: the receipt
 * itself is the proof, a signature over the device and the send time made
 * with this site's secret keys (Brikpanel_Push_Sender::read_receipt()).
 * Anything else is ignored, with the same empty answer.
 *
 * @return void
 */
function brikpanel_push_receipt() {
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- signed receipt, see above.
	$token = isset( $_POST['r'] ) ? sanitize_text_field( wp_unslash( $_POST['r'] ) ) : '';
	if ( '' !== $token && strlen( $token ) <= 64 ) {
		Brikpanel_Push_Sender::confirm( $token );
	}
	wp_die( '', '', array( 'response' => 204 ) );
}
add_action( 'wp_ajax_brikpanel_push_receipt', 'brikpanel_push_receipt' );
add_action( 'wp_ajax_nopriv_brikpanel_push_receipt', 'brikpanel_push_receipt' );

// -----------------------------------------------------------------------------
// Logging out on a device stops its notifications (owner decision, 7 October
// 2026). A session that simply expires does not.
// -----------------------------------------------------------------------------

/**
 * @param int $user_id User who logged out (WordPress 5.5+).
 * @return void
 */
function brikpanel_push_on_logout( $user_id = 0 ) {
	$name = Brikpanel_Push_Sender::device_cookie_name();
	if ( empty( $_COOKIE[ $name ] ) ) {
		return;
	}
	$cookie = Brikpanel_Push_Sender::read_device_cookie( sanitize_text_field( wp_unslash( $_COOKIE[ $name ] ) ) );
	brikpanel_push_clear_device_cookie();
	if ( ! $cookie || ( $user_id && (int) $user_id !== $cookie['user'] ) ) {
		return;
	}
	$row = Brikpanel_Push_Store::get( $cookie['device'] );
	if ( $row && (int) $row['user_id'] === $cookie['user'] && 'active' === $row['status'] ) {
		Brikpanel_Push_Store::mark_removed( $cookie['device'] );
	}
}
add_action( 'wp_logout', 'brikpanel_push_on_logout' );

/**
 * Names this browser's device for the logout above (set when notifications
 * are turned on; covers wp-login.php, so the site path).
 *
 * @param int $device_id Device id.
 * @param int $user_id   Owner.
 * @return void
 */
function brikpanel_push_set_device_cookie( $device_id, $user_id ) {
	if ( headers_sent() ) {
		return;
	}
	$name  = Brikpanel_Push_Sender::device_cookie_name();
	$value = Brikpanel_Push_Sender::device_cookie_value( $device_id, $user_id );
	setcookie(
		$name,
		$value,
		array(
			'expires'  => time() + YEAR_IN_SECONDS,
			'path'     => SITECOOKIEPATH ? SITECOOKIEPATH : '/',
			'domain'   => COOKIE_DOMAIN,
			'secure'   => is_ssl(),
			'httponly' => true,
			'samesite' => 'Lax',
		)
	);
	$_COOKIE[ $name ] = $value;
}

/** @return void */
function brikpanel_push_clear_device_cookie() {
	$name = Brikpanel_Push_Sender::device_cookie_name();
	unset( $_COOKIE[ $name ] );
	if ( ! headers_sent() ) {
		setcookie( $name, '', time() - YEAR_IN_SECONDS, SITECOOKIEPATH ? SITECOOKIEPATH : '/', COOKIE_DOMAIN );
	}
}

// -----------------------------------------------------------------------------
// Background jobs. Registered whether or not the setting is on: a job left in
// the queue when it was switched off then finishes quietly (the handlers check
// the setting) instead of failing with "no callbacks are registered".
// -----------------------------------------------------------------------------

/** @return void */
function brikpanel_push_register_jobs() {
	if ( ! class_exists( 'Brikpanel_Cron' ) ) {
		return;
	}
	Brikpanel_Cron::register_handler( Brikpanel_Push_Sender::HOOK_ORDER, array( 'Brikpanel_Push_Sender', 'job_order' ), 'brikpanel_push_job_label_order' );
	Brikpanel_Cron::register_handler( Brikpanel_Push_Sender::HOOK_RETRY, array( 'Brikpanel_Push_Sender', 'job_retry' ), 'brikpanel_push_job_label_retry' );
	Brikpanel_Cron::register_handler( Brikpanel_Push_Sender::HOOK_HELD, array( 'Brikpanel_Push_Sender', 'send_held' ), 'brikpanel_push_job_label_held' );
	Brikpanel_Cron::register_handler( Brikpanel_Push_Sender::HOOK_WATCHDOG, array( 'Brikpanel_Push_Sender', 'watchdog' ), 'brikpanel_push_job_label_watchdog' );
}
add_action( 'brikpanel_cron_register', 'brikpanel_push_register_jobs' );

/** @return array Label on the Scheduled tasks screen. */
function brikpanel_push_job_label_order() {
	return array(
		'label'       => __( 'Phone notification', 'brikpanel' ),
		'description' => __( 'Sends a new order to the devices that turned on order notifications.', 'brikpanel' ),
	);
}

/** @return array */
function brikpanel_push_job_label_retry() {
	return array(
		'label'       => __( 'Phone notification, second try', 'brikpanel' ),
		'description' => __( 'Sends a notification again after a push service was busy.', 'brikpanel' ),
	);
}

/** @return array */
function brikpanel_push_job_label_held() {
	return array(
		'label'       => __( 'Phone notification summary', 'brikpanel' ),
		'description' => __( 'After a rush of orders, sends one message with the number of orders that were not announced one by one.', 'brikpanel' ),
	);
}

/** @return array */
function brikpanel_push_job_label_watchdog() {
	return array(
		'label'       => __( 'Phone notification check', 'brikpanel' ),
		'description' => __( 'Emails a person when their phone stops confirming order notifications.', 'brikpanel' ),
	);
}

// The screens (settings, the dashboard card, the AJAX they call).
if ( is_admin() ) {
	require_once __DIR__ . '/brikpanel-push-admin.php';
}
