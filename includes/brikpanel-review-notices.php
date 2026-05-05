<?php
/**
 * BrikPanel — Review Request Admin Notices
 *
 * Shows two contextual review-request notices:
 *   1. After 14 days since plugin activation.
 *   2. After 50 completed WooCommerce orders since plugin activation.
 *
 * Notices are dismissable, snoozable, and respect a single permanent
 * "already reviewed" state shared by both triggers.
 *
 * @package BrikPanel
 * @since   2.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* ── Configuration ──────────────────────────────────────────────────────────── */
if ( ! defined( 'BRIKPANEL_REVIEW_DAYS_THRESHOLD' ) ) {
    define( 'BRIKPANEL_REVIEW_DAYS_THRESHOLD', 14 );
}
if ( ! defined( 'BRIKPANEL_REVIEW_ORDERS_THRESHOLD' ) ) {
    define( 'BRIKPANEL_REVIEW_ORDERS_THRESHOLD', 50 );
}
if ( ! defined( 'BRIKPANEL_REVIEW_SNOOZE_DAYS' ) ) {
    define( 'BRIKPANEL_REVIEW_SNOOZE_DAYS', 7 );
}
if ( ! defined( 'BRIKPANEL_REVIEW_URL' ) ) {
    define( 'BRIKPANEL_REVIEW_URL', 'https://wordpress.org/support/plugin/brikpanel-admin-panel-dashboard-for-woocommerce/reviews/?filter=5#new-post' );
}

/* ── Activation: store install timestamp ────────────────────────────────────── */
register_activation_hook( BRIKPANEL_PATH . 'brikpanel.php', 'brikpanel_review_on_activate' );
function brikpanel_review_on_activate() {
    if ( ! get_option( 'brikpanel_activated_at' ) ) {
        add_option( 'brikpanel_activated_at', time(), '', false );
    }
    if ( false === get_option( 'brikpanel_completed_orders_count', false ) ) {
        add_option( 'brikpanel_completed_orders_count', 0, '', false );
    }
}

/* ── Bootstrap fallback (covers updates from versions without these options) ── */
add_action( 'admin_init', 'brikpanel_review_bootstrap' );
function brikpanel_review_bootstrap() {
    if ( ! get_option( 'brikpanel_activated_at' ) ) {
        add_option( 'brikpanel_activated_at', time(), '', false );
    }
    if ( false === get_option( 'brikpanel_completed_orders_count', false ) ) {
        add_option( 'brikpanel_completed_orders_count', 0, '', false );
    }
}

/* ── Increment completed-orders counter ─────────────────────────────────────── */
add_action( 'woocommerce_order_status_completed', 'brikpanel_review_increment_orders', 10, 1 );
function brikpanel_review_increment_orders( $order_id ) {
    // No need to keep counting once the user has dismissed permanently.
    if ( get_option( 'brikpanel_review_dismissed' ) ) {
        return;
    }
    $count = (int) get_option( 'brikpanel_completed_orders_count', 0 );
    update_option( 'brikpanel_completed_orders_count', $count + 1, false );
}

/* ── Decide whether (and which) notice should appear ────────────────────────── */
function brikpanel_review_get_active_notice() {
    if ( get_option( 'brikpanel_review_dismissed' ) ) {
        return false;
    }

    $snooze_until = (int) get_option( 'brikpanel_review_snooze_until', 0 );
    if ( $snooze_until && $snooze_until > time() ) {
        return false;
    }

    // Orders trigger takes priority — it's a stronger signal of value.
    $orders = (int) get_option( 'brikpanel_completed_orders_count', 0 );
    if ( $orders >= BRIKPANEL_REVIEW_ORDERS_THRESHOLD ) {
        return 'orders';
    }

    $activated_at = (int) get_option( 'brikpanel_activated_at', 0 );
    if ( $activated_at && ( time() - $activated_at ) >= ( BRIKPANEL_REVIEW_DAYS_THRESHOLD * DAY_IN_SECONDS ) ) {
        return 'days';
    }

    return false;
}

/* ── Render notice ──────────────────────────────────────────────────────────── */
add_action( 'admin_notices', 'brikpanel_review_render_notice' );
function brikpanel_review_render_notice() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    $type = brikpanel_review_get_active_notice();
    if ( ! $type ) {
        return;
    }

    $nonce = wp_create_nonce( 'brikpanel_review_nonce' );

    if ( 'orders' === $type ) {
        $title = sprintf(
            /* translators: %d: number of completed orders */
            __( "You've completed %d orders with BrikPanel — congrats!", 'brikpanel' ),
            BRIKPANEL_REVIEW_ORDERS_THRESHOLD
        );
        $body = __( 'It looks like BrikPanel is helping you run your store. Would you take 30 seconds to leave a 5-star review? It would mean the world to our small team and helps other store owners discover the plugin.', 'brikpanel' );
    } else {
        $title = __( 'Enjoying BrikPanel so far?', 'brikpanel' );
        $body  = __( "You've been using BrikPanel for a couple of weeks. If it has saved you time, would you mind leaving a quick 5-star review? It only takes 30 seconds and helps us a lot.", 'brikpanel' );
    }
    ?>
    <div class="notice brikpanel-notice brikpanel-review-notice" data-nonce="<?php echo esc_attr( $nonce ); ?>" data-trigger="<?php echo esc_attr( $type ); ?>">
        <div class="brikpanel-review-notice__inner">
            <div class="brikpanel-review-notice__icon" aria-hidden="true">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M12 2.5l2.95 5.98 6.6.96-4.78 4.66 1.13 6.58L12 17.58l-5.9 3.1 1.13-6.58L2.45 9.44l6.6-.96L12 2.5z" fill="#ffffff"/>
                </svg>
            </div>
            <div class="brikpanel-review-notice__content">
                <p class="brikpanel-review-notice__title"><?php echo esc_html( $title ); ?></p>
                <p class="brikpanel-review-notice__body"><?php echo esc_html( $body ); ?></p>
                <div class="brikpanel-review-notice__actions">
                    <a href="<?php echo esc_url( BRIKPANEL_REVIEW_URL ); ?>"
                       target="_blank"
                       rel="noopener noreferrer"
                       class="brikpanel-review-notice__btn brikpanel-review-notice__btn--primary"
                       data-action="reviewed">
                        <?php esc_html_e( 'Leave a 5-star review', 'brikpanel' ); ?>
                    </a>
                    <button type="button"
                            class="brikpanel-review-notice__btn brikpanel-review-notice__btn--secondary"
                            data-action="already">
                        <?php esc_html_e( 'I already did', 'brikpanel' ); ?>
                    </button>
                    <button type="button"
                            class="brikpanel-review-notice__btn brikpanel-review-notice__btn--ghost"
                            data-action="snooze">
                        <?php esc_html_e( 'Maybe later', 'brikpanel' ); ?>
                    </button>
                </div>
            </div>
            <button type="button"
                    class="brikpanel-review-notice__close"
                    data-action="snooze"
                    aria-label="<?php esc_attr_e( 'Dismiss', 'brikpanel' ); ?>">
                <svg width="14" height="14" viewBox="0 0 14 14" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M1 1l12 12M13 1L1 13" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>
                </svg>
            </button>
        </div>
    </div>
    <style>
        .brikpanel-review-notice {
            background: #ffffff;
            border: 1px solid #e3e3e3;
            border-left: 4px solid #303030;
            border-radius: 0.75rem;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
            margin: 1rem 20px 1rem 2px;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            color: #303030;
        }
        .brikpanel-review-notice__inner {
            display: flex;
            align-items: flex-start;
            gap: 1rem;
            padding: 1.25rem 1.5rem;
            position: relative;
        }
        .brikpanel-review-notice__icon {
            flex-shrink: 0;
            width: 42px;
            height: 42px;
            border-radius: 50%;
            background: #303030;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .brikpanel-review-notice__content {
            flex: 1 1 auto;
            min-width: 0;
            padding-right: 2rem;
        }
        .brikpanel-review-notice__title {
            margin: 0 0 0.375rem !important;
            font-size: 1rem !important;
            font-weight: 600 !important;
            color: #303030 !important;
            line-height: 1.4 !important;
            padding: 0 !important;
        }
        .brikpanel-review-notice__body {
            margin: 0 0 0.875rem !important;
            font-size: 0.875rem !important;
            color: #616161 !important;
            line-height: 1.5 !important;
            padding: 0 !important;
        }
        .brikpanel-review-notice__actions {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            align-items: center;
        }
        .brikpanel-review-notice__btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0.5rem 1rem;
            border-radius: 0.5rem;
            font-size: 0.8125rem;
            font-weight: 550;
            font-family: inherit;
            text-decoration: none;
            cursor: pointer;
            border: none;
            line-height: 1.2;
            transition: background 0.15s ease, color 0.15s ease;
        }
        .brikpanel-review-notice__btn:focus {
            outline: none;
            box-shadow: 0 0 0 2px #303030;
        }
        .brikpanel-review-notice__btn--primary {
            background: #303030;
            color: #ffffff;
            box-shadow: inset 0 -1px 0 rgba(0, 0, 0, 0.2), inset 0 1px 0 rgba(255, 255, 255, 0.1);
        }
        .brikpanel-review-notice__btn--primary:hover,
        .brikpanel-review-notice__btn--primary:focus {
            background: #1a1a1a;
            color: #ffffff;
        }
        .brikpanel-review-notice__btn--secondary {
            background: #ffffff;
            color: #303030;
            box-shadow: inset 0 0 0 1px #e3e3e3, 0 1px 0 rgba(0, 0, 0, 0.05);
        }
        .brikpanel-review-notice__btn--secondary:hover,
        .brikpanel-review-notice__btn--secondary:focus {
            background: #f7f7f7;
            color: #303030;
        }
        .brikpanel-review-notice__btn--ghost {
            background: transparent;
            color: #8a8a8a;
        }
        .brikpanel-review-notice__btn--ghost:hover,
        .brikpanel-review-notice__btn--ghost:focus {
            color: #303030;
            background: #f7f7f7;
        }
        .brikpanel-review-notice__close {
            position: absolute;
            top: 0.625rem;
            right: 0.75rem;
            width: 28px;
            height: 28px;
            border-radius: 0.375rem;
            background: transparent;
            border: none;
            color: #8a8a8a;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0;
            transition: background 0.15s ease, color 0.15s ease;
        }
        .brikpanel-review-notice__close:hover,
        .brikpanel-review-notice__close:focus {
            color: #303030;
            background: #f7f7f7;
            outline: none;
        }
        @media (max-width: 600px) {
            .brikpanel-review-notice__inner { padding: 1rem 1.125rem; gap: 0.75rem; }
            .brikpanel-review-notice__icon { width: 36px; height: 36px; }
            .brikpanel-review-notice__content { padding-right: 1.75rem; }
            .brikpanel-review-notice__actions { gap: 0.375rem; }
            .brikpanel-review-notice__btn { padding: 0.5rem 0.75rem; }
        }
    </style>
    <script>
        (function () {
            var notice = document.querySelector('.brikpanel-review-notice');
            if (!notice) return;
            var nonce = notice.getAttribute('data-nonce');
            var ajaxUrl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
            var sent = false;

            function dismiss(action) {
                if (sent) return;
                sent = true;
                var fd = new FormData();
                fd.append('action', 'brikpanel_review_action');
                fd.append('_ajax_nonce', nonce);
                fd.append('review_action', action);
                try {
                    fetch(ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' });
                } catch (err) {}
                notice.style.transition = 'opacity 0.25s ease, transform 0.25s ease';
                notice.style.opacity = '0';
                notice.style.transform = 'translateY(-8px)';
                setTimeout(function () { if (notice && notice.parentNode) notice.parentNode.removeChild(notice); }, 280);
            }

            notice.querySelectorAll('[data-action]').forEach(function (el) {
                el.addEventListener('click', function (e) {
                    var act = el.getAttribute('data-action');
                    if (el.tagName === 'A') {
                        // Let the link open in a new tab; just record the dismissal.
                        dismiss('reviewed');
                        return;
                    }
                    e.preventDefault();
                    dismiss(act);
                });
            });
        })();
    </script>
    <?php
}

/* ── AJAX: handle dismiss / snooze / reviewed actions ───────────────────────── */
add_action( 'wp_ajax_brikpanel_review_action', 'brikpanel_review_ajax_handler' );
function brikpanel_review_ajax_handler() {
    check_ajax_referer( 'brikpanel_review_nonce' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( [ 'message' => 'forbidden' ], 403 );
    }

    $action = isset( $_POST['review_action'] ) ? sanitize_key( wp_unslash( $_POST['review_action'] ) ) : '';

    switch ( $action ) {
        case 'reviewed':
        case 'already':
            update_option( 'brikpanel_review_dismissed', 1, false );
            delete_option( 'brikpanel_review_snooze_until' );
            break;

        case 'snooze':
            update_option(
                'brikpanel_review_snooze_until',
                time() + ( BRIKPANEL_REVIEW_SNOOZE_DAYS * DAY_IN_SECONDS ),
                false
            );
            break;

        default:
            wp_send_json_error( [ 'message' => 'invalid_action' ], 400 );
    }

    wp_send_json_success();
}
