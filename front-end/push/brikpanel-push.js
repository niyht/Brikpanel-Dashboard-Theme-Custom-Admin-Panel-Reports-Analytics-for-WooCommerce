/**
 * BrikPanel - phone notifications in the browser.
 *
 * - Works out what this browser can do and tells the server in a cookie
 *   (brikpanel_phone_<blog>, phones only): a = can turn notifications on,
 *   i = iPhone browser tab (Add to Home Screen first), o = on, d = blocked,
 *   u = cannot. The dashboard card takes its turn from it, and the top bar's
 *   phone button shows from it.
 * - The dashboard card, the phone button's panel and "Your devices" in
 *   Settings: turning notifications on (the subscribe call is the first thing
 *   the tap does: iPhones allow the question only inside a tap), a test,
 *   removing a device. The panel opens by itself on every page the server
 *   allows it (window.brikpanelPushAuto) until its X is pressed and confirmed.
 * - Keeps them alive: once per visit, and after an hour away, it checks the
 *   subscription. A lost one or one made with old keys is made again (where
 *   the browser needs a tap, a line asks for it); the server learns about a
 *   new address; a device removed elsewhere stops here too.
 *
 * Settings come from wp_localize_script as window.brikpanelPush.
 */
(function (window, document) {
	'use strict';

	var C = window.brikpanelPush;
	if (!C || !window.fetch || !window.Promise) {
		return;
	}
	var L = C.i18n || {};
	var nav = window.navigator;
	var ua = nav.userAgent || '';
	var mm = function (q) {
		try {
			return !!(window.matchMedia && window.matchMedia(q).matches);
		} catch (e) {
			return false;
		}
	};
	var isIOS = /iP(hone|od|ad)/.test(ua) || (nav.platform === 'MacIntel' && nav.maxTouchPoints > 1);
	var isAndroid = !isIOS && /Android/i.test(ua);
	var standalone = mm('(display-mode: standalone)') || nav.standalone === true; // i18n-ignore: media query, not user text
	var screenMin = Math.min(window.screen ? window.screen.width : 0, window.screen ? window.screen.height : 0) || 0;
	var phone = mm('(pointer: coarse)') && screenMin > 0 && screenMin < 600;
	var secure = !!window.isSecureContext;
	var supported = secure && 'serviceWorker' in nav && 'PushManager' in window && 'Notification' in window;
	var LS = 'brikpanel_push_' + C.blog;
	var state = { reg: null, sub: null, letter: '' };

	/* ── What this browser is ─────────────────────────────────────────────── */

	function iosVersion() {
		var m = ua.match(/OS (\d+)[_.](\d+)/);
		return m ? parseFloat(m[1] + '.' + m[2]) : 0;
	}

	function osName() {
		if (isIOS) {
			return 'ios';
		}
		if (/Android/i.test(ua)) {
			return 'android';
		}
		if (/CrOS/.test(ua)) {
			return 'chromeos';
		}
		if (/Windows/.test(ua)) {
			return 'windows';
		}
		if (/Mac OS X|Macintosh/.test(ua)) {
			return 'mac';
		}
		return /Linux/.test(ua) ? 'linux' : '';
	}

	function browserName() {
		// Brave says it is Chrome in its user agent; only this object tells them apart.
		if (nav.brave && typeof nav.brave.isBrave === 'function') {
			return 'brave';
		}
		if (/EdgA?\/|Edg\//.test(ua)) {
			return 'edge';
		}
		if (/SamsungBrowser/.test(ua)) {
			return 'samsung';
		}
		if (/OPR\//.test(ua)) {
			return 'opera';
		}
		if (/Firefox|FxiOS/.test(ua)) {
			return 'firefox';
		}
		if (/Chrome|CriOS/.test(ua)) {
			return 'chrome';
		}
		return /Safari/.test(ua) ? 'safari' : '';
	}

	/** The letter before the subscription is known ('o' comes later). */
	function baseLetter() {
		if (!secure) {
			return 'u';
		}
		if (isIOS && !standalone) {
			var v = iosVersion();
			return v && v < 16.4 ? 'u' : 'i';
		}
		if (!supported || /EdgA\//.test(ua)) {
			return 'u';
		}
		return Notification.permission === 'denied' ? 'd' : 'a';
	}

	/** Why notifications cannot work here, in a sentence. */
	function reason(letter) {
		if (letter === 'i') {
			return L.iosTab || '';
		}
		if (letter === 'd') {
			return (isIOS ? L.blockedIos : L.blocked) || '';
		}
		if (letter === 'o') {
			return L.already || '';
		}
		if (letter === 'u') {
			if (!secure) {
				return L.insecure || '';
			}
			// Edge, an app's own browser, any Android browser without them: Chrome has them.
			if (isAndroid) {
				return L.edgeAndroid || L.unsupported || '';
			}
			if (isIOS && iosVersion() && iosVersion() < 16.4) {
				return L.iosOld || '';
			}
			return L.unsupported || '';
		}
		return '';
	}

	/**
	 * The letter the server hears before this visit's check has run. A phone
	 * whose notifications were turned on here stays 'o' (the check repairs a
	 * lost subscription by itself, or the dashboard's repair line asks for a
	 * tap): before, every page view said 'a' again, so the server offered the
	 * card and the top bar's phone button to a phone that was already on.
	 */
	function startLetter(letter) {
		if (letter === 'a' && Notification.permission === 'granted' && local().on) {
			return 'o';
		}
		return letter;
	}

	function setCookie(letter) {
		var name = 'brikpanel_phone_' + C.blog;
		var tail = '; path=' + C.scope + '; samesite=lax' + (window.location.protocol === 'https:' ? '; secure' : ''); // i18n-ignore: cookie attributes
		try {
			document.cookie = phone && letter
				? name + '=' + letter + tail + '; max-age=2592000' // i18n-ignore: cookie attribute
				: name + '=' + tail + '; max-age=0'; // i18n-ignore: cookie attribute
		} catch (e) { /* cookies off */ }
	}

	function local(value) {
		try {
			if (value === undefined) {
				return JSON.parse(window.localStorage.getItem(LS) || '{}') || {};
			}
			if (value === null) {
				window.localStorage.removeItem(LS);
			} else {
				window.localStorage.setItem(LS, JSON.stringify(value));
			}
		} catch (e) {
			return {};
		}
		return value;
	}

	/**
	 * Android, in a browser tab: whether BrikPanel is installed on this phone
	 * right now: true, false, or null where this browser cannot tell (no
	 * answer in time, or no such question: only Chrome answers it, because the
	 * manifest names the app itself for navigator.getInstalledRelatedApps()).
	 */
	function appCheck() {
		if (typeof nav.getInstalledRelatedApps !== 'function') {
			return Promise.resolve(null);
		}
		var timer = 0;
		return Promise.race([
			Promise.resolve(nav.getInstalledRelatedApps()).then(function (apps) {
				return (apps || []).some(function (app) {
					return !!app && app.platform === 'webapp';
				});
			}),
			new Promise(function (resolve) {
				timer = window.setTimeout(function () {
					resolve(null);
				}, 800);
			})
		]).catch(function () {
			return null;
		}).then(function (installed) {
			window.clearTimeout(timer);
			return installed;
		});
	}

	/**
	 * Android: BrikPanel was installed from, or opened as an app in, this
	 * browser (the app shares its storage). Only "it was here", never "it is
	 * here": with the browser's answer it tells a removed app from a phone that
	 * never had one (owner, 28 October 2026: "Ben uygulamayı yükledim. Sonra
	 * sildim. Sonra tarayıcıdan tekrar girdim").
	 */
	var APP = 'brikpanel_app_' + C.blog;
	function appSeen(value) {
		try {
			if (value === undefined) {
				return !!window.localStorage.getItem(APP);
			}
			if (value) {
				window.localStorage.setItem(APP, String(Date.now()));
			} else {
				window.localStorage.removeItem(APP);
			}
		} catch (e) { /* storage off */ }
		return !!value;
	}

	/**
	 * Tells the phone button and the dashboard card that this phone's
	 * notifications just went on or off (a save, the visit's check, a removal).
	 */
	function announce(on) {
		try {
			window.dispatchEvent(new CustomEvent('brikpanel-push-state', { detail: { on: !!on } }));
		} catch (e) { /* an old browser: the next page shows it */ }
	}

	/* ── Server ───────────────────────────────────────────────────────────── */

	function post(action, data) {
		var body = new URLSearchParams();
		body.set('action', action);
		body.set('nonce', C.nonce);
		Object.keys(data || {}).forEach(function (k) {
			body.set(k, data[k]);
		});
		return fetch(C.ajax, { method: 'POST', body: body, credentials: 'same-origin' }).then(function (r) {
			return r.json().catch(function () {
				return { success: false, data: { reason: 'http' + r.status } };
			});
		});
	}

	function snack(text, tone) {
		if (text && window.brikpanelSnack && window.brikpanelSnack.show) {
			window.brikpanelSnack.show(text, { tone: tone || 'success' });
		}
	}

	function format(text, value) {
		return String(text || '').replace('%s', value);
	}

	/** Every %s in a sentence that names the same thing twice. */
	function formatAll(text, value) {
		return String(text || '').split('%s').join(value);
	}

	/**
	 * Android: Chrome (or Samsung Internet) shows a site's notifications from a
	 * background job, and Android runs those late while the phone sleeps unless
	 * the browser may run in the background. Fills the card's last step.
	 */
	function batteryStep(box) {
		if (!box || !isAndroid) {
			return;
		}
		var browser = browserName();
		var names = { samsung: L.samsung, brave: L.brave, opera: L.opera };
		var name = names[browser] || L.chrome;
		var title = box.querySelector('.brikpanel-push-card__tip-title');
		var text = box.querySelector('[data-bp-push-battery-text]');
		var path = box.querySelector('[data-bp-push-battery-path]');
		if (browser === 'firefox') {
			// Mozilla's push server hands Firefox its messages at normal priority
			// (autopush-rs, fcm/client.rs): no battery setting brings them sooner.
			if (title && L.firefoxTitle) {
				title.textContent = L.firefoxTitle;
			}
			if (text) {
				text.textContent = L.firefoxBody || '';
			}
			if (path) {
				path.hidden = true;
			}
			box.hidden = !L.firefoxBody;
			return;
		}
		if (text) {
			text.textContent = formatAll(L.battery, name);
		}
		if (path) {
			path.textContent = formatAll(L.batteryPath, name);
		}
		box.hidden = !L.battery;
	}

	/* ── Subscription ─────────────────────────────────────────────────────── */

	function keyBytes() {
		var s = String(C.key || '');
		var pad = '='.repeat((4 - (s.length % 4)) % 4);
		var raw = window.atob((s + pad).replace(/-/g, '+').replace(/_/g, '/'));
		var out = new Uint8Array(raw.length);
		for (var i = 0; i < raw.length; i++) {
			out[i] = raw.charCodeAt(i);
		}
		return out;
	}

	function sameKey(sub) {
		var have = sub && sub.options && sub.options.applicationServerKey;
		if (!have) {
			return true;
		}
		var a = new Uint8Array(have);
		var b = keyBytes();
		if (a.length !== b.length) {
			return false;
		}
		for (var i = 0; i < a.length; i++) {
			if (a[i] !== b[i]) {
				return false;
			}
		}
		return true;
	}

	var regPromise = null;
	function register() {
		if (!supported) {
			return Promise.reject(new Error('unsupported'));
		}
		if (!regPromise) {
			var ready = C.shared
				? nav.serviceWorker.ready
				: nav.serviceWorker.register(C.worker, { scope: C.scope, updateViaCache: 'all' }).then(function () {
					return nav.serviceWorker.getRegistration(C.scope);
				}).then(function (reg) {
					return reg && reg.active ? reg : nav.serviceWorker.ready;
				});
			regPromise = ready.then(function (reg) {
				state.reg = reg;
				return reg;
			});
			regPromise.catch(function () {
				regPromise = null;
			});
		}
		return regPromise;
	}

	function save(sub, ctx, replaces) {
		var j = sub.toJSON ? sub.toJSON() : {};
		var keys = j.keys || {};
		return post('brikpanel_push_save', {
			ctx: ctx,
			endpoint: j.endpoint || sub.endpoint,
			p256dh: keys.p256dh || '',
			auth: keys.auth || '',
			os: osName(),
			browser: browserName(),
			standalone: standalone ? 1 : 0,
			phone: phone ? 1 : 0,
			replaces: replaces || ''
		}).then(function (res) {
			if (!res || !res.success) {
				throw new Error((res && res.data && res.data.reason) || 'save');
			}
			if (res.data.status === 'active') {
				local({ on: true, ep: sub.endpoint, sync: Date.now(), id: res.data.id, hash: res.data.hash });
				state.sub = sub;
				state.letter = 'o';
				setCookie('o');
				announce(true);
			}
			return res.data;
		});
	}

	/**
	 * Turns notifications on. The subscribe call runs first, still inside the
	 * tap: an iPhone asks for permission only there.
	 */
	function turnOn(ctx) {
		var p;
		if (state.reg && state.reg.pushManager) {
			try {
				p = state.reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: keyBytes() });
			} catch (e) {
				p = Promise.reject(e);
			}
		} else {
			p = register().then(function (reg) {
				return reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: keyBytes() });
			});
		}
		return p.then(function (sub) {
			return save(sub, ctx, local().hash || '');
		});
	}

	/** The second tap after "Tap again": the plain permission question first. */
	function askThenTurnOn(ctx) {
		return Notification.requestPermission().then(function (answer) {
			if (answer !== 'granted') {
				var err = new Error('denied');
				err.name = 'NotAllowedError';
				throw err;
			}
			return turnOn(ctx);
		});
	}

	function failure(err) {
		if (err && err.name === 'NotAllowedError') {
			return Notification.permission === 'denied' ? 'denied' : 'again';
		}
		return 'error';
	}

	/** Make a lost or outdated subscription again; a line asks for a tap where needed. */
	function repair(oldSub) {
		var replaces = local().hash || '';
		var first = oldSub ? oldSub.unsubscribe().catch(function () {}) : Promise.resolve();
		return first.then(function () {
			return state.reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: keyBytes() });
		}).then(function (sub) {
			return save(sub, 'repair', replaces);
		}).catch(function () {
			announce(false);
			showRepair();
			return null;
		});
	}

	/** Once per visit and after an hour away: is this device still on? */
	function check() {
		if (!supported || !C.on || !C.key || (isIOS && !standalone)) {
			return Promise.resolve(null);
		}
		var l = local();
		if (Notification.permission !== 'granted') {
			if (l.on && Notification.permission === 'denied') {
				local(null);
				announce(false);
			} else if (l.on) {
				// On here before, now asked for again: on Android the app installed
				// after notifications were turned on in the browser has its own
				// permission, which starts off. One tap gives it.
				announce(false);
				register().then(showRepair, function () {});
			}
			return Promise.resolve(null);
		}
		return nav.serviceWorker.getRegistration(C.scope).then(function (reg) {
			if (reg) {
				state.reg = reg;
				// A BrikPanel update brought a new worker: take it now, not at the
				// browser's own daily check (its address carries the version).
				var have = reg.installing || reg.waiting || reg.active;
				var want = '';
				try {
					want = new URL(C.worker, window.location.href).href;
				} catch (e) { /* old browser: the daily check */ }
				if (!C.shared && want && have && have.scriptURL && have.scriptURL !== want) {
					return register();
				}
				return reg;
			}
			return l.on ? register() : null;
		}).then(function (reg) {
			if (!reg) {
				return null;
			}
			return reg.pushManager.getSubscription().then(function (sub) {
				if (!sub) {
					return l.on ? repair(null) : null;
				}
				state.sub = sub;
				state.letter = 'o';
				if (!sameKey(sub)) {
					return repair(sub);
				}
				if (sub.endpoint !== l.ep || !l.sync || Date.now() - l.sync > 86400000) {
					return save(sub, 'sync').then(function (d) {
						if (d.status === 'removed') {
							state.sub = null;
							state.letter = 'a';
							local(null);
							announce(false);
							return sub.unsubscribe().catch(function () {}).then(function () {
								return null;
							});
						}
						if (d.status === 'gone') {
							return repair(sub);
						}
						return sub;
					});
				}
				return sub;
			});
		}).catch(function () {
			return null;
		});
	}

	/** This page view's check: the card and the visit share one. */
	var checkRun = null;
	function checkOnce() {
		if (!checkRun) {
			checkRun = check();
		}
		return checkRun;
	}

	/* ── The repair line (dashboard) ──────────────────────────────────────── */

	function showRepair() {
		var line = document.querySelector('[data-bp-push-repair]');
		if (!line) {
			return;
		}
		// Never next to the phone button's open panel: it offers the same tap.
		if (document.querySelector('[data-bp-push-menu].is-open')) {
			return;
		}
		// Never next to an ask on screen.
		var asks = document.querySelectorAll('.brikpanel-ask');
		for (var i = 0; i < asks.length; i++) {
			if (!asks[i].hidden && asks[i].offsetParent !== null) {
				return;
			}
		}
		line.hidden = false;
	}

	function bindRepair() {
		var line = document.querySelector('[data-bp-push-repair]');
		var btn = line && line.querySelector('[data-bp-push-repair-on]');
		if (!btn) {
			return;
		}
		btn.addEventListener('click', function () {
			btn.disabled = true;
			var replaces = local().hash || '';
			var p;
			try {
				p = state.reg ? state.reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: keyBytes() }) : register().then(function (reg) {
					return reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: keyBytes() });
				});
			} catch (e) {
				p = Promise.reject(e);
			}
			p.then(function (sub) {
				return save(sub, 'repair', replaces);
			}).then(function () {
				line.hidden = true;
				snack(L.on);
			}).catch(function (err) {
				snack(failure(err) === 'denied' ? reason('d') : format(L.failed, err && err.message ? err.message : ''), 'error');
			}).then(function () {
				btn.disabled = false;
			});
		});
	}

	/* ── The dashboard card and the top bar's phone button ─────────────────── */

	function closeAsk(card) {
		var body = new URLSearchParams();
		body.set('action', 'brikpanel_ask_close');
		body.set('_ajax_nonce', C.askNonce);
		body.set('ask', 'push');
		try {
			fetch(C.ajax, { method: 'POST', body: body, credentials: 'same-origin', keepalive: true }).catch(function () {});
		} catch (e) { /* ignore */ }
		if (card && card.parentNode) {
			card.parentNode.removeChild(card);
		}
	}

	/**
	 * The phone button leaves the top bar where it has no use at all: not a
	 * phone, or the store cannot send. Otherwise it stays (owner, 28 October
	 * 2026), also once this phone is on: it then shows that, with a test.
	 * Nothing in its panel takes it away ("hiçbir zaman kaybolmasın o buton").
	 */
	function dropMenu() {
		var menu = document.querySelector('[data-bp-push-menu]');
		if (menu && menu.parentNode) {
			menu.parentNode.removeChild(menu);
		}
	}

	/** Turned on from the top bar: the dashboard card has nothing left to offer here. */
	function dropCard() {
		var card = document.querySelector('[data-bp-push-card]');
		if (card && card.parentNode) {
			card.parentNode.removeChild(card);
		}
	}

	/**
	 * The steps that turn notifications on. kind 'card': the dashboard card, an
	 * ask (its X, or finding the phone already on, ends its turn). kind 'menu':
	 * the panel of the top bar's phone button, outside the ask line, on every
	 * phone: it opens by itself where notifications can still be turned on and
	 * the server allows it, and there its X first asks "Are you sure?" in the
	 * panel. Closing it never takes the button away. With notifications on it
	 * says so (a tick on the button, a test in the panel); a phone whose
	 * notifications are blocked, or whose browser cannot get them, it tells
	 * what to do.
	 */
	function bindOffer(card, kind) {
		var menu = kind === 'menu';
		var views = card.querySelectorAll('[data-bp-push-view]');
		var on = card.querySelector('[data-bp-push-on]');
		var test = card.querySelector('[data-bp-push-card-test]');
		var install = card.querySelector('[data-bp-push-install]');
		var here = card.querySelector('[data-bp-push-here]');
		var hint = menu ? card.querySelector('[data-bp-push-hint]') : null;
		var removed = card.querySelector('[data-bp-push-removed]');
		var removedAgain = card.querySelector('[data-bp-push-removed-again]');
		var removedHere = card.querySelector('[data-bp-push-removed-here]');
		var appSure = card.querySelector('[data-bp-push-app-sure]');
		var appUnsure = card.querySelector('[data-bp-push-app-unsure]');
		var label = on ? on.textContent : '';
		var installLabel = install ? install.textContent : '';
		var again = false;
		var current = '';
		var browserChosen = false;
		// 'app' view: the browser says it is installed (sure), or cannot tell.
		var sure = true;
		function view(name) {
			current = name;
			// The app was removed: installing it again comes first where the browser
			// can (its install offer is back), as on a phone that never had it.
			var reinstall = name === 'removed' && canInstall();
			for (var i = 0; i < views.length; i++) {
				views[i].hidden = views[i].getAttribute('data-bp-push-view') !== name;
			}
			if (on) {
				on.hidden = name !== 'ask' && (name !== 'removed' || reinstall);
			}
			if (removedAgain) {
				removedAgain.hidden = !reinstall;
			}
			if (removedHere) {
				removedHere.hidden = reinstall;
			}
			if (appSure) {
				appSure.hidden = !sure;
			}
			if (appUnsure) {
				appUnsure.hidden = sure;
			}
			if (removed) {
				removed.hidden = name !== 'app' || sure;
			}
			if (install) {
				install.hidden = name !== 'install' && !reinstall;
			}
			if (here) {
				here.hidden = name !== 'install' && !reinstall;
			}
			if (test) {
				test.hidden = name !== 'done' || !local().id;
			}
		}
		// Android in a browser tab with the install offer at hand: the app first
		// (the permission must be the app's own, see brikpanel_push_card_render()).
		function canInstall() {
			return isAndroid && !standalone && !!(window.brikpanelInstallPrompt && window.brikpanelInstallPrompt.prompt);
		}
		function blocked() {
			var text = card.querySelector('[data-bp-push-blocked]');
			if (text) {
				text.textContent = reason('d');
			}
			view('blocked');
		}
		// The steps for a phone without notifications, from what the browser says now.
		function offView() {
			var now = baseLetter();
			if (now === 'd') {
				blocked();
			} else if (now === 'u') {
				var why = card.querySelector('[data-bp-push-cannot]');
				if (why) {
					why.textContent = reason('u');
				}
				view('cannot');
			} else {
				view(now === 'i' ? 'ios' : (canInstall() ? 'install' : 'ask'));
			}
		}
		// On: what the panel shows from then on, the button's tick with it.
		function onView() {
			view('done');
			var note = card.querySelector('[data-bp-push-note]');
			if (note) {
				note.textContent = L.onPhone || '';
			}
			batteryStep(card.querySelector('[data-bp-push-battery]'));
			if (menu) {
				card.classList.add('is-on');
			}
		}
		var letter = baseLetter();
		var isOn = startLetter(letter) === 'o';
		// The card offers only where notifications can be turned on; the button stays on every phone.
		var offered = menu || letter === 'a' || letter === 'i';
		if (!phone || !C.on || !offered || (!menu && isOn)) {
			if (menu) {
				dropMenu();
			} else {
				card.hidden = true;
			}
			return;
		}
		if (!menu) {
			card.hidden = false;
		}
		if (isOn) {
			onView();
		} else {
			if (menu) {
				card.classList.remove('is-on');
			}
			offView();
		}
		// On or off elsewhere on this page (Settings, the dashboard card, the visit's check).
		window.addEventListener('brikpanel-push-state', function (e) {
			var nowOn = !!(e.detail && e.detail.on);
			if (nowOn && current !== 'done') {
				onView();
			} else if (!nowOn && current === 'done') {
				if (menu) {
					card.classList.remove('is-on');
				}
				offView();
			}
		});
		function untouched() {
			return !browserChosen && !again && !!on && !on.disabled;
		}
		// Chrome can make the offer after the page has loaded. It makes none for an
		// installed app, so BrikPanel is not (or no longer) on this phone: where it
		// was, the panel says it was removed and offers it again.
		window.addEventListener('brikpanel-install-ready', function () {
			if ((current === 'ask' || current === 'app' || current === 'install' || current === 'removed') && untouched() && canInstall()) {
				view(appSeen() ? 'removed' : 'install');
			}
		});
		// Installed (from the card or from Chrome's menu) before turning them on:
		// the next step is inside the app.
		window.addEventListener('appinstalled', function () {
			if (!isAndroid || standalone) {
				return;
			}
			appSeen(true);
			if (current === 'install' || current === 'removed' || (current === 'ask' && on && !on.disabled)) {
				view('installed');
			}
		});
		// Back in the tab: the browser is asked whether the app is installed, so a
		// panel that opens by itself opens on the right step. Installed: the app,
		// never this browser. Gone after it was here: "removed", with the app again
		// where the browser can install it, else this browser. It was here and this
		// browser cannot tell: the app, with a link for a person who removed it.
		var tab = isAndroid && !standalone && !isOn && letter === 'a';
		var known = tab ? appCheck() : Promise.resolve(undefined);
		known.then(function (installed) {
			if (installed === undefined || (current !== 'ask' && current !== 'install') || !untouched()) {
				return;
			}
			if (installed === true) {
				sure = true;
				view('app');
			} else if (appSeen()) {
				if (installed === false || canInstall()) {
					view('removed');
				} else {
					sure = false;
					view('app');
				}
			}
		});
		if (removed) {
			removed.addEventListener('click', function () {
				appSeen(false);
				view('ask');
			});
		}
		// Ready before the tap, so the tap itself can ask (iPhones allow it only
		// there). The card is on screen; the button's panel gets ready when it
		// opens, so the pages a phone opens without using it register nothing.
		var ready = Promise.resolve();
		if (letter === 'a') {
			var toggle = menu ? card.querySelector('[data-topbar-toggle]') : null;
			if (toggle) {
				toggle.addEventListener('click', function () {
					register().catch(function () {});
				});
			} else if (!menu) {
				ready = register().catch(function () {});
			}
		}

		var x = menu ? null : card.querySelector('[data-bp-ask-close]');
		if (x) {
			x.addEventListener('click', function () {
				closeAsk(card);
			});
		}

		// The panel opens by itself (brikpanel_push_menu_auto()) where notifications
		// can still be turned on: on every page, or after an X confirmed in a browser
		// tab only inside the installed app. A tap outside, Escape or the button put
		// it away for this page only.
		var toggleBtn = menu ? card.querySelector('[data-topbar-toggle]') : null;
		var auto = window.brikpanelPushAuto;
		var asked = menu && !isOn && letter !== 'u' && (auto === 'all' || (auto === 'app' && standalone));
		// Where its X was confirmed: 'tab' (browser tabs) or 'app' (everywhere).
		var off = C.off === 'app' || C.off === 'tab' ? C.off : '';
		function closedHere() {
			return off === 'app' || (off === 'tab' && !standalone);
		}
		if (asked) {
			if (hint) {
				hint.hidden = false;
			}
			if (letter === 'a') {
				register().catch(function () {});
			}
			known.then(function () {
				if (!asked) {
					return;
				}
				card.classList.add('is-open');
				if (toggleBtn) {
					toggleBtn.setAttribute('aria-expanded', 'true');
				}
			});
		}

		// "Are you sure?" in the panel itself, in place of the steps.
		var steps = menu ? card.querySelector('[data-bp-push-steps]') : null;
		var confirmBox = menu ? card.querySelector('[data-bp-push-confirm]') : null;
		var yes = confirmBox ? confirmBox.querySelector('[data-bp-push-confirm-yes]') : null;
		var no = confirmBox ? confirmBox.querySelector('[data-bp-push-confirm-no]') : null;
		var closeX = menu ? card.querySelector('[data-bp-push-menu-close]') : null;
		var confirming = false;
		function confirmShow() {
			confirming = true;
			steps.hidden = true;
			confirmBox.hidden = false;
			if (no) {
				no.focus();
			}
		}
		function confirmHide() {
			confirming = false;
			if (confirmBox) {
				confirmBox.hidden = true;
			}
			if (steps) {
				steps.hidden = false;
			}
		}
		function closePanel() {
			var inside = card.contains(document.activeElement);
			card.classList.remove('is-open');
			if (toggleBtn) {
				toggleBtn.setAttribute('aria-expanded', 'false');
				if (inside) {
					toggleBtn.focus();
				}
			}
		}
		// Put away in any way: the next opening starts at the steps again.
		if (confirmBox && window.MutationObserver) {
			new MutationObserver(function () {
				if (confirming && !card.classList.contains('is-open')) {
					confirmHide();
				}
			}).observe(card, { attributes: true, attributeFilter: ['class'] });
		}
		if (closeX) {
			closeX.addEventListener('click', function () {
				// For good only after the question. Once they are on, where this
				// browser cannot get them, once it was closed for good here, or a
				// second X on the question: it just closes.
				if (!confirmBox || confirming || current === 'done' || current === 'cannot' || closedHere()) {
					closePanel();
					return;
				}
				confirmShow();
			});
		}
		if (yes) {
			yes.addEventListener('click', function () {
				// Closed in a browser tab, it still opens inside the installed app,
				// where notifications are turned on; closed in the app, nowhere.
				var where = standalone ? 'app' : 'tab';
				post('brikpanel_push_auto_off', { where: where, shown: asked ? 1 : 0 }).then(function (res) {
					if (!res || !res.success) {
						throw new Error('off');
					}
				}).catch(function () {
					snack(L.error, 'error');
				});
				off = where === 'app' || off === 'app' ? 'app' : 'tab';
				asked = false;
				closePanel();
			});
		}
		if (no) {
			no.addEventListener('click', function () {
				confirmHide();
				if (closeX) {
					closeX.focus();
				}
			});
		}
		if (on) {
			on.addEventListener('click', function () {
				on.disabled = true;
				on.textContent = L.turning || label;
				// In front of the person as the offer ('card'), the server ends its turn.
				var ctx = menu && !asked ? 'menu' : 'card';
				(again ? askThenTurnOn(ctx) : turnOn(ctx)).then(function () {
					asked = false;
					onView();
					// The dashboard card has nothing left to offer; the button keeps its tick.
					if (menu) {
						dropCard();
					}
				}).catch(function (err) {
					var why = failure(err);
					if (why === 'denied') {
						blocked();
						setCookie('d');
						return;
					}
					if (why === 'again') {
						again = true;
						on.textContent = L.again || label;
						return;
					}
					on.textContent = label;
					snack(format(L.failed, err && err.message ? err.message : ''), 'error');
				}).then(function () {
					on.disabled = false;
				});
			});
		}
		if (test) {
			test.addEventListener('click', function () {
				var id = local().id;
				if (id) {
					sendTest(id, test);
				}
			});
		}
		if (install) {
			install.addEventListener('click', function () {
				var prompt = window.brikpanelInstallPrompt;
				if (!prompt || !prompt.prompt) {
					view('ask');
					return;
				}
				// The offer works once: Chrome makes a new one on a later page view.
				window.brikpanelInstallPrompt = null;
				install.disabled = true;
				install.textContent = L.installing || installLabel;
				var choice;
				try {
					prompt.prompt();
					choice = prompt.userChoice || Promise.resolve({});
				} catch (e) {
					choice = Promise.resolve({});
				}
				choice.then(function (res) {
					return res || {};
				}, function () {
					return {};
				}).then(function (res) {
					install.disabled = false;
					install.textContent = installLabel;
					// Declined: notifications in this browser are the other way.
					browserChosen = res.outcome !== 'accepted';
					if (!browserChosen) {
						appSeen(true);
					}
					view(browserChosen ? 'ask' : 'installed');
				});
			});
		}
		if (here) {
			here.addEventListener('click', function () {
				browserChosen = true;
				view('ask');
			});
		}
		// Already on (turned on in Settings, or on another visit): the card
		// has nothing to offer, and its turn passes on. The button's check is
		// the visit's own (start()).
		if (!menu) {
			ready.then(checkOnce).then(function (sub) {
				if (sub && card.parentNode && current !== 'done') {
					closeAsk(card);
				}
			});
		}
	}

	/* ── Settings: Your devices ───────────────────────────────────────────── */

	function sendTest(id, btn) {
		var label = btn ? btn.textContent : '';
		if (btn) {
			btn.disabled = true;
			btn.textContent = L.sending || label;
		}
		return post('brikpanel_push_test', { id: id }).then(function (res) {
			if (res && res.success && res.data.ok) {
				snack(L.sent);
				refreshSoon();
			} else if (res && res.data && res.data.reason === 'wait') {
				snack(L.wait, 'error');
			} else {
				var d = (res && res.data) || {};
				snack(format(L.refused, ((d.code ? d.code + ' ' : '') + (d.reason || '')).trim()), 'error');
			}
			if (res && res.data && res.data.html) {
				paint(res.data.html);
			}
		}).catch(function () {
			snack(L.error, 'error');
		}).then(function () {
			if (btn) {
				btn.disabled = false;
				btn.textContent = label;
			}
		});
	}

	var list = null;
	function paint(html) {
		if (list && typeof html === 'string') {
			list.innerHTML = html;
			markThisDevice();
		}
	}

	var refreshTimer = null;
	/** After a test: show "delivered" when the phone confirms (up to 30 s). */
	function refreshSoon() {
		var left = 10;
		window.clearInterval(refreshTimer);
		refreshTimer = window.setInterval(function () {
			left--;
			post('brikpanel_push_devices', {}).then(function (res) {
				if (res && res.success) {
					paint(res.data.html);
				}
			});
			if (left <= 0) {
				window.clearInterval(refreshTimer);
			}
		}, 3000);
	}

	function sha256(text) {
		if (!window.crypto || !window.crypto.subtle || !window.TextEncoder) {
			return Promise.resolve('');
		}
		return window.crypto.subtle.digest('SHA-256', new TextEncoder().encode(text)).then(function (buf) {
			return Array.prototype.map.call(new Uint8Array(buf), function (b) {
				return ('0' + b.toString(16)).slice(-2);
			}).join('');
		});
	}

	function markThisDevice() {
		if (!list || !state.sub) {
			return;
		}
		sha256(state.sub.endpoint).then(function (hash) {
			var rows = list.querySelectorAll('[data-bp-push-row]');
			for (var i = 0; i < rows.length; i++) {
				var badge = rows[i].querySelector('[data-bp-push-this]');
				if (badge) {
					badge.hidden = !hash || rows[i].getAttribute('data-hash') !== hash;
				}
			}
		});
	}

	function bindSettings() {
		var box = document.querySelector('[data-bp-push-devices]');
		if (!box) {
			return;
		}
		list = box.querySelector('[data-bp-push-list]');
		var add = box.querySelector('[data-bp-push-add]');
		var hint = box.querySelector('[data-bp-push-hint]');
		var tip = box.querySelector('[data-bp-push-battery]');
		var again = false;
		function footer() {
			var letter = state.letter || baseLetter();
			var can = C.on && !!C.key && letter === 'a';
			if (add) {
				add.hidden = !can;
			}
			if (hint) {
				var text = can ? '' : (C.on ? reason(letter) : '');
				hint.textContent = text;
				hint.hidden = !text;
			}
			if (tip) {
				if (letter === 'o' && phone) {
					batteryStep(tip);
				} else {
					tip.hidden = true;
				}
			}
		}
		footer();
		// The check waits for the worker: a subscription lives on its registration.
		var ready = supported && C.on && C.key && !(isIOS && !standalone) ? register().catch(function () {}) : Promise.resolve();
		ready.then(checkOnce).then(function () {
			footer();
			markThisDevice();
		});

		if (add) {
			var label = add.textContent;
			add.addEventListener('click', function () {
				add.disabled = true;
				add.textContent = L.turning || label;
				(again ? askThenTurnOn('settings') : turnOn('settings')).then(function (d) {
					paint(d.html);
					snack(L.on);
					footer();
				}).catch(function (err) {
					var why = failure(err);
					if (why === 'again') {
						again = true;
						add.textContent = L.again || label;
						add.disabled = false;
						return;
					}
					if (why === 'denied') {
						state.letter = 'd';
						footer();
					} else {
						snack(format(L.failed, err && err.message ? err.message : ''), 'error');
					}
				}).then(function () {
					if (!again) {
						add.textContent = label;
					}
					add.disabled = false;
				});
			});
		}

		box.addEventListener('click', function (e) {
			var btn = e.target.closest ? e.target.closest('button') : null;
			if (!btn || !box.contains(btn)) {
				return;
			}
			var row = btn.closest('[data-bp-push-row]');
			var id = row ? row.getAttribute('data-id') : '';
			if (btn.hasAttribute('data-bp-push-test') && id) {
				sendTest(id, btn);
			} else if (btn.hasAttribute('data-bp-push-remove') && id) {
				var mine = row.querySelector('[data-bp-push-this]');
				btn.disabled = true;
				post('brikpanel_push_remove', { id: id }).then(function (res) {
					if (!res || !res.success) {
						throw new Error('remove');
					}
					paint(res.data.html);
					snack(L.removed);
					// Removing this browser's own row: its subscription goes too.
					if (mine && !mine.hidden && state.sub) {
						var sub = state.sub;
						state.sub = null;
						state.letter = 'a';
						local(null);
						setCookie('a');
						sub.unsubscribe().catch(function () {});
						footer();
						announce(false);
					}
				}).catch(function () {
					btn.disabled = false;
					snack(L.error, 'error');
				});
			} else if (btn.hasAttribute('data-bp-push-op')) {
				var op = btn.getAttribute('data-bp-push-op');
				if (op === 'new_keys' && !window.confirm(L.confirmKeys)) {
					return;
				}
				btn.disabled = true;
				post('brikpanel_push_fix', { op: op }).then(function (res) {
					if (!res || !res.success) {
						throw new Error(op);
					}
					window.location.reload();
				}).catch(function () {
					btn.disabled = false;
					snack(L.error, 'error');
				});
			}
		});
	}

	/* ── Start ────────────────────────────────────────────────────────────── */

	function start() {
		state.letter = baseLetter();
		setCookie(startLetter(state.letter));
		// Opened as an app on Android: its browser tab learns it was on this phone.
		if (isAndroid && standalone) {
			appSeen(true);
		}
		var card = document.querySelector('[data-bp-push-card]');
		var menu = document.querySelector('[data-bp-push-menu]');
		if (card) {
			bindOffer(card, 'card');
		}
		if (menu) {
			bindOffer(menu, 'menu');
		}
		bindRepair();
		bindSettings();

		// Elsewhere (and on the dashboard without a card): the visit's check,
		// again when an update brings a new worker (its address changes).
		if (!document.querySelector('[data-bp-push-card]') && !document.querySelector('[data-bp-push-devices]')) {
			var flag = 'brikpanel_push_checked_' + C.blog;
			var done = false;
			try {
				done = window.sessionStorage.getItem(flag) === String(C.worker);
				window.sessionStorage.setItem(flag, String(C.worker));
			} catch (e) { /* private mode */ }
			if (!done) {
				checkOnce().then(function () {
					if (state.letter === 'o') {
						setCookie('o');
						announce(true);
					}
				});
			}
		}

		// Back after an hour away (an app left open on the phone): look again.
		var hiddenAt = 0;
		document.addEventListener('visibilitychange', function () {
			if (document.hidden) {
				hiddenAt = Date.now();
			} else if (hiddenAt && Date.now() - hiddenAt > 3600000) {
				hiddenAt = 0;
				check().then(function () {
					if (state.letter === 'o') {
						announce(true);
					}
				});
			}
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', start);
	} else {
		start();
	}
})(window, document);

/**
 * Settings > Notifications > "Notification text" (brikpanel_push_render_parts_field()):
 * the arrows move a part, the switch turns it on or off, and the preview under
 * the list redraws from the rows' own text (written by PHP in the store's
 * language and money format: nothing is formatted here). Kept apart from the
 * phone code above, so it works in a browser that cannot get notifications.
 */
(function (document) {
	'use strict';

	function init() {
		var root = document.getElementById('brikpanel-push-parts');
		if (!root || root.getAttribute('data-bp-ready')) {
			return;
		}
		root.setAttribute('data-bp-ready', '1');
		var list = root.querySelector('[data-bp-parts-list]');
		var hidden = document.getElementById('brikpanel_push_parts_json');
		var body = root.querySelector('[data-bp-parts-body]');
		if (!list || !hidden || !body) {
			return;
		}
		var rows = function () {
			return Array.prototype.slice.call(list.querySelectorAll('[data-part]'));
		};

		// notify: an arrow is not a form field, so WooCommerce hears about the
		// change only through the hidden field (its Save button and its
		// "leave the page?" question listen to fields).
		function refresh(notify) {
			var all = rows();
			var order = [];
			var on = [];
			var shown = 0;
			while (body.firstChild) {
				body.removeChild(body.firstChild);
			}
			all.forEach(function (row, i) {
				var key = row.getAttribute('data-part');
				var box = row.querySelector('input[type="checkbox"]');
				var up = row.querySelector('[data-bp-parts-move="up"]');
				var down = row.querySelector('[data-bp-parts-move="down"]');
				var isOn = !!(box && box.checked);
				order.push(key);
				row.classList.toggle('is-off', !isOn);
				if (up) {
					up.disabled = i === 0;
				}
				if (down) {
					down.disabled = i === all.length - 1;
				}
				if (!isOn) {
					return;
				}
				on.push(key);
				var text = row.getAttribute('data-text') || '';
				if (!text) {
					return;
				}
				if (shown) {
					body.appendChild(document.createTextNode(' · '));
				}
				var span = document.createElement('span');
				span.textContent = text;
				if (row.hasAttribute('data-example')) {
					span.className = 'is-example';
				}
				body.appendChild(span);
				shown++;
			});
			hidden.value = JSON.stringify({ order: order, on: on });
			if (notify) {
				hidden.dispatchEvent(new Event('change', { bubbles: true }));
			}
		}

		list.addEventListener('click', function (e) {
			var btn = e.target.closest ? e.target.closest('[data-bp-parts-move]') : null;
			if (!btn || btn.disabled) {
				return;
			}
			var row = btn.closest('[data-part]');
			if (!row) {
				return;
			}
			e.preventDefault();
			if (btn.getAttribute('data-bp-parts-move') === 'up') {
				if (row.previousElementSibling) {
					list.insertBefore(row, row.previousElementSibling);
				}
			} else if (row.nextElementSibling) {
				list.insertBefore(row.nextElementSibling, row);
			}
			refresh(true);
			// The focus stays with the part that moved, on its other arrow at an end.
			var again = row.querySelector('[data-bp-parts-move="' + btn.getAttribute('data-bp-parts-move') + '"]');
			var other = row.querySelector('[data-bp-parts-move]:not([data-bp-parts-move="' + btn.getAttribute('data-bp-parts-move') + '"])');
			if (again && !again.disabled) {
				again.focus();
			} else if (other) {
				other.focus();
			}
		});

		// A switch is a form field: WooCommerce already hears it.
		list.addEventListener('change', function (e) {
			if (e.target && e.target.matches && e.target.matches('input[type="checkbox"]')) {
				refresh(false);
			}
		});

		refresh(false);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})(document);
