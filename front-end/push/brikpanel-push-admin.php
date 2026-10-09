<?php
/**
 * BrikPanel - phone notifications: the screens.
 *
 * - WooCommerce > Settings > BrikPanel > Notifications: the "Phone
 *   notifications" switch, then "Your devices": each person sees only their
 *   own devices, can send a test or remove one, and problems on the server
 *   side (no https, outside requests blocked, a copy of the store) are listed
 *   first.
 * - The dashboard on a phone: one "Turn on order notifications" card, which
 *   takes its turn in the one-ask-at-a-time line (includes/brikpanel-asks.php,
 *   id "push"), and a hidden line for a phone whose notifications stopped.
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
			'desc'    => __( 'Send every new order (processing, completed or on hold) to the phones and computers where notifications are turned on. A notification shows the order number, the total and the number of items, never the customer.', 'brikpanel' ),
			'default' => 'yes',
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
	if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON && ! function_exists( 'fastcgi_finish_request' ) && ! function_exists( 'litespeed_finish_request' ) ) {
		$lines[] = array(
			'tone' => 'info',
			'text' => __( 'On this server a notification can arrive a minute or more after the order: it is sent by a background job, and WordPress runs those only when its cron is called (DISABLE_WP_CRON).', 'brikpanel' ),
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
		return '<p class="brikpanel-push-empty" dir="auto">' . esc_html__( 'No devices yet. Open the store admin on your phone and turn notifications on from the card on the dashboard, or turn them on for this device below.', 'brikpanel' ) . '</p>';
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
	if ( ! function_exists( 'brikpanel_ask_allows' ) || ! brikpanel_ask_allows( 'push' ) ) {
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
		<div class="brikpanel-ea-card__text">
			<div class="brikpanel-push-card__view" data-bp-push-view="ask"<?php echo 'i' === $cookie ? ' hidden' : ''; ?>>
				<p class="brikpanel-ea-card__title" dir="auto"><?php esc_html_e( 'Get every new order on this phone', 'brikpanel' ); ?></p>
				<p class="brikpanel-ea-card__body" dir="auto"><?php esc_html_e( 'A notification with the order number and total, also when the phone is locked. The customer is never named.', 'brikpanel' ); ?></p>
			</div>
			<div class="brikpanel-push-card__view" data-bp-push-view="ios"<?php echo 'i' === $cookie ? '' : ' hidden'; ?>>
				<p class="brikpanel-ea-card__title" dir="auto"><?php esc_html_e( 'Get every new order on this iPhone', 'brikpanel' ); ?></p>
				<p class="brikpanel-ea-card__body" dir="auto"><?php esc_html_e( 'An iPhone shows notifications only for apps on the Home Screen. Add BrikPanel there first:', 'brikpanel' ); ?></p>
				<ol class="brikpanel-push-card__steps">
					<li dir="auto"><?php esc_html_e( 'Tap the Share button in Safari.', 'brikpanel' ); ?>
						<svg class="brikpanel-push-card__share" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M12 15V3"/><path d="M8 7l4-4 4 4"/><path d="M7 10H5.5A1.5 1.5 0 0 0 4 11.5v8A1.5 1.5 0 0 0 5.5 21h13a1.5 1.5 0 0 0 1.5-1.5v-8a1.5 1.5 0 0 0-1.5-1.5H17"/></svg>
					</li>
					<li dir="auto"><?php esc_html_e( 'Choose "Add to Home Screen".', 'brikpanel' ); ?></li>
					<li dir="auto"><?php esc_html_e( 'Open BrikPanel from the Home Screen and turn notifications on there.', 'brikpanel' ); ?></li>
				</ol>
			</div>
			<div class="brikpanel-push-card__view" data-bp-push-view="done" hidden>
				<p class="brikpanel-ea-card__title" dir="auto"><?php esc_html_e( 'Order notifications are on for this phone', 'brikpanel' ); ?></p>
				<p class="brikpanel-ea-card__body" dir="auto" data-bp-push-note></p>
			</div>
			<div class="brikpanel-push-card__view" data-bp-push-view="blocked" hidden>
				<p class="brikpanel-ea-card__title" dir="auto"><?php esc_html_e( 'Notifications are blocked', 'brikpanel' ); ?></p>
				<p class="brikpanel-ea-card__body" dir="auto" data-bp-push-blocked></p>
			</div>
		</div>
		<div class="brikpanel-push-card__actions">
			<button type="button" class="brikpanel-ea-card__cta" data-bp-push-on<?php echo 'i' === $cookie ? ' hidden' : ''; ?>><?php esc_html_e( 'Turn on order notifications', 'brikpanel' ); ?></button>
			<button type="button" class="brikpanel-btn brikpanel-btn--secondary brikpanel-push-card__btn" data-bp-push-card-test hidden><?php esc_html_e( 'Send a test', 'brikpanel' ); ?></button>
			<button type="button" class="brikpanel-btn brikpanel-btn--secondary brikpanel-push-card__btn" data-bp-push-install hidden><?php esc_html_e( 'Install BrikPanel', 'brikpanel' ); ?></button>
		</div>
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
 * admin page a phone opens (the phone's cookie says it is one): it keeps the
 * phone's notifications alive and tells the server what the phone can do.
 *
 * @return void
 */
function brikpanel_push_enqueue() {
	if ( ! function_exists( 'brikpanel_pwa_for_current_user' ) || ! brikpanel_pwa_for_current_user() ) {
		return;
	}
	$settings  = brikpanel_push_on_settings();
	$dashboard = brikpanel_push_on_dashboard();
	if ( ! $settings && ( ! brikpanel_push_available() || ( ! $dashboard && '' === brikpanel_push_phone_cookie() ) ) ) {
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
		'blockedIos'  => __( 'Notifications are off for BrikPanel. Turn them on in the iPhone Settings, Notifications, BrikPanel. If BrikPanel is not listed, delete it from the Home Screen and add it again.', 'brikpanel' ),
		'iosTab'      => __( 'An iPhone shows notifications only for apps on the Home Screen. In Safari, tap Share, then "Add to Home Screen", open BrikPanel from there and turn notifications on.', 'brikpanel' ),
		'iosOld'      => __( 'Notifications need iOS 16.4 or later. Update the iPhone first.', 'brikpanel' ),
		'insecure'    => __( 'Notifications need the store admin on https.', 'brikpanel' ),
		'edgeAndroid' => __( 'This browser cannot get notifications. Use Chrome on Android.', 'brikpanel' ),
		'unsupported' => __( 'This browser cannot get notifications.', 'brikpanel' ),
		'already'     => __( 'Notifications are on for this device.', 'brikpanel' ),
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
	if ( ! in_array( $ctx, array( 'card', 'settings', 'repair', 'sync' ), true ) ) {
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
