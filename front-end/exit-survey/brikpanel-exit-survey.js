/**
 * Deactivation survey window on the Plugins screen.
 *
 * A click on BrikPanel's own Deactivate link opens the window instead of
 * leaving at once. "Skip and deactivate" follows the link straight away and
 * sends nothing. "Send and deactivate" posts the answer to admin-ajax (which
 * passes it on to brksoft.com) and follows the link when that is done, or
 * after a few seconds at most: the answer never holds the deactivation up.
 * Closing the window (the X, Escape, a click on the dimmed page) keeps
 * BrikPanel active.
 *
 * The click is caught on the document because a plugin search redraws the
 * rows. A modified click, or a browser without <dialog>, deactivates as before.
 *
 * "It slowed down my site" and "Something did not work" also carry a summary of
 * how pages arrived in this browser, worked out from the page arrival watch's
 * samples (front-end/net-watch/brikpanel-net-watch.js) and listed in the
 * window, under the comment box, before anything is sent.
 *
 * Server side and markup: includes/brikpanel-exit-survey.php.
 */
(function () {
	'use strict';

	const cfg = window.brikpanelExitSurvey;
	if (!cfg || !cfg.nonce || !cfg.plugin) return;
	const t = cfg.i18n || {};
	const diagReasons = Array.isArray(cfg.diag) ? cfg.diag : [];

	const $dialog = document.getElementById('brikpanel-exit-survey');
	if (!$dialog || typeof $dialog.showModal !== 'function') return;

	const $form = $dialog.querySelector('form');
	const $send = $dialog.querySelector('[data-bp-exit="send"]');
	const $skip = $dialog.querySelector('[data-bp-exit="skip"]');
	const $close = $dialog.querySelector('[data-bp-exit="close"]');
	if (!$form || !$send || !$skip || !$close) return;

	const sendLabel = $send.textContent;
	let target = '';
	let $opener = null;
	let busy = false;
	let left = false;
	let pressedOnBackdrop = false;

	const reason = () => {
		const $checked = $form.querySelector('input[name="reason"]:checked'); // i18n-ignore: CSS selector
		return $checked ? $checked.value : '';
	};

	const panelOf = key => $form.querySelector('.bp-exit__more[data-reason="' + key + '"]'); // i18n-ignore: CSS selector

	// Only the picked reason shows its questions; Send waits for a reason. The
	// privacy line names the speed numbers when the picked reason sends them.
	const sync = () => {
		const picked = reason();
		$form.querySelectorAll('.bp-exit__more').forEach($more => {
			$more.hidden = $more.dataset.reason !== picked;
		});
		const diag = diagReasons.includes(picked);
		$form.querySelectorAll('[data-bp-exit-privacy]').forEach($p => { // i18n-ignore: CSS selector
			$p.hidden = ($p.dataset.bpExitPrivacy === 'diag') !== diag;
		});
		$send.disabled = busy || !picked;
	};

	// The page arrival watch's samples for this site, summed up: how many,
	// how many were stuck and never came, medians in milliseconds, protocol
	// shares in percent. Nothing in it says which page or when.
	const netSummary = () => {
		let rows = [];
		try {
			const db = JSON.parse(window.localStorage.getItem(String(cfg.netKey || '')) || 'null');
			if (db && Array.isArray(db.s)) rows = db.s.slice(-40);
		} catch (e) {
			rows = [];
		}
		const now = Date.now() / 1000;
		const out = { n: 0, stuck: 0, never: 0 };
		const lists = { srv: [], ttfb: [], dom: [], load: [] };
		const proto = { h3: 0, h2: 0, h1: 0 };
		let protoSeen = 0;
		rows.forEach(r => {
			if (!r || typeof r !== 'object') return;
			out.n++;
			// A page still open in another tab is not "never": a minute's grace.
			if (r.w) {
				out.stuck++;
				if (!r.c && now - (Number(r.t) || 0) > 60) out.never++;
			}
			if (r.srv > 0) lists.srv.push(Number(r.srv));
			if (r.d && r.ttfb >= 0) lists.ttfb.push(Number(r.ttfb));
			if (r.c && r.dcl > 0) lists.dom.push(Number(r.dcl));
			if (r.d && r.ld > 0) lists.load.push(Number(r.ld));
			const p = String(r.pr || '');
			if (p) {
				protoSeen++;
				if (p.indexOf('h3') === 0) proto.h3++;
				else if (p === 'h2') proto.h2++;
				else if (p.indexOf('http/1') === 0) proto.h1++;
			}
		});
		Object.keys(lists).forEach(k => {
			const a = lists[k].filter(v => isFinite(v)).sort((x, y) => x - y);
			if (a.length) out[k] = Math.round(a[Math.floor(a.length / 2)]);
		});
		if (protoSeen) {
			Object.keys(proto).forEach(k => {
				out[k] = Math.round((proto[k] * 100) / protoSeen);
			});
		}
		return out;
	};

	const fmt = window.brikpanelFormat;
	const num = (v, d) => (fmt ? fmt.number(v, d || 0) : String(v));
	const secs = ms => String(t.sec || '%s').replace('%s', num(ms / 1000, 1));
	const pct = v => (fmt ? fmt.percent(v, 0) : String(v));

	// The browser's part of the list in the window, exactly what goes out.
	let net = null;
	const fillNet = () => {
		net = netSummary();
		const lines = [];
		if (!net.n) {
			lines.push([t.netNone, '']);
		} else {
			lines.push([t.netPages, num(net.n)]);
			lines.push([t.netStuck, num(net.stuck)]);
			lines.push([t.netNever, num(net.never)]);
			if (net.srv != null) lines.push([t.netSrv, secs(net.srv)]);
			if (net.ttfb != null) lines.push([t.netTtfb, secs(net.ttfb)]);
			if (net.dom != null) lines.push([t.netDom, secs(net.dom)]);
			if (net.load != null) lines.push([t.netLoad, secs(net.load)]);
			[['h3', 'HTTP/3'], ['h2', 'HTTP/2'], ['h1', 'HTTP/1.1']].forEach(([k, name]) => { // i18n-ignore: protocol names
				if (net[k]) lines.push([String(t.netProto || '%s').replace('%s', name), pct(net[k])]);
			});
		}
		$form.querySelectorAll('[data-bp-exit-net]').forEach($dl => { // i18n-ignore: CSS selector
			$dl.textContent = '';
			lines.forEach(([label, value]) => {
				const $row = document.createElement('div');
				$row.className = 'bp-exit__fact';
				const $dt = document.createElement('dt');
				$dt.textContent = label || '';
				$row.appendChild($dt);
				if (value !== '') {
					const $dd = document.createElement('dd');
					$dd.textContent = value;
					$row.appendChild($dd);
				}
				$dl.appendChild($row);
			});
		});
	};

	const lockScroll = on => document.documentElement.classList.toggle('bp-exit-lock', on);

	const setBusy = on => {
		busy = on;
		$skip.disabled = on;
		$send.disabled = on || !reason();
		$close.disabled = on;
		if (on) {
			$dialog.setAttribute('aria-busy', 'true');
		} else {
			$dialog.removeAttribute('aria-busy');
			$send.textContent = sendLabel;
		}
	};

	const reset = () => {
		left = false;
		$form.reset();
		setBusy(false);
		sync();
	};

	// Follow the Deactivate link, once.
	const leave = () => {
		if (left || !target) return;
		left = true;
		window.location.href = target;
	};

	// Focus is left to the browser's own dialog focusing, not put on the first
	// reason: WordPress rings a focused radio, and a ringed top answer reads as
	// already picked, which would tilt the very answer this window measures.
	const open = $link => {
		target = $link.href;
		$opener = $link;
		reset();
		if (diagReasons.length) fillNet();
		lockScroll(true);
		$dialog.showModal();
	};

	document.addEventListener('click', event => {
		if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
		const $link = event.target instanceof Element ? event.target.closest('span.deactivate a') : null; // i18n-ignore: CSS selector
		if (!$link || !$link.href) return;
		const $row = $link.closest('tr');
		if (!$row || $row.dataset.plugin !== cfg.plugin) return;
		event.preventDefault();
		open($link);
	});

	$form.addEventListener('change', event => {
		if (event.target.name !== 'reason') return;
		sync();
		const $more = panelOf(reason());
		if ($more) $more.scrollIntoView({ block: 'nearest' });
	});

	// Keys typed in the window are the window's: the Plugins screen and other
	// plugins listen on the document. Tab goes round inside the window. Enter on
	// a reason or the list does not send: only the Send button does.
	$dialog.addEventListener('keydown', event => {
		event.stopPropagation();
		if (event.key === 'Enter' && event.target instanceof HTMLInputElement) {
			event.preventDefault();
			return;
		}
		if (event.key !== 'Tab') return;
		const stops = [...$dialog.querySelectorAll('button, input, select, textarea, a[href]')] // i18n-ignore: CSS selector
			.filter($el => !$el.disabled && $el.getClientRects().length && !($el.type === 'radio' && !$el.checked && reason()));
		if (!stops.length) return;
		const first = stops[0];
		const last = stops[stops.length - 1];
		if (event.shiftKey && document.activeElement === first) {
			event.preventDefault();
			last.focus();
		} else if (!event.shiftKey && document.activeElement === last) {
			event.preventDefault();
			first.focus();
		}
	});

	// While the answer is on its way the window stays: deactivation follows.
	$dialog.addEventListener('cancel', event => {
		if (busy) event.preventDefault();
	});
	$dialog.addEventListener('close', () => {
		lockScroll(false);
		if (busy) return;
		if ($opener && $opener.isConnected) $opener.focus();
	});

	// A click on the dimmed page closes the window, but only when it also began
	// there: selecting text in a comment and letting go outside must not.
	$dialog.addEventListener('pointerdown', event => {
		pressedOnBackdrop = event.target === $dialog;
	});
	$dialog.addEventListener('click', event => {
		if (event.target === $dialog && pressedOnBackdrop && !busy) $dialog.close();
		pressedOnBackdrop = false;
	});

	$close.addEventListener('click', () => {
		if (!busy) $dialog.close();
	});

	$skip.addEventListener('click', () => {
		if (busy) return;
		setBusy(true);
		leave();
	});

	$form.addEventListener('submit', event => {
		event.preventDefault();
		const picked = reason();
		if (busy || !picked) return;
		setBusy(true);
		if (t.sending) $send.textContent = t.sending;

		const body = new URLSearchParams();
		body.append('action', 'brikpanel_exit_survey');
		body.append('nonce', cfg.nonce);
		body.append('reason', picked);
		const $panel = panelOf(picked);
		if ($panel) {
			const $feature = $panel.querySelector('select[name="feature"]'); // i18n-ignore: CSS selector
			const $comment = $panel.querySelector('textarea'); // i18n-ignore: CSS selector
			if ($feature) body.append('feature', $feature.value);
			if ($comment) body.append('comment', $comment.value);
		}
		if (net && diagReasons.includes(picked)) body.append('net', JSON.stringify(net));

		const controller = typeof AbortController === 'function' ? new AbortController() : null;
		const timer = window.setTimeout(() => {
			if (controller) controller.abort();
			leave();
		}, Number(cfg.timeout) || 5000);
		fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body, signal: controller ? controller.signal : undefined })
			.catch(() => null)
			.then(() => {
				window.clearTimeout(timer);
				leave();
			});
	});

	// Back from the next page with the old one restored from the cache: the
	// window must not come back half sent.
	window.addEventListener('pageshow', event => {
		if (!event.persisted) return;
		busy = false;
		if ($dialog.open) $dialog.close();
		lockScroll(false);
		reset();
	});
}());
