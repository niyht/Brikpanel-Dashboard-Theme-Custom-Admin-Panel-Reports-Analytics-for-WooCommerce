<?php
/**
 * BrikPanel — BrikMentor Launch Surfaces
 *
 * BrikMentor is publicly available, so the waitlist funnel is now a launch
 * funnel. Everything here sits behind a single kill-switch option
 * (brikpanel_brikmentor_live, default ON):
 *
 *   - Flag ON   → a floating BrikMentor button appears on the
 *                 Abandoned Carts and Customer Analytics screens with a
 *                 context-aware pitch panel, and the existing waitlist
 *                 surfaces turn into "BrikMentor is live" CTAs (handled in
 *                 brikpanel-early-access.php via the helpers below).
 *   - Flag OFF  → this module renders nothing and the early-access waitlist
 *                 (includes/brikpanel-early-access.php) behaves as it did
 *                 before launch. Nothing writes this value: it exists so a
 *                 store that does not want the promotion can set the option
 *                 to 'no' and be left alone.
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
 * launch flag.
 *
 * Defaults ON, and the default is what actually ships the promotion: nothing
 * in the plugin ever writes this option, there is no remote flag behind it and
 * no upgrade routine sets it, so a default of 'no' would mean no store ever
 * sees a launch surface. Reading it as an option at all is only so a merchant
 * who does not want the promotion can silence it with a single 'no'.
 *
 * @return bool
 */
function brikpanel_brikmentor_is_live() {
    $value = get_option( 'brikpanel_brikmentor_live', 'yes' );
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

/**
 * Stable per-store id for this store's BrikMentor purchase attempt.
 *
 * WHY IT EXISTS. The relay treats this value as "one store's one purchase", and
 * two things hang off it that silently do not happen without it:
 *
 *  1. SESSION REUSE. Without a claim_id the relay has nothing to key on, so
 *     every single click on a launch CTA mints a brand new Stripe Checkout
 *     session. A merchant who clicks twice ends up with two live payment pages
 *     open, which is two ways to pay for the same thing.
 *  2. AUTOMATIC DUPLICATE REFUND. When a store does pay twice, the relay
 *     cancels the second subscription and refunds it automatically, and it
 *     recognises that case by the repeated claim_id. Without one the double
 *     payment is only flagged for a human to refund by hand.
 *
 * The id is generated once, lazily, and stored. Lazily matters: this function is
 * only reached from a rendering CTA, and CTAs only render once the launch flag
 * is on, so a pre-launch install never writes the option at all.
 *
 * @return string UUIDv4, in the exact lowercase-hyphenated shape the relay validates.
 */
function brikpanel_brikmentor_claim_id() {
    $id = get_option( 'brikpanel_brikmentor_claim_id', '' );
    if ( ! is_string( $id )
        || ! preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $id ) ) {
        $id = wp_generate_uuid4();
        update_option( 'brikpanel_brikmentor_claim_id', $id, false );
    }
    return $id;
}

/**
 * Checkout URL for the "$1 launch" CTAs. A plain link the merchant follows to
 * the brksoft.com relay's Stripe Checkout; on success the relay lands them on
 * its own welcome page (license key + plugin download + install steps). No
 * install ever happens from inside wp-admin — that is what keeps BrikPanel
 * within wp.org Guideline 8 (a directory plugin may not install an
 * off-directory plugin from an external server).
 *
 * `src` + `site_url` + `claim_id` ride along so the relay can attribute the
 * sale, recognise a returning store (the first-month discount is
 * first-purchase-only), reuse an already-open Checkout session instead of
 * opening another one, and auto-refund a genuine double payment.
 * `site_url` is rawurlencode()'d before add_query_arg() because WordPress's
 * build_query() does NOT url-encode values — this matches the exact wire format
 * the relay already parses.
 *
 * Overridable via option/filter so campaigns can be re-pointed without a release.
 *
 * @return string
 */
function brikpanel_brikmentor_checkout_url() {
    $base = get_option( 'brikpanel_brikmentor_checkout_url', '' );
    if ( ! is_string( $base ) || '' === trim( $base ) ) {
        $relay = get_option( 'brikmentor_relay_url' );
        $relay = ( is_string( $relay ) && '' !== trim( $relay ) )
            ? untrailingslashit( $relay )
            : 'https://brksoft.com';
        $base = $relay . '/wp-json/brikmentor-relay/v1/checkout';
    }
    $base = add_query_arg(
        array(
            'src'      => 'panel',
            'site_url' => rawurlencode( home_url() ),
            'claim_id' => brikpanel_brikmentor_claim_id(),
        ),
        $base
    );
    /**
     * Filter the BrikMentor Stripe Checkout URL used by the launch CTAs.
     *
     * @param string $base Full checkout URL including src/site_url args.
     */
    return esc_url_raw( (string) apply_filters( 'brikpanel_brikmentor_checkout_url', $base ) );
}

/* ── Promo price token ──────────────────────────────────────────────────────── */

/**
 * The first-month promo price, ready to print.
 *
 * The amount and its currency symbol are a single typographic unit. Locales
 * that put the symbol last ("1 $") used to wrap between the two whenever the
 * pair happened to land on a line end, leaving a stray "$." opening the next
 * line. Every space inside the token is therefore collapsed to a non-breaking
 * space, so the pair can never be split no matter the locale or the container
 * width.
 *
 * Keeping the price out of the sentences also means a campaign change is one
 * string, not nine translations.
 *
 * @return string Price token, e.g. "$1" or "1 $" with a non-breaking space.
 */
function brikpanel_brikmentor_price() {
    /* translators: first-month promo price in US dollars. Locales that place the symbol after the amount should translate this as "1 $". The space is turned into a non-breaking space automatically, so the amount and its symbol never split across two lines. */
    $price     = trim( _x( '$1', 'first-month promo price', 'brikpanel' ) );
    $unbroken  = preg_replace( '/[\s\x{00A0}]+/u', "\u{00A0}", $price );
    $price     = ( null === $unbroken || '' === $unbroken ) ? $price : $unbroken;

    /**
     * Filter the first-month promo price token shown on every launch surface.
     *
     * @param string $price Display price, symbol and amount already joined.
     */
    return (string) apply_filters( 'brikpanel_brikmentor_price', $price );
}

/**
 * Glue a figure to the unit that follows it.
 *
 * Several locales write the unit detached ("25 %", "1 $"). When such a pair
 * lands on a line end the symbol drops alone onto the next line, which reads
 * like a typo. A non-breaking space keeps the pair together everywhere, at no
 * cost to locales that write the symbol first ("%25").
 *
 * @param string $text Translated copy.
 * @return string
 */
function brikpanel_brikmentor_nb_units( $text ) {
    $out = preg_replace( '/(\d)\x20+([%$€£₺¥])/u', "\$1\u{00A0}\$2", (string) $text );
    return ( null === $out ) ? (string) $text : $out;
}

/**
 * Fill a promo sentence's "%s" with the price token.
 *
 * Deliberately a literal swap rather than sprintf(): this copy is full of
 * literal percent signs ("15-25%", "100% free") and the translations come from
 * outside contributors, so a locale string can easily read as a malformed
 * format. sprintf() answers that with a mangled sentence ("100% free" becomes
 * "1000.000000ree") or, in PHP 8, a thrown Error. A marketing line is never
 * worth either, and the price token needs no formatting flags.
 *
 * @param string $format Translated sentence containing a single %s.
 * @return string
 */
function brikpanel_brikmentor_price_text( $format ) {
    $format = (string) $format;
    if ( false === strpos( $format, '%s' ) && false === strpos( $format, '%1$s' ) ) {
        return brikpanel_brikmentor_nb_units( $format );
    }
    $text = str_replace( array( '%1$s', '%s' ), brikpanel_brikmentor_price(), $format );
    // A translator who escaped their literal percent signs still gets one.
    $text = str_replace( '%%', '%', $text );

    return brikpanel_brikmentor_nb_units( $text );
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
    $screens = array(
        'brikpanel-abandoned-carts'    => array(
            'context' => 'carts',
            'title'   => __( 'Recover these carts on autopilot', 'brikpanel' ),
            // The price never lives inside the pitch copy: it is printed on its
            // own line below, where it cannot wrap or fight the sentence.
            'body'    => __( 'Roughly 70 of every 100 shoppers leave without completing checkout. Well-timed cart recovery emails bring back 5-10% of total revenue in a store that runs them properly. It is the one sales channel you set up once and that never asks for your time again. Don\'t leave that money on the table.', 'brikpanel' ),
            'variants' => array(
                // Opened from the padlock in the Phone column rather than from
                // the floating button. It has to answer what was actually
                // clicked, and answer it honestly: the WhatsApp draft is opened
                // by hand, one cart at a time. Only the email is automatic, and
                // promising otherwise here would sell the wrong product.
                'lock' => __( 'Unlocking gives you the phone number behind each cart and a ready-made WhatsApp message to open with one click. The reminder emails then go out on their own, and they bring back 5-10% of total revenue in a store that runs them properly.', 'brikpanel' ),
            ),
        ),
        'brikpanel-customer-analytics' => array(
            'context'  => 'analytics',
            'title'    => __( 'Turn this data into revenue', 'brikpanel' ),
            // Default body (LTV tab is the landing tab); the rest are swapped
            // in client-side when the merchant changes tabs.
            // Each variant describes a flow BrikMentor actually ships (post
            // purchase, winback), in the terms of the tab on screen. Nothing
            // here may promise targeting the product does not have: there is
            // no LTV-keyed campaign and no cohort-keyed campaign, so neither
            // is claimed.
            'body'     => __( 'Lifetime value grows one repeat order at a time. BrikMentor follows up a few days after each completed order with the products other customers bought alongside it, then starts a win-back series for the buyers who drift away.', 'brikpanel' ),
            'variants' => array(
                'ltv'    => __( 'Lifetime value grows one repeat order at a time. BrikMentor follows up a few days after each completed order with the products other customers bought alongside it, then starts a win-back series for the buyers who drift away.', 'brikpanel' ),
                'rfm'    => __( 'These segments are ready-made audiences. BrikMentor reads them straight from BrikPanel: pick the ones to re-engage (At Risk, Can\'t Lose Them and Hibernating by default) and it sends a reminder first, a coupon only if that is not enough.', 'brikpanel' ),
                'cohort' => __( 'A retention curve that flattens out is repeat revenue leaking away. BrikMentor scans your customers daily, starts a win-back series for anyone who has not ordered in months, and cancels it the moment they buy again.', 'brikpanel' ),
            ),
        ),
    );

    // Figures keep their unit ("25 %") on the same line in every locale.
    foreach ( $screens as $slug => $screen ) {
        $screens[ $slug ]['body'] = brikpanel_brikmentor_nb_units( $screen['body'] );
        if ( ! empty( $screen['variants'] ) ) {
            $screens[ $slug ]['variants'] = array_map( 'brikpanel_brikmentor_nb_units', $screen['variants'] );
        }
    }

    return $screens;
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
    // The one objection every merchant on this screen already has, pointed at
    // the section of the landing page that answers it at length. Appended only
    // when the campaign URL does not carry a fragment of its own.
    $why_url      = ( false === strpos( $cta_url, '#' ) ) ? $cta_url . '#bm-free' : $cta_url;
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
            <div class="brikpanel-bm-panel__offer">
                <span class="brikpanel-bm-panel__offer-label"><?php esc_html_e( 'First month', 'brikpanel' ); ?></span>
                <span class="brikpanel-bm-panel__offer-price"><?php echo esc_html( brikpanel_brikmentor_price() ); ?></span>
            </div>
            <div class="brikpanel-bm-panel__actions">
                <a class="brikpanel-bm-panel__cta" href="<?php echo esc_url( $checkout_url ); ?>" target="_blank" rel="noopener noreferrer">
                    <?php esc_html_e( 'Try BrikMentor', 'brikpanel' ); ?>
                </a>
                <div class="brikpanel-bm-panel__links">
                    <a class="brikpanel-bm-panel__ghost" href="<?php echo esc_url( $why_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Wouldn\'t a free plugin be enough?', 'brikpanel' ); ?></a>
                    <a class="brikpanel-bm-panel__ghost" href="<?php echo esc_url( $cta_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Learn more', 'brikpanel' ); ?></a>
                </div>
            </div>
        </div>
        <button type="button" class="brikpanel-bm-fab" data-bm-fab aria-haspopup="dialog" aria-expanded="false" title="BrikMentor">
            <span class="screen-reader-text"><?php esc_html_e( 'Open the BrikMentor panel', 'brikpanel' ); ?></span>
            <svg class="brikpanel-bm-fab__star" width="22" height="22" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M12 2.5l2.95 5.98 6.6.96-4.78 4.66 1.13 6.58L12 17.58l-5.9 3.1 1.13-6.58L2.45 9.44l6.6-.96L12 2.5z" fill="#fff"/></svg>
        </button>
    </div>
    <?php brikpanel_brikmentor_print_star_motion(); ?>
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
        /* The acrobat routine itself is printed by
           brikpanel_brikmentor_print_star_motion(), because the launch
           announcement reuses it on screens this <style> never reaches. */
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
            margin: 0 0 0.875rem; padding: 0;
            font-size: 0.8125rem; line-height: 1.55; color: #616161;
        }
        /* The offer is a price tag, not a sentence: label left, amount right,
           on its own line so no locale can wrap the amount away from its
           currency symbol. */
        .brikpanel-bm-panel__offer {
            display: flex; align-items: baseline; justify-content: space-between; gap: 0.75rem;
            margin: 0 0 1rem; padding: 0.5rem 0.75rem;
            background: #f7f7f7; border: 1px solid #e3e3e3; border-radius: 0.5rem;
        }
        .brikpanel-bm-panel__offer-label {
            font-size: 0.75rem; font-weight: 550; color: #616161;
            text-transform: uppercase; letter-spacing: 0.04em;
        }
        .brikpanel-bm-panel__offer-price {
            font-size: 1.125rem; font-weight: 600; color: #303030;
            line-height: 1.2; white-space: nowrap; font-variant-numeric: tabular-nums;
        }
        .brikpanel-bm-panel__actions { display: flex; flex-direction: column; gap: 0.375rem; }
        .brikpanel-bm-panel__cta {
            display: flex; align-items: center; justify-content: center; text-align: center;
            padding: 0.5625rem 1rem; border-radius: 0.5rem;
            background: #303030; color: #fff; text-decoration: none;
            font-size: 0.8125rem; font-weight: 550; line-height: 1.2;
            box-shadow: inset 0 -1px 0 rgba(0,0,0,0.2), inset 0 1px 0 rgba(255,255,255,0.1);
            transition: background 0.15s ease;
        }
        .brikpanel-bm-panel__cta:hover { background: #1a1a1a; color: #fff; }
        .brikpanel-bm-panel__cta:focus { outline: none; box-shadow: 0 0 0 2px #303030; color: #fff; }
        /* Two links of very different lengths, in a fixed-width panel, in nine
           languages: es, fr and pl do not fit them on one line and were pushing
           past the panel edge. They wrap to a second line instead, so the row
           grows rather than overflowing, and the seven languages that do fit
           keep the single row. nowrap keeps each link whole, so what wraps is
           the pair and never the phrase. */
        .brikpanel-bm-panel__links {
            display: flex; flex-wrap: wrap; align-items: center;
            justify-content: space-between; gap: 0 0.25rem; margin: 0 -0.5rem;
        }
        .brikpanel-bm-panel__links .brikpanel-bm-panel__ghost { white-space: nowrap; }
        .brikpanel-bm-panel__ghost {
            background: transparent; border: none; cursor: pointer; text-decoration: none;
            padding: 0.375rem 0.5rem; border-radius: 0.375rem;
            font-size: 0.8125rem; font-weight: 550; font-family: inherit; color: #8a8a8a;
            transition: background 0.15s ease, color 0.15s ease;
        }
        .brikpanel-bm-panel__ghost:hover { background: #f7f7f7; color: #303030; text-decoration: none; }
        .brikpanel-bm-panel__ghost:focus { outline: none; box-shadow: 0 0 0 2px #303030; color: #303030; }
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

        // The pitch this screen loaded with, so an opener that names no variant
        // can put it back. Without it, opening once from the padlock would leave
        // its copy behind for every later open from the floating button.
        var defaultBody = body ? body.textContent : '';
        // The tab-driven variant in force, if any (Customer Analytics). It wins
        // over the default so the floating button keeps talking about the data
        // on screen, and loses to an opener that names its own variant.
        var screenKey = null;

        function pitchFor(key) {
            if (!body) { return; }
            if (key && variants[key]) { body.textContent = variants[key]; return; }
            if (screenKey && variants[screenKey]) { body.textContent = variants[screenKey]; return; }
            body.textContent = defaultBody;
        }

        // Whatever opened the panel, so focus can be handed back on close. The
        // panel is role="dialog": opened from a control deep in a table without
        // moving focus, it leaves a keyboard user stranded behind it.
        var opener = null;

        function setOpen(open) {
            panel.hidden = !open;
            fab.setAttribute('aria-expanded', open ? 'true' : 'false');
            root.classList.toggle('is-open', open);
            if (open) {
                var close = panel.querySelector('[data-bm-panel-close]');
                if (close) { close.focus(); }
            } else if (opener) {
                opener.focus();
                opener = null;
            }
        }
        fab.addEventListener('click', function () {
            if (panel.hidden) { pitchFor(null); }
            opener = fab;
            setOpen(panel.hidden);
        });

        // Anything anywhere on the page opens this panel by carrying
        // [data-bm-open]. Delegated, so a control built later by fetch - the
        // padlock in the Abandoned Carts table - needs no wiring and no global,
        // and setOpen() stays the single place the panel's state changes. The
        // listener only exists when the panel does, so a screen without it
        // never calls preventDefault() and the control's own href is followed.
        document.addEventListener('click', function (e) {
            var el = e.target && e.target.closest ? e.target.closest('[data-bm-open]') : null;
            if (!el) { return; }
            e.preventDefault();
            pitchFor(el.getAttribute('data-bm-variant'));
            opener = el;
            setOpen(true);
        });
        root.querySelectorAll('[data-bm-panel-close]').forEach(function (el) {
            el.addEventListener('click', function () { setOpen(false); });
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && !panel.hidden) setOpen(false);
        });
        document.addEventListener('mousedown', function (e) {
            // An opener outside the panel is not "outside" for this purpose:
            // closing here on mousedown and reopening on the click that follows
            // replays the rise animation for a frame and reads as a flicker.
            if (e.target && e.target.closest && e.target.closest('[data-bm-open]')) { return; }
            if (!panel.hidden && !root.contains(e.target)) setOpen(false);
        });

        // On Customer Analytics, follow the LTV / RFM / Cohort tabs so the
        // pitch always talks about the data on screen.
        if (body && variants && Object.keys(variants).length) {
            document.addEventListener('click', function (e) {
                var tab = e.target && e.target.closest ? e.target.closest('.bp-ca-tab') : null;
                if (!tab) return;
                var key = tab.getAttribute('data-tab');
                if (key && variants[key]) { screenKey = key; body.textContent = variants[key]; }
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

/* ── Settings-page row (BrikPanel General settings) ─────────────────────────── */

/**
 * Add the BrikMentor launch section to the BrikPanel General settings.
 *
 * The promo_active() test wraps the whole push, not just the renderer: a
 * section whose renderer prints nothing still emits `<h2>…</h2><table
 * class="form-table"></table>`, which the settings-card wrapper in
 * front-end/orders/brikpanel-orders.php happily turns into a visible empty
 * card.
 *
 * Priority 998 puts this above the newsletter section (999) in
 * brikpanel-early-access.php. Do not give both the same priority: they would
 * then order by file-load order rather than by intent.
 *
 * @param array $fields
 * @return array
 */
add_filter( 'brikpanel_settings_fields', 'brikpanel_brikmentor_settings_field', 998 );
function brikpanel_brikmentor_settings_field( $fields ) {
    if ( ! brikpanel_brikmentor_promo_active() ) {
        return $fields;
    }
    $fields[] = array(
        'type'  => 'title',
        'id'    => 'brk_brikmentor_title',
        'title' => __( 'BrikMentor', 'brikpanel' ),
    );
    $fields[] = array(
        // Pseudo-field: it stores nothing, so it is excluded from the
        // import/export option map (see brikpanel-import-export.php).
        'type' => 'brikpanel_brikmentor_promo',
        'id'   => 'brikpanel_brikmentor_promo_field',
    );
    $fields[] = array(
        'type' => 'sectionend',
        'id'   => 'brk_brikmentor_title',
    );
    return $fields;
}

/**
 * Render the BrikMentor launch settings row.
 *
 * @param array $field Unused; WooCommerce passes the field definition.
 * @return void
 */
add_action( 'woocommerce_admin_field_brikpanel_brikmentor_promo', 'brikpanel_brikmentor_render_settings_field' );
function brikpanel_brikmentor_render_settings_field( $field ) {
    $cta_url      = brikpanel_brikmentor_url();
    $checkout_url = brikpanel_brikmentor_checkout_url();
    ?>
    <tr valign="top">
        <th scope="row" class="titledesc">
            <label><?php esc_html_e( 'BrikMentor is live', 'brikpanel' ); ?></label>
        </th>
        <td class="forminp">
            <p class="brikpanel-bm-settings-desc">
                <?php
                // Price is injected, never baked into the copy, so it stays one
                // unbreakable token in every locale.
                echo esc_html(
                    brikpanel_brikmentor_price_text( __( 'BrikMentor, the AI assistant and email marketing engine for your store data, is out now: automated cart recovery, win-back and segment campaigns inside WooCommerce. First month just %s.', 'brikpanel' ) )
                );
                ?>
            </p>
            <a class="button button-primary brikpanel-bm-settings-btn" href="<?php echo esc_url( $checkout_url ); ?>" target="_blank" rel="noopener noreferrer">
                <?php echo esc_html( brikpanel_brikmentor_price_text( __( 'Try BrikMentor for %s', 'brikpanel' ) ) ); ?>
            </a>
            <a class="brikpanel-bm-settings-learn" href="<?php echo esc_url( $cta_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Learn more', 'brikpanel' ); ?></a>
            <style>
                .brikpanel-bm-settings-desc { max-width: 640px; color: #616161; margin: 0 0 0.75rem; }
                .brikpanel-bm-settings-btn { display: inline-flex; align-items: center; }
                .brikpanel-bm-settings-learn { margin-inline-start: 0.75rem; color: #616161; text-decoration: none; font-size: 0.8125rem; }
                .brikpanel-bm-settings-learn:hover { color: #303030; }
            </style>
        </td>
    </tr>
    <?php
}

/* ── Shared star motion ─────────────────────────────────────────────────────── */

/**
 * Print the acrobatic star keyframes, at most once per request.
 *
 * WHY THIS IS NOT INLINE ANY MORE. The routine used to live inside
 * brikpanel_brikmentor_render_fab()'s own <style>, which prints on exactly two
 * screens. The launch announcement reuses it on every BrikPanel screen, and a
 * @keyframes name that resolves on one screen and not on another fails in the
 * quietest way CSS can: the element simply sits still, with no error anywhere.
 * Printing it from one guarded place is what keeps the two surfaces honest.
 *
 * Only the star's routine is shared. Each surface keeps its own pulse: the
 * floating button's ring carries that button's drop shadow and inset highlight,
 * which would be wrong on a flat badge inside a white card.
 *
 * @return void
 */
function brikpanel_brikmentor_print_star_motion() {
    static $printed = false;
    if ( $printed ) {
        return;
    }
    $printed = true;
    ?>
    <style>
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
    </style>
    <?php
}

/* ── Launch announcement popup ──────────────────────────────────────────────── */

/**
 * Campaign id stamped on a user who has already been shown the announcement.
 *
 * A campaign string rather than a bare 1: a later announcement only has to
 * change this value to reach everyone again, with no migration and without
 * losing the record that this one was delivered. (BrikMentor's own update
 * notice keys its per-user watermark the same way.)
 */
if ( ! defined( 'BRIKPANEL_BM_ANNOUNCE_CAMPAIGN' ) ) {
    define( 'BRIKPANEL_BM_ANNOUNCE_CAMPAIGN', 'launch-1' );
}

/** User meta holding the campaign id this user has been shown. */
if ( ! defined( 'BRIKPANEL_BM_ANNOUNCE_META' ) ) {
    define( 'BRIKPANEL_BM_ANNOUNCE_META', '_brikpanel_bm_announce_dismissed' );
}

/** Days a fresh install is left alone before the announcement may appear. */
if ( ! defined( 'BRIKPANEL_BM_ANNOUNCE_GRACE_DAYS' ) ) {
    define( 'BRIKPANEL_BM_ANNOUNCE_GRACE_DAYS', 3 );
}

/**
 * True on one of BrikPanel's own admin pages.
 *
 * A pure $_GET read, like brikpanel_ea_is_dashboard(), so the footer of every
 * other admin screen leaves before an option or a user meta is touched.
 *
 * WooCommerce ▸ Settings ▸ BrikPanel is deliberately NOT included even though
 * it is a BrikPanel screen: the newsletter modal and the developer-docs modal
 * both live there, and that screen already carries its own BrikMentor row.
 * Leaving it out removes the only two places where a second dialog could be on
 * screen at the same moment.
 *
 * @return bool
 */
function brikpanel_brikmentor_announce_is_panel_screen() {
    if ( ! isset( $_GET['page'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen check.
        return false;
    }
    $page = sanitize_key( wp_unslash( $_GET['page'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    return 0 === strpos( $page, 'brikpanel-' );
}

/**
 * Should the launch announcement render on this request?
 *
 * Six gates, cheapest first. Any one of them failing means the markup is never
 * printed at all — nothing is rendered hidden and toggled later, so a merchant
 * this does not apply to pays nothing for it.
 *
 * @return bool
 */
function brikpanel_brikmentor_announce_should_render() {
    if ( ! brikpanel_brikmentor_announce_is_panel_screen() ) {
        return false;
    }
    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        return false;
    }
    // The gate every launch surface shares: kill switch off, or BrikMentor
    // already installed here, and nothing promotional renders.
    if ( ! brikpanel_brikmentor_promo_active() ) {
        return false;
    }

    // NEVER on top of the welcome tour.
    //
    // The tour (front-end/welcome/brikpanel-welcome.php) renders in the footer
    // of EVERY admin screen, with no screen restriction, and keeps rendering
    // until the user closes it. Nothing in the plugin sequences popups: there
    // is no queue, no latch and no shared registry. Without this test a
    // first-time installer meets two dialogs at once on their very first
    // pageview. Read-only — the tour keeps sole ownership of its own meta.
    if ( function_exists( 'brikpanel_should_show_welcome' ) && brikpanel_should_show_welcome() ) {
        return false;
    }

    // A store that installed BrikPanel minutes ago has just been walked through
    // the tour; pitching a paid add-on in the same sitting reads as two popups
    // back to back even when they never overlap. An existing store's timestamp
    // is already old, so the audience this is actually written for sees it on
    // the first pageview after the update.
    //
    // A very old install that never had the option gets today's stamp from the
    // admin_init backfill in includes/brikpanel-review-notices.php and waits
    // three days. That is a delay, not a fault, and it is not worth a second
    // piece of state to avoid.
    $installed_at = (int) get_option( 'brikpanel_activated_at' );
    if ( $installed_at > 0
        && ( time() - $installed_at ) < ( BRIKPANEL_BM_ANNOUNCE_GRACE_DAYS * DAY_IN_SECONDS ) ) {
        return false;
    }

    $seen = get_user_meta( get_current_user_id(), BRIKPANEL_BM_ANNOUNCE_META, true );
    return BRIKPANEL_BM_ANNOUNCE_CAMPAIGN !== $seen;
}

add_action( 'admin_footer', 'brikpanel_brikmentor_render_announce' );
/**
 * Render the one-time "BrikMentor is live" announcement.
 *
 * Every string here is one the plugin already ships and already has translated
 * in all nine catalogues, which is why the announcement is worded the way it
 * is: a new sentence would mean nine hand translations.
 *
 * @return void
 */
function brikpanel_brikmentor_render_announce() {
    if ( ! brikpanel_brikmentor_announce_should_render() ) {
        return;
    }

    $cta_url      = brikpanel_brikmentor_url();
    $checkout_url = brikpanel_brikmentor_checkout_url();
    // The one objection a merchant reading this already has, pointed at the
    // section of the landing page that answers it at length. Same treatment as
    // the floating panel: appended only when the campaign URL carries no
    // fragment of its own.
    $why_url      = ( false === strpos( $cta_url, '#' ) ) ? $cta_url . '#bm-free' : $cta_url;
    // Price is injected, never baked into the copy, so it stays one unbreakable
    // token in every locale (see brikpanel_brikmentor_price()).
    $title        = brikpanel_brikmentor_price_text( __( 'BrikMentor is live: first month %s', 'brikpanel' ) );
    $cta_label    = brikpanel_brikmentor_price_text( __( 'Try BrikMentor for %s', 'brikpanel' ) );
    $nonce        = wp_create_nonce( 'brikpanel_bm_announce_nonce' );
    ?>
    <div class="brikpanel-bm-ann" id="brikpanel-bm-ann" data-nonce="<?php echo esc_attr( $nonce ); ?>" role="dialog" aria-modal="true" aria-labelledby="brikpanel-bm-ann-title">
        <div class="brikpanel-bm-ann__card" role="document">
            <button type="button" class="brikpanel-bm-ann__close" data-bm-ann-close aria-label="<?php esc_attr_e( 'Close', 'brikpanel' ); ?>">
                <svg width="12" height="12" viewBox="0 0 14 14" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M1 1l12 12M13 1L1 13" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
            </button>

            <div class="brikpanel-bm-ann__badge" aria-hidden="true">
                <svg class="brikpanel-bm-ann__star" width="22" height="22" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M12 2.5l2.95 5.98 6.6.96-4.78 4.66 1.13 6.58L12 17.58l-5.9 3.1 1.13-6.58L2.45 9.44l6.6-.96L12 2.5z" fill="#fff"/></svg>
            </div>

            <span class="brikpanel-bm-ann__kicker"><?php esc_html_e( 'BrikMentor is live', 'brikpanel' ); ?></span>
            <h2 class="brikpanel-bm-ann__title" id="brikpanel-bm-ann-title"><?php echo esc_html( $title ); ?></h2>
            <p class="brikpanel-bm-ann__body"><?php esc_html_e( 'The AI assistant and email marketing engine for your store data is out now. Automated cart recovery, win-back and segment campaigns, running inside WooCommerce.', 'brikpanel' ); ?></p>

            <a class="brikpanel-bm-ann__cta" href="<?php echo esc_url( $checkout_url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $cta_label ); ?></a>

            <div class="brikpanel-bm-ann__links">
                <a class="brikpanel-bm-ann__ghost" href="<?php echo esc_url( $cta_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Learn more', 'brikpanel' ); ?></a>
                <button type="button" class="brikpanel-bm-ann__ghost" data-bm-ann-close><?php esc_html_e( 'Not now', 'brikpanel' ); ?></button>
            </div>

            <div class="brikpanel-bm-ann__why">
                <a href="<?php echo esc_url( $why_url ); ?>" target="_blank" rel="noopener noreferrer">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><circle cx="12" cy="12" r="9.25" stroke-width="1.5"/><path d="M9.5 9.4a2.55 2.55 0 114.45 1.65c-.72.8-1.95 1.2-1.95 2.45" stroke-width="1.7"/><circle cx="12" cy="16.9" r=".95" fill="currentColor" stroke="none"/></svg>
                    <span><?php esc_html_e( 'Wouldn\'t a free plugin be enough?', 'brikpanel' ); ?></span>
                </a>
            </div>
        </div>
    </div>
    <?php brikpanel_brikmentor_print_star_motion(); ?>
    <style>
        /* Sits above every BrikPanel surface (dev docs 100050, newsletter 99999,
           floating button 9991) and below the welcome tour and the new-order
           toast, both 999999. The welcome-tour gate already makes an overlap
           impossible; this ordering is the second line of defence. */
        .brikpanel-bm-ann {
            position: fixed; inset: 0; z-index: 999990;
            display: flex; align-items: center; justify-content: center;
            /* wp-admin does not apply border-box globally, so both boxes have to
               ask for it: without this the card renders 490px wide, not 440. */
            box-sizing: border-box;
            padding: 24px; overflow-y: auto;
            background: rgba(20, 20, 20, 0.45);
            -webkit-backdrop-filter: blur(2px); backdrop-filter: blur(2px);
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
        }
        .brikpanel-bm-ann__card {
            position: relative;
            box-sizing: border-box;
            width: 440px; max-width: 100%;
            background: #fff; border: 1px solid #e3e3e3; border-radius: 0.75rem;
            box-shadow: 0 8px 28px rgba(0, 0, 0, 0.16);
            padding: 1.5rem 1.5rem 1.25rem;
            text-align: start;
            animation: brikpanel-bm-ann-rise 0.22s cubic-bezier(.2,.7,.3,1);
        }
        @keyframes brikpanel-bm-ann-rise { from { transform: translateY(8px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
        .brikpanel-bm-ann__close {
            position: absolute; inset-block-start: 0.625rem; inset-inline-end: 0.625rem;
            width: 28px; height: 28px; border-radius: 0.375rem;
            background: transparent; border: none; color: #8a8a8a; cursor: pointer;
            display: flex; align-items: center; justify-content: center; padding: 0;
            transition: background 0.15s ease, color 0.15s ease;
        }
        .brikpanel-bm-ann__close:hover { background: #f7f7f7; color: #303030; }
        .brikpanel-bm-ann__close:focus { outline: none; box-shadow: 0 0 0 2px #303030; }
        /* The badge keeps its own pulse: a plain ring, with none of the floating
           button's drop shadow, which would read as dirt on a white card. */
        .brikpanel-bm-ann__badge {
            width: 44px; height: 44px; border-radius: 50%;
            background: #303030; display: flex; align-items: center; justify-content: center;
            margin-block-end: 0.875rem;
            animation: brikpanel-bm-ann-breathe 3.2s ease-in-out infinite;
        }
        @keyframes brikpanel-bm-ann-breathe {
            0%, 100% { box-shadow: 0 0 0 0 rgba(48, 48, 48, 0.30); }
            50%      { box-shadow: 0 0 0 9px rgba(48, 48, 48, 0); }
        }
        .brikpanel-bm-ann__star {
            display: block;
            transform-origin: 50% 52%;
            animation: brikpanel-bm-acrobat 7s cubic-bezier(.34,.06,.36,.96) infinite;
        }
        @media (prefers-reduced-motion: reduce) {
            .brikpanel-bm-ann__card,
            .brikpanel-bm-ann__badge,
            .brikpanel-bm-ann__star { animation: none; }
        }
        .brikpanel-bm-ann__kicker {
            display: block;
            font-size: 0.75rem; font-weight: 550; color: #1a8917;
            text-transform: uppercase; letter-spacing: 0.04em;
            margin-block-end: 0.3rem;
        }
        .brikpanel-bm-ann__title {
            margin: 0 0 0.5rem; padding: 0;
            font-size: 1.0625rem; font-weight: 600; line-height: 1.32; color: #303030;
        }
        .brikpanel-bm-ann__body {
            margin: 0 0 1.125rem; padding: 0;
            font-size: 0.875rem; line-height: 1.55; color: #616161;
        }
        .brikpanel-bm-ann__cta {
            display: flex; align-items: center; justify-content: center; text-align: center;
            padding: 0.625rem 1rem; border-radius: 0.5rem;
            background: #303030; color: #fff; text-decoration: none;
            font-size: 0.875rem; font-weight: 550; line-height: 1.2;
            box-shadow: inset 0 -1px 0 rgba(0,0,0,0.2), inset 0 1px 0 rgba(255,255,255,0.1);
            transition: background 0.15s ease;
        }
        .brikpanel-bm-ann__cta:hover { background: #1a1a1a; color: #fff; }
        .brikpanel-bm-ann__cta:focus { outline: none; box-shadow: 0 0 0 2px #303030; color: #fff; }
        .brikpanel-bm-ann__links {
            display: flex; align-items: center; justify-content: space-between;
            gap: 0.5rem; margin: 0.375rem -0.5rem 0;
        }
        .brikpanel-bm-ann__ghost {
            background: transparent; border: none; cursor: pointer; text-decoration: none;
            padding: 0.375rem 0.5rem; border-radius: 0.375rem;
            font-size: 0.8125rem; font-weight: 550; font-family: inherit; color: #8a8a8a;
            transition: background 0.15s ease, color 0.15s ease;
        }
        .brikpanel-bm-ann__ghost:hover { background: #f7f7f7; color: #303030; text-decoration: none; }
        .brikpanel-bm-ann__ghost:focus { outline: none; box-shadow: 0 0 0 2px #303030; color: #303030; }
        /* The objection gets a line of its own. Three ghost links across 440px
           read as one grey smudge; alone under a rule this one is legible and,
           being the only thing there, actually gets noticed. */
        .brikpanel-bm-ann__why {
            display: flex; justify-content: center;
            margin-block-start: 0.875rem; padding-block-start: 0.75rem;
            border-block-start: 1px solid #e3e3e3;
        }
        .brikpanel-bm-ann__why a {
            display: inline-flex; align-items: center; gap: 0.4rem;
            font-size: 0.8125rem; font-weight: 500; color: #616161; text-decoration: none;
            padding: 0.3rem 0.5rem; border-radius: 0.375rem;
            transition: background 0.15s ease, color 0.15s ease;
        }
        .brikpanel-bm-ann__why a:hover { background: #f7f7f7; color: #303030; }
        .brikpanel-bm-ann__why a:focus { outline: none; box-shadow: 0 0 0 2px #303030; color: #303030; }
        .brikpanel-bm-ann__why svg { flex: 0 0 15px; opacity: 0.7; transition: opacity 0.15s ease; }
        .brikpanel-bm-ann__why a:hover svg { opacity: 1; }
    </style>
    <script>
    (function () {
        var root = document.getElementById('brikpanel-bm-ann');
        if (!root) { return; }
        var card   = root.querySelector('.brikpanel-bm-ann__card');
        // Whatever had focus when the dialog appeared, so it can be handed back.
        var opener = document.activeElement;
        var stamped = false;

        // Every way out writes the stamp, including following a link. Anything
        // less and a merchant who ignores the dialog meets it again on the next
        // BrikPanel page, and the one on every page after that.
        function stamp() {
            if (stamped) { return; }
            stamped = true;
            var fd = new FormData();
            fd.append('action', 'brikpanel_bm_announce_dismiss');
            fd.append('_ajax_nonce', root.getAttribute('data-nonce'));
            fetch(<?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>, {
                method: 'POST', body: fd, credentials: 'same-origin', keepalive: true
            });
        }

        function close() {
            stamp();
            document.removeEventListener('keydown', onKey);
            if (root.parentNode) { root.parentNode.removeChild(root); }
            if (opener && opener.focus) { opener.focus(); }
        }

        function onKey(e) {
            if (e.key === 'Escape') { close(); }
        }

        root.querySelectorAll('[data-bm-ann-close]').forEach(function (el) {
            el.addEventListener('click', close);
        });
        // The CTA and the two links open in a new tab, so the dialog would still
        // be sitting here when the merchant comes back. Stamp and clear it.
        card.querySelectorAll('a[href]').forEach(function (el) {
            el.addEventListener('click', function () { close(); });
        });
        root.addEventListener('mousedown', function (e) {
            if (e.target === root) { close(); }
        });
        document.addEventListener('keydown', onKey);

        var first = root.querySelector('[data-bm-ann-close]');
        if (first) { first.focus(); }
    })();
    </script>
    <?php
}

/* ── AJAX: stamp the announcement as seen ───────────────────────────────────── */
add_action( 'wp_ajax_brikpanel_bm_announce_dismiss', 'brikpanel_bm_ajax_announce_dismiss' );
function brikpanel_bm_ajax_announce_dismiss() {
    check_ajax_referer( 'brikpanel_bm_announce_nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        wp_send_json_error( array( 'reason' => 'forbidden' ), 403 );
    }
    update_user_meta( get_current_user_id(), BRIKPANEL_BM_ANNOUNCE_META, BRIKPANEL_BM_ANNOUNCE_CAMPAIGN );
    wp_send_json_success();
}
