/**
 * BrikPanel — BrikControl Page Script
 *
 * Backs the admin.php?page=brikpanel-brikcontrol page:
 *   - "Scan now" button → kicks off Action Scheduler async scan
 *   - polls progress while a scan is running and updates the bar + chips
 *   - reloads the page once the scan finishes so the freshly-rendered cards
 *     come from the server (avoids client-side rendering parity bugs)
 *   - per-check repair and undo buttons
 *
 * Every sentence with a number in it (progress, confirmations, results) comes
 * from the server, written with _n() in the viewer's language; this file only
 * places it.
 */
(function () {
    'use strict';

    // Sample tables in the check cards: rows turn into cards when a table
    // does not fit (field test B6). The rows come from the server, so one
    // refit each is enough; a closed <details> is measured once it opens.
    if (window.brikpanelFitTable) {
        Array.prototype.forEach.call(document.querySelectorAll('.brikpanel-bc-table-wrap > .brikpanel-fit-table'), function (table) {
            window.brikpanelFitTable(table, { labels: 'head', slack: 0 }).refit();
        });
    }

    var cfg = window.brikpanelBrikControl;
    if (!cfg || !cfg.ajax_url) {
        return;
    }

    var rescanBtn = document.querySelector('[data-bc-rescan-page]');
    var rescanLabel = document.querySelector('[data-bc-rescan-label]');
    var progressEl = document.querySelector('[data-bc-progress]');
    var progressBar = document.querySelector('[data-bc-progress-bar]');
    var progressPct = document.querySelector('[data-bc-progress-pct]');
    var progressLabel = document.querySelector('[data-bc-progress-label]');

    var pollHandle = null;
    var pollAttempts = 0;
    var MAX_ATTEMPTS = 240; // 240 * 5s = 20 min max poll window

    function showProgress(show) {
        if (!progressEl) return;
        if (show) {
            progressEl.removeAttribute('hidden');
        } else {
            progressEl.setAttribute('hidden', '');
        }
    }

    function updateProgress(progress, texts) {
        if (!progress) return;
        var pct = 0;
        if (progress.total > 0) {
            pct = Math.min(100, Math.round((progress.cursor / progress.total) * 100));
        }
        if (progressBar) progressBar.style.width = pct + '%'; // i18n-ignore: CSS width, not text.
        if (texts) {
            if (progressPct) progressPct.textContent = texts.pct || '';
            if (progressLabel && texts.label) progressLabel.textContent = texts.label;
        }
    }

    function updateChips(bundle) {
        if (!bundle || !bundle.status_summary) return;
        var s = bundle.status_summary;
        ['critical', 'warning', 'ok', 'unknown'].forEach(function (key) {
            var el = document.querySelector('[data-bc-count="' + key + '"]');
            if (!el) return;
            el.textContent = s[key] || 0;
            // A zero count keeps the neutral look: "0 warnings" is good news.
            var chip = el.closest('.brikpanel-bc-summary-chip');
            if (chip) chip.classList.toggle('is-zero', !(Number(s[key]) > 0));
        });
    }

    function poll() {
        pollAttempts++;
        if (pollAttempts > MAX_ATTEMPTS) {
            stopPolling();
            return;
        }

        var fd = new FormData();
        fd.append('action', 'brikpanel_brikcontrol_progress');
        fd.append('security', cfg.nonce);

        fetch(cfg.ajax_url, { method: 'POST', credentials: 'same-origin', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (json) {
                if (!json || !json.success || !json.data) return;
                var d = json.data;
                if (d.is_active) {
                    showProgress(true);
                    updateProgress(d.progress, d.texts);
                    updateChips(d.bundle);
                    return;
                }
                // Scan finished — full reload so the cards reflect the new
                // data without us re-implementing the partial render here.
                stopPolling();
                window.location.reload();
            })
            .catch(function () { /* swallow — keep polling */ });
    }

    function startPolling() {
        stopPolling();
        pollAttempts = 0;
        showProgress(true);
        pollHandle = setInterval(poll, 5000);
        // Quick first hit so the bar updates within ~1s.
        setTimeout(poll, 1200);
    }

    function stopPolling() {
        if (pollHandle) {
            clearInterval(pollHandle);
            pollHandle = null;
        }
    }

    function triggerRescan() {
        if (!rescanBtn) return;
        rescanBtn.disabled = true;
        if (rescanLabel) rescanLabel.textContent = cfg.i18n.rescanning;

        var fd = new FormData();
        fd.append('action', 'brikpanel_brikcontrol_rescan');
        fd.append('security', cfg.nonce);

        fetch(cfg.ajax_url, { method: 'POST', credentials: 'same-origin', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (json) {
                if (json && json.success) {
                    startPolling();
                } else {
                    rescanBtn.disabled = false;
                    if (rescanLabel) rescanLabel.textContent = cfg.i18n.rescan_failed;
                }
            })
            .catch(function () {
                rescanBtn.disabled = false;
                if (rescanLabel) rescanLabel.textContent = cfg.i18n.rescan_failed;
            });
    }

    if (rescanBtn) {
        rescanBtn.addEventListener('click', function (e) {
            e.preventDefault();
            triggerRescan();
        });
    }

    // If the page is rendered while a scan is already running, jump straight
    // into polling (no need to click rescan).
    if (progressEl && !progressEl.hasAttribute('hidden')) {
        startPolling();
    }

    // Per-check repair action. The button only exists for checks that opted in
    // via supports_fix() and that currently have something to clean.
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-bc-fix]');
        if (!btn || btn.disabled) return;
        e.preventDefault();

        var i18n     = cfg.i18n || {};
        var checkId  = btn.getAttribute('data-bc-fix');
        var labelEl  = btn.querySelector('[data-bc-fix-label]');
        var resultEl = btn.parentNode ? btn.parentNode.querySelector('[data-bc-fix-result]') : null;
        // Captured from the DOM, never a hard-coded English string: the server
        // rendered it through the check's translated get_fix_label().
        var original = labelEl ? labelEl.textContent : '';

        // Written by the check on the server, with its count and plural.
        if (!window.confirm(btn.getAttribute('data-bc-fix-confirm') || '')) return;

        btn.disabled = true;
        if (labelEl) labelEl.textContent = i18n.fix_running || '';
        if (resultEl) resultEl.textContent = '';

        var restore = function (message) {
            btn.disabled = false;
            if (labelEl) labelEl.textContent = original;
            if (resultEl) resultEl.textContent = message;
        };

        var fd = new FormData();
        fd.append('action', 'brikpanel_brikcontrol_fix');
        fd.append('security', cfg.nonce);
        fd.append('check_id', checkId);

        fetch(cfg.ajax_url, { method: 'POST', credentials: 'same-origin', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (json) {
                if (json && json.success && json.data) {
                    if (resultEl) resultEl.textContent = json.data.message || '';
                    // Reload so the card re-renders from the freshly saved result;
                    // later when there is more than the count to read first.
                    setTimeout(function () { window.location.reload(); }, json.data.note ? 5000 : 2000);
                    return;
                }
                restore((json && json.data && json.data.message) || i18n.fix_failed || '');
            })
            .catch(function () {
                restore(i18n.fix_failed || '');
            });
    });

    // Undo for checks whose repair rewrites data instead of deleting dead rows.
    // Same shape as the repair handler above, deliberately: one confirm, one
    // request, reload so the card re-renders from the freshly saved result.
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-bc-undo]');
        if (!btn || btn.disabled) return;
        e.preventDefault();

        var i18n     = cfg.i18n || {};
        var checkId  = btn.getAttribute('data-bc-undo');
        var labelEl  = btn.querySelector('[data-bc-undo-label]');
        var resultEl = btn.parentNode ? btn.parentNode.querySelector('[data-bc-fix-result]') : null;
        // Captured from the DOM: the server already rendered it translated.
        var original = labelEl ? labelEl.textContent : '';

        if (!window.confirm(btn.getAttribute('data-bc-undo-confirm') || '')) return;

        btn.disabled = true;
        if (labelEl) labelEl.textContent = i18n.undo_running || '';
        if (resultEl) resultEl.textContent = '';

        var restore = function (message) {
            btn.disabled = false;
            if (labelEl) labelEl.textContent = original;
            if (resultEl) resultEl.textContent = message;
        };

        var fd = new FormData();
        fd.append('action', 'brikpanel_brikcontrol_undo');
        fd.append('security', cfg.nonce);
        fd.append('check_id', checkId);

        fetch(cfg.ajax_url, { method: 'POST', credentials: 'same-origin', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (json) {
                if (json && json.success && json.data) {
                    if (resultEl) resultEl.textContent = json.data.message || '';
                    setTimeout(function () { window.location.reload(); }, json.data.note ? 5000 : 2000);
                    return;
                }
                restore((json && json.data && json.data.message) || i18n.undo_failed || '');
            })
            .catch(function () {
                restore(i18n.undo_failed || '');
            });
    });
})();
