<?php
/**
 * BrikPanel - phone notifications: the screens.
 *
 * - WooCommerce > Settings > BrikPanel > Notifications: the "Phone
 *   notifications" switch, "Notification text" (which parts a notification
 *   shows and in which order, with a preview), then "Your devices": each person sees only their
 *   own devices, can send a test or remove one, and problems on the server
 *   side (no https, outside requests blocked, a copy of the store) are listed
 *   first.
 * - A phone: the top bar's phone button, whose panel opens by itself on every
 *   page until notifications are on there or the person presses its X and
 *   confirms; where the top bar has no phone button, a dashboard card that
 *   takes its turn in the one-ask-at-a-time line (includes/brikpanel-asks.php,
 *   id "push"); a hidden line for a phone whose notifications stopped.
 * - brikpanel-push.js where it matters, and the AJAX it calls (nonce
 *   "brikpanel_push" and manage_woocommerce on every call; a person only
 *   ever touches their own devices).
 *
 * @package BrikPanel
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// -----------------------------------------------------------------------------
// Settings: WooCommerce > Settings > BrikPanel > Notifications
// -----------------------------------------------------------------------------

/**
 * @param array $map Title id => section.
 * @return array
 */
function brikpanel_push_settings_title_map( $map ) {
	if ( is_array( $map ) ) {
		$map['brk_push_title'] = 'notifications';
	}
	return $map;
}
add_filter( 'brikpanel_settings_title_section_map', 'brikpanel_push_settings_title_map' );

/**
 * The block goes right after "Order notifications" (owner decision, 7 October
 * 2026: switch on by default, in the Notifications section).
 *
 * @param array $fields Settings fields.
 * @return array
 */
function brikpanel_push_settings_fields( $fields ) {
	if ( ! is_array( $fields ) ) {
		return $fields;
	}
	$block = array(
		array(
			'name' => __( 'Phone notifications', 'brikpanel' ),
			'type' => 'title',
			'id'   => 'brk_push_title',
			'desc' => __( 'A notification on your phone for every new order, also when the phone is locked. Each person turns it on on their own phone.', 'brikpanel' ),
		),
		array(
			'name'    => __( 'Phone notifications', 'brikpanel' ),
			'id'      => 'brikpanel_push_enabled',
			'type'    => 'checkbox',
			'desc'    => __( 'Send every new order (processing, completed or on hold) to the phones and computers where notifications are turned on. A notification never shows the customer.', 'brikpanel' ),
			'default' => 'yes',
		),
		// What the notification says (owner decision, 30 October 2026): brikpanel_push_render_parts_field().
		array(
			'name' => __( 'Notification text', 'brikpanel' ),
			'id'   => 'brikpanel_push_parts_field',
			'type' => 'brikpanel_push_parts',
			'desc' => __( 'Choose what an order notification shows, and in which order.', 'brikpanel' ),
		),
		array(
			'type' => 'brikpanel_push_devices',
			'id'   => 'brikpanel_push_devices_card',
		),
		array(
			'type' => 'sectionend',
			'id'   => 'brk_push_title',
		),
	);
	foreach ( array_values( $fields ) as $index => $field ) {
		if ( isset( $field['type'], $field['id'] ) && 'sectionend' === $field['type'] && 'brk_order_notify_title' === $field['id'] ) {
			$fields = array_values( $fields );
			array_splice( $fields, $index + 1, 0, $block );
			return $fields;
		}
	}
	return array_merge( $fields, $block );
}
add_filter( 'brikpanel_settings_fields', 'brikpanel_push_settings_fields', 8 );

/** @return bool On WooCommerce > Settings > BrikPanel > Notifications. */
function brikpanel_push_on_settings() {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only screen check.
	return isset( $_GET['page'], $_GET['tab'] )
		&& 'wc-settings' === sanitize_key( wp_unslash( $_GET['page'] ) )
		&& 'brikpanel' === sanitize_key( wp_unslash( $_GET['tab'] ) )
		&& function_exists( 'brikpanel_settings_get_current_section' )
		&& 'notifications' === brikpanel_settings_get_current_section();
	// phpcs:enable
}

/** @return bool On the BrikPanel dashboard. */
function brikpanel_push_on_dashboard() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen check.
	return isset( $_GET['page'] ) && 'brikpanel-dashboard' === sanitize_key( wp_unslash( $_GET['page'] ) );
}

/** @return bool The person may fix the store-wide problems (new keys, the address). */
function brikpanel_push_is_site_admin() {
	// The administrator role, not manage_options: stores hand that capability
	// to shop managers (includes/brikpanel-access-control.php).
	if ( function_exists( 'brikpanel_user_is_administrator' ) ) {
		return (bool) brikpanel_user_is_administrator();
	}
	return current_user_can( 'manage_options' );
}

/**
 * Store-wide problems first, in the person's language. Only what is wrong (or
 * worth knowing) is listed.
 *
 * @return array[] Each: text, tone (bad|info), op (an action button), label.
 */
function brikpanel_push_health() {
	$lines = array();
	if ( ! brikpanel_push_enabled() ) {
		$lines[] = array(
			'tone' => 'info',
			'text' => __( 'Phone notifications are off for this store. Turn on "Phone notifications" above and save.', 'brikpanel' ),
		);
	}
	if ( ! Brikpanel_Push_Store::crypto_ok() ) {
		$lines[] = array(
			'tone' => 'bad',
			'text' => __( 'This server cannot sign notifications: its PHP OpenSSL extension has no elliptic curve support. Ask your host to update OpenSSL.', 'brikpanel' ),
		);
	} elseif ( Brikpanel_Push_Store::vapid_unreadable() ) {
		$lines[] = array(
			'tone'  => 'bad',
			'text'  => __( 'Notifications are paused: the key that signs them can no longer be read, because the security keys in this site\'s wp-config.php file changed. After new keys are created, each phone turns notifications back on by itself the next time BrikPanel is opened on it.', 'brikpanel' ),
			'op'    => brikpanel_push_is_site_admin() ? 'new_keys' : '',
			'label' => __( 'Create new keys', 'brikpanel' ),
		);
	}
	if ( ! brikpanel_push_secure_request() ) {
		$lines[] = array(
			'tone' => 'bad',
			'text' => __( 'Phone notifications need the store admin on https. Open this page with https:// to turn them on.', 'brikpanel' ),
		);
	}
	if ( ! Brikpanel_Push_Store::site_matches() ) {
		$lines[] = array(
			'tone'  => 'bad',
			'text'  => sprintf(
				/* translators: 1: this site's address, 2: the address where phones turned notifications on. */
				__( 'Notifications are paused: this site is %1$s, but the phones were turned on at %2$s. A copy of a store, such as a staging site, never sends to the owner\'s phones. If the store really moved here, send from this address.', 'brikpanel' ),
				Brikpanel_Push_Store::site_key(),
				(string) get_option( Brikpanel_Push_Store::OPT_SITE, '' )
			),
			'op'    => brikpanel_push_is_site_admin() ? 'resume_site' : '',
			'label' => __( 'Send from this address', 'brikpanel' ),
		);
	}
	$blocked = brikpanel_push_blocked_hosts();
	if ( $blocked ) {
		$lines[] = array(
			'tone' => 'bad',
			'text' => sprintf(
				/* translators: %s: comma-separated host names. */
				__( 'This site blocks outside requests (WP_HTTP_BLOCK_EXTERNAL in wp-config.php). Add these hosts to WP_ACCESSIBLE_HOSTS: %s', 'brikpanel' ),
				implode( ', ', $blocked )
			),
		);
	}
	if ( function_exists( 'brikpanel_pwa_shared_worker' ) && brikpanel_pwa_shared_worker() ) {
		$lines[] = array(
			'tone' => 'info',
			'text' => __( 'Works together with the PWA plugin: its service worker shows BrikPanel\'s notifications too.', 'brikpanel' ),
		);
	}
	return $lines;
}

/**
 * Push services that WP_HTTP_BLOCK_EXTERNAL would stop.
 *
 * @return string[]
 */
function brikpanel_push_blocked_hosts() {
	if ( ! defined( 'WP_HTTP_BLOCK_EXTERNAL' ) || ! WP_HTTP_BLOCK_EXTERNAL ) {
		return array();
	}
	$allowed = defined( 'WP_ACCESSIBLE_HOSTS' ) ? array_map( 'trim', explode( ',', strtolower( (string) WP_ACCESSIBLE_HOSTS ) ) ) : array();
	$hosts   = (array) apply_filters( 'brikpanel_push_allowed_hosts', array( 'fcm.googleapis.com', '*.push.apple.com', 'updates.push.services.mozilla.com', '*.notify.windows.com' ) );
	$missing = array();
	foreach ( $hosts as $host ) {
		$host = strtolower( (string) $host );
		if ( ! in_array( $host, $allowed, true ) ) {
			$missing[] = $host;
		}
	}
	return $missing;
}

/**
 * What a device's last notification did, in the viewer's language.
 *
 * @param array $row Device row.
 * @return array{text:string,tone:string}
 */
function brikpanel_push_device_status( array $row ) {
	$now = Brikpanel_Push_Store::now_ts();
	$ts  = static function ( $value ) {
		return empty( $value ) ? 0 : (int) strtotime( $value . ' UTC' );
	};
	if ( 'gone' === $row['status'] ) {
		return array(
			'text' => __( 'Stopped: this device no longer accepts notifications. Turn them on again on the device.', 'brikpanel' ),
			'tone' => 'bad',
		);
	}
	if ( ! empty( $row['fail_code'] ) || ! empty( $row['fail_reason'] ) ) {
		$why = trim( ( empty( $row['fail_code'] ) ? '' : (int) $row['fail_code'] . ' ' ) . (string) $row['fail_reason'] );
		if ( 'blocked' === $row['fail_reason'] ) {
			$why = __( 'outside requests are blocked on this site', 'brikpanel' );
		}
		return array(
			/* translators: %s: error code and reason from the push service, such as "403 BadJwtToken". */
			'text' => sprintf( __( 'The last notification failed: %s', 'brikpanel' ), $why ),
			'tone' => 'bad',
		);
	}
	$confirmed = $ts( $row['confirmed_at'] );
	$pending   = (int) $row['pending_count'];
	if ( $pending > 0 && $confirmed && $ts( $row['pending_since'] ) && $now - $ts( $row['pending_since'] ) > HOUR_IN_SECONDS ) {
		return array(
			/* translators: %s: date and time. */
			'text' => sprintf( __( 'Not confirmed since %s', 'brikpanel' ), wp_date( brikpanel_datetime_format(), $ts( $row['pending_since'] ) ) ),
			'tone' => 'bad',
		);
	}
	if ( $confirmed ) {
		// The phone's receipt answers the last send: how long it took to get there.
		$sent  = $ts( $row['sent_at'] );
		$delay = ( 0 === $pending && $sent && $sent <= $confirmed ) ? $confirmed - $sent : -1;
		if ( $delay >= 2 * MINUTE_IN_SECONDS && 'android' === (string) $row['os'] && 'firefox' === (string) $row['browser'] ) {
			// Mozilla's push server sends Firefox its messages at normal priority: no setting helps.
			return array(
				/* translators: %s: how late, such as "7 mins". */
				'text' => sprintf( __( 'The last notification arrived %s late: Firefox holds notifications while the phone sleeps, and no setting changes that. For instant ones, turn them on in Chrome.', 'brikpanel' ), human_time_diff( 0, $delay ) ),
				'tone' => 'bad',
			);
		}
		if ( $delay >= 2 * MINUTE_IN_SECONDS && 'android' === (string) $row['os'] ) {
			// Chrome shows a site's notification from a background job, and Android
			// runs those late while the phone sleeps unless the browser is unrestricted.
			$browser = Brikpanel_Push_Sender::browser_label( (string) $row['browser'] );
			$browser = '' === $browser ? 'Chrome' : $browser;
			return array(
				/* translators: 1: how late, such as "7 mins". 2: browser name (Chrome, Brave, Samsung Internet...). The menu path is in Android's own Settings app. */
				'text' => sprintf( __( 'The last notification arrived %1$s late: Android held it back while the phone slept. In Settings > Apps > %2$s > Battery, choose Unrestricted.', 'brikpanel' ), human_time_diff( 0, $delay ), $browser ),
				'tone' => 'bad',
			);
		}
		if ( $delay >= 0 ) {
			return array(
				/* translators: 1: how long ago, such as "5 mins". 2: how long the notification took to arrive, such as "2 secs". */
				'text' => sprintf( __( 'Last notification delivered %1$s ago, %2$s after it was sent', 'brikpanel' ), human_time_diff( $confirmed, max( $confirmed, $now ) ), human_time_diff( 0, max( 1, $delay ) ) ),
				'tone' => 'good',
			);
		}
		return array(
			/* translators: %s: how long ago, such as "5 mins". */
			'text' => sprintf( __( 'Last notification delivered %s ago', 'brikpanel' ), human_time_diff( $confirmed, max( $confirmed, $now ) ) ),
			'tone' => 'good',
		);
	}
	if ( ! empty( $row['sent_at'] ) ) {
		return array(
			'text' => __( 'Sent, waiting for the device to confirm it', 'brikpanel' ),
			'tone' => 'plain',
		);
	}
	return array(
		'text' => __( 'Ready. Send a test to check it.', 'brikpanel' ),
		'tone' => 'plain',
	);
}

/**
 * The person's devices, for the card and for the AJAX refresh.
 *
 * @param int $user_id User id.
 * @return string HTML.
 */
function brikpanel_push_devices_html( $user_id ) {
	$rows = Brikpanel_Push_Store::user_devices( (int) $user_id );
	if ( ! $rows ) {
		return '<p class="brikpanel-push-empty" dir="auto">' . esc_html__( 'No devices yet. Open the store admin on your phone and tap the phone button in the top bar, or turn notifications on for this device below.', 'brikpanel' ) . '</p>';
	}
	$can_send = brikpanel_push_available() && Brikpanel_Push_Store::site_matches() && ! Brikpanel_Push_Store::vapid_unreadable();
	ob_start();
	echo '<ul class="brikpanel-push-list">';
	foreach ( $rows as $row ) {
		$status = brikpanel_push_device_status( $row );
		$phone  = ! empty( $row['phone'] );
		?>
		<li class="brikpanel-push-row" data-bp-push-row data-id="<?php echo (int) $row['id']; ?>" data-hash="<?php echo esc_attr( $row['endpoint_hash'] ); ?>">
			<span class="brikpanel-push-row__icon" aria-hidden="true">
				<?php if ( $phone ) : ?>
					<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" focusable="false"><rect x="6.5" y="2.5" width="11" height="19" rx="2.5"/><path d="M10.5 18.5h3"/></svg>
				<?php else : ?>
					<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" focusable="false"><rect x="3" y="4.5" width="18" height="12" rx="1.8"/><path d="M8.5 20h7M12 16.5V20"/></svg>
				<?php endif; ?>
			</span>
			<span class="brikpanel-push-row__text">
				<span class="brikpanel-push-row__name">
					<span dir="auto"><?php echo esc_html( Brikpanel_Push_Sender::device_name( $row ) ); ?></span>
					<span class="brikpanel-badge brikpanel-push-row__this" data-bp-push-this hidden><?php esc_html_e( 'This device', 'brikpanel' ); ?></span>
				</span>
				<span class="brikpanel-push-row__status brikpanel-push-row__status--<?php echo esc_attr( $status['tone'] ); ?>" dir="auto"><?php echo esc_html( $status['text'] ); ?></span>
			</span>
			<span class="brikpanel-push-row__actions">
				<?php if ( 'active' === $row['status'] && $can_send ) : ?>
					<button type="button" class="brikpanel-btn brikpanel-btn--secondary" data-bp-push-test><?php esc_html_e( 'Send test', 'brikpanel' ); ?></button>
				<?php endif; ?>
				<button type="button" class="brikpanel-btn brikpanel-btn--danger-link" data-bp-push-remove><?php esc_html_e( 'Remove', 'brikpanel' ); ?></button>
			</span>
		</li>
		<?php
	}
	echo '</ul>';
	return (string) ob_get_clean();
}

/**
 * The names of the notification's parts, as the Settings list shows them.
 *
 * @return array<string,string>
 */
function brikpanel_push_parts_labels() {
	return array(
		'total'    => __( 'Total', 'brikpanel' ),
		'items'    => __( 'Number of items', 'brikpanel' ),
		'number'   => __( 'Order number', 'brikpanel' ),
		'skus'     => __( 'SKU', 'brikpanel' ),
		'campaign' => __( 'Campaign', 'brikpanel' ),
		'store'    => __( 'Store name', 'brikpanel' ),
	);
}

/**
 * The preview's text for a store without orders yet: made with the same
 * formatters as a real notification; no SKU and no campaign to show.
 *
 * @return array<string,string>
 */
function brikpanel_push_parts_example() {
	$currency = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '';
	return array(
		'total'    => brikpanel_money_text( 59, array( 'currency' => $currency ) ),
		/* translators: %s: number of items. */
		'items'    => sprintf( _n( '%s item', '%s items', 2, 'brikpanel' ), brikpanel_number( 2 ) ),
		/* translators: %s: order number, e.g. #1234. Shown in a phone notification. */
		'number'   => sprintf( _x( '#%s', 'order number in a phone notification', 'brikpanel' ), '1001' ),
		'skus'     => '',
		'campaign' => '',
		'store'    => Brikpanel_Push_Sender::store_name(),
	);
}

/**
 * "Notification text": which parts an order notification shows, and in which
 * order (apattee, 29 October 2026; owner decision, 30 October 2026). A switch
 * and two arrows per part, like Dashboard sections, then the notification the
 * way the store's newest order reads with them. A part that order lacks (no
 * SKU, no campaign) shows its name in grey italics. brikpanel-push.js moves the
 * rows and redraws the preview; brikpanel_push_parts_save() stores the choice.
 * Inside the "Phone notifications" card's table, so it is a plain row.
 *
 * @param array $field WooCommerce settings field.
 * @return void
 */
function brikpanel_push_render_parts_field( $field ) {
	$chosen = brikpanel_push_parts();
	$on     = array_flip( $chosen['on'] );
	$labels = brikpanel_push_parts_labels();
	// A paid order first: the sample shows the parts, not "Awaiting payment".
	$latest = Brikpanel_Push_Sender::latest_order( array( 'processing', 'completed' ) );
	$latest = $latest ? $latest : Brikpanel_Push_Sender::latest_order();
	$values = $latest ? Brikpanel_Push_Sender::parts( $latest ) : brikpanel_push_parts_example();
	$icons  = brikpanel_pwa_app_icons();
	$title  = isset( $field['title'] ) ? (string) $field['title'] : ( isset( $field['name'] ) ? (string) $field['name'] : '' );
	$help   = isset( $field['desc'] ) ? (string) $field['desc'] : '';
	$up     = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><polyline points="18 15 12 9 6 15"/></svg>';
	$down   = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><polyline points="6 9 12 15 18 9"/></svg>';
	$body   = array();
	foreach ( $chosen['on'] as $key ) {
		if ( isset( $values[ $key ] ) && '' !== $values[ $key ] ) {
			$body[] = '<span>' . esc_html( $values[ $key ] ) . '</span>';
		} elseif ( isset( $labels[ $key ] ) && in_array( $key, array( 'skus', 'campaign' ), true ) ) {
			$body[] = '<span class="is-example">' . esc_html( $labels[ $key ] ) . '</span>';
		}
	}
	?>
	<tr valign="top">
		<th scope="row" class="titledesc">
			<label id="brikpanel-push-parts-label"><?php echo esc_html( $title ); ?></label>
		</th>
		<td class="forminp">
			<div class="brikpanel-push-parts" id="brikpanel-push-parts" data-bp-push-parts>
				<?php if ( '' !== $help ) : ?>
					<p class="brikpanel-push-parts__help" dir="auto"><?php echo esc_html( $help ); ?></p>
				<?php endif; ?>
				<ul class="brikpanel-push-parts__list" role="list" aria-labelledby="brikpanel-push-parts-label" data-bp-parts-list>
					<?php
					foreach ( $chosen['order'] as $key ) :
						$text    = isset( $values[ $key ] ) ? (string) $values[ $key ] : '';
						$example = '' === $text && in_array( $key, array( 'skus', 'campaign' ), true );
						?>
						<li class="brikpanel-push-parts__row<?php echo isset( $on[ $key ] ) ? '' : ' is-off'; ?>" data-part="<?php echo esc_attr( $key ); ?>" data-text="<?php echo esc_attr( $example ? $labels[ $key ] : $text ); ?>"<?php echo $example ? ' data-example="1"' : ''; ?>>
							<label class="brikpanel-push-parts__toggle">
								<span class="brikpanel-push-parts__switch">
									<input type="checkbox" name="brikpanel_push_parts_on[]" value="<?php echo esc_attr( $key ); ?>" <?php checked( isset( $on[ $key ] ) ); ?>>
									<span class="brikpanel-push-parts__track" aria-hidden="true"></span>
								</span>
								<span class="brikpanel-push-parts__label" dir="auto"><?php echo esc_html( $labels[ $key ] ); ?></span>
							</label>
							<span class="brikpanel-push-parts__value" dir="auto"><?php echo esc_html( $text ); ?></span>
							<span class="brikpanel-push-parts__actions">
								<button type="button" class="brikpanel-push-parts__btn" data-bp-parts-move="up" aria-label="<?php esc_attr_e( 'Move up', 'brikpanel' ); ?>" title="<?php esc_attr_e( 'Move up', 'brikpanel' ); ?>"><?php echo $up; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?></button>
								<button type="button" class="brikpanel-push-parts__btn" data-bp-parts-move="down" aria-label="<?php esc_attr_e( 'Move down', 'brikpanel' ); ?>" title="<?php esc_attr_e( 'Move down', 'brikpanel' ); ?>"><?php echo $down; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?></button>
							</span>
						</li>
					<?php endforeach; ?>
				</ul>
				<input type="hidden" id="brikpanel_push_parts_json" name="brikpanel_push_parts_json" value="<?php echo esc_attr( brikpanel_push_parts_sanitize( $chosen ) ); ?>">
				<div class="brikpanel-push-parts__preview">
					<p class="brikpanel-push-parts__preview-label" dir="auto"><?php echo esc_html_x( 'Preview', 'heading above a sample phone notification', 'brikpanel' ); ?></p>
					<div class="brikpanel-push-parts__note">
						<img class="brikpanel-push-parts__note-icon<?php echo $icons['store'] ? ' is-store' : ''; ?>" src="<?php echo esc_url( $icons['192'] ); ?>" width="20" height="20" alt="" decoding="async">
						<span class="brikpanel-push-parts__note-app" dir="auto"><?php echo esc_html( brikpanel_pwa_app_short_name() ); ?></span>
						<span class="brikpanel-push-parts__note-time"><?php echo esc_html_x( 'now', 'time of a sample phone notification', 'brikpanel' ); ?></span>
						<p class="brikpanel-push-parts__note-title" dir="auto"><?php echo esc_html_x( 'New order', 'phone notification title', 'brikpanel' ); ?></p>
						<p class="brikpanel-push-parts__note-body" dir="auto" aria-live="polite" data-bp-parts-body><?php echo implode( ' · ', $body ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- each part escaped above. ?></p>
					</div>
					<p class="brikpanel-push-parts__hint" dir="auto"><?php esc_html_e( 'SKUs and the campaign show when the order has them.', 'brikpanel' ); ?></p>
				</div>
			</div>
		</td>
	</tr>
	<?php
}
add_action( 'woocommerce_admin_field_brikpanel_push_parts', 'brikpanel_push_render_parts_field' );

/**
 * Stores "Notification text" when the Notifications section is saved. Runs
 * after WooCommerce's own save (which skips this row: no input carries its id)
 * and only when the list was on the page, so a save of another section never
 * resets it. WooCommerce checked its settings nonce before this action.
 *
 * @return void
 */
function brikpanel_push_parts_save() {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		return;
	}
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce verified its settings nonce before this action.
	if ( ! isset( $_POST['brikpanel_push_parts_json'] ) ) {
		return;
	}
	$posted = json_decode( (string) wp_unslash( $_POST['brikpanel_push_parts_json'] ), true ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON, each key checked against the known parts.
	$on     = isset( $_POST['brikpanel_push_parts_on'] ) && is_array( $_POST['brikpanel_push_parts_on'] )
		? array_map( 'sanitize_key', (array) wp_unslash( $_POST['brikpanel_push_parts_on'] ) )
		: array();
	// phpcs:enable
	$order = is_array( $posted ) && isset( $posted['order'] ) && is_array( $posted['order'] ) ? $posted['order'] : array();
	update_option(
		'brikpanel_push_parts',
		brikpanel_push_parts_sanitize(
			array(
				'order' => $order,
				'on'    => $on,
			)
		),
		false
	);
}
add_action( 'woocommerce_update_options_brikpanel', 'brikpanel_push_parts_save', 11 );

/**
 * "Your devices": closes WooCommerce's settings table, draws the card,
 * reopens an empty table for the closing sectionend (same as the other
 * custom settings cards).
 *
 * @return void
 */
function brikpanel_push_render_devices_card() {
	$user_id = get_current_user_id();
	$health  = brikpanel_push_health();
	?>
	</table>
	<section class="bp-settings-card bp-settings-card--custom brikpanel-push-devices wc-settings-prevent-change-event" id="brikpanel-push-devices" data-bp-push-devices>
		<header class="brikpanel-push-devices__head">
			<h3 class="brikpanel-push-devices__title" dir="auto"><?php esc_html_e( 'Your devices', 'brikpanel' ); ?></h3>
			<p class="brikpanel-push-devices__sub" dir="auto"><?php esc_html_e( 'Devices where you turned on order notifications. Each person sees only their own.', 'brikpanel' ); ?></p>
		</header>
		<?php if ( $health ) : ?>
			<ul class="brikpanel-push-health">
				<?php foreach ( $health as $line ) : ?>
					<li class="brikpanel-push-health__line brikpanel-push-health__line--<?php echo esc_attr( $line['tone'] ); ?>">
						<span dir="auto"><?php echo esc_html( $line['text'] ); ?></span>
						<?php if ( ! empty( $line['op'] ) ) : ?>
							<button type="button" class="brikpanel-btn brikpanel-btn--secondary" data-bp-push-op="<?php echo esc_attr( $line['op'] ); ?>"><?php echo esc_html( $line['label'] ); ?></button>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
		<div class="brikpanel-push-devices__list" data-bp-push-list aria-live="polite">
			<?php echo brikpanel_push_devices_html( $user_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?>
		</div>
		<footer class="brikpanel-push-devices__foot">
			<button type="button" class="brikpanel-btn brikpanel-btn--primary" data-bp-push-add hidden><?php esc_html_e( 'Turn on for this device', 'brikpanel' ); ?></button>
			<p class="brikpanel-push-devices__hint" data-bp-push-hint dir="auto" hidden></p>
			<?php // Android, on for this device: the same step the dashboard card shows after turning on. ?>
			<div class="brikpanel-push-card__tip brikpanel-push-devices__tip" data-bp-push-battery hidden>
				<p class="brikpanel-push-card__tip-title" dir="auto"><?php esc_html_e( 'For instant notifications while the phone sleeps', 'brikpanel' ); ?></p>
				<p class="brikpanel-push-card__tip-body" dir="auto" data-bp-push-battery-text></p>
				<p class="brikpanel-push-card__tip-path" dir="auto" data-bp-push-battery-path></p>
			</div>
		</footer>
	</section>
	<table class="form-table">
	<?php
}
add_action( 'woocommerce_admin_field_brikpanel_push_devices', 'brikpanel_push_render_devices_card' );

// -----------------------------------------------------------------------------
// The dashboard on a phone
// -----------------------------------------------------------------------------

/**
 * "Get every new order on this phone": one tap turns notifications on. On an
 * iPhone browser tab the card shows how to add BrikPanel to the Home Screen
 * first (iPhones send notifications only to Home Screen apps).
 *
 * Printed only when this page view comes from a phone that can turn them on
 * (the cookie brikpanel-push.js sets); before the script has run once on a
 * new phone (the Home Screen app keeps its own cookies), it is printed hidden
 * and the script shows it.
 *
 * @return void
 */
function brikpanel_push_card_render() {
	// Where the top bar has the phone button, its panel opening by itself is the
	// offer (brikpanel_push_menu_auto()); the card is for a top bar without it.
	if ( brikpanel_push_menu_printed() || ! function_exists( 'brikpanel_ask_allows' ) || ! brikpanel_ask_allows( 'push' ) ) {
		return;
	}
	$cookie = brikpanel_push_phone_cookie();
	if ( '' !== $cookie && ! in_array( $cookie, array( 'a', 'i' ), true ) ) {
		return;
	}
	$visible = '' !== $cookie;
	if ( $visible ) {
		brikpanel_ask_shown( 'push' );
	}
	?>
	<div class="brikpanel-ea-card brikpanel-ea-card--ask brikpanel-push-card brikpanel-ask" data-bp-ask="push" data-bp-push-card<?php echo $visible ? '' : ' hidden'; ?>>
		<div class="brikpanel-ea-card__badge" aria-hidden="true">
			<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" focusable="false"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/></svg>
		</div>
		<?php brikpanel_push_offer_markup( $cookie, 'card' ); ?>
		<button type="button" class="brikpanel-ea-card__close" data-bp-ask-close aria-label="<?php esc_attr_e( 'Dismiss', 'brikpanel' ); ?>">
			<svg width="13" height="13" viewBox="0 0 14 14" fill="none" aria-hidden="true" focusable="false"><path d="M1 1l12 12M13 1L1 13" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
		</button>
	</div>
	<?php
	if ( function_exists( 'brikpanel_ea_print_card_styles' ) ) {
		brikpanel_ea_print_card_styles();
	}
}
add_action( 'brikpanel_dashboard_before_sections', 'brikpanel_push_card_render', 5 );

/**
 * The steps that turn notifications on, shared by the dashboard card and the
 * phone button's panel in the top bar: one view per state (brikpanel-push.js
 * switches them) and the buttons. The card keeps the early-access card's look,
 * the panel has its own (brikpanel-push.css), so only the class names differ.
 *
 * @param string $cookie What this phone said it can do (brikpanel_push_phone_cookie()).
 * @param string $where  'card' or 'menu'.
 * @return void
 */
function brikpanel_push_offer_markup( $cookie, $where ) {
	$card  = 'card' === $where;
	$ios   = 'i' === $cookie;
	$text  = $card ? 'brikpanel-ea-card__text' : 'brikpanel-push-offer__text';
	$title = $card ? 'brikpanel-ea-card__title' : 'brikpanel-push-offer__title';
	$body  = $card ? 'brikpanel-ea-card__body' : 'brikpanel-push-offer__body';
	$cta   = $card ? 'brikpanel-ea-card__cta' : 'brikpanel-btn brikpanel-btn--primary brikpanel-push-offer__cta';
	// The app carries the store's name (brikpanel_pwa_app_short_name()): the steps say it the way the phone shows it.
	$app = brikpanel_pwa_app_short_name();
	?>
	<div class="<?php echo esc_attr( $text ); ?>">
		<div class="brikpanel-push-card__view" data-bp-push-view="ask"<?php echo $ios ? ' hidden' : ''; ?>>
			<p class="<?php echo esc_attr( $title ); ?>" dir="auto"><?php esc_html_e( 'Get every new order on this phone', 'brikpanel' ); ?></p>
			<p class="<?php echo esc_attr( $body ); ?>" dir="auto"><?php esc_html_e( 'A notification with the order number and total, also when the phone is locked. The customer is never named.', 'brikpanel' ); ?></p>
		</div>
		<div class="brikpanel-push-card__view" data-bp-push-view="ios"<?php echo $ios ? '' : ' hidden'; ?>>
			<p class="<?php echo esc_attr( $title ); ?>" dir="auto"><?php esc_html_e( 'Get every new order on this iPhone', 'brikpanel' ); ?></p>
			<p class="<?php echo esc_attr( $body ); ?>" dir="auto"><?php echo esc_html( sprintf( /* translators: %s: the app's name on the phone, which is the store's name. */ __( 'An iPhone shows notifications only for apps on the Home Screen. Add %s there first:', 'brikpanel' ), $app ) ); ?></p>
			<ol class="brikpanel-push-card__steps">
				<li dir="auto"><?php esc_html_e( 'Tap the Share button in Safari.', 'brikpanel' ); ?>
					<svg class="brikpanel-push-card__share" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M12 15V3"/><path d="M8 7l4-4 4 4"/><path d="M7 10H5.5A1.5 1.5 0 0 0 4 11.5v8A1.5 1.5 0 0 0 5.5 21h13a1.5 1.5 0 0 0 1.5-1.5v-8a1.5 1.5 0 0 0-1.5-1.5H17"/></svg>
				</li>
				<li dir="auto"><?php esc_html_e( 'Choose "Add to Home Screen".', 'brikpanel' ); ?></li>
				<li dir="auto"><?php echo esc_html( $card ? sprintf( /* translators: %s: the app's name on the phone, which is the store's name. */ __( 'Open %s from the Home Screen and turn notifications on there.', 'brikpanel' ), $app ) : sprintf( /* translators: %s: the app's name on the phone, which is the store's name. */ __( 'Open %s from the Home Screen and tap the phone button at the top to turn notifications on.', 'brikpanel' ), $app ) ); ?></li>
			</ol>
		</div>
		<?php
		// Android in the browser: the app first, notifications inside it. A permission
		// given in the tab does not carry over: on Android 13 and later the installed
		// app has its own, starts without it, and Chrome then drops its notifications.
		?>
		<div class="brikpanel-push-card__view" data-bp-push-view="install" hidden>
			<p class="<?php echo esc_attr( $title ); ?>" dir="auto"><?php esc_html_e( 'Get every new order on this phone', 'brikpanel' ); ?></p>
			<p class="<?php echo esc_attr( $body ); ?>" dir="auto"><?php echo esc_html( sprintf( /* translators: %1$s: the app's name on the phone, which is the store's name. */ __( 'First add %1$s to this phone as an app. Then open the %1$s app and turn on order notifications there.', 'brikpanel' ), $app ) ); ?></p>
		</div>
		<div class="brikpanel-push-card__view" data-bp-push-view="installed" hidden>
			<p class="<?php echo esc_attr( $title ); ?>" dir="auto"><?php echo esc_html( sprintf( /* translators: %s: the app's name on the phone, which is the store's name. */ __( '%s is being added to this phone', 'brikpanel' ), $app ) ); ?></p>
			<p class="<?php echo esc_attr( $body ); ?>" dir="auto"><?php echo esc_html( $card ? sprintf( /* translators: %s: the app's name on the phone, which is the store's name. */ __( 'When it is ready, open the %s app (on your home screen or in your app list) and turn on order notifications there.', 'brikpanel' ), $app ) : sprintf( /* translators: %s: the app's name on the phone, which is the store's name. */ __( 'When it is ready, open the %s app (on your home screen or in your app list) and tap the phone button at the top to turn on order notifications.', 'brikpanel' ), $app ) ); ?></p>
		</div>
		<?php
		// Android, back in the browser tab once BrikPanel is installed: still the
		// app, never "in this browser" (the permission trap above). The browser
		// says it is installed, or it was opened as an app on this phone before
		// and this browser cannot tell whether it still is: then a link for a
		// person who removed it.
		?>
		<div class="brikpanel-push-card__view" data-bp-push-view="app" hidden>
			<p class="<?php echo esc_attr( $title ); ?>" dir="auto"><?php echo esc_html( sprintf( /* translators: %s: the app's name on the phone, which is the store's name. */ __( 'Open the %s app', 'brikpanel' ), $app ) ); ?></p>
			<p class="<?php echo esc_attr( $body ); ?>" dir="auto" data-bp-push-app-sure><?php echo esc_html( sprintf( /* translators: %s: the app's name on the phone, which is the store's name. */ __( '%s is installed on this phone. Open it from your home screen or app list and turn on order notifications there.', 'brikpanel' ), $app ) ); ?></p>
			<p class="<?php echo esc_attr( $body ); ?>" dir="auto" data-bp-push-app-unsure hidden><?php echo esc_html( sprintf( /* translators: %s: the app's name on the phone, which is the store's name. */ __( 'If %s is on your home screen, open it there and turn on order notifications.', 'brikpanel' ), $app ) ); ?></p>
		</div>
		<?php
		// The app that was on this phone is gone (the browser says so). Where the
		// browser can install it again (Chrome makes its install offer again once it
		// is gone), the app first, as on a phone that never had it (owner, 28 October
		// 2026: "uygulamayı yükle demesi gerekmez mi?"); elsewhere notifications in
		// the browser instead. brikpanel-push.js shows one of the two lines.
		?>
		<div class="brikpanel-push-card__view" data-bp-push-view="removed" hidden>
			<p class="<?php echo esc_attr( $title ); ?>" dir="auto"><?php echo esc_html( sprintf( /* translators: %s: the app's name on the phone, which is the store's name. */ __( 'The %s app was removed from this phone', 'brikpanel' ), $app ) ); ?></p>
			<p class="<?php echo esc_attr( $body ); ?>" dir="auto" data-bp-push-removed-again hidden><?php echo esc_html( sprintf( /* translators: %s: the app's name on the phone, which is the store's name. */ __( 'Add it to this phone again. Then open the %s app and turn on order notifications there.', 'brikpanel' ), $app ) ); ?></p>
			<p class="<?php echo esc_attr( $body ); ?>" dir="auto" data-bp-push-removed-here><?php esc_html_e( 'You can turn on order notifications in this browser instead.', 'brikpanel' ); ?></p>
		</div>
		<div class="brikpanel-push-card__view" data-bp-push-view="done" hidden>
			<p class="<?php echo esc_attr( $title ); ?>" dir="auto"><?php esc_html_e( 'Order notifications are on for this phone', 'brikpanel' ); ?></p>
			<p class="<?php echo esc_attr( $body ); ?>" dir="auto" data-bp-push-note></p>
			<div class="brikpanel-push-card__tip" data-bp-push-battery hidden>
				<p class="brikpanel-push-card__tip-title" dir="auto"><?php esc_html_e( 'Last step: instant notifications while the phone sleeps', 'brikpanel' ); ?></p>
				<p class="brikpanel-push-card__tip-body" dir="auto" data-bp-push-battery-text></p>
				<p class="brikpanel-push-card__tip-path" dir="auto" data-bp-push-battery-path></p>
			</div>
		</div>
		<div class="brikpanel-push-card__view" data-bp-push-view="blocked" hidden>
			<p class="<?php echo esc_attr( $title ); ?>" dir="auto"><?php esc_html_e( 'Notifications are blocked', 'brikpanel' ); ?></p>
			<p class="<?php echo esc_attr( $body ); ?>" dir="auto" data-bp-push-blocked></p>
		</div>
		<?php // A browser that cannot get notifications at all (Edge on Android, an app's own browser, an old iPhone): why, and what to use. ?>
		<div class="brikpanel-push-card__view" data-bp-push-view="cannot" hidden>
			<p class="<?php echo esc_attr( $title ); ?>" dir="auto"><?php esc_html_e( 'Get every new order on this phone', 'brikpanel' ); ?></p>
			<p class="<?php echo esc_attr( $body ); ?>" dir="auto" data-bp-push-cannot></p>
		</div>
	</div>
	<div class="brikpanel-push-card__actions">
		<button type="button" class="<?php echo esc_attr( $cta ); ?>" data-bp-push-on<?php echo $ios ? ' hidden' : ''; ?>><?php esc_html_e( 'Turn on order notifications', 'brikpanel' ); ?></button>
		<button type="button" class="<?php echo esc_attr( $cta ); ?>" data-bp-push-install hidden><?php esc_html_e( 'Install the app', 'brikpanel' ); ?></button>
		<button type="button" class="brikpanel-btn brikpanel-btn--link brikpanel-push-card__btn" data-bp-push-here hidden><?php esc_html_e( 'Turn them on in this browser instead', 'brikpanel' ); ?></button>
		<button type="button" class="brikpanel-btn brikpanel-btn--link brikpanel-push-card__btn" data-bp-push-removed hidden><?php esc_html_e( 'Removed the app? Turn them on in this browser', 'brikpanel' ); ?></button>
		<button type="button" class="brikpanel-btn brikpanel-btn--secondary brikpanel-push-card__btn" data-bp-push-card-test hidden><?php esc_html_e( 'Send a test', 'brikpanel' ); ?></button>
	</div>
	<?php
}

// -----------------------------------------------------------------------------
// The phone button in the top bar
// -----------------------------------------------------------------------------

/**
 * Whether this page view gets the phone button in the top bar (owner decision,
 * 27 October 2026: the dashboard card waits its turn in the one-ask-at-a-time
 * line, sometimes for weeks, and is gone once closed, so a phone always has
 * this way in). It stays on every phone (owner, 28 October 2026: "o buton hep
 * kalsın"): what a browser says about its notifications is not always true
 * (an app removed from the phone, a subscription an iPhone dropped), so the
 * button never guesses. With notifications on it carries a tick and its panel
 * says so, with a test; where the browser cannot get them, the panel says why.
 * Nothing in its panel hides it (owner, 28 October 2026: "hiçbir zaman
 * kaybolmasın o buton"; a "Don't show this button" link did, and its question
 * read like the X's): it leaves only where the owner hides it for everyone
 * under Top bar items (item "phone_app"). Its panel opens by itself where
 * notifications can still be turned on (brikpanel_push_menu_auto()).
 *
 * The phone test comes first: on a computer this costs one cookie read (the
 * cookie only exists on phones).
 *
 * @return bool
 */
function brikpanel_push_menu_wanted() {
	$phone = '' !== brikpanel_push_phone_cookie() || brikpanel_push_phone_view();
	if ( ! $phone || ! get_current_user_id() || ! current_user_can( 'manage_woocommerce' ) ) {
		return false;
	}
	if ( ! function_exists( 'brikpanel_pwa_for_current_user' ) || ! brikpanel_pwa_for_current_user() ) {
		return false;
	}
	if ( function_exists( 'brikpanel_topbar_item_is_visible' ) && ! brikpanel_topbar_item_is_visible( 'phone_app' ) ) {
		return false;
	}
	return brikpanel_push_available() && brikpanel_push_secure_request() && Brikpanel_Push_Store::site_matches() && ! Brikpanel_Push_Store::vapid_unreadable();
}

/**
 * Whether the top bar printed the phone button on this page view.
 *
 * @param bool|null $set True from brikpanel_push_menu_render(); null reads.
 * @return bool
 */
function brikpanel_push_menu_printed( $set = null ) {
	static $printed = false;
	if ( null !== $set ) {
		$printed = (bool) $set;
	}
	return $printed;
}

/**
 * The phone button and its panel, in the top bar's right group next to the
 * order bell (front-end/dashboard/brikpanel-dashboard-topbar.php). The panel
 * opens like the bell's (brikpanel-topbar.js) and holds the same steps as the
 * dashboard card under the app's own icon; it also opens by itself
 * (brikpanel_push_menu_auto()). On a phone it fills most of the screen, so its
 * X stays in sight: the content scrolls inside it, under the X.
 *
 * Its X first asks "Are you sure?" in the panel itself (owner, 27 October
 * 2026: "Kapatırsanız bildirim gelmeyebilir"), and closing it never takes the
 * button away (brikpanel_push_menu_wanted()).
 *
 * @return void
 */
function brikpanel_push_menu_render() {
	if ( ! brikpanel_push_menu_wanted() ) {
		return;
	}
	brikpanel_push_menu_printed( true );
	$label = __( 'Phone app', 'brikpanel' );
	// Notifications on: the arrow ("get it") turns into a tick (brikpanel-push.js keeps it current).
	$is_on = 'o' === brikpanel_push_phone_cookie();
	?>
	<div class="brikpanel-topbar-menu brikpanel-push-menu<?php echo $is_on ? ' is-on' : ''; ?>" data-topbar-menu="phone-app" data-bp-push-menu>
		<button type="button" class="brikpanel-topbar-icon-btn brikpanel-push-menu__btn" data-topbar-toggle="phone-app" aria-haspopup="dialog" aria-expanded="false" aria-label="<?php echo esc_attr( $label ); ?>">
			<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><rect x="6" y="2" width="12" height="20" rx="2.5"/><path class="brikpanel-push-menu__get" d="M12 7v7M9 11.5l3 3 3-3"/><path class="brikpanel-push-menu__tick" d="M8.75 12.25l2.25 2.25 4.25-4.5"/></svg>
		</button>
		<div class="brikpanel-topbar-dropdown brikpanel-push-menu__panel" role="dialog" aria-label="<?php echo esc_attr( $label ); ?>">
			<button type="button" class="brikpanel-push-menu__close" data-bp-push-menu-close aria-label="<?php esc_attr_e( 'Close', 'brikpanel' ); ?>">
				<svg width="14" height="14" viewBox="0 0 14 14" fill="none" aria-hidden="true" focusable="false"><path d="M1 1l12 12M13 1L1 13" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
			</button>
			<div class="brikpanel-push-menu__body">
				<div class="brikpanel-push-menu__app">
					<?php $app_icons = brikpanel_pwa_app_icons(); ?>
					<img class="brikpanel-push-menu__icon<?php echo $app_icons['store'] ? ' brikpanel-push-menu__icon--store' : ''; ?>" src="<?php echo esc_url( $app_icons['192'] ); ?>" width="44" height="44" alt="" decoding="async">
					<div class="brikpanel-push-menu__app-text">
						<p class="brikpanel-push-menu__name" dir="auto"><?php echo esc_html( brikpanel_pwa_app_short_name() ); ?></p>
						<p class="brikpanel-push-menu__sub" dir="auto"><?php esc_html_e( 'Works like an app on your phone.', 'brikpanel' ); ?></p>
					</div>
				</div>
				<div class="brikpanel-push-menu__steps" data-bp-push-steps>
					<?php brikpanel_push_offer_markup( brikpanel_push_phone_cookie(), 'menu' ); ?>
					<p class="brikpanel-push-menu__hint" dir="auto" data-bp-push-hint hidden><?php esc_html_e( 'You can open this any time with the phone button at the top.', 'brikpanel' ); ?></p>
				</div>
				<div class="brikpanel-push-menu__confirm" data-bp-push-confirm role="group" aria-labelledby="brikpanel-push-confirm-title" hidden>
					<p class="brikpanel-push-offer__title" id="brikpanel-push-confirm-title" dir="auto"><?php esc_html_e( 'Are you sure you want to close this?', 'brikpanel' ); ?></p>
					<p class="brikpanel-push-offer__body" dir="auto"><?php esc_html_e( 'If you close it, you may not get order notifications.', 'brikpanel' ); ?></p>
					<div class="brikpanel-push-menu__confirm-actions">
						<button type="button" class="brikpanel-btn brikpanel-btn--secondary brikpanel-push-menu__confirm-btn" data-bp-push-confirm-yes><?php esc_html_e( 'Close', 'brikpanel' ); ?></button>
						<button type="button" class="brikpanel-btn brikpanel-btn--primary brikpanel-push-menu__confirm-btn" data-bp-push-confirm-no><?php echo esc_html_x( 'Cancel', 'phone panel: keep it open', 'brikpanel' ); ?></button>
					</div>
				</div>
			</div>
		</div>
	</div>
	<?php
}

/**
 * Where this person closed the panel for good with its X: '' (nowhere yet),
 * 'tab' (in a browser tab: it still opens inside the installed app, where
 * notifications are turned on) or 'app' (inside the app: nowhere any more).
 * Per person and site.
 *
 * @return string
 */
function brikpanel_push_auto_off() {
	$off = get_user_option( 'brikpanel_push_auto_off', get_current_user_id() );
	return in_array( $off, array( 'tab', 'app' ), true ) ? $off : '';
}

/**
 * Whether the button on this page view still has something to ask for: not
 * on a phone whose notifications are on, nor where the browser cannot get
 * them (its panel only says why). Those keep the button, never the panel
 * opening by itself, and their asks show as on any page.
 *
 * @return bool
 */
function brikpanel_push_menu_offers() {
	return brikpanel_push_menu_printed() && ! in_array( brikpanel_push_phone_cookie(), array( 'o', 'u' ), true );
}

/**
 * Before any ask prints (asks rule 8): on a page view where the panel will
 * open by itself everywhere, no ask shows next to it. Where it was closed in
 * a tab, only the script knows whether this is the app (Android's app shares
 * the browser's cookies), so the asks keep their place and the panel opens
 * only on a page view with none of them (brikpanel_push_menu_auto()).
 *
 * @param bool $taken Taken already.
 * @return bool
 */
function brikpanel_push_auto_takes_screen( $taken ) {
	if ( $taken || ! brikpanel_push_menu_offers() || '' !== brikpanel_push_auto_off() ) {
		return $taken;
	}
	// The welcome tour comes first (asks rule 2).
	return ! ( function_exists( 'brikpanel_should_show_welcome' ) && brikpanel_should_show_welcome() );
}
add_filter( 'brikpanel_asks_screen_taken', 'brikpanel_push_auto_takes_screen' );

/**
 * Whether the panel may open by itself on this page view: the screens and
 * people asks are for, no notice box or "saved" message on screen, no welcome
 * tour, no ask drawn (includes/brikpanel-asks.php, rules 1, 2, 6 and 7).
 *
 * @return bool
 */
function brikpanel_push_auto_page_free() {
	if ( ! function_exists( 'brikpanel_asks_in_scope' ) || ! brikpanel_asks_in_scope() || brikpanel_asks_hold() || brikpanel_asks_url_hold() ) {
		return false;
	}
	if ( function_exists( 'brikpanel_should_show_welcome' ) && brikpanel_should_show_welcome() ) {
		return false;
	}
	return ! brikpanel_asks_on_page();
}

/**
 * On a phone the panel opens by itself on every page, until notifications are
 * on there or the person presses its X and confirms (owner, 27 October 2026:
 * "Çarpı işaretine tıklayana kadar ... devamlı olarak gözükmeli. Zira
 * header'daki buton çok da net bir buton değil."). A tap outside, Escape or
 * the button put it away for that page only. Decided in the footer, after the
 * notices, the welcome tour and the BrikMentor announcement have printed; the
 * script opens it only on a phone without notifications ('all': anywhere,
 * 'app': only inside the installed app, after an X in a browser tab).
 *
 * @return void
 */
function brikpanel_push_menu_auto() {
	if ( ! brikpanel_push_menu_offers() ) {
		return;
	}
	$off = brikpanel_push_auto_off();
	if ( 'app' === $off || ! brikpanel_push_auto_page_free() ) {
		return;
	}
	wp_add_inline_script( 'brikpanel_push', 'window.brikpanelPushAuto = ' . wp_json_encode( 'tab' === $off ? 'app' : 'all' ) . ';', 'before' );
}
add_action( 'admin_footer', 'brikpanel_push_menu_auto', 100 );

/**
 * Whether the panel was in front of the person, opened by itself, when they
 * closed it for good: then the other asks wait their days after it (asks
 * rule 3), as after any window that was put in front of them.
 *
 * @return bool
 */
function brikpanel_push_ajax_was_shown() {
	return ! empty( $_POST['shown'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- brikpanel_push_ajax_guard() ran.
}

/**
 * The panel's X, confirmed: it never opens by itself again where it was
 * closed (a browser tab, or the installed app and with it everywhere). The
 * button stays, and the dashboard card never follows.
 *
 * @return void
 */
function brikpanel_push_ajax_auto_off() {
	brikpanel_push_ajax_guard();
	$where = isset( $_POST['where'] ) && 'app' === sanitize_key( wp_unslash( $_POST['where'] ) ) ? 'app' : 'tab'; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- brikpanel_push_ajax_guard() ran.
	$off   = 'app' === $where || 'app' === brikpanel_push_auto_off() ? 'app' : 'tab';
	update_user_option( get_current_user_id(), 'brikpanel_push_auto_off', $off );
	if ( function_exists( 'brikpanel_ask_closed' ) ) {
		brikpanel_ask_closed( 'push', brikpanel_push_ajax_was_shown() );
	}
	wp_send_json_success( array( 'off' => $off ) );
}
add_action( 'wp_ajax_brikpanel_push_auto_off', 'brikpanel_push_ajax_auto_off' );

/**
 * A phone whose notifications stopped (an iPhone can drop them, an update can
 * change the keys): brikpanel-push.js turns them back on by itself where the
 * browser allows it, and shows this line where a tap is needed. Not an ask:
 * it never shows next to one.
 *
 * @return void
 */
function brikpanel_push_repair_render() {
	if ( ! brikpanel_push_available() ) {
		return;
	}
	?>
	<div class="brikpanel-push-repair" data-bp-push-repair hidden>
		<span dir="auto"><?php esc_html_e( 'Order notifications stopped on this phone.', 'brikpanel' ); ?></span>
		<button type="button" class="brikpanel-btn brikpanel-btn--primary" data-bp-push-repair-on><?php esc_html_e( 'Turn them back on', 'brikpanel' ); ?></button>
	</div>
	<?php
}
add_action( 'brikpanel_dashboard_before_sections', 'brikpanel_push_repair_render', 4 );

// -----------------------------------------------------------------------------
// Which phones can turn notifications on (for the card's turn)
// -----------------------------------------------------------------------------

/**
 * Remembers, per person and site, what their phone said it can do, so the
 * card's turn can be decided on any page. Written at most once a day, or when
 * the answer changes.
 *
 * @return void
 */
function brikpanel_push_stamp_phone() {
	if ( wp_doing_ajax() || wp_doing_cron() || ! get_current_user_id() ) {
		return;
	}
	$state = brikpanel_push_phone_cookie();
	if ( '' === $state ) {
		return;
	}
	$now   = function_exists( 'brikpanel_asks_now' ) ? brikpanel_asks_now() : time();
	$stamp = get_user_option( 'brikpanel_push_phone', get_current_user_id() );
	if ( is_array( $stamp ) && isset( $stamp['s'], $stamp['t'] ) && $state === $stamp['s'] && (int) $stamp['t'] > $now - DAY_IN_SECONDS ) {
		return;
	}
	update_user_option(
		get_current_user_id(),
		'brikpanel_push_phone',
		array(
			's' => $state,
			't' => $now,
		)
	);
}
add_action( 'admin_init', 'brikpanel_push_stamp_phone' );

// -----------------------------------------------------------------------------
// The script
// -----------------------------------------------------------------------------

/**
 * brikpanel-push.js on the dashboard, on Settings > Notifications, and on any
 * admin page a phone opens (the phone's cookie or browser says it is one, which
 * is also when the top bar has the phone button): it keeps the phone's
 * notifications alive and tells the server what the phone can do.
 *
 * @return void
 */
function brikpanel_push_enqueue() {
	if ( ! function_exists( 'brikpanel_pwa_for_current_user' ) || ! brikpanel_pwa_for_current_user() ) {
		return;
	}
	$settings  = brikpanel_push_on_settings();
	$dashboard = brikpanel_push_on_dashboard();
	if ( ! $settings && ! brikpanel_push_menu_wanted() && ( ! brikpanel_push_available() || ( ! $dashboard && '' === brikpanel_push_phone_cookie() ) ) ) {
		return;
	}
	$js  = 'front-end/push/brikpanel-push.js';
	$css = 'front-end/push/brikpanel-push.css';
	wp_enqueue_style( 'brikpanel_push', BRIKPANEL_URL . $css, brikpanel_narrow_dep( 'ui', 'style' ), @filemtime( BRIKPANEL_PATH . $css ) ?: BRIKPANEL_VERSION ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a missing file falls back to the version.
	wp_enqueue_script( 'brikpanel_push', BRIKPANEL_URL . $js, brikpanel_narrow_dep( 'snack' ), @filemtime( BRIKPANEL_PATH . $js ) ?: BRIKPANEL_VERSION, true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a missing file falls back to the version.
	if ( function_exists( 'brikpanel_narrow_dep' ) ) {
		foreach ( brikpanel_narrow_dep( 'snack', 'style' ) as $handle ) {
			wp_enqueue_style( $handle );
		}
	}
	$can = brikpanel_push_available() && Brikpanel_Push_Store::site_matches() && ! Brikpanel_Push_Store::vapid_unreadable();
	wp_localize_script(
		'brikpanel_push',
		'brikpanelPush',
		array(
			'ajax'     => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( 'brikpanel_push' ),
			'askNonce' => wp_create_nonce( 'brikpanel_ask_close' ),
			'blog'     => get_current_blog_id(),
			'key'      => $can ? Brikpanel_Push_Store::vapid_public() : '',
			'worker'   => brikpanel_pwa_worker_url(),
			'scope'    => brikpanel_pwa_admin_path(),
			'shared'   => brikpanel_pwa_shared_worker(),
			'on'       => $can,
			'secure'   => is_ssl(),
			// Where the phone button's panel was closed for good (brikpanel_push_auto_off()).
			'off'      => brikpanel_push_auto_off(),
			'page'     => $settings ? 'settings' : ( $dashboard ? 'dashboard' : 'other' ),
			'i18n'     => brikpanel_push_js_strings(),
		)
	);
}
add_action( 'admin_enqueue_scripts', 'brikpanel_push_enqueue' );

/**
 * Every sentence the script shows.
 *
 * @return array<string,string>
 */
function brikpanel_push_js_strings() {
	$app = brikpanel_pwa_app_short_name();
	return array(
		'turning'     => __( 'Turning on…', 'brikpanel' ),
		'on'          => __( 'Order notifications are on for this device.', 'brikpanel' ),
		'onPhone'     => __( 'You will get every new order here, also when the phone is locked.', 'brikpanel' ),
		'again'       => __( 'Tap again to allow notifications.', 'brikpanel' ),
		/* translators: %s: the browser's error message. */
		'failed'      => __( 'Notifications could not be turned on: %s', 'brikpanel' ),
		'sending'     => __( 'Sending…', 'brikpanel' ),
		'sent'        => __( 'Test sent. It should arrive in a few seconds.', 'brikpanel' ),
		/* translators: %s: error code and reason from the push service. */
		'refused'     => __( 'The push service refused the test (%s).', 'brikpanel' ),
		'wait'        => __( 'Wait a few seconds before the next test.', 'brikpanel' ),
		'removed'     => __( 'Device removed.', 'brikpanel' ),
		'error'       => __( 'Something went wrong. Reload the page and try again.', 'brikpanel' ),
		'confirmKeys' => __( 'Create new keys? Every phone turns notifications back on by itself the next time BrikPanel is opened on it.', 'brikpanel' ),
		'blocked'     => __( 'Notifications are blocked for this site in this browser. Allow them in the browser\'s site settings, then try again.', 'brikpanel' ),
		/* translators: %1$s: the app's name on the phone, which is the store's name. */
		'blockedIos'  => sprintf( __( 'Notifications are off for %1$s. Turn them on in the iPhone Settings, Notifications, %1$s. If %1$s is not listed, delete it from the Home Screen and add it again.', 'brikpanel' ), $app ),
		/* translators: %s: the app's name on the phone, which is the store's name. */
		'iosTab'      => sprintf( __( 'An iPhone shows notifications only for apps on the Home Screen. In Safari, tap Share, then "Add to Home Screen", open %s from there and turn notifications on.', 'brikpanel' ), $app ),
		'iosOld'      => __( 'Notifications need iOS 16.4 or later. Update the iPhone first.', 'brikpanel' ),
		'insecure'    => __( 'Notifications need the store admin on https.', 'brikpanel' ),
		'edgeAndroid' => __( 'This browser cannot get notifications. Use Chrome on Android.', 'brikpanel' ),
		'unsupported' => __( 'This browser cannot get notifications.', 'brikpanel' ),
		'already'     => __( 'Notifications are on for this device.', 'brikpanel' ),
		'installing'  => __( 'Installing…', 'brikpanel' ),
		/* translators: %s: browser name (Chrome or Samsung Internet), twice. */
		'battery'     => __( 'To save battery, Android holds back notifications from %s while the phone sleeps. Let %s run in the background:', 'brikpanel' ),
		/* translators: %s: browser name. The menu path in Android's own Settings app, in the phone's language. */
		'batteryPath' => __( 'Settings > Apps > %s > Battery > Unrestricted', 'brikpanel' ),
		'firefoxTitle' => __( 'In Firefox, notifications can wait while the phone sleeps', 'brikpanel' ),
		'firefoxBody'  => __( 'Firefox holds them back until the phone wakes up, and no setting on the phone changes that. For instant order notifications, open BrikPanel in Chrome and turn them on there.', 'brikpanel' ),
		// Product names, never translated.
		'chrome'      => 'Chrome', // i18n-ignore: product name
		'samsung'     => 'Samsung Internet', // i18n-ignore: product name
		'brave'       => 'Brave', // i18n-ignore: product name
		'firefox'     => 'Firefox', // i18n-ignore: product name
		'opera'       => 'Opera', // i18n-ignore: product name
	);
}

// -----------------------------------------------------------------------------
// AJAX
// -----------------------------------------------------------------------------

/**
 * Nonce and capability on every call.
 *
 * @return void
 */
function brikpanel_push_ajax_guard() {
	if ( ! check_ajax_referer( 'brikpanel_push', 'nonce', false ) ) {
		wp_send_json_error( array( 'reason' => 'nonce' ), 403 );
	}
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_send_json_error( array( 'reason' => 'forbidden' ), 403 );
	}
}

/**
 * One of the person's own devices, or the call ends.
 *
 * @return array
 */
function brikpanel_push_ajax_own_device() {
	$id  = isset( $_POST['id'] ) ? absint( wp_unslash( $_POST['id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- brikpanel_push_ajax_guard() ran.
	$row = $id ? Brikpanel_Push_Store::get( $id ) : null;
	if ( ! $row || (int) $row['user_id'] !== get_current_user_id() || 'removed' === $row['status'] ) {
		wp_send_json_error( array( 'reason' => 'not_found' ), 404 );
	}
	return $row;
}

/**
 * Turn on (card, settings, repair) or check in (sync) a subscription.
 *
 * @return void
 */
function brikpanel_push_ajax_save() {
	brikpanel_push_ajax_guard();
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- brikpanel_push_ajax_guard() ran.
	$ctx      = isset( $_POST['ctx'] ) ? sanitize_key( wp_unslash( $_POST['ctx'] ) ) : 'settings';
	// Kept byte for byte (sanitize_text_field() would drop the %2b in an Edge
	// endpoint): printable ASCII only, then the push service allow-list.
	$endpoint = isset( $_POST['endpoint'] ) ? (string) wp_unslash( $_POST['endpoint'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated below, never printed.
	if ( ! preg_match( '#^https://[A-Za-z0-9.\-]+(?::443)?/[\x21-\x7E]*$#', $endpoint ) ) {
		$endpoint = '';
	}
	$p256dh   = isset( $_POST['p256dh'] ) ? sanitize_text_field( wp_unslash( $_POST['p256dh'] ) ) : '';
	$auth     = isset( $_POST['auth'] ) ? sanitize_text_field( wp_unslash( $_POST['auth'] ) ) : '';
	$replaces = isset( $_POST['replaces'] ) ? sanitize_key( wp_unslash( $_POST['replaces'] ) ) : '';
	$meta     = array(
		'os'         => isset( $_POST['os'] ) ? sanitize_key( wp_unslash( $_POST['os'] ) ) : '',
		'browser'    => isset( $_POST['browser'] ) ? sanitize_key( wp_unslash( $_POST['browser'] ) ) : '',
		'standalone' => ! empty( $_POST['standalone'] ),
		'phone'      => ! empty( $_POST['phone'] ),
	);
	// phpcs:enable
	// 'card' is the phone offer in front of the person (the dashboard card, or the
	// button's panel that opened by itself): turning on closes its turn. 'menu' is
	// the button opened by hand: the asks line drops the offer once the phone says "on".
	if ( ! in_array( $ctx, array( 'card', 'menu', 'settings', 'repair', 'sync' ), true ) ) {
		$ctx = 'settings';
	}
	if ( ! brikpanel_push_available() || ! Brikpanel_Push_Store::site_matches() || Brikpanel_Push_Store::vapid_unreadable() ) {
		wp_send_json_error( array( 'reason' => 'off' ), 409 );
	}
	$raw_key  = Brikpanel_WebPush::b64url_decode( $p256dh );
	$raw_auth = Brikpanel_WebPush::b64url_decode( $auth );
	if ( ! Brikpanel_Push_Sender::allowed_endpoint( $endpoint ) || ! Brikpanel_WebPush::valid_public( $raw_key ) || 16 !== strlen( $raw_auth ) ) {
		wp_send_json_error( array( 'reason' => 'invalid' ), 400 );
	}
	$row = Brikpanel_Push_Store::save_device(
		get_current_user_id(),
		array(
			'endpoint' => $endpoint,
			'p256dh'   => Brikpanel_WebPush::b64url_encode( $raw_key ),
			'auth'     => Brikpanel_WebPush::b64url_encode( $raw_auth ),
		),
		$meta,
		'sync' !== $ctx
	);
	if ( is_wp_error( $row ) ) {
		wp_send_json_error( array( 'reason' => 'limit' === $row->get_error_message() ? 'limit' : 'save' ), 'limit' === $row->get_error_message() ? 409 : 500 );
	}
	if ( 'active' !== $row['status'] ) {
		// A check-in from a device that was removed or stopped: the phone puts
		// its subscription away, or makes a new one.
		wp_send_json_success( array( 'status' => $row['status'] ) );
	}
	// A new subscription that replaces this browser's old one.
	if ( '' !== $replaces && $replaces !== $row['endpoint_hash'] ) {
		$old = Brikpanel_Push_Store::get_by_hash( $replaces );
		if ( $old && (int) $old['user_id'] === get_current_user_id() && 'removed' !== $old['status'] ) {
			Brikpanel_Push_Store::mark_removed( (int) $old['id'] );
		}
	}
	if ( 'sync' === $ctx ) {
		Brikpanel_Push_Store::mark_seen( (int) $row['id'] );
	}
	if ( 'card' === $ctx && function_exists( 'brikpanel_ask_closed' ) ) {
		brikpanel_ask_closed( 'push' );
	}
	brikpanel_push_set_device_cookie( (int) $row['id'], get_current_user_id() );
	wp_send_json_success(
		array(
			'status' => 'active',
			'id'     => (int) $row['id'],
			'hash'   => $row['endpoint_hash'],
			'html'   => 'settings' === $ctx ? brikpanel_push_devices_html( get_current_user_id() ) : '',
		)
	);
}
add_action( 'wp_ajax_brikpanel_push_save', 'brikpanel_push_ajax_save' );

/** @return void */
function brikpanel_push_ajax_remove() {
	brikpanel_push_ajax_guard();
	$row = brikpanel_push_ajax_own_device();
	Brikpanel_Push_Store::mark_removed( (int) $row['id'] );
	wp_send_json_success( array( 'html' => brikpanel_push_devices_html( get_current_user_id() ) ) );
}
add_action( 'wp_ajax_brikpanel_push_remove', 'brikpanel_push_ajax_remove' );

/** @return void */
function brikpanel_push_ajax_test() {
	brikpanel_push_ajax_guard();
	$row = brikpanel_push_ajax_own_device();
	if ( 'active' !== $row['status'] || ! brikpanel_push_available() || ! Brikpanel_Push_Store::site_matches() ) {
		wp_send_json_error( array( 'reason' => 'off' ), 409 );
	}
	// One test per person every 10 seconds.
	$lock = 'brikpanel_push_test_' . get_current_user_id();
	if ( get_transient( $lock ) ) {
		wp_send_json_error( array( 'reason' => 'wait' ), 429 );
	}
	set_transient( $lock, 1, 10 );
	$result = Brikpanel_Push_Sender::send_test( $row );
	wp_send_json_success(
		array(
			'ok'     => $result['ok'],
			'code'   => $result['code'],
			'reason' => $result['reason'],
			'html'   => brikpanel_push_devices_html( get_current_user_id() ),
		)
	);
}
add_action( 'wp_ajax_brikpanel_push_test', 'brikpanel_push_ajax_test' );

/** @return void */
function brikpanel_push_ajax_devices() {
	brikpanel_push_ajax_guard();
	wp_send_json_success( array( 'html' => brikpanel_push_devices_html( get_current_user_id() ) ) );
}
add_action( 'wp_ajax_brikpanel_push_devices', 'brikpanel_push_ajax_devices' );

/**
 * Store-wide fixes, for site administrators: new keys after the old ones
 * became unreadable, or "the store really moved here".
 *
 * @return void
 */
function brikpanel_push_ajax_fix() {
	brikpanel_push_ajax_guard();
	if ( ! brikpanel_push_is_site_admin() ) {
		wp_send_json_error( array( 'reason' => 'forbidden' ), 403 );
	}
	$op = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( $_POST['op'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- brikpanel_push_ajax_guard() ran.
	if ( 'new_keys' === $op ) {
		if ( ! Brikpanel_Push_Store::vapid_unreadable() ) {
			wp_send_json_error( array( 'reason' => 'not_needed' ), 409 );
		}
		$ok = Brikpanel_Push_Store::reset_vapid();
		delete_option( Brikpanel_Push_Sender::OPT_KEYS_ALERT );
		$ok ? wp_send_json_success() : wp_send_json_error( array( 'reason' => 'keys' ), 500 );
	}
	if ( 'resume_site' === $op ) {
		Brikpanel_Push_Store::remember_site();
		wp_send_json_success();
	}
	wp_send_json_error( array( 'reason' => 'unknown' ), 400 );
}
add_action( 'wp_ajax_brikpanel_push_fix', 'brikpanel_push_ajax_fix' );
