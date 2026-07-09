<?php
/**
 * BrikPanel - Remove the WordPress "Help" tab across the admin
 *
 * The store owner wants a clean, distraction-free admin with no generic
 * WordPress "Help" buttons. WordPress renders the "Help" toggle (top-right,
 * beside "Screen Options") on any screen that has registered help tabs or a
 * help sidebar. Clearing both on every admin screen removes the button
 * everywhere while leaving "Screen Options" untouched — that one stays useful
 * (column pickers, items-per-page, etc.).
 *
 * Done server-side so the button never renders (no flash, nothing to override),
 * with a tiny inline style as a fallback for the rare plugin that registers a
 * help tab too late to be caught by the removal.
 *
 * @package BrikPanel
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Strip every help tab (and the help sidebar) from the current admin screen so
 * WordPress does not render the "Help" toggle.
 *
 * Hooked late on admin_head: core, WooCommerce and other plugins register their
 * help tabs earlier (on load-{page} / current_screen / lower-priority
 * admin_head), and wp-admin/admin-header.php calls render_screen_meta() only
 * after admin_head has finished — so by removal time the tab list is complete
 * and the toggle has not been drawn yet.
 */
function brikpanel_remove_admin_help_tabs() {
    if ( ! function_exists( 'get_current_screen' ) ) {
        return;
    }
    $screen = get_current_screen();
    if ( $screen instanceof WP_Screen ) {
        $screen->remove_help_tabs();
        $screen->set_help_sidebar( '' );
    }
}
add_action( 'admin_head', 'brikpanel_remove_admin_help_tabs', 999 );

/**
 * Fallback: hide the "Help" toggle and its slide-out panel with a tiny global
 * style, covering the rare plugin that registers a help tab after the removal
 * above runs. Scoped strictly to the Help elements — "Screen Options" is left
 * alone.
 */
function brikpanel_hide_admin_help_css() {
    echo '<style id="brikpanel-hide-help">#screen-meta-links #contextual-help-link-wrap{display:none!important}#contextual-help-wrap{display:none!important}</style>' . "\n";
}
add_action( 'admin_head', 'brikpanel_hide_admin_help_css', 1000 );
