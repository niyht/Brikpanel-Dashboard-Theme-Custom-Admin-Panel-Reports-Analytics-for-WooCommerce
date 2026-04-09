<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Sayfa görüntülenmesini AJAX ile takip eder ve veritabanına kaydeder.
 * Not: Veriler Yerel Zaman (Local Time) olarak kaydediliyor.
 */
function brikpanel_track_page_view() {
    // Skip tracking for admin users.
    if ( brikpanel_is_admin_user() ) {
        wp_send_json_success();
    }

    if (!isset($_POST['security']) || !wp_verify_nonce(sanitize_key($_POST['security']), 'brikpanel_nonce_action')) {
        wp_send_json_error(['message' => 'Invalid nonce.']);
    }
    
    if (!isset($_POST['page_id'])) {
        wp_send_json_error('Page ID missing');
    }

    global $wpdb;

    $page_id = intval($_POST['page_id']);
    $table_name = $wpdb->prefix . 'brikpanel_visited_pages';
    
    // Bugünün tarihi (Yerel Zaman)
    $current_date_str = wp_date('Y-m-d');

    // PERFORMANS İYİLEŞTİRMESİ:
    // DATE(date_column) kullanmak yerine LIKE kullanıyoruz.
    // Bu sayede MySQL indeksleri daha verimli kullanabilir.
    $existing = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$table_name} WHERE page_id = %d AND date_column LIKE %s",
        $page_id,
        $current_date_str . '%' // '2023-10-25%'
    ));

    $visit_count = 0;

    if ($existing) {
        $wpdb->update(
            $table_name,
            [
                'visit_count' => $existing->visit_count + 1, 
                // Güncelleme saati (Yerel)
                'date_column' => current_time('mysql') 
            ],
            ['id' => $existing->id],
            ['%d', '%s'],
            ['%d']
        );
        $visit_count = $existing->visit_count + 1;
    } else {
        $wpdb->insert(
            $table_name,
            [
                'page_id'     => $page_id, 
                'visit_count' => 1, 
                // Oluşturma saati (Yerel)
                'date_column' => current_time('mysql')
            ],
            ['%d', '%d', '%s']
        );
        $visit_count = 1;
    }

    wp_send_json_success(['count' => $visit_count]);
}
add_action('wp_ajax_brikpanel_track_page_view', 'brikpanel_track_page_view');
add_action('wp_ajax_nopriv_brikpanel_track_page_view', 'brikpanel_track_page_view');

/**
 * Sayfa görüntülenmesini takip eden JS'i siteye ekler.
 */
function brikpanel_track_page_view_js() {
    if (is_admin() || brikpanel_is_admin_user()) return;
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