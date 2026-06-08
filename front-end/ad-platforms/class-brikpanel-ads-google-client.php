<?php
/**
 * BrikPanel — Google Ads API client (proxy-tunnelled).
 *
 * Every call to googleads.googleapis.com is routed through the WPCode proxy
 * on brksoft.com. The proxy injects the `developer-token` header (sourced
 * from a wp-config.php constant on the proxy side) so this code — which
 * ships to thousands of WP installs — never sees the developer token.
 *
 * The plugin sends only:
 *   - The user's access_token (refreshed automatically by the token vault)
 *   - The query / endpoint path
 *   - Optional login_customer_id (for MCC users)
 *
 * The proxy validates the request, forwards to Google with the developer
 * token attached, and returns the parsed response body. From this code's
 * perspective the proxy is the API.
 *
 * For the MVP we only need two operations:
 *   - list_accounts(): enumerate accounts the user can read from. Used by
 *     the settings page to populate the "Primary account" dropdown.
 *   - fetch_spend(): pull daily total cost_micros for a date range. The
 *     only metrics we read are date + cost + impressions + clicks.
 *
 * @package BrikPanel
 * @since   3.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Brikpanel_Ads_Google_Exception extends \RuntimeException {
	/** @var int */
	public $http_code = 0;
	/** @var string */
	public $api_reason = '';

	public function __construct( $message, $http_code = 0, $api_reason = '' ) {
		parent::__construct( $message );
		$this->http_code  = (int) $http_code;
		$this->api_reason = (string) $api_reason;
	}
}

class Brikpanel_Ads_Google_Client {

	/** Maximum HTTP attempts per request. */
	const MAX_ATTEMPTS = 3;

	/**
	 * Backoff schedule (seconds) for retryable infrastructure errors only
	 * (429 / 502 / 503 / 504). Kept short so the settings UI doesn't hang
	 * the merchant for a minute on a misconfigured proxy.
	 */
	const BACKOFF = [ 1, 3, 6 ];

	/**
	 * List accounts the connected user can read from. Returns the
	 * descriptive name + currency for the picker UI.
	 *
	 * Implementation:
	 *   1. /customers:listAccessibleCustomers returns resource names like
	 *      "customers/1234567890" — bare IDs we still have to enrich.
	 *   2. For each customer ID, send a SELECT customer.id, name, currency
	 *      query through searchStream so we can show a human label.
	 *
	 * @return array<int, array{id:string, name:string, currency:string, is_manager:bool}>
	 * @throws Brikpanel_Ads_Google_Exception
	 */
	public function list_accounts() {
		$resp = $this->request( 'POST', '/google-ads/list-accessible-customers', [] );
		$resource_names = isset( $resp['resourceNames'] ) && is_array( $resp['resourceNames'] )
			? $resp['resourceNames']
			: [];

		$out = [];
		foreach ( $resource_names as $resource ) {
			if ( ! preg_match( '#^customers/(\d+)$#', (string) $resource, $m ) ) {
				continue;
			}
			$cid  = $m[1];
			$info = [
				'id'         => $cid,
				'name'       => $cid,
				'currency'   => '',
				'is_manager' => false,
			];
			try {
				// Pass 403 as a "silent" code: it is expected and routine here.
				// The user can see the customer in listAccessibleCustomers but
				// has no direct read access (it is reachable only through a
				// manager). We still surface the row so it can be picked once
				// login_customer_id is set, so a 403 is not an error worth
				// logging. Without this, every Load accounts for an MCC user
				// would flood the "Recent errors" card. request() still logs
				// genuinely unexpected enrichment failures (e.g. 500).
				$detail = $this->request( 'POST', '/google-ads/customer-info', [
					'customer_id' => $cid,
				], [ 403 ] );
				if ( is_array( $detail ) ) {
					$info['name']       = (string) ( $detail['descriptiveName'] ?? $cid );
					$info['currency']   = (string) ( $detail['currencyCode'] ?? '' );
					$info['is_manager'] = ! empty( $detail['manager'] );
				}
			} catch ( Brikpanel_Ads_Google_Exception $e ) {
				// Expected/benign failures (403) are already silenced at the
				// request() layer; anything else was logged there too. Nothing
				// to do here but keep the row with its fallback (bare ID) label.
				unset( $e );
			}
			$out[] = $info;
		}
		return $out;
	}

	/**
	 * Pull daily spend rows for the given customer + date range.
	 *
	 * Returns one row per day in the (account-timezone) date range. Days
	 * with no spend are omitted by Google's API — callers must not assume
	 * a contiguous range.
	 *
	 * @param string $customer_id Bare CID, no dashes.
	 * @param string $start_date  YYYY-MM-DD (inclusive)
	 * @param string $end_date    YYYY-MM-DD (inclusive)
	 * @return array<int, array{date:string, spend_amount:float, spend_currency:string, impressions:int, clicks:int}>
	 * @throws Brikpanel_Ads_Google_Exception
	 */
	public function fetch_spend( $customer_id, $start_date, $end_date ) {
		$customer_id = preg_replace( '/[^0-9]/', '', (string) $customer_id );
		if ( $customer_id === '' ) {
			throw new Brikpanel_Ads_Google_Exception( 'Missing Google Ads customer ID.' );
		}
		if ( ! self::is_valid_date( $start_date ) || ! self::is_valid_date( $end_date ) ) {
			throw new Brikpanel_Ads_Google_Exception( 'Invalid date range.' );
		}

		// Single-line GAQL — no formatting hazards, no user input.
		$query = sprintf(
			"SELECT segments.date, metrics.cost_micros, metrics.impressions, metrics.clicks, customer.currency_code FROM customer WHERE segments.date BETWEEN '%s' AND '%s'",
			$start_date,
			$end_date
		);

		$resp = $this->request( 'POST', '/google-ads/query', [
			'customer_id' => $customer_id,
			'query'       => $query,
		] );

		// searchStream returns an array of response chunks, each with `results`.
		// Some proxies flatten this to a top-level `results` array; handle both.
		$chunks = isset( $resp['chunks'] ) && is_array( $resp['chunks'] )
			? $resp['chunks']
			: [ $resp ];

		$out = [];
		foreach ( $chunks as $chunk ) {
			$results = isset( $chunk['results'] ) && is_array( $chunk['results'] )
				? $chunk['results']
				: [];
			foreach ( $results as $row ) {
				$date   = (string) ( $row['segments']['date'] ?? '' );
				$cost   = (int)    ( $row['metrics']['costMicros'] ?? 0 );
				$imps   = (int)    ( $row['metrics']['impressions'] ?? 0 );
				$clicks = (int)    ( $row['metrics']['clicks'] ?? 0 );
				$curr   = (string) ( $row['customer']['currencyCode'] ?? '' );
				if ( ! self::is_valid_date( $date ) ) {
					continue;
				}
				$out[] = [
					'date'           => $date,
					'spend_amount'   => $cost / 1000000, // cost_micros → currency units
					'spend_currency' => $curr,
					'impressions'    => $imps,
					'clicks'         => $clicks,
					'raw'            => $row,
				];
			}
		}
		return $out;
	}

	// =========================================================================
	// HTTP plumbing
	// =========================================================================

	/**
	 * POST to a proxy endpoint with the user's access token attached.
	 *
	 * The proxy adds developer-token + login-customer-id headers and forwards
	 * to Google. Returns the parsed JSON body on success, throws on terminal
	 * failure.
	 *
	 * @param string $method
	 * @param string $path Relative to BRIKPANEL_ADS_PROXY_BASE (must start with "/").
	 * @param array  $body
	 * @param int[]  $silent_codes HTTP status codes that are an expected outcome
	 *                             for this call and must NOT be written to the
	 *                             error log (the exception is still thrown so the
	 *                             caller can react). Example: customer-info passes
	 *                             [403] because manager-only accounts routinely
	 *                             return it during the account picker enrichment.
	 * @return array
	 * @throws Brikpanel_Ads_Google_Exception
	 */
	private function request( $method, $path, array $body, array $silent_codes = [] ) {
		$attempt        = 0;
		$refreshed_once = false;
		$last_status    = 0;
		$last_message   = '';
		$last_reason    = '';

		if ( Brikpanel_Ads_Proxy::is_killed() ) {
			throw new Brikpanel_Ads_Google_Exception(
				__( 'Google Ads connection was revoked for security. Please reconnect.', 'brikpanel' ),
				0,
				'killswitch'
			);
		}

		while ( $attempt < self::MAX_ATTEMPTS ) {
			$token = Brikpanel_Ads_Tokens::get_access_token( Brikpanel_Ads_Tokens::PLATFORM_GOOGLE );
			if ( $token === null ) {
				throw new Brikpanel_Ads_Google_Exception(
					__( 'Not connected to Google Ads.', 'brikpanel' ),
					0,
					'not_connected'
				);
			}

			$desc              = Brikpanel_Ads_Tokens::describe( Brikpanel_Ads_Tokens::PLATFORM_GOOGLE );
			$login_customer_id = preg_replace( '/[^0-9]/', '', (string) ( $desc['login_customer_id'] ?? '' ) );

			$payload = array_merge( [
				'site_url'           => home_url(),
				'access_token'       => $token,
				'login_customer_id'  => $login_customer_id,
			], $body );

			$args = [
				'method'    => $method,
				'timeout'   => 30,
				'sslverify' => true,
				'headers'   => [
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				],
				'body'      => wp_json_encode( $payload ),
			];

			$resp = wp_remote_request( BRIKPANEL_ADS_PROXY_BASE . $path, $args );
			$open = Brikpanel_Ads_Proxy::open( $resp, 'google ' . $path );

			if ( $open['wp_error'] ) {
				$last_message = is_wp_error( $resp ) ? $resp->get_error_message() : 'Network error.';
				$this->sleep_backoff( $attempt );
				$attempt++;
				continue;
			}

			$code        = (int) $open['code'];
			$parsed      = $open['data'];
			$last_status = $code;

			// Verified, fresh, correctly-signed 2xx envelope.
			if ( $open['ok'] ) {
				return is_array( $parsed ) ? $parsed : [];
			}

			// 2xx but the envelope failed verification → treat as a hard
			// security failure. Never retry, never use the body: a tampered or
			// unsigned success response means the channel cannot be trusted.
			if ( $code >= 200 && $code < 300 ) {
				Brikpanel_Ads_Logger::log( 'google', $method . ' ' . $path . ' rejected: unsigned/forged proxy response (' . $open['error'] . ')', $code );
				throw new Brikpanel_Ads_Google_Exception(
					__( 'The BrikPanel proxy returned an unverifiable response and was rejected.', 'brikpanel' ),
					$code,
					'bad_signature'
				);
			}


			$reason = '';
			$msg    = '';
			if ( is_array( $parsed ) ) {
				if ( isset( $parsed['error']['message'] ) ) {
					$msg = (string) $parsed['error']['message'];
				} elseif ( isset( $parsed['message'] ) ) {
					$msg = (string) $parsed['message'];
				}
				if ( isset( $parsed['error']['status'] ) ) {
					$reason = (string) $parsed['error']['status'];
				} elseif ( isset( $parsed['reason'] ) ) {
					$reason = (string) $parsed['reason'];
				}
			}
			$last_message = $msg !== '' ? $msg : ( 'HTTP ' . $code );
			$last_reason  = $reason;

			// 401 → refresh once, retry. After that, give up — the user likely
			// revoked the grant on Google's side.
			if ( $code === 401 && ! $refreshed_once ) {
				$refreshed_once = true;
				Brikpanel_Ads_Tokens::refresh( Brikpanel_Ads_Tokens::PLATFORM_GOOGLE );
				$attempt++;
				continue;
			}

			// Only infrastructure-tier failures are worth retrying:
			//   429 → rate limited, will pass after a backoff
			//   502/503/504 → gateway / unavailable / timeout
			// 500 is application-level (proxy misconfigured, our bug) — retry
			// won't fix it and just makes the UI hang for a minute.
			if ( $code === 429 || $code === 502 || $code === 503 || $code === 504 ) {
				$this->sleep_backoff( $attempt );
				$attempt++;
				continue;
			}

			if ( ! in_array( $code, $silent_codes, true ) ) {
				Brikpanel_Ads_Logger::log( 'google', $method . ' ' . $path . ' → ' . $code . ' ' . $msg, $code, [ 'reason' => $reason ] );
			}
			throw new Brikpanel_Ads_Google_Exception( $last_message, $code, $reason );
		}

		Brikpanel_Ads_Logger::log( 'google', $method . ' ' . $path . ' exhausted retries (last ' . $last_status . ')', $last_status, [ 'reason' => $last_reason ] );
		throw new Brikpanel_Ads_Google_Exception(
			$last_message !== '' ? $last_message : __( 'Request to Google Ads failed after retries.', 'brikpanel' ),
			$last_status,
			$last_reason
		);
	}

	private function sleep_backoff( $attempt ) {
		$idx = min( $attempt, count( self::BACKOFF ) - 1 );
		$seconds = (int) self::BACKOFF[ $idx ];
		if ( $seconds > 0 ) {
			sleep( $seconds );
		}
	}

	private static function is_valid_date( $date ) {
		if ( ! is_string( $date ) || ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m ) ) {
			return false;
		}
		return checkdate( (int) $m[2], (int) $m[3], (int) $m[1] );
	}
}
