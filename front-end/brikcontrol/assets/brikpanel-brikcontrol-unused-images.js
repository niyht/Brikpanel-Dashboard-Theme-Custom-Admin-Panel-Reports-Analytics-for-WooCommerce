/**
 * BrikPanel - Store Health: unused images card
 *
 * Backs the card the server draws (front-end/brikcontrol/views/partials/
 * unused-images-card.php): starts the check and follows it, pages through
 * the list and the trash, keeps the selection, opens the preview, and moves,
 * restores or deletes images in batches the server allows (max_ids).
 *
 * Every sentence comes from the server: finished ones in replies, counted
 * ones as plural forms (brikpanelFormat.count / format). The grid's tiles are
 * the server's markup too, for the first page and every later one, so the
 * two never drift apart.
 */
(function () {
	'use strict';

	var cfg = window.brikpanelUnusedImages;
	var card = document.querySelector('[data-unimg]');
	if (!cfg || !card || !cfg.ajax_url) {
		return;
	}

	var i18n = cfg.i18n || {};
	var F = window.brikpanelFormat || null;
	var Sheet = window.brikpanelSheet || null;
	var MAX = Math.max(1, parseInt(cfg.max_ids, 10) || 200);
	var POLL_MS = 3000;

	// ---------------------------------------------------------------- helpers

	function number(n) {
		return F ? F.number(n) : String(n);
	}

	function counted(msg, n, extra) {
		if (!F) {
			return '';
		}
		return F.format(F.plural(msg, n), [F.number(n)].concat(extra || []));
	}

	/** "820 KB", "3.4 MB", "1.2 GB": the same steps as bytes_label() in PHP. */
	function bytesLabel(bytes) {
		var b = Math.max(0, Number(bytes) || 0);
		var units = i18n.bytes || {};
		if (!F) {
			return '';
		}
		if (b >= 1073741824) {
			return F.format(units.gb, [F.number(b / 1073741824, 1, true)]);
		}
		if (b >= 1048576) {
			return F.format(units.mb, [F.number(b / 1048576, b >= 104857600 ? 0 : 1, true)]);
		}
		return F.format(units.kb, [F.number(b > 0 ? Math.max(1, Math.round(b / 1024)) : 0)]);
	}

	function post(action, data) {
		var fd = new FormData();
		fd.append('action', 'brikpanel_unused_images_' + action);
		fd.append('security', cfg.nonce);
		Object.keys(data || {}).forEach(function (key) {
			var value = data[key];
			if (Array.isArray(value)) {
				value.forEach(function (v) {
					fd.append(key + '[]', v);
				});
			} else if (value !== undefined && value !== null) {
				fd.append(key, value);
			}
		});
		return fetch(cfg.ajax_url, { method: 'POST', credentials: 'same-origin', body: fd })
			.then(function (r) {
				return r.json().catch(function () {
					return null;
				});
			})
			.then(function (json) {
				if (json && json.success) {
					return json.data || {};
				}
				var err = new Error((json && json.data && json.data.message) || i18n.failed || '');
				err.server = true;
				throw err;
			});
	}

	function setHidden(el, hidden) {
		if (el) {
			el.hidden = !!hidden;
		}
	}

	var dialog = card.querySelector('[data-unimg-dialog]');
	var statusEl = card.querySelector('[data-unimg-status]');
	var statusTimer = 0;

	/** The line under the list: what an action did, with Undo when it can be undone. */
	function status(message, undo) {
		if (!statusEl) {
			return;
		}
		window.clearTimeout(statusTimer);
		statusEl.textContent = '';
		if (!message) {
			return;
		}
		var text = document.createElement('span');
		text.textContent = message;
		statusEl.appendChild(text);
		if (typeof undo === 'function' && i18n.undo) {
			var btn = document.createElement('button');
			btn.type = 'button';
			btn.className = 'brikpanel-btn brikpanel-btn--link brikpanel-unimg-undo';
			btn.textContent = i18n.undo;
			btn.addEventListener('click', function () {
				btn.disabled = true;
				undo();
			});
			statusEl.appendChild(btn);
		}
		statusTimer = window.setTimeout(function () {
			statusEl.textContent = '';
		}, 15000);
	}

	// ------------------------------------------------------------------- scan

	var parts = {};
	Array.prototype.forEach.call(card.querySelectorAll('[data-unimg-part]'), function (el) {
		parts[el.getAttribute('data-unimg-part')] = el;
	});
	var againBtn = card.querySelector('[data-unimg-again]');
	var pollTimer = 0;

	function showState(name) {
		card.setAttribute('data-state', name);
		Object.keys(parts).forEach(function (key) {
			setHidden(parts[key], key !== name);
		});
		setHidden(againBtn, name !== 'result');
	}

	function updateProgress(p) {
		var label = card.querySelector('[data-unimg-label]');
		var pct = card.querySelector('[data-unimg-pct]');
		var bar = card.querySelector('[data-unimg-bar]');
		if (label && p.label) {
			label.textContent = p.label;
		}
		if (pct) {
			pct.textContent = p.pct_text || '';
		}
		if (bar) {
			bar.style.width = Math.min(100, Math.max(0, Number(p.pct) || 0)) + '%'; // i18n-ignore: CSS width, not text.
		}
	}

	/** The finished list is drawn by the server: back to the card, freshly loaded. */
	function reloadToCard() {
		var url = window.location.href.split('#')[0] + '#brikpanel-unused-images';
		if (window.location.href === url) {
			window.location.reload();
		} else {
			window.location.href = url;
			window.location.reload();
		}
	}

	function poll() {
		window.clearTimeout(pollTimer);
		pollTimer = window.setTimeout(function () {
			post('progress', {})
				.then(function (p) {
					if (p.running) {
						updateProgress(p);
						poll();
						return;
					}
					reloadToCard();
				})
				.catch(function () {
					poll();
				});
		}, POLL_MS);
	}

	Array.prototype.forEach.call(card.querySelectorAll('[data-unimg-start]'), function (btn) {
		btn.addEventListener('click', function () {
			var buttons = card.querySelectorAll('[data-unimg-start]');
			Array.prototype.forEach.call(buttons, function (b) {
				b.disabled = true;
			});
			updateProgress({ label: i18n.starting || '', pct: 0, pct_text: '' });
			showState('running');
			status('');
			post('start', {})
				.then(function (p) {
					if (p.running) {
						updateProgress(p);
						poll();
					} else {
						reloadToCard();
					}
				})
				.catch(function (err) {
					Array.prototype.forEach.call(buttons, function (b) {
						b.disabled = false;
					});
					showState(card.querySelector('[data-unimg-view="list"]') && card.getAttribute('data-had-result') ? 'result' : 'intro');
					status((err && err.server && err.message) || i18n.start_failed || '');
				});
		});
	});

	if (card.getAttribute('data-state') === 'running') {
		poll();
	}
	if (parts.result && !parts.result.hidden) {
		card.setAttribute('data-had-result', '1');
	}

	// ------------------------------------------------------------------ views

	var views = {};
	Array.prototype.forEach.call(card.querySelectorAll('[data-unimg-view]'), function (root) {
		var name = root.getAttribute('data-unimg-view');
		views[name] = {
			name: name,
			root: root,
			grid: root.querySelector('[data-unimg-grid]'),
			moreRow: root.querySelector('.brikpanel-unimg-more'),
			moreBtn: root.querySelector('[data-unimg-more]'),
			bar: root.querySelector('[data-unimg-selbar]'),
			count: root.querySelector('[data-unimg-count]'),
			allBtns: Array.prototype.slice.call(root.querySelectorAll('[data-unimg-all]')),
			page: 1,
			sort: 'size',
			total: parseInt(root.getAttribute('data-total'), 10) || 0,
			bytes: Number(root.getAttribute('data-bytes')) || 0,
			left: parseInt(root.getAttribute('data-left'), 10) || 0,
			loaded: name === 'list' || root.getAttribute('data-loaded') === '1',
			selected: {},
			all: false,
			busy: false,
			anchor: null
		};
	});

	function tiles(view) {
		return view.grid ? Array.prototype.slice.call(view.grid.querySelectorAll('[data-unimg-tile]')) : [];
	}

	function tileId(tile) {
		return parseInt(tile.getAttribute('data-unimg-tile'), 10) || 0;
	}

	function selectedIds(view) {
		return Object.keys(view.selected).map(function (k) {
			return parseInt(k, 10);
		});
	}

	function selectedCount(view) {
		return view.all ? view.total : selectedIds(view).length;
	}

	function selectedBytes(view) {
		var sum = 0;
		if (view.all) {
			return view.bytes;
		}
		tiles(view).forEach(function (t) {
			if (view.selected[tileId(t)]) {
				sum += Number(t.getAttribute('data-bytes')) || 0;
			}
		});
		return sum;
	}

	function syncTile(view, tile) {
		var on = view.all || !!view.selected[tileId(tile)];
		var box = tile.querySelector('input[type="checkbox"]');
		if (box) {
			box.checked = on;
		}
		tile.classList.toggle('is-selected', on);
	}

	function syncBar(view) {
		var n = selectedCount(view);
		view.root.classList.toggle('is-selecting', n > 0);
		if (view.bar) {
			setHidden(view.bar, n === 0);
		}
		if (view.count) {
			view.count.textContent = counted(i18n.selected_size, n, [bytesLabel(selectedBytes(view))]);
		}
		view.allBtns.forEach(function (btn) {
			btn.textContent = counted(i18n.select_all, view.total);
			// The one above the grid steps aside while the bar offers the same.
			var top = btn.hasAttribute('data-unimg-all-top');
			setHidden(btn, view.all || view.total === 0 || n >= view.total || (top && n > 0));
		});
	}

	function syncAll(view) {
		tiles(view).forEach(function (t) {
			syncTile(view, t);
		});
		syncBar(view);
	}

	function clearSelection(view) {
		view.selected = {};
		view.all = false;
		view.anchor = null;
		syncAll(view);
	}

	function setMore(view, left, label) {
		view.left = Math.max(0, left | 0);
		if (view.moreBtn && label) {
			view.moreBtn.textContent = label;
		}
		setHidden(view.moreRow, view.left === 0);
	}

	function loadPage(view, page, replace) {
		if (view.loading) {
			return Promise.resolve();
		}
		view.loading = true;
		if (view.moreBtn) {
			view.moreBtn.disabled = true;
		}
		return post('page', { view: view.name, sort: view.sort, page: page })
			.then(function (d) {
				if (replace) {
					view.grid.innerHTML = d.html || ''; // Server markup, escaped there (tiles_html()).
				} else {
					view.grid.insertAdjacentHTML('beforeend', d.html || '');
				}
				view.page = page;
				view.total = d.total | 0;
				view.loaded = true;
				setMore(view, d.left, d.more);
				syncAll(view);
			})
			.catch(function (err) {
				status((err && err.message) || i18n.failed || '');
			})
			.then(function () {
				view.loading = false;
				if (view.moreBtn) {
					view.moreBtn.disabled = false;
				}
			});
	}

	Object.keys(views).forEach(function (name) {
		var view = views[name];
		if (!view.grid) {
			return;
		}

		view.grid.addEventListener('change', function (e) {
			var box = e.target;
			if (!box || box.type !== 'checkbox') {
				return;
			}
			var tile = box.closest('[data-unimg-tile]');
			if (!tile) {
				return;
			}
			var id = tileId(tile);
			if (view.all) {
				// Leaving "everything" for a list of the loaded tiles minus this one.
				view.all = false;
				view.selected = {};
				tiles(view).forEach(function (t) {
					view.selected[tileId(t)] = true;
				});
			}
			var list = tiles(view);
			var from = view.anchor !== null ? list.indexOf(view.anchor) : -1;
			var to = list.indexOf(tile);
			if (view.shift && from > -1 && to > -1) {
				// Shift-click: everything between the last tile clicked and this one.
				var lo = Math.min(from, to);
				var hi = Math.max(from, to);
				for (var i = lo; i <= hi; i++) {
					if (box.checked) {
						view.selected[tileId(list[i])] = true;
					} else {
						delete view.selected[tileId(list[i])];
					}
				}
			} else if (box.checked) {
				view.selected[id] = true;
			} else {
				delete view.selected[id];
			}
			view.anchor = tile;
			syncAll(view);
		});

		view.grid.addEventListener('click', function (e) {
			view.shift = !!e.shiftKey;
			var open = e.target.closest && e.target.closest('.brikpanel-unimg-open');
			if (!open) {
				return;
			}
			var tile = open.closest('[data-unimg-tile]');
			if (tile) {
				openPreview(view, tile);
			}
		});

		if (view.moreBtn) {
			view.moreBtn.addEventListener('click', function () {
				loadPage(view, view.page + 1, false);
			});
		}
		view.allBtns.forEach(function (btn) {
			btn.addEventListener('click', function () {
				view.all = true;
				syncAll(view);
			});
		});
		var clear = view.root.querySelector('[data-unimg-clear]');
		if (clear) {
			clear.addEventListener('click', function () {
				clearSelection(view);
			});
		}
		Array.prototype.forEach.call(view.root.querySelectorAll('[data-unimg-act]'), function (btn) {
			if (btn.disabled) {
				btn.setAttribute('data-stale', '1');
			}
			btn.addEventListener('click', function () {
				var act = btn.getAttribute('data-unimg-act');
				runOnSelection(view, act);
			});
		});

		syncAll(view);
	});

	var sortSelect = card.querySelector('[data-unimg-sort]');
	if (sortSelect && views.list) {
		// The server draws the first page largest first. A browser that puts a
		// field back as it was before a reload would show another order above
		// it, so the field follows the grid.
		sortSelect.value = views.list.sort;
		sortSelect.addEventListener('change', function () {
			views.list.sort = sortSelect.value;
			loadPage(views.list, 1, true);
		});
	}

	var trashBox = card.querySelector('[data-unimg-trash]');
	if (trashBox && views.trash) {
		trashBox.addEventListener('toggle', function () {
			if (trashBox.open && !views.trash.loaded) {
				loadPage(views.trash, 1, true);
			}
		});
	}

	// ---------------------------------------------------------------- actions

	var ENDPOINT = { trash: 'trash', restore: 'restore', purge: 'delete' };

	function confirmText(act, n) {
		if (act === 'purge') {
			return counted(i18n.confirm_purge, n);
		}
		if (act === 'trash' && !cfg.trash) {
			return counted(i18n.confirm_now, n);
		}
		return '';
	}

	/** Asks before what cannot be taken back: a sheet on a phone, the browser's box elsewhere. */
	function ask(text) {
		if (!text) {
			return Promise.resolve(true);
		}
		if (Sheet && Sheet.isPhone && Sheet.isPhone() && Sheet.confirm) {
			return Sheet.confirm({ text: text, ok: i18n.delete_now || '', cancel: i18n.cancel || '', danger: true });
		}
		return Promise.resolve(window.confirm(text));
	}

	function setBusy(view, busy) {
		view.busy = busy;
		Array.prototype.forEach.call(view.root.querySelectorAll('[data-unimg-act], [data-unimg-all], [data-unimg-clear]'), function (b) {
			b.disabled = busy || b.hasAttribute('data-stale');
		});
		Array.prototype.forEach.call(dialog ? dialog.querySelectorAll('[data-unimg-d-act]') : [], function (b) {
			b.disabled = busy || b.hasAttribute('data-stale');
		});
		card.classList.toggle('is-busy', busy);
	}

	/** Take the tiles of these images off a view. */
	function dropTiles(view, ids) {
		var gone = {};
		ids.forEach(function (id) {
			gone[id] = true;
			delete view.selected[id];
		});
		tiles(view).forEach(function (t) {
			if (gone[tileId(t)]) {
				t.parentNode.removeChild(t);
			}
		});
	}

	/** The card's figures after an action: the summary, the list's size, the trash. */
	function applyFigures(d) {
		var summary = card.querySelector('[data-unimg-summary]');
		if (summary && d.summary) {
			summary.textContent = d.summary;
		}
		if (views.list) {
			var shown = tiles(views.list).length;
			views.list.total = d.count | 0;
			views.list.bytes = Number(d.bytes) || 0;
			setMore(views.list, Math.max(0, views.list.total - shown));
			setHidden(views.list.root, views.list.total === 0);
		}
		if (trashBox) {
			var title = trashBox.querySelector('[data-unimg-trash-title]');
			if (title && d.trash_title) {
				title.textContent = d.trash_title;
			}
			setHidden(trashBox, !(d.trash_count > 0));
			if (views.trash) {
				views.trash.total = d.trash_count | 0;
				views.trash.bytes = Number(d.trash_bytes) || 0;
				views.trash.loaded = false;
				if (trashBox.open) {
					loadPage(views.trash, 1, true);
				} else {
					views.trash.grid.innerHTML = '';
				}
			}
		}
	}

	/**
	 * Send an action in batches the server accepts. `ids` is a list, or null
	 * for "everything in the view": the server then takes the next batch
	 * itself, until a batch changes nothing.
	 */
	function runBatches(act, ids) {
		var done = [];
		var kept = [];
		var failed = 0;
		var last = null;
		var queue = ids ? ids.slice() : null;

		function next() {
			var data = queue ? { ids: queue.splice(0, MAX) } : { all: 1 };
			return post(ENDPOINT[act], data).then(function (d) {
				last = d;
				done = done.concat(d.done || []);
				kept = kept.concat(d.kept || []);
				failed += d.failed | 0;
				if (queue && d.rest && d.rest.length) {
					queue = d.rest.concat(queue);
				}
				var moved = (d.done || []).length + (d.kept || []).length;
				var more = queue ? queue.length > 0 : moved > 0 && (act === 'trash' ? d.count > 0 : d.trash_count > 0);
				if (more && (queue || moved > 0)) {
					return next();
				}
				return null;
			});
		}

		return next().then(function () {
			return { done: done, kept: kept, failed: failed, last: last };
		});
	}

	function finishMessage(act, outcome) {
		// The server wrote the sentence for the last batch; for several, the
		// counts are summed into the same sentence from the last reply's kind.
		return outcome.last ? outcome.last.message : '';
	}

	function runAction(view, act, ids) {
		if (view.busy) {
			return Promise.resolve(null);
		}
		var n = ids ? ids.length : view.total;
		return ask(confirmText(act, n)).then(function (ok) {
			if (!ok) {
				return null;
			}
			setBusy(view, true);
			status(i18n.working || '');
			return runBatches(act, ids)
				.then(function (outcome) {
					var target = act === 'restore' || act === 'purge' ? views.trash : views.list;
					if (target) {
						dropTiles(target, outcome.done.concat(outcome.kept));
					}
					if (act === 'restore' && views.list) {
						views.list.loaded = true;
						loadPage(views.list, 1, true);
					}
					if (outcome.last) {
						applyFigures(outcome.last);
					}
					var undo = null;
					if (act === 'trash' && cfg.trash && outcome.done.length) {
						undo = function () {
							runAction(views.trash || view, 'restore', outcome.done.slice());
						};
					}
					status(summaryOf(act, outcome), undo);
					clearSelection(view);
					return outcome;
				})
				.catch(function (err) {
					status((err && err.message) || i18n.failed || '');
					return null;
				})
				.then(function (outcome) {
					setBusy(view, false);
					return outcome;
				});
		});
	}

	/** One sentence for everything the batches did, counted here. */
	function summaryOf(act, outcome) {
		var parts2 = [];
		var forms = i18n.done || {};
		var key = act === 'trash' ? (cfg.trash ? 'trash' : 'purge') : act;
		if (forms[key]) {
			parts2.push(counted(forms[key], outcome.done.length));
		}
		if (outcome.kept.length && forms.kept) {
			parts2.push(counted(forms.kept, outcome.kept.length));
		}
		if (outcome.failed && forms.failed) {
			parts2.push(counted(forms.failed, outcome.failed));
		}
		return parts2.length ? parts2.join(' ') : finishMessage(act, outcome);
	}

	function runOnSelection(view, act) {
		var ids = view.all ? null : selectedIds(view);
		if (ids && !ids.length) {
			return;
		}
		runAction(view, act, ids);
	}

	// ---------------------------------------------------------------- preview

	var dialogImg = dialog ? dialog.querySelector('[data-unimg-d-img]') : null;
	var preview = { view: null, tile: null, handle: null, open: false };

	if (dialog && dialog.parentNode !== document.body) {
		// Out of the card, so nothing the card's box does (clipping, stacking)
		// reaches the window; its own CSS still lives under .brikpanel-unimg-dialog.
		document.body.appendChild(dialog);
	}

	if (dialog && Sheet && Sheet.attach) {
		preview.handle = Sheet.attach(dialog, {
			onClose: function () {
				preview.open = false;
				dialog.classList.remove('is-open');
				if (dialogImg) {
					dialogImg.removeAttribute('src');
				}
				// Back on the tile the preview ended on, not the one it opened from.
				var tile = preview.tile;
				window.setTimeout(function () {
					var btn = tile && tile.isConnected ? tile.querySelector('.brikpanel-unimg-open') : null;
					if (btn) {
						btn.focus({ preventScroll: false });
					}
				}, 320);
			}
		});
	}

	function fill(tile) {
		var data = function (key) {
			return tile.getAttribute('data-' + key) || '';
		};
		Array.prototype.forEach.call(dialog.querySelectorAll('[data-unimg-d]'), function (el) {
			el.textContent = data(el.getAttribute('data-unimg-d').replace(/_/g, '-'));
		});
		Array.prototype.forEach.call(dialog.querySelectorAll('[data-unimg-d-row]'), function (row) {
			setHidden(row, !data(row.getAttribute('data-unimg-d-row')));
		});
		Array.prototype.forEach.call(dialog.querySelectorAll('[data-unimg-d-link]'), function (a) {
			var url = data(a.getAttribute('data-unimg-d-link').replace(/_/g, '-'));
			if (url) {
				a.setAttribute('href', url);
			} else {
				a.removeAttribute('href');
			}
			setHidden(a, !url);
		});
		// The product a file was uploaded to, without a link when the viewer cannot edit it.
		var plain = dialog.querySelector('[data-unimg-d-plain="parent"]');
		if (plain) {
			plain.textContent = data('parent-url') ? '' : data('parent');
		}

		var missing = data('missing') === '1' || !data('full');
		setHidden(dialog.querySelector('[data-unimg-d-missing]'), !missing);
		if (dialogImg) {
			setHidden(dialogImg, missing);
			if (missing) {
				dialogImg.removeAttribute('src');
			} else {
				dialogImg.setAttribute('src', data('full'));
			}
		}

		var list = tiles(preview.view);
		var at = list.indexOf(tile);
		var pos = dialog.querySelector('[data-unimg-d-pos]');
		if (pos && F) {
			pos.textContent = F.format(i18n.position, [number(at + 1), number(preview.view.total)]);
		}
		Array.prototype.forEach.call(dialog.querySelectorAll('[data-unimg-d-only]'), function (el) {
			setHidden(el, el.getAttribute('data-unimg-d-only') !== preview.view.name);
		});
		var prev = dialog.querySelector('[data-unimg-d-nav="-1"]');
		var next = dialog.querySelector('[data-unimg-d-nav="1"]');
		if (prev) {
			prev.disabled = at <= 0;
		}
		if (next) {
			next.disabled = at >= list.length - 1 && preview.view.left === 0;
		}
	}

	function openPreview(view, tile) {
		if (!dialog) {
			return;
		}
		preview.view = view;
		preview.tile = tile;
		fill(tile);
		if (preview.open) {
			return;
		}
		preview.open = true;
		dialog.classList.add('is-open');
		if (preview.handle) {
			preview.handle.open();
		}
		// The window is drawn a frame later (hidden until then, and a hidden
		// element takes no focus): focus it once it shows, so the keys reach it.
		window.requestAnimationFrame(function () {
			window.requestAnimationFrame(function () {
				if (preview.open && !dialog.contains(document.activeElement)) {
					try {
						dialog.focus({ preventScroll: true });
					} catch (e) {
						dialog.focus();
					}
				}
			});
		});
	}

	function closePreview() {
		if (!preview.open) {
			return;
		}
		if (preview.handle) {
			preview.handle.close();
		} else {
			preview.open = false;
			dialog.classList.remove('is-open');
		}
	}

	function step(dir) {
		var list = tiles(preview.view);
		var at = list.indexOf(preview.tile);
		var to = at + dir;
		if (to >= 0 && to < list.length) {
			preview.tile = list[to];
			fill(preview.tile);
			return;
		}
		if (dir > 0 && preview.view.left > 0) {
			var view = preview.view;
			loadPage(view, view.page + 1, false).then(function () {
				var fresh = tiles(view);
				if (fresh[to]) {
					preview.tile = fresh[to];
					fill(preview.tile);
				}
			});
		}
	}

	if (dialog) {
		var closeBtn = dialog.querySelector('[data-unimg-d-close]');
		if (closeBtn) {
			closeBtn.addEventListener('click', closePreview);
		}
		Array.prototype.forEach.call(dialog.querySelectorAll('[data-unimg-d-nav]'), function (btn) {
			btn.addEventListener('click', function () {
				step(parseInt(btn.getAttribute('data-unimg-d-nav'), 10) || 0);
			});
		});
		Array.prototype.forEach.call(dialog.querySelectorAll('[data-unimg-d-act]'), function (btn) {
			if (btn.disabled) {
				btn.setAttribute('data-stale', '1');
			}
			btn.addEventListener('click', function () {
				var view = preview.view;
				var tile = preview.tile;
				if (!view || !tile) {
					return;
				}
				var list = tiles(view);
				var at = list.indexOf(tile);
				runAction(view, btn.getAttribute('data-unimg-d-act'), [tileId(tile)]).then(function (outcome) {
					if (!outcome || !preview.open) {
						return;
					}
					// On to the image that took this one's place, or done.
					var rest = tiles(view);
					if (!rest.length) {
						closePreview();
						return;
					}
					preview.tile = rest[Math.min(at, rest.length - 1)];
					fill(preview.tile);
				});
			});
		});
		// On the document: wherever the focus is while the preview is open
		// (the page behind it is inert), the arrows move through the images.
		document.addEventListener('keydown', function (e) {
			if (!preview.open || (e.key !== 'ArrowLeft' && e.key !== 'ArrowRight')) {
				return;
			}
			if (e.target && /^(INPUT|SELECT|TEXTAREA)$/.test(e.target.tagName)) {
				return;
			}
			var rtl = document.documentElement.dir === 'rtl' || document.body.classList.contains('rtl');
			var forward = (e.key === 'ArrowRight') !== rtl;
			e.preventDefault();
			step(forward ? 1 : -1);
		});
		if (!preview.handle) {
			document.addEventListener('keydown', function (e) {
				if (preview.open && (e.key === 'Escape' || e.key === 'Esc')) {
					closePreview();
				}
			});
		}
	}

	// Back from a finished check: the card, not the top of the page.
	if (window.location.hash === '#brikpanel-unused-images' && card.scrollIntoView) {
		card.scrollIntoView({ block: 'start' });
	}
})();
