/**
 * BrikPanel: "Print order summary" from the orders list opens in a new tab
 * (front-end/orders/brikpanel-order-print.php).
 *
 * Both looks of the list submit the same form: WooCommerce's own Apply buttons
 * and BrikPanel's bulk bar, which sets the two action menus and clicks the
 * bottom Apply (brikpanelBulkActions() in brikpanel-orders.js). When the chosen
 * action is ours, that one submission goes to a new tab, so the list keeps its
 * page, filters and ticks. Both menus then return to "Bulk actions": otherwise
 * pressing Enter in the search box later would submit the print action again.
 *
 * No text of its own.
 */
(function () {
	'use strict';

	var ACTION = 'brikpanel_print_orders';
	var form = document.getElementById('wc-orders-filter') || document.getElementById('posts-filter');
	if (!form) {
		return;
	}

	// The menu that belongs to the Apply button that sent the form.
	function chosenAction(submitter) {
		var id = submitter && submitter.id;
		var menu = null;
		if (id === 'doaction2') {
			menu = document.getElementById('bulk-action-selector-bottom');
		} else if (id === 'doaction') {
			menu = document.getElementById('bulk-action-selector-top');
		}
		return menu ? menu.value : '';
	}

	form.addEventListener('submit', function (event) {
		if (event.defaultPrevented || chosenAction(event.submitter) !== ACTION) {
			return;
		}
		form.setAttribute('target', '_blank');
		window.setTimeout(function () {
			form.removeAttribute('target');
			['bulk-action-selector-top', 'bulk-action-selector-bottom'].forEach(function (id) {
				var menu = document.getElementById(id);
				if (menu) {
					menu.value = '-1';
				}
			});
		}, 0);
	});
})();
