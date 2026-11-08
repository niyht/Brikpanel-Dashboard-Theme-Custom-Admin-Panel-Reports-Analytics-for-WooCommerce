<?php
/**
 * BrikPanel - phone notifications: what holds whether the module is on or off.
 *
 * The module itself (brikpanel-push.php: phone notifications and the Home
 * Screen app) loads on every site unless wp-config.php has
 * define( 'BRIKPANEL_PHONE_APP', false ) (brikpanel_phone_app_enabled() in
 * brikpanel.php). This file loads either way:
 * - the export keys: a module that is off still owns its keys, so an export
 *   never carries a signing key a test left behind
 *   (tools/export-coverage-audit.php, rule D);
 * - the jobs: with the module off, a notification job a test left queued
 *   finishes quietly instead of failing with "no callbacks are registered".
 *
 * @package BrikPanel
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Export registry: classified at file scope, above every gate
 * (includes/brikpanel-export-registry.php). The switch itself travels as a
 * settings field (portable); the keys, tokens and counters never leave the site.
 *
 * @param array $map Registry so far.
 * @return array
 */
function brikpanel_push_register_export_keys( $map ) {
	$map['brikpanel_push_enabled'] = array(
		'class'   => 'portable',
		'group'   => 'notifications',
		'default' => 'yes',
	);
	// "Notification text": no row = brikpanel_push_parts_default().
	$map['brikpanel_push_parts'] = array(
		'class'    => 'portable',
		'group'    => 'notifications',
		'default'  => '',
		'sanitize' => 'brikpanel_push_parts_sanitize',
	);
	foreach ( array( 'brikpanel_push_vapid', 'brikpanel_push_vapid_unreadable', 'brikpanel_push_jwt' ) as $key ) {
		$map[ $key ] = array( 'class' => 'secret' );
	}
	// brikpanel_push_auto_off: where one person closed the top bar phone button's panel for good ('tab' or 'app').
	foreach ( array( 'brikpanel_push_env', 'brikpanel_push_active', 'brikpanel_push_site', 'brikpanel_push_flood', 'brikpanel_push_keys_alerted', 'brikpanel_push_phone', 'brikpanel_push_auto_off' ) as $key ) {
		$map[ $key ] = array( 'class' => 'internal' );
	}
	return $map;
}
add_filter( 'brikpanel_exportable_option_keys', 'brikpanel_push_register_export_keys' );

/**
 * Module off: its jobs stand down (includes/cron/class-brikpanel-cron.php).
 * The hooks are Brikpanel_Push_Sender's HOOK_* constants, written out because
 * the class is not loaded while the module is off.
 *
 * @return void
 */
function brikpanel_push_stand_down() {
	if ( brikpanel_phone_app_enabled() || ! class_exists( 'Brikpanel_Cron' ) || ! Brikpanel_Cron::is_available() ) {
		return;
	}
	Brikpanel_Cron::stand_down( array( 'brikpanel_push_order', 'brikpanel_push_retry', 'brikpanel_push_held', 'brikpanel_push_watchdog' ) );
}
add_action( 'brikpanel_cron_register', 'brikpanel_push_stand_down' );

/**
 * "Notification text" (Settings > Notifications, apattee, 29 October 2026):
 * which parts an order notification shows, and in which order. Stored as JSON
 * {"order": every part, "on": the ones shown}, so a part that is off keeps its
 * place. These live here, not in the module, so an import cleans the value
 * while the module is off too.
 *
 * @return string[] Every part, in the default order.
 */
function brikpanel_push_parts_keys() {
	return array( 'total', 'items', 'number', 'skus', 'campaign', 'store' );
}

/**
 * Before the store chooses: total, items and order number. The store's name
 * is off because the app on the phone is named after the store (owner
 * decision, 30 October 2026).
 *
 * @return array{order:string[],on:string[]}
 */
function brikpanel_push_parts_default() {
	return array(
		'order' => brikpanel_push_parts_keys(),
		'on'    => array( 'total', 'items', 'number' ),
	);
}

/**
 * Known parts only, each once; a part missing from a saved list (one added in
 * a later version) joins at the end, off. Everything off is a valid choice.
 *
 * @param mixed $raw JSON string or array.
 * @return array{order:string[],on:string[]}
 */
function brikpanel_push_parts_clean( $raw ) {
	if ( is_string( $raw ) ) {
		$raw = json_decode( $raw, true );
	}
	if ( ! is_array( $raw ) || ! isset( $raw['order'] ) || ! is_array( $raw['order'] ) ) {
		return brikpanel_push_parts_default();
	}
	$known = brikpanel_push_parts_keys();
	$order = array();
	foreach ( $raw['order'] as $key ) {
		if ( is_string( $key ) && in_array( $key, $known, true ) && ! in_array( $key, $order, true ) ) {
			$order[] = $key;
		}
	}
	foreach ( $known as $key ) {
		if ( ! in_array( $key, $order, true ) ) {
			$order[] = $key;
		}
	}
	$wanted = isset( $raw['on'] ) && is_array( $raw['on'] ) ? $raw['on'] : array();
	$on     = array();
	foreach ( $order as $key ) {
		if ( in_array( $key, $wanted, true ) ) {
			$on[] = $key;
		}
	}
	return array(
		'order' => $order,
		'on'    => $on,
	);
}

/**
 * The stored form (also the import cleaner of the export registry).
 *
 * @param mixed $raw JSON string or array.
 * @return string JSON.
 */
function brikpanel_push_parts_sanitize( $raw ) {
	return (string) wp_json_encode( brikpanel_push_parts_clean( $raw ) );
}

/**
 * The store's choice, or the default when it never chose.
 *
 * @return array{order:string[],on:string[]}
 */
function brikpanel_push_parts() {
	$raw = get_option( 'brikpanel_push_parts', '' );
	return ( '' === $raw || false === $raw ) ? brikpanel_push_parts_default() : brikpanel_push_parts_clean( $raw );
}
