/**
 * BrikPanel: pop-up scroll locks on the screens whose <html> clips.
 *
 * A pop-up stops the page behind it from scrolling with `body { overflow:
 * hidden }`, set inline (Advanced Shipment Tracking's tracking panel on the
 * orders list) or through a class (`body.modal-open` of Thickbox and the media
 * library, the products list's own drawer). That holds the page still only
 * while <html> does not clip: the browser then hands body's overflow to the
 * window. The orders and products lists clip <html> sideways (`overflow-x:
 * clip`, so a stray wide element cannot push the page sideways on a phone),
 * and there the lock clipped <body> to the height of the window instead: the
 * page jumped to the top and stayed there after the pop-up closed.
 *
 * While a pop-up holds such a lock, <html> stops clipping
 * (html.brikpanel-scroll-held in brikpanel-scroll-lock.css), so the lock
 * reaches the window again, and the scroll position is put back in case the
 * page had already jumped. Where <body> itself is the scrolling box (phones),
 * the lock already holds the page and nothing is changed.
 */
(function () {
	'use strict';

	function start() {
		var html = document.documentElement;
		var body = document.body;
		if (!body || !('MutationObserver' in window)) return;

		var held = false;
		// Body's overflow and own inline overflow while no pop-up holds it.
		var freeY = window.getComputedStyle(body).overflowY;
		var freeInline = body.style.overflow;
		var lastX = window.scrollX;
		var lastY = window.scrollY;

		window.addEventListener('scroll', function () {
			if (held) return;
			lastX = window.scrollX;
			lastY = window.scrollY;
		}, { passive: true });

		function clips(value) {
			return value === 'hidden' || value === 'clip';
		}

		function sync() {
			var locked = clips(window.getComputedStyle(body).overflowY);
			if (!locked) {
				if (held) {
					held = false;
					html.classList.remove('brikpanel-scroll-held');
					// WooCommerce's modals leave `overflow: auto` behind, which would
					// make <body> the scrolling box under a clipping <html>.
					if (!freeInline && /^(auto|scroll)$/.test(body.style.overflow)) body.style.overflow = '';
					window.scrollTo(lastX, lastY);
				}
				freeY = window.getComputedStyle(body).overflowY;
				freeInline = body.style.overflow;
				return;
			}
			if (held || freeY !== 'visible') return;
			var hs = window.getComputedStyle(html);
			// Nothing to do while <html> does not clip, or while another lock
			// already holds <html> itself.
			if ((hs.overflowX === 'visible' && hs.overflowY === 'visible') || clips(hs.overflowY)) return;
			held = true;
			html.classList.add('brikpanel-scroll-held');
			window.scrollTo(lastX, lastY);
		}

		new MutationObserver(sync).observe(body, { attributes: true, attributeFilter: ['class', 'style'] });
		window.addEventListener('resize', sync);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', start);
	} else {
		start();
	}
})();
