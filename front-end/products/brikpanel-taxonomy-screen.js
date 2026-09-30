/**
 * BrikPanel – product taxonomy screens (Tags, Brands, Categories, attribute
 * terms, Attributes)
 *
 * Gives these screens the orders list look: a page head with Screen Options
 * and an "Add new" button, the add form in a slide-in panel instead of a fixed
 * left column, the table in one card with a search toolbar, a bulk action bar
 * that appears when rows are ticked, and icon pagination.
 *
 * Nothing is rebuilt: WordPress' and WooCommerce's own nodes are moved or given
 * classes, so core's AJAX add (tags.js), quick edit, Screen Options and every
 * third-party field or column keep working. The add panel stays inside
 * #col-left for the same reason.
 *
 * Runs as soon as it loads (footer), before jQuery's ready handlers, so the
 * category tree script finds the toolbar already in place.
 *
 * @package BrikPanel
 */
(function ($) {
    'use strict';

    var CFG  = window.brikpanelTaxScreen || {};
    var I18N = CFG.i18n || {};
    var MODE = CFG.mode || 'terms';
    var BODY = document.body;

    var drawer = null;

    /* Each step on its own, so one failure never leaves the page half built. */
    function step(fn) {
        try {
            return fn();
        } catch (error) {
            if (window.console && window.console.error) {
                window.console.error(error);
            }
        }
        return null;
    }

    function make(tag, attrs, text) {
        var node = document.createElement(tag);
        Object.keys(attrs || {}).forEach(function (key) {
            node.setAttribute(key, attrs[key]);
        });
        if (text) {
            node.textContent = text;
        }
        return node;
    }

    function svg(paths, size) {
        var ns   = 'http://www.w3.org/2000/svg';
        var icon = document.createElementNS(ns, 'svg');
        icon.setAttribute('viewBox', '0 0 20 20');
        icon.setAttribute('width', String(size || 16));
        icon.setAttribute('height', String(size || 16));
        icon.setAttribute('fill', 'none');
        icon.setAttribute('stroke', 'currentColor');
        icon.setAttribute('stroke-width', '1.75');
        icon.setAttribute('stroke-linecap', 'round');
        icon.setAttribute('stroke-linejoin', 'round');
        icon.setAttribute('aria-hidden', 'true');
        icon.setAttribute('focusable', 'false');
        paths.forEach(function (d) {
            var path = document.createElementNS(ns, 'path');
            path.setAttribute('d', d);
            icon.appendChild(path);
        });
        return icon;
    }

    function pageWrap() {
        return document.querySelector('#wpbody-content > .wrap') || document.querySelector('#wpbody-content .wrap');
    }

    // =========================================================================
    // PAGE HEAD
    // =========================================================================

    function buildPageHead() {
        var wrap    = pageWrap();
        var heading = wrap && wrap.querySelector(':scope > h1');
        if (!heading) {
            return null;
        }

        var top   = make('div', { 'class': 'brikpanel-page-top' });
        var title = make('div', { 'class': 'brikpanel-page-title' });
        wrap.insertBefore(top, heading);

        // common.js moves notices after .wp-header-end, or after the first
        // heading when there is none (WooCommerce's attribute screens), which
        // would now put them inside the title row.
        if (!wrap.querySelector('.wp-header-end')) {
            top.insertAdjacentElement('afterend', make('hr', { 'class': 'wp-header-end' }));
        }

        if (CFG.back_url && CFG.back_label) {
            var back = make('a', {
                'class': 'brikpanel-page-back',
                href: CFG.back_url,
                'aria-label': CFG.back_label,
                title: CFG.back_label
            });
            back.appendChild(svg(['M12.5 5l-5 5 5 5']));
            title.appendChild(back);
        }

        heading.classList.add('wp-heading-inline');
        title.appendChild(heading);

        // "Search results for …" sits right after the heading.
        var subtitle = wrap.querySelector(':scope > .subtitle');
        if (subtitle) {
            title.appendChild(subtitle);
        }

        top.appendChild(title);

        var actions = make('div', { 'class': 'brikpanel-page-actions' });
        wrap.querySelectorAll(':scope > .page-title-action').forEach(function (link) {
            actions.appendChild(link);
        });

        // Screen Options joins the actions; its panel opens under the head.
        // The node keeps its id, so WordPress' own toggle keeps working.
        var links = document.getElementById('screen-meta-links');
        if (links && links.querySelector('.show-settings')) {
            actions.insertBefore(links, actions.firstChild);
            var meta = document.getElementById('screen-meta');
            if (meta) {
                top.insertAdjacentElement('afterend', meta);
            }
        }

        top.appendChild(actions);
        return actions;
    }

    // =========================================================================
    // ADD PANEL
    // =========================================================================

    function hasContainingAncestor(node) {
        var el = node.parentElement;
        while (el && el !== BODY) {
            var cs = window.getComputedStyle(el);
            if (cs.transform !== 'none' || cs.filter !== 'none' || cs.perspective !== 'none' ||
                /paint|layout|strict|content/.test(cs.contain || '') ||
                /transform|filter|perspective/.test(cs.willChange || '')) {
                return true;
            }
            el = el.parentElement;
        }
        return false;
    }

    function buildDrawer(actions) {
        var col      = document.getElementById('col-left');
        var formWrap = col && col.querySelector('.form-wrap');
        var form     = formWrap && formWrap.querySelector('form');
        var heading  = formWrap && formWrap.querySelector(':scope > h2');
        var label    = heading ? heading.textContent.trim() : '';

        // Without a title there is nothing to put on the button; the form
        // then simply stays on the page.
        if (!actions || !form || !label) {
            return null;
        }

        var button = make('button', {
            type: 'button',
            'class': 'page-title-action brikpanel-tax-add',
            'aria-controls': 'col-left',
            'aria-expanded': 'false',
            'aria-haspopup': 'dialog'
        }, label);
        actions.appendChild(button);

        if (!heading.id) {
            heading.id = 'brikpanel-tax-panel-title';
        }

        col.classList.add('brikpanel-tax-panel');
        col.setAttribute('role', 'dialog');
        col.setAttribute('aria-modal', 'true');
        col.setAttribute('aria-labelledby', heading.id);
        col.setAttribute('tabindex', '-1');

        var head  = make('div', { 'class': 'brikpanel-tax-panel-head' });
        var close = make('button', {
            type: 'button',
            'class': 'brikpanel-tax-panel-close',
            'aria-label': I18N.close || '',
            title: I18N.close || ''
        });
        close.appendChild(svg(['M5 5l10 10', 'M15 5L5 15']));
        head.appendChild(heading);
        head.appendChild(close);
        col.insertBefore(head, col.firstChild);

        var colWrap = col.querySelector('.col-wrap');
        if (colWrap) {
            colWrap.classList.add('brikpanel-tax-panel-body');

            // Core writes add errors (and its "added" notice) here; inside the
            // panel they show next to the form they belong to.
            var response = document.getElementById('ajax-response');
            if (response) {
                colWrap.insertBefore(response, colWrap.firstChild);
            }
        }

        var submitRow = form.querySelector('p.submit');
        if (submitRow && I18N.cancel) {
            submitRow.classList.add('brikpanel-tax-panel-foot');
            var cancel = make('button', { type: 'button', 'class': 'button brikpanel-tax-panel-cancel' }, I18N.cancel);
            submitRow.insertBefore(cancel, submitRow.firstChild);
            cancel.addEventListener('click', function () {
                api.close();
            });
        }

        var backdrop = make('div', { 'class': 'brikpanel-tax-backdrop', 'aria-hidden': 'true' });
        BODY.appendChild(backdrop);

        // position:fixed is relative to a transformed ancestor, not the window.
        if (hasContainingAncestor(col)) {
            BODY.appendChild(col);
        }

        var isOpen    = false;
        var returnTo  = null;
        var lockTimer = null;

        function focusables() {
            return Array.prototype.filter.call(
                col.querySelectorAll('a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'),
                function (el) {
                    return el.offsetWidth > 0 || el.offsetHeight > 0 || el === document.activeElement;
                }
            );
        }

        function firstField() {
            return form.querySelector('input[type="text"]:not([disabled]), textarea:not([disabled]), select:not([disabled])');
        }

        var api = {
            open: function (focusNode) {
                if (!isOpen) {
                    isOpen   = true;
                    returnTo = document.activeElement;
                    window.clearTimeout(lockTimer);
                    BODY.classList.add('brikpanel-tax-panel-open');
                    col.classList.add('is-open');
                    backdrop.classList.add('is-open');
                    button.setAttribute('aria-expanded', 'true');
                }

                var target = focusNode || firstField();
                window.setTimeout(function () {
                    (target || col).focus({ preventScroll: true });
                }, 40);
            },
            close: function () {
                if (!isOpen) {
                    return;
                }
                isOpen = false;
                col.classList.remove('is-open');
                backdrop.classList.remove('is-open');
                button.setAttribute('aria-expanded', 'false');
                lockTimer = window.setTimeout(function () {
                    BODY.classList.remove('brikpanel-tax-panel-open');
                }, 300);

                var back = returnTo && document.contains(returnTo) && returnTo !== BODY ? returnTo : button;
                back.focus({ preventScroll: true });
            },
            isOpen: function () {
                return isOpen;
            },
            scrollTop: function () {
                if (colWrap) {
                    colWrap.scrollTop = 0;
                }
            }
        };

        button.addEventListener('click', function () {
            api.open();
        });
        close.addEventListener('click', function () {
            api.close();
        });
        backdrop.addEventListener('click', function () {
            api.close();
        });

        // The page behind must not scroll while the panel is open.
        ['wheel', 'touchmove'].forEach(function (type) {
            backdrop.addEventListener(type, function (event) {
                event.preventDefault();
            }, { passive: false });
        });

        document.addEventListener('keydown', function (event) {
            if (!isOpen || event.defaultPrevented) {
                return;
            }

            // The media library, select2 and friends handle their own keys.
            var target = event.target;
            if (target && target.closest && target.closest('.media-modal, .select2-container, .ui-autocomplete, .wp-picker-container')) {
                return;
            }

            if (event.key === 'Escape') {
                event.preventDefault();
                api.close();
                return;
            }

            if (event.key !== 'Tab' || !col.contains(target)) {
                return;
            }

            var items = focusables();
            if (!items.length) {
                return;
            }
            var first = items[0];
            var last  = items[items.length - 1];
            if (event.shiftKey && target === first) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && target === last) {
                event.preventDefault();
                first.focus();
            }
        });

        // "Add sub-category" and other callers.
        document.addEventListener('brikpanel:tax-panel-open', function (event) {
            var detail = event.detail || {};
            api.open(detail.focus || null);
        });

        BODY.classList.add('brikpanel-tax-has-panel');
        return api;
    }

    // =========================================================================
    // TOAST
    // =========================================================================

    function toast(message, type) {
        if (!message) {
            return;
        }

        var container = document.querySelector('.brikpanel-cat-toast-container');
        if (!container) {
            container = make('div', { 'class': 'brikpanel-cat-toast-container', role: 'status', 'aria-live': 'polite' });
            BODY.appendChild(container);
        }

        // is-*, never a bare `error`: WordPress styles every div.error as an admin notice.
        var item = make('div', { 'class': 'brikpanel-cat-toast is-' + (type || 'success') }, message);
        container.appendChild(item);

        window.setTimeout(function () { item.classList.add('show'); }, 10);
        window.setTimeout(function () {
            item.classList.remove('show');
            window.setTimeout(function () { item.remove(); }, 300);
        }, 3500);
    }

    // =========================================================================
    // TERMS: ADD FEEDBACK
    // =========================================================================

    function bindTermAdd() {
        if (!drawer || !$) {
            return;
        }

        $(document).on('ajaxSuccess.bptax', function (event, xhr, settings) {
            var data = settings && typeof settings.data === 'string' ? settings.data : '';
            if (data.indexOf('action=add-tag') === -1) {
                return;
            }

            var text = (xhr && xhr.responseText) || '';

            // tags.js has already written the error into #ajax-response.
            if (text.indexOf('<wp_error') !== -1) {
                drawer.open();
                drawer.scrollTop();
                return;
            }

            var match = text.match(/<term id=['"](\d+)['"]/);

            window.setTimeout(function () {
                var response = document.getElementById('ajax-response');
                var notice   = response ? response.querySelector('.notice p, .updated p') : null;
                toast((notice && notice.textContent.trim()) || I18N.added, 'success');

                if (match) {
                    var row = document.getElementById('tag-' + match[1]);
                    if (row) {
                        row.classList.remove('brikpanel-tax-row-new');
                        void row.offsetWidth;
                        row.classList.add('brikpanel-tax-row-new');
                    }
                }

                // The panel stays open for the next one.
                if (drawer.isOpen()) {
                    var name = document.getElementById('tag-name');
                    if (name) {
                        name.focus({ preventScroll: true });
                    }
                }
            }, 40);
        });
    }

    // =========================================================================
    // TERMS: CARD, TOOLBAR, BULK BAR, PAGINATION
    // =========================================================================

    function buildTermsCard() {
        var card     = document.querySelector('#col-right .col-wrap');
        var listForm = document.getElementById('posts-filter');
        if (!card || !listForm) {
            return;
        }

        card.classList.add('brikpanel-tax-card');

        var bar   = make('div', { 'class': 'brikpanel-tax-toolbar' });
        var tools = make('div', { 'class': 'brikpanel-tax-toolbar-tools' });

        // The search form becomes the card's first row. It stays its own form
        // next to #posts-filter, never nested inside it.
        var search = document.querySelector('.wrap form.search-form');
        if (search) {
            search.classList.add('brikpanel-tax-search');
            var box = search.querySelector('.search-box');
            if (box) {
                box.insertBefore(make('span', { 'class': 'brikpanel-tax-search-icon', 'aria-hidden': 'true' }), box.firstChild);
            }
            var input = search.querySelector('#tag-search-input, input[name="s"]');
            if (input && !input.getAttribute('placeholder') && I18N.search) {
                input.setAttribute('placeholder', I18N.search);
            }
            if (box && input) {
                addSearchClear(box, input);
            }
            bar.appendChild(search);
        }

        bar.appendChild(tools);
        card.insertBefore(bar, card.firstChild);

        // The tree script may already have put its controls in the old spot.
        var treeTools = document.querySelector('.tablenav.top .brikpanel-tree-tools');
        if (treeTools) {
            tools.appendChild(treeTools);
        }

        // Notes printed after the table (WooCommerce's "Deleting a category
        // does not delete the products…", `after-{taxonomy}-table` output)
        // read as a quiet footnote under the card, not as a bare block inside
        // it.
        var trailing = [];
        var node = listForm.nextSibling;
        while (node) {
            if (node.nodeType === 1 && node.tagName !== 'SCRIPT') {
                trailing.push(node);
            }
            node = node.nextSibling;
        }
        if (trailing.length) {
            var footnote = make('div', { 'class': 'brikpanel-tax-footnote' });
            trailing.forEach(function (el) {
                footnote.appendChild(el);
            });
            card.insertAdjacentElement('afterend', footnote);
        }
    }

    /* One clear control for both searches: a server search (?s=) goes back
       to the full list, the live tree filter just empties. Replaces the
       browser's own blue "x". */
    function addSearchClear(box, input) {
        if (!I18N.clear_search) {
            return;
        }

        var params     = new URLSearchParams(window.location.search);
        var fromServer = !!params.get('s');
        var clear      = make('button', {
            type: 'button',
            'class': 'brikpanel-tax-search-clear',
            'aria-label': I18N.clear_search,
            title: I18N.clear_search
        });
        clear.appendChild(svg(['M6 6l8 8', 'M14 6l-8 8'], 14));
        input.insertAdjacentElement('afterend', clear);

        function sync() {
            clear.hidden = !input.value && !fromServer;
        }

        clear.addEventListener('click', function () {
            if (fromServer) {
                params.delete('s');
                params.delete('paged');
                window.location.search = params.toString();
                return;
            }
            input.value = '';
            input.dispatchEvent(new Event('input', { bubbles: true }));
            input.focus();
        });
        input.addEventListener('input', sync);
        sync();
    }

    /* WooCommerce moves the default category's "cannot be deleted" tip into
       the row's first <th>, meant to be the checkbox cell. Since WordPress 7
       that cell is a <td> and the first <th> is the name cell, so its script
       wipes the name, row actions and quick edit data. The script runs right
       after this one; the nodes are kept and put back the moment it has run,
       with the tip in the checkbox cell where WooCommerce wanted it. */
    function guardDefaultTermRow() {
        document.querySelectorAll('#the-list > tr').forEach(function (row) {
            var tip  = row.querySelector('td.column-thumb .woocommerce-help-tip'); // i18n-ignore: CSS selector, not user copy.
            var name = row.querySelector('th.column-name');
            var cb   = row.querySelector('.check-column');
            if (!tip || !name || !cb || cb.tagName === 'TH' || cb.querySelector('input')) {
                return;
            }

            var saved    = Array.prototype.slice.call(name.childNodes);
            var observer = new MutationObserver(function () {
                if (name.querySelector('.row-title')) {
                    return;
                }
                observer.disconnect();

                var moved = name.querySelector('.woocommerce-help-tip');
                name.textContent = '';
                saved.forEach(function (child) {
                    name.appendChild(child);
                });
                if (moved) {
                    cb.textContent = '';
                    cb.appendChild(moved);
                }
            });
            observer.observe(name, { childList: true });

            // Nothing to guard once the page is parsed.
            document.addEventListener('DOMContentLoaded', function () {
                window.setTimeout(function () {
                    observer.disconnect();
                }, 0);
            });
        });
    }

    function buildPagination() {
        document.querySelectorAll('#posts-filter .tablenav.bottom .pagination-links').forEach(function (links) {
            var names = ['double-left', 'left', 'right', 'double-right'];
            links.querySelectorAll(':scope > .button').forEach(function (button, index) {
                if (!names[index]) {
                    return;
                }
                var glyph = button.querySelector('span[aria-hidden="true"]') || button;
                if (glyph === button) {
                    button.textContent = '';
                } else {
                    glyph.textContent = '';
                }
                glyph.classList.add('brikpanel-tax-chevron', 'is-' + names[index]);
            });
        });

        var bottom = document.querySelector('#posts-filter .tablenav.bottom');
        if (bottom) {
            bottom.classList.add('brikpanel-tax-pagination');
        }
    }

    function buildBulkBar() {
        var listForm = document.getElementById('posts-filter');
        var select   = document.getElementById('bulk-action-selector-top');
        var apply    = document.getElementById('doaction');
        var card     = document.querySelector('.brikpanel-tax-card');
        if (!listForm || !select || !apply) {
            return;
        }

        var bar   = make('div', { 'class': 'brikpanel-tax-bulk', role: 'region', 'aria-label': I18N.bulk_actions || '' });
        var inner = make('div', { 'class': 'brikpanel-tax-bulk-inner' });
        var count = make('span', { 'class': 'brikpanel-bulk-count', 'aria-live': 'polite' });
        inner.appendChild(count);

        // Read from core's own dropdown, so plugin actions and translations
        // come along.
        Array.prototype.forEach.call(select.options, function (option) {
            if (option.value === '-1' || option.disabled) {
                return;
            }
            var action = make('button', { type: 'button', value: option.value }, option.textContent);
            if (option.value === 'delete' || option.value === 'trash') {
                action.classList.add('is-destructive');
            }
            action.addEventListener('click', function () {
                select.value = option.value;
                var bottom = document.getElementById('bulk-action-selector-bottom');
                if (bottom) {
                    bottom.value = option.value;
                }
                // Through jQuery, so handlers other code bound to the Apply
                // button (confirmations) still run.
                if ($) {
                    $(apply).trigger('click');
                } else {
                    apply.click();
                }
            });
            inner.appendChild(action);
        });

        bar.appendChild(inner);
        listForm.appendChild(bar);

        function update() {
            // Counted off the DOM: core's select-all and shift-click flip the
            // boxes without firing change events.
            var ticked = listForm.querySelectorAll('#the-list .check-column input[type="checkbox"]:checked').length;
            count.textContent = (I18N.selected_count || '%d').replace('%d', String(ticked));
            bar.classList.toggle('is-visible', ticked > 0);
            if (card) {
                card.classList.toggle('has-selection', ticked > 0);
            }
        }

        listForm.addEventListener('change', update);
        listForm.addEventListener('click', function (event) {
            if (event.target && event.target.closest && event.target.closest('.check-column')) {
                window.setTimeout(update, 0);
            }
        });
        if ($) {
            // Rows removed by AJAX delete or filtered by the tree search.
            $(document).on('ajaxComplete.bptax', function () {
                window.setTimeout(update, 500);
            });
        }
        update();
    }

    // =========================================================================
    // ATTRIBUTES
    // =========================================================================

    function buildAttributesTable() {
        var card  = document.querySelector('#col-right .col-wrap');
        var table = card && card.querySelector('table.attributes-table');
        if (!table) {
            return;
        }

        card.classList.add('brikpanel-tax-card');

        var keys = ['name', 'slug'];
        if (CFG.has_type) {
            keys.push('type');
        }
        keys.push('orderby', 'terms');

        var hidden = Array.isArray(CFG.hidden_columns) ? CFG.hidden_columns : [];
        var labels = [];

        var heads = table.querySelectorAll('thead tr > th, thead tr > td');
        if (heads.length !== keys.length) {
            // Another plugin changed the table; leave the columns alone.
            return;
        }

        heads.forEach(function (th, index) {
            var key = keys[index];
            labels[index] = th.textContent.trim();
            th.classList.add('manage-column', 'column-' + key);
            // common.js saves the hidden list from `.manage-column[id]`.
            if (!th.id && !document.getElementById(key)) {
                th.id = key;
            }
            if (key === 'name') {
                th.classList.add('column-primary');
            }
            if (hidden.indexOf(key) !== -1) {
                th.classList.add('hidden');
            }
        });

        table.querySelectorAll('tbody > tr').forEach(function (row) {
            var cells = row.children;
            if (cells.length === 1 && cells[0].hasAttribute('colspan')) {
                cells[0].classList.add('colspanchange');
                return;
            }
            Array.prototype.forEach.call(cells, function (cell, index) {
                var key = keys[index];
                if (!key) {
                    return;
                }
                cell.classList.add('column-' + key);
                cell.setAttribute('data-colname', labels[index] || '');
                if (hidden.indexOf(key) !== -1) {
                    cell.classList.add('hidden');
                }
                if (key === 'terms') {
                    wrapTermList(cell);
                }
            });
        });
    }

    /* The term names are a bare text node followed by <br> and the
       "Configure terms" link; wrapped, they can be clamped to two lines. */
    function wrapTermList(cell) {
        var list = make('span', { 'class': 'brikpanel-attr-terms' });
        var node = cell.firstChild;
        while (node && !(node.nodeType === 1 && (node.tagName === 'BR' || node.tagName === 'A'))) {
            var next = node.nextSibling;
            list.appendChild(node);
            node = next;
        }
        if (!list.textContent.trim()) {
            return;
        }
        list.setAttribute('title', list.textContent.trim());
        cell.insertBefore(list, cell.firstChild);
        var br = list.nextSibling;
        if (br && br.nodeType === 1 && br.tagName === 'BR') {
            br.remove();
        }
    }

    function attributeSubmitFeedback() {
        if (!CFG.submitted) {
            return;
        }

        // A reload after the POST would ask to resend the form.
        if (window.history && window.history.replaceState) {
            window.history.replaceState(null, '', window.location.href);
        }

        var error = document.getElementById('woocommerce_errors');
        if (error) {
            var body = document.querySelector('#col-left .brikpanel-tax-panel-body');
            if (body && drawer) {
                // `inline` keeps common.js from hoisting it back under the
                // page title on DOM ready.
                error.classList.add('inline');
                body.insertBefore(error, body.firstChild);
                drawer.open();
            }
            return;
        }

        toast(I18N.attribute_added, 'success');
    }

    // =========================================================================
    // EDIT SCREENS
    // =========================================================================

    function markEditCard() {
        var form = MODE === 'term-edit'
            ? document.getElementById('edittag')
            : document.querySelector('.wrap.woocommerce > form');
        if (form) {
            form.classList.add('brikpanel-tax-edit-card');
        }
        // An id on the wrap lets the card styles outrank themes that style
        // #edittag and the attribute form through id selectors.
        var wrap = pageWrap();
        if (wrap && !wrap.id) {
            wrap.id = 'brikpanel-tax-edit';
        }
    }

    // =========================================================================
    // BOOT
    // =========================================================================

    function boot() {
        var actions = step(buildPageHead);

        if (MODE === 'terms' || MODE === 'attributes') {
            drawer = step(function () { return buildDrawer(actions); });
        }

        if (MODE === 'terms') {
            step(guardDefaultTermRow);
            step(buildTermsCard);
            step(buildPagination);
            step(buildBulkBar);
            step(bindTermAdd);
        } else if (MODE === 'attributes') {
            step(buildAttributesTable);
            step(attributeSubmitFeedback);
        } else {
            step(markEditCard);
        }

        // Motion only after the first paint, so nothing animates into place.
        window.requestAnimationFrame(function () {
            BODY.classList.add('brikpanel-tax-ready');
            window.setTimeout(function () {
                BODY.classList.add('brikpanel-tax-motion');
            }, 150);
        });
    }

    boot();
})(window.jQuery);
