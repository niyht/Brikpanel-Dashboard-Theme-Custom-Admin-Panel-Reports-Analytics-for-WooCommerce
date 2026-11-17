<?php
/**
 * Customer returns: WooCommerce > Settings > BrikPanel > Returns.
 *
 * Six settings approved by the owner on 14 November 2026 (Customer returns
 * off, Return window 14 days, Returnable statuses [Completed], Return
 * reasons, Photos optional, EU withdrawal off), plus two rows that are not
 * settings: the returns link with a Copy button, and a warning when the
 * Flexible Refund plugin is active too.
 *
 * Registered while the module is off, so the switch stays reachable.
 *
 * @package BrikPanel
 * @since   3.3.52
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'woocommerce_get_sections_brikpanel', 'brikpanel_returns_settings_section' );
add_filter( 'brikpanel_settings_section_groups', 'brikpanel_returns_settings_group' );
add_filter( 'brikpanel_settings_title_section_map', 'brikpanel_returns_settings_title_map' );
add_filter( 'brikpanel_settings_section_icons', 'brikpanel_returns_settings_icon' );
add_filter( 'brikpanel_settings_fields', 'brikpanel_returns_settings_fields' );
add_action( 'woocommerce_admin_field_brikpanel_returns_link', 'brikpanel_returns_render_link_row' );
add_action( 'woocommerce_admin_field_brikpanel_returns_fr_note', 'brikpanel_returns_render_fr_row' );
add_filter( 'woocommerce_admin_settings_sanitize_option_brikpanel_returns_reasons', 'brikpanel_returns_sanitize_reasons' );
add_filter( 'woocommerce_admin_settings_sanitize_option_brikpanel_returns_window_days', 'brikpanel_returns_sanitize_window' );
add_filter( 'woocommerce_admin_settings_sanitize_option_brikpanel_returns_statuses', 'brikpanel_returns_sanitize_statuses' );
add_action( 'update_option_brikpanel_returns_enabled', 'brikpanel_returns_on_enabled_change', 10, 2 );
add_action( 'add_option_brikpanel_returns_enabled', 'brikpanel_returns_on_enabled_added', 10, 2 );
add_filter( 'brikpanel_module_pages', 'brikpanel_returns_module_page' );

/**
 * The Returns screen in the module registry: while returns are off, its
 * address opens the "Returns is off" card with "Turn it on in Settings"
 * (includes/brikpanel-module-pages.php, rule C5).
 *
 * @param array $pages Slug => entry.
 * @return array
 */
function brikpanel_returns_module_page( $pages ) {
	$pages['brikpanel-returns'] = array(
		'label'          => __( 'Returns', 'brikpanel' ),
		'enabled'        => 'brikpanel_returns_enabled',
		'cap'            => 'edit_shop_orders',
		'section'        => 'returns',
		'option'         => 'brikpanel_returns_enabled',
		'fallback'       => function_exists( 'brikpanel_wc_orders_list_url' ) ? brikpanel_wc_orders_list_url() : admin_url( 'edit.php?post_type=shop_order' ),
		'fallback_label' => __( 'Open the orders list', 'brikpanel' ),
	);
	return $pages;
}

/**
 * @param array $sections Sections.
 * @return array
 */
function brikpanel_returns_settings_section( $sections ) {
	$sections['returns'] = __( 'Returns', 'brikpanel' );
	return $sections;
}

/**
 * Sit right after Order statuses (or Orders) in the Store group.
 *
 * @param array $groups Groups.
 * @return array
 */
function brikpanel_returns_settings_group( $groups ) {
	if ( isset( $groups['store']['sections'] ) && is_array( $groups['store']['sections'] ) ) {
		$list = $groups['store']['sections'];
		$pos  = array_search( 'order-statuses', $list, true );
		if ( false === $pos ) {
			$pos = array_search( 'orders', $list, true );
		}
		if ( false === $pos ) {
			$list[] = 'returns';
		} else {
			array_splice( $list, $pos + 1, 0, 'returns' );
		}
		$groups['store']['sections'] = $list;
	}
	return $groups;
}

/**
 * @param array $map Title id => section.
 * @return array
 */
function brikpanel_returns_settings_title_map( $map ) {
	$map['brk_returns_title'] = 'returns';
	return $map;
}

/**
 * @param array $paths Section => inner SVG markup.
 * @return array
 */
function brikpanel_returns_settings_icon( $paths ) {
	// i18n-ignore: inline SVG path data, not user-facing text
	$paths['returns'] = '<path d="M9 14 4 9l5-5"/><path d="M4 9h10.5a5.5 5.5 0 0 1 0 11H11"/>';
	return $paths;
}

/**
 * Statuses a merchant may pick: everything but the ones that mean the order
 * was never paid or is already over.
 *
 * @return array<string,string>
 */
function brikpanel_returns_status_choices() {
	$out   = array();
	$never = array( 'wc-pending', 'wc-failed', 'wc-cancelled', 'wc-refunded', 'wc-checkout-draft' );
	foreach ( wc_get_order_statuses() as $key => $label ) {
		if ( ! in_array( $key, $never, true ) ) {
			$out[ $key ] = $label;
		}
	}
	return $out;
}

/**
 * @param array $fields Settings fields.
 * @return array
 */
function brikpanel_returns_settings_fields( $fields ) {
	$fields[] = array(
		'title' => __( 'Returns', 'brikpanel' ),
		'type'  => 'title',
		'id'    => 'brk_returns_title',
		'desc'  => __( 'Customers ask for a return from their account, choose the items, a reason and add photos. You approve, mark the items as received and refund in one place: Returns, next to Orders.', 'brikpanel' ),
	);
	$fields[] = array(
		'title'   => __( 'Customer returns', 'brikpanel' ),
		'desc'    => __( 'Let customers request returns from their account', 'brikpanel' ),
		'id'      => 'brikpanel_returns_enabled',
		'type'    => 'checkbox',
		'default' => 'no',
	);
	$fields[] = array(
		'title'             => __( 'Return window', 'brikpanel' ),
		'desc'              => __( 'days after the order is completed', 'brikpanel' ),
		'desc_tip'          => __( '0 means no limit.', 'brikpanel' ),
		'id'                => 'brikpanel_returns_window_days',
		'type'              => 'number',
		'default'           => (string) BRIKPANEL_RETURNS_DEFAULT_WINDOW,
		'custom_attributes' => array(
			'min'  => 0,
			'max'  => 3650,
			'step' => 1,
		),
		'css'               => 'width:90px;',
	);
	$fields[] = array(
		'title'    => __( 'Returnable statuses', 'brikpanel' ),
		'desc_tip' => __( 'Orders in these statuses can be returned. Add a status such as "Delivered" if your store uses one.', 'brikpanel' ),
		'id'       => 'brikpanel_returns_statuses',
		'type'     => 'multiselect',
		'class'    => 'wc-enhanced-select',
		'default'  => array( 'wc-completed' ),
		'options'  => brikpanel_returns_status_choices(),
		'css'      => 'width:340px;',
	);
	$fields[] = array(
		'title'    => __( 'Return reasons', 'brikpanel' ),
		'desc'     => __( 'One reason per line. Customers choose one of them.', 'brikpanel' ),
		'id'       => 'brikpanel_returns_reasons',
		'type'     => 'textarea',
		'default'  => implode( "\n", brikpanel_returns_default_reasons() ),
		'css'      => 'width:340px;height:120px;',
	);
	$fields[] = array(
		'title'   => __( 'Photos', 'brikpanel' ),
		'desc'    => __( 'Customers can add up to 5 photos of the items.', 'brikpanel' ),
		'id'      => 'brikpanel_returns_photos',
		'type'    => 'select',
		'default' => 'optional',
		'options' => array(
			'off'      => __( 'Off', 'brikpanel' ),
			'optional' => __( 'Optional', 'brikpanel' ),
			'required' => __( 'Required', 'brikpanel' ),
		),
		'css'     => 'width:340px;',
	);
	$fields[] = array(
		'title'   => __( 'EU withdrawal', 'brikpanel' ),
		'desc'    => __( 'Use the EU "Withdraw from contract here" wording and steps', 'brikpanel' )
			. '<span class="brikpanel-returns-desc">' . esc_html__( 'Required for stores selling to consumers in the EU since 19 June 2026. Customers can withdraw without a reason, also before the order is completed, until at least 14 days after it is completed, and confirm it in a second step. They get an email with the date and time. If your deliveries take longer, add the delivery time to the return window.', 'brikpanel' ) . '</span>',
		'id'      => 'brikpanel_returns_eu_withdrawal',
		'type'    => 'checkbox',
		'default' => 'no',
	);
	$fields[] = array(
		'type' => 'brikpanel_returns_link',
		'id'   => 'brikpanel_returns_link_field',
	);
	if ( brikpanel_returns_flexible_refund_active() ) {
		$fields[] = array(
			'type' => 'brikpanel_returns_fr_note',
			'id'   => 'brikpanel_returns_fr_note_field',
		);
	}
	$fields[] = array(
		'type' => 'sectionend',
		'id'   => 'brk_returns_title',
	);
	return $fields;
}

/**
 * The address of the returns page: My Account with ?bp-return=1. Logged-out
 * visitors get the order number + email form there, logged-in customers the
 * orders they can return.
 *
 * @return string
 */
function brikpanel_returns_link() {
	$page = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : home_url( '/' );
	return add_query_arg( 'bp-return', '1', $page );
}

/**
 * Whether the Flexible Refund plugin (free or PRO) is active on this site.
 *
 * @return bool
 */
function brikpanel_returns_flexible_refund_active() {
	if ( ! function_exists( 'is_plugin_active' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	return is_plugin_active( 'flexible-refund-and-return-order-for-woocommerce/flexible-refund-and-return-order-for-woocommerce.php' )
		|| is_plugin_active( 'flexible-refunds-pro/flexible-refunds-pro.php' );
}

/**
 * The "Returns link" row: the address and a Copy button.
 *
 * @param array $field Field (unused).
 * @return void
 */
function brikpanel_returns_render_link_row( $field ) {
	unset( $field );
	$url = brikpanel_returns_link();
	?>
	<tr valign="top">
		<th scope="row" class="titledesc">
			<label for="brikpanel-returns-link"><?php esc_html_e( 'Returns link', 'brikpanel' ); ?></label>
		</th>
		<td class="forminp">
			<div class="brikpanel-returns-link">
				<input type="text" id="brikpanel-returns-link" class="brikpanel-control brikpanel-returns-link__input" value="<?php echo esc_attr( $url ); ?>" readonly>
				<button type="button" class="brikpanel-btn brikpanel-btn--secondary" data-bp-returns-copy="brikpanel-returns-link" data-label="<?php esc_attr_e( 'Copy', 'brikpanel' ); ?>" data-done="<?php esc_attr_e( 'Copied', 'brikpanel' ); ?>"><?php esc_html_e( 'Copy', 'brikpanel' ); ?></button>
			</div>
			<p class="description"><?php esc_html_e( 'Customers without an account return their order here with the order number and email. Put this link in your store footer, for example as "Withdraw from contract here" for EU customers.', 'brikpanel' ); ?></p>
			<style>
				.brikpanel-returns-link { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; max-width: 560px; }
				.brikpanel-returns-link .brikpanel-returns-link__input { flex: 1 1 16rem; min-width: 0; }
				.brikpanel-returns-desc { display: block; max-width: 560px; margin-top: 6px; color: #616161; font-size: 13px; line-height: 1.5; }
			</style>
			<?php
			wp_print_inline_script_tag(
				'document.addEventListener("click",function(e){var b=e.target.closest&&e.target.closest("[data-bp-returns-copy]");if(!b){return;}var i=document.getElementById(b.getAttribute("data-bp-returns-copy"));if(!i){return;}var done=function(){b.textContent=b.getAttribute("data-done");setTimeout(function(){b.textContent=b.getAttribute("data-label");},1800);};var pick=function(){i.focus();i.select();};if(navigator.clipboard&&navigator.clipboard.writeText){navigator.clipboard.writeText(i.value).then(done,pick);}else{pick();}});'
			);
			?>
		</td>
	</tr>
	<?php
}

/**
 * The warning shown when Flexible Refund is active too.
 *
 * @param array $field Field (unused).
 * @return void
 */
function brikpanel_returns_render_fr_row( $field ) {
	unset( $field );
	?>
	<tr valign="top">
		<th scope="row" class="titledesc"><?php esc_html_e( 'Flexible Refund', 'brikpanel' ); ?></th>
		<td class="forminp">
			<p class="brikpanel-override-note"><?php esc_html_e( 'The Flexible Refund plugin is also active. With both turned on, customers see two return buttons on their orders. Keep one of them.', 'brikpanel' ); ?></p>
		</td>
	</tr>
	<?php
}

/**
 * Keep the reasons clean; store nothing while they are the defaults, so a
 * multilingual store shows them in each customer's language.
 *
 * @param mixed $value Posted value.
 * @return string
 */
function brikpanel_returns_sanitize_reasons( $value ) {
	$lines = array();
	foreach ( preg_split( '/\r\n|\r|\n/', (string) $value ) as $line ) {
		$line = trim( wp_strip_all_tags( $line ) );
		if ( '' !== $line ) {
			$lines[] = function_exists( 'mb_substr' ) ? mb_substr( $line, 0, 120 ) : substr( $line, 0, 120 );
		}
	}
	$lines = array_slice( array_values( array_unique( $lines ) ), 0, 20 );
	if ( ! $lines || $lines === brikpanel_returns_default_reasons() ) {
		return '';
	}
	return implode( "\n", $lines );
}

/**
 * @param mixed $value Posted value.
 * @return string
 */
function brikpanel_returns_sanitize_window( $value ) {
	$days = is_numeric( $value ) ? (int) $value : BRIKPANEL_RETURNS_DEFAULT_WINDOW;
	return (string) max( 0, min( 3650, $days ) );
}

/**
 * @param mixed $value Posted value.
 * @return string[]
 */
function brikpanel_returns_sanitize_statuses( $value ) {
	$choices = brikpanel_returns_status_choices();
	$out     = array();
	foreach ( (array) $value as $status ) {
		$status = sanitize_text_field( (string) $status );
		if ( isset( $choices[ $status ] ) ) {
			$out[] = $status;
		}
	}
	return $out ? array_values( array_unique( $out ) ) : array( 'wc-completed' );
}

/**
 * Turning returns on creates the table at once (before the version bump has
 * run brikpanel_create_table()) and refreshes the counts.
 *
 * @param mixed $old   Old value.
 * @param mixed $value New value.
 * @return void
 */
function brikpanel_returns_on_enabled_change( $old, $value ) {
	unset( $old );
	if ( 'yes' === $value && class_exists( 'Brikpanel_Returns_Store' ) ) {
		Brikpanel_Returns_Store::ensure_table();
		Brikpanel_Returns_Store::counts( true );
	}
}

/**
 * @param string $option Option name.
 * @param mixed  $value  Value.
 * @return void
 */
function brikpanel_returns_on_enabled_added( $option, $value ) {
	brikpanel_returns_on_enabled_change( null, $value );
	unset( $option );
}
