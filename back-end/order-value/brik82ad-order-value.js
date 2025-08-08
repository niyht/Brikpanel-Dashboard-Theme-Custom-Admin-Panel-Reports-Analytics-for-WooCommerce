document.addEventListener('DOMContentLoaded', function() {
    let sendButton = document.getElementById('brik82adSendButtonOrderValue');
    let ajaxValue = document.getElementById('brik82adAjaxOrderValue');
    let dateSelect = document.getElementById('brik82adDateSelectOrderValue');
    let brik82adRadios = document.getElementById('brik82adRadioFilterOrderValue');
    let selectDates = [];

    // Tarih Aralığı Seçici
    flatpickr("#brik82adDateSelectOrderValue", {
        mode: "range",
        dateFormat: "Y-m-d",
        onChange: function (dates) {
            if (dates.length === 2) {
                selectDates = dates.map(date => date.toISOString().split('T')[0]);
            }
        }
    });

    /**
     * Seçilen filtreye göre AJAX isteği atıp, sonucu #brik82adAjaxOrderValue span'ına basar.
     * @param {string} filterValue - today, yesterday, 7days, custom vs.
     */
    function fetchOrderValue(filterValue) {
        ajaxValue.innerHTML = 'Loading...'; // İsteğe bağlı olarak loading yazısı

        let formData = new FormData();

        switch (filterValue) {
            case 'today':
                formData.append("action", "brik82ad_ajax_today_order_value");
                formData.append('security', brik82adData.nonce);
                break;
            case 'yesterday':
                formData.append("action", "brik82ad_ajax_yesterday_order_value");
                formData.append('security', brik82adData.nonce);
                break;
            case '7days':
                formData.append("action", "brik82ad_ajax_7days_order_value");
                formData.append('security', brik82adData.nonce);
                break;
            case '30days':
                formData.append("action", "brik82ad_ajax_30days_order_value");
                formData.append('security', brik82adData.nonce);
                break;
            case '90days':
                formData.append("action", "brik82ad_ajax_90days_order_value");
                formData.append('security', brik82adData.nonce);
                break;
            case '365days':
                formData.append("action", "brik82ad_ajax_365days_order_value");
                formData.append('security', brik82adData.nonce);
                break;
            case 'custom':
                // Custom ise, selectDates dizisinin [0] ve [1] var mı bak
                if (selectDates.length !== 2) {
                    console.warn('Custom date range not properly selected.');
                    ajaxValue.innerHTML = 'No date selected';
                    return;
                }
                formData.append("action", "brik82ad_date_ajax_order_value");
                formData.append('security', brik82adData.nonce);
                formData.append('start_date', selectDates[0]);
                formData.append('end_date', selectDates[1]);
                break;
            default:
                // Geçersiz filtre -> hiç sorgu yapma veya hatayı göster
                console.warn('Invalid filter: ', filterValue);
                ajaxValue.innerHTML = 'Invalid filter';
                return;
        }

        // AJAX isteği
        fetch(brik82adData.ajax_url, {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                ajaxValue.innerHTML = data.data.total;
            } else {
                ajaxValue.innerHTML = 'error';
            }
        })
        .catch(error => {
            console.error('Error:', error);
            ajaxValue.innerHTML = 'error';
        });
    }

    // Sayfa ilk açıldığında varsayılan olarak "today" verisini çek
    fetchOrderValue('today');

    // Radyo değiştiğinde
    brik82adRadios.addEventListener('change', function() {
        let selectedRadio = document.querySelector('input[name="filterOrderValue"]:checked');
        if (!selectedRadio) return;

        // Eğer custom seçiliyse tarih alanını göster, butona tıklandığında custom sorgusu
        if (selectedRadio.value === 'custom') {
            dateSelect.style.display = '';
            sendButton.style.display = '';
        } else {
            // Diğer filtreler için tarih alanını gizle
            dateSelect.style.display = 'none';
            sendButton.style.display = 'none';
            // Seçili filtreyle hemen veri çek
            fetchOrderValue(selectedRadio.value);
        }
    });

    // Custom butonuna basıldığında
    sendButton.addEventListener('click', function() {
        let selectedRadio = document.querySelector('input[name="filterOrderValue"]:checked');
        if (selectedRadio && selectedRadio.value === 'custom') {
            fetchOrderValue('custom');
        }
    });

    // --- Her 30 saniyede bir seçili filtreye göre veriyi yenile
    setInterval(() => {
        let selectedRadio = document.querySelector('input[name="filterOrderValue"]:checked');
        if (!selectedRadio) return;

        // Custom seçiliyse tarih var mı kontrol et
        if (selectedRadio.value === 'custom') {
            if (selectDates.length === 2) {
                fetchOrderValue('custom');
            }
        } else {
            fetchOrderValue(selectedRadio.value);
        }
    }, 30000);

});
