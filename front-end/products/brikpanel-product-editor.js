/**
 * BrikPanel – Simplified Product Editor
 * @package BrikPanel
 * @since 1.6.0
 */
(function ($) {
    'use strict';

    var PE = brikpanelPE || {};
    var productData = window.brikpanelProductData || {};

    var state = { images: [], saving: false, dirty: false, varTemplate: null, varAttributes: [], variations: [], downloads: [], tags: [], linked: { upsells: [], cross_sells: [] }, varCustomStarted: false, defaultAttributes: {} };

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
        initSurerankAnalyze();
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

        // Keep the SEOPress "Primary category" dropdown in sync with the
        // category checklist — only the currently-selected categories are valid
        // primary choices. Delegated so it survives checklist re-renders.
        $(document).on('change', 'input[name="category_ids[]"]', syncPrimaryCatOptions);

        $('#bpe-add-brand-toggle').on('click', function (e) { e.preventDefault(); toggleSection($('#bpe-new-brand-section')); });
        $('#bpe-add-brand-btn').on('click', addBrand);

        // Quick-start template cards (shown only on an empty variable product).
        // "Size + Color" drops in the two variation rows; "Custom" just reveals
        // the attribute controls so the user builds their own.
        $('#bpe-var-templates').on('click', '.brikpanel-pe-var-template', function () {
            var t = $(this).data('template');
            state.varCustomStarted = true;
            if (t === 'size-color') {
                quickAddSizeColor();
            } else {
                if (!$('#bpe-var-toggle').is(':checked')) { $('#bpe-var-toggle').prop('checked', true).trigger('change'); }
                updateVarStartView();
                $('#bpe-attr-new-name').trigger('focus');
            }
        });
        // Quick add from the controls: Size + Color rows flagged for variations,
        // turning the product variable in one click.
        $('#bpe-attr-quick-sizecolor').on('click', quickAddSizeColor);
        $('#bpe-generate-vars').on('click', generateVariations);
        $('#bpe-apply-bulk').on('click', applyBulk);

        // Default Form Values selects (delegated — rebuilt on every table render).
        $('#bpe-var-defaults').on('change', '.bpe-var-default', function () {
            var key = $(this).attr('data-key');
            var val = $(this).val();
            if (val) { state.defaultAttributes[key] = val; }
            else { delete state.defaultAttributes[key]; }
            state.dirty = true;
        });

        // Duplicate (delegated so dynamically injected button still works)
        $(document).on('click', '#bpe-duplicate', duplicateProduct);

        // Scope to real form controls: ACF (and other plugins) put
        // data-required="1" on the field *wrapper div*, not the input. A bare
        // [data-required] selector would match those wrappers too — see the
        // note in validateAll().
        $(':input[data-required]').on('blur', function () { validateField($(this)); });

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

    /* Build a datetime-local value (Y-m-d\TH:i) for ~24h from now, in the
       browser's local time — used to seed the schedule picker when the merchant
       first switches to "Scheduled" and the field is empty. */
    function defaultScheduleValue() {
        var d = new Date(Date.now() + 24 * 60 * 60 * 1000);
        function p(n) { return (n < 10 ? '0' : '') + n; }
        return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate()) +
               'T' + p(d.getHours()) + ':' + p(d.getMinutes());
    }

    /* Swap the primary button label to "Schedule" while the status is future,
       restoring the original label (Publish/Update/Save) for any other status.
       The server-rendered label is captured once as the baseline. */
    function updatePublishLabel(status) {
        var $pub = $('#bpe-publish');
        if (!$pub.length) return;
        if (typeof $pub.data('origLabel') === 'undefined') {
            $pub.data('origLabel', $.trim($pub.text()));
        }
        if (status === 'future') {
            $pub.text(PE.i18n.schedule || 'Schedule');
        } else {
            $pub.text($pub.data('origLabel'));
        }
    }

    /* Custom Visibility dropdown (replaces the old <select>) */
    function initStatusDropdown() {
        var $wrap = $('.brikpanel-pe-status-wrap');
        if (!$wrap.length) return;
        var $trigger = $('#bpe-status-trigger');
        var $menu = $wrap.find('.brikpanel-pe-status-menu');
        var $hidden = $('#bpe-status');

        // Capture the non-future baseline for the primary button label. If the
        // product loads already scheduled, the server label is "Schedule"; derive
        // the correct fallback (Update for an existing product, Publish for a new
        // one) so switching away from "Scheduled" doesn't leave a stale label.
        var $pubBtn = $('#bpe-publish');
        if ($pubBtn.length && typeof $pubBtn.data('origLabel') === 'undefined') {
            if ($hidden.val() === 'future') {
                var isEdit = parseInt($('#bpe-product-id').val() || '0', 10) > 0;
                $pubBtn.data('origLabel', isEdit ? (PE.i18n.update || 'Update') : (PE.i18n.publish || 'Publish'));
            } else {
                $pubBtn.data('origLabel', $.trim($pubBtn.text()));
            }
        }

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
            // Show/hide the scheduled publish date/time picker. When switching to
            // "Scheduled" with an empty field, seed a sensible near-future default
            // (tomorrow, same time) so the merchant isn't left with a blank input.
            var $schedWrap = $('#bpe-schedule-wrap');
            if ($schedWrap.length) {
                if (v === 'future') {
                    var $date = $('#bpe-schedule-date');
                    if (!$date.val()) { $date.val(defaultScheduleValue()); }
                    $schedWrap.addClass('is-visible');
                    $date.focus();
                } else {
                    $schedWrap.removeClass('is-visible');
                }
            }
            updatePublishLabel(v);
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
        bindToggle('#bpe-digital-toggle', '#bpe-digital-section');

        // Hide the parent pricing + inventory cards whenever the variations
        // toggle is on — each variation row already carries its own price,
        // sale schedule, stock qty, stock status and SKU, so the top-level
        // fields are dead inputs in that mode and must not block submit.
        // The unified attribute editor stays visible for both product types:
        // a simple product treats every attribute as a spec, while a variable
        // product splits them by each row's "Use for variations" switch (Size,
        // Color drive variations; Brand, Material stay as specs). The variation
        // builder (Generate + table) and the per-row switches only show while
        // "Variable product" is on.
        function syncVariableMode() {
            var isVar = $('#bpe-var-toggle').is(':checked');
            $('#bpe-pricing-card, #bpe-inventory-card, #bpe-gtin-card').toggle(!isVar);
            $('#bpe-var-card').toggleClass('bpe-variable-on', isVar);
            $('#bpe-var-build').toggle(isVar);
            // The table only makes sense once variations have been generated.
            $('#bpe-var-table-section').toggle(isVar && !!(state.variations && state.variations.length));
            $('.brikpanel-pe-attr-help-var').toggle(isVar);
            $('.brikpanel-pe-attr-help-simple').toggle(!isVar);
            updateVarStartView();
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
        // Scope each search box to its own wrapper so the Category search and
        // the Brand search (which share .brikpanel-pe-cat-* classes) never
        // filter each other's lists.
        $('#bpe-cat-search, #bpe-brand-search').on('input', function () {
            var q = this.value.toLowerCase();
            var $wrap = $(this).closest('.brikpanel-pe-cat-wrap');
            $wrap.find('.brikpanel-pe-cat-tree li').each(function () {
                var name = $(this).data('name') || '';
                var match = name.indexOf(q) !== -1 || q === '';
                $(this).toggle(match);
                // Show parent chain if child matches
                if (match && q) $(this).parents('li').show();
            });
        });
    }

    // Rebuild the SEOPress "Primary category" <select> from the currently
    // checked categories, preserving the chosen primary when it is still
    // selected. The first option (the server-rendered "None" label) is left
    // untouched so no user-facing string lives in JS.
    function syncPrimaryCatOptions() {
        var $sel = $('#bpe-seo-primary-cat');
        if (!$sel.length) return;
        var current = $sel.val();
        $sel.find('option').not(':first').remove();
        $('input[name="category_ids[]"]:checked').each(function () {
            var $cb = $(this);
            var label = $.trim($cb.closest('label').text());
            $sel.append($('<option></option>').attr('value', $cb.val()).text(label));
        });
        if (current && $sel.find('option[value="' + current + '"]').length) {
            $sel.val(current);
        } else {
            $sel.val('none');
        }
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

            // The new term is pre-checked, so refresh the primary-category
            // dropdown to include it.
            syncPrimaryCatOptions();

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

    function addBrand() {
        var $btn = $('#bpe-add-brand-btn');
        if ($btn.prop('disabled')) return;

        var name = $.trim($('#bpe-new-brand-name').val());
        // The parent select only exists for hierarchical brand taxonomies.
        var parent = parseInt($('#bpe-new-brand-parent').val(), 10) || 0;
        if (!name) return;

        // Preserve the current client-side selection across the re-render.
        var selected = $('input[name="brand_ids[]"]:checked').map(function () {
            return this.value;
        }).get();

        $btn.prop('disabled', true);
        $.post(PE.ajax_url, {
            action: 'brikpanel_add_brand',
            security: PE.nonce,
            name: name,
            parent: parent,
            selected_ids: selected
        }, function (r) {
            $btn.prop('disabled', false);
            if (!r.success) {
                showToast((r.data && r.data.message) || (PE.i18n.error || 'Error'), 'error');
                return;
            }
            var d = r.data;

            $('#bpe-brand-list').html(d.checklist_html);

            // Rebuild parent dropdown (hierarchical taxonomies only) while
            // keeping the "— No parent —" sentinel and prior selection.
            var $select = $('#bpe-new-brand-parent');
            if ($select.length && d.options_html) {
                var prevParent = $select.val();
                $select.find('option').not('[value="0"]').remove();
                $select.append(d.options_html);
                if (prevParent && $select.find('option[value="' + prevParent + '"]').length) {
                    $select.val(prevParent);
                } else {
                    $select.val('0');
                }
            }

            $('#bpe-new-brand-name').val('').focus();

            var $search = $('#bpe-brand-search');
            if ($search.val()) $search.trigger('input');

            showToast(PE.i18n.brand_added || 'Brand added', 'success');
        }).fail(function () {
            $btn.prop('disabled', false);
            showToast(PE.i18n.error || 'Error', 'error');
        });
    }

    /* Tags the rich-text editor + paste sanitizer are allowed to keep. Block
       and inline maps are intentionally lean: a small, predictable subset that
       every toolbar control can still tweak afterwards (the user's complaint
       was that pasted website markup could only be fixed via the code view). */
    var EDITOR_BLOCK_TAGS  = { P:1, H2:1, H3:1, H4:1, H5:1, H6:1, UL:1, OL:1, LI:1, BLOCKQUOTE:1 };
    var EDITOR_TABLE_TAGS  = { TABLE:1, THEAD:1, TBODY:1, TFOOT:1, TR:1, TD:1, TH:1, CAPTION:1 };
    var EDITOR_INLINE_MAP  = { STRONG:'strong', B:'strong', EM:'em', I:'em', U:'u', A:'a', CODE:'code' };
    var EDITOR_DROP_TAGS   = { SCRIPT:1, STYLE:1, NOSCRIPT:1, IFRAME:1, VIDEO:1, AUDIO:1, OBJECT:1, EMBED:1, SVG:1, FORM:1, INPUT:1, BUTTON:1, META:1, LINK:1, HEAD:1 };

    /* Only the four WordPress alignment classes are allowed on an editor image;
       everything else (inline styles, arbitrary classes, onerror, …) is dropped
       so a pasted/inserted <img> can never carry a script vector. */
    function editorImageAlignClass(cls) {
        var m = ('' + (cls == null ? '' : cls)).match(/align(none|left|center|right)/);
        return m ? 'align' + m[1] : '';
    }
    /* Normalise an arbitrary width hint into a safe CSS width value
       ("50%" / "300px") or '' (let the image use its natural size). Only a
       bare number + optional %/px is accepted — anything else is dropped so a
       pasted style string can never smuggle extra declarations. */
    function editorSafeWidth(w) {
        if (w == null || w === '') return '';
        var m = ('' + w).match(/(\d{1,4})(?:\.\d+)?\s*(%|px)?/);
        if (!m) return '';
        var n = parseFloat(m[1]);
        if (!(n > 0)) return '';
        var unit = m[2] || 'px';
        if (unit === '%') { if (n > 100) n = 100; }
        else { if (n > 2000) n = 2000; }
        return n + unit;
    }
    /* Build a clean, attribute-restricted <img>. Returns '' when the URL is not
       safe. Only src, alt, the alignment + lightbox classes, and a width style
       survive. The brikpanel-lightbox class opts the image into the front-end
       click-to-enlarge overlay on the product page. */
    function editorBuildImg(src, alt, align, width, lightbox) {
        var safe = editorSafeUrl(src);
        if (!safe) return '';
        var cls = editorImageAlignClass(align && /^align/.test(align) ? align : ('align' + (align || 'none')));
        var lb = lightbox === true || (typeof align === 'string' && /(^|\s)brikpanel-lightbox(\s|$)/.test(align));
        if (lb) cls += (cls ? ' ' : '') + 'brikpanel-lightbox';
        var w = editorSafeWidth(width);
        var html = '<img src="' + editorEscAttr(safe) + '" alt="' + editorEscAttr(alt || '') + '"';
        if (cls) html += ' class="' + cls + '"';
        if (w) html += ' style="width:' + w + '"';
        return html + '>';
    }
    /* The size presets the dialog offers, as a fraction of the available width.
       'full' means no explicit width (natural size, capped by max-width:100%). */
    var EDITOR_IMG_SIZES = { small: '25%', medium: '50%', large: '75%', full: '' };
    function editorWidthToSize(cssWidth) {
        var m = ('' + (cssWidth || '')).match(/(\d{1,4})(?:\.\d+)?\s*%/);
        if (!m) return 'full';
        var n = parseFloat(m[1]);
        if (n <= 37) return 'small';
        if (n <= 62) return 'medium';
        if (n < 88) return 'large';
        return 'full';
    }

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
            var parentTag = node.tagName || '';
            for (var i = 0; i < kids.length; i++) {
                var child = kids[i];
                if (child.nodeType === 3) {
                    // Whitespace-only text between table-structural elements is
                    // layout cruft from the source — keep the grid markup clean.
                    if (/^(TABLE|THEAD|TBODY|TFOOT|TR)$/.test(parentTag) && !child.nodeValue.trim()) continue;
                    out += esc(child.nodeValue); continue;
                }
                if (child.nodeType !== 1) continue;
                var tag = child.tagName;
                if (tag === 'BR') { out += '<br>'; continue; }
                if (tag === 'IMG') {
                    // Void element — emit a sanitized image (safe URL + the four
                    // alignment classes + a width style only); skip if unsafe.
                    var iw = (child.style && child.style.width) ? child.style.width : (child.getAttribute('width') || '');
                    out += editorBuildImg(child.getAttribute('src'), child.getAttribute('alt'), child.getAttribute('class'), iw);
                    continue;
                }
                if (EDITOR_DROP_TAGS[tag]) continue;
                var inner = walk(child);
                if (tag === 'H1') tag = 'H2';
                if (EDITOR_BLOCK_TAGS[tag]) {
                    var lt = tag.toLowerCase();
                    if (tag === 'LI') { out += '<li>' + inner + '</li>'; }
                    else if (inner.replace(/<br>/g, '').trim() !== '' || tag === 'UL' || tag === 'OL') { out += '<' + lt + '>' + inner + '</' + lt + '>'; }
                } else if (EDITOR_TABLE_TAGS[tag]) {
                    // Preserve pasted tables (structure + colspan/rowspan) so the
                    // grid survives instead of collapsing into loose text.
                    var ltag = tag.toLowerCase();
                    var attrs = '';
                    if (tag === 'TD' || tag === 'TH') {
                        var cs = parseInt(child.getAttribute('colspan'), 10);
                        var rs = parseInt(child.getAttribute('rowspan'), 10);
                        if (cs > 1) attrs += ' colspan="' + cs + '"';
                        if (rs > 1) attrs += ' rowspan="' + rs + '"';
                    }
                    out += '<' + ltag + attrs + '>' + inner + '</' + ltag + '>';
                } else if (EDITOR_INLINE_MAP[tag]) {
                    var m = EDITOR_INLINE_MAP[tag];
                    if (m === 'a') {
                        var href = editorSafeUrl(child.getAttribute('href'));
                        out += href ? '<a href="' + editorEscAttr(href) + '" rel="noopener">' + inner + '</a>' : inner;
                    } else if (inner !== '') {
                        out += '<' + m + '>' + inner + '</' + m + '>';
                    }
                } else if (tag === 'DIV' || tag === 'SECTION' || tag === 'ARTICLE') {
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
        $field.find('[data-cmd="justifyLeft"]').toggleClass('is-active', st('justifyLeft'));
        $field.find('[data-cmd="justifyCenter"]').toggleClass('is-active', st('justifyCenter'));
        $field.find('[data-cmd="justifyRight"]').toggleClass('is-active', st('justifyRight'));
        var sel = window.getSelection();
        var block = 'p';
        if (sel && sel.rangeCount) {
            var n = sel.getRangeAt(0).commonAncestorContainer;
            while (n && n !== ed) {
                if (n.nodeType === 1) {
                    var t = n.tagName.toLowerCase();
                    // Every format the menu offers is reported as-is so the label
                    // and active tick match the caret's real block.
                    if (/^h[1-6]$/.test(t) || t === 'blockquote' || t === 'p') { block = t; break; }
                    if (t === 'div' || t === 'li') { block = 'p'; break; }
                }
                n = n.parentNode;
            }
        }
        if (['p', 'h1', 'h2', 'h3', 'h4', 'h5', 'blockquote'].indexOf(block) === -1) block = 'p';
        var $items = $field.find('.brikpanel-pe-fmt-item');
        $items.removeClass('is-active');
        var $active = $items.filter('[data-format="' + block + '"]').addClass('is-active');
        if ($active.length) {
            $field.find('.brikpanel-pe-fmt-label').text($active.text());
        }
    }

    // execCommand('foreColor') always emits `color: rgb(...)`, but wp_kses_post
    // strips rgb() on save (it only keeps hex / named colours). Convert to hex
    // so applied colours actually persist. Idempotent for values already hex.
    function bpeRgbToHex(rgb) {
        var m = /^rgba?\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)/i.exec(rgb || '');
        if (!m) return null;
        function h(x) { x = parseInt(x, 10).toString(16); return x.length === 1 ? '0' + x : x; }
        return '#' + h(m[1]) + h(m[2]) + h(m[3]);
    }

    function runEditorCommand($field, cmd, value) {
        var $editor = $field.find('.brikpanel-pe-editor');
        var $source = $field.find('.brikpanel-pe-editor-source');
        if (!$source.prop('hidden')) return; // visual mode only
        $editor.focus();
        if (cmd === 'formatBlock') {
            document.execCommand('formatBlock', false, '<' + value + '>');
        } else if (cmd === 'foreColor') {
            // styleWithCSS makes execCommand emit <span style="color:…"> instead
            // of the deprecated <font color> tag, so the colour survives
            // wp_kses_post on save (text-align + color are allowed CSS props).
            try { document.execCommand('styleWithCSS', false, true); } catch (e) {}
            document.execCommand('foreColor', false, value);
            try { document.execCommand('styleWithCSS', false, false); } catch (e) {}
            // Normalise the rgb() execCommand produced to hex so wp_kses_post
            // keeps it (it strips color: rgb(...) but allows hex). Must rewrite
            // the style ATTRIBUTE string via setAttribute — assigning
            // element.style.color re-serialises back to rgb() in innerHTML.
            $editor.find('[style*="color"]').each(function () {
                var styleAttr = this.getAttribute('style');
                if (!styleAttr || !/color:\s*rgb/i.test(styleAttr)) { return; }
                var fixed = styleAttr.replace(/color:\s*(rgba?\([^)]*\))/gi, function (whole, rgbVal) {
                    var hex = bpeRgbToHex(rgbVal);
                    return hex ? 'color: ' + hex : whole;
                });
                this.setAttribute('style', fixed);
            });
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
        $('.brikpanel-pe-fmt.is-open, .brikpanel-pe-colorpick.is-open').removeClass('is-open')
            .find('.brikpanel-pe-fmt-trigger, .brikpanel-pe-color-trigger').attr('aria-expanded', 'false');
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

    /* ---- Image dialog (choose / replace, alt text, alignment) ---------- */
    var imageDlgState = { $field: null, img: null, src: '', alt: '', align: 'none', size: 'full', lightbox: false };
    var imageMediaFrame = null;

    function buildImageDialog() {
        if (document.getElementById('bpe-image-dialog')) return;
        var i = PE.i18n || {};
        // Shares .brikpanel-pe-linkdlg styling (vars, backdrop, box, actions);
        // .brikpanel-pe-imgdlg adds the preview + alignment-segment styles.
        var html =
            '<div id="bpe-image-dialog" class="brikpanel-pe-linkdlg brikpanel-pe-imgdlg" hidden>' +
              '<div class="brikpanel-pe-linkdlg-backdrop"></div>' +
              '<div class="brikpanel-pe-linkdlg-box" role="dialog" aria-modal="true" aria-label="' + esc(i.image_title || 'Insert image') + '">' +
                '<div class="brikpanel-pe-linkdlg-title brikpanel-pe-imgdlg-title">' + esc(i.image_title || 'Insert image') + '</div>' +
                '<div class="brikpanel-pe-imgdlg-preview" id="bpe-img-preview"></div>' +
                '<button type="button" class="brikpanel-pe-btn secondary brikpanel-pe-imgdlg-choose">' + esc(i.image_choose || 'Choose image') + '</button>' +
                '<label class="brikpanel-pe-linkdlg-lbl" for="bpe-img-alt">' + esc(i.image_alt || 'Alt text (description)') + '</label>' +
                '<input type="text" id="bpe-img-alt" class="brikpanel-pe-linkdlg-url" placeholder="' + esc(i.image_alt_ph || '') + '" autocomplete="off">' +
                '<label class="brikpanel-pe-linkdlg-lbl">' + esc(i.image_align || 'Alignment') + '</label>' +
                '<div class="brikpanel-pe-imgdlg-align" role="group">' +
                  '<button type="button" data-align="none">' + esc(i.align_none || 'None') + '</button>' +
                  '<button type="button" data-align="left">' + esc(i.align_left || 'Left') + '</button>' +
                  '<button type="button" data-align="center">' + esc(i.align_center || 'Center') + '</button>' +
                  '<button type="button" data-align="right">' + esc(i.align_right || 'Right') + '</button>' +
                '</div>' +
                '<label class="brikpanel-pe-linkdlg-lbl">' + esc(i.image_size || 'Size') + '</label>' +
                '<div class="brikpanel-pe-imgdlg-size" role="group">' +
                  '<button type="button" data-size="small">' + esc(i.size_small || 'Small') + '</button>' +
                  '<button type="button" data-size="medium">' + esc(i.size_medium || 'Medium') + '</button>' +
                  '<button type="button" data-size="large">' + esc(i.size_large || 'Large') + '</button>' +
                  '<button type="button" data-size="full">' + esc(i.size_full || 'Full') + '</button>' +
                '</div>' +
                '<label class="brikpanel-pe-linkdlg-check brikpanel-pe-imgdlg-lightbox"><input type="checkbox" id="bpe-img-lightbox"><span>' + esc(i.image_lightbox || 'Open in a lightbox when clicked') + '</span></label>' +
                '<div class="brikpanel-pe-linkdlg-actions">' +
                  '<button type="button" class="brikpanel-pe-btn secondary brikpanel-pe-imgdlg-cancel">' + esc(i.link_cancel || 'Cancel') + '</button>' +
                  '<button type="button" class="brikpanel-pe-btn primary brikpanel-pe-imgdlg-ok">' + esc(i.image_insert || 'Insert image') + '</button>' +
                '</div>' +
              '</div>' +
            '</div>';
        $('body').append(html);

        var $dlg = $('#bpe-image-dialog');
        function closeDlg() {
            $dlg.attr('hidden', true);
            imageDlgState.$field = null;
            imageDlgState.img = null;
        }
        function syncDlg() {
            var i2 = PE.i18n || {};
            var $prev = $('#bpe-img-preview');
            if (imageDlgState.src) {
                $prev.html('<img src="' + editorEscAttr(imageDlgState.src) + '" alt="">').removeClass('is-empty');
                $dlg.find('.brikpanel-pe-imgdlg-choose').text(i2.image_replace || 'Replace image');
            } else {
                $prev.text(i2.image_none || 'No image selected').addClass('is-empty');
                $dlg.find('.brikpanel-pe-imgdlg-choose').text(i2.image_choose || 'Choose image');
            }
            $dlg.find('.brikpanel-pe-imgdlg-align button').each(function () {
                $(this).toggleClass('is-active', $(this).data('align') === imageDlgState.align);
            });
            $dlg.find('.brikpanel-pe-imgdlg-size button').each(function () {
                $(this).toggleClass('is-active', $(this).data('size') === imageDlgState.size);
            });
            $('#bpe-img-lightbox').prop('checked', !!imageDlgState.lightbox);
        }
        $dlg.data('sync', syncDlg);

        $dlg.find('.brikpanel-pe-imgdlg-cancel, .brikpanel-pe-linkdlg-backdrop').on('click', closeDlg);
        $dlg.on('keydown', function (e) { if (e.key === 'Escape') closeDlg(); });

        $dlg.find('.brikpanel-pe-imgdlg-align').on('click', 'button', function () {
            imageDlgState.align = $(this).data('align');
            syncDlg();
        });

        $dlg.find('.brikpanel-pe-imgdlg-size').on('click', 'button', function () {
            imageDlgState.size = $(this).data('size');
            syncDlg();
        });

        $dlg.on('change', '#bpe-img-lightbox', function () {
            imageDlgState.lightbox = $(this).is(':checked');
        });

        $dlg.find('.brikpanel-pe-imgdlg-choose').on('click', function () {
            if (typeof wp === 'undefined' || !wp.media) return;
            if (!imageMediaFrame) {
                imageMediaFrame = wp.media({
                    title: PE.i18n.image_choose || 'Choose image',
                    button: { text: PE.i18n.select || 'Select' },
                    library: { type: 'image' },
                    multiple: false
                });
                imageMediaFrame.on('select', function () {
                    var a = imageMediaFrame.state().get('selection').first();
                    if (!a) return;
                    a = a.toJSON();
                    // Prefer a web-friendly size over the full-res original.
                    var url = a.url;
                    if (a.sizes) {
                        if (a.sizes.large) url = a.sizes.large.url;
                        else if (a.sizes.medium_large) url = a.sizes.medium_large.url;
                    }
                    imageDlgState.src = url;
                    if (!$('#bpe-img-alt').val()) {
                        imageDlgState.alt = a.alt || '';
                        $('#bpe-img-alt').val(imageDlgState.alt);
                    }
                    syncDlg();
                });
            }
            imageMediaFrame.open();
        });

        $dlg.find('.brikpanel-pe-imgdlg-ok').on('click', function () {
            var $field = imageDlgState.$field;
            if (!$field) { closeDlg(); return; }
            imageDlgState.src = imageDlgState.src || '';
            if (!imageDlgState.src) { showToast(PE.i18n.image_required || 'Please choose an image first.', 'error'); return; }
            var alt = $('#bpe-img-alt').val() || '';
            var align = imageDlgState.align || 'none';
            var width = EDITOR_IMG_SIZES[imageDlgState.size || 'full'] || '';
            var lightbox = !!imageDlgState.lightbox;
            var existing = imageDlgState.img;
            var ed = $field.find('.brikpanel-pe-editor')[0];
            closeDlg();

            if (existing && ed && ed.contains(existing)) {
                // Edit in place: only ever set the validated attributes.
                existing.setAttribute('src', editorSafeUrl(imageDlgState.src) || existing.getAttribute('src'));
                existing.setAttribute('alt', alt);
                existing.className = 'align' + align + (lightbox ? ' brikpanel-lightbox' : '');
                var sw = editorSafeWidth(width);
                if (sw) { existing.style.width = sw; } else { existing.style.removeProperty('width'); }
            } else {
                insertImageIntoEditor($field, editorBuildImg(imageDlgState.src, alt, align, width, lightbox));
            }
            state.dirty = true;
            refreshEditorToolbar($field);
        });
    }

    /* Insert an image at the saved caret and leave the caret right after it so
       the user can keep typing without re-clicking. A trailing <br> is added
       when the image would otherwise be the last node in its block (a bare
       block/floated image at the end of a contenteditable has no caret slot to
       continue from), giving the integrated "type after the image" feel. */
    function insertImageIntoEditor($field, imgHtml) {
        if (!imgHtml) return;
        restoreEditorRange($field);
        var ed = $field.find('.brikpanel-pe-editor')[0];
        if (!ed) return;
        var tmp = document.createElement('div');
        tmp.innerHTML = imgHtml;
        var node = tmp.firstChild;
        if (!node) return;

        var sel = window.getSelection();
        var range;
        if (sel && sel.rangeCount && ed.contains(sel.getRangeAt(0).commonAncestorContainer)) {
            range = sel.getRangeAt(0);
            range.deleteContents();
            range.insertNode(node);
        } else {
            ed.appendChild(node);
        }

        // Guarantee a caret slot only when the image landed as a bare, trailing
        // child of the editable root (an empty editor) — there a block/floated
        // image has nowhere to type next. Inside a paragraph the browser already
        // gives a caret after the image, so adding a <br> would just leave a
        // stray blank line.
        if (node.parentNode === ed && !node.nextSibling) {
            node.parentNode.insertBefore(document.createElement('br'), null);
        }

        var r = document.createRange();
        r.setStartAfter(node);
        r.collapse(true);
        sel.removeAllRanges();
        sel.addRange(r);
        ed.focus();
    }

    function openImageDialog($field, existingImg) {
        buildImageDialog();
        rememberEditorRange($field);
        var i = PE.i18n || {};
        imageDlgState.$field = $field;
        imageDlgState.img = existingImg || null;
        if (existingImg) {
            imageDlgState.src = existingImg.getAttribute('src') || '';
            imageDlgState.alt = existingImg.getAttribute('alt') || '';
            imageDlgState.align = editorImageAlignClass(existingImg.className).replace('align', '') || 'none';
            imageDlgState.size = editorWidthToSize(existingImg.style && existingImg.style.width);
            imageDlgState.lightbox = /(^|\s)brikpanel-lightbox(\s|$)/.test(existingImg.className || '');
        } else {
            imageDlgState.src = '';
            imageDlgState.alt = '';
            imageDlgState.align = 'none';
            imageDlgState.size = 'full';
            imageDlgState.lightbox = false;
        }
        var $dlg = $('#bpe-image-dialog');
        $dlg.find('.brikpanel-pe-imgdlg-title').text(existingImg ? (i.image_edit_title || 'Image settings') : (i.image_title || 'Insert image'));
        $dlg.find('.brikpanel-pe-imgdlg-ok').text(existingImg ? (i.image_update || 'Update image') : (i.image_insert || 'Insert image'));
        $('#bpe-img-alt').val(imageDlgState.alt);
        ($dlg.data('sync') || function () {})();
        $dlg.removeAttr('hidden');
        setTimeout(function () { $('#bpe-img-alt').focus(); }, 0);
    }

    function initEditor() {
        buildLinkDialog();
        buildImageDialog();

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

            // Text-colour dropdown — trigger toggles, swatches apply.
            if ($btn.hasClass('brikpanel-pe-color-trigger')) {
                var $cp = $btn.closest('.brikpanel-pe-colorpick');
                var cpOpen = !$cp.hasClass('is-open');
                closeFmtMenus();
                $cp.toggleClass('is-open', cpOpen);
                $btn.attr('aria-expanded', cpOpen ? 'true' : 'false');
                return;
            }
            if ($btn.hasClass('brikpanel-pe-color-swatch')) {
                closeFmtMenus();
                if ($source.prop('hidden')) {
                    runEditorCommand($field, 'foreColor', $btn.data('color'));
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

            if (cmd === 'image') {
                if ($source.prop('hidden')) openImageDialog($field, null);
                return;
            }

            runEditorCommand($field, cmd);
        });

        // Close the heading + colour menus on outside click / Escape.
        $(document).on('click', function (e) {
            if (!$(e.target).closest('.brikpanel-pe-fmt, .brikpanel-pe-colorpick').length) closeFmtMenus();
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

        // Click an existing image to re-open the dialog (change alignment, alt,
        // or replace it). Visual mode only.
        $('.brikpanel-pe-editor').on('click', 'img', function (e) {
            var $field = $(this).closest('[data-editor-field]');
            if (!$field.find('.brikpanel-pe-editor-source').prop('hidden')) return;
            e.preventDefault();
            openImageDialog($field, this);
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

    /* Quick add: insert Size and Color rows already flagged for variations and
       flip the product to variable, covering the common apparel case in one
       click. Pre-attaches the matching global taxonomy when one exists so term
       suggestions and the save path use the real attribute. */
    function quickAddSizeColor() {
        var $vt = $('#bpe-var-toggle');
        if (!$vt.prop('checked')) { $vt.prop('checked', true).trigger('change'); }
        var sizeAttr  = findGlobalAttrForRole('size');
        var colorAttr = findGlobalAttrForRole('color');
        var sizeName  = sizeAttr  ? sizeAttr.name  : (PE.i18n.size  || 'Size');
        var colorName = colorAttr ? colorAttr.name : (PE.i18n.color || 'Color');
        if (!attributeExistsInList(sizeName)) {
            $('#bpe-attr-list').append(createAttrRow(sizeName, ['S', 'M', 'L', 'XL', 'XXL'], sizeAttr ? sizeAttr.taxonomy : '', true));
        }
        if (!attributeExistsInList(colorName)) {
            $('#bpe-attr-list').append(createAttrRow(colorName, [], colorAttr ? colorAttr.taxonomy : '', true));
        }
        state.varCustomStarted = true;
        state.dirty = true;
        updateVarStartView();
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
            // Cap the rendered rows so a huge taxonomy (hundreds of terms)
            // keeps the DOM light, but high enough that a store with ~40
            // colors shows every option in the scrollable dropdown. The old
            // cap of 10 hid the rest even while typing a substring, so terms
            // past the 10th could never be picked.
            var matches = availableTerms.filter(function (t) {
                return existing.indexOf(t.toLowerCase()) === -1 && (!q || t.toLowerCase().indexOf(q) !== -1);
            }).slice(0, 50);
            if (!matches.length) { $suggestions.hide(); return; }
            var html = '';
            matches.forEach(function (t) {
                html += '<div class="brikpanel-pe-tag-suggestion" data-value="' + esc(t) + '">' + esc(t) + '</div>';
            });
            $suggestions.html(html).show();
        }

        $input.on('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); var v = $.trim(this.value); if (v && !tagExists($wrap, v)) { $input.before(createTag(v)); this.value = ''; showTermSuggestions(''); $(this).trigger('change'); } }
            if (e.key === 'Backspace' && !this.value) { $wrap.find('.brikpanel-pe-tag:last').remove(); showTermSuggestions(''); $(this).trigger('change'); }
        });

        $input.on('input', function () { showTermSuggestions($.trim(this.value)); });
        $input.on('focus', function () { showTermSuggestions($.trim(this.value)); });
        $input.on('blur', function () { setTimeout(function () { $suggestions.hide(); }, 150); });

        $suggestions.on('mousedown', '.brikpanel-pe-tag-suggestion', function (e) {
            e.preventDefault();
            // Read via attr(), not data(): jQuery.data() JSON-coerces a purely
            // numeric term (shoe sizes like "40", "42") into a Number, and the
            // string helpers below then throw on .toLowerCase(). Keep it a string.
            var v = String($(this).attr('data-value') || '');
            if (v && !tagExists($wrap, v)) {
                $input.before(createTag(v));
                $input.val('');
                showTermSuggestions('');
                $input.trigger('change');
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
        $rm.on('click', function () {
            // Removing a value changes the attribute set — notify the list so the
            // "regenerate variations" hint can re-evaluate (tag remove is a click,
            // it emits no input/change event of its own).
            var $list = $tag.closest('#bpe-attr-list');
            $tag.remove();
            if ($list.length) { $list.trigger('change'); }
        });
        $tag.append($rm);
        return $tag;
    }

    function tagExists($w, val) {
        var needle = String(val).toLowerCase();
        var e = false;
        $w.find('.brikpanel-pe-tag').each(function () { if ($(this).clone().children().remove().end().text().trim().toLowerCase() === needle) e = true; });
        return e;
    }

    /* ====== Unified attribute editor (variation attributes + plain specs) ===
       One list. Each row carries a "Use for variations" switch: when the
       product is Variable, switched-on rows build the variation table and
       switched-off rows stay as specs; when Simple, every row is a spec. */

    function isVariableOn() { return $('#bpe-var-toggle').is(':checked'); }

    /* Quick-start template cards vs. the attribute controls. The cards are an
       empty-state shortcut: shown only when the product is Variable, no
       attributes exist yet, and the user has not dismissed them via "Custom".
       Otherwise the controls + list take over. */
    function updateVarStartView() {
        var $templates = $('#bpe-var-templates');
        if (!$templates.length) { return; }
        var empty = $('#bpe-attr-list .brikpanel-pe-attr-row').length === 0;
        var showTemplates = isVariableOn() && empty && !state.varCustomStarted;
        $templates.toggle(showTemplates);
        $('#bpe-var-attr-controls').toggle(!showTemplates);
        refreshVarStaleHint();
    }

    function initAttributes() {
        var $list = $('#bpe-attr-list');
        if (!$list.length) return;

        // Existing global attribute picker — a searchable popover dropdown.
        // New rows default to "use for variations" when the product is currently
        // Variable, else to a spec. Rows start with an empty value list: the
        // typeahead inside the tag input lets the user pick only the terms they
        // want (pre-loading every term locks up the browser on huge taxonomies).
        var $combo = $('#bpe-attr-combo');
        if ($combo.length) {
            var $comboTrigger = $('#bpe-attr-combo-trigger');
            var $comboSearch = $('#bpe-attr-combo-search');
            var $comboOptions = $combo.find('.brikpanel-pe-combo-option');
            var $comboEmpty = $combo.find('.brikpanel-pe-combo-empty');

            var closeCombo = function () {
                $combo.removeClass('open');
                $comboTrigger.attr('aria-expanded', 'false');
            };
            var openCombo = function () {
                $combo.addClass('open');
                $comboTrigger.attr('aria-expanded', 'true');
                $comboSearch.val('').trigger('input');
                setTimeout(function () { $comboSearch.trigger('focus'); }, 0);
            };

            $comboTrigger.on('click', function (e) {
                e.stopPropagation();
                if ($combo.hasClass('open')) { closeCombo(); } else { openCombo(); }
            });

            $comboSearch.on('input', function () {
                var q = $.trim(this.value).toLowerCase();
                var any = false;
                $comboOptions.each(function () {
                    var match = !q || this.textContent.toLowerCase().indexOf(q) !== -1;
                    this.style.display = match ? '' : 'none';
                    if (match) { any = true; }
                });
                $comboEmpty[0].style.display = any ? 'none' : '';
            });

            $comboSearch.on('keydown', function (e) {
                if (e.key === 'Escape') {
                    e.preventDefault();
                    closeCombo();
                    $comboTrigger.trigger('focus');
                } else if (e.key === 'Enter') {
                    e.preventDefault();
                    var $first = $comboOptions.filter(function () { return this.style.display !== 'none'; }).first();
                    if ($first.length) { $first.trigger('click'); }
                }
            });

            $combo.on('click', '.brikpanel-pe-combo-option', function () {
                var name = $(this).attr('data-value');
                var taxonomy = $(this).attr('data-taxonomy') || '';
                closeCombo();
                if (!name || attributeExistsInList(name)) { return; }
                $list.append(createAttrRow(name, [], taxonomy, isVariableOn()));
                state.varCustomStarted = true;
                state.dirty = true;
                updateVarStartView();
            });

            $(document).on('click', function (e) {
                if ($combo.hasClass('open') && !$combo[0].contains(e.target)) { closeCombo(); }
            });
        }

        // "Add" button for new custom (non-taxonomy) attributes.
        var $newName = $('#bpe-attr-new-name');
        $('#bpe-attr-add').on('click', function () {
            var name = $.trim($newName.val());
            if (!name || attributeExistsInList(name)) { $newName.focus(); return; }
            $list.append(createAttrRow(name, [], '', isVariableOn()));
            $newName.val('').focus();
            state.varCustomStarted = true;
            state.dirty = true;
            updateVarStartView();
        });
        $newName.on('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); $('#bpe-attr-add').click(); }
        });

        // Editing values or toggling "use for variations" after a table was
        // generated drifts it out of sync — surface the regenerate hint.
        $list.on('input change', function () { refreshVarStaleHint(); });

        // Drag-to-reorder attribute rows (jquery-ui-sortable is a dependency of
        // this script). Order determines the variation axis order, so a reorder
        // dirties the form and marks any generated table stale.
        if (typeof $list.sortable === 'function') {
            $list.sortable({
                items: '> .brikpanel-pe-attr-row',
                handle: '.brikpanel-pe-attr-drag',
                tolerance: 'pointer',
                cursor: 'grabbing',
                placeholder: 'brikpanel-pe-attr-row-placeholder',
                forcePlaceholderSize: true,
                update: function () {
                    state.dirty = true;
                    refreshVarStaleHint();
                }
            });
        }
    }

    function attributeExistsInList(name) {
        var lower = String(name).toLowerCase();
        var found = false;
        $('#bpe-attr-list .brikpanel-pe-tag-group').each(function () {
            if (String($(this).attr('data-attr-name') || '').toLowerCase() === lower) found = true;
        });
        return found;
    }

    /* One attribute row: the tag-group (label + value tags) plus a per-row
       "Use for variations" switch and a remove button. The switch only shows
       while the product is Variable (CSS, via #bpe-var-card.bpe-variable-on). */
    function createAttrRow(name, defaults, taxonomy, useForVar) {
        var $row = $('<div class="brikpanel-pe-attr-row">');
        var $group = createTagGroup(name, defaults || [], taxonomy);

        var $controls = $('<span class="brikpanel-pe-attr-row-controls">');
        var $useLabel = $('<label class="brikpanel-pe-usevar">');
        var $useChk = $('<input type="checkbox" class="brikpanel-pe-usevar-check">');
        if (useForVar) { $useChk.prop('checked', true); }
        $useChk.on('change', function () { state.dirty = true; });
        $useLabel.append(
            $useChk,
            $('<span class="brikpanel-pe-usevar-slider"></span>'),
            $('<span class="brikpanel-pe-usevar-text">' + esc(PE.i18n.use_for_variations || 'Use for variations') + '</span>')
        );

        var $remove = $('<button type="button" class="brikpanel-pe-attr-remove" aria-label="' + esc(PE.i18n.remove || 'Remove') + '" title="' + esc(PE.i18n.remove || 'Remove') + '">&times;</button>');
        $remove.on('click', function () {
            $row.remove();
            state.dirty = true;
            // Back to a clean slate? Let the quick-start cards return.
            if ($('#bpe-attr-list .brikpanel-pe-attr-row').length === 0) { state.varCustomStarted = false; }
            updateVarStartView();
        });

        $controls.append($useLabel, $remove);
        $group.find('> label').first().append($controls);

        // Drag handle (left gutter) for reordering. Attribute order = variation
        // axis order, which drives the variation name join order and combo
        // nesting, so reordering must mark dirty + flag the table stale.
        var dragLabel = esc(PE.i18n.reorder_attribute || 'Drag to reorder');
        var $drag = $('<span class="brikpanel-pe-attr-drag" role="button" tabindex="0" aria-label="' + dragLabel + '" title="' + dragLabel + '">' +
            '<svg width="12" height="12" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true" focusable="false"><circle cx="5" cy="3" r="1.4"/><circle cx="11" cy="3" r="1.4"/><circle cx="5" cy="8" r="1.4"/><circle cx="11" cy="8" r="1.4"/><circle cx="5" cy="13" r="1.4"/><circle cx="11" cy="13" r="1.4"/></svg>' +
            '</span>');
        $row.append($drag, $group);
        return $row;
    }

    /* Read every attribute row into {name, values, taxonomy, useVar}. */
    function collectAttrRows() {
        var rows = [];
        $('#bpe-attr-list .brikpanel-pe-tag-group').each(function () {
            var $g = $(this);
            var name = $g.attr('data-attr-name');
            if (!name) return;
            var vals = [];
            $g.find('.brikpanel-pe-tag').each(function () {
                vals.push($(this).clone().children().remove().end().text().trim());
            });
            rows.push({
                name: name,
                values: vals,
                taxonomy: $g.attr('data-attr-taxonomy') || '',
                useVar: $g.find('.brikpanel-pe-usevar-check').is(':checked')
            });
        });
        return rows;
    }

    /* Variation attributes = rows flagged "use for variations" (only when the
       product is Variable). These drive the variation table. */
    /* Effective axis key for a row — must match genCombinations()/the server so
       two rows that resolve to the same key (e.g. "Size" and "Size ") collapse
       to one axis here instead of silently clobbering each other in the
       cartesian product. */
    function attrAxisKey(r) {
        return r.taxonomy ? r.taxonomy.toLowerCase() : slugify(r.name);
    }

    function collectVariationAttributes() {
        if (!isVariableOn()) return [];
        var out = [], seen = {};
        collectAttrRows().forEach(function (r) {
            if (!r.useVar || !r.values.length) return;
            var key = attrAxisKey(r);
            if (!key || seen[key]) return;
            seen[key] = true;
            out.push({ name: r.name, values: r.values, taxonomy: r.taxonomy });
        });
        return out;
    }

    /* Non-variation specs = every row when Simple; the not-used-for-variations
       rows when Variable. Saved as the product's descriptive attributes.
       `treatAsVariable` lets the save path pass the *effective* mode: when the
       Variable toggle is on but zero variations exist (the product falls back to
       simple server-side), pass false so the would-be variation rows are still
       persisted as specs instead of silently vanishing. */
    function collectNonVariationAttributes(treatAsVariable) {
        var variable = (typeof treatAsVariable === 'boolean') ? treatAsVariable : isVariableOn();
        var out = [], seen = {};
        collectAttrRows().forEach(function (r) {
            if (variable && r.useVar) return;
            if (!r.values.length) return;
            var key = attrAxisKey(r);
            if (!key || seen[key]) return;
            seen[key] = true;
            out.push({ name: r.name, values: r.values, taxonomy: r.taxonomy });
        });
        return out;
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
            v.enabled        = $row.find('.var-enabled').is(':checked');
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

    function generateVariations() {
        var attrs = collectVariationAttributes();
        if (!attrs.length) {
            showToast(PE.i18n.need_variation_attr || 'Switch on “Use for variations” for at least one attribute, then add its values.', 'error');
            return;
        }
        state.varAttributes = attrs;
        var combos = genCombinations(state.varAttributes), baseSKU = $('#bpe-sku').val() || '';
        // Guard the cartesian explosion: a handful of attributes with many
        // values each can produce thousands of rows that freeze the tab (and
        // the server caps the save anyway). Refuse and tell the user to trim.
        var MAX_GEN = 500;
        if (combos.length > MAX_GEN) {
            showToast((PE.i18n.too_many_variations || 'That combination would create more than %d variations. Reduce the number of attribute values, then try again.').replace('%d', MAX_GEN), 'error');
            return;
        }
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
                // New variations are Active by default (matches WooCommerce);
                // regenerating keeps whatever active state an existing row had.
                enabled: ex ? (ex.enabled === undefined ? true : !!ex.enabled) : true,
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
        $('#bpe-var-table-section').show();
        // Snapshot the attribute set this table was built from so later edits
        // to the attribute rows can surface a "regenerate" hint.
        state.varSignature = variationAttrSignature();
        refreshVarStaleHint();
        fetchVariationPreviews();
    }

    /* Stable signature of the current variation-attribute set (names + values +
       taxonomy). Used to detect that the attributes drifted from what the
       generated variation table was built on. */
    function variationAttrSignature() {
        return JSON.stringify(collectVariationAttributes());
    }

    /* Show the "attributes changed, regenerate" hint when a generated table is
       now out of sync with the attribute rows. Never blocks saving. */
    function refreshVarStaleHint() {
        var $hint = $('#bpe-var-stale-hint');
        if (!$hint.length) return;
        var stale = isVariableOn()
            && state.variations && state.variations.length
            && typeof state.varSignature === 'string'
            && state.varSignature !== variationAttrSignature();
        $hint.prop('hidden', !stale);
    }

    /* Pull the 3rd-party per-variation field structure for not-yet-saved rows
       so the "More fields" expander shows before the first save. No-op when no
       provider plugin is registered (server returns an empty map) or when every
       row already has a real ID. Re-renders once the HTML arrives. */
    function fetchVariationPreviews() {
        var newCount = 0;
        state.variations.forEach(function (v) { if (!v.id) newCount++; });
        if (!newCount) return;
        var pid = $('#bpe-product-id').val() || 0;
        if (!pid) return; // the auto-draft id is needed to parent the stub variation
        // Sequence guard: rapid Generate clicks / attribute edits fire
        // overlapping requests. Only the latest response may touch state, so a
        // late reply for a now-stale table can't attach extras to wrong rows.
        var seq = (state.previewSeq = (state.previewSeq || 0) + 1);
        var expectCount = state.variations.length;
        $.post(PE.ajax_url, {
            action: 'brikpanel_pe_preview_variation_fields',
            security: PE.nonce,
            product_id: pid,
            count: expectCount
        }).done(function (r) {
            if (seq !== state.previewSeq) return; // superseded by a newer fetch
            if (state.variations.length !== expectCount) return; // table changed underneath
            if (r && r.success && r.data && r.data.extras && !$.isEmptyObject(r.data.extras)) {
                state.previewExtras = r.data.extras;
                renderVarTable();
            }
        }).fail(function () {
            if (seq !== state.previewSeq) return;
            showToast(PE.i18n.preview_failed || 'Could not load extra variation fields. Saving still works.', 'error');
        });
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

    /* After a successful save the server's variation list is authoritative:
       it is exactly what was just persisted, with real IDs, attributes and the
       per-variation 3rd-party field map. Adopt it wholesale so brand-new
       variations pick up their IDs — and therefore their "More fields"
       expander — without a page reload. Matching by attribute signature is
       deliberately avoided: a "custom" attribute may resolve to an existing
       taxonomy on save (e.g. a "Size" label saved under pa_beden), so the
       client key and server key differ and any signature match would miss. */
    function adoptSavedVariations(savedVars, extras, silent) {
        if (!Array.isArray(savedVars)) return;
        productData.variation_extras = extras || {};
        productData.variations = savedVars;
        // Background auto-save runs behind the user's back — never disturb the
        // live table/state mid-edit. The fresh productData is enough for the
        // next manual render to be correct.
        if (silent) return;
        state.variations = savedVars.map(function (v) { return $.extend(true, {}, v); });
        // The freshly-saved table matches the current attribute rows, so reset
        // the drift baseline and clear any stale hint.
        state.varSignature = variationAttrSignature();
        refreshVarStaleHint();
        var $tableSection = $('#bpe-var-table-section');
        if ($tableSection.length && $tableSection.is(':visible') && state.variations.length) {
            renderVarTable();
        }
    }

    /* Default Form Values — one dropdown per variation attribute letting the
       merchant pick which option is pre-selected on the storefront (WC's
       native _default_attributes). Rebuilt from the live attribute rows so it
       always tracks the current axes/values; the chosen names are kept in
       state.defaultAttributes and round-tripped on save. */
    function renderDefaultFormValues() {
        var $wrap = $('#bpe-var-defaults');
        if (!$wrap.length) return;
        var attrs = collectVariationAttributes();
        if (!attrs.length) { $wrap.hide().empty(); return; }
        var i18n = PE.i18n || {};

        // Second group of the variation tools panel: a micro-heading matching
        // the bulk group's "Apply to all variations", then one self-describing
        // select per axis ("No default Color…"). The placeholder names the
        // attribute, so no per-field labels are needed; help rides as tooltip.
        var $title = $('<span class="brikpanel-pe-var-bulk-heading"></span>').text(i18n.default_form_values || 'Default Form Values');
        if (i18n.default_form_values_help) { $title.attr('title', i18n.default_form_values_help); }

        var $row = $('<div class="brikpanel-pe-var-defaults-row"></div>');
        attrs.forEach(function (a) {
            var key = attrAxisKey(a);
            var cur = state.defaultAttributes[key] || '';
            // Drop a stale selection that no longer matches a current value.
            if (cur && a.values.indexOf(cur) === -1) { cur = ''; delete state.defaultAttributes[key]; }

            var $sel = $('<select class="brikpanel-pe-select bpe-var-default"></select>').attr('data-key', key);
            var noneLabel = (i18n.no_default_for || 'No default %s…').replace('%s', a.name);
            $sel.append($('<option value=""></option>').text(noneLabel));
            a.values.forEach(function (val) {
                var $opt = $('<option></option>').attr('value', val).text(val);
                if (val === cur) $opt.prop('selected', true);
                $sel.append($opt);
            });
            $row.append($sel);
        });

        // Explicit flex (not .show(), which would force display:block and lose
        // the group's column layout).
        $wrap.empty().append($title).append($row).css('display', 'flex');
    }

    function renderVarTable() {
        var $tb = $('#bpe-var-table-body').empty(), sep = PE.decimal_sep || ',';
        renderDefaultFormValues();
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
        var previewExtras = state.previewExtras || {};
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
            // "Active" state. Undefined (freshly generated rows) defaults to on,
            // matching WooCommerce, so new variations are purchasable by default.
            var varEnabled = (v.enabled === undefined) ? true : !!v.enabled;
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
            // Saved variations read their real per-variation 3rd-party fields
            // (keyed by variation ID). Variations not yet persisted fall back to
            // the preview HTML (keyed by row index) so the expander works before
            // the first save; the loop index matches the save-time mapping.
            var extraHtml = (v.id && extras[v.id]) ? extras[v.id]
                : (!v.id && previewExtras[idx]) ? previewExtras[idx]
                : '';
            var hasExtra = !!extraHtml;
            var expanderTd = hasExtra
                ? '<td class="var-expand-cell"><button type="button" class="var-expand-btn" data-idx="' + idx + '" aria-label="' + esc(PE.i18n.more_fields || 'More fields') + '"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg></button></td>'
                : '<td class="var-expand-cell"></td>';
            var rowClasses = 'var-main-row';
            if (hasExtra) rowClasses += ' has-extra';
            if (hasBackorderNotify && varStatus === 'onbackorder') rowClasses += ' has-backorder';
            if (!varEnabled) rowClasses += ' is-disabled';
            // "Active" toggle sits at the head of the variation name cell —
            // where WooCommerce's own "Enabled" checkbox lives — so merchants
            // working only in this dashboard can turn a variation on/off.
            var enabledSwitch = '<label class="brikpanel-pe-switch brikpanel-pe-switch-xs var-enabled-switch" title="' + esc(PE.i18n.variation_active || 'Active') + '">' +
                '<input type="checkbox" class="var-enabled"' + (varEnabled ? ' checked' : '') + ' aria-label="' + esc(PE.i18n.variation_active || 'Active') + '">' +
                '<span class="brikpanel-pe-slider"></span></label>';
            $tb.append('<tr data-idx="' + idx + '" class="' + rowClasses + '">' +
                expanderTd +
                '<td class="var-name">' + enabledSwitch + '<span class="var-name-text">' + esc(v.name) + '</span></td>' +
                '<td><input type="text" class="var-price" value="' + esc(pv) + '" data-price="1" placeholder="0' + sep + '00"></td>' +
                '<td><input type="text" class="var-sale-price" value="' + esc(sv) + '" data-price="1" placeholder="0' + sep + '00"></td>' +
                '<td><input type="text" class="var-sale-from" value="' + esc(v.sale_from || '') + '" placeholder="' + esc(PE.i18n.date_placeholder || 'YYYY-MM-DD') + '" autocomplete="off"></td>' +
                '<td><input type="text" class="var-sale-to"   value="' + esc(v.sale_to   || '') + '" placeholder="' + esc(PE.i18n.date_placeholder || 'YYYY-MM-DD') + '" autocomplete="off"></td>' +
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
                    '<div class="brikpanel-pe-var-extras">' + extraHtml + '</div>' +
                    '</td></tr>');
            }
        });
        $tb.find('.var-image-btn').on('click', function () { openVarImagePicker($(this).data('idx')); });
        $tb.find('.var-image-remove').on('click', function (e) { e.stopPropagation(); removeVarImage($(this).data('idx')); });
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
            // When the last variation is removed there is nothing to show — hide
            // the table. The product stays "Variable" per the top toggle until
            // the user turns it off (save with zero variations falls back to a
            // simple product server-side).
            if (!state.variations.length) { $('#bpe-var-table-section').hide(); }
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
        // Per-variation "Active" toggle: reflect the on/off state visually
        // (dim the row when disabled) and mark the editor dirty so the change
        // is persisted on the next save. Bound fresh each render.
        $tb.find('.var-enabled').on('change', function () {
            var $cb = $(this), $row = $cb.closest('tr.var-main-row');
            $row.toggleClass('is-disabled', !$cb.is(':checked'));
            state.dirty = true;
        });
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
            // Delegated on the persistent table body, which survives re-renders,
            // so namespace + off first or each render stacks another handler and
            // one status change fires N times.
            $tb.off('change.bpVarStatus').on('change.bpVarStatus', '.var-stock-status', function () {
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
            var removeLabel = esc(PE.i18n.remove_image || 'Remove image');
            return '<span class="var-image-wrap" data-idx="' + idx + '">' +
                '<button type="button" class="var-image-btn has-images" data-idx="' + idx + '">' +
                '<img src="' + esc(images[0].url) + '" alt="">' + badge + '</button>' +
                '<button type="button" class="var-image-remove" data-idx="' + idx + '" aria-label="' + removeLabel + '" title="' + removeLabel + '">' +
                '<svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>' +
                '</span>';
        }
        return '<span class="var-image-wrap" data-idx="' + idx + '">' +
            '<button type="button" class="var-image-btn" data-idx="' + idx + '">' +
            '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/></svg></button>' +
            '</span>';
    }

    // Rebind click handlers for a single (re-rendered) variation image cell.
    function bindVarImageCell(idx) {
        var $wrap = $('#bpe-var-table-body .var-image-wrap[data-idx="' + idx + '"]');
        $wrap.find('.var-image-btn').off('click').on('click', function () { openVarImagePicker(idx); });
        $wrap.find('.var-image-remove').off('click').on('click', function (e) {
            e.stopPropagation();
            removeVarImage(idx);
        });
    }

    // Clear all images for a variation. The change is committed on product save
    // (an empty image_ids array clears the thumbnail + gallery meta server-side).
    function removeVarImage(idx) {
        state.variations[idx].images = [];
        $('#bpe-var-table-body .var-image-wrap[data-idx="' + idx + '"]').replaceWith(buildVarImageCell([], idx));
        bindVarImageCell(idx);
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
            // Re-render just this cell, then rebind its picker + remove handlers.
            $('#bpe-var-table-body .var-image-wrap[data-idx="' + idx + '"]').replaceWith(buildVarImageCell(newImages, idx));
            bindVarImageCell(idx);
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
        // Bulk Active/Inactive — flip every row's toggle and its dimmed state.
        // Empty value means "No change", so it never touches rows accidentally.
        var active = $('#bpe-bulk-active').val();
        if (active === '1' || active === '0') {
            var on = active === '1';
            $('#bpe-var-table-body tr.var-main-row').each(function () {
                var $row = $(this);
                $row.find('.var-enabled').prop('checked', on);
                $row.toggleClass('is-disabled', !on);
            });
        }
        // A bulk apply mutates the table — mark dirty so the unload guard and
        // background auto-save treat it as a real change.
        if (price || salePrice || stock !== '' || active === '1' || active === '0') { state.dirty = true; }
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
        // Only validate BrikPanel's own required inputs. ACF and other
        // metabox plugins mark required fields with data-required="1" on the
        // *wrapper div* (e.g. <div class="acf-field is-required" data-required="1">);
        // a bare [data-required] selector matched those divs, and $(div).val()
        // is undefined, so a single required ACF field made validateAll() always
        // fail — silently blocking the Update/Publish button. Scoping to :input
        // (input/select/textarea/button) excludes the wrappers; the fields'
        // native/ACF validation still runs server-side.
        $(':input[data-required]:visible').each(function () { if (!validateField($(this))) ok = false; });
        return ok;
    }

    /* Save */
    function saveProduct(status, silent) {
        if (state.saving) return;
        if (!silent && (status === 'publish' || status === 'future') && !validateAll()) { showToast(PE.i18n.fill_required || 'Please fill in the required fields', 'error'); return; }
        var name = $.trim($('#bpe-name').val());
        if (!name) { if (!silent) { showToast(PE.i18n.fill_name || 'Please fill in the product name', 'error'); validateField($('#bpe-name')); } return; }

        // ACF client-side validation. ACF marks its required/format errors only
        // through its own validator; BrikPanel's custom save never triggered it,
        // so on an explicit save ACF's *server-side* validation would fail
        // silently and drop the ENTIRE $_POST['acf'] payload — discarding every
        // ACF edit in that save while the editor still reported success. Run
        // ACF's validateForm first (over the card container that holds all ACF
        // groups + the #acf-form-data nonce block); on failure it renders the
        // inline field errors natively and we abort with a toast. Skipped for
        // silent autosaves and when ACF is absent. The `acfValidated` flag guards
        // the one-level re-entry after validation succeeds.
        if (!silent && !state.acfValidated && window.acf && typeof acf.validateForm === 'function') {
            var $acfForm = $('.brikpanel-pe-content');
            if ($acfForm.find('.acf-field').length) {
                acf.validateForm({
                    form: $acfForm,
                    reset: true,
                    success: function () {
                        state.acfValidated = true;
                        saveProduct(status, silent);
                        state.acfValidated = false;
                    },
                    failure: function () {
                        showToast(PE.i18n.fill_required || 'Please fill in the required fields', 'error');
                    }
                });
                return;
            }
        }

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

        // Permalink slug — opt-in section. Only sent when its card is rendered so
        // a disabled section never rewrites the stored slug. An empty value tells
        // the server to let WordPress regenerate it from the product name.
        var $slugField = $('#bpe-slug');
        if ($slugField.length) data.slug = $slugField.val();

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

        // Scheduled publishing — send the chosen datetime-local value only when
        // the "Scheduled" status is selected. The server treats an empty or past
        // date as "publish now". Only relevant when the schedule UI is rendered.
        if (status === 'future') {
            data.publish_date = $('#bpe-schedule-date').val() || '';
        }

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

        // Primary category — only sent when the field is rendered (a SEO plugin
        // with the feature is active + the category section is visible). Omitting
        // it leaves the existing value untouched on the server. The server
        // mirrors it to every active plugin's meta key.
        if ($('#bpe-seo-primary-cat').length) {
            data.primary_cat = $('#bpe-seo-primary-cat').val() || 'none';
        }

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

        // Brand IDs — only sent when the brand section is rendered. Omitting
        // the field tells the server to leave existing brand assignments
        // untouched rather than clearing them.
        if ($('#bpe-brand-list').length) {
            var brands = []; $('input[name="brand_ids[]"]:checked').each(function () { brands.push($(this).val()); });
            data.brand_ids = brands.join(',');
        }

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
            // A <select multiple> (ACF select with multiple=1, taxonomy
            // multi_select, any 3rd-party multi-select) returns an ARRAY from
            // .val() — or null when nothing is selected. Normalise null to an
            // empty array so the array-spread paths below handle it uniformly
            // instead of pushing a bare null.
            if (val === null && $el.is('select[multiple]')) val = [];
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
                // When the input is a <select multiple>, .val() is an array and
                // each selected value must become its own element of __arr.
                // Pushing the whole array (the old behaviour) made PHP receive a
                // nested array (`[['x','y']]`) — ACF then stringified it to the
                // literal "Array", silently destroying multi-select values.
                if (Array.isArray(val)) {
                    for (var ai = 0; ai < val.length; ai++) cursor.__arr.push(val[ai]);
                } else {
                    cursor.__arr.push(val);
                }
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
                // An empty __arr with no sibling keys means a <select multiple>
                // was emptied (all options deselected). ACF submits a bare
                // hidden input for this case so it can clear the value; emit an
                // empty string under the base prefix to reproduce that, else the
                // field is simply absent from the POST and ACF keeps the old
                // value — making it impossible to clear a multi-select.
                if (val.__arr.length === 0 && Object.keys(val).length === 1) {
                    target.push([prefix, '']);
                    return;
                }
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

        // Send the non-variation (spec) attributes whenever the card is on the
        // page. It is shown for both simple and variable products now, so its
        // contents (descriptive/tag attributes like "Occasion") are always
        // posted; the server merges them with any variation attributes. If the
        // card markup is absent entirely we omit the key, which tells the
        // server "section not posted — leave existing attributes alone".
        if ($('#bpe-attr-list').length) {
            // Pass the *effective* variable mode: when the toggle is on but no
            // variations exist the save falls back to a simple product, so the
            // variation-flagged rows must be kept as specs (isVar is false here).
            data.non_variation_attributes = JSON.stringify(collectNonVariationAttributes(isVar));
        }

        if (isVar) {
            // Variation attributes come straight from the unified editor's
            // "use for variations" rows so the latest toggles/edits are sent.
            // Attach each axis's Default Form Values selection (display name,
            // '' = none) so the server can persist _default_attributes.
            var varAttrs = collectVariationAttributes();
            varAttrs.forEach(function (a) {
                a['default'] = state.defaultAttributes[attrAxisKey(a)] || '';
            });
            data.attributes = JSON.stringify(varAttrs);
            var tv = [];
            // Iterate only the main rows — extras rows sit between them and
            // would otherwise shift the idx → state.variations mapping.
            $('#bpe-var-table-body tr.var-main-row').each(function (idx) {
                var v = state.variations[idx]; if (!v) return;
                var $mainRow = $(this);
                var varManageStock = $mainRow.find('.var-manage').is(':checked');
                var varStockStatus = $mainRow.find('.var-stock-status').val() || 'instock';
                var varObj = { id: v.id || 0, attributes: v.attributes,
                    enabled: $mainRow.find('.var-enabled').is(':checked') ? 1 : 0,
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
                // Surface non-fatal save warnings (e.g. a duplicate SKU/GTIN
                // that WooCommerce rejected). The product saved, but these
                // values did not \u2014 show each as a longer-lived error toast so
                // the merchant knows to pick a unique value instead of assuming
                // everything persisted. Messages are already localized server-side.
                if (r.data.warnings && r.data.warnings.length) {
                    r.data.warnings.forEach(function (w, i) {
                        setTimeout(function () { showToast(w, 'error', 7000); }, 250 * (i + 1));
                    });
                }
                // Refresh SureRank's SEO analysis so the checks reflect the
                // just-saved title/description/keyword/content. Deferred so it
                // reads the product id this handler may set just below.
                if (typeof PE._surerankAnalyze === 'function') { setTimeout(PE._surerankAnalyze, 0); }
                // Adopt the freshly-saved variation IDs + per-variation 3rd-party
                // fields so the "More fields" expander appears for variations that
                // were just created (new product, or rows added this session)
                // without forcing a full page reload.
                if (r.data.variations) { adoptSavedVariations(r.data.variations, r.data.variation_extras || {}, silent); }
                if (r.data.product_id) {
                    $('#bpe-product-id').val(r.data.product_id);
                    // Reflect the final slug (WP may have de-duplicated it or
                    // generated it from the title when left blank) so the field
                    // shows the real permalink without a reload.
                    if (typeof r.data.slug !== 'undefined') { $('#bpe-slug').val(r.data.slug); }
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
            // See the attribute-term handler above: attr(), not data(), so a
            // numeric tag name stays a string instead of being coerced to Number.
            var v = String($(this).attr('data-value') || '');
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
        var needle = String(name).toLowerCase();
        return state.tags.some(function (t) { return String(t).toLowerCase() === needle; });
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
        // Hydrate the unified attribute editor. Variation attributes load first
        // (flagged "use for variations"), then the plain specs. Runs for simple
        // AND variable products.
        var $aList = $('#bpe-attr-list');
        if ($aList.length) {
            if (productData.is_variable && productData.attributes && productData.attributes.length) {
                productData.attributes.forEach(function (attr) {
                    $aList.append(createAttrRow(attr.name, attr.values || [], attr.taxonomy || '', true));
                });
            }
            if (productData.non_variation_attributes && productData.non_variation_attributes.length) {
                productData.non_variation_attributes.forEach(function (attr) {
                    $aList.append(createAttrRow(attr.name, attr.values || [], attr.taxonomy || '', false));
                });
            }
        }
        if (productData.is_variable && productData.attributes && productData.attributes.length) {
            state.varAttributes = productData.attributes;
            // Seed the Default Form Values selection map from each variation
            // attribute's stored default (a display name resolved server-side).
            state.defaultAttributes = {};
            productData.attributes.forEach(function (a) {
                if (a && a['default']) {
                    state.defaultAttributes[attrAxisKey({ name: a.name, taxonomy: a.taxonomy })] = a['default'];
                }
            });
            state.variations = productData.variations || [];
            state.variations.forEach(function (v) {
                var p = []; Object.keys(v.attributes).forEach(function (k) { p.push(v.attributes[k]); });
                v.name = p.join(' - ');
            });
            if (state.variations.length) { renderVarTable(); }
        }
        // Reflect the loaded product type: the toggle is pre-checked by PHP for
        // variable products. Running the sync now wires up the builder, table
        // visibility, per-row switches and parent-pricing visibility to match.
        $('#bpe-var-toggle').trigger('change');
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

    /* SureRank "Analyze" panel. SureRank's own analysis lives in a React popup
       BrikPanel routes around via the unified SEO fields, so we render its
       checks server-side inside the SEO card. The Re-analyze button and a
       post-save refresh re-fetch the panel so results track the latest saved
       title, description, focus keyword and content. */
    function initSurerankAnalyze() {
        var $panel = $('#bpe-surerank-analysis');
        if (!$panel.length) return;

        var $btn  = $('#bpe-surerank-rerun');
        var $body = $('#bpe-surerank-body');
        var xhr = null, timer = null;

        // Send the current (possibly unsaved) field values + description so
        // the server can analyze exactly what's on screen — no save needed.
        function payload() {
            return {
                action: 'brikpanel_pe_surerank_analyze',
                security: PE.nonce,
                product_id: $('#bpe-product-id').val() || 0,
                seo_title: $('#bpe-seo-title').val() || '',
                seo_description: $('#bpe-seo-desc').val() || '',
                seo_focus_kw: $('#bpe-seo-focus-kw').val() || '',
                seo_canonical: $('#bpe-seo-canonical').val() || '',
                content: (typeof getEditorHtml === 'function' ? (getEditorHtml('bpe-description') || '') : '')
            };
        }

        function analyze(silent) {
            var data = payload();
            if (!data.product_id) return;
            if (xhr) { try { xhr.abort(); } catch (e) {} }
            if (!silent) { $btn.prop('disabled', true).text(PE.i18n.analyzing || 'Analyzing...'); }
            $panel.addClass('is-analyzing');
            xhr = $.post(PE.ajax_url, data).done(function (r) {
                if (r && r.success && typeof r.data.html === 'string' && r.data.html !== '') {
                    $body.html(r.data.html);
                }
            }).always(function () {
                xhr = null;
                $panel.removeClass('is-analyzing');
                if (!silent) { $btn.prop('disabled', false).text(PE.i18n.analyze || 'Re-analyze'); }
            });
        }

        function scheduleLive() {
            clearTimeout(timer);
            timer = setTimeout(function () { analyze(true); }, 800);
        }

        // Manual re-analyze button.
        $btn.on('click', function () { analyze(false); });

        // Live, debounced updates as the user edits the SEO fields or the
        // product description — the checks track typing without a save.
        $(document).on('input change',
            '#bpe-seo-title, #bpe-seo-desc, #bpe-seo-focus-kw, #bpe-seo-canonical',
            scheduleLive);
        $(document).on('input change keyup paste',
            '#bpe-description, #bpe-description-source',
            scheduleLive);

        // Expose for the save-success handler to refresh after a save.
        PE._surerankAnalyze = function () { analyze(true); };
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
    function showToast(msg, type, duration) {
        var $c = $('#bpe-toast-container');
        if (!$c.length) { $c = $('<div id="bpe-toast-container" class="bpe-toast-container">'); $('body').append($c); }
        var $t = $('<div class="bpe-toast bpe-toast-' + type + '">' + esc(msg) + '</div>');
        $c.append($t);
        requestAnimationFrame(function () { $t.addClass('show'); });
        setTimeout(function () { $t.removeClass('show'); setTimeout(function () { $t.remove(); }, 300); }, duration || 3500);
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
