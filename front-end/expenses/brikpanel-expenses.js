/**
 * BrikPanel — Operational Expenses
 * Handles the expenses page: AJAX list/save/delete, modal, pagination, CSV export.
 */
(function () {
    'use strict';

    var cfg   = window.brikpanelExpenses || {};
    var i18n  = cfg.i18n || {};
    var CURRENCY = cfg.currency || '₺';

    // ── State ────────────────────────────────────────────────────────────────
    var state = {
        page:     1,
        pages:    1,
        loading:  false,
        editId:   0,
        items:    [],   // cache of current page items for edit lookup
    };

    // ── DOM refs ─────────────────────────────────────────────────────────────
    function el(id) { return document.getElementById(id); }

    var $tbody       = el('brikpanel-ex-tbody');
    var $total       = el('brikpanel-ex-total');
    var $count       = el('brikpanel-ex-count');
    var $from        = el('brikpanel-ex-from');
    var $to          = el('brikpanel-ex-to');
    var $catFilter   = el('brikpanel-ex-cat-filter');
    var $pagination  = el('brikpanel-ex-pagination');
    var $pageInfo    = el('brikpanel-ex-page-info');
    var $prev        = el('brikpanel-ex-prev');
    var $next        = el('brikpanel-ex-next');
    var $overlay     = el('brikpanel-ex-overlay');
    var $modalTitle  = el('brikpanel-ex-modal-title');
    var $form        = el('brikpanel-ex-form');
    var $editId      = el('brikpanel-ex-edit-id');
    var $date        = el('brikpanel-ex-date');
    var $amount      = el('brikpanel-ex-amount');
    var $category    = el('brikpanel-ex-category');
    var $parentCat   = el('brikpanel-ex-parent-category');
    var $parentHint  = el('brikpanel-ex-parent-hint');
    var $recurring   = el('brikpanel-ex-recurring');
    var $description = el('brikpanel-ex-description');
    var $submitBtn   = el('brikpanel-ex-submit-btn');
    var $kind        = el('brikpanel-ex-kind');
    var $prefix      = el('brikpanel-ex-prefix');
    var $suffix      = el('brikpanel-ex-suffix');

    // A percentage cost is a rate of revenue, always on: show "%" instead of the
    // currency and hide BOTH the Date and Recurring fields (a percentage has no
    // meaningful single date and applies every period by nature).
    function syncKind() {
        var pct = $kind && $kind.value === 'percent';
        if ($prefix) $prefix.hidden = pct;
        if ($suffix) $suffix.hidden = !pct;
        if ($amount) $amount.max = pct ? '100' : '';
        [$recurring, $date].forEach(function (input) {
            if (!input || !input.closest) return;
            var field = input.closest('.brikpanel-ex-field');
            if (field) field.hidden = pct;
        });
        // A hidden required date would block form submit in some browsers.
        if ($date) $date.required = !pct;
    }


    // ── "Part of" picker ──────────────────────────────────────────────────────
    // The <select> lists the expenses this one can be filed under. There is no
    // free-text path on purpose: you can only file a cost under a cost you have.

    function foldTitle(s) { return (s || '').trim().toLowerCase(); }

    function groupValue() {
        return $parentCat ? $parentCat.value : '';
    }

    // Put a stored value back into the picker. If it no longer matches an
    // option — the parent was renamed or deleted from somewhere else — add it
    // back as an option rather than silently resetting the field, so opening an
    // expense to change its amount cannot quietly unfile it.
    function setGroupValue(val) {
        if (!$parentCat) return;
        val = (val || '').trim();
        if (val === '') { $parentCat.value = ''; return; }
        var match = Array.prototype.slice.call($parentCat.options)
            .some(function (o) { return o.value === val; });
        if (!match) {
            var opt = document.createElement('option');
            opt.value = val;
            opt.textContent = val;
            opt.setAttribute('data-key', foldTitle(val));
            opt.setAttribute('data-transient', '1');
            $parentCat.appendChild(opt);
        }
        $parentCat.value = val;
    }

    // Offer a just-saved expense as a parent straight away. Only top-level ones
    // qualify — nesting stops at two levels — and only if the picker does not
    // already list that title.
    function addParentOption(title, parent) {
        title = (title || '').trim();
        if (!$parentCat || title === '' || (parent || '').trim() !== '') return;
        var key = foldTitle(title);
        var exists = Array.prototype.slice.call($parentCat.options)
            .some(function (o) { return o.getAttribute('data-key') === key; });
        if (exists) return;
        var opt = document.createElement('option');
        opt.value = title;
        opt.textContent = title;
        opt.setAttribute('data-key', key);
        $parentCat.appendChild(opt);
    }

    // An expense can never be filed under itself, so grey that option out as the
    // title is typed.
    //
    // It must NEVER clear the current selection: a title is typed one letter at
    // a time and passes through names on the way to its own. "ahmo 2" filed
    // under "ahmo" spends one keystroke looking exactly like its own parent, and
    // clearing there silently unfiled the expense with nothing on screen to show
    // for it. Disabling alone is safe — a disabled option that is already
    // selected still submits, and the server rejects a real collision with a
    // message the merchant can read.
    function syncSelfExclusion() {
        if (!$parentCat) return;
        var self = foldTitle($category ? $category.value : '');
        Array.prototype.slice.call($parentCat.options).forEach(function (o) {
            if (!o.value) { return; }
            o.disabled = self !== '' && o.getAttribute('data-key') === self
                && $parentCat.value !== o.value;
        });
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    function recurringLabel(val) {
        var map = {
            none:    i18n.recurring_none    || 'One-time',
            monthly: i18n.recurring_monthly || 'Monthly',
            weekly:  i18n.recurring_weekly  || 'Weekly',
            yearly:  i18n.recurring_yearly  || 'Yearly',
        };
        return map[val] || val;
    }

    function escHtml(str) {
        return String(str || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function showToast(msg, type) {
        var t = document.createElement('div');
        t.className = 'brikpanel-ex-toast brikpanel-ex-toast--' + (type || 'success');
        t.textContent = msg;
        document.body.appendChild(t);
        // force reflow for CSS transition
        void t.offsetHeight;
        t.classList.add('brikpanel-ex-toast--visible');
        setTimeout(function () {
            t.classList.remove('brikpanel-ex-toast--visible');
            setTimeout(function () { t.parentNode && t.parentNode.removeChild(t); }, 350);
        }, 3500);
    }

    function ajax(data, cb) {
        data._ajax_nonce = cfg.nonce;
        var body = new URLSearchParams(data).toString();
        fetch(cfg.ajax_url, {
            method:  'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body:    body,
        })
        .then(function (r) { return r.json(); })
        .then(function (res) { cb(null, res); })
        .catch(function (err) { cb(err, null); });
    }

    // ── List ──────────────────────────────────────────────────────────────────

    function fetchList(page) {
        if (state.loading) return;
        state.loading = true;
        state.page    = page || 1;
        $tbody.innerHTML = '<tr><td colspan="6" class="brikpanel-ex-empty brikpanel-ex-loading"><span class="brikpanel-ex-spinner"></span></td></tr>';

        ajax({
            action:    'brikpanel_expenses_list',
            date_from: $from ? $from.value : '',
            date_to:   $to   ? $to.value   : '',
            category:  $catFilter ? $catFilter.value : '',
            page:      state.page,
        }, function (err, res) {
            state.loading = false;
            if (err || !res || !res.success) {
                $tbody.innerHTML = '<tr><td colspan="6" class="brikpanel-ex-empty">' + escHtml(i18n.error || 'Something went wrong.') + '</td></tr>';
                return;
            }
            var d = res.data;
            state.pages = d.pages || 1;
            state.items = d.items || [];

            if ($total) $total.textContent = d.total_fmt || CURRENCY + '0';
            if ($count) $count.textContent = d.total_count || '0';

            renderRows(state.items);
            renderPagination(d.page, d.pages);
        });
    }

    function renderRows(items) {
        if (!items.length) {
            $tbody.innerHTML = '<tr><td colspan="6" class="brikpanel-ex-empty">' + escHtml(i18n.no_expenses || 'No expenses found.') + '</td></tr>';
            return;
        }
        var html = '';
        items.forEach(function (item) {
            var isPct = item.kind === 'percent';
            html += '<tr data-id="' + item.id + '">';
            // Percentage costs are always-on, so they have no single date and no
            // repeat schedule — show "Ongoing" and a dash instead.
            html += '<td class="brikpanel-ex-date-cell">' + escHtml(isPct ? (i18n.ongoing || 'Ongoing') : item.date) + '</td>';
            // What this cost is filed under sits above the title as a quiet line
            // rather than claiming a whole column, so the table keeps its six
            // columns and standalone rows look exactly as they always did.
            html += '<td>';
            if (item.parent_category) {
                html += '<span class="brikpanel-ex-parent-cat">'
                    + escHtml((i18n.filed_under || 'Part of %s').replace('%s', item.parent_category))
                    + '</span>';
            }
            html += '<span class="brikpanel-ex-cat-badge">' + escHtml(item.category) + '</span></td>';
            html += '<td class="brikpanel-ex-desc-cell">' + escHtml(item.description || '—') + '</td>';
            html += isPct
                ? '<td>—</td>'
                : '<td><span class="brikpanel-ex-rec-badge brikpanel-ex-rec-' + escHtml(item.recurring) + '">' + escHtml(recurringLabel(item.recurring)) + '</span></td>';
            html += '<td class="brikpanel-ex-num">' + escHtml(item.amount_fmt) + '</td>';
            html += '<td class="brikpanel-ex-actions-cell">';
            html += '<button class="brikpanel-ex-icon-btn brikpanel-ex-edit-btn" data-id="' + item.id + '" title="' + escHtml(i18n.edit || 'Edit') + '" aria-label="' + escHtml(i18n.edit || 'Edit') + '">';
            html += '<svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>';
            html += '</button>';
            html += '<button class="brikpanel-ex-icon-btn brikpanel-ex-delete-btn" data-id="' + item.id + '" title="' + escHtml(i18n.delete || 'Delete') + '" aria-label="' + escHtml(i18n.delete || 'Delete') + '">';
            html += '<svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>';
            html += '</button>';
            html += '</td>';
            html += '</tr>';
        });
        $tbody.innerHTML = html;
    }

    function renderPagination(page, pages) {
        if (!$pagination) return;
        if (pages <= 1) {
            $pagination.hidden = true;
            return;
        }
        $pagination.hidden = false;
        if ($pageInfo) $pageInfo.textContent = page + ' / ' + pages;
        if ($prev) $prev.disabled = (page <= 1);
        if ($next) $next.disabled = (page >= pages);
    }

    // ── Modal ────────────────────────────────────────────────────────────────

    function openModal(editData) {
        state.editId = editData ? editData.id : 0;
        // Drop any option a previous edit had to add back by hand, so a name
        // that no longer exists cannot leak into the next expense's choices.
        if ($parentCat) {
            Array.prototype.slice.call($parentCat.querySelectorAll('option[data-transient]'))
                .forEach(function (o) { o.remove(); });
        }
        if ($editId)    $editId.value    = state.editId || '';
        if ($modalTitle) $modalTitle.textContent = editData ? (i18n.edit_title || 'Edit expense') : (i18n.add_title || 'Add expense');

        if (editData) {
            if ($date)        $date.value        = editData.date || '';
            if ($amount)      $amount.value      = editData.amount || '';
            if ($category)    $category.value    = editData.category || '';
            setGroupValue(editData.parent_category || '');
            if ($recurring)   $recurring.value   = editData.recurring || 'none';
            if ($description) $description.value = editData.description || '';
            if ($kind)        $kind.value        = editData.kind || 'fixed';
        } else {
            if ($form) $form.reset();
            // Set today as default date
            var today = new Date();
            var y = today.getFullYear();
            var m = String(today.getMonth() + 1).padStart(2, '0');
            var d = String(today.getDate()).padStart(2, '0');
            if ($date) $date.value = y + '-' + m + '-' + d;
            if ($recurring) $recurring.value = 'none';
            if ($kind) $kind.value = 'fixed';
            setGroupValue('');
        }
        syncKind();
        // An expense that already has costs filed under it cannot itself be
        // filed under another one — nesting stops at two levels, so the picker
        // is closed off rather than offering a choice the server would refuse.
        if ($parentCat) {
            var hasKids = !!(editData && editData.has_children);
            $parentCat.disabled = hasKids;
            if ($parentHint) {
                $parentHint.textContent = hasKids
                    ? (i18n.parent_has_children || 'Other costs are filed under this one, so it cannot go under another.')
                    : (i18n.parent_hint || '');
            }
        }
        syncSelfExclusion();

        $overlay.hidden = false;
        document.body.classList.add('brikpanel-ex-modal-open');
        if ($date) $date.focus();
    }

    function closeModal() {
        $overlay.hidden = true;
        document.body.classList.remove('brikpanel-ex-modal-open');
        state.editId = 0;
    }

    // ── Save ──────────────────────────────────────────────────────────────────

    function saveExpense(e) {
        e.preventDefault();
        if ($submitBtn) {
            $submitBtn.disabled = true;
            $submitBtn.textContent = '…';
        }

        var isPct  = $kind && $kind.value === 'percent';
        var title  = $category ? $category.value.trim() : '';
        var parent = groupValue();
        ajax({
            action:       'brikpanel_expenses_save',
            id:           $editId ? $editId.value : '',
            kind:         isPct ? 'percent' : 'fixed',
            expense_date: $date        ? $date.value        : '',
            amount:       $amount      ? $amount.value      : '',
            category:     title,
            parent_category: parent,
            recurring:    ( isPct || !$recurring ) ? 'none' : $recurring.value,
            description:  $description ? $description.value : '',
        }, function (err, res) {
            if ($submitBtn) {
                $submitBtn.disabled = false;
                $submitBtn.textContent = i18n.save || 'Save';
            }
            if (err || !res || !res.success) {
                var msg = (res && res.data && res.data.message) ? res.data.message : (i18n.error || 'Something went wrong.');
                showToast(msg, 'error');
                return;
            }
            // The expense just saved is itself something the next one can go
            // under, and the picker is rendered once with the page — so offer it
            // now rather than only after a reload.
            addParentOption(title, parent);
            closeModal();
            fetchList(1);
        });
    }

    // ── Delete ───────────────────────────────────────────────────────────────

    function deleteExpense(id) {
        // Removing a parent takes everything filed under it, so say so before
        // asking — the merchant must never learn that from the result.
        var item = null;
        for (var i = 0; i < state.items.length; i++) {
            if (state.items[i].id === id) { item = state.items[i]; break; }
        }
        var question = (item && item.has_children)
            ? (i18n.confirm_delete_parent || 'Delete this expense and everything filed under it?')
            : (i18n.confirm_delete || 'Delete this expense?');
        if (!window.confirm(question)) return;
        ajax({ action: 'brikpanel_expenses_delete', id: id }, function (err, res) {
            if (err || !res || !res.success) {
                showToast(i18n.error || 'Something went wrong.', 'error');
                return;
            }
            fetchList(state.page);
        });
    }

    // ── Edit click (open modal pre-filled from cache) ─────────────────────────

    function handleEditClick(id) {
        var found = null;
        for (var i = 0; i < state.items.length; i++) {
            if (state.items[i].id === id) { found = state.items[i]; break; }
        }
        if (!found) return;
        openModal(found);
    }

    // ── CSV Export ───────────────────────────────────────────────────────────

    function exportCSV() {
        ajax({
            action:    'brikpanel_expenses_list',
            date_from: $from ? $from.value : '',
            date_to:   $to   ? $to.value   : '',
            category:  $catFilter ? $catFilter.value : '',
            page:      1,
            per_page:  99999,
        }, function (err, res) {
            if (err || !res || !res.success) {
                showToast(i18n.error || 'Something went wrong.', 'error');
                return;
            }
            var items = res.data.items || [];
            if (!items.length) {
                showToast(i18n.no_expenses || 'No expenses found.', 'error');
                return;
            }
            var rows = [[
                i18n.csv_date        || 'Date',
                i18n.csv_parent_category || 'Part of',
                i18n.csv_category    || 'Title',
                i18n.csv_description || 'Description',
                i18n.csv_recurring   || 'Recurring',
                i18n.csv_amount      || 'Amount',
            ]];
            items.forEach(function (item) {
                rows.push([
                    item.date,
                    '"' + (item.parent_category || '').replace(/"/g, '""') + '"',
                    item.category,
                    '"' + (item.description || '').replace(/"/g, '""') + '"',
                    recurringLabel(item.recurring),
                    item.amount,
                ]);
            });
            var csv = rows.map(function (r) { return r.join(','); }).join('\n');
            var blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
            var url  = URL.createObjectURL(blob);
            var a    = document.createElement('a');
            a.href     = url;
            a.download = 'expenses-' + ($from ? $from.value : '') + '--' + ($to ? $to.value : '') + '.csv';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(url);
        });
    }

    // ── Event bindings ────────────────────────────────────────────────────────

    function bind() {
        // Add expense button
        var addBtn = el('brikpanel-ex-add-btn');
        if (addBtn) addBtn.addEventListener('click', function () { openModal(null); });

        // Apply filter
        var searchBtn = el('brikpanel-ex-search-btn');
        if (searchBtn) searchBtn.addEventListener('click', function () { fetchList(1); });

        // Export CSV
        var exportBtn = el('brikpanel-ex-export-btn');
        if (exportBtn) exportBtn.addEventListener('click', exportCSV);

        // Form submit (save)
        if ($form) $form.addEventListener('submit', saveExpense);
        if ($kind) $kind.addEventListener('change', syncKind);
        // Typing a title that matches an offered parent must take that option
        // out of play immediately, not only when the form is submitted.
        if ($category) $category.addEventListener('input', syncSelfExclusion);

        // Close modal buttons
        var closeBtn = el('brikpanel-ex-modal-close');
        if (closeBtn) closeBtn.addEventListener('click', closeModal);
        var cancelBtn = el('brikpanel-ex-cancel-btn');
        if (cancelBtn) cancelBtn.addEventListener('click', closeModal);

        // Overlay click to close
        if ($overlay) {
            $overlay.addEventListener('click', function (e) {
                if (e.target === $overlay) closeModal();
            });
        }

        // Escape key
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && !$overlay.hidden) closeModal();
        });

        // Pagination
        if ($prev) $prev.addEventListener('click', function () {
            if (state.page > 1) fetchList(state.page - 1);
        });
        if ($next) $next.addEventListener('click', function () {
            if (state.page < state.pages) fetchList(state.page + 1);
        });

        // Table row actions (edit / delete) via delegation
        if ($tbody) {
            $tbody.addEventListener('click', function (e) {
                var editBtn   = e.target.closest('.brikpanel-ex-edit-btn');
                var deleteBtn = e.target.closest('.brikpanel-ex-delete-btn');
                if (editBtn) {
                    var id = parseInt(editBtn.getAttribute('data-id'), 10);
                    if (id) handleEditClick(id);
                }
                if (deleteBtn) {
                    var dId = parseInt(deleteBtn.getAttribute('data-id'), 10);
                    if (dId) deleteExpense(dId);
                }
            });
        }

        // Filter on Enter key in date/category inputs
        [$from, $to, $catFilter].forEach(function (input) {
            if (!input) return;
            input.addEventListener('keydown', function (e) {
                if (e.key === 'Enter') fetchList(1);
            });
        });
    }

    // ── Init ──────────────────────────────────────────────────────────────────

    function init() {
        bind();
        fetchList(1);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

}());
