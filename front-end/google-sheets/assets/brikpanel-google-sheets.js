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
			tabs.forEach(function (b) { b.classList.toggle('is-active', b === btn); });
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
	function toast(tone, message, opts) {
		if (!toastEl) {
			return;
		}
		opts = opts || {};
		toastEl.textContent = message;
		toastEl.setAttribute('data-tone', tone || 'info');
		toastEl.hidden = false;
		void toastEl.offsetWidth;
		toastEl.classList.add('is-visible');
		clearTimeout(toastTimer);
		if (!opts.sticky) {
			toastTimer = setTimeout(function () {
				toastEl.classList.remove('is-visible');
				setTimeout(function () { toastEl.hidden = true; }, 350);
			}, opts.duration || 3500);
		}
	}
	function dismissToast() {
		if (!toastEl) return;
		clearTimeout(toastTimer);
		toastEl.classList.remove('is-visible');
		setTimeout(function () { toastEl.hidden = true; }, 350);
	}

	// Surface OAuth-return flash on load.
	var flashTone = root.getAttribute('data-flash-tone');
	var flashMsg = root.getAttribute('data-flash-message');
	if (flashTone && flashMsg) {
		setTimeout(function () { toast(flashTone, flashMsg); }, 100);
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
	// ---------------------------------------------------------------------
	function ajax(action, payload) {
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
		}).then(function (r) { return r.json(); }).then(function (j) {
			if (!j || j.success !== true) {
				var msg = (j && j.data && j.data.message) ? j.data.message : i18n.generic_error;
				var err = new Error(msg);
				err.payload = j && j.data ? j.data : {};
				throw err;
			}
			return j.data || {};
		});
	}

	// ---------------------------------------------------------------------
	// Connect / Disconnect / Re-auth
	// ---------------------------------------------------------------------
	function startConnect() {
		toast('info', i18n.connecting);
		ajax('brikpanel_gs_oauth_start').then(function (data) {
			if (data && data.authorize_url) {
				window.location.href = data.authorize_url;
			} else {
				toast('error', i18n.generic_error);
			}
		}).catch(function (e) { toast('error', e.message); });
	}
	var connectBtn = document.getElementById('bp-gs-connect');
	if (connectBtn) { connectBtn.addEventListener('click', startConnect); }
	var reauthBtn = document.getElementById('bp-gs-reauth');
	if (reauthBtn) { reauthBtn.addEventListener('click', startConnect); }
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
				if (!window.gapi) { reject(new Error(i18n.picker_failed || 'gapi unavailable')); return; }
				window.gapi.load('picker', {
					callback: function () { resolve(); },
					onerror: function () { reject(new Error(i18n.picker_failed || 'picker load failed')); }
				});
			};
			s.onerror = function () { reject(new Error(i18n.picker_failed || 'api.js load failed')); };
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
				toast('success', i18n.validated + ' — ' + (data.spreadsheet_title || data.spreadsheet_id));
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
			toast('info', i18n.creating || 'Creating spreadsheet…');
			ajax('brikpanel_gs_create_spreadsheet', { title: title })
				.then(function (data) {
					toast('success', i18n.created + ' — ' + (data.spreadsheet_title || ''));
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

	function resetSync(btn, form) {
		// Pick the right confirm message based on which flow's reset button was
		// clicked — orders reset wording mentions order history, products reset
		// mentions every product. Both share the same destructive footprint
		// (wipe tab + re-push) but the user-facing copy should not lie.
		var resetFlow = btn.getAttribute('data-reset-flow') || form.getAttribute('data-flow') || 'orders';
		var defaultMsg = i18n.reset_confirm;
		if (resetFlow === 'products' && i18n.reset_products_confirm) {
			defaultMsg = i18n.reset_products_confirm;
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
			.then(function () { toast('success', i18n.saved); })
			.catch(function (e) { toast('error', e.message); })
			.then(function () { btn.disabled = false; });
	}

	function syncNow(flow, btn) {
		btn.disabled = true;
		var originalText = btn.textContent;
		btn.innerHTML = '<span class="bp-gs-spinner" aria-hidden="true"></span> ' + escapeHtml(i18n.syncing);

		// Sticky "Syncing..." toast — stays visible until request completes.
		toast('info', i18n.syncing, { sticky: true });

		ajax('brikpanel_gs_sync_now', { flow: flow })
			.then(function (data) {
				dismissToast();
				var msg = (data && data.message) ? data.message : i18n.saved;
				if (data && typeof data.duration_seconds === 'number') {
					msg += ' (' + data.duration_seconds + 's)';
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
				panel.innerHTML = '<table><thead><tr><th>Time</th><th>Flow</th><th>Code</th><th>Message</th></tr></thead><tbody>' + rows + '</tbody></table>';
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
				if (pillText) { pillText.textContent = (s.email || i18n.connected_label || 'Connected'); }
			} else {
				// Token revoked externally — reflect it AND force a page reload
				// once so the disconnected UI replaces the target-spreadsheet
				// card. Use a sentinel attribute so we only reload once.
				pill.setAttribute('data-state', 'off');
				if (pillText) { pillText.textContent = (i18n.not_connected_label || 'Not connected'); }
				if (!root.getAttribute('data-disconnect-reloaded')) {
					root.setAttribute('data-disconnect-reloaded', '1');
					setTimeout(function () { window.location.reload(); }, 500);
				}
			}
			var expiresEl = document.getElementById('bp-gs-expires');
			if (expiresEl && s.expires_in > 0) {
				var mins = Math.max(0, Math.ceil(s.expires_in / 60));
				var tpl = i18n.expires_template || 'in %d min (auto-refreshed)';
				expiresEl.textContent = tpl.replace('%d', String(mins));
			}
		}).catch(function () { /* silent */ });
	}
	if (root.getAttribute('data-connected') === '1') {
		setInterval(pollStatus, 30000);
	}

})();
