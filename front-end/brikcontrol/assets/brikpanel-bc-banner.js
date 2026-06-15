/**
 * Store Health dashboard banner — standalone dismiss handler.
 *
 * The banner renders on the BrikPanel dashboard whenever there is a critical
 * finding, independent of the topbar. When the topbar is enabled its own JS
 * already carries the dismiss handler; this file is enqueued only when the
 * topbar is OFF so the banner's "X" still works (otherwise no script would be
 * listening for the click and the banner could never be dismissed).
 *
 * No user-facing strings here, so nothing to localize beyond the transport
 * config (ajax_url + nonce).
 */
(function () {
    'use strict';

    var cfg = window.brikpanelBcBanner || {};

    document.addEventListener('click', function (e) {
        var dismissBtn = e.target.closest('[data-bc-dismiss]');
        if (!dismissBtn) return;
        e.preventDefault();

        var banner = dismissBtn.closest('[data-bc-banner]');
        if (banner) banner.style.display = 'none';

        if (!cfg.ajax_url || !cfg.nonce) return;

        var fd = new FormData();
        fd.append('action', 'brikpanel_brikcontrol_dismiss');
        fd.append('security', cfg.nonce);
        fd.append('key', 'dashboard_banner');
        fetch(cfg.ajax_url, { method: 'POST', credentials: 'same-origin', body: fd }).catch(function () {});
    });
})();
