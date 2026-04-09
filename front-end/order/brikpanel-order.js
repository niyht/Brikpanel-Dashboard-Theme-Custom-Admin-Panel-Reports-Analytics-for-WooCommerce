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
				cfg.i18n.orders +
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

		// Position header to align with WP content area
		function positionHeader() {
			var wpcontent = document.getElementById('wpcontent');
			if (wpcontent) {
				header.style.left = wpcontent.getBoundingClientRect().left + 'px';
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
	   AJAX STATUS CHANGE
	   ============================================================ */
	function changeStatus(slug, label) {
		var badge = document.querySelector('.brikpanel-order-header__status-badge');
		var dropdown = document.querySelector('.brikpanel-status-dropdown');

		if (!badge || !cfg.order_id) return;

		// Close dropdown
		dropdown.classList.remove('is-open');
		badge.setAttribute('aria-expanded', 'false');

		// Optimistic UI update
		badge.className = 'brikpanel-order-header__status-badge status--' + slug;
		badge.querySelector('.brk-status-label').textContent = label;

		// Update active state in dropdown
		dropdown.querySelectorAll('.brikpanel-status-dropdown__item').forEach(function (item) {
			item.classList.toggle('is-active', item.getAttribute('data-status') === slug);
		});

		// Also sync WooCommerce's own status select
		var wcSelect = document.querySelector('#order_status');
		if (wcSelect) {
			wcSelect.value = 'wc-' + slug;
			wcSelect.dispatchEvent(new Event('change', { bubbles: true }));
		}

		// AJAX request
		var fd = new FormData();
		fd.append('action', 'brikpanel_change_order_status');
		fd.append('order_id', cfg.order_id);
		fd.append('new_status', slug);
		fd.append('_ajax_nonce', cfg.nonce);

		fetch(cfg.ajax_url, { method: 'POST', body: fd, credentials: 'same-origin' })
			.then(function (r) { return r.json(); })
			.then(function (res) {
				if (res.success) {
					showToast(cfg.i18n.status_changed.replace('%s', label), 'success');
				} else {
					showToast(res.data && res.data.message ? res.data.message : cfg.i18n.error, 'error');
				}
			})
			.catch(function () {
				showToast(cfg.i18n.error, 'error');
			});
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
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
