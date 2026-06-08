/**
 * BrikPanel – Simplified Product Editor
 * @package BrikPanel
 * @since 1.6.0
 */
(function ($) {
    'use strict';

    var PE = brikpanelPE || {};
    var productData = window.brikpanelProductData || {};

    var state = { images: [], saving: false, dirty: false, varTemplate: null, varAttributes: [], variations: [], downloads: [], tags: [], linked: { upsells: [], cross_sells: [] } };

    function init() {
        bindEvents();
        initStatusDropdown();
        initCatalogVisibility();
        initHeaderOverflow();
        initFeaturedStar();
        initToggles();
        initImages();
        initCharCounter();
        initPriceInputs();
        initCategorySearch();
        initEditor();
        initSeoPreview();
        initSeoAnalysisBridge();
        initAutoSave();
        initInlineEdit();
        initTags();
        initSaleDates();
        initAttributes();
        initManageStock();
        initBackorderNotify();
        initLinkedProducts();
        initThirdPartyTabLazyLoad();
        loadExistingData();
    }

    /* Some 3rd-party WooCommerce product-data tabs only render their saved
       content when their tab link is clicked (they bind the lazy loader to a
       `.{key}_tab` click). BrikPanel shows the panel directly with no WC tab
       nav, so those plugins would display an empty builder. We emit a hidden,
       WC-shaped tab-nav node per panel (.brikpanel-pe-wc-tabsim) carrying the
       classes the plugin listens on; firing a click here triggers the lazy
       render. The panel itself stays visible via CSS regardless. */
    function initThirdPartyTabLazyLoad() {
        var $sims = $('.brikpanel-pe-wc-tabsim');
        if (!$sims.length) return;
        $sims.each(function () {
            // Trigger handlers bound to the <li> (WC's `.{key}_tab`) and the
            // <ul>. We avoid triggering the <a> so jQuery does not follow its
            // href and scroll the page to the anchor.
            $(this).find('li').addBack().trigger('click');
        });
    }

    /* Linked products (upsells & cross-sells) — opt-in card. Lightweight
       chip + AJAX search picker; only active when the card is rendered. */
    function initLinkedProducts() {
        if (!$('#bpe-linked-card').length) return;
        state.linked.upsells     = (productData.upsells || []).slice();
        state.linked.cross_sells = (productData.cross_sells || []).slice();

        ['upsells', 'cross_sells'].forEach(function (field) {
            var $wrap = $('.brikpanel-pe-linked[data-field="' + field + '"]');
            if (!$wrap.length) return;
            var $chips   = $wrap.find('.brikpanel-pe-linked-chips');
            var $search  = $wrap.find('.brikpanel-pe-linked-search');
            var $results = $wrap.find('.brikpanel-pe-linked-results');
            var timer = null;

            function renderChips() {
                $chips.empty();
                state.linked[field].forEach(function (item, i) {
                    $chips.append(
                        '<span class="brikpanel-pe-linked-chip" data-i="' + i + '">' +
                            esc(item.text) +
                            '<button type="button" class="brikpanel-pe-linked-remove" aria-label="' + esc(PE.i18n && PE.i18n.chip_remove ? PE.i18n.chip_remove : 'Remove') + '">&times;</button>' +
                        '</span>'
                    );
                });
            }
            renderChips();

            $chips.on('click', '.brikpanel-pe-linked-remove', function () {
                var i = $(this).closest('.brikpanel-pe-linked-chip').data('i');
                state.linked[field].splice(i, 1);
                renderChips();
                state.dirty = true;
            });

            function hideResults() { $results.attr('hidden', true).empty(); }

            $search.on('input', function () {
                var q = $.trim($search.val());
                clearTimeout(timer);
                if (q.length < 2) { hideResults(); return; }
                timer = setTimeout(function () {
                    $.get(PE.ajax_url, {
                        action: 'brikpanel_pe_search_products',
                        security: PE.nonce,
                        q: q,
                        exclude: $('#bpe-product-id').val() || 0
                    }).done(function (res) {
                        if (!res || !res.success) { hideResults(); return; }
                        var chosen = {};
                        state.linked[field].forEach(function (it) { chosen[it.id] = true; });
                        var list = (res.data.results || []).filter(function (r) { return !chosen[r.id]; });
                        if (!list.length) { hideResults(); return; }
                        $results.empty();
                        list.forEach(function (r) {
                            $results.append('<button type="button" class="brikpanel-pe-linked-option" data-id="' + r.id + '">' + esc(r.text) + '</button>');
                        });
                        $results.removeAttr('hidden');
                    }).fail(hideResults);
                }, 250);
            });

            $results.on('click', '.brikpanel-pe-linked-option', function () {
                var id = parseInt($(this).data('id'), 10);
                var text = $(this).text();
                if (!state.linked[field].some(function (it) { return it.id === id; })) {
                    state.linked[field].push({ id: id, text: text });
                    renderChips();
                    state.dirty = true;
                }
                $search.val('');
                hideResults();
            });

            $(document).on('click', function (e) {
                if (!$wrap[0].contains(e.target)) hideResults();
            });
        });
    }

    /* "Track quantity" toggle for simple products. When on, the merchant
       enters a quantity + backorder rule and WooCommerce derives the stock
       status; when off, they pick an in/out/backorder status directly. The
       two field sets are mutually exclusive, mirroring WC's native tab. */
    function initManageStock() {
        var $toggle = $('#bpe-manage-stock');
        if (!$toggle.length) return;
        function sync() {
            var on = $toggle.is(':checked');
            $('#bpe-stock-qty-field, #bpe-backorders-field').toggle(on);
            $('#bpe-stock-status-field').toggle(!on);
            // The "Backorder behavior" radio collapse belongs to the
            // tracking-off flow (it refines a manual "On backorder" status).
            // While tracking is on, backorders are set via the dropdown, so
            // keep the collapse closed to avoid a duplicate control.
            $('#bpe-backorder-notify').toggleClass('open', !on && $('#bpe-stock-status').val() === 'onbackorder');
        }
        $toggle.on('change', function () { sync(); state.dirty = true; });
        sync();
    }

    /* Backorder notification — opt-in via setting. The collapse opens
       only when the simple-product stock status is "On backorder". */
    function initBackorderNotify() {
        var $collapse = $('#bpe-backorder-notify');
        if (!$collapse.length) return;
        var $sel = $('#bpe-stock-status');
        function sync() {
            // Never open while stock tracking is on — backorders are picked
            // via the "Allow backorders?" dropdown in that mode.
            if ($('#bpe-manage-stock').is(':checked')) { $collapse.removeClass('open'); return; }
            $collapse.toggleClass('open', $sel.val() === 'onbackorder');
        }
        $sel.on('change', sync);
        sync();
    }

    /* Sale schedule date pickers (flatpickr) */
    function initSaleDates() {
        if (typeof flatpickr !== 'function') return;
        var $from = $('#bpe-sale-from'), $to = $('#bpe-sale-to');
        if (!$from.length || !$to.length) return;

        var fpFrom = flatpickr($from.get(0), {
            dateFormat: 'Y-m-d',
            allowInput: false,
            onChange: function (dates) {
                if (fpTo && dates[0]) {
                    fpTo.set('minDate', dates[0]);
                }
            }
        });
        var fpTo = flatpickr($to.get(0), {
            dateFormat: 'Y-m-d',
            allowInput: false,
            minDate: $from.val() || null
        });
    }

    function bindEvents() {
        $('#bpe-publish').on('click', function () {
            var status = $('#bpe-status').val() || 'publish';
            saveProduct(status);
        });

        var $dz = $('#bpe-dropzone');
        $dz.on('click', openMediaLibrary);
        $('#bpe-add-images').on('click', function (e) { e.stopPropagation(); openMediaLibrary(); });
        $dz.on('dragover', function (e) { e.preventDefault(); $dz.addClass('dragover'); });
        $dz.on('dragleave drop', function () { $dz.removeClass('dragover'); });
        $dz.on('drop', function (e) { e.preventDefault(); handleFileDrop(e.originalEvent.dataTransfer.files); });

        $('#bpe-add-cat-toggle').on('click', function (e) { e.preventDefault(); toggleSection($('#bpe-new-cat-section')); });
        $('#bpe-add-cat-btn').on('click', addCategory);

        $('.brikpanel-pe-var-template').on('click', function () { selectTemplate($(this).data('template')); });
        $('#bpe-var-back').on('click', function () { showVarStep(1); });
        $('#bpe-var-back-2').on('click', function () { captureVarTableInputs(); showVarStep(2); });
        $('#bpe-var-forward-1').on('click', function () { showVarStep(2); });
        $('#bpe-var-forward-2').on('click', function () { showVarStep(3); });
        $('#bpe-generate-vars').on('click', generateVariations);
        $('#bpe-apply-bulk').on('click', applyBulk);

        // Duplicate (delegated so dynamically injected button still works)
        $(document).on('click', '#bpe-duplicate', duplicateProduct);

        $('[data-required]').on('blur', function () { validateField($(this)); });

        // Ctrl+S shortcut
        $(document).on('keydown', function (e) {
            if ((e.ctrlKey || e.metaKey) && e.key === 's') {
                e.preventDefault();
                var status = $('#bpe-status').val() || 'publish';
                saveProduct(status);
            }
        });

        // Track dirty state
        $(document).on('input change', '.brikpanel-pe-content input, .brikpanel-pe-content textarea, .brikpanel-pe-content select, .brikpanel-pe-content [contenteditable]', function () {
            state.dirty = true;
        });
        // Password field lives in the header — track it separately
        $(document).on('input', '#bpe-post-password', function () {
            state.dirty = true;
        });

        // Beforeunload warning
        $(window).on('beforeunload', function () {
            if (state.dirty) return true;
        });

        // Vendor selector reactivity:
        //   1. Hide / show the parent's "Vendor SKU" row depending on whether
        //      a real vendor is selected (no point in collecting an SKU under
        //      "None"). Hidden via `[hidden]` so screen readers also skip it.
        //   2. When the parent vendor changes, refresh the inherit-label of
        //      every variation row's vendor select so "(parent: Acme …)" stays
        //      accurate without a full re-render of the table.
        $(document).on('change', '#bpe-vendor', function () {
            var $sel = $(this);
            var newId = parseInt($sel.val(), 10) || 0;
            var newName = $sel.find('option:selected').text();
            $('.brikpanel-pe-vendor-sku-row').prop('hidden', newId === 0);
            productData.parent_vendor_id = newId;
            var inherit = newId === 0
                ? (PE.i18n.inherit_parent || '(parent)')
                : (PE.i18n.inherit_parent_named || '(parent: %s)').replace('%s', newName);
            $('.var-vendor option[value="0"]').text(inherit);
        });
    }

    /* Close every other header popover (status / catalog visibility / overflow)
       except the one passed in. The triggers call e.stopPropagation(), so the
       per-dropdown outside-click handlers don't fire for sibling triggers;
       this keeps only one header menu open at a time. */
    function closeHeaderPopovers(except) {
        $('.brikpanel-pe-status-wrap, #bpe-catvis-wrap, #bpe-header-overflow').not(except).each(function () {
            var $w = $(this);
            if ($w.hasClass('is-open')) {
                $w.removeClass('is-open').find('[aria-expanded="true"]').attr('aria-expanded', 'false');
            }
        });
    }

    /* Custom Visibility dropdown (replaces the old <select>) */
    function initStatusDropdown() {
        var $wrap = $('.brikpanel-pe-status-wrap');
        if (!$wrap.length) return;
        var $trigger = $('#bpe-status-trigger');
        var $menu = $wrap.find('.brikpanel-pe-status-menu');
        var $hidden = $('#bpe-status');

        function close() {
            $wrap.removeClass('is-open');
            $trigger.attr('aria-expanded', 'false');
        }
        function open() {
            $wrap.addClass('is-open');
            $trigger.attr('aria-expanded', 'true');
        }

        $trigger.on('click', function (e) {
            e.stopPropagation();
            closeHeaderPopovers($wrap);
            $wrap.hasClass('is-open') ? close() : open();
        });

        $menu.on('click', 'li[role="option"]', function () {
            var v = $(this).data('value');
            $hidden.val(v).trigger('change');
            $wrap.attr('data-status', v);
            $menu.find('li').removeClass('is-active');
            $(this).addClass('is-active');
            $trigger.find('.brikpanel-pe-status-trigger-label').text($(this).find('strong').text());
            // Show/hide password field
            var $pwWrap = $('#bpe-password-wrap');
            if (v === 'password') {
                $pwWrap.addClass('is-visible');
                $('#bpe-post-password').focus();
            } else {
                $pwWrap.removeClass('is-visible');
            }
            close();
        });

        $(document).on('click', function (e) {
            if (!$(e.target).closest('.brikpanel-pe-status-wrap').length) close();
        });

        $(document).on('keydown', function (e) {
            if (e.key === 'Escape' && $wrap.hasClass('is-open')) close();
        });
    }

    /* Featured-product star button in the header. Toggles the visual state
       and the hidden #bpe-is-featured field; the actual save fires when the
       user hits Update/Publish (or the next auto-save tick). No standalone
       AJAX — keeps the editor's single-commit save model intact. */
    function initFeaturedStar() {
        var $btn = $('#bpe-featured-star');
        if (!$btn.length) return;
        var $field = $('#bpe-is-featured');

        $btn.on('click', function () {
            var nowOn = !$btn.hasClass('is-on');
            $btn.toggleClass('is-on', nowOn);
            $btn.attr('aria-pressed', nowOn ? 'true' : 'false');
            // i18n-ignore: data-label-on/off are __()-wrapped in PHP at brikpanel-product-editor.php:512-513; the English fallback only runs if the attribute is missing.
            var label = nowOn
                ? ($btn.data('label-on')  || 'Featured')
                : ($btn.data('label-off') || 'Mark as featured');
            $btn.attr('title', label);
            $btn.find('.screen-reader-text').text(label);
            $btn.find('svg').attr('fill', nowOn ? 'currentColor' : 'none');
            $field.val(nowOn ? '1' : '0');
            state.dirty = true;
        });
    }

    /* Catalog visibility dropdown */
    function initCatalogVisibility() {
        var $wrap = $('#bpe-catvis-wrap');
        if (!$wrap.length) return;
        var $trigger = $('#bpe-catvis-trigger');
        var $menu = $wrap.find('.brikpanel-pe-catvis-menu');
        var $hidden = $('#bpe-catalog-visibility');

        function close() { $wrap.removeClass('is-open'); $trigger.attr('aria-expanded', 'false'); }
        function open()  { $wrap.addClass('is-open');  $trigger.attr('aria-expanded', 'true');  }

        $trigger.on('click', function (e) {
            e.stopPropagation();
            closeHeaderPopovers($wrap);
            $wrap.hasClass('is-open') ? close() : open();
        });

        $menu.on('click', 'li[role="option"]', function () {
            var v = $(this).data('value');
            $hidden.val(v).trigger('change');
            $menu.find('li[role="option"]').removeClass('is-active');
            $(this).addClass('is-active');
            $trigger.find('.brikpanel-pe-catvis-label').text($(this).text());
            state.dirty = true;
            close();
        });

        $(document).on('click', function (e) {
            if (!$(e.target).closest('#bpe-catvis-wrap').length) close();
        });
        $(document).on('keydown', function (e) {
            if (e.key === 'Escape' && $wrap.hasClass('is-open')) close();
        });
    }

    /* Header overflow ("More actions") menu — only interactive on mobile,
       where View product / Duplicate / Add new collapse behind a kebab
       trigger. On desktop the menu is display:contents, so the trigger is
       hidden and the actions render inline; the handlers below are harmless
       there. Mirrors the status / catalog-visibility dropdown pattern. */
    function initHeaderOverflow() {
        var $wrap = $('#bpe-header-overflow');
        if (!$wrap.length) return;
        var $trigger = $('#bpe-overflow-trigger');

        function close() { $wrap.removeClass('is-open'); $trigger.attr('aria-expanded', 'false'); }
        function open()  { $wrap.addClass('is-open');  $trigger.attr('aria-expanded', 'true');  }

        $trigger.on('click', function (e) {
            e.stopPropagation();
            closeHeaderPopovers($wrap);
            $wrap.hasClass('is-open') ? close() : open();
        });

        // Collapse the menu once an action is chosen (Duplicate stays on the
        // page; View product / Add new navigate away anyway).
        $wrap.find('.brikpanel-pe-overflow-menu').on('click', 'a, button', function () {
            close();
        });

        $(document).on('click', function (e) {
            if (!$(e.target).closest('#bpe-header-overflow').length) close();
        });
        $(document).on('keydown', function (e) {
            if (e.key === 'Escape' && $wrap.hasClass('is-open')) close();
        });
    }

    /* Toggles */
    function initToggles() {
        bindToggle('#bpe-weight-toggle', '#bpe-weight-section');
        bindToggle('#bpe-dims-toggle', '#bpe-dims-section');
        bindToggle('#bpe-seo-toggle', '#bpe-seo-section');
        bindToggle('#bpe-var-toggle', '#bpe-var-section');
        bindToggle('#bpe-digital-toggle', '#bpe-digital-section');

        // Hide the parent pricing + inventory cards whenever the variations
        // toggle is on — each variation row already carries its own price,
        // sale schedule, stock qty, stock status and SKU, so the top-level
        // fields are dead inputs in that mode and must not block submit.
        // Also hide the Product attributes (specs) card so users don't get
        // confused between variation attributes and spec attributes — the
        // variations wizard handles attribute editing in that mode.
        function syncVariableMode() {
            var isVar = $('#bpe-var-toggle').is(':checked');
            $('#bpe-pricing-card, #bpe-inventory-card, #bpe-gtin-card, #bpe-attr-card').toggle(!isVar);
        }
        $('#bpe-var-toggle').on('change', syncVariableMode);
        syncVariableMode();

        // Product type selector (enabled via "Product type selector" setting).
        // The dropdown is the canonical source of truth for which WC product
        // type the editor is creating. For backward compatibility, we keep
        // #bpe-var-toggle mirrored to the variable-or-not derived flag so the
        // existing pricing/inventory/variations show/hide logic continues to
        // work unchanged.
        var $productType = $('#bpe-product-type');
        if ($productType.length) {
            function isVariableType(t) {
                if (!t) return false;
                if (t === 'variable') return true;
                return t.indexOf('variable-') === 0 || t.indexOf('variable_') === 0;
            }
            function syncProductType() {
                var t = $productType.val();
                var shouldBeVariable = isVariableType(t);
                var $vt = $('#bpe-var-toggle');
                if ($vt.is(':checked') !== shouldBeVariable) {
                    $vt.prop('checked', shouldBeVariable).trigger('change');
                }
            }
            $productType.on('change', syncProductType);
            syncProductType();
        }

        // Virtual = no physical shipping (service product). Downloadable
        // products are implicitly virtual too — when Digital is toggled on
        // we flip Virtual on and disable it so the user can't end up in an
        // inconsistent "downloadable but ships physically" state by accident.
        function syncShippingVisibility() {
            var virtualOn = $('#bpe-virtual-toggle').is(':checked') || $('#bpe-digital-toggle').is(':checked');
            $('#bpe-weight-card, #bpe-dims-card').toggle(!virtualOn);
        }
        function syncVirtualLock() {
            var digitalOn = $('#bpe-digital-toggle').is(':checked');
            var $virtual = $('#bpe-virtual-toggle');
            if (digitalOn) {
                $virtual.prop('checked', true).prop('disabled', true);
            } else {
                $virtual.prop('disabled', false);
            }
        }
        $('#bpe-digital-toggle').on('change', function () {
            $('#bpe-digital-section').toggleClass('open', this.checked);
            syncVirtualLock();
            syncShippingVisibility();
        });
        $('#bpe-virtual-toggle').on('change', syncShippingVisibility);
        syncVirtualLock();
        syncShippingVisibility();

        // Add download file
        $('#bpe-add-download').on('click', openFilePicker);
    }

    function bindToggle(cb, sec) { $(cb).on('change', function () { toggleSection($(sec), this.checked); }); }

    function toggleSection($s, force) {
        var open = typeof force === 'boolean' ? force : !$s.hasClass('open');
        $s.toggleClass('open', open);
    }

    /* Multi-select media helper — every click toggles selection, no Ctrl needed */
    var _origToggle = null;
    function enableClickToToggle() {
        if (wp.media && wp.media.view && wp.media.view.Attachment && !_origToggle) {
            _origToggle = wp.media.view.Attachment.prototype.toggleSelectionHandler;
            wp.media.view.Attachment.prototype.toggleSelectionHandler = function () {
                // Always use 'toggle' method so each click adds/removes
                this.toggleSelection({ method: 'toggle' });
            };
        }
    }
    function disableClickToToggle() {
        if (_origToggle) {
            wp.media.view.Attachment.prototype.toggleSelectionHandler = _origToggle;
            _origToggle = null;
        }
    }

    /* Images */
    function initImages() {
        $('#bpe-gallery').sortable({
            items: '.brikpanel-pe-gallery-item', tolerance: 'pointer', cursor: 'grabbing',
            placeholder: 'brikpanel-pe-gallery-item ui-sortable-placeholder',
            update: syncImageOrder
        });
    }

    function openMediaLibrary() {
        var frame = wp.media({ title: PE.i18n.add_images || 'Add images', multiple: true, library: { type: 'image' }, button: { text: PE.i18n.select || 'Select' } });
        frame.on('open', enableClickToToggle);
        frame.on('close', disableClickToToggle);
        frame.on('select', function () {
            frame.state().get('selection').toJSON().forEach(function (att) {
                addImage(att.id, (att.sizes && att.sizes.thumbnail) ? att.sizes.thumbnail.url : att.url);
            });
        });
        frame.open();
    }

    /* Downloadable files */
    function openFilePicker() {
        var frame = wp.media({
            title: PE.i18n.select_file || 'Select downloadable file',
            multiple: true,
            button: { text: PE.i18n.select || 'Select' }
        });
        frame.on('open', enableClickToToggle);
        frame.on('close', disableClickToToggle);
        frame.on('select', function () {
            frame.state().get('selection').toJSON().forEach(function (att) {
                addDownload({
                    id: '',
                    name: att.title || att.filename || 'File',
                    file: att.url
                });
            });
        });
        frame.open();
    }

    function addDownload(d) {
        // Avoid duplicates by file URL
        if (state.downloads.some(function (x) { return x.file === d.file; })) return;
        state.downloads.push(d);
        renderDownloads();
        state.dirty = true;
    }

    function removeDownload(idx) {
        state.downloads.splice(idx, 1);
        renderDownloads();
        state.dirty = true;
    }

    function renderDownloads() {
        var $list = $('#bpe-downloads-list').empty();
        if (!state.downloads.length) {
            $list.append('<p class="brikpanel-pe-text-muted">' + (PE.i18n.no_files || 'No files added yet.') + '</p>');
            return;
        }
        state.downloads.forEach(function (d, idx) {
            var $row = $('<div class="brikpanel-pe-download-item" data-idx="' + idx + '">');
            $row.append('<svg class="brikpanel-pe-download-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>');
            var $info = $('<div class="brikpanel-pe-download-info">');
            $info.append('<input type="text" class="brikpanel-pe-download-name" value="' + esc(d.name) + '" placeholder="' + (PE.i18n.file_name || 'File name') + '">');
            var $urlRow = $('<div class="brikpanel-pe-download-url-row">');
            $urlRow.append('<input type="url" class="brikpanel-pe-download-url" value="' + esc(d.file) + '" placeholder="https://…" spellcheck="false">');
            $urlRow.append('<button type="button" class="brikpanel-pe-download-browse" title="' + (PE.i18n.choose_file || 'Choose file') + '">' +
                '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg>' +
                '</button>');
            $info.append($urlRow);
            $row.append($info);
            var $rm = $('<button type="button" class="brikpanel-pe-download-remove" title="' + (PE.i18n.remove || 'Remove') + '">&times;</button>');
            $rm.on('click', function () { removeDownload(idx); });
            $row.append($rm);
            $list.append($row);
        });

        // Update name on input
        $list.find('.brikpanel-pe-download-name').on('input', function () {
            var idx = parseInt($(this).closest('.brikpanel-pe-download-item').data('idx'), 10);
            if (state.downloads[idx]) {
                state.downloads[idx].name = this.value;
                state.dirty = true;
            }
        });

        // Update URL on input — lets users correct or replace a link in place.
        $list.find('.brikpanel-pe-download-url').on('input', function () {
            var idx = parseInt($(this).closest('.brikpanel-pe-download-item').data('idx'), 10);
            if (state.downloads[idx]) {
                state.downloads[idx].file = this.value;
                state.dirty = true;
            }
        });

        // Media library picker to replace the current file URL.
        $list.find('.brikpanel-pe-download-browse').on('click', function (e) {
            e.preventDefault();
            var $item = $(this).closest('.brikpanel-pe-download-item');
            var idx = parseInt($item.data('idx'), 10);
            if (!state.downloads[idx]) return;
            var frame = wp.media({
                title: PE.i18n.select_file || 'Select downloadable file',
                multiple: false,
                button: { text: PE.i18n.select || 'Select' }
            });
            frame.on('open', enableClickToToggle);
            frame.on('close', disableClickToToggle);
            frame.on('select', function () {
                var att = frame.state().get('selection').first().toJSON();
                state.downloads[idx].file = att.url;
                if (!state.downloads[idx].name) {
                    state.downloads[idx].name = att.title || att.filename || 'File';
                }
                state.dirty = true;
                renderDownloads();
            });
            frame.open();
        });
    }

    function handleFileDrop(files) {
        Array.from(files).forEach(function (file) {
            if (!file.type.startsWith('image/')) return;

            // Show upload placeholder with spinner
            var placeholderId = 'upload-' + Date.now() + '-' + Math.random().toString(36).substr(2, 5);
            var $placeholder = $('<div class="brikpanel-pe-gallery-item is-uploading" id="' + placeholderId + '"></div>');
            $('#bpe-gallery').append($placeholder);

            var fd = new FormData();
            fd.append('action', 'brikpanel_upload_image');
            fd.append('security', PE.nonce);
            fd.append('file', file);
            $.ajax({ url: PE.ajax_url, type: 'POST', data: fd, processData: false, contentType: false,
                success: function (r) {
                    $('#' + placeholderId).remove();
                    if (r.success) addImage(r.data.id, r.data.url);
                },
                error: function () { $('#' + placeholderId).remove(); }
            });
        });
    }

    function addImage(id, url) {
        if (state.images.some(function (i) { return i.id === id; })) return;
        state.images.push({ id: id, url: url });
        renderGallery();
    }

    function removeImage(id) {
        state.images = state.images.filter(function (i) { return i.id !== id; });
        renderGallery();
    }

    function renderGallery() {
        var $g = $('#bpe-gallery').empty();
        state.images.forEach(function (img, idx) {
            var $item = $('<div class="brikpanel-pe-gallery-item" data-id="' + img.id + '">');
            $item.append('<img src="' + esc(img.url) + '" alt="">');
            if (idx === 0) $item.append('<span class="brikpanel-pe-gallery-item-badge">' + (PE.i18n.featured || 'Featured') + '</span>');
            var $rm = $('<button type="button" class="brikpanel-pe-gallery-item-remove">&times;</button>');
            $rm.on('click', function (e) { e.stopPropagation(); removeImage(img.id); });
            $item.append($rm);
            $g.append($item);
        });
        $g.sortable('refresh');
    }

    function syncImageOrder() {
        var o = [];
        $('#bpe-gallery .brikpanel-pe-gallery-item').each(function () {
            var id = parseInt($(this).data('id'), 10);
            var f = state.images.find(function (i) { return i.id === id; });
            if (f) o.push(f);
        });
        state.images = o;
        renderGallery();
    }

    function initPriceInputs() {
        $(document).on('input', '[data-price]', function () {
            var sep = PE.decimal_sep || ',';
            this.value = this.value.replace(sep === ',' ? /[^0-9,]/g : /[^0-9.]/g, '');
        });
    }

    function initCharCounter() { /* char counter removed — short description now supports HTML with no length limit */ }

    /* Keep editor contenteditable in sync with its HTML-source textarea. */
    function syncEditorFromSource($field) {
        var $editor = $field.find('.brikpanel-pe-editor');
        var $source = $field.find('.brikpanel-pe-editor-source');
        if ($source.is(':visible') || !$source.prop('hidden')) {
            $editor.html($source.val());
        }
    }

    function getEditorHtml(id) {
        var $field = $('#' + id).closest('[data-editor-field]');
        var $source = $field.find('.brikpanel-pe-editor-source');
        if (!$source.prop('hidden')) {
            // HTML source mode is active — trust the textarea value.
            return $source.val();
        }
        return $('#' + id).html();
    }

    function initCategorySearch() {
        $('#bpe-cat-search').on('input', function () {
            var q = this.value.toLowerCase();
            $('.brikpanel-pe-cat-tree li').each(function () {
                var name = $(this).data('name') || '';
                var match = name.indexOf(q) !== -1 || q === '';
                $(this).toggle(match);
                // Show parent chain if child matches
                if (match && q) $(this).parents('li').show();
            });
        });
    }

    function addCategory() {
        var $btn = $('#bpe-add-cat-btn');
        if ($btn.prop('disabled')) return;

        var name = $.trim($('#bpe-new-cat-name').val());
        var parent = parseInt($('#bpe-new-cat-parent').val(), 10) || 0;
        if (!name) return;

        // Current client-side selection — preserved across the re-render so
        // the user doesn't lose pending checkbox changes.
        var selected = $('input[name="category_ids[]"]:checked').map(function () {
            return this.value;
        }).get();

        $btn.prop('disabled', true);
        $.post(PE.ajax_url, {
            action: 'brikpanel_add_category',
            security: PE.nonce,
            name: name,
            parent: parent,
            selected_ids: selected
        }, function (r) {
            $btn.prop('disabled', false);
            if (!r.success) {
                showToast((r.data && r.data.message) || 'Error', 'error');
                return;
            }
            var d = r.data;

            // Swap checklist — server-rendered HTML keeps depth classes,
            // hierarchical order, and the newly created term pre-checked.
            $('.brikpanel-pe-cat-list').html(d.checklist_html);

            // Rebuild parent dropdown while preserving the "— No parent —"
            // sentinel and keeping the user's previously-selected parent
            // if it still exists.
            var prevParent = $('#bpe-new-cat-parent').val();
            var $select = $('#bpe-new-cat-parent');
            $select.find('option').not('[value="0"]').remove();
            $select.append(d.options_html);
            if (prevParent && $select.find('option[value="' + prevParent + '"]').length) {
                $select.val(prevParent);
            } else {
                $select.val('0');
            }

            $('#bpe-new-cat-name').val('').focus();

            // Re-run the search filter so the fresh DOM respects the
            // active query instead of showing every item again.
            var $search = $('#bpe-cat-search');
            if ($search.val()) $search.trigger('input');

            showToast(PE.i18n.category_added || 'Category added', 'success');
        }).fail(function () {
            $btn.prop('disabled', false);
            showToast('Error', 'error');
        });
    }

    /* Tags the rich-text editor + paste sanitizer are allowed to keep. Block
       and inline maps are intentionally lean: a small, predictable subset that
       every toolbar control can still tweak afterwards (the user's complaint
       was that pasted website markup could only be fixed via the code view). */
    var EDITOR_BLOCK_TAGS  = { P:1, H2:1, H3:1, H4:1, H5:1, H6:1, UL:1, OL:1, LI:1, BLOCKQUOTE:1 };
    var EDITOR_INLINE_MAP  = { STRONG:'strong', B:'strong', EM:'em', I:'em', U:'u', A:'a', CODE:'code' };
    var EDITOR_DROP_TAGS   = { SCRIPT:1, STYLE:1, NOSCRIPT:1, IMG:1, IFRAME:1, VIDEO:1, AUDIO:1, OBJECT:1, EMBED:1, SVG:1, FORM:1, INPUT:1, BUTTON:1, META:1, LINK:1, HEAD:1 };

    function editorSafeUrl(u) {
        u = (u == null ? '' : ('' + u)).trim();
        if (u === '') return '';
        if (/^(https?:|mailto:|tel:)/i.test(u)) return u;
        if (/^(\/|#|\?)/.test(u)) return u;            // root-relative / anchor
        if (/^[\w.-]+(\.[\w.-]+)+(\/.*)?$/.test(u)) return 'https://' + u; // bare domain
        if (/^[^:/?#\s]+(\/.*)?$/.test(u)) return u;   // relative path
        return '';
    }
    function editorEscAttr(s) { return ('' + s).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); }

    /* Convert arbitrary pasted HTML into the lean allowed subset. Unknown
       wrappers (span, font, div, msword cruft…) are unwrapped, all inline
       styles/classes dropped, links re-validated. Returns clean HTML string. */
    function sanitizeEditorHtml(html) {
        var doc;
        try { doc = new DOMParser().parseFromString(html, 'text/html'); } catch (e) { return null; }
        if (!doc || !doc.body) return null;

        function walk(node) {
            var out = '';
            var kids = node.childNodes;
            for (var i = 0; i < kids.length; i++) {
                var child = kids[i];
                if (child.nodeType === 3) { out += esc(child.nodeValue); continue; }
                if (child.nodeType !== 1) continue;
                var tag = child.tagName;
                if (tag === 'BR') { out += '<br>'; continue; }
                if (EDITOR_DROP_TAGS[tag]) continue;
                var inner = walk(child);
                if (tag === 'H1') tag = 'H2';
                if (EDITOR_BLOCK_TAGS[tag]) {
                    var lt = tag.toLowerCase();
                    if (tag === 'LI') { out += '<li>' + inner + '</li>'; }
                    else if (inner.replace(/<br>/g, '').trim() !== '' || tag === 'UL' || tag === 'OL') { out += '<' + lt + '>' + inner + '</' + lt + '>'; }
                } else if (EDITOR_INLINE_MAP[tag]) {
                    var m = EDITOR_INLINE_MAP[tag];
                    if (m === 'a') {
                        var href = editorSafeUrl(child.getAttribute('href'));
                        out += href ? '<a href="' + editorEscAttr(href) + '" rel="noopener">' + inner + '</a>' : inner;
                    } else if (inner !== '') {
                        out += '<' + m + '>' + inner + '</' + m + '>';
                    }
                } else if (tag === 'DIV' || tag === 'SECTION' || tag === 'ARTICLE' || tag === 'TD' || tag === 'TH') {
                    out += inner.replace(/<br>/g, '').trim() !== '' ? '<p>' + inner + '</p>' : '';
                } else {
                    out += inner; // span/font/etc — unwrap, keep content
                }
            }
            return out;
        }
        return walk(doc.body)
            .replace(/<p>(\s|<br>)*<\/p>/gi, '')
            .replace(/[ \t]{2,}/g, ' ')
            .trim();
    }

    /* Remember the live selection inside an editor so the heading <select>
       (which steals focus when opened) can restore it before formatting. */
    function rememberEditorRange($field) {
        var sel = window.getSelection();
        if (!sel || sel.rangeCount === 0) return;
        var ed = $field.find('.brikpanel-pe-editor')[0];
        if (ed && ed.contains(sel.getRangeAt(0).commonAncestorContainer)) {
            $field.data('bpRange', sel.getRangeAt(0).cloneRange());
        }
    }
    function restoreEditorRange($field) {
        var r = $field.data('bpRange');
        var ed = $field.find('.brikpanel-pe-editor')[0];
        if (!ed) return;
        ed.focus();
        if (r) { var sel = window.getSelection(); sel.removeAllRanges(); sel.addRange(r); }
    }

    /* Reflect caret context on the toolbar: active inline buttons + the
       block-format label/active item shown by the custom heading dropdown. */
    function refreshEditorToolbar($field) {
        var ed = $field.find('.brikpanel-pe-editor')[0];
        if (!ed) return;
        function st(c) { try { return document.queryCommandState(c); } catch (e) { return false; } }
        $field.find('[data-cmd="bold"]').toggleClass('is-active', st('bold'));
        $field.find('[data-cmd="italic"]').toggleClass('is-active', st('italic'));
        $field.find('[data-cmd="insertUnorderedList"]').toggleClass('is-active', st('insertUnorderedList'));
        $field.find('[data-cmd="insertOrderedList"]').toggleClass('is-active', st('insertOrderedList'));
        var sel = window.getSelection();
        var block = 'p';
        if (sel && sel.rangeCount) {
            var n = sel.getRangeAt(0).commonAncestorContainer;
            while (n && n !== ed) {
                if (n.nodeType === 1) {
                    var t = n.tagName.toLowerCase();
                    if (t === 'h2' || t === 'h3' || t === 'h4') { block = t; break; }
                    if (t === 'p' || t === 'div' || t === 'li' || /^h[1-6]$/.test(t)) { block = (t === 'h1') ? 'h2' : (/^h[56]$/.test(t) ? 'p' : (t === 'div' || t === 'li' ? 'p' : t)); break; }
                }
                n = n.parentNode;
            }
        }
        if (['p', 'h2', 'h3', 'h4'].indexOf(block) === -1) block = 'p';
        var $items = $field.find('.brikpanel-pe-fmt-item');
        $items.removeClass('is-active');
        var $active = $items.filter('[data-format="' + block + '"]').addClass('is-active');
        if ($active.length) {
            $field.find('.brikpanel-pe-fmt-label').text($active.text());
        }
    }

    function runEditorCommand($field, cmd, value) {
        var $editor = $field.find('.brikpanel-pe-editor');
        var $source = $field.find('.brikpanel-pe-editor-source');
        if (!$source.prop('hidden')) return; // visual mode only
        $editor.focus();
        if (cmd === 'formatBlock') {
            document.execCommand('formatBlock', false, '<' + value + '>');
        } else {
            document.execCommand(cmd, false, null);
        }
        if (cmd === 'insertOrderedList' || cmd === 'insertUnorderedList') {
            // execCommand can leave the new list wrapped in a <p> (invalid:
            // a <p> may not contain block elements). Unwrap those.
            $editor.find('p > ul:only-child, p > ol:only-child').each(function () {
                $(this).unwrap();
            });
        }
        state.dirty = true;
        refreshEditorToolbar($field);
    }

    function closeFmtMenus() {
        $('.brikpanel-pe-fmt.is-open').removeClass('is-open')
            .find('.brikpanel-pe-fmt-trigger').attr('aria-expanded', 'false');
    }

    /* ---- Link dialog (URL + "open in new tab") ------------------------- */
    var linkDlgState = { $field: null, anchor: null };

    function buildLinkDialog() {
        if (document.getElementById('bpe-link-dialog')) return;
        var i = PE.i18n || {};
        var html =
            '<div id="bpe-link-dialog" class="brikpanel-pe-linkdlg" hidden>' +
              '<div class="brikpanel-pe-linkdlg-backdrop"></div>' +
              '<div class="brikpanel-pe-linkdlg-box" role="dialog" aria-modal="true" aria-label="' + esc(i.link_title || 'Insert link') + '">' +
                '<div class="brikpanel-pe-linkdlg-title">' + esc(i.link_title || 'Insert link') + '</div>' +
                '<label class="brikpanel-pe-linkdlg-lbl" for="bpe-link-url">' + esc(i.link_url || 'URL') + '</label>' +
                '<input type="text" id="bpe-link-url" class="brikpanel-pe-linkdlg-url" placeholder="https://example.com" autocomplete="off" spellcheck="false">' +
                '<label class="brikpanel-pe-linkdlg-check"><input type="checkbox" id="bpe-link-newtab"><span>' + esc(i.link_new_tab || 'Open in a new tab') + '</span></label>' +
                '<div class="brikpanel-pe-linkdlg-actions">' +
                  '<button type="button" class="brikpanel-pe-btn secondary brikpanel-pe-linkdlg-cancel">' + esc(i.link_cancel || 'Cancel') + '</button>' +
                  '<button type="button" class="brikpanel-pe-btn primary brikpanel-pe-linkdlg-ok">' + esc(i.link_insert || 'Insert link') + '</button>' +
                '</div>' +
              '</div>' +
            '</div>';
        $('body').append(html);

        var $dlg = $('#bpe-link-dialog');
        function closeDlg() {
            $dlg.attr('hidden', true);
            linkDlgState.$field = null;
            linkDlgState.anchor = null;
        }
        $dlg.find('.brikpanel-pe-linkdlg-cancel, .brikpanel-pe-linkdlg-backdrop').on('click', closeDlg);
        $dlg.on('keydown', function (e) {
            if (e.key === 'Escape') closeDlg();
            if (e.key === 'Enter' && e.target.id === 'bpe-link-url') { e.preventDefault(); $dlg.find('.brikpanel-pe-linkdlg-ok').click(); }
        });
        $dlg.find('.brikpanel-pe-linkdlg-ok').on('click', function () {
            var $field = linkDlgState.$field;
            if (!$field) { closeDlg(); return; }
            var url = editorSafeUrl($('#bpe-link-url').val());
            if (!url) { $('#bpe-link-url').focus(); return; }
            var newTab = $('#bpe-link-newtab').is(':checked');
            var anchor = linkDlgState.anchor;
            closeDlg();
            restoreEditorRange($field);
            var ed = $field.find('.brikpanel-pe-editor')[0];

            function decorate(a) {
                if (!a) return;
                a.setAttribute('href', url);
                if (newTab) {
                    a.setAttribute('target', '_blank');
                    a.setAttribute('rel', 'noopener noreferrer');
                } else {
                    a.removeAttribute('target');
                    a.removeAttribute('rel');
                }
            }

            if (anchor && ed && ed.contains(anchor)) {
                // Editing an existing link (incl. just toggling new-tab).
                decorate(anchor);
            } else {
                var sel = window.getSelection();
                if (sel && sel.toString().length) {
                    document.execCommand('createLink', false, url);
                    // Tag every anchor the command produced in the selection.
                    var node = sel.anchorNode;
                    var a = node && (node.nodeType === 1 ? node : node.parentNode);
                    a = a && a.closest ? a.closest('a[href]') : null;
                    if (a) { decorate(a); }
                    else { $(ed).find('a[href="' + url.replace(/"/g, '\\"') + '"]').each(function () { decorate(this); }); }
                } else {
                    var rel = newTab ? ' target="_blank" rel="noopener noreferrer"' : '';
                    document.execCommand('insertHTML', false, '<a href="' + editorEscAttr(url) + '"' + rel + '>' + esc(url) + '</a>');
                }
            }
            state.dirty = true;
            refreshEditorToolbar($field);
        });
    }

    function openLinkDialog($field) {
        buildLinkDialog();
        // Save the live selection before the dialog input steals focus.
        rememberEditorRange($field);
        var ed = $field.find('.brikpanel-pe-editor')[0];
        var sel = window.getSelection();
        var anchor = null;
        if (ed && sel && sel.rangeCount) {
            var n = sel.getRangeAt(0).commonAncestorContainer;
            n = (n && n.nodeType === 1) ? n : (n ? n.parentNode : null);
            if (n && n.closest) {
                var a = n.closest('a[href]');
                if (a && ed.contains(a)) anchor = a;
            }
        }
        linkDlgState.$field = $field;
        linkDlgState.anchor = anchor;
        $('#bpe-link-url').val(anchor ? anchor.getAttribute('href') : '');
        $('#bpe-link-newtab').prop('checked', anchor ? (anchor.getAttribute('target') === '_blank') : false);
        $('#bpe-link-dialog').removeAttr('hidden');
        setTimeout(function () { $('#bpe-link-url').focus().select(); }, 0);
    }

    function initEditor() {
        buildLinkDialog();

        // Keep the caret inside the editable when a toolbar control is pressed
        // (mousedown would otherwise blur the selection first). This is what
        // makes every command — including the heading menu — reliable.
        $('.brikpanel-pe-editor-toolbar').on('mousedown', 'button', function (e) { e.preventDefault(); });

        $('.brikpanel-pe-editor-toolbar').on('click', 'button', function (e) {
            e.preventDefault();
            var $btn = $(this);
            var cmd = $btn.data('cmd');
            var $field = $btn.closest('[data-editor-field]');
            var $editor = $field.find('.brikpanel-pe-editor');
            var $source = $field.find('.brikpanel-pe-editor-source');

            // Custom heading dropdown — trigger toggles, items apply.
            if ($btn.hasClass('brikpanel-pe-fmt-trigger')) {
                var $fmt = $btn.closest('.brikpanel-pe-fmt');
                var willOpen = !$fmt.hasClass('is-open');
                closeFmtMenus();
                $fmt.toggleClass('is-open', willOpen);
                $btn.attr('aria-expanded', willOpen ? 'true' : 'false');
                return;
            }
            if ($btn.hasClass('brikpanel-pe-fmt-item')) {
                closeFmtMenus();
                if ($source.prop('hidden')) {
                    runEditorCommand($field, 'formatBlock', $btn.data('format'));
                }
                return;
            }

            if (cmd === 'html') {
                var isSource = !$source.prop('hidden');
                var $controls = $field.find('.brikpanel-pe-editor-toolbar button').not('.brikpanel-pe-fmt-item');
                if (isSource) {
                    // Switch back to visual
                    $editor.html($source.val());
                    $source.prop('hidden', true);
                    $editor.prop('hidden', false);
                    $btn.removeClass('is-active');
                    $controls.not(this).prop('disabled', false);
                    $editor.focus();
                    refreshEditorToolbar($field);
                } else {
                    // Switch to HTML source
                    $source.val($editor.html());
                    $editor.prop('hidden', true);
                    $source.prop('hidden', false);
                    $btn.addClass('is-active');
                    $controls.not(this).prop('disabled', true);
                    closeFmtMenus();
                    $source.focus();
                }
                state.dirty = true;
                return;
            }

            if (cmd === 'createLink') {
                if ($source.prop('hidden')) openLinkDialog($field);
                return;
            }

            runEditorCommand($field, cmd);
        });

        // Close the heading menu on outside click / Escape.
        $(document).on('click', function (e) {
            if (!$(e.target).closest('.brikpanel-pe-fmt').length) closeFmtMenus();
        });
        $(document).on('keydown', function (e) {
            if (e.key === 'Escape') closeFmtMenus();
        });

        // Sanitize pasted website content down to the editable subset so it
        // stays tweakable from the toolbar instead of only the code view.
        $('.brikpanel-pe-editor').on('paste', function (e) {
            var cd = e.originalEvent && e.originalEvent.clipboardData;
            if (!cd) return; // very old browser — let default happen
            var html = cd.getData('text/html');
            if (!html) return; // plain-text paste — browser default is fine
            e.preventDefault();
            var clean = sanitizeEditorHtml(html);
            if (clean == null) { clean = esc(cd.getData('text/plain') || ''); }
            var ed = this;
            var wasEmpty = ed.textContent.trim() === '' && !/<(img|hr|ul|ol|table)/i.test(ed.innerHTML);
            document.execCommand('insertHTML', false, clean);
            // Pasting into an empty editable leaves stray empty <p></p>
            // wrappers around the inserted content (an insertHTML quirk).
            // Tidy them and drop the caret at the end so typing continues
            // naturally.
            if (wasEmpty) {
                ed.innerHTML = ed.innerHTML
                    .replace(/^(?:<p>(?:\s|<br\s*\/?>|&nbsp;)*<\/p>)+/i, '')
                    .replace(/(?:<p>(?:\s|<br\s*\/?>|&nbsp;)*<\/p>)+$/i, '');
                var rng = document.createRange();
                rng.selectNodeContents(ed);
                rng.collapse(false);
                var sel2 = window.getSelection();
                sel2.removeAllRanges();
                sel2.addRange(rng);
            }
            state.dirty = true;
            refreshEditorToolbar($(this).closest('[data-editor-field]'));
        });

        // Keep toolbar state in sync with the caret.
        $('.brikpanel-pe-editor').on('keyup mouseup focus', function () {
            var $field = $(this).closest('[data-editor-field]');
            rememberEditorRange($field);
            refreshEditorToolbar($field);
        });

        // Keep textarea value live so autosave/submit always reads fresh HTML.
        $(document).on('input', '.brikpanel-pe-editor-source', function () {
            state.dirty = true;
        });
    }

    /* ====== Variation Wizard ====== */
    /* Synonym lists for the Beden+Renk template. Match what the backend uses
       in detect_size_color_role() — keep the two in sync. */
    var SIZE_COLOR_SYNONYMS = {
        size:  ['size', 'beden'],
        color: ['color', 'colour', 'renk']
    };

    /* Find an existing global attribute that represents the given role
       (size|color) by slug, label, or taxonomy. Returns the global-attribute
       entry or null. Lets the size+color template pre-attach its taxonomy so
       suggestions and saved terms use the real attribute right away. */
    function findGlobalAttrForRole(role) {
        var globals = productData.global_attributes || [];
        var synonyms = SIZE_COLOR_SYNONYMS[role] || [];
        for (var i = 0; i < globals.length; i++) {
            var g = globals[i];
            var slug = String(g.slug || '').toLowerCase();
            var name = String(g.name || '').toLowerCase();
            var tax  = String(g.taxonomy || '').toLowerCase();
            if (synonyms.indexOf(slug) !== -1
                || synonyms.indexOf(name) !== -1
                || tax === 'pa_' + role) {
                return g;
            }
        }
        return null;
    }

    function selectTemplate(template) {
        // Re-selecting the same template after going Back must not wipe the
        // values the user already typed — only advance to step 2.
        if (state.varTemplate === template && $('#bpe-var-attributes').children().length) {
            showVarStep(2);
            return;
        }
        state.varTemplate = template;
        $('.brikpanel-pe-var-template').removeClass('active');
        $('.brikpanel-pe-var-template[data-template="' + template + '"]').addClass('active');
        var $attrs = $('#bpe-var-attributes').empty();

        if (template === 'size-color') {
            // If a matching global Size/Color attribute already exists, attach
            // its taxonomy so the user gets term suggestions and the save
            // path uses the real taxonomy directly. Otherwise leave taxonomy
            // empty — the backend promotes it on publish.
            var sizeAttr  = findGlobalAttrForRole('size');
            var colorAttr = findGlobalAttrForRole('color');
            $attrs.append(createTagGroup(
                sizeAttr ? sizeAttr.name : (PE.i18n.size || 'Size'),
                ['S', 'M', 'L', 'XL', 'XXL'],
                sizeAttr ? sizeAttr.taxonomy : ''
            ));
            $attrs.append(createTagGroup(
                colorAttr ? colorAttr.name : (PE.i18n.color || 'Color'),
                [],
                colorAttr ? colorAttr.taxonomy : ''
            ));
        } else if (template === 'custom') {
            $attrs.append(createCustomAttrUI());
        }
        showVarStep(2);
    }

    function createCustomAttrUI() {
        var $wrap = $('<div>');
        var globalAttrs = productData.global_attributes || [];

        // Existing attributes dropdown
        if (globalAttrs.length) {
            var $selectWrap = $('<div class="brikpanel-pe-attr-select-wrap">');
            var $select = $('<select class="brikpanel-pe-attr-select"><option value="">' + (PE.i18n.select_attribute || 'Select existing attribute...') + '</option></select>');
            globalAttrs.forEach(function (a) {
                $select.append('<option value="' + esc(a.name) + '" data-taxonomy="' + esc(a.taxonomy || '') + '" data-terms=\'' + JSON.stringify(a.terms) + '\'>' + esc(a.name) + '</option>');
            });
            $select.on('change', function () {
                var name = this.value;
                if (!name) return;
                var $opt = $(this).find(':selected');
                var taxonomy = $opt.data('taxonomy') || '';
                var rawTerms = $opt.data('terms');
                var terms = Array.isArray(rawTerms) ? rawTerms : [];
                if (typeof rawTerms === 'string') { try { terms = JSON.parse(rawTerms); } catch (e) { terms = []; } }
                // Prevent duplicate
                if ($('#bpe-custom-attrs-list .brikpanel-pe-tag-group[data-attr-name="' + name + '"]').length) { this.value = ''; return; }
                $('#bpe-custom-attrs-list').append(createTagGroup(name, terms, taxonomy));
                this.value = '';
            });
            $selectWrap.append($select);
            $wrap.append($selectWrap);
            $wrap.append('<div class="brikpanel-pe-attr-divider">' + (PE.i18n.or_create_new || 'or create new') + '</div>');
        }

        // Create new attribute
        var $nameRow = $('<div class="brikpanel-pe-inline-form" style="margin-bottom:.75rem">');
        var $nameInput = $('<input type="text" placeholder="' + (PE.i18n.attribute_name || 'Attribute name (e.g.: Material)') + '">');
        var $addBtn = $('<button type="button" class="brikpanel-pe-btn secondary small">' + (PE.i18n.add_attribute || 'Add') + '</button>');
        $addBtn.on('click', function () {
            var name = $.trim($nameInput.val());
            if (name && !$('#bpe-custom-attrs-list .brikpanel-pe-tag-group[data-attr-name="' + name + '"]').length) {
                $('#bpe-custom-attrs-list').append(createTagGroup(name, []));
                $nameInput.val('').focus();
            }
        });
        $nameInput.on('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); $addBtn.click(); } });
        $nameRow.append($nameInput, $addBtn);
        $wrap.append($nameRow, '<div id="bpe-custom-attrs-list"></div>');
        return $wrap;
    }

    function createTagGroup(name, defaults, taxonomy) {
        var taxAttr = taxonomy ? ' data-attr-taxonomy="' + esc(taxonomy) + '"' : '';
        var $group = $('<div class="brikpanel-pe-tag-group" data-attr-name="' + esc(name) + '"' + taxAttr + '>');
        $group.append('<label>' + esc(name) + '</label>');
        var $inputWrap = $('<div class="brikpanel-pe-attr-input-container">');
        var $wrap = $('<div class="brikpanel-pe-tag-input-wrap">');
        var $input = $('<input type="text" placeholder="' + (PE.i18n.type_enter_value || 'Press Enter to add...') + '" autocomplete="off">');
        defaults.forEach(function (v) { $wrap.append(createTag(v)); });

        // Find available terms for this attribute from global attributes
        var globalAttrs = productData.global_attributes || [];
        var availableTerms = [];
        globalAttrs.forEach(function (a) {
            if (a.name === name || a.taxonomy === name || a.slug === name) { availableTerms = (a.terms || []).slice(); }
        });

        var $suggestions = $('<div class="brikpanel-pe-tag-suggestions brikpanel-pe-attr-term-suggestions">');

        function getExistingTags() {
            var tags = [];
            $wrap.find('.brikpanel-pe-tag').each(function () {
                tags.push($(this).clone().children().remove().end().text().trim().toLowerCase());
            });
            return tags;
        }

        function showTermSuggestions(filter) {
            if (!availableTerms.length) { $suggestions.hide(); return; }
            var existing = getExistingTags();
            var q = (filter || '').toLowerCase();
            var matches = availableTerms.filter(function (t) {
                return existing.indexOf(t.toLowerCase()) === -1 && (!q || t.toLowerCase().indexOf(q) !== -1);
            }).slice(0, 10);
            if (!matches.length) { $suggestions.hide(); return; }
            var html = '';
            matches.forEach(function (t) {
                html += '<div class="brikpanel-pe-tag-suggestion" data-value="' + esc(t) + '">' + esc(t) + '</div>';
            });
            $suggestions.html(html).show();
        }

        $input.on('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); var v = $.trim(this.value); if (v && !tagExists($wrap, v)) { $input.before(createTag(v)); this.value = ''; showTermSuggestions(''); } }
            if (e.key === 'Backspace' && !this.value) { $wrap.find('.brikpanel-pe-tag:last').remove(); showTermSuggestions(''); }
        });

        $input.on('input', function () { showTermSuggestions($.trim(this.value)); });
        $input.on('focus', function () { showTermSuggestions($.trim(this.value)); });
        $input.on('blur', function () { setTimeout(function () { $suggestions.hide(); }, 150); });

        $suggestions.on('mousedown', '.brikpanel-pe-tag-suggestion', function (e) {
            e.preventDefault();
            var v = $(this).data('value');
            if (v && !tagExists($wrap, v)) {
                $input.before(createTag(v));
                $input.val('');
                showTermSuggestions('');
            }
        });

        $wrap.append($input);
        $wrap.on('click', function () { $input.focus(); });
        $inputWrap.append($wrap, $suggestions);
        $group.append($inputWrap);
        return $group;
    }

    function createTag(value) {
        var $tag = $('<span class="brikpanel-pe-tag">' + esc(value) + '</span>');
        var $rm = $('<button type="button" class="brikpanel-pe-tag-remove">&times;</button>');
        $rm.on('click', function () { $tag.remove(); });
        $tag.append($rm);
        return $tag;
    }

    function tagExists($w, val) {
        var e = false;
        $w.find('.brikpanel-pe-tag').each(function () { if ($(this).clone().children().remove().end().text().trim().toLowerCase() === val.toLowerCase()) e = true; });
        return e;
    }

    /* ====== Product Attributes (specs — independent from variations) ====== */
    function initAttributes() {
        var $card = $('#bpe-attr-card');
        if (!$card.length) return;

        var $list = $('#bpe-attr-list');

        // Wire up the taxonomy attribute selector. Selecting an option appends
        // a tag-group pre-filled with that taxonomy's terms.
        $('#bpe-attr-select').on('change', function () {
            var name = this.value;
            if (!name) return;
            var $opt = $(this).find(':selected');
            var taxonomy = $opt.attr('data-taxonomy') || '';
            var rawTerms = $opt.attr('data-terms');
            var terms = [];
            if (rawTerms) { try { terms = JSON.parse(rawTerms) || []; } catch (e) { terms = []; } }
            if (attributeExistsInList(name)) { this.value = ''; return; }
            $list.append(createAttributeRow(name, terms, taxonomy));
            this.value = '';
            state.dirty = true;
        });

        // Wire up the "Add" button for new custom (non-taxonomy) attributes.
        var $newName = $('#bpe-attr-new-name');
        $('#bpe-attr-add').on('click', function () {
            var name = $.trim($newName.val());
            if (!name || attributeExistsInList(name)) { $newName.focus(); return; }
            $list.append(createAttributeRow(name, [], ''));
            $newName.val('').focus();
            state.dirty = true;
        });
        $newName.on('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); $('#bpe-attr-add').click(); }
        });
    }

    function attributeExistsInList(name) {
        var lower = String(name).toLowerCase();
        var found = false;
        $('#bpe-attr-list .brikpanel-pe-tag-group').each(function () {
            if (String($(this).attr('data-attr-name') || '').toLowerCase() === lower) found = true;
        });
        return found;
    }

    /* Wraps createTagGroup with a remove-attribute button so users can drop a
       whole spec from the product without clearing every tag manually. */
    function createAttributeRow(name, defaults, taxonomy) {
        var $row = $('<div class="brikpanel-pe-attr-row">');
        var $group = createTagGroup(name, defaults, taxonomy);
        var $remove = $('<button type="button" class="brikpanel-pe-attr-remove" aria-label="' + esc(PE.i18n.remove || 'Remove') + '" title="' + esc(PE.i18n.remove || 'Remove') + '">&times;</button>');
        $remove.on('click', function () {
            $row.remove();
            state.dirty = true;
        });
        $group.find('> label').first().append($remove);
        $row.append($group);
        return $row;
    }

    function collectNonVariationAttributes() {
        var a = [], seen = {};
        $('#bpe-attr-list .brikpanel-pe-tag-group').each(function () {
            var $g = $(this);
            var name = $g.attr('data-attr-name');
            var taxonomy = $g.attr('data-attr-taxonomy') || '';
            var vals = [];
            $g.find('.brikpanel-pe-tag').each(function () {
                vals.push($(this).clone().children().remove().end().text().trim());
            });
            if (!name || !vals.length) return;
            var key = String(name).toLowerCase();
            if (seen[key]) return;
            seen[key] = true;
            a.push({ name: name, values: vals, taxonomy: taxonomy });
        });
        return a;
    }

    function showVarStep(s) {
        $('.brikpanel-pe-var-step').hide();
        $('.brikpanel-pe-var-step[data-step="' + s + '"]').show();
        updateVarWizardNav();
    }

    /* Show the Forward (→) button only when the next step has something to
       show. Lets the user retrace step1 → step2 → step3 after clicking Back
       without re-running the template picker or Generate. */
    function updateVarWizardNav() {
        var hasAttrs = $('#bpe-var-attributes').children().length > 0;
        var hasVars  = state.variations && state.variations.length > 0;
        $('#bpe-var-forward-1').toggle(hasAttrs);
        $('#bpe-var-forward-2').toggle(hasVars);
    }

    /* Sync the variation table's live DOM inputs back into state.variations.
       Called before any step transition that may rebuild the table so the
       user's in-flight edits (prices, stock, SKU, dates, vendor, cogs) are
       not silently dropped when they go Back and re-generate. */
    function captureVarTableInputs() {
        var sep = PE.decimal_sep || ',';
        $('#bpe-var-table-body tr.var-main-row').each(function (idx) {
            var v = state.variations[idx]; if (!v) return;
            var $row = $(this);
            v.regular_price  = parsePrice($row.find('.var-price').val(), sep);
            v.sale_price     = parsePrice($row.find('.var-sale-price').val(), sep);
            v.sale_from      = $row.find('.var-sale-from').val() || '';
            v.sale_to        = $row.find('.var-sale-to').val()   || '';
            v.manage_stock   = $row.find('.var-manage').is(':checked');
            v.stock_quantity = $row.find('.var-stock').val();
            v.stock_status   = $row.find('.var-stock-status').val() || 'instock';
            v.sku            = $row.find('.var-sku').val();
            var $vgtin = $row.find('.var-gtin');
            if ($vgtin.length) v.global_unique_id = $vgtin.val();
            var $vtax = $row.find('.var-tax-class');
            if ($vtax.length) v.tax_class = $vtax.val();
            var $vship = $row.find('.var-shipping-class');
            if ($vship.length) v.shipping_class = $vship.val();
            var $cogs = $row.find('.var-cogs');
            if ($cogs.length) v.cogs_value = parsePrice($cogs.val(), sep);
            var $vendor = $row.find('.var-vendor');
            if ($vendor.length) v.vendor_id = parseInt($vendor.val(), 10) || 0;
            if (v.stock_status === 'onbackorder') {
                var $back = $('#bpe-var-table-body tr.var-backorder-row[data-idx="' + idx + '"] input[type="radio"]:checked');
                if ($back.length) v.backorders = $back.val() === 'notify' ? 'notify' : 'yes';
            }
        });
    }

    function collectAttributes() {
        var a = [], seen = {};
        // Scope to the variations container so unrelated tag-groups (if any
        // ever appear elsewhere) can't interleave with attribute order, and
        // de-dupe by name in case the same attribute was inserted twice.
        $('#bpe-var-attributes .brikpanel-pe-tag-group').each(function () {
            var $g = $(this);
            var name = $g.attr('data-attr-name'), vals = [];
            $g.find('.brikpanel-pe-tag').each(function () { vals.push($(this).clone().children().remove().end().text().trim()); });
            if (!name || !vals.length) return;
            var key = String(name).toLowerCase();
            if (seen[key]) return;
            seen[key] = true;
            a.push({ name: name, values: vals, taxonomy: $g.attr('data-attr-taxonomy') || '' });
        });
        return a;
    }

    function generateVariations() {
        state.varAttributes = collectAttributes();
        if (!state.varAttributes.length) return;
        var combos = genCombinations(state.varAttributes), baseSKU = $('#bpe-sku').val() || '';
        state.variations = combos.map(function (combo) {
            var ex = findExVar(combo), np = [], sp = [baseSKU];
            Object.keys(combo).forEach(function (k) { np.push(combo[k]); sp.push(slugify(combo[k])); });
            return { id: ex ? ex.id : 0, attributes: combo, name: np.join(' - '),
                regular_price: ex ? ex.regular_price : '', sale_price: ex ? ex.sale_price : '',
                stock_quantity: ex ? (ex.stock_quantity !== null ? ex.stock_quantity : '') : '',
                stock_status: ex ? (ex.stock_status || 'instock') : 'instock',
                // New variations track stock by default (quantity column
                // active), preserving the prior behavior; existing ones keep
                // whatever was saved.
                manage_stock: ex ? !!ex.manage_stock : true,
                backorders: ex ? (ex.backorders || 'no') : 'no',
                sale_from: ex ? (ex.sale_from || '') : '',
                sale_to:   ex ? (ex.sale_to   || '') : '',
                sku: ex ? ex.sku : sp.filter(Boolean).join('-').toUpperCase(),
                global_unique_id: ex ? (ex.global_unique_id || '') : '',
                tax_class: ex ? (ex.tax_class !== undefined && ex.tax_class !== null ? ex.tax_class : 'parent') : 'parent',
                shipping_class: ex ? (ex.shipping_class !== undefined && ex.shipping_class !== null ? ex.shipping_class : '') : '',
                images: ex && ex.images ? ex.images : [],
                cogs_value: ex ? (ex.cogs_value || '') : '',
                vendor_id:  ex ? (ex.vendor_id || 0) : 0,
                vendor_sku: ex ? (ex.vendor_sku || '') : '' };
        });
        renderVarTable();
        showVarStep(3);
    }

    function genCombinations(attrs) {
        return attrs.reduce(function (combos, attr) {
            // Taxonomy attributes must key on their taxonomy slug (e.g. `pa_renk`)
            // so the variation's attribute keys match WC's internal lookup.
            var slug = attr.taxonomy ? attr.taxonomy : slugify(attr.name);
            if (!combos.length) return attr.values.map(function (v) { var o = {}; o[slug] = v; return o; });
            var r = [];
            combos.forEach(function (c) { attr.values.forEach(function (v) { var n = $.extend({}, c); n[slug] = v; r.push(n); }); });
            return r;
        }, []);
    }

    function findExVar(combo) {
        // Look in the in-flight state first so user edits made before clicking
        // Back survive when the table is regenerated. Fall back to the server-
        // hydrated snapshot for the initial render of a saved product.
        var pools = [];
        if (state.variations && state.variations.length) pools.push(state.variations);
        if (productData.variations && productData.variations.length) pools.push(productData.variations);
        function matches(v) {
            if (!v || !v.attributes) return false;
            return Object.keys(combo).every(function (k) {
                return (v.attributes[k] || '').toString().toLowerCase() === (combo[k] || '').toString().toLowerCase();
            });
        }
        for (var p = 0; p < pools.length; p++) {
            var hit = pools[p].find(matches);
            if (hit) return hit;
        }
        return null;
    }

    function renderVarTable() {
        var $tb = $('#bpe-var-table-body').empty(), sep = PE.decimal_sep || ',';
        var hasCogs   = productData.cogs_enabled || false;
        var hasGtin   = !!productData.gtin_enabled;
        var hasTax    = !!productData.tax_enabled;
        var taxClassOpts = productData.tax_class_options || {};
        var hasShipping  = !!productData.shipping_class_enabled;
        var shippingClassOpts = productData.shipping_class_options || {};
        var hasVendor = !!productData.vendor_field_enabled;
        var hasBackorderNotify = !!productData.backorder_notify;
        var vendorOpts = Array.isArray(productData.vendor_options) ? productData.vendor_options : [];
        var parentVendorId = productData.parent_vendor_id || 0;
        var parentVendorName = '';
        for (var pi = 0; pi < vendorOpts.length; pi++) {
            if (vendorOpts[pi].id === parentVendorId) { parentVendorName = vendorOpts[pi].name; break; }
        }
        var extras = productData.variation_extras || {};
        // colspan for the extras row — main row has 9 base cols + optional gtin + optional tax class + optional cogs + optional vendor + expander + delete
        var baseCols = 10 + (hasGtin ? 1 : 0) + (hasTax ? 1 : 0) + (hasShipping ? 1 : 0) + (hasCogs ? 1 : 0) + (hasVendor ? 1 : 0) + 2; // 10 base (incl. Track) +1 expander toggle, +1 delete cell
        state.variations.forEach(function (v, idx) {
            var pv = v.regular_price ? ('' + v.regular_price).replace('.', sep) : '';
            var sv = v.sale_price ? ('' + v.sale_price).replace('.', sep) : '';
            var stk = v.stock_quantity !== '' && v.stock_quantity !== null ? v.stock_quantity : '';
            var cogsv = hasCogs && v.cogs_value ? ('' + v.cogs_value).replace('.', sep) : '';
            var varStatus = v.stock_status || 'instock';
            // Per-variation stock tracking. When on, the quantity input is
            // active and the status select is disabled (WC derives it); when
            // off, the reverse. Mirrors the simple-product "Track quantity".
            var varManage = !!v.manage_stock;
            var trackTd = '<td class="var-track-cell"><label class="brikpanel-pe-switch brikpanel-pe-switch-sm">' +
                '<input type="checkbox" class="var-manage"' + (varManage ? ' checked' : '') + '>' +
                '<span class="brikpanel-pe-slider"></span></label></td>';
            var imgCellHtml = buildVarImageCell(v.images, idx);
            var cogsTd = hasCogs ? '<td><input type="text" class="var-cogs" value="' + esc(cogsv) + '" data-price="1" placeholder="0' + sep + '00"></td>' : '';
            var taxTd = '';
            if (hasTax) {
                var vtc = (v.tax_class === undefined || v.tax_class === null) ? 'parent' : ('' + v.tax_class);
                var taxOptsHtml = '';
                Object.keys(taxClassOpts).forEach(function (slug) {
                    taxOptsHtml += '<option value="' + esc(slug) + '"' + (slug === vtc ? ' selected' : '') + '>' + esc(taxClassOpts[slug]) + '</option>';
                });
                taxTd = '<td><select class="var-tax-class brikpanel-pe-select">' + taxOptsHtml + '</select></td>';
            }
            var shipTd = '';
            if (hasShipping) {
                var vsc = (v.shipping_class === undefined || v.shipping_class === null) ? '' : ('' + v.shipping_class);
                var shipOptsHtml = '';
                Object.keys(shippingClassOpts).forEach(function (slug) {
                    shipOptsHtml += '<option value="' + esc(slug) + '"' + (slug === vsc ? ' selected' : '') + '>' + esc(shippingClassOpts[slug]) + '</option>';
                });
                shipTd = '<td><select class="var-shipping-class brikpanel-pe-select">' + shipOptsHtml + '</select></td>';
            }
            // Vendor select per variation. The "(parent)" option keeps cells
            // visually clean when most variations share the parent vendor.
            var vendorTd = '';
            if (hasVendor) {
                var vid = parseInt(v.vendor_id, 10) || 0;
                var inheritLabel = parentVendorName
                    ? (PE.i18n.inherit_parent_named || '(parent: %s)').replace('%s', parentVendorName)
                    : (PE.i18n.inherit_parent || '(parent)');
                var opts = '<option value="0"' + (vid === 0 ? ' selected' : '') + '>' + esc(inheritLabel) + '</option>';
                for (var oi = 0; oi < vendorOpts.length; oi++) {
                    var o = vendorOpts[oi];
                    opts += '<option value="' + o.id + '"' + (vid === o.id ? ' selected' : '') + '>' + esc(o.name) + '</option>';
                }
                vendorTd = '<td class="var-vendor-cell"><select class="var-vendor brikpanel-pe-vendor-select">' + opts + '</select></td>';
            }
            var statusTd = '<td><select class="var-stock-status"' + (varManage ? ' disabled' : '') + '>' +
                '<option value="instock"' + (varStatus === 'instock' ? ' selected' : '') + '>' + (PE.i18n.in_stock || 'In stock') + '</option>' +
                '<option value="outofstock"' + (varStatus === 'outofstock' ? ' selected' : '') + '>' + (PE.i18n.out_of_stock || 'Out of stock') + '</option>' +
                '<option value="onbackorder"' + (varStatus === 'onbackorder' ? ' selected' : '') + '>' + (PE.i18n.on_backorder || 'On backorder') + '</option>' +
                '</select></td>';
            // Per-variation backorder mode — only injected when the merchant
            // enabled the sub-option in settings. Hidden until the row's
            // stock status is "On backorder".
            var backorderRow = '';
            if (hasBackorderNotify) {
                var varBackVal = (v.backorders === 'notify') ? 'notify' : 'yes';
                var showBack   = (varStatus === 'onbackorder');
                backorderRow = '<tr class="var-backorder-row" data-idx="' + idx + '"' + (showBack ? '' : ' hidden') + '>' +
                    '<td colspan="' + baseCols + '">' +
                    '<div class="brikpanel-pe-var-backorder">' +
                    '<span class="brikpanel-pe-var-backorder-label">' + esc(PE.i18n.backorder_label || 'Backorder') + '</span>' +
                    '<div class="brikpanel-pe-radio-group">' +
                    '<label class="brikpanel-pe-radio"><input type="radio" name="var-backorders-' + idx + '" value="yes"' + (varBackVal === 'yes' ? ' checked' : '') + '><span>' + esc(PE.i18n.backorder_silent || 'Allow without notification') + '</span></label>' +
                    '<label class="brikpanel-pe-radio"><input type="radio" name="var-backorders-' + idx + '" value="notify"' + (varBackVal === 'notify' ? ' checked' : '') + '><span>' + esc(PE.i18n.backorder_notify || 'Allow and notify customer') + '</span></label>' +
                    '</div></div></td></tr>';
            }
            var hasExtra = v.id && extras[v.id];
            var expanderTd = hasExtra
                ? '<td class="var-expand-cell"><button type="button" class="var-expand-btn" data-idx="' + idx + '" aria-label="' + esc(PE.i18n.more_fields || 'More fields') + '"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg></button></td>'
                : '<td class="var-expand-cell"></td>';
            var rowClasses = 'var-main-row';
            if (hasExtra) rowClasses += ' has-extra';
            if (hasBackorderNotify && varStatus === 'onbackorder') rowClasses += ' has-backorder';
            $tb.append('<tr data-idx="' + idx + '" class="' + rowClasses + '">' +
                expanderTd +
                '<td class="var-name">' + esc(v.name) + '</td>' +
                '<td><input type="text" class="var-price" value="' + esc(pv) + '" data-price="1" placeholder="0' + sep + '00"></td>' +
                '<td><input type="text" class="var-sale-price" value="' + esc(sv) + '" data-price="1" placeholder="0' + sep + '00"></td>' +
                '<td><input type="text" class="var-sale-from" value="' + esc(v.sale_from || '') + '" placeholder="YYYY-MM-DD" autocomplete="off"></td>' +
                '<td><input type="text" class="var-sale-to"   value="' + esc(v.sale_to   || '') + '" placeholder="YYYY-MM-DD" autocomplete="off"></td>' +
                trackTd +
                '<td><input type="number" class="var-stock" value="' + esc('' + stk) + '" min="0" placeholder="0"' + (varManage ? '' : ' disabled') + '></td>' +
                statusTd +
                cogsTd +
                vendorTd +
                '<td><input type="text" class="var-sku" value="' + esc(v.sku) + '"></td>' +
                (hasGtin ? '<td><input type="text" class="var-gtin" value="' + esc(v.global_unique_id || '') + '" inputmode="numeric" autocomplete="off"></td>' : '') +
                taxTd +
                shipTd +
                '<td>' + imgCellHtml + '</td>' +
                '<td class="var-delete-cell"><button type="button" class="var-delete-btn" data-idx="' + idx + '" aria-label="' + esc(PE.i18n.delete_variation || 'Delete variation') + '" title="' + esc(PE.i18n.delete_variation || 'Delete variation') + '"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v2"/></svg></button></td>' +
                '</tr>');
            if (backorderRow) {
                $tb.append(backorderRow);
            }
            if (hasExtra) {
                $tb.append('<tr class="var-extras-row" data-idx="' + idx + '" data-variation-id="' + v.id + '" hidden>' +
                    '<td colspan="' + baseCols + '" class="var-extras-cell">' +
                    '<div class="brikpanel-pe-var-extras">' + extras[v.id] + '</div>' +
                    '</td></tr>');
            }
        });
        $tb.find('.var-image-btn').on('click', function () { openVarImagePicker($(this).data('idx')); });
        $tb.find('.var-delete-btn').on('click', function () {
            var idx = $(this).data('idx');
            var confirmMsg = PE.i18n.confirm_delete_variation || 'Delete this variation? This change is applied when you save the product.';
            if (!window.confirm(confirmMsg)) return;
            // Pull live DOM edits back into state.variations BEFORE the splice
            // so unsaved price/stock/SKU edits on sibling rows survive the
            // re-render. Without this, renderVarTable() restores those rows
            // from the stale server-hydrated values and the user perceives
            // deleting one variation as wiping data from the others.
            captureVarTableInputs();
            state.variations.splice(idx, 1);
            state.dirty = true;
            renderVarTable();
        });
        $tb.find('.var-expand-btn').on('click', function () {
            var idx = $(this).data('idx');
            var $row = $tb.find('.var-extras-row[data-idx="' + idx + '"]');
            var open = $row.is('[hidden]');
            if (open) { $row.removeAttr('hidden'); $(this).addClass('open'); }
            else      { $row.attr('hidden', 'hidden'); $(this).removeClass('open'); }
        });
        // Per-variation "Track" toggle: enables the quantity input and lets
        // WC derive the status (status select disabled), or vice-versa.
        // Bound on fresh checkboxes each render — no event stacking.
        $tb.find('.var-manage').on('change', function () {
            var $cb = $(this), $row = $cb.closest('tr.var-main-row');
            var on = $cb.is(':checked');
            $row.find('.var-stock').prop('disabled', !on);
            $row.find('.var-stock-status').prop('disabled', on);
            if (on) {
                // Tracking on: status is derived, so collapse any open
                // backorder sub-row tied to a manual "On backorder" choice.
                var idx = $row.data('idx');
                $tb.find('tr.var-backorder-row[data-idx="' + idx + '"]').attr('hidden', 'hidden');
                $row.removeClass('has-backorder');
            }
            state.dirty = true;
        });
        // Reveal the per-variation backorder sub-row only when the row's
        // stock status flips to "On backorder". Adds/removes the
        // `has-backorder` class so the main row's bottom border merges.
        if (hasBackorderNotify) {
            $tb.on('change', '.var-stock-status', function () {
                var $sel = $(this);
                var idx = $sel.closest('tr.var-main-row').data('idx');
                var $mainRow = $tb.find('tr.var-main-row[data-idx="' + idx + '"]');
                var $backRow = $tb.find('tr.var-backorder-row[data-idx="' + idx + '"]');
                var on = $sel.val() === 'onbackorder';
                if (on) { $backRow.removeAttr('hidden'); $mainRow.addClass('has-backorder'); }
                else    { $backRow.attr('hidden', 'hidden'); $mainRow.removeClass('has-backorder'); }
            });
        }

        // Flatpickr on every per-variation sale date input
        if (typeof flatpickr === 'function') {
            $tb.find('.var-sale-from, .var-sale-to').each(function () {
                if (this._flatpickr) return;
                flatpickr(this, { dateFormat: 'Y-m-d', allowInput: false });
            });
        }
    }

    function buildVarImageCell(images, idx) {
        var count = images ? images.length : 0;
        var badge = count > 1 ? '<span class="var-image-count">' + count + '</span>' : '';
        if (count > 0) {
            return '<button type="button" class="var-image-btn has-images" data-idx="' + idx + '">' +
                '<img src="' + esc(images[0].url) + '" alt="">' + badge + '</button>';
        }
        return '<button type="button" class="var-image-btn" data-idx="' + idx + '">' +
            '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/></svg></button>';
    }

    function openVarImagePicker(idx) {
        // wp_localize_script flattens booleans to strings (true → "1", false → ""),
        // so check truthiness against the "1"/"0" payload instead of === false.
        var galleryEnabled = String(PE.variation_gallery_enabled) === '1';
        var frame = wp.media({
            title: galleryEnabled ? (PE.i18n.select_images || 'Select images') : (PE.i18n.select_image || 'Select image'),
            multiple: galleryEnabled,
            library: { type: 'image' },
            button: { text: PE.i18n.select || 'Select' }
        });
        frame.on('open', enableClickToToggle);
        frame.on('close', disableClickToToggle);

        // Pre-select ONLY the variation's own images. The media frame would
        // otherwise inherit the current post context and auto-highlight the
        // parent product's featured image, which users reported as
        // unexpected. `selection.reset()` clears any pre-populated items
        // (including that inherited featured image) before we add ours.
        frame.on('open', function () {
            var selection = frame.state().get('selection');
            selection.reset();
            var imgs = state.variations[idx].images || [];
            if (!galleryEnabled) imgs = imgs.slice(0, 1);
            imgs.forEach(function (img) {
                var attachment = wp.media.attachment(img.id);
                attachment.fetch();
                selection.add(attachment);
            });
        });

        frame.on('select', function () {
            var attachments = frame.state().get('selection').toJSON();
            var newImages = attachments.map(function (att) {
                return {
                    id: att.id,
                    url: (att.sizes && att.sizes.thumbnail) ? att.sizes.thumbnail.url : att.url
                };
            });
            if (!galleryEnabled) newImages = newImages.slice(0, 1);
            state.variations[idx].images = newImages;
            // Re-render just this cell
            var $btn = $('.var-image-btn[data-idx="' + idx + '"]');
            $btn.replaceWith(buildVarImageCell(newImages, idx));
            // Rebind click
            $('#bpe-var-table-body .var-image-btn[data-idx="' + idx + '"]').on('click', function () {
                openVarImagePicker(idx);
            });
        });

        frame.open();
    }

    function applyBulk() {
        var price = $.trim($('#bpe-bulk-price').val());
        var salePrice = $.trim($('#bpe-bulk-sale-price').val());
        var stock = $.trim($('#bpe-bulk-stock').val());
        if (price) $('#bpe-var-table-body .var-price').val(price);
        if (salePrice) $('#bpe-var-table-body .var-sale-price').val(salePrice);
        if (stock !== '') {
            // Setting a bulk quantity implies the merchant wants tracking on,
            // so enable it across all rows (and disable the now-derived status
            // select) before applying the value.
            $('#bpe-var-table-body tr.var-main-row').each(function () {
                var $row = $(this);
                $row.find('.var-manage').prop('checked', true);
                $row.find('.var-stock').prop('disabled', false).val(stock);
                $row.find('.var-stock-status').prop('disabled', true);
                $row.removeClass('has-backorder');
                var idx = $row.data('idx');
                $('#bpe-var-table-body tr.var-backorder-row[data-idx="' + idx + '"]').attr('hidden', 'hidden');
            });
        }
    }

    /* Validation — only enforces required fields that are actually visible.
       A hidden required field (e.g. the top-level price input while the
       variations toggle is on) must not block publishing. */
    function validateField($i) {
        var $e = $i.closest('.brikpanel-pe-field').find('.brikpanel-pe-field-error');
        if (!$i.is(':visible')) { $i.removeClass('has-error'); $e.text(''); return true; }
        var v = $.trim($i.val());
        if ($i.data('required') && !v) { $i.addClass('has-error'); $e.text(PE.i18n.field_required || 'This field is required'); return false; }
        $i.removeClass('has-error'); $e.text(''); return true;
    }
    function validateAll() {
        var ok = true;
        $('[data-required]:visible').each(function () { if (!validateField($(this))) ok = false; });
        return ok;
    }

    /* Save */
    function saveProduct(status, silent) {
        if (state.saving) return;
        if (!silent && status === 'publish' && !validateAll()) { showToast(PE.i18n.fill_required || 'Please fill in the required fields', 'error'); return; }
        var name = $.trim($('#bpe-name').val());
        if (!name) { if (!silent) { showToast(PE.i18n.fill_name || 'Please fill in the product name', 'error'); validateField($('#bpe-name')); } return; }

        state.saving = true;
        var $pub = $('#bpe-publish'), op = $pub.text();
        $pub.prop('disabled', true).text(PE.i18n.saving || 'Saving...');

        var isVar = $('#bpe-var-toggle').is(':checked') && state.variations.length > 0;
        var sep = PE.decimal_sep || ',';
        var data = { action: 'brikpanel_save_product', security: PE.nonce,
            product_id: $('#bpe-product-id').val() || 0, status: status, name: name,
            short_description: getEditorHtml('bpe-short-desc'), description: getEditorHtml('bpe-description'),
            sku: $('#bpe-sku').val(),
            is_variable: isVar ? 1 : 0 };
        // Only send the GTIN when the field is actually rendered (the section
        // is opt-in). Omitting the key lets the server leave any existing
        // value untouched instead of wiping it.
        var $gtinField = $('#bpe-gtin');
        if ($gtinField.length) data.global_unique_id = $gtinField.val();

        // Opt-in WC-core sections — only send a key when its card is rendered
        // so a disabled section never wipes the stored value server-side.
        if ($('#bpe-tax-card').length) {
            data.tax_status = $('#bpe-tax-status').val();
            data.tax_class  = $('#bpe-tax-class').val();
        }
        if ($('#bpe-shipping-class-card').length) {
            data.shipping_class = $('#bpe-shipping-class').val();
        }
        if ($('#bpe-sold-individually-card').length) {
            data.sold_individually = $('#bpe-sold-individually').is(':checked') ? 1 : 0;
        }
        if ($('#bpe-linked-card').length) {
            data.upsell_ids     = JSON.stringify(state.linked.upsells.map(function (i) { return i.id; }));
            data.cross_sell_ids = JSON.stringify(state.linked.cross_sells.map(function (i) { return i.id; }));
        }
        if ($('#bpe-advanced-card').length) {
            data.purchase_note   = $('#bpe-purchase-note').val();
            data.reviews_allowed = $('#bpe-reviews-allowed').is(':checked') ? 1 : 0;
            data.menu_order      = $('#bpe-menu-order').val();
        }

        // Product type — only when the selector is enabled. Server falls
        // back to the is_variable flag when missing. We send the selected
        // type verbatim even if zero variations exist; the user may be
        // setting up the parent first and adding variations later.
        var $ptSel = $('#bpe-product-type');
        if ($ptSel.length) {
            var ptVal = $ptSel.val();
            if (ptVal) {
                data.product_type = ptVal;
            }
        }

        // Password protected
        data.post_password = status === 'password' ? ($('#bpe-post-password').val() || '') : '';

        // Catalog visibility
        data.catalog_visibility = $('#bpe-catalog-visibility').val() || 'visible';

        // Featured product — applies to both simple and variable products
        // (WC stores it on the parent via the `product_visibility` taxonomy).
        // Source of truth is the hidden #bpe-is-featured field, kept in sync
        // by the header star button. The field is ONLY rendered when the
        // "Featured product star" setting is on — when it's off we omit the
        // key from the payload entirely so the server leaves the existing
        // featured flag untouched (whatever set it elsewhere is preserved).
        var $featuredField = $('#bpe-is-featured');
        if ($featuredField.length) {
            data.is_featured = $featuredField.val() === '1' ? 1 : 0;
        }

        if (!isVar) {
            data.regular_price = parsePrice($('#bpe-price').val(), sep);
            // Sale fields are always visible now — the server treats empty
            // sale price as "no sale" and clears the scheduled dates.
            data.sale_price = parsePrice($('#bpe-sale-price').val(), sep);
            data.sale_from  = $('#bpe-sale-from').val() || '';
            data.sale_to    = $('#bpe-sale-to').val()   || '';
        }
        // "Track quantity" toggle is the explicit manage_stock source. When
        // on we send quantity + backorder rule and let WC derive the status;
        // when off we send the directly-chosen status instead.
        var manageStock = $('#bpe-manage-stock').is(':checked');
        data.manage_stock = manageStock ? 1 : 0;
        if (manageStock) {
            data.stock_quantity = $('#bpe-stock').val();
            data.backorders = $('#bpe-backorders').val() || 'no';
        } else {
            data.stock_status = $('#bpe-stock-status').val() || 'instock';
            // Backorder mode — only sent when the merchant turned on the
            // "Notify customer" sub-option *and* the status is "On backorder".
            // Falls through to the legacy yes/no mapping on the server otherwise.
            if (data.stock_status === 'onbackorder') {
                var $backRadio = $('input[name="backorders"]:checked');
                if ($backRadio.length) {
                    data.backorders = $backRadio.val() === 'notify' ? 'notify' : 'yes';
                }
            }
        }
        // Only forward COGS when the section is rendered. Sending an empty
        // value back when the card is hidden would tell the save handler to
        // clear the meta and wipe the cost on every product the merchant
        // touches after disabling the section in settings.
        var $cogsInput = $('#bpe-cogs');
        if ($cogsInput.length) {
            data.cogs_value = parsePrice($cogsInput.val() || '', sep);
        }

        // Parent product vendor (only when the editor field is rendered).
        // The select has name="bp_vendor_id" so it would also be picked up
        // by a native form submit — we still send it explicitly because the
        // editor uses a manual JSON payload, not form serialization.
        var $bpVendor = $('#bpe-vendor');
        if ($bpVendor.length) {
            data.bp_vendor_id  = parseInt($bpVendor.val(), 10) || 0;
            data.bp_vendor_sku = ($('#bpe-vendor-sku').val() || '').trim();
        }
        data.weight = $('#bpe-weight-toggle').is(':checked') ? parsePrice($('#bpe-weight').val(), sep) : '';

        // Dimensions
        if ($('#bpe-dims-toggle').is(':checked')) {
            data.length = parsePrice($('#bpe-length').val(), sep);
            data.width = parsePrice($('#bpe-width').val(), sep);
            data.height = parsePrice($('#bpe-height').val(), sep);
        } else { data.length = ''; data.width = ''; data.height = ''; }

        // SEO
        data.seo_title = $('#bpe-seo-title').val() || '';
        data.seo_focus_kw = $('#bpe-seo-focus-kw').val() || '';
        data.seo_canonical = $('#bpe-seo-canonical').val() || '';
        data.seo_noindex = $('#bpe-seo-noindex').is(':checked') ? 1 : 0;
        data.seo_description = $('#bpe-seo-desc').val() || '';

        // Virtual / Digital / downloads — flags are independent on the wire.
        // The server treats `is_downloadable=1` as implying virtual too, but
        // we send the explicit toggle state so the data round-trips back to
        // the editor identically on next load.
        data.is_virtual      = $('#bpe-virtual-toggle').is(':checked') ? 1 : 0;
        data.is_downloadable = $('#bpe-digital-toggle').is(':checked') ? 1 : 0;
        if (data.is_downloadable) {
            data.downloads = JSON.stringify(state.downloads);
        } else {
            data.downloads = '[]';
        }

        if (state.images.length) { data.image_id = state.images[0].id; data.gallery_ids = state.images.slice(1).map(function (i) { return i.id; }).join(','); }
        else { data.image_id = 0; data.gallery_ids = ''; }

        var cats = []; $('input[name="category_ids[]"]:checked').each(function () { cats.push($(this).val()); });
        data.category_ids = cats.join(',');

        // Tags
        data.tag_names = state.tags.join(',');

        // Third-party metabox + WC Product Data custom fields.
        // Array-style names (`tax_input[orderable_product_label][]`, groups of
        // checkboxes, etc.) must accumulate into a JS array — otherwise each
        // repeated name overwrites the previous one and only the last checked
        // term makes it to the server. We strip the trailing `[]` so jQuery's
        // default serializer re-emits it for every array element.
        //
        // .brikpanel-pe-var-extras inputs are per-variation 3rd-party fields
        // whose names look like `field_name[<loop_idx>]`. Posting them at the
        // top level lets PHP assemble `$_POST['field_name'][<loop_idx>]`
        // natively — exactly what `woocommerce_save_product_variation` handlers
        // expect to read from.
        //
        // .brikpanel-pe-ext-card holds cards registered by third parties via the
        // `brikpanel_product_editor_boxes` filter. Any inputs a developer renders
        // inside their box (e.g. ACF's `acf[...]` fields via acf_render_fields())
        // are forwarded the same way so they reach the server and the native
        // save_post handlers (ACF, custom meta, …) can persist them.
        //
        // #acf-form-data is ACF's hidden block (_acf_nonce, _acf_post_id, …).
        // It must travel with the payload so ACF's save_post handler can verify
        // its nonce — wherever on the page acf_form_data() emitted it.
        $('.brikpanel-pe-metaboxes-wrap :input[name], .brikpanel-pe-wc-fields :input[name], .brikpanel-pe-var-extras :input[name], .brikpanel-pe-ext-card :input[name], #acf-form-data :input[name]').each(function () {
            var $el = $(this), name = $el.attr('name');
            if (!name) return;
            if (($el.is(':checkbox') || $el.is(':radio')) && !$el.is(':checked')) return;
            var val = $el.val();
            // Extract bracket groups: name="a[b][c]" → key="a", suffixes=["b","c"].
            var m = /^([^\[]+)((?:\[[^\]]*\])*)$/.exec(name);
            if (!m) return;
            var key = m[1];
            var suffix = m[2] || '';
            if (suffix === '') {
                if (!data.hasOwnProperty(key)) data[key] = val;
                return;
            }
            // Walk bracket chain into data[key] as a nested array/object.
            if (!data.hasOwnProperty(key) || (typeof data[key] !== 'object' || data[key] === null)) {
                data[key] = {};
            }
            var parts = [];
            suffix.replace(/\[([^\]]*)\]/g, function (_, p) { parts.push(p); return ''; });
            var cursor = data[key];
            for (var i = 0; i < parts.length - 1; i++) {
                var p = parts[i];
                if (p === '') p = (Array.isArray(cursor) ? cursor.length : Object.keys(cursor).length).toString();
                if (typeof cursor[p] !== 'object' || cursor[p] === null) cursor[p] = {};
                cursor = cursor[p];
            }
            var last = parts[parts.length - 1];
            if (last === '') {
                if (!Array.isArray(cursor.__arr)) cursor.__arr = [];
                cursor.__arr.push(val);
            } else {
                cursor[last] = val;
            }
        });
        // Flatten nested containers back to URL-encoded bracket notation so
        // URLSearchParams serialises them correctly. `{a: {0: 'x', 1: 'y'}}`
        // becomes `a[0]=x&a[1]=y` — which is what PHP unpacks into
        // `$_POST['a'] = ['x','y']`.
        function flattenPost(target, prefix, val) {
            if (val === null || val === undefined) return;
            if (typeof val !== 'object') { target.push([prefix, val]); return; }
            if (Array.isArray(val)) {
                val.forEach(function (v, i) { flattenPost(target, prefix + '[' + i + ']', v); });
                return;
            }
            // {__arr: [...]} is the collector's internal marker for inputs
            // whose names end in `[]` (e.g. `tax_input[tax][]` for hierarchical
            // taxonomy checkboxes). Emit the array values under the original
            // prefix so the `__arr` key never leaks into the POST payload.
            if (Array.isArray(val.__arr)) {
                val.__arr.forEach(function (v, i) { flattenPost(target, prefix + '[' + i + ']', v); });
                Object.keys(val).forEach(function (k) {
                    if (k === '__arr') return;
                    flattenPost(target, prefix + '[' + k + ']', val[k]);
                });
                return;
            }
            Object.keys(val).forEach(function (k) {
                flattenPost(target, prefix + '[' + k + ']', val[k]);
            });
        }
        // Rank Math — drains its Redux store into the POST payload. Rank
        // Math's React metabox keeps the user's edits in a wp.data store and
        // only persists them via a REST call wired to the classic-editor
        // form submit event. Our BrikPanel Update button isn't a form
        // submit, so without this hand-off the user's SEO edits are
        // abandoned when they leave the page.
        try {
            var rmSel = window.wp && window.wp.data && window.wp.data.select && window.wp.data.select('rank-math');
            if (rmSel && typeof rmSel.getTitle === 'function') {
                var _rmPick = function (getter, key) {
                    try { var v = rmSel[getter] && rmSel[getter](); if (v !== undefined && v !== null) data[key] = v; } catch (e) {}
                };
                _rmPick('getTitle',           'bpe_rm_title');
                _rmPick('getDescription',     'bpe_rm_description');
                _rmPick('getCanonicalUrl',    'bpe_rm_canonical_url');
                _rmPick('getBreadcrumbTitle', 'bpe_rm_breadcrumb_title');
                _rmPick('getPillarContent',   'bpe_rm_pillar_content');
                _rmPick('getFacebookTitle',       'bpe_rm_facebook_title');
                _rmPick('getFacebookDescription', 'bpe_rm_facebook_description');
                _rmPick('getFacebookImage',       'bpe_rm_facebook_image');
                _rmPick('getFacebookImageID',     'bpe_rm_facebook_image_id');
                _rmPick('getTwitterTitle',        'bpe_rm_twitter_title');
                _rmPick('getTwitterDescription',  'bpe_rm_twitter_description');
                _rmPick('getTwitterImage',        'bpe_rm_twitter_image');
                _rmPick('getTwitterImageID',      'bpe_rm_twitter_image_id');
                _rmPick('getTwitterUseFacebook',  'bpe_rm_twitter_use_facebook');
                _rmPick('getTwitterCardType',     'bpe_rm_twitter_card_type');
                // Keywords: Rank Math stores them as a comma-separated string.
                try { var kw = rmSel.getKeywords && rmSel.getKeywords(); if (typeof kw === 'string') data.bpe_rm_focus_keyword = kw; } catch (e) {}
                // Robots array (noindex, nofollow, etc.) — JSON-encode so PHP
                // receives an intact list regardless of jQuery's serializer.
                try {
                    var robots = rmSel.getRobots && rmSel.getRobots();
                    if (Array.isArray(robots)) data.bpe_rm_robots = JSON.stringify(robots);
                } catch (e) {}
                try {
                    var adv = rmSel.getAdvancedRobots && rmSel.getAdvancedRobots();
                    if (adv && typeof adv === 'object') data.bpe_rm_advanced_robots = JSON.stringify(adv);
                } catch (e) {}
                data.bpe_rm_active = 1;
            }
        } catch (e) { /* Rank Math not active — skip */ }

        // AIOSEO — mirrors the Rank Math approach. AIOSEO's Vue app stores
        // the user's edits in a hidden `#aioseo-post-settings` input as a
        // JSON blob; capture the current value so it round-trips through
        // our save endpoint (AIOSEO's own save_post hook reads it from the
        // same hidden input on submit).
        try {
            var aioHidden = document.getElementById('aioseo-post-settings');
            if (aioHidden && aioHidden.value) {
                data.aioseo_post_settings = aioHidden.value;
            }
        } catch (e) {}

        // Unwrap anything we nested into plain data keys and emit bracketed pairs.
        var flattened = [];
        Object.keys(data).forEach(function (k) {
            var v = data[k];
            if (v && typeof v === 'object' && !Array.isArray(v)) {
                flattenPost(flattened, k, v);
                delete data[k];
            }
        });
        data.__flat_extra_pairs = flattened;

        // Send the non-variation (spec) attributes only when the section is
        // visible. We hide it when the variations toggle is on (specs are
        // managed elsewhere in that mode), so omitting the key tells the
        // server "section not posted — leave existing attributes alone" and
        // toggling variations on/off does not wipe spec data.
        if ($('#bpe-attr-card').length && !$('#bpe-var-toggle').is(':checked')) {
            data.non_variation_attributes = JSON.stringify(collectNonVariationAttributes());
        }

        if (isVar) {
            data.attributes = JSON.stringify(state.varAttributes);
            var tv = [];
            // Iterate only the main rows — extras rows sit between them and
            // would otherwise shift the idx → state.variations mapping.
            $('#bpe-var-table-body tr.var-main-row').each(function (idx) {
                var v = state.variations[idx]; if (!v) return;
                var $mainRow = $(this);
                var varManageStock = $mainRow.find('.var-manage').is(':checked');
                var varStockStatus = $mainRow.find('.var-stock-status').val() || 'instock';
                var varObj = { id: v.id || 0, attributes: v.attributes,
                    regular_price: parsePrice($mainRow.find('.var-price').val(), sep),
                    sale_price: parsePrice($mainRow.find('.var-sale-price').val(), sep),
                    sale_from: $mainRow.find('.var-sale-from').val() || '',
                    sale_to:   $mainRow.find('.var-sale-to').val()   || '',
                    manage_stock: varManageStock ? 1 : 0,
                    stock_quantity: $mainRow.find('.var-stock').val(),
                    stock_status: varStockStatus,
                    sku: $mainRow.find('.var-sku').val(),
                    image_ids: (v.images || []).map(function(img) { return img.id; }) };
                var $varGtin = $mainRow.find('.var-gtin');
                if ($varGtin.length) varObj.global_unique_id = $varGtin.val();
                var $varTax = $mainRow.find('.var-tax-class');
                if ($varTax.length) varObj.tax_class = $varTax.val();
                var $varShip = $mainRow.find('.var-shipping-class');
                if ($varShip.length) varObj.shipping_class = $varShip.val();
                // Backorder mode. When tracking is on, WC derives the status,
                // so we roundtrip the stored backorder rule (preserving any
                // existing "allow/notify"). When off, it only matters while
                // the manually-chosen status is "On backorder".
                if (varManageStock) {
                    if (v.backorders && v.backorders !== 'no') varObj.backorders = v.backorders;
                } else if (varStockStatus === 'onbackorder') {
                    var $backRow = $('#bpe-var-table-body tr.var-backorder-row[data-idx="' + idx + '"]');
                    var $backSel = $backRow.find('input[type="radio"]:checked');
                    if ($backSel.length) {
                        varObj.backorders = $backSel.val() === 'notify' ? 'notify' : 'yes';
                    }
                }
                var $cogsInput = $(this).find('.var-cogs');
                if ($cogsInput.length) varObj.cogs_value = parsePrice($cogsInput.val(), sep);
                var $varVendor = $(this).find('.var-vendor');
                if ($varVendor.length) {
                    varObj.vendor_id  = parseInt($varVendor.val(), 10) || 0;
                    varObj.vendor_sku = v.vendor_sku || '';
                }
                tv.push(varObj);
            });
            data.variations = JSON.stringify(tv);
        }

        // Build FormData so bracketed repeat keys (`field[0]`, `field[1]`…)
        // from third-party variation fields stay intact. $.post uses jQuery's
        // param serializer which can't emit the same key twice or our nested
        // flattened pairs reliably.
        var fd = new FormData();
        var _extraPairs = data.__flat_extra_pairs || [];
        delete data.__flat_extra_pairs;
        Object.keys(data).forEach(function (k) {
            var v = data[k];
            if (Array.isArray(v)) {
                v.forEach(function (item) { fd.append(k + '[]', item); });
            } else if (v === undefined || v === null) {
                fd.append(k, '');
            } else {
                fd.append(k, v);
            }
        });
        _extraPairs.forEach(function (pair) { fd.append(pair[0], pair[1]); });

        $.ajax({ url: PE.ajax_url, type: 'POST', data: fd, processData: false, contentType: false, dataType: 'json' })
        .done(function (r) {
            state.saving = false; $pub.prop('disabled', false).text(op);
            if (r.success) {
                state.dirty = false;
                showToast(r.data.message + ' \u2713', 'success');
                if (r.data.product_id) {
                    $('#bpe-product-id').val(r.data.product_id);
                    // Keep the auto-save gate in sync: a product becomes live
                    // once saved as publish/private/password, and reverts when
                    // saved back to draft.
                    $('#bpe-product-id').data('live', (status === 'publish' || status === 'private' || status === 'password') ? 1 : 0);
                    var newUrl = PE.admin_url + 'admin.php?page=brikpanel-product-editor&product_id=' + r.data.product_id;
                    window.history.replaceState(null, '', newUrl);
                    // Status dropdown lives in the header and is the anchor
                    // new buttons get inserted before.
                    var $statusAnchor = $('.brikpanel-pe-status-wrap');
                    // Duplicate is available as soon as the product has an ID
                    // (draft, publish, private — all valid).
                    if (!$('#bpe-duplicate').length && $statusAnchor.length) {
                        $('<button type="button" class="brikpanel-pe-btn secondary" id="bpe-duplicate" data-id="' + r.data.product_id + '">' + (PE.i18n.duplicate || 'Duplicate') + '</button>').insertBefore($statusAnchor);
                    } else {
                        $('#bpe-duplicate').attr('data-id', r.data.product_id);
                    }
                    if (status === 'publish' || status === 'private' || status === 'password') {
                        $pub.text(PE.i18n.update || 'Update');
                        // View product
                        if (!$('#bpe-view-product').length && $statusAnchor.length) {
                            var viewUrl = r.data.permalink || (PE.admin_url.replace(/wp-admin\/?$/, '') + '?p=' + r.data.product_id);
                            $('<a href="' + viewUrl + '" class="brikpanel-pe-btn secondary" id="bpe-view-product" target="_blank">' + (PE.i18n.view_product || 'View product') + '</a>').insertBefore($('#bpe-duplicate'));
                        } else if ($('#bpe-view-product').length && r.data.permalink) {
                            $('#bpe-view-product').attr('href', r.data.permalink);
                        }
                        // Add new
                        if (!$('#bpe-add-new').length && $statusAnchor.length) {
                            $('<a href="' + PE.admin_url + 'admin.php?page=brikpanel-product-editor" class="brikpanel-pe-btn secondary" id="bpe-add-new"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg> ' + (PE.i18n.add_new || 'Add new') + '</a>').insertBefore($statusAnchor);
                        }
                    }
                }
            } else showToast(r.data.message || PE.i18n.error || 'An error occurred', 'error');
        }).fail(function () {
            state.saving = false; $pub.prop('disabled', false).text(op); $draft.prop('disabled', false).text(od);
            showToast(PE.i18n.error || 'An error occurred', 'error');
        });
    }

    /* ====== Product Tags ====== */
    function initTags() {
        var $wrap = $('#bpe-tags-wrap');
        var $input = $('#bpe-tag-input');
        var $suggestions = $('#bpe-tag-suggestions');
        if (!$input.length) return;

        var allTags = (productData.all_tags || []).slice();

        $input.on('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ',') {
                e.preventDefault();
                var v = $.trim(this.value.replace(/,/g, ''));
                if (v && !hasTag(v)) {
                    addProductTag(v);
                    this.value = '';
                    $suggestions.hide();
                }
            }
            if (e.key === 'Backspace' && !this.value && state.tags.length) {
                state.tags.pop();
                renderProductTags();
                state.dirty = true;
            }
        });

        $input.on('input', function () {
            var q = $.trim(this.value).toLowerCase();
            if (!q) { $suggestions.hide(); return; }
            var matches = allTags.filter(function (t) {
                return t.toLowerCase().indexOf(q) !== -1 && !hasTag(t);
            }).slice(0, 8);
            if (!matches.length) { $suggestions.hide(); return; }
            var html = '';
            matches.forEach(function (t) {
                html += '<div class="brikpanel-pe-tag-suggestion" data-value="' + esc(t) + '">' + esc(t) + '</div>';
            });
            $suggestions.html(html).show();
        });

        $suggestions.on('mousedown', '.brikpanel-pe-tag-suggestion', function (e) {
            e.preventDefault();
            var v = $(this).data('value');
            if (v && !hasTag(v)) {
                addProductTag(v);
                $input.val('');
                $suggestions.hide();
            }
        });

        $input.on('blur', function () {
            setTimeout(function () { $suggestions.hide(); }, 150);
        });

        $wrap.on('click', function () { $input.focus(); });
    }

    function hasTag(name) {
        return state.tags.some(function (t) { return t.toLowerCase() === name.toLowerCase(); });
    }

    function addProductTag(name) {
        state.tags.push(name);
        renderProductTags();
        state.dirty = true;
    }

    function removeProductTag(idx) {
        state.tags.splice(idx, 1);
        renderProductTags();
        state.dirty = true;
    }

    function renderProductTags() {
        var $wrap = $('#bpe-tags-wrap');
        $wrap.find('.brikpanel-pe-tag').remove();
        var $input = $wrap.find('input');
        state.tags.forEach(function (t, idx) {
            var $tag = $('<span class="brikpanel-pe-tag">' + esc(t) + '</span>');
            var $rm = $('<button type="button" class="brikpanel-pe-tag-remove">&times;</button>');
            $rm.on('click', function () { removeProductTag(idx); });
            $tag.append($rm);
            $input.before($tag);
        });
    }

    function loadExistingData() {
        if (!productData || !productData.id) return;
        if (productData.gallery && productData.gallery.length) {
            productData.gallery.forEach(function (i) { state.images.push({ id: i.id, url: i.url }); });
            renderGallery();
        }
        if (productData.downloads && productData.downloads.length) {
            state.downloads = productData.downloads.slice();
            renderDownloads();
        }
        if (productData.tags && productData.tags.length) {
            state.tags = productData.tags.slice();
            renderProductTags();
        }
        if (productData.is_downloadable || productData.is_virtual) {
            $('#bpe-weight-card, #bpe-dims-card').hide();
        }
        // Hydrate the Product attributes (specs) card if the section is
        // visible. Independent from variations — runs for simple AND variable
        // products.
        if (productData.non_variation_attributes && productData.non_variation_attributes.length) {
            var $aList = $('#bpe-attr-list');
            if ($aList.length) {
                productData.non_variation_attributes.forEach(function (attr) {
                    $aList.append(createAttributeRow(attr.name, attr.values || [], attr.taxonomy || ''));
                });
            }
        }
        if (productData.is_variable && productData.attributes && productData.attributes.length) {
            state.varAttributes = productData.attributes;
            state.variations = productData.variations || [];
            state.variations.forEach(function (v) {
                var p = []; Object.keys(v.attributes).forEach(function (k) { p.push(v.attributes[k]); });
                v.name = p.join(' - ');
            });
            var $a = $('#bpe-var-attributes').empty();
            productData.attributes.forEach(function (attr) { $a.append(createTagGroup(attr.name, attr.values, attr.taxonomy || '')); });
            if (state.variations.length) { renderVarTable(); showVarStep(3); }
        }
    }

    /* Auto-save every 60s using the current visibility (no silent downgrade). */
    function initAutoSave() {
        // Only auto-save if on the editor page
        if (!$('#bpe-product-id').length) return;
        setInterval(function () {
            // Skip silent auto-save until the product is actually published.
            // A brand-new or draft product is a work in progress, so we never
            // persist it behind the user's back — auto-save only protects
            // already-live (publish/private) products from losing edits.
            if ($('#bpe-product-id').data('live') != 1) return;
            if (state.dirty && !state.saving && $.trim($('#bpe-name').val())) {
                var status = $('#bpe-status').val() || 'draft';
                saveProduct(status, true); // silent = true
            }
        }, 60000);
    }

    /* Duplicate product */
    function duplicateProduct() {
        var id = $('#bpe-duplicate').data('id');
        if (!id) return;
        var $btn = $('#bpe-duplicate');
        $btn.prop('disabled', true).text(PE.i18n.duplicating || 'Duplicating...');
        $.post(PE.ajax_url, { action: 'brikpanel_duplicate_product', security: PE.nonce, product_id: id }, function (r) {
            $btn.prop('disabled', false).text(PE.i18n.duplicate || 'Duplicate');
            if (r.success) {
                showToast(r.data.message + ' \u2713', 'success');
                setTimeout(function () {
                    window.location.href = PE.admin_url + 'admin.php?page=brikpanel-product-editor&product_id=' + r.data.product_id;
                }, 500);
            } else { showToast(r.data.message || 'Error', 'error'); }
        });
    }

    /* SEO live preview */
    function initSeoPreview() {
        $('#bpe-seo-title').on('input', function () {
            var val = this.value || $('#bpe-name').val() || PE.i18n.product_title || 'Product title';
            $('#bpe-seo-preview-title').text(val);
            $('#bpe-seo-title-count').text(this.value.length);
        });
        $('#bpe-seo-desc').on('input', function () {
            var val = this.value || ($('#bpe-short-desc').text() || '').trim();
            $('#bpe-seo-preview-desc').text(val);
            $('#bpe-seo-desc-count').text(this.value.length);
        });
        // Also update preview when product name changes
        $('#bpe-name').on('input', function () {
            if (!$('#bpe-seo-title').val()) {
                $('#bpe-seo-preview-title').text(this.value || PE.i18n.product_title || 'Product title');
            }
        });
    }

    /* Live SEO analysis bridge.
     *
     * The active SEO plugin's metabox renders inside the BrikPanel SEO card,
     * but its content analyser reads the *native* post-editor fields, which
     * PHP scaffolds hidden into the page (.brikpanel-pe-seo-native-bridge:
     * #title / #content / #excerpt / slug). This keeps that scaffold in sync
     * with the BrikPanel fields on every edit and pokes each plugin to
     * re-analyse, so the SEO/readability score updates in real time exactly
     * like it does on the native WooCommerce product editor — for Yoast,
     * Rank Math, AIO SEO and SEOPress alike, on simple and variable products. */
    function initSeoAnalysisBridge() {
        var $bridge = $('.brikpanel-pe-seo-native-bridge');
        if (!$bridge.length) return; // no SEO plugin active — nothing to feed

        var nTitle   = document.getElementById('title');
        var nContent = document.getElementById('content');
        var nExcerpt = document.getElementById('excerpt');

        function fire(el) {
            if (!el) return;
            // Native + jQuery events so both addEventListener and jQuery
            // .on() collectors (Yoast Woo binds jQuery('#excerpt')) react.
            ['input', 'change', 'keyup'].forEach(function (type) {
                el.dispatchEvent(new Event(type, { bubbles: true }));
            });
            try { $(el).trigger('input').trigger('change').trigger('keyup'); } catch (e) {}
        }

        function refreshAnalyzers() {
            // Yoast SEO (+ WooCommerce)
            try {
                if (window.YoastSEO && YoastSEO.app && typeof YoastSEO.app.refresh === 'function') {
                    YoastSEO.app.refresh();
                }
            } catch (e) {}
            // Rank Math (classic assessor)
            try {
                if (window.rankMathEditor && typeof rankMathEditor.refresh === 'function') {
                    rankMathEditor.refresh('content');
                }
            } catch (e) {}
            // AIO SEO / SEOPress re-analyse from the #content/#title input
            // events fired in pushToNative(), so no explicit API call needed.
        }

        function pushToNative() {
            var title   = $('#bpe-name').val() || '';
            var content = getEditorHtml('bpe-description') || '';
            var excerpt = getEditorHtml('bpe-short-desc') || '';

            if (nTitle && nTitle.value !== title) { nTitle.value = title; fire(nTitle); }
            if (nContent && nContent.value !== content) { nContent.value = content; fire(nContent); }
            if (nExcerpt && nExcerpt.value !== excerpt) { nExcerpt.value = excerpt; fire(nExcerpt); }

            refreshAnalyzers();
        }

        var debounce = null;
        function scheduleSync() {
            clearTimeout(debounce);
            debounce = setTimeout(pushToNative, 450);
        }

        // BrikPanel name + the two rich-text editors (contenteditable, their
        // hidden HTML-source textareas, and paste/keystroke inside them).
        $(document).on(
            'input change keyup paste',
            '#bpe-name, #bpe-short-desc, #bpe-short-desc-source, #bpe-description, #bpe-description-source',
            scheduleSync
        );

        // Seed once the analyser is alive. Yoast emits YoastSEO:ready; for the
        // others a short delayed first push covers their boot. Both paths are
        // idempotent (pushToNative only fires events when a value changed).
        $(window).on('YoastSEO:ready', pushToNative);
        setTimeout(pushToNative, 1200);
    }

    /* Inline edit on product list page */
    function initInlineEdit() {
        $(document).on('dblclick', '.brikpanel-pe-stock-badge, .column-price .woocommerce-Price-amount', function () {
            var $el = $(this);
            if ($el.find('input').length) return; // already editing

            var field = $el.data('field') || ($el.closest('.column-price').length ? 'price' : '');
            var productId = $el.data('product-id') || $el.closest('tr').find('.check-column input').val();
            var currentVal = $el.data('value') || $el.text().replace(/[^\d.,]/g, '');

            if (!field || !productId) return;

            var $input = $('<input type="text" class="brikpanel-pe-inline-input" value="' + esc(currentVal) + '">');
            var origHtml = $el.html();
            $el.html('').append($input);
            $input.focus().select();

            function saveInline() {
                var newVal = $.trim($input.val());
                if (newVal === currentVal) { $el.html(origHtml); return; }
                $.post(PE.ajax_url, {
                    action: 'brikpanel_inline_edit', security: PE.nonce,
                    product_id: productId, field: field, value: newVal
                }, function (r) {
                    if (r.success) {
                        $el.text(newVal).data('value', newVal);
                        // Update stock badge class
                        if (field === 'stock') {
                            $el.removeClass('brikpanel-pe-stock-low brikpanel-pe-stock-out');
                            var n = parseInt(newVal, 10);
                            if (n === 0) $el.addClass('brikpanel-pe-stock-out');
                            else if (n <= 5) $el.addClass('brikpanel-pe-stock-low');
                        }
                    } else { $el.html(origHtml); showToast(r.data.message || 'Error', 'error'); }
                }).fail(function () { $el.html(origHtml); });
            }

            $input.on('blur', saveInline);
            $input.on('keydown', function (e) {
                if (e.key === 'Enter') { e.preventDefault(); saveInline(); }
                if (e.key === 'Escape') { $el.html(origHtml); }
            });
        });
    }

    /* Toast */
    function showToast(msg, type) {
        var $c = $('#bpe-toast-container');
        if (!$c.length) { $c = $('<div id="bpe-toast-container" class="bpe-toast-container">'); $('body').append($c); }
        var $t = $('<div class="bpe-toast bpe-toast-' + type + '">' + esc(msg) + '</div>');
        $c.append($t);
        requestAnimationFrame(function () { $t.addClass('show'); });
        setTimeout(function () { $t.removeClass('show'); setTimeout(function () { $t.remove(); }, 300); }, 3500);
    }

    /* Helpers */
    function parsePrice(v, sep) {
        if (!v) return '';
        v = v.replace(/\s/g, '');
        if (sep === ',') { v = v.replace(/\./g, '').replace(',', '.'); } else { v = v.replace(/,/g, ''); }
        return v;
    }

    function slugify(t) {
        return t.toString().normalize('NFD').replace(/[\u0300-\u036f]/g, '')
            .replace(/\u0131/g, 'i').replace(/\u015f/g, 's').replace(/\u00e7/g, 'c')
            .replace(/\u011f/g, 'g').replace(/\u00f6/g, 'o').replace(/\u00fc/g, 'u')
            .toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
    }

    function esc(s) { if (!s) return ''; var d = document.createElement('div'); d.appendChild(document.createTextNode(s)); return d.innerHTML; }

    $(document).ready(init);
})(jQuery);
