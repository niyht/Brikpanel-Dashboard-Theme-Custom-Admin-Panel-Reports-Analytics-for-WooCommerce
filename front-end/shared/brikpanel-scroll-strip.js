/**
 * BrikPanel - a row of tabs that scrolls inside itself.
 *
 * Field test C: at phone widths the Google Sheets tab row pushed the whole page
 * sideways (the row neither wrapped nor scrolled), the Customer Analytics tabs
 * broke every label over two lines, and the orders status tabs faded out under
 * the search button whether or not anything was left to scroll to, while the
 * first tab was cut hard once the row was scrolled. A tab row keeps one line,
 * scrolls inside its own box and fades only at an edge that has more behind it.
 *
 * Mark a row with `data-bp-strip` (set up at DOMContentLoaded) or call
 * `brikpanelScrollStrip(el, opts)` for a row a script builds. The row's
 * children are the items. The look lives in brikpanel-scroll-strip.css.
 *
 * - The fades are a mask whose four stops the script sets as custom
 *   properties, physical left and right: in a right-to-left page the start is on
 *   the right. `scrollLeft` runs negative there, so positions use its absolute
 *   value.
 * - `endClear` / `startClear` (px, or the `data-bp-strip-end-clear` attribute):
 *   a band at that edge that stays clear of tabs, e.g. under the orders search
 *   button, which sits on top of the row. Tabs fade out before it.
 * - The active item (`active` selector) is kept fully in view on start, when it
 *   changes and when an item gets focus, by moving `scrollLeft` only: the page
 *   itself never scrolls.
 *
 * Usage:
 *   var strip = window.brikpanelScrollStrip && window.brikpanelScrollStrip(el, { endClear: 58 });
 *   strip.sync();                    // after changing the items outside the observers
 *   brikpanelScrollStrip.inspect();  // state of every strip, for the test kit
 */
(function (window, document) {
	'use strict';

	if (window.brikpanelScrollStrip) {
		return;
	}

	var ACTIVE = '.is-active, .current, .active, [aria-selected="true"], [aria-current="page"]';
	var strips = [];
	var byEl = typeof window.WeakMap === 'function' ? new window.WeakMap() : null;
	var resizeObserver = null;

	function num(v, fallback) {
		var n = parseFloat(v);
		return isFinite(n) ? n : fallback;
	}

	function Strip(el, opts) {
		opts = opts || {};
		this.el = el;
		this.fade = num(opts.fade != null ? opts.fade : el.getAttribute('data-bp-strip-fade'), 28);
		this.endClear = num(opts.endClear != null ? opts.endClear : el.getAttribute('data-bp-strip-end-clear'), 0);
		this.startClear = num(opts.startClear != null ? opts.startClear : el.getAttribute('data-bp-strip-start-clear'), 0);
		this.active = opts.active || el.getAttribute('data-bp-strip-active') || ACTIVE;
		this.frame = 0;
		this.lastActive = null;
		this.state = { hiddenStart: false, hiddenEnd: false, fadeStart: false, fadeEnd: false };
		var self = this;
		this.onScroll = function () {
			self.schedule(false);
		};
		this.onFocus = function (e) {
			var item = self.itemOf(e.target);
			if (item) {
				self.reveal(item);
			}
		};
	}

	Strip.prototype.rtl = function () {
		return window.getComputedStyle(this.el).direction === 'rtl';
	};

	// The direct child of the strip that holds a node, or null.
	Strip.prototype.itemOf = function (node) {
		while (node && node.parentNode !== this.el) {
			node = node.parentNode;
		}
		return node && node.nodeType === 1 ? node : null;
	};

	Strip.prototype.activeItem = function () {
		var hit = null;
		try {
			hit = this.el.querySelector(this.active);
		} catch (e) {
			hit = null;
		}
		return hit ? this.itemOf(hit) || hit : null;
	};

	// Move the row (never the page) so the item sits inside the clear part.
	Strip.prototype.reveal = function (item) {
		if (!item || !this.el.isConnected) {
			return;
		}
		var box = this.el.getBoundingClientRect();
		var r = item.getBoundingClientRect();
		var rtl = this.rtl();
		var leftClear = (rtl ? this.endClear : this.startClear);
		var rightClear = (rtl ? this.startClear : this.endClear);
		var hasMore = this.el.scrollWidth > this.el.clientWidth + 1;
		if (!hasMore) {
			return;
		}
		// A fade covers the edge too: keep the item clear of it.
		var left = box.left + leftClear + this.fade;
		var right = box.right - rightClear - this.fade;
		var delta = 0;
		if (r.width > right - left) {
			delta = r.left - left;
		} else if (r.left < left) {
			delta = r.left - left;
		} else if (r.right > right) {
			delta = r.right - right;
		}
		if (Math.abs(delta) >= 1) {
			this.el.scrollLeft += delta;
		}
	};

	Strip.prototype.sync = function () {
		var el = this.el;
		if (!el.isConnected) {
			this.destroy();
			return;
		}
		var max = el.scrollWidth - el.clientWidth;
		var pos = Math.abs(el.scrollLeft);
		var hiddenStart = max > 1 && pos > 1;
		var hiddenEnd = max > 1 && max - pos > 1;
		var rtl = this.rtl();
		var hiddenLeft = rtl ? hiddenEnd : hiddenStart;
		var hiddenRight = rtl ? hiddenStart : hiddenEnd;
		var leftClear = rtl ? this.endClear : this.startClear;
		var rightClear = rtl ? this.startClear : this.endClear;
		var s = el.style;
		// Mask stops: [0 .. l0] transparent, [l0 .. l1] fade in, then opaque up
		// to [r1 .. r0] from the right edge. A side with nothing behind it keeps
		// its clear band opaque, so a last tab that rests there is not hidden.
		s.setProperty('--bp-strip-l0', (hiddenLeft ? leftClear : 0) + 'px');
		s.setProperty('--bp-strip-l1', (hiddenLeft ? leftClear + this.fade : 0) + 'px');
		s.setProperty('--bp-strip-r0', (hiddenRight ? rightClear : 0) + 'px');
		s.setProperty('--bp-strip-r1', (hiddenRight ? rightClear + this.fade : 0) + 'px');
		el.classList.toggle('bp-strip--masked', hiddenLeft || hiddenRight);
		el.classList.toggle('bp-strip--more-start', hiddenStart);
		el.classList.toggle('bp-strip--more-end', hiddenEnd);
		this.state = { hiddenStart: hiddenStart, hiddenEnd: hiddenEnd, fadeStart: hiddenStart, fadeEnd: hiddenEnd };
	};

	Strip.prototype.schedule = function (checkActive) {
		var self = this;
		if (checkActive) {
			self.pendingActive = true;
		}
		if (self.frame) {
			return;
		}
		self.frame = window.requestAnimationFrame(function () {
			self.frame = 0;
			if (self.pendingActive) {
				self.pendingActive = false;
				var a = self.activeItem();
				if (a && a !== self.lastActive) {
					self.lastActive = a;
					self.reveal(a);
				}
			}
			self.sync();
		});
	};

	Strip.prototype.destroy = function () {
		var el = this.el;
		if (!el) {
			return;
		}
		el.removeEventListener('scroll', this.onScroll);
		el.removeEventListener('focusin', this.onFocus);
		if (resizeObserver) {
			resizeObserver.unobserve(el);
		}
		if (this.mutations) {
			this.mutations.disconnect();
		}
		if (byEl) {
			byEl['delete'](el);
		}
		var i = strips.indexOf(this);
		if (i > -1) {
			strips.splice(i, 1);
		}
		this.el = null;
	};

	function find(el) {
		if (byEl) {
			return byEl.get(el) || null;
		}
		for (var i = 0; i < strips.length; i++) {
			if (strips[i].el === el) {
				return strips[i];
			}
		}
		return null;
	}

	function brikpanelScrollStrip(el, opts) {
		if (!el || el.nodeType !== 1) {
			return null;
		}
		var existing = find(el);
		if (existing) {
			return existing;
		}
		if (!el.hasAttribute('data-bp-strip')) {
			el.setAttribute('data-bp-strip', '');
		}
		var strip = new Strip(el, opts);
		strips.push(strip);
		if (byEl) {
			byEl.set(el, strip);
		}
		el.addEventListener('scroll', strip.onScroll, { passive: true });
		el.addEventListener('focusin', strip.onFocus);
		if (typeof window.ResizeObserver === 'function') {
			if (!resizeObserver) {
				resizeObserver = new window.ResizeObserver(function (entries) {
					for (var i = 0; i < entries.length; i++) {
						var s = find(entries[i].target);
						if (s) {
							s.schedule(true);
						}
					}
				});
			}
			resizeObserver.observe(el);
		} else {
			window.addEventListener('resize', function () {
				if (strip.el) {
					strip.schedule(true);
				}
			});
		}
		if (typeof window.MutationObserver === 'function') {
			// New items, or another item became the active one.
			strip.mutations = new window.MutationObserver(function () {
				strip.schedule(true);
			});
			strip.mutations.observe(el, {
				childList: true,
				subtree: true,
				attributes: true,
				attributeFilter: ['class', 'aria-selected', 'aria-current']
			});
		}
		if (document.fonts && document.fonts.ready && document.fonts.ready.then) {
			document.fonts.ready.then(function () {
				if (strip.el) {
					strip.lastActive = null;
					strip.schedule(true);
				}
			});
		}
		// First paint: the active item in view, then the fades.
		var active = strip.activeItem();
		if (active) {
			strip.lastActive = active;
			strip.reveal(active);
		}
		strip.sync();
		return strip;
	}

	// Every `[data-bp-strip]` under `root` (default: the document).
	brikpanelScrollStrip.auto = function (root) {
		var list = (root || document).querySelectorAll('[data-bp-strip]');
		for (var i = 0; i < list.length; i++) {
			brikpanelScrollStrip(list[i]);
		}
	};

	// For the test kit: where content is hidden and where the fades are.
	brikpanelScrollStrip.inspect = function () {
		return strips.filter(function (s) {
			return s.el && s.el.isConnected;
		}).map(function (s) {
			s.sync();
			var a = s.activeItem();
			var visible = null;
			if (a) {
				var box = s.el.getBoundingClientRect();
				var r = a.getBoundingClientRect();
				var rtl = s.rtl();
				var leftClear = rtl ? s.endClear : s.startClear;
				var rightClear = rtl ? s.startClear : s.endClear;
				visible = r.left >= box.left + leftClear - 1 && r.right <= box.right - rightClear + 1;
			}
			return {
				sel: s.el.tagName.toLowerCase() + '.' + String(s.el.getAttribute('class') || '').trim().split(/\s+/).slice(0, 3).join('.'),
				hiddenStart: s.state.hiddenStart,
				hiddenEnd: s.state.hiddenEnd,
				fadeStart: s.el.classList.contains('bp-strip--more-start') && s.el.classList.contains('bp-strip--masked'),
				fadeEnd: s.el.classList.contains('bp-strip--more-end') && s.el.classList.contains('bp-strip--masked'),
				activeVisible: visible
			};
		});
	};

	window.brikpanelScrollStrip = brikpanelScrollStrip;

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', function () {
			brikpanelScrollStrip.auto();
		});
	} else {
		brikpanelScrollStrip.auto();
	}
})(window, document);
