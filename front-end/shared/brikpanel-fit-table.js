/**
 * BrikPanel - fit the table, or stack its rows.
 *
 * Field tests B2 and B6: tables inside a card lost their last columns to an
 * `overflow-x:auto` box. The browser hid the overflow in a scroll area with no
 * hint, so row buttons, COGS, SKU and Delete were simply not there for most
 * people. A table that cannot show every column inside its card now turns its
 * rows into stacked cards instead (CLAUDE.md, "Tablo sığma kuralı"). The
 * stacked look itself lives in each screen's stylesheet (or in the opt-in
 * `brikpanel-fit-table` base), under `.is-stacked`.
 *
 * How the decision is made:
 * - The width a table needs is measured on an invisible copy in a zero-size
 *   box, never on the live table. Switching the live table back to a table
 *   just to measure it shortened the page for a moment, which cost a stacked
 *   list its scroll position and the browser's scroll anchoring on the next
 *   resize.
 * - The copy loses ids, names and `for` before it enters the document. A
 *   copied checked radio that becomes connected unchecks the live radio of
 *   the same group, and a duplicated id or form field name can be picked up
 *   by other scripts.
 * - After every render the screen calls `refit()`, which measures again.
 *   A width change of the box re-applies the cached measurement at once and
 *   measures again once the width has settled (media queries can change what
 *   the table needs).
 * - A table that is hidden when it renders (a closed section, a tab) is
 *   measured as soon as its box gets a width.
 *
 * Style fitted tables by class, not by id: the measured copy has no ids.
 * Tables must use `table-layout:auto`.
 *
 * Usage:
 *   var fit = window.brikpanelFitTable && window.brikpanelFitTable(table, { floor: 720 });
 *   // after every tbody render:
 *   if (fit) fit.refit();
 *
 * `target` is the table, or the box whose table is re-rendered in place (the
 * current table is then looked up on each measurement).
 *
 * Options:
 *   wrap         the box whose width is the room (default: the table's parent)
 *   floor        stack whenever the room is narrower than this, fit or not (0)
 *   slack        tolerated overflow in px before stacking (1)
 *   hysteresis   extra px a stacked table needs before it unstacks on a resize (0)
 *   cls          class set on the table while stacked ('is-stacked')
 *   levels       class lists the table may take before it stacks, roomiest
 *                first, e.g. ['', 'is-snug', 'is-snug is-fold']. Each level is
 *                measured on its own copy and the first one that fits is set
 *                on the table; only when none fits does it stack, keeping the
 *                last level's classes. Scope the level rules with
 *                `:not(.is-stacked)` where the stacked look must not get them.
 *   labels       'head' copies each header text into `data-bp-label` of the
 *                body cells in the same column (cells that have one are kept)
 *   prepareClone function(copy) run on the detached copy before it is measured
 *   onChange     function(stacked, controller) run when the state flips (or,
 *                with `levels`, when the level changes: controller.level)
 *   spanRows     false keeps message rows as rendered. By default a body row
 *                with a single spanning cell (empty, loading, error) spans
 *                exactly the header cells that show: with `table-layout:fixed`
 *                a colspan that also counts hidden columns makes the browser
 *                add phantom columns, which took their share of the width and
 *                ended the header's background half way (field test C12).
 */
(function (window, document) {
	'use strict';

	if (window.brikpanelFitTable) {
		return;
	}

	var controllers = [];
	var byWrap = typeof window.WeakMap === 'function' ? new window.WeakMap() : null;
	var observer = null;
	var fontsHooked = false;
	var STRIP_ATTRS = ['id', 'name', 'for', 'form', 'autofocus'];
	var STRIP_TAGS = 'script,template,iframe,object,embed,video,audio';

	function forEach(list, fn) {
		Array.prototype.forEach.call(list, fn);
	}

	function sanitizeCopy(copy) {
		var nodes = copy.querySelectorAll('*');
		forEach(STRIP_ATTRS, function (attr) {
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

	function stampHeadLabels(table) {
		var head = table.tHead && table.tHead.rows[0];
		if (!head) {
			return;
		}
		var labels = [];
		forEach(head.cells, function (th) {
			labels.push((th.textContent || '').replace(/\s+/g, ' ').trim());
		});
		forEach(table.tBodies, function (tbody) {
			forEach(tbody.rows, function (row) {
				// Message and colspan rows do not line up with the header.
				if (row.cells.length !== labels.length) {
					return;
				}
				forEach(row.cells, function (cell, i) {
					if (labels[i] && !cell.hasAttribute('data-bp-label')) {
						cell.setAttribute('data-bp-label', labels[i]);
					}
				});
			});
		});
	}

	// A one-cell message row spans the header cells that show, no more.
	function syncSpans(table) {
		var head = table.tHead && table.tHead.rows[0];
		if (!head) {
			return;
		}
		var cols = 0;
		forEach(head.cells, function (th) {
			if (window.getComputedStyle(th).display !== 'none') {
				cols += th.colSpan || 1;
			}
		});
		cols = Math.max(1, cols);
		forEach(table.tBodies, function (tbody) {
			forEach(tbody.rows, function (row) {
				if (row.cells.length === 1 && row.cells[0].colSpan > 1 && row.cells[0].colSpan !== cols) {
					row.cells[0].colSpan = cols;
				}
			});
		});
	}

	function Controller(target, opts) {
		opts = opts || {};
		this.isTable = target.tagName === 'TABLE';
		this.fixedTable = this.isTable ? target : null;
		this.wrap = opts.wrap || (this.isTable ? target.parentNode : target);
		this.floor = opts.floor || 0;
		this.slack = typeof opts.slack === 'number' ? opts.slack : 1;
		this.hysteresis = opts.hysteresis || 0;
		this.cls = opts.cls || 'is-stacked';
		this.labels = opts.labels || false;
		this.prepareClone = typeof opts.prepareClone === 'function' ? opts.prepareClone : null;
		this.onChange = typeof opts.onChange === 'function' ? opts.onChange : null;
		this.spanRows = opts.spanRows !== false;
		this.levels = null;
		this.levelClasses = [];
		if (opts.levels && opts.levels.length) {
			var all = this.levelClasses;
			this.levels = Array.prototype.map.call(opts.levels, function (list) {
				var names = String(list || '').split(/\s+/).filter(Boolean);
				forEach(names, function (name) {
					if (all.indexOf(name) === -1) {
						all.push(name);
					}
				});
				return names;
			});
		}
		this.needs = [];
		this.level = -1;
		this.levelTable = null;
		this.need = -1;
		this.measuredTable = null;
		this.lastRoom = 0;
		this.frame = 0;
		this.settleTimer = 0;
	}

	Controller.prototype.table = function () {
		if (this.fixedTable) {
			return this.fixedTable.isConnected === false ? null : this.fixedTable;
		}
		return this.wrap ? this.wrap.querySelector('table') : null;
	};

	// Width the table needs with `names` (a level's classes) set on it.
	Controller.prototype.measureWith = function (table, names) {
		var box = document.createElement('div');
		var copy = table.cloneNode(true);
		box.setAttribute('aria-hidden', 'true');
		box.style.cssText = 'position:absolute;width:0;height:0;overflow:hidden;visibility:hidden;pointer-events:none';
		copy.classList.remove(this.cls);
		forEach(this.levelClasses, function (name) {
			copy.classList.remove(name);
		});
		forEach(names || [], function (name) {
			copy.classList.add(name);
		});
		copy.setAttribute('inert', '');
		sanitizeCopy(copy);
		if (this.prepareClone) {
			this.prepareClone(copy);
		}
		box.appendChild(copy);
		this.wrap.appendChild(box);
		var need = copy.offsetWidth;
		this.wrap.removeChild(box);
		return need;
	};

	Controller.prototype.measure = function (table) {
		var self = this;
		if (this.levels) {
			this.needs = this.levels.map(function (names) {
				return self.measureWith(table, names);
			});
			this.need = this.needs[this.needs.length - 1];
		} else {
			this.need = this.measureWith(table, null);
		}
		this.measuredTable = table;
	};

	// The first level that fits, or -1. A resize only moves to a roomier
	// level than the current one when that level fits with the hysteresis to
	// spare, for the same reason a stacked table unstacks late (below).
	Controller.prototype.pickLevel = function (room, fromResize, current) {
		for (var i = 0; i < this.needs.length; i++) {
			var need = this.needs[i];
			if (need > room + this.slack) {
				continue;
			}
			if (fromResize && this.hysteresis && i < current && need > room - this.hysteresis) {
				continue;
			}
			return i;
		}
		return -1;
	};

	Controller.prototype.setLevel = function (table, level) {
		if (level === this.level && table === this.levelTable) {
			return false;
		}
		var keep = this.levels[level] || [];
		forEach(this.levelClasses, function (name) {
			table.classList.toggle(name, keep.indexOf(name) > -1);
		});
		this.level = level;
		this.levelTable = table;
		return true;
	};

	Controller.prototype.apply = function (fromResize) {
		if (!this.wrap) {
			return;
		}
		var room = this.wrap.clientWidth;
		if (!room) {
			this.lastRoom = 0; // not laid out yet (hidden); the observer retries
			return;
		}
		var table = this.table();
		if (!table) {
			return;
		}
		if (this.spanRows) {
			syncSpans(table);
		}
		// Below the floor the table stacks whatever it needs: no copy to measure
		// (a phone's product list sets an endless floor and redraws often).
		if (room >= this.floor && (this.need < 0 || this.measuredTable !== table)) {
			this.measure(table);
		}
		var was = table.classList.contains(this.cls);
		var stack;
		var levelChanged = false;
		if (this.levels) {
			var current = was ? this.levels.length : (table === this.levelTable ? this.level : 0);
			var level = room < this.floor ? -1 : this.pickLevel(room, fromResize, current);
			stack = level < 0;
			// Stacked, the table keeps the most compact level's classes.
			levelChanged = this.setLevel(table, stack ? this.levels.length - 1 : level);
		} else {
			stack = room < this.floor || this.need > room + this.slack;
			// Only a resize may keep a stacked table stacked a little longer: a
			// page scrollbar that appears because the cards are taller must not
			// flip the table straight back.
			if (!stack && was && fromResize && this.hysteresis && this.need > room - this.hysteresis) {
				stack = true;
			}
		}
		if (stack !== was) {
			table.classList.toggle(this.cls, stack);
		}
		if ((stack !== was || levelChanged) && this.onChange) {
			this.onChange(stack, this);
		}
		this.lastRoom = room;
	};

	// After every render: the rows changed, so they are measured again.
	Controller.prototype.refit = function () {
		var table = this.table();
		if (table && this.labels === 'head') {
			stampHeadLabels(table);
		}
		this.need = -1;
		this.apply(false);
	};

	Controller.prototype.schedule = function () {
		var self = this;
		// Once the width settles, measure again: a media query may have changed
		// what the table needs (the widescreen editor floors its name column
		// above 1360px only), and a stale smaller need would leave the table
		// overflowing instead of stacked.
		window.clearTimeout(self.settleTimer);
		self.settleTimer = window.setTimeout(function () {
			if (!self.wrap) {
				return;
			}
			self.need = -1;
			self.apply(true);
		}, 200);
		if (self.frame) {
			return;
		}
		self.frame = window.requestAnimationFrame(function () {
			self.frame = 0;
			self.apply(true);
		});
	};

	Controller.prototype.isStacked = function () {
		var table = this.table();
		return !!(table && table.classList.contains(this.cls));
	};

	Controller.prototype.state = function () {
		return { room: this.wrap ? this.wrap.clientWidth : 0, need: this.need, stacked: this.isStacked(), level: this.level, needs: this.needs.slice() };
	};

	Controller.prototype.destroy = function () {
		window.clearTimeout(this.settleTimer);
		if (observer && this.wrap) {
			observer.unobserve(this.wrap);
		}
		if (byWrap && this.wrap) {
			byWrap['delete'](this.wrap);
		}
		var i = controllers.indexOf(this);
		if (i > -1) {
			controllers.splice(i, 1);
		}
		this.wrap = null;
	};

	function findController(wrap) {
		if (byWrap) {
			return byWrap.get(wrap) || null;
		}
		for (var i = 0; i < controllers.length; i++) {
			if (controllers[i].wrap === wrap) {
				return controllers[i];
			}
		}
		return null;
	}

	function watch(ctl) {
		if (typeof window.ResizeObserver === 'function') {
			if (!observer) {
				// Only a width change matters; the stacked class itself only
				// changes the height. The frame keeps the work out of the
				// observer callback, so it never reports a resize loop.
				observer = new window.ResizeObserver(function (entries) {
					forEach(entries, function (entry) {
						var c = findController(entry.target);
						if (!c) {
							return;
						}
						if (!entry.target.isConnected) {
							c.destroy();
							return;
						}
						if (entry.target.clientWidth !== c.lastRoom) {
							c.schedule();
						}
					});
				});
			}
			observer.observe(ctl.wrap);
		} else {
			window.addEventListener('resize', function () {
				if (ctl.wrap) {
					ctl.schedule();
				}
			});
		}
		var details = ctl.wrap.closest ? ctl.wrap.closest('details') : null;
		if (details) {
			details.addEventListener('toggle', function () {
				if (ctl.wrap) {
					ctl.schedule();
				}
			});
		}
		if (!fontsHooked && document.fonts && document.fonts.ready) {
			fontsHooked = true;
			document.fonts.ready.then(function () {
				forEach(controllers.slice(), function (c) {
					c.refit();
				});
			});
		}
	}

	function brikpanelFitTable(target, opts) {
		if (!target) {
			return null;
		}
		var probe = new Controller(target, opts);
		if (!probe.wrap) {
			return null;
		}
		var existing = findController(probe.wrap);
		if (existing) {
			return existing;
		}
		controllers.push(probe);
		if (byWrap) {
			byWrap.set(probe.wrap, probe);
		}
		watch(probe);
		return probe;
	}

	// For the test harness: the state of every fitted table on the page.
	brikpanelFitTable.inspect = function () {
		return controllers.map(function (c) {
			var s = c.state();
			var t = c.table();
			return { table: t ? t.className : '', room: s.room, need: s.need, stacked: s.stacked, level: s.level, needs: s.needs };
		});
	};

	// For a table that is not fitted (its phone layout is its own) but still
	// shows message rows: brikpanelFitTable.syncSpans(table).
	brikpanelFitTable.syncSpans = function (table) {
		if (table && table.tagName === 'TABLE') {
			syncSpans(table);
		}
	};

	window.brikpanelFitTable = brikpanelFitTable;
})(window, document);
