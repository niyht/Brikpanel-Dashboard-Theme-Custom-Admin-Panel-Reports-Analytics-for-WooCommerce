<?php
/**
 * BrikPanel — Cron / Background Jobs bootstrap.
 *
 * Loads the Brikpanel_Cron wrapper (public scheduling API on top of
 * Action Scheduler) and the Scheduled Tasks admin page. Recurring jobs
 * needed by other modules (OAuth token refresh, Sheets sync, marketplace
 * sync, etc.) register themselves on the `brikpanel_cron_register` action,
 * which fires once Action Scheduler is guaranteed to be loaded.
 *
 * @package BrikPanel
 * @since   3.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-brikpanel-cron.php';

if ( is_admin() ) {
	require_once __DIR__ . '/class-brikpanel-cron-page.php';
}

/**
 * Provide a single, well-defined hook that other modules use to register
 * their recurring jobs / handlers. Fires after WooCommerce + Action
 * Scheduler are both ready.
 *
 * Modules should hook in like:
 *
 *   add_action( 'brikpanel_cron_register', function () {
 *       Brikpanel_Cron::register_handler( 'brikpanel_my_job', 'my_handler' );
 *       Brikpanel_Cron::schedule_recurring( 'brikpanel_my_job', HOUR_IN_SECONDS );
 *   } );
 *
 * The action runs at `init` priority 20 to give other plugins (notably
 * WooCommerce) time to bootstrap their own dependencies first.
 */
add_action( 'init', function () {
	if ( ! Brikpanel_Cron::is_available() ) {
		return;
	}
	/**
	 * Fires once per request when Action Scheduler is ready and BrikPanel
	 * background jobs can be registered/scheduled.
	 */
	do_action( 'brikpanel_cron_register' );
}, 20 );
