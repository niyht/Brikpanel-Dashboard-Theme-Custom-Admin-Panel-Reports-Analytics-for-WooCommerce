document.addEventListener('DOMContentLoaded', function() {
    let sendButton = document.getElementById('brikpanelSendButton');
    let ajaxValue = document.getElementById('brikpanelAjaxValue');
    let dateSelect = document.getElementById('brikpanelDateSelect');
    let brikpanelRadios = document.getElementById('brikpanelRadioFilter');
    let selectDates = [];

    // --- Flatpickr Date Picker
    flatpickr("#brikpanelDateSelect", {
      mode: "range",
      dateFormat: "Y-m-d",
      onChange: function(dates) {
        if (dates.length === 2) {
          selectDates = dates.map(date => date.toLocaleDateString('sv-SE')); // ISO formatlı yerel tarih
        }
      }
    });


    /**
     * Sends AJAX request according to selected filter and writes the result to `ajaxValue` element.
     * @param {string} filterValue - ('today', 'yesterday', '7days', 'custom', etc.)
     */
    function fetchData(filterValue) {
        // If you want to show "Loading..." etc. on the screen:
        ajaxValue.innerHTML = 'Loading...';

        // Create FormData
        let formData = new FormData();

        switch (filterValue) {
            case 'today':
                formData.append("action", "brikpanel_ajax_today");
                break;
            case 'yesterday':
                formData.append("action", "brikpanel_ajax_yesterday");
                break;
            case '7days':
                formData.append("action", "brikpanel_ajax_7days");
                break;
            case '30days':
                formData.append("action", "brikpanel_ajax_30days");
                break;
            case '90days':
                formData.append("action", "brikpanel_ajax_90days");
                break;
            case '365days':
                formData.append("action", "brikpanel_ajax_365days");
                break;
            case 'custom':
                // If custom, is date range selected?
                if (selectDates.length !== 2) {
                    ajaxValue.innerHTML = 'Please select a date range.';
                    return;
                }
                formData.append("action", "brikpanel_ajax_send");
                formData.append('start_date', selectDates[0]);
                formData.append('end_date', selectDates[1]);
                formData.append("security", brikpanelAjax.nonce); // Nonce for security

                break;
            default:
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
                ajaxValue.innerHTML = 'Error';
            }
        })
        .catch(error => {
            ajaxValue.innerHTML = 'Error';
        });
    }

    // On first page load, fetch default "today" data:
    fetchData('today');

    // On radio change
    brikpanelRadios.addEventListener('change', function() {
        let selectedRadio = document.querySelector('input[name="filter"]:checked');
        if (!selectedRadio) return;

        // If custom is selected, show date selection and button
        if (selectedRadio.value === 'custom') {
            dateSelect.style.display = '';
            sendButton.style.display = '';
        } else {
            // Hide date for others
            dateSelect.style.display = 'none';
            sendButton.style.display = 'none';
            // Immediately fetch selected filter
            fetchData(selectedRadio.value);
        }
    });

    // On custom button click
    sendButton.addEventListener('click', function() {
        let selectedRadio = document.querySelector('input[name="filter"]:checked');
        if (selectedRadio && selectedRadio.value === 'custom') {
            fetchData('custom');
        }
    });

    // --- Automatically update according to selected filter every 30 seconds ---
    // Pause polling when tab is hidden to save resources.
    let pollInterval = null;

    function pollTick() {
        let selectedRadio = document.querySelector('input[name="filter"]:checked');
        if (!selectedRadio) return;

        if (selectedRadio.value === 'custom') {
            if (selectDates.length === 2) {
                fetchData('custom');
            }
        } else {
            fetchData(selectedRadio.value);
        }
    }

    function startPolling() {
        if (!pollInterval) {
            pollInterval = setInterval(pollTick, 30000);
        }
    }

    function stopPolling() {
        if (pollInterval) {
            clearInterval(pollInterval);
            pollInterval = null;
        }
    }

    document.addEventListener('visibilitychange', function () {
        if (document.hidden) {
            stopPolling();
        } else {
            pollTick();
            startPolling();
        }
    });

    startPolling();

});
