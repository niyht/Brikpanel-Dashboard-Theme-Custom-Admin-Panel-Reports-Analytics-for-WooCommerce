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
 * The same file keeps "Screen Options" off a screen where WordPress itself
 * found nothing to put in it, so another plugin cannot force an empty one on.
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

/**
 * WordPress' own "Screen Options" answer per screen, noted before any other
 * plugin's filter can change it.
 *
 * @param string    $screen_id Screen id.
 * @param bool|null $answer    Answer to note, or null to read the noted one.
 * @return bool|null The noted answer, or null when none was noted.
 */
function brikpanel_screen_options_wp_answer( $screen_id, $answer = null ) {
    static $answers = [];
    if ( null !== $answer ) {
        $answers[ $screen_id ] = (bool) $answer;
    }
    return isset( $answers[ $screen_id ] ) ? $answers[ $screen_id ] : null;
}

/**
 * Note WordPress' answer: it says yes only when the screen has something to
 * set (boxes, list columns, items per page or a plugin's own settings).
 *
 * @param bool           $show   WordPress' answer.
 * @param WP_Screen|null $screen Current screen.
 * @return bool Unchanged.
 */
function brikpanel_screen_options_note_wp_answer( $show, $screen = null ) {
    if ( $screen instanceof WP_Screen ) {
        brikpanel_screen_options_wp_answer( $screen->id, $show );
    }
    return $show;
}
add_filter( 'screen_options_show_screen', 'brikpanel_screen_options_note_wp_answer', -PHP_INT_MAX, 2 );

/**
 * Keep an empty "Screen Options" off: WordPress' "no" wins at the end.
 *
 * Advanced Access Manager (7.0 to 8.0 at least) answers this filter with its
 * own capability check and drops WordPress' answer, so every admin screen
 * showed the toggle: the BrikPanel dashboard, the products list and
 * WordPress' own settings pages got a button that opened an empty panel. A
 * plugin can still hide the toggle, it can no longer show an empty one.
 *
 * @param bool           $show   Answer after every other filter.
 * @param WP_Screen|null $screen Current screen.
 * @return bool
 */
function brikpanel_screen_options_keep_empty_hidden( $show, $screen = null ) {
    if ( $show && $screen instanceof WP_Screen
        && false === brikpanel_screen_options_wp_answer( $screen->id ) ) {
        return false;
    }
    return $show;
}
add_filter( 'screen_options_show_screen', 'brikpanel_screen_options_keep_empty_hidden', PHP_INT_MAX, 2 );
