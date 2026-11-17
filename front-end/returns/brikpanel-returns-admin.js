/**
 * BrikPanel Returns screen.
 *
 * - Tables turn into cards when they do not fit (shared fit-table).
 * - Action buttons open native dialogs; a form is sent once.
 * - The refund dialog shows the amount as shipping and the deduction change.
 * - Photos open large in a viewer.
 *
 * Words come from brikpanelReturnsAdmin.i18n (wp_localize_script); money is
 * written by the shared window.brikpanelFormat.
 */
(function () {
	'use strict';

	var cfg = window.brikpanelReturnsAdmin || {};
	var t = cfg.i18n || {};

	function fitTables() {
		if (typeof window.brikpanelFitTable !== 'function') {
			return;
		}
		['bp-ret-table', 'bp-ret-lines'].forEach(function (id) {
			var table = document.getElementById(id);
			if (table) {
				window.brikpanelFitTable(table, { labels: 'head' });
			}
		});
	}

	function openDialog(id) {
		var dialog = document.getElementById(id);
		if (!dialog || typeof dialog.showModal !== 'function') {
			return;
		}
		dialog.showModal();
		var first = dialog.querySelector('textarea, input:not([type="hidden"]), button[type="submit"]');
		if (first) {
			first.focus();
		}
	}

	function money(amount, form) {
		var BF = window.brikpanelFormat;
		var symbol = form.getAttribute('data-symbol');
		var decimals = parseInt(cfg.decimals, 10);
		if (BF && typeof BF.money === 'function') {
			return BF.money(amount, { symbol: symbol == null ? undefined : symbol, decimals: isNaN(decimals) ? undefined : decimals });
		}
		return String(Math.round(amount * 100) / 100);
	}

	function refreshRefund(form) {
		var goods = parseFloat(form.getAttribute('data-goods')) || 0;
		var shipping = parseFloat(form.getAttribute('data-shipping')) || 0;
		var remaining = parseFloat(form.getAttribute('data-remaining')) || 0;
		var shipBox = form.querySelector('[data-bp-ret-shipping]');
		var deduction = form.querySelector('[data-bp-ret-deduction]');
		var total = goods + (shipBox && shipBox.checked ? shipping : 0);
		var cut = deduction ? parseFloat(deduction.value) || 0 : 0;
		var error = form.querySelector('[data-bp-ret-error]');
		var bad = cut < 0 || cut > total;
		if (error) {
			error.hidden = !bad;
			error.textContent = bad ? (t.tooMuch || '') : '';
		}
		if (deduction) {
			deduction.setCustomValidity(bad ? (t.tooMuch || '') : '');
		}
		total = Math.max(0, Math.min(total - Math.max(0, cut), remaining || total));
		var out = form.querySelector('[data-bp-ret-total]');
		if (out) {
			out.textContent = money(total, form);
		}
	}

	function bindForms() {
		Array.prototype.forEach.call(document.querySelectorAll('[data-bp-ret-form]'), function (form) {
			form.addEventListener('submit', function (event) {
				if (form.getAttribute('data-sent') === '1') {
					event.preventDefault();
					return;
				}
				form.setAttribute('data-sent', '1');
				// Not disabled: a disabled submit button would drop its own
				// name and value (the refund route) from the form.
				var button = event.submitter || form.querySelector('button[type="submit"]');
				if (button) {
					button.setAttribute('aria-disabled', 'true');
					if (t.working) {
						button.textContent = t.working;
					}
				}
			});
		});

		Array.prototype.forEach.call(document.querySelectorAll('[data-bp-ret-refund]'), function (form) {
			form.addEventListener('input', function () {
				refreshRefund(form);
			});
			form.addEventListener('change', function () {
				refreshRefund(form);
			});
			refreshRefund(form);
		});
	}

	function bindClicks() {
		document.addEventListener('click', function (event) {
			var opener = event.target.closest('[data-bp-ret-open]');
			if (opener) {
				event.preventDefault();
				openDialog(opener.getAttribute('data-bp-ret-open'));
				return;
			}
			var closer = event.target.closest('[data-bp-ret-close]');
			if (closer) {
				var dialog = closer.closest('dialog');
				if (dialog) {
					dialog.close();
				}
				return;
			}
			var photo = event.target.closest('[data-bp-ret-photo]');
			if (photo) {
				var viewer = document.getElementById('bp-ret-viewer');
				var img = viewer ? viewer.querySelector('[data-bp-ret-viewer-img]') : null;
				if (viewer && img && typeof viewer.showModal === 'function') {
					img.src = photo.getAttribute('data-bp-ret-photo');
					viewer.showModal();
				}
			}
		});

		// A click on the dimmed backdrop closes a dialog.
		Array.prototype.forEach.call(document.querySelectorAll('.bp-ret-dialog'), function (dialog) {
			dialog.addEventListener('click', function (event) {
				if (event.target === dialog) {
					dialog.close();
				}
			});
		});
	}

	function init() {
		fitTables();
		bindForms();
		bindClicks();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
