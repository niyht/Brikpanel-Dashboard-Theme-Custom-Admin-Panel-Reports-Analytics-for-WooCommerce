/**
 * BrikPanel - service worker for phone notifications.
 *
 * Served from admin-ajax.php?action=brikpanel_push_sw (scope: the admin
 * folder), after a line of settings: self.BRIKPANEL_PUSH = { receipt, start,
 * icon, badge, title, shared }.
 *
 * Every message shows a notification, always: iPhones remove every
 * subscription of a site that receives three pushes without showing one.
 * Then, in the background and for at most 8 seconds, it tells the store the
 * phone got it (the delivery receipt behind "last notification delivered")
 * and tells open admin windows to check for new orders now.
 *
 * Messages are Declarative Web Push JSON (web_push: 8030): an iPhone on iOS
 * 18.4 or later can show them even without this worker; everywhere else this
 * worker shows them.
 */
(function () {
	'use strict';

	var C = self.BRIKPANEL_PUSH || {};

	// BrikPanel's own worker takes over at once. Inside the PWA plugin's worker
	// (shared) the update rules are that plugin's.
	if (!C.shared) {
		self.addEventListener('install', function () {
			self.skipWaiting();
		});

		self.addEventListener('activate', function (event) {
			event.waitUntil(self.clients.claim());
		});
	}

	function read(event) {
		try {
			return event.data ? event.data.json() : null;
		} catch (e) {
			return null;
		}
	}

	/** Notification options from a message's notification part. */
	function optionsFor(n) {
		var data = (n.data && typeof n.data === 'object') ? n.data : {};
		var options = {
			body: typeof n.body === 'string' ? n.body : '',
			icon: C.icon || undefined,
			badge: C.badge || undefined,
			silent: false,
			data: { bp: 1, url: typeof n.navigate === 'string' ? n.navigate : C.start },
		};
		if (typeof n.tag === 'string' && n.tag) options.tag = n.tag;
		if (typeof n.lang === 'string' && n.lang) options.lang = n.lang;
		if (n.dir === 'rtl' || n.dir === 'ltr') options.dir = n.dir;
		return { options: options, data: data };
	}

	/**
	 * Tells the store the phone got it. A late receipt (the phone slept) is
	 * answered with the orders sent in the meantime: Android may have dropped
	 * their pushes, so they are shown here (the same tag replaces a copy that
	 * still arrives, without a second sound).
	 */
	function sendReceipt(token, heard) {
		if (!token || !C.receipt) return Promise.resolve();
		var body = new URLSearchParams();
		body.set('action', 'brikpanel_push_receipt');
		body.set('r', String(token));
		// What an open window said (tellWindows), for the server's logs only.
		if (heard) body.set('w', String(heard));
		return fetch(C.receipt, {
			method: 'POST',
			body: body,
			credentials: 'omit',
			cache: 'no-store',
			keepalive: true,
		}).then(function (res) {
			return res && res.status === 200 ? res.json() : null;
		}).then(function (json) {
			var missed = json && Array.isArray(json.missed) ? json.missed.slice(0, 5) : [];
			return Promise.all(missed.map(function (n) {
				if (!n || typeof n !== 'object' || typeof n.title !== 'string' || !n.title) return null;
				return self.registration.showNotification(n.title, optionsFor(n).options).catch(function () { /* ignore */ });
			}));
		}).catch(function () { /* the receipt is best effort */ });
	}

	/**
	 * Tells the open admin windows to check for new orders now. A window on
	 * screen is also asked whether it will play the cash register itself
	 * (brikpanel-order-notify.js): then the phone's own notification comes
	 * without a sound, so a new order is not heard twice. No answer within a
	 * two seconds, or no window on screen: the notification keeps its sound.
	 *
	 * @return {Promise<string>} 'p' a window plays it, 'n' it cannot, 't' no answer in time, '0' no window on screen.
	 */
	function tellWindows(kind) {
		return self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (list) {
			var asks = [];
			list.forEach(function (client) {
				var msg = { type: 'brikpanel-push', kind: kind || '' };
				if (client.visibilityState !== 'visible' || typeof MessageChannel === 'undefined') {
					try {
						client.postMessage(msg);
					} catch (e) { /* ignore */ }
					return;
				}
				asks.push(new Promise(function (resolve) {
					var timer = setTimeout(function () { resolve('t'); }, 2000);
					try {
						var channel = new MessageChannel();
						channel.port1.onmessage = function (e) {
							clearTimeout(timer);
							resolve(e.data && e.data.play ? 'p' : 'n');
						};
						msg.ask = 1;
						client.postMessage(msg, [channel.port2]);
					} catch (e) {
						clearTimeout(timer);
						resolve('n');
					}
				}));
			});
			if (!asks.length) {
				return '0';
			}
			return Promise.all(asks).then(function (answers) {
				// p: a window plays; n: a window said it cannot; t: no answer in time.
				return answers.indexOf('p') !== -1 ? 'p' : (answers.indexOf('t') !== -1 ? 't' : 'n');
			});
		}).catch(function () {
			return 'n';
		});
	}

	self.addEventListener('push', function (event) {
		var msg = read(event);
		var n = (msg && typeof msg.notification === 'object' && msg.notification) || {};
		var parts = optionsFor(n);
		var data = parts.data;
		var options = parts.options;

		// Inside the PWA plugin's worker, messages that are not BrikPanel's
		// (unreadable ones too) are left to that plugin's own handler.
		if (C.shared && (!msg || data.bp !== 1)) return;

		var title = (typeof n.title === 'string' && n.title) ? n.title : (C.title || '');
		// Orders only: a test has no order for the window to find, so it always rings.
		var order = data.k === 'order' || data.k === 'orders';
		var asked = tellWindows(data.k);
		var shown = asked.then(function (heard) {
			if (order && heard === 'p') {
				options.silent = true;
			}
			return self.registration.showNotification(title, options);
		}).catch(function () {
			return self.registration.showNotification(title, options);
		}).catch(function () { /* ignore */ });
		var receipt = asked.then(function (heard) {
			return sendReceipt(data.r, heard);
		}, function () {
			return sendReceipt(data.r, '');
		});
		var capped = Promise.race([receipt, new Promise(function (resolve) { setTimeout(resolve, 8000); })]);

		event.waitUntil(Promise.all([shown, capped]));
	});

	function sameOrigin(url) {
		try {
			return new URL(url, self.location.href).origin === self.location.origin;
		} catch (e) {
			return false;
		}
	}

	self.addEventListener('notificationclick', function (event) {
		var nd = event.notification.data;
		// Inside the PWA plugin's worker, only BrikPanel's own notifications.
		if (C.shared && !(nd && nd.bp === 1)) return;
		event.notification.close();
		var target = (nd && nd.url) || C.start;
		// Only the store's own pages: a message can never send the phone elsewhere.
		var url = sameOrigin(target) ? target : C.start;

		event.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (list) {
			for (var i = 0; i < list.length; i++) {
				var client = list[i];
				if (typeof client.navigate === 'function' && typeof client.focus === 'function') {
					return client.navigate(url).then(function (win) {
						return (win || client).focus();
					}).catch(function () {
						return self.clients.openWindow ? self.clients.openWindow(url) : null;
					});
				}
			}
			return self.clients.openWindow ? self.clients.openWindow(url) : null;
		}));
	});
})();
