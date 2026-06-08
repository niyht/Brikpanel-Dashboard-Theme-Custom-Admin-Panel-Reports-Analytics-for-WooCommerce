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
    let mpShareChart = null;
    let liveInterval = null;
    let globeInstance = null;
    let globeMarkers = [];
    let globeMarkersData = [];
    let globePhi = 0;
    let globeTheta = 0;
    let globeVisible = false;
    let locView = 'orders';       // 'orders' | 'customers'
    let locationsData = null;     // cached locations payload for re-render on tab switch
    let deviceView = 'visitors';  // 'visitors' | 'orders' — tab inside the device panel
    let deviceData = { visitors: null, orders: null }; // cached payloads for re-render on tab switch
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
        initLocTabs();
        initDeviceTabs();
        initCopySummary();
        initExportButton();
        initRowLinks();
        initProfitBreakdownToggle();
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
                if (datepickerInstance) {
                    datepickerInstance.close();
                }
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

            // Date-range subtitle — always states which dates / how long.
            renderPeriod(d.period);

            // Broadcast the payload so dashboard add-ons (Ad Platforms, etc.)
            // can fill their own cards without each having to make a separate
            // AJAX round-trip. Subscribers listen on document for
            // `brikpanel:dashboardData` and read e.detail.
            try {
                document.dispatchEvent(new CustomEvent('brikpanel:dashboardData', { detail: d }));
            } catch (err) { /* IE / old WebView fallback: ignored */ }

            // Summary cards
            updateCard('card-total-sales', d.total_sales);
            updateCard('card-orders', d.order_count_display != null ? d.order_count_display : formatNumber(d.order_count));
            updateCard('card-aov', d.aov);
            updateCard('card-visitors', d.visitor_count_display != null ? d.visitor_count_display : formatNumber(d.visitor_count));
            updateCard('card-conversion', (d.conversion_rate_display != null ? d.conversion_rate_display : d.conversion_rate) + '%');

            // Deltas
            updateDelta('delta-total-sales', d.deltas.sales);
            updateDelta('delta-orders', d.deltas.orders);
            updateDelta('delta-aov', d.deltas.aov);
            updateDelta('delta-visitors', d.deltas.visitors);
            updateDelta('delta-conversion', d.deltas.conversion);

            // Profit (Revenue − Cost of goods − Expenses)
            renderProfit(d.profit);

            // Charts
            renderSalesChart(d.sales_over_time);
            renderFunnelChart(d.funnel);
            renderRatesChart(d.order_rates);

            // Globe + Tables
            locationsData = d.order_locations;
            applyLocView(locView);

            // Tables
            renderTopProducts(d.top_products);
            renderRecentOrders(d.recent_orders);
            renderMostViewed(d.most_viewed);
            renderMostCart(d.most_cart);

            // Device breakdown + customer types.
            // Cache both payloads so tab switches re-render without an AJAX
            // round-trip; render the active view.
            deviceData.visitors = d.devices;
            deviceData.orders   = d.order_devices;
            applyDeviceView(deviceView);
            renderCustomerTypes(d.customer_types);

            // RFM segment distribution (precomputed nightly).
            renderRfmSegments(d.rfm_distribution || []);

            // Low stock + LTV summary panel (Returns & Refunds % is now
            // surfaced in the Order Rates donut alongside cancelled/failed).
            renderLowStock(d.low_stock);
            renderLtvPanel(d.ltv_panel);

            // Subscriptions.
            renderSubscriptions(d.subscription_stats);

            // Marketplace analytics (BrikMarket-only).
            renderMarketplaceAnalytics(d.marketplace);
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

        // No baseline (previous period was zero): server sends null. Label it
        // rather than inventing a "+100%" that reads like ordinary growth.
        if (value === null || value === undefined) {
            el.textContent = i18n.delta_new || 'New';
            el.className = 'brikpanel-dash-card-delta is-new';
            return;
        }

        // Genuinely flat / no movement.
        if (value === 0) {
            el.textContent = '--';
            el.className = 'brikpanel-dash-card-delta neutral';
            return;
        }

        var arrow = value > 0 ? '\u2191' : '\u2193';
        el.textContent = arrow + ' ' + formatDeltaPct(Math.abs(value));
        el.className = 'brikpanel-dash-card-delta ' + (value > 0 ? 'positive' : 'negative');
    }

    // A raw "+3704%" is technically right but unreadable. Past ~10\u00d7 growth,
    // show the multiplier ("38\u00d7") which people parse instantly; mid-range
    // drops the noisy decimal; small moves keep one decimal of precision.
    function formatDeltaPct(abs) {
        if (abs >= 1000) {
            return (Math.round((abs / 100 + 1) * 10) / 10) + '\u00d7';
        }
        if (abs >= 100) {
            return Math.round(abs) + '%';
        }
        return abs + '%';
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
    // PROFIT (Revenue − Cost of goods − Expenses)
    // =========================================================================

    function renderProfit(p) {
        if (!p) return;

        var ofRev = i18n.profit_of_revenue || 'of revenue';

        updateCard('card-profit-revenue', p.revenue);
        updateCard('card-profit-cogs', p.cogs);
        updateCard('card-profit-expenses', p.expenses);
        updateCard('card-profit-net', p.net);

        // Revenue here is the SAME figure as the "Total Sales" KPI card and
        // is just the top line of the P&L — repeating its trend arrow makes
        // users think they're two different numbers. Label the relationship
        // instead of duplicating the delta.
        var revDelta = document.getElementById('delta-profit-revenue');
        if (revDelta) {
            revDelta.textContent = i18n.profit_revenue_note || 'Same as Total Sales';
            revDelta.className = 'brikpanel-dash-card-delta brikpanel-dash-card-delta-static';
        }
        updateDelta('delta-profit-net', p.delta_net);

        // Cost of Goods: share of revenue. Two failure modes are called out
        // because both silently overstate Net profit: (a) no product has a
        // cost at all, (b) some sold products have no cost on file. When the
        // server returned the per-product list of offenders, the warning gets
        // a hover "!" that names them — so the merchant can jump straight to
        // the products that matter instead of guessing.
        var cogsDelta = document.getElementById('delta-profit-cogs');
        if (cogsDelta) {
            var cogsWarn = false;
            var cogsList = null;
            if (!p.has_cogs) {
                cogsDelta.textContent = i18n.profit_cogs_hint || 'Set “Cost of goods” on products';
                cogsWarn = true;
            } else if (p.cogs_incomplete) {
                var tpl = i18n.profit_cogs_partial || 'cost missing on %d items — profit overstated';
                cogsDelta.textContent = tpl.replace('%d', p.cogs_missing_lines);
                cogsWarn = true;
                if (Array.isArray(p.cogs_missing_products) && p.cogs_missing_products.length) {
                    cogsList = p.cogs_missing_products;
                }
            } else {
                cogsDelta.textContent = p.cogs_pct + '% ' + ofRev;
            }
            cogsDelta.className = 'brikpanel-dash-card-delta brikpanel-dash-card-delta-static'
                + (cogsWarn ? ' warn' : '');
            // Anchor the "!" to the card LABEL, not this delta line. The delta
            // text ("cost missing on N items — profit overstated") already fills
            // its line at common card widths, so an inline "!" there wraps onto
            // its own row and grows only this card — breaking the four-card row
            // alignment. The short "Cost of Goods" label always has room for it,
            // mirroring the Net Profit card's estimate "!".
            var cogsCard = cogsDelta.closest('.brikpanel-dash-card');
            var cogsLabel = cogsCard ? cogsCard.querySelector('.brikpanel-dash-card-label') : null;
            setMissingCogsListFlag(cogsLabel, cogsList);
        }

        // Expenses: share of revenue under the card; the composition itself
        // lives in a full-width ribbon below so the four hero cards stay
        // perfectly uniform in height.
        var expDelta = document.getElementById('delta-profit-expenses');
        if (expDelta) {
            expDelta.textContent = p.expenses_pct + '% ' + ofRev;
        }
        renderExpenseBreakdown(p);

        // Net profit: colour green/red and show the margin %.
        var netCard = document.querySelector('.brikpanel-dash-card[data-metric="profit_net"]');
        if (netCard) {
            netCard.classList.toggle('is-loss', p.net_raw < 0);
            netCard.classList.toggle('is-profit', p.net_raw > 0);
            // Missing costs make this optimistic, not exact. Instead of a
            // loud border, mark it with a quiet "!" that explains — on
            // hover/focus — exactly what to do to make it accurate.
            var estTip = (i18n.profit_estimate_tip
                || '%d sold items have no cost set. Add their “Cost of goods” so Net profit is accurate.')
                .replace('%d', p.cogs_missing_lines);
            setEstimateFlag(netCard, !!p.cogs_incomplete, estTip);
        }
        var netDelta = document.getElementById('delta-profit-net');
        if (netDelta && (p.margin || p.margin === 0)) {
            var marginTxt = (p.net_raw < 0 ? (i18n.profit_loss || 'Loss') + ' · ' : '')
                + p.margin + '% ' + ofRev;
            var base = netDelta.textContent && netDelta.textContent !== '--'
                ? netDelta.textContent + ' · ' : '';
            netDelta.textContent = base + marginTxt;
        }
    }

    // Add/remove a small "!" marker (with a hover/focus tooltip telling the
    // user what to fix) next to a card's label. Idempotent — safe to call
    // on every render. Keyboard-reachable via tabindex; the styled tooltip
    // is the only visible one (no native `title` so it doesn't double up).
    function setEstimateFlag(card, show, msg) {
        if (!card) return;
        var label = card.querySelector('.brikpanel-dash-card-label');
        if (!label) return;
        var flag = label.querySelector('.brikpanel-dash-flag');

        if (!show) {
            if (flag) flag.parentNode.removeChild(flag);
            return;
        }
        if (!flag) {
            flag = document.createElement('span');
            flag.className = 'brikpanel-dash-flag';
            flag.setAttribute('tabindex', '0');
            flag.setAttribute('role', 'note');
            flag.innerHTML =
                '<span class="brikpanel-dash-flag-mark" aria-hidden="true">!</span>'
                + '<span class="brikpanel-dash-flag-tip"></span>';
            label.appendChild(flag);
        }
        flag.setAttribute('aria-label', msg);
        flag.querySelector('.brikpanel-dash-flag-tip').textContent = msg;
    }

    // Append a "!" next to the COGS card label whose tooltip lists the
    // offending product names + their lost-cost revenue. `host` is the card
    // label (kept short so the icon never wraps and grows the card).
    // Idempotent: any prior flag on the same host is replaced before
    // re-rendering, so range toggles cannot double up the icon. Product names
    // use textContent (untrusted user input); the per-row amount is the
    // server's already-formatted wc_price() HTML so the currency symbol/decimal
    // style matches the rest of the UI.
    function setMissingCogsListFlag(host, products) {
        if (!host) return;
        var existing = host.querySelector('.brikpanel-dash-flag');
        if (existing) existing.parentNode.removeChild(existing);
        if (!Array.isArray(products) || !products.length) return;

        var flag = document.createElement('span');
        flag.className = 'brikpanel-dash-flag brikpanel-dash-flag-list';
        flag.setAttribute('tabindex', '0');
        flag.setAttribute('role', 'note');

        var mark = document.createElement('span');
        mark.className = 'brikpanel-dash-flag-mark';
        mark.setAttribute('aria-hidden', 'true');
        mark.textContent = '!';

        var tip = document.createElement('span');
        tip.className = 'brikpanel-dash-flag-tip brikpanel-dash-flag-tip-list';

        var title = document.createElement('strong');
        title.className = 'brikpanel-dash-flag-tip-title';
        title.textContent = i18n.profit_cogs_missing_title || 'Products without a cost';
        tip.appendChild(title);

        var list = document.createElement('ul');
        list.className = 'brikpanel-dash-flag-tip-items';
        var unlinkedLbl = i18n.profit_cogs_missing_unlinked || 'no longer in catalog';
        products.forEach(function (it) {
            var li = document.createElement('li');
            li.className = 'brikpanel-dash-flag-tip-item';

            var name = document.createElement('span');
            name.className = 'brikpanel-dash-flag-tip-item-name';
            name.textContent = it.name;
            if (it.unlinked) {
                // Quiet sibling note so the merchant knows this row has no
                // editable product behind it — they can't fix it from the
                // dashboard the way they would for a linked product.
                var tag = document.createElement('em');
                tag.className = 'brikpanel-dash-flag-tip-item-unlinked';
                tag.textContent = ' (' + unlinkedLbl + ')';
                name.appendChild(tag);
            }
            li.appendChild(name);

            var meta = document.createElement('span');
            meta.className = 'brikpanel-dash-flag-tip-item-meta';
            meta.innerHTML = it.missing_revenue_html;
            li.appendChild(meta);

            list.appendChild(li);
        });
        tip.appendChild(list);

        var ariaTpl = i18n.profit_cogs_missing_aria || '%d products are missing a cost';
        flag.setAttribute('aria-label', ariaTpl.replace('%d', products.length));

        flag.appendChild(mark);
        flag.appendChild(tip);
        host.appendChild(flag);
    }

    // Fill the (collapsed-by-default) breakdown list inside the Expenses
    // card. The list itself is hidden behind a toggle so all four hero
    // cards stay the same compact height until the user opts to expand it.
    function renderExpenseBreakdown(p) {
        var box    = document.getElementById('profit-expenses-breakdown');
        var toggle = document.getElementById('profit-bd-toggle');
        if (!box) return;

        var items = (p && p.breakdown) ? p.breakdown : [];
        var total = 0;
        items.forEach(function (b) { total += Number(b.raw) || 0; });

        box.innerHTML = '';

        // No expenses at all → no toggle, card stays minimal.
        if (!items.length || total <= 0) {
            if (toggle) {
                toggle.hidden = true;
                toggle.setAttribute('aria-expanded', 'false');
            }
            var cardEmpty = document.getElementById('profit-expenses-card');
            if (cardEmpty) cardEmpty.classList.remove('is-bd-open');
            return;
        }
        if (toggle) toggle.hidden = false;

        items.forEach(function (b) {
            var pct = Math.round((b.raw / total) * 100);

            var row = document.createElement('div');
            row.className = 'brikpanel-dash-bd-row';

            var k = document.createElement('span');
            k.className = 'brikpanel-dash-bd-k';
            k.textContent = b.label;

            var v = document.createElement('span');
            v.className = 'brikpanel-dash-bd-v';
            v.innerHTML = b.amount + ' <span class="brikpanel-dash-bd-pct">' + pct + '%</span>';

            row.appendChild(k);
            row.appendChild(v);
            box.appendChild(row);
        });
    }

    // Wire the Expenses "Breakdown ⌄" toggle once. Open/closed state is kept
    // across data refreshes so a refresh never collapses what the user opened.
    function initProfitBreakdownToggle() {
        var toggle = document.getElementById('profit-bd-toggle');
        var card   = document.getElementById('profit-expenses-card');
        if (!toggle || !card) return;

        toggle.addEventListener('click', function () {
            var open = !card.classList.contains('is-bd-open');
            card.classList.toggle('is-bd-open', open);
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
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
            (i18n.refunded || 'Returns & Refunds') + ' (' + rates.refunded + '%)',
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
            var rowAttr = p.url
                ? ' class="brikpanel-dash-row-link" data-href="' + escapeHtml(p.url) + '" tabindex="0" role="link"'
                : '';
            html += '<tr' + rowAttr + '>' +
                '<td class="rank">' + (i + 1) + '</td>' +
                '<td>' + escapeHtml(p.name) + '</td>' +
                '<td>' + formatNumber(p.qty) + '</td>' +
                '</tr>';
        });

        html += '</tbody></table>';
        wrap.innerHTML = html;
    }

    function initRowLinks() {
        // Delegated handler for any clickable row inside the dashboard.
        // Always opens the target in a new tab.
        document.addEventListener('click', function (e) {
            var row = e.target.closest && e.target.closest('.brikpanel-dash-row-link');
            if (!row) return;
            var href = row.getAttribute('data-href');
            if (!href) return;
            // Don't intercept clicks on actual links/buttons inside the row.
            if (e.target.closest('a, button')) return;
            e.preventDefault();
            window.open(href, '_blank', 'noopener');
        });

        document.addEventListener('auxclick', function (e) {
            if (e.button !== 1) return;
            var row = e.target.closest && e.target.closest('.brikpanel-dash-row-link');
            if (!row) return;
            var href = row.getAttribute('data-href');
            if (!href) return;
            e.preventDefault();
            window.open(href, '_blank', 'noopener');
        });

        document.addEventListener('keydown', function (e) {
            if (e.key !== 'Enter' && e.key !== ' ') return;
            var row = e.target.closest && e.target.closest('.brikpanel-dash-row-link');
            if (!row || row !== document.activeElement) return;
            var href = row.getAttribute('data-href');
            if (!href) return;
            e.preventDefault();
            window.open(href, '_blank', 'noopener');
        });
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

            var rowAttr = o.edit_url
                ? ' class="brikpanel-dash-row-link" data-href="' + escapeHtml(o.edit_url) + '" tabindex="0" role="link"'
                : '';

            html += '<tr' + rowAttr + '>' +
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
            var rowAttr = p.url
                ? ' class="brikpanel-dash-row-link" data-href="' + escapeHtml(p.url) + '" tabindex="0" role="link"'
                : '';
            html += '<tr' + rowAttr + '>' +
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
            var rowAttr = p.url
                ? ' class="brikpanel-dash-row-link" data-href="' + escapeHtml(p.url) + '" tabindex="0" role="link"'
                : '';
            html += '<tr' + rowAttr + '>' +
                '<td class="rank">' + (i + 1) + '</td>' +
                '<td>' + escapeHtml(p.name) + '</td>' +
                '<td>' + formatNumber(p.count) + '</td>' +
                '</tr>';
        });

        html += '</tbody></table>';
        wrap.innerHTML = html;
    }

    function renderDevices(data) {
        var wrap = document.getElementById('brikpanel-device-breakdown');
        if (!wrap) return;
        if (!data) {
            wrap.innerHTML = '<p class="brikpanel-dash-empty">' + (i18n.no_data || 'No data for this period') + '</p>';
            return;
        }
        var mobile  = data.mobile  || 0;
        var tablet  = data.tablet  || 0;
        var desktop = data.desktop || 0;
        var total   = mobile + tablet + desktop;

        if (total === 0) {
            wrap.innerHTML = '<p class="brikpanel-dash-empty">' + (i18n.no_data || 'No data for this period') + '</p>';
            return;
        }

        var pct = function (n) { return total > 0 ? Math.round((n / total) * 100) : 0; };

        var rows = [
            { label: i18n.device_desktop || 'Desktop', icon: '🖥', count: desktop, p: pct(desktop) },
            { label: i18n.device_mobile  || 'Mobile',  icon: '📱', count: mobile,  p: pct(mobile)  },
            { label: i18n.device_tablet  || 'Tablet',  icon: '⬛', count: tablet,  p: pct(tablet)  },
        ];

        var html = '<div class="brikpanel-device-list">';
        rows.forEach(function (r) {
            html += '<div class="brikpanel-device-row">'
                + '<span class="brikpanel-device-label">' + escapeHtml(r.label) + '</span>'
                + '<div class="brikpanel-device-bar-wrap">'
                +   '<div class="brikpanel-device-bar" style="width:' + r.p + '%"></div>'
                + '</div>'
                + '<span class="brikpanel-device-pct">' + r.p + '%</span>'
                + '<span class="brikpanel-device-count brikpanel-dash-muted">(' + formatNumber(r.count) + ')</span>'
                + '</div>';
        });
        html += '</div>';
        wrap.innerHTML = html;
    }

    function renderCustomerTypes(data) {
        var wrap = document.getElementById('brikpanel-customer-types');
        if (!wrap) return;
        if (!data) {
            wrap.innerHTML = '<p class="brikpanel-dash-empty">' + (i18n.no_data || 'No data for this period') + '</p>';
            return;
        }
        var newC    = data['new']    || 0;
        var repeatC = data['repeat'] || 0;
        var total   = newC + repeatC;

        if (total === 0) {
            wrap.innerHTML = '<p class="brikpanel-dash-empty">' + (i18n.no_data || 'No data for this period') + '</p>';
            return;
        }

        var pct = function (n) { return total > 0 ? Math.round((n / total) * 100) : 0; };

        var rows = [
            { label: i18n.ctype_new    || 'New customers',    count: newC,    p: pct(newC)    },
            { label: i18n.ctype_repeat || 'Repeat customers', count: repeatC, p: pct(repeatC) },
        ];

        var html = '<div class="brikpanel-device-list">';
        rows.forEach(function (r) {
            html += '<div class="brikpanel-device-row">'
                + '<span class="brikpanel-device-label">' + escapeHtml(r.label) + '</span>'
                + '<div class="brikpanel-device-bar-wrap">'
                +   '<div class="brikpanel-device-bar" style="width:' + r.p + '%"></div>'
                + '</div>'
                + '<span class="brikpanel-device-pct">' + r.p + '%</span>'
                + '<span class="brikpanel-device-count brikpanel-dash-muted">(' + formatNumber(r.count) + ')</span>'
                + '</div>';
        });
        html += '</div>';
        wrap.innerHTML = html;
    }

    var rfmDonutChart = null;
    function renderRfmSegments(segments) {
        var wrap = document.getElementById('brikpanel-rfm-segments');
        if (!wrap) return;
        if (!segments || segments.length === 0) {
            wrap.innerHTML = '<p class="brikpanel-dash-empty">' + (i18n.no_data || 'No customer data yet — the nightly recompute hasn\'t populated metrics.') + '</p>';
            return;
        }

        var chartId = 'brikpanel-rfm-donut-canvas';
        var legendHtml = '<div style="flex:1;min-width:200px;display:grid;grid-template-columns:1fr 1fr;gap:0.375rem 1rem;font-size:0.8125rem;">';
        segments.forEach(function (s) {
            legendHtml += '<div style="display:flex;align-items:center;gap:0.5rem;">'
                + '<span style="width:8px;height:8px;border-radius:50%;background:' + s.color + ';flex-shrink:0"></span>'
                + '<span style="flex:1;color:#303030;">' + escapeHtml(s.label) + '</span>'
                + '<span style="color:#616161;font-variant-numeric:tabular-nums;">' + s.customers + ' (' + s.share + '%)</span>'
                + '</div>';
        });
        legendHtml += '</div>';

        wrap.innerHTML = '<div style="width:180px;height:180px;flex-shrink:0;"><canvas id="' + chartId + '"></canvas></div>' + legendHtml;

        var canvas = document.getElementById(chartId);
        if (!canvas || typeof Chart === 'undefined') { return; }

        if (rfmDonutChart) { rfmDonutChart.destroy(); }
        rfmDonutChart = new Chart(canvas.getContext('2d'), {
            type: 'doughnut',
            data: {
                labels: segments.map(function (s) { return s.label; }),
                datasets: [{
                    data: segments.map(function (s) { return s.customers; }),
                    backgroundColor: segments.map(function (s) { return s.color; }),
                    borderWidth: 2,
                    borderColor: '#ffffff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '62%',
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function (ctx) {
                                var total = ctx.dataset.data.reduce(function (a, b) { return a + b; }, 0);
                                var pct = total > 0 ? Math.round(ctx.parsed / total * 100) : 0;
                                return ctx.label + ': ' + ctx.parsed + ' (' + pct + '%)';
                            }
                        }
                    }
                }
            }
        });
    }

    function renderLowStock(products) {
        var wrap = document.getElementById('low-stock-table');
        if (!wrap) return;

        if (!products || products.length === 0) {
            wrap.innerHTML = '<p class="brikpanel-dash-empty">' + (i18n.all_stocked || 'All products are sufficiently stocked') + '</p>';
            return;
        }

        var html = '<table class="brikpanel-dash-table"><thead><tr>' +
            '<th>' + (i18n.product || 'Product') + '</th>' +
            '<th>' + (i18n.sku || 'SKU') + '</th>' +
            '<th>' + (i18n.stock || 'Remaining') + '</th>' +
            '</tr></thead><tbody>';

        products.forEach(function (p) {
            var nameCell = p.edit_url
                ? '<a href="' + escapeHtml(p.edit_url) + '" style="color:#303030;text-decoration:none;font-weight:500;">' + escapeHtml(p.name) + '</a>'
                : escapeHtml(p.name);
            html += '<tr>' +
                '<td>' + nameCell + '</td>' +
                '<td class="brikpanel-dash-muted">' + (p.sku ? escapeHtml(p.sku) : '&mdash;') + '</td>' +
                '<td><span class="brikpanel-dash-badge-warning">' + p.stock + '</span></td>' +
                '</tr>';
        });

        html += '</tbody></table>';
        wrap.innerHTML = html;
    }

    function renderLtvPanel(data) {
        var wrap = document.getElementById('brikpanel-ltv-panel');
        if (!wrap) return;

        if (!data || !data.total_customers) {
            wrap.innerHTML = '<p class="brikpanel-dash-empty">' + (i18n.ltv_empty || 'Customer metrics will appear after the nightly recompute.') + '</p>';
            return;
        }

        var html = '<div class="brikpanel-dash-ltv">' +
            '<div class="brikpanel-dash-ltv-headline">' +
                '<span class="brikpanel-dash-ltv-value">' + data.avg_ltv + '</span>' +
                '<span class="brikpanel-dash-ltv-label">' + (i18n.average_ltv || 'Average LTV') + '</span>' +
            '</div>' +
            '<table class="brikpanel-dash-table brikpanel-dash-returns-breakdown"><tbody>' +
                '<tr><td>' + (i18n.total_customers || 'Total customers') + '</td><td><strong>' + formatNumber(data.total_customers) + '</strong></td></tr>' +
                '<tr><td>' + (i18n.repeat_customers || 'Repeat customers') + '</td><td><strong>' + formatNumber(data.repeat_customers) + ' (' + data.repeat_rate + '%)</strong></td></tr>' +
                '<tr><td>' + (i18n.total_lifetime_value || 'Total lifetime value') + '</td><td><strong>' + data.total_ltv + '</strong></td></tr>' +
                '<tr><td>' + (i18n.top_customer_ltv || 'Top customer') + '</td><td><strong>' + data.max_ltv + '</strong></td></tr>' +
            '</tbody></table>' +
        '</div>';

        wrap.innerHTML = html;
    }

    function renderSubscriptions(data) {
        var wrap = document.getElementById('brikpanel-subscriptions-wrap');
        if (!wrap) return;

        if (!data || !data.length) {
            wrap.innerHTML = '<p class="brikpanel-dash-empty">' + (i18n.no_data || 'No data for this period') + '</p>';
            return;
        }

        // Status → icon + accent color (monochrome palette with subtle tints)
        var statusMeta = {
            'wc-active':         { icon: '●', cls: 'brikpanel-subs-card--active' },
            'wc-on-hold':        { icon: '◐', cls: 'brikpanel-subs-card--hold' },
            'wc-cancelled':      { icon: '✕', cls: 'brikpanel-subs-card--cancelled' },
            'wc-expired':        { icon: '○', cls: 'brikpanel-subs-card--expired' },
            'wc-pending':        { icon: '◌', cls: 'brikpanel-subs-card--pending' },
            'wc-pending-cancel': { icon: '◔', cls: 'brikpanel-subs-card--pending-cancel' }
        };

        var total = 0;
        for (var j = 0; j < data.length; j++) { total += data[j].count; }

        var html = '<div class="brikpanel-subs-total">';
        html += '<span class="brikpanel-subs-total-num">' + formatNumber(total) + '</span>';
        html += '<span class="brikpanel-subs-total-label">' + (i18n.total_subscriptions || 'total subscriptions') + '</span>';
        html += '</div><div class="brikpanel-subs-cards">';

        for (var i = 0; i < data.length; i++) {
            var item = data[i];
            var meta = statusMeta[item.status] || { icon: '·', cls: '' };
            var pct = total > 0 ? Math.round((item.count / total) * 100) : 0;
            html += '<div class="brikpanel-subs-card ' + meta.cls + '">';
            html += '<span class="brikpanel-subs-card-icon">' + meta.icon + '</span>';
            html += '<span class="brikpanel-subs-card-count">' + formatNumber(item.count) + '</span>';
            html += '<span class="brikpanel-subs-card-label">' + escHtml(item.label) + '</span>';
            if (total > 0) {
                html += '<span class="brikpanel-subs-card-pct">' + pct + '%</span>';
            }
            html += '</div>';
        }

        html += '</div>';
        wrap.innerHTML = html;
    }

    function escHtml(str) {
        return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
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
        img.alt = i18n.globe_alt || 'Order Locations Globe';
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

    // =========================================================================
    // LOCATION VIEW TOGGLE
    // =========================================================================

    function initLocTabs() {
        // Scope to the locations panel so the device-panel tabs (which reuse
        // the same .brikpanel-loc-tab class) don't get bound here too.
        var tabs = document.querySelectorAll('.brikpanel-loc-tab[data-view]');
        tabs.forEach(function (btn) {
            btn.addEventListener('click', function () {
                var view = this.getAttribute('data-view');
                if (view === locView) return;
                locView = view;

                tabs.forEach(function (t) { t.classList.remove('brikpanel-loc-tab--active'); });
                this.classList.add('brikpanel-loc-tab--active');

                if (locationsData) {
                    applyLocView(view);
                }
            });
        });
    }

    // =========================================================================
    // DEVICE VIEW TOGGLE (visitors / orders inside the same panel)
    // =========================================================================

    function initDeviceTabs() {
        var tabs = document.querySelectorAll('.brikpanel-device-tab');
        tabs.forEach(function (btn) {
            btn.addEventListener('click', function () {
                var view = this.getAttribute('data-device-view');
                if (view === deviceView) return;
                deviceView = view;

                tabs.forEach(function (t) { t.classList.remove('brikpanel-loc-tab--active'); });
                this.classList.add('brikpanel-loc-tab--active');

                applyDeviceView(view);
            });
        });
    }

    function applyDeviceView(view) {
        var title = document.getElementById('brikpanel-device-title');
        if (title) {
            title.textContent = view === 'orders'
                ? (i18n.device_title_orders   || 'Orders by Device')
                : (i18n.device_title_visitors || 'Visitors by Device');
        }
        renderDevices(deviceData[view]);
    }

    function applyLocView(view) {
        if (!locationsData) return;

        var countField = view === 'customers' ? 'customers' : 'count';

        // Sort copies by the active metric so globe hub + bar rankings reflect the chosen view.
        var sortByMetric = function (a, b) { return (b[countField] || 0) - (a[countField] || 0); };
        var countries = (locationsData.countries || []).slice().sort(sortByMetric);
        var cities    = (locationsData.cities    || []).slice().sort(sortByMetric);

        var maxVal = countries.length ? (countries[0][countField] || 0) : 0;

        // Rebuild global marker arrays
        globeMarkers = [];
        globeMarkersData = [];
        countries.forEach(function (c) {
            var coords = COUNTRY_COORDS[c.code];
            if (!coords) return;
            var val = c[countField] || 0;
            var cCities = cities.filter(function (ci) { return ci.country === c.code; });
            globeMarkers.push({
                location: [coords[0], coords[1]],
                size: Math.max(0.015, maxVal > 0 ? (val / maxVal) * 0.03 : 0.015),
                id: 'marker-' + c.code
            });
            globeMarkersData.push({
                country: c.name,
                code: c.code,
                orders: c.count,
                customers: c.customers || 0,
                total: c.total || '',
                lat: coords[0],
                lon: coords[1],
                cities: cCities
            });
        });

        // Rebuild globe with new marker sizes (always recreate to update arc routing too)
        if (globeMarkers.length > 0) {
            createGlobeInstance();
        } else if (globeInstance) {
            globeInstance.destroy();
            globeInstance = null;
        }

        // Update titles
        var globeTitle = document.getElementById('globe-panel-title');
        var countriesTitle = document.getElementById('loc-panel-countries-title');
        var citiesTitle = document.getElementById('loc-panel-cities-title');
        if (globeTitle) globeTitle.textContent = view === 'customers' ? (i18n.loc_cust_locations || 'Customer Locations') : (i18n.loc_order_locations || 'Order Locations');
        if (countriesTitle) countriesTitle.textContent = view === 'customers' ? (i18n.loc_top_countries_customers || 'Top Countries by Customers') : (i18n.loc_top_countries_orders || 'Top Countries by Orders');
        if (citiesTitle) citiesTitle.textContent = view === 'customers' ? (i18n.loc_top_cities_customers || 'Top Cities by Customers') : (i18n.loc_top_cities_orders || 'Top Cities by Orders');

        renderTopCountries(countries, view);
        renderTopCities(cities, view);
    }

    function renderTopCountries(countries, view) {
        var wrap = document.getElementById('top-countries-table');
        if (!wrap) return;

        if (!countries || countries.length === 0) {
            wrap.innerHTML = '<p class="brikpanel-dash-empty">' + (i18n.no_data || 'No data for this period') + '</p>';
            return;
        }

        var isCustomers = view === 'customers';
        var countField  = isCustomers ? 'customers' : 'count';
        var unitLabel   = isCustomers ? (i18n.customers || 'customers') : (i18n.orders || 'orders');
        var maxCount    = 0;
        countries.forEach(function (c) { if ((c[countField] || 0) > maxCount) maxCount = c[countField] || 0; });

        var html = '<div class="brikpanel-country-list">';

        countries.slice(0, 5).forEach(function (c) {
            var val = c[countField] || 0;
            var pct = maxCount > 0 ? Math.round((val / maxCount) * 100) : 0;
            html += '<div class="brikpanel-country-row">' +
                '<div class="country-flag">' + countryFlag(c.code) + '</div>' +
                '<div class="country-info">' +
                    '<div class="country-header">' +
                        '<span class="country-name">' + escapeHtml(c.name) + '</span>' +
                        (!isCustomers ? '<span class="country-total">' + (c.total || '') + '</span>' : '') +
                    '</div>' +
                    '<div class="country-bar-wrap">' +
                        '<div class="country-bar" style="width:' + pct + '%"></div>' +
                    '</div>' +
                    '<div class="country-meta">' + formatNumber(val) + ' ' + unitLabel + '</div>' +
                '</div>' +
            '</div>';
        });

        html += '</div>';
        wrap.innerHTML = html;
    }

    function renderTopCities(cities, view) {
        var wrap = document.getElementById('top-cities-table');
        if (!wrap) return;

        if (!cities || cities.length === 0) {
            wrap.innerHTML = '<p class="brikpanel-dash-empty">' + (i18n.no_data || 'No data for this period') + '</p>';
            return;
        }

        var isCustomers = view === 'customers';
        var countField  = isCustomers ? 'customers' : 'count';
        var unitLabel   = isCustomers ? (i18n.loc_customers || 'Customers') : (i18n.loc_orders || 'Orders');

        var html = '<table class="brikpanel-dash-table"><thead><tr>' +
            '<th>#</th><th>' + (i18n.city || 'City') + '</th><th>' + unitLabel + '</th>' +
            '</tr></thead><tbody>';

        cities.slice(0, 5).forEach(function (c, i) {
            html += '<tr><td class="rank">' + (i + 1) + '</td>' +
                '<td>' + escapeHtml(c.name) + '</td>' +
                '<td>' + formatNumber(c[countField] || 0) + '</td></tr>';
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
    // MARKETPLACE ANALYTICS (BrikMarket)
    //
    // Only fires when the server-side payload includes a `marketplace` key,
    // which the dashboard PHP only emits when BrikMarket is active. Updates
    // four KPI cards, the per-marketplace list, the share donut, the top
    // categories table, and the marketplace top-products table.
    // =========================================================================

    function renderMarketplaceAnalytics(mp) {
        var section = document.getElementById('brikpanel-marketplace-section');
        if (!section) return;
        if (!mp) {
            section.style.display = 'none';
            return;
        }
        section.style.display = '';

        // KPI cards.
        var totals = mp.totals || {};
        updateCard('card-mp-sales',  totals.revenue_html || '--');
        updateCard('card-mp-orders', formatNumber(totals.orders || 0));
        updateCard('card-mp-aov',    totals.aov_html || '--');
        updateCard('card-mp-share',  (totals.share_total_pct || 0) + '%');

        var deltas = mp.deltas || {};
        updateDelta('delta-mp-sales',  deltas.revenue);
        updateDelta('delta-mp-orders', deltas.orders);
        updateDelta('delta-mp-aov',    deltas.aov);

        var shareDelta = document.getElementById('delta-mp-share');
        if (shareDelta) {
            // Static caption: site + marketplace combined revenue, formatted server-side.
            shareDelta.classList.remove('positive', 'negative', 'neutral');
            shareDelta.classList.add('neutral');
            shareDelta.innerHTML = (i18n.mp_of_total || 'of') + ' ' + (totals.combined_revenue_html || '') + ' ' + (i18n.mp_combined || 'combined');
        }

        // Per-marketplace list.
        var listEl = document.getElementById('brikpanel-mp-list');
        if (listEl) {
            var rows = mp.by_marketplace || [];
            if (rows.length === 0) {
                listEl.innerHTML = '<p class="brikpanel-dash-empty">' + (i18n.no_data || 'No data for this period') + '</p>';
            } else {
                var html = '';
                rows.forEach(function (row) {
                    var initial = (row.label || '?').charAt(0).toUpperCase();
                    var deltaClass = row.delta_revenue > 0 ? 'positive' : (row.delta_revenue < 0 ? 'negative' : 'neutral');
                    var deltaText  = row.delta_revenue === 0 ? '--' : (row.delta_revenue > 0 ? '+' : '') + row.delta_revenue + '%';
                    var cats = '';
                    if (row.top_categories && row.top_categories.length) {
                        cats = '<div class="brikpanel-dash-mp-cats">';
                        row.top_categories.forEach(function (c) {
                            cats += '<span class="brikpanel-dash-mp-cat">' + escapeHtml(c.name) + '</span>';
                        });
                        cats += '</div>';
                    }

                    html +=
                        '<div class="brikpanel-dash-mp-item">' +
                          '<div class="brikpanel-dash-mp-item-head">' +
                            '<span class="brikpanel-dash-mp-badge" style="background:' + escapeHtml(row.color) + ';">' + escapeHtml(initial) + '</span>' +
                            '<span class="brikpanel-dash-mp-name">' + escapeHtml(row.label) + '</span>' +
                            '<span class="brikpanel-dash-mp-share">' + row.revenue_share + '%</span>' +
                          '</div>' +
                          '<div class="brikpanel-dash-mp-bar"><span style="width:' + Math.min(100, row.revenue_share) + '%;background:' + escapeHtml(row.color) + ';"></span></div>' +
                          '<div class="brikpanel-dash-mp-stats">' +
                            '<div><span>' + (i18n.revenue || 'Revenue') + '</span><strong>' + row.revenue_html + '</strong></div>' +
                            '<div><span>' + (i18n.orders || 'Orders') + '</span><strong>' + formatNumber(row.orders) + '</strong></div>' +
                            '<div><span>' + (i18n.aov || 'AOV') + '</span><strong>' + row.aov_html + '</strong></div>' +
                            '<div><span>' + (i18n.vs_prev || 'vs. prev') + '</span><strong class="brikpanel-dash-mp-delta ' + deltaClass + '">' + deltaText + '</strong></div>' +
                          '</div>' +
                          cats +
                        '</div>';
                });
                listEl.innerHTML = html;
            }
        }

        // Share donut.
        renderMarketplaceShareChart(mp.by_marketplace || []);

        // Top categories.
        var catEl = document.getElementById('brikpanel-mp-categories');
        if (catEl) {
            var cats = mp.categories || [];
            if (cats.length === 0) {
                // Categories require marketplace items to be linked to a WC
                // product (via _product_id or _marketplace_sku). Make the
                // empty state explain that — generic "No data" is misleading
                // when the user has marketplace orders but no mapped catalog.
                catEl.innerHTML = '<p class="brikpanel-dash-empty">' + (i18n.mp_no_categories || 'No category data — marketplace items must be linked to your WooCommerce catalog (by product or SKU) for category breakdown to appear.') + '</p>';
            } else {
                var ch = '<table class="brikpanel-dash-table"><thead><tr>' +
                    '<th>#</th>' +
                    '<th>' + (i18n.category || 'Category') + '</th>' +
                    '<th>' + (i18n.orders || 'Orders') + '</th>' +
                    '<th>' + (i18n.revenue || 'Revenue') + '</th>' +
                    '<th>' + (i18n.share || 'Share') + '</th>' +
                    '</tr></thead><tbody>';
                cats.forEach(function (c, i) {
                    ch += '<tr>' +
                        '<td class="rank">' + (i + 1) + '</td>' +
                        '<td>' + escapeHtml(c.name) + '</td>' +
                        '<td>' + formatNumber(c.orders) + '</td>' +
                        '<td>' + c.revenue_html + '</td>' +
                        '<td>' + c.share + '%</td>' +
                        '</tr>';
                });
                ch += '</tbody></table>';
                catEl.innerHTML = ch;
            }
        }

        // Top products.
        var prEl = document.getElementById('brikpanel-mp-products');
        if (prEl) {
            var products = mp.top_products || [];
            if (products.length === 0) {
                prEl.innerHTML = '<p class="brikpanel-dash-empty">' + (i18n.no_data || 'No data for this period') + '</p>';
            } else {
                var ph = '<table class="brikpanel-dash-table"><thead><tr>' +
                    '<th>#</th>' +
                    '<th>' + (i18n.product || 'Product') + '</th>' +
                    '<th>' + (i18n.source || 'Source') + '</th>' +
                    '<th>' + (i18n.qty_sold || 'Qty') + '</th>' +
                    '<th>' + (i18n.revenue || 'Revenue') + '</th>' +
                    '</tr></thead><tbody>';
                products.forEach(function (p, i) {
                    ph += '<tr>' +
                        '<td class="rank">' + (i + 1) + '</td>' +
                        '<td>' + escapeHtml(p.name) + '</td>' +
                        '<td><span class="brikpanel-dash-source" style="background:' + escapeHtml(p.marketplace_color) + ';">' + escapeHtml(p.marketplace_label) + '</span></td>' +
                        '<td>' + formatNumber(p.qty) + '</td>' +
                        '<td>' + p.revenue_html + '</td>' +
                        '</tr>';
                });
                ph += '</tbody></table>';
                prEl.innerHTML = ph;
            }
        }
    }

    function renderMarketplaceShareChart(rows) {
        var canvas = document.getElementById('brikpanel-mp-share-chart');
        if (!canvas || typeof Chart === 'undefined') return;

        var labels = rows.map(function (r) { return r.label; });
        var data   = rows.map(function (r) { return r.revenue; });
        var colors = rows.map(function (r) { return r.color; });

        if (mpShareChart) {
            mpShareChart.data.labels = labels;
            mpShareChart.data.datasets[0].data = data;
            mpShareChart.data.datasets[0].backgroundColor = colors;
            mpShareChart.update();
            return;
        }

        var ctx = canvas.getContext('2d');
        mpShareChart = new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: labels,
                datasets: [{
                    data: data,
                    backgroundColor: colors,
                    borderColor: '#ffffff',
                    borderWidth: 2,
                    hoverOffset: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '62%',
                plugins: {
                    legend: {
                        position: 'right',
                        labels: { boxWidth: 12, padding: 10, font: { size: 12 } }
                    },
                    tooltip: {
                        callbacks: {
                            label: function (ctx) {
                                var total = ctx.dataset.data.reduce(function (a, b) { return a + b; }, 0);
                                var pct   = total > 0 ? ((ctx.parsed / total) * 100).toFixed(1) : 0;
                                return ctx.label + ': ' + pct + '%';
                            }
                        }
                    }
                }
            }
        });
    }

    // =========================================================================
    // PERIOD SUBTITLE (which dates / how long)
    // =========================================================================

    function renderPeriod(period) {
        var box = document.getElementById('brikpanel-dash-period');
        if (!box) return;
        var textEl = box.querySelector('.brikpanel-dash-period-text');
        if (!textEl) return;
        if (period && period.text) {
            textEl.textContent = period.text;
        } else {
            textEl.textContent = (i18n.period_loading || 'Loading…');
        }
    }

    // =========================================================================
    // EXPORT EXCEL (current date-range report)
    // =========================================================================

    function initExportButton() {
        var btn = document.getElementById('brikpanel-export-xlsx');
        if (!btn) return;
        btn.addEventListener('click', function () {
            if (!CFG.export_url || !CFG.export_nonce) return;

            // Custom range needs both dates resolved before we can export.
            if (currentRange === 'custom' && (!customStartDate || !customEndDate)) {
                window.alert(i18n.export_select_dates || 'Pick a custom date range first.');
                return;
            }

            var labelEl = btn.querySelector('.brikpanel-dash-export-label');
            var original = labelEl ? labelEl.textContent : '';
            if (labelEl) labelEl.textContent = (i18n.export_preparing || 'Preparing…');
            btn.disabled = true;

            var params = new URLSearchParams();
            params.set('action', 'brikpanel_dashboard_export');
            params.set('brikpanel_export_nonce', CFG.export_nonce);
            params.set('range', currentRange);
            if (currentRange === 'custom') {
                params.set('start_date', customStartDate);
                params.set('end_date', customEndDate);
            }

            // Canonical single-request download: a transient <a download>.
            // The server replies with Content-Disposition: attachment, so the
            // browser streams the file without navigating away — the dashboard
            // stays put and the (expensive) export query runs exactly once.
            var a = document.createElement('a');
            a.href = CFG.export_url + '?' + params.toString();
            a.download = '';
            a.style.display = 'none';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);

            setTimeout(function () {
                if (labelEl) labelEl.textContent = original || (i18n.export_button || 'Export Excel');
                btn.disabled = false;
            }, 2500);
        });
    }

    // =========================================================================
    // COPY EVERYTHING (store summary)
    // =========================================================================

    function initCopySummary() {
        var btn = document.getElementById('brikpanel-copy-summary');
        if (!btn) return;
        btn.addEventListener('click', handleCopySummary);
    }

    function handleCopySummary() {
        var btn = document.getElementById('brikpanel-copy-summary');
        if (!btn || btn.classList.contains('is-loading')) return;

        var labelEl    = btn.querySelector('.brikpanel-dash-copy-label');
        var progressEl = btn.querySelector('.brikpanel-dash-copy-progress > span');
        var originalLabel = labelEl ? labelEl.textContent : '';

        btn.classList.add('is-loading');
        btn.classList.remove('is-success', 'is-error');
        btn.disabled = true;
        if (labelEl)    labelEl.textContent = (i18n.summary_collecting || 'Collecting data…');
        if (progressEl) progressEl.style.width = '15%';

        // Indeterminate-ish progress bar that creeps toward 90% until the AJAX
        // resolves. Good signal for stores where aggregation takes 5–15s.
        var progress = 15;
        var ticker = setInterval(function () {
            progress = Math.min(90, progress + Math.max(1, (90 - progress) * 0.08));
            if (progressEl) progressEl.style.width = progress + '%';
        }, 250);

        var formData = new FormData();
        formData.append('action', 'brikpanel_generate_store_summary');
        formData.append('security', CFG.nonce || '');

        fetch(CFG.ajax_url, {
            method: 'POST',
            credentials: 'same-origin',
            body: formData
        })
        .then(function (res) { return res.json(); })
        .then(function (json) {
            clearInterval(ticker);
            if (!json || !json.success || !json.data || !json.data.markdown) {
                throw new Error((json && json.data && json.data.message) || 'AJAX failed');
            }
            if (progressEl) progressEl.style.width = '100%';
            return copyToClipboard(json.data.markdown).then(function () { return json.data; });
        })
        .then(function (data) {
            btn.classList.remove('is-loading');
            btn.classList.add('is-success');
            if (labelEl) {
                var bytes = data.bytes || 0;
                var sizeStr = bytes > 1024 ? (bytes / 1024).toFixed(1) + ' KB' : bytes + ' B';
                labelEl.textContent = (i18n.summary_copied || 'Copied!') + ' (' + sizeStr + ')';
            }
            setTimeout(function () { resetCopyButton(originalLabel); }, 3000);
        })
        .catch(function (err) {
            clearInterval(ticker);
            console.error('[BrikPanel] Store summary failed:', err);
            btn.classList.remove('is-loading');
            btn.classList.add('is-error');
            if (labelEl) labelEl.textContent = (i18n.summary_failed || 'Failed — try again');
            if (progressEl) progressEl.style.width = '0%';
            setTimeout(function () { resetCopyButton(originalLabel); }, 3500);
        });
    }

    function resetCopyButton(originalLabel) {
        var btn = document.getElementById('brikpanel-copy-summary');
        if (!btn) return;
        btn.disabled = false;
        btn.classList.remove('is-loading', 'is-success', 'is-error');
        var labelEl    = btn.querySelector('.brikpanel-dash-copy-label');
        var progressEl = btn.querySelector('.brikpanel-dash-copy-progress > span');
        if (labelEl)    labelEl.textContent = originalLabel || (i18n.summary_button || 'Copy everything');
        if (progressEl) progressEl.style.width = '0%';
    }

    function copyToClipboard(text) {
        // Modern clipboard API requires a secure context (https or localhost).
        if (navigator.clipboard && window.isSecureContext) {
            return navigator.clipboard.writeText(text);
        }
        // Fallback for older browsers / non-HTTPS admin URLs.
        return new Promise(function (resolve, reject) {
            try {
                var ta = document.createElement('textarea');
                ta.value = text;
                ta.style.position = 'fixed';
                ta.style.left = '-9999px';
                ta.style.top = '0';
                document.body.appendChild(ta);
                ta.focus();
                ta.select();
                var ok = document.execCommand('copy');
                document.body.removeChild(ta);
                ok ? resolve() : reject(new Error('execCommand failed')); // i18n-ignore: internal Error thrown into devtools/promise chain, not user-facing
            } catch (e) { reject(e); }
        });
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
