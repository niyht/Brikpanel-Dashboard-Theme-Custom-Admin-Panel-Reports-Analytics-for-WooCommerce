/**
 * BrikPanel — Abandoned Carts admin list page.
 * All strings come from window.brikpanelCartAbAdmin.i18n (i18n rule).
 */
(function () {
	'use strict';

	var cfg = window.brikpanelCartAbAdmin;
	if (!cfg) {
		return;
	}

	var state = { page: 1, pages: 1 };

	function $(id) {
		return document.getElementById(id);
	}

	function filters() {
		return {
			search: $('brikpanel-cartab-search').value.trim(),
			status: $('brikpanel-cartab-status').value,
			source: $('brikpanel-cartab-source').value,
			from: $('brikpanel-cartab-from').value,
			to: $('brikpanel-cartab-to').value
		};
	}

	function post(action, data) {
		var body = new FormData();
		body.append('action', action);
		body.append('_ajax_nonce', cfg.nonce);
		Object.keys(data || {}).forEach(function (k) {
			body.append(k, data[k]);
		});
		return fetch(cfg.ajax_url, { method: 'POST', credentials: 'same-origin', body: body })
			.then(function (res) { return res.json(); });
	}

	function badge(text, kind) {
		var el = document.createElement('span');
		el.className = 'brikpanel-cartab-badge brikpanel-cartab-badge-' + kind;
		el.textContent = text;
		return el;
	}

	function renderCartCell(row) {
		var cell = document.createElement('td');
		if (row.item_count > 0) {
			var count = document.createElement('span');
			count.className = 'brikpanel-cartab-cart-count';
			count.textContent = row.item_count + ' ' + (row.item_count === 1 ? cfg.i18n.item : cfg.i18n.items);
			var total = document.createElement('span');
			total.className = 'brikpanel-cartab-cart-total';
			total.textContent = row.total_h;
			cell.appendChild(count);
			cell.appendChild(total);
		} else {
			var dash = document.createElement('span');
			dash.className = 'brikpanel-cartab-muted';
			dash.textContent = '—';
			cell.appendChild(dash);
		}
		return cell;
	}

	function renderDetailsRow(row) {
		var tr = document.createElement('tr');
		tr.className = 'brikpanel-cartab-details-row';
		tr.hidden = true;
		var td = document.createElement('td');
		td.colSpan = 7;

		var box = document.createElement('div');
		box.className = 'brikpanel-cartab-details';

		if (!row.cart_items.length) {
			var none = document.createElement('div');
			none.className = 'brikpanel-cartab-muted';
			none.textContent = cfg.i18n.empty_cart;
			box.appendChild(none);
		} else {
			row.cart_items.forEach(function (item) {
				var line = document.createElement('div');
				line.className = 'brikpanel-cartab-details-item';

				var name = document.createElement('span');
				name.className = 'brikpanel-cartab-details-name';
				name.textContent = item.qty + ' × ' + item.name;
				line.appendChild(name);

				var attrs = item.attributes || {};
				var attrKeys = Object.keys(attrs);
				if (attrKeys.length) {
					var attrSpan = document.createElement('span');
					attrSpan.className = 'brikpanel-cartab-details-attrs';
					attrSpan.textContent = attrKeys.map(function (k) {
						return k + ': ' + attrs[k];
					}).join(', ');
					line.appendChild(attrSpan);
				}

				if (item.sku) {
					var sku = document.createElement('span');
					sku.className = 'brikpanel-cartab-details-sku';
					sku.textContent = cfg.i18n.sku + ': ' + item.sku;
					line.appendChild(sku);
				}
				box.appendChild(line);
			});
		}

		if (row.order_id > 0 && row.order_url) {
			var orderLink = document.createElement('a');
			orderLink.className = 'brikpanel-cartab-details-order';
			orderLink.href = row.order_url;
			orderLink.textContent = cfg.i18n.order + ' #' + row.order_id;
			box.appendChild(orderLink);
		}

		td.appendChild(box);
		tr.appendChild(td);
		return tr;
	}

	function render(items) {
		var tbody = $('brikpanel-cartab-tbody');
		tbody.textContent = '';

		if (!items.length) {
			var tr = document.createElement('tr');
			var td = document.createElement('td');
			td.colSpan = 7;
			td.className = 'brikpanel-cartab-empty';
			td.textContent = cfg.i18n.empty;
			tr.appendChild(td);
			tbody.appendChild(tr);
			return;
		}

		items.forEach(function (row) {
			var tr = document.createElement('tr');

			var emailTd = document.createElement('td');
			emailTd.className = 'brikpanel-cartab-email-cell';
			emailTd.textContent = row.email;
			tr.appendChild(emailTd);

			var nameTd = document.createElement('td');
			nameTd.textContent = (row.first_name + ' ' + row.last_name).trim() || '—';
			tr.appendChild(nameTd);

			tr.appendChild(renderCartCell(row));

			var sourceTd = document.createElement('td');
			sourceTd.appendChild(badge(cfg.sources[row.source] || row.source, 'source'));
			tr.appendChild(sourceTd);

			var statusTd = document.createElement('td');
			statusTd.appendChild(badge(cfg.statuses[row.status] || row.status, row.status));
			tr.appendChild(statusTd);

			var dateTd = document.createElement('td');
			dateTd.className = 'brikpanel-cartab-date-cell';
			dateTd.textContent = row.updated_h;
			tr.appendChild(dateTd);

			var actionsTd = document.createElement('td');
			actionsTd.className = 'brikpanel-cartab-actions-cell';

			var detailsBtn = document.createElement('button');
			detailsBtn.type = 'button';
			detailsBtn.className = 'brikpanel-cartab-row-btn';
			detailsBtn.textContent = cfg.i18n.details;

			var deleteBtn = document.createElement('button');
			deleteBtn.type = 'button';
			deleteBtn.className = 'brikpanel-cartab-row-btn brikpanel-cartab-row-btn-danger';
			deleteBtn.textContent = cfg.i18n.delete;

			actionsTd.appendChild(detailsBtn);
			actionsTd.appendChild(deleteBtn);
			tr.appendChild(actionsTd);
			tbody.appendChild(tr);

			var detailsTr = renderDetailsRow(row);
			tbody.appendChild(detailsTr);

			detailsBtn.addEventListener('click', function () {
				detailsTr.hidden = !detailsTr.hidden;
			});
			deleteBtn.addEventListener('click', function () {
				if (!window.confirm(cfg.i18n.confirm_delete)) {
					return;
				}
				post('brikpanel_cartab_delete', { id: row.id }).then(function (json) {
					if (json && json.success) {
						load();
					} else {
						window.alert(cfg.i18n.error);
					}
				}).catch(function () {
					window.alert(cfg.i18n.error);
				});
			});
		});
	}

	function load() {
		var data = filters();
		data.page = state.page;
		post('brikpanel_cartab_list', data).then(function (json) {
			if (!json || !json.success) {
				window.alert(cfg.i18n.error);
				return;
			}
			var d = json.data;
			state.pages = d.pages;
			render(d.items);

			$('brikpanel-cartab-stat-total').textContent = d.counts.total;
			$('brikpanel-cartab-stat-active').textContent = d.counts.active;
			$('brikpanel-cartab-stat-abandoned').textContent = d.counts.abandoned;
			$('brikpanel-cartab-stat-recovered').textContent = d.counts.recovered;

			// Money strings arrive pre-formatted (and entity-decoded) from PHP.
			var amounts = d.amounts || {};
			$('brikpanel-cartab-amount-total').textContent = amounts.total || '';
			$('brikpanel-cartab-amount-active').textContent = amounts.active || '';
			$('brikpanel-cartab-amount-abandoned').textContent = amounts.abandoned || '';
			$('brikpanel-cartab-amount-recovered').textContent = amounts.recovered || '';

			var pager = $('brikpanel-cartab-pagination');
			pager.style.display = d.pages > 1 ? '' : 'none';
			$('brikpanel-cartab-page-info').textContent = d.page + ' / ' + d.pages;
			$('brikpanel-cartab-prev').disabled = d.page <= 1;
			$('brikpanel-cartab-next').disabled = d.page >= d.pages;
		}).catch(function () {
			window.alert(cfg.i18n.error);
		});
	}

	function exportUrl(format) {
		var f = filters();
		var params = new URLSearchParams({
			action: 'brikpanel_cartab_export',
			_wpnonce: cfg.nonce,
			format: format,
			search: f.search,
			status: f.status,
			source: f.source,
			from: f.from,
			to: f.to
		});
		return cfg.ajax_url + '?' + params.toString();
	}

	// Wire up
	$('brikpanel-cartab-apply').addEventListener('click', function () {
		state.page = 1;
		load();
	});
	$('brikpanel-cartab-search').addEventListener('keydown', function (e) {
		if (e.key === 'Enter') {
			state.page = 1;
			load();
		}
	});
	$('brikpanel-cartab-prev').addEventListener('click', function () {
		if (state.page > 1) {
			state.page--;
			load();
		}
	});
	$('brikpanel-cartab-next').addEventListener('click', function () {
		if (state.page < state.pages) {
			state.page++;
			load();
		}
	});
	$('brikpanel-cartab-export-csv').addEventListener('click', function () {
		window.location.href = exportUrl('csv');
	});
	$('brikpanel-cartab-export-xlsx').addEventListener('click', function () {
		window.location.href = exportUrl('xlsx');
	});
	$('brikpanel-cartab-popup-toggle').addEventListener('change', function () {
		var checkbox = this;
		var discountWrap = $('brikpanel-cartab-discount-wrap');
		discountWrap.style.display = checkbox.checked ? '' : 'none';
		post('brikpanel_cartab_popup_toggle', { enable: checkbox.checked ? '1' : '0' }).then(function (json) {
			if (!json || !json.success) {
				checkbox.checked = !checkbox.checked;
				discountWrap.style.display = checkbox.checked ? '' : 'none';
				window.alert(cfg.i18n.error);
			}
		}).catch(function () {
			checkbox.checked = !checkbox.checked;
			discountWrap.style.display = checkbox.checked ? '' : 'none';
			window.alert(cfg.i18n.error);
		});
	});
	$('brikpanel-cartab-popup-discount').addEventListener('change', function () {
		var input = this;
		var value = Math.max(0, Math.min(100, parseInt(input.value, 10) || 0));
		input.value = value;
		post('brikpanel_cartab_popup_discount', { discount: value }).then(function (json) {
			if (!json || !json.success) {
				window.alert(cfg.i18n.error);
			}
		}).catch(function () {
			window.alert(cfg.i18n.error);
		});
	});

	load();
})();
