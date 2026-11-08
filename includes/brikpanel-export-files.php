<?php
/**
 * BrikPanel - the folder that holds an export while it is being written.
 *
 * A long export (the orders list, front-end/orders/brikpanel-order-export.php)
 * is written over many requests, so its parts live on disk between them:
 * wp-content/uploads/brikpanel-exports/ (per site on a multisite). Uploads is
 * the one folder WordPress can always write to and, on hosting with several
 * web servers, the one they share; the system temp folder is neither.
 *
 * The files hold customer names and addresses, so:
 * - every name starts with 32 random hex characters nobody can guess;
 * - the folder refuses web access on Apache (.htaccess) and has no listing
 *   (index.php); on other servers the random name is the guard;
 * - a file is deleted as soon as it was downloaded or the export cancelled,
 *   and anything older than two hours is swept: at the start of the next
 *   export and by a one-off cron event three hours after each export starts.
 *
 * This file is loaded on every request (it is tiny) because the cron event
 * runs outside wp-admin, where the export module is not loaded.
 *
 * @package BrikPanel
 */

defined( 'ABSPATH' ) || exit;

/** Files older than this many seconds are left over from an abandoned export. */
const BRIKPANEL_EXPORT_FILE_MAX_AGE = 7200;

/**
 * The export folder, created and protected on first use.
 *
 * @param bool $create Create it when missing.
 * @return string|false Absolute path without a trailing slash, false when it
 *                      cannot be used.
 */
function brikpanel_export_dir( $create = true ) {
	// false: do not create this month's uploads folder as a side effect.
	$uploads = wp_upload_dir( null, false );
	if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
		return false;
	}
	$dir = untrailingslashit( $uploads['basedir'] ) . '/brikpanel-exports';
	if ( ! is_dir( $dir ) ) {
		if ( ! $create || ! wp_mkdir_p( $dir ) ) {
			return false;
		}
	}
	if ( $create ) {
		if ( ! file_exists( $dir . '/index.php' ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a fixed file in our own folder.
			file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" );
		}
		if ( ! file_exists( $dir . '/.htaccess' ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a fixed file in our own folder.
			file_put_contents(
				$dir . '/.htaccess',
				"# BrikPanel: exports are downloaded through wp-admin only.\n"
				. "<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n"
				. "<IfModule !mod_authz_core.c>\n\tOrder deny,allow\n\tDeny from all\n</IfModule>\n"
			);
		}
		if ( ! wp_is_writable( $dir ) ) {
			return false;
		}
	}
	return $dir;
}

/**
 * Delete export files older than $max_age seconds. Only names this plugin
 * writes (32 hex characters, then a dot or a dash) are touched.
 *
 * @param int $max_age Seconds.
 * @return int Files deleted.
 */
function brikpanel_export_sweep( $max_age = BRIKPANEL_EXPORT_FILE_MAX_AGE ) {
	$dir = brikpanel_export_dir( false );
	if ( ! $dir ) {
		return 0;
	}
	$deleted = 0;
	$cutoff  = time() - max( 0, (int) $max_age );
	$names   = scandir( $dir );
	foreach ( is_array( $names ) ? $names : array() as $name ) {
		if ( ! preg_match( '/^[a-f0-9]{32}[.-][a-z0-9.-]+$/', $name ) ) {
			continue;
		}
		$path  = $dir . '/' . $name;
		$mtime = @filemtime( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a file may vanish between the listing and here.
		if ( is_file( $path ) && false !== $mtime && $mtime < $cutoff ) {
			wp_delete_file( $path );
			$deleted++;
		}
	}
	return $deleted;
}

/**
 * Ask WP-Cron to sweep once, a little after the files of the export that just
 * started would count as left over. WordPress drops a duplicate event within
 * ten minutes, and an existing one is kept.
 *
 * @return void
 */
function brikpanel_export_schedule_sweep() {
	if ( ! wp_next_scheduled( 'brikpanel_export_sweep' ) ) {
		wp_schedule_single_event( time() + BRIKPANEL_EXPORT_FILE_MAX_AGE + HOUR_IN_SECONDS, 'brikpanel_export_sweep' );
	}
}

/**
 * The cron event's handler.
 *
 * @return void
 */
function brikpanel_export_sweep_event() {
	brikpanel_export_sweep();
}
add_action( 'brikpanel_export_sweep', 'brikpanel_export_sweep_event' );
