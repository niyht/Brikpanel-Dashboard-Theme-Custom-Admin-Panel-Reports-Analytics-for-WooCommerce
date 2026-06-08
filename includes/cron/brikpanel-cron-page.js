/**
 * BrikPanel — Scheduled Tasks page
 * Lists Action Scheduler jobs in the `brikpanel` group with run-now / retry /
 * cancel / view-log controls. All requests go through admin-ajax.php.
 */
(function () {
	'use strict';

	var cfg  = window.brikpanelCron || {};
	var i18n = cfg.i18n || {};

	// ── State ────────────────────────────────────────────────────────────────
	var state = {
		page:    1,
		pages:   1,
		status:  '',
		hook:    '',
		loading: false,
	};

	// ── DOM refs ─────────────────────────────────────────────────────────────
	function el(id) { return document.getElementById(id); }

	var $tbody         = el('brikpanel-cron-tbody');
	var $statusFilter  = el('brikpanel-cron-status-filter');
	var $hookFilter    = el('brikpanel-cron-hook-filter');
	var $applyBtn      = el('brikpanel-cron-apply-btn');
	var $refreshBtn    = el('brikpanel-cron-refresh-btn');
	var $pagination    = el('brikpanel-cron-pagination');
	var $pageInfo      = el('brikpanel-cron-page-info');
	var $prev          = el('brikpanel-cron-prev');
	var $next          = el('brikpanel-cron-next');
	var $logOverlay    = el('brikpanel-cron-log-overlay');
	var $logBody       = el('brikpanel-cron-log-body');
	var $logClose      = el('brikpanel-cron-log-close');
	var $toast         = el('brikpanel-cron-toast');
	var $kpisContainer = el('brikpanel-cron-kpis');

	// ── Helpers ──────────────────────────────────────────────────────────────

	function escHtml(str) {
		return String(str == null ? '' : str)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;')
			.replace(/'/g, '&#39;');
	}

	function showToast(msg, tone) {
		if (!$toast) return;
		$toast.textContent = msg;
		$toast.dataset.tone = tone || 'success';
		$toast.hidden = false;
		// reflow
		void $toast.offsetHeight;
		$toast.classList.add('is-visible');
		setTimeout(function () {
			$toast.classList.remove('is-visible');
			setTimeout(function () { $toast.hidden = true; }, 350);
		}, 3500);
	}

	function ajax(data) {
		data._ajax_nonce = cfg.nonce;
		var body = new URLSearchParams(data).toString();
		return fetch(cfg.ajax_url, {
			method:  'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body:    body,
		}).then(function (r) { return r.json(); });
	}

	// ── KPI loader ───────────────────────────────────────────────────────────

	function loadKpis() {
		ajax({ action: 'brikpanel_cron_kpis' })
			.then(function (res) {
				if (!res || !res.success) return;
				var data = res.data || {};
				updateKpi('pending',  data.pending);
				updateKpi('running',  data.running);
				updateKpi('failed',   data.failed);
				updateKpi('complete', data.complete);
			})
			.catch(function () { /* silent */ });
	}

	function updateKpi(key, value) {
		if (!$kpisContainer) return;
		var card = $kpisContainer.querySelector('[data-kpi="' + key + '"] .brikpanel-cron-kpi-value'); // i18n-ignore: selector fragment
		if (card) card.textContent = (value === undefined || value === null) ? '0' : String(value);
	}

	// ── List loader ──────────────────────────────────────────────────────────

	function loadList(page) {
		if (state.loading) return;
		state.loading = true;
		state.page    = page || 1;
		$tbody.innerHTML = '<tr><td colspan="6" class="brikpanel-cron-empty">' + escHtml(i18n.loading || 'Loading…') + '</td></tr>';

		ajax({
			action: 'brikpanel_cron_list',
			page:   state.page,
			status: state.status,
			hook:   state.hook,
		}).then(function (res) {
			state.loading = false;
			if (!res || !res.success) {
				$tbody.innerHTML = '<tr><td colspan="6" class="brikpanel-cron-empty">' + escHtml((res && res.data && res.data.message) || i18n.error) + '</td></tr>';
				return;
			}
			renderList(res.data || {});
		}).catch(function () {
			state.loading = false;
			$tbody.innerHTML = '<tr><td colspan="6" class="brikpanel-cron-empty">' + escHtml(i18n.error) + '</td></tr>';
		});
	}

	function renderList(data) {
		var items = data.items || [];
		state.pages = data.pages || 1;
		state.page  = data.page  || 1;

		if (!items.length) {
			$tbody.innerHTML = '<tr><td colspan="6" class="brikpanel-cron-empty">' + escHtml(i18n.no_jobs) + '</td></tr>';
			$pagination.hidden = true;
			return;
		}

		var rows = items.map(function (item) {
			return rowHtml(item);
		}).join('');
		$tbody.innerHTML = rows;

		if (state.pages > 1) {
			$pagination.hidden = false;
			$pageInfo.textContent = state.page + ' / ' + state.pages;
			$prev.disabled = state.page <= 1;
			$next.disabled = state.page >= state.pages;
		} else {
			$pagination.hidden = true;
		}
	}

	function rowHtml(item) {
		var hookCell  = '<div class="brikpanel-cron-job-cell">'
			+ '<strong>' + escHtml(item.label) + '</strong>'
			+ '<small>' + escHtml(item.hook) + '</small>'
			+ '</div>';

		var statusCell = '<span class="brikpanel-cron-badge" data-tone="' + escHtml(item.status_tone) + '">' + escHtml(item.status_label) + '</span>';

		var recurring  = item.recurring
			? '<span class="brikpanel-cron-recurring-yes">' + escHtml(i18n.recurring_yes) + '</span>'
			: '<span class="brikpanel-cron-recurring-no">' + escHtml(i18n.recurring_no) + '</span>';

		var args = item.args_preview === '—' ? '—' : '<span class="brikpanel-cron-args" title="' + escHtml(item.args_preview) + '">' + escHtml(item.args_preview) + '</span>';

		var actions = actionsHtml(item);

		return '<tr data-action-id="' + item.id + '">'
			+ '<td>' + hookCell + '</td>'
			+ '<td>' + statusCell + '</td>'
			+ '<td>' + escHtml(item.scheduled_fmt) + '</td>'
			+ '<td>' + recurring + '</td>'
			+ '<td>' + args + '</td>'
			+ '<td><div class="brikpanel-cron-row-actions">' + actions + '</div></td>'
			+ '</tr>';
	}

	function actionsHtml(item) {
		var buttons = [];

		if (item.status === 'pending' || item.status === 'in-progress') {
			buttons.push('<button type="button" class="brikpanel-cron-btn brikpanel-cron-btn-secondary brikpanel-cron-btn-icon" data-act="run">' + escHtml(i18n.run_now) + '</button>');
			buttons.push('<button type="button" class="brikpanel-cron-btn brikpanel-cron-btn-secondary brikpanel-cron-btn-icon brikpanel-cron-btn-danger" data-act="cancel">' + escHtml(i18n.cancel) + '</button>');
		} else if (item.status === 'failed' || item.status === 'canceled' || item.status === 'cancelled') {
			buttons.push('<button type="button" class="brikpanel-cron-btn brikpanel-cron-btn-secondary brikpanel-cron-btn-icon" data-act="retry">' + escHtml(i18n.retry) + '</button>');
		}
		// Logs available for any status.
		buttons.push('<button type="button" class="brikpanel-cron-btn brikpanel-cron-btn-secondary brikpanel-cron-btn-icon" data-act="logs">' + escHtml(i18n.view_logs) + '</button>');
		return buttons.join('');
	}

	// ── Row action handlers ──────────────────────────────────────────────────

	function onTableClick(e) {
		var btn = e.target.closest('button[data-act]');
		if (!btn) return;
		var row = btn.closest('tr[data-action-id]');
		if (!row) return;
		var id  = row.getAttribute('data-action-id');
		var act = btn.getAttribute('data-act');
		btn.disabled = true;

		if (act === 'run') {
			if (!window.confirm(i18n.confirm_run)) { btn.disabled = false; return; }
			ajax({ action: 'brikpanel_cron_run_now', action_id: id })
				.then(function (res) {
					if (res && res.success) {
						showToast(i18n.done_running, 'success');
						refresh();
					} else {
						showToast((res && res.data && res.data.message) || i18n.error, 'error');
						btn.disabled = false;
					}
				})
				.catch(function () { showToast(i18n.error, 'error'); btn.disabled = false; });
		} else if (act === 'retry') {
			ajax({ action: 'brikpanel_cron_retry', action_id: id })
				.then(function (res) {
					if (res && res.success) {
						showToast(i18n.done_retried, 'success');
						refresh();
					} else {
						showToast((res && res.data && res.data.message) || i18n.error, 'error');
						btn.disabled = false;
					}
				})
				.catch(function () { showToast(i18n.error, 'error'); btn.disabled = false; });
		} else if (act === 'cancel') {
			if (!window.confirm(i18n.confirm_cancel)) { btn.disabled = false; return; }
			ajax({ action: 'brikpanel_cron_cancel', action_id: id })
				.then(function (res) {
					if (res && res.success) {
						showToast(i18n.done_cancelled, 'success');
						refresh();
					} else {
						showToast((res && res.data && res.data.message) || i18n.error, 'error');
						btn.disabled = false;
					}
				})
				.catch(function () { showToast(i18n.error, 'error'); btn.disabled = false; });
		} else if (act === 'logs') {
			openLogs(id);
			btn.disabled = false;
		}
	}

	function openLogs(id) {
		$logBody.innerHTML = '<div class="brikpanel-cron-empty">' + escHtml(i18n.loading || 'Loading…') + '</div>';
		$logOverlay.hidden = false;

		ajax({ action: 'brikpanel_cron_logs', action_id: id })
			.then(function (res) {
				if (!res || !res.success) {
					$logBody.innerHTML = '<div class="brikpanel-cron-empty">' + escHtml((res && res.data && res.data.message) || i18n.error) + '</div>';
					return;
				}
				var entries = (res.data && res.data.entries) || [];
				if (!entries.length) {
					$logBody.innerHTML = '<div class="brikpanel-cron-empty">' + escHtml(i18n.no_logs) + '</div>';
					return;
				}
				$logBody.innerHTML = entries.map(function (e) {
					return '<div class="brikpanel-cron-log-entry">'
						+ '<div class="brikpanel-cron-log-date">' + escHtml(e.date) + '</div>'
						+ '<div class="brikpanel-cron-log-message">' + escHtml(e.message) + '</div>'
						+ '</div>';
				}).join('');
			})
			.catch(function () {
				$logBody.innerHTML = '<div class="brikpanel-cron-empty">' + escHtml(i18n.error) + '</div>';
			});
	}

	function closeLogs() { $logOverlay.hidden = true; }

	function refresh() {
		loadKpis();
		loadList(state.page);
	}

	// ── Wire events ──────────────────────────────────────────────────────────

	function init() {
		if (!$tbody) return;

		$applyBtn && $applyBtn.addEventListener('click', function () {
			state.status = $statusFilter.value;
			state.hook   = $hookFilter.value;
			loadList(1);
			loadKpis();
		});

		$refreshBtn && $refreshBtn.addEventListener('click', refresh);

		$prev && $prev.addEventListener('click', function () { if (state.page > 1) loadList(state.page - 1); });
		$next && $next.addEventListener('click', function () { if (state.page < state.pages) loadList(state.page + 1); });

		$tbody.addEventListener('click', onTableClick);

		$logClose && $logClose.addEventListener('click', closeLogs);
		$logOverlay && $logOverlay.addEventListener('click', function (e) {
			if (e.target === $logOverlay) closeLogs();
		});
		document.addEventListener('keydown', function (e) {
			if (e.key === 'Escape' && !$logOverlay.hidden) closeLogs();
		});

		loadKpis();
		loadList(1);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
