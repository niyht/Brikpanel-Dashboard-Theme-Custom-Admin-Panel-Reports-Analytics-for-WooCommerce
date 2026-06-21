/**
 * BrikPanel Global Topbar
 *
 * - Relocates the BrikPanel search overlay out of #wpadminbar so it still
 *   opens when the admin bar is hidden.
 * - Wires dropdown menus (Create, notifications, user).
 * - Polls the `brikpanel_topbar_stats` endpoint every 30s for today's
 *   revenue / orders / conversion / live visitors / pending order counts.
 *
 * @since 2.2.3
 */
(function () {
    'use strict';

    var topbarInterval = null;

    document.addEventListener('DOMContentLoaded', function () {
        initTopbar();

        // Pause polling when tab is hidden to save resources.
        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState === 'hidden') {
                stopTopbarPolling();
            } else {
                fetchTopbarStats();
                startTopbarPolling();
            }
        });
    });

    function initTopbar() {
        var topbar = document.getElementById('brikpanel-topbar');
        if (!topbar) return;

        initMobileMenu();

        // Ctrl/Cmd key label inside the search chip.
        var modKey = document.getElementById('brikpanel-topbar-kbd-mod');
        if (modKey) {
            var isMac = false;
            if (navigator.userAgentData) {
                isMac = navigator.userAgentData.platform === 'macOS';
            } else {
                isMac = /Mac|iPod|iPhone|iPad/.test(navigator.userAgent);
            }
            modKey.innerHTML = isMac ? '&#8984;' : 'Ctrl';
        }

        // Relocate the BrikPanel search overlay out of #wpadminbar (which is
        // display:none when the topbar is active) so Ctrl+K and our button
        // can still open it.
        var overlay = document.querySelector('.brikpanel-search-overlay');
        if (overlay && overlay.parentElement && overlay.parentElement.closest('#wpadminbar')) {
            document.body.appendChild(overlay);
        }

        // Search trigger → open the existing BrikPanel search modal.
        var searchBtn = document.getElementById('brikpanel-topbar-search');
        if (searchBtn) {
            searchBtn.addEventListener('click', function () {
                var ov = document.querySelector('.brikpanel-search-overlay');
                var input = document.querySelector('.brikpanel-search-modal input');
                if (ov) ov.classList.remove('hidden');
                if (input) input.focus();
            });
        }

        // Dropdown toggles.
        var toggles = topbar.querySelectorAll('[data-topbar-toggle]');
        toggles.forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.stopPropagation();
                var menu = btn.closest('.brikpanel-topbar-menu');
                if (!menu) return;
                var isOpen = menu.classList.contains('is-open');
                closeAllTopbarMenus();
                if (!isOpen) {
                    menu.classList.add('is-open');
                    btn.setAttribute('aria-expanded', 'true');
                }
            });
        });

        document.addEventListener('click', function (e) {
            if (!e.target.closest('.brikpanel-topbar-menu')) {
                closeAllTopbarMenus();
            }
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') closeAllTopbarMenus();
        });

        initCacheClear();
        initHiddenNotices(topbar);

        fetchTopbarStats();
        startTopbarPolling();
    }

    /**
     * Hidden third-party notices.
     *
     * BrikPanel suppresses other plugins'/themes' admin notices and parks them
     * in an off-screen holder (#brikpanel-foreign-notices-holder, rendered by
     * brikpanel_render_hidden_notices_box() in the page body). The topbar
     * renders on `in_admin_header` — before those notices exist — so we relocate
     * them here, once the DOM is ready: move each notice into the topbar panel,
     * stamp the badge count, and reveal the otherwise-hidden button.
     *
     * If no notices were suppressed the holder is absent and the button stays
     * hidden. On admin screens without the topbar, the holder renders as a
     * self-contained <details> fallback instead (see brikpanel.php).
     */
    function initHiddenNotices(topbar) {
        var menu = topbar.querySelector('[data-topbar-menu="hidden-notices"]');
        if (!menu) return;

        var panelList = menu.querySelector('.brikpanel-fn-panel-list');
        if (!panelList) return;

        // Count flavour: the modern .notice family, the legacy .updated/.error
        // containers, and the core update nag (.update-nag, sometimes printed
        // without a .notice class).
        var COUNT_SEL = '.notice, .updated, .error, .update-nag';
        var CHILD_SEL = ':scope > .notice, :scope > .updated, :scope > .error, :scope > .update-nag';

        // 1) Notices BrikPanel collected server-side wait in an off-screen
        //    holder rendered into the page body. Move them into the panel and
        //    mark them ours so the declutter rules (all `:not(.brikpanel-notice)`)
        //    keep them visible here.
        var holder = document.getElementById('brikpanel-foreign-notices-holder');
        if (holder) {
            var source = holder.querySelector('.brikpanel-fn-list') || holder;
            Array.prototype.forEach.call(source.querySelectorAll(CHILD_SEL), function (n) {
                if (isErrorNotice(n)) return;     // red/error notices stay on screen
                if (!noticeHasContent(n)) return; // skip empty placeholder wrappers
                n.classList.add('brikpanel-notice');
                panelList.appendChild(n);
            });
            holder.parentNode && holder.parentNode.removeChild(holder);
        }

        var badge = menu.querySelector('.brikpanel-topbar-badge');

        // Sync the badge with the live notice count, and retire the button once
        // every notice has been dismissed (WP core removes each .notice node on
        // dismiss, so a MutationObserver keeps us honest without per-button wiring).
        var refresh = function () {
            var n = panelList.querySelectorAll(COUNT_SEL).length;
            if (badge) {
                badge.hidden = n === 0;
                badge.textContent = n > 99 ? '99+' : String(n);
            }
            menu.style.display = n === 0 ? 'none' : '';
            if (n === 0) menu.classList.remove('is-open');
        };

        // 2) Sweep up any foreign notices the server-side buffer could not
        //    reach — ones a page template printed inline, or that a plugin
        //    injected after the notices hook. These are exactly the notices
        //    BrikPanel's CSS fallback would otherwise hide with no trace, so
        //    surfacing them here keeps the panel complete: nothing the store
        //    needs to see silently disappears. Runs once now and again shortly
        //    after, to catch notices added late by other plugins' scripts.
        sweepForeignNotices(panelList);
        refresh();
        new MutationObserver(refresh).observe(panelList, { childList: true });
        // Re-sweep a couple of times so notices other plugins' scripts inject or
        // fill in late are caught once they actually have content.
        setTimeout(function () { sweepForeignNotices(panelList); }, 800);
        setTimeout(function () { sweepForeignNotices(panelList); }, 2500);
    }

    /**
     * Relocate stray third-party notices into the hidden-notices panel.
     *
     * Targets the same surfaces BrikPanel's CSS fallback hides (top-of-page
     * notices in `.wrap` / `#wpbody-content`), but deliberately skips:
     *  - BrikPanel's own panel (already relocated) and `.brikpanel-notice`,
     *  - inline / below-title form notices that belong next to a field,
     *  - WordPress's JS-controlled control notices (connection-lost,
     *    local-storage, anything still `hidden`) which must stay where they are.
     */
    function sweepForeignNotices(panelList) {
        var SWEEP_SEL = [
            '#wpbody-content > .notice', '#wpbody-content > .updated', '#wpbody-content > .error',
            '.wrap > .notice', '.wrap > .updated', '.wrap > .error', '.wrap > .update-nag'
        ].join(', ');

        Array.prototype.forEach.call(document.querySelectorAll(SWEEP_SEL), function (n) {
            if (n.closest('#brikpanel-topbar')) return;            // already in our panel
            if (n.classList.contains('brikpanel-notice')) return;  // ours
            if (n.classList.contains('inline') || n.classList.contains('below-h2')) return;
            if (n.classList.contains('hidden')) return;            // JS-controlled, leave it
            if (n.id === 'lost-connection-notice' || n.id === 'local-storage-notice') return;
            if (isErrorNotice(n)) return;                          // red/error notices stay on screen
            if (!noticeHasContent(n)) return;                      // empty placeholder, leave in place
            n.classList.add('brikpanel-notice');
            panelList.appendChild(n);
        });
    }

    /**
     * Red "error" notices (modern `.notice-error` or the legacy `.error`
     * container) flag something genuinely broken, so by default they are left on
     * screen rather than tucked behind the bell. The store owner can opt in
     * (Settings → "Also hide error notices") to collect them like any other
     * notice, in which case this stops treating them specially.
     */
    function isErrorNotice(n) {
        if (window.brikpanelTopbar && window.brikpanelTopbar.hide_errors) return false;
        return n.classList.contains('notice-error') || n.classList.contains('error');
    }

    /**
     * Whether a notice actually has something worth showing. Many plugins (and
     * WordPress itself) print empty `<div class="notice">` shells that their own
     * scripts fill in later, or leave behind after content is removed — pulling
     * those into the panel produces blank rows. We treat a notice as meaningful
     * only if it has visible text, or real content like an image/icon/link/
     * control (the dismiss "x" every dismissible notice carries does not count).
     */
    function noticeHasContent(n) {
        if ((n.textContent || '').trim() !== '') return true;
        return !!n.querySelector('img, svg, a[href], input, select, textarea, button:not(.notice-dismiss), .button');
    }

    /**
     * Wires the cache-clear control(s) rendered into the topbar.
     *
     * Supports both layouts:
     *  - Single bare icon button (one cache plugin active).
     *  - Icon button + dropdown of plugin-specific entries (2+ active).
     */
    function initCacheClear() {
        var topbar = document.getElementById('brikpanel-topbar');
        if (!topbar) return;

        // Single-button form: the button itself carries data-cache-id and is
        // not wired into a dropdown toggle.
        topbar.querySelectorAll('.brikpanel-topbar-cache-btn[data-cache-id]').forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                clearCache(btn.getAttribute('data-cache-id'), btn);
            });
        });

        // Dropdown form: each item inside the dropdown carries data-cache-id.
        topbar.querySelectorAll('.brikpanel-topbar-cache-item[data-cache-id]').forEach(function (item) {
            item.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                var trigger = topbar.querySelector('.brikpanel-topbar-cache-btn[data-topbar-toggle="cache"]');
                closeAllTopbarMenus();
                clearCache(item.getAttribute('data-cache-id'), trigger || item);
            });
        });
    }

    /**
     * Sends the cache-clear AJAX request and surfaces the result as a toast.
     * The trigger button enters a "loading" state for the duration of the
     * request to prevent double-fires.
     */
    function clearCache(cacheId, trigger) {
        var cfg = window.brikpanelTopbar || {};
        if (!cfg.ajax_url || !cfg.cache_nonce || !cfg.cache_action) return;
        if (trigger && trigger.classList.contains('is-loading')) return;

        if (trigger) {
            trigger.classList.add('is-loading');
            trigger.setAttribute('aria-busy', 'true');
        }

        var i18n = cfg.i18n || {};
        var body = new URLSearchParams();
        body.append('action', cfg.cache_action);
        body.append('security', cfg.cache_nonce);
        body.append('cache_id', cacheId || '');

        fetch(cfg.ajax_url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString()
        })
        .then(function (r) { return r.json().catch(function () { return null; }); })
        .then(function (json) {
            if (json && json.success && json.data && json.data.message) {
                showTopbarToast(json.data.message, 'success');
            } else if (json && !json.success && json.data && json.data.message) {
                showTopbarToast(json.data.message, 'error');
            } else {
                showTopbarToast(i18n.cache_failed || 'Cache could not be cleared.', 'error');
            }
        })
        .catch(function () {
            showTopbarToast(i18n.cache_failed || 'Cache could not be cleared.', 'error');
        })
        .finally(function () {
            if (trigger) {
                trigger.classList.remove('is-loading');
                trigger.removeAttribute('aria-busy');
            }
        });
    }

    /**
     * Lightweight self-contained toast — kept inside the topbar module so we
     * don't depend on per-page toast utilities (which aren't loaded on every
     * admin screen).
     */
    function showTopbarToast(message, type) {
        type = type === 'error' ? 'error' : 'success';
        var container = document.getElementById('brikpanel-topbar-toast-container');
        if (!container) {
            container = document.createElement('div');
            container.id = 'brikpanel-topbar-toast-container';
            container.className = 'brikpanel-topbar-toast-container';
            document.body.appendChild(container);
        }

        var toast = document.createElement('div');
        toast.className = 'brikpanel-topbar-toast brikpanel-topbar-toast-' + type;
        toast.setAttribute('role', type === 'error' ? 'alert' : 'status');

        var text = document.createElement('span');
        text.className = 'brikpanel-topbar-toast-text';
        text.textContent = String(message);
        toast.appendChild(text);

        var close = document.createElement('button');
        close.type = 'button';
        close.className = 'brikpanel-topbar-toast-close';
        close.setAttribute('aria-label', (window.brikpanelTopbar && window.brikpanelTopbar.i18n && window.brikpanelTopbar.i18n.close) || 'Close');
        close.innerHTML = '&times;';
        close.addEventListener('click', function () { dismiss(); });
        toast.appendChild(close);

        container.appendChild(toast);
        // Trigger CSS enter animation on next frame.
        requestAnimationFrame(function () { toast.classList.add('is-visible'); });

        var dismissTimer = setTimeout(dismiss, 3500);

        function dismiss() {
            clearTimeout(dismissTimer);
            if (!toast.parentElement) return;
            toast.classList.remove('is-visible');
            setTimeout(function () {
                if (toast.parentElement) toast.parentElement.removeChild(toast);
            }, 300);
        }
    }

    /**
     * Mobile hamburger → toggles the off-canvas WP sidebar.
     * Replaces the WP admin-bar's `#wp-admin-bar-menu-toggle`, which is gone
     * because we hide #wpadminbar entirely.
     */
    function initMobileMenu() {
        var btn = document.getElementById('brikpanel-topbar-menu-btn');
        if (!btn) return;

        // Insert a backdrop so tapping outside the sidebar closes it.
        var backdrop = document.createElement('div');
        backdrop.className = 'brikpanel-topbar-mobile-backdrop';
        backdrop.id = 'brikpanel-topbar-mobile-backdrop';
        document.body.appendChild(backdrop);

        var setOpen = function (open) {
            document.body.classList.toggle('brikpanel-mobile-nav-open', open);
            btn.setAttribute('aria-expanded', open ? 'true' : 'false');
        };

        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            setOpen(!document.body.classList.contains('brikpanel-mobile-nav-open'));
        });
        backdrop.addEventListener('click', function () { setOpen(false); });

        // Close when navigating via a sidebar link (small screens).
        document.addEventListener('click', function (e) {
            if (!document.body.classList.contains('brikpanel-mobile-nav-open')) return;
            var link = e.target.closest('#adminmenu a, #brikpanel-navigation a');
            if (!link) return;
            // First tap on a classic-menu parent that has children expands its
            // submenu inline instead of navigating (WordPress core suppresses the
            // jump at <=782px). Keep the off-canvas panel open so the revealed
            // children are actually usable — only a leaf tap should close it.
            var isClassicParentTap = link.classList.contains('menu-top')
                && link.closest('#adminmenu li.wp-has-submenu')
                && window.innerWidth <= 782;
            if (isClassicParentTap) return;
            setOpen(false);
        });

        // Auto-close when resizing back up to the full desktop sidebar. The
        // off-canvas hamburger now spans WP's whole auto-fold range (<=960px),
        // so only a width past 960px means the static sidebar is back.
        window.addEventListener('resize', function () {
            if (window.innerWidth > 960 && document.body.classList.contains('brikpanel-mobile-nav-open')) {
                setOpen(false);
            }
        });

        // Escape closes the sidebar too.
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && document.body.classList.contains('brikpanel-mobile-nav-open')) {
                setOpen(false);
            }
        });
    }

    function closeAllTopbarMenus() {
        var topbar = document.getElementById('brikpanel-topbar');
        if (!topbar) return;
        topbar.querySelectorAll('.brikpanel-topbar-menu.is-open').forEach(function (menu) {
            menu.classList.remove('is-open');
            var btn = menu.querySelector('[data-topbar-toggle]');
            if (btn) btn.setAttribute('aria-expanded', 'false');
        });
    }

    function startTopbarPolling() {
        stopTopbarPolling();
        topbarInterval = setInterval(fetchTopbarStats, 30000);
    }

    function stopTopbarPolling() {
        if (topbarInterval) {
            clearInterval(topbarInterval);
            topbarInterval = null;
        }
    }

    function fetchTopbarStats() {
        var cfg = window.brikpanelTopbar || {};
        if (!cfg.ajax_url || !cfg.nonce) return;

        var body = new URLSearchParams();
        body.append('action', 'brikpanel_topbar_stats');
        body.append('security', cfg.nonce);

        fetch(cfg.ajax_url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString()
        })
        .then(function (r) { return r.json(); })
        .then(function (json) {
            if (!json || !json.success || !json.data) return;
            renderTopbarStats(json.data);
        })
        .catch(function () { /* silent */ });
    }

    function renderTopbarStats(data) {
        var liveCount = document.getElementById('brikpanel-topbar-live-count');
        var livePill  = document.getElementById('brikpanel-topbar-live');
        if (liveCount) liveCount.textContent = formatNumber(data.live || 0);
        if (livePill) {
            if ((data.live || 0) > 0) livePill.classList.remove('is-empty');
            else livePill.classList.add('is-empty');
        }

        var n = data.notifications || {};
        setText('brikpanel-topbar-notif-processing', formatNumber(n.processing || 0));
        setText('brikpanel-topbar-notif-pending',    formatNumber(n.pending    || 0));
        setText('brikpanel-topbar-notif-onhold',     formatNumber(n.onhold     || 0));
        setText('brikpanel-topbar-notif-oos',        formatNumber(n.oos        || 0));
        setText('brikpanel-topbar-notif-customers',  formatNumber(n.customers  || 0));

        // Mark rows with actual counts so they highlight.
        ['processing', 'pending', 'onhold', 'oos', 'customers'].forEach(function (k) {
            var el = document.getElementById('brikpanel-topbar-notif-' + k);
            if (!el) return;
            var row = el.closest('.brikpanel-topbar-dropdown-row');
            if (!row) return;
            if ((n[k] || 0) > 0) row.setAttribute('data-has-count', 'true');
            else row.removeAttribute('data-has-count');
        });

        var badge = document.getElementById('brikpanel-topbar-notif-badge');
        if (badge) {
            var total = (n.processing || 0) + (n.pending || 0) + (n.onhold || 0);
            if (total > 0) {
                badge.hidden = false;
                badge.textContent = total > 99 ? '99+' : String(total);
            } else {
                badge.hidden = true;
            }
        }
    }

    function setText(id, value) {
        var el = document.getElementById(id);
        if (!el) return;
        if (value == null) { el.textContent = '—'; return; }
        if (typeof value === 'string' && value.indexOf('<') !== -1) {
            el.innerHTML = value;
        } else {
            el.textContent = String(value);
        }
    }

    function formatNumber(n) {
        n = Number(n) || 0;
        return n.toLocaleString();
    }

})();
