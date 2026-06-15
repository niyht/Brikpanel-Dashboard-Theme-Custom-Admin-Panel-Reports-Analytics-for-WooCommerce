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

// =============================================================================
// BRAND LOGO — option keys + helpers
// =============================================================================

/**
 * Whether the admin has opted in to use a custom brand logo. Default off so
 * fresh installs keep the BrikPanel mark + site name.
 */
function brikpanel_brand_logo_is_enabled() {
	return get_option( 'brikpanel_brand_logo_enabled', 'no' ) === 'yes';
}

/**
 * Resolve the brand logo URL. Returns an empty string when the feature is off
 * or no valid source is configured, so callers can branch with a simple
 * `if ( $url === '' )` instead of probing the options themselves.
 *
 * Two sources are supported, in priority order:
 *   1. An explicit external URL (e.g. a logo hosted on a CDN). Stored as
 *      `brikpanel_brand_logo_url`. Wins when set so an admin can override a
 *      previously picked media item without clearing it first.
 *   2. A WordPress media library attachment. Stored as `brikpanel_brand_logo_id`.
 */
function brikpanel_brand_logo_get_url() {
	if ( ! brikpanel_brand_logo_is_enabled() ) {
		return '';
	}
	$custom = (string) get_option( 'brikpanel_brand_logo_url', '' );
	if ( $custom !== '' ) {
		return $custom;
	}
	$id = (int) get_option( 'brikpanel_brand_logo_id', 0 );
	if ( $id <= 0 ) {
		return '';
	}
	$url = wp_get_attachment_image_url( $id, 'full' );
	return is_string( $url ) ? $url : '';
}

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
 * Sanitize an admin-supplied custom CSS blob before it is stored or printed.
 *
 * Custom CSS is a privileged input — only users with `manage_woocommerce` can
 * reach the field — so the goal here is integrity, not untrusted-input defense:
 * the value is echoed verbatim inside a `<style>` element, and the one thing
 * that can break out of that context is a literal `</style` sequence (the HTML
 * parser ends a raw-text element there regardless of CSS string context). We
 * strip null bytes and neutralize any `</style` so the blob can never close the
 * tag and inject markup. Everything else valid CSS needs — `{ } : ; > + ~`,
 * `url()`, `@media`, `content: "<3"` — is preserved untouched.
 */
function brikpanel_appearance_sanitize_custom_css( $css ) {
	$css = (string) $css;
	if ( $css === '' ) {
		return '';
	}
	$css = str_replace( "\0", '', $css );
	// Case-insensitive: collapse any attempt to close (or re-open) the style
	// element. Valid dashboard CSS never contains the literal "</style".
	$css = preg_replace( '#</\s*style#i', '', $css );
	return trim( (string) $css );
}

/**
 * The stored custom CSS, re-sanitized on read so a value written by an older
 * build (or smuggled straight into the option) is still safe at print time.
 */
function brikpanel_appearance_get_custom_css() {
	return brikpanel_appearance_sanitize_custom_css( (string) get_option( 'brikpanel_custom_css', '' ) );
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
		//
		// `:not(body):not(html)` is critical: the topbar feature tags both
		// <body> and <html> with `brikpanel-has-topbar` so its CSS can offset
		// layout. Without the exclusion the broad `[class*="brikpanel-"] *`
		// reaches every descendant of <body> on every admin page that renders
		// the topbar, including foreign plugin screens (e.g. WP BNav's icon
		// picker rendered tofu boxes because Font Awesome's `font-family` got
		// overridden). Real BrikPanel wrappers like `.brikpanel-dashboard` and
		// `.brikpanel-topbar-*` still match and receive the font; only the
		// body/html sentinels are excluded.
		$css .= '[class*="brikpanel-"]:not(body):not(html),'
			. '[class*="brikpanel-"]:not(body):not(html) *,'
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
	// Excluded users (see access control) get the stock admin — no BrikPanel
	// font/accent theming. Never trips on login_head (is_admin() is false
	// there, so should_neutralize() returns false).
	if ( function_exists( 'brikpanel_access_should_neutralize' ) && brikpanel_access_should_neutralize() ) {
		return;
	}
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
	if ( function_exists( 'brikpanel_access_should_neutralize' ) && brikpanel_access_should_neutralize() ) {
		return;
	}
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
	if ( function_exists( 'brikpanel_access_should_neutralize' ) && brikpanel_access_should_neutralize() ) {
		return;
	}
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
			'name'    => __( 'Custom brand logo', 'brikpanel' ),
			'id'      => 'brikpanel_brand_logo_enabled',
			'type'    => 'checkbox',
			'desc'    => __( 'Replace the BrikPanel mark in the admin top bar and on the modern login page with your own brand logo. When off, the default BrikPanel icon and your site name are shown.', 'brikpanel' ),
			'default' => 'no',
		],
		[
			'name' => __( 'Brand logo image', 'brikpanel' ),
			'id'   => 'brikpanel_brand_logo_id',
			'type' => 'brikpanel_brand_logo_picker',
			'desc' => __( 'Pick an image from your media library, or paste an external image URL to load the logo from a CDN. When a URL is set it takes priority over the media library pick. The same logo is used in the admin top bar and on the modern login page. PNG or SVG with transparent background gives the cleanest result. Recommended size: 256×256 px or wider.', 'brikpanel' ),
		],
		[
			'name' => __( 'Custom CSS', 'brikpanel' ),
			'id'   => 'brikpanel_custom_css',
			'type' => 'brikpanel_custom_css',
			'desc' => __( 'Add your own CSS to fine-tune the BrikPanel interface — change button colors, backgrounds, spacing, and more. Your rules load last, so they override the built-in styles. Tip: prefix your selectors with a BrikPanel class (for example .brikpanel-dashboard, .brikpanel-topbar, .brikpanel-pe) so the changes stay inside BrikPanel and leave the rest of the admin untouched.', 'brikpanel' ),
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

/**
 * Give Appearance its own sub-nav section under the "Interface" group instead
 * of falling into the catch-all General page. The physical splice position in
 * the fields array no longer matters once the title is mapped here.
 */
add_filter( 'woocommerce_get_sections_brikpanel', function ( $sections ) {
	if ( ! isset( $sections['appearance'] ) ) {
		$sections['appearance'] = __( 'Appearance', 'brikpanel' );
	}
	return $sections;
} );
add_filter( 'brikpanel_settings_title_section_map', function ( $map ) {
	$map['brk_appearance_title'] = 'appearance';
	return $map;
} );

// =============================================================================
// BRAND LOGO — WC settings: custom field type (media library picker)
// =============================================================================

/**
 * Render the brand logo picker row inside the BrikPanel WC settings tab.
 *
 * Uses the WordPress media library modal (wp.media) rather than a plain URL
 * input so admins can browse, upload and crop without leaving the page. The
 * attachment ID is stored — not the URL — so renaming the uploads folder or
 * regenerating thumbnails never breaks the brand mark.
 */
function brikpanel_brand_logo_render_field( $field ) {
	$id          = isset( $field['id'] ) ? $field['id'] : 'brikpanel_brand_logo_id';
	$url_id      = 'brikpanel_brand_logo_url';
	$value       = (int) get_option( $id, 0 );
	$custom_url  = (string) get_option( $url_id, '' );
	$att_url     = $value > 0 ? wp_get_attachment_image_url( $value, 'medium' ) : '';
	// An external URL overrides the media library pick in the preview, mirroring
	// the runtime resolution in brikpanel_brand_logo_get_url().
	$preview_url = $custom_url !== '' ? $custom_url : ( is_string( $att_url ) ? $att_url : '' );
	$desc        = isset( $field['desc'] ) ? $field['desc'] : '';
	$has_logo    = $preview_url !== '';
	?>
	<tr valign="top">
		<th scope="row" class="titledesc">
			<label for="<?php echo esc_attr( $url_id ); ?>"><?php echo esc_html( $field['name'] ); ?></label>
		</th>
		<td class="forminp forminp-brikpanel_brand_logo_picker">
			<div class="brikpanel-logo-picker" data-target="<?php echo esc_attr( $id ); ?>" data-att-url="<?php echo esc_attr( is_string( $att_url ) ? $att_url : '' ); ?>">
				<div class="brikpanel-logo-picker__preview <?php echo $has_logo ? 'has-logo' : 'is-empty'; ?>">
					<?php if ( $has_logo ) : ?>
						<img src="<?php echo esc_url( $preview_url ); ?>" alt="" />
					<?php else : ?>
						<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
							<rect x="3" y="3" width="18" height="18" rx="2" ry="2"/>
							<circle cx="8.5" cy="8.5" r="1.5"/>
							<polyline points="21 15 16 10 5 21"/>
						</svg>
						<span><?php esc_html_e( 'No logo selected', 'brikpanel' ); ?></span>
					<?php endif; ?>
				</div>
				<div class="brikpanel-logo-picker__actions">
					<button type="button" class="button brikpanel-logo-picker__select">
						<?php echo $has_logo ? esc_html__( 'Replace logo', 'brikpanel' ) : esc_html__( 'Select logo', 'brikpanel' ); ?>
					</button>
					<button type="button" class="button-link brikpanel-logo-picker__remove" <?php echo $has_logo ? '' : 'hidden'; ?>>
						<?php esc_html_e( 'Remove', 'brikpanel' ); ?>
					</button>
				</div>
				<input type="hidden" name="<?php echo esc_attr( $id ); ?>" id="<?php echo esc_attr( $id ); ?>" value="<?php echo esc_attr( $value ); ?>" />
				<div class="brikpanel-logo-picker__url">
					<label for="<?php echo esc_attr( $url_id ); ?>"><?php esc_html_e( 'Or load from an image URL (CDN)', 'brikpanel' ); ?></label>
					<input type="url" inputmode="url" class="regular-text code" name="<?php echo esc_attr( $url_id ); ?>" id="<?php echo esc_attr( $url_id ); ?>" value="<?php echo esc_attr( $custom_url ); ?>" placeholder="https://cdn.mysite.com/logo.png" autocomplete="off" spellcheck="false" />
				</div>
			</div>
			<?php if ( $desc !== '' ) : ?>
				<p class="description"><?php echo wp_kses_post( $desc ); ?></p>
			<?php endif; ?>
		</td>
	</tr>
	<?php
}
add_action( 'woocommerce_admin_field_brikpanel_brand_logo_picker', 'brikpanel_brand_logo_render_field' );

/**
 * Persist the brand logo attachment ID on settings submit. Runs at the
 * standard WC priority and ignores the value when the attachment does not
 * exist or is not an image, so a stale ID never lingers in the option.
 */
add_action( 'woocommerce_update_options_brikpanel', function () {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		return;
	}
	if ( ! isset( $_POST['brikpanel_brand_logo_id'] ) ) {
		return;
	}
	$raw = (int) wp_unslash( $_POST['brikpanel_brand_logo_id'] );
	if ( $raw <= 0 ) {
		update_option( 'brikpanel_brand_logo_id', 0 );
		return;
	}
	$attachment = get_post( $raw );
	if ( ! $attachment || $attachment->post_type !== 'attachment' ) {
		update_option( 'brikpanel_brand_logo_id', 0 );
		return;
	}
	$mime = (string) get_post_mime_type( $attachment );
	if ( strpos( $mime, 'image/' ) !== 0 ) {
		update_option( 'brikpanel_brand_logo_id', 0 );
		return;
	}
	update_option( 'brikpanel_brand_logo_id', (int) $attachment->ID );
} );

/**
 * Persist the external brand logo URL on settings submit. Sanitized with
 * esc_url_raw and restricted to http(s) (plus protocol-relative) so the option
 * can never carry a javascript: or data: payload into the topbar / login CSS.
 * An empty or invalid value clears the override and falls back to the media
 * library pick (if any).
 */
add_action( 'woocommerce_update_options_brikpanel', function () {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		return;
	}
	if ( ! isset( $_POST['brikpanel_brand_logo_url'] ) ) {
		return;
	}
	$raw = trim( (string) wp_unslash( $_POST['brikpanel_brand_logo_url'] ) );
	if ( $raw === '' ) {
		update_option( 'brikpanel_brand_logo_url', '' );
		return;
	}
	$clean = esc_url_raw( $raw, [ 'http', 'https' ] );
	update_option( 'brikpanel_brand_logo_url', is_string( $clean ) ? $clean : '' );
} );

/**
 * Load the media library on the BrikPanel WC settings tab plus the styles &
 * inline script for the logo picker. Scoped to that screen so we never pay
 * the cost on unrelated admin pages.
 */
add_action( 'admin_enqueue_scripts', function () {
	if ( ! isset( $_GET['page'], $_GET['tab'] ) ) {
		return;
	}
	if ( sanitize_key( $_GET['page'] ) !== 'wc-settings' || sanitize_key( $_GET['tab'] ) !== 'brikpanel' ) {
		return;
	}
	// The brand logo picker only renders on the Appearance section, so the
	// media library is only needed there. Loading wp_enqueue_media() on every
	// BrikPanel section pulled its whole dependency graph into the head of
	// every section for nothing; the lighter the settings head, the less it can
	// aggravate conflicts with other admin plugins. Scope strictly to
	// Appearance, mirroring the code-editor block below.
	if ( function_exists( 'brikpanel_settings_get_current_section' )
		&& brikpanel_settings_get_current_section() !== 'appearance' ) {
		return;
	}
	wp_enqueue_media();

	$css = '
		.brikpanel-logo-picker { display:flex; flex-wrap:wrap; align-items:center; gap:1rem; max-width:560px; }
		.brikpanel-logo-picker__preview {
			display:flex; align-items:center; justify-content:center; gap:0.5rem;
			width:120px; height:80px; padding:0.5rem;
			background:#fafafa; border:1px solid #e3e3e3; border-radius:0.5rem;
			color:#8a8a8a; font-size:0.75rem; text-align:center; line-height:1.3;
		}
		.brikpanel-logo-picker__preview.has-logo { padding:4px; background:#ffffff; }
		.brikpanel-logo-picker__preview img { max-width:100%; max-height:100%; object-fit:contain; display:block; }
		.brikpanel-logo-picker__preview.is-empty { flex-direction:column; }
		.brikpanel-logo-picker__actions { display:flex; align-items:center; gap:0.75rem; }
		.brikpanel-logo-picker__remove { color:#d72c0d; text-decoration:none; font-size:0.8125rem; }
		.brikpanel-logo-picker__remove:hover { color:#a02009; text-decoration:underline; }
			.brikpanel-logo-picker__url { flex:1 1 100%; display:flex; flex-direction:column; gap:0.375rem; margin-top:0.25rem; }
			.brikpanel-logo-picker__url label { font-size:0.8125rem; font-weight:600; color:#616161; }
			.brikpanel-logo-picker__url input { max-width:420px; }
	';
	wp_register_style( 'brikpanel-logo-picker', false, [], BRIKPANEL_VERSION );
	wp_enqueue_style( 'brikpanel-logo-picker' );
	wp_add_inline_style( 'brikpanel-logo-picker', $css );

	$i18n = [
		'select'  => esc_js( __( 'Select logo', 'brikpanel' ) ),
		'replace' => esc_js( __( 'Replace logo', 'brikpanel' ) ),
		'noLogo'  => esc_js( __( 'No logo selected', 'brikpanel' ) ),
		'title'   => esc_js( __( 'Select brand logo', 'brikpanel' ) ),
		'use'     => esc_js( __( 'Use this image', 'brikpanel' ) ),
	];
	$js = "(function(){
		var T = {
			select: '" . $i18n['select'] . "',
			replace: '" . $i18n['replace'] . "',
			noLogo: '" . $i18n['noLogo'] . "',
			title: '" . $i18n['title'] . "',
			use: '" . $i18n['use'] . "'
		};
		var EMPTY = '<svg width=\"28\" height=\"28\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"1.6\" stroke-linecap=\"round\" stroke-linejoin=\"round\" aria-hidden=\"true\"><rect x=\"3\" y=\"3\" width=\"18\" height=\"18\" rx=\"2\" ry=\"2\"/><circle cx=\"8.5\" cy=\"8.5\" r=\"1.5\"/><polyline points=\"21 15 16 10 5 21\"/></svg>';
		// Recompute the preview + button/remove state from the wrapper's current
		// data. An external URL (data-att-url is the fallback) takes priority over
		// the media library pick, mirroring brikpanel_brand_logo_get_url() in PHP.
		function refresh(wrap){
			var urlInput = wrap.querySelector('.brikpanel-logo-picker__url input');
			var preview = wrap.querySelector('.brikpanel-logo-picker__preview');
			var sel = wrap.querySelector('.brikpanel-logo-picker__select');
			var rm = wrap.querySelector('.brikpanel-logo-picker__remove');
			var custom = urlInput && urlInput.value ? urlInput.value.trim() : '';
			var att = wrap.getAttribute('data-att-url') || '';
			var eff = custom !== '' ? custom : att;
			if (eff !== '') {
				preview.classList.add('has-logo');
				preview.classList.remove('is-empty');
				var img = preview.querySelector('img');
				if (!img) { preview.innerHTML = '<img alt=\"\" />'; img = preview.querySelector('img'); }
				img.src = eff;
				if (sel) sel.textContent = T.replace;
				if (rm) rm.hidden = false;
			} else {
				preview.classList.remove('has-logo');
				preview.classList.add('is-empty');
				preview.innerHTML = EMPTY + '<span>' + T.noLogo + '</span>';
				if (sel) sel.textContent = T.select;
				if (rm) rm.hidden = true;
			}
		}
		var frame = null;
		document.addEventListener('click', function (e) {
			var btn = e.target.closest('.brikpanel-logo-picker__select');
			if (btn && typeof wp !== 'undefined' && wp.media) {
				e.preventDefault();
				var wrap = btn.closest('.brikpanel-logo-picker');
				if (!wrap) return;
				var input = wrap.querySelector('input[type=hidden]');
				var urlInput = wrap.querySelector('.brikpanel-logo-picker__url input');
				frame = wp.media({
					title: T.title,
					button: { text: T.use },
					multiple: false,
					library: { type: 'image' }
				});
				frame.on('select', function () {
					var a = frame.state().get('selection').first().toJSON();
					var url = a.sizes && a.sizes.medium ? a.sizes.medium.url : a.url;
					input.value = a.id;
					wrap.setAttribute('data-att-url', url);
					// Picking from the library overrides a previously typed URL so
					// the new image actually shows, instead of being shadowed.
					if (urlInput) urlInput.value = '';
					refresh(wrap);
				});
				frame.open();
				return;
			}
			var rm = e.target.closest('.brikpanel-logo-picker__remove');
			if (rm) {
				e.preventDefault();
				var w = rm.closest('.brikpanel-logo-picker');
				if (!w) return;
				w.querySelector('input[type=hidden]').value = '0';
				w.setAttribute('data-att-url', '');
				var u = w.querySelector('.brikpanel-logo-picker__url input');
				if (u) u.value = '';
				refresh(w);
			}
		});
		document.addEventListener('input', function (e) {
			var inp = e.target.closest('.brikpanel-logo-picker__url input');
			if (!inp) return;
			var wrap = inp.closest('.brikpanel-logo-picker');
			if (wrap) refresh(wrap);
		});
	})();";
	wp_register_script( 'brikpanel-logo-picker', '', [], BRIKPANEL_VERSION, true );
	wp_enqueue_script( 'brikpanel-logo-picker' );
	wp_add_inline_script( 'brikpanel-logo-picker', $js );
} );

// =============================================================================
// BRAND LOGO — runtime: login page + admin topbar overrides
// =============================================================================

/**
 * Print the login-page override CSS that swaps the BrikPanel mark inside
 * `.login h1::before` for the admin's brand logo. Emitted only when both the
 * feature toggle is on AND the modern login page is active, so we never leak
 * brand assets onto the native wp-login.php style when the modern login is
 * disabled.
 */
function brikpanel_brand_logo_print_login_styles() {
	$url = brikpanel_brand_logo_get_url();
	if ( $url === '' ) {
		return;
	}
	if ( get_option( 'brikpanel_modern_login', 'yes' ) !== 'yes' ) {
		return;
	}
	$safe = esc_url( $url );
	$css  = '.login h1::before{'
		. 'width:200px !important;'
		. 'height:64px !important;'
		. 'margin:0 auto 1rem !important;'
		. 'background:transparent !important;'
		. 'background-image:url("' . $safe . '") !important;'
		. 'background-repeat:no-repeat !important;'
		. 'background-position:center !important;'
		. 'background-size:contain !important;'
		. 'border-radius:0 !important;'
		. '}'
		// Drop the "Welcome back" subtitle so the custom logo carries the
		// brand identity without a redundant label underneath.
		. '.login h1::after{display:none !important;}';
	echo '<style id="brikpanel-brand-logo-login">' . $css . '</style>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- URL escaped above.
}
add_action( 'login_head', 'brikpanel_brand_logo_print_login_styles', 10000 );

/**
 * Print the admin override CSS for the BrikPanel top bar so a wide / non-
 * square brand logo can stretch horizontally instead of being letterboxed
 * inside the default 32×32 square. The class hook is added in the topbar
 * render via `brikpanel_brand_logo_topbar_classes()` below.
 */
function brikpanel_brand_logo_print_admin_styles() {
	if ( function_exists( 'brikpanel_access_should_neutralize' ) && brikpanel_access_should_neutralize() ) {
		return;
	}
	if ( brikpanel_brand_logo_get_url() === '' ) {
		return;
	}
	$css = '.brikpanel-topbar-brand-mark.has-custom-logo{'
		. 'width:auto;'
		. 'min-width:32px;'
		. 'max-width:160px;'
		. 'height:36px;'
		. 'border-radius:0;'
		. 'overflow:visible;'
		. '}'
		. '.brikpanel-topbar-brand-mark.has-custom-logo img{'
		. 'width:auto;'
		. 'height:100%;'
		. 'max-width:100%;'
		. 'object-fit:contain;'
		. '}';
	echo '<style id="brikpanel-brand-logo-admin">' . $css . '</style>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- no dynamic input.
}
add_action( 'admin_head', 'brikpanel_brand_logo_print_admin_styles', 10000 );

// =============================================================================
// CUSTOM CSS — WC settings field, save handler, runtime print
// =============================================================================

/**
 * Render the Custom CSS editor row inside the BrikPanel WC settings tab.
 *
 * A plain `<textarea>` is the markup baseline so the field works (and saves)
 * even when the user has disabled the code editor in their profile. When the
 * code editor is available it is upgraded to a CodeMirror instance with CSS
 * syntax highlighting + linting by the inline script enqueued below.
 */
function brikpanel_custom_css_render_field( $field ) {
	$id    = 'brikpanel_custom_css';
	$value = brikpanel_appearance_get_custom_css();
	$desc  = isset( $field['desc'] ) ? $field['desc'] : '';
	?>
	<tr valign="top">
		<th scope="row" class="titledesc">
			<label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $field['name'] ); ?></label>
		</th>
		<td class="forminp forminp-brikpanel_custom_css">
			<div class="brikpanel-custom-css">
				<textarea name="<?php echo esc_attr( $id ); ?>" id="<?php echo esc_attr( $id ); ?>" class="brikpanel-custom-css__editor" rows="14" cols="60" spellcheck="false" autocomplete="off" autocorrect="off" autocapitalize="off"><?php echo esc_textarea( $value ); ?></textarea>
			</div>
			<?php if ( $desc !== '' ) : ?>
				<p class="description"><?php echo wp_kses_post( $desc ); ?></p>
			<?php endif; ?>
		</td>
	</tr>
	<?php
}
add_action( 'woocommerce_admin_field_brikpanel_custom_css', 'brikpanel_custom_css_render_field' );

/**
 * Persist the custom CSS on settings submit. Runs inside the WC settings save
 * pipeline, which has already verified the settings nonce before firing this
 * action, so the capability gate is the only additional check we need.
 *
 * Priority 99 is deliberate: WooCommerce's generic `woocommerce_update_options()`
 * also picks up our registered field and stores an HTML-entity-encoded copy
 * (`<` → `&lt;`), which would render literally inside a raw-text `<style>` tag.
 * Running last lets our own sanitizer write the clean, verbatim CSS that wins.
 */
add_action( 'woocommerce_update_options_brikpanel', function () {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		return;
	}
	if ( ! isset( $_POST['brikpanel_custom_css'] ) ) {
		return;
	}
	$raw = (string) wp_unslash( $_POST['brikpanel_custom_css'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized on the next line.
	update_option( 'brikpanel_custom_css', brikpanel_appearance_sanitize_custom_css( $raw ) );
}, 99 );

/**
 * Upgrade the Custom CSS textarea to a CodeMirror editor on the BrikPanel
 * settings tab, and style the editor to match the BrikPanel design language.
 * Scoped to that screen so we never pay the cost on unrelated admin pages.
 */
add_action( 'admin_enqueue_scripts', function () {
	if ( ! isset( $_GET['page'], $_GET['tab'] ) ) {
		return;
	}
	if ( sanitize_key( $_GET['page'] ) !== 'wc-settings' || sanitize_key( $_GET['tab'] ) !== 'brikpanel' ) {
		return;
	}
	// The Custom CSS textarea only renders on the Appearance section. Loading
	// the code editor (and running its initializer) on any other section both
	// wastes assets and throws an uncaught CodeMirror error against a textarea
	// that isn't on the page — which can abort later jQuery ready callbacks and
	// visibly break the settings screen. Scope strictly to the Appearance tab.
	if ( function_exists( 'brikpanel_settings_get_current_section' )
		&& brikpanel_settings_get_current_section() !== 'appearance' ) {
		return;
	}

	$css = '
		.brikpanel-custom-css { max-width:720px; }
		.brikpanel-custom-css .CodeMirror,
		.brikpanel-custom-css__editor {
			width:100%; min-height:300px;
			border:1px solid #8a8a8a; border-radius:0.5rem;
			font-family:Menlo,Consolas,Monaco,monospace; font-size:0.8125rem; line-height:1.6;
		}
		.brikpanel-custom-css .CodeMirror { padding:0; overflow:hidden; }
		.brikpanel-custom-css .CodeMirror-focused { border-color:#303030; box-shadow:0 0 0 1px #303030; }
		.brikpanel-custom-css__editor { padding:0.625rem 0.75rem; resize:vertical; color:#303030; }
		.brikpanel-custom-css__editor:focus { border-color:#303030; box-shadow:0 0 0 1px #303030; outline:none; }
	';
	wp_register_style( 'brikpanel-custom-css-editor', false, [], BRIKPANEL_VERSION );
	wp_enqueue_style( 'brikpanel-custom-css-editor' );
	wp_add_inline_style( 'brikpanel-custom-css-editor', $css );

	// Returns false when the user disabled the code editor in their profile —
	// in that case the plain textarea is left as-is and still saves.
	$settings = wp_enqueue_code_editor( [ 'type' => 'text/css' ] );
	if ( false === $settings ) {
		return;
	}
	wp_add_inline_script(
		'code-editor',
		sprintf(
			'jQuery(function($){ if(window.wp && wp.codeEditor && document.getElementById("brikpanel_custom_css")){ wp.codeEditor.initialize("brikpanel_custom_css", %s); } });',
			wp_json_encode( $settings )
		)
	);
} );

/**
 * Print the admin's custom CSS into the admin <head>. Hooked after the
 * appearance overrides (9999) and brand logo admin styles (10000) so the
 * user's own rules land last and win the cascade. Excluded users get the
 * stock admin with no BrikPanel CSS injected.
 */
function brikpanel_custom_css_print_admin_styles() {
	if ( function_exists( 'brikpanel_access_should_neutralize' ) && brikpanel_access_should_neutralize() ) {
		return;
	}
	$css = brikpanel_appearance_get_custom_css();
	if ( $css === '' ) {
		return;
	}
	echo "<style id=\"brikpanel-custom-css-overrides\">{$css}</style>"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- sanitized in brikpanel_appearance_sanitize_custom_css().
}
add_action( 'admin_head', 'brikpanel_custom_css_print_admin_styles', 10001 );
