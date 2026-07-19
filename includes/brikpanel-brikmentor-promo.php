<?php
/**
 * BrikPanel — BrikMentor Launch Surfaces
 *
 * Once BrikMentor is publicly available, the waitlist funnel flips into a
 * launch funnel. Everything here sits behind a single kill-switch option
 * (brikpanel_brikmentor_live, default OFF):
 *
 *   - Flag OFF  → this module renders nothing; the early-access waitlist
 *                 (includes/brikpanel-early-access.php) behaves exactly as
 *                 before.
 *   - Flag ON   → a floating BrikMentor button appears on the
 *                 Abandoned Carts and Customer Analytics screens with a
 *                 context-aware pitch panel, and the existing waitlist
 *                 surfaces turn into "BrikMentor is live" CTAs (handled in
 *                 brikpanel-early-access.php via the helpers below).
 *   - BrikMentor plugin installed → every promotional surface auto-hides,
 *                 regardless of the flag.
 *
 * All copy lives in PHP behind __( … , 'brikpanel' ); the inline script only
 * ever receives strings through wp_json_encode() of translated values.
 *
 * @package BrikPanel
 * @since   3.2.12
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* ── State helpers ──────────────────────────────────────────────────────────── */

/**
 * Is the BrikMentor product publicly launched? Single source of truth for the
 * launch flag. Default off: nothing changes until the option is flipped.
 *
 * @return bool
 */
function brikpanel_brikmentor_is_live() {
    $value = get_option( 'brikpanel_brikmentor_live', 'no' );
    return in_array( $value, array( 'yes', '1', 1, true ), true );
}

/**
 * Is the BrikMentor plugin itself installed and active on this site? When it
 * is, promoting it is pointless, so every launch surface auto-hides.
 *
 * @return bool
 */
function brikpanel_brikmentor_installed() {
    // BRIKMENTOR_VERSION is defined unconditionally in brikmentor.php; the
    // class names match the plugin's Brikmentor_* prefix (belt and braces in
    // case a future version defers the constant).
    $installed = defined( 'BRIKMENTOR_VERSION' )
        || class_exists( 'Brikmentor_Install', false )
        || class_exists( 'Brikmentor_Flows', false )
        || class_exists( 'BrikMentor', false );

    /**
     * Lets the (future) BrikMentor plugin, or tests, declare themselves
     * present without depending on a hardcoded class name.
     *
     * @param bool $installed
     */
    return (bool) apply_filters( 'brikpanel_brikmentor_installed', $installed );
}

/**
 * Should any launch surface render? Live flag on AND the product not already
 * installed here.
 *
 * @return bool
 */
function brikpanel_brikmentor_promo_active() {
    return brikpanel_brikmentor_is_live() && ! brikpanel_brikmentor_installed();
}

/**
 * Landing URL for every launch CTA. Overridable via option so campaigns can
 * be re-pointed without a release.
 *
 * @return string
 */
function brikpanel_brikmentor_url() {
    $url = get_option( 'brikpanel_brikmentor_url', '' );
    if ( ! is_string( $url ) || '' === trim( $url ) ) {
        $url = 'https://brksoft.com/brikmentor';
    }
    return esc_url_raw( $url );
}

/* ── Floating launch button (Abandoned Carts + Customer Analytics) ──────────── */

/**
 * The screens that get the floating button, keyed by their page slug. Each
 * screen carries its own pitch copy; the Customer Analytics screen swaps the
 * copy per active tab (LTV / RFM / Cohort) client-side.
 *
 * @return array<string, array>
 */
function brikpanel_brikmentor_fab_screens() {
    return array(
        'brikpanel-abandoned-carts'    => array(
            'context' => 'carts',
            'title'   => __( 'Recover these carts on autopilot', 'brikpanel' ),
            'body'    => __( 'Sending cart recovery emails consistently, with the right strategy, directly lifts a store\'s total revenue by 15-25% on average. Across the industry, roughly 70 of every 100 shoppers leave without completing checkout, which makes this automation the highest-ROI marketing channel there is. Don\'t leave money on the table: with BrikMentor, your first month is just $1.', 'brikpanel' ),
        ),
        'brikpanel-customer-analytics' => array(
            'context'  => 'analytics',
            'title'    => __( 'Turn this data into revenue', 'brikpanel' ),
            // Default body (LTV tab is the landing tab); the rest are swapped
            // in client-side when the merchant changes tabs.
            'body'     => __( 'Your best customers are worth many times a single order. BrikMentor turns your LTV data into automated email campaigns that bring high-value customers back to buy again. First month just $1.', 'brikpanel' ),
            'variants' => array(
                'ltv'    => __( 'Your best customers are worth many times a single order. BrikMentor turns your LTV data into automated email campaigns that bring high-value customers back to buy again. First month just $1.', 'brikpanel' ),
                'rfm'    => __( 'These segments are ready-made audiences. BrikMentor automatically sends each RFM segment the right email and wins back at-risk customers before they churn. First month just $1.', 'brikpanel' ),
                'cohort' => __( 'Every cohort that fades is repeat revenue lost. BrikMentor runs win-back campaigns that re-activate lapsed cohorts on autopilot. First month just $1.', 'brikpanel' ),
            ),
        ),
    );
}

/** Current screen's floating-button config, or null when not applicable. */
function brikpanel_brikmentor_current_fab_screen() {
    if ( ! isset( $_GET['page'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen check.
        return null;
    }
    $page    = sanitize_key( wp_unslash( $_GET['page'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    $screens = brikpanel_brikmentor_fab_screens();
    return isset( $screens[ $page ] ) ? $screens[ $page ] : null;
}

add_action( 'admin_footer', 'brikpanel_brikmentor_render_fab' );
function brikpanel_brikmentor_render_fab() {
    if ( ! brikpanel_brikmentor_promo_active() ) {
        return;
    }
    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        return;
    }
    $screen = brikpanel_brikmentor_current_fab_screen();
    if ( ! $screen ) {
        return;
    }

    $cta_url      = brikpanel_brikmentor_url();
    $checkout_url = function_exists( 'brikpanel_brikmentor_checkout_url' ) ? brikpanel_brikmentor_checkout_url() : $cta_url;
    $variants     = isset( $screen['variants'] ) ? $screen['variants'] : array();
    ?>
    <div class="brikpanel-bm-fab-root" id="brikpanel-bm-fab">
        <div class="brikpanel-bm-panel" data-bm-panel role="dialog" aria-labelledby="brikpanel-bm-panel-title" hidden>
            <button type="button" class="brikpanel-bm-panel__close" data-bm-panel-close aria-label="<?php esc_attr_e( 'Close', 'brikpanel' ); ?>">
                <svg width="12" height="12" viewBox="0 0 14 14" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M1 1l12 12M13 1L1 13" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
            </button>
            <div class="brikpanel-bm-panel__head">
                <span class="brikpanel-bm-panel__badge" aria-hidden="true">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M12 2.5l2.95 5.98 6.6.96-4.78 4.66 1.13 6.58L12 17.58l-5.9 3.1 1.13-6.58L2.45 9.44l6.6-.96L12 2.5z" fill="#fff"/></svg>
                </span>
                <span class="brikpanel-bm-panel__kicker"><?php esc_html_e( 'BrikMentor is live', 'brikpanel' ); ?></span>
            </div>
            <h2 class="brikpanel-bm-panel__title" id="brikpanel-bm-panel-title"><?php echo esc_html( $screen['title'] ); ?></h2>
            <p class="brikpanel-bm-panel__body" data-bm-body><?php echo esc_html( $screen['body'] ); ?></p>
            <div class="brikpanel-bm-panel__actions">
                <a class="brikpanel-bm-panel__cta brikpanel-bm-checkout" data-bm-checkout href="<?php echo esc_url( $checkout_url ); ?>">
                    <?php esc_html_e( 'Try BrikMentor for $1', 'brikpanel' ); ?>
                </a>
                <a class="brikpanel-bm-panel__ghost" href="<?php echo esc_url( $cta_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Learn more', 'brikpanel' ); ?></a>
                <button type="button" class="brikpanel-bm-panel__ghost" data-bm-panel-close><?php esc_html_e( 'Not now', 'brikpanel' ); ?></button>
            </div>
        </div>
        <button type="button" class="brikpanel-bm-fab" data-bm-fab aria-haspopup="dialog" aria-expanded="false" title="BrikMentor">
            <span class="screen-reader-text"><?php esc_html_e( 'Open the BrikMentor panel', 'brikpanel' ); ?></span>
            <svg class="brikpanel-bm-fab__star" width="22" height="22" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M12 2.5l2.95 5.98 6.6.96-4.78 4.66 1.13 6.58L12 17.58l-5.9 3.1 1.13-6.58L2.45 9.44l6.6-.96L12 2.5z" fill="#fff"/></svg>
        </button>
    </div>
    <style>
        .brikpanel-bm-fab-root {
            position: fixed; right: 24px; bottom: 24px; z-index: 9991;
            display: flex; flex-direction: column; align-items: flex-end; gap: 0.75rem;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
        }
        .brikpanel-bm-fab {
            width: 52px; height: 52px; border-radius: 50%;
            background: #303030; border: none; cursor: pointer; padding: 0;
            display: flex; align-items: center; justify-content: center;
            box-shadow: 0 4px 14px rgba(0, 0, 0, 0.25), inset 0 1px 0 rgba(255, 255, 255, 0.12);
            transition: background 0.15s ease, transform 0.15s ease;
            animation: brikpanel-bm-breathe 3.2s ease-in-out infinite;
        }
        .brikpanel-bm-fab:hover { background: #1a1a1a; transform: scale(1.05); animation-play-state: paused; }
        .brikpanel-bm-fab:focus { outline: none; box-shadow: 0 0 0 2px #fff, 0 0 0 4px #303030; }
        .brikpanel-bm-fab__star {
            display: block;
            transform-origin: 50% 52%;
            animation: brikpanel-bm-acrobat 7s cubic-bezier(.34,.06,.36,.96) infinite;
        }
        @keyframes brikpanel-bm-breathe {
            0%, 100% { box-shadow: 0 4px 14px rgba(0, 0, 0, 0.25), 0 0 0 0 rgba(48, 48, 48, 0.30), inset 0 1px 0 rgba(255, 255, 255, 0.12); }
            50%      { box-shadow: 0 4px 14px rgba(0, 0, 0, 0.25), 0 0 0 9px rgba(48, 48, 48, 0),   inset 0 1px 0 rgba(255, 255, 255, 0.12); }
        }
        /* Acrobatic routine: wind-up → overshoot spin → bounce-settle →
           squash-and-hop → coin flip → wiggle → rest. Ends on rotate(720deg)
           (visually identical to 0deg) so the loop is seamless. */
        @keyframes brikpanel-bm-acrobat {
            0%   { transform: rotate(0deg) scale(1); }
            6%   { transform: rotate(-38deg) scale(0.85); }
            16%  { transform: rotate(410deg) scale(1.22); }
            21%  { transform: rotate(338deg) scale(0.94); }
            25%  { transform: rotate(360deg) scale(1); }
            33%  { transform: rotate(360deg) translateY(-7px) scale(1.1); }
            38%  { transform: rotate(360deg) translateY(1px) scale(1.18, 0.8); }
            42%  { transform: rotate(360deg) translateY(-3px) scale(0.96, 1.06); }
            46%  { transform: rotate(360deg) translateY(0) scale(1); }
            54%  { transform: rotate(360deg) rotateY(200deg) scale(1.08); }
            62%  { transform: rotate(360deg) rotateY(360deg) scale(1); }
            70%  { transform: rotate(384deg) scale(1.06); }
            76%  { transform: rotate(338deg) scale(1.03); }
            81%  { transform: rotate(368deg) scale(1.01); }
            85%  { transform: rotate(357deg) scale(1); }
            88%  { transform: rotate(360deg) scale(1); }
            100% { transform: rotate(720deg) scale(1); }
        }
        /* The show is over once the merchant clicks: while the panel is open
           the button sits still (upright star, no pulse) and resumes when the
           panel closes. */
        .brikpanel-bm-fab-root.is-open .brikpanel-bm-fab,
        .brikpanel-bm-fab-root.is-open .brikpanel-bm-fab__star { animation: none; }
        @media (prefers-reduced-motion: reduce) {
            .brikpanel-bm-fab, .brikpanel-bm-fab__star { animation: none; }
        }
        .brikpanel-bm-panel {
            width: 320px; max-width: calc(100vw - 48px);
            background: #fff; border: 1px solid #e3e3e3; border-radius: 0.75rem;
            box-shadow: 0 8px 28px rgba(0, 0, 0, 0.16);
            padding: 1.25rem 1.5rem 1.25rem;
            position: relative;
            animation: brikpanel-bm-rise 0.22s cubic-bezier(.2,.7,.3,1);
        }
        .brikpanel-bm-panel[hidden] { display: none; }
        @keyframes brikpanel-bm-rise { from { transform: translateY(8px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
        .brikpanel-bm-panel__close {
            position: absolute; top: 0.625rem; right: 0.625rem;
            width: 26px; height: 26px; border-radius: 0.375rem;
            background: transparent; border: none; color: #8a8a8a; cursor: pointer;
            display: flex; align-items: center; justify-content: center; padding: 0;
            transition: background 0.15s ease, color 0.15s ease;
        }
        .brikpanel-bm-panel__close:hover { background: #f7f7f7; color: #303030; }
        .brikpanel-bm-panel__head { display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.75rem; }
        .brikpanel-bm-panel__badge {
            width: 28px; height: 28px; border-radius: 50%;
            background: #303030; display: flex; align-items: center; justify-content: center; flex-shrink: 0;
        }
        .brikpanel-bm-panel__kicker {
            font-size: 0.75rem; font-weight: 550; color: #1a8917;
            text-transform: uppercase; letter-spacing: 0.04em;
        }
        .brikpanel-bm-panel__title {
            margin: 0 0 0.5rem; padding: 0;
            font-size: 1rem; font-weight: 600; line-height: 1.35; color: #303030;
        }
        .brikpanel-bm-panel__body {
            margin: 0 0 1rem; padding: 0;
            font-size: 0.8125rem; line-height: 1.55; color: #616161;
        }
        .brikpanel-bm-panel__actions { display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap; }
        .brikpanel-bm-panel__cta {
            display: inline-flex; align-items: center; justify-content: center;
            padding: 0.5rem 1rem; border-radius: 0.5rem;
            background: #303030; color: #fff; text-decoration: none;
            font-size: 0.8125rem; font-weight: 550; line-height: 1.2;
            box-shadow: inset 0 -1px 0 rgba(0,0,0,0.2), inset 0 1px 0 rgba(255,255,255,0.1);
            transition: background 0.15s ease;
        }
        .brikpanel-bm-panel__cta:hover { background: #1a1a1a; color: #fff; }
        .brikpanel-bm-panel__cta:focus { outline: none; box-shadow: 0 0 0 2px #303030; color: #fff; }
        .brikpanel-bm-panel__ghost {
            background: transparent; border: none; cursor: pointer;
            padding: 0.5rem 0.75rem; border-radius: 0.5rem;
            font-size: 0.8125rem; font-weight: 550; font-family: inherit; color: #8a8a8a;
            transition: background 0.15s ease, color 0.15s ease;
        }
        .brikpanel-bm-panel__ghost:hover { background: #f7f7f7; color: #303030; }
        @media (max-width: 782px) {
            .brikpanel-bm-fab-root { right: 16px; bottom: 16px; }
        }
    </style>
    <script>
    (function () {
        var root = document.getElementById('brikpanel-bm-fab');
        if (!root) return;
        var panel   = root.querySelector('[data-bm-panel]');
        var fab     = root.querySelector('[data-bm-fab]');
        var body    = root.querySelector('[data-bm-body]');
        // Tab-specific pitch copy (Customer Analytics). Empty object elsewhere.
        var variants = <?php echo wp_json_encode( $variants ); ?>;

        function setOpen(open) {
            panel.hidden = !open;
            fab.setAttribute('aria-expanded', open ? 'true' : 'false');
            root.classList.toggle('is-open', open);
        }
        fab.addEventListener('click', function () { setOpen(panel.hidden); });
        root.querySelectorAll('[data-bm-panel-close]').forEach(function (el) {
            el.addEventListener('click', function () { setOpen(false); });
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && !panel.hidden) setOpen(false);
        });
        document.addEventListener('mousedown', function (e) {
            if (!panel.hidden && !root.contains(e.target)) setOpen(false);
        });

        // On Customer Analytics, follow the LTV / RFM / Cohort tabs so the
        // pitch always talks about the data on screen.
        if (body && variants && Object.keys(variants).length) {
            document.addEventListener('click', function (e) {
                var tab = e.target && e.target.closest ? e.target.closest('.bp-ca-tab') : null;
                if (!tab) return;
                var key = tab.getAttribute('data-tab');
                if (key && variants[key]) body.textContent = variants[key];
            });
        }
    })();
    </script>
    <?php
}

/* ── AJAX: permanently dismiss the launch card on the dashboard ──────────────── */
add_action( 'wp_ajax_brikpanel_bm_card_dismiss', 'brikpanel_bm_ajax_card_dismiss' );
function brikpanel_bm_ajax_card_dismiss() {
    check_ajax_referer( 'brikpanel_bm_promo_nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'reason' => 'forbidden' ), 403 );
    }
    update_option( 'brikpanel_bm_live_card_dismissed', 1, false );
    wp_send_json_success();
}
