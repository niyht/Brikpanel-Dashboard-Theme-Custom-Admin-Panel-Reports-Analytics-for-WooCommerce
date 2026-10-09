<?php
/**
 * BrikPanel - Web Push protocol, with nothing but PHP's OpenSSL extension.
 *
 * - P-256 key pairs: the site's signing key (VAPID) and one fresh key per message
 * - payload encryption: aes128gcm, one record (RFC 8291 + RFC 8188)
 * - sender authorization: an ES256 token (VAPID, RFC 8292)
 *
 * No Composer library on purpose: the maintained one (minishlink/web-push)
 * needs PHP 8.2 and brings Guzzle, while BrikPanel supports PHP 7.4 and runs
 * next to other plugins' Guzzle copies. Everything used here exists since
 * PHP 7.3 (openssl_pkey_derive is the newest).
 *
 * Pure functions: no WordPress state, no options, no HTTP. Checked byte for
 * byte against RFC 8291's own example in tools/test-push.php.
 *
 * @package BrikPanel
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Brikpanel_WebPush' ) ) {

	class Brikpanel_WebPush {

		/** DER prefix of an uncompressed P-256 public key (SubjectPublicKeyInfo). */
		const SPKI_PREFIX = '3059301306072a8648ce3d020106082a8648ce3d030107034200';

		/** DER pieces of a P-256 private key (SEC1 ECPrivateKey): prefix, d, middle, public point. */
		const SEC1_PREFIX = '30770201010420';
		const SEC1_MIDDLE = 'a00a06082a8648ce3d030107a144034200';

		/** aes128gcm record size: one record holds the whole message. */
		const RECORD_SIZE = 4096;

		/**
		 * Whether this PHP can send Web Push: OpenSSL with the P-256 curve,
		 * ECDH, HKDF and AES-128-GCM. Some hosts build OpenSSL without EC.
		 *
		 * @return bool
		 */
		public static function available() {
			if ( ! function_exists( 'openssl_pkey_new' ) || ! function_exists( 'openssl_pkey_derive' )
				|| ! function_exists( 'hash_hkdf' ) || ! function_exists( 'openssl_encrypt' )
				|| ! function_exists( 'openssl_get_curve_names' ) || ! function_exists( 'random_bytes' ) ) {
				return false;
			}
			$curves = openssl_get_curve_names();
			if ( ! is_array( $curves ) || ! in_array( 'prime256v1', $curves, true ) ) {
				return false;
			}
			return in_array( 'aes-128-gcm', array_map( 'strtolower', (array) openssl_get_cipher_methods() ), true );
		}

		/**
		 * @param string $bin Raw bytes.
		 * @return string base64url without padding.
		 */
		public static function b64url_encode( $bin ) {
			return rtrim( strtr( base64_encode( (string) $bin ), '+/', '-_' ), '=' );
		}

		/**
		 * @param string $text base64url (padding optional).
		 * @return string Raw bytes; '' when the text is not base64url.
		 */
		public static function b64url_decode( $text ) {
			$text = strtr( trim( (string) $text ), '-_', '+/' );
			if ( '' === $text || preg_match( '#[^A-Za-z0-9+/=]#', $text ) ) {
				return '';
			}
			$pad = strlen( $text ) % 4;
			if ( $pad ) {
				$text .= str_repeat( '=', 4 - $pad );
			}
			$out = base64_decode( $text, true );
			return false === $out ? '' : $out;
		}

		/**
		 * A new P-256 key pair.
		 *
		 * @return array{public:string,private:string}|null Raw: 65-byte uncompressed point, 32-byte scalar.
		 */
		public static function create_keys() {
			$key = openssl_pkey_new(
				array(
					'curve_name'       => 'prime256v1',
					'private_key_type' => OPENSSL_KEYTYPE_EC,
				)
			);
			if ( ! $key ) {
				return null;
			}
			$details = openssl_pkey_get_details( $key );
			if ( empty( $details['ec']['x'] ) || empty( $details['ec']['y'] ) || empty( $details['ec']['d'] ) ) {
				return null;
			}
			return array(
				'public'  => "\x04" . self::pad32( $details['ec']['x'] ) . self::pad32( $details['ec']['y'] ),
				'private' => self::pad32( $details['ec']['d'] ),
			);
		}

		/**
		 * @param string $bytes Big-endian number.
		 * @return string Exactly 32 bytes.
		 */
		private static function pad32( $bytes ) {
			$bytes = ltrim( (string) $bytes, "\0" );
			return str_pad( $bytes, 32, "\0", STR_PAD_LEFT );
		}

		/**
		 * @param string $raw65 Uncompressed P-256 point.
		 * @return string PEM.
		 */
		public static function public_pem( $raw65 ) {
			$der = hex2bin( self::SPKI_PREFIX ) . $raw65;
			return "-----BEGIN PUBLIC KEY-----\n" . chunk_split( base64_encode( $der ), 64, "\n" ) . "-----END PUBLIC KEY-----\n";
		}

		/**
		 * @param string $d32   Private scalar.
		 * @param string $pub65 Its public point.
		 * @return string PEM.
		 */
		public static function private_pem( $d32, $pub65 ) {
			$der = hex2bin( self::SEC1_PREFIX ) . $d32 . hex2bin( self::SEC1_MIDDLE ) . $pub65;
			return "-----BEGIN EC PRIVATE KEY-----\n" . chunk_split( base64_encode( $der ), 64, "\n" ) . "-----END EC PRIVATE KEY-----\n";
		}

		/**
		 * Whether $raw is a P-256 public point: 65 bytes, uncompressed, on the
		 * curve (OpenSSL refuses to load a point that is not on it).
		 *
		 * @param string $raw Raw bytes.
		 * @return bool
		 */
		public static function valid_public( $raw ) {
			if ( ! is_string( $raw ) || 65 !== strlen( $raw ) || "\x04" !== $raw[0] ) {
				return false;
			}
			return false !== @openssl_pkey_get_public( self::public_pem( $raw ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- a bad point only means "not valid".
		}

		/**
		 * ECDH shared secret.
		 *
		 * @param mixed  $private_key OpenSSL private key.
		 * @param string $peer_raw65  Peer's public point.
		 * @return string|null 32 bytes.
		 */
		private static function ecdh( $private_key, $peer_raw65 ) {
			$peer = @openssl_pkey_get_public( self::public_pem( $peer_raw65 ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- checked below.
			if ( ! $peer ) {
				return null;
			}
			$secret = openssl_pkey_derive( $peer, $private_key, 32 );
			return ( is_string( $secret ) && 32 === strlen( $secret ) ) ? $secret : null;
		}

		/**
		 * Encrypts a push message for one subscription (RFC 8291, aes128gcm).
		 *
		 * @param string     $plain     Payload.
		 * @param string     $ua_public Subscription's p256dh, raw 65 bytes.
		 * @param string     $auth      Subscription's auth secret, raw 16 bytes.
		 * @param int        $pad_to    Pad the record to this many bytes (hides the payload length).
		 * @param array|null $fixed     Tests only: ['private' => d32, 'public' => pub65, 'salt' => 16 bytes].
		 * @return string|null Request body; null when the key or the size is wrong.
		 */
		public static function encrypt( $plain, $ua_public, $auth, $pad_to = 0, $fixed = null ) {
			if ( ! is_string( $ua_public ) || 65 !== strlen( $ua_public ) || ! is_string( $auth ) || 16 !== strlen( $auth ) ) {
				return null;
			}
			if ( is_array( $fixed ) ) {
				$as_public  = $fixed['public'];
				$as_private = openssl_pkey_get_private( self::private_pem( $fixed['private'], $fixed['public'] ) );
				$salt       = $fixed['salt'];
			} else {
				$as_private = openssl_pkey_new(
					array(
						'curve_name'       => 'prime256v1',
						'private_key_type' => OPENSSL_KEYTYPE_EC,
					)
				);
				$details    = $as_private ? openssl_pkey_get_details( $as_private ) : null;
				if ( empty( $details['ec']['x'] ) || empty( $details['ec']['y'] ) ) {
					return null;
				}
				$as_public = "\x04" . self::pad32( $details['ec']['x'] ) . self::pad32( $details['ec']['y'] );
				$salt      = random_bytes( 16 );
			}
			if ( ! $as_private ) {
				return null;
			}

			$ecdh = self::ecdh( $as_private, $ua_public );
			if ( null === $ecdh ) {
				return null;
			}

			// RFC 8291 3.3 and 3.4: the input key, then RFC 8188's content key and nonce.
			$ikm   = hash_hkdf( 'sha256', $ecdh, 32, "WebPush: info\0" . $ua_public . $as_public, $auth );
			$cek   = hash_hkdf( 'sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt );
			$nonce = hash_hkdf( 'sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt );

			// One record: the payload, the 0x02 "last record" delimiter, then zeros.
			$record = (string) $plain . "\x02";
			if ( $pad_to > strlen( $record ) ) {
				$record .= str_repeat( "\0", $pad_to - strlen( $record ) );
			}
			if ( strlen( $record ) + 16 > self::RECORD_SIZE ) {
				return null;
			}

			$tag    = '';
			$cipher = openssl_encrypt( $record, 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16 );
			if ( false === $cipher || 16 !== strlen( $tag ) ) {
				return null;
			}

			// Header: salt (16) | record size (4) | key id length (1) | key id = sender's public key (65).
			return $salt . pack( 'N', self::RECORD_SIZE ) . chr( 65 ) . $as_public . $cipher . $tag;
		}

		/**
		 * The sender token (VAPID, RFC 8292): an ES256 JWT.
		 *
		 * @param string $audience scheme://host[:port] of the push service.
		 * @param string $subject  Contact: an https URL or a mailto: address.
		 * @param string $d32      Signing key, raw private scalar.
		 * @param string $pub65    Its public point.
		 * @param int    $expires  Unix time, at most 24 hours ahead.
		 * @return string|null
		 */
		public static function vapid_jwt( $audience, $subject, $d32, $pub65, $expires ) {
			$header = self::b64url_encode( '{"typ":"JWT","alg":"ES256"}' );
			$claims = self::b64url_encode(
				(string) json_encode(
					array(
						'aud' => (string) $audience,
						'exp' => (int) $expires,
						'sub' => (string) $subject,
					),
					JSON_UNESCAPED_SLASHES
				)
			);
			$input = $header . '.' . $claims;
			$key   = openssl_pkey_get_private( self::private_pem( $d32, $pub65 ) );
			$der   = '';
			if ( ! $key || ! openssl_sign( $input, $der, $key, OPENSSL_ALGO_SHA256 ) ) {
				return null;
			}
			$raw = self::der_to_raw( $der );
			return null === $raw ? null : $input . '.' . self::b64url_encode( $raw );
		}

		/**
		 * The Authorization header value for a token.
		 *
		 * @param string $jwt   Token from vapid_jwt().
		 * @param string $pub65 Signing key's public point.
		 * @return string
		 */
		public static function vapid_header( $jwt, $pub65 ) {
			return 'vapid t=' . $jwt . ', k=' . self::b64url_encode( $pub65 );
		}

		/**
		 * scheme://host[:port] of an endpoint: the token's audience.
		 *
		 * @param string $endpoint Push endpoint URL.
		 * @return string '' when the URL has no scheme or host.
		 */
		public static function audience( $endpoint ) {
			$parts = function_exists( 'wp_parse_url' ) ? wp_parse_url( $endpoint ) : parse_url( $endpoint ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- outside WordPress in tests.
			if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
				return '';
			}
			return strtolower( $parts['scheme'] ) . '://' . strtolower( $parts['host'] ) . ( isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '' );
		}

		/**
		 * OpenSSL's DER ECDSA signature (SEQUENCE of two INTEGERs) as JOSE's raw r || s.
		 *
		 * @param string $der DER signature.
		 * @return string|null 64 bytes.
		 */
		public static function der_to_raw( $der ) {
			$len = strlen( (string) $der );
			if ( $len < 8 || 0x30 !== ord( $der[0] ) ) {
				return null;
			}
			$pos = 2;
			if ( ord( $der[1] ) & 0x80 ) {
				$pos += ord( $der[1] ) & 0x7f;
			}
			$out = '';
			for ( $i = 0; $i < 2; $i++ ) {
				if ( $pos + 2 > $len || 0x02 !== ord( $der[ $pos ] ) ) {
					return null;
				}
				$part = ltrim( substr( $der, $pos + 2, ord( $der[ $pos + 1 ] ) ), "\0" );
				$pos += 2 + ord( $der[ $pos + 1 ] );
				if ( strlen( $part ) > 32 ) {
					return null;
				}
				$out .= str_pad( $part, 32, "\0", STR_PAD_LEFT );
			}
			return $out;
		}

		/**
		 * Raw r || s back to DER (tests check signatures with openssl_verify).
		 *
		 * @param string $raw 64 bytes.
		 * @return string|null
		 */
		public static function raw_to_der( $raw ) {
			if ( 64 !== strlen( (string) $raw ) ) {
				return null;
			}
			$seq = '';
			foreach ( array( substr( $raw, 0, 32 ), substr( $raw, 32 ) ) as $part ) {
				$part = ltrim( $part, "\0" );
				if ( '' === $part || ord( $part[0] ) & 0x80 ) {
					$part = "\0" . $part;
				}
				$seq .= "\x02" . chr( strlen( $part ) ) . $part;
			}
			return "\x30" . chr( strlen( $seq ) ) . $seq;
		}
	}
}
