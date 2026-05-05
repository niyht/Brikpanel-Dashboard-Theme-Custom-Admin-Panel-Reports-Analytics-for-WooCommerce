/**
 * BrikPanel – Simplified Product Editor
 * @package BrikPanel
 * @since 1.6.0
 */
(function ($) {
    'use strict';

    var PE = brikpanelPE || {};
    var productData = window.brikpanelProductData || {};

    var state = { images: [], saving: false, dirty: false, varTemplate: null, varAttributes: [], variations: [], downloads: [], tags: [] };

    function init() {
        bindEvents();
        initStatusDropdown();
        initCatalogVisibility();
        initToggles();
        initImages();
        initCharCounter();
        initPriceInputs();
        initCategorySearch();
        initEditor();
        initSeoPreview();
        initAutoSave();
        initInlineEdit();
        initTags();
        initSaleDates();
        loadExistingData();
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
        $('#bpe-var-back-2').on('click', function () { showVarStep(2); });
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
        function syncVariableMode() {
            var isVar = $('#bpe-var-toggle').is(':checked');
            $('#bpe-pricing-card, #bpe-inventory-card').toggle(!isVar);
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

        // When digital is on, hide shipping (no physical shipping)
        $('#bpe-digital-toggle').on('change', function () {
            var on = this.checked;
            $('#bpe-weight-card, #bpe-dims-card').toggle(!on);
        });
        // Initial state
        if ($('#bpe-digital-toggle').is(':checked')) {
            $('#bpe-weight-card, #bpe-dims-card').hide();
        }

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

    function initEditor() {
        $('.brikpanel-pe-editor-toolbar button').on('click', function (e) {
            e.preventDefault();
            var cmd = $(this).data('cmd');
            var $field = $(this).closest('[data-editor-field]');
            var $editor = $field.find('.brikpanel-pe-editor');
            var $source = $field.find('.brikpanel-pe-editor-source');

            if (cmd === 'html') {
                var isSource = !$source.prop('hidden');
                if (isSource) {
                    // Switch back to visual
                    $editor.html($source.val());
                    $source.prop('hidden', true);
                    $editor.prop('hidden', false);
                    $(this).removeClass('is-active');
                    $field.find('.brikpanel-pe-editor-toolbar button').not(this).prop('disabled', false);
                    $editor.focus();
                } else {
                    // Switch to HTML source
                    $source.val($editor.html());
                    $editor.prop('hidden', true);
                    $source.prop('hidden', false);
                    $(this).addClass('is-active');
                    $field.find('.brikpanel-pe-editor-toolbar button').not(this).prop('disabled', true);
                    $source.focus();
                }
                state.dirty = true;
                return;
            }

            // Regular formatting commands operate on visual editor only.
            if (!$source.prop('hidden')) return;
            document.execCommand(cmd, false, null);
            $editor.focus();
        });

        // Keep textarea value live so autosave/submit always reads fresh HTML.
        $(document).on('input', '.brikpanel-pe-editor-source', function () {
            state.dirty = true;
        });
    }

    /* ====== Variation Wizard ====== */
    function selectTemplate(template) {
        state.varTemplate = template;
        $('.brikpanel-pe-var-template').removeClass('active');
        $('.brikpanel-pe-var-template[data-template="' + template + '"]').addClass('active');
        var $attrs = $('#bpe-var-attributes').empty();

        if (template === 'size-color') {
            $attrs.append(createTagGroup(PE.i18n.size || 'Size', ['S', 'M', 'L', 'XL', 'XXL']));
            $attrs.append(createTagGroup(PE.i18n.color || 'Color', []));
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

    function showVarStep(s) { $('.brikpanel-pe-var-step').hide(); $('.brikpanel-pe-var-step[data-step="' + s + '"]').show(); }

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
                sale_from: ex ? (ex.sale_from || '') : '',
                sale_to:   ex ? (ex.sale_to   || '') : '',
                sku: ex ? ex.sku : sp.filter(Boolean).join('-').toUpperCase(),
                images: ex && ex.images ? ex.images : [] };
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
        if (!productData.variations || !productData.variations.length) return null;
        return productData.variations.find(function (v) {
            return Object.keys(combo).every(function (k) { return (v.attributes[k] || '').toLowerCase() === (combo[k] || '').toLowerCase(); });
        }) || null;
    }

    function renderVarTable() {
        var $tb = $('#bpe-var-table-body').empty(), sep = PE.decimal_sep || ',';
        var hasCogs = productData.cogs_enabled || false;
        var extras = productData.variation_extras || {};
        // colspan for the extras row — main row has 9 base cols + optional cogs + image
        var baseCols = 9 + (hasCogs ? 1 : 0) + 1; // +1 expander toggle cell
        state.variations.forEach(function (v, idx) {
            var pv = v.regular_price ? ('' + v.regular_price).replace('.', sep) : '';
            var sv = v.sale_price ? ('' + v.sale_price).replace('.', sep) : '';
            var stk = v.stock_quantity !== '' && v.stock_quantity !== null ? v.stock_quantity : '';
            var cogsv = hasCogs && v.cogs_value ? ('' + v.cogs_value).replace('.', sep) : '';
            var varStatus = v.stock_status || 'instock';
            var imgCellHtml = buildVarImageCell(v.images, idx);
            var cogsTd = hasCogs ? '<td><input type="text" class="var-cogs" value="' + esc(cogsv) + '" data-price="1" placeholder="0' + sep + '00"></td>' : '';
            var statusTd = '<td><select class="var-stock-status">' +
                '<option value="instock"' + (varStatus === 'instock' ? ' selected' : '') + '>' + (PE.i18n.in_stock || 'In stock') + '</option>' +
                '<option value="outofstock"' + (varStatus === 'outofstock' ? ' selected' : '') + '>' + (PE.i18n.out_of_stock || 'Out of stock') + '</option>' +
                '<option value="onbackorder"' + (varStatus === 'onbackorder' ? ' selected' : '') + '>' + (PE.i18n.on_backorder || 'On backorder') + '</option>' +
                '</select></td>';
            var hasExtra = v.id && extras[v.id];
            var expanderTd = hasExtra
                ? '<td class="var-expand-cell"><button type="button" class="var-expand-btn" data-idx="' + idx + '" aria-label="' + esc(PE.i18n.more_fields || 'More fields') + '"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg></button></td>'
                : '<td class="var-expand-cell"></td>';
            $tb.append('<tr data-idx="' + idx + '" class="var-main-row' + (hasExtra ? ' has-extra' : '') + '">' +
                expanderTd +
                '<td class="var-name">' + esc(v.name) + '</td>' +
                '<td><input type="text" class="var-price" value="' + esc(pv) + '" data-price="1" placeholder="0' + sep + '00"></td>' +
                '<td><input type="text" class="var-sale-price" value="' + esc(sv) + '" data-price="1" placeholder="0' + sep + '00"></td>' +
                '<td><input type="text" class="var-sale-from" value="' + esc(v.sale_from || '') + '" placeholder="YYYY-MM-DD" autocomplete="off"></td>' +
                '<td><input type="text" class="var-sale-to"   value="' + esc(v.sale_to   || '') + '" placeholder="YYYY-MM-DD" autocomplete="off"></td>' +
                '<td><input type="number" class="var-stock" value="' + esc('' + stk) + '" min="0" placeholder="0"></td>' +
                statusTd +
                cogsTd +
                '<td><input type="text" class="var-sku" value="' + esc(v.sku) + '"></td>' +
                '<td>' + imgCellHtml + '</td></tr>');
            if (hasExtra) {
                $tb.append('<tr class="var-extras-row" data-idx="' + idx + '" data-variation-id="' + v.id + '" hidden>' +
                    '<td colspan="' + baseCols + '" class="var-extras-cell">' +
                    '<div class="brikpanel-pe-var-extras">' + extras[v.id] + '</div>' +
                    '</td></tr>');
            }
        });
        $tb.find('.var-image-btn').on('click', function () { openVarImagePicker($(this).data('idx')); });
        $tb.find('.var-expand-btn').on('click', function () {
            var idx = $(this).data('idx');
            var $row = $tb.find('.var-extras-row[data-idx="' + idx + '"]');
            var open = $row.is('[hidden]');
            if (open) { $row.removeAttr('hidden'); $(this).addClass('open'); }
            else      { $row.attr('hidden', 'hidden'); $(this).removeClass('open'); }
        });

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
        var frame = wp.media({
            title: PE.i18n.select_images || 'Select images',
            multiple: true,
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
        if (stock !== '') $('#bpe-var-table-body .var-stock').val(stock);
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
            sku: $('#bpe-sku').val(), is_variable: isVar ? 1 : 0 };

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

        if (!isVar) {
            data.regular_price = parsePrice($('#bpe-price').val(), sep);
            // Sale fields are always visible now — the server treats empty
            // sale price as "no sale" and clears the scheduled dates.
            data.sale_price = parsePrice($('#bpe-sale-price').val(), sep);
            data.sale_from  = $('#bpe-sale-from').val() || '';
            data.sale_to    = $('#bpe-sale-to').val()   || '';
        }
        data.stock_quantity = $('#bpe-stock').val();
        data.stock_status = $('#bpe-stock-status').val() || 'instock';
        data.cogs_value = parsePrice($('#bpe-cogs').val() || '', sep);
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

        // Digital / downloads
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
        $('.brikpanel-pe-metaboxes-wrap :input[name], .brikpanel-pe-wc-fields :input[name], .brikpanel-pe-var-extras :input[name]').each(function () {
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

        if (isVar) {
            data.attributes = JSON.stringify(state.varAttributes);
            var tv = [];
            // Iterate only the main rows — extras rows sit between them and
            // would otherwise shift the idx → state.variations mapping.
            $('#bpe-var-table-body tr.var-main-row').each(function (idx) {
                var v = state.variations[idx]; if (!v) return;
                var varObj = { id: v.id || 0, attributes: v.attributes,
                    regular_price: parsePrice($(this).find('.var-price').val(), sep),
                    sale_price: parsePrice($(this).find('.var-sale-price').val(), sep),
                    sale_from: $(this).find('.var-sale-from').val() || '',
                    sale_to:   $(this).find('.var-sale-to').val()   || '',
                    stock_quantity: $(this).find('.var-stock').val(),
                    stock_status: $(this).find('.var-stock-status').val() || 'instock',
                    sku: $(this).find('.var-sku').val(),
                    image_ids: (v.images || []).map(function(img) { return img.id; }) };
                var $cogsInput = $(this).find('.var-cogs');
                if ($cogsInput.length) varObj.cogs_value = parsePrice($cogsInput.val(), sep);
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
        if (productData.is_downloadable) {
            $('#bpe-weight-card, #bpe-dims-card').hide();
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
