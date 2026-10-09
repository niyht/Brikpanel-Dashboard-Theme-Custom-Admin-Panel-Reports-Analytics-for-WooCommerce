<?php
/**
 * BrikPanel - phone notifications: storage.
 *
 * - the devices: one row per browser subscription, {prefix}brikpanel_push_devices
 * - the site's signing key (VAPID): the public half readable, the private
 *   half in the secret vault (someone who can read the database would
 *   otherwise be able to send notifications to the staff's phones)
 * - the signed sender tokens, cached per push service
 * - whether this server can send at all
 *
 * A delivery receipt and a send can touch the same device at the same
 * moment, so every write to a row is a single UPDATE, never read-modify-write.
 * All times are stored in UTC.
 *
 * @package BrikPanel
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Brikpanel_Push_Store' ) ) {

	class Brikpanel_Push_Store {

		const OPT_VAPID            = 'brikpanel_push_vapid';
		const OPT_VAPID_UNREADABLE = 'brikpanel_push_vapid_unreadable';
		const OPT_JWT              = 'brikpanel_push_jwt';
		const OPT_ENV              = 'brikpanel_push_env';
		const OPT_ACTIVE           = 'brikpanel_push_active';
		const OPT_SITE             = 'brikpanel_push_site';
		const VAULT_INFO           = 'brikpanel-push-v1';
		const MAX_DEVICES_PER_USER = 20;

		/** @var bool[] Whether a device table exists, by table name (a network switches sites mid-request). */
		private static $table_ok = array();

		/** @var array|false|null Decrypted signing key (per request). */
		private static $vapid = null;

		/** @var string[] The site's address key, by site id. */
		private static $site_key = array();

		/** Forget what this request remembered (tests). */
		public static function flush() {
			self::$table_ok = array();
			self::$vapid    = null;
			self::$site_key = array();
		}

		/** @return string */
		public static function table() {
			global $wpdb;
			return $wpdb->prefix . 'brikpanel_push_devices';
		}

		/**
		 * The device table, for dbDelta (brikpanel_create_table() runs it on every version bump).
		 *
		 * @return string
		 */
		public static function schema_sql() {
			global $wpdb;
			$table = self::table();
			return "CREATE TABLE {$table} (
				id BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
				user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
				endpoint_hash CHAR(64) NOT NULL DEFAULT '',
				endpoint TEXT NOT NULL,
				p256dh VARCHAR(100) NOT NULL DEFAULT '',
				auth VARCHAR(32) NOT NULL DEFAULT '',
				os VARCHAR(12) NOT NULL DEFAULT '',
				browser VARCHAR(12) NOT NULL DEFAULT '',
				standalone TINYINT(1) NOT NULL DEFAULT 0,
				phone TINYINT(1) NOT NULL DEFAULT 0,
				status VARCHAR(10) NOT NULL DEFAULT 'active',
				created_at DATETIME NOT NULL,
				seen_at DATETIME NULL DEFAULT NULL,
				sent_at DATETIME NULL DEFAULT NULL,
				confirmed_at DATETIME NULL DEFAULT NULL,
				pending_count SMALLINT(5) UNSIGNED NOT NULL DEFAULT 0,
				pending_since DATETIME NULL DEFAULT NULL,
				fail_code SMALLINT(5) UNSIGNED NOT NULL DEFAULT 0,
				fail_reason VARCHAR(64) NOT NULL DEFAULT '',
				failed_at DATETIME NULL DEFAULT NULL,
				alerted_at DATETIME NULL DEFAULT NULL,
				removed_at DATETIME NULL DEFAULT NULL,
				UNIQUE KEY uniq_endpoint (endpoint_hash),
				KEY idx_user (user_id),
				KEY idx_status (status)
			) {$wpdb->get_charset_collate()};";
		}

		/**
		 * Creates the table when it is missing: before the version bump runs
		 * brikpanel_create_table(), the first device creates it.
		 *
		 * @return bool Whether the table is there.
		 */
		public static function ensure_table() {
			global $wpdb;
			$table = self::table();
			if ( isset( self::$table_ok[ $table ] ) ) {
				return self::$table_ok[ $table ];
			}
			$like = $wpdb->esc_like( $table );
			if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) ) ) {
				require_once ABSPATH . 'wp-admin/includes/upgrade.php';
				dbDelta( self::schema_sql() );
			}
			self::$table_ok[ $table ] = ( $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) ) );
			return self::$table_ok[ $table ];
		}

		/** @return int Unix time (filter `brikpanel_push_now` for tests). */
		public static function now_ts() {
			return (int) apply_filters( 'brikpanel_push_now', time() );
		}

		/**
		 * @param int|null $ts Unix time; now when omitted.
		 * @return string UTC MySQL datetime.
		 */
		public static function at( $ts = null ) {
			return gmdate( 'Y-m-d H:i:s', null === $ts ? self::now_ts() : (int) $ts );
		}

		// ------------------------------------------------------------------
		// Devices
		// ------------------------------------------------------------------

		/**
		 * @param int $id Device id.
		 * @return array|null
		 */
		public static function get( $id ) {
			if ( ! self::ensure_table() ) {
				return null;
			}
			global $wpdb;
			$table = self::table();
			$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name.
			return $row ? $row : null;
		}

		/**
		 * @param string $endpoint Push endpoint URL.
		 * @return string sha256 hex.
		 */
		public static function hash( $endpoint ) {
			return hash( 'sha256', (string) $endpoint );
		}

		/**
		 * @param string $hash sha256 hex of the endpoint.
		 * @return array|null
		 */
		public static function get_by_hash( $hash ) {
			if ( ! self::ensure_table() ) {
				return null;
			}
			global $wpdb;
			$table = self::table();
			$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE endpoint_hash = %s", (string) $hash ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name.
			return $row ? $row : null;
		}

		/**
		 * A person's devices, newest first; removed ones are left out.
		 *
		 * @param int $user_id User id.
		 * @return array[]
		 */
		public static function user_devices( $user_id ) {
			if ( ! self::ensure_table() ) {
				return array();
			}
			global $wpdb;
			$table = self::table();
			return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d AND status <> 'removed' ORDER BY id DESC", (int) $user_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name.
		}

		/**
		 * Every device that should get the next notification.
		 *
		 * @return array[]
		 */
		public static function active_devices() {
			if ( ! self::ensure_table() ) {
				return array();
			}
			global $wpdb;
			$table = self::table();
			return (array) $wpdb->get_results( "SELECT * FROM {$table} WHERE status = 'active' ORDER BY id ASC", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name, no input.
		}

		/**
		 * @param int $user_id User id.
		 * @return int
		 */
		public static function count_user_active( $user_id ) {
			if ( ! self::ensure_table() ) {
				return 0;
			}
			global $wpdb;
			$table = self::table();
			return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND status = 'active'", (int) $user_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name.
		}

		/**
		 * Adds a device, or refreshes it. A subscription belongs to the
		 * browser: when another person turns notifications on in the same
		 * browser, the row moves to them.
		 *
		 * @param int   $user_id User id.
		 * @param array $sub     endpoint, p256dh, auth (base64url), checked by the caller.
		 * @param array $meta    os, browser, standalone, phone.
		 * @param bool  $revive  Bring a removed or stopped row back to active.
		 * @return array|WP_Error The row.
		 */
		public static function save_device( $user_id, $sub, $meta, $revive = true ) {
			if ( ! self::ensure_table() ) {
				return new WP_Error( 'brikpanel_push_table', 'table' );
			}
			global $wpdb;
			$table   = self::table();
			$hash    = self::hash( $sub['endpoint'] );
			$now     = self::at();
			$current = self::get_by_hash( $hash );
			$fields  = array(
				'user_id'    => (int) $user_id,
				'p256dh'     => (string) $sub['p256dh'],
				'auth'       => (string) $sub['auth'],
				'os'         => substr( sanitize_key( isset( $meta['os'] ) ? $meta['os'] : '' ), 0, 12 ),
				'browser'    => substr( sanitize_key( isset( $meta['browser'] ) ? $meta['browser'] : '' ), 0, 12 ),
				'standalone' => empty( $meta['standalone'] ) ? 0 : 1,
				'phone'      => empty( $meta['phone'] ) ? 0 : 1,
			);

			if ( $current ) {
				if ( 'active' !== $current['status'] && ! $revive ) {
					return $current;
				}
				if ( (int) $current['user_id'] !== (int) $user_id && self::count_user_active( $user_id ) >= self::MAX_DEVICES_PER_USER ) {
					return new WP_Error( 'brikpanel_push_limit', 'limit' );
				}
				$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$wpdb->prepare(
						"UPDATE {$table} SET user_id = %d, p256dh = %s, auth = %s, os = %s, browser = %s, standalone = %d, phone = %d, status = 'active', seen_at = %s, fail_code = 0, fail_reason = '', failed_at = NULL, removed_at = NULL WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name.
						$fields['user_id'],
						$fields['p256dh'],
						$fields['auth'],
						$fields['os'],
						$fields['browser'],
						$fields['standalone'],
						$fields['phone'],
						$now,
						(int) $current['id']
					)
				);
			} else {
				if ( self::count_user_active( $user_id ) >= self::MAX_DEVICES_PER_USER ) {
					return new WP_Error( 'brikpanel_push_limit', 'limit' );
				}
				$ok = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$table,
					array_merge(
						$fields,
						array(
							'endpoint_hash' => $hash,
							'endpoint'      => (string) $sub['endpoint'],
							'status'        => 'active',
							'created_at'    => $now,
							'seen_at'       => $now,
						)
					)
				);
				if ( false === $ok && ! self::get_by_hash( $hash ) ) {
					return new WP_Error( 'brikpanel_push_save', 'save' );
				}
			}
			self::refresh_active_count();
			if ( '' === (string) get_option( self::OPT_SITE, '' ) ) {
				self::remember_site();
			}
			$row = self::get_by_hash( $hash );
			return $row ? $row : new WP_Error( 'brikpanel_push_save', 'save' );
		}

		/**
		 * The page checked in: the device is in use.
		 *
		 * @param int $id Device id.
		 * @return void
		 */
		public static function mark_seen( $id ) {
			self::update( $id, 'seen_at = %s', array( self::at() ) );
		}

		/**
		 * The push service took a notification for this device.
		 *
		 * @param int      $id      Device id.
		 * @param int|null $sent_ts The send time written into the notification's
		 *                          receipt. The push service can take seconds to
		 *                          answer: a time read after its answer would be
		 *                          later than the receipt's, and the receipt would
		 *                          never clear the pending count.
		 * @return void
		 */
		public static function mark_sent( $id, $sent_ts = null ) {
			$sent = self::at( $sent_ts );
			self::update(
				$id,
				'sent_at = %s, pending_count = LEAST(pending_count + 1, 65000), pending_since = COALESCE(pending_since, %s), fail_code = 0, fail_reason = \'\', failed_at = NULL',
				array( $sent, $sent )
			);
		}

		/**
		 * The push service refused it.
		 *
		 * @param int    $id     Device id.
		 * @param int    $code   HTTP status (0: no answer).
		 * @param string $reason Short reason (Apple's JSON reason, "blocked", ...).
		 * @param bool   $gone   The subscription no longer exists (404/410).
		 * @return void
		 */
		public static function mark_failed( $id, $code, $reason, $gone ) {
			$sql = 'fail_code = %d, fail_reason = %s, failed_at = %s' . ( $gone ? ", status = 'gone'" : '' );
			self::update( $id, $sql, array( (int) $code, substr( (string) $reason, 0, 64 ), self::at() ) );
			if ( $gone ) {
				self::refresh_active_count();
			}
		}

		/**
		 * The phone confirmed a notification that was sent at $sent_ts. When
		 * that notification is not older than the first unconfirmed one, the
		 * phone is alive: nothing is pending any more. pending_count is set
		 * before pending_since, so the condition reads the old pending_since
		 * whether the server applies the assignments in order (MySQL) or all
		 * at once (MariaDB SIMULTANEOUS_ASSIGNMENT).
		 *
		 * @param int $id      Device id.
		 * @param int $sent_ts When that notification was sent.
		 * @return void
		 */
		public static function mark_confirmed( $id, $sent_ts ) {
			$sent = self::at( $sent_ts );
			$now  = self::at();
			self::update(
				$id,
				'pending_count = IF(pending_since IS NULL OR pending_since <= %s, 0, pending_count), pending_since = IF(pending_since IS NULL OR pending_since <= %s, NULL, pending_since), confirmed_at = %s, seen_at = %s',
				array( $sent, $sent, $now, $now )
			);
		}

		/**
		 * @param int $id Device id.
		 * @return void
		 */
		public static function mark_removed( $id ) {
			self::update( $id, "status = 'removed', removed_at = %s", array( self::at() ) );
			self::refresh_active_count();
		}

		/**
		 * @param int $id Device id.
		 * @return void
		 */
		public static function mark_alerted( $id ) {
			self::update( $id, 'alerted_at = %s', array( self::at() ) );
		}

		/**
		 * One prepared UPDATE of one row.
		 *
		 * @param int    $id   Device id.
		 * @param string $set  SET clause with placeholders.
		 * @param array  $args Placeholder values.
		 * @return void
		 */
		private static function update( $id, $set, $args ) {
			if ( ! self::ensure_table() ) {
				return;
			}
			global $wpdb;
			$table  = self::table();
			$args[] = (int) $id;
			$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET {$set} WHERE id = %d", $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery -- table name and a fixed SET clause.
		}

		/**
		 * A person left the site: their phones stop at once.
		 *
		 * @param int $user_id User id.
		 * @return void
		 */
		public static function delete_user( $user_id ) {
			if ( ! self::ensure_table() ) {
				return;
			}
			global $wpdb;
			$wpdb->delete( self::table(), array( 'user_id' => (int) $user_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::refresh_active_count();
		}

		/**
		 * Phones that have notifications the phone has not confirmed yet (the
		 * watchdog reads them).
		 *
		 * @return array[]
		 */
		public static function pending_phones() {
			if ( ! self::ensure_table() ) {
				return array();
			}
			global $wpdb;
			$table = self::table();
			return (array) $wpdb->get_results( "SELECT * FROM {$table} WHERE status = 'active' AND phone = 1 AND pending_count > 0 ORDER BY id ASC", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name, no input.
		}

		/**
		 * Removed and stopped devices are kept for a month (the list can say
		 * why a phone stopped), then dropped.
		 *
		 * @param int $days Age in days.
		 * @return void
		 */
		public static function purge_removed( $days = 30 ) {
			if ( ! self::ensure_table() ) {
				return;
			}
			global $wpdb;
			$table  = self::table();
			$before = self::at( self::now_ts() - (int) $days * DAY_IN_SECONDS );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE ( status = 'removed' AND removed_at < %s ) OR ( status = 'gone' AND failed_at < %s )", $before, $before ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery -- table name.
		}

		/**
		 * Keeps the active device count in an option: the order hooks read
		 * it on every order and skip all work when nobody turned notifications on.
		 *
		 * @return int
		 */
		public static function refresh_active_count() {
			global $wpdb;
			$table = self::table();
			$count = self::ensure_table() ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'active'" ) : 0; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name, no input.
			if ( (int) get_option( self::OPT_ACTIVE, 0 ) !== $count ) {
				update_option( self::OPT_ACTIVE, $count, true );
			}
			return $count;
		}

		/** @return int */
		public static function active_count() {
			return (int) get_option( self::OPT_ACTIVE, 0 );
		}

		// ------------------------------------------------------------------
		// The site's address: a copy of the store never sends
		// ------------------------------------------------------------------

		/**
		 * The site's address as stored, without the scheme and a leading
		 * "www.". Read from the stored value (or WP_SITEURL), never through
		 * site_url(): that one changes per request (a visitor's host, http
		 * behind a proxy, language domains), and a checkout must not look like
		 * a copy of the store.
		 *
		 * @return string
		 */
		public static function site_key() {
			$blog = get_current_blog_id();
			if ( isset( self::$site_key[ $blog ] ) ) {
				return self::$site_key[ $blog ];
			}
			if ( ! is_multisite() && defined( 'WP_SITEURL' ) && WP_SITEURL ) {
				$url = (string) WP_SITEURL;
			} else {
				$all = wp_load_alloptions();
				if ( isset( $all['siteurl'] ) ) {
					$url = (string) $all['siteurl'];
				} else {
					global $wpdb;
					$url = (string) $wpdb->get_var( "SELECT option_value FROM {$wpdb->options} WHERE option_name = 'siteurl' LIMIT 1" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				}
			}
			$url = strtolower( trim( $url ) );
			$url = (string) preg_replace( '#^[a-z][a-z0-9+.\-]*://#', '', $url );
			$url = (string) preg_replace( '#^www\.#', '', $url );

			self::$site_key[ $blog ] = untrailingslashit( $url );
			return self::$site_key[ $blog ];
		}

		/**
		 * Whether this is the store the phones were turned on for. A staging
		 * copy or a local copy of the database carries the same devices and
		 * keys: its test orders must not reach the owner's phone.
		 *
		 * @return bool
		 */
		public static function site_matches() {
			$stored = (string) get_option( self::OPT_SITE, '' );
			return '' === $stored || $stored === self::site_key();
		}

		/**
		 * Phones get this site's orders (first device, or an administrator
		 * confirmed the new address).
		 *
		 * @return void
		 */
		public static function remember_site() {
			update_option( self::OPT_SITE, self::site_key(), true );
		}

		// ------------------------------------------------------------------
		// Whether this server can send
		// ------------------------------------------------------------------

		/**
		 * Crypto support, checked once per PHP/OpenSSL build.
		 *
		 * @return array{sig:string,crypto:bool}
		 */
		public static function env() {
			$sig = PHP_VERSION . '|' . ( defined( 'OPENSSL_VERSION_TEXT' ) ? OPENSSL_VERSION_TEXT : '' );
			$env = get_option( self::OPT_ENV );
			if ( is_array( $env ) && isset( $env['sig'] ) && $env['sig'] === $sig ) {
				return $env;
			}
			$env = array(
				'sig'    => $sig,
				'crypto' => class_exists( 'Brikpanel_WebPush' ) && Brikpanel_WebPush::available() && self::vault_ready(),
			);
			update_option( self::OPT_ENV, $env, true );
			return $env;
		}

		/** @return bool */
		public static function crypto_ok() {
			$env = self::env();
			return ! empty( $env['crypto'] );
		}

		// ------------------------------------------------------------------
		// The site's signing key (VAPID)
		// ------------------------------------------------------------------

		/** @return bool */
		private static function vault_ready() {
			if ( ! class_exists( 'Brikpanel_Secret_Vault' ) && defined( 'BRIKPANEL_PATH' ) && is_readable( BRIKPANEL_PATH . 'includes/class-brikpanel-secret-vault.php' ) ) {
				require_once BRIKPANEL_PATH . 'includes/class-brikpanel-secret-vault.php';
			}
			return class_exists( 'Brikpanel_Secret_Vault' ) && Brikpanel_Secret_Vault::is_available();
		}

		/**
		 * @param string $blob Vault envelope.
		 * @return string Plaintext, '' when it cannot be opened.
		 */
		private static function vault_open( $blob ) {
			if ( ! self::vault_ready() ) {
				return '';
			}
			$info  = Brikpanel_Secret_Vault::info( self::VAULT_INFO );
			$plain = Brikpanel_Secret_Vault::decrypt( (string) $blob, $info, $info );
			return is_string( $plain ) ? $plain : '';
		}

		/**
		 * Creates the key the first time; never replaces an existing one.
		 * Two requests can get here at once: INSERT IGNORE lets only one of
		 * them write, and both read back the same key. Otherwise a phone could
		 * subscribe with a key that was overwritten a moment later.
		 *
		 * @return array|null Stored value.
		 */
		private static function create_vapid() {
			if ( ! class_exists( 'Brikpanel_WebPush' ) || ! Brikpanel_WebPush::available() || ! self::vault_ready() ) {
				return null;
			}
			$keys = Brikpanel_WebPush::create_keys();
			if ( ! $keys ) {
				return null;
			}
			$blob = Brikpanel_Secret_Vault::encrypt( Brikpanel_WebPush::b64url_encode( $keys['private'] ), Brikpanel_Secret_Vault::info( self::VAULT_INFO ) );
			if ( ! is_string( $blob ) || '' === $blob ) {
				return null;
			}
			$value = array(
				'v'    => 1,
				'pub'  => Brikpanel_WebPush::b64url_encode( $keys['public'] ),
				'priv' => $blob,
				'at'   => time(),
			);
			global $wpdb;
			$autoload = version_compare( get_bloginfo( 'version' ), '6.6', '>=' ) ? 'off' : 'no';
			$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, %s)",
					self::OPT_VAPID,
					maybe_serialize( $value ),
					$autoload
				)
			);
			wp_cache_delete( self::OPT_VAPID, 'options' );
			$notoptions = wp_cache_get( 'notoptions', 'options' );
			if ( is_array( $notoptions ) && isset( $notoptions[ self::OPT_VAPID ] ) ) {
				unset( $notoptions[ self::OPT_VAPID ] );
				wp_cache_set( 'notoptions', $notoptions, 'options' );
			}
			$stored = get_option( self::OPT_VAPID );
			return is_array( $stored ) ? $stored : null;
		}

		/**
		 * The public key for pages, base64url. Never decrypts anything.
		 *
		 * @return string '' when there is none and none can be made.
		 */
		public static function vapid_public() {
			$stored = get_option( self::OPT_VAPID );
			if ( ! is_array( $stored ) || empty( $stored['pub'] ) ) {
				$stored = self::create_vapid();
			}
			return is_array( $stored ) && ! empty( $stored['pub'] ) ? (string) $stored['pub'] : '';
		}

		/**
		 * The whole key, decrypted, for signing.
		 *
		 * @return array{pub:string,priv:string}|null Raw keys; null when missing or unreadable.
		 */
		public static function vapid() {
			if ( null !== self::$vapid ) {
				return self::$vapid ? self::$vapid : null;
			}
			$stored = get_option( self::OPT_VAPID );
			if ( ! is_array( $stored ) || empty( $stored['pub'] ) || empty( $stored['priv'] ) ) {
				$stored = self::create_vapid();
			}
			$pub  = is_array( $stored ) && isset( $stored['pub'] ) ? Brikpanel_WebPush::b64url_decode( $stored['pub'] ) : '';
			$priv = is_array( $stored ) && isset( $stored['priv'] ) ? Brikpanel_WebPush::b64url_decode( self::vault_open( $stored['priv'] ) ) : '';
			if ( 65 !== strlen( $pub ) || 32 !== strlen( $priv ) ) {
				self::$vapid = false;
				return null;
			}
			self::$vapid = array(
				'pub'  => $pub,
				'priv' => $priv,
			);
			return self::$vapid;
		}

		/**
		 * The key exists but cannot be opened (the site's security keys in
		 * wp-config.php changed). Sending waits until an administrator creates
		 * new keys; the old value is never deleted.
		 *
		 * @return bool
		 */
		public static function vapid_unreadable() {
			$stored = get_option( self::OPT_VAPID );
			if ( ! is_array( $stored ) || empty( $stored['priv'] ) ) {
				return false;
			}
			return '' === self::vault_open( $stored['priv'] );
		}

		/**
		 * New keys after the old ones became unreadable. The old value is
		 * kept aside; every phone subscribes again by itself on its next visit
		 * (brikpanel-push.js notices the key changed).
		 *
		 * @return bool
		 */
		public static function reset_vapid() {
			$stored = get_option( self::OPT_VAPID );
			if ( false !== $stored ) {
				update_option( self::OPT_VAPID_UNREADABLE, $stored, false );
				delete_option( self::OPT_VAPID );
			}
			delete_option( self::OPT_JWT );
			self::$vapid = null;
			return '' !== self::vapid_public();
		}

		/**
		 * The contact the token names (VAPID "sub"): the site's address as an
		 * https URL. Apple refuses tokens without a valid mailto: or https: one.
		 *
		 * @return string
		 */
		public static function subject() {
			$parts = wp_parse_url( home_url( '/' ) );
			$host  = isset( $parts['host'] ) ? strtolower( $parts['host'] ) : 'localhost';
			return 'https://' . $host . ( isset( $parts['port'] ) && 443 !== (int) $parts['port'] && 80 !== (int) $parts['port'] ? ':' . (int) $parts['port'] : '' );
		}

		/**
		 * A signed token for one push service, reused until an hour before it
		 * expires (Apple asks senders not to refresh more than once an hour).
		 *
		 * @param string $audience scheme://host of the push service.
		 * @return string|null
		 */
		public static function jwt( $audience ) {
			$key = self::vapid();
			if ( ! $key || '' === $audience ) {
				return null;
			}
			$now   = self::now_ts();
			$pub   = Brikpanel_WebPush::b64url_encode( $key['pub'] );
			$cache = get_option( self::OPT_JWT, array() );
			if ( ! is_array( $cache ) ) {
				$cache = array();
			}
			if ( isset( $cache[ $audience ]['t'], $cache[ $audience ]['exp'], $cache[ $audience ]['k'] )
				&& $pub === $cache[ $audience ]['k'] && (int) $cache[ $audience ]['exp'] - HOUR_IN_SECONDS > $now ) {
				return (string) $cache[ $audience ]['t'];
			}
			$exp = $now + 12 * HOUR_IN_SECONDS;
			$jwt = Brikpanel_WebPush::vapid_jwt( $audience, self::subject(), $key['priv'], $key['pub'], $exp );
			if ( ! $jwt ) {
				return null;
			}
			foreach ( $cache as $aud => $entry ) {
				if ( empty( $entry['exp'] ) || (int) $entry['exp'] <= $now ) {
					unset( $cache[ $aud ] );
				}
			}
			$cache[ $audience ] = array(
				't'   => $jwt,
				'exp' => $exp,
				'k'   => $pub,
			);
			update_option( self::OPT_JWT, $cache, false );
			return $jwt;
		}

		/**
		 * A push service refused a token: the next message signs a new one.
		 *
		 * @param string $audience scheme://host of the push service.
		 * @return void
		 */
		public static function forget_jwt( $audience ) {
			$cache = get_option( self::OPT_JWT, array() );
			if ( is_array( $cache ) && isset( $cache[ $audience ] ) ) {
				unset( $cache[ $audience ] );
				update_option( self::OPT_JWT, $cache, false );
			}
		}
	}
}
