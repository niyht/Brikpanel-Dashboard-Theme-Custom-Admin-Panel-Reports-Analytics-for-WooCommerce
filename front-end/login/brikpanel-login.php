<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Brikpanel_Login {

    /**
     * Heading text resolved for this request, or null before it is worked out.
     *
     * @var string|null
     */
    private $heading_cache = null;

    public function __construct() {
        if ( get_option( 'brikpanel_modern_login', 'yes' ) !== 'yes' ) {
            return;
        }

        add_action( 'login_enqueue_scripts', array( $this, 'enqueue_assets' ) );
        add_action( 'login_head', array( $this, 'hide_default_styles' ) );
        add_filter( 'login_headerurl', array( $this, 'logo_url' ) );
        add_filter( 'login_headertext', array( $this, 'logo_title' ) );
        add_action( 'login_footer', array( $this, 'render_custom_footer' ) );

        // Register the AJAX endpoint on `plugins_loaded` so that class/constant
        // probes for 3rd-party 2FA plugins (Wordfence LS, Two Factor, WP 2FA,
        // …) run AFTER every plugin has had a chance to declare its symbols.
        // Registering from the constructor is too early — brikpanel.php is
        // require'd during the initial plugin include phase, before most
        // plugins have loaded their classes.
        add_action( 'plugins_loaded', array( $this, 'maybe_register_ajax_actions' ), 100 );
    }

    /**
     * Conditionally expose the AJAX login endpoint.
     *
     * Skipping the action registration when a 2FA / SSO plugin is active
     * also removes a redundant attack surface — the endpoint literally does
     * not exist, so there is nothing for a stale cached script or a hand-
     * crafted POST to bypass.
     */
    public function maybe_register_ajax_actions() {
        if ( $this->should_disable_ajax_login() ) {
            return;
        }
        add_action( 'wp_ajax_nopriv_brikpanel_ajax_login', array( $this, 'handle_ajax_login' ) );
        add_action( 'wp_ajax_brikpanel_ajax_login', array( $this, 'handle_ajax_login' ) );
    }

    /**
     * Detect whether AJAX login interception must stand down.
     *
     * Intercepting wp-login.php via AJAX is incompatible with plugins that
     * alter the multi-step authentication flow — either by injecting extra
     * fields into the login form (Wordfence LS) or by redirecting to a
     * dedicated challenge page after primary auth (Two Factor, WP 2FA,
     * miniOrange). In both cases the AJAX path either breaks the UX or,
     * worse, silently bypasses the second factor.
     *
     * When this returns true we only restyle wp-login.php; the browser
     * submits the form natively and the 3rd-party plugin sees its expected
     * environment.
     */
    private function should_disable_ajax_login() {
        // Manual override — always wins over auto-detection.
        if ( get_option( 'brikpanel_login_force_native', 'yes' ) === 'yes' ) {
            return true;
        }

        // Known login-flow-modifying plugins. Class / function / constant
        // existence checks are cheap and don't depend on is_plugin_active(),
        // which isn't loaded on the front-end.
        $detected =
            class_exists( '\\WordfenceLS\\Controller_WordfenceLS' )    // Wordfence Login Security
            || class_exists( 'Two_Factor_Core' )                        // Two Factor (official)
            || defined( 'WP_2FA_VERSION' )                              // WP 2FA by Melapress
            || class_exists( 'Miniorange_Authentication' )              // miniOrange 2FA
            || class_exists( 'ITSEC_Two_Factor' )                       // Solid Security / iThemes
            || function_exists( 'duo_start_session' )                   // Duo Two-Factor
            || class_exists( 'RublonWordPress' )                        // Rublon
            || class_exists( 'GoogleAuthenticator' );                   // Google Authenticator (Henrik Schack)

        /**
         * Filters whether BrikPanel should skip AJAX login interception.
         *
         * Lets site owners or 3rd-party plugins force native wp-login.php
         * submission when their custom `authenticate` / `wp_login` flow is
         * incompatible with AJAX — for example multi-step 2FA challenges
         * or SSO redirects.
         *
         * @param bool $disabled Default based on auto-detection of known plugins.
         */
        return (bool) apply_filters( 'brikpanel_disable_ajax_login', $detected );
    }

    /**
     * Enqueue login page assets.
     */
    public function enqueue_assets() {
        $css_path = __DIR__ . '/brikpanel-login.css';
        $js_path  = __DIR__ . '/brikpanel-login.js';
        $css_ver  = BRIKPANEL_VERSION . ( file_exists( $css_path ) ? '.' . filemtime( $css_path ) : '' );
        $js_ver   = BRIKPANEL_VERSION . ( file_exists( $js_path )  ? '.' . filemtime( $js_path )  : '' );

        wp_enqueue_style(
            'brikpanel-login',
            BRIKPANEL_URL . 'front-end/login/brikpanel-login.css',
            array(),
            $css_ver
        );

        // Style-only mode when a 2FA / SSO plugin is active or the site
        // owner has forced native submission. wp-login.php keeps its native
        // POST flow so multi-step authentication works as the plugin author
        // intended.
        if ( $this->should_disable_ajax_login() ) {
            return;
        }

        wp_enqueue_script(
            'brikpanel-login',
            BRIKPANEL_URL . 'front-end/login/brikpanel-login.js',
            array(),
            $js_ver,
            true
        );

        wp_localize_script( 'brikpanel-login', 'brikpanelLogin', array(
            'ajaxurl'  => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'brikpanel_login_nonce' ),
            'redirect' => admin_url(),
            'i18n'     => array(
                'logging_in'     => esc_html__( 'Logging in...', 'brikpanel' ),
                'login'          => esc_html__( 'Log In', 'brikpanel' ),
                'error_generic'  => esc_html__( 'An error occurred. Please try again.', 'brikpanel' ),
            ),
        ) );
    }

    /**
     * Hide WordPress default login branding via CSS.
     *
     * The heading anchor is NOT hidden here any more. wp-login.php prints
     * `<h1><a>{login_headertext}</a></h1>`, so that anchor is the element
     * that carries the heading — and whether it ends up empty is only
     * settled after every plugin has had its turn on `login_headertext`,
     * which fires after `login_head`. The stylesheet hides it with
     * `#login h1 a:empty` instead, which is decided by the final markup
     * rather than by a guess made earlier in the request.
     */
    public function hide_default_styles() {
        ?>
        <style>
            /* Hide default WP elements that we replace */
            .language-switcher { display: none !important; }
        </style>
        <?php
    }

    /**
     * Change the logo link to the site URL.
     */
    public function logo_url() {
        return home_url( '/' );
    }

    /**
     * The heading text rendered inside the login logo anchor.
     *
     * wp-login.php echoes this value WITHOUT escaping it, so the escaping
     * happens here and nowhere else.
     */
    public function logo_title() {
        $text = $this->heading_text();

        return $text === '' ? '' : esc_html( $text );
    }

    /**
     * Which login screen is being rendered.
     *
     * `login_headertext` fires before the `login_body_class` filter, so the
     * action cannot be taken from a filter argument. wp-login.php declares
     * `global $action` inside login_header() and validates it before the
     * header renders, which makes the global reliable — but a plugin that
     * registers a `login_form_{$action}` filter lets an arbitrary string
     * through core's validation, so it is re-checked against our own list
     * before it is ever used as an array key.
     *
     * @return string One of the known action slugs; `login` as the fallback.
     */
    private function current_action() {
        $action = isset( $GLOBALS['action'] ) && is_string( $GLOBALS['action'] ) ? $GLOBALS['action'] : 'login';

        $known = array(
            'login',
            'lostpassword',
            'retrievepassword',
            'resetpass',
            'rp',
            'register',
            'checkemail',
            'confirmaction',
            'confirm_admin_email',
        );

        return in_array( $action, $known, true ) ? $action : 'login';
    }

    /**
     * Resolve the heading once per request.
     *
     * Both `login_head` (which decides whether the anchor stays hidden) and
     * the `login_headertext` filter need the answer, and they run at
     * different points of the same page render.
     *
     * @return string Unescaped heading text; empty string means "no heading".
     */
    private function heading_text() {
        if ( $this->heading_cache === null ) {
            $this->heading_cache = $this->resolve_heading_text();
        }

        return $this->heading_cache;
    }

    /**
     * Work out what belongs above the login card.
     *
     * Two axes decide it: the admin's heading choice, and which screen is
     * being rendered. Password reset and registration screens always get
     * their own wording — a store's "Welcome back" (or its name) above a
     * "choose a new password" form reads as a mistake.
     *
     * @return string
     */
    private function resolve_heading_text() {
        $mode = brikpanel_login_clean_heading_mode( get_option( 'brikpanel_login_heading', 'default' ) );

        if ( 'none' === $mode ) {
            return '';
        }

        // A brand logo with the heading left on its default is the look this
        // plugin has shipped since the logo picker landed: the logo carries
        // the identity and nothing is written underneath it. Only an explicit
        // choice — site name or custom text — puts a heading back under it,
        // so existing installs are not redesigned by an update.
        $has_logo = function_exists( 'brikpanel_brand_logo_get_url' )
            && brikpanel_brand_logo_get_url() !== '';

        if ( 'default' === $mode && $has_logo ) {
            return '';
        }

        $action = $this->current_action();

        if ( 'login' !== $action ) {
            $per_action = array(
                'lostpassword'        => __( 'Reset your password', 'brikpanel' ),
                'retrievepassword'    => __( 'Reset your password', 'brikpanel' ),
                'resetpass'           => __( 'Choose a new password', 'brikpanel' ),
                'rp'                  => __( 'Choose a new password', 'brikpanel' ),
                'register'            => __( 'Create an account', 'brikpanel' ),
                'checkemail'          => __( 'Check your email', 'brikpanel' ),
                'confirmaction'       => __( 'Confirm your action', 'brikpanel' ),
                'confirm_admin_email' => __( 'Confirm your email address', 'brikpanel' ),
            );

            if ( isset( $per_action[ $action ] ) ) {
                return $per_action[ $action ];
            }
        }

        if ( 'site_name' === $mode ) {
            // Decoded before it is re-escaped by the caller: get_bloginfo()
            // hands back a display-filtered name, so an ampersand would
            // otherwise ship as `&amp;amp;`.
            return wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
        }

        if ( 'custom' === $mode ) {
            $custom = trim( (string) get_option( 'brikpanel_login_heading_text', '' ) );

            if ( '' !== $custom ) {
                return $custom;
            }
        }

        return __( 'Welcome back', 'brikpanel' );
    }

    /**
     * Render custom footer in the login page.
     */
    public function render_custom_footer() {
        if ( get_option( 'brikpanel_login_hide_footer_credit', 'yes' ) !== 'yes' ) {
            $site_name = get_bloginfo( 'name' );
            ?>
            <div class="brikpanel-login-footer">
                <?php
                printf(
                    /* translators: %s: site name */
                    esc_html__( '%s · Powered by WordPress', 'brikpanel' ),
                    esc_html( $site_name )
                );
                ?>
            </div>
            <?php
        }
        ?>
        <div id="brikpanel-toast" class="brikpanel-toast" aria-live="polite"></div>
        <?php
    }

    /**
     * Handle AJAX login request.
     */
    public function handle_ajax_login() {
        // Verify nonce
        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['nonce'] ), 'brikpanel_login_nonce' ) ) {
            wp_send_json_error( array(
                'message' => esc_html__( 'Security check failed. Please refresh the page.', 'brikpanel' ),
            ) );
        }

        $username = isset( $_POST['username'] ) ? sanitize_user( wp_unslash( $_POST['username'] ) ) : '';
        $password = isset( $_POST['password'] ) ? wp_unslash( $_POST['password'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $remember = isset( $_POST['remember'] ) && $_POST['remember'] === 'true';

        if ( empty( $username ) || empty( $password ) ) {
            wp_send_json_error( array(
                'message' => esc_html__( 'Please enter both username and password.', 'brikpanel' ),
            ) );
        }

        $creds = array(
            'user_login'    => $username,
            'user_password' => $password,
            'remember'      => $remember,
        );

        $user = wp_signon( $creds, is_ssl() );

        if ( is_wp_error( $user ) ) {
            $error_code = $user->get_error_code();
            $credential_codes = array(
                'invalid_username', 'invalid_email',
                'incorrect_password', 'empty_username', 'empty_password',
            );

            if ( in_array( $error_code, $credential_codes, true ) ) {
                if ( $error_code === 'invalid_username' || $error_code === 'invalid_email' ) {
                    $message = esc_html__( 'Unknown username or email address.', 'brikpanel' );
                } elseif ( $error_code === 'incorrect_password' ) {
                    $message = esc_html__( 'The password you entered is incorrect.', 'brikpanel' );
                } elseif ( $error_code === 'empty_username' ) {
                    $message = esc_html__( 'Please enter a username or email address.', 'brikpanel' );
                } else {
                    $message = esc_html__( 'Please enter your password.', 'brikpanel' );
                }
            } else {
                // Surface the actual error from `authenticate` filter — this is
                // where captcha plugins (Cloudflare Turnstile, hCaptcha,
                // reCAPTCHA, 2FA plugins, etc.) return their own WP_Error with a
                // user-friendly explanation. Strip tags and normalise whitespace
                // so the message renders cleanly inside the toast.
                $raw = $user->get_error_message();
                if ( ! is_string( $raw ) || $raw === '' ) {
                    $raw = esc_html__( 'Login failed. Please try again.', 'brikpanel' );
                }
                $message = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $raw ) ) );
            }

            wp_send_json_error( array(
                'message' => $message,
                'code'    => $error_code,
            ) );
        }

        // Determine redirect URL
        $redirect = admin_url();

        if ( isset( $_POST['redirect_to'] ) && ! empty( $_POST['redirect_to'] ) ) {
            $redirect = esc_url_raw( wp_unslash( $_POST['redirect_to'] ) );
        }

        wp_send_json_success( array(
            'redirect' => $redirect,
            'message'  => esc_html__( 'Login successful! Redirecting...', 'brikpanel' ),
        ) );
    }
}

new Brikpanel_Login();

// =============================================================================
// LOGIN SETTINGS GLUE — heading sanitizer + brand logo pointer row
// =============================================================================
// These sit at file scope, outside the class, because the settings screen
// renders whether or not the modern login page is switched on.

/**
 * The heading modes the setting accepts.
 *
 * @return string[]
 */
function brikpanel_login_heading_modes() {
    return array( 'default', 'site_name', 'custom', 'none' );
}

/**
 * Clean a heading mode. Anything unrecognised falls back to the default,
 * which is what an unset option resolves to anyway.
 *
 * @param mixed $value Candidate mode.
 * @return string
 */
function brikpanel_login_clean_heading_mode( $value ) {
    $value = is_scalar( $value ) ? (string) $value : '';

    return in_array( $value, brikpanel_login_heading_modes(), true ) ? $value : 'default';
}

/**
 * Clean the custom heading text.
 *
 * WooCommerce's default text sanitizer runs sanitize_text_field(), which
 * strips anything shaped like a percent-encoded octet — a heading such as
 * "%20 off today" would silently lose its "%20". Same reasoning, and the same
 * shape, as the cart abandonment popup copy sanitizer.
 *
 * Takes a single argument so the settings screen, the importer and any future
 * caller all clean the value exactly the same way.
 *
 * @param mixed $text Candidate heading.
 * @return string
 */
function brikpanel_login_clean_heading_text( $text ) {
    $clean = wp_strip_all_tags( is_scalar( $text ) ? (string) $text : '' );
    // Drop control characters; keep printable punctuation.
    $clean = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', (string) $clean );
    // One line, one space between words: the heading renders on a single row,
    // so a pasted newline or tab would only show up as a ragged gap.
    $clean = preg_replace( '/\s+/u', ' ', (string) $clean );
    $clean = trim( (string) $clean );

    // Counted in characters, not bytes, so a multibyte heading is not cut
    // mid-character.
    return brikpanel_substr( $clean, 0, 100 );
}

/**
 * WooCommerce settings hook — clean the submitted heading before it is saved.
 *
 * @param mixed $value     Value WooCommerce prepared.
 * @param array $option    Field definition.
 * @param mixed $raw_value Raw submitted value.
 * @return string
 */
function brikpanel_login_sanitize_heading_text( $value, $option, $raw_value ) {
    return brikpanel_login_clean_heading_text( $raw_value );
}
add_filter( 'woocommerce_admin_settings_sanitize_option_brikpanel_login_heading_text', 'brikpanel_login_sanitize_heading_text', 10, 3 );

/**
 * Own both heading keys in the export registry.
 *
 * The settings-field walk would otherwise classify the text field by its
 * WooCommerce type and clean it with sanitize_text_field() on import — which
 * eats a percent sign, so a heading that survived being typed would not
 * survive being carried to another site. Registered at file scope, above
 * every module gate, the way the export coverage audit requires.
 *
 * @param array $map Registry so far.
 * @return array
 */
function brikpanel_login_register_export_keys( $map ) {
    $map['brikpanel_login_heading'] = array(
        'class'    => 'portable',
        'group'    => 'login',
        'sanitize' => 'brikpanel_login_clean_heading_mode',
        'default'  => 'default',
    );
    $map['brikpanel_login_heading_text'] = array(
        'class'    => 'portable',
        'group'    => 'login',
        'sanitize' => 'brikpanel_login_clean_heading_text',
        'default'  => '',
    );

    return $map;
}
add_filter( 'brikpanel_exportable_option_keys', 'brikpanel_login_register_export_keys' );

/**
 * Render the pointer row that sends the admin to the brand logo picker.
 *
 * The logo shown on the login page has always been the Appearance brand logo,
 * but nothing on the Login settings screen said so, so it read as a missing
 * feature.
 *
 * @param array $field Field definition.
 * @return void
 */
function brikpanel_login_render_logo_hint( $field ) {
    $name = isset( $field['name'] ) ? $field['name'] : __( 'Login page logo', 'brikpanel' );
    $url  = admin_url( 'admin.php?page=wc-settings&tab=brikpanel&section=appearance' );
    ?>
    <tr valign="top">
        <th scope="row" class="titledesc"><?php echo esc_html( $name ); ?></th>
        <td class="forminp forminp-brikpanel_login_logo_hint">
            <p class="description">
                <?php
                printf(
                    /* translators: %s: link to the Appearance settings section. */
                    esc_html__( 'The login page uses your brand logo. Set it under %s.', 'brikpanel' ),
                    '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Appearance', 'brikpanel' ) . '</a>'
                );
                ?>
            </p>
        </td>
    </tr>
    <?php
}
add_action( 'woocommerce_admin_field_brikpanel_login_logo_hint', 'brikpanel_login_render_logo_hint' );
