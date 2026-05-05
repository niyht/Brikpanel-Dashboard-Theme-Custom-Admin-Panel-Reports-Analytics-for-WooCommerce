document.addEventListener('DOMContentLoaded', () => {
	brikpanelSearch.addShortcutKey()

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

	// Alter what gets focused when the modal’s input is focused
	document.querySelector('.brikpanel-search-modal input').addEventListener('focus', brikpanelSearch.handleModalInputFocus);
	document.querySelector('.brikpanel-search-modal input').addEventListener('blur', brikpanelSearch.handleModalInputBlur);
	document.querySelector('.brikpanel-search-modal input').addEventListener('keyup', brikpanelSearch.debounce(brikpanelSearch.search, 250));
});

const brikpanelSearch = {
	/**
	 * Determine whether to show command symbol (Mac) or Ctrl (others) and place
	 * it in the search input.
	 */
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
	openModal: function () {
		document.querySelector('.brikpanel-search-overlay').classList.remove('hidden');
		document.querySelector('.brikpanel-search-modal input').focus();
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
		// If someone clicked on the overlay to close it
		if (event.target.closest('.brikpanel-search-modal') !== null) {
			return;
		}
		document.querySelector('.brikpanel-search-overlay').classList.add('hidden');
	},
	closeModal: function () {
		document.querySelector('.brikpanel-search-overlay').classList.add('hidden');
	},
	handleModalInputFocus: function (event) {
		event.target.parentElement.classList.add('focus');
	},
	handleModalInputBlur: function (event) {
		event.target.parentElement.classList.remove('focus');
	},
	search: async function (event) {
		const query = event.target.value;
		if (query === '') {
			const hintText = (brikpanelSearchAjax.i18n && brikpanelSearchAjax.i18n.hint_text)
				? brikpanelSearchAjax.i18n.hint_text
				: 'You can search orders by customer name, email, phone, order ID, or product SKUs within an order.';
			document.querySelector('.brikpanel-search-modal .result-list').innerHTML = '<p class="hint-text">' + hintText + '</p>';
			return;
		}
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
			if (!response.ok) return;
			document.querySelector('.brikpanel-search-modal .result-list').innerHTML = await response.text();
		} catch (e) {
			console.error('BrikPanel search error:', e);
		}
	},

	debounce: function (func, wait) {
		let timeout;
		// This is the function that is returned and will be executed many times.
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

