/**
 * BrikPanel - the message strip at the bottom of a phone screen ("snackbar").
 *
 *   brikpanelSnack.show(text, { tone: 'success'|'error'|'info', undo: fn, ms })
 *   brikpanelSnack.hide()
 *
 * One strip on <body>, a live region screen readers announce. With `undo` it
 * carries an Undo button (the screens use it instead of asking "are you
 * sure?" before a trash). A bar fixed to the bottom of the screen lifts it:
 * the screen sets --bp-snack-lift on <html> to the bar's height.
 */
(function (window, document) {
	'use strict';

	if (window.brikpanelSnack) {
		return;
	}

	var L = window.brikpanelSnackL10n || {};
	var ICONS = {
		success: '<svg width="20" height="20" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m4.5 10.5 3.5 3.5 7.5-8"/></svg>',
		error: '<svg width="20" height="20" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true" focusable="false"><circle cx="10" cy="10" r="7"/><path d="M10 6.5v4.5M10 13.6v.1"/></svg>',
		info: '<svg width="20" height="20" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true" focusable="false"><circle cx="10" cy="10" r="7"/><path d="M10 9v4.5M10 6.4v.1"/></svg>'
	};

	var root = null;
	var icon = null;
	var text = null;
	var undoBtn = null;
	var timer = 0;
	var undoFn = null;

	function ensure() {
		if (root) {
			return;
		}
		root = document.createElement('div');
		root.className = 'brikpanel-snack';
		root.setAttribute('role', 'status');
		root.setAttribute('aria-live', 'polite');
		// An open sheet leaves this strip usable (its Undo).
		root.setAttribute('data-bp-sheet-keep', '');
		icon = document.createElement('span');
		icon.className = 'brikpanel-snack__icon';
		icon.setAttribute('aria-hidden', 'true');
		text = document.createElement('span');
		text.className = 'brikpanel-snack__text';
		undoBtn = document.createElement('button');
		undoBtn.type = 'button';
		undoBtn.className = 'brikpanel-snack__undo';
		undoBtn.textContent = L.undo || '';
		undoBtn.hidden = true;
		undoBtn.addEventListener('click', function () {
			var fn = undoFn;
			hide();
			if (fn) {
				fn();
			}
		});
		root.appendChild(icon);
		root.appendChild(text);
		root.appendChild(undoBtn);
		document.body.appendChild(root);
	}

	function show(message, opts) {
		opts = opts || {};
		ensure();
		var tone = opts.tone === 'error' ? 'error' : (opts.tone === 'info' ? 'info' : 'success');
		root.setAttribute('aria-live', tone === 'error' ? 'assertive' : 'polite');
		root.className = 'brikpanel-snack is-' + tone;
		icon.innerHTML = ICONS[tone]; // Static markup.
		text.textContent = message || '';
		undoFn = typeof opts.undo === 'function' ? opts.undo : null;
		undoBtn.hidden = !undoFn;
		// Restart the slide when a second message follows the first.
		void root.offsetWidth;
		root.classList.add('is-shown');
		window.clearTimeout(timer);
		var ms = opts.ms || (undoFn ? 5000 : (tone === 'error' ? 6000 : 3000));
		timer = window.setTimeout(hide, ms);
	}

	function hide() {
		window.clearTimeout(timer);
		undoFn = null;
		if (root) {
			root.classList.remove('is-shown');
		}
	}

	window.brikpanelSnack = { show: show, hide: hide };
})(window, document);
