<?php
/**
 * BrikPanel — Ad Platforms multi-platform token vault.
 *
 * Single encrypted blob in wp_options holding OAuth credentials for both
 * Google Ads and Meta Ads, keyed by platform slug. The encryption envelope is
 * shared with the Google Sheets vault: see Brikpanel_Secret_Vault.
 *
 * Why a single blob instead of one option per platform: atomic update,
 * consistent encryption envelope, single load/decrypt per request, and the
 * settings page only needs one DB read to render both connection cards.
 *
 * READ FAILURES NEVER DELETE
 * --------------------------
 * Until 3.3.14 a decrypt failure was treated as corruption and the option was
 * deleted. It was virtually never corruption: the key was salted with
 * site_url(), which is a per-request value, so a connection made in an HTTPS
 * browser request was unreadable from the WP-Cron loopback seconds later and
 * the plugin destroyed it. Both platforms at once, since they share this blob.
 *
 * The vault is therefore tri-state — empty / readable / unreadable — and a
 * read failure is a read failure. Nothing on a read path deletes credentials
 * any more, write paths refuse to persist a vault built on top of something
 * they could not read, and an explicit reconnect parks the old ciphertext in
 * QUARANTINE_OPTION rather than dropping it. Brikpanel_Secret_Vault carries
 * the full explanation of the key bug.
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

	/**
	 * Refresh-skew seconds: refresh if a token expires sooner than this.
	 *
	 * This is per-platform because the two token lifetimes are three orders of
	 * magnitude apart:
	 *
	 *   - Google issues 1-hour access tokens and a long-lived refresh_token, so
	 *     a 90-second skew is fine: something touches the API far more often
	 *     than once an hour, and the refresh_token can always mint a new one.
	 *
	 *   - Meta has no refresh_token. The long-lived user token lives ~60 days
	 *     and the ONLY way to renew it is to hand the still-valid token back
	 *     through fb_exchange_token before it dies. With a 90-second skew the
	 *     renewal window is 90 seconds wide at the end of 60 days, while the
	 *     only thing that reliably calls in is the once-a-day sync — so the
	 *     renewal fired on roughly 0.1% of cycles and the connection silently
	 *     died every two months. A 7-day skew gives the daily sync seven
	 *     consecutive chances to renew while the token is still valid.
	 */
	const REFRESH_SKEW      = 90;
	const REFRESH_SKEW_META = 7 * DAY_IN_SECONDS;

	/**
	 * Option prefix latched when a refresh failed permanently, so the settings
	 * page can tell the merchant to reconnect instead of showing a green
	 * "Connected" pill over a dead connection.
	 */
	const NEEDS_RECONNECT_PREFIX = 'brikpanel_ads_needs_reconnect_';

	/**
	 * HKDF info parameter.
	 *
	 * v2 means the key is no longer salted with the request's site_url(). See
	 * Brikpanel_Secret_Vault for why that salt destroyed connections. The v1
	 * value below is kept solely to read blobs written before 3.3.14; every
	 * such blob is re-encrypted under v2 the first time it is opened.
	 */
	const KDF_INFO        = 'brikpanel-ads-v2';
	const KDF_INFO_LEGACY = 'brikpanel-ads-v1';

	/**
	 * Ciphertext we could not open, parked instead of deleted.
	 *
	 * A blob that fails to decrypt is almost never corrupt — it is a blob
	 * written under a different key (a different request scheme or host, a
	 * SAPI without libsodium, rotated wp-config salts). Deleting it destroys
	 * the only copy of the merchant's credentials that exists. So when an
	 * explicit reconnect has to overwrite an unreadable vault, the old
	 * ciphertext is parked here first. autoload=no; no plaintext; nobody on
	 * the site can open it.
	 */
	const QUARANTINE_OPTION = 'brikpanel_ads_tokens_unreadable';

	/**
	 * Throttle stamp for the "could not decrypt" log line.
	 *
	 * is_connected() runs on init:20 of EVERY request, storefront included, so
	 * an unreadable vault would otherwise write one log entry per page view and
	 * evict all 100 real entries from the ring buffer within a minute.
	 * Shape: [ 'sig' => <hash of blob+reason>, 'at' => <unix ts> ].
	 */
	const ALERT_OPTION = 'brikpanel_ads_vault_alert';

	/** How long the same unreadable blob stays quiet after being reported. */
	const ALERT_TTL = 6 * HOUR_IN_SECONDS;

	/** Vault states. See $state. */
	const STATE_EMPTY      = 'empty';
	const STATE_OK         = 'ok';
	const STATE_UNREADABLE = 'unreadable';

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

	/**
	 * Whether load_all() has run this request.
	 *
	 * Separate from $cache because an unreadable vault caches an empty array
	 * too, and "empty because there is nothing" must stay distinguishable from
	 * "empty because we could not read it".
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
	 * True while load_all_fresh() is running, i.e. a write is about to follow.
	 *
	 * Suppresses the lazy re-encrypt: the persist() that follows re-encrypts
	 * under the current key for free, and skipping it here removes the only
	 * window where a rewrap could land on top of a newer record.
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
	 * Whether a specific platform is currently connected.
	 *
	 * @param string $platform PLATFORM_GOOGLE | PLATFORM_META
	 */
	public static function is_connected( $platform ) {
		if ( ! self::is_valid_platform( $platform ) ) {
			return false;
		}
		// This runs on init:20 of every request, storefront included. On the
		// overwhelming majority of installs nothing is connected, so answer
		// from the (already primed) raw option without decrypting anything.
		if ( ! self::$loaded && (string) get_option( self::OPTION, '' ) === '' ) {
			self::$loaded = true;
			self::$cache  = [];
			self::$state  = self::STATE_EMPTY;
			return false;
		}
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

		$all = self::load_all_fresh();

		// An explicit reconnect is the one path allowed to overwrite a vault we
		// cannot open — the merchant is deliberately replacing it. Park the old
		// ciphertext first (it may still be readable from another context, or
		// after the site address is put back), start from an empty vault rather
		// than one we half-guessed, and say plainly that anything else stored
		// in there needs reconnecting too.
		$replace_unreadable = ( self::$state === self::STATE_UNREADABLE );
		if ( $replace_unreadable ) {
			self::quarantine( (string) get_option( self::OPTION, '' ) );
			Brikpanel_Ads_Logger::log(
				'oauth',
				'An unreadable credential vault was replaced by this new connection; other platforms stored in it must be reconnected.',
				0,
				[ 'reason' => 'vault_replaced' ]
			);
			$all = [];
		}

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

		return self::persist( $all, $replace_unreadable );
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
		$all = self::load_all_fresh();
		if ( self::$state === self::STATE_UNREADABLE ) {
			// Editing one field of a vault we cannot read would mean writing
			// back a vault with everything else missing. persist() would refuse
			// anyway; refusing here keeps the intent in the code.
			return false;
		}
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
		$all = self::load_all_fresh();

		// A vault we cannot open cannot be partially edited, and the merchant
		// has asked for a connection to be removed. Dropping the whole row is
		// the only honest answer — and unlike the old decrypt-failure wipe this
		// one is user-initiated, so the quarantine goes with it.
		if ( self::$state === self::STATE_UNREADABLE ) {
			Brikpanel_Ads_Logger::log(
				'oauth',
				'Disconnect requested on an unreadable credential vault; all stored platforms were removed.',
				0,
				[ 'reason' => 'disconnect_unreadable' ]
			);
			self::clear();
			return;
		}

		unset( $all[ $platform ] );
		if ( empty( $all ) ) {
			self::clear();
			return;
		}
		self::persist( $all );
	}

	/**
	 * Wipe ALL connections.
	 *
	 * Only ever called for a deliberate removal: a merchant disconnect, or a
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
	 * @param string $platform
	 * @return string|null Null when not connected or refresh failed.
	 */
	public static function get_access_token( $platform ) {
		$tokens = self::load_platform( $platform );
		if ( ! $tokens || empty( $tokens['access_token'] ) ) {
			return null;
		}

		$remaining = (int) $tokens['expires_at'] - time();
		if ( $remaining > self::refresh_skew( $platform ) ) {
			return (string) $tokens['access_token'];
		}

		$refreshed = self::refresh( $platform );
		if ( $refreshed === false ) {
			// Renewal failed but the token itself may still have life left (we
			// start trying a week early on Meta). Keep using it rather than
			// hard-failing the sync a week before we have to.
			return $remaining > 0 ? (string) $tokens['access_token'] : null;
		}
		return (string) $refreshed['access_token'];
	}

	/**
	 * How early to renew, per platform. See REFRESH_SKEW for the rationale.
	 *
	 * @param string $platform
	 * @return int Seconds.
	 */
	private static function refresh_skew( $platform ) {
		return $platform === self::PLATFORM_META ? self::REFRESH_SKEW_META : self::REFRESH_SKEW;
	}

	/**
	 * Whether the last renewal attempt for this platform failed permanently
	 * (token revoked / expired past recovery). The settings page surfaces this
	 * as a "reconnect required" banner.
	 *
	 * @param string $platform
	 * @return string '' when healthy, otherwise a short reason slug.
	 */
	public static function needs_reconnect( $platform ) {
		if ( ! self::is_valid_platform( $platform ) ) {
			return '';
		}
		return (string) get_option( self::NEEDS_RECONNECT_PREFIX . $platform, '' );
	}

	/** Latch the reconnect-required flag. */
	public static function flag_needs_reconnect( $platform, $reason = 'revoked' ) {
		if ( self::is_valid_platform( $platform ) ) {
			update_option( self::NEEDS_RECONNECT_PREFIX . $platform, (string) $reason, false );
		}
	}

	/** Clear the reconnect-required flag (called on a successful connect). */
	public static function clear_needs_reconnect( $platform ) {
		if ( self::is_valid_platform( $platform ) ) {
			delete_option( self::NEEDS_RECONNECT_PREFIX . $platform );
		}
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
		// Read past this process's cache. A long-lived Action Scheduler worker
		// can hold a snapshot minutes old, and spending a superseded credential
		// gets a 400 back — which, for Meta, this class treats as terminal and
		// used to answer by deleting the connection the merchant had just made.
		// refresh() runs once an hour at most, so the uncached read is free.
		$tokens = self::load_platform_fresh( $platform );
		if ( ! $tokens ) {
			if ( self::$state === self::STATE_UNREADABLE ) {
				// Do not spend a credential we could not read, and above all do
				// not let the resulting failure drop the connection.
				return false;
			}
			// Not a failure to report: a caller asked for a token on a platform
			// that is not connected, which is exactly what happens on the retry
			// right after we dropped a revoked grant.
			Brikpanel_Ads_Logger::note( 'oauth', 'Refresh attempted with no stored tokens for ' . $platform );
			return false;
		}

		// Fingerprint the credential this attempt is about to spend, so a
		// permanent failure can be attributed to it rather than to whatever is
		// stored by the time the answer comes back.
		$sent_fp = self::credential_fingerprint( $platform, $tokens );

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
			if ( self::is_permanent_refresh_failure( $platform, $code, $body ) ) {
				// Before acting on "this credential is dead", check it is still
				// the stored one. The merchant can reconnect (or another worker
				// can renew) while this call is in flight, and because ANY
				// 400/401 counts as terminal for Meta, attributing it to the new
				// credential would delete the connection they just made. This
				// guard is the only thing standing between a mid-flight
				// reconnect and a wiped connection.
				$now = self::load_platform_fresh( $platform );
				if ( ! is_array( $now ) ) {
					return false; // already gone; nothing left to drop
				}
				if ( ! hash_equals( $sent_fp, self::credential_fingerprint( $platform, $now ) ) ) {
					Brikpanel_Ads_Logger::note(
						'oauth',
						$platform . ' refresh failed on a credential that is no longer stored; the newer connection was left alone.',
						$code
					);
					return false;
				}

				// Token is gone for good. Latch the reconnect flag FIRST (the
				// UI reads it to explain why the platform went dark), then drop
				// the dead credentials so nothing keeps retrying with them.
				self::flag_needs_reconnect( $platform, 'revoked' );
				Brikpanel_Ads_Logger::log(
					'oauth',
					$platform . ' refresh failed permanently (HTTP ' . $code . ') — connection dropped, merchant must reconnect.',
					$code
				);
				self::disconnect( $platform );

				// A revoked grant discovered mid-backfill used to leave every
				// remaining 90-day chunk queued. They all woke up, found no
				// connection, and filled the log ring with identical notes
				// while the progress bar stayed frozen with no explanation.
				// Stop the queue and say why on the card instead.
				if ( class_exists( 'Brikpanel_Ads_Sync' ) ) {
					Brikpanel_Ads_Sync::cancel_backfill( $platform, Brikpanel_Ads_Sync::HALT_CONNECTION_LOST );
				}
			}
			return false;
		}

		$all = self::load_all_fresh();

		if ( self::$state === self::STATE_UNREADABLE ) {
			// The vault became unreadable between the call and the write.
			// Persisting now would store a vault holding only this platform.
			return false;
		}

		// The merchant can disconnect while the refresh call is in flight.
		// Writing the renewed token back here would resurrect the connection
		// they just removed, so treat a vanished platform as a failed refresh
		// (which it is: there is nothing left to refresh).
		if ( ! isset( $all[ $platform ] ) || ! is_array( $all[ $platform ] ) ) {
			return false;
		}

		// Apply the response ONTO the record as it stands in the database right
		// now. The pre-call snapshot must not be written back wholesale: a
		// refresh answer carries a token and an expiry, nothing else, while
		// primary_account / login_customer_id / connected_email belong to
		// whatever the merchant last saved — possibly in the browser, while
		// this Action Scheduler worker was mid-call. Assigning the snapshot
		// back silently reverted those.
		$fresh                 = $all[ $platform ];
		$fresh['access_token'] = (string) $body['access_token'];
		$fresh['expires_at']   = time() + max( 60, (int) ( $body['expires_in'] ?? 3600 ) );
		if ( ! empty( $body['scope'] ) ) {
			$fresh['scope'] = (string) $body['scope'];
		}
		// Meta's fb_exchange_token does NOT issue a new refresh_token (there is
		// none), and Google's refresh response usually omits one too. Take one
		// only when it is actually offered; otherwise keep what is stored.
		if ( ! empty( $body['refresh_token'] ) ) {
			$fresh['refresh_token'] = (string) $body['refresh_token'];
		}

		$all[ $platform ] = $fresh;
		if ( ! self::persist( $all ) ) {
			return false;
		}

		// A renewal that came back clean means the connection is healthy again.
		// Cleared only now: doing it before the write took down the merchant's
		// "reconnect required" banner without knowing the write had landed.
		self::clear_needs_reconnect( $platform );

		return $fresh;
	}

	/**
	 * Hash of the credential a refresh spends, used to tell "this token is
	 * dead" apart from "this token was replaced while we were asking".
	 *
	 * Google spends the refresh_token; Meta exchanges the access_token itself.
	 *
	 * @param string $platform
	 * @param array  $tokens
	 * @return string
	 */
	private static function credential_fingerprint( $platform, array $tokens ) {
		$secret = $platform === self::PLATFORM_GOOGLE
			? ( $tokens['refresh_token'] ?? '' )
			: ( $tokens['access_token'] ?? '' );
		return hash( 'sha256', (string) $secret );
	}

	/** Drop the in-process plaintext cache and the derived-key memo. */
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
	 * Load and decrypt the full multi-platform vault.
	 *
	 * @return array<string, array>
	 */
	private static function load_all() {
		if ( self::$loaded ) {
			return is_array( self::$cache ) ? self::$cache : [];
		}
		self::$loaded = true;

		$raw = (string) get_option( self::OPTION, '' );
		if ( $raw === '' ) {
			self::$state = self::STATE_EMPTY;
			self::$cache = [];
			return [];
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
			// NOT wiped. Until 3.3.14 this branch deleted the option and logged
			// "corrupted blob, wiping", which was wrong on both counts: the
			// ciphertext was virtually always intact, and it was the only copy
			// of the merchant's credentials in existence. A key that does not
			// open a blob means this REQUEST cannot read it — a different
			// scheme, a different host, a SAPI without libsodium — not that the
			// data is gone. Keep it and say so.
			self::$state = self::STATE_UNREADABLE;
			self::$cache = [];
			self::report_unreadable( $raw, 'key_mismatch' );
			return [];
		}

		$data = json_decode( $plain, true );
		if ( ! is_array( $data ) ) {
			// Previously a SILENT clear(). Same reasoning, and now audible.
			self::$state = self::STATE_UNREADABLE;
			self::$cache = [];
			self::report_unreadable( $raw, 'corrupt_payload' );
			return [];
		}

		self::$state = self::STATE_OK;
		self::$cache = $data;

		if ( $rewrap && ! self::$in_fresh_read ) {
			self::rewrap( $raw, $data );
		}

		return $data;
	}

	/**
	 * Re-encrypt a legacy blob under the current key.
	 *
	 * Lazy migration: it happens on whichever request first manages to open the
	 * blob, so there is no one-shot upgrade pass to mis-sequence and no window
	 * where a half-migrated site is unreadable.
	 *
	 * Skipped entirely when a write is about to follow (see $in_fresh_read).
	 * The raw option is re-read immediately before the write so that a save()
	 * landing in the microseconds since load_all() wins over this rewrap.
	 *
	 * @param string $raw  The exact ciphertext this rewrap is replacing.
	 * @param array  $data The decrypted vault.
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

		// Park the pre-migration ciphertext. If this site is ever rolled back to
		// a build that predates the v3 format, the old build will not recognise
		// it and will wipe — and this copy is what makes that recoverable.
		self::quarantine( $raw );

		update_option( self::OPTION, $blob, false );
		Brikpanel_Ads_Logger::note(
			'oauth',
			'Stored credentials were re-encrypted with a stable site key.',
			0,
			[ 'reason' => 'rewrapped' ]
		);
	}

	/**
	 * Park ciphertext we could not open (or are about to replace).
	 *
	 * Never overwrites an existing quarantine: the older copy is the one more
	 * likely to still be recoverable.
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
	 * ALERT_TTL, with the three facts that actually diagnose it.
	 *
	 * Keying the throttle on the blob means a NEW failure is never suppressed:
	 * a merchant who reconnects and fails again gets a fresh entry immediately,
	 * because the ciphertext changed.
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

		// scheme/host/ctx ARE the diagnosis: two entries differing in either
		// column is the whole bug report, readable without a database client.
		Brikpanel_Ads_Logger::log(
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
		self::load_all();
		return self::$state === self::STATE_UNREADABLE;
	}

	/**
	 * Re-read the vault from the database, ignoring this process's cache.
	 *
	 * Every write here is a read-modify-write of one blob holding BOTH
	 * platforms, and load_all() answers from a static cache that can be
	 * minutes old inside a long-lived Action Scheduler worker. So a worker that
	 * cached the vault, then had Meta's token refreshed at the end of its
	 * batch, wrote its stale snapshot back — resurrecting a Google connection
	 * the merchant had disconnected in the browser meanwhile, and in the
	 * opposite order wiping a connection they had just made. Both show up as
	 * "I connected it and it did not stick".
	 *
	 * Reading fresh immediately before each write closes the realistic window
	 * (a snapshot from minutes ago) down to the microseconds between this read
	 * and the update_option that follows. One extra uncached read on operations
	 * that happen a handful of times per site.
	 *
	 * @return array<string, array>
	 */
	private static function load_all_fresh() {
		self::$cache  = null;
		self::$loaded = false;
		self::$state  = self::STATE_EMPTY;

		// autoload=no, but get_option still answers from the per-request object
		// cache once something has read it.
		wp_cache_delete( self::OPTION, 'options' );

		// And from the "notoptions" list, which is the half that actually bit:
		// a worker that started before anything was connected recorded
		// brikpanel_ads_tokens as non-existent, so clearing only the value
		// cache still returned an empty vault after the merchant connected,
		// and the next write wiped the brand-new connection. That is the exact
		// "I connected it and it did not stick" report.
		$notoptions = wp_cache_get( 'notoptions', 'options' );
		if ( is_array( $notoptions ) && isset( $notoptions[ self::OPTION ] ) ) {
			unset( $notoptions[ self::OPTION ] );
			wp_cache_set( 'notoptions', $notoptions, 'options' );
		}

		// A write follows this read, so skip the lazy re-encrypt: persist()
		// will re-encrypt under the current key anyway.
		self::$in_fresh_read = true;
		try {
			return self::load_all();
		} finally {
			self::$in_fresh_read = false;
		}
	}

	/** load_platform(), bypassing this process's cache. Used before a write. */
	private static function load_platform_fresh( $platform ) {
		if ( ! self::is_valid_platform( $platform ) ) {
			return null;
		}
		$all = self::load_all_fresh();
		if ( ! isset( $all[ $platform ] ) || ! is_array( $all[ $platform ] ) ) {
			return null;
		}
		return $all[ $platform ];
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

	/**
	 * Encrypt and store the whole vault.
	 *
	 * @param array $all
	 * @param bool  $replace_unreadable Only an explicit merchant reconnect may
	 *                                  pass true. It means "I know the stored
	 *                                  blob cannot be opened, replace it
	 *                                  wholesale", and the caller must have
	 *                                  quarantined the old ciphertext first.
	 * @return bool
	 */
	private static function persist( array $all, $replace_unreadable = false ) {
		if ( self::$state === self::STATE_UNREADABLE && ! $replace_unreadable ) {
			// $all was built on top of a vault we could not read, so it is
			// missing every platform that was in there. Writing it would finish
			// the job the old wipe started. Refuse; the ciphertext stays on
			// disk and stays recoverable.
			return false;
		}

		$encrypted = Brikpanel_Secret_Vault::encrypt(
			wp_json_encode( $all ),
			Brikpanel_Secret_Vault::info( self::KDF_INFO )
		);
		if ( $encrypted === false ) {
			Brikpanel_Ads_Logger::log(
				'oauth',
				'Credential encryption is unavailable on this server (no libsodium, no OpenSSL).',
				0,
				[ 'reason' => 'no_cipher' ]
			);
			return false;
		}
		$ok = update_option( self::OPTION, $encrypted, false );
		if ( $ok ) {
			self::$cache  = $all;
			self::$loaded = true;
			self::$state  = self::STATE_OK;
		}
		return (bool) $ok;
	}

	private static function is_valid_platform( $platform ) {
		return $platform === self::PLATFORM_GOOGLE || $platform === self::PLATFORM_META;
	}

	/**
	 * Decide whether a failed refresh is permanent (credentials are dead and
	 * only a fresh consent can fix it) or transient (network blip, proxy
	 * hiccup, rate limit) and therefore worth retrying tomorrow.
	 *
	 * Google speaks OAuth 2.0 error slugs, so we match the well-known
	 * permanent ones. Meta does NOT: Graph replies with a nested error object
	 * ({"error":{"type":"OAuthException","code":190,...}}) and the proxy used
	 * to flatten that with a bare (string) cast, which produced the literal
	 * text "Array" — a value that matched nothing in the allowlist, so a
	 * revoked Meta token was never detected and the card sat on a green
	 * "Connected" pill forever. We now dig the real type/code out of the
	 * nested shape AND fall back to the HTTP status: fb_exchange_token only
	 * answers 400/401 when the token itself is unusable (throttling is 429,
	 * outages are 5xx), and because Meta renewal now starts a full week early
	 * a 400 there is never "the token merely aged out in flight".
	 *
	 * @param string $platform
	 * @param int    $code HTTP status from the proxy.
	 * @param mixed  $body Decoded proxy body.
	 * @return bool
	 */
	private static function is_permanent_refresh_failure( $platform, $code, $body ) {
		if ( $code !== 400 && $code !== 401 ) {
			return false;
		}

		// The proxy answers 400 for its OWN request-validation problems too
		// ("Bad request.", "Missing access_token.", "Proxy not configured.").
		// Those say nothing about the merchant's credentials, so never drop a
		// healthy connection over one.
		$message = is_array( $body ) && isset( $body['message'] ) && is_scalar( $body['message'] )
			? strtolower( trim( (string) $body['message'] ) )
			: '';
		if ( $message !== '' && $message !== 'refresh failed.' ) {
			return false;
		}

		$err = '';
		if ( is_array( $body ) && isset( $body['error'] ) ) {
			$raw = $body['error'];
			if ( is_array( $raw ) ) {
				// Meta's nested error object, or a proxy that forwarded it whole.
				$err = (string) ( $raw['type'] ?? $raw['message'] ?? '' );
			} elseif ( is_scalar( $raw ) ) {
				$err = (string) $raw;
			}
		}
		$err = strtolower( trim( $err ) );

		if ( $platform === self::PLATFORM_META ) {
			// Any upstream 400/401 from fb_exchange_token means the token is
			// unusable: Graph answers 429 for throttling and 5xx for outages,
			// and because Meta renewal now starts a full week early this can
			// never be "the token merely aged out while the call was in
			// flight". Reconnect is the only fix.
			return true;
		}

		return in_array(
			$err,
			[ 'invalid_grant', 'unauthorized_client', 'oauth_exception', 'token_revoked', 'token_expired', 'invalid_token' ],
			true
		);
	}
}
