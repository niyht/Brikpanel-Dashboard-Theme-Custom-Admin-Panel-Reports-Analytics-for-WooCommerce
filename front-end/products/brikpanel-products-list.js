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
        brand: '',
        stock_filter: '',
        product_type: '',
        featured: '',
        // Map of taxonomy slug -> term slug for Brand/Tag/custom-taxonomy
        // filters carried in from a taxonomy "product count" link. Surfaced
        // as removable chips; sent to the server as `tax_filters`.
        tax_filters: {},
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
    // In-flight fetchProducts() request. Tracked so a rapid filter/sort change
    // (e.g. picking a category, then immediately switching the sort dropdown
    // before the first request returns) aborts the now-stale request instead
    // of being silently dropped — without this the dropdown shows the new
    // value while the table renders the old query's results.
    var currentFetchXhr = null;

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
    // FILTER STATE <-> URL
    // =========================================================================
    // Persist the active search/filter/sort/page in the page URL so the view
    // survives a reload and, crucially, can be returned to from the product
    // editor's "Back to products" link. Each product link carries the current
    // list URL as `bpl_return`; the editor rebuilds its back button from it.
    // Values are re-sanitized server side in ajax_list(), so here they are just
    // plain strings — and they only ever reach the DOM through jQuery .val()
    // (not innerHTML), so there is no injection surface.
    var URL_PARAM_MAP = {
        search:       'bpl_s',
        status:       'bpl_status',
        category:     'bpl_cat',
        brand:        'bpl_brand',
        stock_filter: 'bpl_stock',
        product_type: 'bpl_type',
        featured:     'bpl_featured',
        sort:         'bpl_sort'
    };
    var URL_DEFAULTS = {
        search: '', status: 'any', category: '', brand: '', stock_filter: '',
        product_type: '', featured: '', sort: 'date-desc'
    };
    // Taxonomy-filter URL params (keyed by taxonomy slug) seeded from the DOM.
    var taxParamKeys = [];

    function hasActiveFilters() {
        var keyed = Object.keys(URL_PARAM_MAP).some(function (key) {
            return state[key] !== URL_DEFAULTS[key] && state[key] !== '';
        });
        return keyed || state.page > 1 || Object.keys(state.tax_filters).length > 0;
    }

    // Seed taxonomy filters from the container's data attribute. PHP renders
    // both the chips and this JSON map from the incoming URL, so the two never
    // disagree. The chips' remove buttons are wired up in bindEvents().
    function readTaxFiltersFromDom() {
        var raw = $('#brikpanel-products-list').attr('data-tax-filters');
        if (!raw) { return; }
        try {
            var parsed = JSON.parse(raw);
            if (parsed && typeof parsed === 'object') {
                state.tax_filters = parsed;
                // Remember which URL params are taxonomy filters so syncStateToUrl
                // can drop them once their chip is removed.
                Object.keys(parsed).forEach(function (slug) {
                    if (taxParamKeys.indexOf(slug) === -1) { taxParamKeys.push(slug); }
                });
            }
        } catch (e) {}
    }

    // Read filter params from the current URL into state and reflect them in
    // the filter controls. Runs once on boot, before the first fetch.
    function readStateFromUrl() {
        var params;
        try { params = new URLSearchParams(window.location.search); }
        catch (e) { return; }

        Object.keys(URL_PARAM_MAP).forEach(function (key) {
            var val = params.get(URL_PARAM_MAP[key]);
            if (val !== null) { state[key] = val; }
        });

        var paged = parseInt(params.get('bpl_paged'), 10);
        if (paged > 0) { state.page = paged; }

        $('#bpl-search').val(state.search);
        $('#bpl-cat-filter').val(state.category);
        $('#bpl-brand-filter').val(state.brand);
        $('#bpl-stock-filter').val(state.stock_filter);
        $('#bpl-type-filter').val(state.product_type);
        $('#bpl-featured-filter').val(state.featured);
        $('#bpl-sort').val(state.sort);

        $('.brikpanel-pl-tab').removeClass('active');
        var $tab = $('.brikpanel-pl-tab[data-status="' + state.status + '"]');
        ($tab.length ? $tab : $('.brikpanel-pl-tab[data-status="any"]')).addClass('active');
    }

    // Mirror the active (non-default) filters into the URL via replaceState so
    // a reload or the editor's back link reproduces this exact view. Non-bpl
    // params (e.g. page=brikpanel-products) are preserved untouched.
    function syncStateToUrl() {
        var params;
        try { params = new URLSearchParams(window.location.search); }
        catch (e) { return; }

        Object.keys(URL_PARAM_MAP).forEach(function (key) {
            var param = URL_PARAM_MAP[key];
            if (state[key] !== URL_DEFAULTS[key] && state[key] !== '' && state[key] != null) {
                params.set(param, state[key]);
            } else {
                params.delete(param);
            }
        });

        if (state.page > 1) { params.set('bpl_paged', state.page); }
        else { params.delete('bpl_paged'); }

        // Keep taxonomy-filter params in step with state so a removed chip also
        // disappears from the URL (and thus from a reload / editor back link).
        taxParamKeys.forEach(function (slug) {
            if (state.tax_filters[slug]) { params.set(slug, state.tax_filters[slug]); }
            else { params.delete(slug); }
        });

        var qs = params.toString();
        var newUrl = window.location.pathname + (qs ? '?' + qs : '') + window.location.hash;
        try { window.history.replaceState(null, '', newUrl); } catch (e) {}
    }

    // The list URL to hand the editor as a return target — only when a filter
    // is actually active, so unfiltered views keep clean editor URLs and fall
    // back to the plain product list.
    function currentReturnUrl() {
        return hasActiveFilters() ? window.location.href : '';
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

        // Remove an active taxonomy-filter chip (Brand/Tag/custom).
        $(document).on('click', '.brikpanel-pl-filter-chip-remove', function () {
            var $chip = $(this).closest('.brikpanel-pl-filter-chip');
            var slug = $chip.data('taxonomy');
            if (slug) { delete state.tax_filters[slug]; }
            $chip.remove();
            if ($('#bpl-filter-chips .brikpanel-pl-filter-chip').length === 0) {
                $('#bpl-filter-chips').attr('hidden', 'hidden');
            }
            state.page = 1;
            fetchProducts();
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

        $('#bpl-brand-filter').on('change', function () {
            state.brand = $(this).val();
            state.page = 1;
            fetchProducts();
        });

        // Click a product's category to filter the whole list by it (reuses the
        // category dropdown path so the control and the URL stay in sync).
        $(document).on('click', '.brikpanel-pl-cat-link', function (e) {
            e.preventDefault();
            e.stopPropagation();
            var catId = String($(this).data('cat-id') || '');
            if (!catId) { return; }
            hideCatMorePopover();
            state.category = catId;
            $('#bpl-cat-filter').val(catId);
            state.page = 1;
            fetchProducts();
        });

        $('#bpl-stock-filter').on('change', function () {
            state.stock_filter = $(this).val();
            state.page = 1;
            fetchProducts();
        });

        $('#bpl-type-filter').on('change', function () {
            state.product_type = $(this).val();
            state.page = 1;
            fetchProducts();
        });

        $('#bpl-featured-filter').on('change', function () {
            state.featured = $(this).val();
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
        // Custom taxonomy search inputs (Brands etc.). Delegated so it works
        // even when the drawer is rendered before this binding runs.
        $(document).on('input', '.bpl-qe-tax-search', function () {
            var tax = $(this).data('taxonomy');
            if (!tax) return;
            filterTermList($('#bpl-qe-tax-list-' + tax), $(this).val());
        });

        // Quick-edit stock status toggle
        $(document).on('click', '#bpl-qe-stock-status-toggle .brikpanel-pl-toggle-opt', function () {
            var v = $(this).data('value');
            var $grp = $('#bpl-qe-stock-status-toggle');
            $grp.find('.brikpanel-pl-toggle-opt').removeClass('is-active').attr('aria-checked', 'false');
            $(this).addClass('is-active').attr('aria-checked', 'true');
            $('#bpl-qe-stock-status').val(v);
        });

        // Quick-edit Virtual / Digital toggles. Digital implies Virtual on
        // the wire (a downloadable product has no shipping), so we lock the
        // Virtual switch on when Digital is enabled and free it again when
        // Digital flips off. Mirrors the full product editor's behavior.
        $('#bpl-qe-digital-toggle').on('change', function () {
            $('#bpl-qe-digital-section').toggleClass('open', this.checked);
            syncQeVirtualLock();
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

        // Toggle status. Scheduled products are excluded — a plain click can't
        // express a new publish date, so changing a schedule stays in the full
        // editor. Clicking the "Scheduled" badge opens that product instead.
        $(document).on('click', '.brikpanel-pl-status-badge', function (e) {
            e.stopPropagation();
            var $tr = $(this).closest('tr');
            if ($(this).hasClass('scheduled')) {
                var editUrl = $tr.data('edit-url');
                if (editUrl) {
                    if (PL.open_in_new_tab) { window.open(editUrl, '_blank', 'noopener'); }
                    else { window.location.href = editUrl; }
                }
                return;
            }
            var id = parseInt($tr.data('id'));
            toggleStatus(id);
        });

        // Toggle featured (inline star in the name cell). Optimistic UI:
        // flip immediately, AJAX in the background, revert on error.
        $(document).on('click', '.brikpanel-pl-featured-star', function (e) {
            e.preventDefault();
            e.stopPropagation();
            toggleFeatured($(this));
        });

        // Quick-edit drawer featured star. Same backend and optimistic
        // contract as the row star — flips locally, calls the shared
        // toggle endpoint, and reverts on failure. Both stars (row +
        // drawer) read from state.products.is_featured, so a successful
        // toggle from either surface propagates to the other on next render.
        $(document).on('click', '#bpl-qe-featured-star', function (e) {
            e.preventDefault();
            toggleQeFeatured($(this));
        });

        // Keyboard shortcut: Escape to close drawer
        $(document).on('keydown', function (e) {
            if (e.key === 'Escape' && $('#bpl-drawer').hasClass('open')) {
                closeDrawer();
            }
        });

        // Category "+N" hover/focus popover — floating on <body> so it
        // escapes the table wrapper's overflow clipping. A short close grace
        // period lets the cursor travel from the badge into the popover so the
        // list stays open and can be scrolled/read.
        $(document)
            .on('mouseenter focusin', '.brikpanel-pl-cat-more', function () {
                cancelCatMoreClose();
                showCatMorePopover(this);
            })
            .on('mouseleave focusout', '.brikpanel-pl-cat-more', function () {
                scheduleCatMoreClose();
            });
        $(window).on('scroll resize', hideCatMorePopover);
    }

    var $catMorePop = null;
    var catMoreCloseTimer = null;
    function cancelCatMoreClose() {
        if (catMoreCloseTimer) {
            clearTimeout(catMoreCloseTimer);
            catMoreCloseTimer = null;
        }
    }
    function scheduleCatMoreClose() {
        cancelCatMoreClose();
        catMoreCloseTimer = setTimeout(hideCatMorePopover, 180);
    }
    function showCatMorePopover(el) {
        var raw = el.getAttribute('data-others') || '[]';
        var others;
        try { others = JSON.parse(raw); } catch (err) { others = []; }
        if (!others.length) return;

        if (!$catMorePop) {
            $catMorePop = $('<div class="brikpanel-pl-cat-popover" role="tooltip"></div>').appendTo(document.body);
            // Keep the popover open while the cursor is over it, and close
            // once the cursor leaves it (with the same grace period).
            $catMorePop
                .on('mouseenter', cancelCatMoreClose)
                .on('mouseleave', scheduleCatMoreClose);
        }
        cancelCatMoreClose();
        var html = '<ul class="brikpanel-pl-cat-popover-list">';
        for (var i = 0; i < others.length; i++) {
            var o = others[i];
            // Support both the object shape {name, id} and the legacy string.
            var name = (o && typeof o === 'object') ? o.name : o;
            var id = (o && typeof o === 'object') ? o.id : null;
            html += (id != null)
                ? '<li><a href="#" class="brikpanel-pl-cat-link" data-cat-id="' + escAttr(String(id)) + '">' + escHtml(name) + '</a></li>'
                : '<li>' + escHtml(name) + '</li>';
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
        // 1 sort handle (hidden unless sortMode) + 9 native BrikPanel cols
        // (check, image, name, sku, gtin, price, stock, cat, status) + 1
        // actions col + N ASE extras. The handle <th>/<td> is always present
        // in the DOM so colspan calculations stay stable; CSS hides it when
        // sortMode is off, but it still counts as a real column.
        var extras = state.extraColumns ? Object.keys(state.extraColumns).length : 0;
        // +1 for the opt-in Product Code column when its plugin is active (its
        // <th>/<td> are always in the DOM in that case; CSS hides them when off).
        return 14 + (PL.has_product_code ? 1 : 0) + extras;
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

    function fetchProducts(silent) {
        // Abort any in-flight request — its response would render stale data
        // for the previous filter/sort and overwrite whatever we're about to
        // load. The aborted request's error handler short-circuits on
        // textStatus === 'abort' so we don't flash an error toast.
        if (currentFetchXhr) {
            try { currentFetchXhr.abort(); } catch (e) {}
            currentFetchXhr = null;
        }

        // Keep the URL in step with what we are about to render, so a reload
        // or the editor back link reproduces this exact view.
        syncStateToUrl();

        state.loading = true;
        showProgress();

        var $body = $('#bpl-table-body');
        // `silent` mode: skip the spinner row so an optimistic local update
        // (e.g. just-removed rows after a bulk action) stays visible while
        // the background fetch syncs counts and pagination.
        if (!silent) {
            $body.html('<tr class="brikpanel-pl-loading-row"><td colspan="' + totalColumnCount() + '"><div class="brikpanel-pl-spinner"></div></td></tr>');
        }

        currentFetchXhr = $.ajax({
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
                brand: state.brand,
                stock_filter: state.stock_filter,
                product_type: state.product_type,
                featured: state.featured,
                tax_filters: state.tax_filters,
                sort: state.sort
            },
            // Tolerate responses contaminated by upstream PHP output (notices,
            // deprecations, DB errors or debug echoes printed before the JSON).
            // Without this a single leading warning from a third-party plugin
            // breaks jQuery's parser and the whole list renders as
            // "An error occurred." even though the query succeeded. We strip
            // anything before the first `{` so the JSON tail still parses.
            // Multisite installs are especially prone to this (per-site
            // install/upgrade routines fire extra notices during admin-ajax).
            dataType: 'json',
            dataFilter: function (raw) {
                if (typeof raw !== 'string') return raw;
                var i = raw.indexOf('{');
                return i > 0 ? raw.slice(i) : raw;
            },
            success: function (res) {
                currentFetchXhr = null;
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
            error: function (xhr, textStatus) {
                // Aborted by a follow-up fetchProducts() — the new request
                // owns the loading state and UI, so leave them alone.
                if (textStatus === 'abort') return;
                currentFetchXhr = null;
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

    // Cost-of-goods cell. The server sends a pre-formatted price HTML string
    // (so the currency symbol/format matches wc_price() everywhere else) plus
    // a `partial` flag for variable products where some variations lack a
    // cost — flagged with a small "!" so it doesn't pretend the range is
    // complete. When the column is off for the current user the payload is
    // null and the cell renders an em-dash so it stays a valid <td> if a
    // different admin had the column on in the same page.
    function renderCogsCell(p) {
        var cogs = p ? p.cogs : null;
        var inner = (cogs && cogs.html) ? cogs.html : '<span class="brikpanel-pl-text-muted">&mdash;</span>';
        var flag = '';
        if (cogs && cogs.partial && cogs.missing > 0) {
            var tpl   = PL.i18n.cogs_partial || '%d variations have no cost';
            var label = tpl.replace('%d', cogs.missing);
            flag = ' <span class="brikpanel-pl-cogs-flag" tabindex="0" role="note" aria-label="'
                + escAttr(label) + '" title="' + escAttr(label) + '">!</span>';
        }
        // Variable products: cost is stored per variation (edited via the
        // variation popup / quick edit), so the aggregated parent cell stays
        // read-only. Simple products get an inline-editable cost.
        if (!p || p.type === 'variable') {
            return inner + flag;
        }
        var cogsVal = (p.cogs_value === '' || p.cogs_value == null) ? '' : String(p.cogs_value);
        return '<span class="brikpanel-pl-editable brikpanel-pl-cogs-cell" data-field="cogs_value" data-value="'
            + escAttr(cogsVal) + '">' + inner + '</span>' + flag;
    }

    function renderProductRow(p) {
        var checked = state.selected.indexOf(p.id) > -1 ? ' checked' : '';
        var statusClass, statusLabel, statusTitle = PL.i18n.click_to_toggle;
        if (p.status === 'publish') {
            statusClass = 'published';
            statusLabel = PL.i18n.published;
        } else if (p.status === 'future') {
            // Scheduled: shows the future publish date on hover and is NOT a
            // quick toggle (changing a schedule needs the full editor).
            statusClass = 'scheduled';
            statusLabel = PL.i18n.scheduled || 'Scheduled';
            statusTitle = p.scheduled_label || (PL.i18n.scheduled || 'Scheduled');
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

        // Prefer the explicit struck-regular + sale markup built server-side
        // for on-sale simple products (immune to 3rd-party get_price_html
        // filters that can hide the sale price); fall back to WooCommerce's
        // price_html for everything else (variable ranges, non-sale, etc.).
        var priceDisplay = p.sale_display ? p.sale_display : (p.price_html || '—');
        var isVariable = p.type === 'variable';

        var priceEditable = isVariable ?
            '<span class="brikpanel-pl-editable brikpanel-pl-price-cell" data-field="price" data-value="">' + priceDisplay + '</span>' :
            '<span class="brikpanel-pl-editable brikpanel-pl-price-cell" data-field="price" data-value="' + escAttr(p.regular_price || '') + '">' + priceDisplay + '</span>';

        var catText;
        if (!p.categories.length) {
            catText = '<span class="brikpanel-pl-text-muted">—</span>';
        } else {
            // Primary category is a link that filters the list to it (like the
            // native WooCommerce list). category_ids is parallel to categories.
            var catIds0 = (p.category_ids && p.category_ids.length) ? p.category_ids : [];
            var mainCatId = catIds0.length ? catIds0[0] : null;
            var mainCat = (mainCatId != null)
                ? '<a href="#" class="brikpanel-pl-cat-main brikpanel-pl-cat-link" data-cat-id="' + escAttr(String(mainCatId)) + '">' + escHtml(p.categories[0]) + '</a>'
                : '<span class="brikpanel-pl-cat-main">' + escHtml(p.categories[0]) + '</span>';
            if (p.categories.length === 1) {
                catText = mainCat;
            } else {
                // Pair each overflow category name with its term id (parallel
                // arrays) so the popover can render clickable filter links too.
                var otherNames = p.categories.slice(1);
                var otherIds = catIds0.slice(1);
                var others = otherNames.map(function (name, i) {
                    return { name: name, id: (otherIds[i] != null ? otherIds[i] : null) };
                });
                catText = mainCat +
                    '<span class="brikpanel-pl-cat-more" data-others="' + escAttr(JSON.stringify(others)) + '" tabindex="0" aria-label="' + escAttr((PL.i18n.more_categories || '%d more').replace('%d', others.length)) + '">+' + others.length + '</span>';
            }
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

        // Featured-product star — opt-in via the BrikPanel setting. Renders
        // inline at the start of the product name cell. The click handler
        // calls brikpanel_toggle_featured and flips the UI optimistically.
        var featuredStarHtml = '';
        if (PL.show_featured_star) {
            var isFeatured = !!p.is_featured;
            var starTitle  = isFeatured
                ? (PL.i18n.unmark_featured || 'Featured — click to remove')
                : (PL.i18n.mark_featured || 'Mark as featured');
            featuredStarHtml =
                '<button type="button" class="brikpanel-pl-featured-star' + (isFeatured ? ' is-on' : '') +
                    '" data-id="' + p.id + '" aria-pressed="' + (isFeatured ? 'true' : 'false') + '" title="' + escAttr(starTitle) + '">' +
                    '<svg width="16" height="16" viewBox="0 0 24 24" fill="' + (isFeatured ? 'currentColor' : 'none') +
                        '" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
                        '<polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>' +
                    '</svg>' +
                '</button>';
        }

        // Global Unique ID (GTIN/UPC/EAN/ISBN) cell. Hidden by default; the
        // value object is built server-side and already accounts for variable
        // products (single shared value vs. an "N GTINs" multi badge).
        var gid = p.global_unique_id || { value: '', tooltip: '', multi: false };
        var gidInner = gid.value
            ? (gid.multi
                ? '<span class="brikpanel-pl-gid-multi" title="' + escAttr(gid.tooltip) + '">' + escHtml(gid.value) + '</span>'
                : escHtml(gid.value))
            : '<span class="brikpanel-pl-text-muted">—</span>';

        // Product Code cell — rendered only when the "Product Code for
        // WooCommerce" plugin is active. Same compact "N codes" badge shape as
        // the GTIN cell for variable products whose codes live on variations.
        var pcodeCell = '';
        if (PL.has_product_code) {
            var pc = p.product_code || { value: '', tooltip: '', multi: false };
            var pcInner = pc.value
                ? (pc.multi
                    ? '<span class="brikpanel-pl-gid-multi" title="' + escAttr(pc.tooltip) + '">' + escHtml(pc.value) + '</span>'
                    : escHtml(pc.value))
                : '<span class="brikpanel-pl-text-muted">—</span>';
            pcodeCell = '<td class="brikpanel-pl-cell-pcode brikpanel-pl-col brikpanel-pl-col-product_code">' + pcInner + '</td>';
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

        // Carry the active filtered list URL into the editor so its "Back to
        // products" link returns to this exact view instead of the full list.
        var ret = currentReturnUrl();
        var editHref = ret
            ? p.edit_url + (p.edit_url.indexOf('?') > -1 ? '&' : '?') + 'bpl_return=' + encodeURIComponent(ret)
            : p.edit_url;

        return '<tr class="brikpanel-pl-row" data-id="' + p.id + '" data-edit-url="' + escAttr(editHref) + '">' +
            handleCell +
            '<td class="brikpanel-pl-cell-check"><input type="checkbox" class="brikpanel-pl-row-check brikpanel-pl-checkbox" value="' + p.id + '"' + checked + '></td>' +
            '<td class="brikpanel-pl-cell-image brikpanel-pl-col brikpanel-pl-col-image"><img src="' + escAttr(p.image) + '" alt="" class="brikpanel-pl-thumb" loading="lazy"></td>' +
            '<td class="brikpanel-pl-cell-name brikpanel-pl-col brikpanel-pl-col-name"><span class="brikpanel-pl-name-id" title="' + escAttr(PL.i18n.product_id || '') + '">#' + p.id + '</span>' + featuredStarHtml + '<a href="' + escAttr(editHref) + '" class="brikpanel-pl-product-name-link"' + (PL.open_in_new_tab ? ' target="_blank" rel="noopener"' : '') + '><span class="brikpanel-pl-product-name-text">' + escHtml(p.name) + '</span></a>' + typeLabel + aseActionsHtml + '</td>' +
            '<td class="brikpanel-pl-cell-sku brikpanel-pl-col brikpanel-pl-col-sku"><span class="brikpanel-pl-editable brikpanel-pl-sku-cell" data-field="sku" data-value="' + escAttr(p.sku || '') + '">' + (p.sku ? escHtml(p.sku) : '<span class="brikpanel-pl-text-muted">—</span>') + '</span></td>' +
            '<td class="brikpanel-pl-cell-guid brikpanel-pl-col brikpanel-pl-col-global_unique_id">' + gidInner + '</td>' +
            pcodeCell +
            '<td class="brikpanel-pl-cell-price brikpanel-pl-col brikpanel-pl-col-price">' + priceEditable + '</td>' +
            '<td class="brikpanel-pl-cell-cogs brikpanel-pl-col brikpanel-pl-col-cogs">' + renderCogsCell(p) + '</td>' +
            '<td class="brikpanel-pl-cell-stock brikpanel-pl-col brikpanel-pl-col-stock" data-id="' + p.id + '">' + stockCellHtml + '</td>' +
            '<td class="brikpanel-pl-cell-cat brikpanel-pl-col brikpanel-pl-col-category">' + catText + '</td>' +
            '<td class="brikpanel-pl-cell-shipclass brikpanel-pl-col brikpanel-pl-col-shipping_class">' + (p.shipping_class ? escHtml(p.shipping_class) : '<span class="brikpanel-pl-text-muted">—</span>') + '</td>' +
            '<td class="brikpanel-pl-cell-author brikpanel-pl-col brikpanel-pl-col-author">' + (p.author ? escHtml(p.author) : '<span class="brikpanel-pl-text-muted">—</span>') + '</td>' +
            '<td class="brikpanel-pl-cell-menu_order brikpanel-pl-col brikpanel-pl-col-menu_order"><span class="brikpanel-pl-editable brikpanel-pl-menu-order-cell" data-field="menu_order" data-value="' + escAttr(p.menu_order != null ? p.menu_order : 0) + '">' + escHtml(String(p.menu_order != null ? p.menu_order : 0)) + '</span></td>' +
            '<td class="brikpanel-pl-cell-status brikpanel-pl-col brikpanel-pl-col-status"><span class="brikpanel-pl-status-badge ' + statusClass + '" title="' + escAttr(statusTitle) + '">' + escHtml(statusLabel) + '</span></td>' +
            '<td class="brikpanel-pl-cell-date brikpanel-pl-col brikpanel-pl-col-date">' + (p.date ? escHtml(p.date) : '<span class="brikpanel-pl-text-muted">—</span>') + '</td>' +
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
        closePricePopover();

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

        // Simple product price click → popover with regular + sale price so
        // both can be edited from the list (not just the regular price).
        if (field === 'price') {
            openPricePopover($el, productId);
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

    /* Simple-product price popover: regular + sale price. Variable products
       route to the variation popup instead (per-variation pricing). Reuses the
       stock popover's visual classes so no extra CSS is needed. */
    function closePricePopover() {
        $('.brikpanel-pl-price-popover').remove();
        $(document).off('click.bplPricePopover');
        $(document).off('keydown.bplPricePopover');
    }

    function openPricePopover($el, productId) {
        closeStockPopover();
        var product = getProductById(productId);
        var reg  = (product && product.regular_price != null) ? String(product.regular_price) : '';
        var sale = (product && product.sale_price != null) ? String(product.sale_price) : '';

        var html =
            '<div class="brikpanel-pl-stock-popover brikpanel-pl-price-popover" role="dialog" aria-label="' + escAttr(PL.i18n.price_label || 'Price') + '">' +
                '<div class="brikpanel-pl-stock-popover-field">' +
                    '<label>' + escHtml(PL.i18n.price_label || 'Price') + '</label>' +
                    '<input type="text" inputmode="decimal" class="brikpanel-pl-stock-popover-qty brikpanel-pl-price-popover-regular" value="' + escAttr(reg) + '" placeholder="0">' +
                '</div>' +
                '<div class="brikpanel-pl-stock-popover-field">' +
                    '<label>' + escHtml(PL.i18n.sale_label || 'Sale') + '</label>' +
                    '<input type="text" inputmode="decimal" class="brikpanel-pl-stock-popover-qty brikpanel-pl-price-popover-sale" value="' + escAttr(sale) + '" placeholder="—">' +
                '</div>' +
                '<div class="brikpanel-pl-stock-popover-actions">' +
                    '<button type="button" class="brikpanel-pl-btn secondary small brikpanel-pl-price-popover-cancel">' + escHtml(PL.i18n.cancel || 'Cancel') + '</button>' +
                    '<button type="button" class="brikpanel-pl-btn primary small brikpanel-pl-price-popover-save">' + escHtml(PL.i18n.save_changes || 'Save') + '</button>' +
                '</div>' +
            '</div>';

        var $pop = $(html);
        $('body').append($pop);

        // Position relative to the clicked cell with position:fixed so table or
        // body scroll cannot cause drift. Clamp to the viewport, flip above when
        // there is no room below. Mirrors the stock popover.
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
            top = rect.top - popH - 8;
            if (top < margin) top = margin;
        }
        $pop.css({position: 'fixed', top: top, left: left});
        $pop.find('.brikpanel-pl-price-popover-regular').focus().select();

        $pop.on('click', '.brikpanel-pl-price-popover-save', function () {
            var regVal  = $pop.find('.brikpanel-pl-price-popover-regular').val();
            var saleVal = $pop.find('.brikpanel-pl-price-popover-sale').val();
            savePricePopover(productId, regVal, saleVal, $el);
        });
        $pop.on('keydown', 'input', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                $pop.find('.brikpanel-pl-price-popover-save').click();
            }
        });
        $pop.on('click', '.brikpanel-pl-price-popover-cancel', closePricePopover);

        setTimeout(function () {
            $(document).on('click.bplPricePopover', function (e) {
                if (!$(e.target).closest('.brikpanel-pl-price-popover, .brikpanel-pl-cell-price').length) {
                    closePricePopover();
                }
            });
            $(document).on('keydown.bplPricePopover', function (e) {
                if (e.key === 'Escape') closePricePopover();
            });
        }, 50);
    }

    function savePricePopover(productId, regVal, saleVal, $cell) {
        var data = {
            action: 'brikpanel_quick_edit_product',
            security: PL.nonce,
            product_id: productId,
            regular_price: regVal,
            sale_price: saleVal
        };
        var $save = $('.brikpanel-pl-price-popover-save');
        $save.prop('disabled', true).text(PL.i18n.saving || 'Saving...');

        $.ajax({
            url: PL.ajax_url,
            type: 'POST',
            data: data,
            success: function (res) {
                if (res.success) {
                    updateProductInState(res.data.product);
                    refreshRow($cell.closest('tr'), res.data.product);
                    closePricePopover();
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
        } else if (field === 'menu_order') {
            data.menu_order = newValue;
        } else if (field === 'cogs_value') {
            data.cogs_value = newValue;
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
    function syncQeVirtualLock() {
        var digitalOn = $('#bpl-qe-digital-toggle').is(':checked');
        var $virtual = $('#bpl-qe-virtual-toggle');
        if (digitalOn) {
            $virtual.prop('checked', true).prop('disabled', true);
        } else {
            $virtual.prop('disabled', false);
        }
    }

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

        // Featured star — gated by the same opt-in setting as the row star;
        // when the option is off the button isn't rendered at all.
        var $qeStar = $('#bpl-qe-featured-star');
        if ($qeStar.length) {
            $qeStar.data('id', product.id).attr('data-id', product.id);
            applyQeFeaturedState($qeStar, !!product.is_featured);
        }

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

        // Custom taxonomies (Brands etc.). Clear every rendered taxonomy list
        // first so a product that has no terms in a taxonomy ends up with all
        // checkboxes unchecked, then re-check the assigned ones.
        $('.bpl-qe-tax-list .bpl-qe-tax-cb').prop('checked', false);
        var customTaxes = product.custom_taxonomies || {};
        Object.keys(customTaxes).forEach(function (tax) {
            var idSet = {};
            (customTaxes[tax] || []).forEach(function (tid) { idSet[String(tid)] = true; });
            $('#bpl-qe-tax-list-' + tax + ' .bpl-qe-tax-cb').each(function () {
                this.checked = !!idSet[String(this.value)];
            });
        });

        // Reset term search fields so filters don't carry over between edits
        $('#bpl-qe-cat-search, #bpl-qe-tag-search, .bpl-qe-tax-search').val('').trigger('input');

        // Variable vs Simple product handling. Simple-only fields are tagged
        // with `.brikpanel-pl-qe-simple-only` so they can be reordered freely
        // alongside the always-visible fields and still toggle as a group
        // when the user opens a variable product.
        if (product.type === 'variable') {
            $('.brikpanel-pl-qe-simple-only').hide();
            $('#bpl-qe-variations').show();
            loadDrawerVariations(product.id);
        } else {
            $('.brikpanel-pl-qe-simple-only').show();
            $('#bpl-qe-variations').hide().empty();
            $('#bpl-qe-price').val(product.regular_price || '');
            $('#bpl-qe-sale-price').val(product.sale_price || '');
            $('#bpl-qe-stock').val(product.stock !== null ? product.stock : '');
            // COGS — only writes when the field is rendered (slug visible).
            // Avoid `|| ''` here: a legit zero cost arrives as either the
            // string "0" or the JS number 0 depending on whether WC's native
            // COGS returns a float — both are falsy under `||` and would
            // silently blank the input, making "0" look unsaved on reopen.
            $('#bpl-qe-cogs').val((product.cogs_value === '' || product.cogs_value == null) ? '' : String(product.cogs_value));
            var st = product.stock_status || 'instock';
            $('#bpl-qe-stock-status').val(st);
            var $grp = $('#bpl-qe-stock-status-toggle');
            $grp.find('.brikpanel-pl-toggle-opt').each(function () {
                var active = $(this).data('value') === st;
                $(this).toggleClass('is-active', active).attr('aria-checked', active ? 'true' : 'false');
            });

            // Virtual / Digital / downloadable fields
            var isDownloadable = !!product.is_downloadable;
            var isVirtual      = !!product.is_virtual || isDownloadable;
            $('#bpl-qe-virtual-toggle').prop('checked', isVirtual);
            $('#bpl-qe-digital-toggle').prop('checked', isDownloadable);
            $('#bpl-qe-digital-section').toggleClass('open', isDownloadable);
            syncQeVirtualLock();
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

        // Save main product fields. Each block runs only when its drawer
        // field is actually rendered — fields hidden via the QE field-order
        // panel are absent from the DOM and must not be sent (an empty CSV
        // would wipe category/tag assignments; an unchecked toggle would
        // clear is_virtual / is_downloadable; etc.).
        var mainData = {
            action: 'brikpanel_quick_edit_product',
            security: PL.nonce,
            product_id: id
        };

        var $nameField = $('#bpl-qe-name');
        if ($nameField.length) {
            mainData.name = $nameField.val();
        }
        var $skuField = $('#bpl-qe-sku');
        if ($skuField.length) {
            mainData.sku = $skuField.val();
        }
        var $statusField = $('#bpl-qe-status');
        if ($statusField.length) {
            mainData.status = $statusField.val();
        }

        var $catList = $('#bpl-qe-cat-list');
        if ($catList.length) {
            var selectedCats = $catList.find('.bpl-qe-cat-cb:checked').map(function () {
                return parseInt(this.value, 10);
            }).get();
            // CSV strings (rather than array[]=) so an empty selection still
            // round-trips to the server and clears the taxonomy.
            mainData.category_ids_csv = selectedCats.join(',');
        }

        var $tagList = $('#bpl-qe-tag-list');
        if ($tagList.length) {
            var selectedTags = $tagList.find('.bpl-qe-tag-cb:checked').map(function () {
                return parseInt(this.value, 10);
            }).get();
            mainData.tag_ids_csv = selectedTags.join(',');
        }

        // Custom taxonomies (Brands etc.). Encoded as custom_tax_ids[<slug>]=
        // so empty selections still arrive at the server and the assignment
        // is cleared. When the custom_taxonomies field is hidden no lists
        // are present in the DOM, so this loop is a no-op and nothing is
        // sent — preserving the existing assignments untouched.
        $('.bpl-qe-tax-list').each(function () {
            var $list = $(this);
            var tax = $list.data('taxonomy');
            if (!tax) return;
            var ids = $list.find('.bpl-qe-tax-cb:checked').map(function () {
                return parseInt(this.value, 10);
            }).get();
            mainData['custom_tax_ids[' + tax + ']'] = ids.join(',');
        });

        if (!isVariable) {
            if ($('#bpl-qe-price').length) {
                mainData.regular_price = $('#bpl-qe-price').val();
                mainData.sale_price = $('#bpl-qe-sale-price').val();
            }
            if ($('#bpl-qe-stock').length) {
                mainData.stock = $('#bpl-qe-stock').val();
            }
            if ($('#bpl-qe-stock-status').length) {
                mainData.stock_status = $('#bpl-qe-stock-status').val();
            }
            if ($('#bpl-qe-cogs').length) {
                mainData.cogs_value = $('#bpl-qe-cogs').val();
            }

            // Virtual / Digital / downloadable. Only sent for non-variable
            // products (the drawer hides the toggle group for variable types
            // and routes them through the full editor where per-variation
            // propagation runs).
            if ($('#bpl-qe-digital-toggle').length) {
                mainData.is_virtual      = $('#bpl-qe-virtual-toggle').is(':checked') ? 1 : 0;
                mainData.is_downloadable = $('#bpl-qe-digital-toggle').is(':checked') ? 1 : 0;
                mainData.downloads = mainData.is_downloadable
                    ? JSON.stringify(state.qeDownloads)
                    : '[]';
            }
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

        // Optimistic UI update *before* the AJAX. The success callback can be
        // skipped if a plugin contaminates the response (PHP notice / DB error
        // printed before the JSON breaks jQuery's parser → error handler runs
        // even though the server actually deleted the rows). Doing the local
        // mutation up front guarantees the UI reflects the user's action; the
        // background fetch reconciles authoritative state.
        var inTrashView = (state.status === 'trash');
        var removesRows =
            action === 'delete' ||
            (action === 'trash' && !inTrashView) ||
            (action === 'restore' && inTrashView);
        var targetIds = state.selected.slice();
        if (removesRows) {
            var idSet = {};
            targetIds.forEach(function (id) { idSet[id] = true; });
            var beforeLen = (state.products || []).length;
            state.products = (state.products || []).filter(function (p) { return !idSet[p.id]; });
            var actuallyRemoved = beforeLen - state.products.length;
            state.total = Math.max(0, (state.total || 0) - actuallyRemoved);
            renderProducts();
        }
        state.selected = [];
        updateBulkBar();

        $.ajax({
            url: PL.ajax_url,
            type: 'POST',
            data: {
                action: 'brikpanel_bulk_action_products',
                security: PL.nonce,
                bulk_action: action,
                product_ids: targetIds
            },
            // Tolerate responses contaminated by upstream PHP output (notices,
            // DB errors, debug echoes printed before wp_send_json). We strip
            // anything before the first `{` so jQuery can still parse the JSON
            // tail instead of bouncing into the error handler.
            dataType: 'json',
            dataFilter: function (raw) {
                if (typeof raw !== 'string') return raw;
                var i = raw.indexOf('{');
                return i > 0 ? raw.slice(i) : raw;
            },
            success: function (res) {
                setBulkLoading(false);
                if (res && res.success) {
                    showToast(res.data && res.data.message ? res.data.message : '', 'success');
                } else {
                    showToast(res && res.data && res.data.message ? res.data.message : PL.i18n.error, 'error');
                }
                // Always reconcile with the server — confirms the optimistic
                // update on success, restores correct rows if the action
                // partially failed.
                fetchProducts(true);
            },
            error: function () {
                setBulkLoading(false);
                // Couldn't even parse a JSON tail. The server may still have
                // applied the change (the optimistic update is likely correct);
                // a silent re-fetch is the source of truth either way.
                fetchProducts(true);
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

        // Optimistic local removal — see bulkAction() for why this lives
        // outside the success callback. We always reconcile via fetchProducts
        // afterwards so the UI matches the server.
        state.products = (state.products || []).filter(function (p) { return p.id !== id; });
        state.total = Math.max(0, (state.total || 0) - 1);
        $row.fadeOut(300, function () { $(this).remove(); });

        $.ajax({
            url: PL.ajax_url,
            type: 'POST',
            data: {
                action: 'brikpanel_delete_product',
                security: PL.nonce,
                product_id: id
            },
            dataType: 'json',
            dataFilter: function (raw) {
                if (typeof raw !== 'string') return raw;
                var i = raw.indexOf('{');
                return i > 0 ? raw.slice(i) : raw;
            },
            success: function (res) {
                if (res && res.success) {
                    showToast(res.data && res.data.message ? res.data.message : '', 'success');
                } else {
                    showToast(res && res.data && res.data.message ? res.data.message : PL.i18n.error, 'error');
                }
                fetchProducts(true);
            },
            error: function () {
                fetchProducts(true);
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
        var $badge = $('#bpl-table-body tr[data-id="' + id + '"] .brikpanel-pl-status-badge'); // i18n-ignore: selector fragment
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

    /**
     * Flip a product's featured flag from the row star. Updates the button
     * UI optimistically; on failure we restore the previous state so the row
     * never lies about server state. Single source of truth lives in WC's
     * `product_visibility` taxonomy, written via WC_Product::set_featured()
     * in the AJAX handler.
     */
    function toggleFeatured($btn) {
        if ($btn.prop('disabled')) return;
        var id = parseInt($btn.data('id'), 10);
        if (!id) return;

        var wasOn = $btn.hasClass('is-on');
        var willBe = !wasOn;

        $btn.prop('disabled', true);
        applyFeaturedStarState($btn, willBe);

        $.ajax({
            url: PL.ajax_url,
            type: 'POST',
            data: {
                action: 'brikpanel_toggle_featured',
                security: PL.nonce,
                product_id: id
            },
            success: function (res) {
                $btn.prop('disabled', false);
                if (res.success) {
                    applyFeaturedStarState($btn, !!res.data.is_featured);
                    for (var i = 0; i < state.products.length; i++) {
                        if (state.products[i].id === id) {
                            state.products[i].is_featured = !!res.data.is_featured;
                            break;
                        }
                    }
                    showToast(res.data.message, 'success');
                } else {
                    applyFeaturedStarState($btn, wasOn);
                    showToast((res.data && res.data.message) || PL.i18n.error, 'error');
                }
            },
            error: function () {
                $btn.prop('disabled', false);
                applyFeaturedStarState($btn, wasOn);
                showToast(PL.i18n.error, 'error');
            }
        });
    }

    function applyFeaturedStarState($btn, on) {
        $btn.toggleClass('is-on', !!on);
        $btn.attr('aria-pressed', on ? 'true' : 'false');
        var label = on
            ? (PL.i18n.unmark_featured || 'Featured — click to remove')
            : (PL.i18n.mark_featured || 'Mark as featured');
        $btn.attr('title', label);
        $btn.find('svg').attr('fill', on ? 'currentColor' : 'none');
    }

    /**
     * Quick-edit drawer counterpart of toggleFeatured. Hits the same
     * brikpanel_toggle_featured endpoint, then mirrors the result onto the
     * matching row's star (if rendered) and into state.products so the next
     * fetchProducts/refreshRow keeps both surfaces consistent.
     */
    function toggleQeFeatured($btn) {
        if ($btn.prop('disabled')) return;
        var id = parseInt($btn.data('id') || $btn.attr('data-id'), 10);
        if (!id) return;

        var wasOn = $btn.hasClass('is-on');
        var willBe = !wasOn;

        $btn.prop('disabled', true);
        applyQeFeaturedState($btn, willBe);

        $.ajax({
            url: PL.ajax_url,
            type: 'POST',
            data: {
                action: 'brikpanel_toggle_featured',
                security: PL.nonce,
                product_id: id
            },
            success: function (res) {
                $btn.prop('disabled', false);
                if (res.success) {
                    var nowOn = !!res.data.is_featured;
                    applyQeFeaturedState($btn, nowOn);
                    for (var i = 0; i < state.products.length; i++) {
                        if (state.products[i].id === id) {
                            state.products[i].is_featured = nowOn;
                            break;
                        }
                    }
                    // Mirror the change onto the row star so both surfaces
                    // stay in lockstep without waiting for a refetch.
                    var $rowStar = $('.brikpanel-pl-featured-star[data-id="' + id + '"]');
                    if ($rowStar.length) {
                        applyFeaturedStarState($rowStar, nowOn);
                    }
                    showToast(res.data.message, 'success');
                } else {
                    applyQeFeaturedState($btn, wasOn);
                    showToast((res.data && res.data.message) || PL.i18n.error, 'error');
                }
            },
            error: function () {
                $btn.prop('disabled', false);
                applyQeFeaturedState($btn, wasOn);
                showToast(PL.i18n.error, 'error');
            }
        });
    }

    function applyQeFeaturedState($btn, on) {
        $btn.toggleClass('is-on', !!on);
        $btn.attr('aria-pressed', on ? 'true' : 'false');
        var label = on
            ? (PL.i18n.unmark_featured || 'Featured — click to remove')
            : (PL.i18n.mark_featured || 'Mark as featured');
        $btn.attr('title', label);
        $btn.find('svg').attr('fill', on ? 'currentColor' : 'none');
        $btn.find('.screen-reader-text').text(label);
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

        // The count beside the page title reflects the CURRENT result set, not
        // the whole catalog. The status tabs right below already carry the
        // catalog-wide totals, so mirroring counts.all here was both redundant
        // and actively misleading once a filter was active: arriving from the
        // "Out of stock" notification showed "Products 94" above 9 rows. Reads
        // as the sum of every tab when nothing is filtered, so the unfiltered
        // view is unchanged. `state.total` is assigned from the same response
        // immediately before this call.
        $('#bpl-total-count').text(state.total);

        // Show/hide the Scheduled (future) tab. Inserted between Published and
        // Draft to match the static markup order. Kept visible while the user is
        // on the Scheduled view so they don't lose their place when the count
        // hits 0 (e.g. the last scheduled product just went live).
        if (counts.future > 0) {
            if (!$('[data-count="future"]').length) {
                var $futureTab = $(
                    '<button class="brikpanel-pl-tab' + (state.status === 'future' ? ' active' : '') + '" data-status="future">' +
                    (PL.i18n.scheduled || 'Scheduled') + ' <span class="brikpanel-pl-tab-count" data-count="future">' + counts.future + '</span></button>'
                );
                var $draftTab = $('[data-status="draft"]');
                if ($draftTab.length) { $futureTab.insertBefore($draftTab); }
                else { $('.brikpanel-pl-tabs').append($futureTab); }
            } else {
                $('[data-count="future"]').text(counts.future);
            }
        } else if (state.status === 'future') {
            $('[data-count="future"]').text(0);
        } else {
            $('[data-status="future"]').remove();
        }

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

        // Show/hide trash tab. The badge has to track 0 too — otherwise a
        // bulk permanent-delete from trash leaves a stale "Trash N" count
        // even though the rows are gone. We keep the tab visible (with "0")
        // while the user is actively on the trash view so they don't lose
        // their place; we drop it only after they've navigated away.
        if (counts.trash > 0) {
            if (!$('[data-count="trash"]').length) {
                $('.brikpanel-pl-tabs').append(
                    '<button class="brikpanel-pl-tab' + (state.status === 'trash' ? ' active' : '') + '" data-status="trash">' +
                    PL.i18n.trashed_tab + ' <span class="brikpanel-pl-tab-count" data-count="trash">' + counts.trash + '</span></button>'
                );
            } else {
                $('[data-count="trash"]').text(counts.trash);
            }
        } else if (state.status === 'trash') {
            $('[data-count="trash"]').text(0);
        } else {
            $('[data-status="trash"]').remove();
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

    // Lenient JSON parser shared by the per-row trash actions — strips any
    // PHP-printed garbage (notices, deprecations, DB errors) that comes
    // before the JSON body so we don't lose the success path.
    var lenientJsonFilter = function (raw) {
        if (typeof raw !== 'string') return raw;
        var i = raw.indexOf('{');
        return i > 0 ? raw.slice(i) : raw;
    };

    $(document).on('click', '.brikpanel-pl-action-restore', function (e) {
        e.preventDefault();
        e.stopPropagation();
        var $row = $(this).closest('tr');
        var id = parseInt($row.data('id'));
        // Optimistic: drop the row and decrement total before the round-trip
        // so a contaminated response can't strand the user on stale UI.
        state.products = (state.products || []).filter(function (p) { return p.id !== id; });
        state.total = Math.max(0, (state.total || 0) - 1);
        $row.fadeOut(200, function () { $(this).remove(); });
        $.ajax({
            url: PL.ajax_url,
            type: 'POST',
            data: {
                action: 'brikpanel_bulk_action_products',
                security: PL.nonce,
                bulk_action: 'restore',
                product_ids: [id]
            },
            dataType: 'json',
            dataFilter: lenientJsonFilter,
            success: function (res) {
                if (res && res.success) {
                    showToast(PL.i18n.restored, 'success');
                }
                fetchProducts(true);
            },
            error: function () {
                fetchProducts(true);
            }
        });
    });

    $(document).on('click', '.brikpanel-pl-action-delete-perm', function (e) {
        e.preventDefault();
        e.stopPropagation();
        var $row = $(this).closest('tr');
        var id = parseInt($row.data('id'));
        if (!confirm(PL.i18n.confirm_permanent_delete)) return;
        // Optimistic UI update before the AJAX — see bulkAction() for why.
        state.products = (state.products || []).filter(function (p) { return p.id !== id; });
        state.total = Math.max(0, (state.total || 0) - 1);
        $row.fadeOut(200, function () { $(this).remove(); });
        $.ajax({
            url: PL.ajax_url,
            type: 'POST',
            data: {
                action: 'brikpanel_bulk_action_products',
                security: PL.nonce,
                bulk_action: 'delete',
                product_ids: [id]
            },
            dataType: 'json',
            dataFilter: lenientJsonFilter,
            success: function (res) {
                if (res && res.success) {
                    showToast(PL.i18n.deleted_permanently, 'success');
                }
                fetchProducts(true);
            },
            error: function () {
                fetchProducts(true);
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
            dataType: 'json',
            dataFilter: lenientJsonFilter,
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
            '<button class="brikpanel-pl-var-popup-close" aria-label="' + escHtml(PL.i18n.popup_close || 'Close') + '">&times;</button></div>';

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
        $('.brikpanel-pl-qe-simple-only').hide();

        $.ajax({
            url: PL.ajax_url,
            type: 'POST',
            data: { action: 'brikpanel_get_variations', security: PL.nonce, product_id: productId },
            dataType: 'json',
            dataFilter: lenientJsonFilter,
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
        var cogsVisible = !!PL.qe_cogs_visible;
        for (var i = 0; i < variations.length; i++) {
            var v = variations[i];
            var stockVal = v.stock !== null ? v.stock : '';
            var vStatus = v.stock_status || 'instock';
            html += '<div class="brikpanel-pl-qe-var-item" data-var-id="' + v.id + '">';
            html += '<div class="brikpanel-pl-qe-var-name">' + escHtml(v.name) + '</div>';
            html += '<div class="brikpanel-pl-qe-var-fields">';
            html += '<div class="brikpanel-pl-qe-field"><label>' + escHtml(PL.i18n.price_label || 'Price') + '</label><input type="text" class="brikpanel-pl-qe-var-input" data-field="regular_price" value="' + escAttr(v.regular_price || '') + '"></div>';
            html += '<div class="brikpanel-pl-qe-field"><label>' + escHtml(PL.i18n.sale_label || 'Sale') + '</label><input type="text" class="brikpanel-pl-qe-var-input" data-field="sale_price" value="' + escAttr(v.sale_price || '') + '"></div>';
            if (cogsVisible) {
                // Same falsy guard as the simple-product field: a saved "0"
                // cost arrives as either string "0" or number 0 (WC native
                // get_cogs_value() returns a float for non-null costs).
                // `|| ''` would treat both as empty and erase the value.
                var vCogs = (v.cogs_value === '' || v.cogs_value == null) ? '' : String(v.cogs_value);
                html += '<div class="brikpanel-pl-qe-field"><label>' + escHtml(PL.i18n.cogs_label || 'Cost') + '</label><input type="text" class="brikpanel-pl-qe-var-input" data-field="cogs_value" value="' + escAttr(vCogs) + '" inputmode="decimal"></div>';
            }
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
            refreshBulkValueUI();
        });

        // Action change → swap the value control (numeric / shipping unit / term picker)
        $('#bpl-bulk-action-cat, #bpl-bulk-action-sel').on('change', refreshBulkValueUI);

        // Keep the rounding example in sync with the digit / direction pickers.
        $('#bpl-bulk-round-digits, #bpl-bulk-round-mode').on('change', updateRoundPreview);

        // Term picker search (categories / tags / brands), delegated.
        $(document).on('input', '.bpl-bulk-term-search', function () {
            filterTermList($(this).siblings('.bpl-bulk-term-list'), $(this).val());
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

        // Reset taxonomy term pickers so stale selections never carry over.
        $('.bpl-bulk-term-cb').prop('checked', false);
        $('.bpl-bulk-term-search').val('');
        $('.bpl-bulk-term-list').find('li, .brikpanel-pl-qe-term-item').show();

        // Sync the value control to the currently selected action.
        refreshBulkValueUI();

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
            dataType: 'json',
            dataFilter: lenientJsonFilter,
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

    // Renders a purely numeric "12,345 -> 12,300" example for the rounding action
    // from the chosen digit count + direction. Numbers only — no translatable
    // text — so it can live in the JS without an i18n key.
    function updateRoundPreview() {
        var $out = $('#bpl-bulk-round-preview');
        if (!$out.length) { return; }
        var sample = 12345;
        var digits = parseInt($('#bpl-bulk-round-digits').val(), 10) || 2;
        var mode   = $('#bpl-bulk-round-mode').val() || 'nearest';
        var factor = Math.pow(10, digits);
        var rounded;
        if (mode === 'up')        { rounded = Math.ceil(sample / factor) * factor; }
        else if (mode === 'down') { rounded = Math.floor(sample / factor) * factor; }
        else                      { rounded = Math.round(sample / factor) * factor; }
        var fmt = function (n) {
            try { return n.toLocaleString(); } catch (e) { return String(n); }
        };
        $out.text(fmt(sample) + ' → ' + fmt(rounded));
    }

    // Reads the active update tab, swaps the value control to match the chosen
    // action (numeric input, shipping unit suffix, or taxonomy term picker) and
    // hides the variation filter where it does not apply.
    function refreshBulkValueUI() {
        var activeTab = $('.brikpanel-pl-modal-tab.active').data('tab');
        if (activeTab !== 'bpl-bulk-tab-cat' && activeTab !== 'bpl-bulk-tab-sel') {
            $('#bpl-bulk-term-region').hide();
            return;
        }

        var which   = activeTab === 'bpl-bulk-tab-sel' ? 'sel' : 'cat';
        var $action = $('#bpl-bulk-action-' + which);
        var action  = $action.val() || '';
        var $opt    = $action.find('option:selected');
        var $valWrap = $('#bpl-bulk-value-' + which + '-wrap');

        var isTax = action.indexOf('tax_set__') === 0 || action.indexOf('tax_add__') === 0;
        var isShipping = action === 'set_weight' || action === 'set_length' ||
                         action === 'set_width' || action === 'set_height';
        // Maintenance actions need no value: they recompute from existing data.
        var isNoValue = action === 'repair_visibility';
        // Rounding swaps the single value input for its own digit + direction picker.
        var isRound = action === 'round_prices';

        // The variation filter only applies to per-variation price/stock edits.
        if (which === 'cat') {
            $('#bpl-bulk-varfilter-cat').toggle(!isTax && !isShipping && !isNoValue);
        }

        $('#bpl-bulk-round-region').toggle(isRound);
        if (isRound) {
            $('#bpl-bulk-term-region').hide();
            $valWrap.hide();
            updateRoundPreview();
        } else if (isNoValue) {
            $('#bpl-bulk-term-region').hide();
            $valWrap.hide();
        } else if (isTax) {
            $valWrap.hide();
            var taxonomy = action.split('__')[1];
            var $region = $('#bpl-bulk-term-region').show();
            $region.find('.bpl-bulk-term-picker').hide();
            $region.find('.bpl-bulk-term-picker[data-taxonomy="' + taxonomy + '"]').show();
            $('#bpl-bulk-term-label').text($opt.text().replace(/\s+/g, ' ').trim());
        } else {
            $('#bpl-bulk-term-region').hide();
            $valWrap.show();
            var $unit = $valWrap.find('.bpl-bulk-unit');
            if (isShipping) {
                var unit = $opt.data('unit') || '';
                $unit.text(unit).toggle(!!unit);
            } else {
                $unit.text('').hide();
            }
        }
    }

    function applyBulkUpdate() {
        var activeTab = $('.brikpanel-pl-modal-tab.active').data('tab');
        var which   = activeTab === 'bpl-bulk-tab-sel' ? 'sel' : 'cat';
        var action  = $('#bpl-bulk-action-' + which).val() || '';
        var isTax   = action.indexOf('tax_set__') === 0 || action.indexOf('tax_add__') === 0;

        // Collect taxonomy term IDs from the visible picker when a tax action runs.
        var termIds = '';
        if (isTax) {
            var taxonomy = action.split('__')[1];
            var termSel = '#bpl-bulk-term-region .bpl-bulk-term-picker[data-taxonomy="' + taxonomy + '"] .bpl-bulk-term-cb:checked'; // i18n-ignore: CSS selector
            var ids = $(termSel).map(function () { return parseInt(this.value, 10); }).get();
            if (action.indexOf('tax_add__') === 0 && !ids.length) {
                showToast(PL.i18n.bulk_select_terms, 'error');
                return;
            }
            termIds = ids.join(',');
        }

        // For the rounding action the digit count travels in `value` and the
        // direction in `round_mode`; the per-tab numeric input stays hidden.
        var isRound   = action === 'round_prices';
        var roundMode = isRound ? ($('#bpl-bulk-round-mode').val() || 'nearest') : 'nearest';

        var params;
        if (activeTab === 'bpl-bulk-tab-cat') {
            var scope = $('#bpl-bulk-scope').val();
            var catId = $('#bpl-bulk-cat').val();
            if (scope === 'category' && !catId) { showToast(PL.i18n.bulk_select_cat, 'error'); return; }
            if (!confirm(scope === 'all' ? PL.i18n.bulk_all_confirm : PL.i18n.bulk_cat_confirm)) return;
            params = {
                mode: scope === 'all' ? 'all' : 'category',
                category: scope === 'all' ? '' : catId,
                bulk_action: action,
                value: isRound ? ($('#bpl-bulk-round-digits').val() || '2') : $('#bpl-bulk-value-cat').val(),
                round_mode: roundMode,
                term_ids: termIds,
                attr_key: isTax ? '' : ($('#bpl-bulk-attr-key').val() || ''),
                attr_val: isTax ? '' : ($('#bpl-bulk-attr-val').val() || '')
            };
        } else {
            if (!state.selected.length) { showToast(PL.i18n.bulk_no_selection, 'error'); return; }
            if (!confirm(PL.i18n.bulk_confirm)) return;
            params = {
                mode: 'selected',
                selected_ids: state.selected.join(','),
                bulk_action: action,
                value: isRound ? ($('#bpl-bulk-round-digits').val() || '2') : $('#bpl-bulk-value-sel').val(),
                round_mode: roundMode,
                term_ids: termIds
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
        readTaxFiltersFromDom();
        readStateFromUrl();
        fetchProducts();
    }

    $(document).ready(init);

})(jQuery);
