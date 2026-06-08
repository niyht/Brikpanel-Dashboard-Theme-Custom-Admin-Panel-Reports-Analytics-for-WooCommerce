<?php
/**
 * BrikPanel — Sheets OAuth proxy handshake.
 *
 * Implements the plugin-side of the proxy OAuth flow described in the plan:
 *
 *   1. ajax_start() generates state + PKCE verifier, stashes them in a
 *      short-lived transient, and returns the authorize URL pointing at the
 *      brksoft.com /oauth/start proxy endpoint.
 *   2. The browser visits brksoft.com → Google consent → brksoft.com /callback
 *      → 302 back to admin.php?page=brikpanel-google-sheets&brikpanel_oauth_return=<handoff>&state=<state>.
 *   3. handle_return() (admin_init) sees the return params, validates state
 *      against the transient, POSTs to brksoft.com /oauth/redeem with the
 *      handoff token + site_url + code_verifier, persists the returned tokens
 *      via Brikpanel_Sheets_Tokens, then redirects to a clean URL.
 *
 * @package BrikPanel
 * @since   2.9.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Brikpanel_Sheets_OAuth {

	const STATE_TRANSIENT_PREFIX = 'bp_gs_state_';
	const STATE_TTL              = 600; // 10 minutes
	const NONCE_ACTION           = 'brikpanel_gs_nonce';
	const RETURN_PARAM           = 'brikpanel_oauth_return';

	/**
	 * Scopes requested at consent.
	 *
	 * NON-SENSITIVE ONLY — by design. drive.file is a non-sensitive scope, so
	 * the OAuth consent screen needs no Google verification and users never see
	 * the "Google hasn't verified this app" warning.
	 *
	 * - drive.file:   per-file access — the app can ONLY touch spreadsheets it
	 *                 created itself or that the user explicitly hands over via
	 *                 the Google Picker. This is sufficient for every Sheets API
	 *                 call the plugin makes (create / append / update / clear /
	 *                 get / batchUpdate) on those files.
	 * - openid email: surface the connected email on the settings UI.
	 *
	 * Deliberately NOT requested: .../auth/spreadsheets (sensitive — would force
	 * Google verification and show the unverified-app warning). Picking an
	 * existing sheet is handled by the Google Picker instead.
	 */
	const SCOPES = 'https://www.googleapis.com/auth/drive.file openid email';

	public function __construct() {
		add_action( 'wp_ajax_brikpanel_gs_oauth_start',      [ $this, 'ajax_start' ] );
		add_action( 'wp_ajax_brikpanel_gs_oauth_disconnect', [ $this, 'ajax_disconnect' ] );
		add_action( 'admin_init',                            [ $this, 'handle_return' ] );
	}

	// =========================================================================
	// AJAX — generate authorize URL
	// =========================================================================

	public function ajax_start() {
		check_ajax_referer( self::NONCE_ACTION, '_ajax_nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'brikpanel' ) ], 403 );
		}

		$state    = bin2hex( random_bytes( 16 ) );
		$verifier = self::base64url( random_bytes( 32 ) );
		$challenge = self::base64url( hash( 'sha256', $verifier, true ) );

		$return_url = admin_url( 'admin.php?page=brikpanel-google-sheets' );

		set_transient(
			self::STATE_TRANSIENT_PREFIX . hash( 'sha256', $state ),
			[
				'verifier'   => $verifier,
				'return_url' => $return_url,
				'user_id'    => get_current_user_id(),
				'created_at' => time(),
			],
			self::STATE_TTL
		);

		$payload = [
			'return_url'            => $return_url,
			'state'                 => $state,
			'code_challenge'        => $challenge,
			'code_challenge_method' => 'S256',
			'site_url'              => home_url(),
			'scope'                 => self::SCOPES,
		];

		$resp = wp_remote_post( BRIKPANEL_GS_PROXY_BASE . '/oauth/start', [
			'timeout'   => 20,
			'sslverify' => true,
			'headers'   => [ 'Content-Type' => 'application/json', 'Accept' => 'application/json' ],
			'body'      => wp_json_encode( $payload ),
		] );

		$open = Brikpanel_Sheets_Proxy::open( $resp, 'oauth/start' );
		if ( $open['wp_error'] ) {
			Brikpanel_Sheets_Logger::log_request_error( 'oauth', 'oauth/start', $resp );
			wp_send_json_error( [ 'message' => __( 'Could not reach the BrikPanel proxy. Please try again in a moment.', 'brikpanel' ) ], 502 );
		}
		$code = (int) $open['code'];
		$body = $open['data'];
		if ( ! $open['ok'] || empty( $body['authorize_url'] ) ) {
			Brikpanel_Sheets_Logger::log_request_error( 'oauth', 'oauth/start', $resp, $code );
			$message = in_array( $open['error'], [ 'unsigned', 'bad_sig', 'stale', 'malformed' ], true )
				? __( 'The BrikPanel proxy returned an unverifiable response and was rejected.', 'brikpanel' )
				: ( is_array( $body ) && ! empty( $body['message'] )
					? (string) $body['message']
					: __( 'Could not start OAuth: proxy returned an error.', 'brikpanel' ) );
			wp_send_json_error( [ 'message' => $message ], 502 );
		}

		wp_send_json_success( [ 'authorize_url' => (string) $body['authorize_url'] ] );
	}

	// =========================================================================
	// AJAX — disconnect
	// =========================================================================

	public function ajax_disconnect() {
		check_ajax_referer( self::NONCE_ACTION, '_ajax_nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'brikpanel' ) ], 403 );
		}

		// Best-effort proxy revoke (does not need to succeed for local cleanup).
		$tokens_desc = Brikpanel_Sheets_Tokens::describe();
		if ( $tokens_desc['connected'] ) {
			wp_remote_post( BRIKPANEL_GS_PROXY_BASE . '/oauth/revoke', [
				'timeout'   => 8,
				'sslverify' => true,
				'headers'   => [ 'Content-Type' => 'application/json' ],
				'body'      => wp_json_encode( [ 'site_url' => home_url() ] ),
			] );
		}

		Brikpanel_Sheets_Tokens::clear();
		wp_send_json_success( [ 'message' => __( 'Disconnected.', 'brikpanel' ) ] );
	}

	// =========================================================================
	// Return handler (admin_init)
	// =========================================================================

	public function handle_return() {
		if ( ! isset( $_GET[ self::RETURN_PARAM ] ) || ! isset( $_GET['state'] ) ) {
			return;
		}
		if ( ! is_admin() ) {
			return;
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$state   = sanitize_text_field( wp_unslash( $_GET['state'] ) );
		$handoff = sanitize_text_field( wp_unslash( $_GET[ self::RETURN_PARAM ] ) );

		// Optional: an `?brikpanel_oauth_error=...` short-circuit so the proxy
		// can bubble user-facing errors (e.g. consent denied) back without
		// going through the redeem step.
		if ( isset( $_GET['brikpanel_oauth_error'] ) ) {
			$err = sanitize_text_field( wp_unslash( $_GET['brikpanel_oauth_error'] ) );
			Brikpanel_Sheets_Logger::log( 'oauth', 'Proxy reported error during consent: ' . $err );
			$this->finish_with_notice( 'error', $err );
		}

		$trans_key = self::STATE_TRANSIENT_PREFIX . hash( 'sha256', $state );
		$stash     = get_transient( $trans_key );
		if ( ! is_array( $stash ) || empty( $stash['verifier'] ) ) {
			Brikpanel_Sheets_Logger::log( 'oauth', 'OAuth return with unknown / expired state.' );
			$this->finish_with_notice( 'error', __( 'OAuth session expired. Please try connecting again.', 'brikpanel' ) );
		}
		// Single-use state.
		delete_transient( $trans_key );

		// User identity binding — refuse to apply tokens for a different user.
		if ( (int) ( $stash['user_id'] ?? 0 ) !== get_current_user_id() ) {
			Brikpanel_Sheets_Logger::log( 'oauth', 'OAuth return user mismatch.' );
			$this->finish_with_notice( 'error', __( 'OAuth callback was for a different user. Aborted.', 'brikpanel' ) );
		}

		$resp = wp_remote_post( BRIKPANEL_GS_PROXY_BASE . '/oauth/redeem', [
			'timeout'   => 20,
			'sslverify' => true,
			'headers'   => [ 'Content-Type' => 'application/json', 'Accept' => 'application/json' ],
			'body'      => wp_json_encode( [
				'handoff_token' => $handoff,
				'site_url'      => home_url(),
				'code_verifier' => $stash['verifier'],
			] ),
		] );

		$open = Brikpanel_Sheets_Proxy::open( $resp, 'oauth/redeem' );
		if ( $open['wp_error'] ) {
			Brikpanel_Sheets_Logger::log_request_error( 'oauth', 'oauth/redeem', $resp );
			$this->finish_with_notice( 'error', __( 'Could not reach the BrikPanel proxy.', 'brikpanel' ) );
		}
		$code = (int) $open['code'];
		$body = $open['data'];
		if ( ! $open['ok'] || empty( $body['access_token'] ) ) {
			Brikpanel_Sheets_Logger::log_request_error( 'oauth', 'oauth/redeem', $resp, $code );
			if ( in_array( $open['error'], [ 'unsigned', 'bad_sig', 'stale', 'malformed' ], true ) ) {
				$this->finish_with_notice( 'error', __( 'The BrikPanel proxy returned an unverifiable response and was rejected.', 'brikpanel' ) );
			}
			$this->finish_with_notice( 'error', __( 'OAuth redemption failed. Please try connecting again.', 'brikpanel' ) );
		}

		// Granular-consent guard. Google lets the user complete OAuth while
		// UNCHECKING the Drive permission, returning a perfectly valid token
		// whose granted scope is only "openid email". Saving that would show a
		// green "Connected" and then 403 on the Picker and every sync. Refuse
		// it here with an actionable message instead of a misleading success.
		//
		// The granted scope is resolved authoritatively: the proxy's echoed
		// scope when present, else Google's own tokeninfo endpoint — so the
		// gate never hard-depends on the proxy. Only an empty result (network
		// failure with no hint) fails open, to avoid locking everyone out on a
		// transient blip.
		$granted_scope = Brikpanel_Sheets_Tokens::resolve_granted_scope(
			(string) $body['access_token'],
			(string) ( $body['scope'] ?? '' )
		);
		if ( $granted_scope !== '' && ! Brikpanel_Sheets_Tokens::scope_has_drive( $granted_scope ) ) {
			Brikpanel_Sheets_Tokens::clear();
			Brikpanel_Sheets_Logger::log( 'oauth', 'Consent completed WITHOUT drive.file — granted scope: ' . $granted_scope );
			$this->finish_with_notice(
				'error',
				__( 'Almost there: Google did not grant access to your spreadsheets. On the Google permission screen, please keep the "See, edit, create and delete only the specific Google Drive files you use with this app" box checked, then connect again.', 'brikpanel' )
			);
		}

		$ok = Brikpanel_Sheets_Tokens::save( [
			'access_token'    => (string) $body['access_token'],
			'refresh_token'   => (string) ( $body['refresh_token'] ?? '' ),
			'expires_in'      => (int) ( $body['expires_in'] ?? 3600 ),
			// Persist the authoritative scope (falls back to the proxy hint)
			// so the Picker/create guards downstream stay reliable.
			'scope'           => $granted_scope !== '' ? $granted_scope : (string) ( $body['scope'] ?? '' ),
			'token_type'      => (string) ( $body['token_type'] ?? 'Bearer' ),
			'connected_email' => (string) ( $body['email'] ?? '' ),
		] );

		if ( ! $ok ) {
			$this->finish_with_notice( 'error', __( 'Could not save tokens. Please try again.', 'brikpanel' ) );
		}

		// Successful reconnect clears any operator kill-switch latch.
		Brikpanel_Sheets_Proxy::clear_killswitch();

		$this->finish_with_notice( 'success', __( 'Google Sheets connected.', 'brikpanel' ) );
	}

	// =========================================================================
	// Helpers
	// =========================================================================

	/**
	 * Redirect back to the settings page with a query flag the JS picks up
	 * to surface a toast. Exits the request.
	 *
	 * @param string $tone    success|error
	 * @param string $message
	 */
	private function finish_with_notice( $tone, $message ) {
		$url = add_query_arg(
			[
				'page'                  => 'brikpanel-google-sheets',
				'brikpanel_oauth_flash' => $tone,
				'brikpanel_msg'         => rawurlencode( $message ),
			],
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * URL-safe Base64 without padding (per RFC 4648 §5 / PKCE spec).
	 *
	 * @param string $bin
	 * @return string
	 */
	public static function base64url( $bin ) {
		return rtrim( strtr( base64_encode( $bin ), '+/', '-_' ), '=' );
	}
}
