<?php
/**
 * BrikPanel — Ad Platforms storage layer (wp_brikpanel_ad_spend).
 *
 * Thin abstraction over the daily-spend table. Every row represents one
 * (date × platform × account) tuple. Idempotent UPSERTs are the only write
 * path so re-fetching the same window (the 7-day refresh window or a manual
 * resync) cannot create duplicates.
 *
 * The table is created by the main plugin's activation routine alongside the
 * other BrikPanel custom tables (see brikpanel.php → brikpanel_create_table).
 *
 * @package BrikPanel
 * @since   3.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Brikpanel_Ads_Store {

	/** Table suffix (joined with $wpdb->prefix on each call so blog switches work). */
	const TABLE = 'brikpanel_ad_spend';

	/** @return string Fully-qualified table name with prefix. */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	// =========================================================================
	// Writes
	// =========================================================================

	/**
	 * Insert-or-update a single day row.
	 *
	 * @param string $platform     google_ads | meta_ads
	 * @param string $account_id   External account ID (Google CID, Meta act_xxx).
	 * @param string $date         YYYY-MM-DD (account-timezone date as reported by the platform).
	 * @param array  $data {
	 *     @type float|int|string $spend_amount   Amount in $currency. Required.
	 *     @type string           $spend_currency 3-letter currency code from the ad account.
	 *     @type int              $impressions    Optional.
	 *     @type int              $clicks         Optional.
	 *     @type array|null       $raw            Optional raw API payload — stored as JSON for future use (BrikMentor).
	 * }
	 * @return bool true on success.
	 */
	public static function upsert( $platform, $account_id, $date, array $data ) {
		global $wpdb;
		if ( ! self::is_valid_platform( $platform ) || $account_id === '' || ! self::is_valid_date( $date ) ) {
			return false;
		}
		$table = self::table();

		$row = [
			'spend_amount'   => self::sanitize_decimal( $data['spend_amount'] ?? 0 ),
			'spend_currency' => self::sanitize_currency( $data['spend_currency'] ?? '' ),
			'impressions'    => max( 0, (int) ( $data['impressions'] ?? 0 ) ),
			'clicks'         => max( 0, (int) ( $data['clicks'] ?? 0 ) ),
			'raw_data'       => isset( $data['raw'] ) && is_array( $data['raw'] )
				? self::sanitize_raw_json( $data['raw'] )
				: null,
			'fetched_at'     => current_time( 'mysql', true ),
		];

		// INSERT ... ON DUPLICATE KEY UPDATE keeps writes idempotent against
		// the (date, platform, account_id) UNIQUE KEY. We use raw SQL because
		// $wpdb->replace() would re-key the row (losing the original `id`) and
		// $wpdb->insert/update would race under concurrent workers.
		$sql = "INSERT INTO {$table}
			(date, platform, account_id, spend_amount, spend_currency, impressions, clicks, raw_data, fetched_at)
			VALUES (%s, %s, %s, %s, %s, %d, %d, %s, %s)
			ON DUPLICATE KEY UPDATE
				spend_amount = VALUES(spend_amount),
				spend_currency = VALUES(spend_currency),
				impressions = VALUES(impressions),
				clicks = VALUES(clicks),
				raw_data = VALUES(raw_data),
				fetched_at = VALUES(fetched_at)";

		$ok = $wpdb->query( $wpdb->prepare(
			$sql,
			$date,
			$platform,
			$account_id,
			$row['spend_amount'],
			$row['spend_currency'],
			$row['impressions'],
			$row['clicks'],
			$row['raw_data'] === null ? '' : $row['raw_data'],
			$row['fetched_at']
		) );

		// $wpdb->query returns false on error, or the affected-row count
		// otherwise. INSERT ... ON DUPLICATE KEY UPDATE returns 0 when the
		// new values match the existing row exactly — still a success.
		return $ok !== false;
	}

	/**
	 * Bulk upsert. Builds a single multi-row INSERT for performance —
	 * historical backfill can mean 1000+ rows per account, and one round-trip
	 * per row would be silly.
	 *
	 * @param string  $platform
	 * @param string  $account_id
	 * @param array[] $rows Each entry: ['date' => YYYY-MM-DD, ...self::upsert data]
	 * @return int Number of rows written / updated.
	 */
	public static function bulk_upsert( $platform, $account_id, array $rows ) {
		global $wpdb;
		if ( ! self::is_valid_platform( $platform ) || $account_id === '' || empty( $rows ) ) {
			return 0;
		}
		$table  = self::table();
		$now    = current_time( 'mysql', true );
		$values = [];
		$args   = [];

		foreach ( $rows as $row ) {
			$date = isset( $row['date'] ) ? (string) $row['date'] : '';
			if ( ! self::is_valid_date( $date ) ) {
				continue;
			}
			$values[] = '(%s, %s, %s, %s, %s, %d, %d, %s, %s)';
			$args[] = $date;
			$args[] = $platform;
			$args[] = $account_id;
			$args[] = self::sanitize_decimal( $row['spend_amount'] ?? 0 );
			$args[] = self::sanitize_currency( $row['spend_currency'] ?? '' );
			$args[] = max( 0, (int) ( $row['impressions'] ?? 0 ) );
			$args[] = max( 0, (int) ( $row['clicks'] ?? 0 ) );
			$args[] = isset( $row['raw'] ) && is_array( $row['raw'] ) ? (string) self::sanitize_raw_json( $row['raw'] ) : '';
			$args[] = $now;
		}
		if ( empty( $values ) ) {
			return 0;
		}

		$sql = "INSERT INTO {$table}
			(date, platform, account_id, spend_amount, spend_currency, impressions, clicks, raw_data, fetched_at)
			VALUES " . implode( ', ', $values ) . "
			ON DUPLICATE KEY UPDATE
				spend_amount = VALUES(spend_amount),
				spend_currency = VALUES(spend_currency),
				impressions = VALUES(impressions),
				clicks = VALUES(clicks),
				raw_data = VALUES(raw_data),
				fetched_at = VALUES(fetched_at)";

		$result = $wpdb->query( $wpdb->prepare( $sql, $args ) );
		// Bump the dashboard cache version so KPI cards re-query on next render.
		self::bump_cache_version();
		return $result === false ? 0 : (int) $result;
	}

	/**
	 * Delete every row for a (platform, account_id) tuple. Used on disconnect
	 * so reconnecting against a different account doesn't show stale data.
	 *
	 * @param string $platform
	 * @param string $account_id  Pass '' to wipe every row for the platform.
	 * @return int Rows deleted.
	 */
	public static function delete_account( $platform, $account_id = '' ) {
		global $wpdb;
		if ( ! self::is_valid_platform( $platform ) ) {
			return 0;
		}
		$table = self::table();
		if ( $account_id === '' ) {
			$n = (int) $wpdb->query( $wpdb->prepare(
				"DELETE FROM {$table} WHERE platform = %s",
				$platform
			) );
		} else {
			$n = (int) $wpdb->query( $wpdb->prepare(
				"DELETE FROM {$table} WHERE platform = %s AND account_id = %s",
				$platform,
				$account_id
			) );
		}
		self::bump_cache_version();
		return $n;
	}

	// =========================================================================
	// Reads — used by Sync (catch-up logic) and Dashboard (KPI cards)
	// =========================================================================

	/**
	 * Newest date already stored for the given (platform, account_id) tuple,
	 * as YYYY-MM-DD, or null if no rows exist. Used to decide whether the
	 * platform needs a historical backfill or just the refresh window.
	 */
	public static function last_synced_date( $platform, $account_id ) {
		global $wpdb;
		if ( ! self::is_valid_platform( $platform ) ) {
			return null;
		}
		$table = self::table();
		$val   = $wpdb->get_var( $wpdb->prepare(
			"SELECT MAX(date) FROM {$table} WHERE platform = %s AND account_id = %s",
			$platform,
			$account_id
		) );
		return $val ? (string) $val : null;
	}

	/** True if any row exists for (platform, account_id). */
	public static function has_data( $platform, $account_id ) {
		global $wpdb;
		if ( ! self::is_valid_platform( $platform ) ) {
			return false;
		}
		$table = self::table();
		$val   = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$table} WHERE platform = %s AND account_id = %s LIMIT 1",
			$platform,
			$account_id
		) );
		return $val > 0;
	}

	/**
	 * Per-platform totals for a date range. Result is grouped so a multi-
	 * currency situation (Meta account in USD, store in TRY) is preserved:
	 * the dashboard can show "₺5.000 + $200" instead of silently summing
	 * different currencies.
	 *
	 * @param string $start YYYY-MM-DD inclusive (site-local timezone)
	 * @param string $end   YYYY-MM-DD inclusive
	 * @return array<int, array{platform:string, currency:string, spend:float, impressions:int, clicks:int}>
	 */
	public static function totals_for_range( $start, $end ) {
		global $wpdb;
		if ( ! self::is_valid_date( $start ) || ! self::is_valid_date( $end ) ) {
			return [];
		}
		$table = self::table();
		$rows  = $wpdb->get_results( $wpdb->prepare(
			"SELECT platform, spend_currency AS currency,
				SUM(spend_amount) AS spend,
				SUM(impressions) AS impressions,
				SUM(clicks) AS clicks
			FROM {$table}
			WHERE date BETWEEN %s AND %s
			GROUP BY platform, spend_currency",
			$start,
			$end
		), ARRAY_A );

		$out = [];
		foreach ( (array) $rows as $r ) {
			$out[] = [
				'platform'    => (string) $r['platform'],
				'currency'    => (string) $r['currency'],
				'spend'       => (float) $r['spend'],
				'impressions' => (int) $r['impressions'],
				'clicks'      => (int) $r['clicks'],
			];
		}
		return $out;
	}

	/**
	 * Per-day breakdown grouped by platform for the date range. Used by the
	 * dashboard chart card.
	 *
	 * @param string $start
	 * @param string $end
	 * @return array<int, array{date:string, platform:string, currency:string, spend:float}>
	 */
	public static function daily_breakdown( $start, $end ) {
		global $wpdb;
		if ( ! self::is_valid_date( $start ) || ! self::is_valid_date( $end ) ) {
			return [];
		}
		$table = self::table();
		$rows  = $wpdb->get_results( $wpdb->prepare(
			"SELECT date, platform, spend_currency AS currency, SUM(spend_amount) AS spend
			FROM {$table}
			WHERE date BETWEEN %s AND %s
			GROUP BY date, platform, spend_currency
			ORDER BY date ASC",
			$start,
			$end
		), ARRAY_A );

		$out = [];
		foreach ( (array) $rows as $r ) {
			$out[] = [
				'date'     => (string) $r['date'],
				'platform' => (string) $r['platform'],
				'currency' => (string) $r['currency'],
				'spend'    => (float) $r['spend'],
			];
		}
		return $out;
	}

	/**
	 * Month-by-month rollup for a single (platform, account) tuple. Powers the
	 * "Imported spend data" panel on the settings page so the merchant can see
	 * exactly what BrikPanel pulled. Newest month first.
	 *
	 * Grouped by currency as well so a (rare) mid-life account currency switch
	 * stays visible instead of being silently summed.
	 *
	 * @param string $platform
	 * @param string $account_id
	 * @return array<int, array{month:string, currency:string, spend:float, impressions:int, clicks:int, days:int}>
	 */
	public static function monthly_breakdown( $platform, $account_id ) {
		global $wpdb;
		if ( ! self::is_valid_platform( $platform ) || $account_id === '' ) {
			return [];
		}
		$table = self::table();
		$rows  = $wpdb->get_results( $wpdb->prepare(
			"SELECT DATE_FORMAT(date, '%%Y-%%m') AS month, spend_currency AS currency,
				SUM(spend_amount) AS spend,
				SUM(impressions) AS impressions,
				SUM(clicks) AS clicks,
				COUNT(*) AS days
			FROM {$table}
			WHERE platform = %s AND account_id = %s
			GROUP BY month, spend_currency
			ORDER BY month DESC",
			$platform,
			$account_id
		), ARRAY_A );

		$out = [];
		foreach ( (array) $rows as $r ) {
			$out[] = [
				'month'       => (string) $r['month'],
				'currency'    => (string) $r['currency'],
				'spend'       => (float) $r['spend'],
				'impressions' => (int) $r['impressions'],
				'clicks'      => (int) $r['clicks'],
				'days'        => (int) $r['days'],
			];
		}
		return $out;
	}

	/**
	 * All-time summary for a single (platform, account) tuple: date span, total
	 * spend / impressions / clicks, day count, and the account currency. Returns
	 * null when no rows exist yet (nothing pulled).
	 *
	 * @param string $platform
	 * @param string $account_id
	 * @return array{first_date:string, last_date:string, days:int, spend:float, impressions:int, clicks:int, currency:string}|null
	 */
	public static function account_summary( $platform, $account_id ) {
		global $wpdb;
		if ( ! self::is_valid_platform( $platform ) || $account_id === '' ) {
			return null;
		}
		$table = self::table();
		$r = $wpdb->get_row( $wpdb->prepare(
			"SELECT MIN(date) AS first_date, MAX(date) AS last_date,
				COUNT(*) AS days,
				SUM(spend_amount) AS spend,
				SUM(impressions) AS impressions,
				SUM(clicks) AS clicks,
				MAX(spend_currency) AS currency
			FROM {$table}
			WHERE platform = %s AND account_id = %s",
			$platform,
			$account_id
		), ARRAY_A );

		if ( ! is_array( $r ) || (int) $r['days'] === 0 ) {
			return null;
		}
		return [
			'first_date'  => (string) $r['first_date'],
			'last_date'   => (string) $r['last_date'],
			'days'        => (int) $r['days'],
			'spend'       => (float) $r['spend'],
			'impressions' => (int) $r['impressions'],
			'clicks'      => (int) $r['clicks'],
			'currency'    => (string) $r['currency'],
		];
	}

	// =========================================================================
	// Cache-busting
	// =========================================================================

	/**
	 * Shared cache version key — incremented on every write so the dashboard
	 * KPI helper can build transient keys that auto-invalidate on new data.
	 * Mirrors the pattern used by brikpanel-helpers.php for order-driven
	 * cache busts.
	 */
	const CACHE_VERSION_OPTION = 'brikpanel_ads_cache_version';

	public static function cache_version() {
		$v = (int) get_option( self::CACHE_VERSION_OPTION, 0 );
		return $v > 0 ? $v : 1;
	}

	public static function bump_cache_version() {
		$v = self::cache_version() + 1;
		update_option( self::CACHE_VERSION_OPTION, $v, false );
	}

	// =========================================================================
	// Helpers
	// =========================================================================

	private static function is_valid_platform( $platform ) {
		return $platform === Brikpanel_Ads_Tokens::PLATFORM_GOOGLE
			|| $platform === Brikpanel_Ads_Tokens::PLATFORM_META;
	}

	private static function is_valid_date( $date ) {
		if ( ! is_string( $date ) || ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m ) ) {
			return false;
		}
		return checkdate( (int) $m[2], (int) $m[3], (int) $m[1] );
	}

	/**
	 * `raw_data` is attacker-influenced: it is whatever the brksoft.com proxy
	 * (or anything upstream of it) put in the API response. Even though the
	 * envelope signature now authenticates the proxy, this column is persisted
	 * verbatim and is earmarked for the BrikMentor AI addon — i.e. it will be
	 * fed to an LLM and may later be rendered. So we treat it as hostile data
	 * at rest:
	 *
	 *   - hard caps on depth / breadth / total size so a rogue response cannot
	 *     bloat the table or build a billion-node structure (DoS),
	 *   - every string is control-char-stripped and length-capped so a future
	 *     UI consumer cannot be stored-XSS'd and an LLM consumer cannot be
	 *     prompt-injected with megabytes of crafted text.
	 *
	 * IMPORTANT for future consumers: still escape on output (esc_html) and
	 * wrap as untrusted when sending to any LLM. Sanitisation here reduces
	 * blast radius; it is not a substitute for output-side encoding.
	 *
	 * @param mixed $raw
	 * @return string|null JSON string, or null if nothing usable survived.
	 */
	private static function sanitize_raw_json( $raw ) {
		$budget = 1500; // max total scalar/array nodes
		$clean  = self::sanitize_raw_node( $raw, 0, $budget );
		if ( $clean === null ) {
			return null;
		}
		$json = wp_json_encode( $clean );
		if ( ! is_string( $json ) || $json === '' || $json === 'null' ) {
			return null;
		}
		// Final overall ceiling (defence in depth on top of the node budget).
		if ( strlen( $json ) > 32 * 1024 ) {
			$json = (string) wp_json_encode( [ '_truncated' => true ] );
		}
		return $json;
	}

	/**
	 * Recursive value sanitiser with a shared node budget passed by reference.
	 *
	 * @param mixed $v
	 * @param int   $depth
	 * @param int   $budget  Remaining node budget (by reference).
	 * @return mixed Sanitised value (scalars/arrays only).
	 */
	private static function sanitize_raw_node( $v, $depth, &$budget ) {
		if ( $budget-- <= 0 || $depth > 6 ) {
			return null;
		}
		if ( is_array( $v ) ) {
			$out = [];
			$n   = 0;
			foreach ( $v as $k => $vv ) {
				if ( $n++ >= 200 ) {
					break;
				}
				$key = is_int( $k ) ? $k : substr( preg_replace( '/[^\x20-\x7E]/', '', (string) $k ), 0, 64 );
				$out[ $key ] = self::sanitize_raw_node( $vv, $depth + 1, $budget );
			}
			return $out;
		}
		if ( is_bool( $v ) || is_int( $v ) || is_null( $v ) ) {
			return $v;
		}
		if ( is_float( $v ) ) {
			return is_finite( $v ) ? $v : 0;
		}
		if ( is_string( $v ) ) {
			// Drop control chars (keep tab/newline), then hard length cap.
			$s = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $v );
			if ( strlen( $s ) > 2048 ) {
				$s = substr( $s, 0, 2048 );
			}
			return $s;
		}
		// Objects / resources / closures are never expected from a JSON
		// response — drop them.
		return null;
	}

	private static function sanitize_decimal( $v ) {
		if ( is_string( $v ) ) {
			// Strip non-numeric characters except minus/dot — Google returns
			// micros as plain integers, Meta returns spend as a string like
			// "12.34", both safe.
			$v = preg_replace( '/[^0-9.\-]/', '', $v );
		}
		$f = (float) $v;
		if ( ! is_finite( $f ) ) {
			return 0;
		}
		return $f;
	}

	private static function sanitize_currency( $cur ) {
		$cur = strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) $cur ) );
		if ( strlen( $cur ) > 8 ) {
			$cur = substr( $cur, 0, 8 );
		}
		return $cur;
	}
}
