<?php
/**
 * BrikPanel — Meta Marketing API client (proxy-tunnelled).
 *
 * Identical proxy architecture to the Google client: every Meta call is
 * routed through the WPCode proxy on brksoft.com so the app_secret never
 * lives in the plugin. The proxy adds the secret only on token exchange /
 * refresh — plain Graph API reads with a valid user access token don't
 * need it.
 *
 * MVP surface area:
 *   - list_accounts(): /me/adaccounts → all ad accounts the connected user
 *     can read.
 *   - fetch_spend(): /act_{id}/insights?time_increment=1 → daily rows for
 *     a date range.
 *
 * @package BrikPanel
 * @since   3.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Brikpanel_Ads_Meta_Exception extends \RuntimeException {
	/** @var int */
	public $http_code = 0;
	/** @var int */
	public $meta_code = 0;

	public function __construct( $message, $http_code = 0, $meta_code = 0 ) {
		parent::__construct( $message );
		$this->http_code = (int) $http_code;
		$this->meta_code = (int) $meta_code;
	}
}

class Brikpanel_Ads_Meta_Client {

	const MAX_ATTEMPTS = 3;
	/**
	 * Backoff for retryable infrastructure failures only. See
	 * Brikpanel_Ads_Google_Client::BACKOFF for rationale.
	 */
	const BACKOFF = [ 1, 3, 6 ];

	/**
	 * Meta caps insights history at ~37 months. Even when the user asks for
	 * 3 years, we ceiling the start date to today-37mo so we don't get a
	 * "selected date range is over 37 months" error from Graph.
	 */
	const HISTORY_CEILING_DAYS = 1100; // ~37 months

	/**
	 * Meta also rejects insights requests with a window larger than 90 days
	 * per call (when time_increment=1). For longer historical fetches we
	 * have to page in 90-day chunks.
	 */
	const MAX_WINDOW_DAYS = 90;

	// =========================================================================
	// Public API
	// =========================================================================

	/**
	 * List all ad accounts visible to the connected user.
	 *
	 * @return array<int, array{id:string, account_id:string, name:string, currency:string, timezone:string, status:int}>
	 * @throws Brikpanel_Ads_Meta_Exception
	 */
	public function list_accounts() {
		$resp = $this->request( 'POST', '/meta/list-ad-accounts', [] );
		$accounts = isset( $resp['data'] ) && is_array( $resp['data'] ) ? $resp['data'] : [];
		$out = [];
		foreach ( $accounts as $row ) {
			if ( empty( $row['id'] ) ) {
				continue;
			}
			$out[] = [
				'id'         => (string) $row['id'],                                       // act_1234567890
				'account_id' => (string) ( $row['account_id'] ?? '' ),                     // bare 1234567890
				'name'       => (string) ( $row['name'] ?? $row['id'] ),
				'currency'   => (string) ( $row['currency'] ?? '' ),
				'timezone'   => (string) ( $row['timezone_name'] ?? '' ),
				'status'     => (int)    ( $row['account_status'] ?? 0 ),
			];
		}
		return $out;
	}

	/**
	 * Pull daily spend for a date range. Pages through 90-day windows
	 * because Meta rejects single requests spanning more than 90 days when
	 * time_increment=1.
	 *
	 * @param string $account_id Either "act_123..." or bare "123...". We
	 *                           normalise both.
	 * @param string $start_date YYYY-MM-DD inclusive
	 * @param string $end_date   YYYY-MM-DD inclusive
	 * @return array<int, array{date:string, spend_amount:float, spend_currency:string, impressions:int, clicks:int}>
	 * @throws Brikpanel_Ads_Meta_Exception
	 */
	public function fetch_spend( $account_id, $start_date, $end_date ) {
		$account_id = self::normalise_account_id( $account_id );
		if ( $account_id === '' ) {
			throw new Brikpanel_Ads_Meta_Exception( 'Missing Meta ad account ID.' );
		}
		if ( ! self::is_valid_date( $start_date ) || ! self::is_valid_date( $end_date ) ) {
			throw new Brikpanel_Ads_Meta_Exception( 'Invalid date range.' );
		}

		// Clamp the start date to Meta's 37-month history ceiling.
		$earliest = gmdate( 'Y-m-d', time() - self::HISTORY_CEILING_DAYS * DAY_IN_SECONDS );
		if ( strcmp( $start_date, $earliest ) < 0 ) {
			$start_date = $earliest;
		}
		if ( strcmp( $start_date, $end_date ) > 0 ) {
			return [];
		}

		$rows = [];
		$cursor = $start_date;
		while ( strcmp( $cursor, $end_date ) <= 0 ) {
			$slice_end = gmdate(
				'Y-m-d',
				min(
					strtotime( $end_date . ' 00:00:00 UTC' ),
					strtotime( $cursor . ' 00:00:00 UTC' ) + ( self::MAX_WINDOW_DAYS - 1 ) * DAY_IN_SECONDS
				)
			);

			$resp = $this->request( 'POST', '/meta/insights', [
				'account_id' => $account_id,
				'since'      => $cursor,
				'until'      => $slice_end,
			] );

			$data = isset( $resp['data'] ) && is_array( $resp['data'] ) ? $resp['data'] : [];
			foreach ( $data as $row ) {
				$date = (string) ( $row['date_start'] ?? '' );
				if ( ! self::is_valid_date( $date ) ) {
					continue;
				}
				$rows[] = [
					'date'           => $date,
					'spend_amount'   => (float)  ( $row['spend'] ?? 0 ),
					'spend_currency' => (string) ( $row['account_currency'] ?? '' ),
					'impressions'    => (int)    ( $row['impressions'] ?? 0 ),
					'clicks'         => (int)    ( $row['clicks'] ?? 0 ),
					'raw'            => $row,
				];
			}

			// Follow pagination cursor if Graph returned `paging.next` —
			// shouldn't happen at time_increment=1 with our 90-day windows,
			// but defend against the edge case.
			$next_cursor = isset( $resp['paging']['cursors']['after'] ) ? (string) $resp['paging']['cursors']['after'] : '';
			while ( $next_cursor !== '' ) {
				$resp = $this->request( 'POST', '/meta/insights', [
					'account_id' => $account_id,
					'since'      => $cursor,
					'until'      => $slice_end,
					'after'      => $next_cursor,
				] );
				$data = isset( $resp['data'] ) && is_array( $resp['data'] ) ? $resp['data'] : [];
				foreach ( $data as $row ) {
					$date = (string) ( $row['date_start'] ?? '' );
					if ( ! self::is_valid_date( $date ) ) {
						continue;
					}
					$rows[] = [
						'date'           => $date,
						'spend_amount'   => (float)  ( $row['spend'] ?? 0 ),
						'spend_currency' => (string) ( $row['account_currency'] ?? '' ),
						'impressions'    => (int)    ( $row['impressions'] ?? 0 ),
						'clicks'         => (int)    ( $row['clicks'] ?? 0 ),
						'raw'            => $row,
					];
				}
				$next_cursor = isset( $resp['paging']['cursors']['after'] ) ? (string) $resp['paging']['cursors']['after'] : '';
			}

			// Advance the cursor one day past the slice_end.
			$cursor = gmdate( 'Y-m-d', strtotime( $slice_end . ' 00:00:00 UTC' ) + DAY_IN_SECONDS );
		}
		return $rows;
	}

	// =========================================================================
	// HTTP plumbing
	// =========================================================================

	private function request( $method, $path, array $body ) {
		$attempt        = 0;
		$refreshed_once = false;
		$last_status    = 0;
		$last_message   = '';
		$last_meta_code = 0;

		if ( Brikpanel_Ads_Proxy::is_killed() ) {
			throw new Brikpanel_Ads_Meta_Exception(
				__( 'Meta Ads connection was revoked for security. Please reconnect.', 'brikpanel' ),
				0,
				0
			);
		}

		while ( $attempt < self::MAX_ATTEMPTS ) {
			$token = Brikpanel_Ads_Tokens::get_access_token( Brikpanel_Ads_Tokens::PLATFORM_META );
			if ( $token === null ) {
				throw new Brikpanel_Ads_Meta_Exception(
					__( 'Not connected to Meta Ads.', 'brikpanel' ),
					0,
					0
				);
			}

			$payload = array_merge( [
				'site_url'     => home_url(),
				'access_token' => $token,
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
			$open = Brikpanel_Ads_Proxy::open( $resp, 'meta ' . $path );

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

			// 2xx but envelope verification failed → hard security failure.
			// Never retry, never trust the body.
			if ( $code >= 200 && $code < 300 ) {
				Brikpanel_Ads_Logger::log( 'meta', $method . ' ' . $path . ' rejected: unsigned/forged proxy response (' . $open['error'] . ')', $code );
				throw new Brikpanel_Ads_Meta_Exception(
					__( 'The BrikPanel proxy returned an unverifiable response and was rejected.', 'brikpanel' ),
					$code,
					0
				);
			}

			$msg       = '';
			$meta_code = 0;
			if ( is_array( $parsed ) ) {
				if ( isset( $parsed['error']['message'] ) ) {
					$msg = (string) $parsed['error']['message'];
				} elseif ( isset( $parsed['message'] ) ) {
					$msg = (string) $parsed['message'];
				}
				if ( isset( $parsed['error']['code'] ) ) {
					$meta_code = (int) $parsed['error']['code'];
				}
			}
			$last_message   = $msg !== '' ? $msg : ( 'HTTP ' . $code );
			$last_meta_code = $meta_code;

			// Meta token expired/invalid → try refresh once, retry.
			// Meta-specific codes:
			//   190 OAuthException — token issue, refresh or reconnect
			//   102 API session    — expired session, same handling
			if ( $code === 401 || $meta_code === 190 || $meta_code === 102 ) {
				if ( ! $refreshed_once ) {
					$refreshed_once = true;
					Brikpanel_Ads_Tokens::refresh( Brikpanel_Ads_Tokens::PLATFORM_META );
					$attempt++;
					continue;
				}
			}

			// Retry only infrastructure-tier failures + Meta rate-limit codes
			// (4 = too many calls, 17 = user-level rate limit, 32 = page-level
			// rate limit, 613 = ads insights call rate limit).
			// 500 is application-level — retry won't fix proxy misconfig
			// and would block the settings UI.
			if ( $code === 429 || $code === 502 || $code === 503 || $code === 504
				|| in_array( $meta_code, [ 4, 17, 32, 613 ], true ) ) {
				$this->sleep_backoff( $attempt );
				$attempt++;
				continue;
			}

			Brikpanel_Ads_Logger::log( 'meta', $method . ' ' . $path . ' → ' . $code . ' ' . $msg, $code, [ 'meta_code' => $meta_code ] );
			throw new Brikpanel_Ads_Meta_Exception( $last_message, $code, $meta_code );
		}

		Brikpanel_Ads_Logger::log( 'meta', $method . ' ' . $path . ' exhausted retries (last ' . $last_status . ')', $last_status, [ 'meta_code' => $last_meta_code ] );
		throw new Brikpanel_Ads_Meta_Exception(
			$last_message !== '' ? $last_message : __( 'Request to Meta Ads failed after retries.', 'brikpanel' ),
			$last_status,
			$last_meta_code
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

	/**
	 * Normalise a Meta ad account ID to the "act_XXX" form Graph expects.
	 * Accepts either "act_1234567890" or bare "1234567890".
	 */
	public static function normalise_account_id( $id ) {
		$id = trim( (string) $id );
		if ( $id === '' ) {
			return '';
		}
		if ( strncmp( $id, 'act_', 4 ) === 0 ) {
			$digits = preg_replace( '/[^0-9]/', '', substr( $id, 4 ) );
			return $digits !== '' ? 'act_' . $digits : '';
		}
		$digits = preg_replace( '/[^0-9]/', '', $id );
		return $digits !== '' ? 'act_' . $digits : '';
	}
}
