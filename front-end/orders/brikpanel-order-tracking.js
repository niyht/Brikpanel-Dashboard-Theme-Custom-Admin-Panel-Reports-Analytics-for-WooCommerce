/**
 * Trakoo tracking numbers on the orders screens.
 *
 * Orders list: the "Add tracking number" button in an order's panel
 * (brikpanel-orders-compact.php) opens a small window and saves through
 * Trakoo's own handler, wotv_save_track_info_all_item, so Trakoo's email and
 * its "change the order status" option work as in its own window. The row is
 * then brought up to date without a reload: Trakoo's tracking cell, the
 * button, both WhatsApp links (their draft can carry the number now) and the
 * status badge when the status changed.
 *
 * Single order screen: Trakoo saves from its own window there; the WhatsApp
 * links drawn before the number existed are refreshed afterwards.
 *
 * Server side: front-end/orders/brikpanel-order-tracking.php.
 */
(function () {
	'use strict';

	const cfg = window.brikpanelOrderTracking;
	if (!cfg || !cfg.ajax_url || !cfg.nonce) return;
	const t = cfg.i18n || {};

	const post = data => {
		const body = new URLSearchParams();
		Object.keys(data).forEach(key => body.append(key, data[key] == null ? '' : String(data[key])));
		return fetch(cfg.ajax_url, { method: 'POST', credentials: 'same-origin', body });
	};

	// The order's new state as the server sees it (tracking, Trakoo's cell,
	// WhatsApp link, status), or null.
	const refresh = orderId => post({ action: 'brikpanel_order_tracking_refresh', nonce: cfg.nonce, order_id: orderId })
		.then(response => (response.ok ? response.json() : null))
		.then(result => (result && result.success && result.data ? result.data : null))
		.catch(() => null);

	// Only a https://wa.me/ link ever goes into an href.
	const whatsAppUrl = url => {
		try {
			const parsed = new URL(String(url || ''));
			return parsed.protocol === 'https:' && parsed.hostname === 'wa.me' ? String(url) : '';
		} catch (e) {
			return '';
		}
	};
	const setWhatsApp = ($links, url) => {
		const href = whatsAppUrl(url);
		if (href) $links.forEach($link => $link.setAttribute('href', href));
	};
	// Trakoo can move the order to a new status, which drops a WhatsApp press
	// noted in the old one: the buttons' words and follow-up state follow
	// (brikpanel-order-whatsapp.js; the links above work without it).
	const whatsAppState = (orderId, payload) => {
		if (!whatsAppUrl(payload.whatsapp)) return;
		document.dispatchEvent(new CustomEvent('brikpanel:order-whatsapp', {
			detail: { orderId: String(orderId), url: payload.whatsapp, followup: !!payload.whatsapp_followup },
		}));
	};

	// ── Single order screen ─────────────────────────────────────────────────
	if (cfg.screen === 'edit') {
		if (!window.jQuery) return;
		const orderId = String(cfg.order_id || '');
		window.jQuery(document).on('ajaxSuccess', (event, xhr, settings) => {
			const data = settings && typeof settings.data === 'string' ? new URLSearchParams(settings.data) : null;
			if (!data || !/^wotv_save_track_info_(all_)?item$/.test(data.get('action') || '') || data.get('order_id') !== orderId) return;
			const answer = xhr && xhr.responseJSON;
			if (answer && answer.status && answer.status !== 'success') return;
			refresh(orderId).then(payload => {
				if (!payload) return;
				setWhatsApp(document.querySelectorAll('a.brikpanel-wa-btn, a.bp-osummary__wa'), payload.whatsapp); // i18n-ignore: CSS selector
				whatsAppState(orderId, payload);
			});
		});
		return;
	}

	if (cfg.screen !== 'list') return;

	const carriers = Array.isArray(cfg.carriers) ? cfg.carriers : [];
	const statuses = Array.isArray(cfg.statuses) ? cfg.statuses : [];
	const column = typeof cfg.column === 'string' ? cfg.column : '';
	let defaultStatus = typeof cfg.default_status === 'string' ? cfg.default_status : '';
	let emailDefault = !!cfg.email_default;

	const make = (tag, attrs, text) => {
		const $el = document.createElement(tag);
		Object.keys(attrs || {}).forEach(name => {
			const value = attrs[name];
			if (value === true) $el.setAttribute(name, '');
			else if (value !== false && value != null) $el.setAttribute(name, String(value));
		});
		if (text != null) $el.textContent = text;
		return $el;
	};

	let $dialog = null;
	const ui = {};
	let current = null; // { orderId, $button, $row } of the open window
	let busy = false;
	let seq = 0;
	let lockedScroll = false;

	const lockScroll = () => {
		const $html = document.documentElement;
		if ($html.classList.contains('brikpanel-modal-lock')) return;
		$html.classList.add('brikpanel-modal-lock');
		lockedScroll = true;
	};
	const unlockScroll = () => {
		// A WooCommerce modal (the order preview) may hold the same lock.
		if (lockedScroll && !document.querySelector('.wc-backbone-modal')) {
			document.documentElement.classList.remove('brikpanel-modal-lock');
		}
		lockedScroll = false;
	};

	const setError = (message, $field) => {
		ui.error.textContent = message || '';
		ui.error.hidden = !message;
		[ui.number, ui.carrier].forEach($input => {
			$input.classList.toggle('is-error', $input === $field);
			if ($input === $field) $input.setAttribute('aria-invalid', 'true');
			else $input.removeAttribute('aria-invalid');
		});
		if ($field) $field.focus();
	};

	const setBusy = on => {
		busy = on;
		ui.save.disabled = on;
		ui.save.textContent = on ? (t.saving || '') : (t.save || '');
		ui.form.setAttribute('aria-busy', on ? 'true' : 'false');
	};

	// A carrier that delivers digitally (a custom carrier in Trakoo's settings)
	// takes no tracking number.
	const syncDigital = () => {
		const digital = ui.carrier.selectedOptions[0]?.dataset.digital === '1';
		ui.number.disabled = digital;
		ui.digital.hidden = !digital;
	};

	const fillCarriers = currentSlug => {
		ui.carrier.replaceChildren();
		const shown = carriers.filter(row => row[3] || row[0] === currentSlug);
		const wanted = currentSlug || (typeof cfg.default_carrier === 'string' ? cfg.default_carrier : '');
		const known = shown.some(row => row[0] === wanted);
		if (!known) ui.carrier.append(make('option', { value: '' }, t.choose || ''));
		shown.forEach(row => {
			const $option = make('option', { value: row[0] }, row[1]);
			if (row[2]) $option.dataset.digital = '1';
			ui.carrier.append($option);
		});
		ui.carrier.value = known ? wanted : '';
	};

	const build = () => {
		$dialog = make('dialog', { class: 'bp-otrack', 'aria-labelledby': 'bp-otrack-title', 'aria-describedby': 'bp-otrack-sub' });
		ui.form = make('form', { class: 'bp-otrack__form', novalidate: true });

		const $head = make('div', { class: 'bp-otrack__head' });
		const $titles = make('div', { class: 'bp-otrack__titles' });
		ui.title = make('h2', { class: 'bp-otrack__title', id: 'bp-otrack-title' });
		ui.sub = make('p', { class: 'bp-otrack__sub', id: 'bp-otrack-sub' });
		$titles.append(ui.title, ui.sub);
		ui.close = make('button', { type: 'button', class: 'bp-otrack__close', 'aria-label': t.close || '', title: t.close || '' });
		ui.close.innerHTML = '<svg width="16" height="16" viewBox="0 0 20 20" aria-hidden="true" focusable="false"><path d="M5 5l10 10M15 5L5 15" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round"/></svg>';
		$head.append($titles, ui.close);

		const $body = make('div', { class: 'bp-otrack__body' });
		ui.several = make('p', { class: 'bp-otrack__note', hidden: true }, t.several || '');

		const $numberField = make('div', { class: 'brikpanel-field' });
		ui.number = make('input', { type: 'text', id: 'bp-otrack-number', class: 'brikpanel-control', autocomplete: 'off', spellcheck: 'false', dir: 'auto' });
		ui.digital = make('p', { class: 'bp-otrack__hint', hidden: true }, t.digital || '');
		$numberField.append(make('label', { for: 'bp-otrack-number' }, t.number || ''), ui.number, ui.digital);

		const $carrierField = make('div', { class: 'brikpanel-field' });
		ui.carrier = make('select', { id: 'bp-otrack-carrier', class: 'brikpanel-control' });
		$carrierField.append(make('label', { for: 'bp-otrack-carrier' }, t.carrier || ''), ui.carrier);

		const $statusField = make('div', { class: 'brikpanel-field' });
		ui.status = make('select', { id: 'bp-otrack-status', class: 'brikpanel-control' });
		ui.status.append(make('option', { value: '' }, t.no_change || ''));
		statuses.forEach(row => ui.status.append(make('option', { value: row[0] }, row[1])));
		$statusField.append(make('label', { for: 'bp-otrack-status' }, t.status || ''), ui.status);

		const $check = make('label', { class: 'bp-otrack__check', for: 'bp-otrack-email' });
		ui.email = make('input', { type: 'checkbox', id: 'bp-otrack-email' });
		$check.append(ui.email, make('span', {}, t.email || ''));

		ui.error = make('p', { class: 'bp-otrack__error', role: 'alert', hidden: true });
		$body.append(ui.several, $numberField, $carrierField, $statusField, $check, ui.error);

		const $foot = make('div', { class: 'bp-otrack__foot' });
		ui.cancel = make('button', { type: 'button', class: 'brikpanel-btn brikpanel-btn--secondary bp-otrack__btn' }, t.cancel || '');
		ui.save = make('button', { type: 'submit', class: 'brikpanel-btn brikpanel-btn--primary bp-otrack__btn' }, t.save || '');
		$foot.append(ui.cancel, ui.save);

		ui.form.append($head, $body, $foot);
		$dialog.append(ui.form);
		// Outside the list table: its row clicks and phone card taps stay out.
		document.body.append($dialog);

		// Keys typed in the window are the window's: the orders page's own
		// shortcuts (Escape, "F" for search) and other plugins' listen on the
		// document. The browser's own Escape and Enter still work. Tab goes
		// round inside the window instead of stepping out to the browser.
		$dialog.addEventListener('keydown', event => {
			event.stopPropagation();
			if (event.key !== 'Tab') return;
			const stops = [...$dialog.querySelectorAll('button, input, select')].filter($el => !$el.disabled && $el.getClientRects().length); // i18n-ignore: CSS selector
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
		$dialog.addEventListener('cancel', event => {
			if (busy) event.preventDefault();
		});
		$dialog.addEventListener('close', () => {
			unlockScroll();
			const $opener = current && current.$button;
			current = null;
			if ($opener && $opener.isConnected) $opener.focus();
		});
		// A click on the dimmed page around the window closes it.
		$dialog.addEventListener('click', event => {
			if (event.target === $dialog && !busy) $dialog.close();
		});
		ui.close.addEventListener('click', () => {
			if (!busy) $dialog.close();
		});
		ui.cancel.addEventListener('click', () => {
			if (!busy) $dialog.close();
		});
		ui.carrier.addEventListener('change', () => {
			syncDigital();
			if (ui.carrier.value) setError('');
		});
		ui.number.addEventListener('input', () => {
			if (ui.number.value.trim()) setError('');
		});
		ui.form.addEventListener('submit', event => {
			event.preventDefault();
			save();
		});
	};

	const rowOf = orderId => document.getElementById('order-' + orderId) || document.getElementById('post-' + orderId);

	const open = $button => {
		if (busy) return;
		if (!$dialog) build();
		if (typeof $dialog.showModal !== 'function') {
			// Browsers without <dialog> (Safari before 15.4): the order screen has Trakoo's own window.
			const $open = $button.closest('.bp-od')?.querySelector('.bp-open-order');
			if ($open && $open.href) window.location.href = $open.href;
			return;
		}
		const orderId = $button.dataset.orderId || '';
		current = { orderId, $button, $row: rowOf(orderId) };

		const count = parseInt($button.dataset.count || '0', 10) || 0;
		ui.title.textContent = $button.dataset.has === '1' ? (t.edit || '') : (t.add || '');
		ui.sub.textContent = (t.order || '').replace('%s', $button.dataset.orderNumber || orderId);
		ui.several.hidden = count < 2;
		ui.number.value = $button.dataset.number || '';
		fillCarriers($button.dataset.carrier || '');
		ui.status.value = [...ui.status.options].some($option => $option.value === defaultStatus) ? defaultStatus : '';
		ui.email.checked = emailDefault;
		setBusy(false);
		setError('');
		syncDigital();

		lockScroll();
		$dialog.showModal();
		(ui.number.disabled ? ui.carrier : ui.number).focus();
	};

	// Bring the row up to date after a save.
	const apply = (job, payload) => {
		const $button = job.$button;
		const tracking = (payload && payload.tracking) || {};
		if ($button && $button.isConnected) {
			const has = payload ? !!tracking.has : true;
			$button.textContent = has ? (t.edit || '') : (t.add || '');
			$button.dataset.has = has ? '1' : '';
			if (payload) {
				$button.dataset.number = tracking.number || '';
				$button.dataset.carrier = tracking.carrier || '';
				$button.dataset.count = String(tracking.count || 0);
			} else {
				$button.dataset.number = job.number;
				$button.dataset.carrier = job.slug;
				$button.dataset.count = job.number ? '1' : '0';
			}
		}
		if (!payload || !job.$row) return;

		// Trakoo's cell, as the list would draw it now.
		if (typeof payload.column === 'string' && column) {
			const $template = document.createElement('template');
			$template.innerHTML = payload.column;
			const replaced = !document.dispatchEvent(new CustomEvent('brikpanel:order-cell-replace', {
				cancelable: true,
				detail: { row: job.$row, key: column, fragment: $template.content },
			}));
			if (!replaced) {
				const $cell = job.$row.querySelector(':scope > td.column-' + CSS.escape(column));
				if ($cell) $cell.replaceChildren($template.content);
			}
		}

		// The WhatsApp draft can carry the new number now.
		if (payload.whatsapp) {
			const $panel = document.getElementById('bp-order-detail-' + job.orderId);
			setWhatsApp([
				...job.$row.querySelectorAll('a.brikpanel-wa-list-link'),
				...($panel ? $panel.querySelectorAll('a.bp-od-wa') : []),
			], payload.whatsapp);
			whatsAppState(job.orderId, payload);
		}

		// Only when a new status was asked for: a change staged on the badge by
		// hand must not be dropped by a save that left the status alone.
		if (job.status && payload.status && payload.status.slug) {
			document.dispatchEvent(new CustomEvent('brikpanel:order-status-changed', {
				detail: { orderId: job.orderId, slug: payload.status.slug, label: payload.status.label || '' },
			}));
		}
	};

	let $toast = null;
	let toastTimer = 0;
	const toast = message => {
		if (!message) return;
		if (!$toast) {
			$toast = make('div', { class: 'bp-otrack-toast', role: 'status', 'aria-live': 'polite' });
			document.body.append($toast);
		}
		// Just under whichever bar is at the top of the window.
		const $bar = document.getElementById('brikpanel-topbar') || document.getElementById('wpadminbar');
		const top = $bar ? Math.max(0, $bar.getBoundingClientRect().bottom) : 0;
		$toast.style.setProperty('--bp-otrack-top', Math.round(top + 12) + 'px');
		$toast.textContent = message;
		$toast.classList.add('is-in');
		clearTimeout(toastTimer);
		toastTimer = setTimeout(() => {
			$toast.classList.remove('is-in');
			// Emptied once it has slid away, so a screen reader finds no stale text.
			toastTimer = setTimeout(() => { $toast.textContent = ''; }, 400);
		}, 3500);
	};

	const fail = (message, $field) => {
		setBusy(false);
		setError(message || t.failed || '', $field);
	};

	const save = () => {
		if (busy || !current) return;
		const number = ui.number.value.trim();
		const $option = ui.carrier.selectedOptions[0];
		const slug = ui.carrier.value;
		const digital = $option && $option.dataset.digital === '1';
		if (!slug) {
			setError(t.choose || '', ui.carrier);
			return;
		}
		if (!digital && !number) {
			setError(t.need_number || '', ui.number);
			return;
		}
		const status = ui.status.value;
		const job = {
			orderId: current.orderId,
			$button: current.$button,
			$row: current.$row,
			number: digital ? '' : number,
			slug,
			status: /^wc-[a-z0-9_-]+$/.test(status) ? status : '',
			email: ui.email.checked,
			seq: ++seq,
		};
		setError('');
		setBusy(true);

		post({
			action: 'wotv_save_track_info_all_item',
			action_nonce: cfg.trakoo_nonce || '',
			order_id: job.orderId,
			tracking_code: job.number,
			carrier_id: slug,
			// Trakoo only echoes the name back; it reads the carrier by its slug.
			carrier_name: $option ? $option.textContent : '',
			change_order_status: job.status,
			send_mail: job.email ? 'yes' : 'no',
			add_to_paypal: 'no',
		})
			.then(response => response.text())
			.then(text => {
				const body = text.trim();
				// WordPress answers "0" (or "-1") when the key has expired.
				if (body === '0' || body === '-1') throw new Error(t.expired || '');
				let answer = null;
				try {
					answer = JSON.parse(body);
				} catch (e) {
					answer = null;
				}
				if (answer && typeof answer === 'object' && answer.status && answer.status !== 'success') {
					throw new Error(typeof answer.message === 'string' && answer.message ? answer.message : (t.failed || ''));
				}
				return refresh(job.orderId).then(payload => ({ answer, payload }));
			})
			.then(({ answer, payload }) => {
				// No readable answer (a notice printed in front of it): count the
				// save only when the order now carries the number that was sent.
				const confirmed = answer
					|| (payload && payload.tracking && (job.number ? payload.tracking.number === job.number : payload.tracking.has));
				if (!confirmed) throw new Error(t.failed || '');

				// Trakoo keeps these as the defaults of its window; so does this one.
				defaultStatus = job.status;
				emailDefault = job.email;
				setBusy(false);
				// The window sits above everything, the notice included: close first.
				if (job.seq === seq && $dialog.open) $dialog.close();
				apply(job, payload);
				toast(payload ? t.saved : t.saved_reload);
			})
			.catch(error => {
				if (job.seq !== seq) return;
				if ($dialog.open) {
					fail(error && error.message);
				} else {
					setBusy(false);
					toast(error && error.message);
				}
			});
	};

	document.addEventListener('click', event => {
		const $button = event.target.closest ? event.target.closest('.bp-od-track') : null;
		if (!$button) return;
		event.preventDefault();
		open($button);
	});
})();
