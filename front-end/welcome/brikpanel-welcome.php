<?php
/**
 * BrikPanel: welcome tour.
 *
 * A two-pane dialog shown to each admin until they dismiss it once. The left
 * pane is the BrikPanel logo plate in 3D: every step of the tour lays one
 * brick, and the last step flattens the plate into the logo. The right pane
 * shows one feature area per step with a hand-drawn sketch, then a final panel
 * with quick links. Dismissed via AJAX.
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
    // The tour was the first ask: the next one (bar the new-store guide) waits
    // its turn instead of opening on the very next page.
    if ( function_exists( 'brikpanel_ask_closed' ) ) {
        brikpanel_ask_closed( 'welcome' );
    }
    wp_send_json_success();
} );

/* ── Reset (for testing) ─────────────────────────────────────────────────────── */
add_action( 'wp_ajax_brikpanel_reset_welcome', function () {
    check_ajax_referer( 'brikpanel_welcome_nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error();
    }
    delete_user_meta( get_current_user_id(), '_brikpanel_welcome_dismissed' );
    wp_send_json_success();
} );

/* ── Should we show the tour? ────────────────────────────────────────────────── */
/**
 * Whether the current user gets the welcome tour on this request. Shown once
 * per user: any stored dismissal, from whichever version, keeps it closed.
 * BrikMentor's launch popup asks this too, to stay out of the tour's way.
 *
 * @return bool
 */
function brikpanel_should_show_welcome() {
    if ( ! is_admin() || wp_doing_ajax() ) {
        return false;
    }

    // The welcome tour is a setup walkthrough that ends on a call to open the
    // BrikPanel settings, so it is only relevant to users who can actually
    // configure BrikPanel. Gating it on the same settings-access check keeps it
    // away from shop managers and other non-administrators (including managers a
    // role editor granted manage_options) when the settings lock is on, matching
    // the hidden settings link. Users the BrikPanel interface is disabled for see
    // the native admin and should never get the tour either.
    if ( function_exists( 'brikpanel_user_can_open_settings' ) && ! brikpanel_user_can_open_settings() ) {
        return false;
    }
    if ( function_exists( 'brikpanel_access_is_disabled_for_user' ) && brikpanel_access_is_disabled_for_user() ) {
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

    // The step row is the shared scroll strip: one line that scrolls inside
    // itself when the step names do not fit (front-end/shared/brikpanel-scroll-strip.js).
    // The shared UI parts carry the buttons.
    $style_deps  = function_exists( 'brikpanel_narrow_deps' ) ? brikpanel_narrow_deps( [ 'scroll_strip', 'ui' ], 'style' ) : [];
    $script_deps = function_exists( 'brikpanel_narrow_dep' ) ? brikpanel_narrow_dep( 'scroll_strip' ) : [];

    wp_enqueue_style(
        'brikpanel_welcome_styles',
        BRIKPANEL_URL . 'front-end/welcome/brikpanel-welcome.css',
        $style_deps,
        BRIKPANEL_VERSION
    );

    wp_enqueue_script(
        'brikpanel_welcome_scripts',
        BRIKPANEL_URL . 'front-end/welcome/brikpanel-welcome.js',
        $script_deps,
        BRIKPANEL_VERSION,
        true
    );

    wp_localize_script( 'brikpanel_welcome_scripts', 'brikpanelWelcome', [
        'ajax_url' => admin_url( 'admin-ajax.php' ),
        'nonce'    => wp_create_nonce( 'brikpanel_welcome_nonce' ),
        'i18n'     => [
            'next'   => _x( 'Next', 'welcome tour button', 'brikpanel' ),
            'finish' => _x( 'Finish', 'welcome tour button', 'brikpanel' ),
        ],
    ] );
} );

/* ── Render ──────────────────────────────────────────────────────────────────── */
add_action( 'admin_footer', function () {
    if ( ! brikpanel_should_show_welcome() ) {
        return;
    }

    require_once __DIR__ . '/brikpanel-welcome-sketches.php';

    /* ── Icons (trusted static SVG) ──────────────────────────────────────────── */
    $icon_close = '<svg viewBox="0 0 14 14" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true" focusable="false"><path d="M3 3l8 8M11 3l-8 8"/></svg>';
    $icon_arrow = '<svg class="brikpanel-welcome-arrow" viewBox="0 0 16 16" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M3 8h10M9 4l4 4-4 4"/></svg>';
    $icon_chev  = '<svg class="brikpanel-welcome-arrow" viewBox="0 0 16 16" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M6 3l5 5-5 5"/></svg>';
    $icon_check = '<svg viewBox="0 0 20 20" width="12" height="12" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M5 10l3 3 7-7"/></svg>';
    $link_icons = [
        'dashboard' => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="4" rx="1"/><rect x="14" y="11" width="7" height="10" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/></svg>',
        'orders'    => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/><path d="M9 14l2 2 4-4"/></svg>',
        'customers' => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87"/><path d="M16 3.13a4 4 0 010 7.75"/></svg>',
        'sheets'    => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" aria-hidden="true" focusable="false"><rect x="4" y="3" width="16" height="18" rx="2"/><path d="M4 9h16M4 15h16M10 9v12"/></svg>',
    ];

    /* ── The four steps ──────────────────────────────────────────────────────── */
    $steps = [
        [
            'key'   => 'profit',
            'tab'   => _x( 'Profit', 'welcome tour step name', 'brikpanel' ),
            'area'  => __( 'Dashboard', 'brikpanel' ),
            'title' => __( 'Know what you really earn', 'brikpanel' ),
            'text'  => __( 'Your dashboard takes cost of goods, ad spend and expenses off your sales, so net profit is always one glance away.', 'brikpanel' ),
            'chips' => [ __( 'Live visitors', 'brikpanel' ), __( 'Any date range', 'brikpanel' ), __( 'Top products', 'brikpanel' ) ],
        ],
        [
            'key'   => 'orders',
            'tab'   => _x( 'Orders', 'welcome tour step name', 'brikpanel' ),
            'area'  => __( 'Orders and products', 'brikpanel' ),
            'title' => __( 'Handle orders in seconds', 'brikpanel' ),
            'text'  => __( 'Hear every new order, change its status right in the list, and edit price and stock without opening a single extra page.', 'brikpanel' ),
            'chips' => [ __( 'Order alerts', 'brikpanel' ), __( 'Quick edit', 'brikpanel' ), __( 'Ctrl K search', 'brikpanel' ) ],
        ],
        [
            'key'   => 'customers',
            'tab'   => _x( 'Customers', 'welcome tour step name', 'brikpanel' ),
            'area'  => __( 'Customers', 'brikpanel' ),
            'title' => __( 'Meet your best customers', 'brikpanel' ),
            'text'  => __( 'See lifetime value for every customer, spot your VIPs and notice the ones who might not come back.', 'brikpanel' ),
            'chips' => [ __( 'VIP and at risk groups', 'brikpanel' ), __( 'Cohorts', 'brikpanel' ), __( 'CSV export', 'brikpanel' ) ],
        ],
        [
            'key'   => 'connect',
            'tab'   => _x( 'Connect', 'welcome tour step name', 'brikpanel' ),
            'area'  => __( 'Integrations', 'brikpanel' ),
            'title' => __( 'Connect the tools you use', 'brikpanel' ),
            'text'  => __( 'Sync orders and customers to Google Sheets, and bring in Google Ads and Meta spend to see your true ROAS.', 'brikpanel' ),
            'chips' => [ __( 'Google Sheets', 'brikpanel' ), __( 'Google Ads', 'brikpanel' ), __( 'Meta Ads', 'brikpanel' ) ],
        ],
    ];
    $count = count( $steps );
    $num   = function ( $n ) {
        return function_exists( 'brikpanel_number' ) ? brikpanel_number( $n ) : (string) (int) $n;
    };
    foreach ( $steps as $i => $step ) {
        /* translators: 1: step number, 2: number of steps, 3: area of the admin, for example "1 of 4 · Dashboard" */
        $steps[ $i ]['kicker'] = sprintf( __( '%1$s of %2$s · %3$s', 'brikpanel' ), $num( $i + 1 ), $num( $count ), $step['area'] );
    }

    /* ── Quick links on the final panel ──────────────────────────────────────── */
    // A switched-off module gets no link (CLAUDE.md, closed modules).
    $dashboard_url = function_exists( 'brikpanel_module_url' )
        ? brikpanel_module_url( 'brikpanel-dashboard', [], '', false )
        : admin_url( 'admin.php?page=brikpanel-dashboard' );
    // Already on the dashboard: its link only closes the tour.
    $on_dashboard = isset( $_GET['page'] ) && 'brikpanel-dashboard' === sanitize_key( wp_unslash( $_GET['page'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only comparison.

    $links = [];
    if ( '' !== $dashboard_url ) {
        $links[] = [
            'icon'  => 'dashboard',
            'title' => __( 'Dashboard', 'brikpanel' ),
            'desc'  => __( 'Sales, net profit and live visitors', 'brikpanel' ),
            'cta'   => _x( 'Open', 'welcome tour link', 'brikpanel' ),
            'url'   => $dashboard_url,
            'here'  => $on_dashboard,
        ];
    }
    $links[] = [
        'icon'  => 'orders',
        'title' => __( 'Orders', 'brikpanel' ),
        'desc'  => __( 'New orders and quick status changes', 'brikpanel' ),
        'cta'   => _x( 'Open', 'welcome tour link', 'brikpanel' ),
        'url'   => function_exists( 'brikpanel_wc_orders_list_url' ) ? brikpanel_wc_orders_list_url() : admin_url( 'edit.php?post_type=shop_order' ),
        'here'  => false,
    ];
    $links[] = [
        'icon'  => 'customers',
        'title' => __( 'Customers', 'brikpanel' ),
        'desc'  => __( 'Lifetime value and segments', 'brikpanel' ),
        'cta'   => _x( 'Open', 'welcome tour link', 'brikpanel' ),
        'url'   => function_exists( 'brikpanel_module_url' ) ? brikpanel_module_url( 'brikpanel-customer-analytics' ) : admin_url( 'admin.php?page=brikpanel-customer-analytics' ),
        'here'  => false,
    ];
    if ( ! function_exists( 'brikpanel_module_available' ) || brikpanel_module_available( 'brikpanel-google-sheets' ) ) {
        $sheets_connected = class_exists( 'Brikpanel_Sheets_Tokens' ) && Brikpanel_Sheets_Tokens::is_connected();
        $links[]          = [
            'icon'  => 'sheets',
            'title' => __( 'Google Sheets', 'brikpanel' ),
            'desc'  => __( 'Sync your store to a sheet', 'brikpanel' ),
            'cta'   => $sheets_connected ? _x( 'Open', 'welcome tour link', 'brikpanel' ) : _x( 'Connect', 'welcome tour link', 'brikpanel' ),
            'url'   => admin_url( 'admin.php?page=brikpanel-google-sheets' ),
            'here'  => false,
        ];
    }

    // The bricks, in the order the steps lay them: their place on the plate
    // and the colours of their top, front and left faces.
    $bricks = [
        [ 46, 46, '#fbfbfb', '#dadada', '#c2c2c2' ],
        [ 130, 46, '#a8a8a8', '#929292', '#7b7b7b' ],
        [ 46, 130, '#a8a8a8', '#929292', '#7b7b7b' ],
        [ 130, 130, '#fbfbfb', '#dadada', '#c2c2c2' ],
    ];
    ?>
    <dialog id="brikpanel-welcome-overlay" class="brikpanel-welcome-overlay" aria-labelledby="brikpanel-welcome-title">
        <div class="brikpanel-welcome-modal" tabindex="-1" data-bw-modal>
            <?php echo brikpanel_welcome_sketch_defs(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?>

            <!-- Left: the plate and its bricks -->
            <div class="brikpanel-welcome-left" data-bw-left>
                <div class="brikpanel-welcome-intro">
                    <span class="brikpanel-welcome-brand" dir="ltr">
                        <span class="brikpanel-welcome-brand-mark" aria-hidden="true"><span></span><span></span><span></span><span></span></span>
                        BrikPanel
                    </span>
                    <h2 id="brikpanel-welcome-title" class="brikpanel-welcome-title">
                        <span data-bw-start><?php esc_html_e( 'Welcome to BrikPanel', 'brikpanel' ); ?></span>
                        <span data-bw-done hidden><?php esc_html_e( 'You are all set', 'brikpanel' ); ?></span>
                    </h2>
                    <p class="brikpanel-welcome-lead" data-bw-start><?php esc_html_e( 'Four things BrikPanel does for your store. Each one lays a brick.', 'brikpanel' ); ?></p>
                    <p class="brikpanel-welcome-lead" data-bw-done hidden><?php esc_html_e( 'Every brick is in place, and everything is already switched on.', 'brikpanel' ); ?></p>
                </div>

                <div class="brikpanel-welcome-stage" aria-hidden="true" dir="ltr" data-bw-stage>
                    <span class="brikpanel-welcome-glow"></span>
                    <span class="brikpanel-welcome-persp">
                        <span class="brikpanel-welcome-iso">
                            <span class="brikpanel-welcome-face brikpanel-welcome-ground"></span>
                            <span class="brikpanel-welcome-box brikpanel-welcome-plate">
                                <span class="brikpanel-welcome-face brikpanel-welcome-side brikpanel-welcome-plate-front"></span>
                                <span class="brikpanel-welcome-face brikpanel-welcome-side brikpanel-welcome-plate-left"></span>
                                <span class="brikpanel-welcome-face brikpanel-welcome-top brikpanel-welcome-plate-top"></span>
                            </span>
                            <?php foreach ( $bricks as $b ) : ?>
                                <?php $pos = 'left:' . (int) $b[0] . 'px;top:' . (int) $b[1] . 'px'; ?>
                                <span class="brikpanel-welcome-face brikpanel-welcome-slot" style="<?php echo esc_attr( $pos ); ?>" data-bw-slot></span>
                                <span class="brikpanel-welcome-face brikpanel-welcome-shadow" style="<?php echo esc_attr( $pos ); ?>" data-bw-shadow></span>
                                <span class="brikpanel-welcome-box brikpanel-welcome-brick" style="<?php echo esc_attr( $pos ); ?>" data-bw-brick>
                                    <span class="brikpanel-welcome-face brikpanel-welcome-side brikpanel-welcome-brick-front" style="<?php echo esc_attr( 'background:' . $b[3] ); ?>"></span>
                                    <span class="brikpanel-welcome-face brikpanel-welcome-side brikpanel-welcome-brick-left" style="<?php echo esc_attr( 'background:' . $b[4] ); ?>"></span>
                                    <span class="brikpanel-welcome-face brikpanel-welcome-top brikpanel-welcome-brick-top" style="<?php echo esc_attr( 'background:' . $b[2] ); ?>"></span>
                                </span>
                            <?php endforeach; ?>
                        </span>
                    </span>
                </div>

                <nav class="brikpanel-welcome-steps" data-bp-strip aria-label="<?php esc_attr_e( 'Tour sections', 'brikpanel' ); ?>">
                    <?php foreach ( $steps as $i => $step ) : ?>
                        <button type="button" class="brikpanel-welcome-step<?php echo 0 === $i ? ' is-active' : ''; ?>" data-bw-goto="<?php echo (int) $i; ?>"<?php echo 0 === $i ? ' aria-current="step"' : ''; ?>>
                            <span class="brikpanel-welcome-step-num" aria-hidden="true"><?php echo esc_html( $num( $i + 1 ) ); ?></span>
                            <span class="brikpanel-welcome-step-name"><?php echo esc_html( $step['tab'] ); ?></span>
                        </button>
                    <?php endforeach; ?>
                </nav>
            </div>

            <!-- Right: one feature area at a time -->
            <div class="brikpanel-welcome-right">
                <button type="button" class="brikpanel-welcome-close" data-bw-close aria-label="<?php esc_attr_e( 'Close', 'brikpanel' ); ?>"><?php echo $icon_close; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?></button>
                <p class="brikpanel-welcome-sr" aria-live="polite" data-bw-live></p>

                <div class="brikpanel-welcome-panels">
                    <?php foreach ( $steps as $i => $step ) : ?>
                        <section class="brikpanel-welcome-panel" data-bw-panel="<?php echo (int) $i; ?>"<?php echo 0 === $i ? '' : ' hidden'; ?>>
                            <p class="brikpanel-welcome-kicker" data-bw-kicker><?php echo esc_html( $step['kicker'] ); ?></p>
                            <h3 class="brikpanel-welcome-heading"><?php echo esc_html( $step['title'] ); ?></h3>
                            <p class="brikpanel-welcome-text"><?php echo esc_html( $step['text'] ); ?></p>
                            <ul class="brikpanel-welcome-chips">
                                <?php foreach ( $step['chips'] as $chip ) : ?>
                                    <li class="brikpanel-welcome-chip"><?php echo $icon_check; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?><?php echo esc_html( $chip ); ?></li>
                                <?php endforeach; ?>
                            </ul>
                            <?php echo brikpanel_welcome_sketch( $step['key'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG, notes escaped inside. ?>
                        </section>
                    <?php endforeach; ?>

                    <section class="brikpanel-welcome-panel brikpanel-welcome-panel--done" data-bw-panel="<?php echo (int) $count; ?>" hidden>
                        <p class="brikpanel-welcome-kicker brikpanel-welcome-kicker--done" data-bw-kicker><?php esc_html_e( 'All set', 'brikpanel' ); ?></p>
                        <h3 class="brikpanel-welcome-heading"><?php esc_html_e( 'You are ready to go', 'brikpanel' ); ?></h3>
                        <p class="brikpanel-welcome-text"><?php esc_html_e( 'Everything is already switched on. Jump straight to the part you need.', 'brikpanel' ); ?></p>
                        <ul class="brikpanel-welcome-links">
                            <?php foreach ( $links as $link ) : ?>
                                <li>
                                    <a class="brikpanel-welcome-link" href="<?php echo esc_url( $link['url'] ); ?>" data-bw-cta<?php echo $link['here'] ? ' data-bw-here' : ''; ?>>
                                        <span class="brikpanel-welcome-link-icon"><?php echo $link_icons[ $link['icon'] ]; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?></span>
                                        <span class="brikpanel-welcome-link-text">
                                            <span class="brikpanel-welcome-link-title"><?php echo esc_html( $link['title'] ); ?></span>
                                            <span class="brikpanel-welcome-link-desc"><?php echo esc_html( $link['desc'] ); ?></span>
                                        </span>
                                        <span class="brikpanel-welcome-link-cta"><?php echo esc_html( $link['cta'] ); ?><?php echo $icon_chev; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?></span>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </section>
                </div>

                <div class="brikpanel-welcome-footer">
                    <button type="button" class="brikpanel-btn brikpanel-btn--link brikpanel-welcome-skip" data-bw-skip><?php esc_html_e( 'Skip tour', 'brikpanel' ); ?></button>
                    <span class="brikpanel-welcome-footer-gap"></span>
                    <button type="button" class="brikpanel-btn brikpanel-btn--secondary brikpanel-welcome-back" data-bw-prev hidden><?php echo esc_html_x( 'Back', 'welcome tour button', 'brikpanel' ); ?></button>
                    <button type="button" class="brikpanel-btn brikpanel-btn--primary brikpanel-welcome-next" data-bw-next><span data-bw-next-label><?php echo esc_html_x( 'Next', 'welcome tour button', 'brikpanel' ); ?></span><?php echo $icon_arrow; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?></button>
                    <?php if ( '' !== $dashboard_url ) : ?>
                        <a class="brikpanel-btn brikpanel-btn--primary brikpanel-welcome-go" href="<?php echo esc_url( $dashboard_url ); ?>" data-bw-cta<?php echo $on_dashboard ? ' data-bw-here' : ''; ?> hidden><?php esc_html_e( 'Open your dashboard', 'brikpanel' ); ?><?php echo $icon_arrow; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?></a>
                    <?php else : ?>
                        <button type="button" class="brikpanel-btn brikpanel-btn--primary brikpanel-welcome-go" data-bw-close hidden><?php esc_html_e( 'Close', 'brikpanel' ); ?></button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </dialog>
    <?php
} );
