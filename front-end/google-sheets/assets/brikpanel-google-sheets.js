/* global BrikpanelGS */
(function () {
	'use strict';

	if (typeof BrikpanelGS === 'undefined') {
		return;
	}

	var root = document.getElementById('bp-gs');
	if (!root) {
		return;
	}

	var i18n = BrikpanelGS.i18n || {};

	// ---------------------------------------------------------------------
	// Tabs
	// ---------------------------------------------------------------------
	var tabs = root.querySelectorAll('.bp-gs-tab');
	var panels = root.querySelectorAll('.bp-gs-tabpanel');
	tabs.forEach(function (btn) {
		btn.addEventListener('click', function () {
			var target = btn.getAttribute('data-tab');
			tabs.forEach(function (b) {
				b.classList.toggle('is-active', b === btn);
				b.setAttribute('aria-selected', b === btn ? 'true' : 'false');
			});
			panels.forEach(function (p) {
				p.classList.toggle('is-active', p.getAttribute('data-panel') === target);
			});
		});
	});

	// ---------------------------------------------------------------------
	// Toast
	// ---------------------------------------------------------------------
	var toastEl = document.getElementById('bp-gs-toast');
	var toastTimer = null;
	// The 350 ms hide that follows the slide-out. It has to be cancellable:
	// `dismissToast(); toast(...)` used to show the new toast and then hide it
	// a third of a second later, so an error after a sticky "Syncing…" flashed
	// by unread.
	var toastHideTimer = null;
	function hideToast() {
		if (!toastEl) return;
		clearTimeout(toastTimer);
		clearTimeout(toastHideTimer);
		toastEl.classList.remove('is-visible');
		toastHideTimer = setTimeout(function () { toastEl.hidden = true; }, 350);
	}
	function toast(tone, message, opts) {
		if (!toastEl) {
			return;
		}
		opts = opts || {};
		clearTimeout(toastTimer);
		clearTimeout(toastHideTimer);
		toastEl.textContent = message;
		toastEl.setAttribute('data-tone', tone || 'info');
		toastEl.hidden = false;
		void toastEl.offsetWidth;
		toastEl.classList.add('is-visible');
		if (!opts.sticky) {
			toastTimer = setTimeout(hideToast, opts.duration || 3500);
		}
	}
	function dismissToast() {
		hideToast();
	}

	// Surface OAuth-return flash on load.
	var flashTone = root.getAttribute('data-flash-tone');
	var flashMsg = root.getAttribute('data-flash-message');
	if (flashTone) {
		// An error flash carries no text of its own any more (the server fills
		// the box from a one-time record), so a reload may arrive with none.
		if (flashMsg) {
			setTimeout(function () { toast(flashTone, flashMsg); }, 100);
		}
		// Clean URL so reload doesn't re-fire the toast.
		if (window.history && window.history.replaceState) {
			var url = new URL(window.location.href);
			url.searchParams.delete('brikpanel_oauth_flash');
			url.searchParams.delete('brikpanel_msg');
			url.searchParams.delete('brikpanel_oauth_return');
			url.searchParams.delete('state');
			window.history.replaceState({}, '', url.toString());
		}
	}

	// ---------------------------------------------------------------------
	// AJAX helper
	//
	// Every failure is an Error with `kind`:
	//   session  the answer was "-1" (security token expired) or "0" (logged
	//            out, or no handler)
	//   cutoff   the answer was not BrikPanel's: a PHP fatal, a host time-out
	//            or a front server's own error page
	//   fetch    no answer at all
	//   server   BrikPanel answered success:false; `data` is its payload
	// A cutoff or fetch failure is told in the caller's words (opts.fallback):
	// the sync buttons say "click Sync now again", which made no sense under
	// any other button.
	// ---------------------------------------------------------------------
	function ajaxError(kind, message, data) {
		var err = new Error(message);
		err.kind = kind;
		err.data = data || {};
		err.payload = err.data;
		return err;
	}

	function parseAnswer(text) {
		try { return JSON.parse(text); } catch (e) { /* read below */ }
		// PHP notices printed ahead of the answer: read from where it starts.
		var at = text.lastIndexOf('{"success"');
		if (at > 0) {
			try { return JSON.parse(text.slice(at)); } catch (e2) { /* not ours */ }
		}
		return null;
	}

	function ajax(action, payload, opts) {
		opts = opts || {};
		var fallback = opts.fallback || i18n.server_error_generic;
		var body = new URLSearchParams();
		body.append('action', action);
		body.append('_ajax_nonce', BrikpanelGS.nonce);
		if (payload) {
			Object.keys(payload).forEach(function (k) {
				var v = payload[k];
				if (Array.isArray(v)) {
					v.forEach(function (item) { body.append(k + '[]', item); });
				} else if (v !== undefined && v !== null) {
					body.append(k, v);
				}
			});
		}
		return fetch(BrikpanelGS.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: body
		}).then(function (r) {
			return r.text().then(function (text) {
				var raw = String(text || '').trim();
				if (raw === '-1' || raw === '0') {
					throw ajaxError('session', i18n.session_expired, { http: r.status });
				}
				var j = parseAnswer(raw);
				if (!j || typeof j !== 'object') {
					throw ajaxError('cutoff', fallback, { http: r.status, raw: raw.slice(0, 500) });
				}
				if (j.success !== true) {
					var d = j.data;
					var msg = (d && typeof d === 'object' && d.message) ? d.message
						: ((typeof d === 'string' && d) ? d : i18n.generic_error);
					throw ajaxError('server', msg, (d && typeof d === 'object') ? d : {});
				}
				return j.data || {};
			});
		}, function () {
			throw ajaxError('fetch', fallback, {});
		});
	}

	// ---------------------------------------------------------------------
	// Connect / Disconnect / Re-auth
	//
	// The server asks brksoft.com for Google's sign-in address. When that
	// request cannot get through, the browser asks once more (the server then
	// uses IPv4 only) and says "Still trying…" meanwhile. If that fails too,
	// the box beside the button says what went wrong, why and what to do, and
	// stays until the next click. It used to be a 3.5 s toast, "Could not
	// reach the BrikPanel proxy. Please try again in a moment.", which sent a
	// merchant whose host blocks outgoing connections round in circles.
	// ---------------------------------------------------------------------
	var connectBusy = false;

	function connectParts(btn) {
		var card = btn ? btn.closest('.bp-gs-card') : null;
		return {
			btn: btn,
			box: card ? card.querySelector('[data-role="connect-error"]') : null,
			status: card ? card.querySelector('[data-role="connect-status"]') : null
		};
	}

	function setConnectBusy(ui, on, statusText) {
		if (ui.btn) {
			ui.btn.disabled = !!on;
			if (on) { ui.btn.setAttribute('aria-busy', 'true'); } else { ui.btn.removeAttribute('aria-busy'); }
		}
		if (ui.status) { ui.status.textContent = on ? (statusText || '') : ''; }
	}

	function setBoxPart(box, role, text) {
		var el = box.querySelector('[data-role="' + role + '"]');
		if (!el) { return; }
		el.textContent = text || '';
		el.hidden = !text;
	}

	function hideConnectError(ui) {
		if (ui.box) { ui.box.hidden = true; }
	}

	function showConnectError(ui, info) {
		if (!ui.box) {
			toast('error', info.title || info.reason || i18n.generic_error, { duration: 7000 });
			return;
		}
		// Shown first, filled after: role="alert" then reads the new text out.
		ui.box.hidden = false;
		setBoxPart(ui.box, 'connect-error-title', info.title);
		setBoxPart(ui.box, 'connect-error-reason', info.reason);
		setBoxPart(ui.box, 'connect-error-help', info.help);
		var row = ui.box.querySelector('[data-role="connect-error-detail-row"]');
		var code = ui.box.querySelector('[data-role="connect-error-detail"]');
		if (code) { code.textContent = info.detail || ''; }
		if (row) { row.hidden = !info.detail; }
	}

	function connectErrorInfo(e) {
		if (e && e.kind === 'session') {
			return { title: '', reason: e.message, help: '', detail: '' };
		}
		if (e && (e.kind === 'cutoff' || e.kind === 'fetch')) {
			var http = e.data && e.data.http ? e.data.http : 0;
			return {
				title: i18n.connect_failed_title,
				reason: i18n.connect_cut_off,
				help: i18n.connect_help,
				detail: http ? 'HTTP ' + http : '' // i18n-ignore: protocol name and status number, not prose
			};
		}
		var d = (e && e.data) || {};
		return {
			title: d.message || (e && e.message) || i18n.generic_error,
			reason: d.reason || '',
			help: d.help || '',
			detail: d.detail || ''
		};
	}

	function startConnect(ev) {
		if (connectBusy) { return; }
		var ui = connectParts(ev && ev.currentTarget);
		connectBusy = true;
		hideConnectError(ui);
		setConnectBusy(ui, true, i18n.connecting);

		function attempt(n) {
			return ajax('brikpanel_gs_oauth_start', { attempt: n }, { fallback: i18n.connect_cut_off })
				.then(function (data) {
					if (data && data.authorize_url) {
						// Stay busy while the browser leaves for Google.
						window.location.href = data.authorize_url;
						return;
					}
					throw ajaxError('server', i18n.generic_error, {});
				})
				.catch(function (e) {
					var d = (e && e.data) || {};
					// Only a request that never got an answer is worth repeating.
					// A refusal, a 429 or an expired session would only repeat.
					var again = n < 2 && (d.retry === true || (e && (e.kind === 'cutoff' || e.kind === 'fetch')));
					if (again) {
						setConnectBusy(ui, true, i18n.still_trying);
						return new Promise(function (resolve) { setTimeout(resolve, 1000); })
							.then(function () { return attempt(n + 1); });
					}
					connectBusy = false;
					setConnectBusy(ui, false);
					showConnectError(ui, connectErrorInfo(e));
					if (ui.btn && document.activeElement === document.body) { ui.btn.focus(); }
				});
		}
		attempt(1);
	}
	var connectBtn = document.getElementById('bp-gs-connect');
	if (connectBtn) { connectBtn.addEventListener('click', startConnect); }
	var reauthBtn = document.getElementById('bp-gs-reauth');
	if (reauthBtn) { reauthBtn.addEventListener('click', startConnect); }
	// Coming back with the Back button restores the page as it was left:
	// a button still locked for the trip to Google.
	window.addEventListener('pageshow', function (ev) {
		if (!ev.persisted) { return; }
		connectBusy = false;
		[connectBtn, reauthBtn].forEach(function (btn) {
			if (btn) { setConnectBusy(connectParts(btn), false); }
		});
	});
	var disconnectBtn = document.getElementById('bp-gs-disconnect');
	if (disconnectBtn) {
		disconnectBtn.addEventListener('click', function () {
			if (!window.confirm(i18n.disconnect_confirm)) { return; }
			ajax('brikpanel_gs_oauth_disconnect').then(function () {
				window.location.reload();
			}).catch(function (e) { toast('error', e.message); });
		});
	}

	// ---------------------------------------------------------------------
	// Spreadsheet picker (Google Picker, drive.file scope)
	//
	// Under the non-sensitive drive.file scope the only way to let BrikPanel
	// reach an *existing* sheet is for the user to hand it over through the
	// Google Picker — which grants per-file access to just that file. We then
	// store the picked ID exactly like a freshly created sheet.
	// ---------------------------------------------------------------------
	var gapiLoadingPromise = null;

	function loadGapiPicker() {
		if (window.google && window.google.picker) {
			return Promise.resolve();
		}
		if (gapiLoadingPromise) {
			return gapiLoadingPromise;
		}
		gapiLoadingPromise = new Promise(function (resolve, reject) {
			var s = document.createElement('script');
			s.src = 'https://apis.google.com/js/api.js';
			s.async = true;
			s.defer = true;
			s.onload = function () {
				if (!window.gapi) { reject(new Error(i18n.picker_failed)); return; }
				window.gapi.load('picker', {
					callback: function () { resolve(); },
					onerror: function () { reject(new Error(i18n.picker_failed)); }
				});
			};
			s.onerror = function () { reject(new Error(i18n.picker_failed)); };
			document.head.appendChild(s);
		});
		return gapiLoadingPromise;
	}

	// The live Picker instance. Google's embedded Picker does NOT reliably
	// auto-close on selection, and it does not block a second open — so without
	// an explicit reference + dispose() the overlays stack and every click
	// "does nothing" because an old, dead iframe sits on top.
	var pickerInstance = null;

	function disposePicker() {
		if (pickerInstance) {
			try { pickerInstance.setVisible(false); } catch (e) {}
			try { pickerInstance.dispose(); } catch (e) {}
			pickerInstance = null;
		}
	}

	function adoptSpreadsheet(id) {
		// Sticky so it stays visible even though the heavy validate round-trip
		// runs right after the Picker tears its iframe down.
		toast('info', i18n.validating, { sticky: true });
		ajax('brikpanel_gs_validate_spreadsheet', { input: id })
			.then(function (data) {
				dismissToast();
				toast('success', i18n.validated + ': ' + (data.spreadsheet_title || data.spreadsheet_id));
				setTimeout(function () { window.location.reload(); }, 800);
			})
			.catch(function (e) {
				dismissToast();
				toast('error', e.message || i18n.generic_error, { duration: 7000 });
			});
	}

	function openPicker(boot) {
		var google = window.google;

		// Never stack: kill any previous Picker before building a new one.
		disposePicker();

		var view = new google.picker.DocsView(google.picker.ViewId.SPREADSHEETS)
			.setIncludeFolders(false)
			.setSelectFolderEnabled(false)
			.setMode(google.picker.DocsViewMode.LIST);

		var builder = new google.picker.PickerBuilder()
			.setOAuthToken(boot.token)
			.setDeveloperKey(boot.api_key)
			.addView(view)
			.setTitle('BrikPanel')
			// REQUIRED in WP admin: without an explicit origin the Picker UI
			// loads but its selection postMessage never reaches us (the parent
			// origin can't be validated), so "Select" appears to do nothing.
			.setOrigin(window.location.protocol + '//' + window.location.host)
			.setCallback(function (data) {
				var act = data[google.picker.Response.ACTION];
				if (act === google.picker.Action.PICKED) {
					var docs = data[google.picker.Response.DOCUMENTS] || [];
					var doc = docs[0];
					var id = doc && doc[google.picker.Document.ID];
					// Tear the Picker down FIRST so the user gets immediate
					// visible feedback instead of a frozen-looking overlay.
					disposePicker();
					if (id) {
						adoptSpreadsheet(id);
					}
				} else if (act === google.picker.Action.CANCEL) {
					disposePicker();
					dismissToast();
				}
			});

		// setAppId is required for drive.file so the picked file is granted to
		// our Cloud project; guard in case an older Picker build lacks it.
		if (boot.app_id && typeof builder.setAppId === 'function') {
			builder = builder.setAppId(boot.app_id);
		}
		pickerInstance = builder.build();
		pickerInstance.setVisible(true);
	}

	var pickBtn = document.getElementById('bp-gs-pick');
	if (pickBtn) {
		pickBtn.addEventListener('click', function () {
			if (BrikpanelGS.pickerAvailable === false) {
				toast('error', i18n.picker_unavailable);
				return;
			}
			pickBtn.disabled = true;
			toast('info', i18n.picker_loading, { sticky: true });
			ajax('brikpanel_gs_picker_bootstrap')
				.then(function (boot) {
					return loadGapiPicker().then(function () {
						dismissToast();
						openPicker(boot);
					});
				})
				.catch(function (e) {
					dismissToast();
					toast('error', e.message || i18n.picker_failed);
				})
				.then(function () { pickBtn.disabled = false; });
		});
	}
	var createBtn = document.getElementById('bp-gs-create');
	if (createBtn) {
		createBtn.addEventListener('click', function () {
			var input = document.getElementById('bp-gs-sheet-create-title');
			var title = input ? input.value.trim() : '';
			createBtn.disabled = true;
			toast('info', i18n.creating);
			ajax('brikpanel_gs_create_spreadsheet', { title: title })
				.then(function (data) {
					toast('success', i18n.created + ': ' + (data.spreadsheet_title || ''));
					setTimeout(function () { window.location.reload(); }, 700);
				})
				.catch(function (e) { toast('error', e.message); })
				.then(function () { createBtn.disabled = false; });
		});
	}

	// ---------------------------------------------------------------------
	// Save / Sync now per flow
	// ---------------------------------------------------------------------
	root.querySelectorAll('.bp-gs-form').forEach(function (form) {
		var flow = form.getAttribute('data-flow');
		form.querySelectorAll('[data-action]').forEach(function (btn) {
			btn.addEventListener('click', function () {
				var action = btn.getAttribute('data-action');
				if (action === 'save') {
					saveForm(form, flow, btn);
				} else if (action === 'sync-now') {
					syncNow(flow, btn);
				} else if (action === 'pull-now') {
					pullNow(flow, btn);
				} else if (action === 'reset-sync') {
					resetSync(btn, form);
				}
			});
		});
	});

	// ---------------------------------------------------------------------
	// Orders export-scope preview
	//
	// Ticking every shipping box is NOT the same as ticking none: the filter
	// only matches orders that recorded a shipping method, so orders without a
	// shipping line vanish from the export. That surprise is invisible until
	// after a sync, so count it live here instead.
	// ---------------------------------------------------------------------
	(function () {
		var ordersForm = root.querySelector('.bp-gs-form[data-flow="orders"]');
		if (!ordersForm) { return; }
		var scopeEl = ordersForm.querySelector('[data-role="orders-scope"]');
		if (!scopeEl) { return; }

		var countEl = document.createElement('span');
		countEl.className = 'bp-gs-scope-count';
		var warnEl = document.createElement('span');
		warnEl.className = 'bp-gs-scope-warn';
		scopeEl.appendChild(countEl);
		scopeEl.appendChild(warnEl);

		function checkedValues(name) {
			var out = [];
			ordersForm.querySelectorAll('input[name="' + name + '"]:checked').forEach(function (el) {
				out.push(el.value);
			});
			return out;
		}

		// Minimal printf for the %s / %1$s placeholders used by the localized
		// strings. Values are numbers we produced, never user input.
		function format(tpl, args) {
			var i = 0;
			return String(tpl || '')
				.replace(/%(\d+)\$s/g, function (m, n) { return args[parseInt(n, 10) - 1]; })
				.replace(/%s/g, function () { return args[i++]; });
		}

		var timer = null;
		var seq = 0;

		function refresh() {
			var methods = checkedValues('shipping_methods[]');
			var statuses = checkedValues('bulk_statuses[]');
			var sinceEl = ordersForm.querySelector('input[name="bulk_since"]');
			var mySeq = ++seq;

			scopeEl.hidden = false;
			scopeEl.removeAttribute('data-tone');
			countEl.textContent = i18n.scope_counting || '';
			warnEl.textContent = '';

			ajax('brikpanel_gs_orders_scope', {
				shipping_methods: methods,
				statuses: statuses,
				since: sinceEl ? sinceEl.value : ''
			}).then(function (data) {
				// Drop stale responses so fast clicking cannot leave a wrong count on screen.
				if (mySeq !== seq || !data) { return; }
				var matched = Number(data.matched) || 0;
				var total = Number(data.total) || 0;

				// The sentence comes finished from the server, in the plural
				// form for these counts and with the store's separators.
				countEl.textContent = data.text || '';
				if (!data.filtered) {
					warnEl.textContent = '';
					return;
				}
				if (matched === 0) {
					warnEl.textContent = i18n.scope_none || '';
					scopeEl.setAttribute('data-tone', 'warn');
				} else if (matched < total) {
					warnEl.textContent = i18n.scope_warning || '';
					scopeEl.setAttribute('data-tone', 'warn');
				}
			}).catch(function () {
				// A failed count must never block the form; just hide the hint.
				if (mySeq !== seq) { return; }
				scopeEl.hidden = true;
			});
		}

		function schedule() {
			clearTimeout(timer);
			timer = setTimeout(refresh, 250);
		}

		ordersForm.addEventListener('change', function (ev) {
			var t = ev.target;
			if (!t || !t.name) { return; }
			if (t.name === 'shipping_methods[]' || t.name === 'bulk_statuses[]' || t.name === 'bulk_since') {
				schedule();
			}
		});

		refresh();
	})();

	function resetSync(btn, form) {
		// Pick the right confirm message based on which flow's reset button was
		// clicked — orders reset wording mentions order history, products reset
		// mentions every product. Both share the same destructive footprint
		// (wipe tab + re-push) but the user-facing copy should not lie.
		var resetFlow = btn.getAttribute('data-reset-flow') || form.getAttribute('data-flow') || 'orders';
		var defaultMsg = i18n.reset_confirm;
		if (resetFlow === 'products' && i18n.reset_products_confirm) {
			defaultMsg = i18n.reset_products_confirm;
		} else if (resetFlow === 'expenses' && i18n.reset_expenses_confirm) {
			defaultMsg = i18n.reset_expenses_confirm;
		}
		if (!window.confirm(defaultMsg)) { return; }
		btn.disabled = true;
		var orig = btn.textContent;
		btn.innerHTML = '<span class="bp-gs-spinner" aria-hidden="true"></span> ' + escapeHtml(i18n.resetting);
		ajax('brikpanel_gs_reset_sync', { flow: resetFlow })
			.then(function (data) {
				toast('success', (data && data.message) || i18n.saved, { duration: 6000 });
				// Auto-trigger Sync now so the user does not have to click again.
				var flow = form.getAttribute('data-flow');
				var syncBtn = form.querySelector('[data-action="sync-now"]');
				if (syncBtn) {
					setTimeout(function () { syncNow(flow, syncBtn); }, 500);
				}
			})
			.catch(function (e) { toast('error', e.message || i18n.generic_error); })
			.then(function () { btn.disabled = false; btn.textContent = orig; });
	}

	function pullNow(flow, btn) {
		btn.disabled = true;
		var originalText = btn.textContent;
		btn.innerHTML = '<span class="bp-gs-spinner" aria-hidden="true"></span> ' + escapeHtml(i18n.pulling);
		toast('info', i18n.pulling, { sticky: true });
		ajax('brikpanel_gs_pull_now', { flow: flow })
			.then(function (data) {
				dismissToast();
				var msg = (data && data.message) ? data.message : i18n.saved;
				if (data && typeof data.duration_seconds === 'number') {
					msg += ' (' + data.duration_seconds + 's)';
				}
				toast('success', msg, { duration: 6000 });
			})
			.catch(function (e) {
				dismissToast();
				toast('error', e.message || i18n.generic_error, { duration: 6000 });
			})
			.then(function () {
				btn.disabled = false;
				btn.textContent = originalText;
			});
	}

	function saveForm(form, flow, btn) {
		var payload = { flow: flow };
		form.querySelectorAll('input[name], select[name]').forEach(function (input) {
			var name = input.name;
			if (name === '') { return; }
			if (input.type === 'checkbox') {
				if (name.endsWith('[]')) {
					if (input.checked) {
						var key = name.slice(0, -2);
						payload[key] = payload[key] || [];
						payload[key].push(input.value);
					}
				} else {
					payload[name] = input.checked ? 'yes' : 'no';
				}
			} else if (input.type === 'radio') {
				if (input.checked) { payload[name] = input.value; }
			} else {
				payload[name] = input.value;
			}
		});
		btn.disabled = true;
		ajax('brikpanel_gs_save_settings', payload)
			.then(function (data) {
				// A save can carry a consequence worth spelling out (e.g. a row
				// layout change wipes the tab) — prefer the server's message.
				toast('success', (data && data.message) || i18n.saved, { duration: 6000 });
				if (data && data.reload) {
					// Column picker + mandatory flags changed server-side; the
					// simplest honest UI is a fresh render.
					setTimeout(function () { window.location.reload(); }, 1200);
				}
			})
			.catch(function (e) { toast('error', e.message); })
			.then(function () { btn.disabled = false; });
	}

	function syncNow(flow, btn) {
		btn.disabled = true;
		var originalText = btn.textContent;
		btn.innerHTML = '<span class="bp-gs-spinner" aria-hidden="true"></span> ' + escapeHtml(i18n.syncing);

		// Sticky "Syncing..." toast — stays visible until the run completes.
		toast('info', i18n.syncing, { sticky: true });

		// A large date range can hold far more orders than one request may
		// safely process, so the server hands back `more` and we keep asking.
		// Each request stays short, and the merchant watches the count climb
		// instead of staring at a spinner that eventually times out.
		var totalOrders = 0;
		var totalRows = 0;
		var MAX_PASSES = 500; // backstop against a server that never clears `more`
		// Flows whose bulk export pages itself across several short requests.
		// Anything else answers in one pass.
		var PAGED = { orders: true, products: true };

		function runPass(pass) {
			// A cut-off pass is resumable, which is what this fallback says.
			return ajax('brikpanel_gs_sync_now', { flow: flow }, { fallback: i18n.server_error }).then(function (data) {
				var result = (data && data.result) ? data.result : {};
				totalOrders += Number(result.orders) || 0;
				totalRows += Number(result.rows) || 0;

				if (PAGED[flow] && result.more && pass < MAX_PASSES) {
					toast('info', formatProgress(totalOrders, totalRows), { sticky: true });
					return runPass(pass + 1);
				}
				return data;
			});
		}

		// "Orders: 12, rows: 30": the counts add up in the browser pass by pass,
		// so the text names them instead of needing a plural form per language.
		function formatProgress(orders, rows, finished) {
			var num = function (n) { return window.brikpanelFormat ? window.brikpanelFormat.number(n || 0) : String(Number(n) || 0); };
			if (flow === 'products') {
				var ptpl = finished ? i18n.sync_done_products : i18n.sync_progress_products;
				if (!ptpl) { return i18n.syncing; }
				return ptpl.replace('%1$s', num(rows));
			}
			var tpl = finished ? i18n.sync_done : i18n.sync_progress;
			if (!tpl) { return i18n.syncing; }
			return tpl.replace('%1$s', num(orders)).replace('%2$s', num(rows));
		}

		runPass(1)
			.then(function (data) {
				dismissToast();
				var msg = (data && data.message) ? data.message : i18n.saved;
				// Across several passes the last response only describes its own
				// pass, so report the run total instead.
				if (flow === 'orders' && totalOrders > 0) {
					msg = formatProgress(totalOrders, totalRows, true);
				} else if (flow === 'products' && totalRows > 0) {
					msg = formatProgress(totalOrders, totalRows, true);
				}
				toast('success', msg, { duration: 5000 });

				// Refresh "Last successful sync" display inline.
				if (data && data.last_sync_display) {
					var card = btn.closest('.bp-gs-tabpanel');
					if (card) {
						var dt = card.querySelector('.bp-gs-dl dt');
						if (dt && dt.nextElementSibling) {
							dt.nextElementSibling.textContent = data.last_sync_display;
						}
					}
				}
			})
			.catch(function (e) {
				dismissToast();
				toast('error', e.message || i18n.generic_error, { duration: 6000 });
			})
			.then(function () {
				btn.disabled = false;
				btn.textContent = originalText;
			});
	}

	// ---------------------------------------------------------------------
	// Column mapper
	// ---------------------------------------------------------------------
	root.querySelectorAll('.bp-gs-column-mapper').forEach(function (mapper) {
		var flow = mapper.getAttribute('data-flow');
		var selectedList = mapper.querySelector('[data-role="selected"]');
		var availableList = mapper.querySelector('[data-role="available"]');

		// Promote/demote rows between lists on checkbox toggle in the "available" list.
		if (availableList) {
			availableList.addEventListener('change', function (ev) {
				var input = ev.target;
				if (!input || input.type !== 'checkbox') { return; }
				var item = input.closest('.bp-gs-column-item');
				if (!item) { return; }
				if (input.checked) {
					// Move to selected list with up/down controls.
					item.classList.add('is-selected');
					if (!item.querySelector('.bp-gs-column-actions')) {
						var actions = document.createElement('span');
						actions.className = 'bp-gs-column-actions';
						actions.innerHTML = '<button type="button" class="bp-gs-icon-btn" data-act="up">↑</button><button type="button" class="bp-gs-icon-btn" data-act="down">↓</button>';
						item.appendChild(actions);
					}
					selectedList.appendChild(item);
				}
			});
		}

		// Demote when unchecked in selected list (skip mandatory).
		if (selectedList) {
			selectedList.addEventListener('change', function (ev) {
				var input = ev.target;
				if (!input || input.type !== 'checkbox' || input.disabled) { return; }
				if (input.checked) { return; }
				var item = input.closest('.bp-gs-column-item');
				if (!item) { return; }
				item.classList.remove('is-selected');
				var actions = item.querySelector('.bp-gs-column-actions');
				if (actions) { actions.remove(); }
				if (availableList) { availableList.appendChild(item); }
			});

			selectedList.addEventListener('click', function (ev) {
				var btn = ev.target.closest('.bp-gs-icon-btn');
				if (!btn) { return; }
				var act = btn.getAttribute('data-act');
				var item = btn.closest('.bp-gs-column-item');
				if (!item) { return; }
				if (act === 'up' && item.previousElementSibling) {
					item.parentNode.insertBefore(item, item.previousElementSibling);
				} else if (act === 'down' && item.nextElementSibling) {
					item.parentNode.insertBefore(item.nextElementSibling, item);
				}
			});
		}

		var saveBtn = mapper.querySelector('[data-action="save-columns"]');
		if (saveBtn) {
			saveBtn.addEventListener('click', function () {
				var columns = [];
				if (selectedList) {
					selectedList.querySelectorAll('.bp-gs-column-item').forEach(function (item) {
						var input = item.querySelector('input[type="checkbox"]');
						if (input && (input.checked || input.disabled)) {
							columns.push(item.getAttribute('data-key'));
						}
					});
				}
				saveBtn.disabled = true;
				ajax('brikpanel_gs_save_columns', { flow: flow, columns: columns })
					.then(function () { toast('success', i18n.saved); })
					.catch(function (e) { toast('error', e.message); })
					.then(function () { saveBtn.disabled = false; });
			});
		}
	});

	// ---------------------------------------------------------------------
	// Log viewer
	// ---------------------------------------------------------------------
	root.querySelectorAll('[data-action="view-log"]').forEach(function (btn) {
		btn.addEventListener('click', function () {
			var flow = btn.getAttribute('data-flow');
			var panel = document.getElementById('bp-gs-log-' + flow);
			if (!panel) { return; }
			if (!panel.hidden) {
				panel.hidden = true;
				return;
			}
			panel.hidden = false;
			panel.innerHTML = '<div class="bp-gs-log-empty">…</div>';
			ajax('brikpanel_gs_view_log', { flow: flow }).then(function (data) {
				var entries = (data && data.entries) || [];
				if (entries.length === 0) {
					panel.innerHTML = '<div class="bp-gs-log-empty">' + i18n.log_empty + '</div>';
					return;
				}
				var rows = entries.map(function (e) {
					return '<tr>'
						+ '<td>' + escapeHtml(e.ts_display || '') + '</td>'
						+ '<td>' + escapeHtml(e.flow || '') + '</td>'
						+ '<td>' + (e.code ? escapeHtml(String(e.code)) : '') + '</td>'
						+ '<td>' + escapeHtml(e.message || '') + '</td>'
						+ '</tr>';
				}).join('');
				panel.innerHTML = '<table><thead><tr>'
					+ '<th>' + escapeHtml(i18n.log_col_time) + '</th>'
					+ '<th>' + escapeHtml(i18n.log_col_flow) + '</th>'
					+ '<th>' + escapeHtml(i18n.log_col_code) + '</th>'
					+ '<th>' + escapeHtml(i18n.log_col_message) + '</th>'
					+ '</tr></thead><tbody>' + rows + '</tbody></table>';
			}).catch(function (e) {
				panel.innerHTML = '<div class="bp-gs-log-empty">' + escapeHtml(e.message) + '</div>';
			});
		});
	});

	function escapeHtml(s) {
		return String(s)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;');
	}

	// ---------------------------------------------------------------------
	// Status pill polling (only when connected)
	// ---------------------------------------------------------------------
	function pollStatus() {
		ajax('brikpanel_gs_status').then(function (s) {
			var pill = document.getElementById('bp-gs-pill');
			if (!pill) { return; }
			var pillText = pill.querySelector('.bp-gs-pill-text');
			if (s.connected) {
				pill.setAttribute('data-state', 'live');
				if (pillText) { pillText.textContent = (s.email || i18n.connected_label); }
			} else {
				// Token revoked externally — reflect it AND force a page reload
				// once so the disconnected UI replaces the target-spreadsheet
				// card. Use a sentinel attribute so we only reload once.
				pill.setAttribute('data-state', 'off');
				if (pillText) { pillText.textContent = i18n.not_connected_label; }
				if (!root.getAttribute('data-disconnect-reloaded')) {
					root.setAttribute('data-disconnect-reloaded', '1');
					setTimeout(function () { window.location.reload(); }, 500);
				}
			}
			// A long email ends in "…" in the pill; the tooltip keeps it whole.
			if (pillText) { pill.setAttribute('title', pillText.textContent); }
			var expiresEl = document.getElementById('bp-gs-expires');
			if (expiresEl && s.expires_in > 0) {
				var mins = Math.max(0, Math.ceil(s.expires_in / 60));
				expiresEl.textContent = window.brikpanelFormat ? window.brikpanelFormat.count(i18n.expires_template, mins) : '';
			}
		}).catch(function () { /* silent */ });
	}
	if (root.getAttribute('data-connected') === '1') {
		setInterval(pollStatus, 30000);
	}

})();
