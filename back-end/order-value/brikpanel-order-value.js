document.addEventListener('DOMContentLoaded', function() {
    let sendButton = document.getElementById('brikpanelSendButtonOrderValue');
    let ajaxValue = document.getElementById('brikpanelAjaxOrderValue');
    let dateSelect = document.getElementById('brikpanelDateSelectOrderValue');
    let brikpanelRadios = document.getElementById('brikpanelRadioFilterOrderValue');
    let selectDates = [];

    // --- Flatpickr Date Picker
    flatpickr("#brikpanelDateSelectOrderValue", {
      mode: "range",
      dateFormat: "Y-m-d",
      onChange: function(dates) {
        if (dates.length === 2) {
          selectDates = dates.map(date => date.toLocaleDateString('sv-SE')); // ISO formatlı yerel tarih
        }
      }
    });


    /**
     * Sends AJAX request according to selected filter and prints the result to #brikpanelAjaxOrderValue span.
     * @param {string} filterValue - today, yesterday, 7days, custom etc.
     */
    function fetchOrderValue(filterValue) {
        ajaxValue.innerHTML = 'Loading...'; // Optionally show loading text

        let formData = new FormData();

        switch (filterValue) {
            case 'today':
                formData.append("action", "brikpanel_ajax_today_order_value");
                break;
            case 'yesterday':
                formData.append("action", "brikpanel_ajax_yesterday_order_value");
                break;
            case '7days':
                formData.append("action", "brikpanel_ajax_7days_order_value");
                break;
            case '30days':
                formData.append("action", "brikpanel_ajax_30days_order_value");
                break;
            case '90days':
                formData.append("action", "brikpanel_ajax_90days_order_value");
                break;
            case '365days':
                formData.append("action", "brikpanel_ajax_365days_order_value");
                break;
            case 'custom':
                // If custom, check if selectDates[0] and [1] exist
                if (selectDates.length !== 2) {
                    ajaxValue.innerHTML = 'No date selected';
                    return;
                }
                formData.append("action", "brikpanel_date_ajax_order_value");
                formData.append('start_date', selectDates[0]);
                formData.append('end_date', selectDates[1]);
                formData.append("security", brikpanelAjax.nonce); // Nonce for security
                break;
            default:
                // Invalid filter -> do not query or show error
                ajaxValue.innerHTML = 'Invalid filter';
                return;
        }

        // AJAX request
        fetch(brikpanelAjax.ajax_url, {
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
            ajaxValue.innerHTML = 'error';
        });
    }

    // On first page load, fetch "today" data by default
    fetchOrderValue('today');

    // On radio change
    brikpanelRadios.addEventListener('change', function() {
        let selectedRadio = document.querySelector('input[name="filterOrderValue"]:checked');
        if (!selectedRadio) return;

        // If custom is selected, show date field, on button click do custom query
        if (selectedRadio.value === 'custom') {
            dateSelect.style.display = '';
            sendButton.style.display = '';
        } else {
            // Hide date field for other filters
            dateSelect.style.display = 'none';
            sendButton.style.display = 'none';
            // Immediately fetch data with selected filter
            fetchOrderValue(selectedRadio.value);
        }
    });

    // On custom button click
    sendButton.addEventListener('click', function() {
        let selectedRadio = document.querySelector('input[name="filterOrderValue"]:checked');
        if (selectedRadio && selectedRadio.value === 'custom') {
            fetchOrderValue('custom');
        }
    });

    // --- Refresh data every 30 seconds according to selected filter
    // Pause polling when tab is hidden to save resources.
    let pollInterval = null;

    function pollTick() {
        let selectedRadio = document.querySelector('input[name="filterOrderValue"]:checked');
        if (!selectedRadio) return;
        if (selectedRadio.value === 'custom') {
            if (selectDates.length === 2) fetchOrderValue('custom');
        } else {
            fetchOrderValue(selectedRadio.value);
        }
    }

    function startPolling() { if (!pollInterval) pollInterval = setInterval(pollTick, 30000); }
    function stopPolling()  { if (pollInterval) { clearInterval(pollInterval); pollInterval = null; } }

    document.addEventListener('visibilitychange', function () {
        if (document.hidden) { stopPolling(); } else { pollTick(); startPolling(); }
    });
    startPolling();

});
