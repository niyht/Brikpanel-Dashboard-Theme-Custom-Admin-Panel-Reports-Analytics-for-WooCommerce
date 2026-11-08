<?php
/**
 * BrikPanel Customers: everything the customer page shows about one person.
 *
 * A customer is a registered user (u:<id>) or a guest (e:<email>), the keys
 * of the Customers list. Guests have no WordPress account, so their name,
 * phone and addresses come from their latest order. The numbers come from
 * the Customers engine, by the same rules as the list, so the page and the
 * row that opened it always agree.
 *
 * Multisite: users and their billing details are shared by the whole network,
 * so a key alone would let one site's shop manager read another site's
 * customer. A registered customer opens only when they belong to this site or
 * have ordered here; a guest only when they have ordered here.
 *
 * Nothing here is cached: it is personal data and changes with every order.
 *
 * @package BrikPanel
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Brikpanel_Customer_Profile {

	/** Latest orders listed on the page. */
	const ORDERS_SHOWN = 25;

	/** Products listed under "Products bought". */
	const PRODUCTS_SHOWN = 10;

	/** Abandoned carts listed. */
	const CARTS_SHOWN = 5;

	/**
	 * Everything the page shows, or null when the key is malformed or the
	 * person is not a customer of this site.
	 *
	 * @param mixed $raw_key Key from the URL (unslashed).
	 * @return array|null
	 */
	public static function load( $raw_key ) {
		$k = Brikpanel_Customers_Engine::parse_key( $raw_key );
		if ( ! $k ) {
			return null;
		}

		$user  = 'u' === $k['type'] ? get_userdata( $k['id'] ) : null;
		$stats = Brikpanel_Customers_Engine::stats( $k, false );
		if ( 'u' === $k['type'] ) {
			if ( ! $user ) {
				return null;
			}
			// Not this site's member and no order here: someone else's customer.
			if ( null === $stats && is_multisite() && ! is_user_member_of_blog( $user->ID, get_current_blog_id() ) ) {
				return null;
			}
		} elseif ( null === $stats ) {
			return null;
		}

		$latest = ( $stats && $stats['latest_order_id'] > 0 ) ? wc_get_order( $stats['latest_order_id'] ) : null;
		if ( ! $latest instanceof WC_Order ) {
			$latest = null;
		}

		// Who: the account first, the latest order second.
		$account = [];
		if ( $user ) {
			foreach ( [ 'first_name', 'last_name', 'phone', 'city', 'country' ] as $field ) {
				$account[ $field ] = (string) get_user_meta( $user->ID, 'billing_' . $field, true );
			}
		}
		$order_name = $latest ? trim( $latest->get_billing_first_name() . ' ' . $latest->get_billing_last_name() ) : '';
		if ( $user ) {
			$name = trim( $account['first_name'] . ' ' . $account['last_name'] );
			if ( '' === $name ) {
				$name = $order_name;
			}
			if ( '' === $name ) {
				$name = function_exists( 'brikpanel_plain_name' ) ? brikpanel_plain_name( (string) $user->display_name ) : (string) $user->display_name;
			}
			$email = (string) $user->user_email;
		} else {
			$name  = $order_name;
			$email = $k['email'];
		}
		if ( '' === $name ) {
			$name = $email;
		}
		$phone = $user && '' !== $account['phone'] ? $account['phone'] : ( $latest ? (string) $latest->get_billing_phone() : '' );
		$city    = $latest && '' !== $latest->get_billing_city() ? (string) $latest->get_billing_city() : (string) ( $account['city'] ?? '' );
		$country = $latest && '' !== $latest->get_billing_country() ? (string) $latest->get_billing_country() : (string) ( $account['country'] ?? '' );

		// Since when: the earlier of sign-up and first order (any status).
		$registered = $user ? (string) $user->user_registered : '';
		$since      = $stats ? $stats['first_seen'] : '';
		if ( '' !== $registered && '0000-00-00 00:00:00' !== $registered && ( '' === $since || strcmp( $registered, $since ) < 0 ) ) {
			$since = $registered;
		}

		$count = $stats ? (int) $stats['order_count'] : 0;
		$spent = $stats ? (float) $stats['total_spent'] : 0.0;
		$last  = $stats ? (string) $stats['last_order'] : '';
		$days  = '';
		if ( '' !== $last ) {
			$days = (int) floor( max( 0, time() - (int) strtotime( $last . ' UTC' ) ) / DAY_IN_SECONDS );
		}

		$paid_ids = self::order_ids( $k, true );
		$all_ids  = self::order_ids( $k, false );

		return [
			'key'           => $k['key'],
			'is_guest'      => 'e' === $k['type'],
			'user_id'       => $user ? (int) $user->ID : 0,
			'username'      => $user ? (string) $user->user_login : '',
			'name'          => $name,
			'email'         => $email,
			'phone'         => $phone,
			'city'          => $city,
			'country'       => $country,
			'registered'    => $registered,
			'since'         => $since,
			'stats'         => [
				'order_count'     => $count,
				'other_count'     => $stats ? (int) $stats['other_count'] : 0,
				'total_spent'     => $spent,
				'aov'             => $count > 0 ? $spent / $count : 0.0,
				'first_order'     => $stats ? (string) $stats['first_order'] : '',
				'last_order'      => $last,
				'days_since_last' => $days,
			],
			'rfm'           => self::rfm( $k['key'] ),
			'orders'        => self::orders( array_slice( $all_ids, 0, self::ORDERS_SHOWN ) ),
			'orders_total'  => count( $all_ids ),
			'orders_url'    => self::orders_list_url( $k ),
			'products'      => self::products( $paid_ids ),
			'coupons'       => self::coupons( $paid_ids ),
			'carts'         => self::carts( $email, $user ? (int) $user->ID : 0 ),
			'billing'       => self::address( $user, $latest, 'billing' ),
			'shipping'      => self::address( $user, $latest, 'shipping' ),
			'brikmentor'    => self::brikmentor( $email ),
			'whatsapp'      => self::whatsapp( $phone, $country ),
			'edit_user_url' => ( $user && current_user_can( 'edit_user', $user->ID ) ) ? (string) get_edit_user_link( $user->ID ) : '',
		];
	}

	/**
	 * The customer's order ids, newest first, read with the customer's own
	 * index conditions (account id, or guest email with no account).
	 *
	 * @param array $k            Parsed key.
	 * @param bool  $counted_only Only the paid and refunded statuses.
	 * @param int   $limit        At most this many (0 = all).
	 * @return int[]
	 */
	private static function order_ids( array $k, $counted_only, $limit = 0 ) {
		global $wpdb;

		$hpos     = Brikpanel_Customers_Engine::hpos();
		$statuses = $counted_only
			? 'IN (' . brikpanel_statuses_sql_in( brikpanel_ltv_counted_statuses() ) . ')'
			: "NOT IN ('" . implode( "','", array_map( 'esc_sql', Brikpanel_Customers_Engine::NOT_ORDERS ) ) . "')";
		$params   = [];

		if ( $hpos ) {
			$sql = "SELECT o.id FROM {$wpdb->prefix}wc_orders o WHERE o.type = 'shop_order' AND o.status {$statuses}";
			if ( 'u' === $k['type'] ) {
				$sql     .= ' AND o.customer_id = %d';
				$params[] = (int) $k['id'];
			} else {
				$sql     .= ' AND o.customer_id = 0 AND o.billing_email = %s';
				$params[] = (string) $k['email'];
			}
			$sql .= ' ORDER BY o.date_created_gmt DESC, o.id DESC';
		} else {
			// The customer's orders by id, found through their own meta rows
			// (Brikpanel_Customers_Engine::legacy_order_ids()).
			$ids = Brikpanel_Customers_Engine::legacy_order_ids( $k );
			if ( ! $ids ) {
				return [];
			}
			$sql = "SELECT o.ID FROM {$wpdb->posts} o
				WHERE o.ID IN (" . implode( ',', array_map( 'intval', $ids ) ) . ") AND o.post_type = 'shop_order' AND o.post_status {$statuses}
				ORDER BY o.post_date_gmt DESC, o.ID DESC";
		}
		if ( $limit > 0 ) {
			$sql     .= ' LIMIT %d';
			$params[] = (int) $limit;
		}
		return array_map( 'intval', (array) $wpdb->get_col( $params ? $wpdb->prepare( $sql, $params ) : $sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- built from literals, integers and placeholders above.
	}

	/**
	 * Rows for the orders card.
	 *
	 * @param int[] $ids Order ids.
	 * @return array[]
	 */
	private static function orders( array $ids ) {
		$rows = [];
		foreach ( $ids as $id ) {
			$order = wc_get_order( $id );
			if ( ! $order instanceof WC_Order ) {
				continue;
			}
			$created = $order->get_date_created();
			$rows[]  = [
				'id'           => (int) $order->get_id(),
				'number'       => (string) $order->get_order_number(),
				'date'         => $created ? (string) wp_date( brikpanel_date_format(), $created->getTimestamp() ) : '',
				'status'       => (string) $order->get_status(),
				'status_label' => (string) wc_get_order_status_name( $order->get_status() ),
				'items'        => (int) $order->get_item_count(),
				'total_html'   => wc_price( (float) $order->get_total(), [ 'currency' => $order->get_currency() ] ),
				'edit_url'     => (string) $order->get_edit_order_url(),
			];
		}
		return $rows;
	}

	/**
	 * Address of the orders screen filtered to this customer: WooCommerce's
	 * own customer filter for an account, a search on the email for a guest.
	 *
	 * @param array $k Parsed key.
	 * @return string
	 */
	public static function orders_list_url( array $k ) {
		$base = function_exists( 'brikpanel_wc_orders_list_url' ) ? brikpanel_wc_orders_list_url() : admin_url( 'edit.php?post_type=shop_order' );
		return 'u' === $k['type']
			? add_query_arg( '_customer_user', (int) $k['id'], $base )
			: add_query_arg( 's', rawurlencode( $k['email'] ), $base );
	}

	/**
	 * What the customer bought in paid orders, by product (a variable
	 * product's variations together), most bought first.
	 *
	 * @param int[] $order_ids Paid order ids.
	 * @return array[]
	 */
	private static function products( array $order_ids ) {
		global $wpdb;

		if ( ! $order_ids ) {
			return [];
		}
		$out = [];
		foreach ( array_chunk( $order_ids, 1000 ) as $chunk ) {
			$ph   = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT pid.meta_value AS product_id, SUM(qty.meta_value + 0) AS qty, MAX(li.order_item_name) AS item_name
				FROM {$wpdb->prefix}woocommerce_order_items li
				INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta pid ON pid.order_item_id = li.order_item_id AND pid.meta_key = '_product_id'
				INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta qty ON qty.order_item_id = li.order_item_id AND qty.meta_key = '_qty'
				WHERE li.order_item_type = 'line_item' AND li.order_id IN ({$ph})
				GROUP BY pid.meta_value", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$chunk
			) );
			foreach ( (array) $rows as $r ) {
				$pid = (int) $r->product_id;
				if ( ! isset( $out[ $pid ] ) ) {
					$out[ $pid ] = [ 'product_id' => $pid, 'qty' => 0, 'item_name' => (string) $r->item_name ];
				}
				$out[ $pid ]['qty'] += (int) $r->qty;
			}
		}
		usort( $out, static function ( $a, $b ) {
			return $b['qty'] <=> $a['qty'] ?: strcmp( $a['item_name'], $b['item_name'] );
		} );
		$out = array_slice( $out, 0, self::PRODUCTS_SHOWN );
		foreach ( $out as &$p ) {
			$product   = $p['product_id'] > 0 ? wc_get_product( $p['product_id'] ) : null;
			$p['name'] = $product ? (string) $product->get_name() : $p['item_name'];
			$p['url']  = $product && current_user_can( 'edit_post', $p['product_id'] ) ? (string) get_edit_post_link( $p['product_id'], 'raw' ) : '';
			unset( $p['item_name'] );
		}
		return array_values( $out );
	}

	/**
	 * Coupon codes used in paid orders, with how often.
	 *
	 * @param int[] $order_ids Paid order ids.
	 * @return array[]
	 */
	private static function coupons( array $order_ids ) {
		global $wpdb;

		if ( ! $order_ids ) {
			return [];
		}
		$out = [];
		foreach ( array_chunk( $order_ids, 1000 ) as $chunk ) {
			$ph   = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT LOWER(order_item_name) AS code, COUNT(*) AS uses FROM {$wpdb->prefix}woocommerce_order_items
				WHERE order_item_type = 'coupon' AND order_id IN ({$ph}) GROUP BY LOWER(order_item_name)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$chunk
			) );
			foreach ( (array) $rows as $r ) {
				$code         = (string) $r->code;
				$out[ $code ] = ( $out[ $code ] ?? 0 ) + (int) $r->uses;
			}
		}
		arsort( $out );
		$list = [];
		foreach ( $out as $code => $uses ) {
			$list[] = [ 'code' => $code, 'uses' => $uses ];
		}
		return $list;
	}

	/**
	 * The customer's latest abandoned-cart rows, found by email (indexed);
	 * statuses read as the Abandoned Carts screen names them.
	 *
	 * @param string $email   Email.
	 * @param int    $user_id Account id (0 for a guest).
	 * @return array[]
	 */
	private static function carts( $email, $user_id ) {
		global $wpdb;

		$table = $wpdb->prefix . 'brikpanel_abandoned_carts';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return [];
		}
		$email = brikpanel_strtolower( trim( (string) $email ) );
		if ( '' === $email ) {
			return [];
		}
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, status, item_count, cart_total, currency, abandoned_at, order_id, created_at, updated_at
			FROM {$table} WHERE email = %s ORDER BY id DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$email,
			self::CARTS_SHOWN
		) );
		$labels = class_exists( 'Brikpanel_Cart_Abandonment' ) && method_exists( 'Brikpanel_Cart_Abandonment', 'display_status_labels' )
			? Brikpanel_Cart_Abandonment::display_status_labels()
			: [];
		$out = [];
		foreach ( (array) $rows as $r ) {
			$status = class_exists( 'Brikpanel_Cart_Abandonment' ) && method_exists( 'Brikpanel_Cart_Abandonment', 'derive_display_status' )
				? Brikpanel_Cart_Abandonment::derive_display_status( $r->status, $r->item_count, $r->abandoned_at, $r->order_id )
				: (string) $r->status;
			$when  = (string) ( $r->abandoned_at ?: $r->updated_at ?: $r->created_at );
			$out[] = [
				'id'           => (int) $r->id,
				'status'       => (string) $status,
				'status_label' => (string) ( $labels[ $status ] ?? $status ),
				'items'        => (int) $r->item_count,
				'total_html'   => wc_price( (float) $r->cart_total, [ 'currency' => (string) $r->currency ?: get_woocommerce_currency() ] ),
				'date'         => brikpanel_local_date( $when ),
			];
		}
		return $out;
	}

	/**
	 * Billing or shipping address as WooCommerce formats it for the store's
	 * country conventions: the account's, else the latest order's.
	 *
	 * @param WP_User|null  $user   Account.
	 * @param WC_Order|null $latest Latest order.
	 * @param string        $type   billing|shipping.
	 * @return string HTML with <br/> line breaks, or ''.
	 */
	private static function address( $user, $latest, $type ) {
		if ( $user && class_exists( 'WC_Customer' ) ) {
			try {
				$customer = new WC_Customer( $user->ID );
				$fields   = 'billing' === $type ? $customer->get_billing() : $customer->get_shipping();
				unset( $fields['email'], $fields['phone'] );
				// A name alone is not an address: then the latest order's is used.
				$lines = array_intersect_key( $fields, array_flip( [ 'address_1', 'address_2', 'city', 'postcode', 'country' ] ) );
				if ( '' !== trim( implode( '', array_map( 'strval', $lines ) ) ) ) {
					return (string) WC()->countries->get_formatted_address( $fields );
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}
		if ( $latest ) {
			return 'billing' === $type ? (string) $latest->get_formatted_billing_address() : (string) $latest->get_formatted_shipping_address();
		}
		return '';
	}

	/**
	 * RFM segment from the nightly table, with its label.
	 *
	 * @param string $key Customer key.
	 * @return array{key: string, label: string}
	 */
	private static function rfm( $key ) {
		global $wpdb;

		$seg = (string) $wpdb->get_var( $wpdb->prepare(
			"SELECT rfm_segment FROM {$wpdb->prefix}brikpanel_customer_metrics WHERE customer_key = %s",
			$key
		) );
		$label = '';
		if ( '' !== $seg && function_exists( 'brikpanel_ca_rfm_segment_labels' ) ) {
			$labels = brikpanel_ca_rfm_segment_labels();
			if ( isset( $labels[ $seg ] ) ) {
				$label = is_array( $labels[ $seg ] ) ? (string) ( $labels[ $seg ]['label'] ?? $seg ) : (string) $labels[ $seg ];
			}
		}
		return [ 'key' => $seg, 'label' => $label ];
	}

	/**
	 * Email marketing status and the orders BrikMentor's emails brought, when
	 * BrikMentor runs on this site. Its tables are per site and may be from an
	 * older version, so every step is checked and nothing here can break the
	 * page.
	 *
	 * @param string $email Email.
	 * @return array|null Null when BrikMentor is not installed.
	 */
	private static function brikmentor( $email ) {
		global $wpdb;

		if ( ! function_exists( 'brikpanel_brikmentor_installed' ) || ! brikpanel_brikmentor_installed() ) {
			return null;
		}
		$out = [ 'status' => 'none', 'consent_source' => '', 'consent_date' => '', 'orders' => 0, 'revenue' => [] ];
		try {
			if ( class_exists( 'Brikmentor_Contacts' ) && method_exists( 'Brikmentor_Contacts', 'get_by_email' ) ) {
				$contact = Brikmentor_Contacts::get_by_email( $email );
				if ( $contact ) {
					$out['status']         = 'subscribed' === (string) ( $contact->status ?? '' ) ? 'subscribed' : 'unsubscribed';
					$out['consent_source'] = (string) ( $contact->consent_source ?? '' );
					$out['consent_date']   = (string) ( $contact->consent_date ?? '' );
				}
			}
			if ( function_exists( 'brikmentor_table' ) ) {
				$table = (string) brikmentor_table( 'conversions' );
				if ( '' !== $table && $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) {
					$norm = function_exists( 'brikmentor_normalize_email' ) ? (string) brikmentor_normalize_email( $email ) : brikpanel_strtolower( (string) $email );
					$rows = $wpdb->get_results( $wpdb->prepare(
						"SELECT currency, COUNT(*) AS n, SUM(order_total - refund_total) AS net FROM {$table} WHERE email = %s GROUP BY currency", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						$norm
					) );
					foreach ( (array) $rows as $r ) {
						$out['orders'] += (int) $r->n;
						$cur            = (string) $r->currency ?: get_woocommerce_currency();
						$out['revenue'][ $cur ] = ( $out['revenue'][ $cur ] ?? 0 ) + (float) $r->net;
					}
				}
			}
		} catch ( \Throwable $e ) {
			unset( $e );
		}
		$wpdb->last_error = '';
		return $out;
	}

	/**
	 * WhatsApp chat address, when the store shows WhatsApp to this user.
	 *
	 * @param string $phone   Phone as typed.
	 * @param string $country Billing country, for numbers typed without one.
	 * @return string
	 */
	private static function whatsapp( $phone, $country ) {
		if ( '' === trim( (string) $phone ) || ! function_exists( 'brikpanel_phone_to_e164' ) ) {
			return '';
		}
		if ( function_exists( 'brikpanel_whatsapp_visible_for_user' ) && ! brikpanel_whatsapp_visible_for_user() ) {
			return '';
		}
		$digits = (string) brikpanel_phone_to_e164( $phone, $country );
		return '' !== $digits ? 'https://wa.me/' . $digits : '';
	}
}
