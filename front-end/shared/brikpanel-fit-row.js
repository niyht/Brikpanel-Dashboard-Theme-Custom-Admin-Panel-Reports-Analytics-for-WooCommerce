/**
 * BrikPanel - fit a header row, giving way in priority order.
 *
 * Field test B10: the product editor's sticky header let its title give way
 * first when the row ran out of room. "Edit / product" broke over two lines at
 * 1280px and Update was left alone on a second row; with a notice in the row
 * the title shrank to "E..". The order edit bar pushed Save off the screen with
 * a long order number and status. Breakpoints cannot prevent that: the widths
 * depend on the language, on the controls a product shows (password box,
 * featured star), on the order number and on the status names a store uses.
 * (CLAUDE.md, "Başlık satırı kuralı".)
 *
 * A row lists its compact states ("levels") from the fullest to the most
 * compact. The helper tries them in that order and keeps the first one that
 * fits, so what gives way is the row's own priority order, measured:
 * - every checked line holds its items on one line (or on as many as that
 *   line may use) and inside its box, and
 * - the title is neither cut nor broken.
 *
 * How it measures:
 * - 'copy' (default): an invisible copy of the row at the live row's width, in
 *   a zero-height box right after it. The live row is never switched to measure
 *   it, so a sticky header in the page flow never changes the page height.
 * - 'live': the levels are tried on the row itself in one go, so nothing is
 *   painted in between. Only for a row out of the page flow (position: fixed)
 *   whose height does not change, like the order edit bar: its Screen Options
 *   button is styled through WordPress ids that a copy does not keep.
 * - CSS transitions are off while measuring: a transition would hand the
 *   measurement the value a property had before the class changed.
 * - An element with `data-bp-fit-labels` (a JSON list of every text it can
 *   show, e.g. Update and Saving...) is measured with its widest text, so a
 *   save in progress never flips the row. Only in 'copy' mode.
 *
 * Start it as soon as the row's opening tag is parsed (an inline script as its
 * first child): the page can arrive in pieces and be painted in between, and
 * the observers below then fit every piece before it is painted. A script
 * after the row can sit in the server's output buffer while the rest of the
 * page is built, and the row was painted unfitted for that long.
 *
 * When it measures again: the row's width changes (ResizeObserver), fonts
 * finish loading, or the row's content changes (MutationObserver). A content
 * change while one of the row's popovers is open (`hold`, default '.is-open')
 * waits until it closes, so an open menu never jumps. Class changes that only
 * toggle `ignoreClasses` (default is-open, is-active, is-bp-tip-open) are not
 * content changes, and neither is a tooltip bubble being placed (.brikpanel-tip).
 *
 * Usage:
 *   var fit = brikpanelFitRow(row, {
 *     levels: ['', 'is-fold', { cls: 'is-two-rows is-fold', lines: [...] }],
 *     lines:  [''],          // lines checked by a plain level; '' = the row
 *     title:  'h1',
 *     measure: 'copy',
 *     onChange: function (state) {}
 *   });
 *   brikpanelFitRow.auto(row); // options from the row's data-bp-fit-row JSON
 *   fit.refit();               // after changing the row outside the observers
 *
 * A level is a class string or { cls, lines, spare }. A line is a selector
 * string (inside the row; '' is the row itself) or { sel, max, steps }: `max` is
 * how many lines its items may use (default 1), `steps` are extra classes tried
 * in order for that line alone (a string or { cls, max, spare }), the first that
 * fits is kept. A line spread over more than one line must end with at least
 * two items, so a primary button is never left alone on the last one.
 *
 * Spare room: a line whose width its container sets (the row itself, or a
 * group that grows to fill it) must leave opts.spare of its room free (default
 * 0, always at least 8px), and while the page is still loading, going back to a
 * less compact level asks for opts.unfoldSpare (default 0.03), so a web font
 * swapping in does not flip the row a moment after it was painted. A level or a
 * step whose classes let an item shrink to fill the line (a label ending in
 * "…") takes `spare: false`: it fills the line to the pixel by design.
 *
 * The row gets `data-bp-fit-level` (the chosen level's index) and fires
 * 'brikpanel:fit-row' with { level, classes } whenever the level changes.
 */
(function (window, document) {
	'use strict';

	if (window.brikpanelFitRow) {
		return;
	}

	var MEASURING = 'data-bp-fit-measuring';
	var STRIP_ATTRS = ['id', 'name', 'for', 'form', 'autofocus', 'aria-controls', 'aria-labelledby', 'aria-describedby', 'data-bp-fit-row'];
	var STRIP_TAGS = 'script,template,iframe,object,embed,video,audio';
	var LATCH_MS = 600;
	var LATCH_PX = 24;
	var controllers = [];
	var observer = null;
	var styleReady = false;
	// Until the page has loaded and its fonts are in (plus a second), text can
	// still change width under a fitted row: a web font from the font stack
	// swaps in. See `unfoldSpare`.
	var settling = true;

	function settleSoon() {
		window.setTimeout(function () {
			settling = false;
		}, 1000);
	}
	if (document.readyState === 'complete') {
		settleSoon();
	} else {
		window.addEventListener('load', function () {
			if (document.fonts && document.fonts.ready && document.fonts.ready.then) {
				document.fonts.ready.then(settleSoon, settleSoon);
			} else {
				settleSoon();
			}
		});
	}

	function forEach(list, fn) {
		Array.prototype.forEach.call(list, fn);
	}

	function toArray(list) {
		return Array.prototype.slice.call(list || []);
	}

	function splitClasses(cls) {
		return String(cls || '').split(/\s+/).filter(Boolean);
	}

	// One page-wide rule switches transitions off while a row is measured.
	function ensureMeasuringStyle() {
		if (styleReady) {
			return;
		}
		styleReady = true;
		var style = document.createElement('style');
		style.id = 'brikpanel-fit-row-measuring';
		(document.head || document.documentElement).appendChild(style);
		try {
			style.sheet.insertRule('[' + MEASURING + '],[' + MEASURING + '] *{transition:none!important;animation:none!important}', 0);
		} catch (e) {
			// A browser without CSSOM insertRule measures with transitions on.
		}
	}

	function sanitizeCopy(copy) {
		var nodes = copy.querySelectorAll('*');
		STRIP_ATTRS.forEach(function (attr) {
			copy.removeAttribute(attr);
		});
		forEach(nodes, function (node) {
			for (var i = 0; i < STRIP_ATTRS.length; i++) {
				if (node.hasAttribute(STRIP_ATTRS[i])) {
					node.removeAttribute(STRIP_ATTRS[i]);
				}
			}
		});
		forEach(copy.querySelectorAll(STRIP_TAGS), function (node) {
			if (node.parentNode) {
				node.parentNode.removeChild(node);
			}
		});
	}

	// The copy of an element with several possible texts is as wide as the
	// widest: every text in one grid cell, only the widest one decides.
	function stackLabels(copy) {
		forEach(copy.querySelectorAll('[data-bp-fit-labels]'), function (el) {
			var labels;
			try {
				labels = JSON.parse(el.getAttribute('data-bp-fit-labels') || '[]');
			} catch (e) {
				labels = [];
			}
			if (!labels || !labels.length) {
				return;
			}
			// Keep icons (svg) and replace only the text.
			forEach(toArray(el.childNodes), function (node) {
				if (node.nodeType === 3) {
					el.removeChild(node);
				}
			});
			var stack = document.createElement('span');
			stack.style.cssText = 'display:inline-grid';
			labels.forEach(function (label) {
				var span = document.createElement('span');
				span.style.cssText = 'grid-area:1/1';
				span.textContent = String(label);
				stack.appendChild(span);
			});
			el.appendChild(stack);
		});
	}

	// Parse "a, b" or a list into line specs.
	function normalizeLines(lines) {
		if (!lines) {
			return [{ sel: '', max: 1, steps: null }];
		}
		return lines.map(function (line) {
			if (typeof line === 'string') {
				return { sel: line, max: 1, steps: null };
			}
			return {
				sel: line.sel || '',
				max: line.max > 1 ? line.max : 1,
				steps: Array.isArray(line.steps) ? line.steps.map(normalizeStep) : null
			};
		});
	}

	// `spare: false` on a step or a level whose classes let an item shrink
	// (a status label ending in "…"): the shrinking item fills the line to the
	// pixel, so there is never room to spare, and it already absorbs the small
	// changes the spare room is there for.
	function normalizeStep(step) {
		if (typeof step === 'string') {
			return { cls: step, max: 0, spare: true };
		}
		return {
			cls: step && step.cls ? step.cls : '',
			max: step && step.max > 1 ? step.max : 0,
			spare: !(step && step.spare === false)
		};
	}

	function normalizeLevels(levels, defaultLines) {
		return (levels || ['']).map(function (level) {
			if (typeof level === 'string') {
				return { cls: level, lines: defaultLines, spare: true };
			}
			return {
				cls: level.cls || '',
				lines: level.lines ? normalizeLines(level.lines) : defaultLines,
				spare: level.spare !== false
			};
		});
	}

	// Items laid out in a line: element children, looking through
	// display:contents wrappers, skipping what is hidden or taken out of the
	// flow (popovers).
	function lineItems(box) {
		var items = [];
		(function walk(parent) {
			forEach(parent.children, function (child) {
				var cs = window.getComputedStyle(child);
				if (cs.display === 'none') {
					return;
				}
				if (cs.display === 'contents') {
					walk(child);
					return;
				}
				if (cs.position === 'absolute' || cs.position === 'fixed') {
					return;
				}
				var r = child.getBoundingClientRect();
				if (r.width < 0.5 && r.height < 0.5) {
					return;
				}
				items.push(r);
			});
		})(box);
		return items;
	}

	// The lines the items use (by vertical overlap), top to bottom, with the
	// number of items on each and their summed width.
	function lineGroups(rects) {
		var lines = [];
		rects.slice().sort(function (a, b) {
			return a.top - b.top;
		}).forEach(function (r) {
			var mid = (r.top + r.bottom) / 2;
			for (var i = 0; i < lines.length; i++) {
				if (mid >= lines[i].top && mid <= lines[i].bottom) {
					lines[i].n++;
					lines[i].sum += r.width;
					lines[i].top = Math.min(lines[i].top, r.top);
					lines[i].bottom = Math.max(lines[i].bottom, r.bottom);
					return;
				}
			}
			lines.push({ top: r.top, bottom: r.bottom, n: 1, sum: r.width });
		});
		lines.sort(function (a, b) {
			return a.top - b.top;
		});
		return lines;
	}

	// A line fits when its items stay inside the box, use at most `max` lines,
	// do not strand one item alone on the last one and, when `ratio` is a
	// number, leave that share of the room spare (at least 8px, for rounding).
	// `ratio` is null for a group as wide as its own content, which never has
	// any spare room: the row around it is the one that is asked.
	function lineFits(box, max, ratio) {
		if (!box) {
			return true;
		}
		var rects = lineItems(box);
		if (!rects.length) {
			return true;
		}
		var br = box.getBoundingClientRect();
		var cs = window.getComputedStyle(box);
		var left = br.left + box.clientLeft + (parseFloat(cs.paddingLeft) || 0);
		var right = br.left + box.clientLeft + box.clientWidth - (parseFloat(cs.paddingRight) || 0);
		for (var i = 0; i < rects.length; i++) {
			if (rects[i].left < left - 1 || rects[i].right > right + 1) {
				return false;
			}
		}
		var lines = lineGroups(rects);
		if (lines.length > max) {
			return false;
		}
		var room = right - left;
		var gap = parseFloat(cs.columnGap);
		var spare = typeof ratio === 'number' ? Math.max(8, room * ratio) : 0;
		if (isNaN(gap)) {
			gap = 0;
		}
		for (var j = 0; j < lines.length; j++) {
			if (lines[j].sum + gap * (lines[j].n - 1) + spare > room + 1) {
				return false;
			}
		}
		// A spread line must not strand its last item alone.
		return lines.length < 2 || lines[lines.length - 1].n >= 2;
	}

	function titleFits(root, sel) {
		if (!sel) {
			return true;
		}
		var t = root.querySelector(sel);
		if (!t) {
			return true;
		}
		if (t.scrollWidth > t.clientWidth + 1) {
			return false;
		}
		var range = document.createRange();
		range.selectNodeContents(t);
		var tops = {};
		var count = 0;
		forEach(range.getClientRects(), function (r) {
			if (r.width < 0.5) {
				return;
			}
			var key = Math.round(r.top);
			if (!tops[key]) {
				tops[key] = true;
				count++;
			}
		});
		return count <= 1;
	}

	function resolve(root, sel) {
		return sel ? root.querySelector(sel) : root;
	}

	function Controller(row, opts) {
		opts = opts || {};
		this.row = row;
		this.title = opts.title || '';
		this.measure = opts.measure === 'live' ? 'live' : 'copy';
		// Share of a row's room a level must leave free to be chosen (at least
		// 8px), and the larger share asked, while the page is still loading,
		// before going back to a less compact level. Without that second margin
		// a web font from the font stack (Roboto) arriving mid-load narrowed the
		// editor header by ~3% and unfolded it a moment after it was painted.
		// Once the page has settled the level only depends on the width and
		// the content, so the same window always shows the same header.
		this.spare = typeof opts.spare === 'number' ? opts.spare : 0;
		this.unfoldSpare = typeof opts.unfoldSpare === 'number' ? opts.unfoldSpare : 0.03;
		this.useSpare = this.spare;
		this.hold = typeof opts.hold === 'string' ? opts.hold : '.is-open';
		this.ignore = Array.isArray(opts.ignoreClasses) ? opts.ignoreClasses : ['is-open', 'is-active', 'is-bp-tip-open'];
		this.onChange = typeof opts.onChange === 'function' ? opts.onChange : null;
		var defaultLines = normalizeLines(opts.lines || ['']);
		this.levels = normalizeLevels(opts.levels, defaultLines);
		this.allClasses = [];
		var seen = {};
		var self = this;
		this.levels.forEach(function (level) {
			splitClasses(level.cls).forEach(function (c) { if (!seen[c]) { seen[c] = true; self.allClasses.push(c); } });
			level.lines.forEach(function (line) {
				(line.steps || []).forEach(function (step) {
					splitClasses(step.cls).forEach(function (c) { if (!seen[c]) { seen[c] = true; self.allClasses.push(c); } });
				});
			});
		});
		this.index = -1;
		this.classes = '';
		this.lastWidth = 0;
		this.lastChange = 0;
		this.latchWidth = 0;
		this.pending = false;
		this.frame = 0;
		this.trials = 0;
		this.ms = 0;
		this.changes = 0;
		this.mo = null;
	}

	Controller.prototype.setClasses = function (el, classes) {
		var want = {};
		splitClasses(classes).forEach(function (c) { want[c] = true; });
		this.allClasses.forEach(function (c) {
			if (want[c]) {
				el.classList.add(c);
			} else {
				el.classList.remove(c);
			}
		});
	};

	// A line fits, and so does the title when it sits in that line. Spare room
	// is only asked of a line whose width its container sets (the row itself,
	// or a group that grows to fill it): a group that is as wide as its own
	// content never has any, and the row around it already asks for it.
	Controller.prototype.lineOk = function (subject, line, max, spare) {
		var box = resolve(subject, line.sel);
		var ratio = null;
		if (box && spare !== false) {
			var cs = window.getComputedStyle(box);
			if (box === subject || parseFloat(cs.flexGrow) > 0 || cs.flexBasis === '100%') {
				ratio = this.useSpare;
			}
		}
		if (!lineFits(box, max, ratio)) {
			return false;
		}
		if (this.title && box && box.querySelector(this.title)) {
			return titleFits(box, this.title);
		}
		return true;
	};

	// Try every level on `subject` (the copy, or the live row); return the
	// first that fits, or the last one with each line's most compact step.
	// A line with steps keeps the first of its steps that fits, on its own:
	// the title row going compact never forces the action row to.
	Controller.prototype.search = function (subject) {
		var last = null;
		for (var i = 0; i < this.levels.length; i++) {
			var level = this.levels[i];
			var classes = level.cls;
			var maxes = [];
			var spares = [];
			var ok = true;
			for (var j = 0; j < level.lines.length; j++) {
				var line = level.lines[j];
				maxes[j] = line.max;
				spares[j] = level.spare;
				if (!line.steps) {
					continue;
				}
				var picked = -1;
				for (var k = 0; k < line.steps.length; k++) {
					var step = line.steps[k];
					this.setClasses(subject, (classes + ' ' + step.cls).trim());
					this.trials++;
					if (this.lineOk(subject, line, step.max || line.max, level.spare && step.spare)) {
						picked = k;
						break;
					}
				}
				if (picked < 0) {
					ok = false;
					picked = line.steps.length - 1;
				}
				classes = (classes + ' ' + line.steps[picked].cls).trim();
				maxes[j] = line.steps[picked].max || line.max;
				spares[j] = level.spare && line.steps[picked].spare;
			}
			this.setClasses(subject, classes);
			this.trials++;
			for (var m = 0; ok && m < level.lines.length; m++) {
				if (!this.lineOk(subject, level.lines[m], maxes[m], spares[m])) {
					ok = false;
				}
			}
			if (ok && !titleFits(subject, this.title)) {
				ok = false;
			}
			last = { index: i, classes: classes, maxes: maxes, spares: spares, fitted: ok };
			if (ok) {
				return last;
			}
		}
		return last || { index: 0, classes: '', maxes: [], fitted: true };
	};

	// The search, and while the page is still loading, before going back to a
	// less compact level than the row has now, a second one that asks for more
	// room to spare. Folding further is never delayed: that would let the row
	// overflow.
	Controller.prototype.carefulSearch = function (subject) {
		this.useSpare = this.spare;
		var result = this.search(subject);
		if (settling && this.index > -1 && result.index < this.index) {
			this.useSpare = this.unfoldSpare;
			var careful = this.search(subject);
			this.useSpare = this.spare;
			if (careful.index < this.index) {
				return careful;
			}
			return { index: this.index, classes: this.classes, maxes: this.maxes || [], spares: this.spares || [], fitted: this.fitted !== false };
		}
		return result;
	};

	Controller.prototype.fit = function (fromResize) {
		var row = this.row;
		if (!row || !row.isConnected) {
			this.destroy();
			return;
		}
		var width = row.getBoundingClientRect().width;
		if (width < 1) {
			return; // not laid out yet; the ResizeObserver tries again
		}
		var started = window.performance && performance.now ? performance.now() : 0;
		var live = this.measure === 'live';
		ensureMeasuringStyle();
		var result;
		if (live) {
			row.setAttribute(MEASURING, '');
			result = this.carefulSearch(row);
		} else {
			var box = document.createElement('div');
			box.setAttribute('aria-hidden', 'true');
			box.setAttribute('inert', '');
			box.style.cssText = 'position:absolute;left:0;top:0;width:' + width + 'px;height:0;overflow:hidden;visibility:hidden;pointer-events:none;contain:layout style';
			var copy = row.cloneNode(true);
			sanitizeCopy(copy);
			stackLabels(copy);
			copy.setAttribute(MEASURING, '');
			copy.setAttribute('aria-hidden', 'true');
			copy.style.setProperty('position', 'static', 'important');
			copy.style.setProperty('box-sizing', 'border-box', 'important');
			copy.style.setProperty('width', width + 'px', 'important');
			copy.style.setProperty('max-width', 'none', 'important');
			copy.style.setProperty('margin', '0', 'important');
			box.appendChild(copy);
			row.parentNode.insertBefore(box, row.nextSibling);
			result = this.carefulSearch(copy);
			row.parentNode.removeChild(box);
		}
		if (started) {
			this.ms = Math.round((performance.now() - started) * 10) / 10;
		}

		var now = Date.now();
		// Scrollbar latch: a page scrollbar that appears because the row became
		// more compact must not flip it straight back.
		var latched = fromResize && this.index > -1 && result.index < this.index
			&& now - this.lastChange < LATCH_MS && Math.abs(width - this.latchWidth) <= LATCH_PX;
		if (live) {
			// Leave the live row in the kept state with transitions still off,
			// and let the browser take that style before they come back on.
			this.setClasses(row, latched ? this.classes : result.classes);
			void row.offsetWidth;
			row.removeAttribute(MEASURING);
		}
		this.lastWidth = width;
		if (latched) {
			return;
		}
		this.fitted = result.fitted;
		this.maxes = result.maxes || [];
		this.spares = result.spares || [];
		if (result.index !== this.index || result.classes !== this.classes) {
			var grew = this.index > -1 && result.index > this.index;
			this.index = result.index;
			this.classes = result.classes;
			if (!live) {
				this.setClasses(row, result.classes);
			}
			row.setAttribute('data-bp-fit-level', String(result.index));
			this.lastChange = now;
			if (grew) {
				this.latchWidth = width;
			}
			this.changes++;
			if (this.mo) {
				this.mo.takeRecords();
			}
			this.markTitle();
			var detail = { level: result.index, classes: result.classes, fitted: result.fitted };
			if (this.onChange) {
				try { this.onChange(detail, this); } catch (e) { /* a caller's bug must not stop the fitting */ }
			}
			var ev;
			try {
				ev = new window.CustomEvent('brikpanel:fit-row', { detail: detail });
			} catch (e) {
				ev = document.createEvent('CustomEvent');
				ev.initCustomEvent('brikpanel:fit-row', false, false, detail);
			}
			row.dispatchEvent(ev);
		} else {
			this.markTitle();
		}
	};

	// The full title as a tooltip, only while the title is really cut.
	Controller.prototype.markTitle = function () {
		if (!this.title) {
			return;
		}
		var t = this.row.querySelector(this.title);
		if (!t) {
			return;
		}
		var cut = t.scrollWidth > t.clientWidth + 1;
		if (cut) {
			t.setAttribute('title', (t.textContent || '').trim());
			t.setAttribute('data-bp-fit-cut', '1');
		} else if (t.hasAttribute('data-bp-fit-cut')) {
			t.removeAttribute('title');
			t.removeAttribute('data-bp-fit-cut');
		}
	};

	Controller.prototype.schedule = function (fromResize) {
		var self = this;
		if (!fromResize && this.hold && this.row.querySelector(this.hold)) {
			this.pending = true; // an open popover: wait until it closes
			return;
		}
		if (this.frame) {
			return;
		}
		this.frame = window.requestAnimationFrame(function () {
			self.frame = 0;
			self.pending = false;
			self.fit(fromResize);
		});
	};

	Controller.prototype.refit = function () {
		if (this.frame) {
			window.cancelAnimationFrame(this.frame);
			this.frame = 0;
		}
		this.pending = false;
		this.fit(false);
	};

	// Whether a class change is only one of the ignored state classes.
	Controller.prototype.onlyStateChange = function (record) {
		var before = splitClasses(record.oldValue);
		var after = splitClasses(record.target.getAttribute('class'));
		var ignore = this.ignore.concat(this.allClasses);
		var strip = function (list) {
			return list.filter(function (c) { return ignore.indexOf(c) === -1; }).sort().join(' ');
		};
		return strip(before) === strip(after);
	};

	Controller.prototype.watch = function () {
		var self = this;
		if (typeof window.MutationObserver === 'function') {
			this.mo = new window.MutationObserver(function (records) {
				var content = false;
				var closed = false;
				for (var i = 0; i < records.length; i++) {
					var r = records[i];
					var target = r.target.nodeType === 1 ? r.target : r.target.parentNode;
					// The row's own attributes are the helper's classes (and a
					// screen's inline position); a child added to the row itself
					// is content, like the page still arriving.
					if (!target || (r.target === self.row && r.type === 'attributes')) {
						continue;
					}
					// A label swap is measured already; a tooltip's placement
					// (brikpanel-tip.js) is not content.
					if (target.closest && target.closest('[data-bp-fit-labels], .brikpanel-tip')) {
						continue;
					}
					if (r.type === 'attributes' && r.attributeName === 'class') {
						if (self.onlyStateChange(r)) {
							var was = splitClasses(r.oldValue).indexOf('is-open') > -1;
							var now = target.classList.contains('is-open');
							if (was && !now) {
								closed = true;
							}
							continue;
						}
					}
					if (r.type === 'attributes' && r.attributeName === 'title') {
						continue;
					}
					content = true;
				}
				if (content || (closed && self.pending)) {
					self.schedule(false);
				}
			});
			this.mo.observe(this.row, {
				subtree: true,
				childList: true,
				characterData: true,
				attributes: true,
				attributeOldValue: true,
				attributeFilter: ['class', 'hidden', 'style']
			});
		}
		if (typeof window.ResizeObserver === 'function') {
			if (!observer) {
				observer = new window.ResizeObserver(function (entries) {
					forEach(entries, function (entry) {
						var c = find(entry.target);
						if (!c) {
							return;
						}
						var w = entry.target.getBoundingClientRect().width;
						if (Math.abs(w - c.lastWidth) >= 0.5) {
							c.schedule(true);
						}
					});
				});
			}
			observer.observe(this.row);
		} else {
			window.addEventListener('resize', function () {
				self.schedule(true);
			});
		}
		if (document.fonts) {
			if (document.fonts.ready && document.fonts.ready.then) {
				document.fonts.ready.then(function () { self.schedule(true); });
			}
			if (document.fonts.addEventListener) {
				document.fonts.addEventListener('loadingdone', function () { self.schedule(true); });
			}
		}
	};

	// A live check of the current state, for the test harness only. A row
	// whose most compact level still did not fit is not a drift.
	Controller.prototype.liveFits = function () {
		if (!this.row || this.index < 0 || this.fitted === false) {
			return null;
		}
		var level = this.levels[this.index] || this.levels[0];
		var maxes = this.maxes || [];
		var spares = this.spares || [];
		for (var i = 0; i < level.lines.length; i++) {
			if (!this.lineOk(this.row, level.lines[i], maxes[i] || level.lines[i].max, spares[i])) {
				return false;
			}
		}
		return titleFits(this.row, this.title);
	};

	Controller.prototype.destroy = function () {
		if (this.mo) {
			this.mo.disconnect();
			this.mo = null;
		}
		if (observer && this.row) {
			observer.unobserve(this.row);
		}
		var i = controllers.indexOf(this);
		if (i > -1) {
			controllers.splice(i, 1);
		}
		this.row = null;
	};

	function find(row) {
		for (var i = 0; i < controllers.length; i++) {
			if (controllers[i].row === row) {
				return controllers[i];
			}
		}
		return null;
	}

	function brikpanelFitRow(row, opts) {
		if (!row || row.nodeType !== 1) {
			return null;
		}
		var existing = find(row);
		if (existing) {
			return existing;
		}
		if (!opts) {
			return null;
		}
		var c = new Controller(row, opts);
		controllers.push(c);
		c.fit(false);
		c.watch();
		return c;
	}

	// Options from the row's own data-bp-fit-row attribute (JSON).
	brikpanelFitRow.auto = function (row) {
		if (!row || row.nodeType !== 1) {
			return null;
		}
		var existing = find(row);
		if (existing) {
			return existing;
		}
		var opts;
		try {
			opts = JSON.parse(row.getAttribute('data-bp-fit-row') || 'null');
		} catch (e) {
			opts = null;
		}
		return opts ? brikpanelFitRow(row, opts) : null;
	};

	brikpanelFitRow.get = find;

	// For the test harness: every fitted row and what the helper decided.
	brikpanelFitRow.inspect = function () {
		return controllers.map(function (c) {
			var row = c.row;
			return {
				row: row ? (row.tagName.toLowerCase() + (row.id ? '#' + row.id : '') + '.' + String(row.getAttribute('class') || '').trim().split(/\s+/).slice(0, 2).join('.')) : '',
				width: c.lastWidth,
				index: c.index,
				classes: c.classes,
				fitted: c.fitted,
				liveFits: row ? c.liveFits() : null,
				trials: c.trials,
				ms: c.ms,
				changes: c.changes
			};
		});
	};

	window.brikpanelFitRow = brikpanelFitRow;

	// Safety net: a row whose inline call never ran (a blocked inline script).
	function autoAll() {
		forEach(document.querySelectorAll('[data-bp-fit-row]'), function (row) {
			brikpanelFitRow.auto(row);
		});
	}
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', autoAll);
	} else {
		autoAll();
	}
})(window, document);
