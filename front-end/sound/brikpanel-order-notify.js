/**
 * BrikPanel - New Order Notification
 *
 * Polls the backend on an interval and renders a slide-in toast for every
 * new paid order detected since the last seen ID. Settings are read from
 * the localized BrikpanelOrderNotify object printed by PHP.
 */
(function () {
    'use strict';

    if (typeof window.BrikpanelOrderNotify !== 'object') {
        return;
    }

    var cfg = window.BrikpanelOrderNotify;
    var intervalSec = Math.max(10, Math.min(300, parseInt(cfg.interval, 10) || 30));
    var volume = Math.max(0, Math.min(1, (parseInt(cfg.volume, 10) || 70) / 100));
    var enablePopup = cfg.popup === '1' || cfg.popup === 1 || cfg.popup === true;
    var enableSound = cfg.sound === '1' || cfg.sound === 1 || cfg.sound === true;
    var enableConfetti = cfg.confetti === '1' || cfg.confetti === 1 || cfg.confetti === true;

    if (!enablePopup && !enableSound && !enableConfetti) {
        return;
    }

    // Persist baseline per-tab so a page reload doesn't replay old orders.
    var STORAGE_KEY = 'brikpanel_order_notify_last_seen';
    var lastSeen = 0;
    try {
        var stored = window.sessionStorage.getItem(STORAGE_KEY);
        if (stored) lastSeen = parseInt(stored, 10) || 0;
    } catch (e) { /* sessionStorage may be unavailable */ }

    var audio = null;
    if (enableSound && cfg.soundUrl) {
        try {
            audio = new Audio(cfg.soundUrl);
            audio.preload = 'auto';
            audio.volume = volume;
        } catch (e) { audio = null; }
    }

    var stack = null;
    function getStack() {
        if (stack && document.body.contains(stack)) return stack;
        stack = document.getElementById('brikpanel-order-notify-stack');
        if (!stack) {
            stack = document.createElement('div');
            stack.id = 'brikpanel-order-notify-stack';
            document.body.appendChild(stack);
        }
        return stack;
    }

    function fmtText(template, replacements) {
        return template.replace(/%\w+%/g, function (key) {
            var k = key.slice(1, -1);
            return Object.prototype.hasOwnProperty.call(replacements, k)
                ? replacements[k]
                : key;
        });
    }

    function buildToast(order) {
        var root = document.createElement('div');
        root.className = 'bp-order-notify';
        root.setAttribute('role', 'status');
        root.setAttribute('aria-live', 'polite');
        root.style.setProperty('--bp-notify-duration', '8s');

        var head = document.createElement('div');
        head.className = 'bp-order-notify__head';

        var icon = document.createElement('div');
        icon.className = 'bp-order-notify__icon';
        icon.innerHTML = '<svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">' +
            '<path d="M3 4a1 1 0 011-1h1.6l.55 2.2L7.7 12.5a2 2 0 001.94 1.5H15a2 2 0 001.94-1.5l1.4-5.6a1 1 0 00-.97-1.24H7.1L6.6 4H4a1 1 0 01-1-1zm5 13a1.5 1.5 0 11-3 0 1.5 1.5 0 013 0zm9 0a1.5 1.5 0 11-3 0 1.5 1.5 0 013 0z"/>' +
            '</svg>';

        var title = document.createElement('div');
        title.className = 'bp-order-notify__title';
        var titleText = cfg.i18n.title || 'New order';
        title.innerHTML = '<span></span><small></small>';
        title.querySelector('span').textContent = titleText;
        title.querySelector('small').textContent = fmtText(cfg.i18n.subtitle || 'Order #%number%', { number: order.number });

        var close = document.createElement('button');
        close.type = 'button';
        close.className = 'bp-order-notify__close';
        close.setAttribute('aria-label', cfg.i18n.dismiss || 'Dismiss');
        close.innerHTML = '<svg viewBox="0 0 14 14" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" aria-hidden="true">' +
            '<path d="M2 2l10 10M12 2L2 12"/>' +
            '</svg>';

        head.appendChild(icon);
        head.appendChild(title);
        head.appendChild(close);

        var body = document.createElement('div');
        body.className = 'bp-order-notify__body';

        function row(label, value, valueClass) {
            var r = document.createElement('div');
            r.className = 'bp-order-notify__row';
            var l = document.createElement('span');
            l.className = 'label';
            l.textContent = label;
            var v = document.createElement('span');
            v.className = 'value' + (valueClass ? ' ' + valueClass : '');
            // Total may include currency HTML entities (&pound; &euro;) — use innerHTML for those, escape for others.
            if (valueClass === 'value--total') {
                v.innerHTML = value;
            } else {
                v.textContent = value;
            }
            r.appendChild(l);
            r.appendChild(v);
            return r;
        }

        body.appendChild(row(cfg.i18n.totalLabel || 'Total', order.total, 'value--total'));
        if (order.itemCount) {
            body.appendChild(row(
                cfg.i18n.itemsLabel || 'Items',
                order.itemCount === 1
                    ? (cfg.i18n.itemSingular || '1 item')
                    : fmtText(cfg.i18n.itemPlural || '%count% items', { count: order.itemCount })
            ));
        }
        if (order.customer) {
            body.appendChild(row(cfg.i18n.customerLabel || 'Customer', order.customer));
        }
        if (order.payment) {
            body.appendChild(row(cfg.i18n.paymentLabel || 'Payment', order.payment));
        }

        var actions = document.createElement('div');
        actions.className = 'bp-order-notify__actions';

        var view = document.createElement('a');
        view.className = 'bp-order-notify__btn bp-order-notify__btn--primary';
        view.href = order.editUrl;
        view.textContent = cfg.i18n.view || 'View order';

        var dismiss = document.createElement('button');
        dismiss.type = 'button';
        dismiss.className = 'bp-order-notify__btn bp-order-notify__btn--secondary';
        dismiss.textContent = cfg.i18n.dismiss || 'Dismiss';

        actions.appendChild(dismiss);
        actions.appendChild(view);

        var progress = document.createElement('div');
        progress.className = 'bp-order-notify__progress';

        root.appendChild(head);
        root.appendChild(body);
        root.appendChild(actions);
        root.appendChild(progress);

        var hideTimer;
        function dismissToast() {
            if (hideTimer) clearTimeout(hideTimer);
            root.classList.remove('is-visible');
            root.classList.add('is-leaving');
            setTimeout(function () {
                if (root.parentNode) root.parentNode.removeChild(root);
            }, 350);
        }

        close.addEventListener('click', dismissToast);
        dismiss.addEventListener('click', dismissToast);
        root.addEventListener('mouseenter', function () {
            if (hideTimer) clearTimeout(hideTimer);
            progress.style.animationPlayState = 'paused';
        });
        root.addEventListener('mouseleave', function () {
            progress.style.animationPlayState = 'running';
            hideTimer = setTimeout(dismissToast, 4000);
        });

        hideTimer = setTimeout(dismissToast, 8000);

        return root;
    }

    function showToast(order) {
        if (!enablePopup) return;
        var s = getStack();
        var toast = buildToast(order);
        s.appendChild(toast);
        // Cap stack size — keep latest 3.
        while (s.children.length > 3) {
            s.removeChild(s.firstChild);
        }
        // Force reflow so the transition runs.
        // eslint-disable-next-line no-unused-expressions
        toast.offsetHeight;
        toast.classList.add('is-visible');
    }

    function playSound() {
        if (!enableSound || !audio) return;
        try {
            audio.currentTime = 0;
            audio.volume = volume;
            var p = audio.play();
            if (p && typeof p.catch === 'function') p.catch(function () { /* ignore autoplay block */ });
        } catch (e) { /* ignore */ }
    }

    function fireConfetti() {
        if (!enableConfetti || typeof window.confetti !== 'function') return;
        try {
            window.confetti({
                particleCount: 120,
                spread: 75,
                startVelocity: 35,
                origin: { y: 0.7, x: 0.85 },
                disableForReducedMotion: true,
            });
        } catch (e) { /* ignore */ }
    }

    function celebrate(orders) {
        if (!orders || !orders.length) return;
        playSound();
        fireConfetti();
        // Stagger toasts slightly so they animate in sequence.
        orders.forEach(function (order, i) {
            setTimeout(function () { showToast(order); }, i * 250);
        });
    }

    function persistLastSeen(id) {
        if (!id || id <= lastSeen) return;
        lastSeen = id;
        try { window.sessionStorage.setItem(STORAGE_KEY, String(id)); } catch (e) { /* ignore */ }
    }

    function poll() {
        var formData = new FormData();
        formData.append('action', 'brikpanel_check_new_orders');
        formData.append('security', cfg.nonce);
        formData.append('last_seen', String(lastSeen));

        fetch(cfg.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            body: formData,
        })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (res) {
                if (!res || !res.success || !res.data) return;
                var data = res.data;
                // First call: just establish the baseline silently.
                if (data.firstRun) {
                    persistLastSeen(parseInt(data.baseline, 10) || 0);
                    return;
                }
                if (data.orders && data.orders.length) {
                    celebrate(data.orders);
                }
                if (data.baseline) {
                    persistLastSeen(parseInt(data.baseline, 10) || 0);
                }
            })
            .catch(function () { /* network errors are silent */ });
    }

    var pollTimer = null;
    function startPolling() {
        if (pollTimer) return;
        poll();
        pollTimer = setInterval(poll, intervalSec * 1000);
    }
    function stopPolling() {
        if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
    }

    document.addEventListener('visibilitychange', function () {
        if (document.hidden) stopPolling();
        else startPolling();
    });

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', startPolling);
    } else {
        startPolling();
    }

    // Manual trigger for QA / hooks API.
    window.BrikpanelOrderNotify.test = function (order) {
        celebrate([order || {
            id: 0,
            number: 'TEST',
            total: '$99.00',
            itemCount: 2,
            customer: 'Jane Doe', // i18n-ignore: demo/preview test data for sound notification QA function
            payment: 'Credit card', // i18n-ignore: demo/preview test data for sound notification QA function
            editUrl: '#',
        }]);
    };
})();
