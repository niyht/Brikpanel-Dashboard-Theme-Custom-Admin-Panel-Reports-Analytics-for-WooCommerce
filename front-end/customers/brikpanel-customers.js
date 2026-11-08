/**
 * BrikPanel Customers page: the tab strip.
 *
 * The page opens on the tab the server drew (?tab=); switching tabs updates
 * the address (Back returns to the previous tab) and wakes the tab's own
 * script the first time: the Segments list for All customers
 * (window.brikpanelSegmentsApi), the analytics panels for the other three
 * (window.brikpanelCustomerAnalytics). "Export CSV" downloads the open tab.
 */
(function () {
	'use strict';

	const ROOT = document.getElementById('bp-cus');
	if (!ROOT) return;

	const TABS = ['all', 'rfm', 'ltv', 'cohort'];
	const strip = ROOT.querySelector('.bp-cus-tabs');
	let current = TABS.indexOf(ROOT.getAttribute('data-tab')) !== -1 ? ROOT.getAttribute('data-tab') : 'all';

	function tabFromUrl() {
		const tab = new URL(window.location.href).searchParams.get('tab') || 'all';
		return TABS.indexOf(tab) !== -1 ? tab : 'all';
	}

	// The address of a tab. The list's own entry arguments (the bell's preset,
	// an RFM segment) belong to the first view only and are dropped.
	function urlFor(tab) {
		const url = new URL(window.location.href);
		if (tab === 'all') url.searchParams.delete('tab'); else url.searchParams.set('tab', tab);
		url.searchParams.delete('preset');
		url.searchParams.delete('rfm');
		return url.toString();
	}

	function show(tab, push) {
		if (TABS.indexOf(tab) === -1 || !ROOT.querySelector('.bp-cus-tab[data-tab="' + tab + '"]')) tab = 'all';
		current = tab;
		ROOT.setAttribute('data-tab', tab);
		ROOT.querySelectorAll('.bp-cus-tab').forEach(function (a) {
			const on = a.getAttribute('data-tab') === tab;
			a.classList.toggle('is-active', on);
			a.setAttribute('aria-selected', on ? 'true' : 'false');
		});
		const list = document.getElementById('bp-cus-panel-all');
		if (list) list.hidden = tab !== 'all';
		const analytics = document.getElementById('bp-ca');
		if (analytics) analytics.hidden = tab === 'all';

		if (tab === 'all') {
			if (window.brikpanelSegmentsApi) window.brikpanelSegmentsApi.start();
		} else if (window.brikpanelCustomerAnalytics) {
			window.brikpanelCustomerAnalytics.show(tab);
		}
		if (push) window.history.pushState({ bpCustomersTab: tab }, '', urlFor(tab));
		// The BrikMentor corner button follows the tab with its copy.
		document.dispatchEvent(new CustomEvent('brikpanel:customers-tab', { detail: { tab: tab } }));
	}

	if (strip) {
		strip.addEventListener('click', function (e) {
			const link = e.target.closest('.bp-cus-tab');
			if (!link) return;
			// A new tab or window keeps the browser's own behaviour.
			if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || e.button !== 0) return;
			e.preventDefault();
			if (link.getAttribute('data-tab') !== current) show(link.getAttribute('data-tab'), true);
		});
	}

	window.addEventListener('popstate', function () {
		const tab = tabFromUrl();
		if (tab !== current) show(tab, false);
	});

	const exportBtn = document.getElementById('bp-cus-export');
	if (exportBtn) {
		exportBtn.addEventListener('click', function () {
			if (current === 'all') {
				if (window.brikpanelSegmentsApi) window.brikpanelSegmentsApi.exportCsv();
				return;
			}
			const ca = window.brikpanelCA || {};
			const url = current === 'ltv' ? ca.export_url : (current === 'rfm' ? ca.rfm_export_url : ca.cohort_export_url);
			if (url) window.location.href = url;
		});
	}

	window.brikpanelCustomers = {
		// A click on an RFM card: All customers, filtered to that segment.
		openSegment: function (segment) {
			show('all', true);
			if (window.brikpanelSegmentsApi) window.brikpanelSegmentsApi.openSegment(segment);
			const list = document.getElementById('bp-cus-panel-all');
			if (list && typeof list.scrollIntoView === 'function') list.scrollIntoView({ block: 'start' });
		},
		show: function (tab) { show(tab, true); },
	};
})();
