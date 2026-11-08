/* BrikPanel page arrival watch. Printed inline at the top of <head> by
 * includes/brikpanel-net-watch.php (read that file's header first). Needs no
 * other file: when this runs, the rest of the page may be stuck on the way. */
(function (w, d) {
	var C = w.brikpanelNetCfg;
	if (!C || !C.k || w.top !== w.self || !w.performance || !performance.now) return;
	var ls;
	try { ls = w.localStorage; ls.getItem(C.k); } catch (e) { return; }
	var S = C.s || {}, T = C.t || 15000, id = Math.random().toString(36).slice(2, 10);

	function nav() { return (performance.getEntriesByType && performance.getEntriesByType('navigation')[0]) || null; }
	function rd() {
		try {
			var o = JSON.parse(ls.getItem(C.k) || 'null');
			return o && o.s && o.s.push ? o : { v: 1, s: [] };
		} catch (e) { return { v: 1, s: [] }; }
	}
	function wr(o) {
		try {
			if (o.s.length > 40) o.s = o.s.slice(-40);
			ls.setItem(C.k, JSON.stringify(o));
		} catch (e) { /* storage full or blocked */ }
	}
	function put(f, rid) {
		var o = rd(), i, k;
		for (i = o.s.length - 1; i >= 0; i--) {
			if (o.s[i].i === (rid || id)) {
				for (k in f) o.s[i][k] = f[k];
				break;
			}
		}
		wr(o);
	}
	function secs(ms) {
		var v = Math.round(ms / 100) / 10;
		v = v >= 10 ? String(Math.round(v)) : String(v).replace('.', C.dec || '.');
		return String(S.sec).replace('%s', v);
	}
	function fmt(s, a, b) { return String(s || '').replace('%1$s', a).replace('%2$s', b).replace('%s', a); }
	function el(tag, cls, text) {
		var e = d.createElement(tag);
		if (cls) e.className = cls;
		if (text) e.textContent = text;
		return e;
	}

	var db = rd();
	if (C.login && !db.s.length) return; // on the login page, only in a browser that already used the admin
	var last = db.s.length ? db.s[db.s.length - 1] : null;
	var prev = last && last.w && !last.c && !last.x && Date.now() / 1000 - last.t < 900 ? last : null;
	var srvs = [], k;
	for (k = 0; k < db.s.length; k++) if (db.s[k].d && db.s[k].srv > 0) srvs.push(db.s[k].srv);
	srvs = srvs.slice(-10).sort(function (x, y) { return x - y; });
	var srvMed = srvs.length ? srvs[Math.floor(srvs.length / 2)] : null;
	db.s.push({ i: id, t: Math.round(Date.now() / 1000), p: C.p || '' });
	wr(db);

	function proto() {
		var n = nav();
		if (n && n.nextHopProtocol) return n.nextHopProtocol;
		for (var j = db.s.length - 1; j >= 0; j--) if (db.s[j].pr) return db.s[j].pr;
		return '';
	}
	function flagStep(list) {
		var ua = navigator.userAgent || '', addr = null;
		if (/Firefox\//.test(ua)) { list.appendChild(el('li', '', S.stepFirefox)); return; }
		if (/Edg\//.test(ua)) addr = 'edge://flags/#enable-quic';
		else if (/OPR\//.test(ua)) addr = 'opera://flags/#enable-quic';
		else if (/YaBrowser\//.test(ua)) addr = 'browser://flags/#enable-quic';
		else if (navigator.brave) addr = 'brave://flags/#enable-quic';
		else if (/Chrome\//.test(ua)) addr = 'chrome://flags/#enable-quic';
		if (!addr) return; // Safari has no switch for it
		var li = el('li'), parts = String(S.stepBrowser).split('%s'), b = el('button', 'bp-netw__copy', S.copy);
		li.appendChild(d.createTextNode(parts[0] || ''));
		li.appendChild(el('code', '', addr));
		b.type = 'button';
		b.onclick = function () {
			try { navigator.clipboard.writeText(addr).then(function () { b.textContent = S.copied; }, function () {}); } catch (e) { /* no clipboard */ }
		};
		li.appendChild(b);
		li.appendChild(d.createTextNode(parts[1] || ''));
		list.appendChild(li);
	}

	var box = null, head = null, shownAt = 0, title0 = null, visible = 0, ready = d.readyState !== 'loading', leaving = false, tick;
	// Firefox finishes a page it is leaving: it cancels the stuck file, reads
	// the rest and fires DOMContentLoaded and load. Such a page did not arrive.
	function onLeave() { leaving = true; }
	// mode: 'stall' (still waiting), 'arrived' (came after a stall), 'prev' (the last page never came).
	function show(mode, ms) {
		var line = mode === 'stall' ? fmt(S.title, secs(ms)) : mode === 'arrived' ? fmt(S.arrived, secs(ms)) : fmt(S.prevStuck, secs(ms));
		if (box && mode === 'stall') { head.textContent = line; return; }
		var now = mode === 'arrived' && typeof w.brikpanelNetSrv === 'number' ? w.brikpanelNetSrv : null;
		var slow = false, cause;
		if (now !== null) {
			slow = now >= ms / 2;
			cause = fmt(slow ? S.arrivedSlow : S.arrivedFast, secs(now));
		} else if (srvMed !== null) {
			slow = srvMed >= ms / 2;
			cause = fmt(slow ? S.slowServer : S.fastServer, secs(srvMed));
		} else {
			cause = S.unknown;
		}
		if (C.css && !d.getElementById('bp-netw-css')) {
			var st = el('style');
			st.id = 'bp-netw-css';
			st.textContent = C.css;
			(d.head || d.documentElement).appendChild(st);
		}
		var keepOpen = box && box.querySelector('details[open]');
		if (!box) {
			box = el('div', 'bp-netw');
			box.setAttribute('role', 'status');
			box.setAttribute('aria-live', 'polite');
		}
		box.textContent = '';
		var card = el('div', 'bp-netw__card'), x = el('button', 'bp-netw__x', '×');
		x.type = 'button';
		x.setAttribute('aria-label', String(S.close));
		x.onclick = function () { box.hidden = true; };
		head = el('p', 'bp-netw__title', line);
		card.appendChild(x);
		card.appendChild(head);
		card.appendChild(el('p', 'bp-netw__text', cause));
		var h3 = !slow && proto() === 'h3', more = el('details', 'bp-netw__more'), list = el('ol', 'bp-netw__steps');
		if (h3) card.appendChild(el('p', 'bp-netw__text', S.h3));
		more.appendChild(el('summary', '', S.stepsTitle));
		if (slow) {
			list.appendChild(el('li', '', S.stepSlow));
		} else {
			if (h3) {
				list.appendChild(el('li', '', S.stepHostH3));
				flagStep(list);
			}
			list.appendChild(el('li', '', S.stepAntivirus));
			list.appendChild(el('li', '', S.stepNetwork));
			if (!h3) list.appendChild(el('li', '', S.stepHost));
		}
		more.appendChild(list);
		if (keepOpen) more.open = true;
		card.appendChild(more);
		box.appendChild(card);
		box.hidden = false;
		if (!box.parentNode) (d.body || d.documentElement).appendChild(box);
	}

	// Stuck: the page is still being read after T ms on screen. `load` would
	// also wait for every image and frame, so it does not decide.
	tick = setInterval(function () {
		if (d.visibilityState === 'visible' && !d.prerendering) visible += 1000;
		if (ready) { clearInterval(tick); return; }
		if (visible < T) return;
		var ms = Math.round(performance.now());
		if (!shownAt) {
			shownAt = ms;
			title0 = d.title;
			w.addEventListener('beforeunload', onLeave);
		}
		put({ w: Math.round(ms / 1000) });
		d.title = fmt(S.tabTitle, secs(ms));
		if (d.body) show('stall', ms);
	}, 1000);

	d.addEventListener('DOMContentLoaded', function () {
		ready = true;
		clearInterval(tick);
		if (leaving) return;
		w.removeEventListener('beforeunload', onLeave);
		var n = nav();
		put({ c: 1, dcl: n ? Math.round(n.domContentLoadedEventStart) : Math.round(performance.now()) });
		if (title0 !== null) d.title = title0;
		if (shownAt) {
			show('arrived', Math.round(performance.now()));
		} else if (prev) {
			put({ x: 1 }, prev.i);
			setTimeout(function () {
				if (d.querySelector('dialog[open]')) return;
				show('prev', prev.w * 1000);
				// It sits over the page's own header: away after a while, unless it is being read.
				setTimeout(function hide() {
					if (!box || box.hidden) return;
					if (box.querySelector('details[open]') || box.matches(':hover') || box.contains(d.activeElement)) {
						setTimeout(hide, 5000);
						return;
					}
					box.hidden = true;
				}, 15000);
			}, 1200);
		}
	});

	w.addEventListener('load', function () {
		if (leaving) return;
		var n = nav(), f = { d: 1 };
		if (n) {
			f.ttfb = Math.round(n.responseStart - n.requestStart);
			f.dl = Math.round(n.responseEnd - n.responseStart);
			f.ld = Math.round(n.loadEventStart);
			f.pr = n.nextHopProtocol || '';
		}
		if (typeof w.brikpanelNetSrv === 'number') f.srv = Math.round(w.brikpanelNetSrv);
		put(f);
		if (shownAt && box && !box.hidden && !box.querySelector('details[open]')) {
			setTimeout(function () { if (box && !box.querySelector('details[open]')) box.hidden = true; }, d.querySelector('dialog[open]') ? 0 : 8000);
		}
	});
	w.addEventListener('pagehide', function () { if (box) box.hidden = true; });
}(window, document));
