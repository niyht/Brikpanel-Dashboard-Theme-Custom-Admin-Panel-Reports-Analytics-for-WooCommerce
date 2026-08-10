/**
 * BrikPanel – Folder-style category tree
 *
 * WordPress renders a hierarchical taxonomy as a flat table whose only nesting
 * cue is an em-dash prefix. This turns that same table into a tree: collapsible
 * parents, indent guides, child counts, instant filtering, and drag & drop
 * re-parenting — without replacing the list table, so every WooCommerce and
 * third-party column keeps working.
 *
 * @package BrikPanel
 * @since 1.7.0
 */
(function ($) {
    'use strict';

    var CE   = window.brikpanelCE || {};
    var I18N = CE.i18n || {};

    // TREE: draw the folder tree. Off when the setting is disabled, when the
    // taxonomy is flat, or when core renders one of its own hierarchy-less
    // views (a search or a column sort).
    // FULL: every term of the taxonomy is on this page, so the search box can
    // filter live instead of reloading.
    var TREE = !!CE.tree;
    var FULL = !!CE.full_tree && TREE;

    var STORE_KEY = 'brikpanel_terms_collapsed_' + (CE.store_key || 'default');

    var nodes       = {};     // term id -> node
    var order       = [];     // nodes, in table order
    var collapsed   = {};     // term id -> 1 when its children are hidden
    var stateExists = false;  // a remembered state was found for this screen
    var filtering   = false;

    var dragId    = 0;     // term being dragged
    var dragBlock = {};    // ids that cannot receive the dragged term
    var hoverTimer = null;

    // =========================================================================
    // BOOT
    // =========================================================================

    function init() {
        if (!$('#the-list').length) {
            return;
        }

        repositionSearch();
        wrapRowActions();
        setupDescriptionTooltips();

        if (TREE) {
            collapsed = loadState();
            buildToolbar();
            bindToggles();
            bindSelectionGuard();
            buildTree();
            bindDragAndDrop();
        }

        bindRowActions();
        bindListRefresh();
    }

    // =========================================================================
    // TREE
    // =========================================================================

    /**
     * Read the rendered rows and rebuild every tree decoration from scratch.
     * Safe to call again after rows are added, edited or removed over AJAX.
     */
    function buildTree() {
        teardownTree();
        parseRows();
        order.forEach(decorateRow);

        if (!stateExists) {
            // First visit: a tree opens closed, so the top level reads as a
            // short list of folders instead of one long flat dump. Every
            // later change to that state is remembered.
            collapseAll();
        }

        pruneState();
        refreshTree();
    }

    function collapseAll() {
        collapsed = {};
        order.forEach(function (node) {
            if (node.kids.length) {
                collapsed[node.id] = 1;
            }
        });
        saveState();
    }

    function teardownTree() {
        $('#the-list').find('.brikpanel-tree-pre, .brikpanel-tree-kids').remove();
        $('#the-list').find('.brikpanel-tree-row').removeClass(
            'brikpanel-tree-row brikpanel-tree-hidden brikpanel-tree-branch brikpanel-tree-leaf'
        );
    }

    function parseRows() {
        nodes = {};
        order = [];

        $('#the-list').children('tr').each(function () {
            var $row = $(this);
            var id   = getTermId($row);

            if (!id) {
                return;
            }

            var levelMatch = ($row.attr('class') || '').match(/level-(\d+)/);
            var level      = levelMatch ? parseInt(levelMatch[1], 10) : 0;

            // WordPress prints the quick-edit payload in every row; it carries
            // the real parent id, so the hierarchy never has to be guessed
            // from the em-dash prefix (which is locale and filter dependent).
            var parent = parseInt($row.find('#inline_' + id + ' .parent').first().text(), 10);
            if (isNaN(parent)) {
                parent = 0;
            }

            var $title = $row.find('a.row-title').first();

            var node = {
                id:     id,
                level:  level,
                parent: parent,
                name:   $.trim($title.length ? $title.text() : ''),
                slug:   $.trim($row.find('td.column-slug').first().text()),
                $row:   $row,
                kids:   []
            };

            nodes[id] = node;
            order.push(node);
        });

        order.forEach(function (node) {
            var parent = nodes[node.parent];
            if (parent && parent !== node) {
                node.up = parent;
                parent.kids.push(node);
            }
        });

        breakCycles();
    }

    /**
     * Detach any term whose ancestor chain loops back on itself.
     *
     * WordPress will not create such a loop, but a botched import or a direct
     * database edit can leave one behind, and every ancestor walk in here
     * (visibility, filtering, drag validation) would then spin forever and
     * freeze the tab. A looping term is treated as a root instead.
     *
     * @return {void}
     */
    function breakCycles() {
        order.forEach(function (node) {
            var seen = {};
            var up   = node.up;

            seen[node.id] = true;

            while (up) {
                if (seen[up.id]) {
                    detachNode(node);
                    break;
                }

                seen[up.id] = true;
                up = up.up;
            }
        });
    }

    function detachNode(node) {
        if (node.up) {
            var kids  = node.up.kids;
            var index = kids.indexOf(node);

            if (index > -1) {
                kids.splice(index, 1);
            }
        }

        delete node.up;
        node.parent = 0;
    }

    /**
     * Replace the em-dash prefix with real indentation, a twisty and an icon.
     */
    function decorateRow(node) {
        var $cell = node.$row.find('.column-name').first();
        if (!$cell.length) {
            return;
        }

        var $strong = $cell.children('strong').first();
        if (!$strong.length) {
            return;
        }

        var $title = $strong.find('a.row-title').first();

        if (node.level > 0 && $title.length) {
            stripLevelPadding($title[0], node.level);
            node.name = $.trim($title.text());
        }

        var hasKids = node.kids.length > 0;

        var $pre = $('<span class="brikpanel-tree-pre"></span>');

        for (var i = 0; i < node.level; i++) {
            // The innermost step draws the elbow that ties the row to its
            // parent; the ones before it are plain spacers.
            $pre.append(
                '<span class="brikpanel-tree-indent' +
                (i === node.level - 1 ? ' is-connector' : '') +
                '" aria-hidden="true"></span>'
            );
        }

        if (hasKids) {
            $('<button type="button" class="brikpanel-tree-toggle"></button>')
                .attr('aria-expanded', 'true')
                .attr('aria-label', I18N.toggle_children || '')
                .attr('title', I18N.toggle_children || '')
                .html(svgChevron())
                .appendTo($pre);
        } else {
            $pre.append('<span class="brikpanel-tree-toggle is-leaf" aria-hidden="true"></span>');
        }

        $('<span class="brikpanel-cat-drag-handle" draggable="true"></span>')
            .attr('title', I18N.drag_to_nest || '')
            .html(svgGrip())
            .appendTo($pre);

        $('<span class="brikpanel-tree-icon" aria-hidden="true"></span>')
            .html(hasKids ? svgFolder() : svgLeaf())
            .appendTo($pre);

        $strong.prepend($pre);

        if (hasKids) {
            $('<span class="brikpanel-tree-kids"></span>')
                .attr('title', I18N.subcategories || '')
                .text(String(node.kids.length))
                .insertAfter($title.length ? $title : $pre);
        }

        node.$row
            .addClass('brikpanel-tree-row')
            .addClass(hasKids ? 'brikpanel-tree-branch' : 'brikpanel-tree-leaf');
    }

    /**
     * Remove the "— " padding WordPress prepends to a nested term's name,
     * one dash per level.
     *
     * Only the anchor's leading text node is touched. Plugins filter
     * `term_name` and can put real markup (badges, flags, language icons)
     * inside that link, and rewriting the link's text would silently delete
     * it. If the link does not start with the padding at all, nothing is
     * changed.
     *
     * @param {HTMLElement} link  The a.row-title element.
     * @param {number}      level Depth of the row, 1 = first child level.
     * @return {void}
     */
    function stripLevelPadding(link, level) {
        var node = link.firstChild;

        if (!node || 3 !== node.nodeType) {
            return;
        }

        var cleaned = node.nodeValue.replace(
            new RegExp('^[\\s\\u00a0]*(?:\\u2014[\\s\\u00a0]*){1,' + level + '}'),
            ''
        );

        if (cleaned !== node.nodeValue) {
            node.nodeValue = cleaned;
        }
    }

    /**
     * Apply the current collapsed state (or filter result) to every row.
     */
    function refreshTree() {
        if (filtering) {
            return;
        }

        order.forEach(function (node) {
            var hidden = false;
            var up     = node.up;

            while (up) {
                if (collapsed[up.id]) {
                    hidden = true;
                    break;
                }
                up = up.up;
            }

            node.$row.toggleClass('brikpanel-tree-hidden', hidden);

            if (node.kids.length) {
                var isCollapsed = !!collapsed[node.id];
                node.$row.find('.brikpanel-tree-toggle').first()
                    .attr('aria-expanded', isCollapsed ? 'false' : 'true')
                    .toggleClass('is-collapsed', isCollapsed);
                node.$row.toggleClass('brikpanel-tree-collapsed', isCollapsed);
            }
        });

        dropHiddenSelections();
        updateCounter();
    }

    // =========================================================================
    // BULK-SELECTION SAFETY
    // =========================================================================

    /**
     * A bulk action must never reach a row the user cannot see.
     *
     * WordPress' "select all" ticks every checkbox in the table body, collapsed
     * and filtered-out rows included, so a bulk delete run after a search could
     * wipe categories that were never on screen. Hidden rows are unticked right
     * after core has had its turn, and the header box is re-synced against what
     * is actually visible.
     *
     * @return {void}
     */
    function bindSelectionGuard() {
        $(document).on(
            'click.bpcat',
            '.wp-list-table thead .check-column input[type="checkbox"], .wp-list-table tfoot .check-column input[type="checkbox"]',
            function () {
                window.setTimeout(dropHiddenSelections, 0);
            }
        );
    }

    function dropHiddenSelections() {
        var changed = false;

        order.forEach(function (node) {
            if (!node.$row.hasClass('brikpanel-tree-hidden')) {
                return;
            }

            var $box = node.$row.find('.check-column input[type="checkbox"]');

            if ($box.prop('checked')) {
                $box.prop('checked', false);
                changed = true;
            }
        });

        if (changed) {
            syncSelectAll();
        }
    }

    function syncSelectAll() {
        var selectable = 0;
        var ticked     = 0;

        order.forEach(function (node) {
            if (node.$row.hasClass('brikpanel-tree-hidden')) {
                return;
            }

            // WooCommerce leaves the default category without a checkbox, and
            // other rows can be disabled; neither counts towards "all".
            var $box = node.$row.find('.check-column input[type="checkbox"]').not(':disabled');

            if (!$box.length) {
                return;
            }

            selectable++;

            if ($box.prop('checked')) {
                ticked++;
            }
        });

        $('.wp-list-table thead .check-column input[type="checkbox"], .wp-list-table tfoot .check-column input[type="checkbox"]')
            .prop('checked', selectable > 0 && ticked === selectable);
    }

    function setCollapsed(id, value) {
        if (value) {
            collapsed[id] = 1;
        } else {
            delete collapsed[id];
        }
        saveState();
    }

    function expandAncestors(node) {
        var up = node.up;
        while (up) {
            delete collapsed[up.id];
            up = up.up;
        }
    }

    // =========================================================================
    // TOOLBAR
    // =========================================================================

    function buildToolbar() {
        var $nav = $('.tablenav.top').first();
        if (!$nav.length || $nav.find('.brikpanel-tree-tools').length) {
            return;
        }

        var $tools = $('<div class="brikpanel-tree-tools"></div>');

        $('<button type="button" class="button brikpanel-tree-expand"></button>')
            .text(I18N.expand_all || '')
            .appendTo($tools);

        $('<button type="button" class="button brikpanel-tree-collapse"></button>')
            .text(I18N.collapse_all || '')
            .appendTo($tools);

        $('<span class="brikpanel-tree-counter" aria-live="polite"></span>').appendTo($tools);

        var $bulk = $nav.children('.alignleft.actions').first();
        if ($bulk.length) {
            $tools.insertAfter($bulk);
        } else {
            $nav.prepend($tools);
        }

        $tools.on('click', '.brikpanel-tree-expand', function () {
            collapsed = {};
            saveState();
            refreshTree();
        });

        $tools.on('click', '.brikpanel-tree-collapse', function () {
            collapseAll();
            refreshTree();
        });
    }

    /**
     * Twisty clicks, delegated so rebuilt rows keep working.
     */
    function bindToggles() {
        $('#the-list').on('click.bpcat', '.brikpanel-tree-toggle', function (e) {
            e.preventDefault();

            var id = getTermId($(this).closest('tr'));
            if (!id || !nodes[id]) {
                return;
            }

            setCollapsed(id, !collapsed[id]);
            refreshTree();
        });
    }

    function updateCounter() {
        var $counter = $('.brikpanel-tree-counter');
        if (!$counter.length) {
            return;
        }

        var total   = order.length;
        var visible = 0;

        order.forEach(function (node) {
            if (!node.$row.hasClass('brikpanel-tree-hidden')) {
                visible++;
            }
        });

        if (!filtering && visible === total) {
            $counter.text('');
            return;
        }

        var template = I18N.showing_x_of_y || '';
        $counter.text(
            template.replace('%1$s', String(visible)).replace('%2$s', String(total))
        );
    }

    // =========================================================================
    // INSTANT FILTER
    // =========================================================================

    /**
     * With the whole taxonomy on the page, the search box can filter live
     * instead of reloading into WordPress' flat, hierarchy-less result list.
     */
    function bindInstantFilter($input, $form) {
        if (I18N.filter_placeholder) {
            $input.attr('placeholder', I18N.filter_placeholder);
        }

        $input.on('input.bpcat search.bpcat', function () { // i18n-ignore: jQuery event namespace, not user copy.
            applyFilter($(this).val());
        });

        $form.on('submit.bpcat', function (e) {
            e.preventDefault();
            applyFilter($input.val());
        });

        if ($.trim($input.val())) {
            applyFilter($input.val());
        }
    }

    function applyFilter(term) {
        var query = $.trim(String(term || '')).toLowerCase();

        if (!query) {
            filtering = false;
            $('#the-list').removeClass('brikpanel-tree-filtering');
            setEmptyState(false);
            refreshTree();
            return;
        }

        filtering = true;
        $('#the-list').addClass('brikpanel-tree-filtering');

        var keep    = {};
        var matches = 0;

        order.forEach(function (node) {
            if (node.name.toLowerCase().indexOf(query) === -1 &&
                node.slug.toLowerCase().indexOf(query) === -1) {
                return;
            }

            keep[node.id] = true;
            matches++;

            // Keep the path to the match visible so the branch still reads
            // as a tree instead of a flat pile of matches.
            var up = node.up;
            while (up) {
                keep[up.id] = true;
                up = up.up;
            }
        });

        order.forEach(function (node) {
            node.$row.toggleClass('brikpanel-tree-hidden', !keep[node.id]);
        });

        dropHiddenSelections();
        setEmptyState(0 === matches);
        updateCounter();
    }

    function setEmptyState(show) {
        var $row = $('#the-list').children('tr.brikpanel-tree-empty');

        if (!show) {
            $row.remove();
            return;
        }

        if ($row.length) {
            return;
        }

        var columns = $('#the-list').closest('table').find('thead th, thead td').length || 1;

        $('<tr class="brikpanel-tree-empty"><td class="colspanchange"></td></tr>')
            .find('td')
            .attr('colspan', columns)
            .text(I18N.no_matches || '')
            .end()
            .appendTo($('#the-list'));
    }

    // =========================================================================
    // STATE PERSISTENCE
    // =========================================================================

    function loadState() {
        try {
            var raw = window.localStorage.getItem(STORE_KEY);
            if (null === raw) {
                return {};
            }

            stateExists = true;

            var parsed = JSON.parse(raw);
            return (parsed && typeof parsed === 'object') ? parsed : {};
        } catch (err) {
            return {};
        }
    }

    function saveState() {
        stateExists = true;

        try {
            window.localStorage.setItem(STORE_KEY, JSON.stringify(collapsed));
        } catch (err) {
            // Private browsing / quota: the tree simply resets next visit.
        }
    }

    /**
     * Drop remembered ids for terms that no longer exist or lost their
     * children, so the stored state cannot grow without bound.
     */
    function pruneState() {
        var cleaned = {};
        var changed = false;

        Object.keys(collapsed).forEach(function (id) {
            if (nodes[id] && nodes[id].kids.length) {
                cleaned[id] = 1;
            } else {
                changed = true;
            }
        });

        if (changed) {
            collapsed = cleaned;
            saveState();
        }
    }

    // =========================================================================
    // DRAG & DROP NESTING
    // =========================================================================

    function bindDragAndDrop() {
        var $list = $('#the-list');

        $list.on('dragstart.bpcat', '.brikpanel-cat-drag-handle', function (e) {
            var $row = $(this).closest('tr');
            var id   = getTermId($row);

            if (!id || !nodes[id]) {
                // Leaving a stale id behind would let the drop handler run
                // against a row the tree knows nothing about.
                dragId = 0;
                return;
            }

            dragId = id;

            // A term can never be dropped on itself or on its own descendants.
            dragBlock = {};
            dragBlock[dragId] = true;
            markDescendants(nodes[dragId], dragBlock);

            $row.addClass('brikpanel-cat-dragging');
            $('body').addClass('brikpanel-cat-dnd');

            var dt = e.originalEvent.dataTransfer;
            if (dt) {
                dt.effectAllowed = 'move';
                try {
                    dt.setData('text/plain', String(dragId));
                } catch (err) {
                    // Older browsers reject unknown MIME types; drag still works.
                }
            }
        });

        $list.on('dragend.bpcat', '.brikpanel-cat-drag-handle', endDrag);

        $list.on('dragover.bpcat dragenter.bpcat', 'tr', function (e) { // i18n-ignore: jQuery event namespace, not user copy.
            if (!dragId) {
                return;
            }

            var $row     = $(this);
            var targetId = getTermId($row);

            if (!targetId) {
                return;
            }

            e.preventDefault();
            e.stopPropagation();

            var dt = e.originalEvent.dataTransfer;

            if (dragBlock[targetId]) {
                if (dt) {
                    dt.dropEffect = 'none';
                }
                // dragover fires continuously; only touch the DOM when the row
                // under the cursor actually changed.
                if (!$row.hasClass('brikpanel-cat-drop-denied')) {
                    clearDropTargets();
                    clearAutoExpand();
                    $row.addClass('brikpanel-cat-drop-denied');
                }
                return;
            }

            if (dt) {
                dt.dropEffect = 'move';
            }

            if (!$row.hasClass('brikpanel-cat-drop-target')) {
                clearDropTargets();
                $row.addClass('brikpanel-cat-drop-target');
                scheduleAutoExpand(targetId);
            }
        });

        $list.on('dragleave.bpcat', 'tr', function () {
            $(this).removeClass('brikpanel-cat-drop-target brikpanel-cat-drop-denied');
        });

        $list.on('drop.bpcat', 'tr', function (e) {
            if (!dragId) {
                return;
            }

            e.preventDefault();
            e.stopPropagation();

            var targetId = getTermId($(this));
            var moving   = dragId;
            // endDrag() clears the blocklist, so read it while it is still set.
            var refused  = !targetId || !!dragBlock[targetId] || !nodes[targetId];

            endDrag();

            if (refused) {
                return;
            }

            var message = (I18N.confirm_nest || '')
                .replace('%1$s', nodes[moving].name)
                .replace('%2$s', nodes[targetId].name);

            if (!window.confirm(message)) {
                return;
            }

            setParent(moving, targetId);
        });

        // Drop strip that lifts a branch back to the top level.
        var $root = $(
            '<div class="brikpanel-tree-droproot"><span></span></div>'
        );
        $root.find('span').text(I18N.drop_to_top_level || '');
        $root.insertBefore($('#the-list').closest('table'));

        $root.on('dragover dragenter', function (e) {
            if (!dragId) {
                return;
            }
            e.preventDefault();
            if (e.originalEvent.dataTransfer) {
                e.originalEvent.dataTransfer.dropEffect = 'move';
            }
            $root.addClass('is-over');
        });

        $root.on('dragleave', function () {
            $root.removeClass('is-over');
        });

        $root.on('drop', function (e) {
            if (!dragId) {
                return;
            }
            e.preventDefault();

            var moving = dragId;
            endDrag();

            if (!nodes[moving] || !nodes[moving].parent) {
                return;
            }

            setParent(moving, 0);
        });
    }

    function endDrag() {
        clearAutoExpand();
        clearDropTargets();
        $('#the-list tr').removeClass('brikpanel-cat-dragging');
        $('.brikpanel-tree-droproot').removeClass('is-over');
        $('body').removeClass('brikpanel-cat-dnd');
        dragId = 0;
        dragBlock = {};
    }

    function clearDropTargets() {
        $('#the-list tr').removeClass('brikpanel-cat-drop-target brikpanel-cat-drop-denied');
    }

    /**
     * Hovering a collapsed parent while dragging opens it, so a term can be
     * dropped deep into the tree without letting go first.
     */
    function scheduleAutoExpand(targetId) {
        clearAutoExpand();

        if (!collapsed[targetId] || !nodes[targetId] || !nodes[targetId].kids.length) {
            return;
        }

        hoverTimer = window.setTimeout(function () {
            setCollapsed(targetId, false);
            refreshTree();
        }, 700);
    }

    function clearAutoExpand() {
        if (hoverTimer) {
            window.clearTimeout(hoverTimer);
            hoverTimer = null;
        }
    }

    function markDescendants(node, bag) {
        node.kids.forEach(function (kid) {
            bag[kid.id] = true;
            markDescendants(kid, bag);
        });
    }

    // =========================================================================
    // AJAX SET PARENT
    // =========================================================================

    function setParent(termId, parentId) {
        var $row = $('#tag-' + termId);
        $row.addClass('brikpanel-cat-saving');

        $.ajax({
            url: CE.ajax_url,
            type: 'POST',
            data: {
                action:    'brikpanel_set_category_parent',
                security:  CE.nonce,
                term_id:   termId,
                parent_id: parentId,
                taxonomy:  CE.taxonomy
            },
            success: function (res) {
                $row.removeClass('brikpanel-cat-saving');

                if (res && res.success) {
                    showToast(res.data.message, 'success');
                    // The whole branch order changes server-side; a reload is
                    // the only way to show it exactly as WordPress will.
                    window.setTimeout(function () {
                        window.location.reload();
                    }, 700);
                } else {
                    showToast((res && res.data && res.data.message) || I18N.error, 'error');
                }
            },
            error: function () {
                $row.removeClass('brikpanel-cat-saving');
                showToast(I18N.error, 'error');
            }
        });
    }

    // =========================================================================
    // ROW ACTIONS
    // =========================================================================

    function bindRowActions() {
        $(document).on('click.bpcat', 'a.brikpanel-toplevel', function (e) {
            e.preventDefault();
            var termId = parseInt($(this).attr('data-term'), 10);
            if (termId) {
                setParent(termId, 0);
            }
        });

        $(document).on('click.bpcat', 'a.brikpanel-add-child', function (e) {
            e.preventDefault();

            var termId = parseInt($(this).attr('data-term'), 10);
            var name   = $(this).attr('data-name') || '';
            var $form  = $('#addtag');
            var $sel   = $form.find('select#parent');

            if (!termId || !$form.length || !$sel.length) {
                return;
            }

            if (!$sel.find('option[value="' + termId + '"]').length) {
                showToast(I18N.error, 'error');
                return;
            }

            $sel.val(String(termId)).trigger('change');

            // Expand the branch so the new child is visible once it is added.
            if (TREE && nodes[termId]) {
                setCollapsed(termId, false);
                expandAncestors(nodes[termId]);
                saveState();
                refreshTree();
            }

            var $name = $form.find('#tag-name');
            $name.val('').trigger('focus');

            if ($form[0] && $form[0].scrollIntoView) {
                $form[0].scrollIntoView({ block: 'center' });
            }

            showToast(
                (I18N.adding_under || '').replace('%s', name),
                'success'
            );
        });
    }

    /**
     * WordPress adds, quick-edits and deletes term rows over AJAX. Rebuild the
     * tree afterwards so new rows get their twisty and the counts stay right.
     */
    function bindListRefresh() {
        if (!TREE) {
            return;
        }

        $(document).on('ajaxSuccess.bpcat', function (event, xhr, settings) {
            var data = (settings && settings.data) || '';

            if (typeof data !== 'string') {
                return;
            }

            var isAdd    = data.indexOf('action=add-tag') !== -1;
            var isInline = data.indexOf('action=inline-save-tax') !== -1;
            var isDelete = data.indexOf('action=delete-tag') !== -1;

            if (!isAdd && !isInline && !isDelete) {
                return;
            }

            // Deletions fade the row out over 400ms before removing it, so
            // wait comfortably past that or the gone row is counted again.
            window.setTimeout(function () {
                wrapRowActions();
                setupDescriptionTooltips();
                buildTree();
            }, isDelete ? 900 : 30);
        });
    }

    // =========================================================================
    // ROW ACTIONS WRAPPER
    // =========================================================================

    function wrapRowActions() {
        $('#the-list .row-actions').each(function () {
            var $ra = $(this);
            if ($ra.children('.brikpanel-row-actions-inner').length) {
                return;
            }
            $ra.contents().wrapAll('<span class="brikpanel-row-actions-inner"></span>');
        });
    }

    // =========================================================================
    // DESCRIPTION TOOLTIP
    // =========================================================================

    function setupDescriptionTooltips() {
        $('#the-list td.column-description').each(function () {
            var $td = $(this);

            if ($td.children('.brikpanel-desc-text, .brikpanel-desc-empty').length) {
                return;
            }

            // WP renders empty descriptions as: <span aria-hidden="true">—</span><span class="screen-reader-text">No description</span>
            // If any such placeholder exists, treat the cell as empty.
            var hasPlaceholder = $td.find('.screen-reader-text').length > 0 || $td.find('[aria-hidden="true"]').length > 0;
            var text = hasPlaceholder ? '' : $.trim($td.text());
            // Safety: also treat bare em-dash/dash as empty
            if (text === '—' || text === '-') {
                text = '';
            }

            if (text) {
                // Wrap in a span so td stays overflow:visible (for tooltip) while text truncates
                $td.empty().append(
                    $('<span class="brikpanel-desc-text"></span>').text(text)
                );
                $td.attr('data-description', text);
                $td.attr('title', text);
            } else {
                // Show a muted dash, no tooltip
                $td.empty().append($('<span class="brikpanel-desc-empty"></span>').text('—')); // i18n-ignore: typographic dash, not copy.
                $td.attr('data-description', '');
                $td.removeAttr('title');
            }
        });
    }

    // =========================================================================
    // REPOSITION SEARCH
    // =========================================================================

    function repositionSearch() {
        var $searchForm = $('form.search-form');
        var $heading    = $('.wrap > h1').first();

        if (!$searchForm.length || !$heading.length) {
            return;
        }

        // Create a header container
        var $header = $('<div class="brikpanel-cat-header"></div>');
        $heading.wrap($header);
        $header = $heading.parent();

        // Move the whole <form> into the header. Moving only `.search-box`
        // strands the input+submit outside any form, so clicking the button
        // or pressing Enter no longer triggers a GET to edit-tags.php.
        $searchForm.appendTo($header);
        $header.addClass('brikpanel-cat-header');

        // Only when the full tree is on the page can the box filter live;
        // otherwise it has to keep reloading into a server-side search.
        var $input = $searchForm.find('#tag-search-input');
        if (FULL && $input.length) {
            bindInstantFilter($input, $searchForm);
        }
    }

    // =========================================================================
    // TOAST
    // =========================================================================

    function showToast(message, type) {
        if (!message) {
            return;
        }

        var $container = $('.brikpanel-cat-toast-container');

        if (!$container.length) {
            $container = $('<div class="brikpanel-cat-toast-container"></div>');
            $('body').append($container);
        }

        var $toast = $('<div class="brikpanel-cat-toast"></div>').addClass(type).text(message);
        $container.append($toast);

        window.setTimeout(function () { $toast.addClass('show'); }, 10);
        window.setTimeout(function () {
            $toast.removeClass('show');
            window.setTimeout(function () { $toast.remove(); }, 300);
        }, 3500);
    }

    // =========================================================================
    // ICONS
    // =========================================================================

    function svgChevron() {
        return '<svg viewBox="0 0 20 20" width="12" height="12" fill="none" aria-hidden="true" focusable="false">' +
            '<path d="M7.5 4.5L13 10l-5.5 5.5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
    }

    function svgFolder() {
        return '<svg viewBox="0 0 20 20" width="14" height="14" fill="none" aria-hidden="true" focusable="false">' +
            '<path d="M2.5 5.5A1.5 1.5 0 014 4h3.2c.4 0 .8.16 1.06.44l1 1.06H16A1.5 1.5 0 0117.5 7v7.5A1.5 1.5 0 0116 16H4a1.5 1.5 0 01-1.5-1.5v-9z" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/></svg>';
    }

    function svgLeaf() {
        return '<svg viewBox="0 0 20 20" width="14" height="14" fill="none" aria-hidden="true" focusable="false">' +
            '<path d="M10.6 2.9l5.6 5.6a1.5 1.5 0 010 2.12l-5.02 5.02a1.5 1.5 0 01-2.12 0L3.5 10.1V4.4a1.5 1.5 0 011.5-1.5h5.6z" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/>' +
            '<circle cx="7" cy="6.4" r="1" fill="currentColor"/></svg>';
    }

    function svgGrip() {
        return '<svg viewBox="0 0 20 20" width="12" height="12" aria-hidden="true" focusable="false">' +
            '<circle cx="7.5" cy="5" r="1.3" fill="currentColor"/><circle cx="12.5" cy="5" r="1.3" fill="currentColor"/>' +
            '<circle cx="7.5" cy="10" r="1.3" fill="currentColor"/><circle cx="12.5" cy="10" r="1.3" fill="currentColor"/>' +
            '<circle cx="7.5" cy="15" r="1.3" fill="currentColor"/><circle cx="12.5" cy="15" r="1.3" fill="currentColor"/></svg>';
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    function getTermId($row) {
        var match = ($row.attr('id') || '').match(/tag-(\d+)/);
        return match ? parseInt(match[1], 10) : 0;
    }

    // =========================================================================
    // BOOT
    // =========================================================================

    $(function () {
        init();
    });

})(jQuery);
