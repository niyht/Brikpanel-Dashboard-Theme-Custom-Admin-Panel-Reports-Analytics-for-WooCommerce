/**
 * BrikPanel - AJAX Coupons List
 * @package BrikPanel
 * @since 1.8.0
 */
(function ($) {
    'use strict';

    var CP = window.brikpanelCP || {};

    var state = {
        page: 1,
        per_page: CP.per_page || 20,
        search: '',
        status: 'any',
        sort: 'date-desc',
        selected: [],
        coupons: [],
        loading: false,
        total: 0,
        pages: 0,
        extraColumns: {},
        // Token-picker selections keyed by target ('products', 'exclude_products',
        // 'categories', 'exclude_categories'). Each value is an array of
        // {value, label} objects.
        tokens: {
            products: [],
            exclude_products: [],
            categories: [],
            exclude_categories: []
        }
    };

    var searchTimer = null;
    var tokenSearchTimers = {};

    // Map of token-target -> config (which AJAX action to call and which DOM ids to use).
    var TOKEN_TARGETS = {
        products:           { action: 'brikpanel_coupons_search_products',   tokensId: 'bpc-products-tokens',           inputId: 'bpc-products-search',           sugId: 'bpc-products-suggestions' },
        exclude_products:   { action: 'brikpanel_coupons_search_products',   tokensId: 'bpc-exclude-products-tokens',   inputId: 'bpc-exclude-products-search',   sugId: 'bpc-exclude-products-suggestions' },
        categories:         { action: 'brikpanel_coupons_search_categories', tokensId: 'bpc-categories-tokens',         inputId: 'bpc-categories-search',         sugId: 'bpc-categories-suggestions' },
        exclude_categories: { action: 'brikpanel_coupons_search_categories', tokensId: 'bpc-exclude-categories-tokens', inputId: 'bpc-exclude-categories-search', sugId: 'bpc-exclude-categories-suggestions' }
    };

    function isFieldEnabled(name) {
        return !!(CP.enabled_fields && CP.enabled_fields[name]);
    }

    // =========================================================================
    // INIT
    // =========================================================================

    function bindEvents() {
        // Search
        $('#bpc-search').on('input', function () {
            clearTimeout(searchTimer);
            var val = $(this).val();
            searchTimer = setTimeout(function () {
                state.search = val;
                state.page = 1;
                fetchCoupons();
            }, 350);
        });

        // Status tabs
        $(document).on('click', '.brikpanel-cp-tab', function () {
            $('.brikpanel-cp-tab').removeClass('active');
            $(this).addClass('active');
            state.status = $(this).data('status');
            state.page = 1;
            fetchCoupons();
        });

        // Sort
        $('#bpc-sort').on('change', function () {
            state.sort = $(this).val();
            state.page = 1;
            fetchCoupons();
        });

        // Check all
        $('#bpc-check-all').on('change', function () {
            var checked = this.checked;
            $('.brikpanel-cp-row-check').prop('checked', checked);
            if (checked) {
                state.selected = state.coupons.map(function (c) { return c.id; });
            } else {
                state.selected = [];
            }
            updateBulkBar();
        });

        // Row checkbox
        $(document).on('change', '.brikpanel-cp-row-check', function () {
            var id = parseInt($(this).val());
            if (this.checked) {
                if (state.selected.indexOf(id) === -1) state.selected.push(id);
            } else {
                state.selected = state.selected.filter(function (s) { return s !== id; });
            }
            $('#bpc-check-all').prop('checked', state.selected.length === state.coupons.length && state.coupons.length > 0);
            updateBulkBar();
        });

        // Bulk actions
        $('#bpc-select-all-btn').on('click', function () {
            state.selected = state.coupons.map(function (c) { return c.id; });
            $('.brikpanel-cp-row-check').prop('checked', true);
            $('#bpc-check-all').prop('checked', true);
            updateBulkBar();
        });

        $('#bpc-deselect-all-btn').on('click', function () {
            state.selected = [];
            $('.brikpanel-cp-row-check').prop('checked', false);
            $('#bpc-check-all').prop('checked', false);
            updateBulkBar();
        });

        $('#bpc-bulk-publish').on('click', function () { bulkStatusChange('publish'); });
        $('#bpc-bulk-draft').on('click', function () { bulkStatusChange('draft'); });
        $('#bpc-bulk-trash').on('click', function () { bulkTrash(); });

        // Pagination
        $(document).on('click', '.brikpanel-cp-page-btn:not(.disabled):not(.active)', function () {
            var p = $(this).data('page');
            if (p === 'prev') {
                state.page = Math.max(1, state.page - 1);
            } else if (p === 'next') {
                state.page = Math.min(state.pages, state.page + 1);
            } else {
                state.page = parseInt(p);
            }
            fetchCoupons();
            $('html, body').animate({ scrollTop: $('#bpc-table').offset().top - 80 }, 200);
        });

        // Inline edit - click on editable amount
        $(document).on('click', '.brikpanel-cp-editable', function (e) {
            e.stopPropagation();
            startInlineEdit($(this));
        });

        // Inline edit - save on Enter, cancel on Escape
        $(document).on('keydown', '.brikpanel-cp-inline-input', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                saveInlineEdit($(this));
            } else if (e.key === 'Escape') {
                cancelInlineEdit($(this));
            }
        });

        // Inline edit - save on blur
        $(document).on('blur', '.brikpanel-cp-inline-input', function () {
            var $input = $(this);
            setTimeout(function () {
                if ($input.closest('.brikpanel-cp-editable').length) {
                    saveInlineEdit($input);
                }
            }, 150);
        });

        // Edit coupon (open drawer)
        $(document).on('click', '.brikpanel-cp-action-edit', function (e) {
            e.preventDefault();
            e.stopPropagation();
            var id = parseInt($(this).closest('tr').data('id'));
            openDrawer(id);
        });

        // Click coupon code to open drawer
        $(document).on('click', '.brikpanel-cp-code-text', function (e) {
            e.stopPropagation();
            var id = parseInt($(this).closest('tr').data('id'));
            openDrawer(id);
        });

        // Add new coupon
        $('#bpc-add-new').on('click', function () {
            openDrawer(0);
        });

        // Close drawer
        $('#bpc-drawer-close, #bpc-drawer-cancel, #bpc-drawer-overlay').on('click', closeDrawer);
        $('#bpc-drawer-save').on('click', saveDrawer);

        // Keyboard shortcut: Escape to close drawer
        $(document).on('keydown', function (e) {
            if (e.key === 'Escape' && $('#bpc-drawer').hasClass('open')) {
                closeDrawer();
            }
        });

        // Toggle status
        $(document).on('click', '.brikpanel-cp-status-badge', function (e) {
            e.stopPropagation();
            var id = parseInt($(this).closest('tr').data('id'));
            toggleStatus(id);
        });

        // Delete coupon
        $(document).on('click', '.brikpanel-cp-action-delete', function (e) {
            e.preventDefault();
            e.stopPropagation();
            var id = parseInt($(this).closest('tr').data('id'));
            var code = $(this).closest('tr').find('.brikpanel-cp-code-text').text();
            if (confirm(CP.i18n.confirm_delete.replace('%s', code))) {
                deleteCoupon(id, false);
            }
        });

        // Permanent delete (trash view)
        $(document).on('click', '.brikpanel-cp-action-delete-perm', function (e) {
            e.preventDefault();
            e.stopPropagation();
            var id = parseInt($(this).closest('tr').data('id'));
            if (confirm(CP.i18n.confirm_permanent_delete)) {
                deleteCoupon(id, true);
            }
        });

        // Restore from trash
        $(document).on('click', '.brikpanel-cp-action-restore', function (e) {
            e.preventDefault();
            e.stopPropagation();
            var id = parseInt($(this).closest('tr').data('id'));
            restoreCoupon(id);
        });

        // Duplicate coupon
        $(document).on('click', '.brikpanel-cp-action-duplicate', function (e) {
            e.preventDefault();
            e.stopPropagation();
            var id = parseInt($(this).closest('tr').data('id'));
            duplicateCoupon(id);
        });

        // Generate coupon code
        $('#bpc-generate-code').on('click', function () {
            var code = generateCouponCode();
            $('#bpc-code').val(code).focus();
        });

        // Discount type changes prefix symbol
        $('#bpc-discount-type').on('change', function () {
            updateAmountPrefix();
        });

        // Token pickers (products / exclude products / categories / exclude categories)
        bindTokenPickers();
    }

    // =========================================================================
    // TOKEN PICKERS
    // =========================================================================

    function bindTokenPickers() {
        Object.keys(TOKEN_TARGETS).forEach(function (target) {
            var cfg = TOKEN_TARGETS[target];
            var $input = $('#' + cfg.inputId);
            if (!$input.length) return; // field not rendered (setting disabled)

            // Typing -> debounced search.
            $input.on('input', function () {
                var term = $(this).val();
                clearTimeout(tokenSearchTimers[target]);
                if (term.length < 2) {
                    $('#' + cfg.sugId).attr('hidden', true).empty();
                    return;
                }
                tokenSearchTimers[target] = setTimeout(function () {
                    runTokenSearch(target, term);
                }, 250);
            });

            // Keyboard: Escape closes suggestions.
            $input.on('keydown', function (e) {
                if (e.key === 'Escape') {
                    $('#' + cfg.sugId).attr('hidden', true);
                }
            });

            // Hide suggestions when focus leaves the wrap (small delay so a click registers).
            $input.on('blur', function () {
                setTimeout(function () { $('#' + cfg.sugId).attr('hidden', true); }, 150);
            });
        });

        // Suggestion clicks (event delegation).
        $(document).on('mousedown', '.brikpanel-cp-token-suggestion', function (e) {
            e.preventDefault();
            var $sug = $(this).closest('.brikpanel-cp-token-suggestions');
            var $field = $sug.closest('.brikpanel-cp-token-field');
            var target = $field.data('token-target');
            if (!target) return;
            addToken(target, {
                value: $(this).data('value'),
                label: $(this).data('label')
            });
            $field.find('.brikpanel-cp-token-input').val('').focus();
            $sug.attr('hidden', true);
        });

        // Token remove (event delegation).
        $(document).on('click', '.brikpanel-cp-token-remove', function (e) {
            e.preventDefault();
            var $token = $(this).closest('.brikpanel-cp-token');
            var $field = $token.closest('.brikpanel-cp-token-field');
            var target = $field.data('token-target');
            if (!target) return;
            removeToken(target, $token.data('value'));
        });
    }

    function runTokenSearch(target, term) {
        var cfg = TOKEN_TARGETS[target];
        var $sug = $('#' + cfg.sugId);

        $.ajax({
            url: CP.ajax_url,
            type: 'POST',
            data: {
                action: cfg.action,
                security: CP.nonce,
                q: term
            },
            success: function (res) {
                if (!res || !res.success) return;
                var items = (res.data && res.data.items) || [];
                if (!items.length) {
                    $sug.html('<div class="brikpanel-cp-token-empty">' + escHtml(CP.i18n.no_results) + '</div>');
                    $sug.removeAttr('hidden');
                    return;
                }
                // Filter out anything already selected for this target.
                var selectedValues = (state.tokens[target] || []).map(function (t) { return String(t.value); });
                var html = '';
                for (var i = 0; i < items.length; i++) {
                    if (selectedValues.indexOf(String(items[i].value)) !== -1) continue;
                    html += '<div class="brikpanel-cp-token-suggestion" data-value="' + escAttr(items[i].value) + '" data-label="' + escAttr(items[i].label) + '">' + escHtml(items[i].label) + '</div>';
                }
                if (!html) {
                    $sug.html('<div class="brikpanel-cp-token-empty">' + escHtml(CP.i18n.no_results) + '</div>');
                } else {
                    $sug.html(html);
                }
                $sug.removeAttr('hidden');
            }
        });
    }

    function addToken(target, item) {
        if (!item || !item.value) return;
        var list = state.tokens[target] || (state.tokens[target] = []);
        var sv = String(item.value);
        for (var i = 0; i < list.length; i++) {
            if (String(list[i].value) === sv) return; // already in
        }
        list.push({ value: parseInt(item.value, 10), label: String(item.label || item.value) });
        renderTokens(target);
    }

    function removeToken(target, value) {
        var list = state.tokens[target] || [];
        var sv = String(value);
        state.tokens[target] = list.filter(function (t) { return String(t.value) !== sv; });
        renderTokens(target);
    }

    function setTokens(target, items) {
        state.tokens[target] = (items || []).map(function (i) {
            return { value: parseInt(i.value, 10), label: String(i.label || i.value) };
        });
        renderTokens(target);
    }

    function renderTokens(target) {
        var cfg = TOKEN_TARGETS[target];
        if (!cfg) return;
        var $host = $('#' + cfg.tokensId);
        if (!$host.length) return;

        var list = state.tokens[target] || [];
        if (!list.length) {
            $host.empty();
            return;
        }
        var html = '';
        for (var i = 0; i < list.length; i++) {
            html += '<span class="brikpanel-cp-token" data-value="' + escAttr(list[i].value) + '">' +
                '<span class="brikpanel-cp-token-label">' + escHtml(list[i].label) + '</span>' +
                '<button type="button" class="brikpanel-cp-token-remove" aria-label="' + escAttr(CP.i18n.remove) + '">&times;</button>' +
                '</span>';
        }
        $host.html(html);
    }

    function tokenValues(target) {
        var list = state.tokens[target] || [];
        return list.map(function (t) { return parseInt(t.value, 10); }).filter(function (v) { return v > 0; });
    }

    // =========================================================================
    // FETCH COUPONS
    // =========================================================================

    function totalColumnCount() {
        // 9 native cols (check, code, type, amount, desc, usage, revenue, expiry,
        // status) + 1 actions col + N extras.
        var extras = state.extraColumns ? Object.keys(state.extraColumns).length : 0;
        return 10 + extras;
    }

    function syncAseHeaders() {
        var $headerRow = $('#bpc-table thead tr');
        if (!$headerRow.length) return;

        $headerRow.find('th.brikpanel-cp-th-ase').remove();

        var cols = state.extraColumns || {};
        var ids  = Object.keys(cols);
        if (!ids.length) return;

        var $actionsTh = $headerRow.find('th.brikpanel-cp-th-actions');
        for (var i = 0; i < ids.length; i++) {
            var colId = ids[i];
            var label = cols[colId];
            var $th = $('<th class="brikpanel-cp-th-ase column-' + escAttr(colId) + '" data-ase-col="' + escAttr(colId) + '"></th>')
                .text(label);
            if ($actionsTh.length) {
                $actionsTh.before($th);
            } else {
                $headerRow.append($th);
            }
        }
    }

    function fetchCoupons() {
        if (state.loading) return;
        state.loading = true;

        var $body = $('#bpc-table-body');
        $body.html('<tr class="brikpanel-cp-loading-row"><td colspan="' + totalColumnCount() + '"><div class="brikpanel-cp-spinner"></div></td></tr>');

        $.ajax({
            url: CP.ajax_url,
            type: 'POST',
            data: {
                action: 'brikpanel_fetch_coupons',
                security: CP.nonce,
                page: state.page,
                per_page: state.per_page,
                search: state.search,
                status: state.status,
                sort: state.sort
            },
            success: function (res) {
                state.loading = false;
                if (!res.success) {
                    $body.html('<tr><td colspan="' + totalColumnCount() + '" class="brikpanel-cp-empty">' + escHtml(res.data.message || CP.i18n.error) + '</td></tr>');
                    return;
                }

                state.coupons = res.data.coupons;
                state.total = res.data.total;
                state.pages = res.data.pages;
                state.extraColumns = res.data.extra_columns || {};

                updateCounts(res.data.counts);
                syncAseHeaders();
                renderCoupons();
                renderPagination();
                updateBulkBar();

                // Reset check-all
                $('#bpc-check-all').prop('checked', false);
            },
            error: function () {
                state.loading = false;
                $body.html('<tr><td colspan="' + totalColumnCount() + '" class="brikpanel-cp-empty">' + escHtml(CP.i18n.error) + '</td></tr>');
            }
        });
    }

    // =========================================================================
    // RENDER COUPONS
    // =========================================================================

    function renderCoupons() {
        var $body = $('#bpc-table-body');

        if (!state.coupons.length) {
            $body.html('<tr><td colspan="' + totalColumnCount() + '" class="brikpanel-cp-empty">' +
                '<div class="brikpanel-cp-empty-state">' +
                '<svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="#8a8a8a" stroke-width="1.5"><path d="M20 12V8H6a2 2 0 0 1-2-2c0-1.1.9-2 2-2h12v4"/><path d="M4 6v12c0 1.1.9 2 2 2h14v-4"/><path d="M18 12a2 2 0 0 0-2 2c0 1.1.9 2 2 2h4v-4h-4z"/></svg>' +
                '<p>' + escHtml(CP.i18n.no_coupons) + '</p>' +
                '</div></td></tr>');
            return;
        }

        var html = '';
        for (var i = 0; i < state.coupons.length; i++) {
            html += renderCouponRow(state.coupons[i]);
        }

        $body.html(html);

        // Restore checked states
        state.selected.forEach(function (id) {
            $body.find('.brikpanel-cp-row-check[value="' + id + '"]').prop('checked', true);
        });
    }

    function renderCouponRow(c) {
        var checked = state.selected.indexOf(c.id) > -1 ? ' checked' : '';
        var statusClass = c.status === 'publish' ? 'published' : (c.status === 'trash' ? 'trashed' : 'draft');
        var statusLabel = c.status === 'publish' ? CP.i18n.published : (c.status === 'trash' ? CP.i18n.trashed : CP.i18n.draft);

        // Type display
        var typeLabel = getTypeLabel(c.discount_type);

        // Amount display
        var amountDisplay = formatAmount(c.amount, c.discount_type);

        // Description
        var descHtml = c.description ? escHtml(c.description) : '<span class="brikpanel-cp-text-muted">&mdash;</span>';

        // Usage
        var usageHtml = c.usage_count;
        if (c.usage_limit) {
            usageHtml += ' / ' + c.usage_limit;
        } else {
            usageHtml += ' / &infin;'; // i18n-ignore: HTML entity for infinity symbol, language-neutral typographic separator
        }

        // Revenue
        var revenueHtml = c.revenue_formatted
            ? '<span class="brikpanel-cp-revenue-cell">' + c.revenue_formatted + '</span>'
            : '<span class="brikpanel-cp-text-muted">&mdash;</span>';

        // Expiry
        var expiryHtml = '';
        if (c.expiry_date) {
            var today = new Date();
            today.setHours(0, 0, 0, 0);
            var expiry = new Date(c.expiry_date + 'T23:59:59');
            if (expiry < today) {
                expiryHtml = '<span class="brikpanel-cp-expiry-expired">' + escHtml(c.expiry_date) + '</span>';
            } else {
                expiryHtml = escHtml(c.expiry_date);
            }
        } else {
            expiryHtml = '<span class="brikpanel-cp-text-muted">&mdash;</span>';
        }

        // Actions
        var actionsHtml = '';
        if (c.status === 'trash') {
            actionsHtml = '<div class="brikpanel-cp-actions">' +
                '<button type="button" class="brikpanel-cp-action-restore" title="' + escAttr(CP.i18n.restore) + '">' +
                '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="1,4 1,10 7,10"/><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"/></svg>' +
                '</button>' +
                '<button type="button" class="brikpanel-cp-action-delete-perm" title="' + escAttr(CP.i18n.delete_permanently) + '">' +
                '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#d72c0d" stroke-width="2"><polyline points="3,6 5,6 21,6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>' +
                '</button>' +
                '</div>';
        } else {
            actionsHtml = '<div class="brikpanel-cp-actions">' +
                '<button type="button" class="brikpanel-cp-action-edit" title="' + escAttr(CP.i18n.edit) + '">' +
                '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>' +
                '</button>' +
                '<button type="button" class="brikpanel-cp-action-duplicate" title="' + escAttr(CP.i18n.duplicate) + '">' +
                '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>' +
                '</button>' +
                '<button type="button" class="brikpanel-cp-action-delete" title="' + escAttr(CP.i18n.trash) + '">' +
                '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3,6 5,6 21,6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>' +
                '</button>' +
                '</div>';
        }

        // Cells contributed by third-party plugins (ASE etc.) via the
        // manage_{post_type}_posts_custom_column action. HTML already
        // sanitised server-side via wp_kses_post.
        var aseCellsHtml = '';
        var extraCols    = state.extraColumns || {};
        var extraIds     = Object.keys(extraCols);
        if (extraIds.length && c.extra_cells) {
            for (var ci = 0; ci < extraIds.length; ci++) {
                var aseColId = extraIds[ci];
                var aseHtml  = (typeof c.extra_cells[aseColId] !== 'undefined') ? c.extra_cells[aseColId] : '';
                aseCellsHtml += '<td class="brikpanel-cp-cell-ase column-' + escAttr(aseColId) + '" data-ase-col="' + escAttr(aseColId) + '">' + aseHtml + '</td>';
            }
        }

        // Row actions injected via post_row_actions filter.
        var aseActionsHtml = '';
        if (c.extra_actions && c.extra_actions.length) {
            var parts = [];
            for (var ai = 0; ai < c.extra_actions.length; ai++) {
                parts.push('<span class="brikpanel-cp-row-action brikpanel-cp-row-action-' + escAttr(c.extra_actions[ai].id || '') + '">' + (c.extra_actions[ai].html || '') + '</span>');
            }
            aseActionsHtml = '<div class="brikpanel-cp-row-actions">' + parts.join('') + '</div>';
        }

        return '<tr class="brikpanel-cp-row" data-id="' + c.id + '">' +
            '<td class="brikpanel-cp-cell-check"><input type="checkbox" class="brikpanel-cp-row-check brikpanel-cp-checkbox" value="' + c.id + '"' + checked + '></td>' +
            '<td class="brikpanel-cp-cell-code"><span class="brikpanel-cp-code-text">' + escHtml(c.code) + '</span>' + aseActionsHtml + '</td>' +
            '<td class="brikpanel-cp-cell-type"><span class="brikpanel-cp-type-badge">' + escHtml(typeLabel) + '</span></td>' +
            '<td class="brikpanel-cp-cell-amount"><span class="brikpanel-cp-editable brikpanel-cp-amount-cell" data-field="amount" data-value="' + escAttr(c.amount) + '">' + amountDisplay + '</span></td>' +
            '<td class="brikpanel-cp-cell-desc"><span class="brikpanel-cp-desc-cell">' + descHtml + '</span></td>' +
            '<td class="brikpanel-cp-cell-usage"><span class="brikpanel-cp-usage-cell">' + usageHtml + '</span></td>' +
            '<td class="brikpanel-cp-cell-revenue">' + revenueHtml + '</td>' +
            '<td class="brikpanel-cp-cell-expiry"><span class="brikpanel-cp-expiry-cell">' + expiryHtml + '</span></td>' +
            '<td class="brikpanel-cp-cell-status"><span class="brikpanel-cp-status-badge ' + statusClass + '" title="' + escAttr(CP.i18n.click_to_toggle) + '">' + escHtml(statusLabel) + '</span></td>' +
            aseCellsHtml +
            '<td class="brikpanel-cp-actions-cell">' + actionsHtml + '</td>' +
            '</tr>';
    }

    // =========================================================================
    // INLINE EDITING (Amount)
    // =========================================================================

    function startInlineEdit($el) {
        if ($el.find('.brikpanel-cp-inline-input').length) return;

        var field = $el.data('field');
        var value = $el.data('value') || '';

        var $input = $('<input type="text" class="brikpanel-cp-inline-input" value="' + escAttr(value) + '">');

        $el.data('original-html', $el.html());
        $el.html('').append($input);
        $input.focus().select();
    }

    function saveInlineEdit($input) {
        var $el = $input.closest('.brikpanel-cp-editable');
        if (!$el.length) return;

        var field = $el.data('field');
        var newValue = $input.val().trim();
        var oldValue = String($el.data('value') || '');
        var couponId = parseInt($el.closest('tr').data('id'));

        // No change
        if (newValue === oldValue) {
            cancelInlineEdit($input);
            return;
        }

        $el.addClass('saving');
        $input.prop('disabled', true);

        // Get the coupon to know what fields to send
        var coupon = getCouponById(couponId);
        if (!coupon) {
            cancelInlineEdit($input);
            return;
        }

        var data = {
            action: 'brikpanel_save_coupon',
            security: CP.nonce,
            coupon_id: couponId,
            code: coupon.code,
            description: coupon.description,
            discount_type: coupon.discount_type,
            amount: field === 'amount' ? newValue : coupon.amount,
            free_shipping: coupon.free_shipping,
            expiry_date: coupon.expiry_date,
            minimum_amount: coupon.minimum_amount,
            maximum_amount: coupon.maximum_amount,
            individual_use: coupon.individual_use,
            exclude_sale_items: coupon.exclude_sale_items,
            usage_limit: coupon.usage_limit,
            usage_limit_per_user: coupon.usage_limit_per_user
        };

        $.ajax({
            url: CP.ajax_url,
            type: 'POST',
            data: data,
            success: function (res) {
                $el.removeClass('saving');
                if (res.success) {
                    showToast(CP.i18n.saved, 'success');
                    fetchCoupons();
                } else {
                    cancelInlineEdit($input);
                    showToast(res.data.message || CP.i18n.error, 'error');
                }
            },
            error: function () {
                $el.removeClass('saving');
                cancelInlineEdit($input);
                showToast(CP.i18n.error, 'error');
            }
        });
    }

    function cancelInlineEdit($input) {
        var $el = $input.closest('.brikpanel-cp-editable');
        var original = $el.data('original-html');
        if (original !== undefined) {
            $el.html(original);
        }
    }

    function getCouponById(id) {
        for (var i = 0; i < state.coupons.length; i++) {
            if (state.coupons[i].id === id) return state.coupons[i];
        }
        return null;
    }

    // =========================================================================
    // DRAWER (Create / Edit)
    // =========================================================================

    function openDrawer(id) {
        var coupon = null;
        var isNew = (id === 0);

        if (!isNew) {
            coupon = getCouponById(id);
            if (!coupon) return;
        }

        // Reset form
        $('#bpc-coupon-id').val(isNew ? '' : coupon.id);
        $('#bpc-code').val(isNew ? '' : coupon.code);
        $('#bpc-description').val(isNew ? '' : coupon.description);
        $('#bpc-discount-type').val(isNew ? 'percent' : coupon.discount_type);
        $('#bpc-amount').val(isNew ? '' : coupon.amount);
        $('#bpc-free-shipping').prop('checked', isNew ? false : coupon.free_shipping === 'yes');
        $('#bpc-expiry').val(isNew ? '' : coupon.expiry_date);
        $('#bpc-min-spend').val(isNew ? '' : coupon.minimum_amount);
        $('#bpc-max-spend').val(isNew ? '' : coupon.maximum_amount);
        $('#bpc-individual-use').prop('checked', isNew ? false : coupon.individual_use === 'yes');
        $('#bpc-exclude-sale').prop('checked', isNew ? false : coupon.exclude_sale_items === 'yes');
        $('#bpc-usage-limit').val(isNew ? '' : coupon.usage_limit);
        $('#bpc-usage-limit-user').val(isNew ? '' : coupon.usage_limit_per_user);

        // Advanced restriction fields (rendered only when the matching setting is enabled).
        setTokens('products',           isNew ? [] : (coupon.product_labels || []));
        setTokens('exclude_products',   isNew ? [] : (coupon.excluded_product_labels || []));
        setTokens('categories',         isNew ? [] : (coupon.category_labels || []));
        setTokens('exclude_categories', isNew ? [] : (coupon.excluded_category_labels || []));

        $('#bpc-allowed-emails').val(isNew ? '' : (coupon.email_restrictions || ''));
        $('#bpc-limit-items').val(isNew ? '' : (coupon.limit_usage_to_x_items || ''));

        // Clear any leftover search input / suggestions in token pickers.
        $('.brikpanel-cp-token-input').val('');
        $('.brikpanel-cp-token-suggestions').attr('hidden', true).empty();

        // Usage count
        if (!isNew && coupon.usage_count > 0) {
            $('#bpc-usage-count-display').text(coupon.usage_count);
            $('#bpc-usage-count-field').show();
        } else {
            $('#bpc-usage-count-field').hide();
        }

        // Update drawer title
        $('#bpc-drawer-title').text(isNew ? CP.i18n.add_coupon : CP.i18n.edit_coupon);

        // Update save button text
        $('#bpc-drawer-save').text(isNew ? CP.i18n.create : CP.i18n.save_changes);

        // Update amount prefix
        updateAmountPrefix();

        // Open
        $('#bpc-drawer, #bpc-drawer-overlay').addClass('open');
        $('body').addClass('brikpanel-cp-drawer-open');

        // Focus
        setTimeout(function () { $('#bpc-code').focus(); }, 200);
    }

    function closeDrawer() {
        $('#bpc-drawer, #bpc-drawer-overlay').removeClass('open');
        $('body').removeClass('brikpanel-cp-drawer-open');
    }

    function saveDrawer() {
        var $btn = $('#bpc-drawer-save');
        var couponId = parseInt($('#bpc-coupon-id').val()) || 0;
        var code = $('#bpc-code').val().trim();

        if (!code) {
            showToast(CP.i18n.code_required, 'error');
            $('#bpc-code').focus();
            return;
        }

        $btn.prop('disabled', true).text(CP.i18n.saving);

        var data = {
            action: 'brikpanel_save_coupon',
            security: CP.nonce,
            coupon_id: couponId,
            code: code,
            description: $('#bpc-description').val(),
            discount_type: $('#bpc-discount-type').val(),
            amount: $('#bpc-amount').val(),
            free_shipping: $('#bpc-free-shipping').is(':checked') ? 'yes' : 'no',
            expiry_date: $('#bpc-expiry').val(),
            minimum_amount: $('#bpc-min-spend').val(),
            maximum_amount: $('#bpc-max-spend').val(),
            individual_use: $('#bpc-individual-use').is(':checked') ? 'yes' : 'no',
            exclude_sale_items: $('#bpc-exclude-sale').is(':checked') ? 'yes' : 'no',
            usage_limit: $('#bpc-usage-limit').val(),
            usage_limit_per_user: $('#bpc-usage-limit-user').val()
        };

        // Advanced restriction fields - only sent if the corresponding setting is on
        // (the server also gates this, so a forged payload still cannot write).
        // Arrays are joined into comma-separated strings so that an "empty
        // selection" still transmits the key — jQuery drops empty array
        // values from the POST body, which would otherwise prevent the user
        // from clearing out a previously-selected list.
        if (isFieldEnabled('products')) {
            data.product_ids = tokenValues('products').join(',');
        }
        if (isFieldEnabled('exclude_products')) {
            data.excluded_product_ids = tokenValues('exclude_products').join(',');
        }
        if (isFieldEnabled('categories')) {
            data.category_ids = tokenValues('categories').join(',');
        }
        if (isFieldEnabled('exclude_categories')) {
            data.excluded_category_ids = tokenValues('exclude_categories').join(',');
        }
        if (isFieldEnabled('emails')) {
            data.email_restrictions = $('#bpc-allowed-emails').val();
        }
        if (isFieldEnabled('limit_items')) {
            data.limit_usage_to_x_items = $('#bpc-limit-items').val();
        }

        $.ajax({
            url: CP.ajax_url,
            type: 'POST',
            data: data,
            traditional: true,
            success: function (res) {
                $btn.prop('disabled', false).text(couponId > 0 ? CP.i18n.save_changes : CP.i18n.create);
                if (res.success) {
                    showToast(res.data.message, 'success');
                    closeDrawer();
                    fetchCoupons();
                } else {
                    showToast(res.data.message || CP.i18n.error, 'error');
                }
            },
            error: function () {
                $btn.prop('disabled', false).text(couponId > 0 ? CP.i18n.save_changes : CP.i18n.create);
                showToast(CP.i18n.error, 'error');
            }
        });
    }

    // =========================================================================
    // TOGGLE STATUS
    // =========================================================================

    function toggleStatus(id) {
        var $badge = $('#bpc-table-body tr[data-id="' + id + '"] .brikpanel-cp-status-badge'); // i18n-ignore: selector fragment
        $badge.addClass('brikpanel-cp-status-saving');

        $.ajax({
            url: CP.ajax_url,
            type: 'POST',
            data: {
                action: 'brikpanel_toggle_coupon_status',
                security: CP.nonce,
                coupon_id: id
            },
            success: function (res) {
                $badge.removeClass('brikpanel-cp-status-saving');
                if (res.success) {
                    var newClass = res.data.status === 'publish' ? 'published' : 'draft';
                    var newLabel = res.data.status === 'publish' ? CP.i18n.published : CP.i18n.draft;
                    $badge.removeClass('published draft trashed').addClass(newClass).text(newLabel);

                    // Update state
                    for (var i = 0; i < state.coupons.length; i++) {
                        if (state.coupons[i].id === id) {
                            state.coupons[i].status = res.data.status;
                            break;
                        }
                    }

                    showToast(res.data.message, 'success');
                } else {
                    showToast(res.data.message || CP.i18n.error, 'error');
                }
            },
            error: function () {
                $badge.removeClass('brikpanel-cp-status-saving');
                showToast(CP.i18n.error, 'error');
            }
        });
    }

    // =========================================================================
    // DELETE / RESTORE / DUPLICATE
    // =========================================================================

    function deleteCoupon(id, permanent) {
        var $row = $('#bpc-table-body tr[data-id="' + id + '"]');
        $row.addClass('brikpanel-cp-row-deleting');

        $.ajax({
            url: CP.ajax_url,
            type: 'POST',
            data: {
                action: 'brikpanel_delete_coupon',
                security: CP.nonce,
                coupon_id: id,
                permanent: permanent ? 'yes' : 'no'
            },
            success: function (res) {
                if (res.success) {
                    $row.fadeOut(300, function () {
                        $(this).remove();
                        showToast(res.data.message, 'success');
                        fetchCoupons();
                    });
                } else {
                    $row.removeClass('brikpanel-cp-row-deleting');
                    showToast(res.data.message || CP.i18n.error, 'error');
                }
            },
            error: function () {
                $row.removeClass('brikpanel-cp-row-deleting');
                showToast(CP.i18n.error, 'error');
            }
        });
    }

    function restoreCoupon(id) {
        $.ajax({
            url: CP.ajax_url,
            type: 'POST',
            data: {
                action: 'brikpanel_toggle_coupon_status',
                security: CP.nonce,
                coupon_id: id
            },
            success: function (res) {
                if (res.success) {
                    showToast(CP.i18n.restored, 'success');
                    fetchCoupons();
                } else {
                    showToast(res.data.message || CP.i18n.error, 'error');
                }
            },
            error: function () {
                showToast(CP.i18n.error, 'error');
            }
        });
    }

    function duplicateCoupon(id) {
        showToast(CP.i18n.duplicating, 'info');

        $.ajax({
            url: CP.ajax_url,
            type: 'POST',
            data: {
                action: 'brikpanel_duplicate_coupon',
                security: CP.nonce,
                coupon_id: id
            },
            success: function (res) {
                if (res.success) {
                    showToast(res.data.message || CP.i18n.duplicated, 'success');
                    fetchCoupons();
                } else {
                    showToast(res.data.message || CP.i18n.error, 'error');
                }
            },
            error: function () {
                showToast(CP.i18n.error, 'error');
            }
        });
    }

    // =========================================================================
    // BULK ACTIONS
    // =========================================================================

    function bulkStatusChange(newStatus) {
        if (!state.selected.length) return;

        var confirmMsg = CP.i18n.confirm_bulk.replace('%d', state.selected.length);
        if (!confirm(confirmMsg)) return;

        var pending = state.selected.length;
        var completed = 0;

        state.selected.forEach(function (id) {
            // We set status by using wp_update_post approach via toggle
            // For bulk, we need a different approach - update each post status
            $.ajax({
                url: CP.ajax_url,
                type: 'POST',
                data: {
                    action: 'brikpanel_toggle_coupon_status',
                    security: CP.nonce,
                    coupon_id: id
                },
                complete: function () {
                    completed++;
                    if (completed >= pending) {
                        state.selected = [];
                        updateBulkBar();
                        fetchCoupons();
                        showToast(CP.i18n.bulk_updated, 'success');
                    }
                }
            });
        });
    }

    function bulkTrash() {
        if (!state.selected.length) return;

        var confirmMsg = CP.i18n.confirm_bulk_trash.replace('%d', state.selected.length);
        if (!confirm(confirmMsg)) return;

        var pending = state.selected.length;
        var completed = 0;

        state.selected.forEach(function (id) {
            $.ajax({
                url: CP.ajax_url,
                type: 'POST',
                data: {
                    action: 'brikpanel_delete_coupon',
                    security: CP.nonce,
                    coupon_id: id,
                    permanent: 'no'
                },
                complete: function () {
                    completed++;
                    if (completed >= pending) {
                        state.selected = [];
                        updateBulkBar();
                        fetchCoupons();
                        showToast(CP.i18n.bulk_trashed, 'success');
                    }
                }
            });
        });
    }

    function updateBulkBar() {
        var count = state.selected.length;
        $('#bpc-selected-count').text(count);
        if (count > 0) {
            $('#bpc-bulk-bar').slideDown(200);
        } else {
            $('#bpc-bulk-bar').slideUp(200);
        }
    }

    // =========================================================================
    // PAGINATION
    // =========================================================================

    function renderPagination() {
        var $pag = $('#bpc-pagination');
        if (state.pages <= 1) {
            var showingText = CP.i18n.showing.replace('%1$d', state.total).replace('%2$d', state.total);
            $pag.html('<span class="brikpanel-cp-showing">' + showingText + '</span>');
            return;
        }

        var start = (state.page - 1) * state.per_page + 1;
        var end = Math.min(state.page * state.per_page, state.total);
        var html = '<span class="brikpanel-cp-showing">' +
            CP.i18n.showing_range.replace('%1$d', start).replace('%2$d', end).replace('%3$d', state.total) +
            '</span>';

        html += '<div class="brikpanel-cp-page-btns">';

        // Prev
        html += '<button class="brikpanel-cp-page-btn' + (state.page <= 1 ? ' disabled' : '') + '" data-page="prev">' +
            '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15,18 9,12 15,6"/></svg>' +
            '</button>';

        // Page numbers
        var pages = getPaginationNumbers(state.page, state.pages);
        for (var i = 0; i < pages.length; i++) {
            if (pages[i] === '...') {
                html += '<span class="brikpanel-cp-page-dots">...</span>';
            } else {
                html += '<button class="brikpanel-cp-page-btn' + (pages[i] === state.page ? ' active' : '') + '" data-page="' + pages[i] + '">' + pages[i] + '</button>';
            }
        }

        // Next
        html += '<button class="brikpanel-cp-page-btn' + (state.page >= state.pages ? ' disabled' : '') + '" data-page="next">' +
            '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9,18 15,12 9,6"/></svg>' +
            '</button>';

        html += '</div>';
        $pag.html(html);
    }

    function getPaginationNumbers(current, total) {
        if (total <= 7) {
            var arr = [];
            for (var i = 1; i <= total; i++) arr.push(i);
            return arr;
        }

        var pages = [1];
        if (current > 3) pages.push('...');
        for (var j = Math.max(2, current - 1); j <= Math.min(total - 1, current + 1); j++) {
            pages.push(j);
        }
        if (current < total - 2) pages.push('...');
        pages.push(total);
        return pages;
    }

    // =========================================================================
    // UPDATE COUNTS
    // =========================================================================

    function updateCounts(counts) {
        if (!counts) return;
        $('[data-count="all"]').text(counts.all);
        $('[data-count="publish"]').text(counts.publish);
        $('[data-count="draft"]').text(counts.draft);
        $('#bpc-total-count').text(counts.all);

        // Show/hide trash tab
        if (counts.trash > 0) {
            if (!$('[data-count="trash"]').length) {
                $('.brikpanel-cp-tabs').append(
                    '<button class="brikpanel-cp-tab' + (state.status === 'trash' ? ' active' : '') + '" data-status="trash">' +
                    escHtml(CP.i18n.trashed) + ' <span class="brikpanel-cp-tab-count" data-count="trash">' + counts.trash + '</span></button>'
                );
            } else {
                $('[data-count="trash"]').text(counts.trash);
            }
        } else {
            // Remove trash tab when count is 0
            var $trashTab = $('.brikpanel-cp-tab[data-status="trash"]');
            if ($trashTab.length) {
                // If trash tab was active, switch to 'any'
                if ($trashTab.hasClass('active')) {
                    state.status = 'any';
                    $trashTab.remove();
                    $('.brikpanel-cp-tab[data-status="any"]').addClass('active');
                    fetchCoupons();
                } else {
                    $trashTab.remove();
                }
            }
        }
    }

    // =========================================================================
    // TOAST NOTIFICATIONS
    // =========================================================================

    function showToast(message, type) {
        type = type || 'success';
        var $container = $('#bpc-toast-container');
        var $toast = $('<div class="brikpanel-cp-toast ' + type + '">' +
            '<span class="brikpanel-cp-toast-text">' + escHtml(message) + '</span>' +
            '<button class="brikpanel-cp-toast-close">&times;</button>' +
            '</div>');

        $container.append($toast);

        // Animate in
        setTimeout(function () { $toast.addClass('show'); }, 10);

        // Close button
        $toast.find('.brikpanel-cp-toast-close').on('click', function () {
            $toast.removeClass('show');
            setTimeout(function () { $toast.remove(); }, 300);
        });

        // Auto-dismiss
        setTimeout(function () {
            $toast.removeClass('show');
            setTimeout(function () { $toast.remove(); }, 300);
        }, 3500);
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    function getTypeLabel(type) {
        switch (type) {
            case 'percent': return CP.i18n.type_percent;
            case 'fixed_cart': return CP.i18n.type_fixed_cart;
            case 'fixed_product': return CP.i18n.type_fixed_product;
            default: return type;
        }
    }

    function formatAmount(amount, type) {
        if (!amount || parseFloat(amount) === 0) return '<span class="brikpanel-cp-text-muted">&mdash;</span>';
        if (type === 'percent') {
            return escHtml(amount) + '%';
        }
        return escHtml(CP.currency) + escHtml(amount);
    }

    function updateAmountPrefix() {
        var type = $('#bpc-discount-type').val();
        if (type === 'percent') {
            $('#bpc-amount-prefix').text('%');
        } else {
            $('#bpc-amount-prefix').text(CP.currency);
        }
    }

    function generateCouponCode() {
        var chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        var code = '';
        for (var i = 0; i < 8; i++) {
            code += chars.charAt(Math.floor(Math.random() * chars.length));
        }
        return code;
    }

    function escHtml(str) {
        if (str === null || str === undefined) return '';
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(String(str)));
        return div.innerHTML;
    }

    function escAttr(str) {
        if (str === null || str === undefined) return '';
        return String(str).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/'/g, '&#39;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    // =========================================================================
    // BOOT
    // =========================================================================

    $(document).ready(function () {
        if (!$('#brikpanel-coupons-list').length) return;
        bindEvents();
        fetchCoupons();
    });

})(jQuery);
