document.addEventListener('DOMContentLoaded', function() {
    let sendButton = document.getElementById('brik82adSendButton');
    let ajaxValue = document.getElementById('brik82adAjaxValue');
    let dateSelect = document.getElementById('brik82adDateSelect');
    let brik82adRadios = document.getElementById('brik82adRadioFilter');
    let selectDates = [];

    // Flatpickr Tarih Seçici
    flatpickr("#brik82adDateSelect", {
        mode: "range",
        dateFormat: "Y-m-d",
        onChange: function (dates) {
            if (dates.length === 2) {
                selectDates = dates.map(date => date.toISOString().split('T')[0]);
            }
        }
    });

    /**
     * Seçili filtreye göre AJAX isteği atıp sonucu `ajaxValue` elementine yazan fonksiyon.
     * @param {string} filterValue - ('today', 'yesterday', '7days', 'custom' vb.)
     */
    function fetchData(filterValue) {
        // Ekrana "Loading..." vb. göstermek isterseniz:
        ajaxValue.innerHTML = 'Loading...';

        // FormData oluştur
        let formData = new FormData();

        switch (filterValue) {
            case 'today':
                formData.append("action", "brik82ad_ajax_today");
                formData.append('security', brik82adData.nonce);
                break;
            case 'yesterday':
                formData.append("action", "brik82ad_ajax_yesterday");
                formData.append('security', brik82adData.nonce);
                break;
            case '7days':
                formData.append("action", "brik82ad_ajax_7days");
                formData.append('security', brik82adData.nonce);
                break;
            case '30days':
                formData.append("action", "brik82ad_ajax_30days");
                formData.append('security', brik82adData.nonce);
                break;
            case '90days':
                formData.append("action", "brik82ad_ajax_90days");
                formData.append('security', brik82adData.nonce);
                break;
            case '365days':
                formData.append("action", "brik82ad_ajax_365days");
                formData.append('security', brik82adData.nonce);
                break;
            case 'custom':
                // Custom ise date range seçilmiş mi?
                if (selectDates.length !== 2) {
                    ajaxValue.innerHTML = 'Please select a date range.';
                    return;
                }
                formData.append("action", "brik82ad_ajax_send");
                formData.append('start_date', selectDates[0]);
                formData.append('end_date', selectDates[1]);
                formData.append('security', brik82adData.nonce);
                break;
            default:
                ajaxValue.innerHTML = 'Invalid filter';
                return;
        }

        // AJAX isteği
        fetch(ajaxurl, {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                ajaxValue.innerHTML = data.data.total;
            } else {
                ajaxValue.innerHTML = 'Error';
            }
        })
        .catch(error => {
            console.error('Error:', error);
            ajaxValue.innerHTML = 'Error';
        });
    }

    // Sayfa ilk açıldığında varsayılan "today" verisini çekelim:
    fetchData('today');

    // Radyo değiştiğinde
    brik82adRadios.addEventListener('change', function() {
        let selectedRadio = document.querySelector('input[name="filter"]:checked');
        if (!selectedRadio) return;

        // Eğer custom seçiliyse tarih seçimi ve butonu açalım
        if (selectedRadio.value === 'custom') {
            dateSelect.style.display = '';
            sendButton.style.display = '';
        } else {
            // Diğerlerinde tarihi gizleyelim
            dateSelect.style.display = 'none';
            sendButton.style.display = 'none';
            // Seçili filtreyi hemen çekelim
            fetchData(selectedRadio.value);
        }
    });

    // Custom butonuna basıldığında
    sendButton.addEventListener('click', function() {
        let selectedRadio = document.querySelector('input[name="filter"]:checked');
        if (selectedRadio && selectedRadio.value === 'custom') {
            fetchData('custom');
        }
    });

    // --- Her 30 saniyede bir seçili filtreye göre otomatik güncelle ---
    setInterval(() => {
        // Hangisi seçiliyse al
        let selectedRadio = document.querySelector('input[name="filter"]:checked');
        if (!selectedRadio) return;

        if (selectedRadio.value === 'custom') {
            // Custom seçiliyse iki tarih var mı, varsa isteği yinele
            if (selectDates.length === 2) {
                fetchData('custom');
            }
        } else {
            // Diğer filtrelerde doğrudan fetch
            fetchData(selectedRadio.value);
        }
    }, 30000); // 30.000 ms = 30 saniye

});
