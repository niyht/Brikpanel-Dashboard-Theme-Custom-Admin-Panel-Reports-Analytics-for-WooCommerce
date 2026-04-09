/**
 * BrikPanel Dashboard - Main JavaScript
 *
 * Handles date filtering, batch AJAX data loading,
 * Chart.js rendering, and live visitor polling.
 *
 * @package BrikPanel
 * @since 1.8.0
 */

(function () {
    'use strict';

    const CFG = window.brikpanelDashboard || {};
    const i18n = CFG.i18n || {};

    // State
    let currentRange = 'today';
    let customStartDate = '';
    let customEndDate = '';
    let salesChart = null;
    let funnelChart = null;
    let ratesChart = null;
    let liveInterval = null;
    let globeInstance = null;
    let globeMarkers = [];
    let globeMarkersData = [];
    let globePhi = 0;
    let globeTheta = 0;
    let globeVisible = false;
    var prefersReducedMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    let datepickerInstance = null;
    let isLoading = false;

    // Chart.js defaults
    if (typeof Chart !== 'undefined') {
        Chart.defaults.font.family = '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif';
        Chart.defaults.font.size = 12;
        Chart.defaults.color = '#616161';
    }

    // =========================================================================
    // INIT
    // =========================================================================

    document.addEventListener('DOMContentLoaded', function () {
        initDatePresets();
        initDatepicker();
        fetchDashboardData();
        startLivePolling();

        // Pause polling when tab is hidden
        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState === 'hidden') {
                stopLivePolling();
            } else {
                startLivePolling();
                fetchDashboardData();
            }
        });
    });

    // =========================================================================
    // DATE PRESETS
    // =========================================================================

    function initDatePresets() {
        var presets = document.querySelectorAll('.brikpanel-dash-preset');
        presets.forEach(function (btn) {
            btn.addEventListener('click', function () {
                var range = this.getAttribute('data-range');

                presets.forEach(function (b) { b.classList.remove('active'); });
                this.classList.add('active');

                var customRange = document.querySelector('.brikpanel-dash-custom-range');

                if (range === 'custom') {
                    customRange.style.display = 'block';
                    if (datepickerInstance) {
                        datepickerInstance.open();
                    }
                    return;
                }

                customRange.style.display = 'none';
                currentRange = range;
                fetchDashboardData();
            });
        });
    }

    function initDatepicker() {
        var input = document.getElementById('brikpanel-dash-datepicker');
        if (!input || typeof flatpickr === 'undefined') return;

        datepickerInstance = flatpickr(input, {
            mode: 'range',
            dateFormat: 'Y-m-d',
            maxDate: 'today',
            onClose: function (selectedDates) {
                if (selectedDates.length === 2) {
                    var y1 = selectedDates[0].getFullYear();
                    var m1 = String(selectedDates[0].getMonth() + 1).padStart(2, '0');
                    var d1 = String(selectedDates[0].getDate()).padStart(2, '0');
                    customStartDate = y1 + '-' + m1 + '-' + d1;

                    var y2 = selectedDates[1].getFullYear();
                    var m2 = String(selectedDates[1].getMonth() + 1).padStart(2, '0');
                    var d2 = String(selectedDates[1].getDate()).padStart(2, '0');
                    customEndDate = y2 + '-' + m2 + '-' + d2;

                    currentRange = 'custom';
                    fetchDashboardData();
                }
            }
        });
    }

    // =========================================================================
    // FETCH DASHBOARD DATA (Single batch call)
    // =========================================================================

    function fetchDashboardData() {
        if (isLoading) return;
        isLoading = true;

        setLoadingState(true);

        var fd = new FormData();
        fd.append('action', 'brikpanel_dashboard_data');
        fd.append('security', CFG.nonce);
        fd.append('range', currentRange);

        if (currentRange === 'custom') {
            fd.append('start_date', customStartDate);
            fd.append('end_date', customEndDate);
        }

        fetch(CFG.ajax_url, {
            method: 'POST',
            body: fd,
            credentials: 'same-origin'
        })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            isLoading = false;
            setLoadingState(false);

            if (!res.success) return;
            var d = res.data;

            // Summary cards
            updateCard('card-total-sales', d.total_sales);
            updateCard('card-orders', formatNumber(d.order_count));
            updateCard('card-aov', d.aov);
            updateCard('card-visitors', formatNumber(d.visitor_count));
            updateCard('card-conversion', d.conversion_rate + '%');

            // Deltas
            updateDelta('delta-total-sales', d.deltas.sales);
            updateDelta('delta-orders', d.deltas.orders);
            updateDelta('delta-aov', d.deltas.aov);
            updateDelta('delta-visitors', d.deltas.visitors);
            updateDelta('delta-conversion', d.deltas.conversion);

            // Charts
            renderSalesChart(d.sales_over_time);
            renderFunnelChart(d.funnel);
            renderRatesChart(d.order_rates);

            // Globe + Tables
            renderGlobe(d.order_locations);
            renderTopCountries(d.order_locations.countries);
            renderTopCities(d.order_locations.cities);

            // Tables
            renderTopProducts(d.top_products);
            renderRecentOrders(d.recent_orders);
            renderMostViewed(d.most_viewed);
            renderMostCart(d.most_cart);
        })
        .catch(function () {
            isLoading = false;
            setLoadingState(false);
        });
    }

    // =========================================================================
    // UPDATE UI HELPERS
    // =========================================================================

    function updateCard(id, value) {
        var el = document.getElementById(id);
        if (el) el.innerHTML = value;
    }

    function updateDelta(id, value) {
        var el = document.getElementById(id);
        if (!el) return;

        if (value === 0 || value === null) {
            el.textContent = '--';
            el.className = 'brikpanel-dash-card-delta neutral';
            return;
        }

        var arrow = value > 0 ? '\u2191' : '\u2193';
        el.textContent = arrow + ' ' + Math.abs(value) + '%';
        el.className = 'brikpanel-dash-card-delta ' + (value > 0 ? 'positive' : 'negative');
    }

    function setLoadingState(loading) {
        var values = document.querySelectorAll('.brikpanel-dash-card-value');
        values.forEach(function (el) {
            if (loading) {
                el.classList.add('loading');
            } else {
                el.classList.remove('loading');
            }
        });
    }

    function formatNumber(n) {
        if (n === null || n === undefined) return '0';
        return Number(n).toLocaleString();
    }

    // =========================================================================
    // SALES OVER TIME CHART
    // =========================================================================

    function renderSalesChart(data) {
        var ctx = document.getElementById('brikpanel-sales-chart');
        if (!ctx || typeof Chart === 'undefined') return;

        var labels = data.map(function (d) { return d.date; });
        var revenue = data.map(function (d) { return d.revenue; });
        var orders = data.map(function (d) { return d.orders; });

        if (salesChart) {
            salesChart.data.labels = labels;
            salesChart.data.datasets[0].data = revenue;
            salesChart.data.datasets[1].data = orders;
            salesChart.update();
            return;
        }

        salesChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [
                    {
                        label: i18n.revenue || 'Revenue',
                        data: revenue,
                        borderColor: '#303030',
                        backgroundColor: 'rgba(48, 48, 48, 0.05)',
                        fill: true,
                        tension: 0.3,
                        borderWidth: 2,
                        pointRadius: data.length > 30 ? 0 : 3,
                        pointHoverRadius: 5,
                        yAxisID: 'y'
                    },
                    {
                        label: i18n.orders || 'Orders',
                        data: orders,
                        borderColor: '#8a8a8a',
                        backgroundColor: 'rgba(138, 138, 138, 0.1)',
                        fill: false,
                        tension: 0.3,
                        borderWidth: 1.5,
                        borderDash: [4, 4],
                        pointRadius: 0,
                        pointHoverRadius: 4,
                        yAxisID: 'y1'
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    mode: 'index',
                    intersect: false
                },
                scales: {
                    x: {
                        grid: { display: false },
                        ticks: { maxTicksLimit: 12, font: { size: 11 } }
                    },
                    y: {
                        position: 'left',
                        beginAtZero: true,
                        grid: { color: 'rgba(0,0,0,0.04)' },
                        ticks: {
                            font: { size: 11 },
                            callback: function (v) {
                                if (v >= 1000) return (v / 1000).toFixed(v >= 10000 ? 0 : 1) + 'k';
                                return v;
                            }
                        }
                    },
                    y1: {
                        position: 'right',
                        beginAtZero: true,
                        grid: { display: false },
                        ticks: {
                            font: { size: 11 },
                            stepSize: 1
                        }
                    }
                },
                plugins: {
                    legend: {
                        display: true,
                        position: 'top',
                        align: 'end',
                        labels: { boxWidth: 12, padding: 16, font: { size: 11 } }
                    },
                    tooltip: {
                        backgroundColor: '#303030',
                        titleFont: { size: 12, weight: '600' },
                        bodyFont: { size: 12 },
                        cornerRadius: 6,
                        padding: 10
                    }
                }
            }
        });
    }

    // =========================================================================
    // CONVERSION FUNNEL CHART
    // =========================================================================

    function renderFunnelChart(funnel) {
        var ctx = document.getElementById('brikpanel-funnel-chart');
        if (!ctx || typeof Chart === 'undefined') return;

        var labels = [
            i18n.visitors || 'Visitors',
            i18n.product_views || 'Product Views',
            i18n.add_to_cart || 'Add to Cart',
            i18n.checkout || 'Checkout',
            i18n.orders || 'Orders'
        ];
        var values = [funnel.visitors, funnel.products, funnel.cart, funnel.checkout, funnel.orders];
        var colors = ['#303030', '#4a4a4a', '#6a6a6a', '#8a8a8a', '#1a8917'];

        if (funnelChart) {
            funnelChart.data.datasets[0].data = values;
            funnelChart.update();
            return;
        }

        funnelChart = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [{
                    data: values,
                    backgroundColor: colors,
                    borderRadius: 4,
                    barThickness: 32
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                indexAxis: 'y',
                scales: {
                    x: {
                        beginAtZero: true,
                        grid: { color: 'rgba(0,0,0,0.04)' },
                        ticks: { font: { size: 11 } }
                    },
                    y: {
                        grid: { display: false },
                        ticks: { font: { size: 11, weight: '500' } }
                    }
                },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: '#303030',
                        cornerRadius: 6,
                        padding: 10
                    }
                }
            }
        });
    }

    // =========================================================================
    // ORDER RATES CHART (Doughnut)
    // =========================================================================

    function renderRatesChart(rates) {
        var ctx = document.getElementById('brikpanel-rates-chart');
        if (!ctx || typeof Chart === 'undefined') return;

        var labels = [
            (i18n.successful || 'Successful') + ' (' + rates.successful + '%)',
            (i18n.failed || 'Failed') + ' (' + rates.failed + '%)',
            (i18n.refunded || 'Refunded') + ' (' + rates.refunded + '%)',
            (i18n.cancelled || 'Cancelled') + ' (' + rates.cancelled + '%)'
        ];
        var values = [rates.successful, rates.failed, rates.refunded, rates.cancelled];
        var colors = ['#303030', '#d72c0d', '#8a8a8a', '#616161'];

        // If all values are 0, show a placeholder
        var allZero = values.every(function (v) { return v === 0; });
        if (allZero) {
            values = [1];
            labels = [i18n.no_orders || 'No orders'];
            colors = ['#e3e3e3'];
        }

        if (ratesChart) {
            ratesChart.data.labels = labels;
            ratesChart.data.datasets[0].data = values;
            ratesChart.data.datasets[0].backgroundColor = colors;
            ratesChart.update();
            return;
        }

        ratesChart = new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: labels,
                datasets: [{
                    data: values,
                    backgroundColor: colors,
                    borderWidth: 0,
                    spacing: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '60%',
                plugins: {
                    legend: {
                        display: true,
                        position: 'right',
                        labels: {
                            boxWidth: 10,
                            padding: 12,
                            font: { size: 11 }
                        }
                    },
                    tooltip: {
                        backgroundColor: '#303030',
                        cornerRadius: 6,
                        padding: 10
                    }
                }
            }
        });
    }

    // =========================================================================
    // TABLES
    // =========================================================================

    function renderTopProducts(products) {
        var wrap = document.getElementById('top-products-table');
        if (!wrap) return;

        if (!products || products.length === 0) {
            wrap.innerHTML = '<p class="brikpanel-dash-empty">' + (i18n.no_data || 'No data for this period') + '</p>';
            return;
        }

        var html = '<table class="brikpanel-dash-table"><thead><tr>' +
            '<th>#</th>' +
            '<th>' + (i18n.product || 'Product') + '</th>' +
            '<th>' + (i18n.qty_sold || 'Qty Sold') + '</th>' +
            '</tr></thead><tbody>';

        products.forEach(function (p, i) {
            html += '<tr>' +
                '<td class="rank">' + (i + 1) + '</td>' +
                '<td>' + escapeHtml(p.name) + '</td>' +
                '<td>' + formatNumber(p.qty) + '</td>' +
                '</tr>';
        });

        html += '</tbody></table>';
        wrap.innerHTML = html;
    }

    function renderRecentOrders(orders) {
        var wrap = document.getElementById('recent-orders-table');
        if (!wrap) return;

        if (!orders || orders.length === 0) {
            wrap.innerHTML = '<p class="brikpanel-dash-empty">' + (i18n.no_orders || 'No orders') + '</p>';
            return;
        }

        var html = '<table class="brikpanel-dash-table"><thead><tr>' +
            '<th>' + (i18n.order || 'Order') + '</th>' +
            '<th>' + (i18n.customer || 'Customer') + '</th>' +
            '<th>' + (i18n.source || 'Source') + '</th>' +
            '<th>' + (i18n.status || 'Status') + '</th>' +
            '<th>' + (i18n.total || 'Total') + '</th>' +
            '</tr></thead><tbody>';

        orders.forEach(function (o) {
            var sourceHtml = '';
            if (o.source && o.source.label) {
                sourceHtml = '<span class="brikpanel-dash-source" style="background:' + escapeHtml(o.source.color) + ';">' + escapeHtml(o.source.label) + '</span>';
            }

            html += '<tr>' +
                '<td>#' + o.id + '</td>' +
                '<td>' + escapeHtml(o.customer) + '</td>' +
                '<td>' + sourceHtml + '</td>' +
                '<td><span class="brikpanel-dash-status ' + escapeHtml(o.status) + '">' + escapeHtml(o.status) + '</span></td>' +
                '<td>' + o.total + '</td>' +
                '</tr>';
        });

        html += '</tbody></table>';
        wrap.innerHTML = html;
    }

    function renderMostViewed(pages) {
        var wrap = document.getElementById('most-viewed-table');
        if (!wrap) return;

        if (!pages || pages.length === 0) {
            wrap.innerHTML = '<p class="brikpanel-dash-empty">' + (i18n.no_data || 'No data for this period') + '</p>';
            return;
        }

        var html = '<table class="brikpanel-dash-table"><thead><tr>' +
            '<th>#</th>' +
            '<th>' + (i18n.page || 'Page') + '</th>' +
            '<th>' + (i18n.views || 'Views') + '</th>' +
            '</tr></thead><tbody>';

        pages.forEach(function (p, i) {
            html += '<tr>' +
                '<td class="rank">' + (i + 1) + '</td>' +
                '<td>' + escapeHtml(p.title) + '</td>' +
                '<td>' + formatNumber(p.views) + '</td>' +
                '</tr>';
        });

        html += '</tbody></table>';
        wrap.innerHTML = html;
    }

    function renderMostCart(products) {
        var wrap = document.getElementById('most-cart-table');
        if (!wrap) return;

        if (!products || products.length === 0) {
            wrap.innerHTML = '<p class="brikpanel-dash-empty">' + (i18n.no_data || 'No data for this period') + '</p>';
            return;
        }

        var html = '<table class="brikpanel-dash-table"><thead><tr>' +
            '<th>#</th>' +
            '<th>' + (i18n.product || 'Product') + '</th>' +
            '<th>' + (i18n.cart_count || 'Cart Adds') + '</th>' +
            '</tr></thead><tbody>';

        products.forEach(function (p, i) {
            html += '<tr>' +
                '<td class="rank">' + (i + 1) + '</td>' +
                '<td>' + escapeHtml(p.name) + '</td>' +
                '<td>' + formatNumber(p.count) + '</td>' +
                '</tr>';
        });

        html += '</tbody></table>';
        wrap.innerHTML = html;
    }

    // =========================================================================
    // GLOBE - ORDER LOCATIONS
    // =========================================================================

    var COUNTRY_COORDS = {
        AF:[33,65],AL:[41,20],DZ:[28,3],AD:[42.5,1.5],AO:[-12.5,18.5],AG:[17.05,-61.8],AR:[-34,-64],AM:[40,45],AU:[-25,134],AT:[47.3,13.3],
        AZ:[40.5,47.5],BS:[24,-76],BH:[26,50.5],BD:[24,90],BB:[13.2,-59.5],BY:[53,28],BE:[50.8,4],BZ:[17.3,-88.8],BJ:[9.5,2.3],BT:[27.5,90.5],
        BO:[-17,-65],BA:[44,18],BW:[-22,24],BR:[-10,-55],BN:[4.5,114.7],BG:[43,25],BF:[13,-2],BI:[-3.5,30],KH:[13,105],CM:[6,12.5],CA:[60,-96],
        CV:[16,-24],CF:[7,21],TD:[15,19],CL:[-30,-71],CN:[35,105],CO:[4,-72],KM:[-12.2,44.3],CG:[-1,15],CD:[-3,23],CR:[10,-84],CI:[8,-5.5],
        HR:[45.2,15.5],CU:[22,-80],CY:[35,33],CZ:[49.8,15.5],DK:[56,10],DJ:[11.5,43],DM:[15.4,-61.4],DO:[19,-70.7],EC:[-2,-77.5],EG:[27,30],
        SV:[13.8,-88.9],GQ:[2,10],ER:[15,39],EE:[59,26],ET:[8,38],FJ:[-18,175],FI:[64,26],FR:[46,2],GA:[-1,11.8],GM:[13.5,-15.5],GE:[42,43.5],
        DE:[51,9],GH:[8,-2],GR:[39,22],GD:[12.1,-61.7],GT:[15.5,-90.3],GN:[11,-10],GW:[12,-15],GY:[5,-59],HT:[19,-72.4],HN:[15,-86.5],
        HU:[47,20],IS:[65,-18],IN:[20,77],ID:[-5,120],IR:[32,53],IQ:[33,44],IE:[53,-8],IL:[31.5,34.8],IT:[42.8,12.8],JM:[18.3,-77.3],
        JP:[36,138],JO:[31,36],KZ:[48,68],KE:[1,38],KI:[1.4,173],KP:[40,127],KR:[37,127.5],KW:[29.5,47.8],KG:[41,75],LA:[18,105],
        LV:[57,25],LB:[33.8,35.8],LS:[-29.5,28.5],LR:[6.5,-9.5],LY:[25,17],LI:[47.2,9.5],LT:[56,24],LU:[49.8,6.2],MK:[41.5,22],MG:[-20,47],
        MW:[-13.5,34],MY:[2.5,112.5],MV:[3.2,73],ML:[17,-4],MT:[35.9,14.4],MH:[9,168],MR:[20,-12],MU:[-20.3,57.6],MX:[23,-102],FM:[6.9,158.2],
        MD:[47,29],MC:[43.7,7.4],MN:[46,105],ME:[42.5,19.3],MA:[32,-5],MZ:[-18.3,35],MM:[22,98],NA:[-22,17],NR:[-0.5,166.9],NP:[28,84],
        NL:[52.5,5.8],NZ:[-42,174],NI:[13,-85],NE:[16,8],NG:[10,8],NO:[62,10],OM:[21,57],PK:[30,70],PW:[7.5,134.6],PA:[9,-80],PG:[-6,147],
        PY:[-23,-58],PE:[-10,-76],PH:[13,122],PL:[52,20],PT:[39.5,-8],QA:[25.5,51.3],RO:[46,25],RU:[60,100],RW:[-2,30],KN:[17.3,-62.7],
        LC:[13.9,-61],VC:[13.3,-61.2],WS:[-13.8,-172],SM:[43.9,12.4],ST:[1,7],SA:[25,45],SN:[14,-14],RS:[44,21],SC:[-4.7,55.5],SL:[8.5,-11.8],
        SG:[1.4,103.8],SK:[48.7,19.5],SI:[46.1,15],SB:[-8,159],SO:[10,49],ZA:[-29,24],ES:[40,-4],LK:[7,81],SD:[16,30],SR:[4,-56],SZ:[-26.5,31.5],
        SE:[62,15],CH:[47,8],SY:[35,38],TW:[23.5,121],TJ:[39,71],TZ:[-6,35],TH:[15,100],TL:[-8.8,126],TG:[8,1.2],TO:[-20,-175],TT:[11,-61],
        TN:[34,9],TR:[39,35.2],TM:[40,60],TV:[-8,178],UG:[1,32],UA:[49,32],AE:[24,54],GB:[54,-2],US:[38,-97],UY:[-33,-56],UZ:[41,64],
        VU:[-16,167],VE:[8,-66],VN:[16,108],YE:[15,48],ZM:[-15,30],ZW:[-20,30]
    };


    function renderGlobe(locations) {
        if (typeof COBE === 'undefined') return;

        var countries = locations.countries || [];
        if (countries.length === 0) {
            globeMarkers = [];
            globeMarkersData = [];
            if (globeInstance) {
                globeInstance.destroy();
                globeInstance = null;
            }
            return;
        }

        var maxCount = countries[0].count;
        var cities = locations.cities || [];

        globeMarkers = [];
        globeMarkersData = [];

        countries.forEach(function (c, idx) {
            var coords = COUNTRY_COORDS[c.code];
            if (coords) {
                var countyCities = cities.filter(function(city) {
                    return city.country === c.code;
                });

                globeMarkers.push({
                    location: [coords[0], coords[1]],
                    size: Math.max(0.015, (c.count / maxCount) * 0.03),
                    id: 'marker-' + c.code
                });

                globeMarkersData.push({
                    country: c.name,
                    code: c.code,
                    orders: c.count,
                    total: c.total || '',
                    lat: coords[0],
                    lon: coords[1],
                    cities: countyCities
                });
            }
        });

        createGlobeInstance();
    }

    function createGlobeInstance() {
        if (globeInstance) {
            globeInstance.destroy();
            globeInstance = null;
        }

        var canvas = document.getElementById('brikpanel-globe');
        if (!canvas) return;

        if (!COBE || !COBE.default) return;

        var container = document.getElementById('globe-container');
        var w = container ? container.offsetWidth : 500;
        var h = container ? container.offsetHeight : 450;
        var size = Math.min(w, h);

        // Build arcs: hub (top country) to ALL others
        var allArcs = [];
        if (globeMarkers.length > 1) {
            var hub = globeMarkers[0].location;
            for (var i = 1; i < globeMarkers.length; i++) {
                allArcs.push({
                    from: hub,
                    to: globeMarkers[i].location
                });
            }
        }

        // --- Adaptive quality tiers ---
        var tiers = [
            { render: Math.min(size, 400), samples: 12000 },
            { render: 300, samples: 8000 },
            { render: 220, samples: 4000 }
        ];

        var quality = 'slow';
        for (var ti = 0; ti < tiers.length; ti++) {
            if (globeInstance) { globeInstance.destroy(); globeInstance = null; }
            quality = tryGlobeAtSize(canvas, size, tiers[ti].render, tiers[ti].samples, allArcs);
            if (quality === 'fast') return;
        }

        // All tiers too slow — static image fallback
        if (globeInstance) {
            createStaticGlobeFallback(canvas, globeInstance, size);
            globeInstance = null;
        }
    }

    function tryGlobeAtSize(canvas, displaySize, renderSize, samples, allArcs) {
        // Use actual DPR so rendered pixels match screen pixels (no blurry upscaling)
        var dpr = Math.min(window.devicePixelRatio || 1, 2);
        var actualRender = Math.round(renderSize * dpr);

        canvas.width = actualRender;
        canvas.height = actualRender;
        canvas.style.width = displaySize + 'px';
        canvas.style.height = displaySize + 'px';

        var globe = COBE.default(canvas, {
            devicePixelRatio: dpr,
            width: actualRender,
            height: actualRender,
            phi: globePhi,
            theta: globeTheta,
            dark: 0,
            diffuse: 1.2,
            mapSamples: samples,
            mapBrightness: 6,
            baseColor: [1, 1, 1],
            markerColor: [0.1, 0.1, 0.1],
            glowColor: [1, 1, 1],
            arcColor: [0.3, 0.3, 0.3],
            arcWidth: 0.4,
            arcHeight: 0.3,
            markerElevation: 0.02,
            markers: globeMarkers,
            arcs: allArcs
        });

        // Benchmark: measure render time
        var t0 = performance.now();
        globe.update({ phi: globePhi, theta: globeTheta });
        var renderTime = performance.now() - t0;

        // >80ms per frame = can't sustain smooth animation (~12fps threshold)
        if (renderTime > 80) {
            globeInstance = globe;
            return 'slow';
        }

        // --- Fast enough: set up full interactive animated globe ---
        globeInstance = globe;

        var pointerDown = false;
        var pointerX = 0;
        var pointerY = 0;
        var destroyed = false;
        var animFrame = null;
        var rotationSpeed = prefersReducedMotion ? 0 : 0.003;
        var arcTime = 0;
        var labelWrapper = null;

        // IntersectionObserver: pause when not visible
        globeVisible = true;
        var observer = null;
        if (window.IntersectionObserver) {
            observer = new IntersectionObserver(function (entries) {
                var wasVisible = globeVisible;
                globeVisible = entries[0].isIntersecting;
                if (globeVisible && !wasVisible && !animFrame) animate();
            }, { threshold: 0.1 });
            observer.observe(canvas);
        }

        // Smooth rAF animation loop
        function animate() {
            if (destroyed || !globeVisible) { animFrame = null; return; }

            if (!pointerDown && !prefersReducedMotion) {
                globePhi += rotationSpeed;
            }
            arcTime += 0.016;

            // Arc data-transfer animation: each arc pulses one at a time
            // hub→country "sending" effect
            var totalArcs = allArcs.length || 1;
            var sendDuration = 1.2; // seconds for one arc to fully light up and fade
            var cycleLen = totalArcs * sendDuration;
            var t = arcTime % cycleLen;

            var pulsedArcs = allArcs.map(function (arc, idx) {
                var arcStart = idx * sendDuration;
                var local = t - arcStart;
                if (local < 0) local += cycleLen;

                var b;
                if (local < sendDuration) {
                    var p = local / sendDuration;
                    // Smooth ease-in-out: quickly brighten, hold briefly, fade out
                    if (p < 0.3) {
                        b = 0.08 + 0.52 * (p / 0.3); // rise
                    } else if (p < 0.5) {
                        b = 0.6; // hold bright
                    } else {
                        b = 0.6 * (1 - (p - 0.5) / 0.5); // fade out
                        b = Math.max(b, 0.08);
                    }
                } else {
                    b = 0.08;
                }
                return { from: arc.from, to: arc.to, color: [b, b, b] };
            });

            globe.update({
                phi: globePhi,
                theta: globeTheta,
                arcs: pulsedArcs
            });

            // Update label visibility with CSS transition handling
            if (labelWrapper) {
                var rs = getComputedStyle(document.documentElement);
                globeMarkersData.forEach(function (d) {
                    var a = labelWrapper.querySelector('[style*="--cobe-marker-' + d.code + '"]');
                    if (!a) return;
                    var tag = a.querySelector('.globe-code-tag');
                    if (!tag) return;
                    var vis = rs.getPropertyValue('--cobe-visible-marker-' + d.code).trim();
                    tag.classList.toggle('globe-code-tag--visible', !!vis);
                });
            }

            animFrame = requestAnimationFrame(animate);
        }

        if (prefersReducedMotion) {
            globe.update({ phi: globePhi, theta: globeTheta });
        } else if (globeVisible) {
            animate();
        }

        var origDestroy = globe.destroy;
        globeInstance.destroy = function () {
            destroyed = true;
            if (animFrame) cancelAnimationFrame(animFrame);
            if (observer) observer.disconnect();
            origDestroy();
        };

        // Drag interaction
        canvas.addEventListener('pointerdown', function (e) {
            pointerDown = true;
            pointerX = e.clientX;
            pointerY = e.clientY;
            canvas.style.cursor = 'grabbing';
        });

        window.addEventListener('pointerup', function () {
            pointerDown = false;
            canvas.style.cursor = 'grab';
        });

        window.addEventListener('pointermove', function (e) {
            if (pointerDown) {
                var dx = e.clientX - pointerX;
                var dy = e.clientY - pointerY;
                pointerX = e.clientX;
                pointerY = e.clientY;
                globePhi += dx * 0.005;
                globeTheta += dy * 0.005;
            }
        });

        canvas.addEventListener('wheel', function (e) {
            e.preventDefault();
            globeTheta += e.deltaY * 0.0005;
        }, { passive: false });

        canvas.style.cursor = 'grab';

        // Add country code labels + set labelWrapper for visibility checks
        setTimeout(function () {
            setupGlobeLabels(canvas, displaySize);
            labelWrapper = canvas.parentElement;
        }, 300);

        return 'fast';
    }

    // Slow device fallback: capture globe as image, destroy WebGL, animate with CSS
    function createStaticGlobeFallback(canvas, globe, size) {
        // Capture current frame as PNG
        var dataURL = canvas.toDataURL('image/png');

        // Destroy cobe — free all WebGL resources
        globe.destroy();
        globeInstance = null;

        // Replace canvas with a CSS-animated image
        var container = canvas.parentElement;
        if (!container) return;

        // Remove cobe's wrapper divs and canvas
        canvas.style.display = 'none';
        // Also hide any cobe-generated anchor divs
        var cobeAnchors = container.querySelectorAll('div[style*="--cobe"]');
        cobeAnchors.forEach(function (el) { el.style.display = 'none'; });

        // Build: wrapper (clips circle + holds lighting overlay) > img (rotates)
        var wrap = document.createElement('div');
        wrap.className = 'brikpanel-globe-static-wrap';
        wrap.style.cssText = 'width:' + size + 'px;height:' + size + 'px;margin:0 auto;';

        var img = document.createElement('img');
        img.src = dataURL;
        img.alt = 'Order Locations Globe';
        img.className = 'brikpanel-globe-static';
        img.style.cssText = 'width:100%;height:100%;';

        wrap.appendChild(img);
        container.appendChild(wrap);

        // Hide theme toggle (static image can't change theme)
        var themeBtn = document.getElementById('globe-theme-toggle');
        if (themeBtn) themeBtn.style.display = 'none';
    }

    // Shared label setup for live globe
    function setupGlobeLabels(canvas, size) {
        var wrapper = canvas.parentElement;
        if (!wrapper) return;
        wrapper.style.overflow = 'hidden';
        wrapper.style.width = size + 'px';
        wrapper.style.height = size + 'px';
        wrapper.style.margin = '0 auto';
        wrapper.style.borderRadius = '50%';

        globeMarkersData.forEach(function (data) {
            var anchor = wrapper.querySelector('[style*="--cobe-marker-' + data.code + '"]');
            if (!anchor) return;
            anchor.style.overflow = 'visible';
            anchor.style.width = '0';
            anchor.style.height = '0';
            var tag = document.createElement('span');
            tag.className = 'globe-code-tag';
            tag.textContent = data.code;
            anchor.appendChild(tag);
        });
    }

    function countryFlag(code) {
        if (!code || code.length !== 2) return '';
        var base = 0x1F1E6;
        return String.fromCodePoint(base + code.charCodeAt(0) - 65, base + code.charCodeAt(1) - 65);
    }

    function renderTopCountries(countries) {
        var wrap = document.getElementById('top-countries-table');
        if (!wrap) return;

        if (!countries || countries.length === 0) {
            wrap.innerHTML = '<p class="brikpanel-dash-empty">' + (i18n.no_data || 'No data for this period') + '</p>';
            return;
        }

        var maxCount = countries[0].count;
        var html = '<div class="brikpanel-country-list">';

        countries.slice(0, 5).forEach(function (c) {
            var pct = maxCount > 0 ? Math.round((c.count / maxCount) * 100) : 0;
            html += '<div class="brikpanel-country-row">' +
                '<div class="country-flag">' + countryFlag(c.code) + '</div>' +
                '<div class="country-info">' +
                    '<div class="country-header">' +
                        '<span class="country-name">' + escapeHtml(c.name) + '</span>' +
                        '<span class="country-total">' + (c.total || '') + '</span>' +
                    '</div>' +
                    '<div class="country-bar-wrap">' +
                        '<div class="country-bar" style="width:' + pct + '%"></div>' +
                    '</div>' +
                    '<div class="country-meta">' + formatNumber(c.count) + ' ' + (i18n.orders || 'orders') + '</div>' +
                '</div>' +
            '</div>';
        });

        html += '</div>';
        wrap.innerHTML = html;
    }

    function renderTopCities(cities) {
        var wrap = document.getElementById('top-cities-table');
        if (!wrap) return;

        if (!cities || cities.length === 0) {
            wrap.innerHTML = '<p class="brikpanel-dash-empty">' + (i18n.no_data || 'No data for this period') + '</p>';
            return;
        }

        var html = '<table class="brikpanel-dash-table"><thead><tr>' +
            '<th>#</th><th>' + (i18n.city || 'City') + '</th><th>' + (i18n.orders || 'Orders') + '</th>' +
            '</tr></thead><tbody>';

        cities.slice(0, 5).forEach(function (c, i) {
            html += '<tr><td class="rank">' + (i + 1) + '</td>' +
                '<td>' + escapeHtml(c.name) + '</td>' +
                '<td>' + formatNumber(c.count) + '</td></tr>';
        });

        html += '</tbody></table>';
        wrap.innerHTML = html;
    }

    // =========================================================================
    // LIVE VISITORS POLLING
    // =========================================================================

    function startLivePolling() {
        if (liveInterval) return;
        fetchLiveVisitors();
        liveInterval = setInterval(fetchLiveVisitors, 10000);
    }

    function stopLivePolling() {
        if (liveInterval) {
            clearInterval(liveInterval);
            liveInterval = null;
        }
    }

    function fetchLiveVisitors() {
        var fd = new FormData();
        fd.append('action', 'brikpanel_dashboard_live');

        fetch(CFG.ajax_url, {
            method: 'POST',
            body: fd,
            credentials: 'same-origin'
        })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            if (!res.success) return;
            renderLiveVisitors(res.data);
        })
        .catch(function () {});
    }

    function renderLiveVisitors(visitors) {
        var countEl = document.getElementById('live-count');
        var listEl = document.getElementById('live-visitors-list');
        if (!countEl || !listEl) return;

        countEl.textContent = visitors.length;

        if (visitors.length === 0) {
            listEl.innerHTML = '<p class="brikpanel-dash-empty">' + (i18n.no_visitors || 'No active visitors') + '</p>';
            return;
        }

        var html = '';
        visitors.forEach(function (v) {
            var pagePath = v.page_url;
            try {
                var urlObj = new URL(v.page_url);
                pagePath = urlObj.pathname + (urlObj.search || '');
            } catch (e) {}

            // Status detection
            var status = v.visitor_status || (v.has_cart_item === 'Yes' ? 'cart' : 'browsing');
            var badgeClass, badgeText;

            if (status === 'order_received') {
                badgeClass = 'order-received';
                badgeText = i18n.order_received || 'Order Received';
            } else if (status === 'cart') {
                badgeClass = 'added-to-cart';
                badgeText = i18n.added_to_cart || 'Added to Cart';
            } else {
                badgeClass = 'browsing';
                badgeText = i18n.browsing || 'Browsing';
            }

            // Display name: customer name or IP
            var displayName = v.customer_name ? escapeHtml(v.customer_name) : (v.ip_address || '');
            var ipLabel = v.ip_address ? '<span class="brikpanel-dash-live-ip">' + escapeHtml(v.ip_address) + '</span>' : '';

            // Tooltip data for hover
            var tooltipParts = [];
            if (v.customer_email) tooltipParts.push(v.customer_email);
            if (v.customer_phone) tooltipParts.push(v.customer_phone);
            if (v.page_url) tooltipParts.push(v.page_url);
            var tooltipData = tooltipParts.length > 0 ? ' data-bp-tooltip="' + escapeHtml(tooltipParts.join('\n')) + '"' : '';

            html += '<div class="brikpanel-dash-live-item"' + tooltipData + '>' +
                '<div class="brikpanel-dash-live-info">' +
                    '<span class="brikpanel-dash-live-name">' + displayName + '</span>' +
                    (v.customer_name ? ipLabel : '') +
                    '<span class="brikpanel-dash-live-page" title="' + escapeHtml(v.page_url) + '">' + escapeHtml(pagePath) + '</span>' +
                '</div>' +
                '<span class="brikpanel-dash-live-badge ' + badgeClass + '">' + badgeText + '</span>' +
                '</div>';
        });

        listEl.innerHTML = html;

        // Attach tooltip handlers
        listEl.querySelectorAll('[data-bp-tooltip]').forEach(function (el) {
            el.addEventListener('mouseenter', showTooltip);
            el.addEventListener('mouseleave', hideTooltip);
        });
    }

    function showTooltip(e) {
        hideTooltip();
        var text = e.currentTarget.getAttribute('data-bp-tooltip');
        if (!text) return;

        var tip = document.createElement('div');
        tip.className = 'brikpanel-dash-tooltip';
        tip.id = 'bp-live-tooltip';

        var lines = text.split('\n');
        lines.forEach(function (line) {
            var p = document.createElement('div');
            p.textContent = line;
            tip.appendChild(p);
        });

        document.body.appendChild(tip);

        var rect = e.currentTarget.getBoundingClientRect();
        tip.style.top = (rect.bottom + window.scrollY + 6) + 'px';
        tip.style.left = (rect.left + window.scrollX) + 'px';
    }

    function hideTooltip() {
        var existing = document.getElementById('bp-live-tooltip');
        if (existing) existing.remove();
    }

    // =========================================================================
    // UTILITIES
    // =========================================================================

    function escapeHtml(str) {
        if (!str) return '';
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

})();
