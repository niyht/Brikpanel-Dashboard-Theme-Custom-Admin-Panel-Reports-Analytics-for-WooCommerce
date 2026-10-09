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

    // The redesigned cards (brikpanel-dashboard-viz.js draws them).
    var V = window.brikpanelDashViz || null;
    var lastData = null;               // the payload last drawn, for redraws on resize and tab switches
    var salesMetric = 'r';             // Sales over time shows revenue, orders ('o') or average order value ('aov')
    var playNext = false;              // set by a range change: the cards in view animate
    var firstData = true;              // the first payload: only cards below the first screen animate, when reached
    var pendingPlay = typeof WeakMap === 'function' ? new WeakMap() : null;
    var liveRows = null;               // the last Live list from the poll (null before the first answer)
    var liveSig = '';                  // what that list showed, to redraw only on a change
    var liveOpen = false;              // "N more on the store" opened
    var LIVE_SHOW = 5;                 // rows shown before "N more on the store"
    var todayInfo = null;              // { block: today's figures, serverNow, clientAt } for the live card

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
        initDvTabs();
        initDvResize();
        initCopySummary();
        initExportButton();
        initRowLinks();
        initPagesWindow();
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
                playNext = true;
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
                playNext = true;
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

            lastData = d;
            todayInfo = { block: d.today || null, serverNow: Number(d.now) || Math.floor(Date.now() / 1000), clientAt: Date.now() / 1000 };

            // Summary cards
            safe('cards', function () {
                var deltas = d.deltas || {};
                updateCard('card-total-sales', d.total_sales);
                updateCard('card-orders', d.order_count_display != null ? d.order_count_display : formatNumber(d.order_count));
                updateCard('card-aov', d.aov);
                updateCard('card-visitors', d.visitor_count_display != null ? d.visitor_count_display : formatNumber(d.visitor_count));
                // The server sends the rate as a finished percentage (store
                // separators, the viewer's sign position); a payload cached
                // before that still carries the bare number.
                updateCard('card-conversion', d.conversion_rate_pct != null ? d.conversion_rate_pct : fmtPct(d.conversion_rate, 2));
                updateDelta('delta-total-sales', deltas.sales);
                updateDelta('delta-orders', deltas.orders);
                updateDelta('delta-aov', deltas.aov);
                updateDelta('delta-visitors', deltas.visitors);
                updateDelta('delta-conversion', deltas.conversion);
                // Units sold in the same paid orders: beside the Orders change
                // and at the head of the Order rates card.
                setItemsSold('card-items-sold', d.items_sold_label);
                setItemsSold('rates-items-sold', d.items_sold_label);
            });

            // Profit (Revenue − Cost of goods − Expenses), the store cards'
            // small lines and the figures counting up.
            safe('profit', function () { renderProfit(d.profit); });
            safe('sparks', function () { renderKpiSparks(d); });
            safe('countUp', function () { playKpis(d); });

            safe('sales', function () { renderSales(d); });
            safe('funnel', function () { renderFunnel(d); });
            safe('cartab', function () { renderAbandonedCarts(d.abandoned_carts, d.abandoned_carts_on); });
            safe('rates', function () { renderRates(d); });

            // Globe + Tables
            safe('locations', function () {
                locationsData = d.order_locations;
                applyLocView(locView);
            });

            safe('products', function () { renderProducts(d); });
            safe('orders', function () { renderOrders(d.recent_orders); });
            safe('visitors', function () { renderVisitors(d); });
            safe('customers', function () { renderCustomers(d); });
            safe('stock', function () { renderLowStock(d.low_stock, d.low_stock_empty); });
            safe('ltv', function () { renderLtvPanel(d.ltv_panel); });
            safe('subscriptions', function () { renderSubscriptions(d.subscription_stats); });
            // Marketplace analytics (BrikMarket-only).
            safe('marketplace', function () { renderMarketplaceAnalytics(d.marketplace); });

            // The live card's "Today so far" (and whether tracking is off)
            // came with this data.
            safe('live', function () { renderLive(); });

            firstData = false;
            playNext = false;
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
        setDelta(el, value, false);
    }

    // inverse: a rise is the bad direction (more abandoned carts), so it gets
    // the error colour and a fall the success one; the arrow still shows the
    // direction of the change.
    function setDelta(el, value, inverse) {
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
        var good  = inverse ? value < 0 : value > 0;
        el.textContent = arrow + ' ' + formatDeltaPct(Math.abs(value));
        el.className = 'brikpanel-dash-card-delta ' + (good ? 'positive' : 'negative');
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
    // REDESIGNED CARDS (October 2026)
    //
    // Sales over time, Conversion funnel, Order rates, Products, Recent
    // orders, Visitors and Customers are drawn as SVG by
    // brikpanel-dashboard-viz.js (window.brikpanelDashViz), which holds no
    // text: every word and number below comes from the localized i18n bag and
    // window.brikpanelFormat. Each card can appear more than once (Settings
    // can split a merged card, plan_merged_cards() in PHP), so every renderer
    // draws every card of its kind, found by [data-bp-dv].
    // =========================================================================

    function dvCards(kind) {
        return Array.prototype.slice.call(document.querySelectorAll('[data-bp-dv="' + kind + '"]'));
    }

    function dvSlot(root, name) {
        return root ? root.querySelector('[data-bp-dv-slot="' + name + '"]') : null;
    }

    // A card animates once: right away after a range change when it is in
    // view, otherwise the first time it comes into view. On the first load
    // the cards already on screen simply stand finished.
    function schedulePlay(card, run) {
        if (!card || !V || V.reduced()) return;
        if (pendingPlay) {
            var prev = pendingPlay.get(card);
            if (prev) {
                prev.disconnect();
                pendingPlay.delete(card);
            }
        }
        var later = function () {
            var io = V.onView(card, run, 0.3);
            if (io && pendingPlay) pendingPlay.set(card, io);
        };
        if (firstData) {
            if (card.getBoundingClientRect().top > (window.innerHeight || 0)) later();
            return;
        }
        if (!playNext) return;
        if (V.inView(card, 0.3)) run(); else later();
    }

    function money(v, decimals) {
        if (!BF) return String(Number(v) || 0);
        return decimals == null ? BF.money(v) : BF.money(v, { decimals: decimals });
    }

    // "2.41% converted" with the figure in bold: the pattern's %s (or %1$s,
    // %2$s ...) becomes the given values, which may be DOM nodes.
    function fillNodes(el, pattern, values) {
        el.textContent = '';
        var vals = Array.isArray(values) ? values : [values];
        var next = 0;
        var re = /%(?:(\d+)\$)?([sd%])/g;
        var str = String(pattern == null ? '' : pattern);
        var last = 0;
        var m;
        while ((m = re.exec(str)) !== null) {
            if (m.index > last) el.appendChild(document.createTextNode(str.slice(last, m.index)));
            if (m[2] === '%') {
                el.appendChild(document.createTextNode('%'));
            } else {
                var v = m[1] ? vals[parseInt(m[1], 10) - 1] : vals[next++];
                if (v && typeof v === 'object' && v.nodeType) el.appendChild(v);
                else el.appendChild(document.createTextNode(v == null ? '' : String(v)));
            }
            last = re.lastIndex;
        }
        if (last < str.length) el.appendChild(document.createTextNode(str.slice(last)));
        return el;
    }

    function bold(text) {
        var b = document.createElement('b');
        var bdi = document.createElement('bdi');
        bdi.textContent = text;
        b.appendChild(bdi);
        return b;
    }

    // Text that keeps its own reading order inside a right-to-left page
    // ("99 orders" stays "99 orders" next to Arabic or Hebrew).
    function setIsolated(el, text) {
        if (!el) return;
        el.textContent = '';
        if (!text) return;
        var bdi = document.createElement('bdi');
        bdi.textContent = text;
        el.appendChild(bdi);
    }

    // A count in a plural sentence with the number in bold: "84 visitors".
    function countNodes(el, msg, n) {
        return fillNodes(el, BF ? BF.plural(msg, n) : '%s', [bold(formatNumber(n))]);
    }

    function dvDelta(el, value, inverse) {
        if (!el) return;
        el.textContent = '';
        el.className = el.className.replace(/\s*\bis-(up|down)\b/g, '');
        if (value === null || value === undefined || !isFinite(value) || Number(value) === 0) {
            el.hidden = true;
            return;
        }
        var good = inverse ? value < 0 : value > 0;
        el.textContent = (value > 0 ? '↑' : '↓') + ' ' + formatDeltaPct(Math.abs(value));
        el.className += good ? ' is-up' : ' is-down';
        el.hidden = false;
    }

    function calcDelta(cur, prev) {
        if (!prev && !cur) return 0;
        if (!prev) return null;
        return Math.round((cur - prev) / Math.abs(prev) * 1000) / 10;
    }

    function timeFmt() {
        return (BF && BF.l10n && BF.l10n.timeFormat) || 'H:i';
    }

    function dayMonthFmt() {
        return (BF && BF.l10n && BF.l10n.dayMonth) || 'M j';
    }

    // "09:00" / "9:00 am" for an hour of the day in the store's time format.
    function hourLabel(h, minutes) {
        var hh = (h < 10 ? '0' : '') + h;
        return BF ? BF.date('2000-01-01 ' + hh + ':' + (minutes || '00'), timeFmt()) : hh + ':' + (minutes || '00');
    }

    function agoText(seconds) {
        var s = Math.max(0, Math.floor(Number(seconds) || 0));
        if (s < 60) return i18n.just_now || '';
        if (!BF) return '';
        if (s < 3600) return BF.count(i18n.min_ago, Math.floor(s / 60));
        if (s < 86400) return BF.count(i18n.h_ago, Math.floor(s / 3600));
        return BF.count(i18n.d_ago, Math.floor(s / 86400));
    }

    // The language of the page for letter case ("i" becomes "İ" in Turkish).
    function pageLang() {
        var l = (BF && BF.locale) || document.documentElement.lang || '';
        return String(l).replace('_', '-') || undefined;
    }

    function monogram(name) {
        var words = String(name || '').trim().split(/\s+/).filter(Boolean).slice(0, 2);
        var out = words.map(function (w) { return Array.from ? Array.from(w)[0] : w.charAt(0); }).join('');
        try {
            return out.toLocaleUpperCase(pageLang());
        } catch (e) {
            return out.toUpperCase();
        }
    }

    // ------------------------------------------------------------------ KPI rows

    // The small line of the period in each store card. Today's point of a
    // day range is left out (a morning dip is not a trend); within a single
    // day the hours add up as they pass.
    function renderKpiSparks(d) {
        var slots = document.querySelectorAll('.bp-dv-spark[data-spark]');
        if (!slots.length) return;
        sizeKpis();
        var ss = d && d.sales_series;
        var cur = (ss && Array.isArray(ss.cur)) ? ss.cur : [];
        var day = !!ss && ss.unit === 'day';
        var pts = cur.slice();
        if (day && ss.today !== null && ss.today === pts.length - 1) pts = pts.slice(0, -1);
        var long = day && pts.length > 7;
        var k1 = long ? 1 : 0;
        var k2 = long ? 3 : 0;
        var useAll = pts.some(function (x) { return x && x.ra !== undefined; });
        var run = function (pick) {
            if (day) return pts.map(function (x) { return x ? pick(x) : null; });
            var cr = 0;
            var co = 0;
            return pts.map(function (x) {
                if (!x) return null;
                cr += Number(useAll && x.ra !== undefined ? x.ra : x.r) || 0;
                co += Number(x.o) || 0;
                return pick({ r: cr, o: co });
            });
        };
        var series = {
            r: V ? V.smooth(run(function (x) { return Number(useAll && x.ra !== undefined ? x.ra : x.r) || 0; }), k1) : [],
            o: V ? V.smooth(run(function (x) { return Number(x.o) || 0; }), k1) : [],
            aov: V ? V.smooth(run(function (x) { return Number(x.o) ? (Number(x.r) || 0) / Number(x.o) : null; }), k2) : [],
            v: day && V ? V.smooth(pts.map(function (x) { return x ? Number(x.v) || 0 : null; }), k1) : [],
            conv: day && V ? V.smooth(pts.map(function (x) { return x && Number(x.v) ? (Number(x.o) || 0) / Number(x.v) : null; }), k2) : []
        };
        Array.prototype.forEach.call(slots, function (slot) {
            var vals = series[slot.getAttribute('data-spark')] || [];
            // A flat line at zero (nothing sold yet today) says nothing the
            // figure does not.
            var moves = vals.some(function (v) { return v !== null && v !== 0; });
            // Measured while shown: a hidden slot has no width, and one left
            // hidden here would never be measured again when its card widens.
            slot.classList.remove('is-empty');
            var w = slot.clientWidth;
            var svg = (V && w > 0 && vals.length > 1 && moves) ? V.spark(vals, w, 30, V.isRtl(slot)) : '';
            slot.innerHTML = svg;
            slot.classList.toggle('is-empty', !svg);
        });
    }

    // Count the store and money figures up after a range change. The finished
    // server text (wc_price markup) is put back at the end.
    function playKpis(d) {
        if (!V || !BF) return;
        var p = d.profit || {};
        var figures = [
            ['card-total-sales', d.total_sales_raw, 'm'],
            ['card-orders', d.order_count, 'n'],
            ['card-aov', d.aov_raw, 'm'],
            ['card-visitors', d.visitor_count, 'n'],
            ['card-conversion', d.conversion_rate, 'p'],
            ['card-profit-revenue', p.revenue_raw, 'm'],
            ['card-profit-cogs', p.cogs_raw, 'm'],
            ['card-profit-expenses', p.expenses_raw, 'm'],
            ['card-profit-net', p.net_raw, 'm']
        ];
        var fmts = {
            m: function (v) { return money(v); },
            n: function (v) { return formatNumber(Math.round(v)); },
            p: function (v) { return fmtPct(v, 2); }
        };
        figures.forEach(function (f) {
            var el = document.getElementById(f[0]);
            var to = Number(f[1]);
            if (!el || !isFinite(to)) return;
            var card = el.closest('.brikpanel-dash-card') || el;
            var html = el.innerHTML;
            schedulePlay(card, function () {
                V.countUp(el, to, fmts[f[2]], { done: function () { el.innerHTML = html; } });
            });
        });
    }

    // ------------------------------------------------------------------ sales over time

    function salesMetricValue(x, m) {
        if (!x) return null;
        if (m === 'aov') return Number(x.o) ? (Number(x.r) || 0) / Number(x.o) : null;
        return Number(x[m]) || 0;
    }

    function renderSales(d, allowPlay) {
        dvCards('sales').forEach(function (card) { drawSalesCard(card, d, allowPlay !== false); });
    }

    function drawSalesCard(card, d, allowPlay) {
        var box = card.querySelector('.bp-dv-chart');
        var empty = dvSlot(card, 'empty');
        var ss = d && d.sales_series;
        var p = (d && d.period) || {};
        if (!box || !V) return;

        var curPts = (ss && Array.isArray(ss.cur)) ? ss.cur : [];
        var prevPts = (ss && Array.isArray(ss.prev)) ? ss.prev : [];
        var hourly = !!ss && ss.unit === 'hour';
        var isToday = hourly && ss.now_hour !== null && ss.now_hour !== undefined;
        var m = salesMetric;

        // Within a day the hours add up as they pass ("so far"): a quiet
        // morning reads as a slow start, not as a line jumping between 0 and 1.
        var series = function (arr) {
            if (!hourly) return arr.map(function (x) { return salesMetricValue(x, m); });
            var cr = 0;
            var co = 0;
            return arr.map(function (x) {
                if (!x) return null;
                cr += Number(x.r) || 0;
                co += Number(x.o) || 0;
                return m === 'r' ? cr : m === 'o' ? co : (co ? cr / co : null);
            });
        };
        var sum = function (arr, k) {
            var t = 0;
            arr.forEach(function (x) { if (x) t += Number(x[k]) || 0; });
            return t;
        };
        var cur = series(curPts);
        var prev = series(prevPts);
        var curR = sum(curPts, 'r');
        var curO = sum(curPts, 'o');
        var prevR = sum(prevPts, 'r');
        var prevO = sum(prevPts, 'o');
        var total = m === 'r' ? curR : m === 'o' ? curO : (curO ? curR / curO : 0);
        var prevTotal = m === 'r' ? prevR : m === 'o' ? prevO : (prevO ? prevR / prevO : 0);
        var fmt = function (v) { return m === 'o' ? formatNumber(Math.round(v)) : money(v); };

        var figure = dvSlot(card, 'total');
        if (figure) figure.textContent = fmt(total);
        var hasPrev = prevPts.length > 0;
        var change = hasPrev ? calcDelta(total, prevTotal) : null;
        dvDelta(dvSlot(card, 'delta'), change, false);
        // "vs previous 30 days" only beside a change it explains.
        setIsolated(dvSlot(card, 'cmp'), (change === null || change === 0) ? '' : hourly
            ? (isToday ? (i18n.sales_vs_yesterday || '') : (i18n.sales_vs_day_before || ''))
            : (BF ? BF.count(i18n.sales_vs_days, Number(p.days) || curPts.length) : ''));

        var curName = hourly
            ? (isToday ? (i18n.today || '') : (p.range === 'yesterday' ? (i18n.yesterday || '') : (BF ? BF.dayMonth(p.from_iso) : '')))
            : (p.label || '');
        var prevName = hourly
            ? (isToday ? (i18n.yesterday || '') : (prevPts[0] && BF ? BF.dayMonth(String(prevPts[0].t).slice(0, 10)) : ''))
            : (i18n.sales_prev_period || '');
        var curLabel = dvSlot(card, 'cur-label');
        var prevLabel = dvSlot(card, 'prev-label');
        if (curLabel) curLabel.textContent = curName;
        if (prevLabel) {
            prevLabel.textContent = prevName;
            prevLabel.parentNode.hidden = !hasPrev;
        }

        var hasSales = curPts.some(function (x) { return x && (Number(x.r) !== 0 || Number(x.o) !== 0); });
        // No lines, nothing for the key to name.
        var legend = card.querySelector('.bp-dv-legend');
        if (legend) legend.hidden = !hasSales;
        if (!hasSales) {
            box.hidden = true;
            box.innerHTML = '';
            if (empty) {
                fillEmpty(empty, emptyReason('orders', 'site'));
                empty.hidden = false;
            }
            V.tip.hide();
            return;
        }
        if (empty) empty.hidden = true;
        box.hidden = false;

        var todayIdx = (!hourly && ss.today !== null && ss.today !== undefined) ? Number(ss.today) : null;
        var metricName = hourly
            ? (m === 'r' ? i18n.sales_r_so_far : m === 'o' ? i18n.sales_o_so_far : i18n.sales_aov_so_far)
            : (m === 'r' ? i18n.revenue : m === 'o' ? i18n.orders : i18n.aov_label);
        var dayOf = function (pt) { return pt && BF ? BF.dateShort(String(pt.t).slice(0, 10)) : ''; };

        V.line(box, {
            cur: cur,
            prev: hasPrev ? prev : [],
            partialFrom: todayIdx !== null && todayIdx === cur.length - 1 ? todayIdx : null,
            hourly: hourly,
            step: m === 'o' ? 'int' : '',
            ariaLabel: (metricName || '') + ', ' + curName,
            live: dvSlot(card, 'live'),
            xLabel: function (i) {
                return hourly ? hourLabel(i) : (BF ? BF.dayMonth(String(curPts[i].t).slice(0, 10)) : String(curPts[i].t));
            },
            yLabel: function (v) {
                return m === 'o' ? formatNumber(v) : (BF && BF.compactMoney ? BF.compactMoney(v) : String(v));
            },
            tipAt: function (i) {
                var title = hourly
                    ? (BF ? BF.format(i18n.sales_until, [curName, hourLabel(i, '59')]) : '')
                    : (i === todayIdx ? fillText(i18n.sales_so_far, dayOf(curPts[i])) : dayOf(curPts[i]));
                var rows = [[metricName || '', cur[i] === null || cur[i] === undefined ? (i18n.sales_not_yet || '') : fmt(cur[i])]];
                if (hasPrev && prev[i] !== null && prev[i] !== undefined) {
                    rows.push([
                        hourly ? (BF ? BF.format(i18n.sales_until, [prevName, hourLabel(i, '59')]) : '') : fillText(i18n.sales_prev_of, dayOf(prevPts[i])),
                        fmt(prev[i])
                    ]);
                }
                if (m === 'r' && !hourly && curPts[i]) {
                    rows.push([i18n.orders || '', formatNumber(Number(curPts[i].o) || 0)]);
                }
                return { title: title, rows: rows };
            }
        });

        if (allowPlay) {
            schedulePlay(card, function () {
                var svg = box.querySelector('svg');
                if (!svg) return;
                var ln = svg.querySelector('.bp-dv-ch-line');
                if (ln && ln.getTotalLength) {
                    var len = ln.getTotalLength();
                    V.anim(ln, [{ strokeDasharray: len + ' ' + len, strokeDashoffset: len }, { strokeDasharray: len + ' ' + len, strokeDashoffset: 0 }], { duration: 1100, easing: 'cubic-bezier(.65, 0, .35, 1)' });
                }
                V.anim(svg.querySelector('.bp-dv-ch-area'), [{ opacity: 0 }, { opacity: 1 }], { duration: 700, delay: 500 });
                V.anim(svg.querySelector('.bp-dv-ch-prev'), [{ opacity: 0 }, { opacity: 1 }], { duration: 500, delay: 200 });
                var fig = dvSlot(card, 'total');
                if (fig) {
                    var text = fig.textContent;
                    V.countUp(fig, total, fmt, { done: function () { fig.textContent = text; } });
                }
            });
        }
    }

    // ------------------------------------------------------------------ funnel (B)

    function rateText(r) {
        if (r === null || r === undefined || !isFinite(r)) return '';
        return r > 100 ? fmtPct(100, 0) + '+' : fmtPct(r, 1);
    }

    function renderFunnel(d, allowPlay) {
        dvCards('funnel').forEach(function (card) { drawFunnelCard(card, d, allowPlay !== false); });
    }

    function drawFunnelCard(card, d, allowPlay) {
        var box = dvSlot(card, 'flow');
        var empty = dvSlot(card, 'empty');
        var conv = dvSlot(card, 'conv');
        if (!box || !V) return;
        var f = (d && d.funnel) || {};
        var vals = [f.visitors, f.products, f.cart, f.checkout, f.orders].map(function (v) { return Math.max(0, Number(v) || 0); });
        var labels = [i18n.visitors, i18n.product_views, i18n.add_to_cart, i18n.checkout, i18n.orders];

        if (conv) {
            conv.textContent = '';
            if (vals[0] > 0) {
                fillNodes(conv, i18n.funnel_converted || '%s', [bold(fmtPct(Math.min(100, vals[4] / vals[0] * 100), 2))]);
            }
        }

        if (vals.every(function (v) { return !v; })) {
            box.hidden = true;
            box.innerHTML = '';
            box.style.height = '';
            if (empty) {
                fillEmpty(empty, emptyReason('visits'));
                empty.hidden = false;
            }
            return;
        }
        if (empty) empty.hidden = true;
        box.hidden = false;

        var max = Math.max.apply(null, vals);
        var steps = vals.map(function (val, i) {
            var rate = i && vals[i - 1] ? val / vals[i - 1] * 100 : null;
            return {
                label: labels[i] || '',
                value: formatNumber(val),
                val: val,
                rel: max ? val / max : 0,
                rateRaw: rate,
                rate: rate === null ? '' : rateText(rate),
                share: vals[0] ? val / vals[0] * 100 : 0,
                left: i ? Math.max(0, vals[i - 1] - val) : 0
            };
        });

        V.flow(box, steps, {
            ariaFor: function (i) { return steps[i].label + ' ' + steps[i].value; },
            tipFor: function (i) {
                var st = steps[i];
                var rows = [[i18n.people || '', st.value]];
                if (i) {
                    rows.push([i18n.of_visitors || '', fmtPct(st.share, 1)]);
                    if (st.rate) rows.push([i18n.of_prev_step || '', st.rate]);
                    if (st.left > 0) rows.push([i18n.did_not_continue || '', formatNumber(st.left)]);
                }
                return { title: st.label, rows: rows, note: st.rateRaw !== null && st.rateRaw > 100 ? (i18n.funnel_more_orders || '') : '' };
            }
        });

        if (allowPlay) {
            schedulePlay(card, function () {
                var rib = box.querySelector('.bp-dv-flow-rib');
                var dur = 1250;
                if (rib) V.anim(rib, [{ clipPath: box.getAttribute('data-reveal') }, { clipPath: 'inset(0 0 0 0)' }], { duration: dur, delay: 120, easing: 'cubic-bezier(.65, 0, .35, 1)' });
                box.querySelectorAll('.bp-dv-flow-bead').forEach(function (b) {
                    V.anim(b, [{ opacity: 0, transform: 'translate(-50%, -50%) scale(.6)' }, { opacity: 1, transform: 'translate(-50%, -50%) scale(1)' }], { duration: 420, delay: 120 + dur * (Number(b.getAttribute('data-x')) || 0) * 0.95, easing: 'cubic-bezier(.34, 1.4, .64, 1)' });
                });
            });
        }
    }

    // ------------------------------------------------------------------ abandoned carts

    // "Abandoned carts 6 ↑20% · $36,799.80 · Recovered 1 (17%)": carts first
    // left in the period, their value, how many were bought back since. Shown
    // only while carts are collected; a payload cached before these keys
    // existed leaves the line hidden.
    function renderAbandonedCarts(data, on) {
        var line = document.getElementById('brikpanel-dash-cartab');
        if (!line) return;
        var show = !!on && !!data && typeof data === 'object';

        line.querySelectorAll('.brikpanel-dash-cartab-item, .brikpanel-dash-cartab-vals').forEach(function (node) { node.remove(); });
        line.hidden = !show;
        if (!show) return;

        var vals = document.createElement('span');
        vals.className = 'brikpanel-dash-cartab-vals';
        line.appendChild(vals);

        var count = Number(data.count) || 0;
        if (!count) {
            vals.appendChild(cartabItem(i18n.cartab_none || '', ''));
            return;
        }

        var countItem = cartabItem('', '');
        var strong = document.createElement('strong');
        strong.textContent = formatNumber(count);
        countItem.appendChild(strong);
        // No change shown without a previous period to compare with ("New"
        // would read as good news here) or when nothing moved.
        if (data.delta !== null && data.delta !== undefined && Number(data.delta) !== 0) {
            var delta = document.createElement('span');
            setDelta(delta, Number(data.delta), true);
            countItem.appendChild(delta);
        }
        vals.appendChild(countItem);

        if (data.value) {
            vals.appendChild(cartabItem(String(data.value), 'brikpanel-dash-cartab-sep'));
        }

        var rate = data.rate === null || data.rate === undefined ? '' : fmtPct(Number(data.rate), 0);
        var recovered = BF
            ? BF.format(i18n.cartab_recovered || '', [formatNumber(Number(data.recovered) || 0), rate])
            : String(data.recovered || 0);
        vals.appendChild(cartabItem(recovered, 'brikpanel-dash-cartab-sep'));
    }

    function cartabItem(text, extraClass) {
        var item = document.createElement('span');
        item.className = 'brikpanel-dash-cartab-item' + (extraClass ? ' ' + extraClass : '');
        if (text) {
            // <bdi>: a price or "Recovered 1 (17%)" keeps its own reading
            // order on a right-to-left screen.
            var bdi = document.createElement('bdi');
            bdi.textContent = text;
            item.appendChild(bdi);
        }
        return item;
    }

    // ------------------------------------------------------------------ order rates (D)

    var RATE_CATS = [
        ['successful', 'successful'],
        ['refunded', 'refunded'],
        ['cancelled', 'cancelled'],
        ['failed', 'failed']
    ];

    // Order counts per group; a payload cached before the counts existed has
    // only the shares, which are turned back into counts.
    function rateCounts(rates) {
        rates = rates || {};
        var counts = rates.counts && typeof rates.counts === 'object' ? rates.counts : null;
        var total = Number(rates.total) || 0;
        var out = {};
        RATE_CATS.forEach(function (c) {
            out[c[0]] = counts ? Math.max(0, Number(counts[c[0]]) || 0) : Math.round((Number(rates[c[0]]) || 0) * total / 100);
        });
        return out;
    }

    function renderRates(d, allowPlay) {
        dvCards('rates').forEach(function (card) { drawRatesCard(card, d, allowPlay !== false); });
    }

    function drawRatesCard(card, d, allowPlay) {
        var box = dvSlot(card, 'box');
        var empty = dvSlot(card, 'empty');
        var lead = dvSlot(card, 'lead');
        var ordersEl = dvSlot(card, 'orders');
        if (!box || !V) return;
        var counts = rateCounts(d && d.order_rates);
        var total = 0;
        RATE_CATS.forEach(function (c) { total += counts[c[0]]; });
        setIsolated(ordersEl, total && BF ? BF.count(i18n.camp_orders, total) : '');

        if (!total) {
            box.innerHTML = '';
            box.hidden = true;
            if (lead) lead.hidden = true;
            if (empty) {
                fillEmpty(empty, emptyReason('orders', 'site'));
                empty.hidden = false;
            }
            return;
        }
        if (empty) empty.hidden = true;
        if (lead) lead.hidden = false;
        box.hidden = false;

        var cats = RATE_CATS.map(function (c) {
            var n = counts[c[0]];
            return { key: c[0], cls: 'is-' + c[0], label: i18n[c[1]] || '', n: n, pct: Math.round(n / total * 1000) / 10 };
        });
        var w = box.clientWidth || 300;
        var wide = w >= 430;
        var size = wide ? Math.min(196, Math.round(w * 0.42)) : Math.min(w, 176);
        var aria = cats.map(function (c) { return c.label + ' ' + fmtPct(c.pct, 1); }).join(', ');

        box.classList.toggle('is-narrow', !wide);
        box.innerHTML = V.waffle(cats.map(function (c) { return { key: c.key, n: c.n, cls: c.cls }; }), size, aria, V.isRtl(box)) +
            '<ul class="bp-dv-rates-leg"></ul>';
        var list = box.querySelector('.bp-dv-rates-leg');
        cats.forEach(function (c) {
            var li = document.createElement('li');
            li.className = 'bp-dv-rates-item' + (c.n ? '' : ' is-zero');
            li.setAttribute('data-k', c.key);
            li.setAttribute('tabindex', '0');
            li.setAttribute('data-bp-tip', 'top');
            var sw = document.createElement('i');
            sw.className = 'bp-dv-sw ' + c.cls;
            sw.setAttribute('aria-hidden', 'true');
            var name = document.createElement('span');
            name.className = 'bp-dv-lname';
            name.appendChild(document.createTextNode(c.label));
            var small = document.createElement('small');
            setIsolated(small, BF ? BF.count(i18n.camp_orders, c.n) : String(c.n));
            name.appendChild(small);
            var pct = document.createElement('b');
            pct.className = 'bp-dv-lpct';
            pct.textContent = fmtPct(c.pct, 1);
            var bubble = document.createElement('span');
            bubble.className = 'brikpanel-tip bp-dv-tipbox';
            bubble.setAttribute('role', 'tooltip');
            V.tip.fill(bubble, { title: c.label, rows: [[i18n.orders || '', formatNumber(c.n)], [i18n.share || '', fmtPct(c.pct, 1)]] });
            li.appendChild(sw);
            li.appendChild(name);
            li.appendChild(pct);
            li.appendChild(bubble);
            list.appendChild(li);
        });
        wireRatesHighlight(card, box, cats);

        if (allowPlay) {
            schedulePlay(card, function () {
                box.querySelectorAll('.bp-dv-sq').forEach(function (s) {
                    s.style.transformBox = 'fill-box';
                    s.style.transformOrigin = '50% 50%';
                    V.anim(s, [{ opacity: 0, transform: 'scale(.4)' }, { opacity: 1, transform: 'scale(1)' }], { duration: 320, delay: 140 + (Number(s.getAttribute('data-n')) || 0) * 6, easing: V.EASE_OUT });
                });
                box.querySelectorAll('.bp-dv-rates-item').forEach(function (li, i) {
                    V.anim(li, [{ opacity: 0 }, { opacity: 1 }], { duration: 360, delay: 420 + i * 90 });
                });
            });
        }
    }

    // Pointing at a square or a legend row lights up its group; a square
    // also shows its group in the chart tooltip.
    function wireRatesHighlight(card, box, cats) {
        var setHl = function (key) {
            card.classList.toggle('is-hl', !!key);
            box.querySelectorAll('[data-k]').forEach(function (n) {
                var same = !!key && n.getAttribute('data-k') === key;
                n.classList.toggle('is-on', same);
                n.classList.toggle('is-dim', !!key && !same);
            });
        };
        var tipFor = function (key) {
            var c = cats.filter(function (x) { return x.key === key; })[0];
            return c ? { title: c.label, rows: [[i18n.orders || '', formatNumber(c.n)], [i18n.share || '', fmtPct(c.pct, 1)]] } : null;
        };
        box.onpointerover = function (e) {
            var sq = e.target.closest && e.target.closest('.bp-dv-sq');
            var li = e.target.closest && e.target.closest('.bp-dv-rates-item');
            if (sq) {
                setHl(sq.getAttribute('data-k'));
                var t = tipFor(sq.getAttribute('data-k'));
                if (t) V.tip.show(t, sq.getBoundingClientRect());
            } else if (li) {
                setHl(li.getAttribute('data-k'));
            }
        };
        box.onpointerleave = function () {
            setHl(null);
            V.tip.hide();
        };
        box.onfocusin = function (e) {
            var li = e.target.closest && e.target.closest('.bp-dv-rates-item');
            if (li) setHl(li.getAttribute('data-k'));
        };
        box.onfocusout = function () { setHl(null); };
        box.onpointerout = function (e) {
            var sq = e.target.closest && e.target.closest('.bp-dv-sq');
            if (sq && !(e.relatedTarget && e.relatedTarget.closest && e.relatedTarget.closest('.bp-dv-sq'))) V.tip.hide();
        };
    }

    // ------------------------------------------------------------------ products

    function productViews(d) {
        return {
            sold: { rows: d.top_products, name: 'name', val: 'qty', unit: i18n.unit_sold, reason: function () { return emptyReason('orders', 'site'); } },
            viewed: { rows: d.most_viewed, name: 'title', val: 'views', unit: i18n.unit_views, reason: function () { return emptyReason('visits'); } },
            cart: { rows: d.most_cart, name: 'name', val: 'count', unit: i18n.unit_adds, reason: function () { return emptyReason('visits'); } }
        };
    }

    function renderProducts(d, allowPlay) {
        dvCards('products').forEach(function (card) {
            var views = productViews(d || {});
            card.querySelectorAll('[data-bp-dv-view]').forEach(function (panel) {
                drawProductPanel(panel, views[panel.getAttribute('data-bp-dv-view')]);
            });
            updateProductsFoot(card);
            if (allowPlay !== false) {
                schedulePlay(card, function () { playProductBars(card); });
            }
        });
    }

    function drawProductPanel(panel, view) {
        if (!panel || !view) return;
        var rows = Array.isArray(view.rows) ? view.rows : [];
        if (!rows.length) {
            showEmpty(panel, view.reason());
            return;
        }
        var max = 1;
        rows.forEach(function (r) { max = Math.max(max, Number(r[view.val]) || 0); });
        var ul = document.createElement('ul');
        ul.className = 'bp-dv-plist';
        rows.forEach(function (r, i) {
            ul.appendChild(productRow(String(r[view.name] || ''), Number(r[view.val]) || 0, max, view.unit, r.url, i + 1, ''));
        });
        panel.textContent = '';
        panel.appendChild(ul);
    }

    // One row of a Products list (and of the Most viewed pages window): rank,
    // initials, name with its bar, the figure. A row with an address opens it
    // (initRowLinks()). rank '' leaves the rank column empty; type is a small
    // second word after the name (Product, Page, Category).
    function productRow(name, val, max, unit, url, rank, type) {
        var li = document.createElement('li');
        li.className = 'bp-dv-prow';
        if (url) {
            li.className += ' brikpanel-dash-row-link';
            li.setAttribute('data-href', url);
            li.setAttribute('tabindex', '0');
            li.setAttribute('role', 'link');
        }
        var nameHtml = '<span class="bp-dv-pname" dir="auto">' + escapeHtml(name) + '</span>';
        if (type) {
            nameHtml = '<span class="bp-dv-pline">' + nameHtml + '<span class="bp-dv-ptype" dir="auto">' + escapeHtml(type) + '</span></span>';
        }
        li.innerHTML =
            '<span class="bp-dv-rank">' + escapeHtml(rank === '' ? '' : formatNumber(rank)) + '</span>' +
            '<span class="bp-dv-ptile" aria-hidden="true">' + escapeHtml(monogram(name)) + '</span>' +
            '<span class="bp-dv-pmain">' + nameHtml +
            '<span class="bp-dv-pbar"><i style="width:' + Math.min(100, val / Math.max(1, max) * 100).toFixed(1) + '%"></i></span></span>' +
            '<span class="bp-dv-pval"><b>' + escapeHtml(formatNumber(val)) + '</b><span>' + escapeHtml(BF ? BF.plural(unit, val) : '') + '</span></span>';
        return li;
    }

    // The three lists of the Products and pages card, as the payload and the
    // window name them: its rows, the period's count, the "View all" text.
    var PRODUCT_LISTS = {
        sold: { rows: 'top_products', total: 'top_products_total', viewAll: 'products_view_all', count: 'products_count', title: 'list_title_sold', search: 'search_products', unit: 'unit_sold' },
        viewed: { rows: 'most_viewed', total: 'most_viewed_total', viewAll: 'pages_view_all', count: 'pages_count', title: 'list_title_viewed', search: 'search_pages', unit: 'unit_views' },
        cart: { rows: 'most_cart', total: 'most_cart_total', viewAll: 'products_view_all', count: 'products_count', title: 'list_title_cart', search: 'search_products', unit: 'unit_adds' }
    };

    // The card's foot follows its open tab: "View all N products" (or pages)
    // once the period has more than the card lists; it opens the window with
    // that tab's whole list (initPagesWindow()).
    function updateProductsFoot(card) {
        var viewAllBtn = card.querySelector('.bp-dv-pages-open');
        if (!viewAllBtn) return;
        var on = card.querySelector('[role="tab"][aria-selected="true"]');
        var view = on ? on.getAttribute('data-bp-dv-tab') : String(card.getAttribute('data-views') || '').split(' ')[0];
        var list = PRODUCT_LISTS[view];
        if (!list) {
            viewAllBtn.hidden = true;
            return;
        }
        var total = lastData ? Number(lastData[list.total]) || 0 : 0;
        var shown = lastData && Array.isArray(lastData[list.rows]) ? lastData[list.rows].length : 0;
        var label = viewAllBtn.querySelector('[data-bp-dv-slot="pages-label"]');
        if (label) label.textContent = BF ? BF.count(i18n[list.viewAll], total) : String(total);
        viewAllBtn.setAttribute('data-bp-dv-list', view);
        viewAllBtn.hidden = !document.getElementById('bp-dv-pages') || total <= shown;
    }

    // ------------------------------------------------------------------ list window

    // The whole list of the Products and pages card's open tab (Best sellers,
    // Most viewed pages or Most added to cart) for the period, opened from the
    // card's "View all" button (Brikpanel_Dashboard::render_pages_window()).
    // The browser's own dialog keeps it above the top bar, keeps the keyboard
    // in it and closes it on Esc; rows open their page like the card's rows
    // (initRowLinks()). It asks for the dates on screen, so it matches the
    // card even after midnight, and loads again once those dates change.
    function initPagesWindow() {
        var dlg = document.getElementById('bp-dv-pages');
        if (!dlg || typeof dlg.showModal !== 'function') return;
        var list = dvSlot(dlg, 'pages-list');
        var note = dvSlot(dlg, 'pages-note');
        var moreBtn = dvSlot(dlg, 'pages-more');
        var input = dvSlot(dlg, 'pages-q');
        var sub = dvSlot(dlg, 'pages-sub');
        var title = dlg.querySelector('.bp-dv-pages-title');
        var closeBtn = dlg.querySelector('[data-bp-dv-pages-close]');
        if (!list || !note || !moreBtn || !input || !sub) return;
        var st = { list: 'viewed', opener: null, period: null, search: '', next: 0, total: null, top: 0, count: 0, keys: {}, seq: 0, ctl: null };
        var timer = 0;
        var downOnBackdrop = false;

        function setNote(text) {
            note.textContent = text || '';
            note.hidden = !text;
        }

        function setSub() {
            var label = st.period && st.period.label ? String(st.period.label) : '';
            var count = st.total !== null && BF ? BF.count(i18n[PRODUCT_LISTS[st.list].count], st.total) : '';
            sub.textContent = label && count && BF ? BF.format(i18n.pages_sub, [label, count]) : (label || count);
        }

        function load(reset) {
            if (st.ctl) st.ctl.abort();
            var ctl = typeof AbortController !== 'undefined' ? new AbortController() : null;
            st.ctl = ctl;
            var seq = ++st.seq;
            if (reset) {
                st.next = 0;
                st.count = 0;
                st.keys = {};
                st.total = null;
                list.textContent = '';
                moreBtn.hidden = true;
                setNote(i18n.pages_loading);
                setSub();
            }
            moreBtn.disabled = true;
            var p = st.period || {};
            var fd = new FormData();
            fd.append('action', 'brikpanel_dash_list');
            fd.append('security', CFG.nonce);
            fd.append('list', st.list);
            if (p.from_iso && p.to_iso) {
                fd.append('range', 'custom');
                fd.append('start_date', p.from_iso);
                fd.append('end_date', p.to_iso);
            } else {
                fd.append('range', currentRange);
                if (currentRange === 'custom') {
                    fd.append('start_date', customStartDate);
                    fd.append('end_date', customEndDate);
                }
            }
            fd.append('search', st.search);
            fd.append('offset', String(st.next));
            fetch(CFG.ajax_url, { method: 'POST', body: fd, credentials: 'same-origin', signal: ctl ? ctl.signal : undefined })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (seq !== st.seq) return;
                    st.ctl = null;
                    moreBtn.disabled = false;
                    if (!res || !res.success || !res.data) {
                        setNote(i18n.pages_error);
                        return;
                    }
                    var data = res.data;
                    var rows = Array.isArray(data.rows) ? data.rows : [];
                    if (data.total !== null && data.total !== undefined) st.total = Number(data.total) || 0;
                    // The bars measure against the list's first row of the period.
                    if (!st.top && !st.search && rows.length) st.top = Number(rows[0].value) || 0;
                    var unit = i18n[PRODUCT_LISTS[st.list].unit];
                    var frag = document.createDocumentFragment();
                    rows.forEach(function (r) {
                        var key = String(r.key || '');
                        if (!key || st.keys[key]) return;
                        st.keys[key] = true;
                        st.count++;
                        var value = Number(r.value) || 0;
                        frag.appendChild(productRow(String(r.title || ''), value, st.top || value, unit, r.url, st.search ? '' : st.count, String(r.type || '')));
                    });
                    list.appendChild(frag);
                    st.next = Number(data.next) || st.next + rows.length;
                    moreBtn.hidden = !data.has_more;
                    setNote(st.count ? '' : (st.search ? i18n.pages_no_match : (st.list === 'sold' ? emptyReason('orders', 'site') : emptyReason('visits')).text));
                    setSub();
                })
                .catch(function (err) {
                    if (err && err.name === 'AbortError') return;
                    if (seq !== st.seq) return;
                    st.ctl = null;
                    moreBtn.disabled = false;
                    setNote(i18n.pages_error);
                });
        }

        // Ctrl/Cmd+K would open the search palette behind the window (the
        // palette listens in the capture phase, brikpanel-search.js).
        function swallowSearch(e) {
            if (dlg.open && (e.ctrlKey || e.metaKey) && !e.altKey && (e.key === 'k' || e.key === 'K')) {
                e.preventDefault();
                e.stopImmediatePropagation();
            }
        }

        function open(opener) {
            var p = lastData && lastData.period ? lastData.period : null;
            var kind = opener.getAttribute('data-bp-dv-list');
            kind = PRODUCT_LISTS[kind] ? kind : 'viewed';
            var same = kind === st.list && st.period && p && st.period.from_iso === p.from_iso && st.period.to_iso === p.to_iso;
            st.opener = opener;
            st.period = p;
            st.list = kind;
            if (title) title.textContent = i18n[PRODUCT_LISTS[kind].title] || '';
            input.placeholder = i18n[PRODUCT_LISTS[kind].search] || '';
            input.setAttribute('aria-label', input.placeholder);
            if (!same || !st.count) {
                st.search = '';
                st.top = 0;
                input.value = '';
                load(true);
            }
            document.documentElement.classList.add('bp-dv-pages-lock');
            window.addEventListener('keydown', swallowSearch, true);
            try {
                dlg.showModal();
            } catch (err) {
                dlg.setAttribute('open', '');
            }
            // The search box takes the keyboard; on a phone the keyboard would
            // cover the list, so the window opens on its close button there.
            if (!(window.matchMedia && window.matchMedia('(pointer: coarse)').matches)) input.focus();
        }

        function close() {
            if (dlg.open) dlg.close();
        }

        dlg.addEventListener('close', function () {
            document.documentElement.classList.remove('bp-dv-pages-lock');
            window.removeEventListener('keydown', swallowSearch, true);
            if (st.opener && document.contains(st.opener)) st.opener.focus();
        });
        // A click on the dialog itself is a click on the backdrop. It has to
        // start there too, so a text selection that ends outside keeps it open.
        dlg.addEventListener('pointerdown', function (e) { downOnBackdrop = e.target === dlg; });
        dlg.addEventListener('click', function (e) {
            if (e.target === dlg && downOnBackdrop) close();
            downOnBackdrop = false;
        });
        // Esc closes the window at once, also from a search box with text in
        // it (where the browser would first only clear the box).
        dlg.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && !e.defaultPrevented) {
                e.preventDefault();
                close();
            }
        });
        if (closeBtn) closeBtn.addEventListener('click', close);
        moreBtn.addEventListener('click', function () { load(false); });
        var runSearch = function () {
            clearTimeout(timer);
            var v = input.value.trim();
            if (v === st.search) return;
            st.search = v;
            load(true);
        };
        input.addEventListener('input', function () {
            clearTimeout(timer);
            timer = setTimeout(runSearch, 300);
        });
        input.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                runSearch();
            }
        });
        document.addEventListener('click', function (e) {
            var b = e.target.closest && e.target.closest('.bp-dv-pages-open');
            if (b) {
                e.preventDefault();
                open(b);
            }
        });
    }

    function playProductBars(card) {
        if (!V) return;
        var panel = Array.prototype.filter.call(card.querySelectorAll('[data-bp-dv-view]'), function (p) { return !p.hidden; })[0];
        if (!panel) return;
        panel.querySelectorAll('.bp-dv-pbar i').forEach(function (b, i) {
            V.anim(b, [{ transform: 'scaleX(0)' }, { transform: 'scaleX(1)' }], { duration: 600, delay: 60 + i * 60, easing: V.EASE_OUT });
        });
    }

    // ------------------------------------------------------------------ recent orders

    // WooCommerce's name for an order status (i18n.status_labels, keyed like
    // $order->get_status()), or '' when it has none.
    function statusLabelFor(slug) {
        var labels = i18n.status_labels;
        if (!labels || typeof labels !== 'object' || !Object.prototype.hasOwnProperty.call(labels, slug)) return '';
        return typeof labels[slug] === 'string' ? labels[slug] : '';
    }

    function orderWhen(o) {
        var ts = Number(o.ts) || 0;
        if (!ts || !BF) return o.date || '';
        var when = BF.parts(ts);
        var now = BF.now();
        return when && now && when.y === now.y ? BF.date(ts, dayMonthFmt() + ' ' + timeFmt()) : BF.dateShort(ts);
    }

    function renderOrders(orders) {
        dvCards('orders').forEach(function (card) {
            var list = dvSlot(card, 'list');
            if (!list) return;
            if (!Array.isArray(orders) || !orders.length) {
                // All-time list: only the new-store reason applies; a store
                // that has orders keeps its own sentence.
                var st = emptyCtx.store;
                showEmpty(list, (st && !st.has_any_order)
                    ? emptyReason('orders', 'all')
                    : { text: i18n.no_orders || '', note: '', preset: '' });
                return;
            }
            var ul = document.createElement('ul');
            ul.className = 'bp-dv-olist';
            orders.forEach(function (o) {
                var li = document.createElement('li');
                li.className = 'bp-dv-orow';
                if (o.edit_url) {
                    li.className += ' brikpanel-dash-row-link';
                    li.setAttribute('data-href', o.edit_url);
                    li.setAttribute('tabindex', '0');
                    li.setAttribute('role', 'link');
                }
                var status = String(o.status || '');
                var statusLabel = statusLabelFor(status) || status;
                var source = o.source && o.source.label
                    ? '<span class="bp-dv-osrc" dir="auto">' + escapeHtml(o.source.label) + '</span>'
                    : '';
                // o.total and o.total_base are wc_price() markup from the server.
                li.innerHTML =
                    '<span class="bp-dv-ono"><b><bdi>#' + escapeHtml(String(o.number || o.id)) + '</bdi></b><span>' + escapeHtml(orderWhen(o)) + '</span></span>' +
                    '<span class="bp-dv-ocust"><span class="bp-dv-oname" dir="auto">' + escapeHtml(o.customer || '') + '</span>' + source + '</span>' +
                    '<span class="bp-dv-pill is-' + escapeAttr(status.replace(/[^a-z0-9_-]/gi, '')) + '">' + escapeHtml(statusLabel) + '</span>' +
                    '<span class="bp-dv-otot"><b>' + (o.total || '') + '</b>' +
                    (o.total_base ? '<span class="bp-dv-obase">≈ ' + o.total_base + '</span>' : '') +
                    (o.items_label ? '<span><bdi>' + escapeHtml(o.items_label) + '</bdi></span>' : '') + '</span>';
                ul.appendChild(li);
            });
            list.textContent = '';
            list.appendChild(ul);
        });
    }

    // ------------------------------------------------------------------ visitors

    // Channel labels for the Sources tab.
    function sourceChannelLabel(channel) {
        var map = {
            direct: i18n.src_direct,
            search: i18n.src_search,
            social: i18n.src_social,
            referral: i18n.src_referral,
            paid: i18n.src_paid,
            email: i18n.src_email
        };
        return map[channel] || channel;
    }

    // Colour follows the device, never its rank: mobile, desktop, tablet.
    var DEVICE_KEYS = [['mobile', 'device_mobile', 'is-k0'], ['desktop', 'device_desktop', 'is-k1'], ['tablet', 'device_tablet', 'is-k2']];

    function legendHtml(groups) {
        var t = 0;
        groups.forEach(function (g) { t += g.n; });
        return groups.map(function (g) {
            return '<span class="bp-dv-dleg-item"><i class="bp-dv-dot-sw ' + escapeAttr(g.cls) + '" aria-hidden="true"></i><b>' + escapeHtml(g.name) + '</b><bdi>' + escapeHtml(fmtPct(t ? g.n / t * 100 : 0, 0)) + '</bdi><em><bdi>' + escapeHtml(formatNumber(g.n)) + '</bdi></em></span>';
        }).join('');
    }

    // The strip sits beside its legend on a wide card, above it on a narrow one.
    function stripRow(slot, groups, reason) {
        if (!slot) return;
        var total = 0;
        groups.forEach(function (g) { total += g.n; });
        if (!total) {
            showEmpty(slot, reason);
            return;
        }
        var bw = slot.clientWidth || 0;
        if (!bw) {
            slot.setAttribute('data-bp-dv-pending', '1');
            return;
        }
        slot.removeAttribute('data-bp-dv-pending');
        var side = bw >= 600;
        var w = side ? Math.min(500, bw - 230) : Math.min(560, bw);
        slot.innerHTML = '<div class="bp-dv-blk-row' + (side ? ' is-side' : '') + '"><div class="bp-dv-strip">' + V.dots(groups, w, V.isRtl(slot)) + '</div><div class="bp-dv-dleg">' + legendHtml(groups) + '</div></div>';
    }

    function playDots(root) {
        if (!V || !root) return;
        root.querySelectorAll('.bp-dv-dot').forEach(function (c) {
            V.anim(c, [{ opacity: 0.12 }, { opacity: 1 }], { duration: 240, delay: 80 + (Number(c.getAttribute('data-n')) || 0) * 6, easing: 'linear' });
        });
    }

    function renderVisitors(d, allowPlay) {
        dvCards('visitors').forEach(function (card) {
            card.querySelectorAll('[data-bp-dv-view]').forEach(function (panel) {
                if (!panel.hidden) drawVisitorsPanel(panel, d);
                else panel.setAttribute('data-bp-dv-stale', '1');
            });
            if (allowPlay !== false) {
                schedulePlay(card, function () { playVisitorsPanel(card); });
            }
        });
    }

    function drawVisitorsPanel(panel, d) {
        if (!panel || !d || !V) return;
        panel.removeAttribute('data-bp-dv-stale');
        var view = panel.getAttribute('data-bp-dv-view');
        if (view === 'devices') {
            var groups = function (data) {
                return DEVICE_KEYS.map(function (k) {
                    return { key: k[0], name: i18n[k[1]] || k[0], n: Math.max(0, Number(data && data[k[0]]) || 0), cls: k[2] };
                });
            };
            stripRow(dvSlot(panel, 'dev-visitors'), groups(d.devices), emptyReason('visits'));
            stripRow(dvSlot(panel, 'dev-orders'), groups(d.order_devices), emptyReason('orders', 'site'));
        } else if (view === 'sources') {
            drawSources(panel, d.sources, d.top_referrers);
            // Shown as a third column on a wide card only (fitVisitors()).
            drawCampaigns(dvSlot(panel, 'campaigns-col'), d.top_campaigns);
        } else if (view === 'campaigns') {
            drawCampaigns(dvSlot(panel, 'campaigns'), d.top_campaigns);
        }
    }

    function drawSources(panel, data, referrers) {
        var chEl = dvSlot(panel, 'channels');
        var refWrap = dvSlot(panel, 'referrers-wrap');
        var refEl = dvSlot(panel, 'referrers');
        var order = ['direct', 'search', 'social', 'referral', 'paid', 'email'];
        var total = 0;
        order.forEach(function (k) { total += Math.max(0, Number(data && data[k]) || 0); });
        if (!total) {
            // Visits counted but none with a known source keeps its own sentence.
            showEmpty(chEl, emptyReason('visits', null, i18n.src_empty));
            var t = emptyCtx.tracking;
            if (refWrap) refWrap.hidden = !!t && (!t.enabled || emptyCtx.visitors <= 0);
        } else {
            var rows = order.map(function (k) { return { key: k, n: Math.max(0, Number(data[k]) || 0) }; })
                .filter(function (r) { return r.n > 0; })
                .sort(function (a, b) { return b.n - a.n; });
            var max = rows[0].n;
            chEl.innerHTML = '<ul class="bp-dv-srcs">' + rows.map(function (r) {
                // The name is cut with "…" on a narrow column; the title keeps it whole.
                var label = sourceChannelLabel(r.key);
                return '<li><span class="bp-dv-src-name" title="' + escapeAttr(label) + '">' + escapeHtml(label) + '</span>' +
                    '<span class="bp-dv-sbar"><i style="width:' + (r.n / max * 100).toFixed(1) + '%"></i></span>' +
                    '<span class="bp-dv-spct"><bdi>' + escapeHtml(fmtPct(r.n / total * 100, 0)) + '</bdi></span></li>';
            }).join('') + '</ul>';
            if (refWrap) refWrap.hidden = false;
        }
        var grid = panel.querySelector('.bp-dv-src2');
        if (grid && refWrap) grid.classList.toggle('is-noref', !!refWrap.hidden);
        if (!refEl) return;
        var list = Array.isArray(referrers) ? referrers.slice(0, 5) : [];
        if (!list.length) {
            showEmpty(refEl, { text: i18n.src_no_referrers || '', note: '', preset: '' });
            return;
        }
        // A site can send visits through two channels (google.com from ads and from
        // search). Those rows name their channel, so the list never shows a site twice
        // with nothing to tell the rows apart; a site listed once stays as it is.
        var seen = {};
        list.forEach(function (r) {
            var h = String(r.host || '').toLowerCase();
            seen[h] = (seen[h] || 0) + 1;
        });
        refEl.innerHTML = '<ul class="bp-dv-refs">' + list.map(function (r) {
            var host = String(r.host || '');
            var ch = seen[host.toLowerCase()] > 1 ? '<em class="bp-dv-ref-ch">' + escapeHtml(sourceChannelLabel(r.channel)) + '</em>' : '';
            return '<li><span><bdi>' + escapeHtml(host) + '</bdi>' + ch + '</span><b><bdi>' + escapeHtml(formatNumber(r.hits || 0)) + '</bdi></b></li>';
        }).join('') + '</ul>';
    }

    function drawCampaigns(slot, list) {
        if (!slot) return;
        list = Array.isArray(list) ? list : [];
        if (!list.length) {
            showEmpty(slot, { text: i18n.camp_none || '', note: '', preset: '' });
            return;
        }
        slot.innerHTML = '<ul class="bp-dv-camps">' + list.map(function (c) {
            var orders = BF ? BF.count(i18n.camp_orders, Number(c.orders) || 0) : String(Number(c.orders) || 0);
            var meta = orders;
            if (c.conversion !== null && c.conversion !== undefined && BF) {
                meta = BF.format(i18n.camp_meta || '', [orders, fmtPct(Number(c.conversion), 1)]);
            }
            return '<li><span class="bp-dv-camp" dir="auto" title="' + escapeAttr(c.name || '') + '">' + escapeHtml(c.name || '') + '</span>' +
                '<span class="bp-dv-cmeta"><bdi>' + escapeHtml(meta) + '</bdi></span>' +
                '<span class="bp-dv-crev"><bdi>' + escapeHtml(c.revenue_text || '—') + '</bdi></span></li>';
        }).join('') + '</ul>';
    }

    function playVisitorsPanel(card) {
        if (!V) return;
        var panel = Array.prototype.filter.call(card.querySelectorAll('[data-bp-dv-view]'), function (p) { return !p.hidden; })[0];
        if (!panel) return;
        playDots(panel);
        panel.querySelectorAll('.bp-dv-sbar i').forEach(function (b, i) {
            V.anim(b, [{ transform: 'scaleX(0)' }, { transform: 'scaleX(1)' }], { duration: 600, delay: 100 + i * 60, easing: V.EASE_OUT });
        });
        panel.querySelectorAll('.bp-dv-camps li, .bp-dv-refs li').forEach(function (li, i) {
            V.anim(li, [{ opacity: 0 }, { opacity: 1 }], { duration: 300, delay: 60 + i * 50 });
        });
    }

    // ------------------------------------------------------------------ customers

    var SEG_GROUPS = [['loyal', 'seg_loyal', 'is-loyal'], ['attention', 'seg_attention', 'is-attention'], ['risk', 'seg_risk', 'is-risk']];

    function renderCustomers(d, allowPlay) {
        dvCards('customers').forEach(function (card) {
            drawCustomersCard(card, d);
            if (allowPlay !== false) {
                schedulePlay(card, function () { playDots(card); });
            }
        });
    }

    function drawCustomersCard(card, d) {
        if (!card || !d || !V) return;
        var typesSlot = dvSlot(card, 'types');
        if (typesSlot) {
            var ct = d.customer_types || {};
            var groups = [
                { key: 'new', name: i18n.ctype_new || '', n: Math.max(0, Number(ct['new']) || 0), cls: 'is-k0' },
                { key: 'repeat', name: i18n.ctype_repeat || '', n: Math.max(0, Number(ct['repeat']) || 0), cls: 'is-k3' }
            ];
            var total = groups[0].n + groups[1].n;
            var meta = dvSlot(card, 'types-meta');
            setIsolated(meta, total && BF ? BF.count(i18n.types_meta, total) : '');
            // Read from WooCommerce's analytics table, which can lag behind
            // new orders: emptyReason() keeps the plain sentence while the
            // window does have paid orders.
            stripRow(typesSlot, groups, emptyReason('orders', 'all'));
        }

        var segSlot = dvSlot(card, 'segments');
        if (segSlot) {
            var segs = Array.isArray(d.rfm_distribution) ? d.rfm_distribution : [];
            var totalSeg = 0;
            segs.forEach(function (s) { totalSeg += Math.max(0, Number(s.customers) || 0); });
            var segTotal = dvSlot(card, 'seg-total');
            setIsolated(segTotal, totalSeg && BF ? BF.count(i18n.seg_total, totalSeg) : '');
            // "Out of every 100 customers" only above a strip that is there.
            var segMeta = card.querySelector('[data-block="segments"] .bp-dv-blk-meta');
            if (segMeta) segMeta.hidden = !totalSeg;
            if (!totalSeg) {
                // The card covers all time: the lifetime value card's sentence.
                var st = emptyCtx.store;
                showEmpty(segSlot, {
                    text: (st && !st.has_any_order ? i18n.empty_rfm_new : i18n.ltv_empty) || i18n.ltv_empty || '',
                    note: '',
                    preset: ''
                });
                return;
            }
            var sg = SEG_GROUPS.map(function (g) {
                var members = segs.filter(function (s) { return (s.group || 'attention') === g[0]; });
                var n = 0;
                members.forEach(function (s) { n += Math.max(0, Number(s.customers) || 0); });
                return { key: g[0], name: i18n[g[1]] || g[0], n: n, cls: g[2], members: members };
            });
            stripRow(segSlot, sg, { text: '', note: '', preset: '' });
            var detail = document.createElement('div');
            detail.className = 'bp-dv-seg-detail';
            sg.forEach(function (g) {
                var p = document.createElement('p');
                g.members.forEach(function (s, i) {
                    if (i) p.appendChild(document.createTextNode(' · '));
                    // A name and its count break to the next line together.
                    var item = document.createElement('span');
                    item.className = 'bp-dv-seg-item';
                    var name = document.createElement('span');
                    name.setAttribute('dir', 'auto');
                    name.textContent = s.label || s.key || '';
                    item.appendChild(name);
                    item.appendChild(document.createTextNode(' '));
                    item.appendChild(bold(formatNumber(Number(s.customers) || 0)));
                    p.appendChild(item);
                });
                detail.appendChild(p);
            });
            segSlot.appendChild(detail);
        }
    }

    // ------------------------------------------------------------------ low stock + LTV

    // Tables turn into stacked cards when they cannot show every column in
    // their box (field test B6: on a phone the right-hand totals scrolled out
    // of sight). Each box is watched once; its table is re-rendered in place,
    // so the shared helper looks it up again on every measurement.
    // floor: stack below this room even when the table would squeeze in.
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

        // A variation reads as its product's name with the options on a
        // quieter line under it; the stock as the Products page badge.
        var html = '<table class="brikpanel-dash-table bp-dv-stock-table"><thead><tr>' +
            '<th>' + escapeHtml(i18n.product || '') + '</th>' +
            '<th>' + escapeHtml(i18n.sku || '') + '</th>' +
            '<th>' + escapeHtml(i18n.stock || '') + '</th>' +
            '</tr></thead><tbody>';

        products.forEach(function (p) {
            var title = p.title || p.name || '';
            var label = '<span dir="auto">' + escapeHtml(title) + '</span>' + (p.variant ? '<small dir="auto">' + escapeHtml(p.variant) + '</small>' : '');
            var nameCell = p.edit_url
                ? '<a class="bp-dv-stock-name" href="' + escapeAttr(p.edit_url) + '">' + label + '</a>'
                : '<span class="bp-dv-stock-name">' + label + '</span>';
            html += '<tr>' +
                '<td class="brikpanel-fit-lead">' + nameCell + '</td>' +
                '<td class="brikpanel-dash-muted bp-dv-stock-sku">' + (p.sku ? skuHtml(p.sku) : '&mdash;') + '</td>' +
                '<td class="brikpanel-fit-headline"><span class="bp-dv-stock-badge">' + escapeHtml(formatNumber(p.stock)) + '</span></td>' +
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

        // Amounts are wc_price() markup from the server.
        var row = function (label, valueHtml) {
            return '<div class="bp-dv-kv-row"><span>' + escapeHtml(label || '') + '</span><b>' + valueHtml + '</b></div>';
        };
        wrap.innerHTML =
            '<div class="bp-dv-ltv-top"><b>' + data.avg_ltv + '</b><span>' + escapeHtml(i18n.average_ltv || '') + '</span></div>' +
            '<div class="bp-dv-kv">' +
                row(i18n.total_customers, escapeHtml(formatNumber(data.total_customers))) +
                row(i18n.repeat_customers, '<bdi>' + escapeHtml(formatNumber(data.repeat_customers) + ' (' + fmtPct(data.repeat_rate) + ')') + '</bdi>') +
                row(i18n.total_lifetime_value, data.total_ltv) +
                row(i18n.top_customer_ltv, data.max_ltv) +
            '</div>';
    }

    // ------------------------------------------------------------------ tabs + resizing

    function initDvTabs() {
        // Each card opens on the tab this user opened last time (printed by
        // the server); the sales chart starts on that metric.
        var salesOn = document.querySelector('[data-bp-dv="sales"] [role="tab"][aria-selected="true"]');
        if (salesOn) {
            var sk = salesOn.getAttribute('data-bp-dv-tab');
            salesMetric = sk === 'o' || sk === 'aov' ? sk : 'r';
        }
        if (!V) return;
        dvCards('sales').forEach(function (card) {
            V.tabs(card, function (key, byUser) {
                salesMetric = key === 'o' || key === 'aov' ? key : 'r';
                // Every sales card shows the same metric.
                dvCards('sales').forEach(function (other) {
                    other.querySelectorAll('[role="tab"]').forEach(function (b) {
                        var on = b.getAttribute('data-bp-dv-tab') === salesMetric;
                        b.setAttribute('aria-selected', on ? 'true' : 'false');
                        b.tabIndex = on ? 0 : -1;
                    });
                });
                if (byUser) saveTab('sales', salesMetric);
                if (!lastData) return;
                var was = playNext;
                playNext = true;
                renderSales(lastData);
                playNext = was;
            });
        });
        dvCards('products').forEach(function (card) {
            V.tabs(card, function (key, byUser) {
                playProductBars(card);
                updateProductsFoot(card);
                if (byUser) saveTab('products', key);
            });
        });
        dvCards('visitors').forEach(function (card) {
            V.tabs(card, function (key, byUser) {
                var panel = Array.prototype.filter.call(card.querySelectorAll('[data-bp-dv-view]'), function (p) { return !p.hidden; })[0];
                if (panel && lastData) drawVisitorsPanel(panel, lastData);
                playVisitorsPanel(card);
                if (byUser) saveTab('visitors', key);
            });
        });
    }

    // The tab a user opens is remembered for them (Brikpanel_Dashboard::
    // save_tab_preferences(), per user and per site), so the card opens on it
    // next time. Sent a moment after the click, one request at a time; a
    // choice still waiting when the page closes goes out with keepalive.
    var tabsPending = {};
    var tabsTimer = 0;
    var tabsBusy = false;

    function saveTab(card, key) {
        tabsPending[card] = key;
        clearTimeout(tabsTimer);
        tabsTimer = setTimeout(function () { flushTabs(false); }, 400);
    }

    function flushTabs(leaving) {
        clearTimeout(tabsTimer);
        var cards = Object.keys(tabsPending);
        if (!cards.length || (tabsBusy && !leaving)) return;
        var fd = new FormData();
        fd.append('action', 'brikpanel_dash_save_tab');
        fd.append('security', CFG.nonce);
        cards.forEach(function (c) { fd.append('tabs[' + c + ']', tabsPending[c]); });
        tabsPending = {};
        tabsBusy = true;
        var done = function () {
            tabsBusy = false;
            if (Object.keys(tabsPending).length) flushTabs(false);
        };
        // The answer is not read: a refused save (a user BrikPanel's access
        // rules keep on the classic dashboard) simply leaves the old tab.
        fetch(CFG.ajax_url, { method: 'POST', body: fd, credentials: 'same-origin', keepalive: !!leaving }).then(done, done);
    }

    window.addEventListener('pagehide', function () { flushTabs(true); });

    // Breakpoints by the space the dashboard and each card really have (the
    // admin menu takes 160 to 220px of the window). Classes written by
    // measurement, not container queries: in Chromium a size container holds
    // position:fixed boxes, so help bubbles were cut by their card. The page
    // prints the same root classes before its first paint (render_page()).
    var ROOT_STEPS = [[1300, 'bp-dv-lt1300'], [1000, 'bp-dv-lt1000'], [920, 'bp-dv-lt920'], [800, 'bp-dv-lt800'], [560, 'bp-dv-lt560']];
    var CARD_STEPS = [[560, 'is-w560'], [470, 'is-w470']];

    function contentWidth(el) {
        var cs = window.getComputedStyle(el);
        return el.clientWidth - (parseFloat(cs.paddingLeft) || 0) - (parseFloat(cs.paddingRight) || 0);
    }

    function applySteps(el, steps) {
        var w = contentWidth(el);
        steps.forEach(function (st) { el.classList.toggle(st[1], w <= st[0]); });
    }

    // A Visitors card this wide (the same 740px the page decides with before
    // its first paint, render_box_visitors()) shows the campaigns as a third
    // column of Sources and drops the Top campaigns tab; a narrower card keeps
    // the tab, so the card is never taller than before. A card open on Top
    // campaigns then shows Sources, without saving that as the user's choice.
    var SRC3_MIN = 740;

    function fitVisitors(card) {
        var wide = contentWidth(card) >= SRC3_MIN;
        if (card.classList.contains('is-src3') === wide) return;
        card.classList.toggle('is-src3', wide);
        var on = card.querySelector('[role="tab"][aria-selected="true"]');
        if (wide && V && on && on.getAttribute('data-bp-dv-tab') === 'campaigns') {
            var api = V.tabs(card);
            if (api) api.select('sources');
        }
    }

    // A store card too narrow for its small line hides it.
    function sizeKpis() {
        ['brikpanel-profit-cards', 'brikpanel-kpi-cards'].forEach(function (id) {
            var grid = document.getElementById(id);
            if (grid) fitKpiFigures(grid);
        });
        document.querySelectorAll('.bp-dv-kpi').forEach(function (card) {
            card.classList.toggle('is-narrow', contentWidth(card) < 205);
        });
    }

    // A long figure (a large sum in a currency with many digits) takes one
    // size down when that is what keeps its row in one line. Measured
    // against the row's full width, not against the columns the tiles helper
    // picked: those follow from this.
    function fitKpiFigures(grid) {
        var cards = Array.prototype.filter.call(grid.children, function (c) {
            return c.classList.contains('bp-dv-kpi') && !c.hidden && c.offsetParent !== null;
        });
        var was = grid.classList.contains('is-long');
        var long = false;
        var root = document.getElementById('brikpanel-dashboard');
        // On a tablet the store cards take 3 + 3 whatever the figures are.
        var fixedRows = grid.id === 'brikpanel-kpi-cards' && root && root.classList.contains('bp-dv-lt800');
        if (cards.length > 1 && !fixedRows) {
            var gap = parseFloat(window.getComputedStyle(grid).columnGap) || 0;
            var room = (grid.clientWidth - gap * (cards.length - 1)) / cards.length - (cards[0].offsetWidth - contentWidth(cards[0]));
            // The figures are measured as drawn: a CSS zoom on the page scales
            // that, not the widths above, and every row read as too long.
            var drawn = grid.getBoundingClientRect().width;
            var scale = grid.offsetWidth && Math.abs(drawn - grid.offsetWidth) > 1 ? drawn / grid.offsetWidth : 1;
            var widest = function () {
                var w = 0;
                cards.forEach(function (c) {
                    var v = c.querySelector('.brikpanel-dash-card-value');
                    if (v) w = Math.max(w, v.getBoundingClientRect().width / scale);
                });
                return w;
            };
            grid.classList.remove('is-long');
            if (widest() > room) {
                grid.classList.add('is-long');
                long = widest() <= room;
            }
        }
        grid.classList.toggle('is-long', long);
        // The tiles helper does not watch the row's own classes.
        if (long !== was && window.brikpanelTiles && window.brikpanelTiles.refit) window.brikpanelTiles.refit();
    }

    // Charts measured in pixels are drawn again when their card changes width.
    function initDvResize() {
        var root = document.getElementById('brikpanel-dashboard');
        if (root) applySteps(root, ROOT_STEPS);
        document.querySelectorAll('.bp-dv-card').forEach(function (card) { applySteps(card, CARD_STEPS); });
        dvCards('visitors').forEach(fitVisitors);
        sizeKpis();
        if (!V) return;
        if (root) V.watchWidth(root, function () { applySteps(root, ROOT_STEPS); });
        var redraw = {
            sales: function (card) { drawSalesCard(card, lastData, false); },
            funnel: function (card) { drawFunnelCard(card, lastData, false); },
            rates: function (card) { drawRatesCard(card, lastData, false); },
            visitors: function (card) {
                card.querySelectorAll('[data-bp-dv-view]').forEach(function (panel) {
                    if (!panel.hidden) drawVisitorsPanel(panel, lastData);
                });
            },
            customers: function (card) { drawCustomersCard(card, lastData); },
            live: function (card) { drawLiveCard(card); }
        };
        document.querySelectorAll('.bp-dv-card').forEach(function (card) {
            var kind = card.getAttribute('data-bp-dv');
            V.watchWidth(card, function () {
                applySteps(card, CARD_STEPS);
                if (kind === 'visitors') fitVisitors(card);
                if (!redraw[kind] || (!lastData && kind !== 'live')) return;
                V.tip.hide();
                try { redraw[kind](card); } catch (err) { if (window.console) window.console.error(err); }
            });
        });
        // The tiles helper changes the store row's columns without changing
        // the row's width, so the small lines follow a card's width.
        var kpiCard = document.querySelector('#brikpanel-kpi-cards > .bp-dv-kpi');
        if (kpiCard) {
            V.watchWidth(kpiCard, function () {
                sizeKpis();
                if (lastData) safe('sparks', function () { renderKpiSparks(lastData); });
            });
        }
    }

    // One failing card must never stop the others from drawing.
    function safe(name, fn) {
        try {
            fn();
        } catch (err) {
            if (window.console && window.console.error) {
                window.console.error('[BrikPanel] dashboard ' + name + ':', err); // i18n-ignore: developer console message
            }
        }
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

    // Dragging turns whichever globe is on screen. Bound once per element and
    // once on the window: bound per globe, every range change added another
    // set and a drag turned the globe that many times as fast.
    var globeDrag = { down: false, x: 0, y: 0, el: null, bound: false };

    function bindGlobeDrag(el) {
        if (!el || el.__bpGlobeDrag) return;
        el.__bpGlobeDrag = true;
        el.style.cursor = 'grab';
        el.addEventListener('pointerdown', function (e) {
            globeDrag.down = true;
            globeDrag.x = e.clientX;
            globeDrag.y = e.clientY;
            globeDrag.el = el;
            el.style.cursor = 'grabbing';
        });
        el.addEventListener('wheel', function (e) {
            e.preventDefault();
            // Firefox can count the wheel in lines (deltaMode 1) or pages, not pixels.
            var dy = e.deltaMode === 1 ? e.deltaY * 33 : (e.deltaMode === 2 ? e.deltaY * 600 : e.deltaY);
            globeTheta += dy * 0.0005;
        }, { passive: false });
        if (globeDrag.bound) return;
        globeDrag.bound = true;
        window.addEventListener('pointerup', function () {
            globeDrag.down = false;
            if (globeDrag.el) globeDrag.el.style.cursor = 'grab';
        });
        window.addEventListener('pointermove', function (e) {
            if (!globeDrag.down) return;
            globePhi += (e.clientX - globeDrag.x) * 0.005;
            globeTheta += (e.clientY - globeDrag.y) * 0.005;
            globeDrag.x = e.clientX;
            globeDrag.y = e.clientY;
        });
    }

    // cobe puts the canvas in a new box each time it starts and leaves the box
    // behind when it is destroyed: every range change nested the globe one box
    // deeper, each box keeping the size and the round clip of its day.
    function unwrapGlobeCanvas(container, canvas) {
        while (canvas.parentElement && canvas.parentElement !== container && container.contains(canvas.parentElement)) {
            var box = canvas.parentElement;
            box.parentElement.insertBefore(canvas, box);
            box.parentElement.removeChild(box);
        }
    }

    // Whether the canvas really has a live WebGL context. cobe draws nothing,
    // without a word, when it cannot get one (LibreWolf ships with WebGL off).
    function canvasHasGL(canvas) {
        try {
            var gl = canvas.getContext('webgl2') || canvas.getContext('webgl');
            return !!gl && !gl.isContextLost();
        } catch (e) {
            return false;
        }
    }

    // A WebGL context can be taken away at any time: the GPU resets after
    // sleep or a driver update, or the browser is over its number of live
    // contexts and drops the oldest. cobe never draws again after that and the
    // globe vanished until the page was reloaded. Now the drawn globe stands
    // in at once; the WebGL globe comes back when the browser returns the
    // context, or on a new canvas when it does not (at most twice a page).
    var globeLostTimer = 0;
    var globeCanvasSwaps = 0;

    function watchGlobeContext(canvas) {
        if (canvas.__bpGlobeWatch) return;
        canvas.__bpGlobeWatch = true;
        canvas.addEventListener('webglcontextlost', function (e) {
            e.preventDefault(); // without this the context is never handed back
            if (document.getElementById('brikpanel-globe') !== canvas) return;
            redrawGlobe();
            clearTimeout(globeLostTimer);
            globeLostTimer = setTimeout(function () {
                if (document.getElementById('brikpanel-globe') !== canvas) return;
                if (canvasHasGL(canvas) || globeCanvasSwaps >= 2) return;
                globeCanvasSwaps++;
                var fresh = document.createElement('canvas');
                fresh.id = 'brikpanel-globe';
                if (globeInstance) {
                    globeInstance.destroy();
                    globeInstance = null;
                }
                if (canvas.parentNode) canvas.parentNode.replaceChild(fresh, canvas);
                redrawGlobe();
            }, 3000);
        });
        canvas.addEventListener('webglcontextrestored', function () {
            clearTimeout(globeLostTimer);
            if (document.getElementById('brikpanel-globe') === canvas) redrawGlobe();
        });
    }

    // Arc data-transfer animation: each arc pulses one at a time, the hub
    // "sending" to a country. Grey level of arc idx at time (seconds): quickly
    // brighter, held, then faded back.
    function globeArcGlow(idx, total, time) {
        var sendDuration = 1.2; // seconds for one arc to light up and fade
        var cycleLen = (total || 1) * sendDuration;
        var local = time % cycleLen - idx * sendDuration;
        if (local < 0) local += cycleLen;
        if (local >= sendDuration) return 0.08;
        var p = local / sendDuration;
        if (p < 0.3) return 0.08 + 0.52 * (p / 0.3);
        if (p < 0.5) return 0.6;
        return Math.max(0.08, 0.6 * (1 - (p - 0.5) / 0.5));
    }

    // Draw the globe again with the markers it has, when its box is showing.
    function redrawGlobe() {
        var container = document.getElementById('globe-container');
        if (!container || container.hidden || !globeMarkers.length) return;
        createGlobeInstance();
    }

    function createGlobeInstance() {
        if (globeInstance) {
            globeInstance.destroy();
            globeInstance = null;
        }

        var canvas = document.getElementById('brikpanel-globe');
        if (!canvas) return;

        var container = document.getElementById('globe-container');
        var w = container ? container.offsetWidth : 500;
        var h = container ? container.offsetHeight : 450;
        var size = Math.min(w, h);

        if (container) unwrapGlobeCanvas(container, canvas);
        canvas.style.display = '';

        if (typeof COBE === 'undefined' || !COBE.default) {
            globeInstance = startLiteGlobe(container, canvas, size);
            return;
        }
        watchGlobeContext(canvas);

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
            if (container) unwrapGlobeCanvas(container, canvas);
            quality = tryGlobeAtSize(canvas, size, tiers[ti].render, tiers[ti].samples, allArcs);
            if (quality === 'fast') return;
            if (quality === 'nogl') {
                if (container) unwrapGlobeCanvas(container, canvas);
                globeInstance = startLiteGlobe(container, canvas, size);
                return;
            }
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

        // No WebGL (switched off, blocked, or the context is gone): cobe has
        // drawn nothing, the drawn globe takes over.
        if (!canvasHasGL(canvas)) {
            globe.destroy();
            return 'nogl';
        }

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

            if (!globeDrag.down && !prefersReducedMotion) {
                globePhi += rotationSpeed;
            }
            arcTime += 0.016;

            var pulsedArcs = allArcs.map(function (arc, idx) {
                var b = globeArcGlow(idx, allArcs.length, arcTime);
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

        bindGlobeDrag(canvas);

        // Add country code labels + set labelWrapper for visibility checks.
        // Not for a globe already replaced (a quick range change).
        setTimeout(function () {
            if (destroyed) return;
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

    // =========================================================================
    // GLOBE WITHOUT WEBGL
    // LibreWolf ships with WebGL turned off, and any browser can have it
    // blocked or lose it: cobe then draws nothing and the panel stood empty.
    // The same dotted globe, drawn on a 2D canvas. Its land is the points cobe
    // samples (12000 Fibonacci points over cobe's own map), worked out once and
    // kept here as 2 bits each (how dark the dot is), so nothing reads pixels
    // back (LibreWolf scrambles that). Light, size, markers, arcs, labels and
    // dragging follow the WebGL globe (tryGlobeAtSize, cobe's shader).
    // =========================================================================
    var LITE_SAMPLES = 12000;
    var LITE_LAND = '//////////////+////vv/98/zz/PPN8/8zzzPPdM8/zzzjPPO898zwzP/OM898xzD/vP8MwfwzPfMP8M8zzy/NPOMw/xDz/9P5w8/zw7EPD84M/Tx+Pv7I8v7zz+/Dw/c73z3v/PM+///z//Pz/8/3zz3/P7/8//z///PP8//73//PD/8//zz//PP/+/3//3PHf8/e//z/PD/8//393/PPc8P/3/zPDP8/f/z7/PDO89/z9zfPPM8P/zz/PDB89P////PHM8N/zwzPDP8MMvz8/LDM8ePzw9vHHNMPPzw/PDN8PP3x8/DDN8PHywwPDP8MPHz8/DDY8P/zw4PDD/MPHww8DDP8PP/w8/DD+8PXww4HDj+MPPw8/DD/8P/zw8/Dj/MPPww+DDD/PP/w8/DD/8P/ww8zDz8MPPw8/DD/8PPzw8/DD/MPPww/zDz8OP/w8/DD+8PPww8zDz8MPPw8/zD38OPxw8/DT+MLPww9zDz8OP/w8/DD/8MPww8zDz+MEPw8/zD78MPww8/DDPMCPww8zDz8OD/w8vDDj8OPww8zDS9MCPw8vzDz8MDxw8vDCzMHPww8zDz8MD/w8PDD/8MPww8zDA3MOPw4vzC78ML7w4fDBzMMPww8jDw/MDvwwPDD48MP8w8rDBzMHPwwfDDy8MPPww/DDxMMP8w8DDz/MDDwwfDDw8MPMw0bDD2MPPww/zDz8MHPww+DD4MMDcw8DDT/MDDww9DDw8MPMwwMDD8MPP0w+DDT8MHPwwMDDwMMDMw8jDD8MHnww8DDy8MPMwwKDDxMPH0w/TDB8MLjwwMDDyMMDEw8DDA/MPzww+DDD8MD0wwMDDzMPDww8DDA8MPvwwMDDTMMD8w8DDC3MPHww9DDD8MDcwwEDDzMOD8w/jDA8MPPwwsDDDMMDcw8TDD3MOLww9jDD8MLMwwQDDzMMP8w+zDD8MPPww8TDDMMHMw4TDD3MMOww8zDD8MLMww0DDzMMP8w4zDD8MOOww4zDDMMPMw0zDDzMMPwwwzDD8MNMwwwzDjMMP8wQzDD0MOPwwwzDDMMPMwgzjDzMMPwwwzDD8MLMwwwzDDMMP8wQzDDxMMOwwwzDDMMPMwQzzDDMMPwwwzDDwMGMwQwzDDMIP8wQzDDzMEOwwwzDDAMPMwwzzDDMAOwwwzDD2MIMwg0zDDMAPcwgzDDjMANwwwzDDNAPMw8zzDDMAOwwwzDDzIJMwg0zDDMAPMwwzDDDMBMgwwzDDDIHMw8zzDDMANAwwzDDzIKMgAwzDTMAPMgwxDDDMCMAw0zDDDALMAwzzDDMBPAwwyDDjEKMAAwzDzOBPMAwyDDDMAMAwwzDDDADMAAwzDzMDOAAwxDDjIIMAAwzDTPAPcAQxDDjMAMAAwzDDDADMAAwzDzMAO0AgxDDzIMMAAwwDDPANMAAwDDzMAMAAwzBDDMDMAAwyDjMAM8AAxDDjEMMAAwwBjPANMAAwDDzMAMgA0zADDMIMAAwwCzMAMsAAwDDTIMMAAwwAzPAMMAAwABzMAMYAwzADDMMMAAwwAzMAMsAAwDDDIIMAAwwAzPEMcAAwAADAAMMAwyADDMAMAAwwAzMAMsAAwCDDEGMAAwwADPGMMAIwADDADM8AwwADDMMMQMwwAzMDMcAEwBDDAPMAAwwAjDKMMAMwAADADMcA8wADDEMMwMwwAzEDMMAMwADDAPMQAwwAjDGMMAMwAADADM4A8wADDMMMwMwwAjADMMAMxADDAHMgAwwADDGMMQMwAADADMoA8wATDIIMwMwgADADMMQMzATDADMQAwwADDHMMQMxAABADMAA8zAzCAAMwMwQADADMIwMzAjAADMAAwQAzJDEMQMwAAAADMAw8zAzAAAMgMwAADADMAwMwAjAADMAAwAAzMDAMwMwAAAADMAg8zAzAAAMAMwAAzMDAAwMwATAADMQAwwAzMDAMwMyAAgMDMAgMxAzAAAMAMwgAzMDAAwMyATQMDMAAAwAzMDAMQMxAAwMDMgQMwAzAAAMAMQwAzMDAAwMzAzwMDMAAAwAxEDAsgMwAgwMDMwQMzAzAABMAMAwAzMDAQwMwAz0MDMAEAwAyMDA8gMRAgxMDMwgMzAzMABMAMDwwzEDAwwMwAy4IDMQIAwAzMDA8AMBAwxEDMggMzAzMADMAMAwwzADAwQMwAw4ADMAIAwAzIDB8gMAAw1ADMQQMwAyMADMAMAww7ADAwwMwAw8ADMAIAwAzADB8gMAAw1ADMQAMwAwMADMwMAwwzADAwwMwAw0ADMAAAwAzADCsQMAAwzADMQAMyAwIADMAMAwwzADAwAMwAw4ADMAAAwAyIDDMAMAAwzADMAAMwAwEADMAMAwAzADAAAMwAw0ADMAAAwAyADDMAMCAQzADMAAMwAwAADMAMCwAzADAQAMwAwyADMAAAwAwADCMAMjAAzADMwAMyAwAADMAMAwAzADAAAMzABzADMAAAwAwEDDMAMjAAzADMwAMzAwDADMAMAwAxMDAAAMzADzADMQAAwAwODDMAMCAAzIDMwAMzADDADMAMAwAwMDAAAMzADzADMwAAwAyPDCMAMCAAzMDkwAMzADDADMAMDwAxMDAAAMzABzMDAwAAwAzPABMAMDAAzMDAwAMzACDADEwMDwAzMDAAAMzAAzMDAwAAwAzPACIgMDAAzMDAwAMzAADADAwMDwAzMAAIwMzAAzMDAwABwAxPADAwMDAAzMDIwwMzAADADAwMDwAzMAAIwMzAAzMDAwAAxAwPADAwMDAAzMAMwwMzAADADAwMDwAyMADMwMjAAzMBAwAAzAwPADAwIDAAzMAMwwMzAADADAwADxAwMADMwMDAAzMAEwAAzAwPADAwADAAzMAD4wMDAADADAwADzAwMADMwEDAAzMAAwAATAwPADAwADAAzMADowMDAADADAwADzAwMADMwADAAzMAAwAMDAwPADAwADCAzMADMwADAADADAwADTAwMADMwADAAzMAAwAMDAAPADAwADBAyMADIwADAADADAwAHDAQMADMwADAAzMAAQAMDAAPADAgADDAwMADMwADAADADAQAODAAMADMAADAAwMAAAAMCAAPADAAALDAQIADMAADAAAADAAAMAAAMADAAADCAgIAAEAMAAAMADAAANBAAEADAAADAAAABAAAMAAIMADAAAHAAAAAAAAMAAAMADAAAMAAAMADAAADAAAAAAAAMAAMMADAAAMAAAAAAAAOAAAMAAAAAMAAAAACAAAAAAQAAAAAMAAMEAAAAAMAAAAAAAAMAAAEAAAAAMAAEAAAAAAAAAwAAAAAMAAAIAAAAAMAAMAAAAAAAAAYAAAAAMAAAAAAAAAAAA8AAAAAAAAAIAAAAAMAAEAAAAAAAAAQAAgAAMAAAAAAAAAAAAwAAAAAAAAAAAAgAAIAAAAAAAAAAAAwAAQAAAAAAAAAAAAAAAwAAAAAAAAAgAAAAAIAAAAAAAAAAAAwAAAAAAAAAQAAAAAAAAwAAAAAAAAAwAAAAAAAAAAAAAAAAAAwAAAAAAAAAgAAAAAAAAAAAAAAAAAAwAAAAAAAAAAAAAAAAAAgAAAAAAAAAwAAAAAAAAQAAAAAAAAAwAAAAAAAAAAAAAAAAAAwAAAAAAAAAAAAAAAAAAAAAAAAAAAAQAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAEACAAAAAAAAAAgAAAAAAADAAAAAAAEDAAAMAAwQRBAwEIAAggDDBwMAAwwwDAhMMIgwwzDDwMMAwwwDDDOMOIwwzDD/MMPwwxzTD/MMM8wzzzDPMP9wzzzD//MP8wz3zDvfP/9w//D//8P+0y/7D//MP/0///z/8P//w//T//+v/////3///////////////////////////////////////'; // i18n-ignore: map data, not text
    var liteLand = null;

    function liteLandPoints() {
        if (liteLand) return liteLand;
        var bits = window.atob(LITE_LAND);
        var golden = (Math.sqrt(5) - 1) / 2;
        var pts = [];
        for (var j = 0; j < LITE_SAMPLES; j++) {
            var level = (bits.charCodeAt(j >> 2) >> ((j & 3) * 2)) & 3;
            if (!level) continue;
            var y = 1 - 2 * j / LITE_SAMPLES;
            var m = Math.sqrt(Math.max(0, 1 - y * y));
            var l = ((j * golden) % 1) * 2 * Math.PI;
            pts.push(Math.cos(l) * m, y, Math.sin(l) * m, level / 3);
        }
        liteLand = new Float32Array(pts);
        return liteLand;
    }

    // A place on the globe, where cobe puts it.
    function liteXYZ(loc, lift) {
        var lat = loc[0] * Math.PI / 180;
        var lon = loc[1] * Math.PI / 180;
        var c = Math.cos(lat) * lift;
        return [c * Math.cos(lon), Math.sin(lat) * lift, -c * Math.sin(lon)];
    }

    // Brightness at a point of the globe facing the viewer by i, darkened by d
    // (cobe's shader): white middle, a faint grey ring near the rim, white rim.
    function liteShade(i, d) {
        return Math.max(0, Math.min(1, (1 - d) * Math.pow(i, 0.4) + 0.1 + Math.pow(1 - i, 4)));
    }

    function startLiteGlobe(container, canvas, size) {
        if (!container || !size) return null;
        var dpr = Math.min(window.devicePixelRatio || 1, 2);
        var px = Math.round(size * dpr);
        var box = document.createElement('div');
        box.className = 'brikpanel-globe-lite';
        box.style.cssText = 'position:relative;width:' + size + 'px;height:' + size + 'px;margin:0 auto;border-radius:50%;overflow:hidden;';
        var c2 = document.createElement('canvas');
        c2.width = px;
        c2.height = px;
        c2.style.cssText = 'display:block;width:' + size + 'px;height:' + size + 'px;';
        c2.setAttribute('role', 'img');
        c2.setAttribute('aria-label', i18n.globe_alt || 'Order locations globe');
        var ctx = c2.getContext('2d');
        if (!ctx) return null;
        box.appendChild(c2);
        canvas.style.display = 'none';
        container.appendChild(box);

        var half = px / 2;
        var R = half * 0.8;
        var dot = 0.0062 * R;
        var land = liteLandPoints();
        var count = land.length / 4;
        var screen = new Float32Array(count * 4);
        var shades = [];

        var sphere = ctx.createRadialGradient(half, half, 0, half, half, R);
        for (var st = 0; st <= 24; st++) {
            var rr = st / 24;
            var g0 = Math.round(255 * liteShade(Math.sqrt(Math.max(0, 1 - rr * rr)), 0));
            sphere.addColorStop(rr, 'rgb(' + g0 + ',' + g0 + ',' + g0 + ')');
        }

        var marks = globeMarkers.map(function (m, k) {
            var data = globeMarkersData[k] || {};
            var item = { p: liteXYZ(m.location, 1.02), r: Math.max(1.5 * dpr, m.size * R * 0.55), anchor: null, tag: null };
            if (data.code) {
                item.anchor = document.createElement('div');
                item.anchor.style.cssText = 'position:absolute;width:0;height:0;overflow:visible;pointer-events:none;';
                item.tag = document.createElement('span');
                item.tag.className = 'globe-code-tag';
                item.tag.textContent = data.code;
                item.anchor.appendChild(item.tag);
                box.appendChild(item.anchor);
            }
            return item;
        });

        // From the first country to each other one, lifted off the surface.
        var arcs = [];
        if (globeMarkers.length > 1) {
            var a = liteXYZ(globeMarkers[0].location, 1);
            for (var k = 1; k < globeMarkers.length; k++) {
                var b = liteXYZ(globeMarkers[k].location, 1);
                var w = Math.acos(Math.max(-1, Math.min(1, a[0] * b[0] + a[1] * b[1] + a[2] * b[2])));
                var pts = [];
                for (var t = 0; t <= 32; t++) {
                    var f = t / 32;
                    var s0 = w < 1e-4 ? 1 - f : Math.sin((1 - f) * w) / Math.sin(w);
                    var s1 = w < 1e-4 ? f : Math.sin(f * w) / Math.sin(w);
                    var up = 1 + 0.32 * Math.sin(Math.PI * f) * Math.min(1, w / 1.2);
                    pts.push([(s0 * a[0] + s1 * b[0]) * up, (s0 * a[1] + s1 * b[1]) * up, (s0 * a[2] + s1 * b[2]) * up]);
                }
                arcs.push(pts);
            }
        }

        var destroyed = false;
        var frame = 0;
        var arcTime = 0;
        var visible = true;

        function draw() {
            var sp = Math.sin(globePhi), cp = Math.cos(globePhi);
            var sn = Math.sin(globeTheta), cn = Math.cos(globeTheta);
            ctx.clearRect(0, 0, px, px);
            ctx.fillStyle = sphere;
            ctx.beginPath();
            ctx.arc(half, half, R, 0, Math.PI * 2);
            ctx.fill();

            // Land dots, one path per shade of grey.
            for (var s = 0; s < 12; s++) shades[s] = [];
            for (var n = 0; n < count; n++) {
                var o = n * 4;
                var x = land[o], y = land[o + 1], z = land[o + 2];
                var vz = -x * sp * cn + y * sn + z * cp * cn;
                if (vz <= 0.06) continue;
                var lum = liteShade(vz, land[o + 3] * 6 * Math.pow(vz, 1.2));
                if (lum > 0.97) continue;
                screen[o] = half + (x * cp + z * sp) * R;
                screen[o + 1] = half - (x * sp * sn + y * cn - z * cp * sn) * R;
                screen[o + 2] = vz;
                shades[Math.round(lum * 11)].push(o);
            }
            for (var g = 0; g < 12; g++) {
                if (!shades[g].length) continue;
                var gray = Math.round(255 * g / 11);
                ctx.fillStyle = 'rgb(' + gray + ',' + gray + ',' + gray + ')';
                ctx.beginPath();
                for (var e = 0; e < shades[g].length; e++) {
                    var q = shades[g][e];
                    var dx = screen[q] - half, dy = screen[q + 1] - half;
                    var rot = Math.atan2(dy, dx);
                    var rx = dot * Math.max(0.2, screen[q + 2]);
                    ctx.moveTo(screen[q] + rx * Math.cos(rot), screen[q + 1] + rx * Math.sin(rot));
                    ctx.ellipse(screen[q], screen[q + 1], rx, dot, rot, 0, Math.PI * 2);
                }
                ctx.fill();
            }

            if (arcs.length) {
                ctx.lineWidth = Math.max(1, 0.9 * dpr);
                ctx.lineCap = 'round';
                arcs.forEach(function (line, idx) {
                    var lv = Math.round(255 * globeArcGlow(idx, arcs.length, arcTime));
                    ctx.strokeStyle = 'rgb(' + lv + ',' + lv + ',' + lv + ')';
                    ctx.beginPath();
                    var pen = false;
                    line.forEach(function (p) {
                        var ax = p[0] * cp + p[2] * sp;
                        var ay = p[0] * sp * sn + p[1] * cn - p[2] * cp * sn;
                        var az = -p[0] * sp * cn + p[1] * sn + p[2] * cp * cn;
                        if (az > 0 || ax * ax + ay * ay > 1) {
                            if (pen) ctx.lineTo(half + ax * R, half - ay * R); else ctx.moveTo(half + ax * R, half - ay * R);
                            pen = true;
                        } else {
                            pen = false;
                        }
                    });
                    ctx.stroke();
                });
            }

            ctx.fillStyle = '#1a1a1a';
            marks.forEach(function (m) {
                var mx = m.p[0] * cp + m.p[2] * sp;
                var my = m.p[0] * sp * sn + m.p[1] * cn - m.p[2] * cp * sn;
                var mz = -m.p[0] * sp * cn + m.p[1] * sn + m.p[2] * cp * cn;
                if (mz > 0) {
                    ctx.beginPath();
                    ctx.arc(half + mx * R, half - my * R, m.r, 0, Math.PI * 2);
                    ctx.fill();
                }
                if (m.anchor) {
                    m.anchor.style.left = ((half + mx * R) / px * 100) + '%';
                    m.anchor.style.top = ((half - my * R) / px * 100) + '%';
                    m.tag.classList.toggle('globe-code-tag--visible', mz > 0.15);
                }
            });
        }

        function tick() {
            frame = 0;
            if (destroyed || !visible) return;
            if (!globeDrag.down) globePhi += 0.003;
            arcTime += 0.016;
            draw();
            frame = requestAnimationFrame(tick);
        }

        // Reduced motion: drawn once, and again only while it is dragged.
        var onDrag = null;
        if (prefersReducedMotion) {
            onDrag = function () { if (globeDrag.down && globeDrag.el === c2) draw(); };
            window.addEventListener('pointermove', onDrag);
        }

        var io = null;
        if (window.IntersectionObserver) {
            io = new IntersectionObserver(function (entries) {
                visible = entries[0].isIntersecting;
                if (visible && !frame && !destroyed && !prefersReducedMotion) frame = requestAnimationFrame(tick);
            }, { threshold: 0.1 });
            io.observe(c2);
        }

        bindGlobeDrag(c2);
        draw();
        if (!prefersReducedMotion) frame = requestAnimationFrame(tick);

        return {
            lite: true,
            update: function () {},
            destroy: function () {
                destroyed = true;
                if (frame) cancelAnimationFrame(frame);
                if (io) io.disconnect();
                if (onDrag) window.removeEventListener('pointermove', onDrag);
                if (box.parentNode) box.parentNode.removeChild(box);
                canvas.style.display = '';
            }
        };
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

    // Flags are emoji, which Windows browsers draw as two plain letters
    // ("TR"). Checked once by drawing one: without colour the list shows a
    // small grey country-code badge instead.
    var flagEmojiOk = null;
    function canDrawFlags() {
        if (flagEmojiOk !== null) return flagEmojiOk;
        flagEmojiOk = true;
        try {
            var canvas = document.createElement('canvas');
            canvas.width = 20;
            canvas.height = 20;
            var ctx = canvas.getContext('2d');
            if (!ctx) return flagEmojiOk;
            ctx.textBaseline = 'top';
            ctx.font = '16px "Apple Color Emoji", "Segoe UI Emoji", "Noto Color Emoji", sans-serif'; // i18n-ignore: font stack, not text
            ctx.fillText(String.fromCodePoint(0x1F1F9, 0x1F1F7), 0, 0);
            var px = ctx.getImageData(0, 0, 20, 20).data;
            var colour = false;
            for (var i = 0; i < px.length; i += 4) {
                if (px[i + 3] > 32 && (Math.abs(px[i] - px[i + 1]) > 24 || Math.abs(px[i + 1] - px[i + 2]) > 24)) {
                    colour = true;
                    break;
                }
            }
            flagEmojiOk = colour;
        } catch (e) {
            flagEmojiOk = true;
        }
        return flagEmojiOk;
    }

    function countryFlag(code) {
        if (!code || !/^[A-Z]{2}$/.test(code)) return '';
        if (!canDrawFlags()) {
            return '<span class="bp-dv-flag-code">' + code + '</span>';
        }
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
                renderLive();
                return;
            }
            takeLiveRows(res.data);
        })
        .catch(function () {});
    }

    // Why the Live list is empty. It shows who is on the store right now, so
    // the selected window says nothing about it: only tracking being off is
    // a reason; otherwise nobody happens to be on the store.
    function liveEmptyText() {
        var t = emptyCtx.tracking;
        return (t && !t.enabled && i18n.empty_tracking_off) ? i18n.empty_tracking_off : (i18n.live_none || '');
    }

    // Device icon for a live visitor row.
    //
    // The server stores a three-value keyword (mobile | tablet | desktop), but
    // it is still whitelisted here rather than interpolated: the markup is
    // built as a string, so an unexpected value must never reach innerHTML.
    // Rows written before this feature shipped have no device at all, and
    // rather than claim "desktop" we emit an empty span of the same width so
    // the column stays aligned.
    var LIVE_DEVICE_PATHS = {
        mobile: '<rect x="7" y="2" width="10" height="20" rx="2" ry="2"></rect><line x1="12" y1="18" x2="12.01" y2="18"></line>',
        tablet: '<rect x="4" y="2" width="16" height="20" rx="2" ry="2"></rect><line x1="12" y1="18" x2="12.01" y2="18"></line>',
        desktop: '<rect x="2" y="3" width="20" height="14" rx="2" ry="2"></rect><line x1="8" y1="21" x2="16" y2="21"></line><line x1="12" y1="17" x2="12" y2="21"></line>'
    };

    function liveDeviceLabel(device) {
        if (device === 'mobile') return i18n.device_mobile || '';
        if (device === 'tablet') return i18n.device_tablet || '';
        if (device === 'desktop') return i18n.device_desktop || '';
        return '';
    }

    function liveDeviceIcon(device) {
        // hasOwnProperty, not a plain lookup: a bare LIVE_DEVICE_PATHS[device]
        // would happily return an inherited member ("constructor", "toString")
        // for a value that is not one of ours and splice it into the markup.
        if (!Object.prototype.hasOwnProperty.call(LIVE_DEVICE_PATHS, device)) {
            return '<span class="brikpanel-dash-live-device" aria-hidden="true"></span>';
        }
        return '<span class="brikpanel-dash-live-device" aria-hidden="true">' +
            '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' +
            LIVE_DEVICE_PATHS[device] +
            '</svg></span>';
    }

    // One bubble line for a live visitor's source ("Campaign: %s"). The
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

    function livePagePath(url) {
        var path = url || '';
        try {
            var u = new URL(url);
            path = u.pathname + (u.search || '');
        } catch (e) {}
        return path;
    }

    // What changes between two polls in a way the list shows.
    function liveSignature(rows) {
        return (rows || []).map(function (v) {
            return [v.id, v.page_url, v.page_title, v.visitor_status, v.cart_count, v.customer_name, Math.floor((Number(v.ago) || 0) / 60)].join('|');
        }).join('~');
    }

    function renderLive() {
        dvCards('live').forEach(drawLiveCard);
    }

    function drawLiveCard(card) {
        var body = dvSlot(card, 'body');
        var pill = card.querySelector('.bp-dv-live-pill');
        var countEl = dvSlot(card, 'count');
        if (!body) return;

        if (liveStale) {
            // The server refused the list (an expired nonce on a tab left open,
            // or the session ended): no count, and how to get it back.
            if (pill) pill.hidden = true;
            showEmpty(body, { text: i18n.live_reload || '', note: '', preset: '' });
            return;
        }
        if (liveRows === null) {
            if (pill) pill.hidden = true;
            return;
        }

        var rows = liveRows.slice().sort(function (a, b) { return (Number(a.ago) || 0) - (Number(b.ago) || 0); });
        var n = rows.length;
        if (pill) {
            pill.hidden = false;
            pill.classList.toggle('is-on', n > 0);
        }
        if (countEl) countEl.textContent = fillText(i18n.live_now, formatNumber(n));

        // Keep the focus on the "more" button when it redraws itself.
        var hadFocus = document.activeElement && body.contains(document.activeElement) && document.activeElement.classList.contains('bp-dv-live-more');
        body.textContent = '';

        if (n) {
            var list = document.createElement('div');
            list.className = 'bp-dv-live-list' + (liveOpen ? ' is-open' : '');
            rows.slice(0, liveOpen ? 100 : LIVE_SHOW).forEach(function (v) { list.appendChild(liveRow(v)); });
            body.appendChild(list);
            if (n > LIVE_SHOW) {
                var more = document.createElement('button');
                more.type = 'button';
                more.className = 'bp-dv-live-more';
                more.setAttribute('aria-expanded', liveOpen ? 'true' : 'false');
                var moreText = document.createElement('span');
                moreText.textContent = liveOpen ? (i18n.live_less || '') : (BF ? BF.count(i18n.live_more, n - LIVE_SHOW) : '');
                more.appendChild(moreText);
                more.insertAdjacentHTML('beforeend', '<svg class="bp-dv-chev' + (liveOpen ? ' is-up' : '') + '" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><polyline points="6 9 12 15 18 9"></polyline></svg>');
                more.addEventListener('click', function () {
                    liveOpen = !liveOpen;
                    renderLive();
                });
                body.appendChild(more);
                if (hadFocus) more.focus();
            }
            body.appendChild(todayLine());
        } else {
            liveOpen = false;
            var none = document.createElement('div');
            none.className = 'bp-dv-live-empty';
            none.innerHTML = '<svg width="18" height="18" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>';
            var noneText = document.createElement('span');
            noneText.setAttribute('dir', 'auto');
            noneText.textContent = liveEmptyText();
            none.appendChild(noneText);
            body.appendChild(none);
            todayDetails(body, card);
        }
    }

    function liveRow(v) {
        var row = document.createElement('button');
        row.type = 'button';
        row.className = 'bp-dv-live-row';
        row.setAttribute('data-bp-tip', 'top');

        var pageTitle = typeof v.page_title === 'string' ? v.page_title : '';
        var pagePath = livePagePath(v.page_url);
        var page = pageTitle || pagePath;
        var src = (v.source && typeof v.source === 'object' && v.source.channel) ? v.source : null;
        var srcChannel = src ? sourceChannelLabel(src.channel) : '';
        var srcLine = src ? srcChannel + (src.name ? ' · ' + src.name : '') : '';
        var name = typeof v.customer_name === 'string' ? v.customer_name : '';
        var status = v.visitor_status || (v.has_cart_item === 'Yes' ? 'cart' : 'browsing');
        var cartCount = Number(v.cart_count) || (v.has_cart_item === 'Yes' ? 1 : 0);

        var who = name || page;
        var what = name ? page : (srcLine || (i18n.browsing || ''));
        var chip = status === 'order_received'
            ? (i18n.order_received || '')
            : (cartCount > 0 && BF ? BF.count(i18n.live_in_cart, cartCount) : '');
        var ago = agoText(v.ago);

        row.insertAdjacentHTML('beforeend', liveDeviceIcon(v.device));
        var main = document.createElement('span');
        main.className = 'bp-dv-live-main';
        var whoEl = document.createElement('span');
        whoEl.className = 'bp-dv-live-who';
        whoEl.setAttribute('dir', 'auto');
        whoEl.textContent = who;
        var whatEl = document.createElement('span');
        whatEl.className = 'bp-dv-live-what';
        whatEl.setAttribute('dir', 'auto');
        whatEl.textContent = what;
        main.appendChild(whoEl);
        main.appendChild(whatEl);
        row.appendChild(main);

        var side = document.createElement('span');
        side.className = 'bp-dv-live-side';
        if (chip) {
            var chipEl = document.createElement('span');
            chipEl.className = 'bp-dv-live-chip' + (status === 'order_received' ? ' is-order' : '');
            chipEl.textContent = chip;
            side.appendChild(chipEl);
        }
        var agoEl = document.createElement('span');
        agoEl.className = 'bp-dv-live-ago';
        agoEl.textContent = ago;
        side.appendChild(agoEl);
        row.appendChild(side);

        // The details the row has no room for, in its own bubble.
        var lines = [];
        var deviceLabel = liveDeviceLabel(v.device);
        if (deviceLabel) lines.push(deviceLabel);
        if (v.customer_email) lines.push(v.customer_email);
        if (v.customer_phone) lines.push(v.customer_phone);
        if (pageTitle) lines.push(pageTitle);
        if (v.page_url) lines.push(v.page_url);
        if (src) {
            lines.push(liveSourceLine(i18n.live_src_source, srcLine));
            if (src.medium) lines.push(liveSourceLine(i18n.live_src_medium, src.medium));
            if (src.campaign) lines.push(liveSourceLine(i18n.live_src_campaign, src.campaign));
            if (src.term) lines.push(liveSourceLine(i18n.live_src_term, src.term));
            if (src.landing) lines.push(liveSourceLine(i18n.live_src_landing, src.landing));
        }
        var bubble = document.createElement('span');
        bubble.className = 'brikpanel-tip bp-dv-tipbox bp-dv-live-tip';
        bubble.setAttribute('role', 'tooltip');
        var title = document.createElement('span');
        title.className = 'bp-dv-tip-t';
        title.setAttribute('dir', 'auto');
        title.textContent = who;
        bubble.appendChild(title);
        lines.forEach(function (line) {
            // Each line takes the direction of its own text, so on a
            // right-to-left screen "Landing page: /" or an email address is
            // not reordered around its punctuation.
            var l = document.createElement('span');
            l.className = 'bp-dv-tip-line';
            l.setAttribute('dir', 'auto');
            l.textContent = line;
            bubble.appendChild(l);
        });
        row.appendChild(bubble);
        row.setAttribute('aria-label', [who, what, chip, ago].filter(Boolean).join(', '));
        return row;
    }

    // While anyone is on the store, today's figures are one line at the bottom.
    function todayLine() {
        var t = todayInfo && todayInfo.block;
        var p = document.createElement('p');
        p.className = 'bp-dv-today-line';
        var head = document.createElement('span');
        head.className = 'bp-dv-today-line-h';
        head.textContent = i18n.today_so_far || '';
        p.appendChild(head);
        if (!t) return p;
        var add = function (node) { p.appendChild(node); };
        add(countNodes(document.createElement('span'), i18n.n_visitors, Number(t.visitors) || 0));
        add(countNodes(document.createElement('span'), i18n.camp_orders, Number(t.orders) || 0));
        var sales = document.createElement('span');
        sales.appendChild(bold(money(Number(t.revenue) || 0)));
        add(sales);
        return p;
    }

    // Nobody on the store: today so far, hour by hour, and the last order.
    function todayDetails(body, card) {
        var t = todayInfo && todayInfo.block;
        if (!t || !Array.isArray(t.hours)) return;

        var head = document.createElement('div');
        head.className = 'bp-dv-today-h';
        var h1 = document.createElement('span');
        h1.textContent = i18n.today_so_far || '';
        var h2 = document.createElement('span');
        h2.textContent = t.built_at && BF ? fillText(i18n.today_until, BF.date(Number(t.built_at), timeFmt())) : '';
        head.appendChild(h1);
        head.appendChild(h2);
        body.appendChild(head);

        var figs = document.createElement('div');
        figs.className = 'bp-dv-today-figs';
        [[i18n.visitors, formatNumber(Number(t.visitors) || 0)], [i18n.orders, formatNumber(Number(t.orders) || 0)], [i18n.sales, money(Number(t.revenue) || 0)]].forEach(function (f) {
            var cell = document.createElement('div');
            var label = document.createElement('span');
            label.textContent = f[0] || '';
            cell.appendChild(label);
            cell.appendChild(bold(f[1]));
            figs.appendChild(cell);
        });
        body.appendChild(figs);

        var hours = document.createElement('div');
        hours.className = 'bp-dv-hours-box';
        hours.setAttribute('tabindex', '0');
        hours.setAttribute('role', 'group');
        hours.setAttribute('aria-label', i18n.hours_aria || '');
        body.appendChild(hours);
        if (V) {
            V.bars(hours, {
                values: t.hours.map(function (x) { return x ? Number(x.r) || 0 : null; }),
                now: t.now_hour,
                ariaLabel: i18n.hours_aria || '',
                live: dvSlot(card, 'hours-live'),
                labelAt: function (h) { return hourLabel(h); },
                tipAt: function (i) {
                    var x = t.hours[i];
                    var title = BF ? BF.range(hourLabel(i), hourLabel(i, '59')) : '';
                    if (!x) return { title: title, rows: [[i18n.sales || '', i18n.sales_not_yet || '']] };
                    return { title: title, rows: [[i18n.sales || '', money(Number(x.r) || 0)], [i18n.orders || '', formatNumber(Number(x.o) || 0)]] };
                }
            });
        }

        if (t.last_order && t.last_order.number) {
            var line = document.createElement('p');
            line.className = 'bp-dv-last-order';
            fillLastOrder(line);
            body.appendChild(line);
        }
    }

    // "Last order #348, 28 min ago", the number linking to the order.
    function fillLastOrder(line) {
        var last = todayInfo && todayInfo.block ? todayInfo.block.last_order : null;
        if (!line || !last || !last.number) return;
        var link = document.createElement(last.edit_url ? 'a' : 'b');
        if (last.edit_url) link.href = last.edit_url;
        var bdi = document.createElement('bdi');
        bdi.textContent = '#' + last.number;
        link.appendChild(bdi);
        // Seconds since the order on the server's clock, plus the time this
        // page has been open since the data arrived.
        var since = (Number(todayInfo.serverNow) || 0) - (Number(last.ts) || 0) + (Date.now() / 1000 - todayInfo.clientAt);
        fillNodes(line, i18n.last_order || '%1$s, %2$s', [link, agoText(since)]);
    }

    // A fresh list from the poll: redrawn only when something the list shows
    // changed, and never while one of its bubbles is open.
    function takeLiveRows(rows) {
        rows = Array.isArray(rows) ? rows : [];
        var sig = liveSignature(rows);
        var openTip = document.querySelector('.bp-dv-live .is-bp-tip-open');
        var first = liveRows === null;
        liveRows = rows;
        liveListEmpty = rows.length === 0;
        if (!first && sig === liveSig) {
            // Nothing new: only the "Last order ... ago" line moves on.
            if (liveListEmpty) document.querySelectorAll('.bp-dv-live .bp-dv-last-order').forEach(fillLastOrder);
            return;
        }
        if (openTip) {
            liveSig = '';
            return;
        }
        liveSig = sig;
        renderLive();
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
