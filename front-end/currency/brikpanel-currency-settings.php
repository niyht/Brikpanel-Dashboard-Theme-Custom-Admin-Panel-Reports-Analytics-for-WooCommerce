<?php
/**
 * Currency settings screen (BrikPanel ▸ Settings ▸ Currency).
 *
 * Lets a merchant enter a flat fallback exchange rate for each non-base
 * currency their store has taken orders in. These rates are only used when an
 * order carries no day-of-sale rate from a multi-currency plugin (see
 * includes/brikpanel-currency.php), so stores running CURCY get accurate
 * historical conversion automatically and never need to touch this screen.
 *
 * @package BrikPanel
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Register the "Currency" section under the BrikPanel settings tab, after
 * Analytics in the Store group.
 */
add_filter( 'woocommerce_get_sections_brikpanel', function ( $sections ) {
    $out = [];
    foreach ( $sections as $id => $label ) {
        $out[ $id ] = $label;
        if ( 'analytics' === $id ) {
            $out['currency'] = __( 'Currency', 'brikpanel' );
        }
    }
    if ( ! isset( $out['currency'] ) ) {
        $out['currency'] = __( 'Currency', 'brikpanel' );
    }
    return $out;
} );

add_filter( 'brikpanel_settings_section_groups', function ( $groups ) {
    if ( isset( $groups['store']['sections'] ) && is_array( $groups['store']['sections'] ) ) {
        $sections = $groups['store']['sections'];
        if ( ! in_array( 'currency', $sections, true ) ) {
            $pos = array_search( 'analytics', $sections, true );
            if ( false === $pos ) {
                $sections[] = 'currency';
            } else {
                array_splice( $sections, $pos + 1, 0, 'currency' );
            }
            $groups['store']['sections'] = $sections;
        }
    }
    return $groups;
} );

add_filter( 'brikpanel_settings_title_section_map', function ( $map ) {
    $map['brk_currency_title'] = 'currency';
    return $map;
} );

add_filter( 'brikpanel_settings_section_icons', function ( $icons ) {
    // Coin / currency glyph.
    $icons['currency'] = '<circle cx="12" cy="12" r="9"/><path d="M14.5 9.5a2.5 2.5 0 0 0-2.5-1.5c-1.4 0-2.5.9-2.5 2s1.1 2 2.5 2 2.5.9 2.5 2-1.1 2-2.5 2a2.5 2.5 0 0 1-2.5-1.5"/><line x1="12" y1="6" x2="12" y2="8"/><line x1="12" y1="16" x2="12" y2="18"/>';
    return $icons;
} );

/**
 * Inject the section fields: a title and the custom rate-table field.
 */
add_filter( 'brikpanel_settings_fields', function ( $fields ) {
    $fields[] = [
        'name' => __( 'Currency', 'brikpanel' ),
        'type' => 'title',
        'id'   => 'brk_currency_title',
        'desc' => __( 'When your store takes orders in more than one currency, BrikPanel converts every order to your store currency before adding it up, so Revenue, Average Order Value and the sales chart are never a meaningless mix of currencies. If you use a multi-currency plugin such as CURCY, the exact rate from the day of each sale is read automatically from the order — you only need the rates below for currencies that arrive without one.', 'brikpanel' ),
    ];
    $fields[] = [
        'type' => 'brikpanel_fx_rates',
        'id'   => 'brikpanel_fx_rates_field',
    ];
    $fields[] = [
        'type' => 'sectionend',
        'id'   => 'brk_currency_title',
    ];
    return $fields;
} );

/**
 * Render the rate table. One row per non-base currency found in the store's
 * order history: "1 <CUR> = [ rate ] <BASE>".
 */
function brikpanel_render_fx_rates_field() {
    $base       = strtoupper( (string) brikpanel_base_currency() );
    $currencies = brikpanel_order_currencies_in_use();
    $foreign    = array_values( array_diff( $currencies, [ $base ] ) );
    $rates      = brikpanel_manual_fx_rates();
    ?>
    </table>
    <section class="bp-fx-card">
        <header class="bp-fx-card__head">
            <h3 class="bp-fx-card__title"><?php esc_html_e( 'Exchange rates to your store currency', 'brikpanel' ); ?></h3>
            <p class="bp-fx-card__sub">
                <?php
                printf(
                    /* translators: %s: store base currency code, e.g. USD. */
                    esc_html__( 'Your store currency is %s. Enter how much one unit of each currency below is worth in it.', 'brikpanel' ),
                    '<strong>' . esc_html( $base ) . '</strong>'
                );
                ?>
            </p>
        </header>

        <?php if ( empty( $foreign ) ) : ?>
            <p class="bp-fx-empty">
                <?php
                printf(
                    /* translators: %s: store base currency code. */
                    esc_html__( 'All of your orders are already in %s, so there is nothing to convert here.', 'brikpanel' ),
                    '<strong>' . esc_html( $base ) . '</strong>'
                );
                ?>
            </p>
        <?php else : ?>
            <div class="bp-fx-list">
                <?php foreach ( $foreign as $code ) :
                    $code = strtoupper( $code );
                    $val  = isset( $rates[ $code ] ) ? $rates[ $code ] : '';
                    ?>
                    <div class="bp-fx-row">
                        <span class="bp-fx-unit">1 <?php echo esc_html( $code ); ?></span>
                        <span class="bp-fx-eq">=</span>
                        <input
                            type="number"
                            step="any"
                            min="0"
                            inputmode="decimal"
                            class="bp-fx-input"
                            name="brikpanel_fx[<?php echo esc_attr( $code ); ?>]"
                            value="<?php echo esc_attr( $val ); ?>"
                            placeholder="0.00"
                            autocomplete="off"
                        >
                        <span class="bp-fx-base"><?php echo esc_html( $base ); ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
            <p class="bp-fx-hint">
                <?php esc_html_e( 'Leave a rate empty to count those orders at their original number (no conversion). Orders that already carry a rate from your multi-currency plugin ignore the values here and always use their own day-of-sale rate.', 'brikpanel' ); ?>
            </p>
        <?php endif; ?>
    </section>
    <table class="form-table">
    <?php
}
add_action( 'woocommerce_admin_field_brikpanel_fx_rates', 'brikpanel_render_fx_rates_field' );

/**
 * Inline styles for the rate card. Kept scoped and tiny; matches the BrikPanel
 * settings card design language.
 */
function brikpanel_fx_rates_styles() {
    if ( ! function_exists( 'brikpanel_settings_get_current_section' )
        || 'currency' !== brikpanel_settings_get_current_section() ) {
        return;
    }
    ?>
    <style>
        .bp-fx-card{background:#fff;border:1px solid #e3e3e3;border-radius:.75rem;box-shadow:0 1px 3px rgba(0,0,0,.08);padding:1.25rem 1.5rem;max-width:560px;margin:0 0 1rem}
        .bp-fx-card__title{margin:0 0 .25rem;font-size:1rem;font-weight:600;color:#303030}
        .bp-fx-card__sub{margin:0 0 1rem;font-size:.8125rem;color:#616161}
        .bp-fx-list{display:flex;flex-direction:column;gap:.625rem}
        .bp-fx-row{display:flex;align-items:center;gap:.625rem}
        .bp-fx-unit{min-width:84px;font-weight:600;font-size:.875rem;color:#303030}
        .bp-fx-eq{color:#8a8a8a;font-weight:600}
        .bp-fx-input{width:160px;padding:.5rem .625rem;border:1px solid #8a8a8a;border-radius:.5rem;font-size:.875rem}
        .bp-fx-input:focus{border-color:#303030;box-shadow:0 0 0 1px #303030;outline:none}
        .bp-fx-base{font-weight:600;font-size:.875rem;color:#616161}
        .bp-fx-hint,.bp-fx-empty{margin:1rem 0 0;font-size:.75rem;color:#8a8a8a;line-height:1.5}
        .bp-fx-empty{margin-top:0}
    </style>
    <?php
}
add_action( 'admin_head', 'brikpanel_fx_rates_styles' );

/**
 * Persist the rate table. Runs on the BrikPanel settings save for the Currency
 * section only. Writing the option triggers the backfill + cache bust wired up
 * in includes/brikpanel-currency.php.
 */
add_action( 'woocommerce_update_options_brikpanel', function () {
    if ( ! function_exists( 'brikpanel_settings_get_current_section' )
        || 'currency' !== brikpanel_settings_get_current_section() ) {
        return;
    }
    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        return;
    }

    $posted = isset( $_POST['brikpanel_fx'] ) && is_array( $_POST['brikpanel_fx'] )
        ? wp_unslash( $_POST['brikpanel_fx'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput — sanitised per value below.
        : [];

    $clean = [];
    foreach ( $posted as $code => $rate ) {
        $code = strtoupper( sanitize_text_field( (string) $code ) );
        $rate = (float) str_replace( ',', '.', (string) $rate );
        if ( '' !== $code && $rate > 0 ) {
            $clean[ $code ] = $rate;
        }
    }

    update_option( BRIKPANEL_FX_RATES_OPTION, $clean );
} );
