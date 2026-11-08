/**
 * BrikPanel Customers: one customer's page.
 *
 * The orders table turns its rows into cards when it cannot show every column
 * in its card (CLAUDE.md "Tablo sığma kuralı"); the header row is fitted by
 * the inline call printed with it (brikpanel-fit-row.js).
 */
(function () {
	'use strict';

	const table = document.getElementById('bp-cpr-orders');
	if (table && window.brikpanelFitTable) {
		const fit = window.brikpanelFitTable(table, { labels: 'head' });
		if (fit && typeof fit.refit === 'function') fit.refit();
	}
})();
