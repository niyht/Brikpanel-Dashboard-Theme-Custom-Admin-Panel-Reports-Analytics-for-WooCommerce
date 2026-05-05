<?php
/**
 * BrikPanel — Feature Showcase Popup
 *
 * Shows a multi-slide welcome popup highlighting key features.
 * Displayed once per user; dismissed via AJAX.
 *
 * @package BrikPanel
 * @since   2.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* ── Dismiss AJAX ────────────────────────────────────────────────────────────── */
add_action( 'wp_ajax_brikpanel_dismiss_welcome', function () {
    check_ajax_referer( 'brikpanel_welcome_nonce' );
    update_user_meta( get_current_user_id(), '_brikpanel_welcome_dismissed', BRIKPANEL_VERSION );
    wp_send_json_success();
} );

/* ── Reset (for testing / new versions) ──────────────────────────────────────── */
add_action( 'wp_ajax_brikpanel_reset_welcome', function () {
    check_ajax_referer( 'brikpanel_welcome_nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error();
    }
    delete_user_meta( get_current_user_id(), '_brikpanel_welcome_dismissed' );
    wp_send_json_success();
} );

/* ── Should we show the popup? ───────────────────────────────────────────────── */
function brikpanel_should_show_welcome() {
    if ( ! is_admin() || wp_doing_ajax() ) {
        return false;
    }
    $dismissed = get_user_meta( get_current_user_id(), '_brikpanel_welcome_dismissed', true );
    return empty( $dismissed );
}

/* ── Enqueue assets ──────────────────────────────────────────────────────────── */
add_action( 'admin_enqueue_scripts', function () {
    if ( ! brikpanel_should_show_welcome() ) {
        return;
    }

    wp_enqueue_style(
        'brikpanel_welcome_styles',
        BRIKPANEL_URL . 'front-end/welcome/brikpanel-welcome.css',
        [],
        BRIKPANEL_VERSION
    );

    wp_enqueue_script(
        'brikpanel_welcome_scripts',
        BRIKPANEL_URL . 'front-end/welcome/brikpanel-welcome.js',
        [],
        BRIKPANEL_VERSION,
        true
    );

    wp_localize_script( 'brikpanel_welcome_scripts', 'brikpanelWelcome', [
        'ajax_url' => admin_url( 'admin-ajax.php' ),
        'nonce'    => wp_create_nonce( 'brikpanel_welcome_nonce' ),
        'i18n'     => [
            'next'        => __( 'Next', 'brikpanel' ),
            'previous'    => __( 'Previous', 'brikpanel' ),
            'get_started' => __( 'Get Started', 'brikpanel' ),
            'skip'        => __( 'Skip tour', 'brikpanel' ),
        ],
    ] );
} );

/* ── Render HTML ─────────────────────────────────────────────────────────────── */
add_action( 'admin_footer', function () {
    if ( ! brikpanel_should_show_welcome() ) {
        return;
    }

    /* SVG icon helpers */
    $icon_check = '<svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M5 10l3 3 7-7"/></svg>';

    $icon_close = '<svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M5 5l10 10M15 5L5 15"/></svg>';

    $icon_arrow_left = '<svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2"><path d="M13 16l-6-6 6-6"/></svg>';

    /* Feature icons */
    $icon_dashboard = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="4" rx="1"/><rect x="14" y="11" width="7" height="10" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/></svg>';

    $icon_orders = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/><path d="M9 14l2 2 4-4"/></svg>';

    $icon_products = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z"/><path d="M3.27 6.96L12 12.01l8.73-5.05M12 22.08V12"/></svg>';

    $icon_coupons = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 12V8H6a2 2 0 01-2-2c0-1.1.9-2 2-2h12v4"/><path d="M4 6v12c0 1.1.9 2 2 2h14v-4"/><path d="M18 12a2 2 0 000 4h4v-4h-4z"/></svg>';

    $icon_search = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg>';

    $icon_live = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>';

    $icon_logo = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/></svg>';

    $total_slides = 7; // intro + 6 features
    ?>
    <div id="brikpanel-welcome-overlay" class="brikpanel-welcome-overlay" style="display:none">
        <div class="brikpanel-welcome-modal">
            <button type="button" class="brikpanel-welcome-close" aria-label="<?php esc_attr_e( 'Close', 'brikpanel' ); ?>">
                <?php echo $icon_close; ?>
            </button>

            <div class="brikpanel-welcome-slides">

                <!-- ============================================================ -->
                <!-- SLIDE 0 — Intro                                              -->
                <!-- ============================================================ -->
                <div class="brikpanel-welcome-slide brikpanel-welcome-intro" data-slide="0">
                    <div class="brikpanel-welcome-logo">
                        <?php echo $icon_logo; ?>
                    </div>
                    <h2><?php esc_html_e( 'Welcome to BrikPanel', 'brikpanel' ); ?></h2>
                    <p><?php esc_html_e( 'A modern, Shopify-inspired admin experience for WooCommerce. Here is what you get:', 'brikpanel' ); ?></p>

                    <div class="brikpanel-welcome-features-grid">
                        <div class="brikpanel-welcome-feature-mini" data-bw-goto="1">
                            <span class="bw-icon"><?php echo $icon_dashboard; ?></span>
                            <span>
                                <span class="bw-label"><?php esc_html_e( 'Analytics Dashboard', 'brikpanel' ); ?></span><br>
                                <span class="bw-sublabel"><?php esc_html_e( 'Real-time insights', 'brikpanel' ); ?></span>
                            </span>
                        </div>
                        <div class="brikpanel-welcome-feature-mini" data-bw-goto="2">
                            <span class="bw-icon"><?php echo $icon_orders; ?></span>
                            <span>
                                <span class="bw-label"><?php esc_html_e( 'Order Management', 'brikpanel' ); ?></span><br>
                                <span class="bw-sublabel"><?php esc_html_e( 'Streamlined workflow', 'brikpanel' ); ?></span>
                            </span>
                        </div>
                        <div class="brikpanel-welcome-feature-mini" data-bw-goto="3">
                            <span class="bw-icon"><?php echo $icon_products; ?></span>
                            <span>
                                <span class="bw-label"><?php esc_html_e( 'Product Editor', 'brikpanel' ); ?></span><br>
                                <span class="bw-sublabel"><?php esc_html_e( 'Simplified editing', 'brikpanel' ); ?></span>
                            </span>
                        </div>
                        <div class="brikpanel-welcome-feature-mini" data-bw-goto="4">
                            <span class="bw-icon"><?php echo $icon_coupons; ?></span>
                            <span>
                                <span class="bw-label"><?php esc_html_e( 'Coupon Manager', 'brikpanel' ); ?></span><br>
                                <span class="bw-sublabel"><?php esc_html_e( 'Quick drawer editor', 'brikpanel' ); ?></span>
                            </span>
                        </div>
                        <div class="brikpanel-welcome-feature-mini" data-bw-goto="5">
                            <span class="bw-icon"><?php echo $icon_search; ?></span>
                            <span>
                                <span class="bw-label"><?php esc_html_e( 'Global Search', 'brikpanel' ); ?></span><br>
                                <span class="bw-sublabel"><?php esc_html_e( 'Find anything fast', 'brikpanel' ); ?></span>
                            </span>
                        </div>
                        <div class="brikpanel-welcome-feature-mini" data-bw-goto="6">
                            <span class="bw-icon"><?php echo $icon_live; ?></span>
                            <span>
                                <span class="bw-label"><?php esc_html_e( 'Live Tracking', 'brikpanel' ); ?></span><br>
                                <span class="bw-sublabel"><?php esc_html_e( 'Visitor monitoring', 'brikpanel' ); ?></span>
                            </span>
                        </div>
                    </div>
                </div>

                <!-- ============================================================ -->
                <!-- SLIDE 1 — Analytics Dashboard                                -->
                <!-- ============================================================ -->
                <div class="brikpanel-welcome-slide" data-slide="1">
                    <div class="brikpanel-welcome-feature-detail">
                        <div style="position:relative;display:flex;align-items:center;justify-content:center">
                            <div class="brikpanel-welcome-feature-icon"><?php echo $icon_dashboard; ?></div>
                            <div class="brikpanel-welcome-pulse-ring"></div>
                        </div>
                        <h3><?php esc_html_e( 'Analytics Dashboard', 'brikpanel' ); ?></h3>
                        <p><?php esc_html_e( 'A beautiful, Shopify-style overview of your store performance with interactive charts and real-time data.', 'brikpanel' ); ?></p>
                        <ul class="brikpanel-welcome-highlights">
                            <li>
                                <span class="bw-check"><?php echo $icon_check; ?></span>
                                <?php esc_html_e( 'Sales, orders, and conversion rate cards', 'brikpanel' ); ?>
                            </li>
                            <li>
                                <span class="bw-check"><?php echo $icon_check; ?></span>
                                <?php esc_html_e( 'Interactive sales chart with date range filtering', 'brikpanel' ); ?>
                            </li>
                            <li>
                                <span class="bw-check"><?php echo $icon_check; ?></span>
                                <?php esc_html_e( 'Top products and customers overview', 'brikpanel' ); ?>
                            </li>
                            <li>
                                <span class="bw-check"><?php echo $icon_check; ?></span>
                                <?php esc_html_e( 'Interactive globe showing customer locations', 'brikpanel' ); ?>
                            </li>
                        </ul>
                    </div>
                </div>

                <!-- ============================================================ -->
                <!-- SLIDE 2 — Order Management                                   -->
                <!-- ============================================================ -->
                <div class="brikpanel-welcome-slide" data-slide="2">
                    <div class="brikpanel-welcome-feature-detail">
                        <div style="position:relative;display:flex;align-items:center;justify-content:center">
                            <div class="brikpanel-welcome-feature-icon"><?php echo $icon_orders; ?></div>
                            <div class="brikpanel-welcome-pulse-ring"></div>
                        </div>
                        <h3><?php esc_html_e( 'Order Management', 'brikpanel' ); ?></h3>
                        <p><?php esc_html_e( 'Handle orders faster with inline actions, a modern edit page, and quick status updates.', 'brikpanel' ); ?></p>
                        <ul class="brikpanel-welcome-highlights">
                            <li>
                                <span class="bw-check"><?php echo $icon_check; ?></span>
                                <?php esc_html_e( 'Inline status changes directly from the list', 'brikpanel' ); ?>
                            </li>
                            <li>
                                <span class="bw-check"><?php echo $icon_check; ?></span>
                                <?php esc_html_e( 'Modern order edit page with sticky header', 'brikpanel' ); ?>
                            </li>
                            <li>
                                <span class="bw-check"><?php echo $icon_check; ?></span>
                                <?php esc_html_e( 'One-click address copy to clipboard', 'brikpanel' ); ?>
                            </li>
                            <li>
                                <span class="bw-check"><?php echo $icon_check; ?></span>
                                <?php esc_html_e( 'Order overview stats for the last 30 days', 'brikpanel' ); ?>
                            </li>
                        </ul>
                    </div>
                </div>

                <!-- ============================================================ -->
                <!-- SLIDE 3 — Product Editor                                     -->
                <!-- ============================================================ -->
                <div class="brikpanel-welcome-slide" data-slide="3">
                    <div class="brikpanel-welcome-feature-detail">
                        <div style="position:relative;display:flex;align-items:center;justify-content:center">
                            <div class="brikpanel-welcome-feature-icon"><?php echo $icon_products; ?></div>
                            <div class="brikpanel-welcome-pulse-ring"></div>
                        </div>
                        <h3><?php esc_html_e( 'Simplified Product Editor', 'brikpanel' ); ?></h3>
                        <p><?php esc_html_e( 'Create and edit products with a clean, distraction-free interface designed for speed.', 'brikpanel' ); ?></p>
                        <ul class="brikpanel-welcome-highlights">
                            <li>
                                <span class="bw-check"><?php echo $icon_check; ?></span>
                                <?php esc_html_e( 'Drag & drop image gallery with reordering', 'brikpanel' ); ?>
                            </li>
                            <li>
                                <span class="bw-check"><?php echo $icon_check; ?></span>
                                <?php esc_html_e( 'Variation wizard with ready-made templates', 'brikpanel' ); ?>
                            </li>
                            <li>
                                <span class="bw-check"><?php echo $icon_check; ?></span>
                                <?php esc_html_e( 'Inline price and stock editing on product list', 'brikpanel' ); ?>
                            </li>
                            <li>
                                <span class="bw-check"><?php echo $icon_check; ?></span>
                                <?php esc_html_e( 'Auto-save and one-click product duplication', 'brikpanel' ); ?>
                            </li>
                        </ul>
                    </div>
                </div>

                <!-- ============================================================ -->
                <!-- SLIDE 4 — Coupon Manager                                     -->
                <!-- ============================================================ -->
                <div class="brikpanel-welcome-slide" data-slide="4">
                    <div class="brikpanel-welcome-feature-detail">
                        <div style="position:relative;display:flex;align-items:center;justify-content:center">
                            <div class="brikpanel-welcome-feature-icon"><?php echo $icon_coupons; ?></div>
                            <div class="brikpanel-welcome-pulse-ring"></div>
                        </div>
                        <h3><?php esc_html_e( 'Coupon Manager', 'brikpanel' ); ?></h3>
                        <p><?php esc_html_e( 'Create and manage coupons without leaving the page, using a fast side-drawer editor.', 'brikpanel' ); ?></p>
                        <ul class="brikpanel-welcome-highlights">
                            <li>
                                <span class="bw-check"><?php echo $icon_check; ?></span>
                                <?php esc_html_e( 'Side drawer for quick coupon creation and editing', 'brikpanel' ); ?>
                            </li>
                            <li>
                                <span class="bw-check"><?php echo $icon_check; ?></span>
                                <?php esc_html_e( 'Percentage, fixed cart, and fixed product discounts', 'brikpanel' ); ?>
                            </li>
                            <li>
                                <span class="bw-check"><?php echo $icon_check; ?></span>
                                <?php esc_html_e( 'Usage limits, expiry dates, and spend restrictions', 'brikpanel' ); ?>
                            </li>
                            <li>
                                <span class="bw-check"><?php echo $icon_check; ?></span>
                                <?php esc_html_e( 'Inline amount editing and status toggling', 'brikpanel' ); ?>
                            </li>
                        </ul>
                    </div>
                </div>

                <!-- ============================================================ -->
                <!-- SLIDE 5 — Global Search                                      -->
                <!-- ============================================================ -->
                <div class="brikpanel-welcome-slide" data-slide="5">
                    <div class="brikpanel-welcome-feature-detail">
                        <div style="position:relative;display:flex;align-items:center;justify-content:center">
                            <div class="brikpanel-welcome-feature-icon"><?php echo $icon_search; ?></div>
                            <div class="brikpanel-welcome-pulse-ring"></div>
                        </div>
                        <h3><?php esc_html_e( 'Global Search', 'brikpanel' ); ?></h3>
                        <p><?php esc_html_e( 'Search across orders, products, and customers from anywhere in the admin panel.', 'brikpanel' ); ?></p>
                        <ul class="brikpanel-welcome-highlights">
                            <li>
                                <span class="bw-check"><?php echo $icon_check; ?></span>
                                <?php esc_html_e( 'Open with keyboard shortcut', 'brikpanel' ); ?>
                                <span class="brikpanel-welcome-kbd"><kbd>Ctrl</kbd><kbd>K</kbd></span>
                            </li>
                            <li>
                                <span class="bw-check"><?php echo $icon_check; ?></span>
                                <?php esc_html_e( 'Search by name, email, phone, order ID, or SKU', 'brikpanel' ); ?>
                            </li>
                            <li>
                                <span class="bw-check"><?php echo $icon_check; ?></span>
                                <?php esc_html_e( 'Instant AJAX-powered results', 'brikpanel' ); ?>
                            </li>
                            <li>
                                <span class="bw-check"><?php echo $icon_check; ?></span>
                                <?php esc_html_e( 'Available on every admin page', 'brikpanel' ); ?>
                            </li>
                        </ul>
                    </div>
                </div>

                <!-- ============================================================ -->
                <!-- SLIDE 6 — Live Visitor Tracking                              -->
                <!-- ============================================================ -->
                <div class="brikpanel-welcome-slide" data-slide="6">
                    <div class="brikpanel-welcome-feature-detail">
                        <div style="position:relative;display:flex;align-items:center;justify-content:center">
                            <div class="brikpanel-welcome-feature-icon"><?php echo $icon_live; ?></div>
                            <div class="brikpanel-welcome-pulse-ring"></div>
                        </div>
                        <h3><?php esc_html_e( 'Live Visitor Tracking', 'brikpanel' ); ?></h3>
                        <p><?php esc_html_e( 'Monitor your store visitors in real time and see their activity as it happens.', 'brikpanel' ); ?></p>
                        <ul class="brikpanel-welcome-highlights">
                            <li>
                                <span class="bw-check"><?php echo $icon_check; ?></span>
                                <?php esc_html_e( 'Real-time active visitor count on dashboard', 'brikpanel' ); ?>
                            </li>
                            <li>
                                <span class="bw-check"><?php echo $icon_check; ?></span>
                                <?php esc_html_e( 'See which pages visitors are browsing', 'brikpanel' ); ?>
                            </li>
                            <li>
                                <span class="bw-check"><?php echo $icon_check; ?></span>
                                <?php esc_html_e( 'Track cart and checkout activity live', 'brikpanel' ); ?>
                            </li>
                            <li>
                                <span class="bw-check"><?php echo $icon_check; ?></span>
                                <?php esc_html_e( 'Conversion funnel visualization', 'brikpanel' ); ?>
                            </li>
                        </ul>

                        <!-- Confetti on last slide -->
                        <div class="brikpanel-welcome-confetti">
                            <?php
                            $colors = [ '#303030', '#616161', '#8a8a8a', '#1a8917', '#e3e3e3', '#d72c0d' ];
                            for ( $i = 0; $i < 20; $i++ ) {
                                $left  = rand( 5, 95 );
                                $delay = ( $i * 0.06 );
                                $color = $colors[ $i % count( $colors ) ];
                                printf(
                                    '<span style="left:%d%%;animation-delay:%.2fs;background:%s"></span>',
                                    $left,
                                    $delay,
                                    $color
                                );
                            }
                            ?>
                        </div>
                    </div>
                </div>

            </div><!-- /.brikpanel-welcome-slides -->

            <!-- ── Footer ───────────────────────────────────────────────────── -->
            <div class="brikpanel-welcome-footer">
                <div class="brikpanel-welcome-dots">
                    <?php for ( $i = 0; $i < $total_slides; $i++ ) : ?>
                        <button type="button"
                                class="brikpanel-welcome-dot<?php echo $i === 0 ? ' is-active' : ''; ?>"
                                aria-label="<?php printf( esc_attr__( 'Slide %d', 'brikpanel' ), $i + 1 ); ?>">
                        </button>
                    <?php endfor; ?>
                </div>

                <div class="brikpanel-welcome-nav">
                    <button type="button" class="brikpanel-welcome-skip">
                        <?php esc_html_e( 'Skip tour', 'brikpanel' ); ?>
                    </button>
                    <button type="button" class="brikpanel-welcome-btn brikpanel-welcome-btn--secondary" data-bw-prev style="visibility:hidden">
                        <?php echo $icon_arrow_left; ?>
                        <?php esc_html_e( 'Previous', 'brikpanel' ); ?>
                    </button>
                    <button type="button" class="brikpanel-welcome-btn brikpanel-welcome-btn--primary" data-bw-next>
                        <?php esc_html_e( 'Next', 'brikpanel' ); ?>
                        <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2"><path d="M7 4l6 6-6 6"/></svg>
                    </button>
                </div>
            </div>

        </div><!-- /.brikpanel-welcome-modal -->
    </div><!-- /#brikpanel-welcome-overlay -->
    <?php
} );
