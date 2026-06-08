<?php
/**
 * BrikPanel - New Order Notifications
 *
 * Slide-in popup, chime sound, and confetti burst whenever a new paid order
 * arrives. Driven by an admin-side poll against an HPOS-compatible AJAX
 * endpoint that returns every "processing" order created since the
 * caller's last-seen ID.
 *
 * Behaviour is fully gated by the BrikPanel settings tab:
 *   - brikpanel_order_notify_popup     (yes/no, default yes)
 *   - brikpanel_order_notify_sound     (yes/no, default yes)
 *   - brikpanel_order_notify_confetti  (yes/no, default yes)
 *   - brikpanel_order_notify_volume    (0-100, default 70)
 *   - brikpanel_order_notify_interval  (10-300 seconds, default 30)
 *
 * Works for both simple and variable products — detection runs at the order
 * level, not the line-item level.
 *
 * @package BrikPanel
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Resolved notification settings — read once per request, cached in a static.
 *
 * @return array{popup:bool,sound:bool,confetti:bool,volume:int,interval:int,enabled:bool}
 */
function brikpanel_order_notify_settings() {
    static $cached = null;
    if ( $cached !== null ) {
        return $cached;
    }
    $popup    = get_option( 'brikpanel_order_notify_popup', 'yes' ) === 'yes';
    $sound    = get_option( 'brikpanel_order_notify_sound', 'yes' ) === 'yes';
    $confetti = get_option( 'brikpanel_order_notify_confetti', 'yes' ) === 'yes';
    $volume   = (int) get_option( 'brikpanel_order_notify_volume', 70 );
    $interval = (int) get_option( 'brikpanel_order_notify_interval', 30 );

    $volume   = max( 0, min( 100, $volume ) );
    $interval = max( 10, min( 300, $interval ) );

    $cached = [
        'popup'    => $popup,
        'sound'    => $sound,
        'confetti' => $confetti,
        'volume'   => $volume,
        'interval' => $interval,
        'enabled'  => ( $popup || $sound || $confetti ),
    ];
    return $cached;
}

/**
 * Enqueue popup assets in the WordPress admin (not inside the front end).
 *
 * Skipped entirely when:
 *   - the user lacks `manage_woocommerce`
 *   - all three notification surfaces (popup, sound, confetti) are off
 *   - we're on the WC settings tab where the user is editing these toggles —
 *     showing a toast over a settings save is jarring
 */
function brikpanel_order_notify_enqueue( $hook ) {
    unset( $hook );

    if ( ! is_admin() ) {
        return;
    }
    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        return;
    }

    $settings = brikpanel_order_notify_settings();
    if ( ! $settings['enabled'] ) {
        return;
    }

    $base_url = plugin_dir_url( __FILE__ );
    $base_dir = plugin_dir_path( __FILE__ );

    $css_ver = file_exists( $base_dir . 'brikpanel-order-notify.css' )
        ? filemtime( $base_dir . 'brikpanel-order-notify.css' )
        : BRIKPANEL_VERSION;
    $js_ver = file_exists( $base_dir . 'brikpanel-order-notify.js' )
        ? filemtime( $base_dir . 'brikpanel-order-notify.js' )
        : BRIKPANEL_VERSION;

    if ( $settings['popup'] ) {
        wp_enqueue_style(
            'brikpanel-order-notify',
            $base_url . 'brikpanel-order-notify.css',
            [],
            $css_ver
        );
    }

    $deps = [];
    if ( $settings['confetti'] ) {
        $confetti_path = BRIKPANEL_PATH . 'assets/js/confetti.browser.min.js';
        $confetti_url  = BRIKPANEL_URL . 'assets/js/confetti.browser.min.js';
        if ( file_exists( $confetti_path ) ) {
            wp_enqueue_script(
                'brikpanel-confetti',
                $confetti_url,
                [],
                filemtime( $confetti_path ),
                true
            );
            $deps[] = 'brikpanel-confetti';
        }
    }

    wp_enqueue_script(
        'brikpanel-order-notify',
        $base_url . 'brikpanel-order-notify.js',
        $deps,
        $js_ver,
        true
    );

    $sound_url = '';
    if ( $settings['sound'] ) {
        $sound_path = $base_dir . 'brikpanel-sound.wav';
        if ( file_exists( $sound_path ) ) {
            $sound_url = $base_url . 'brikpanel-sound.wav?v=' . filemtime( $sound_path );
        }
    }

    wp_localize_script(
        'brikpanel-order-notify',
        'BrikpanelOrderNotify',
        [
            'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'brikpanel_order_notify' ),
            'soundUrl' => $sound_url,
            'popup'    => $settings['popup'] ? '1' : '0',
            'sound'    => ( $settings['sound'] && $sound_url ) ? '1' : '0',
            'confetti' => ( $settings['confetti'] && in_array( 'brikpanel-confetti', $deps, true ) ) ? '1' : '0',
            'volume'   => $settings['volume'],
            'interval' => $settings['interval'],
            'i18n'     => [
                'title'         => __( 'New order!', 'brikpanel' ),
                'subtitle'      => __( 'Order #%number%', 'brikpanel' ),
                'totalLabel'    => __( 'Total', 'brikpanel' ),
                'itemsLabel'    => __( 'Items', 'brikpanel' ),
                'customerLabel' => __( 'Customer', 'brikpanel' ),
                'paymentLabel'  => __( 'Payment', 'brikpanel' ),
                'view'          => __( 'View order', 'brikpanel' ),
                'dismiss'       => __( 'Dismiss', 'brikpanel' ),
                'itemSingular'  => __( '1 item', 'brikpanel' ),
                'itemPlural'    => __( '%count% items', 'brikpanel' ),
            ],
        ]
    );
}
add_action( 'admin_enqueue_scripts', 'brikpanel_order_notify_enqueue', 20 );

/**
 * Cache the most recent processing-order ID so a brand-new admin session can
 * use it as a baseline without scanning the orders table on first load.
 *
 * `woocommerce_order_status_processing` fires for both simple and variable
 * orders — variation data lives at the line-item level, never on the order
 * itself, so detection at the order level covers both.
 */
function brikpanel_order_notify_track_processing( $order_id ) {
    $order_id = (int) $order_id;
    if ( $order_id <= 0 ) {
        return;
    }
    $current = (int) get_option( 'brikpanel_order_notify_latest_id', 0 );
    if ( $order_id > $current ) {
        update_option( 'brikpanel_order_notify_latest_id', $order_id, false );
    }
}
add_action( 'woocommerce_order_status_processing', 'brikpanel_order_notify_track_processing', 10, 1 );

/**
 * AJAX endpoint — return every "processing" order created since `last_seen`.
 *
 * Request:
 *   action     = brikpanel_check_new_orders
 *   security   = nonce (`brikpanel_order_notify`)
 *   last_seen  = integer order ID; 0 means "first run, just give me a baseline"
 *
 * Response (success):
 *   {
 *     firstRun: bool,
 *     baseline: int,        // newest processing-order ID known
 *     orders:   array<{
 *       id, number, total, itemCount, customer, payment, editUrl
 *     }>
 *   }
 */
function brikpanel_order_notify_ajax_check() {
    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
    }
    check_ajax_referer( 'brikpanel_order_notify', 'security' );

    $last_seen = isset( $_POST['last_seen'] ) ? absint( $_POST['last_seen'] ) : 0;

    // Resolve the newest processing order ID once — used both as the baseline
    // and as an early-exit when there is nothing new to report.
    //
    // Order by ID, NOT by date_created. The whole last-seen / baseline contract
    // is an integer ID comparison ($baseline > $last_seen), and order IDs are
    // assigned strictly in creation order, so the highest ID is always the
    // genuinely newest order. date_created is NOT reliable for this: it can be
    // backdated, imported, edited, or set out of ID order (demo/seeded stores,
    // CSV migrations, sequential-order-number plugins), in which case the
    // newest-by-date order is an OLD low-ID order — that desync silently
    // suppressed real notifications and replayed stale orders as if new.
    $latest_ids = wc_get_orders( [
        'status'  => [ 'processing' ],
        'limit'   => 1,
        'orderby' => 'ID',
        'order'   => 'DESC',
        'return'  => 'ids',
    ] );
    $baseline = ! empty( $latest_ids ) ? (int) $latest_ids[0] : 0;

    if ( $last_seen <= 0 ) {
        wp_send_json_success( [
            'firstRun' => true,
            'baseline' => $baseline,
            'orders'   => [],
        ] );
    }

    if ( $baseline <= $last_seen ) {
        wp_send_json_success( [
            'firstRun' => false,
            'baseline' => $baseline,
            'orders'   => [],
        ] );
    }

    // Fetch up to 5 newest unseen processing orders. We cap so a long-idle tab
    // returning from sleep can't trigger dozens of toasts at once. Ordered by
    // ID DESC for the same reason as the baseline above — the comparison that
    // gates each toast ( $id > $last_seen ) is an ID comparison, so the
    // candidate set must be the highest IDs, never the newest dates.
    $unseen_ids = wc_get_orders( [
        'status'  => [ 'processing' ],
        'limit'   => 5,
        'orderby' => 'ID',
        'order'   => 'DESC',
        'return'  => 'ids',
    ] );

    $orders = [];
    foreach ( $unseen_ids as $id ) {
        $id = (int) $id;
        if ( $id <= $last_seen ) {
            continue;
        }
        $order = wc_get_order( $id );
        if ( ! $order ) {
            continue;
        }

        $customer = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
        if ( $customer === '' ) {
            $customer = $order->get_billing_email();
        }

        $orders[] = [
            'id'        => $id,
            'number'    => $order->get_order_number(),
            // Total contains currency markup (e.g. <span class="...">) — keep
            // it as a small whitelisted HTML fragment so the symbol renders.
            'total'     => wp_kses(
                $order->get_formatted_order_total(),
                [
                    'span' => [ 'class' => true ],
                    'bdi'  => [],
                    'sup'  => [],
                ]
            ),
            'itemCount' => (int) $order->get_item_count(),
            'customer'  => $customer,
            'payment'   => $order->get_payment_method_title(),
            'editUrl'   => $order->get_edit_order_url(),
        ];
    }

    // Caller animates oldest → newest, so reverse before sending.
    $orders = array_reverse( $orders );

    wp_send_json_success( [
        'firstRun' => false,
        'baseline' => $baseline,
        'orders'   => $orders,
    ] );
}
add_action( 'wp_ajax_brikpanel_check_new_orders', 'brikpanel_order_notify_ajax_check' );
