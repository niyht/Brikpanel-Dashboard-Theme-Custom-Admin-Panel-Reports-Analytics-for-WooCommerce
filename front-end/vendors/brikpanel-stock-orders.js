/**
 * BrikPanel — Stock Orders controller.
 *
 * One file, two modes:
 *   - list    → renders the PO table with filters, pagination, summary.
 *   - editor  → renders the create/edit page with product picker,
 *               line items, totals, and status transitions.
 *
 * Mode is selected from window.brikpanelStockOrders.mode at boot time so
 * we don't need two separate files.
 */
(function () {
    'use strict';

    if (typeof window.brikpanelStockOrders === 'undefined') return;
    var cfg = window.brikpanelStockOrders;

    document.addEventListener('DOMContentLoaded', function () {
        if (cfg.mode === 'editor') initEditor();
        else                       initList();
    });

    // ─── shared helpers ──────────────────────────────────────────────────
    function $(id) { return document.getElementById(id); }
    function ajax(action, data) {
        var fd = new FormData();
        fd.append('action', action);
        fd.append('_ajax_nonce', cfg.nonce);
        for (var k in data) {
            if (Object.prototype.hasOwnProperty.call(data, k)) fd.append(k, data[k]);
        }
        return fetch(cfg.ajax_url, { method:'POST', body: fd, credentials:'same-origin' })
            .then(function (r) { return r.json(); });
    }
    function escapeHtml(s) {
        if (s === null || typeof s === 'undefined') return '';
        return String(s).replace(/[&<>"']/g, function (m) {
            return ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' })[m];
        });
    }
    function fmtMoney(n) {
        n = parseFloat(n) || 0;
        return cfg.currency + n.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    }
    function toast(message, isError) {
        var el = document.createElement('div');
        el.textContent = message;
        el.style.cssText =
            'position:fixed;top:44px;right:20px;z-index:200000;' +
            'padding:0.625rem 0.875rem;border-radius:0.5rem;font-size:0.8125rem;font-weight:550;' +
            'background:' + (isError ? '#fce4e4' : '#e4f5e1') + ';' +
            'color:' + (isError ? '#c62828' : '#1a6b15') + ';' +
            'border:1px solid ' + (isError ? '#f1b0b0' : '#b7e1b0') + ';' +
            'box-shadow:0 4px 12px rgba(0,0,0,0.08);' +
            'transform:translateX(110%);transition:transform 0.3s cubic-bezier(0.16, 1, 0.3, 1);';
        document.body.appendChild(el);
        requestAnimationFrame(function () { el.style.transform = 'translateX(0)'; });
        setTimeout(function () {
            el.style.transform = 'translateX(110%)';
            setTimeout(function () { if (el.parentNode) el.parentNode.removeChild(el); }, 350);
        }, 3500);
    }

    // ═════════════════════════════════════════════════════════════════════
    // LIST MODE
    // ═════════════════════════════════════════════════════════════════════
    function initList() {
        var state = { page: 1, search: '', status: '', date_from: '', date_to: '', loading: false };
        var dom = {
            tbody:    $('brikpanel-so-tbody'),
            search:   $('brikpanel-so-search'),
            statusF:  $('brikpanel-so-status-filter'),
            from:     $('brikpanel-so-from'),
            to:       $('brikpanel-so-to'),
            prev:     $('brikpanel-so-prev'),
            next:     $('brikpanel-so-next'),
            page:     $('brikpanel-so-page-info'),
            paging:   $('brikpanel-so-pagination'),
            sumOpen:  $('brikpanel-so-open'),
            sumOpenV: $('brikpanel-so-open-value'),
            sumRecv:  $('brikpanel-so-recv-90d'),
        };

        function load() {
            if (state.loading) return;
            state.loading = true;
            dom.tbody.innerHTML = '<tr><td colspan="8" class="brikpanel-so-empty">Loading…</td></tr>';
            ajax('brikpanel_so_list', {
                search:    state.search,
                status:    state.status,
                date_from: state.date_from,
                date_to:   state.date_to,
                page:      state.page,
            }).then(function (res) {
                state.loading = false;
                if (!res || !res.success) { renderError(); return; }
                renderRows(res.data.items || []);
                renderSummary(res.data.summary || {});
                renderPaging(res.data);
            }).catch(function () { state.loading = false; renderError(); });
        }

        function renderRows(items) {
            if (!items.length) {
                dom.tbody.innerHTML = '<tr><td colspan="8" class="brikpanel-so-empty">' + escapeHtml(cfg.i18n.no_orders) + '</td></tr>';
                return;
            }
            var html = '';
            for (var i = 0; i < items.length; i++) {
                var po = items[i];
                var url = cfg.edit_url + po.id;
                html +=
                '<tr>' +
                    '<td><a class="brikpanel-so-ref-link" href="' + url + '">' + escapeHtml(po.reference || ('#' + po.id)) + '</a></td>' +
                    '<td>' + escapeHtml(po.vendor_name) + '</td>' +
                    '<td><span class="brikpanel-so-status brikpanel-so-status--' + escapeHtml(po.status) + '">' + escapeHtml(po.status_label) + '</span></td>' +
                    '<td>' + (po.order_date    || '<span style="color:#8a8a8a;">—</span>') + '</td>' +
                    '<td>' + (po.expected_date || '<span style="color:#8a8a8a;">—</span>') + '</td>' +
                    '<td>' + (po.received_date || '<span style="color:#8a8a8a;">—</span>') + '</td>' +
                    '<td class="brikpanel-so-num">' + escapeHtml(po.total_fmt) + '</td>' +
                    '<td class="brikpanel-so-num">' +
                        '<div class="brikpanel-so-row-actions">' +
                            '<a class="brikpanel-so-btn brikpanel-so-btn-icon" href="' + url + '" title="' + escapeHtml(cfg.i18n.open || 'Open') + '">' +
                                '<svg width="14" height="14" viewBox="0 0 16 16" fill="none"><path d="M5 2l7 6-7 6" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>' +
                            '</a>' +
                            '<button type="button" class="brikpanel-so-btn brikpanel-so-btn-icon" data-action="delete" data-id="' + po.id + '" title="' + escapeHtml(cfg.i18n.delete || 'Delete') + '">' +
                                '<svg width="14" height="14" viewBox="0 0 16 16" fill="none"><path d="M3 4h10M6 4V2.5C6 2 6.5 2 7 2h2c.5 0 1 0 1 .5V4M5 4l.5 9.5c0 .5.5.5 1 .5h3c.5 0 1 0 1-.5L11 4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>' +
                            '</button>' +
                        '</div>' +
                    '</td>' +
                '</tr>';
            }
            dom.tbody.innerHTML = html;

            var delBtns = dom.tbody.querySelectorAll('[data-action="delete"]');
            for (var j = 0; j < delBtns.length; j++) {
                delBtns[j].addEventListener('click', function () {
                    if (!confirm(cfg.i18n.confirm_delete)) return;
                    var id = parseInt(this.dataset.id, 10);
                    ajax('brikpanel_so_delete', { id: id }).then(function (res) {
                        if (!res || !res.success) { toast(cfg.i18n.error, true); return; }
                        load();
                    });
                });
            }
        }

        function renderSummary(s) {
            if (dom.sumOpen)  dom.sumOpen.textContent  = (typeof s.open_count !== 'undefined') ? s.open_count : '—';
            if (dom.sumOpenV) dom.sumOpenV.textContent = s.open_value_fmt || '—';
            if (dom.sumRecv)  dom.sumRecv.textContent  = s.recv_90d_fmt || '—';
        }
        function renderPaging(d) {
            if (!dom.paging) return;
            if (d.pages <= 1) { dom.paging.hidden = true; return; }
            dom.paging.hidden = false;
            dom.page.textContent = d.page + ' / ' + d.pages;
            dom.prev.disabled = d.page <= 1;
            dom.next.disabled = d.page >= d.pages;
        }
        function renderError() {
            dom.tbody.innerHTML = '<tr><td colspan="8" class="brikpanel-so-empty">' + escapeHtml(cfg.i18n.error) + '</td></tr>';
        }

        if (dom.search) {
            var t;
            dom.search.addEventListener('input', function () {
                clearTimeout(t);
                t = setTimeout(function () {
                    state.search = dom.search.value.trim();
                    state.page = 1;
                    load();
                }, 250);
            });
        }
        if (dom.statusF) dom.statusF.addEventListener('change', function () { state.status = dom.statusF.value; state.page = 1; load(); });
        if (dom.from) dom.from.addEventListener('change', function () { state.date_from = dom.from.value; state.page = 1; load(); });
        if (dom.to)   dom.to.addEventListener('change',   function () { state.date_to   = dom.to.value;   state.page = 1; load(); });
        if (dom.prev) dom.prev.addEventListener('click',  function () { if (state.page > 1) { state.page--; load(); } });
        if (dom.next) dom.next.addEventListener('click',  function () { state.page++; load(); });

        load();
    }

    // ═════════════════════════════════════════════════════════════════════
    // EDITOR MODE
    // ═════════════════════════════════════════════════════════════════════
    function initEditor() {
        var dom = {
            form:       $('brikpanel-so-form'),
            vendor:     $('brikpanel-so-vendor'),
            reference:  $('brikpanel-so-reference'),
            orderDate:  $('brikpanel-so-order-date'),
            expDate:    $('brikpanel-so-expected-date'),
            recDate:    $('brikpanel-so-received-date'),
            shipping:   $('brikpanel-so-shipping'),
            tax:        $('brikpanel-so-tax'),
            notes:      $('brikpanel-so-notes'),
            picker:     $('brikpanel-so-product-search'),
            suggest:    $('brikpanel-so-suggestions'),
            tbody:      $('brikpanel-so-items-tbody'),
            saveBtn:    $('brikpanel-so-save-draft'),
            subtotal:   $('brikpanel-so-subtotal-value'),
            shippingV:  $('brikpanel-so-shipping-value'),
            taxV:       $('brikpanel-so-tax-value'),
            totalV:     $('brikpanel-so-total-value'),
        };

        var locked = !!cfg.locked;
        var items  = (cfg.items_seed || []).map(function (it) { return Object.assign({}, it); });

        // ─── render lines ────────────────────────────────────────────────
        function renderLines() {
            if (!items.length) {
                dom.tbody.innerHTML = '<tr class="brikpanel-so-items-empty"><td colspan="6">' + escapeHtml((cfg.i18n && cfg.i18n.empty_items) || 'No items yet — search above to add a product.') + '</td></tr>';
                recalcTotals();
                return;
            }
            var html = '';
            for (var i = 0; i < items.length; i++) {
                var it = items[i];
                var meta = [];
                if (it.sku) meta.push('SKU: ' + escapeHtml(it.sku));
                // i18n-ignore: variation_label is _x()-wrapped in class-brikpanel-stock-orders.php:446; scanner can't trace the &&-guarded key chain.
                if (it.variation_id) meta.push(((cfg.i18n && cfg.i18n.variation_label) || 'Variation #') + it.variation_id);
                html +=
                '<tr data-idx="' + i + '">' +
                    '<td>' +
                        '<div class="brikpanel-so-item-title">' + escapeHtml(it.title || ('#' + it.product_id)) + '</div>' +
                        (meta.length ? '<div class="brikpanel-so-item-meta">' + meta.join(' · ') + '</div>' : '') +
                    '</td>' +
                    '<td class="brikpanel-so-num"><input type="number" min="0" step="1" value="' + (it.qty_ordered || 0) + '" data-field="qty_ordered" ' + (locked ? 'disabled' : '') + ' /></td>' +
                    '<td class="brikpanel-so-num"><input type="number" min="0" step="1" value="' + (it.qty_received || 0) + '" data-field="qty_received" ' + (locked ? 'disabled' : '') + ' /></td>' +
                    '<td class="brikpanel-so-num"><input type="number" min="0" step="0.01" value="' + (it.unit_cost || 0) + '" data-field="unit_cost" ' + (locked ? 'disabled' : '') + ' /></td>' +
                    '<td class="brikpanel-so-num"><strong data-line-total>' + fmtMoney(it.line_total || 0) + '</strong></td>' +
                    '<td class="brikpanel-so-num">' +
                        (locked ? '' : '<button type="button" class="brikpanel-so-btn brikpanel-so-btn-icon" data-remove>' +
                            '<svg width="14" height="14" viewBox="0 0 16 16" fill="none"><path d="M4 4l8 8M12 4l-8 8" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>' +
                        '</button>') +
                    '</td>' +
                '</tr>';
            }
            dom.tbody.innerHTML = html;

            // Wire inputs
            var inputs = dom.tbody.querySelectorAll('input[data-field]');
            for (var k = 0; k < inputs.length; k++) {
                inputs[k].addEventListener('input', onLineEdit);
            }
            var rm = dom.tbody.querySelectorAll('[data-remove]');
            for (var r = 0; r < rm.length; r++) {
                rm[r].addEventListener('click', function () {
                    var idx = parseInt(this.closest('tr').dataset.idx, 10);
                    items.splice(idx, 1);
                    renderLines();
                });
            }
            recalcTotals();
        }

        function onLineEdit(e) {
            var tr = e.target.closest('tr');
            var idx = parseInt(tr.dataset.idx, 10);
            var field = e.target.dataset.field;
            var val = parseFloat(e.target.value);
            if (isNaN(val) || val < 0) val = 0;
            items[idx][field] = val;
            // Auto-cap received at ordered (UI nudge; server enforces too).
            if (field === 'qty_ordered' && items[idx].qty_received > val) {
                items[idx].qty_received = val;
            }
            items[idx].line_total = (items[idx].qty_ordered || 0) * (items[idx].unit_cost || 0);
            tr.querySelector('[data-line-total]').textContent = fmtMoney(items[idx].line_total);
            // Refresh received-qty input if we capped it.
            var qrInput = tr.querySelector('input[data-field="qty_received"]');
            if (qrInput && parseFloat(qrInput.value) !== items[idx].qty_received) {
                qrInput.value = items[idx].qty_received;
            }
            recalcTotals();
        }

        function recalcTotals() {
            var sub = 0;
            for (var i = 0; i < items.length; i++) {
                sub += (items[i].qty_ordered || 0) * (items[i].unit_cost || 0);
            }
            var ship = parseFloat(dom.shipping.value) || 0;
            var tax  = parseFloat(dom.tax.value) || 0;
            var total = sub + ship + tax;
            dom.subtotal.textContent  = fmtMoney(sub);
            dom.shippingV.textContent = fmtMoney(ship);
            dom.taxV.textContent      = fmtMoney(tax);
            dom.totalV.textContent    = fmtMoney(total);
        }

        // ─── product picker ─────────────────────────────────────────────
        var pickerTimer;
        var pickerActive = -1;
        var pickerResults = [];

        function showSuggestions(results) {
            pickerResults = results;
            pickerActive = -1;
            if (!results.length) {
                dom.suggest.innerHTML = '<div class="brikpanel-so-suggestion-empty">' + escapeHtml(cfg.i18n.no_results) + '</div>';
                dom.suggest.hidden = false;
                return;
            }
            var html = '';
            for (var i = 0; i < results.length; i++) {
                var r = results[i];
                var meta = r.sku ? r.sku : '';
                html += '<div class="brikpanel-so-suggestion" data-idx="' + i + '">' +
                            '<div>' + escapeHtml(r.title) + '</div>' +
                            (meta ? '<div class="brikpanel-so-suggestion-meta">' + escapeHtml(meta) + '</div>' : '') +
                        '</div>';
            }
            dom.suggest.innerHTML = html;
            dom.suggest.hidden = false;

            var rows = dom.suggest.querySelectorAll('.brikpanel-so-suggestion');
            for (var j = 0; j < rows.length; j++) {
                rows[j].addEventListener('mousedown', function (e) {
                    e.preventDefault();
                    var idx = parseInt(this.dataset.idx, 10);
                    pickItem(pickerResults[idx]);
                });
            }
        }
        function hideSuggestions() {
            dom.suggest.hidden = true;
        }
        function pickItem(r) {
            // Avoid dupes — if same product/variation already in list, bump qty.
            for (var i = 0; i < items.length; i++) {
                if (items[i].product_id === r.product_id && items[i].variation_id === r.variation_id) {
                    items[i].qty_ordered = (items[i].qty_ordered || 0) + 1;
                    items[i].line_total  = items[i].qty_ordered * (items[i].unit_cost || 0);
                    renderLines();
                    dom.picker.value = '';
                    hideSuggestions();
                    return;
                }
            }
            items.push({
                id:           0,
                product_id:   r.product_id,
                variation_id: r.variation_id,
                title:        r.title,
                sku:          r.sku || '',
                qty_ordered:  1,
                qty_received: 0,
                unit_cost:    parseFloat(r.cost) || 0,
                line_total:   parseFloat(r.cost) || 0,
            });
            renderLines();
            dom.picker.value = '';
            hideSuggestions();
        }

        if (dom.picker) {
            dom.picker.addEventListener('input', function () {
                clearTimeout(pickerTimer);
                var term = dom.picker.value.trim();
                if (term.length < 2) { hideSuggestions(); return; }
                pickerTimer = setTimeout(function () {
                    ajax('brikpanel_so_search_product', { term: term }).then(function (res) {
                        if (!res || !res.success) return;
                        showSuggestions(res.data || []);
                    });
                }, 220);
            });
            dom.picker.addEventListener('blur', function () {
                setTimeout(hideSuggestions, 120);
            });
            dom.picker.addEventListener('keydown', function (e) {
                if (dom.suggest.hidden) return;
                if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                    e.preventDefault();
                    var rows = dom.suggest.querySelectorAll('.brikpanel-so-suggestion');
                    if (!rows.length) return;
                    pickerActive += (e.key === 'ArrowDown') ? 1 : -1;
                    if (pickerActive < 0) pickerActive = rows.length - 1;
                    if (pickerActive >= rows.length) pickerActive = 0;
                    for (var i = 0; i < rows.length; i++) rows[i].classList.toggle('is-active', i === pickerActive);
                } else if (e.key === 'Enter') {
                    if (pickerActive >= 0 && pickerResults[pickerActive]) {
                        e.preventDefault();
                        pickItem(pickerResults[pickerActive]);
                    }
                } else if (e.key === 'Escape') {
                    hideSuggestions();
                }
            });
        }

        // ─── totals reactivity ──────────────────────────────────────────
        if (dom.shipping) dom.shipping.addEventListener('input', recalcTotals);
        if (dom.tax)      dom.tax.addEventListener('input', recalcTotals);

        // ─── Vendor defaults prefill ────────────────────────────────────
        // When a vendor is selected on a NEW PO (not editing an existing one),
        // pull their defaults (shipping fee, lead time → expected date) and
        // apply them to any field the user hasn't explicitly filled. We never
        // overwrite user input, and we never touch saved POs.
        function applyVendorDefaults(vendorId) {
            if (locked) return;
            if (cfg.po_id) return; // editing an existing PO — don't second-guess saved values
            if (!vendorId || vendorId === '0') return;

            // Fetch via the vendors AJAX namespace (separate nonce).
            var fd = new FormData();
            fd.append('action', 'brikpanel_vendors_defaults');
            fd.append('_ajax_nonce', cfg.vendors_nonce);
            fd.append('id', vendorId);
            fetch(cfg.ajax_url, { method:'POST', body: fd, credentials:'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (!res || !res.success) return;
                    var v = res.data;
                    var applied = false;

                    // Shipping fee — only if current is 0/empty
                    if (dom.shipping) {
                        var current = parseFloat(dom.shipping.value);
                        if ((isNaN(current) || current === 0) && parseFloat(v.default_shipping_fee) > 0) {
                            dom.shipping.value = parseFloat(v.default_shipping_fee).toFixed(2);
                            applied = true;
                        }
                    }

                    // Expected date — only if empty AND lead time is defined.
                    // Use order_date as anchor; fall back to today.
                    if (dom.expDate && !dom.expDate.value && parseInt(v.default_lead_time_days, 10) > 0) {
                        var anchor = dom.orderDate && dom.orderDate.value ? new Date(dom.orderDate.value + 'T00:00:00') : new Date();
                        if (!isNaN(anchor.getTime())) {
                            anchor.setDate(anchor.getDate() + parseInt(v.default_lead_time_days, 10));
                            dom.expDate.value = anchor.toISOString().slice(0, 10);
                            applied = true;
                        }
                    }

                    if (applied) {
                        recalcTotals();
                        toast(cfg.i18n.prefilled || 'Defaults applied.', false);
                    }
                })
                .catch(function () { /* silent — prefill is best-effort */ });
        }

        if (dom.vendor) {
            dom.vendor.addEventListener('change', function () {
                applyVendorDefaults(dom.vendor.value);
            });
        }
        // Trigger once on load for the case where the editor was opened with
        // a pre-selected vendor via ?vendor_id=X (link from vendor detail page).
        if (!cfg.po_id && cfg.preselect_vid && cfg.preselect_vid > 0) {
            applyVendorDefaults(cfg.preselect_vid);
        }

        // ─── save ───────────────────────────────────────────────────────
        function collectPayload() {
            return {
                id:            cfg.po_id || 0,
                vendor_id:     dom.vendor ? dom.vendor.value : 0,
                reference:     dom.reference.value.trim(),
                order_date:    dom.orderDate.value,
                expected_date: dom.expDate.value,
                received_date: dom.recDate.value,
                shipping_fee:  dom.shipping.value || 0,
                tax:           dom.tax.value || 0,
                notes:         dom.notes.value,
                items:         JSON.stringify(items),
            };
        }

        if (dom.saveBtn) {
            dom.saveBtn.addEventListener('click', function () {
                if (locked) return;
                if (!dom.vendor.value || dom.vendor.value === '0') {
                    toast(cfg.i18n.vendor_required, true);
                    dom.vendor.focus();
                    return;
                }
                ajax('brikpanel_so_save', collectPayload()).then(function (res) {
                    if (!res || !res.success) {
                        toast((res && res.data && res.data.message) || cfg.i18n.error, true);
                        return;
                    }
                    toast(cfg.i18n.saved, false);
                    if (!cfg.po_id && res.data && res.data.id) {
                        // Redirect new POs into edit mode so the URL has the id.
                        window.location.href = cfg.back_url + '&action=edit&id=' + res.data.id;
                    }
                }).catch(function () { toast(cfg.i18n.error, true); });
            });
        }

        // ─── status transitions ─────────────────────────────────────────
        var statusBtns = document.querySelectorAll('[data-action="status"]');
        for (var s = 0; s < statusBtns.length; s++) {
            statusBtns[s].addEventListener('click', function () {
                if (locked) return;
                var target = this.dataset.status;
                if (target === 'received'  && !confirm(cfg.i18n.confirm_receive)) return;
                if (target === 'cancelled' && !confirm(cfg.i18n.confirm_cancel))  return;

                // Save first so the latest line items + totals are persisted
                // before the receive automation reads them.
                ajax('brikpanel_so_save', collectPayload()).then(function (saveRes) {
                    if (!saveRes || !saveRes.success) {
                        toast((saveRes && saveRes.data && saveRes.data.message) || cfg.i18n.error, true);
                        return;
                    }
                    var poId = (saveRes.data && saveRes.data.id) ? saveRes.data.id : cfg.po_id;
                    ajax('brikpanel_so_status', { id: poId, status: target }).then(function (res) {
                        if (!res || !res.success) {
                            toast((res && res.data && res.data.message) || cfg.i18n.error, true);
                            return;
                        }
                        // Reload the page so server-rendered status pill / actions refresh.
                        window.location.href = cfg.back_url + '&action=edit&id=' + poId;
                    });
                });
            });
        }

        // First render
        renderLines();
        recalcTotals();
    }
})();
