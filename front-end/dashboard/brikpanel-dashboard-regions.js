/**
 * BrikPanel dashboard: the region map of the Order locations card, for a
 * store that sells in one country (Brikpanel_Dashboard_Regions decides).
 *
 * The day's first look, and a switch from the world globe, flies in: the
 * dotted globe turns to the country, the camera dives in, the dots give way
 * to the country's own shape, the borders draw themselves from the middle
 * out and the regions take their colours, busiest first. Back to the world,
 * the map flies out into the globe facing the country and the world globe
 * takes over right there. After the day's first look the map comes straight.
 *
 * Smooth on slow machines too:
 * - The dotted globe is never blown up. Its dots (12,000 over the earth,
 *   about 150 km apart) are gone before the camera comes close, so a coarse
 *   dot grid is never on screen; everything closer is drawn from the map's
 *   own shapes, sharp at any size.
 * - Every shape is built once, in the globe's units, as a Path2D; a frame
 *   only changes the scale (setTransform).
 * - Once the camera stops, what is finished is painted once into a hidden
 *   canvas, and a frame copies that one picture plus the few borders and
 *   colours still on their way. Painting every region and border in every
 *   frame took 15 to 23 ms a frame in Firefox (France, 96 departments).
 * - A frame moves the motion on by 34 ms at most: a busy moment of the page
 *   delays it, it never jumps. A machine far too slow gets the map straight.
 *
 * This file holds no text: names, numbers and labels come from the caller,
 * already translated and formatted.
 *
 * API: window.brikpanelRegionMap.open( opts ) -> { ready, update, reverse, destroy };
 *      window.brikpanelRegionMap.preload( mapsUrl, cc, version ) -> Promise.
 */
(function (window, document) {
    'use strict';

    if (window.brikpanelRegionMap) {
        return;
    }

    var RAD = Math.PI / 180;
    // No orders, then five steps from few to many (CSS legend: .brikpanel-loc-legend).
    var STEPS = ['#ececf0', '#d9d9df', '#b1b1ba', '#84848e', '#55555e', '#1d1d1f'];
    var LINE = '#84848e';      // a border while it draws itself in
    var PAPER = '#ffffff';     // the borders between coloured regions
    var LAND = '#e6e6ea';
    var HOVER = '#d72c0d';
    var BOX = '#e3e3e3';
    // One border drawing itself in, one region taking its colour: short
    // enough that only part of the country is on its way at any moment.
    var BORDER_MS = 380;
    var FILL_MS = 240;
    var REVERSE_MS = 950;      // the flight back to the globe
    var STEP_MAX = 34;         // the most a frame moves the motion on
    var DOT_PX = 3.5;          // regions too small to see are drawn as dots

    var cache = {};

    /* ------------------------------------------------------------ geometry */

    function xyz(lat, lon) {
        var a = lat * RAD, o = lon * RAD, c = Math.cos(a);
        return [c * Math.cos(o), Math.sin(a), -c * Math.sin(o)];
    }

    // The globe's rotation that faces a place (the WebGL globe's phi and theta).
    function facing(lat, lon) {
        return [-Math.PI / 2 - lon * RAD, lat * RAD];
    }

    function rot(phi, theta) {
        return { sp: Math.sin(phi), cp: Math.cos(phi), sn: Math.sin(theta), cn: Math.cos(theta) };
    }

    function pr(x, y, z, r) {
        return [
            x * r.cp + z * r.sp,
            x * r.sp * r.sn + y * r.cn - z * r.cp * r.sn,
            -x * r.sp * r.cn + y * r.sn + z * r.cp * r.cn
        ];
    }

    // An angle brought into -PI..PI.
    function wrap(a) {
        return ((a + Math.PI) % (2 * Math.PI) + 2 * Math.PI) % (2 * Math.PI) - Math.PI;
    }

    // "lon,lat,dlon,dlat,..." in 1/1000 degree, rings joined by ';' -> unit vectors.
    function decodeRings(str) {
        if (!str) {
            return [];
        }
        return String(str).split(';').map(function (ring) {
            var n = ring.split(',');
            var out = new Float32Array(Math.floor(n.length / 2) * 3);
            var lon = 0, lat = 0;
            for (var i = 0, k = 0; i + 1 < n.length; i += 2, k += 3) {
                lon += +n[i];
                lat += +n[i + 1];
                var p = xyz(lat / 1000, lon / 1000);
                out[k] = p[0];
                out[k + 1] = p[1];
                out[k + 2] = p[2];
            }
            return out;
        });
    }

    function emptyBox() {
        return [Infinity, Infinity, -Infinity, -Infinity];
    }

    function growBox(b, x, y) {
        if (x < b[0]) b[0] = x;
        if (y < b[1]) b[1] = y;
        if (x > b[2]) b[2] = x;
        if (y > b[3]) b[3] = y;
    }

    // Rings seen from rotation r, as one Path2D in globe units (y up), with
    // the longest ring's length (for the border drawing in), the bounds, and
    // the size of the biggest piece (an island group is small even when its
    // islands lie far apart).
    function shape(rings, r) {
        var path = new Path2D();
        var box = emptyBox();
        var longest = 0;
        var piece = [0, 0];
        rings.forEach(function (ring) {
            var pen = false, len = 0, px = 0, py = 0;
            var rb = emptyBox();
            for (var k = 0; k < ring.length; k += 3) {
                var p = pr(ring[k], ring[k + 1], ring[k + 2], r);
                if (p[2] <= 0) {
                    pen = false;
                    continue;
                }
                if (pen) {
                    path.lineTo(p[0], p[1]);
                    len += Math.sqrt((p[0] - px) * (p[0] - px) + (p[1] - py) * (p[1] - py));
                } else {
                    path.moveTo(p[0], p[1]);
                    pen = true;
                }
                px = p[0];
                py = p[1];
                growBox(box, p[0], p[1]);
                growBox(rb, p[0], p[1]);
            }
            if (pen) {
                path.closePath();
            }
            if (len > longest) {
                longest = len;
            }
            if (rb[2] > rb[0] && (rb[2] - rb[0]) * (rb[3] - rb[1]) > piece[0] * piece[1]) {
                piece = [rb[2] - rb[0], rb[3] - rb[1]];
            }
        });
        return { path: path, box: box, len: longest, piece: piece };
    }

    // The map file, decoded and seen from the country's own view: one item
    // per region, the far islands in their boxes, the land without a region.
    function build(map) {
        var view = facing(map.view[0], map.view[1]);
        var r = rot(view[0], view[1]);
        var boxOf = {};
        var insets = (map.in || []).map(function (b, i) {
            (b.k || []).forEach(function (k) { boxOf[k] = i; });
            return { b: b.b, items: [] };
        });
        var main = [];
        var centre = xyz(map.view[0], map.view[1]);
        (map.r || []).forEach(function (row) {
            var item = { key: String(row[0]), rings: decodeRings(row[1]), at: row[2] ? xyz(row[2][0], row[2][1]) : null };
            if (boxOf[item.key] !== undefined) {
                insets[boxOf[item.key]].items.push(item);
            } else {
                main.push(item);
            }
        });

        var bounds = emptyBox();
        main.forEach(function (g) {
            var s = shape(g.rings, r);
            g.path = s.path;
            g.box = s.box;
            g.len = s.len;
            g.piece = s.piece;
            g.lab = g.at ? pr(g.at[0], g.at[1], g.at[2], r) : [(s.box[0] + s.box[2]) / 2, (s.box[1] + s.box[3]) / 2, 1];
            g.dist = g.at ? Math.acos(Math.max(-1, Math.min(1, g.at[0] * centre[0] + g.at[1] * centre[1] + g.at[2] * centre[2]))) : 0;
            growBox(bounds, s.box[0], s.box[1]);
            growBox(bounds, s.box[2], s.box[3]);
        });
        var land = null;
        if (map.land) {
            land = shape(decodeRings(map.land), r);
            growBox(bounds, land.box[0], land.box[1]);
            growBox(bounds, land.box[2], land.box[3]);
        }
        // Centre-out order of the borders drawing in.
        main.slice().sort(function (a, b) { return a.dist - b.dist; }).forEach(function (g, i) { g.order = i; });

        // Each box faces its own islands.
        insets = insets.filter(function (box) { return box.items.length; }).map(function (box) {
            var c = [0, 0, 0];
            box.items.forEach(function (g) {
                g.rings.forEach(function (ring) {
                    for (var k = 0; k < ring.length; k += 3) {
                        c[0] += ring[k];
                        c[1] += ring[k + 1];
                        c[2] += ring[k + 2];
                    }
                });
            });
            var lat = Math.atan2(c[1], Math.sqrt(c[0] * c[0] + c[2] * c[2])) / RAD;
            var lon = Math.atan2(-c[2], c[0]) / RAD;
            var f = facing(lat, lon), ri = rot(f[0], f[1]);
            var b = emptyBox();
            box.items.forEach(function (g) {
                var s = shape(g.rings, ri);
                g.path = s.path;
                g.box = s.box;
                g.len = s.len;
                g.piece = s.piece;
                g.lab = g.at ? pr(g.at[0], g.at[1], g.at[2], ri) : [(s.box[0] + s.box[2]) / 2, (s.box[1] + s.box[3]) / 2, 1];
                growBox(b, s.box[0], s.box[1]);
                growBox(b, s.box[2], s.box[3]);
            });
            box.bounds = b;
            return box;
        });

        // All regions as one path: the country's shape and every border in
        // one call; with the land without a region, the country's outline.
        var borders = null, country = null;
        if (typeof Path2D.prototype.addPath === 'function') {
            borders = new Path2D();
            main.forEach(function (g) { borders.addPath(g.path); });
            country = new Path2D();
            country.addPath(borders);
            if (land) {
                country.addPath(land.path);
            }
        }
        return { view: view, main: main, insets: insets, land: land, bounds: bounds, borders: borders, country: country };
    }

    // Runs fn when the page has a quiet moment (at the latest after timeout ms).
    function idle(fn, timeout) {
        if (typeof window.requestIdleCallback === 'function') {
            window.requestIdleCallback(fn, { timeout: timeout });
        } else {
            window.setTimeout(fn, 60);
        }
    }

    // The map file, fetched and built once per page. The building (a few
    // thousand points turned into shapes) waits for a quiet moment.
    function load(url, cc, version) {
        var key = cc + '|' + version;
        if (!cache[key]) {
            cache[key] = window.fetch(url + cc.toLowerCase() + '.json?ver=' + encodeURIComponent(version), { credentials: 'same-origin' })
                .then(function (res) {
                    if (!res.ok) {
                        throw new Error(String(res.status));
                    }
                    return res.json();
                })
                .then(function (map) {
                    if (!map || !map.r || !map.view) {
                        throw new Error('map');
                    }
                    return new Promise(function (resolve, reject) {
                        idle(function () {
                            try {
                                resolve(build(map));
                            } catch (e) {
                                reject(e);
                            }
                        }, 400);
                    });
                });
            cache[key].catch(function () { delete cache[key]; });
        }
        return cache[key];
    }

    /* ------------------------------------------------------------ helpers */

    function clamp01(t) {
        return t < 0 ? 0 : (t > 1 ? 1 : t);
    }

    function span(t, a, b) {
        return clamp01((t - a) / (b - a));
    }

    // Slow at both ends, never hanging still.
    function easeSoft(t) {
        return (1 - Math.cos(Math.PI * t)) / 2;
    }

    function smooth(a, b, x) {
        var s = span(x, a, b);
        return s * s * (3 - 2 * s);
    }

    function grey(t) {
        var g = Math.round(255 * t);
        return 'rgb(' + g + ',' + g + ',' + g + ')';
    }

    function today() {
        var d = new Date();
        return d.getFullYear() + '-' + (d.getMonth() + 1) + '-' + d.getDate();
    }

    // Whether the day's animation already played (per site: the dashboards of
    // a network share one address). Without storage it never plays.
    function playedToday(key) {
        try {
            return window.localStorage.getItem(key) === today();
        } catch (e) {
            return true;
        }
    }

    function markPlayed(key) {
        try {
            window.localStorage.setItem(key, today());
        } catch (e) {
            // Nothing to keep it in: the next visit just shows the map.
        }
    }

    // When each step of the flight in happens (ms), from how far the globe
    // turns (radians) and how much the camera comes in. The zoom starts while
    // the turn eases out; the borders start where the camera stops.
    function timeline(turnRad, ratio) {
        var turn = Math.round(650 + 550 * Math.min(1, Math.abs(turnRad) / Math.PI));
        var zoomFrom = Math.max(0, turn - 250);
        var zoom = Math.round(Math.min(1800, Math.max(1100, 1050 + 150 * Math.log(Math.max(1, ratio)))));
        var stop = zoomFrom + zoom;
        return {
            turn: [0, turn],
            marks: [Math.round(turn * 0.45), turn + 150],
            land: [turn, turn + 300],
            zoom: [zoomFrom, stop],
            borders: [stop, stop + 1000],
            fills: [stop + 350, stop + 1050],
            // After the colours: the two never cost a frame together.
            white: [stop + 1050, stop + 1350],
            late: [stop + 900, stop + 1300],
            end: stop + 1400
        };
    }

    /* ------------------------------------------------------------ one map */

    function open(opts) {
        var container = opts.container;
        var viz = window.brikpanelDashViz || null;
        var reduced = !!opts.reduced;
        var canvas = document.createElement('canvas');
        canvas.className = 'brikpanel-loc-map';
        canvas.setAttribute('role', 'img');
        canvas.setAttribute('aria-label', opts.label || '');
        // Shown once its first picture is drawn, so the box never blinks empty.
        canvas.style.opacity = '0';
        container.appendChild(canvas);
        var ctx = canvas.getContext('2d');

        var focus = opts.focus;
        var metric = opts.metric === 'customers' ? 'customers' : 'count';
        var geo = null;
        var W = 0, H = 0, dpr = 1, R0 = 0;
        var fitMain = null, fitBoxes = [];
        var tl = timeline(0, 1);
        var raf = 0, t = 0, done = false, dead = false, shown = false;
        var phiStart = 0, thetaStart = 0;
        var cam = null;            // where the camera was in the last frame
        var hover = null;
        var visible = false, io = null, ro = null;
        var pendingPlay = false;
        var values = {};
        var layers = null;         // hidden pictures of the reveal: { done, top, area }
        var finalPic = null;       // the finished map, drawn once

        function size() {
            W = Math.max(120, container.clientWidth);
            H = Math.max(120, container.clientHeight);
            var ratio = Math.min(window.devicePixelRatio || 1, 2);
            canvas.width = Math.round(W * ratio);
            canvas.height = Math.round(H * ratio);
            dpr = canvas.width / W;
            // The world globe's size (brikpanel-dashboard.js: 0.8 of half the box).
            R0 = Math.min(W, H) * 0.4;
            if (geo) {
                fit();
            }
        }

        function fit() {
            var b = geo.bounds;
            var pad = Math.min(W, H) * (W < 600 ? 0.04 : 0.06);
            var R = Math.min((W - 2 * pad) / Math.max(1e-6, b[2] - b[0]), (H - 2 * pad) / Math.max(1e-6, b[3] - b[1]));
            var fx = (b[0] + b[2]) / 2, fy = (b[1] + b[3]) / 2;
            fitMain = { R: R, cx: W / 2 - fx * R, cy: H / 2 + fy * R, fx: fx, fy: fy };
            fitBoxes = geo.insets.map(function (box) {
                var x = box.b[0] * W, y = box.b[1] * H, w = box.b[2] * W, h = box.b[3] * H;
                var ib = box.bounds, ipad = Math.min(w, h) * 0.1;
                var Ri = Math.min((w - 2 * ipad) / Math.max(1e-6, ib[2] - ib[0]), (h - 2 * ipad) / Math.max(1e-6, ib[3] - ib[1]));
                return { x: x, y: y, w: w, h: h, R: Ri, cx: x + w / 2 - (ib[0] + ib[2]) / 2 * Ri, cy: y + h / 2 + (ib[1] + ib[3]) / 2 * Ri };
            });
            geo.main.forEach(function (g) { g.tiny = tiny(g, fitMain); });
            geo.insets.forEach(function (box, i) {
                box.items.forEach(function (g) { g.tiny = tiny(g, fitBoxes[i]); });
            });
            // The hidden pictures belong to one size.
            layers = null;
            finalPic = null;
        }

        // Regions too small to see or point at (a city, a sliver of an island).
        function tiny(g, f) {
            var w = g.piece[0] * f.R, h = g.piece[1] * f.R;
            return w * h < 36 || (w < 5 && h < 5);
        }

        // Colour steps (quantiles of the regions with orders), rank and value per region.
        function paint() {
            values = {};
            (focus.regions || []).forEach(function (row) {
                values[row.key] = row;
            });
            var all = geo.main.concat.apply(geo.main, geo.insets.map(function (b) { return b.items; }));
            var vals = all.map(function (g) { return values[g.key] ? (values[g.key][metric] || 0) : 0; })
                .filter(function (v) { return v > 0; })
                .sort(function (a, b) { return a - b; });
            var q = [0.2, 0.4, 0.6, 0.8].map(function (p) { return vals.length ? vals[Math.floor(p * (vals.length - 1))] : 0; });
            all.forEach(function (g) {
                var v = values[g.key] ? (values[g.key][metric] || 0) : 0;
                g.v = v;
                g.step = v <= 0 ? 0 : (v <= q[0] ? 1 : (v <= q[1] ? 2 : (v <= q[2] ? 3 : (v <= q[3] ? 4 : 5))));
            });
            all.slice().sort(function (a, b) { return (b.v - a.v) || ((a.dist || 0) - (b.dist || 0)); }).forEach(function (g, i) { g.rank = i; });
            finalPic = null;
        }

        /* ---------------- drawing */

        // Globe units -> pixels of context x, for a map drawn at radius f.R around (f.cx, f.cy).
        function unitOn(x, f) {
            x.setTransform(dpr * f.R, 0, 0, -dpr * f.R, dpr * f.cx, dpr * f.cy);
        }

        function pixelsOn(x) {
            x.setTransform(dpr, 0, 0, dpr, 0, 0);
        }

        // The pixels the country covers (with room for lines and dots): a
        // frame of the reveal copies only these.
        function mapArea() {
            var b = geo.bounds, f = fitMain, pad = DOT_PX + 4;
            var x0 = Math.max(0, Math.floor((f.cx + b[0] * f.R - pad) * dpr));
            var y0 = Math.max(0, Math.floor((f.cy - b[3] * f.R - pad) * dpr));
            var x1 = Math.min(canvas.width, Math.ceil((f.cx + b[2] * f.R + pad) * dpr));
            var y1 = Math.min(canvas.height, Math.ceil((f.cy - b[1] * f.R + pad) * dpr));
            return [x0, y0, Math.max(1, x1 - x0), Math.max(1, y1 - y0)];
        }

        function makeLayer() {
            var c = document.createElement('canvas');
            c.width = canvas.width;
            c.height = canvas.height;
            return c;
        }

        function lines(x, colour, px, f) {
            x.lineJoin = 'round';
            x.lineCap = 'round';
            x.strokeStyle = colour;
            x.lineWidth = px / f.R;
        }

        function strokeAll(x, list) {
            if (geo.borders && list === geo.main) {
                x.stroke(geo.borders);
            } else {
                list.forEach(function (g) { x.stroke(g.path); });
            }
        }

        // The country's light grey shape (land without a region a shade darker).
        function drawLand(x, f, alpha) {
            unitOn(x, f);
            x.globalAlpha = alpha;
            if (geo.land) {
                x.fillStyle = LAND;
                x.fill(geo.land.path, 'evenodd');
            }
            x.fillStyle = STEPS[0];
            if (geo.borders) {
                x.fill(geo.borders, 'evenodd');
            } else {
                geo.main.forEach(function (g) { x.fill(g.path, 'evenodd'); });
            }
            x.globalAlpha = 1;
        }

        // A tiny region's dot; a light dot on white (an island in the sea) gets an edge.
        function drawDot(x, g, f, alpha) {
            pixelsOn(x);
            x.globalAlpha = alpha;
            x.beginPath();
            x.arc(f.cx + g.lab[0] * f.R, f.cy - g.lab[1] * f.R, DOT_PX, 0, Math.PI * 2);
            x.fillStyle = STEPS[Math.max(1, g.step)];
            x.fill();
            x.lineWidth = 1;
            x.strokeStyle = g.step <= 1 ? STEPS[2] : PAPER;
            x.stroke();
            x.globalAlpha = 1;
        }

        // The far islands' boxes, finished (they come in last, as a whole).
        function drawBoxes(x, alpha) {
            geo.insets.forEach(function (box, i) {
                var f = fitBoxes[i];
                pixelsOn(x);
                x.globalAlpha = alpha;
                x.lineWidth = 1;
                x.strokeStyle = BOX;
                x.strokeRect(Math.round(f.x) + 0.5, Math.round(f.y) + 0.5, Math.round(f.w), Math.round(f.h));
                unitOn(x, f);
                box.items.forEach(function (g) {
                    x.fillStyle = STEPS[g.step];
                    x.fill(g.path, 'evenodd');
                });
                lines(x, PAPER, 0.8, f);
                strokeAll(x, box.items);
                x.globalAlpha = 1;
                box.items.forEach(function (g) {
                    if (g.tiny) drawDot(x, g, f, alpha);
                });
            });
            x.globalAlpha = 1;
        }

        // The finished country (colours, white borders, the smallest
        // regions' dots), without the far islands' boxes.
        function drawCountry(x) {
            x.setTransform(1, 0, 0, 1, 0, 0);
            x.clearRect(0, 0, x.canvas.width, x.canvas.height);
            unitOn(x, fitMain);
            if (geo.land) {
                x.fillStyle = LAND;
                x.fill(geo.land.path, 'evenodd');
            }
            geo.main.forEach(function (g) {
                x.fillStyle = STEPS[g.step];
                x.fill(g.path, 'evenodd');
            });
            lines(x, PAPER, 0.8, fitMain);
            strokeAll(x, geo.main);
            geo.main.forEach(function (g) {
                if (g.tiny) drawDot(x, g, fitMain, 1);
            });
        }

        // The finished map, drawn once into a hidden canvas: every frame of a
        // still map (pointing at a region, a resize) copies it. After a flight
        // the finished country is drawn already; only the boxes join it.
        function finalPicture() {
            if (finalPic) {
                return finalPic;
            }
            var c = layers ? layers.top : makeLayer();
            var x = c.getContext('2d');
            if (!layers) {
                drawCountry(x);
            }
            drawBoxes(x, 1);
            layers = null;
            finalPic = c;
            return c;
        }

        // The hidden pictures the reveal copies from, drawn before the flight
        // starts so no moment of it costs extra: the country's grey shape
        // (finished borders and colours get painted on it as they finish)
        // and the finished country, which comes over it at the end, so the
        // last frame of the flight and the still map are the same picture.
        function prepare() {
            if (layers || !fitMain) {
                return;
            }
            layers = { done: makeLayer(), top: makeLayer(), area: mapArea() };
            drawLand(layers.done.getContext('2d'), fitMain, 1);
            drawCountry(layers.top.getContext('2d'));
            geo.main.forEach(function (g) {
                g.bDone = false;
                g.fDone = false;
            });
            finalPic = null;
        }

        // The dotted globe of the world view (the same land points and light
        // as brikpanel-dashboard.js, startLiteGlobe), with the world's order dots.
        var dotX = null, dotY = null, dotZ = null;
        var buckets = [[], [], [], [], [], [], [], [], [], [], [], []];
        function drawGlobe(c, sphereA, dotsA, marksA) {
            pixelsOn(ctx);
            var r = rot(c.phi, c.theta);
            if (sphereA > 0.01) {
                var gr = ctx.createRadialGradient(c.cx, c.cy, 0, c.cx, c.cy, c.R);
                for (var st = 0; st <= 12; st++) {
                    var rr = st / 12;
                    gr.addColorStop(rr, grey(opts.shade(Math.sqrt(Math.max(0, 1 - rr * rr)), 0)));
                }
                ctx.globalAlpha = sphereA;
                ctx.fillStyle = gr;
                // Only the grey ring: the globe is white inside two thirds of
                // its radius, as white as the card, and painting it cost a
                // full box of pixels a frame once the globe grew.
                ctx.beginPath();
                ctx.arc(c.cx, c.cy, c.R, 0, Math.PI * 2);
                ctx.arc(c.cx, c.cy, c.R * 0.66, 0, Math.PI * 2, true);
                ctx.fill();
            }
            if (dotsA > 0.01 && opts.land) {
                var land = opts.land, count = land.length / 4;
                if (!dotX) {
                    dotX = new Float32Array(count);
                    dotY = new Float32Array(count);
                    dotZ = new Float32Array(count);
                }
                // The dots keep their size: the camera leaves them behind
                // before they could grow into a coarse grid.
                var dot = 0.0062 * R0 * Math.min(1.2, Math.pow(Math.max(1, c.R / R0), 0.3));
                for (var b = 0; b < 12; b++) buckets[b].length = 0;
                for (var n = 0; n < count; n++) {
                    var o = n * 4;
                    var x = land[o], y = land[o + 1], z = land[o + 2];
                    var vz = -x * r.sp * r.cn + y * r.sn + z * r.cp * r.cn;
                    if (vz <= 0.06) continue;
                    var X = c.cx + (x * r.cp + z * r.sp) * c.R;
                    var Y = c.cy - (x * r.sp * r.sn + y * r.cn - z * r.cp * r.sn) * c.R;
                    if (X < -8 || X > W + 8 || Y < -8 || Y > H + 8) continue;
                    var lum = opts.shade(vz, land[o + 3] * 6 * Math.pow(vz, 1.2));
                    if (lum > 0.97) continue;
                    dotX[n] = X;
                    dotY[n] = Y;
                    dotZ[n] = vz;
                    buckets[Math.round(lum * 11)].push(n);
                }
                ctx.globalAlpha = dotsA;
                for (var k = 0; k < 12; k++) {
                    var list = buckets[k];
                    if (!list.length) continue;
                    ctx.fillStyle = grey(k / 11);
                    ctx.beginPath();
                    for (var e = 0; e < list.length; e++) {
                        var q = list[e];
                        // Round dots, smaller towards the rim: Chrome draws a
                        // turned ellipse five times slower than a circle
                        // (21 ms against 4 ms a frame for this globe).
                        var rd = dot * Math.max(0.35, dotZ[q]);
                        ctx.moveTo(dotX[q] + rd, dotY[q]);
                        ctx.arc(dotX[q], dotY[q], rd, 0, Math.PI * 2);
                    }
                    ctx.fill();
                }
            }
            if (marksA > 0.01 && opts.markers && opts.markers.length) {
                ctx.globalAlpha = marksA;
                ctx.fillStyle = '#1a1a1a';
                opts.markers.forEach(function (m) {
                    var p = xyz(m.location[0], m.location[1]);
                    var v = pr(p[0] * 1.02, p[1] * 1.02, p[2] * 1.02, r);
                    if (v[2] <= 0) return;
                    ctx.beginPath();
                    ctx.arc(c.cx + v[0] * c.R, c.cy - v[1] * c.R, Math.max(1.5, m.size * R0 * 0.55), 0, Math.PI * 2);
                    ctx.fill();
                });
            }
            ctx.globalAlpha = 1;
        }

        // How much of the globe is left as the camera comes in (zf: the
        // globe's size against the turned globe's, zp: how far the zoom got).
        function dotsAlpha(c) {
            return Math.min(1 - smooth(1.1, 2.2, c.zf), 1 - span(c.zp, 0.15, 0.5));
        }

        // The globe's soft rim stays a little longer and sweeps out past the
        // edges of the box: the sense of coming in after the dots are gone.
        function sphereAlpha(c) {
            return Math.min(1 - smooth(1.4, 3.4, c.zf), 1 - span(c.zp, 0.25, 0.7));
        }

        // Where the camera is at time ms of the flight in: turned towards the
        // country, then coming in until the country fills the box. The size
        // grows evenly in steps of "times as big", and the point the camera
        // looks at moves from the globe's middle to the country's middle with it.
        function camera(ms) {
            var turn = easeSoft(span(ms, tl.turn[0], tl.turn[1]));
            var zp = span(ms, tl.zoom[0], tl.zoom[1]);
            var e = easeSoft(zp);
            var R1 = R0 * 1.12;
            var Rs = R0 + (R1 - R0) * turn;
            var R = Rs * Math.pow(fitMain.R / Rs, e);
            return {
                phi: phiStart + wrap(geo.view[0] - phiStart) * turn,
                theta: thetaStart + (geo.view[1] - thetaStart) * turn,
                R: R,
                cx: W / 2 - fitMain.fx * e * R,
                cy: H / 2 + fitMain.fy * e * R,
                e: e,
                rs: Rs,
                zp: zp,
                zf: R / R1,
                turned: turn >= 1
            };
        }

        // A finished grey border, painted into the hidden picture.
        function bakeLine(d, g) {
            unitOn(d, fitMain);
            lines(d, LINE, 1.1, fitMain);
            d.stroke(g.path);
        }

        // Before the finished map comes over the hidden picture, the outer
        // half of the grey border along the coast goes (one cut along the
        // country's outline): the finished map covers the rest, so the
        // flight's last frame is the still map (a grey edge along the coast
        // used to vanish there). Cutting each border as it finished cost
        // 40 ms a frame.
        function trimOutside() {
            if (layers.trimmed || !geo.country) return;
            layers.trimmed = true;
            var d = layers.done.getContext('2d');
            unitOn(d, fitMain);
            d.globalCompositeOperation = 'destination-in';
            d.fillStyle = '#000';
            d.fill(geo.country, 'evenodd');
            d.globalCompositeOperation = 'source-over';
        }

        // The borders drawing in and the colours coming, once the camera stopped.
        function reveal(ms) {
            var d = layers.done.getContext('2d');
            var n = Math.max(1, geo.main.length - 1);
            var bw = tl.borders[1] - tl.borders[0] - BORDER_MS;
            var fw = tl.fills[1] - tl.fills[0] - FILL_MS;
            var moving = [];
            geo.main.forEach(function (g) {
                g.bp = clamp01((ms - tl.borders[0] - g.order / n * bw) / BORDER_MS);
                g.fa = clamp01((ms - tl.fills[0] - g.rank / n * fw) / FILL_MS);
                // What just finished is painted into the hidden picture, once.
                if (g.fa >= 1 && !g.fDone) {
                    g.fDone = true;
                    if (g.tiny) {
                        drawDot(d, g, fitMain, 1);
                    } else {
                        unitOn(d, fitMain);
                        d.fillStyle = STEPS[g.step];
                        d.fill(g.path, 'evenodd');
                        if (g.bDone) {
                            bakeLine(d, g);
                        }
                    }
                }
                if (g.bp >= 1 && !g.bDone) {
                    g.bDone = true;
                    if (!g.tiny) {
                        bakeLine(d, g);
                    }
                }
                if ((g.fa > 0 && !g.fDone) || (g.bp > 0 && !g.bDone)) {
                    moving.push(g);
                }
            });

            var a = layers.area;
            var white = span(ms, tl.white[0], tl.white[1]);
            if (white > 0) {
                trimOutside();
            }
            ctx.setTransform(1, 0, 0, 1, 0, 0);
            if (white < 1) {
                ctx.drawImage(layers.done, a[0], a[1], a[2], a[3], a[0], a[1], a[2], a[3]);
            }
            moving.forEach(function (g) {
                if (g.tiny) {
                    if (g.fa > 0 && !g.fDone) drawDot(ctx, g, fitMain, g.fa);
                    return;
                }
                unitOn(ctx, fitMain);
                if (g.fa > 0 && !g.fDone) {
                    ctx.globalAlpha = g.fa;
                    ctx.fillStyle = STEPS[g.step];
                    ctx.fill(g.path, 'evenodd');
                    ctx.globalAlpha = 1;
                    if (g.bDone) {
                        // The border stays on top of the colour coming in.
                        lines(ctx, LINE, 1.1, fitMain);
                        ctx.stroke(g.path);
                    }
                }
                if (g.bp > 0 && !g.bDone && g.len > 0) {
                    lines(ctx, LINE, 1.1, fitMain);
                    ctx.setLineDash([g.len * g.bp, g.len * 2]);
                    ctx.stroke(g.path);
                    ctx.setLineDash([]);
                }
            });
            // The finished country comes over it (its borders white), the far islands come in.
            if (white > 0) {
                ctx.setTransform(1, 0, 0, 1, 0, 0);
                ctx.globalAlpha = white;
                ctx.drawImage(layers.top, a[0], a[1], a[2], a[3], a[0], a[1], a[2], a[3]);
                ctx.globalAlpha = 1;
            }
            var late = span(ms, tl.late[0], tl.late[1]);
            if (late > 0) {
                drawBoxes(ctx, late);
            }
        }

        // One frame of the flight in.
        function frame(ms) {
            ctx.setTransform(1, 0, 0, 1, 0, 0);
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            if (ms >= tl.zoom[1]) {
                cam = { phi: geo.view[0], theta: geo.view[1], e: 1, rs: R0 * 1.12, turned: true };
                reveal(ms);
                return;
            }
            var c = camera(ms);
            cam = c;
            drawGlobe(c, sphereAlpha(c), dotsAlpha(c), 1 - span(ms, tl.marks[0], tl.marks[1]));
            // The country's own shape sits exactly on the dots only once the
            // globe faces it.
            var la = c.turned ? span(ms, tl.land[0], tl.land[1]) : 0;
            if (la > 0) {
                drawLand(ctx, c, la);
            }
        }

        function drawHover() {
            if (!hover) return;
            var f = hover.f;
            if (hover.g.tiny) {
                pixelsOn(ctx);
                ctx.beginPath();
                ctx.arc(f.cx + hover.g.lab[0] * f.R, f.cy - hover.g.lab[1] * f.R, DOT_PX + 1.5, 0, Math.PI * 2);
                ctx.lineWidth = 2;
                ctx.strokeStyle = HOVER;
                ctx.stroke();
                return;
            }
            unitOn(ctx, f);
            lines(ctx, HOVER, 2, f);
            ctx.stroke(hover.g.path);
        }

        // The finished map (and the region pointed at).
        function still() {
            ctx.setTransform(1, 0, 0, 1, 0, 0);
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            if (!geo || !fitMain) return;
            ctx.drawImage(finalPicture(), 0, 0);
            drawHover();
        }

        /* ---------------- motion */

        function stop() {
            if (raf) {
                window.cancelAnimationFrame(raf);
                raf = 0;
            }
        }

        function finish() {
            stop();
            done = true;
            cam = null;
            still();
        }

        // Fades the canvas in once its first picture is drawn (after the
        // browser has seen it invisible, or there is nothing to fade).
        function show() {
            if (shown) return;
            shown = true;
            window.requestAnimationFrame(function () {
                if (dead) return;
                canvas.style.opacity = '';
                if (opts.onShown) {
                    window.setTimeout(opts.onShown, 240);
                }
                whenSeen();
            });
        }

        // Runs the flight in from the globe turned to (fromPhi, fromTheta).
        function play(fromPhi, fromTheta) {
            stop();
            done = false;
            phiStart = fromPhi;
            thetaStart = fromTheta;
            var turnRad = Math.sqrt(Math.pow(wrap(geo.view[0] - fromPhi) * Math.cos(geo.view[1]), 2) + Math.pow(geo.view[1] - fromTheta, 2));
            tl = timeline(turnRad, fitMain.R / (R0 * 1.12));
            prepare();
            t = 0;
            var last = 0, wall = 0;
            var step = function (now) {
                raf = 0;
                if (dead) return;
                if (!last) {
                    last = now;
                    wall = now;
                }
                t += Math.min(STEP_MAX, Math.max(0, now - last));
                last = now;
                if (t >= tl.end) {
                    finish();
                    return;
                }
                // A machine that keeps less than half the pace gets the map.
                if (now - wall > 1500 && t < (now - wall) * 0.45) {
                    finish();
                    return;
                }
                frame(t);
                raf = window.requestAnimationFrame(step);
            };
            raf = window.requestAnimationFrame(step);
        }

        // The day's first look plays once the card is on screen, in a quiet
        // moment: the dashboard draws its other cards in the same breath.
        function whenSeen() {
            if (!pendingPlay || !visible || document.hidden || dead || !geo || !shown) return;
            pendingPlay = false;
            idle(function () {
                if (dead || done || raf) return;
                if (!visible || document.hidden) {
                    pendingPlay = true;
                    return;
                }
                markPlayed(opts.storeKey);
                play(phiStart, thetaStart);
            }, 700);
        }

        /* ---------------- pointer */

        function hit(clientX, clientY) {
            var rect = canvas.getBoundingClientRect();
            var x = clientX - rect.left, y = clientY - rect.top;
            var i, g, f;
            for (i = 0; i < geo.insets.length; i++) {
                f = fitBoxes[i];
                if (x < f.x || x > f.x + f.w || y < f.y || y > f.y + f.h) continue;
                for (var j = 0; j < geo.insets[i].items.length; j++) {
                    g = geo.insets[i].items[j];
                    if (onShape(g, f, x, y)) return { g: g, f: f };
                }
                return null;
            }
            for (i = 0; i < geo.main.length; i++) {
                g = geo.main[i];
                if (onShape(g, fitMain, x, y)) return { g: g, f: fitMain };
            }
            return null;
        }

        function onShape(g, f, x, y) {
            if (g.tiny) {
                var dx = x - (f.cx + g.lab[0] * f.R), dy = y - (f.cy - g.lab[1] * f.R);
                return dx * dx + dy * dy <= 64;
            }
            var ux = (x - f.cx) / f.R, uy = (f.cy - y) / f.R;
            if (ux < g.box[0] || ux > g.box[2] || uy < g.box[1] || uy > g.box[3]) return false;
            unitOn(ctx, f);
            return ctx.isPointInPath(g.path, x * dpr, y * dpr, 'evenodd');
        }

        function showTip(h, clientX, clientY) {
            if (!viz || !viz.tip) return;
            var row = values[h.g.key] || { count: 0, customers: 0, total: '' };
            var rows = [[opts.text.orders, opts.number(row.count || 0)], [opts.text.customers, opts.number(row.customers || 0)]];
            if (row.total) {
                rows.push([opts.text.sales, row.total]);
            }
            viz.tip.show({ title: (focus.names && focus.names[h.g.key]) || h.g.key, rows: rows }, { left: clientX, top: clientY - 4, width: 0, height: 8, bottom: clientY + 4 });
        }

        function point(e) {
            if (!done || !geo) return;
            var h = hit(e.clientX, e.clientY);
            var was = hover ? hover.g.key : '';
            hover = h;
            if ((h ? h.g.key : '') !== was) {
                still();
            }
            if (h) {
                showTip(h, e.clientX, e.clientY);
            } else if (viz && viz.tip) {
                viz.tip.hide();
            }
        }

        function leave(e) {
            if (e.pointerType && e.pointerType !== 'mouse') return;
            clearHover();
        }

        function clearHover() {
            if (viz && viz.tip) viz.tip.hide();
            if (hover) {
                hover = null;
                if (done) still();
            }
        }

        function outside(e) {
            if (hover && e.target !== canvas) clearHover();
        }

        canvas.addEventListener('pointermove', point);
        canvas.addEventListener('pointerdown', point);
        canvas.addEventListener('pointerleave', leave);
        document.addEventListener('pointerdown', outside);
        window.addEventListener('scroll', clearHover, { passive: true });

        function onVisibility() {
            whenSeen();
        }
        document.addEventListener('visibilitychange', onVisibility);

        if (window.IntersectionObserver) {
            io = new window.IntersectionObserver(function (entries) {
                visible = entries[0].isIntersecting;
                whenSeen();
            }, { threshold: 0.3 });
            io.observe(canvas);
        } else {
            visible = true;
        }

        var lastW = 0, lastH = 0;
        function resized() {
            if (dead) return;
            if (Math.abs(container.clientWidth - lastW) < 2 && Math.abs(container.clientHeight - lastH) < 2) return;
            lastW = container.clientWidth;
            lastH = container.clientHeight;
            size();
            hover = null;
            if (viz && viz.tip) viz.tip.hide();
            if (!geo) return;
            if (done) {
                still();
            } else if (raf) {
                // Mid flight: the rest of it at the new size.
                prepare();
            } else {
                frame(0);
            }
        }
        if (typeof window.ResizeObserver === 'function') {
            ro = new window.ResizeObserver(function () { window.requestAnimationFrame(resized); });
            ro.observe(container);
        } else {
            window.addEventListener('resize', resized);
        }

        size();
        lastW = container.clientWidth;
        lastH = container.clientHeight;

        var ready = load(opts.mapsUrl, focus.cc, focus.v).then(function (g) {
            if (dead) return;
            geo = g;
            fit();
            paint();
            var how = opts.animate;
            if (reduced || !how || (how === 'day' && playedToday(opts.storeKey))) {
                finish();
                show();
            } else if (how === 'switch') {
                // From the world globe as it stands: the same picture first,
                // faded in over it, then the flight.
                phiStart = opts.from ? opts.from[0] : geo.view[0] - 0.9;
                thetaStart = opts.from ? opts.from[1] : 0.3;
                tl = timeline(0, 1);
                frame(0);
                show();
                play(phiStart, thetaStart);
            } else {
                // The world globe stands still until the card is seen; the
                // hidden pictures of the reveal get drawn meanwhile (only for
                // a card on screen: one never scrolled to keeps no pictures).
                phiStart = geo.view[0] - 0.9;
                thetaStart = 0.3;
                tl = timeline(0, 1);
                frame(0);
                pendingPlay = true;
                show();
                idle(function () {
                    if (!dead && !done && visible) prepare();
                }, 1500);
            }
        });

        return {
            ready: ready,
            // New figures (another period, Orders / Customers): colours only, no animation.
            update: function (nextFocus, nextMetric) {
                if (nextFocus) focus = nextFocus;
                metric = nextMetric === 'customers' ? 'customers' : 'count';
                if (!geo) return;
                paint();
                hover = null;
                if (done) {
                    still();
                } else if (layers) {
                    // Mid flight: what is finished gets painted again in the new colours.
                    layers = null;
                    prepare();
                }
            },
            // Back to the world: the map flies out into the globe facing the
            // country, then cb(phi, theta) starts the world globe right there.
            reverse: function (cb) {
                pendingPlay = false;
                if (!geo || reduced || !fitMain) {
                    stop();
                    cb(geo ? geo.view[0] : null, geo ? geo.view[1] : null);
                    return;
                }
                // From where the camera is: mid flight in, or the finished map.
                if (!done && cam && cam.e >= 1) {
                    finish();
                }
                var from = done ? { phi: geo.view[0], theta: geo.view[1], e: 1, rs: R0 * 1.12, turned: true } : (cam || { phi: phiStart, theta: thetaStart, e: 0, rs: R0, turned: false });
                var colours = done;
                var picture = colours ? finalPicture() : null;
                var R1 = R0 * 1.12;
                stop();
                hover = null;
                if (viz && viz.tip) viz.tip.hide();
                done = false;
                var t0 = 0;
                var step = function (now) {
                    raf = 0;
                    if (dead) return;
                    if (!t0) t0 = now;
                    var ms = Math.min(REVERSE_MS, now - t0);
                    var k = easeSoft(span(ms, 60, REVERSE_MS - 50));
                    var e = from.e * (1 - k);
                    var Rb = from.rs + (R0 - from.rs) * k;
                    var R = Rb * Math.pow(fitMain.R / Rb, e);
                    var c = { phi: from.phi, theta: from.theta, R: R, cx: W / 2 - fitMain.fx * e * R, cy: H / 2 + fitMain.fy * e * R, e: e, zp: e, zf: R / R1 };
                    ctx.setTransform(1, 0, 0, 1, 0, 0);
                    ctx.clearRect(0, 0, canvas.width, canvas.height);
                    drawGlobe(c, sphereAlpha(c), dotsAlpha(c), span(ms, REVERSE_MS - 300, REVERSE_MS - 40));
                    if (from.turned) {
                        var la = smooth(0.03, 0.2, e);
                        if (la > 0) drawLand(ctx, c, la);
                    }
                    // The coloured map shrinks with the camera while it fades.
                    var fade = colours ? 1 - span(ms, 0, 260) : 0;
                    if (fade > 0) {
                        var s = R / fitMain.R;
                        ctx.setTransform(s, 0, 0, s, dpr * (c.cx - fitMain.cx * s), dpr * (c.cy - fitMain.cy * s));
                        ctx.globalAlpha = fade;
                        ctx.drawImage(picture, 0, 0);
                        ctx.globalAlpha = 1;
                    }
                    if (ms >= REVERSE_MS) {
                        // Finished: only its last picture stays, until destroy().
                        dead = true;
                        cb(from.phi, from.theta);
                        return;
                    }
                    raf = window.requestAnimationFrame(step);
                };
                raf = window.requestAnimationFrame(step);
            },
            // Gone at once, or (fade) faded out over what took its place.
            destroy: function (fade) {
                dead = true;
                stop();
                if (io) io.disconnect();
                if (ro) ro.disconnect();
                canvas.removeEventListener('pointermove', point);
                canvas.removeEventListener('pointerdown', point);
                canvas.removeEventListener('pointerleave', leave);
                window.removeEventListener('resize', resized);
                window.removeEventListener('scroll', clearHover);
                document.removeEventListener('pointerdown', outside);
                document.removeEventListener('visibilitychange', onVisibility);
                if (viz && viz.tip) viz.tip.hide();
                layers = null;
                finalPic = null;
                var gone = function () {
                    if (canvas.parentNode) canvas.parentNode.removeChild(canvas);
                };
                if (fade && !reduced && shown) {
                    canvas.style.pointerEvents = 'none';
                    canvas.style.opacity = '0';
                    window.setTimeout(gone, 280);
                } else {
                    gone();
                }
            }
        };
    }

    window.brikpanelRegionMap = {
        open: open,
        // The map file fetched and built ahead, so the switch from the world is instant.
        preload: function (mapsUrl, cc, version) {
            return load(mapsUrl, cc, version);
        }
    };
})(window, document);
