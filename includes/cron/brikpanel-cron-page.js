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

	var $table         = el('brikpanel-cron-table');
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

	// ── Fit: table or stacked cards ──────────────────────────────────────────
	// Rows turn into stacked cards when the table cannot show every column
	// inside its card (field test B2: the row buttons were cut off at the card
	// edge). Measured rather than guessed, because the width the table needs
	// depends on the language, the job names and the buttons a row carries.
	// The shared helper (front-end/shared/brikpanel-fit-table.js) measures an
	// invisible copy and watches the width. Below 800px the table would fit
	// but squeeze the job names too hard, so it stacks there regardless.
	var FIT = ($table && window.brikpanelFitTable) ? window.brikpanelFitTable($table, { floor: 800 }) : null;

	// After every render: the rows changed, so they are measured again.
	function fitTable() {
		if (FIT) FIT.refit();
	}

	// Every tbody change goes through here so the fit never lags a render.
	function setBody(html) {
		$tbody.innerHTML = html;
		if ($table) $table.classList.remove('is-loading');
		fitTable();
	}

	function messageRow(text) {
		return '<tr><td colspan="4" class="brikpanel-cron-empty">' + escHtml(text) + '</td></tr>';
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
		var box  = $kpisContainer.querySelector('[data-kpi="' + key + '"]'); // i18n-ignore: selector fragment
		var card = box ? box.querySelector('.brikpanel-cron-kpi-value') : null; // i18n-ignore: selector fragment
		var n    = (value === undefined || value === null) ? 0 : Number(value);
		if (card) card.textContent = (value === undefined || value === null) ? '0' : String(value);
		// Failed/Done take their colour only when they count something.
		if (box) box.classList.toggle('is-nonzero', n > 0);
	}

	// ── List loader ──────────────────────────────────────────────────────────

	function loadList(page) {
		if (state.loading) return;
		state.loading = true;
		state.page    = page || 1;
		// A reload after Run now / Cancel / Retry keeps the rows on screen,
		// dimmed, instead of collapsing the list to one "Loading" row: the
		// collapse threw the page back to the top, far from the row just used.
		if ($tbody.querySelector('tr[data-action-id]')) {
			$table.classList.add('is-loading');
		} else {
			setBody(messageRow(i18n.loading));
		}

		ajax({
			action: 'brikpanel_cron_list',
			page:   state.page,
			status: state.status,
			hook:   state.hook,
		}).then(function (res) {
			state.loading = false;
			if (!res || !res.success) {
				setBody(messageRow((res && res.data && res.data.message) || i18n.error));
				return;
			}
			renderList(res.data || {});
		}).catch(function () {
			state.loading = false;
			setBody(messageRow(i18n.error));
		});
	}

	function renderList(data) {
		var items = data.items || [];
		state.pages = data.pages || 1;
		state.page  = data.page  || 1;

		if (!items.length) {
			setBody(messageRow(i18n.no_jobs));
			$pagination.hidden = true;
			return;
		}

		setBody(items.map(rowHtml).join(''));

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
		// The hook slug may break after its underscores (it has no spaces, and
		// unbroken it forced a ~260px column). Args JSON is left-to-right data,
		// so it is isolated from a right-to-left page with dir="ltr".
		var args = item.args_preview
			? '<div class="brikpanel-cron-args" title="' + escHtml(item.args_preview) + '"><span dir="ltr">' + escHtml(item.args_preview) + '</span></div>'
			: '';

		var jobCell = '<div class="brikpanel-cron-job-cell">'
			+ '<strong>' + escHtml(item.label) + '</strong>'
			+ '<small>' + escHtml(item.hook).replace(/_/g, '_<wbr>') + '</small>'
			+ args
			+ '</div>';

		var statusCell = '<span class="brikpanel-cron-badge" data-tone="' + escHtml(item.status_tone) + '">' + escHtml(item.status_label) + '</span>';

		var whenCell = escHtml(item.scheduled_fmt)
			+ (item.recurring ? '<small class="brikpanel-cron-recurring">' + escHtml(i18n.recurring) + '</small>' : '');

		return '<tr data-action-id="' + item.id + '">'
			+ '<td class="brikpanel-cron-col-job">' + jobCell + '</td>'
			+ '<td class="brikpanel-cron-col-status" data-bp-label="' + escHtml(i18n.col_status) + '">' + statusCell + '</td>'
			+ '<td class="brikpanel-cron-col-when" data-bp-label="' + escHtml(i18n.col_scheduled) + '">' + whenCell + '</td>'
			+ '<td class="brikpanel-cron-col-actions"><div class="brikpanel-cron-row-actions">' + actionsHtml(item) + '</div></td>'
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
		$logBody.innerHTML = '<div class="brikpanel-cron-empty">' + escHtml(i18n.loading) + '</div>';
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

		fitTable();
		loadKpis();
		loadList(1);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
