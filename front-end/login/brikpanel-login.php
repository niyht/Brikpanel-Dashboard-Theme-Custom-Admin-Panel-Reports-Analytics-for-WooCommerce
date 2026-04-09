<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Brikpanel_Login {

    public function __construct() {
        add_action( 'login_enqueue_scripts', array( $this, 'enqueue_assets' ) );
        add_action( 'login_head', array( $this, 'hide_default_styles' ) );
        add_filter( 'login_headerurl', array( $this, 'logo_url' ) );
        add_filter( 'login_headertext', array( $this, 'logo_title' ) );
        add_action( 'login_footer', array( $this, 'render_custom_footer' ) );
        add_action( 'wp_ajax_nopriv_brikpanel_ajax_login', array( $this, 'handle_ajax_login' ) );
        add_action( 'wp_ajax_brikpanel_ajax_login', array( $this, 'handle_ajax_login' ) );
    }

    /**
     * Enqueue login page assets.
     */
    public function enqueue_assets() {
        wp_enqueue_style(
            'brikpanel-login',
            BRIKPANEL_URL . 'front-end/login/brikpanel-login.css',
            array(),
            BRIKPANEL_VERSION
        );

        wp_enqueue_script(
            'brikpanel-login',
            BRIKPANEL_URL . 'front-end/login/brikpanel-login.js',
            array(),
            BRIKPANEL_VERSION,
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
            $message    = esc_html__( 'Invalid username or password.', 'brikpanel' );

            if ( $error_code === 'invalid_username' || $error_code === 'invalid_email' ) {
                $message = esc_html__( 'Unknown username or email address.', 'brikpanel' );
            } elseif ( $error_code === 'incorrect_password' ) {
                $message = esc_html__( 'The password you entered is incorrect.', 'brikpanel' );
            } elseif ( $error_code === 'empty_username' ) {
                $message = esc_html__( 'Please enter a username or email address.', 'brikpanel' );
            } elseif ( $error_code === 'empty_password' ) {
                $message = esc_html__( 'Please enter your password.', 'brikpanel' );
            }

            wp_send_json_error( array( 'message' => $message ) );
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
