<?php
/**
 * BrikPanel returns page inside My Account (?bp-return=1).
 *
 * Shown in place of the login form for visitors who are not logged in (the
 * order number + email form) and in place of the dashboard for customers who
 * are (their returnable orders). The markup comes from
 * brikpanel_returns_render_returns_page().
 *
 * @package BrikPanel
 * @since   3.3.52
 */

defined( 'ABSPATH' ) || exit;

if ( function_exists( 'brikpanel_returns_render_returns_page' ) ) {
	brikpanel_returns_render_returns_page();
}
