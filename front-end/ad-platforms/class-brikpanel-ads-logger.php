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

	/** Severity for an entry that reports a real failure. */
	const SEVERITY_ERROR = 'error';

	/** Severity for routine activity that is not a failure. */
	const SEVERITY_INFO = 'info';

	/**
	 * Append a failure entry. Older entries are evicted FIFO.
	 *
	 * @param string $flow    One of: oauth, google, meta, sync, client.
	 * @param string $message Free-form message; redacted before storage.
	 * @param int    $code    HTTP status code if applicable; 0 otherwise.
	 * @param array  $context Optional extra fields (kept tiny). All scalar values.
	 */
	public static function log( $flow, $message, $code = 0, array $context = [] ) {
		self::write( self::SEVERITY_ERROR, $flow, $message, $code, $context );
	}

	/**
	 * Append a routine, non-failure entry.
	 *
	 * Background jobs that correctly decide they have nothing to do (a backfill
	 * chunk queued before the merchant disconnected, a chunk superseded by a
	 * newer backfill) used to land here through log(), which made the settings
	 * card present a dozen "nothing to do" notes as a dozen errors. Merchants
	 * read that as a broken connection and wrote in about it. Same buffer, same
	 * retention — only the severity differs, so the card can label them.
	 *
	 * @param string $flow
	 * @param string $message
	 * @param int    $code
	 * @param array  $context
	 */
	public static function note( $flow, $message, $code = 0, array $context = [] ) {
		self::write( self::SEVERITY_INFO, $flow, $message, $code, $context );
	}

	/**
	 * Shared writer for log() and note().
	 *
	 * @param string $severity self::SEVERITY_ERROR or self::SEVERITY_INFO.
	 * @param string $flow
	 * @param string $message
	 * @param int    $code
	 * @param array  $context
	 */
	private static function write( $severity, $flow, $message, $code, array $context ) {
		$entry = [
			'ts'       => time(),
			'flow'     => (string) $flow,
			'code'     => (int) $code,
			'severity' => $severity === self::SEVERITY_INFO ? self::SEVERITY_INFO : self::SEVERITY_ERROR,
			'message'  => self::redact( (string) $message ),
			'context'  => self::sanitize_context( $context ),
		];

		$log = (array) get_option( self::OPTION, [] );

		// Collapse an immediate repeat instead of appending it. The buffer only
		// holds MAX_ENTRIES, and several call sites are polls: a backfill chunk
		// that skips, a status the API keeps rejecting, or a vault that cannot
		// be decrypted re-logs the identical line every pass and evicts the
		// whole history — including the OAuth failure that explains why the
		// sync stopped — within minutes. Keep the newest timestamp and count
		// the repeats so the signal survives. Ported from
		// Brikpanel_Sheets_Logger, with severity added to the comparison
		// because this buffer carries two of them.
		$last_idx = count( $log ) - 1;
		if ( $last_idx >= 0
			&& isset( $log[ $last_idx ] )
			&& is_array( $log[ $last_idx ] )
			&& ( $log[ $last_idx ]['message'] ?? null ) === $entry['message']
			&& ( $log[ $last_idx ]['flow'] ?? null ) === $entry['flow']
			&& (int) ( $log[ $last_idx ]['code'] ?? 0 ) === $entry['code']
			&& self::severity_of( $log[ $last_idx ] ) === $entry['severity']
		) {
			$log[ $last_idx ]['ts']    = $entry['ts'];
			$log[ $last_idx ]['count'] = (int) ( $log[ $last_idx ]['count'] ?? 1 ) + 1;
		} else {
			$log[] = $entry;
		}

		if ( count( $log ) > self::MAX_ENTRIES ) {
			$log = array_slice( $log, -self::MAX_ENTRIES );
		}
		update_option( self::OPTION, $log, false );
	}

	/**
	 * Severity of a stored entry. Entries written before severities existed
	 * carry no field; they were all written by log(), so they are errors.
	 *
	 * @param array $entry
	 * @return string
	 */
	public static function severity_of( array $entry ) {
		return isset( $entry['severity'] ) && $entry['severity'] === self::SEVERITY_INFO
			? self::SEVERITY_INFO
			: self::SEVERITY_ERROR;
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
