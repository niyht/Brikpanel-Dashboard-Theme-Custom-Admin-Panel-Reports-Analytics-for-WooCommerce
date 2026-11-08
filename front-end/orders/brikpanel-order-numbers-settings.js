/**
 * Settings > BrikPanel > Orders, "Order numbers" card.
 *
 * Shows the Prefix, Start from and Next order number rows only while the
 * switch is on, and keeps "Next order number" in step with what is typed.
 * The number and any error come back from the server as finished, translated
 * text, so this file holds no words of its own. Visibility only: if it never
 * runs, every row simply stays visible.
 */
(function () {
	'use strict';

	var cfg = window.brikpanelOrderNumbers || {};

	function init() {
		var toggle = document.getElementById('brikpanel_order_numbers');
		var prefix = document.getElementById('brikpanel_order_numbers_prefix');
		var start = document.getElementById('brikpanel_order_numbers_start');
		var preview = document.querySelector('[data-bp-onum-preview]');
		var error = document.querySelector('[data-bp-onum-error]');
		if (!toggle || !prefix || !start || !preview) {
			return;
		}

		var rows = [prefix, start, preview].map(function (el) { return el.closest('tr'); });

		function sync() {
			rows.forEach(function (row) {
				if (row) {
					row.style.display = toggle.checked ? '' : 'none';
				}
			});
		}
		toggle.addEventListener('change', sync);
		sync();

		if (!cfg.ajaxUrl || !cfg.nonce || !window.fetch || !window.URLSearchParams) {
			return;
		}

		var timer = 0;
		var asked = 0;

		function ask() {
			var mine = ++asked;
			var body = new URLSearchParams();
			body.append('action', 'brikpanel_order_numbers_preview');
			body.append('_ajax_nonce', cfg.nonce);
			body.append('prefix', prefix.value);
			body.append('start', start.value);

			window.fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
				.then(function (response) { return response.ok ? response.json() : null; })
				.then(function (reply) {
					// An older answer that arrives late must not overwrite a newer one.
					if (mine !== asked || !reply || !reply.success || !reply.data) {
						return;
					}
					preview.textContent = reply.data.next || '';
					if (error) {
						error.textContent = reply.data.error || '';
					}
					if (reply.data.placeholder) {
						start.placeholder = reply.data.placeholder;
					}
				})
				.catch(function () {});
		}

		function later() {
			window.clearTimeout(timer);
			timer = window.setTimeout(ask, 400);
		}
		prefix.addEventListener('input', later);
		start.addEventListener('input', later);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
