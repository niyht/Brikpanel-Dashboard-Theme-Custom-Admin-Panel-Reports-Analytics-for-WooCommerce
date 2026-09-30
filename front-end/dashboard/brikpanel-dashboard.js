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

    // Brief confirmation in the top-right corner. Callers pass text that was
    // already translated server-side; this only places it.
    function showToast(msg, type) {
        if (!msg) return;
        var t = document.createElement('div');
        t.className = 'brikpanel-dash-toast brikpanel-dash-toast--' + (type || 'success');
        t.setAttribute('role', 'status');
        t.textContent = msg;
        document.body.appendChild(t);
        void t.offsetHeight; // force reflow so the transition runs
        t.classList.add('is-visible');
        setTimeout(function () {
            t.classList.remove('is-visible');
            setTimeout(function () { t.parentNode && t.parentNode.removeChild(t); }, 350);
        }, 3500);
    }

    // Inbound control channel for dashboard add-ons (Ad Platforms today).
    //
    // This file already BROADCASTS its payload on document as
    // `brikpanel:dashboardData` (see fetchDashboardData), so these two
    // listeners are the return leg of a channel that already exists: a module
    // living in its own inline <script> can ask for a refetch or a toast
    // without this IIFE exporting anything onto `window`. In wp-admin `window`
    // is shared with WooCommerce, Gutenberg and every other plugin, so a
    // global here would be a permanent collision surface and a permanent API
    // promise for a plugin that ships to wordpress.org.
    //
    // Registered at IIFE scope rather than inside DOMContentLoaded so an early
    // dispatch is never missed, and deliberately fire-and-forget: a module
    // that loads before this file gets a silent no-op, where a call on an
    // undefined global would throw and take the rest of its handler with it.
    //
    // fetchDashboardData and showToast are function declarations, so both are
    // hoisted and safe to reference from up here.
    document.addEventListener('brikpanel:refresh', function () {
        fetchDashboardData();
    });

    // detail: { message: <already-translated string>, type: 'success' | 'error' }
    // The text is translated server-side by whoever dispatches it; this only
    // places it, exactly like every other caller of showToast.
    document.addEventListener('brikpanel:toast', function (e) {
        var d = (e && e.detail) ? e.detail : {};
        showToast(d.message, d.type);
    });

    // State. The range is seeded from the user's remembered selection (see
    // Brikpanel_Dashboard::get_range_preference) so a refresh, or leaving the
    // dashboard and coming back, resumes the period the user actually picked
    // rather than resetting to "Today". Server-validated; 'today' when unset.
    let currentRange = CFG.saved_range || 'today';
    let customStartDate = CFG.saved_start || '';
    let customEndDate = CFG.saved_end || '';
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
    let deviceView = 'visitors';  // 'visitors' | 'orders' | 'sources' — tab inside the device panel
    let deviceData = { visitors: null, orders: null }; // cached payloads for re-render on tab switch
    let sourceData = null;        // cached traffic-source channel breakdown
    let topReferrersData = null;  // cached top referrers list
    var prefersReducedMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    let datepickerInstance = null;
    let isLoading = false;
    let currentFetchController = null; // aborts an in-flight request when a newer one starts
    let fetchSeq = 0;                  // sequence token so stale responses can't overwrite newer ones
    // From the last payload, for the empty states: store-wide facts (any
    // order ever, the latest paid day, any visit ever), visitor tracking
    // settings, the window shown, whether that window has paid orders and its
    // visitor count. See emptyReason().
    let emptyCtx = { store: null, tracking: null, period: null, paid: null, visitors: 0 };
    let liveListEmpty = false;         // the Live list last showed nobody
    let liveStale = false;             // the server refused the Live request; polling stopped

    // Numbers, percentages, money and dates in the store's format, the percent
    // sign where the viewer's language writes it (front-end/shared/
    // brikpanel-format.js, field test E2/E9). The browser's language used to
    // decide ("1.234" on a Turkish computer, "0% gelirin" in Turkish).
    var BF = window.brikpanelFormat || null;
    function fmtPct(v, decimals) {
        return BF ? BF.percent(v, decimals == null ? 1 : decimals) : String(Number(v) || 0);
    }
    function fillText(pattern, value) {
        return BF ? BF.fill(pattern || '%s', value) : String(value);
    }

    // Chart.js defaults
    if (typeof Chart !== 'undefined') {
        Chart.defaults.font.family = '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif';
        Chart.defaults.font.size = 12;
        Chart.defaults.color = '#616161';
        if (BF) BF.chart(Chart);
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
        initAddExpense();
        initRemoveExpense();
        initEmptyStates();
        initNewStoreGuide();
        fetchDashboardData();
        startLivePolling();

        // Pause polling when tab is hidden. Coming back refreshes the live
        // count at once, but only reloads the whole dashboard when the tab was
        // away long enough for the numbers to have moved: flicking between
        // tabs used to rebuild the full payload on every switch.
        var hiddenAt = 0;
        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState === 'hidden') {
                hiddenAt = Date.now();
                stopLivePolling();
            } else {
                startLivePolling();
                if (!hiddenAt || Date.now() - hiddenAt >= 60000) {
                    fetchDashboardData();
                }
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

        // The calendar in the viewer's language from the store's first
        // weekday; the field shows the range in the store's short date format
        // ("1 Eyl 2026 - 22 Eyl 2026") while the picker itself keeps Y-m-d.
        var pickerOptions = {
            mode: 'range',
            dateFormat: 'Y-m-d',
            altInput: true,
            altInputClass: 'brikpanel-dash-range-field',
            maxDate: 'today',
            onOpen: function (selectedDates, dateStr, instance) {
                // Start every reopen fresh — whether the picker was opened via the
                // "Custom" preset button or by clicking the date field directly.
                // Otherwise a previous range stays highlighted and anchored to its
                // (possibly year-old) month, and the first click only resets the
                // selection to a single date, which is confusing to pick a new range from.
                if (instance.selectedDates.length) {
                    instance.clear();
                }
            },
            onClose: function (selectedDates) {
                if (!selectedDates.length) return;

                var fmt = function (dt) {
                    return dt.getFullYear() + '-' +
                        String(dt.getMonth() + 1).padStart(2, '0') + '-' +
                        String(dt.getDate()).padStart(2, '0');
                };

                // A single picked day is treated as a one-day range (start = end),
                // so selecting one date works instead of being silently ignored.
                customStartDate = fmt(selectedDates[0]);
                customEndDate = fmt(selectedDates[selectedDates.length - 1]);

                currentRange = 'custom';
                fetchDashboardData();
            }
        };
        datepickerInstance = BF ? BF.datePicker(input, pickerOptions) : flatpickr(input, pickerOptions);

        // Restore a remembered custom range into the field so it reads the same
        // as when it was picked. `false` = do not fire onChange, this is state
        // restoration, not a new selection.
        if (currentRange === 'custom' && customStartDate && customEndDate) {
            datepickerInstance.setDate([customStartDate, customEndDate], false);
        }
    }

    // =========================================================================
    // FETCH DASHBOARD DATA (Single batch call)
    // =========================================================================

    function fetchDashboardData() {
        // A new selection must always supersede an in-flight request rather than
        // being silently dropped. The old `if (isLoading) return;` guard meant
        // that picking a fresh range while a slow (e.g. year-wide) query was
        // still loading left the dashboard stuck on the previous range's data.
        // Abort the previous request and tag this one so a stale, late-arriving
        // response can never overwrite the most recent selection.
        if (currentFetchController) {
            currentFetchController.abort();
        }
        var controller = (typeof AbortController !== 'undefined') ? new AbortController() : null;
        currentFetchController = controller;
        var seq = ++fetchSeq;

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
            credentials: 'same-origin',
            signal: controller ? controller.signal : undefined
        })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            // Ignore a response that a newer request has already superseded.
            if (seq !== fetchSeq) return;

            isLoading = false;
            currentFetchController = null;
            setLoadingState(false);

            if (!res.success) return;
            var d = res.data;

            // What the empty cards below explain themselves with.
            emptyCtx.store    = d.store || null;
            emptyCtx.tracking = d.tracking || null;
            emptyCtx.period   = d.period || null;
            emptyCtx.paid     = windowPaid(d);
            emptyCtx.visitors = Number(d.visitor_count) || 0;

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
            // The server sends the rate as a finished percentage (store
            // separators, the viewer's sign position); a payload cached before
            // that still carries the bare number.
            updateCard('card-conversion', d.conversion_rate_pct != null ? d.conversion_rate_pct : fmtPct(d.conversion_rate, 2));

            // Deltas
            updateDelta('delta-total-sales', d.deltas.sales);
            updateDelta('delta-orders', d.deltas.orders);
            updateDelta('delta-aov', d.deltas.aov);
            updateDelta('delta-visitors', d.deltas.visitors);
            updateDelta('delta-conversion', d.deltas.conversion);

            // Units sold in the same paid orders: beside the Orders change
            // and at the head of the Order Rates card.
            setItemsSold('card-items-sold', d.items_sold_label);
            setItemsSold('rates-items-sold', d.items_sold_label);

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
            // Traffic sources live as a third tab in the same panel.
            sourceData       = d.sources;
            topReferrersData = d.top_referrers;
            applyDeviceView(deviceView);
            renderCustomerTypes(d.customer_types);

            // RFM segment distribution (precomputed nightly).
            renderRfmSegments(d.rfm_distribution || []);

            // Low stock + LTV summary panel (Returns & Refunds % is now
            // surfaced in the Order Rates donut alongside cancelled/failed).
            renderLowStock(d.low_stock, d.low_stock_empty);
            renderLtvPanel(d.ltv_panel);

            // Subscriptions.
            renderSubscriptions(d.subscription_stats);

            // Marketplace analytics (BrikMarket-only).
            renderMarketplaceAnalytics(d.marketplace);

            // An empty Live list drawn before this data arrived can now say
            // whether tracking is off. A stopped list keeps its reload line.
            if (liveListEmpty && !liveStale) renderLiveVisitors([]);
        })
        .catch(function (err) {
            // An aborted request is expected (a newer selection took over); it
            // must not clear the loading state belonging to the newer request.
            if (err && err.name === 'AbortError') return;
            if (seq !== fetchSeq) return;
            isLoading = false;
            currentFetchController = null;
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

    // "5,361 items sold" comes ready from the server (plural form and number
    // format). Nothing sold, or a payload cached before the key existed,
    // hides the line instead of printing a blank or "undefined".
    function setItemsSold(id, label) {
        var el = document.getElementById(id);
        if (!el) return;
        var text = typeof label === 'string' ? label : '';
        // Inside <bdi> the phrase keeps its own reading order on a
        // right-to-left screen ("10 items sold", not "items sold 10"), while
        // the box and the dot before it stay on the page's side.
        var phrase = document.createElement('bdi');
        phrase.textContent = text;
        el.textContent = '';
        el.appendChild(phrase);
        el.hidden = text === '';
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
            var times = Math.round((abs / 100 + 1) * 10) / 10;
            return (BF ? BF.number(times, 1, true) : String(times)) + '\u00d7';
        }
        if (abs >= 100) {
            return fmtPct(Math.round(abs), 0);
        }
        return fmtPct(abs, 1);
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
        if (n === null || n === undefined) n = 0;
        return BF ? BF.number(n) : String(Number(n) || 0);
    }

    // =========================================================================
    // EMPTY STATES (field test F1: say why a card is empty)
    // =========================================================================

    var DAY_MS = 86400000;

    // A 'Y-m-d' as a UTC day stamp, so days compare and subtract exactly.
    function ymdStamp(ymd) {
        var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(ymd || ''));
        return m ? Date.UTC(Number(m[1]), Number(m[2]) - 1, Number(m[3])) : NaN;
    }

    // The store's today, read from the window the server resolved: the
    // preset windows end today, "Yesterday" the day before. NaN for a custom
    // window, which says nothing about today.
    function storeTodayStamp(period) {
        if (!period) return NaN;
        var end = ymdStamp(period.to_iso);
        if (period.range === 'yesterday') return end + DAY_MS;
        return ['today', '7days', '30days', '90days'].indexOf(period.range) !== -1 ? end : NaN;
    }

    // The smallest preset whose window holds the given day, to offer as a
    // one-click way to it. None from the widest preset or a custom window.
    function presetFor(day, period) {
        if (!period || period.range === '90days' || period.range === 'custom') return '';
        var today = storeTodayStamp(period);
        var stamp = ymdStamp(day);
        if (isNaN(today) || isNaN(stamp) || stamp > today) return '';
        var spans = [['7days', 7], ['30days', 30], ['90days', 90]];
        for (var i = 0; i < spans.length; i++) {
            if (today - stamp <= (spans[i][1] - 1) * DAY_MS) {
                return spans[i][0] === period.range ? '' : spans[i][0];
            }
        }
        return '';
    }

    // "Sep 24", with the year when it is not this year's.
    function emptyDayLabel(day, period) {
        if (!BF) return String(day);
        var sameYear = period && String(period.to_iso || '').slice(0, 4) === String(day).slice(0, 4);
        return sameYear ? BF.dayMonth(day) : BF.date(day);
    }

    // Whether the window shown has paid orders, from the window's own counts.
    // 'site' is the Orders card (marketplace orders left out while BrikMarket
    // is active); 'all' adds the window's marketplace orders, the marketplace
    // section's total (no section without BrikMarket, when the Orders card
    // already counts every order).
    function windowPaid(d) {
        var site = Number(d.order_count) > 0;
        var mp = !!(d.marketplace && d.marketplace.totals) && Number(d.marketplace.totals.orders) > 0;
        return { site: site, all: site || mp };
    }

    // Why a card has nothing to show, as { text, note, preset }.
    //
    // kind 'orders': the card counts paid orders. A window that has paid
    //   orders keeps the card's own sentence: something else emptied the
    //   card (no marketplace sale, orders without a country or a browser).
    //   Otherwise a store that never had an order says so (and how many
    //   administrator orders were left out); a quiet window names the latest
    //   paid day and offers the smallest preset that reaches it, or only says
    //   the window is quiet when that day is over a year back. basis 'site'
    //   is the card's own marketplace-free count (BrikMarket), 'all' counts
    //   marketplace orders too.
    // kind 'visits': the card counts BrikPanel's visitor tracking: off, or,
    //   on a store that never counted a visit, waiting for cookie consent or
    //   nothing counted yet. A store with visits keeps the card's own
    //   sentence for a window without any.
    // fallback: the card's own sentence, kept whenever none of that is the
    //   reason.
    function emptyReason(kind, basis, fallback) {
        var r = { text: fallback || i18n.no_data || '', note: '', preset: '' };

        if (kind === 'visits') {
            var t = emptyCtx.tracking;
            if (!t) return r;
            if (!t.enabled) {
                r.text = i18n.empty_tracking_off || r.text;
                return r;
            }
            if (emptyCtx.visitors > 0) return r;
            var vs = emptyCtx.store;
            if (!vs || vs.has_any_visit !== false) return r;
            r.text = (t.consent ? i18n.empty_visits_consent : i18n.empty_visits_none) || r.text;
            return r;
        }

        var paid = emptyCtx.paid;
        if (paid && (basis === 'all' ? paid.all : paid.site)) return r;

        var s = emptyCtx.store;
        if (!s) return r;
        if (!s.has_any_order) {
            r.text = i18n.empty_new_orders || r.text;
            var admins = Number(s.admin_orders) || 0;
            if (admins > 0 && BF && i18n.empty_admin_orders) {
                r.note = BF.count(i18n.empty_admin_orders, admins);
            }
            return r;
        }
        if (!s.has_paid_orders) {
            r.text = i18n.empty_no_paid || r.text;
            return r;
        }

        var p = emptyCtx.period;
        if (!p) return r;
        var day = basis === 'all' ? s.last_paid_day_all : s.last_paid_day_site;
        if (!day) {
            // The server looks a year back; an older last order is not named.
            r.text = i18n.empty_quiet_nodate || r.text;
            return r;
        }
        var stamp = ymdStamp(day);
        // A latest paid day inside the window means the window does have
        // paid orders, counted in a way the counts above do not share: never
        // name a day of the window as the last order before it.
        if (stamp >= ymdStamp(p.from_iso) && stamp <= ymdStamp(p.to_iso)) return r;
        if (!i18n.empty_quiet) return r;

        r.text = fillText(i18n.empty_quiet, emptyDayLabel(day, p));
        r.preset = presetFor(day, p);
        return r;
    }

    // Fill an element with a reason: the sentence, the administrator note on
    // its own line, then the preset link. Text nodes only, never markup.
    function fillEmpty(el, r) {
        if (!el) return;
        el.textContent = '';
        el.appendChild(document.createTextNode(r.text || ''));
        if (r.note) {
            var note = document.createElement('span');
            note.className = 'brikpanel-dash-empty-note';
            note.textContent = r.note;
            el.appendChild(note);
        }
        var labels = {
            '7days': i18n.empty_show_7days,
            '30days': i18n.empty_show_30days,
            '90days': i18n.empty_show_90days
        };
        var label = r.preset ? labels[r.preset] : '';
        if (label) {
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'brikpanel-dash-empty-link brikpanel-dash-empty-range';
            btn.setAttribute('data-bp-range', r.preset);
            btn.textContent = label;
            el.appendChild(document.createElement('br'));
            el.appendChild(btn);
        }
    }

    // Replace a card's content with a reason.
    function showEmpty(wrap, r) {
        if (!wrap) return;
        var p = document.createElement('p');
        p.className = 'brikpanel-dash-empty';
        fillEmpty(p, r);
        wrap.textContent = '';
        wrap.appendChild(p);
    }

    // A chart with nothing to draw hides its box and shows the reason in the
    // line the page prints right after it; given no reason, the line hides
    // and the box comes back. The box is shown BEFORE Chart.js draws, which
    // measures it. Returns true when the box was hidden until now.
    function setChartEmpty(canvas, r) {
        var chartBox = canvas ? canvas.parentElement : null;
        var msg = chartBox ? chartBox.nextElementSibling : null;
        if (!msg || !msg.classList.contains('brikpanel-dash-chart-empty')) msg = null;
        if (r) {
            if (msg) {
                fillEmpty(msg, r);
                msg.hidden = false;
                chartBox.hidden = true;
            }
            return false;
        }
        var wasHidden = !!(chartBox && chartBox.hidden);
        if (msg) msg.hidden = true;
        if (chartBox) chartBox.hidden = false;
        return wasHidden;
    }

    // The preset link inside an empty card clicks the matching date button.
    function initEmptyStates() {
        document.addEventListener('click', function (e) {
            var btn = e.target.closest && e.target.closest('.brikpanel-dash-empty-range');
            if (!btn) return;
            var range = btn.getAttribute('data-bp-range');
            if (['7days', '30days', '90days'].indexOf(range) === -1) return;
            var preset = document.querySelector('.brikpanel-dash-preset[data-range="' + range + '"]');
            if (preset) preset.click();
        });
    }

    // =========================================================================
    // NEW STORE GUIDE ("Your store is ready for its first order")
    // =========================================================================

    function initNewStoreGuide() {
        var guide = document.getElementById('brikpanel-dash-guide');
        if (!guide) return;
        guide.addEventListener('click', function (e) {
            if (!e.target.closest) return;
            if (e.target.closest('.brikpanel-dash-guide__close')) {
                var fd = new FormData();
                fd.append('action', 'brikpanel_dash_guide_dismiss');
                fd.append('security', guide.getAttribute('data-nonce') || '');
                fetch(CFG.ajax_url, {
                    method: 'POST',
                    body: fd,
                    credentials: 'same-origin',
                    keepalive: true
                }).catch(function () {});
                if (guide.parentNode) guide.parentNode.removeChild(guide);
                return;
            }
            // "Add your monthly expenses" opens the Expenses card's own window.
            if (e.target.closest('[data-bp-guide-action="add-expense"]')) {
                var add = document.getElementById('profit-exp-add');
                if (add) add.click();
            }
        });
    }

    // An expense was just saved from the quick window: tick the guide's
    // "Add your monthly expenses" step the way the server draws a done step
    // (check mark, quiet row, "Done: ..." for screen readers), so it stops
    // asking for something already done. The sentence comes with the page.
    function markGuideExpenseDone() {
        var guide = document.getElementById('brikpanel-dash-guide');
        var btn = guide ? guide.querySelector('[data-bp-guide-action="add-expense"]') : null;
        var step = btn ? btn.closest('.brikpanel-dash-guide__step') : null;
        var labelEl = btn ? btn.querySelector('.brikpanel-dash-guide__label') : null;
        if (!step || !labelEl) return;

        var SVG_NS = 'http://www.w3.org/2000/svg';
        var tick = document.createElementNS(SVG_NS, 'svg');
        [['width', '12'], ['height', '12'], ['viewBox', '0 0 24 24'], ['fill', 'none'],
            ['stroke', 'currentColor'], ['stroke-width', '3'], ['stroke-linecap', 'round'],
            ['stroke-linejoin', 'round'], ['aria-hidden', 'true'], ['focusable', 'false']
        ].forEach(function (a) { tick.setAttribute(a[0], a[1]); });
        var line = document.createElementNS(SVG_NS, 'polyline');
        line.setAttribute('points', '20 6 9 17 4 12');
        tick.appendChild(line);

        var mark = document.createElement('span');
        mark.className = 'brikpanel-dash-guide__mark';
        mark.appendChild(tick);

        var label = document.createElement('span');
        label.className = 'brikpanel-dash-guide__label';
        var spoken = btn.getAttribute('data-bp-done-label') || '';
        var shown = document.createElement('span');
        shown.textContent = labelEl.textContent;
        if (spoken) {
            var sr = document.createElement('span');
            sr.className = 'screen-reader-text';
            sr.textContent = spoken;
            label.appendChild(sr);
            shown.setAttribute('aria-hidden', 'true');
        }
        label.appendChild(shown);

        var item = document.createElement('span');
        item.className = 'brikpanel-dash-guide__item';
        item.appendChild(mark);
        item.appendChild(label);

        step.replaceChild(item, btn);
        step.classList.add('is-done');
    }

    // =========================================================================
    // PROFIT (Revenue − Cost of goods − Expenses)
    // =========================================================================

    function renderProfit(p) {
        if (!p) return;

        // Sentences built on the server: plural forms by count, the share of
        // revenue as one translated phrase with the percent sign where the
        // language writes it. Empty when there is nothing to say.
        var tx = p.texts || {};

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
            // When refunds have been netted out, Revenue no longer equals the
            // Total Sales KPI, so label it accordingly instead of claiming they
            // match. The breakdown below spells out gross minus returns.
            var netted = p.returns_on && Number(p.returns_raw) > 0;
            // "Tax in the Profit section" set to take tax out of Revenue takes
            // it off this figure too, so it no longer matches Total Sales either.
            var noTax = !!p.tax_excluded && Number(p.tax_raw) > 0;
            // "Tax in the Profit section" kept tax in Revenue: the line names
            // the amount (server-built, translated), and the returns stay in
            // the breakdown below. The title carries it when the line is cut.
            var taxNote = (p.tax_in_revenue && typeof p.tax_note === 'string') ? p.tax_note : '';
            revDelta.title = taxNote;
            if (taxNote) {
                revDelta.textContent = taxNote;
            } else if (netted && noTax) {
                revDelta.textContent = i18n.profit_revenue_net_tax_note || 'Net of returns and tax';
            } else if (noTax) {
                revDelta.textContent = i18n.profit_revenue_tax_note || 'Excluding tax';
            } else {
                revDelta.textContent = netted
                    ? (i18n.profit_revenue_net_note || 'Net of returns')
                    : (i18n.profit_revenue_note || 'Same as total sales');
            }
            revDelta.className = 'brikpanel-dash-card-delta brikpanel-dash-card-delta-static';
        }
        renderRevenueBreakdown(p);
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
            // No cost in a window without sales is no news: the hint used to
            // tell a fully costed catalog to set costs on every quiet day.
            if (!p.has_cogs && Number(p.revenue_raw) > 0) {
                cogsDelta.textContent = i18n.profit_cogs_hint || 'Set “Cost of goods” on products';
                cogsWarn = true;
            } else if (p.cogs_incomplete) {
                cogsDelta.textContent = tx.cogs_partial || '';
                cogsWarn = true;
                if (Array.isArray(p.cogs_missing_products) && p.cogs_missing_products.length) {
                    cogsList = p.cogs_missing_products;
                }
            } else {
                cogsDelta.textContent = tx.cogs_share || '';
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
            setMissingCogsListFlag(cogsLabel, cogsList, tx.cogs_missing_aria || '');
        }

        // Expenses: share of revenue under the card; the composition itself
        // lives in a full-width ribbon below so the four hero cards stay
        // perfectly uniform in height.
        var expDelta = document.getElementById('delta-profit-expenses');
        if (expDelta) {
            expDelta.textContent = tx.expenses_share || '';
            expDelta.className = 'brikpanel-dash-card-delta brikpanel-dash-card-delta-static';
        }
        renderExpenseBreakdown(p);

        // Payment fees are read per order, so the total can be built from only
        // part of them. Flag that on the Expenses card rather than in its delta
        // line, which already carries the share-of-revenue figure. The server
        // picks the case: fees in a currency with no rate (the total
        // understates), some orders without a fee, or a gateway that records
        // none at all (which used to look like the feature being broken).
        var expCard = document.getElementById('profit-expenses-card');
        if (expCard) {
            var feeTip = tx.fees_tip || '';
            setEstimateFlag(expCard, !!feeTip, feeTip);
        }

        // Net profit: colour green/red and show the margin %.
        var netCard = document.querySelector('.brikpanel-dash-card[data-metric="profit_net"]');
        if (netCard) {
            netCard.classList.toggle('is-loss', p.net_raw < 0);
            netCard.classList.toggle('is-profit', p.net_raw > 0);
            // Missing costs make this optimistic, not exact. Instead of a
            // loud border, mark it with a quiet "!" that explains, on
            // hover/focus, exactly what to do to make it accurate.
            var estTip = tx.estimate_tip || '';
            setEstimateFlag(netCard, !!p.cogs_incomplete && !!estTip, estTip);
        }
        var netDelta = document.getElementById('delta-profit-net');
        if (netDelta) {
            var parts = [];
            if (p.net_raw < 0) parts.push(i18n.profit_loss || 'Loss');
            if (tx.margin_share) parts.push(tx.margin_share);
            if (parts.length) {
                var base = netDelta.textContent && netDelta.textContent !== '--'
                    ? netDelta.textContent + ' · ' : '';
                netDelta.textContent = base + parts.join(' · ');
            }
        }
    }

    // Add/remove a small "!" marker (with a hover/focus/tap tooltip telling
    // the user what to fix) next to a card's label. Idempotent — safe to call
    // on every render. Keyboard-reachable via tabindex; the styled tooltip
    // is the only visible one (no native `title` so it doesn't double up).
    // front-end/shared/brikpanel-tip.js opens and places it (data-bp-tip).
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
            flag.setAttribute('data-bp-tip', '');
            flag.innerHTML =
                '<span class="brikpanel-dash-flag-mark" aria-hidden="true">!</span>'
                + '<span class="brikpanel-dash-flag-tip brikpanel-tip"></span>';
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
    function setMissingCogsListFlag(host, products, ariaText) {
        if (!host) return;
        var existing = host.querySelector('.brikpanel-dash-flag');
        if (existing) existing.parentNode.removeChild(existing);
        if (!Array.isArray(products) || !products.length) return;

        var flag = document.createElement('span');
        flag.className = 'brikpanel-dash-flag brikpanel-dash-flag-list';
        flag.setAttribute('tabindex', '0');
        flag.setAttribute('role', 'note');
        flag.setAttribute('data-bp-tip', '');

        var mark = document.createElement('span');
        mark.className = 'brikpanel-dash-flag-mark';
        mark.setAttribute('aria-hidden', 'true');
        mark.textContent = '!';

        var tip = document.createElement('span');
        // Interactive: the pointer may enter it to scroll the list.
        tip.className = 'brikpanel-dash-flag-tip brikpanel-dash-flag-tip-list brikpanel-tip brikpanel-tip--interactive';

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

        // Server-built, with the plural form for this count.
        flag.setAttribute('aria-label', ariaText || title.textContent);

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

        var win = (p && p.window) ? p.window : null;

        items.forEach(function (b, i) {
            var row = document.createElement('div');
            // depth 1 = an expense filed under another one, drawn indented right
            // beneath it. Both lines carry their own amount: nesting is visual,
            // nothing is subtotalled. A `label` line is a name with no expense
            // of its own (a parent that has no line in this window).
            var isLabel = b.key === 'label';
            var isChild = Number(b.depth) === 1;
            // The connector drawn down the left of a nested run has to know
            // where that run starts and ends, and CSS cannot see it: `is-child`
            // rows are siblings of everything else, not wrapped in anything. So
            // mark the head of the run and its final line here.
            var next    = items[i + 1];
            var isParent = !isChild && next && Number(next.depth) === 1;
            var isLastChild = isChild && !(next && Number(next.depth) === 1);
            row.className = 'brikpanel-dash-bd-row'
                + (isLabel ? ' is-label' : '')
                + (isParent ? ' is-parent' : '')
                + (isChild ? ' is-child' : '')
                + (isLastChild ? ' is-child-last' : '');

            var k = document.createElement('span');
            k.className = 'brikpanel-dash-bd-k';
            k.textContent = b.label;

            row.appendChild(k);

            if (!isLabel) {
                var pct = Math.round((b.raw / total) * 100);
                var v = document.createElement('span');
                v.className = 'brikpanel-dash-bd-v';
                v.innerHTML = b.amount + ' <span class="brikpanel-dash-bd-pct">' + escapeHtml(fmtPct(pct, 0)) + '</span>';
                row.appendChild(v);
            }

            // Only lines that map to real expense rows carry `del`; tax and ad
            // spend come from elsewhere and stay read-only.
            if (b.del && b.del.type) {
                row.appendChild(removeButton(b, win));
            }

            box.appendChild(row);
        });
    }

    // The little × on a breakdown line. Attributes are set through the DOM API
    // rather than built into a string, so a category containing quotes or angle
    // brackets can never break out of the markup.
    function removeButton(item, win) {
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'brikpanel-dash-bd-x';

        var label = (i18n.exp_del_aria || 'Remove %s').replace('%s', item.label);
        btn.setAttribute('title', label);
        btn.setAttribute('aria-label', label);
        btn.setAttribute('data-del-type', item.del.type);
        btn.setAttribute('data-del-label', item.label);
        // Both always-on kinds address one row by id and have no date window;
        // everything else is matched by title inside the viewed period.
        if (item.del.type === 'percent' || item.del.type === 'per_order') {
            btn.setAttribute('data-del-id', String(item.del.id));
        } else {
            btn.setAttribute('data-del-cat', item.del.cat || '');
            // Present on a nested title (scopes the removal to its group) and
            // on a group row (names the group itself). Absent on flat lines.
            btn.setAttribute('data-del-group', item.del.group || '');
            btn.setAttribute('data-del-from', (win && win.from) ? win.from : '');
            btn.setAttribute('data-del-to', (win && win.to) ? win.to : '');
        }

        var svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        svg.setAttribute('width', '12');
        svg.setAttribute('height', '12');
        svg.setAttribute('viewBox', '0 0 24 24');
        svg.setAttribute('fill', 'none');
        svg.setAttribute('stroke', 'currentColor');
        svg.setAttribute('stroke-width', '2.5');
        svg.setAttribute('stroke-linecap', 'round');
        svg.setAttribute('aria-hidden', 'true');
        [['18','6','6','18'], ['6','6','18','18']].forEach(function (c) {
            var line = document.createElementNS('http://www.w3.org/2000/svg', 'line');
            line.setAttribute('x1', c[0]); line.setAttribute('y1', c[1]);
            line.setAttribute('x2', c[2]); line.setAttribute('y2', c[3]);
            svg.appendChild(line);
        });
        btn.appendChild(svg);
        return btn;
    }

    // Fill the (collapsed-by-default) breakdown list inside the Revenue card,
    // mirroring the Expenses breakdown. Shows how gross sales become the net
    // Revenue figure on the card: Gross sales − Returns = Net revenue, with
    // coupons listed afterwards as an informational note (the discount is
    // already inside the order totals, so it is never subtracted again). Rows
    // are pre-labelled and pre-formatted server-side; this only arranges them
    // and applies the right sign per row `type`.
    function renderRevenueBreakdown(p) {
        var box    = document.getElementById('profit-revenue-breakdown');
        var toggle = document.getElementById('profit-rev-bd-toggle');
        var card   = document.getElementById('profit-revenue-card');
        if (!box) return;

        var items = (p && p.revenue_breakdown) ? p.revenue_breakdown : [];

        box.innerHTML = '';

        // Nothing to decompose (no returns, no coupons) → hide the toggle and
        // keep the card minimal, exactly like the Expenses card does.
        if (!items.length) {
            if (toggle) {
                toggle.hidden = true;
                toggle.setAttribute('aria-expanded', 'false');
            }
            if (card) card.classList.remove('is-bd-open');
            return;
        }
        if (toggle) toggle.hidden = false;

        function row(label, valueHtml, extraClass) {
            var r = document.createElement('div');
            r.className = 'brikpanel-dash-bd-row' + (extraClass ? ' ' + extraClass : '');
            var k = document.createElement('span');
            k.className = 'brikpanel-dash-bd-k';
            k.textContent = label;
            var v = document.createElement('span');
            v.className = 'brikpanel-dash-bd-v';
            v.innerHTML = valueHtml;
            r.appendChild(k);
            r.appendChild(v);
            box.appendChild(r);
        }

        var infoRows = [];
        items.forEach(function (b) {
            if (b.type === 'info') {
                infoRows.push(b); // rendered after the Net revenue total
                return;
            }
            // Deductions get a leading minus so the arithmetic reads cleanly.
            var sign = (b.type === 'deduct') ? '− ' : '';
            row(b.label, sign + b.amount, b.type === 'deduct' ? 'brikpanel-dash-bd-row-deduct' : '');
        });

        // Net revenue total = the value on the card itself.
        if (p.revenue) {
            row(i18n.profit_net_revenue || 'Net revenue', p.revenue, 'brikpanel-dash-bd-row-total');
        }

        infoRows.forEach(function (b) {
            row(b.label, b.amount, 'brikpanel-dash-bd-row-info');
        });
    }

    // Wire a Revenue/Expenses "Breakdown ⌄" toggle once. Open/closed state is
    // kept across data refreshes so a refresh never collapses what the user
    // opened.
    function wireBreakdownToggle(toggleId, cardId) {
        var toggle = document.getElementById(toggleId);
        var card   = document.getElementById(cardId);
        if (!toggle || !card) return;

        toggle.addEventListener('click', function () {
            var open = !card.classList.contains('is-bd-open');
            card.classList.toggle('is-bd-open', open);
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
    }

    function initProfitBreakdownToggle() {
        wireBreakdownToggle('profit-bd-toggle', 'profit-expenses-card');
        wireBreakdownToggle('profit-rev-bd-toggle', 'profit-revenue-card');
    }

    // =========================================================================
    // ADD EXPENSE (quick modal on the Profit > Expenses card)
    // =========================================================================

    function initAddExpense() {
        var openBtn = document.getElementById('profit-exp-add');
        var modal   = document.getElementById('brikpanel-exp-modal');
        if (!openBtn || !modal) return; // Expenses field hidden → no quick-add

        var saveBtn   = document.getElementById('brikpanel-exp-save');
        var amountEl  = document.getElementById('brikpanel-exp-amount');
        var catEl     = document.getElementById('brikpanel-exp-category');
        var pcatEl    = document.getElementById('brikpanel-exp-parent-category');

        // The "Part of" picker lists the expenses this one can be filed under.
        // There is no free-text path: a cost can only sit under a cost that
        // already exists.
        function groupValue() { return pcatEl ? pcatEl.value : ''; }

        // An expense can never be filed under itself, so grey that option out as
        // the title is typed.
        //
        // It must NEVER clear the current selection: a title is typed one letter
        // at a time and passes through names on the way to its own. "ahmo 2"
        // filed under "ahmo" spends one keystroke looking exactly like its own
        // parent, and clearing there silently unfiled the expense with nothing
        // on screen to show for it. Disabling alone is safe — a disabled option
        // that is already selected still submits, and the server rejects a real
        // collision with a message the merchant can read.
        function syncSelfExclusion() {
            if (!pcatEl) return;
            var self = (catEl ? catEl.value : '').trim().toLowerCase();
            Array.prototype.slice.call(pcatEl.options).forEach(function (o) {
                if (!o.value) { return; }
                o.disabled = self !== '' && o.getAttribute('data-key') === self
                    && pcatEl.value !== o.value;
            });
        }
        if (catEl) catEl.addEventListener('input', syncSelfExclusion);

        // Offer a just-saved expense as a parent straight away. Only top-level
        // ones qualify — nesting stops at two levels — and only if the picker
        // does not already list that title.
        function addParentOption(title, parent) {
            title = (title || '').trim();
            if (!pcatEl || title === '' || (parent || '').trim() !== '') return;
            var key = title.toLowerCase();
            var exists = Array.prototype.slice.call(pcatEl.options)
                .some(function (o) { return o.getAttribute('data-key') === key; });
            if (exists) return;
            var opt = document.createElement('option');
            opt.value = title;
            opt.textContent = title;
            opt.setAttribute('data-key', key);
            pcatEl.appendChild(opt);
        }
        var dateEl    = document.getElementById('brikpanel-exp-date');
        var recEl     = document.getElementById('brikpanel-exp-recurring');
        var descEl    = document.getElementById('brikpanel-exp-desc');
        var hintEl    = document.getElementById('brikpanel-exp-recurring-hint');
        var msgEl     = document.getElementById('brikpanel-exp-msg');
        var kindEl    = document.getElementById('brikpanel-exp-kind');
        var prefixEl  = document.getElementById('brikpanel-exp-prefix');
        var suffixEl  = document.getElementById('brikpanel-exp-suffix');
        var recField  = document.getElementById('brikpanel-exp-recurring-field');
        var row2El    = document.getElementById('brikpanel-exp-row2');
        var pctHintEl = document.getElementById('brikpanel-exp-percent-hint');
        var poHintEl  = document.getElementById('brikpanel-exp-per-order-hint');
        var scopeEl   = document.getElementById('brikpanel-exp-scope');
        var scopeFld  = document.getElementById('brikpanel-exp-scope-field');
        var cfg       = (CFG.expenses || {});
        var saving    = false;

        function isPercent()  { return kindEl && kindEl.value === 'percent'; }
        function isPerOrder() { return kindEl && kindEl.value === 'per_order'; }

        function showMsg(text, isError) {
            if (!msgEl) return;
            msgEl.textContent = text;
            msgEl.className = 'brikpanel-exp-msg' + (isError ? ' is-error' : ' is-ok');
            msgEl.hidden = false;
        }
        function clearMsg() { if (msgEl) { msgEl.hidden = true; msgEl.textContent = ''; } }

        function openModal() {
            clearMsg();
            modal.hidden = false;
            document.body.classList.add('brikpanel-exp-modal-open');
            // Focus the amount field for fast entry.
            setTimeout(function () { if (amountEl) amountEl.focus(); }, 30);
        }
        function closeModal() {
            modal.hidden = true;
            document.body.classList.remove('brikpanel-exp-modal-open');
        }

        openBtn.addEventListener('click', openModal);

        modal.addEventListener('click', function (e) {
            if (e.target.closest('[data-exp-close]')) { e.preventDefault(); closeModal(); }
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && !modal.hidden) closeModal();
        });

        // Reveal the "counts every period" hint only for repeating expenses.
        // Both always-on kinds suppress it: without the isPerOrder() arm,
        // switching a Monthly fixed row to a per-order cost would leave "counted
        // automatically in every period" hanging under a now-hidden Repeats.
        function syncHint() {
            if (hintEl) hintEl.hidden = isPercent() || isPerOrder() || !recEl || recEl.value === 'none';
        }
        if (recEl) recEl.addEventListener('change', syncHint);

        // A percentage cost is a rate of revenue and a per-order cost is a unit
        // price: both are always on, so both drop the Date and Repeats row (that
        // one wrapper holds the pair here, unlike the Expenses page where the two
        // fields are hidden separately). Only the percentage swaps the currency
        // for "%" and caps the input at 100, since a per-order cost is still money.
        // "Applies to" belongs to the per-order kind alone.
        function syncType() {
            var pct     = isPercent();
            var perOrd  = isPerOrder();
            var ongoing = pct || perOrd;
            if (prefixEl) prefixEl.hidden = pct;
            if (suffixEl) suffixEl.hidden = !pct;
            if (row2El) row2El.hidden = ongoing;      // hides Date + Repeats together
            if (recField) recField.hidden = false;    // restored for the fixed case
            if (pctHintEl) pctHintEl.hidden = !pct;
            if (poHintEl) poHintEl.hidden = !perOrd;
            if (scopeFld) scopeFld.hidden = !perOrd;
            if (amountEl) amountEl.max = pct ? '100' : '';
            syncHint();
        }
        if (kindEl) kindEl.addEventListener('change', syncType);
        syncType();

        function save() {
            if (saving) return;
            var amount = amountEl ? parseFloat(amountEl.value) : NaN;
            var category = catEl ? catEl.value.trim() : '';
            var pct = isPercent();
            // The 0-100 ceiling is percent-only: a per-order cost is money.
            if (!(amount >= 0) || isNaN(amount) || category === '' || (pct && amount > 100)) {
                showMsg(i18n.exp_required || 'Enter an amount and a title.', true);
                return;
            }
            saving = true;
            saveBtn.disabled = true;
            var original = saveBtn.textContent;
            saveBtn.textContent = i18n.exp_saving || 'Saving…';
            clearMsg();

            var savedTitle  = category;
            var savedParent = groupValue();

            var fd = new FormData();
            fd.append('action', cfg.action || 'brikpanel_expenses_save');
            fd.append('_ajax_nonce', cfg.nonce || '');
            fd.append('id', '0');
            fd.append('kind', kindEl ? kindEl.value : 'fixed');
            fd.append('scope', (isPerOrder() && scopeEl) ? scopeEl.value : '');
            fd.append('amount', String(amount));
            fd.append('category', category);
            fd.append('parent_category', savedParent);
            fd.append('expense_date', dateEl ? dateEl.value : '');
            fd.append('recurring', (pct || isPerOrder() || !recEl) ? 'none' : recEl.value);
            fd.append('description', descEl ? descEl.value : '');

            fetch(CFG.ajax_url, { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (j) {
                    saving = false;
                    saveBtn.disabled = false;
                    saveBtn.textContent = original;
                    if (j && j.success) {
                        closeModal();
                        markGuideExpenseDone();
                        // Reset for next time.
                        if (amountEl) amountEl.value = '';
                        // The expense just saved is itself something the next
                        // one can go under, and the picker is rendered once with
                        // the page — so offer it now instead of after a reload.
                        addParentOption(savedTitle, savedParent);
                        if (pcatEl) pcatEl.value = '';
                        if (catEl) catEl.value = '';
                        if (descEl) descEl.value = '';
                        if (recEl) recEl.value = 'none';
                        if (kindEl) kindEl.value = 'fixed';
                        if (scopeEl) scopeEl.value = '';
                        syncType();
                        // The save busted the dashboard cache server-side, so a
                        // refetch returns figures that already include this expense.
                        fetchDashboardData();
                    } else {
                        showMsg((j && j.data && j.data.message) || i18n.exp_error || 'Could not save.', true);
                    }
                })
                .catch(function () {
                    saving = false;
                    saveBtn.disabled = false;
                    saveBtn.textContent = original;
                    showMsg(i18n.exp_error || 'Could not save.', true);
                });
        }

        if (saveBtn) saveBtn.addEventListener('click', save);
        // Enter in the amount/category field submits.
        [amountEl, catEl, descEl].forEach(function (el) {
            if (!el) return;
            el.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); save(); } });
        });
    }

    // =========================================================================
    // REMOVE AN EXPENSE FROM THE PROFIT BREAKDOWN
    //
    // A breakdown line is a grouped total, not one expense, so the browser never
    // decides what gets deleted. Clicking × asks the server what the line covers
    // (preview), shows that answer, and sends the chosen option back with the
    // token the preview issued (commit). Every sentence in the dialog is written
    // server-side — nothing here composes user-facing text.
    // =========================================================================

    function initRemoveExpense() {
        var modal = document.getElementById('brikpanel-expdel-modal');
        var box   = document.getElementById('profit-expenses-breakdown');
        if (!modal || !box) return;

        var titleEl   = document.getElementById('brikpanel-expdel-title');
        var bodyEl    = document.getElementById('brikpanel-expdel-body');
        var noteEl    = document.getElementById('brikpanel-expdel-note');
        var scopesEl  = document.getElementById('brikpanel-expdel-scopes');
        var msgEl     = document.getElementById('brikpanel-expdel-msg');
        var confirmEl = document.getElementById('brikpanel-expdel-confirm');
        var cfg       = (CFG.expenses || {});
        var pending   = null;   // { payload, token }
        var busy      = false;

        function showMsg(text) {
            if (!msgEl) return;
            msgEl.textContent = text;
            msgEl.className = 'brikpanel-exp-msg is-error';
            msgEl.hidden = false;
        }
        function clearMsg() { if (msgEl) { msgEl.hidden = true; msgEl.textContent = ''; } }

        function openModal() {
            clearMsg();
            modal.hidden = false;
            document.body.classList.add('brikpanel-exp-modal-open');
            var first = scopesEl.querySelector('input[type="radio"]');
            setTimeout(function () { (first || confirmEl).focus(); }, 30);
        }
        function closeModal() {
            modal.hidden = true;
            document.body.classList.remove('brikpanel-exp-modal-open');
            pending = null;
        }

        function post(fields) {
            var fd = new FormData();
            fd.append('action', 'brikpanel_expense_line_delete');
            fd.append('_ajax_nonce', cfg.nonce || '');
            Object.keys(fields).forEach(function (k) { fd.append(k, fields[k]); });
            return fetch(CFG.ajax_url, { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function (r) { return r.json(); });
        }

        // Everything the request needs, read straight off the button.
        function payloadFor(btn) {
            var type = btn.getAttribute('data-del-type');
            if (type === 'percent' || type === 'per_order') {
                return { type: type, id: btn.getAttribute('data-del-id') || '0' };
            }
            return {
                type: type === 'group' ? 'group' : 'cat',
                cat: btn.getAttribute('data-del-cat') || '',
                group: btn.getAttribute('data-del-group') || '',
                date_from: btn.getAttribute('data-del-from') || '',
                date_to: btn.getAttribute('data-del-to') || ''
            };
        }

        function renderScopes(scopes) {
            scopesEl.innerHTML = '';
            // One option is not a choice: show it as plain text and let the
            // Remove button carry it.
            if (scopes.length < 2) {
                if (scopes.length === 1 && scopes[0].detail) {
                    var only = document.createElement('p');
                    only.className = 'brikpanel-expdel-only';
                    only.textContent = scopes[0].detail;
                    scopesEl.appendChild(only);
                }
                return;
            }
            scopes.forEach(function (s, idx) {
                var id = 'brikpanel-expdel-scope-' + s.id;
                var wrap = document.createElement('label');
                wrap.className = 'brikpanel-expdel-scope';
                wrap.setAttribute('for', id);

                var radio = document.createElement('input');
                radio.type = 'radio';
                radio.name = 'brikpanel-expdel-scope';
                radio.id = id;
                radio.value = s.id;
                if (idx === 0) radio.checked = true;

                var text = document.createElement('span');
                var strong = document.createElement('strong');
                strong.textContent = s.label;
                text.appendChild(strong);
                if (s.detail) {
                    var det = document.createElement('span');
                    det.className = 'brikpanel-expdel-scope-detail';
                    det.textContent = s.detail;
                    text.appendChild(det);
                }

                wrap.appendChild(radio);
                wrap.appendChild(text);
                scopesEl.appendChild(wrap);
                if (idx === 0) wrap.classList.add('is-selected');
            });

            // Mirror the checked radio onto the label. The stylesheet also has a
            // :has() rule, but not every browser in the wild supports it and the
            // selected option must always be obvious before something is removed.
            scopesEl.addEventListener('change', function () {
                scopesEl.querySelectorAll('.brikpanel-expdel-scope').forEach(function (l) {
                    l.classList.toggle('is-selected', !!l.querySelector('input:checked'));
                });
            });
        }

        function chosenScope() {
            var checked = scopesEl.querySelector('input[type="radio"]:checked');
            if (checked) return checked.value;
            return pending && pending.scopes.length ? pending.scopes[0].id : '';
        }

        box.addEventListener('click', function (e) {
            var btn = e.target.closest('.brikpanel-dash-bd-x');
            if (!btn || busy) return;
            e.preventDefault();
            e.stopPropagation();   // the row sits inside a card with its own toggle

            busy = true;
            btn.classList.add('is-busy');
            var payload = payloadFor(btn);
            post(Object.assign({ mode: 'preview' }, payload)).then(function (j) {
                busy = false;
                btn.classList.remove('is-busy');
                if (!j || !j.success) {
                    showToast((j && j.data && j.data.message) || i18n.exp_del_error || 'Could not remove.', 'error');
                    return;
                }
                pending = { payload: payload, token: j.data.token, scopes: j.data.scopes || [] };
                titleEl.textContent = j.data.title || '';
                bodyEl.textContent = j.data.body || '';
                if (j.data.note) {
                    noteEl.textContent = j.data.note;
                    noteEl.hidden = false;
                } else {
                    noteEl.hidden = true;
                    noteEl.textContent = '';
                }
                renderScopes(pending.scopes);
                openModal();
            }).catch(function () {
                busy = false;
                btn.classList.remove('is-busy');
                showToast(i18n.exp_del_error || 'Could not remove.', 'error');
            });
        });

        confirmEl.addEventListener('click', function () {
            if (!pending || busy) return;
            busy = true;
            confirmEl.disabled = true;
            var original = confirmEl.textContent;
            confirmEl.textContent = i18n.exp_del_working || 'Removing…';
            clearMsg();

            post(Object.assign({ mode: 'commit', scope: chosenScope(), token: pending.token }, pending.payload))
                .then(function (j) {
                    busy = false;
                    confirmEl.disabled = false;
                    confirmEl.textContent = original;
                    if (!j || !j.success) {
                        showMsg((j && j.data && j.data.message) || i18n.exp_del_error || 'Could not remove.');
                        return;
                    }
                    closeModal();
                    showToast(j.data.message || '', 'success');
                    // The commit busted the dashboard cache server-side.
                    fetchDashboardData();
                })
                .catch(function () {
                    busy = false;
                    confirmEl.disabled = false;
                    confirmEl.textContent = original;
                    showMsg(i18n.exp_del_error || 'Could not remove.');
                });
        });

        modal.addEventListener('click', function (e) {
            if (e.target.closest('[data-expdel-close]')) { e.preventDefault(); closeModal(); }
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && !modal.hidden) closeModal();
        });
    }

    // =========================================================================
    // SALES OVER TIME CHART
    // =========================================================================

    function renderSalesChart(data) {
        var ctx = document.getElementById('brikpanel-sales-chart');
        if (!ctx || typeof Chart === 'undefined') return;

        // The server lists every day of the window, days without sales at 0.
        // All zero is an empty window: the reason instead of a flat line on a
        // bare 0 to 1 axis.
        data = Array.isArray(data) ? data : [];
        var hasSales = data.some(function (d) { return Number(d.revenue) !== 0 || Number(d.orders) !== 0; });
        if (!hasSales) {
            setChartEmpty(ctx, emptyReason('orders', 'site'));
            return;
        }
        var wasHidden = setChartEmpty(ctx, null);

        // Axis: short day and month in the viewer's language ("22 Eyl"), was
        // the raw "2026-09-22". The tooltip title names the full date.
        var days = data.map(function (d) { return d.date; });
        var labels = days.map(function (day) { return BF ? BF.dayMonth(day) : day; });
        var revenue = data.map(function (d) { return d.revenue; });
        var orders = data.map(function (d) { return d.orders; });

        // A one-day range is a single bucket, and Chart.js draws no line segment
        // for one point — with the dotless style the Orders series would vanish
        // entirely. Force a visible dot in that case. Revenue thins its dots out
        // on long ranges where they would smear into the line.
        var revRadius = data.length === 1 ? 4 : (data.length > 30 ? 0 : 3);
        var ordRadius = data.length === 1 ? 4 : 0;

        if (salesChart) {
            salesChart.$bpDays = days;
            salesChart.data.labels = labels;
            salesChart.data.datasets[0].data = revenue;
            salesChart.data.datasets[1].data = orders;
            // Recompute with the new point count: switching from "Last 30 days"
            // to "Today" otherwise kept the old radii and hid the single point.
            salesChart.data.datasets[0].pointRadius = revRadius;
            salesChart.data.datasets[1].pointRadius = ordRadius;
            // Back from hidden: measure the box again before drawing.
            if (wasHidden) salesChart.resize();
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
                        pointRadius: revRadius,
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
                        pointRadius: ordRadius,
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
                                return BF ? BF.compact(v) : v;
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
                        padding: 10,
                        callbacks: {
                            title: function (items) {
                                var day = items.length && salesChart && salesChart.$bpDays ? salesChart.$bpDays[items[0].dataIndex] : '';
                                return day && BF ? BF.dateShort(day) : (items.length ? items[0].label : '');
                            },
                            label: function (ctx) {
                                var v = ctx.parsed ? ctx.parsed.y : 0;
                                var shown = !BF ? String(v) : (ctx.dataset.yAxisID === 'y' ? BF.money(v) : BF.number(v));
                                return (ctx.dataset.label ? ctx.dataset.label + ': ' : '') + shown;
                            }
                        }
                    }
                }
            }
        });
        salesChart.$bpDays = days;
    }

    // =========================================================================
    // CONVERSION FUNNEL CHART
    // =========================================================================

    function renderFunnelChart(funnel) {
        var ctx = document.getElementById('brikpanel-funnel-chart');
        if (!ctx || typeof Chart === 'undefined') return;

        var labels = [
            i18n.visitors || 'Visitors',
            i18n.product_views || 'Product views',
            i18n.add_to_cart || 'Add to cart',
            i18n.checkout || 'Checkout',
            i18n.orders || 'Orders'
        ];
        funnel = funnel || {};
        var values = [funnel.visitors, funnel.products, funnel.cart, funnel.checkout, funnel.orders];
        var colors = ['#303030', '#4a4a4a', '#6a6a6a', '#8a8a8a', '#1a8917'];

        // Nothing at any step: why, instead of empty bars on a 0 to 1 axis.
        if (values.every(function (v) { return !Number(v); })) {
            setChartEmpty(ctx, emptyReason('visits'));
            return;
        }
        var wasHidden = setChartEmpty(ctx, null);

        if (funnelChart) {
            funnelChart.data.datasets[0].data = values;
            if (wasHidden) funnelChart.resize();
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
                        padding: 10,
                        callbacks: {
                            label: function (ctx) {
                                return BF ? BF.tooltipValue(ctx) : ctx.formattedValue;
                            }
                        }
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
            (i18n.successful || 'Successful') + ' (' + fmtPct(rates.successful) + ')',
            (i18n.failed || 'Failed') + ' (' + fmtPct(rates.failed) + ')',
            (i18n.refunded || 'Returns & refunds') + ' (' + fmtPct(rates.refunded) + ')',
            (i18n.cancelled || 'Cancelled') + ' (' + fmtPct(rates.cancelled) + ')'
        ];
        var values = [rates.successful, rates.failed, rates.refunded, rates.cancelled];
        var colors = ['#303030', '#d72c0d', '#8a8a8a', '#616161'];

        // No orders in the window: the reason instead of a grey placeholder
        // ring with a "No orders" legend.
        if (values.every(function (v) { return !Number(v); })) {
            setChartEmpty(ctx, emptyReason('orders', 'site'));
            return;
        }
        var wasHidden = setChartEmpty(ctx, null);

        if (ratesChart) {
            ratesChart.data.labels = labels;
            ratesChart.data.datasets[0].data = values;
            ratesChart.data.datasets[0].backgroundColor = colors;
            if (wasHidden) ratesChart.resize();
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
                        padding: 10,
                        callbacks: {
                            // The label already carries its percentage.
                            label: function (ctx) { return ctx.label; }
                        }
                    }
                }
            }
        });
    }

    // =========================================================================
    // TABLES
    // =========================================================================

    // Tables turn into stacked cards when they cannot show every column in
    // their box (field test B6: on a phone the right-hand totals scrolled out
    // of sight). Each box is watched once; its table is re-rendered in place,
    // so the shared helper looks it up again on every measurement.
    // floor: stack below this room even when the table would squeeze in
    // (see renderRecentOrders).
    function refitDashTable(wrap, floor) {
        if (!wrap || !window.brikpanelFitTable) return;
        var table = wrap.querySelector('table.brikpanel-dash-table');
        if (table) table.classList.add('brikpanel-fit-table');
        var fit = window.brikpanelFitTable(wrap, { labels: 'head', slack: 0, floor: floor || 0 });
        if (fit) fit.refit();
    }

    // A long SKU may break after a hyphen, never mid-word.
    function skuHtml(sku) {
        return escapeHtml(sku).replace(/-/g, '-<wbr>');
    }

    function renderTopProducts(products) {
        var wrap = document.getElementById('top-products-table');
        if (!wrap) return;

        if (!products || products.length === 0) {
            showEmpty(wrap, emptyReason('orders', 'site'));
            return;
        }

        var html = '<table class="brikpanel-dash-table"><thead><tr>' +
            '<th>#</th>' +
            '<th>' + (i18n.product || 'Product') + '</th>' +
            '<th>' + (i18n.qty_sold || 'Qty sold') + '</th>' +
            '</tr></thead><tbody>';

        products.forEach(function (p, i) {
            var rowAttr = p.url
                ? ' class="brikpanel-dash-row-link" data-href="' + escapeAttr(p.url) + '" tabindex="0" role="link"'
                : '';
            html += '<tr' + rowAttr + '>' +
                '<td class="rank">' + (i + 1) + '</td>' +
                '<td class="brikpanel-fit-lead">' + escapeHtml(p.name) + '</td>' +
                '<td class="brikpanel-fit-headline">' + formatNumber(p.qty) + '</td>' +
                '</tr>';
        });

        html += '</tbody></table>';
        wrap.innerHTML = html;
        refitDashTable(wrap);
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

    // WooCommerce's name for an order status (i18n.status_labels, keyed like
    // $order->get_status()), or '' when it has none.
    function statusLabelFor(slug) {
        var labels = i18n.status_labels;
        if (!labels || typeof labels !== 'object' || !Object.prototype.hasOwnProperty.call(labels, slug)) return '';
        return typeof labels[slug] === 'string' ? labels[slug] : '';
    }

    function renderRecentOrders(orders) {
        var wrap = document.getElementById('recent-orders-table');
        if (!wrap) return;

        if (!orders || orders.length === 0) {
            // All-time list: only the new-store reason applies; a store that
            // has orders keeps its own sentence.
            var st = emptyCtx.store;
            showEmpty(wrap, (st && !st.has_any_order)
                ? emptyReason('orders', 'all')
                : { text: i18n.no_orders || '', note: '', preset: '' });
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
                // title: a long source name can shorten with "…" in the two-line rows.
                sourceHtml = '<span class="brikpanel-dash-source" style="background:' + escapeHtml(o.source.color) + ';" title="' + escapeAttr(o.source.label) + '">' + escapeHtml(o.source.label) + '</span>';
            }

            var rowAttr = o.edit_url
                ? ' class="brikpanel-dash-row-link" data-href="' + escapeAttr(o.edit_url) + '" tabindex="0" role="link"'
                : '';

            // WooCommerce's own name for the status, in the admin's language;
            // a status it does not list keeps its short name.
            var statusLabel = statusLabelFor(o.status);
            var dateHtml = o.date ? '<span class="brikpanel-dash-order-date">' + escapeHtml(o.date) + '</span>' : '';
            // Units on the order, under its total: the row already has two
            // lines (number + date), so this adds no height.
            // <bdi>: "3 items" keeps its order on a right-to-left screen.
            var itemsHtml = o.items_label ? '<span class="brikpanel-dash-order-items"><bdi>' + escapeHtml(o.items_label) + '</bdi></span>' : '';
            // The same count beside the customer, shown only when the table
            // turns into two-line rows (phone, half-width card): "Name · 3 items".
            var whoItemsHtml = o.items_label ? '<span class="brikpanel-dash-order-who-items"><bdi>' + escapeHtml(o.items_label) + '</bdi></span>' : '';

            html += '<tr' + rowAttr + '>' +
                // <bdi>: an order number with letters ("#2026-INV-0001") keeps
                // its order on a right-to-left screen. The space keeps number
                // and date two words for screen readers.
                '<td class="brikpanel-fit-lead"><bdi>#' + escapeHtml(String(o.number || o.id)) + '</bdi> ' + dateHtml + '</td>' +
                '<td class="brikpanel-dash-order-who"><span class="brikpanel-dash-order-who-name">' + escapeHtml(o.customer) + '</span>' + whoItemsHtml + '</td>' +
                '<td>' + sourceHtml + '</td>' +
                '<td><span class="brikpanel-dash-status ' + escapeHtml(o.status) + (statusLabel ? '' : ' brikpanel-dash-status--slug') + '">' + escapeHtml(statusLabel || o.status) + '</span></td>' +
                '<td class="brikpanel-fit-headline">' + o.total + (o.total_base ? '<div class="brikpanel-dash-total-base">≈ ' + o.total_base + '</div>' : '') + itemsHtml + '</td>' +
                '</tr>';
        });

        html += '</tbody></table>';
        wrap.innerHTML = html;
        // Five columns squeezed under 340px only fit by breaking the date over
        // three lines (short status names, as in Arabic); rows read better.
        // Stacked, each order is two lines (brikpanel-dashboard.css).
        refitDashTable(wrap, 340);
    }

    function renderMostViewed(pages) {
        var wrap = document.getElementById('most-viewed-table');
        if (!wrap) return;

        if (!pages || pages.length === 0) {
            showEmpty(wrap, emptyReason('visits'));
            return;
        }

        var html = '<table class="brikpanel-dash-table"><thead><tr>' +
            '<th>#</th>' +
            '<th>' + (i18n.page || 'Page') + '</th>' +
            '<th>' + (i18n.views || 'Views') + '</th>' +
            '</tr></thead><tbody>';

        pages.forEach(function (p, i) {
            var rowAttr = p.url
                ? ' class="brikpanel-dash-row-link" data-href="' + escapeAttr(p.url) + '" tabindex="0" role="link"'
                : '';
            html += '<tr' + rowAttr + '>' +
                '<td class="rank">' + (i + 1) + '</td>' +
                '<td>' + escapeHtml(p.title) + '</td>' +
                '<td>' + formatNumber(p.views) + '</td>' +
                '</tr>';
        });

        html += '</tbody></table>';
        wrap.innerHTML = html;
        refitDashTable(wrap);
    }

    function renderMostCart(products) {
        var wrap = document.getElementById('most-cart-table');
        if (!wrap) return;

        if (!products || products.length === 0) {
            showEmpty(wrap, emptyReason('visits'));
            return;
        }

        var html = '<table class="brikpanel-dash-table"><thead><tr>' +
            '<th>#</th>' +
            '<th>' + (i18n.product || 'Product') + '</th>' +
            '<th>' + (i18n.cart_count || 'Cart adds') + '</th>' +
            '</tr></thead><tbody>';

        products.forEach(function (p, i) {
            var rowAttr = p.url
                ? ' class="brikpanel-dash-row-link" data-href="' + escapeAttr(p.url) + '" tabindex="0" role="link"'
                : '';
            html += '<tr' + rowAttr + '>' +
                '<td class="rank">' + (i + 1) + '</td>' +
                '<td>' + escapeHtml(p.name) + '</td>' +
                '<td>' + formatNumber(p.count) + '</td>' +
                '</tr>';
        });

        html += '</tbody></table>';
        wrap.innerHTML = html;
        refitDashTable(wrap);
    }

    // view: 'visitors' (tracking) or 'orders' (the orders' own browser).
    function renderDevices(data, view) {
        var wrap = document.getElementById('brikpanel-device-breakdown');
        if (!wrap) return;
        var mobile  = data ? (data.mobile  || 0) : 0;
        var tablet  = data ? (data.tablet  || 0) : 0;
        var desktop = data ? (data.desktop || 0) : 0;
        var total   = mobile + tablet + desktop;

        if (total === 0) {
            showEmpty(wrap, view === 'orders' ? emptyReason('orders', 'site') : emptyReason('visits'));
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
                + '<span class="brikpanel-device-pct">' + escapeHtml(fmtPct(r.p, 0)) + '</span>'
                + '<span class="brikpanel-device-count brikpanel-dash-muted">(' + formatNumber(r.count) + ')</span>'
                + '</div>';
        });
        html += '</div>';
        wrap.innerHTML = html;
    }

    function renderCustomerTypes(data) {
        var wrap = document.getElementById('brikpanel-customer-types');
        if (!wrap) return;
        var newC    = data ? (data['new']    || 0) : 0;
        var repeatC = data ? (data['repeat'] || 0) : 0;
        var total   = newC + repeatC;

        // Read from WooCommerce's analytics table, which can lag behind new
        // orders: emptyReason() keeps the plain sentence while the window
        // does have paid orders.
        if (total === 0) {
            showEmpty(wrap, emptyReason('orders', 'all'));
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
                + '<span class="brikpanel-device-pct">' + escapeHtml(fmtPct(r.p, 0)) + '</span>'
                + '<span class="brikpanel-device-count brikpanel-dash-muted">(' + formatNumber(r.count) + ')</span>'
                + '</div>';
        });
        html += '</div>';
        wrap.innerHTML = html;
    }

    // Channel labels for the Traffic Sources card. Falls back to English so the
    // bars never render blank if a key is missing from the localize payload.
    function sourceChannelLabel(channel) {
        var map = {
            direct:   i18n.src_direct   || 'Direct',
            search:   i18n.src_search   || 'Organic Search',
            social:   i18n.src_social   || 'Social',
            referral: i18n.src_referral || 'Referral',
            paid:     i18n.src_paid     || 'Paid',
            email:    i18n.src_email    || 'Email'
        };
        return map[channel] || channel;
    }

    // Returns true when it said no visits were counted at all, so the empty
    // referrer list under it need not say so a second time.
    function renderSources(data) {
        // Shares the breakdown container with the device views (same panel, tabbed).
        var wrap = document.getElementById('brikpanel-device-breakdown');
        if (!wrap) return false;

        // Fixed display order; hide empty channels so the card stays clean.
        var order = ['direct', 'search', 'social', 'referral', 'paid', 'email'];
        var total = 0;
        if (data) {
            order.forEach(function (k) { total += (data[k] || 0); });
        }

        if (total === 0) {
            // Visits counted but none with a known source keeps its own sentence.
            showEmpty(wrap, emptyReason('visits', null, i18n.src_empty));
            var t = emptyCtx.tracking;
            return !!t && (!t.enabled || emptyCtx.visitors <= 0);
        }

        var pct = function (n) { return total > 0 ? Math.round((n / total) * 100) : 0; };

        var rows = order
            .map(function (k) { return { label: sourceChannelLabel(k), count: data[k] || 0, p: pct(data[k] || 0) }; })
            .filter(function (r) { return r.count > 0; })
            .sort(function (a, b) { return b.count - a.count; });

        var html = '<div class="brikpanel-device-list">';
        rows.forEach(function (r) {
            html += '<div class="brikpanel-device-row">'
                + '<span class="brikpanel-device-label">' + escapeHtml(r.label) + '</span>'
                + '<div class="brikpanel-device-bar-wrap">'
                +   '<div class="brikpanel-device-bar" style="width:' + r.p + '%"></div>'
                + '</div>'
                + '<span class="brikpanel-device-pct">' + escapeHtml(fmtPct(r.p, 0)) + '</span>'
                + '<span class="brikpanel-device-count brikpanel-dash-muted">(' + formatNumber(r.count) + ')</span>'
                + '</div>';
        });
        html += '</div>';
        wrap.innerHTML = html;
        return false;
    }

    function renderTopReferrers(list) {
        var wrap = document.getElementById('brikpanel-top-referrers');
        if (!wrap) return;
        if (!list || !list.length) {
            showEmpty(wrap, { text: i18n.src_no_referrers || '', note: '', preset: '' });
            return;
        }

        var html = '<ul class="brikpanel-referrer-list">';
        list.forEach(function (r) {
            html += '<li class="brikpanel-referrer-row">'
                + '<span class="brikpanel-referrer-host">' + escapeHtml(r.host || '') + '</span>'
                + '<span class="brikpanel-referrer-channel">' + escapeHtml(sourceChannelLabel(r.channel)) + '</span>'
                + '<span class="brikpanel-referrer-hits brikpanel-dash-muted">' + formatNumber(r.hits || 0) + '</span>'
                + '</li>';
        });
        html += '</ul>';
        wrap.innerHTML = html;
    }

    var rfmDonutChart = null;
    function renderRfmSegments(segments) {
        var wrap = document.getElementById('brikpanel-rfm-segments');
        if (!wrap) return;
        if (!segments || segments.length === 0) {
            // The card covers all time, so not the "this period" sentence: the
            // same one the lifetime value card shows (field test D7). A store
            // with no order yet has no customers to wait for.
            var st = emptyCtx.store;
            showEmpty(wrap, {
                text: (st && !st.has_any_order ? i18n.empty_rfm_new : i18n.ltv_empty) || i18n.ltv_empty || '',
                note: '',
                preset: ''
            });
            return;
        }

        var chartId = 'brikpanel-rfm-donut-canvas';
        var legendHtml = '<div style="flex:1;min-width:200px;display:grid;grid-template-columns:1fr 1fr;gap:0.375rem 1rem;font-size:0.8125rem;">';
        segments.forEach(function (s) {
            legendHtml += '<div style="display:flex;align-items:center;gap:0.5rem;">'
                + '<span style="width:8px;height:8px;border-radius:50%;background:' + s.color + ';flex-shrink:0"></span>'
                + '<span style="flex:1;color:#303030;">' + escapeHtml(s.label) + '</span>'
                + '<span style="color:#616161;font-variant-numeric:tabular-nums;">' + escapeHtml(formatNumber(s.customers) + ' (' + fmtPct(s.share) + ')') + '</span>'
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
                                return ctx.label + ': ' + formatNumber(ctx.parsed) + ' (' + fmtPct(pct, 0) + ')';
                            }
                        }
                    }
                }
            }
        });
    }

    function renderLowStock(products, empty) {
        var wrap = document.getElementById('low-stock-table');
        if (!wrap) return;

        if (!products || products.length === 0) {
            // The server says why nothing is listed: no products, stock not
            // tracked, or nothing low (plus how many ran out, with a link).
            var p = document.createElement('p');
            p.className = 'brikpanel-dash-empty';
            p.textContent = (empty && empty.text) || '';
            if (empty && empty.link && empty.url) {
                var a = document.createElement('a');
                a.className = 'brikpanel-dash-empty-link';
                a.href = empty.url;
                a.textContent = empty.link;
                p.appendChild(document.createElement('br'));
                p.appendChild(a);
            }
            wrap.textContent = '';
            wrap.appendChild(p);
            return;
        }

        var html = '<table class="brikpanel-dash-table"><thead><tr>' +
            '<th>' + (i18n.product || 'Product') + '</th>' +
            '<th>' + (i18n.sku || 'SKU') + '</th>' +
            '<th>' + (i18n.stock || 'Remaining') + '</th>' +
            '</tr></thead><tbody>';

        products.forEach(function (p) {
            var nameCell = p.edit_url
                ? '<a href="' + escapeAttr(p.edit_url) + '" style="color:#303030;text-decoration:none;font-weight:500;">' + escapeHtml(p.name) + '</a>'
                : escapeHtml(p.name);
            html += '<tr>' +
                '<td class="brikpanel-fit-lead">' + nameCell + '</td>' +
                '<td class="brikpanel-dash-muted">' + (p.sku ? skuHtml(p.sku) : '&mdash;') + '</td>' +
                '<td><span class="brikpanel-dash-badge-warning">' + p.stock + '</span></td>' +
                '</tr>';
        });

        html += '</tbody></table>';
        wrap.innerHTML = html;
        refitDashTable(wrap);
    }

    function renderLtvPanel(data) {
        var wrap = document.getElementById('brikpanel-ltv-panel');
        if (!wrap) return;

        if (!data || !data.total_customers) {
            var st = emptyCtx.store;
            showEmpty(wrap, {
                text: (st && !st.has_any_order ? i18n.empty_ltv_new : i18n.ltv_empty) || i18n.ltv_empty || '',
                note: '',
                preset: ''
            });
            return;
        }

        var html = '<div class="brikpanel-dash-ltv">' +
            '<div class="brikpanel-dash-ltv-headline">' +
                '<span class="brikpanel-dash-ltv-value">' + data.avg_ltv + '</span>' +
                '<span class="brikpanel-dash-ltv-label">' + (i18n.average_ltv || 'Average LTV') + '</span>' +
            '</div>' +
            '<table class="brikpanel-dash-table brikpanel-dash-returns-breakdown"><tbody>' +
                '<tr><td>' + (i18n.total_customers || 'Total customers') + '</td><td><strong>' + formatNumber(data.total_customers) + '</strong></td></tr>' +
                '<tr><td>' + (i18n.repeat_customers || 'Repeat customers') + '</td><td><strong>' + escapeHtml(formatNumber(data.repeat_customers) + ' (' + fmtPct(data.repeat_rate) + ')') + '</strong></td></tr>' +
                '<tr><td>' + (i18n.total_lifetime_value || 'Total lifetime value') + '</td><td><strong>' + data.total_ltv + '</strong></td></tr>' +
                '<tr><td>' + (i18n.top_customer_ltv || 'Top customer') + '</td><td><strong>' + data.max_ltv + '</strong></td></tr>' +
            '</tbody></table>' +
        '</div>';

        wrap.innerHTML = html;
    }

    function renderSubscriptions(data) {
        var wrap = document.getElementById('brikpanel-subscriptions-wrap');
        if (!wrap) return;

        var subsTotal = 0;
        if (Array.isArray(data)) {
            data.forEach(function (it) { subsTotal += Number(it.count) || 0; });
        }
        // The server always lists every status, so "empty" is a zero total.
        if (subsTotal === 0) {
            showEmpty(wrap, { text: i18n.empty_no_subscriptions || i18n.no_data || '', note: '', preset: '' });
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
                html += '<span class="brikpanel-subs-card-pct">' + escapeHtml(fmtPct(pct, 0)) + '</span>';
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
        img.alt = i18n.globe_alt || 'Order locations globe';
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

    // Undo what the last globe left on its box: the circle size
    // setupGlobeLabels() wrote on it and the slow-device picture that
    // createStaticGlobeFallback() put in place of the canvas.
    function resetGlobeBox(container, keepSize) {
        if (!keepSize) {
            ['width', 'height', 'borderRadius', 'overflow', 'margin'].forEach(function (k) {
                container.style[k] = '';
            });
        }
        var stills = container.querySelectorAll('.brikpanel-globe-static-wrap');
        for (var i = 0; i < stills.length; i++) {
            stills[i].parentNode.removeChild(stills[i]);
        }
        var canvas = document.getElementById('brikpanel-globe');
        if (canvas) canvas.style.display = '';
    }

    // No order to place: remove the globe, hide its box and show why (r).
    // With r = null the box comes back first, visible and measurable, for
    // createGlobeInstance() (which reads its size) to draw in.
    function setGlobeEmpty(r) {
        var container = document.getElementById('globe-container');
        if (!container) return;
        var panel = container.parentElement;
        var msg = panel ? panel.querySelector('.brikpanel-dash-globe-empty') : null;

        if (r) {
            if (globeInstance) {
                globeInstance.destroy();
                globeInstance = null;
            }
            resetGlobeBox(container, false);
            if (msg) {
                fillEmpty(msg, r);
                msg.hidden = false;
                container.hidden = true;
            }
            return;
        }

        if (msg) msg.hidden = true;
        // Back from hidden: measure the panel afresh. A globe that is simply
        // redrawn (Orders / Customers) keeps its box as it is.
        var wasHidden = container.hidden;
        container.hidden = false;
        resetGlobeBox(container, !wasHidden);
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
        var title   = document.getElementById('brikpanel-device-title');
        var refWrap = document.getElementById('brikpanel-source-referrers');

        if (view === 'sources') {
            if (title) { title.textContent = i18n.src_title || 'Traffic sources'; }
            // No visits at all: the sources line already says why, so the
            // referrer list under it stays out of sight.
            var noVisits = renderSources(sourceData);
            renderTopReferrers(topReferrersData);
            if (refWrap) { refWrap.style.display = noVisits ? 'none' : ''; }
            return;
        }

        if (title) {
            title.textContent = view === 'orders'
                ? (i18n.device_title_orders   || 'Orders by device')
                : (i18n.device_title_visitors || 'Visitors by device');
        }
        if (refWrap) { refWrap.style.display = 'none'; }
        renderDevices(deviceData[view], view);
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

        // Rebuild globe with new marker sizes (always recreate to update arc
        // routing too). Nothing to place: the reason instead of a blank
        // 450px box (field test F3).
        if (globeMarkers.length > 0) {
            setGlobeEmpty(null);
            createGlobeInstance();
        } else {
            setGlobeEmpty(emptyReason('orders', 'site'));
        }

        // Update titles
        var globeTitle = document.getElementById('globe-panel-title');
        var countriesTitle = document.getElementById('loc-panel-countries-title');
        var citiesTitle = document.getElementById('loc-panel-cities-title');
        if (globeTitle) globeTitle.textContent = view === 'customers' ? (i18n.loc_cust_locations || 'Customer locations') : (i18n.loc_order_locations || 'Order locations');
        if (countriesTitle) countriesTitle.textContent = view === 'customers' ? (i18n.loc_top_countries_customers || 'Top countries by customers') : (i18n.loc_top_countries_orders || 'Top countries by orders');
        if (citiesTitle) citiesTitle.textContent = view === 'customers' ? (i18n.loc_top_cities_customers || 'Top cities by customers') : (i18n.loc_top_cities_orders || 'Top cities by orders');

        renderTopCountries(countries, view);
        renderTopCities(cities, view);

        // No country means no city either: one reason in this panel, not two.
        var noPlaces = countries.length === 0;
        var citiesWrap = document.getElementById('top-cities-table');
        if (citiesTitle) citiesTitle.hidden = noPlaces;
        if (citiesWrap) citiesWrap.hidden = noPlaces;
    }

    function renderTopCountries(countries, view) {
        var wrap = document.getElementById('top-countries-table');
        if (!wrap) return;

        if (!countries || countries.length === 0) {
            showEmpty(wrap, emptyReason('orders', 'site'));
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
            showEmpty(wrap, emptyReason('orders', 'site'));
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
        refitDashTable(wrap);
    }

    // =========================================================================
    // LIVE VISITORS POLLING
    // =========================================================================

    function startLivePolling() {
        if (liveInterval || liveStale) return;
        fetchLiveVisitors();
        // 30s: a visitor stays "live" for at least 75s after their last ping,
        // so a faster poll only adds server load without showing anything new.
        liveInterval = setInterval(fetchLiveVisitors, 30000);
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
        fd.append('security', CFG.nonce || '');

        fetch(CFG.ajax_url, {
            method: 'POST',
            body: fd,
            credentials: 'same-origin'
        })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            if (liveStale) return;
            if (!res || !res.success) {
                // Refused: the nonce printed with the page expired (a tab left
                // open for a day) or the session ended. Every later poll would
                // be refused too, and the last list would stay on screen as if
                // it were live. Stop, and say how to get it back.
                liveStale = true;
                stopLivePolling();
                renderLiveStale();
                return;
            }
            renderLiveVisitors(res.data);
        })
        .catch(function () {});
    }

    // The Live list after the server refused it: no count (it is no longer
    // known) and a line asking for a reload, which brings a fresh nonce.
    function renderLiveStale() {
        var countEl = document.getElementById('live-count');
        var listEl = document.getElementById('live-visitors-list');
        liveListEmpty = false;
        if (countEl) countEl.hidden = true;
        if (listEl) showEmpty(listEl, { text: i18n.live_reload || '', note: '', preset: '' });
    }

    // Why the Live list is empty. It shows who is on the store right now, so
    // the selected window says nothing about it: only tracking being off is
    // a reason; otherwise nobody happens to be on the store.
    function liveEmptyReason() {
        var t = emptyCtx.tracking;
        var text = (t && !t.enabled && i18n.empty_tracking_off) ? i18n.empty_tracking_off : (i18n.no_visitors || '');
        return { text: text, note: '', preset: '' };
    }

    // Device icon for a live visitor row.
    //
    // The server stores a three-value keyword (mobile | tablet | desktop), but
    // it is still whitelisted here rather than interpolated: the markup is
    // built as a string, so an unexpected value must never reach innerHTML.
    // Rows written before this feature shipped have no device at all (the
    // transient lives for up to 120s), and rather than claim "desktop" we emit
    // an empty span of the same width so the column stays aligned.
    var LIVE_DEVICE_PATHS = {
        mobile: '<rect x="7" y="2" width="10" height="20" rx="2" ry="2"></rect><line x1="12" y1="18" x2="12.01" y2="18"></line>',
        tablet: '<rect x="4" y="2" width="16" height="20" rx="2" ry="2"></rect><line x1="12" y1="18" x2="12.01" y2="18"></line>',
        desktop: '<rect x="2" y="3" width="20" height="14" rx="2" ry="2"></rect><line x1="8" y1="21" x2="16" y2="21"></line><line x1="12" y1="17" x2="12" y2="21"></line>'
    };

    function liveDeviceLabel(device) {
        if (device === 'mobile') return i18n.device_mobile || 'Mobile';
        if (device === 'tablet') return i18n.device_tablet || 'Tablet';
        if (device === 'desktop') return i18n.device_desktop || 'Desktop';
        return '';
    }

    function liveDeviceIcon(device) {
        // hasOwnProperty, not a plain lookup: a bare LIVE_DEVICE_PATHS[device]
        // would happily return an inherited member ("constructor", "toString")
        // for a value that is not one of ours and splice it into the markup.
        if (!Object.prototype.hasOwnProperty.call(LIVE_DEVICE_PATHS, device)) {
            return '<span class="brikpanel-dash-live-device" aria-hidden="true"></span>';
        }

        // Attribute context: the label comes from a translation file, which is
        // exactly the kind of string that can carry an unexpected quote.
        var label = escapeAttr(liveDeviceLabel(device));

        // No title attribute on purpose: the row already carries the card's own
        // tooltip, and a native one on top of it would show two tooltips at
        // once. The device label is added to that tooltip instead.
        return '<span class="brikpanel-dash-live-device" role="img" aria-label="' + label + '">' +
            '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' +
            LIVE_DEVICE_PATHS[device] +
            '</svg></span>';
    }

    function renderLiveVisitors(visitors) {
        var countEl = document.getElementById('live-count');
        var listEl = document.getElementById('live-visitors-list');
        if (!countEl || !listEl) return;

        visitors = Array.isArray(visitors) ? visitors : [];
        countEl.textContent = formatNumber(visitors.length);
        countEl.hidden = false;
        liveListEmpty = visitors.length === 0;

        if (visitors.length === 0) {
            showEmpty(listEl, liveEmptyReason());
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
                badgeText = i18n.order_received || 'Order received';
            } else if (status === 'cart') {
                badgeClass = 'added-to-cart';
                badgeText = i18n.added_to_cart || 'Added to cart';
            } else {
                badgeClass = 'browsing';
                badgeText = i18n.browsing || 'Browsing';
            }

            // Display name: customer name or IP
            var displayName = v.customer_name ? escapeHtml(v.customer_name) : (v.ip_address || '');
            var ipLabel = v.ip_address ? '<span class="brikpanel-dash-live-ip">' + escapeHtml(v.ip_address) + '</span>' : '';

            // The page's name (product, page, category) when the server could
            // find one; the address otherwise (search results, older trackers).
            var pageTitle = (typeof v.page_title === 'string') ? v.page_title : '';

            // Where the visitor came from ("Traffic source in Live view"): the
            // channel as a small pill, then the source, campaign and search
            // term, cut with "…" when long.
            //
            // dir="auto" here and on the page name: the line takes the
            // direction of its own text, so a long English name on a
            // right-to-left screen is cut at its end, not at its start.
            var src = (v.source && typeof v.source === 'object' && v.source.channel) ? v.source : null;
            var srcChannel = src ? sourceChannelLabel(src.channel) : '';
            var srcBits = src ? [src.name, src.campaign, src.term].filter(function (b) {
                return typeof b === 'string' && b !== '';
            }) : [];
            var srcHtml = src
                ? '<span class="brikpanel-dash-live-src">' +
                    '<span class="brikpanel-dash-live-src-ch">' + escapeHtml(srcChannel) + '</span>' +
                    (srcBits.length ? '<span class="brikpanel-dash-live-src-name" dir="auto">' + escapeHtml(srcBits.join(' · ')) + '</span>' : '') +
                  '</span>'
                : '';

            // Tooltip data for hover
            var tooltipParts = [];
            var deviceLabel = liveDeviceLabel(v.device);
            if (deviceLabel) tooltipParts.push(deviceLabel);
            if (v.customer_email) tooltipParts.push(v.customer_email);
            if (v.customer_phone) tooltipParts.push(v.customer_phone);
            if (pageTitle) tooltipParts.push(pageTitle);
            if (v.page_url) tooltipParts.push(v.page_url);
            if (src) {
                tooltipParts.push(liveSourceLine(i18n.live_src_source, srcChannel + (src.name ? ' · ' + src.name : '')));
                if (src.medium) tooltipParts.push(liveSourceLine(i18n.live_src_medium, src.medium));
                if (src.campaign) tooltipParts.push(liveSourceLine(i18n.live_src_campaign, src.campaign));
                if (src.term) tooltipParts.push(liveSourceLine(i18n.live_src_term, src.term));
                if (src.landing) tooltipParts.push(liveSourceLine(i18n.live_src_landing, src.landing));
            }
            var tooltipData = tooltipParts.length > 0 ? ' data-bp-tooltip="' + escapeAttr(tooltipParts.join('\n')) + '"' : '';

            html += '<div class="brikpanel-dash-live-item"' + tooltipData + '>' +
                liveDeviceIcon(v.device) +
                '<div class="brikpanel-dash-live-info">' +
                    '<span class="brikpanel-dash-live-name">' + displayName + '</span>' +
                    (v.customer_name ? ipLabel : '') +
                    // No title attribute: the row's own hover card already
                    // carries the full name and address, and a native tooltip
                    // would open on top of it.
                    '<span class="brikpanel-dash-live-page" dir="auto">' + escapeHtml(pageTitle || pagePath) + '</span>' +
                    srcHtml +
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

    // One hover-card line for a live visitor's source ("Campaign: %s"). The
    // template is translated server-side; without it the bare value still
    // reads fine, so no English is baked in here.
    // A function replacement, because the value comes from a link: a string
    // one would treat "$&" or "$1" inside a campaign name as patterns.
    function liveSourceLine(tpl, value) {
        var text = String(value);
        return (typeof tpl === 'string' && tpl.indexOf('%s') !== -1)
            ? tpl.replace('%s', function () { return text; })
            : text;
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
            // Each line takes the direction of its own text, so on a
            // right-to-left screen "Landing page: /" or an email address is
            // not reordered around its punctuation.
            p.dir = 'auto';
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
        updateCard('card-mp-share',  escapeHtml(fmtPct(totals.share_total_pct || 0)));

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
        // No marketplace order in the window: every marketplace card below
        // gives the same reason (no order ever, or the latest paid day).
        var mpRows = mp.by_marketplace || [];
        var listEl = document.getElementById('brikpanel-mp-list');
        if (listEl) {
            var rows = mpRows;
            if (rows.length === 0) {
                showEmpty(listEl, emptyReason('orders', 'all'));
            } else {
                var html = '';
                rows.forEach(function (row) {
                    var initial = (row.label || '?').charAt(0).toUpperCase();
                    var deltaClass = row.delta_revenue > 0 ? 'positive' : (row.delta_revenue < 0 ? 'negative' : 'neutral');
                    var deltaText  = row.delta_revenue === 0 ? '--' : (row.delta_revenue > 0 ? '+' : '') + fmtPct(row.delta_revenue);
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
                            '<span class="brikpanel-dash-mp-share">' + escapeHtml(fmtPct(row.revenue_share)) + '</span>' +
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
            if (cats.length === 0 && mpRows.length === 0) {
                showEmpty(catEl, emptyReason('orders', 'all'));
            } else if (cats.length === 0) {
                // Categories require marketplace items to be linked to a WC
                // product (via _product_id or _marketplace_sku). Make the
                // empty state explain that — generic "No data" is misleading
                // when the user has marketplace orders but no mapped catalog.
                showEmpty(catEl, { text: i18n.mp_no_categories || '', note: '', preset: '' });
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
                        '<td>' + escapeHtml(fmtPct(c.share)) + '</td>' +
                        '</tr>';
                });
                ch += '</tbody></table>';
                catEl.innerHTML = ch;
                refitDashTable(catEl);
            }
        }

        // Top products.
        var prEl = document.getElementById('brikpanel-mp-products');
        if (prEl) {
            var products = mp.top_products || [];
            if (products.length === 0) {
                showEmpty(prEl, emptyReason('orders', 'all'));
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
                refitDashTable(prEl);
            }
        }
    }

    function renderMarketplaceShareChart(rows) {
        var canvas = document.getElementById('brikpanel-mp-share-chart');
        if (!canvas || typeof Chart === 'undefined') return;

        rows = Array.isArray(rows) ? rows : [];
        var labels = rows.map(function (r) { return r.label; });
        var data   = rows.map(function (r) { return r.revenue; });
        var colors = rows.map(function (r) { return r.color; });

        // No marketplace revenue: the reason instead of an empty ring.
        if (!data.some(function (v) { return Number(v) > 0; })) {
            setChartEmpty(canvas, emptyReason('orders', 'all'));
            return;
        }
        var wasHidden = setChartEmpty(canvas, null);

        if (mpShareChart) {
            mpShareChart.data.labels = labels;
            mpShareChart.data.datasets[0].data = data;
            mpShareChart.data.datasets[0].backgroundColor = colors;
            if (wasHidden) mpShareChart.resize();
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
                                var pct   = total > 0 ? (ctx.parsed / total) * 100 : 0;
                                return ctx.label + ': ' + fmtPct(pct, 1);
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
            if (labelEl) labelEl.textContent = (i18n.summary_failed || '');
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

    // Escape for a value that lands inside a double-quoted HTML attribute.
    //
    // escapeHtml() is not enough there: it serialises a text node, which
    // encodes & < > but deliberately leaves the double quote alone (a quote is
    // legal text content). In an attribute the quote closes it early and
    // everything after is parsed as further attributes — an event handler in a
    // customer-supplied billing phone becomes a real onmouseover on the row.
    function escapeAttr(str) {
        return escapeHtml(str).replace(/"/g, '&quot;');
    }

})();
