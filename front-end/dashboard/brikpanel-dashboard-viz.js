/**
 * BrikPanel dashboard: the hand-drawn charts of the redesigned cards (October
 * 2026). Plain SVG, no chart library: the sales line with its previous period,
 * the funnel ribbon, the 100 squares of Order rates, the dot strips of the
 * Visitors and Customers cards, today's hourly sales bars and the small lines
 * in the store cards.
 *
 * This file only draws and handles pointer, touch and keyboard. It holds no
 * text: every label, number and tooltip line comes from the caller
 * (brikpanel-dashboard.js), already translated and formatted with
 * window.brikpanelFormat.
 *
 * Colours come from classes (brikpanel-dashboard.css), never from fill or
 * stroke attributes, so the palette lives in one place and RTL, print and
 * forced colours can restyle it.
 *
 * Right-to-left: time and the funnel run from the right edge; text is never
 * mirrored. Reduced motion: nothing animates.
 *
 * API: window.brikpanelDashViz.{ mono, niceMax, per100, smooth, spark, dots,
 * waffle, flow, line, bars, tip, indexer, tabs, anim, countUp, onView,
 * inView, watchWidth, reduced, isRtl, esc }.
 */
(function (window, document) {
    'use strict';

    if (window.brikpanelDashViz) {
        return;
    }

    var EASE = 'cubic-bezier(.4, 0, .2, 1)';
    var EASE_OUT = 'cubic-bezier(.22, 1, .36, 1)';
    var EDGE = 12; // px kept free at the screen edge by the data tooltip
    var GAP = 10;  // px between the tooltip and what it points at
    var uid = 0;

    var reducedMq = window.matchMedia ? window.matchMedia('(prefers-reduced-motion: reduce)') : null;

    function reduced() {
        return !!(reducedMq && reducedMq.matches);
    }

    function isRtl(el) {
        var node = el || document.documentElement;
        try {
            return window.getComputedStyle(node).direction === 'rtl';
        } catch (e) {
            return document.documentElement.dir === 'rtl';
        }
    }

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function f2(n) {
        return (Math.round(n * 100) / 100).toString();
    }

    function nextId(prefix) {
        uid += 1;
        return prefix + uid;
    }

    /* ------------------------------------------------------------ geometry */

    // Monotone cubic through the points: smooth, and it never overshoots the
    // data (a quiet day never dips below zero between two busy ones).
    function mono(p) {
        var n = p.length;
        if (!n) {
            return '';
        }
        if (n === 1) {
            return 'M' + f2(p[0][0]) + ',' + f2(p[0][1]);
        }
        var m = [];
        var t = [];
        var i;
        for (i = 0; i < n - 1; i++) {
            var dx = p[i + 1][0] - p[i][0];
            m[i] = dx === 0 ? 0 : (p[i + 1][1] - p[i][1]) / dx;
        }
        t[0] = m[0];
        t[n - 1] = m[n - 2];
        for (i = 1; i < n - 1; i++) {
            t[i] = m[i - 1] * m[i] <= 0 ? 0 : (m[i - 1] + m[i]) / 2;
        }
        for (i = 0; i < n - 1; i++) {
            if (m[i] === 0) {
                t[i] = 0;
                t[i + 1] = 0;
                continue;
            }
            var a = t[i] / m[i];
            var b = t[i + 1] / m[i];
            var s = a * a + b * b;
            if (s > 9) {
                var k = 3 / Math.sqrt(s);
                t[i] = k * a * m[i];
                t[i + 1] = k * b * m[i];
            }
        }
        var d = 'M' + f2(p[0][0]) + ',' + f2(p[0][1]);
        for (i = 0; i < n - 1; i++) {
            var h = (p[i + 1][0] - p[i][0]) / 3;
            d += ' C' + f2(p[i][0] + h) + ',' + f2(p[i][1] + t[i] * h) + ' ' +
                f2(p[i + 1][0] - h) + ',' + f2(p[i + 1][1] - t[i + 1] * h) + ' ' +
                f2(p[i + 1][0]) + ',' + f2(p[i + 1][1]);
        }
        return d;
    }

    // A round top for an axis with four steps (0, 1/4, 1/2, 3/4, top).
    function niceMax(v) {
        if (!(v > 0)) {
            return 4;
        }
        var raw = v / 4;
        var p = Math.pow(10, Math.floor(Math.log(raw) / Math.LN10));
        var f = raw / p;
        return (f <= 1 ? 1 : f <= 2 ? 2 : f <= 2.5 ? 2.5 : f <= 5 ? 5 : 10) * p * 4;
    }

    // Shares of 100 by the largest remainder, so the parts always add up to
    // 100; a group with anything in it keeps at least one.
    function per100(counts) {
        var total = 0;
        counts.forEach(function (c) { total += c > 0 ? c : 0; });
        var raw = counts.map(function (c) { return total ? Math.max(0, c) / total * 100 : 0; });
        var out = raw.map(Math.floor);
        var rest = 0;
        if (total) {
            rest = 100;
            out.forEach(function (x) { rest -= x; });
        }
        raw.map(function (r, i) { return [r - out[i], i]; })
            .sort(function (a, b) { return b[0] - a[0]; })
            .forEach(function (pair) {
                if (rest > 0) {
                    out[pair[1]]++;
                    rest--;
                }
            });
        counts.forEach(function (c, i) {
            if (c > 0 && out[i] === 0) {
                var big = out.indexOf(Math.max.apply(null, out));
                out[big]--;
                out[i] = 1;
            }
        });
        return out;
    }

    // Moving average over k points on each side; nulls stay null.
    function smooth(arr, k) {
        if (!k) {
            return arr.slice();
        }
        return arr.map(function (v, i) {
            if (v === null) {
                return null;
            }
            var t = 0;
            var c = 0;
            for (var j = Math.max(0, i - k); j <= Math.min(arr.length - 1, i + k); j++) {
                if (arr[j] !== null) {
                    t += arr[j];
                    c++;
                }
            }
            return c ? t / c : null;
        });
    }

    /* ------------------------------------------------------------ small parts */

    // The store cards' line for the period: decorative (the figure beside it
    // says the number), so it is hidden from screen readers.
    function spark(vals, w, h, rtl) {
        var pts = [];
        vals.forEach(function (v, i) {
            if (v !== null && isFinite(v)) {
                pts.push([i, v]);
            }
        });
        if (pts.length < 2 || !(w > 0)) {
            return '';
        }
        var max = -Infinity;
        var min = Infinity;
        pts.forEach(function (p) {
            max = Math.max(max, p[1]);
            min = Math.min(min, p[1]);
        });
        var span = max - min || 1;
        var n = vals.length - 1 || 1;
        var xy = pts.map(function (p) {
            var x = p[0] / n * (w - 4) + 2;
            return [rtl ? w - x : x, h - 2 - (p[1] - min) / span * (h - 6)];
        });
        var d = mono(xy);
        var last = xy[xy.length - 1];
        var first = xy[0];
        return '<svg class="bp-dv-spark-svg" width="' + f2(w) + '" height="' + f2(h) + '" viewBox="0 0 ' + f2(w) + ' ' + f2(h) + '" aria-hidden="true" focusable="false">' +
            '<path class="bp-dv-spark-area" d="' + d + ' L' + f2(last[0]) + ',' + f2(h) + ' L' + f2(first[0]) + ',' + f2(h) + ' Z"/>' +
            '<path class="bp-dv-spark-line" d="' + d + '"/>' +
            '<circle class="bp-dv-spark-end" cx="' + f2(last[0]) + '" cy="' + f2(last[1]) + '" r="2.6"/>' +
            '</svg>';
    }

    // 100 dots in two rows, filled column by column so each group reads from
    // the start edge. groups: [{ key, n, cls }].
    function dots(groups, width, rtl) {
        var cols = 50;
        var rows = 2;
        var p = width / cols;
        var r = Math.min(4.4, Math.max(1.6, p * 0.36));
        var alloc = per100(groups.map(function (g) { return g.n; }));
        var k = 0;
        var out = '';
        groups.forEach(function (g, gi) {
            for (var j = 0; j < alloc[gi]; j++, k++) {
                var c = Math.floor(k / rows);
                var rw = k % rows;
                var cx = (c + 0.5) * p;
                out += '<circle class="bp-dv-dot ' + esc(g.cls) + '" cx="' + f2(rtl ? width - cx : cx) + '" cy="' + f2((rw + 0.5) * p) + '" r="' + f2(r) + '" data-k="' + esc(g.key) + '" data-n="' + k + '"/>';
            }
        });
        return '<svg class="bp-dv-dots" width="' + f2(width) + '" height="' + f2(p * rows) + '" viewBox="0 0 ' + f2(width) + ' ' + f2(p * rows) + '" aria-hidden="true" focusable="false">' + out + '</svg>';
    }

    // Ten by ten squares, one per hundredth, filled row by row from the
    // start corner. groups: [{ key, n, cls }]. label: what a screen reader hears.
    function waffle(groups, size, label, rtl) {
        var alloc = per100(groups.map(function (g) { return g.n; }));
        var p = size / 10;
        var sq = p - 3;
        var k = 0;
        var out = '';
        groups.forEach(function (g, gi) {
            for (var j = 0; j < alloc[gi]; j++, k++) {
                var col = k % 10;
                var x = (rtl ? 9 - col : col) * p + 1.5;
                out += '<rect class="bp-dv-sq ' + esc(g.cls) + '" data-k="' + esc(g.key) + '" data-n="' + k + '" x="' + f2(x) + '" y="' + f2(Math.floor(k / 10) * p + 1.5) + '" width="' + f2(sq) + '" height="' + f2(sq) + '" rx="2.5"/>';
            }
        });
        return '<svg class="bp-dv-waffle" width="' + f2(size) + '" height="' + f2(size) + '" viewBox="0 0 ' + f2(size) + ' ' + f2(size) + '" role="img" aria-label="' + esc(label) + '">' + out + '</svg>';
    }

    /* ------------------------------------------------------------ tooltip */

    // One floating tooltip for the charts, where the shared help bubble
    // (front-end/shared/brikpanel-tip.js, one bubble per trigger) cannot
    // follow a pointer along a line. Placed like the shared one: never past
    // the screen edge, above what it points at, below when there is no room.
    var tipEl = null;

    function tipNode() {
        if (!tipEl) {
            tipEl = document.createElement('div');
            tipEl.className = 'bp-dv-tip bp-dv-tipbox';
            tipEl.setAttribute('role', 'tooltip');
            tipEl.hidden = true;
            document.body.appendChild(tipEl);
        }
        return tipEl;
    }

    // { title, body, rows: [[label, value]], note } as text nodes only.
    function fillTip(node, t) {
        node.textContent = '';
        if (!t) {
            return;
        }
        if (t.title) {
            var head = document.createElement('div');
            head.className = 'bp-dv-tip-t';
            head.textContent = t.title;
            node.appendChild(head);
        }
        if (t.body) {
            var body = document.createElement('p');
            body.className = 'bp-dv-tip-p';
            body.textContent = t.body;
            node.appendChild(body);
        }
        (t.rows || []).forEach(function (row) {
            var line = document.createElement('div');
            line.className = 'bp-dv-tip-r';
            var a = document.createElement('span');
            a.textContent = row[0];
            var b = document.createElement('b');
            b.textContent = row[1];
            // A price or a percentage keeps its own reading order in RTL.
            var bdi = document.createElement('bdi');
            bdi.appendChild(b);
            line.appendChild(a);
            line.appendChild(bdi);
            node.appendChild(line);
        });
        if (t.note) {
            var note = document.createElement('p');
            note.className = 'bp-dv-tip-p bp-dv-tip-n';
            note.textContent = t.note;
            node.appendChild(note);
        }
    }

    // The bottom of the fixed bars at the top of the screen (WordPress admin
    // bar, BrikPanel top bar), so the tooltip never opens under them.
    function topLimit() {
        var limit = 0;
        ['wpadminbar', 'brikpanel-topbar'].forEach(function (id) {
            var el = document.getElementById(id);
            if (el && el.offsetParent !== null) {
                var r = el.getBoundingClientRect();
                if (r.bottom > limit && r.top <= 0) {
                    limit = r.bottom;
                }
            }
        });
        return limit + 4;
    }

    function placeTip(r) {
        var node = tipNode();
        node.style.maxWidth = Math.max(160, Math.min(300, window.innerWidth - EDGE * 2)) + 'px';
        var w = node.offsetWidth;
        var h = node.offsetHeight;
        var vw = window.innerWidth;
        var vh = window.innerHeight;
        var x = Math.max(EDGE, Math.min(vw - w - EDGE, r.left + r.width / 2 - w / 2));
        var y = r.top - h - GAP;
        if (y < topLimit()) {
            y = Math.min(vh - h - EDGE, r.bottom + GAP);
        }
        node.style.transform = 'translate(' + Math.round(x) + 'px, ' + Math.round(Math.max(EDGE, y)) + 'px)';
    }

    var tip = {
        show: function (t, rect) {
            var node = tipNode();
            fillTip(node, t);
            node.hidden = false;
            placeTip(rect);
        },
        hide: function () {
            if (tipEl) {
                tipEl.hidden = true;
            }
        },
        fill: fillTip
    };

    /* ------------------------------------------------------------ pointer + keyboard */

    // Moves an index along a chart with the pointer (hover, or a finger
    // dragged sideways), the arrow keys (reversed in RTL), Home and End.
    // opts: { count, indexAt(clientX) → i, onIndex(i, how), onLeave(),
    // rtl, live: an aria-live node the keyboard steps are read into }.
    function indexer(el, opts) {
        var current = -1;
        var pinned = false;
        var lastHow = '';
        // The chart under it is redrawn (new range, new width): read the
        // number of points and its RTL state each time.
        var count = function () { return typeof opts.count === 'function' ? opts.count() : opts.count; };
        var rtlNow = function () { return typeof opts.rtl === 'function' ? opts.rtl() : !!opts.rtl; };

        function set(i, how) {
            i = Math.max(0, Math.min(count() - 1, i));
            current = i;
            lastHow = how;
            opts.onIndex(i, how);
        }

        function leave() {
            current = -1;
            pinned = false;
            opts.onLeave();
        }

        el.addEventListener('pointermove', function (e) {
            if (e.pointerType === 'touch' && !pinned) {
                return;
            }
            set(opts.indexAt(e.clientX), 'pointer');
        });
        el.addEventListener('pointerdown', function (e) {
            if (e.pointerType === 'touch' || e.pointerType === 'pen') {
                pinned = true;
                set(opts.indexAt(e.clientX), 'touch');
            }
        });
        el.addEventListener('pointerleave', function (e) {
            if (e.pointerType === 'mouse' && lastHow !== 'key') {
                leave();
            }
        });
        el.addEventListener('focus', function () {
            if (current < 0) {
                set(count() - 1, 'key');
            }
        });
        el.addEventListener('blur', function () {
            if (lastHow === 'key') {
                leave();
            }
        });
        el.addEventListener('keydown', function (e) {
            var step = 0;
            if (e.key === 'ArrowRight') {
                step = rtlNow() ? -1 : 1;
            } else if (e.key === 'ArrowLeft') {
                step = rtlNow() ? 1 : -1;
            } else if (e.key === 'Home') {
                e.preventDefault();
                set(0, 'key');
                return;
            } else if (e.key === 'End') {
                e.preventDefault();
                set(count() - 1, 'key');
                return;
            } else if (e.key === 'Escape') {
                leave();
                return;
            }
            if (step) {
                e.preventDefault();
                set((current < 0 ? count() - 1 : current) + step, 'key');
            }
        });
        // A tap anywhere else lets go of a pinned point. Opened by the
        // pointer, the point closes on scroll; opened by the keyboard it stays
        // and is placed again where the chart moved to. A chart drawn again
        // in a new box (the live card's hours) leaves its old box behind:
        // these page listeners remove themselves once their box is gone.
        var onDocDown = function (e) {
            if (!el.isConnected) {
                detach();
                return;
            }
            if (pinned && !el.contains(e.target)) {
                leave();
            }
        };
        var onScroll = function () {
            if (!el.isConnected) {
                detach();
                return;
            }
            if (current < 0) {
                return;
            }
            if (lastHow === 'key') {
                opts.onIndex(current, 'key');
            } else {
                leave();
            }
        };
        var detach = function () {
            document.removeEventListener('pointerdown', onDocDown, true);
            window.removeEventListener('scroll', onScroll);
        };
        document.addEventListener('pointerdown', onDocDown, true);
        window.addEventListener('scroll', onScroll, { passive: true });

        return { leave: leave, current: function () { return current; } };
    }

    /* ------------------------------------------------------------ sales line */

    // The sales chart: one axis, the current period as a line with a soft
    // area under it, the previous period dashed, the part of today not over
    // yet dotted. cfg: { cur: [v|null], prev: [v|null], partialFrom: i|null,
    // max (optional), xLabel(i), yLabel(v), tipAt(i) → tooltip, ariaLabel,
    // live (aria-live node), step (axis rounding: 'int' for whole numbers) }.
    function line(box, cfg) {
        var rtl = isRtl(box);
        var W = Math.max(240, box.clientWidth);
        var H = Math.max(220, box.clientHeight || 0);
        var n = cfg.cur.length;
        var all = cfg.cur.concat(cfg.prev || []).filter(function (v) { return v !== null && isFinite(v); });
        var max = niceMax(Math.max.apply(null, [1].concat(all)));
        if (cfg.step === 'int') {
            max = Math.max(4, Math.ceil(max / 4) * 4);
        }
        // Room for the longest axis label (about 6.5px a character at 11px).
        var longest = 0;
        for (var g = 0; g <= 4; g++) {
            longest = Math.max(longest, String(cfg.yLabel(max / 4 * g)).length);
        }
        var axisW = Math.max(30, Math.min(96, Math.round(longest * 6.6 + 12)));
        var pad = 10;
        var T = 8;
        var B = 26;
        var pw = W - axisW - pad;
        var ph = H - T - B;
        var x0 = rtl ? pad : axisW;
        var X = function (i) {
            var off = n === 1 ? pw / 2 : i / (n - 1) * pw;
            return rtl ? x0 + pw - off : x0 + off;
        };
        var Y = function (v) { return T + ph - v / max * ph; };
        var pts = function (arr) {
            var out = [];
            arr.forEach(function (v, i) {
                if (v !== null && isFinite(v)) {
                    out.push([X(i), Y(v)]);
                }
            });
            return out;
        };
        var cp = pts(cfg.cur);
        var pp = pts(cfg.prev || []);
        var partial = cfg.partialFrom !== null && cfg.partialFrom !== undefined && cp.length > 2 && cfg.partialFrom === cp.length - 1;
        var solid = partial ? cp.slice(0, -1) : cp;
        var lineD = mono(solid);
        var partD = partial ? 'M' + f2(cp[cp.length - 2][0]) + ',' + f2(cp[cp.length - 2][1]) + ' L' + f2(cp[cp.length - 1][0]) + ',' + f2(cp[cp.length - 1][1]) : '';
        var fullD = mono(cp);
        var areaD = cp.length ? fullD + ' L' + f2(cp[cp.length - 1][0]) + ',' + f2(T + ph) + ' L' + f2(cp[0][0]) + ',' + f2(T + ph) + ' Z' : '';

        var grid = '';
        for (g = 0; g <= 4; g++) {
            var v = max / 4 * g;
            var y = Y(v);
            grid += '<line class="bp-dv-ch-grid" x1="' + f2(x0) + '" x2="' + f2(x0 + pw) + '" y1="' + f2(y) + '" y2="' + f2(y) + '"/>' +
                '<text class="bp-dv-ch-axis" x="' + f2(rtl ? x0 + pw + 8 : x0 - 8) + '" y="' + f2(y + 3.5) + '" text-anchor="end">' + esc(cfg.yLabel(v)) + '</text>';
        }

        // Day labels at least 64px apart, on a round step.
        var need = n > 1 ? 64 * (n - 1) / pw : 1;
        var steps = cfg.hourly ? [1, 2, 3, 6, 12] : [1, 2, 3, 5, 7, 10, 14, 15, 30, 60, 90, 180, 365];
        var every = steps[steps.length - 1];
        for (var s = 0; s < steps.length; s++) {
            if (steps[s] >= need) {
                every = steps[s];
                break;
            }
        }
        var xl = '';
        for (var i = 0; i < n; i++) {
            if (i % every === 0) {
                xl += '<text class="bp-dv-ch-axis" x="' + f2(X(i)) + '" y="' + f2(H - 6) + '" text-anchor="middle">' + esc(cfg.xLabel(i)) + '</text>';
            }
        }

        var gid = nextId('bp-dv-g');
        box.innerHTML = '<svg class="bp-dv-ch" width="' + W + '" height="' + H + '" viewBox="0 0 ' + W + ' ' + H + '" role="img" aria-label="' + esc(cfg.ariaLabel || '') + '">' +
            '<defs><linearGradient id="' + gid + '" x1="0" x2="0" y1="0" y2="1"><stop class="bp-dv-ch-stop-a" offset="0"/><stop class="bp-dv-ch-stop-b" offset="1"/></linearGradient></defs>' +
            grid + xl +
            (pp.length ? '<path class="bp-dv-ch-prev" d="' + mono(pp) + '"/>' : '') +
            (areaD ? '<path class="bp-dv-ch-area" d="' + areaD + '" fill="url(#' + gid + ')"/>' : '') +
            (lineD ? '<path class="bp-dv-ch-line" d="' + lineD + '"/>' : '') +
            (partD ? '<path class="bp-dv-ch-part" d="' + partD + '"/>' : '') +
            (cp.length === 1 ? '<circle class="bp-dv-ch-single" cx="' + f2(cp[0][0]) + '" cy="' + f2(cp[0][1]) + '" r="4"/>' : '') +
            '<line class="bp-dv-ch-cross" x1="0" x2="0" y1="' + T + '" y2="' + f2(T + ph) + '" visibility="hidden"/>' +
            '<circle class="bp-dv-ch-dot-prev" r="3.5" visibility="hidden"/><circle class="bp-dv-ch-dot" r="4.5" visibility="hidden"/>' +
            '</svg>';

        var svg = box.firstChild;
        var cross = svg.querySelector('.bp-dv-ch-cross');
        var dot = svg.querySelector('.bp-dv-ch-dot');
        var dotp = svg.querySelector('.bp-dv-ch-dot-prev');

        function show(i, how) {
            var cx = X(i);
            var cv = cfg.cur[i];
            var pv = cfg.prev ? cfg.prev[i] : null;
            cross.setAttribute('x1', f2(cx));
            cross.setAttribute('x2', f2(cx));
            cross.setAttribute('visibility', 'visible');
            if (cv !== null && cv !== undefined && isFinite(cv)) {
                dot.setAttribute('cx', f2(cx));
                dot.setAttribute('cy', f2(Y(cv)));
                dot.setAttribute('visibility', 'visible');
            } else {
                dot.setAttribute('visibility', 'hidden');
            }
            if (pv !== null && pv !== undefined && isFinite(pv)) {
                dotp.setAttribute('cx', f2(cx));
                dotp.setAttribute('cy', f2(Y(pv)));
                dotp.setAttribute('visibility', 'visible');
            } else {
                dotp.setAttribute('visibility', 'hidden');
            }
            var t = cfg.tipAt(i);
            var bb = svg.getBoundingClientRect();
            var ty = bb.top + (cv !== null && cv !== undefined && isFinite(cv) ? Y(cv) : T) - 6;
            tip.show(t, { left: bb.left + cx - 1, width: 2, top: ty, bottom: bb.top + T + ph, height: 2 });
            if (how === 'key' && cfg.live) {
                var spoken = [t.title].concat((t.rows || []).map(function (r) { return r[0] + ' ' + r[1]; })).join(', ');
                cfg.live.textContent = spoken;
            }
        }

        function hide() {
            cross.setAttribute('visibility', 'hidden');
            dot.setAttribute('visibility', 'hidden');
            dotp.setAttribute('visibility', 'hidden');
            tip.hide();
        }

        box.__bpDvState = { n: n, x0: x0, pw: pw, rtl: rtl };
        if (!box.__bpDvIndexer) {
            box.__bpDvIndexer = indexer(box, {
                count: function () { return box.__bpDvState.n; },
                rtl: function () { return box.__bpDvState.rtl; },
                indexAt: function (clientX) {
                    var st = box.__bpDvState;
                    var bb = box.firstChild.getBoundingClientRect();
                    var x = clientX - bb.left;
                    var off = st.rtl ? st.x0 + st.pw - x : x - st.x0;
                    return Math.round(off / (st.pw / Math.max(1, st.n - 1)));
                },
                onIndex: function (i, how) { box.__bpDvShow(i, how); },
                onLeave: function () { box.__bpDvHide(); }
            });
        }
        box.__bpDvShow = show;
        box.__bpDvHide = hide;
        return { svg: svg, X: X, Y: Y, hide: hide };
    }

    /* ------------------------------------------------------------ hourly bars */

    // Today's sales hour by hour: past hours filled, the current hour dark,
    // the hours still to come as short grey stubs. cfg: { values: [v|null] x24,
    // now: i|null, labelAt(i), tipAt(i), ariaLabel, live }.
    function bars(box, cfg) {
        var rtl = isRtl(box);
        var W = Math.max(160, box.clientWidth);
        var H = 66;
        var top = 4;
        var base = 50;
        var n = cfg.values.length;
        var max = 0;
        cfg.values.forEach(function (v) {
            if (v !== null && v > max) {
                max = v;
            }
        });
        var bw = W / n;
        var out = '';
        for (var h = 0; h < n; h++) {
            var v = cfg.values[h];
            var later = v === null;
            var hh = later ? 2 : Math.max(2, max > 0 ? v / max * (base - top) : 2);
            var x = h * bw + bw * 0.16;
            var xx = rtl ? W - x - bw * 0.68 : x;
            out += '<rect class="bp-dv-hb' + (h === cfg.now ? ' is-now' : later ? ' is-later' : '') + '" x="' + f2(xx) + '" y="' + f2(base - hh) + '" width="' + f2(bw * 0.68) + '" height="' + f2(hh) + '" rx="1.5"/>';
        }
        // Hour labels that fit: about 6px a character at this size, 8px apart.
        // The last one (23:00) always stays; a label it would touch goes.
        var marks = [0, 6, 12, 18, 23];
        var placed = [];
        marks.forEach(function (m, k) {
            var text = String(cfg.labelAt(m));
            var tw = text.length * 6;
            var anchor = k === 0 ? 'start' : k === marks.length - 1 ? 'end' : 'middle';
            var lx = k === 0 ? 0 : k === marks.length - 1 ? W : m * bw + bw / 2;
            var left = anchor === 'start' ? lx : anchor === 'end' ? lx - tw : lx - tw / 2;
            var item = { text: text, anchor: anchor, lx: lx, left: left, right: left + tw };
            while (placed.length && placed[placed.length - 1].right + 8 > item.left) {
                if (k === marks.length - 1 && placed.length > 1) {
                    placed.pop();
                } else {
                    item = null;
                    break;
                }
            }
            if (item) {
                placed.push(item);
            }
        });
        var labels = '';
        placed.forEach(function (it) {
            // Mirrored position only: SVG text inherits the page's direction,
            // so in RTL "start" already means the right edge of the label.
            labels += '<text class="bp-dv-hb-label" x="' + f2(rtl ? W - it.lx : it.lx) + '" y="64" text-anchor="' + it.anchor + '">' + esc(it.text) + '</text>';
        });
        box.innerHTML = '<svg class="bp-dv-hours" width="' + f2(W) + '" height="' + H + '" viewBox="0 0 ' + f2(W) + ' ' + H + '" role="img" aria-label="' + esc(cfg.ariaLabel || '') + '">' + out + labels + '</svg>';

        var svg = box.firstChild;
        var rects = svg.querySelectorAll('.bp-dv-hb');

        function show(i, how) {
            Array.prototype.forEach.call(rects, function (r, k) { r.classList.toggle('is-hl', k === i); });
            var r = rects[i].getBoundingClientRect();
            var t = cfg.tipAt(i);
            tip.show(t, { left: r.left, width: r.width, top: Math.min(r.top, svg.getBoundingClientRect().top + 20), bottom: r.bottom, height: r.height });
            if (how === 'key' && cfg.live) {
                cfg.live.textContent = [t.title].concat((t.rows || []).map(function (row) { return row[0] + ' ' + row[1]; })).join(', ');
            }
        }

        function hide() {
            Array.prototype.forEach.call(rects, function (r) { r.classList.remove('is-hl'); });
            tip.hide();
        }

        box.__bpDvState = { n: n, rtl: rtl };
        if (!box.__bpDvIndexer) {
            box.__bpDvIndexer = indexer(box, {
                count: function () { return box.__bpDvState.n; },
                rtl: function () { return box.__bpDvState.rtl; },
                indexAt: function (clientX) {
                    var st = box.__bpDvState;
                    var bb = box.firstChild.getBoundingClientRect();
                    var x = clientX - bb.left;
                    return Math.floor((st.rtl ? bb.width - x : x) / (bb.width / st.n));
                },
                onIndex: function (i, how) { box.__bpDvShow(i, how); },
                onLeave: function () { box.__bpDvHide(); }
            });
        }
        box.__bpDvShow = show;
        box.__bpDvHide = hide;
    }

    /* ------------------------------------------------------------ funnel ribbon */

    // Edges of the ribbon: flat across each step, an S-curve between steps.
    function edge(n, at, pw, u0, u1, ws, f) {
        var a = function (i) { return i === 0 ? u0 : at(i) - pw; };
        var b = function (i) { return i === n - 1 ? u1 : at(i) + pw; };
        var c = [['M', [a(0), f * ws[0]]]];
        for (var i = 0; i < n; i++) {
            c.push(['L', [b(i), f * ws[i]]]);
            if (i < n - 1) {
                var m = (b(i) + a(i + 1)) / 2;
                c.push(['C', [m, f * ws[i]], [m, f * ws[i + 1]], [a(i + 1), f * ws[i + 1]]]);
            }
        }
        return c;
    }

    function edgeBack(n, at, pw, u0, u1, ws, f) {
        var a = function (i) { return i === 0 ? u0 : at(i) - pw; };
        var b = function (i) { return i === n - 1 ? u1 : at(i) + pw; };
        var c = [['L', [b(n - 1), f * ws[n - 1]]]];
        for (var i = n - 1; i >= 0; i--) {
            c.push(['L', [a(i), f * ws[i]]]);
            if (i > 0) {
                var m = (a(i) + b(i - 1)) / 2;
                c.push(['C', [m, f * ws[i]], [m, f * ws[i - 1]], [b(i - 1), f * ws[i - 1]]]);
            }
        }
        return c;
    }

    function toD(cmds, map) {
        return cmds.map(function (cmd) {
            return cmd[0] + ' ' + cmd.slice(1).map(function (p) {
                return map(p).map(function (v) { return v.toFixed(2); }).join(' ');
            }).join(' ');
        }).join(' ');
    }

    // The conversion funnel as a ribbon that narrows with the people left at
    // each step, inside a pale band of the full width, with the step rates as
    // beads on it. Below 450px of card it turns to run down the card.
    // steps: [{ label, value (text), val, rel, rate (text or '') }].
    // opts: { tipFor(i) → tooltip, ariaFor(i) → text }.
    function flow(box, steps, opts) {
        var rtl = isRtl(box);
        var W = Math.max(240, box.clientWidth);
        var vertical = W < 450;
        var n = steps.length;
        var H;
        var map;
        var at;
        var pw;
        var u0;
        var u1;
        var half;
        var env;
        var cross = '';
        var labs = '';
        var beads = '';
        var hits = [];
        var term;
        var clip;
        var i;

        if (!vertical) {
            H = 212;
            var labH = 52;
            var top = labH + 12;
            half = (H - top - 4) / 2;
            var yc = top + half;
            var cw = W / n;
            at = function (k) { return cw * (k + 0.5); };
            pw = Math.min(16, cw * 0.15);
            u0 = 1;
            u1 = W - 1;
            map = function (p) { return [rtl ? W - p[0] : p[0], yc + p[1]]; };
            env = '<rect class="bp-dv-flow-env" x="' + f2(u0) + '" y="' + f2(yc - half) + '" width="' + f2(u1 - u0) + '" height="' + f2(2 * half) + '" rx="12"/>';
            clip = '<rect x="' + f2(u0) + '" y="' + f2(yc - half) + '" width="' + f2(u1 - u0) + '" height="' + f2(2 * half) + '" rx="12"/>';
            for (i = 0; i < n; i++) {
                var cx = rtl ? W - at(i) : at(i);
                cross += '<line class="bp-dv-flow-cross" data-step="' + i + '" x1="' + f2(cx) + '" x2="' + f2(cx) + '" y1="' + f2(yc - half - 6) + '" y2="' + f2(yc + half + 2) + '"/>';
                labs += '<div class="bp-dv-flow-lab" data-step="' + i + '" style="left:' + f2(cx - cw / 2 + 1) + 'px;width:' + f2(cw - 2) + 'px;height:' + labH + 'px"><span class="bp-dv-flow-name">' + esc(steps[i].label) + '</span><span class="bp-dv-flow-val"><bdi>' + esc(steps[i].value) + '</bdi></span></div>';
                hits.push({ left: rtl ? W - cw * (i + 1) : cw * i, top: 0, width: cw, height: H });
                if (i < n - 1 && steps[i + 1].rate) {
                    var mid = (at(i) + at(i + 1)) / 2;
                    beads += '<span class="bp-dv-flow-bead" data-step="' + (i + 1) + '" data-x="' + (mid / W).toFixed(3) + '" style="left:' + f2(rtl ? W - mid : mid) + 'px;top:' + f2(yc) + 'px"><bdi>' + esc(steps[i + 1].rate) + '</bdi></span>';
                }
            }
            term = [rtl ? W - u1 : u1, yc];
        } else {
            var rowH = 60;
            H = rowH * n;
            var xc = 30;
            half = 26;
            at = function (k) { return rowH * (k + 0.5); };
            pw = 9;
            u0 = 1;
            u1 = H - 1;
            map = function (p) { return [rtl ? W - (xc + p[1]) : xc + p[1], p[0]]; };
            var ex = rtl ? W - xc - half : xc - half;
            env = '<rect class="bp-dv-flow-env" x="' + f2(ex) + '" y="' + f2(u0) + '" width="' + f2(2 * half) + '" height="' + f2(u1 - u0) + '" rx="12"/>';
            clip = '<rect x="' + f2(ex) + '" y="' + f2(u0) + '" width="' + f2(2 * half) + '" height="' + f2(u1 - u0) + '" rx="12"/>';
            for (i = 0; i < n; i++) {
                var lx1 = rtl ? 0 : xc - half - 4;
                var lx2 = rtl ? W - xc + half + 4 : W;
                cross += '<line class="bp-dv-flow-cross" data-step="' + i + '" x1="' + f2(lx1) + '" x2="' + f2(lx2) + '" y1="' + f2(at(i)) + '" y2="' + f2(at(i)) + '"/>';
                labs += '<div class="bp-dv-flow-vlab" data-step="' + i + '" style="inset-inline-start:' + (xc + half + 18) + 'px;top:' + f2(at(i) - 12) + 'px;height:24px"><span class="bp-dv-flow-name">' + esc(steps[i].label) + '</span><span class="bp-dv-flow-val"><bdi>' + esc(steps[i].value) + '</bdi></span></div>';
                hits.push({ left: 0, top: rowH * i, width: W, height: rowH });
                if (i < n - 1 && steps[i + 1].rate) {
                    var vmid = (at(i) + at(i + 1)) / 2;
                    beads += '<span class="bp-dv-flow-bead" data-step="' + (i + 1) + '" data-x="' + (vmid / H).toFixed(3) + '" style="left:' + f2(rtl ? W - xc : xc) + 'px;top:' + f2(vmid) + 'px"><bdi>' + esc(steps[i + 1].rate) + '</bdi></span>';
                }
            }
            term = [rtl ? W - xc : xc, u1];
        }

        var ws = steps.map(function (st) { return Math.max(st.val > 0 ? 1.25 : 0.5, st.rel * half); });
        var ribbon = toD(edge(n, at, pw, u0, u1, ws, -1).concat(edgeBack(n, at, pw, u0, u1, ws, 1)), map) + ' Z';
        var strands = [-0.62, -0.31, 0.31, 0.62].map(function (f) {
            return '<path class="bp-dv-flow-strand" d="' + toD(edge(n, at, pw, u0, u1, ws, f), map) + '"/>';
        }).join('');
        var termR = ws[n - 1] < 3 ? 2.75 : 0;
        var gid = nextId('bp-dv-f');
        var grad = vertical
            ? 'x1="0" x2="0" y1="0" y2="1"'
            : (rtl ? 'x1="1" x2="0" y1="0" y2="0"' : 'x1="0" x2="1" y1="0" y2="0"');
        var termCx = vertical ? term[0] : (rtl ? term[0] + termR : term[0] - termR);
        var termCy = vertical ? term[1] - termR : term[1];

        box.style.height = H + 'px';
        box.setAttribute('data-vertical', vertical ? '1' : '');
        box.setAttribute('data-reveal', vertical ? 'inset(0 0 100% 0)' : (rtl ? 'inset(0 0 0 100%)' : 'inset(0 100% 0 0)'));
        box.innerHTML = '<svg class="bp-dv-flow-svg" width="' + W + '" height="' + H + '" viewBox="0 0 ' + W + ' ' + H + '" aria-hidden="true" focusable="false">' + env + cross + '</svg>' +
            '<svg class="bp-dv-flow-svg bp-dv-flow-rib" width="' + W + '" height="' + H + '" viewBox="0 0 ' + W + ' ' + H + '" aria-hidden="true" focusable="false">' +
            '<defs><linearGradient id="g' + gid + '" ' + grad + '><stop class="bp-dv-flow-stop-a" offset="0"/><stop class="bp-dv-flow-stop-b" offset=".55"/><stop class="bp-dv-flow-stop-c" offset="1"/></linearGradient><clipPath id="c' + gid + '">' + clip + '</clipPath></defs>' +
            '<g clip-path="url(#c' + gid + ')"><path d="' + ribbon + '" fill="url(#g' + gid + ')"/>' + strands + '</g>' +
            (termR ? '<circle class="bp-dv-flow-term" cx="' + f2(termCx) + '" cy="' + f2(termCy) + '" r="' + termR + '"/>' : '') +
            '</svg>' + labs + beads;

        // One button per step, each with its own bubble (the shared helper
        // opens it on hover, focus and tap and keeps it on screen).
        hits.forEach(function (hb, k) {
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'bp-dv-flow-hit';
            btn.setAttribute('data-step', String(k));
            btn.setAttribute('data-bp-tip', vertical ? '' : 'top');
            btn.setAttribute('aria-label', opts.ariaFor(k));
            btn.style.left = f2(hb.left) + 'px';
            btn.style.top = f2(hb.top) + 'px';
            btn.style.width = f2(hb.width) + 'px';
            btn.style.height = f2(hb.height) + 'px';
            var bubble = document.createElement('span');
            bubble.className = 'brikpanel-tip bp-dv-tipbox';
            bubble.setAttribute('role', 'tooltip');
            fillTip(bubble, opts.tipFor(k));
            btn.appendChild(bubble);
            box.appendChild(btn);
        });

        function highlight(k) {
            var card = box.closest('.bp-dv-card') || box;
            card.classList.toggle('is-hl', k !== null);
            box.querySelectorAll('[data-step]').forEach(function (node) {
                if (node.classList.contains('bp-dv-flow-hit')) {
                    return;
                }
                var same = k !== null && node.getAttribute('data-step') === String(k);
                node.classList.toggle('is-on', same);
                node.classList.toggle('is-dim', k !== null && !same);
            });
        }

        if (!box.__bpDvFlowWired) {
            box.__bpDvFlowWired = true;
            var on = function (e) {
                var b = e.target.closest && e.target.closest('.bp-dv-flow-hit');
                if (b && box.contains(b)) {
                    highlight(Number(b.getAttribute('data-step')));
                }
            };
            var off = function (e) {
                var b = e.target.closest && e.target.closest('.bp-dv-flow-hit');
                if (b && !(e.relatedTarget && b.contains(e.relatedTarget))) {
                    highlight(null);
                }
            };
            box.addEventListener('pointerover', on);
            box.addEventListener('focusin', on);
            box.addEventListener('pointerout', off);
            box.addEventListener('focusout', off);
        }
    }

    /* ------------------------------------------------------------ tabs */

    // A tab row (role tablist, buttons role tab, panels hidden): click, the
    // arrow keys (reversed in RTL), Home and End; one tab in the Tab order.
    // Tabs that share one panel (the sales chart's metrics) only switch.
    // onChange(key, byUser) runs on every switch; byUser is false when the
    // returned select(key) switched from code. A tab the card hides (no box
    // on screen) is skipped by the keys but still has its panel hidden.
    function tabs(root, onChange) {
        var list = root.querySelector('[role="tablist"]');
        if (!list) {
            return null;
        }
        if (list.__bpDvTabs) {
            return list.__bpDvTabs;
        }
        var btns = function () { return Array.prototype.slice.call(list.querySelectorAll('[role="tab"]')); };

        function select(btn, focus, byUser) {
            var all = btns();
            var keep = btn.getAttribute('aria-controls');
            all.forEach(function (b) {
                var on = b === btn;
                b.setAttribute('aria-selected', on ? 'true' : 'false');
                b.tabIndex = on ? 0 : -1;
                var id = b.getAttribute('aria-controls');
                var panel = id ? document.getElementById(id) : null;
                if (panel && id !== keep) {
                    panel.hidden = true;
                }
            });
            var shown = keep ? document.getElementById(keep) : null;
            if (shown) {
                shown.hidden = false;
            }
            if (focus) {
                btn.focus();
            }
            if (onChange) {
                onChange(btn.getAttribute('data-bp-dv-tab'), byUser !== false);
            }
        }

        var api = {
            select: function (key) {
                var b = btns().filter(function (x) { return x.getAttribute('data-bp-dv-tab') === key; })[0];
                if (b && b.getAttribute('aria-selected') !== 'true') {
                    select(b, false, false);
                }
            }
        };
        list.__bpDvTabs = api;

        list.addEventListener('click', function (e) {
            var b = e.target.closest && e.target.closest('[role="tab"]');
            if (b && list.contains(b) && b.getAttribute('aria-selected') !== 'true') {
                select(b, false);
            }
        });
        list.addEventListener('keydown', function (e) {
            var all = btns().filter(function (b) { return b.getClientRects().length > 0; });
            var at = all.indexOf(document.activeElement);
            if (at < 0) {
                return;
            }
            var rtl = isRtl(list);
            var to = -1;
            if (e.key === 'ArrowRight') {
                to = (at + (rtl ? -1 : 1) + all.length) % all.length;
            } else if (e.key === 'ArrowLeft') {
                to = (at + (rtl ? 1 : -1) + all.length) % all.length;
            } else if (e.key === 'Home') {
                to = 0;
            } else if (e.key === 'End') {
                to = all.length - 1;
            }
            if (to > -1) {
                e.preventDefault();
                select(all[to], true);
            }
        });
        return api;
    }

    /* ------------------------------------------------------------ motion */

    function anim(el, frames, opts) {
        if (reduced() || !el || typeof el.animate !== 'function') {
            return null;
        }
        var o = { fill: 'backwards', easing: EASE };
        for (var k in opts) {
            if (Object.prototype.hasOwnProperty.call(opts, k)) {
                o[k] = opts[k];
            }
        }
        try {
            return el.animate(frames, o);
        } catch (e) {
            return null;
        }
    }

    // Counts a figure up from zero in its own format, then hands it back to
    // the caller (done), which writes the finished server text again.
    function countUp(el, to, fmt, opts) {
        opts = opts || {};
        if (reduced() || !el || !isFinite(to)) {
            if (opts.done) {
                opts.done();
            }
            return;
        }
        var t0 = performance.now() + (opts.delay || 0);
        var dur = opts.duration || 700;
        el.textContent = fmt(0);
        var step = function (t) {
            var p = Math.min(1, Math.max(0, (t - t0) / dur));
            if (p >= 1) {
                if (opts.done) {
                    opts.done();
                } else {
                    el.textContent = fmt(to);
                }
                return;
            }
            el.textContent = fmt(to * (1 - Math.pow(1 - p, 3)));
            window.requestAnimationFrame(step);
        };
        window.requestAnimationFrame(step);
    }

    function inView(el, frac) {
        if (!el || !el.getBoundingClientRect) {
            return false;
        }
        var r = el.getBoundingClientRect();
        if (!r.height) {
            return false;
        }
        var vh = window.innerHeight || document.documentElement.clientHeight;
        var visible = Math.min(r.bottom, vh) - Math.max(r.top, 0);
        return visible >= Math.min(r.height, vh) * (frac || 0.3);
    }

    // Calls cb once, the first time el is at least `frac` in view.
    function onView(el, cb, frac) {
        if (!el) {
            return null;
        }
        if (typeof window.IntersectionObserver !== 'function') {
            cb();
            return null;
        }
        var io = new window.IntersectionObserver(function (entries) {
            entries.forEach(function (en) {
                if (en.isIntersecting) {
                    io.disconnect();
                    cb();
                }
            });
        }, { threshold: frac || 0.3 });
        io.observe(el);
        return io;
    }

    // Calls cb(width) when el's width changes by 2px or more, at most once a frame.
    function watchWidth(el, cb) {
        var last = el.clientWidth;
        var raf = 0;
        var run = function () {
            window.cancelAnimationFrame(raf);
            raf = window.requestAnimationFrame(function () {
                var w = el.clientWidth;
                if (Math.abs(w - last) < 2) {
                    return;
                }
                last = w;
                cb(w);
            });
        };
        if (typeof window.ResizeObserver === 'function') {
            var ro = new window.ResizeObserver(run);
            ro.observe(el);
            return ro;
        }
        window.addEventListener('resize', run);
        return null;
    }

    window.brikpanelDashViz = {
        EASE: EASE,
        EASE_OUT: EASE_OUT,
        mono: mono,
        niceMax: niceMax,
        per100: per100,
        smooth: smooth,
        spark: spark,
        dots: dots,
        waffle: waffle,
        flow: flow,
        line: line,
        bars: bars,
        tip: tip,
        indexer: indexer,
        tabs: tabs,
        anim: anim,
        countUp: countUp,
        inView: inView,
        onView: onView,
        watchWidth: watchWidth,
        reduced: reduced,
        isRtl: isRtl,
        esc: esc
    };
})(window, document);
