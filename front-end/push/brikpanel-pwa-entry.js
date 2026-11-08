/* BrikPanel phone app entrance, printed inline on that page only by
 * front-end/push/brikpanel-pwa-entry.php (read its header first).
 *
 * A login address this phone remembers (kept by the login page inside the app)
 * is opened straight away, with the admin page to come back to. When the last
 * two tries within a quarter of an hour did not reach a login form (the
 * address changed), the page stays and the address box opens instead, filled
 * in, so the app never loops into a dead page. An address typed in the box
 * must be on this site; it is remembered for next time. */
(function (w, d) {
	var C = w.brikpanelEntry;
	if (!C || !C.keys) return;
	var K = C.keys, ls = null;
	try {
		ls = w.localStorage;
		ls.getItem(K.login);
	} catch (e) {
		ls = null;
	}
	var btn = d.getElementById('bp-entry-login');
	var form = d.getElementById('bp-entry-form');
	var input = d.getElementById('bp-entry-url');
	var err = d.getElementById('bp-entry-error');
	var other = d.getElementById('bp-entry-other');

	// An empty value would read as this site's home page: it is no address.
	function onSite(raw) {
		raw = String(raw || '').trim();
		if (!raw) return null;
		try {
			var u = new URL(raw, location.origin);
			if (u.origin !== location.origin || !/^https?:$/.test(u.protocol)) return null;
			u.hash = '';
			return u;
		} catch (e) {
			return null;
		}
	}
	// What people type: a full address, a path, "shop.example.com/login" or a bare "login".
	function fromTyped(raw) {
		raw = String(raw || '').trim();
		if (!raw) return null;
		if (/^[a-z][a-z0-9+.-]*:/i.test(raw)) return onSite(raw);
		if (raw.charAt(0) === '/') return onSite(raw);
		var head = raw.split('/')[0];
		if (head.indexOf('.') !== -1) return onSite(location.protocol + '//' + raw);
		return onSite('/' + raw);
	}
	function withTarget(u) {
		var v = new URL(u.href);
		if (C.target && !v.searchParams.has('redirect_to')) v.searchParams.set('redirect_to', C.target);
		return v.href;
	}
	function store(key, value) {
		try {
			if (!ls) return;
			if (value === null) ls.removeItem(key);
			else ls.setItem(key, value);
		} catch (e) { /* storage full or blocked */ }
	}

	var saved = ls ? onSite(ls.getItem(K.login)) : null;
	if (saved) {
		if (btn) btn.href = withTarget(saved);
		if (input) input.value = saved.href;
		var t = null;
		try {
			t = JSON.parse(ls.getItem(K.tries) || 'null');
		} catch (e) {
			t = null;
		}
		var now = Date.now();
		var recent = !!(t && typeof t.t === 'number' && now - t.t < 9e5);
		var n = recent ? (t.n | 0) : 0;
		if (n < 2) {
			store(K.tries, JSON.stringify({ t: recent ? t.t : now, n: n + 1 }));
			location.replace(withTarget(saved));
			return;
		}
		if (other) other.open = true;
	}

	if (form && input) {
		form.addEventListener('submit', function (ev) {
			ev.preventDefault();
			var u = fromTyped(input.value);
			if (!u) {
				if (err) err.hidden = false;
				input.setAttribute('aria-invalid', 'true');
				input.focus();
				return;
			}
			if (err) err.hidden = true;
			input.removeAttribute('aria-invalid');
			store(K.login, u.href);
			store(K.tries, null);
			location.href = withTarget(u);
		});
		input.addEventListener('input', function () {
			if (err && !err.hidden) {
				err.hidden = true;
				input.removeAttribute('aria-invalid');
			}
		});
	}
}(window, document));
