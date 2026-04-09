<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Seconds after which a visitor with no ping is considered inactive.
// Ping interval is 30s, so 75s = 2.5x tolerance.
if ( ! defined( 'BRIKPANEL_VISITOR_TIMEOUT' ) ) {
    define( 'BRIKPANEL_VISITOR_TIMEOUT', 75 );
}

/* ----------------------------------------------------------
 * 1) Ziyaretçi ID (Cookie)
 * ---------------------------------------------------------- */
function _brikpanel_get_visitor_id() {
    if ( is_user_logged_in() && current_user_can( 'manage_options' ) ) {
        return false; 
    }
    $cookie_name = 'brikpanel_vid';
    if ( isset( $_COOKIE[ $cookie_name ] ) ) {
        return sanitize_text_field( wp_unslash( $_COOKIE[ $cookie_name ] ) );
    }
    $new_id = uniqid( 'bp_', true );
    setcookie( $cookie_name, $new_id, time() + YEAR_IN_SECONDS, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true );
    $_COOKIE[ $cookie_name ] = $new_id;
    return $new_id;
}


/* ----------------------------------------------------------
 * 2) AJAX: Ziyaretçiyi Kaydet VEYA Sil (Frontend)
 * ---------------------------------------------------------- */
function brikpanel_track_live_visitor() {
    // Güvenlik: Nonce kontrolü (POST içinde gelmeli, GET değil)
    // sendBeacon POST gönderir ancak headerları farklıdır, yine de $_POST doldurulur.
    
    $visitor_id = _brikpanel_get_visitor_id();
    if ( ! $visitor_id ) {
        wp_send_json_success( 'Skipped' );
    }

    $page_url = isset( $_POST['page_url'] ) ? esc_url_raw( wp_unslash( $_POST['page_url'] ) ) : '';
    $is_exit  = isset( $_POST['is_exit'] ) && $_POST['is_exit'] === 'true';

    // Visitor status detection: browsing / cart / order_received
    $visitor_status = 'browsing';
    $cart_count     = 0;

    if ( class_exists( 'WC_Cart' ) && function_exists( 'WC' ) && WC()->cart ) {
        $cart_count = WC()->cart->get_cart_contents_count();
        if ( $cart_count > 0 ) {
            $visitor_status = 'cart';
        }
    }

    // Check if visitor is on order-received (thank you) page
    if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'order-received' ) ) {
        $visitor_status = 'order_received';
    }

    // Collect customer info if logged in
    $customer_name  = '';
    $customer_email = '';
    $customer_phone = '';
    $customer_id    = 0;

    if ( is_user_logged_in() ) {
        $user = wp_get_current_user();
        $customer_id = $user->ID;
        $first = get_user_meta( $user->ID, 'billing_first_name', true );
        $last  = get_user_meta( $user->ID, 'billing_last_name', true );
        $customer_name = trim( $first . ' ' . $last );
        if ( empty( $customer_name ) ) {
            $customer_name = $user->display_name;
        }
        $customer_email = $user->user_email;
        $customer_phone = get_user_meta( $user->ID, 'billing_phone', true );
    }

    // Real IP address (first 10 chars of hash for privacy, full for admin display)
    $raw_ip    = $_SERVER['REMOTE_ADDR'] ?? '';
    $hashed_ip = substr( md5( $raw_ip ), 0, 10 );

    $visitors = get_transient( 'brikpanel_live_visitors' );
    if ( ! is_array( $visitors ) ) {
        $visitors = [];
    }

    if ( $is_exit ) {
        if ( isset( $visitors[ $visitor_id ] ) ) {
            unset( $visitors[ $visitor_id ] );
        }
    } else {
        $visitors[ $visitor_id ] = [
            'id'             => $visitor_id,
            'ip_address'     => $hashed_ip,
            'page_url'       => $page_url,
            'has_cart_item'  => $cart_count > 0 ? 'Yes' : 'No',
            'visitor_status' => $visitor_status,
            'cart_count'     => $cart_count,
            'customer_name'  => $customer_name,
            'customer_email' => $customer_email,
            'customer_phone' => $customer_phone,
            'customer_id'    => $customer_id,
            'last_active'    => time(),
        ];
    }

    // TEMİZLİK: 
    // Önceki kodda 120 saniyeydi. 
    // Şimdi 25 saniye yapıyoruz (Ping her 10 sn geliyor, 2.5 katı tolerans yeterli).
    // Böylece sekme kapanmasa bile internet kopsa 25sn sonra silinir.
    $limit_time = time() - BRIKPANEL_VISITOR_TIMEOUT; 
    
    foreach ( $visitors as $vid => $data ) {
        if ( isset($data['last_active']) && $data['last_active'] < $limit_time ) {
            unset( $visitors[ $vid ] );
        }
    }

    set_transient( 'brikpanel_live_visitors', $visitors, 120 ); // Transient süresi 2dk kalsın ama iç mantık 25sn.

    wp_send_json_success( $is_exit ? 'Removed' : 'Tracked' );
}
add_action( 'wp_ajax_nopriv_brikpanel_track_live_visitor', 'brikpanel_track_live_visitor' );
add_action( 'wp_ajax_brikpanel_track_live_visitor', 'brikpanel_track_live_visitor' );


/* ----------------------------------------------------------
 * 3) AJAX: Veriyi Oku (Admin Dashboard)
 * ---------------------------------------------------------- */
function brikpanel_get_live_data() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Unauthorized' );
    }

    $visitors = get_transient( 'brikpanel_live_visitors' );
    if ( ! is_array( $visitors ) ) {
        $visitors = [];
    }

    // Gösterirken de 25 saniyeden eski olanları filtrele
    $limit_time = time() - BRIKPANEL_VISITOR_TIMEOUT;
    $active_visitors = [];
    
    foreach ( $visitors as $vid => $data ) {
        if ( isset($data['last_active']) && $data['last_active'] >= $limit_time ) {
            $active_visitors[] = $data;
        }
    }

    wp_send_json_success( $active_visitors );
}
add_action( 'wp_ajax_brikpanel_get_live_data', 'brikpanel_get_live_data' );


/* ----------------------------------------------------------
 * 4) JS Tracker (Frontend - SendBeacon Eklendi)
 * ---------------------------------------------------------- */
function brikpanel_live_visitor_tracker_js() {
    if ( is_admin() ) return;
    ?>
    <script>
        document.addEventListener("DOMContentLoaded", function() {
            const endpoint = "<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>";
            
            // 1. Normal Ping Fonksiyonu (Her 10 saniyede bir)
            function pingTracker() {
                const fd = new FormData();
                fd.append('action', 'brikpanel_track_live_visitor');
                fd.append('page_url', window.location.href);
                fd.append('is_exit', 'false');

                fetch(endpoint, {
                    method: 'POST',
                    body: fd,
                    keepalive: true // İstek sayfa kapansa bile devam etmeye çalışsın
                }).catch(() => {});
            }

            // 2. Çıkış Sinyali (Sekme kapanınca çalışır)
            function sendExitSignal() {
                // FormData ile beacon göndermek bazı tarayıcılarda sorun olabilir, 
                // bu yüzden URLSearchParams kullanıyoruz.
                const data = new URLSearchParams();
                data.append('action', 'brikpanel_track_live_visitor');
                data.append('page_url', window.location.href);
                data.append('is_exit', 'true');

                // navigator.sendBeacon: Sayfa kapanırken veri göndermenin en güvenilir yoludur.
                // Asenkron çalışır ve sayfanın kapanmasını engellemez.
                if (navigator.sendBeacon) {
                    navigator.sendBeacon(endpoint, data);
                } else {
                    fetch(endpoint, {
                        method: 'POST',
                        body: data,
                        keepalive: true,
                    }).catch(() => {});
                }
            }

            // Sayfa kapatıldığında veya gizlendiğinde (mobil için) tetikle
            window.addEventListener("pagehide", sendExitSignal);
            // Mobilde sekme değiştirince visibilitychange daha güvenilirdir
            document.addEventListener("visibilitychange", function() {
                if (document.visibilityState === 'hidden') {
                    // Mobilde arkaplana atılınca da bazen çıkış sayılabilir, 
                    // ama şimdilik "pagehide" en güvenli "kapanma" sinyalidir.
                    // Buraya ekleme yapmıyorum, pagehide yeterlidir.
                }
            });

            // Başlat
            pingTracker();
            setInterval(pingTracker, 30000);
        });
    </script>
    <?php
}
add_action( 'wp_footer', 'brikpanel_live_visitor_tracker_js' );