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
		toast.setAttribute('role', 'status');
		toast.innerHTML = icon;
		var text = document.createElement('span');
		text.textContent = message || '';
		toast.appendChild(text);
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

		var isNew = !!cfg.is_new;
		var orderId = cfg.order_id || '';
		// What the header prints. A sequential-order-number plugin can make the
		// shop's number differ from the ID, and the orders list already shows
		// that number, so the two screens have to agree.
		var orderNumber = cfg.order_number || orderId;
		var orderDate = cfg.order_date || '';
		var currentStatus = cfg.current_status || '';
		var statusLabel = cfg.status_label || '';
		var ordersUrl = cfg.orders_url || 'admin.php?page=wc-orders';

		// A new order has no stored status yet: start from the form's own status
		// select, which is what "Create" will save.
		var wcStatusSelect = document.getElementById('order_status');
		if (!currentStatus && wcStatusSelect && wcStatusSelect.value) {
			currentStatus = wcStatusSelect.value.replace(/^wc-/, '');
			var selectedOption = wcStatusSelect.options[wcStatusSelect.selectedIndex];
			statusLabel = selectedOption ? selectedOption.text : '';
		}

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
			'<span class="brikpanel-order-header__title">' + (isNew ? escHtml(cfg.i18n.new_order || '') : '#' + escHtml(String(orderNumber))) + '</span>' +
			(orderDate && !isNew ? ' <span class="brikpanel-order-header__date">&middot; ' + escHtml(orderDate) + '</span>' : '');

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
			escHtml((isNew ? cfg.i18n.create : cfg.i18n.save) || '');
		saveBtn.addEventListener('click', function () {
			var origSave = document.querySelector('.save_order.button-primary');
			if (origSave) origSave.click();
		});

		right.appendChild(statusWrap);
		right.appendChild(saveBtn);

		header.appendChild(left);
		header.appendChild(right);

		wrap.insertBefore(header, wrap.firstChild);

		// Pull WordPress's "Screen Options" / "Help" toggles up into this header.
		// WP floats #screen-meta-links at the very top of the content — exactly
		// where this fixed header sits — so the buttons end up hidden behind it.
		// Moving the whole container here (its native toggle handlers travel with
		// the node) puts them back within reach beside the order actions. The
		// slide-down panel (#screen-meta) needs no nudge of its own: it lives
		// inside #wpbody-content, which now carries the reservation.
		var screenMetaLinks = document.getElementById('screen-meta-links');
		if (screenMetaLinks && !screenMetaLinks.closest('.brikpanel-order-header')) {
			var hasToggle = false;
			screenMetaLinks.querySelectorAll('.screen-meta-toggle button').forEach(function (b) {
				if ((b.textContent || '').trim() !== '') hasToggle = true;
			});
			if (hasToggle) {
				screenMetaLinks.classList.add('brikpanel-order-screen-meta');
				right.insertBefore(screenMetaLinks, right.firstChild);
			}
		}

		// Align the fixed header with the WP content column and publish its
		// height. The vertical reservation itself is a plain CSS rule on
		// #wpbody-content (see brikpanel-order.css) that consumes the custom
		// property set here, so it stays correct across every breakpoint and
		// `top:` override without this function re-running.
		//
		// The previous approach padded #poststuff by the measured overlap, and
		// inverted whenever a notice was on screen: #poststuff lives inside
		// <form id="order">, below the notices, so a notice pushed it down, the
		// measured overlap went negative, the code concluded nothing needed
		// reserving, and the notice absorbed the entire clipping.
		//
		// The reservation assumes the bar's `top` equals the top of
		// #wpbody-content. That is true with WordPress's default chrome, but it
		// is an assumption the CSS cannot check, and it is false whenever the
		// admin bar is not showing: core only puts the `wp-toolbar` class on
		// <html> when is_admin_bar_showing() is true, and it is that class alone
		// that carries `padding-top: var(--wp-admin--admin-bar--height)`. A
		// white-label plugin filtering show_admin_bar to false therefore moves
		// the content column to y=0 while the bar stays pinned at 32px, and the
		// reservation ends up 32px short — the exact clipping this was meant to
		// fix. The same goes for anything that redefines
		// --wp-admin--admin-bar--height, which the hardcoded 32/46 duplicates.
		//
		// So measure the invariant instead of trusting it: publish the content
		// column's own top edge and let the bar sit on it.
		var poststuff = document.getElementById('poststuff');
		var bodyContent = document.getElementById('wpbody-content');
		var metricsFrame = 0;
		var lastHeight = '';
		var lastTop = '';
		var lastLeft = '';
		var lastRight = '';

		function applyHeaderMetrics() {
			metricsFrame = 0;

			var wpcontent = document.getElementById('wpcontent');
			if (wpcontent) {
				var contentRect = wpcontent.getBoundingClientRect();
				var left = contentRect.left + 'px';
				if (left !== lastLeft) {
					lastLeft = left;
					header.style.left = left;
				}
				// RTL docks the sidebar on the right, so the bar has to stop at the
				// content column's right edge too, or it runs underneath the menu.
				if (document.body.classList.contains('rtl')) {
					var right = Math.max(0, document.documentElement.clientWidth - contentRect.right) + 'px';
					if (right !== lastRight) {
						lastRight = right;
						header.style.right = right;
					}
				}
			}

			// Written to documentElement rather than as an inline style on a node
			// inside .wrap, so the observers below can never be re-triggered by
			// our own write. Both values are compared before writing: the
			// #wpcontent observer below reacts to the reservation this property
			// drives, so an unconditional write is a feedback edge, and a
			// re-write that lands on a scrollbar threshold can oscillate.
			var height = Math.ceil(header.getBoundingClientRect().height) + 'px';
			if (height !== lastHeight) {
				lastHeight = height;
				document.documentElement.style.setProperty('--brk-order-header-h', height);
			}

			// The column's border-box top. Our own reservation is padding INSIDE
			// that box, so measuring it here cannot feed back into itself.
			if (bodyContent) {
				var top = Math.max(0, Math.round(
					bodyContent.getBoundingClientRect().top + (window.pageYOffset || document.documentElement.scrollTop || 0)
				)) + 'px';
				if (top !== lastTop) {
					lastTop = top;
					document.documentElement.style.setProperty('--brk-order-header-top', top);
				}
			}

			// Clear the inline padding a cached copy of the previous script may
			// have left behind; the reservation is CSS-owned now.
			if (poststuff && poststuff.style.paddingTop) {
				poststuff.style.paddingTop = '';
			}
		}

		function scheduleHeaderMetrics() {
			if (metricsFrame) return;
			metricsFrame = window.requestAnimationFrame(applyHeaderMetrics);
		}

		applyHeaderMetrics();
		window.addEventListener('resize', scheduleHeaderMetrics);

		// Folding the admin menu changes the content column without changing the
		// window, so it is invisible to the resize listener. Core announces it on
		// the jQuery document bus, which is the only signal available when
		// ResizeObserver is missing — and the observer below coalesces the
		// duplicate when it is present.
		if (window.jQuery) {
			window.jQuery(document).on('wp-collapse-menu wp-menu-state-set wp-window-resized', scheduleHeaderMetrics);
		}

		if (typeof ResizeObserver === 'function') {
			// The bar reflows on its own: translated labels wrap, the status badge
			// changes width after an AJAX status change, and the 782px / 600px
			// breakpoints change its min-height. Watching the bar is sufficient —
			// the reservation no longer depends on where the notices sit, so
			// adding or dismissing one cannot invalidate it.
			new ResizeObserver(scheduleHeaderMetrics).observe(header);

			// Collapsing / expanding the admin menu moves the content column's
			// left edge; the bar is fixed, so it does not follow on its own.
			var contentCol = document.getElementById('wpcontent');
			if (contentCol) {
				new ResizeObserver(scheduleHeaderMetrics).observe(contentCol);
			}
		}

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

		// Staging only touches the form's status select, so a new order (no id
		// yet) can pick its status here too.
		if (!badge || !dropdown) return;

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
	var itemDownloads = (cfg.item_downloads && typeof cfg.item_downloads === 'object') ? cfg.item_downloads : {};

	function paintItemDownloads(replace) {
		document.querySelectorAll('#order_line_items tr.item').forEach(function (row) {
			var nameCell = row.querySelector('td.name');
			if (!nameCell) return;
			var current = nameCell.querySelector('.brk-item-downloads');
			var files = itemDownloads[row.getAttribute('data-order_item_id')];
			var hasFiles = Array.isArray(files) && files.length > 0;
			if (current && (replace || !hasFiles)) {
				current.remove();
				current = null;
			}
			if (hasFiles && !current) nameCell.appendChild(buildDownloadsBlock(files));
		});
	}

	function renderItemDownloads() {
		paintItemDownloads(false);

		// WooCommerce re-renders the line items table after actions like
		// "Add item" / quantity edits via AJAX, which wipes our injected
		// markup. Re-paint on any childList mutation of the line items tbody.
		// The tbody itself is replaced on reload, so watch the whole items box.
		var items = document.getElementById('woocommerce-order-items');
		if (items && 'MutationObserver' in window) {
			var debounce;
			var observer = new MutationObserver(function (mutations) {
				// Our own block going in or out is not a reason to paint again.
				var foreign = mutations.some(function (m) {
					return Array.prototype.some.call(m.addedNodes, function (n) {
						return !(n.nodeType === 1 && n.classList.contains('brk-item-downloads'));
					}) || Array.prototype.some.call(m.removedNodes, function (n) {
						return !(n.nodeType === 1 && n.classList.contains('brk-item-downloads'));
					});
				});
				if (!foreign) return;
				clearTimeout(debounce);
				debounce = setTimeout(function () { paintItemDownloads(false); }, 50);
			});
			observer.observe(items, { childList: true, subtree: true });
		}
	}

	/* WooCommerce grants and revokes download access over AJAX, in the
	   "Downloadable product permissions" box. Confirm it with a toast, mark
	   the new rows, and refresh the download details under each item so the
	   Items tab shows the change without a reload. */
	function watchDownloadPermissions() {
		if (!window.jQuery) return;
		var $ = window.jQuery;

		function actionOf(settings) {
			var data = settings && settings.data;
			if (typeof data !== 'string') return '';
			var match = data.match(/(?:^|&)action=([^&]+)/);
			return match ? decodeURIComponent(match[1]) : '';
		}

		function refreshItemDownloads() {
			var orderId = cfg.order_id || (document.getElementById('post_ID') || {}).value;
			if (!orderId || !cfg.downloads_nonce || !cfg.ajax_url) return;
			$.post(cfg.ajax_url, {
				action: 'brikpanel_order_item_downloads',
				order_id: orderId,
				nonce: cfg.downloads_nonce
			}).done(function (response) {
				if (!response || !response.success || !response.data || typeof response.data !== 'object') return;
				itemDownloads = response.data;
				paintItemDownloads(true);
			});
		}

		$(document).ajaxSuccess(function (event, xhr, settings) {
			var action = actionOf(settings);
			var box = document.getElementById('woocommerce-order-downloads');
			if (action === 'woocommerce_grant_access_to_download') {
				var html = String(xhr && xhr.responseText || '');
				// WooCommerce answers "-1" or nothing when it could not grant, and
				// shows its own alert for that.
				if (html.indexOf('wc-metabox') === -1) return;
				window.setTimeout(function () {
					if (box) {
						var rows = box.querySelectorAll('.wc-metaboxes > .wc-metabox');
						var fresh = Array.prototype.slice.call(rows).filter(function (row) { return !row.hasAttribute('data-bp-seen'); });
						fresh.forEach(function (row) {
							row.classList.add('bp-perm-new');
							window.setTimeout(function () { row.classList.remove('bp-perm-new'); }, 2400);
						});
						markSeenPermissions();
						if (fresh[0] && fresh[0].scrollIntoView && fresh[0].getBoundingClientRect().bottom > window.innerHeight) {
							fresh[0].scrollIntoView({ block: 'nearest', behavior: 'smooth' });
						}
					}
				}, 0);
				showToast(cfg.i18n.access_granted, 'success');
				refreshItemDownloads();
			} else if (action === 'woocommerce_revoke_access_to_download') {
				showToast(cfg.i18n.access_revoked, 'success');
				refreshItemDownloads();
			}
		});

		markSeenPermissions();
	}

	function markSeenPermissions() {
		document.querySelectorAll('#woocommerce-order-downloads .wc-metaboxes > .wc-metabox').forEach(function (row) {
			row.setAttribute('data-bp-seen', '1');
		});
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
	   TABBED LAYOUT
	   One card with Items / Customer / Notes / More tabs on the left and
	   a summary column on the right. WooCommerce's own boxes are MOVED
	   into the tabs, never rebuilt, so every native control (add item,
	   refund, recalculate, address editing, customer search, notes) and
	   every other plugin's box keeps its markup, ids and handlers. The
	   boxes stay inside the order <form>, so saving is unchanged.
	   ============================================================ */
	var TAB_BOXES = {
		items: ['woocommerce-order-items'],
		customer: ['woocommerce-order-data', 'brikpanel_order_fields', 'woocommerce-customer-history'],
		notes: ['woocommerce-order-notes']
	};
	// Stay in the right-hand column under the summary. Everything else,
	// BrikPanel's own shipping cost box included, lives under More.
	var SIDE_BOXES = ['woocommerce-order-actions'];
	// Replaced by BrikPanel's own "Additional details" card and kept hidden.
	var HIDDEN_BOXES = ['order_custom'];

	function buildTabs() {
		var postBody = document.getElementById('post-body');
		var mainCol = document.getElementById('postbox-container-2');
		var sideCol = document.getElementById('postbox-container-1');
		if (!postBody || !mainCol || !sideCol || document.querySelector('.bp-otabs')) return;

		var i18n = cfg.i18n || {};
		document.body.classList.add('bp-order-tabs');

		var postIdInput = document.getElementById('post_ID');
		var orderKey = String(cfg.order_id || (postIdInput && postIdInput.value) || 'new');
		var storageKey = 'brikpanelOrderTab:' + orderKey;

		var TABS = [
			{ key: 'items', label: i18n.tab_items || '' },
			{ key: 'customer', label: i18n.tab_customer || '' },
			{ key: 'notes', label: i18n.tab_notes || '' },
			{ key: 'more', label: i18n.tab_more || '' }
		];

		var card = document.createElement('div');
		card.className = 'bp-otabs';

		var bar = document.createElement('div');
		bar.className = 'bp-otabs__bar';
		bar.setAttribute('role', 'tablist');
		bar.setAttribute('aria-label', i18n.tabs_label || '');
		card.appendChild(bar);

		var buttons = {};
		var panels = {};
		var counts = {};
		TABS.forEach(function (tab) {
			var button = document.createElement('button');
			button.type = 'button';
			button.className = 'bp-otabs__tab';
			button.id = 'bp-otab-' + tab.key;
			button.setAttribute('role', 'tab');
			button.setAttribute('aria-controls', 'bp-otabpanel-' + tab.key);
			button.setAttribute('data-tab', tab.key);

			var label = document.createElement('span');
			label.textContent = tab.label;
			button.appendChild(label);

			var count = document.createElement('span');
			count.className = 'bp-otabs__count';
			button.appendChild(count);

			bar.appendChild(button);
			buttons[tab.key] = button;
			counts[tab.key] = count;

			var panel = document.createElement('div');
			panel.className = 'bp-otabs__panel bp-otabs__panel--' + tab.key;
			panel.id = 'bp-otabpanel-' + tab.key;
			panel.setAttribute('role', 'tabpanel');
			panel.setAttribute('aria-labelledby', button.id);
			panel.tabIndex = -1;
			card.appendChild(panel);
			panels[tab.key] = panel;
		});

		mainCol.insertBefore(card, mainCol.firstChild);

		// New orders have no payment line yet; an empty one only leaves a gap.
		var orderMeta = document.querySelector('#woocommerce-order-data .woocommerce-order-data__meta');
		if (orderMeta && !orderMeta.textContent.trim()) orderMeta.hidden = true;

		// Named boxes first, in their tab order.
		Object.keys(TAB_BOXES).forEach(function (key) {
			TAB_BOXES[key].forEach(function (id) {
				var box = document.getElementById(id);
				if (box) panels[key].appendChild(box);
			});
		});

		// The boxes a merchant reaches for on every single order — the invoice,
		// the shipping label, the tracking number — open in the right column
		// instead of costing a click under More. Which ones is decided on the
		// server (front-end/order/brikpanel-order-box-placement.php): a curated
		// list of well known plugins, plus whatever this person chose for
		// themselves under Screen Options. Always a plain list; anything else
		// would throw and cost the whole tabbed layout.
		var sidebarBoxes = Array.isArray(cfg.sidebar_boxes) ? cfg.sidebar_boxes : [];

		// Everything else (downloads, attribution, other plugins' boxes from
		// either column) goes to More, in the order WordPress printed it.
		var keep = SIDE_BOXES.concat(HIDDEN_BOXES, sidebarBoxes);
		var rest = Array.prototype.slice.call(
			document.querySelectorAll('#normal-sortables > .postbox, #advanced-sortables > .postbox, #side-sortables > .postbox, #post-body-content > .postbox')
		);
		rest.forEach(function (box) {
			if (keep.indexOf(box.id) !== -1) return;
			panels.more.appendChild(box);
		});

		// Summary card at the top of the right column, then order actions, then
		// the boxes chosen for that column.
		var summary = buildSummary();
		sideCol.insertBefore(summary, sideCol.firstChild);
		var sideSortables = document.getElementById('side-sortables');
		if (sideSortables) {
			// Prepended rather than appended: now that other boxes stay in this
			// column, appending would push order actions under them, and
			// registration order is no guide (WooCommerce Tax hooks
			// `add_meta_boxes` at priority 5, WooCommerce's own order boxes at
			// 30 on the legacy screen). Reversed so the list order is kept.
			SIDE_BOXES.slice().reverse().forEach(function (id) {
				var box = document.getElementById(id);
				if (box) sideSortables.insertBefore(box, sideSortables.firstChild);
			});
			sidebarBoxes.forEach(function (id) {
				var box = document.getElementById(id);
				// A box WordPress already printed here is left exactly where it
				// is. Some plugins find their own box by walking the DOM, and
				// re-parenting a node reloads any iframe inside it, so the
				// cheapest move is the one not made.
				if (box && box.parentElement !== sideSortables) sideSortables.appendChild(box);
			});
			// Anything that measured itself before this ran — every
			// `wp.domReady` consumer enqueued ahead of this script — is now in a
			// 320px column and has to look again. Queued as a jQuery ready
			// callback, which lands after every other plugin's own setup.
			if (window.jQuery) {
				window.jQuery(function () { window.dispatchEvent(new Event('resize')); });
			}
		}

		function isShown(box) {
			// Computed only: a box saved as hidden keeps its hide-if-js class after
			// Screen Options shows it again with an inline style. A closed panel
			// does not change its children's own computed display.
			return getComputedStyle(box).display !== 'none';
		}

		function refreshCounts() {
			var items = document.querySelectorAll('#order_line_items tr.item').length;
			var notes = document.querySelectorAll('#woocommerce-order-notes ul.order_notes > li.note').length;
			var more = Array.prototype.filter.call(panels.more.querySelectorAll(':scope > .postbox'), isShown).length;
			counts.items.textContent = items ? String(items) : '';
			counts.notes.textContent = notes ? String(notes) : '';
			counts.more.textContent = more ? String(more) : '';
			counts.customer.textContent = '';
			// A tab with nothing in it is not offered: More when no other box
			// exists, and any tab whose boxes were all switched off in Screen
			// Options (a preference that may predate the tabs).
			TABS.forEach(function (tab) {
				var shown = tab.key === 'more'
					? more > 0
					: Array.prototype.some.call(panels[tab.key].querySelectorAll(':scope > .postbox'), isShown);
				buttons[tab.key].hidden = !shown;
			});
			if (active && buttons[active].hidden) select(active, false);
		}

		function firstVisibleTab() {
			for (var i = 0; i < TABS.length; i++) {
				if (!buttons[TABS[i].key].hidden) return TABS[i].key;
			}
			return 'items';
		}

		var active = '';
		function select(key, focus) {
			if (!buttons[key] || buttons[key].hidden) key = firstVisibleTab();
			active = key;
			TABS.forEach(function (tab) {
				var on = tab.key === key;
				buttons[tab.key].setAttribute('aria-selected', on ? 'true' : 'false');
				buttons[tab.key].tabIndex = on ? 0 : -1;
				panels[tab.key].hidden = !on;
			});
			try { window.sessionStorage.setItem(storageKey, key); } catch (e) { /* storage unavailable */ }
			if (focus) buttons[key].focus();
			// Widgets measured while hidden (select2) size themselves on resize.
			window.dispatchEvent(new Event('resize'));
		}

		bar.addEventListener('click', function (e) {
			var button = e.target.closest('.bp-otabs__tab');
			if (button) select(button.getAttribute('data-tab'), false);
		});

		bar.addEventListener('keydown', function (e) {
			var visible = TABS.map(function (t) { return t.key; }).filter(function (k) { return !buttons[k].hidden; });
			var index = visible.indexOf(active);
			var next = null;
			if (e.key === 'ArrowRight' || e.key === 'ArrowDown') next = visible[(index + 1) % visible.length];
			else if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') next = visible[(index - 1 + visible.length) % visible.length];
			else if (e.key === 'Home') next = visible[0];
			else if (e.key === 'End') next = visible[visible.length - 1];
			if (next) {
				e.preventDefault();
				select(next, true);
			}
		});

		// Screen Options → "Show in the sidebar". The curated default cannot know
		// every shipping plugin in every country, so each person can move any
		// box either way. The box moves at once and the choice is saved after.
		var sidePrefs = document.querySelector('#screen-options-wrap .bp-side-boxes');
		if (sidePrefs && sideSortables) {
			var prefError = sidePrefs.querySelector('.bp-side-boxes__error');
			var prefToggles = Array.prototype.slice.call(sidePrefs.querySelectorAll('.bp-side-box-tog'));
			var prefBusy = false;
			var prefDirty = false;
			var prefSaved = prefToggles.map(function (toggle) { return toggle.checked; });

			// Only ever the box that was just toggled. Walking the whole list
			// would drag boxes nobody asked about: a plugin is free to move its
			// own box after this script runs (WP Overnight puts its document
			// data next to the order details), and re-homing that on an
			// unrelated click would undo it.
			var moveBox = function (toggle) {
				var box = document.getElementById(toggle.value);
				if (!box) return;
				var target = toggle.checked ? sideSortables : panels.more;
				if (box.parentElement !== target) target.appendChild(box);
			};

			var applyPrefs = function (toggles) {
				toggles.forEach(moveBox);
				refreshCounts();
				// A box that just changed column has to measure itself again.
				window.dispatchEvent(new Event('resize'));
			};

			// One request at a time, always carrying the latest state, so quick
			// clicks cannot reach the server out of order and store an old set.
			var savePrefs = function () {
				if (prefBusy) { prefDirty = true; return; }
				prefBusy = true;
				prefDirty = false;
				var body = new window.URLSearchParams({
					action: 'brikpanel_order_side_boxes',
					nonce: sidePrefs.dataset.nonce || ''
				});
				prefToggles.forEach(function (toggle) {
					body.append('boxes[' + toggle.value + ']', toggle.checked ? 'side' : 'more');
				});
				window.fetch(cfg.ajax_url, { method: 'POST', credentials: 'same-origin', body: body })
					.then(function (response) { return response.json(); })
					.then(function (result) {
						if (!result || !result.success) throw new Error();
						prefSaved = prefToggles.map(function (toggle) { return toggle.checked; });
					})
					.catch(function () {
						// Put back what is stored, so the screen never shows an
						// arrangement that would be gone after a reload. Only the
						// boxes this session actually moved are put back.
						prefDirty = false;
						var reverted = [];
						prefToggles.forEach(function (toggle, index) {
							if (toggle.checked === prefSaved[index]) return;
							toggle.checked = prefSaved[index];
							reverted.push(toggle);
						});
						applyPrefs(reverted);
						if (prefError) {
							prefError.textContent = (cfg.i18n && cfg.i18n.error) || '';
							prefError.hidden = false;
						}
					})
					.then(function () {
						prefBusy = false;
						if (prefDirty) savePrefs();
					});
			};

			sidePrefs.addEventListener('change', function (e) {
				if (!e.target.classList || !e.target.classList.contains('bp-side-box-tog')) return;
				applyPrefs([e.target]);
				if (prefError) prefError.hidden = true;
				savePrefs();
			});

			var prefReset = sidePrefs.querySelector('.bp-side-boxes__reset');
			if (prefReset) {
				prefReset.addEventListener('click', function () {
					// The shipped defaults are not in the page — only the result
					// of applying them — so the server clears the choices and
					// the screen is drawn again from scratch.
					var body = new window.URLSearchParams({
						action: 'brikpanel_order_side_boxes',
						nonce: sidePrefs.dataset.nonce || '',
						reset: '1'
					});
					prefReset.disabled = true;
					window.fetch(cfg.ajax_url, { method: 'POST', credentials: 'same-origin', body: body })
						.then(function () { window.location.reload(); })
						.catch(function () {
							prefReset.disabled = false;
							if (prefError) {
								prefError.textContent = (cfg.i18n && cfg.i18n.error) || '';
								prefError.hidden = false;
							}
						});
				});
			}
		}

		// WordPress saves which boxes are hidden (Screen Options, collapse toggles)
		// by collecting every `.postbox` that is `:hidden` on screen. Boxes in an
		// inactive tab match that, so the first Screen Options click stored the
		// Customer / Notes / More boxes as hidden and they vanished on the next
		// load. Open every panel for the synchronous read, then restore; nothing
		// paints in between.
		if (window.postboxes && typeof window.postboxes.save_state === 'function' && !window.postboxes.bpTabsPatched) {
			var saveState = window.postboxes.save_state;
			window.postboxes.save_state = function () {
				var closedPanels = Array.prototype.slice.call(document.querySelectorAll('.bp-otabs__panel[hidden]'));
				closedPanels.forEach(function (panel) { panel.hidden = false; });
				try {
					return saveState.apply(this, arguments);
				} finally {
					closedPanels.forEach(function (panel) { panel.hidden = true; });
				}
			};
			window.postboxes.bpTabsPatched = true;
		}

		// WordPress also persists WHICH COLUMN each box sits in. `save_order`
		// reads `$('.meta-box-sortables').sortable('toArray')` straight off the
		// live DOM and posts it as `order[side]` / `order[normal]`, which
		// `wp_ajax_meta_box_order` writes to the `meta-box-order_<screen>` user
		// meta. `do_meta_boxes()` then replays that on every load — including
		// with this feature switched off, and even with BrikPanel deactivated.
		// Since this screen arranges the boxes itself, persisting its own
		// arrangement would silently rewrite the merchant's native layout for
		// good. Dropping the `order[*]` vars stops that: the handler only writes
		// the meta `if ( $order )`, while `page_columns` still saves, so the
		// legacy 1/2-column picker keeps working.
		if (window.postboxes && typeof window.postboxes.save_order === 'function' && !window.postboxes.bpOrderPatched) {
			window.postboxes.save_order = function (page) {
				if (!window.jQuery) return;
				var $ = window.jQuery;
				$.post(window.ajaxurl || cfg.ajax_url, {
					action: 'meta-box-order',
					_ajax_nonce: $('#meta-box-order-nonce').val(),
					page_columns: $('.columns-prefs input:checked').val() || 0,
					page: page
				});
			};
			window.postboxes.bpOrderPatched = true;
		}

		// Nothing on this screen is meant to be dragged: the right column is
		// built from a saved preference and the boxes inside the tabs are not in
		// a sortable at all. A drag that cannot be saved would just snap back on
		// the next load, so the drag itself is switched off.
		//
		// `sortable('disable')` does NOT work here: core's own
		// `columns.enableSortables()` (wp-admin/js/common.js) re-enables every
		// `.meta-box-sortables` on each `wp-window-resized`, and this file fires
		// synthetic resize events of its own. `cancel` is the option core never
		// touches, and matching everything stops a drag from ever starting.
		//
		// jQuery UI binds the sortable inside `postboxes.init()`, so this wraps
		// that call rather than racing it on jQuery ready: the script runs on
		// DOMContentLoaded, always before any ready callback, so the wrapper is
		// in place in time.
		if (window.postboxes && typeof window.postboxes.init === 'function' && !window.postboxes.bpInitPatched) {
			var postboxInit = window.postboxes.init;
			window.postboxes.init = function () {
				var result = postboxInit.apply(this, arguments);
				try {
					var $side = window.jQuery && window.jQuery('#side-sortables');
					if ($side && $side.length && $side.data('ui-sortable')) {
						$side.sortable('option', 'cancel', '*');
					}
				} catch (error) { /* jQuery UI missing; nothing to lock */ }
				return result;
			};
			window.postboxes.bpInitPatched = true;
		}

		// A required field inside a hidden tab would block saving without the
		// browser being able to show it: open its tab first.
		var form = document.getElementById('order') || document.getElementById('post');
		if (form) {
			form.addEventListener('invalid', function (e) {
				var panel = e.target.closest && e.target.closest('.bp-otabs__panel');
				if (panel && panel.hidden) {
					select(panel.id.replace('bp-otabpanel-', ''), false);
				}
			}, true);
		}

		// Links to a box (e.g. "#woocommerce-order-notes") open its tab.
		function openTabForHash() {
			var hash = window.location.hash.replace('#', '');
			if (!hash) return false;
			var target = document.getElementById(hash);
			var panel = target && target.closest('.bp-otabs__panel');
			if (!panel) return false;
			select(panel.id.replace('bp-otabpanel-', ''), false);
			return true;
		}
		window.addEventListener('hashchange', openTabForHash);

		refreshCounts();
		if (!openTabForHash()) {
			var initial = cfg.is_new ? 'customer' : 'items';
			try {
				var saved = window.sessionStorage.getItem(storageKey);
				if (saved && buttons[saved]) initial = saved;
			} catch (e) { /* storage unavailable */ }
			select(initial, false);
		}

		// Items reload over AJAX (add, delete, recalculate) and notes are added
		// without a reload; Screen Options can show or hide boxes.
		if ('MutationObserver' in window) {
			var pending = 0;
			var schedule = function () {
				if (pending) return;
				pending = window.requestAnimationFrame(function () {
					pending = 0;
					refreshCounts();
					updateSummaryTotal();
				});
			};
			[panels.items, panels.notes, panels.more].forEach(function (panel) {
				new MutationObserver(schedule).observe(panel, { childList: true, subtree: true, attributes: true, attributeFilter: ['style', 'class'] });
			});
			// Customer only needs to know when one of its boxes is shown or hidden.
			panels.customer.querySelectorAll(':scope > .postbox').forEach(function (box) {
				new MutationObserver(schedule).observe(box, { attributes: true, attributeFilter: ['style', 'class'] });
			});
		}
		if (window.jQuery) {
			window.jQuery(document.body).on('order-totals-recalculate-complete', function () {
				window.requestAnimationFrame(updateSummaryTotal);
			});
		}

		// The right column follows the page only while it fits on screen.
		/* Sticky offsets are measured, not assumed: depending on the width the
		   page scrolls either the window or <body> (BrikPanel's top bar layout),
		   and a sticky element's `top` counts from whichever one scrolls. */
		var header = document.querySelector('.brikpanel-order-header');
		function syncSticky() {
			var body = document.body;
			// <body> overflow only makes <body> scroll when <html> is not
			// `visible`; otherwise the browser hands it to the window.
			var bodyScrolls = /(auto|scroll)/.test(getComputedStyle(body).overflowY) &&
				getComputedStyle(document.documentElement).overflowY !== 'visible' &&
				body.scrollHeight > body.clientHeight;
			var base = bodyScrolls ? body.getBoundingClientRect().top : 0;
			var headerBottom = header ? header.getBoundingClientRect().bottom : 0;
			var top = Math.max(0, Math.round(headerBottom - base));
			document.documentElement.style.setProperty('--bp-otabs-top', top + 'px');

			// The right column follows the page only while it sits beside the
			// tabs and fits on screen.
			var sideBesideMain = sideCol.getBoundingClientRect().left > mainCol.getBoundingClientRect().left + 10;
			var fits = sideCol.getBoundingClientRect().height + headerBottom + 32 < window.innerHeight;
			body.classList.toggle('bp-order-side-sticky', fits && sideBesideMain);

			syncBarFade();
		}
		// Tabs that do not fit fade out at the edge instead of being cut, until
		// the bar is scrolled to its end (scrollLeft is negative right to left).
		function syncBarFade() {
			bar.classList.toggle('is-overflowing', bar.scrollWidth - Math.abs(bar.scrollLeft) - bar.clientWidth > 1);
		}
		bar.addEventListener('scroll', syncBarFade, { passive: true });
		syncSticky();
		window.addEventListener('resize', syncSticky);
		// On phones the window can scroll a little on top of <body>, which moves
		// the reference edge; re-measure after scrolling settles.
		var scrollFrame = 0;
		window.addEventListener('scroll', function () {
			if (scrollFrame) return;
			scrollFrame = window.requestAnimationFrame(function () {
				scrollFrame = 0;
				syncSticky();
			});
		}, { passive: true });
		if (typeof ResizeObserver === 'function') {
			var stickyObserver = new ResizeObserver(syncSticky);
			stickyObserver.observe(sideCol);
			stickyObserver.observe(card);
			if (header) stickyObserver.observe(header);
		}

		/* Narrow screens show each item as a small card; each value keeps the
		   column name from the table header as its label. WooCommerce rebuilds
		   the items table over AJAX, so labels are re-applied after it does. */
		function labelItemCells() {
			var table = panels.items.querySelector('table.woocommerce_order_items');
			if (!table) return;
			var labels = {};
			table.querySelectorAll('thead th').forEach(function (th) { // i18n-ignore: CSS selector
				var key = Array.prototype.find.call(th.classList, function (cls) { return cls !== 'sortable' && cls !== 'sorted'; });
				if (key) labels[key] = th.textContent.replace(/\s+/g, ' ').trim();
			});
			table.querySelectorAll('tbody td').forEach(function (td) { // i18n-ignore: CSS selector
				// Fee, shipping and refund rows leave price and quantity empty;
				// their labels are not shown for nothing.
				var empty = td.textContent.replace(/\s+/g, '') === '' && !td.querySelector('input, select, textarea, img, svg, button, a');
				td.toggleAttribute('data-bp-empty', empty);
				Array.prototype.some.call(td.classList, function (cls) {
					if (!labels[cls]) return false;
					if (td.getAttribute('data-bp-label') !== labels[cls]) td.setAttribute('data-bp-label', labels[cls]);
					return true;
				});
			});
		}
		labelItemCells();
		if ('MutationObserver' in window) {
			var labelPending = 0;
			new MutationObserver(function () {
				if (labelPending) return;
				labelPending = window.requestAnimationFrame(function () {
					labelPending = 0;
					labelItemCells();
				});
			}).observe(panels.items, { childList: true, subtree: true });
		}
	}

	function orderTotalHtmlNode() {
		// The order total is always the last row of the first totals table.
		var cell = document.querySelector('#woocommerce-order-items .wc-order-totals-items table.wc-order-totals tr:last-child td.total');
		return cell ? cell.querySelector('.woocommerce-Price-amount') || cell : null;
	}

	function updateSummaryTotal() {
		var target = document.querySelector('.bp-osummary__total');
		var source = orderTotalHtmlNode();
		if (!target) return;
		var text = source ? source.textContent.replace(/\s+/g, ' ').trim() : '';
		if (target.textContent !== text) target.textContent = text;
	}

	function buildSummary() {
		var i18n = cfg.i18n || {};
		var data = cfg.summary || {};

		var box = document.createElement('div');
		box.className = 'bp-osummary';

		var label = document.createElement('div');
		label.className = 'bp-osummary__label';
		label.textContent = i18n.order_total || '';
		box.appendChild(label);

		var total = document.createElement('div');
		total.className = 'bp-osummary__total';
		box.appendChild(total);

		var payment = document.createElement('div');
		payment.className = 'bp-osummary__payment';
		var pill = document.createElement('span');
		pill.className = 'bp-osummary__pill' + (data.paid ? ' is-paid' : '');
		pill.textContent = data.paid ? (i18n.paid || '') : (i18n.not_paid || '');
		payment.appendChild(pill);
		var paymentText = [];
		if (data.payment) paymentText.push(data.payment);
		if (data.paid && data.date_paid && i18n.paid_on) paymentText.push(i18n.paid_on.replace('%s', data.date_paid));
		if (paymentText.length) {
			var method = document.createElement('span');
			method.className = 'bp-osummary__method';
			method.textContent = paymentText.join(' · ');
			payment.appendChild(method);
		}
		box.appendChild(payment);

		var customer = document.createElement('div');
		customer.className = 'bp-osummary__customer';
		if (data.customer_name) {
			var avatar = document.createElement('span');
			avatar.className = 'bp-osummary__avatar';
			avatar.setAttribute('aria-hidden', 'true');
			avatar.textContent = data.customer_name.split(/\s+/).slice(0, 2).map(function (part) { return part.charAt(0); }).join('').toUpperCase();
			customer.appendChild(avatar);

			var who = document.createElement('div');
			who.className = 'bp-osummary__who';
			var name = document.createElement('div');
			name.className = 'bp-osummary__name';
			name.textContent = data.customer_name;
			who.appendChild(name);
			if (data.customer_orders !== null && data.customer_orders !== undefined && i18n.customer_orders) {
				var orders = document.createElement('div');
				orders.className = 'bp-osummary__orders';
				orders.textContent = i18n.customer_orders.replace('%d', String(data.customer_orders));
				who.appendChild(orders);
			}
			customer.appendChild(who);

			if (data.whatsapp_url && /^https:\/\/wa\.me\/\d+/.test(data.whatsapp_url)) {
				var wa = document.createElement('a');
				wa.className = 'bp-osummary__wa';
				wa.href = data.whatsapp_url;
				wa.target = '_blank';
				wa.rel = 'noopener';
				wa.title = i18n.whatsapp || '';
				wa.setAttribute('aria-label', i18n.whatsapp || '');
				customer.appendChild(wa);
			}
		} else {
			var empty = document.createElement('div');
			empty.className = 'bp-osummary__orders';
			empty.textContent = i18n.no_customer || '';
			customer.appendChild(empty);
		}
		box.appendChild(customer);

		window.requestAnimationFrame(updateSummaryTotal);
		updateSummaryTotal.call(null);
		return box;
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

	function safely(fn) {
		try {
			fn();
		} catch (error) {
			if (window.console) window.console.error(error);
		}
	}

	function reveal() {
		// The page stays hidden (body.bp-order-booting) until the layout is in
		// place, so the untouched WooCommerce layout never flashes first.
		if (!document.body.classList.contains('bp-order-booting')) return;
		document.body.classList.remove('bp-order-booting');
		document.body.classList.add('bp-order-ready');
	}

	function init() {
		if (!document.body.classList.contains('brikpanel-modern-edit')) {
			reveal();
			return;
		}
		try {
			safely(hideOriginalTitle);
			safely(buildHeader);
			try {
				buildTabs();
			} catch (error) {
				// Without the tabs the page keeps the two-column modern layout.
				document.body.classList.remove('bp-order-tabs');
				if (window.console) window.console.error(error);
			}
			safely(addCopyButtons);
			safely(enhanceNotes);
			safely(renderItemDownloads);
			safely(watchDownloadPermissions);
		} finally {
			reveal();
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
