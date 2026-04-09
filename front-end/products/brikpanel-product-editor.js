/**
 * BrikPanel – Simplified Product Editor
 * @package BrikPanel
 * @since 1.6.0
 */
(function ($) {
    'use strict';

    var PE = brikpanelPE || {};
    var productData = window.brikpanelProductData || {};

    var state = { images: [], saving: false, dirty: false, varTemplate: null, varAttributes: [], variations: [] };

    function init() {
        bindEvents();
        initToggles();
        initImages();
        initCharCounter();
        initPriceInputs();
        initCategorySearch();
        initEditor();
        initSeoPreview();
        initAutoSave();
        initInlineEdit();
        loadExistingData();
    }

    function bindEvents() {
        $('#bpe-save-draft').on('click', function () { saveProduct('draft'); });
        $('#bpe-publish').on('click', function () { saveProduct('publish'); });

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

        // Duplicate
        $('#bpe-duplicate').on('click', duplicateProduct);

        $('[data-required]').on('blur', function () { validateField($(this)); });

        // Ctrl+S shortcut
        $(document).on('keydown', function (e) {
            if ((e.ctrlKey || e.metaKey) && e.key === 's') {
                e.preventDefault();
                var status = $('#bpe-product-id').val() && $('#bpe-publish').text() === (PE.i18n.update || 'Update') ? 'publish' : 'draft';
                saveProduct(status);
            }
        });

        // Track dirty state
        $(document).on('input change', '.brikpanel-pe-content input, .brikpanel-pe-content textarea, .brikpanel-pe-content select, .brikpanel-pe-content [contenteditable]', function () {
            state.dirty = true;
        });

        // Beforeunload warning
        $(window).on('beforeunload', function () {
            if (state.dirty) return true;
        });
    }

    /* Toggles */
    function initToggles() {
        bindToggle('#bpe-sale-toggle', '#bpe-sale-section');
        bindToggle('#bpe-weight-toggle', '#bpe-weight-section');
        bindToggle('#bpe-dims-toggle', '#bpe-dims-section');
        bindToggle('#bpe-seo-toggle', '#bpe-seo-section');
        bindToggle('#bpe-var-toggle', '#bpe-var-section');
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

    function initCharCounter() { $('#bpe-short-desc').on('input', function () { $('#bpe-short-desc-count').text(this.value.length); }); }

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
        var name = $.trim($('#bpe-new-cat-name').val());
        var parent = parseInt($('#bpe-new-cat-parent').val(), 10) || 0;
        if (!name) return;
        $.post(PE.ajax_url, { action: 'brikpanel_add_category', security: PE.nonce, name: name, parent: parent }, function (r) {
            if (r.success) {
                var d = r.data, $li = $('<li data-name="' + esc(d.name.toLowerCase()) + '">');
                $li.append('<label><input type="checkbox" name="category_ids[]" value="' + d.term_id + '" checked> ' + esc(d.name) + '</label>');
                if (d.parent > 0) {
                    var $p = $('.brikpanel-pe-cat-tree li').filter(function () { return $(this).find('> label input').val() == d.parent; });
                    if ($p.length) { var $sub = $p.find('> ul.brikpanel-pe-cat-children'); if (!$sub.length) { $sub = $('<ul class="brikpanel-pe-cat-children">'); $p.append($sub); } $sub.append($li); }
                    else $('.brikpanel-pe-cat-tree').append($li);
                } else $('.brikpanel-pe-cat-tree').append($li);
                $('#bpe-new-cat-parent').append('<option value="' + d.term_id + '">' + esc(d.name) + '</option>');
                $('#bpe-new-cat-name').val('');
                showToast(PE.i18n.category_added || 'Category added', 'success');
            } else showToast(r.data.message || 'Error', 'error');
        });
    }

    function initEditor() {
        $('.brikpanel-pe-editor-toolbar button').on('click', function (e) {
            e.preventDefault(); document.execCommand($(this).data('cmd'), false, null); $('#bpe-description').focus();
        });
    }

    /* ====== Variation Wizard ====== */
    function selectTemplate(template) {
        state.varTemplate = template;
        $('.brikpanel-pe-var-template').removeClass('active');
        $('.brikpanel-pe-var-template[data-template="' + template + '"]').addClass('active');
        var $attrs = $('#bpe-var-attributes').empty();

        if (template === 'size-color') {
            $attrs.append(createTagGroup('Size', ['S', 'M', 'L', 'XL', 'XXL']));
            $attrs.append(createTagGroup('Color', []));
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
                $select.append('<option value="' + esc(a.name) + '" data-terms=\'' + JSON.stringify(a.terms) + '\'>' + esc(a.name) + '</option>');
            });
            $select.on('change', function () {
                var name = this.value;
                if (!name) return;
                var rawTerms = $(this).find(':selected').data('terms');
                var terms = Array.isArray(rawTerms) ? rawTerms : [];
                if (typeof rawTerms === 'string') { try { terms = JSON.parse(rawTerms); } catch (e) { terms = []; } }
                // Prevent duplicate
                if ($('#bpe-custom-attrs-list .brikpanel-pe-tag-group[data-attr-name="' + name + '"]').length) { this.value = ''; return; }
                $('#bpe-custom-attrs-list').append(createTagGroup(name, terms));
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

    function createTagGroup(name, defaults) {
        var $group = $('<div class="brikpanel-pe-tag-group" data-attr-name="' + esc(name) + '">');
        $group.append('<label>' + esc(name) + '</label>');
        var $wrap = $('<div class="brikpanel-pe-tag-input-wrap">');
        var $input = $('<input type="text" placeholder="' + (PE.i18n.type_enter || 'Type and press Enter...') + '">');
        defaults.forEach(function (v) { $wrap.append(createTag(v)); });
        $input.on('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); var v = $.trim(this.value); if (v && !tagExists($wrap, v)) { $input.before(createTag(v)); this.value = ''; } }
            if (e.key === 'Backspace' && !this.value) $wrap.find('.brikpanel-pe-tag:last').remove();
        });
        $wrap.append($input);
        $wrap.on('click', function () { $input.focus(); });
        $group.append($wrap);
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
        var a = [];
        $('.brikpanel-pe-tag-group').each(function () {
            var name = $(this).data('attr-name'), vals = [];
            $(this).find('.brikpanel-pe-tag').each(function () { vals.push($(this).clone().children().remove().end().text().trim()); });
            if (name && vals.length) a.push({ name: name, values: vals });
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
                sku: ex ? ex.sku : sp.filter(Boolean).join('-').toUpperCase(),
                images: ex && ex.images ? ex.images : [] };
        });
        renderVarTable();
        showVarStep(3);
    }

    function genCombinations(attrs) {
        return attrs.reduce(function (combos, attr) {
            var slug = slugify(attr.name);
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
        state.variations.forEach(function (v, idx) {
            var pv = v.regular_price ? ('' + v.regular_price).replace('.', sep) : '';
            var sv = v.sale_price ? ('' + v.sale_price).replace('.', sep) : '';
            var stk = v.stock_quantity !== '' && v.stock_quantity !== null ? v.stock_quantity : '';
            var imgCount = v.images ? v.images.length : 0;
            var imgCellHtml = buildVarImageCell(v.images, idx);
            $tb.append('<tr data-idx="' + idx + '">' +
                '<td class="var-name">' + esc(v.name) + '</td>' +
                '<td><input type="text" class="var-price" value="' + esc(pv) + '" data-price="1" placeholder="0' + sep + '00"></td>' +
                '<td><input type="text" class="var-sale-price" value="' + esc(sv) + '" data-price="1" placeholder="0' + sep + '00"></td>' +
                '<td><input type="number" class="var-stock" value="' + esc('' + stk) + '" min="0" placeholder="0"></td>' +
                '<td><input type="text" class="var-sku" value="' + esc(v.sku) + '"></td>' +
                '<td>' + imgCellHtml + '</td></tr>');
        });
        $tb.find('.var-image-btn').on('click', function () { openVarImagePicker($(this).data('idx')); });
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

        // Pre-select existing images
        frame.on('open', function () {
            var selection = frame.state().get('selection');
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
        var stock = $.trim($('#bpe-bulk-stock').val());
        if (price) $('#bpe-var-table-body .var-price').val(price);
        if (stock !== '') $('#bpe-var-table-body .var-stock').val(stock);
    }

    /* Validation */
    function validateField($i) {
        var v = $.trim($i.val()), $e = $i.closest('.brikpanel-pe-field').find('.brikpanel-pe-field-error');
        if ($i.data('required') && !v) { $i.addClass('has-error'); $e.text(PE.i18n.field_required || 'This field is required'); return false; }
        $i.removeClass('has-error'); $e.text(''); return true;
    }
    function validateAll() { var ok = true; $('[data-required]').each(function () { if (!validateField($(this))) ok = false; }); return ok; }

    /* Save */
    function saveProduct(status, silent) {
        if (state.saving) return;
        if (!silent && status === 'publish' && !validateAll()) { showToast(PE.i18n.fill_required || 'Please fill in the required fields', 'error'); return; }
        var name = $.trim($('#bpe-name').val());
        if (!name) { if (!silent) { showToast(PE.i18n.fill_name || 'Please fill in the product name', 'error'); validateField($('#bpe-name')); } return; }

        state.saving = true;
        var $pub = $('#bpe-publish'), $draft = $('#bpe-save-draft'), op = $pub.text(), od = $draft.text();
        $pub.prop('disabled', true).text(PE.i18n.saving || 'Saving...'); $draft.prop('disabled', true);

        var isVar = $('#bpe-var-toggle').is(':checked') && state.variations.length > 0;
        var sep = PE.decimal_sep || ',';
        var data = { action: 'brikpanel_save_product', security: PE.nonce,
            product_id: $('#bpe-product-id').val() || 0, status: status, name: name,
            short_description: $('#bpe-short-desc').val(), description: $('#bpe-description').html(),
            sku: $('#bpe-sku').val(), is_variable: isVar ? 1 : 0 };

        if (!isVar) {
            data.regular_price = parsePrice($('#bpe-price').val(), sep);
            data.sale_price = $('#bpe-sale-toggle').is(':checked') ? parsePrice($('#bpe-sale-price').val(), sep) : '';
        }
        data.stock_quantity = $('#bpe-stock').val();
        data.weight = $('#bpe-weight-toggle').is(':checked') ? parsePrice($('#bpe-weight').val(), sep) : '';

        // Dimensions
        if ($('#bpe-dims-toggle').is(':checked')) {
            data.length = parsePrice($('#bpe-length').val(), sep);
            data.width = parsePrice($('#bpe-width').val(), sep);
            data.height = parsePrice($('#bpe-height').val(), sep);
        } else { data.length = ''; data.width = ''; data.height = ''; }

        // SEO
        data.seo_title = $('#bpe-seo-title').val() || '';
        data.seo_description = $('#bpe-seo-desc').val() || '';

        if (state.images.length) { data.image_id = state.images[0].id; data.gallery_ids = state.images.slice(1).map(function (i) { return i.id; }).join(','); }
        else { data.image_id = 0; data.gallery_ids = ''; }

        var cats = []; $('input[name="category_ids[]"]:checked').each(function () { cats.push($(this).val()); });
        data.category_ids = cats.join(',');

        if (isVar) {
            data.attributes = JSON.stringify(state.varAttributes);
            var tv = [];
            $('#bpe-var-table-body tr').each(function (idx) {
                var v = state.variations[idx]; if (!v) return;
                tv.push({ id: v.id || 0, attributes: v.attributes,
                    regular_price: parsePrice($(this).find('.var-price').val(), sep),
                    sale_price: parsePrice($(this).find('.var-sale-price').val(), sep),
                    stock_quantity: $(this).find('.var-stock').val(), sku: $(this).find('.var-sku').val(),
                    image_ids: (v.images || []).map(function(img) { return img.id; }) });
            });
            data.variations = JSON.stringify(tv);
        }

        $.post(PE.ajax_url, data, function (r) {
            state.saving = false; $pub.prop('disabled', false).text(op); $draft.prop('disabled', false).text(od);
            if (r.success) {
                state.dirty = false;
                showToast(r.data.message + ' \u2713', 'success');
                if (r.data.product_id) {
                    $('#bpe-product-id').val(r.data.product_id);
                    var newUrl = PE.admin_url + 'admin.php?page=brikpanel-product-editor&product_id=' + r.data.product_id;
                    window.history.replaceState(null, '', newUrl);
                    if (status === 'publish') {
                        $pub.text(PE.i18n.update || 'Update');
                        // Show View Product button if not already visible
                        if (!$('#bpe-view-product').length) {
                            var viewUrl = r.data.permalink || '';
                            if (!viewUrl) viewUrl = '/?p=' + r.data.product_id;
                            $('<a href="' + viewUrl + '" class="brikpanel-pe-btn secondary" id="bpe-view-product" target="_blank">' + (PE.i18n.view_product || 'View product') + '</a>').insertBefore($draft);
                        }
                    }
                }
            } else showToast(r.data.message || PE.i18n.error || 'An error occurred', 'error');
        }).fail(function () {
            state.saving = false; $pub.prop('disabled', false).text(op); $draft.prop('disabled', false).text(od);
            showToast(PE.i18n.error || 'An error occurred', 'error');
        });
    }

    function loadExistingData() {
        if (!productData || !productData.id) return;
        if (productData.gallery && productData.gallery.length) {
            productData.gallery.forEach(function (i) { state.images.push({ id: i.id, url: i.url }); });
            renderGallery();
        }
        if (productData.is_variable && productData.attributes && productData.attributes.length) {
            state.varAttributes = productData.attributes;
            state.variations = productData.variations || [];
            state.variations.forEach(function (v) {
                var p = []; Object.keys(v.attributes).forEach(function (k) { p.push(v.attributes[k]); });
                v.name = p.join(' - ');
            });
            var $a = $('#bpe-var-attributes').empty();
            productData.attributes.forEach(function (attr) { $a.append(createTagGroup(attr.name, attr.values)); });
            if (state.variations.length) { renderVarTable(); showVarStep(3); }
        }
    }

    /* Auto-save draft every 60s */
    function initAutoSave() {
        // Only auto-save if on the editor page
        if (!$('#bpe-product-id').length) return;
        setInterval(function () {
            if (state.dirty && !state.saving && $.trim($('#bpe-name').val())) {
                saveProduct('draft', true); // silent = true
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
            var val = this.value || $('#bpe-short-desc').val() || '';
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
