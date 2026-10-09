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
		// Selected products for the pickers (value + label).
		selectedProducts: [],
		excludedProducts: [],
		// Saved segments (shared by everyone who opens Segments), the one
		// applied right now and the filters it applied: changing any of them
		// lifts its highlight. saveForm: the inline name field is open.
		saved: Array.isArray(CFG.saved) ? CFG.saved : [],
		savedId: '',
		savedSig: '',
		saveForm: false,
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
			exclude_product_ids: [],
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

	// Presets, then the tab's saved segments, then "Save as segment" (only
	// when there is something beyond a preset to keep) or its name field.
	function renderChips() {
		const holder = el('bp-seg-chips');
		const typed = el('bp-seg-save-name');
		const typedName = typed ? typed.value : '';
		const list = PRESETS[state.tab] || [];
		let html = list.map(function (p) {
			const active = !state.savedId && p.key === state.preset ? ' is-active' : '';
			return '<button type="button" class="bp-seg-chip' + active + '" data-preset="' + escape(p.key) + '">' + escape(p.label) + '</button>';
		}).join('');
		html += state.saved.filter(function (seg) { return seg.tab === state.tab; }).map(function (seg) {
			const active = seg.id === state.savedId;
			return '<span class="bp-seg-saved' + (active ? ' is-active' : '') + '">'
				+ '<button type="button" class="bp-seg-saved-apply" data-saved="' + escape(seg.id) + '" aria-pressed="' + (active ? 'true' : 'false') + '">' + escape(seg.name) + '</button>'
				+ '<button type="button" class="bp-seg-saved-del" data-saved-del="' + escape(seg.id) + '" aria-label="' + escape(fill(I18N.saved_delete_label, seg.name)) + '">&times;</button>'
				+ '</span>';
		}).join('');
		if (state.saveForm) {
			html += '<span class="bp-seg-save-form">'
				+ '<input type="text" class="brikpanel-control bp-seg-save-name" id="bp-seg-save-name" maxlength="60" placeholder="' + escape(I18N.save_name) + '" aria-label="' + escape(I18N.save_name) + '">'
				+ '<button type="button" class="brikpanel-btn brikpanel-btn--primary" data-save-confirm>' + escape(I18N.save) + '</button>'
				+ '<button type="button" class="brikpanel-btn brikpanel-btn--link" data-save-cancel>' + escape(I18N.cancel) + '</button>'
				+ '</span>';
		} else if (canSave()) {
			html += '<button type="button" class="bp-seg-chip bp-seg-chip-add" data-save-open>'
				+ '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true" focusable="false"><path d="M12 5v14M5 12h14"/></svg>'
				+ escape(I18N.save_segment) + '</button>';
		}
		holder.innerHTML = html;
		const field = el('bp-seg-save-name');
		if (field && typedName) field.value = typedName;
	}

	function fill(pattern, value) {
		return window.brikpanelFormat ? window.brikpanelFormat.fill(pattern || '', value) : String(pattern || '').replace('%s', value);
	}

	// -----------------------------------------------------------------------
	// Filter options (load once)
	// -----------------------------------------------------------------------

	function loadFilterOptions() {
		return post('brikpanel_segments_filter_options', {}).then(function (res) {
			if (!res || !res.success) return;
			state.options = res.data;
			// Statuses as chip-style multi
			renderMultiChips('bp-seg-statuses', res.data.statuses || [], 'statuses');
			// Countries as native multiselect
			fillSelect('bp-seg-country', res.data.countries || [], state.filters.countries);
			fillSelect('bp-seg-payment', res.data.payment_methods || [], state.filters.payment_methods);
			fillSelect('bp-seg-categories', res.data.categories || [], state.filters.category_ids);
			fillSelect('bp-seg-rfm', res.data.rfm_segments || [], state.filters.rfm_segments);
		});
	}

	// Read and written through state.filters[key] at click time: holding the
	// array from load time let a status cleared by Reset (or replaced by a
	// saved segment) come back with the next click.
	function renderMultiChips(containerId, items, key) {
		const host = el(containerId);
		if (!host) return;
		host.innerHTML = items.map(function (it) {
			const isActive = state.filters[key].indexOf(it.value) !== -1 ? ' is-active' : '';
			return '<span class="bp-seg-multi-chip' + isActive + '" data-value="' + escape(it.value) + '">' + escape(it.label) + '</span>';
		}).join('');
		host.querySelectorAll('.bp-seg-multi-chip').forEach(function (chip) {
			chip.addEventListener('click', function () {
				const v = chip.dataset.value;
				const selected = state.filters[key].slice();
				const idx = selected.indexOf(v);
				if (idx === -1) selected.push(v); else selected.splice(idx, 1);
				state.filters[key] = selected;
				chip.classList.toggle('is-active', selected.indexOf(v) !== -1);
				state.page = 1;
				runQuery();
			});
		});
	}

	function syncMultiChips(containerId, key) {
		const host = el(containerId);
		if (!host) return;
		host.querySelectorAll('.bp-seg-multi-chip').forEach(function (chip) {
			chip.classList.toggle('is-active', state.filters[key].indexOf(chip.dataset.value) !== -1);
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
		state.filters.exclude_product_ids = state.excludedProducts.map(function (p) { return p.value; });
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
			exclude_product_ids: f.exclude_product_ids,
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
		if (state.savedId && signature() !== state.savedSig) {
			state.savedId = '';
		}
		renderChips();
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
		exclude_product_ids: '',
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
	// Product pickers: "Products" (the order has one of them) and "Exclude
	// products" (it has none of them). One product is never in both lists:
	// picking it in one takes it out of the other.
	// -----------------------------------------------------------------------

	function makeProductPicker(opts) {
		const input = el(opts.input);
		const box = el(opts.box);
		let seq = 0;

		const search = debounce(function (term) {
			if (term.length < 2) {
				box.hidden = true;
				return;
			}
			const mine = ++seq;
			post('brikpanel_segments_search_products', { q: term }).then(function (res) {
				// A slower answer to an older search must not replace a newer one.
				if (mine !== seq || !res || !res.success) return;
				const list = res.data.products || [];
				if (!list.length) {
					box.innerHTML = '<div class="bp-seg-suggestion" style="color:#616161">' + escape(I18N.no_products || '') + '</div>';
					box.hidden = false;
					return;
				}
				box.innerHTML = list.map(function (p) {
					return '<div class="bp-seg-suggestion" data-value="' + escape(p.value) + '" data-label="' + escape(p.label) + '">' + escape(p.label) + '</div>';
				}).join('');
				box.hidden = false;
				box.querySelectorAll('.bp-seg-suggestion[data-value]').forEach(function (node) {
					node.addEventListener('click', function () {
						add({ value: node.dataset.value, label: node.dataset.label });
						input.value = '';
						box.hidden = true;
					});
				});
			});
		}, 250);

		function same(a, b) { return String(a.value) === String(b.value); }

		function add(p) {
			const mine = state[opts.list];
			if (mine.some(function (x) { return same(x, p); })) return;
			state[opts.other] = state[opts.other].filter(function (x) { return !same(x, p); });
			state[opts.list] = mine.concat([p]);
			renderProductLists();
			state.page = 1;
			runQuery();
		}

		input.addEventListener('input', function () { search(input.value.trim()); });
		input.addEventListener('focus', function () { if (input.value.trim().length >= 2) search(input.value.trim()); });
		document.addEventListener('click', function (e) {
			if (!e.target.closest('#' + opts.input) && !e.target.closest('#' + opts.box)) {
				box.hidden = true;
			}
		});
	}

	function renderProductList(hostId, listKey) {
		const host = el(hostId);
		if (!host) return;
		host.innerHTML = state[listKey].map(function (p) {
			return '<span class="bp-seg-selected-chip">' + escape(p.label) + ' <button type="button" data-remove="' + escape(p.value) + '" aria-label="' + escape(I18N.remove || '') + '">&times;</button></span>';
		}).join('');
		host.querySelectorAll('button[data-remove]').forEach(function (btn) {
			btn.addEventListener('click', function () {
				state[listKey] = state[listKey].filter(function (x) { return String(x.value) !== String(btn.dataset.remove); });
				renderProductLists();
				state.page = 1;
				runQuery();
			});
		});
	}

	function renderProductLists() {
		renderProductList('bp-seg-selected-products', 'selectedProducts');
		renderProductList('bp-seg-excluded-products', 'excludedProducts');
	}

	// -----------------------------------------------------------------------
	// Saved segments
	// -----------------------------------------------------------------------

	// Something beyond a preset chip to keep: a "More filters" value of this
	// tab or a search.
	function canSave() {
		return !state.savedId && (countActiveFilters(state.tab) > 0 || state.filters.search !== '');
	}

	// This tab's filters and the preset, in a form that compares equal when
	// the same filters are set in any order.
	function signature() {
		const f = state.filters;
		const parts = Object.keys(FILTER_TABS).filter(function (k) {
			return FILTER_TABS[k] === '' || FILTER_TABS[k] === state.tab;
		}).map(function (k) {
			const v = f[k];
			return k + '=' + (Array.isArray(v) ? v.map(String).sort().join(',') : String(v == null ? '' : v));
		});
		parts.push('search=' + f.search, 'preset=' + state.preset);
		return parts.join('|');
	}

	let statusTimer = null;
	function showStatus(text, isError) {
		const node = el('bp-seg-save-status');
		if (!node) return;
		clearTimeout(statusTimer);
		node.textContent = text;
		node.classList.toggle('is-error', !!isError);
		node.hidden = !text;
		if (text && !isError) {
			statusTimer = setTimeout(function () { node.hidden = true; node.textContent = ''; }, 3500);
		}
	}

	// Values a saved segment names that the store no longer offers (a status
	// plugin removed, a deleted category) are dropped: a filter the screen
	// cannot show must not narrow the list in secret.
	function keepKnown(values, items) {
		if (!Array.isArray(items)) return values;
		const known = items.map(function (it) { return String(it.value); });
		return values.filter(function (v) { return known.indexOf(String(v)) !== -1; });
	}

	function setSelectValues(id, values) {
		const sel = el(id);
		if (!sel) return;
		Array.from(sel.options).forEach(function (o) { o.selected = values.indexOf(o.value) !== -1; });
	}

	const TEXT_FIELDS = {
		'bp-seg-date-from': 'date_from',
		'bp-seg-date-to': 'date_to',
		'bp-seg-city': 'city',
		'bp-seg-coupon': 'coupon',
		'bp-seg-search': 'search',
		'bp-seg-total-min': 'total_min',
		'bp-seg-total-max': 'total_max',
		'bp-seg-spent-min': 'spent_min',
		'bp-seg-spent-max': 'spent_max',
		'bp-seg-count-min': 'order_count_min',
		'bp-seg-count-max': 'order_count_max',
		'bp-seg-last-order-from': 'last_order_from',
		'bp-seg-last-order-to': 'last_order_to',
		'bp-seg-registered-from': 'registered_from',
		'bp-seg-registered-to': 'registered_to',
	};

	function applySaved(id) {
		const seg = state.saved.filter(function (x) { return x.id === id && x.tab === state.tab; })[0];
		if (!seg) return;
		optionsReady.then(function () {
			const f = emptyFilters();
			const src = seg.filters || {};
			Object.keys(f).forEach(function (k) {
				if (src[k] === undefined || src[k] === null) return;
				f[k] = Array.isArray(f[k]) ? [].concat(src[k]).map(String) : String(src[k]);
			});
			const o = state.options || {};
			f.statuses = keepKnown(f.statuses, o.statuses);
			f.payment_methods = keepKnown(f.payment_methods, o.payment_methods);
			f.countries = keepKnown(f.countries, o.countries);
			f.category_ids = keepKnown(f.category_ids, o.categories);
			f.rfm_segments = keepKnown(f.rfm_segments, o.rfm_segments);

			state.filters = f;
			state.selectedProducts = (seg.products || []).slice();
			state.excludedProducts = (seg.exclude_products || []).slice();
			state.preset = seg.preset || '';
			state.page = 1;
			state.saveForm = false;

			Object.keys(TEXT_FIELDS).forEach(function (fieldId) {
				const node = el(fieldId);
				if (node) node.value = f[TEXT_FIELDS[fieldId]];
			});
			setSelectValues('bp-seg-country', f.countries);
			setSelectValues('bp-seg-payment', f.payment_methods);
			setSelectValues('bp-seg-categories', f.category_ids);
			setSelectValues('bp-seg-rfm', f.rfm_segments);
			syncMultiChips('bp-seg-statuses', 'statuses');
			renderProductLists();

			collectFilters();
			state.savedId = seg.id;
			state.savedSig = signature();
			runQuery();
		});
	}

	function submitSave(replace) {
		const field = el('bp-seg-save-name');
		const name = field ? field.value.trim() : '';
		if (!name) {
			showStatus(I18N.save_name_required, true);
			if (field) field.focus();
			return;
		}
		collectFilters();
		const data = buildRequestData();
		delete data.page;
		delete data.sort;
		delete data.order;
		data.tab = state.tab;
		data.name = name;
		if (replace) data.replace = '1';
		const sig = signature();
		post('brikpanel_segments_save_segment', data).then(function (res) {
			if (res && res.success) {
				state.saved = res.data.saved || [];
				state.saveForm = false;
				state.savedId = res.data.id;
				state.savedSig = sig;
				renderChips();
				showStatus(I18N.saved_done, false);
				return;
			}
			const code = res && res.data ? res.data.code : '';
			if (code === 'exists') {
				if (window.confirm(fill(I18N.save_replace_confirm, name))) submitSave(true);
				return;
			}
			if (code === 'name') {
				showStatus(I18N.save_name_required, true);
				return;
			}
			if (code === 'limit') {
				showStatus(window.brikpanelFormat ? window.brikpanelFormat.count(I18N.save_limit, Number(CFG.saved_max) || 0) : '', true);
				return;
			}
			showStatus(I18N.save_error, true);
		}).catch(function () {
			showStatus(I18N.save_error, true);
		});
	}

	function deleteSaved(id) {
		const seg = state.saved.filter(function (x) { return x.id === id; })[0];
		if (!seg) return;
		if (!window.confirm(fill(I18N.saved_delete_confirm, seg.name))) return;
		post('brikpanel_segments_delete_segment', { id: id }).then(function (res) {
			if (!res || !res.success) {
				showStatus(I18N.delete_error, true);
				return;
			}
			state.saved = res.data.saved || [];
			if (state.savedId === id) state.savedId = '';
			renderChips();
			showStatus(I18N.saved_deleted, false);
		}).catch(function () {
			showStatus(I18N.delete_error, true);
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
				state.savedId = '';
				state.saveForm = false;
				ROOT.setAttribute('data-tab', state.tab);
				ROOT.querySelectorAll('.bp-seg-tab').forEach(function (b) {
					b.classList.toggle('is-active', b === btn);
					b.setAttribute('aria-selected', b === btn ? 'true' : 'false');
				});
				renderChips();
				runQuery();
			});
		});

		// Chip row (event delegation). Saved segments and the save form are
		// handled first: a chip without data-preset would otherwise read as "All".
		const chips = el('bp-seg-chips');
		chips.addEventListener('click', function (e) {
			const del = e.target.closest('[data-saved-del]');
			if (del) { deleteSaved(del.dataset.savedDel); return; }
			const saved = e.target.closest('[data-saved]');
			if (saved) { applySaved(saved.dataset.saved); return; }
			if (e.target.closest('[data-save-open]')) {
				state.saveForm = true;
				renderChips();
				const field = el('bp-seg-save-name');
				if (field) field.focus();
				return;
			}
			if (e.target.closest('[data-save-confirm]')) { submitSave(false); return; }
			if (e.target.closest('[data-save-cancel]')) {
				state.saveForm = false;
				showStatus('', false);
				renderChips();
				return;
			}
			const btn = e.target.closest('.bp-seg-chip[data-preset]');
			if (!btn) return;
			state.preset = btn.dataset.preset || '';
			state.savedId = '';
			state.page = 1;
			renderChips();
			runQuery();
		});
		chips.addEventListener('keydown', function (e) {
			if (!e.target || e.target.id !== 'bp-seg-save-name') return;
			if (e.key === 'Enter') { e.preventDefault(); submitSave(false); }
			if (e.key === 'Escape') {
				e.preventDefault();
				state.saveForm = false;
				showStatus('', false);
				renderChips();
				const add = chips.querySelector('[data-save-open]');
				if (add) add.focus();
			}
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
			state.excludedProducts = [];
			state.preset = '';
			state.savedId = '';
			state.saveForm = false;
			state.page = 1;
			showStatus('', false);
			ROOT.querySelectorAll('input[type="text"], input[type="date"], input[type="number"], input[type="search"]').forEach(function (i) { i.value = ''; });
			ROOT.querySelectorAll('select').forEach(function (s) { Array.from(s.options).forEach(function (o) { o.selected = false; }); });
			ROOT.querySelectorAll('.bp-seg-multi-chip.is-active').forEach(function (c) { c.classList.remove('is-active'); });
			renderProductLists();
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

		// Product pickers
		makeProductPicker({ input: 'bp-seg-product-search', box: 'bp-seg-product-suggestions', list: 'selectedProducts', other: 'excludedProducts' });
		makeProductPicker({ input: 'bp-seg-exclude-search', box: 'bp-seg-exclude-suggestions', list: 'excludedProducts', other: 'selectedProducts' });
	}

	// -----------------------------------------------------------------------
	// Boot
	// -----------------------------------------------------------------------

	ROOT.setAttribute('data-tab', state.tab);
	renderChips();
	wire();
	fitTable();
	// A saved segment is applied only once the option lists it names exist.
	const optionsReady = loadFilterOptions();
	optionsReady.then(runQuery);
})();
