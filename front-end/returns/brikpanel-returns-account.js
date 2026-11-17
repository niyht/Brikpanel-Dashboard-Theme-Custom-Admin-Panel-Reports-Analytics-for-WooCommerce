/**
 * BrikPanel customer returns: My Account form helpers.
 *
 * The forms work without this file. With it: a quantity is only sent for a
 * ticked item, too many or too large photos are caught before the upload, and
 * the submit button cannot send the form twice.
 *
 * Words come from brikpanelReturns.i18n (wp_localize_script).
 */
(function () {
	'use strict';

	var cfg = window.brikpanelReturns || {};
	var t = cfg.i18n || {};

	function syncQty(form) {
		var boxes = form.querySelectorAll('[data-bp-return-check]');
		Array.prototype.forEach.call(boxes, function (box) {
			var row = box.closest('.brikpanel-return__item');
			var select = row ? row.querySelector('[data-bp-return-qty]') : null;
			if (select) {
				select.disabled = !box.checked;
			}
		});
	}

	function checkPhotos(input) {
		var message = '';
		var files = input.files || [];
		var max = parseInt(cfg.maxPhotos, 10) || 0;
		var bytes = parseInt(cfg.maxBytes, 10) || 0;
		if (max && files.length > max) {
			message = t.tooMany || '';
		} else if (bytes) {
			for (var i = 0; i < files.length; i++) {
				if (files[i].size > bytes) {
					message = t.tooBig || '';
					break;
				}
			}
		}
		input.setCustomValidity(message);
		if (message) {
			input.reportValidity();
		}
	}

	function bind(form) {
		syncQty(form);

		form.addEventListener('change', function (event) {
			var target = event.target;
			if (target.matches('[data-bp-return-check]')) {
				target.setCustomValidity('');
				syncQty(form);
			} else if (target.matches('[data-bp-return-photos]')) {
				checkPhotos(target);
			}
		});

		form.addEventListener('submit', function (event) {
			var boxes = form.querySelectorAll('[data-bp-return-check]');
			if (boxes.length) {
				var any = Array.prototype.some.call(boxes, function (box) {
					return box.checked;
				});
				if (!any) {
					event.preventDefault();
					boxes[0].setCustomValidity(t.pick || '');
					boxes[0].reportValidity();
					return;
				}
			}
			var button = form.querySelector('[data-bp-return-submit]');
			if (!button) {
				return;
			}
			if (button.getAttribute('aria-disabled') === 'true') {
				event.preventDefault();
				return;
			}
			button.setAttribute('aria-disabled', 'true');
			if (t.sending) {
				button.textContent = t.sending;
			}
		});

		// Back from the next page (back/forward cache): the button works again.
		window.addEventListener('pageshow', function (event) {
			if (!event.persisted) {
				return;
			}
			var button = form.querySelector('[data-bp-return-submit]');
			if (button && button.getAttribute('aria-disabled') === 'true') {
				button.removeAttribute('aria-disabled');
				if (button.dataset.label) {
					button.textContent = button.dataset.label;
				}
			}
		});

		var submit = form.querySelector('[data-bp-return-submit]');
		if (submit) {
			submit.dataset.label = submit.textContent;
		}
	}

	function init() {
		var forms = document.querySelectorAll('[data-bp-return-form]');
		Array.prototype.forEach.call(forms, bind);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
