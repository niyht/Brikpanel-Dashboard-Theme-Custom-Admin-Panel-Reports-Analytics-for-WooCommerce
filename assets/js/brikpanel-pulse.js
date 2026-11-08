/**
 * BrikPanel storefront visit counter.
 *
 * Before 3.3.48 this code was printed inside every storefront page, 26 KB the
 * browser could not keep between pages. Now it is a file the browser caches;
 * only the page's own values come with the page, as a JSON block the browser
 * never runs (<script type="application/json" id="brikpanel-tracker-cfg">,
 * brikpanel_unified_tracker_tag() in back-end/tracking/brikpanel-unified-tracker.php).
 * The file reads it by id, so a speed plugin that bundles this file or moves
 * it elsewhere on the page does not lose the settings, and a strict
 * Content-Security-Policy that blocks inline scripts does not stop it.
 *
 * What it does, the person check, the idle limit and the consent gate are
 * described there, above brikpanel_unified_tracker_config(). The server side
 * is brikpanel_ajax_unified_track() (tv=2), unchanged: pages cached with the
 * old inline code keep counting.
 *
 * Settings shape: { v: 1, endpoint, isProduct, pageId, pageType, livePage,
 * day, humanCookie, liveSource, requireConsent, consentCat, consentCookie,
 * countOnOpen, interval, idle }. A missing key falls back to the old default,
 * so a page cached with an older shape still works with a newer file.
 */
(function() {
    function readConfig() {
        var el = document.getElementById('brikpanel-tracker-cfg');
        if (!el) return null;
        try {
            var c = JSON.parse(el.textContent || '');
            return (c && typeof c === 'object' && c.endpoint) ? c : null;
        } catch (e) {
            return null;
        }
    }

    function boot(C) {
        var endpoint   = String(C.endpoint || '');
        var isProduct  = !!C.isProduct;
        var pageId     = parseInt(C.pageId, 10) || 0;
        var pageType   = String(C.pageType || '');
        var LIVE_PAGE  = String(C.livePage || '');

        // The day this page was built. Only a fallback: a cached page can be
        // days old, so the store's real day comes from the person mark.
        var PAGE_DAY     = String(C.day || '');
        var CAMPAIGN_KEY = 'brikpanel_campaign_viewed';
        var HUMAN_COOKIE = String(C.humanCookie || 'brikpanel_human');

        // Where this visit came from, for the Live visitors list.
        var LIVE_SOURCE = C.liveSource !== false;
        var ENTRY_KEY   = 'brikpanel_entry_src';
        var entry       = null;

        // Consent gate. When true nothing is sent, read or written until the
        // visitor allows analytics.
        var REQUIRE_CONSENT = !!C.requireConsent;
        var CONSENT_CAT     = String(C.consentCat || 'statistics');
        var CONSENT_COOKIE  = String(C.consentCookie || 'brikpanel_consent');

        // Count a visitor as soon as the page is on screen, before any input
        // (3.3.41). The server decides; this only says whether to ask.
        var COUNT_ON_OPEN = !!C.countOnOpen;

        var running   = false;
        var timer     = null;
        var forgotten = false;
        var proven    = false;   // the server counted this page as a person's
        var asking    = false;   // waiting for a person's first input event
        var shownWait = false;   // waiting for the page to be on screen
        var openCounted  = false; // this page view was counted when it opened
        var openInFlight = false; // the page-open request has not answered yet
        var personQueued = false; // a person touched the page meanwhile

        // Idle limit: see the docblock above brikpanel_unified_tracker_config().
        var INTERVAL_MS = parseInt(C.interval, 10) || 30000;
        var IDLE_MS     = parseInt(C.idle, 10) || 1800000;
        var listening   = false;
        var lastActive  = Date.now();
        var lastX = null, lastY = null;

        function idleFor() {
            return Date.now() - lastActive;
        }

        function readCookie(name) {
            var parts = document.cookie ? document.cookie.split(';') : [];
            for (var i = 0; i < parts.length; i++) {
                var p = parts[i].trim();
                if (p.indexOf(name + '=') === 0) return p.slice(name.length + 1);
            }
            return '';
        }

        // The person mark, when this browser has one. Its shape only: the
        // server checks the signature.
        function humanMark() {
            var v = readCookie(HUMAN_COOKIE);
            return /^1\.\d{4}-\d{2}-\d{2}\.[vpac]{0,4}\.[0-9a-f]{20}$/.test(v) ? v : '';
        }

        // The store's day as the server last said it, else the page's. Read
        // from the person mark or from a page counted on open ("0." in front,
        // 3.3.41), which says nothing about being a person.
        function storeDay() {
            var m = /^[01]\.(\d{4}-\d{2}-\d{2})\.[vpac]{0,4}\.[0-9a-f]{20}$/.exec(readCookie(HUMAN_COOKIE));
            return m ? m[1] : PAGE_DAY;
        }

        // A browser driven by automation says so itself; a person's browser
        // never does.
        function automated() {
            try {
                if (navigator.webdriver === true) return true;
                if (/HeadlessChrome/.test(navigator.userAgent || '')) return true;
                var brands = navigator.userAgentData && navigator.userAgentData.brands;
                if (brands && brands.length) {
                    for (var i = 0; i < brands.length; i++) {
                        if (/Headless/i.test((brands[i] && brands[i].brand) || '')) return true;
                    }
                }
                if (navigator.languages && navigator.languages.length === 0) return true;
            } catch (e) {}
            return false;
        }

        function buildLive(fd) {
            fd.append('live', '1');
            fd.append('page_url', window.location.href);
            fd.append('idle', String(Math.max(0, Math.floor(idleFor() / 1000))));
            // Which page this is, so the Live list can show its name. Not
            // page_id: that one counts a page view, once per page load.
            if (LIVE_PAGE) fd.append('live_page', LIVE_PAGE);
            if (REQUIRE_CONSENT) fd.append('consent', '1');
            // Sent with every ping, not once: the live row is rewritten on each
            // ping and can expire while the tab sits in the background.
            if (entry) {
                fd.append('src_ref', entry.r || '');
                fd.append('src_url', entry.u || '');
            }
        }

        // The visit's entry: the referring page and the address it landed on
        // (which carries any campaign tags). Remembered for this tab, so every
        // later page can still say where the visitor came from. A new entry
        // starts when there is none yet, when the address carries a campaign
        // or ad-click tag, or when the visitor comes back from another site
        // after half an hour away. A mid-visit return from another site (a
        // payment page, say) keeps the original source. With nothing stored
        // and a referrer from this very site (consent given on a later page)
        // the source is unknown, so nothing is sent. Runs from start() only,
        // so nothing is stored before the visitor allows analytics.
        function captureEntry() {
            if (!LIVE_SOURCE) {
                try { sessionStorage.removeItem(ENTRY_KEY); } catch (e) {}
                return null;
            }
            var now = Date.now(), saved = null;
            try { saved = JSON.parse(sessionStorage.getItem(ENTRY_KEY) || 'null'); } catch (e) {}
            if (!saved || typeof saved !== 'object') saved = null;
            var ref = '', refHost = '', here = '';
            try {
                ref = document.referrer || '';
                here = window.location.hostname.replace(/^www\./, '');
                if (ref) refHost = new URL(ref).hostname.replace(/^www\./, '');
            } catch (e) {}
            var external = !!refHost && refHost !== here && refHost.slice(-(here.length + 1)) !== '.' + here;
            var tagged = /[?&](utm_[a-z]+|gclid|gclsrc|gbraid|wbraid|msclkid|fbclid|ttclid)=/i.test(window.location.search || '');
            var idle = !saved || !saved.t || (now - saved.t) > 1800000;
            if (!saved || tagged || (external && idle)) {
                if (!saved && !tagged && ref && !external) return null;
                saved = {
                    r: external ? String(ref).slice(0, 1000) : '',
                    u: String(window.location.href).slice(0, 2000)
                };
            }
            saved.t = now;
            try { sessionStorage.setItem(ENTRY_KEY, JSON.stringify(saved)); } catch (e) {}
            return saved;
        }

        // Whether a consent platform is actually driving the Consent API.
        // With the API installed but no banner configured this is empty, and
        // wp_has_consent() then answers "allow" for everything — its
        // documented "nobody is asking, so nothing is denied" stance. Mirrors
        // the same test on the PHP side so the two can never disagree.
        function consentTypeDefined() {
            var type = '';
            try {
                if (typeof consent_api !== 'undefined' && consent_api && consent_api.consent_type) {
                    type = consent_api.consent_type;
                }
            } catch (e) {}
            return !!(type || window.wp_consent_type || window.wp_fallback_consent_type);
        }

        // The Consent API's own decision cookie for our category, or '' when
        // the banner has not written one yet. Several popular banners set it
        // from JavaScript without ever declaring a consent type, so it is the
        // only durable evidence they leave behind.
        function consentApiCookie() {
            var prefix = 'wp_consent';
            try {
                if (typeof consent_api !== 'undefined' && consent_api && consent_api.cookie_prefix) {
                    prefix = consent_api.cookie_prefix;
                }
            } catch (e) {}
            return readCookie(prefix + '_' + CONSENT_CAT);
        }

        // Same order as brikpanel_consent_granted() in PHP: our own record,
        // then the banner's decision cookie, then the API itself but only
        // while a real banner is driving it.
        function consentGranted() {
            if (readCookie(CONSENT_COOKIE) === '1') return true;
            if (typeof wp_has_consent !== 'function') return false;
            var decided = consentApiCookie();
            if (decided) return decided === 'allow';
            if (consentTypeDefined()) {
                try { return !!wp_has_consent(CONSENT_CAT); } catch (e) {}
            }
            return false;
        }

        // The campaign of this address, read the way WooCommerce's own order
        // attribution reads it, so a visit and the order it leads to carry the
        // same name: utm_campaign, else google_cpc for a Google Ads click id and
        // yandex_cpc for a Yandex one; decoded once, a "+" kept as it is.
        function landingCampaign() {
            var q = window.location.search || '';
            if (q.length < 2) return '';
            var found = Object.create(null);
            var pairs = q.slice(1).split('&');
            for (var i = 0; i < pairs.length; i++) {
                var eq = pairs[i].indexOf('=');
                var key = eq === -1 ? pairs[i] : pairs[i].slice(0, eq);
                if (key && !(key in found)) found[key] = eq === -1 ? '' : pairs[i].slice(eq + 1);
            }
            var raw = found.utm_campaign || '';
            if (!raw && found.gclid) raw = 'google_cpc';
            if (!raw && found.yclid) raw = 'yandex_cpc';
            try { raw = decodeURIComponent(raw); } catch (e) {}
            raw = String(raw).replace(/\s+/g, ' ').trim();
            return raw === '(none)' ? '' : raw.slice(0, 100);
        }

        // Each campaign counts once a day per browser. One key holds the
        // store day's list (at most 20 names), so yesterday's names go when
        // the day turns instead of piling up a key each.
        function campaignDue(name) {
            if (!name) return false;
            try {
                var seen = JSON.parse(localStorage.getItem(CAMPAIGN_KEY) || 'null');
                if (!seen || seen.d !== storeDay() || !Array.isArray(seen.c)) return true;
                return seen.c.indexOf(name.toLowerCase()) === -1;
            } catch (e) {
                return true;
            }
        }

        function campaignCounted(name, day) {
            try {
                var seen = JSON.parse(localStorage.getItem(CAMPAIGN_KEY) || 'null');
                var list = seen && seen.d === day && Array.isArray(seen.c) ? seen.c : [];
                list.push(name.toLowerCase());
                localStorage.setItem(CAMPAIGN_KEY, JSON.stringify({ d: day, c: list.slice(-20) }));
            } catch (e) {}
        }

        // Latches of trackers before 3.3.30 (one key per day, never cleaned).
        function dropOldLatches() {
            try {
                var keys = [];
                for (var i = 0; i < localStorage.length; i++) {
                    var k = localStorage.key(i);
                    if (k && /^brikpanel_(?:visitor_viewed_|product_viewed_)/.test(k)) keys.push(k);
                }
                for (var j = 0; j < keys.length; j++) localStorage.removeItem(keys[j]);
            } catch (e) {}
        }

        function startPings() {
            if (timer || !running) return;
            timer = setInterval(pingLive, INTERVAL_MS);
        }

        // One combined request per page view: live ping, page view, visitor,
        // product view and campaign. `human`: sent because a person just
        // touched the page (asks the server for the mark). `open`: the page
        // is on screen and nobody touched it yet (3.3.41, "Count visitors
        // when the page opens"); no live ping, the Live list keeps the
        // people who touched.
        function sendCombined(human, open) {
            if (!running) return;
            var fd = new FormData();
            fd.append('action', 'brikpanel_unified_track');
            fd.append('tv', '2');
            if (human) fd.append('human', '1');
            if (open) {
                fd.append('open', '1');
                if (REQUIRE_CONSENT) fd.append('consent', '1');
                openInFlight = true;
            } else {
                buildLive(fd);
            }
            // Once per page view: not again after it was counted on open.
            if (pageId && !openCounted) {
                fd.append('page_id', pageId);
                fd.append('page_type', pageType);
            }
            // Always asked; the server counts each once per store day.
            fd.append('visitor', '1');
            try {
                fd.append('ref', document.referrer || '');
                fd.append('url', window.location.href || '');
            } catch (e) {}
            if (isProduct) fd.append('product', '1');
            var campaign = landingCampaign();
            var wantCampaign = campaignDue(campaign);
            if (wantCampaign) fd.append('campaign', campaign);

            fetch(endpoint, {
                method: 'POST',
                credentials: 'same-origin',
                body: fd,
                keepalive: true
            }).then(function(res) {
                return res.ok ? res.json() : null;
            }).then(function(json) {
                answered(json && json.success && json.data ? json.data : null, open, wantCampaign, campaign);
            }).catch(function() {
                answered(null, open, false, '');
            });
        }

        // The server's answer. A person who touched the page while the
        // page-open request was on its way is sent only now, once that
        // answer's cookie says what was counted, so the visitor is not
        // counted a second time.
        function answered(d, open, wantCampaign, campaign) {
            if (open) openInFlight = false;
            if (d && d.human === 'ok') {
                personQueued = false;
                proven = true;
                stopAsking();
                startPings();
                if (wantCampaign && d.campaign) campaignCounted(campaign, d.day || storeDay());
                return;
            }
            if (d && d.human === 'open') {
                if (d.pv) openCounted = true;
                if (wantCampaign && d.campaign) campaignCounted(campaign, d.day || storeDay());
            }
            if (personQueued) {
                personQueued = false;
                sendCombined(true);
                return;
            }
            if (d && d.human === 'need') {
                proven = false;
                askPerson();
            }
        }

        // Recurring live ping for tabs that stay open — live part only, and
        // only for a page already counted as a person's.
        function pingLive() {
            if (!proven) return;
            if (document.visibilityState === 'hidden') return;
            if (idleFor() >= IDLE_MS) return;
            var fd = new FormData();
            fd.append('action', 'brikpanel_unified_track');
            fd.append('tv', '2');
            buildLive(fd);
            fetch(endpoint, {
                method: 'POST',
                credentials: 'same-origin',
                body: fd,
                keepalive: true
            }).catch(function() {});
        }

        // An add-to-cart made before this page was counted as a person's (the
        // add button was the very first touch) waits in the shopper's session
        // on the server. Any counted request releases it, so after an add the
        // next one is sent shortly instead of at the next 30-second ping.
        var flushTries = 0;
        function flushSoon() {
            if (!running) return;
            if (!proven) {
                if (++flushTries <= 6) setTimeout(flushSoon, 1500);
                return;
            }
            flushTries = 0;
            pingLive();
        }
        function onCartAdd() {
            flushTries = 0;
            setTimeout(flushSoon, 1200);
        }
        function listenCart() {
            // Classic and theme AJAX buttons fire WooCommerce's jQuery event;
            // the block cart fires its own DOM event.
            if (window.jQuery) {
                window.jQuery(document.body).on('added_to_cart', onCartAdd);
            }
            document.body.addEventListener('wc-blocks_added_to_cart', onCartAdd);
        }

        // Wait for the first real input of a person, then send once.
        // Scripted events (isTrusted false) and a pointer that did not move
        // do not count. Scroll is left out on purpose: a page can scroll
        // itself, while a person scrolls with a wheel, a touch or a key.
        // The end of a touch or click counts too: when a speed plugin starts
        // this script during the touch that woke it, the rest of that touch
        // is all that is left to catch.
        var PERSON_EVENTS = ['pointerdown', 'pointermove', 'touchstart', 'wheel', 'keydown',
            'touchmove', 'touchend', 'pointerup', 'mousedown', 'click'];
        var px = null, py = null;
        function onPerson(e) {
            if (!asking || !e || e.isTrusted === false) return;
            if (e.type === 'pointermove') {
                if (px === null) { px = e.screenX; py = e.screenY; return; }
                if (e.screenX === px && e.screenY === py) return;
            }
            stopAsking();
            // The page-open request is still on its way: sent when it answers.
            if (openInFlight) {
                personQueued = true;
                return;
            }
            sendCombined(true);
        }
        function askPerson() {
            if (asking || !running) return;
            asking = true;
            px = null; py = null;
            for (var i = 0; i < PERSON_EVENTS.length; i++) {
                document.addEventListener(PERSON_EVENTS[i], onPerson, { capture: true, passive: true });
            }
        }
        function stopAsking() {
            if (!asking) return;
            asking = false;
            for (var i = 0; i < PERSON_EVENTS.length; i++) {
                document.removeEventListener(PERSON_EVENTS[i], onPerson, true);
            }
        }

        // Whether a person already tapped, clicked or pressed a key on this
        // page before this script started. A speed plugin that holds scripts
        // back until the first touch starts this one only after it, and that
        // touch never reaches the listeners above. The browser keeps the
        // answer itself, and only a real input sets it, never a script.
        function touchedBefore() {
            try {
                return !!(navigator.userActivation && navigator.userActivation.hasBeenActive);
            } catch (e) {}
            return false;
        }

        // Nothing is sent for a page nobody is looking at: a prerender the
        // visitor has not opened, or a tab still in the background.
        function onShown() {
            if (!shownWait || !running) return;
            if (document.prerendering) return;
            if (document.visibilityState === 'hidden') return;
            shownWait = false;
            document.removeEventListener('visibilitychange', onShown);
            document.removeEventListener('prerenderingchange', onShown);
            if (humanMark()) {
                sendCombined(false);
            } else if (touchedBefore()) {
                sendCombined(true);
            } else {
                // Counted now when the store asks for it; the person mark
                // still waits for a real input.
                if (COUNT_ON_OPEN) sendCombined(false, true);
                askPerson();
            }
        }
        function whenShown() {
            shownWait = true;
            document.addEventListener('visibilitychange', onShown);
            document.addEventListener('prerenderingchange', onShown);
            onShown();
        }

        // Whether this browser carries anything of ours worth erasing.
        //
        // Several banners (CookieYes among them) announce "deny" for every
        // category on the very first page load, before the visitor has
        // touched anything. Treating that as a withdrawal would fire an
        // erase request on every page view of every non-consenting visitor —
        // the exact "no requests before consent" promise this feature makes.
        // A deny with nothing to erase is a no-op.
        //
        // brikpanel_vid is HttpOnly and therefore invisible here, but it is
        // never created without one of the signals below also being created,
        // so checking these is equivalent in practice.
        function hasFootprint() {
            if (readCookie(CONSENT_COOKIE) === '1') return true;
            if (readCookie(HUMAN_COOKIE)) return true;
            try {
                for (var i = 0; i < localStorage.length; i++) {
                    var k = localStorage.key(i);
                    if (k && /^brikpanel_(?:visitor_viewed_|product_viewed_|campaign_viewed$)/.test(k)) return true;
                }
            } catch (e) {}
            try { if (sessionStorage.getItem(ENTRY_KEY)) return true; } catch (e) {}
            return false;
        }

        // Drop every latch this browser owns: after a withdrawal nothing
        // BrikPanel wrote may survive. (The cookies go with the erase request.)
        function clearLatches() {
            try {
                var keys = [];
                for (var i = 0; i < localStorage.length; i++) {
                    var k = localStorage.key(i);
                    if (k && /^brikpanel_(?:visitor_viewed_|product_viewed_|campaign_viewed$)/.test(k)) keys.push(k);
                }
                for (var j = 0; j < keys.length; j++) localStorage.removeItem(keys[j]);
            } catch (e) {}
            entry = null;
            try { sessionStorage.removeItem(ENTRY_KEY); } catch (e) {}
        }

        // Ask the server to expire our cookies and drop this browser from the
        // live-visitor list. It only ever touches identifiers the request
        // itself carries — the daily totals are anonymous and stay put.
        function sendForget() {
            var fd = new FormData();
            fd.append('action', 'brikpanel_forget');
            fetch(endpoint, {
                method: 'POST',
                credentials: 'same-origin',
                body: fd,
                keepalive: true
            }).catch(function() {});
        }

        // Someone touched the page: back from idle, ping now and restart the interval.
        function markActive(e) {
            if (e && e.isTrusted === false) return;
            if (e && e.type === 'pointermove') {
                if (e.screenX === lastX && e.screenY === lastY) return;
                lastX = e.screenX; lastY = e.screenY;
            }
            var wasIdle = idleFor() >= IDLE_MS;
            lastActive = Date.now();
            if (wasIdle && running && proven && document.visibilityState !== 'hidden') {
                pingLive();
                if (timer) clearInterval(timer);
                timer = setInterval(pingLive, INTERVAL_MS);
            }
        }

        function markShown(e) {
            if (document.visibilityState === 'hidden') return;
            if (e && e.type === 'pageshow' && !e.persisted) return;
            markActive(e);
        }

        function listen() {
            if (listening) return;
            listening = true;
            listenCart();
            var types = ['pointerdown', 'pointermove', 'keydown', 'wheel', 'touchstart', 'click'];
            for (var i = 0; i < types.length; i++) {
                document.addEventListener(types[i], markActive, { capture: true, passive: true });
            }
            // Page scroll only: a box that scrolls itself must not count.
            window.addEventListener('scroll', markActive, { passive: true });
            document.addEventListener('visibilitychange', markShown);
            document.addEventListener('prerenderingchange', markShown);
            window.addEventListener('pageshow', markShown);
        }

        // Idempotent: consent platforms routinely fire their change event more
        // than once, and the jQuery fallback below can double up with the
        // native listener.
        function start() {
            if (running) return;
            // Puppeteer, Playwright, Selenium and headless browsers: nothing is
            // sent, so a scripted crawl cannot become a visitor, a live entry
            // or a product view.
            if (automated()) return;
            running    = true;
            forgotten  = false;
            lastActive = Date.now();
            listen();
            dropOldLatches();
            entry      = captureEntry();
            whenShown();
        }

        // Clearing the interval is the point: without it a withdrawal would
        // keep pinging for as long as the tab stays open. The `forgotten`
        // latch matters just as much — binding both the native and the jQuery
        // consent event means a single "deny" can arrive twice, and erasing
        // twice would fire a second pointless request every time.
        function stop(forget) {
            if (timer) { clearInterval(timer); timer = null; }
            var wasRunning = running;
            running = false;
            proven  = false;
            asking  = false;
            if (!forget || forgotten) return;
            // Nothing was ever started and nothing of ours is stored: this is
            // a banner announcing its default state, not a withdrawal.
            if (!wasRunning && !hasFootprint()) return;
            forgotten = true;
            clearLatches();
            sendForget();
        }

        /**
         * Public API for cookie-consent platforms.
         *
         * Call brikpanel_start_tracking() once the visitor allows analytics
         * and brikpanel_stop_tracking() when they withdraw. Both are safe to
         * call repeatedly and neither needs a page reload.
         */
        window.brikpanel_start_tracking = function() { start(); };
        window.brikpanel_stop_tracking  = function() { stop(true); };

        function onReady(fn) {
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', fn);
            } else {
                fn();
            }
        }

        // The WP Consent API payload is built as an array with a string
        // property, so hasOwnProperty is the only safe way to test it — a
        // bare lookup can hit Array.prototype members.
        function onConsentChange(e, extra) {
            var detail = (e && e.detail) || extra;
            if (!detail || !Object.prototype.hasOwnProperty.call(detail, CONSENT_CAT)) return;
            if (detail[CONSENT_CAT] === 'allow') { start(); } else { stop(true); }
        }

        if (!REQUIRE_CONSENT) {
            onReady(start);
        } else {
            // Already-consented revisit: start without waiting for an event.
            onReady(function() { if (consentGranted()) start(); });

            // Fired by the CMP once it knows which regime applies (geo-ip
            // lookups resolve after page load).
            document.addEventListener('wp_consent_type_defined', function() {
                if (consentGranted()) { start(); } else { stop(false); }
            });

            // Current WP Consent API dispatches a native CustomEvent. Older
            // and forked builds trigger it through jQuery, which never reaches
            // addEventListener, so bind both — start()/stop() are idempotent.
            document.addEventListener('wp_listen_for_consent_change', onConsentChange);
            // jQuery may come after this file now that it is a file of its own:
            // bind as soon as it is there.
            var jqBound = false;
            var bindJq = function() {
                if (jqBound || !window.jQuery) return;
                jqBound = true;
                window.jQuery(document).on('wp_listen_for_consent_change', onConsentChange);
            };
            bindJq();
            if (!jqBound) {
                document.addEventListener('DOMContentLoaded', bindJq);
                window.addEventListener('load', bindJq);
            }
        }
    }

    var booted = false;
    function tryBoot() {
        if (booted) return;
        var c = readConfig();
        if (!c) return;
        booted = true;
        boot(c);
    }

    tryBoot();
    // A speed plugin may have moved this file above the settings block:
    // look again once the page is parsed.
    if (!booted && document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', tryBoot);
    }
})();
