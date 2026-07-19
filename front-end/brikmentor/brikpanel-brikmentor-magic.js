/**
 * BrikPanel — BrikMentor zero-paste purchase flow (client).
 *
 * Binds every launch CTA ([data-bm-checkout]) to: start a claim → open Stripe
 * Checkout → poll the relay for the minted key → auto-run the existing
 * installer chain → open BrikMentor. Resumes polling on load when a claim is
 * still pending.
 *
 * Every user-facing string comes from window.brikpanelBrikmentor.i18n — never
 * a literal here (BrikPanel i18n rule).
 */
(function () {
	'use strict';

	var cfg = window.brikpanelBrikmentor;
	if (!cfg || !cfg.i18n) {
		return;
	}

	var i18n = cfg.i18n;
	var pollTimer = null;
	var pollUntil = 0;
	var statusEl = null;
	var busy = false;

	// ── tiny DOM helpers ─────────────────────────────────────────────────────
	function el(tag, cls, txt) {
		var e = document.createElement(tag);
		if (cls) { e.className = cls; }
		if (txt != null) { e.textContent = txt; }
		return e;
	}

	function injectCss() {
		if (document.getElementById('brikpanel-bm-magic-css')) { return; }
		var css = ''
			+ '.brikpanel-bm-magic{position:fixed;right:24px;bottom:24px;z-index:99999;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;}'
			+ '.brikpanel-bm-magic__box{width:340px;max-width:calc(100vw - 48px);background:#fff;border:1px solid #e3e3e3;border-radius:12px;box-shadow:0 8px 28px rgba(0,0,0,.16);padding:18px 20px;display:flex;flex-direction:column;gap:10px;}'
			+ '.brikpanel-bm-magic__spin{width:22px;height:22px;border:2px solid #e3e3e3;border-top-color:#303030;border-radius:50%;animation:brikpanel-bm-magic-spin .8s linear infinite;}'
			+ '@keyframes brikpanel-bm-magic-spin{to{transform:rotate(360deg);}}'
			+ '.brikpanel-bm-magic__txt{font-size:14px;line-height:1.5;color:#303030;font-weight:550;}'
			+ '.brikpanel-bm-magic__key{font-family:monospace;font-size:12px;color:#303030;background:#f7f7f7;border:1px solid #e3e3e3;border-radius:6px;padding:8px 10px;word-break:break-all;}'
			+ '.brikpanel-bm-magic__act{display:flex;align-items:center;gap:8px;flex-wrap:wrap;}'
			+ '.brikpanel-bm-magic__btn{cursor:pointer;border:none;background:#303030;color:#fff;font-weight:550;font-size:13px;font-family:inherit;padding:8px 14px;border-radius:8px;box-shadow:inset 0 -1px 0 rgba(0,0,0,.2),inset 0 1px 0 rgba(255,255,255,.1);}'
			+ '.brikpanel-bm-magic__btn:hover{background:#1a1a1a;}'
			+ '.brikpanel-bm-magic__link{cursor:pointer;text-decoration:none;color:#303030;font-weight:550;font-size:13px;padding:8px 12px;border-radius:8px;box-shadow:inset 0 0 0 1px #e3e3e3;}'
			+ '.brikpanel-bm-magic__link:hover{background:#f7f7f7;color:#303030;}'
			+ '.brikpanel-bm-magic__x{cursor:pointer;background:transparent;border:none;color:#8a8a8a;font-size:13px;font-family:inherit;padding:8px 6px;}'
			+ '.brikpanel-bm-magic__x:hover{color:#303030;}';
		var style = document.createElement('style');
		style.id = 'brikpanel-bm-magic-css';
		style.textContent = css;
		document.head.appendChild(style);
	}

	function ensureStatus() {
		if (statusEl) { return statusEl; }
		injectCss();
		statusEl = el('div', 'brikpanel-bm-magic');
		var box = el('div', 'brikpanel-bm-magic__box');
		var spin = el('div', 'brikpanel-bm-magic__spin');
		var txt = el('div', 'brikpanel-bm-magic__txt');
		var act = el('div', 'brikpanel-bm-magic__act');
		box.appendChild(spin);
		box.appendChild(txt);
		box.appendChild(act);
		statusEl.appendChild(box);
		document.body.appendChild(statusEl);
		statusEl._spin = spin;
		statusEl._txt = txt;
		statusEl._act = act;
		statusEl._box = box;
		return statusEl;
	}

	function closeStatus() {
		if (statusEl && statusEl.parentNode) { statusEl.parentNode.removeChild(statusEl); }
		statusEl = null;
	}

	/**
	 * Render the status card.
	 * opts: {spin:bool, key:string, actions:[{label,onClick}|{label,href,blank}], close:bool}
	 */
	function render(text, opts) {
		opts = opts || {};
		var s = ensureStatus();
		s._spin.style.display = opts.spin === false ? 'none' : 'block';
		s._txt.textContent = text;
		s._act.innerHTML = '';

		// Remove any prior key row.
		if (s._keyRow) { s._box.removeChild(s._keyRow); s._keyRow = null; }
		if (opts.key) {
			var kr = el('div', 'brikpanel-bm-magic__key', opts.key);
			s._box.insertBefore(kr, s._act);
			s._keyRow = kr;
		}

		(opts.actions || []).forEach(function (a) {
			var node;
			if (a.href) {
				node = el('a', 'brikpanel-bm-magic__link', a.label);
				node.href = a.href;
				if (a.blank) { node.target = '_blank'; node.rel = 'noopener'; }
			} else {
				node = el('button', 'brikpanel-bm-magic__btn', a.label);
				node.addEventListener('click', a.onClick);
			}
			s._act.appendChild(node);
		});
		if (opts.close) {
			var x = el('button', 'brikpanel-bm-magic__x', i18n.close);
			x.addEventListener('click', closeStatus);
			s._act.appendChild(x);
		}
	}

	// ── ajax ─────────────────────────────────────────────────────────────────
	function post(action, extra) {
		var body = new URLSearchParams();
		body.append('action', action);
		body.append('_ajax_nonce', cfg.nonce);
		Object.keys(extra || {}).forEach(function (k) { body.append(k, extra[k]); });
		return fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
			.then(function (r) { return r.json(); });
	}

	// ── flow ─────────────────────────────────────────────────────────────────
	function start() {
		if (busy) { return; }
		busy = true;
		render(i18n.launching, { spin: true });

		post('brikpanel_brikmentor_claim_start', {}).then(function (res) {
			busy = false;
			if (!res || !res.success || !res.data || !res.data.checkout_url) {
				render(i18n.error, { spin: false, actions: [{ label: i18n.retry, onClick: start }], close: true });
				return;
			}
			var win = window.open(res.data.checkout_url, '_blank');
			if (!win) {
				render(i18n.popupBlocked, {
					spin: false,
					actions: [{ label: i18n.openCheckout, href: res.data.checkout_url, blank: true }],
					close: true
				});
			} else {
				render(i18n.waiting, { spin: true });
			}
			beginPolling();
		}).catch(function () {
			busy = false;
			render(i18n.error, { spin: false, actions: [{ label: i18n.retry, onClick: start }], close: true });
		});
	}

	function beginPolling() {
		pollUntil = Date.now() + (cfg.maxPollMin || 30) * 60000;
		schedule(500);
	}

	function schedule(ms) {
		if (pollTimer) { clearTimeout(pollTimer); }
		pollTimer = setTimeout(poll, ms);
	}

	function poll() {
		post('brikpanel_brikmentor_claim_poll', {}).then(function (res) {
			var status = res && res.data ? res.data.status : '';
			if (status === 'ready' && res.data.license) {
				stopPolling();
				install(res.data.license);
				return;
			}
			if (status === 'expired') {
				stopPolling();
				render(i18n.expired, { spin: false, actions: [{ label: i18n.retry, onClick: start }, { label: i18n.goToInstaller, href: cfg.settingsUrl }], close: true });
				return;
			}
			if (status === 'claimed') {
				stopPolling();
				render(i18n.claimed, { spin: false, actions: [{ label: i18n.goToInstaller, href: cfg.settingsUrl }], close: true });
				return;
			}
			if (status === 'idle') {
				stopPolling();
				closeStatus();
				return;
			}
			// pending
			if (Date.now() < pollUntil) {
				schedule(cfg.pollMs || 5000);
			} else {
				render(i18n.stillWaiting, { spin: false, actions: [{ label: i18n.openCheckout, onClick: start }], close: true });
			}
		}).catch(function () {
			if (Date.now() < pollUntil) { schedule(cfg.pollMs || 5000); }
		});
	}

	function stopPolling() {
		if (pollTimer) { clearTimeout(pollTimer); pollTimer = null; }
	}

	function install(license) {
		render(i18n.received, { spin: true });
		var body = new URLSearchParams();
		body.append('action', 'brikpanel_brikmentor_install');
		body.append('nonce', cfg.installNonce);
		body.append('bm_action', 'install');
		body.append('license', license);
		// Staged copy while the single request runs (download+install+activate).
		var t1 = setTimeout(function () { render(i18n.installing, { spin: true }); }, 600);
		var t2 = setTimeout(function () { render(i18n.activating, { spin: true }); }, 8000);

		fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
			.then(function (r) { return r.json(); })
			.then(function (data) {
				clearTimeout(t1); clearTimeout(t2);
				if (data && data.success) {
					var redirect = (data.data && data.data.redirect) ? data.data.redirect : cfg.openUrl;
					render(i18n.done, { spin: false, actions: [{ label: i18n.openBtn, href: redirect }] });
					setTimeout(function () { window.location.href = redirect; }, 1200);
				} else {
					installFailed(license);
				}
			})
			.catch(function () {
				clearTimeout(t1); clearTimeout(t2);
				installFailed(license);
			});
	}

	function installFailed(license) {
		render(i18n.failedInstall, {
			spin: false,
			key: i18n.yourKey + ' ' + license,
			actions: [{ label: i18n.goToInstaller, href: cfg.settingsUrl }],
			close: true
		});
	}

	// ── wiring ───────────────────────────────────────────────────────────────
	document.addEventListener('click', function (e) {
		var trigger = e.target && e.target.closest ? e.target.closest('[data-bm-checkout]') : null;
		if (!trigger) { return; }
		e.preventDefault();
		start();
	});

	// Resume polling if a claim was left pending (paid on another device, or
	// the page was reloaded mid-flow).
	if (cfg.hasPendingClaim) {
		render(i18n.waiting, { spin: true });
		beginPolling();
	}
})();
