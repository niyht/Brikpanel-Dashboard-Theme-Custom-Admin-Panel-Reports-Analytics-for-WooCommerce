<?php
/**
 * BrikPanel — Sheets Token vault (encrypted OAuth credentials).
 *
 * Token storage strategy:
 *  - Tokens are persisted in a single wp_options row (autoload=no), payload
 *    is JSON-encoded then encrypted. The encryption envelope is shared with the
 *    Ad Platforms vault: see Brikpanel_Secret_Vault. The key is derived from the
 *    site's own salts, so it never has to be configured by the user but is
 *    unique per-site and unpredictable to outside code.
 *  - The plaintext cache is held only as private static $cache and is cleared
 *    on demand via flush_cache().
 *  - get_access_token() lazily refreshes the access token (via the brksoft.com
 *    proxy /oauth/refresh endpoint) when it would expire within REFRESH_SKEW
 *    seconds. This is the single entry point all API callers use; clients
 *    do not see refresh tokens.
 *
 * READ FAILURES NEVER DELETE
 * --------------------------
 * Until 3.3.14 a decrypt failure was treated as corruption and the option was
 * deleted. It was virtually never corruption: the key was salted with
 * site_url(), which is a per-request value, so a connection made in an HTTPS
 * browser request was unreadable from the WP-Cron loopback and the plugin
 * destroyed it. The vault is therefore tri-state — empty / readable /
 * unreadable — a read failure is a read failure, write paths refuse to persist
 * on top of something they could not read, and an explicit reconnect parks the
 * old ciphertext in QUARANTINE_OPTION rather than dropping it.
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

	/**
	 * HKDF info parameter.
	 *
	 * v2 means the key is no longer salted with the request's site_url(). See
	 * Brikpanel_Secret_Vault for why that salt destroyed connections. The v1
	 * value below is kept solely to read blobs written before 3.3.14; every
	 * such blob is re-encrypted under v2 the first time it is opened.
	 */
	const KDF_INFO        = 'brikpanel-gs-v2';
	const KDF_INFO_LEGACY = 'brikpanel-gs-v1';

	/**
	 * Ciphertext we could not open, parked instead of deleted.
	 *
	 * See Brikpanel_Ads_Tokens::QUARANTINE_OPTION — same reasoning, same
	 * guarantees: autoload=no, no plaintext, nobody on the site can open it.
	 */
	const QUARANTINE_OPTION = 'brikpanel_gs_tokens_unreadable';

	/**
	 * Throttle stamp for the "could not decrypt" log line, so an unreadable
	 * vault cannot evict the whole ring buffer.
	 * Shape: [ 'sig' => <hash of blob+reason>, 'at' => <unix ts> ].
	 */
	const ALERT_OPTION = 'brikpanel_gs_vault_alert';

	/** How long the same unreadable blob stays quiet after being reported. */
	const ALERT_TTL = 6 * HOUR_IN_SECONDS;

	/** Vault states. See $state. */
	const STATE_EMPTY      = 'empty';
	const STATE_OK         = 'ok';
	const STATE_UNREADABLE = 'unreadable';

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

	/**
	 * Whether load() has run this request. Separate from $cache because an
	 * unreadable vault must stay distinguishable from an empty one.
	 *
	 * @var bool
	 */
	private static $loaded = false;

	/**
	 * STATE_EMPTY | STATE_OK | STATE_UNREADABLE for the cached load.
	 *
	 * @var string
	 */
	private static $state = self::STATE_EMPTY;

	/**
	 * True while load_fresh() is running, i.e. a write is about to follow.
	 * Suppresses the lazy re-encrypt; persist() handles it for free.
	 *
	 * @var bool
	 */
	private static $in_fresh_read = false;

	/**
	 * One "could not decrypt" report per request, at most.
	 *
	 * @var bool
	 */
	private static $reported = false;

	// =========================================================================
	// Public API
	// =========================================================================

	/**
	 * Whether there is a connection this request can actually use.
	 *
	 * The cheap half is unchanged: on a site that has never connected, the raw
	 * option is empty and this answers without decrypting anything. That
	 * matters because it runs on `init` of every request, storefront included,
	 * through the sync modules' maybe_attach_hooks().
	 *
	 * The second half exists because "a row is present" stopped being the same
	 * as "connected" once an unreadable vault survives instead of being
	 * deleted. Every background job is gated on this, and answering true for
	 * credentials we cannot open would send each one off to fail on a null
	 * token and mark itself failed. Standing down quietly is the honest
	 * behaviour, and the settings card is what tells the merchant why.
	 */
	public static function is_connected() {
		$raw = get_option( self::OPTION, '' );
		if ( ! is_string( $raw ) || $raw === '' ) {
			return false;
		}
		return self::load() !== null;
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
			self::persist( $tokens );
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
		$existing = self::load_fresh();

		// An explicit reconnect is the one path allowed to overwrite a vault we
		// cannot open. Park the old ciphertext first, and inherit nothing from
		// it — load() returned null, so $existing is already empty.
		$replace_unreadable = ( self::$state === self::STATE_UNREADABLE );
		if ( $replace_unreadable ) {
			self::quarantine( (string) get_option( self::OPTION, '' ) );
			Brikpanel_Sheets_Logger::log(
				'oauth',
				'An unreadable credential vault was replaced by this new connection.',
				0,
				[ 'reason' => 'vault_replaced' ]
			);
			$existing = null;
		}

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

		return self::persist( $payload, $replace_unreadable );
	}

	/**
	 * Delete the stored tokens.
	 *
	 * Only ever called for a deliberate removal: a merchant disconnect, a
	 * consent completed without the required scope, a revoked grant, or a
	 * signed operator kill-switch. It is NOT reachable from a read path any
	 * more — that was the bug.
	 */
	public static function clear() {
		self::$cache  = null;
		self::$loaded = false;
		self::$state  = self::STATE_EMPTY;
		delete_option( self::OPTION );
		delete_option( self::QUARANTINE_OPTION );
		delete_option( self::ALERT_OPTION );
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
	 * Transient set when a refresh failed for a reason on OUR side (proxy down,
	 * 5xx, unreachable) rather than a revoked grant. Short-lived on purpose: it
	 * only has to outlive the request that shows the message.
	 */
	const OUTAGE_FLAG = 'brikpanel_gs_refresh_outage';

	/**
	 * Record that token refresh failed because the BrikPanel proxy was
	 * unavailable, so the UI can tell "try again shortly" apart from
	 * "reconnect your Google account".
	 *
	 * @return void
	 */
	private static function flag_service_outage() {
		set_transient( self::OUTAGE_FLAG, 1, 15 * MINUTE_IN_SECONDS );
	}

	/**
	 * Whether the most recent refresh failure was a BrikPanel-side outage.
	 *
	 * @return bool
	 */
	public static function had_service_outage() {
		return (bool) get_transient( self::OUTAGE_FLAG );
	}

	/**
	 * Force a refresh via the brksoft.com proxy. Updates storage and cache.
	 *
	 * @return array|false New token payload or false on failure.
	 */
	public static function refresh() {
		// Read past this process's cache: a long-lived worker can hold a
		// snapshot minutes old, and spending a superseded refresh_token gets
		// invalid_grant back — which the branch below answers by wiping the
		// connection the merchant may have just re-made.
		$tokens = self::load_fresh();
		if ( ! $tokens || empty( $tokens['refresh_token'] ) ) {
			if ( self::$state === self::STATE_UNREADABLE ) {
				// Do not spend a credential we could not read, and above all do
				// not let the resulting failure wipe the vault.
				return false;
			}
			Brikpanel_Sheets_Logger::log( 'oauth', 'Refresh attempted with no refresh_token.' );
			return false;
		}

		// Fingerprint the credential this attempt spends, so invalid_grant can
		// be attributed to it rather than to whatever is stored by the time the
		// answer comes back.
		$sent_fp = hash( 'sha256', (string) $tokens['refresh_token'] );

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
			self::flag_service_outage();
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
					// Only if the token we spent is still the stored one. The
					// merchant can reconnect while this call is in flight, and
					// wiping here would delete the connection they just made.
					$now = self::load_fresh();
					if ( ! is_array( $now ) || empty( $now['refresh_token'] ) ) {
						return false; // already gone, or unreadable; leave it
					}
					if ( ! hash_equals( $sent_fp, hash( 'sha256', (string) $now['refresh_token'] ) ) ) {
						Brikpanel_Sheets_Logger::log(
							'oauth',
							'Refresh failed on a credential that is no longer stored; the newer connection was left alone.',
							$code,
							[ 'reason' => 'superseded_credential' ]
						);
						return false;
					}
					self::clear();
					delete_transient( self::OUTAGE_FLAG );
					return false;
				}
			}
			// Anything else (5xx, an HTML error page, a proxy hiccup) is OUR end
			// being temporarily unavailable, not a broken grant. A merchant hit
			// this for weeks and was told only "Not connected to Google Sheets",
			// which reads as "your connection is gone" — they went and blamed
			// their own host's request filtering. Remember it so the message the
			// UI shows can say "retry", not "reconnect".
			if ( $code === 0 || $code >= 500 ) {
				self::flag_service_outage();
			}
			return false;
		}
		delete_transient( self::OUTAGE_FLAG );

		// Apply the response ONTO the record as it stands in the database right
		// now, not onto the pre-call snapshot. A refresh answer carries a token
		// and an expiry, nothing else; connected_email and connected_at belong
		// to whatever was last saved, possibly in the browser while this worker
		// was mid-call. And a vault that vanished mid-flight (the merchant
		// disconnected) must not be resurrected.
		$fresh = self::load_fresh();
		if ( ! is_array( $fresh ) ) {
			return false;
		}

		$fresh['access_token'] = (string) $body['access_token'];
		$fresh['expires_at']   = time() + max( 60, (int) ( $body['expires_in'] ?? 3600 ) );
		if ( ! empty( $body['scope'] ) ) {
			$fresh['scope'] = (string) $body['scope'];
		}
		// Google may re-issue a refresh_token; usually it omits one. Take it
		// only when offered, otherwise keep what is stored.
		if ( ! empty( $body['refresh_token'] ) ) {
			$fresh['refresh_token'] = (string) $body['refresh_token'];
		}

		if ( ! self::persist( $fresh ) ) {
			return false;
		}
		return $fresh;
	}

	/**
	 * Drop the in-process plaintext cache. Called from __destruct hooks and
	 * after admin-disconnect actions.
	 */
	public static function flush_cache() {
		self::$cache    = null;
		self::$loaded   = false;
		self::$state    = self::STATE_EMPTY;
		self::$reported = false;
		Brikpanel_Secret_Vault::flush();
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
		if ( self::$loaded ) {
			return is_array( self::$cache ) ? self::$cache : null;
		}
		self::$loaded = true;

		$raw = (string) get_option( self::OPTION, '' );
		if ( $raw === '' ) {
			self::$state = self::STATE_EMPTY;
			self::$cache = null;
			return null;
		}

		$rewrap = false;
		$plain  = Brikpanel_Secret_Vault::decrypt(
			$raw,
			Brikpanel_Secret_Vault::info( self::KDF_INFO ),
			// Bare, NOT through info(): blobs written before 3.3.14 were keyed
			// with the constant alone. Folding the blog id in here would make
			// every existing connection permanently unreadable.
			self::KDF_INFO_LEGACY,
			$rewrap
		);

		if ( $plain === false ) {
			// NOT wiped. Until 3.3.14 this deleted the option and logged
			// "corrupted blob, wiping". The blob was virtually never corrupt —
			// the key was salted with site_url(), a per-request value, so a
			// connection made in the browser was unreadable from cron and the
			// plugin destroyed it. See Brikpanel_Secret_Vault.
			self::$state = self::STATE_UNREADABLE;
			self::$cache = null;
			self::report_unreadable( $raw, 'key_mismatch' );
			return null;
		}

		$data = json_decode( $plain, true );
		if ( ! is_array( $data ) ) {
			// Previously a SILENT clear(). Same reasoning, and now audible.
			self::$state = self::STATE_UNREADABLE;
			self::$cache = null;
			self::report_unreadable( $raw, 'corrupt_payload' );
			return null;
		}

		self::$state = self::STATE_OK;
		self::$cache = $data;

		if ( $rewrap && ! self::$in_fresh_read ) {
			self::rewrap( $raw, $data );
		}

		return $data;
	}

	/**
	 * Re-read the vault from the database, ignoring this process's cache.
	 *
	 * Mirrors Brikpanel_Ads_Tokens::load_all_fresh(). Every write here is a
	 * read-modify-write of one blob, and load() answers from a static cache
	 * that can be minutes old inside a long-lived Action Scheduler worker —
	 * so a worker holding a stale snapshot could write it back over a
	 * connection the merchant had just made in the browser.
	 *
	 * @return array|null
	 */
	private static function load_fresh() {
		self::$cache  = null;
		self::$loaded = false;
		self::$state  = self::STATE_EMPTY;

		// autoload=no, but get_option still answers from the per-request object
		// cache once something has read it.
		wp_cache_delete( self::OPTION, 'options' );

		// And from the "notoptions" list: a worker that started before anything
		// was connected recorded this key as non-existent, so clearing only the
		// value cache would still return an empty vault after the merchant
		// connected.
		$notoptions = wp_cache_get( 'notoptions', 'options' );
		if ( is_array( $notoptions ) && isset( $notoptions[ self::OPTION ] ) ) {
			unset( $notoptions[ self::OPTION ] );
			wp_cache_set( 'notoptions', $notoptions, 'options' );
		}

		self::$in_fresh_read = true;
		try {
			return self::load();
		} finally {
			self::$in_fresh_read = false;
		}
	}

	/**
	 * Encrypt and store the vault.
	 *
	 * @param array $payload
	 * @param bool  $replace_unreadable Only an explicit merchant reconnect may
	 *                                  pass true, having quarantined first.
	 * @return bool
	 */
	private static function persist( array $payload, $replace_unreadable = false ) {
		if ( self::$state === self::STATE_UNREADABLE && ! $replace_unreadable ) {
			return false;
		}

		$encrypted = Brikpanel_Secret_Vault::encrypt(
			wp_json_encode( $payload ),
			Brikpanel_Secret_Vault::info( self::KDF_INFO )
		);
		if ( $encrypted === false ) {
			Brikpanel_Sheets_Logger::log(
				'oauth',
				'Credential encryption is unavailable on this server (no libsodium, no OpenSSL).',
				0,
				[ 'reason' => 'no_cipher' ]
			);
			return false;
		}

		$ok = update_option( self::OPTION, $encrypted, false );
		if ( $ok ) {
			self::$cache  = $payload;
			self::$loaded = true;
			self::$state  = self::STATE_OK;
		}
		return (bool) $ok;
	}

	/**
	 * Re-encrypt a legacy blob under the current key. Lazy migration; see
	 * Brikpanel_Ads_Tokens::rewrap() for the full reasoning.
	 *
	 * @param string $raw
	 * @param array  $data
	 */
	private static function rewrap( $raw, array $data ) {
		$blob = Brikpanel_Secret_Vault::encrypt(
			wp_json_encode( $data ),
			Brikpanel_Secret_Vault::info( self::KDF_INFO )
		);
		if ( $blob === false ) {
			return;
		}
		if ( (string) get_option( self::OPTION, '' ) !== $raw ) {
			return; // somebody wrote a newer vault; leave it alone
		}

		self::quarantine( $raw );
		update_option( self::OPTION, $blob, false );
		Brikpanel_Sheets_Logger::log(
			'oauth',
			'Stored credentials were re-encrypted with a stable site key.',
			0,
			[ 'reason' => 'rewrapped' ]
		);
	}

	/**
	 * Park ciphertext we could not open (or are about to replace). Never
	 * overwrites an existing quarantine.
	 *
	 * @param string $raw
	 */
	private static function quarantine( $raw ) {
		if ( $raw === '' || (string) get_option( self::QUARANTINE_OPTION, '' ) !== '' ) {
			return;
		}
		update_option( self::QUARANTINE_OPTION, $raw, false );
	}

	/**
	 * Report an unreadable vault once per request and once per blob per
	 * ALERT_TTL. See Brikpanel_Ads_Tokens::report_unreadable().
	 *
	 * @param string $raw
	 * @param string $reason 'key_mismatch' | 'corrupt_payload'
	 */
	private static function report_unreadable( $raw, $reason ) {
		if ( self::$reported ) {
			return;
		}
		self::$reported = true;

		$sig  = substr( md5( $raw . '|' . $reason ), 0, 16 );
		$seen = get_option( self::ALERT_OPTION, [] );
		if (
			is_array( $seen )
			&& isset( $seen['sig'], $seen['at'] )
			&& $seen['sig'] === $sig
			&& ( time() - (int) $seen['at'] ) < self::ALERT_TTL
		) {
			return;
		}
		update_option( self::ALERT_OPTION, [ 'sig' => $sig, 'at' => time() ], false );

		$message = $reason === 'corrupt_payload'
			? 'Stored credentials decrypted but the contents were not readable — kept, not deleted.'
			: 'Stored credentials could not be decrypted with this site key — kept, not deleted. Check the site address (http vs https, www vs non-www) and the wp-config salts, then reconnect.';

		Brikpanel_Sheets_Logger::log(
			'oauth',
			$message,
			0,
			[
				'reason' => $reason,
				'scheme' => is_ssl() ? 'https' : 'http',
				'host'   => (string) wp_parse_url( site_url(), PHP_URL_HOST ),
				'ctx'    => wp_doing_cron() ? 'cron' : ( is_admin() ? 'admin' : 'front' ),
			]
		);
	}

	/** Whether the stored vault exists but could not be opened this request. */
	public static function is_unreadable() {
		self::load();
		return self::$state === self::STATE_UNREADABLE;
	}
}
