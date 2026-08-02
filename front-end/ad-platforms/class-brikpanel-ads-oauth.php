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
	 *   - ads_read       : read campaign + ad insights (spend, impressions, clicks)
	 *                      AND list the connected user's ad accounts via
	 *                      /me/adaccounts. Per Meta docs ads_read alone returns
	 *                      every ad account the token can reach.
	 *   - email          : surface the connected user email.
	 *   - public_profile : required by Meta for any login flow.
	 *
	 * business_management was intentionally dropped: it is a read+write Business
	 * Manager scope, far broader than our read-only need, and it drew heavier App
	 * Review scrutiny. The rare case it covered (an ad account owned by a Business
	 * Manager the user has no direct role on, so it is absent from /me/adaccounts)
	 * is handled by the manual "enter ad account ID" fallback in the account card.
	 *
	 * ads_read requires App Review + Advanced Access for Production use. In
	 * Development mode (no review yet) it works for app Admins / Developers /
	 * Testers only.
	 */
	const SCOPES_GOOGLE = 'https://www.googleapis.com/auth/adwords openid email';
	const SCOPES_META   = 'ads_read,email,public_profile';

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

		// "Re-authorize" must be able to recover a permission the merchant
		// unticked on a previous consent screen. Meta will not re-ask for a
		// permission it has already recorded a decision about unless the
		// authorize call carries auth_type=rerequest, so pass the intent along
		// to the proxy. Older proxy builds ignore the extra field, which just
		// leaves today's behaviour unchanged.
		$reauth = ! empty( $_POST['reauth'] );

		$payload = [
			'platform'              => $platform,
			'return_url'            => $return_url,
			'state'                 => $state,
			'code_challenge'        => $challenge,
			'code_challenge_method' => 'S256',
			'site_url'              => home_url(),
			'scope'                 => $scope,
			'reauth'                => $reauth ? 1 : 0,
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
		Brikpanel_Ads_Tokens::clear_needs_reconnect( $platform );

		// The confirmation dialog promises "your synced spend data will be
		// deleted", and until now nothing ever deleted it. The leftover rows
		// stayed in wp_brikpanel_ad_spend and kept feeding the dashboard's Ad
		// Spend, ROAS and Net Profit cards for a platform the merchant had
		// explicitly disconnected, with no surface anywhere that showed where
		// the numbers were coming from. Honour the promise.
		//
		// Note this is scoped to an explicit, user-initiated disconnect. A
		// token that merely got revoked upstream keeps its history, because
		// past spend for past dates is still factual and deleting it would
		// silently rewrite months of closed-period profit figures.
		$deleted = Brikpanel_Ads_Store::delete_account( $platform );
		Brikpanel_Ads_Logger::log( 'oauth', 'Disconnected ' . $platform . '; removed ' . (int) $deleted . ' stored spend row(s).' );

		wp_send_json_success( [ 'message' => __( 'Disconnected.', 'brikpanel' ) ] );
	}

	// =========================================================================
	// Return handler (admin_init)
	// =========================================================================

	public function handle_return() {
		// The consent-denied branch matters as much as the success branch: when
		// the merchant clicks "Cancel" on the platform's consent screen the
		// proxy bounces back with `brikpanel_ads_oauth_error` + `state` and NO
		// handoff token. Requiring the handoff token here (which is what this
		// guard used to do) made the whole error branch below unreachable, so a
		// denied consent dropped the user back on the page with no toast, no
		// notice, and the error still hanging in the URL.
		$has_error  = isset( $_GET['brikpanel_ads_oauth_error'] );
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

		// Proxy-reported error short-circuit (e.g. user denied consent).
		if ( $has_error ) {
			$err = sanitize_text_field( wp_unslash( $_GET['brikpanel_ads_oauth_error'] ) );
			Brikpanel_Ads_Logger::log( 'oauth', 'Proxy reported error during consent: ' . $err );
			// Burn the pending state so a stale one cannot be replayed.
			delete_transient( self::STATE_TRANSIENT_PREFIX . hash( 'sha256', $state ) );
			$this->finish_with_notice( 'error', self::consent_error_message( $err ) );
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

		// Meta's token endpoint does not echo the granted scope back, so the
		// vault would record an empty string and `describe()['scope']` was
		// permanently blank for Meta. Fall back to what we asked for.
		$granted_scope = (string) ( $body['scope'] ?? '' );
		if ( $granted_scope === '' ) {
			$granted_scope = $platform === Brikpanel_Ads_Tokens::PLATFORM_GOOGLE
				? self::SCOPES_GOOGLE
				: self::SCOPES_META;
		}

		$ok = Brikpanel_Ads_Tokens::save( $platform, [
			'access_token'    => (string) $body['access_token'],
			'refresh_token'   => (string) ( $body['refresh_token'] ?? '' ),
			'expires_in'      => (int) ( $body['expires_in'] ?? 3600 ),
			'scope'           => $granted_scope,
			'token_type'      => (string) ( $body['token_type'] ?? 'Bearer' ),
			'connected_email' => (string) ( $body['email'] ?? '' ),
		] );

		if ( ! $ok ) {
			$this->finish_with_notice( 'error', __( 'Could not save tokens. Please try again.', 'brikpanel' ) );
		}

		// Successful reconnect clears any operator kill-switch latch and the
		// "reconnect required" banner raised by a failed token renewal.
		Brikpanel_Ads_Proxy::clear_killswitch();
		Brikpanel_Ads_Tokens::clear_needs_reconnect( $platform );

		// Mark this connection as needing an initial historical backfill on
		// the next scheduled sync. The sync orchestrator reads this flag,
		// resets it once it queues the backfill.
		update_option( 'brikpanel_ads_needs_backfill_' . $platform, 'yes', false );

		// One cheap probe so a Meta connect that silently lost `ads_read`
		// tells the merchant immediately instead of at the first sync.
		$permission_warning = self::verify_meta_permissions( $platform );
		if ( $permission_warning !== '' ) {
			$this->finish_with_notice( 'error', $permission_warning );
		}

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

	/**
	 * Translate the platform's OAuth error slug into a sentence the merchant
	 * can act on. Unknown slugs pass through so we never swallow a real cause.
	 *
	 * @param string $err
	 * @return string
	 */
	private static function consent_error_message( $err ) {
		switch ( strtolower( trim( (string) $err ) ) ) {
			case 'access_denied':
			case 'user_denied':
				return __( 'Connection cancelled: the permission request was declined on the platform’s consent screen. Nothing was saved. Click Connect to try again.', 'brikpanel' );
			case 'consent_required':
			case 'interaction_required':
				return __( 'The platform needs you to complete the consent screen. Click Connect to try again.', 'brikpanel' );
			case 'server_error':
			case 'temporarily_unavailable':
				return __( 'The advertising platform is temporarily unavailable. Please try connecting again in a few minutes.', 'brikpanel' );
		}
		if ( $err === '' ) {
			return __( 'The connection did not complete. Nothing was saved. Please try again.', 'brikpanel' );
		}
		/* translators: %s = raw error code reported by the advertising platform. */
		return sprintf( __( 'The connection did not complete (%s). Nothing was saved. Please try again.', 'brikpanel' ), $err );
	}

	/**
	 * Right after a Meta connect, confirm the token can actually read ad
	 * accounts.
	 *
	 * Meta's consent screen lets a user untick individual permissions, so it is
	 * entirely possible to finish the handshake with a perfectly valid token
	 * that has no `ads_read`. Without this probe the card would say "Connected"
	 * and every later action would fail with a developer-facing Graph string.
	 * Worse, Meta will not re-prompt for a permission the user already decided
	 * on unless the authorize call carries `auth_type=rerequest`, which is what
	 * the Re-authorize button now asks for.
	 *
	 * Only permission-shaped failures are reported; a transient network or rate
	 * limit error must not scare the merchant off a connection that is fine.
	 *
	 * @param string $platform
	 * @return string '' when healthy, otherwise a translated warning.
	 */
	private static function verify_meta_permissions( $platform ) {
		if ( $platform !== Brikpanel_Ads_Tokens::PLATFORM_META || ! class_exists( 'Brikpanel_Ads_Meta_Client' ) ) {
			return '';
		}
		try {
			( new Brikpanel_Ads_Meta_Client() )->list_accounts();
		} catch ( Brikpanel_Ads_Meta_Exception $e ) {
			if ( Brikpanel_Ads_Meta_Client::is_permission_error( $e->meta_code ) ) {
				Brikpanel_Ads_Logger::log( 'oauth', 'Meta connected without ads_read; prompting for re-authorisation.', $e->http_code );
				return __( 'Meta connected, but the advertising permission (ads_read) was not granted, so no spend can be imported. Click Re-authorize and leave the advertising permission enabled.', 'brikpanel' );
			}
		} catch ( \Throwable $e ) {
			// Not a permission problem — stay quiet.
			return '';
		}
		return '';
	}

	/** URL-safe Base64 without padding (RFC 4648 §5 / PKCE spec). */
	public static function base64url( $bin ) {
		return rtrim( strtr( base64_encode( $bin ), '+/', '-_' ), '=' );
	}
}
