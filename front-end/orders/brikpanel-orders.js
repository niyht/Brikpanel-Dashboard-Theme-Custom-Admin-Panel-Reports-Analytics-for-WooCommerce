var _ordersI18n = (window.brikpanelOrdersOverview && window.brikpanelOrdersOverview.i18n) || {};

function makeElement(tagName, attributes = {}, properties = {}, listeners = []) {
	const $element = document.createElement(tagName);
	Object.entries(attributes).forEach(([key, value]) => {
		$element.setAttribute(key, value);
	});
	Object.entries(properties).forEach(([key, value]) => {
		$element[key] = value;
	});
	listeners.forEach(({ event, handler }) => {
		$element.addEventListener(event, handler);
	});
	return $element;
}

document.addEventListener('DOMContentLoaded', () => {
	brikpanelOrderTableFilters();
});

function brikpanelOrderTableFilters() {
	// Date filter
	brikpanelFilterCustomDropdown('date', 'date', '#filter-by-date', '[selected="selected"]:not([value="0"])');

	// Order Tags by 99w
	brikpanelFilterCustomDropdown('order-tags-99w', (_ordersI18n.filter_order_tag || 'order tag'), 'select[name="wcot_order_tags_filter"]', '[selected=""]:not([value=""])');

	// Customer filter
	brikpanelFilterSelect2SubmitEvent('.wc-customer-search');

	// Custom dropdowns are positioned under their chip on open
	// (see brikpanelAdjustCustomDropdownPosition), so the layout stays
	// correct after the filter row wraps on mobile/tablet.

	// Clear filters button
	brikpanelClearFilters(['#filter-by-date', 'select[name="wcot_order_tags_filter"]', '.wc-customer-search', '#dropdown_shop_order_subtype']);
}

document.addEventListener('DOMContentLoaded', () => {
	try {
		brikpanelTableScroll();
		brikpanelPageHeader();
		brikpanelOrdersOverviewSection();
		brikpanelTableHeader();
		brikpanelOrderSearch();
		brikpanelFilters();
		brikpanelBulkActions(getBulkActions());
		brikpanelPagination();
		brikpanelOrderNumberNamePreview();
		brikpanelOrderStatus();
		brikpanelFooter();
		brikpanelEmptyTrashButton();
		brikpanelAddFiltersToOrderLinks();
		brikpanelListTableSearchResultCount();
	} finally {
		showBrikpanel();
	}
});

/**
 * Orders overview section: 30-day summary + marketplace stats (if BrikMarket active).
 */
function brikpanelOrdersOverviewSection() {
	if (typeof brikpanelOrdersOverview === 'undefined') return;

	const $wrapper = makeElement('div', { class: 'brikpanel-overview' });

	// Skeleton / loading state
	const $summaryCard = makeElement('div', { class: 'brikpanel-overview-summary' });
	$summaryCard.innerHTML = `
		<div class="brikpanel-overview-title">
			<span class="brikpanel-overview-dot"></span>
			<span class="brikpanel-overview-title-text"></span>
		</div>
		<div class="brikpanel-overview-stats">
			<div class="brikpanel-stat skeleton"><span class="brikpanel-stat-value">—</span><span class="brikpanel-stat-label">&nbsp;</span></div>
			<div class="brikpanel-stat skeleton"><span class="brikpanel-stat-value">—</span><span class="brikpanel-stat-label">&nbsp;</span></div>
			<div class="brikpanel-stat skeleton"><span class="brikpanel-stat-value">—</span><span class="brikpanel-stat-label">&nbsp;</span></div>
			<div class="brikpanel-stat skeleton"><span class="brikpanel-stat-value">—</span><span class="brikpanel-stat-label">&nbsp;</span></div>
			<div class="brikpanel-stat skeleton"><span class="brikpanel-stat-value">—</span><span class="brikpanel-stat-label">&nbsp;</span></div>
		</div>
	`;
	$wrapper.append($summaryCard);

	// Insert after page header
	const $pageTop = document.querySelector('.brikpanel-page-top');
	if ($pageTop) {
		$pageTop.insertAdjacentElement('afterend', $wrapper);
	}

	// Fetch data
	const body = new FormData();
	body.append('action', 'brikpanel_orders_overview');
	body.append('_ajax_nonce', brikpanelOrdersOverview.nonce);

	fetch(brikpanelOrdersOverview.ajax_url, { method: 'POST', body })
		.then(r => r.json())
		.then(response => {
			if (!response.success) return;
			const { summary, marketplaces } = response.data;

			// Populate 30-day summary
			const i18n = brikpanelOrdersOverview.i18n;
			$summaryCard.innerHTML = '';

			const $title = makeElement('div', { class: 'brikpanel-overview-title' });
			$title.innerHTML = `<span class="brikpanel-overview-dot"></span><span class="brikpanel-overview-title-text">${i18n.last_30_days}</span>`;
			$summaryCard.append($title);

			const $stats = makeElement('div', { class: 'brikpanel-overview-stats' });

			const stats = [
				{ value: summary.total, label: i18n.orders, cls: '' },
				{ value: summary.completed, label: i18n.completed, cls: 'completed' },
				{ value: summary.refunded, label: i18n.refunded, cls: 'refunded' },
				{ value: summary.cancelled, label: i18n.cancelled, cls: 'cancelled' },
				{ value: summary.revenue_formatted, label: i18n.revenue, cls: 'revenue' },
			];

			stats.forEach(s => {
				const $stat = makeElement('div', { class: `brikpanel-stat ${s.cls}` });
				$stat.innerHTML = `<span class="brikpanel-stat-value">${s.value}</span><span class="brikpanel-stat-label">${s.label}</span>`;
				$stats.append($stat);
			});

			$summaryCard.append($stats);

			// Marketplace section (only if data exists)
			if (marketplaces && marketplaces.length > 0) {
				const $mpCard = makeElement('div', { class: 'brikpanel-overview-marketplaces' });

				const $mpTitle = makeElement('div', { class: 'brikpanel-overview-title' });
				$mpTitle.innerHTML = `<span class="brikpanel-overview-dot marketplace"></span><span class="brikpanel-overview-title-text">${i18n.marketplaces}</span>`;
				$mpCard.append($mpTitle);

				// Sort by revenue descending
				const sorted = [...marketplaces].sort((a, b) => (b.revenue_raw || 0) - (a.revenue_raw || 0));
				const maxVisible = 1;

				const $mpList = makeElement('div', { class: 'brikpanel-mp-list' });

				sorted.forEach((mp, idx) => {
					const $row = makeElement('div', { class: `brikpanel-mp-row${idx >= maxVisible ? ' brikpanel-mp-hidden' : ''}` });
					$row.innerHTML = `
						<div class="brikpanel-mp-name">
							${mp.logo ? `<img src="${mp.logo}" alt="${mp.name}" class="brikpanel-mp-logo">` : ''}
							<span>${mp.name}</span>
						</div>
						<div class="brikpanel-mp-stats">
							<span class="brikpanel-mp-stat"><strong>${mp.products}</strong> ${i18n.products}</span>
							<span class="brikpanel-mp-divider"></span>
							<span class="brikpanel-mp-stat"><strong>${mp.orders}</strong> ${i18n.orders_low}</span>
							<span class="brikpanel-mp-divider"></span>
							<span class="brikpanel-mp-stat revenue"><strong>${mp.revenue}</strong></span>
						</div>
					`;
					$mpList.append($row);
				});

				$mpCard.append($mpList);

				// Show all / collapse toggle (only if more than maxVisible)
				if (sorted.length > maxVisible) {
					const $toggle = makeElement('button', { class: 'brikpanel-mp-toggle' }, {}, [{
						event: 'click',
						handler: () => {
							const expanded = $mpCard.classList.toggle('expanded');
							$toggle.querySelector('.brikpanel-mp-toggle-text').textContent =
								expanded ? i18n.show_less : i18n.show_all;
							$toggle.querySelector('.brikpanel-mp-toggle-icon').classList.toggle('up', expanded);
						}
					}]);
					$toggle.innerHTML = `<span class="brikpanel-mp-toggle-text">${i18n.show_all}</span><span class="brikpanel-mp-toggle-icon"></span>`;
					$mpCard.append($toggle);
				}

				$wrapper.append($mpCard);
			}
		})
		.catch(() => {
			// Remove skeleton on error
			$wrapper.remove();
		});
}

/**
 * Makes adjustments to the orders table to make it scroll horizontally
 */
function brikpanelTableScroll() {
	const $tableContainer = makeElement('div', { class: 'wp-list-table-container', ['scroll-x']: '0' }, {}, [{
		event: 'scroll',
		handler: (event) => {
			event.currentTarget.setAttribute('scroll-x', `${event.currentTarget.scrollLeft}`);
		}
	}]);
	document.querySelector('.wp-list-table').insertAdjacentElement('beforebegin', $tableContainer);
	$tableContainer.append(document.querySelector('.wp-list-table'));
}

function brikpanelPageHeader() {
	const $heading = document.querySelector('.wp-heading-inline');
	const $header = makeElement('div', { class: 'brikpanel-page-top' });
	$heading.insertAdjacentElement('beforebegin', $header);
	$header.insertAdjacentElement('afterbegin', $heading);
	const $pageActions = makeElement('div', { class: 'brikpanel-page-actions' });
	document.querySelectorAll('.page-title-action').forEach($pageAction => {
		$pageActions.insertAdjacentElement('beforeend', $pageAction);
	});
	$header.insertAdjacentElement('beforeend', $pageActions);
}

/**
 * Makes the table top header with 'All', 'Processing', 'Drafts' filters
 */
function brikpanelTableHeader() {
	// Make table top header
	const $header1 = makeElement('div', { class: 'brikpanel-orders-table-header-1' });
	(document.querySelector('#posts-filter') ?? document.querySelector('#wc-orders-filter')).insertAdjacentElement('afterbegin', $header1);

	// Move 'All', 'Processing', 'Drafts' header inside the form
	const $subsubsub = document.querySelector('.subsubsub');
	$header1.append($subsubsub);

	// Remove pipe characters between 'All', 'Processing', 'Drafts'
	$subsubsub.querySelectorAll('li:not(:last-child)').forEach($li => {
		$li.innerHTML = $li.innerHTML.slice(0, -1);
	});

	// Highlight 'All' tab if no other is selected
	if (!document.querySelector('.subsubsub .current')) {
		document.querySelector('.subsubsub .all a').classList.add('current');
	}
}

/**
 * Enhances the search UI and moves it to the table top header
 */
function brikpanelOrderSearch() {
	// If filters are selected such that there are no filtered results, the
	// original WooCommerce search disappears, so here we create an empty div
	// as a backup.
	const $search = document.querySelector('.search-box') || document.createElement('div');

	const $searchContainer = makeElement('div', { class: 'brikpanel-search' }, {}, [{
		event: 'click', handler: (event) => {
			if (!event.currentTarget.classList.contains('expanded')) {
				event.currentTarget.classList.add('expanded');
				(document.querySelector('#post-search-input') ?? document.querySelector('#orders-search-input-search-input')).focus();
				// Hide Empty Trash button
				$emptyTrash = document.querySelector('input#delete_all');
				if ($emptyTrash) {
					$emptyTrash.style.display = 'none';
				}
			}
		}
	}]);

	if (typeof openSearchAndFilterByDefault !== 'undefined' && openSearchAndFilterByDefault) {
		$searchContainer.classList.add('expanded');
	}

	// Wrap the original search bar in the button we just made.
	$searchContainer.append($search);

	// Move the search bar inside the top header
	document.querySelector('.brikpanel-orders-table-header-1').insertAdjacentElement('beforeend', $searchContainer);

	if (document.querySelector('#order-search-filter')) {
		// Search bar placeholder initial text if there's search a search filter dropdown for HPOS setting
		const setSearchFilter = (val) => {
			let searchMessage = '';
			if (val === 'all') {
				searchMessage = _ordersI18n.search || 'Search';
			} else {
				const prettySearchFilter = document.querySelector(`#order-search-filter option[value="${val}"]`).innerHTML;
				searchMessage = (_ordersI18n.searching_by || 'Searching by') + ' ' + prettySearchFilter;
			}
			document.querySelector('#orders-search-input-search-input').setAttribute('placeholder', searchMessage);
		};
		setSearchFilter(document.querySelector('#order-search-filter').value);
		document.querySelector('#order-search-filter').addEventListener('change', (event) => {
			setSearchFilter(event.target.value);
		});
	} else {
		// Search bar placeholder if there's no search filter dropdown
		(document.querySelector('#post-search-input') ?? document.querySelector('#orders-search-input-search-input'))?.setAttribute('placeholder', _ordersI18n.search || 'Search');
	}

	// Remove search submit button
	document.querySelector('#search-submit')?.remove();

	// If the search box is rendered, add search-related elements
	if (document.querySelector('.search-box')) {
		// Make divs for the search and filter icons
		const $searchIcon = makeElement('div', { class: 'search-icon' });
		const $filterIcon = makeElement('div', { class: 'filter-icon' });
		const $searchAndFilterIcons = makeElement('div', { class: 'icons' });
		$searchAndFilterIcons.append($searchIcon);
		$searchAndFilterIcons.append($filterIcon);
		$searchContainer.insertAdjacentElement('afterbegin', $searchAndFilterIcons);

		// Add tooltip to search button
		let $tooltip = makeElement('div', { class: 'brikpanel-tooltip top' }, { innerHTML: _ordersI18n.search_and_filter || 'Search and filter (F)' });
		$searchContainer.append($tooltip);

		// Adjust tooltip width
		$tooltip = document.querySelector('.brikpanel-search .brikpanel-tooltip');
		let halfWidth = $tooltip.clientWidth / 2;
		halfWidth += document.body.clientWidth - $tooltip.getBoundingClientRect().right - 4;
		$tooltip.style.width = `${halfWidth * 2}px`;

		// Cancel search button
		const $cancel = makeElement('div', { class: 'brikpanel-search-cancel' }, { innerHTML: _ordersI18n.cancel || 'Cancel' }, [{
			event: 'click',
			handler: (event) => {
				event.stopPropagation();

				const searchInput = document.querySelector('#orders-search-input-search-input') ?? document.querySelector('#post-search-input');
				if (searchInput) {
					if (searchInput.value === '') {
						event.currentTarget.closest('.brikpanel-search').classList.remove('expanded');
					} else {
						brikpanelClearSearch();
					}

					$emptyTrash = document.querySelector('input#delete_all');
					if ($emptyTrash) {
						// Show Empty Trash button
						$emptyTrash.style.display = 'inline';
					}
				} else {
					event.currentTarget.closest('.brikpanel-search').classList.remove('expanded');
				}
			}
		}]);
		document.querySelector('.search-box').insertAdjacentElement('beforeend', $cancel);
	}

	// If a search query is active when the page is reloaded, which happens
	// when first entering a search, expand the search area.
	const params = new URLSearchParams(window.location.search);
	if (params.get('filter_action') && params.get('filter_action') === 'Filter') {
		document.querySelector('.brikpanel-search').classList.add('expanded');
	}

	// Add event listener for expanding search on 'F' key press
	document.addEventListener('keydown', (event) => {
		if (['INPUT', 'TEXTAREA', 'SELECT'].includes(event.target.tagName) || event.key.toLowerCase() !== 'f' || document.querySelector('.select2-container--open')) {
			return;
		}
		event.preventDefault();
		document.querySelector('.brikpanel-search').classList.add('expanded');
		(document.querySelector('#post-search-input') ?? document.querySelector('#orders-search-input-search-input')).focus();
	});
}

function brikpanelClearSearch() {
	const input = document.querySelector('#orders-search-input-search-input') ?? document.querySelector('#post-search-input');
	const submit = document.querySelector('#order-query-submit') ?? document.querySelector('#post-query-submit');
	input.value = '';
	submit.click();
}

function brikpanelAdjustCustomDropdownPosition(key) {
	const $filterButton = document.querySelector(`.brikpanel-${key}-filter`);
	const $dropdown = document.querySelector(`.brikpanel-${key}-dropdown`);
	if (!$filterButton || !$dropdown) {
		return;
	}
	// The dropdown is absolutely positioned inside the filter form (its
	// offset parent). Anchor it directly under its own chip using live
	// layout offsets so it stays correct whether the filter row sits on a
	// single line (desktop) or wraps onto several lines (mobile/tablet).
	const $form = $filterButton.closest('#posts-filter, #wc-orders-filter');
	if (!$form) {
		return;
	}
	const top = $filterButton.offsetTop + $filterButton.offsetHeight + 4;
	let left = $filterButton.offsetLeft;
	// Keep the panel fully inside the form on narrow viewports.
	const maxLeft = $form.clientWidth - $dropdown.offsetWidth - 8;
	if (left > maxLeft) {
		left = Math.max(4, maxLeft);
	}
	$dropdown.style.transform = 'none';
	$dropdown.style.left = `${left}px`;
	$dropdown.style.top = `${top}px`;
}

function brikpanelFilterCustomDropdown(key, prettyText, selectSelector, selectedOptionSelector) {
	// Filter button
	const $filterButton = makeElement('div', { class: `brikpanel-${key}-filter` }, { innerHTML: (_ordersI18n.filter_by || 'Filter by') + ' ' + prettyText }, [{
		event: 'click',
		handler: () => {
			const $dropdown = document.querySelector(`.brikpanel-${key}-dropdown`);
			$dropdown.classList.toggle('expanded');
			if ($dropdown.classList.contains('expanded')) {
				// Re-anchor under the chip every time it opens — the row may
				// have re-wrapped since init (resize, orientation change).
				brikpanelAdjustCustomDropdownPosition(key);
				const closeDropdownListener = (event) => {
					if (!event.target.closest(`.brikpanel-${key}-dropdown`) && !event.target.closest(`.brikpanel-${key}-filter`)) {
						document.querySelector(`.brikpanel-${key}-dropdown`).classList.remove('expanded');
						window.removeEventListener('click', closeDropdownListener);
					}
				};
				window.addEventListener('click', closeDropdownListener);
			}
		}
	}]);
	waitForElement('.brikpanel-filters').then(() => {
		document.querySelector('.brikpanel-filters').prepend($filterButton);
	});

	// Filter dropdown
	const $dropdown = makeElement('div', { class: `brikpanel-filter-dropdown brikpanel-${key}-dropdown` });
	document.querySelectorAll(`${selectSelector} option:not([value=""])`).forEach($option => {
		const $dropdownLabel = makeElement('label', { for: `brikpanel-${key}-` + $option.value }, { innerHTML: $option.innerHTML });
		const $dropdownItem = makeElement('input', {
			nodeType: 'INPUT',
			type: 'radio',
			value: $option.value,
			id: `brikpanel-${key}-${$option.value}`
		}, {}, [{
			event: 'input',
			handler: () => {
				document.querySelector(`${selectSelector}`).value = $option.value;
				(document.querySelector('#order-query-submit') ?? document.querySelector('#post-query-submit')).click();
			}
		}]);
		$dropdownLabel.append($dropdownItem);
		$dropdown.append($dropdownLabel);
	});
	const $form = (document.querySelector('#posts-filter') ?? document.querySelector('#wc-orders-filter'));
	$form.append($dropdown);

	// Filter initial state
	const $selectedOption = document.querySelector(`${selectSelector} ${selectedOptionSelector}`);
	if ($selectedOption) {
		$filterButton.innerHTML = `${$selectedOption.innerHTML}<span class="brikpanel-filter-remove"></span>`;
		$filterButton.querySelector('.brikpanel-filter-remove').addEventListener('click', (event) => {
			event.stopPropagation();
			document.querySelector(`${selectSelector}`).value = '0';
			(document.querySelector('#order-query-submit') ?? document.querySelector('#post-query-submit')).click();
		});
		if (!document.querySelector('.no-items')) {
			waitForElement('.brikpanel-search').then(() => {
				document.querySelector('.brikpanel-search').classList.add('expanded');
			});
		}
		document.querySelector(`#brikpanel-${key}-${$selectedOption.value}`).checked = true;
	}
}

function brikpanelSelect2FilterRemovers() {
	// Add a custom clear X to the Select2 filters.
	waitForElement('.select2-selection__clear').then(addCustomFilterRemovers);

	function addCustomFilterRemovers() {
		const originalRemovers = document.querySelectorAll('.brikpanel-filters .select2-selection__clear');
		if (originalRemovers.length > 0) {
			document.querySelector('.brikpanel-search')?.classList.add('expanded');
			originalRemovers.forEach(original => {
				const customRemover = createCustomRemover();
				original.insertAdjacentElement('afterend', customRemover);
				original.remove();
			});
		}
	}

	function createCustomRemover() {
		return makeElement('span', { class: 'brikpanel-filter-remove' }, {}, [{
			event: 'click',
			handler: event => handleCustomRemoverClick(event)
		}]);
	}

	function handleCustomRemoverClick(event) {
		const dropdown = document.querySelector('.select2-dropdown');
		// Product filter gets added with WooCommerce Subscriptions.
		const searchInput =
			event.target.parentElement.id.includes('product')
				? document.querySelector('.wc-product-search')
				: document.querySelector('.wc-customer-search');
		const submitButton = document.querySelector('#order-query-submit') ?? document.querySelector('#post-query-submit');

		// If there is a way to do this without having the dropdown flash for a
		// second before this gets applied…
		dropdown.style.display = 'none';
		searchInput.value = '';
		submitButton.click();
	}
}

function brikpanelFilterSelect2SubmitEvent(selectSelector) {
	const $selectElement = document.querySelector(selectSelector);
	if ($selectElement) {
		const customerFilterObserver = new MutationObserver(() => {
			(document.querySelector('#order-query-submit') ?? document.querySelector('#post-query-submit')).click();
		});
		customerFilterObserver.observe($selectElement, {
			attributeFilter: ['selected'],
			childList: true
		});
	}
}

function brikpanelClearFilters(selectSelectors) {
	const $clearFilters = makeElement('button', { class: 'brikpanel-clear-filter' }, { innerHTML: _ordersI18n.clear_filters || 'Clear filters' }, [{
		event: 'click',
		handler: () => {
			selectSelectors.forEach(selector => {
				const $select = document.querySelector(selector);
				if ($select) {
					$select.value = '';
				}
			});

			(document.querySelector('#order-query-submit') ?? document.querySelector('#post-query-submit')).click();
		}
	}]);
	waitForElement('.brikpanel-filters').then(() => {
		document.querySelector('.brikpanel-filters').append($clearFilters);
	});
}

/**
 * Creates a Shopify-style filters tool and places it in the table top header (collapsed under 'Search and 'Filter' button)
 */
function brikpanelFilters() {
	// Move filters to below the search
	const $filters = document.querySelector('.tablenav.top .actions:not(.bulkactions)');
	$filters.classList.remove('alignleft');
	$filters.classList.add('brikpanel-filters');
	document.querySelector('.brikpanel-orders-table-header-1').insertAdjacentElement('afterend', $filters);

	// Hide filter form elements
	for (const $filter of $filters.children) {
		if (!$filter.classList.contains('select2')) {
			$filter.style.display = 'none';
		}
	}

	brikpanelSelect2FilterRemovers();
}

/**
 * Get the bulk actions from the native WooCommerce dropdown for use in ours.
 * By grabbing them from here, we ensure compatibility with any plugin that adds
 * bulk actions as well as users of WooCommerce in all languages.
 * @returns {Array<Object>}
 */
function getBulkActions() {
	const options = document.querySelectorAll('#bulk-action-selector-top option');
	if (!options) {
		return;
	}
	const keysToSkip = new Set([
		'-1',
	]);
	const actions = [];
	for (const option of options) {
		if (keysToSkip.has(option.value)) {
			continue;
		}
		actions.push(
			{ value: option.value, innerHTML: option.innerHTML }
		);
	}
	return actions;
}

/**
 * Creates Shopify-style bulk actions that shows only when items are selected
 * @param {Array<Object>} actions - The array of actions from getBulkActions().
 */
function brikpanelBulkActions(actions) {
	// Move bulk actions to after .wrap
	const $bulkActions = document.querySelector('.tablenav.bottom .bulkactions');
	if (!$bulkActions) return;
	document.querySelector('.wrap').insertAdjacentElement('afterend', $bulkActions);

	// Give bulk actions the brikpanel classname and reorder stuff within
	$bulkActions.classList.add('brikpanel-bulk-actions');
	$bulkActions.querySelector('#bulk-action-selector-bottom').style.display = "none";
	$bulkActions.querySelector('#doaction2').style.display = "none";

	actions.forEach(({ value, innerHTML }) => {
		const $bulkAction = makeElement('button', { value: value }, { innerHTML: innerHTML }, [{
			event: 'click', handler: (event) => {
				document.querySelector('#bulk-action-selector-bottom').value = event.currentTarget.value;
				document.querySelector('#bulk-action-selector-top').value = event.currentTarget.value;
				document.querySelector('#doaction2').click();
			}
		}]);
		$bulkActions.append($bulkAction);
	});

	// Move hidden form elements to bulk actions space (so it's inside form)
	const $bulkActionsBg = makeElement('div', { class: 'bulkactions-bg' });
	document.querySelector('#the-list').insertAdjacentElement('afterend', $bulkActionsBg);
	$bulkActions.querySelectorAll('select, input').forEach($el => {
		$bulkActionsBg.append($el);
	});

	// Add event listener to checkboxes
	let checked = [];
	document.querySelectorAll('[name="id[]"], [name="post[]"]').forEach($checkbox => {
		$checkbox.addEventListener('change', (event) => {
			const $checkbox = event.currentTarget;
			if (checked.includes($checkbox.id) && !$checkbox.checked) {
				checked.splice(checked.indexOf($checkbox.id), 1);
			} else if (!checked.includes($checkbox.id) && $checkbox.checked) {
				checked.push($checkbox.id);
			}
			if (checked.length > 0) {
				document.querySelector('.brikpanel-bulk-actions')?.classList.add('show');
				document.querySelector('.bulkactions-bg')?.classList.add('show');
			} else {
				document.querySelector('.brikpanel-bulk-actions')?.classList.remove('show');
				document.querySelector('.bulkactions-bg')?.classList.remove('show');
			}
		});
	});
	document.querySelector('#cb-select-all-1').addEventListener('change', (event) => {
		const $checkbox = event.currentTarget;
		if ($checkbox.checked) {
			document.querySelectorAll('.check-column input[name="post[]"], .check-column input[name="id[]"]').forEach($cb => {
				$cb.checked = true;
			});
			checked = Array.from(document.querySelectorAll('.check-column input[name="post[]"], .check-column input[name="id[]"]')).map(($cb) => {
				return $cb.id;
			});
			document.querySelector('.brikpanel-bulk-actions')?.classList.add('show');
			document.querySelector('.bulkactions-bg')?.classList.add('show');
		} else {
			document.querySelectorAll('.check-column input[name="post[]"], .check-column input[name="id[]"]').forEach($cb => {
				$cb.checked = false;
			});
			checked = [];
			document.querySelector('.brikpanel-bulk-actions')?.classList.remove('show');
			document.querySelector('.bulkactions-bg')?.classList.remove('show');
		}
	});
}

/**
 * Hides top pagination and adjusts bottom pagination
 */
function brikpanelPagination() {
	// Hide top pagination
	document.querySelector('.tablenav.top').style.display = 'none';

	// Give pagination the brikpanel classname and reorder stuff within
	const $bottomPagination = document.querySelector('.tablenav.bottom');
	$bottomPagination.classList.add('brikpanel-pagination');

	document.querySelector('.brikpanel-pagination .pagination-links')
		.querySelectorAll('.button span[aria-hidden], .button:not(:has(.screen-reader-text))')
		.forEach(($chevron, index) => {
			$chevron.innerHTML = '';
			$chevron.classList.add('chevron');
			switch (index) {
				case 0:
					$chevron.classList.add('double-left');
					break;
				case 1:
					$chevron.classList.add('left');
					break;
				case 2:
					$chevron.classList.add('right');
					break;
				case 3:
					$chevron.classList.add('double-right');
					break;
				default:
					break;
			}
		});
}

/**
 * Adjusts details for the table cell with order number, order name, and preview button
 */
function brikpanelOrderNumberNamePreview() {
	// Remove 'Preview' text from the preview buttons
	document.querySelectorAll('.order-preview').forEach($preview => {
		$preview.innerHTML = '';
		$preview.closest('.order_number').querySelector('.order-view')?.insertAdjacentElement('afterend', $preview);
	});

	// Rearrange order number table cell
	document.querySelectorAll('.wp-list-table tbody .column-order_number').forEach($cell => {
		const $cellItems = makeElement('div', { class: 'brikpanel-order-number' }, { innerHTML: $cell.innerHTML });
		$cell.innerHTML = '';
		$cell.append($cellItems);
	});
}

/**
 * Makes adjustments to the order status table cell
 */
function brikpanelOrderStatus() {
	// Change order status mark
	document.querySelectorAll('mark.order-status').forEach($mark => {
		const $new = makeElement('div', { class: $mark.className }, { innerHTML: $mark.innerHTML });
		$mark.innerHTML = '';
		$mark.insertAdjacentElement('afterend', $new);
		$mark.remove();
	});
}

/**
 * Removes the table footer
 */
function brikpanelFooter() {
	// Remove table footer
	document.querySelector('.wp-list-table tfoot').remove();
}

/**
 * Makes the analytics section
 */


function brikpanelEmptyTrashButton() {
	const params = new URLSearchParams(window.location.search);
	const status = params.get('status') ?? params.get('post_status');
	if (status !== 'trash') {
		return;
	}
	// We clone it here to keep the filter hiding code in filters() clean.
	const $button = document.querySelector('input#delete_all').cloneNode();
	$button.style.display = 'inline';
	document.querySelector('.brikpanel-search').insertAdjacentElement(
		'beforebegin',
		$button
	);
}

/**
 * If an order status filter is applied, we add the query param to each link
 * to an individual order, allowing us to maintain that filtering for the next
 * and previous buttons inside the single order view.
 */
function brikpanelAddFiltersToOrderLinks() {
	const params = new URLSearchParams(window.location.search);
	const status = params.get('status') ?? params.get('post_status');
	if (!status) {
		return;
	}
	const orderLinks = document.querySelectorAll('a.order-view');
	for (const link of orderLinks) {
		// We carry over the two different query params for HPOS and non-HPOS
		// because if someone navigates using the back button from inside the
		// order view, we'll need the original query param for the filtering to
		// work.
		link.href = `${link.href}&${params.has('status') ? 'status' : 'post_status'}=${status}`;
	}
}

/**
 * Helper function for analytics. Draws the graph in the corresponding canvas element.
 * @param {object} section - The section for which we’re making the graph.
 */
function makeGraph(section) {
	const $canvas = document.querySelector(`canvas#brikpanel-${section.key}`);
	const ctx = $canvas.getContext('2d');
	const canvasPadding = 4;
	const graphFill = (canvasWidth, canvasHeight, colorStop1 = 0.2, colorStop2 = 1) => {
		const fill = ctx.createLinearGradient(canvasWidth / 2, 0, canvasWidth / 2, canvasHeight);
		fill.addColorStop(colorStop1, '#51b0FF88');
		fill.addColorStop(colorStop2, '#ffffff00');
		return fill;
	}
	if (section.value === 0 || section.value === '$0.00') {
		const flatGraphInnerWidth = 100;
		const flatGraphInnerHeight = 36;
		const flatGraphCanvasWidth = flatGraphInnerWidth + canvasPadding * 2;
		const flatGraphCanvasHeight = flatGraphInnerHeight + canvasPadding * 2;
		$canvas.setAttribute('width', `${flatGraphCanvasWidth}`);
		$canvas.setAttribute('height', `${flatGraphCanvasHeight}`);
		ctx.beginPath();
		ctx.moveTo(canvasPadding, flatGraphCanvasHeight * 2 / 3);
		ctx.lineTo(canvasPadding + flatGraphInnerWidth, flatGraphCanvasHeight * 2 / 3);
		ctx.lineWidth = 2.5;
		ctx.lineCap = 'round';
		ctx.strokeStyle = '#3AA3FF';
		ctx.stroke();
		ctx.beginPath();
		ctx.moveTo(canvasPadding, flatGraphCanvasHeight * 2 / 3);
		ctx.lineTo(canvasPadding + flatGraphInnerWidth, flatGraphCanvasHeight * 2 / 3);
		ctx.lineTo(canvasPadding + flatGraphInnerWidth, canvasPadding + flatGraphInnerHeight);
		ctx.lineTo(canvasPadding, canvasPadding + flatGraphInnerHeight);
		ctx.closePath();
		ctx.fillStyle = graphFill(flatGraphCanvasWidth, flatGraphCanvasHeight, 0.6);
		ctx.fill();
		return;
	}
	const graphDivisions = brikpanelAnalyticsData.graphDivisions;
	const innerWidth = graphDivisions > 12 ? 200 : 148;
	const innerHeight = 36;
	const unit = (innerWidth / graphDivisions).toFixed(2);
	const canvasWidth = innerWidth + canvasPadding * 2;
	const canvasHeight = innerHeight + canvasPadding * 2;
	$canvas.setAttribute('width', `${canvasWidth}`);
	$canvas.setAttribute('height', `${canvasHeight}`);
	const heightAtDivision = (i) => {
		const data = brikpanelAnalyticsData.intervalData[i] ? brikpanelAnalyticsData.intervalData[i][section.key] : 0;
		const ratio = data / brikpanelAnalyticsData.divisionMaxima[section.key];
		return (1 - ratio.toFixed(2)) * innerHeight;
	}
	ctx.beginPath();
	const graphXCoord = (graphDivision) => graphDivision * unit + canvasPadding;
	const graphYCoord = (graphDivision) => heightAtDivision(graphDivision) + canvasPadding;
	for (let i = 0; i < graphDivisions; i++) {
		if (graphYCoord(i) < innerHeight + canvasPadding) {
			ctx.moveTo(graphXCoord(i) - 0.5, innerHeight + canvasPadding - 1);
			ctx.lineTo(graphXCoord(i) + 0.5, innerHeight + canvasPadding - 1);
			ctx.stroke();
			ctx.moveTo(graphXCoord(i - 1), graphYCoord(i - 1));
		}
		if (i === 0) {
			ctx.moveTo(graphXCoord(i), graphYCoord(i));
		} else {
			ctx.bezierCurveTo(graphXCoord(i - 1) + 2.5, graphYCoord(i - 1), graphXCoord(i) - 2.5, graphYCoord(i), graphXCoord(i), graphYCoord(i));
		}
	}
	const strokeGradient = ctx.createLinearGradient(canvasWidth / 2, 0, canvasWidth / 2, canvasHeight);
	strokeGradient.addColorStop(.3, '#44B6FF');
	strokeGradient.addColorStop(.9, '#3896F0');
	ctx.strokeStyle = strokeGradient;
	ctx.lineWidth = 3;
	ctx.lineCap = 'round';
	ctx.lineJoin = 'round';
	ctx.stroke();
	ctx.beginPath();
	for (let i = 0; i < graphDivisions; i++) {
		if (i === 0) {
			ctx.moveTo(graphXCoord(i), graphYCoord(i));
		} else {
			ctx.bezierCurveTo(graphXCoord(i - 1) + 2.5, graphYCoord(i - 1), graphXCoord(i) - 2.5, graphYCoord(i), graphXCoord(i), graphYCoord(i));
		}
	}
	ctx.lineTo(graphXCoord(graphDivisions - 1), innerHeight + canvasPadding);
	ctx.lineTo(graphXCoord(0), innerHeight + canvasPadding);
	ctx.lineTo(graphXCoord(0), graphYCoord(0));
	ctx.closePath();
	ctx.lineWidth = 1;
	ctx.fillStyle = graphFill(canvasWidth, canvasHeight);
	ctx.fill();
}

function addEventListenersForAnalyticsRangeSelection() {
	for (const radio of document.querySelectorAll('input[name="days"]')) {
		radio.addEventListener('click', async () => {
			brikpanelAnalyticsData = await fetchGraphData(radio.value);

			// wp_send_json_success() puts these into strings, so we have to turn
			// them back to JSON here. This could be refactored so it's not necessary.
			brikpanelAnalyticsData.intervalData = JSON.parse(brikpanelAnalyticsData.intervalData);
			brikpanelAnalyticsData.divisionMaxima = JSON.parse(brikpanelAnalyticsData.divisionMaxima);

			document.querySelector('.brikpanel-analytics').remove();
		});
	}
}

async function fetchGraphData(days) {
	const body = new FormData();
	body.append('_ajax_nonce', brikpanelAnalyticsAJAX.nonce);
	body.append('action', 'brikpanel_order_list_analytics');
	body.append('days', days);
	body.append('is_subscriptions', isBrikpanelSubscriptionList);

	// brikpanelAjax is globally available in the admin: https://developer.wordpress.org/plugins/javascript/ajax/#url
	const response = await fetch(brikpanelAjax, {
		method: 'POST',
		body,
	});

	return (await response.json()).data;
}

/**
 * Unhides the page content
 */
function showBrikpanel() {
	// Unhide page elements
	document.querySelector('#wpbody-content')?.classList.add('show');
}

function waitForElement(selector) {
	return new Promise((resolve) => {
		const checkElement = () => {
			const element = document.querySelector(selector);
			if (element !== null) {
				resolve(element);
			} else {
				requestAnimationFrame(checkElement);
			}
		};

		checkElement();
	});
}

/**
 * Add a result count near the search bar in the order list table.
 *
 * This was introduced because Brikpanel removes the top pagination. The item
 * count, if pagination shows, is only at the bottom and can be easily missed.
 *
 * @returns void
 */
function brikpanelListTableSearchResultCount() {
	const params = new URLSearchParams(window.location.search);
	// Don’t show result count unless a search query has been entered.
	if (!params.has('s')) {
		return;
	}

	const total = document.querySelector('.displaying-num')?.textContent.split(' ')[0];
	// Exit if pagination isn’t rendered, since then we wouldn’t have any number to grab.
	// It may not be visible, but it’s usually in the DOM.
	if (!total) {
		return;
	}

	const searchInput =
		document.querySelector('#orders-search-input-search-input')
		|| document.querySelector('#post-search-input');
	if (!searchInput) {
		return;
	}

	searchInput.insertAdjacentHTML('afterend', `
		<span style="white-space: nowrap; color: #707070;">
			${total} result${parseInt(total) === 1 ? '' : 's'}
		</span>
	`);
}
