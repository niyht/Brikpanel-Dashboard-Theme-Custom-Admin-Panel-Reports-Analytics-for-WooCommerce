<?php
/**
 * BrikPanel — Welcome / Feature Tour Popup
 *
 * A guided, two-pane onboarding modal: a navigable section rail on the left
 * and rich feature content on the right. Shown once per user, per version,
 * and dismissed via AJAX.
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

    /* ── Icon helpers (trusted static SVG) ───────────────────────────────────── */
    $icon_check      = '<svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M5 10l3 3 7-7"/></svg>';
    $icon_close      = '<svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M5 5l10 10M15 5L5 15"/></svg>';
    $icon_arrow_left = '<svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2"><path d="M13 16l-6-6 6-6"/></svg>';
    $icon_arrow_right = '<svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2"><path d="M7 4l6 6-6 6"/></svg>';

    $icon_logo      = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/></svg>';
    $icon_sparkles  = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l1.6 4.4L18 9l-4.4 1.6L12 15l-1.6-4.4L6 9l4.4-1.6L12 3z"/><path d="M19 14l.8 2.2L22 17l-2.2.8L19 20l-.8-2.2L16 17l2.2-.8L19 14z"/></svg>';
    $icon_dashboard = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="4" rx="1"/><rect x="14" y="11" width="7" height="10" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/></svg>';
    $icon_products  = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z"/><path d="M3.27 6.96L12 12.01l8.73-5.05M12 22.08V12"/></svg>';
    $icon_orders    = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/><path d="M9 14l2 2 4-4"/></svg>';
    $icon_customers = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87"/><path d="M16 3.13a4 4 0 010 7.75"/></svg>';
    $icon_integrations = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><path d="M8.59 13.51l6.83 3.98M15.41 6.51l-6.82 3.98"/></svg>';
    $icon_operations = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="3" width="15" height="13" rx="1"/><path d="M16 8h4l3 3v5h-7V8z"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>';
    $icon_customize = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="4" y1="21" x2="4" y2="14"/><line x1="4" y1="10" x2="4" y2="3"/><line x1="12" y1="21" x2="12" y2="12"/><line x1="12" y1="8" x2="12" y2="3"/><line x1="20" y1="21" x2="20" y2="16"/><line x1="20" y1="12" x2="20" y2="3"/><line x1="1" y1="14" x2="7" y2="14"/><line x1="9" y1="8" x2="15" y2="8"/><line x1="17" y1="16" x2="23" y2="16"/></svg>';
    $icon_rocket    = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4.5 16.5c-1.5 1.26-2 5-2 5s3.74-.5 5-2c.71-.84.7-2.13-.09-2.91a2.18 2.18 0 00-2.91-.09z"/><path d="M12 15l-3-3a22 22 0 012-3.95A12.88 12.88 0 0122 2c0 2.72-.78 7.5-6 11a22.35 22.35 0 01-4 2z"/><path d="M9 12H4s.55-3.03 2-4c1.62-1.08 5 0 5 0"/><path d="M12 15v5s3.03-.55 4-2c1.08-1.62 0-5 0-5"/></svg>';

    $icon_kbd = function ( $keys ) {
        $out = '<span class="brikpanel-welcome-kbd">';
        foreach ( $keys as $k ) {
            $out .= '<kbd>' . esc_html( $k ) . '</kbd>';
        }
        return $out . '</span>';
    };

    /* ── Tour data: rail + content slides ─────────────────────────────────────── */
    $sections = [
        // 0 — Welcome (hero, rendered separately)
        [
            'rail_icon'  => $icon_sparkles,
            'rail_title' => __( 'Welcome', 'brikpanel' ),
            'hero'       => true,
        ],
        // 1 — Dashboard & insights
        [
            'rail_icon'  => $icon_dashboard,
            'rail_title' => __( 'Dashboard & insights', 'brikpanel' ),
            'icon'       => $icon_dashboard,
            'title'      => __( 'Dashboard & insights', 'brikpanel' ),
            'sub'        => __( 'A real-time, Shopify-style overview of everything happening in your store.', 'brikpanel' ),
            'highlights' => [
                __( 'Sales, orders, and conversion rate at a glance', 'brikpanel' ),
                __( 'Net profit after cost of goods, ads, and expenses', 'brikpanel' ),
                __( 'Interactive sales chart with flexible date ranges', 'brikpanel' ),
                __( 'Live visitor counter updated in real time', 'brikpanel' ),
                __( 'Your top products and best customers', 'brikpanel' ),
                __( 'An interactive globe of where customers are', 'brikpanel' ),
            ],
        ],
        // 2 — Products
        [
            'rail_icon'  => $icon_products,
            'rail_title' => __( 'Products', 'brikpanel' ),
            'icon'       => $icon_products,
            'title'      => __( 'Products, your way', 'brikpanel' ),
            'sub'        => __( 'Create and edit products in a clean, fast, distraction-free editor.', 'brikpanel' ),
            'highlights' => [
                __( 'A simplified editor built for speed, not clutter', 'brikpanel' ),
                __( 'Modern list with inline price and stock editing', 'brikpanel' ),
                __( 'Drag & drop image gallery with reordering', 'brikpanel' ),
                __( 'Variation wizard with ready-made templates', 'brikpanel' ),
                __( 'Quick-edit side drawer for one-off changes', 'brikpanel' ),
                __( 'Duplicate, bulk-edit, and export in a click', 'brikpanel' ),
            ],
            'tags'       => [
                [ 'text' => __( 'Works with simple & variable products', 'brikpanel' ), 'pos' => true ],
            ],
        ],
        // 3 — Orders & coupons
        [
            'rail_icon'  => $icon_orders,
            'rail_title' => __( 'Orders & coupons', 'brikpanel' ),
            'icon'       => $icon_orders,
            'title'      => __( 'Orders & coupons', 'brikpanel' ),
            'sub'        => __( 'Process orders and run promotions without ever leaving the page.', 'brikpanel' ),
            'highlights' => [
                __( 'Change order status inline, right from the list', 'brikpanel' ),
                __( 'Modern order edit page with a sticky action bar', 'brikpanel' ),
                __( 'One-click copy of the customer address', 'brikpanel' ),
                __( 'Sound, popup, and confetti on every new order', 'brikpanel' ),
                __( 'Create and edit coupons in a fast side drawer', 'brikpanel' ),
                __( 'Percentage, fixed cart, and fixed product discounts', 'brikpanel' ),
            ],
        ],
        // 4 — Customers
        [
            'rail_icon'  => $icon_customers,
            'rail_title' => __( 'Customers', 'brikpanel' ),
            'icon'       => $icon_customers,
            'title'      => __( 'Know your customers', 'brikpanel' ),
            'sub'        => __( 'Understand who your best customers are and how they behave over time.', 'brikpanel' ),
            'highlights' => [
                __( 'Lifetime value (LTV) for every customer', 'brikpanel' ),
                __( 'RFM segments: VIP, at-risk, and dormant', 'brikpanel' ),
                __( 'Cohort retention shows who keeps coming back', 'brikpanel' ),
                __( 'Build custom segments with powerful filters', 'brikpanel' ),
                __( 'Export any view to CSV in one click', 'brikpanel' ),
            ],
        ],
        // 5 — Integrations (NEW)
        [
            'rail_icon'  => $icon_integrations,
            'rail_title' => __( 'Integrations', 'brikpanel' ),
            'rail_badge' => __( 'New', 'brikpanel' ),
            'icon'       => $icon_integrations,
            'title'      => __( 'Connect & grow', 'brikpanel' ),
            'sub'        => __( 'Plug BrikPanel into the tools you already use to grow your store.', 'brikpanel' ),
            'highlights' => [
                __( 'Sync orders, products, and customers to Google Sheets', 'brikpanel' ),
                __( 'Real-time and scheduled exports, fully automated', 'brikpanel' ),
                __( 'Pull ad spend from Google Ads and Meta', 'brikpanel' ),
                __( 'See true ROAS and net profit on your dashboard', 'brikpanel' ),
                __( 'Secure connection you can disconnect anytime', 'brikpanel' ),
            ],
            'tags'       => [
                [ 'text' => __( 'Google Sheets', 'brikpanel' ) ],
                [ 'text' => __( 'Google Ads', 'brikpanel' ) ],
                [ 'text' => __( 'Meta Ads', 'brikpanel' ) ],
            ],
        ],
        // 6 — Operations
        [
            'rail_icon'  => $icon_operations,
            'rail_title' => __( 'Operations', 'brikpanel' ),
            'icon'       => $icon_operations,
            'title'      => __( 'Stock & operations', 'brikpanel' ),
            'sub'        => __( 'Keep stock, suppliers, and costs under control from one place.', 'brikpanel' ),
            'highlights' => [
                __( 'Manage suppliers with full contact details', 'brikpanel' ),
                __( 'Create purchase orders and track received stock', 'brikpanel' ),
                __( 'Assign a supplier per product and per variation', 'brikpanel' ),
                __( 'Log operating expenses for accurate profit', 'brikpanel' ),
                __( 'BrikControl health checks keep your store in shape', 'brikpanel' ),
            ],
            'tags'       => [
                [ 'text' => __( 'Suppliers work per variation', 'brikpanel' ), 'pos' => true ],
            ],
        ],
        // 7 — Make it yours
        [
            'rail_icon'  => $icon_customize,
            'rail_title' => __( 'Make it yours', 'brikpanel' ),
            'icon'       => $icon_customize,
            'title'      => __( 'Make it yours', 'brikpanel' ),
            'sub'        => __( 'Search instantly, restyle the admin, and make BrikPanel feel like yours.', 'brikpanel' ),
            'highlights' => [
                [ 'text' => __( 'Global search from anywhere', 'brikpanel' ), 'kbd' => [ 'Ctrl', 'K' ] ],
                __( 'Find orders, products, and customers instantly', 'brikpanel' ),
                __( 'Custom fonts, an accent color, and your own logo', 'brikpanel' ),
                __( 'A modern, on-brand login page for your team', 'brikpanel' ),
                __( 'Reorder the sidebar and import or export settings', 'brikpanel' ),
            ],
        ],
        // 8 — All set (final, rendered separately)
        [
            'rail_icon'  => $icon_rocket,
            'rail_title' => __( 'You are all set', 'brikpanel' ),
            'final'      => true,
        ],
    ];

    $total_slides = count( $sections );
    ?>
    <div id="brikpanel-welcome-overlay" class="brikpanel-welcome-overlay" style="display:none">
        <div class="brikpanel-welcome-modal" role="dialog" aria-modal="true" aria-label="<?php esc_attr_e( 'Welcome to BrikPanel', 'brikpanel' ); ?>">

            <!-- Progress bar -->
            <div class="brikpanel-welcome-progress"><span class="brikpanel-welcome-progress-fill"></span></div>

            <button type="button" class="brikpanel-welcome-close" aria-label="<?php esc_attr_e( 'Close', 'brikpanel' ); ?>">
                <?php echo $icon_close; ?>
            </button>

            <div class="brikpanel-welcome-body">

                <!-- ── Left rail ──────────────────────────────────────────────── -->
                <nav class="brikpanel-welcome-rail" aria-label="<?php esc_attr_e( 'Tour sections', 'brikpanel' ); ?>">
                    <div class="brikpanel-welcome-railhead">
                        <span class="brikpanel-welcome-railhead-logo"><?php echo $icon_logo; ?></span>
                        <span class="brikpanel-welcome-railhead-name">BrikPanel</span>
                        <span class="brikpanel-welcome-railhead-ver">v<?php echo esc_html( BRIKPANEL_VERSION ); ?></span>
                    </div>

                    <?php foreach ( $sections as $i => $sec ) : ?>
                        <button type="button"
                                class="brikpanel-welcome-railitem<?php echo 0 === $i ? ' is-active' : ''; ?>"
                                data-bw-goto="<?php echo (int) $i; ?>">
                            <span class="brikpanel-welcome-rail-ico"><?php echo $sec['rail_icon']; ?></span>
                            <span class="brikpanel-welcome-rail-title"><?php echo esc_html( $sec['rail_title'] ); ?></span>
                            <?php if ( ! empty( $sec['rail_badge'] ) ) : ?>
                                <span class="brikpanel-welcome-rail-badge"><?php echo esc_html( $sec['rail_badge'] ); ?></span>
                            <?php endif; ?>
                        </button>
                    <?php endforeach; ?>
                </nav>

                <!-- ── Slides ─────────────────────────────────────────────────── -->
                <div class="brikpanel-welcome-slides">
                    <?php foreach ( $sections as $i => $sec ) : ?>

                        <?php if ( ! empty( $sec['hero'] ) ) : ?>
                            <!-- Hero / Welcome -->
                            <div class="brikpanel-welcome-slide brikpanel-welcome-hero<?php echo 0 === $i ? ' is-active' : ''; ?>" data-slide="<?php echo (int) $i; ?>">
                                <div class="brikpanel-welcome-logo"><?php echo $icon_logo; ?></div>
                                <h2><?php esc_html_e( 'Welcome to BrikPanel', 'brikpanel' ); ?></h2>
                                <p><?php esc_html_e( 'A modern, Shopify-inspired admin experience for WooCommerce. Run your whole store from one clean, fast place.', 'brikpanel' ); ?></p>
                                <p class="brikpanel-welcome-hero-hint"><?php esc_html_e( 'Use the menu on the left to jump around, or click Next to take the quick tour.', 'brikpanel' ); ?></p>
                            </div>

                        <?php elseif ( ! empty( $sec['final'] ) ) : ?>
                            <!-- Final / All set -->
                            <div class="brikpanel-welcome-slide brikpanel-welcome-final" data-slide="<?php echo (int) $i; ?>">
                                <div class="brikpanel-welcome-final-ico"><?php echo $icon_rocket; ?></div>
                                <h2><?php esc_html_e( 'You are all set', 'brikpanel' ); ?></h2>
                                <p><?php esc_html_e( 'That is the tour. Everything is on by default, so you can dive straight in. Here are a couple of great places to start.', 'brikpanel' ); ?></p>
                                <div class="brikpanel-welcome-cta">
                                    <a class="brikpanel-welcome-btn brikpanel-welcome-btn--primary" data-bw-cta href="<?php echo esc_url( admin_url( 'admin.php?page=brikpanel-dashboard' ) ); ?>">
                                        <?php esc_html_e( 'Open your dashboard', 'brikpanel' ); ?>
                                        <?php echo $icon_arrow_right; ?>
                                    </a>
                                    <a class="brikpanel-welcome-btn brikpanel-welcome-btn--secondary" data-bw-cta href="<?php echo esc_url( admin_url( 'admin.php?page=brikpanel-google-sheets' ) ); ?>">
                                        <?php echo $icon_integrations; ?>
                                        <?php esc_html_e( 'Connect Google Sheets', 'brikpanel' ); ?>
                                    </a>
                                </div>
                                <a class="brikpanel-welcome-final-link" data-bw-cta href="<?php echo esc_url( admin_url( 'admin.php?page=wc-settings&tab=brikpanel' ) ); ?>">
                                    <?php esc_html_e( 'Browse all settings', 'brikpanel' ); ?>
                                </a>

                                <!-- Confetti -->
                                <div class="brikpanel-welcome-confetti">
                                    <?php
                                    $colors = [ '#303030', '#616161', '#8a8a8a', '#1a8917', '#e3e3e3', '#d72c0d' ];
                                    for ( $c = 0; $c < 22; $c++ ) {
                                        printf(
                                            '<span style="left:%d%%;animation-delay:%.2fs;background:%s"></span>',
                                            rand( 4, 96 ),
                                            ( $c * 0.05 ),
                                            $colors[ $c % count( $colors ) ]
                                        );
                                    }
                                    ?>
                                </div>
                            </div>

                        <?php else : ?>
                            <!-- Feature slide -->
                            <div class="brikpanel-welcome-slide" data-slide="<?php echo (int) $i; ?>">
                                <div class="brikpanel-welcome-head">
                                    <span class="brikpanel-welcome-head-ico"><?php echo $sec['icon']; ?></span>
                                    <div class="brikpanel-welcome-head-txt">
                                        <h3><?php echo esc_html( $sec['title'] ); ?></h3>
                                        <p class="brikpanel-welcome-sub"><?php echo esc_html( $sec['sub'] ); ?></p>
                                    </div>
                                </div>

                                <ul class="brikpanel-welcome-highlights">
                                    <?php foreach ( $sec['highlights'] as $hl ) : ?>
                                        <li>
                                            <span class="bw-check"><?php echo $icon_check; ?></span>
                                            <span class="bw-hl-text">
                                                <?php
                                                if ( is_array( $hl ) ) {
                                                    echo esc_html( $hl['text'] );
                                                    if ( ! empty( $hl['kbd'] ) ) {
                                                        echo ' ' . $icon_kbd( $hl['kbd'] ); // already escaped
                                                    }
                                                } else {
                                                    echo esc_html( $hl );
                                                }
                                                ?>
                                            </span>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>

                                <?php if ( ! empty( $sec['tags'] ) ) : ?>
                                    <div class="brikpanel-welcome-tags">
                                        <?php foreach ( $sec['tags'] as $tag ) : ?>
                                            <span class="brikpanel-welcome-tag<?php echo ! empty( $tag['pos'] ) ? ' is-pos' : ''; ?>">
                                                <?php if ( ! empty( $tag['pos'] ) ) : ?>
                                                    <span class="bw-tag-check"><?php echo $icon_check; ?></span>
                                                <?php endif; ?>
                                                <?php echo esc_html( $tag['text'] ); ?>
                                            </span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                    <?php endforeach; ?>
                </div><!-- /.brikpanel-welcome-slides -->

            </div><!-- /.brikpanel-welcome-body -->

            <!-- ── Footer ───────────────────────────────────────────────────── -->
            <div class="brikpanel-welcome-footer">
                <button type="button" class="brikpanel-welcome-skip">
                    <?php esc_html_e( 'Skip tour', 'brikpanel' ); ?>
                </button>
                <div class="brikpanel-welcome-nav">
                    <button type="button" class="brikpanel-welcome-btn brikpanel-welcome-btn--secondary" data-bw-prev style="visibility:hidden">
                        <?php echo $icon_arrow_left; ?>
                        <?php esc_html_e( 'Previous', 'brikpanel' ); ?>
                    </button>
                    <button type="button" class="brikpanel-welcome-btn brikpanel-welcome-btn--primary" data-bw-next>
                        <?php esc_html_e( 'Next', 'brikpanel' ); ?>
                        <?php echo $icon_arrow_right; ?>
                    </button>
                </div>
            </div>

        </div><!-- /.brikpanel-welcome-modal -->
    </div><!-- /#brikpanel-welcome-overlay -->
    <?php
} );
