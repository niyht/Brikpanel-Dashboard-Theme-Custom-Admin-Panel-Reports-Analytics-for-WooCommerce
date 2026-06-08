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

	// activeContext is set while the dropdown is open OR while a pending
	// change is staged on a badge. pendingStatus !== null means staged.
	let activeContext = null;

	// ── Build dropdown ──────────────────────────────────────────────────
	const $dropdown = document.createElement('div');
	$dropdown.className = 'brikpanel-status-dropdown';

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

	// ── Event delegation (capture phase to intercept before <a> navigates) ──
	document.addEventListener('click', function (e) {
		if (e.target.closest('.brikpanel-status-bar')) return;

		var $status = e.target.closest('td.column-order_status .order-status'); // i18n-ignore: CSS selector
		if ($status) {
			e.preventDefault();
			e.stopPropagation();
			toggleDropdown($status);
			return;
		}
		if (!e.target.closest('.brikpanel-status-dropdown')) {
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
		$dropdown.style.left = rect.left + 'px';
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
		$barText.textContent = tpl
			.replace('%1$s', activeContext.orderId)
			.replace('%2$s', orig)
			.replace('%3$s', activeContext.pendingLabel);

		$bar.classList.add('open');
		$barSave.disabled = false;
		$barSave.textContent = i18n.save || 'Save';
	}

	function hideBar() {
		$bar.classList.remove('open');
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
})();
