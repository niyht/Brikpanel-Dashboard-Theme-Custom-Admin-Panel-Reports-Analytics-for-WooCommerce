<?php
/**
 * BrikPanel — What a merchant is told when the connection service cannot help
 * (shared by the Ad Platforms and Google Sheets modules).
 *
 * Both modules start their OAuth handshake by asking the BrikPanel proxy on
 * brksoft.com for the sign-in address, and finish it by asking the proxy for
 * the tokens. Until 3.3.27 a failed call ended in a 3.5 second toast, "Could
 * not reach the BrikPanel proxy. Please try again in a moment.", while the real
 * cause (a cURL error) went only into the module's log. A merchant whose host
 * blocks outgoing connections was told to try again, forever, and wrote in. The
 * 502 that carried the toast fared worse behind Cloudflare or an nginx front:
 * they replace a 502 body with their own page, and the merchant got a sentence
 * written for a different button.
 *
 * This class turns a failed proxy call into:
 *   - a machine code + kind, safe to hand to the browser,
 *   - a headline, a plain reason and the next step, built when the answer or
 *     the page is produced, in the viewer's language,
 *   - the raw technical detail, so the merchant can forward it to the host.
 *
 * It also owns the second attempt (IPv4 only: brksoft.com publishes an IPv6
 * address since 2026-09-22, and a broken IPv6 path stalls a request that IPv4
 * would carry) and the one-time record a failed OAuth return leaves for the
 * settings page.
 *
 * @package BrikPanel
 * @since   3.3.27
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Brikpanel_Proxy_Errors' ) ) {

	class Brikpanel_Proxy_Errors {

		/** A connect click makes at most this many requests. */
		const MAX_ATTEMPTS = 2;

		/**
		 * Seconds one start request may take. Connecting gives up after 10 s
		 * (WordPress' default), and the proxy answers in well under a second;
		 * 15 keeps both attempts inside the 30 s many hosts allow a request.
		 */
		const START_TIMEOUT = 15;

		/** Seconds a failed OAuth return stays readable by the next page load. */
		const FLASH_TTL = 300;

		/** Longest technical detail shown or stored, in characters. */
		const DETAIL_MAX = 300;

		/** cURL errors that mean the secure connection itself failed. */
		const CURL_SSL = [ 35, 53, 54, 58, 59, 60, 64, 66, 77, 80, 82, 83, 90, 91 ];

		/** cURL errors that mean the connection was refused, reset or lost. */
		const CURL_CONNECT = [ 7, 52, 55, 56 ];

		/**
		 * Which attempt the browser says this is, clamped to 1..MAX_ATTEMPTS.
		 * The caller has already checked the nonce.
		 *
		 * @return int
		 */
		public static function attempt_from_request() {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by the calling AJAX handler.
			$raw = isset( $_POST['attempt'] ) ? wp_unslash( $_POST['attempt'] ) : 1;
			$n   = is_scalar( $raw ) ? (int) $raw : 1;
			return max( 1, min( self::MAX_ATTEMPTS, $n ) );
		}

		/**
		 * The host the merchant should name to their hosting provider. Read off
		 * the module's proxy base, so a staging override shows its own host.
		 *
		 * @param string $base
		 * @return string
		 */
		public static function host( $base ) {
			$host = wp_parse_url( (string) $base, PHP_URL_HOST );
			return is_string( $host ) && $host !== '' ? $host : 'brksoft.com';
		}

		/**
		 * wp_remote_post() to the proxy. With $force_ipv4 the request is made
		 * over IPv4 only. The hook is scoped to this one URL prefix and removed
		 * again whatever happens, so no other request on the site is touched.
		 *
		 * @param string $url
		 * @param array  $args
		 * @param string $base       The module's proxy base URL.
		 * @param bool   $force_ipv4
		 * @return array|WP_Error
		 */
		public static function post( $url, array $args, $base, $force_ipv4 = false ) {
			$hook = null;
			$base = (string) $base;
			if ( $force_ipv4 && $base !== '' && defined( 'CURLOPT_IPRESOLVE' ) && defined( 'CURL_IPRESOLVE_V4' ) ) {
				$hook = static function ( $handle, $request, $request_url ) use ( $base ) {
					if ( strpos( (string) $request_url, $base ) === 0 ) {
						curl_setopt( $handle, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- WordPress has no API for the address family.
					}
				};
				add_action( 'http_api_curl', $hook, 10, 3 );
			}
			try {
				return wp_remote_post( $url, $args );
			} finally {
				if ( $hook ) {
					remove_action( 'http_api_curl', $hook, 10 );
				}
			}
		}

		/**
		 * Sort a failed proxy call. No sentences here: those are built by
		 * texts(), when and where they are shown.
		 *
		 * @param array|WP_Error $resp The wp_remote_* result.
		 * @param array          $open The module proxy's open() result.
		 * @return array{0:string,1:string,2:string} [code, kind, detail]
		 */
		public static function classify( $resp, array $open ) {
			if ( is_wp_error( $resp ) ) {
				$err_code = (string) $resp->get_error_code();
				$message  = trim( wp_strip_all_tags( (string) $resp->get_error_message() ) );
				$detail   = ( $err_code === '' || $err_code === 'http_request_failed' ) ? $message : $err_code . ': ' . $message;
				return [ 'proxy_unreachable', self::network_kind( $err_code, $message ), self::trim_detail( $detail ) ];
			}

			$http  = (int) ( $open['code'] ?? 0 );
			$error = (string) ( $open['error'] ?? '' );
			$said  = self::proxy_message( $open );

			if ( $http === 429 ) {
				return [ 'rate_limited', 'rate_limited', self::trim_detail( 'HTTP 429' . ( $said !== '' ? ': ' . $said : '' ) ) ];
			}
			if ( $error === 'stale' ) {
				return [ 'proxy_rejected', 'clock', self::trim_detail( 'HTTP ' . $http . ': stale, ' . gmdate( 'Y-m-d H:i' ) . ' UTC' ) ];
			}
			if ( in_array( $error, [ 'unsigned', 'bad_sig', 'malformed' ], true ) ) {
				return [ 'proxy_rejected', 'unverified', self::trim_detail( 'HTTP ' . $http . ': ' . $error ) ];
			}
			return [ 'proxy_error', 'http', self::trim_detail( 'HTTP ' . $http . ( $said !== '' ? ': ' . $said : '' ) ) ];
		}

		/**
		 * Headline, reason and next step for a classified failure.
		 *
		 * @param string $code  From classify().
		 * @param string $kind  From classify().
		 * @param string $stage 'start' (the connect click) or 'finish' (back from the sign-in page).
		 * @param string $host
		 * @return array{0:string,1:string,2:string} [message, reason, help]
		 */
		public static function texts( $code, $kind, $stage, $host ) {
			$host = (string) $host;

			/* translators: %s: host name of the BrikPanel connection service, for example brksoft.com. */
			$help_network = sprintf( __( 'Try again in a few minutes. If it keeps happening, ask your hosting provider to allow this site to connect to %s.', 'brikpanel' ), $host );
			/* translators: %s: plugin name, BrikPanel. */
			$help_service = sprintf( __( 'Try again in a few minutes. If it keeps happening, contact %s support.', 'brikpanel' ), 'BrikPanel' );

			if ( $code === 'proxy_unreachable' ) {
				/* translators: %s: host name of the BrikPanel connection service, for example brksoft.com. */
				$message = sprintf( __( 'Your site could not reach %s.', 'brikpanel' ), $host );
				switch ( $kind ) {
					case 'timeout':
						/* translators: %s: host name of the BrikPanel connection service, for example brksoft.com. */
						return [ $message, sprintf( __( '%s did not answer in time.', 'brikpanel' ), $host ), $help_network ];
					case 'dns':
						/* translators: %s: host name of the BrikPanel connection service, for example brksoft.com. */
						return [ $message, sprintf( __( 'Your server could not find the address of %s.', 'brikpanel' ), $host ), $help_network ];
					case 'connect':
						/* translators: %s: host name of the BrikPanel connection service, for example brksoft.com. */
						return [ $message, sprintf( __( 'Your server could not open a connection to %s, or the connection was cut off.', 'brikpanel' ), $host ), $help_network ];
					case 'ssl':
						/* translators: %s: host name of the BrikPanel connection service, for example brksoft.com. */
						return [ $message, sprintf( __( 'Your server could not make a secure connection to %s. Its security certificates may be out of date.', 'brikpanel' ), $host ), $help_network ];
					case 'blocked':
						return [
							$message,
							/* translators: %s: host name of the BrikPanel connection service, for example brksoft.com. */
							sprintf( __( 'This site is set to block connections to other websites, so it cannot reach %s.', 'brikpanel' ), $host ),
							/* translators: %s: host name of the BrikPanel connection service, for example brksoft.com. */
							sprintf( __( 'Ask your developer or hosting provider to allow this site to connect to %s.', 'brikpanel' ), $host ),
						];
				}
				/* translators: %s: host name of the BrikPanel connection service, for example brksoft.com. */
				return [ $message, sprintf( __( 'Your server could not connect to %s.', 'brikpanel' ), $host ), $help_network ];
			}

			if ( $code === 'rate_limited' ) {
				return [
					__( 'Too many connection attempts.', 'brikpanel' ),
					/* translators: %s: host name of the BrikPanel connection service, for example brksoft.com. */
					sprintf( __( '%s received too many connection attempts from your server in a short time.', 'brikpanel' ), $host ),
					__( 'Wait a few minutes, then try again.', 'brikpanel' ),
				];
			}

			$message = $stage === 'finish'
				? __( 'The connection was not completed.', 'brikpanel' )
				: __( 'The connection could not be started.', 'brikpanel' );

			if ( $kind === 'clock' ) {
				return [
					$message,
					/* translators: %s: host name of the BrikPanel connection service, for example brksoft.com. */
					sprintf( __( 'The reply from %s was refused because the clock on your server seems to be wrong.', 'brikpanel' ), $host ),
					__( 'Ask your hosting provider to check the date and time on your server, then try again.', 'brikpanel' ),
				];
			}
			if ( $kind === 'unverified' ) {
				/* translators: %s: host name of the BrikPanel connection service, for example brksoft.com. */
				return [ $message, sprintf( __( 'The reply from %s could not be verified, so it was not used.', 'brikpanel' ), $host ), $help_service ];
			}
			/* translators: %s: host name of the BrikPanel connection service, for example brksoft.com. */
			return [ $message, sprintf( __( '%s answered with an error.', 'brikpanel' ), $host ), $help_service ];
		}

		/**
		 * The error answer for a failed connect click (sent with HTTP 200, see
		 * the modules' ajax_start()).
		 *
		 * @param array|WP_Error $resp
		 * @param array          $open
		 * @param string         $base
		 * @param string         $stage
		 * @param int            $attempt
		 * @return array
		 */
		public static function payload( $resp, array $open, $base, $stage, $attempt ) {
			list( $code, $kind, $detail )    = self::classify( $resp, $open );
			list( $message, $reason, $help ) = self::texts( $code, $kind, $stage, self::host( $base ) );
			return [
				'code'    => $code,
				'kind'    => $kind,
				'message' => $message,
				'reason'  => $reason,
				'help'    => $help,
				'detail'  => $detail,
				// Only a network failure is worth a second request. A refused or
				// unverified answer would be the same again, a 429 would only
				// spend another of the five attempts the proxy allows, and a site
				// that blocks outgoing requests blocks the second one too.
				'retry'   => $code === 'proxy_unreachable' && $kind !== 'blocked' && (int) $attempt < self::MAX_ATTEMPTS,
				'attempt' => (int) $attempt,
			];
		}

		/**
		 * Leave a failed OAuth return for the next page load of the current
		 * user. Only the record travels: the sentence is built on that page,
		 * and nothing a link could forge reaches the box.
		 *
		 * @param string $prefix Module-specific transient prefix.
		 * @param array  $record ['code','kind','detail'] or ['message'], plus optional 'platform'.
		 */
		public static function store_flash( $prefix, array $record ) {
			$user = get_current_user_id();
			if ( $user > 0 ) {
				set_transient( $prefix . $user, $record, self::FLASH_TTL );
			}
		}

		/**
		 * Read and forget the current user's flash record.
		 *
		 * @param string $prefix
		 * @return array
		 */
		public static function take_flash( $prefix ) {
			$user = get_current_user_id();
			if ( $user <= 0 ) {
				return [];
			}
			$key    = $prefix . $user;
			$record = get_transient( $key );
			if ( $record !== false ) {
				delete_transient( $key );
			}
			return is_array( $record ) ? $record : [];
		}

		/**
		 * The parts of the connect error box for a flash record.
		 *
		 * @param array  $record From take_flash().
		 * @param string $base
		 * @return array{title?:string,reason?:string,help?:string,detail?:string} Empty when there is nothing to show.
		 */
		public static function flash_box( array $record, $base ) {
			$code = isset( $record['code'] ) ? (string) $record['code'] : '';
			if ( $code !== '' ) {
				list( $message, $reason, $help ) = self::texts( $code, (string) ( $record['kind'] ?? '' ), 'finish', self::host( $base ) );
				return [
					'title'  => $message,
					'reason' => $reason,
					'help'   => $help,
					'detail' => self::trim_detail( (string) ( $record['detail'] ?? '' ) ),
				];
			}
			$message = isset( $record['message'] ) ? trim( (string) $record['message'] ) : '';
			return $message === '' ? [] : [ 'title' => '', 'reason' => $message, 'help' => '', 'detail' => '' ];
		}

		/**
		 * Strings both modules' scripts use for the connect flow. Merged into
		 * each module's localized i18n bag, so the two stay word for word alike.
		 *
		 * @param string $host
		 * @return array<string,string>
		 */
		public static function js_strings( $host ) {
			return [
				'still_trying'         => __( 'Still trying…', 'brikpanel' ),
				/* translators: %s: plugin name, BrikPanel. */
				'server_error_generic' => sprintf( __( 'Your server stopped the request before %s could finish. Please try again.', 'brikpanel' ), 'BrikPanel' ),
				'connect_cut_off'      => __( 'Your server stopped the request before the connection could start.', 'brikpanel' ),
				'connect_failed_title' => __( 'The connection could not be started.', 'brikpanel' ),
				/* translators: %s: host name of the BrikPanel connection service, for example brksoft.com. */
				'connect_help'         => sprintf( __( 'Try again in a few minutes. If it keeps happening, ask your hosting provider to allow this site to connect to %s.', 'brikpanel' ), (string) $host ),
				'session_expired'      => __( 'Your session expired. Reload the page and try again.', 'brikpanel' ),
			];
		}

		/**
		 * Turn the OAuth error a consent screen reports (RFC 6749 4.1.2.1) into
		 * a sentence the merchant can act on. Unknown codes pass through, so a
		 * real cause is never swallowed.
		 *
		 * @param string $err     The error code the proxy passed back.
		 * @param string $service 'ads' or 'sheets', for the one sentence that names the service.
		 * @return string
		 */
		public static function consent_error_message( $err, $service = 'ads' ) {
			$err = trim( (string) $err );
			switch ( strtolower( $err ) ) {
				case 'access_denied':
				case 'user_denied':
					return __( 'Connection cancelled: the permission request was declined on the platform’s consent screen. Nothing was saved. Click Connect to try again.', 'brikpanel' );
				case 'consent_required':
				case 'interaction_required':
					return __( 'The platform needs you to complete the consent screen. Click Connect to try again.', 'brikpanel' );
				case 'server_error':
				case 'temporarily_unavailable':
					return $service === 'sheets'
						? __( 'Google is temporarily unavailable. Please try connecting again in a few minutes.', 'brikpanel' )
						: __( 'The advertising platform is temporarily unavailable. Please try connecting again in a few minutes.', 'brikpanel' );
			}
			if ( $err === '' ) {
				return __( 'The connection did not complete. Nothing was saved. Please try again.', 'brikpanel' );
			}
			/* translators: %s = raw error code reported by the advertising platform. */
			return sprintf( __( 'The connection did not complete (%s). Nothing was saved. Please try again.', 'brikpanel' ), $err );
		}

		/**
		 * Which network failure a WP_Error describes. The cURL number decides
		 * when there is one; other transports only leave words.
		 *
		 * @param string $err_code
		 * @param string $message
		 * @return string timeout|dns|connect|ssl|blocked|other
		 */
		private static function network_kind( $err_code, $message ) {
			if ( $err_code === 'http_request_not_executed' ) {
				return 'blocked'; // WP_HTTP_BLOCK_EXTERNAL, or a filter refusing the host.
			}
			$m    = strtolower( (string) $message );
			$curl = preg_match( '/curl error (\d+)/', $m, $hit ) ? (int) $hit[1] : 0;

			if ( $curl === 6 || ( $curl === 28 && strpos( $m, 'resolving' ) !== false ) ) {
				return 'dns';
			}
			if ( $curl === 28 ) {
				return 'timeout';
			}
			if ( in_array( $curl, self::CURL_SSL, true ) ) {
				return 'ssl';
			}
			if ( in_array( $curl, self::CURL_CONNECT, true ) ) {
				return 'connect';
			}

			if ( strpos( $m, 'resolve' ) !== false || strpos( $m, 'getaddrinfo' ) !== false || strpos( $m, 'name or service not known' ) !== false ) {
				return 'dns';
			}
			if ( strpos( $m, 'timed out' ) !== false || strpos( $m, 'timeout' ) !== false ) {
				return 'timeout';
			}
			// Before the SSL words: the fsockopen transport names the address as
			// "ssl://brksoft.com:443" even when the connection was simply refused.
			if ( strpos( $m, 'refused' ) !== false || strpos( $m, 'reset' ) !== false || strpos( $m, 'unreachable' ) !== false || strpos( $m, 'failed to connect' ) !== false ) {
				return 'connect';
			}
			if ( preg_match( '#\b(ssl|tls|certificate)\b(?!://)#', $m ) ) {
				return 'ssl';
			}
			return 'other';
		}

		/** The proxy's own "message", as plain text. */
		private static function proxy_message( array $open ) {
			$data = $open['data'] ?? [];
			if ( ! is_array( $data ) || ! isset( $data['message'] ) || ! is_scalar( $data['message'] ) ) {
				return '';
			}
			return trim( wp_strip_all_tags( (string) $data['message'] ) );
		}

		/** One line of plain text, no longer than DETAIL_MAX characters. */
		private static function trim_detail( $text ) {
			$text = trim( (string) preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) $text ) ) );
			if ( function_exists( 'brikpanel_strlen' ) && function_exists( 'brikpanel_substr' ) ) {
				return brikpanel_strlen( $text ) > self::DETAIL_MAX ? brikpanel_substr( $text, 0, self::DETAIL_MAX - 1 ) . '…' : $text;
			}
			return strlen( $text ) > self::DETAIL_MAX ? substr( $text, 0, self::DETAIL_MAX - 1 ) . '…' : $text;
		}
	}
}
