document.addEventListener('DOMContentLoaded', function() {
    const _i18n = (window.brikpanelOrderRates && window.brikpanelOrderRates.i18n) || {};
    const i18nOR = {
        successful:      _i18n.successful      || 'Successful',
        failed:          _i18n.failed          || 'Failed',
        refunded:        _i18n.refunded        || 'Refunded',
        cancelled:       _i18n.cancelled       || 'Cancelled',
        order_statuses:  _i18n.order_statuses  || 'Order Statuses',
        of_total_orders: _i18n.of_total_orders || '% of total orders',
    };
    let sendButton = document.getElementById('brikpanelSendButtonOrderRates');
    let dateSelect = document.getElementById('brikpanelDateSelectOrderRates');
    let brikpanelRadios = document.getElementById('brikpanelRadioFilterOrderRates');
    let selectDates = [];
    let brikpanelOrderRatesChart; 

    // --- Flatpickr Date Picker
    flatpickr("#brikpanelDateSelectOrderRates", {
        mode: "range",
        dateFormat: "Y-m-d",
        onChange: function(dates) {
            if (dates.length === 2) {
                selectDates = dates.map(date => date.toLocaleDateString('sv-SE'));
            }
        }
    });

    // Grafik oluşturma fonksiyonu
    function initializeChart() {
        const ctx = document.getElementById('brikpanelOrderRatesChart').getContext('2d');
        if (!ctx) return;

        // Eğer daha önceden bir grafik varsa, sonsuz döngüyü önlemek için onu yok et
        if (brikpanelOrderRatesChart) {
            brikpanelOrderRatesChart.destroy();
        }

        brikpanelOrderRatesChart = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: [i18nOR.successful, i18nOR.failed, i18nOR.refunded, i18nOR.cancelled],
                datasets: [{
                    label: i18nOR.order_statuses,
                    data: [],
                    backgroundColor: [
                        '#389b48',
                        '#DA6C6C',
                        '#FFF9BD',
                        '#B7B1F2'
                    ],
                    borderColor: [
                        '#000',
                        '#000',
                        '#000',
                        '#000'
                    ],
                    borderWidth: 1
                }]
            },
            options: {
                // Bu ayarlar, canvas'ın etrafındaki div'e göre boyutlanmasını sağlar
                responsive: true,
                maintainAspectRatio: false, 
                scales: {
                    y: {
                        beginAtZero: true,
                        max: 100, // Yüzde olduğu için max 100
                        ticks: {
                            precision: 0,
                            callback: function(value) {
                                return value + '%';
                            }
                        }
                    }
                },
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return context.parsed.y + i18nOR.of_total_orders;
                            }
                        }
                    }
                }
            }
        });
    }

    // Grafik verilerini güncelleme fonksiyonu
    function updateChartData(apiData) {
        if (!brikpanelOrderRatesChart) return;

        // PHP'den gelen "25% successful orders" formatından sayıyı çıkar
        const parseValue = (text) => {
            const match = String(text).match(/(\d+\.?\d*)%/);
            return match ? parseFloat(match[1]) : 0;
        };

        const newData = [
            parseValue(apiData.successful),
            parseValue(apiData.failed),
            parseValue(apiData.refunded),
            parseValue(apiData.cancelled)
        ];

        brikpanelOrderRatesChart.data.datasets[0].data = newData;
        brikpanelOrderRatesChart.update();
    }
    
    // Grafik verilerini sıfırlama fonksiyonu
    function resetChartData() {
        if (!brikpanelOrderRatesChart) return;
        brikpanelOrderRatesChart.data.datasets[0].data = [];
        brikpanelOrderRatesChart.update();
    }

    // AJAX fonksiyonu - PHP'deki action'larla uyumlu
    function fetchOrderRates(filterValue) {
        let formData = new FormData();
        formData.append("security", brikpanelAjax.nonce); // Her durumda nonce ekle
        
        // PHP'deki action isimlerine uygun olarak ayarla
        switch (filterValue) {
            case 'today': 
                formData.append("action", "brikpanel_ajax_today_order_rates"); 
                break;
            case 'yesterday': 
                formData.append("action", "brikpanel_ajax_yesterday_order_rates"); 
                break;
            case '7days': 
                formData.append("action", "brikpanel_ajax_7days_order_rates"); 
                break;
            case '30days': 
                formData.append("action", "brikpanel_ajax_30days_order_rates"); 
                break;
            case '90days': 
                formData.append("action", "brikpanel_ajax_90days_order_rates"); 
                break;
            case '365days': 
                formData.append("action", "brikpanel_ajax_365days_order_rates"); 
                break;
            case 'custom':
                if (selectDates.length !== 2) {
                    resetChartData(); 
                    return;
                }
                formData.append("action", "brikpanel_ajax_send_order_rates");
                formData.append('start_date', selectDates[0]);
                formData.append('end_date', selectDates[1]);
                break;
            default:
                resetChartData();
                return;
        }

        fetch(brikpanelAjax.ajax_url, {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                if (data.data.message) {
                    resetChartData();
                } else {
                    updateChartData(data.data);
                }
            } else {
                resetChartData();
                console.error('API Error:', data.data ? data.data.message : 'Unknown error');
            }
        })
        .catch(error => {
            resetChartData();
            console.error('Error fetching data:', error);
        });
    }

    // --- Olay Dinleyiciler ---
    initializeChart();
    fetchOrderRates('today');

    if (brikpanelRadios) {
        brikpanelRadios.addEventListener('change', function() {
            let selectedRadio = document.querySelector('input[name="filterOrderRates"]:checked');
            if (!selectedRadio) return;

            if (selectedRadio.value === 'custom') {
                dateSelect.style.display = 'block';
                sendButton.style.display = 'block';
                resetChartData();
            } else {
                dateSelect.style.display = 'none';
                sendButton.style.display = 'none';
                fetchOrderRates(selectedRadio.value);
            }
        });
    }

    if (sendButton) {
        sendButton.addEventListener('click', function() {
            fetchOrderRates('custom');
        });
    }

    // Auto refresh every 30 seconds
    // Pause polling when tab is hidden to save resources.
    let pollInterval = null;

    function pollTick() {
        let selectedRadio = document.querySelector('input[name="filterOrderRates"]:checked');
        if (!selectedRadio || selectedRadio.value === 'custom') return;
        fetchOrderRates(selectedRadio.value);
    }

    function startPolling() { if (!pollInterval) pollInterval = setInterval(pollTick, 30000); }
    function stopPolling()  { if (pollInterval) { clearInterval(pollInterval); pollInterval = null; } }

    document.addEventListener('visibilitychange', function () {
        if (document.hidden) { stopPolling(); } else { pollTick(); startPolling(); }
    });
    startPolling();
});