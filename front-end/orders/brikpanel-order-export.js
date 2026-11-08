/**
 * BrikPanel - the orders list's Export window (Excel / CSV).
 *
 * The title row asks for the button (brikpanelPageHeader() in
 * brikpanel-orders.js calls window.brikpanelOrderExport.button() before the
 * page is shown, so the row never jumps). The window picks the orders (the
 * ticked ones, or the whole list as it is filtered now) and the file type,
 * then:
 *   1. posts to the list's own address, which reads the list with all its
 *      filters and starts a job (front-end/orders/brikpanel-order-export.php);
 *   2. asks admin-ajax for steps until the file is ready, showing the count;
 *   3. downloads it through admin-post with a hidden download link, so the
 *      page is never left.
 * Every sentence comes from brikpanelOrderExportConfig.i18n (PHP); numbers are
 * written by window.brikpanelFormat.
 */
(function (window, document) {
	'use strict';

	var C = window.brikpanelOrderExportConfig;
	if (!C || window.brikpanelOrderExport) {
		return;
	}
	var t = C.i18n || {};
	var F = window.brikpanelFormat || null;
	var FORMAT_KEY = 'brikpanelOrderExportFormat';
	var RETRIES = 2;

	var ICON = '<svg class="bp-oexp-open__icon" width="16" height="16" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M10 3.5v9"/><path d="m6.25 9 3.75 3.75L13.75 9"/><path d="M4 15.5h12"/></svg>';
	var CLOSE = '<svg width="16" height="16" viewBox="0 0 20 20" aria-hidden="true" focusable="false"><path d="M5 5l10 10M15 5L5 15" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round"/></svg>';

	function number(n) {
		return F ? F.number(n) : String(n);
	}

	function count(msg, n) {
		return F ? F.count(msg, n) : String(n);
	}

	// "412 of 1,234 orders": the plural follows the total.
	function progressText(done, total) {
		if (!F) {
			return String(done) + ' / ' + String(total);
		}
		return F.format(F.plural(t.progress, total), [F.number(done), F.number(total)]);
	}

	function make(tag, attrs, text) {
		var el = document.createElement(tag);
		Object.keys(attrs || {}).forEach(function (name) {
			var value = attrs[name];
			if (value === true) {
				el.setAttribute(name, '');
			} else if (value !== false && value != null) {
				el.setAttribute(name, String(value));
			}
		});
		if (text != null) {
			el.textContent = text;
		}
		return el;
	}

	function readFormat() {
		try {
			var saved = window.localStorage.getItem(FORMAT_KEY);
			return saved === 'csv' || saved === 'xlsx' ? saved : '';
		} catch (e) {
			return '';
		}
	}

	function saveFormat(format) {
		try {
			window.localStorage.setItem(FORMAT_KEY, format);
		} catch (e) {
			// A private window: the choice is only not remembered.
		}
	}

	// Ticked rows, in the list's order (HPOS: id[], posts storage: post[]).
	function selectedIds() {
		var boxes = document.querySelectorAll('#the-list .check-column input[name="id[]"]:checked, #the-list .check-column input[name="post[]"]:checked');
		var ids = [];
		Array.prototype.forEach.call(boxes, function (box) {
			var id = parseInt(box.value, 10);
			if (id > 0 && ids.indexOf(id) === -1) {
				ids.push(id);
			}
		});
		return ids;
	}

	// The list's own address, without what would make WordPress or WooCommerce
	// treat the start request as a bulk action. The other parts are kept byte
	// for byte: WooCommerce redirects a list address whose query is not written
	// the way it writes it (a raw "@", a "*" left unencoded), and a redirected
	// POST comes back as the list page instead of the job.
	var DROP = ['action', 'action2', '_wpnonce', '_wp_http_referer', 'paged', 'filter_action'];
	function listUrl() {
		var href = window.location.href.split('#')[0];
		var q = href.indexOf('?');
		if (q === -1) {
			return href;
		}
		var parts = href.slice(q + 1).split('&').filter(function (part) {
			var key = part.split('=')[0];
			try {
				key = decodeURIComponent(key.replace(/\+/g, ' '));
			} catch (e) {
				// A malformed escape: keep the part as it is.
			}
			return part !== '' && DROP.indexOf(key) === -1;
		});
		return href.slice(0, q) + (parts.length ? '?' + parts.join('&') : '');
	}

	var $dialog = null;
	var ui = {};
	var busy = false;
	var job = '';
	var run = 0;
	var opener = null;

	function onBeforeUnload(event) {
		event.preventDefault();
		event.returnValue = '';
	}

	function setBusy(on) {
		busy = on;
		ui.close.hidden = on;
		ui.submit.hidden = on;
		ui.form.setAttribute('aria-busy', on ? 'true' : 'false');
		if (on) {
			window.addEventListener('beforeunload', onBeforeUnload);
		} else {
			window.removeEventListener('beforeunload', onBeforeUnload);
		}
	}

	function showError(message) {
		ui.error.textContent = message || '';
		ui.error.hidden = !message;
	}

	function showChoices() {
		ui.choose.hidden = false;
		ui.progress.hidden = true;
	}

	function showProgress(done, total, status) {
		ui.choose.hidden = true;
		ui.progress.hidden = false;
		var known = total > 0;
		ui.bar.classList.toggle('is-waiting', !known);
		var pct = known ? Math.round((done / total) * 100) : 0;
		ui.fill.style.width = known ? pct + '%' : '';
		if (known) {
			ui.bar.setAttribute('aria-valuenow', String(pct));
			ui.bar.setAttribute('aria-valuetext', progressText(done, total));
		} else {
			ui.bar.removeAttribute('aria-valuenow');
			ui.bar.removeAttribute('aria-valuetext');
		}
		ui.count.textContent = known ? progressText(done, total) : '';
		ui.count.hidden = !known;
		ui.status.textContent = status || '';
		ui.status.hidden = !status;
	}

	function lockScroll(on) {
		var html = document.documentElement;
		if (on) {
			html.classList.add('brikpanel-modal-lock');
		} else if (!document.querySelector('.wc-backbone-modal')) {
			// The order preview (WooCommerce's modal) may hold the same lock.
			html.classList.remove('brikpanel-modal-lock');
		}
	}

	function build() {
		$dialog = make('dialog', { class: 'bp-oexp', 'aria-labelledby': 'bp-oexp-title' });
		ui.form = make('form', { class: 'bp-oexp__form', novalidate: true });

		var head = make('div', { class: 'bp-oexp__head' });
		head.appendChild(make('h2', { class: 'bp-oexp__title', id: 'bp-oexp-title' }, t.title || ''));
		ui.close = make('button', { type: 'button', class: 'bp-oexp__close', 'aria-label': t.close || '', title: t.close || '' });
		ui.close.innerHTML = CLOSE;
		head.appendChild(ui.close);

		var body = make('div', { class: 'bp-oexp__body' });

		// The two questions.
		ui.choose = make('div', { class: 'bp-oexp__choose' });

		var scope = make('fieldset', { class: 'bp-oexp__group' });
		scope.appendChild(make('legend', { class: 'bp-oexp__legend' }, t.orders || ''));
		ui.selected = option('scope', 'selected');
		ui.list = option('scope', 'list');
		scope.appendChild(ui.selected.label);
		scope.appendChild(ui.list.label);

		var type = make('fieldset', { class: 'bp-oexp__group' });
		type.appendChild(make('legend', { class: 'bp-oexp__legend' }, t.file_type || ''));
		ui.xlsx = option('format', 'xlsx', t.xlsx, t.xlsx_hint);
		ui.csv = option('format', 'csv', t.csv, t.csv_hint);
		type.appendChild(ui.xlsx.label);
		type.appendChild(ui.csv.label);
		ui.noZip = make('p', { class: 'bp-oexp__note', hidden: true }, t.no_zip || '');
		type.appendChild(ui.noZip);

		ui.choose.appendChild(scope);
		ui.choose.appendChild(type);

		// The progress, in the same place.
		ui.progress = make('div', { class: 'bp-oexp__progress', hidden: true });
		ui.bar = make('div', { class: 'bp-oexp__bar', role: 'progressbar', 'aria-valuemin': '0', 'aria-valuemax': '100', 'aria-labelledby': 'bp-oexp-title' });
		ui.fill = make('span', { class: 'bp-oexp__fill' });
		ui.bar.appendChild(ui.fill);
		ui.count = make('p', { class: 'bp-oexp__count', 'aria-live': 'polite' });
		ui.status = make('p', { class: 'bp-oexp__status' });
		ui.progress.appendChild(ui.bar);
		ui.progress.appendChild(ui.count);
		ui.progress.appendChild(ui.status);

		ui.error = make('p', { class: 'bp-oexp__error', role: 'alert', hidden: true });

		body.appendChild(ui.choose);
		body.appendChild(ui.progress);
		body.appendChild(ui.error);

		var foot = make('div', { class: 'bp-oexp__foot' });
		ui.cancel = make('button', { type: 'button', class: 'brikpanel-btn brikpanel-btn--secondary bp-oexp__btn' }, t.cancel || '');
		ui.submit = make('button', { type: 'submit', class: 'brikpanel-btn brikpanel-btn--primary bp-oexp__btn' }, t.export || '');
		foot.appendChild(ui.cancel);
		foot.appendChild(ui.submit);

		ui.form.appendChild(head);
		ui.form.appendChild(body);
		ui.form.appendChild(foot);
		$dialog.appendChild(ui.form);
		// Outside the list: its row clicks and phone card taps stay out.
		document.body.appendChild($dialog);

		// Keys typed in the window are the window's: the orders page's own
		// shortcuts ("F" for search) listen on the document. Tab goes round
		// inside the window.
		$dialog.addEventListener('keydown', function (event) {
			event.stopPropagation();
			if (event.key !== 'Tab') {
				return;
			}
			var stops = Array.prototype.filter.call($dialog.querySelectorAll('button, input'), function (el) {
				return !el.disabled && el.getClientRects().length && (el.type !== 'radio' || el.checked);
			});
			if (!stops.length) {
				return;
			}
			var first = stops[0];
			var last = stops[stops.length - 1];
			if (event.shiftKey && document.activeElement === first) {
				event.preventDefault();
				last.focus();
			} else if (!event.shiftKey && document.activeElement === last) {
				event.preventDefault();
				first.focus();
			}
		});
		// Escape closes the window, except while a file is being written:
		// Cancel says what happens to it.
		$dialog.addEventListener('cancel', function (event) {
			if (busy) {
				event.preventDefault();
			}
		});
		$dialog.addEventListener('close', function () {
			lockScroll(false);
			restoreFocus();
		});
		// A click on the dimmed page around the window closes it.
		$dialog.addEventListener('click', function (event) {
			if (event.target === $dialog && !busy) {
				$dialog.close();
			}
		});
		ui.close.addEventListener('click', function () {
			if (!busy) {
				$dialog.close();
			}
		});
		ui.cancel.addEventListener('click', function () {
			if (busy) {
				stop();
			} else {
				$dialog.close();
			}
		});
		ui.form.addEventListener('submit', function (event) {
			event.preventDefault();
			if (!busy) {
				start();
			}
		});
	}

	// One labelled radio with an optional line under it.
	function option(group, value, title, hint) {
		var label = make('label', { class: 'bp-oexp__option' });
		var input = make('input', { type: 'radio', name: 'bp-oexp-' + group, value: value });
		var text = make('span', { class: 'bp-oexp__option-text' });
		var name = make('span', { class: 'bp-oexp__option-title' }, title || '');
		text.appendChild(name);
		if (hint) {
			text.appendChild(make('span', { class: 'bp-oexp__hint' }, hint));
		}
		label.appendChild(input);
		label.appendChild(text);
		return { label: label, input: input, name: name };
	}

	function restoreFocus() {
		var target = opener;
		opener = null;
		if (!target || !target.isConnected) {
			return;
		}
		// Folded into "...": the button is hidden with the closed menu.
		var visible = target.getClientRects().length && window.getComputedStyle(target).visibility !== 'hidden';
		if (!visible) {
			var wrap = target.closest('.brikpanel-overflow');
			target = wrap ? wrap.querySelector('.brikpanel-overflow__trigger') : null;
		}
		if (target) {
			target.focus();
		}
	}

	function open(button) {
		if (!$dialog) {
			build();
		}
		if (typeof $dialog.showModal !== 'function') {
			return;
		}
		opener = button || null;
		if (!busy) {
			var ids = selectedIds();
			var total = parseInt(C.total, 10) || 0;
			ui.selected.label.hidden = ids.length === 0;
			ui.selected.name.textContent = count(t.selected, ids.length);
			ui.list.name.textContent = count(C.filtered ? t.in_list : t.all, total);
			ui.list.label.hidden = total === 0;
			(ids.length ? ui.selected.input : ui.list.input).checked = true;

			var canXlsx = !!C.can_xlsx;
			ui.xlsx.input.disabled = !canXlsx;
			ui.xlsx.label.classList.toggle('is-disabled', !canXlsx);
			ui.noZip.hidden = canXlsx;
			var format = canXlsx ? (readFormat() || 'xlsx') : 'csv';
			(format === 'csv' ? ui.csv.input : ui.xlsx.input).checked = true;

			showError('');
			showChoices();
			setBusy(false);
		}
		lockScroll(true);
		$dialog.showModal();
		var focus = busy ? ui.cancel : ui.choose.querySelector('input:checked');
		if (focus) {
			focus.focus();
		}
	}

	function post(url, fields) {
		var body = new window.FormData();
		Object.keys(fields).forEach(function (key) {
			body.append(key, fields[key]);
		});
		return window.fetch(url, { method: 'POST', body: body, credentials: 'same-origin' }).then(function (response) {
			return response.text().then(function (text) {
				var json = null;
				try {
					json = JSON.parse(text);
				} catch (e) {
					json = null;
				}
				if (!json || typeof json !== 'object') {
					var error = new Error('response');
					error.retry = true;
					throw error;
				}
				if (!json.success) {
					var failed = new Error('failed');
					failed.code = json.data && json.data.code ? String(json.data.code) : 'failed';
					throw failed;
				}
				return json.data || {};
			});
		}, function () {
			var offline = new Error('network');
			offline.retry = true;
			throw offline;
		});
	}

	// A step that failed on the way (a timeout, a dropped connection) is asked
	// again: the server cuts its files back to the last saved length first.
	function postWithRetry(url, fields, tries) {
		return post(url, fields).catch(function (error) {
			if (!error.retry || tries >= RETRIES) {
				throw error;
			}
			return new Promise(function (resolve) {
				window.setTimeout(resolve, 1500);
			}).then(function () {
				return postWithRetry(url, fields, tries + 1);
			});
		});
	}

	function messageFor(error) {
		var code = error && error.code ? error.code : '';
		if (code === 'expired') {
			return t.err_expired || '';
		}
		if (code === 'empty') {
			return t.err_empty || '';
		}
		if (code === 'disk') {
			return t.err_disk || '';
		}
		if (code === 'list') {
			return t.err_list || '';
		}
		return t.err_failed || '';
	}

	function start() {
		var scope = ui.selected.input.checked && !ui.selected.label.hidden ? 'selected' : 'list';
		var format = ui.csv.input.checked || !C.can_xlsx ? 'csv' : 'xlsx';
		saveFormat(format);
		var fields = {
			brikpanel_order_export: 'prepare',
			brikpanel_oexp_nonce: C.nonce || '',
			scope: scope,
			format: format,
		};
		if (scope === 'selected') {
			fields.ids = selectedIds().join(',');
		}

		var mine = ++run;
		job = '';
		showError('');
		setBusy(true);
		showProgress(0, 0, t.preparing || '');

		post(listUrl(), fields).then(function (data) {
			if (mine !== run) {
				return null;
			}
			job = String(data.job || '');
			var total = parseInt(data.total, 10) || 0;
			showProgress(0, total, '');
			return step(mine, total);
		}).catch(function (error) {
			if (mine !== run) {
				return;
			}
			fail(error);
		});
	}

	function step(mine, total, last, stalls) {
		return postWithRetry(C.ajax_url, {
			action: 'brikpanel_order_export_step',
			_wpnonce: C.nonce || '',
			job: job,
		}, 0).then(function (data) {
			if (mine !== run) {
				return null;
			}
			var done = parseInt(data.done, 10) || 0;
			total = parseInt(data.total, 10) || total;
			if (data.ready) {
				finish(total, !!data.capped);
				return null;
			}
			// Every step writes orders or builds the file; three answers in a
			// row that do neither mean the server is stuck, not slow.
			stalls = done > (last || 0) ? 0 : (stalls || 0) + 1;
			if (stalls >= 3) {
				throw new Error('stalled');
			}
			showProgress(done, total, done >= total ? (t.building || '') : '');
			return step(mine, total, done, stalls);
		});
	}

	function finish(total, capped) {
		showProgress(total, total, '');
		var link = document.createElement('a');
		link.href = C.download_url + '&job=' + encodeURIComponent(job) + '&_wpnonce=' + encodeURIComponent(C.nonce || '');
		link.setAttribute('download', '');
		link.hidden = true;
		document.body.appendChild(link);
		setBusy(false);
		link.click();
		link.remove();
		job = '';
		$dialog.close();
		toast(capped ? (t.capped || '') : count(t.done, total), capped);
	}

	function stop() {
		run++;
		var cancelled = job;
		job = '';
		setBusy(false);
		showChoices();
		$dialog.close();
		if (cancelled) {
			post(C.ajax_url, {
				action: 'brikpanel_order_export_cancel',
				_wpnonce: C.nonce || '',
				job: cancelled,
			}).catch(function () {
				// Left over files are swept within a few hours anyway.
			});
		}
	}

	function fail(error) {
		var abandoned = job;
		job = '';
		setBusy(false);
		showChoices();
		showError(messageFor(error));
		if (abandoned) {
			post(C.ajax_url, {
				action: 'brikpanel_order_export_cancel',
				_wpnonce: C.nonce || '',
				job: abandoned,
			}).catch(function () {});
		}
		if ($dialog.open) {
			ui.submit.focus();
		}
	}

	var $toast = null;
	var toastTimer = 0;
	function toast(message, warn) {
		if (!message) {
			return;
		}
		if (!$toast) {
			$toast = make('div', { class: 'bp-oexp-toast', role: 'status', 'aria-live': 'polite' });
			document.body.appendChild($toast);
		}
		// Just under whichever bar is at the top of the window.
		var bar = document.getElementById('brikpanel-topbar') || document.getElementById('wpadminbar');
		var top = bar ? Math.max(0, bar.getBoundingClientRect().bottom) : 0;
		$toast.style.setProperty('--bp-oexp-top', Math.round(top + 12) + 'px');
		$toast.classList.toggle('is-warn', !!warn);
		$toast.textContent = message;
		$toast.classList.add('is-in');
		window.clearTimeout(toastTimer);
		toastTimer = window.setTimeout(function () {
			$toast.classList.remove('is-in');
			// Emptied once it has slid away, so a screen reader finds no stale text.
			toastTimer = window.setTimeout(function () {
				$toast.textContent = '';
			}, 400);
		}, warn ? 9000 : 3500);
	}

	// The title row's button; null when there is nothing to export.
	function button() {
		if (!(parseInt(C.total, 10) > 0)) {
			return null;
		}
		var el = make('button', { type: 'button', class: 'bp-oexp-open' });
		el.innerHTML = ICON;
		el.appendChild(make('span', { class: 'bp-oexp-open__label' }, t.export || ''));
		el.addEventListener('click', function () {
			open(el);
		});
		return el;
	}

	window.brikpanelOrderExport = {
		button: button,
		open: open,
	};
})(window, document);
