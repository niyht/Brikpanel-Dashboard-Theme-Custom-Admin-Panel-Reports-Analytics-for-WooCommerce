/**
 * BrikPanel — Cart Abandonment front-end capture.
 *
 * Two independent jobs, both configured via the localized `brikpanelCartAb`
 * object:
 *  1. Checkout email capture: a delegated `input` listener plus a low-cost
 *     poller watch every native email field on the checkout page — this
 *     covers the classic shortcode checkout (#billing_email), the Gutenberg
 *     block checkout (#email, a React-controlled input that still emits
 *     native input events), third-party checkouts, and browser autofill
 *     (which the poller catches even when no event fires).
 *  2. Signup popup with coupon: optional, disabled by default. Klaviyo-style
 *     lifecycle — closing the popup collapses it into a floating tab on the
 *     left edge (click to reopen); dismissing the tab hides everything for
 *     the cooldown period; a successful signup shows the personal coupon
 *     code, then auto-closes and never returns.
 *
 * Rendered entirely with textContent (no HTML injection from settings
 * values). All user-facing strings come from the localize payload (i18n rule).
 */
(function () {
	'use strict';

	var cfg = window.brikpanelCartAb;
	if (!cfg || !cfg.ajaxUrl) {
		return;
	}

	var EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;
	// Dedup signature covers email AND the extra fields, so a name/phone
	// filled in after the email was captured still triggers one update ping.
	var lastSent = '';

	function signature(email, extra) {
		return email.toLowerCase() + '|' + JSON.stringify(extra || {});
	}

	function send(email, source, extra) {
		email = (email || '').trim();
		var sig = signature(email, extra);
		if (!EMAIL_RE.test(email) || sig === lastSent) {
			return Promise.reject(new Error('invalid'));
		}
		lastSent = sig;

		var body = new FormData();
		body.append('action', 'brikpanel_cartab_capture');
		body.append('email', email);
		body.append('source', source);
		if (extra) {
			Object.keys(extra).forEach(function (k) {
				if (extra[k]) {
					body.append(k, extra[k]);
				}
			});
		}
		return fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
			.then(function (res) { return res.json(); })
			.then(function (json) {
				if (!json || !json.success) {
					lastSent = ''; // allow retry on server-side rejection
					throw new Error('rejected');
				}
				if (json.data && json.data.throttled) {
					lastSent = ''; // rate-limited — the 3s poller will retry
				}
				return json;
			})
			.catch(function (err) {
				if (err && err.message !== 'invalid' && err.message !== 'rejected') {
					lastSent = ''; // network error — retry later
				}
				throw err;
			});
	}

	// Correct the email on a popup signup and re-deliver the coupon. Used by
	// the emailed-coupon "edit" affordance so a mistyped inbox is recoverable.
	function updateEmail(id, email) {
		var body = new FormData();
		body.append('action', 'brikpanel_cartab_popup_update_email');
		body.append('id', id);
		body.append('email', email);
		return fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
			.then(function (res) { return res.json(); })
			.then(function (json) {
				if (!json || !json.success) {
					throw new Error('rejected');
				}
				return json;
			});
	}

	/* ------------------------------------------------------------------
	 * 1) Checkout capture
	 * ------------------------------------------------------------------ */
	// wp_localize_script stringifies scalars — "0" is truthy, so compare numerically.
	if (Number(cfg.isCheckout) === 1) {
		var debounceTimer = null;

		function fieldExtra() {
			// Best-effort name/phone from classic checkout fields; block
			// checkout ids covered too. Missing fields are simply skipped.
			function val(sel) {
				var el = document.querySelector(sel);
				return el && el.value ? el.value.trim() : '';
			}
			return {
				first_name: val('#billing_first_name') || val('#billing-first_name'),
				last_name: val('#billing_last_name') || val('#billing-last_name'),
				phone: val('#billing_phone') || val('#billing-phone'),
				// The country the phone was written in. Without it the WhatsApp
				// link has to guess a country code later, and a number typed
				// "90 545..." in a US-based store ends up dialling +1 90 545...
				// Both fields are <select>, so .value is the two-letter code.
				phone_country: val('#billing_country') || val('#billing-country')
			};
		}

		function isEmailField(el) {
			if (!el || el.tagName !== 'INPUT') {
				return false;
			}
			if (el.closest('.brikpanel-cartab-popup')) {
				return false; // the popup has its own submit path
			}
			return el.type === 'email' || el.id === 'billing_email' || /email/i.test(el.name || '');
		}

		function captureFrom(el, immediate) {
			if (!isEmailField(el) || !EMAIL_RE.test((el.value || '').trim())) {
				return;
			}
			window.clearTimeout(debounceTimer);
			debounceTimer = window.setTimeout(function () {
				send(el.value, 'checkout', fieldExtra()).catch(function () {});
			}, immediate ? 0 : 900);
		}

		document.addEventListener('input', function (e) { captureFrom(e.target, false); }, true);
		document.addEventListener('change', function (e) { captureFrom(e.target, true); }, true);
		document.addEventListener('focusout', function (e) { captureFrom(e.target, true); }, true);

		// Poller: catches prefilled values (saved customer, autofill,
		// programmatic fills from express-checkout plugins) that never fire
		// an input event. Cheap — a handful of DOM reads every 3s.
		function scanFields() {
			var fields = document.querySelectorAll('input[type="email"], #billing_email');
			for (var i = 0; i < fields.length; i++) {
				var value = (fields[i].value || '').trim();
				if (EMAIL_RE.test(value) && signature(value, fieldExtra()) !== lastSent) {
					send(value, 'checkout', fieldExtra()).catch(function () {});
					break;
				}
			}
		}
		window.setInterval(scanFields, 3000);

		// Logged-in customer: email already known server-side; register it
		// right away so even an immediate bounce is captured.
		if (cfg.knownEmail) {
			send(cfg.knownEmail, 'checkout', fieldExtra()).catch(function () {});
		} else {
			window.setTimeout(scanFields, 1200);
		}
	}

	/* ------------------------------------------------------------------
	 * 2) Signup popup + floating tab (teaser)
	 * ------------------------------------------------------------------ */
	if (cfg.popup && Number(cfg.popup.enabled) === 1) {
		var LS_DONE = 'brikpanel_cartab_done';
		var LS_DISMISS = 'brikpanel_cartab_dismissed';
		var LS_TEASER_HIDDEN = 'brikpanel_cartab_teaser_hidden';
		var LS_COUPON = 'brikpanel_cartab_coupon';
		var SS_CODE_TAB = 'brikpanel_cartab_code_tab_hidden';
		// Not an XSS guard (every code is written with textContent) — it stops a
		// corrupt or hand-edited record from being painted into the tab.
		var CODE_RE = /^[A-Z0-9][A-Z0-9_-]{2,31}$/;
		var DAY_MS = 86400000;
		// Longest minimum-spend amount kept with a saved code ("$1,234.56" with
		// its direction marks is far shorter); anything longer is not ours.
		var MIN_MAX = 40;

		var storage;
		try {
			storage = window.localStorage;
			storage.setItem('brikpanel_cartab_probe', '1');
			storage.removeItem('brikpanel_cartab_probe');
		} catch (err) {
			storage = null;
		}

		var session;
		try {
			session = window.sessionStorage;
			session.setItem('brikpanel_cartab_probe', '1');
			session.removeItem('brikpanel_cartab_probe');
		} catch (err2) {
			session = null;
		}

		var teaserEl = null;
		var overlayEl = null;
		var discount = Math.max(0, Number(cfg.popup.discount) || 0);
		var cooldownMs = Math.max(1, Number(cfg.popup.cooldown) || 7) * DAY_MS;

		function lsGet(key) {
			return storage ? storage.getItem(key) : null;
		}
		function lsSet(key, value) {
			if (storage) {
				storage.setItem(key, value);
			}
		}
		function ssGet(key) {
			return session ? session.getItem(key) : null;
		}
		function ssSet(key, value) {
			if (session) {
				session.setItem(key, value);
			}
		}

		function isDone() {
			return !!lsGet(LS_DONE);
		}

		/* ---------------- Saved coupon ---------------- */

		// The code the visitor was given, kept for as long as it is worth
		// something. Without this the code exists on screen once and is gone the
		// moment the popup closes.
		var couponCache;

		function savedCoupon() {
			if (couponCache !== undefined) {
				return couponCache;
			}
			couponCache = null;

			var raw = lsGet(LS_COUPON);
			if (!raw) {
				return couponCache;
			}
			try {
				var rec = JSON.parse(raw);
				if (rec && rec.v === 1 && typeof rec.code === 'string'
					&& CODE_RE.test(rec.code) && Number(rec.exp) > Date.now()) {
					// The minimum is optional (records saved before it existed
					// have none); a damaged one is dropped, not the whole code.
					if (typeof rec.min !== 'string' || rec.min.length > MIN_MAX) {
						rec.min = '';
					}
					couponCache = rec;
				} else if (storage) {
					storage.removeItem(LS_COUPON); // Expired or foreign shape.
				}
			} catch (err3) {
				if (storage) {
					storage.removeItem(LS_COUPON);
				}
			}
			return couponCache;
		}

		function saveCoupon(data) {
			if (!storage || !data || typeof data.coupon !== 'string' || !CODE_RE.test(data.coupon)) {
				return null;
			}
			// The percentage is stored as it was ISSUED: a merchant who later
			// moves 10% to 20% must not make an old visitor's tab lie.
			var rec = {
				v: 1,
				code: data.coupon,
				pct: Math.max(0, Number(data.discount) || 0),
				exp: Number(data.expires) > 0 ? Number(data.expires) * 1000 : Date.now() + 30 * DAY_MS,
				auto: data.autoapply ? 1 : 0,
				// The code's minimum spend as an amount, also as issued. Only
				// the amount is kept; the sentence around it is written in the
				// language of whichever page reopens the tab.
				min: (typeof data.minimum === 'string' && data.minimum.length <= MIN_MAX) ? data.minimum : ''
			};
			try {
				storage.setItem(LS_COUPON, JSON.stringify(rec));
			} catch (err4) {
				return null;
			}
			couponCache = rec;
			return rec;
		}

		function teaserHidden() {
			var ts = parseInt(lsGet(LS_TEASER_HIDDEN) || '0', 10);
			if (!ts) {
				return false;
			}
			if (Date.now() - ts < cooldownMs) {
				return true;
			}
			if (storage) {
				// Cooldown over — forget both flags so the flow restarts fresh.
				storage.removeItem(LS_TEASER_HIDDEN);
				storage.removeItem(LS_DISMISS);
			}
			return false;
		}

		function wasDismissed() {
			return !!lsGet(LS_DISMISS);
		}

		/* ---------------- Floating tab ---------------- */

		function removeTeaser() {
			if (teaserEl && teaserEl.parentNode) {
				teaserEl.parentNode.removeChild(teaserEl);
			}
			teaserEl = null;
		}

		function showTeaser() {
			if (teaserEl) {
				return;
			}

			// Two modes share one tab. With a saved code it is the way back to
			// that code, so "done" and the signup cooldown no longer apply — they
			// are about the invitation, not about something the visitor owns.
			var rec = savedCoupon();
			if (rec) {
				if (ssGet(SS_CODE_TAB)) {
					return;
				}
			} else if (isDone() || teaserHidden()) {
				return;
			}

			teaserEl = document.createElement('div');
			teaserEl.className = rec ? 'brikpanel-cartab-teaser is-coupon' : 'brikpanel-cartab-teaser';

			var open = document.createElement('button');
			open.type = 'button';
			open.className = 'brikpanel-cartab-teaser-btn';
			open.setAttribute('aria-label', rec ? cfg.i18n.couponTeaserLabel : cfg.popup.title);

			if (rec ? rec.pct > 0 : discount > 0) {
				var mini = document.createElement('span');
				mini.className = 'brikpanel-cartab-teaser-ticket';
				mini.textContent = '%';
				open.appendChild(mini);
			}

			var label = document.createElement('span');
			label.className = 'brikpanel-cartab-teaser-label';
			label.textContent = rec ? cfg.i18n.couponTeaser : cfg.popup.teaser;
			open.appendChild(label);

			var close = document.createElement('button');
			close.type = 'button';
			close.className = 'brikpanel-cartab-teaser-close';
			close.setAttribute('aria-label', cfg.i18n.close);
			close.textContent = '×';

			open.addEventListener('click', function () {
				if (rec) {
					openCodePanel(rec);
				} else {
					openPopup();
				}
			});
			if (!rec) {
				// Reopening the invitation draws it again: fetch the
				// handwriting while the pointer is on its way.
				open.addEventListener('pointerenter', askHandFont);
				open.addEventListener('focus', askHandFont);
			}
			close.addEventListener('click', function (e) {
				e.stopPropagation();
				if (rec) {
					// Session-scoped on purpose. LS_TEASER_HIDDEN is the
					// don't-nag-me cooldown (days); hiding a visitor's own code
					// for a week would recreate the very bug this fixes.
					ssSet(SS_CODE_TAB, '1');
				} else {
					lsSet(LS_TEASER_HIDDEN, String(Date.now()));
				}
				removeTeaser();
			});

			teaserEl.appendChild(open);
			teaserEl.appendChild(close);
			document.body.appendChild(teaserEl);
			window.requestAnimationFrame(function () {
				teaserEl.classList.add('is-in');
			});
		}

		/* ---------------- Offer sketch (pencil drawing of the discount) ---------------- */

		// Drawn the way the welcome tour draws: every pen line has pathLength 1
		// and its dash offset runs from 1 to 0, the lines wobble like pencil, and
		// the handwritten notes are HTML laid over the drawing so a translation
		// can be measured and fitted. One 364 x 132 grid (the card's content
		// width), scaled with the card.
		//
		// The six "Offer animation" keys are older than the drawings (3.3.43
		// redrew every one), so a saved choice keeps working: slot = handwriting,
		// assembly = ring, classic = coupon cut out by scissors, envelope =
		// envelope, pocket = gift box, scratch = eraser.
		var SVG_NS = 'http://www.w3.org/2000/svg';
		var GRID_W = 364;
		var GRID_H = 132;
		var HAND_FONT = '600 22px "BrikPanel Caveat"';
		var SKETCHES = {
			slot: drawHandwriting,
			assembly: drawRing,
			classic: drawCoupon,
			envelope: drawEnvelope,
			pocket: drawGift,
			scratch: drawEraser
		};
		var NOTE_SLOTS = {
			assembly: 'noteRing',
			classic: 'noteCoupon',
			envelope: 'noteEnvelope',
			pocket: 'noteGift',
			scratch: 'noteEraser'
		};
		// Single-stroke pen digits, 60 units tall: [advance, strokes in writing order].
		var PEN_GLYPHS = {
			'0': [40, ['M24 2C12 2 5 16 5 32C5 49 12 60 21 60C31 60 37 47 37 30C37 14 31 2 22 3C19 3.5 17 5 16 7']],
			'1': [24, ['M2 17C8 13 13 8 16 2C15 21 14 41 13 60']],
			'2': [40, ['M5 15C7 6 14 1 22 2C31 3 36 10 35 19C34 30 22 42 6 59C17 58 28 58 39 59']],
			'3': [40, ['M6 9C11 3 18 1 24 2C33 3 36 12 32 20C29 26 22 29 15 29C25 29 36 34 36 45C36 55 28 61 18 60C12 60 7 57 4 53']],
			'4': [42, ['M26 2C20 16 13 29 4 41C16 41 28 40 39 40', 'M29 20C28 33 28 47 27 60']],
			'5': [40, ['M34 3C26 3 18 3 11 4C10 12 9 20 8 28C14 24 21 23 27 25C35 28 38 38 36 47C33 57 24 61 16 60C10 59 6 56 4 52']],
			'6': [40, ['M30 3C20 8 9 20 6 36C4 50 11 60 21 60C31 60 37 52 36 43C35 34 28 29 20 30C13 31 8 36 6 41']],
			'7': [40, ['M4 4C15 4 26 3 37 3C30 20 23 38 15 60']],
			'8': [40, ['M31 14C31 6 26 2 20 2C12 2 7 7 8 14C9 22 18 26 22 29C29 33 36 39 35 48C34 56 28 60 20 60C11 60 5 54 6 46C7 38 15 33 21 28C27 24 31 20 31 14']],
			'9': [40, ['M33 18C33 9 27 3 19 3C11 3 6 10 7 18C8 26 15 30 22 29C28 28 32 23 33 18C33 32 32 46 30 60']],
			'%': [42, ['M14 3C8 3 5 8 5 13C5 19 9 22 13 22C18 22 21 17 20 11C19 6 17 3 13 3', 'M35 2L7 60', 'M30 40C24 40 21 45 22 50C22 56 26 59 30 59C35 59 38 54 37 48C36 43 33 40 29 41']]
		};
		var handFont = null;

		function sketchStyle() {
			var style = String(cfg.popup.style || 'slot');
			return Object.prototype.hasOwnProperty.call(SKETCHES, style) ? style : 'slot';
		}

		// "%s" is the number, "%%" the sign: the shopper's language decides the
		// order ("%10" in Turkish, "10 %" in German).
		function offerText() {
			return String(cfg.popup.pct || '%s%%').replace(/%(%|s)/g, function (m, c) {
				return c === 's' ? String(discount) : '%';
			});
		}

		// The note beside the drawing, or '' when this page's settings carry
		// none (a page cached before the notes existed): no note then, rather
		// than an English one.
		function noteText(style) {
			var slot = NOTE_SLOTS[style];
			var text = slot ? cfg.i18n[slot] : '';
			return typeof text === 'string' ? text : '';
		}

		// Asks for the handwriting font with the letters this popup will write.
		// Called shortly before the popup opens (and when the tab is pointed
		// at), so a visitor who leaves before it never downloads it.
		function askHandFont() {
			if (handFont || discount <= 0 || !document.fonts || typeof document.fonts.load !== 'function') {
				return handFont;
			}
			var sample = (noteText(sketchStyle()) + ' ' + String(cfg.i18n.offBadge || '')).trim();
			try {
				handFont = document.fonts.load(HAND_FONT, sample || 'a');
			} catch (errF) {
				handFont = null;
			}
			return handFont;
		}

		// Calls back once the font is in, or after 400 ms with false: the popup
		// is never held back for a slow font.
		function whenHandFont(done) {
			var asked = askHandFont();
			var called = false;
			function finish(ok) {
				if (!called) {
					called = true;
					done(ok);
				}
			}
			if (!asked) {
				finish(false);
				return;
			}
			window.setTimeout(function () {
				finish(false);
			}, 400);
			asked.then(function (faces) {
				finish(!!(faces && faces.length));
			}, function () {
				finish(false);
			});
		}

		// Draws the offer into its (already placed, still empty) box.
		function drawSketch(box, fontReady) {
			// Without the font the notes keep the fallback face for good, so a
			// late swap can never move them away from their arrows.
			if (!fontReady) {
				box.classList.add('is-plain-hand');
			}
			var style = sketchStyle();
			try {
				SKETCHES[style](box, {
					pct: offerText(),
					off: String(cfg.i18n.offBadge || ''),
					note: noteText(style)
				});
			} catch (errD) {
				// A drawing must never cost the visitor the popup itself; the
				// title still says what the offer is.
				box.textContent = '';
			}
		}

		/* -- Drawing kit -- */

		function r1(n) {
			return Math.round(n * 10) / 10;
		}

		function pt(x, y) {
			return r1(x) + ' ' + r1(y);
		}

		function svgEl(tag, attrs, parent) {
			var node = document.createElementNS(SVG_NS, tag);
			for (var k in attrs) {
				if (Object.prototype.hasOwnProperty.call(attrs, k)) {
					node.setAttribute(k, attrs[k]);
				}
			}
			if (parent) {
				parent.appendChild(node);
			}
			return node;
		}

		// A full-size drawing layer; several can stack on the same grid.
		function sketchLayer(box, cls) {
			return svgEl('svg', {
				viewBox: '0 0 ' + GRID_W + ' ' + GRID_H,
				'class': 'brikpanel-cartab-layer' + (cls ? ' ' + cls : ''),
				'aria-hidden': 'true',
				focusable: 'false'
			}, box);
		}

		// A group of pencil lines that move together.
		function ink(parent) {
			return svgEl('g', {}, parent);
		}

		// A pen line that draws itself: starts after `dd` seconds, takes `dur`.
		// `ox`: where a part drawn at a moved origin sits (a handwritten digit),
		// so each part wobbles in its own way.
		function pen(parent, d, dd, dur, cls, ox) {
			return svgEl('path', {
				d: roughen(d, ox),
				pathLength: '1',
				'class': 'brikpanel-cartab-pen' + (cls ? ' ' + cls : ''),
				style: '--bp-cartab-dd:' + (Math.round(dd * 100) / 100) + 's;--bp-cartab-dur:' + dur + 's'
			}, parent);
		}

		// A line that is simply there: it arrives with the part it belongs to.
		function stroke(parent, d, cls) {
			return svgEl('path', { d: roughen(d), 'class': 'brikpanel-cartab-line' + (cls ? ' ' + cls : '') }, parent);
		}

		// A flat area: 'paper' hides what is behind it, 'shade' is pencil grey,
		// 'ink' is solid. With `dd` it fades in at that moment.
		function area(parent, d, tone, dd) {
			var attrs = { d: roughen(d), 'class': 'brikpanel-cartab-shape is-' + tone };
			if (dd !== undefined) {
				attrs['class'] += ' is-later';
				attrs.style = '--bp-cartab-dd:' + dd + 's';
			}
			return svgEl('path', attrs, parent);
		}

		/* -- Pencil edge -- */

		// The welcome tour roughens its lines with a turbulence filter. Here the
		// same wobble is drawn into each line once, when it is made: a filter is
		// painted again on every frame of the drawing, and on a phone that cost
		// more than the drawing itself.

		// Smooth 2-D value noise in [-1, 1].
		function noise2(x, y, seed) {
			var xi = Math.floor(x);
			var yi = Math.floor(y);
			var xf = x - xi;
			var yf = y - yi;
			function corner(i, j) {
				var n = Math.sin(i * 127.1 + j * 311.7 + seed * 74.7) * 43758.5453;
				return (n - Math.floor(n)) * 2 - 1;
			}
			var u = xf * xf * (3 - 2 * xf);
			var v = yf * yf * (3 - 2 * yf);
			var a = corner(xi, yi);
			var b = corner(xi + 1, yi);
			var c = corner(xi, yi + 1);
			return a + (b - a) * u + (c - a) * v + (a - b - c + corner(xi + 1, yi + 1)) * u * v;
		}

		// Where the pencil really puts a point: up to about 1.4 units off, the
		// same spot always moved the same way (two lines that meet still meet).
		function wobble(x, y) {
			var f = 0.05;
			return [
				x + noise2(x * f, y * f, 1) + 0.4 * noise2(x * f * 2.3, y * f * 2.3, 3),
				y + noise2(x * f, y * f, 2) + 0.4 * noise2(x * f * 2.3, y * f * 2.3, 4)
			];
		}

		// Redraws a path in pencil: straight runs and curves are cut into short
		// pieces and every point is moved by wobble(), so corners stay corners.
		// Reads the commands the drawings use: M L H V C Q A Z, absolute or
		// relative.
		function roughen(d, ox) {
			var off = ox || 0;
			var tok = String(d).match(/[A-Za-z]|-?(?:\d+\.?\d*|\.\d+)(?:e[-+]?\d+)?/g) || [];
			var out = '';
			var i = 0;
			var cmd = '';
			var x = 0;
			var y = 0;
			var sx = 0;
			var sy = 0;
			function num() {
				return parseFloat(tok[i++]);
			}
			function at(px, py) {
				var w = wobble(px + off, py);
				return [w[0] - off, w[1]];
			}
			function lineTo(nx, ny) {
				var len = Math.sqrt((nx - x) * (nx - x) + (ny - y) * (ny - y));
				var n = Math.max(1, Math.round(len / 7));
				var pts = [];
				for (var k = 0; k <= n; k++) {
					pts.push(at(x + (nx - x) * k / n, y + (ny - y) * k / n));
				}
				// A smooth curve through the moved points: the run bends, its
				// ends stay sharp.
				for (var j = 0; j < n; j++) {
					var p0 = pts[Math.max(0, j - 1)];
					var p1 = pts[j];
					var p2 = pts[j + 1];
					var p3 = pts[Math.min(n, j + 2)];
					out += 'C' + pt(p1[0] + (p2[0] - p0[0]) / 6, p1[1] + (p2[1] - p0[1]) / 6)
						+ ' ' + pt(p2[0] - (p3[0] - p1[0]) / 6, p2[1] - (p3[1] - p1[1]) / 6)
						+ ' ' + pt(p2[0], p2[1]);
				}
				x = nx;
				y = ny;
			}
			function cubicTo(x1, y1, x2, y2, nx, ny, depth) {
				// Long curves are split in two (de Casteljau) until the wobble
				// can run along them.
				if (depth < 4 && Math.sqrt((nx - x) * (nx - x) + (ny - y) * (ny - y)) > 10) {
					var ax = (x + x1) / 2, ay = (y + y1) / 2;
					var bx = (x1 + x2) / 2, by = (y1 + y2) / 2;
					var cx = (x2 + nx) / 2, cy = (y2 + ny) / 2;
					var abx = (ax + bx) / 2, aby = (ay + by) / 2;
					var bcx = (bx + cx) / 2, bcy = (by + cy) / 2;
					var mx = (abx + bcx) / 2, my = (aby + bcy) / 2;
					cubicTo(ax, ay, abx, aby, mx, my, depth + 1);
					cubicTo(bcx, bcy, cx, cy, nx, ny, depth + 1);
					return;
				}
				var c1 = at(x1, y1);
				var c2 = at(x2, y2);
				var e = at(nx, ny);
				out += 'C' + pt(c1[0], c1[1]) + ' ' + pt(c2[0], c2[1]) + ' ' + pt(e[0], e[1]);
				x = nx;
				y = ny;
			}
			while (i < tok.length) {
				if (/[A-Za-z]/.test(tok[i])) {
					cmd = tok[i++];
					if (cmd === 'Z' || cmd === 'z') {
						if (x !== sx || y !== sy) {
							lineTo(sx, sy);
						}
						out += 'Z';
					}
					continue;
				}
				var rel = cmd === cmd.toLowerCase();
				var bx0 = rel ? x : 0;
				var by0 = rel ? y : 0;
				var C = cmd.toUpperCase();
				if (C === 'M') {
					x = num() + bx0;
					y = num() + by0;
					sx = x;
					sy = y;
					var m = at(x, y);
					out += 'M' + pt(m[0], m[1]);
					cmd = rel ? 'l' : 'L'; // more pairs after a move are lines
				} else if (C === 'L') {
					var lx = num() + bx0;
					lineTo(lx, num() + by0);
				} else if (C === 'H') {
					lineTo(num() + bx0, y);
				} else if (C === 'V') {
					lineTo(x, num() + by0);
				} else if (C === 'C') {
					var c1x = num() + bx0, c1y = num() + by0, c2x = num() + bx0, c2y = num() + by0;
					var ex = num() + bx0;
					cubicTo(c1x, c1y, c2x, c2y, ex, num() + by0, 0);
				} else if (C === 'Q') {
					var qx = num() + bx0, qy = num() + by0, qex = num() + bx0, qey = num() + by0;
					cubicTo(x + (qx - x) * 2 / 3, y + (qy - y) * 2 / 3, qex + (qx - qex) * 2 / 3, qey + (qy - qey) * 2 / 3, qex, qey, 0);
				} else if (C === 'A') {
					var arc = [num(), num(), num(), num(), num()];
					x = num() + bx0;
					y = num() + by0;
					var ae = at(x, y);
					out += 'A' + arc.join(' ') + ' ' + pt(ae[0], ae[1]);
				} else {
					i++;
				}
			}
			return out;
		}

		function word(parent, text, cls, size, x, y, anchor) {
			var node = svgEl('text', { 'class': cls, x: r1(x), y: r1(y) }, parent);
			if (anchor) {
				node.setAttribute('text-anchor', anchor);
			}
			node.style.fontSize = size + 'px';
			node.textContent = text;
			return node;
		}

		// Shrinks an SVG text to `max` grid units wide, never below `floor` of
		// its size; returns the width it ends up with.
		function fitWord(node, size, max, floor) {
			node.style.fontSize = size + 'px';
			var w = node.getComputedTextLength();
			if (w > max && w > 0) {
				node.style.fontSize = r1(Math.max(size * floor, size * max / w)) + 'px';
				w = node.getComputedTextLength();
			}
			return w;
		}

		function roundRect(x0, y0, x1, y1, r) {
			return 'M' + pt(x0 + r, y0) + 'H' + r1(x1 - r) + 'Q' + pt(x1, y0) + ' ' + pt(x1, y0 + r)
				+ 'V' + r1(y1 - r) + 'Q' + pt(x1, y1) + ' ' + pt(x1 - r, y1)
				+ 'H' + r1(x0 + r) + 'Q' + pt(x0, y1) + ' ' + pt(x0, y1 - r)
				+ 'V' + r1(y0 + r) + 'Q' + pt(x0, y0) + ' ' + pt(x0 + r, y0) + 'Z';
		}

		// Smooth curve through the points (Catmull-Rom as cubic Beziers).
		function smoothPath(p, closed) {
			var n = p.length;
			function at(i) {
				return closed ? p[(i + n) % n] : p[Math.max(0, Math.min(n - 1, i))];
			}
			var d = 'M' + pt(p[0][0], p[0][1]);
			var last = closed ? n : n - 1;
			for (var i = 0; i < last; i++) {
				var p0 = at(i - 1);
				var p1 = at(i);
				var p2 = at(i + 1);
				var p3 = at(i + 2);
				d += 'C' + pt(p1[0] + (p2[0] - p0[0]) / 6, p1[1] + (p2[1] - p0[1]) / 6)
					+ ' ' + pt(p2[0] - (p3[0] - p1[0]) / 6, p2[1] - (p3[1] - p1[1]) / 6)
					+ ' ' + pt(p2[0], p2[1]);
			}
			return closed ? d + 'Z' : d;
		}

		// A quick pen loop round (cx, cy): starts inside, goes once round and
		// overshoots, ending a little outside its start. `closed` gives the
		// plain shape under it.
		function penLoop(cx, cy, rx, ry, closed) {
			var steps = 30;
			var start = closed ? 0 : 205;
			var sweep = closed ? 360 : 392;
			var count = closed ? steps : steps + 1;
			var pts = [];
			for (var i = 0; i < count; i++) {
				var t = i / steps;
				var a = (start + sweep * t) * Math.PI / 180;
				var k = 1 + (closed ? 0.02 : 0.022) * Math.sin(3 * a + 0.6) + (closed ? 0 : 0.07 * (t - 0.5));
				pts.push([cx + Math.cos(a) * rx * k, cy + Math.sin(a) * ry * k]);
			}
			return smoothPath(pts, closed);
		}

		// Curved arrow from p0 to p3 with a drawn head.
		function penArrow(parent, p0, c1, c2, p3, dd, dur) {
			pen(parent, 'M' + pt(p0[0], p0[1]) + 'C' + pt(c1[0], c1[1]) + ' ' + pt(c2[0], c2[1]) + ' ' + pt(p3[0], p3[1]), dd, dur, 'is-thin');
			var a = Math.atan2(p3[1] - c2[1], p3[0] - c2[0]);
			var h1 = [p3[0] + Math.cos(a + Math.PI - 0.5) * 8, p3[1] + Math.sin(a + Math.PI - 0.5) * 8];
			var h2 = [p3[0] + Math.cos(a + Math.PI + 0.5) * 8, p3[1] + Math.sin(a + Math.PI + 0.5) * 8];
			pen(parent, 'M' + pt(h1[0], h1[1]) + 'L' + pt(p3[0], p3[1]) + 'L' + pt(h2[0], h2[1]), dd + dur, 0.2, 'is-thin');
		}

		// Back-and-forth pencil shading from x0 to x1: parallel slanted
		// strokes, each a little off the last.
		function scribble(x0, x1, yt, yb, step, lean, seed) {
			var r = seed * 9301 + 49297;
			function rnd() {
				r = (r * 9301 + 49297) % 233280;
				return r / 233280;
			}
			var d = 'M' + pt(x0, yb);
			for (var x = x0; x + lean < x1 && x < x1; x += step) {
				d += 'L' + pt(x + lean, yt + rnd() * 4) + 'L' + pt(x + step * 0.55, yb - rnd() * 4);
			}
			return d;
		}

		// A handwritten note over the drawing, written in the reading
		// direction of its own text. Null when there is nothing to write.
		function handNote(box, text, dd, dur, rot, cls) {
			if (!text) {
				return null;
			}
			var node = document.createElement('span');
			node.className = 'brikpanel-cartab-note' + (cls ? ' ' + cls : '');
			node.setAttribute('dir', 'auto');
			node.textContent = text;
			node.style.setProperty('--bp-cartab-dd', dd + 's');
			node.style.setProperty('--bp-cartab-dur', dur + 's');
			node.style.setProperty('--bp-cartab-rot', rot + 'deg');
			box.appendChild(node);
			return node;
		}

		function placeNote(node, x, y) {
			node.style.left = (x / GRID_W * 100) + '%';
			node.style.top = (y / GRID_H * 100) + '%';
		}

		// A note's size in grid units; the box is always GRID_W units wide.
		function gridW(box, node) {
			return node.offsetWidth * GRID_W / (box.clientWidth || GRID_W);
		}
		function gridH(box, node) {
			return node.offsetHeight * GRID_W / (box.clientWidth || GRID_W);
		}

		// Keeps a note inside maxW x maxH grid units: smaller first, then over
		// two lines; one that still does not fit is left out (with its arrow)
		// rather than spilling past the card. `base` is its size in px and cqw.
		function fitNote(box, node, maxW, maxH, base) {
			if (!node) {
				return null;
			}
			var px = base ? base[0] : 15;
			var cq = base ? base[1] : 6;
			var k = 1;
			while (gridW(box, node) > maxW && k > 0.75) {
				k -= 0.08;
				node.style.fontSize = 'max(' + Math.round(px * k) + 'px, ' + r1(cq * k) + 'cqw)';
			}
			if (gridW(box, node) > maxW) {
				node.classList.add('is-wrapped');
				node.style.width = (maxW / GRID_W * 100) + '%';
			}
			if (gridW(box, node) > maxW + 1 || gridH(box, node) > maxH) {
				node.parentNode.removeChild(node);
				return null;
			}
			return node;
		}

		/* -- The six drawings -- */

		// Ring: the discount, ringed by hand, with a note and an arrow.
		function drawRing(box, t) {
			var svg = sketchLayer(box);
			var back = svgEl('g', {}, svg);
			var lines = ink(svg);
			var words = svgEl('g', { 'class': 'brikpanel-cartab-appear', style: '--bp-cartab-dd:.15s' }, svg);
			var num = word(words, t.pct, 'brikpanel-cartab-num', 46, 0, 0);
			var off = word(words, t.off, 'brikpanel-cartab-word', 13, 0, 0);
			var note = fitNote(box, handNote(box, t.note, 1.95, 0.75, -4), 140, 62);
			var gap = 10;
			var nw = num.getComputedTextLength();
			var ww = off.getComputedTextLength();
			// The ring is the words plus 48; whatever the note leaves.
			var room = GRID_W - 8 - (note ? gridW(box, note) + 16 : 0);
			var scale = Math.min(1, (room - 48) / (nw + gap + ww));
			if (scale < 0.62 && note) {
				note.parentNode.removeChild(note);
				note = null;
				room = GRID_W - 8;
				scale = Math.min(1, (room - 48) / (nw + gap + ww));
			}
			scale = Math.max(0.5, scale);
			if (scale < 1) {
				num.style.fontSize = r1(46 * scale) + 'px';
				off.style.fontSize = r1(13 * scale) + 'px';
				gap *= scale;
				nw = num.getComputedTextLength();
				ww = off.getComputedTextLength();
			}
			var size = 46 * scale;
			var gw = nw + gap + ww;
			var rx = gw / 2 + 24;
			var ry = 34 * Math.min(1, scale + 0.2);
			var cy = 66;
			var total = 2 * rx + (note ? 16 + gridW(box, note) : 0);
			var cx = GRID_W / 2 - total / 2 + rx;
			num.setAttribute('x', r1(cx - gw / 2));
			num.setAttribute('y', r1(cy + size * 0.35));
			off.setAttribute('x', r1(cx - gw / 2 + nw + gap));
			off.setAttribute('y', r1(cy + size * 0.1));
			area(back, penLoop(cx, cy, rx * 0.96, ry * 0.92, true), 'shade', 1.3);
			pen(lines, penLoop(cx, cy, rx, ry, false), 0.5, 1.05);
			if (note) {
				var re = cx + rx;
				var top = cy - ry - 3;
				placeNote(note, re + 14, top);
				var sy = Math.max(cy - 8, top + gridH(box, note) + 4);
				penArrow(lines, [re + 30, sy], [re + 32, sy + 14], [re + 20, cy + 14], [re + 6, cy + 9], 1.65, 0.4);
			}
		}

		// Coupon: drawn, then cut along its perforation; the stub comes away.
		function drawCoupon(box, t) {
			var X0 = 112, X1 = 330, Y0 = 30, Y1 = 108, PX = 276, R = 9, NR = 6;
			var svg = sketchLayer(box);
			var root = svgEl('g', {}, svg);
			var left = svgEl('g', {}, root);
			var leftPen = ink(left);
			var stub = svgEl('g', { 'class': 'brikpanel-cartab-stub' }, root);
			var stubPen = ink(stub);
			// Two open outlines meeting at the perforation, so the stub can go.
			pen(leftPen, 'M' + pt(PX, Y0 + NR) + 'A' + NR + ' ' + NR + ' 0 0 1 ' + pt(PX - NR, Y0)
				+ 'H' + (X0 + R) + 'Q' + pt(X0, Y0) + ' ' + pt(X0, Y0 + R) + 'V' + (Y1 - R)
				+ 'Q' + pt(X0, Y1) + ' ' + pt(X0 + R, Y1) + 'H' + (PX - NR)
				+ 'A' + NR + ' ' + NR + ' 0 0 1 ' + pt(PX, Y1 - NR), 0.15, 0.9);
			pen(stubPen, 'M' + pt(PX, Y0 + NR) + 'A' + NR + ' ' + NR + ' 0 0 0 ' + pt(PX + NR, Y0)
				+ 'H' + (X1 - R) + 'Q' + pt(X1, Y0) + ' ' + pt(X1, Y0 + R) + 'V' + (Y1 - R)
				+ 'Q' + pt(X1, Y1) + ' ' + pt(X1 - R, Y1) + 'H' + (PX + NR)
				+ 'A' + NR + ' ' + NR + ' 0 0 0 ' + pt(PX, Y1 - NR), 0.6, 0.55);
			var dash = '';
			for (var y = Y0 + NR + 4; y <= Y1 - NR - 8; y += 8) {
				dash += 'M' + PX + ' ' + y + 'v4';
			}
			pen(leftPen, dash, 1.05, 0.45, 'is-thin');
			var bars = [290, 294, 297, 302, 306, 309, 314, 318];
			var barcode = '';
			for (var b = 0; b < bars.length; b++) {
				barcode += 'M' + bars[b] + ' ' + (54 + (b % 3) * 2) + 'V' + (86 - (b % 2) * 3);
			}
			pen(stubPen, barcode, 1.1, 0.4, 'is-thin');
			// The cut: both new edges draw down with the scissors.
			pen(leftPen, 'M' + (PX - 0.6) + ' ' + (Y0 + NR + 2) + 'V' + (Y1 - NR - 2), 1.95, 1.25, 'is-cut');
			pen(stubPen, 'M' + (PX + 0.6) + ' ' + (Y0 + NR + 2) + 'V' + (Y1 - NR - 2), 1.95, 1.25, 'is-cut');
			var mid = (X0 + PX - NR) / 2;
			var words = svgEl('g', { 'class': 'brikpanel-cartab-appear', style: '--bp-cartab-dd:.55s' }, left);
			fitWord(word(words, t.pct, 'brikpanel-cartab-num', 38, mid, 75, 'middle'), 38, PX - NR - X0 - 18, 0.55);
			fitWord(word(words, t.off, 'brikpanel-cartab-word', 11.5, mid, 94, 'middle'), 11.5, PX - NR - X0 - 18, 0.6);
			// Scissors, blades down, pivot at the origin.
			var at = svgEl('g', { transform: 'translate(' + PX + ' 15) scale(1.2)' }, root);
			var move = svgEl('g', { 'class': 'brikpanel-cartab-cut-move' }, svgEl('g', { 'class': 'brikpanel-cartab-cut-out' }, at));
			var leverA = ink(svgEl('g', { 'class': 'brikpanel-cartab-lever is-a' }, move));
			var leverB = ink(svgEl('g', { 'class': 'brikpanel-cartab-lever is-b' }, move));
			pen(leverA, 'M0 0C-2 6-3.5 13-2.5 21C-.5 15 .8 8 1 1', 1.35, 0.3);
			pen(leverA, 'M.5-1L4.5-7.5M7.5-8C3.5-8 2.6-12.6 4-15.8C6-19.6 11-19.2 12.2-15.4C13.3-11.6 11-8 7.5-8Z', 1.5, 0.4);
			pen(leverB, 'M0 0C2 6 3.5 13 2.5 21C.5 15-.8 8-1 1', 1.4, 0.3);
			pen(leverB, 'M-.5-1L-4.5-7.5M-7.5-8C-3.5-8-2.6-12.6-4-15.8C-6-19.6-11-19.2-12.2-15.4C-13.3-11.6-11-8-7.5-8Z', 1.55, 0.4);
			svgEl('circle', { cx: '0', cy: '0', r: '1.6', 'class': 'brikpanel-cartab-shape is-ink is-later', style: '--bp-cartab-dd:1.7s' }, move);
			var note = fitNote(box, handNote(box, t.note, 1.25, 0.7, -5), 100, 84);
			if (note) {
				placeNote(note, 6, 34);
				var nb = 34 + gridH(box, note);
				penArrow(ink(root), [40, nb + 6], [48, nb + 22], [80, nb + 20], [X0 - 5, 76], 1.75, 0.45);
			} else {
				root.setAttribute('transform', 'translate(' + r1(GRID_W / 2 - (X0 + X1) / 2) + ' 0)');
			}
		}

		// Envelope: drawn and sealed; the seal comes off, the flap opens and
		// the card with the discount rises out.
		function drawEnvelope(box, t) {
			var T0 = 58, BT = 124;
			var svg = sketchLayer(box);
			var root = svgEl('g', {}, svg);
			var open = svgEl('g', { 'class': 'brikpanel-cartab-flap-open' }, root);
			var letter = svgEl('g', { 'class': 'brikpanel-cartab-letter' }, root);
			var paper = ink(letter);
			var words = svgEl('g', {}, letter);
			// The card grows to its words, and the envelope round the card.
			var num = word(words, t.pct, 'brikpanel-cartab-num', 25, 0, 0, 'middle');
			var off = word(words, t.off, 'brikpanel-cartab-word', 9.5, 0, 0, 'middle');
			var textW = Math.max(fitWord(num, 25, 150, 0.7), fitWord(off, 9.5, 150, 0.7));
			var cardW = Math.max(84, textW + 24);
			var envW = cardW + 56;
			var note = fitNote(box, handNote(box, t.note, 3, 0.6, -5), Math.min(130, GRID_W - envW - 34), 46);
			var total = envW + (note ? 26 + gridW(box, note) : 0);
			var L = GRID_W / 2 - total / 2;
			var R = L + envW;
			var CX = L + envW / 2;
			var LX0 = CX - cardW / 2, LX1 = CX + cardW / 2, LY0 = T0 + 6, LY1 = BT - 4;
			var openPen = ink(open);
			area(openPen, 'M' + pt(L, T0) + 'L' + pt(CX, 24) + 'L' + pt(R, T0) + 'Z', 'shade');
			stroke(openPen, 'M' + pt(L, T0) + 'L' + pt(CX, 24) + 'L' + pt(R, T0));
			area(paper, 'M' + pt(LX0, LY0) + 'H' + r1(LX1) + 'V' + LY1 + 'H' + r1(LX0) + 'Z', 'paper');
			stroke(paper, 'M' + pt(LX0, LY0) + 'H' + r1(LX1) + 'V' + LY1 + 'H' + r1(LX0) + 'Z', 'is-thin');
			stroke(paper, 'M' + pt(LX0 + 12, LY0 + 52) + 'H' + r1(LX1 - 22), 'is-thin is-grey');
			num.setAttribute('x', r1(CX));
			num.setAttribute('y', LY0 + 30);
			off.setAttribute('x', r1(CX));
			off.setAttribute('y', LY0 + 44);
			letter.style.transformOrigin = r1(CX) + 'px 60px';
			// The front hides the card until it rises.
			var front = ink(root);
			area(front, roundRect(L, T0, R, BT, 5), 'paper');
			pen(front, roundRect(L, T0, R, BT, 5), 0.15, 0.85);
			pen(front, 'M' + pt(L + 3, BT - 3) + 'L' + pt(CX - 12, T0 + 36) + 'M' + pt(R - 3, BT - 3) + 'L' + pt(CX + 12, T0 + 36), 0.85, 0.4, 'is-thin');
			var flapPen = ink(svgEl('g', { 'class': 'brikpanel-cartab-flap' }, root));
			area(flapPen, 'M' + pt(L, T0) + 'L' + pt(CX, T0 + 40) + 'L' + pt(R, T0) + 'Z', 'paper', 0.95);
			pen(flapPen, 'M' + pt(L, T0) + 'L' + pt(CX, T0 + 40) + 'L' + pt(R, T0), 0.95, 0.5);
			var sealOut = svgEl('g', { 'class': 'brikpanel-cartab-seal-out' }, root);
			var sealIn = svgEl('g', { 'class': 'brikpanel-cartab-seal-in' }, sealOut);
			svgEl('circle', { cx: r1(CX), cy: T0 + 38, r: '9.5', 'class': 'brikpanel-cartab-shape is-ink' }, sealIn);
			word(sealIn, '%', 'brikpanel-cartab-seal-text', 10, CX, T0 + 41.6, 'middle');
			sealIn.style.transformOrigin = sealOut.style.transformOrigin = r1(CX) + 'px ' + (T0 + 38) + 'px';
			var marks = ink(root);
			pen(marks, 'M' + pt(LX0 - 8, 18) + 'l-9-4M' + pt(LX0 - 5, 9) + 'l-7-7M' + pt(LX0 + 3, 3) + 'l-2-9', 3, 0.3, 'is-thin');
			if (note) {
				placeNote(note, R + 18, 14);
				var nb = 14 + gridH(box, note);
				penArrow(marks, [R + 26, Math.max(44, nb + 4)], [R + 22, Math.max(56, nb + 14)], [R + 8, 58], [LX1 + 6, 46], 3.35, 0.4);
			}
		}

		// Gift box: drawn and tied; the lid flies off and the tag comes out.
		function drawGift(box, t) {
			var svg = sketchLayer(box);
			var root = svgEl('g', {}, svg);
			var lid = svgEl('g', { 'class': 'brikpanel-cartab-lid' }, root);
			var tag = svgEl('g', { 'class': 'brikpanel-cartab-tag' }, root);
			var tagPen = ink(tag);
			var words = svgEl('g', {}, tag);
			// The tag grows to its words, the box to the tag.
			var num = word(words, t.pct, 'brikpanel-cartab-num', 21, 0, 0, 'middle');
			var off = word(words, t.off, 'brikpanel-cartab-word', 8.5, 0, 0, 'middle');
			var textW = Math.max(fitWord(num, 21, 120, 0.7), fitWord(off, 8.5, 120, 0.7));
			var tagW = Math.max(54, textW + 16);
			var boxW = Math.max(92, tagW + 26);
			var lidW = boxW + 16;
			var note = fitNote(box, handNote(box, t.note, 2.7, 0.7, -4), 150, 50);
			// Centre what is on screen at the end: the tossed lid on the left,
			// the note (or the pop lines) on the right.
			var reach = note ? boxW / 2 + 30 + gridW(box, note) : tagW / 2 + 30;
			var CX = (GRID_W - (lidW / 2 + 50 + reach)) / 2 + lidW / 2 + 50;
			var BX0 = CX - boxW / 2, BX1 = CX + boxW / 2, BY0 = 74, BY1 = 124;
			var LX0 = CX - lidW / 2, LX1 = CX + lidW / 2, LY0 = 58, LY1 = 75;
			var TX0 = CX - tagW / 2, TX1 = CX + tagW / 2, TY0 = BY0 + 6, TY1 = BY1 - 2;
			var lidPen = ink(lid);
			area(lidPen, roundRect(LX0, LY0, LX1, LY1, 3), 'paper', 0.7);
			pen(lidPen, roundRect(LX0, LY0, LX1, LY1, 3), 0.55, 0.45);
			pen(lidPen, 'M' + pt(CX - 6, LY0) + 'V' + LY1 + 'M' + pt(CX + 6, LY0) + 'V' + LY1, 0.95, 0.25, 'is-thin');
			pen(lidPen, 'M' + pt(CX, LY0) + 'C' + pt(CX - 10, LY0 - 16) + ' ' + pt(CX - 28, LY0 - 15) + ' ' + pt(CX - 22, LY0 - 5)
				+ 'C' + pt(CX - 18, LY0 + 1) + ' ' + pt(CX - 8, LY0 + 2) + ' ' + pt(CX, LY0), 1.1, 0.35);
			pen(lidPen, 'M' + pt(CX, LY0) + 'C' + pt(CX + 10, LY0 - 16) + ' ' + pt(CX + 28, LY0 - 15) + ' ' + pt(CX + 22, LY0 - 5)
				+ 'C' + pt(CX + 18, LY0 + 1) + ' ' + pt(CX + 8, LY0 + 2) + ' ' + pt(CX, LY0), 1.3, 0.35);
			lid.style.transformOrigin = r1(CX) + 'px 66px';
			var tagShape = 'M' + pt(TX0 + 10, TY0) + 'H' + r1(TX1 - 10) + 'L' + pt(TX1, TY0 + 10) + 'V' + (TY1 - 4)
				+ 'Q' + pt(TX1, TY1) + ' ' + pt(TX1 - 4, TY1) + 'H' + r1(TX0 + 4) + 'Q' + pt(TX0, TY1) + ' ' + pt(TX0, TY1 - 4)
				+ 'V' + (TY0 + 10) + 'Z';
			area(tagPen, tagShape, 'paper');
			stroke(tagPen, tagShape);
			svgEl('circle', { cx: r1(CX), cy: TY0 + 8, r: '2.6', 'class': 'brikpanel-cartab-line is-thin' }, tagPen);
			pen(tagPen, 'M' + pt(CX, TY0 + 5) + 'C' + pt(CX - 7, TY0 - 5) + ' ' + pt(CX + 8, TY0 - 11) + ' ' + pt(CX + 2, TY0 - 21), 2.6, 0.3, 'is-thin');
			num.setAttribute('x', r1(CX));
			num.setAttribute('y', TY0 + 29);
			off.setAttribute('x', r1(CX));
			off.setAttribute('y', TY0 + 39);
			tag.style.transformOrigin = r1(CX) + 'px 100px';
			var body = ink(root);
			area(body, roundRect(BX0, BY0, BX1, BY1, 3), 'paper');
			pen(body, roundRect(BX0, BY0, BX1, BY1, 3), 0.15, 0.6);
			pen(body, 'M' + pt(CX - 6, BY0) + 'V' + BY1 + 'M' + pt(CX + 6, BY0) + 'V' + BY1, 0.75, 0.3, 'is-thin');
			pen(body, 'M' + pt(BX1 - 15, BY1 - 3) + 'L' + pt(BX1 - 3, BY1 - 15) + 'M' + pt(BX1 - 25, BY1 - 3) + 'L' + pt(BX1 - 3, BY1 - 25)
				+ 'M' + pt(BX1 - 35, BY1 - 3) + 'L' + pt(BX1 - 3, BY1 - 35), 0.9, 0.3, 'is-thin is-grey');
			var marks = ink(root);
			pen(marks, 'M' + pt(TX0 - 8, 22) + 'l-8-5M' + pt(CX - 4, 6) + 'l-2-8M' + pt(TX1 + 10, 16) + 'l7-6M' + pt(TX1 + 13, 28) + 'l9-1', 2.55, 0.3, 'is-thin');
			if (note) {
				placeNote(note, BX1 + 30, 66);
				penArrow(marks, [BX1 + 34, 62], [BX1 + 28, 50], [BX1 + 18, 48], [TX1 + 9, 50], 3.05, 0.4);
			}
		}

		// Eraser: the discount waits under pencil shading until an eraser
		// rubs it free.
		function drawEraser(box, t) {
			var CX0 = 124, CX1 = 334, CY0 = 26, CY1 = 110, MX = (CX0 + CX1) / 2;
			var base = sketchLayer(box);
			var lines = ink(base);
			pen(lines, roundRect(CX0, CY0, CX1, CY1, 10), 0.15, 0.85);
			// The prize, shown only where the eraser has been.
			var prize = sketchLayer(box, 'brikpanel-cartab-erase-num');
			var num = word(prize, t.pct, 'brikpanel-cartab-num', 40, 0, 0);
			var off = word(prize, t.off, 'brikpanel-cartab-word', 13, 0, 0);
			var gap = 9;
			var inner = CX1 - CX0 - 24;
			var nw = num.getComputedTextLength();
			var ww = off.getComputedTextLength();
			if (nw + gap + ww <= inner) {
				num.setAttribute('x', r1(MX - (nw + gap + ww) / 2));
				num.setAttribute('y', 82);
				off.setAttribute('x', r1(MX - (nw + gap + ww) / 2 + nw + gap));
				off.setAttribute('y', 72);
			} else {
				// Too long for one line: the word goes under the number.
				fitWord(num, 34, inner, 0.6);
				fitWord(off, 12, inner, 0.6);
				num.setAttribute('text-anchor', 'middle');
				num.setAttribute('x', r1(MX));
				num.setAttribute('y', 72);
				off.setAttribute('text-anchor', 'middle');
				off.setAttribute('x', r1(MX));
				off.setAttribute('y', 92);
			}
			// Pencil shading over it, rubbed away behind the eraser.
			var shade = ink(sketchLayer(box, 'brikpanel-cartab-erase-hatch'));
			pen(shade, scribble(136, 318, 40, 97, 4.4, 9, 1), 0.8, 0.55, 'is-hatch');
			pen(shade, scribble(140, 316, 42, 95, 6.5, -8, 2), 1.3, 0.4, 'is-hatch is-soft');
			// The eraser and the crumbs it leaves.
			var top = sketchLayer(box);
			var holder = svgEl('g', { transform: 'translate(131 68)' }, top);
			var rub = svgEl('g', { 'class': 'brikpanel-cartab-eraser-y' },
				svgEl('g', { 'class': 'brikpanel-cartab-eraser-x' },
					svgEl('g', { 'class': 'brikpanel-cartab-eraser-out' },
						svgEl('g', { 'class': 'brikpanel-cartab-eraser-in' }, holder))));
			var block = svgEl('g', { transform: 'rotate(-24)' }, ink(rub));
			area(block, roundRect(-17, -10, 17, 10, 3), 'paper');
			area(block, 'M-3 -10H14Q17 -10 17 -7V7Q17 10 14 10H-3Z', 'shade');
			stroke(block, roundRect(-17, -10, 17, 10, 3));
			stroke(block, 'M-3 -10V10', 'is-thin');
			var crumbs = [150, 176, 204, 233, 260, 287, 310];
			for (var c = 0; c < crumbs.length; c++) {
				svgEl('path', {
					d: 'M' + crumbs[c] + ' ' + (100 + (c % 2) * 4) + 'c1.5-1.6 3 1.6 4.5 0',
					'class': 'brikpanel-cartab-crumb',
					style: '--bp-cartab-dd:' + (Math.round((1.8 + (crumbs[c] - 131) / 200 * 1.2) * 100) / 100) + 's'
				}, top);
			}
			var note = fitNote(box, handNote(box, t.note, 3.05, 0.65, -6), 104, 70);
			if (note) {
				placeNote(note, 6, 40);
				var nb = 40 + gridH(box, note);
				penArrow(lines, [44, nb + 4], [52, nb + 18], [90, nb + 16], [CX0 - 6, 72], 3.4, 0.4);
			}
		}

		// Handwriting: the discount writes itself, the word follows, two
		// strokes underline it.
		function drawHandwriting(box, t) {
			var chars = String(t.pct).split('');
			var parts = [];
			var x = 0;
			for (var i = 0; i < chars.length; i++) {
				// Direction marks a translation may carry take no room.
				if (/[‎‏؜⁦-⁩]/.test(chars[i])) {
					continue;
				}
				if (/\s/.test(chars[i])) {
					x += 10;
					continue;
				}
				if (!Object.prototype.hasOwnProperty.call(PEN_GLYPHS, chars[i])) {
					// A sign the pen cannot write (an Arabic "٪"): the ring
					// stands in, without a note, since this style has none.
					drawRing(box, { pct: t.pct, off: t.off, note: '' });
					return;
				}
				parts.push({ glyph: PEN_GLYPHS[chars[i]], x: x });
				x += PEN_GLYPHS[chars[i]][0] + 4;
			}
			var tw = x - 4;
			var svg = sketchLayer(box);
			var lines = ink(svg);
			var off = handNote(box, t.off, 0, 0.55, -3, 'brikpanel-cartab-note--word');
			var gap = 14;
			var ww = off ? gridW(box, off) : 0;
			var stacked = false;
			if (off && tw + gap + ww > GRID_W - 12) {
				// Too long beside the number: the word goes under it.
				off = fitNote(box, off, GRID_W - 20, 40, [20, 9.3]);
				ww = off ? gridW(box, off) : 0;
				stacked = !!off;
			}
			var all = stacked ? tw : tw + (off ? gap + ww : 0);
			var x0 = GRID_W / 2 - all / 2;
			var glyphs = svgEl('g', { transform: 'translate(' + r1(x0 + 4) + ' ' + (stacked ? 4 : 16) + ') skewX(-8)' }, lines);
			var dd = 0.25;
			for (var p = 0; p < parts.length; p++) {
				var strokes = parts[p].glyph[1];
				var cell = svgEl('g', { transform: 'translate(' + parts[p].x + ' 0)' }, glyphs);
				for (var s = 0; s < strokes.length; s++) {
					var dur = s === 0 ? 0.42 : 0.2;
					pen(cell, strokes[s], dd, dur, 'is-glyph', parts[p].x);
					dd += dur + 0.04;
				}
				dd += 0.06;
			}
			var lineFrom = x0;
			var lineTo = x0 + all;
			var lineY = 93;
			if (off) {
				off.style.setProperty('--bp-cartab-dd', (Math.round(dd * 100) / 100) + 's');
				if (stacked) {
					lineFrom = GRID_W / 2 - ww / 2;
					lineTo = lineFrom + ww;
					lineY = 108;
					placeNote(off, lineFrom, 66);
				} else {
					placeNote(off, x0 + tw + gap, 46);
				}
			}
			var span = lineTo - lineFrom;
			pen(lines, 'M' + pt(lineFrom - 4, lineY) + 'C' + pt(lineFrom + span * 0.3, lineY - 4) + ' ' + pt(lineFrom + span * 0.65, lineY + 2) + ' ' + pt(lineTo + 6, lineY - 3), dd + 0.5, 0.35);
			pen(lines, 'M' + pt(lineFrom + span * 0.12, lineY + 8) + 'C' + pt(lineFrom + span * 0.4, lineY + 5) + ' ' + pt(lineFrom + span * 0.7, lineY + 9) + ' ' + pt(lineFrom + span * 0.9, lineY + 6), dd + 0.85, 0.25);
			var sx = stacked ? lineTo + 6 : x0 + all + 13;
			var sy = stacked ? 72 : 38;
			pen(lines, 'M' + pt(sx, sy) + 'l8-8M' + pt(sx + 4, sy + 10) + 'l11-1M' + pt(sx, sy + 19) + 'l8 6', dd + 1.1, 0.25, 'is-thin');
		}

		/* -- After signing up -- */

		// Pencil tick before the success line.
		function drawTick(line) {
			var tick = svgEl('svg', { viewBox: '0 0 20 20', 'class': 'brikpanel-cartab-tick', 'aria-hidden': 'true', focusable: 'false' });
			pen(ink(tick), 'M3.5 10.5L8 15L16.5 4.5', 0.15, 0.35);
			line.insertBefore(tick, line.firstChild);
		}

		// Pencil frame round the code. `still`: already drawn (the quiet code
		// panel), not drawing itself every time it opens.
		function frameCode(code, still) {
			if (!code) {
				return;
			}
			var w = code.offsetWidth + 6;
			var h = code.offsetHeight + 6;
			if (w < 7 || h < 7) {
				return;
			}
			var frame = svgEl('svg', {
				viewBox: '0 0 ' + w + ' ' + h,
				'class': 'brikpanel-cartab-code-frame' + (still ? ' is-still' : ''),
				'aria-hidden': 'true',
				focusable: 'false'
			});
			pen(ink(frame), roundRect(1.5, 1.5, w - 1.5, h - 1.5, 8), 0.4, 0.75, 'is-thin');
			// 3px outside the chip's own (now invisible) border, whatever a
			// theme made of that border.
			var at = -(3 + (parseFloat(window.getComputedStyle(code).borderLeftWidth) || 0)) + 'px';
			frame.style.setProperty('left', at, 'important');
			frame.style.setProperty('top', at, 'important');
			frame.style.setProperty('width', w + 'px', 'important');
			frame.style.setProperty('height', h + 'px', 'important');
			code.appendChild(frame);
			code.classList.add('has-frame');
		}

		/* ---------------- Popup ---------------- */

		// mode: false = dismissed by the visitor, true = gone for good,
		// 'collapse' = shrink into the tab it becomes (nothing was dismissed and
		// nothing is lost, so neither flag is written).
		function closePopup(mode) {
			if (!overlayEl) {
				return;
			}
			var el = overlayEl;
			var collapsing = mode === 'collapse';
			overlayEl = null;
			if (collapsing) {
				el.classList.add('is-collapsing');
			}
			el.classList.remove('is-open');
			window.setTimeout(function () {
				if (el.parentNode) {
					el.parentNode.removeChild(el);
				}
			}, collapsing ? 420 : 250);

			if (mode === true) {
				removeTeaser();
				return;
			}
			if (!collapsing) {
				lsSet(LS_DISMISS, String(Date.now()));
			}
			showTeaser(); // collapse into the floating tab, Klaviyo-style
		}

		/* ---------------- Shared modal shell ---------------- */

		// Overlay + card + close button + the background/Escape handlers, shared
		// by the signup popup and the code panel so there is one copy of the
		// dismissal plumbing.
		function buildShell(ariaLabel, extraClass, onClose) {
			var overlay = document.createElement('div');
			overlay.className = 'brikpanel-cartab-popup-overlay';
			overlay.setAttribute('role', 'presentation');

			var modal = document.createElement('div');
			modal.className = extraClass ? 'brikpanel-cartab-popup ' + extraClass : 'brikpanel-cartab-popup';
			modal.setAttribute('role', 'dialog');
			modal.setAttribute('aria-modal', 'true');
			modal.setAttribute('aria-label', ariaLabel);

			var close = document.createElement('button');
			close.type = 'button';
			close.className = 'brikpanel-cartab-popup-close';
			close.setAttribute('aria-label', cfg.i18n.close);
			close.textContent = '×';
			modal.appendChild(close);

			close.addEventListener('click', onClose);
			overlay.addEventListener('click', function (e) {
				if (e.target === overlay) {
					onClose();
				}
			});
			// Self-detaching: an auto-closed or collapsed card leaves this
			// listener bound to a node that is no longer in the document, so the
			// first key after that unbinds it. Without this, reopening the code
			// panel repeatedly would stack a dead listener each time.
			document.addEventListener('keydown', function onKey(e) {
				if (!overlay.parentNode) {
					document.removeEventListener('keydown', onKey);
					return;
				}
				if (e.key === 'Escape') {
					document.removeEventListener('keydown', onKey);
					onClose();
				}
			});

			overlay.appendChild(modal);
			return { overlay: overlay, modal: modal, close: close };
		}

		/* ---------------- Code panel ---------------- */

		// The way back to a code the visitor already earned. Deliberately not the
		// signup popup: there is nothing left to sign up for, so no form, no
		// offer drawing, no tick and no auto-close.
		function openCodePanel(rec) {
			if (overlayEl || !rec) {
				return;
			}
			removeTeaser();

			// 'collapse', not false: closing a code panel is not dismissing the
			// signup invitation, so it must not write LS_DISMISS. It also sends
			// the card back into the tab it came from.
			var shell = buildShell(cfg.i18n.couponTeaserLabel, 'brikpanel-cartab-popup--code', function () {
				closePopup('collapse');
			});

			var title = document.createElement('h2');
			title.className = 'brikpanel-cartab-popup-title';
			title.textContent = cfg.i18n.couponIntro;
			shell.modal.appendChild(title);

			var box = document.createElement('div');
			box.className = 'brikpanel-cartab-coupon';
			box.appendChild(buildCouponRow(rec.code));
			var minLine = buildMinimumLine(rec.min);
			if (minLine) {
				box.appendChild(minLine);
			}

			var hint = document.createElement('div');
			hint.className = 'brikpanel-cartab-coupon-hint';
			hint.textContent = rec.auto ? cfg.i18n.couponHintAuto : cfg.i18n.couponHint;
			box.appendChild(hint);
			shell.modal.appendChild(box);

			overlayEl = shell.overlay;
			document.body.appendChild(shell.overlay);
			// The same pencil frame as at signup, already drawn: this panel only
			// hands the code back.
			frameCode(box.querySelector('.brikpanel-cartab-coupon-code'), true);
			window.requestAnimationFrame(function () {
				shell.overlay.classList.add('is-open');
				var copyBtn = shell.modal.querySelector('.brikpanel-cartab-coupon-copy');
				if (copyBtn) {
					copyBtn.focus({ preventScroll: true });
				}
			});
		}

		/* ---------------- Coupon code row ---------------- */

		// One builder for both surfaces, so the code chip, the clipboard
		// fallback and the "Copied!" state can never drift apart.
		function buildCouponRow(code) {
			var codeRow = document.createElement('div');
			codeRow.className = 'brikpanel-cartab-coupon-row';

			var codeEl = document.createElement('span');
			codeEl.className = 'brikpanel-cartab-coupon-code';
			codeEl.textContent = code;

			var copy = document.createElement('button');
			copy.type = 'button';
			copy.className = 'brikpanel-cartab-coupon-copy';
			copy.textContent = cfg.i18n.copy;
			copy.addEventListener('click', function () {
				var mark = function () {
					copy.textContent = cfg.i18n.copied;
					copy.classList.add('is-copied');
				};
				if (navigator.clipboard && navigator.clipboard.writeText) {
					navigator.clipboard.writeText(code).then(mark).catch(mark);
				} else {
					var tmp = document.createElement('textarea');
					tmp.value = code;
					document.body.appendChild(tmp);
					tmp.select();
					try { document.execCommand('copy'); } catch (e) { /* noop */ }
					document.body.removeChild(tmp);
					mark();
				}
			});

			codeRow.appendChild(codeEl);
			codeRow.appendChild(copy);
			return codeRow;
		}

		// "Minimum spend $30.00" under the code, when the code has one. Nothing
		// at all when there is no amount, or when a cached older page left the
		// sentence out of its settings: no line is better than an English one.
		// A merchant's own wording without the %s is shown as they wrote it.
		function buildMinimumLine(amount) {
			var tpl = cfg.i18n.couponMinimum;
			if (typeof tpl !== 'string' || tpl === '' || typeof amount !== 'string' || amount === '') {
				return null;
			}
			var line = document.createElement('div');
			line.className = 'brikpanel-cartab-coupon-minimum';
			// A function replacement, so a "$" in the amount is never read as a
			// replacement pattern.
			line.textContent = tpl.indexOf('%s') === -1 ? tpl : tpl.replace('%s', function () {
				return amount;
			});
			return line;
		}

		function openPopup() {
			if (overlayEl || isDone()) {
				return;
			}
			removeTeaser(); // no floating tab behind the open popup
			var shell = buildShell(cfg.popup.title, '', function () { closePopup(false); });
			var overlay = shell.overlay;
			var modal = shell.modal;

			// The pencil drawing of the offer. It measures its words, so it is
			// drawn once the card is in the page; here it is only its box. It
			// carries the offer in words too: the merchant's title may not.
			var sketch = null;
			if (discount > 0) {
				sketch = document.createElement('div');
				sketch.className = 'brikpanel-cartab-sketch brikpanel-cartab-sketch--' + sketchStyle();
				sketch.setAttribute('role', 'img');
				sketch.setAttribute('aria-label', (offerText() + ' ' + String(cfg.i18n.offBadge || '')).trim());
				modal.appendChild(sketch);
			}

			var title = document.createElement('h2');
			title.className = 'brikpanel-cartab-popup-title';
			title.textContent = cfg.popup.title;
			modal.appendChild(title);

			if (cfg.popup.message) {
				var message = document.createElement('p');
				message.className = 'brikpanel-cartab-popup-message';
				message.textContent = cfg.popup.message;
				modal.appendChild(message);
			}

			var form = document.createElement('form');
			form.className = 'brikpanel-cartab-popup-form';
			form.noValidate = true;

			var input = document.createElement('input');
			input.type = 'email';
			input.required = true;
			input.autocomplete = 'email';
			input.className = 'brikpanel-cartab-popup-input';
			input.placeholder = cfg.popup.placeholder;
			input.setAttribute('aria-label', cfg.i18n.emailLabel);

			var button = document.createElement('button');
			button.type = 'submit';
			button.className = 'brikpanel-cartab-popup-button';
			var buttonLabel = document.createElement('span');
			buttonLabel.className = 'brikpanel-cartab-popup-button-label';
			buttonLabel.textContent = cfg.popup.button;
			button.appendChild(buttonLabel);
			// Sliding arrow micro-interaction (static SVG, no user content).
			var arrowNs = 'http://www.w3.org/2000/svg';
			var arrow = document.createElementNS(arrowNs, 'svg');
			arrow.setAttribute('class', 'brikpanel-cartab-popup-button-arrow');
			arrow.setAttribute('viewBox', '0 0 24 24');
			arrow.setAttribute('width', '15');
			arrow.setAttribute('height', '15');
			arrow.setAttribute('aria-hidden', 'true');
			var arrowPath = document.createElementNS(arrowNs, 'path');
			arrowPath.setAttribute('d', 'M5 12h14M13 6l6 6-6 6');
			arrowPath.setAttribute('fill', 'none');
			arrowPath.setAttribute('stroke', 'currentColor');
			arrowPath.setAttribute('stroke-width', '2');
			arrowPath.setAttribute('stroke-linecap', 'round');
			arrowPath.setAttribute('stroke-linejoin', 'round');
			arrow.appendChild(arrowPath);
			button.appendChild(arrow);

			var note = document.createElement('div');
			note.className = 'brikpanel-cartab-popup-note';
			note.setAttribute('aria-live', 'polite');

			form.appendChild(input);
			form.appendChild(button);
			modal.appendChild(form);
			modal.appendChild(note);

			var autoClose = null;

			function showSuccess(data) {
				// Inline !important because the form's own display rule is
				// !important (theme hardening) and would beat [hidden].
				form.style.setProperty('display', 'none', 'important');
				note.textContent = cfg.popup.success;
				note.className = 'brikpanel-cartab-popup-note is-success';
				drawTick(note);
				lsSet(LS_DONE, '1');
				removeTeaser();
				modal.classList.add('is-done');

				// Companion-plugin mode: the coupon was deferred to email —
				// show the "check your inbox" state instead of the code.
				var emailedCoupon = data && data.coupon_emailed;
				if (emailedCoupon) {
					var emailedBox = document.createElement('div');
					emailedBox.className = 'brikpanel-cartab-coupon';

					var emailedIntro = document.createElement('div');
					emailedIntro.className = 'brikpanel-cartab-coupon-intro';
					emailedIntro.textContent = cfg.i18n.couponEmailed.replace('%s', data.email || '');

					var emailedHint = document.createElement('div');
					emailedHint.className = 'brikpanel-cartab-coupon-hint';
					emailedHint.textContent = cfg.i18n.couponEmailedHint;

					emailedBox.appendChild(emailedIntro);
					emailedBox.appendChild(emailedHint);

					// "Edit email" affordance: only meaningful when the code was
					// emailed (an inline code is already on screen, no inbox to
					// miss). Needs the entry id the server returned to authorize
					// the correction.
					if (data && data.id) {
						var editWrap = document.createElement('div');
						editWrap.className = 'brikpanel-cartab-editmail';

						var editToggle = document.createElement('button');
						editToggle.type = 'button';
						editToggle.className = 'brikpanel-cartab-editmail-toggle';
						editToggle.textContent = cfg.i18n.editEmail;

						var editForm = document.createElement('form');
						editForm.className = 'brikpanel-cartab-editmail-form';
						editForm.noValidate = true;
						editForm.hidden = true;

						var editInput = document.createElement('input');
						editInput.type = 'email';
						editInput.required = true;
						editInput.autocomplete = 'email';
						editInput.className = 'brikpanel-cartab-editmail-input';
						editInput.value = data.email || '';
						editInput.setAttribute('aria-label', cfg.i18n.emailLabel);

						var editSave = document.createElement('button');
						editSave.type = 'submit';
						editSave.className = 'brikpanel-cartab-editmail-save';
						editSave.textContent = cfg.i18n.editSave;

						var editCancel = document.createElement('button');
						editCancel.type = 'button';
						editCancel.className = 'brikpanel-cartab-editmail-cancel';
						editCancel.textContent = cfg.i18n.editCancel;

						var editNote = document.createElement('div');
						editNote.className = 'brikpanel-cartab-editmail-note';
						editNote.setAttribute('aria-live', 'polite');

						editForm.appendChild(editInput);
						editForm.appendChild(editSave);
						editForm.appendChild(editCancel);
						editWrap.appendChild(editToggle);
						editWrap.appendChild(editForm);
						editWrap.appendChild(editNote);
						emailedBox.appendChild(editWrap);

						function openEdit() {
							window.clearTimeout(autoClose); // don't auto-close mid-edit
							editToggle.hidden = true;
							editForm.hidden = false;
							editNote.textContent = '';
							editInput.focus({ preventScroll: true });
							editInput.select();
						}

						function closeEdit() {
							editForm.hidden = true;
							editToggle.hidden = false;
							editNote.textContent = '';
						}

						editToggle.addEventListener('click', openEdit);
						editCancel.addEventListener('click', closeEdit);

						editForm.addEventListener('submit', function (e) {
							e.preventDefault();
							var next = (editInput.value || '').trim();
							if (!EMAIL_RE.test(next)) {
								editNote.textContent = cfg.i18n.invalidEmail;
								editNote.className = 'brikpanel-cartab-editmail-note is-error';
								return;
							}
							editSave.disabled = true;
							editCancel.disabled = true;
							updateEmail(data.id, next).then(function (json) {
								var d = (json && json.data) ? json.data : {};
								var addr = d.email || next;
								editForm.hidden = true;
								editToggle.hidden = false;
								editToggle.disabled = true;
								editToggle.textContent = cfg.i18n.editDone;
								editNote.textContent = '';
								emailedIntro.textContent = cfg.i18n.couponEmailed.replace('%s', addr);
								// Give the reader time again after a correction.
								window.clearTimeout(autoClose);
								autoClose = window.setTimeout(function () { closePopup(true); }, 9000);
							}).catch(function () {
								editSave.disabled = false;
								editCancel.disabled = false;
								editNote.textContent = cfg.i18n.error;
								editNote.className = 'brikpanel-cartab-editmail-note is-error';
							});
						});
					}

					modal.appendChild(emailedBox);
				}

				var hasCoupon = data && data.coupon;
				// Keep the code past this one screen. Nothing is stored on the
				// emailed path: there is no code in the payload, and the inbox is
				// where that visitor is being sent.
				var stored = hasCoupon ? saveCoupon(data) : null;
				if (hasCoupon) {
					var couponBox = document.createElement('div');
					couponBox.className = 'brikpanel-cartab-coupon';

					var intro = document.createElement('div');
					intro.className = 'brikpanel-cartab-coupon-intro';
					intro.textContent = cfg.i18n.couponIntro;

					var hint = document.createElement('div');
					hint.className = 'brikpanel-cartab-coupon-hint';
					hint.textContent = data.applied ? cfg.i18n.couponHintApplied
						: (data.autoapply ? cfg.i18n.couponHintAuto : cfg.i18n.couponHint);

					couponBox.appendChild(intro);
					couponBox.appendChild(buildCouponRow(data.coupon));
					var minimumLine = buildMinimumLine(data.minimum);
					if (minimumLine) {
						couponBox.appendChild(minimumLine);
					}
					couponBox.appendChild(hint);

					// Only promised when it actually happened — with storage
					// blocked there is no tab to come back to.
					if (stored) {
						var savedNote = document.createElement('div');
						savedNote.className = 'brikpanel-cartab-coupon-saved';
						savedNote.textContent = cfg.i18n.couponSaved;
						couponBox.appendChild(savedNote);
					}

					modal.appendChild(couponBox);
					frameCode(couponBox.querySelector('.brikpanel-cartab-coupon-code'), false);
				}

				// Auto-close: longer when a coupon (or the emailed-coupon
				// notice) is on screen so the visitor has time to read it.
				// Stored so the edit-email flow can defer it while correcting.
				autoClose = window.setTimeout(function () {
					// With a code saved the popup shrinks into the tab that now
					// holds it. Everything else keeps closing for good.
					closePopup(stored ? 'collapse' : true);
				}, (hasCoupon || emailedCoupon) ? 9000 : 2500);
			}

			form.addEventListener('submit', function (e) {
				e.preventDefault();
				var email = (input.value || '').trim();
				if (!EMAIL_RE.test(email)) {
					note.textContent = cfg.i18n.invalidEmail;
					note.className = 'brikpanel-cartab-popup-note is-error';
					return;
				}
				button.disabled = true;
				lastSent = ''; // popup submit always goes through even if checkout sent earlier
				send(email, 'popup').then(function (json) {
					showSuccess(json && json.data ? json.data : null);
				}).catch(function () {
					button.disabled = false;
					note.textContent = cfg.i18n.error;
					note.className = 'brikpanel-cartab-popup-note is-error';
				});
			});

			overlayEl = overlay;
			document.body.appendChild(overlay);

			// Opened from a frame callback, so a popup that comes up in a
			// background tab starts drawing when the tab is looked at.
			function reveal() {
				window.requestAnimationFrame(function () {
					overlay.classList.add('is-open');
					modal.classList.add('is-drawing');
					input.focus({ preventScroll: true });
				});
			}
			if (!sketch) {
				reveal();
				return;
			}
			whenHandFont(function (fontReady) {
				// Closed while the font was on its way: nothing left to draw.
				if (overlayEl !== overlay) {
					return;
				}
				drawSketch(sketch, fontReady);
				reveal();
			});
		}

		/**
		 * Arm the popup.
		 *
		 * Without the "Wait for cookie banner" setting this is the plain delay
		 * it has always been. With it, the delay still runs from page load, but
		 * when it ends the popup checks whether a cookie banner is on screen and
		 * waits for the visitor to answer it first — accepting or declining,
		 * both are answers — so the two never stack up at the entrance.
		 *
		 * Everything here is built so the popup cannot be lost:
		 *  - the cap timer is armed first and unconditionally, so a banner that
		 *    never reports, a visitor who ignores it, or a platform we cannot
		 *    read still ends with the popup on screen;
		 *  - a missing or broken signal module falls straight through to the
		 *    old behaviour;
		 *  - every path opens through maybeOpen(), which latches, so no
		 *    combination of timers can open it twice.
		 */
		function schedulePopup() {
			var t0 = Date.now();
			var delayMs = Math.max(0, Number(cfg.popup.delay) || 0) * 1000;
			var capMs = Math.max(1, Number(cfg.popup.maxWait) || 30) * 1000;
			var opened = false;
			var capTimer = null;
			var delayTimer = null;

			function maybeOpen() {
				if (opened) {
					return;
				}
				opened = true;
				if (capTimer) {
					window.clearTimeout(capTimer);
					capTimer = null;
				}
				if (delayTimer) {
					window.clearTimeout(delayTimer);
					delayTimer = null;
				}
				if (!isDone() && !teaserHidden() && !wasDismissed()) {
					openPopup();
				}
			}

			// The handwriting font, asked for a moment before the popup is due
			// so it is in by then; nobody who leaves earlier downloads it.
			window.setTimeout(askHandFont, Math.max(0, delayMs - 1500));

			var signal = window.brikpanelConsentSignal;
			var waiting = Number(cfg.popup.waitConsent) === 1 && signal
				&& typeof signal.answered === 'function' && typeof signal.onAnswer === 'function';

			if (!waiting) {
				delayTimer = window.setTimeout(maybeOpen, delayMs);
				return;
			}

			// Asked when the popup is about to show rather than at page load:
			// several platforms draw their banner a moment after this script
			// runs, so a verdict taken at load would miss them.
			function attempt() {
				delayTimer = null;
				if (opened) {
					return;
				}
				var clear = true;
				try {
					clear = signal.answered();
				} catch (err3) {}
				if (clear) {
					maybeOpen();
					return;
				}
				// Let the banner's own closing animation finish first.
				try {
					signal.onAnswer(function () {
						if (!opened && !delayTimer) {
							delayTimer = window.setTimeout(maybeOpen, 800);
						}
					});
				} catch (err4) {
					maybeOpen();
				}
			}

			capTimer = window.setTimeout(maybeOpen, capMs);
			delayTimer = window.setTimeout(attempt, delayMs);
		}

		/* ---------------- Entry flow ---------------- */

		if (savedCoupon()) {
			// The visitor owns an unexpired code, so "done" is no longer the end
			// of the road: the tab is how they get back to it. Shown straight
			// away: it is a small tab, not an interruption, and it is how the
			// visitor reaches a code they already own.
			showTeaser();
		} else if (!isDone() && !teaserHidden()) {
			if (wasDismissed()) {
				// Popup was closed before — stay collapsed as the floating tab.
				showTeaser();
			} else {
				schedulePopup();
			}
		}
	}
})();
