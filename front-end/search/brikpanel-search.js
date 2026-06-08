document.addEventListener('DOMContentLoaded', () => {
	brikpanelSearch.addShortcutKey();

	// Cache the initial palette body (hint + recent orders) so we can
	// restore it instantly when the query is cleared, without a round trip.
	const results = document.querySelector('.brikpanel-search-modal .results');
	if (results) {
		brikpanelSearch.initialHTML = results.innerHTML;
	}

	// Opening and closing the modal through clicking
	document.querySelector('.brikpanel-search-menu-item').addEventListener('click', brikpanelSearch.openModal);
	document.querySelector('.brikpanel-search-menu-item-mobile').addEventListener('click', brikpanelSearch.openModal);
	document.querySelector('.brikpanel-search-overlay').addEventListener('click', brikpanelSearch.handleOverlayClick);

	// Opening and closing the modal with keyboard.
	// Ctrl/Cmd+K is registered in the capture phase so we run before any
	// bubble-phase listeners — notably WordPress core's Command Palette
	// (`commands-command-menu`), which also binds Ctrl/Cmd+K. Without
	// this, both modals open simultaneously and the WP one stays behind
	// our overlay; closing ours via Escape then reveals an empty WP
	// search modal underneath.
	document.addEventListener('keydown', brikpanelSearch.handleOpenShortcut, true);
	document.addEventListener('keydown', brikpanelSearch.handleEscapeKey);
	// Arrow/Enter navigation is bound on document in the capture phase (like
	// the open shortcut) so it keeps working even though the BrikPanel top
	// bar relocates the overlay out of #wpadminbar into <body>, and so no
	// other capture-phase listener can swallow the arrow keys first.
	document.addEventListener('keydown', brikpanelSearch.handleListNavigation, true);

	const input = document.querySelector('.brikpanel-search-modal input');
	input.addEventListener('focus', brikpanelSearch.handleModalInputFocus);
	input.addEventListener('blur', brikpanelSearch.handleModalInputBlur);
	input.addEventListener('keyup', brikpanelSearch.debounce(brikpanelSearch.search, 220));
});

const brikpanelSearch = {
	initialHTML: '',
	activeIndex: -1,
	// Last query the results actually reflect. The search runs on the
	// input's keyup, which also fires for Arrow/Enter/Esc/modifier keys —
	// re-rendering on those would wipe the keyboard selection on every
	// arrow press. We only refresh when the typed text truly changed.
	lastQuery: '',
	// Monotonic token: a slower earlier request can resolve after a newer
	// one, so each fetch tags its token and stale responses are dropped.
	reqToken: 0,

	addShortcutKey: function () {
		const shortcutKey = document.getElementById('shortcut-key');

		function isMacOS() {
			if (navigator.userAgentData) {
				return navigator.userAgentData.platform === "macOS";
			} else {
				return /Mac|iPod|iPhone|iPad/.test(navigator.userAgent);
			}
		}

		if (isMacOS()) {
			shortcutKey.innerHTML = '&#8984;'; // Command symbol (⌘)
		} else {
			shortcutKey.innerHTML = 'Ctrl';
		}
	},

	getOverlay: function () {
		return document.querySelector('.brikpanel-search-overlay');
	},

	openModal: function () {
		const overlay = brikpanelSearch.getOverlay();
		clearTimeout(brikpanelSearch._closeTimer);
		// Visibility is driven purely by `.hidden`; the entrance animation
		// is a CSS @keyframes that auto-plays on display. This keeps the
		// palette working no matter who opens it — Ctrl+K, the admin-bar
		// item, or the top bar button (which only toggles `.hidden`).
		overlay.classList.remove('hidden', 'is-closing');
		document.querySelector('.brikpanel-search-modal input').focus();
	},

	closeModal: function () {
		const overlay = brikpanelSearch.getOverlay();
		if (overlay.classList.contains('hidden')) {
			return;
		}
		overlay.classList.add('is-closing');
		clearTimeout(brikpanelSearch._closeTimer);
		brikpanelSearch._closeTimer = setTimeout(() => {
			overlay.classList.add('hidden');
			overlay.classList.remove('is-closing');
		}, 170);
	},

	handleOpenShortcut: function (event) {
		if ((event.metaKey || event.ctrlKey) && event.key === 'k') {
			event.preventDefault();
			event.stopPropagation();
			event.stopImmediatePropagation();
			brikpanelSearch.openModal();
		}
	},

	handleEscapeKey: function (event) {
		if (event.key === 'Escape') {
			brikpanelSearch.closeModal();
		}
	},

	handleOverlayClick: function (event) {
		// Ignore clicks that land inside the modal itself.
		if (event.target.closest('.brikpanel-search-modal') !== null) {
			return;
		}
		brikpanelSearch.closeModal();
	},

	handleModalInputFocus: function (event) {
		event.target.parentElement.classList.add('focus');
	},

	handleModalInputBlur: function (event) {
		event.target.parentElement.classList.remove('focus');
	},

	/**
	 * Every result is an <a>. Collect them in DOM order for keyboard nav.
	 */
	getResultLinks: function () {
		return Array.from(document.querySelectorAll('.brikpanel-search-modal .results li a'));
	},

	setActive: function (index) {
		const links = brikpanelSearch.getResultLinks();
		links.forEach((a) => a.closest('li').classList.remove('is-active'));
		if (links.length === 0) {
			brikpanelSearch.activeIndex = -1;
			return;
		}
		brikpanelSearch.activeIndex = (index + links.length) % links.length;
		const li = links[brikpanelSearch.activeIndex].closest('li');
		li.classList.add('is-active');
		li.scrollIntoView({ block: 'nearest' });
	},

	handleListNavigation: function (event) {
		// Only act while the palette is open, otherwise arrow/Enter keys
		// would be hijacked everywhere in wp-admin.
		const overlay = document.querySelector('.brikpanel-search-overlay');
		if (!overlay || overlay.classList.contains('hidden')) {
			return;
		}
		if (event.key !== 'ArrowDown' && event.key !== 'ArrowUp' && event.key !== 'Enter') {
			return;
		}
		if (event.key === 'ArrowDown') {
			event.preventDefault();
			brikpanelSearch.setActive(brikpanelSearch.activeIndex + 1);
		} else if (event.key === 'ArrowUp') {
			event.preventDefault();
			brikpanelSearch.setActive(brikpanelSearch.activeIndex - 1);
		} else if (event.key === 'Enter') {
			const links = brikpanelSearch.getResultLinks();
			const target = links[brikpanelSearch.activeIndex] || links[0];
			if (target) {
				event.preventDefault();
				window.location.href = target.href;
			}
		}
	},

	renderResults: function (html) {
		const results = document.querySelector('.brikpanel-search-modal .results');
		if (!results) return;
		results.innerHTML = html;
		brikpanelSearch.activeIndex = -1;
	},

	/**
	 * Lightweight shimmer placeholder shown while a query is in flight, so
	 * the palette feels responsive instead of frozen on slow stores.
	 */
	renderLoading: function () {
		const row = '<div class="bp-skel-row lg"></div><div class="bp-skel-row sm"></div>';
		brikpanelSearch.renderResults(
			'<div class="bp-skel">' + row + row + row + '</div>'
		);
	},

	search: async function (event) {
		const query = event.target.value.trim();

		// The typed text did not change (Arrow/Enter/Esc/Shift/Ctrl, etc.):
		// keep the current results and selection, do nothing.
		if (query === brikpanelSearch.lastQuery) {
			return;
		}
		brikpanelSearch.lastQuery = query;

		if (query === '') {
			brikpanelSearch.reqToken++;
			brikpanelSearch.renderResults(brikpanelSearch.initialHTML);
			return;
		}

		const token = ++brikpanelSearch.reqToken;
		brikpanelSearch.renderLoading();

		try {
			const fd = new FormData();
			fd.append('action', 'brikpanel_search');
			fd.append('query', query);
			fd.append('security', brikpanelSearchAjax.nonce);
			const response = await fetch(brikpanelSearchAjax.ajax_url, {
				method: 'POST',
				credentials: 'same-origin',
				body: fd,
			});
			// Drop stale responses (a newer keystroke already superseded us).
			if (token !== brikpanelSearch.reqToken) return;
			if (!response.ok) return;
			brikpanelSearch.renderResults(await response.text());
		} catch (e) {
			if (token === brikpanelSearch.reqToken) {
				console.error('BrikPanel search error:', e);
			}
		}
	},

	debounce: function (func, wait) {
		let timeout;
		return function executedFunction(...args) {
			const later = () => {
				clearTimeout(timeout);
				func(...args);
			};
			clearTimeout(timeout);
			timeout = setTimeout(later, wait);
		};
	},
}
