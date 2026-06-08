<?php
/**
 * BrikPanel — Signed proxy-response envelope (shared by the Ad Platforms and
 * Google Sheets modules).
 *
 * THREAT MODEL
 * ------------
 * Every BrikPanel install funnels its Google/Meta OAuth tokens and API reads
 * through the WPCode proxy on brksoft.com. `sslverify=true` protects the call
 * in transit, but it does NOT protect against:
 *   - a CDN / reverse-proxy cache in front of brksoft.com poisoning responses,
 *   - a partial / log-only compromise of brksoft.com that can rewrite output
 *     but cannot read the signing secret,
 *   - an operator-triggered mass "revoke" needing an authenticated channel.
 *
 * So every *successful* proxy response is wrapped in an HMAC-SHA256 envelope.
 * The plugin refuses to act on a 2xx body that is not correctly signed and
 * fresh. A signed `directive` field gives the operator an authenticated
 * kill-switch (see Brikpanel_Ads_Tokens / Brikpanel_Sheets_Tokens handlers).
 *
 * HONEST LIMITATION
 * -----------------
 * The default secret is shipped in this (public, wordpress.org) source, so by
 * itself it only stops attackers who do NOT have the plugin source — i.e. the
 * cache/transport/log-only cases above. Operators who want protection against
 * a *full* brksoft.com compromise must set a private matching value via
 * `define( 'BRIKPANEL_PROXY_SECRET', '...' )` in wp-config.php on BOTH the
 * merchant side (rare) and brksoft.com (always). A full DB compromise of
 * brksoft.com still defeats signing — that risk is inherent to the proxy
 * design and is mitigated operationally, not cryptographically.
 *
 * WIRE FORMAT (proxy → plugin, success only)
 * ------------------------------------------
 *   {
 *     "bp_env":   1,
 *     "ts":       <unix int>,
 *     "nonce":    "<hex>",
 *     "directive":"" | "revoke" | "revoke:google_ads" | "revoke:meta_ads"
 *                    | "revoke:sheets",
 *     "payload":  "<exact JSON string of the original response data>",
 *     "sig":      "<hex hmac_sha256( secret, base )>"
 *   }
 *
 *   base = "bpv1\n" . bp_env . "\n" . ts . "\n" . nonce . "\n"
 *          . directive . "\n" . payload
 *
 * Non-2xx (error) responses are intentionally left unsigned: the plugin never
 * treats them as trusted data, only as a human-readable failure reason, and
 * never honours a directive from them.
 *
 * @package BrikPanel
 * @since   3.0.2
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Brikpanel_Proxy_Envelope' ) ) {

	class Brikpanel_Proxy_Envelope {

		/**
		 * Default shared secret. Overridable — and strongly recommended to be
		 * overridden in high-security deployments — via
		 * define( 'BRIKPANEL_PROXY_SECRET', '...' ) in wp-config.php.
		 *
		 * brksoft.com MUST carry the same value (its WPCode snippets read the
		 * identical constant, falling back to this same default).
		 */
		const DEFAULT_SECRET = 'b9a2aada776bc68cb91fd4f43fd8d572bf2062e97cc3c0c16e700fab1960a169';

		/** Max accepted clock skew (seconds) between brksoft.com and the site. */
		const MAX_SKEW = 900;

		/** Signature base version tag — bump if `base` construction changes. */
		const BASE_TAG = 'bpv1';

		/**
		 * The active shared secret.
		 *
		 * @return string
		 */
		public static function secret() {
			if ( defined( 'BRIKPANEL_PROXY_SECRET' ) && is_string( BRIKPANEL_PROXY_SECRET ) && BRIKPANEL_PROXY_SECRET !== '' ) {
				return (string) BRIKPANEL_PROXY_SECRET;
			}
			return self::DEFAULT_SECRET;
		}

		/**
		 * Whether a valid signature is required on 2xx proxy responses.
		 *
		 * Defaults to true (fail-closed). Can only be relaxed by explicitly
		 * defining BRIKPANEL_PROXY_REQUIRE_SIG as false in wp-config.php — used
		 * solely for a controlled migration window while the brksoft.com
		 * snippet is being updated. Leave it unset in production.
		 *
		 * @return bool
		 */
		public static function signature_required() {
			if ( defined( 'BRIKPANEL_PROXY_REQUIRE_SIG' ) ) {
				return (bool) BRIKPANEL_PROXY_REQUIRE_SIG;
			}
			return true;
		}

		/**
		 * Compute the canonical signature for an envelope's parts.
		 *
		 * @param int    $ts
		 * @param string $nonce
		 * @param string $directive
		 * @param string $payload   Exact JSON string that will be transmitted.
		 * @param string $secret
		 * @return string Lowercase hex HMAC-SHA256.
		 */
		public static function compute_sig( $ts, $nonce, $directive, $payload, $secret ) {
			$base = self::BASE_TAG . "\n"
				. '1' . "\n"
				. (int) $ts . "\n"
				. (string) $nonce . "\n"
				. (string) $directive . "\n"
				. (string) $payload;
			return hash_hmac( 'sha256', $base, (string) $secret );
		}

		/**
		 * Validate + open a proxy HTTP response.
		 *
		 * @param int    $http_code  HTTP status from wp_remote_retrieve_response_code().
		 * @param string $raw_body   Raw response body.
		 * @return array{
		 *     ok:bool,            // true only if a fresh, correctly-signed 2xx envelope
		 *     code:int,           // original HTTP code
		 *     data:array,         // verified payload (ok) OR best-effort decoded error body (not ok)
		 *     directive:string,   // signed directive ('' when none / not ok)
		 *     error:string        // machine reason when !ok: 'http' | 'unsigned' | 'stale' | 'bad_sig' | 'malformed'
		 * }
		 */
		public static function open( $http_code, $raw_body ) {
			$http_code = (int) $http_code;
			$decoded   = json_decode( (string) $raw_body, true );

			// --- Non-2xx: never trusted, never carries a directive. ----------
			if ( $http_code < 200 || $http_code >= 300 ) {
				return [
					'ok'        => false,
					'code'      => $http_code,
					'data'      => is_array( $decoded ) ? $decoded : [],
					'directive' => '',
					'error'     => 'http',
				];
			}

			// --- 2xx: an envelope is mandatory (unless explicitly relaxed). --
			$is_envelope = is_array( $decoded )
				&& isset( $decoded['bp_env'], $decoded['ts'], $decoded['nonce'], $decoded['sig'], $decoded['payload'] )
				&& (int) $decoded['bp_env'] === 1;

			if ( ! $is_envelope ) {
				if ( ! self::signature_required() ) {
					// Migration escape hatch only. Treat the raw body as the
					// payload, no directive is ever honoured in this mode.
					return [
						'ok'        => true,
						'code'      => $http_code,
						'data'      => is_array( $decoded ) ? $decoded : [],
						'directive' => '',
						'error'     => '',
					];
				}
				return [
					'ok'        => false,
					'code'      => $http_code,
					'data'      => [],
					'directive' => '',
					'error'     => 'unsigned',
				];
			}

			$ts        = (int) $decoded['ts'];
			$nonce     = (string) $decoded['nonce'];
			$sig       = (string) $decoded['sig'];
			$directive = isset( $decoded['directive'] ) ? (string) $decoded['directive'] : '';
			$payload   = (string) $decoded['payload'];

			// Shape guards before any crypto work.
			if (
				$nonce === '' || strlen( $nonce ) > 64 || ! ctype_xdigit( $nonce ) ||
				$sig === ''   || strlen( $sig ) !== 64 || ! ctype_xdigit( $sig ) ||
				strlen( $directive ) > 64 ||
				strlen( $payload ) > 5 * 1024 * 1024
			) {
				return [ 'ok' => false, 'code' => $http_code, 'data' => [], 'directive' => '', 'error' => 'malformed' ];
			}

			// Freshness (replay window). abs() so future-skew is bounded too.
			if ( abs( time() - $ts ) > self::MAX_SKEW ) {
				return [ 'ok' => false, 'code' => $http_code, 'data' => [], 'directive' => '', 'error' => 'stale' ];
			}

			$expected = self::compute_sig( $ts, $nonce, $directive, $payload, self::secret() );
			if ( ! hash_equals( $expected, $sig ) ) {
				return [ 'ok' => false, 'code' => $http_code, 'data' => [], 'directive' => '', 'error' => 'bad_sig' ];
			}

			$inner = json_decode( $payload, true );
			if ( ! is_array( $inner ) ) {
				return [ 'ok' => false, 'code' => $http_code, 'data' => [], 'directive' => '', 'error' => 'malformed' ];
			}

			return [
				'ok'        => true,
				'code'      => $http_code,
				'data'      => $inner,
				'directive' => self::normalize_directive( $directive ),
				'error'     => '',
			];
		}

		/**
		 * Whitelist directives so a malformed-but-signed value can't surprise
		 * the kill-switch handlers.
		 *
		 * @param string $d
		 * @return string '' | 'revoke' | 'revoke:google_ads' | 'revoke:meta_ads' | 'revoke:sheets'
		 */
		public static function normalize_directive( $d ) {
			$d = strtolower( trim( (string) $d ) );
			$allowed = [ 'revoke', 'revoke:google_ads', 'revoke:meta_ads', 'revoke:sheets' ];
			return in_array( $d, $allowed, true ) ? $d : '';
		}
	}
}
