/**
 * BrikPanel - Product description image lightbox (front-end)
 *
 * Click-to-enlarge overlay for product-description images that opted in via the
 * editor (class `brikpanel-lightbox`). Dependency-free, built once, lazily.
 */
(function () {
	'use strict';

	var cfg = (typeof brikpanelLightbox !== 'undefined') ? brikpanelLightbox : {};
	var i18n = cfg.i18n || {};
	var overlay = null;
	var overlayImg = null;
	var lastFocus = null;

	function buildOverlay() {
		if (overlay) return;
		overlay = document.createElement('div');
		overlay.className = 'brikpanel-lightbox-overlay';
		overlay.setAttribute('role', 'dialog');
		overlay.setAttribute('aria-modal', 'true');
		overlay.hidden = true;

		var closeLabel = i18n.close || 'Close';
		var btn = document.createElement('button');
		btn.type = 'button';
		btn.className = 'brikpanel-lightbox-close';
		btn.setAttribute('aria-label', closeLabel);
		btn.title = closeLabel;
		btn.innerHTML = '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>';

		overlayImg = document.createElement('img');
		overlayImg.className = 'brikpanel-lightbox-img';
		overlayImg.alt = '';

		overlay.appendChild(btn);
		overlay.appendChild(overlayImg);
		document.body.appendChild(overlay);

		btn.addEventListener('click', close);
		overlay.addEventListener('click', function (e) {
			if (e.target === overlay) close();
		});
		document.addEventListener('keydown', function (e) {
			if (!overlay.hidden && (e.key === 'Escape' || e.key === 'Esc')) close();
		});
	}

	function open(src, alt) {
		buildOverlay();
		lastFocus = document.activeElement;
		overlayImg.src = src;
		overlayImg.alt = alt || '';
		overlay.hidden = false;
		document.documentElement.classList.add('brikpanel-lightbox-open');
		// Defer so the transition runs from the hidden state.
		requestAnimationFrame(function () { overlay.classList.add('is-open'); });
		overlay.querySelector('.brikpanel-lightbox-close').focus();
	}

	function close() {
		if (!overlay) return;
		overlay.classList.remove('is-open');
		document.documentElement.classList.remove('brikpanel-lightbox-open');
		var done = function () {
			overlay.hidden = true;
			overlayImg.removeAttribute('src');
			overlay.removeEventListener('transitionend', done);
		};
		overlay.addEventListener('transitionend', done);
		// Fallback if no transition fires.
		setTimeout(function () { if (overlay && overlay.classList.contains('is-open') === false) done(); }, 350);
		if (lastFocus && lastFocus.focus) { try { lastFocus.focus(); } catch (e) {} }
	}

	document.addEventListener('click', function (e) {
		var img = e.target && e.target.closest ? e.target.closest('img.brikpanel-lightbox') : null;
		if (!img) return;
		// If the editor wrapped the image in a link, let the overlay win.
		e.preventDefault();
		open(img.currentSrc || img.src, img.getAttribute('alt'));
	});
})();
