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
 *                 Since 3.3.8 three more surfaces: a card under the dashboard
 *                 KPIs carrying the store's own abandoned-cart figures, a
 *                 "BrikMentor" sidebar entry with an in-admin page behind it,
 *                 and a text link under the "Abandoned" figure on the
 *                 Abandoned Carts screen.
 *   - Flag OFF  → this module renders nothing and the early-access waitlist
 *                 (includes/brikpanel-early-access.php) behaves as it did
 *                 before launch. The Abandoned Carts screen also drops its
 *                 padlocked WhatsApp column and envelope. The flag is the
 *                 "Show BrikMentor promotion" switch under WooCommerce →
 *                 Settings → BrikPanel, and `define(
 *                 'BRIKPANEL_BRIKMENTOR_PROMO', false )` in wp-config.php
 *                 pins it for agencies that deploy by config.
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
/**
 * Tell Import / Export about the BrikMentor promotion switch.
 *
 * Registered HERE, at file scope, and not inside the settings-field callback
 * that draws the checkbox — because that callback returns early on a site that
 * has BrikMentor installed, and the field does not exist there at all. Exporting
 * from such a site would then omit the key entirely, and a strict "make the
 * target match the source" import would delete the target's `no` and switch the
 * promotion back on. That is the opposite of what the agency who asked for this
 * switch wants, on the exact stores they asked for it on.
 *
 * `clear => never` closes the other half of the same hole: the value travels
 * when the source has one, and the target's own choice is left alone when it
 * does not. A promotion nobody asked to see again should never come back by
 * itself.
 *
 * @param array $map Registry so far.
 * @return array
 */
add_filter( 'brikpanel_exportable_option_keys', 'brikpanel_brikmentor_register_export_keys' );
function brikpanel_brikmentor_register_export_keys( $map ) {
    $map['brikpanel_brikmentor_live'] = [
        'class'   => 'portable',
        'group'   => 'general',
        'type'    => 'checkbox',
        'default' => 'yes',
        'clear'   => 'never',
    ];
    return $map;
}

function brikpanel_brikmentor_is_live() {
    if ( brikpanel_brikmentor_promo_is_pinned() ) {
        // wp-config.php wins over the option: an agency that deploys the same
        // config to every client store gets the same answer on every one of
        // them, whatever a later settings save writes.
        return (bool) BRIKPANEL_BRIKMENTOR_PROMO;
    }
    $value = get_option( 'brikpanel_brikmentor_live', 'yes' );
    return in_array( $value, array( 'yes', '1', 1, true ), true );
}

/**
 * Has wp-config.php pinned the promotion on or off?
 *
 * `define( 'BRIKPANEL_BRIKMENTOR_PROMO', false );` hides every launch surface
 * without anyone having to open the settings screen, and the settings toggle
 * shows as read-only while it is set. Only a real boolean counts, so a typo
 * such as `'no'` (a non-empty string, i.e. true) cannot switch the promotion
 * ON by mistake: anything that is not a boolean is treated as "not pinned".
 *
 * @return bool
 */
function brikpanel_brikmentor_promo_is_pinned() {
    return defined( 'BRIKPANEL_BRIKMENTOR_PROMO' ) && is_bool( BRIKPANEL_BRIKMENTOR_PROMO );
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
 * @param string $via Which surface the click came from (announce, fab, lock,
 *                    lock-mail, dashboard, dashboard-top, carts-stat, settings,
 *                    page). Every BrikPanel surface shares src=panel, so this is
 *                    the only way the relay can tell them apart. Optional.
 * @return string
 */
function brikpanel_brikmentor_checkout_url( $via = '' ) {
    $base = get_option( 'brikpanel_brikmentor_checkout_url', '' );
    if ( ! is_string( $base ) || '' === trim( $base ) ) {
        $relay = get_option( 'brikmentor_relay_url' );
        $relay = ( is_string( $relay ) && '' !== trim( $relay ) )
            ? untrailingslashit( $relay )
            : 'https://brksoft.com';
        $base = $relay . '/wp-json/brikmentor-relay/v1/checkout';
    }
    $args = array(
        'src'      => 'panel',
        'site_url' => rawurlencode( home_url() ),
        'claim_id' => brikpanel_brikmentor_claim_id(),
    );
    $via  = sanitize_key( (string) $via );
    if ( '' !== $via ) {
        $args['via'] = $via;
    }
    $base = add_query_arg( $args, $base );
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
                // Opened from the padlocked envelope in the Email column. It
                // must not promise that unlocking turns that envelope into
                // something clever: it stays a hand-off to the merchant's own
                // mail client, one cart at a time. What is actually for sale
                // here is not having to click it at all, so that is what this
                // says.
                'lock-mail' => __( 'Writing to one shopper at a time is the slow way back. BrikMentor follows up on every abandoned cart for you: a reminder written for the cart it belongs to, sent on a schedule you set once. Cart recovery emails bring back 5-10% of total revenue in a store that runs them properly.', 'brikpanel' ),
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
    $checkout_url = function_exists( 'brikpanel_brikmentor_checkout_url' ) ? brikpanel_brikmentor_checkout_url( 'fab' ) : $cta_url;
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
        }
        .brikpanel-bm-fab:hover { background: #1a1a1a; transform: scale(1.05); }
        .brikpanel-bm-fab:focus { outline: none; box-shadow: 0 0 0 2px #fff, 0 0 0 4px #303030; }
        /* A still star. The routine that used to spin it (see
           brikpanel_brikmentor_print_star_motion()) now belongs to the one-time
           announcement only: with the dashboard card, the sidebar entry and the
           in-page links carrying the pitch, this button is a quiet way back to
           the panel, not the thing that has to catch the eye. */
        .brikpanel-bm-fab__star { display: block; }
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

        // The CTA names the surface that opened the panel (`via`), so the relay
        // can tell the floating button from the padlocks and the stat-card
        // link even though all of them share this one panel. Rewritten on
        // every open; the button's own value is the default.
        var cta = panel.querySelector('.brikpanel-bm-panel__cta');
        function viaFor(key) {
            if (!cta) { return; }
            try {
                var u = new URL(cta.getAttribute('href'), window.location.href);
                u.searchParams.set('via', key || 'fab');
                cta.setAttribute('href', u.toString());
            } catch (e) { /* an unparsable href keeps whatever it had */ }
        }

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
            if (panel.hidden) { pitchFor(null); viaFor('fab'); }
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
            viaFor(el.getAttribute('data-bm-via') || el.getAttribute('data-bm-variant'));
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
    // With BrikMentor installed there is nothing to promote and nothing to
    // switch off, so the whole section stays out of the way.
    if ( brikpanel_brikmentor_installed() ) {
        return $fields;
    }
    $fields[] = array(
        'type'  => 'title',
        'id'    => 'brk_brikmentor_title',
        'title' => __( 'BrikMentor', 'brikpanel' ),
    );

    // The on/off switch is drawn whether or not the promotion is currently on:
    // a switch that disappears the moment it is turned off could never be
    // turned back on. A store where wp-config.php has pinned the value sees
    // the switch read-only, with a line saying where the value comes from.
    $pinned = brikpanel_brikmentor_promo_is_pinned();
    $toggle = array(
        'name'    => __( 'Show BrikMentor promotion', 'brikpanel' ),
        'id'      => 'brikpanel_brikmentor_live',
        'type'    => 'checkbox',
        'desc'    => __( 'Show the BrikMentor card, menu item, corner button and the locked contact buttons on BrikPanel screens. Turn this off to hide every BrikMentor promotion, for example when you set up BrikPanel for a client.', 'brikpanel' ),
        'default' => 'yes',
    );
    if ( $pinned ) {
        $toggle['value']             = BRIKPANEL_BRIKMENTOR_PROMO ? 'yes' : 'no';
        $toggle['disabled']          = true;
        $toggle['desc_tip']          = __( 'Set by the BRIKPANEL_BRIKMENTOR_PROMO constant in wp-config.php, so it cannot be changed here.', 'brikpanel' );
    }
    $fields[] = $toggle;

    if ( brikpanel_brikmentor_promo_active() ) {
        $fields[] = array(
            // Pseudo-field: it stores nothing, so it is excluded from the
            // import/export option map (see brikpanel-import-export.php).
            'type' => 'brikpanel_brikmentor_promo',
            'id'   => 'brikpanel_brikmentor_promo_field',
        );
    }
    $fields[] = array(
        'type' => 'sectionend',
        'id'   => 'brk_brikmentor_title',
    );
    return $fields;
}

/**
 * Keep the stored switch untouched while wp-config.php pins the value.
 *
 * A disabled checkbox is not posted, so a plain save of the General section
 * would write 'no' underneath the constant; the day the constant is removed
 * the store would then wake up with the promotion off for no reason it can
 * see. Returning the current stored value makes the save a no-op instead.
 *
 * @param mixed $value  Sanitised value about to be saved.
 * @return mixed
 */
add_filter( 'woocommerce_admin_settings_sanitize_option_brikpanel_brikmentor_live', 'brikpanel_brikmentor_keep_pinned_switch' );
function brikpanel_brikmentor_keep_pinned_switch( $value ) {
    if ( brikpanel_brikmentor_promo_is_pinned() ) {
        return get_option( 'brikpanel_brikmentor_live', 'yes' );
    }
    return $value;
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
    $checkout_url = brikpanel_brikmentor_checkout_url( 'settings' );
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
 * Since 3.3.8 only the one-time launch announcement uses it: the floating
 * button's star sits still now that the dashboard card, the sidebar entry and
 * the in-page links carry the pitch. Kept as a guarded printer so a second
 * surface can pick it up again without the @keyframes name resolving on one
 * screen and not another, which fails in the quietest way CSS can: the element
 * simply sits still, with no error anywhere.
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
    // Never on the BrikMentor page itself: announcing the product on top of
    // its own pitch would be two dialogs saying the same thing.
    if ( BRIKPANEL_BM_PAGE_SLUG === $page ) {
        return false;
    }
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
    $checkout_url = brikpanel_brikmentor_checkout_url( 'announce' );
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


/* ── Store figures behind the pitch (abandoned carts, last 30 days) ─────────── */

/**
 * Days of cart history the pitch figures cover. The sentence printed beside
 * the figure says "30 days" in every language, so this is not a knob: change
 * it and the sentence must change with it.
 */
if ( ! defined( 'BRIKPANEL_BM_PITCH_DAYS' ) ) {
    define( 'BRIKPANEL_BM_PITCH_DAYS', 30 );
}

/** How long a merchant's X keeps the dashboard pitch card away, in days. */
if ( ! defined( 'BRIKPANEL_BM_PITCH_SNOOZE_DAYS' ) ) {
    define( 'BRIKPANEL_BM_PITCH_SNOOZE_DAYS', 30 );
}

/** User meta holding the time the dashboard pitch card may come back. */
if ( ! defined( 'BRIKPANEL_BM_PITCH_META' ) ) {
    define( 'BRIKPANEL_BM_PITCH_META', '_brikpanel_bm_pitch_snoozed_until' );
}

/** Slug of the in-admin BrikMentor page behind the sidebar entry. */
if ( ! defined( 'BRIKPANEL_BM_PAGE_SLUG' ) ) {
    define( 'BRIKPANEL_BM_PAGE_SLUG', 'brikpanel-brikmentor' );
}

/**
 * Abandoned carts of the last 30 days: how many, and what they hold.
 *
 * Reads BrikPanel's own abandoned-carts table and nothing else, so a store
 * whose cart module never captured anything gets null and no pitch. The rows
 * are the ones the Abandoned Carts screen itself calls "Abandoned": stored as
 * such, holding at least one item, and not since turned into an order. Cart
 * totals are kept in the currency the cart was captured in, so they are
 * summed per currency (as that screen does) and the currency with the most
 * carts is the one shown: adding euros to dollars would print a figure that
 * is true in no currency.
 *
 * Cached for fifteen minutes. The dashboard is the most opened screen in the
 * plugin and a figure a quarter of an hour old sells exactly as well.
 *
 * @return array{count:int, amount:float, currency:string}|null Null when no cart qualifies.
 */
function brikpanel_brikmentor_cart_pitch() {
    $cached = get_transient( 'brikpanel_bm_cart_pitch' );
    if ( is_array( $cached ) && array_key_exists( 'count', $cached ) ) {
        return empty( $cached['count'] ) ? null : $cached;
    }

    global $wpdb;
    $table = $wpdb->prefix . 'brikpanel_abandoned_carts';
    $best  = array( 'count' => 0, 'amount' => 0.0, 'currency' => '' );

    // The cart module may never have created its table on this store; a
    // missing table must mean "no pitch", not a database error on the dashboard.
    $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
    if ( $exists === $table ) {
        $since = gmdate( 'Y-m-d H:i:s', time() - BRIKPANEL_BM_PITCH_DAYS * DAY_IN_SECONDS );
        $row   = $wpdb->get_row( $wpdb->prepare(
            "SELECT currency, COUNT(*) AS c, SUM(cart_total) AS amount
               FROM {$table}
              WHERE status = 'abandoned' AND item_count > 0 AND abandoned_at >= %s
              GROUP BY currency
              ORDER BY c DESC, amount DESC
              LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is prefix + constant.
            $since
        ) );
        if ( $row && (int) $row->c > 0 ) {
            $best = array(
                'count'    => (int) $row->c,
                'amount'   => (float) $row->amount,
                // Rows captured before the currency column existed carry ''.
                // The Abandoned Carts screen reads those as the store currency.
                'currency' => '' !== (string) $row->currency ? (string) $row->currency
                    : ( function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '' ),
            );
        }
    }

    set_transient( 'brikpanel_bm_cart_pitch', $best, 15 * MINUTE_IN_SECONDS );
    return empty( $best['count'] ) ? null : $best;
}

/**
 * The cart total, formatted in the carts' own currency.
 *
 * @param array $pitch A brikpanel_brikmentor_cart_pitch() result.
 * @return string
 */
function brikpanel_brikmentor_pitch_amount( array $pitch ) {
    $amount = (float) ( $pitch['amount'] ?? 0 );
    if ( function_exists( 'brikpanel_money_text' ) ) {
        return brikpanel_money_text( $amount, array( 'currency' => (string) ( $pitch['currency'] ?? '' ) ) );
    }
    return number_format_i18n( $amount, 2 );
}

/**
 * "47 abandoned carts in the last 30 days", already translated and pluralised.
 *
 * @param array $pitch A brikpanel_brikmentor_cart_pitch() result.
 * @return string
 */
function brikpanel_brikmentor_pitch_label( array $pitch ) {
    $count = (int) ( $pitch['count'] ?? 0 );
    return sprintf(
        /* translators: %s: number of abandoned carts */
        _n( '%s abandoned cart in the last 30 days', '%s abandoned carts in the last 30 days', $count, 'brikpanel' ),
        number_format_i18n( $count )
    );
}

/** Has this user put the dashboard pitch card away for a while? */
function brikpanel_brikmentor_pitch_snoozed() {
    $until = (int) get_user_meta( get_current_user_id(), BRIKPANEL_BM_PITCH_META, true );
    return $until > time();
}

/**
 * Whether the pitch card has rendered on this request.
 *
 * The launch card at the bottom of the dashboard asks this before printing,
 * so the two never share a screen: the pitch card fires under the KPIs, well
 * before the bottom of the page renders, which is what makes a plain static
 * flag enough.
 *
 * @param bool|null $set Pass true to record a render; null only reads.
 * @return bool
 */
function brikpanel_brikmentor_pitch_rendered( $set = null ) {
    static $rendered = false;
    if ( null !== $set ) {
        $rendered = (bool) $set;
    }
    return $rendered;
}

/* ── Dashboard pitch card (under the KPI rows) ──────────────────────────────── */

add_action( 'brikpanel_dashboard_after_kpis', 'brikpanel_brikmentor_render_dashboard_pitch', 20 );
/**
 * A card under the dashboard KPIs that talks in the store's own numbers.
 *
 * It sits where the merchant's eye already is, reads like one more figure of
 * theirs rather than an advert, and only exists when there is a figure to
 * show: a store with no abandoned carts in the last 30 days keeps the generic
 * launch card at the bottom of the page instead. Priority 20 keeps it below
 * the ad-platform cards that hook the same action.
 *
 * Every string but the figure sentence is one the plugin already ships.
 *
 * @return void
 */
function brikpanel_brikmentor_render_dashboard_pitch() {
    if ( ! brikpanel_brikmentor_promo_active() ) {
        return;
    }
    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        return;
    }
    if ( brikpanel_brikmentor_pitch_snoozed() ) {
        return;
    }
    $pitch = brikpanel_brikmentor_cart_pitch();
    if ( ! $pitch ) {
        return;
    }
    brikpanel_brikmentor_pitch_rendered( true );

    $cta_url      = brikpanel_brikmentor_url();
    $checkout_url = brikpanel_brikmentor_checkout_url( 'dashboard-top' );
    $nonce        = wp_create_nonce( 'brikpanel_bm_pitch_nonce' );
    $cta_label    = brikpanel_brikmentor_price_text( __( 'Try BrikMentor for %s', 'brikpanel' ) );
    ?>
    <div class="brikpanel-ea-card brikpanel-bm-pitch" data-bm-pitch data-nonce="<?php echo esc_attr( $nonce ); ?>">
        <div class="brikpanel-ea-card__badge" aria-hidden="true">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M12 2.5l2.95 5.98 6.6.96-4.78 4.66 1.13 6.58L12 17.58l-5.9 3.1 1.13-6.58L2.45 9.44l6.6-.96L12 2.5z" fill="#fff"/></svg>
        </div>
        <div class="brikpanel-bm-pitch__stat">
            <span class="brikpanel-bm-pitch__amount"><?php echo esc_html( brikpanel_brikmentor_pitch_amount( $pitch ) ); ?></span>
            <span class="brikpanel-bm-pitch__label"><?php echo esc_html( brikpanel_brikmentor_pitch_label( $pitch ) ); ?></span>
        </div>
        <div class="brikpanel-ea-card__text">
            <p class="brikpanel-ea-card__title"><?php esc_html_e( 'Recover these carts on autopilot', 'brikpanel' ); ?></p>
            <p class="brikpanel-ea-card__body"><?php echo esc_html( brikpanel_brikmentor_nb_units( __( 'Writing to one shopper at a time is the slow way back. BrikMentor follows up on every abandoned cart for you: a reminder written for the cart it belongs to, sent on a schedule you set once. Cart recovery emails bring back 5-10% of total revenue in a store that runs them properly.', 'brikpanel' ) ) ); ?></p>
        </div>
        <a class="brikpanel-ea-card__cta" href="<?php echo esc_url( $checkout_url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $cta_label ); ?></a>
        <a class="brikpanel-ea-card__learn" href="<?php echo esc_url( $cta_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Learn more', 'brikpanel' ); ?></a>
        <button type="button" class="brikpanel-ea-card__close" data-bm-pitch-dismiss aria-label="<?php esc_attr_e( 'Dismiss', 'brikpanel' ); ?>">
            <svg width="13" height="13" viewBox="0 0 14 14" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M1 1l12 12M13 1L1 13" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
        </button>
    </div>
    <?php brikpanel_brikmentor_print_pitch_assets(); ?>
    <?php
}

/* ── AJAX: put the dashboard pitch card away for a while ────────────────────── */
add_action( 'wp_ajax_brikpanel_bm_pitch_dismiss', 'brikpanel_bm_ajax_pitch_dismiss' );
function brikpanel_bm_ajax_pitch_dismiss() {
    check_ajax_referer( 'brikpanel_bm_pitch_nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        wp_send_json_error( array( 'reason' => 'forbidden' ), 403 );
    }
    update_user_meta(
        get_current_user_id(),
        BRIKPANEL_BM_PITCH_META,
        time() + BRIKPANEL_BM_PITCH_SNOOZE_DAYS * DAY_IN_SECONDS
    );
    wp_send_json_success();
}

/**
 * Styles and the dismiss script the pitch cards share (dashboard, Customer
 * Analytics). Printed at most once per request, on top of the base card styles
 * the launch card already ships.
 *
 * @return void
 */
function brikpanel_brikmentor_print_pitch_assets() {
    static $printed = false;
    if ( $printed ) {
        return;
    }
    $printed = true;
    if ( function_exists( 'brikpanel_ea_print_card_styles' ) ) {
        brikpanel_ea_print_card_styles();
    }
    ?>
    <style>
        /* On the dashboard the card sits between the KPI grid (1.25rem below
           it already) and the next section, so it takes the grid's own bottom
           spacing, not the launch card's top margin. */
        .brikpanel-bm-pitch { margin: 0 0 1.25rem; gap: 1.25rem; }
        /* On Customer Analytics it sits between the header and the tab bar. */
        .brikpanel-bm-pitch--ca { margin: 0.25rem 0 1.25rem; }
        /* The figure block is styled like the KPI cards above it: a big
           tabular number and a small label, so it reads as the store's own
           data, which it is. */
        .brikpanel-bm-pitch__stat {
            flex: 0 0 auto; display: flex; flex-direction: column; min-width: 9rem;
            padding-inline-end: 1.25rem; border-inline-end: 1px solid #e3e3e3;
        }
        .brikpanel-bm-pitch__amount {
            font-size: 1.375rem; font-weight: 600; line-height: 1.2; color: #303030;
            white-space: nowrap; font-variant-numeric: tabular-nums;
        }
        .brikpanel-bm-pitch__label { margin-top: 0.15rem; font-size: 0.75rem; font-weight: 550; color: #616161; }
        .brikpanel-bm-pitch .brikpanel-ea-card__cta:focus,
        .brikpanel-bm-pitch .brikpanel-ea-card__close:focus { outline: none; box-shadow: 0 0 0 2px #303030; }
        /* Narrow: the figure keeps the first line, the pitch takes a full
           line under it, and the button, the link and the X share one row
           at the end instead of wrapping one by one. */
        @media (max-width: 960px) {
            .brikpanel-bm-pitch { flex-wrap: wrap; row-gap: 0.75rem; }
            .brikpanel-bm-pitch__stat { border-inline-end: 0; padding-inline-end: 0; }
            .brikpanel-bm-pitch .brikpanel-ea-card__text { flex-basis: 100%; order: 1; }
            .brikpanel-bm-pitch .brikpanel-ea-card__cta,
            .brikpanel-bm-pitch .brikpanel-ea-card__learn { order: 2; }
            .brikpanel-bm-pitch .brikpanel-ea-card__close { order: 2; margin-inline-start: auto; }
        }
    </style>
    <script>
    (function () {
        var cards = document.querySelectorAll('[data-bm-pitch]');
        Array.prototype.forEach.call(cards, function (card) {
            var btn = card.querySelector('[data-bm-pitch-dismiss]');
            if (!btn) { return; }
            btn.addEventListener('click', function () {
                var fd = new FormData();
                fd.append('action', 'brikpanel_bm_pitch_dismiss');
                fd.append('_ajax_nonce', card.getAttribute('data-nonce'));
                fetch(<?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>, {
                    method: 'POST', body: fd, credentials: 'same-origin', keepalive: true
                });
                if (card.parentNode) { card.parentNode.removeChild(card); }
            });
        });
    })();
    </script>
    <?php
}

/* ── Customer Analytics pitch card (between the header and the tabs) ────────── */

/**
 * The RFM segments BrikMentor's win-back series writes to by default.
 *
 * Mirrors the product's own default (at_risk, cant_lose, hibernating). The
 * segment slugs are BrikPanel's, so the count is taken from the same table the
 * RFM tab reads. Filterable so a future product default can be matched
 * without a release on this side.
 *
 * @return string[]
 */
function brikpanel_brikmentor_winback_segments() {
    return (array) apply_filters( 'brikpanel_brikmentor_winback_segments', array( 'at_risk', 'cant_lose', 'hibernating' ) );
}

/**
 * How many customers sit in the segments the win-back series would write to.
 *
 * Same table as the RFM tab (brikpanel_customer_metrics, filled by the nightly
 * recompute), so the figure agrees with what the merchant sees on that tab. A
 * store whose metrics were never computed gets null and no card. Cached for
 * fifteen minutes like the cart figure.
 *
 * @return array{count:int}|null Null when no customer qualifies.
 */
function brikpanel_brikmentor_rfm_pitch() {
    $cached = get_transient( 'brikpanel_bm_rfm_pitch' );
    if ( is_array( $cached ) && array_key_exists( 'count', $cached ) ) {
        return empty( $cached['count'] ) ? null : $cached;
    }

    global $wpdb;
    $table = $wpdb->prefix . 'brikpanel_customer_metrics';
    $best  = array( 'count' => 0 );

    $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
    if ( $exists === $table ) {
        $segments = array_values( array_filter( array_map( 'sanitize_key', brikpanel_brikmentor_winback_segments() ) ) );
        if ( $segments ) {
            $marks         = implode( ', ', array_fill( 0, count( $segments ), '%s' ) );
            $best['count'] = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE rfm_segment IN ({$marks})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is prefix + constant; placeholders built per segment.
                $segments
            ) );
        }
    }

    set_transient( 'brikpanel_bm_rfm_pitch', $best, 15 * MINUTE_IN_SECONDS );
    return empty( $best['count'] ) ? null : $best;
}

/**
 * "312 customers are drifting away", translated and pluralised.
 *
 * @param array $pitch A brikpanel_brikmentor_rfm_pitch() result.
 * @return string
 */
function brikpanel_brikmentor_rfm_label( array $pitch ) {
    $count = (int) ( $pitch['count'] ?? 0 );
    return sprintf(
        /* translators: %s: number of customers in the At Risk, Can't Lose Them and Hibernating segments */
        _n( '%s customer is drifting away', '%s customers are drifting away', $count, 'brikpanel' ),
        number_format_i18n( $count )
    );
}

add_action( 'brikpanel_ca_after_header', 'brikpanel_brikmentor_render_analytics_pitch', 20 );
/**
 * The pitch card on Customer Analytics, in the store's own numbers.
 *
 * The count is the customers in the segments the win-back series writes to,
 * which is exactly what that screen's RFM tab shows the merchant. Same card as
 * the dashboard, same snooze (one X puts both away for thirty days), same
 * gates: promotion on, BrikMentor not installed, a figure to show. Every
 * string but the figure sentence is one the plugin already ships.
 *
 * @return void
 */
function brikpanel_brikmentor_render_analytics_pitch() {
    if ( ! brikpanel_brikmentor_promo_active() ) {
        return;
    }
    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        return;
    }
    if ( brikpanel_brikmentor_pitch_snoozed() ) {
        return;
    }
    $pitch = brikpanel_brikmentor_rfm_pitch();
    if ( ! $pitch ) {
        return;
    }

    $screens      = brikpanel_brikmentor_fab_screens();
    $body         = isset( $screens['brikpanel-customer-analytics']['variants']['rfm'] ) ? $screens['brikpanel-customer-analytics']['variants']['rfm'] : '';
    $cta_url      = brikpanel_brikmentor_url();
    $checkout_url = brikpanel_brikmentor_checkout_url( 'analytics' );
    $nonce        = wp_create_nonce( 'brikpanel_bm_pitch_nonce' );
    $cta_label    = brikpanel_brikmentor_price_text( __( 'Try BrikMentor for %s', 'brikpanel' ) );
    ?>
    <div class="brikpanel-ea-card brikpanel-bm-pitch brikpanel-bm-pitch--ca" data-bm-pitch data-nonce="<?php echo esc_attr( $nonce ); ?>">
        <div class="brikpanel-ea-card__badge" aria-hidden="true">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M12 2.5l2.95 5.98 6.6.96-4.78 4.66 1.13 6.58L12 17.58l-5.9 3.1 1.13-6.58L2.45 9.44l6.6-.96L12 2.5z" fill="#fff"/></svg>
        </div>
        <div class="brikpanel-bm-pitch__stat">
            <span class="brikpanel-bm-pitch__amount"><?php echo esc_html( number_format_i18n( (int) $pitch['count'] ) ); ?></span>
            <span class="brikpanel-bm-pitch__label"><?php echo esc_html( brikpanel_brikmentor_rfm_label( $pitch ) ); ?></span>
        </div>
        <div class="brikpanel-ea-card__text">
            <p class="brikpanel-ea-card__title"><?php esc_html_e( 'Turn this data into revenue', 'brikpanel' ); ?></p>
            <p class="brikpanel-ea-card__body"><?php echo esc_html( $body ); ?></p>
        </div>
        <a class="brikpanel-ea-card__cta" href="<?php echo esc_url( $checkout_url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $cta_label ); ?></a>
        <a class="brikpanel-ea-card__learn" href="<?php echo esc_url( $cta_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Learn more', 'brikpanel' ); ?></a>
        <button type="button" class="brikpanel-ea-card__close" data-bm-pitch-dismiss aria-label="<?php esc_attr_e( 'Dismiss', 'brikpanel' ); ?>">
            <svg width="13" height="13" viewBox="0 0 14 14" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M1 1l12 12M13 1L1 13" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
        </button>
    </div>
    <?php brikpanel_brikmentor_print_pitch_assets(); ?>
    <?php
}

/* ── Sidebar entry + the in-admin BrikMentor page behind it ─────────────────── */

add_action( 'admin_menu', 'brikpanel_brikmentor_register_menu', 60 );
/**
 * A "BrikMentor" entry in the sidebar, on every admin screen.
 *
 * It is a real admin page, not a link out of the admin: a merchant who clicks
 * a sidebar item expects a screen of theirs to open, and the page it opens
 * carries the store's own figures, which no outside page can. Registered as a
 * top-level so the modern sidebar pins it into the store cluster (the
 * `brikpanel` prefix already marks it as a store surface there); in the
 * native WordPress menu it lands right after Abandoned Carts.
 *
 * @return void
 */
function brikpanel_brikmentor_register_menu() {
    if ( ! brikpanel_brikmentor_promo_active() ) {
        return;
    }
    $hook = add_menu_page(
        'BrikMentor',
        'BrikMentor',
        'manage_woocommerce',
        BRIKPANEL_BM_PAGE_SLUG,
        'brikpanel_brikmentor_render_page',
        'dashicons-star-filled',
        56.9
    );
    if ( $hook ) {
        add_action( 'load-' . $hook, function () {
            global $title;
            $title = 'BrikMentor';
        } );
    }
}

add_action( 'brikpanel_nav_store_cluster_ready', 'brikpanel_brikmentor_pin_menu' );
/**
 * Put the sidebar entry after Marketing (before Settings), or after Abandoned
 * Carts on a store without the Marketing item. Two calls on purpose: the
 * second is a no-op when Marketing is missing.
 *
 * @param array $menu The $menu-shaped array, by reference.
 * @return void
 */
function brikpanel_brikmentor_pin_menu( &$menu ) {
    if ( ! function_exists( 'brikpanel_move_item_after' ) || ! is_array( $menu ) ) {
        return;
    }
    $menu = brikpanel_move_item_after( $menu, BRIKPANEL_BM_PAGE_SLUG, 'brikpanel-abandoned-carts' );
    $menu = brikpanel_move_item_after( $menu, BRIKPANEL_BM_PAGE_SLUG, 'woocommerce-marketing' );
}

add_filter( 'brikpanel_nav_new_item_after', 'brikpanel_brikmentor_menu_first_position', 10, 2 );
/**
 * Where the entry first appears in a sidebar the merchant customized before
 * this version existed: after Marketing, the same spot it takes on a store
 * that never touched the customizer. Without this the customizer would file
 * a never-seen row at the very end, inside the collapsed Site management
 * group, and the entry would be invisible on exactly the stores that care
 * enough about their sidebar to have arranged it.
 *
 * @param string $after Slug to insert after.
 * @param string $slug  The new item's slug.
 * @return string
 */
function brikpanel_brikmentor_menu_first_position( $after, $slug ) {
    return ( BRIKPANEL_BM_PAGE_SLUG === (string) $slug ) ? 'woocommerce-marketing' : $after;
}

add_filter( 'brikpanel_nav_item_title', 'brikpanel_brikmentor_menu_badge', 10, 2 );
/**
 * The "New" pill beside the sidebar label. Added at render time rather than
 * baked into the registered title, so the nav customizer, the native menu
 * and screen readers all see the plain word "BrikMentor".
 *
 * @param string $title Sidebar label HTML.
 * @param string $slug  Top-level menu slug.
 * @return string
 */
function brikpanel_brikmentor_menu_badge( $title, $slug ) {
    if ( BRIKPANEL_BM_PAGE_SLUG !== (string) $slug ) {
        return $title;
    }
    return $title . ' <span class="brikpanel-nav-badge-new">' . esc_html__( 'New', 'brikpanel' ) . '</span>';
}

/**
 * The regular monthly price, ready to print (the price after the $1 month).
 *
 * Same treatment as brikpanel_brikmentor_price(): one typographic token, the
 * space inside it made non-breaking, filterable so a price change is one
 * string and not nine translations.
 *
 * @return string
 */
function brikpanel_brikmentor_regular_price() {
    /* translators: regular monthly price in US dollars, charged after the first month. Locales that place the symbol after the amount should translate this as "15 $". */
    $price    = trim( _x( '$15', 'regular monthly price', 'brikpanel' ) );
    $unbroken = preg_replace( '/[\s\x{00A0}]+/u', "\u{00A0}", $price );
    $price    = ( null === $unbroken || '' === $unbroken ) ? $price : $unbroken;

    /**
     * Filter the regular monthly price token shown on the BrikMentor page.
     *
     * @param string $price Display price, symbol and amount already joined.
     */
    return (string) apply_filters( 'brikpanel_brikmentor_regular_price', $price );
}

/**
 * The eight flows, in the order they are shown, each with a one-line pitch.
 *
 * Names are the ones BrikMentor itself uses for these flows, so a merchant
 * who buys meets the same words inside the product.
 *
 * @return array<int, array{icon:string, name:string, line:string}>
 */
function brikpanel_brikmentor_page_flows() {
    return array(
        array(
            'icon' => 'cart',
            'name' => __( 'Abandoned cart', 'brikpanel' ),
            'line' => __( 'Reminders on a proven schedule, a coupon only if needed.', 'brikpanel' ),
        ),
        array(
            'icon' => 'return',
            'name' => __( 'Winback', 'brikpanel' ),
            'line' => __( 'Brings back customers who stopped ordering.', 'brikpanel' ),
        ),
        array(
            'icon' => 'bag',
            'name' => __( 'Post-purchase', 'brikpanel' ),
            'line' => __( 'What other customers bought alongside, a few days after each order.', 'brikpanel' ),
        ),
        array(
            'icon' => 'box',
            'name' => __( 'Back in stock', 'brikpanel' ),
            'line' => __( 'A waitlist button on sold-out products, an email the moment they return.', 'brikpanel' ),
        ),
        array(
            'icon' => 'card',
            'name' => __( 'Payment recovery', 'brikpanel' ),
            'line' => __( 'A second chance for orders whose payment failed.', 'brikpanel' ),
        ),
        array(
            'icon' => 'wave',
            'name' => __( 'Welcome series', 'brikpanel' ),
            'line' => __( 'First emails to shoppers who join through the signup popup.', 'brikpanel' ),
        ),
        array(
            'icon' => 'ticket',
            'name' => __( 'Popup coupon', 'brikpanel' ),
            'line' => __( 'Delivers the discount code your popup promises.', 'brikpanel' ),
        ),
        array(
            'icon' => 'history',
            'name' => __( 'Old cart backlog', 'brikpanel' ),
            'line' => __( 'A one-time series for carts abandoned before you installed.', 'brikpanel' ),
        ),
    );
}

/**
 * Small line icons for the BrikMentor page, keyed by name. Inline SVG so the
 * page needs no asset file; stroke follows the text colour.
 *
 * @param string $key Icon key.
 * @return string SVG markup (trusted, static).
 */
function brikpanel_brikmentor_page_icon( $key ) {
    $paths = array(
        'cart'    => '<circle cx="9" cy="20" r="1.2"/><circle cx="18" cy="20" r="1.2"/><path d="M2.5 3.5h2.4l2.3 11.2a1.5 1.5 0 0 0 1.5 1.2h8.6a1.5 1.5 0 0 0 1.5-1.2L20.5 8H6.2"/>',
        'return'  => '<path d="M3 12a9 9 0 1 0 3-6.7"/><path d="M3 4v5h5"/>',
        'bag'     => '<path d="M6.5 8h11l1 12.5h-13z"/><path d="M9 8V6.5a3 3 0 0 1 6 0V8"/>',
        'box'     => '<path d="M3.5 7.5 12 3l8.5 4.5v9L12 21l-8.5-4.5z"/><path d="M3.5 7.5 12 12l8.5-4.5"/><path d="M12 12v9"/>',
        'card'    => '<rect x="2.5" y="5.5" width="19" height="13" rx="2"/><path d="M2.5 10h19"/><path d="M6.5 14.5h4"/>',
        'wave'    => '<path d="M3.5 7.5 12 13l8.5-5.5"/><rect x="3.5" y="5" width="17" height="14" rx="2"/>',
        'ticket'  => '<path d="M3.5 8.5a2 2 0 0 0 0 4 2 2 0 0 1 0 4v1.5h17V16a2 2 0 0 1 0-4 2 2 0 0 1 0-4V6.5h-17z"/><path d="M12 6.5v13" stroke-dasharray="2 2.5"/>',
        'history' => '<circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/>',
        'spark'   => '<path d="M12 3.5 13.8 9l5.7 1.8-5.7 1.8L12 18.5l-1.8-5.9-5.7-1.8L10.2 9z"/>',
        'at'      => '<circle cx="12" cy="12" r="3.5"/><path d="M15.5 12v1.5a2.5 2.5 0 0 0 5 0V12a8.5 8.5 0 1 0-3.3 6.7"/>',
        'check'   => '<circle cx="12" cy="12" r="8.5"/><path d="m8.5 12.2 2.4 2.4 4.8-5"/>',
        'phone'   => '<path d="M6.5 3.5h3l1.5 4-2 1.2a11 11 0 0 0 6.3 6.3l1.2-2 4 1.5v3a2 2 0 0 1-2.2 2A16 16 0 0 1 4.5 5.7a2 2 0 0 1 2-2.2z"/>',
    );
    $d = isset( $paths[ $key ] ) ? $paths[ $key ] : $paths['spark'];
    return '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $d . '</svg>';
}

/**
 * The in-admin BrikMentor page.
 *
 * One screen, three blocks: the store's own figure with the offer beside it,
 * the eight flows as a grid of one-liners, and four facts about what the
 * merchant does not have to do. Nothing is installed from here: the button
 * opens BrikMentor's own checkout in a new tab, exactly as the floating panel
 * does. Flow names are BrikMentor's own; the figure sentence, the flow lines
 * and the facts are the only strings this page adds to the catalogue.
 *
 * @return void
 */
function brikpanel_brikmentor_render_page() {
    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        return;
    }
    $pitch        = brikpanel_brikmentor_cart_pitch();
    $cta_url      = brikpanel_brikmentor_url();
    $checkout_url = brikpanel_brikmentor_checkout_url( 'page' );
    $why_url      = ( false === strpos( $cta_url, '#' ) ) ? $cta_url . '#bm-free' : $cta_url;
    $cta_label    = brikpanel_brikmentor_price_text( __( 'Try BrikMentor for %s', 'brikpanel' ) );
    $generic      = brikpanel_brikmentor_price_text( __( 'BrikMentor is live: first month %s', 'brikpanel' ) );
    /* translators: %s: regular monthly price, e.g. "$15" */
    $then         = brikpanel_brikmentor_nb_units( str_replace( array( '%1$s', '%s' ), brikpanel_brikmentor_regular_price(), __( 'then %s a month, cancel any time', 'brikpanel' ) ) );

    $facts = array(
        array( 'spark', __( 'AI writes every email for your store, in your language, from your own product names.', 'brikpanel' ) ),
        array( 'at', __( 'Sent from your own domain, with an unsubscribe link in every email.', 'brikpanel' ) ),
        array( 'check', __( 'Revenue counts only when a customer clicks and then orders, listed by order number.', 'brikpanel' ) ),
        array( 'phone', __( 'Unlocks phone numbers and one-click WhatsApp messages on Abandoned Carts.', 'brikpanel' ) ),
    );
    ?>
    <div class="wrap brikpanel-bm-page">
        <div class="brikpanel-bm-page__head">
            <h1>BrikMentor</h1>
            <p class="brikpanel-bm-page__sub"><?php esc_html_e( 'The AI assistant and email marketing engine for your store data is out now. Automated cart recovery, win-back and segment campaigns, running inside WooCommerce.', 'brikpanel' ); ?></p>
        </div>

        <div class="brikpanel-bm-page__card brikpanel-bm-page__hero">
            <div class="brikpanel-bm-page__hero-main">
                <span class="brikpanel-bm-page__kicker"><?php esc_html_e( 'BrikMentor is live', 'brikpanel' ); ?></span>
                <?php if ( $pitch ) : ?>
                    <div class="brikpanel-bm-page__stat">
                        <span class="brikpanel-bm-page__amount"><?php echo esc_html( brikpanel_brikmentor_pitch_amount( $pitch ) ); ?></span>
                        <span class="brikpanel-bm-page__stat-label"><?php echo esc_html( brikpanel_brikmentor_pitch_label( $pitch ) ); ?></span>
                    </div>
                    <h2 class="brikpanel-bm-page__title"><?php esc_html_e( 'Recover these carts on autopilot', 'brikpanel' ); ?></h2>
                <?php else : ?>
                    <h2 class="brikpanel-bm-page__title"><?php echo esc_html( $generic ); ?></h2>
                    <p class="brikpanel-bm-page__body"><?php esc_html_e( 'Roughly 70 of every 100 shoppers leave without completing checkout. Well-timed cart recovery emails bring back 5-10% of total revenue in a store that runs them properly. It is the one sales channel you set up once and that never asks for your time again. Don\'t leave that money on the table.', 'brikpanel' ); ?></p>
                <?php endif; ?>
            </div>
            <div class="brikpanel-bm-page__offer">
                <div class="brikpanel-bm-page__price">
                    <span class="brikpanel-bm-page__price-label"><?php esc_html_e( 'First month', 'brikpanel' ); ?></span>
                    <span class="brikpanel-bm-page__price-value"><?php echo esc_html( brikpanel_brikmentor_price() ); ?></span>
                </div>
                <p class="brikpanel-bm-page__then"><?php echo esc_html( $then ); ?></p>
                <a class="brikpanel-bm-page__cta" href="<?php echo esc_url( $checkout_url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $cta_label ); ?></a>
                <a class="brikpanel-bm-page__ghost" href="<?php echo esc_url( $why_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Wouldn\'t a free plugin be enough?', 'brikpanel' ); ?></a>
                <a class="brikpanel-bm-page__ghost" href="<?php echo esc_url( $cta_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Learn more', 'brikpanel' ); ?></a>
            </div>
        </div>

        <h2 class="brikpanel-bm-page__h2"><?php esc_html_e( 'Eight flows, ready on day one', 'brikpanel' ); ?></h2>
        <div class="brikpanel-bm-page__flows">
            <?php foreach ( brikpanel_brikmentor_page_flows() as $flow ) : ?>
                <div class="brikpanel-bm-page__flow">
                    <span class="brikpanel-bm-page__icon"><?php echo brikpanel_brikmentor_page_icon( $flow['icon'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?></span>
                    <span class="brikpanel-bm-page__flow-name"><?php echo esc_html( $flow['name'] ); ?></span>
                    <span class="brikpanel-bm-page__flow-line"><?php echo esc_html( $flow['line'] ); ?></span>
                </div>
            <?php endforeach; ?>
        </div>

        <h2 class="brikpanel-bm-page__h2"><?php esc_html_e( 'Nothing to set up', 'brikpanel' ); ?></h2>
        <div class="brikpanel-bm-page__facts">
            <?php foreach ( $facts as $fact ) : ?>
                <div class="brikpanel-bm-page__fact">
                    <span class="brikpanel-bm-page__icon"><?php echo brikpanel_brikmentor_page_icon( $fact[0] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?></span>
                    <span><?php echo esc_html( $fact[1] ); ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <style>
        .brikpanel-bm-page {
            max-width: 820px; margin: 1.25rem auto 2rem;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            color: #303030;
        }
        .brikpanel-bm-page__head { margin-bottom: 1rem; }
        .brikpanel-bm-page__head h1 { margin: 0; padding: 0; font-size: 1.125rem; font-weight: 600; color: #303030; }
        .brikpanel-bm-page__sub { margin: 0.25rem 0 0; font-size: 0.8125rem; line-height: 1.5; color: #616161; max-width: 640px; }
        .brikpanel-bm-page__card {
            background: #fff; border: 1px solid #e3e3e3; border-radius: 0.75rem;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08); padding: 1.25rem 1.5rem;
        }
        .brikpanel-bm-page__hero { display: flex; gap: 1.5rem; align-items: stretch; }
        .brikpanel-bm-page__hero-main { flex: 1 1 auto; min-width: 0; display: flex; flex-direction: column; justify-content: center; }
        .brikpanel-bm-page__kicker {
            display: block; margin-bottom: 0.5rem;
            font-size: 0.75rem; font-weight: 550; color: #1a8917; text-transform: uppercase; letter-spacing: 0.04em;
        }
        .brikpanel-bm-page__stat { display: flex; flex-direction: column; margin-bottom: 0.625rem; }
        .brikpanel-bm-page__amount {
            font-size: 1.75rem; font-weight: 600; line-height: 1.15; color: #303030;
            white-space: nowrap; font-variant-numeric: tabular-nums;
        }
        .brikpanel-bm-page__stat-label { margin-top: 0.15rem; font-size: 0.8125rem; font-weight: 550; color: #616161; }
        .brikpanel-bm-page__title { margin: 0; padding: 0; font-size: 1.0625rem; font-weight: 600; line-height: 1.35; color: #303030; }
        .brikpanel-bm-page__body { margin: 0.5rem 0 0; padding: 0; font-size: 0.875rem; line-height: 1.55; color: #616161; }
        /* The offer column: price tag, what comes after, the button, two links. */
        .brikpanel-bm-page__offer {
            flex: 0 0 240px; display: flex; flex-direction: column; gap: 0.5rem; justify-content: center;
            padding-inline-start: 1.5rem; border-inline-start: 1px solid #e3e3e3;
        }
        .brikpanel-bm-page__price {
            display: flex; align-items: baseline; justify-content: space-between; gap: 0.75rem;
            padding: 0.5rem 0.75rem; background: #f7f7f7; border: 1px solid #e3e3e3; border-radius: 0.5rem;
        }
        .brikpanel-bm-page__price-label { font-size: 0.75rem; font-weight: 550; color: #616161; text-transform: uppercase; letter-spacing: 0.04em; }
        .brikpanel-bm-page__price-value { font-size: 1.125rem; font-weight: 600; color: #303030; line-height: 1.2; white-space: nowrap; font-variant-numeric: tabular-nums; }
        .brikpanel-bm-page__then { margin: -0.125rem 0 0; padding: 0; text-align: center; font-size: 0.75rem; color: #8a8a8a; }
        .brikpanel-bm-page__cta {
            display: flex; align-items: center; justify-content: center; text-align: center;
            padding: 0.625rem 1rem; border-radius: 0.5rem;
            background: #303030; color: #fff; text-decoration: none;
            font-size: 0.875rem; font-weight: 550; line-height: 1.2;
            box-shadow: inset 0 -1px 0 rgba(0,0,0,0.2), inset 0 1px 0 rgba(255,255,255,0.1);
            transition: background 0.15s ease;
        }
        .brikpanel-bm-page__cta:hover { background: #1a1a1a; color: #fff; }
        .brikpanel-bm-page__cta:focus { outline: none; box-shadow: 0 0 0 2px #303030; color: #fff; }
        .brikpanel-bm-page__ghost {
            display: block; text-align: center; text-decoration: none;
            padding: 0.25rem 0.5rem; border-radius: 0.375rem;
            font-size: 0.8125rem; font-weight: 550; color: #8a8a8a;
            transition: background 0.15s ease, color 0.15s ease;
        }
        .brikpanel-bm-page__ghost:hover { background: #f7f7f7; color: #303030; }
        .brikpanel-bm-page__ghost:focus { outline: none; box-shadow: 0 0 0 2px #303030; color: #303030; }
        .brikpanel-bm-page__h2 { margin: 1.5rem 0 0.75rem; padding: 0; font-size: 0.9375rem; font-weight: 600; color: #303030; }
        /* Flows: eight small cards, four across. Icon, name, one line. */
        .brikpanel-bm-page__flows { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 0.75rem; }
        .brikpanel-bm-page__flow {
            display: flex; flex-direction: column; gap: 0.25rem;
            background: #fff; border: 1px solid #e3e3e3; border-radius: 0.75rem;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08); padding: 0.875rem 1rem;
        }
        .brikpanel-bm-page__icon {
            display: inline-flex; align-items: center; justify-content: center; flex: 0 0 auto;
            width: 32px; height: 32px; border-radius: 50%; background: #f1f1f1; color: #303030;
        }
        .brikpanel-bm-page__flow .brikpanel-bm-page__icon { margin-bottom: 0.375rem; }
        .brikpanel-bm-page__flow-name { font-size: 0.8125rem; font-weight: 600; color: #303030; line-height: 1.3; }
        .brikpanel-bm-page__flow-line { font-size: 0.75rem; line-height: 1.45; color: #616161; }
        /* Facts: two columns of icon + one sentence. */
        .brikpanel-bm-page__facts { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0.75rem; }
        .brikpanel-bm-page__fact {
            display: flex; align-items: center; gap: 0.75rem;
            background: #fff; border: 1px solid #e3e3e3; border-radius: 0.75rem;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08); padding: 0.75rem 1rem;
            font-size: 0.8125rem; line-height: 1.45; color: #303030;
        }
        @media (max-width: 782px) {
            .brikpanel-bm-page__hero { flex-direction: column; }
            .brikpanel-bm-page__offer { flex-basis: auto; padding-inline-start: 0; border-inline-start: 0; padding-top: 1rem; border-top: 1px solid #e3e3e3; }
            .brikpanel-bm-page__flows { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .brikpanel-bm-page__facts { grid-template-columns: minmax(0, 1fr); }
        }
    </style>
    <?php
}
