/**
 * BrikPanel — Welcome / Feature Tour Popup
 *
 * Drives the two-pane tour: section rail, slide transitions, progress bar,
 * keyboard navigation, and dismissal.
 *
 * @package BrikPanel
 * @since   2.0.4
 */
(function () {
    'use strict';

    /* ── Refs ──────────────────────────────────────────────────────────────── */
    const overlay = document.getElementById('brikpanel-welcome-overlay');
    if (!overlay) return;

    const slides   = Array.from(overlay.querySelectorAll('.brikpanel-welcome-slide'));
    const rail     = Array.from(overlay.querySelectorAll('.brikpanel-welcome-railitem'));
    const progress = overlay.querySelector('.brikpanel-welcome-progress-fill');
    const btnPrev  = overlay.querySelector('[data-bw-prev]');
    const btnNext  = overlay.querySelector('[data-bw-next]');
    const btnClose = overlay.querySelector('.brikpanel-welcome-close');
    const skipBtn  = overlay.querySelector('.brikpanel-welcome-skip');

    const i18n = (typeof brikpanelWelcome !== 'undefined' && brikpanelWelcome.i18n) ? brikpanelWelcome.i18n : {};
    const arrow = '<svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2"><path d="M7 4l6 6-6 6"/></svg>';

    let current = 0;
    let maxSeen = 0;
    const total = slides.length;

    /* ── Show ──────────────────────────────────────────────────────────────── */
    function open() {
        overlay.style.display = 'flex';
        requestAnimationFrame(function () {
            overlay.classList.add('is-visible');
        });
        goTo(0);
        document.addEventListener('keydown', onKey);
    }

    /* ── Close ─────────────────────────────────────────────────────────────── */
    function close() {
        overlay.classList.remove('is-visible');
        document.removeEventListener('keydown', onKey);
        setTimeout(function () {
            overlay.style.display = 'none';
        }, 450);
        dismiss();
    }

    /* ── Dismiss AJAX ──────────────────────────────────────────────────────── */
    function dismiss() {
        if (typeof brikpanelWelcome === 'undefined') return;
        var fd = new FormData();
        fd.append('action', 'brikpanel_dismiss_welcome');
        fd.append('_wpnonce', brikpanelWelcome.nonce);
        fetch(brikpanelWelcome.ajax_url, {
            method: 'POST',
            body: fd,
            credentials: 'same-origin',
            keepalive: true
        });
    }

    /* ── Navigate ──────────────────────────────────────────────────────────── */
    function goTo(idx) {
        if (idx < 0 || idx >= total) return;

        var prev = current;
        current = idx;
        if (idx > maxSeen) maxSeen = idx;

        slides.forEach(function (s, i) {
            s.classList.remove('is-active', 'is-exiting-left');
            if (i === prev && prev !== idx) {
                s.classList.add('is-exiting-left');
            }
        });
        requestAnimationFrame(function () {
            slides[current].classList.add('is-active');
        });

        rail.forEach(function (r, i) {
            r.classList.toggle('is-active', i === current);
            r.classList.toggle('is-visited', i < maxSeen && i !== current);
        });

        /* progress bar */
        if (progress) progress.style.width = ((current + 1) / total * 100) + '%';

        /* keep active rail item in view (matters on mobile horizontal rail) */
        if (rail[current] && rail[current].scrollIntoView) {
            rail[current].scrollIntoView({ block: 'nearest', inline: 'nearest' });
        }

        /* button states */
        if (btnPrev) btnPrev.style.visibility = current === 0 ? 'hidden' : 'visible';
        if (skipBtn) skipBtn.style.visibility = current === total - 1 ? 'hidden' : 'visible';
        if (btnNext) {
            var isLast = current === total - 1;
            var label  = isLast ? (i18n.get_started || 'Get Started') : (i18n.next || 'Next');
            btnNext.innerHTML = label + ' ' + arrow;
        }
    }

    /* ── Keyboard ──────────────────────────────────────────────────────────── */
    function onKey(e) {
        if (e.key === 'Escape')     close();
        if (e.key === 'ArrowRight') goTo(current + 1);
        if (e.key === 'ArrowLeft')  goTo(current - 1);
    }

    /* ── Events ────────────────────────────────────────────────────────────── */
    if (btnClose) btnClose.addEventListener('click', close);
    if (btnPrev)  btnPrev.addEventListener('click', function () { goTo(current - 1); });
    if (btnNext)  btnNext.addEventListener('click', function () {
        if (current === total - 1) { close(); return; }
        goTo(current + 1);
    });
    if (skipBtn)  skipBtn.addEventListener('click', close);

    overlay.addEventListener('click', function (e) {
        if (e.target === overlay) close();
    });

    /* Rail items + intro feature cards → jump to section */
    overlay.querySelectorAll('[data-bw-goto]').forEach(function (el) {
        el.addEventListener('click', function () {
            goTo(parseInt(this.getAttribute('data-bw-goto'), 10));
        });
    });

    /* CTA links (final slide) → mark dismissed before navigating away */
    overlay.querySelectorAll('[data-bw-cta]').forEach(function (el) {
        el.addEventListener('click', function () { dismiss(); });
    });

    /* ── Init ──────────────────────────────────────────────────────────────── */
    open();

})();
