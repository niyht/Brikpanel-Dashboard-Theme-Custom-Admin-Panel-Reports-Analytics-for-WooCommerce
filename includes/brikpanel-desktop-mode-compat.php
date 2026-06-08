<?php
/**
 * BrikPanel - Desktop Mode compatibility.
 *
 * The "Desktop Mode" plugin (https://wordpress.org/plugins/desktop-mode/)
 * reimagines /wp-admin as a desktop operating system: each admin screen
 * opens inside a draggable iframe "window" rendered in chromeless mode
 * (no admin bar, no admin menu), and a unified dock built from the admin
 * menu replaces the sidebar.
 *
 * BrikPanel ships its own admin chrome that overlaps Desktop Mode's:
 *
 *   - The global top bar (Brikpanel_Dashboard_Topbar) is fixed to the top
 *     of every admin page and offsets the layout via a padding-top on the
 *     <html> element. Inside a Desktop Mode window it duplicates the window
 *     title bar, eats vertical space, and its fixed positioning fights the
 *     iframe's own scroll container. On the shell host page it paints a
 *     redundant strip over the desktop and pushes the whole shell down.
 *
 *   - The custom navigation sidebar (#brikpanel-navigation) replaces the
 *     native #adminmenu, and brikpanel-navigation.css offsets #wpcontent
 *     with a left margin sized for that sidebar. Inside a chromeless window
 *     the sidebar is hidden by Desktop Mode but the orphaned left margin
 *     remains, pushing every screen's content off to the right.
 *
 * Desktop Mode already provides those affordances (dock = navigation,
 * window title bar + shell admin bar = top bar, Cmd+K palette = global
 * search), so the fix is to stand down BrikPanel's own chrome whenever a
 * render happens under Desktop Mode and let the screen content fill the
 * window cleanly. BrikPanel's per-screen styling (orders, products,
 * editors, ...) is untouched: only the global chrome is suppressed.
 *
 * Scope of suppression — {@see brikpanel_is_desktop_mode()} is true for:
 *   - chromeless iframe windows (`desktop_mode_chromeless=1`), and
 *   - the shell host page itself (desktop mode enabled, not chromeless).
 * It is false for the per-request "classic" detached tab
 * (`desktop_mode_classic=1`), where the user explicitly asked to view a
 * single page outside the shell and therefore wants BrikPanel's full
 * chrome back.
 *
 * Everything degrades to a no-op when Desktop Mode is not installed/active:
 * the gate is a single `function_exists()` check.
 *
 * @package BrikPanel
 * @since   3.1.12
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Whether the current admin render is happening under the Desktop Mode shell.
 *
 * Returns true for both the chromeless iframe windows and the shell host
 * page, so BrikPanel's global chrome stands down in either context. Returns
 * false for the "classic" detached-tab override (the one case where a
 * desktop-mode user is deliberately viewing a page outside the shell) and
 * whenever the Desktop Mode plugin is absent.
 *
 * The result is cached per-request: the underlying Desktop Mode helpers read
 * user meta and request flags that do not change within a single page load.
 *
 * @since 3.1.12
 *
 * @return bool
 */
function brikpanel_is_desktop_mode() {
    static $is = null;
    if ( null !== $is ) {
        return $is;
    }

    // Desktop Mode plugin not present/active — nothing to integrate with.
    if ( ! function_exists( 'desktop_mode_is_enabled' ) ) {
        $is = false;
        return $is;
    }

    // Detached "classic" tab: the user explicitly opened this single page
    // outside the shell, so keep BrikPanel's full chrome intact.
    if ( function_exists( 'desktop_mode_is_classic_request' ) && desktop_mode_is_classic_request() ) {
        $is = false;
        return $is;
    }

    $is = (bool) desktop_mode_is_enabled();
    return $is;
}

/**
 * Whether the current render is a Desktop Mode *chromeless* iframe window
 * (as opposed to the shell host page). Used to scope the content-layout
 * override stylesheet, which only matters inside windows.
 *
 * @since 3.1.12
 *
 * @return bool
 */
function brikpanel_is_desktop_mode_chromeless() {
    return function_exists( 'desktop_mode_is_chromeless_request' )
        && desktop_mode_is_chromeless_request();
}

/**
 * Inside a chromeless window, neutralise BrikPanel's full-screen layout
 * assumptions so each screen's content sits flush in the window.
 *
 * BrikPanel's per-screen stylesheets pin a few sticky headers to the top of
 * the viewport with an offset that assumes either the WordPress admin bar
 * (`top: 32px`) or BrikPanel's own top bar height. Desktop Mode strips the
 * admin bar in chromeless mode and we suppress the BrikPanel top bar, so
 * those offsets would leave a gap or float the header at the wrong place.
 * We re-pin them to the top of the window. Scoped tightly to
 * `.desktop-mode-chromeless` so nothing leaks into classic admin.
 *
 * Hooked on `desktop_mode_chromeless_styles`, the action Desktop Mode fires
 * after enqueuing its own chromeless stylesheet (see the plugin's
 * includes/render/assets.php), which is the documented extension point for
 * exactly this kind of override.
 *
 * @since 3.1.12
 *
 * @return void
 */
function brikpanel_desktop_mode_chromeless_overrides() {
    $css = <<<CSS
/* BrikPanel x Desktop Mode: content fills the chromeless window. */
body.desktop-mode-chromeless #wpcontent,
body.desktop-mode-chromeless #wpbody-content {
    margin-left: 0 !important;
    padding-left: 0 !important;
}
/* Sticky BrikPanel screen headers assume an admin bar / top bar above them;
   there is neither inside a window, so pin them to the very top. */
body.desktop-mode-chromeless .brikpanel-order-header,
body.desktop-mode-chromeless .brikpanel-pl-sticky,
body.desktop-mode-chromeless .brikpanel-editor-header {
    top: 0 !important;
}
CSS;

    wp_register_style( 'brikpanel-desktop-mode-compat', false, array(), BRIKPANEL_VERSION );
    wp_enqueue_style( 'brikpanel-desktop-mode-compat' );
    wp_add_inline_style( 'brikpanel-desktop-mode-compat', $css );
}
add_action( 'desktop_mode_chromeless_styles', 'brikpanel_desktop_mode_chromeless_overrides' );
