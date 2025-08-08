document.addEventListener('DOMContentLoaded', () => {
	brik82adSearch.addShortcutKey()

	// Opening and closing the modal through clicking
	document.querySelector('.brik82ad-search-menu-item').addEventListener('click', brik82adSearch.openModal);
	document.querySelector('.brik82ad-search-menu-item-mobile').addEventListener('click', brik82adSearch.openModal);
	document.querySelector('.brik82ad-search-overlay').addEventListener('click', brik82adSearch.handleOverlayClick);

	// Opening and closing the modal with keyboard
	document.addEventListener('keydown', brik82adSearch.handleOpenShortcut);
	document.addEventListener('keydown', brik82adSearch.handleEscapeKey);

	// Alter what gets focused when the modal’s input is focused
	document.querySelector('.brik82ad-search-modal input').addEventListener('focus', brik82adSearch.handleModalInputFocus);
	document.querySelector('.brik82ad-search-modal input').addEventListener('blur', brik82adSearch.handleModalInputBlur);
	document.querySelector('.brik82ad-search-modal input').addEventListener('keyup', brik82adSearch.debounce(brik82adSearch.search, 250));
});

const brik82adSearch = {
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
		document.querySelector('.brik82ad-search-overlay').classList.remove('hidden');
		document.querySelector('.brik82ad-search-modal input').focus();
	},
	handleOpenShortcut: function (event) {
		if ((event.metaKey || event.ctrlKey) && event.key === 'k') {
			event.preventDefault();
			brik82adSearch.openModal();
		}
	},
	handleEscapeKey: function (event) {
		if (event.key === 'Escape') {
			brik82adSearch.closeModal();
		}
	},
	handleOverlayClick: function (event) {
		// If someone clicked on the overlay to close it
		if (event.target.closest('.brik82ad-search-modal') !== null) {
			return;
		}
		document.querySelector('.brik82ad-search-overlay').classList.add('hidden');
	},
	closeModal: function () {
		document.querySelector('.brik82ad-search-overlay').classList.add('hidden');
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
			document.querySelector('.brik82ad-search-modal .result-list').innerHTML = '<p class="hint-text">You can search orders by customer name, email, phone, order ID, or product SKUs within an order.</p>';
			return;
		}
		const url = brik82adSearchData.ajax_url;
		const params = new URLSearchParams({
			action: 'brik82ad_search',
			query: event.target.value,
			security: brik82adSearchData.nonce
		});
		const response = await fetch(`${url}?${params.toString()}`);
		document.querySelector('.brik82ad-search-modal .result-list').innerHTML = await response.text();
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

