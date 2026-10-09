/**
 * BrikPanel — Ad Platforms admin page JS.
 *
 * Vanilla JS; no jQuery. Single AJAX helper that wraps fetch() with the
 * nonce + admin-ajax conventions, then per-action handlers wired by data
 * attributes on the rendered cards.
 */
(function () {
	'use strict';

	if (typeof window.BrikpanelAds !== 'object') {
		return;
	}
	var BP = window.BrikpanelAds;
	var $root = document.getElementById('bp-ads');
	if (!$root) { return; }

	// ---------- helpers ----------
	// Every failure is an Error with `kind` (session, cutoff, fetch, server)
	// and `data`. A reply that is not BrikPanel's own (a PHP fatal, a host
	// time-out, a front server's error page) used to reach r.json() and show
	// the browser's "Unexpected token '<' ... is not valid JSON" in the toast.
	function ajaxError(kind, message, data) {
		var err = new Error(message);
		err.kind = kind;
		err.data = data || {};
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

	function ajax(action, data, opts) {
		opts = opts || {};
		var fallback = opts.fallback || BP.i18n.server_error_generic;
		var fd = new FormData();
		fd.append('action', action);
		fd.append('_ajax_nonce', BP.nonce);
		if (data && typeof data === 'object') {
			Object.keys(data).forEach(function (k) {
				var v = data[k];
				if (v === undefined || v === null) { return; }
				if (Array.isArray(v)) {
					// PHP reads "key[]" as an array. Appending the array itself
					// would send one comma-joined string.
					v.forEach(function (item) { fd.append(k + '[]', item); });
				} else {
					fd.append(k, v);
				}
			});
		}
		return fetch(BP.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: fd
		}).then(function (r) {
			return r.text().then(function (text) {
				var raw = String(text || '').trim();
				// "-1": the security token expired; "0": logged out, or no handler.
				if (raw === '-1' || raw === '0') {
					throw ajaxError('session', BP.i18n.session_expired, { http: r.status });
				}
				var json = parseAnswer(raw);
				if (!json || typeof json !== 'object') {
					throw ajaxError('cutoff', fallback, { http: r.status });
				}
				if (!json.success) {
					var d = json.data;
					var msg = (d && typeof d === 'object' && d.message) ? d.message
						: ((typeof d === 'string' && d) ? d : BP.i18n.generic_error);
					throw ajaxError('server', msg, (d && typeof d === 'object') ? d : {});
				}
				return json.data || {};
			});
		}, function () {
			throw ajaxError('fetch', fallback, {});
		});
	}

	function toast(message, tone) {
		var $t = document.getElementById('bp-ads-toast');
		if (!$t) { return; }
		// Both timers go: the slide-out's own 320 ms hide used to fire after a
		// new toast had appeared, and took that one away with it.
		clearTimeout(toast._timer);
		clearTimeout(toast._hideTimer);
		$t.textContent = message;
		$t.className = 'bp-ads-toast ' + (tone === 'error' ? 'is-error' : 'is-success');
		$t.hidden = false;
		// reflow then animate in
		void $t.offsetWidth;
		$t.classList.add('is-visible');
		toast._timer = setTimeout(function () {
			$t.classList.remove('is-visible');
			toast._hideTimer = setTimeout(function () { $t.hidden = true; }, 320);
		}, 3500);
	}

	function busy($btn, on) {
		if (!$btn) { return; }
		if (on) { $btn.classList.add('is-loading'); $btn.disabled = true; }
		else    { $btn.classList.remove('is-loading'); $btn.disabled = false; }
	}

	function cardForButton($btn) {
		return $btn.closest('.bp-ads-card');
	}

	function platformOfCard($card) {
		return $card ? $card.getAttribute('data-platform') : '';
	}

	// ---------- flash on load ----------
	(function () {
		var tone = $root.getAttribute('data-flash-tone');
		var msg  = $root.getAttribute('data-flash-message');
		if (tone) {
			// An error flash carries no text of its own any more (the server
			// fills the box from a one-time record), so a reload may arrive
			// with none.
			if (msg) {
				toast(msg, tone === 'success' ? 'success' : 'error');
			}
			// Strip the flash params from the URL so refresh doesn't re-show.
			if (window.history && window.history.replaceState) {
				var url = new URL(window.location.href);
				url.searchParams.delete('brikpanel_ads_flash');
				url.searchParams.delete('brikpanel_msg');
				window.history.replaceState({}, '', url.toString());
			}
		}
	})();

	// ---------- click delegation ----------
	$root.addEventListener('click', function (e) {
		var $btn = e.target.closest('[data-action]');
		if (!$btn) { return; }
		var action = $btn.getAttribute('data-action');
		var $card  = cardForButton($btn);
		var platform = platformOfCard($card);

		switch (action) {
			case 'connect':
				return handleConnect($btn, platform, false);
			case 'reconnect':
				// Re-authorize must be able to win back a permission the user
				// unticked last time, which needs auth_type=rerequest upstream.
				return handleConnect($btn, platform, true);
			case 'disconnect':
				return handleDisconnect($btn, platform);
			case 'load-accounts':
				return handleLoadAccounts($btn, platform);
			case 'save-accounts':
				return handleSaveAccounts($btn, platform);
			case 'save-manual':
				return handleSaveManual($btn, platform);
			case 'save-mcc':
				return handleSaveMcc($btn);
			case 'sync-now':
				return handleSyncNow($btn, platform);
			case 'view-log':
				return handleViewLog($btn);
			case 'clear-log':
				return handleClearLog($btn);
			case 'refresh-insights':
				return loadInsights($btn);
		}
	});

	// Keep Save, the hints and the currency note in step with the ticks.
	$root.addEventListener('change', function (e) {
		var $box = e.target.closest('.bp-ads-account-check');
		if (!$box) { return; }
		refreshAccountState($box.closest('.bp-ads-card'));
	});

	// ---------- connect / re-authorize ----------
	// The server asks brksoft.com for the platform's sign-in address. When
	// that request cannot get through, the browser asks once more (the server
	// then uses IPv4 only) and says "Still trying…" meanwhile. If that fails
	// too, the box in the card says what went wrong, why and what to do, and
	// stays until the next click. The two cards share one guard, so two
	// connects can never race.
	var connectInFlight = false;

	function connectParts($btn) {
		var $card = cardForButton($btn);
		return {
			btn: $btn,
			box: $card ? $card.querySelector('[data-role="connect-error"]') : null,
			status: $card ? $card.querySelector('[data-role="connect-status"]') : null
		};
	}

	function setConnectBusy(ui, on, statusText) {
		busy(ui.btn, on);
		if (ui.btn) {
			if (on) { ui.btn.setAttribute('aria-busy', 'true'); } else { ui.btn.removeAttribute('aria-busy'); }
		}
		if (ui.status) { ui.status.textContent = on ? (statusText || '') : ''; }
	}

	function setBoxPart($box, role, text) {
		var $el = $box.querySelector('[data-role="' + role + '"]');
		if (!$el) { return; }
		$el.textContent = text || '';
		$el.hidden = !text;
	}

	function showConnectError(ui, info) {
		if (!ui.box) {
			toast(info.title || info.reason || BP.i18n.generic_error, 'error');
			return;
		}
		// Shown first, filled after: role="alert" then reads the new text out.
		ui.box.hidden = false;
		setBoxPart(ui.box, 'connect-error-title', info.title);
		setBoxPart(ui.box, 'connect-error-reason', info.reason);
		setBoxPart(ui.box, 'connect-error-help', info.help);
		var $row = ui.box.querySelector('[data-role="connect-error-detail-row"]');
		var $code = ui.box.querySelector('[data-role="connect-error-detail"]');
		if ($code) { $code.textContent = info.detail || ''; }
		if ($row) { $row.hidden = !info.detail; }
	}

	function connectErrorInfo(err) {
		if (err && err.kind === 'session') {
			return { title: '', reason: err.message, help: '', detail: '' };
		}
		if (err && (err.kind === 'cutoff' || err.kind === 'fetch')) {
			var http = err.data && err.data.http ? err.data.http : 0;
			return {
				title: BP.i18n.connect_failed_title,
				reason: BP.i18n.connect_cut_off,
				help: BP.i18n.connect_help,
				detail: http ? 'HTTP ' + http : '' // i18n-ignore: protocol name and status number, not prose
			};
		}
		var d = (err && err.data) || {};
		return {
			title: d.message || (err && err.message) || BP.i18n.generic_error,
			reason: d.reason || '',
			help: d.help || '',
			detail: d.detail || ''
		};
	}

	function handleConnect($btn, platform, reauth) {
		if (!platform || connectInFlight) { return; }
		connectInFlight = true;
		var ui = connectParts($btn);
		if (ui.box) { ui.box.hidden = true; }
		setConnectBusy(ui, true, BP.i18n.connecting);

		function attempt(n) {
			return ajax('brikpanel_ads_oauth_start', { platform: platform, reauth: reauth ? 1 : 0, attempt: n }, { fallback: BP.i18n.connect_cut_off })
				.then(function (data) {
					if (data && data.authorize_url) {
						// Stay busy while the browser leaves for the platform.
						window.location.href = data.authorize_url;
						return;
					}
					throw ajaxError('server', BP.i18n.generic_error, {});
				})
				.catch(function (err) {
					var d = (err && err.data) || {};
					// Only a request that never got an answer is worth repeating.
					var again = n < 2 && (d.retry === true || (err && (err.kind === 'cutoff' || err.kind === 'fetch')));
					if (again) {
						setConnectBusy(ui, true, BP.i18n.still_trying);
						return new Promise(function (resolve) { setTimeout(resolve, 1000); })
							.then(function () { return attempt(n + 1); });
					}
					connectInFlight = false;
					setConnectBusy(ui, false);
					showConnectError(ui, connectErrorInfo(err));
					if ($btn && document.activeElement === document.body) { $btn.focus(); }
				});
		}
		attempt(1);
	}

	// Coming back with the Back button restores the page as it was left: a
	// button still spinning for the trip to the consent screen.
	window.addEventListener('pageshow', function (ev) {
		if (!ev.persisted) { return; }
		connectInFlight = false;
		Array.prototype.forEach.call($root.querySelectorAll('[data-action="connect"], [data-action="reconnect"]'), function ($b) {
			setConnectBusy(connectParts($b), false);
		});
	});

	// ---------- action handlers ----------

	function handleDisconnect($btn, platform) {
		if (!platform) { return; }
		if (!window.confirm(BP.i18n.disconnect_confirm)) { return; }
		busy($btn, true);
		ajax('brikpanel_ads_oauth_disconnect', { platform: platform })
			.then(function () {
				toast(BP.i18n.saved, 'success');
				setTimeout(function () { window.location.reload(); }, 400);
			})
			.catch(function (err) {
				busy($btn, false);
				toast(err.message || BP.i18n.generic_error, 'error');
			});
	}

	// ---------- ad account list ----------
	function accountField($card) {
		return $card ? $card.querySelector('[data-role="accounts-field"]') : null;
	}

	// The selection as the page was rendered (what the server has stored).
	function savedAccounts($card) {
		var $field = accountField($card);
		try {
			var list = JSON.parse(($field && $field.getAttribute('data-saved')) || '[]');
			return Array.isArray(list) ? list.map(String) : [];
		} catch (e) {
			return [];
		}
	}

	function accountChecks($card) {
		var $field = accountField($card);
		return $field ? Array.prototype.slice.call($field.querySelectorAll('.bp-ads-account-check')) : [];
	}

	function tickedAccounts($card) {
		return accountChecks($card)
			.filter(function ($c) { return $c.checked; })
			.map(function ($c) { return $c.value; });
	}

	function sameSet(a, b) {
		if (a.length !== b.length) { return false; }
		var x = a.slice().sort();
		var y = b.slice().sort();
		for (var i = 0; i < x.length; i++) {
			if (x[i] !== y[i]) { return false; }
		}
		return true;
	}

	function refreshAccountState($card) {
		if (!$card || !accountField($card)) { return; }
		var $save = $card.querySelector('[data-action="save-accounts"]');
		if ($save) { $save.disabled = sameSet(tickedAccounts($card), savedAccounts($card)); }

		// Ticking a manager account is allowed — rare setups do report spend
		// on one — but it is far more often a mistake, so warn rather than
		// block. Google only ever returns accounts linked directly to the
		// signed-in address, so an agency whose login sits on the manager
		// sees nothing but managers; say what to do instead.
		var managerTicked = accountChecks($card).some(function ($c) {
			return $c.checked && $c.getAttribute('data-manager') === '1';
		});
		showAccountHint($card, managerTicked
			? BP.i18n.manager_picked
			: ($card.getAttribute('data-only-managers') === '1' ? BP.i18n.only_managers : ''));

		updateCurrencyNote($card);
	}

	// Ticked accounts that spend in another currency than the store: the
	// dashboard cannot put that spend into ROAS or Net profit.
	function updateCurrencyNote($card) {
		var $note = $card.querySelector('[data-role="currency-note"]');
		if (!$note) { return; }
		var store = String(BP.storeCurrency || '').toUpperCase();
		var names = [];
		accountChecks($card).forEach(function ($c) {
			var cur = String($c.getAttribute('data-currency') || '').toUpperCase();
			if ($c.checked && store && cur && cur !== store) {
				names.push($c.getAttribute('data-name') || $c.value);
			}
		});
		if (!names.length || !window.brikpanelFormat) {
			$note.textContent = '';
			$note.hidden = true;
			return;
		}
		var F = window.brikpanelFormat;
		$note.textContent = F.format(F.plural(BP.i18n.currency_note, names.length), [names.join(', ')]);
		$note.hidden = false;
	}

	function accountRow(id, a, saved) {
		var $li = document.createElement('li');
		$li.className = 'bp-ads-account';
		var $label = document.createElement('label');
		$label.className = 'bp-ads-account-label';

		var $box = document.createElement('input');
		$box.type = 'checkbox';
		$box.className = 'bp-ads-account-check';
		$box.value = id;
		$box.checked = !!a.checked;
		$box.setAttribute('data-name', a.name || '');
		$box.setAttribute('data-currency', a.currency || '');
		$box.setAttribute('data-has-data', a.hasData ? '1' : '0');
		$box.setAttribute('data-manager', a.manager ? '1' : '0');

		var $text = document.createElement('span');
		$text.className = 'bp-ads-account-text';
		var $name = document.createElement('span');
		$name.className = 'bp-ads-account-name';
		$name.textContent = a.name || id;
		$text.appendChild($name);

		var parts = [a.name ? id : '', a.currency || '', a.status || '', a.manager ? BP.i18n.manager_suffix : '']
			.filter(function (p) { return !!p; });
		if (parts.length) {
			var $meta = document.createElement('span');
			$meta.className = 'bp-ads-account-meta';
			$meta.textContent = parts.join(' · ');
			$text.appendChild($meta);
		}

		$label.appendChild($box);
		$label.appendChild($text);

		// Spend still stored for an account the saved selection leaves out.
		if (a.hasData && saved.indexOf(id) === -1) {
			var $badge = document.createElement('span');
			$badge.className = 'brikpanel-badge bp-ads-account-badge';
			$badge.textContent = BP.i18n.not_selected || '';
			$label.appendChild($badge);
		}
		$li.appendChild($label);
		return $li;
	}

	function handleLoadAccounts($btn, platform) {
		if (!platform) { return; }
		var $card = cardForButton($btn);
		var $list = $card ? $card.querySelector('[data-role="account-list"]') : null;
		if (!$list) { return; }
		busy($btn, true);
		ajax('brikpanel_ads_list_accounts', { platform: platform })
			.then(function (data) {
				var accounts = (data && data.accounts) || [];
				if (!accounts.length) {
					toast(BP.i18n.no_accounts, 'error');
					busy($btn, false);
					return;
				}
				// Keep what is on screen: ticks the merchant already changed, and
				// rows the server listed (accounts added by ID, accounts with
				// imported spend) that the platform's list may not contain.
				var order = [];
				var info = {};
				accountChecks($card).forEach(function ($c) {
					order.push($c.value);
					info[$c.value] = {
						checked: $c.checked,
						name: $c.getAttribute('data-name') || '',
						currency: $c.getAttribute('data-currency') || '',
						hasData: $c.getAttribute('data-has-data') === '1',
						manager: $c.getAttribute('data-manager') === '1',
						status: ''
					};
				});
				accounts.forEach(function (acc) {
					var id = platform === 'google_ads'
						? String(acc.id || '')
						: String(acc.id || (acc.account_id ? 'act_' + acc.account_id : ''));
					if (!id) { return; }
					var prev = info[id];
					info[id] = {
						checked: prev ? prev.checked : false,
						name: acc.name && String(acc.name) !== id ? String(acc.name) : (prev ? prev.name : ''),
						currency: acc.currency ? String(acc.currency).toUpperCase() : (prev ? prev.currency : ''),
						hasData: prev ? prev.hasData : false,
						manager: !!acc.is_manager,
						// Surface disabled / closed / unsettled accounts so the
						// merchant doesn't tick a dead one and then wonder why
						// today's spend never moves off zero.
						status: acc.status_label ? String(acc.status_label) : ''
					};
					if (!prev) { order.push(id); }
				});

				var saved = savedAccounts($card);
				$list.innerHTML = '';
				order.forEach(function (id) { $list.appendChild(accountRow(id, info[id], saved)); });
				$list.hidden = false;
				var $empty = $card.querySelector('[data-role="accounts-empty"]');
				if ($empty) { $empty.remove(); }

				if (platform === 'google_ads') {
					var onlyManagers = accounts.every(function (acc) { return !!acc.is_manager; });
					$card.setAttribute('data-only-managers', onlyManagers ? '1' : '0');
				}
				refreshAccountState($card);
				busy($btn, false);
			})
			.catch(function (err) {
				busy($btn, false);
				toast(err.message || BP.i18n.generic_error, 'error');
			});
	}

	// Show (or clear) an inline hint under the account list.
	function showAccountHint($card, message) {
		var $field = accountField($card);
		if (!$field) { return; }
		var $hint = $field.querySelector('[data-role="account-hint"]');
		if (!message) {
			if ($hint) { $hint.remove(); }
			return;
		}
		if (!$hint) {
			$hint = document.createElement('p');
			$hint.className = 'bp-ads-inline-hint';
			$hint.setAttribute('data-role', 'account-hint');
			var $actions = $field.querySelector('.bp-ads-accounts-actions');
			$field.insertBefore($hint, $actions ? $actions.nextSibling : null);
		}
		$hint.textContent = message;
	}

	// The saved card is drawn by the server (names, "Not selected" marks,
	// Sync now), so a save reloads the page and says how it went there.
	function reloadWithFlash(message, tone) {
		var url = new URL(window.location.href);
		url.searchParams.set('brikpanel_ads_flash', tone === 'error' ? 'error' : 'success');
		url.searchParams.set('brikpanel_msg', message);
		window.location.href = url.toString();
	}

	function handleSaveAccounts($btn, platform) {
		if (!platform) { return; }
		var $card = cardForButton($btn);
		var ticked = tickedAccounts($card);
		if (!ticked.length) {
			toast(BP.i18n.pick_accounts_first, 'error');
			return;
		}
		// The server deletes the stored spend of every account left out.
		var losing = accountChecks($card).some(function ($c) {
			return !$c.checked && $c.getAttribute('data-has-data') === '1';
		});
		if (losing && !window.confirm(BP.i18n.remove_confirm)) { return; }

		busy($btn, true);
		ajax('brikpanel_ads_save_accounts', {
			platform: platform,
			mode: 'replace',
			account_ids: ticked,
			// The list this page shows. If another tab has changed the stored
			// list since, the server refuses instead of deleting spend of an
			// account this page never showed.
			base_ids: savedAccounts($card)
		})
			.then(function (data) {
				reloadWithFlash((data && data.message) || BP.i18n.saved, 'success');
			})
			.catch(function (err) {
				busy($btn, false);
				toast(err.message || BP.i18n.generic_error, 'error');
			});
	}

	// "Can't find your account?": adds one account to the stored list and
	// never removes one, whatever the boxes above show.
	function handleSaveManual($btn, platform) {
		if (!platform) { return; }
		var $card = cardForButton($btn);
		var $inp  = $card.querySelector('[data-role="manual-account"]');
		var accountId = $inp ? $inp.value.trim() : '';
		if (!accountId) {
			toast(BP.i18n.manual_empty, 'error');
			return;
		}
		busy($btn, true);
		ajax('brikpanel_ads_save_accounts', { platform: platform, mode: 'add', account_ids: [accountId] })
			.then(function (data) {
				reloadWithFlash((data && data.message) || BP.i18n.saved, 'success');
			})
			.catch(function (err) {
				busy($btn, false);
				toast(err.message || BP.i18n.generic_error, 'error');
			});
	}

	function handleSaveMcc($btn) {
		var $card = cardForButton($btn);
		var $inp  = $card.querySelector('#bp-ads-mcc');
		var val   = $inp ? $inp.value.replace(/[^0-9]/g, '') : '';
		busy($btn, true);
		ajax('brikpanel_ads_save_login_customer', { login_customer_id: val })
			.then(function () {
				toast(BP.i18n.saved, 'success');
				busy($btn, false);
			})
			.catch(function (err) {
				busy($btn, false);
				toast(err.message || BP.i18n.generic_error, 'error');
			});
	}

	function handleSyncNow($btn, platform) {
		if (!platform) { return; }
		busy($btn, true);
		ajax('brikpanel_ads_sync_now', { platform: platform })
			.then(function (data) {
				toast((data && data.message) || BP.i18n.saved, data && data.tone === 'error' ? 'error' : 'success');
				busy($btn, false);
				// Update last-sync line without a full page reload.
				var $card = cardForButton($btn);
				var $last = $card.querySelector('[data-role="last-sync"]');
				if ($last) { $last.textContent = BP.i18n.just_now; }
				// Trigger a status refresh so backfill bar can update too.
				refreshStatus();
				// New rows may have landed; refresh the imported-data panel.
				loadInsights();
			})
			.catch(function (err) {
				busy($btn, false);
				toast(err.message || BP.i18n.generic_error, 'error');
			});
	}

	function handleViewLog($btn) {
		var $log = document.getElementById('bp-ads-log');
		if (!$log) { return; }
		if ($log.hidden === false && $log.dataset.loaded === '1') {
			$log.hidden = true;
			$log.dataset.loaded = '';
			return;
		}
		busy($btn, true);
		ajax('brikpanel_ads_view_log', {})
			.then(function (data) {
				renderLog($log, (data && data.entries) || []);
				$log.hidden = false;
				$log.dataset.loaded = '1';
				busy($btn, false);
			})
			.catch(function (err) {
				busy($btn, false);
				toast(err.message || BP.i18n.generic_error, 'error');
			});
	}

	function handleClearLog($btn) {
		busy($btn, true);
		ajax('brikpanel_ads_clear_log', {})
			.then(function () {
				var $log = document.getElementById('bp-ads-log');
				if ($log) {
					$log.innerHTML = '<div class="bp-ads-log-empty">' + BP.i18n.log_empty + '</div>';
				}
				busy($btn, false);
			})
			.catch(function (err) {
				busy($btn, false);
				toast(err.message || BP.i18n.generic_error, 'error');
			});
	}

	function renderLog($log, entries) {
		if (!entries || !entries.length) {
			$log.innerHTML = '<div class="bp-ads-log-empty">' + BP.i18n.log_empty + '</div>';
			return;
		}
		var html = '';
		entries.forEach(function (e) {
			// A background job that correctly found nothing to do is not a
			// failure. Label it, so a run of routine notes stops reading as a
			// run of errors.
			var isNote = e.severity === 'info';
			html += '<div class="bp-ads-log-entry' + (isNote ? ' is-note' : '') + '">'
				+   '<div class="bp-ads-log-meta">'
				+     '<span class="bp-ads-log-sev' + (isNote ? ' is-note' : '') + '">'
				+       escapeHtml(isNote ? (BP.i18n.log_note_label || '') : (BP.i18n.log_error_label || ''))
				+     '</span>'
				+     '<span class="bp-ads-log-flow">' + escapeHtml(e.flow || '') + '</span>'
				+     '<span>' + escapeHtml(e.ts_display || '') + '</span>'
				+     (e.code ? '<span class="bp-ads-log-code">HTTP ' + escapeHtml(String(e.code)) + '</span>' : '')
				+   '</div>'
				+   '<div class="bp-ads-log-msg">' + escapeHtml(e.message || '') + '</div>'
				+ '</div>';
		});
		$log.innerHTML = html;
	}

	function escapeHtml(s) {
		return String(s).replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	}

	// ---------- imported spend data (monthly breakdown) ----------
	// Laid out like the store's prices, numbers and dates, in the viewer's
	// language (front-end/shared/brikpanel-format.js); the amount keeps the ad
	// account's own currency symbol. All of it used to follow the browser's
	// language (field test E2).
	var BF = window.brikpanelFormat || null;
	var symbols = {};
	function fmtMoney(amount, currency) {
		var n = Number(amount) || 0;
		var code = String(currency || '').toUpperCase();
		if (!BF) { return (code ? code + ' ' : '') + n.toFixed(2); }
		return BF.money(n, { symbol: symbols[code] || code, decimals: 2 });
	}
	function fmtNum(n) {
		return BF ? BF.number(Number(n) || 0) : String(Number(n) || 0);
	}
	function fmtPct(n) {
		return BF ? BF.percent(Number(n) || 0, 2, false) : (Number(n) || 0).toFixed(2);
	}
	function fmtMonth(ym) {
		return BF && /^\d{4}-\d{1,2}$/.test(String(ym)) ? BF.monthYear(ym + '-01') : String(ym);
	}
	function fmtDay(ymd) {
		return BF && ymd ? BF.dateShort(ymd) : String(ymd || '');
	}
	function derive(spend, impr, clicks) {
		return {
			ctr: impr > 0 ? (clicks / impr * 100) : 0,
			cpc: clicks > 0 ? (spend / clicks) : 0,
			cpm: impr > 0 ? (spend / impr * 1000) : 0
		};
	}
	function kpiCell(value, label) {
		return '<div class="bp-ads-kpi">'
			+ '<span class="bp-ads-kpi-v">' + escapeHtml(value) + '</span>'
			+ '<span class="bp-ads-kpi-l">' + escapeHtml(label || '') + '</span>'
			+ '</div>';
	}

	function renderPlatformInsight(platform, acc, i18n, fold) {
		var name = platform === 'google_ads'
			? (BP.i18n.platform_google || '')
			: (BP.i18n.platform_meta || '');
		var cur = acc.currency || '';
		var s = acc.summary;
		var d = derive(s.spend, s.impressions, s.clicks);

		var label = acc.name ? acc.name + ' (' + acc.account_id + ')' : acc.account_id;
		var meta = (i18n.account || '').replace('%s', escapeHtml(label))
			+ ' · ' + escapeHtml(cur)
			+ ' · ' + (i18n.span || '')
				.replace('%1$s', escapeHtml(fmtDay(s.first_date)))
				.replace('%2$s', escapeHtml(fmtDay(s.last_date)));

		var kpis = [
			kpiCell(fmtMoney(s.spend, cur), i18n.kpi_spend),
			kpiCell(fmtNum(s.impressions), i18n.kpi_impr),
			kpiCell(fmtNum(s.clicks), i18n.kpi_clicks),
			kpiCell(fmtPct(d.ctr), i18n.kpi_ctr),
			kpiCell(fmtMoney(d.cpc, cur), i18n.kpi_cpc),
			kpiCell(fmtMoney(d.cpm, cur), i18n.kpi_cpm)
		].join('');

		var totalRow = '<tr class="bp-ads-table-total">'
			+ '<td class="brikpanel-fit-lead">' + escapeHtml(i18n.total_row || '') + '</td>'
			+ '<td class="num">' + escapeHtml(fmtMoney(s.spend, cur)) + '</td>'
			+ '<td class="num">' + escapeHtml(fmtNum(s.impressions)) + '</td>'
			+ '<td class="num">' + escapeHtml(fmtNum(s.clicks)) + '</td>'
			+ '<td class="num">' + escapeHtml(fmtPct(d.ctr)) + '</td>'
			+ '<td class="num">' + escapeHtml(fmtMoney(d.cpc, cur)) + '</td>'
			+ '</tr>';

		var rows = acc.months.map(function (m) {
			var md = derive(m.spend, m.impressions, m.clicks);
			var rc = m.currency || cur;
			return '<tr>'
				+ '<td class="brikpanel-fit-lead">' + escapeHtml(fmtMonth(m.month)) + '</td>'
				+ '<td class="num brikpanel-fit-headline">' + escapeHtml(fmtMoney(m.spend, rc)) + '</td>'
				+ '<td class="num">' + escapeHtml(fmtNum(m.impressions)) + '</td>'
				+ '<td class="num">' + escapeHtml(fmtNum(m.clicks)) + '</td>'
				+ '<td class="num">' + escapeHtml(fmtPct(md.ctr)) + '</td>'
				+ '<td class="num">' + escapeHtml(fmtMoney(md.cpc, rc)) + '</td>'
				+ '</tr>';
		}).join('');

		var table = '<div class="bp-ads-table-wrap"><table class="bp-ads-table brikpanel-fit-table">'
			+   '<thead><tr>'
			+     '<th>' + escapeHtml(i18n.col_month || '') + '</th>'
			+     '<th class="num">' + escapeHtml(i18n.col_spend || '') + '</th>'
			+     '<th class="num">' + escapeHtml(i18n.col_impr || '') + '</th>'
			+     '<th class="num">' + escapeHtml(i18n.col_clicks || '') + '</th>'
			+     '<th class="num">' + escapeHtml(i18n.col_ctr || '') + '</th>'
			+     '<th class="num">' + escapeHtml(i18n.col_cpc || '') + '</th>'
			+   '</tr></thead>'
			+   '<tbody>' + totalRow + rows + '</tbody>'
			+ '</table></div>';

		// With many accounts the month tables are folded: twenty of them open
		// at once make a very long page, and each one is measured to fit.
		if (fold) {
			table = '<details class="bp-ads-insight-months">'
				+ '<summary class="bp-ads-insight-months-toggle">' + escapeHtml(i18n.months || '') + '</summary>'
				+ table
				+ '</details>';
		}

		var badge = acc.selected ? ''
			: ' <span class="brikpanel-badge bp-ads-account-badge">' + escapeHtml(BP.i18n.not_selected || '') + '</span>';

		return '<div class="bp-ads-insight">'
			+ '<div class="bp-ads-insight-head">'
			+   '<span class="bp-ads-insight-name">' + escapeHtml(name) + badge + '</span>'
			+   '<span class="bp-ads-insight-meta">' + meta + '</span>'
			+ '</div>'
			+ '<div class="bp-ads-kpi-strip">' + kpis + '</div>'
			+ table
			+ '</div>';
	}

	// Stacked into cards when a month table cannot show every column (field
	// test B6: on a phone CTR and CPC scrolled out of sight). Measured while
	// visible; a folded table is measured when it is opened.
	function fitInsightTables($body) {
		if (!window.brikpanelFitTable) { return; }
		Array.prototype.forEach.call($body.querySelectorAll('.bp-ads-table-wrap'), function (wrap) {
			var fitNow = function () {
				if (wrap.bpFit) { wrap.bpFit.refit(); return; }
				var fit = window.brikpanelFitTable(wrap, { labels: 'head', slack: 0 });
				if (fit) { wrap.bpFit = fit; fit.refit(); }
			};
			var $details = wrap.closest('details');
			if (!$details) {
				fitNow();
			} else {
				$details.addEventListener('toggle', function () {
					if ($details.open) { fitNow(); }
				});
			}
		});
	}

	function loadInsights($btn) {
		var $card = document.getElementById('bp-ads-insights');
		var $body = document.getElementById('bp-ads-insights-body');
		if (!$card || !$body) { return; }
		var i18n = BP.i18n.insights || {};
		if ($btn) { busy($btn, true); }
		ajax('brikpanel_ads_spend_breakdown', {})
			.then(function (data) {
				var blocks = [];
				['google_ads', 'meta_ads'].forEach(function (p) {
					var pd = data && data[p];
					if (!pd || !pd.connected || !Array.isArray(pd.accounts)) { return; }
					if (pd.symbols) {
						Object.keys(pd.symbols).forEach(function (k) { symbols[k] = pd.symbols[k]; });
					}
					pd.accounts.forEach(function (acc) {
						if (!acc || !acc.summary || !acc.months || !acc.months.length) { return; }
						blocks.push({ platform: p, acc: acc });
					});
				});
				if (!blocks.length) {
					$card.hidden = true;
				} else {
					var fold = blocks.length > 3;
					$body.innerHTML = blocks.map(function (b) {
						return renderPlatformInsight(b.platform, b.acc, i18n, fold);
					}).join('');
					$card.hidden = false;
					fitInsightTables($body);
				}
				if ($btn) { busy($btn, false); }
			})
			.catch(function () {
				if ($btn) { busy($btn, false); }
			});
	}

	// ---------- background status polling (backfill progress) ----------
	var pollTimer = null;
	function refreshStatus() {
		ajax('brikpanel_ads_status', {})
			.then(function (data) {
				if (!data) { return; }
				Object.keys(data).forEach(function (platform) {
					var $card = $root.querySelector('.bp-ads-card[data-platform="' + platform + '"]');
					if (!$card) { return; }
					var state = data[platform];
					var $back = $card.querySelector('.bp-ads-backfill');
					var total     = state.backfill && state.backfill.total     || 0;
					var completed = state.backfill && state.backfill.completed || 0;
					var halted    = !!(state.backfill && state.backfill.halted);
					var reason    = (state.backfill && state.backfill.error) || '';
					if (total > 0 && completed < total) {
						if (!$back) {
							var fragment = document.createElement('div');
							fragment.className = 'bp-ads-backfill';
							fragment.innerHTML =
								'<div class="bp-ads-backfill-label"></div>' +
								'<div class="bp-ads-backfill-bar"><div class="bp-ads-backfill-fill" style="width:0%"></div></div>';
							var $body = $card.querySelector('.bp-ads-card-body');
							if ($body) { $body.appendChild(fragment); }
							$back = fragment;
						}
						var pct = Math.min(100, Math.round((completed / Math.max(1, total)) * 100));
						$back.querySelector('.bp-ads-backfill-fill').style.width = pct + '%';
						// A stalled bar with no words is what merchants report as
						// a bug. When the import gave up, say so and say why.
						$back.classList.toggle('is-halted', halted);
						$back.querySelector('.bp-ads-backfill-label').textContent = halted
							? String(BP.i18n.backfill_halted || '')
							: String(BP.i18n.backfill_progress || '')
								.replace('%1$d', completed)
								.replace('%2$d', total);

						var $reason = $back.querySelector('.bp-ads-backfill-reason');
						if (reason) {
							if (!$reason) {
								$reason = document.createElement('p');
								$reason.className = 'bp-ads-backfill-reason';
								$back.appendChild($reason);
							}
							$reason.textContent = reason;
						} else if ($reason) {
							$reason.remove();
						}
					} else if ($back) {
						$back.remove();
					}
				});
			})
			.catch(function () { /* silent */ });
	}

	Array.prototype.forEach.call($root.querySelectorAll('.bp-ads-card[data-connected="1"]'), refreshAccountState);

	// Only poll when at least one card is connected (otherwise nothing to update).
	var anyConnected = !!$root.querySelector('.bp-ads-card[data-connected="1"]');
	if (anyConnected) {
		// Populate the imported-data panel on first paint.
		loadInsights();
		pollTimer = setInterval(refreshStatus, 8000);
		// Pause polling when the page is hidden to avoid pointless work.
		document.addEventListener('visibilitychange', function () {
			if (document.hidden) {
				clearInterval(pollTimer);
				pollTimer = null;
			} else if (!pollTimer) {
				pollTimer = setInterval(refreshStatus, 8000);
				refreshStatus();
			}
		});
	}
})();
