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
	// Table rendering
	// -----------------------------------------------------------------------

	function renderOrdersTable(data) {
		el('bp-seg-thead').innerHTML =
			'<tr>'
			+ '<th>' + escape(I18N.col_order || 'Order') + '</th>'
			+ '<th>' + escape(I18N.col_date || 'Date') + '</th>'
			+ '<th>' + escape(I18N.col_status || 'Status') + '</th>'
			+ '<th>' + escape(I18N.col_customer || 'Customer') + '</th>'
			+ '<th>' + escape(I18N.col_location || 'Location') + '</th>'
			+ '<th>' + escape(I18N.col_payment || 'Payment') + '</th>'
			+ '<th class="bp-seg-num">' + escape(I18N.col_total || 'Total') + '</th>'
			+ '</tr>';

		if (!data.items.length) {
			el('bp-seg-tbody').innerHTML = '<tr><td class="bp-seg-empty" colspan="7">' + escape(I18N.no_results || 'No orders match these filters.') + '</td></tr>';
			return;
		}

		el('bp-seg-tbody').innerHTML = data.items.map(function (o) {
			const location = [o.city, o.country].filter(Boolean).join(', ');
			return '<tr>'
				+ '<td><a class="bp-seg-primary-link" href="' + escape(o.edit_url) + '">#' + escape(o.id) + '</a></td>'
				+ '<td>' + escape(o.date) + '</td>'
				+ '<td><span class="bp-seg-status is-' + escape(o.status) + '">' + escape(o.status_label) + '</span></td>'
				+ '<td class="bp-seg-customer">' + (o.name ? escape(o.name) : '<span class="bp-seg-subtle">' + escape(I18N.guest || 'Guest') + '</span>') + (o.email ? '<div class="bp-seg-subtle">' + escape(o.email) + '</div>' : '') + '</td>'
				+ '<td>' + escape(location || '—') + '</td>'
				+ '<td>' + escape(o.payment || '—') + '</td>'
				+ '<td class="bp-seg-num">' + o.total_display + '</td>'
				+ '</tr>';
		}).join('');
	}

	function renderCustomersTable(data) {
		el('bp-seg-thead').innerHTML =
			'<tr>'
			+ '<th>' + escape(I18N.col_customer || 'Customer') + '</th>'
			+ '<th>' + escape(I18N.col_email || 'Email') + '</th>'
			+ '<th>' + escape(I18N.col_registered || 'Registered') + '</th>'
			+ '<th class="bp-seg-num">' + escape(I18N.col_orders || 'Orders') + '</th>'
			+ '<th class="bp-seg-num">' + escape(I18N.col_spent || 'Total spent') + '</th>'
			+ '<th class="bp-seg-num">' + escape(I18N.col_aov || 'AOV') + '</th>'
			+ '<th>' + escape(I18N.col_last_order || 'Last order') + '</th>'
			+ '</tr>';

		if (!data.items.length) {
			el('bp-seg-tbody').innerHTML = '<tr><td class="bp-seg-empty" colspan="7">' + escape(I18N.no_customers || 'No customers match these filters.') + '</td></tr>';
			return;
		}

		el('bp-seg-tbody').innerHTML = data.items.map(function (c) {
			const nameCell = c.edit_url
				? '<a class="bp-seg-primary-link" href="' + escape(c.edit_url) + '">' + escape(c.name) + '</a>'
				: escape(c.name) + ' <span class="bp-seg-subtle">(' + escape(I18N.guest || 'Guest') + ')</span>';
			return '<tr>'
				+ '<td class="bp-seg-customer">' + nameCell + '</td>'
				+ '<td class="bp-seg-customer">' + escape(c.email || '—') + '</td>'
				+ '<td>' + escape(c.registered || '—') + '</td>'
				+ '<td class="bp-seg-num">' + escape(c.order_count) + '</td>'
				+ '<td class="bp-seg-num">' + c.total_spent_display + '</td>'
				+ '<td class="bp-seg-num">' + c.aov_display + '</td>'
				+ '<td>' + escape(c.last_order || '—') + '</td>'
				+ '</tr>';
		}).join('');
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

	function formatNumber(n) {
		try { return new Intl.NumberFormat().format(Number(n || 0)); } catch (e) { return String(n || 0); }
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
		updateActiveFilterCount();
		ROOT.classList.add('bp-seg-loading');

		const reqId = ++state.lastRequestId;
		const action = state.tab === 'customers' ? 'brikpanel_segments_query_customers' : 'brikpanel_segments_query_orders';

		post(action, buildRequestData()).then(function (res) {
			if (reqId !== state.lastRequestId) return;
			ROOT.classList.remove('bp-seg-loading');
			if (!res || !res.success) {
				el('bp-seg-tbody').innerHTML = '<tr><td class="bp-seg-empty" colspan="9">' + escape(I18N.error || 'Something went wrong.') + '</td></tr>';
				return;
			}
			if (state.tab === 'customers') renderCustomersTable(res.data); else renderOrdersTable(res.data);
			renderStats(res.data);
			renderPagination(res.data);
		}).catch(function () {
			ROOT.classList.remove('bp-seg-loading');
			el('bp-seg-tbody').innerHTML = '<tr><td class="bp-seg-empty" colspan="9">' + escape(I18N.error || 'Something went wrong.') + '</td></tr>';
		});
	}

	// -----------------------------------------------------------------------
	// Active-filter count for the "More filters" badge
	// -----------------------------------------------------------------------

	function updateActiveFilterCount() {
		let count = 0;
		const f = state.filters;
		[
			'date_from', 'date_to', 'city', 'coupon',
			'total_min', 'total_max', 'spent_min', 'spent_max',
			'order_count_min', 'order_count_max',
			'last_order_from', 'last_order_to', 'registered_from', 'registered_to',
		].forEach(function (k) { if (f[k] !== '' && f[k] != null) count++; });
		['statuses', 'payment_methods', 'countries', 'product_ids', 'category_ids', 'rfm_segments'].forEach(function (k) {
			if (f[k] && f[k].length) count++;
		});

		const badge = el('bp-seg-active-filter-count');
		if (count > 0) {
			badge.hidden = false;
			badge.textContent = count;
		} else {
			badge.hidden = true;
		}
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
				box.innerHTML = '<div class="bp-seg-suggestion" style="color:#8a8a8a">' + escape(I18N.no_products || 'No products found.') + '</div>';
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
	loadFilterOptions().then(runQuery);
})();
