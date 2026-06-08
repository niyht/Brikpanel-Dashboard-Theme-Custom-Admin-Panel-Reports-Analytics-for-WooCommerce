<?php
/**
 * BrikPanel — Ad Platforms OAuth handshake.
 *
 * Platform-aware sibling of Brikpanel_Sheets_OAuth. Drives the OAuth dance
 * for both Google Ads and Meta Marketing API through the same WPCode-hosted
 * proxy on brksoft.com. The platform slug travels with the state token so
 * the proxy and the plugin can route to the right authorize / redeem
 * endpoint without leaking secrets to the browser.
 *
 *   1. ajax_start() — plugin generates state + PKCE verifier, stashes them
 *      in a short-lived transient (tagged with the platform), POSTs to the
 *      proxy's /oauth/start with the requested platform.
 *   2. Browser visits the platform's consent page → proxy /oauth/callback →
 *      302 back to admin.php?page=brikpanel-ad-platforms&brikpanel_ads_oauth_return=<handoff>&state=<state>.
 *   3. handle_return() validates state, POSTs to /oauth/redeem with the
 *      handoff token + site_url + code_verifier, persists the returned
 *      tokens via Brikpanel_Ads_Tokens::save(), then redirects to a clean URL.
 *
 * @package BrikPanel
 * @since   3.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Brikpanel_Ads_OAuth {

	const STATE_TRANSIENT_PREFIX = 'bp_ads_state_';
	const STATE_TTL              = 600; // 10 minutes
	const NONCE_ACTION           = 'brikpanel_ads_nonce';
	const RETURN_PARAM           = 'brikpanel_ads_oauth_return';

	/**
	 * Scopes per platform.
	 *
	 * Google Ads:
	 *   - adwords     : the actual Ads API scope (yes, still named after the
	 *                   AdWords API even on the new Google Ads API).
	 *   - openid+email: surface the connected account email in the UI.
	 *
	 * Meta:
	 *   - ads_read            : read campaign + ad insights (spend, impressions, clicks).
	 *   - business_management : list ad accounts that live under a Business
	 *     Manager. Without this, /me/adaccounts only returns personal ad
	 *     accounts — most pro Meta advertisers organise their accounts under
	 *     a BM, so without business_management the picker is empty for them.
	 *   - email               : surface the connected user email.
	 *   - public_profile      : required by Meta for any login flow.
	 *
	 * Both ads_read and business_management require App Review + Advanced
	 * Access for Production use. In Development mode (no review yet), they
	 * work for app Admins / Developers / Testers only.
	 */
	const SCOPES_GOOGLE = 'https://www.googleapis.com/auth/adwords openid email';
	const SCOPES_META   = 'ads_read,business_management,email,public_profile';

	public function __construct() {
		add_action( 'wp_ajax_brikpanel_ads_oauth_start',      [ $this, 'ajax_start' ] );
		add_action( 'wp_ajax_brikpanel_ads_oauth_disconnect', [ $this, 'ajax_disconnect' ] );
		add_action( 'admin_init',                             [ $this, 'handle_return' ] );
	}

	// =========================================================================
	// AJAX — generate authorize URL
	// =========================================================================

	public function ajax_start() {
		check_ajax_referer( self::NONCE_ACTION, '_ajax_nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'brikpanel' ) ], 403 );
		}

		$platform = isset( $_POST['platform'] ) ? sanitize_key( wp_unslash( $_POST['platform'] ) ) : '';
		if ( ! in_array( $platform, [ Brikpanel_Ads_Tokens::PLATFORM_GOOGLE, Brikpanel_Ads_Tokens::PLATFORM_META ], true ) ) {
			wp_send_json_error( [ 'message' => __( 'Unknown platform.', 'brikpanel' ) ], 400 );
		}

		// Hard server-side gate: a locked platform (pending Google / Meta
		// approval) must never be able to start OAuth, even if the disabled
		// button is bypassed with a hand-crafted request.
		if ( function_exists( 'brikpanel_ads_platform_locked' ) && brikpanel_ads_platform_locked( $platform ) ) {
			wp_send_json_error( [ 'message' => __( 'This connection is not available yet, it is pending platform approval.', 'brikpanel' ) ], 403 );
		}

		$state     = bin2hex( random_bytes( 16 ) );
		$verifier  = self::base64url( random_bytes( 32 ) );
		$challenge = self::base64url( hash( 'sha256', $verifier, true ) );

		$return_url = admin_url( 'admin.php?page=brikpanel-ad-platforms' );
		$scope      = $platform === Brikpanel_Ads_Tokens::PLATFORM_GOOGLE
			? self::SCOPES_GOOGLE
			: self::SCOPES_META;

		set_transient(
			self::STATE_TRANSIENT_PREFIX . hash( 'sha256', $state ),
			[
				'platform'   => $platform,
				'verifier'   => $verifier,
				'return_url' => $return_url,
				'user_id'    => get_current_user_id(),
				'created_at' => time(),
			],
			self::STATE_TTL
		);

		$payload = [
			'platform'              => $platform,
			'return_url'            => $return_url,
			'state'                 => $state,
			'code_challenge'        => $challenge,
			'code_challenge_method' => 'S256',
			'site_url'              => home_url(),
			'scope'                 => $scope,
		];

		$resp = wp_remote_post( BRIKPANEL_ADS_PROXY_BASE . '/oauth/start', [
			'timeout'   => 20,
			'sslverify' => true,
			'headers'   => [ 'Content-Type' => 'application/json', 'Accept' => 'application/json' ],
			'body'      => wp_json_encode( $payload ),
		] );

		$open = Brikpanel_Ads_Proxy::open( $resp, 'oauth/start (' . $platform . ')' );
		if ( $open['wp_error'] ) {
			Brikpanel_Ads_Logger::log_request_error( 'oauth', 'oauth/start (' . $platform . ')', $resp );
			wp_send_json_error( [ 'message' => __( 'Could not reach the BrikPanel proxy. Please try again in a moment.', 'brikpanel' ) ], 502 );
		}
		$code = (int) $open['code'];
		$body = $open['data'];
		if ( ! $open['ok'] || empty( $body['authorize_url'] ) ) {
			Brikpanel_Ads_Logger::log_request_error( 'oauth', 'oauth/start (' . $platform . ')', $resp, $code );
			$message = ( $open['error'] === 'unsigned' || $open['error'] === 'bad_sig' || $open['error'] === 'stale' || $open['error'] === 'malformed' )
				? __( 'The BrikPanel proxy returned an unverifiable response and was rejected.', 'brikpanel' )
				: ( is_array( $body ) && ! empty( $body['message'] )
					? (string) $body['message']
					: __( 'Could not start OAuth: proxy returned an error.', 'brikpanel' ) );
			wp_send_json_error( [ 'message' => $message ], 502 );
		}

		wp_send_json_success( [ 'authorize_url' => (string) $body['authorize_url'] ] );
	}

	// =========================================================================
	// AJAX — disconnect a specific platform
	// =========================================================================

	public function ajax_disconnect() {
		check_ajax_referer( self::NONCE_ACTION, '_ajax_nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'brikpanel' ) ], 403 );
		}
		$platform = isset( $_POST['platform'] ) ? sanitize_key( wp_unslash( $_POST['platform'] ) ) : '';
		if ( ! in_array( $platform, [ Brikpanel_Ads_Tokens::PLATFORM_GOOGLE, Brikpanel_Ads_Tokens::PLATFORM_META ], true ) ) {
			wp_send_json_error( [ 'message' => __( 'Unknown platform.', 'brikpanel' ) ], 400 );
		}

		// Best-effort proxy revoke (does not need to succeed for local cleanup).
		if ( Brikpanel_Ads_Tokens::is_connected( $platform ) ) {
			wp_remote_post( BRIKPANEL_ADS_PROXY_BASE . '/oauth/revoke', [
				'timeout'   => 8,
				'sslverify' => true,
				'headers'   => [ 'Content-Type' => 'application/json' ],
				'body'      => wp_json_encode( [
					'platform' => $platform,
					'site_url' => home_url(),
				] ),
			] );
		}

		Brikpanel_Ads_Tokens::disconnect( $platform );
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

		// Proxy-reported error short-circuit (e.g. user denied consent).
		if ( isset( $_GET['brikpanel_ads_oauth_error'] ) ) {
			$err = sanitize_text_field( wp_unslash( $_GET['brikpanel_ads_oauth_error'] ) );
			Brikpanel_Ads_Logger::log( 'oauth', 'Proxy reported error during consent: ' . $err );
			$this->finish_with_notice( 'error', $err );
		}

		$trans_key = self::STATE_TRANSIENT_PREFIX . hash( 'sha256', $state );
		$stash     = get_transient( $trans_key );
		if ( ! is_array( $stash ) || empty( $stash['verifier'] ) || empty( $stash['platform'] ) ) {
			Brikpanel_Ads_Logger::log( 'oauth', 'OAuth return with unknown / expired state.' );
			$this->finish_with_notice( 'error', __( 'OAuth session expired. Please try connecting again.', 'brikpanel' ) );
		}
		// Single-use state.
		delete_transient( $trans_key );

		$platform = (string) $stash['platform'];
		if ( ! in_array( $platform, [ Brikpanel_Ads_Tokens::PLATFORM_GOOGLE, Brikpanel_Ads_Tokens::PLATFORM_META ], true ) ) {
			$this->finish_with_notice( 'error', __( 'Unknown platform in OAuth state.', 'brikpanel' ) );
		}

		// Refuse to redeem / persist tokens for a platform that got locked
		// (e.g. unlock was reverted mid-flow). Mirrors the ajax_start gate.
		if ( function_exists( 'brikpanel_ads_platform_locked' ) && brikpanel_ads_platform_locked( $platform ) ) {
			$this->finish_with_notice( 'error', __( 'This connection is not available yet, it is pending platform approval.', 'brikpanel' ) );
		}

		// User identity binding — refuse to apply tokens for a different WP user.
		if ( (int) ( $stash['user_id'] ?? 0 ) !== get_current_user_id() ) {
			Brikpanel_Ads_Logger::log( 'oauth', 'OAuth return user mismatch.' );
			$this->finish_with_notice( 'error', __( 'OAuth callback was for a different user. Aborted.', 'brikpanel' ) );
		}

		$resp = wp_remote_post( BRIKPANEL_ADS_PROXY_BASE . '/oauth/redeem', [
			'timeout'   => 20,
			'sslverify' => true,
			'headers'   => [ 'Content-Type' => 'application/json', 'Accept' => 'application/json' ],
			'body'      => wp_json_encode( [
				'platform'      => $platform,
				'handoff_token' => $handoff,
				'site_url'      => home_url(),
				'code_verifier' => $stash['verifier'],
			] ),
		] );

		$open = Brikpanel_Ads_Proxy::open( $resp, 'oauth/redeem' );
		if ( $open['wp_error'] ) {
			Brikpanel_Ads_Logger::log_request_error( 'oauth', 'oauth/redeem', $resp );
			$this->finish_with_notice( 'error', __( 'Could not reach the BrikPanel proxy.', 'brikpanel' ) );
		}
		$code = (int) $open['code'];
		$body = $open['data'];
		if ( ! $open['ok'] || empty( $body['access_token'] ) ) {
			Brikpanel_Ads_Logger::log_request_error( 'oauth', 'oauth/redeem', $resp, $code );
			if ( in_array( $open['error'], [ 'unsigned', 'bad_sig', 'stale', 'malformed' ], true ) ) {
				$this->finish_with_notice( 'error', __( 'The BrikPanel proxy returned an unverifiable response and was rejected.', 'brikpanel' ) );
			}
			$this->finish_with_notice( 'error', __( 'OAuth redemption failed. Please try connecting again.', 'brikpanel' ) );
		}

		$ok = Brikpanel_Ads_Tokens::save( $platform, [
			'access_token'    => (string) $body['access_token'],
			'refresh_token'   => (string) ( $body['refresh_token'] ?? '' ),
			'expires_in'      => (int) ( $body['expires_in'] ?? 3600 ),
			'scope'           => (string) ( $body['scope'] ?? '' ),
			'token_type'      => (string) ( $body['token_type'] ?? 'Bearer' ),
			'connected_email' => (string) ( $body['email'] ?? '' ),
		] );

		if ( ! $ok ) {
			$this->finish_with_notice( 'error', __( 'Could not save tokens. Please try again.', 'brikpanel' ) );
		}

		// Successful reconnect clears any operator kill-switch latch.
		Brikpanel_Ads_Proxy::clear_killswitch();

		// Mark this connection as needing an initial historical backfill on
		// the next scheduled sync. The sync orchestrator reads this flag,
		// resets it once it queues the backfill.
		update_option( 'brikpanel_ads_needs_backfill_' . $platform, 'yes', false );

		$label = $platform === Brikpanel_Ads_Tokens::PLATFORM_GOOGLE
			? __( 'Google Ads connected.', 'brikpanel' )
			: __( 'Meta Ads connected.', 'brikpanel' );

		$this->finish_with_notice( 'success', $label );
	}

	// =========================================================================
	// Helpers
	// =========================================================================

	private function finish_with_notice( $tone, $message ) {
		$url = add_query_arg(
			[
				'page'                   => 'brikpanel-ad-platforms',
				'brikpanel_ads_flash'    => $tone,
				'brikpanel_msg'          => rawurlencode( $message ),
			],
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $url );
		exit;
	}

	/** URL-safe Base64 without padding (RFC 4648 §5 / PKCE spec). */
	public static function base64url( $bin ) {
		return rtrim( strtr( base64_encode( $bin ), '+/', '-_' ), '=' );
	}
}
