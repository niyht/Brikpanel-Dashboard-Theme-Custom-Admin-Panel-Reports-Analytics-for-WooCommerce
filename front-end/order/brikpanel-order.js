/**
 * BrikPanel — Modern Order Edit Page
 *
 * AJAX-powered features:
 * - Sticky header with order info + status badge
 * - Inline AJAX status change (dropdown)
 * - Copy address to clipboard
 * - Toast notifications
 * - AJAX note submission
 */
(function () {
	'use strict';

	var cfg = window.brikpanelOrderEdit || {};

	/* ============================================================
	   TOAST
	   ============================================================ */
	function showToast(message, type) {
		type = type || 'success';
		var existing = document.querySelector('.brikpanel-toast');
		if (existing) existing.remove();

		var icon = type === 'success'
			? '<svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>'
			: '<svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/></svg>';

		var toast = document.createElement('div');
		toast.className = 'brikpanel-toast brikpanel-toast--' + type;
		toast.innerHTML = icon + '<span>' + message + '</span>';
		document.body.appendChild(toast);

		requestAnimationFrame(function () {
			requestAnimationFrame(function () {
				toast.classList.add('is-visible');
			});
		});

		setTimeout(function () {
			toast.classList.remove('is-visible');
			setTimeout(function () { toast.remove(); }, 350);
		}, 3500);
	}

	/* ============================================================
	   STICKY HEADER
	   ============================================================ */
	function buildHeader() {
		var wrap = document.querySelector('.wrap');
		if (!wrap || document.querySelector('.brikpanel-order-header')) return;

		var orderId = cfg.order_id || '';
		var orderDate = cfg.order_date || '';
		var currentStatus = cfg.current_status || '';
		var statusLabel = cfg.status_label || '';
		var ordersUrl = cfg.orders_url || 'admin.php?page=wc-orders';

		var header = document.createElement('div');
		header.className = 'brikpanel-order-header';

		// Left side
		var left = document.createElement('div');
		left.className = 'brikpanel-order-header__left';

		left.innerHTML =
			'<a href="' + ordersUrl + '" class="brikpanel-order-header__back">' +
				'<svg width="16" height="16" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 4l-6 6 6 6"/></svg>' +
				'<span class="brk-back-label">' + escHtml(cfg.i18n.orders) + '</span>' +
			'</a>' +
			'<div class="brikpanel-order-header__divider"></div>' +
			'<span class="brikpanel-order-header__title">#' + orderId + '</span>' +
			(orderDate ? ' <span class="brikpanel-order-header__date">&middot; ' + orderDate + '</span>' : '');

		// Right side
		var right = document.createElement('div');
		right.className = 'brikpanel-order-header__right';

		// Status badge
		var statusWrap = document.createElement('div');
		statusWrap.className = 'brikpanel-order-header__status';

		var badge = document.createElement('button');
		badge.type = 'button';
		badge.className = 'brikpanel-order-header__status-badge status--' + currentStatus;
		badge.setAttribute('aria-expanded', 'false');
		badge.innerHTML = '<span class="brk-status-label">' + escHtml(statusLabel) + '</span>' +
			'<svg width="14" height="14" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 8l4 4 4-4"/></svg>';

		var dropdown = document.createElement('div');
		dropdown.className = 'brikpanel-status-dropdown';
		dropdown.setAttribute('role', 'listbox');

		var statuses = cfg.statuses || {};
		Object.keys(statuses).forEach(function (key) {
			var slug = key.replace('wc-', '');
			var btn = document.createElement('button');
			btn.type = 'button';
			btn.className = 'brikpanel-status-dropdown__item' + (slug === currentStatus ? ' is-active' : '');
			btn.setAttribute('data-status', slug);
			btn.setAttribute('role', 'option');
			btn.innerHTML = '<span>' + escHtml(statuses[key]) + '</span>' +
				'<svg class="brk-check" width="16" height="16" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>';
			btn.addEventListener('click', function () { changeStatus(slug, statuses[key]); });
			dropdown.appendChild(btn);
		});

		statusWrap.appendChild(badge);
		statusWrap.appendChild(dropdown);

		// Save button
		var saveBtn = document.createElement('button');
		saveBtn.type = 'button';
		saveBtn.className = 'brk-btn brk-btn--primary brk-save-btn';
		saveBtn.innerHTML = '<svg width="15" height="15" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2"><path d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>' +
			cfg.i18n.save;
		saveBtn.addEventListener('click', function () {
			var origSave = document.querySelector('.save_order.button-primary');
			if (origSave) origSave.click();
		});

		right.appendChild(statusWrap);
		right.appendChild(saveBtn);

		header.appendChild(left);
		header.appendChild(right);

		wrap.insertBefore(header, wrap.firstChild);

		// Position header to align with WP content area and reserve vertical
		// space below the fixed header. The reserve cannot live on the
		// header's next CSS sibling (it is the hidden original <h1>), so we
		// measure the actual overlap and pad #poststuff. This self-adjusts
		// across breakpoints, header heights and translated labels.
		var poststuff = document.getElementById('poststuff');
		function positionHeader() {
			var wpcontent = document.getElementById('wpcontent');
			if (wpcontent) {
				header.style.left = wpcontent.getBoundingClientRect().left + 'px';
			}
			if (poststuff) {
				poststuff.style.paddingTop = '0px';
				var gap = 16;
				var overlap = Math.round(
					header.getBoundingClientRect().bottom - poststuff.getBoundingClientRect().top + gap
				);
				poststuff.style.paddingTop = ( overlap > 0 ? overlap : 0 ) + 'px';
			}
		}
		positionHeader();
		window.addEventListener('resize', positionHeader);

		// Toggle dropdown
		badge.addEventListener('click', function (e) {
			e.stopPropagation();
			var isOpen = dropdown.classList.contains('is-open');
			dropdown.classList.toggle('is-open', !isOpen);
			badge.setAttribute('aria-expanded', String(!isOpen));
		});

		// Close on outside click
		document.addEventListener('click', function () {
			dropdown.classList.remove('is-open');
			badge.setAttribute('aria-expanded', 'false');
		});

		// Close on Escape
		document.addEventListener('keydown', function (e) {
			if (e.key === 'Escape') {
				dropdown.classList.remove('is-open');
				badge.setAttribute('aria-expanded', 'false');
			}
		});
	}

	/* ============================================================
	   STAGE STATUS CHANGE — no AJAX, WC's own Update button persists it
	   ============================================================ */
	function changeStatus(slug, label) {
		var badge = document.querySelector('.brikpanel-order-header__status-badge');
		var dropdown = document.querySelector('.brikpanel-status-dropdown');

		if (!badge || !cfg.order_id) return;

		// Close dropdown
		dropdown.classList.remove('is-open');
		badge.setAttribute('aria-expanded', 'false');

		// Visual update on the BrikPanel badge
		badge.className = 'brikpanel-order-header__status-badge status--' + slug;
		badge.querySelector('.brk-status-label').textContent = label;

		// Update active state in dropdown
		dropdown.querySelectorAll('.brikpanel-status-dropdown__item').forEach(function (item) {
			item.classList.toggle('is-active', item.getAttribute('data-status') === slug);
		});

		// Sync WooCommerce's own status select so the native Update button
		// commits this status when the user submits the form.
		var wcSelect = document.querySelector('#order_status');
		if (wcSelect) {
			wcSelect.value = 'wc-' + slug;
			wcSelect.dispatchEvent(new Event('change', { bubbles: true }));
		}
	}

	/* ============================================================
	   COPY ADDRESS TO CLIPBOARD
	   ============================================================ */
	function addCopyButtons() {
		var columns = document.querySelectorAll('.order_data_column');
		columns.forEach(function (col) {
			var heading = col.querySelector('h3, h4');
			var address = col.querySelector('.address');
			if (!heading || !address) return;

			var text = heading.textContent.trim().toLowerCase();
			if (text.indexOf('billing') === -1 && text.indexOf('shipping') === -1 &&
				text.indexOf('fatura') === -1 && text.indexOf('teslimat') === -1) return;

			var btn = document.createElement('button');
			btn.type = 'button';
			btn.className = 'brikpanel-copy-address';
			btn.innerHTML = '<svg width="13" height="13" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="5" y="5" width="10" height="12" rx="1.5"/><path d="M8 5V3.5A1.5 1.5 0 019.5 2h4A1.5 1.5 0 0115 3.5V14"/></svg>' +
				'<span>' + cfg.i18n.copy + '</span>';

			btn.addEventListener('click', function () {
				var addrText = address.innerText.trim();
				copyToClipboard(addrText).then(function () {
					btn.classList.add('is-copied');
					btn.querySelector('span').textContent = cfg.i18n.copied;
					setTimeout(function () {
						btn.classList.remove('is-copied');
						btn.querySelector('span').textContent = cfg.i18n.copy;
					}, 2000);
					showToast(cfg.i18n.address_copied, 'success');
				});
			});

			heading.style.display = 'flex';
			heading.style.alignItems = 'center';
			heading.style.justifyContent = 'space-between';
			heading.appendChild(btn);
		});
	}

	/* ============================================================
	   AJAX NOTE SUBMISSION
	   ============================================================ */
	function enhanceNotes() {
		var addBtn = document.querySelector('.add_note .button');
		if (!addBtn) return;

		addBtn.addEventListener('click', function (e) {
			// WooCommerce handles note adding via its own AJAX, just show toast on success
			var observer = new MutationObserver(function (mutations) {
				mutations.forEach(function (m) {
					if (m.addedNodes.length > 0) {
						m.addedNodes.forEach(function (node) {
							if (node.nodeType === 1 && node.classList && node.classList.contains('note')) {
								showToast(cfg.i18n.note_added, 'success');
								// Animate the new note
								node.style.opacity = '0';
								node.style.transform = 'translateY(8px)';
								node.style.transition = 'opacity 0.25s ease, transform 0.25s ease';
								requestAnimationFrame(function () {
									requestAnimationFrame(function () {
										node.style.opacity = '1';
										node.style.transform = 'translateY(0)';
									});
								});
								observer.disconnect();
							}
						});
					}
				});
			});

			var notesList = document.querySelector('.order_notes');
			if (notesList) {
				observer.observe(notesList, { childList: true, subtree: true });
				// Disconnect after 10s as safety
				setTimeout(function () { observer.disconnect(); }, 10000);
			}
		});
	}

	/* ============================================================
	   DOWNLOADABLE PRODUCT DOWNLOAD STATS (per line item)
	   ============================================================ */
	function renderItemDownloads() {
		var data = cfg.item_downloads || {};
		if (!data || typeof data !== 'object') return;

		var itemIds = Object.keys(data);
		if (!itemIds.length) return;

		var paint = function () {
			itemIds.forEach(function (itemId) {
				var files = data[itemId];
				if (!Array.isArray(files) || !files.length) return;

				var row = document.querySelector('#order_line_items tr.item[data-order_item_id="' + itemId + '"]');
				if (!row) return;

				var nameCell = row.querySelector('td.name');
				if (!nameCell) return;
				if (nameCell.querySelector('.brk-item-downloads')) return;

				nameCell.appendChild(buildDownloadsBlock(files));
			});
		};

		paint();

		// WooCommerce re-renders the line items table after actions like
		// "Add item" / quantity edits via AJAX, which wipes our injected
		// markup. Re-paint on any childList mutation of the line items tbody.
		var items = document.getElementById('order_line_items');
		if (items && 'MutationObserver' in window) {
			var debounce;
			var observer = new MutationObserver(function () {
				clearTimeout(debounce);
				debounce = setTimeout(paint, 50);
			});
			observer.observe(items, { childList: true, subtree: true });
		}
	}

	function buildDownloadsBlock(files) {
		var wrap = document.createElement('div');
		wrap.className = 'brk-item-downloads';

		var total = 0;
		files.forEach(function (f) { total += (f.count | 0); });

		var header = document.createElement('div');
		header.className = 'brk-item-downloads__header';
		header.innerHTML =
			'<svg class="brk-item-downloads__icon" width="13" height="13" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">' +
				'<path d="M10 3v10"/><path d="M5 9l5 5 5-5"/><path d="M4 16h12"/>' +
			'</svg>' +
			'<span class="brk-item-downloads__title">' + escHtml(cfg.i18n.downloads) + '</span>' +
			'<span class="brk-item-downloads__total">' + escHtml(formatCount(total)) + '</span>';
		wrap.appendChild(header);

		var list = document.createElement('ul');
		list.className = 'brk-item-downloads__list';

		files.forEach(function (f) {
			var li = document.createElement('li');
			li.className = 'brk-item-downloads__file';

			var name = document.createElement('span');
			name.className = 'brk-item-downloads__name';
			name.textContent = f.name || '—';
			li.appendChild(name);

			var meta = document.createElement('span');
			meta.className = 'brk-item-downloads__meta';

			var parts = [];
			parts.push(formatCount(f.count | 0));

			if (f.remaining === null || typeof f.remaining === 'undefined') {
				parts.push(cfg.i18n.unlimited);
			} else {
				parts.push(cfg.i18n.remaining.replace('%s', String(f.remaining)));
			}

			if (f.expires) {
				parts.push(cfg.i18n.expires.replace('%s', f.expires));
			}

			meta.textContent = parts.join(' · ');
			li.appendChild(meta);

			list.appendChild(li);
		});

		wrap.appendChild(list);
		return wrap;
	}

	function formatCount(n) {
		var tmpl = n === 1 ? cfg.i18n.download_one : cfg.i18n.download_many;
		return tmpl.replace('%d', String(n));
	}

	/* ============================================================
	   HELPERS
	   ============================================================ */
	function escHtml(str) {
		var div = document.createElement('div');
		div.appendChild(document.createTextNode(str));
		return div.innerHTML;
	}

	function copyToClipboard(text) {
		if (navigator.clipboard && navigator.clipboard.writeText) {
			return navigator.clipboard.writeText(text);
		}
		// Fallback for non-HTTPS contexts
		var ta = document.createElement('textarea');
		ta.value = text;
		ta.style.cssText = 'position:fixed;left:-9999px;top:-9999px';
		document.body.appendChild(ta);
		ta.select();
		try { document.execCommand('copy'); } catch (e) { /* ignore */ }
		document.body.removeChild(ta);
		return Promise.resolve();
	}

	/* ============================================================
	   INIT
	   ============================================================ */
	function hideOriginalTitle() {
		var wrap = document.querySelector('.wrap');
		if (!wrap) return;
		var els = wrap.querySelectorAll(':scope > h1, :scope > .wp-heading-inline, :scope > .page-title-action, :scope > hr.wp-header-end');
		els.forEach(function (el) { el.style.display = 'none'; });
	}

	function init() {
		if (!document.body.classList.contains('brikpanel-modern-edit')) return;
		hideOriginalTitle();
		buildHeader();
		addCopyButtons();
		enhanceNotes();
		renderItemDownloads();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
