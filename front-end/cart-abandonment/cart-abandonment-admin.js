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

	// WhatsApp glyph, drawn rather than loaded so the list needs no image asset.
	var WA_PATH = 'M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413Z';

	function whatsappIcon() {
		var ns = 'http://www.w3.org/2000/svg';
		var svg = document.createElementNS(ns, 'svg');
		svg.setAttribute('viewBox', '0 0 24 24');
		svg.setAttribute('aria-hidden', 'true');
		svg.setAttribute('focusable', 'false');
		var path = document.createElementNS(ns, 'path');
		path.setAttribute('d', WA_PATH);
		path.setAttribute('fill', 'currentColor');
		svg.appendChild(path);
		return svg;
	}

	function muted(text) {
		var el = document.createElement('span');
		el.className = 'brikpanel-cartab-muted';
		el.textContent = text;
		return el;
	}

	function renderPhoneCell(row) {
		var cell = document.createElement('td');
		cell.className = 'brikpanel-cartab-phone-cell';

		if (!row.phone) {
			cell.appendChild(muted('—'));
			cell.title = cfg.i18n.no_phone;
			return cell;
		}

		var number = document.createElement('span');
		number.className = 'brikpanel-cartab-phone';
		number.textContent = row.phone;
		if (row.phone_source === 'account') {
			number.title = cfg.i18n.phone_account;
		} else if (row.phone_source === 'order') {
			number.title = cfg.i18n.phone_order;
		}
		cell.appendChild(number);

		// Opens a normal WhatsApp conversation from the merchant's own account
		// with a draft they can still edit. Nothing is sent from this page.
		if (row.wa_number) {
			var link = document.createElement('a');
			link.className = 'brikpanel-cartab-wa';
			link.href = 'https://wa.me/' + row.wa_number + '?text=' + encodeURIComponent(row.wa_text || '');
			link.target = '_blank';
			link.rel = 'noopener noreferrer';
			// wa_title spells out the number that will be dialled; both strings
			// are translated in PHP, so neither is an English fallback.
			link.title = row.wa_title || cfg.i18n.whatsapp;
			link.setAttribute('aria-label', cfg.i18n.whatsapp);
			link.appendChild(whatsappIcon());
			cell.appendChild(link);
		}

		return cell;
	}

	function renderMailCell(row) {
		var cell = document.createElement('td');
		cell.className = 'brikpanel-cartab-mail-cell';
		var mail = row.mail || {};

		if (!mail.text) {
			cell.appendChild(muted('—'));
			cell.title = cfg.i18n.no_followups;
			return cell;
		}

		var count = document.createElement('span');
		count.className = 'brikpanel-cartab-mail-count';
		count.textContent = mail.text;
		cell.appendChild(count);

		if (mail.note) {
			var note = document.createElement('span');
			note.className = 'brikpanel-cartab-mail-note';
			note.textContent = mail.note;
			cell.appendChild(note);
		}

		return cell;
	}

	function renderDetailsRow(row) {
		var tr = document.createElement('tr');
		tr.className = 'brikpanel-cartab-details-row';
		tr.hidden = true;
		var td = document.createElement('td');
		td.colSpan = cfg.columns || 7;

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
			td.colSpan = cfg.columns || 7;
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

			if (cfg.outreach) {
				tr.appendChild(renderPhoneCell(row));
			}

			tr.appendChild(renderCartCell(row));

			if (cfg.outreach) {
				tr.appendChild(renderMailCell(row));
			}

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
