document.addEventListener('DOMContentLoaded', function () {
    const _i18n = (window.brikpanelMostView && window.brikpanelMostView.i18n) || {};
    const i18nMV = {
        label:       _i18n.label       || 'View Count',
        select_date: _i18n.select_date || 'Please select a valid date range.',
    };
    let sendButton = document.getElementById('brikpanelSendButtonMostView');
    let dateSelect = document.getElementById('brikpanelDateSelectMostView');
    let brikpanelRadios = document.getElementById('brikpanelMostViewFilter');
    let brikpanelMostViewChart;
    let selectDates = [];
  
    // --- Flatpickr Date Picker
    flatpickr("#brikpanelDateSelectMostView", {
      mode: "range",
      dateFormat: "Y-m-d",
      onChange: function(dates) {
        if (dates.length === 2) {
          selectDates = dates.map(date => date.toLocaleDateString('sv-SE')); // ISO formatlı yerel tarih
        }
      }
    });
  
    // Initialize chart
    function initializeChart() {
        const ctx = document.getElementById('brikpanelMostViewChart').getContext('2d');
        brikpanelMostViewChart = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: [],
                datasets: [{
                    label: i18nMV.label,
                    data: [],
                    borderColor: 'rgb(0, 0, 0)',
                    backgroundColor: '#389b48',
                    borderWidth: 1
                }]
            },
            options: {
                scales: {
                  x: {
                    ticks: {
                      display: false // Remove labels on X axis
                    },
                    grid: {
                      drawTicks: false, // Remove small ticks on X axis
                      drawBorder: false // Remove X axis line
                    }
                  },
                  y: {
                    beginAtZero: true
                  }
                },
                interaction: {
                  mode: 'index', // Show only the relevant bar info on hover
                  intersect: false
                },
                plugins: {
                  tooltip: {
                    enabled: true // Show tooltip on hover
                  },
                  legend: {
                    display: false // Remove 'Customers' label above the chart
                  }
                }
              },
            });
          }
          initializeChart();
        
    // 📊 Update chart
    function updateChartData(response) {
        if (brikpanelMostViewChart) {
            const products = response.data.total || [];
            const labels = products.map(product => product.page_name);
            const data = products.map(product => product.visit_count);
  
            brikpanelMostViewChart.data.labels = labels;
            brikpanelMostViewChart.data.datasets[0].data = data;
            brikpanelMostViewChart.update();
        }
    }
  
    // 🔄 Reset chart
    function resetChartData() {
        if (brikpanelMostViewChart) {
            brikpanelMostViewChart.data.labels = [];
            brikpanelMostViewChart.data.datasets[0].data = [];
            brikpanelMostViewChart.update();
        }
    }
  
    // 📨 AJAX data fetch function
    function fetchData(filter, startDate = '', endDate = '') {
        let formDataMostView = new FormData();
        formDataMostView.append("action", "brikpanel_ajax_most_view");
        formDataMostView.append("filter", filter);
        formDataMostView.append("security", brikpanelAjax.nonce); // Nonce for security

        if (filter === "custom") {
            if (!startDate || !endDate) {
                alert(i18nMV.select_date);
                return;
            }
            formDataMostView.append('start_date', startDate);
            formDataMostView.append('end_date', endDate);
        }
  
        fetch(brikpanelAjax.ajax_url, { method: 'POST', body: formDataMostView })
            .then(res => res.json())
            .then(response => {
                if (response.success) {
                    updateChartData(response);
                } else {
                    resetChartData();
                }
            })
            .catch(e => console.error('BrikPanel most-view error:', e));
    }
  
    // 📅 When date filter changes
    brikpanelRadios.addEventListener('change', function () {
        let selectedFilter = document.querySelector('input[name="filterMostView"]:checked').value;
  
        if (selectedFilter === "custom") {
            dateSelect.style.display = '';
            sendButton.style.display = '';
            sendButton.onclick = function () {
                fetchData("custom", selectDates[0], selectDates[1]);
            };
        } else {
            dateSelect.style.display = 'none';
            sendButton.style.display = 'none';
            fetchData(selectedFilter);
        }
    });
  
    // Fetch "today" data on page load
    fetchData("today");

    // After page load and other event definitions, add at the bottom:
    // Pause polling when tab is hidden to save resources.
    let pollInterval = null;

    function pollTick() {
        let el = document.querySelector('input[name="filterMostView"]:checked');
        if (!el) return;
        let selectedFilter = el.value;
        if (selectedFilter === "custom") {
          if (selectDates.length === 2) fetchData("custom", selectDates[0], selectDates[1]);
        } else {
          fetchData(selectedFilter);
        }
    }

    function startPolling() { if (!pollInterval) pollInterval = setInterval(pollTick, 30000); }
    function stopPolling()  { if (pollInterval) { clearInterval(pollInterval); pollInterval = null; } }

    document.addEventListener('visibilitychange', function () {
        if (document.hidden) { stopPolling(); } else { pollTick(); startPolling(); }
    });
    startPolling();

});
