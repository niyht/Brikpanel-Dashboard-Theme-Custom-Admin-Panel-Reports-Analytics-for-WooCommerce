<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Brikpanel_Login {

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
     */
    public function hide_default_styles() {
        ?>
        <style>
            /* Hide default WP elements that we replace */
            .login h1 a { display: none !important; }
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
     * Change the logo alt text to the site name.
     */
    public function logo_title() {
        return get_bloginfo( 'name' );
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
                    esc_html__( '%s — Powered by WordPress', 'brikpanel' ),
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
