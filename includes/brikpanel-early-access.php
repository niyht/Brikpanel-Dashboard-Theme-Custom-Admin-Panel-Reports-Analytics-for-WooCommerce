<?php
/**
 * BrikPanel — Newsletter Subscription Capture
 *
 * Collects an opt-in email address for the BrikPanel product newsletter: new
 * BrikPanel and BrikMentor features, WooCommerce tips and store-growth ideas.
 *
 * History: this module started life as the "BrikMentor early access waitlist"
 * (a milestone-triggered beta invite). BrikMentor has since launched, so the
 * waitlist offer no longer exists, but the capture machinery below was sound
 * and was kept wholesale. That is why every option, AJAX action, nonce action
 * and CSS class here still carries the `ea` / `early_access` prefix: renaming
 * them would unsubscribe everyone who already joined and would need a data
 * migration for zero user-visible benefit. Read `ea` as "email address".
 *
 * Two entry points, both opt-in and both click-triggered (nothing auto-opens):
 *   1. A dismissible card at the bottom of the BrikPanel dashboard.
 *   2. A row in WooCommerce > Settings > BrikPanel (General).
 *
 * Reliability contract — a submitted email must NEVER be lost:
 *   1. On submit the lead is written to a durable local store immediately.
 *   2. Delivery to the collection endpoint is attempted synchronously.
 *   3. On any failure the lead stays in a local outbox and is retried via
 *      WP-Cron with exponential backoff, plus an admin_init fallback flush for
 *      sites where cron is disabled. Retries never give up.
 *   4. Each lead carries a stable UUID so the endpoint can de-duplicate; retries
 *      never create duplicates.
 * The AJAX handler returns success to the browser as soon as the lead is stored
 * locally, so the merchant always sees confirmation even while delivery is still
 * pending in the background.
 *
 * Privacy / wp.org compliance: nothing is collected automatically. The email is
 * only sent after the merchant types it in and ticks an explicit, unchecked-by-
 * default consent box. See the "What data does BrikPanel send outside my site?"
 * entry in readme.txt.
 *
 * @package BrikPanel
 * @since   3.1.28
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* ── Configuration ──────────────────────────────────────────────────────────── */

// Where leads are delivered. A matching REST endpoint must exist on this host.
// Overridable from wp-config.php for staging/testing.
if ( ! defined( 'BRIKPANEL_EA_ENDPOINT' ) ) {
    define( 'BRIKPANEL_EA_ENDPOINT', 'https://brksoft.com/wp-json/brikpanel-leads/v1/subscribe' );
}

// WhatsApp fallback. If a host blocks all outbound HTTP, no retry can ever
// deliver the lead, so the merchant is offered a channel that does not depend on
// their site at all. Digits only, international format, no "+".
if ( ! defined( 'BRIKPANEL_EA_WHATSAPP' ) ) {
    define( 'BRIKPANEL_EA_WHATSAPP', '905457288342' );
}

/**
 * Entry points allowed to produce a lead, mirrored into the `source` field so
 * the two segments can be nurtured differently on the collection side.
 *
 * These values are deliberately NOT the historical waitlist ones
 * (`self_serve`, `settings`, `milestone_100`, …): keeping them distinct is what
 * lets the collection endpoint tell a 2026 beta-waitlist row apart from a
 * newsletter subscriber. The receiving endpoint applies no whitelist of its
 * own, so adding a value here is all that is needed on the wire.
 *
 * @return string[]
 */
function brikpanel_ea_sources() {
    return array( 'newsletter_dashboard', 'newsletter_settings' );
}

/**
 * Is the dismissible dashboard card available?
 *
 * Uses its own dismissal flag rather than the waitlist-era
 * `brikpanel_ea_card_dismissed`: a merchant who turned down a closed beta was
 * not turning down a product newsletter, so the new offer gets a fresh
 * hearing. (Same reasoning the launch card already uses for
 * `brikpanel_bm_live_card_dismissed`.)
 *
 * @return bool
 */
function brikpanel_ea_card_available() {
    if ( get_option( 'brikpanel_ea_subscribed' ) ) {
        return false;
    }
    if ( get_option( 'brikpanel_newsletter_card_dismissed' ) ) {
        return false;
    }
    return true;
}

/** True on the BrikPanel dashboard screen (where the card lives). */
function brikpanel_ea_is_dashboard() {
    return isset( $_GET['page'] ) && 'brikpanel-dashboard' === $_GET['page']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen check.
}

/**
 * True on the WooCommerce > Settings > BrikPanel > General screen, the one
 * section that carries the newsletter row.
 *
 * The section check matters: the settings tab has a dozen sections and the
 * modal is only needed on the one holding the trigger button. Titles that are
 * not in brikpanel_settings_section_for_title()'s map (ours is not, on
 * purpose) render under General, which resolves to the empty section id.
 *
 * @return bool
 */
function brikpanel_ea_is_settings_page() {
    // phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only screen check.
    if ( ! isset( $_GET['page'], $_GET['tab'] ) || 'wc-settings' !== $_GET['page'] || 'brikpanel' !== $_GET['tab'] ) {
        return false;
    }
    // phpcs:enable
    if ( function_exists( 'brikpanel_settings_get_current_section' ) ) {
        return '' === brikpanel_settings_get_current_section();
    }
    return true;
}

/* ── Render the modal ───────────────────────────────────────────────────────── */
add_action( 'admin_footer', 'brikpanel_ea_render_modal' );
function brikpanel_ea_render_modal() {
    // Cheapest checks first: this runs in the footer of EVERY admin screen, and
    // the two screen tests are pure $_GET reads, so every other admin page
    // short-circuits before a single option is touched.
    $on_dashboard = brikpanel_ea_is_dashboard();
    $on_settings  = brikpanel_ea_is_settings_page();
    if ( ! $on_dashboard && ! $on_settings ) {
        return;
    }
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
    if ( get_option( 'brikpanel_ea_subscribed' ) ) {
        return; // Already subscribed: no trigger renders, so no modal is needed.
    }
    // The dashboard only carries a trigger while its card is undismissed; the
    // settings row always does.
    if ( ! $on_settings && ! brikpanel_ea_card_available() ) {
        return;
    }

    $nonce = wp_create_nonce( 'brikpanel_ea_nonce' );
    ?>
    <div class="brikpanel-ea-overlay" id="brikpanel-ea" data-nonce="<?php echo esc_attr( $nonce ); ?>" role="dialog" aria-modal="true" aria-labelledby="brikpanel-ea-title" hidden>
        <div class="brikpanel-ea-modal" role="document">
            <button type="button" class="brikpanel-ea-close" data-ea-dismiss aria-label="<?php esc_attr_e( 'Close', 'brikpanel' ); ?>">
                <svg width="14" height="14" viewBox="0 0 14 14" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M1 1l12 12M13 1L1 13" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
            </button>

            <!-- Step 1: pitch + email -->
            <div class="brikpanel-ea-step" data-ea-step="pitch">
                <div class="brikpanel-ea-badge" aria-hidden="true">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><rect x="2.75" y="5" width="18.5" height="14" rx="2.5" stroke="#fff" stroke-width="1.8"/><path d="M3.5 7l8.5 6 8.5-6" stroke="#fff" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </div>
                <h2 class="brikpanel-ea-title" id="brikpanel-ea-title"><?php esc_html_e( 'Stay up to date', 'brikpanel' ); ?></h2>
                <p class="brikpanel-ea-body"><?php esc_html_e( 'Get an occasional email about new BrikPanel and BrikMentor features, WooCommerce tips and ideas for growing your store. Short emails, only when there is something worth knowing.', 'brikpanel' ); ?></p>

                <form class="brikpanel-ea-form" data-ea-form novalidate>
                    <label class="brikpanel-ea-label" for="brikpanel-ea-email"><?php esc_html_e( 'Your email', 'brikpanel' ); ?></label>
                    <input type="email" id="brikpanel-ea-email" class="brikpanel-ea-input" data-ea-email autocomplete="email" placeholder="<?php esc_attr_e( 'you@store.com', 'brikpanel' ); ?>" required>
                    <p class="brikpanel-ea-error" data-ea-error-email hidden><?php esc_html_e( 'Please enter a valid email address.', 'brikpanel' ); ?></p>

                    <label class="brikpanel-ea-consent">
                        <input type="checkbox" data-ea-consent>
                        <span><?php esc_html_e( 'Email me BrikPanel product news and occasional WooCommerce tips. I can unsubscribe at any time.', 'brikpanel' ); ?></span>
                    </label>
                    <p class="brikpanel-ea-error" data-ea-error-consent hidden><?php esc_html_e( 'Please tick the box so we can email you.', 'brikpanel' ); ?></p>

                    <div class="brikpanel-ea-actions">
                        <button type="submit" class="brikpanel-ea-btn brikpanel-ea-btn--primary" data-ea-submit><?php esc_html_e( 'Subscribe', 'brikpanel' ); ?></button>
                        <button type="button" class="brikpanel-ea-btn brikpanel-ea-btn--ghost" data-ea-dismiss><?php esc_html_e( 'Not now', 'brikpanel' ); ?></button>
                    </div>
                    <p class="brikpanel-ea-fineprint"><?php esc_html_e( 'No spam. Unsubscribe with one click, any time.', 'brikpanel' ); ?></p>
                </form>
            </div>

            <!-- Step 2: thank you -->
            <div class="brikpanel-ea-step" data-ea-step="done" hidden>
                <div class="brikpanel-ea-badge brikpanel-ea-badge--ok" aria-hidden="true">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M5 12.5l4.2 4.2L19 7" stroke="#fff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </div>
                <h2 class="brikpanel-ea-title"><?php esc_html_e( "You're subscribed.", 'brikpanel' ); ?></h2>
                <p class="brikpanel-ea-body"><?php esc_html_e( "Thanks. We'll email you when there is something worth knowing.", 'brikpanel' ); ?></p>
                <div class="brikpanel-ea-actions">
                    <button type="button" class="brikpanel-ea-btn brikpanel-ea-btn--primary" data-ea-dismiss><?php esc_html_e( 'Close', 'brikpanel' ); ?></button>
                </div>
            </div>

            <!-- Fallback: shown only when this site cannot reach our server, so a
                 lead is never lost even on hosts that block outbound requests. -->
            <div class="brikpanel-ea-wa" data-ea-whatsapp hidden>
                <p class="brikpanel-ea-wa__text"><?php esc_html_e( "Heads up: we could not reach our server from your site (some hosts block outbound connections). We saved your request and will keep trying, but to be safe you can message us on WhatsApp and we'll subscribe you by hand.", 'brikpanel' ); ?></p>
                <a class="brikpanel-ea-wa__btn" data-ea-wa href="#" target="_blank" rel="noopener noreferrer">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M12.04 2c-5.46 0-9.91 4.45-9.91 9.91 0 1.75.46 3.45 1.32 4.95L2 22l5.25-1.38c1.45.79 3.08 1.21 4.79 1.21 5.46 0 9.91-4.45 9.91-9.91S17.5 2 12.04 2zm5.8 14.01c-.25.7-1.44 1.33-1.99 1.41-.53.08-1.18.11-1.9-.12-.44-.14-1-.32-1.72-.63-3.03-1.31-5-4.36-5.16-4.56-.15-.2-1.22-1.62-1.22-3.09 0-1.47.77-2.19 1.05-2.49.27-.3.59-.37.79-.37.2 0 .39 0 .56.01.18.01.42-.07.66.5.25.59.84 2.04.91 2.19.07.15.12.32.02.52-.1.2-.15.32-.29.5-.15.17-.31.39-.44.52-.15.15-.3.31-.13.6.17.3.76 1.25 1.63 2.02 1.12.99 2.06 1.3 2.36 1.45.3.15.47.12.65-.07.18-.2.74-.86.94-1.16.2-.3.39-.25.66-.15.27.1 1.71.81 2 .96.3.15.5.22.57.34.07.12.07.7-.18 1.4z"/></svg>
                    <?php esc_html_e( 'Subscribe on WhatsApp', 'brikpanel' ); ?>
                </a>
            </div>
        </div>
    </div>
    <?php brikpanel_ea_print_styles(); ?>
    <script>
    (function () {
        var overlay = document.getElementById('brikpanel-ea');
        if (!overlay) return;
        var ajaxUrl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
        var nonce   = overlay.getAttribute('data-nonce');
        var sources = <?php echo wp_json_encode( brikpanel_ea_sources() ); ?>;
        var source  = sources[0];
        var lastEmail = '';
        var waNumber   = <?php echo wp_json_encode( preg_replace( '/\D/', '', (string) apply_filters( 'brikpanel_ea_whatsapp', BRIKPANEL_EA_WHATSAPP ) ) ); ?>;
        var waTemplate = <?php echo wp_json_encode( __( 'Hello, please subscribe me to the BrikPanel newsletter. My email is {EMAIL} and my store is {SITE}.', 'brikpanel' ) ); ?>;
        var waSite     = <?php echo wp_json_encode( home_url() ); ?>;

        // Reveal the WhatsApp fallback and point it at a pre-filled message. This
        // is the last-resort, site-independent channel that guarantees we can
        // still receive the lead even if outbound HTTP is blocked here.
        function revealWhatsApp(email) {
            var link = overlay.querySelector('[data-ea-wa]');
            var box  = overlay.querySelector('[data-ea-whatsapp]');
            if (!link || !box || !waNumber) return;
            var msg = waTemplate.replace('{EMAIL}', email || lastEmail || '').replace('{SITE}', waSite);
            link.setAttribute('href', 'https://wa.me/' + waNumber + '?text=' + encodeURIComponent(msg));
            box.hidden = false;
        }

        function show(step) {
            overlay.querySelectorAll('[data-ea-step]').forEach(function (el) {
                el.hidden = el.getAttribute('data-ea-step') !== step;
            });
        }
        function open(src) {
            if (src && sources.indexOf(src) !== -1) source = src;
            overlay.hidden = false;
            document.body.style.overflow = 'hidden';
            var email = overlay.querySelector('[data-ea-email]');
            if (email) setTimeout(function () { email.focus(); }, 60);
        }
        function close() {
            overlay.hidden = true;
            document.body.style.overflow = '';
        }
        function post(action, fields) {
            var fd = new FormData();
            fd.append('action', action);
            fd.append('_ajax_nonce', nonce);
            Object.keys(fields || {}).forEach(function (k) { fd.append(k, fields[k]); });
            return fetch(ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function (r) { return r.json().catch(function () { return { success: false }; }); })
                .catch(function () { return { success: false, networkError: true }; });
        }

        var emailInput  = overlay.querySelector('[data-ea-email]');
        var consentBox  = overlay.querySelector('[data-ea-consent]');
        var errEmail    = overlay.querySelector('[data-ea-error-email]');
        var errConsent  = overlay.querySelector('[data-ea-error-consent]');
        var form        = overlay.querySelector('[data-ea-form]');
        var submitBtn   = overlay.querySelector('[data-ea-submit]');

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            errEmail.hidden = true; errConsent.hidden = true;
            var email = (emailInput.value || '').trim();
            var ok = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
            if (!ok) { errEmail.hidden = false; emailInput.focus(); return; }
            if (!consentBox.checked) { errConsent.hidden = false; return; }

            lastEmail = email;
            submitBtn.disabled = true;
            post('brikpanel_ea_submit', { email: email, consent: '1', source: source }).then(function (res) {
                submitBtn.disabled = false;
                if (res && res.success) {
                    // Stored locally for sure. Subscribed, so the always-on card
                    // is no longer relevant.
                    var card = document.querySelector('[data-ea-card]');
                    if (card && card.parentNode) card.parentNode.removeChild(card);
                    show('done');
                    // Could not reach our server on the first try: offer WhatsApp
                    // as a safety net (the lead also keeps retrying in the queue).
                    if (res.data && res.data.delivered === false) {
                        revealWhatsApp(email);
                    }
                } else if (res && res.data && (res.data.reason === 'invalid_email' || res.data.reason === 'no_consent')) {
                    if (res.data.reason === 'no_consent') { errConsent.hidden = false; } else { errEmail.hidden = false; }
                } else {
                    // The request itself failed (network blocked, admin-ajax down):
                    // we may not have stored the lead, so surface WhatsApp now.
                    revealWhatsApp(email);
                }
            });
        });

        overlay.querySelectorAll('[data-ea-dismiss]').forEach(function (el) {
            el.addEventListener('click', close);
        });
        overlay.addEventListener('mousedown', function (e) { if (e.target === overlay) close(); });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && !overlay.hidden) close();
        });

        // Triggers (dashboard card CTA, settings row button). Each carries its
        // own source via data-ea-source.
        document.querySelectorAll('[data-ea-open]').forEach(function (el) {
            el.addEventListener('click', function (e) {
                e.preventDefault();
                open(el.getAttribute('data-ea-source'));
            });
        });
        document.querySelectorAll('[data-ea-card-dismiss]').forEach(function (el) {
            el.addEventListener('click', function (e) {
                e.preventDefault();
                post('brikpanel_ea_card_dismiss', {});
                var card = el.closest('[data-ea-card]');
                if (card && card.parentNode) card.parentNode.removeChild(card);
            });
        });

        show('pitch');
    })();
    </script>
    <?php
}

/* ── Modal styles (scoped, follows the BrikPanel design system) ─────────────── */
function brikpanel_ea_print_styles() {
    ?>
    <style>
        .brikpanel-ea-overlay[hidden] { display: none; }
        .brikpanel-ea-overlay {
            position: fixed; inset: 0; z-index: 99999;
            display: flex; align-items: center; justify-content: center;
            padding: 1.5rem;
            background: rgba(20, 20, 20, 0.45);
            -webkit-backdrop-filter: blur(2px); backdrop-filter: blur(2px);
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            color: #303030;
            animation: brikpanel-ea-fade 0.2s ease;
        }
        @keyframes brikpanel-ea-fade { from { opacity: 0; } to { opacity: 1; } }
        .brikpanel-ea-modal {
            position: relative;
            width: 100%; max-width: 440px;
            background: #fff;
            border: 1px solid #e3e3e3;
            border-radius: 0.75rem;
            box-shadow: 0 12px 40px rgba(0, 0, 0, 0.18);
            padding: 1.75rem 1.75rem 1.5rem;
            animation: brikpanel-ea-rise 0.22s cubic-bezier(.2,.7,.3,1);
        }
        @keyframes brikpanel-ea-rise { from { transform: translateY(12px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
        .brikpanel-ea-close {
            position: absolute; top: 0.75rem; inset-inline-end: 0.75rem;
            width: 30px; height: 30px; border-radius: 0.375rem;
            background: transparent; border: none; color: #8a8a8a; cursor: pointer;
            display: flex; align-items: center; justify-content: center; padding: 0;
            transition: background 0.15s ease, color 0.15s ease;
        }
        .brikpanel-ea-close:hover { background: #f7f7f7; color: #303030; }
        .brikpanel-ea-badge {
            width: 46px; height: 46px; border-radius: 50%;
            background: #303030; display: flex; align-items: center; justify-content: center;
            margin-bottom: 1rem;
        }
        .brikpanel-ea-badge--ok { background: #1a8917; }
        .brikpanel-ea-title {
            margin: 0 0 0.5rem; padding: 0;
            font-size: 1.125rem; font-weight: 600; line-height: 1.35; color: #303030;
        }
        .brikpanel-ea-body {
            margin: 0 0 1.25rem; padding: 0;
            font-size: 0.875rem; line-height: 1.55; color: #616161;
        }
        .brikpanel-ea-label {
            display: block; margin: 0 0 0.375rem;
            font-size: 0.8125rem; font-weight: 600; color: #303030;
        }
        /* Scoped under the overlay on purpose: wp-admin styles the bare
           `input[type=email]` selector, which outspecifies a lone class and
           would otherwise repaint the field (and its focus ring) in WordPress
           blue instead of the BrikPanel palette. */
        .brikpanel-ea-overlay .brikpanel-ea-input {
            width: 100%; box-sizing: border-box;
            padding: 0.625rem 0.75rem;
            font-size: 0.875rem; color: #303030;
            border: 1px solid #8a8a8a; border-radius: 0.5rem;
            background: #fff; line-height: 1.5; min-height: 0;
            box-shadow: none;
            transition: border-color 0.15s ease, box-shadow 0.15s ease;
        }
        .brikpanel-ea-overlay .brikpanel-ea-input::placeholder { color: #8a8a8a; }
        .brikpanel-ea-overlay .brikpanel-ea-input:focus {
            outline: none; border-color: #303030; box-shadow: 0 0 0 1px #303030;
        }
        .brikpanel-ea-error {
            margin: 0.375rem 0 0; padding: 0;
            font-size: 0.8125rem; color: #d72c0d; line-height: 1.4;
        }
        .brikpanel-ea-consent {
            display: flex; align-items: flex-start; gap: 0.5rem;
            margin: 0.875rem 0 0; cursor: pointer;
            font-size: 0.8125rem; line-height: 1.45; color: #616161;
        }
        .brikpanel-ea-consent input { margin-top: 0.15rem; flex-shrink: 0; }
        .brikpanel-ea-actions {
            display: flex; flex-wrap: wrap; gap: 0.5rem; align-items: center;
            margin-top: 1.25rem;
        }
        .brikpanel-ea-btn {
            display: inline-flex; align-items: center; justify-content: center;
            padding: 0.5rem 1rem; border-radius: 0.5rem;
            font-size: 0.8125rem; font-weight: 550; font-family: inherit;
            line-height: 1.2; cursor: pointer; border: none; text-decoration: none;
            transition: background 0.15s ease, color 0.15s ease;
        }
        .brikpanel-ea-btn:focus { outline: none; box-shadow: 0 0 0 2px #303030; }
        .brikpanel-ea-btn--primary {
            background: #303030; color: #fff;
            box-shadow: inset 0 -1px 0 rgba(0,0,0,0.2), inset 0 1px 0 rgba(255,255,255,0.1);
        }
        .brikpanel-ea-btn--primary:hover { background: #1a1a1a; color: #fff; }
        .brikpanel-ea-btn--primary:disabled { opacity: 0.6; cursor: default; }
        .brikpanel-ea-btn--ghost { background: transparent; color: #8a8a8a; }
        .brikpanel-ea-btn--ghost:hover { color: #303030; background: #f7f7f7; }
        .brikpanel-ea-fineprint {
            margin: 0.75rem 0 0; padding: 0;
            font-size: 0.75rem; color: #8a8a8a; line-height: 1.4;
        }
        .brikpanel-ea-wa {
            margin-top: 1.25rem; padding-top: 1.25rem;
            border-top: 1px solid #e3e3e3;
        }
        .brikpanel-ea-wa__text {
            margin: 0 0 0.75rem; padding: 0;
            font-size: 0.8125rem; line-height: 1.5; color: #616161;
        }
        .brikpanel-ea-wa__btn {
            display: inline-flex; align-items: center; gap: 0.5rem;
            padding: 0.5rem 1rem; border-radius: 0.5rem;
            background: #25d366; color: #fff; text-decoration: none;
            font-size: 0.8125rem; font-weight: 550; line-height: 1.2;
        }
        .brikpanel-ea-wa__btn:hover { background: #1ebe5d; color: #fff; }
        .brikpanel-ea-wa__btn svg { flex-shrink: 0; }
        @media (max-width: 600px) {
            .brikpanel-ea-modal { padding: 1.5rem 1.25rem 1.25rem; }
            .brikpanel-ea-title { font-size: 1.0625rem; }
        }
    </style>
    <?php
}

/* ── AJAX: store the lead ───────────────────────────────────────────────────── */
add_action( 'wp_ajax_brikpanel_ea_submit', 'brikpanel_ea_ajax_submit' );
function brikpanel_ea_ajax_submit() {
    check_ajax_referer( 'brikpanel_ea_nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'reason' => 'forbidden' ), 403 );
    }

    $email   = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
    $consent = isset( $_POST['consent'] ) && '1' === (string) wp_unslash( $_POST['consent'] );

    if ( ! $email || ! is_email( $email ) ) {
        wp_send_json_error( array( 'reason' => 'invalid_email' ), 400 );
    }
    if ( ! $consent ) {
        wp_send_json_error( array( 'reason' => 'no_consent' ), 400 );
    }

    // Entry point that produced this lead, kept separate so the dashboard and
    // settings segments can be told apart on the collection side.
    $sources = brikpanel_ea_sources();
    $source  = isset( $_POST['source'] ) ? sanitize_key( wp_unslash( $_POST['source'] ) ) : '';
    if ( ! in_array( $source, $sources, true ) ) {
        $source = $sources[0];
    }

    $lead = array(
        'id'          => brikpanel_ea_lead_id(),
        'email'       => $email,
        // `tool` and `milestone` belong to the retired waitlist flow but stay on
        // the wire: the deployed collection endpoint reads both, and plugin
        // versions in the field are years apart. Never drop a payload key.
        'tool'        => '',
        'consent'     => true,
        'source'      => $source,
        'milestone'   => 0,
        'site'        => home_url(),
        'locale'      => get_locale(),
        'version'     => defined( 'BRIKPANEL_VERSION' ) ? BRIKPANEL_VERSION : '',
        'created'     => time(),
    );

    // Durable record first — this is the "never lose it" guarantee.
    update_option( 'brikpanel_ea_lead', $lead, false );
    brikpanel_ea_outbox_put( $lead );

    // Never ask again.
    brikpanel_update_option( 'brikpanel_ea_subscribed', 1 );

    // Best-effort immediate delivery; failure just leaves it queued for retry.
    // The result tells the UI whether to offer the WhatsApp fallback.
    $delivered = brikpanel_ea_deliver( $lead['id'] );

    wp_send_json_success( array( 'delivered' => (bool) $delivered ) );
}

/* ── AJAX: permanently dismiss the dashboard card ───────────────────────────── */
add_action( 'wp_ajax_brikpanel_ea_card_dismiss', 'brikpanel_ea_ajax_card_dismiss' );
function brikpanel_ea_ajax_card_dismiss() {
    check_ajax_referer( 'brikpanel_ea_nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'reason' => 'forbidden' ), 403 );
    }
    update_option( 'brikpanel_newsletter_card_dismissed', 1, false );
    wp_send_json_success();
}

/* ── Dashboard cards ────────────────────────────────────────────────────────── */

/**
 * Render the dashboard cards, in priority order.
 *
 * The two cards are independent offers with independent dismissal flags, so
 * both can be on screen at once: the BrikMentor launch CTA first (it is the
 * revenue surface), the newsletter below it in a quieter treatment so the pair
 * does not read as two competing pitches.
 *
 * @return void
 */
add_action( 'brikpanel_dashboard_after_sections', 'brikpanel_ea_render_card' );
function brikpanel_ea_render_card() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
    if ( function_exists( 'brikpanel_brikmentor_promo_active' ) && brikpanel_brikmentor_promo_active() ) {
        brikpanel_ea_render_live_card();
    }
    if ( ! brikpanel_ea_card_available() ) {
        return;
    }
    ?>
    <div class="brikpanel-ea-card brikpanel-ea-card--muted" data-ea-card>
        <div class="brikpanel-ea-card__badge" aria-hidden="true">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><rect x="2.75" y="5" width="18.5" height="14" rx="2.5" stroke="currentColor" stroke-width="1.8"/><path d="M3.5 7l8.5 6 8.5-6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
        </div>
        <div class="brikpanel-ea-card__text">
            <p class="brikpanel-ea-card__title"><?php esc_html_e( 'Product news and store tips', 'brikpanel' ); ?></p>
            <p class="brikpanel-ea-card__body"><?php esc_html_e( 'New features, WooCommerce tips and ideas for growing your store, in a short email now and then. Unsubscribe any time.', 'brikpanel' ); ?></p>
        </div>
        <button type="button" class="brikpanel-ea-card__cta" data-ea-open data-ea-source="newsletter_dashboard"><?php esc_html_e( 'Subscribe', 'brikpanel' ); ?></button>
        <button type="button" class="brikpanel-ea-card__close" data-ea-card-dismiss aria-label="<?php esc_attr_e( 'Dismiss', 'brikpanel' ); ?>">
            <svg width="13" height="13" viewBox="0 0 14 14" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M1 1l12 12M13 1L1 13" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
        </button>
    </div>
    <?php brikpanel_ea_print_card_styles(); ?>
    <?php
}

/**
 * Card styles, shared by both dashboard cards (BrikMentor launch and
 * newsletter). Printed at most once per request.
 *
 * The base block is the union of what the two surfaces need; the `--muted`
 * modifier is the newsletter's quieter treatment (outlined badge, secondary
 * button) so the launch card keeps visual priority when both are on screen.
 * Styling the base restyles both.
 *
 * @return void
 */
function brikpanel_ea_print_card_styles() {
    static $printed = false;
    if ( $printed ) {
        return;
    }
    $printed = true;
    ?>
    <style>
        .brikpanel-ea-card {
            display: flex; align-items: center; gap: 1rem;
            background: #fff; border: 1px solid #e3e3e3; border-radius: 0.75rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.08);
            padding: 1rem 1.25rem; margin: 1rem 0 0;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
        }
        .brikpanel-ea-card__badge {
            flex-shrink: 0; width: 38px; height: 38px; border-radius: 50%;
            background: #303030; display: flex; align-items: center; justify-content: center;
        }
        .brikpanel-ea-card__text { flex: 1 1 auto; min-width: 0; }
        .brikpanel-ea-card__title { margin: 0 0 0.15rem; padding: 0; font-size: 0.9375rem; font-weight: 600; color: #303030; }
        .brikpanel-ea-card__body { margin: 0; padding: 0; font-size: 0.8125rem; line-height: 1.45; color: #616161; }
        .brikpanel-ea-card__cta {
            flex-shrink: 0; cursor: pointer; border: none; text-decoration: none;
            background: #303030; color: #fff; font-weight: 550; font-size: 0.8125rem;
            font-family: inherit; padding: 0.5rem 1rem; border-radius: 0.5rem;
            box-shadow: inset 0 -1px 0 rgba(0,0,0,0.2), inset 0 1px 0 rgba(255,255,255,0.1);
            transition: background 0.15s ease;
        }
        .brikpanel-ea-card__cta:hover { background: #1a1a1a; color: #fff; }
        .brikpanel-ea-card__learn { flex-shrink: 0; color: #616161; text-decoration: none; font-size: 0.8125rem; }
        .brikpanel-ea-card__learn:hover { color: #303030; }
        .brikpanel-ea-card__close {
            flex-shrink: 0; width: 26px; height: 26px; border-radius: 0.375rem;
            background: transparent; border: none; color: #8a8a8a; cursor: pointer;
            display: flex; align-items: center; justify-content: center; padding: 0;
            transition: background 0.15s ease, color 0.15s ease;
        }
        .brikpanel-ea-card__close:hover { background: #f7f7f7; color: #303030; }
        /* Quieter treatment: never competes with a paid-product CTA above it. */
        .brikpanel-ea-card--muted .brikpanel-ea-card__badge { background: #f1f1f1; color: #616161; }
        .brikpanel-ea-card--muted .brikpanel-ea-card__cta {
            background: #fff; color: #303030;
            box-shadow: inset 0 0 0 1px #e3e3e3, 0 1px 0 rgba(0,0,0,0.05);
        }
        .brikpanel-ea-card--muted .brikpanel-ea-card__cta:hover { background: #f7f7f7; color: #303030; }
        @media (max-width: 600px) {
            .brikpanel-ea-card { flex-wrap: wrap; }
            .brikpanel-ea-card__text { flex-basis: 100%; order: 2; }
        }
    </style>
    <?php
}

/* ── Launch-mode dashboard card ("BrikMentor is live", first month $1) ────────── */

/**
 * Fill a promo sentence's "%s" with the first-month price token.
 *
 * Thin wrapper over the promo module so this file keeps working on its own if
 * that module is ever missing (the loader is deliberately fault-tolerant); the
 * fallback simply drops the placeholder rather than printing a raw "%s".
 *
 * @param string $format Translated sentence containing a single %s.
 * @return string
 */
function brikpanel_ea_price_text( $format ) {
    if ( function_exists( 'brikpanel_brikmentor_price_text' ) ) {
        return brikpanel_brikmentor_price_text( $format );
    }
    return trim( str_replace( array( '%1$s', '%s' ), '', (string) $format ) );
}

function brikpanel_ea_render_live_card() {
    if ( get_option( 'brikpanel_bm_live_card_dismissed' ) ) {
        return;
    }
    $nonce        = wp_create_nonce( 'brikpanel_bm_promo_nonce' );
    $cta_url      = function_exists( 'brikpanel_brikmentor_url' ) ? brikpanel_brikmentor_url() : 'https://brksoft.com/brikmentor';
    $checkout_url = function_exists( 'brikpanel_brikmentor_checkout_url' ) ? brikpanel_brikmentor_checkout_url() : $cta_url;
    // Price is injected, never baked into the copy, so it stays one unbreakable
    // token in every locale (see brikpanel_brikmentor_price()).
    $title        = brikpanel_ea_price_text( __( 'BrikMentor is live: first month %s', 'brikpanel' ) );
    $cta_label    = brikpanel_ea_price_text( __( 'Try BrikMentor for %s', 'brikpanel' ) );
    ?>
    <div class="brikpanel-ea-card brikpanel-bm-live-card" data-bm-live-card data-nonce="<?php echo esc_attr( $nonce ); ?>">
        <div class="brikpanel-ea-card__badge" aria-hidden="true">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M12 2.5l2.95 5.98 6.6.96-4.78 4.66 1.13 6.58L12 17.58l-5.9 3.1 1.13-6.58L2.45 9.44l6.6-.96L12 2.5z" fill="#fff"/></svg>
        </div>
        <div class="brikpanel-ea-card__text">
            <p class="brikpanel-ea-card__title"><?php echo esc_html( $title ); ?></p>
            <p class="brikpanel-ea-card__body"><?php esc_html_e( 'The AI assistant and email marketing engine for your store data is out now. Automated cart recovery, win-back and segment campaigns, running inside WooCommerce.', 'brikpanel' ); ?></p>
        </div>
        <a class="brikpanel-ea-card__cta" href="<?php echo esc_url( $checkout_url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $cta_label ); ?></a>
        <a class="brikpanel-ea-card__learn" href="<?php echo esc_url( $cta_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Learn more', 'brikpanel' ); ?></a>
        <button type="button" class="brikpanel-ea-card__close" data-bm-live-card-dismiss aria-label="<?php esc_attr_e( 'Dismiss', 'brikpanel' ); ?>">
            <svg width="13" height="13" viewBox="0 0 14 14" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M1 1l12 12M13 1L1 13" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
        </button>
    </div>
    <?php brikpanel_ea_print_card_styles(); ?>
    <script>
    (function () {
        var card = document.querySelector('[data-bm-live-card]');
        if (!card) return;
        var btn = card.querySelector('[data-bm-live-card-dismiss]');
        btn.addEventListener('click', function () {
            var fd = new FormData();
            fd.append('action', 'brikpanel_bm_card_dismiss');
            fd.append('_ajax_nonce', card.getAttribute('data-nonce'));
            fetch(<?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>, { method: 'POST', body: fd, credentials: 'same-origin' });
            card.parentNode.removeChild(card);
        });
    })();
    </script>
    <?php
}

/* ── Durable outbox + delivery ──────────────────────────────────────────────── */

/** Stable per-site lead id (also acts as the idempotency key). */
function brikpanel_ea_lead_id() {
    $id = get_option( 'brikpanel_ea_lead_id' );
    if ( ! $id ) {
        $id = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : md5( home_url() . microtime() );
        add_option( 'brikpanel_ea_lead_id', $id, '', false );
    }
    return $id;
}

/** Put/replace a lead in the outbox keyed by id (resets the retry clock to now). */
function brikpanel_ea_outbox_put( array $lead ) {
    $box = get_option( 'brikpanel_ea_outbox', array() );
    if ( ! is_array( $box ) ) {
        $box = array();
    }
    $box[ $lead['id'] ] = array(
        'lead'     => $lead,
        'attempts' => isset( $box[ $lead['id'] ]['attempts'] ) ? (int) $box[ $lead['id'] ]['attempts'] : 0,
        'next'     => time(),
    );
    update_option( 'brikpanel_ea_outbox', $box, false );
    brikpanel_ea_schedule_flush( time() );
}

/** Remove a delivered lead from the outbox. */
function brikpanel_ea_outbox_remove( $id ) {
    $box = get_option( 'brikpanel_ea_outbox', array() );
    if ( is_array( $box ) && isset( $box[ $id ] ) ) {
        unset( $box[ $id ] );
        update_option( 'brikpanel_ea_outbox', $box, false );
    }
}

/** Backoff per attempt count; capped at one day. Retries never stop. */
function brikpanel_ea_backoff( $attempts ) {
    $steps = array( 5 * MINUTE_IN_SECONDS, 15 * MINUTE_IN_SECONDS, 30 * MINUTE_IN_SECONDS, HOUR_IN_SECONDS, 3 * HOUR_IN_SECONDS, 6 * HOUR_IN_SECONDS, 12 * HOUR_IN_SECONDS, DAY_IN_SECONDS );
    $i = min( max( 0, (int) $attempts ), count( $steps ) - 1 );
    return $steps[ $i ];
}

/**
 * Attempt to deliver a single queued lead. Removes it on a 2xx response;
 * otherwise bumps the attempt count and pushes the next retry into the future.
 *
 * @param string $id Lead id present in the outbox.
 * @return bool True on confirmed delivery.
 */
function brikpanel_ea_deliver( $id ) {
    $box = get_option( 'brikpanel_ea_outbox', array() );
    if ( ! is_array( $box ) || empty( $box[ $id ]['lead'] ) ) {
        return false;
    }
    $lead = $box[ $id ]['lead'];

    /** Allow the delivery endpoint to be overridden (staging/testing). */
    $endpoint = apply_filters( 'brikpanel_ea_endpoint', BRIKPANEL_EA_ENDPOINT );

    $resp = wp_remote_post(
        $endpoint,
        array(
            'timeout'     => 8,
            'redirection' => 2,
            'blocking'    => true,
            'sslverify'   => true,
            'headers'     => array( 'Content-Type' => 'application/json; charset=utf-8' ),
            'body'        => wp_json_encode( $lead ),
            'user-agent'  => 'BrikPanel/' . ( defined( 'BRIKPANEL_VERSION' ) ? BRIKPANEL_VERSION : '0' ) . '; ' . home_url(),
        )
    );

    $code = is_wp_error( $resp ) ? 0 : (int) wp_remote_retrieve_response_code( $resp );
    if ( $code >= 200 && $code < 300 ) {
        brikpanel_ea_outbox_remove( $id );
        return true;
    }

    // Failure: reschedule with backoff.
    $box = get_option( 'brikpanel_ea_outbox', array() );
    if ( is_array( $box ) && isset( $box[ $id ] ) ) {
        $attempts            = (int) $box[ $id ]['attempts'] + 1;
        $box[ $id ]['attempts'] = $attempts;
        $box[ $id ]['next']     = time() + brikpanel_ea_backoff( $attempts );
        update_option( 'brikpanel_ea_outbox', $box, false );
        brikpanel_ea_schedule_flush( $box[ $id ]['next'] );
    }
    return false;
}

/** Process every due lead in the outbox. */
add_action( 'brikpanel_ea_flush', 'brikpanel_ea_flush_outbox' );
function brikpanel_ea_flush_outbox() {
    $box = get_option( 'brikpanel_ea_outbox', array() );
    if ( ! is_array( $box ) || ! $box ) {
        return;
    }
    $now      = time();
    $earliest = 0;
    foreach ( $box as $id => $item ) {
        if ( (int) $item['next'] <= $now ) {
            brikpanel_ea_deliver( $id );
        }
    }
    // Reschedule a single event for the next still-pending lead.
    $box = get_option( 'brikpanel_ea_outbox', array() );
    if ( is_array( $box ) && $box ) {
        foreach ( $box as $item ) {
            $next     = (int) $item['next'];
            $earliest = $earliest ? min( $earliest, $next ) : $next;
        }
        brikpanel_ea_schedule_flush( $earliest );
    }
}

/** Schedule (or keep) a single flush event at/after $when. */
function brikpanel_ea_schedule_flush( $when ) {
    $when = max( (int) $when, time() + 30 );
    if ( ! wp_next_scheduled( 'brikpanel_ea_flush' ) ) {
        wp_schedule_single_event( $when, 'brikpanel_ea_flush' );
    }
}

/**
 * Fallback flush for sites with WP-Cron disabled or unreliable. Runs at most
 * once every few minutes, only when something is actually due.
 */
add_action( 'admin_init', 'brikpanel_ea_admin_init_flush' );
function brikpanel_ea_admin_init_flush() {
    $box = get_option( 'brikpanel_ea_outbox', array() );
    if ( ! is_array( $box ) || ! $box ) {
        return;
    }
    $last = (int) get_option( 'brikpanel_ea_last_flush', 0 );
    if ( time() - $last < 5 * MINUTE_IN_SECONDS ) {
        return;
    }
    $due = false;
    foreach ( $box as $item ) {
        if ( (int) $item['next'] <= time() ) { $due = true; break; }
    }
    if ( ! $due ) {
        return;
    }
    update_option( 'brikpanel_ea_last_flush', time(), false );
    brikpanel_ea_flush_outbox();
}

/* ── Settings-page row (bottom of the BrikPanel General settings) ───────────── */

/**
 * Append the newsletter section to the bottom of the General settings list.
 *
 * Priority 999 keeps it last, below the BrikMentor promo section which
 * registers at 998 (see brikpanel-brikmentor-promo.php). Two filters at the
 * same priority would order by file-load order instead of by intent.
 *
 * The title id is intentionally unmapped so
 * brikpanel_settings_section_for_title() defaults it to General — which is
 * also the section brikpanel_ea_is_settings_page() gates the modal on. Change
 * one and you must change the other.
 *
 * Unlike the retired waitlist, this section renders on every store, including
 * ones that already run BrikMentor: a product newsletter is not a pitch for a
 * product the merchant has already bought.
 *
 * @param array $fields
 * @return array
 */
add_filter( 'brikpanel_settings_fields', 'brikpanel_ea_settings_field', 999 );
function brikpanel_ea_settings_field( $fields ) {
    $fields[] = array(
        'type'  => 'title',
        'id'    => 'brk_newsletter_title',
        'title' => __( 'Newsletter', 'brikpanel' ),
    );
    $fields[] = array(
        'type' => 'brikpanel_early_access',
        'id'   => 'brikpanel_ea_settings_field',
    );
    $fields[] = array(
        'type' => 'sectionend',
        'id'   => 'brk_newsletter_title',
    );
    return $fields;
}

/** Render the newsletter settings row (CTA, or a confirmation once subscribed). */
add_action( 'woocommerce_admin_field_brikpanel_early_access', 'brikpanel_ea_render_settings_field' );
function brikpanel_ea_render_settings_field( $field ) {
    $subscribed = (bool) get_option( 'brikpanel_ea_subscribed' );
    ?>
    <tr valign="top">
        <th scope="row" class="titledesc">
            <label><?php esc_html_e( 'Product news and tips', 'brikpanel' ); ?></label>
        </th>
        <td class="forminp">
            <?php if ( $subscribed ) : ?>
                <p class="brikpanel-newsletter-joined">
                    <span class="brikpanel-newsletter-check" aria-hidden="true">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M5 12.5l4.2 4.2L19 7" stroke="#1a8917" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </span>
                    <?php esc_html_e( "You're subscribed to our newsletter. You can unsubscribe from any email we send.", 'brikpanel' ); ?>
                </p>
            <?php else : ?>
                <p class="brikpanel-newsletter-desc">
                    <?php esc_html_e( 'New BrikPanel and BrikMentor features, WooCommerce tips and store growth ideas, straight to your inbox. Unsubscribe any time.', 'brikpanel' ); ?>
                </p>
                <button type="button" class="button brikpanel-newsletter-btn" data-ea-open data-ea-source="newsletter_settings">
                    <?php esc_html_e( 'Subscribe', 'brikpanel' ); ?>
                </button>
            <?php endif; ?>
            <style>
                .brikpanel-newsletter-desc { max-width: 640px; color: #616161; margin: 0 0 0.75rem; }
                .brikpanel-newsletter-btn { display: inline-flex; align-items: center; }
                .brikpanel-newsletter-joined { display: flex; align-items: center; gap: 0.5rem; color: #1a8917; font-weight: 550; margin: 0; }
                .brikpanel-newsletter-check { display: inline-flex; }
            </style>
        </td>
    </tr>
    <?php
}
