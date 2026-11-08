<?php
/**
 * BrikPanel - New Order Notifications
 *
 * Slide-in popup, chime sound, and confetti burst whenever a new order
 * arrives. Driven by an admin-side poll against an HPOS-compatible AJAX
 * endpoint that returns every order in a new-order status
 * (brikpanel_new_order_statuses(): processing, completed, on-hold) created
 * since the caller's last-seen ID.
 *
 * One tab per site and user polls, also while it sits in the background
 * (front-end/sound/brikpanel-order-notify.js): the sound plays once and the
 * card shows once, in whichever tab is on screen.
 *
 * Three or more orders waiting at once come as one summary card (how many,
 * their total, the newest three) instead of a card each: after a few hours
 * away a busy store would otherwise get a long row of cards to click away.
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

    // brikpanelFormat counts the items in the store's number format and with
    // the right plural form of the admin language.
    $deps = function_exists( 'brikpanel_narrow_dep' ) ? brikpanel_narrow_dep( 'format' ) : [];
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

    // MP3 since 8 Nov 2026: the old WAV was 428 KB and every admin page
    // preloads the chime, which was more than half of what BrikPanel sends
    // on a plain admin screen. Same sound, 40 KB; every browser plays MP3.
    $sound_url = '';
    if ( $settings['sound'] ) {
        $sound_path = $base_dir . 'brikpanel-sound.mp3';
        if ( file_exists( $sound_path ) ) {
            $sound_url = $base_url . 'brikpanel-sound.mp3?v=' . filemtime( $sound_path );
        }
    }

    wp_localize_script(
        'brikpanel-order-notify',
        'BrikpanelOrderNotify',
        [
            'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'brikpanel_order_notify' ),
            // Shared state (last seen order, waiting cards, which tab polls)
            // is kept per site and person: subdirectory multisite sites share
            // one browser origin.
            'blog'     => get_current_blog_id(),
            'user'     => get_current_user_id(),
            'soundUrl' => $sound_url,
            'popup'    => $settings['popup'] ? '1' : '0',
            'sound'    => ( $settings['sound'] && $sound_url ) ? '1' : '0',
            'confetti' => ( $settings['confetti'] && in_array( 'brikpanel-confetti', $deps, true ) ) ? '1' : '0',
            'volume'   => $settings['volume'],
            'interval' => $settings['interval'],
            'viewing'  => brikpanel_order_notify_viewing(),
            // The summary card adds up totals only in the store currency,
            // and links to the orders list.
            'currency'  => get_woocommerce_currency(),
            'ordersUrl' => function_exists( 'brikpanel_wc_orders_list_url' )
                ? brikpanel_wc_orders_list_url()
                : admin_url( 'edit.php?post_type=shop_order' ),
            'i18n'     => [
                'title'         => __( 'New order!', 'brikpanel' ),
                'subtitle'      => __( 'Order #%number%', 'brikpanel' ),
                'totalLabel'    => __( 'Total', 'brikpanel' ),
                'itemsLabel'    => __( 'Items', 'brikpanel' ),
                'customerLabel' => __( 'Customer', 'brikpanel' ),
                'paymentLabel'  => __( 'Payment', 'brikpanel' ),
                'view'          => __( 'View order', 'brikpanel' ),
                'dismiss'       => __( 'Dismiss', 'brikpanel' ),
                // Plural forms of the admin language (Russian and Polish have three).
                'items'         => function_exists( 'brikpanel_js_plural' )
                    ? brikpanel_js_plural( _n_noop( '%s item', '%s items', 'brikpanel' ) )
                    : [ 'forms' => [ '%s' ], 'en' => true ],
                'awaiting'      => __( 'Awaiting payment', 'brikpanel' ),
                // The summary card for three or more waiting orders.
                'pileTitle'     => function_exists( 'brikpanel_js_plural' )
                    /* translators: %s: number of new orders. */
                    ? brikpanel_js_plural( _n_noop( '%s new order', '%s new orders', 'brikpanel' ) )
                    : [ 'forms' => [ '%s' ], 'en' => true ],
                /* translators: %d: number of further items that are not listed by name. */
                'pileMore'      => __( '+%d more', 'brikpanel' ),
                /* translators: %s: total amount of the new orders, e.g. $122.00. */
                'pileTotal'     => __( 'Total %s', 'brikpanel' ),
                'viewOrders'    => __( 'View orders', 'brikpanel' ),
            ],
        ]
    );
}
add_action( 'admin_enqueue_scripts', 'brikpanel_order_notify_enqueue', 20 );

/**
 * The order this screen edits, 0 on every other screen. That order gets no
 * card and no sound: the person is already looking at it (a tap on its phone
 * notification lands here, front-end/push).
 *
 * @return int Order id.
 */
function brikpanel_order_notify_viewing() {
    // phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only: which order this screen shows, used as a number.
    $action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
    $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
    if ( 'edit' !== $action || ! $screen ) {
        return 0;
    }
    $id = 0;
    if ( 'post' === $screen->base && 'shop_order' === $screen->post_type && isset( $_GET['post'] ) ) {
        $id = absint( $_GET['post'] );
    } elseif ( isset( $_GET['page'], $_GET['id'] ) && 'wc-orders' === sanitize_key( wp_unslash( $_GET['page'] ) ) ) {
        $id = absint( $_GET['id'] );
    }
    // phpcs:enable WordPress.Security.NonceVerification.Recommended
    return $id;
}

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

// =============================================================================
// MARKETPLACE ORDERS
// =============================================================================
//
// A store that also sells on marketplaces through BrikMarket gets most of its
// orders from there, and those are handled in the marketplace's own panel.
// With "Skip marketplace orders" on, they do not announce themselves, so an
// order placed on the store itself is not lost among them.

/**
 * Meta keys that mark an order a marketplace integration brought in.
 * BrikMarket keeps the marketplace in `_brksoft_marketplace`.
 *
 * @return string[]
 */
function brikpanel_marketplace_order_meta_keys() {
    /**
     * Filter the order meta keys that mark a marketplace order.
     *
     * @param string[] $keys Meta keys.
     */
    $keys = (array) apply_filters( 'brikpanel_marketplace_order_meta_keys', [ '_brksoft_marketplace' ] );
    return array_values( array_unique( array_filter( array_map( 'strval', $keys ) ) ) );
}

/**
 * Whether new order alerts leave marketplace orders out.
 *
 * @return bool
 */
function brikpanel_order_notify_skips_marketplace() {
    return 'yes' === get_option( 'brikpanel_order_notify_skip_marketplace', 'no' )
        && [] !== brikpanel_marketplace_order_meta_keys();
}

/**
 * Whether the order came from a marketplace.
 *
 * @param mixed $order Order.
 * @return bool
 */
function brikpanel_is_marketplace_order( $order ) {
    if ( ! $order instanceof WC_Order ) {
        return false;
    }
    if ( 'brikmarket' === $order->get_created_via() ) {
        return true;
    }
    foreach ( brikpanel_marketplace_order_meta_keys() as $key ) {
        if ( '' !== (string) $order->get_meta( $key, true, 'edit' ) ) {
            return true;
        }
    }
    return false;
}

/**
 * The `meta_query` that leaves marketplace orders out of a wc_get_orders()
 * call, or an empty array when they are announced like any other order.
 * Filtering in the query, not afterwards, keeps a burst of marketplace orders
 * from pushing a store order out of the newest five.
 *
 * @return array
 */
function brikpanel_order_notify_marketplace_clause() {
    if ( ! brikpanel_order_notify_skips_marketplace() ) {
        return [];
    }
    $clause = [ 'relation' => 'AND' ];
    foreach ( brikpanel_marketplace_order_meta_keys() as $key ) {
        $clause[] = [
            'key'     => $key,
            'compare' => 'NOT EXISTS',
        ];
    }
    return $clause;
}

/**
 * The setting sits under the other order alert options, and only on stores
 * where BrikMarket brings orders in.
 *
 * @param array $fields WC settings field definitions.
 * @return array
 */
function brikpanel_order_notify_marketplace_field( $fields ) {
    if ( ! function_exists( 'brikpanel_brikmarket_active' ) || ! brikpanel_brikmarket_active() ) {
        return $fields;
    }
    $field = [
        'name'    => __( 'Skip marketplace orders', 'brikpanel' ),
        'id'      => 'brikpanel_order_notify_skip_marketplace',
        'type'    => 'checkbox',
        'desc'    => __( 'Orders that BrikMarket brings in from marketplaces such as Trendyol, Hepsiburada or Amazon do not trigger a new order alert, so orders placed on your own store stand out.', 'brikpanel' ),
        'default' => 'no',
    ];
    $fields = array_values( (array) $fields );
    foreach ( $fields as $i => $existing ) {
        if ( is_array( $existing ) && isset( $existing['id'] ) && 'brikpanel_order_notify_confetti' === $existing['id'] ) {
            array_splice( $fields, $i + 1, 0, [ $field ] );
            break;
        }
    }
    return $fields;
}
add_filter( 'brikpanel_settings_fields', 'brikpanel_order_notify_marketplace_field' );

/**
 * Phone notifications follow the same setting.
 *
 * @param bool     $notify Whether the order gets a phone notification.
 * @param WC_Order $order  The order.
 * @return bool
 */
function brikpanel_order_notify_push_marketplace( $notify, $order ) {
    if ( $notify && brikpanel_order_notify_skips_marketplace() && brikpanel_is_marketplace_order( $order ) ) {
        return false;
    }
    return $notify;
}
add_filter( 'brikpanel_push_should_notify', 'brikpanel_order_notify_push_marketplace', 10, 2 );

/**
 * AJAX endpoint — return every new order (brikpanel_new_order_statuses())
 * created since `last_seen`.
 *
 * Request:
 *   action     = brikpanel_check_new_orders
 *   security   = nonce (`brikpanel_order_notify`)
 *   last_seen  = integer order ID; 0 means "first run, just give me a baseline"
 *   known      = "1" when the browser already took its baseline: with
 *                last_seen 0 that means the shop had no new order then
 *
 * Response (success):
 *   {
 *     firstRun: bool,
 *     baseline: int,        // newest new-order ID known
 *     nonce:    string,     // fresh nonce: tabs left open for days keep working
 *     orders:   array<{
 *       id, number, total, amount, currency, itemCount, customer, payment,
 *       awaiting, editUrl
 *     }>,
 *     more:     null|{ count, sums: { currency: amount } }  // new orders not sent
 *   }
 */
function brikpanel_order_notify_ajax_check() {
    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
    }
    check_ajax_referer( 'brikpanel_order_notify', 'security' );

    $last_seen = isset( $_POST['last_seen'] ) ? absint( $_POST['last_seen'] ) : 0;
    // A browser that took its baseline while the shop had no new order yet
    // also sends 0, with "known". Its shop's first orders are news: before,
    // the very first order silently became the baseline and never had a card.
    $known     = $last_seen <= 0 && isset( $_POST['known'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['known'] ) );
    $statuses  = function_exists( 'brikpanel_new_order_statuses' ) ? brikpanel_new_order_statuses() : [ 'processing' ];
    $nonce     = wp_create_nonce( 'brikpanel_order_notify' );

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
    $skip_marketplace = brikpanel_order_notify_marketplace_clause();
    $latest_query     = [
        'status'  => $statuses,
        'type'    => 'shop_order',
        'limit'   => 1,
        'orderby' => 'ID',
        'order'   => 'DESC',
        'return'  => 'ids',
    ];
    if ( $skip_marketplace ) {
        $latest_query['meta_query'] = $skip_marketplace; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
    }
    $latest_ids = wc_get_orders( $latest_query );
    $baseline   = ! empty( $latest_ids ) ? (int) $latest_ids[0] : 0;

    if ( $last_seen <= 0 && ! $known ) {
        wp_send_json_success( [
            'firstRun' => true,
            'baseline' => $baseline,
            'nonce'    => $nonce,
            'orders'   => [],
        ] );
    }

    if ( $baseline <= $last_seen ) {
        wp_send_json_success( [
            'firstRun' => false,
            'baseline' => $baseline,
            'nonce'    => $nonce,
            'orders'   => [],
        ] );
    }

    // Fetch up to 5 newest unseen new orders. We cap so a long-idle tab
    // returning from sleep can't trigger dozens of toasts at once. Ordered by
    // ID DESC for the same reason as the baseline above — the comparison that
    // gates each toast ( $id > $last_seen ) is an ID comparison, so the
    // candidate set must be the highest IDs, never the newest dates.
    //
    // From an empty start only the last day counts: orders brought in later
    // with their old dates (a move from another shop system) are no news.
    $since = $known ? time() - DAY_IN_SECONDS : 0;
    $limit = 5;
    $query = [
        'status'  => $statuses,
        'type'    => 'shop_order',
        'limit'   => $limit,
        'orderby' => 'ID',
        'order'   => 'DESC',
        'return'  => 'ids',
    ];
    if ( $since ) {
        $query['date_created'] = '>=' . $since;
    }
    if ( $skip_marketplace ) {
        $query['meta_query'] = $skip_marketplace; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
    }
    $unseen_ids = wc_get_orders( $query );

    $orders  = [];
    $new_ids = [];
    foreach ( $unseen_ids as $id ) {
        $id = (int) $id;
        if ( $id <= $last_seen ) {
            continue;
        }
        $new_ids[] = $id;
        $order     = wc_get_order( $id );
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
            // Built with wc_price(), not get_formatted_order_total(): that one
            // runs a filter multi-currency plugins write into. CURCY adds
            // "Currency: USD" (and a red base-currency line) on every admin
            // request without a screen, which this AJAX call is, and wp_kses()
            // keeps the text of the tags it drops.
            'total'     => wp_kses(
                wc_price( $order->get_total(), [ 'currency' => $order->get_currency() ] ),
                [
                    'span' => [ 'class' => true ],
                    'bdi'  => [],
                    'sup'  => [],
                ]
            ),
            // The summary card adds the totals up.
            'amount'    => (float) $order->get_total(),
            'currency'  => $order->get_currency(),
            'itemCount' => (int) $order->get_item_count(),
            'customer'  => $customer,
            // Gateway titles are saved through wp_kses_post(): "&" is "&amp;".
            'payment'   => brikpanel_plain_label( $order->get_payment_method_title() ),
            // A bank transfer waits on-hold: the card says the money is not in yet.
            'awaiting'  => $order->has_status( 'on-hold' ),
            'editUrl'   => $order->get_edit_order_url(),
        ];
    }

    // Caller animates oldest → newest, so reverse before sending.
    $orders = array_reverse( $orders );

    // Only the newest five are sent. When all five are new, more may have
    // come in since the caller's last look: the summary card counts them and
    // adds their totals, so it says how many orders really arrived.
    $more = null;
    if ( count( $new_ids ) === $limit ) {
        $more = brikpanel_order_notify_unsent( $statuses, $last_seen, min( $new_ids ), null, $since );
        if ( $more['count'] < 1 ) {
            $more = null;
        }
    }

    wp_send_json_success( [
        'firstRun' => false,
        'baseline' => $baseline,
        'nonce'    => $nonce,
        'orders'   => $orders,
        'more'     => $more,
    ] );
}
add_action( 'wp_ajax_brikpanel_check_new_orders', 'brikpanel_order_notify_ajax_check' );

/**
 * New orders a poll does not send: it sends the newest five, and after a
 * busy few hours away there can be more. Counts the orders whose ID lies
 * between the two given ones and adds up their totals per currency.
 *
 * @param string[]  $statuses  Status slugs without "wc-" (brikpanel_new_order_statuses()).
 * @param int       $after_id  Last order the browser saw (not counted).
 * @param int       $before_id Oldest order the poll sends (not counted).
 * @param bool|null $hpos      Order storage; null reads WooCommerce's setting.
 * @param int       $since     Only orders created at or after this Unix time; 0 for any.
 * @return array{count:int,sums:array<string,float>}
 */
function brikpanel_order_notify_unsent( $statuses, $after_id, $before_id, $hpos = null, $since = 0 ) {
    global $wpdb;

    $out       = [ 'count' => 0, 'sums' => [] ];
    $after_id  = absint( $after_id );
    $before_id = absint( $before_id );
    $statuses  = array_values( array_filter( array_map( 'sanitize_key', (array) $statuses ) ) );
    if ( ! $statuses || $before_id <= $after_id + 1 ) {
        return $out;
    }
    if ( null === $hpos ) {
        $hpos = function_exists( 'brikpanel_wc_hpos_enabled' ) && brikpanel_wc_hpos_enabled();
    }

    // Both tables keep the status with its "wc-" prefix.
    $wc_statuses = array_map(
        static function ( $status ) {
            return 'wc-' . $status;
        },
        $statuses
    );
    $in    = implode( ', ', array_fill( 0, count( $wc_statuses ), '%s' ) );
    $args  = array_merge( $wc_statuses, [ $after_id, $before_id ] );
    $since = absint( $since );
    $date  = '';
    if ( $since ) {
        $date   = $hpos ? ' AND date_created_gmt >= %s' : ' AND p.post_date_gmt >= %s';
        $args[] = gmdate( 'Y-m-d H:i:s', $since );
    }

    // Marketplace orders are left out here too when the poll leaves them out.
    $skip = '';
    $keys = brikpanel_order_notify_skips_marketplace() ? brikpanel_marketplace_order_meta_keys() : [];
    if ( $keys ) {
        $key_in = implode( ', ', array_fill( 0, count( $keys ), '%s' ) );
        $skip   = $hpos
            ? " AND NOT EXISTS ( SELECT 1 FROM {$wpdb->prefix}wc_orders_meta mk WHERE mk.order_id = {$wpdb->prefix}wc_orders.id AND mk.meta_key IN ({$key_in}) )"
            : " AND NOT EXISTS ( SELECT 1 FROM {$wpdb->postmeta} mk WHERE mk.post_id = p.ID AND mk.meta_key IN ({$key_in}) )";
        $args   = array_merge( $args, $keys );
    }

    // The ID range starts at the last order the browser saw, so the scan is
    // a short walk along the primary key.
    if ( $hpos ) {
        $sql = "SELECT currency AS cur, COUNT(*) AS n, SUM(total_amount) AS amount
            FROM {$wpdb->prefix}wc_orders
            WHERE type = 'shop_order' AND status IN ({$in}) AND id > %d AND id < %d{$date}{$skip}
            GROUP BY currency";
    } else {
        $sql = "SELECT cur.meta_value AS cur, COUNT(*) AS n, SUM(tot.meta_value) AS amount
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} tot ON tot.post_id = p.ID AND tot.meta_key = '_order_total'
            LEFT JOIN {$wpdb->postmeta} cur ON cur.post_id = p.ID AND cur.meta_key = '_order_currency'
            WHERE p.post_type = 'shop_order' AND p.post_status IN ({$in}) AND p.ID > %d AND p.ID < %d{$date}{$skip}
            GROUP BY cur.meta_value";
    }

    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- only the placeholder list is interpolated.
    $rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ) );
    foreach ( (array) $rows as $row ) {
        $n = (int) $row->n;
        if ( $n < 1 ) {
            continue;
        }
        $out['count'] += $n;
        // Very old orders may lack the currency field: they were placed in
        // the store currency.
        $cur = strtoupper( (string) preg_replace( '/[^A-Za-z0-9]/', '', (string) $row->cur ) );
        if ( '' === $cur ) {
            $cur = get_woocommerce_currency();
        }
        $out['sums'][ $cur ] = round( ( $out['sums'][ $cur ] ?? 0 ) + (float) $row->amount, 4 );
    }
    return $out;
}
