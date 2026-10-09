/**
 * BrikPanel - New Order Notification
 *
 * Polls the backend on an interval and renders a slide-in toast for every
 * new order (processing, completed or on-hold) detected since the last seen
 * ID. Settings are read from the localized BrikpanelOrderNotify object
 * printed by PHP.
 *
 * One tab per site and person polls, also while it sits in the background:
 * the tab that was on screen last holds a Web Lock (on http stores, where
 * browsers offer no Web Locks, a short lease in localStorage). It shares new
 * orders with the other tabs, so the sound plays once and each card shows
 * once, in a tab that is on screen.
 *
 * The sound plays the moment an order arrives. The toast and the confetti
 * never land on top of anything: they wait while a window is open (the
 * welcome tour, the BrikMentor announcement, any modal) or while another
 * notification holds the corner, and they come one at a time, never stacked.
 * Waiting toasts are shared by the tabs, so changing page does not lose them.
 */
(function () {
    'use strict';

    if (typeof window.BrikpanelOrderNotify !== 'object') {
        return;
    }

    var cfg = window.BrikpanelOrderNotify;
    var i18n = cfg.i18n || {};
    var intervalSec = Math.max(10, Math.min(300, parseInt(cfg.interval, 10) || 30));
    // 0 is a valid volume (silent), so no "|| 70" here.
    var volumeRaw = parseInt(cfg.volume, 10);
    var volume = Math.max(0, Math.min(1, (isNaN(volumeRaw) ? 70 : volumeRaw) / 100));
    var enablePopup = cfg.popup === '1' || cfg.popup === 1 || cfg.popup === true;
    var enableSound = cfg.sound === '1' || cfg.sound === 1 || cfg.sound === true;
    var enableConfetti = cfg.confetti === '1' || cfg.confetti === 1 || cfg.confetti === true;

    if (!enablePopup && !enableSound && !enableConfetti) {
        return;
    }

    // Subdirectory multisite sites share one browser origin, and two people
    // can share a browser: everything shared is per site and per person.
    var BLOG = parseInt(cfg.blog, 10) || 0;
    var USER = parseInt(cfg.user, 10) || 0;
    var PREFIX = 'brikpanel_on_' + BLOG + '_' + USER + '_';
    var LOCK = 'brikpanel-order-notify-' + BLOG + '-' + USER;
    var TAB_ID = Math.random().toString(36).slice(2) + Date.now().toString(36);
    var MAX_QUEUE = 10;
    var MAX_IDS = 50;
    // A card that waited this long for a tab on screen is old news.
    var QUEUE_MAX_AGE = 24 * 60 * 60 * 1000;
    var LEASE_RENEW = 5000;
    // A tab on screen renews its lease every 5 seconds; browsers wake hidden
    // tabs only about once a minute, so their lease can be a minute old and
    // still be alive. A tab that closes without saying so is replaced after this.
    var LEASE_STALE_VISIBLE = 15000;
    var LEASE_STALE_HIDDEN = 90000;

    // ------------------------------------------------------------------
    // Shared state (localStorage; kept in memory when storage is blocked)
    // ------------------------------------------------------------------

    var memory = {};

    function lsGet(key) {
        try {
            var v = window.localStorage.getItem(PREFIX + key);
            if (v !== null) return v;
        } catch (e) { /* blocked storage: memory only */ }
        return Object.prototype.hasOwnProperty.call(memory, key) ? memory[key] : null;
    }

    function lsSet(key, value) {
        memory[key] = value;
        try { window.localStorage.setItem(PREFIX + key, value); } catch (e) { /* ignore */ }
    }

    function lsRemove(key) {
        delete memory[key];
        try { window.localStorage.removeItem(PREFIX + key); } catch (e) { /* ignore */ }
    }

    function parseJSON(raw, fallback) {
        if (!raw) return fallback;
        try {
            var v = JSON.parse(raw);
            return v == null ? fallback : v;
        } catch (e) { return fallback; }
    }

    function lsJSON(key, fallback) {
        return parseJSON(lsGet(key), fallback);
    }

    function getLastSeen() {
        return parseInt(lsGet('last'), 10) || 0;
    }

    function setLastSeen(id) {
        if (id > getLastSeen()) lsSet('last', String(id));
    }

    function getIds(key) {
        var list = lsJSON(key, []);
        return Array.isArray(list) ? list : [];
    }

    function hasId(key, id) {
        return getIds(key).indexOf(id) !== -1;
    }

    function addIds(key, ids) {
        var list = getIds(key);
        ids.forEach(function (id) {
            if (list.indexOf(id) === -1) list.push(id);
        });
        if (list.length > MAX_IDS) list = list.slice(-MAX_IDS);
        lsSet(key, JSON.stringify(list));
    }

    function removeIds(key, ids) {
        lsSet(key, JSON.stringify(getIds(key).filter(function (id) { return ids.indexOf(id) === -1; })));
    }

    function orderId(order) {
        return parseInt(order && order.id, 10) || 0;
    }

    // Cards waiting for a free screen. Items: { o: order, c: fire confetti, t: queued at }.
    function getQueue() {
        var q = lsJSON('q', []);
        return Array.isArray(q) ? q : [];
    }

    function setQueue(q) {
        if (q.length > MAX_QUEUE) q = q.slice(-MAX_QUEUE);
        if (q.length) lsSet('q', JSON.stringify(q));
        else lsRemove('q');
    }

    function queueHas(id) {
        return getQueue().some(function (item) { return orderId(item && item.o) === id; });
    }

    function currentNonce() {
        return lsGet('nonce') || cfg.nonce || '';
    }

    // Earlier versions kept the last seen order and the waiting cards per tab,
    // without the site in the name. They are dropped, not moved: on a
    // subdirectory multisite they could belong to another site. A new poller
    // only takes a fresh baseline, it never replays old orders.
    try {
        window.sessionStorage.removeItem('brikpanel_order_notify_last_seen');
        window.sessionStorage.removeItem('brikpanel_order_notify_pending');
    } catch (e) { /* ignore */ }

    // ------------------------------------------------------------------
    // Sound
    // ------------------------------------------------------------------

    var audio = null;
    if (enableSound && cfg.soundUrl) {
        try {
            audio = new Audio(cfg.soundUrl);
            audio.preload = 'auto';
            audio.volume = volume;
        } catch (e) { audio = null; }
    }

    var unlocked = false;
    var unlockArmed = false;
    // A touch pointerdown does not count as a media gesture on iPhone; these do.
    var UNLOCK_EVENTS = ['touchend', 'pointerup', 'click', 'keydown'];
    var IGNORED_KEYS = ['Escape', 'Shift', 'Control', 'Alt', 'Meta', 'CapsLock'];

    function onGesture(e) {
        if (e && e.type === 'keydown' && IGNORED_KEYS.indexOf(e.key) !== -1) return;
        disarmUnlock();
        unlockAudio();
    }

    // Browsers (and iPhones above all) let a page play sound only after a tap
    // or a key press. Playing the sound muted inside the first one unlocks it
    // for the order that comes later.
    function armUnlock() {
        if (!audio || unlocked || unlockArmed) return;
        unlockArmed = true;
        UNLOCK_EVENTS.forEach(function (type) {
            document.addEventListener(type, onGesture, { capture: true, passive: true });
        });
    }

    function disarmUnlock() {
        if (!unlockArmed) return;
        unlockArmed = false;
        UNLOCK_EVENTS.forEach(function (type) {
            document.removeEventListener(type, onGesture, { capture: true, passive: true });
        });
    }

    function unlockAudio() {
        if (!audio) return;
        var reset = function () {
            try {
                audio.pause();
                audio.currentTime = 0;
            } catch (e) { /* ignore */ }
            audio.muted = false;
        };
        try {
            audio.muted = true;
            var p = audio.play();
            if (p && typeof p.then === 'function') {
                p.then(function () {
                    reset();
                    unlocked = true;
                }, function () {
                    audio.muted = false;
                    armUnlock();
                });
            } else {
                reset();
                unlocked = true;
            }
        } catch (e) {
            audio.muted = false;
        }
    }

    // Resolves true when the sound really started.
    function playSound() {
        if (!enableSound || !audio) return Promise.resolve(false);
        try {
            audio.muted = false;
            audio.currentTime = 0;
            audio.volume = volume;
            var p = audio.play();
            if (p && typeof p.then === 'function') {
                return p.then(function () {
                    unlocked = true;
                    return true;
                }, function () {
                    // Blocked until the next tap or key press: wait for it.
                    unlocked = false;
                    armUnlock();
                    return false;
                });
            }
            return Promise.resolve(true);
        } catch (e) {
            return Promise.resolve(false);
        }
    }

    var playing = {};

    // Plays once for the orders nobody has heard yet, in any tab. The orders
    // are claimed before the sound starts, so this tab's own queue and the
    // other tabs never play them a second time.
    function playOnce(ids, hop) {
        if (!enableSound || !audio) return;
        var heard = getIds('heard');
        var claim = ids.filter(function (id) { return id !== 0 && heard.indexOf(id) === -1 && !playing[id]; });
        if (!claim.length) return;
        addIds('heard', claim);
        claim.forEach(function (id) { playing[id] = true; });
        playSound().then(function (ok) {
            claim.forEach(function (id) { delete playing[id]; });
            if (ok) return;
            // Not allowed to play here (no tap in this tab yet): give the
            // orders back to a tab on screen that may play them.
            removeIds('heard', claim);
            setTimeout(function () {
                announce('replay', { ids: claim, hop: (hop || 0) + 1 });
            }, 0);
        });
    }

    function isStandalone() {
        try {
            return window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true; // i18n-ignore: media query, not user text
        } catch (e) { return false; }
    }

    // ------------------------------------------------------------------
    // Toast
    // ------------------------------------------------------------------

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

    function itemsText(count) {
        if (window.brikpanelFormat && typeof window.brikpanelFormat.count === 'function' && i18n.items) {
            return window.brikpanelFormat.count(i18n.items, count);
        }
        return String(count);
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
        // dir="auto": an untranslated English text in a right-to-left admin
        // keeps its own order ("New order!", not "!New order").
        var titleText = document.createElement('span');
        titleText.dir = 'auto';
        titleText.textContent = i18n.title || 'New order';
        var subtitle = document.createElement('small');
        subtitle.dir = 'auto';
        subtitle.textContent = fmtText(i18n.subtitle || 'Order #%number%', { number: order.number });
        if (order.awaiting) {
            // A bank transfer waits on-hold: the money is not in yet.
            var tag = document.createElement('span');
            tag.className = 'bp-order-notify__tag';
            tag.textContent = i18n.awaiting || 'Awaiting payment';
            subtitle.appendChild(tag);
        }
        title.appendChild(titleText);
        title.appendChild(subtitle);

        var close = document.createElement('button');
        close.type = 'button';
        close.className = 'bp-order-notify__close';
        close.setAttribute('aria-label', i18n.dismiss || 'Dismiss');
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
            v.dir = 'auto';
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

        body.appendChild(row(i18n.totalLabel || 'Total', order.total, 'value--total'));
        if (order.itemCount) {
            body.appendChild(row(i18n.itemsLabel || 'Items', itemsText(order.itemCount)));
        }
        if (order.customer) {
            body.appendChild(row(i18n.customerLabel || 'Customer', order.customer));
        }
        if (order.payment) {
            body.appendChild(row(i18n.paymentLabel || 'Payment', order.payment));
        }

        var actions = document.createElement('div');
        actions.className = 'bp-order-notify__actions';

        var view = document.createElement('a');
        view.className = 'bp-order-notify__btn bp-order-notify__btn--primary';
        view.href = order.editUrl;
        view.textContent = i18n.view || 'View order';

        var dismiss = document.createElement('button');
        dismiss.type = 'button';
        dismiss.className = 'bp-order-notify__btn bp-order-notify__btn--secondary';
        dismiss.textContent = i18n.dismiss || 'Dismiss';

        actions.appendChild(dismiss);
        actions.appendChild(view);

        var progress = document.createElement('div');
        progress.className = 'bp-order-notify__progress';

        root.appendChild(head);
        root.appendChild(body);
        root.appendChild(actions);
        root.appendChild(progress);

        var hideTimer;
        var gone = false;
        function dismissToast() {
            if (gone) return;
            gone = true;
            if (hideTimer) clearTimeout(hideTimer);
            root.classList.remove('is-visible');
            root.classList.add('is-leaving');
            setTimeout(function () {
                if (root.parentNode) root.parentNode.removeChild(root);
                // The corner is free again: the next waiting order may come in.
                if (current === root) current = null;
                drain();
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

    // The toast on screen; only one at a time.
    var current = null;

    function showToast(order) {
        if (!enablePopup) return;
        var s = getStack();
        var toast = buildToast(order);
        current = toast;
        s.appendChild(toast);
        // Force reflow so the transition runs.
        // eslint-disable-next-line no-unused-expressions
        toast.offsetHeight;
        toast.classList.add('is-visible');
    }

    function isRtl() {
        return document.documentElement.getAttribute('dir') === 'rtl'
            || (document.body && document.body.classList.contains('rtl'));
    }

    function visibleBox(el) {
        if (!el || el.hidden) return null;
        var box = el.getBoundingClientRect();
        if (box.width < 2 || box.height < 2) return null;
        var cs = window.getComputedStyle(el);
        if (cs.visibility === 'hidden' || cs.display === 'none' || parseFloat(cs.opacity) < 0.05) return null;
        return box;
    }

    // A window covers the page: the welcome tour, the BrikMentor
    // announcement, the newsletter window, any other modal, or an open top
    // bar menu. In-page cards never count, or a dashboard left open all day
    // would never get a toast.
    function windowOpen() {
        var html = document.documentElement;
        if (html.classList.contains('brikpanel-welcome-lock') || html.classList.contains('bp-exit-lock')) return true;
        if (document.body && document.body.classList.contains('modal-open')) return true;
        if (document.querySelector('dialog[open]')) return true;
        if (document.querySelector('#brikpanel-topbar .brikpanel-topbar-menu.is-open')) return true;
        var modals = document.querySelectorAll('[aria-modal="true"]');
        for (var i = 0; i < modals.length; i++) {
            if (visibleBox(modals[i])) return true;
        }
        return false;
    }

    function inFixedLayer(el) {
        for (var n = el, depth = 0; n && n !== document.body && depth < 6; n = n.parentElement, depth++) {
            var pos = window.getComputedStyle(n).position;
            if (pos === 'fixed' || pos === 'sticky') return true;
        }
        return false;
    }

    // Another notification (a screen's own toast or snackbar) sits where the
    // order toast would slide in.
    function cornerTaken() {
        var narrow = window.innerWidth <= 600;
        var top = narrow ? 56 : 44;
        var width = narrow ? window.innerWidth - 20 : 360;
        var left = narrow ? 10 : (isRtl() ? 20 : window.innerWidth - 20 - width);
        var right = left + width;
        var bottom = top + 260;
        var stackEl = document.getElementById('brikpanel-order-notify-stack');
        var others = document.querySelectorAll('[class*="toast"], [class*="snack"]');
        for (var i = 0; i < others.length; i++) {
            var el = others[i];
            if (stackEl && stackEl.contains(el)) continue;
            var box = visibleBox(el);
            if (!box || !inFixedLayer(el)) continue;
            if (box.left < right && box.right > left && box.top < bottom && box.bottom > top) return true;
        }
        return false;
    }

    function busy() {
        return windowOpen() || cornerTaken();
    }

    function fireConfetti() {
        if (!enableConfetti || typeof window.confetti !== 'function') return;
        try {
            window.confetti({
                particleCount: 120,
                spread: 75,
                startVelocity: 35,
                origin: { y: 0.7, x: isRtl() ? 0.15 : 0.85 },
                disableForReducedMotion: true,
            });
        } catch (e) { /* ignore */ }
    }

    // ------------------------------------------------------------------
    // Waiting cards: shared by the tabs, shown once, in a tab on screen
    // ------------------------------------------------------------------

    var locks = (navigator.locks && typeof navigator.locks.request === 'function') ? navigator.locks : null;

    function usable(item, now) {
        return !!(item && item.o) && (!item.t || (now - item.t) < QUEUE_MAX_AGE) && !hasId('shown', orderId(item.o));
    }

    // Removes cards that are old or already shown, and takes `wanted` (an
    // order id; the first usable card when omitted).
    function take(wanted) {
        var now = Date.now();
        var item = null;
        var q = getQueue().filter(function (it) {
            if (!usable(it, now)) return false;
            if (!item && (wanted === undefined || orderId(it.o) === wanted)) {
                item = it;
                return false;
            }
            return true;
        });
        setQueue(q);
        if (item) addIds('shown', [orderId(item.o)]);
        return item;
    }

    // Takes the next card nobody has shown yet. Two tabs on screen (two
    // windows side by side) never take the same card.
    function takeNext(done) {
        if (locks) {
            // The lock is held a moment after taking: Firefox runs tabs in
            // separate processes and hands localStorage writes to the others
            // only when the writing task ends.
            locks.request(LOCK + '-q', function () {
                var item = take();
                return new Promise(function (resolve) {
                    setTimeout(function () { resolve(item); }, 60);
                });
            }).then(done, function () { done(null); });
            return;
        }
        // Without Web Locks: claim the card, wait a moment and look again.
        // When two tabs claim the same card at once, the last claim wins.
        var now = Date.now();
        var next = null;
        getQueue().some(function (it) {
            if (usable(it, now)) { next = it; return true; }
            return false;
        });
        if (!next) {
            take(-1);
            done(null);
            return;
        }
        var id = orderId(next.o);
        var stamp = TAB_ID + ':' + id;
        lsSet('claim', stamp);
        setTimeout(function () {
            if (lsGet('claim') !== stamp || hasId('shown', id)) {
                done(null);
                return;
            }
            done(take(id));
        }, 80 + Math.floor(Math.random() * 60));
    }

    var retryTimer = null;
    var taking = false;
    // Show the next waiting order once the screen is free. A hidden tab waits
    // for visibilitychange instead of checking every second.
    function drain() {
        if (retryTimer) { clearTimeout(retryTimer); retryTimer = null; }
        if (current || taking || document.hidden) return;
        if (!getQueue().length) return;
        if (busy()) {
            retryTimer = setTimeout(drain, 1000);
            return;
        }
        taking = true;
        takeNext(function (item) {
            taking = false;
            if (!item) return;
            // The tab that polled may not have been allowed to play (no tap
            // yet): the tab on screen plays it then, once.
            playOnce([orderId(item.o)]);
            if (item.c) fireConfetti();
            if (enablePopup && item.o) {
                showToast(item.o);
            } else {
                drain();
            }
        });
    }

    // ------------------------------------------------------------------
    // Messages between the tabs
    // ------------------------------------------------------------------

    var channel = null;
    try {
        if (typeof BroadcastChannel === 'function') channel = new BroadcastChannel(LOCK);
    } catch (e) { channel = null; }

    function announce(type, data) {
        if (channel) {
            try { channel.postMessage({ type: type, data: data || null }); } catch (e) { /* ignore */ }
            return;
        }
        lsSet('signal', JSON.stringify({ type: type, data: data || null, t: Date.now(), from: TAB_ID }));
    }

    function onSignal(type, data) {
        if (type === 'orders') {
            drain();
        } else if (type === 'poll') {
            pollNow();
        } else if (type === 'replay' && data && Array.isArray(data.ids)) {
            // Another tab could not play: a tab on screen that may play does.
            if (!document.hidden && unlocked && (data.hop || 0) < 2) playOnce(data.ids, data.hop);
        }
    }

    if (channel) {
        channel.onmessage = function (e) {
            if (e && e.data) onSignal(e.data.type, e.data.data);
        };
    }

    // ------------------------------------------------------------------
    // Polling: only the leader tab
    // ------------------------------------------------------------------

    var isLeader = false;
    var stopped = false;
    var pollTimer = null;
    var polling = false;
    var failures = 0;

    function currentInterval() {
        var sec = intervalSec;
        // Three failures in a row: slow down, up to five minutes.
        if (failures >= 3) sec = Math.min(300, intervalSec * Math.pow(2, failures - 2));
        return sec * 1000;
    }

    // A tab that just became the leader waits for the rest of the interval
    // the previous leader started, so switching tabs never adds requests.
    function nextPollDelay() {
        var last = parseInt(lsGet('lastPoll'), 10) || 0;
        var wait = last + currentInterval() - Date.now();
        return Math.max(0, Math.min(wait, currentInterval()));
    }

    function clearPollTimer() {
        if (pollTimer) { clearTimeout(pollTimer); pollTimer = null; }
    }

    function schedulePoll(delay) {
        clearPollTimer();
        if (!isLeader || stopped) return;
        pollTimer = setTimeout(function () { poll(false); }, delay);
    }

    function pollNow() {
        if (isLeader && !stopped) schedulePoll(0);
    }

    function fail() {
        failures++;
        schedulePoll(currentInterval());
    }

    function celebrate(orders) {
        if (!orders || !orders.length) return;
        var ids = orders.map(orderId);
        if (enablePopup || enableConfetti) {
            var q = getQueue();
            var now = Date.now();
            orders.forEach(function (order, i) {
                q.push({ o: order, c: i === 0 ? 1 : 0, t: now });
            });
            setQueue(q);
        }
        // The sound is the alert: it never waits for a free screen.
        playOnce(ids);
        // Told after this task ends, when the other tabs can see the queue.
        setTimeout(function () { announce('orders'); }, 0);
        drain();
    }

    function handle(data) {
        if (data.nonce) lsSet('nonce', String(data.nonce));
        var baseline = parseInt(data.baseline, 10) || 0;
        // First call: just establish the baseline silently.
        if (data.firstRun) {
            setLastSeen(baseline);
            return;
        }
        var fresh = [];
        (Array.isArray(data.orders) ? data.orders : []).forEach(function (order) {
            var id = orderId(order);
            if (id > 0 && !hasId('shown', id) && !queueHas(id)) fresh.push(order);
        });
        if (baseline) setLastSeen(baseline);
        celebrate(fresh);
    }

    function poll(isRetry) {
        if (!isLeader || stopped || polling) return;
        polling = true;
        lsSet('lastPoll', String(Date.now()));

        var nonce = currentNonce();
        var formData = new FormData();
        formData.append('action', 'brikpanel_check_new_orders');
        formData.append('security', nonce);
        formData.append('last_seen', String(getLastSeen()));

        fetch(cfg.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            body: formData,
        })
            .then(function (r) {
                // 400 "0": no session (logged out). 403: expired nonce or no access.
                if (r.status === 400) return { loggedOut: true };
                if (r.status === 403) return { forbidden: true };
                if (!r.ok) throw new Error('http');
                return r.json();
            })
            .then(function (res) {
                polling = false;
                if (res && res.loggedOut) {
                    stop();
                    return;
                }
                if (res && res.forbidden) {
                    // Another tab may hold a newer nonce: try it once.
                    if (!isRetry && currentNonce() !== nonce) {
                        poll(true);
                        return;
                    }
                    fail();
                    return;
                }
                if (!res || !res.success || !res.data) {
                    fail();
                    return;
                }
                failures = 0;
                handle(res.data);
                schedulePoll(currentInterval());
            })
            .catch(function () {
                polling = false;
                fail();
            });
    }

    // ------------------------------------------------------------------
    // Which tab polls
    // ------------------------------------------------------------------

    var waitCtrl = null;    // aborts this tab's place in the lock queue
    var releaseLock = null; // ends this tab's hold of the lock
    var leaseTimer = null;

    function becomeLeader() {
        if (isLeader || stopped) return;
        isLeader = true;
        schedulePoll(nextPollDelay());
    }

    function stepDown() {
        if (!isLeader) return;
        isLeader = false;
        clearPollTimer();
    }

    // Web Locks: a tab coming on screen takes the lock (steal); the others
    // wait in line and take over when the holder closes.
    function requestLock(steal) {
        if (!locks || stopped) return;
        var opts = {};
        var ctrl = null;
        if (steal) {
            opts.steal = true;
        } else if (typeof AbortController === 'function') {
            ctrl = new AbortController();
            opts.signal = ctrl.signal;
            waitCtrl = ctrl;
        }
        locks.request(LOCK, opts, function () {
            if (waitCtrl === ctrl) waitCtrl = null;
            becomeLeader();
            return new Promise(function (resolve) { releaseLock = resolve; });
        }).then(function () {
            stepDown();
        }, function (err) {
            // AbortError: another tab came on screen and took the lock, or
            // this tab gave up its place in the line to take it itself.
            var wasLeader = isLeader;
            stepDown();
            if (wasLeader && !stopped && err && err.name === 'AbortError') {
                requestLock(false);
            }
        });
    }

    // Without Web Locks (http stores): a lease in localStorage, renewed every
    // few seconds. A tab on screen takes it from a tab that is not.
    function leaseTick() {
        if (stopped) return;
        var now = Date.now();
        var lease = lsJSON('lease', null);
        var visible = !document.hidden;
        var mine = lease && lease.id === TAB_ID;
        var free = !lease || (now - (lease.t || 0)) > (lease.v ? LEASE_STALE_VISIBLE : LEASE_STALE_HIDDEN);
        if (mine || free || (visible && !lease.v)) {
            lsSet('lease', JSON.stringify({ id: TAB_ID, t: now, v: visible }));
            becomeLeader();
        } else {
            stepDown();
        }
    }

    function startLease() {
        leaseTick();
        leaseTimer = setInterval(leaseTick, LEASE_RENEW);
    }

    function stop() {
        stopped = true;
        stepDown();
        if (releaseLock) { releaseLock(); releaseLock = null; }
        if (waitCtrl) { waitCtrl.abort(); waitCtrl = null; }
        if (leaseTimer) {
            clearInterval(leaseTimer);
            leaseTimer = null;
            var lease = lsJSON('lease', null);
            if (lease && lease.id === TAB_ID) lsRemove('lease');
        }
    }

    // ------------------------------------------------------------------
    // Events
    // ------------------------------------------------------------------

    document.addEventListener('visibilitychange', function () {
        if (document.hidden) {
            if (!locks) leaseTick();
            return;
        }
        if (locks) {
            if (!isLeader && !stopped) {
                if (waitCtrl) { waitCtrl.abort(); waitCtrl = null; }
                requestLock(true);
            }
        } else {
            leaseTick();
        }
        // A Home Screen app reopened on an iPhone may need a new tap for sound.
        if (isStandalone()) armUnlock();
        drain();
    });

    window.addEventListener('pageshow', function (e) {
        if (!e || !e.persisted) return;
        if (locks && !isLeader && !stopped) requestLock(!document.hidden);
        armUnlock();
        drain();
    });

    window.addEventListener('pagehide', function () {
        if (locks) return;
        var lease = lsJSON('lease', null);
        if (lease && lease.id === TAB_ID) lsRemove('lease');
    });

    window.addEventListener('storage', function (e) {
        if (!e || !e.key || e.key.indexOf(PREFIX) !== 0) return;
        var key = e.key.slice(PREFIX.length);
        if (key === 'q') {
            drain();
        } else if (key === 'signal' && !channel) {
            var s = parseJSON(e.newValue, null);
            if (s && s.from !== TAB_ID) onSignal(s.type, s.data);
        } else if (key === 'lease' && !locks) {
            leaseTick();
        }
    });

    // A phone notification reached this browser (BrikPanel's service
    // worker): check now instead of waiting for the next interval.
    if (navigator.serviceWorker && typeof navigator.serviceWorker.addEventListener === 'function') {
        navigator.serviceWorker.addEventListener('message', function (e) {
            if (!e || !e.data || e.data.type !== 'brikpanel-push') return;
            if (isLeader) pollNow();
            else announce('poll');
        });
    }

    function start() {
        // The nonce printed with this page is the freshest one.
        if (cfg.nonce) lsSet('nonce', String(cfg.nonce));
        if (locks) requestLock(!document.hidden);
        else startLease();
        armUnlock();
        // Orders that were waiting when the previous page closed.
        drain();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }

    // For QA: whether a toast would have to wait right now.
    cfg.busy = busy;

    // For QA: which tab polls and what is shared.
    cfg.state = function () {
        return {
            leader: isLeader,
            mode: locks ? 'locks' : 'lease',
            lastSeen: getLastSeen(),
            queue: getQueue().length,
            failures: failures,
            unlocked: unlocked,
            stopped: stopped,
        };
    };

    // For QA / the service worker: check for new orders now.
    cfg.pollNow = function () {
        if (isLeader) pollNow();
        else announce('poll');
    };

    // Manual trigger for QA / hooks API.
    cfg.test = function (order) {
        var o = order || {
            number: 'TEST',
            total: '$99.00',
            itemCount: 2,
            customer: 'Jane Doe', // i18n-ignore: demo/preview test data for sound notification QA function
            payment: 'Credit card', // i18n-ignore: demo/preview test data for sound notification QA function
            editUrl: '#',
        };
        // A test order gets a one-off id, so "heard" and "shown" never hold it back.
        o.id = orderId(o) || -Date.now();
        celebrate([o]);
    };
})();
