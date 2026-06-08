<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Seconds after which a visitor with no ping is considered inactive.
// Ping interval is 30s, so 75s = 2.5x tolerance.
if ( ! defined( 'BRIKPANEL_VISITOR_TIMEOUT' ) ) {
    define( 'BRIKPANEL_VISITOR_TIMEOUT', 75 );
}

// Hard cap on the number of visitors stored in the transient. Prevents the
// option row from ballooning under bot traffic on weak hosts. The dashboard
// only renders the active subset anyway.
if ( ! defined( 'BRIKPANEL_VISITOR_MAX' ) ) {
    define( 'BRIKPANEL_VISITOR_MAX', 500 );
}

// Minimum seconds between accepted pings from the same visitor. Drops
// duplicate / spammy pings cheaply before we touch the transient.
if ( ! defined( 'BRIKPANEL_VISITOR_PING_INTERVAL' ) ) {
    define( 'BRIKPANEL_VISITOR_PING_INTERVAL', 10 );
}

/**
 * Skip live tracking for obvious bots. Light user-agent sniff — not a
 * security boundary, just a load-shedding heuristic so search crawlers
 * don't fill the transient.
 */
function _brikpanel_is_bot_ua() {
    $ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';
    if ( $ua === '' ) {
        return true;
    }
    return (bool) preg_match( '/(bot|crawler|spider|crawling|facebookexternalhit|slurp|mediapartners|ahrefs|semrush|petalbot|bingpreview|yandex|baidu|duckduckbot|applebot)/i', $ua );
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
    // No nonce: this is a public endpoint reachable from any frontend visitor.
    // We defend against abuse with three cheap checks instead — bot UA filter,
    // per-visitor rate limit, and a hard cap on the transient size — so a
    // single host can't fill memory or hammer admin-ajax.

    if ( _brikpanel_is_bot_ua() ) {
        wp_send_json_success( 'Skipped' );
    }

    $visitor_id = _brikpanel_get_visitor_id();
    if ( ! $visitor_id ) {
        wp_send_json_success( 'Skipped' );
    }

    // Per-visitor rate limit. Use the object cache when available (in-memory,
    // O(1)). On hosts without one, wp_cache_* is per-request only — fall back
    // to a transient so the limit actually persists between requests.
    $rl_key = 'bp_lv_' . md5( $visitor_id );
    if ( function_exists( 'brikpanel_has_object_cache' ) && brikpanel_has_object_cache() ) {
        if ( wp_cache_get( $rl_key, 'brikpanel_live' ) ) {
            wp_send_json_success( 'Throttled' );
        }
        wp_cache_set( $rl_key, 1, 'brikpanel_live', BRIKPANEL_VISITOR_PING_INTERVAL );
    } else {
        if ( get_transient( $rl_key ) ) {
            wp_send_json_success( 'Throttled' );
        }
        set_transient( $rl_key, 1, BRIKPANEL_VISITOR_PING_INTERVAL );
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

    // Cleanup: drop stale entries first so the cap below preserves recent
    // visitors. If the transient still exceeds the cap (bot flood), keep only
    // the most-recently-active entries — bounded memory beats completeness.
    $limit_time = time() - BRIKPANEL_VISITOR_TIMEOUT;
    foreach ( $visitors as $vid => $data ) {
        if ( ! isset( $data['last_active'] ) || $data['last_active'] < $limit_time ) {
            unset( $visitors[ $vid ] );
        }
    }

    if ( count( $visitors ) > BRIKPANEL_VISITOR_MAX ) {
        uasort( $visitors, static function ( $a, $b ) {
            return ( $b['last_active'] ?? 0 ) <=> ( $a['last_active'] ?? 0 );
        } );
        $visitors = array_slice( $visitors, 0, BRIKPANEL_VISITOR_MAX, true );
    }

    set_transient( 'brikpanel_live_visitors', $visitors, 120 );

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
    // Skip the tracker for logged-in admins (matches _brikpanel_get_visitor_id)
    // and for obvious bots — saves the network round-trip entirely.
    if ( is_user_logged_in() && current_user_can( 'manage_options' ) ) return;
    if ( _brikpanel_is_bot_ua() ) return;
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