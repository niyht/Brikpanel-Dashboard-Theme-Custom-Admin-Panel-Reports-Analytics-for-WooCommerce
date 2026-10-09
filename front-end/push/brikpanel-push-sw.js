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

	function sendReceipt(token) {
		if (!token || !C.receipt) return Promise.resolve();
		var body = new URLSearchParams();
		body.set('action', 'brikpanel_push_receipt');
		body.set('r', String(token));
		return fetch(C.receipt, {
			method: 'POST',
			body: body,
			credentials: 'omit',
			cache: 'no-store',
			keepalive: true,
		}).catch(function () { /* the receipt is best effort */ });
	}

	function tellWindows(kind) {
		return self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (list) {
			list.forEach(function (client) {
				try {
					client.postMessage({ type: 'brikpanel-push', kind: kind || '' });
				} catch (e) { /* ignore */ }
			});
		}).catch(function () { /* ignore */ });
	}

	self.addEventListener('push', function (event) {
		var msg = read(event);
		var n = (msg && typeof msg.notification === 'object' && msg.notification) || {};
		var data = (n.data && typeof n.data === 'object') ? n.data : {};

		// Inside the PWA plugin's worker, messages that are not BrikPanel's
		// (unreadable ones too) are left to that plugin's own handler.
		if (C.shared && (!msg || data.bp !== 1)) return;

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

		var title = (typeof n.title === 'string' && n.title) ? n.title : (C.title || '');
		var shown = self.registration.showNotification(title, options).catch(function () { /* ignore */ });
		var extras = Promise.all([sendReceipt(data.r), tellWindows(data.k)]);
		var capped = Promise.race([extras, new Promise(function (resolve) { setTimeout(resolve, 8000); })]);

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
