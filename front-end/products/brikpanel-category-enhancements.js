/**
 * BrikPanel – Category Drag & Drop Nesting
 *
 * Allows dragging a category row ON TOP of another row
 * to make it a child (sub-category) via AJAX.
 *
 * @package BrikPanel
 * @since 1.7.0
 */
(function ($) {
    'use strict';

    var CE = window.brikpanelCE || {};
    var dragRow = null;
    var dragId = 0;
    var placeholder = null;

    function init() {
        var $table = $('#the-list');
        if (!$table.length) return;

        // Make rows draggable
        $table.find('tr').each(function () {
            makeRowDraggable($(this));
        });

        // Move search box into the table card header
        repositionSearch();

        // Set data-description for hover tooltip
        setupDescriptionTooltips();

        // Wrap row-actions children so the grid 0fr -> 1fr hover expansion
        // animates to natural content height without clipping wrapped actions.
        wrapRowActions();
    }

    // =========================================================================
    // ROW ACTIONS WRAPPER
    // =========================================================================

    function wrapRowActions() {
        $('#the-list .row-actions').each(function () {
            var $ra = $(this);
            if ($ra.children('.brikpanel-row-actions-inner').length) return;
            $ra.contents().wrapAll('<span class="brikpanel-row-actions-inner"></span>');
        });
    }

    // =========================================================================
    // DESCRIPTION TOOLTIP
    // =========================================================================

    function setupDescriptionTooltips() {
        $('#the-list td.column-description').each(function () {
            var $td = $(this);
            // WP renders empty descriptions as: <span aria-hidden="true">—</span><span class="screen-reader-text">No description</span>
            // If any such placeholder exists, treat the cell as empty.
            var hasPlaceholder = $td.find('.screen-reader-text').length > 0 || $td.find('[aria-hidden="true"]').length > 0;
            var text = hasPlaceholder ? '' : $.trim($td.text());
            // Safety: also treat bare em-dash/dash as empty
            if (text === '\u2014' || text === '—' || text === '-') text = '';

            if (text) {
                // Wrap in a span so td stays overflow:visible (for tooltip) while text truncates
                $td.empty().append(
                    $('<span class="brikpanel-desc-text"></span>').text(text)
                );
                $td.attr('data-description', text);
                $td.attr('title', text);
            } else {
                // Show a muted dash, no tooltip
                $td.html('<span class="brikpanel-desc-empty">—</span>');
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
        var $heading = $('.wrap > h1').first();
        if (!$searchForm.length || !$heading.length) return;

        // Create a header container
        var $header = $('<div class="brikpanel-cat-header"></div>');
        $heading.wrap($header);
        $header = $heading.parent();

        // Move the whole <form> into the header. Moving only `.search-box`
        // strands the input+submit outside any form, so clicking the button
        // or pressing Enter no longer triggers a GET to edit-tags.php.
        $searchForm.appendTo($header);

        // Style adjustments via class
        $header.addClass('brikpanel-cat-header');
    }

    // =========================================================================
    // DRAG & DROP NESTING
    // =========================================================================

    function makeRowDraggable($row) {
        var tag = $row.find('.row-title').first();
        if (!tag.length) return;

        // Add drag handle
        var $handle = $('<span class="brikpanel-cat-drag-handle" draggable="true" title="' + escAttr(CE.i18n.drag_to_nest) + '">⠿</span>');
        tag.before($handle);

        $handle.on('dragstart', function (e) {
            dragRow = $row;
            dragId = getTermId($row);
            $row.addClass('brikpanel-cat-dragging');
            e.originalEvent.dataTransfer.effectAllowed = 'move';
            e.originalEvent.dataTransfer.setData('text/plain', dragId);

            // Create ghost
            setTimeout(function () {
                $row.css('opacity', '0.4');
            }, 0);
        });

        $handle.on('dragend', function () {
            $row.removeClass('brikpanel-cat-dragging').css('opacity', '');
            clearDropTargets();
            dragRow = null;
            dragId = 0;
        });

        // Drop targets
        $row.on('dragover', function (e) {
            e.preventDefault();
            if (!dragRow || dragRow[0] === this) return;
            e.originalEvent.dataTransfer.dropEffect = 'move';

            clearDropTargets();
            $(this).addClass('brikpanel-cat-drop-target');
        });

        $row.on('dragleave', function () {
            $(this).removeClass('brikpanel-cat-drop-target');
        });

        $row.on('drop', function (e) {
            e.preventDefault();
            if (!dragRow || dragRow[0] === this) return;

            var targetId = getTermId($(this));
            var targetName = $(this).find('.row-title').text();

            clearDropTargets();
            dragRow.css('opacity', '');

            // Show confirmation
            var dragName = dragRow.find('.row-title').text();
            var msg = CE.i18n.confirm_nest
                .replace('%1$s', dragName)
                .replace('%2$s', targetName);

            if (!confirm(msg)) return;

            setParent(dragId, targetId);
        });
    }

    function clearDropTargets() {
        $('#the-list tr').removeClass('brikpanel-cat-drop-target');
    }

    function getTermId($row) {
        // WP adds id="tag-{ID}" to each row
        var id = $row.attr('id') || '';
        var match = id.match(/tag-(\d+)/);
        return match ? parseInt(match[1]) : 0;
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
                action: 'brikpanel_set_category_parent',
                security: CE.nonce,
                term_id: termId,
                parent_id: parentId,
                taxonomy: CE.taxonomy
            },
            success: function (res) {
                $row.removeClass('brikpanel-cat-saving');
                if (res.success) {
                    showToast(res.data.message, 'success');
                    // Reload the page to reflect hierarchy changes
                    setTimeout(function () {
                        window.location.reload();
                    }, 800);
                } else {
                    showToast(res.data.message || CE.i18n.error, 'error');
                }
            },
            error: function () {
                $row.removeClass('brikpanel-cat-saving');
                showToast(CE.i18n.error, 'error');
            }
        });
    }

    // =========================================================================
    // "MAKE TOP LEVEL" BUTTON
    // =========================================================================

    // Add "Make top level" to row actions for child categories
    function addTopLevelActions() {
        $('#the-list tr').each(function () {
            var $row = $(this);
            var $name = $row.find('.column-name .row-title');
            // Check if it's a child (name starts with "—" dash prefix)
            var nameText = $name.text();
            if (nameText.indexOf('—') === 0 || $name.closest('td').text().indexOf('—') > -1) {
                var termId = getTermId($row);
                var $actions = $row.find('.row-actions');
                if ($actions.length && !$actions.find('.brikpanel-toplevel').length) {
                    $actions.find('span:last').after(
                        ' | <span class="brikpanel-toplevel"><a href="#" data-term="' + termId + '">' +
                        escHtml(CE.i18n.make_top_level) + '</a></span>'
                    );
                }
            }
        });

        $(document).on('click', '.brikpanel-toplevel a', function (e) {
            e.preventDefault();
            var termId = parseInt($(this).data('term'));
            if (termId) {
                setParent(termId, 0);
            }
        });
    }

    // =========================================================================
    // TOAST
    // =========================================================================

    function showToast(message, type) {
        var $existing = $('.brikpanel-cat-toast-container');
        var $container;
        if ($existing.length) {
            $container = $existing;
        } else {
            $container = $('<div class="brikpanel-cat-toast-container"></div>');
            $('body').append($container);
        }

        var $toast = $('<div class="brikpanel-cat-toast ' + type + '">' + escHtml(message) + '</div>');
        $container.append($toast);

        setTimeout(function () { $toast.addClass('show'); }, 10);
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
    // BOOT
    // =========================================================================

    $(document).ready(function () {
        init();
        addTopLevelActions();
    });

})(jQuery);
