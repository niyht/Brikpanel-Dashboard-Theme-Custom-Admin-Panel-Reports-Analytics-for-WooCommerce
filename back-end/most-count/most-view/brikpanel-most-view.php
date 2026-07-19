<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Sayfa görüntülenmesini veritabanına kaydeder (kayıt çekirdeği).
 * Not: Veriler Yerel Zaman (Local Time) olarak kaydediliyor.
 *
 * Shared by the unified tracker endpoint and the legacy standalone AJAX
 * action below. Callers apply the master-switch / admin / bot guards.
 *
 * @param int $page_id Post ID of the viewed page.
 */
function brikpanel_record_page_view( $page_id ) {
    global $wpdb;

    $page_id = (int) $page_id;
    if ( $page_id <= 0 ) {
        return;
    }
    $table_name = $wpdb->prefix . 'brikpanel_visited_pages';
    $today      = wp_date( 'Y-m-d' );
    $day_start  = $today . ' 00:00:00';
    $day_end    = $today . ' 23:59:59';

    // BETWEEN on the indexed datetime column engages idx_page_date instead of
    // forcing a full scan as LIKE would have done.
    $updated = $wpdb->query( $wpdb->prepare(
        "UPDATE {$table_name}
            SET visit_count = visit_count + 1,
                date_column = %s
          WHERE page_id = %d
            AND date_column BETWEEN %s AND %s
          ORDER BY date_column DESC
          LIMIT 1",
        current_time( 'mysql' ),
        $page_id,
        $day_start,
        $day_end
    ) );

    if ( ! $updated ) {
        $wpdb->insert(
            $table_name,
            [
                'page_id'     => $page_id,
                'visit_count' => 1,
                'date_column' => current_time( 'mysql' ),
            ],
            [ '%d', '%d', '%s' ]
        );
    }
}

/**
 * Legacy standalone AJAX action (pre-3.2.20 tracker JS). New pages ship the
 * unified tracker; this stays registered for cached pages still carrying the
 * old inline JS.
 */
function brikpanel_track_page_view() {
    // Master tracking switch — also guards pings from cached pages that still
    // carry the old tracker JS after the merchant turned tracking off.
    if ( function_exists( 'brikpanel_frontend_tracking_enabled' ) && ! brikpanel_frontend_tracking_enabled() ) {
        wp_send_json_success();
    }
    if ( brikpanel_is_admin_user() ) {
        wp_send_json_success();
    }
    if ( function_exists( '_brikpanel_is_bot_ua' ) && _brikpanel_is_bot_ua() ) {
        wp_send_json_success();
    }

    if ( ! isset( $_POST['security'] ) || ! wp_verify_nonce( sanitize_key( $_POST['security'] ), 'brikpanel_nonce_action' ) ) {
        wp_send_json_error( [ 'message' => 'Invalid nonce.' ] );
    }
    if ( ! isset( $_POST['page_id'] ) ) {
        wp_send_json_error( 'Page ID missing' );
    }

    brikpanel_record_page_view( intval( $_POST['page_id'] ) );

    wp_send_json_success( [ 'count' => 1 ] );
}
add_action('wp_ajax_brikpanel_track_page_view', 'brikpanel_track_page_view');
add_action('wp_ajax_nopriv_brikpanel_track_page_view', 'brikpanel_track_page_view');

// The inline page-view tracker JS moved into the unified tracker
// (back-end/tracking/brikpanel-unified-tracker.php) in 3.2.20.