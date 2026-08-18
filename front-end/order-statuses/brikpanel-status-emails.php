<?php
/**
 * Status-change emails.
 *
 * Sends a WooCommerce-styled email whenever an order moves into a custom order
 * status, with a per-status configuration the merchant controls: on/off, who
 * receives it (the customer, the store admin, extra addresses), and the subject,
 * heading and body — the body understanding a small set of {placeholders}.
 *
 * This mirrors what dedicated custom-status plugins (WPFactory's "Additional
 * Custom Order Status", Tyche's) offer, so a merchant migrating to BrikPanel
 * keeps the "email on status change" behaviour without a second plugin.
 *
 * The email is rendered through WooCommerce's own email header/footer templates
 * and inline-styled, so it inherits the store's email branding (header image,
 * base colour, footer text) exactly like every native WooCommerce email.
 *
 * Loaded globally (front + admin) from brikpanel.php: status changes happen on
 * the front-end checkout, in cron, over the REST API and in wp-admin, so the
 * sender must be registered everywhere. Only the settings-screen UI is gated to
 * admin requests at the bottom of this file. Works the same for orders holding
 * simple or variable products — the status lives on the order, not the product.
 *
 * @package BrikPanel
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const BRIKPANEL_STATUS_EMAILS_OPTION = 'brikpanel_status_emails';

/**
 * Default subject/heading/body used when a status email is enabled but a field
 * was left blank, so an email always has meaningful content out of the box.
 *
 * @return array{subject:string,heading:string,content:string}
 */
function brikpanel_status_email_defaults() {
	return [
		'subject' => __( 'Your order {order_number} is now {order_status}', 'brikpanel' ),
		'heading' => __( 'Your order status has changed', 'brikpanel' ),
		'content' => __(
			"Hi {customer_first_name},\n\n"
			. "This is a quick update on your order {order_number} placed on {order_date}. "
			. "Its status is now: {order_status}.\n\n"
			. "{order_items}\n\n"
			. "Order total: {order_total}\n\n"
			. "You can view your order here: {order_view_url}\n\n"
			. "Thank you for shopping with {site_title}.",
			'brikpanel'
		),
	];
}

/**
 * Read and normalise the per-status email configuration.
 *
 * Guarantees a predictable shape for every stored slug: booleans are real
 * booleans, extra recipients are a clean comma-separated list of valid emails,
 * and the text fields are strings (blank allowed — the sender falls back to the
 * defaults). Cached per-request; the cache is flushed on option change.
 *
 * @param bool $reset Internal — flush the per-request cache.
 * @return array<string,array{enabled:bool,customer:bool,admin:bool,recipients:string,subject:string,heading:string,content:string}>
 */
function brikpanel_get_status_emails( $reset = false ) {
	static $cache = null;
	if ( $reset ) {
		$cache = null;
		return [];
	}
	if ( null !== $cache ) {
		return $cache;
	}

	$raw = get_option( BRIKPANEL_STATUS_EMAILS_OPTION, [] );
	$out = [];

	if ( is_array( $raw ) ) {
		foreach ( $raw as $slug => $cfg ) {
			$slug = sanitize_key( (string) $slug );
			if ( '' === $slug || ! is_array( $cfg ) ) {
				continue;
			}
			$out[ $slug ] = [
				'enabled'    => ! empty( $cfg['enabled'] ),
				'customer'   => ! empty( $cfg['customer'] ),
				'admin'      => ! empty( $cfg['admin'] ),
				'recipients' => brikpanel_status_email_clean_recipients( isset( $cfg['recipients'] ) ? (string) $cfg['recipients'] : '' ),
				'subject'    => isset( $cfg['subject'] ) ? (string) $cfg['subject'] : '',
				'heading'    => isset( $cfg['heading'] ) ? (string) $cfg['heading'] : '',
				'content'    => isset( $cfg['content'] ) ? (string) $cfg['content'] : '',
			];
		}
	}

	$cache = $out;
	return $cache;
}

$brikpanel_status_emails_reset = static function () {
	brikpanel_get_status_emails( true );
};
add_action( 'add_option_' . BRIKPANEL_STATUS_EMAILS_OPTION, $brikpanel_status_emails_reset );
add_action( 'update_option_' . BRIKPANEL_STATUS_EMAILS_OPTION, $brikpanel_status_emails_reset );
add_action( 'delete_option_' . BRIKPANEL_STATUS_EMAILS_OPTION, $brikpanel_status_emails_reset );
unset( $brikpanel_status_emails_reset );

/**
 * Reduce a free-text recipients field to a clean, comma-separated list of valid
 * email addresses (deduplicated). Anything that is not a valid email is dropped.
 *
 * @param string $value Raw field value (comma / semicolon / newline separated).
 * @return string
 */
function brikpanel_status_email_clean_recipients( $value ) {
	$parts = preg_split( '/[,;\s]+/', (string) $value );
	$clean = [];
	foreach ( (array) $parts as $part ) {
		$part = trim( $part );
		if ( '' === $part ) {
			continue;
		}
		if ( is_email( $part ) ) {
			$clean[ strtolower( $part ) ] = sanitize_email( $part );
		}
	}
	return implode( ', ', array_values( $clean ) );
}

// =============================================================================
// SENDING — runs on every request where an order status can change.
// =============================================================================

/**
 * The full set of {placeholders} the subject/heading/body understand, resolved
 * for a given order.
 *
 * @param WC_Order $order Order the email is about.
 * @return array<string,string> token => replacement (may contain HTML).
 */
function brikpanel_status_email_tokens( $order ) {
	$items = [];
	foreach ( $order->get_items() as $item ) {
		$name = $item->get_name();
		$qty  = method_exists( $item, 'get_quantity' ) ? (int) $item->get_quantity() : 1;
		$items[] = esc_html( $name ) . ' &times; ' . $qty;
	}
	$items_html = $items ? '<ul style="margin:0 0 0 1em;padding:0;">' . '<li>' . implode( '</li><li>', $items ) . '</li></ul>' : '';

	// Numbers, dates, totals and phone numbers are isolated: their separators
	// are bidirectionally neutral, so a right-to-left email reorders the pieces
	// and a total sent as 15.402,50 is read as 50,15.402. WooCommerce guards
	// against this with <bdi>, which wp_kses_post() strips (core's allowlist has
	// bdo but not bdi), so the plain-text isolate characters put it back.
	$tokens = [
		'{order_number}'        => brikpanel_bidi_isolate_ltr( $order->get_order_number() ),
		'{order_id}'            => brikpanel_bidi_isolate_ltr( (string) $order->get_id() ),
		'{order_date}'          => $order->get_date_created() ? brikpanel_bidi_isolate_ltr( wc_format_datetime( $order->get_date_created() ) ) : '',
		'{order_total}'         => brikpanel_money_text_from_html( wp_kses_post( $order->get_formatted_order_total() ) ),
		'{order_status}'        => wc_get_order_status_name( $order->get_status() ),
		'{order_items}'         => $items_html,
		'{order_view_url}'      => esc_url( $order->get_view_order_url() ),
		'{customer_first_name}' => $order->get_billing_first_name(),
		'{customer_last_name}'  => $order->get_billing_last_name(),
		'{customer_full_name}'  => $order->get_formatted_billing_full_name(),
		'{billing_email}'       => $order->get_billing_email(),
		'{billing_phone}'       => brikpanel_bidi_isolate_ltr( $order->get_billing_phone() ),
		'{site_title}'          => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
		'{site_url}'            => home_url(),
	];

	/**
	 * Filter the placeholder map for status-change emails.
	 *
	 * @param array    $tokens token => replacement.
	 * @param WC_Order $order  Order.
	 */
	return apply_filters( 'brikpanel_status_email_tokens', $tokens, $order );
}

/**
 * Replace {placeholders} in a string. For subjects (plain text) the result is
 * stripped of tags and decoded; for the body the HTML replacements are kept.
 *
 * @param string   $text  Template text.
 * @param WC_Order $order Order.
 * @param bool     $html  True to keep HTML replacements (body), false for plain (subject/heading).
 * @return string
 */
function brikpanel_status_email_apply_tokens( $text, $order, $html ) {
	$text = strtr( (string) $text, brikpanel_status_email_tokens( $order ) );
	if ( ! $html ) {
		// Decode before stripping, not after: {order_total} arrives as price
		// markup carrying `&#36;`/`&nbsp;`, and stripping first leaves the
		// entity sitting in the subject line verbatim.
		$text = brikpanel_plain_text_from_html( $text );
	}
	return $text;
}

/**
 * Wrap body HTML in WooCommerce's email header + footer templates and inline the
 * email CSS, producing a message visually identical to native WooCommerce mail.
 *
 * @param WC_Order $order   Order (used by the header template for order context).
 * @param string   $heading Email heading (already token-replaced, plain text).
 * @param string   $body    Body HTML (already token-replaced).
 * @return string Full, inline-styled HTML email.
 */
function brikpanel_status_email_render( $order, $heading, $body ) {
	$email     = new WC_Email();
	$email->id = 'brikpanel_status_email';

	ob_start();
	wc_get_template(
		'emails/email-header.php',
		[
			'email_heading' => $heading,
			'order'         => $order,
			'sent_to_admin' => false,
			'plain_text'    => false,
			'email'         => $email,
		]
	);
	echo wp_kses_post( wpautop( $body ) );
	wc_get_template( 'emails/email-footer.php', [ 'email' => $email ] );
	$html = ob_get_clean();

	return $email->style_inline( $html );
}

/**
 * On every order-status transition, send the configured email if the destination
 * status has one enabled. Wrapped in a try/catch so a mail failure can never
 * break the status change itself.
 *
 * @param int      $order_id Order ID.
 * @param string   $from     Previous status slug (bare).
 * @param string   $to       New status slug (bare).
 * @param WC_Order $order    Order object (WC 3.0+ passes it; we still guard).
 */
function brikpanel_status_email_on_status_changed( $order_id, $from, $to, $order = null ) {
	$configs = brikpanel_get_status_emails();
	$to      = sanitize_key( (string) $to );
	if ( empty( $configs[ $to ] ) || empty( $configs[ $to ]['enabled'] ) ) {
		return;
	}

	if ( ! $order instanceof WC_Order ) {
		$order = wc_get_order( $order_id );
	}
	if ( ! $order instanceof WC_Order ) {
		return;
	}

	// A single status change can reach this worker twice in one request: once
	// from the resilient save-lifecycle path (brikpanel_status_email_after_save)
	// and once from WooCommerce's native `woocommerce_order_status_changed`
	// action. Deliver each transition only once. Keyed per request; a genuine
	// re-transition in a later request starts with a fresh (empty) map.
	static $handled = [];
	$dedupe_key = $order_id . '|' . sanitize_key( (string) $from ) . '|' . $to;
	if ( isset( $handled[ $dedupe_key ] ) ) {
		return;
	}
	$handled[ $dedupe_key ] = true;

	/**
	 * Last-chance gate to skip a status-change email for a specific order.
	 *
	 * @param bool     $send  Whether to send.
	 * @param string   $to    Destination status slug.
	 * @param WC_Order $order Order.
	 */
	if ( ! apply_filters( 'brikpanel_status_email_should_send', true, $to, $order ) ) {
		return;
	}

	try {
		// WooCommerce lazy-loads its email framework: WC_Email lives in
		// includes/emails/, which WC's autoloader does not cover, and the class
		// is only included when WC_Emails (the mailer) instantiates. WC only
		// instantiates the mailer when one of its own transactional email
		// actions fires — and transitions into CUSTOM statuses match none of
		// them. On a lean site nothing else loads the mailer either, so without
		// this boot `new WC_Email()` below throws "class not found", the catch
		// swallows it, and the email silently never sends. Booting the mailer
		// also attaches WooCommerce's email header/footer/styling hooks, so the
		// rendered message carries the store's full email branding.
		if ( function_exists( 'WC' ) && is_callable( [ WC(), 'mailer' ] ) ) {
			WC()->mailer();
		}

		$cfg      = $configs[ $to ];
		$defaults = brikpanel_status_email_defaults();

		$subject_tpl = '' !== trim( $cfg['subject'] ) ? $cfg['subject'] : $defaults['subject'];
		$heading_tpl = '' !== trim( $cfg['heading'] ) ? $cfg['heading'] : $defaults['heading'];
		$content_tpl = '' !== trim( $cfg['content'] ) ? $cfg['content'] : $defaults['content'];

		$subject = brikpanel_status_email_apply_tokens( $subject_tpl, $order, false );
		$heading = brikpanel_status_email_apply_tokens( $heading_tpl, $order, false );
		$body    = brikpanel_status_email_apply_tokens( $content_tpl, $order, true );
		$html    = brikpanel_status_email_render( $order, $heading, $body );

		// Send to the customer separately from staff so neither party's address is
		// ever exposed in the other's To header (mirrors WooCommerce's own split
		// between customer emails and admin emails).
		$mailer = new WC_Email();

		if ( $cfg['customer'] ) {
			$customer_email = $order->get_billing_email();
			if ( $customer_email && is_email( $customer_email ) ) {
				$recipients = apply_filters( 'brikpanel_status_email_customer_recipient', $customer_email, $to, $order );
				$mailer->send( $recipients, $subject, $html, $mailer->get_headers(), [] );
			}
		}

		$staff = [];
		if ( $cfg['admin'] ) {
			$staff[] = get_option( 'admin_email' );
		}
		if ( '' !== $cfg['recipients'] ) {
			$staff = array_merge( $staff, array_map( 'trim', explode( ',', $cfg['recipients'] ) ) );
		}
		$staff = array_values( array_unique( array_filter( $staff, 'is_email' ) ) );
		/**
		 * Filter the staff (admin + extra) recipients of a status-change email.
		 *
		 * @param string[] $staff Recipient emails.
		 * @param string   $to    Destination status slug.
		 * @param WC_Order $order Order.
		 */
		$staff = apply_filters( 'brikpanel_status_email_staff_recipients', $staff, $to, $order );
		if ( $staff ) {
			$mailer->send( implode( ', ', $staff ), $subject, $html, $mailer->get_headers(), [] );
		}
	} catch ( \Throwable $e ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'BrikPanel status email failed: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}
}
add_action( 'woocommerce_order_status_changed', 'brikpanel_status_email_on_status_changed', 10, 4 );

// -----------------------------------------------------------------------------
// Resilient delivery via the order save lifecycle.
//
// WooCommerce fires `woocommerce_order_status_' . $to`, `..._$from_to_$to` and
// finally `woocommerce_order_status_changed` inside ONE try/catch in
// WC_Order::status_transition(). If any earlier callback (a third-party plugin
// hooking a `woocommerce_order_status_*` action) throws, WooCommerce swallows
// the exception and the trailing `woocommerce_order_status_changed` action —
// the only one the sender above listens to — never runs. The status still
// changes, so the merchant sees the move but no email is sent and nothing
// reaches the mail log. This is a common symptom when migrating from another
// custom-status plugin whose hooks are still present.
//
// `woocommerce_before/after_order_object_save` run OUTSIDE that transition
// try/catch (the transition happens after WC_Order::save() calls parent::save()),
// so this path delivers the email even when the native action is blocked. It is
// de-duplicated with the native hook by the static guard in the worker above, so
// a normal (conflict-free) transition still sends exactly one email.

/**
 * Per-order stash for the order's persisted status captured just before a save,
 * so the after-save handler can tell a real transition from an ordinary re-save.
 *
 * @param int         $order_id Order ID.
 * @param string|null $value    Value to store (null to read).
 * @param bool        $pop      Read and remove.
 * @return string|null
 */
function brikpanel_status_email_prev_status( $order_id, $value = null, $pop = false ) {
	static $store = [];
	$order_id = (int) $order_id;
	if ( $pop ) {
		$prev = isset( $store[ $order_id ] ) ? $store[ $order_id ] : null;
		unset( $store[ $order_id ] );
		return $prev;
	}
	if ( null !== $value ) {
		$store[ $order_id ] = (string) $value;
		return $store[ $order_id ];
	}
	return isset( $store[ $order_id ] ) ? $store[ $order_id ] : null;
}

/**
 * Read an order's currently persisted status (bare slug, no `wc-`) straight from
 * storage, honouring HPOS or the legacy post table. Called at most once per save
 * and only when the order is being moved into a status that has an email enabled.
 *
 * @param int $order_id Order ID.
 * @return string Bare status slug, or '' if unknown.
 */
function brikpanel_status_email_persisted_status( $order_id ) {
	$order_id = (int) $order_id;
	if ( $order_id <= 0 ) {
		return '';
	}

	$status = '';
	if ( class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
		&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) {
		global $wpdb;
		$table  = $wpdb->prefix . 'wc_orders';
		$status = $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$table} WHERE id = %d", $order_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	} else {
		$status = get_post_status( $order_id );
	}

	$status = (string) $status;
	if ( '' === $status ) {
		return '';
	}
	return 0 === strpos( $status, 'wc-' ) ? substr( $status, 3 ) : $status;
}

/**
 * Before an order is written, if it is moving into a status that has an email
 * enabled, remember the status still in storage so the after-save handler can
 * confirm a real transition. Cheap for everyone else: stores with no enabled
 * status emails, or saves that do not enter an enabled status, return at once
 * without touching the database.
 *
 * @param WC_Order $order Order about to be saved.
 */
function brikpanel_status_email_capture_prev_status( $order ) {
	if ( ! $order instanceof WC_Order ) {
		return;
	}
	$to      = $order->get_status();
	$configs = brikpanel_get_status_emails();
	if ( empty( $configs[ $to ] ) || empty( $configs[ $to ]['enabled'] ) ) {
		return;
	}
	$order_id = $order->get_id();
	if ( ! $order_id ) {
		return; // Brand-new order (no prior status): parity with the native hook.
	}
	brikpanel_status_email_prev_status( $order_id, brikpanel_status_email_persisted_status( $order_id ) );
}
add_action( 'woocommerce_before_order_object_save', 'brikpanel_status_email_capture_prev_status', 1 );

/**
 * After an order is written, if we stashed a prior status for it and the order
 * actually moved to a different status, deliver the email. Runs before the
 * status-transition actions fire, so it is immune to a third-party callback that
 * throws inside `woocommerce_order_status_*` and blocks the native hook.
 *
 * @param WC_Order $order Order that was saved.
 */
function brikpanel_status_email_after_save( $order ) {
	if ( ! $order instanceof WC_Order ) {
		return;
	}
	$order_id = $order->get_id();
	$from     = brikpanel_status_email_prev_status( $order_id, null, true );
	if ( null === $from ) {
		return; // Not a save we flagged (order did not enter an enabled status).
	}
	$to = $order->get_status();
	if ( '' === $from || $from === $to ) {
		return; // No real transition.
	}
	brikpanel_status_email_on_status_changed( $order_id, $from, $to, $order );
}
add_action( 'woocommerce_after_order_object_save', 'brikpanel_status_email_after_save', 1 );

// =============================================================================
// SETTINGS SCREEN — per-status email configuration UI.
// Admin-only: the sender above already covers every non-admin request.
// =============================================================================
if ( ! is_admin() ) {
	return;
}

/**
 * Inject the "Status change emails" field into the Order statuses section,
 * directly before its sectionend so it renders under the custom-status list.
 * Runs at a late priority so the order-statuses file (default priority) has
 * already built the title + repeater + sectionend we splice into.
 */
add_filter( 'brikpanel_settings_fields', function ( $fields ) {
	if ( ! is_array( $fields ) ) {
		return $fields;
	}
	foreach ( $fields as $i => $field ) {
		if ( isset( $field['type'], $field['id'] )
			&& 'sectionend' === $field['type']
			&& 'brk_order_statuses_title' === $field['id'] ) {
			array_splice( $fields, $i, 0, [ [
				'type' => 'brikpanel_status_emails',
				'id'   => 'brikpanel_status_emails_field',
			] ] );
			break;
		}
	}
	return $fields;
}, 20 );

/**
 * The placeholders offered in the UI helper, as token => human description.
 *
 * @return array<string,string>
 */
function brikpanel_status_email_placeholder_help() {
	return [
		'{order_number}'        => __( 'Order number', 'brikpanel' ),
		'{order_status}'        => __( 'New status name', 'brikpanel' ),
		'{order_date}'          => __( 'Order date', 'brikpanel' ),
		'{order_total}'         => __( 'Order total', 'brikpanel' ),
		'{order_items}'         => __( 'List of items', 'brikpanel' ),
		'{order_view_url}'      => __( 'Order view link', 'brikpanel' ),
		'{customer_first_name}' => __( 'Customer first name', 'brikpanel' ),
		'{customer_full_name}'  => __( 'Customer full name', 'brikpanel' ),
		'{billing_email}'       => __( 'Customer email', 'brikpanel' ),
		'{site_title}'          => __( 'Store name', 'brikpanel' ),
	];
}

/**
 * Render the "Status change emails" card: one collapsible block per custom
 * status, each with an on/off toggle, recipient choices and the message fields.
 *
 * Follows the repeater's form-table dance: close the table WooCommerce opened,
 * emit our card, reopen an empty table for the trailing sectionend.
 */
function brikpanel_render_status_emails_field() {
	$statuses = function_exists( 'brikpanel_get_custom_order_statuses' ) ? brikpanel_get_custom_order_statuses() : [];
	$emails   = brikpanel_get_status_emails();
	$defaults = brikpanel_status_email_defaults();
	$help     = brikpanel_status_email_placeholder_help();
	?>
	</table>
	<section class="bp-cse-card">
		<header class="bp-cos-card__head">
			<div>
				<h3 class="bp-cos-card__title"><?php esc_html_e( 'Status change emails', 'brikpanel' ); ?></h3>
				<p class="bp-cos-card__sub"><?php esc_html_e( 'Send an email automatically when an order moves into one of your custom statuses. Each email is styled like your other WooCommerce emails and can use placeholders such as {order_number}.', 'brikpanel' ); ?></p>
			</div>
		</header>

		<?php if ( ! $statuses ) : ?>
			<p class="bp-cos-empty"><?php esc_html_e( 'Create a custom status above first, then set up its email here.', 'brikpanel' ); ?></p>
		<?php else : ?>
			<div class="bp-cse-list">
				<?php foreach ( $statuses as $slug => $data ) :
					$cfg     = isset( $emails[ $slug ] ) ? $emails[ $slug ] : [];
					$enabled = ! empty( $cfg['enabled'] );
					$subject = isset( $cfg['subject'] ) && '' !== $cfg['subject'] ? $cfg['subject'] : $defaults['subject'];
					$heading = isset( $cfg['heading'] ) && '' !== $cfg['heading'] ? $cfg['heading'] : $defaults['heading'];
					$content = isset( $cfg['content'] ) && '' !== $cfg['content'] ? $cfg['content'] : $defaults['content'];
					// Customer defaults on for a brand-new (unconfigured) status.
					$to_customer = isset( $cfg['customer'] ) ? ! empty( $cfg['customer'] ) : true;
					$to_admin    = ! empty( $cfg['admin'] );
					$recipients  = isset( $cfg['recipients'] ) ? $cfg['recipients'] : '';
					$base        = 'brikpanel_status_email[' . esc_attr( $slug ) . ']';
					?>
					<div class="bp-cse-item<?php echo $enabled ? ' is-on' : ''; ?>" data-cse-item>
						<div class="bp-cse-head" data-cse-toggle role="button" tabindex="0" aria-expanded="false">
							<span class="bp-cos-dot" style="background:<?php echo esc_attr( $data['color'] ); ?>"></span>
							<span class="bp-cse-title"><?php echo esc_html( $data['label'] ); ?></span>
							<code class="bp-cos-slug"><?php echo esc_html( $slug ); ?></code>
							<span class="bp-cse-state" data-cse-state><?php echo $enabled ? esc_html__( 'On', 'brikpanel' ) : esc_html__( 'Off', 'brikpanel' ); ?></span>
							<label class="bp-cse-switch" data-cse-switch>
								<input type="hidden" name="<?php echo $base; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped above. ?>[enabled]" value="0">
								<input type="checkbox" name="<?php echo $base; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped above. ?>[enabled]" value="1" <?php checked( $enabled ); ?> data-cse-enable>
								<span class="bp-cse-slider"></span>
							</label>
							<svg class="bp-cse-chev" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="6 9 12 15 18 9"/></svg>
						</div>

						<div class="bp-cse-body" data-cse-body>
							<div class="bp-cse-body-inner">
								<div class="bp-cse-field bp-cse-recipients">
									<span class="bp-cse-label"><?php esc_html_e( 'Send to', 'brikpanel' ); ?></span>
									<div class="bp-cse-checks">
										<label class="bp-cse-check">
											<input type="checkbox" name="<?php echo $base; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>[customer]" value="1" <?php checked( $to_customer ); ?>>
											<?php esc_html_e( 'Customer', 'brikpanel' ); ?>
										</label>
										<label class="bp-cse-check">
											<input type="checkbox" name="<?php echo $base; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>[admin]" value="1" <?php checked( $to_admin ); ?>>
											<?php esc_html_e( 'Store admin', 'brikpanel' ); ?>
										</label>
									</div>
								</div>

								<div class="bp-cse-field">
									<label class="bp-cse-label" for="<?php echo esc_attr( 'bp-cse-rcpt-' . $slug ); ?>"><?php esc_html_e( 'Extra recipients', 'brikpanel' ); ?></label>
									<input type="text" id="<?php echo esc_attr( 'bp-cse-rcpt-' . $slug ); ?>" class="bp-cse-input" name="<?php echo $base; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>[recipients]" value="<?php echo esc_attr( $recipients ); ?>" placeholder="<?php esc_attr_e( 'name@example.com, warehouse@example.com', 'brikpanel' ); ?>" autocomplete="off">
								</div>

								<div class="bp-cse-field">
									<label class="bp-cse-label" for="<?php echo esc_attr( 'bp-cse-subj-' . $slug ); ?>"><?php esc_html_e( 'Subject', 'brikpanel' ); ?></label>
									<input type="text" id="<?php echo esc_attr( 'bp-cse-subj-' . $slug ); ?>" class="bp-cse-input" name="<?php echo $base; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>[subject]" value="<?php echo esc_attr( $subject ); ?>" data-cse-insertable autocomplete="off">
								</div>

								<div class="bp-cse-field">
									<label class="bp-cse-label" for="<?php echo esc_attr( 'bp-cse-head-' . $slug ); ?>"><?php esc_html_e( 'Heading', 'brikpanel' ); ?></label>
									<input type="text" id="<?php echo esc_attr( 'bp-cse-head-' . $slug ); ?>" class="bp-cse-input" name="<?php echo $base; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>[heading]" value="<?php echo esc_attr( $heading ); ?>" data-cse-insertable autocomplete="off">
								</div>

								<div class="bp-cse-field">
									<label class="bp-cse-label" for="<?php echo esc_attr( 'bp-cse-body-' . $slug ); ?>"><?php esc_html_e( 'Message', 'brikpanel' ); ?></label>
									<textarea id="<?php echo esc_attr( 'bp-cse-body-' . $slug ); ?>" class="bp-cse-textarea" name="<?php echo $base; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>[content]" rows="7" data-cse-insertable><?php echo esc_textarea( $content ); ?></textarea>
								</div>

								<div class="bp-cse-field">
									<span class="bp-cse-label"><?php esc_html_e( 'Insert placeholder', 'brikpanel' ); ?></span>
									<div class="bp-cse-tokens">
										<?php foreach ( $help as $token => $desc ) : ?>
											<button type="button" class="bp-cse-token" data-token="<?php echo esc_attr( $token ); ?>" title="<?php echo esc_attr( $desc ); ?>"><?php echo esc_html( $token ); ?></button>
										<?php endforeach; ?>
									</div>
								</div>
							</div>
						</div>
					</div>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
	</section>
	<table class="form-table">
	<?php
}
add_action( 'woocommerce_admin_field_brikpanel_status_emails', 'brikpanel_render_status_emails_field' );

/**
 * Persist the per-status email configuration. Runs after the repeater save
 * (priority 11) so it can prune email config for statuses removed in the same
 * request, and only keeps config for slugs that are still real custom statuses.
 */
add_action( 'woocommerce_update_options_brikpanel', function () {
	if ( ! function_exists( 'brikpanel_settings_get_current_section' )
		|| 'order-statuses' !== brikpanel_settings_get_current_section() ) {
		return;
	}
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		return;
	}

	$posted = isset( $_POST['brikpanel_status_email'] ) && is_array( $_POST['brikpanel_status_email'] )
		? wp_unslash( $_POST['brikpanel_status_email'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput — each field sanitised individually below.
		: [];

	// Authoritative current list of custom statuses (repeater already saved).
	$valid_slugs = function_exists( 'brikpanel_get_custom_order_statuses' )
		? array_keys( brikpanel_get_custom_order_statuses() )
		: [];

	$clean = [];
	foreach ( $posted as $slug => $cfg ) {
		$slug = sanitize_key( (string) $slug );
		if ( '' === $slug || ! is_array( $cfg ) || ! in_array( $slug, $valid_slugs, true ) ) {
			continue;
		}
		$clean[ $slug ] = [
			'enabled'    => ! empty( $cfg['enabled'] ),
			'customer'   => ! empty( $cfg['customer'] ),
			'admin'      => ! empty( $cfg['admin'] ),
			'recipients' => brikpanel_status_email_clean_recipients( isset( $cfg['recipients'] ) ? (string) $cfg['recipients'] : '' ),
			'subject'    => isset( $cfg['subject'] ) ? sanitize_text_field( (string) $cfg['subject'] ) : '',
			'heading'    => isset( $cfg['heading'] ) ? sanitize_text_field( (string) $cfg['heading'] ) : '',
			// Body may contain limited HTML; wp_kses_post keeps safe markup and
			// preserves the {placeholder} tokens (plain text passes through).
			'content'    => isset( $cfg['content'] ) ? wp_kses_post( (string) $cfg['content'] ) : '',
		];
	}

	update_option( BRIKPANEL_STATUS_EMAILS_OPTION, $clean );
}, 12 );

/**
 * Load the card's CSS + JS on the BrikPanel WC settings tab only, with a
 * filemtime cache-bust so an updated asset is never served stale.
 */
add_action( 'admin_enqueue_scripts', function ( $hook ) {
	if ( 'woocommerce_page_wc-settings' !== $hook ) {
		return;
	}
	$tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( 'brikpanel' !== $tab ) {
		return;
	}
	$css_path = __DIR__ . '/brikpanel-status-emails.css';
	$js_path  = __DIR__ . '/brikpanel-status-emails.js';
	wp_enqueue_style(
		'brikpanel-status-emails',
		plugins_url( 'brikpanel-status-emails.css', __FILE__ ),
		[],
		file_exists( $css_path ) ? filemtime( $css_path ) : BRIKPANEL_VERSION
	);
	wp_enqueue_script(
		'brikpanel-status-emails',
		plugins_url( 'brikpanel-status-emails.js', __FILE__ ),
		[],
		file_exists( $js_path ) ? filemtime( $js_path ) : BRIKPANEL_VERSION,
		true
	);
	wp_localize_script( 'brikpanel-status-emails', 'brikpanelStatusEmails', [
		'i18n' => [
			'on'  => __( 'On', 'brikpanel' ),
			'off' => __( 'Off', 'brikpanel' ),
		],
	] );
} );
