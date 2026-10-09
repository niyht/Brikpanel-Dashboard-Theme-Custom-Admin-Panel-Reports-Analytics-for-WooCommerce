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

	/** Per-user transient a failed return leaves for the settings page (see Brikpanel_Proxy_Errors). */
	const FLASH_PREFIX = 'brikpanel_gs_oauth_err_';

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

		// The browser asks a second time, once, when the first request could
		// not reach the proxy; that one goes over IPv4 only. The return trip
		// after Google then uses the same address family, or a store whose
		// start only worked over IPv4 would fail after the merchant consented.
		$attempt = Brikpanel_Proxy_Errors::attempt_from_request();
		$ipv4    = $attempt > 1;

		$state    = bin2hex( random_bytes( 16 ) );
		$verifier = self::base64url( random_bytes( 32 ) );
		$challenge = self::base64url( hash( 'sha256', $verifier, true ) );

		$return_url = admin_url( 'admin.php?page=brikpanel-google-sheets' );

		$state_key = self::STATE_TRANSIENT_PREFIX . hash( 'sha256', $state );
		set_transient(
			$state_key,
			[
				'verifier'   => $verifier,
				'return_url' => $return_url,
				'user_id'    => get_current_user_id(),
				'created_at' => time(),
				'ipv4'       => $ipv4,
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

		$resp = Brikpanel_Proxy_Errors::post(
			BRIKPANEL_GS_PROXY_BASE . '/oauth/start',
			[
				'timeout'   => Brikpanel_Proxy_Errors::START_TIMEOUT,
				'sslverify' => true,
				'headers'   => [ 'Content-Type' => 'application/json', 'Accept' => 'application/json' ],
				'body'      => wp_json_encode( $payload ),
			],
			BRIKPANEL_GS_PROXY_BASE,
			$ipv4
		);

		$open = Brikpanel_Sheets_Proxy::open( $resp, 'oauth/start' );
		$body = $open['data'];
		if ( $open['wp_error'] || ! $open['ok'] || empty( $body['authorize_url'] ) ) {
			// Nothing will come back for this state.
			delete_transient( $state_key );
			Brikpanel_Sheets_Logger::log_request_error(
				'oauth',
				'oauth/start (attempt ' . $attempt . ( $ipv4 ? ', IPv4' : '' ) . ')',
				$resp,
				$open['wp_error'] ? 0 : (int) $open['code']
			);
			// HTTP 200 on purpose. A front server (Cloudflare, an nginx proxy)
			// replaces a 502 body with its own page, and the merchant then read
			// "click Sync now again" under the Connect button.
			wp_send_json_error( Brikpanel_Proxy_Errors::payload( $resp, $open, BRIKPANEL_GS_PROXY_BASE, 'start', $attempt ) );
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
		// A declined consent comes back with `brikpanel_oauth_error` + `state`
		// and NO handoff token. Requiring the token here made the error branch
		// below unreachable, so a merchant who clicked Cancel on Google's screen
		// landed back on the page with no message at all. The Ad Platforms twin
		// was fixed the same way.
		$has_error  = isset( $_GET['brikpanel_oauth_error'] );
		$has_return = isset( $_GET[ self::RETURN_PARAM ] );
		if ( ( ! $has_return && ! $has_error ) || ! isset( $_GET['state'] ) ) {
			return;
		}
		if ( ! is_admin() ) {
			return;
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$state   = sanitize_text_field( wp_unslash( $_GET['state'] ) );
		$handoff = $has_return ? sanitize_text_field( wp_unslash( $_GET[ self::RETURN_PARAM ] ) ) : '';

		// The proxy bubbles the consent screen's error up (e.g. consent
		// denied) without going through the redeem step. Show a sentence, not
		// the raw "access_denied" code.
		if ( $has_error ) {
			$err = sanitize_text_field( wp_unslash( $_GET['brikpanel_oauth_error'] ) );
			Brikpanel_Sheets_Logger::log( 'oauth', 'Proxy reported error during consent: ' . $err );
			// Burn the pending state so a stale one cannot be replayed.
			delete_transient( self::STATE_TRANSIENT_PREFIX . hash( 'sha256', $state ) );
			$this->finish_with_notice( 'error', Brikpanel_Proxy_Errors::consent_error_message( $err, 'sheets' ) );
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

		$ipv4 = ! empty( $stash['ipv4'] );
		$resp = Brikpanel_Proxy_Errors::post(
			BRIKPANEL_GS_PROXY_BASE . '/oauth/redeem',
			[
				'timeout'   => 20,
				'sslverify' => true,
				'headers'   => [ 'Content-Type' => 'application/json', 'Accept' => 'application/json' ],
				'body'      => wp_json_encode( [
					'handoff_token' => $handoff,
					'site_url'      => home_url(),
					'code_verifier' => $stash['verifier'],
				] ),
			],
			BRIKPANEL_GS_PROXY_BASE,
			$ipv4
		);

		$open = Brikpanel_Sheets_Proxy::open( $resp, 'oauth/redeem' );
		$body = $open['data'];
		if ( $open['wp_error'] || ! $open['ok'] || empty( $body['access_token'] ) ) {
			Brikpanel_Sheets_Logger::log_request_error(
				'oauth',
				'oauth/redeem' . ( $ipv4 ? ' (IPv4)' : '' ),
				$resp,
				$open['wp_error'] ? 0 : (int) $open['code']
			);
			$this->finish_with_notice( 'error', '', Brikpanel_Proxy_Errors::classify( $resp, $open ) );
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
			Brikpanel_Sheets_Logger::log( 'oauth', 'Consent completed WITHOUT drive.file; granted scope: ' . $granted_scope );
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
	 * An error does not travel in the address bar: it is left as a one-time
	 * record for this user, and the page builds the box beside the Connect
	 * button from it, in the viewer's language (Brikpanel_Proxy_Errors).
	 *
	 * @param string $tone    success|error
	 * @param string $message A finished sentence; unused when $error is given.
	 * @param array  $error   [code, kind, detail] from Brikpanel_Proxy_Errors::classify().
	 */
	private function finish_with_notice( $tone, $message, array $error = [] ) {
		$args = [
			'page'                  => 'brikpanel-google-sheets',
			'brikpanel_oauth_flash' => $tone,
		];
		if ( $tone === 'error' ) {
			Brikpanel_Proxy_Errors::store_flash(
				self::FLASH_PREFIX,
				$error
					? [ 'code' => (string) $error[0], 'kind' => (string) $error[1], 'detail' => (string) $error[2] ]
					: [ 'message' => (string) $message ]
			);
		} else {
			$args['brikpanel_msg'] = rawurlencode( $message );
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
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
