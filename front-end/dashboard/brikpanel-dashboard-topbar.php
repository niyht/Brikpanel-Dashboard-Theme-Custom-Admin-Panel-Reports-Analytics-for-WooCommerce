<?php
/**
 * BrikPanel - Global Admin Top Bar
 *
 * Replaces the WordPress admin bar across the entire WordPress admin with an
 * e-commerce focused top bar (live KPIs, global search, quick-create,
 * order notifications, user menu).
 *
 * @package BrikPanel
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Brikpanel_Dashboard_Topbar {

    const OPTION_KEY   = 'brikpanel_dashboard_topbar';
    const NONCE_ACTION = 'brikpanel_topbar_nonce';

    private static $instance = null;

    public static function instance() {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        if ( ! $this->is_enabled() ) {
            return;
        }
        // Mark <html> and <body> so our CSS can offset the admin layout and
        // hide the native WP admin bar.
        add_filter( 'admin_body_class', [ $this, 'add_body_class' ] );
        add_action( 'admin_head',       [ $this, 'print_html_class_script' ], 0 );

        // Render the topbar at the very top of every admin page, before the
        // main #wpbody-content block.
        add_action( 'in_admin_header', [ $this, 'render' ] );

        // Enqueue topbar assets on every admin page.
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );

        // AJAX endpoint for live topbar stats.
        add_action( 'wp_ajax_brikpanel_topbar_stats', [ $this, 'ajax_stats' ] );
    }

    // =========================================================================
    // GATING
    // =========================================================================

    public static function is_enabled() {
        return get_option( self::OPTION_KEY, 'yes' ) === 'yes';
    }

    /**
     * Decide whether the topbar should render on the current admin request.
     * We intentionally skip block-editor screens (Gutenberg post/page editor,
     * site editor, navigation editor, widgets editor) because Gutenberg takes
     * over the admin chrome with its own toolbar + fullscreen mode, and our
     * fixed topbar would overlay its header and break its layout math (the
     * `interface-interface-skeleton__html-container` class on <html> assumes
     * no extra top padding). Also skipped: AJAX / customizer requests where
     * there's no visible admin layout.
     */
    private function should_render() {
        if ( ! is_admin() ) {
            return false;
        }
        if ( wp_doing_ajax() ) {
            return false;
        }
        // Under the Desktop Mode shell, the window title bar + shell admin
        // bar already provide what our top bar offers; rendering it would
        // duplicate chrome inside every window and offset the layout. Stand
        // down (see includes/brikpanel-desktop-mode-compat.php).
        if ( function_exists( 'brikpanel_is_desktop_mode' ) && brikpanel_is_desktop_mode() ) {
            return false;
        }
        // Network Admin and User Admin are not per-site contexts — the topbar
        // would show the main blog's site name, link to a subsite-scoped
        // dashboard, and overlay the super-admin chrome. Stay out of those
        // contexts entirely.
        if ( is_network_admin() || is_user_admin() ) {
            return false;
        }
        if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
            return false;
        }

        // Site editor (full-site editing). Its own page slug, not a screen
        // we can detect via `is_block_editor()` reliably, so gate on $pagenow.
        global $pagenow;
        if ( isset( $pagenow ) && in_array( $pagenow, [ 'site-editor.php', 'customize.php' ], true ) ) {
            return false;
        }

        // Block editor screens — covers post.php / post-new.php when the
        // post type uses Gutenberg, the widgets block editor, and the
        // navigation editor. Falls back to false on legacy classic-editor
        // screens (where we still want the topbar to appear).
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        if ( $screen && method_exists( $screen, 'is_block_editor' ) && $screen->is_block_editor() ) {
            return false;
        }

        return true;
    }

    // =========================================================================
    // BODY CLASSES & HTML CLASS (for CSS layout offsets)
    // =========================================================================

    public function add_body_class( $classes ) {
        if ( $this->should_render() ) {
            $classes .= ' brikpanel-has-topbar';
        }
        return $classes;
    }

    /**
     * Tag <html> with the same marker class so we can adjust padding-top on
     * the root element (the native admin-bar relies on `.wp-toolbar` for the
     * same thing — we mirror the pattern).
     */
    public function print_html_class_script() {
        if ( ! $this->should_render() ) {
            return;
        }
        echo '<script>document.documentElement.classList.add("brikpanel-has-topbar");</script>';
    }

    // =========================================================================
    // ASSET ENQUEUE
    // =========================================================================

    public function enqueue_assets() {
        if ( ! $this->should_render() ) {
            return;
        }

        wp_enqueue_style(
            'brikpanel_topbar_styles',
            BRIKPANEL_URL . 'front-end/topbar/brikpanel-topbar.css',
            [],
            BRIKPANEL_VERSION
        );

        wp_enqueue_script(
            'brikpanel_topbar_scripts',
            BRIKPANEL_URL . 'front-end/topbar/brikpanel-topbar.js',
            [],
            BRIKPANEL_VERSION,
            true
        );

        wp_localize_script( 'brikpanel_topbar_scripts', 'brikpanelTopbar', [
            'ajax_url'        => admin_url( 'admin-ajax.php' ),
            'nonce'           => wp_create_nonce( self::NONCE_ACTION ),
            'cache_nonce'     => class_exists( 'Brikpanel_Cache_Clear' ) ? wp_create_nonce( Brikpanel_Cache_Clear::NONCE_ACTION ) : '',
            'cache_action'    => class_exists( 'Brikpanel_Cache_Clear' ) ? Brikpanel_Cache_Clear::AJAX_ACTION : '',
            'i18n'            => [
                'cache_clearing' => __( 'Clearing cache…', 'brikpanel' ),
                'cache_failed'   => __( 'Cache could not be cleared.', 'brikpanel' ),
                'close'          => __( 'Close', 'brikpanel' ),
            ],
        ] );
    }

    // =========================================================================
    // RENDER TOPBAR MARKUP
    // =========================================================================

    public function render() {
        if ( ! $this->should_render() ) {
            return;
        }

        $site_name    = get_bloginfo( 'name' );
        $site_url     = home_url( '/' );
        $current_user = wp_get_current_user();
        $avatar_url   = get_avatar_url( $current_user->ID, [ 'size' => 64 ] );
        $display_name = $current_user->display_name ?: $current_user->user_login;
        $profile_url  = admin_url( 'profile.php' );
        $logout_url   = wp_logout_url( admin_url() );

        // Custom brand logo overrides the default BrikPanel mark when the
        // store owner has uploaded one and flipped the Appearance toggle. Helper
        // lives in front-end/appearance/brikpanel-appearance.php (always loaded).
        $brand_logo_url = function_exists( 'brikpanel_brand_logo_get_url' ) ? brikpanel_brand_logo_get_url() : '';
        $has_brand_logo = $brand_logo_url !== '';
        $icon_url       = $has_brand_logo ? $brand_logo_url : BRIKPANEL_URL . 'assets/icon.png';
        $mark_class     = $has_brand_logo ? 'brikpanel-topbar-brand-mark has-custom-logo' : 'brikpanel-topbar-brand-mark';

        ?>
        <header class="brikpanel-topbar" id="brikpanel-topbar" role="banner">
            <div class="brikpanel-topbar-inner">

                <!-- ========== LEFT ========== -->
                <div class="brikpanel-topbar-left">
                    <!-- Mobile: hamburger to toggle the WP sidebar (replaces the
                         #wp-admin-bar-menu-toggle that lives inside the now-hidden
                         WP admin bar). -->
                    <button type="button" class="brikpanel-topbar-menu-btn" id="brikpanel-topbar-menu-btn" aria-label="<?php esc_attr_e( 'Toggle navigation', 'brikpanel' ); ?>" aria-expanded="false">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
                    </button>

                    <a class="brikpanel-topbar-brand" href="<?php echo esc_url( admin_url( 'admin.php?page=brikpanel-dashboard' ) ); ?>" aria-label="<?php esc_attr_e( 'BrikPanel dashboard', 'brikpanel' ); ?>">
                        <span class="<?php echo esc_attr( $mark_class ); ?>" aria-hidden="true">
                            <img src="<?php echo esc_url( $icon_url ); ?>" alt="" width="32" height="32">
                        </span>
                        <span class="brikpanel-topbar-brand-text">
                            <span class="brikpanel-topbar-brand-name"><?php echo esc_html( $site_name ); ?></span>
                            <span class="brikpanel-topbar-brand-sub"><?php esc_html_e( 'Admin', 'brikpanel' ); ?></span>
                        </span>
                    </a>

                    <span class="brikpanel-topbar-live-pill is-empty" id="brikpanel-topbar-live" title="<?php esc_attr_e( 'Live visitors right now', 'brikpanel' ); ?>">
                        <span class="brikpanel-topbar-live-dot"></span>
                        <span class="brikpanel-topbar-live-count" id="brikpanel-topbar-live-count">0</span>
                        <span class="brikpanel-topbar-live-label"><?php esc_html_e( 'live', 'brikpanel' ); ?></span>
                    </span>
                </div>

                <!-- ========== RIGHT ========== -->
                <div class="brikpanel-topbar-right">

                    <!-- Search trigger (opens the existing brikpanel-search overlay) -->
                    <button type="button" class="brikpanel-topbar-search-btn" id="brikpanel-topbar-search" aria-label="<?php esc_attr_e( 'Search orders', 'brikpanel' ); ?>">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                        <span class="brikpanel-topbar-search-text"><?php esc_html_e( 'Search', 'brikpanel' ); ?></span>
                        <span class="brikpanel-topbar-kbd"><span class="brikpanel-topbar-kbd-key" id="brikpanel-topbar-kbd-mod">Ctrl</span><span>+</span><span class="brikpanel-topbar-kbd-key">K</span></span>
                    </button>

                    <!-- Quick create -->
                    <div class="brikpanel-topbar-menu" data-topbar-menu="create">
                        <button type="button" class="brikpanel-topbar-btn brikpanel-topbar-btn-primary" data-topbar-toggle="create" aria-haspopup="menu" aria-expanded="false">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                            <span class="brikpanel-topbar-btn-label"><?php esc_html_e( 'Create', 'brikpanel' ); ?></span>
                        </button>
                        <div class="brikpanel-topbar-dropdown" role="menu">
                            <a class="brikpanel-topbar-dropdown-item" role="menuitem" href="<?php echo esc_url( admin_url( 'admin.php?page=brikpanel-product-editor' ) ); ?>">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M20 7l-8-4-8 4 8 4 8-4z"/><path d="M4 7v10l8 4 8-4V7"/><line x1="12" y1="11" x2="12" y2="21"/></svg>
                                <span><?php esc_html_e( 'New product', 'brikpanel' ); ?></span>
                            </a>
                            <a class="brikpanel-topbar-dropdown-item" role="menuitem" href="<?php echo esc_url( admin_url( 'post-new.php?post_type=shop_order' ) ); ?>">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2l1 4h10l1-4"/><path d="M5 6h14l-1 15H6L5 6z"/><path d="M9 10v6"/><path d="M15 10v6"/></svg>
                                <span><?php esc_html_e( 'New order', 'brikpanel' ); ?></span>
                            </a>
                            <a class="brikpanel-topbar-dropdown-item" role="menuitem" href="<?php echo esc_url( admin_url( 'admin.php?page=brikpanel-coupons&action=new' ) ); ?>">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M20 12a2 2 0 0 1 2-2V6a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v4a2 2 0 0 1 2 2 2 2 0 0 1-2 2v4a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-4a2 2 0 0 1-2-2z"/><line x1="9" y1="9" x2="15" y2="15"/><circle cx="9" cy="9" r=".6"/><circle cx="15" cy="15" r=".6"/></svg>
                                <span><?php esc_html_e( 'New coupon', 'brikpanel' ); ?></span>
                            </a>
                            <a class="brikpanel-topbar-dropdown-item" role="menuitem" href="<?php echo esc_url( admin_url( 'post-new.php' ) ); ?>">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/></svg>
                                <span><?php esc_html_e( 'New post', 'brikpanel' ); ?></span>
                            </a>
                        </div>
                    </div>

                    <!-- Notifications -->
                    <div class="brikpanel-topbar-menu" data-topbar-menu="notifications">
                        <button type="button" class="brikpanel-topbar-icon-btn" data-topbar-toggle="notifications" aria-haspopup="menu" aria-expanded="false" aria-label="<?php esc_attr_e( 'Order notifications', 'brikpanel' ); ?>">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 8a6 6 0 1 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/></svg>
                            <span class="brikpanel-topbar-badge" id="brikpanel-topbar-notif-badge" hidden>0</span>
                        </button>
                        <div class="brikpanel-topbar-dropdown brikpanel-topbar-dropdown-wide" role="menu">
                            <div class="brikpanel-topbar-dropdown-header">
                                <span><?php esc_html_e( 'Needs attention', 'brikpanel' ); ?></span>
                            </div>
                            <a class="brikpanel-topbar-dropdown-row" role="menuitem" href="<?php echo esc_url( admin_url( 'admin.php?page=wc-orders&status=processing' ) ); ?>">
                                <span class="brikpanel-topbar-dropdown-row-label"><?php esc_html_e( 'Processing orders', 'brikpanel' ); ?></span>
                                <span class="brikpanel-topbar-dropdown-row-count" id="brikpanel-topbar-notif-processing">0</span>
                            </a>
                            <a class="brikpanel-topbar-dropdown-row" role="menuitem" href="<?php echo esc_url( admin_url( 'admin.php?page=wc-orders&status=pending' ) ); ?>">
                                <span class="brikpanel-topbar-dropdown-row-label"><?php esc_html_e( 'Pending payment', 'brikpanel' ); ?></span>
                                <span class="brikpanel-topbar-dropdown-row-count" id="brikpanel-topbar-notif-pending">0</span>
                            </a>
                            <a class="brikpanel-topbar-dropdown-row" role="menuitem" href="<?php echo esc_url( admin_url( 'admin.php?page=wc-orders&status=on-hold' ) ); ?>">
                                <span class="brikpanel-topbar-dropdown-row-label"><?php esc_html_e( 'On hold', 'brikpanel' ); ?></span>
                                <span class="brikpanel-topbar-dropdown-row-count" id="brikpanel-topbar-notif-onhold">0</span>
                            </a>
                            <a class="brikpanel-topbar-dropdown-row" role="menuitem" href="<?php echo esc_url( admin_url( 'edit.php?post_type=product&stock_status=outofstock' ) ); ?>">
                                <span class="brikpanel-topbar-dropdown-row-label"><?php esc_html_e( 'Out of stock', 'brikpanel' ); ?></span>
                                <span class="brikpanel-topbar-dropdown-row-count" id="brikpanel-topbar-notif-oos">0</span>
                            </a>
                            <a class="brikpanel-topbar-dropdown-row" role="menuitem" href="<?php echo esc_url( admin_url( 'users.php?role=customer' ) ); ?>">
                                <span class="brikpanel-topbar-dropdown-row-label"><?php esc_html_e( 'New customers today', 'brikpanel' ); ?></span>
                                <span class="brikpanel-topbar-dropdown-row-count" id="brikpanel-topbar-notif-customers">0</span>
                            </a>
                        </div>
                    </div>

                    <?php
                    if ( class_exists( 'Brikpanel_BrikControl' ) ) {
                        Brikpanel_BrikControl::instance()->render_topbar_button();
                    }
                    ?>

                    <?php
                    // Master on/off switch (administrators only). Lets an admin
                    // hand the whole store the classic WordPress admin in one
                    // click while BrikPanel is still being tuned.
                    if ( class_exists( 'Brikpanel_Master_Switch' ) ) {
                        Brikpanel_Master_Switch::instance()->render_topbar_switch();
                    }
                    ?>

                    <?php $this->render_cache_clear_button(); ?>

                    <!-- View site -->
                    <a class="brikpanel-topbar-icon-btn" href="<?php echo esc_url( $site_url ); ?>" target="_blank" rel="noopener" title="<?php esc_attr_e( 'View store', 'brikpanel' ); ?>" aria-label="<?php esc_attr_e( 'View store', 'brikpanel' ); ?>">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
                    </a>

                    <!-- User menu -->
                    <div class="brikpanel-topbar-menu brikpanel-topbar-menu-user" data-topbar-menu="user">
                        <button type="button" class="brikpanel-topbar-user-btn" data-topbar-toggle="user" aria-haspopup="menu" aria-expanded="false">
                            <img src="<?php echo esc_url( $avatar_url ); ?>" alt="" width="28" height="28" loading="lazy">
                            <span class="brikpanel-topbar-user-name"><?php echo esc_html( $display_name ); ?></span>
                            <svg class="brikpanel-topbar-user-caret" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="6 9 12 15 18 9"/></svg>
                        </button>
                        <div class="brikpanel-topbar-dropdown brikpanel-topbar-dropdown-user" role="menu">
                            <div class="brikpanel-topbar-user-card">
                                <img src="<?php echo esc_url( $avatar_url ); ?>" alt="" width="36" height="36">
                                <div class="brikpanel-topbar-user-card-text">
                                    <strong><?php echo esc_html( $display_name ); ?></strong>
                                    <span><?php echo esc_html( $current_user->user_email ); ?></span>
                                </div>
                            </div>
                            <a class="brikpanel-topbar-dropdown-item" role="menuitem" href="<?php echo esc_url( $profile_url ); ?>">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                                <span><?php esc_html_e( 'Your profile', 'brikpanel' ); ?></span>
                            </a>
                            <a class="brikpanel-topbar-dropdown-item" role="menuitem" href="<?php echo esc_url( admin_url( 'admin.php?page=wc-settings&tab=brikpanel' ) ); ?>">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
                                <span><?php esc_html_e( 'BrikPanel settings', 'brikpanel' ); ?></span>
                            </a>
                            <div class="brikpanel-topbar-dropdown-sep" aria-hidden="true"></div>
                            <a class="brikpanel-topbar-dropdown-item brikpanel-topbar-dropdown-item-danger" role="menuitem" href="<?php echo esc_url( $logout_url ); ?>">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                                <span><?php esc_html_e( 'Log out', 'brikpanel' ); ?></span>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </header>
        <?php
    }

    // =========================================================================
    // CACHE CLEAR BUTTON
    // =========================================================================

    /**
     * Renders a one-click "Clear cache" control in the topbar when one or more
     * supported cache / optimization plugins are active.
     *
     * - 1 active plugin  → single icon button that purges that plugin.
     * - 2+ active plugins → icon button + dropdown listing each plugin and a
     *   "Clear all" entry.
     * - 0 active plugins → nothing rendered (no DOM cost).
     *
     * Only users with `manage_options` see the control, mirroring the
     * capability check enforced in the AJAX handler.
     */
    private function render_cache_clear_button() {
        if ( ! class_exists( 'Brikpanel_Cache_Clear' ) ) {
            return;
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $active = Brikpanel_Cache_Clear::get_active_caches();
        if ( empty( $active ) ) {
            return;
        }

        $broom_svg = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19.36 2.72l1.42 1.42-5.72 5.71-1.42-1.42z"/><path d="M14.27 8.83l-2.12 2.12-1.42-1.41 2.13-2.13"/><path d="M5.5 12.5l4 4"/><path d="M3 21l3.5-3.5a3 3 0 0 1 4.24 0l1.76 1.76a3 3 0 0 1 0 4.24"/><path d="M8 22l1.5-1.5"/><path d="M11.5 18.5L13 17"/></svg>';

        // Single plugin — render a flat icon button (no dropdown).
        if ( count( $active ) === 1 ) {
            $id    = key( $active );
            $label = current( $active );
            /* translators: %s: cache plugin name (e.g. "WP Rocket") */
            $title = sprintf( __( 'Purge %s', 'brikpanel' ), $label );
            ?>
            <button type="button"
                    class="brikpanel-topbar-icon-btn brikpanel-topbar-cache-btn"
                    data-cache-id="<?php echo esc_attr( $id ); ?>"
                    title="<?php echo esc_attr( $title ); ?>"
                    aria-label="<?php echo esc_attr( $title ); ?>">
                <?php echo $broom_svg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG markup ?>
                <span class="brikpanel-topbar-cache-spinner" aria-hidden="true"></span>
            </button>
            <?php
            return;
        }

        // Multiple plugins — icon button opens a dropdown listing each.
        ?>
        <div class="brikpanel-topbar-menu" data-topbar-menu="cache">
            <button type="button"
                    class="brikpanel-topbar-icon-btn brikpanel-topbar-cache-btn"
                    data-topbar-toggle="cache"
                    aria-haspopup="menu"
                    aria-expanded="false"
                    title="<?php esc_attr_e( 'Clear cache', 'brikpanel' ); ?>"
                    aria-label="<?php esc_attr_e( 'Clear cache', 'brikpanel' ); ?>">
                <?php echo $broom_svg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG markup ?>
                <span class="brikpanel-topbar-cache-spinner" aria-hidden="true"></span>
            </button>
            <div class="brikpanel-topbar-dropdown brikpanel-topbar-dropdown-cache" role="menu">
                <div class="brikpanel-topbar-dropdown-header">
                    <?php esc_html_e( 'Clear cache', 'brikpanel' ); ?>
                </div>
                <button type="button"
                        class="brikpanel-topbar-dropdown-item brikpanel-topbar-cache-item"
                        role="menuitem"
                        data-cache-id="all">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
                    <span><?php esc_html_e( 'Clear all caches', 'brikpanel' ); ?></span>
                </button>
                <div class="brikpanel-topbar-dropdown-sep" aria-hidden="true"></div>
                <?php foreach ( $active as $id => $label ) : ?>
                    <button type="button"
                            class="brikpanel-topbar-dropdown-item brikpanel-topbar-cache-item"
                            role="menuitem"
                            data-cache-id="<?php echo esc_attr( $id ); ?>">
                        <?php echo $broom_svg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG markup ?>
                        <span><?php echo esc_html( $label ); ?></span>
                    </button>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
    }

    // =========================================================================
    // AJAX: LIVE STATS (revenue / orders / conv / live visitors / notifications)
    // =========================================================================

    public function ajax_stats() {
        if ( ! check_ajax_referer( self::NONCE_ACTION, 'security', false ) ) {
            wp_send_json_error( [ 'message' => 'Invalid nonce.' ] );
        }
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => 'Unauthorized.' ] );
        }

        $start_local = wp_date( 'Y-m-d 00:00:00' );
        $end_local   = wp_date( 'Y-m-d 23:59:59' );
        $start_gmt   = get_gmt_from_date( $start_local );
        $end_gmt     = get_gmt_from_date( $end_local );
        $start_date  = substr( $start_local, 0, 10 );
        $end_date    = substr( $end_local, 0, 10 );

        $revenue    = function_exists( 'brikpanel_get_total_revenue' ) ? (float) brikpanel_get_total_revenue( $start_gmt, $end_gmt ) : 0;
        $orders     = function_exists( 'brikpanel_get_order_count' ) ? (int) brikpanel_get_order_count( $start_gmt, $end_gmt ) : 0;
        $visitors   = function_exists( 'brikpanel_get_visitor_count' ) ? (int) brikpanel_get_visitor_count( $start_date, $end_date ) : 0;
        $conversion = $visitors > 0 ? round( ( $orders / $visitors ) * 100, 1 ) : 0;
        $aov        = $orders > 0 ? round( $revenue / $orders, 2 ) : 0;

        $counts = [
            'processing' => 0,
            'pending'    => 0,
            'onhold'     => 0,
        ];
        if ( function_exists( 'wc_orders_count' ) ) {
            $counts['processing'] = (int) wc_orders_count( 'processing' );
            $counts['pending']    = (int) wc_orders_count( 'pending' );
            $counts['onhold']     = (int) wc_orders_count( 'on-hold' );
        }

        // Out-of-stock products.
        $oos_query = new WP_Query( [
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'meta_query'     => [
                [ 'key' => '_stock_status', 'value' => 'outofstock' ],
            ],
            'no_found_rows'  => false,
        ] );
        $oos = (int) $oos_query->found_posts;
        wp_reset_postdata();

        // New customers registered today.
        $customer_args = [
            'role__in'    => [ 'customer', 'subscriber' ],
            'date_query'  => [ [ 'after' => $start_local, 'before' => $end_local, 'inclusive' => true ] ],
            'count_total' => true,
            'number'      => 1,
            'fields'      => 'ID',
        ];
        $cu_query      = new WP_User_Query( $customer_args );
        $new_customers = (int) $cu_query->get_total();

        // Live visitors (shared transient with the dashboard live panel).
        $live = 0;
        $visitors_data = get_transient( 'brikpanel_live_visitors' );
        if ( is_array( $visitors_data ) ) {
            if ( ! defined( 'BRIKPANEL_VISITOR_TIMEOUT' ) ) {
                define( 'BRIKPANEL_VISITOR_TIMEOUT', 75 );
            }
            $cutoff = time() - BRIKPANEL_VISITOR_TIMEOUT;
            foreach ( $visitors_data as $v ) {
                if ( isset( $v['last_active'] ) && $v['last_active'] >= $cutoff ) {
                    $live++;
                }
            }
        }

        $notif_total = $counts['processing'] + $counts['pending'] + $counts['onhold'];

        wp_send_json_success( [
            'revenue'     => wc_price( $revenue ),
            'revenue_raw' => $revenue,
            'orders'      => $orders,
            'aov'         => wc_price( $aov ),
            'aov_raw'     => $aov,
            'visitors'    => $visitors,
            'conversion'  => $conversion,
            'live'        => $live,
            'notifications' => [
                'processing' => $counts['processing'],
                'pending'    => $counts['pending'],
                'onhold'     => $counts['onhold'],
                'oos'        => $oos,
                'customers'  => $new_customers,
                'total'      => $notif_total,
            ],
        ] );
    }
}

Brikpanel_Dashboard_Topbar::instance();
