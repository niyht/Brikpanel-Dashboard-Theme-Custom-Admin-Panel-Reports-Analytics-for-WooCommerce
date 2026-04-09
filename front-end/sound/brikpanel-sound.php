<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 1. Sipariş "Processing" olduğunda ID'yi kaydet
 */
function brikpanel_notify_new_order( $order_id ) {
    update_option( 'brikpanel_last_new_order', $order_id );
}
add_action( 'woocommerce_order_status_processing', 'brikpanel_notify_new_order' );


/**
 * 2. AJAX Endpoint: Son sipariş ID'sini ve Fiyatını döndür
 */
function brikpanel_get_last_completed_order() {
    // Yetki kontrolü
    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        wp_send_json_error( 'Unauthorized' );
    }

    $last_order_id = get_option( 'brikpanel_last_new_order', 0 );
    $order_total   = '';

    // Sipariş nesnesini çek ve fiyatı al
    if ( $last_order_id > 0 ) {
        $order = wc_get_order( $last_order_id );
        if ( $order ) {
            // WooCommerce ayarlarındaki para birimi sembolüyle beraber fiyatı alır
            $order_total = $order->get_formatted_order_total(); 
        }
    }

    wp_send_json_success( array( 
        'last_order_id' => intval( $last_order_id ),
        'order_total'   => $order_total
    ) );
}
add_action( 'wp_ajax_brikpanel_get_last_completed_order', 'brikpanel_get_last_completed_order' );


/**
 * 3. Frontend Scriptleri (JS, CSS, HTML) - Admin Paneline Bas
 */
function brikpanel_print_notification_script() {
    // Sadece admin panelinde çalışsın
    if ( ! is_admin() ) return;

    $sound_url = plugin_dir_url( __FILE__ ) . 'brikpanel-sound.wav';
    $ajax_url  = admin_url( 'admin-ajax.php' );
    $nonce     = wp_create_nonce( 'brikpanel_sound_nonce' );

    // Bildirim metni (Çeviriye uygun)
    // %s yer tutucusu JS tarafında fiyat ile değiştirilecek.
    $notification_text = __( '🎉 Congratulations, you have a new order of %s.', 'brikpanel' );

    ?>
    <script src="https://cdn.jsdelivr.net/npm/canvas-confetti@1.6.0/dist/confetti.browser.min.js"></script>

    <style>
        #brikpanel-notification-toast {
            visibility: hidden;
            min-width: 300px;
            background-color: #333;
            color: #fff;
            text-align: center;
            border-radius: 8px;
            padding: 16px;
            position: fixed;
            z-index: 99999;
            right: 30px;
            top: 50px; /* Admin barın altında */
            box-shadow: 0 4px 12px rgba(0,0,0,0.3);
            font-size: 14px;
            border-left: 5px solid #4caf50; /* Yeşil şerit */
            opacity: 0;
            transition: opacity 0.5s, top 0.5s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }

        #brikpanel-notification-toast.show {
            visibility: visible;
            opacity: 1;
            top: 70px; /* Hafif aşağı kayma efekti */
        }
        
        /* Kapat butonu */
        .bp-close-toast {
            margin-left: 15px;
            cursor: pointer;
            font-weight: bold;
            color: #aaa;
        }
        .bp-close-toast:hover { color: #fff; }
    </style>

    <div id="brikpanel-notification-toast">
        <span id="bp-toast-message"></span>
        <span class="bp-close-toast" onclick="document.getElementById('brikpanel-notification-toast').classList.remove('show')">&times;</span>
    </div>

    <script type="text/javascript">
        document.addEventListener("DOMContentLoaded", function () {
            
            const bpConfig = {
                soundUrl: "<?php echo esc_url( $sound_url ); ?>",
                ajaxUrl:  "<?php echo esc_url( $ajax_url ); ?>",
                nonce:    "<?php echo esc_js( $nonce ); ?>",
                msgTemplate: "<?php echo esc_js( $notification_text ); ?>"
            };

            let lastCheckedOrder = null;
            let audio = new Audio(bpConfig.soundUrl);

            function checkForCompletedOrders() {
                const formData = new FormData();
                formData.append('action', 'brikpanel_get_last_completed_order');
                formData.append('security', bpConfig.nonce);

                fetch(bpConfig.ajaxUrl, {
                    method: "POST",
                    body: formData
                })
                .then(response => {
                    if (!response.ok) throw new Error("Network error");
                    return response.json();
                })
                .then(res => {
                    if (res.success) {
                        let newCompletedOrder = parseInt(res.data.last_order_id);
                        let orderTotal = res.data.order_total;

                        // İlk yükleme: Kaydet ve çık (ses/bildirim yok)
                        if (lastCheckedOrder === null) {
                            lastCheckedOrder = newCompletedOrder;
                            return;
                        }

                        // YENİ SİPARİŞ VARSA
                        if (newCompletedOrder !== lastCheckedOrder && newCompletedOrder > lastCheckedOrder) {
                            
                            // 1. Sesi Çal
                            playDingSound();

                            // 2. Confetti Patlat
                            triggerConfetti();

                            // 3. Bildirimi Göster
                            showNotification(orderTotal);

                            // ID'yi güncelle
                            lastCheckedOrder = newCompletedOrder;
                        }
                    }
                })
                .catch(() => { /* Hata varsa sessizce geç */ });
            }

            // Ses Çalma Fonksiyonu
            function playDingSound() {
                let playPromise = audio.play();
                if (playPromise !== undefined) {
                    playPromise.catch(() => {
                        // Otomatik oynatma engellendiyse yapacak bir şey yok
                    });
                }
            }

            // Confetti Fonksiyonu
            function triggerConfetti() {
                if (typeof confetti === 'function') {
                    // Ekranın ortasından konfeti fırlat
                    confetti({
                        particleCount: 150,
                        spread: 70,
                        origin: { y: 0.6 }
                    });
                }
            }

            // Bildirim Gösterme Fonksiyonu
            function showNotification(price) {
                const toast = document.getElementById("brikpanel-notification-toast");
                const msgSpan = document.getElementById("bp-toast-message");

                // Metindeki %s yerine gerçek fiyatı koy
                let message = bpConfig.msgTemplate.replace('%s', price);
                
                msgSpan.innerHTML = message;
                
                // Göster
                toast.classList.add("show");

                // 5 Saniye sonra otomatik gizle
                setTimeout(function(){ 
                    toast.classList.remove("show"); 
                }, 500000);
            }

            // Başlat
            checkForCompletedOrders();
            setInterval(checkForCompletedOrders, 30000); // 30 saniye
        });
    </script>
    <?php
}
add_action( 'admin_footer', 'brikpanel_print_notification_script' );