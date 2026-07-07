<?php
/**
 * BrikPanel — BrikMentor Early-Access Capture
 *
 * Shows a high-intent, milestone-triggered modal asking the store owner to join
 * the private beta list for BrikMentor (an upcoming AI assistant for their store
 * data). It fires once at 100 completed orders and, if dismissed, once more at
 * 200 completed orders.
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

// Completed-order milestones at which the early-access modal may appear.
if ( ! defined( 'BRIKPANEL_EA_MILESTONES' ) ) {
    // Highest first is not required; the resolver picks the highest reached.
    define( 'BRIKPANEL_EA_MILESTONES', '100,200' );
}

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

/** @return int[] Sorted ascending list of milestone thresholds. */
function brikpanel_ea_milestones() {
    $list = array_map( 'intval', explode( ',', (string) BRIKPANEL_EA_MILESTONES ) );
    $list = array_values( array_filter( $list, static function ( $n ) { return $n > 0; } ) );
    sort( $list );
    return $list ? $list : array( 100, 200 );
}

/* ── Order counter (independent of the review-notice counter) ───────────────────
 * The review system stops counting once a review is dismissed, so early-access
 * needs its own counter that keeps running until the owner subscribes or has
 * passed the final milestone. */
add_action( 'woocommerce_order_status_completed', 'brikpanel_ea_increment_orders', 10, 1 );
function brikpanel_ea_increment_orders( $order_id ) {
    if ( get_option( 'brikpanel_ea_subscribed' ) ) {
        return;
    }
    $milestones = brikpanel_ea_milestones();
    $final      = end( $milestones );
    if ( (int) get_option( 'brikpanel_ea_dismissed_upto', 0 ) >= $final ) {
        return; // No milestone left to reach.
    }
    $count = (int) get_option( 'brikpanel_ea_orders_count', 0 );
    update_option( 'brikpanel_ea_orders_count', $count + 1, false );
}

/**
 * Resolve the milestone that currently applies (0 when none).
 *
 * @return int
 */
function brikpanel_ea_active_milestone() {
    $count   = (int) get_option( 'brikpanel_ea_orders_count', 0 );
    $reached = 0;
    foreach ( brikpanel_ea_milestones() as $threshold ) {
        if ( $count >= $threshold ) {
            $reached = $threshold;
        }
    }
    return $reached;
}

/**
 * Should the modal be shown to the current request?
 *
 * @return int The applicable milestone (truthy) or 0 to stay hidden.
 */
function brikpanel_ea_should_show() {
    if ( get_option( 'brikpanel_ea_subscribed' ) ) {
        return 0;
    }
    $milestone = brikpanel_ea_active_milestone();
    if ( $milestone <= 0 ) {
        return 0;
    }
    if ( (int) get_option( 'brikpanel_ea_dismissed_upto', 0 ) >= $milestone ) {
        return 0;
    }
    return $milestone;
}

/**
 * Is the always-on, low-friction self-serve card available? It is the second,
 * volume entry point (vs. the high-intent milestone modal): present on the
 * dashboard until the owner subscribes or dismisses it.
 *
 * @return bool
 */
function brikpanel_ea_card_available() {
    if ( get_option( 'brikpanel_ea_subscribed' ) ) {
        return false;
    }
    if ( get_option( 'brikpanel_ea_card_dismissed' ) ) {
        return false;
    }
    return true;
}

/** True on the BrikPanel dashboard screen (where the self-serve card lives). */
function brikpanel_ea_is_dashboard() {
    return isset( $_GET['page'] ) && 'brikpanel-dashboard' === $_GET['page']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen check.
}

/** True on the WooCommerce > Settings > BrikPanel tab (where the settings option lives). */
function brikpanel_ea_is_settings_page() {
    // phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only screen check.
    return isset( $_GET['page'], $_GET['tab'] ) && 'wc-settings' === $_GET['page'] && 'brikpanel' === $_GET['tab'];
    // phpcs:enable
}

/**
 * True on one of BrikPanel's own full-screen admin pages (page=brikpanel*).
 * The milestone modal auto-opens only here, so it stays "contextual" per the
 * wp.org admin-experience guideline instead of firing site-wide.
 *
 * @return bool
 */
function brikpanel_ea_is_brikpanel_page() {
    return isset( $_GET['page'] ) && 0 === strpos( (string) $_GET['page'], 'brikpanel' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen check.
}

/* ── Render the modal ───────────────────────────────────────────────────────── */
add_action( 'admin_footer', 'brikpanel_ea_render_modal' );
function brikpanel_ea_render_modal() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
    // Render the modal when it can be opened. The milestone auto-open is limited
    // to BrikPanel's own screens (contextual), never site-wide, to respect the
    // wp.org admin-experience guideline. The self-serve card (dashboard) and the
    // settings option are the always-available, low-friction entry points.
    $milestone_raw  = brikpanel_ea_should_show();
    $milestone      = ( $milestone_raw && brikpanel_ea_is_brikpanel_page() ) ? $milestone_raw : 0;
    $not_subscribed = ! get_option( 'brikpanel_ea_subscribed' );
    $self_serve_ok  = $not_subscribed && ( ( brikpanel_ea_is_dashboard() && brikpanel_ea_card_available() ) || brikpanel_ea_is_settings_page() );
    if ( ! $milestone && ! $self_serve_ok ) {
        return;
    }
    $autoopen = $milestone > 0;
    $source   = $milestone > 0 ? 'milestone_' . $milestone : 'self_serve';

    $nonce = wp_create_nonce( 'brikpanel_ea_nonce' );

    if ( $autoopen ) {
        /* translators: %d: number of completed orders reached (100 or 200). */
        $title = sprintf( __( '%d orders in. Want first access to BrikMentor?', 'brikpanel' ), $milestone );
    } else {
        $title = __( 'Get early access to BrikMentor', 'brikpanel' );
    }

    $body = __( "We're building BrikMentor: an AI assistant that reads your store data, spots what is driving (and leaking) revenue, and turns it into action, from smart suggestions to email and marketing automation that reaches the right customers, all inside WooCommerce. Your store now has enough data to get real value from it. Join the private beta list and we'll invite you the moment it opens.", 'brikpanel' );

    // Tool dropdown options. Keys are stable identifiers (not shown); labels are
    // translatable. The empty key is the placeholder.
    $tools = array(
        ''          => __( 'Select an option', 'brikpanel' ),
        'none'      => __( 'None yet', 'brikpanel' ),
        'klaviyo'   => 'Klaviyo',
        'mailchimp' => 'Mailchimp',
        'brevo'     => 'Brevo (Sendinblue)',
        'omnisend'  => 'Omnisend',
        'mailerlite'=> 'MailerLite',
        'hostinger_reach' => 'Hostinger Reach',
        'other'     => __( 'Other', 'brikpanel' ),
    );
    ?>
    <div class="brikpanel-ea-overlay" id="brikpanel-ea" data-nonce="<?php echo esc_attr( $nonce ); ?>" data-autoopen="<?php echo $autoopen ? '1' : '0'; ?>" data-source="<?php echo esc_attr( $source ); ?>" role="dialog" aria-modal="true" aria-labelledby="brikpanel-ea-title" hidden>
        <div class="brikpanel-ea-modal" role="document">
            <button type="button" class="brikpanel-ea-close" data-ea-dismiss aria-label="<?php esc_attr_e( 'Close', 'brikpanel' ); ?>">
                <svg width="14" height="14" viewBox="0 0 14 14" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M1 1l12 12M13 1L1 13" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
            </button>

            <!-- Step 1: pitch + email -->
            <div class="brikpanel-ea-step" data-ea-step="pitch">
                <div class="brikpanel-ea-badge" aria-hidden="true">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M12 2.5l2.95 5.98 6.6.96-4.78 4.66 1.13 6.58L12 17.58l-5.9 3.1 1.13-6.58L2.45 9.44l6.6-.96L12 2.5z" fill="#fff"/></svg>
                </div>
                <h2 class="brikpanel-ea-title" id="brikpanel-ea-title"><?php echo esc_html( $title ); ?></h2>
                <p class="brikpanel-ea-body"><?php echo esc_html( $body ); ?></p>

                <form class="brikpanel-ea-form" data-ea-form novalidate>
                    <label class="brikpanel-ea-label" for="brikpanel-ea-email"><?php esc_html_e( 'Your email', 'brikpanel' ); ?></label>
                    <input type="email" id="brikpanel-ea-email" class="brikpanel-ea-input" data-ea-email autocomplete="email" placeholder="<?php esc_attr_e( 'you@store.com', 'brikpanel' ); ?>" required>
                    <p class="brikpanel-ea-error" data-ea-error-email hidden><?php esc_html_e( 'Please enter a valid email address.', 'brikpanel' ); ?></p>

                    <label class="brikpanel-ea-consent">
                        <input type="checkbox" data-ea-consent>
                        <span><?php esc_html_e( 'Email me about the BrikMentor beta and occasional BrikPanel tips. I can unsubscribe at any time.', 'brikpanel' ); ?></span>
                    </label>
                    <p class="brikpanel-ea-error" data-ea-error-consent hidden><?php esc_html_e( 'Please tick the box so we can email you the invite.', 'brikpanel' ); ?></p>

                    <div class="brikpanel-ea-actions">
                        <button type="submit" class="brikpanel-ea-btn brikpanel-ea-btn--primary" data-ea-submit><?php esc_html_e( 'Join the early access list', 'brikpanel' ); ?></button>
                        <button type="button" class="brikpanel-ea-btn brikpanel-ea-btn--ghost" data-ea-dismiss><?php esc_html_e( 'Not now', 'brikpanel' ); ?></button>
                    </div>
                    <p class="brikpanel-ea-fineprint"><?php esc_html_e( 'No spam. Just the beta invite and the occasional heads-up.', 'brikpanel' ); ?></p>
                </form>
            </div>

            <!-- Step 2: optional tooling question -->
            <div class="brikpanel-ea-step" data-ea-step="tool" hidden>
                <div class="brikpanel-ea-badge brikpanel-ea-badge--ok" aria-hidden="true">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M5 12.5l4.2 4.2L19 7" stroke="#fff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </div>
                <h2 class="brikpanel-ea-title"><?php esc_html_e( "You're on the list.", 'brikpanel' ); ?></h2>
                <p class="brikpanel-ea-body"><?php esc_html_e( 'One quick question, totally optional: which email or marketing tool do you use today? It helps us build the right thing.', 'brikpanel' ); ?></p>

                <label class="brikpanel-ea-label" for="brikpanel-ea-tool"><?php esc_html_e( 'Email / marketing tool', 'brikpanel' ); ?></label>
                <select id="brikpanel-ea-tool" class="brikpanel-ea-input" data-ea-tool>
                    <?php foreach ( $tools as $key => $label ) : ?>
                        <option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option>
                    <?php endforeach; ?>
                </select>

                <div class="brikpanel-ea-actions">
                    <button type="button" class="brikpanel-ea-btn brikpanel-ea-btn--primary" data-ea-tool-save><?php esc_html_e( 'Done', 'brikpanel' ); ?></button>
                    <button type="button" class="brikpanel-ea-btn brikpanel-ea-btn--ghost" data-ea-tool-skip><?php esc_html_e( 'Skip', 'brikpanel' ); ?></button>
                </div>
            </div>

            <!-- Step 3: thank you -->
            <div class="brikpanel-ea-step" data-ea-step="done" hidden>
                <div class="brikpanel-ea-badge brikpanel-ea-badge--ok" aria-hidden="true">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M5 12.5l4.2 4.2L19 7" stroke="#fff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </div>
                <h2 class="brikpanel-ea-title"><?php esc_html_e( 'Thank you.', 'brikpanel' ); ?></h2>
                <p class="brikpanel-ea-body"><?php esc_html_e( "We'll email you the moment the BrikMentor beta opens. Thanks for helping shape it.", 'brikpanel' ); ?></p>
                <div class="brikpanel-ea-actions">
                    <button type="button" class="brikpanel-ea-btn brikpanel-ea-btn--primary" data-ea-dismiss><?php esc_html_e( 'Close', 'brikpanel' ); ?></button>
                </div>
            </div>

            <!-- Fallback: shown only when this site cannot reach our server, so a
                 lead is never lost even on hosts that block outbound requests. -->
            <div class="brikpanel-ea-wa" data-ea-whatsapp hidden>
                <p class="brikpanel-ea-wa__text"><?php esc_html_e( "Heads up: we could not reach our server from your site (some hosts block outbound connections). We saved your request and will keep trying, but to be safe you can message us on WhatsApp and we'll add you to the list by hand.", 'brikpanel' ); ?></p>
                <a class="brikpanel-ea-wa__btn" data-ea-wa href="#" target="_blank" rel="noopener noreferrer">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M12.04 2c-5.46 0-9.91 4.45-9.91 9.91 0 1.75.46 3.45 1.32 4.95L2 22l5.25-1.38c1.45.79 3.08 1.21 4.79 1.21 5.46 0 9.91-4.45 9.91-9.91S17.5 2 12.04 2zm5.8 14.01c-.25.7-1.44 1.33-1.99 1.41-.53.08-1.18.11-1.9-.12-.44-.14-1-.32-1.72-.63-3.03-1.31-5-4.36-5.16-4.56-.15-.2-1.22-1.62-1.22-3.09 0-1.47.77-2.19 1.05-2.49.27-.3.59-.37.79-.37.2 0 .39 0 .56.01.18.01.42-.07.66.5.25.59.84 2.04.91 2.19.07.15.12.32.02.52-.1.2-.15.32-.29.5-.15.17-.31.39-.44.52-.15.15-.3.31-.13.6.17.3.76 1.25 1.63 2.02 1.12.99 2.06 1.3 2.36 1.45.3.15.47.12.65-.07.18-.2.74-.86.94-1.16.2-.3.39-.25.66-.15.27.1 1.71.81 2 .96.3.15.5.22.57.34.07.12.07.7-.18 1.4z"/></svg>
                    <?php esc_html_e( 'Request access on WhatsApp', 'brikpanel' ); ?>
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
        var autoopen = overlay.getAttribute('data-autoopen') === '1';
        var source   = overlay.getAttribute('data-source') || 'self_serve';
        var sentDismiss = false;
        var lastEmail = '';
        var waNumber   = <?php echo wp_json_encode( preg_replace( '/\D/', '', (string) apply_filters( 'brikpanel_ea_whatsapp', BRIKPANEL_EA_WHATSAPP ) ) ); ?>;
        var waTemplate = <?php echo wp_json_encode( __( 'Hello, I would like early access to BrikMentor. My email is {EMAIL} and my store is {SITE}.', 'brikpanel' ) ); ?>;
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
            if (src) source = src;
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
        function dismiss() {
            // Only the milestone path advances the milestone state; closing the
            // self-serve modal just hides it (the card stays for next time).
            if (!sentDismiss && source.indexOf('milestone') === 0) {
                sentDismiss = true;
                post('brikpanel_ea_dismiss', {});
            }
            close();
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
                    show('tool');
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

        overlay.querySelector('[data-ea-tool-save]').addEventListener('click', function () {
            var tool = overlay.querySelector('[data-ea-tool]').value || '';
            post('brikpanel_ea_tool', { tool: tool }).then(function () { show('done'); });
        });
        overlay.querySelector('[data-ea-tool-skip]').addEventListener('click', function () { show('done'); });

        overlay.querySelectorAll('[data-ea-dismiss]').forEach(function (el) {
            el.addEventListener('click', dismiss);
        });
        overlay.addEventListener('mousedown', function (e) { if (e.target === overlay) dismiss(); });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && !overlay.hidden) dismiss();
        });

        // Self-serve triggers (dashboard card CTA, settings option). Each may
        // carry its own source via data-ea-source (defaults to self_serve).
        document.querySelectorAll('[data-ea-open]').forEach(function (el) {
            el.addEventListener('click', function (e) {
                e.preventDefault();
                open(el.getAttribute('data-ea-source') || 'self_serve');
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
        if (autoopen) open(source);
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
            position: absolute; top: 0.75rem; right: 0.75rem;
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
        .brikpanel-ea-input {
            width: 100%; box-sizing: border-box;
            padding: 0.625rem 0.75rem;
            font-size: 0.875rem; color: #303030;
            border: 1px solid #8a8a8a; border-radius: 0.5rem;
            background: #fff; line-height: 1.5; min-height: 0;
            transition: border-color 0.15s ease, box-shadow 0.15s ease;
        }
        .brikpanel-ea-input::placeholder { color: #8a8a8a; }
        .brikpanel-ea-input:focus {
            outline: none; border-color: #303030; box-shadow: 0 0 0 1px #303030;
        }
        select.brikpanel-ea-input { height: auto; }
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

/* ── AJAX: store the lead (step 1) ──────────────────────────────────────────── */
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

    // Entry point that produced this lead, kept separate so the milestone
    // (high-intent) and self-serve (volume) segments can be nurtured differently.
    $source = isset( $_POST['source'] ) ? sanitize_key( wp_unslash( $_POST['source'] ) ) : 'self_serve';
    if ( ! in_array( $source, array( 'self_serve', 'settings', 'milestone_100', 'milestone_200' ), true ) ) {
        $source = 'self_serve';
    }

    $lead = array(
        'id'          => brikpanel_ea_lead_id(),
        'email'       => $email,
        'tool'        => '',
        'consent'     => true,
        'source'      => $source,
        'milestone'   => brikpanel_ea_active_milestone(),
        'site'        => home_url(),
        'locale'      => get_locale(),
        'version'     => defined( 'BRIKPANEL_VERSION' ) ? BRIKPANEL_VERSION : '',
        'created'     => time(),
    );

    // Durable record first — this is the "never lose it" guarantee.
    update_option( 'brikpanel_ea_lead', $lead, false );
    brikpanel_ea_outbox_put( $lead );

    // Never ask again.
    update_option( 'brikpanel_ea_subscribed', 1, false );

    // Best-effort immediate delivery; failure just leaves it queued for retry.
    // The result tells the UI whether to offer the WhatsApp fallback.
    $delivered = brikpanel_ea_deliver( $lead['id'] );

    wp_send_json_success( array( 'delivered' => (bool) $delivered ) );
}

/* ── AJAX: attach the optional tool answer (step 2) ─────────────────────────── */
add_action( 'wp_ajax_brikpanel_ea_tool', 'brikpanel_ea_ajax_tool' );
function brikpanel_ea_ajax_tool() {
    check_ajax_referer( 'brikpanel_ea_nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'reason' => 'forbidden' ), 403 );
    }

    $allowed = array( 'none', 'klaviyo', 'mailchimp', 'brevo', 'omnisend', 'mailerlite', 'hostinger_reach', 'other' );
    $tool    = isset( $_POST['tool'] ) ? sanitize_key( wp_unslash( $_POST['tool'] ) ) : '';
    if ( $tool && ! in_array( $tool, $allowed, true ) ) {
        $tool = 'other';
    }

    $lead = get_option( 'brikpanel_ea_lead', array() );
    if ( is_array( $lead ) && ! empty( $lead['id'] ) ) {
        $lead['tool'] = $tool;
        update_option( 'brikpanel_ea_lead', $lead, false );
        brikpanel_ea_outbox_put( $lead ); // Re-queue with the updated field (same id → idempotent).
        brikpanel_ea_deliver( $lead['id'] );
    }
    wp_send_json_success();
}

/* ── AJAX: dismiss (advance to next milestone, no nagging) ──────────────────── */
add_action( 'wp_ajax_brikpanel_ea_dismiss', 'brikpanel_ea_ajax_dismiss' );
function brikpanel_ea_ajax_dismiss() {
    check_ajax_referer( 'brikpanel_ea_nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'reason' => 'forbidden' ), 403 );
    }
    $milestone = brikpanel_ea_active_milestone();
    $current   = (int) get_option( 'brikpanel_ea_dismissed_upto', 0 );
    if ( $milestone > $current ) {
        update_option( 'brikpanel_ea_dismissed_upto', $milestone, false );
    }
    wp_send_json_success();
}

/* ── AJAX: permanently dismiss the self-serve dashboard card ─────────────────── */
add_action( 'wp_ajax_brikpanel_ea_card_dismiss', 'brikpanel_ea_ajax_card_dismiss' );
function brikpanel_ea_ajax_card_dismiss() {
    check_ajax_referer( 'brikpanel_ea_nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'reason' => 'forbidden' ), 403 );
    }
    update_option( 'brikpanel_ea_card_dismissed', 1, false );
    wp_send_json_success();
}

/* ── Self-serve card on the dashboard (low-friction, always-on entry point) ──── */
add_action( 'brikpanel_dashboard_after_sections', 'brikpanel_ea_render_card' );
function brikpanel_ea_render_card() {
    if ( ! current_user_can( 'manage_options' ) || ! brikpanel_ea_card_available() ) {
        return;
    }
    ?>
    <div class="brikpanel-ea-card" data-ea-card>
        <div class="brikpanel-ea-card__badge" aria-hidden="true">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M12 2.5l2.95 5.98 6.6.96-4.78 4.66 1.13 6.58L12 17.58l-5.9 3.1 1.13-6.58L2.45 9.44l6.6-.96L12 2.5z" fill="#fff"/></svg>
        </div>
        <div class="brikpanel-ea-card__text">
            <p class="brikpanel-ea-card__title"><?php esc_html_e( 'BrikMentor is coming', 'brikpanel' ); ?></p>
            <p class="brikpanel-ea-card__body"><?php esc_html_e( 'An AI assistant that reads your store data and turns it into action, including email and marketing automation. Join the early access list to try it first.', 'brikpanel' ); ?></p>
        </div>
        <button type="button" class="brikpanel-ea-card__cta" data-ea-open><?php esc_html_e( 'Join the list', 'brikpanel' ); ?></button>
        <button type="button" class="brikpanel-ea-card__close" data-ea-card-dismiss aria-label="<?php esc_attr_e( 'Dismiss', 'brikpanel' ); ?>">
            <svg width="13" height="13" viewBox="0 0 14 14" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M1 1l12 12M13 1L1 13" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
        </button>
    </div>
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
            flex-shrink: 0; cursor: pointer; border: none;
            background: #303030; color: #fff; font-weight: 550; font-size: 0.8125rem;
            font-family: inherit; padding: 0.5rem 1rem; border-radius: 0.5rem;
            box-shadow: inset 0 -1px 0 rgba(0,0,0,0.2), inset 0 1px 0 rgba(255,255,255,0.1);
            transition: background 0.15s ease;
        }
        .brikpanel-ea-card__cta:hover { background: #1a1a1a; }
        .brikpanel-ea-card__close {
            flex-shrink: 0; width: 26px; height: 26px; border-radius: 0.375rem;
            background: transparent; border: none; color: #8a8a8a; cursor: pointer;
            display: flex; align-items: center; justify-content: center; padding: 0;
            transition: background 0.15s ease, color 0.15s ease;
        }
        .brikpanel-ea-card__close:hover { background: #f7f7f7; color: #303030; }
        @media (max-width: 600px) {
            .brikpanel-ea-card { flex-wrap: wrap; }
            .brikpanel-ea-card__text { flex-basis: 100%; order: 2; }
        }
    </style>
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

/* ── Settings-page option (bottom of the BrikPanel General settings) ─────────── */

/**
 * Append the BrikMentor early-access option to the bottom of the General
 * settings list. Priority 999 keeps it last; the title id is intentionally
 * unmapped so brikpanel_settings_section_for_title() defaults it to General.
 *
 * @param array $fields
 * @return array
 */
add_filter( 'brikpanel_settings_fields', 'brikpanel_ea_settings_field', 999 );
function brikpanel_ea_settings_field( $fields ) {
    $fields[] = array(
        'type'  => 'title',
        'id'    => 'brk_brikmentor_title',
        'title' => __( 'BrikMentor early access', 'brikpanel' ),
    );
    $fields[] = array(
        'type' => 'brikpanel_early_access',
        'id'   => 'brikpanel_ea_settings_field',
    );
    $fields[] = array(
        'type' => 'sectionend',
        'id'   => 'brk_brikmentor_title',
    );
    return $fields;
}

/** Render the early-access settings row (CTA, or a confirmation once joined). */
add_action( 'woocommerce_admin_field_brikpanel_early_access', 'brikpanel_ea_render_settings_field' );
function brikpanel_ea_render_settings_field( $field ) {
    $subscribed = (bool) get_option( 'brikpanel_ea_subscribed' );
    ?>
    <tr valign="top">
        <th scope="row" class="titledesc">
            <label><?php esc_html_e( 'Join the early access list', 'brikpanel' ); ?></label>
        </th>
        <td class="forminp">
            <?php if ( $subscribed ) : ?>
                <p class="brikpanel-ea-settings-joined">
                    <span class="brikpanel-ea-settings-check" aria-hidden="true">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M5 12.5l4.2 4.2L19 7" stroke="#1a8917" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </span>
                    <?php esc_html_e( "You're on the BrikMentor early access list. We'll email you when the beta opens.", 'brikpanel' ); ?>
                </p>
            <?php else : ?>
                <p class="brikpanel-ea-settings-desc">
                    <?php esc_html_e( 'BrikMentor is an upcoming AI assistant and email marketing platform that reads your store data and helps you act on it. Join the private beta list to try it first.', 'brikpanel' ); ?>
                </p>
                <button type="button" class="button brikpanel-ea-settings-btn" data-ea-open data-ea-source="settings">
                    <?php esc_html_e( 'Join the early access list', 'brikpanel' ); ?>
                </button>
            <?php endif; ?>
            <style>
                .brikpanel-ea-settings-desc { max-width: 640px; color: #616161; margin: 0 0 0.75rem; }
                .brikpanel-ea-settings-btn { display: inline-flex; align-items: center; }
                .brikpanel-ea-settings-joined { display: flex; align-items: center; gap: 0.5rem; color: #1a8917; font-weight: 550; margin: 0; }
                .brikpanel-ea-settings-check { display: inline-flex; }
            </style>
        </td>
    </tr>
    <?php
}
