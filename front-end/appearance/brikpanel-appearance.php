<?php
/**
 * BrikPanel — Appearance customization
 *
 * Lets the store owner change the BrikPanel admin UI font and accent color
 * from WooCommerce → Settings → BrikPanel. Overrides are scoped to BrikPanel
 * surfaces only; the rest of WordPress admin and third-party plugins are
 * untouched.
 *
 * Implementation:
 *   - `brikpanel_ui_font`           — keyed font choice (system + curated Google fonts)
 *   - `brikpanel_ui_primary_color`  — accent / primary color (hex)
 *
 * Both options are sanitized server-side and injected as a small inline
 * `<style>` tag on every admin page (and the modern login page) so the
 * overrides apply across dashboard, products list, product editor, orders,
 * coupons, segments, customer analytics, expenses, top bar, sidebar, and
 * cron pages without an extra HTTP round-trip.
 *
 * @package BrikPanel
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const BRIKPANEL_APPEARANCE_DEFAULT_FONT  = 'system';
const BRIKPANEL_APPEARANCE_DEFAULT_COLOR = '#303030';

/**
 * Curated font catalogue. Keys are stored in the option; each entry exposes
 * a human label, the CSS font stack to apply at runtime, and the optional
 * Google Fonts query (when the font is not a system font). Adding a new
 * entry here is the only place needed to expose a new choice in the UI.
 *
 * @return array<string, array{label:string, stack:string, google:false|string}>
 */
function brikpanel_appearance_fonts() {
	$system_stack = '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen, Ubuntu, Cantarell, "Helvetica Neue", sans-serif';
	return [
		'system' => [
			'label'  => __( 'System default', 'brikpanel' ),
			'stack'  => $system_stack,
			'google' => false,
		],
		'inter' => [
			'label'  => 'Inter',
			'stack'  => '"Inter", ' . $system_stack,
			'google' => 'Inter:wght@400;500;600;700',
		],
		'poppins' => [
			'label'  => 'Poppins',
			'stack'  => '"Poppins", ' . $system_stack,
			'google' => 'Poppins:wght@400;500;600;700',
		],
		'roboto' => [
			'label'  => 'Roboto',
			'stack'  => '"Roboto", ' . $system_stack,
			'google' => 'Roboto:wght@400;500;600;700',
		],
		'manrope' => [
			'label'  => 'Manrope',
			'stack'  => '"Manrope", ' . $system_stack,
			'google' => 'Manrope:wght@400;500;600;700',
		],
		'dm-sans' => [
			'label'  => 'DM Sans',
			'stack'  => '"DM Sans", ' . $system_stack,
			'google' => 'DM+Sans:wght@400;500;600;700',
		],
		'plus-jakarta' => [
			'label'  => 'Plus Jakarta Sans',
			'stack'  => '"Plus Jakarta Sans", ' . $system_stack,
			'google' => 'Plus+Jakarta+Sans:wght@400;500;600;700',
		],
		'nunito' => [
			'label'  => 'Nunito',
			'stack'  => '"Nunito", ' . $system_stack,
			'google' => 'Nunito:wght@400;500;600;700',
		],
		'work-sans' => [
			'label'  => 'Work Sans',
			'stack'  => '"Work Sans", ' . $system_stack,
			'google' => 'Work+Sans:wght@400;500;600;700',
		],
	];
}

/**
 * Validated font key (falls back to system on unknown values).
 */
function brikpanel_appearance_get_font_key() {
	$key   = (string) get_option( 'brikpanel_ui_font', BRIKPANEL_APPEARANCE_DEFAULT_FONT );
	$fonts = brikpanel_appearance_fonts();
	return isset( $fonts[ $key ] ) ? $key : BRIKPANEL_APPEARANCE_DEFAULT_FONT;
}

/**
 * Validated primary color (hex). Falls back to the BrikPanel default if the
 * stored value is not a #RGB or #RRGGBB hex literal.
 */
function brikpanel_appearance_get_primary_color() {
	$color = (string) get_option( 'brikpanel_ui_primary_color', BRIKPANEL_APPEARANCE_DEFAULT_COLOR );
	if ( ! preg_match( '/^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/i', $color ) ) {
		return BRIKPANEL_APPEARANCE_DEFAULT_COLOR;
	}
	return $color;
}

/**
 * Multiply each RGB channel by `$factor` to produce a darker shade for
 * hover states. `$factor` < 1 darkens, > 1 lightens. Result is a 6-digit
 * lowercase hex string.
 */
function brikpanel_appearance_shade( $hex, $factor ) {
	$hex = ltrim( $hex, '#' );
	if ( strlen( $hex ) === 3 ) {
		$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
	}
	$r = hexdec( substr( $hex, 0, 2 ) );
	$g = hexdec( substr( $hex, 2, 2 ) );
	$b = hexdec( substr( $hex, 4, 2 ) );
	$r = max( 0, min( 255, (int) round( $r * $factor ) ) );
	$g = max( 0, min( 255, (int) round( $g * $factor ) ) );
	$b = max( 0, min( 255, (int) round( $b * $factor ) ) );
	return sprintf( '#%02x%02x%02x', $r, $g, $b );
}

/**
 * Build the runtime CSS that applies the chosen font + accent color across
 * BrikPanel surfaces. Returns an empty string when both settings are at
 * their default values, so we don't print a no-op `<style>` tag.
 */
function brikpanel_appearance_build_css() {
	$font_key  = brikpanel_appearance_get_font_key();
	$color     = brikpanel_appearance_get_primary_color();
	$is_def_f  = ( $font_key === BRIKPANEL_APPEARANCE_DEFAULT_FONT );
	$is_def_c  = ( strcasecmp( $color, BRIKPANEL_APPEARANCE_DEFAULT_COLOR ) === 0 );

	if ( $is_def_f && $is_def_c ) {
		return '';
	}

	$css = '';

	if ( ! $is_def_c ) {
		$hover = brikpanel_appearance_shade( $color, 0.78 );

		// The :root scope reaches every BrikPanel page that defines its
		// variables at the document root (dashboard, top bar, sidebar nav,
		// orders, coupons, expenses, segments, customer analytics, cron,
		// products list, welcome screen).
		$css .= ':root{'
			. '--bp-primary:' . $color . ';'
			. '--bp-primary-hover:' . $hover . ';'
			. '--bp-input-focus:' . $color . ';'
			. '--bp-topbar-primary:' . $color . ';'
			. '--bp-topbar-primary-hover:' . $hover . ';'
			. '--brikpanel-primary:' . $color . ';'
			. '}';

		// The product editor scopes its variables to .brikpanel-pe rather
		// than :root, so override that scope explicitly.
		$css .= '.brikpanel-pe{'
			. '--pe-primary:' . $color . ';'
			. '--pe-primary-hover:' . $hover . ';'
			. '--pe-input-focus:' . $color . ';'
			. '}';

		// A few widgets paint backgrounds with hardcoded #303030 / #1a1a1a
		// instead of going through the variable. Catch those by scoping a
		// targeted override on the canonical primary surfaces. Specificity
		// is kept low so site themes can still override if needed.
		$css .= '.brikpanel-topbar-search-submit,'
			. '.brikpanel-pe-btn-primary,'
			. '.brikpanel-pe-publish-btn,'
			. '.brikpanel-coupons-add-btn,'
			. '.brikpanel-products-add-btn,'
			. '.brikpanel-orders-add-btn,'
			. '.brikpanel-segments-add-btn,'
			. '.brikpanel-expenses-add-btn,'
			. '.brikpanel-cron-action-btn,'
			. '.brikpanel-welcome-cta'
			. '{background-color:' . $color . ';}'
			. '.brikpanel-topbar-search-submit:hover,'
			. '.brikpanel-pe-btn-primary:hover,'
			. '.brikpanel-pe-publish-btn:hover,'
			. '.brikpanel-coupons-add-btn:hover,'
			. '.brikpanel-products-add-btn:hover,'
			. '.brikpanel-orders-add-btn:hover,'
			. '.brikpanel-segments-add-btn:hover,'
			. '.brikpanel-expenses-add-btn:hover,'
			. '.brikpanel-cron-action-btn:hover,'
			. '.brikpanel-welcome-cta:hover'
			. '{background-color:' . $hover . ';}';
	}

	if ( ! $is_def_f ) {
		$fonts = brikpanel_appearance_fonts();
		$stack = $fonts[ $font_key ]['stack'];
		// Force the font on every BrikPanel-namespaced element. Children
		// inherit naturally; !important wins over the per-component
		// hardcoded font-family declarations.
		$css .= '[class*="brikpanel-"],[class*="brikpanel-"] *,'
			. '.brikpanel-pe,.brikpanel-pe *,'
			. '.bp-login,.bp-login *'
			. '{font-family:' . $stack . ' !important;}';
	}

	return $css;
}

/**
 * Print the runtime CSS into the admin <head>. Hooked late so the inline
 * `<style>` lands after every enqueued plugin stylesheet, ensuring the
 * cascade resolves in our favor even without `!important` on the variable
 * declarations.
 */
function brikpanel_appearance_print_admin_styles() {
	$css = brikpanel_appearance_build_css();
	if ( $css === '' ) {
		return;
	}
	echo "<style id=\"brikpanel-appearance-overrides\">{$css}</style>"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — values are sanitized in helpers above.
}
add_action( 'admin_head', 'brikpanel_appearance_print_admin_styles', 9999 );
add_action( 'login_head', 'brikpanel_appearance_print_admin_styles', 9999 );

/**
 * Enqueue the chosen Google Font (if any). Skipped for the `system` default
 * so we never make an outbound request unless the admin opted in.
 *
 * Both `admin_enqueue_scripts` and `login_enqueue_scripts` hook into this
 * so the font is available on the modern login page too.
 */
function brikpanel_appearance_enqueue_font() {
	$key   = brikpanel_appearance_get_font_key();
	$fonts = brikpanel_appearance_fonts();
	if ( empty( $fonts[ $key ]['google'] ) ) {
		return;
	}
	$query = $fonts[ $key ]['google'];
	$url   = 'https://fonts.googleapis.com/css2?family=' . $query . '&display=swap';
	wp_enqueue_style(
		'brikpanel-appearance-font',
		$url,
		[],
		null
	);
}
add_action( 'admin_enqueue_scripts', 'brikpanel_appearance_enqueue_font' );
add_action( 'login_enqueue_scripts', 'brikpanel_appearance_enqueue_font' );

/**
 * Preconnect to fonts.gstatic.com to shave the TLS handshake off the first
 * font fetch. Only emitted when a Google font is selected.
 */
function brikpanel_appearance_preconnect() {
	$key   = brikpanel_appearance_get_font_key();
	$fonts = brikpanel_appearance_fonts();
	if ( empty( $fonts[ $key ]['google'] ) ) {
		return;
	}
	echo "<link rel=\"preconnect\" href=\"https://fonts.googleapis.com\">\n";
	echo "<link rel=\"preconnect\" href=\"https://fonts.gstatic.com\" crossorigin>\n";
}
add_action( 'admin_head', 'brikpanel_appearance_preconnect', 1 );
add_action( 'login_head', 'brikpanel_appearance_preconnect', 1 );

/**
 * Inject the Appearance section into the BrikPanel WC settings tab. The
 * fields are inserted directly before the "Developers" section so admins
 * land on them naturally in the visual flow.
 */
add_filter( 'brikpanel_settings_fields', 'brikpanel_appearance_register_fields', 5 );
function brikpanel_appearance_register_fields( $fields ) {
	if ( ! is_array( $fields ) ) {
		return $fields;
	}

	$font_options = [];
	foreach ( brikpanel_appearance_fonts() as $key => $def ) {
		$font_options[ $key ] = $def['label'];
	}

	$appearance = [
		[
			'name' => __( 'Appearance', 'brikpanel' ),
			'type' => 'title',
			'id'   => 'brk_appearance_title',
			'desc' => __( 'Customize how the BrikPanel admin interface looks. Changes apply to dashboard, products, orders, coupons, segments, the top bar, sidebar, and the modern login page. The rest of WordPress admin is untouched.', 'brikpanel' ),
		],
		[
			'name'     => __( 'Interface font', 'brikpanel' ),
			'id'       => 'brikpanel_ui_font',
			'type'     => 'select',
			'desc'     => __( 'Pick the typeface used across BrikPanel. "System default" stays on your operating system\'s native font (no external request). Other choices load a single Google Fonts stylesheet with display: swap.', 'brikpanel' ),
			'desc_tip' => true,
			'options'  => $font_options,
			'default'  => BRIKPANEL_APPEARANCE_DEFAULT_FONT,
		],
		[
			'name'     => __( 'Accent color', 'brikpanel' ),
			'id'       => 'brikpanel_ui_primary_color',
			'type'     => 'color',
			'desc'     => __( 'Primary color used for buttons, focus rings, toggles, and active highlights. The BrikPanel default is a near-black gray (#303030); pick anything you like.', 'brikpanel' ),
			'desc_tip' => true,
			'default'  => BRIKPANEL_APPEARANCE_DEFAULT_COLOR,
			'css'      => 'width: 6.5rem;',
		],
		[
			'type' => 'sectionend',
			'id'   => 'brk_appearance_title',
		],
	];

	// Find the "Developers" section and splice the Appearance section in
	// just before it. Falls back to appending at the end if the marker is
	// absent (e.g. another plugin removed the developers section).
	$insert_at = null;
	foreach ( $fields as $i => $field ) {
		if ( isset( $field['id'] ) && $field['id'] === 'brk_developers_title' && isset( $field['type'] ) && $field['type'] === 'title' ) {
			$insert_at = $i;
			break;
		}
	}

	if ( $insert_at === null ) {
		return array_merge( $fields, $appearance );
	}

	array_splice( $fields, $insert_at, 0, $appearance );
	return $fields;
}
