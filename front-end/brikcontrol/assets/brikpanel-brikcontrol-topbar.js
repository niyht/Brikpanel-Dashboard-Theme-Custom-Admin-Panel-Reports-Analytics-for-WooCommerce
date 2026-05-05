/**
 * BrikPanel — BrikControl Topbar
 *
 * Drives the shield button + dropdown rendered by Brikpanel_BrikControl::render_topbar_button.
 * Server pre-renders the markup with stale-but-valid data; this script:
 *   - polls /wp-admin/admin-ajax.php every 60s for fresh status
 *   - updates badge color, count, and dropdown rows in place
 *   - handles the "Rescan now" button (kicks off Action Scheduler async scan)
 *
 * Defensive: if the global config object is missing the script no-ops rather
 * than blowing up the topbar for everyone else.
 */
(function () {
    'use strict';

    var cfg = window.brikpanelBrikControlTopbar;
    if (!cfg || !cfg.ajax_url) {
        return;
    }

    var menuEl = document.querySelector('[data-topbar-menu="brikcontrol"]');
    if (!menuEl) {
        return;
    }

    var btn = menuEl.querySelector('.brikpanel-bc-btn');
    var dropdown = menuEl.querySelector('[data-bc-dropdown]');
    var listEl = menuEl.querySelector('[data-bc-list]');
    var lastScanEl = menuEl.querySelector('[data-bc-last-scan]');
    var rescanBtn = menuEl.querySelector('[data-bc-rescan]');

    var STATUS_LABELS = {
        critical: 'Critical',
        warning: 'Warning',
        ok: 'OK',
        unknown: 'Pending'
    };

    function relativeTime(ts) {
        if (!ts) {
            return cfg.i18n.never_scanned;
        }
        var diff = Math.max(0, Math.floor(Date.now() / 1000) - ts);
        if (diff < 60) return cfg.i18n.just_now;
        if (diff < 3600) return cfg.i18n.minutes_ago.replace('%s', Math.floor(diff / 60));
        if (diff < 86400) return cfg.i18n.hours_ago.replace('%s', Math.floor(diff / 3600));
        return cfg.i18n.days_ago.replace('%s', Math.floor(diff / 86400));
    }

    function setState(state, badgeText) {
        if (!btn) return;
        btn.classList.remove('brikpanel-bc-state-ok', 'brikpanel-bc-state-warning', 'brikpanel-bc-state-critical');
        btn.classList.add('brikpanel-bc-state-' + state);

        var existing = btn.querySelector('.brikpanel-bc-badge');
        if (badgeText) {
            if (existing) {
                existing.textContent = badgeText;
            } else {
                var span = document.createElement('span');
                span.className = 'brikpanel-topbar-badge brikpanel-bc-badge';
                span.textContent = badgeText;
                btn.appendChild(span);
            }
        } else if (existing) {
            existing.remove();
        }
    }

    function renderRows(checks) {
        if (!listEl) return;
        listEl.innerHTML = '';

        if (!checks || checks.length === 0) {
            var empty = document.createElement('div');
            empty.className = 'brikpanel-bc-empty';
            empty.textContent = cfg.i18n.all_ok;
            listEl.appendChild(empty);
            return;
        }

        checks.forEach(function (c) {
            var row = document.createElement('div');
            row.className = 'brikpanel-bc-row brikpanel-bc-row-' + (c.status || 'unknown');

            var dot = document.createElement('span');
            dot.className = 'brikpanel-bc-dot brikpanel-bc-dot-' + (c.status || 'unknown');
            dot.setAttribute('aria-hidden', 'true');

            var text = document.createElement('div');
            text.className = 'brikpanel-bc-row-text';

            var label = document.createElement('span');
            label.className = 'brikpanel-bc-row-label';
            label.textContent = c.label || c.id;
            text.appendChild(label);

            if (c.summary) {
                var summary = document.createElement('span');
                summary.className = 'brikpanel-bc-row-summary';
                summary.textContent = c.summary;
                text.appendChild(summary);
            }

            row.appendChild(dot);
            row.appendChild(text);
            listEl.appendChild(row);
        });
    }

    function applyPayload(bundle) {
        var summary = (bundle && bundle.status_summary) || { critical: 0, warning: 0, ok: 0 };
        var critical = summary.critical || 0;
        var warning = summary.warning || 0;

        if (critical > 0) {
            setState('critical', String(critical));
        } else if (warning > 0) {
            setState('warning', String(warning));
        } else {
            setState('ok', '');
        }

        if (lastScanEl) {
            lastScanEl.textContent = bundle.last_scan
                ? relativeTime(bundle.last_scan)
                : cfg.i18n.never_scanned;
        }

        var rows = [];
        if (bundle.checks) {
            Object.keys(bundle.checks).forEach(function (id) {
                var c = bundle.checks[id];
                rows.push({
                    id: id,
                    label: c.label || id,
                    status: c.status || 'unknown',
                    summary: c.summary || ''
                });
            });
        }
        // Sort: critical → warning → unknown → ok
        var order = { critical: 0, warning: 1, unknown: 2, ok: 3 };
        rows.sort(function (a, b) {
            return (order[a.status] || 4) - (order[b.status] || 4);
        });
        renderRows(rows);
    }

    function fetchStatus() {
        var fd = new FormData();
        fd.append('action', 'brikpanel_brikcontrol_data');
        fd.append('security', cfg.nonce);

        fetch(cfg.ajax_url, { method: 'POST', credentials: 'same-origin', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (json) {
                if (json && json.success && json.data && json.data.bundle) {
                    applyPayload(json.data.bundle);
                }
            })
            .catch(function () { /* network error — keep stale state */ });
    }

    function triggerRescan() {
        if (!rescanBtn) return;
        rescanBtn.disabled = true;
        var orig = rescanBtn.textContent;
        rescanBtn.textContent = cfg.i18n.scan_running;

        var fd = new FormData();
        fd.append('action', 'brikpanel_brikcontrol_rescan');
        fd.append('security', cfg.nonce);

        fetch(cfg.ajax_url, { method: 'POST', credentials: 'same-origin', body: fd })
            .then(function (r) { return r.json(); })
            .then(function () {
                setTimeout(function () {
                    fetchStatus();
                    rescanBtn.disabled = false;
                    rescanBtn.textContent = orig;
                }, 2500);
            })
            .catch(function () {
                rescanBtn.disabled = false;
                rescanBtn.textContent = orig;
            });
    }

    if (rescanBtn) {
        rescanBtn.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            triggerRescan();
        });
    }

    // Periodic refresh
    var interval = (cfg.refresh_interval && cfg.refresh_interval >= 15000) ? cfg.refresh_interval : 60000;
    setInterval(fetchStatus, interval);

    // First refresh on load (in case server-rendered cache was stale).
    setTimeout(fetchStatus, 800);

    // Dashboard banner dismiss handler — the banner renders on the dashboard
    // (not on the BrikControl page), where only the topbar JS is loaded, so
    // we need to handle the click here too.
    document.addEventListener('click', function (e) {
        var dismissBtn = e.target.closest('[data-bc-dismiss]');
        if (!dismissBtn) return;
        e.preventDefault();
        var banner = dismissBtn.closest('[data-bc-banner]');
        if (banner) banner.style.display = 'none';

        var fd = new FormData();
        fd.append('action', 'brikpanel_brikcontrol_dismiss');
        fd.append('security', cfg.nonce);
        fd.append('key', 'dashboard_banner');
        fetch(cfg.ajax_url, { method: 'POST', credentials: 'same-origin', body: fd }).catch(function () {});
    });
})();
