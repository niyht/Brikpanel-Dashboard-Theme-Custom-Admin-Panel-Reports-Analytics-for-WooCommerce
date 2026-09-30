(function () {
	'use strict';

	if (typeof window.brikpanelSegments === 'undefined') {
		return;
	}

	const CFG = window.brikpanelSegments;
	const I18N = CFG.i18n || {};
	const ROOT = document.getElementById('brikpanel-segments');
	if (!ROOT) return;

	// -----------------------------------------------------------------------
	// State
	// -----------------------------------------------------------------------

	const state = {
		tab: 'orders',
		page: 1,
		sort: '',
		order: 'desc',
		preset: '',
		// Filters mirror server-side names so we can POST them directly.
		filters: emptyFilters(),
		// Loaded lookups.
		options: null,
		// Selected products for the picker (value + label).
		selectedProducts: [],
		lastRequestId: 0,
	};

	function emptyFilters() {
		return {
			date_from: '',
			date_to: '',
			statuses: [],
			payment_methods: [],
			countries: [],
			city: '',
			total_min: '',
			total_max: '',
			spent_min: '',
			spent_max: '',
			order_count_min: '',
			order_count_max: '',
			last_order_from: '',
			last_order_to: '',
			registered_from: '',
			registered_to: '',
			coupon: '',
			product_ids: [],
			category_ids: [],
			rfm_segments: [],
			search: '',
		};
	}

	// -----------------------------------------------------------------------
	// Preset definitions per tab
	// -----------------------------------------------------------------------

	const PRESETS = {
		orders: [
			// Date
			{ key: '', label: I18N.preset_all || 'All' },
			{ key: 'today', label: I18N.preset_today || 'Today' },
			{ key: 'last7', label: I18N.preset_last7 || 'Last 7 days' },
			{ key: 'last30', label: I18N.preset_last30 || 'Last 30 days' },
			{ key: 'last90', label: I18N.preset_last90 || 'Last 90 days' },
			// Status
			{ key: 'processing', label: I18N.preset_processing || 'Processing' },
			{ key: 'completed', label: I18N.preset_completed || 'Completed' },
			{ key: 'pending', label: I18N.preset_pending || 'Pending payment' },
			{ key: 'on_hold', label: I18N.preset_on_hold || 'On hold' },
			{ key: 'refunded', label: I18N.preset_refunded || 'Refunded' },
			{ key: 'cancelled', label: I18N.preset_cancelled || 'Cancelled' },
			{ key: 'returns', label: I18N.preset_returns || 'Returns' },
			// Shipping
			{ key: 'free_shipping', label: I18N.preset_free_shipping || 'Free shipping' },
			{ key: 'paid_shipping', label: I18N.preset_paid_shipping || 'Paid shipping' },
			// Value
			{ key: 'high_value', label: I18N.preset_high_value || 'High value' },
		],
		customers: [
			{ key: '', label: I18N.preset_all || 'All' },
			{ key: 'new_customers', label: I18N.preset_new_customers || 'New (30 days)' },
			{ key: 'repeat', label: I18N.preset_repeat || 'Repeat buyers' },
			{ key: 'vip', label: I18N.preset_vip || 'VIP (5+ orders)' },
			{ key: 'one_time', label: I18N.preset_one_time || 'One-time buyers' },
			{ key: 'dormant', label: I18N.preset_dormant || 'Dormant (90+ days)' },
			{ key: 'high_value', label: I18N.preset_high_value || 'High value' },
		],
	};

	// -----------------------------------------------------------------------
	// Helpers
	// -----------------------------------------------------------------------

	function el(id) { return document.getElementById(id); }
	function escape(str) {
		return String(str || '').replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	}

	function debounce(fn, wait) {
		let t;
		return function () {
			const args = arguments, ctx = this;
			clearTimeout(t);
			t = setTimeout(function () { fn.apply(ctx, args); }, wait);
		};
	}

	function post(action, data) {
		const body = new URLSearchParams();
		body.append('action', action);
		body.append('_ajax_nonce', CFG.nonce);
		Object.keys(data || {}).forEach(function (k) {
			const v = data[k];
			if (Array.isArray(v)) {
				v.forEach(function (item) { body.append(k + '[]', item); });
			} else if (v !== undefined && v !== null && v !== '') {
				body.append(k, v);
			}
		});
		return fetch(CFG.ajax_url, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString(),
		}).then(function (r) { return r.json(); });
	}

	// -----------------------------------------------------------------------
	// Chips (presets)
	// -----------------------------------------------------------------------

	function renderChips() {
		const holder = el('bp-seg-chips');
		const list = PRESETS[state.tab] || [];
		holder.innerHTML = list.map(function (p) {
			const active = p.key === state.preset ? ' is-active' : '';
			return '<button type="button" class="bp-seg-chip' + active + '" data-preset="' + escape(p.key) + '">' + escape(p.label) + '</button>';
		}).join('');
	}

	// -----------------------------------------------------------------------
	// Filter options (load once)
	// -----------------------------------------------------------------------

	function loadFilterOptions() {
		return post('brikpanel_segments_filter_options', {}).then(function (res) {
			if (!res || !res.success) return;
			state.options = res.data;
			// Statuses as chip-style multi
			renderMultiChips('bp-seg-statuses', res.data.statuses || [], state.filters.statuses, function (values) {
				state.filters.statuses = values;
				runQuery();
			});
			// Countries as native multiselect
			fillSelect('bp-seg-country', res.data.countries || [], state.filters.countries);
			fillSelect('bp-seg-payment', res.data.payment_methods || [], state.filters.payment_methods);
			fillSelect('bp-seg-categories', res.data.categories || [], state.filters.category_ids);
			fillSelect('bp-seg-rfm', res.data.rfm_segments || [], state.filters.rfm_segments);
		});
	}

	function renderMultiChips(containerId, items, selected, onChange) {
		const host = el(containerId);
		if (!host) return;
		host.innerHTML = items.map(function (it) {
			const isActive = selected.indexOf(it.value) !== -1 ? ' is-active' : '';
			return '<span class="bp-seg-multi-chip' + isActive + '" data-value="' + escape(it.value) + '">' + escape(it.label) + '</span>';
		}).join('');
		host.querySelectorAll('.bp-seg-multi-chip').forEach(function (chip) {
			chip.addEventListener('click', function () {
				const v = chip.dataset.value;
				const idx = selected.indexOf(v);
				if (idx === -1) selected.push(v); else selected.splice(idx, 1);
				chip.classList.toggle('is-active');
				onChange(selected.slice());
			});
		});
	}

	function fillSelect(selectId, items, selected) {
		const sel = el(selectId);
		if (!sel) return;
		sel.innerHTML = items.map(function (it) {
			const isSelected = selected.indexOf(String(it.value)) !== -1 || selected.indexOf(it.value) !== -1 ? ' selected' : '';
			return '<option value="' + escape(it.value) + '"' + isSelected + '>' + escape(it.label) + '</option>';
		}).join('');
	}

	// -----------------------------------------------------------------------
	// Fit: table or stacked cards
	// -----------------------------------------------------------------------
	// Rows turn into stacked cards when the table cannot show every column
	// inside its card (field test B2: Total vanished at 1280px). Measured
	// rather than guessed, because the width depends on the language and on
	// store data such as custom order statuses and payment method titles.

	// The shared helper (front-end/shared/brikpanel-fit-table.js) measures an
	// invisible copy and watches the width; below 720px it stacks regardless.
	const TABLE = el('bp-seg-table');
	const FIT = (TABLE && window.brikpanelFitTable) ? window.brikpanelFitTable(TABLE, { floor: 720 }) : null;

	// After every render: the rows changed, so they are measured again.
	function fitTable() {
		if (FIT) FIT.refit();
	}

	// Every tbody change goes through here so the fit never lags a render.
	function setBody(html) {
		el('bp-seg-tbody').innerHTML = html;
		fitTable();
	}

	function cellLabel(text) {
		return ' data-bp-label="' + escape(text) + '"';
	}

	// Left-to-right data (phone numbers, addresses) keeps its order on a
	// right-to-left page. Addresses may break after "@" and before a dot, never
	// inside a word; an unbroken address used to hold its column wide open.
	function ltrHtml(text) {
		return '<span dir="ltr">' + escape(text) + '</span>';
	}

	function emailHtml(email) {
		return '<span dir="ltr">' + escape(email).replace(/@/g, '@<wbr>').replace(/\./g, '<wbr>.') + '</span>';
	}

	// -----------------------------------------------------------------------
	// Table rendering
	// -----------------------------------------------------------------------

	// An empty page with nothing narrowing it (and no rows on other pages)
	// means the store has nothing to list yet; "match these filters" would send
	// the merchant hunting for a filter that is not there.
	function emptyRow(data, narrowed, filteredText, noneText) {
		const text = (narrowed || Number(data.total) > 0) ? filteredText : noneText;
		return '<tr><td class="bp-seg-empty" colspan="8">' + escape(text) + '</td></tr>';
	}

	function renderOrdersTable(data, narrowed) {
		const L = {
			order: I18N.col_order, date: I18N.col_date, status: I18N.col_status, customer: I18N.col_customer,
			phone: I18N.col_phone, location: I18N.col_location, payment: I18N.col_payment, total: I18N.col_total,
		};
		el('bp-seg-thead').innerHTML =
			'<tr>'
			+ '<th>' + escape(L.order) + '</th>'
			+ '<th>' + escape(L.date) + '</th>'
			+ '<th>' + escape(L.status) + '</th>'
			+ '<th>' + escape(L.customer) + '</th>'
			+ '<th>' + escape(L.phone) + '</th>'
			+ '<th>' + escape(L.location) + '</th>'
			+ '<th>' + escape(L.payment) + '</th>'
			+ '<th class="bp-seg-num">' + escape(L.total) + '</th>'
			+ '</tr>';

		if (!data.items.length) {
			setBody(emptyRow(data, narrowed, I18N.no_results, I18N.no_orders_yet));
			return;
		}

		setBody(data.items.map(function (o) {
			const location = [o.city, o.country].filter(Boolean).join(', ');
			return '<tr>'
				+ '<td class="bp-seg-cell-title bp-seg-order-no"><a class="bp-seg-primary-link" href="' + escape(o.edit_url) + '">#' + escape(o.number || o.id) + '</a></td>'
				+ '<td' + cellLabel(L.date) + '>' + escape(o.date) + '</td>'
				+ '<td' + cellLabel(L.status) + '><span class="bp-seg-status is-' + escape(o.status) + '">' + escape(o.status_label) + '</span></td>'
				+ '<td class="bp-seg-customer"' + cellLabel(L.customer) + '>' + (o.name ? escape(o.name) : '<span class="bp-seg-subtle">' + escape(I18N.guest) + '</span>') + (o.email ? '<div class="bp-seg-subtle">' + emailHtml(o.email) + '</div>' : '') + '</td>'
				+ '<td class="bp-seg-phone"' + cellLabel(L.phone) + '>' + (o.phone ? ltrHtml(o.phone) : '—') + '</td>'
				+ '<td' + cellLabel(L.location) + '>' + escape(location || '—') + '</td>'
				+ '<td' + cellLabel(L.payment) + '>' + escape(o.payment || '—') + '</td>'
				+ '<td class="bp-seg-num bp-seg-cell-headline"' + cellLabel(L.total) + '>' + o.total_display + '</td>'
				+ '</tr>';
		}).join(''));
	}

	function renderCustomersTable(data, narrowed) {
		const L = {
			customer: I18N.col_customer, email: I18N.col_email, phone: I18N.col_phone, registered: I18N.col_registered,
			orders: I18N.col_orders, spent: I18N.col_spent, aov: I18N.col_aov, lastOrder: I18N.col_last_order,
		};
		el('bp-seg-thead').innerHTML =
			'<tr>'
			+ '<th>' + escape(L.customer) + '</th>'
			+ '<th>' + escape(L.email) + '</th>'
			+ '<th>' + escape(L.phone) + '</th>'
			+ '<th>' + escape(L.registered) + '</th>'
			+ '<th class="bp-seg-num">' + escape(L.orders) + '</th>'
			+ '<th class="bp-seg-num">' + escape(L.spent) + '</th>'
			+ '<th class="bp-seg-num">' + escape(L.aov) + '</th>'
			+ '<th>' + escape(L.lastOrder) + '</th>'
			+ '</tr>';

		if (!data.items.length) {
			setBody(emptyRow(data, narrowed, I18N.no_customers, I18N.no_customers_yet));
			return;
		}

		setBody(data.items.map(function (c) {
			const nameCell = c.edit_url
				? '<a class="bp-seg-primary-link" href="' + escape(c.edit_url) + '">' + escape(c.name) + '</a>'
				: escape(c.name) + ' <span class="bp-seg-subtle">(' + escape(I18N.guest) + ')</span>';
			return '<tr>'
				+ '<td class="bp-seg-customer bp-seg-cell-title">' + nameCell + '</td>'
				+ '<td class="bp-seg-customer"' + cellLabel(L.email) + '>' + (c.email ? emailHtml(c.email) : '—') + '</td>'
				+ '<td class="bp-seg-phone"' + cellLabel(L.phone) + '>' + (c.phone ? ltrHtml(c.phone) : '—') + '</td>'
				+ '<td' + cellLabel(L.registered) + '>' + escape(c.registered || '—') + '</td>'
				// String(): escape() drops a bare 0, and a customer with no orders showed an empty cell.
				+ '<td class="bp-seg-num"' + cellLabel(L.orders) + '>' + escape(String(c.order_count || 0)) + '</td>'
				+ '<td class="bp-seg-num bp-seg-cell-headline"' + cellLabel(L.spent) + '>' + c.total_spent_display + '</td>'
				+ '<td class="bp-seg-num"' + cellLabel(L.aov) + '>' + c.aov_display + '</td>'
				+ '<td' + cellLabel(L.lastOrder) + '>' + escape(c.last_order || '—') + '</td>'
				+ '</tr>';
		}).join(''));
	}

	function renderPagination(data) {
		const pag = el('bp-seg-pagination');
		if (!data.total || data.pages <= 1) {
			pag.hidden = true;
			return;
		}
		pag.hidden = false;
		el('bp-seg-page-info').textContent = data.page + ' / ' + data.pages;
		el('bp-seg-prev').disabled = data.page <= 1;
		el('bp-seg-next').disabled = data.page >= data.pages;
	}

	function renderStats(data) {
		el('bp-seg-stat-count').textContent = formatNumber(data.summary.count);
		el('bp-seg-count').textContent = formatNumber(data.summary.count);
		el('bp-seg-stat-revenue').innerHTML = data.summary.revenue_display || '—';
		el('bp-seg-stat-aov').innerHTML = data.summary.aov_display || '—';
		el('bp-seg-stat-revenue-label').textContent = state.tab === 'customers'
			? (I18N.total_spent || 'Total spent')
			: (I18N.total_revenue || 'Total revenue');
	}

	// The store's separators, not the browser's language (field test E2).
	function formatNumber(n) {
		return window.brikpanelFormat ? window.brikpanelFormat.number(n || 0) : String(Number(n) || 0);
	}

	// -----------------------------------------------------------------------
	// Fetch + render
	// -----------------------------------------------------------------------

	function collectFilters() {
		// Mirror DOM -> state.filters so AJAX gets the latest values.
		state.filters.date_from = el('bp-seg-date-from').value;
		state.filters.date_to = el('bp-seg-date-to').value;
		state.filters.city = el('bp-seg-city').value.trim();
		state.filters.coupon = el('bp-seg-coupon').value.trim();
		state.filters.search = el('bp-seg-search').value.trim();
		state.filters.total_min = el('bp-seg-total-min').value;
		state.filters.total_max = el('bp-seg-total-max').value;
		state.filters.spent_min = el('bp-seg-spent-min').value;
		state.filters.spent_max = el('bp-seg-spent-max').value;
		state.filters.order_count_min = el('bp-seg-count-min').value;
		state.filters.order_count_max = el('bp-seg-count-max').value;
		state.filters.last_order_from = el('bp-seg-last-order-from').value;
		state.filters.last_order_to = el('bp-seg-last-order-to').value;
		state.filters.registered_from = el('bp-seg-registered-from').value;
		state.filters.registered_to = el('bp-seg-registered-to').value;

		state.filters.countries = Array.from(el('bp-seg-country').selectedOptions).map(function (o) { return o.value; });
		state.filters.payment_methods = Array.from(el('bp-seg-payment').selectedOptions).map(function (o) { return o.value; });
		state.filters.category_ids = Array.from(el('bp-seg-categories').selectedOptions).map(function (o) { return o.value; });
		const rfmEl = el('bp-seg-rfm');
		state.filters.rfm_segments = rfmEl ? Array.from(rfmEl.selectedOptions).map(function (o) { return o.value; }) : [];
		state.filters.product_ids = state.selectedProducts.map(function (p) { return p.value; });
	}

	function buildRequestData() {
		const f = state.filters;
		return {
			preset: state.preset,
			page: state.page,
			sort: state.sort,
			order: state.order,
			date_from: f.date_from,
			date_to: f.date_to,
			statuses: f.statuses,
			payment_methods: f.payment_methods,
			countries: f.countries,
			city: f.city,
			total_min: f.total_min,
			total_max: f.total_max,
			spent_min: f.spent_min,
			spent_max: f.spent_max,
			order_count_min: f.order_count_min,
			order_count_max: f.order_count_max,
			last_order_from: f.last_order_from,
			last_order_to: f.last_order_to,
			registered_from: f.registered_from,
			registered_to: f.registered_to,
			coupon: f.coupon,
			product_ids: f.product_ids,
			category_ids: f.category_ids,
			rfm_segments: f.rfm_segments,
			search: f.search,
		};
	}

	function runQuery() {
		collectFilters();
		// Captured with the request: a later keystroke must not change which
		// empty text this answer shows. The search box and the preset chips
		// sit outside "More filters", so the badge count leaves them out.
		// Only this tab's filters count here: a value left in a field of the
		// other tab is hidden and this tab's query ignores it, so it must not
		// turn "No customers yet." into "No customers match these filters.".
		updateActiveFilterCount();
		const narrowed = countActiveFilters(state.tab) > 0 || state.preset !== '' || state.filters.search !== '';
		ROOT.classList.add('bp-seg-loading');

		const reqId = ++state.lastRequestId;
		const action = state.tab === 'customers' ? 'brikpanel_segments_query_customers' : 'brikpanel_segments_query_orders';

		post(action, buildRequestData()).then(function (res) {
			if (reqId !== state.lastRequestId) return;
			ROOT.classList.remove('bp-seg-loading');
			if (!res || !res.success) {
				setBody('<tr><td class="bp-seg-empty" colspan="8">' + escape(I18N.error) + '</td></tr>');
				return;
			}
			if (state.tab === 'customers') renderCustomersTable(res.data, narrowed); else renderOrdersTable(res.data, narrowed);
			renderStats(res.data);
			renderPagination(res.data);
		}).catch(function () {
			ROOT.classList.remove('bp-seg-loading');
			setBody('<tr><td class="bp-seg-empty" colspan="8">' + escape(I18N.error) + '</td></tr>');
		});
	}

	// -----------------------------------------------------------------------
	// Active-filter count for the "More filters" badge
	// -----------------------------------------------------------------------

	// Which tab's query reads each "More filters" key: '' both, otherwise only
	// that tab. Matches query_orders() / query_customers() in
	// brikpanel-segments.php and the .bp-seg-orders-only /
	// .bp-seg-customers-only fields in views/page.php (coupon is orders only).
	const FILTER_TABS = {
		date_from: '',
		date_to: '',
		countries: '',
		city: '',
		product_ids: '',
		category_ids: '',
		statuses: 'orders',
		total_min: 'orders',
		total_max: 'orders',
		payment_methods: 'orders',
		coupon: 'orders',
		spent_min: 'customers',
		spent_max: 'customers',
		order_count_min: 'customers',
		order_count_max: 'customers',
		last_order_from: 'customers',
		last_order_to: 'customers',
		registered_from: 'customers',
		registered_to: 'customers',
		rfm_segments: 'customers',
	};

	// Filled "More filters" keys. With a tab, keys that belong only to the
	// other tab are skipped; without one, every key counts (the badge).
	function countActiveFilters(tab) {
		const f = state.filters;
		let count = 0;
		Object.keys(FILTER_TABS).forEach(function (k) {
			if (tab && FILTER_TABS[k] !== '' && FILTER_TABS[k] !== tab) return;
			const v = f[k];
			if (Array.isArray(v) ? v.length > 0 : (v !== '' && v != null)) count++;
		});
		return count;
	}

	function updateActiveFilterCount() {
		const count = countActiveFilters('');

		const badge = el('bp-seg-active-filter-count');
		if (count > 0) {
			badge.hidden = false;
			badge.textContent = count;
		} else {
			badge.hidden = true;
		}
		return count;
	}

	// -----------------------------------------------------------------------
	// Product picker
	// -----------------------------------------------------------------------

	const searchProductsDebounced = debounce(function (term) {
		if (term.length < 2) {
			el('bp-seg-product-suggestions').hidden = true;
			return;
		}
		post('brikpanel_segments_search_products', { q: term }).then(function (res) {
			if (!res || !res.success) return;
			const list = res.data.products || [];
			const box = el('bp-seg-product-suggestions');
			if (!list.length) {
				box.innerHTML = '<div class="bp-seg-suggestion" style="color:#616161">' + escape(I18N.no_products || '') + '</div>';
				box.hidden = false;
				return;
			}
			box.innerHTML = list.map(function (p) {
				return '<div class="bp-seg-suggestion" data-value="' + escape(p.value) + '" data-label="' + escape(p.label) + '">' + escape(p.label) + '</div>';
			}).join('');
			box.hidden = false;
			box.querySelectorAll('.bp-seg-suggestion').forEach(function (s) {
				s.addEventListener('click', function () {
					addProduct({ value: s.dataset.value, label: s.dataset.label });
					el('bp-seg-product-search').value = '';
					box.hidden = true;
				});
			});
		});
	}, 250);

	function addProduct(p) {
		if (state.selectedProducts.some(function (x) { return String(x.value) === String(p.value); })) return;
		state.selectedProducts.push(p);
		renderSelectedProducts();
		runQuery();
	}

	function removeProduct(value) {
		state.selectedProducts = state.selectedProducts.filter(function (x) { return String(x.value) !== String(value); });
		renderSelectedProducts();
		runQuery();
	}

	function renderSelectedProducts() {
		const host = el('bp-seg-selected-products');
		host.innerHTML = state.selectedProducts.map(function (p) {
			return '<span class="bp-seg-selected-chip">' + escape(p.label) + ' <button type="button" data-remove="' + escape(p.value) + '" aria-label="' + escape(I18N.remove || 'Remove') + '">&times;</button></span>';
		}).join('');
		host.querySelectorAll('button[data-remove]').forEach(function (btn) {
			btn.addEventListener('click', function () { removeProduct(btn.dataset.remove); });
		});
	}

	// -----------------------------------------------------------------------
	// Event wiring
	// -----------------------------------------------------------------------

	function wire() {
		// Tabs
		ROOT.querySelectorAll('.bp-seg-tab').forEach(function (btn) {
			btn.addEventListener('click', function () {
				if (btn.dataset.tab === state.tab) return;
				state.tab = btn.dataset.tab;
				state.page = 1;
				state.preset = '';
				ROOT.setAttribute('data-tab', state.tab);
				ROOT.querySelectorAll('.bp-seg-tab').forEach(function (b) {
					b.classList.toggle('is-active', b === btn);
					b.setAttribute('aria-selected', b === btn ? 'true' : 'false');
				});
				renderChips();
				runQuery();
			});
		});

		// Preset chips (event delegation)
		el('bp-seg-chips').addEventListener('click', function (e) {
			const btn = e.target.closest('.bp-seg-chip');
			if (!btn) return;
			state.preset = btn.dataset.preset || '';
			state.page = 1;
			renderChips();
			runQuery();
		});

		// More-filters toggle
		el('bp-seg-toggle-more').addEventListener('click', function () {
			const panel = el('bp-seg-more');
			const expanded = !panel.hidden;
			panel.hidden = expanded;
			el('bp-seg-toggle-more').setAttribute('aria-expanded', expanded ? 'false' : 'true');
		});

		// Reset
		el('bp-seg-reset').addEventListener('click', function () {
			state.filters = emptyFilters();
			state.selectedProducts = [];
			state.preset = '';
			state.page = 1;
			ROOT.querySelectorAll('input[type="text"], input[type="date"], input[type="number"], input[type="search"]').forEach(function (i) { i.value = ''; });
			ROOT.querySelectorAll('select').forEach(function (s) { Array.from(s.options).forEach(function (o) { o.selected = false; }); });
			ROOT.querySelectorAll('.bp-seg-multi-chip.is-active').forEach(function (c) { c.classList.remove('is-active'); });
			renderSelectedProducts();
			renderChips();
			runQuery();
		});

		// Live inputs with debounce
		const debouncedQuery = debounce(function () { state.page = 1; runQuery(); }, 350);
		['bp-seg-search', 'bp-seg-city', 'bp-seg-coupon',
			'bp-seg-total-min', 'bp-seg-total-max',
			'bp-seg-spent-min', 'bp-seg-spent-max',
			'bp-seg-count-min', 'bp-seg-count-max',
		].forEach(function (id) {
			const node = el(id);
			if (node) node.addEventListener('input', debouncedQuery);
		});

		// Instant-apply inputs
		['bp-seg-date-from', 'bp-seg-date-to',
			'bp-seg-last-order-from', 'bp-seg-last-order-to',
			'bp-seg-registered-from', 'bp-seg-registered-to',
		].forEach(function (id) {
			const node = el(id);
			if (node) node.addEventListener('change', function () { state.page = 1; runQuery(); });
		});

		// Selects
		['bp-seg-country', 'bp-seg-payment', 'bp-seg-categories', 'bp-seg-rfm'].forEach(function (id) {
			const node = el(id);
			if (node) node.addEventListener('change', function () { state.page = 1; runQuery(); });
		});

		// Pagination
		el('bp-seg-prev').addEventListener('click', function () { if (state.page > 1) { state.page--; runQuery(); } });
		el('bp-seg-next').addEventListener('click', function () { state.page++; runQuery(); });

		// Export
		el('bp-seg-export').addEventListener('click', function () {
			collectFilters();
			const params = new URLSearchParams();
			params.append('action', 'brikpanel_segments_export');
			params.append('_wpnonce', CFG.nonce);
			params.append('tab', state.tab);
			const reqData = buildRequestData();
			Object.keys(reqData).forEach(function (k) {
				const v = reqData[k];
				if (Array.isArray(v)) v.forEach(function (it) { params.append(k + '[]', it); });
				else if (v !== '' && v !== null && v !== undefined) params.append(k, v);
			});
			window.location.href = CFG.ajax_url + '?' + params.toString();
		});

		// Product picker
		const productInput = el('bp-seg-product-search');
		productInput.addEventListener('input', function () { searchProductsDebounced(productInput.value.trim()); });
		productInput.addEventListener('focus', function () { if (productInput.value.trim().length >= 2) searchProductsDebounced(productInput.value.trim()); });
		document.addEventListener('click', function (e) {
			if (!e.target.closest('#bp-seg-product-search') && !e.target.closest('#bp-seg-product-suggestions')) {
				const box = el('bp-seg-product-suggestions');
				if (box) box.hidden = true;
			}
		});
	}

	// -----------------------------------------------------------------------
	// Boot
	// -----------------------------------------------------------------------

	ROOT.setAttribute('data-tab', state.tab);
	renderChips();
	wire();
	fitTable();
	loadFilterOptions().then(runQuery);
})();
