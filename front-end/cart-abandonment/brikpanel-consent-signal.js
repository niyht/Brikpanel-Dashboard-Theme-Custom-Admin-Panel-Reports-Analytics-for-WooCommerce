/**
 * BrikPanel — cookie banner "has the visitor answered?" signal.
 *
 * A storefront surface that interrupts the visitor (today: the cart
 * abandonment signup popup) should not land on top of a cookie banner the
 * visitor has not dealt with yet. This module answers one question and
 * nothing else:
 *
 *     has this visitor answered the banner — either way?
 *
 * Note "either way". This is NOT the consent gate used for tracking
 * (brikpanel_frontend_tracking_allowed / consentGranted in the tracker's
 * footer script): a visitor who DECLINES cookies has still answered, and a
 * signup form is not tracking, so a decline releases the surface exactly like
 * an accept does. The tracker keeps its own, stricter copy on purpose.
 *
 * Every unknown resolves to "answered", never to "keep waiting": a surface
 * that never appears is a far worse failure than one that appears while a
 * banner is still on screen. The caller owns the hard time cap on top of that.
 *
 * Renders nothing and reads no user-facing string, so it carries no
 * translatable text (i18n rule).
 *
 * Public API:
 *   window.brikpanelConsentSignal.answered()      -> bool, asked fresh each call
 *   window.brikpanelConsentSignal.state()         -> 'answered' | 'clear' | 'pending'
 *   window.brikpanelConsentSignal.onAnswer( fn )  -> fires at once if already answered
 *   window.brikpanel_popup_consent_answered()     -> merchant bridge for banners
 *                                                    that do not speak the
 *                                                    WordPress Consent API
 */
(function () {
	'use strict';

	var cfg = window.brikpanelConsent || {};

	var CATEGORY = typeof cfg.category === 'string' && cfg.category ? cfg.category : 'statistics';
	var OWN_COOKIE = typeof cfg.ownCookie === 'string' && cfg.ownCookie ? cfg.ownCookie : 'brikpanel_consent';
	var PREFIX_FALLBACK = typeof cfg.cookiePrefix === 'string' && cfg.cookiePrefix ? cfg.cookiePrefix : 'wp_consent';

	// Remembers that this browser already answered, so a visitor who declines
	// pays the waiting cost once instead of on every page view.
	//
	// Two strengths. A visitor who demonstrably acted is remembered for a
	// month. A verdict that merely says "nothing is asking right now" is
	// remembered for a day, so a store that adds a cookie banner later is not
	// stuck with a month-old assumption on returning browsers.
	var LS_LATCH = 'brikpanel_consent_answered';
	var LATCH_TTL = 30 * 86400000;
	var SOFT_LATCH_TTL = 86400000;
	var SOFT_MARK = 's';

	var POLL_MS = 400;
	// How long a page with no sign of a consent platform keeps being watched
	// for one appearing late. Long enough for a script-drawn banner, short
	// enough that stores without a banner pay almost nothing.
	var WATCH_MS = 8000;
	// Banners resolve in seconds, not minutes. Past this the poll is dead
	// weight; the caller's own cap has taken over long before.
	var POLL_LIMIT_MS = 60000;
	// A consent platform is installed but is asking nothing on screen. Give a
	// late banner this long to render before deciding none is coming.
	var GRACE_MS = 3000;
	// Cookie names a consent platform is likely to own. Only ever used to
	// notice CHANGE, so a false match costs nothing worse than resolving early
	// — which is the plugin's behaviour without this module at all.
	var BANNER_RE = /(consent|cookie|cmp|gdpr)/i;

	var settled = false;
	var pending = false;
	var sawBanner = false;
	var noPlatformSince = 0;
	var callbacks = [];
	var pollTimer = null;
	var snapshot = '';
	var t0 = Date.now();

	// Containers a consent platform names after what it does. Matching on the
	// id/class vocabulary rather than on vendor names keeps this working across
	// versions and forks, and every candidate still has to look like a banner
	// (fixed, on screen, big enough, with something to click) before it counts.
	var BANNER_SEL = [
		'[id*="cookie" i]', '[class*="cookie" i]',
		'[id*="consent" i]', '[class*="consent" i]',
		'[id*="gdpr" i]', '[class*="gdpr" i]',
		'[id*="cerez" i]', '[class*="cerez" i]'
	].join(',');

	function readCookie(name) {
		var parts = document.cookie ? document.cookie.split(';') : [];
		for (var i = 0; i < parts.length; i++) {
			var p = parts[i].trim();
			if (p.indexOf(name + '=') === 0) {
				return p.slice(name.length + 1);
			}
		}
		return '';
	}

	// The API exposes its prefix at runtime; the localized value is what PHP
	// resolved for this site and is the fallback when the API is not loaded.
	function prefix() {
		try {
			if (typeof consent_api !== 'undefined' && consent_api && consent_api.cookie_prefix) {
				return consent_api.cookie_prefix;
			}
		} catch (e) {}
		return PREFIX_FALLBACK;
	}

	function decisionCookie() {
		return readCookie(prefix() + '_' + CATEGORY);
	}

	// Whether a consent platform is actually driving the Consent API. With the
	// API installed but no banner configured this is empty. Same test as the
	// tracker's footer script and brikpanel_consent_granted() in PHP.
	function consentTypeDefined() {
		var type = '';
		try {
			if (typeof consent_api !== 'undefined' && consent_api && consent_api.consent_type) {
				type = consent_api.consent_type;
			}
		} catch (e) {}
		return !!(type || window.wp_consent_type || window.wp_fallback_consent_type);
	}

	// Any evidence at all that a Consent-API-speaking banner exists here.
	function consentApiSeen() {
		if (typeof wp_has_consent === 'function') {
			return true;
		}
		try {
			if (typeof consent_api !== 'undefined' && consent_api) {
				return true;
			}
		} catch (e) {}
		return !!(window.wp_consent_type || window.wp_fallback_consent_type);
	}

	function latchFresh() {
		try {
			var raw = window.localStorage.getItem(LS_LATCH) || '';
			var soft = raw.charAt(raw.length - 1) === SOFT_MARK;
			var ts = parseInt(raw, 10);
			return !!ts && Date.now() - ts < (soft ? SOFT_LATCH_TTL : LATCH_TTL);
		} catch (e) {
			return false;
		}
	}

	function writeLatch(firm) {
		try {
			window.localStorage.setItem(LS_LATCH, String(Date.now()) + (firm ? '' : SOFT_MARK));
		} catch (e) {}
	}

	function bannerCookies() {
		var wanted = prefix() + '_';
		var parts = document.cookie ? document.cookie.split(';') : [];
		var out = [];
		for (var i = 0; i < parts.length; i++) {
			var p = parts[i].trim();
			var eq = p.indexOf('=');
			var name = eq === -1 ? p : p.slice(0, eq);
			if (name.indexOf(wanted) === 0 || BANNER_RE.test(name)) {
				out.push(p);
			}
		}
		return out;
	}

	// Compared against the page-load snapshot: a change means the visitor acted
	// on the banner, including the accept-after-preemptive-deny case that leaves
	// the category cookie's own value untouched.
	function bannerSignature() {
		return bannerCookies().sort().join('|');
	}

	function visible(el) {
		var st;
		try {
			st = window.getComputedStyle(el);
		} catch (e) {
			return false;
		}
		if (!st || st.display === 'none' || st.visibility === 'hidden' || Number(st.opacity) === 0) {
			return false;
		}
		if (st.position !== 'fixed' && st.position !== 'sticky') {
			return false;
		}
		var r = el.getBoundingClientRect();
		if (r.width < 180 || r.height < 56) {
			return false;
		}
		// Really on screen, not merely rendered: a banner that has been answered
		// is often left in the page and pushed out of the viewport instead of
		// being hidden, and that must not read as "still asking".
		var vh = window.innerHeight || 0;
		var vw = window.innerWidth || 0;
		var shownH = Math.min(r.bottom, vh) - Math.max(r.top, 0);
		var shownW = Math.min(r.right, vw) - Math.max(r.left, 0);
		return shownH >= 40 && shownW >= 120;
	}

	/**
	 * The consent banner currently asking the visitor something, if any.
	 *
	 * Deliberately conservative: a false positive costs a wait the cap ends,
	 * while a false negative costs nothing worse than the behaviour this plugin
	 * had before the gate existed.
	 */
	function visibleBanner() {
		var nodes;
		try {
			nodes = document.querySelectorAll(BANNER_SEL);
		} catch (e) {
			return null;
		}
		// A page built entirely out of "cookie" class names is not a banner.
		if (!nodes.length || nodes.length > 400) {
			return null;
		}
		for (var i = 0; i < nodes.length; i++) {
			var el = nodes[i];
			if (!visible(el)) {
				continue;
			}
			if (!el.querySelector('button,a,input[type="button"],input[type="submit"]')) {
				continue;
			}
			return el;
		}
		return null;
	}

	function stopPoll() {
		if (pollTimer) {
			window.clearInterval(pollTimer);
			pollTimer = null;
		}
	}

	function fire() {
		var waiting = callbacks;
		callbacks = [];
		for (var i = 0; i < waiting.length; i++) {
			// One broken callback must not strand the others.
			try {
				waiting[i]();
			} catch (e) {}
		}
	}

	/**
	 * The visitor demonstrably dealt with the banner. Permanent, remembered for
	 * LATCH_TTL so a decline is not paid for on every page view.
	 */
	function settle(firm) {
		if (settled) {
			return;
		}
		settled = true;
		pending = false;
		stopPoll();
		writeLatch(!!firm);
		fire();
	}

	/**
	 * Recompute the live state.
	 *
	 * Two different "answered"s live here. `settled` is the real one: the
	 * visitor acted, and nothing can take it back. `pending === false` without
	 * `settled` is provisional — no banner is asking anything right now — and
	 * stays under review, because plenty of platforms draw their banner a
	 * moment after this script has run. That is why the caller asks again when
	 * it is about to open, instead of trusting a verdict from page load.
	 *
	 * Order mirrors brikpanel_consent_granted() in PHP as far as the two can
	 * agree: the difference is that PHP asks "granted?" and this asks
	 * "answered?", so a decline settles it here and does not there.
	 */
	function recompute(isBoot) {
		if (settled) {
			return;
		}
		if (latchFresh() || readCookie(OWN_COOKIE) === '1') {
			settle(true);
			return;
		}

		var decision = decisionCookie();
		if (decision === 'allow') {
			settle(true);
			return;
		}

		// A banner on screen outranks everything else: it is the thing we are
		// waiting for, whatever the platform does or does not report.
		if (visibleBanner()) {
			sawBanner = true;
			pending = true;
			return;
		}
		if (sawBanner) {
			// It was up and it is gone, so the visitor dealt with it.
			settle(true);
			return;
		}

		// A platform's cookies changing means the visitor acted — but only once
		// we are past the window in which platforms write their own defaults.
		// Several write a full set of "deny" cookies before the visitor has
		// touched anything (see below), which is not an answer.
		if (!isBoot && Date.now() - t0 >= GRACE_MS && bannerSignature() !== snapshot) {
			settle(true);
			return;
		}

		var api = consentApiSeen();
		// A "deny" present before the visitor touched anything is NOT an answer:
		// CookieYes and friends announce deny for every category on the very
		// first page load. It is evidence of a platform, nothing more.
		var platform = api || !!decision || bannerCookies().length > 0;
		if (!platform) {
			// Nothing suggests a consent platform is here. Provisionally free,
			// still watched for a short while in case a banner is still coming.
			pending = false;
			noPlatformSince = noPlatformSince || Date.now();
			if (!isBoot && Date.now() - noPlatformSince > WATCH_MS) {
				stopPoll();
			}
			return;
		}
		noPlatformSince = 0;

		// Only while the API can actually be asked. Several platforms declare a
		// consent type without the API plugin being installed, and treating that
		// as "a banner is coming" would hold the popup back on every page view
		// of a visitor who answered long ago.
		if (!decision && api && consentTypeDefined() && typeof wp_has_consent === 'function') {
			// A regime is declared and no decision is recorded yet, so a banner
			// is up or about to be. The API's own answer still settles the
			// "this visitor needs no banner" case (geo-ip regimes).
			try {
				if (wp_has_consent(CATEGORY)) {
					// The platform says this visitor is not being asked at all.
					// True today; not a decision they made, so remember it softly.
					settle(false);
					return;
				}
			} catch (e) {}
			pending = true;
			return;
		}

		// A platform is installed but nothing is being asked on screen: either
		// this visitor answered on an earlier visit or no banner applies to
		// them. Give a late banner a moment to render, then stop waiting.
		if (Date.now() - t0 >= GRACE_MS) {
			settle(false);
			return;
		}
		pending = true;
	}

	function tick() {
		var was = pending;
		recompute(false);
		if (was && !pending && !settled) {
			fire();
		}
	}

	/**
	 * A Consent API decision arrived.
	 *
	 * The payload is an array carrying string properties, so hasOwnProperty is
	 * the only safe way to test it — a bare lookup can hit Array.prototype
	 * members.
	 *
	 * An 'allow' is always the visitor: no banner grants statistics on its own.
	 * Anything else is NOT taken at face value, because CookieYes and others
	 * broadcast "deny" for every category on the very first page load, before
	 * the visitor has touched the banner. Those go back through the normal
	 * state check, which keeps waiting while a banner is on screen and settles
	 * once it is gone.
	 */
	function onConsentChange(e, extra) {
		var detail = (e && e.detail) || extra;
		if (!detail || !Object.prototype.hasOwnProperty.call(detail, CATEGORY)) {
			return;
		}
		if (detail[CATEGORY] === 'allow') {
			settle(true);
			return;
		}
		tick();
	}

	function onTypeDefined() {
		tick();
	}

	function boot() {
		snapshot = bannerSignature();
		recompute(true);

		// Banners write their decision, hide themselves, or dispatch an event —
		// most do two of the three and some only one, so watch for all of them.
		if (!settled) {
			pollTimer = window.setInterval(function () {
				if (settled || Date.now() - t0 > POLL_LIMIT_MS) {
					stopPoll();
					return;
				}
				tick();
			}, POLL_MS);
		}

		// Current WP Consent API dispatches native CustomEvents. Older and
		// forked builds trigger them through jQuery, which never reaches
		// addEventListener, so bind both — settle() is idempotent.
		document.addEventListener('wp_listen_for_consent_change', onConsentChange);
		document.addEventListener('wp_consent_type_defined', onTypeDefined);
		if (window.jQuery) {
			window.jQuery(document).on('wp_listen_for_consent_change', onConsentChange);
			window.jQuery(document).on('wp_consent_type_defined', onTypeDefined);
		}
	}

	window.brikpanelConsentSignal = {
		// Live answer: false only while a banner is actually waiting on the
		// visitor. Ask again at the moment you are about to interrupt them.
		answered: function () {
			if (!settled) {
				recompute(false);
			}
			return settled || !pending;
		},
		state: function () {
			return this.answered() ? (settled ? 'answered' : 'clear') : 'pending';
		},
		onAnswer: function (fn) {
			if (typeof fn !== 'function') {
				return;
			}
			if (this.answered()) {
				try {
					fn();
				} catch (e) {}
				return;
			}
			callbacks.push(fn);
		}
	};

	// Merchant bridge, in the family of window.brikpanel_start_tracking().
	window.brikpanel_popup_consent_answered = function () {
		settle(true);
	};

	// Fail open on our own bugs: a thrown exception must release the waiting
	// surface, never strand it.
	try {
		boot();
	} catch (err) {
		// Fail open on our own bug, but do not remember that verdict for long.
		settle(false);
	}
})();
