/**
 * BrikPanel - bottom sheets for phones.
 *
 * On a phone (782px and narrower) a list of actions, a short form or a
 * question slides up from the bottom edge, the way a native app shows it,
 * instead of a dropdown under a small button or a window in the middle of the
 * screen. A grip on top drags it back down; the page behind is dimmed, inert
 * and does not scroll.
 *
 *   brikpanelSheet.open({ title, body, foot, headEnd, tall, onClose })
 *       A sheet built here. body / foot / headEnd: DOM nodes (or jQuery objects).
 *   brikpanelSheet.actions({ title, context: { img, title, sub }, items })
 *       A list of actions and a Cancel button. Item: { label, icon, danger, end, run }.
 *   brikpanelSheet.confirm({ title, text, ok, cancel, danger })  -> Promise<boolean>
 *   brikpanelSheet.fromMenu(menu, { title, context, trigger })
 *       The items of a "More actions" menu as an action sheet; picking one
 *       clicks the real item, so its own handlers run unchanged.
 *   brikpanelSheet.attach(el, { onRequestClose, onClose, dragHandles })
 *       An element already on the page becomes the sheet where it is: its
 *       ids, form fields and handlers stay put, only classes change.
 *       Returns { open(), close(), isOpen() }.
 *   brikpanelSheet.isPhone(), .onPhoneChange(fn), .close(), .isOpen()
 *
 * Built sheets share one element on <body>. One sheet is open at a time.
 *
 * Stacking (CLAUDE.md, ekran kuralları): scrim 100004, sheet 100005: above the
 * top bar (99999) and the screens' own drawers and dialogs (100000), below
 * tips (100010) and the WordPress media window (159900+). Not a <dialog>: the
 * browser's top layer would sit above wp.media and leave it unclickable. An
 * open sheet carries no transform, because a transform would hold the
 * position:fixed children (tips, pinned menus) inside it.
 */
(function (window, document) {
	'use strict';

	if (window.brikpanelSheet) {
		return;
	}

	var L = window.brikpanelSheetL10n || {};
	var mq = window.matchMedia ? window.matchMedia('(max-width: 782px)') : null;
	var reduceMq = window.matchMedia ? window.matchMedia('(prefers-reduced-motion: reduce)') : null;
	var CLOSE_MS = 280;
	var FOCUSABLE = 'button:not([disabled]), [href], input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';
	var X_SVG = '<svg width="20" height="20" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" aria-hidden="true" focusable="false"><path d="m5.5 5.5 9 9M14.5 5.5l-9 9"/></svg>';

	var scrim = null;
	var built = null;
	var active = null;
	var locked = null;

	function isPhone() {
		return !!(mq && mq.matches);
	}

	function reduced() {
		return !!(reduceMq && reduceMq.matches);
	}

	function onPhoneChange(fn) {
		if (!mq) {
			return;
		}
		if (mq.addEventListener) {
			mq.addEventListener('change', fn);
		} else if (mq.addListener) {
			mq.addListener(fn);
		}
	}

	function make(tag, cls, text) {
		var n = document.createElement(tag);
		if (cls) {
			n.className = cls;
		}
		if (text !== undefined && text !== null) {
			n.textContent = text;
		}
		return n;
	}

	// Our own static icon markup, or a node copied from the page. Never text
	// that came from data.
	function iconNode(icon) {
		var node = null;
		if (icon && icon.nodeType === 1) {
			node = icon.cloneNode(true);
		} else if (typeof icon === 'string' && icon.indexOf('<svg') === 0) {
			var tpl = document.createElement('template');
			tpl.innerHTML = icon;
			node = tpl.content.firstElementChild;
		}
		if (!node) {
			return null;
		}
		node.removeAttribute('width');
		node.removeAttribute('height');
		node.removeAttribute('class');
		node.setAttribute('aria-hidden', 'true');
		node.setAttribute('focusable', 'false');
		var wrap = make('span', 'brikpanel-sheet__icon');
		wrap.appendChild(node);
		return wrap;
	}

	function setContent(box, content) {
		while (box.firstChild) {
			box.removeChild(box.firstChild);
		}
		if (!content) {
			return;
		}
		if (content.jquery) {
			content = content.toArray();
		}
		if (Array.isArray(content)) {
			content.forEach(function (n) {
				if (n) {
					box.appendChild(n);
				}
			});
			return;
		}
		if (content.nodeType) {
			box.appendChild(content);
		}
	}

	// ------------------------------------------------------------ scroll lock
	// The document scrolls under the BrikPanel top bar, so the lock goes on
	// <html>. A lock on <body> clips the page and throws it back to the top.
	function lockScroll() {
		if (locked) {
			return;
		}
		locked = { x: window.pageXOffset, y: window.pageYOffset };
		document.documentElement.classList.add('brikpanel-sheet-lock');
	}

	function unlockScroll() {
		if (!locked) {
			return;
		}
		var was = locked;
		locked = null;
		document.documentElement.classList.remove('brikpanel-sheet-lock');
		if (window.pageXOffset !== was.x || window.pageYOffset !== was.y) {
			window.scrollTo(was.x, was.y);
		}
	}

	// ------------------------------------------------------------------ inert
	// Everything but the sheet: for an element deep in the page that means the
	// siblings of each of its ancestors, up to <body>. Left alone: what a
	// sheet may open on top of itself on <body> (the WordPress media window,
	// which reuses its old containers, a date picker, a select2 list, a tip).
	var KEEP_LIVE = '[data-bp-sheet-keep], .media-modal, .media-modal-backdrop, [id^="__wp-uploader"], .flatpickr-calendar, .select2-container, .brikpanel-tip, .ui-datepicker';

	function inertAround(sheetEl) {
		var list = [];
		var node = sheetEl;
		while (node && node !== document.body && node.parentNode && node.parentNode !== document) {
			var parent = node.parentNode;
			for (var c = parent.firstElementChild; c; c = c.nextElementSibling) {
				if (c === node || c === scrim || c.inert || c.matches(KEEP_LIVE)) {
					continue;
				}
				if (/^(SCRIPT|STYLE|LINK|TEMPLATE|NOSCRIPT)$/.test(c.tagName)) {
					continue;
				}
				c.inert = true;
				list.push(c);
			}
			node = parent;
		}
		return list;
	}

	function releaseInert(list) {
		(list || []).forEach(function (c) {
			c.inert = false;
		});
	}

	// ------------------------------------------------------------------ focus
	function focusables(root) {
		return Array.prototype.filter.call(root.querySelectorAll(FOCUSABLE), function (n) {
			return n.getClientRects().length > 0;
		});
	}

	function focusInto(sheetEl, initial) {
		var target = null;
		if (initial) {
			target = typeof initial === 'string' ? sheetEl.querySelector(initial) : initial;
		}
		// The sheet itself by default: focusing a field would open the phone's
		// keyboard over the sheet the moment it slides up.
		if (!target) {
			target = sheetEl;
		}
		try {
			target.focus({ preventScroll: true });
		} catch (e) {
			// Detached or not focusable: nothing to do.
		}
	}

	function onKey(e) {
		if (!active) {
			return;
		}
		if (e.key === 'Escape' || e.key === 'Esc') {
			if (document.activeElement && !active.el.contains(document.activeElement) && document.activeElement !== document.body) {
				return;
			}
			e.preventDefault();
			e.stopPropagation();
			requestClose('escape');
			return;
		}
		if (e.key !== 'Tab') {
			return;
		}
		var inSheet = active.el.contains(document.activeElement) || document.activeElement === document.body;
		if (!inSheet) {
			return;
		}
		var list = focusables(active.el);
		if (!list.length) {
			e.preventDefault();
			return;
		}
		var first = list[0];
		var last = list[list.length - 1];
		if (e.shiftKey && (document.activeElement === first || document.activeElement === active.el)) {
			e.preventDefault();
			last.focus();
		} else if (!e.shiftKey && document.activeElement === last) {
			e.preventDefault();
			first.focus();
		}
	}

	// ------------------------------------------------------------------- drag
	// The grip (and the title row) drag the sheet down; a long or quick pull
	// closes it, a short one lets it spring back.
	function bindDrag(sheetEl, handles) {
		var startY = 0;
		var lastY = 0;
		var lastT = 0;
		var speed = 0;
		var pid = null;

		function down(e) {
			if (!active || active.el !== sheetEl || pid !== null) {
				return;
			}
			if (e.button !== undefined && e.button !== 0) {
				return;
			}
			if (e.target.closest && e.target.closest('button, a, input, select, textarea, label, [role="button"]')) {
				return;
			}
			pid = e.pointerId;
			startY = lastY = e.clientY;
			lastT = e.timeStamp || Date.now();
			speed = 0;
			sheetEl.classList.add('is-sheet-dragging');
			try {
				e.currentTarget.setPointerCapture(pid);
			} catch (err) {
				// Pointer capture is a convenience.
			}
		}

		function move(e) {
			if (pid === null || e.pointerId !== pid) {
				return;
			}
			var t = e.timeStamp || Date.now();
			var dy = e.clientY - startY;
			speed = (e.clientY - lastY) / Math.max(1, t - lastT);
			lastY = e.clientY;
			lastT = t;
			// Pulling up meets resistance: the sheet is already all the way up.
			sheetEl.style.setProperty('--bp-sheet-drag', (dy > 0 ? dy : dy / 6) + 'px');
		}

		function up(e) {
			if (pid === null || (e.pointerId !== undefined && e.pointerId !== pid)) {
				return;
			}
			pid = null;
			var dy = e.clientY - startY;
			var h = sheetEl.getBoundingClientRect().height || 1;
			sheetEl.classList.remove('is-sheet-dragging');
			if (dy > Math.min(140, h * 0.3) || speed > 0.6) {
				requestClose('drag');
			} else {
				sheetEl.style.removeProperty('--bp-sheet-drag');
			}
		}

		handles.forEach(function (h) {
			if (!h || h.getAttribute('data-bp-sheet-drag') === '1') {
				return;
			}
			h.setAttribute('data-bp-sheet-drag', '1');
			h.addEventListener('pointerdown', down);
			h.addEventListener('pointermove', move);
			h.addEventListener('pointerup', up);
			h.addEventListener('pointercancel', up);
		});
	}

	// --------------------------------------------------------- show and hide
	function ensureScrim() {
		if (scrim) {
			return scrim;
		}
		scrim = make('div', 'brikpanel-sheet-scrim');
		scrim.setAttribute('aria-hidden', 'true');
		scrim.addEventListener('click', function () {
			requestClose('scrim');
		});
		document.body.appendChild(scrim);
		return scrim;
	}

	// The phone's keyboard covers the bottom of the layout viewport; the
	// visual viewport says how much. The sheet sits on top of it.
	function syncKeyboard() {
		if (!active) {
			return;
		}
		var vv = window.visualViewport;
		var kb = 0;
		if (vv) {
			kb = Math.max(0, Math.round(window.innerHeight - vv.height - vv.offsetTop));
		}
		active.el.style.setProperty('--bp-sheet-kb', kb + 'px');
		document.documentElement.classList.toggle('bp-kbd-open', kb > 80);
	}

	function watchKeyboard(on) {
		var vv = window.visualViewport;
		if (!vv) {
			return;
		}
		if (on) {
			vv.addEventListener('resize', syncKeyboard);
			vv.addEventListener('scroll', syncKeyboard);
			syncKeyboard();
		} else {
			vv.removeEventListener('resize', syncKeyboard);
			vv.removeEventListener('scroll', syncKeyboard);
			document.documentElement.classList.remove('bp-kbd-open');
		}
	}

	// Android's back gesture closes the sheet instead of leaving the page
	// (CloseWatcher, Chrome). It must be made inside the tap that opens the
	// sheet; where it is missing the back gesture navigates as before.
	function makeWatcher(a) {
		if (typeof window.CloseWatcher !== 'function') {
			return;
		}
		try {
			a.watcher = new window.CloseWatcher();
			a.watcher.onclose = function () {
				a.watcher = null;
				if (active === a) {
					requestClose('back');
				}
			};
		} catch (e) {
			a.watcher = null;
		}
	}

	function show(sheetEl, kind, opts) {
		ensureScrim();
		var a = {
			el: sheetEl,
			kind: kind,
			opts: opts || {},
			returnTo: (opts && opts.returnFocus) || document.activeElement,
			inerted: []
		};
		active = a;
		if (window.brikpanelOverflow && window.brikpanelOverflow.close) {
			window.brikpanelOverflow.close(null);
		}
		lockScroll();
		a.inerted = inertAround(sheetEl);
		sheetEl.style.removeProperty('--bp-sheet-drag');
		sheetEl.classList.remove('is-sheet-dragging');
		// Laid out in its closed place first, so the slide starts from there.
		void sheetEl.offsetWidth;
		var reveal = function () {
			if (active !== a) {
				return;
			}
			sheetEl.classList.add('is-sheet-open');
			scrim.classList.add('is-sheet-open');
		};
		if (reduced()) {
			reveal();
		} else {
			window.requestAnimationFrame(reveal);
		}
		focusInto(sheetEl, a.opts.initialFocus);
		document.addEventListener('keydown', onKey, true);
		makeWatcher(a);
		watchKeyboard(true);
	}

	function hide(a, reason) {
		if (!a || active !== a) {
			return;
		}
		active = null;
		document.removeEventListener('keydown', onKey, true);
		watchKeyboard(false);
		if (a.watcher) {
			var w = a.watcher;
			a.watcher = null;
			try {
				w.destroy();
			} catch (e) {
				// Already closed by the back gesture.
			}
		}
		a.el.classList.remove('is-sheet-open', 'is-sheet-dragging');
		if (scrim) {
			scrim.classList.remove('is-sheet-open');
		}
		releaseInert(a.inerted);
		a.inerted = [];
		unlockScroll();
		window.setTimeout(function () {
			if (active && active.el === a.el) {
				return;
			}
			a.el.style.removeProperty('--bp-sheet-drag');
			a.el.style.removeProperty('--bp-sheet-kb');
			if (a.kind === 'built') {
				setContent(built.body, null);
				setContent(built.foot, null);
				setContent(built.end, null);
			}
		}, reduced() ? 0 : CLOSE_MS + 40);
		if (typeof a.opts.onClose === 'function') {
			try {
				a.opts.onClose(reason);
			} catch (e) {
				if (window.console && window.console.error) {
					window.console.error(e);
				}
			}
		}
		if (reason !== 'replace' && a.returnTo && a.returnTo.focus && document.documentElement.contains(a.returnTo)) {
			try {
				a.returnTo.focus({ preventScroll: true });
			} catch (e) {
				// The element that opened the sheet went away: nothing to return to.
			}
		}
	}

	// Closing that the user asked for: an attached sheet's own screen decides
	// (it runs its close routine, which ends in close()).
	function requestClose(reason) {
		if (!active) {
			return;
		}
		var a = active;
		if (a.kind === 'attached' && typeof a.opts.onRequestClose === 'function') {
			if (a.opts.onRequestClose(reason) !== false && active === a) {
				hide(a, reason);
			}
			return;
		}
		hide(a, reason);
	}

	function closeNow() {
		if (active) {
			hide(active, 'replace');
		}
	}

	// ---------------------------------------------------------- built sheets
	function ensureBuilt() {
		if (built) {
			return built;
		}
		var root = make('section', 'brikpanel-sheet brikpanel-sheet--built');
		root.setAttribute('role', 'dialog');
		root.setAttribute('aria-modal', 'true');
		root.setAttribute('tabindex', '-1');
		var grip = make('div', 'brikpanel-sheet__grip');
		grip.appendChild(make('span', 'brikpanel-sheet__grip-bar'));
		var head = make('header', 'brikpanel-sheet__head');
		var title = make('h2', 'brikpanel-sheet__title');
		title.id = 'brikpanel-sheet-title';
		var end = make('div', 'brikpanel-sheet__head-end');
		var closeBtn = make('button', 'brikpanel-sheet__close');
		closeBtn.type = 'button';
		closeBtn.setAttribute('aria-label', L.close || '');
		closeBtn.innerHTML = X_SVG; // Static markup.
		closeBtn.addEventListener('click', function () {
			requestClose('button');
		});
		head.appendChild(title);
		head.appendChild(end);
		head.appendChild(closeBtn);
		var body = make('div', 'brikpanel-sheet__body');
		var foot = make('footer', 'brikpanel-sheet__foot');
		root.appendChild(grip);
		root.appendChild(head);
		root.appendChild(body);
		root.appendChild(foot);
		document.body.appendChild(root);
		built = { el: root, grip: grip, head: head, title: title, end: end, body: body, foot: foot };
		bindDrag(root, [grip, head]);
		return built;
	}

	function open(opts) {
		opts = opts || {};
		var b = ensureBuilt();
		closeNow();
		var cls = 'brikpanel-sheet brikpanel-sheet--built';
		if (opts.tall) {
			cls += ' is-tall';
		}
		if (opts.kind === 'actions') {
			cls += ' brikpanel-sheet--actions';
		}
		if (opts.className) {
			cls += ' ' + opts.className;
		}
		b.el.className = cls;
		b.title.textContent = opts.title || '';
		b.head.classList.toggle('is-untitled', !opts.title);
		if (opts.title) {
			b.el.setAttribute('aria-labelledby', b.title.id);
			b.el.removeAttribute('aria-label');
		} else {
			b.el.removeAttribute('aria-labelledby');
			if (opts.label) {
				b.el.setAttribute('aria-label', opts.label);
			} else {
				b.el.removeAttribute('aria-label');
			}
		}
		setContent(b.end, opts.headEnd || null);
		setContent(b.body, opts.body || null);
		setContent(b.foot, opts.foot || null);
		b.foot.classList.toggle('is-empty', !opts.foot);
		b.foot.classList.toggle('is-single', b.foot.children.length === 1);
		b.body.scrollTop = 0;
		show(b.el, 'built', opts);
		return {
			el: b.el,
			body: b.body,
			foot: b.foot,
			close: function () {
				if (active && active.el === b.el) {
					hide(active, 'api');
				}
			}
		};
	}

	function contextBlock(ctx) {
		var box = make('div', 'brikpanel-sheet__context');
		if (ctx.img) {
			var img = make('img', 'brikpanel-sheet__context-img');
			img.setAttribute('src', ctx.img);
			img.setAttribute('alt', '');
			box.appendChild(img);
		}
		var text = make('div', 'brikpanel-sheet__context-text');
		text.appendChild(make('span', 'brikpanel-sheet__context-title', ctx.title || ''));
		if (ctx.sub) {
			text.appendChild(make('span', 'brikpanel-sheet__context-sub', ctx.sub));
		}
		box.appendChild(text);
		return box;
	}

	function actions(opts) {
		opts = opts || {};
		var list = make('div', 'brikpanel-sheet__actions');
		if (opts.context && (opts.context.title || opts.context.img)) {
			list.appendChild(contextBlock(opts.context));
		}
		// Destructive actions go last, under a line, as on a phone's own
		// action sheets; and when some actions carry an icon, the others get
		// an empty slot so every label starts at the same place.
		var isSep = function (it) {
			return it === '-' || !!(it && it.separator);
		};
		var safe = [];
		var danger = [];
		(opts.items || []).forEach(function (it) {
			if (it) {
				(!isSep(it) && it.danger ? danger : safe).push(it);
			}
		});
		var items = danger.length ? safe.concat(['-'], danger) : safe;
		var withIcons = items.some(function (it) {
			return !isSep(it) && !!it.icon;
		});
		var lastWasSep = true;
		items.forEach(function (it) {
			if (it === '-' || it.separator) {
				if (!lastWasSep) {
					list.appendChild(make('div', 'brikpanel-sheet__sep'));
					lastWasSep = true;
				}
				return;
			}
			var b = make('button', 'brikpanel-sheet__action' + (it.danger ? ' is-danger' : ''));
			b.type = 'button';
			if (it.disabled) {
				b.disabled = true;
			}
			var ic = it.icon ? iconNode(it.icon) : null;
			if (!ic && withIcons) {
				ic = make('span', 'brikpanel-sheet__icon');
				ic.setAttribute('aria-hidden', 'true');
			}
			if (ic) {
				b.appendChild(ic);
			}
			b.appendChild(make('span', 'brikpanel-sheet__action-label', it.label || ''));
			if (it.end) {
				b.appendChild(make('span', 'brikpanel-sheet__action-end', it.end));
			}
			b.addEventListener('click', function () {
				// Closed first, the action runs inside this same tap: a link it
				// follows still counts as the user's own click (new tabs open).
				if (active) {
					hide(active, 'action');
				}
				if (typeof it.run === 'function') {
					it.run();
				}
			});
			list.appendChild(b);
			lastWasSep = false;
		});
		var last = list.lastElementChild;
		if (last && last.classList.contains('brikpanel-sheet__sep')) {
			list.removeChild(last);
		}
		var cancel = make('button', 'brikpanel-btn brikpanel-btn--secondary brikpanel-sheet__cancel', L.cancel || '');
		cancel.type = 'button';
		cancel.addEventListener('click', function () {
			requestClose('cancel');
		});
		list.appendChild(cancel);
		return open({
			kind: 'actions',
			title: opts.title || '',
			label: opts.label || (opts.context && opts.context.title) || '',
			body: list,
			returnFocus: opts.returnFocus,
			onClose: opts.onClose
		});
	}

	function confirmSheet(opts) {
		opts = opts || {};
		return new Promise(function (resolve) {
			var settled = false;
			function settle(v) {
				if (settled) {
					return;
				}
				settled = true;
				if (active && active.el === (built && built.el)) {
					hide(active, v ? 'confirm' : 'cancel');
				}
				resolve(v);
			}
			var body = make('p', 'brikpanel-sheet__text', opts.text || '');
			var no = make('button', 'brikpanel-btn brikpanel-btn--secondary brikpanel-sheet__btn', opts.cancel || L.cancel || '');
			var yes = make('button', 'brikpanel-btn ' + (opts.danger ? 'brikpanel-btn--danger' : 'brikpanel-btn--primary') + ' brikpanel-sheet__btn', opts.ok || '');
			no.type = 'button';
			yes.type = 'button';
			no.addEventListener('click', function () {
				settle(false);
			});
			yes.addEventListener('click', function () {
				settle(true);
			});
			open({
				title: opts.title || '',
				label: opts.title || opts.text || '',
				body: opts.text ? body : null,
				foot: [no, yes],
				returnFocus: opts.returnFocus,
				onClose: function () {
					if (!settled) {
						settled = true;
						resolve(false);
					}
				}
			});
		});
	}

	// The items of a "More actions" menu (front-end/shared/brikpanel-overflow.js).
	// Labels come from the item's own label span or text, the icon from its svg;
	// picking one clicks the real item.
	function fromMenu(menu, opts) {
		opts = opts || {};
		var items = [];
		var kids = menu ? menu.children : [];
		for (var i = 0; i < kids.length; i++) {
			var k = kids[i];
			if (k.matches('[role="separator"], hr, .brikpanel-pl-menu-sep, .brikpanel-sheet-sep')) {
				items.push('-');
				continue;
			}
			// A plugin's row action comes wrapped in a span: use the link or
			// button inside it.
			if (!k.matches('a, button')) {
				var inner = k.querySelector(':scope > a, :scope > button');
				if (!inner) {
					continue;
				}
				k = inner;
			}
			if (k.hidden || k.disabled || k.getAttribute('aria-hidden') === 'true' || k.hasAttribute('data-bp-sheet-skip')) {
				continue;
			}
			(function (node) {
				var labelEl = node.querySelector('.brikpanel-overflow__label');
				var label = (labelEl ? labelEl.textContent : node.textContent).replace(/\s+/g, ' ').trim();
				if (!label) {
					label = node.getAttribute('aria-label') || node.getAttribute('title') || '';
				}
				items.push({
					label: label,
					icon: node.querySelector('svg'),
					danger: node.hasAttribute('data-bp-danger') || /(^|\s)(danger|is-danger)(\s|$)/.test(node.className),
					run: function () {
						node.click();
					}
				});
			})(k);
		}
		return actions({
			title: opts.title,
			label: opts.label,
			context: opts.context,
			items: items,
			returnFocus: opts.trigger
		});
	}

	// ------------------------------------------------------ attached sheets
	function attach(host, opts) {
		opts = opts || {};
		if (!host) {
			return null;
		}
		host.classList.add('brikpanel-sheet-attached');
		if (!host.hasAttribute('tabindex')) {
			host.setAttribute('tabindex', '-1');
		}
		var grip = null;
		for (var c = host.firstElementChild; c; c = c.nextElementSibling) {
			if (c.classList.contains('brikpanel-sheet__grip')) {
				grip = c;
				break;
			}
		}
		if (!grip) {
			grip = make('div', 'brikpanel-sheet__grip');
			grip.setAttribute('aria-hidden', 'true');
			grip.appendChild(make('span', 'brikpanel-sheet__grip-bar'));
			host.insertBefore(grip, host.firstChild);
		}
		var handles = [grip];
		if (opts.dragHandles) {
			handles = handles.concat(Array.prototype.slice.call(opts.dragHandles));
		}
		bindDrag(host, handles);
		return {
			open: function () {
				if (active && active.el === host) {
					return;
				}
				closeNow();
				show(host, 'attached', {
					onRequestClose: opts.onRequestClose,
					onClose: opts.onClose,
					initialFocus: opts.initialFocus,
					returnFocus: document.activeElement
				});
			},
			close: function () {
				if (active && active.el === host) {
					hide(active, 'api');
				}
			},
			isOpen: function () {
				return !!(active && active.el === host);
			}
		};
	}

	// The next page slides in on a phone only when one of our own taps sent
	// it there (a product row, Add product, a back arrow): the head script
	// printed with this part (brikpanel_print_page_transition_script()) skips
	// every other transition, so a swipe back that the phone animates itself
	// never slides twice.
	function flagTransition(url, dir) {
		try {
			var to = new URL(url, window.location.href);
			window.sessionStorage.setItem('bpVt', JSON.stringify({
				dir: dir === 'back' ? 'back' : 'forward',
				to: to.pathname + to.search,
				ts: Date.now()
			}));
		} catch (e) {
			// No storage: the page simply loads without the slide.
		}
	}

	// Links that ask for it: <a href="…" data-bp-vt="forward|back">.
	document.addEventListener('click', function (e) {
		var a = e.target && e.target.closest ? e.target.closest('a[data-bp-vt][href]') : null;
		if (a && isPhone() && !e.defaultPrevented && !e.metaKey && !e.ctrlKey && !e.shiftKey && a.target !== '_blank') {
			flagTransition(a.href, a.getAttribute('data-bp-vt'));
		}
	});

	// A phone turned to a wider screen (or a desktop window narrowed and
	// widened again): a sheet open across the switch closes.
	onPhoneChange(function () {
		if (active && !isPhone()) {
			requestClose('resize');
		}
	});

	window.brikpanelSheet = {
		open: open,
		actions: actions,
		confirm: confirmSheet,
		fromMenu: fromMenu,
		attach: attach,
		close: function () {
			if (active) {
				requestClose('api');
			}
		},
		isOpen: function () {
			return !!active;
		},
		isPhone: isPhone,
		onPhoneChange: onPhoneChange,
		flagTransition: flagTransition
	};
})(window, document);
