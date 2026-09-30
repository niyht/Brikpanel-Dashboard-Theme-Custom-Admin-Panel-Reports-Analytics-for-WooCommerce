/**
 * BrikPanel - a row of summary tiles that never leaves one tile stranded.
 *
 * Field test C8/C10: on phones Store Health wrapped its three severity tiles
 * 2+1 and stretched the last one ("OK", the least urgent) across the row, and
 * Customer Analytics stacked its four figures one per row, each as tall as a
 * card. Tiles wrapping on their own (flex-wrap, auto-fit) end up in whatever
 * rows the width allows. Here a row of N tiles only ever takes a column count
 * that divides N evenly (4: 4 or 2+2; 3: all three or a list; 6: 6, 3+3, 2+2+2),
 * the widest that fits: every tile's content inside its tile. A list (one per
 * line) is the last resort, and gets `is-tiles-list` for a compact look.
 *
 * Mark the grid with `data-bp-tiles` (set up at DOMContentLoaded) or call
 * `brikpanelTiles(grid)`. The grid's visible children are the tiles. Tiles
 * that appear or disappear later (a "Pending" tile) are picked up. Columns
 * share the row equally; `--bp-tile-max` on the grid caps a column (a short
 * figure that should not stretch across a wide screen).
 *
 * Why measured: tile text depends on the language and the store (a currency
 * value, a long Russian label), so no breakpoint fits every case. Values and
 * labels overflow their tile instead of breaking mid-word, which is what makes
 * "does it fit" measurable. The test only switches the column count of this
 * grid, synchronously, in one frame.
 */
(function (window, document) {
	'use strict';

	if (window.brikpanelTiles) {
		return;
	}

	var grids = [];
	var observer = null;

	function tilesOf(grid) {
		return Array.prototype.filter.call(grid.children, function (el) {
			return !el.hidden && window.getComputedStyle(el).display !== 'none';
		});
	}

	// Column counts to try, widest first: the divisors of n, then 1.
	function candidates(n) {
		var out = [];
		for (var c = n; c >= 2; c--) {
			if (n % c === 0) {
				out.push(c);
			}
		}
		out.push(1);
		return out;
	}

	function overflows(tile) {
		if (tile.scrollWidth > tile.clientWidth + 1) {
			return true;
		}
		var kids = tile.querySelectorAll('*');
		for (var i = 0; i < kids.length; i++) {
			var k = kids[i];
			if (k.scrollWidth > k.clientWidth + 1 && window.getComputedStyle(k).overflowX === 'visible') {
				return true;
			}
		}
		return false;
	}

	function fit(grid) {
		if (!grid.isConnected) {
			return;
		}
		var tiles = tilesOf(grid);
		var n = tiles.length;
		if (!n || !grid.clientWidth) {
			return;
		}
		var list = candidates(n);
		var chosen = 1;
		for (var i = 0; i < list.length; i++) {
			var cols = list[i];
			// Inline, so it beats the screen's own breakpoint columns; without this
			// script the screen keeps the layout it had.
			grid.style.gridTemplateColumns = 'repeat(' + cols + ', minmax(0, var(--bp-tile-max, 1fr)))';
			grid.classList.toggle('is-tiles-list', cols === 1);
			if (cols === 1) {
				chosen = 1;
				break;
			}
			var ok = true;
			for (var t = 0; t < tiles.length; t++) {
				if (overflows(tiles[t])) {
					ok = false;
					break;
				}
			}
			if (ok) {
				chosen = cols;
				break;
			}
		}
		grid.setAttribute('data-bp-tiles-cols', String(chosen));
	}

	function schedule(grid) {
		if (grid.__bpTilesFrame) {
			return;
		}
		grid.__bpTilesFrame = window.requestAnimationFrame(function () {
			grid.__bpTilesFrame = 0;
			fit(grid);
		});
	}

	function brikpanelTiles(grid) {
		if (!grid || grid.nodeType !== 1 || grids.indexOf(grid) > -1) {
			return grid || null;
		}
		grids.push(grid);
		if (!grid.hasAttribute('data-bp-tiles')) {
			grid.setAttribute('data-bp-tiles', '');
		}
		fit(grid);
		if (typeof window.ResizeObserver === 'function') {
			if (!observer) {
				observer = new window.ResizeObserver(function (entries) {
					for (var i = 0; i < entries.length; i++) {
						var g = entries[i].target;
						if (g.__bpTilesWidth !== g.clientWidth) {
							g.__bpTilesWidth = g.clientWidth;
							schedule(g);
						}
					}
				});
			}
			grid.__bpTilesWidth = grid.clientWidth;
			observer.observe(grid);
		} else {
			window.addEventListener('resize', function () {
				schedule(grid);
			});
		}
		if (typeof window.MutationObserver === 'function') {
			// Values arrive by AJAX, a tile shows up or hides. The grid's own
			// attributes are what fit() writes: those must not start another fit.
			new window.MutationObserver(function (records) {
				for (var i = 0; i < records.length; i++) {
					if (!(records[i].type === 'attributes' && records[i].target === grid)) {
						schedule(grid);
						return;
					}
				}
			}).observe(grid, { childList: true, subtree: true, characterData: true, attributes: true, attributeFilter: ['hidden', 'style', 'class'] });
		}
		if (document.fonts && document.fonts.ready && document.fonts.ready.then) {
			document.fonts.ready.then(function () {
				schedule(grid);
			});
		}
		return grid;
	}

	brikpanelTiles.auto = function (root) {
		var list = (root || document).querySelectorAll('[data-bp-tiles]');
		for (var i = 0; i < list.length; i++) {
			brikpanelTiles(list[i]);
		}
	};

	brikpanelTiles.refit = function () {
		grids.forEach(function (g) {
			fit(g);
		});
	};

	window.brikpanelTiles = brikpanelTiles;

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', function () {
			brikpanelTiles.auto();
		});
	} else {
		brikpanelTiles.auto();
	}
})(window, document);
