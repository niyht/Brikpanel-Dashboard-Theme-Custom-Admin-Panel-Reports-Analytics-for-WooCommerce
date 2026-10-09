<?php
/**
 * BrikPanel - phone notifications: what holds whether the module is on or off.
 *
 * The module itself (brikpanel-push.php: phone notifications and the Home
 * Screen app) loads only when wp-config.php has
 * define( 'BRIKPANEL_PHONE_APP', true ) (brikpanel_phone_app_enabled() in
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
	foreach ( array( 'brikpanel_push_vapid', 'brikpanel_push_vapid_unreadable', 'brikpanel_push_jwt' ) as $key ) {
		$map[ $key ] = array( 'class' => 'secret' );
	}
	foreach ( array( 'brikpanel_push_env', 'brikpanel_push_active', 'brikpanel_push_site', 'brikpanel_push_flood', 'brikpanel_push_keys_alerted', 'brikpanel_push_phone' ) as $key ) {
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
