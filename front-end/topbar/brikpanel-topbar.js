/**
 * BrikPanel Global Topbar
 *
 * - Relocates the BrikPanel search overlay out of #wpadminbar so it still
 *   opens when the admin bar is hidden.
 * - Wires dropdown menus (Create, notifications, user).
 * - Polls the `brikpanel_topbar_stats` endpoint every 30s for live
 *   visitors and the notification bell counts.
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
        initActionOverflow(topbar);
        initComingSoonBadge(topbar);

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
            if (isCoreUpdatedMessage(n)) return;                   // WP/WC own "Order updated." etc. stay in place
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
     * WordPress and WooCommerce print their own first-party "updated"
     * confirmation bar with id="message" (e.g. "Order updated.", "Post
     * updated.") directly in the page body, not via a third-party banner. These
     * are the action feedback the user is waiting for after saving, so they must
     * never be tucked behind the bell or hidden — keep them exactly where the
     * screen rendered them.
     */
    function isCoreUpdatedMessage(n) {
        return n.id === 'message';
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
     * Keep the end of the action cluster in view when it overflows.
     *
     * Below 600px `.brikpanel-topbar-right` becomes a horizontally scrollable
     * strip (see brikpanel-topbar.css) so that a store carrying more controls
     * than a narrow phone can hold — the cache-clear button, a custom shortcut,
     * a third-party `brikpanel_topbar_items` entry — never pushes one of them
     * off-screen where it cannot be tapped at all. A scroll container starts at
     * scrollLeft 0, i.e. showing its *first* items, which would leave the user
     * menu (profile, log out) hidden behind a swipe nobody knows to make. The
     * cluster reads right-to-left in importance, so park it at the end instead;
     * the earlier icons are the ones a swipe then reveals.
     *
     * Purely corrective: when nothing overflows, scrollWidth === clientWidth
     * and this is a no-op.
     */
    function initActionOverflow(topbar) {
        var right = topbar.querySelector('.brikpanel-topbar-right');
        if (!right) return;

        var pin = function () {
            if (right.scrollWidth > right.clientWidth) {
                right.scrollLeft = right.scrollWidth;
            }
        };

        pin();
        window.addEventListener('resize', pin);
    }

    /**
     * Coming soon badge: fall back to the icon when the bar is too crowded.
     *
     * At 960px and below the stylesheet always shows the icon alone. Above
     * that the label normally fits, but extra controls in the bar (cache clear,
     * a custom shortcut, third-party items), a long store name or a wide logo
     * can run the left cluster out of room. The store name gives way first by
     * ellipsizing, so "crowded" means the name is cut short by the layout or
     * the cluster still clips. The badge then drops its label, and only gets it
     * back once showing it no longer crowds the bar.
     */
    function initComingSoonBadge(topbar) {
        var badge = topbar.querySelector('.brikpanel-topbar-coming-soon');
        var left = topbar.querySelector('.brikpanel-topbar-left');
        if (!badge || !left) return;

        var right = topbar.querySelector('.brikpanel-topbar-right');
        var name = topbar.querySelector('.brikpanel-topbar-brand-name');
        var iconOnly = window.matchMedia('(max-width: 960px)');
        var frame = 0;

        // Smallest max-width the stylesheet gives the store name: clamp(180px, ...).
        var READABLE_NAME = 180;

        var crowded = function () {
            if (left.scrollWidth > left.clientWidth) return true;
            if (!name || !name.getClientRects().length) return false;
            if (name.scrollWidth <= name.clientWidth) return false;
            // A short name should show in full. A long one is cut at its own
            // max-width anyway, so it only has to keep a readable part.
            return name.getBoundingClientRect().width < Math.min(name.scrollWidth, READABLE_NAME) - 0.5;
        };

        var fit = function () {
            frame = 0;
            if (iconOnly.matches) return;
            if (!badge.classList.contains('is-compact')) {
                if (crowded()) badge.classList.add('is-compact');
                return;
            }
            // Try the label again and keep it only if everything still fits.
            // Both steps land before the next paint, so nothing flickers.
            badge.classList.remove('is-compact');
            if (crowded()) badge.classList.add('is-compact');
        };

        var schedule = function () {
            if (!frame) frame = window.requestAnimationFrame(fit);
        };

        fit();

        if (typeof window.ResizeObserver === 'function') {
            // The bar follows the viewport; the clusters change width when a
            // control appears later (hidden notices bell) or a count grows.
            var observer = new window.ResizeObserver(schedule);
            observer.observe(topbar);
            observer.observe(left);
            if (right) observer.observe(right);
        } else {
            window.addEventListener('resize', schedule);
        }
    }

    /**
     * Sidebar toggle.
     *
     * Above 960px it hides / shows the fixed desktop sidebar and remembers the
     * choice per user. At 960px and below it is the off-canvas hamburger, which
     * replaces the WP admin-bar's `#wp-admin-bar-menu-toggle` (gone because we
     * hide #wpadminbar entirely). The `[` key does the same as a click.
     */
    function initMobileMenu() {
        var btn = document.getElementById('brikpanel-topbar-menu-btn');
        if (!btn) return;

        var body = document.body;
        var cfg = window.brikpanelTopbar || {};
        var i18n = cfg.i18n || {};
        // Must match the CSS breakpoint exactly; matchMedia (unlike innerWidth
        // arithmetic) measures the same way the stylesheet does.
        var desktopMql = window.matchMedia ? window.matchMedia('(min-width: 961px)') : null;
        var isDesktop = function () {
            return desktopMql ? desktopMql.matches : window.innerWidth > 960;
        };
        // wp_localize_script() sends PHP false as "" (true as "1").
        var desktopToggleOn = cfg.sidebar_toggle !== '' && cfg.sidebar_toggle !== false
            && !btn.classList.contains('is-desktop-off');

        // Insert a backdrop so tapping outside the sidebar closes it.
        var backdrop = document.createElement('div');
        backdrop.className = 'brikpanel-topbar-mobile-backdrop';
        backdrop.id = 'brikpanel-topbar-mobile-backdrop';
        body.appendChild(backdrop);

        var tip = document.getElementById('brikpanel-topbar-sidebar-tip');
        var tipLabel = tip ? tip.querySelector('.brikpanel-topbar-sidebar-tip-label') : null;
        var topbarEl = document.getElementById('brikpanel-topbar');

        var syncButton = function () {
            if (isDesktop()) {
                var hidden = body.classList.contains('brikpanel-sidebar-hidden');
                var label = hidden ? i18n.show_sidebar : i18n.hide_sidebar;
                if (label) {
                    btn.setAttribute('aria-label', label);
                    if (tipLabel) tipLabel.textContent = label;
                }
                btn.setAttribute('aria-expanded', hidden ? 'false' : 'true');
            } else {
                if (i18n.toggle_nav) btn.setAttribute('aria-label', i18n.toggle_nav);
                btn.setAttribute('aria-expanded', body.classList.contains('brikpanel-mobile-nav-open') ? 'true' : 'false');
            }
        };

        var setOpen = function (open) {
            body.classList.toggle('brikpanel-mobile-nav-open', open);
            syncButton();
        };

        var animTimer = 0;
        // Saving is debounced into one request carrying the final state: fast
        // repeated toggles would otherwise fire parallel requests that can
        // reach the server out of order and store a stale choice.
        var saveTimer = 0;
        var pendingHidden = null;
        var flushSave = function () {
            clearTimeout(saveTimer);
            if (pendingHidden === null || !cfg.ajax_url || !cfg.nonce || !window.fetch) return;
            var data = new URLSearchParams();
            data.append('action', 'brikpanel_topbar_sidebar_state');
            data.append('security', cfg.nonce);
            data.append('hidden', pendingHidden ? 'yes' : 'no');
            pendingHidden = null;
            // keepalive: the choice still lands if the page is left right away.
            // A failure only costs the memory of the choice, never the
            // on-screen state, so it is left silent.
            fetch(cfg.ajax_url, { method: 'POST', credentials: 'same-origin', body: data, keepalive: true })
                .catch(function () {});
        };
        var saveState = function (hidden) {
            // Bridge until the save lands: the next page request carries this
            // cookie, so navigating right after a toggle keeps the new state.
            var ck = cfg.sidebar_cookie;
            if (ck && ck.name && ck.user) {
                document.cookie = ck.name + '=' + encodeURIComponent(ck.user + ':' + (hidden ? 'yes' : 'no'))
                    + '; path=' + (ck.path || '/') + '; max-age=120; SameSite=Lax' // i18n-ignore: cookie attributes, not UI text
                    + (location.protocol === 'https:' ? '; Secure' : ''); // i18n-ignore: cookie attribute, not UI text
            }
            pendingHidden = hidden;
            clearTimeout(saveTimer);
            saveTimer = setTimeout(flushSave, 400);
        };
        window.addEventListener('pagehide', flushSave);

        var setDesktopHidden = function (hidden) {
            if (hidden === body.classList.contains('brikpanel-sidebar-hidden')) return;

            // Focus must not stay on a link that is about to become invisible.
            var nav = document.getElementById('adminmenuwrap');
            if (hidden && nav && nav.contains(document.activeElement)) {
                btn.focus();
            }

            // Transitions exist only while this class is present, and it has to
            // be applied (and flushed) before the state flips or nothing animates.
            body.classList.add('brikpanel-sidebar-animating');
            void body.offsetWidth; // eslint-disable-line no-void
            body.classList.toggle('brikpanel-sidebar-hidden', hidden);
            syncButton();
            saveState(hidden);

            clearTimeout(animTimer);
            animTimer = setTimeout(function () {
                body.classList.remove('brikpanel-sidebar-animating');
                // Content-width consumers that only listen to resize (charts,
                // sticky table headers, the order edit bar) re-measure now.
                window.dispatchEvent(new Event('resize'));
            }, 280);
        };

        var toggle = function () {
            if (isDesktop()) {
                if (desktopToggleOn) setDesktopHidden(!body.classList.contains('brikpanel-sidebar-hidden'));
            } else {
                setOpen(!body.classList.contains('brikpanel-mobile-nav-open'));
            }
        };

        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            hideTip();
            // stopPropagation keeps the document click from closing an open
            // top bar dropdown, so close it here like every other bar button.
            closeAllTopbarMenus();
            toggle();
        });

        // Tooltip (label + shortcut), shown after a short hover delay.
        var tipTimer = 0;
        var showTip = function () {
            if (!topbarEl || !tip || !isDesktop()) return;
            clearTimeout(tipTimer);
            tipTimer = setTimeout(function () { topbarEl.classList.add('is-sidebar-tip-open'); }, 350);
        };
        var hideTip = function () {
            clearTimeout(tipTimer);
            if (topbarEl) topbarEl.classList.remove('is-sidebar-tip-open');
        };
        btn.addEventListener('mouseenter', showTip);
        btn.addEventListener('mouseleave', hideTip);
        btn.addEventListener('focus', function () {
            // Older browsers throw on an unknown pseudo-class; skip the tip there.
            try {
                if (btn.matches(':focus-visible')) showTip();
            } catch (err) {}
        });
        btn.addEventListener('blur', hideTip);

        // `[` shortcut. Skipped while typing, while a modal or the search
        // palette is open, and for Ctrl/Cmd chords. Alt / AltGr stay allowed:
        // on Turkish and several other layouts `[` itself is typed with them.
        document.addEventListener('keydown', function (e) {
            if (e.key !== '[' || e.defaultPrevented || e.repeat || e.isComposing || e.metaKey) return;
            if (e.ctrlKey && !e.altKey) return;
            var t = e.target;
            if (t && t.nodeType === 1) {
                if (t.isContentEditable || t.closest('input, textarea, select, [contenteditable]:not([contenteditable="false"]), [role="textbox"]')) return;
            }
            if (body.classList.contains('modal-open')) return;
            var overlay = document.querySelector('.brikpanel-search-overlay');
            if (overlay && !overlay.classList.contains('hidden')) return;
            if (document.querySelector('dialog[open]')) return;
            if (isDesktop() && !desktopToggleOn) return;
            e.preventDefault();
            toggle();
        });

        syncButton();
        var onBreakpoint = function () { hideTip(); syncButton(); };
        if (desktopMql && desktopMql.addEventListener) {
            desktopMql.addEventListener('change', onBreakpoint);
        } else if (desktopMql && desktopMql.addListener) {
            desktopMql.addListener(onBreakpoint);
        }
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
