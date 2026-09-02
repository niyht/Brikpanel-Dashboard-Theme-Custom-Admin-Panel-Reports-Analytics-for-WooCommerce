<?php
/**
 * BrikPanel — WhatsApp quick-contact for orders
 *
 * Adds a one-click WhatsApp shortcut wherever a store manager fulfils orders:
 *   - a small WhatsApp icon in front of the order number on the Orders list
 *     (its own column, so Screen Options can hide it), and
 *   - a "Message on WhatsApp" button on the single order edit screen, right
 *     under the billing address.
 *
 * Clicking opens https://wa.me/<number> — WhatsApp Web on desktop, the app on
 * mobile — pre-targeting the customer's billing phone from that order. The
 * number is normalised to the international digits WhatsApp expects: any
 * non-digits are stripped and, when the customer typed a local number, the
 * order's billing-country dialing code is prepended so the chat actually opens.
 *
 * Works for HPOS and the legacy posts screen, and for simple and variable
 * product orders alike (the phone lives on the order, not the products).
 *
 * @package BrikPanel
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// =============================================================================
// MODULE SWITCH + ROLE SCOPING
// =============================================================================
//
// The WhatsApp shortcut is opt-out per store and opt-out per role, mirroring the
// Access control options (e.g. brikpanel_orders_overview_hidden_roles):
//
//   brikpanel_whatsapp_enabled       yes/no, default 'yes' — global on/off.
//   brikpanel_whatsapp_hidden_roles  array of role slugs   — roles that do NOT
//                                                            see the shortcut.
//   brikpanel_whatsapp_optin (user meta) 'yes' — a per-user override that lets a
//     user whose role is hidden switch the shortcut back on for their own account
//     (the common ask: hide it from administrators by default, but let an admin
//     re-enable it just for themselves from their profile page).
//
// Defaults keep existing behaviour identical: the global switch is on and no role
// is hidden, so every user with WooCommerce's `manage_woocommerce` capability
// still sees the shortcut exactly as before.

const BRIKPANEL_WHATSAPP_OPT_ENABLED      = 'brikpanel_whatsapp_enabled';
const BRIKPANEL_WHATSAPP_OPT_HIDDEN_ROLES = 'brikpanel_whatsapp_hidden_roles';
const BRIKPANEL_WHATSAPP_USER_OPTIN_META  = 'brikpanel_whatsapp_optin';

/**
 * Whether the WhatsApp order module is switched on store-wide.
 *
 * A missing option counts as on, so a fresh install behaves exactly as before.
 *
 * @return bool
 */
function brikpanel_whatsapp_module_enabled() {
	return get_option( BRIKPANEL_WHATSAPP_OPT_ENABLED, 'yes' ) !== 'no';
}

/**
 * Whether a given user's role is currently in the "hidden roles" list.
 *
 * @param WP_User|null $user
 * @return bool
 */
function brikpanel_whatsapp_user_is_hidden_by_role( $user ) {
	if ( ! $user instanceof WP_User || ! $user->ID ) {
		return false;
	}
	$hidden_roles = array_map( 'strval', (array) get_option( BRIKPANEL_WHATSAPP_OPT_HIDDEN_ROLES, [] ) );
	if ( ! $hidden_roles ) {
		return false;
	}
	return (bool) array_intersect( array_map( 'strval', (array) $user->roles ), $hidden_roles );
}

/**
 * Whether the WhatsApp shortcut should render for a user.
 *
 * Resolution (any earlier rule wins):
 *   1. Global switch off  → hidden for everyone.
 *   2. No resolvable user → hidden (front-end, cron, logged-out).
 *   3. User lacks `manage_woocommerce` → hidden (the baseline this feature has
 *      always honoured — only order handlers ever saw it).
 *   4. User's role is in the hidden-roles list → hidden, UNLESS the user has
 *      opted back in for their own account (per-user meta).
 *   5. Otherwise → shown.
 *
 * @param WP_User|null $user Optional user; defaults to the current user.
 * @return bool
 */
function brikpanel_whatsapp_visible_for_user( $user = null ) {
	if ( ! brikpanel_whatsapp_module_enabled() ) {
		return false;
	}
	if ( ! function_exists( 'wp_get_current_user' ) ) {
		return false;
	}
	if ( null === $user ) {
		$user = wp_get_current_user();
	}
	if ( ! $user instanceof WP_User || ! $user->ID ) {
		return false;
	}

	// Baseline WooCommerce order-management capability — unchanged from before.
	if ( ! user_can( $user, 'manage_woocommerce' ) ) {
		return false;
	}

	$visible = true;
	if ( brikpanel_whatsapp_user_is_hidden_by_role( $user ) ) {
		// Hidden by role, unless this user re-enabled it for their own account.
		$visible = get_user_meta( $user->ID, BRIKPANEL_WHATSAPP_USER_OPTIN_META, true ) === 'yes';
	}

	/**
	 * Filter the final per-user visibility of the WhatsApp order shortcut.
	 *
	 * @param bool    $visible
	 * @param WP_User $user
	 */
	return (bool) apply_filters( 'brikpanel_whatsapp_visible_for_user', $visible, $user );
}

/**
 * The ISO country -> dialing code map that used to live here now lives in
 * includes/brikpanel-helpers.php, next to the shared number normaliser, because
 * the Abandoned Carts screen needs it too and this file is only loaded inside
 * wp-admin. The function name and its `brikpanel_country_dialing_code` filter
 * are unchanged, so anything hooking it keeps working.
 */

/**
 * Normalise an order's billing phone into the international digits WhatsApp
 * expects. Returns '' when the order has no usable phone.
 *
 * The work itself is done by the shared normaliser in includes/, which the
 * Abandoned Carts screen calls too, so the same customer never gets two
 * different links depending on which screen the shop manager is looking at.
 *
 * An order always carries a real billing country, so the normaliser's
 * "the country is only a guess" branch never applies here.
 *
 * @param WC_Order $order
 * @return string Digits only (no "+"), or ''.
 */
function brikpanel_order_whatsapp_number( $order ) {
	if ( ! is_a( $order, 'WC_Order' ) ) {
		return '';
	}
	if ( ! function_exists( 'brikpanel_phone_to_e164' ) ) {
		return '';
	}

	$number = brikpanel_phone_to_e164( $order->get_billing_phone(), $order->get_billing_country() );
	if ( '' === $number ) {
		return '';
	}

	/**
	 * Filter the final WhatsApp number (digits only, no "+") for an order.
	 */
	return (string) apply_filters( 'brikpanel_order_whatsapp_number', $number, $order );
}

// =============================================================================
// THE DRAFT MESSAGE — one general template plus per-status overrides
// =============================================================================
//
// Opening an empty chat leaves the whole message to be retyped for every order,
// so the shortcut carries a draft. It is still only a draft: WhatsApp opens with
// the text in the input box and nothing is sent until the merchant presses send.
//
//   brikpanel_whatsapp_order_message           string — the general template.
//   brikpanel_whatsapp_order_status_messages   array  — bare status slug =>
//                                                       [ enabled, message ].
//
// A status with an enabled, non-empty override wins; everything else falls back
// to the general template. An empty general template means no `?text=` at all,
// i.e. exactly the pre-3.2.62 behaviour of opening a blank conversation.

const BRIKPANEL_WHATSAPP_OPT_ORDER_MESSAGE  = 'brikpanel_whatsapp_order_message';
const BRIKPANEL_WHATSAPP_OPT_ORDER_STATUSES = 'brikpanel_whatsapp_order_status_messages';

/**
 * The general draft shipped with the plugin, used until a merchant edits it.
 *
 * Kept as a __() string (rather than a stored default) so a store that never
 * opens the setting still gets the message in its own language. Deliberately
 * free of {order_items}: the orders list renders this for every visible row and
 * that token is the only one that costs an extra query per order.
 *
 * @return string
 */
function brikpanel_whatsapp_order_default_message() {
	return __( 'Hi {customer_first_name}, this is {site_title} about your order {order_number}. Its current status is {order_status}.', 'brikpanel' );
}

/**
 * The general template: whatever the merchant wrote, or the built-in default.
 *
 * Returns '' only when the merchant deliberately cleared the field, which is
 * the documented way to go back to opening an empty chat.
 *
 * @return string
 */
function brikpanel_whatsapp_order_general_message() {
	$saved = get_option( BRIKPANEL_WHATSAPP_OPT_ORDER_MESSAGE, null );
	if ( null === $saved ) {
		// Option was never written — a fresh install gets the built-in draft.
		return brikpanel_whatsapp_order_default_message();
	}
	return brikpanel_whatsapp_normalise_newlines( (string) $saved );
}

/**
 * Browsers post textarea line breaks as CRLF; WhatsApp only needs the LF, and a
 * stray CR survives URL-encoding as a visible %0D in the draft.
 *
 * @param string $text
 * @return string
 */
function brikpanel_whatsapp_normalise_newlines( $text ) {
	return str_replace( array( "\r\n", "\r" ), "\n", (string) $text );
}

/**
 * Order statuses a merchant can write a message for, as bare slug => label.
 *
 * Every registered status is offered, not just BrikPanel's own: the whole point
 * is being able to say something different for "Processing" than for "Pending
 * payment", and third-party statuses (shipping plugins, marketplaces) are just
 * as worth messaging about. `checkout-draft` is dropped — it is WooCommerce's
 * internal placeholder for a checkout in progress, never a real order.
 *
 * @return array<string,string>
 */
function brikpanel_whatsapp_order_statuses() {
	$out = array();
	if ( function_exists( 'wc_get_order_statuses' ) ) {
		foreach ( wc_get_order_statuses() as $key => $label ) {
			$slug = 'wc-' === substr( $key, 0, 3 ) ? substr( $key, 3 ) : $key;
			if ( 'checkout-draft' === $slug ) {
				continue;
			}
			$out[ $slug ] = (string) $label;
		}
	}

	/**
	 * Filter the statuses offered in the per-status message list.
	 *
	 * @param array<string,string> $out Bare slug => label.
	 */
	return (array) apply_filters( 'brikpanel_whatsapp_order_statuses', $out );
}

/**
 * The stored per-status overrides, normalised to a predictable shape.
 *
 * Cached per request: the orders list calls this once per visible row.
 *
 * @param bool $reset Internal — drop the cache after the option changes.
 * @return array<string,array{enabled:bool,message:string}>
 */
function brikpanel_whatsapp_order_status_messages( $reset = false ) {
	static $cache = null;

	if ( $reset ) {
		$cache = null;
		return array();
	}
	if ( null !== $cache ) {
		return $cache;
	}

	$cache = array();
	foreach ( (array) get_option( BRIKPANEL_WHATSAPP_OPT_ORDER_STATUSES, array() ) as $slug => $cfg ) {
		$slug = sanitize_key( (string) $slug );
		if ( '' === $slug || ! is_array( $cfg ) ) {
			continue;
		}
		$cache[ $slug ] = array(
			'enabled' => ! empty( $cfg['enabled'] ),
			'message' => isset( $cfg['message'] ) ? brikpanel_whatsapp_normalise_newlines( (string) $cfg['message'] ) : '',
		);
	}
	return $cache;
}

/**
 * Flush the per-request cache the moment the option changes, so the post-save
 * settings render in the same request already reflects what was just stored.
 */
$brikpanel_wa_status_reset = static function () {
	brikpanel_whatsapp_order_status_messages( true );
};
add_action( 'add_option_' . BRIKPANEL_WHATSAPP_OPT_ORDER_STATUSES, $brikpanel_wa_status_reset );
add_action( 'update_option_' . BRIKPANEL_WHATSAPP_OPT_ORDER_STATUSES, $brikpanel_wa_status_reset );
add_action( 'delete_option_' . BRIKPANEL_WHATSAPP_OPT_ORDER_STATUSES, $brikpanel_wa_status_reset );
unset( $brikpanel_wa_status_reset );

/**
 * The template that applies to one order: its status override when that override
 * is switched on and actually says something, otherwise the general template.
 *
 * @param WC_Order $order
 * @return string
 */
function brikpanel_whatsapp_order_template_for( $order ) {
	$per_status = brikpanel_whatsapp_order_status_messages();
	$slug       = $order->get_status();

	if ( isset( $per_status[ $slug ] ) && $per_status[ $slug ]['enabled'] && '' !== trim( $per_status[ $slug ]['message'] ) ) {
		return $per_status[ $slug ]['message'];
	}
	return brikpanel_whatsapp_order_general_message();
}

/**
 * Reduce a WooCommerce-formatted HTML fragment to the plain text WhatsApp shows.
 *
 * Kept as its own name because this module reaches for it on every item name
 * and every piece of item meta; the reasoning about entity and whitespace
 * order now lives with the shared helper.
 *
 * @param string $html
 * @return string
 */
function brikpanel_whatsapp_plain_text( $html ) {
	return brikpanel_plain_text_from_html( $html );
}

/**
 * The order's line items as plain text, one product per line.
 *
 * Simple and variable products go down the same path. The quantity and name
 * always come from the line item; the variation's attributes are appended from
 * the item's own meta, which is where WooCommerce records what was actually
 * bought.
 *
 * The one subtlety is that stores disagree about where the attributes live.
 * Depending on woocommerce_product_variation_title_include_attributes, a
 * variation's stored name is either the bare parent ("Hoodie") or the parent
 * with its attributes already spelled out ("Hoodie - Ekru, L"). Appending the
 * meta blindly produces "Hoodie - Ekru, L (Colour: Ekru, Size: L)" on the second
 * kind of store, so any attribute the name already states is skipped and only
 * the genuinely new ones are added.
 *
 * @param WC_Order $order
 * @return string
 */
function brikpanel_whatsapp_order_items_text( $order ) {
	$lines = array();

	foreach ( $order->get_items() as $item ) {
		$name = brikpanel_whatsapp_plain_text( $item->get_name() );
		$qty  = method_exists( $item, 'get_quantity' ) ? (int) $item->get_quantity() : 1;
		$line = $qty . ' × ' . $name;

		$extras = array();
		if ( method_exists( $item, 'get_formatted_meta_data' ) ) {
			$folded = brikpanel_strtolower( $name );
			foreach ( $item->get_formatted_meta_data() as $meta ) {
				$key   = brikpanel_whatsapp_plain_text( $meta->display_key );
				$value = brikpanel_whatsapp_plain_text( $meta->display_value );
				if ( '' === $value ) {
					continue;
				}
				$needle = brikpanel_strtolower( $value );
				if ( false !== strpos( $folded, $needle ) ) {
					// The item name already says this; repeating it reads badly.
					continue;
				}
				$extras[] = '' !== $key ? $key . ': ' . $value : $value;
			}
		}

		if ( $extras ) {
			$line .= ' (' . implode( ', ', $extras ) . ')';
		}
		$lines[] = $line;
	}

	return implode( "\n", $lines );
}

/**
 * The {placeholders} a draft understands, resolved for one order.
 *
 * The token names match the status-change email placeholders on purpose, so a
 * merchant learns one vocabulary for both features. The values differ: an email
 * wants HTML, a WhatsApp draft wants plain text.
 *
 * Only the tokens the template actually uses are resolved. That is not a
 * micro-optimisation: the orders list builds a draft for every visible row, and
 * {order_items} is the one token that costs a query per order.
 *
 * @param WC_Order $order    Order the draft is about.
 * @param string   $template Template being filled, used to skip unused tokens.
 * @return array<string,string>
 */
function brikpanel_whatsapp_order_tokens( $order, $template ) {
	$uses = static function ( $token ) use ( $template ) {
		return false !== strpos( $template, $token );
	};

	// Numbers, dates and phone numbers are isolated because a right-to-left
	// message reorders them otherwise: the separators inside them are neutral
	// characters, and an Arabic paragraph lays the pieces out back to front.
	$tokens = array(
		'{order_number}'        => brikpanel_bidi_isolate_ltr( (string) $order->get_order_number() ),
		'{order_id}'            => brikpanel_bidi_isolate_ltr( (string) $order->get_id() ),
		'{order_status}'        => function_exists( 'wc_get_order_status_name' ) ? (string) wc_get_order_status_name( $order->get_status() ) : (string) $order->get_status(),
		'{customer_first_name}' => (string) $order->get_billing_first_name(),
		'{customer_last_name}'  => (string) $order->get_billing_last_name(),
		'{customer_full_name}'  => brikpanel_whatsapp_plain_text( $order->get_formatted_billing_full_name() ),
		'{billing_email}'       => (string) $order->get_billing_email(),
		'{billing_phone}'       => brikpanel_bidi_isolate_ltr( (string) $order->get_billing_phone() ),
		'{site_title}'          => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
		'{site_url}'            => home_url(),
	);

	if ( $uses( '{order_date}' ) ) {
		$created = $order->get_date_created();
		$tokens['{order_date}'] = $created && function_exists( 'wc_format_datetime' ) ? brikpanel_bidi_isolate_ltr( (string) wc_format_datetime( $created ) ) : '';
	}

	if ( $uses( '{order_total}' ) ) {
		// Deliberately not get_formatted_order_total(): that runs the
		// woocommerce_get_formatted_order_total filter, where third-party
		// currency plugins have been seen to fatal, and on a refunded order it
		// returns struck-through-plus-new markup that flattens into two prices
		// sitting next to each other. The raw total is what a customer expects.
		$tokens['{order_total}'] = brikpanel_money_text(
			$order->get_total(),
			array( 'currency' => $order->get_currency() )
		);
	}

	if ( $uses( '{order_view_url}' ) ) {
		$tokens['{order_view_url}'] = esc_url_raw( (string) $order->get_view_order_url() );
	}

	if ( $uses( '{order_items}' ) ) {
		$tokens['{order_items}'] = brikpanel_whatsapp_order_items_text( $order );
	}

	/**
	 * Filter the placeholder map for an order's WhatsApp draft.
	 *
	 * @param array    $tokens   token => plain-text replacement.
	 * @param WC_Order $order    Order.
	 * @param string   $template Template being filled.
	 */
	return (array) apply_filters( 'brikpanel_whatsapp_order_tokens', $tokens, $order, $template );
}

/**
 * The finished draft for an order, or '' when there is nothing to prefill.
 *
 * Wrapped in a Throwable guard because this runs on every orders-list render:
 * a third-party filter blowing up inside a token must cost the draft, not the
 * whole screen.
 *
 * @param WC_Order $order
 * @return string
 */
function brikpanel_whatsapp_order_message( $order ) {
	if ( ! is_a( $order, 'WC_Order' ) ) {
		return '';
	}

	try {
		$template = brikpanel_whatsapp_order_template_for( $order );
		if ( '' === trim( $template ) ) {
			return '';
		}

		$tokens = brikpanel_whatsapp_order_tokens( $order, $template );

		// A placeholder given a line of its own — the natural way to write
		// {order_items} — would leave a blank line behind when it resolves to
		// nothing. Drop the whole line instead of substituting emptiness into
		// it. Only a line that is *nothing but* the placeholder is removed, so a
		// merchant's own blank lines are left alone.
		foreach ( $tokens as $token => $value ) {
			if ( '' === $value ) {
				$template = preg_replace( '/^[ \t]*' . preg_quote( $token, '/' ) . '[ \t]*\R?/m', '', $template );
			}
		}

		$text = strtr( $template, $tokens );

		// A sentence written around a placeholder also has to survive that
		// placeholder being empty mid-line (an order with no billing first
		// name). Same tidy-up the abandoned-cart draft does: close up the space
		// before a comma and collapse the run of spaces left behind.
		if ( in_array( '', $tokens, true ) ) {
			$text = preg_replace( '/[ \t]+([,،])/u', '$1', $text );
			$text = preg_replace( '/[ \t]{2,}/', ' ', $text );
		}

		$text = trim( (string) $text );

		/**
		 * Maximum characters in a prefilled draft. WhatsApp carries it in the
		 * URL, so an unbounded order with fifty line items would produce a link
		 * long enough for a browser or the app to truncate mid-word.
		 *
		 * @param int $max
		 */
		$max = (int) apply_filters( 'brikpanel_whatsapp_order_message_max_length', 1200 );
		if ( $max > 0 && brikpanel_strlen( $text ) > $max ) {
			// Cut by characters, not bytes: a byte-wise cut lands mid-character
			// in Arabic, Turkish or any other non-ASCII text.
			$text = brikpanel_substr( $text, 0, $max );

			// Prefer to end on a whole line, so a long item list stops after a
			// product rather than halfway through its name. Only when that keeps
			// most of the draft — a single very long line would otherwise be cut
			// back to almost nothing.
			$break = brikpanel_strrpos( $text, "\n" );
			if ( false !== $break && $break > (int) ( $max * 0.6 ) ) {
				$text = brikpanel_substr( $text, 0, $break );
			}
			$text = rtrim( $text );

			// The cut can land between an isolate and its terminator, and an
			// unclosed isolate drags everything after it into the wrong
			// direction. Repaired before the filter below rather than after,
			// so text a filter appends is not stranded inside the repair.
			$text = brikpanel_bidi_close_isolates( $text );
		}

		/**
		 * Filter the finished WhatsApp draft for an order.
		 *
		 * @param string   $text
		 * @param WC_Order $order
		 */
		return (string) apply_filters( 'brikpanel_whatsapp_order_message', $text, $order );
	} catch ( \Throwable $e ) {
		// Fall back to a message-less chat rather than taking the screen down.
		return '';
	}
}

/**
 * Full https://wa.me/ URL for an order, or '' when no phone is available.
 *
 * Carries the draft as `?text=` when one resolves. Both surfaces (the orders
 * list icon and the order-edit button) go through here, so they can never drift
 * apart.
 *
 * @param WC_Order $order
 * @return string
 */
function brikpanel_order_whatsapp_url( $order ) {
	$number = brikpanel_order_whatsapp_number( $order );
	if ( $number === '' ) {
		return '';
	}

	$url     = 'https://wa.me/' . $number;
	$message = brikpanel_whatsapp_order_message( $order );
	if ( '' !== $message ) {
		$url .= '?text=' . rawurlencode( $message );
	}

	return apply_filters( 'brikpanel_order_whatsapp_url', $url, $order );
}

/**
 * Escape a WhatsApp URL for an href attribute, keeping the draft's line breaks.
 *
 * esc_url() cannot be used here: it strips %0A and %0D outright
 * (wp-includes/formatting.php, the CRLF guard that stops header injection), so
 * every line break in the draft would silently vanish and the message would
 * arrive as one run-on paragraph. That guard protects redirects and headers, not
 * an anchor's href, where encoded newlines are exactly what WhatsApp expects.
 *
 * Rather than skip escaping, the URL is matched against the only shape this
 * module ever produces — https://wa.me/<digits> with an optional percent-encoded
 * text parameter — and attribute-escaped when it fits. Anything else (a filter
 * rewrote it, so we can no longer reason about it) falls back to esc_url and
 * loses the newlines, which is the safe direction to fail in.
 *
 * @param string $url
 * @return string Escaped for use inside a double-quoted attribute.
 */
function brikpanel_whatsapp_esc_url( $url ) {
	$url = (string) $url;
	if ( preg_match( '#^https://wa\.me/\d+(\?text=[A-Za-z0-9\-_.~%]*)?$#', $url ) ) {
		return esc_attr( $url );
	}
	return esc_url( $url );
}

/**
 * The WhatsApp glyph as inline SVG (brand green). Static, trusted markup.
 *
 * @param int $size Pixel size.
 * @return string
 */
function brikpanel_order_whatsapp_icon_svg( $size = 18 ) {
	$size = (int) $size;
	return '<svg class="brikpanel-wa-glyph" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.019-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51l-.57-.01c-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.71.306 1.263.489 1.694.625.712.227 1.36.195 1.872.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>';
}

// =============================================================================
// ORDERS LIST — WhatsApp column (rendered in front of the order number)
// =============================================================================

$brikpanel_wa_hpos = get_option( 'woocommerce_custom_orders_table_enabled' ) === 'yes';
if ( $brikpanel_wa_hpos ) {
	add_filter( 'manage_woocommerce_page_wc-orders_columns', 'brikpanel_wa_add_order_column', 21 );
	add_action( 'manage_woocommerce_page_wc-orders_custom_column', 'brikpanel_wa_fill_order_column', 21, 2 );
} else {
	add_filter( 'manage_edit-shop_order_columns', 'brikpanel_wa_add_order_column', 21 );
	add_action( 'manage_shop_order_posts_custom_column', 'brikpanel_wa_fill_order_column_legacy', 21, 2 );
}

/**
 * Insert the WhatsApp column immediately before the order-number column so the
 * icon reads as sitting "in front of" the order. Falls back to prepending after
 * the checkbox when the order-number column can't be found.
 *
 * @param array $columns
 * @return array
 */
function brikpanel_wa_add_order_column( $columns ) {
	if ( ! is_array( $columns ) || isset( $columns['brikpanel_whatsapp'] ) ) {
		return $columns;
	}
	if ( ! brikpanel_whatsapp_visible_for_user() ) {
		return $columns;
	}
	$label  = __( 'WhatsApp', 'brikpanel' );
	$anchor = isset( $columns['order_number'] ) ? 'order_number' : ( isset( $columns['order_title'] ) ? 'order_title' : '' );

	if ( $anchor === '' ) {
		return array_merge( array( 'brikpanel_whatsapp' => $label ), $columns );
	}
	$out = array();
	foreach ( $columns as $key => $value ) {
		if ( $key === $anchor ) {
			$out['brikpanel_whatsapp'] = $label;
		}
		$out[ $key ] = $value;
	}
	return $out;
}

/**
 * Render the icon cell (HPOS — receives the order object).
 *
 * @param string   $column
 * @param WC_Order $order
 */
function brikpanel_wa_fill_order_column( $column, $order ) {
	if ( $column !== 'brikpanel_whatsapp' ) {
		return;
	}
	brikpanel_wa_render_list_icon( $order );
}

/**
 * Render the icon cell (legacy — receives the post ID).
 *
 * @param string $column
 * @param int    $post_id
 */
function brikpanel_wa_fill_order_column_legacy( $column, $post_id ) {
	if ( $column !== 'brikpanel_whatsapp' ) {
		return;
	}
	$order = wc_get_order( $post_id );
	if ( $order ) {
		brikpanel_wa_render_list_icon( $order );
	}
}

/**
 * Echo the list-cell WhatsApp link for an order (nothing when no phone).
 *
 * @param WC_Order $order
 */
function brikpanel_wa_render_list_icon( $order ) {
	if ( ! brikpanel_whatsapp_visible_for_user() ) {
		return;
	}
	$url = brikpanel_order_whatsapp_url( $order );
	if ( $url === '' ) {
		return;
	}
	$title = __( 'Message the customer on WhatsApp', 'brikpanel' );
	printf(
		'<a href="%1$s" target="_blank" rel="noopener" class="brikpanel-wa-list-link" title="%2$s" aria-label="%2$s">%3$s</a>',
		brikpanel_whatsapp_esc_url( $url ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside; esc_url would eat the draft's line breaks.
		esc_attr( $title ),
		brikpanel_order_whatsapp_icon_svg( 18 ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static inline SVG.
	);
}

// =============================================================================
// SINGLE ORDER SCREEN — "Message on WhatsApp" button under the billing address
// =============================================================================

add_action( 'woocommerce_admin_order_data_after_billing_address', 'brikpanel_wa_render_order_screen_button', 20 );

/**
 * Render the WhatsApp button on the order edit screen.
 *
 * @param WC_Order $order
 */
function brikpanel_wa_render_order_screen_button( $order ) {
	if ( ! brikpanel_whatsapp_visible_for_user() ) {
		return;
	}
	$url = brikpanel_order_whatsapp_url( $order );
	if ( $url === '' ) {
		return;
	}
	printf(
		'<p class="brikpanel-wa-order-screen"><a href="%1$s" target="_blank" rel="noopener" class="button brikpanel-wa-btn">%2$s<span>%3$s</span></a></p>',
		brikpanel_whatsapp_esc_url( $url ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside; esc_url would eat the draft's line breaks.
		brikpanel_order_whatsapp_icon_svg( 16 ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static inline SVG.
		esc_html__( 'Message on WhatsApp', 'brikpanel' )
	);
}

// =============================================================================
// STYLES (orders list + order edit screen only)
// =============================================================================

add_action( 'admin_head', function () {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( ! $screen ) {
		return;
	}
	$id       = (string) $screen->id;
	$is_list  = ( $id === 'woocommerce_page_wc-orders' || $id === 'edit-shop_order' );
	$is_edit  = ( $id === 'woocommerce_page_wc-orders' && ( isset( $_GET['action'] ) && $_GET['action'] === 'edit' ) )
		|| $id === 'shop_order';
	if ( ! $is_list && ! $is_edit ) {
		return;
	}
	if ( ! brikpanel_whatsapp_visible_for_user() ) {
		return;
	}
	?>
	<style id="brikpanel-wa-style">
		/* Orders list — tight icon column in front of the order number. */
		.wp-list-table th.column-brikpanel_whatsapp,
		.wp-list-table td.column-brikpanel_whatsapp {
			width: 34px;
			text-align: center;
			padding-left: 6px;
			padding-right: 6px;
		}
		.brikpanel-wa-list-link {
			display: inline-flex;
			align-items: center;
			justify-content: center;
			width: 26px;
			height: 26px;
			border-radius: 6px;
			color: #25d366;
			text-decoration: none;
			transition: background-color .15s ease, transform .15s ease;
		}
		.brikpanel-wa-list-link:hover {
			background: rgba(37, 211, 102, .12);
			transform: translateY(-1px);
		}
		.brikpanel-wa-list-link .brikpanel-wa-glyph { display: block; }
		/* Order edit screen — button under the billing address. */
		.brikpanel-wa-order-screen { margin: .75rem 0 0; }
		.brikpanel-wa-btn.brikpanel-wa-btn {
			display: inline-flex;
			align-items: center;
			gap: .4rem;
			height: auto;
			padding: .4rem .75rem;
			color: #fff;
			background: #25d366;
			border-color: #1da851;
			border-radius: 6px;
			box-shadow: none;
			text-shadow: none;
			font-weight: 600;
		}
		.brikpanel-wa-btn.brikpanel-wa-btn:hover {
			color: #fff;
			background: #1da851;
			border-color: #178a43;
		}
		.brikpanel-wa-btn .brikpanel-wa-glyph { color: #fff; }
	</style>
	<?php
} );

// =============================================================================
// SETTINGS — WhatsApp on/off + role scoping (grouped under the Orders section)
// =============================================================================

/**
 * All registered roles as slug => translated display name, for the role
 * multiselect. Reuses the Access control collector when available so the two
 * role pickers stay identical; falls back to a local copy otherwise.
 *
 * @return array<string,string>
 */
function brikpanel_whatsapp_collect_roles() {
	if ( function_exists( 'brikpanel_access_collect_roles' ) ) {
		return brikpanel_access_collect_roles();
	}
	$out = [];
	if ( function_exists( 'wp_roles' ) ) {
		foreach ( wp_roles()->roles as $slug => $info ) {
			$out[ $slug ] = isset( $info['name'] ) ? translate_user_role( $info['name'] ) : $slug;
		}
	}
	return $out;
}

/**
 * Place the WhatsApp settings title under the existing "Orders" sub-nav section.
 */
add_filter( 'brikpanel_settings_title_section_map', function ( $map ) {
	if ( is_array( $map ) ) {
		$map['brk_whatsapp_title'] = 'orders';
	}
	return $map;
} );

/**
 * Register the WhatsApp module settings (global switch + hidden roles).
 */
add_filter( 'brikpanel_settings_fields', function ( $fields ) {
	if ( ! is_array( $fields ) ) {
		return $fields;
	}

	$block = [
		[
			'name' => __( 'WhatsApp', 'brikpanel' ),
			'type' => 'title',
			'id'   => 'brk_whatsapp_title',
			'desc' => __( 'A one-click WhatsApp shortcut for reaching a customer about their order: a small WhatsApp icon on the orders list and a "Message on WhatsApp" button under the billing address on the order edit screen. It opens a chat pre-targeting the order\'s billing phone. Shown to everyone who can manage WooCommerce orders by default.', 'brikpanel' ),
		],
		[
			'name'    => __( 'WhatsApp order shortcut', 'brikpanel' ),
			'id'      => BRIKPANEL_WHATSAPP_OPT_ENABLED,
			'type'    => 'checkbox',
			'desc'    => __( 'Show the WhatsApp icon on the orders list and the "Message on WhatsApp" button on the order edit screen. Turn this off to remove both everywhere. On by default.', 'brikpanel' ),
			'default' => 'yes',
		],
		[
			'name'     => __( 'Hide WhatsApp shortcut from roles', 'brikpanel' ),
			'id'       => BRIKPANEL_WHATSAPP_OPT_HIDDEN_ROLES,
			'type'     => 'multiselect',
			'class'    => 'wc-enhanced-select',
			'desc'     => __( 'Users with any of the selected roles no longer see the WhatsApp shortcut, while everyone else keeps it. A user hidden this way can switch it back on for their own account from their WordPress profile page. Leave empty to show it to everyone who can manage WooCommerce orders.', 'brikpanel' ),
			'desc_tip' => true,
			'options'  => brikpanel_whatsapp_collect_roles(),
			'default'  => [],
		],
		[
			'name'        => __( 'Message', 'brikpanel' ),
			'id'          => BRIKPANEL_WHATSAPP_OPT_ORDER_MESSAGE,
			'type'        => 'textarea',
			'desc'        => sprintf(
				/* translators: %s: comma-separated list of placeholder tags, e.g. {order_number}, {order_status}. */
				__( 'Placeholders: %s', 'brikpanel' ),
				implode( ', ', array_keys( brikpanel_whatsapp_order_placeholder_help() ) )
			),
			'desc_tip'    => __( 'WhatsApp opens with this text already typed in, so you only have to press send - nothing goes out on its own. Write it in any language, and use line breaks freely. {order_items} lists every product on its own line, so give it a line of its own. Clear this field to go back to opening an empty conversation.', 'brikpanel' ),
			'placeholder' => brikpanel_whatsapp_order_default_message(),
			'default'     => brikpanel_whatsapp_order_default_message(),
			'css'         => 'width:340px;height:90px;',
		],
		[
			// The id is the option this card writes, not a name of its own, so
			// the settings export walker picks the per-status messages up with
			// every other setting. (Nothing is posted under this name — the
			// card's own inputs are brikpanel_whatsapp_status[<slug>][…] — so
			// WooCommerce's generic save loop skips it and leaves the option to
			// the dedicated handler below.)
			'type' => 'brikpanel_whatsapp_status_messages',
			'id'   => BRIKPANEL_WHATSAPP_OPT_ORDER_STATUSES,
		],
		[
			'type' => 'sectionend',
			'id'   => 'brk_whatsapp_title',
		],
	];

	return array_merge( $fields, $block );
}, 8 );

/**
 * The placeholders offered in the UI helper, as token => human description.
 *
 * Intentionally the same vocabulary as the status-change emails helper
 * (brikpanel_status_email_placeholder_help), so a merchant who has written one
 * already knows the other. Only the resolved values differ: plain text here,
 * HTML there.
 *
 * @return array<string,string>
 */
function brikpanel_whatsapp_order_placeholder_help() {
	return [
		'{customer_first_name}' => __( 'Customer first name', 'brikpanel' ),
		'{customer_full_name}'  => __( 'Customer full name', 'brikpanel' ),
		'{order_number}'        => __( 'Order number', 'brikpanel' ),
		'{order_status}'        => __( 'Current status name', 'brikpanel' ),
		'{order_date}'          => __( 'Order date', 'brikpanel' ),
		'{order_total}'         => __( 'Order total', 'brikpanel' ),
		'{order_items}'         => __( 'List of items, one per line', 'brikpanel' ),
		'{order_view_url}'      => __( 'Order view link', 'brikpanel' ),
		'{billing_email}'       => __( 'Customer email', 'brikpanel' ),
		'{site_title}'          => __( 'Store name', 'brikpanel' ),
	];
}

/**
 * Render the "Message per order status" card: one collapsible block per order
 * status, each with an on/off toggle and its own draft.
 *
 * Reuses the card markup and styling of the status-change emails card
 * (front-end/order-statuses/brikpanel-status-emails.*): the same .bp-cse-*
 * classes, the same expand/collapse and token-insert behaviour, and the same
 * "close the table WooCommerce opened, emit the card, reopen an empty one for
 * the trailing sectionend" dance. Both stylesheets are already enqueued for the
 * whole BrikPanel settings tab, so nothing extra is loaded for this section.
 */
function brikpanel_render_whatsapp_status_messages_field() {
	$statuses = brikpanel_whatsapp_order_statuses();
	$saved    = brikpanel_whatsapp_order_status_messages();
	$help     = brikpanel_whatsapp_order_placeholder_help();
	$custom   = function_exists( 'brikpanel_get_custom_order_statuses' ) ? brikpanel_get_custom_order_statuses() : [];

	// What an empty box falls back to, shown as the placeholder. Trimmed to one
	// readable line: the general message can run to a thousand characters.
	$fallback = trim( preg_replace( '/\s+/u', ' ', brikpanel_whatsapp_order_general_message() ) );
	if ( brikpanel_strlen( $fallback ) > 90 ) {
		$fallback = rtrim( brikpanel_substr( $fallback, 0, 90 ) ) . '…';
	}
	?>
	</table>
	<section class="bp-cse-card">
		<header class="bp-cos-card__head">
			<div>
				<h3 class="bp-cos-card__title"><?php esc_html_e( 'Message per order status', 'brikpanel' ); ?></h3>
				<p class="bp-cos-card__sub"><?php esc_html_e( 'Say something different depending on where the order stands - one message while it is being prepared, another while it is still awaiting payment. Switch a status on and write its message; every status you leave off uses the general message above.', 'brikpanel' ); ?></p>
			</div>
		</header>

		<?php if ( ! $statuses ) : ?>
			<p class="bp-cos-empty"><?php esc_html_e( 'No order statuses are registered yet.', 'brikpanel' ); ?></p>
		<?php else : ?>
			<div class="bp-cse-list">
				<?php foreach ( $statuses as $slug => $label ) :
					$cfg     = isset( $saved[ $slug ] ) ? $saved[ $slug ] : [];
					$enabled = ! empty( $cfg['enabled'] );
					$message = isset( $cfg['message'] ) ? $cfg['message'] : '';
					// BrikPanel's own statuses carry a colour; core and
					// third-party ones get a neutral dot rather than a made-up one.
					$color   = isset( $custom[ $slug ]['color'] ) ? $custom[ $slug ]['color'] : '#8a8a8a';
					$base    = 'brikpanel_whatsapp_status[' . esc_attr( $slug ) . ']';
					$field   = 'bp-wam-' . $slug;
					?>
					<div class="bp-cse-item<?php echo $enabled ? ' is-on' : ''; ?>" data-cse-item>
						<div class="bp-cse-head" data-cse-toggle role="button" tabindex="0" aria-expanded="false">
							<span class="bp-cos-dot" style="background:<?php echo esc_attr( $color ); ?>"></span>
							<span class="bp-cse-title"><?php echo esc_html( $label ); ?></span>
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
								<div class="bp-cse-field">
									<label class="bp-cse-label" for="<?php echo esc_attr( $field ); ?>"><?php esc_html_e( 'Message', 'brikpanel' ); ?></label>
									<textarea id="<?php echo esc_attr( $field ); ?>" class="bp-cse-textarea" name="<?php echo $base; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped above. ?>[message]" rows="5" data-cse-insertable placeholder="<?php echo esc_attr( $fallback ); ?>"><?php echo esc_textarea( $message ); ?></textarea>
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
add_action( 'woocommerce_admin_field_brikpanel_whatsapp_status_messages', 'brikpanel_render_whatsapp_status_messages_field' );

/**
 * Keep the general message plain, single-purpose text.
 *
 * WooCommerce would run a textarea through wp_kses_post, which keeps markup that
 * WhatsApp would only ever show as literal angle brackets. Mirrors the
 * abandoned-cart popup sanitiser: strip tags, strip control characters other
 * than the line breaks we want to preserve, then cut by characters.
 *
 * @param mixed  $value     Value WooCommerce resolved.
 * @param array  $option    Field definition.
 * @param string $raw_value Raw posted value.
 * @return string
 */
function brikpanel_whatsapp_sanitize_message( $value, $option, $raw_value ) {
	return brikpanel_whatsapp_clean_message( $raw_value );
}
add_filter( 'woocommerce_admin_settings_sanitize_option_' . BRIKPANEL_WHATSAPP_OPT_ORDER_MESSAGE, 'brikpanel_whatsapp_sanitize_message', 10, 3 );

/**
 * Shared cleaner for both the general message and the per-status ones.
 *
 * The control-character class deliberately leaves 0x0A (LF) alone; CR is folded
 * into LF first so a browser's CRLF does not survive as a stray %0D in the URL.
 *
 * @param string $raw
 * @return string
 */
function brikpanel_whatsapp_clean_message( $raw ) {
	$clean = wp_strip_all_tags( (string) $raw );
	$clean = brikpanel_whatsapp_normalise_newlines( $clean );
	$clean = preg_replace( '/[\x00-\x09\x0B-\x1F\x7F]/', '', $clean );
	$clean = trim( (string) $clean );

	// Count characters, not bytes: a byte-wise cut lands mid-character in
	// Arabic, Turkish or any other non-ASCII text and corrupts the tail.
	return brikpanel_substr( $clean, 0, 1000 );
}

/**
 * Persist the per-status messages.
 *
 * Runs at priority 12, after WooCommerce has saved the section's regular fields,
 * and only for the Orders section so posting any other section never touches
 * this option. Statuses that no longer exist are dropped on the way through.
 */
add_action( 'woocommerce_update_options_brikpanel', function () {
	if ( ! function_exists( 'brikpanel_settings_get_current_section' )
		|| 'orders' !== brikpanel_settings_get_current_section() ) {
		return;
	}
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		return;
	}

	$posted = isset( $_POST['brikpanel_whatsapp_status'] ) && is_array( $_POST['brikpanel_whatsapp_status'] )
		? wp_unslash( $_POST['brikpanel_whatsapp_status'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- each field sanitised individually below.
		: [];

	$valid = array_keys( brikpanel_whatsapp_order_statuses() );
	$clean = [];

	foreach ( $posted as $slug => $cfg ) {
		$slug = sanitize_key( (string) $slug );
		if ( '' === $slug || ! is_array( $cfg ) || ! in_array( $slug, $valid, true ) ) {
			continue;
		}
		$message = isset( $cfg['message'] ) ? brikpanel_whatsapp_clean_message( $cfg['message'] ) : '';
		$enabled = ! empty( $cfg['enabled'] );

		// Nothing written and switched off is the default state; not storing it
		// keeps the option from growing a row for every status a merchant
		// merely scrolled past.
		if ( ! $enabled && '' === $message ) {
			continue;
		}
		$clean[ $slug ] = [
			'enabled' => $enabled,
			'message' => $message,
		];
	}

	update_option( BRIKPANEL_WHATSAPP_OPT_ORDER_STATUSES, $clean );
}, 12 );

// =============================================================================
// PER-USER OPT-IN — profile checkbox for a user whose role is hidden by default
// =============================================================================

/**
 * Render the "show it for my account" checkbox on the profile screen — but only
 * for a user who could otherwise see the shortcut (has `manage_woocommerce`) and
 * whose role is currently in the hidden list, so there is something to opt into.
 *
 * @param WP_User $user The profile being viewed/edited.
 */
function brikpanel_whatsapp_render_profile_field( $user ) {
	if ( ! brikpanel_whatsapp_module_enabled() ) {
		return;
	}
	if ( ! $user instanceof WP_User || ! user_can( $user, 'manage_woocommerce' ) ) {
		return;
	}
	if ( ! brikpanel_whatsapp_user_is_hidden_by_role( $user ) ) {
		return;
	}
	$optin = get_user_meta( $user->ID, BRIKPANEL_WHATSAPP_USER_OPTIN_META, true ) === 'yes';
	?>
	<h2><?php esc_html_e( 'WhatsApp order shortcut', 'brikpanel' ); ?></h2>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><?php esc_html_e( 'WhatsApp shortcut', 'brikpanel' ); ?></th>
			<td>
				<label>
					<input type="checkbox" name="brikpanel_whatsapp_optin" value="yes" <?php checked( $optin ); ?> />
					<?php esc_html_e( 'Show the WhatsApp order shortcut for my account', 'brikpanel' ); ?>
				</label>
				<p class="description"><?php esc_html_e( 'Your role has the WhatsApp order shortcut turned off. Tick this to show the WhatsApp icon on the orders list and the button on the order edit screen for your account only.', 'brikpanel' ); ?></p>
			</td>
		</tr>
	</table>
	<?php
}
add_action( 'show_user_profile', 'brikpanel_whatsapp_render_profile_field' );
add_action( 'edit_user_profile', 'brikpanel_whatsapp_render_profile_field' );

/**
 * Save the per-user opt-in. WordPress core verifies the profile-update nonce
 * before these hooks fire. The opt-in is only stored for a user who is actually
 * hidden by role, so a stale POST can never leave an orphan meta behind.
 *
 * @param int $user_id
 */
function brikpanel_whatsapp_save_profile_field( $user_id ) {
	if ( ! current_user_can( 'edit_user', $user_id ) ) {
		return;
	}
	$user = get_user_by( 'id', $user_id );
	if ( ! $user || ! user_can( $user, 'manage_woocommerce' ) || ! brikpanel_whatsapp_user_is_hidden_by_role( $user ) ) {
		return;
	}
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- core verifies the update-user nonce before these hooks run.
	$opt_in = isset( $_POST['brikpanel_whatsapp_optin'] ) && $_POST['brikpanel_whatsapp_optin'] === 'yes';
	if ( $opt_in ) {
		update_user_meta( $user_id, BRIKPANEL_WHATSAPP_USER_OPTIN_META, 'yes' );
	} else {
		delete_user_meta( $user_id, BRIKPANEL_WHATSAPP_USER_OPTIN_META );
	}
}
add_action( 'personal_options_update', 'brikpanel_whatsapp_save_profile_field' );
add_action( 'edit_user_profile_update', 'brikpanel_whatsapp_save_profile_field' );
