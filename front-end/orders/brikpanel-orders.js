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

/**
 * Columns WordPress, WooCommerce, WooCommerce Subscriptions and BrikPanel own.
 * Anything else on the orders list was put there by another plugin, and is a
 * candidate for the icon-action treatment (see brikpanelIconActionColumns).
 */
const BP_ICONBAR_KNOWN_COLUMNS = new Set([
	// WordPress / WooCommerce
	'primary', 'cb', 'comments',
	'order_number', 'order_date', 'order_status', 'origin',
	'billing_address', 'shipping_address', 'order_total', 'wc_actions',
	// WooCommerce Subscriptions
	'subscription_status', 'order_title', 'recurring_total', 'start_date',
	'trial_end_date', 'next_payment_date', 'last_payment_date', 'end_date', 'orders',
	// BrikPanel
	'payment_method', 'order_items', 'tax_total', 'brikpanel_whatsapp',
	'brikpanel_customer', 'brikpanel_shipping_method',
]);

/* A run of this many icon controls turns the treatment on. A lone icon cannot
   stack, so it is never worth reserving width for. */
const BP_ICONBAR_MIN_ITEMS = 2;
/* A cell carrying more visible text of its own than this is a content column
   that happens to hold a couple of icons, not an action column. */
const BP_ICONBAR_MAX_TEXT = 40;
/* Icons per row. Two keeps the column narrow, which is the whole point: the
   merchant who reported this needs room for many columns at once. */
const BP_ICONBAR_COLS = 2;
/* ...but a plugin offering a dozen documents would be twelve rows tall at two
   per row, so the grid widens instead of growing past this many rows. */
const BP_ICONBAR_MAX_ROWS = 3;
/* Hard ceiling on the reserved width, counted in icon slots. */
const BP_ICONBAR_MAX_COLS = 4;

document.addEventListener('DOMContentLoaded', () => {
	brikpanelOrderTableFilters();
});

function brikpanelOrderTableFilters() {
	// Date filter
	brikpanelFilterCustomDropdown('date', 'date', '#filter-by-date', '[selected="selected"]:not([value="0"])');

	// Order Tags by 99w
	brikpanelFilterCustomDropdown('order-tags-99w', (_ordersI18n.filter_order_tag || 'order tag'), 'select[name="wcot_order_tags_filter"]', '[selected=""]:not([value=""])');

	// Shipping method filter
	brikpanelFilterCustomDropdown('shipping-method', (_ordersI18n.filter_shipping_method || 'shipping method'), '#brikpanel_shipping_method', '[selected="selected"]:not([value=""])');

	// Customer filter
	brikpanelFilterSelect2SubmitEvent('.wc-customer-search');

	// Custom dropdowns are positioned under their chip on open
	// (see brikpanelAdjustCustomDropdownPosition), so the layout stays
	// correct after the filter row wraps on mobile/tablet.

	// Clear filters button
	brikpanelClearFilters(['#filter-by-date', 'select[name="wcot_order_tags_filter"]', '#brikpanel_shipping_method', '.wc-customer-search', '#dropdown_shop_order_subtype']);
}

document.addEventListener('DOMContentLoaded', () => {
	// First, and behind its own guard: it needs nothing the calls below build,
	// and any one of them throwing on a page it did not expect would otherwise
	// skip it and leave a store's icon columns stacked.
	try {
		brikpanelIconActionColumns();
	} catch (error) {
		console.error(error);
	}

	// Each pass runs behind its own guard: one pass throwing on a page it did
	// not expect (a store with no orders yet has no list table) must not skip
	// every pass after it.
	const hasTable = !!document.querySelector('.wp-list-table');
	// [pass, needs the list table]
	const passes = [
		[brikpanelModalScrollLock, false],
		[brikpanelTableScroll, true],
		[brikpanelPageHeader, false],
		[brikpanelOrdersOverviewSection, false],
		[brikpanelTableHeader, true],
		[brikpanelOrderSearch, true],
		[brikpanelFilters, true],
		[() => brikpanelBulkActions(getBulkActions()), true],
		[brikpanelPagination, true],
		[brikpanelOrderNumberNamePreview, true],
		[brikpanelCompactRows, true],
		[brikpanelStickyOrderColumn, true],
		[brikpanelOrderStatus, true],
		[brikpanelFooter, true],
		[brikpanelEmptyTrashButton, false],
		[brikpanelAddFiltersToOrderLinks, true],
		[brikpanelListTableSearchResultCount, true],
	].filter(([, needsTable]) => hasTable || !needsTable).map(([pass]) => pass);
	try {
		passes.forEach(pass => {
			try {
				pass();
			} catch (error) {
				console.error(error);
			}
		});
	} finally {
		showBrikpanel();
	}
});

/**
 * Build an SVG area-sparkline (smooth curve + gradient fill + dotted baseline)
 * from a numeric series. The viewBox stretches to the host element.
 *
 * @param {number[]} values Series values (counts or revenue), oldest first.
 * @param {string}   uid    Unique id fragment for the gradient.
 * @returns {string} SVG markup.
 */
function brikpanelSparklineSvg(values, uid) {
	const W = 100, H = 34, padTop = 5, padBottom = 3;
	let pts = Array.isArray(values) ? values.slice() : [];
	if (pts.length === 0) pts = [0, 0];
	if (pts.length === 1) pts = [pts[0], pts[0]];

	const max = Math.max.apply(null, pts.concat([0]));
	const span = max > 0 ? max : 1;
	const innerH = H - padTop - padBottom;
	const n = pts.length;

	const coords = pts.map((v, i) => ({
		x: n === 1 ? W / 2 : (i / (n - 1)) * W,
		y: padTop + (innerH - (v / span) * innerH),
	}));

	// Catmull-Rom -> cubic bezier for a smooth line.
	let line = `M ${coords[0].x.toFixed(2)} ${coords[0].y.toFixed(2)}`;
	for (let i = 0; i < coords.length - 1; i++) {
		const p0 = coords[i - 1] || coords[i];
		const p1 = coords[i];
		const p2 = coords[i + 1];
		const p3 = coords[i + 2] || p2;
		const c1x = p1.x + (p2.x - p0.x) / 6;
		const c1y = p1.y + (p2.y - p0.y) / 6;
		const c2x = p2.x - (p3.x - p1.x) / 6;
		const c2y = p2.y - (p3.y - p1.y) / 6;
		line += ` C ${c1x.toFixed(2)} ${c1y.toFixed(2)}, ${c2x.toFixed(2)} ${c2y.toFixed(2)}, ${p2.x.toFixed(2)} ${p2.y.toFixed(2)}`;
	}

	const area = `${line} L ${W} ${H} L 0 ${H} Z`;
	const baseY = (H - padBottom).toFixed(2);
	const gid = `bpspark-${uid}`;

	return `<svg viewBox="0 0 ${W} ${H}" preserveAspectRatio="none" class="brikpanel-spark-svg" aria-hidden="true" focusable="false">`
		+ `<defs><linearGradient id="${gid}" x1="0" y1="0" x2="0" y2="1">`
		+ `<stop offset="0%" stop-color="#303030" stop-opacity="0.1"/>`
		+ `<stop offset="100%" stop-color="#303030" stop-opacity="0"/>`
		+ `</linearGradient></defs>`
		+ `<line x1="0" y1="${baseY}" x2="${W}" y2="${baseY}" class="brikpanel-spark-base"/>`
		+ `<path d="${area}" fill="url(#${gid})" stroke="none"/>`
		+ `<path d="${line}" fill="none" class="brikpanel-spark-line"/>`
		+ `</svg>`;
}

/**
 * Orders overview section: selectable-range summary cards with sparklines
 * (Total orders, Completed, Refunded, Revenue) + marketplace stats.
 */
function brikpanelOrdersOverviewSection() {
	if (typeof brikpanelOrdersOverview === 'undefined') return;
	// Hidden for this user's role (e.g. branch staff who must not see store-wide totals).
	if (brikpanelOrdersOverview.hide_overview) return;

	const i18n = brikpanelOrdersOverview.i18n || {};
	const RANGES = [
		{ key: 'today', label: i18n.today || '' },
		{ key: '24h', label: i18n.last_24_hours || '' },
		{ key: '7d', label: i18n.last_7_days || '' },
		{ key: '30d', label: i18n.last_30_days || '' },
	];
	const METRICS = [
		{ key: 'total', label: i18n.total_orders || '', revenue: false },
		{ key: 'completed', label: i18n.completed || '', revenue: false },
		{ key: 'refunded', label: i18n.refunded || '', revenue: false },
		{ key: 'revenue', label: i18n.revenue || '', revenue: true },
	];

	const STORAGE_KEY = 'brikpanelOrdersOverviewRange';
	let currentRange = 'today';
	try {
		const saved = window.localStorage.getItem(STORAGE_KEY);
		if (saved && RANGES.some(r => r.key === saved)) currentRange = saved;
	} catch (e) { /* storage may be unavailable */ }

	const $wrapper = makeElement('div', { class: 'brikpanel-overview' });
	const $summaryCard = makeElement('div', { class: 'brikpanel-overview-summary is-loading' });

	// Date-range picker (calendar pill + dropdown).
	const $range = makeElement('div', { class: 'brikpanel-range' });
	const calIcon = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>`;
	const chevIcon = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" class="brikpanel-range-chevron"><polyline points="6 9 12 15 18 9"/></svg>`;

	const $rangeBtn = makeElement('button', {
		class: 'brikpanel-range-btn',
		type: 'button',
		'aria-haspopup': 'true',
		'aria-expanded': 'false',
		'aria-label': i18n.date_range || '',
	});
	$rangeBtn.innerHTML = `<span class="brikpanel-range-ico">${calIcon}</span><span class="brikpanel-range-label"></span>${chevIcon}`;
	const $rangeLabel = $rangeBtn.querySelector('.brikpanel-range-label');

	const $rangeMenu = makeElement('div', { class: 'brikpanel-range-menu', role: 'menu', hidden: 'hidden' });
	RANGES.forEach(r => {
		const $opt = makeElement('button', {
			class: 'brikpanel-range-opt',
			type: 'button',
			role: 'menuitemradio',
			'data-range': r.key,
			'aria-checked': r.key === currentRange ? 'true' : 'false',
		}, { textContent: r.label });
		$opt.insertAdjacentHTML('afterbegin', '<span class="brikpanel-range-radio" aria-hidden="true"></span>');
		$rangeMenu.append($opt);
	});

	$range.append($rangeBtn, $rangeMenu);

	// Metric cards.
	const $metrics = makeElement('div', { class: 'brikpanel-metrics' });
	const metricEls = {};
	METRICS.forEach((m, idx) => {
		const $m = makeElement('div', { class: `brikpanel-metric brikpanel-metric--${m.key}` });
		$m.innerHTML = `<span class="brikpanel-metric-label">${m.label}</span>`
			+ `<div class="brikpanel-metric-body">`
			+ `<span class="brikpanel-metric-value">—</span>`
			+ `<span class="brikpanel-metric-spark">${brikpanelSparklineSvg([0, 0], m.key + idx)}</span>`
			+ `</div>`;
		metricEls[m.key] = {
			value: $m.querySelector('.brikpanel-metric-value'),
			spark: $m.querySelector('.brikpanel-metric-spark'),
		};
		$metrics.append($m);
	});

	$summaryCard.append($range, $metrics);
	$wrapper.append($summaryCard);

	// Insert after the page header, and after the Screen Options panel that
	// opens right under it.
	const $pageTop = document.querySelector('.brikpanel-page-top');
	if ($pageTop) {
		const $next = $pageTop.nextElementSibling;
		($next && $next.id === 'screen-meta' ? $next : $pageTop).insertAdjacentElement('afterend', $wrapper);
	}

	function setRangeLabel(key) {
		const r = RANGES.find(x => x.key === key);
		$rangeLabel.textContent = r ? r.label : '';
		$rangeMenu.querySelectorAll('.brikpanel-range-opt').forEach($o => {
			$o.setAttribute('aria-checked', $o.getAttribute('data-range') === key ? 'true' : 'false');
		});
	}

	function closeMenu() {
		$rangeMenu.setAttribute('hidden', 'hidden');
		$rangeBtn.setAttribute('aria-expanded', 'false');
		$range.classList.remove('open');
		document.removeEventListener('click', onOutside, true);
	}

	function onOutside(e) {
		if (!$range.contains(e.target)) closeMenu();
	}

	$rangeBtn.addEventListener('click', (e) => {
		e.stopPropagation();
		const isOpen = !$rangeMenu.hasAttribute('hidden');
		if (isOpen) { closeMenu(); return; }
		$rangeMenu.removeAttribute('hidden');
		$rangeBtn.setAttribute('aria-expanded', 'true');
		$range.classList.add('open');
		document.addEventListener('click', onOutside, true);
	});

	$rangeMenu.addEventListener('click', (e) => {
		const $opt = e.target.closest('.brikpanel-range-opt');
		if (!$opt) return;
		const key = $opt.getAttribute('data-range');
		closeMenu();
		if (key === currentRange) return;
		currentRange = key;
		try { window.localStorage.setItem(STORAGE_KEY, key); } catch (err) { /* ignore */ }
		setRangeLabel(key);
		loadOverview(key);
	});

	function renderSummary(summary) {
		const series = summary.series || {};
		METRICS.forEach((m, idx) => {
			const el = metricEls[m.key];
			if (!el) return;
			el.value.textContent = m.revenue
				? summary.revenue_formatted
				: Number(summary[m.key] || 0).toLocaleString();
			el.spark.innerHTML = brikpanelSparklineSvg(series[m.key] || [0, 0], m.key + idx);
		});
	}

	function loadOverview(range) {
		$summaryCard.classList.add('is-loading');
		const body = new FormData();
		body.append('action', 'brikpanel_orders_overview');
		body.append('_ajax_nonce', brikpanelOrdersOverview.nonce);
		body.append('range', range);

		fetch(brikpanelOrdersOverview.ajax_url, { method: 'POST', body })
			.then(r => r.json())
			.then(response => {
				if (!response.success) { $summaryCard.classList.remove('is-loading'); return; }
				// A newer request may have superseded this one.
				if (response.data.range && response.data.range !== currentRange) return;
				$summaryCard.classList.remove('is-loading');
				renderSummary(response.data.summary);
				renderMarketplaces(response.data.marketplaces);
			})
			.catch(() => { $summaryCard.classList.remove('is-loading'); });
	}

	let $mpCard = null;
	/* Marketplace card (BrikMarket): one row per marketplace under the summary.
	   Built with DOM nodes so names and logo URLs coming from the marketplace
	   integration are never parsed as HTML. */
	function renderMarketplaces(marketplaces) {
		if ($mpCard) { $mpCard.remove(); $mpCard = null; }
		if (!Array.isArray(marketplaces) || marketplaces.length === 0) return;

		$mpCard = makeElement('div', { class: 'brikpanel-overview-marketplaces' });

		const $head = makeElement('div', { class: 'brikpanel-mp-head' });
		$head.append(makeElement('span', { class: 'brikpanel-overview-title' }, { textContent: i18n.marketplaces || '' }));
		$mpCard.append($head);

		const sorted = [...marketplaces].sort((a, b) => (b.revenue_raw || 0) - (a.revenue_raw || 0));
		const maxVisible = 3;
		const $list = makeElement('div', { class: 'brikpanel-mp-list' });

		sorted.forEach((mp, idx) => {
			const $row = makeElement('div', { class: `brikpanel-mp-row${idx >= maxVisible ? ' brikpanel-mp-hidden' : ''}` });

			const $name = makeElement('div', { class: 'brikpanel-mp-name' });
			const logo = String(mp.logo || '');
			if (/^https?:\/\//i.test(logo)) {
				$name.append(makeElement('img', { src: logo, alt: '', class: 'brikpanel-mp-logo', loading: 'lazy' }));
			}
			$name.append(makeElement('span', {}, { textContent: String(mp.name || '') }));

			const $stats = makeElement('div', { class: 'brikpanel-mp-stats' });
			const addStat = (value, label, extraClass = '') => {
				const $stat = makeElement('span', { class: `brikpanel-mp-stat ${extraClass}`.trim() });
				$stat.append(makeElement('strong', {}, { textContent: String(value) }));
				if (label) $stat.append(document.createTextNode(' ' + label));
				$stats.append($stat);
			};
			addStat(Number(mp.products || 0).toLocaleString(), i18n.products || '');
			addStat(Number(mp.orders || 0).toLocaleString(), i18n.orders_low || '');
			addStat(String(mp.revenue || ''), '', 'revenue');

			$row.append($name, $stats);
			$list.append($row);
		});
		$mpCard.append($list);

		if (sorted.length > maxVisible) {
			const $toggle = makeElement('button', { type: 'button', class: 'brikpanel-mp-toggle', 'aria-expanded': 'false' }, { textContent: i18n.show_all || '' });
			$toggle.addEventListener('click', () => {
				const expanded = $mpCard.classList.toggle('expanded');
				$toggle.textContent = expanded ? (i18n.show_less || '') : (i18n.show_all || '');
				$toggle.setAttribute('aria-expanded', String(expanded));
			});
			$head.append($toggle);
		}

		$wrapper.append($mpCard);
	}

	// Initial render.
	setRangeLabel(currentRange);
	loadOverview(currentRange);
}

/**
 * Makes adjustments to the orders table to make it scroll horizontally
 */
function brikpanelTableScroll() {
	if (!document.querySelector('.wp-list-table')) return;
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
	if (!$heading) return;
	const $header = makeElement('div', { class: 'brikpanel-page-top' });
	$heading.insertAdjacentElement('beforebegin', $header);
	$header.insertAdjacentElement('afterbegin', $heading);
	const $pageActions = makeElement('div', { class: 'brikpanel-page-actions' });
	document.querySelectorAll('.page-title-action').forEach($pageAction => {
		$pageActions.insertAdjacentElement('beforeend', $pageAction);
	});

	/* WordPress's Screen Options toggle joins the page actions, so the title row
	   holds everything instead of the toggle floating on a row of its own. The
	   node moves with its id and WordPress's own handlers, so it keeps working;
	   its panel moves under the title row so it opens below the button. The
	   legacy screen's WooCommerce clip on the toggle is undone in the CSS. */
	const $screenMetaLinks = document.getElementById('screen-meta-links');
	if ($screenMetaLinks && $screenMetaLinks.querySelector('.show-settings')) {
		$pageActions.insertAdjacentElement('afterbegin', $screenMetaLinks);
		const $screenMeta = document.getElementById('screen-meta');
		if ($screenMeta) {
			$header.insertAdjacentElement('afterend', $screenMeta);
		}
	}

	$header.insertAdjacentElement('beforeend', $pageActions);
}

/**
 * WooCommerce's modals (the order preview) lock scrolling with
 * `body { overflow: hidden }`. BrikPanel's top bar gives <body> a fixed height,
 * so that clipped the page and threw it back to the top; closing the modal
 * then left <body> as the scrolling box. The lock is moved to <html> and the
 * scroll position is restored.
 */
function brikpanelModalScrollLock() {
	if (!window.jQuery) return;

	let lastX = window.scrollX;
	let lastY = window.scrollY;
	let lastBodyTop = document.body.scrollTop;
	let locked = false;

	window.addEventListener('scroll', () => {
		if (locked) return;
		lastX = window.scrollX;
		lastY = window.scrollY;
	}, { passive: true });
	document.body.addEventListener('scroll', () => {
		if (!locked) lastBodyTop = document.body.scrollTop;
	}, { passive: true });

	const restore = () => {
		window.scrollTo(lastX, lastY);
		document.body.scrollTop = lastBodyTop;
	};

	window.jQuery(document.body)
		.on('wc_backbone_modal_loaded', () => {
			locked = true;
			document.body.style.overflow = '';
			document.documentElement.classList.add('brikpanel-modal-lock');
			restore();
		})
		.on('wc_backbone_modal_removed', () => {
			document.body.style.overflow = '';
			document.documentElement.classList.remove('brikpanel-modal-lock');
			restore();
			locked = false;
		});
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
		document.querySelector('.subsubsub .all a')?.classList.add('current');
	}

	// Tabs that run under the search button fade out at the edge.
	const syncTabsOverflow = () => $subsubsub.classList.toggle('is-overflowing', $subsubsub.scrollWidth > $subsubsub.clientWidth + 1);
	syncTabsOverflow();
	if ('ResizeObserver' in window) new ResizeObserver(syncTabsOverflow).observe($subsubsub);

	// The search opens by itself when a search or filter is active on load;
	// that must not play the opening animation.
	$header1.classList.add('bp-anim-off');
	window.setTimeout(() => $header1.classList.remove('bp-anim-off'), 700);
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

	// Escape closes an empty search, like Cancel.
	document.addEventListener('keydown', (event) => {
		if (event.key !== 'Escape' || document.querySelector('.select2-container--open, .wc-backbone-modal')) return;
		const $container = document.querySelector('.brikpanel-search.expanded');
		const input = document.querySelector('#orders-search-input-search-input') ?? document.querySelector('#post-search-input');
		if (!$container || (input && input.value !== '')) return;
		const params = new URLSearchParams(window.location.search);
		if (params.get('filter_action') || document.querySelector('.brikpanel-filters .brikpanel-filter-remove')) return;
		$container.classList.remove('expanded');
		input?.blur();
	});

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
	// A store with no orders yet shows WooCommerce's blank slate: no list
	// table and no filter form to attach to.
	if (!document.querySelector('.wp-list-table')) return;
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
	// The row opens and closes by animating a grid track, which (unlike
	// max-height) follows the real height of the chips at any width.
	const $collapse = makeElement('div', { class: 'brikpanel-filters-collapse' });
	$collapse.append($filters);
	document.querySelector('.brikpanel-orders-table-header-1').insertAdjacentElement('afterend', $collapse);

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

	/* Merging needs at least two orders, so its button stays disabled until two
	   rows are ticked. Nothing else here cares how many are selected. */
	let $mergeButton = null;

	// How many orders the actions below will apply to.
	const $selectedCount = makeElement('span', { class: 'brikpanel-bulk-count', 'aria-live': 'polite' });
	/* When the actions do not fit on one row (many statuses, narrow screens)
	   they fold into a "Bulk actions" menu that opens above the bar. */
	const $menuToggle = makeElement('button', {
		type: 'button',
		class: 'brikpanel-bulk-more',
		'aria-expanded': 'false',
		'aria-controls': 'brikpanel-bulk-menu',
	}, { textContent: _ordersI18n.bulk_actions || '' });
	const $menu = makeElement('div', { class: 'brikpanel-bulk-menu', id: 'brikpanel-bulk-menu' });
	$bulkActions.append($selectedCount, $menuToggle, $menu);

	actions.forEach(({ value, innerHTML }) => {
		const $bulkAction = makeElement('button', { value: value }, { innerHTML: innerHTML }, [{
			event: 'click', handler: (event) => {
				document.querySelector('#bulk-action-selector-bottom').value = event.currentTarget.value;
				document.querySelector('#bulk-action-selector-top').value = event.currentTarget.value;
				document.querySelector('#doaction2').click();
			}
		}]);
		if (value === 'brikpanel_merge_orders') {
			$mergeButton = $bulkAction;
		}
		if (value === 'trash' || value === 'delete') {
			$bulkAction.classList.add('is-destructive');
		}
		$menu.append($bulkAction);
	});

	/* Counted live off the DOM rather than off the `checked` array below: WP
	   core's shift-click range select flips the boxes with jQuery, which never
	   fires a native change event, so the array would under-report. */
	function brikpanelSelectedRowCount() {
		return document.querySelectorAll(
			'.check-column input[name="post[]"]:checked, .check-column input[name="id[]"]:checked'
		).length;
	}

	function updateMergeState() {
		const count = brikpanelSelectedRowCount();
		$selectedCount.textContent = (_ordersI18n.selected_count || '%d').replace('%d', String(count));
		$selectedCount.hidden = count === 0;
		if (!$mergeButton) return;
		const enough = brikpanelSelectedRowCount() >= 2;
		$mergeButton.disabled = !enough;
		$mergeButton.classList.toggle('is-disabled', !enough);
		$mergeButton.title = enough ? '' : (_ordersI18n.merge_needs_two || '');
	}

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
			updateMergeState();
		});
	});

	/* Catches the selections that never fire `change` — shift-click ranges. */
	document.querySelector('#the-list')?.addEventListener('click', () => {
		window.requestAnimationFrame(updateMergeState);
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
			updateMergeState();
		} else {
			document.querySelectorAll('.check-column input[name="post[]"], .check-column input[name="id[]"]').forEach($cb => {
				$cb.checked = false;
			});
			checked = [];
			document.querySelector('.brikpanel-bulk-actions')?.classList.remove('show');
			document.querySelector('.bulkactions-bg')?.classList.remove('show');
		}
		updateMergeState();
	});

	updateMergeState();

	const closeMenu = () => {
		$bulkActions.classList.remove('menu-open');
		$menuToggle.setAttribute('aria-expanded', 'false');
	};
	$menuToggle.addEventListener('click', (event) => {
		event.stopPropagation();
		const open = !$bulkActions.classList.contains('menu-open');
		$bulkActions.classList.toggle('menu-open', open);
		$menuToggle.setAttribute('aria-expanded', String(open));
	});
	$menu.addEventListener('click', () => closeMenu());
	document.addEventListener('click', (event) => {
		if (!$bulkActions.contains(event.target)) closeMenu();
	});
	document.addEventListener('keydown', (event) => {
		if (event.key === 'Escape' && $bulkActions.classList.contains('menu-open')) {
			closeMenu();
			$menuToggle.focus();
		}
	});

	const syncCompact = () => {
		const $card = document.querySelector('#wc-orders-filter') ?? document.querySelector('#posts-filter');
		const available = window.matchMedia('(max-width: 520px)').matches
			? window.innerWidth - 24
			: Math.max(240, ($card ? $card.clientWidth : window.innerWidth) - 24);
		$bulkActions.style.maxWidth = `${available}px`;
		$bulkActions.classList.remove('is-compact');
		const natural = $selectedCount.getBoundingClientRect().width + $menu.scrollWidth + 48;
		const compact = natural > available;
		$bulkActions.classList.toggle('is-compact', compact);
		if (!compact) closeMenu();
	};
	syncCompact();
	window.addEventListener('resize', syncCompact);
	if (document.fonts && document.fonts.ready) document.fonts.ready.then(syncCompact);
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

	// Lay out the order number cell. Only the order link and the preview button
	// go into the flex row (link on the left, preview on the right). Any other
	// content in the cell — e.g. meta that other plugins add to the order column
	// such as an invoice number or a "Chat on WhatsApp" link — is left in normal
	// flow below the row, so it stacks inside the column instead of being spread
	// out by the row's space-between and mistaken for separate columns.
	document.querySelectorAll('.wp-list-table tbody .column-order_number').forEach($cell => {
		if ($cell.querySelector('.brikpanel-order-number')) return; // already laid out

		const $view = $cell.querySelector('.order-view');
		if (!$view) return;

		const $row = makeElement('div', { class: 'brikpanel-order-number' });
		$view.before($row);
		$row.append($view);

		const $preview = $cell.querySelector('.order-preview');
		if ($preview) $row.append($preview);
	});
}

/**
 * Compact order list: an arrow in each order number cell opens a detail panel
 * (addresses, phone, items, actions) in a row of its own under the order.
 *
 * The panel markup is printed as an inert <template> in the Customer cell by
 * front-end/orders/brikpanel-orders-compact.php, because WooCommerce offers no
 * hook after a row. The row is built on first open and only shown/hidden after.
 */
function brikpanelCompactRows() {
	if (!document.body.classList.contains('bp-orders-compact')) return;

	const $table = document.querySelector('.wp-list-table');
	const $list = $table?.querySelector('#the-list');
	if (!$list) return;

	const labelShow = _ordersI18n.show_details || '';
	const labelHide = _ordersI18n.hide_details || '';
	const svgNS = 'http://www.w3.org/2000/svg';

	const makeChevron = () => {
		const $svg = document.createElementNS(svgNS, 'svg');
		$svg.setAttribute('viewBox', '0 0 20 20');
		$svg.setAttribute('width', '16');
		$svg.setAttribute('height', '16');
		$svg.setAttribute('aria-hidden', 'true');
		$svg.setAttribute('focusable', 'false');
		const $path = document.createElementNS(svgNS, 'path');
		$path.setAttribute('d', 'M7.5 5l5 5-5 5');
		$path.setAttribute('fill', 'none');
		$path.setAttribute('stroke', 'currentColor');
		$path.setAttribute('stroke-width', '1.75');
		$path.setAttribute('stroke-linecap', 'round');
		$path.setAttribute('stroke-linejoin', 'round');
		$svg.append($path);
		return $svg;
	};

	/* The short row shows only these columns. Every other column (WooCommerce's
	   Origin and Actions, other plugins' columns) keeps rendering, so Screen
	   Options and those plugins keep working, but its content moves into the
	   panel and appears when the order is opened. */
	const ROW_COLUMNS = new Set(['cb', 'order_number', 'brikpanel_whatsapp', 'brikpanel_customer', 'order_date', 'order_status', 'payment_method', 'brikpanel_shipping_method', 'order_total']);
	// Columns whose content never moves to the panel.
	const DROPPED_COLUMNS = new Set();
	// Columns the user keeps in the row ("Show in the row" in Screen Options).
	const rowColumnsConfig = window.brikpanelOrdersOverview?.row_columns;
	const pinned = new Set(Array.isArray(rowColumnsConfig) ? rowColumnsConfig.map(String) : []);
	// Phones show a fixed card, so every extra column opens in the panel there.
	const phoneQuery = window.matchMedia('(max-width: 782px)');
	const inRow = key => pinned.has(key) && !phoneQuery.matches;

	const columnKey = $cell => {
		const name = [...$cell.classList].find(cls => cls.startsWith('column-'));
		return name ? name.slice('column-'.length) : '';
	};

	const headerLabel = $th => {
		if (!$th) return '';
		const $copy = $th.cloneNode(true);
		$copy.querySelectorAll('.screen-reader-text, .sorting-indicators, .sorting-indicator, input').forEach($el => $el.remove());
		return $copy.textContent.replace(/\s+/g, ' ').trim();
	};

	const hasContent = $node => $node.textContent.trim() !== '' || !!$node.querySelector('img, svg, a, button, input, select, [style*="background"]'); // i18n-ignore: CSS selector

	const $headRow = $table.querySelector('thead tr'); // i18n-ignore: CSS selector
	const extraColumns = [];
	for (const $th of $headRow?.children || []) {
		const key = columnKey($th);
		if (!key || ROW_COLUMNS.has(key) || $th.classList.contains('check-column')) continue;
		extraColumns.push({ key, label: headerLabel($th), $th });
	}
	const totalLabel = headerLabel($headRow?.querySelector('.column-order_total'));
	const dateLabel = headerLabel($headRow?.querySelector('.column-order_date'));

	// Columns currently shown (Screen Options can hide some), so the panel
	// spans the whole row.
	// Counted by rendered width: narrow screens hide some columns with CSS.
	const visibleColumns = () => [...$table.querySelectorAll('thead tr:first-child > *')].filter($th => $th.getBoundingClientRect().width > 0).length || 1;
	const syncColspans = () => {
		const span = String(visibleColumns());
		$list.querySelectorAll(':scope > tr.bp-order-detail > td').forEach($cell => {
			if ($cell.getAttribute('colspan') !== span) $cell.setAttribute('colspan', span);
		});
	};

	const makeExtra = (key, label, extraClass = '') => {
		// The column class lets WordPress's Screen Options show/hide the item
		// together with its column.
		const $item = makeElement('div', { class: `bp-od-extra ${key ? 'column-' + key : ''} ${extraClass}`.trim() });
		$item.append(makeElement('div', { class: 'bp-od-label' }, { textContent: label }));
		const $value = makeElement('div', { class: 'bp-od-extra-value' });
		$item.append($value);
		return [$item, $value];
	};

	const extrasByRow = new WeakMap();
	const movesByRow = new WeakMap();
	// Rows whose panel has been built; only those hold column content.
	const builtRows = new WeakSet();
	// Panel item -> the cell it came from. WordPress's Screen Options only
	// updates the column classes of elements in the page, and a panel that was
	// never opened is not, so the item copies the cell's state when shown.
	const itemSource = new WeakMap();

	$list.querySelectorAll(':scope > tr').forEach($row => {
		const $cell = $row.querySelector('.column-order_number');
		if (!$cell || !$row.querySelector('template.bp-order-detail-tpl') || $cell.querySelector('.bp-order-toggle')) return;

		const id = ($row.id || '').replace(/^(order|post)-/, '');
		const $button = makeElement('button', {
			type: 'button',
			class: 'bp-order-toggle',
			'aria-expanded': 'false',
			'aria-controls': `bp-order-detail-${id}`,
			'aria-label': labelShow,
			title: labelShow,
		});
		$button.append(makeChevron());
		($cell.querySelector('.brikpanel-order-number') || $cell).prepend($button);

		const extras = [];
		const moves = [];
		extraColumns.forEach(({ key, label }) => {
			const $source = $row.querySelector(`:scope > .column-${CSS.escape(key)}`);
			if (!$source) return;
			if (DROPPED_COLUMNS.has(key)) {
				moves.push({ key, $source, $item: null, $value: null });
				return;
			}
			// The content stays in its (hidden) cell until the panel is first
			// opened: other plugins that bind their buttons or fill the cell once
			// the page is ready still find it in the page.
			const [$item, $value] = makeExtra(key, label);
			if ($source.classList.contains('hidden')) $item.classList.add('hidden');
			itemSource.set($item, $source);
			moves.push({ key, $source, $item, $value });
			extras.push($item);
		});
		movesByRow.set($row, moves);

		// Anything other plugins print next to the amount (e.g. a currency
		// note) would make the short row tall; it moves to the panel too.
		const $total = $row.querySelector(':scope > .column-order_total');
		if ($total) {
			// WooCommerce wraps the amount in a tooltip span; notes can sit inside it.
			const candidates = [...$total.children].flatMap($child => ($child.matches('.tips') ? [...$child.children] : [$child]));
			const notes = candidates.filter($child =>
				!$child.matches('.amount, .woocommerce-Price-amount, del, ins, .tips, .screen-reader-text, .toggle-row')
				&& !$child.querySelector('.amount, .woocommerce-Price-amount'));
			if (notes.length) {
				const [$item, $value] = makeExtra('', totalLabel);
				notes.forEach($note => $value.append($note));
				extras.push($item);
			}
		}

		// Phones leave the date out of the short card; it shows in the panel.
		const $date = $row.querySelector(':scope > .column-order_date');
		if ($date && hasContent($date)) {
			const [$item, $value] = makeExtra('', dateLabel, 'bp-od-extra--phone');
			$value.append(...[...$date.childNodes].map($node => $node.cloneNode(true)));
			extras.push($item);
		}

		extrasByRow.set($row, extras);
	});

	const buildPanel = ($row, detailId) => {
		const $template = $row.querySelector('template.bp-order-detail-tpl');
		if (!$template) return null;
		// "no-link": WooCommerce opens the order when a row cell is clicked;
		// clicks inside the panel must not.
		const $cell = makeElement('td', { class: 'no-link', colspan: String(visibleColumns()) });
		const $wrap = makeElement('div', { class: 'bp-order-detail-wrap' });
		$wrap.append($template.content.cloneNode(true));

		builtRows.add($row);
		placeRow($row);
		const extras = extrasByRow.get($row) || [];
		const $card = $wrap.querySelector('.bp-od');
		if ($card && extras.length) {
			const $extras = makeElement('div', { class: 'bp-od-extras' });
			$extras.append(...extras);
			$card.append($extras);
			syncExtras($extras);
		}

		$cell.append($wrap);
		const $detail = makeElement('tr', { class: 'bp-order-detail', id: detailId });
		$detail.append($cell);
		$row.after($detail);
		return $detail;
	};

	// The extras block hides when every item sits in the row, and shows only on
	// narrow widths when all that is left is the date.
	const syncExtras = $extras => {
		[...$extras.children].forEach($item => {
			const $source = itemSource.get($item);
			if ($source) $item.classList.toggle('hidden', $source.classList.contains('hidden'));
		});
		const live = [...$extras.children].filter($item => !['bp-od-extra--in-row', 'bp-od-extra--empty', 'hidden'].some(cls => $item.classList.contains(cls)));
		$extras.hidden = live.length === 0;
		$extras.classList.toggle('bp-od-extras--phone', live.length > 0 && live.every($item => $item.classList.contains('bp-od-extra--phone')));
	};

	// Put each extra column's content where it belongs: in its own cell when the
	// user keeps the column in the row, otherwise in the order's panel. Content is
	// moved, never copied, so other plugins' buttons keep their handlers.
	const placeRow = $row => {
		const moves = movesByRow.get($row);
		if (!moves) return;
		const built = builtRows.has($row);
		moves.forEach(({ key, $source, $item, $value }) => {
			const row = inRow(key);
			$source.classList.toggle('bp-col-extra', !row);
			if (!$item) return;
			if (row) {
				while ($value.firstChild) $source.append($value.firstChild);
			} else if (built) {
				while ($source.firstChild) $value.append($source.firstChild);
			}
			$item.classList.toggle('bp-od-extra--in-row', row);
			$item.classList.toggle('bp-od-extra--empty', !row && built && !hasContent($value));
		});
	};

	const applyPlacement = () => {
		extraColumns.forEach(({ key, $th }) => $th.classList.toggle('bp-col-extra', !inRow(key)));
		$list.querySelectorAll(':scope > tr').forEach($row => {
			if (!movesByRow.has($row)) return;
			placeRow($row);
			const $extras = document.getElementById(`bp-order-detail-${($row.id || '').replace(/^(order|post)-/, '')}`)?.querySelector('.bp-od-extras');
			if ($extras) syncExtras($extras);
		});
		syncColspans();
		document.dispatchEvent(new CustomEvent('brikpanel:order-columns-changed'));
	};
	applyPlacement();
	phoneQuery.addEventListener?.('change', applyPlacement);

	// Screen Options "Show in the row" boxes: apply at once, then save for the user.
	const $rowPrefs = document.querySelector('#screen-options-wrap .bp-row-columns');
	if ($rowPrefs) {
		// Right under WordPress's own Columns list.
		const $columnPrefs = $rowPrefs.parentElement?.querySelector(':scope > .metabox-prefs:not(.bp-row-columns)');
		if ($columnPrefs) $columnPrefs.after($rowPrefs);

		const $error = makeElement('p', { class: 'bp-row-columns__error', role: 'alert', hidden: '' });
		$rowPrefs.append($error);
		const $boxes = [...$rowPrefs.querySelectorAll('.bp-row-column-tog')];

		// One request at a time, always carrying the latest choice, so quick
		// clicks can never reach the server out of order and store an old set.
		let saved = new Set(pinned);
		let busy = false;
		let dirty = false;
		const save = () => {
			if (busy) {
				dirty = true;
				return;
			}
			busy = true;
			dirty = false;
			const sent = new Set(pinned);
			const body = new URLSearchParams({ action: 'brikpanel_orders_row_columns', nonce: $rowPrefs.dataset.nonce || '' });
			sent.forEach(key => body.append('columns[]', key));
			fetch(window.brikpanelOrdersOverview?.ajax_url || '', { method: 'POST', credentials: 'same-origin', body })
				.then(response => response.json())
				.then(result => {
					if (!result?.success) throw new Error();
					saved = sent;
				})
				.catch(() => {
					// Put back what is stored, so the page never shows a choice that
					// will be gone after a reload.
					dirty = false;
					pinned.clear();
					saved.forEach(key => pinned.add(key));
					$boxes.forEach($box => { $box.checked = pinned.has($box.value); });
					applyPlacement();
					$error.textContent = _ordersI18n.row_columns_error || '';
					$error.hidden = false;
				})
				.finally(() => {
					busy = false;
					if (dirty) save();
				});
		};

		$rowPrefs.addEventListener('change', event => {
			const $box = event.target;
			if (!$box.matches?.('.bp-row-column-tog')) return;
			if ($box.checked) pinned.add($box.value);
			else pinned.delete($box.value);
			applyPlacement();
			$error.hidden = true;
			save();
		});
	}

	const toggleRow = ($row, $button) => {
		const open = $button.getAttribute('aria-expanded') !== 'true';
		const detailId = $button.getAttribute('aria-controls');
		let $detail = document.getElementById(detailId);
		if (open && !$detail) {
			$detail = buildPanel($row, detailId);
			if (!$detail) return;
		}
		if ($detail) $detail.hidden = !open;
		$row.classList.toggle('bp-order-open', open);
		$button.setAttribute('aria-expanded', String(open));
		$button.setAttribute('aria-label', open ? labelHide : labelShow);
		$button.title = open ? labelHide : labelShow;
	};

	$list.addEventListener('click', event => {
		const $button = event.target.closest('.bp-order-toggle');
		if ($button && $list.contains($button)) {
			event.preventDefault();
			toggleRow($button.closest('tr'), $button);
			return;
		}

		// On phones the whole card opens its details, like the arrow. Links,
		// the checkbox and the status badge keep their own behaviour.
		if (!phoneQuery.matches) return;
		const $row = event.target.closest('#the-list > tr');
		if (!$row || $row.classList.contains('bp-order-detail')) return;
		if (event.target.closest('a, button, input, label, select, textarea, .check-column, .order-status, .row-actions')) return;
		const $rowButton = $row.querySelector('.bp-order-toggle');
		if (!$rowButton) return;
		event.stopPropagation(); // keeps WooCommerce from opening the order
		toggleRow($row, $rowButton);
	});

	// Screen Options: keep open panels spanning the row after a column is
	// shown or hidden. WordPress updates the header in its own change handler.
	document.addEventListener('change', event => {
		if (!event.target.matches?.('.hide-column-tog')) return;
		setTimeout(() => {
			const span = String(visibleColumns());
			$list.querySelectorAll(':scope > tr.bp-order-detail > td').forEach($cell => $cell.setAttribute('colspan', span));
			$list.querySelectorAll(':scope > tr.bp-order-detail .bp-od-extras').forEach(syncExtras);
		});
	});

	// The panel stays in view while the table scrolls sideways: it is sticky
	// to the left edge and as wide as the visible part of the table.
	const $container = $table.closest('.wp-list-table-container');
	if ($container) {
		const syncWidth = () => {
			$table.style.setProperty('--bp-detail-width', `${$container.clientWidth}px`);
			syncColspans();
		};
		syncWidth();
		if ('ResizeObserver' in window) new ResizeObserver(syncWidth).observe($container);
	}
}

/**
 * Keep the order number column, and any column in front of it, pinned to the
 * left edge while the table scrolls sideways.
 *
 * The stylesheet assumes only the checkbox sits before the order number, which
 * is not true once another column (e.g. WhatsApp) is placed there: the order
 * number then stuck at the wrong offset and the column in between slid under
 * it. Offsets are measured from the header instead.
 */
function brikpanelStickyOrderColumn() {
	const $table = document.querySelector('.wp-list-table');
	const $head = $table?.querySelector('thead .column-order_number');
	// Right-to-left admin turns sticky columns off (assets/css/brikpanel-rtl.css).
	if (!$head || document.body.classList.contains('rtl')) return;

	const $style = document.createElement('style');
	$style.id = 'brikpanel-sticky-order-columns';
	document.head.append($style);

	const sync = () => {
		const rules = [];
		let left = 0;
		for (const $cell of $head.parentElement.children) {
			if ($cell.classList.contains('hidden') || $cell.classList.contains('bp-col-extra')) continue;
			const key = [...$cell.classList].find(name => name.startsWith('column-'));
			if ($cell === $head || (key && !$cell.classList.contains('check-column') && key !== 'column-cb')) {
				if (key && /^column-[a-z0-9_-]+$/i.test(key)) {
					rules.push(`table.wp-list-table .${key}{position:sticky!important;left:${Math.floor(left)}px!important;}`);
					if ($cell !== $head) {
						rules.push(`table.wp-list-table tbody .${key}{background-color:#fff;z-index:1;}`, `table.wp-list-table thead .${key}{background-color:#f7f7f7;}`);
					}
				}
			}
			if ($cell === $head) break;
			left += $cell.getBoundingClientRect().width;
		}
		$style.textContent = rules.join('\n');
	};

	sync();
	if ('ResizeObserver' in window) {
		const observer = new ResizeObserver(sync);
		for (const $cell of $head.parentElement.children) {
			observer.observe($cell);
			if ($cell === $head) break;
		}
	}
	document.addEventListener('change', event => {
		if (event.target.matches?.('.hide-column-tog')) setTimeout(sync);
	});
	document.addEventListener('brikpanel:order-columns-changed', sync);
}

/**
 * Visible text length of a subtree: whitespace collapsed, screen-reader-only
 * labels and icon media ignored.
 *
 * An icon button's accessible name normally lives in a .screen-reader-text span
 * (and an inline icon's in <svg><title>), so counting either would misread every
 * properly labelled icon button as a text button.
 *
 * @param {Element}      $node   Subtree root.
 * @param {Set<Element>} [$skip] Elements whose whole subtree is left out: the
 *                               icons themselves, when what matters is the text
 *                               around them rather than the labels they hide.
 * @returns {number} Number of visible characters.
 */
function brikpanelIconbarTextLength($node, $skip = null) {
	let length = 0;

	for (const $child of $node.childNodes) {
		if ($child.nodeType === Node.TEXT_NODE) {
			length += $child.nodeValue.replace(/\s+/g, ' ').trim().length;
			continue;
		}

		if ($child.nodeType !== Node.ELEMENT_NODE || $skip?.has($child)) continue;

		if ($child.classList.contains('screen-reader-text')
			|| $child.localName === 'svg'
			|| $child.localName === 'script'
			|| $child.localName === 'style'
			|| $child.localName === 'template') {
			continue;
		}

		length += brikpanelIconbarTextLength($child, $skip);
	}

	return length;
}

/**
 * How a control shows its icon, or null when it is not an icon-only control.
 *
 * - 'media': an <img> or <svg> inside, and no visible label.
 * - 'bg': the icon is a CSS background image. Plugins that draw icons this way
 *   usually still print a label for screen readers and hide it visually with
 *   `font-size: 0` or the classic far-negative `text-indent`, so the control
 *   only counts when that label really is hidden: a text button that merely
 *   has a decorative background stays a text button. "PDF Invoices and Packing
 *   Slips For WooCommerce" by Acowebs renders its Documents column exactly like
 *   this, and was the plugin behind the original report.
 *
 * Only computed style is read, and none of these properties depend on layout,
 * so this never forces a reflow.
 *
 * @param {Element} $control
 * @returns {'media'|'bg'|null}
 */
function brikpanelIconbarIconKind($control) {
	const hasLabel = brikpanelIconbarTextLength($control) > 0;

	if (!hasLabel && $control.querySelector('img, svg')) return 'media';

	const style = getComputedStyle($control);
	if (!style.backgroundImage.includes('url(')) return null;

	const hidesLabel = parseFloat(style.fontSize) === 0 || parseFloat(style.textIndent) <= -999;

	return !hasLabel || hidesLabel ? 'bg' : null;
}

/**
 * Reads one cell and returns the icon run this pass can lay out, or null when
 * the cell is not one: no run, a labelled control among the icons, copy of its
 * own, or a container between an icon and the cell that cannot be made
 * inline-level.
 *
 * It is all or nothing on purpose. Half-treating a cell -- squaring the icons
 * off but leaving them stacked because they sit in list items -- buys the
 * merchant nothing and costs them a narrower column, so such a cell is better
 * left exactly as the other plugin rendered it.
 *
 * @param {HTMLTableCellElement} $cell Body cell of a column BrikPanel does not own.
 * @returns {{$cell: HTMLTableCellElement, $items: Element[], kinds: string[], $wraps: Element[]}|null}
 */
function brikpanelIconbarReadCell($cell) {
	if ($cell.classList.contains('brikpanel-iconbar-cell')) return null;

	const $items = [];
	const kinds = [];

	for (const $control of $cell.querySelectorAll('a[href], button')) {
		// WordPress' hover links ("Edit | Trash"), the order preview button and
		// the mobile row toggle are already laid out by core and by BrikPanel.
		if ($control.closest('.row-actions, .order-preview, .toggle-row')) continue;

		// A control nested inside another control is one visual thing.
		if ($control.parentElement?.closest('a[href], button')) continue;

		// One control that is not an icon rules the whole cell out, because
		// squaring a text button off at 2rem would cut its label in half.
		const kind = brikpanelIconbarIconKind($control);
		if (!kind) return null;

		$items.push($control);
		kinds.push(kind);
	}

	if ($items.length < BP_ICONBAR_MIN_ITEMS) return null;

	// The labels background icons hide for screen readers are not text anyone
	// sees, so they count neither as the cell's own copy nor as a wrapper's.
	const $icons = new Set($items);

	// A cell with real copy of its own is a content column that happens to carry
	// a couple of icons. Pinning it to the width of two icons would be a worse
	// problem than the one being fixed.
	if (brikpanelIconbarTextLength($cell, $icons) > BP_ICONBAR_MAX_TEXT) return null;

	const $wraps = [];

	// Every container between an icon and the cell has to be one this pass can
	// make inline-level. Anything else -- a list item, a table, a form control --
	// ends the attempt: changing its outer display would change what it means or
	// drop its marker, and leaving it alone would leave the run stacked anyway.
	for (const $item of $items) {
		for (let $wrap = $item.parentElement; $wrap && $wrap !== $cell; $wrap = $wrap.parentElement) {
			if (($wrap.localName !== 'div' && $wrap.localName !== 'p')
				|| brikpanelIconbarTextLength($wrap, $icons) > 0) {
				return null;
			}

			$wraps.push($wrap);
		}
	}

	return { $cell, $items, kinds, $wraps };
}

/**
 * Lays out third-party icon-action columns ("Documents", "Labels", "Invoices"...)
 * as a compact grid instead of a one-icon-per-line stack.
 *
 * BrikPanel pins the table to the width of its scroll container and clears every
 * per-column width, so a column it does not know about gets squeezed towards its
 * min-content width. A cell holding nothing but icon links then breaks that run
 * one icon per line, and the row grows several times taller than it needs to be.
 * A merchant running many columns at once reported exactly this.
 *
 * Nothing is moved, replaced or re-emitted: the other plugin's markup keeps its
 * exact position in the DOM, so its event handlers, its tooltips, its
 * target="_blank" links and any CSS it scopes to `td.column-x > div > a` all keep
 * working. This only adds marker classes and one custom property; the layout
 * itself lives in CSS (see "THIRD-PARTY ICON-ACTION COLUMNS" in
 * brikpanel-orders.css).
 *
 * Every column is read before anything is written, and the reads are DOM walks
 * plus layout-independent computed style, so the pass costs at most one style
 * recalculation and never a reflow.
 */
function brikpanelIconActionColumns() {
	const $table = document.querySelector('.wp-list-table');
	if (!$table) return;

	const plans = [];

	// Which columns are not ours? Read once from the header row. On a store with
	// no third-party order columns -- the overwhelming majority -- this loop is
	// the entire cost of the feature.
	for (const $th of $table.querySelectorAll('thead th.manage-column')) { // i18n-ignore: CSS selector, not user-facing text
		for (const name of $th.classList) {
			if (!name.startsWith('column-')) continue;

			const column = name.slice('column-'.length);
			if (!column || BP_ICONBAR_KNOWN_COLUMNS.has(column)) continue;

			const runs = [];
			let longest = 0;

			for (const $cell of $table.querySelectorAll(`tbody td.column-${CSS.escape(column)}`)) {
				const run = brikpanelIconbarReadCell($cell);
				if (!run) continue;

				runs.push(run);
				longest = Math.max(longest, run.$items.length);
			}

			if (runs.length === 0) continue;

			// One width for the whole column. A table column is as wide as its
			// widest cell, so deciding this per cell would let a long run in one
			// row silently re-pack every other row -- a four-icon cell would
			// break 3 + 1 because some other row asked for three columns. The
			// grid is two icons wide as a rule, and widens only so that a very
			// long run cannot tower over the rest of the table.
			const cols = String(Math.min(
				Math.max(BP_ICONBAR_COLS, Math.ceil(longest / BP_ICONBAR_MAX_ROWS)),
				BP_ICONBAR_MAX_COLS
			));

			plans.push({ $th, runs, cols });
		}
	}

	// Only now write. A class added for one column would otherwise invalidate
	// style, and the next column's computed-style reads would recalculate it.
	for (const { $th, runs, cols } of plans) {
		for (const { $cell, $items, kinds, $wraps } of runs) {
			for (const $wrap of $wraps) $wrap.classList.add('brikpanel-iconbar-wrap');

			$items.forEach(($item, index) => {
				$item.classList.add('brikpanel-iconbar-item', `brikpanel-iconbar-item--${kinds[index]}`);
			});

			$cell.classList.add('brikpanel-iconbar-cell');
			$cell.style.setProperty('--bp-iconbar-cols', cols);
		}

		// A long header label would otherwise hold the narrowed column open on
		// its own, and the blanket nowrap on thead th is meant for the columns
		// BrikPanel lays out itself. Columns this pass left alone keep the
		// header they had.
		$th.classList.add('brikpanel-iconbar-col');
	}
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
	document.querySelector('.wp-list-table tfoot')?.remove();
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
