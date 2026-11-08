/**
 * BrikPanel: printable order summary page (front-end/orders/brikpanel-order-print.php).
 *
 * The toolbar's Print button opens the browser's print window, whose "Save as
 * PDF" printer is how a summary is downloaded. Coming from a Print button or
 * the bulk action (print=1 in the address), the window opens by itself once the
 * pictures have loaded, at most 4 seconds later, so none prints as an empty box.
 * The flag is taken out of the address first: a reload, or coming back through
 * the history, shows the page without opening the window again.
 *
 * No text of its own: everything the page says is printed by PHP.
 */
(function () {
	'use strict';

	function printNow() {
		try {
			window.print();
		} catch (e) {
			// A browser without printing (some in-app views): the page stays readable.
		}
	}

	var button = document.querySelector('[data-bp-print]');
	if (button) {
		button.addEventListener('click', printNow);
	}

	if (!document.body || document.body.getAttribute('data-bp-autoprint') !== '1') {
		return;
	}

	try {
		var address = new URL(window.location.href);
		address.searchParams.delete('print');
		window.history.replaceState(window.history.state, '', address.toString());
	} catch (e) {
		// Old browser without URL(): the window may open again on reload, nothing worse.
	}

	var started = false;
	function start() {
		if (started) {
			return;
		}
		started = true;
		// One frame for the last picture to be painted before the print preview is taken.
		window.setTimeout(printNow, 150);
	}

	var waiting = Array.prototype.filter.call(document.images, function (image) {
		return !image.complete;
	});
	if (!waiting.length) {
		start();
		return;
	}

	var left = waiting.length;
	function loaded() {
		left -= 1;
		if (left <= 0) {
			start();
		}
	}
	waiting.forEach(function (image) {
		image.addEventListener('load', loaded, { once: true });
		image.addEventListener('error', loaded, { once: true });
	});
	window.setTimeout(start, 4000);
})();
