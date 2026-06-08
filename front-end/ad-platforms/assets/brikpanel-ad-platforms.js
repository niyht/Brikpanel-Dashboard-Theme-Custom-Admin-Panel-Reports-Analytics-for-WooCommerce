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
	function ajax(action, data) {
		var fd = new FormData();
		fd.append('action', action);
		fd.append('_ajax_nonce', BP.nonce);
		if (data && typeof data === 'object') {
			Object.keys(data).forEach(function (k) {
				if (data[k] !== undefined && data[k] !== null) {
					fd.append(k, data[k]);
				}
			});
		}
		return fetch(BP.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: fd
		}).then(function (r) { return r.json(); })
		.then(function (json) {
			if (!json || typeof json !== 'object') {
				throw new Error(BP.i18n.generic_error);
			}
			if (!json.success) {
				var msg = (json.data && json.data.message) || BP.i18n.generic_error;
				var err = new Error(msg);
				err.data = json.data || {};
				throw err;
			}
			return json.data || {};
		});
	}

	function toast(message, tone) {
		var $t = document.getElementById('bp-ads-toast');
		if (!$t) { return; }
		$t.textContent = message;
		$t.className = 'bp-ads-toast ' + (tone === 'error' ? 'is-error' : 'is-success');
		$t.hidden = false;
		// reflow then animate in
		void $t.offsetWidth;
		$t.classList.add('is-visible');
		clearTimeout(toast._timer);
		toast._timer = setTimeout(function () {
			$t.classList.remove('is-visible');
			setTimeout(function () { $t.hidden = true; }, 320);
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
		if (tone && msg) {
			toast(msg, tone === 'success' ? 'success' : 'error');
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
			case 'reconnect':
				return handleConnect($btn, platform);
			case 'disconnect':
				return handleDisconnect($btn, platform);
			case 'load-accounts':
				return handleLoadAccounts($btn, platform);
			case 'save-primary':
				return handleSavePrimary($btn, platform);
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

	// Enable Save when the selection changes.
	$root.addEventListener('change', function (e) {
		var $sel = e.target.closest('[data-role="primary-select"]');
		if (!$sel) { return; }
		var $card = $sel.closest('.bp-ads-card');
		var $save = $card.querySelector('[data-action="save-primary"]');
		if ($save) { $save.disabled = !$sel.value; }
	});

	// ---------- action handlers ----------
	function handleConnect($btn, platform) {
		if (!platform) { return; }
		busy($btn, true);
		ajax('brikpanel_ads_oauth_start', { platform: platform })
			.then(function (data) {
				if (data && data.authorize_url) {
					window.location.href = data.authorize_url;
				} else {
					busy($btn, false);
					toast(BP.i18n.generic_error, 'error');
				}
			})
			.catch(function (err) {
				busy($btn, false);
				toast(err.message || BP.i18n.generic_error, 'error');
			});
	}

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

	function handleLoadAccounts($btn, platform) {
		if (!platform) { return; }
		var $card = cardForButton($btn);
		var $sel  = $card.querySelector('[data-role="primary-select"]');
		var $save = $card.querySelector('[data-action="save-primary"]');
		busy($btn, true);
		ajax('brikpanel_ads_list_accounts', { platform: platform })
			.then(function (data) {
				var accounts = (data && data.accounts) || [];
				if (!accounts.length) {
					toast(BP.i18n.no_accounts, 'error');
					busy($btn, false);
					return;
				}
				var current = $sel.value;
				$sel.innerHTML = '';
				accounts.forEach(function (acc) {
					var opt = document.createElement('option');
					if (platform === 'google_ads') {
						opt.value = acc.id;
						opt.textContent = (acc.name || acc.id) +
							(acc.currency ? ' (' + acc.currency + ')' : '') +
							(acc.is_manager ? ' — ' + BP.i18n.manager_suffix : '');
					} else {
						opt.value = acc.id || ('act_' + (acc.account_id || ''));
						opt.textContent = (acc.name || acc.id) +
							(acc.currency ? ' (' + acc.currency + ')' : '');
					}
					if (opt.value === current) { opt.selected = true; }
					$sel.appendChild(opt);
				});
				if ($save) { $save.disabled = !$sel.value; }
				busy($btn, false);
			})
			.catch(function (err) {
				busy($btn, false);
				toast(err.message || BP.i18n.generic_error, 'error');
			});
	}

	function handleSavePrimary($btn, platform) {
		if (!platform) { return; }
		var $card = cardForButton($btn);
		var $sel  = $card.querySelector('[data-role="primary-select"]');
		var accountId = $sel ? $sel.value : '';
		if (!accountId) {
			toast(BP.i18n.pick_account_first, 'error');
			return;
		}
		busy($btn, true);
		ajax('brikpanel_ads_save_primary', { platform: platform, account_id: accountId })
			.then(function (data) {
				toast((data && data.message) || BP.i18n.saved, 'success');
				busy($btn, false);
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
				toast((data && data.message) || BP.i18n.saved, 'success');
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
			html += '<div class="bp-ads-log-entry">'
				+   '<div class="bp-ads-log-meta">'
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
	function fmtMoney(amount, currency) {
		var n = Number(amount) || 0;
		try {
			return new Intl.NumberFormat(undefined, {
				style: 'currency',
				currency: currency || 'USD',
				maximumFractionDigits: 2
			}).format(n);
		} catch (e) {
			return (currency ? currency + ' ' : '') + n.toFixed(2);
		}
	}
	function fmtNum(n) {
		try { return new Intl.NumberFormat().format(Number(n) || 0); }
		catch (e) { return String(Number(n) || 0); }
	}
	function fmtPct(n) {
		return (Number(n) || 0).toFixed(2) + '%';
	}
	function fmtMonth(ym) {
		var parts = String(ym).split('-');
		if (parts.length !== 2) { return ym; }
		var d = new Date(Number(parts[0]), Number(parts[1]) - 1, 1);
		try {
			return new Intl.DateTimeFormat(undefined, { month: 'short', year: 'numeric' }).format(d);
		} catch (e) { return ym; }
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

	function renderPlatformInsight(platform, pd, i18n) {
		var name = platform === 'google_ads'
			? (BP.i18n.platform_google || '')
			: (BP.i18n.platform_meta || '');
		var cur = pd.currency || '';
		var s = pd.summary;
		var d = derive(s.spend, s.impressions, s.clicks);

		var meta = (i18n.account || '').replace('%s', escapeHtml(pd.account_id))
			+ ' · ' + escapeHtml(cur)
			+ ' · ' + (i18n.span || '')
				.replace('%1$s', escapeHtml(s.first_date))
				.replace('%2$s', escapeHtml(s.last_date));

		var kpis = [
			kpiCell(fmtMoney(s.spend, cur), i18n.kpi_spend),
			kpiCell(fmtNum(s.impressions), i18n.kpi_impr),
			kpiCell(fmtNum(s.clicks), i18n.kpi_clicks),
			kpiCell(fmtPct(d.ctr), i18n.kpi_ctr),
			kpiCell(fmtMoney(d.cpc, cur), i18n.kpi_cpc),
			kpiCell(fmtMoney(d.cpm, cur), i18n.kpi_cpm)
		].join('');

		var totalRow = '<tr class="bp-ads-table-total">'
			+ '<td>' + escapeHtml(i18n.total_row || '') + '</td>'
			+ '<td class="num">' + escapeHtml(fmtMoney(s.spend, cur)) + '</td>'
			+ '<td class="num">' + escapeHtml(fmtNum(s.impressions)) + '</td>'
			+ '<td class="num">' + escapeHtml(fmtNum(s.clicks)) + '</td>'
			+ '<td class="num">' + escapeHtml(fmtPct(d.ctr)) + '</td>'
			+ '<td class="num">' + escapeHtml(fmtMoney(d.cpc, cur)) + '</td>'
			+ '</tr>';

		var rows = pd.months.map(function (m) {
			var md = derive(m.spend, m.impressions, m.clicks);
			var rc = m.currency || cur;
			return '<tr>'
				+ '<td>' + escapeHtml(fmtMonth(m.month)) + '</td>'
				+ '<td class="num">' + escapeHtml(fmtMoney(m.spend, rc)) + '</td>'
				+ '<td class="num">' + escapeHtml(fmtNum(m.impressions)) + '</td>'
				+ '<td class="num">' + escapeHtml(fmtNum(m.clicks)) + '</td>'
				+ '<td class="num">' + escapeHtml(fmtPct(md.ctr)) + '</td>'
				+ '<td class="num">' + escapeHtml(fmtMoney(md.cpc, rc)) + '</td>'
				+ '</tr>';
		}).join('');

		return '<div class="bp-ads-insight">'
			+ '<div class="bp-ads-insight-head">'
			+   '<span class="bp-ads-insight-name">' + escapeHtml(name) + '</span>'
			+   '<span class="bp-ads-insight-meta">' + meta + '</span>'
			+ '</div>'
			+ '<div class="bp-ads-kpi-strip">' + kpis + '</div>'
			+ '<div class="bp-ads-table-wrap"><table class="bp-ads-table">'
			+   '<thead><tr>'
			+     '<th>' + escapeHtml(i18n.col_month || '') + '</th>'
			+     '<th class="num">' + escapeHtml(i18n.col_spend || '') + '</th>'
			+     '<th class="num">' + escapeHtml(i18n.col_impr || '') + '</th>'
			+     '<th class="num">' + escapeHtml(i18n.col_clicks || '') + '</th>'
			+     '<th class="num">' + escapeHtml(i18n.col_ctr || '') + '</th>'
			+     '<th class="num">' + escapeHtml(i18n.col_cpc || '') + '</th>'
			+   '</tr></thead>'
			+   '<tbody>' + totalRow + rows + '</tbody>'
			+ '</table></div>'
			+ '</div>';
	}

	function loadInsights($btn) {
		var $card = document.getElementById('bp-ads-insights');
		var $body = document.getElementById('bp-ads-insights-body');
		if (!$card || !$body) { return; }
		var i18n = BP.i18n.insights || {};
		if ($btn) { busy($btn, true); }
		ajax('brikpanel_ads_spend_breakdown', {})
			.then(function (data) {
				var sections = [];
				['google_ads', 'meta_ads'].forEach(function (p) {
					var pd = data && data[p];
					if (!pd || !pd.connected || !pd.summary || !pd.months || !pd.months.length) { return; }
					sections.push(renderPlatformInsight(p, pd, i18n));
				});
				if (!sections.length) {
					$card.hidden = true;
				} else {
					$body.innerHTML = sections.join('');
					$card.hidden = false;
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
						$back.querySelector('.bp-ads-backfill-label').textContent =
							(BP.i18n.backfill_progress || 'Loading history… %1$d of %2$d 90-day chunks done')
								.replace('%1$d', completed)
								.replace('%2$d', total);
					} else if ($back) {
						$back.remove();
					}
				});
			})
			.catch(function () { /* silent */ });
	}

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
