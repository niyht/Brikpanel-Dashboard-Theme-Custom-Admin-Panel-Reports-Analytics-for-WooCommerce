/**
 * BrikPanel - the "More actions" menu that secondary buttons fold into.
 *
 * Field test C2/C6/C8: when a header row ran out of room its buttons lost their
 * labels (Products: three look-alike icons), wrapped into two-line buttons
 * (Customer Analytics) or stacked at uneven widths (Dashboard). CLAUDE.md
 * ("Başlık satırı kuralı") says secondary actions give way first, into a "..."
 * menu, labels intact. This is the product editor's pattern made shared.
 *
 * Markup (the screen prints it; the real buttons live inside the menu):
 *   <div class="brikpanel-overflow">
 *     <button type="button" class="brikpanel-overflow__trigger" aria-expanded="false"
 *             aria-controls="x-menu" aria-label="More actions" title="More actions">…</button>
 *     <div class="brikpanel-overflow__menu" id="x-menu"> …buttons and links… </div>
 *   </div>
 *
 * While the row has room the wrapper and the menu are `display: contents` and
 * the trigger is hidden: the buttons sit in the row as before, with their own
 * ids and click handlers. The menu folds when an ancestor has `is-fold` (a
 * brikpanelFitRow level), or at phone widths for `brikpanel-overflow--phone`.
 *
 * This script only opens and closes: one delegated set of listeners serves
 * every menu, including ones a script builds later. A click on an item still
 * reaches the item's own handlers; the menu closes after it.
 */
(function (window, document) {
	'use strict';

	if (window.brikpanelOverflow) {
		return;
	}

	var OPEN = 'is-open';

	function wrapOf(node) {
		return node && node.closest ? node.closest('.brikpanel-overflow') : null;
	}

	function triggerOf(wrap) {
		return wrap ? wrap.querySelector('.brikpanel-overflow__trigger') : null;
	}

	function menuOf(wrap) {
		return wrap ? wrap.querySelector('.brikpanel-overflow__menu') : null;
	}

	// A folded menu shows its trigger; an unfolded one is `display: contents`.
	function folded(wrap) {
		var t = triggerOf(wrap);
		return !!t && window.getComputedStyle(t).display !== 'none';
	}

	function close(wrap, returnFocus) {
		if (!wrap || !wrap.classList.contains(OPEN)) {
			return;
		}
		wrap.classList.remove(OPEN, 'is-up', 'is-fixed');
		var t = triggerOf(wrap);
		if (t) {
			t.setAttribute('aria-expanded', 'false');
			if (returnFocus) {
				t.focus();
			}
		}
	}

	function closeAll(except) {
		var open = document.querySelectorAll('.brikpanel-overflow.' + OPEN);
		for (var i = 0; i < open.length; i++) {
			if (open[i] !== except) {
				close(open[i], false);
			}
		}
	}

	// Keep the open menu inside the window: it hangs from the trigger's end
	// edge, which on a narrow screen can push its other edge out.
	function clamp(wrap) {
		var menu = menuOf(wrap);
		if (!menu) {
			return;
		}
		menu.style.setProperty('--bp-overflow-shift', '0px');
		var r = menu.getBoundingClientRect();
		var vw = document.documentElement.clientWidth;
		var shift = 0;
		if (r.left < 8) {
			shift = 8 - r.left;
		} else if (r.right > vw - 8) {
			shift = vw - 8 - r.right;
		}
		if (shift) {
			menu.style.setProperty('--bp-overflow-shift', Math.round(shift) + 'px');
		}
	}

	// The part of the window the menu can be seen in: boxes that clip their
	// content (a card with rounded corners, a table's scroll box) cut it off.
	function visibleBand(wrap) {
		var top = 0;
		var bottom = window.innerHeight;
		for (var n = wrap.parentElement; n && n !== document.body && n !== document.documentElement; n = n.parentElement) {
			if (window.getComputedStyle(n).overflowY !== 'visible') {
				var r = n.getBoundingClientRect();
				top = Math.max(top, r.top);
				bottom = Math.min(bottom, r.bottom);
			}
		}
		return { top: top, bottom: bottom };
	}

	// Below the trigger when it fits there, else above it. A menu that fits
	// neither way inside its box (the last row of a short list in a card)
	// leaves the box: it is placed against the window instead, and closes on
	// scroll. Without this the last rows' menus were cut off by the card.
	function place(wrap) {
		var menu = menuOf(wrap);
		var t = triggerOf(wrap);
		if (!menu || !t) {
			return;
		}
		wrap.classList.remove('is-up', 'is-fixed');
		var m = menu.getBoundingClientRect();
		var band = visibleBand(wrap);
		if (m.bottom <= band.bottom + 1) {
			return;
		}
		var tr = t.getBoundingClientRect();
		if (tr.top - band.top >= m.height + 8) {
			wrap.classList.add('is-up');
			return;
		}
		var vw = document.documentElement.clientWidth;
		var vh = window.innerHeight;
		var below = vh - tr.bottom;
		var top = (below >= m.height + 8 || below >= tr.top) ? tr.bottom + 4 : Math.max(8, tr.top - 4 - m.height);
		var rtl = window.getComputedStyle(wrap).direction === 'rtl';
		var left = rtl ? tr.left : tr.right - m.width;
		left = Math.max(8, Math.min(left, vw - 8 - m.width));
		menu.style.setProperty('--bp-overflow-top', Math.round(top) + 'px');
		menu.style.setProperty('--bp-overflow-left', Math.round(left) + 'px');
		wrap.classList.add('is-fixed');
	}

	function open(wrap) {
		closeAll(wrap);
		wrap.classList.add(OPEN);
		var t = triggerOf(wrap);
		if (t) {
			t.setAttribute('aria-expanded', 'true');
		}
		clamp(wrap);
		place(wrap);
	}

	// Capture: the trigger is ours alone, so nothing else (a row that opens on
	// click, say) should see a tap on it.
	document.addEventListener('click', function (e) {
		var t = e.target && e.target.closest ? e.target.closest('.brikpanel-overflow__trigger') : null;
		if (t) {
			var wrap = wrapOf(t);
			if (wrap) {
				e.preventDefault();
				e.stopPropagation();
				if (wrap.classList.contains(OPEN)) {
					close(wrap, false);
				} else {
					open(wrap);
				}
			}
			return;
		}
		var inside = wrapOf(e.target);
		if (!inside || !inside.classList.contains(OPEN)) {
			closeAll(null);
		}
	}, true);

	// Bubble: an item was chosen and its own handlers ran; fold the menu away.
	document.addEventListener('click', function (e) {
		var item = e.target && e.target.closest ? e.target.closest('.brikpanel-overflow__menu a, .brikpanel-overflow__menu button') : null;
		var wrap = wrapOf(item);
		if (wrap && folded(wrap)) {
			close(wrap, false);
		}
	});

	document.addEventListener('keydown', function (e) {
		if (e.key !== 'Escape') {
			return;
		}
		var open = document.querySelector('.brikpanel-overflow.' + OPEN);
		if (open) {
			close(open, true);
		}
	});

	// Tabbing out of an open menu closes it, so it never stays open behind focus.
	document.addEventListener('focusout', function (e) {
		var wrap = wrapOf(e.target);
		if (!wrap || !wrap.classList.contains(OPEN)) {
			return;
		}
		var to = e.relatedTarget;
		if (to && !wrap.contains(to)) {
			close(wrap, false);
		}
	});

	// A header row that gets its room back unfolds; an open menu goes with it.
	// Capture: brikpanelFitRow dispatches the event on the row without bubbling.
	document.addEventListener('brikpanel:fit-row', function (e) {
		var row = e.target;
		if (!row || !row.querySelectorAll) {
			return;
		}
		var list = row.querySelectorAll('.brikpanel-overflow.' + OPEN);
		for (var i = 0; i < list.length; i++) {
			if (!folded(list[i])) {
				close(list[i], false);
			}
		}
	}, true);

	// A menu placed against the window would drift from its row on scroll.
	document.addEventListener('scroll', function () {
		var list = document.querySelectorAll('.brikpanel-overflow.is-fixed.' + OPEN);
		for (var i = 0; i < list.length; i++) {
			close(list[i], false);
		}
	}, true);

	window.addEventListener('resize', function () {
		var list = document.querySelectorAll('.brikpanel-overflow.' + OPEN);
		for (var i = 0; i < list.length; i++) {
			if (folded(list[i])) {
				clamp(list[i]);
			} else {
				close(list[i], false);
			}
		}
	});

	window.brikpanelOverflow = { close: closeAll };
})(window, document);
