<?php
/**
 * Customer returns.
 *
 * Shoppers ask for a return from My Account (or, without an account, from the
 * returns link with their order number and email); the store approves,
 * rejects, marks the goods received and refunds through WooCommerce's own
 * refund, which puts the units back in stock and pays through the gateway
 * when it can. An optional EU mode turns the same flow into the withdrawal
 * function of Directive (EU) 2023/2673 (Art. 11a of the Consumer Rights
 * Directive): "Withdraw from contract here", a separate "Confirm withdrawal"
 * step and an acknowledgement email with the content, date and time.
 *
 * Files:
 *   class-brikpanel-returns-store.php  the table and every read and write.
 *   brikpanel-returns-engine.php       rules and actions (no screens): which
 *                                      lines can go back, the refund, photos,
 *                                      guest access, privacy.
 *   brikpanel-returns-settings.php     WooCommerce > Settings > BrikPanel > Returns.
 *   brikpanel-returns-emails.php       the four emails (WooCommerce > Settings > Emails).
 *   brikpanel-returns-account.php      the shopper's side (My Account).
 *   brikpanel-returns-admin.php        the Returns screen, the order box, the badges.
 *
 * The order's own status is never changed here. WooCommerce changes it to
 * Refunded by itself when a refund leaves nothing refundable.
 *
 * @package BrikPanel
 * @since   3.3.52
 */

defined( 'ABSPATH' ) || exit;

// =============================================================================
// SETTINGS FILE REGISTRY (file scope, above any gate)
// =============================================================================

add_filter( 'brikpanel_exportable_option_keys', 'brikpanel_returns_register_export_keys' );
/**
 * The module's keys that are not settings fields.
 *
 * @param array $map Registry so far.
 * @return array
 */
function brikpanel_returns_register_export_keys( $map ) {
	// Requests per status for the menu bubble; rebuilt from the table on every change.
	$map['brikpanel_returns_counts'] = array( 'class' => 'internal' );
	return $map;
}

// =============================================================================
// SETTINGS (read helpers)
// =============================================================================

/** Default return window in days. */
const BRIKPANEL_RETURNS_DEFAULT_WINDOW = 14;

/** Days the EU withdrawal period lasts after delivery (Consumer Rights Directive, Art. 9). */
const BRIKPANEL_RETURNS_EU_DAYS = 14;

/**
 * Whether customers may request returns.
 *
 * @return bool
 */
function brikpanel_returns_enabled() {
	return 'yes' === get_option( 'brikpanel_returns_enabled', 'no' );
}

/**
 * Days after the order is completed in which a return can be requested.
 * 0 means no limit.
 *
 * @return int
 */
function brikpanel_returns_window_days() {
	$days = get_option( 'brikpanel_returns_window_days', BRIKPANEL_RETURNS_DEFAULT_WINDOW );
	$days = is_numeric( $days ) ? (int) $days : BRIKPANEL_RETURNS_DEFAULT_WINDOW;
	return max( 0, min( 3650, $days ) );
}

/**
 * Order statuses that may still produce a return, never one that means the
 * order was not paid or is already over.
 *
 * @return string[] Status keys with the wc- prefix.
 */
function brikpanel_returns_order_statuses() {
	$saved = get_option( 'brikpanel_returns_statuses', array( 'wc-completed' ) );
	$saved = is_array( $saved ) ? $saved : array();
	$known = function_exists( 'wc_get_order_statuses' ) ? wc_get_order_statuses() : array();
	$never = array( 'wc-pending', 'wc-failed', 'wc-cancelled', 'wc-refunded', 'wc-checkout-draft' );
	$out   = array();
	foreach ( $saved as $status ) {
		$status = (string) $status;
		if ( isset( $known[ $status ] ) && ! in_array( $status, $never, true ) ) {
			$out[] = $status;
		}
	}
	// Nothing selected reads as the default, like the analytics status fields.
	return $out ? array_values( array_unique( $out ) ) : array( 'wc-completed' );
}

/**
 * The reasons a customer picks from, translated defaults until the store
 * writes its own.
 *
 * @return string[]
 */
function brikpanel_returns_default_reasons() {
	return array(
		__( 'Damaged or defective', 'brikpanel' ),
		__( 'Wrong size', 'brikpanel' ),
		__( 'Not as described', 'brikpanel' ),
		__( 'Changed my mind', 'brikpanel' ),
		_x( 'Other', 'return reason', 'brikpanel' ),
	);
}

/**
 * @return string[]
 */
function brikpanel_returns_reasons() {
	$raw = get_option( 'brikpanel_returns_reasons', '' );
	if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
		return brikpanel_returns_default_reasons();
	}
	$out = array();
	foreach ( preg_split( '/\r\n|\r|\n/', $raw ) as $line ) {
		$line = trim( wp_strip_all_tags( $line ) );
		if ( '' !== $line ) {
			$out[] = function_exists( 'mb_substr' ) ? mb_substr( $line, 0, 120 ) : substr( $line, 0, 120 );
		}
	}
	$out = array_slice( array_values( array_unique( $out ) ), 0, 20 );
	return $out ? $out : brikpanel_returns_default_reasons();
}

/**
 * Photos on the request form: off, optional or required.
 *
 * @return string
 */
function brikpanel_returns_photos_mode() {
	$mode = get_option( 'brikpanel_returns_photos', 'optional' );
	return in_array( $mode, array( 'off', 'optional', 'required' ), true ) ? $mode : 'optional';
}

/**
 * Whether the EU withdrawal wording and rules are on.
 *
 * @return bool
 */
function brikpanel_returns_eu() {
	return 'yes' === get_option( 'brikpanel_returns_eu_withdrawal', 'no' );
}

// =============================================================================
// STATUSES
// =============================================================================

/**
 * Every status a return can have.
 *
 * - holds: the request still reserves its units (see the store's header).
 * - open:  the request is not finished (one open request per order).
 *
 * @return array<string,array{label:string,holds:int,open:int}>
 */
function brikpanel_returns_status_defs() {
	return array(
		'requested' => array(
			'label' => _x( 'Requested', 'return status', 'brikpanel' ),
			'holds' => 1,
			'open'  => 1,
		),
		'approved'  => array(
			'label' => _x( 'Approved', 'return status', 'brikpanel' ),
			'holds' => 1,
			'open'  => 1,
		),
		'received'  => array(
			'label' => _x( 'Item received', 'return status', 'brikpanel' ),
			'holds' => 1,
			'open'  => 1,
		),
		'resolving' => array(
			'label' => _x( 'Refunding', 'return status', 'brikpanel' ),
			'holds' => 1,
			'open'  => 1,
		),
		'refunded'  => array(
			'label' => _x( 'Refunded', 'return status', 'brikpanel' ),
			'holds' => 0,
			'open'  => 0,
		),
		'rejected'  => array(
			'label' => _x( 'Rejected', 'return status', 'brikpanel' ),
			'holds' => 0,
			'open'  => 0,
		),
		'cancelled' => array(
			'label' => _x( 'Cancelled', 'return status', 'brikpanel' ),
			'holds' => 0,
			'open'  => 0,
		),
	);
}

/**
 * @param string $status Status key.
 * @return string Label.
 */
function brikpanel_returns_status_label( $status ) {
	$defs = brikpanel_returns_status_defs();
	return isset( $defs[ $status ] ) ? $defs[ $status ]['label'] : (string) $status;
}

// =============================================================================
// LOADING
// =============================================================================

require_once __DIR__ . '/class-brikpanel-returns-store.php';
require_once __DIR__ . '/brikpanel-returns-engine.php';
// The settings section stays reachable while the module is off.
require_once __DIR__ . '/brikpanel-returns-settings.php';
// Email classes only register filters; WooCommerce builds them when its mailer loads.
require_once __DIR__ . '/brikpanel-returns-emails.php';

if ( brikpanel_returns_enabled() ) {
	require_once __DIR__ . '/brikpanel-returns-account.php';
	if ( is_admin() ) {
		require_once __DIR__ . '/brikpanel-returns-admin.php';
	}
}
