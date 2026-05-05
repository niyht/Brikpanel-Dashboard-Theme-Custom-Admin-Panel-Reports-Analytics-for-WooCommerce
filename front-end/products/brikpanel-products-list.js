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
        pages: 0,
        extraColumns: {},
        // Map of extra (3rd-party) column id → true when hidden by user.
        // Native columns use the data-hide-<id> attribute on the table; extras
        // can't because their IDs are dynamic, so we toggle a class on each
        // <th>/<td> instead.
        extraHidden: {},
        // Quick-edit drawer's working copy of the product's downloadable
        // files. Re-seeded from product.downloads on openDrawer; serialized
        // back to JSON on saveDrawer.
        qeDownloads: [],
        // Storefront drag-to-sort mode. When true, the sort dropdown is
        // forced to 'menu-asc', drag handles are visible, and tbody is
        // wired up via jQuery UI sortable. Remembers the previous sort so
        // exiting restores it instead of stranding the user on menu_order.
        sortMode: false,
        prevSort: null,
        sortSaving: false
    };

    var searchTimer = null;

    // =========================================================================
    // PROGRESS BAR
    // =========================================================================

    function showProgress() { $('#bpl-progress').addClass('active'); }
    function hideProgress() { $('#bpl-progress').removeClass('active'); }

    function setBulkLoading(busy, $clickedBtn) {
        var $bar = $('#bpl-bulk-bar');
        if (busy) {
            $bar.addClass('is-loading');
            if ($clickedBtn && $clickedBtn.length) {
                $clickedBtn.data('orig-html', $clickedBtn.html());
                $clickedBtn.css({ opacity: 1, pointerEvents: 'none' })
                    .html('<span class="bpl-btn-spinner"></span> ' + escHtml(PL.i18n.applying || 'Applying...'));
            }
            showProgress();
        } else {
            $bar.removeClass('is-loading');
            $bar.find('.brikpanel-pl-btn').each(function () {
                var orig = $(this).data('orig-html');
                if (orig) { $(this).html(orig).css({ opacity: '', pointerEvents: '' }).removeData('orig-html'); }
            });
            hideProgress();
        }
    }

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
            // Switching to "Custom order" from the dropdown also engages
            // sort mode so the drag handles appear immediately. Switching
            // away while sort mode is active gracefully exits it.
            if (state.sort === 'menu-asc' && !state.sortMode) {
                enterSortMode(false);
            } else if (state.sort !== 'menu-asc' && state.sortMode) {
                exitSortMode(false);
            }
            fetchProducts();
        });

        // Sort mode toggle (drag-to-reorder products by storefront menu_order).
        $('#bpl-sort-toggle').on('click', function () {
            if (state.sortMode) {
                exitSortMode(true);
            } else {
                enterSortMode(true);
            }
            fetchProducts();
        });
        $(document).on('click', '#bpl-sort-done', function () {
            exitSortMode(true);
            fetchProducts();
        });

        // Column visibility dropdown
        var $colsBtn     = $('#bpl-columns-btn');
        var $colsPopover = $('#bpl-columns-popover');
        var $table       = $('#bpl-table');
        var colsSaveTimer = null;

        // Seed extraHidden from popover state (server-rendered with the
        // user's saved preferences) so toggling works before the first
        // products fetch resolves.
        $colsPopover.find('input[type="checkbox"][data-extra="1"]').each(function () {
            var id = String($(this).data('col') || '');
            if (id) state.extraHidden[id] = !this.checked;
        });

        function isExtraColumn(colId) {
            return $colsPopover
                .find('input[type="checkbox"][data-col="' + colId + '"][data-extra="1"]')
                .length > 0;
        }

        function applyColumnVisibility(colId, visible) {
            if (isExtraColumn(colId)) {
                state.extraHidden[colId] = !visible;
                $table.find(
                    'th[data-ase-col="' + colId + '"], td[data-ase-col="' + colId + '"]'
                ).toggleClass('bpl-col-hidden', !visible);
                return;
            }
            if (visible) {
                $table.removeAttr('data-hide-' + colId);
            } else {
                $table.attr('data-hide-' + colId, '1');
            }
        }

        function saveColumns() {
            clearTimeout(colsSaveTimer);
            colsSaveTimer = setTimeout(function () {
                var payload = { action: 'brikpanel_pl_save_columns', security: PL.nonce, columns: {} };
                $colsPopover.find('input[type="checkbox"][data-col]').each(function () {
                    payload.columns[$(this).data('col')] = this.checked ? 1 : 0;
                });
                $.post(PL.ajax_url, payload);
            }, 400);
        }

        $colsBtn.on('click', function (e) {
            e.stopPropagation();
            var willOpen = $colsPopover.prop('hidden');
            $colsPopover.prop('hidden', !willOpen);
            $colsBtn.attr('aria-expanded', willOpen ? 'true' : 'false');
        });

        $colsPopover.on('click', function (e) { e.stopPropagation(); });

        $colsPopover.on('change', 'input[type="checkbox"][data-col]', function () {
            var col = $(this).data('col');
            applyColumnVisibility(col, this.checked);
            saveColumns();
        });

        $(document).on('click', function () {
            if (!$colsPopover.prop('hidden')) {
                $colsPopover.prop('hidden', true);
                $colsBtn.attr('aria-expanded', 'false');
            }
        });

        $(document).on('keydown', function (e) {
            if (e.key === 'Escape' && !$colsPopover.prop('hidden')) {
                $colsPopover.prop('hidden', true);
                $colsBtn.attr('aria-expanded', 'false');
            }
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

        $('#bpl-bulk-publish').on('click', function () { bulkAction('publish', $(this)); });
        $('#bpl-bulk-draft').on('click', function () { bulkAction('draft', $(this)); });
        $('#bpl-bulk-trash').on('click', function () { bulkAction('trash', $(this)); });
        $('#bpl-bulk-delete-perm').on('click', function () { bulkAction('delete', $(this)); });

        // Export selected products as CSV
        $('#bpl-bulk-export').on('click', function () {
            if (!state.selected.length) {
                showToast(PL.i18n.export_no_selection, 'error');
                return;
            }
            var url = PL.export_url + '&product_ids=' + encodeURIComponent(state.selected.join(','));
            window.location.href = url;
        });

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

        // Quick-edit category / tag search — hides non-matching entries while
        // preserving the existing checkbox state so a filter never wipes a
        // selection the user already made.
        $('#bpl-qe-cat-search').on('input', function () {
            filterTermList($('#bpl-qe-cat-list'), $(this).val());
        });
        $('#bpl-qe-tag-search').on('input', function () {
            filterTermList($('#bpl-qe-tag-list'), $(this).val());
        });

        // Quick-edit stock status toggle
        $(document).on('click', '#bpl-qe-stock-status-toggle .brikpanel-pl-toggle-opt', function () {
            var v = $(this).data('value');
            var $grp = $('#bpl-qe-stock-status-toggle');
            $grp.find('.brikpanel-pl-toggle-opt').removeClass('is-active').attr('aria-checked', 'false');
            $(this).addClass('is-active').attr('aria-checked', 'true');
            $('#bpl-qe-stock-status').val(v);
        });

        // Quick-edit digital product toggle — collapses/expands the file list.
        $('#bpl-qe-digital-toggle').on('change', function () {
            $('#bpl-qe-digital-section').toggleClass('open', this.checked);
        });

        // Quick-edit "Add downloadable file" — opens WP media library.
        $('#bpl-qe-add-download').on('click', function (e) {
            e.preventDefault();
            openQeFilePicker();
        });

        // Inline stock-status toggle in the Stock status column. Flips state
        // optimistically, fires ajax_quick_edit, and reverts on failure so a
        // failed save cannot silently desync the UI from the database.
        $(document).on('click', '.brikpanel-pl-ss-toggle', function (e) {
            e.preventDefault();
            e.stopPropagation();
            var $btn = $(this);
            if ($btn.prop('disabled')) return;

            var productId = parseInt($btn.closest('td').data('id'));
            if (!productId) return;

            var wasOn     = $btn.hasClass('is-on');
            var nextOn    = !wasOn;
            var nextValue = nextOn ? 'instock' : 'outofstock';
            var labelOn   = PL.i18n.in_stock || 'In stock';
            var labelOff  = PL.i18n.out_of_stock || 'Out of stock';
            var nextLabel = nextOn ? labelOn : labelOff;

            // Optimistic UI: flip immediately so the click feels instant.
            $btn.toggleClass('is-on', nextOn)
                .attr('aria-checked', nextOn ? 'true' : 'false')
                .attr('data-status', nextValue)
                .attr('title', nextLabel)
                .attr('aria-label', nextLabel)
                .prop('disabled', true);

            $.ajax({
                url: PL.ajax_url,
                type: 'POST',
                data: {
                    action: 'brikpanel_quick_edit_product',
                    security: PL.nonce,
                    product_id: productId,
                    stock_status: nextValue
                },
                success: function (res) {
                    $btn.prop('disabled', false);
                    if (res && res.success) {
                        updateProductInState(res.data.product);
                        showToast(PL.i18n.saved, 'success');
                    } else {
                        // Revert.
                        $btn.toggleClass('is-on', wasOn)
                            .attr('aria-checked', wasOn ? 'true' : 'false')
                            .attr('data-status', wasOn ? 'instock' : 'outofstock')
                            .attr('title', wasOn ? labelOn : labelOff)
                            .attr('aria-label', wasOn ? labelOn : labelOff);
                        showToast((res && res.data && res.data.message) || PL.i18n.error, 'error');
                    }
                },
                error: function () {
                    $btn.prop('disabled', false);
                    $btn.toggleClass('is-on', wasOn)
                        .attr('aria-checked', wasOn ? 'true' : 'false')
                        .attr('data-status', wasOn ? 'instock' : 'outofstock')
                        .attr('title', wasOn ? labelOn : labelOff);
                    $btn.find('.brikpanel-pl-ss-label').text(wasOn ? labelOn : labelOff);
                    showToast(PL.i18n.error, 'error');
                }
            });
        });

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

        // Click row to go to edit page (excluding clicks on the product name link,
        // which is a real <a> and handles its own navigation / new-tab behavior).
        $(document).on('click', '.brikpanel-pl-row', function (e) {
            if ($(e.target).closest('input, button, a, .brikpanel-pl-editable, .brikpanel-pl-actions-cell, .brikpanel-pl-status-badge, .brikpanel-pl-stock-badge, .brikpanel-pl-product-name-link, .brikpanel-pl-cat-more').length) return;
            var url = $(this).data('edit-url');
            if (url) window.location.href = url;
        });

        // Keyboard shortcut: Escape to close drawer
        $(document).on('keydown', function (e) {
            if (e.key === 'Escape' && $('#bpl-drawer').hasClass('open')) {
                closeDrawer();
            }
        });

        // Category "+N" hover/focus popover — floating on <body> so it
        // escapes the table wrapper's overflow clipping.
        $(document)
            .on('mouseenter focusin', '.brikpanel-pl-cat-more', function () {
                showCatMorePopover(this);
            })
            .on('mouseleave focusout', '.brikpanel-pl-cat-more', function () {
                hideCatMorePopover();
            });
        $(window).on('scroll resize', hideCatMorePopover);
    }

    var $catMorePop = null;
    function showCatMorePopover(el) {
        var raw = el.getAttribute('data-others') || '[]';
        var others;
        try { others = JSON.parse(raw); } catch (err) { others = []; }
        if (!others.length) return;

        if (!$catMorePop) {
            $catMorePop = $('<div class="brikpanel-pl-cat-popover" role="tooltip"></div>').appendTo(document.body);
        }
        var html = '<ul class="brikpanel-pl-cat-popover-list">';
        for (var i = 0; i < others.length; i++) {
            html += '<li>' + escHtml(others[i]) + '</li>';
        }
        html += '</ul>';
        $catMorePop.html(html).addClass('is-visible');

        var rect = el.getBoundingClientRect();
        var popWidth = $catMorePop.outerWidth();
        var popHeight = $catMorePop.outerHeight();
        var top = rect.bottom + 6;
        var left = rect.left + (rect.width / 2) - (popWidth / 2);
        // Keep within viewport
        var margin = 8;
        if (left < margin) left = margin;
        if (left + popWidth > window.innerWidth - margin) {
            left = window.innerWidth - popWidth - margin;
        }
        // Flip above if no room below
        if (top + popHeight > window.innerHeight - margin && rect.top - popHeight - 6 > margin) {
            top = rect.top - popHeight - 6;
        }
        $catMorePop.css({ top: top + 'px', left: left + 'px' });
    }
    function hideCatMorePopover() {
        if ($catMorePop) $catMorePop.removeClass('is-visible');
    }

    // =========================================================================
    // FETCH PRODUCTS
    // =========================================================================

    /**
     * Total visible columns including the dynamic ASE-contributed ones.
     * Used for full-width loading / empty rows.
     */
    function totalColumnCount() {
        // 1 sort handle (hidden unless sortMode) + 8 native BrikPanel cols
        // (check, image, name, sku, price, stock, cat, status) + 1 actions
        // col + N ASE extras. The handle <th>/<td> is always present in
        // the DOM so colspan calculations stay stable; CSS hides it when
        // sortMode is off, but it still counts as a real column.
        var extras = state.extraColumns ? Object.keys(state.extraColumns).length : 0;
        return 10 + extras;
    }

    /**
     * Sync the table <thead> with the latest set of ASE-contributed
     * columns so plugin-added headers stay aligned with the row cells.
     * Inserted right before the trailing actions column.
     */
    /**
     * Append a checkbox row to the Columns dropdown for each extra
     * (3rd-party) column reported by the server, removing any entries
     * for columns that have since disappeared. Native column entries
     * are server-rendered and never touched here.
     *
     * Some plugins (SEOPress, etc.) only register their column hooks
     * during AJAX, so the list is only authoritative after a fetch —
     * which is why this is called from the fetch success handler.
     */
    function syncExtraColumnsPopover() {
        var $pop = $('#bpl-columns-popover');
        if (!$pop.length) return;

        var labels = state.extraColumns || {};
        var ids    = Object.keys(labels);

        // Remove popover entries for extras that are no longer present.
        $pop.find('input[type="checkbox"][data-extra="1"]').each(function () {
            var existingId = String($(this).data('col') || '');
            if (existingId && labels.hasOwnProperty(existingId)) return;
            $(this).closest('label').remove();
            delete state.extraHidden[existingId];
        });

        if (!ids.length) {
            $pop.find('.brikpanel-pl-columns-divider').remove();
            return;
        }

        // Ensure the divider exists once, before the first extra entry.
        if (!$pop.find('.brikpanel-pl-columns-divider').length) {
            $pop.append(
                '<div class="brikpanel-pl-columns-divider" role="separator" aria-hidden="true">' +
                escHtml(PL.i18n.plugin_columns || 'Plugin columns') +
                '</div>'
            );
        }

        // Append missing extras and update labels for existing ones.
        for (var i = 0; i < ids.length; i++) {
            var colId    = ids[i];
            var label    = labels[colId] || colId;
            var hidden   = !!state.extraHidden[colId];
            var $existing = $pop.find('input[type="checkbox"][data-col="' + colId + '"][data-extra="1"]');
            if ($existing.length) {
                $existing.prop('checked', !hidden);
                $existing.closest('label').find('span').text(label);
                continue;
            }
            var $row = $(
                '<label class="brikpanel-pl-columns-item is-extra">' +
                    '<input type="checkbox" data-extra="1">' +
                    '<span></span>' +
                '</label>'
            );
            $row.find('input')
                .attr('data-col', colId)
                .prop('checked', !hidden);
            $row.find('span').text(label);
            $pop.append($row);
        }
    }

    function syncAseHeaders() {
        var $headerRow = $('#bpl-table thead tr');
        if (!$headerRow.length) return;

        // Drop previously-injected ASE header cells so a column that has
        // disappeared (option toggled off) leaves no stale <th>.
        $headerRow.find('th.brikpanel-pl-th-ase').remove();

        var cols = state.extraColumns || {};
        var ids  = Object.keys(cols);
        if (!ids.length) return;

        var $actionsTh = $headerRow.find('th.brikpanel-pl-th-actions');
        for (var i = 0; i < ids.length; i++) {
            var colId = ids[i];
            var label = cols[colId];
            var hiddenCls = (state.extraHidden && state.extraHidden[colId]) ? ' bpl-col-hidden' : '';
            var $th = $('<th class="brikpanel-pl-th-ase column-' + escAttr(colId) + hiddenCls + '" data-ase-col="' + escAttr(colId) + '"></th>')
                .text(label);
            if ($actionsTh.length) {
                $actionsTh.before($th);
            } else {
                $headerRow.append($th);
            }
        }
    }

    function fetchProducts() {
        if (state.loading) return;
        state.loading = true;
        showProgress();

        var $body = $('#bpl-table-body');
        $body.html('<tr class="brikpanel-pl-loading-row"><td colspan="' + totalColumnCount() + '"><div class="brikpanel-pl-spinner"></div></td></tr>');

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
                hideProgress();
                if (!res.success) {
                    $body.html('<tr><td colspan="' + totalColumnCount() + '" class="brikpanel-pl-empty">' + escHtml(res.data.message || PL.i18n.error) + '</td></tr>');
                    return;
                }

                state.products = res.data.products;
                state.total = res.data.total;
                state.pages = res.data.pages;
                state.extraColumns = res.data.extra_columns || {};

                // Refresh extras-hidden state from server-resolved
                // visibility (some 3rd-party plugins only register columns
                // during AJAX, so the source of truth lives here).
                var extraState = res.data.extra_columns_state || {};
                Object.keys(state.extraColumns).forEach(function (colId) {
                    state.extraHidden[colId] = !extraState[colId];
                });

                syncExtraColumnsPopover();

                // Update counts
                updateCounts(res.data.counts);
                syncAseHeaders();
                renderProducts();
                renderPagination();
                updateBulkBar();

                // Reset check-all
                $('#bpl-check-all').prop('checked', false);
            },
            error: function () {
                state.loading = false;
                hideProgress();
                $body.html('<tr><td colspan="' + totalColumnCount() + '" class="brikpanel-pl-empty">' + escHtml(PL.i18n.error) + '</td></tr>');
            }
        });
    }

    // =========================================================================
    // RENDER PRODUCTS
    // =========================================================================

    function renderProducts() {
        var $body = $('#bpl-table-body');

        if (!state.products.length) {
            $body.html('<tr><td colspan="' + totalColumnCount() + '" class="brikpanel-pl-empty">' +
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

        if (state.sortMode) {
            initSortable();
        }
    }

    // =========================================================================
    // SORT MODE — drag-to-reorder products by storefront menu_order
    // =========================================================================

    /**
     * Engage sort mode: switch sort to menu_order, lock the dropdown, show
     * the drag handles and the helper banner. `byButton` controls whether
     * the call originated from the toolbar button (true) vs. the sort
     * dropdown selecting "Custom order" (false) — the latter shouldn't
     * stack a second sort change on top of the user's pick.
     */
    function enterSortMode(byButton) {
        if (state.sortMode) return;
        state.prevSort = state.sort;
        state.sortMode = true;
        if (byButton) {
            state.sort = 'menu-asc';
            $('#bpl-sort').val('menu-asc');
        }
        // Page reset so the user starts at the top of the menu_order
        // sequence — otherwise dragging on page 5 would reorder rows
        // 80–100 only and silently leave 0–79 untouched, which is
        // surprising the first time.
        state.page = 1;
        $('#brikpanel-products-list').addClass('is-sort-mode');
        $('#bpl-sort-hint').prop('hidden', false);
        var $btn = $('#bpl-sort-toggle').addClass('is-active').attr('aria-pressed', 'true');
        $btn.find('.brikpanel-pl-sort-toggle-label').text(PL.i18n.sort_done || 'Done sorting');
        // Hide the bulk bar — selection is visually noisy mid-drag.
        state.selected = [];
        $('.brikpanel-pl-row-check, #bpl-check-all').prop('checked', false);
        updateBulkBar();
    }

    function exitSortMode(byButton) {
        if (!state.sortMode) return;
        state.sortMode = false;
        // Tear down the sortable instance so subsequent fetches don't
        // accumulate stale handlers on the new tbody.
        var $body = $('#bpl-table-body');
        if ($body.data('ui-sortable') || $body.hasClass('ui-sortable')) {
            try { $body.sortable('destroy'); } catch (e) {}
        }
        if (byButton && state.prevSort && state.prevSort !== 'menu-asc') {
            state.sort = state.prevSort;
            $('#bpl-sort').val(state.prevSort);
        }
        state.prevSort = null;
        state.page = 1;
        $('#brikpanel-products-list').removeClass('is-sort-mode');
        $('#bpl-sort-hint').prop('hidden', true);
        var $btn = $('#bpl-sort-toggle').removeClass('is-active').attr('aria-pressed', 'false');
        $btn.find('.brikpanel-pl-sort-toggle-label').text(PL.i18n.sort_btn || 'Sort');
    }

    /**
     * Wire up jQuery UI sortable on tbody. Re-called after every fetch
     * because tbody.html() blows away the previous instance. Uses
     * `handle` so only the grip icon initiates a drag — leaves checkbox,
     * row-link, and quick-edit buttons fully clickable.
     */
    function initSortable() {
        var $body = $('#bpl-table-body');
        if (!$body.length || !$.fn.sortable) return;
        // Defensive teardown in case a previous render left a half-initialized
        // instance behind (e.g. fast successive fetches).
        if ($body.data('ui-sortable') || $body.hasClass('ui-sortable')) {
            try { $body.sortable('destroy'); } catch (e) {}
        }
        $body.sortable({
            items: '> tr.brikpanel-pl-row',
            handle: '.brikpanel-pl-sort-handle',
            axis: 'y',
            cursor: 'grabbing',
            opacity: 0.9,
            tolerance: 'pointer',
            placeholder: 'brikpanel-pl-row-placeholder',
            forcePlaceholderSize: true,
            // Keep cell widths intact while dragging — without this the
            // <td>s collapse because the dragged <tr> is detached from
            // the table layout.
            helper: function (e, ui) {
                ui.children().each(function () {
                    $(this).width($(this).width());
                });
                return ui;
            },
            start: function (e, ui) {
                ui.placeholder.height(ui.item.height());
            },
            stop: function () {
                saveProductOrder();
            }
        });
    }

    function saveProductOrder() {
        if (state.sortSaving) return;

        var ids = [];
        $('#bpl-table-body > tr.brikpanel-pl-row').each(function () {
            var id = parseInt($(this).attr('data-id'), 10);
            if (id) ids.push(id);
        });
        if (!ids.length) return;

        // Re-sync the in-memory products array to match the new visual
        // order. Cheap O(n²) is fine here — pages cap at ~100 products.
        var byId = {};
        for (var i = 0; i < state.products.length; i++) {
            byId[state.products[i].id] = state.products[i];
        }
        var reordered = [];
        for (var j = 0; j < ids.length; j++) {
            if (byId[ids[j]]) reordered.push(byId[ids[j]]);
        }
        if (reordered.length === state.products.length) {
            state.products = reordered;
        }

        var base = (state.page - 1) * state.per_page;
        state.sortSaving = true;
        showProgress();

        $.ajax({
            url: PL.ajax_url,
            type: 'POST',
            data: {
                action: 'brikpanel_save_product_order',
                security: PL.nonce,
                ids: ids,
                base: base
            },
            success: function (res) {
                state.sortSaving = false;
                hideProgress();
                if (res && res.success) {
                    showToast(PL.i18n.sort_saved || 'Order saved', 'success');
                } else {
                    var msg = (res && res.data && res.data.message) || PL.i18n.sort_save_failed || 'Could not save order. Please try again.';
                    showToast(msg, 'error');
                }
            },
            error: function () {
                state.sortSaving = false;
                hideProgress();
                showToast(PL.i18n.sort_save_failed || 'Could not save order. Please try again.', 'error');
            }
        });
    }

    function renderProductRow(p) {
        var checked = state.selected.indexOf(p.id) > -1 ? ' checked' : '';
        var statusClass, statusLabel;
        if (p.status === 'publish') {
            statusClass = 'published';
            statusLabel = PL.i18n.published;
        } else if (p.status === 'trash') {
            statusClass = 'trashed';
            statusLabel = PL.i18n.trashed;
        } else if (p.status === 'private') {
            statusClass = 'private';
            statusLabel = PL.i18n.private_status || 'Private';
        } else {
            statusClass = 'draft';
            statusLabel = PL.i18n.draft;
        }

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

        // Inline stock-status toggle rendered beside the quantity inside the
        // same Stock cell — on = instock, off = outofstock. Backorder is a
        // niche state, kept out of the binary toggle. Variable products go
        // through ajax_quick_edit which persists the parent status via
        // update_post_meta regardless of WC's auto-sync.
        var isInStock = p.stock_status !== 'outofstock';
        var statusLabelOn  = PL.i18n.in_stock || 'In stock';
        var statusLabelOff = PL.i18n.out_of_stock || 'Out of stock';
        var toggleTitle = isInStock ? statusLabelOn : statusLabelOff;
        var stockStatusHtml =
            '<button type="button" class="brikpanel-pl-ss-toggle' + (isInStock ? ' is-on' : '') + '" ' +
                'data-status="' + (isInStock ? 'instock' : 'outofstock') + '" ' +
                'role="switch" aria-checked="' + (isInStock ? 'true' : 'false') + '" ' +
                'aria-label="' + escAttr(toggleTitle) + '" ' +
                'title="' + escAttr(toggleTitle) + '">' +
                '<span class="brikpanel-pl-ss-track"><span class="brikpanel-pl-ss-thumb"></span></span>' +
            '</button>';

        var stockCellHtml =
            '<div class="brikpanel-pl-stock-wrap">' + stockHtml + stockStatusHtml + '</div>';

        var priceDisplay = p.price_html || '—';
        var isVariable = p.type === 'variable';

        var priceEditable = isVariable ?
            '<span class="brikpanel-pl-editable brikpanel-pl-price-cell" data-field="price" data-value="">' + priceDisplay + '</span>' :
            '<span class="brikpanel-pl-editable brikpanel-pl-price-cell" data-field="price" data-value="' + escAttr(p.regular_price || '') + '">' + priceDisplay + '</span>';

        var catText;
        if (!p.categories.length) {
            catText = '<span class="brikpanel-pl-text-muted">—</span>';
        } else if (p.categories.length === 1) {
            catText = '<span class="brikpanel-pl-cat-main">' + escHtml(p.categories[0]) + '</span>';
        } else {
            var others = p.categories.slice(1);
            catText = '<span class="brikpanel-pl-cat-main">' + escHtml(p.categories[0]) + '</span>' +
                '<span class="brikpanel-pl-cat-more" data-others="' + escAttr(JSON.stringify(others)) + '" tabindex="0" aria-label="' + escAttr((PL.i18n.more_categories || '%d more').replace('%d', others.length)) + '">+' + others.length + '</span>';
        }

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

        // Cells contributed by third-party plugins (ASE etc.) via the
        // manage_{post_type}_posts_custom_column action. HTML is already
        // sanitised server-side via wp_kses_post.
        var aseCellsHtml = '';
        var extraCols    = state.extraColumns || {};
        var extraIds     = Object.keys(extraCols);
        if (extraIds.length && p.extra_cells) {
            for (var ci = 0; ci < extraIds.length; ci++) {
                var aseColId = extraIds[ci];
                var aseHtml  = (typeof p.extra_cells[aseColId] !== 'undefined') ? p.extra_cells[aseColId] : '';
                var aseHiddenCls = (state.extraHidden && state.extraHidden[aseColId]) ? ' bpl-col-hidden' : '';
                aseCellsHtml += '<td class="brikpanel-pl-cell-ase column-' + escAttr(aseColId) + aseHiddenCls + '" data-ase-col="' + escAttr(aseColId) + '">' + aseHtml + '</td>';
            }
        }

        // Row actions contributed via post_row_actions filter (e.g. ASE's
        // "ID: N" badge).
        var aseActionsHtml = '';
        if (p.extra_actions && p.extra_actions.length) {
            var parts = [];
            for (var ai = 0; ai < p.extra_actions.length; ai++) {
                parts.push('<span class="brikpanel-pl-row-action brikpanel-pl-row-action-' + escAttr(p.extra_actions[ai].id || '') + '">' + (p.extra_actions[ai].html || '') + '</span>');
            }
            aseActionsHtml = '<div class="brikpanel-pl-row-actions">' + parts.join('') + '</div>';
        }

        var handleTitle = PL.i18n.sort_drag_handle || 'Drag to reorder';
        // Span instead of <button> on purpose: jQuery UI Sortable's default
        // `cancel` selector includes `button`, which blocks the drag from
        // starting when mousedown lands on a <button> handle. A non-button
        // element sidesteps that without us having to override `cancel`
        // (which would re-enable accidental drags from quick-edit buttons,
        // toggles, etc. inside the row).
        var handleCell =
            '<td class="brikpanel-pl-cell-handle" aria-hidden="true">' +
                '<span class="brikpanel-pl-sort-handle" aria-label="' + escAttr(handleTitle) + '" title="' + escAttr(handleTitle) + '">' +
                    '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="6" r="1.2"/><circle cx="15" cy="6" r="1.2"/><circle cx="9" cy="12" r="1.2"/><circle cx="15" cy="12" r="1.2"/><circle cx="9" cy="18" r="1.2"/><circle cx="15" cy="18" r="1.2"/></svg>' +
                '</span>' +
            '</td>';

        return '<tr class="brikpanel-pl-row" data-id="' + p.id + '" data-edit-url="' + escAttr(p.edit_url) + '">' +
            handleCell +
            '<td class="brikpanel-pl-cell-check"><input type="checkbox" class="brikpanel-pl-row-check brikpanel-pl-checkbox" value="' + p.id + '"' + checked + '></td>' +
            '<td class="brikpanel-pl-cell-image brikpanel-pl-col brikpanel-pl-col-image"><img src="' + escAttr(p.image) + '" alt="" class="brikpanel-pl-thumb" loading="lazy"></td>' +
            '<td class="brikpanel-pl-cell-name brikpanel-pl-col brikpanel-pl-col-name"><a href="' + escAttr(p.edit_url) + '" class="brikpanel-pl-product-name-link"' + (PL.open_in_new_tab ? ' target="_blank" rel="noopener"' : '') + '><span class="brikpanel-pl-product-name-text">' + escHtml(p.name) + '</span></a>' + typeLabel + aseActionsHtml + '</td>' +
            '<td class="brikpanel-pl-cell-sku brikpanel-pl-col brikpanel-pl-col-sku"><span class="brikpanel-pl-editable brikpanel-pl-sku-cell" data-field="sku" data-value="' + escAttr(p.sku || '') + '">' + (p.sku ? escHtml(p.sku) : '<span class="brikpanel-pl-text-muted">—</span>') + '</span></td>' +
            '<td class="brikpanel-pl-cell-price brikpanel-pl-col brikpanel-pl-col-price">' + priceEditable + '</td>' +
            '<td class="brikpanel-pl-cell-stock brikpanel-pl-col brikpanel-pl-col-stock" data-id="' + p.id + '">' + stockCellHtml + '</td>' +
            '<td class="brikpanel-pl-cell-cat brikpanel-pl-col brikpanel-pl-col-category">' + catText + '</td>' +
            '<td class="brikpanel-pl-cell-status brikpanel-pl-col brikpanel-pl-col-status"><span class="brikpanel-pl-status-badge ' + statusClass + '" title="' + escAttr(PL.i18n.click_to_toggle) + '">' + escHtml(statusLabel) + '</span></td>' +
            aseCellsHtml +
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
        closeStockPopover();

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

        // Simple product stock click → rich popover with quantity + status
        if (field === 'stock') {
            openStockPopover($el, productId);
            return;
        }

        var $input = $('<input type="text" class="brikpanel-pl-inline-input" value="' + escAttr(value) + '">');

        $el.data('original-html', $el.html());
        $el.html('').append($input);
        $input.focus().select();
    }

    /* Simple-product stock popover: quantity + status toggle */
    function closeStockPopover() {
        $('.brikpanel-pl-stock-popover').remove();
        $(document).off('click.bplStockPopover');
        $(document).off('keydown.bplStockPopover');
    }

    function openStockPopover($el, productId) {
        var product = getProductById(productId);
        var currentStock = (product && product.stock !== null && product.stock !== '')
            ? String(product.stock) : '';
        var currentStatus = (product && product.stock_status) ? product.stock_status : 'instock';

        var html =
            '<div class="brikpanel-pl-stock-popover" role="dialog" aria-label="' + escAttr(PL.i18n.stock_label || 'Stock') + '">' +
                '<div class="brikpanel-pl-stock-popover-field">' +
                    '<label>' + escHtml(PL.i18n.stock_label || 'Stock') + '</label>' +
                    '<input type="number" min="0" class="brikpanel-pl-stock-popover-qty" value="' + escAttr(currentStock) + '" placeholder="—">' +
                '</div>' +
                '<div class="brikpanel-pl-stock-popover-field">' +
                    '<label>' + escHtml(PL.i18n.stock_status_label || 'Availability') + '</label>' +
                    '<div class="brikpanel-pl-toggle-group" role="radiogroup">' +
                        '<button type="button" class="brikpanel-pl-toggle-opt ' + (currentStatus === 'instock' ? 'is-active' : '') + '" data-value="instock" role="radio" aria-checked="' + (currentStatus === 'instock') + '">' +
                            '<span class="brikpanel-pl-toggle-dot instock"></span>' + escHtml(PL.i18n.in_stock || 'In stock') +
                        '</button>' +
                        '<button type="button" class="brikpanel-pl-toggle-opt ' + (currentStatus === 'outofstock' ? 'is-active' : '') + '" data-value="outofstock" role="radio" aria-checked="' + (currentStatus === 'outofstock') + '">' +
                            '<span class="brikpanel-pl-toggle-dot outofstock"></span>' + escHtml(PL.i18n.out_of_stock || 'Out of stock') +
                        '</button>' +
                    '</div>' +
                '</div>' +
                '<div class="brikpanel-pl-stock-popover-actions">' +
                    '<button type="button" class="brikpanel-pl-btn secondary small brikpanel-pl-stock-popover-cancel">' + escHtml(PL.i18n.cancel || 'Cancel') + '</button>' +
                    '<button type="button" class="brikpanel-pl-btn primary small brikpanel-pl-stock-popover-save">' + escHtml(PL.i18n.save_changes || 'Save') + '</button>' +
                '</div>' +
            '</div>';

        var $pop = $(html);
        $('body').append($pop);

        // Position relative to the clicked cell, using position:fixed so
        // any scroll inside the table/body does not cause drift. Clamp to
        // the viewport on both axes, with a 12px margin.
        var rect = $el[0].getBoundingClientRect();
        var popW = $pop.outerWidth();
        var popH = $pop.outerHeight();
        var margin = 12;
        var left = rect.left;
        if (left + popW + margin > window.innerWidth) {
            left = window.innerWidth - popW - margin;
        }
        if (left < margin) left = margin;
        var top = rect.bottom + 8;
        if (top + popH + margin > window.innerHeight) {
            // Flip above the cell if there is no room below.
            top = rect.top - popH - 8;
            if (top < margin) top = margin;
        }
        $pop.css({position: 'fixed', top: top, left: left});
        $pop.find('.brikpanel-pl-stock-popover-qty').focus().select();

        // Toggle click
        $pop.on('click', '.brikpanel-pl-toggle-opt', function () {
            $pop.find('.brikpanel-pl-toggle-opt').removeClass('is-active').attr('aria-checked', 'false');
            $(this).addClass('is-active').attr('aria-checked', 'true');
        });

        // Save
        $pop.on('click', '.brikpanel-pl-stock-popover-save', function () {
            var qty = $pop.find('.brikpanel-pl-stock-popover-qty').val();
            var status = $pop.find('.brikpanel-pl-toggle-opt.is-active').data('value') || 'instock';
            saveStockPopover(productId, qty, status, $el);
        });
        // Enter in qty field saves
        $pop.on('keydown', '.brikpanel-pl-stock-popover-qty', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                $pop.find('.brikpanel-pl-stock-popover-save').click();
            }
        });
        $pop.on('click', '.brikpanel-pl-stock-popover-cancel', closeStockPopover);

        // Close on outside click
        setTimeout(function () {
            $(document).on('click.bplStockPopover', function (e) {
                if (!$(e.target).closest('.brikpanel-pl-stock-popover, .brikpanel-pl-cell-stock').length) {
                    closeStockPopover();
                }
            });
            $(document).on('keydown.bplStockPopover', function (e) {
                if (e.key === 'Escape') closeStockPopover();
            });
        }, 50);
    }

    function saveStockPopover(productId, qty, status, $cell) {
        var data = {
            action: 'brikpanel_quick_edit_product',
            security: PL.nonce,
            product_id: productId,
            stock: qty,
            stock_status: status
        };

        var $save = $('.brikpanel-pl-stock-popover-save');
        $save.prop('disabled', true).text(PL.i18n.saving || 'Saving...');

        $.ajax({
            url: PL.ajax_url,
            type: 'POST',
            data: data,
            success: function (res) {
                if (res.success) {
                    updateProductInState(res.data.product);
                    var $row = $cell.closest('tr');
                    refreshRow($row, res.data.product);
                    closeStockPopover();
                    showToast(PL.i18n.saved, 'success');
                } else {
                    $save.prop('disabled', false).text(PL.i18n.save_changes || 'Save');
                    showToast(res.data.message || PL.i18n.error, 'error');
                }
            },
            error: function () {
                $save.prop('disabled', false).text(PL.i18n.save_changes || 'Save');
                showToast(PL.i18n.error, 'error');
            }
        });
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
    // QUICK EDIT — DIGITAL DOWNLOADS
    // =========================================================================

    // Opens the WP media library to attach one or more downloadable files
    // to the product currently being quick-edited. Mirrors the editor flow,
    // de-duped by file URL so the same asset cannot be added twice.
    function openQeFilePicker() {
        if (typeof wp === 'undefined' || !wp.media) {
            showToast(PL.i18n.error, 'error');
            return;
        }
        var frame = wp.media({
            title: PL.i18n.select_file || 'Select downloadable file',
            multiple: true,
            button: { text: PL.i18n.select || 'Select' }
        });
        frame.on('select', function () {
            frame.state().get('selection').toJSON().forEach(function (att) {
                addQeDownload({
                    id: '',
                    name: att.title || att.filename || 'File',
                    file: att.url
                });
            });
        });
        frame.open();
    }

    function addQeDownload(d) {
        if (!d || !d.file) return;
        if (state.qeDownloads.some(function (x) { return x.file === d.file; })) return;
        state.qeDownloads.push(d);
        renderQeDownloads();
    }

    function removeQeDownload(idx) {
        state.qeDownloads.splice(idx, 1);
        renderQeDownloads();
    }

    function renderQeDownloads() {
        var $list = $('#bpl-qe-downloads-list').empty();
        if (!state.qeDownloads.length) {
            $list.append('<p class="brikpanel-pl-text-muted">' + escHtml(PL.i18n.no_files || 'No files added yet.') + '</p>');
            return;
        }
        state.qeDownloads.forEach(function (d, idx) {
            var $row = $('<div class="brikpanel-pl-download-item" data-idx="' + idx + '">');
            $row.append('<svg class="brikpanel-pl-download-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>');
            var $info = $('<div class="brikpanel-pl-download-info">');
            $info.append('<input type="text" class="brikpanel-pl-download-name" value="' + escHtml(d.name) + '" placeholder="' + escHtml(PL.i18n.file_name || 'File name') + '">');
            var $urlRow = $('<div class="brikpanel-pl-download-url-row">');
            $urlRow.append('<input type="url" class="brikpanel-pl-download-url" value="' + escHtml(d.file) + '" placeholder="https://…" spellcheck="false">');
            $urlRow.append('<button type="button" class="brikpanel-pl-download-browse" title="' + escHtml(PL.i18n.choose_file || 'Choose file') + '">' +
                '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg>' +
                '</button>');
            $info.append($urlRow);
            $row.append($info);
            var $rm = $('<button type="button" class="brikpanel-pl-download-remove" title="' + escHtml(PL.i18n.remove || 'Remove') + '">&times;</button>');
            $rm.on('click', function () { removeQeDownload(idx); });
            $row.append($rm);
            $list.append($row);
        });

        $list.find('.brikpanel-pl-download-name').on('input', function () {
            var idx = parseInt($(this).closest('.brikpanel-pl-download-item').data('idx'), 10);
            if (state.qeDownloads[idx]) state.qeDownloads[idx].name = this.value;
        });

        $list.find('.brikpanel-pl-download-url').on('input', function () {
            var idx = parseInt($(this).closest('.brikpanel-pl-download-item').data('idx'), 10);
            if (state.qeDownloads[idx]) state.qeDownloads[idx].file = this.value;
        });

        $list.find('.brikpanel-pl-download-browse').on('click', function (e) {
            e.preventDefault();
            if (typeof wp === 'undefined' || !wp.media) return;
            var $item = $(this).closest('.brikpanel-pl-download-item');
            var idx = parseInt($item.data('idx'), 10);
            if (!state.qeDownloads[idx]) return;
            var frame = wp.media({
                title: PL.i18n.select_file || 'Select downloadable file',
                multiple: false,
                button: { text: PL.i18n.select || 'Select' }
            });
            frame.on('select', function () {
                var att = frame.state().get('selection').first().toJSON();
                state.qeDownloads[idx].file = att.url;
                if (!state.qeDownloads[idx].name) {
                    state.qeDownloads[idx].name = att.title || att.filename || 'File';
                }
                renderQeDownloads();
            });
            frame.open();
        });
    }

    // =========================================================================
    // QUICK EDIT DRAWER
    // =========================================================================

    /**
     * Filter a quick-edit term list (category tree or flat tag list) by a
     * case-insensitive name match. For the hierarchical category tree we
     * also re-show every ancestor of a matching node so deeply nested
     * matches don't look orphaned.
     */
    function filterTermList($list, query) {
        query = (query || '').trim().toLowerCase();
        var isFlat = $list.hasClass('brikpanel-pl-qe-term-list-flat');
        if (isFlat) {
            $list.find('.brikpanel-pl-qe-term-item').each(function () {
                var name = ($(this).data('name') || '').toString();
                $(this).toggle(!query || name.indexOf(query) !== -1);
            });
            return;
        }
        var $items = $list.find('li');
        if (!query) {
            $items.show();
            return;
        }
        $items.hide();
        $items.each(function () {
            var name = ($(this).data('name') || '').toString();
            if (name.indexOf(query) !== -1) {
                $(this).show();
                $(this).parents('li').show();
            }
        });
    }

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

        // Sync category / tag checkboxes with current selection
        var catIdSet = {};
        (product.category_ids || []).forEach(function (cid) { catIdSet[String(cid)] = true; });
        $('#bpl-qe-cat-list .bpl-qe-cat-cb').each(function () {
            this.checked = !!catIdSet[String(this.value)];
        });

        var tagIdSet = {};
        (product.tag_ids || []).forEach(function (tid) { tagIdSet[String(tid)] = true; });
        $('#bpl-qe-tag-list .bpl-qe-tag-cb').each(function () {
            this.checked = !!tagIdSet[String(this.value)];
        });

        // Reset term search fields so filters don't carry over between edits
        $('#bpl-qe-cat-search, #bpl-qe-tag-search').val('').trigger('input');

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
            var st = product.stock_status || 'instock';
            $('#bpl-qe-stock-status').val(st);
            var $grp = $('#bpl-qe-stock-status-toggle');
            $grp.find('.brikpanel-pl-toggle-opt').each(function () {
                var active = $(this).data('value') === st;
                $(this).toggleClass('is-active', active).attr('aria-checked', active ? 'true' : 'false');
            });

            // Digital / downloadable fields
            var isDownloadable = !!product.is_downloadable;
            $('#bpl-qe-digital-toggle').prop('checked', isDownloadable);
            $('#bpl-qe-digital-section').toggleClass('open', isDownloadable);
            state.qeDownloads = (product.downloads || []).map(function (d) {
                return { id: d.id || '', name: d.name || '', file: d.file || '' };
            });
            renderQeDownloads();
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

        // Save main product fields (name, sku, status, categories, tags)
        var selectedCats = $('#bpl-qe-cat-list .bpl-qe-cat-cb:checked').map(function () {
            return parseInt(this.value, 10);
        }).get();
        var selectedTags = $('#bpl-qe-tag-list .bpl-qe-tag-cb:checked').map(function () {
            return parseInt(this.value, 10);
        }).get();

        var mainData = {
            action: 'brikpanel_quick_edit_product',
            security: PL.nonce,
            product_id: id,
            name: $('#bpl-qe-name').val(),
            sku: $('#bpl-qe-sku').val(),
            status: $('#bpl-qe-status').val(),
            // CSV strings (rather than array[]=) so an empty selection still
            // round-trips to the server and clears the taxonomy.
            category_ids_csv: selectedCats.join(','),
            tag_ids_csv: selectedTags.join(',')
        };

        if (!isVariable) {
            mainData.regular_price = $('#bpl-qe-price').val();
            mainData.sale_price = $('#bpl-qe-sale-price').val();
            mainData.stock = $('#bpl-qe-stock').val();
            mainData.stock_status = $('#bpl-qe-stock-status').val();

            // Digital / downloadable. Only send fields when toggle is rendered
            // (drawer state — variable products don't render this section).
            mainData.is_downloadable = $('#bpl-qe-digital-toggle').is(':checked') ? 1 : 0;
            mainData.downloads = mainData.is_downloadable
                ? JSON.stringify(state.qeDownloads)
                : '[]';
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

    function bulkAction(action, $btn) {
        if (!state.selected.length) return;

        var confirmMsg = PL.i18n.confirm_bulk.replace('%d', state.selected.length);
        if (action === 'trash') {
            confirmMsg = PL.i18n.confirm_bulk_trash.replace('%d', state.selected.length);
        }
        if (action === 'delete') {
            confirmMsg = PL.i18n.confirm_bulk_delete_perm.replace('%d', state.selected.length);
            if (!confirm(confirmMsg)) return;
            if (!confirm(PL.i18n.confirm_bulk_delete_perm_2)) return;
        } else {
            if (!confirm(confirmMsg)) return;
        }

        setBulkLoading(true, $btn);

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
                setBulkLoading(false);
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
                setBulkLoading(false);
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

        // Show/hide private tab (inserted before trash tab if present)
        if (counts.private > 0) {
            if (!$('[data-count="private"]').length) {
                var $privateTab = $(
                    '<button class="brikpanel-pl-tab' + (state.status === 'private' ? ' active' : '') + '" data-status="private">' +
                    (PL.i18n.private_status || 'Private') + ' <span class="brikpanel-pl-tab-count" data-count="private">' + counts.private + '</span></button>'
                );
                var $trashTab = $('[data-status="trash"]');
                if ($trashTab.length) {
                    $privateTab.insertBefore($trashTab);
                } else {
                    $('.brikpanel-pl-tabs').append($privateTab);
                }
            } else {
                $('[data-count="private"]').text(counts.private);
            }
        } else {
            $('[data-status="private"]').remove();
        }

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
        // Create overlay + popup (styles live in brikpanel-products-list.css)
        var $overlay = $('<div class="brikpanel-pl-var-overlay"></div>');
        var popupClass = 'brikpanel-pl-var-popup' + (field === 'price' ? ' is-price' : '');
        var $popup = $('<div class="' + popupClass + '"><div class="brikpanel-pl-var-popup-loading"><div class="brikpanel-pl-spinner"></div></div></div>');

        $('body').append($overlay).append($popup);

        // Close on overlay click
        $overlay.on('click', function () {
            closeVariationPopup();
        });

        // Escape key
        $(document).on('keydown.varpopup', function (e) {
            if (e.key === 'Escape') { closeVariationPopup(); }
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
        var html = '<div class="brikpanel-pl-var-popup-header">' +
            '<span class="brikpanel-pl-var-popup-title">' + escHtml(title) + '</span>' +
            '<button class="brikpanel-pl-var-popup-close" aria-label="Close">&times;</button></div>';

        html += '<div class="brikpanel-pl-var-popup-body">';
        for (var i = 0; i < variations.length; i++) {
            var v = variations[i];
            html += '<div class="brikpanel-pl-var-popup-row" data-var-id="' + v.id + '">';
            html += '<div class="brikpanel-pl-var-popup-name">' + escHtml(v.name) + '</div>';

            if (field === 'stock') {
                var stockVal = v.stock !== null ? v.stock : '';
                html += '<div class="brikpanel-pl-var-popup-fields">';
                html += '<input type="number" class="brikpanel-pl-var-popup-input" data-field="stock" value="' + escAttr(stockVal) + '" min="0" placeholder="—">';
                html += '</div>';
            } else {
                html += '<div class="brikpanel-pl-var-popup-fields">';
                html += '<input type="text" class="brikpanel-pl-var-popup-input" data-field="regular_price" value="' + escAttr(v.regular_price || '') + '" placeholder="' + escAttr(PL.i18n.price_label) + '">';
                html += '<input type="text" class="brikpanel-pl-var-popup-input sale" data-field="sale_price" value="' + escAttr(v.sale_price || '') + '" placeholder="' + escAttr(PL.i18n.sale_label) + '">';
                html += '<input type="text" class="brikpanel-pl-var-popup-input" data-field="sale_from" value="' + escAttr(v.sale_from || '') + '" placeholder="Sale start YYYY-MM-DD">';
                html += '<input type="text" class="brikpanel-pl-var-popup-input" data-field="sale_to" value="' + escAttr(v.sale_to || '') + '" placeholder="Sale end YYYY-MM-DD">';
                html += '</div>';
            }
            html += '</div>';
        }
        html += '</div>';

        // Footer with Apply button
        html += '<div class="brikpanel-pl-var-popup-footer">' +
            '<button class="brikpanel-pl-btn secondary small brikpanel-pl-var-popup-cancel">' + escHtml(PL.i18n.cancel || 'Cancel') + '</button>' +
            '<button class="brikpanel-pl-btn primary small brikpanel-pl-var-popup-apply" data-parent="' + productId + '">' + escHtml(PL.i18n.apply) + '</button>' +
            '</div>';

        $popup.html(html);

        // Stock status toggle click — update hidden input on selection
        $popup.on('click', '.brikpanel-pl-toggle-opt', function () {
            var $grp = $(this).closest('.brikpanel-pl-toggle-group');
            $grp.find('.brikpanel-pl-toggle-opt').removeClass('is-active');
            $(this).addClass('is-active');
            var val = $(this).data('value');
            $grp.closest('.brikpanel-pl-var-popup-row').find('input[data-field="stock_status"]').val(val);
        });

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
            var vStatus = v.stock_status || 'instock';
            html += '<div class="brikpanel-pl-qe-var-item" data-var-id="' + v.id + '">';
            html += '<div class="brikpanel-pl-qe-var-name">' + escHtml(v.name) + '</div>';
            html += '<div class="brikpanel-pl-qe-var-fields">';
            html += '<div class="brikpanel-pl-qe-field"><label>' + escHtml(PL.i18n.price_label || 'Price') + '</label><input type="text" class="brikpanel-pl-qe-var-input" data-field="regular_price" value="' + escAttr(v.regular_price || '') + '"></div>';
            html += '<div class="brikpanel-pl-qe-field"><label>' + escHtml(PL.i18n.sale_label || 'Sale') + '</label><input type="text" class="brikpanel-pl-qe-var-input" data-field="sale_price" value="' + escAttr(v.sale_price || '') + '"></div>';
            html += '<div class="brikpanel-pl-qe-field"><label>' + escHtml(PL.i18n.stock_label || 'Stock') + '</label><input type="number" class="brikpanel-pl-qe-var-input" data-field="stock" value="' + escAttr(stockVal) + '" min="0"></div>';
            html += '</div>';
            html += '<div class="brikpanel-pl-qe-field"><label>' + escHtml(PL.i18n.stock_status_label || 'Availability') + '</label>';
            html += '<div class="brikpanel-pl-toggle-group" role="radiogroup">' +
                '<button type="button" class="brikpanel-pl-toggle-opt ' + (vStatus === 'instock' ? 'is-active' : '') + '" data-value="instock"><span class="brikpanel-pl-toggle-dot instock"></span>' + escHtml(PL.i18n.in_stock || 'In stock') + '</button>' +
                '<button type="button" class="brikpanel-pl-toggle-opt ' + (vStatus === 'outofstock' ? 'is-active' : '') + '" data-value="outofstock"><span class="brikpanel-pl-toggle-dot outofstock"></span>' + escHtml(PL.i18n.out_of_stock || 'Out of stock') + '</button>' +
                '<button type="button" class="brikpanel-pl-toggle-opt ' + (vStatus === 'onbackorder' ? 'is-active' : '') + '" data-value="onbackorder"><span class="brikpanel-pl-toggle-dot onbackorder"></span>' + escHtml(PL.i18n.on_backorder || 'On backorder') + '</button>' +
                '</div>';
            html += '<input type="hidden" class="brikpanel-pl-qe-var-input" data-field="stock_status" value="' + escAttr(vStatus) + '">';
            html += '</div>';
            html += '</div>';
        }
        html += '</div>';

        $container.html(html).data('parent-id', productId);

        // Toggle click handler for each variation row
        $container.on('click', '.brikpanel-pl-qe-var-item .brikpanel-pl-toggle-opt', function () {
            var $grp = $(this).closest('.brikpanel-pl-toggle-group');
            $grp.find('.brikpanel-pl-toggle-opt').removeClass('is-active');
            $(this).addClass('is-active');
            $grp.siblings('input[data-field="stock_status"]').val($(this).data('value'));
        });
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
        $('#bpl-bulk-modal-close, #bpl-bulk-modal-cancel').on('click', closeBulkModal);
        $('#bpl-bulk-modal-overlay').on('click', function (e) {
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

        // Scope toggle (all / category)
        $('#bpl-bulk-scope').on('change', function () {
            var scope = $(this).val();
            $('#bpl-bulk-cat-wrap').toggle(scope === 'category');
            if (scope === 'all') {
                // Load variation attributes across the whole store
                loadVariationAttributes(0);
            } else {
                loadVariationAttributes($('#bpl-bulk-cat').val());
            }
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

        // Fast delete toggle: force permanent, disable image cleanup, show warning.
        $('#bpl-del-fast').on('change', function () {
            var on = $(this).is(':checked');
            $('#bpl-del-fast-warning').toggle(on);
            if (on) {
                $('#bpl-del-permanent').prop('checked', true).prop('disabled', true);
                $('#bpl-del-images').prop('checked', false).prop('disabled', true);
            } else {
                $('#bpl-del-permanent').prop('disabled', false);
                $('#bpl-del-images').prop('disabled', false);
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

        // Reset scope selector to category (default)
        $('#bpl-bulk-scope').val('category').trigger('change');

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

        // Empty string or null means "no scope" → clear the dropdown.
        // 0 (numeric) or 'all' means "all products in the store" → query with category=0.
        var catNum = (catId === 0 || catId === '0' || catId === 'all') ? 0 : parseInt(catId, 10);
        var isAll  = (catId === 0 || catId === '0' || catId === 'all');

        if (!isAll && (!catId || isNaN(catNum) || catNum < 1)) {
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
                category: isAll ? 0 : catNum
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
        var params;

        if (activeTab === 'bpl-bulk-tab-cat') {
            var scope = $('#bpl-bulk-scope').val();
            if (scope === 'all') {
                if (!confirm(PL.i18n.bulk_all_confirm)) return;
                params = {
                    mode: 'all',
                    bulk_action: $('#bpl-bulk-action-cat').val(),
                    value: $('#bpl-bulk-value-cat').val(),
                    attr_key: $('#bpl-bulk-attr-key').val() || '',
                    attr_val: $('#bpl-bulk-attr-val').val() || ''
                };
            } else {
                var catId = $('#bpl-bulk-cat').val();
                if (!catId) { showToast(PL.i18n.bulk_select_cat, 'error'); return; }
                if (!confirm(PL.i18n.bulk_cat_confirm)) return;
                params = {
                    mode: 'category',
                    category: catId,
                    bulk_action: $('#bpl-bulk-action-cat').val(),
                    value: $('#bpl-bulk-value-cat').val(),
                    attr_key: $('#bpl-bulk-attr-key').val() || '',
                    attr_val: $('#bpl-bulk-attr-val').val() || ''
                };
            }
        } else {
            if (!state.selected.length) { showToast(PL.i18n.bulk_no_selection, 'error'); return; }
            if (!confirm(PL.i18n.bulk_confirm)) return;
            params = {
                mode: 'selected',
                selected_ids: state.selected.join(','),
                bulk_action: $('#bpl-bulk-action-sel').val(),
                value: $('#bpl-bulk-value-sel').val()
            };
        }

        closeBulkModal();
        runBulkJob('update', params);
    }

    // =========================================================================
    // BULK DELETE
    // =========================================================================

    function applyBulkDelete() {
        var mode = $('#bpl-del-mode').val();
        var catId = $('#bpl-del-cat').val();
        var fast = $('#bpl-del-fast').is(':checked');
        var permanent = fast ? true : $('#bpl-del-permanent').is(':checked');
        var deleteImages = fast ? false : $('#bpl-del-images').is(':checked');
        var delCats = $('#bpl-del-cats').is(':checked');
        var delTags = $('#bpl-del-tags').is(':checked');
        var delAttrs = $('#bpl-del-attrs').is(':checked');
        var delBrands = $('#bpl-del-brands').is(':checked');
        var hasTaxPurge = delCats || delTags || delAttrs || delBrands;

        // Decide whether products will actually be deleted.
        var willDeleteProducts =
            (mode === 'selected' && state.selected.length > 0) ||
            (mode === 'category' && !!catId) ||
            (mode === 'all');

        if (!willDeleteProducts && !hasTaxPurge) {
            if (mode === 'selected') {
                showToast(PL.i18n.bulk_no_selection, 'error');
            } else if (mode === 'category') {
                showToast(PL.i18n.bulk_select_cat, 'error');
            } else {
                showToast(PL.i18n.error, 'error');
            }
            return;
        }

        if (willDeleteProducts) {
            var confirmMsg = PL.i18n.delete_confirm_1 || 'Are you sure?';
            if (mode === 'all') {
                confirmMsg = PL.i18n.delete_confirm_all || confirmMsg;
            }
            if (!confirm(confirmMsg)) return;

            if (permanent) {
                var confirmMsg2 = PL.i18n.delete_confirm_2 || 'PERMANENT DELETE — cannot be undone.';
                if (!confirm(confirmMsg2)) return;
            }

            if (fast) {
                var fastMsg = PL.i18n.fast_delete_confirm || 'FAST DELETE — irreversible. Continue?';
                if (!confirm(fastMsg)) return;
            }
        } else if (hasTaxPurge) {
            var taxOnlyMsg = PL.i18n.tax_only_confirm || 'Wipe the selected taxonomies (categories/tags/attributes/brands)? This cannot be undone.';
            if (!confirm(taxOnlyMsg)) return;
        }

        var params = {
            mode: mode,
            category: catId || '',
            selected_ids: mode === 'selected' ? state.selected.join(',') : '',
            permanent: permanent ? '1' : '0',
            delete_images: deleteImages ? '1' : '0',
            fast: fast ? '1' : '0',
            delete_cats: delCats ? '1' : '0',
            delete_tags: delTags ? '1' : '0',
            delete_attrs: delAttrs ? '1' : '0',
            delete_brands: delBrands ? '1' : '0'
        };

        closeBulkModal();
        runBulkJob('delete', params);
    }

    // =========================================================================
    // BULK JOB RUNNER (batched AJAX loop with progress UI)
    // =========================================================================

    var bulkJob = { id: null, cancelled: false, totalErrors: 0 };

    function openProgressModal(jobType, totalKnown) {
        var title = jobType === 'delete' ? PL.i18n.bulk_title_delete : PL.i18n.bulk_title_update;
        $('#bpl-progress-title').text(title || PL.i18n.applying);
        $('#bpl-progress-stats-text').text(totalKnown ? ('0 / ' + totalKnown) : PL.i18n.bulk_preparing);
        $('#bpl-progress-percent').text('0%');
        $('#bpl-progress-fill').css('width', '0%');
        $('#bpl-progress-errors').hide().text('');
        $('#bpl-progress-cancel').show().prop('disabled', false);
        $('#bpl-progress-done').hide();
        $('#bpl-progress-backdrop').addClass('open').attr('aria-hidden', 'false');
    }

    function updateProgressModal(processed, total) {
        var pct = total > 0 ? Math.floor((processed / total) * 100) : 0;
        $('#bpl-progress-fill').css('width', pct + '%');
        $('#bpl-progress-stats-text').text(
            (PL.i18n.bulk_progress || '%1$d / %2$d').replace('%1$d', processed).replace('%2$d', total)
        );
        $('#bpl-progress-percent').text(pct + '%');
    }

    function finishProgressModal(jobType, processed, errorCount, cancelled) {
        $('#bpl-progress-cancel').hide();
        $('#bpl-progress-done').show().prop('disabled', false);

        if (cancelled) {
            $('#bpl-progress-title').text(PL.i18n.bulk_cancelled || 'Cancelled');
        } else {
            var template = jobType === 'delete' ? PL.i18n.bulk_complete_delete : PL.i18n.bulk_complete_update;
            $('#bpl-progress-title').text((template || '%d items').replace('%d', processed));
        }

        if (errorCount > 0) {
            $('#bpl-progress-errors')
                .text((PL.i18n.bulk_errors_count || '%d errors').replace('%d', errorCount))
                .show();
        }
    }

    function closeProgressModal() {
        $('#bpl-progress-backdrop').removeClass('open').attr('aria-hidden', 'true');
    }

    function runBulkJob(jobType, params) {
        bulkJob = { id: null, cancelled: false, totalErrors: 0 };
        openProgressModal(jobType, 0);

        var prepareData = $.extend({
            action: 'brikpanel_bulk_job_prepare',
            security: PL.nonce,
            job_type: jobType
        }, params);

        $.ajax({
            url: PL.ajax_url, type: 'POST', data: prepareData, dataType: 'json'
        }).done(function (res) {
            if (!res || !res.success) {
                closeProgressModal();
                showToast((res && res.data && res.data.message) || PL.i18n.error, 'error');
                return;
            }
            bulkJob.id = res.data.job_id;
            bulkJob.total = res.data.total;
            updateProgressModal(0, bulkJob.total);
            processNextBatch(jobType, 0, 0, 0);
        }).fail(function () {
            closeProgressModal();
            showToast(PL.i18n.bulk_failed || PL.i18n.error, 'error');
        });
    }

    function processNextBatch(jobType, offset, processedSoFar, retryCount) {
        if (bulkJob.cancelled || !bulkJob.id) {
            return;
        }

        $.ajax({
            url: PL.ajax_url,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'brikpanel_bulk_job_process',
                security: PL.nonce,
                job_id: bulkJob.id,
                offset: offset
            }
        }).done(function (res) {
            if (bulkJob.cancelled) return;
            if (!res || !res.success) {
                var msg = (res && res.data && res.data.message) || PL.i18n.error;
                var expired = res && res.data && res.data.expired;
                bulkJob.id = null;
                finishProgressModal(jobType, processedSoFar, bulkJob.totalErrors, false);
                showToast(msg, 'error');
                if (expired) { fetchProducts(); }
                return;
            }

            var d = res.data;
            processedSoFar += d.processed || 0;
            if (d.errors && d.errors.length) {
                bulkJob.totalErrors += d.errors.length;
            }
            updateProgressModal(processedSoFar, d.total);

            if (d.done) {
                finishProgressModal(jobType, processedSoFar, bulkJob.totalErrors, false);
                state.selected = [];
                updateBulkBar();
                fetchProducts();
                return;
            }

            setTimeout(function () {
                processNextBatch(jobType, d.next_offset, processedSoFar, 0);
            }, 120);
        }).fail(function () {
            if (bulkJob.cancelled) return;
            if (retryCount < 3) {
                var delays = [500, 1500, 4000];
                showToast(PL.i18n.bulk_retrying || 'Retrying...', 'error');
                setTimeout(function () {
                    processNextBatch(jobType, offset, processedSoFar, retryCount + 1);
                }, delays[retryCount]);
                return;
            }
            bulkJob.id = null;
            finishProgressModal(jobType, processedSoFar, bulkJob.totalErrors, false);
            showToast(PL.i18n.bulk_failed || PL.i18n.error, 'error');
        });
    }

    function cancelBulkJob() {
        if (!bulkJob.id) { closeProgressModal(); return; }
        if (!confirm(PL.i18n.bulk_confirm_cancel || 'Cancel the running job?')) return;
        bulkJob.cancelled = true;
        var jobId = bulkJob.id;
        bulkJob.id = null;
        $('#bpl-progress-cancel').prop('disabled', true);
        $.ajax({
            url: PL.ajax_url, type: 'POST',
            data: {
                action: 'brikpanel_bulk_job_cancel',
                security: PL.nonce,
                job_id: jobId
            }
        }).always(function () {
            finishProgressModal(null, 0, bulkJob.totalErrors, true);
            fetchProducts();
        });
    }

    $(document).on('click', '#bpl-progress-cancel', cancelBulkJob);
    $(document).on('click', '#bpl-progress-done', closeProgressModal);
    $(window).on('beforeunload', function () {
        if (bulkJob.id && navigator.sendBeacon) {
            var fd = new FormData();
            fd.append('action', 'brikpanel_bulk_job_cancel');
            fd.append('security', PL.nonce);
            fd.append('job_id', bulkJob.id);
            navigator.sendBeacon(PL.ajax_url, fd);
        }
    });

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
