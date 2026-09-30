/**
 * BrikPanel — Inline Order Status Change
 *
 * Clicking the order status badge in the orders list opens a dropdown.
 * Picking a status only STAGES the change — the badge enters a pending
 * visual state and a sticky save bar appears at the bottom of the page.
 * The change is only persisted when the user clicks "Save"; "Discard"
 * (or selecting a new order's badge) reverts the badge to its original
 * state. This prevents a misclick from firing refund or cancellation
 * workflows.
 */
(function () {
	if (typeof brikpanelStatusInline === 'undefined') return;

	const statuses = brikpanelStatusInline.statuses;
	const i18n = brikpanelStatusInline.i18n || {};

	// ── Status column ───────────────────────────────────────────────────
	// WooCommerce names it order_status. A plugin can swap it for a column of
	// its own that prints the same pill (Flexible Refund: fr_order_status); the
	// server then names it here (brikpanel-orders.php). Its cells also get
	// WooCommerce's class, so every rule written for the status column (this
	// file, the compact row, its phone card) covers them. The class goes last:
	// the compact row reads a cell's key from its first column- class.
	const renamedColumn = typeof brikpanelStatusInline.column === 'string' && brikpanelStatusInline.column !== 'order_status' ? brikpanelStatusInline.column : '';
	const renamedCell = renamedColumn ? 'td.column-' + CSS.escape(renamedColumn) : ''; // i18n-ignore: CSS selector

	function markStatusColumn() {
		if (!renamedColumn) return;
		var cells = '.wp-list-table th.column-' + CSS.escape(renamedColumn) + ', .wp-list-table ' + renamedCell; // i18n-ignore: CSS selector
		document.querySelectorAll(cells).forEach(function ($cell) {
			$cell.classList.add('column-order_status');
		});
	}
	// Loaded in the footer, so the table is normally there already.
	if (document.querySelector('.wp-list-table')) {
		markStatusColumn();
	} else {
		document.addEventListener('DOMContentLoaded', markStatusColumn);
	}

	// activeContext is set while the dropdown is open OR while a pending
	// change is staged on a badge. pendingStatus !== null means staged.
	let activeContext = null;

	// ── Build dropdown ──────────────────────────────────────────────────
	// Not .brikpanel-status-dropdown: that is the single-order header menu, and
	// this script also runs on that screen.
	const $dropdown = document.createElement('div');
	$dropdown.className = 'brikpanel-status-inline-dropdown';

	Object.entries(statuses).forEach(([key, label]) => {
		const slug = key.replace('wc-', '');
		const $item = document.createElement('button');
		$item.className = 'brikpanel-status-dropdown-item';
		$item.dataset.status = slug;
		$item.dataset.label = label;
		$item.type = 'button';

		const $dot = document.createElement('span');
		$dot.className = 'brikpanel-sdi-dot status-dot-' + slug;
		$item.appendChild($dot);
		$item.appendChild(document.createTextNode(label));

		$item.addEventListener('click', function (e) {
			e.stopPropagation();
			if (!activeContext) return;
			if (slug === activeContext.currentStatus && !activeContext.pendingStatus) return;
			stageStatus(slug, label);
		});
		$dropdown.appendChild($item);
	});

	document.body.appendChild($dropdown);

	// ── Build sticky save bar (single instance, reused) ────────────────
	const $bar = document.createElement('div');
	$bar.className = 'brikpanel-status-bar';
	$bar.setAttribute('role', 'region');
	$bar.setAttribute('aria-live', 'polite');
	$bar.innerHTML =
		'<span class="brikpanel-status-bar-text"></span>' +
		'<div class="brikpanel-status-bar-actions">' +
		'<button type="button" class="brikpanel-status-bar-discard"></button>' +
		'<button type="button" class="brikpanel-status-bar-save"></button>' +
		'</div>';

	const $barText = $bar.querySelector('.brikpanel-status-bar-text');
	const $barDiscard = $bar.querySelector('.brikpanel-status-bar-discard');
	const $barSave = $bar.querySelector('.brikpanel-status-bar-save');

	$barDiscard.textContent = i18n.discard || 'Discard';
	$barSave.textContent = i18n.save || 'Save';

	$barDiscard.addEventListener('click', discardPending);
	$barSave.addEventListener('click', commitPending);

	document.body.appendChild($bar);

	// ── Keep clear of the bulk-actions bar ──────────────────────────────
	// Both bars sit at the bottom centre of the window. With rows selected
	// while a status change waited for Save, the bulk-actions bar came up
	// under this one and its buttons were hidden. While this bar is open it
	// rises to 8px above the bulk bar whenever the two would meet, and drops
	// back when they no longer do (the bulk bar is sticky, so scrolling moves it).
	var bulkBar = null, liftFrame = 0, liftWatch = null;
	var BAR_GAP = 24; // the bar's own distance from the bottom edge (CSS)

	function placeBar() {
		liftFrame = 0;
		var lift = 0;
		if (bulkBar && bulkBar.classList.contains('show')) {
			var r = bulkBar.getBoundingClientRect();
			var w = $bar.offsetWidth, h = $bar.offsetHeight;
			var bottom = window.innerHeight - BAR_GAP, left = (window.innerWidth - w) / 2;
			if (r.height > 0 && r.top < bottom && r.bottom > bottom - h && r.left < left + w && r.right > left) {
				lift = Math.max(0, Math.round(bottom - r.top + 8));
			}
		}
		$bar.style.setProperty('--bp-status-lift', lift + 'px');
	}

	function schedulePlace() {
		if (!liftFrame) liftFrame = window.requestAnimationFrame(placeBar);
	}

	function watchBulkBar(on) {
		if (on && !liftWatch) {
			bulkBar = document.querySelector('.brikpanel-bulk-actions');
			if (!bulkBar) return;
			liftWatch = {
				mo: new MutationObserver(schedulePlace),
				ro: window.ResizeObserver ? new ResizeObserver(schedulePlace) : null,
			};
			liftWatch.mo.observe(bulkBar, { attributes: true, attributeFilter: ['class'] });
			if (liftWatch.ro) liftWatch.ro.observe(bulkBar);
			document.addEventListener('scroll', schedulePlace, { capture: true, passive: true });
			window.addEventListener('resize', schedulePlace);
			placeBar();
		} else if (!on && liftWatch) {
			liftWatch.mo.disconnect();
			if (liftWatch.ro) liftWatch.ro.disconnect();
			document.removeEventListener('scroll', schedulePlace, { capture: true });
			window.removeEventListener('resize', schedulePlace);
			liftWatch = null;
		}
	}

	// ── Event delegation (capture phase to intercept before <a> navigates) ──
	document.addEventListener('click', function (e) {
		if (e.target.closest('.brikpanel-status-bar')) return;

		var $status = e.target.closest('td.column-order_status .order-status'); // i18n-ignore: CSS selector
		if (!$status && renamedCell) {
			// A row drawn after load (a plugin refreshing the list) is not marked yet.
			$status = e.target.closest(renamedCell + ' .order-status');
			if ($status) $status.closest('td').classList.add('column-order_status');
		}
		if ($status) {
			e.preventDefault();
			e.stopPropagation();
			toggleDropdown($status);
			return;
		}
		if (!e.target.closest('.brikpanel-status-inline-dropdown')) {
			closeDropdown();
		}
	}, true);

	document.addEventListener('keydown', function (e) {
		if (e.key === 'Escape') closeDropdown();
	});

	// ── Helpers ─────────────────────────────────────────────────────────
	function getOrderId($el) {
		var $row = $el.closest('tr');
		if (!$row) return null;
		return parseInt($row.id.replace('order-', '').replace('post-', ''), 10) || null;
	}

	function getCurrentStatus($el) {
		var classes = $el.className.split(/\s+/);
		for (var i = 0; i < classes.length; i++) {
			if (classes[i].indexOf('status-') === 0 && classes[i] !== 'order-status') {
				return classes[i].replace('status-', '');
			}
		}
		return null;
	}

	function toggleDropdown($status) {
		// Opening the dropdown on a DIFFERENT badge while one is pending
		// drops the pending change — only one staged edit at a time.
		if (activeContext && activeContext.pendingStatus && activeContext.el !== $status) {
			discardPending();
		}

		var wasOpenOnSame = $dropdown.classList.contains('open') && activeContext && activeContext.el === $status;
		$dropdown.classList.remove('open');
		if (wasOpenOnSame) return;

		var orderId = getOrderId($status);
		if (!orderId) return;

		// Reuse pending context if user reopened dropdown on the same badge.
		if (!activeContext || activeContext.el !== $status) {
			var currentStatus = getCurrentStatus($status);
			var $span = $status.querySelector('span');
			activeContext = {
				el: $status,
				orderId: orderId,
				currentStatus: currentStatus,
				pendingStatus: null,
				originalStatus: currentStatus,
				originalText: $span ? $span.textContent : $status.textContent.trim(),
				originalDataTip: $status.getAttribute('data-tip'),
			};
		}

		// Highlight current (or pending, if any)
		var highlight = activeContext.pendingStatus || activeContext.currentStatus;
		var items = $dropdown.querySelectorAll('.brikpanel-status-dropdown-item');
		for (var i = 0; i < items.length; i++) {
			items[i].classList.toggle('current', items[i].dataset.status === highlight);
		}

		paintDots();

		// Position
		var rect = $status.getBoundingClientRect();
		$dropdown.classList.add('open');

		var dh = $dropdown.offsetHeight;
		var spaceBelow = window.innerHeight - rect.bottom;

		if (spaceBelow < dh && rect.top > dh) {
			$dropdown.style.top = (rect.top + window.scrollY - dh - 4) + 'px';
		} else {
			$dropdown.style.top = (rect.bottom + window.scrollY + 4) + 'px';
		}
		// Starts under the pill (under its right edge in RTL) and stays inside
		// the window. The page itself can scroll sideways when other plugins'
		// columns make the table wider than the screen.
		var dw = $dropdown.offsetWidth;
		var rtl = getComputedStyle(document.body).direction === 'rtl';
		var x = rtl ? rect.right - dw : rect.left;
		x = Math.max(8, Math.min(x, document.documentElement.clientWidth - dw - 8));
		$dropdown.style.left = (x + window.scrollX) + 'px';
	}

	// A status BrikPanel has no colour for (another plugin's) takes the colour
	// its pill has in the list, so the menu matches the table. BrikPanel's own
	// dot colours still win: the fallback sits in a zero-specificity rule
	// (brikpanel-order-status-inline.css). Read once, on the first open.
	var dotsPainted = false;
	function paintDots() {
		if (dotsPainted) return;
		dotsPainted = true;
		var items = $dropdown.querySelectorAll('.brikpanel-status-dropdown-item');
		for (var i = 0; i < items.length; i++) {
			var $pill = document.querySelector('.wp-list-table .order-status.status-' + CSS.escape(items[i].dataset.status) + ':not(.brikpanel-status-pending)'); // i18n-ignore: CSS selector
			var $dot = items[i].querySelector('.brikpanel-sdi-dot');
			if ($pill && $dot) {
				$dot.style.setProperty('--bp-sdi-pill', getComputedStyle($pill).color);
			}
		}
	}

	function closeDropdown() {
		$dropdown.classList.remove('open');
		// Keep activeContext alive while a pending change is staged so the
		// save bar's Discard/Save can still locate the badge.
		if (activeContext && !activeContext.pendingStatus) {
			activeContext = null;
		}
	}

	function stageStatus(slug, label) {
		if (!activeContext) return;
		var el = activeContext.el;

		// Strip any existing status-* class (except the bare "order-status").
		var classes = el.className.split(/\s+/);
		for (var i = 0; i < classes.length; i++) {
			if (classes[i].indexOf('status-') === 0 && classes[i] !== 'order-status') {
				el.classList.remove(classes[i]);
			}
		}
		el.classList.add('status-' + slug);
		el.classList.add('brikpanel-status-pending');

		var $span = el.querySelector('span');
		if ($span) {
			$span.textContent = label;
		}
		if (el.hasAttribute('data-tip')) {
			el.setAttribute('data-tip', label);
		}

		activeContext.pendingStatus = slug;
		activeContext.pendingLabel = label;
		// Track current status so re-selecting it acts as a noop on the badge.
		activeContext.currentStatus = slug;

		closeDropdown();
		showBar();
	}

	function showBar() {
		if (!activeContext) return;
		var orig = labelForStatus(activeContext.originalStatus) || activeContext.originalText;
		var tpl = i18n.pending_text || 'Order #%1$s: %2$s → %3$s';
		// The bar names the order the way the Order column does. With a
		// sequential-order-number plugin that is not the row id, so the server
		// hands over a map of the listed orders. Read on use, not at load: the
		// map is printed after this file runs. Falls back to the id, which is
		// the right answer whenever no plugin renumbers orders.
		var numbers = brikpanelStatusInline.numbers || {};
		$barText.textContent = tpl
			.replace('%1$s', numbers[activeContext.orderId] || activeContext.orderId)
			.replace('%2$s', orig)
			.replace('%3$s', activeContext.pendingLabel);

		$bar.classList.add('open');
		$barSave.disabled = false;
		$barSave.textContent = i18n.save || 'Save';
		watchBulkBar(true);
	}

	function hideBar() {
		$bar.classList.remove('open');
		watchBulkBar(false);
	}

	function labelForStatus(slug) {
		if (!slug) return '';
		var key = 'wc-' + slug;
		if (statuses[key]) return statuses[key];
		if (statuses[slug]) return statuses[slug];
		return slug;
	}

	function discardPending() {
		if (!activeContext || !activeContext.pendingStatus) {
			hideBar();
			return;
		}
		revertBadge(activeContext);
		hideBar();
		activeContext = null;
	}

	function revertBadge(ctx) {
		var el = ctx.el;
		// Strip any current status-* and restore original.
		var classes = el.className.split(/\s+/);
		for (var i = 0; i < classes.length; i++) {
			if (classes[i].indexOf('status-') === 0 && classes[i] !== 'order-status') {
				el.classList.remove(classes[i]);
			}
		}
		el.classList.remove('brikpanel-status-pending');
		if (ctx.originalStatus) {
			el.classList.add('status-' + ctx.originalStatus);
		}
		var $span = el.querySelector('span');
		if ($span) {
			$span.textContent = ctx.originalText;
		}
		if (ctx.originalDataTip !== null && ctx.originalDataTip !== undefined) {
			el.setAttribute('data-tip', ctx.originalDataTip);
		}
	}

	function commitPending() {
		if (!activeContext || !activeContext.pendingStatus) return;

		var ctx = activeContext;
		var el = ctx.el;
		var orderId = ctx.orderId;
		var newStatus = ctx.pendingStatus;

		$barSave.disabled = true;
		$barSave.textContent = i18n.saving || 'Saving…';
		el.style.opacity = '0.5';
		el.style.pointerEvents = 'none';

		var body = new FormData();
		body.append('action', 'brikpanel_change_order_status');
		body.append('_ajax_nonce', brikpanelStatusInline.nonce);
		body.append('order_id', orderId);
		body.append('new_status', newStatus);

		fetch(brikpanelStatusInline.ajax_url, { method: 'POST', body: body })
			.then(function (r) { return r.json(); })
			.then(function (res) {
				el.style.opacity = '';
				el.style.pointerEvents = '';

				if (res && res.success) {
					el.classList.remove('brikpanel-status-pending');
					var $span = el.querySelector('span');
					if ($span && res.data && res.data.label) {
						$span.textContent = res.data.label;
					}
					if (el.hasAttribute('data-tip') && res.data && res.data.label) {
						el.setAttribute('data-tip', res.data.label);
					}
					// The new status can have its own WhatsApp message, or the note of an
					// earlier press is gone: the order's WhatsApp buttons take the new draft
					// (brikpanel-order-whatsapp.js).
					if (res.data && typeof res.data.whatsapp === 'string') {
						document.dispatchEvent(new CustomEvent('brikpanel:order-whatsapp', {
							detail: { orderId: orderId, url: res.data.whatsapp, followup: !!res.data.whatsapp_followup },
						}));
					}
					hideBar();
					activeContext = null;
				} else {
					// Server rejected — revert and report.
					revertBadge(ctx);
					$barSave.disabled = false;
					$barSave.textContent = i18n.save || 'Save';
					window.alert((res && res.data && res.data.message) || i18n.error || 'Error');
				}
			})
			.catch(function () {
				el.style.opacity = '';
				el.style.pointerEvents = '';
				revertBadge(ctx);
				$barSave.disabled = false;
				$barSave.textContent = i18n.save || 'Save';
				window.alert(i18n.error || 'Error');
			});
	}

	// ── Status changed by another script ────────────────────────────────
	// The tracking window (brikpanel-order-tracking.js) lets Trakoo move an
	// order to a new status. The badge follows it, and a change staged on that
	// same badge is dropped: saving or discarding it later would undo the
	// status the order has now.
	document.addEventListener('brikpanel:order-status-changed', function (e) {
		var detail = e.detail || {};
		var orderId = parseInt(detail.orderId, 10);
		var slug = typeof detail.slug === 'string' && /^[a-z0-9_-]+$/.test(detail.slug) ? detail.slug : '';
		if (!orderId || !slug) return;
		var $row = document.getElementById('order-' + orderId) || document.getElementById('post-' + orderId);
		if (!$row) return;
		var el = $row.querySelector('td.column-order_status .order-status'); // i18n-ignore: CSS selector
		if (!el && renamedCell) el = $row.querySelector(renamedCell + ' .order-status');
		if (!el) return;

		if (activeContext && activeContext.el === el) {
			$dropdown.classList.remove('open');
			hideBar();
			activeContext = null;
		}

		var label = typeof detail.label === 'string' && detail.label ? detail.label : labelForStatus(slug);
		var classes = el.className.split(/\s+/);
		for (var i = 0; i < classes.length; i++) {
			if (classes[i].indexOf('status-') === 0 && classes[i] !== 'order-status') {
				el.classList.remove(classes[i]);
			}
		}
		el.classList.remove('brikpanel-status-pending');
		el.classList.add('status-' + slug);
		var $span = el.querySelector('span');
		if ($span) {
			$span.textContent = label;
		}
		if (el.hasAttribute('data-tip')) {
			el.setAttribute('data-tip', label);
		}
	});
})();
