<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Sayfa görüntülenmesini AJAX ile takip eder ve veritabanına kaydeder.
 * Not: Veriler Yerel Zaman (Local Time) olarak kaydediliyor.
 */
function brikpanel_track_page_view() {
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

    global $wpdb;

    $page_id    = intval( $_POST['page_id'] );
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

    wp_send_json_success( [ 'count' => 1 ] );
}
add_action('wp_ajax_brikpanel_track_page_view', 'brikpanel_track_page_view');
add_action('wp_ajax_nopriv_brikpanel_track_page_view', 'brikpanel_track_page_view');

/**
 * Sayfa görüntülenmesini takip eden JS'i siteye ekler.
 */
function brikpanel_track_page_view_js() {
    if (is_admin() || brikpanel_is_admin_user()) return;
    if ( function_exists( '_brikpanel_is_bot_ua' ) && _brikpanel_is_bot_ua() ) return;
    ?>
    <script>
    document.addEventListener("DOMContentLoaded", function () {
        let pageId = <?php echo intval(get_the_ID()); ?>;
        if (!pageId) return;
        let data = new FormData();
        data.append("action", "brikpanel_track_page_view");
        data.append("page_id", pageId);
        data.append("security", "<?php echo esc_js(wp_create_nonce('brikpanel_nonce_action')); ?>");
        fetch("<?php echo esc_url_raw(admin_url('admin-ajax.php')); ?>", {
            method: "POST",
            body: data,
            credentials: "same-origin"
        }).catch(() => {});
    });
    </script>
    <?php
}
add_action('wp_footer', 'brikpanel_track_page_view_js');