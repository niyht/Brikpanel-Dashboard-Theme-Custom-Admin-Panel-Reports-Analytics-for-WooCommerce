/**
 * BrikPanel — Vendors page controller.
 *
 * Vanilla JS, no dependencies. Two modes selected from
 * window.brikpanelVendors.mode at boot:
 *
 *   - list   → list/search/filter, modal CRUD, summary cards.
 *   - detail → vendor profile page with stats, recent POs, sourced products,
 *              12-month spend trend (rendered as inline SVG bars).
 */
(function () {
    'use strict';

    if (typeof window.brikpanelVendors === 'undefined') return;
    var cfg = window.brikpanelVendors;

    document.addEventListener('DOMContentLoaded', function () {
        if (cfg.mode === 'detail') initDetail();
        else                       initList();
    });

    // ─────────────────────────────────────────────────────────────────────
    // Shared helpers
    // ─────────────────────────────────────────────────────────────────────
    function $(id) { return document.getElementById(id); }
    function ajax(action, data) {
        var fd = new FormData();
        fd.append('action', action);
        fd.append('_ajax_nonce', cfg.nonce);
        for (var k in data) {
            if (Object.prototype.hasOwnProperty.call(data, k)) fd.append(k, data[k]);
        }
        return fetch(cfg.ajax_url, {
            method: 'POST',
            body: fd,
            credentials: 'same-origin',
        }).then(function (r) { return r.json(); });
    }
    function escapeHtml(s) {
        if (s === null || typeof s === 'undefined') return '';
        return String(s).replace(/[&<>"']/g, function (m) {
            return ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' })[m];
        });
    }
    function escapeAttr(s) { return escapeHtml(s); }
    // A long e-mail breaks only at its joints (after "@", before a dot),
    // never mid-word (CLAUDE.md table rule).
    function emailHtml(email) {
        var at = email.indexOf('@');
        if (at < 0) return escapeHtml(email);
        var dotBreak = function (part) { return escapeHtml(part).split('.').join('<wbr>.'); };
        return dotBreak(email.slice(0, at)) + '@<wbr>' + dotBreak(email.slice(at + 1));
    }
    function truncate(s, n) {
        s = String(s || '');
        return s.length > n ? s.slice(0, n - 1) + '…' : s;
    }
    // The store's price format (symbol side, separators, decimals) through
    // front-end/shared/brikpanel-format.js; it was always "$1,234.56".
    function fmtMoney(n) {
        n = parseFloat(n) || 0;
        return window.brikpanelFormat ? window.brikpanelFormat.money(n) : cfg.currency + n.toFixed(2);
    }
    // A stored Y-m-d in the store's short date format.
    function fmtDate(ymd) {
        return window.brikpanelFormat ? window.brikpanelFormat.dateShort(ymd) : String(ymd || '');
    }
    function toast(message, isError) {
        var el = document.createElement('div');
        el.className = 'brikpanel-ven-toast' + (isError ? ' is-error' : '');
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
        var state = { page: 1, search: '', status: 'active', loading: false };
        var dom = {};

        dom.tbody       = $('brikpanel-ven-tbody');
        dom.search      = $('brikpanel-ven-search');
        dom.statusF     = $('brikpanel-ven-status-filter');
        dom.addBtn      = $('brikpanel-ven-add-btn');
        dom.overlay     = $('brikpanel-ven-overlay');
        dom.modalTitle  = $('brikpanel-ven-modal-title');
        dom.modalClose  = $('brikpanel-ven-modal-close');
        dom.cancelBtn   = $('brikpanel-ven-cancel-btn');
        dom.form        = $('brikpanel-ven-form');
        dom.editId      = $('brikpanel-ven-edit-id');
        dom.fields = {
            name:        $('brikpanel-ven-name'),
            contact:     $('brikpanel-ven-contact'),
            email:       $('brikpanel-ven-email'),
            phone:       $('brikpanel-ven-phone'),
            website:     $('brikpanel-ven-website'),
            tax_id:      $('brikpanel-ven-tax-id'),
            lead_time:   $('brikpanel-ven-lead-time'),
            shipping_fee:$('brikpanel-ven-shipping-fee'),
            address:     $('brikpanel-ven-address'),
            notes:       $('brikpanel-ven-notes'),
            is_active:   $('brikpanel-ven-active'),
        };
        dom.summary = {
            active:  $('brikpanel-ven-active-count'),
            spend:   $('brikpanel-ven-spend-90d'),
            openPos: $('brikpanel-ven-open-pos'),
        };
        dom.pagination = $('brikpanel-ven-pagination');
        dom.pageInfo   = $('brikpanel-ven-page-info');
        dom.prev       = $('brikpanel-ven-prev');
        dom.next       = $('brikpanel-ven-next');

        // events
        if (dom.addBtn)    dom.addBtn.addEventListener('click', openAddModal);
        if (dom.modalClose)dom.modalClose.addEventListener('click', closeModal);
        if (dom.cancelBtn) dom.cancelBtn.addEventListener('click', closeModal);
        if (dom.form)      dom.form.addEventListener('submit', onSubmit);
        if (dom.overlay)   dom.overlay.addEventListener('click', function (e) { if (e.target === dom.overlay) closeModal(); });

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
        if (dom.statusF) {
            dom.statusF.addEventListener('change', function () {
                state.status = dom.statusF.value;
                state.page = 1;
                load();
            });
        }
        if (dom.prev) dom.prev.addEventListener('click', function () { if (state.page > 1) { state.page--; load(); } });
        if (dom.next) dom.next.addEventListener('click', function () { state.page++; load(); });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && dom.overlay && !dom.overlay.hidden) closeModal();
        });

        // Rows turn into stacked cards when the table cannot show every column
        // in its card (field test B6: View, Edit and Delete were cut off on
        // tablets). Labels come from the header texts.
        var listTable = $('brikpanel-ven-table');
        var listFit = (listTable && window.brikpanelFitTable) ? window.brikpanelFitTable(listTable, { labels: 'head', slack: 0 }) : null;
        function setBody(html) {
            dom.tbody.innerHTML = html;
            if (listFit) listFit.refit();
        }

        load();

        // ─── List operations ─────────────────────────────────────────────
        function load() {
            if (state.loading) return;
            state.loading = true;
            setBody('<tr><td colspan="6" class="brikpanel-ven-empty">' + escapeHtml(cfg.i18n.loading) + '</td></tr>');
            ajax('brikpanel_vendors_list', {
                search: state.search,
                status: state.status,
                page:   state.page,
            }).then(function (res) {
                state.loading = false;
                if (!res || !res.success) { renderError(); return; }
                renderRows(res.data.items || []);
                renderSummary(res.data.summary || {});
                renderPagination(res.data);
            }).catch(function () {
                state.loading = false;
                renderError();
            });
        }

        function renderRows(items) {
            if (!items.length) {
                setBody('<tr><td colspan="6" class="brikpanel-ven-empty">' + escapeHtml(cfg.i18n.no_vendors) + '</td></tr>');
                return;
            }
            var html = '';
            for (var i = 0; i < items.length; i++) {
                html += renderRow(items[i]);
            }
            setBody(html);

            var editBtns = dom.tbody.querySelectorAll('[data-action="edit"]');
            for (var e = 0; e < editBtns.length; e++) {
                editBtns[e].addEventListener('click', function (ev) {
                    ev.stopPropagation();
                    openEditModal(parseInt(this.dataset.id, 10));
                });
            }
            var delBtns = dom.tbody.querySelectorAll('[data-action="delete"]');
            for (var d = 0; d < delBtns.length; d++) {
                delBtns[d].addEventListener('click', function (ev) {
                    ev.stopPropagation();
                    onDelete(parseInt(this.dataset.id, 10));
                });
            }
        }

        function renderRow(v) {
            // Built from parts so each can wrap on its own terms: the e-mail
            // breaks only after "@" and before a dot, the phone stays whole.
            var contactParts = [];
            if (v.contact_name) contactParts.push(escapeHtml(v.contact_name));
            if (v.email) contactParts.push('<span dir="ltr">' + emailHtml(v.email) + '</span>');
            if (v.phone) contactParts.push('<span class="brikpanel-ven-nowrap" dir="ltr">' + escapeHtml(v.phone) + '</span>');
            var contact = contactParts.join(' · ');
            var leadHtml;
            if (v.avg_lead_time !== null && typeof v.avg_lead_time !== 'undefined') {
                var avg = Math.round(v.avg_lead_time);
                leadHtml = avg + ' ' + escapeHtml(cfg.i18n.days_short);
                if (v.default_lead_time_days > 0 && Math.abs(avg - v.default_lead_time_days) >= 1) {
                    var diff = avg - v.default_lead_time_days;
                    var cls  = diff > 0 ? 'is-slow' : 'is-fast';
                    var sign = diff > 0 ? '+' : '−';
                    leadHtml += ' <span class="brikpanel-ven-delta ' + cls + '">' + sign + Math.abs(diff) + escapeHtml(cfg.i18n.days_short) + '</span>';
                }
            } else if (v.default_lead_time_days > 0) {
                leadHtml = '<span class="brikpanel-ven-muted">' + v.default_lead_time_days + escapeHtml(cfg.i18n.days_short) + ' ' + escapeHtml(cfg.i18n.lead_default) + '</span>';
            } else {
                leadHtml = '<span class="brikpanel-ven-muted">—</span>';
            }
            var archived = v.is_active ? '' : ' <span class="brikpanel-ven-archived-pill">' + escapeHtml(cfg.i18n.archived) + '</span>';

            var detailUrl = cfg.detail_url + v.id;
            var shippingHint = v.default_shipping_fee_fmt
                ? '<div class="brikpanel-ven-vendor-meta">' + escapeHtml(cfg.i18n.days_short ? (cfg.i18n.default_shipping || 'Default shipping:') + ' ' : '') + escapeHtml(v.default_shipping_fee_fmt) + '</div>'
                : '';

            return '' +
                '<tr>' +
                    '<td class="brikpanel-fit-lead">' +
                        '<a class="brikpanel-ven-vendor-name brikpanel-ven-vendor-link" href="' + escapeAttr(detailUrl) + '">' + escapeHtml(v.name) + '</a>' + archived +
                        (v.address ? '<div class="brikpanel-ven-vendor-meta">' + escapeHtml(truncate(v.address, 60)) + '</div>' : '') +
                        (v.default_shipping_fee_fmt ? '<div class="brikpanel-ven-vendor-meta">' + escapeHtml(cfg.i18n.ship || 'Shipping') + ': ' + escapeHtml(v.default_shipping_fee_fmt) + '</div>' : '') +
                    '</td>' +
                    '<td>' + (contact ? contact : '<span style="color:#8a8a8a;">—</span>') + '</td>' + // contrast-ok: empty-value marker.
                    '<td class="brikpanel-ven-num">' + escapeHtml(v.spend_90d_fmt) + '</td>' +
                    '<td class="brikpanel-ven-num">' + leadHtml + '</td>' +
                    '<td class="brikpanel-ven-num">' + (v.open_pos > 0 ? v.open_pos : '<span style="color:#616161;">0</span>') + '</td>' +
                    '<td class="brikpanel-ven-num brikpanel-fit-full">' +
                        '<div class="brikpanel-ven-row-actions">' +
                            '<a class="brikpanel-ven-btn brikpanel-ven-btn-icon" href="' + escapeAttr(detailUrl) + '" title="' + escapeAttr(cfg.i18n.view || 'View') + '" aria-label="' + escapeAttr(cfg.i18n.view || 'View') + '">' +
                                '<svg width="14" height="14" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M5 2l7 6-7 6" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>' +
                            '</a>' +
                            '<button type="button" class="brikpanel-ven-btn brikpanel-ven-btn-icon" data-action="edit" data-id="' + v.id + '" title="' + escapeAttr(cfg.i18n.edit || 'Edit') + '" aria-label="' + escapeAttr(cfg.i18n.edit || 'Edit') + '">' +
                                '<svg width="14" height="14" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M11.5 2.5a1.414 1.414 0 1 1 2 2L5 13l-3 1 1-3 8.5-8.5z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>' +
                            '</button>' +
                            '<button type="button" class="brikpanel-ven-btn brikpanel-ven-btn-icon" data-action="delete" data-id="' + v.id + '" title="' + escapeAttr(cfg.i18n.delete || 'Delete') + '" aria-label="' + escapeAttr(cfg.i18n.delete || 'Delete') + '">' +
                                '<svg width="14" height="14" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M3 4h10M6 4V2.5C6 2 6.5 2 7 2h2c.5 0 1 0 1 .5V4M5 4l.5 9.5c0 .5.5.5 1 .5h3c.5 0 1 0 1-.5L11 4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>' +
                            '</button>' +
                        '</div>' +
                    '</td>' +
                '</tr>';
        }

        function renderSummary(s) {
            if (dom.summary.active)  dom.summary.active.textContent  = (typeof s.active_count !== 'undefined') ? s.active_count : '—';
            if (dom.summary.spend)   dom.summary.spend.textContent   = s.spend_90d_fmt || '—';
            if (dom.summary.openPos) dom.summary.openPos.textContent = (typeof s.open_pos !== 'undefined') ? s.open_pos : '—';
        }

        function renderPagination(data) {
            if (!dom.pagination) return;
            if (data.pages <= 1) { dom.pagination.hidden = true; return; }
            dom.pagination.hidden = false;
            dom.pageInfo.textContent = data.page + ' / ' + data.pages;
            dom.prev.disabled = data.page <= 1;
            dom.next.disabled = data.page >= data.pages;
        }

        function renderError() {
            setBody('<tr><td colspan="6" class="brikpanel-ven-empty">' + escapeHtml(cfg.i18n.error) + '</td></tr>');
        }

        // ─── Modal (add/edit) ────────────────────────────────────────────
        function openAddModal() {
            dom.modalTitle.textContent = cfg.i18n.add_title;
            dom.editId.value = '';
            resetForm();
            dom.fields.is_active.checked = true;
            showModal();
        }

        function openEditModal(id) {
            ajax('brikpanel_vendors_get', { id: id }).then(function (res) {
                if (!res || !res.success) { toast(cfg.i18n.error, true); return; }
                var v = res.data;
                dom.modalTitle.textContent = cfg.i18n.edit_title;
                dom.editId.value = String(v.id);
                dom.fields.name.value         = v.name || '';
                dom.fields.contact.value      = v.contact_name || '';
                dom.fields.email.value        = v.email || '';
                dom.fields.phone.value        = v.phone || '';
                dom.fields.website.value      = v.website || '';
                dom.fields.tax_id.value       = v.tax_id || '';
                dom.fields.lead_time.value    = v.default_lead_time_days || 0;
                dom.fields.shipping_fee.value = v.default_shipping_fee || 0;
                dom.fields.address.value      = v.address || '';
                dom.fields.notes.value        = v.notes || '';
                dom.fields.is_active.checked  = !!v.is_active;
                showModal();
            });
        }

        function showModal() {
            dom.overlay.hidden = false;
            setTimeout(function () { dom.fields.name.focus(); }, 60);
        }
        function closeModal() {
            dom.overlay.hidden = true;
        }
        function resetForm() {
            for (var k in dom.fields) {
                if (!Object.prototype.hasOwnProperty.call(dom.fields, k)) continue;
                var el = dom.fields[k];
                if (!el) continue;
                if (el.type === 'checkbox') { el.checked = false; }
                else { el.value = ''; }
            }
        }

        function onSubmit(e) {
            e.preventDefault();
            var name = dom.fields.name.value.trim();
            if (!name) {
                toast(cfg.i18n.name_required, true);
                dom.fields.name.focus();
                return;
            }
            var payload = {
                id:                     dom.editId.value || 0,
                name:                   name,
                contact_name:           dom.fields.contact.value.trim(),
                email:                  dom.fields.email.value.trim(),
                phone:                  dom.fields.phone.value.trim(),
                website:                dom.fields.website.value.trim(),
                tax_id:                 dom.fields.tax_id.value.trim(),
                default_lead_time_days: dom.fields.lead_time.value || 0,
                default_shipping_fee:   dom.fields.shipping_fee.value || 0,
                address:                dom.fields.address.value.trim(),
                notes:                  dom.fields.notes.value.trim(),
                is_active:              dom.fields.is_active.checked ? 1 : 0,
            };
            ajax('brikpanel_vendors_save', payload).then(function (res) {
                if (!res || !res.success) {
                    toast((res && res.data && res.data.message) || cfg.i18n.error, true);
                    return;
                }
                toast(cfg.i18n.saved, false);
                closeModal();
                load();
            }).catch(function () { toast(cfg.i18n.error, true); });
        }

        function onDelete(id) {
            if (!confirm(cfg.i18n.confirm_delete)) return;
            ajax('brikpanel_vendors_delete', { id: id }).then(function (res) {
                if (!res || !res.success) { toast(cfg.i18n.error, true); return; }
                toast(cfg.i18n.deleted, false);
                load();
            });
        }
    }

    // ═════════════════════════════════════════════════════════════════════
    // DETAIL MODE
    // ═════════════════════════════════════════════════════════════════════
    function initDetail() {
        var dom = {
            statsRoot:    $('brikpanel-ven-detail-stats'),
            posTbody:     $('brikpanel-ven-detail-pos-tbody'),
            productsTbody:$('brikpanel-ven-detail-products-tbody'),
            chart:        $('brikpanel-ven-spend-chart'),
            chartEmpty:   $('brikpanel-ven-spend-chart-empty'),
            editBtn:      $('brikpanel-ven-detail-edit-btn'),
            // Edit modal (same markup as list page)
            overlay:      $('brikpanel-ven-overlay'),
            modalTitle:   $('brikpanel-ven-modal-title'),
            modalClose:   $('brikpanel-ven-modal-close'),
            cancelBtn:    $('brikpanel-ven-cancel-btn'),
            form:         $('brikpanel-ven-form'),
            editId:       $('brikpanel-ven-edit-id'),
            fields: {
                name:        $('brikpanel-ven-name'),
                contact:     $('brikpanel-ven-contact'),
                email:       $('brikpanel-ven-email'),
                phone:       $('brikpanel-ven-phone'),
                website:     $('brikpanel-ven-website'),
                tax_id:      $('brikpanel-ven-tax-id'),
                lead_time:   $('brikpanel-ven-lead-time'),
                shipping_fee:$('brikpanel-ven-shipping-fee'),
                address:     $('brikpanel-ven-address'),
                notes:       $('brikpanel-ven-notes'),
                is_active:   $('brikpanel-ven-active'),
            }
        };

        // Same fit for the two detail tables (purchase orders, products).
        var posTable = $('brikpanel-ven-detail-pos'), productsTable = $('brikpanel-ven-detail-products');
        var posFit = (posTable && window.brikpanelFitTable) ? window.brikpanelFitTable(posTable, { labels: 'head', slack: 0 }) : null;
        var productsFit = (productsTable && window.brikpanelFitTable) ? window.brikpanelFitTable(productsTable, { labels: 'head', slack: 0 }) : null;
        function setPos(html) {
            if (!dom.posTbody) return;
            dom.posTbody.innerHTML = html;
            if (posFit) posFit.refit();
        }
        function setProducts(html) {
            if (!dom.productsTbody) return;
            dom.productsTbody.innerHTML = html;
            if (productsFit) productsFit.refit();
        }

        load();

        if (dom.editBtn) dom.editBtn.addEventListener('click', openEditModal);
        if (dom.modalClose) dom.modalClose.addEventListener('click', closeModal);
        if (dom.cancelBtn)  dom.cancelBtn.addEventListener('click', closeModal);
        if (dom.form)       dom.form.addEventListener('submit', onSubmit);
        if (dom.overlay)    dom.overlay.addEventListener('click', function (e) { if (e.target === dom.overlay) closeModal(); });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && dom.overlay && !dom.overlay.hidden) closeModal();
        });

        function load() {
            ajax('brikpanel_vendors_detail', { id: cfg.vendor_id }).then(function (res) {
                if (!res || !res.success) {
                    setPos('<tr><td colspan="6" class="brikpanel-ven-empty">' + escapeHtml(cfg.i18n.error) + '</td></tr>');
                    setProducts('<tr><td colspan="5" class="brikpanel-ven-empty">' + escapeHtml(cfg.i18n.error) + '</td></tr>');
                    return;
                }
                renderStats(res.data.vendor || {}, res.data.stats || {});
                renderPos(res.data.pos || []);
                renderProducts(res.data.products || []);
                renderTrend(res.data.trend || []);
            }).catch(function () {
                setPos('<tr><td colspan="6" class="brikpanel-ven-empty">' + escapeHtml(cfg.i18n.error) + '</td></tr>');
                setProducts('<tr><td colspan="5" class="brikpanel-ven-empty">' + escapeHtml(cfg.i18n.error) + '</td></tr>');
            });
        }

        function setStat(name, value) {
            if (!dom.statsRoot) return;
            var el = dom.statsRoot.querySelector('[data-stat="' + name + '"]');
            if (el) el.textContent = value;
        }
        function setStatHtml(name, html) {
            if (!dom.statsRoot) return;
            var el = dom.statsRoot.querySelector('[data-stat="' + name + '"]');
            if (el) el.innerHTML = html;
        }
        function setStatSub(name, html) {
            if (!dom.statsRoot) return;
            var el = dom.statsRoot.querySelector('[data-stat-sub="' + name + '"]');
            if (el) el.innerHTML = html;
        }

        function renderStats(vendor, stats) {
            setStat('lifetime_spend', stats.lifetime_spend_fmt || fmtMoney(0));
            setStat('spend_90d',      stats.spend_90d_fmt      || fmtMoney(0));
            setStat('open_pos',       (typeof stats.open_pos !== 'undefined') ? stats.open_pos : '—');
            setStat('products_count', (typeof stats.products_count !== 'undefined') ? stats.products_count : '—');

            // Open value (sub-line under open_pos count)
            if (stats.open_value > 0) {
                setStatSub('open_value', escapeHtml(stats.open_value_fmt || fmtMoney(stats.open_value)) + ' ' + escapeHtml(cfg.i18n.open_value || 'open value'));
            } else {
                setStatSub('open_value', '');
            }

            // Average lead time + comparison vs default
            if (stats.avg_lead_time !== null && typeof stats.avg_lead_time !== 'undefined') {
                var avg = Math.round(stats.avg_lead_time);
                var leadHtml = escapeHtml(String(avg)) + ' ' + escapeHtml(cfg.i18n.days_short);
                setStatHtml('avg_lead_time', leadHtml);

                if (vendor.default_lead_time_days > 0) {
                    var diff = avg - vendor.default_lead_time_days;
                    var sub  = '';
                    if (diff === 0) {
                        sub = '<span class="brikpanel-ven-delta">' + escapeHtml(cfg.i18n.on_target) + '</span>';
                    } else if (diff > 0) {
                        sub = '<span class="brikpanel-ven-delta is-slow">+' + diff + escapeHtml(cfg.i18n.days_short) + ' ' + escapeHtml(cfg.i18n.slower) + '</span>';
                    } else {
                        sub = '<span class="brikpanel-ven-delta is-fast">' + diff + escapeHtml(cfg.i18n.days_short) + ' ' + escapeHtml(cfg.i18n.faster) + '</span>';
                    }
                    setStatSub('default_lead_time', sub);
                } else {
                    setStatSub('default_lead_time', '');
                }
            } else {
                if (vendor.default_lead_time_days > 0) {
                    setStatHtml('avg_lead_time', '<span class="brikpanel-ven-muted">' + vendor.default_lead_time_days + escapeHtml(cfg.i18n.days_short) + '</span>');
                    setStatSub('default_lead_time', '<span class="brikpanel-ven-muted">' + escapeHtml(cfg.i18n.default_no_pos || '') + '</span>');
                } else {
                    setStatHtml('avg_lead_time', '—');
                    setStatSub('default_lead_time', '');
                }
            }
        }

        function renderPos(pos) {
            if (!dom.posTbody) return;
            if (!pos.length) {
                setPos('<tr><td colspan="6" class="brikpanel-ven-empty">' + escapeHtml(cfg.i18n.no_pos) + '</td></tr>');
                return;
            }
            var html = '';
            for (var i = 0; i < pos.length; i++) {
                var p = pos[i];
                var lead = (p.lead_days !== null && typeof p.lead_days !== 'undefined')
                    ? (p.lead_days + escapeHtml(cfg.i18n.days_short))
                    : '<span class="brikpanel-ven-muted">—</span>';
                html +=
                    '<tr>' +
                        // No link while stock orders is switched off: its page does not exist then.
                        '<td class="brikpanel-fit-lead">' + (cfg.so_edit_url
                            ? '<a class="brikpanel-ven-vendor-link" href="' + escapeAttr(cfg.so_edit_url + p.id) + '">' + escapeHtml(p.reference) + '</a>'
                            : escapeHtml(p.reference)) + '</td>' +
                        '<td><span class="brikpanel-ven-status brikpanel-ven-status--' + escapeAttr(p.status) + '">' + escapeHtml(p.status_label) + '</span></td>' +
                        '<td>' + (p.order_date    ? escapeHtml(fmtDate(p.order_date))    : '<span class="brikpanel-ven-muted">—</span>') + '</td>' +
                        '<td>' + (p.received_date ? escapeHtml(fmtDate(p.received_date)) : '<span class="brikpanel-ven-muted">—</span>') + '</td>' +
                        '<td class="brikpanel-ven-num">' + lead + '</td>' +
                        '<td class="brikpanel-ven-num brikpanel-fit-headline">' + escapeHtml(p.total_fmt) + '</td>' +
                    '</tr>';
            }
            setPos(html);
        }

        function renderProducts(products) {
            if (!dom.productsTbody) return;
            if (!products.length) {
                setProducts('<tr><td colspan="5" class="brikpanel-ven-empty">' + escapeHtml(cfg.i18n.no_products) + '</td></tr>');
                return;
            }
            var html = '';
            for (var i = 0; i < products.length; i++) {
                var p = products[i];
                var titleCell = p.edit_url
                    ? '<a class="brikpanel-ven-vendor-link" href="' + escapeAttr(p.edit_url) + '">' + escapeHtml(p.title) + '</a>'
                    : escapeHtml(p.title);
                html +=
                    '<tr>' +
                        '<td class="brikpanel-fit-lead">' + titleCell + '</td>' +
                        '<td>' + (p.sku   ? escapeHtml(p.sku)   : '<span class="brikpanel-ven-muted">—</span>') + '</td>' +
                        '<td class="brikpanel-ven-num">' + (p.stock !== '' ? escapeHtml(p.stock) : '<span class="brikpanel-ven-muted">—</span>') + '</td>' +
                        '<td class="brikpanel-ven-num">' + (p.cost  ? escapeHtml(p.cost)  : '<span class="brikpanel-ven-muted">—</span>') + '</td>' +
                        '<td class="brikpanel-ven-num">' + (p.price ? escapeHtml(p.price) : '<span class="brikpanel-ven-muted">—</span>') + '</td>' +
                    '</tr>';
            }
            setProducts(html);
        }

        function renderTrend(trend) {
            if (!dom.chart) return;
            var hasData = false;
            var max = 0;
            for (var i = 0; i < trend.length; i++) {
                if (trend[i].value > 0) hasData = true;
                if (trend[i].value > max) max = trend[i].value;
            }
            if (!hasData) {
                dom.chart.hidden = true;
                if (dom.chartEmpty) dom.chartEmpty.hidden = false;
                return;
            }
            dom.chart.hidden = false;
            if (dom.chartEmpty) dom.chartEmpty.hidden = true;

            // Inline SVG bar chart — 12 evenly spaced bars; max height 100%.
            var w = 600, h = 160, padTop = 12, padBot = 28, padL = 4, padR = 4;
            var chartW = w - padL - padR;
            var chartH = h - padTop - padBot;
            var n = trend.length;
            var slotW = chartW / n;
            var barW = Math.max(8, slotW - 8);

            var svg = '<svg viewBox="0 0 ' + w + ' ' + h + '" preserveAspectRatio="none" width="100%" height="' + h + '" role="img">';
            // Baseline
            svg += '<line x1="' + padL + '" y1="' + (padTop + chartH) + '" x2="' + (padL + chartW) + '" y2="' + (padTop + chartH) + '" stroke="#e3e3e3" stroke-width="1" />';
            for (var j = 0; j < n; j++) {
                var t = trend[j];
                var barH = max > 0 ? (t.value / max) * chartH : 0;
                var x = padL + slotW * j + (slotW - barW) / 2;
                var y = padTop + chartH - barH;
                var fill = t.value > 0 ? '#303030' : '#e3e3e3';
                svg += '<rect x="' + x + '" y="' + y + '" width="' + barW + '" height="' + (barH > 0 ? barH : 2) + '" rx="2" ry="2" fill="' + fill + '">';
                svg += '<title>' + escapeHtml(t.label + ': ' + t.value_fmt) + '</title>';
                svg += '</rect>';
                // Month label
                svg += '<text x="' + (x + barW / 2) + '" y="' + (h - 8) + '" text-anchor="middle" font-size="10" fill="#616161" font-family="-apple-system, Segoe UI, sans-serif">' + escapeHtml(t.label) + '</text>';
            }
            svg += '</svg>';
            dom.chart.innerHTML = svg;
        }

        // ─── Edit modal on detail page (reuse list-page form) ────────────
        function openEditModal() {
            ajax('brikpanel_vendors_get', { id: cfg.vendor_id }).then(function (res) {
                if (!res || !res.success) { toast(cfg.i18n.error, true); return; }
                var v = res.data;
                if (dom.modalTitle) dom.modalTitle.textContent = cfg.i18n.edit_title;
                dom.editId.value = String(v.id);
                dom.fields.name.value         = v.name || '';
                dom.fields.contact.value      = v.contact_name || '';
                dom.fields.email.value        = v.email || '';
                dom.fields.phone.value        = v.phone || '';
                dom.fields.website.value      = v.website || '';
                dom.fields.tax_id.value       = v.tax_id || '';
                dom.fields.lead_time.value    = v.default_lead_time_days || 0;
                dom.fields.shipping_fee.value = v.default_shipping_fee || 0;
                dom.fields.address.value      = v.address || '';
                dom.fields.notes.value        = v.notes || '';
                dom.fields.is_active.checked  = !!v.is_active;
                dom.overlay.hidden = false;
                setTimeout(function () { dom.fields.name.focus(); }, 60);
            });
        }
        function closeModal() {
            dom.overlay.hidden = true;
        }
        function onSubmit(e) {
            e.preventDefault();
            var name = dom.fields.name.value.trim();
            if (!name) { toast(cfg.i18n.name_required, true); dom.fields.name.focus(); return; }
            var payload = {
                id:                     dom.editId.value || 0,
                name:                   name,
                contact_name:           dom.fields.contact.value.trim(),
                email:                  dom.fields.email.value.trim(),
                phone:                  dom.fields.phone.value.trim(),
                website:                dom.fields.website.value.trim(),
                tax_id:                 dom.fields.tax_id.value.trim(),
                default_lead_time_days: dom.fields.lead_time.value || 0,
                default_shipping_fee:   dom.fields.shipping_fee.value || 0,
                address:                dom.fields.address.value.trim(),
                notes:                  dom.fields.notes.value.trim(),
                is_active:              dom.fields.is_active.checked ? 1 : 0,
            };
            ajax('brikpanel_vendors_save', payload).then(function (res) {
                if (!res || !res.success) {
                    toast((res && res.data && res.data.message) || cfg.i18n.error, true);
                    return;
                }
                toast(cfg.i18n.saved, false);
                closeModal();
                // Refresh data without full page reload — header text won't change
                // but stats / chart / lead time defaults will reflect updates.
                load();
            }).catch(function () { toast(cfg.i18n.error, true); });
        }
    }
})();
