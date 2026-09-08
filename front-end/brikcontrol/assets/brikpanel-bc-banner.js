/**
 * Store Health dashboard banner — the one and only dismiss handler.
 *
 * The banner renders on the BrikPanel dashboard whenever there is a critical
 * finding, independent of the topbar, so this file is enqueued on the
 * dashboard whether the topbar is on or off. It used to be a fallback for
 * "topbar off" only, with copies of the same handler living in the topbar and
 * Store Health page scripts; the topbar copy sat behind an early return that
 * fires when the shield is not in the DOM, which left the X dead.
 *
 * Dismissal is permanent server-side, so a failed request matters: we re-show
 * the banner instead of leaving the user believing it was saved.
 *
 * User-facing strings come from the localized cfg.i18n (see
 * Brikpanel_BrikControl::enqueue_topbar_assets), never from this file.
 */
(function () {
    'use strict';

    var cfg = window.brikpanelBcBanner || {};
    var i18n = cfg.i18n || {};

    function showError(banner) {
        banner.style.display = '';

        var existing = banner.querySelector('.brikpanel-bc-banner-error');
        if (existing) {
            return;
        }
        var note = document.createElement('span');
        note.className = 'brikpanel-bc-banner-error';
        note.setAttribute('role', 'status');
        note.textContent = i18n.dismiss_failed;
        var text = banner.querySelector('.brikpanel-bc-banner-text');
        (text || banner).appendChild(note);
    }

    document.addEventListener('click', function (e) {
        var dismissBtn = e.target.closest('[data-bc-dismiss]');
        if (!dismissBtn) return;
        e.preventDefault();

        var banner = dismissBtn.closest('[data-bc-banner]');
        if (banner) banner.style.display = 'none';

        if (!cfg.ajax_url || !cfg.nonce) {
            if (banner) showError(banner);
            return;
        }

        var fd = new FormData();
        fd.append('action', 'brikpanel_brikcontrol_dismiss');
        fd.append('security', cfg.nonce);
        fd.append('key', 'dashboard_banner');

        fetch(cfg.ajax_url, { method: 'POST', credentials: 'same-origin', body: fd })
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('http');
                }
                return response.json();
            })
            .then(function (json) {
                // An expired nonce answers 403, but a permission failure can
                // also come back as a 200 with success:false — both mean
                // nothing was stored, so both must un-hide the banner.
                if (!json || !json.success) {
                    throw new Error('rejected');
                }
            })
            .catch(function () {
                if (banner) showError(banner);
            });
    });
})();
