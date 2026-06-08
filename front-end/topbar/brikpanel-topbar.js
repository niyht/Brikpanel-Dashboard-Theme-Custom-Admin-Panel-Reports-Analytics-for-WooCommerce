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

        fetchTopbarStats();
        startTopbarPolling();
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
            if (link) setOpen(false);
        });

        // Auto-close when resizing back up to desktop.
        window.addEventListener('resize', function () {
            if (window.innerWidth > 782 && document.body.classList.contains('brikpanel-mobile-nav-open')) {
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
