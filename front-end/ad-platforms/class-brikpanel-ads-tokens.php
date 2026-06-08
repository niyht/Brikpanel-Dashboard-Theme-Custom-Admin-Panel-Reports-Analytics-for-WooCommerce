<?php
/**
 * BrikPanel — Ad Platforms multi-platform token vault.
 *
 * Single encrypted blob in wp_options holding OAuth credentials for both
 * Google Ads and Meta Ads, keyed by platform slug. Same encryption strategy as
 * the Google Sheets vault (sodium_crypto_secretbox or AES-256-GCM fallback,
 * HKDF-derived key bound to the site's WP salts).
 *
 * Why a single blob instead of one option per platform: atomic update,
 * consistent encryption envelope, single load/decrypt per request, and the
 * settings page only needs one DB read to render both connection cards.
 *
 * The refresh path is platform-aware because Google and Meta diverge:
 *   - Google uses a standard OAuth 2.0 refresh_token flow (long-lived
 *     refresh_token + short-lived access_token).
 *   - Meta has no refresh_token at all — instead, a long-lived user token
 *     (~60 days) is exchanged for another long-lived token via the
 *     fb_exchange_token grant before it expires.
 * Both flows are tunnelled through the WPCode proxy so the client_secret /
 * app_secret never live in the plugin code.
 *
 * @package BrikPanel
 * @since   3.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Brikpanel_Ads_Tokens {

	/** Option key holding the encrypted token blob. autoload=no. */
	const OPTION = 'brikpanel_ads_tokens';

	/** Refresh-skew seconds: refresh if a token expires sooner than this. */
	const REFRESH_SKEW = 90;

	/** HKDF info parameter — bump if the storage format ever changes. */
	const KDF_INFO = 'brikpanel-ads-v1';

	/** Recognised platforms. Any other value is a programming error. */
	const PLATFORM_GOOGLE = 'google_ads';
	const PLATFORM_META   = 'meta_ads';

	/**
	 * In-process plaintext cache. Cleared on demand. Never logged.
	 *
	 * Shape: [
	 *   'google_ads' => [
	 *     'access_token'      => string,
	 *     'refresh_token'     => string,  // Google only
	 *     'expires_at'        => int,
	 *     'scope'             => string,
	 *     'token_type'        => string,
	 *     'connected_email'   => string,
	 *     'connected_at'      => int,
	 *     'developer_token'   => string,  // Google only (stored at proxy, mirrored here for header)
	 *     'login_customer_id' => string,  // Google MCC ID, if connecting via manager
	 *     'primary_account'   => string,  // CID for Google, ad_account_id for Meta
	 *   ],
	 *   'meta_ads' => [ ...similar shape... ],
	 * ]
	 *
	 * @var array|null
	 */
	private static $cache = null;

	// =========================================================================
	// Public API
	// =========================================================================

	/**
	 * Whether a specific platform is currently connected.
	 *
	 * @param string $platform PLATFORM_GOOGLE | PLATFORM_META
	 */
	public static function is_connected( $platform ) {
		$tokens = self::load_platform( $platform );
		return is_array( $tokens ) && ! empty( $tokens['access_token'] );
	}

	/**
	 * Connection metadata for the settings page. Never includes raw tokens.
	 *
	 * @param string $platform
	 * @return array{connected:bool, email:string, scope:string, expires_at:int, connected_at:int, primary_account:string, login_customer_id:string}
	 */
	public static function describe( $platform ) {
		$tokens = self::load_platform( $platform );
		if ( ! $tokens ) {
			return [
				'connected'         => false,
				'email'             => '',
				'scope'             => '',
				'expires_at'        => 0,
				'connected_at'      => 0,
				'primary_account'   => '',
				'login_customer_id' => '',
			];
		}
		return [
			'connected'         => true,
			'email'             => (string) ( $tokens['connected_email'] ?? '' ),
			'scope'             => (string) ( $tokens['scope'] ?? '' ),
			'expires_at'        => (int) ( $tokens['expires_at'] ?? 0 ),
			'connected_at'      => (int) ( $tokens['connected_at'] ?? 0 ),
			'primary_account'   => (string) ( $tokens['primary_account'] ?? '' ),
			'login_customer_id' => (string) ( $tokens['login_customer_id'] ?? '' ),
		];
	}

	/**
	 * Convenience — describe both platforms in one call (used by settings AJAX
	 * status polling).
	 *
	 * @return array<string, array>
	 */
	public static function describe_all() {
		return [
			self::PLATFORM_GOOGLE => self::describe( self::PLATFORM_GOOGLE ),
			self::PLATFORM_META   => self::describe( self::PLATFORM_META ),
		];
	}

	/**
	 * Replace the stored token set for one platform.
	 *
	 * @param string $platform
	 * @param array $tokens {
	 *     @type string $access_token
	 *     @type string $refresh_token   Optional (Google only; never present for Meta).
	 *     @type int    $expires_in      Seconds until expiry (relative).
	 *     @type string $scope           Optional.
	 *     @type string $token_type      Optional.
	 *     @type string $connected_email Optional.
	 * }
	 * @return bool
	 */
	public static function save( $platform, array $tokens ) {
		if ( ! self::is_valid_platform( $platform ) ) {
			return false;
		}
		if ( empty( $tokens['access_token'] ) ) {
			return false;
		}

		$all      = self::load_all();
		$existing = isset( $all[ $platform ] ) && is_array( $all[ $platform ] ) ? $all[ $platform ] : [];

		// Preserve refresh_token across refreshes if upstream omits it (Google's
		// behaviour — refresh_token only ships on the initial grant unless
		// prompt=consent forces re-issue).
		if ( empty( $tokens['refresh_token'] ) && ! empty( $existing['refresh_token'] ) ) {
			$tokens['refresh_token'] = $existing['refresh_token'];
		}
		if ( empty( $tokens['connected_email'] ) && ! empty( $existing['connected_email'] ) ) {
			$tokens['connected_email'] = $existing['connected_email'];
		}
		// Preserve primary_account / login_customer_id across refresh — they
		// are set by the settings page, not by the OAuth response.
		if ( empty( $tokens['primary_account'] ) && ! empty( $existing['primary_account'] ) ) {
			$tokens['primary_account'] = $existing['primary_account'];
		}
		if ( empty( $tokens['login_customer_id'] ) && ! empty( $existing['login_customer_id'] ) ) {
			$tokens['login_customer_id'] = $existing['login_customer_id'];
		}
		if ( empty( $tokens['connected_at'] ) ) {
			$tokens['connected_at'] = (int) ( $existing['connected_at'] ?? time() );
		}

		$expires_in = isset( $tokens['expires_in'] ) ? (int) $tokens['expires_in'] : 3600;
		$tokens['expires_at'] = time() + max( 60, $expires_in );
		unset( $tokens['expires_in'] );

		$all[ $platform ] = [
			'access_token'      => (string) $tokens['access_token'],
			'refresh_token'     => (string) ( $tokens['refresh_token'] ?? '' ),
			'expires_at'        => (int) $tokens['expires_at'],
			'scope'             => (string) ( $tokens['scope'] ?? '' ),
			'token_type'        => (string) ( $tokens['token_type'] ?? 'Bearer' ),
			'connected_email'   => (string) ( $tokens['connected_email'] ?? '' ),
			'connected_at'      => (int) $tokens['connected_at'],
			'primary_account'   => (string) ( $tokens['primary_account'] ?? '' ),
			'login_customer_id' => (string) ( $tokens['login_customer_id'] ?? '' ),
		];

		return self::persist( $all );
	}

	/**
	 * Update a single non-secret metadata field (e.g. primary_account
	 * selection) without touching the access/refresh tokens.
	 *
	 * @param string $platform
	 * @param string $key   One of: primary_account, login_customer_id.
	 * @param string $value
	 * @return bool
	 */
	public static function set_meta( $platform, $key, $value ) {
		if ( ! self::is_valid_platform( $platform ) ) {
			return false;
		}
		$allowed = [ 'primary_account', 'login_customer_id' ];
		if ( ! in_array( $key, $allowed, true ) ) {
			return false;
		}
		$all = self::load_all();
		if ( ! isset( $all[ $platform ] ) || ! is_array( $all[ $platform ] ) ) {
			return false;
		}
		$all[ $platform ][ $key ] = (string) $value;
		return self::persist( $all );
	}

	/**
	 * Disconnect one platform (clears its tokens; leaves the other intact).
	 */
	public static function disconnect( $platform ) {
		if ( ! self::is_valid_platform( $platform ) ) {
			return;
		}
		$all = self::load_all();
		unset( $all[ $platform ] );
		if ( empty( $all ) ) {
			delete_option( self::OPTION );
			self::$cache = null;
			return;
		}
		self::persist( $all );
	}

	/** Wipe ALL connections. */
	public static function clear() {
		self::$cache = null;
		delete_option( self::OPTION );
	}

	/**
	 * Return a currently-valid access token, refreshing transparently if it
	 * is within REFRESH_SKEW seconds of expiry.
	 *
	 * @param string $platform
	 * @return string|null Null when not connected or refresh failed.
	 */
	public static function get_access_token( $platform ) {
		$tokens = self::load_platform( $platform );
		if ( ! $tokens || empty( $tokens['access_token'] ) ) {
			return null;
		}

		if ( ( (int) $tokens['expires_at'] - time() ) > self::REFRESH_SKEW ) {
			return (string) $tokens['access_token'];
		}

		$refreshed = self::refresh( $platform );
		if ( $refreshed === false ) {
			return null;
		}
		return (string) $refreshed['access_token'];
	}

	/**
	 * Force a refresh via the proxy. Dispatches to a platform-specific
	 * implementation because Google and Meta diverge:
	 *   - Google: refresh_token grant against /oauth/refresh.
	 *   - Meta: fb_exchange_token grant against /oauth/refresh — proxy
	 *     submits the current long-lived access_token and Meta returns a
	 *     fresh one with reset 60-day TTL.
	 *
	 * @param string $platform
	 * @return array|false New token payload or false on failure.
	 */
	public static function refresh( $platform ) {
		if ( ! self::is_valid_platform( $platform ) ) {
			return false;
		}
		$tokens = self::load_platform( $platform );
		if ( ! $tokens ) {
			Brikpanel_Ads_Logger::log( 'oauth', 'Refresh attempted with no stored tokens for ' . $platform );
			return false;
		}

		$body = [
			'platform' => $platform,
			'site_url' => home_url(),
		];

		if ( $platform === self::PLATFORM_GOOGLE ) {
			if ( empty( $tokens['refresh_token'] ) ) {
				Brikpanel_Ads_Logger::log( 'oauth', 'Google refresh with no refresh_token; user must reconnect.' );
				return false;
			}
			$body['refresh_token'] = (string) $tokens['refresh_token'];
		} else {
			// Meta uses fb_exchange_token — the "input" is the current long-lived
			// access token itself. The proxy enriches with app_id + app_secret.
			$body['access_token'] = (string) $tokens['access_token'];
		}

		$resp = wp_remote_post( BRIKPANEL_ADS_PROXY_BASE . '/oauth/refresh', [
			'timeout'   => 20,
			'sslverify' => true,
			'headers'   => [ 'Content-Type' => 'application/json', 'Accept' => 'application/json' ],
			'body'      => wp_json_encode( $body ),
		] );

		$open = Brikpanel_Ads_Proxy::open( $resp, $platform . ' token refresh' );
		if ( $open['wp_error'] ) {
			Brikpanel_Ads_Logger::log_request_error( 'oauth', $platform . ' token refresh', $resp );
			return false;
		}
		$code = (int) $open['code'];
		$body = $open['data'];
		if ( ! $open['ok'] || ! is_array( $body ) || empty( $body['access_token'] ) ) {
			Brikpanel_Ads_Logger::log_request_error( 'oauth', $platform . ' token refresh', $resp, $code );
			// Permanent revocation / invalid_grant — wipe so the UI shows
			// "Disconnected" cleanly and the user is prompted to reconnect.
			if ( $code === 400 || $code === 401 ) {
				$err = is_array( $body ) ? (string) ( $body['error'] ?? '' ) : '';
				if ( in_array( $err, [ 'invalid_grant', 'unauthorized_client', 'oauth_exception', 'token_revoked', 'token_expired' ], true ) ) {
					self::disconnect( $platform );
				}
			}
			return false;
		}

		$tokens['access_token'] = (string) $body['access_token'];
		$tokens['expires_at']   = time() + max( 60, (int) ( $body['expires_in'] ?? 3600 ) );
		if ( ! empty( $body['scope'] ) ) {
			$tokens['scope'] = (string) $body['scope'];
		}
		// Meta's fb_exchange_token does NOT issue a new refresh_token (there is
		// none), and Google's refresh response usually omits one too. Preserve
		// whatever we already had.

		$all = self::load_all();
		$all[ $platform ] = $tokens;
		if ( ! self::persist( $all ) ) {
			return false;
		}
		return $tokens;
	}

	/** Drop the in-process plaintext cache. */
	public static function flush_cache() {
		self::$cache = null;
	}

	// =========================================================================
	// Internal load + crypto
	// =========================================================================

	/**
	 * Load and decrypt the full multi-platform vault.
	 *
	 * @return array<string, array>
	 */
	private static function load_all() {
		if ( self::$cache !== null ) {
			return self::$cache;
		}
		$raw = (string) get_option( self::OPTION, '' );
		if ( $raw === '' ) {
			self::$cache = [];
			return [];
		}
		$plain = self::decrypt( $raw );
		if ( $plain === false ) {
			Brikpanel_Ads_Logger::log( 'oauth', 'Token decryption failed — corrupted blob, wiping.' );
			self::clear();
			return [];
		}
		$data = json_decode( $plain, true );
		if ( ! is_array( $data ) ) {
			self::clear();
			return [];
		}
		self::$cache = $data;
		return $data;
	}

	private static function load_platform( $platform ) {
		if ( ! self::is_valid_platform( $platform ) ) {
			return null;
		}
		$all = self::load_all();
		if ( ! isset( $all[ $platform ] ) || ! is_array( $all[ $platform ] ) ) {
			return null;
		}
		return $all[ $platform ];
	}

	private static function persist( array $all ) {
		$encrypted = self::encrypt( wp_json_encode( $all ) );
		if ( $encrypted === false ) {
			Brikpanel_Ads_Logger::log( 'oauth', 'Token encryption failed.' );
			return false;
		}
		$ok = update_option( self::OPTION, $encrypted, false );
		if ( $ok ) {
			self::$cache = $all;
		}
		return (bool) $ok;
	}

	private static function is_valid_platform( $platform ) {
		return $platform === self::PLATFORM_GOOGLE || $platform === self::PLATFORM_META;
	}

	/**
	 * Derive the symmetric encryption key from WordPress salts via HKDF.
	 *
	 * Returns 32 bytes of binary. Never logged.
	 */
	private static function derive_key() {
		$ikm = ( defined( 'AUTH_KEY' ) ? AUTH_KEY : '' )
			. ( defined( 'SECURE_AUTH_KEY' ) ? SECURE_AUTH_KEY : '' )
			. ( defined( 'LOGGED_IN_KEY' ) ? LOGGED_IN_KEY : '' );
		if ( $ikm === '' ) {
			$ikm = wp_salt( 'auth' ) . wp_salt( 'secure_auth' );
		}
		return hash_hkdf( 'sha256', $ikm, 32, self::KDF_INFO, site_url() );
	}

	/**
	 * Encrypt a plaintext string. Output format: "v1:" + base64(nonce . cipher).
	 *
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
				// fall through
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
	 * @return string|false
	 */
	private static function decrypt( $blob ) {
		$key = self::derive_key();

		if ( strncmp( $blob, 'v1:', 3 ) === 0 ) {
			if ( ! function_exists( 'sodium_crypto_secretbox_open' ) ) {
				return false;
			}
			$bin = base64_decode( substr( $blob, 3 ), true );
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
