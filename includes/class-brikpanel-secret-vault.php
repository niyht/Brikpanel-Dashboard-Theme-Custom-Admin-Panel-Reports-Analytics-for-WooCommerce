<?php
/**
 * BrikPanel — Credential encryption envelope (shared by the Ad Platforms and
 * Google Sheets token vaults).
 *
 * WHY THIS FILE EXISTS
 * --------------------
 * The two vaults shipped hand-copied crypto, and the same defect therefore
 * shipped twice. The legacy-key recovery below is the subtlest code in either
 * module; keeping two copies of it in sync is how the original bug happened.
 * `includes/class-brikpanel-proxy-envelope.php` already establishes the
 * pattern of one shared class required by both modules.
 *
 * THE BUG THIS FILE FIXES
 * -----------------------
 * Both vaults derived their key with `site_url()` as the HKDF salt.
 * `site_url()` is NOT a stable site identity — it is a per-request value, in
 * stock WordPress, with no plugins involved:
 *
 *   - `set_url_scheme( $url, null )` (wp-includes/link-template.php) does
 *     `$scheme = is_ssl() ? 'https' : 'http'` and then rewrites the URL's
 *     scheme. So the stored `siteurl` scheme is *overwritten* by whatever the
 *     current request happens to be. A site on HTTPS whose WP-Cron loopback
 *     arrives without SSL markers — the normal case behind Cloudflare, a load
 *     balancer, or any TLS-terminating proxy — derives a different key in the
 *     background than it did in the browser.
 *   - `spawn_cron()` builds the loopback URL from `site_url()` as evaluated in
 *     the *spawning* request, so cron inherits a random visitor's host. www
 *     and non-www therefore produce two different keys on the same site.
 *   - Hosts and plugins filter `site_url` on a per-request basis (Hostinger's
 *     preview-domain mu-plugin keys off an HTTP header; domain mapping and
 *     multilingual plugins key off the requested domain).
 *
 * The result was a key mismatch between two requests on the same site, which
 * both vaults reported as a corrupted blob and "fixed" by deleting the
 * merchant's credentials. See the tri-state load in the two vault classes:
 * a decrypt failure is now a read failure, never a delete.
 *
 * THE KEY
 * -------
 * The IKM is already AUTH_KEY + SECURE_AUTH_KEY + LOGGED_IN_KEY — roughly 192
 * characters of per-site secret. RFC 5869 §2.2 makes the HKDF salt optional,
 * and with an IKM that strong it contributes nothing. So the salt is dropped
 * and the per-site binding comes from the IKM alone, which does not change
 * between two requests.
 *
 * Multisite: dropping the salt would also drop the *accidental* per-blog
 * separation that `site_url()` provided, so callers fold the blog id into the
 * info string instead (see `Brikpanel_Secret_Vault::info()`).
 * `get_current_blog_id()` is 1 on single-site, stays 1 when a single site
 * becomes the main site of a network, and follows `switch_to_blog()` in
 * lockstep with `get_option()` — stable exactly where `site_url()` was not.
 *
 * WIRE FORMAT
 * -----------
 *   v1:<base64(nonce . secretbox)>      legacy — key salted with site_url()
 *   v2:<base64(iv . tag . aes-256-gcm)> legacy — key salted with site_url()
 *   v3:<base64(nonce . secretbox)>      current — key from the site salts only
 *   v4:<base64(iv . tag . aes-256-gcm)> current — key from the site salts only
 *
 * A `v1:`/`v2:` blob is read by walking a short list of plausible historical
 * `site_url()` values. On success the caller is told to re-encrypt, so each
 * site pays that walk once and then never again.
 *
 * @package BrikPanel
 * @since   3.3.14
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Brikpanel_Secret_Vault {

	/** Legacy envelopes: the key was salted with the request's site_url(). */
	const PREFIX_SODIUM_LEGACY = 'v1:';
	const PREFIX_AESGCM_LEGACY = 'v2:';

	/** Current envelopes: the key is bound to the site's salts only. */
	const PREFIX_SODIUM = 'v3:';
	const PREFIX_AESGCM = 'v4:';

	/**
	 * Hard cap on legacy site_url() guesses.
	 *
	 * The candidate list is built from options an administrator controls, so
	 * it is bounded on purpose rather than by accident. Each extra candidate
	 * costs one hash_hkdf plus one AEAD open — measured at ~0.0025 ms each —
	 * and only until the blob is re-encrypted.
	 */
	const MAX_LEGACY_CANDIDATES = 32;

	/**
	 * Derived keys, memoised for the life of the request.
	 *
	 * Keyed by info + salt. Never logged, never exposed.
	 *
	 * @var array<string, string>
	 */
	private static $keys = [];

	/**
	 * Legacy salt candidates, built once per request.
	 *
	 * @var string[]|null
	 */
	private static $legacy_salts = null;

	/**
	 * Count of legacy candidate attempts. Test instrumentation only; the
	 * counter is inert unless BRIKPANEL_VAULT_TEST is defined.
	 *
	 * @var int
	 */
	public static $legacy_attempts = 0;

	// =========================================================================
	// Public API
	// =========================================================================

	/**
	 * Build the HKDF info string for a vault.
	 *
	 * The blog id keeps one site's ciphertext unreadable by another on a
	 * network, replacing the separation the site_url() salt used to provide by
	 * accident. It is stable across the single-site → main-site conversion.
	 *
	 * @param string $base Vault-specific constant, e.g. 'brikpanel-ads-v2'.
	 * @return string
	 */
	public static function info( $base ) {
		return $base . '|' . (int) get_current_blog_id();
	}

	/**
	 * Encrypt a plaintext string into a current-format envelope.
	 *
	 * @param string $plaintext
	 * @param string $info HKDF info string, from self::info().
	 * @return string|false Envelope, or false when no cipher is available.
	 */
	public static function encrypt( $plaintext, $info ) {
		return self::seal( (string) $plaintext, self::key( $info ) );
	}

	/**
	 * Decrypt an envelope, transparently recovering legacy blobs.
	 *
	 * @param string $blob         Stored envelope.
	 * @param string $info         Current HKDF info string, from self::info().
	 * @param string $legacy_info  Pre-3.3.14 HKDF info string, from self::info().
	 * @param bool   $needs_rewrap Set true when the plaintext only came back
	 *                             through a legacy envelope or a legacy salt,
	 *                             so the caller can re-encrypt and persist.
	 * @return string|false Plaintext, or false when nothing could open it.
	 */
	public static function decrypt( $blob, $info, $legacy_info, &$needs_rewrap = false ) {
		$needs_rewrap = false;
		$blob         = (string) $blob;

		if ( 0 === strncmp( $blob, self::PREFIX_SODIUM, 3 ) || 0 === strncmp( $blob, self::PREFIX_AESGCM, 3 ) ) {
			// Current format. Exactly one key can open it; guessing would only
			// hide a real problem.
			return self::unseal( $blob, self::key( $info ) );
		}

		if ( 0 !== strncmp( $blob, self::PREFIX_SODIUM_LEGACY, 3 ) && 0 !== strncmp( $blob, self::PREFIX_AESGCM_LEGACY, 3 ) ) {
			return false;
		}

		// Legacy format. The key that wrote it was salted with whatever
		// site_url() returned in that request, which nobody recorded. Try the
		// plausible values, cheapest first.
		$plain = self::unseal( $blob, self::key( $legacy_info ) );
		if ( $plain !== false ) {
			$needs_rewrap = true;
			return $plain;
		}

		foreach ( self::legacy_salts() as $salt ) {
			if ( defined( 'BRIKPANEL_VAULT_TEST' ) ) {
				self::$legacy_attempts++;
			}
			$plain = self::unseal( $blob, self::key( $legacy_info, $salt ) );
			if ( $plain !== false ) {
				$needs_rewrap = true;
				return $plain;
			}
		}

		return false;
	}

	/** Whether this server can encrypt at all. */
	public static function is_available() {
		return function_exists( 'sodium_crypto_secretbox' ) || function_exists( 'openssl_encrypt' );
	}

	/**
	 * Drop memoised state. Called by the vaults' flush_cache(); also keeps the
	 * test harness honest when it changes site_url() mid-process.
	 */
	public static function flush() {
		self::$keys            = [];
		self::$legacy_salts    = null;
		self::$legacy_attempts = 0;
	}

	// =========================================================================
	// Key derivation
	// =========================================================================

	/**
	 * Input keying material: the site's own secrets.
	 *
	 * @return string
	 */
	private static function ikm() {
		$ikm = ( defined( 'AUTH_KEY' ) ? AUTH_KEY : '' )
			. ( defined( 'SECURE_AUTH_KEY' ) ? SECURE_AUTH_KEY : '' )
			. ( defined( 'LOGGED_IN_KEY' ) ? LOGGED_IN_KEY : '' );
		if ( $ikm === '' ) {
			// Absolutely no salts defined. wp_salt() persists a random value in
			// wp_options the first time it runs, so the plugin still works on a
			// misconfigured install.
			$ikm = wp_salt( 'auth' ) . wp_salt( 'secure_auth' );
		}
		return $ikm;
	}

	/**
	 * Derive (and memoise) a 32-byte key.
	 *
	 * @param string $info
	 * @param string $salt Empty for the current format. Only the legacy path
	 *                     passes a value, and only to read old blobs.
	 * @return string 32 raw bytes. Never logged.
	 */
	private static function key( $info, $salt = '' ) {
		$ck = $info . "\0" . $salt;
		if ( ! isset( self::$keys[ $ck ] ) ) {
			self::$keys[ $ck ] = hash_hkdf( 'sha256', self::ikm(), 32, $info, $salt );
		}
		return self::$keys[ $ck ];
	}

	/**
	 * Plausible historical values of site_url() for this site.
	 *
	 * Built from the RAW options (what WP-CLI and a clean cron see) *and* the
	 * live accessors (what a still-active site_url filter produces), then
	 * expanded over the axes that actually varied: scheme, www, trailing
	 * slash. A subdirectory install's path is preserved.
	 *
	 * Deliberately NOT covered: a domain the site no longer uses, or a filter
	 * that has since been deactivated. Those merchants reconnect once, and the
	 * log now names the cause instead of blaming corruption.
	 *
	 * @return string[]
	 */
	private static function legacy_salts() {
		if ( self::$legacy_salts !== null ) {
			return self::$legacy_salts;
		}

		$roots = [
			(string) get_option( 'siteurl', '' ),
			(string) get_option( 'home', '' ),
			function_exists( 'site_url' ) ? (string) site_url() : '',
			function_exists( 'home_url' ) ? (string) home_url() : '',
		];

		$out = [];
		foreach ( $roots as $root ) {
			$root = trim( $root );
			if ( $root === '' ) {
				continue;
			}
			// Verbatim first — trailing slash and all, exactly as stored.
			$out[] = $root;

			$parts = wp_parse_url( $root );
			$host  = isset( $parts['host'] ) ? (string) $parts['host'] : '';
			if ( $host === '' ) {
				continue;
			}
			$path = isset( $parts['path'] ) ? rtrim( (string) $parts['path'], '/' ) : '';

			// Both spellings, unconditionally. A cron loopback inherits the host
			// of whichever visitor spawned it, so www and non-www really do
			// write blobs under different keys on the same site. Generating a
			// candidate that cannot match costs one HKDF and nothing else, so
			// there is no case worth special-casing out.
			$bare  = ( 0 === strpos( $host, 'www.' ) ) ? substr( $host, 4 ) : $host;
			$hosts = [ $host, $bare, 'www.' . $bare ];

			foreach ( [ 'http', 'https' ] as $scheme ) {
				foreach ( $hosts as $h ) {
					$out[] = $scheme . '://' . $h . $path;
					$out[] = $scheme . '://' . $h . $path . '/';
				}
			}
		}

		$out                = array_values( array_unique( array_filter( $out ) ) );
		self::$legacy_salts = array_slice( $out, 0, self::MAX_LEGACY_CANDIDATES );

		return self::$legacy_salts;
	}

	// =========================================================================
	// Ciphers
	// =========================================================================

	/**
	 * Encrypt with the strongest available primitive.
	 *
	 * @param string $plaintext
	 * @param string $key
	 * @return string|false
	 */
	private static function seal( $plaintext, $key ) {
		if ( function_exists( 'sodium_crypto_secretbox' ) ) {
			try {
				$nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
				$cipher = sodium_crypto_secretbox( $plaintext, $nonce, $key );
				return self::PREFIX_SODIUM . base64_encode( $nonce . $cipher );
			} catch ( \Throwable $e ) {
				// fall through to OpenSSL
			}
		}

		if ( function_exists( 'openssl_encrypt' ) ) {
			try {
				$iv     = random_bytes( 12 );
				$tag    = '';
				$cipher = openssl_encrypt( $plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16 );
				if ( $cipher === false ) {
					return false;
				}
				return self::PREFIX_AESGCM . base64_encode( $iv . $tag . $cipher );
			} catch ( \Throwable $e ) {
				return false;
			}
		}

		return false;
	}

	/**
	 * Open an envelope of any of the four formats with one specific key.
	 *
	 * Returns false for a wrong key, a truncated blob, an unknown prefix, or a
	 * primitive this server does not have. The caller decides what that means
	 * — it must never mean "delete the credentials".
	 *
	 * @param string $blob
	 * @param string $key
	 * @return string|false
	 */
	private static function unseal( $blob, $key ) {
		$prefix = substr( $blob, 0, 3 );
		$body   = substr( $blob, 3 );

		if ( self::PREFIX_SODIUM === $prefix || self::PREFIX_SODIUM_LEGACY === $prefix ) {
			if ( ! function_exists( 'sodium_crypto_secretbox_open' ) ) {
				// Sodium wrote this blob and this SAPI does not have sodium.
				// A real scenario on hosts with separate CLI and FPM extension
				// sets. Unreadable here, perfectly readable elsewhere — which
				// is exactly why this is not treated as corruption.
				return false;
			}
			$bin = base64_decode( $body, true );
			if ( $bin === false || strlen( $bin ) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + 1 ) {
				return false;
			}
			$nonce  = substr( $bin, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$cipher = substr( $bin, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			try {
				$plain = sodium_crypto_secretbox_open( $cipher, $nonce, $key );
			} catch ( \Throwable $e ) {
				return false;
			}
			return $plain === false ? false : (string) $plain;
		}

		if ( self::PREFIX_AESGCM === $prefix || self::PREFIX_AESGCM_LEGACY === $prefix ) {
			if ( ! function_exists( 'openssl_decrypt' ) ) {
				return false;
			}
			$bin = base64_decode( $body, true );
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
