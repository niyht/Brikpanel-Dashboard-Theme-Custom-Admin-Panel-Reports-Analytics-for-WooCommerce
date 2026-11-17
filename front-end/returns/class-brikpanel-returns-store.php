<?php
/**
 * Customer returns: the table and every read and write of it.
 *
 * One row per return request. The ORDER is never changed by this module's
 * data layer; everything a return knows lives here:
 *
 *   - status        requested, approved, received, resolving, refunded,
 *                   rejected, cancelled (brikpanel_returns_status_defs()).
 *   - holds_qty     1 while the request still reserves its units. A refunded
 *                   return reserves nothing because WooCommerce's own refund
 *                   lines already carry those units
 *                   (WC_Order::get_qty_refunded_for_item()), so counting both
 *                   would take the units off twice.
 *   - open_order_id the order id while the request is open, NULL afterwards.
 *                   UNIQUE, so an order has at most one open request and two
 *                   tabs cannot open a second one.
 *   - version       bumped by every write. A transition names the version it
 *                   read and changes nothing when another person got there
 *                   first (compare and swap).
 *   - source/source_id UNIQUE together: the customer form carries a random
 *                   token, so a double click or a browser "back + resend" can
 *                   never create two requests.
 *
 * @package BrikPanel
 * @since   3.3.52
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Brikpanel_Returns_Store' ) ) {

	class Brikpanel_Returns_Store {

		/** @var bool[] Whether the table exists, by table name (a network switches sites mid-request). */
		private static $table_ok = array();

		/** @var array<int,array> Rows read in this request, by id. */
		private static $rows = array();

		/** Forget what this request remembered (tests, site switches). */
		public static function flush() {
			self::$table_ok = array();
			self::$rows     = array();
		}

		/** @return string */
		public static function table() {
			global $wpdb;
			return $wpdb->prefix . 'brikpanel_returns';
		}

		/**
		 * The table, for dbDelta (brikpanel_create_table() runs it on every
		 * version bump; ensure_table() covers the time before that).
		 *
		 * @return string
		 */
		public static function schema_sql() {
			global $wpdb;
			$table = self::table();
			return "CREATE TABLE {$table} (
				id BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
				order_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
				open_order_id BIGINT(20) UNSIGNED NULL DEFAULT NULL,
				customer_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
				email VARCHAR(190) NOT NULL DEFAULT '',
				status VARCHAR(20) NOT NULL DEFAULT 'requested',
				holds_qty TINYINT(1) NOT NULL DEFAULT 1,
				version INT(10) UNSIGNED NOT NULL DEFAULT 1,
				items LONGTEXT NULL,
				reason VARCHAR(190) NOT NULL DEFAULT '',
				comment TEXT NULL,
				photos TEXT NULL,
				admin_message TEXT NULL,
				refund_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
				amount DECIMAL(20,4) NOT NULL DEFAULT 0,
				currency VARCHAR(10) NOT NULL DEFAULT '',
				withdrawal TINYINT(1) NOT NULL DEFAULT 0,
				statement LONGTEXT NULL,
				submitted_at DATETIME NULL DEFAULT NULL,
				source VARCHAR(20) NOT NULL DEFAULT 'account',
				source_id VARCHAR(64) NOT NULL DEFAULT '',
				history LONGTEXT NULL,
				created_at DATETIME NOT NULL,
				updated_at DATETIME NOT NULL,
				UNIQUE KEY uniq_open_order (open_order_id),
				UNIQUE KEY uniq_source (source, source_id),
				KEY idx_status_created (status, created_at),
				KEY idx_order (order_id),
				KEY idx_customer (customer_id),
				KEY idx_email (email)
			) {$wpdb->get_charset_collate()};";
		}

		/**
		 * Creates the table when it is missing: a store that turns returns on
		 * before the version bump has run brikpanel_create_table() still works.
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

		/** @return string Current time for the DATETIME columns (UTC). */
		public static function now() {
			return gmdate( 'Y-m-d H:i:s', (int) apply_filters( 'brikpanel_returns_now', time() ) );
		}

		// =====================================================================
		// READ
		// =====================================================================

		/**
		 * Decode a raw row into the shape the rest of the module reads.
		 *
		 * @param array|null $row Raw row.
		 * @return array|null
		 */
		public static function hydrate( $row ) {
			if ( ! is_array( $row ) ) {
				return null;
			}
			$out = array(
				'id'            => (int) $row['id'],
				'order_id'      => (int) $row['order_id'],
				'open_order_id' => null === $row['open_order_id'] ? 0 : (int) $row['open_order_id'],
				'customer_id'   => (int) $row['customer_id'],
				'email'         => (string) $row['email'],
				'status'        => (string) $row['status'],
				'holds_qty'     => (int) $row['holds_qty'],
				'version'       => (int) $row['version'],
				'items'         => self::decode_list( $row['items'] ),
				'reason'        => (string) $row['reason'],
				'comment'       => (string) $row['comment'],
				'photos'        => self::decode_list( $row['photos'] ),
				'admin_message' => (string) $row['admin_message'],
				'refund_id'     => (int) $row['refund_id'],
				'amount'        => (float) $row['amount'],
				'currency'      => (string) $row['currency'],
				'withdrawal'    => (int) $row['withdrawal'],
				'statement'     => self::decode_map( $row['statement'] ),
				'submitted_at'  => (string) $row['submitted_at'],
				'source'        => (string) $row['source'],
				'source_id'     => (string) $row['source_id'],
				'history'       => self::decode_list( $row['history'] ),
				'created_at'    => (string) $row['created_at'],
				'updated_at'    => (string) $row['updated_at'],
			);
			return $out;
		}

		/**
		 * @param mixed $json Stored JSON.
		 * @return array A list.
		 */
		private static function decode_list( $json ) {
			if ( ! is_string( $json ) || '' === $json ) {
				return array();
			}
			$data = json_decode( $json, true );
			return is_array( $data ) ? array_values( $data ) : array();
		}

		/**
		 * @param mixed $json Stored JSON.
		 * @return array A map.
		 */
		private static function decode_map( $json ) {
			if ( ! is_string( $json ) || '' === $json ) {
				return array();
			}
			$data = json_decode( $json, true );
			return is_array( $data ) ? $data : array();
		}

		/**
		 * One return.
		 *
		 * @param int  $id    Return id.
		 * @param bool $fresh Skip the per-request memo.
		 * @return array|null
		 */
		public static function get( $id, $fresh = false ) {
			global $wpdb;
			$id = absint( $id );
			if ( ! $id || ! self::ensure_table() ) {
				return null;
			}
			if ( ! $fresh && isset( self::$rows[ $id ] ) ) {
				return self::$rows[ $id ];
			}
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', self::table(), $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$out = self::hydrate( $row );
			if ( $out ) {
				self::$rows[ $id ] = $out;
			}
			return $out;
		}

		/**
		 * Every return of one order, oldest first.
		 *
		 * @param int $order_id Order id.
		 * @return array[]
		 */
		public static function for_order( $order_id ) {
			global $wpdb;
			$order_id = absint( $order_id );
			if ( ! $order_id || ! self::ensure_table() ) {
				return array();
			}
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE order_id = %d ORDER BY id ASC', self::table(), $order_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$out  = array();
			foreach ( (array) $rows as $row ) {
				$item = self::hydrate( $row );
				if ( $item ) {
					self::$rows[ $item['id'] ] = $item;
					$out[]                     = $item;
				}
			}
			return $out;
		}

		/**
		 * The latest return of each of these orders, open ones first, in one
		 * query (the orders lists ask for a page of orders at once).
		 *
		 * @param int[] $order_ids Order ids.
		 * @return array<int,array> order id => return.
		 */
		public static function latest_for_orders( array $order_ids ) {
			global $wpdb;
			$order_ids = array_values( array_unique( array_filter( array_map( 'absint', $order_ids ) ) ) );
			if ( ! $order_ids || ! self::ensure_table() ) {
				return array();
			}
			$placeholders = implode( ',', array_fill( 0, count( $order_ids ), '%d' ) );
			$sql          = 'SELECT * FROM %i WHERE order_id IN (' . $placeholders . ') ORDER BY id ASC';
			$rows         = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( array( self::table() ), $order_ids ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			$out          = array();
			foreach ( (array) $rows as $row ) {
				$item = self::hydrate( $row );
				if ( ! $item ) {
					continue;
				}
				$oid = $item['order_id'];
				// Keep an open return over a closed one; otherwise the newest.
				if ( ! isset( $out[ $oid ] ) || $item['open_order_id'] || ! $out[ $oid ]['open_order_id'] ) {
					$out[ $oid ] = $item;
				}
			}
			return $out;
		}

		/**
		 * Every return of one customer (account holders), grouped by order, in
		 * one query.
		 *
		 * @param int $customer_id User id.
		 * @return array<int,array[]> order id => returns, oldest first.
		 */
		public static function for_customer( $customer_id ) {
			global $wpdb;
			$customer_id = absint( $customer_id );
			if ( ! $customer_id || ! self::ensure_table() ) {
				return array();
			}
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE customer_id = %d ORDER BY id ASC LIMIT 500', self::table(), $customer_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$out  = array();
			foreach ( (array) $rows as $row ) {
				$item = self::hydrate( $row );
				if ( $item ) {
					$out[ $item['order_id'] ][] = $item;
				}
			}
			return $out;
		}

		/**
		 * Units of each order line held by this order's returns that still
		 * reserve them (holds_qty = 1).
		 *
		 * @param int $order_id   Order id.
		 * @param int $exclude_id A return to leave out (the one being edited).
		 * @return array<int,int> order item id => units.
		 */
		public static function held_qty( $order_id, $exclude_id = 0 ) {
			return self::held_from( self::for_order( $order_id ), $exclude_id );
		}

		/**
		 * The same count from returns the caller already has (the My Account
		 * orders list reads a customer's returns in one query).
		 *
		 * @param array[] $returns    Returns of one order.
		 * @param int     $exclude_id A return to leave out.
		 * @return array<int,int>
		 */
		public static function held_from( array $returns, $exclude_id = 0 ) {
			$held = array();
			foreach ( $returns as $ret ) {
				if ( 1 !== $ret['holds_qty'] || (int) $ret['id'] === (int) $exclude_id ) {
					continue;
				}
				foreach ( $ret['items'] as $line ) {
					$item_id = isset( $line['item_id'] ) ? (int) $line['item_id'] : 0;
					$qty     = isset( $line['qty'] ) ? (int) $line['qty'] : 0;
					if ( $item_id > 0 && $qty > 0 ) {
						$held[ $item_id ] = ( isset( $held[ $item_id ] ) ? $held[ $item_id ] : 0 ) + $qty;
					}
				}
			}
			return $held;
		}

		/**
		 * The admin list: one page plus the total.
		 *
		 * @param array $args status ('' = all, 'done' = refunded), search,
		 *                    order_ids (from an order number search), page,
		 *                    per_page.
		 * @return array{rows:array[],total:int}
		 */
		public static function query( array $args ) {
			global $wpdb;
			if ( ! self::ensure_table() ) {
				return array(
					'rows'  => array(),
					'total' => 0,
				);
			}
			$status   = isset( $args['status'] ) ? sanitize_key( $args['status'] ) : '';
			$search   = isset( $args['search'] ) ? trim( (string) $args['search'] ) : '';
			$page     = max( 1, isset( $args['page'] ) ? (int) $args['page'] : 1 );
			$per_page = min( 100, max( 1, isset( $args['per_page'] ) ? (int) $args['per_page'] : 20 ) );
			$ids      = isset( $args['order_ids'] ) ? array_filter( array_map( 'absint', (array) $args['order_ids'] ) ) : array();

			$where  = array( '1=1' );
			$params = array( self::table() );
			$known  = array_keys( brikpanel_returns_status_defs() );
			if ( 'done' === $status ) {
				$where[] = "status = 'refunded'";
			} elseif ( 'open' === $status ) {
				$where[] = 'open_order_id IS NOT NULL';
			} elseif ( in_array( $status, $known, true ) ) {
				$where[]  = 'status = %s';
				$params[] = $status;
			}
			if ( '' !== $search ) {
				$like  = '%' . $wpdb->esc_like( $search ) . '%';
				$parts = array( 'email LIKE %s', 'statement LIKE %s' );
				array_push( $params, $like, $like );
				if ( ctype_digit( ltrim( $search, '#' ) ) ) {
					$parts[]  = 'id = %d';
					$params[] = (int) ltrim( $search, '#' );
				}
				if ( $ids ) {
					$parts[] = 'order_id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')';
					$params  = array_merge( $params, array_values( $ids ) );
				}
				$where[] = '(' . implode( ' OR ', $parts ) . ')';
			}
			$where_sql = implode( ' AND ', $where );
			$total     = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE {$where_sql}", $params ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			$params[] = $per_page;
			$params[] = ( $page - 1 ) * $per_page;
			$rows     = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE {$where_sql} ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d", $params ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$out      = array();
			foreach ( (array) $rows as $row ) {
				$item = self::hydrate( $row );
				if ( $item ) {
					$out[] = $item;
				}
			}
			return array(
				'rows'  => $out,
				'total' => $total,
			);
		}

		/**
		 * Requests per status, from the cached option (0 queries) unless asked
		 * to recount.
		 *
		 * @param bool $recount Read the table again and store the result.
		 * @return array<string,int>
		 */
		public static function counts( $recount = false ) {
			global $wpdb;
			if ( ! $recount ) {
				$cached = get_option( 'brikpanel_returns_counts', null );
				if ( is_array( $cached ) ) {
					return array_map( 'intval', $cached );
				}
			}
			$counts = array_fill_keys( array_keys( brikpanel_returns_status_defs() ), 0 );
			if ( self::ensure_table() ) {
				$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT status, COUNT(*) AS n FROM %i GROUP BY status', self::table() ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				foreach ( (array) $rows as $row ) {
					if ( isset( $counts[ $row['status'] ] ) ) {
						$counts[ $row['status'] ] = (int) $row['n'];
					}
				}
			}
			// Autoload off: written on every status change (option prime reads
			// it in wp-admin, so the menu bubble still costs no query).
			update_option( 'brikpanel_returns_counts', $counts, false );
			return $counts;
		}

		/**
		 * Requests that wait for the store: requested, approved and received.
		 *
		 * @return int
		 */
		public static function waiting_count() {
			$c = self::counts();
			return (int) ( ( isset( $c['requested'] ) ? $c['requested'] : 0 ) + ( isset( $c['approved'] ) ? $c['approved'] : 0 ) + ( isset( $c['received'] ) ? $c['received'] : 0 ) );
		}

		// =====================================================================
		// WRITE
		// =====================================================================

		/**
		 * Create a request.
		 *
		 * A second insert with the same source token returns the first
		 * request (double click, back + resend). An order that already has an
		 * open request gets an error, never a second open request.
		 *
		 * @param array $data Column values (items, photos, history, statement as arrays).
		 * @return int|WP_Error Return id.
		 */
		public static function insert( array $data ) {
			global $wpdb;
			if ( ! self::ensure_table() ) {
				return new WP_Error( 'brikpanel_returns_table', __( 'Returns could not be saved. Please try again later.', 'brikpanel' ) );
			}
			$now      = self::now();
			$order_id = isset( $data['order_id'] ) ? absint( $data['order_id'] ) : 0;
			$source   = isset( $data['source'] ) ? sanitize_key( $data['source'] ) : 'account';
			$sid      = isset( $data['source_id'] ) ? substr( preg_replace( '/[^A-Za-z0-9:_\-]/', '', (string) $data['source_id'] ), 0, 64 ) : '';
			if ( '' === $sid ) {
				$sid = wp_generate_password( 32, false, false );
			}

			// The same token again: the first request answers.
			$existing = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE source = %s AND source_id = %s', self::table(), $source, $sid ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( $existing ) {
				return $existing;
			}

			$row = array(
				'order_id'      => $order_id,
				'open_order_id' => $order_id,
				'customer_id'   => isset( $data['customer_id'] ) ? absint( $data['customer_id'] ) : 0,
				'email'         => isset( $data['email'] ) ? substr( sanitize_email( $data['email'] ), 0, 190 ) : '',
				'status'        => 'requested',
				'holds_qty'     => 1,
				'version'       => 1,
				'items'         => wp_json_encode( isset( $data['items'] ) ? array_values( (array) $data['items'] ) : array() ),
				'reason'        => isset( $data['reason'] ) ? substr( sanitize_text_field( $data['reason'] ), 0, 190 ) : '',
				'comment'       => isset( $data['comment'] ) ? sanitize_textarea_field( $data['comment'] ) : '',
				'photos'        => wp_json_encode( isset( $data['photos'] ) ? array_values( (array) $data['photos'] ) : array() ),
				'admin_message' => '',
				'refund_id'     => 0,
				'amount'        => 0,
				'currency'      => isset( $data['currency'] ) ? substr( sanitize_text_field( $data['currency'] ), 0, 10 ) : '',
				'withdrawal'    => empty( $data['withdrawal'] ) ? 0 : 1,
				'statement'     => isset( $data['statement'] ) && is_array( $data['statement'] ) ? wp_json_encode( $data['statement'] ) : null,
				'submitted_at'  => isset( $data['submitted_at'] ) ? (string) $data['submitted_at'] : $now,
				'source'        => $source,
				'source_id'     => $sid,
				'history'       => wp_json_encode( isset( $data['history'] ) ? array_values( (array) $data['history'] ) : array() ),
				'created_at'    => $now,
				'updated_at'    => $now,
			);

			$suppress = $wpdb->suppress_errors( true );
			$ok       = $wpdb->insert( self::table(), $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->suppress_errors( $suppress );
			if ( ! $ok ) {
				// A racing duplicate of the same token, or an open request on this order.
				$existing = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE source = %s AND source_id = %s', self::table(), $source, $sid ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				if ( $existing ) {
					return $existing;
				}
				return new WP_Error( 'brikpanel_returns_open', __( 'There is already an open return for this order.', 'brikpanel' ) );
			}
			$id = (int) $wpdb->insert_id;
			self::counts( true );
			return $id;
		}

		/**
		 * Move a return to a new status, only if nobody changed it since
		 * $version was read.
		 *
		 * @param int    $id      Return id.
		 * @param int    $version Version the caller read.
		 * @param string $status  New status.
		 * @param array  $changes Other columns (arrays are JSON-encoded).
		 * @param array  $entry   History line: note (string), by (user id).
		 * @return bool Whether the change was made.
		 */
		public static function transition( $id, $version, $status, array $changes = array(), array $entry = array() ) {
			global $wpdb;
			$defs = brikpanel_returns_status_defs();
			$ret  = self::get( $id, true );
			if ( ! $ret || ! isset( $defs[ $status ] ) || (int) $ret['version'] !== (int) $version ) {
				return false;
			}
			$open    = ! empty( $defs[ $status ]['open'] );
			$history = $ret['history'];
			$history[] = array(
				's' => $status,
				't' => self::now(),
				'u' => isset( $entry['by'] ) ? (int) $entry['by'] : get_current_user_id(),
				'n' => isset( $entry['note'] ) ? wp_strip_all_tags( (string) $entry['note'] ) : '',
				'f' => $ret['status'],
			);

			$set = array(
				'status'     => $status,
				'holds_qty'  => empty( $defs[ $status ]['holds'] ) ? 0 : 1,
				'history'    => wp_json_encode( $history ),
				'updated_at' => self::now(),
			);
			foreach ( $changes as $col => $value ) {
				if ( in_array( $col, array( 'items', 'photos', 'statement' ), true ) ) {
					$value = wp_json_encode( $value );
				}
				if ( in_array( $col, array( 'admin_message', 'refund_id', 'amount', 'items', 'photos', 'statement', 'reason', 'comment', 'email' ), true ) ) {
					$set[ $col ] = $value;
				}
			}

			$assign = array();
			$params = array( self::table() );
			foreach ( $set as $col => $value ) {
				$assign[] = '`' . $col . '` = %s';
				$params[] = is_float( $value ) ? wc_format_decimal( $value ) : (string) $value;
			}
			// Open requests keep the order id in the UNIQUE column, closed ones release it.
			$assign[] = $open ? 'open_order_id = order_id' : 'open_order_id = NULL';
			$assign[] = 'version = version + 1';
			$params[] = (int) $id;
			$params[] = (int) $version;

			$suppress = $wpdb->suppress_errors( true );
			$changed  = $wpdb->query( $wpdb->prepare( 'UPDATE %i SET ' . implode( ', ', $assign ) . ' WHERE id = %d AND version = %d', $params ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->suppress_errors( $suppress );

			if ( ! $changed && $open ) {
				// Reopening clashed with another open request on the order: keep
				// the units reserved without the UNIQUE claim.
				$assign[ count( $assign ) - 2 ] = 'open_order_id = NULL';
				$changed = $wpdb->query( $wpdb->prepare( 'UPDATE %i SET ' . implode( ', ', $assign ) . ' WHERE id = %d AND version = %d', $params ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			}

			unset( self::$rows[ (int) $id ] );
			if ( $changed ) {
				self::counts( true );
				return true;
			}
			return false;
		}

		/**
		 * Overwrite columns that are not part of the status story (the items'
		 * restock record, anonymised personal data). Version-checked too.
		 *
		 * @param int   $id      Return id.
		 * @param int   $version Version the caller read.
		 * @param array $changes Columns.
		 * @return bool
		 */
		public static function patch( $id, $version, array $changes ) {
			global $wpdb;
			$allowed = array( 'items', 'photos', 'statement', 'email', 'comment', 'reason', 'admin_message', 'customer_id' );
			$assign  = array();
			$params  = array( self::table() );
			foreach ( $changes as $col => $value ) {
				if ( ! in_array( $col, $allowed, true ) ) {
					continue;
				}
				if ( in_array( $col, array( 'items', 'photos', 'statement' ), true ) ) {
					$value = wp_json_encode( $value );
				}
				$assign[] = '`' . $col . '` = %s';
				$params[] = (string) $value;
			}
			if ( ! $assign ) {
				return false;
			}
			$assign[] = 'version = version + 1';
			$assign[] = 'updated_at = %s';
			$params[] = self::now();
			$params[] = (int) $id;
			$params[] = (int) $version;
			$changed  = $wpdb->query( $wpdb->prepare( 'UPDATE %i SET ' . implode( ', ', $assign ) . ' WHERE id = %d AND version = %d', $params ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			unset( self::$rows[ (int) $id ] );
			return (bool) $changed;
		}

		/**
		 * Remove rows (the order they belong to was deleted for good).
		 *
		 * @param int $order_id Order id.
		 * @return array[] The rows that were removed (their photos still need deleting).
		 */
		public static function delete_for_order( $order_id ) {
			global $wpdb;
			$rows = self::for_order( $order_id );
			if ( $rows ) {
				$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE order_id = %d', self::table(), absint( $order_id ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				foreach ( $rows as $row ) {
					unset( self::$rows[ $row['id'] ] );
				}
				self::counts( true );
			}
			return $rows;
		}

		/**
		 * Rows of one person, for the privacy exporter and eraser.
		 *
		 * @param string $email   Email address.
		 * @param int    $user_id User id (0 = none).
		 * @param int    $page    Page (1-based).
		 * @param int    $per     Rows per page.
		 * @return array[]
		 */
		public static function for_person( $email, $user_id, $page = 1, $per = 50 ) {
			global $wpdb;
			if ( ! self::ensure_table() ) {
				return array();
			}
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE email = %s OR ( customer_id > 0 AND customer_id = %d ) ORDER BY id ASC LIMIT %d OFFSET %d', self::table(), (string) $email, (int) $user_id, (int) $per, ( max( 1, (int) $page ) - 1 ) * (int) $per ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$out  = array();
			foreach ( (array) $rows as $row ) {
				$item = self::hydrate( $row );
				if ( $item ) {
					$out[] = $item;
				}
			}
			return $out;
		}

		/**
		 * Returns stuck in "resolving" for longer than $seconds.
		 *
		 * @param int $seconds Age.
		 * @return array[]
		 */
		public static function stuck_resolving( $seconds = 300 ) {
			global $wpdb;
			if ( ! self::ensure_table() ) {
				return array();
			}
			$cut  = gmdate( 'Y-m-d H:i:s', (int) apply_filters( 'brikpanel_returns_now', time() ) - (int) $seconds );
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE status = 'resolving' AND updated_at < %s LIMIT 20", self::table(), $cut ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$out  = array();
			foreach ( (array) $rows as $row ) {
				$item = self::hydrate( $row );
				if ( $item ) {
					$out[] = $item;
				}
			}
			return $out;
		}

		/**
		 * Find the return a WooCommerce refund belongs to.
		 *
		 * @param int $refund_id Refund id.
		 * @return array|null
		 */
		public static function by_refund( $refund_id ) {
			global $wpdb;
			$refund_id = absint( $refund_id );
			if ( ! $refund_id || ! self::ensure_table() ) {
				return null;
			}
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE refund_id = %d LIMIT 1', self::table(), $refund_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return self::hydrate( $row );
		}
	}
}
