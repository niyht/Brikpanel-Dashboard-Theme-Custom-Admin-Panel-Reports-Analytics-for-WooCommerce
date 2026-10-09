/**
 * BrikPanel - phone notifications in the browser.
 *
 * - Works out what this browser can do and tells the server in a cookie
 *   (brikpanel_phone_<blog>, phones only): a = can turn notifications on,
 *   i = iPhone browser tab (Add to Home Screen first), o = on, d = blocked,
 *   u = cannot. The dashboard card takes its turn from it.
 * - The dashboard card and "Your devices" in Settings: turning notifications
 *   on (the subscribe call is the first thing the tap does: iPhones allow the
 *   question only inside a tap), a test, removing a device.
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
			if (/EdgA\//.test(ua)) {
				return L.edgeAndroid || '';
			}
			if (isIOS && iosVersion() && iosVersion() < 16.4) {
				return L.iosOld || '';
			}
			return L.unsupported || '';
		}
		return '';
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
			}
			return Promise.resolve(null);
		}
		return nav.serviceWorker.getRegistration(C.scope).then(function (reg) {
			if (reg) {
				state.reg = reg;
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

	/* ── The repair line (dashboard) ──────────────────────────────────────── */

	function showRepair() {
		var line = document.querySelector('[data-bp-push-repair]');
		if (!line) {
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
				p = state.reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: keyBytes() });
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

	/* ── The dashboard card ───────────────────────────────────────────────── */

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

	function bindCard() {
		var card = document.querySelector('[data-bp-push-card]');
		if (!card) {
			return;
		}
		var views = card.querySelectorAll('[data-bp-push-view]');
		var on = card.querySelector('[data-bp-push-on]');
		var test = card.querySelector('[data-bp-push-card-test]');
		var install = card.querySelector('[data-bp-push-install]');
		var label = on ? on.textContent : '';
		var again = false;
		function view(name) {
			for (var i = 0; i < views.length; i++) {
				views[i].hidden = views[i].getAttribute('data-bp-push-view') !== name;
			}
			if (on) {
				on.hidden = name !== 'ask';
			}
		}
		var letter = baseLetter();
		if (!phone || !C.on || (letter !== 'a' && letter !== 'i')) {
			card.hidden = true;
			return;
		}
		card.hidden = false;
		view(letter === 'i' ? 'ios' : 'ask');
		// Ready before the tap, so the tap itself can ask (iPhones allow it only there).
		var ready = letter === 'a' ? register().catch(function () {}) : Promise.resolve();

		var x = card.querySelector('[data-bp-ask-close]');
		if (x) {
			x.addEventListener('click', function () {
				closeAsk(card);
			});
		}
		if (on) {
			on.addEventListener('click', function () {
				on.disabled = true;
				on.textContent = L.turning || label;
				(again ? askThenTurnOn('card') : turnOn('card')).then(function () {
					view('done');
					var note = card.querySelector('[data-bp-push-note]');
					if (note) {
						note.textContent = L.onPhone || '';
					}
					if (test) {
						test.hidden = false;
					}
					if (install && window.brikpanelInstallPrompt && !standalone) {
						install.hidden = false;
					}
				}).catch(function (err) {
					var why = failure(err);
					if (why === 'denied') {
						var text = card.querySelector('[data-bp-push-blocked]');
						if (text) {
							text.textContent = reason('d');
						}
						view('blocked');
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
				if (prompt && prompt.prompt) {
					prompt.prompt();
					window.brikpanelInstallPrompt = null;
					install.hidden = true;
				}
			});
		}
		// Already on (turned on in Settings, or on another visit): the card
		// has nothing to offer, and its turn passes on.
		ready.then(check).then(function (sub) {
			if (sub && card.parentNode && on && !on.hidden) {
				closeAsk(card);
			}
		});
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
		}
		footer();
		// The check waits for the worker: a subscription lives on its registration.
		var ready = supported && C.on && C.key && !(isIOS && !standalone) ? register().catch(function () {}) : Promise.resolve();
		ready.then(check).then(function () {
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
		setCookie(state.letter);
		bindCard();
		bindRepair();
		bindSettings();

		// Elsewhere (and on the dashboard without a card): the visit's check.
		if (!document.querySelector('[data-bp-push-card]') && !document.querySelector('[data-bp-push-devices]')) {
			var flag = 'brikpanel_push_checked_' + C.blog;
			var done = false;
			try {
				done = !!window.sessionStorage.getItem(flag);
				window.sessionStorage.setItem(flag, '1');
			} catch (e) { /* private mode */ }
			if (!done) {
				check().then(function () {
					if (state.letter === 'o') {
						setCookie('o');
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
				check();
			}
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', start);
	} else {
		start();
	}
})(window, document);
