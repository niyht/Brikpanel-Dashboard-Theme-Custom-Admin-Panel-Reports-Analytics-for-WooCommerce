/**
 * BrikPanel - tooltips placed by measurement, never past the screen edge.
 *
 * Field report 2026-09-26: on a phone the dashboard's "?" beside Copy
 * everything opened a bubble whose left third was off the screen. Each
 * tooltip guessed where its icon sat (anchored to the icon's right edge at
 * 900px and below, centred, always rightward); the fit-row header levels,
 * the two-column card grid and RTL moved the icons and the guesses broke. A
 * card's `overflow: hidden` cut the other bubbles down to a sliver on phones,
 * and the product editor's "?" (a CSS hover bubble inside a field label)
 * never opened on a touch screen: the tap focused the field instead.
 *
 * Markup (the screen prints it; the tip is a direct child of its trigger):
 *   <span class="x" data-bp-tip="start" tabindex="0" role="button"
 *         aria-label="..." aria-describedby="x-body">
 *     ...icon...
 *     <span class="x-tip brikpanel-tip" role="tooltip">...<span id="x-body">...</span></span>
 *   </span>
 * `data-bp-tip` holds optional words: `start` or `end` hangs the bubble from
 * that edge of the trigger (default: centred on it); `top` opens it above
 * when it fits there (default: below). A bubble the pointer has to use (a
 * scrolling list with links) also gets `brikpanel-tip--interactive`. The
 * screen's own class gives the look and `--bp-tip-w`; brikpanel-tip.css owns
 * the rest.
 *
 * Closed, a bubble is `display: none`: no box, so it never adds scrollable
 * overflow and never widens a phone's layout viewport. Open, it is
 * `position: fixed` (it clears cards that clip their content) and placed in
 * one pass: one write of its size limit, one read of the trigger and the
 * bubble, one write of --bp-tip-x / --bp-tip-y / --bp-tip-arrow. It stays in
 * the part of the window that is on screen; the arrow points at the trigger
 * wherever the bubble had to move. While open it follows the page on scroll
 * (one read of the trigger per frame) and closes once the trigger is gone
 * from view.
 *
 * Opens on mouse hover, keyboard focus, and a tap or click, which pins it
 * (a finger has no hover). The next tap on the trigger or the bubble, a tap
 * elsewhere, Escape, or focus leaving closes it. One delegated set of
 * listeners serves every tip, including ones a script adds later.
 *
 * Also: brikpanelTip.nudge(el, within) moves a popover that stays
 * `position: absolute` under its own button back inside the screen, and
 * WooCommerce's help-tip bubble (#tiptip_holder) is corrected after it has
 * placed itself.
 */
(function (window, document) {
	'use strict';

	if (window.brikpanelTip) {
		return;
	}

	var OPEN = 'is-bp-tip-open';
	var EDGE = 12;   // px kept free at the screen edge
	var GAP = 8;     // px between trigger and bubble; the arrow sits in it
	var ARROW = 12;  // the arrow stays this far from a bubble corner
	var GRACE = 150; // ms for the pointer to cross the gap into the bubble

	var state = null; // the one open bubble
	var band = null;  // cached visible area, dropped on resize and zoom
	var frame = 0;
	var closeTimer = 0;
	var lastPointerAt = 0;

	function tipOf(trigger) {
		for (var c = trigger.firstElementChild; c; c = c.nextElementSibling) {
			if (c.classList.contains('brikpanel-tip')) {
				return c;
			}
		}
		return null;
	}

	function triggerOf(node) {
		var t = node && node.closest ? node.closest('[data-bp-tip]') : null;
		return t && tipOf(t) ? t : null;
	}

	function has(trigger, word) {
		return (' ' + (trigger.getAttribute('data-bp-tip') || '') + ' ').indexOf(' ' + word + ' ') > -1;
	}

	// The part of the window a bubble may use: the layout viewport less its
	// scrollbar, cut to what is on screen. Chrome on a phone can keep a layout
	// viewport wider than the screen (something overflowed during load), so
	// the visual viewport decides. Cached: reading it can cost a layout.
	function visible() {
		if (!band) {
			var de = document.documentElement;
			var vv = window.visualViewport;
			band = { l: 0, t: 0, r: de.clientWidth || window.innerWidth, b: de.clientHeight || window.innerHeight };
			if (vv) {
				band.l = Math.max(band.l, vv.offsetLeft);
				band.t = Math.max(band.t, vv.offsetTop);
				band.r = Math.min(band.r, vv.offsetLeft + vv.width);
				band.b = Math.min(band.b, vv.offsetTop + vv.height);
			}
		}
		return band;
	}

	// Bottom of the bars fixed at the top (admin bar, BrikPanel top bar): a
	// bubble opens above its trigger only when it clears them.
	function barsBottom() {
		var bottom = 0;
		var bars = document.querySelectorAll('#wpadminbar, .brikpanel-topbar');
		for (var i = 0; i < bars.length; i++) {
			var r = bars[i].getBoundingClientRect();
			if (r.height && r.top <= 0 && r.bottom > bottom) {
				var pos = window.getComputedStyle(bars[i]).position;
				if (pos === 'fixed' || pos === 'sticky') {
					bottom = r.bottom;
				}
			}
		}
		return bottom;
	}

	// Write: the size limit, and the bubble parked at (0, 0) so the read
	// below also finds where a fixed box's corner really is.
	function prime(s) {
		var v = visible();
		var st = s.tip.style;
		st.setProperty('--bp-tip-room', Math.max(0, Math.floor(v.r - v.l - 2 * EDGE)) + 'px');
		st.setProperty('--bp-tip-x', '0px');
		st.setProperty('--bp-tip-y', '0px');
		st.removeProperty('--bp-tip-h');
		s.tip.removeAttribute('data-bp-tip-capped');
		s.key = '';
	}

	// Read: the one measure of an open.
	function measure(s) {
		var r = s.tip.getBoundingClientRect();
		s.w = r.width;
		s.h = r.height;
		s.ox = r.left; // 0 unless a transformed ancestor holds the fixed box
		s.oy = r.top;
		s.a = s.trigger.getBoundingClientRect();
		s.bars = barsBottom();
		s.rtl = window.getComputedStyle(s.trigger).direction === 'rtl';
	}

	// Write: where the bubble goes, from what measure() read (or a fresh
	// trigger box when only the page scrolled).
	function place(s) {
		var v = visible();
		var a = s.a;
		var mid = a.left + a.width / 2;
		var x = mid - s.w / 2;
		if (has(s.trigger, 'start')) {
			x = s.rtl ? a.right + GAP - s.w : a.left - GAP;
		} else if (has(s.trigger, 'end')) {
			x = s.rtl ? a.left - GAP : a.right + GAP - s.w;
		}
		x = Math.max(v.l + EDGE, Math.min(x, v.r - EDGE - s.w));

		var below = v.b - EDGE - a.bottom - GAP;
		var above = a.top - GAP - Math.max(v.t, s.bars) - EDGE;
		var up = has(s.trigger, 'top');
		var room = up ? above : below;
		var other = up ? below : above;
		if (s.h > room && (s.h <= other || other > room)) {
			up = !up;
			room = other;
		}
		var h = s.h > room ? Math.max(64, Math.floor(room)) : s.h;
		var y = up ? a.top - GAP - h : a.bottom + GAP;

		var px = Math.round(x - s.ox);
		var py = Math.round(y - s.oy);
		var arrow = Math.round(Math.max(ARROW, Math.min(mid - x, s.w - ARROW)));
		var key = px + ',' + py + ',' + arrow + ',' + h + ',' + up;
		if (key === s.key) {
			return;
		}
		s.key = key;
		var st = s.tip.style;
		st.setProperty('--bp-tip-x', px + 'px');
		st.setProperty('--bp-tip-y', py + 'px');
		st.setProperty('--bp-tip-arrow', arrow + 'px');
		if (h < s.h) {
			st.setProperty('--bp-tip-h', h + 'px');
			s.tip.setAttribute('data-bp-tip-capped', '');
		} else {
			st.removeProperty('--bp-tip-h');
			s.tip.removeAttribute('data-bp-tip-capped');
		}
		s.tip.setAttribute('data-bp-tip-side', up ? 'top' : 'bottom');
	}

	// The trigger is still on screen and not under something (a sticky header).
	function inView(s) {
		var v = visible();
		var x = s.a.left + s.a.width / 2;
		var y = s.a.top + s.a.height / 2;
		if (x < v.l || x > v.r || y < Math.max(v.t, s.bars) || y > v.b) {
			return false;
		}
		var hit = document.elementFromPoint(x, y);
		return !!hit && (hit === s.trigger || s.trigger.contains(hit));
	}

	function current() {
		if (state && !state.trigger.isConnected) {
			state = null; // the screen re-rendered it; the bubble went with it
		}
		return state;
	}

	// Only a button says whether it is open; a note (the "!" flags) does not.
	function expanded(trigger, value) {
		if (trigger.getAttribute('role') === 'button') {
			trigger.setAttribute('aria-expanded', value);
		}
	}

	function close() {
		clearTimeout(closeTimer);
		if (state) {
			state.trigger.classList.remove(OPEN);
			expanded(state.trigger, 'false');
			state = null;
		}
	}

	function open(trigger, via) {
		close();
		var tip = tipOf(trigger);
		if (!tip) {
			return;
		}
		var s = { trigger: trigger, tip: tip, via: via, key: '', again: false };
		s.interactive = tip.classList.contains('brikpanel-tip--interactive');
		prime(s);
		trigger.classList.add(OPEN);
		expanded(trigger, 'true');
		measure(s);
		place(s);
		state = s;
	}

	// Tap or click: the first pins the bubble (a hover or focus one included),
	// the next closes it.
	function toggle(trigger) {
		var s = current();
		if (s && s.trigger === trigger) {
			if (s.via === 'click') {
				close();
			} else {
				s.via = 'click';
			}
		} else {
			open(trigger, 'click');
		}
	}

	// Follow the trigger on scroll (one read per frame); measure again after a
	// resize, a zoom or a header level change, since the room changed.
	function schedule(again) {
		var s = current();
		if (!s) {
			return;
		}
		s.again = s.again || again;
		if (frame) {
			return;
		}
		frame = window.requestAnimationFrame(function () {
			frame = 0;
			var t = current();
			if (!t) {
				return;
			}
			if (t.again) {
				t.again = false;
				prime(t);
				measure(t);
			} else if (Math.abs(t.ox) > 0.5 || Math.abs(t.oy) > 0.5) {
				close(); // held by a transformed box: it would drift from its trigger
				return;
			} else {
				t.a = t.trigger.getBoundingClientRect();
			}
			if (inView(t)) {
				place(t);
			} else {
				close();
			}
		});
	}

	// Moves a box by whole pixels with `translate`, which leaves its own
	// transform (an arrow's rotation) alone.
	function shift(el, dx, dy) {
		el.style.translate = Math.round(dx) + 'px ' + Math.round(dy) + 'px';
	}

	// A popover that stays `position: absolute` under its own button keeps
	// its CSS anchor; once shown, this moves it back inside the screen and
	// inside `within` (the box that clips it, or the screen's column so it
	// does not slide under the admin menu). One read, one write; the shift is
	// a `translate`, cleared on the next call.
	function nudge(el, within) {
		if (!el) {
			return 0;
		}
		el.style.translate = '';
		var r = el.getBoundingClientRect();
		if (!r.width) {
			return 0;
		}
		var v = visible();
		var lo = v.l + 8;
		var hi = v.r - 8;
		if (within) {
			var w = within.getBoundingClientRect();
			lo = Math.max(lo, w.left);
			hi = Math.min(hi, w.right);
		}
		var dx = 0;
		if (r.width > hi - lo) {
			dx = window.getComputedStyle(el).direction === 'rtl' ? hi - r.right : lo - r.left;
		} else if (r.left < lo) {
			dx = lo - r.left;
		} else if (r.right > hi) {
			dx = hi - r.right;
		}
		if (dx) {
			shift(el, dx, 0);
		}
		return dx;
	}

	// WooCommerce's help-tip bubble (jQuery tipTip, one #tiptip_holder per
	// page). tipTip measures, but its side flip tests the box that starts at
	// the icon, not the centred one, and it never clamps: on a phone a bubble
	// beside a mid-screen "?" starts past the left edge. tipTip sets the
	// holder's class last and fades it in after a delay; this runs in between
	// (a MutationObserver callback). A side bubble that does not fit moves
	// under (or over) its icon; any other is shifted back inside; the arrow
	// keeps pointing at the icon.
	var tt = { anchor: null, holder: null, watch: null };

	function fixTipTip() {
		var holder = tt.holder;
		var cls = holder.className || '';
		var arrow = document.getElementById('tiptip_arrow');
		holder.style.translate = '';
		if (arrow) {
			arrow.style.translate = '';
		}
		if (!/^tip_/.test(cls) || !tt.anchor || !tt.anchor.isConnected) {
			return;
		}
		band = null;
		var shown = holder.style.display;
		holder.style.visibility = 'hidden';
		holder.style.display = 'block';
		var r = holder.getBoundingClientRect();
		var a = tt.anchor.getBoundingClientRect();
		holder.style.display = shown;
		holder.style.visibility = '';
		var v = visible();
		var lo = v.l + 8;
		var hi = v.r - 8;
		if (r.left >= lo && r.right <= hi) {
			return;
		}
		var mid = a.left + a.width / 2;
		if (/^tip_(left|right)/.test(cls)) {
			// WooCommerce pads only the exact side classes by 5px; the bottom
			// and top classes pad the arrow's side instead.
			var w = r.width - (/^tip_(left|right)$/.test(cls) ? 5 : 0);
			var h = r.height + 5;
			var down = a.bottom + 3 + h <= v.b - 8 || a.top - 3 - h < v.t;
			var x = Math.max(lo, Math.min(mid - w / 2, hi - w));
			var y = down ? a.bottom + 3 : a.top - 3 - h;
			holder.className = down ? 'tip_bottom' : 'tip_top';
			shift(holder, x - r.left, y - r.top);
			if (arrow) {
				arrow.style.marginLeft = Math.round(mid - x - 6) + 'px';
				arrow.style.marginTop = (down ? -12 : Math.round(h - 5)) + 'px';
			}
		} else {
			var dx = r.left < lo ? lo - r.left : hi - r.right;
			shift(holder, dx, 0);
			if (arrow) {
				shift(arrow, -dx, 0);
			}
		}
		tt.watch.takeRecords(); // our own class change is not tipTip placing it again
	}

	// Capture: runs before the jQuery mouseenter handler that opens tipTip.
	document.addEventListener('mouseover', function (e) {
		var el = e.target && e.target.closest ? e.target.closest('.woocommerce-help-tip, .tips, .help_tip') : null;
		if (!el || typeof window.MutationObserver !== 'function') {
			return;
		}
		tt.anchor = el;
		var holder = document.getElementById('tiptip_holder');
		if (!holder || holder === tt.holder) {
			return;
		}
		if (tt.watch) {
			tt.watch.disconnect();
		}
		tt.holder = holder;
		tt.watch = new window.MutationObserver(fixTipTip);
		tt.watch.observe(holder, { attributes: true, attributeFilter: ['class'] });
	}, true);

	function isMouse(e) {
		return e.pointerType === 'mouse' || e.pointerType === 'pen';
	}

	function byKeyboard(t) {
		try {
			return t.matches(':focus-visible');
		} catch (err) {
			return Date.now() - lastPointerAt > 800;
		}
	}

	// A press anywhere else closes the open bubble.
	document.addEventListener('pointerdown', function (e) {
		lastPointerAt = Date.now();
		var s = current();
		if (s && !s.trigger.contains(e.target)) {
			close();
		}
	}, true);

	document.addEventListener('pointerover', function (e) {
		if (!isMouse(e)) {
			return;
		}
		var t = triggerOf(e.target);
		if (!t) {
			return;
		}
		clearTimeout(closeTimer);
		var s = current();
		if (!s || s.trigger !== t) {
			open(t, 'hover');
		}
	});

	// The bubble is inside its trigger, so moving onto it keeps it open; the
	// grace covers the gap between them.
	document.addEventListener('pointerout', function (e) {
		var s = current();
		if (!s || s.via !== 'hover' || !isMouse(e) || !s.trigger.contains(e.target)) {
			return;
		}
		if (e.relatedTarget && s.trigger.contains(e.relatedTarget)) {
			return;
		}
		clearTimeout(closeTimer);
		closeTimer = window.setTimeout(close, GRACE);
	});

	// Keyboard focus opens; focus from a tap is left to the click below.
	document.addEventListener('focusin', function (e) {
		var t = triggerOf(e.target);
		var s = current();
		if (!t || (s && s.trigger === t)) {
			return;
		}
		if (byKeyboard(t)) {
			open(t, 'focus');
		}
	});

	document.addEventListener('focusout', function (e) {
		var s = current();
		if (!s || s.via === 'hover' || !s.trigger.contains(e.target)) {
			return;
		}
		if (e.relatedTarget && s.trigger.contains(e.relatedTarget)) {
			return;
		}
		close();
	});

	// Capture: the trigger is ours alone. A "?" inside a <label> must not
	// focus the field (and open the phone keyboard); a card that opens on
	// click must not see it. A click inside a list bubble is the list's.
	document.addEventListener('click', function (e) {
		var t = triggerOf(e.target);
		if (!t) {
			return;
		}
		var s = current();
		if (s && s.trigger === t && s.interactive && s.tip.contains(e.target)) {
			return;
		}
		e.preventDefault();
		e.stopPropagation();
		toggle(t);
	}, true);

	document.addEventListener('keydown', function (e) {
		var s = current();
		if (e.key === 'Escape' || e.key === 'Esc') {
			if (s) {
				close();
			}
			return;
		}
		if (e.key !== 'Enter' && e.key !== ' ') {
			return;
		}
		var t = triggerOf(e.target);
		if (!t || t !== e.target || /^(A|BUTTON|INPUT|SELECT|TEXTAREA)$/.test(t.tagName)) {
			return;
		}
		e.preventDefault();
		if (s && s.trigger === t) {
			close();
		} else {
			open(t, 'click');
		}
	});

	document.addEventListener('scroll', function (e) {
		var s = current();
		if (s && !s.tip.contains(e.target)) {
			schedule(false);
		}
	}, true);

	function roomChanged() {
		band = null;
		schedule(true);
	}
	window.addEventListener('resize', roomChanged);
	if (window.visualViewport) {
		window.visualViewport.addEventListener('resize', roomChanged);
		window.visualViewport.addEventListener('scroll', roomChanged);
	}

	// A header level change moves the trigger (the dashboard's "?" goes from
	// the right end to the left edge). Capture: fit-row does not bubble it.
	document.addEventListener('brikpanel:fit-row', function (e) {
		var s = current();
		if (s && e.target && e.target.contains && e.target.contains(s.trigger)) {
			schedule(true);
		}
	}, true);

	window.brikpanelTip = {
		close: close,
		nudge: nudge,
		refresh: roomChanged
	};
})(window, document);
