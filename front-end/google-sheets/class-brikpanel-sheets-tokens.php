<?php
/**
 * BrikPanel — Sheets Token vault (encrypted OAuth credentials).
 *
 * Token storage strategy:
 *  - Tokens are persisted in a single wp_options row (autoload=no), payload
 *    is JSON-encoded then encrypted with sodium_crypto_secretbox (or AES-256-GCM
 *    fallback). The encryption key is derived from AUTH_KEY + SECURE_AUTH_KEY
 *    via hash_hkdf, so it never has to be configured by the user but is unique
 *    per-site and unpredictable to outside code.
 *  - The plaintext cache is held only as private static $cache and is cleared
 *    on demand via flush_cache().
 *  - get_access_token() lazily refreshes the access token (via the brksoft.com
 *    proxy /oauth/refresh endpoint) when it would expire within REFRESH_SKEW
 *    seconds. This is the single entry point all API callers use; clients
 *    do not see refresh tokens.
 *
 * @package BrikPanel
 * @since   2.9.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Brikpanel_Sheets_Tokens {

	/** Option key holding the encrypted token blob. autoload=no. */
	const OPTION = 'brikpanel_gs_tokens';

	/** Refresh-skew seconds: refresh if access token expires sooner than this. */
	const REFRESH_SKEW = 90;

	/** HKDF info parameter — bump if the storage format ever changes. */
	const KDF_INFO = 'brikpanel-gs-v1';

	/**
	 * The one scope the whole integration depends on. Google presents this as
	 * an *optional* checkbox on the granular consent screen, so a user can
	 * complete OAuth (valid token, known email) while declining it. Every
	 * Sheets/Drive/Picker call then 403s. We must verify it was actually
	 * granted rather than trust that "connected" implies "usable".
	 */
	const REQUIRED_SCOPE = 'auth/drive.file';

	/**
	 * In-process plaintext cache. Cleared on demand. Never logged.
	 *
	 * Shape: [
	 *   'access_token'  => string,
	 *   'refresh_token' => string,
	 *   'expires_at'    => int (unix timestamp),
	 *   'scope'         => string,
	 *   'token_type'    => string,
	 *   'connected_email' => string,
	 *   'connected_at'  => int,
	 * ]
	 *
	 * @var array|null
	 */
	private static $cache = null;

	// =========================================================================
	// Public API
	// =========================================================================

	/**
	 * Whether any token is currently stored.
	 */
	public static function is_connected() {
		$raw = get_option( self::OPTION, '' );
		return is_string( $raw ) && $raw !== '';
	}

	/**
	 * Get the connection metadata shown on the settings page. Does NOT return
	 * any token value.
	 *
	 * @return array{connected:bool, email:string, scope:string, expires_at:int, connected_at:int}
	 */
	public static function describe() {
		$tokens = self::load();
		if ( ! $tokens ) {
			return [
				'connected'    => false,
				'email'        => '',
				'scope'        => '',
				'expires_at'   => 0,
				'connected_at' => 0,
			];
		}
		return [
			'connected'    => true,
			'email'        => (string) ( $tokens['connected_email'] ?? '' ),
			'scope'        => (string) ( $tokens['scope'] ?? '' ),
			'expires_at'   => (int) ( $tokens['expires_at'] ?? 0 ),
			'connected_at' => (int) ( $tokens['connected_at'] ?? 0 ),
		];
	}

	/**
	 * Whether a granted-scope string actually carries the drive.file scope.
	 *
	 * Google returns granted scopes space-delimited and as full URLs (e.g.
	 * "openid https://www.googleapis.com/auth/userinfo.email
	 * https://www.googleapis.com/auth/drive.file"), so a substring test on the
	 * stable tail "auth/drive.file" is the robust check. Tolerates the
	 * (rarely seen) broader auth/drive scope as a superset.
	 *
	 * @param string $scope
	 * @return bool
	 */
	public static function scope_has_drive( $scope ) {
		$scope = (string) $scope;
		if ( $scope === '' ) {
			return false;
		}
		foreach ( preg_split( '/\s+/', trim( $scope ) ) as $s ) {
			if ( $s === self::REQUIRED_SCOPE
				|| substr( $s, -strlen( self::REQUIRED_SCOPE ) ) === self::REQUIRED_SCOPE
				|| substr( $s, -10 ) === 'auth/drive' ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Resolve the authoritative granted-scope string for an access token.
	 *
	 * The redeem/refresh proxy response *should* echo Google's `scope`, but we
	 * must not hard-depend on a third party for a security gate: if the hint is
	 * empty we ask Google directly via the public tokeninfo endpoint (no
	 * secret, no proxy). Returns '' only when truly indeterminate (network
	 * failure with no hint) so callers can choose to fail open rather than
	 * lock every user out.
	 *
	 * @param string $access_token
	 * @param string $hint Scope string already returned by the proxy, if any.
	 * @return string Space-delimited scope list, or '' if undeterminable.
	 */
	public static function resolve_granted_scope( $access_token, $hint = '' ) {
		$hint = trim( (string) $hint );
		if ( $hint !== '' ) {
			return $hint;
		}
		if ( (string) $access_token === '' ) {
			return '';
		}
		$resp = wp_remote_get(
			'https://oauth2.googleapis.com/tokeninfo?access_token=' . rawurlencode( (string) $access_token ),
			[ 'timeout' => 10, 'sslverify' => true, 'headers' => [ 'Accept' => 'application/json' ] ]
		);
		if ( is_wp_error( $resp ) || (int) wp_remote_retrieve_response_code( $resp ) !== 200 ) {
			return '';
		}
		$data = json_decode( (string) wp_remote_retrieve_body( $resp ), true );
		return is_array( $data ) ? (string) ( $data['scope'] ?? '' ) : '';
	}

	/**
	 * Whether the *currently stored* connection was actually granted the
	 * drive.file scope. False only when we can positively determine the scope
	 * is missing it (the declined-on-consent case). Self-heals connections
	 * stored before this check existed (empty scope) by asking Google once and
	 * persisting the answer, and fails OPEN on a truly indeterminate result so
	 * a network blip never blocks an account that did grant access.
	 *
	 * @return bool
	 */
	public static function has_drive_scope() {
		$tokens = self::load();
		if ( ! $tokens || empty( $tokens['access_token'] ) ) {
			return false;
		}
		$scope = trim( (string) ( $tokens['scope'] ?? '' ) );
		if ( $scope === '' ) {
			// Legacy / proxy-omitted scope: resolve authoritatively, then
			// persist so every later check is a cheap string test.
			$scope = self::resolve_granted_scope( (string) $tokens['access_token'], '' );
			if ( $scope === '' ) {
				return true; // indeterminate — fail open.
			}
			$tokens['scope'] = $scope;
			$encrypted = self::encrypt( wp_json_encode( $tokens ) );
			if ( $encrypted !== false ) {
				update_option( self::OPTION, $encrypted, false );
				self::$cache = $tokens;
			}
		}
		return self::scope_has_drive( $scope );
	}

	/**
	 * Replace the stored token set. Called by the OAuth handler after a
	 * successful redeem or after refreshing.
	 *
	 * @param array $tokens {
	 *     @type string $access_token
	 *     @type string $refresh_token  Optional on refresh (Google may re-issue or not).
	 *     @type int    $expires_in     Seconds until expiry (relative).
	 *     @type string $scope          Optional.
	 *     @type string $token_type     Optional (defaults Bearer).
	 *     @type string $connected_email Optional.
	 * }
	 * @return bool
	 */
	public static function save( array $tokens ) {
		if ( empty( $tokens['access_token'] ) ) {
			return false;
		}

		// Preserve refresh_token across refreshes if Google omits it.
		$existing = self::load();
		if ( empty( $tokens['refresh_token'] ) && ! empty( $existing['refresh_token'] ) ) {
			$tokens['refresh_token'] = $existing['refresh_token'];
		}
		if ( empty( $tokens['connected_email'] ) && ! empty( $existing['connected_email'] ) ) {
			$tokens['connected_email'] = $existing['connected_email'];
		}
		if ( empty( $tokens['connected_at'] ) ) {
			$tokens['connected_at'] = (int) ( $existing['connected_at'] ?? time() );
		}

		$expires_in = isset( $tokens['expires_in'] ) ? (int) $tokens['expires_in'] : 3600;
		$tokens['expires_at'] = time() + max( 60, $expires_in );
		unset( $tokens['expires_in'] );

		$payload = [
			'access_token'    => (string) $tokens['access_token'],
			'refresh_token'   => (string) ( $tokens['refresh_token'] ?? '' ),
			'expires_at'      => (int) $tokens['expires_at'],
			'scope'           => (string) ( $tokens['scope'] ?? '' ),
			'token_type'      => (string) ( $tokens['token_type'] ?? 'Bearer' ),
			'connected_email' => (string) ( $tokens['connected_email'] ?? '' ),
			'connected_at'    => (int) $tokens['connected_at'],
		];

		$encrypted = self::encrypt( wp_json_encode( $payload ) );
		if ( $encrypted === false ) {
			Brikpanel_Sheets_Logger::log( 'oauth', 'Token encryption failed.' );
			return false;
		}

		$ok = update_option( self::OPTION, $encrypted, false );
		if ( $ok ) {
			self::$cache = $payload;
		}
		return (bool) $ok;
	}

	/**
	 * Delete the stored tokens.
	 */
	public static function clear() {
		self::$cache = null;
		delete_option( self::OPTION );
	}

	/**
	 * Return a currently-valid access token, refreshing transparently if it
	 * is within REFRESH_SKEW seconds of expiry.
	 *
	 * @return string|null Null on failure (no tokens stored, or refresh failed).
	 */
	public static function get_access_token() {
		if ( class_exists( 'Brikpanel_Sheets_Proxy' ) && Brikpanel_Sheets_Proxy::is_killed() ) {
			return null;
		}

		$tokens = self::load();
		if ( ! $tokens || empty( $tokens['access_token'] ) ) {
			return null;
		}

		if ( ( (int) $tokens['expires_at'] - time() ) > self::REFRESH_SKEW ) {
			return (string) $tokens['access_token'];
		}

		$refreshed = self::refresh();
		if ( $refreshed === false ) {
			return null;
		}
		return (string) $refreshed['access_token'];
	}

	/**
	 * Force a refresh via the brksoft.com proxy. Updates storage and cache.
	 *
	 * @return array|false New token payload or false on failure.
	 */
	public static function refresh() {
		$tokens = self::load();
		if ( ! $tokens || empty( $tokens['refresh_token'] ) ) {
			Brikpanel_Sheets_Logger::log( 'oauth', 'Refresh attempted with no refresh_token.' );
			return false;
		}

		$resp = wp_remote_post( BRIKPANEL_GS_PROXY_BASE . '/oauth/refresh', [
			'timeout'   => 20,
			'sslverify' => true,
			'headers'   => [ 'Content-Type' => 'application/json', 'Accept' => 'application/json' ],
			'body'      => wp_json_encode( [
				'refresh_token' => $tokens['refresh_token'],
				'site_url'      => home_url(),
			] ),
		] );

		$open = Brikpanel_Sheets_Proxy::open( $resp, 'token refresh' );
		if ( $open['wp_error'] ) {
			Brikpanel_Sheets_Logger::log_request_error( 'oauth', 'token refresh', $resp );
			return false;
		}
		$code = (int) $open['code'];
		$body = $open['data'];
		if ( ! $open['ok'] || ! is_array( $body ) || empty( $body['access_token'] ) ) {
			Brikpanel_Sheets_Logger::log_request_error( 'oauth', 'token refresh', $resp, $code );
			// 400 invalid_grant means the user revoked the grant — wipe tokens so
			// the UI surfaces "Disconnected" cleanly on the next render.
			if ( $code === 400 || $code === 401 ) {
				$err = is_array( $body ) ? (string) ( $body['error'] ?? '' ) : '';
				if ( $err === 'invalid_grant' || $err === 'unauthorized_client' ) {
					self::clear();
				}
			}
			return false;
		}

		// Merge: keep refresh_token + connected_email if proxy omits.
		$tokens['access_token'] = (string) $body['access_token'];
		$tokens['expires_at']   = time() + max( 60, (int) ( $body['expires_in'] ?? 3600 ) );
		if ( ! empty( $body['scope'] ) ) {
			$tokens['scope'] = (string) $body['scope'];
		}

		$encrypted = self::encrypt( wp_json_encode( $tokens ) );
		if ( $encrypted === false ) {
			return false;
		}
		update_option( self::OPTION, $encrypted, false );
		self::$cache = $tokens;
		return $tokens;
	}

	/**
	 * Drop the in-process plaintext cache. Called from __destruct hooks and
	 * after admin-disconnect actions.
	 */
	public static function flush_cache() {
		self::$cache = null;
	}

	// =========================================================================
	// Internal load + crypto
	// =========================================================================

	/**
	 * Load and decrypt the stored tokens. Cached for the rest of the request.
	 *
	 * @return array|null
	 */
	private static function load() {
		if ( self::$cache !== null ) {
			return self::$cache;
		}
		$raw = (string) get_option( self::OPTION, '' );
		if ( $raw === '' ) {
			return null;
		}
		$plain = self::decrypt( $raw );
		if ( $plain === false ) {
			Brikpanel_Sheets_Logger::log( 'oauth', 'Token decryption failed — corrupted blob, wiping.' );
			self::clear();
			return null;
		}
		$data = json_decode( $plain, true );
		if ( ! is_array( $data ) ) {
			self::clear();
			return null;
		}
		self::$cache = $data;
		return $data;
	}

	/**
	 * Derive the symmetric encryption key from WordPress salts via HKDF.
	 *
	 * Returns 32 bytes of binary. Never logged.
	 *
	 * @return string
	 */
	private static function derive_key() {
		$ikm = ( defined( 'AUTH_KEY' ) ? AUTH_KEY : '' )
			. ( defined( 'SECURE_AUTH_KEY' ) ? SECURE_AUTH_KEY : '' )
			. ( defined( 'LOGGED_IN_KEY' ) ? LOGGED_IN_KEY : '' );
		if ( $ikm === '' ) {
			// Absolutely no salts defined. Fall back to wp_salt() so the plugin
			// still works on misconfigured installs — wp_salt persists a
			// random value in wp_options the first time it runs.
			$ikm = wp_salt( 'auth' ) . wp_salt( 'secure_auth' );
		}
		// PHP 7.1+: hash_hkdf is core.
		return hash_hkdf( 'sha256', $ikm, 32, self::KDF_INFO, site_url() );
	}

	/**
	 * Encrypt a plaintext string. Output format: "v1:" + base64(nonce . cipher).
	 * Uses sodium_crypto_secretbox when available, falls back to AES-256-GCM.
	 *
	 * @param string $plaintext
	 * @return string|false
	 */
	private static function encrypt( $plaintext ) {
		$key = self::derive_key();

		if ( function_exists( 'sodium_crypto_secretbox' ) ) {
			try {
				$nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
				$cipher = sodium_crypto_secretbox( (string) $plaintext, $nonce, $key );
				return 'v1:' . base64_encode( $nonce . $cipher );
			} catch ( \Throwable $e ) {
				// Fall through to OpenSSL.
			}
		}

		if ( function_exists( 'openssl_encrypt' ) ) {
			$iv     = random_bytes( 12 );
			$tag    = '';
			$cipher = openssl_encrypt( (string) $plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16 );
			if ( $cipher === false ) {
				return false;
			}
			return 'v2:' . base64_encode( $iv . $tag . $cipher );
		}

		return false;
	}

	/**
	 * Decrypt a blob produced by encrypt().
	 *
	 * @param string $blob
	 * @return string|false
	 */
	private static function decrypt( $blob ) {
		$key = self::derive_key();

		if ( strncmp( $blob, 'v1:', 3 ) === 0 ) {
			if ( ! function_exists( 'sodium_crypto_secretbox_open' ) ) {
				return false;
			}
			$bin   = base64_decode( substr( $blob, 3 ), true );
			if ( $bin === false || strlen( $bin ) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + 1 ) {
				return false;
			}
			$nonce  = substr( $bin, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$cipher = substr( $bin, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$plain  = sodium_crypto_secretbox_open( $cipher, $nonce, $key );
			return $plain === false ? false : (string) $plain;
		}

		if ( strncmp( $blob, 'v2:', 3 ) === 0 ) {
			if ( ! function_exists( 'openssl_decrypt' ) ) {
				return false;
			}
			$bin = base64_decode( substr( $blob, 3 ), true );
			if ( $bin === false || strlen( $bin ) < 12 + 16 + 1 ) {
				return false;
			}
			$iv     = substr( $bin, 0, 12 );
			$tag    = substr( $bin, 12, 16 );
			$cipher = substr( $bin, 28 );
			$plain  = openssl_decrypt( $cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );
			return $plain === false ? false : (string) $plain;
		}

		return false;
	}
}
