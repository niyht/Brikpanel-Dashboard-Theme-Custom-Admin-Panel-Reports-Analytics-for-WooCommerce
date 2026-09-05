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

	// items is kept so a column reorder can repaint the table without asking
	// the server for the same page again.
	var state = { page: 1, pages: 1, items: [] };

	// Incremented per list request; only the newest response is allowed to
	// paint. See load().
	var loadToken = 0;

	function $(id) {
		return document.getElementById(id);
	}

	function filters() {
		var range = $('brikpanel-cartab-range').value;
		var custom = range === 'custom';
		return {
			search: $('brikpanel-cartab-search').value.trim(),
			status: $('brikpanel-cartab-status').value,
			source: $('brikpanel-cartab-source').value,
			range: range,
			// The two dates only mean anything under "Custom range"; sending
			// them otherwise would be ignored server-side anyway, but keeping
			// them empty makes the export URL honest about what was applied.
			from: custom ? $('brikpanel-cartab-from').value : '',
			to: custom ? $('brikpanel-cartab-to').value : '',
			sort: $('brikpanel-cartab-sort').value
		};
	}

	/** True when anything other than the untouched default state is applied. */
	function hasActiveFilters() {
		var f = filters();
		return !!(f.search || f.status || f.source || f.range || f.from || f.to ||
			f.sort !== cfg.defaultSort);
	}

	function columnOrder() {
		return Array.isArray(cfg.columnOrder) ? cfg.columnOrder : [];
	}

	function isVisible(colId) {
		return !!(cfg.columnVisible && cfg.columnVisible[colId]);
	}

	/** Width of a full-row cell: every visible column plus the actions column. */
	function fullColSpan() {
		var span = 1;
		columnOrder().forEach(function (id) {
			if (isVisible(id)) {
				span++;
			}
		});
		return span;
	}

	/** A <td> already tagged with the class its column's hide rule targets. */
	function colCell(colId, extraClass) {
		var td = document.createElement('td');
		td.className = 'brikpanel-cartab-col-' + colId + (extraClass ? ' ' + extraClass : '');
		return td;
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
		var cell = colCell('cart');
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

	// A padlock, drawn the same way and at the same size as the WhatsApp mark so
	// the cell keeps its rhythm when a store's subscription lapses.
	// i18n-ignore: SVG path data, not text. WA_PATH above escapes the audit's
	// heuristic only because its coordinates happen to look less word-like.
	var LOCK_PATH = 'M12 1.5A4.5 4.5 0 0 0 7.5 6v3H7a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-9a2 2 0 0 0-2-2h-.5V6A4.5 4.5 0 0 0 12 1.5Zm0 2A2.5 2.5 0 0 1 14.5 6v3h-5V6A2.5 2.5 0 0 1 12 3.5Zm0 10a1.75 1.75 0 0 1 .75 3.33V19a.75.75 0 0 1-1.5 0v-2.17A1.75 1.75 0 0 1 12 13.5Z';

	function lockIcon() {
		var ns = 'http://www.w3.org/2000/svg';
		var svg = document.createElementNS(ns, 'svg');
		svg.setAttribute('viewBox', '0 0 24 24');
		svg.setAttribute('aria-hidden', 'true');
		svg.setAttribute('focusable', 'false');
		var path = document.createElementNS(ns, 'path');
		path.setAttribute('d', LOCK_PATH);
		path.setAttribute('fill', 'currentColor');
		svg.appendChild(path);
		return svg;
	}

	// The locked stand-in for a feature BrikMentor unlocks. Every string comes
	// from the localized config: cfg.i18n.locked is ours, cfg.lockText is
	// BrikMentor's own already-translated sentence, added on a second line only
	// when it is there.
	function lockBadge() {
		var text = cfg.i18n.locked;
		if (cfg.lockText) {
			text += '\n' + cfg.lockText;
		}
		// Without a usable URL this must not be a link: href="" reloads the
		// admin page, which is worse than not being clickable.
		var el = document.createElement(cfg.lockUrl ? 'a' : 'span');
		el.className = 'brikpanel-cartab-lock';
		if (cfg.lockUrl) {
			el.href = cfg.lockUrl;
			el.target = '_blank';
			el.rel = 'noopener noreferrer';
		}
		el.title = text;
		el.setAttribute('aria-label', cfg.i18n.locked);
		el.appendChild(lockIcon());
		return el;
	}

	function muted(text) {
		var el = document.createElement('span');
		el.className = 'brikpanel-cartab-muted';
		el.textContent = text;
		return el;
	}

	// The open count pinned to the WhatsApp button. A number only, so nothing
	// here needs translating; the sentence it belongs to is composed in PHP and
	// goes on the link's own aria-label, because a label there replaces
	// whatever the badge might have said for itself.
	function waCountBadge(count) {
		var el = document.createElement('span');
		el.className = 'brikpanel-cartab-wa-count';
		el.setAttribute('aria-hidden', 'true');
		el.textContent = String(count); // i18n-ignore: a numeral, not text
		return el;
	}

	function renderPhoneCell(row) {
		var cell = colCell('phone', 'brikpanel-cartab-phone-cell');

		if (row.wa_locked) {
			cell.appendChild(lockBadge());
			return cell;
		}

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
			link.setAttribute('data-cartab-id', String(row.id));
			link.appendChild(whatsappIcon());

			// How many times this draft has been opened, answered in PHP by
			// whoever counts it (BrikMentor). Zero draws no badge: an old
			// BrikMentor that cannot count shows the plain button, never a "0".
			var opens = parseInt(row.wa_opens, 10) || 0;
			var badge = null;
			if (opens > 0) {
				badge = waCountBadge(opens);
				link.appendChild(badge);
			}
			// An aria-label on a link REPLACES everything inside it, so the
			// count has to be written into the label or a screen reader never
			// hears it - which is also why the badge itself is aria-hidden
			// rather than left to be read twice. Both halves are composed and
			// translated in PHP: wa_title spells out the number that will be
			// dialled, wa_opens_title how often the draft was opened. Neither
			// is an English fallback.
			link.setAttribute('aria-label', [cfg.i18n.whatsapp, row.wa_opens_title || ''].filter(Boolean).join(' — '));
			link.title = [row.wa_title || cfg.i18n.whatsapp, row.wa_opens_title || ''].filter(Boolean).join('\n');

			// Nothing is sent from this page, but opening the draft is worth
			// counting - it is the only trace this channel leaves. The tab
			// opens whether or not the beacon lands; keepalive lets the
			// request finish even if the merchant navigates away.
			link.addEventListener('click', function () {
				var body = new FormData();
				body.append('action', 'brikpanel_cartab_outreach_click');
				body.append('_ajax_nonce', cfg.nonce);
				body.append('id', String(row.id));
				body.append('channel', 'whatsapp');
				fetch(cfg.ajax_url, { method: 'POST', credentials: 'same-origin', body: body, keepalive: true })
					.then(function (res) { return res.json(); })
					.then(function (json) {
						// THE SERVER'S COUNT, NOT A GUESS. Incrementing here
						// looked simpler and was wrong: repeat opens of the same
						// cart are folded together for ten minutes, so a badge
						// that moved on every click would show a number the
						// database does not hold - and would show it too high,
						// which is the one direction a figure we also report to
						// ourselves may not err. A null means nobody is
						// counting; the badge is then left exactly as drawn.
						var n = json && json.success && json.data ? json.data.opens : null;
						if (typeof n !== 'number' || n <= 0) {
							return;
						}
						opens = n;
						if (!badge) {
							badge = waCountBadge(opens);
							link.appendChild(badge);
						} else {
							badge.textContent = String(opens);
						}
					})
					.catch(function () { /* an uncounted open is not worth interrupting the merchant */ });

				// The sentence behind the count names a date that is about to be
				// stale, and there is no composing its replacement here without
				// shipping plural rules into JS. Drop it rather than announce
				// something untrue; the control keeps its own name and the full
				// sentence comes back on the next load.
				link.title = row.wa_title || cfg.i18n.whatsapp;
				link.setAttribute('aria-label', cfg.i18n.whatsapp);
			});
			cell.appendChild(link);
		}

		return cell;
	}

	function renderMailCell(row) {
		var cell = colCell('mail', 'brikpanel-cartab-mail-cell');
		var mail = row.mail || {};

		if (mail.locked) {
			cell.appendChild(lockBadge());
			return cell;
		}

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

	// One builder per column id. Whatever is missing here is simply skipped,
	// so a column contributed through the PHP filter cannot break the table.
	var CELLS = {
		email: function (row) {
			var td = colCell('email', 'brikpanel-cartab-email-cell');
			td.textContent = row.email;
			return td;
		},
		name: function (row) {
			var td = colCell('name');
			td.textContent = (row.first_name + ' ' + row.last_name).trim() || '—';
			return td;
		},
		phone: renderPhoneCell,
		cart: renderCartCell,
		total: function (row) {
			var td = colCell('total', 'brikpanel-cartab-total-cell');
			// Pre-formatted and entity-decoded server-side.
			td.textContent = row.item_count > 0 ? row.total_h : '—';
			return td;
		},
		mail: renderMailCell,
		source: function (row) {
			var td = colCell('source');
			td.appendChild(badge(cfg.sources[row.source] || row.source, 'source'));
			return td;
		},
		status: function (row) {
			var td = colCell('status');
			// display_status, not the stored status: the badge has to agree
			// with the stat cards, which count "Active" as carts holding items
			// and "Recovered" as carts that were abandoned before they sold.
			var key = row.display_status || row.status;
			td.appendChild(badge(cfg.statuses[key] || key, key));
			return td;
		},
		created: function (row) {
			var td = colCell('created', 'brikpanel-cartab-date-cell');
			td.textContent = row.created_h || '—';
			return td;
		},
		updated: function (row) {
			var td = colCell('updated', 'brikpanel-cartab-date-cell');
			td.textContent = row.updated_h || '—';
			return td;
		}
	};

	function renderDetailsRow(row) {
		var tr = document.createElement('tr');
		tr.className = 'brikpanel-cartab-details-row';
		tr.hidden = true;
		var td = document.createElement('td');
		td.colSpan = fullColSpan();

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
			td.colSpan = fullColSpan();
			td.className = 'brikpanel-cartab-empty';
			td.textContent = cfg.i18n.empty;
			tr.appendChild(td);
			tbody.appendChild(tr);
			return;
		}

		items.forEach(function (row) {
			var tr = document.createElement('tr');

			// Cells follow the user's column order; hiding is left to CSS so a
			// toggle never has to rebuild the table.
			columnOrder().forEach(function (colId) {
				if (CELLS[colId]) {
					tr.appendChild(CELLS[colId](row));
				}
			});

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

		// Filters apply themselves as you type and click, so several requests
		// can be in flight at once and they do not necessarily come back in
		// the order they were sent. Only the newest one may paint, otherwise a
		// slow response for "t" can land after the one for "tztest" and show
		// the wrong rows for the filters currently on screen.
		var token = ++loadToken;

		post('brikpanel_cartab_list', data).then(function (json) {
			if (token !== loadToken) {
				return;
			}
			if (!json || !json.success) {
				window.alert(cfg.i18n.error);
				return;
			}
			var d = json.data;
			state.pages = d.pages;
			state.items = d.items || [];
			render(state.items);

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
			range: f.range,
			from: f.from,
			to: f.to,
			sort: f.sort
		});
		return cfg.ajax_url + '?' + params.toString();
	}

	// =====================================================================
	// Columns popover: visibility toggles + drag / keyboard reordering
	// =====================================================================

	var colsBtn     = $('brikpanel-cartab-columns-btn');
	var colsPopover = $('brikpanel-cartab-columns-popover');
	var colsList    = $('brikpanel-cartab-columns-list');
	var table       = $('brikpanel-cartab-table');
	var theadRow    = $('brikpanel-cartab-thead-row');
	var colsSaveTimer = null;
	var dragItem = null;

	/** Column ids in the order the popover currently shows them. */
	function popoverOrder() {
		return Array.prototype.map.call(
			colsList.querySelectorAll('.brikpanel-cartab-columns-item'),
			function (item) { return item.getAttribute('data-col'); }
		);
	}

	function saveColumns() {
		window.clearTimeout(colsSaveTimer);
		colsSaveTimer = window.setTimeout(function () {
			var body = new FormData();
			body.append('action', 'brikpanel_cartab_save_columns');
			body.append('_ajax_nonce', cfg.nonce);
			popoverOrder().forEach(function (colId) {
				body.append('order[]', colId);
				body.append('visible[' + colId + ']', isVisible(colId) ? '1' : '0');
			});
			fetch(cfg.ajax_url, { method: 'POST', credentials: 'same-origin', body: body })
				.catch(function () { /* a lost preference is not worth interrupting the page */ });
		}, 400);
	}

	function applyColumnVisibility(colId, visible) {
		cfg.columnVisible[colId] = !!visible;
		if (visible) {
			table.removeAttribute('data-hide-' + colId);
		} else {
			table.setAttribute('data-hide-' + colId, '1');
		}
	}

	/** Push the popover's order onto the header and repaint the cached rows. */
	function applyColumnOrder() {
		cfg.columnOrder = popoverOrder();
		var actionsTh = theadRow.querySelector('.brikpanel-cartab-actions-th');
		cfg.columnOrder.forEach(function (colId) {
			var th = theadRow.querySelector('th[data-col="' + colId + '"]');
			if (th) {
				theadRow.appendChild(th);
			}
		});
		if (actionsTh) {
			theadRow.appendChild(actionsTh); // stays pinned to the end
		}
		render(state.items);
		saveColumns();
	}

	function closePopover() {
		if (!colsPopover.hidden) {
			colsPopover.hidden = true;
			colsBtn.setAttribute('aria-expanded', 'false');
		}
	}

	colsBtn.addEventListener('click', function (e) {
		e.stopPropagation();
		var willOpen = colsPopover.hidden;
		colsPopover.hidden = !willOpen;
		colsBtn.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
	});

	colsPopover.addEventListener('click', function (e) {
		e.stopPropagation();
	});

	colsList.addEventListener('change', function (e) {
		var input = e.target;
		if (!input || input.type !== 'checkbox' || !input.hasAttribute('data-col')) {
			return;
		}
		applyColumnVisibility(input.getAttribute('data-col'), input.checked);
		render(state.items); // colSpan of the details / empty rows depends on it
		saveColumns();
	});

	// Drag and drop. setData is required or Firefox refuses to start a drag.
	colsList.addEventListener('dragstart', function (e) {
		var item = e.target.closest ? e.target.closest('.brikpanel-cartab-columns-item') : null;
		if (!item) {
			return;
		}
		dragItem = item;
		item.classList.add('is-dragging');
		if (e.dataTransfer) {
			e.dataTransfer.effectAllowed = 'move';
			e.dataTransfer.setData('text/plain', item.getAttribute('data-col') || '');
		}
	});

	colsList.addEventListener('dragover', function (e) {
		if (!dragItem) {
			return;
		}
		e.preventDefault();
		if (e.dataTransfer) {
			e.dataTransfer.dropEffect = 'move';
		}
		var over = e.target.closest ? e.target.closest('.brikpanel-cartab-columns-item') : null;
		if (!over || over === dragItem) {
			return;
		}
		var box = over.getBoundingClientRect();
		var after = e.clientY > box.top + box.height / 2;
		colsList.insertBefore(dragItem, after ? over.nextSibling : over);
	});

	colsList.addEventListener('drop', function (e) {
		e.preventDefault();
	});

	colsList.addEventListener('dragend', function () {
		if (!dragItem) {
			return;
		}
		dragItem.classList.remove('is-dragging');
		dragItem = null;
		applyColumnOrder();
	});

	// Keyboard equivalent: Alt + arrows move the focused column.
	colsList.addEventListener('keydown', function (e) {
		if (!e.altKey || (e.key !== 'ArrowUp' && e.key !== 'ArrowDown')) {
			return;
		}
		var item = e.target.closest ? e.target.closest('.brikpanel-cartab-columns-item') : null;
		if (!item) {
			return;
		}
		e.preventDefault();
		if (e.key === 'ArrowUp' && item.previousElementSibling) {
			colsList.insertBefore(item, item.previousElementSibling);
		} else if (e.key === 'ArrowDown' && item.nextElementSibling) {
			colsList.insertBefore(item.nextElementSibling, item);
		} else {
			return;
		}
		item.focus();
		applyColumnOrder();
	});

	document.addEventListener('click', closePopover);
	document.addEventListener('keydown', function (e) {
		if (e.key === 'Escape') {
			closePopover();
		}
	});

	// =====================================================================
	// Filters: every control applies itself, so there is no Apply button
	// =====================================================================

	var customRange = $('brikpanel-cartab-custom-range');
	var rangeSelect = $('brikpanel-cartab-range');
	var clearBtn    = $('brikpanel-cartab-clear');
	var searchTimer = null;

	function syncClearButton() {
		clearBtn.hidden = !hasActiveFilters();
	}

	/** Reload from page one; used by every filter control. */
	function reload() {
		state.page = 1;
		syncClearButton();
		load();
	}

	function syncCustomRange() {
		var custom = rangeSelect.value === 'custom';
		customRange.hidden = !custom;
		if (!custom) {
			// Leaving the custom range empties the inputs so reopening it
			// later does not silently re-apply a range from a past session.
			$('brikpanel-cartab-from').value = '';
			$('brikpanel-cartab-to').value = '';
		}
		return custom;
	}

	rangeSelect.addEventListener('change', function () {
		var custom = syncCustomRange();
		// Switching *to* the custom range changes nothing until a date is
		// picked, so only reload when an actual bound went away or applied.
		if (custom) {
			syncClearButton();
			$('brikpanel-cartab-from').focus();
			return;
		}
		reload();
	});

	['brikpanel-cartab-status', 'brikpanel-cartab-source', 'brikpanel-cartab-sort',
		'brikpanel-cartab-from', 'brikpanel-cartab-to'].forEach(function (id) {
		$(id).addEventListener('change', reload);
	});

	// Typing reloads once the user pauses; Enter skips the wait.
	$('brikpanel-cartab-search').addEventListener('input', function () {
		window.clearTimeout(searchTimer);
		searchTimer = window.setTimeout(reload, 400);
	});
	$('brikpanel-cartab-search').addEventListener('keydown', function (e) {
		if (e.key === 'Enter') {
			e.preventDefault();
			window.clearTimeout(searchTimer);
			reload();
		}
	});

	clearBtn.addEventListener('click', function () {
		$('brikpanel-cartab-search').value = '';
		$('brikpanel-cartab-status').value = '';
		$('brikpanel-cartab-source').value = '';
		rangeSelect.value = '';
		$('brikpanel-cartab-sort').value = cfg.defaultSort;
		syncCustomRange();
		reload();
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
