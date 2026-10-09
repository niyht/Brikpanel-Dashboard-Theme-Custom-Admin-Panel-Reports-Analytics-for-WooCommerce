/**
 * BrikPanel dashboard - "Keep screen on" (phones).
 *
 * A phone left on the counter with the dashboard open plays the cash
 * register sound for every new order, but only while its screen is on. This
 * More menu item asks the browser to keep the screen on (Screen Wake Lock)
 * for as long as the dashboard is open. The choice is remembered on the
 * device; the browser drops the lock when the page is hidden, and it is taken
 * again when the dashboard comes back on screen.
 */
(function () {
    'use strict';

    var cfg = window.brikpanelWake || {};
    var i18n = cfg.i18n || {};
    var btn = document.getElementById('brikpanel-wake-toggle');

    function isPhone() {
        try {
            // A touch screen whose short side is under 600px: phones, not tablets.
            return window.matchMedia('(pointer: coarse)').matches && Math.min(window.screen.width, window.screen.height) < 600; // i18n-ignore: media query, not user text
        } catch (e) {
            return false;
        }
    }

    // Wake Lock exists only on secure (https) pages.
    if (!btn || !('wakeLock' in navigator) || !isPhone()) {
        return;
    }

    var KEY = 'brikpanel_wake_' + (parseInt(cfg.blog, 10) || 0);
    var label = btn.querySelector('.brikpanel-wake-toggle__label');
    var lock = null;
    var wanted = read();
    var waitingForTap = false;

    function read() {
        try {
            return window.localStorage.getItem(KEY) === '1';
        } catch (e) {
            return false;
        }
    }

    function save(on) {
        try {
            if (on) window.localStorage.setItem(KEY, '1');
            else window.localStorage.removeItem(KEY);
        } catch (e) { /* ignore */ }
    }

    function render() {
        btn.setAttribute('aria-pressed', wanted ? 'true' : 'false');
        if (label) label.textContent = wanted ? (i18n.on || '') : (i18n.off || '');
    }

    // Some browsers grant the lock only after a tap: try again on the next one.
    function retryOnTap() {
        if (waitingForTap) return;
        waitingForTap = true;
        document.addEventListener('click', function once() {
            document.removeEventListener('click', once, true);
            waitingForTap = false;
            acquire();
        }, true);
    }

    function acquire() {
        if (!wanted || lock || document.hidden) return;
        navigator.wakeLock.request('screen').then(function (sentinel) {
            if (!wanted) {
                sentinel.release();
                return;
            }
            lock = sentinel;
            lock.addEventListener('release', function () { lock = null; });
        }, function () {
            lock = null;
            retryOnTap();
        });
    }

    function release() {
        if (!lock) return;
        var l = lock;
        lock = null;
        l.release().catch(function () { /* already released */ });
    }

    btn.addEventListener('click', function () {
        wanted = !wanted;
        save(wanted);
        render();
        if (wanted) {
            acquire();
            if (window.brikpanelSnack && i18n.snack) window.brikpanelSnack.show(i18n.snack, { tone: 'success' });
        } else {
            release();
        }
    });

    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) acquire();
    });

    btn.hidden = false;
    render();
    acquire();
})();
