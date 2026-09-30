/**
 * The WhatsApp buttons of orders on the orders list and the single order screen.
 *
 * Follow-ups: a press on any WhatsApp button of an order opens the chat as
 * before (the link's own target). Alongside, the press is noted on the order
 * through brikpanel_whatsapp_pressed; when the order's status has a follow-up,
 * every WhatsApp button of that order on the page switches to the follow-up
 * draft and says so, so the next press opens the follow-up instead of the
 * first message again. Only while some status has a follow-up (cfg.followups).
 *
 * Changes made by other scripts: an inline status change or a saved tracking
 * number sends brikpanel:order-whatsapp { orderId, url, followup } with the
 * draft the server has now, and the order's buttons take it, first message or
 * follow-up alike.
 *
 * Loaded wherever the buttons show (brikpanel-order-whatsapp.php).
 */
(function () {
	'use strict';

	const cfg = window.brikpanelWhatsApp;
	if (!cfg || !cfg.ajax_url || !cfg.nonce) return;
	const t = cfg.i18n || {};

	// The row icon, the opened row's button, and on the order screen the button
	// under the billing address and the customer card's icon.
	const LINKS = 'a.brikpanel-wa-list-link, a.bp-od-wa, a.brikpanel-wa-btn, a.bp-osummary__wa'; // i18n-ignore: CSS selector

	const orderOf = $link => {
		if ($link.dataset.bpWaOrder) return $link.dataset.bpWaOrder;
		if (cfg.order_id) return String(cfg.order_id);
		const $row = $link.closest('tr');
		return $row && $row.id ? $row.id.replace(/^(order|post|bp-order-detail)-/, '') : '';
	};

	const isWaLink = url => {
		try {
			const parsed = new URL(String(url || ''));
			return parsed.protocol === 'https:' && parsed.hostname === 'wa.me';
		} catch (e) {
			return false;
		}
	};

	// Every WhatsApp button of the order, including those in opened-row panels
	// the list has not built yet: it builds them from the row's template.
	const linksOf = orderId => {
		const $links = [...document.querySelectorAll(LINKS)];
		document.querySelectorAll('template.bp-order-detail-tpl').forEach($template => {
			$links.push(...$template.content.querySelectorAll(LINKS));
		});
		return $links.filter($link => orderOf($link) === orderId);
	};

	const setState = (orderId, url, followup) => {
		const words = (followup ? t.followup : t.first) || {};
		linksOf(orderId).forEach($link => {
			if (isWaLink(url)) $link.setAttribute('href', url);
			if (followup) $link.dataset.bpWaFollowup = '1';
			else delete $link.dataset.bpWaFollowup;
			if ($link.classList.contains('bp-od-wa')) {
				const $label = $link.querySelector('.bp-od-wa-label');
				if ($label) $label.textContent = words.panel || '';
			} else if ($link.classList.contains('brikpanel-wa-btn')) {
				const $label = $link.querySelector('span');
				if ($label) $label.textContent = words.button || '';
			} else {
				$link.title = words.icon || '';
				$link.setAttribute('aria-label', words.icon || '');
			}
		});
	};

	document.addEventListener('brikpanel:order-whatsapp', event => {
		const detail = event.detail || {};
		const orderId = String(detail.orderId || '');
		if (!/^\d+$/.test(orderId) || !isWaLink(detail.url)) return;
		setState(orderId, detail.url, !!detail.followup);
	});

	if (!cfg.followups) return;

	const pending = new Set();
	// Capture: the list's own click handlers must not hide a press from here.
	document.addEventListener('click', event => {
		const $link = event.target.closest ? event.target.closest(LINKS) : null;
		if (!$link || $link.dataset.bpWaFollowup === '1') return;
		const orderId = orderOf($link);
		if (!/^\d+$/.test(orderId) || pending.has(orderId)) return;
		pending.add(orderId);
		const body = new URLSearchParams({ action: 'brikpanel_whatsapp_pressed', nonce: cfg.nonce, order_id: orderId });
		// keepalive: on a phone the WhatsApp app can take over before the answer lands.
		fetch(cfg.ajax_url, { method: 'POST', credentials: 'same-origin', body, keepalive: true })
			.then(response => (response.ok ? response.json() : null))
			.then(result => {
				if (result && result.success && result.data && result.data.followup) setState(orderId, result.data.url, true);
			})
			.catch(() => {})
			.finally(() => pending.delete(orderId));
	}, true);
})();
