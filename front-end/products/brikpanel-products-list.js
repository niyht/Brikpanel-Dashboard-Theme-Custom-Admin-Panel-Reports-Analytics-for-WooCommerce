/**
 * BrikPanel – AJAX Products List
 * @package BrikPanel
 * @since 1.7.0
 */
(function ($) {
    'use strict';

    var PL = window.brikpanelPL || {};

    var state = {
        page: 1,
        per_page: PL.per_page || 20,
        search: '',
        status: 'any',
        category: '',
        stock_filter: '',
        sort: 'date-desc',
        selected: [],
        products: [],
        loading: false,
        total: 0,
        pages: 0
    };

    var searchTimer = null;

    // =========================================================================
    // INIT
    // =========================================================================

    function bindEvents() {
        // Search
        $('#bpl-search').on('input', function () {
            clearTimeout(searchTimer);
            var val = $(this).val();
            searchTimer = setTimeout(function () {
                state.search = val;
                state.page = 1;
                fetchProducts();
            }, 350);
        });

        // Status tabs
        $(document).on('click', '.brikpanel-pl-tab', function () {
            $('.brikpanel-pl-tab').removeClass('active');
            $(this).addClass('active');
            state.status = $(this).data('status');
            state.page = 1;
            fetchProducts();
        });

        // Filters
        $('#bpl-cat-filter').on('change', function () {
            state.category = $(this).val();
            state.page = 1;
            fetchProducts();
        });

        $('#bpl-stock-filter').on('change', function () {
            state.stock_filter = $(this).val();
            state.page = 1;
            fetchProducts();
        });

        $('#bpl-sort').on('change', function () {
            state.sort = $(this).val();
            state.page = 1;
            fetchProducts();
        });

        // Check all
        $('#bpl-check-all').on('change', function () {
            var checked = this.checked;
            $('.brikpanel-pl-row-check').prop('checked', checked);
            if (checked) {
                state.selected = state.products.map(function (p) { return p.id; });
            } else {
                state.selected = [];
            }
            updateBulkBar();
        });

        // Row checkbox
        $(document).on('change', '.brikpanel-pl-row-check', function () {
            var id = parseInt($(this).val());
            if (this.checked) {
                if (state.selected.indexOf(id) === -1) state.selected.push(id);
            } else {
                state.selected = state.selected.filter(function (s) { return s !== id; });
            }
            $('#bpl-check-all').prop('checked', state.selected.length === state.products.length && state.products.length > 0);
            updateBulkBar();
        });

        // Bulk actions
        $('#bpl-select-all-btn').on('click', function () {
            state.selected = state.products.map(function (p) { return p.id; });
            $('.brikpanel-pl-row-check').prop('checked', true);
            $('#bpl-check-all').prop('checked', true);
            updateBulkBar();
        });

        $('#bpl-deselect-all-btn').on('click', function () {
            state.selected = [];
            $('.brikpanel-pl-row-check').prop('checked', false);
            $('#bpl-check-all').prop('checked', false);
            updateBulkBar();
        });

        $('#bpl-bulk-publish').on('click', function () { bulkAction('publish'); });
        $('#bpl-bulk-draft').on('click', function () { bulkAction('draft'); });
        $('#bpl-bulk-trash').on('click', function () { bulkAction('trash'); });

        // Pagination
        $(document).on('click', '.brikpanel-pl-page-btn:not(.disabled):not(.active)', function () {
            var p = $(this).data('page');
            if (p === 'prev') {
                state.page = Math.max(1, state.page - 1);
            } else if (p === 'next') {
                state.page = Math.min(state.pages, state.page + 1);
            } else {
                state.page = parseInt(p);
            }
            fetchProducts();
            // Scroll to top of table
            $('html, body').animate({ scrollTop: $('#bpl-table').offset().top - 80 }, 200);
        });

        // Inline edit – click on editable cell
        $(document).on('click', '.brikpanel-pl-editable', function (e) {
            e.stopPropagation();
            startInlineEdit($(this));
        });

        // Inline edit – save on Enter, cancel on Escape
        $(document).on('keydown', '.brikpanel-pl-inline-input', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                saveInlineEdit($(this));
            } else if (e.key === 'Escape') {
                cancelInlineEdit($(this));
            }
        });

        // Inline edit – save on blur
        $(document).on('blur', '.brikpanel-pl-inline-input', function () {
            var $input = $(this);
            // Small delay to allow click events to fire first
            setTimeout(function () {
                if ($input.closest('.brikpanel-pl-editable').length) {
                    saveInlineEdit($input);
                }
            }, 150);
        });

        // Quick edit drawer
        $(document).on('click', '.brikpanel-pl-action-edit', function (e) {
            e.preventDefault();
            e.stopPropagation();
            var id = parseInt($(this).closest('tr').data('id'));
            openDrawer(id);
        });

        $('#bpl-drawer-close, #bpl-qe-cancel, #bpl-drawer-overlay').on('click', closeDrawer);
        $('#bpl-qe-save').on('click', saveDrawer);

        // Delete product
        $(document).on('click', '.brikpanel-pl-action-delete', function (e) {
            e.preventDefault();
            e.stopPropagation();
            var id = parseInt($(this).closest('tr').data('id'));
            var name = $(this).closest('tr').find('.brikpanel-pl-product-name-text').text();
            if (confirm(PL.i18n.confirm_delete.replace('%s', name))) {
                deleteProduct(id);
            }
        });

        // Duplicate product
        $(document).on('click', '.brikpanel-pl-action-duplicate', function (e) {
            e.preventDefault();
            e.stopPropagation();
            var id = parseInt($(this).closest('tr').data('id'));
            duplicateProduct(id);
        });

        // Toggle status
        $(document).on('click', '.brikpanel-pl-status-badge', function (e) {
            e.stopPropagation();
            var id = parseInt($(this).closest('tr').data('id'));
            toggleStatus(id);
        });

        // Click row to go to edit page
        $(document).on('click', '.brikpanel-pl-row', function (e) {
            if ($(e.target).closest('input, button, a, .brikpanel-pl-editable, .brikpanel-pl-actions-cell, .brikpanel-pl-status-badge, .brikpanel-pl-stock-badge').length) return;
            var url = $(this).data('edit-url');
            if (url) window.location.href = url;
        });

        // Keyboard shortcut: Escape to close drawer
        $(document).on('keydown', function (e) {
            if (e.key === 'Escape' && $('#bpl-drawer').hasClass('open')) {
                closeDrawer();
            }
        });
    }

    // =========================================================================
    // FETCH PRODUCTS
    // =========================================================================

    function fetchProducts() {
        if (state.loading) return;
        state.loading = true;

        var $body = $('#bpl-table-body');
        $body.html('<tr class="brikpanel-pl-loading-row"><td colspan="9"><div class="brikpanel-pl-spinner"></div></td></tr>');

        $.ajax({
            url: PL.ajax_url,
            type: 'POST',
            data: {
                action: 'brikpanel_fetch_products',
                security: PL.nonce,
                page: state.page,
                per_page: state.per_page,
                search: state.search,
                status: state.status,
                category: state.category,
                stock_filter: state.stock_filter,
                sort: state.sort
            },
            success: function (res) {
                state.loading = false;
                if (!res.success) {
                    $body.html('<tr><td colspan="9" class="brikpanel-pl-empty">' + escHtml(res.data.message || PL.i18n.error) + '</td></tr>');
                    return;
                }

                state.products = res.data.products;
                state.total = res.data.total;
                state.pages = res.data.pages;

                // Update counts
                updateCounts(res.data.counts);
                renderProducts();
                renderPagination();
                updateBulkBar();

                // Reset check-all
                $('#bpl-check-all').prop('checked', false);
            },
            error: function () {
                state.loading = false;
                $body.html('<tr><td colspan="9" class="brikpanel-pl-empty">' + escHtml(PL.i18n.error) + '</td></tr>');
            }
        });
    }

    // =========================================================================
    // RENDER PRODUCTS
    // =========================================================================

    function renderProducts() {
        var $body = $('#bpl-table-body');

        if (!state.products.length) {
            $body.html('<tr><td colspan="9" class="brikpanel-pl-empty">' +
                '<div class="brikpanel-pl-empty-state">' +
                '<svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="#8a8a8a" stroke-width="1.5"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27,6.96 12,12.01 20.73,6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>' +
                '<p>' + escHtml(PL.i18n.no_products) + '</p>' +
                '</div></td></tr>');
            return;
        }

        var html = '';
        for (var i = 0; i < state.products.length; i++) {
            html += renderProductRow(state.products[i]);
        }

        $body.html(html);

        // Restore checked states
        state.selected.forEach(function (id) {
            $body.find('.brikpanel-pl-row-check[value="' + id + '"]').prop('checked', true);
        });
    }

    function renderProductRow(p) {
        var checked = state.selected.indexOf(p.id) > -1 ? ' checked' : '';
        var statusClass = p.status === 'publish' ? 'published' : (p.status === 'trash' ? 'trashed' : 'draft');
        var statusLabel = p.status === 'publish' ? PL.i18n.published : (p.status === 'trash' ? PL.i18n.trashed : PL.i18n.draft);

        var stockHtml = '';
        if (p.stock !== null && p.stock !== '') {
            var stockClass = '';
            if (parseInt(p.stock) === 0) {
                stockClass = ' out';
            } else if (parseInt(p.stock) <= 5) {
                stockClass = ' low';
            }
            stockHtml = '<span class="brikpanel-pl-stock-badge' + stockClass + ' brikpanel-pl-editable" data-field="stock" data-value="' + escAttr(p.stock) + '">' + escHtml(p.stock) + '</span>';
        } else {
            stockHtml = '<span class="brikpanel-pl-stock-na">—</span>';
        }

        var priceDisplay = p.price_html || '—';
        var isVariable = p.type === 'variable';

        var priceEditable = isVariable ?
            '<span class="brikpanel-pl-editable brikpanel-pl-price-cell" data-field="price" data-value="">' + priceDisplay + '</span>' :
            '<span class="brikpanel-pl-editable brikpanel-pl-price-cell" data-field="price" data-value="' + escAttr(p.regular_price || '') + '">' + priceDisplay + '</span>';

        var catText = p.categories.length ? escHtml(p.categories.join(', ')) : '<span class="brikpanel-pl-text-muted">—</span>';

        var typeLabel = '';
        if (isVariable) {
            typeLabel = '<span class="brikpanel-pl-type-badge">' + escHtml(PL.i18n.variable) + '</span>';
        }

        var trashActions = '';
        if (p.status === 'trash') {
            trashActions = '<button type="button" class="brikpanel-pl-action-restore" title="' + escAttr(PL.i18n.restore) + '">' +
                '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="1,4 1,10 7,10"/><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"/></svg>' +
                '</button>' +
                '<button type="button" class="brikpanel-pl-action-delete-perm" title="' + escAttr(PL.i18n.delete_permanently) + '">' +
                '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#d72c0d" stroke-width="2"><polyline points="3,6 5,6 21,6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>' +
                '</button>';
        }

        return '<tr class="brikpanel-pl-row" data-id="' + p.id + '" data-edit-url="' + escAttr(p.edit_url) + '">' +
            '<td class="brikpanel-pl-cell-check"><input type="checkbox" class="brikpanel-pl-row-check brikpanel-pl-checkbox" value="' + p.id + '"' + checked + '></td>' +
            '<td class="brikpanel-pl-cell-image"><img src="' + escAttr(p.image) + '" alt="" class="brikpanel-pl-thumb" loading="lazy"></td>' +
            '<td class="brikpanel-pl-cell-name"><span class="brikpanel-pl-product-name-text">' + escHtml(p.name) + '</span>' + typeLabel + '</td>' +
            '<td class="brikpanel-pl-cell-sku"><span class="brikpanel-pl-editable brikpanel-pl-sku-cell" data-field="sku" data-value="' + escAttr(p.sku || '') + '">' + (p.sku ? escHtml(p.sku) : '<span class="brikpanel-pl-text-muted">—</span>') + '</span></td>' +
            '<td class="brikpanel-pl-cell-price">' + priceEditable + '</td>' +
            '<td class="brikpanel-pl-cell-stock">' + stockHtml + '</td>' +
            '<td class="brikpanel-pl-cell-cat">' + catText + '</td>' +
            '<td class="brikpanel-pl-cell-status"><span class="brikpanel-pl-status-badge ' + statusClass + '" title="' + escAttr(PL.i18n.click_to_toggle) + '">' + escHtml(statusLabel) + '</span></td>' +
            '<td class="brikpanel-pl-actions-cell">' +
                (p.status !== 'trash' ?
                '<div class="brikpanel-pl-actions">' +
                    '<a href="' + escAttr(p.view_url) + '" target="_blank" class="brikpanel-pl-action-view" title="' + escAttr(PL.i18n.view || 'View') + '">' +
                    '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>' +
                    '</a>' +
                    '<button type="button" class="brikpanel-pl-action-edit" title="' + escAttr(PL.i18n.quick_edit) + '">' +
                    '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>' +
                    '</button>' +
                    '<button type="button" class="brikpanel-pl-action-duplicate" title="' + escAttr(PL.i18n.duplicate) + '">' +
                    '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>' +
                    '</button>' +
                    '<button type="button" class="brikpanel-pl-action-delete" title="' + escAttr(PL.i18n.trash) + '">' +
                    '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3,6 5,6 21,6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>' +
                    '</button>' +
                '</div>'
                : '<div class="brikpanel-pl-actions">' + trashActions + '</div>') +
            '</td>' +
            '</tr>';
    }

    // =========================================================================
    // INLINE EDITING
    // =========================================================================

    function startInlineEdit($el) {
        if ($el.find('.brikpanel-pl-inline-input').length) return;
        if ($('.brikpanel-pl-var-popup').length) closeVariationPopup();

        var field = $el.data('field');
        var value = $el.data('value') || '';
        var $row = $el.closest('tr');
        var productId = parseInt($row.data('id'));

        // Check if variable product → open variation popup
        var product = getProductById(productId);
        if (product && product.type === 'variable' && (field === 'stock' || field === 'price')) {
            openVariationPopup($el, productId, field);
            return;
        }

        var inputType = field === 'stock' ? 'number' : 'text';
        var $input = $('<input type="' + inputType + '" class="brikpanel-pl-inline-input" value="' + escAttr(value) + '">');

        if (field === 'stock') {
            $input.attr('min', '0');
        }

        $el.data('original-html', $el.html());
        $el.html('').append($input);
        $input.focus().select();
    }

    function getProductById(id) {
        for (var i = 0; i < state.products.length; i++) {
            if (state.products[i].id === id) return state.products[i];
        }
        return null;
    }

    function saveInlineEdit($input) {
        var $el = $input.closest('.brikpanel-pl-editable');
        if (!$el.length) return;

        var field = $el.data('field');
        var newValue = $input.val().trim();
        var oldValue = String($el.data('value') || '');
        var productId = parseInt($el.closest('tr').data('id'));

        // No change
        if (newValue === oldValue) {
            cancelInlineEdit($input);
            return;
        }

        $el.addClass('saving');
        $input.prop('disabled', true);

        var data = {
            action: 'brikpanel_quick_edit_product',
            security: PL.nonce,
            product_id: productId
        };

        if (field === 'price') {
            data.regular_price = newValue;
        } else if (field === 'stock') {
            data.stock = newValue;
        } else if (field === 'sku') {
            data.sku = newValue;
        }

        $.ajax({
            url: PL.ajax_url,
            type: 'POST',
            data: data,
            success: function (res) {
                $el.removeClass('saving');
                if (res.success) {
                    // Update the row with new data
                    updateProductInState(res.data.product);
                    var $row = $el.closest('tr');
                    refreshRow($row, res.data.product);
                    showToast(PL.i18n.saved, 'success');
                } else {
                    cancelInlineEdit($input);
                    showToast(res.data.message || PL.i18n.error, 'error');
                }
            },
            error: function () {
                $el.removeClass('saving');
                cancelInlineEdit($input);
                showToast(PL.i18n.error, 'error');
            }
        });
    }

    function cancelInlineEdit($input) {
        var $el = $input.closest('.brikpanel-pl-editable');
        var original = $el.data('original-html');
        if (original !== undefined) {
            $el.html(original);
        }
    }

    function refreshRow($row, product) {
        var newRow = $(renderProductRow(product));
        $row.replaceWith(newRow);
    }

    function updateProductInState(product) {
        for (var i = 0; i < state.products.length; i++) {
            if (state.products[i].id === product.id) {
                state.products[i] = product;
                break;
            }
        }
    }

    // =========================================================================
    // QUICK EDIT DRAWER
    // =========================================================================

    function openDrawer(id) {
        var product = null;
        for (var i = 0; i < state.products.length; i++) {
            if (state.products[i].id === id) {
                product = state.products[i];
                break;
            }
        }
        if (!product) return;

        $('#bpl-qe-id').val(product.id);
        $('#bpl-qe-name').val(product.name);
        $('#bpl-qe-sku').val(product.sku || '');
        $('#bpl-qe-status').val(product.status);
        $('#bpl-qe-cat').val(product.category_ids.length ? product.category_ids[0] : '');

        // Variable vs Simple product handling
        if (product.type === 'variable') {
            $('#bpl-qe-simple-fields').hide();
            $('#bpl-qe-variations').show();
            loadDrawerVariations(product.id);
        } else {
            $('#bpl-qe-simple-fields').show();
            $('#bpl-qe-variations').hide().empty();
            $('#bpl-qe-price').val(product.regular_price || '');
            $('#bpl-qe-sale-price').val(product.sale_price || '');
            $('#bpl-qe-stock').val(product.stock !== null ? product.stock : '');
        }

        $('#bpl-drawer, #bpl-drawer-overlay').addClass('open');
        $('body').addClass('brikpanel-pl-drawer-open');

        // Focus first field
        setTimeout(function () { $('#bpl-qe-name').focus().select(); }, 200);
    }

    function closeDrawer() {
        $('#bpl-drawer, #bpl-drawer-overlay').removeClass('open');
        $('body').removeClass('brikpanel-pl-drawer-open');
    }

    function saveDrawer() {
        var $btn = $('#bpl-qe-save');
        var id = parseInt($('#bpl-qe-id').val());
        if (!id) return;

        var product = getProductById(id);
        var isVariable = product && product.type === 'variable';

        $btn.prop('disabled', true).text(PL.i18n.saving);

        // Save main product fields (name, sku, status, category)
        var mainData = {
            action: 'brikpanel_quick_edit_product',
            security: PL.nonce,
            product_id: id,
            name: $('#bpl-qe-name').val(),
            sku: $('#bpl-qe-sku').val(),
            status: $('#bpl-qe-status').val(),
            category_id: $('#bpl-qe-cat').val()
        };

        if (!isVariable) {
            mainData.regular_price = $('#bpl-qe-price').val();
            mainData.sale_price = $('#bpl-qe-sale-price').val();
            mainData.stock = $('#bpl-qe-stock').val();
        }

        // Save main product
        $.ajax({
            url: PL.ajax_url,
            type: 'POST',
            data: mainData,
            success: function (res) {
                if (!res.success) {
                    $btn.prop('disabled', false).text(PL.i18n.save_changes);
                    showToast(res.data.message || PL.i18n.error, 'error');
                    return;
                }

                if (!isVariable) {
                    $btn.prop('disabled', false).text(PL.i18n.save_changes);
                    updateProductInState(res.data.product);
                    var $row = $('#bpl-table-body tr[data-id="' + id + '"]');
                    refreshRow($row, res.data.product);
                    closeDrawer();
                    showToast(res.data.message, 'success');
                    fetchProducts();
                    return;
                }

                // Save all variations
                var $varItems = $('#bpl-qe-variations .brikpanel-pl-qe-var-item');
                var pending = $varItems.length;
                if (pending === 0) {
                    $btn.prop('disabled', false).text(PL.i18n.save_changes);
                    closeDrawer();
                    fetchProducts();
                    return;
                }

                $varItems.each(function () {
                    var $item = $(this);
                    var varId = parseInt($item.data('var-id'));
                    var varData = { action: 'brikpanel_save_variation', security: PL.nonce, variation_id: varId };

                    $item.find('.brikpanel-pl-qe-var-input').each(function () {
                        varData[$(this).data('field')] = $(this).val();
                    });

                    $.ajax({
                        url: PL.ajax_url,
                        type: 'POST',
                        data: varData,
                        complete: function () {
                            pending--;
                            if (pending <= 0) {
                                $btn.prop('disabled', false).text(PL.i18n.save_changes);
                                closeDrawer();
                                showToast(PL.i18n.saved, 'success');
                                fetchProducts();
                            }
                        }
                    });
                });
            },
            error: function () {
                $btn.prop('disabled', false).text(PL.i18n.save_changes);
                showToast(PL.i18n.error, 'error');
            }
        });
    }

    // =========================================================================
    // BULK ACTIONS
    // =========================================================================

    function bulkAction(action) {
        if (!state.selected.length) return;

        var confirmMsg = PL.i18n.confirm_bulk.replace('%d', state.selected.length);
        if (action === 'trash') {
            confirmMsg = PL.i18n.confirm_bulk_trash.replace('%d', state.selected.length);
        }
        if (!confirm(confirmMsg)) return;

        $.ajax({
            url: PL.ajax_url,
            type: 'POST',
            data: {
                action: 'brikpanel_bulk_action_products',
                security: PL.nonce,
                bulk_action: action,
                product_ids: state.selected
            },
            success: function (res) {
                if (res.success) {
                    showToast(res.data.message, 'success');
                    state.selected = [];
                    updateBulkBar();
                    fetchProducts();
                } else {
                    showToast(res.data.message || PL.i18n.error, 'error');
                }
            },
            error: function () {
                showToast(PL.i18n.error, 'error');
            }
        });
    }

    function updateBulkBar() {
        var count = state.selected.length;
        $('#bpl-selected-count').text(count);
        if (count > 0) {
            $('#bpl-bulk-bar').slideDown(200);
        } else {
            $('#bpl-bulk-bar').slideUp(200);
        }
    }

    // =========================================================================
    // DELETE / DUPLICATE / TOGGLE
    // =========================================================================

    function deleteProduct(id) {
        var $row = $('#bpl-table-body tr[data-id="' + id + '"]');
        $row.addClass('brikpanel-pl-row-deleting');

        $.ajax({
            url: PL.ajax_url,
            type: 'POST',
            data: {
                action: 'brikpanel_delete_product',
                security: PL.nonce,
                product_id: id
            },
            success: function (res) {
                if (res.success) {
                    $row.fadeOut(300, function () {
                        $(this).remove();
                        showToast(res.data.message, 'success');
                        fetchProducts();
                    });
                } else {
                    $row.removeClass('brikpanel-pl-row-deleting');
                    showToast(res.data.message || PL.i18n.error, 'error');
                }
            },
            error: function () {
                $row.removeClass('brikpanel-pl-row-deleting');
                showToast(PL.i18n.error, 'error');
            }
        });
    }

    function duplicateProduct(id) {
        showToast(PL.i18n.duplicating, 'info');

        $.ajax({
            url: PL.ajax_url,
            type: 'POST',
            data: {
                action: 'brikpanel_duplicate_product',
                security: PL.nonce_pe,
                product_id: id
            },
            success: function (res) {
                if (res.success) {
                    showToast(res.data.message || PL.i18n.duplicated, 'success');
                    fetchProducts();
                } else {
                    showToast(res.data.message || PL.i18n.error, 'error');
                }
            },
            error: function () {
                showToast(PL.i18n.error, 'error');
            }
        });
    }

    function toggleStatus(id) {
        var $badge = $('#bpl-table-body tr[data-id="' + id + '"] .brikpanel-pl-status-badge');
        $badge.addClass('brikpanel-pl-status-saving');

        $.ajax({
            url: PL.ajax_url,
            type: 'POST',
            data: {
                action: 'brikpanel_toggle_status',
                security: PL.nonce,
                product_id: id
            },
            success: function (res) {
                $badge.removeClass('brikpanel-pl-status-saving');
                if (res.success) {
                    var newClass = res.data.status === 'publish' ? 'published' : 'draft';
                    var newLabel = res.data.status === 'publish' ? PL.i18n.published : PL.i18n.draft;
                    $badge.removeClass('published draft').addClass(newClass).text(newLabel);

                    // Update state
                    for (var i = 0; i < state.products.length; i++) {
                        if (state.products[i].id === id) {
                            state.products[i].status = res.data.status;
                            break;
                        }
                    }

                    showToast(res.data.message, 'success');
                } else {
                    showToast(res.data.message || PL.i18n.error, 'error');
                }
            },
            error: function () {
                $badge.removeClass('brikpanel-pl-status-saving');
                showToast(PL.i18n.error, 'error');
            }
        });
    }

    // =========================================================================
    // PAGINATION
    // =========================================================================

    function renderPagination() {
        var $pag = $('#bpl-pagination');
        if (state.pages <= 1) {
            $pag.html('<span class="brikpanel-pl-showing">' +
                PL.i18n.showing.replace('%1$d', state.total).replace('%2$d', state.total) +
                '</span>');
            return;
        }

        var start = (state.page - 1) * state.per_page + 1;
        var end = Math.min(state.page * state.per_page, state.total);
        var html = '<span class="brikpanel-pl-showing">' +
            PL.i18n.showing_range.replace('%1$d', start).replace('%2$d', end).replace('%3$d', state.total) +
            '</span>';

        html += '<div class="brikpanel-pl-page-btns">';

        // Prev
        html += '<button class="brikpanel-pl-page-btn' + (state.page <= 1 ? ' disabled' : '') + '" data-page="prev">' +
            '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15,18 9,12 15,6"/></svg>' +
            '</button>';

        // Page numbers
        var pages = getPaginationNumbers(state.page, state.pages);
        for (var i = 0; i < pages.length; i++) {
            if (pages[i] === '...') {
                html += '<span class="brikpanel-pl-page-dots">...</span>';
            } else {
                html += '<button class="brikpanel-pl-page-btn' + (pages[i] === state.page ? ' active' : '') + '" data-page="' + pages[i] + '">' + pages[i] + '</button>';
            }
        }

        // Next
        html += '<button class="brikpanel-pl-page-btn' + (state.page >= state.pages ? ' disabled' : '') + '" data-page="next">' +
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
        $('#bpl-total-count').text(counts.all);

        // Show/hide trash tab
        if (counts.trash > 0) {
            if (!$('[data-count="trash"]').length) {
                $('.brikpanel-pl-tabs').append(
                    '<button class="brikpanel-pl-tab' + (state.status === 'trash' ? ' active' : '') + '" data-status="trash">' +
                    PL.i18n.trashed_tab + ' <span class="brikpanel-pl-tab-count" data-count="trash">' + counts.trash + '</span></button>'
                );
            } else {
                $('[data-count="trash"]').text(counts.trash);
            }
        }
    }

    // =========================================================================
    // TOAST NOTIFICATIONS
    // =========================================================================

    function showToast(message, type) {
        type = type || 'success';
        var $container = $('#bpl-toast-container');
        var $toast = $('<div class="brikpanel-pl-toast ' + type + '">' +
            '<span class="brikpanel-pl-toast-text">' + escHtml(message) + '</span>' +
            '<button class="brikpanel-pl-toast-close">&times;</button>' +
            '</div>');

        $container.append($toast);

        // Animate in
        setTimeout(function () { $toast.addClass('show'); }, 10);

        // Close button
        $toast.find('.brikpanel-pl-toast-close').on('click', function () {
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
    // RESTORE & PERMANENT DELETE (trash view)
    // =========================================================================

    $(document).on('click', '.brikpanel-pl-action-restore', function (e) {
        e.preventDefault();
        e.stopPropagation();
        var id = parseInt($(this).closest('tr').data('id'));
        $.ajax({
            url: PL.ajax_url,
            type: 'POST',
            data: {
                action: 'brikpanel_bulk_action_products',
                security: PL.nonce,
                bulk_action: 'restore',
                product_ids: [id]
            },
            success: function (res) {
                if (res.success) {
                    showToast(PL.i18n.restored, 'success');
                    fetchProducts();
                }
            }
        });
    });

    $(document).on('click', '.brikpanel-pl-action-delete-perm', function (e) {
        e.preventDefault();
        e.stopPropagation();
        var id = parseInt($(this).closest('tr').data('id'));
        if (!confirm(PL.i18n.confirm_permanent_delete)) return;
        $.ajax({
            url: PL.ajax_url,
            type: 'POST',
            data: {
                action: 'brikpanel_bulk_action_products',
                security: PL.nonce,
                bulk_action: 'delete',
                product_ids: [id]
            },
            success: function (res) {
                if (res.success) {
                    showToast(PL.i18n.deleted_permanently, 'success');
                    fetchProducts();
                }
            }
        });
    });

    // =========================================================================
    // VARIATION INLINE POPUP
    // =========================================================================

    function openVariationPopup($anchor, productId, field) {
        // Create overlay + popup
        var $overlay = $('<div class="brikpanel-pl-var-overlay" style="position:fixed;inset:0;background:rgba(0,0,0,0.65);z-index:99997;"></div>');
        var $popup = $('<div class="brikpanel-pl-var-popup" style="position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);z-index:99998;background:#fff;border-radius:0.75rem;box-shadow:0 16px 48px rgba(0,0,0,0.2);width:460px;max-width:92vw;max-height:80vh;display:flex;flex-direction:column;"><div class="brikpanel-pl-var-popup-loading"><div class="brikpanel-pl-spinner"></div></div></div>');

        $('body').append($overlay).append($popup);

        // Close on overlay click
        $overlay.on('click', function () {
            closeVariationPopup();
            fetchProducts();
        });

        // Escape key
        $(document).on('keydown.varpopup', function (e) {
            if (e.key === 'Escape') { closeVariationPopup(); fetchProducts(); }
        });

        // Fetch variations
        $.ajax({
            url: PL.ajax_url,
            type: 'POST',
            data: { action: 'brikpanel_get_variations', security: PL.nonce, product_id: productId },
            success: function (res) {
                if (!res.success || !res.data.variations.length) {
                    $popup.html('<div class="brikpanel-pl-var-popup-empty">' + escHtml(PL.i18n.no_variations) + '</div>');
                    return;
                }
                renderVariationPopup($popup, res.data.variations, field, productId);
            },
            error: function () {
                $popup.html('<div class="brikpanel-pl-var-popup-empty">' + escHtml(PL.i18n.error) + '</div>');
            }
        });
    }

    function renderVariationPopup($popup, variations, field, productId) {
        var title = field === 'stock' ? PL.i18n.stock_by_variation : PL.i18n.price_by_variation;
        var html = '<div class="brikpanel-pl-var-popup-header" style="display:flex;align-items:center;justify-content:space-between;padding:0.75rem 1rem;border-bottom:1px solid #e3e3e3;flex-shrink:0;">' +
            '<span style="font-size:0.875rem;font-weight:600;color:#303030;">' + escHtml(title) + '</span>' +
            '<button class="brikpanel-pl-var-popup-close" style="background:none;border:none;font-size:1.25rem;color:#8a8a8a;cursor:pointer;padding:0;">&times;</button></div>';

        html += '<div class="brikpanel-pl-var-popup-body" style="flex:1;overflow-y:auto;max-height:60vh;padding:0.5rem 0;">';
        for (var i = 0; i < variations.length; i++) {
            var v = variations[i];
            html += '<div class="brikpanel-pl-var-popup-row" data-var-id="' + v.id + '">';
            html += '<span class="brikpanel-pl-var-popup-name">' + escHtml(v.name) + '</span>';

            if (field === 'stock') {
                var stockVal = v.stock !== null ? v.stock : '';
                html += '<input type="number" class="brikpanel-pl-var-popup-input" data-field="stock" value="' + escAttr(stockVal) + '" min="0" placeholder="—">';
            } else {
                html += '<input type="text" class="brikpanel-pl-var-popup-input" data-field="regular_price" value="' + escAttr(v.regular_price || '') + '" placeholder="' + escAttr(PL.i18n.price_label) + '">';
                html += '<input type="text" class="brikpanel-pl-var-popup-input sale" data-field="sale_price" value="' + escAttr(v.sale_price || '') + '" placeholder="' + escAttr(PL.i18n.sale_label) + '">';
            }
            html += '</div>';
        }
        html += '</div>';

        // Footer with Apply button
        html += '<div class="brikpanel-pl-var-popup-footer" style="display:flex;align-items:center;justify-content:flex-end;gap:0.5rem;padding:0.75rem 1rem;border-top:1px solid #e3e3e3;flex-shrink:0;">' +
            '<button class="brikpanel-pl-var-popup-cancel" style="padding:0.5rem 1rem;border:1px solid #e3e3e3;border-radius:0.5rem;background:#fff;color:#303030;font-size:0.8125rem;font-weight:550;cursor:pointer;">' + escHtml(PL.i18n.cancel || 'Cancel') + '</button>' +
            '<button class="brikpanel-pl-var-popup-apply" data-parent="' + productId + '" style="padding:0.5rem 1rem;border:none;border-radius:0.5rem;background:#303030;color:#fff;font-size:0.8125rem;font-weight:550;cursor:pointer;">' + escHtml(PL.i18n.apply) + '</button>' +
            '</div>';

        $popup.html(html);

        // Apply all
        $popup.on('click', '.brikpanel-pl-var-popup-apply', function () {
            var $btn = $(this);
            var $rows = $popup.find('.brikpanel-pl-var-popup-row');
            var pending = $rows.length;
            var saved = 0;

            $btn.prop('disabled', true).text(PL.i18n.saving);

            $rows.each(function () {
                var $row = $(this);
                var varId = parseInt($row.data('var-id'));
                var data = { action: 'brikpanel_save_variation', security: PL.nonce, variation_id: varId };

                $row.find('.brikpanel-pl-var-popup-input').each(function () {
                    data[$(this).data('field')] = $(this).val();
                });

                $.ajax({
                    url: PL.ajax_url,
                    type: 'POST',
                    data: data,
                    success: function (res) {
                        if (res.success) saved++;
                    },
                    complete: function () {
                        pending--;
                        if (pending <= 0) {
                            showToast(saved + ' ' + PL.i18n.saved, 'success');
                            closeVariationPopup();
                            fetchProducts();
                        }
                    }
                });
            });
        });

        // Cancel
        $popup.on('click', '.brikpanel-pl-var-popup-cancel', function () {
            closeVariationPopup();
        });

        // Close button
        $popup.on('click', '.brikpanel-pl-var-popup-close', function () {
            closeVariationPopup();
            fetchProducts();
        });
    }

    function closeVariationPopup() {
        $('.brikpanel-pl-var-popup, .brikpanel-pl-var-overlay').remove();
        $(document).off('keydown.varpopup');
    }

    // =========================================================================
    // QUICK EDIT DRAWER - VARIATION SUPPORT
    // =========================================================================

    function loadDrawerVariations(productId) {
        var $container = $('#bpl-qe-variations');
        $container.html('<div class="brikpanel-pl-spinner" style="margin:1rem auto;display:block;"></div>');
        $container.show();

        // Hide simple product fields
        $('#bpl-qe-simple-fields').hide();

        $.ajax({
            url: PL.ajax_url,
            type: 'POST',
            data: { action: 'brikpanel_get_variations', security: PL.nonce, product_id: productId },
            success: function (res) {
                if (!res.success || !res.data.variations.length) {
                    $container.html('<p class="brikpanel-pl-text-muted" style="text-align:center;padding:1rem;">' + escHtml(PL.i18n.no_variations) + '</p>');
                    return;
                }
                renderDrawerVariations($container, res.data.variations, productId);
            }
        });
    }

    function renderDrawerVariations($container, variations, productId) {
        var html = '<div class="brikpanel-pl-qe-var-list">';
        for (var i = 0; i < variations.length; i++) {
            var v = variations[i];
            var stockVal = v.stock !== null ? v.stock : '';
            html += '<div class="brikpanel-pl-qe-var-item" data-var-id="' + v.id + '">';
            html += '<div class="brikpanel-pl-qe-var-name">' + escHtml(v.name) + '</div>';
            html += '<div class="brikpanel-pl-qe-var-fields">';
            html += '<div class="brikpanel-pl-qe-field"><label>' + escHtml(PL.i18n.price_label || 'Price') + '</label><input type="text" class="brikpanel-pl-qe-var-input" data-field="regular_price" value="' + escAttr(v.regular_price || '') + '"></div>';
            html += '<div class="brikpanel-pl-qe-field"><label>' + escHtml(PL.i18n.sale_label || 'Sale') + '</label><input type="text" class="brikpanel-pl-qe-var-input" data-field="sale_price" value="' + escAttr(v.sale_price || '') + '"></div>';
            html += '<div class="brikpanel-pl-qe-field"><label>' + escHtml(PL.i18n.stock_label || 'Stock') + '</label><input type="number" class="brikpanel-pl-qe-var-input" data-field="stock" value="' + escAttr(stockVal) + '" min="0"></div>';
            html += '</div></div>';
        }
        html += '</div>';

        $container.html(html).data('parent-id', productId);
    }

    // =========================================================================
    // BULK UPDATE MODAL
    // =========================================================================

    function initBulkModal() {
        // Open modal
        $('#bpl-bulk-update-btn').on('click', function () {
            openBulkModal();
        });

        // Close modal
        $('#bpl-bulk-modal-close, #bpl-bulk-modal-cancel, #bpl-bulk-modal-overlay').on('click', function (e) {
            if (e.target === this) closeBulkModal();
        });

        // Tab switching
        $(document).on('click', '.brikpanel-pl-modal-tab', function () {
            var tab = $(this).data('tab');
            $('.brikpanel-pl-modal-tab').removeClass('active');
            $(this).addClass('active');
            $('.brikpanel-pl-modal-tab-content').removeClass('active');
            $('#' + tab).addClass('active');
        });

        // Load attributes when category changes
        $('#bpl-bulk-cat').on('change', function () {
            loadVariationAttributes($(this).val());
        });

        // Attribute key change → populate values
        $('#bpl-bulk-attr-key').on('change', function () {
            var key = $(this).val();
            var $valSelect = $('#bpl-bulk-attr-val');
            if (!key) {
                $valSelect.html('<option value="">' + escHtml(PL.i18n.select_attr_first) + '</option>').prop('disabled', true);
                return;
            }
            var attrs = bulkState.attributes;
            for (var i = 0; i < attrs.length; i++) {
                if (attrs[i].key === key) {
                    var html = '<option value="">' + escHtml(PL.i18n.all_variations) + '</option>';
                    for (var j = 0; j < attrs[i].values.length; j++) {
                        html += '<option value="' + escAttr(attrs[i].values[j].slug) + '">' + escHtml(attrs[i].values[j].name) + '</option>';
                    }
                    $valSelect.html(html).prop('disabled', false);
                    break;
                }
            }
        });

        // Delete mode toggle
        $('#bpl-del-mode').on('change', function () {
            var mode = $(this).val();
            $('#bpl-del-cat-wrap').toggle(mode === 'category');
            if (mode === 'selected') {
                var c = state.selected.length;
                $('#bpl-del-sel-info').show().text(
                    c > 0 ? PL.i18n.bulk_selected_count.replace('%d', c) : PL.i18n.bulk_no_selection
                ).toggleClass('brikpanel-pl-modal-warn', c === 0);
            } else {
                $('#bpl-del-sel-info').hide();
            }
        });

        // Apply
        $('#bpl-bulk-modal-apply').on('click', function () {
            var activeTab = $('.brikpanel-pl-modal-tab.active').data('tab');
            if (activeTab === 'bpl-bulk-tab-del') {
                applyBulkDelete();
            } else {
                applyBulkUpdate();
            }
        });

        // Escape key
        $(document).on('keydown', function (e) {
            if (e.key === 'Escape' && $('#bpl-bulk-modal-overlay').hasClass('open')) {
                closeBulkModal();
            }
        });
    }

    var bulkState = { attributes: [] };

    function openBulkModal() {
        // Update selected count info
        var count = state.selected.length;
        if (count > 0) {
            $('#bpl-bulk-sel-info').text(PL.i18n.bulk_selected_count.replace('%d', count)).removeClass('brikpanel-pl-modal-warn');
        } else {
            $('#bpl-bulk-sel-info').text(PL.i18n.bulk_no_selection).addClass('brikpanel-pl-modal-warn');
        }

        // Reset delete tab
        $('#bpl-del-mode').val('selected').trigger('change');

        $('#bpl-bulk-modal-overlay').addClass('open');
        $('body').addClass('brikpanel-pl-drawer-open');
    }

    function closeBulkModal() {
        $('#bpl-bulk-modal-overlay').removeClass('open');
        $('body').removeClass('brikpanel-pl-drawer-open');
    }

    function loadVariationAttributes(catId) {
        var $keySelect = $('#bpl-bulk-attr-key');
        var $valSelect = $('#bpl-bulk-attr-val');

        if (!catId) {
            $keySelect.html('<option value="">' + escHtml(PL.i18n.all_variations) + '</option>');
            $valSelect.html('<option value="">' + escHtml(PL.i18n.select_attr_first) + '</option>').prop('disabled', true);
            bulkState.attributes = [];
            return;
        }

        $keySelect.html('<option value="">' + escHtml(PL.i18n.loading_attrs) + '</option>');

        $.ajax({
            url: PL.ajax_url,
            type: 'POST',
            data: {
                action: 'brikpanel_get_variation_attributes',
                security: PL.nonce,
                category: catId
            },
            success: function (res) {
                if (res.success && res.data.length) {
                    bulkState.attributes = res.data;
                    var html = '<option value="">' + escHtml(PL.i18n.all_variations) + '</option>';
                    for (var i = 0; i < res.data.length; i++) {
                        html += '<option value="' + escAttr(res.data[i].key) + '">' + escHtml(res.data[i].label) + '</option>';
                    }
                    $keySelect.html(html);
                } else {
                    $keySelect.html('<option value="">' + escHtml(PL.i18n.all_variations) + '</option>');
                    bulkState.attributes = [];
                }
                $valSelect.html('<option value="">' + escHtml(PL.i18n.select_attr_first) + '</option>').prop('disabled', true);
            }
        });
    }

    function applyBulkUpdate() {
        var activeTab = $('.brikpanel-pl-modal-tab.active').data('tab');
        var mode, action, value, catId, attrKey, attrVal;

        if (activeTab === 'bpl-bulk-tab-cat') {
            mode = 'category';
            catId = $('#bpl-bulk-cat').val();
            action = $('#bpl-bulk-action-cat').val();
            value = $('#bpl-bulk-value-cat').val();
            attrKey = $('#bpl-bulk-attr-key').val();
            attrVal = $('#bpl-bulk-attr-val').val();

            if (!catId) {
                showToast(PL.i18n.bulk_select_cat, 'error');
                return;
            }
            if (!confirm(PL.i18n.bulk_cat_confirm)) return;
        } else {
            mode = 'selected';
            action = $('#bpl-bulk-action-sel').val();
            value = $('#bpl-bulk-value-sel').val();
            catId = '';
            attrKey = '';
            attrVal = '';

            if (!state.selected.length) {
                showToast(PL.i18n.bulk_no_selection, 'error');
                return;
            }
            if (!confirm(PL.i18n.bulk_confirm)) return;
        }

        var $btn = $('#bpl-bulk-modal-apply');
        $btn.prop('disabled', true).text(PL.i18n.applying);

        $.ajax({
            url: PL.ajax_url,
            type: 'POST',
            data: {
                action: 'brikpanel_bulk_update_products',
                security: PL.nonce,
                mode: mode,
                bulk_action: action,
                value: value,
                category: catId,
                selected_ids: mode === 'selected' ? state.selected.join(',') : '',
                attr_key: attrKey || '',
                attr_val: attrVal || ''
            },
            success: function (res) {
                $btn.prop('disabled', false).text(PL.i18n.apply);
                if (res.success) {
                    showToast(res.data.message, 'success');
                    closeBulkModal();
                    fetchProducts();
                } else {
                    showToast(res.data.message || PL.i18n.error, 'error');
                }
            },
            error: function () {
                $btn.prop('disabled', false).text(PL.i18n.apply);
                showToast(PL.i18n.error, 'error');
            }
        });
    }

    // =========================================================================
    // BULK DELETE
    // =========================================================================

    function applyBulkDelete() {
        var mode = $('#bpl-del-mode').val();
        var catId = $('#bpl-del-cat').val();
        var permanent = $('#bpl-del-permanent').is(':checked');
        var deleteImages = $('#bpl-del-images').is(':checked');

        if (mode === 'selected' && !state.selected.length) {
            showToast(PL.i18n.bulk_no_selection, 'error');
            return;
        }
        if (mode === 'category' && !catId) {
            showToast(PL.i18n.bulk_select_cat, 'error');
            return;
        }

        // Double confirmation for dangerous operations
        var confirmMsg = PL.i18n.delete_confirm_1 || 'Are you sure you want to delete these products?';
        if (mode === 'all') {
            confirmMsg = PL.i18n.delete_confirm_all || 'Are you sure you want to delete ALL products? This is very dangerous!';
        }
        if (!confirm(confirmMsg)) return;

        if (permanent) {
            var confirmMsg2 = PL.i18n.delete_confirm_2 || 'PERMANENT DELETE — This cannot be undone. Are you absolutely sure?';
            if (!confirm(confirmMsg2)) return;
        }

        var $btn = $('#bpl-bulk-modal-apply');
        $btn.prop('disabled', true).text(PL.i18n.applying);

        $.ajax({
            url: PL.ajax_url,
            type: 'POST',
            data: {
                action: 'brikpanel_bulk_delete_products',
                security: PL.nonce,
                mode: mode,
                category: catId || '',
                selected_ids: mode === 'selected' ? state.selected.join(',') : '',
                permanent: permanent ? '1' : '0',
                delete_images: deleteImages ? '1' : '0'
            },
            success: function (res) {
                $btn.prop('disabled', false).text(PL.i18n.apply);
                if (res.success) {
                    showToast(res.data.message, 'success');
                    state.selected = [];
                    updateBulkBar();
                    closeBulkModal();
                    fetchProducts();
                } else {
                    showToast(res.data.message || PL.i18n.error, 'error');
                }
            },
            error: function () {
                $btn.prop('disabled', false).text(PL.i18n.apply);
                showToast(PL.i18n.error, 'error');
            }
        });
    }

    // =========================================================================
    // BOOT
    // =========================================================================

    function init() {
        bindEvents();
        initBulkModal();
        fetchProducts();
    }

    $(document).ready(init);

})(jQuery);
