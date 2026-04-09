/**
 * BrikPanel — Inline Order Status Change
 *
 * Allows clicking on the order status badge in the orders list table
 * to change the status via a dropdown without leaving the page.
 */
(function () {
	if (typeof brikpanelStatusInline === 'undefined') return;

	const statuses = brikpanelStatusInline.statuses;
	let activeContext = null;

	// ── Build dropdown ──────────────────────────────────────────────────
	const $dropdown = document.createElement('div');
	$dropdown.className = 'brikpanel-status-dropdown';

	Object.entries(statuses).forEach(([key, label]) => {
		const slug = key.replace('wc-', '');
		const $item = document.createElement('button');
		$item.className = 'brikpanel-status-dropdown-item';
		$item.dataset.status = slug;
		$item.type = 'button';

		const $dot = document.createElement('span');
		$dot.className = 'brikpanel-sdi-dot status-dot-' + slug;
		$item.appendChild($dot);
		$item.appendChild(document.createTextNode(label));

		$item.addEventListener('click', function (e) {
			e.stopPropagation();
			if (activeContext && activeContext.currentStatus !== slug) {
				changeStatus(slug);
			}
		});
		$dropdown.appendChild($item);
	});

	document.body.appendChild($dropdown);

	// ── Event delegation (capture phase to intercept before <a> navigates) ──
	document.addEventListener('click', function (e) {
		var $status = e.target.closest('td.column-order_status .order-status');
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
		var wasOpen = $dropdown.classList.contains('open') && activeContext && activeContext.el === $status;
		closeDropdown();
		if (wasOpen) return;

		var orderId = getOrderId($status);
		var currentStatus = getCurrentStatus($status);
		if (!orderId) return;

		activeContext = { el: $status, orderId: orderId, currentStatus: currentStatus };

		// Highlight current
		var items = $dropdown.querySelectorAll('.brikpanel-status-dropdown-item');
		for (var i = 0; i < items.length; i++) {
			items[i].classList.toggle('current', items[i].dataset.status === currentStatus);
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
		activeContext = null;
	}

	function changeStatus(newStatus) {
		if (!activeContext) return;
		var el = activeContext.el;
		var orderId = activeContext.orderId;
		closeDropdown();

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
				if (res.success) {
					// Update class
					var classes = el.className.split(/\s+/);
					for (var i = 0; i < classes.length; i++) {
						if (classes[i].indexOf('status-') === 0 && classes[i] !== 'order-status') {
							el.classList.remove(classes[i]);
						}
					}
					el.classList.add('status-' + newStatus);

					// Update text
					var $span = el.querySelector('span');
					if ($span) {
						$span.textContent = res.data.label;
					}

					// Update data-tip (WooCommerce tooltip)
					if (el.hasAttribute('data-tip')) {
						el.setAttribute('data-tip', res.data.label);
					}
				}
				el.style.opacity = '';
				el.style.pointerEvents = '';
			})
			.catch(function () {
				el.style.opacity = '';
				el.style.pointerEvents = '';
			});
	}
})();
