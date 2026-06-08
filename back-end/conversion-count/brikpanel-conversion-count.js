document.addEventListener('DOMContentLoaded', function() {
  const _i18n = (window.brikpanelConversionCount && window.brikpanelConversionCount.i18n) || {};
  const i18nCC = {
    visitor:         _i18n.visitor         || 'Visitor',
    product:         _i18n.product         || 'Product',
    add_to_cart:     _i18n.add_to_cart     || 'Add to Cart',
    checkout:        _i18n.checkout        || 'Checkout',
    order:           _i18n.order           || 'Order',
    customers:       _i18n.customers       || 'Customers',
    calculating:     _i18n.calculating     || 'Calculating...',
    error:           _i18n.error           || 'Error',
    conversion_rate: _i18n.conversion_rate || 'Conversion Rate',
    select_date:     _i18n.select_date     || 'Please select a valid custom date range.',
  };
  let sendButton = document.getElementById('brikpanelSendButtonVisitorCount');
  let ajaxValueConversionRate = document.getElementById('brikpanelAjaxValueConversionRate');
  let ajaxValueCheckoutCount = document.getElementById('brikpanelAjaxValueCheckoutCount');
  let dateSelect = document.getElementById('brikpanelDateSelectConversionCount');
  let brikpanelRadios = document.getElementById('brikpanelConversionCountFilter');
  let brikpanelConversionChart;
  let selectDates = [];

  // --- Flatpickr Date Picker
  flatpickr("#brikpanelDateSelectConversionCount", {
    mode: "range",
    dateFormat: "Y-m-d",
    onChange: function(dates) {
      if (dates.length === 2) {
        selectDates = dates.map(date => date.toLocaleDateString('sv-SE')); // ISO formatlı yerel tarih
      }
    }
  });

  // --- Chart.js Initialization
  function initializeChart() {
    const ctx = document.getElementById('brikpanelConversionChart').getContext('2d');
    brikpanelConversionChart = new Chart(ctx, {
      type: 'bar',
      data: {
        labels: [i18nCC.visitor, i18nCC.product, i18nCC.add_to_cart, i18nCC.checkout, i18nCC.order],
        datasets: [{
          label: i18nCC.customers,
          data: [0, 0, 0, 0, 0],
          borderColor: 'rgb(0,0,0)',
          borderWidth: 1,
          backgroundColor: '#389b48'
        }]
      },
      options: {
          layout: {
            padding: {
              top: 20 // Buradaki değeri ihtiyaca göre artırabilirsin
            }
          },
        scales: {
          x: {
            ticks: { display: false },
            grid: { drawTicks: false, drawBorder: false }
          },
          y: { beginAtZero: true }
        },
        interaction: {
          mode: 'index',
          intersect: false
        },
        plugins: {
          tooltip: { enabled: true },
          legend: { display: false }
        }
      },
      plugins: [{
        // Print value on top of bars
        afterDatasetsDraw(chart) {
          const { ctx, data } = chart;
          ctx.font = 'bold 14px Arial';
          ctx.textAlign = 'center';
          ctx.textBaseline = 'middle';
          data.datasets.forEach((dataset, i) => {
            const meta = chart.getDatasetMeta(i);
            meta.data.forEach((bar, index) => {
              const value = dataset.data[index];
              ctx.fillStyle = 'black';
              ctx.fillText(value, bar.x, bar.y - 10);
            });
          });
        }
      }]
    });
  }
  initializeChart();

  // --- Update Chart
  function updateChartData(visitor, product, addToCart, checkout, order) {
    if (brikpanelConversionChart) {
      brikpanelConversionChart.data.datasets[0].data = [visitor, product, addToCart, checkout, order];
      brikpanelConversionChart.update();
    }
  }

  // --- Calculate Conversion Rate
  function updateConversion(visitorTotal, orderTotal) {
    let visitorNum = parseInt(visitorTotal, 10) || 0;
    let orderNum = parseInt(orderTotal, 10) || 0;
    if (visitorNum > 0) {
      ajaxValueConversionRate.innerHTML = `${((orderNum / visitorNum) * 100).toFixed(2)}% ${i18nCC.conversion_rate}`;
    } else {
      ajaxValueConversionRate.innerHTML = `0% ${i18nCC.conversion_rate}`;
    }
  }

  // --- Function to make AJAX calls according to selected filter
  function fetchAllStats(selectedFilter) {
    // You can show a "Loading..." message on the screen here.
    ajaxValueConversionRate.innerHTML = i18nCC.calculating;

    // Let's create FormData dynamically here
    // (In your original code, "action" values may be different, use those)
    let formDataVisitorCount = new FormData();
    let formDataOrders = new FormData();
    let formDataProductCount = new FormData();
    let formDataAddtocartCount = new FormData();
    let formDataCheckoutCount = new FormData();

    // Set action according to filter
    switch (selectedFilter) {
      case 'today':
        formDataVisitorCount.append("action", "brikpanel_today_ajax_visitor_count");
        formDataOrders.append("action", "brikpanel_ajax_today_orders");
        formDataProductCount.append("action", "brikpanel_today_ajax_product_count");
        formDataAddtocartCount.append("action", "brikpanel_today_ajax_add_to_cart_count");
        formDataCheckoutCount.append("action", "brikpanel_today_ajax_checkout_count");
        break;
      case 'yesterday':
        formDataVisitorCount.append("action", "brikpanel_ajax_yesterday_visitor_count");
        formDataOrders.append("action", "brikpanel_ajax_yesterday_orders");
        formDataProductCount.append("action", "brikpanel_ajax_yesterday_product_count");
        formDataAddtocartCount.append("action", "brikpanel_ajax_yesterday_add_to_cart_count");
        formDataCheckoutCount.append("action", "brikpanel_ajax_yesterday_checkout_count");
        break;
      case '7days':
        formDataVisitorCount.append("action", "brikpanel_ajax_last_7_days_visitor_count");
        formDataOrders.append("action", "brikpanel_ajax_last_7_days_orders");
        formDataProductCount.append("action", "brikpanel_ajax_last_7_days_product_count");
        formDataAddtocartCount.append("action", "brikpanel_ajax_last_7_days_add_to_cart_count");
        formDataCheckoutCount.append("action", "brikpanel_ajax_last_7_days_checkout_count");
        break;
      case '30days':
        formDataVisitorCount.append("action", "brikpanel_ajax_last_30_days_visitor_count");
        formDataOrders.append("action", "brikpanel_ajax_last_30_days_orders");
        formDataProductCount.append("action", "brikpanel_ajax_last_30_days_product_count");
        formDataAddtocartCount.append("action", "brikpanel_ajax_last_30_days_add_to_cart_count");
        formDataCheckoutCount.append("action", "brikpanel_ajax_last_30_days_checkout_count");
        break;
      case '90days':
        formDataVisitorCount.append("action", "brikpanel_ajax_last_90_days_visitor_count");
        formDataOrders.append("action", "brikpanel_ajax_last_90_days_orders");
        formDataProductCount.append("action", "brikpanel_ajax_last_90_days_product_count");
        formDataAddtocartCount.append("action", "brikpanel_ajax_last_90_days_add_to_cart_count");
        formDataCheckoutCount.append("action", "brikpanel_ajax_last_90_days_checkout_count");
        break;
      case '365days':
        formDataVisitorCount.append("action", "brikpanel_ajax_last_365_days_visitor_count");
        formDataOrders.append("action", "brikpanel_ajax_last_365_days_orders");
        formDataProductCount.append("action", "brikpanel_ajax_last_365_days_product_count");
        formDataAddtocartCount.append("action", "brikpanel_ajax_last_365_days_add_to_cart_count");
        formDataCheckoutCount.append("action", "brikpanel_ajax_last_365_days_checkout_count");
        break;
      case 'custom':
        // If custom, we need to add start_date and end_date
        // If selectDates does not contain 2 dates, show a warning and return
        if (selectDates.length !== 2) {
          // Here you can use alert, or just return without doing anything
          alert(i18nCC.select_date);
          return;
        }
        formDataVisitorCount.append("action", "brikpanel_date_ajax_visitor_count");
        formDataVisitorCount.append('start_date', selectDates[0]);
        formDataVisitorCount.append('end_date', selectDates[1]);
        formDataVisitorCount.append('security', brikpanelAjax.nonce); 

        formDataOrders.append("action", "brikpanel_date_ajax_total_orders");
        formDataOrders.append('start_date', selectDates[0]);
        formDataOrders.append('end_date', selectDates[1]);
        formDataOrders.append('security', brikpanelAjax.nonce); 

        formDataProductCount.append("action", "brikpanel_date_ajax_product_count");
        formDataProductCount.append('start_date', selectDates[0]);
        formDataProductCount.append('end_date', selectDates[1]);
        formDataProductCount.append('security', brikpanelAjax.nonce); 

        formDataAddtocartCount.append("action", "brikpanel_date_ajax_add_to_cart_count");
        formDataAddtocartCount.append('start_date', selectDates[0]);
        formDataAddtocartCount.append('end_date', selectDates[1]);
        formDataAddtocartCount.append('security', brikpanelAjax.nonce); 

        formDataCheckoutCount.append("action", "brikpanel_date_ajax_checkout_count");
        formDataCheckoutCount.append('start_date', selectDates[0]);
        formDataCheckoutCount.append('end_date', selectDates[1]);
        formDataCheckoutCount.append('security', brikpanelAjax.nonce); 
        break;
      default:
        return; // Invalid filter
    }

    // Run AJAX requests in parallel with Promise.all
    Promise.all([
      fetch(brikpanelAjax.ajax_url, { method: 'POST', body: formDataVisitorCount }).then(res => res.json()),
      fetch(brikpanelAjax.ajax_url, { method: 'POST', body: formDataOrders }).then(res => res.json()),
      fetch(brikpanelAjax.ajax_url, { method: 'POST', body: formDataProductCount }).then(res => res.json()),
      fetch(brikpanelAjax.ajax_url, { method: 'POST', body: formDataAddtocartCount }).then(res => res.json()),
      fetch(brikpanelAjax.ajax_url, { method: 'POST', body: formDataCheckoutCount }).then(res => res.json())
    ])

    .then(([visitorData, orderData, productData, addtocartData, checkoutData]) => {
      if (
        visitorData.success &&
        orderData.success &&
        productData.success &&
        addtocartData.success &&
        checkoutData.success
      ) {
        // Put the returned data into the chart
        updateChartData(
          visitorData.data.total,
          productData.data.total,
          addtocartData.data.total,
          checkoutData.data.total,
          orderData.data.total
        );
        // Calculate conversion rate
        updateConversion(visitorData.data.total, orderData.data.total);
      } else {
        // In case of error
        ajaxValueConversionRate.innerHTML = i18nCC.error;
      }
    })
    .catch(error => {
      ajaxValueConversionRate.innerHTML = i18nCC.error;
    });
  }

  // --- Triggered when radio changes
  brikpanelRadios.addEventListener('change', function() {
    let selectedRadio = document.querySelector('input[name="filterConversionCount"]:checked');
    if (!selectedRadio) return;

    // If filter is 'custom', show date option and activate button
    if (selectedRadio.value === 'custom') {
      dateSelect.style.display = '';
      sendButton.style.display = '';

      // When button is clicked, fetch for "custom"
      sendButton.onclick = function () {
        fetchAllStats('custom');
      };

    } else {
      // Hide date fields for other filters
      dateSelect.style.display = 'none';
      sendButton.style.display = 'none';

      // Fetch directly according to selected filter
      fetchAllStats(selectedRadio.value);
    }
  });

  // --- On first page load, get "today" data
  fetchAllStats('today');

  // --- Auto refresh: repeat request every 30 seconds according to current filter
  // Pause polling when tab is hidden to save resources.
  let pollInterval = null;

  function pollTick() {
    let selectedRadio = document.querySelector('input[name="filterConversionCount"]:checked');
    if (!selectedRadio) return;
    if (selectedRadio.value === 'custom') {
      if (selectDates.length === 2) fetchAllStats('custom');
    } else {
      fetchAllStats(selectedRadio.value);
    }
  }

  function startPolling() { if (!pollInterval) pollInterval = setInterval(pollTick, 30000); }
  function stopPolling()  { if (pollInterval) { clearInterval(pollInterval); pollInterval = null; } }

  document.addEventListener('visibilitychange', function () {
    if (document.hidden) { stopPolling(); } else { pollTick(); startPolling(); }
  });
  startPolling();
});
