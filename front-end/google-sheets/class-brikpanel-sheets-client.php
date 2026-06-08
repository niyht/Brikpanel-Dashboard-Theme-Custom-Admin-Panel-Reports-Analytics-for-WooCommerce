<?php
/**
 * BrikPanel — Google Sheets API HTTP client.
 *
 * Thin wrapper over `wp_remote_*` providing the small Sheets API surface
 * the plugin actually needs. All requests automatically inject the bearer
 * token obtained from Brikpanel_Sheets_Tokens and self-recover from:
 *
 *   - 401:   force a token refresh, retry once.
 *   - 429 /  exponential backoff (1, 2, 4, 8, 16s) up to 5 attempts. Workers
 *     5xx:   only — never called inline from a checkout request.
 *   - 403 rateLimitExceeded: longer backoff matching Google's per-minute
 *     window.
 *
 * Used by the sync classes (orders, reports, customers). Throws
 * Brikpanel_Sheets_Exception on terminal failure so callers can mark
 * Action Scheduler rows as failed.
 *
 * @package BrikPanel
 * @since   2.9.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Brikpanel_Sheets_Exception extends \RuntimeException {
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

class Brikpanel_Sheets_Client {

	const SHEETS_BASE = 'https://sheets.googleapis.com/v4';
	const DRIVE_BASE  = 'https://www.googleapis.com/drive/v3';

	/** Maximum HTTP attempts per request. */
	const MAX_ATTEMPTS = 5;

	/** Backoff schedule (seconds) for 429 / 5xx. */
	const BACKOFF = [ 1, 2, 4, 8, 16 ];

	/** Backoff for 403 rateLimitExceeded (per-user-per-minute window). */
	const BACKOFF_USER_QUOTA = [ 5, 15, 60, 60 ];

	// =========================================================================
	// Values
	// =========================================================================

	/**
	 * Append rows to a tab. Returns the response payload (contains updated
	 * range, which row numbers were written, etc.).
	 *
	 * @param string $spreadsheet_id
	 * @param string $tab_name
	 * @param array  $rows  Array of arrays of scalars. e.g. [['#1','John',9.99], ...]
	 * @param string $value_input_option USER_ENTERED parses formulas; RAW writes literal.
	 * @return array
	 * @throws Brikpanel_Sheets_Exception
	 */
	public function append_rows( $spreadsheet_id, $tab_name, array $rows, $value_input_option = 'USER_ENTERED' ) {
		if ( empty( $rows ) ) {
			return [];
		}
		$rows  = self::neutralise_formula_injection( $rows, $value_input_option );
		$range = self::a1_quote_tab( $tab_name ) . '!A1';
		$path  = '/spreadsheets/' . rawurlencode( $spreadsheet_id )
			. '/values/' . rawurlencode( $range )
			. ':append?valueInputOption=' . rawurlencode( $value_input_option )
			. '&insertDataOption=INSERT_ROWS&includeValuesInResponse=false';

		return $this->request( 'POST', self::SHEETS_BASE . $path, [ 'values' => $rows ] );
	}

	/**
	 * Overwrite a specific A1 range with the given rows.
	 *
	 * @param string $spreadsheet_id
	 * @param string $range A1 notation, e.g. "Orders!A5:F5" or "Orders!A:Z".
	 * @param array  $values Array of arrays.
	 * @param string $value_input_option
	 * @return array
	 */
	public function values_update( $spreadsheet_id, $range, array $values, $value_input_option = 'USER_ENTERED' ) {
		$values = self::neutralise_formula_injection( $values, $value_input_option );
		$path = '/spreadsheets/' . rawurlencode( $spreadsheet_id )
			. '/values/' . rawurlencode( $range )
			. '?valueInputOption=' . rawurlencode( $value_input_option );
		return $this->request( 'PUT', self::SHEETS_BASE . $path, [ 'values' => $values, 'range' => $range ] );
	}

	/**
	 * OWASP CSV / Formula Injection guard. With valueInputOption=USER_ENTERED
	 * Sheets treats `=`, `+`, `-`, `@`, leading tab/CR strings as formulas —
	 * so a customer placing an order with a billing name like
	 * `=HYPERLINK("https://evil","Refund link")` would render as a live
	 * clickable phishing link in the merchant's spreadsheet.
	 *
	 * Mitigation: prefix any string cell that starts with one of those chars
	 * with a single quote ('). Sheets treats that as "force literal text"
	 * and hides the quote in the displayed value.
	 *
	 * Only applied when valueInputOption === 'USER_ENTERED' — RAW already
	 * stores values as text and is not vulnerable. Numeric values (int/float)
	 * are left alone so date/number auto-parsing still works.
	 *
	 * Also enforces Sheets' 50,000-char-per-cell limit so a runaway customer
	 * note doesn't fail the whole batch with INVALID_ARGUMENT.
	 *
	 * @param array  $rows
	 * @param string $value_input_option
	 * @return array
	 */
	public static function neutralise_formula_injection( array $rows, $value_input_option ) {
		$is_user_entered = ( $value_input_option === 'USER_ENTERED' );
		$max_len = 49500; // Sheets hard limit is 50,000 — keep margin for the leading quote + truncation marker.

		foreach ( $rows as $r => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			foreach ( $row as $c => $cell ) {
				if ( ! is_string( $cell ) || $cell === '' ) {
					continue;
				}
				// Truncate over-long strings regardless of input mode — both RAW
				// and USER_ENTERED reject cells > 50k chars.
				if ( strlen( $cell ) > $max_len ) {
					$cell = mb_strimwidth( $cell, 0, $max_len, '…', 'UTF-8' );
					$rows[ $r ][ $c ] = $cell;
				}
				if ( ! $is_user_entered ) {
					continue;
				}
				$first = $cell[0];
				if ( $first === '=' || $first === '+' || $first === '-' || $first === '@' || $first === "\t" || $first === "\r" ) {
					$rows[ $r ][ $c ] = "'" . $cell;
				}
			}
		}
		return $rows;
	}

	/**
	 * Read values from a range. Used by the reverse-direction pull (Sheets →
	 * Woo) to compare on-sheet values against current Woo state and apply the
	 * delta. `majorDimension=ROWS` (default) returns one inner array per row.
	 *
	 * Empty trailing cells are omitted by the API; callers must tolerate
	 * variable-length inner arrays.
	 *
	 * @param string $spreadsheet_id
	 * @param string $range A1 notation, e.g. "Orders!A2:Z" (open-ended) or "Orders!A2:F500".
	 * @param string $value_render_option FORMATTED_VALUE (default) returns
	 *               strings as the user sees them; UNFORMATTED_VALUE returns
	 *               typed scalars (int/float/bool); FORMULA returns "=..." for
	 *               formula cells. We use FORMATTED_VALUE so order_status reads
	 *               as e.g. "processing" (matches WC's status keys) and stock
	 *               reads as a parseable number string.
	 * @return array<int, array<int, scalar>> Rows; each row is an indexed array of cell values.
	 */
	public function values_get( $spreadsheet_id, $range, $value_render_option = 'FORMATTED_VALUE' ) {
		$path = '/spreadsheets/' . rawurlencode( $spreadsheet_id )
			. '/values/' . rawurlencode( $range )
			. '?valueRenderOption=' . rawurlencode( $value_render_option )
			. '&majorDimension=ROWS';
		$resp = $this->request( 'GET', self::SHEETS_BASE . $path );
		$rows = isset( $resp['values'] ) && is_array( $resp['values'] ) ? $resp['values'] : [];
		return $rows;
	}

	/**
	 * Clear values in a range (formatting preserved).
	 *
	 * @param string $spreadsheet_id
	 * @param string $range
	 * @return array
	 */
	public function values_clear( $spreadsheet_id, $range ) {
		$path = '/spreadsheets/' . rawurlencode( $spreadsheet_id )
			. '/values/' . rawurlencode( $range ) . ':clear';
		return $this->request( 'POST', self::SHEETS_BASE . $path, new stdClass() );
	}

	// =========================================================================
	// Spreadsheet metadata + structure
	// =========================================================================

	/**
	 * Read spreadsheet metadata (title, sheets list). Does NOT pull cell
	 * values — that would be enormous on large sheets.
	 *
	 * @param string $spreadsheet_id
	 * @return array
	 */
	public function get_spreadsheet( $spreadsheet_id ) {
		$path = '/spreadsheets/' . rawurlencode( $spreadsheet_id )
			. '?fields=' . rawurlencode( 'spreadsheetId,properties(title,locale,timeZone),sheets(properties(sheetId,title,index,gridProperties(rowCount,columnCount)))' );
		return $this->request( 'GET', self::SHEETS_BASE . $path );
	}

	/**
	 * Convenience: return a flat list of [sheetId => title] for the given
	 * spreadsheet.
	 *
	 * @param string $spreadsheet_id
	 * @return array<int, string>
	 */
	public function list_sheets( $spreadsheet_id ) {
		$meta = $this->get_spreadsheet( $spreadsheet_id );
		$out  = [];
		foreach ( (array) ( $meta['sheets'] ?? [] ) as $sheet ) {
			$id   = (int) ( $sheet['properties']['sheetId'] ?? 0 );
			$name = (string) ( $sheet['properties']['title'] ?? '' );
			if ( $name !== '' ) {
				$out[ $id ] = $name;
			}
		}
		return $out;
	}

	/**
	 * Create a new spreadsheet (optionally with named tabs pre-seeded).
	 *
	 * @param string   $title
	 * @param string[] $tab_names
	 * @return array Response (contains spreadsheetId, sheets, spreadsheetUrl).
	 */
	public function create_spreadsheet( $title, array $tab_names = [] ) {
		$sheets = [];
		foreach ( $tab_names as $i => $name ) {
			$sheets[] = [
				'properties' => [
					'title' => (string) $name,
					'index' => $i,
				],
			];
		}
		$payload = [
			'properties' => [ 'title' => (string) $title ],
		];
		if ( ! empty( $sheets ) ) {
			$payload['sheets'] = $sheets;
		}
		return $this->request( 'POST', self::SHEETS_BASE . '/spreadsheets', $payload );
	}

	/**
	 * Apply a list of batchUpdate request objects against a spreadsheet.
	 *
	 * @param string $spreadsheet_id
	 * @param array  $requests
	 * @return array
	 */
	public function batch_update( $spreadsheet_id, array $requests ) {
		$path = '/spreadsheets/' . rawurlencode( $spreadsheet_id ) . ':batchUpdate';
		return $this->request( 'POST', self::SHEETS_BASE . $path, [ 'requests' => $requests ] );
	}

	/**
	 * Ensure a tab named $tab_name exists; create it if it does not. Also
	 * writes the header row and freezes it on first creation, and applies any
	 * data-validation dropdowns the caller wants on specific columns.
	 *
	 * Validation rules are only applied on the same "needs header" condition
	 * as the header itself — first creation, or an existing empty A1 — so
	 * every realtime push does NOT incur an extra batchUpdate API call. To
	 * refresh dropdowns after the WC catalogue changes (e.g. a new custom
	 * order status), the user runs "Reset & re-push" which clears A1 and
	 * triggers this same path.
	 *
	 * @param string   $spreadsheet_id
	 * @param string   $tab_name
	 * @param string[] $headers Optional. If provided AND the tab is created
	 *                          fresh, these are written into row 1 and frozen.
	 * @param array    $validations Optional list of column dropdowns to apply
	 *                              alongside the header write. Each entry:
	 *                              [ column_index:int, values:string[], strict?:bool ]
	 * @return int The sheetId of the existing-or-just-created tab.
	 */
	public function ensure_tab( $spreadsheet_id, $tab_name, array $headers = [], array $validations = [] ) {
		$sheets = $this->list_sheets( $spreadsheet_id );
		$existing_sheet_id = null;
		foreach ( $sheets as $id => $name ) {
			if ( strcasecmp( $name, $tab_name ) === 0 ) {
				$existing_sheet_id = (int) $id;
				break;
			}
		}

		$created = false;
		if ( $existing_sheet_id === null ) {
			// Tab doesn't exist — create it.
			$resp = $this->batch_update( $spreadsheet_id, [
				[ 'addSheet' => [ 'properties' => [ 'title' => $tab_name ] ] ],
			] );
			foreach ( (array) ( $resp['replies'] ?? [] ) as $reply ) {
				if ( isset( $reply['addSheet']['properties']['sheetId'] ) ) {
					$existing_sheet_id = (int) $reply['addSheet']['properties']['sheetId'];
					break;
				}
			}
			$created = true;
		}

		// Write header if: (a) tab was just created, or (b) tab exists but A1 is empty
		// (e.g. pre-seeded empty tab from create_spreadsheet). Never overwrite a
		// non-empty header — user might have customised it.
		if ( ! empty( $headers ) && $existing_sheet_id !== null ) {
			$needs_header = $created;
			if ( ! $needs_header ) {
				// Peek A1:A1 — if empty, write the header.
				$probe_path = '/spreadsheets/' . rawurlencode( $spreadsheet_id )
					. '/values/' . rawurlencode( self::a1_quote_tab( $tab_name ) . '!A1' );
				try {
					$probe = $this->request( 'GET', self::SHEETS_BASE . $probe_path );
					$needs_header = empty( $probe['values'] ) || empty( $probe['values'][0] );
				} catch ( Brikpanel_Sheets_Exception $e ) {
					$needs_header = false; // be conservative on error
				}
			}
			if ( $needs_header ) {
				$range = self::a1_quote_tab( $tab_name ) . '!A1';
				$this->values_update( $spreadsheet_id, $range, [ $headers ], 'RAW' );
				$this->batch_update( $spreadsheet_id, [
					[
						'updateSheetProperties' => [
							'properties' => [
								'sheetId'        => $existing_sheet_id,
								'gridProperties' => [ 'frozenRowCount' => 1 ],
							],
							'fields' => 'gridProperties.frozenRowCount',
						],
					],
					[
						'repeatCell' => [
							'range' => [
								'sheetId'       => $existing_sheet_id,
								'startRowIndex' => 0,
								'endRowIndex'   => 1,
							],
							'cell'   => [ 'userEnteredFormat' => [ 'textFormat' => [ 'bold' => true ] ] ],
							'fields' => 'userEnteredFormat.textFormat.bold',
						],
					],
				] );

				// Apply column dropdowns on the same first-write path.
				if ( ! empty( $validations ) ) {
					try {
						$this->apply_data_validation( $spreadsheet_id, $existing_sheet_id, $validations );
					} catch ( Brikpanel_Sheets_Exception $e ) {
						// Validation failure is not fatal — the tab + headers
						// already landed, so the sync flow can still write
						// rows. Log and continue so a stale dropdown rule
						// (e.g. a removed custom status) does not block sync.
						Brikpanel_Sheets_Logger::log( 'client', 'apply_data_validation failed for tab ' . $tab_name . ': ' . $e->getMessage(), $e->http_code );
					}
				}
			}
		}
		return (int) $existing_sheet_id;
	}

	/**
	 * Apply ONE_OF_LIST data validation to one or more columns of a sheet.
	 * Creates the "click for dropdown" arrow inside cells and (when strict)
	 * rejects any value not in the list.
	 *
	 * Range starts at row 2 (0-indexed startRowIndex=1) so the header row is
	 * never affected; no endRowIndex means the rule extends down indefinitely
	 * as new rows are appended.
	 *
	 * @param string $spreadsheet_id
	 * @param int    $sheet_id      Numeric sheetId (NOT tab name).
	 * @param array  $validations   Each entry: [ column_index:int (0-based),
	 *                              values:string[], strict?:bool (default true) ]
	 * @return array Decoded batchUpdate response.
	 */
	public function apply_data_validation( $spreadsheet_id, $sheet_id, array $validations ) {
		$requests = [];
		foreach ( $validations as $v ) {
			$col_idx = (int) ( $v['column_index'] ?? -1 );
			$values  = isset( $v['values'] ) ? (array) $v['values'] : [];
			if ( $col_idx < 0 || empty( $values ) ) {
				continue;
			}
			$strict = ! array_key_exists( 'strict', $v ) || (bool) $v['strict'];
			$list = [];
			foreach ( $values as $val ) {
				if ( $val === '' || $val === null ) {
					continue;
				}
				$list[] = [ 'userEnteredValue' => (string) $val ];
			}
			if ( empty( $list ) ) {
				continue;
			}
			$requests[] = [
				'setDataValidation' => [
					'range' => [
						'sheetId'          => (int) $sheet_id,
						'startRowIndex'    => 1,
						'startColumnIndex' => $col_idx,
						'endColumnIndex'   => $col_idx + 1,
					],
					'rule' => [
						'condition' => [
							'type'   => 'ONE_OF_LIST',
							'values' => $list,
						],
						'showCustomUi' => true,
						'strict'       => $strict,
					],
				],
			];
		}
		if ( empty( $requests ) ) {
			return [];
		}
		return $this->batch_update( $spreadsheet_id, $requests );
	}

	// =========================================================================
	// Helpers
	// =========================================================================

	/**
	 * Quote a tab name for A1 notation. If it contains whitespace, quotes,
	 * or punctuation, wrap in single quotes (Google's rule) and escape any
	 * embedded apostrophe by doubling it.
	 *
	 * @param string $name
	 * @return string
	 */
	public static function a1_quote_tab( $name ) {
		if ( preg_match( '/^[A-Za-z0-9_]+$/', $name ) && ! preg_match( '/^\d/', $name ) ) {
			return $name;
		}
		return "'" . str_replace( "'", "''", $name ) . "'";
	}

	/**
	 * Parse a Google Sheets URL or raw ID and return the spreadsheet ID, or
	 * null if it cannot be extracted.
	 *
	 * @param string $input
	 * @return string|null
	 */
	public static function extract_spreadsheet_id( $input ) {
		$input = trim( (string) $input );
		if ( $input === '' ) {
			return null;
		}
		if ( preg_match( '#/spreadsheets/d/([a-zA-Z0-9_\-]+)#', $input, $m ) ) {
			return $m[1];
		}
		// Bare ID? (Google IDs are alnum + dashes + underscores, ~44 chars.)
		if ( preg_match( '/^[a-zA-Z0-9_\-]{20,80}$/', $input ) ) {
			return $input;
		}
		return null;
	}

	// =========================================================================
	// HTTP plumbing
	// =========================================================================

	/**
	 * Send a request with full retry / refresh handling.
	 *
	 * @param string     $method GET|POST|PUT|DELETE
	 * @param string     $url    Full URL (already includes query string).
	 * @param array|object|null $body  JSON-serialisable body for POST/PUT.
	 * @return array Decoded JSON response.
	 * @throws Brikpanel_Sheets_Exception
	 */
	private function request( $method, $url, $body = null ) {
		$attempt          = 0;
		$refreshed_once   = false;
		$last_status      = 0;
		$last_message     = '';
		$last_reason      = '';

		while ( $attempt < self::MAX_ATTEMPTS ) {
			$token = Brikpanel_Sheets_Tokens::get_access_token();
			if ( $token === null ) {
				throw new Brikpanel_Sheets_Exception( __( 'Not connected to Google Sheets.', 'brikpanel' ), 0, 'not_connected' );
			}

			$args = [
				'method'    => $method,
				'timeout'   => 25,
				'sslverify' => true,
				'headers'   => [
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json',
					'Accept'        => 'application/json',
				],
			];
			if ( $body !== null ) {
				$args['body'] = wp_json_encode( $body );
			}

			$resp = wp_remote_request( $url, $args );
			if ( is_wp_error( $resp ) ) {
				$last_message = $resp->get_error_message();
				// Transient network error — backoff and retry.
				$this->sleep_backoff( $attempt, self::BACKOFF );
				$attempt++;
				continue;
			}

			$code        = (int) wp_remote_retrieve_response_code( $resp );
			$body_raw    = (string) wp_remote_retrieve_body( $resp );
			$last_status = $code;
			$parsed      = json_decode( $body_raw, true );

			if ( $code >= 200 && $code < 300 ) {
				return is_array( $parsed ) ? $parsed : [];
			}

			// Extract Google's structured error reason if present.
			$reason = '';
			$msg    = '';
			if ( is_array( $parsed ) && isset( $parsed['error'] ) ) {
				$err = $parsed['error'];
				$msg = (string) ( $err['message'] ?? '' );
				if ( ! empty( $err['errors'][0]['reason'] ) ) {
					$reason = (string) $err['errors'][0]['reason'];
				} elseif ( ! empty( $err['status'] ) ) {
					$reason = (string) $err['status'];
				}
			}
			$last_message = $msg !== '' ? $msg : ( 'HTTP ' . $code );
			$last_reason  = $reason;

			// 401 -> refresh once, retry.
			if ( $code === 401 && ! $refreshed_once ) {
				$refreshed_once = true;
				Brikpanel_Sheets_Tokens::refresh();
				$attempt++;
				continue;
			}

			// 403 with user-quota / rate-limit hint -> long backoff.
			if ( $code === 403 && in_array( $reason, [ 'rateLimitExceeded', 'userRateLimitExceeded', 'quotaExceeded' ], true ) ) {
				$this->sleep_backoff( $attempt, self::BACKOFF_USER_QUOTA );
				$attempt++;
				continue;
			}

			// 429 / 5xx -> standard backoff.
			if ( $code === 429 || ( $code >= 500 && $code < 600 ) ) {
				$this->sleep_backoff( $attempt, self::BACKOFF );
				$attempt++;
				continue;
			}

			// Anything else is terminal.
			Brikpanel_Sheets_Logger::log( 'client', $method . ' ' . $url . ' → ' . $code . ' ' . $msg, $code, [ 'reason' => $reason ] );
			throw new Brikpanel_Sheets_Exception( $last_message, $code, $reason );
		}

		Brikpanel_Sheets_Logger::log( 'client', $method . ' ' . $url . ' exhausted retries (last ' . $last_status . ')', $last_status, [ 'reason' => $last_reason ] );
		throw new Brikpanel_Sheets_Exception(
			$last_message !== '' ? $last_message : __( 'Request to Google Sheets failed after retries.', 'brikpanel' ),
			$last_status,
			$last_reason
		);
	}

	/**
	 * Sleep for the backoff slot matching $attempt. CLI worker context only.
	 *
	 * @param int   $attempt
	 * @param int[] $schedule
	 */
	private function sleep_backoff( $attempt, array $schedule ) {
		$idx = min( $attempt, count( $schedule ) - 1 );
		$seconds = (int) $schedule[ $idx ];
		if ( $seconds > 0 ) {
			sleep( $seconds );
		}
	}
}
