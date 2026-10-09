<?php
/**
 * BrikPanel - phone notifications: sending.
 *
 * When an order first reaches one of the "new order" statuses
 * (brikpanel_new_order_statuses(): processing, completed, on-hold), every
 * device that turned on order notifications gets one, from this server
 * straight to the browser's push service (Apple, Google, Mozilla, Microsoft).
 *
 * The order hooks (brikpanel-push.php) only mark the order
 * (`_brikpanel_push` = "q:<time>") and keep it for the end of the request.
 * Sending waits until the page has been handed over:
 *  - WP-CLI, cron and background jobs: at the end of the request;
 *  - PHP-FPM and LiteSpeed: after the response is finished;
 *  - anything else: a background job, so a checkout never waits for a push service.
 * The first two also leave a job two minutes out, in case the request dies
 * mid-way. Before sending, the order is locked and its mark read again from
 * the database: an order is sent once ("s:<time>:<accepted>/<tried>").
 *
 * Each notification carries a signed delivery receipt the phone sends back.
 * A phone that stops confirming for six hours, or that its push service
 * reports as gone, gets its owner an email.
 *
 * @package BrikPanel
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Brikpanel_Push_Sender' ) ) {

	class Brikpanel_Push_Sender {

		const META         = '_brikpanel_push';
		const TTL          = 14400;  // The push service drops a notification it could not deliver in 4 hours.
		const PAD          = 1024;   // Every message is padded to the same size: its length tells nothing.
		const TIMEOUT      = 8;      // Seconds for one push service.
		const BUDGET       = 25;     // Seconds for one run, all devices together.
		const SUMMARY_FROM = 3;      // This many orders at once: one "3 new orders" message.
		const FLOOD_MAX    = 30;     // Messages per window and site...
		const FLOOD_WINDOW = 300;    // ...of five minutes; the rest wait for one summary at its end.
		const MAX_AGE      = 172800; // An order created or paid over 48 hours ago (an import) is not new.
		const LATE         = 14400;  // A queued order not sent within 4 hours is not sent at all.

		const OPT_FLOOD      = 'brikpanel_push_flood';
		const OPT_KEYS_ALERT = 'brikpanel_push_keys_alerted';

		const HOOK_ORDER    = 'brikpanel_push_order';
		const HOOK_RETRY    = 'brikpanel_push_retry';
		const HOOK_HELD     = 'brikpanel_push_held';
		const HOOK_WATCHDOG = 'brikpanel_push_watchdog';

		/** @var array<int,int[]> Orders queued in this request, by site id. */
		private static $batch = array();

		/** @var bool[] "site:order" already queued in this request. */
		private static $seen = array();

		/** @var bool Whether the end-of-request sender is hooked. */
		private static $hooked = false;

		/** @var string[] Status in storage before a save, "site:order" => status. */
		private static $stash = array();

		/** Forget this request's queue (tests). */
		public static function reset() {
			self::$batch = array();
			self::$seen  = array();
			self::$stash = array();
			if ( self::$hooked ) {
				remove_action( 'shutdown', array( __CLASS__, 'dispatch' ), PHP_INT_MAX );
			}
			self::$hooked = false;
		}

		/**
		 * Orders waiting for the end of this request (tests).
		 *
		 * @return array<int,int[]>
		 */
		public static function pending() {
			return self::$batch;
		}

		// ------------------------------------------------------------------
		// Which orders
		// ------------------------------------------------------------------

		/**
		 * Whether new orders can be queued here at all: on, someone has a
		 * device, and this is not a copy of the store.
		 *
		 * @return bool
		 */
		public static function accepting() {
			return brikpanel_push_enabled()
				&& Brikpanel_Push_Store::active_count() > 0
				&& Brikpanel_Push_Store::site_matches();
		}

		/**
		 * A real order, in a "new order" status, created or paid in the last 48 hours.
		 *
		 * @param mixed $order Order.
		 * @return bool
		 */
		public static function is_new_order( $order ) {
			if ( ! $order instanceof WC_Order || 'shop_order' !== $order->get_type() ) {
				return false;
			}
			if ( ! in_array( (string) $order->get_status(), brikpanel_new_order_statuses(), true ) ) {
				return false;
			}
			$latest = 0;
			foreach ( array( $order->get_date_created(), $order->get_date_paid() ) as $date ) {
				if ( $date instanceof WC_DateTime ) {
					$latest = max( $latest, $date->getTimestamp() );
				}
			}
			// No date at all: an order being created right now.
			return 0 === $latest || $latest >= Brikpanel_Push_Store::now_ts() - self::MAX_AGE;
		}

		/**
		 * Marks an order for a notification at the end of this request.
		 *
		 * @param int           $order_id Order id.
		 * @param WC_Order|null $order    The order, when the hook has it.
		 * @return bool Whether it was queued.
		 */
		public static function queue( $order_id, $order = null ) {
			$order_id = (int) $order_id;
			$blog     = get_current_blog_id();
			if ( $order_id <= 0 || isset( self::$seen[ $blog . ':' . $order_id ] ) || ! self::accepting() ) {
				return false;
			}
			try {
				if ( ! $order instanceof WC_Order || (int) $order->get_id() !== $order_id ) {
					$order = wc_get_order( $order_id );
				}
				if ( ! self::is_new_order( $order ) || '' !== (string) $order->get_meta( self::META, true, 'edit' ) ) {
					return false;
				}
				/**
				 * Whether a new order gets a phone notification.
				 *
				 * @since 3.3.32
				 * @param bool     $notify Default true.
				 * @param WC_Order $order  The order.
				 */
				if ( ! apply_filters( 'brikpanel_push_should_notify', true, $order ) ) {
					return false;
				}
				self::$seen[ $blog . ':' . $order_id ] = true;
				self::write_mark( $order, 'q:' . Brikpanel_Push_Store::now_ts() );
				self::$batch[ $blog ][] = $order_id;
				if ( ! self::$hooked ) {
					self::$hooked = true;
					add_action( 'shutdown', array( __CLASS__, 'dispatch' ), PHP_INT_MAX );
				}
				return true;
			} catch ( \Throwable $e ) {
				self::log( 'queue', $e );
				return false;
			}
		}

		/**
		 * The status an order has in storage, without the "wc-" prefix.
		 * Read once per save that moves an order into a "new order" status.
		 *
		 * @param int $order_id Order id.
		 * @return string '' when unknown.
		 */
		public static function stored_status( $order_id ) {
			global $wpdb;
			$order_id = (int) $order_id;
			if ( $order_id <= 0 ) {
				return '';
			}
			if ( function_exists( 'brikpanel_wc_hpos_enabled' ) && brikpanel_wc_hpos_enabled() ) {
				$status = $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$wpdb->prefix}wc_orders WHERE id = %d", $order_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			} else {
				$status = $wpdb->get_var( $wpdb->prepare( "SELECT post_status FROM {$wpdb->posts} WHERE ID = %d", $order_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			}
			$status = (string) $status;
			return 0 === strpos( $status, 'wc-' ) ? substr( $status, 3 ) : $status;
		}

		/**
		 * Keeps (or hands back) an order's stored status across one save.
		 *
		 * @param int         $order_id Order id.
		 * @param string|null $value    Status to keep; null to read.
		 * @param bool        $pop      Read and forget.
		 * @return string|null
		 */
		public static function stash( $order_id, $value = null, $pop = false ) {
			$key = get_current_blog_id() . ':' . (int) $order_id;
			if ( null !== $value ) {
				self::$stash[ $key ] = (string) $value;
				return self::$stash[ $key ];
			}
			$prev = isset( self::$stash[ $key ] ) ? self::$stash[ $key ] : null;
			if ( $pop ) {
				unset( self::$stash[ $key ] );
			}
			return $prev;
		}

		// ------------------------------------------------------------------
		// When
		// ------------------------------------------------------------------

		/**
		 * How this request sends: 'inline' (nobody waits for it), 'finish'
		 * (hand the response over first) or 'async' (a background job).
		 *
		 * @return string
		 */
		public static function mode() {
			if ( ( defined( 'WP_CLI' ) && WP_CLI ) || wp_doing_cron() || did_action( 'action_scheduler_before_execute' ) ) {
				$mode = 'inline';
			} elseif ( function_exists( 'fastcgi_finish_request' ) || function_exists( 'litespeed_finish_request' ) ) {
				$mode = 'finish';
			} else {
				$mode = 'async';
			}
			/**
			 * How phone notifications leave this request.
			 *
			 * @since 3.3.32
			 * @param string $mode 'inline', 'finish' or 'async'.
			 */
			$mode = apply_filters( 'brikpanel_push_dispatch_mode', $mode );
			return in_array( $mode, array( 'inline', 'finish', 'async' ), true ) ? $mode : 'async';
		}

		/**
		 * End of the request: sends what this request queued.
		 *
		 * @return void
		 */
		public static function dispatch() {
			$batches      = self::$batch;
			self::$batch  = array();
			self::$hooked = false;
			if ( ! $batches || ! class_exists( 'Brikpanel_Cron' ) ) {
				return;
			}
			$mode = self::mode();
			$now  = Brikpanel_Push_Store::now_ts();
			$todo = array();

			foreach ( $batches as $blog => $ids ) {
				$ids      = array_values( array_unique( array_map( 'intval', $ids ) ) );
				$switched = is_multisite() && (int) $blog !== get_current_blog_id() && switch_to_blog( (int) $blog );
				try {
					if ( 'async' === $mode ) {
						if ( false === Brikpanel_Cron::enqueue_async( self::HOOK_ORDER, array( 'orders' => $ids ) ) ) {
							$todo[ $blog ] = $ids;
						}
					} else {
						// In case this request dies while sending: the job finds the
						// orders already sent and does nothing.
						Brikpanel_Cron::schedule_single( $now + 2 * MINUTE_IN_SECONDS, self::HOOK_ORDER, array( 'orders' => $ids ) );
						$todo[ $blog ] = $ids;
					}
				} finally {
					if ( $switched ) {
						restore_current_blog();
					}
				}
			}
			if ( ! $todo ) {
				return;
			}
			if ( 'finish' === $mode ) {
				self::finish_response();
			}
			foreach ( $todo as $blog => $ids ) {
				$switched = is_multisite() && (int) $blog !== get_current_blog_id() && switch_to_blog( (int) $blog );
				try {
					self::send_orders( $ids );
				} catch ( \Throwable $e ) {
					self::log( 'dispatch', $e );
				} finally {
					if ( $switched ) {
						restore_current_blog();
					}
				}
			}
		}

		/**
		 * Hands the response over, so nobody waits for the push services.
		 *
		 * @return void
		 */
		private static function finish_response() {
			ignore_user_abort( true );
			if ( function_exists( 'session_status' ) && PHP_SESSION_ACTIVE === session_status() ) {
				session_write_close();
			}
			if ( function_exists( 'fastcgi_finish_request' ) ) {
				fastcgi_finish_request();
			} elseif ( function_exists( 'litespeed_finish_request' ) ) {
				litespeed_finish_request();
			}
		}

		// ------------------------------------------------------------------
		// Sending
		// ------------------------------------------------------------------

		/**
		 * Whether this site sends at all right now.
		 *
		 * @return bool
		 */
		private static function live() {
			return brikpanel_push_enabled() && Brikpanel_Push_Store::site_matches();
		}

		/**
		 * Sends queued orders: one message each, or one summary for three or more.
		 *
		 * @param int[] $ids Order ids.
		 * @return void
		 */
		public static function send_orders( array $ids ) {
			$locked = array();
			$orders = array();
			$busy   = array();
			try {
				foreach ( array_unique( array_map( 'intval', $ids ) ) as $id ) {
					if ( $id <= 0 ) {
						continue;
					}
					if ( ! self::lock( $id ) ) {
						$busy[] = $id;
						continue;
					}
					$locked[] = $id;
					$mark     = self::stored_mark( $id );
					if ( 0 !== strpos( $mark, 'q:' ) ) {
						continue; // Sent, skipped or never queued.
					}
					$order = wc_get_order( $id );
					if ( ! $order instanceof WC_Order ) {
						continue;
					}
					$why = self::skip_reason( $order, (int) substr( $mark, 2 ) );
					if ( '' !== $why ) {
						self::write_mark( $order, 'x:' . Brikpanel_Push_Store::now_ts() . ':' . $why );
						continue;
					}
					$orders[ $id ] = $order;
				}
				if ( $orders ) {
					self::send_new( $orders );
				}
			} finally {
				foreach ( $locked as $id ) {
					self::unlock( $id );
				}
			}
			if ( $busy && class_exists( 'Brikpanel_Cron' ) ) {
				// Another request is sending them; look again in a minute.
				Brikpanel_Cron::schedule_single( Brikpanel_Push_Store::now_ts() + MINUTE_IN_SECONDS, self::HOOK_ORDER, array( 'orders' => $busy ) );
			}
		}

		/**
		 * Why a queued order is not sent ('' = send it).
		 *
		 * @param WC_Order $order     Order.
		 * @param int      $queued_at When it was queued.
		 * @return string
		 */
		private static function skip_reason( $order, $queued_at ) {
			if ( ! self::live() ) {
				return 'off';
			}
			if ( 'shop_order' !== $order->get_type() ) {
				return 'type';
			}
			// Cancelled or refunded in the meantime. A store's own status after
			// processing (packed, shipped) still counts as a new order.
			if ( in_array( (string) $order->get_status(), array( 'pending', 'failed', 'cancelled', 'refunded', 'checkout-draft', 'draft', 'auto-draft', 'trash' ), true ) ) {
				return 'status';
			}
			if ( $queued_at > 0 && Brikpanel_Push_Store::now_ts() - $queued_at > self::LATE ) {
				return 'late';
			}
			return '';
		}

		/**
		 * @param WC_Order[] $orders Orders to announce, by id.
		 * @return void
		 */
		private static function send_new( array $orders ) {
			$now     = Brikpanel_Push_Store::now_ts();
			$devices = self::recipients();
			if ( ! $devices ) {
				foreach ( $orders as $order ) {
					self::write_mark( $order, 's:' . $now . ':0/0' );
				}
				return;
			}
			if ( ! Brikpanel_Push_Store::vapid() ) {
				self::keys_problem();
				foreach ( $orders as $order ) {
					self::write_mark( $order, 'x:' . $now . ':keys' );
				}
				return;
			}

			if ( count( $orders ) >= self::SUMMARY_FROM ) {
				$groups = array(
					array(
						'spec'   => array(
							'kind'  => 'summary',
							'count' => count( $orders ),
						),
						'orders' => $orders,
					),
				);
			} else {
				$groups = array();
				foreach ( $orders as $id => $order ) {
					$groups[] = array(
						'spec'   => array(
							'kind'  => 'order',
							'order' => (int) $id,
							'obj'   => $order,
						),
						'orders' => array( $order ),
					);
				}
			}

			foreach ( $groups as $group ) {
				if ( ! self::flood_take( count( $group['orders'] ) ) ) {
					foreach ( $group['orders'] as $order ) {
						self::write_mark( $order, 'h:' . $now );
					}
					continue;
				}
				$result = self::deliver( $group['spec'], $devices );
				foreach ( $group['orders'] as $order ) {
					self::write_mark( $order, 's:' . $now . ':' . $result['ok'] . '/' . $result['tried'] );
				}
			}
		}

		/**
		 * The devices that get the next order: active, of people who still
		 * manage the store and have BrikPanel on.
		 *
		 * @return array[]
		 */
		public static function recipients() {
			$out   = array();
			$users = array();
			foreach ( Brikpanel_Push_Store::active_devices() as $row ) {
				$uid = (int) $row['user_id'];
				if ( ! isset( $users[ $uid ] ) ) {
					$users[ $uid ] = self::user_may_receive( $uid );
				}
				if ( $users[ $uid ] && self::allowed_endpoint( $row['endpoint'] ) ) {
					$out[] = $row;
				}
			}
			return $out;
		}

		/**
		 * A person gets order notifications while they manage the store and
		 * BrikPanel is on for them. Losing the role pauses them; getting it
		 * back resumes them.
		 *
		 * @param int $user_id User id.
		 * @return bool
		 */
		public static function user_may_receive( $user_id ) {
			$user = get_userdata( (int) $user_id );
			if ( ! $user instanceof WP_User || ! $user->exists() || ! user_can( $user, 'manage_woocommerce' ) ) {
				return false;
			}
			if ( function_exists( 'brikpanel_master_enabled' ) && ! brikpanel_master_enabled() ) {
				return false;
			}
			return ! ( function_exists( 'brikpanel_access_is_disabled_for_user' ) && brikpanel_access_is_disabled_for_user( $user ) );
		}

		/**
		 * Push services a subscription may point at. Anything else (an
		 * address inside the network, a typo) is never called.
		 *
		 * @param string $url Endpoint.
		 * @return bool
		 */
		public static function allowed_endpoint( $url ) {
			$url = (string) $url;
			if ( strlen( $url ) > 1024 || 0 !== strpos( $url, 'https://' ) ) {
				return false;
			}
			$parts = wp_parse_url( $url );
			$host  = isset( $parts['host'] ) ? strtolower( (string) $parts['host'] ) : '';
			if ( '' === $host || isset( $parts['user'] ) || ( isset( $parts['port'] ) && 443 !== (int) $parts['port'] ) ) {
				return false;
			}
			/**
			 * Push service hosts BrikPanel sends to. "*." matches any subdomain.
			 *
			 * @since 3.3.32
			 * @param string[] $hosts Hosts.
			 */
			$hosts = (array) apply_filters( 'brikpanel_push_allowed_hosts', array( 'fcm.googleapis.com', '*.push.apple.com', 'updates.push.services.mozilla.com', '*.notify.windows.com' ) );
			foreach ( $hosts as $pattern ) {
				$pattern = strtolower( (string) $pattern );
				if ( 0 === strpos( $pattern, '*.' ) ) {
					$suffix = substr( $pattern, 1 );
					if ( strlen( $host ) > strlen( $suffix ) && substr( $host, -strlen( $suffix ) ) === $suffix ) {
						return true;
					}
				} elseif ( $host === $pattern ) {
					return true;
				}
			}
			return false;
		}

		/**
		 * Sends one message to devices, in each owner's language.
		 *
		 * @param array   $spec    What to say: kind order|summary|test (+ order, obj, count).
		 * @param array[] $devices Device rows.
		 * @param int     $attempt 0 the first time, then 1 and 2 for retries.
		 * @return array{ok:int,tried:int}
		 */
		private static function deliver( array $spec, array $devices, $attempt = 0 ) {
			$result = array(
				'ok'    => 0,
				'tried' => 0,
			);
			$keys = Brikpanel_Push_Store::vapid();
			if ( ! $keys || ! $devices ) {
				return $result;
			}
			$start  = microtime( true );
			$retry  = array();
			$alerts = array();
			$phones = false;

			foreach ( self::by_locale( $devices ) as $locale => $rows ) {
				$switched = self::switch_locale( $locale );
				try {
					$base = self::message( $spec, $locale );
					if ( null === $base ) {
						continue;
					}
					foreach ( $rows as $row ) {
						$left = self::BUDGET - ( microtime( true ) - $start );
						if ( $left < 1 ) {
							$retry[ 30 ][] = (int) $row['id'];
							continue;
						}
						++$result['tried'];
						$out = self::push( $row, $base, $keys, min( self::TIMEOUT, $left ) );
						self::record( $row, $out );
						if ( $out['ok'] ) {
							++$result['ok'];
							$phones = $phones || ! empty( $row['phone'] );
						} elseif ( $out['gone'] ) {
							if ( ! empty( $row['phone'] ) && ! empty( $row['confirmed_at'] ) ) {
								$alerts[] = $row;
							}
						} elseif ( $out['retry'] > 0 && $attempt < 2 ) {
							$retry[ $out['retry'] ][] = (int) $row['id'];
						}
					}
				} finally {
					if ( $switched ) {
						restore_previous_locale();
					}
				}
			}

			if ( $retry && class_exists( 'Brikpanel_Cron' ) ) {
				$job = $spec;
				unset( $job['obj'] );
				foreach ( $retry as $delay => $ids ) {
					Brikpanel_Cron::schedule_single(
						Brikpanel_Push_Store::now_ts() + (int) $delay,
						self::HOOK_RETRY,
						array(
							'spec'    => $job,
							'devices' => $ids,
							'n'       => $attempt + 1,
						)
					);
				}
			}
			foreach ( $alerts as $row ) {
				self::alert( $row, 'gone' );
			}
			if ( $phones ) {
				self::schedule_watchdog( Brikpanel_Push_Store::now_ts() + 35 * MINUTE_IN_SECONDS );
			}
			return $result;
		}

		/**
		 * One test message to one device, right away (the "Send test" button).
		 *
		 * @param array $row Device row.
		 * @return array{ok:bool,code:int,reason:string}
		 */
		public static function send_test( array $row ) {
			$keys = Brikpanel_Push_Store::vapid();
			if ( ! $keys ) {
				return array(
					'ok'     => false,
					'code'   => 0,
					'reason' => 'keys',
				);
			}
			$base = self::message( array( 'kind' => 'test' ), determine_locale() );
			$out  = self::push( $row, $base, $keys, self::TIMEOUT );
			self::record( $row, $out );
			if ( $out['ok'] && ! empty( $row['phone'] ) ) {
				self::schedule_watchdog( Brikpanel_Push_Store::now_ts() + 35 * MINUTE_IN_SECONDS );
			}
			return array(
				'ok'     => (bool) $out['ok'],
				'code'   => (int) $out['code'],
				'reason' => (string) $out['reason'],
			);
		}

		/**
		 * Stores what the push service answered.
		 *
		 * @param array $row Device row.
		 * @param array $out push() result.
		 * @return void
		 */
		private static function record( array $row, array $out ) {
			if ( $out['ok'] ) {
				// The send time the receipt carries, not the time the push service answered.
				Brikpanel_Push_Store::mark_sent( (int) $row['id'], isset( $out['t'] ) ? (int) $out['t'] : null );
			} else {
				Brikpanel_Push_Store::mark_failed( (int) $row['id'], (int) $out['code'], (string) $out['reason'], (bool) $out['gone'] );
			}
		}

		/**
		 * Encrypts and posts one message to one device.
		 *
		 * @param array  $row     Device row.
		 * @param array  $base    message() result.
		 * @param array  $keys    The site's signing key.
		 * @param float  $timeout Seconds.
		 * @return array{ok:bool,code:int,reason:string,retry:int,gone:bool,t:int}
		 */
		private static function push( array $row, array $base, array $keys, $timeout ) {
			$sent = Brikpanel_Push_Store::now_ts();
			$fail = array(
				'ok'     => false,
				'code'   => 0,
				'reason' => '',
				'retry'  => 0,
				'gone'   => false,
				't'      => $sent,
			);
			$endpoint = (string) $row['endpoint'];
			if ( ! self::allowed_endpoint( $endpoint ) ) {
				$fail['reason'] = 'host';
				return $fail;
			}

			$payload = $base;
			$payload['notification']['data']['r'] = self::receipt_token( (int) $row['id'], $sent );
			$json = (string) wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			if ( strlen( $json ) > 3000 ) {
				$payload['notification']['body'] = '';
				$json = (string) wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			}
			$body = Brikpanel_WebPush::encrypt( $json, Brikpanel_WebPush::b64url_decode( (string) $row['p256dh'] ), Brikpanel_WebPush::b64url_decode( (string) $row['auth'] ), self::PAD );
			if ( null === $body ) {
				$fail['reason'] = 'device keys';
				return $fail;
			}
			$audience = Brikpanel_WebPush::audience( $endpoint );
			$jwt      = Brikpanel_Push_Store::jwt( $audience );
			if ( null === $jwt ) {
				$fail['reason'] = 'sign';
				return $fail;
			}

			$response = wp_safe_remote_post(
				$endpoint,
				array(
					'timeout'     => max( 1, (int) floor( $timeout ) ),
					'redirection' => 0,
					'headers'     => array(
						'Authorization'    => Brikpanel_WebPush::vapid_header( $jwt, $keys['pub'] ),
						'Content-Encoding' => 'aes128gcm',
						'Content-Type'     => 'application/octet-stream',
						'TTL'              => (string) self::TTL,
						'Urgency'          => 'high',
					),
					'body'        => $body,
				)
			);
			$out      = self::classify( $response, $audience );
			$out['t'] = $sent;
			return $out;
		}

		/**
		 * Reads a push service's answer.
		 *
		 * @param array|WP_Error $response wp_safe_remote_post() result.
		 * @param string         $audience Push service.
		 * @return array{ok:bool,code:int,reason:string,retry:int,gone:bool}
		 */
		public static function classify( $response, $audience = '' ) {
			$out = array(
				'ok'     => false,
				'code'   => 0,
				'reason' => '',
				'retry'  => 0,
				'gone'   => false,
			);
			if ( is_wp_error( $response ) ) {
				if ( 'http_request_not_executed' === $response->get_error_code() ) {
					// WP_HTTP_BLOCK_EXTERNAL: Settings lists the hosts to allow.
					$out['reason'] = 'blocked';
					return $out;
				}
				$message       = strtolower( $response->get_error_message() );
				$out['reason'] = ( false !== strpos( $message, 'timed out' ) || false !== strpos( $message, 'error 28' ) ) ? 'timeout' : 'network';
				$out['retry']  = 60;
				return $out;
			}
			$status      = (int) wp_remote_retrieve_response_code( $response );
			$out['code'] = $status;
			if ( $status >= 200 && $status < 300 ) {
				$out['ok'] = true;
				return $out;
			}
			$out['reason'] = self::reason( (string) wp_remote_retrieve_body( $response ) );
			if ( 404 === $status || 410 === $status || ( 400 === $status && in_array( $out['reason'], array( 'BadDeviceToken', 'DeviceTokenNotForTopic' ), true ) ) ) {
				$out['gone'] = true;
			} elseif ( 429 === $status ) {
				$out['retry'] = self::retry_after( (string) wp_remote_retrieve_header( $response, 'retry-after' ) );
			} elseif ( $status >= 500 ) {
				$out['retry'] = 60;
			} elseif ( 403 === $status && false !== stripos( $out['reason'], 'jwt' ) && '' !== $audience ) {
				Brikpanel_Push_Store::forget_jwt( $audience );
			}
			return $out;
		}

		/**
		 * A short reason from an error body (Apple: {"reason":"BadJwtToken"}).
		 *
		 * @param string $body Response body.
		 * @return string
		 */
		private static function reason( $body ) {
			$text = '';
			$json = json_decode( $body, true );
			if ( is_array( $json ) ) {
				foreach ( array( 'reason', 'message', 'error' ) as $key ) {
					if ( isset( $json[ $key ] ) && is_scalar( $json[ $key ] ) && '' !== (string) $json[ $key ] ) {
						$text = (string) $json[ $key ];
						break;
					}
				}
			}
			if ( '' === $text ) {
				$text = wp_strip_all_tags( $body );
			}
			$text = (string) preg_replace( '/[^A-Za-z0-9 _.:\-]/', '', $text );
			return substr( trim( (string) preg_replace( '/\s+/', ' ', $text ) ), 0, 64 );
		}

		/**
		 * Seconds to wait after a 429, from its Retry-After (30 s to 10 min).
		 *
		 * @param string $header Retry-After value.
		 * @return int
		 */
		private static function retry_after( $header ) {
			$header = trim( $header );
			if ( '' === $header ) {
				$wait = 60;
			} elseif ( ctype_digit( $header ) ) {
				$wait = (int) $header;
			} else {
				$at   = strtotime( $header );
				$wait = false === $at ? 60 : $at - Brikpanel_Push_Store::now_ts();
			}
			return max( 30, min( 600, $wait ) );
		}

		// ------------------------------------------------------------------
		// The message
		// ------------------------------------------------------------------

		/**
		 * The message in the current language, before the receipt is added.
		 * Never names the customer: a lock screen is seen by anyone nearby.
		 *
		 * @param array  $spec   kind order|summary|test.
		 * @param string $locale Language of the message.
		 * @return array|null
		 */
		public static function message( array $spec, $locale ) {
			$store = self::store_name();
			$kind  = isset( $spec['kind'] ) ? (string) $spec['kind'] : '';

			if ( 'order' === $kind ) {
				$order = isset( $spec['obj'] ) && $spec['obj'] instanceof WC_Order ? $spec['obj'] : wc_get_order( isset( $spec['order'] ) ? (int) $spec['order'] : 0 );
				if ( ! $order instanceof WC_Order ) {
					return null;
				}
				$number = (string) $order->get_order_number();
				$title  = $order->has_status( 'on-hold' )
					/* translators: %s: order number. Phone notification for an order that waits for a bank transfer or a cheque. */
					? sprintf( __( 'New order #%s, awaiting payment', 'brikpanel' ), $number )
					/* translators: %s: order number. Phone notification title. */
					: sprintf( __( 'New order #%s', 'brikpanel' ), $number );
				$parts = array( $store, brikpanel_money_text( $order->get_total(), array( 'currency' => $order->get_currency() ) ) );
				$items = (int) $order->get_item_count();
				if ( $items > 0 ) {
					/* translators: %s: number of items. */
					$parts[] = sprintf( _n( '%s item', '%s items', $items, 'brikpanel' ), brikpanel_number( $items ) );
				}
				return self::payload( $title, implode( ' · ', array_filter( $parts, 'strlen' ) ), self::order_url( $order ), 'bp-order-' . $order->get_id(), array( 'k' => 'order', 'o' => (int) $order->get_id() ), $locale );
			}

			if ( 'summary' === $kind ) {
				$count = max( 1, isset( $spec['count'] ) ? (int) $spec['count'] : 1 );
				/* translators: %s: number of new orders. Phone notification title. */
				$title = sprintf( _n( '%s new order', '%s new orders', $count, 'brikpanel' ), brikpanel_number( $count ) );
				return self::payload( $title, $store, function_exists( 'brikpanel_wc_orders_list_url' ) ? brikpanel_wc_orders_list_url() : admin_url( 'edit.php?post_type=shop_order' ), 'bp-orders', array( 'k' => 'orders' ), $locale );
			}

			if ( 'test' === $kind ) {
				return self::payload( __( 'Test notification', 'brikpanel' ), __( 'New orders will show up like this.', 'brikpanel' ), self::settings_url(), 'bp-test', array( 'k' => 'test' ), $locale );
			}
			return null;
		}

		/**
		 * The Declarative Web Push message (iPhone 18.4+ shows it even
		 * without the service worker; "mutable" lets the worker show it and
		 * send the receipt).
		 *
		 * @param string $title  Title.
		 * @param string $body   Body.
		 * @param string $url    Page the tap opens.
		 * @param string $tag    Same tag = replaces the older one.
		 * @param array  $data   Worker data.
		 * @param string $locale Language.
		 * @return array
		 */
		private static function payload( $title, $body, $url, $tag, array $data, $locale ) {
			$data['bp'] = 1;
			$title      = self::plain( $title );
			$body       = self::plain( $body );
			if ( ! is_rtl() ) {
				// The direction marks around amounts only matter in right-to-left
				// text; some notification screens draw them as boxes.
				$title = (string) preg_replace( '/[\x{2066}-\x{2069}]/u', '', $title );
				$body  = (string) preg_replace( '/[\x{2066}-\x{2069}]/u', '', $body );
			}
			return array(
				'web_push'     => 8030,
				'notification' => array(
					'title'    => self::clip( $title, 120 ),
					'body'     => self::clip( $body, 240 ),
					'navigate' => self::https( $url ),
					'tag'      => $tag,
					'lang'     => str_replace( '_', '-', (string) $locale ),
					'dir'      => is_rtl() ? 'rtl' : 'ltr',
					'silent'   => false,
					'mutable'  => true,
					'data'     => $data,
				),
				'mutable'      => true,
			);
		}

		/**
		 * @param string $text Maybe HTML.
		 * @return string
		 */
		private static function plain( $text ) {
			return trim( html_entity_decode( wp_strip_all_tags( (string) $text ), ENT_QUOTES, 'UTF-8' ) );
		}

		/**
		 * @param string $text Text.
		 * @param int    $max  Characters.
		 * @return string
		 */
		private static function clip( $text, $max ) {
			if ( brikpanel_strlen( $text ) <= $max ) {
				return $text;
			}
			return rtrim( brikpanel_substr( $text, 0, $max - 1 ) ) . '…';
		}

		/** @return string The store's name, short. */
		public static function store_name() {
			$name = self::plain( wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ) );
			if ( '' === $name ) {
				$name = (string) wp_parse_url( home_url(), PHP_URL_HOST );
			}
			return self::clip( $name, 40 );
		}

		/**
		 * @param WC_Order $order Order.
		 * @return string
		 */
		private static function order_url( $order ) {
			return method_exists( $order, 'get_edit_order_url' ) ? (string) $order->get_edit_order_url() : admin_url( 'post.php?post=' . (int) $order->get_id() . '&action=edit' );
		}

		/** @return string The device list in Settings. */
		public static function settings_url() {
			return admin_url( 'admin.php?page=wc-settings&tab=brikpanel&section=notifications' ) . '#brikpanel-push-devices';
		}

		/**
		 * The store's own https address for a link. In a background job
		 * admin_url() says http when the request itself was not https, while
		 * the phone's app lives on https.
		 *
		 * @param string $url URL.
		 * @return string
		 */
		private static function https( $url ) {
			$all     = wp_load_alloptions();
			$siteurl = isset( $all['siteurl'] ) ? (string) $all['siteurl'] : '';
			if ( 0 === strpos( $url, 'http://' ) && ( 0 === stripos( $siteurl, 'https://' ) || ( function_exists( 'force_ssl_admin' ) && force_ssl_admin() ) ) ) {
				$url = set_url_scheme( $url, 'https' );
			}
			return $url;
		}

		/**
		 * Devices grouped by their owner's language.
		 *
		 * @param array[] $devices Rows.
		 * @return array<string,array[]>
		 */
		private static function by_locale( array $devices ) {
			$groups  = array();
			$locales = array();
			foreach ( $devices as $row ) {
				$uid = (int) $row['user_id'];
				if ( ! isset( $locales[ $uid ] ) ) {
					$locales[ $uid ] = (string) get_user_locale( $uid );
				}
				$groups[ $locales[ $uid ] ][] = $row;
			}
			return $groups;
		}

		/**
		 * @param string $locale Locale.
		 * @return bool Whether it switched (then restore_previous_locale()).
		 */
		private static function switch_locale( $locale ) {
			return function_exists( 'switch_to_locale' ) && '' !== $locale && switch_to_locale( $locale );
		}

		/**
		 * A device's name in the current language ("iPhone · BrikPanel app").
		 *
		 * @param array $row Device row.
		 * @return string
		 */
		public static function device_name( array $row ) {
			$systems = array(
				'android'  => 'Android',
				'windows'  => 'Windows',
				'mac'      => 'Mac',
				'linux'    => 'Linux',
				'chromeos' => 'ChromeOS',
			);
			$os = isset( $row['os'] ) ? (string) $row['os'] : '';
			if ( 'ios' === $os ) {
				$system = empty( $row['phone'] ) ? 'iPad' : 'iPhone';
			} elseif ( isset( $systems[ $os ] ) ) {
				$system = $systems[ $os ];
			} else {
				$system = __( 'Device', 'brikpanel' );
			}
			if ( ! empty( $row['standalone'] ) ) {
				$app = __( 'BrikPanel app', 'brikpanel' );
			} else {
				$browsers = array(
					'chrome'  => 'Chrome',
					'safari'  => 'Safari',
					'firefox' => 'Firefox',
					'edge'    => 'Edge',
					'samsung' => 'Samsung Internet',
					'opera'   => 'Opera',
				);
				$app = isset( $row['browser'], $browsers[ $row['browser'] ] ) ? $browsers[ $row['browser'] ] : '';
			}
			return '' === $app ? $system : $system . ' · ' . $app;
		}

		// ------------------------------------------------------------------
		// Delivery receipts
		// ------------------------------------------------------------------

		/**
		 * @param string $data Data.
		 * @param int    $len  Bytes.
		 * @return string
		 */
		private static function mac( $data, $len ) {
			return substr( hash_hmac( 'sha256', $data, 'brikpanel_push|' . wp_salt( 'auth' ), true ), 0, $len );
		}

		/**
		 * The receipt a notification carries: device, send time, signature.
		 *
		 * @param int $device_id Device id.
		 * @param int $sent      Send time.
		 * @return string base64url, 32 characters.
		 */
		public static function receipt_token( $device_id, $sent ) {
			$head = pack( 'N', (int) $device_id ) . pack( 'N', (int) $sent );
			return Brikpanel_WebPush::b64url_encode( $head . self::mac( 'r|' . get_current_blog_id() . '|' . $head, 16 ) );
		}

		/**
		 * Checks a receipt: signed by this site, sent in the last 72 hours.
		 *
		 * @param string $token Receipt.
		 * @return array{device:int,t:int}|null
		 */
		public static function read_receipt( $token ) {
			$raw = Brikpanel_WebPush::b64url_decode( (string) $token );
			if ( 24 !== strlen( $raw ) ) {
				return null;
			}
			$head = substr( $raw, 0, 8 );
			if ( ! hash_equals( self::mac( 'r|' . get_current_blog_id() . '|' . $head, 16 ), substr( $raw, 8 ) ) ) {
				return null;
			}
			$device = unpack( 'N', substr( $head, 0, 4 ) );
			$sent   = unpack( 'N', substr( $head, 4, 4 ) );
			$now    = Brikpanel_Push_Store::now_ts();
			$sent   = (int) $sent[1];
			if ( $sent < $now - 72 * HOUR_IN_SECONDS || $sent > $now + 5 * MINUTE_IN_SECONDS ) {
				return null;
			}
			return array(
				'device' => (int) $device[1],
				't'      => $sent,
			);
		}

		/**
		 * The phone got a notification.
		 *
		 * @param string $token Receipt.
		 * @return bool Whether it counted.
		 */
		public static function confirm( $token ) {
			$receipt = self::read_receipt( $token );
			if ( ! $receipt ) {
				return false;
			}
			$row = Brikpanel_Push_Store::get( $receipt['device'] );
			if ( ! $row || 'active' !== $row['status'] ) {
				return false;
			}
			Brikpanel_Push_Store::mark_confirmed( $receipt['device'], $receipt['t'] );
			return true;
		}

		// ------------------------------------------------------------------
		// Logging out on a phone stops its notifications
		// ------------------------------------------------------------------

		/** @return string Cookie that names this browser's device. */
		public static function device_cookie_name() {
			return 'brikpanel_push_dev_' . get_current_blog_id();
		}

		/**
		 * @param int $device_id Device id.
		 * @param int $user_id   Its owner.
		 * @return string
		 */
		public static function device_cookie_value( $device_id, $user_id ) {
			$device_id = (int) $device_id;
			$user_id   = (int) $user_id;
			return $device_id . '.' . $user_id . '.' . bin2hex( self::mac( 'c|' . get_current_blog_id() . '|' . $device_id . '|' . $user_id, 12 ) );
		}

		/**
		 * @param string $value Cookie value.
		 * @return array{device:int,user:int}|null
		 */
		public static function read_device_cookie( $value ) {
			if ( ! preg_match( '/^(\d{1,19})\.(\d{1,19})\.([0-9a-f]{24})$/', (string) $value, $m ) ) {
				return null;
			}
			if ( ! hash_equals( self::device_cookie_value( (int) $m[1], (int) $m[2] ), (string) $value ) ) {
				return null;
			}
			return array(
				'device' => (int) $m[1],
				'user'   => (int) $m[2],
			);
		}

		// ------------------------------------------------------------------
		// Too many at once
		// ------------------------------------------------------------------

		/**
		 * Counts a message against the window. Past 30 in five minutes the
		 * orders wait for one summary at the end of the window.
		 *
		 * @param int $orders Orders in the message.
		 * @return bool Whether to send it now.
		 */
		private static function flood_take( $orders ) {
			$now   = Brikpanel_Push_Store::now_ts();
			$flood = get_option( self::OPT_FLOOD );
			$held  = is_array( $flood ) && isset( $flood['h'] ) ? (int) $flood['h'] : 0;
			if ( ! is_array( $flood ) || ! isset( $flood['t'], $flood['n'] ) || $now - (int) $flood['t'] >= self::FLOOD_WINDOW || $now < (int) $flood['t'] ) {
				$flood = array(
					't' => $now,
					'n' => 0,
					'h' => $held,
				);
			}
			if ( (int) $flood['n'] < self::FLOOD_MAX ) {
				++$flood['n'];
				update_option( self::OPT_FLOOD, $flood, false );
				return true;
			}
			$flood['h'] = $held + max( 1, (int) $orders );
			update_option( self::OPT_FLOOD, $flood, false );
			if ( class_exists( 'Brikpanel_Cron' ) ) {
				Brikpanel_Cron::schedule_single( (int) $flood['t'] + self::FLOOD_WINDOW + 5, self::HOOK_HELD, array(), array( 'unique' => true ) );
			}
			return false;
		}

		/**
		 * The orders that waited: one "N new orders" message.
		 *
		 * @return void
		 */
		public static function send_held() {
			$flood = get_option( self::OPT_FLOOD );
			$held  = is_array( $flood ) && isset( $flood['h'] ) ? (int) $flood['h'] : 0;
			if ( $held <= 0 ) {
				return;
			}
			$flood['h'] = 0;
			update_option( self::OPT_FLOOD, $flood, false );
			if ( ! self::live() ) {
				return;
			}
			$devices = self::recipients();
			if ( $devices ) {
				self::deliver(
					array(
						'kind'  => 'summary',
						'count' => $held,
					),
					$devices
				);
			}
		}

		// ------------------------------------------------------------------
		// A phone that stopped
		// ------------------------------------------------------------------

		/**
		 * @param int $when Unix time.
		 * @return void
		 */
		private static function schedule_watchdog( $when ) {
			if ( class_exists( 'Brikpanel_Cron' ) ) {
				Brikpanel_Cron::schedule_single( (int) $when, self::HOOK_WATCHDOG, array(), array( 'unique' => true ) );
			}
		}

		/**
		 * Emails the owner of a phone that has not confirmed two or more
		 * notifications for six hours, after it confirmed before (a phone
		 * that never confirmed may have its receipts blocked by the server).
		 * At most once a week per phone. Then looks again when the next phone
		 * could qualify.
		 *
		 * @return void
		 */
		public static function watchdog() {
			if ( ! self::live() ) {
				return;
			}
			Brikpanel_Push_Store::purge_removed( 30 );
			$now  = Brikpanel_Push_Store::now_ts();
			$next = 0;
			foreach ( Brikpanel_Push_Store::pending_phones() as $row ) {
				if ( empty( $row['confirmed_at'] ) || (int) $row['pending_count'] < 2 || empty( $row['pending_since'] ) || empty( $row['sent_at'] ) ) {
					continue;
				}
				$alerted = empty( $row['alerted_at'] ) ? 0 : (int) strtotime( $row['alerted_at'] . ' UTC' );
				if ( $alerted > $now - 7 * DAY_IN_SECONDS ) {
					continue;
				}
				$due = max( (int) strtotime( $row['pending_since'] . ' UTC' ) + 6 * HOUR_IN_SECONDS, (int) strtotime( $row['sent_at'] . ' UTC' ) + 30 * MINUTE_IN_SECONDS );
				if ( $due > $now ) {
					$next = $next ? min( $next, $due ) : $due;
					continue;
				}
				if ( self::user_may_receive( (int) $row['user_id'] ) ) {
					self::alert( $row, 'silent' );
				}
			}
			if ( $next && class_exists( 'Brikpanel_Cron' ) ) {
				// Not "unique": this job is still running and would block its own successor.
				Brikpanel_Cron::schedule_single( $next + MINUTE_IN_SECONDS, self::HOOK_WATCHDOG );
			}
		}

		/**
		 * Emails a phone's owner, in their language.
		 *
		 * @param array  $row  Device row.
		 * @param string $kind 'gone' (the push service dropped it) or 'silent'.
		 * @return bool
		 */
		private static function alert( array $row, $kind ) {
			$user = get_userdata( (int) $row['user_id'] );
			if ( ! $user instanceof WP_User || ! is_email( $user->user_email ) ) {
				return false;
			}
			$switched = self::switch_locale( (string) get_user_locale( $user ) );
			try {
				$store  = self::store_name();
				$device = self::device_name( $row );
				if ( 'gone' === $kind ) {
					/* translators: 1: store name, 2: device name, like "iPhone · BrikPanel app". */
					$subject = sprintf( __( '[%1$s] Order notifications stopped on %2$s', 'brikpanel' ), $store, $device );
					$heading = __( 'Order notifications stopped', 'brikpanel' );
					$lines   = array(
						/* translators: %s: device name, like "iPhone · BrikPanel app". */
						sprintf( __( 'Your phone (%s) no longer accepts order notifications from this store.', 'brikpanel' ), $device ),
						__( 'This happens when the BrikPanel app is removed from the Home Screen, notifications are turned off for it, or the browser data is cleared.', 'brikpanel' ),
						__( 'To get them again, open your store admin on that phone and turn order notifications on.', 'brikpanel' ),
					);
				} else {
					$since = wp_date( brikpanel_datetime_format(), (int) strtotime( $row['pending_since'] . ' UTC' ) );
					/* translators: 1: store name, 2: device name, like "iPhone · BrikPanel app". */
					$subject = sprintf( __( '[%1$s] Is %2$s still getting order notifications?', 'brikpanel' ), $store, $device );
					$heading = __( 'Order notifications not confirmed', 'brikpanel' );
					$lines   = array(
						/* translators: 1: device name, like "iPhone · BrikPanel app", 2: date and time. */
						sprintf( __( 'Your phone (%1$s) has not confirmed any order notification since %2$s.', 'brikpanel' ), $device, $since ),
						__( 'If they stopped arriving, open BrikPanel on that phone: it checks the notifications and turns them back on when it can.', 'brikpanel' ),
						__( 'If the phone was simply switched off, you can ignore this email.', 'brikpanel' ),
					);
				}
				$html  = '<p>' . implode( '</p><p>', array_map( 'esc_html', $lines ) ) . '</p>';
				$html .= '<p><a href="' . esc_url( self::https( self::settings_url() ) ) . '">' . esc_html__( 'See your devices', 'brikpanel' ) . '</a></p>';
				$sent  = self::mail( $user->user_email, $subject, $heading, $html );
			} finally {
				if ( $switched ) {
					restore_previous_locale();
				}
			}
			if ( $sent ) {
				Brikpanel_Push_Store::mark_alerted( (int) $row['id'] );
			}
			return $sent;
		}

		/**
		 * The signing key can no longer be read: one email to the site's
		 * administrator address, until new keys are created.
		 *
		 * @return void
		 */
		private static function keys_problem() {
			if ( ! Brikpanel_Push_Store::vapid_unreadable() || get_option( self::OPT_KEYS_ALERT ) ) {
				return;
			}
			update_option( self::OPT_KEYS_ALERT, Brikpanel_Push_Store::now_ts(), false );
			$to = (string) get_option( 'admin_email' );
			if ( ! is_email( $to ) ) {
				return;
			}
			$heading = __( 'Phone notifications are paused', 'brikpanel' );
			/* translators: %s: store name. */
			$subject = sprintf( __( '[%s] Phone notifications are paused', 'brikpanel' ), self::store_name() );
			$lines   = array(
				__( 'BrikPanel can no longer read the key it signs phone notifications with, because the security keys in this site\'s wp-config.php file changed.', 'brikpanel' ),
				__( 'An administrator can create new keys under WooCommerce > Settings > BrikPanel > Notifications. Each phone then turns notifications back on by itself the next time BrikPanel is opened on it.', 'brikpanel' ),
			);
			$html  = '<p>' . implode( '</p><p>', array_map( 'esc_html', $lines ) ) . '</p>';
			$html .= '<p><a href="' . esc_url( self::https( self::settings_url() ) ) . '">' . esc_html__( 'Open the settings', 'brikpanel' ) . '</a></p>';
			self::mail( $to, $subject, $heading, $html );
		}

		/**
		 * Sends an email in the store's WooCommerce email design.
		 *
		 * @param string $to      Address.
		 * @param string $subject Subject.
		 * @param string $heading Heading.
		 * @param string $html    Body HTML.
		 * @return bool
		 */
		private static function mail( $to, $subject, $heading, $html ) {
			try {
				if ( function_exists( 'WC' ) && is_callable( array( WC(), 'mailer' ) ) ) {
					$mailer = WC()->mailer();
					return (bool) $mailer->send( $to, $subject, $mailer->wrap_message( $heading, $html ) );
				}
			} catch ( \Throwable $e ) {
				self::log( 'mail', $e );
			}
			return (bool) wp_mail( $to, $subject, $html, array( 'Content-Type: text/html; charset=UTF-8' ) );
		}

		// ------------------------------------------------------------------
		// Background jobs
		// ------------------------------------------------------------------

		/**
		 * @param mixed $payload { orders: int[] } or { order: int }.
		 * @return void
		 */
		public static function job_order( $payload ) {
			$payload = is_array( $payload ) ? $payload : array();
			if ( isset( $payload['orders'] ) ) {
				$ids = (array) $payload['orders'];
			} elseif ( isset( $payload['order'] ) ) {
				$ids = array( $payload['order'] );
			} else {
				$ids = array();
			}
			if ( $ids ) {
				self::send_orders( array_map( 'intval', $ids ) );
			}
		}

		/**
		 * A message that met a busy or slow push service, once more.
		 *
		 * @param mixed $payload { spec, devices: int[], n: int }.
		 * @return void
		 */
		public static function job_retry( $payload ) {
			if ( ! is_array( $payload ) || empty( $payload['spec'] ) || ! is_array( $payload['spec'] ) || empty( $payload['devices'] ) || ! self::live() ) {
				return;
			}
			$spec = $payload['spec'];
			if ( isset( $spec['kind'] ) && 'order' === $spec['kind'] ) {
				$order = wc_get_order( isset( $spec['order'] ) ? (int) $spec['order'] : 0 );
				if ( ! $order instanceof WC_Order || '' !== self::skip_reason( $order, 0 ) ) {
					return;
				}
				$spec['obj'] = $order;
			}
			$wanted  = array_map( 'intval', (array) $payload['devices'] );
			$devices = array();
			foreach ( self::recipients() as $row ) {
				if ( in_array( (int) $row['id'], $wanted, true ) ) {
					$devices[] = $row;
				}
			}
			if ( $devices ) {
				self::deliver( $spec, $devices, isset( $payload['n'] ) ? (int) $payload['n'] : 1 );
			}
		}

		// ------------------------------------------------------------------
		// The order's mark
		// ------------------------------------------------------------------

		/**
		 * The mark as stored right now, past every cache: another request may
		 * have sent the order a moment ago.
		 *
		 * @param int $order_id Order id.
		 * @return string
		 */
		private static function stored_mark( $order_id ) {
			global $wpdb;
			if ( function_exists( 'brikpanel_wc_hpos_enabled' ) && brikpanel_wc_hpos_enabled() ) {
				$value = $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->prefix}wc_orders_meta WHERE order_id = %d AND meta_key = %s ORDER BY id DESC LIMIT 1", (int) $order_id, self::META ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			} else {
				$value = $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s ORDER BY meta_id DESC LIMIT 1", (int) $order_id, self::META ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			}
			return (string) $value;
		}

		/**
		 * Writes the mark. Only this field changes: WooCommerce's "save the
		 * whole order after a meta change" is skipped, so other plugins do not
		 * see an order update they would sync again.
		 *
		 * @param WC_Order $order Order.
		 * @param string   $value Mark.
		 * @return void
		 */
		private static function write_mark( $order, $value ) {
			$order->update_meta_data( self::META, $value );
			add_filter( 'woocommerce_orders_table_datastore_should_save_after_meta_change', array( __CLASS__, 'no_full_save' ), PHP_INT_MAX );
			try {
				$order->save_meta_data();
			} finally {
				remove_filter( 'woocommerce_orders_table_datastore_should_save_after_meta_change', array( __CLASS__, 'no_full_save' ), PHP_INT_MAX );
			}
		}

		/** @return bool */
		public static function no_full_save() {
			return false;
		}

		/**
		 * A database lock per order, so two requests never send the same one.
		 * Freed by the database itself if the request dies.
		 *
		 * @param int $order_id Order id.
		 * @return bool
		 */
		private static function lock( $order_id ) {
			global $wpdb;
			$suppress = $wpdb->suppress_errors( true );
			$got      = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', self::lock_name( $order_id ) ) );
			$wpdb->suppress_errors( $suppress );
			// A database without named locks: carry on, the mark still stops repeats.
			return null === $got || '1' === (string) $got;
		}

		/**
		 * @param int $order_id Order id.
		 * @return void
		 */
		private static function unlock( $order_id ) {
			global $wpdb;
			$suppress = $wpdb->suppress_errors( true );
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::lock_name( $order_id ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->suppress_errors( $suppress );
		}

		/**
		 * @param int $order_id Order id.
		 * @return string Unique per database, table prefix and order (under 64 characters).
		 */
		private static function lock_name( $order_id ) {
			global $wpdb;
			return 'bp_push_' . substr( md5( ( defined( 'DB_NAME' ) ? DB_NAME : '' ) . '|' . $wpdb->prefix ), 0, 12 ) . '_' . (int) $order_id;
		}

		/**
		 * @param string     $where Step.
		 * @param \Throwable $e     Error.
		 * @return void
		 */
		private static function log( $where, $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'BrikPanel phone notifications (' . $where . '): ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
		}
	}
}
