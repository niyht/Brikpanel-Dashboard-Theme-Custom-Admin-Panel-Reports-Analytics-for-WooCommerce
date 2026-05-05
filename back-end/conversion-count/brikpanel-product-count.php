<?php
if( ! defined( 'ABSPATH' ) ) exit;

// Ürün sayfası ziyaretini veritabanına kaydeden AJAX fonksiyonu
function brikpanel_product_view() {
    if ( brikpanel_is_admin_user() ) {
        wp_send_json_success();
    }
    if ( function_exists( '_brikpanel_is_bot_ua' ) && _brikpanel_is_bot_ua() ) {
        wp_send_json_success();
    }

    if ( ! isset( $_POST['security'] ) || ! wp_verify_nonce( sanitize_key( $_POST['security'] ), 'brikpanel_nonce_action' ) ) {
        wp_send_json_error( [ 'message' => 'Invalid nonce.' ] );
    }

    if ( empty( $_POST['is_product'] ) || $_POST['is_product'] !== '1' ) {
        wp_send_json_error();
    }

    global $wpdb;
    $table = $wpdb->prefix . 'brikpanel_visitors';
    $today = wp_date( 'Y-m-d' );

    $updated = $wpdb->query( $wpdb->prepare(
        "UPDATE {$table} SET product_count = product_count + 1 WHERE date_column = %s",
        $today
    ) );

    if ( ! $updated ) {
        $wpdb->insert(
            $table,
            [ 'date_column' => $today, 'product_count' => 1 ],
            [ '%s', '%d' ]
        );
    }

    wp_send_json_success();
}
add_action( 'wp_ajax_nopriv_brikpanel_product_view', 'brikpanel_product_view' );
add_action( 'wp_ajax_brikpanel_product_view', 'brikpanel_product_view' );


// Ürün sayfasına JS kodunu ekleyen fonksiyon
function brikpanel_product_view_script() {
    if ( ! is_singular( 'product' ) || brikpanel_is_admin_user() ) {
        return;
    }
    if ( function_exists( '_brikpanel_is_bot_ua' ) && _brikpanel_is_bot_ua() ) {
        return;
    }

    // --- DÜZELTME: gmdate() yerine wp_date() kullanıyoruz ---
    // Bu, localStorage anahtarının sitenin saat dilimine göre doğru gün için üretilmesini sağlar.
    $key   = 'brikpanel_product_viewed_' . wp_date( 'Y-m-d' );
    $ajax  = esc_url( admin_url( 'admin-ajax.php' ) );
    $nonce = wp_create_nonce('brikpanel_nonce_action');

    ?>
    <script>
    (function() {
        const KEY = '<?php echo esc_js($key); ?>';
        if (localStorage.getItem(KEY)) return;

        const fd = new FormData();
        fd.append('action', 'brikpanel_product_view');
        fd.append('is_product', '1');
        fd.append('security', '<?php echo esc_js($nonce); ?>');

        fetch('<?php echo esc_url_raw($ajax); ?>', {
            method: 'POST',
            credentials: 'same-origin',
            body: fd
        }).then(() => {
            localStorage.setItem(KEY, '1');
        });
    })();
    </script>
    <?php
}
add_action( 'wp_footer', 'brikpanel_product_view_script', 20 );


/**
 * ANA YARDIMCI FONKSİYON
 * Belirtilen tarih aralığındaki toplam ürün görüntülenme sayısını hesaplar.
 *
 * @param string|null $start_date Başlangıç tarihi (Y-m-d formatında).
 * @param string|null $end_date Bitiş tarihi (Y-m-d formatında).
 * @return int Toplam ürün görüntülenme sayısı.
 */
function brikpanel_get_product_view_count( $start_date = null, $end_date = null ) {
    global $wpdb;
    $table_name = $wpdb->prefix . "brikpanel_visitors";

    // SQL sorgusunu ve argümanları dinamik olarak oluşturalım
    $query = "SELECT SUM(product_count) FROM {$table_name} WHERE 1=1";
    $query_args = array();

    if ( $start_date && $end_date ) {
        $query .= " AND date_column BETWEEN %s AND %s";
        $query_args[] = $start_date;
        $query_args[] = $end_date;
    } elseif ( $start_date ) {
        $query .= " AND date_column = %s";
        $query_args[] = $start_date;
    }

    // Eğer argüman varsa, sorguyu hazırla
    if ( ! empty( $query_args ) ) {
        $total_views = $wpdb->get_var( $wpdb->prepare( $query, $query_args ) );
    } else {
        // Eğer tarih aralığı yoksa (tüm zamanlar), basit sorgu çalıştır
        $total_views = $wpdb->get_var( $query );
    }

    return is_null($total_views) ? 0 : (int) $total_views;
}
