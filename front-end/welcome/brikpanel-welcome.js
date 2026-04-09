/**
 * BrikPanel — Feature Showcase Popup
 *
 * @package BrikPanel
 * @since   2.0.1
 */
(function () {
    'use strict';

    /* ── Refs ──────────────────────────────────────────────────────────────── */
    const overlay    = document.getElementById('brikpanel-welcome-overlay');
    if (!overlay) return;

    const modal      = overlay.querySelector('.brikpanel-welcome-modal');
    const slides     = Array.from(overlay.querySelectorAll('.brikpanel-welcome-slide'));
    const dots       = Array.from(overlay.querySelectorAll('.brikpanel-welcome-dot'));
    const btnPrev    = overlay.querySelector('[data-bw-prev]');
    const btnNext    = overlay.querySelector('[data-bw-next]');
    const btnClose   = overlay.querySelector('.brikpanel-welcome-close');
    const skipBtn    = overlay.querySelector('.brikpanel-welcome-skip');

    let current = 0;
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
        fetch(brikpanelWelcome.ajax_url, { method: 'POST', body: fd, credentials: 'same-origin' });
    }

    /* ── Navigate ──────────────────────────────────────────────────────────── */
    function goTo(idx) {
        if (idx < 0 || idx >= total) return;

        var prev = current;
        current = idx;

        slides.forEach(function (s, i) {
            s.classList.remove('is-active', 'is-exiting-left');
            if (i === prev && prev !== idx) {
                s.classList.add('is-exiting-left');
            }
        });

        requestAnimationFrame(function () {
            slides[current].classList.add('is-active');
        });

        dots.forEach(function (d, i) {
            d.classList.toggle('is-active', i === current);
        });

        /* button states */
        if (btnPrev) btnPrev.style.visibility = current === 0 ? 'hidden' : 'visible';
        if (btnNext) {
            var isLast = current === total - 1;
            btnNext.innerHTML = isLast
                ? (brikpanelWelcome && brikpanelWelcome.i18n ? brikpanelWelcome.i18n.get_started : 'Get Started') + ' <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2"><path d="M7 4l6 6-6 6"/></svg>'
                : (brikpanelWelcome && brikpanelWelcome.i18n ? brikpanelWelcome.i18n.next : 'Next') + ' <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2"><path d="M7 4l6 6-6 6"/></svg>';
        }
        if (skipBtn) skipBtn.style.display = current === total - 1 ? 'none' : '';
    }

    /* ── Keyboard ──────────────────────────────────────────────────────────── */
    function onKey(e) {
        if (e.key === 'Escape') close();
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

    dots.forEach(function (d, i) {
        d.addEventListener('click', function () { goTo(i); });
    });

    /* Feature mini-cards on intro slide → jump to feature */
    overlay.querySelectorAll('[data-bw-goto]').forEach(function (el) {
        el.addEventListener('click', function () {
            goTo(parseInt(this.getAttribute('data-bw-goto'), 10));
        });
    });

    /* ── Init ──────────────────────────────────────────────────────────────── */
    open();

})();
