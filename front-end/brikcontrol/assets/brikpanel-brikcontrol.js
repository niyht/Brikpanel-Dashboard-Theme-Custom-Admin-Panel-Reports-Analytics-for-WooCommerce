/**
 * BrikPanel — BrikControl Page Script
 *
 * Backs the admin.php?page=brikpanel-brikcontrol page:
 *   - "Scan now" button → kicks off Action Scheduler async scan
 *   - polls progress while a scan is running and updates the bar + chips
 *   - reloads the page once the scan finishes so the freshly-rendered cards
 *     come from the server (avoids client-side rendering parity bugs)
 *   - handles dashboard banner dismiss button (when banner is on this page —
 *     normally not, but the dismiss button uses the same mechanism)
 */
(function () {
    'use strict';

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

    function updateProgress(progress) {
        if (!progress) return;
        var pct = 0;
        if (progress.total > 0) {
            pct = Math.min(100, Math.round((progress.cursor / progress.total) * 100));
        }
        if (progressBar) progressBar.style.width = pct + '%';
        if (progressPct) progressPct.textContent = pct + '%';
        if (progressLabel && progress.total > 0) {
            var tpl = (cfg.i18n && cfg.i18n.scanning_progress) || 'Scanning {cursor} / {total} products…';
            progressLabel.textContent = tpl.replace('{cursor}', progress.cursor).replace('{total}', progress.total);
        }
    }

    function updateChips(bundle) {
        if (!bundle || !bundle.status_summary) return;
        var s = bundle.status_summary;
        ['critical', 'warning', 'ok', 'unknown'].forEach(function (key) {
            var el = document.querySelector('[data-bc-count="' + key + '"]');
            if (el) el.textContent = s[key] || 0;
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
                    updateProgress(d.progress);
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

    // Banner dismiss handler — works wherever the banner is rendered. Keeps
    // the dashboard render path simple (no extra script needed there).
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-bc-dismiss]');
        if (!btn) return;
        e.preventDefault();
        var banner = btn.closest('[data-bc-banner]');
        if (banner) banner.style.display = 'none';

        var fd = new FormData();
        fd.append('action', 'brikpanel_brikcontrol_dismiss');
        fd.append('security', cfg.nonce);
        fd.append('key', 'dashboard_banner');
        fetch(cfg.ajax_url, { method: 'POST', credentials: 'same-origin', body: fd }).catch(function () {});
    });
})();
