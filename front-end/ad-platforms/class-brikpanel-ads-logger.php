<?php
/**
 * BrikPanel — Ad Platforms Logger (ring buffer + redaction).
 *
 * Stores the last N error entries in a single autoload=no option for the
 * "Recent errors" card on the settings page. Every message is passed through
 * a redaction pass that strips OAuth bearer headers, access/refresh tokens,
 * developer tokens, and authorization codes so secrets never land in
 * wp_options.
 *
 * @package BrikPanel
 * @since   3.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Brikpanel_Ads_Logger {

	/** Option key holding the ring buffer. autoload=no. */
	const OPTION = 'brikpanel_ads_error_log';

	/** Maximum number of entries kept in the buffer. */
	const MAX_ENTRIES = 100;

	/**
	 * Append a log entry. Older entries are evicted FIFO.
	 *
	 * @param string $flow    One of: oauth, google, meta, sync, client.
	 * @param string $message Free-form message; redacted before storage.
	 * @param int    $code    HTTP status code if applicable; 0 otherwise.
	 * @param array  $context Optional extra fields (kept tiny). All scalar values.
	 */
	public static function log( $flow, $message, $code = 0, array $context = [] ) {
		$entry = [
			'ts'      => time(),
			'flow'    => (string) $flow,
			'code'    => (int) $code,
			'message' => self::redact( (string) $message ),
			'context' => self::sanitize_context( $context ),
		];

		$log = (array) get_option( self::OPTION, [] );
		$log[] = $entry;
		if ( count( $log ) > self::MAX_ENTRIES ) {
			$log = array_slice( $log, -self::MAX_ENTRIES );
		}
		update_option( self::OPTION, $log, false );
	}

	/**
	 * Convenience: log an error from a wp_remote_* failure or an API error body.
	 *
	 * @param string                $flow
	 * @param string                $action  Short operation name.
	 * @param WP_Error|array|string $error   wp_remote response (array), WP_Error, or string.
	 * @param int                   $code    Optional HTTP status; auto-extracted from arrays.
	 */
	public static function log_request_error( $flow, $action, $error, $code = 0 ) {
		$msg = $action . ': ';
		if ( is_wp_error( $error ) ) {
			$msg .= $error->get_error_code() . ': ' . $error->get_error_message();
		} elseif ( is_array( $error ) ) {
			if ( $code === 0 && isset( $error['response']['code'] ) ) {
				$code = (int) $error['response']['code'];
			}
			$body = wp_remote_retrieve_body( $error );
			$msg .= ( $body === '' ? '(empty body)' : $body );
		} else {
			$msg .= (string) $error;
		}
		self::log( $flow, $msg, $code );
	}

	/**
	 * Return the most recent N entries (newest first).
	 *
	 * @param int $limit
	 * @return array<int, array{ts:int,flow:string,code:int,message:string,context:array}>
	 */
	public static function recent( $limit = 50 ) {
		$log = (array) get_option( self::OPTION, [] );
		$log = array_reverse( $log );
		return array_slice( $log, 0, max( 1, (int) $limit ) );
	}

	/** Wipe the buffer. */
	public static function clear() {
		update_option( self::OPTION, [], false );
	}

	/**
	 * Redact secrets out of a string before persisting.
	 *
	 * Covers OAuth and ad-platform-specific token shapes:
	 *  - "Authorization: Bearer <token>"
	 *  - JSON "access_token" / "refresh_token" / "id_token" / "code" / "developer-token" values
	 *  - Raw `ya29.*` Google access token shapes
	 *  - `1//` refresh token shape
	 *  - `4/0A...` Google auth code shape
	 *  - `EAA...` Meta long-lived access token shape
	 *  - `developer-token: <token>` headers
	 *
	 * @param string $msg
	 * @return string
	 */
	public static function redact( $msg ) {
		$msg = (string) $msg;
		if ( $msg === '' ) {
			return $msg;
		}

		// Authorization: Bearer xxx
		$msg = preg_replace( '/(Bearer\s+)[A-Za-z0-9._\-]{12,}/i', '$1[REDACTED]', $msg );

		// JSON-looking token values.
		$msg = preg_replace(
			'/"(access_token|refresh_token|id_token|code|developer_token|developer-token|client_secret|app_secret)"\s*:\s*"[^"]{6,}"/i',
			'"$1":"[REDACTED]"',
			$msg
		);

		// Google developer-token / login-customer-id headers.
		$msg = preg_replace( '/(developer-token:\s*)[A-Za-z0-9._\-]{12,}/i', '$1[REDACTED]', $msg );

		// Google raw token shapes.
		$msg = preg_replace( '/\bya29\.[A-Za-z0-9._\-]{20,}/', '[REDACTED-ya29]', $msg );
		$msg = preg_replace( '#\b1//[A-Za-z0-9._\-]{20,}#', '[REDACTED-rt]', $msg );
		$msg = preg_replace( '#\b4/0[A-Za-z0-9._\-]{20,}#', '[REDACTED-code]', $msg );

		// Meta long-lived access tokens (start with "EAA"). Be careful not to
		// chew up any 3-letter word — require a meaningful tail length.
		$msg = preg_replace( '/\bEAA[A-Za-z0-9_\-]{40,}/', '[REDACTED-meta-tok]', $msg );

		return $msg;
	}

	/**
	 * Cap context to scalar values only and redact each one.
	 *
	 * @param array $ctx
	 * @return array<string, scalar|null>
	 */
	private static function sanitize_context( array $ctx ) {
		$out = [];
		foreach ( $ctx as $k => $v ) {
			$key = is_string( $k ) ? substr( $k, 0, 40 ) : (string) $k;
			if ( is_scalar( $v ) || $v === null ) {
				$out[ $key ] = is_string( $v ) ? self::redact( $v ) : $v;
			} else {
				$out[ $key ] = '(' . gettype( $v ) . ')';
			}
		}
		return $out;
	}
}
