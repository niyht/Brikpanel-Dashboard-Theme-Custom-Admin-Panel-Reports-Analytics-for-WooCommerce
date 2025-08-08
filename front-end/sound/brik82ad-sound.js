document.addEventListener("DOMContentLoaded", function () {
    let lastCheckedOrder = null;

    function checkForCompletedOrders() {
        fetch(ajaxurl, {
            method: "POST",
            headers: { "Content-Type": "application/x-www-form-urlencoded" },
            body: new URLSearchParams({ action: "brik82ad_get_last_completed_order" })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                let newCompletedOrder = data.data.last_order_id;

                // Eğer lastCheckedOrder null ise (ilk yükleme) sadece güncelleyip sesi çalma
                if (lastCheckedOrder === null) {
                    lastCheckedOrder = newCompletedOrder;
                    return;
                }

                // Eğer yeni sipariş ID'si öncekiyle aynı değilse sesi çalıştır
                if (newCompletedOrder !== lastCheckedOrder) {
                    playDingSound();
                    lastCheckedOrder = newCompletedOrder;
                }
            }
        })
        .catch(error => console.error("Hata:", error));
    }

    function playDingSound() {
        let audio = new Audio(location.origin + "/wp-content/plugins/brik82ad/front-end/sound/brik82ad-sound.wav");
        audio.play();
    }

    setInterval(checkForCompletedOrders, 30000); // 30 saniyede bir kontrol et
});
