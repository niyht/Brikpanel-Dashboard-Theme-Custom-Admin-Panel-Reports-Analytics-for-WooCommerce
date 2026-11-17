<?php
/**
 * Customer returns: the four email classes.
 *
 * Loaded only from the woocommerce_email_classes callback, when WC_Email
 * exists. One shared template (templates/emails/brikpanel-return.php and its
 * plain text twin) prints every one of them; the classes decide the words.
 * A theme can override the template in yourtheme/woocommerce/emails/.
 *
 * @package BrikPanel
 * @since   3.3.52
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'WC_Email', false ) && ! class_exists( 'Brikpanel_Email_Return_Base', false ) ) {

	/**
	 * What the four emails share.
	 */
	abstract class Brikpanel_Email_Return_Base extends WC_Email {

		/** @var array|null The return the email is about. */
		public $ret = null;

		/**
		 * @param string $id          Email id.
		 * @param bool   $to_customer Customer email.
		 */
		public function brikpanel_setup( $id, $to_customer ) {
			$this->id             = $id;
			$this->customer_email = $to_customer;
			$this->template_base  = trailingslashit( BRIKPANEL_PATH ) . 'templates/';
			$this->template_html  = 'emails/brikpanel-return.php';
			$this->template_plain = 'emails/plain/brikpanel-return.php';
			$this->placeholders   = array(
				'{order_date}'    => '',
				'{order_number}'  => '',
				'{return_number}' => '',
			);
			if ( property_exists( $this, 'email_group' ) ) {
				$this->email_group = 'brikpanel-returns';
			}
		}

		/**
		 * Load the return and its order, then send.
		 *
		 * @param int $return_id Return id.
		 * @return void
		 */
		public function trigger( $return_id ) {
			$this->setup_locale();
			$ret   = Brikpanel_Returns_Store::get( $return_id, true );
			$order = $ret ? wc_get_order( $ret['order_id'] ) : false;
			if ( $ret && $order ) {
				$this->ret                              = $ret;
				$this->object                           = $order;
				$this->placeholders['{order_date}']    = wc_format_datetime( $order->get_date_created() );
				$this->placeholders['{order_number}']  = $order->get_order_number();
				$this->placeholders['{return_number}'] = (string) $ret['id'];
				if ( $this->customer_email ) {
					$this->recipient = $ret['email'] ? $ret['email'] : $order->get_billing_email();
				}
				if ( $this->is_enabled() && $this->get_recipient() ) {
					$this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
				}
			}
			$this->restore_locale();
		}

		/**
		 * The paragraph under the heading.
		 *
		 * @return string
		 */
		abstract public function brikpanel_intro();

		/**
		 * @return array Template arguments.
		 */
		protected function brikpanel_args( $plain ) {
			$ret = is_array( $this->ret ) ? $this->ret : array();
			return array(
				'email_heading'      => $this->get_heading(),
				'additional_content' => $this->get_additional_content(),
				'sent_to_admin'      => ! $this->customer_email,
				'plain_text'         => $plain,
				'email'              => $this,
				'order'              => $this->object,
				'ret'                => $ret,
				'intro'              => $this->brikpanel_intro(),
				'message'            => isset( $ret['admin_message'] ) && in_array( $this->id, array( 'brikpanel_return_approved', 'brikpanel_return_rejected' ), true ) ? (string) $ret['admin_message'] : '',
				'view_url'           => $this->customer_email && $ret && $this->object ? brikpanel_returns_customer_url( $ret, $this->object ) : '',
				'admin_url'          => ! $this->customer_email && $ret ? admin_url( 'admin.php?page=brikpanel-returns&return=' . (int) $ret['id'] ) : '',
				'moment'             => isset( $ret['submitted_at'] ) ? brikpanel_returns_format_moment( $ret['submitted_at'] ) : '',
			);
		}

		/** @return string */
		public function get_content_html() {
			return wc_get_template_html( $this->template_html, $this->brikpanel_args( false ), '', $this->template_base );
		}

		/** @return string */
		public function get_content_plain() {
			return wc_get_template_html( $this->template_plain, $this->brikpanel_args( true ), '', $this->template_base );
		}

		/** @return string */
		public function get_default_additional_content() {
			return '';
		}

		/**
		 * The order is set when the email is about an order (WooCommerce's
		 * preview and the "order" in its template filters expect it).
		 *
		 * @return bool
		 */
		protected function brikpanel_is_withdrawal() {
			return is_array( $this->ret ) && ! empty( $this->ret['withdrawal'] );
		}
	}

	/**
	 * To the store: a new request.
	 */
	class Brikpanel_Email_Return_New extends Brikpanel_Email_Return_Base {

		public function __construct() {
			$this->brikpanel_setup( 'brikpanel_return_new', false );
			$this->title       = __( 'New return request', 'brikpanel' );
			$this->description = __( 'Sent to the store when a customer asks for a return or withdraws from an order.', 'brikpanel' );
			add_action( 'brikpanel_return_created_notification', array( $this, 'trigger' ), 10, 1 );
			parent::__construct();
			$this->recipient = $this->get_option( 'recipient', get_option( 'admin_email' ) );
		}

		/** @return string */
		public function get_default_subject() {
			return __( '[{site_title}]: New return request #{return_number}', 'brikpanel' );
		}

		/** @return string */
		public function get_default_heading() {
			return __( 'New return request', 'brikpanel' );
		}

		/** @return string */
		public function brikpanel_intro() {
			$order = $this->object;
			$name  = $order instanceof WC_Order ? trim( $order->get_formatted_billing_full_name() ) : '';
			if ( '' === $name && is_array( $this->ret ) ) {
				$name = $this->ret['email'];
			}
			$number = $order instanceof WC_Order ? $order->get_order_number() : '';
			if ( $this->brikpanel_is_withdrawal() ) {
				/* translators: 1: customer name, 2: order number */
				$text = sprintf( __( '%1$s withdrew from the contract for order #%2$s.', 'brikpanel' ), $name, $number );
			} else {
				/* translators: 1: customer name, 2: order number */
				$text = sprintf( __( '%1$s asked to return items from order #%2$s.', 'brikpanel' ), $name, $number );
			}
			if ( $order instanceof WC_Order && in_array( $order->get_status(), array( 'processing', 'on-hold' ), true ) ) {
				$text .= ' ' . __( 'The order is not completed yet: stop the shipment if you still can.', 'brikpanel' );
			}
			return $text;
		}

		/**
		 * The recipient field, like WooCommerce's own admin emails.
		 *
		 * @return void
		 */
		public function init_form_fields() {
			parent::init_form_fields();
			$fields = array(
				'recipient' => array(
					'title'       => __( 'Recipients', 'brikpanel' ),
					'type'        => 'text',
					/* translators: %s: admin email */
					'description' => sprintf( __( 'Enter recipients (comma separated) for this email. Defaults to %s.', 'brikpanel' ), '<code>' . esc_attr( get_option( 'admin_email' ) ) . '</code>' ),
					'placeholder' => '',
					'default'     => '',
					'desc_tip'    => true,
				),
			);
			$this->form_fields = array_merge( array_slice( $this->form_fields, 0, 1, true ), $fields, array_slice( $this->form_fields, 1, null, true ) );
		}
	}

	/**
	 * To the customer: we have it (the EU withdrawal acknowledgement).
	 */
	class Brikpanel_Email_Return_Received extends Brikpanel_Email_Return_Base {

		public function __construct() {
			$this->brikpanel_setup( 'brikpanel_return_received', true );
			$this->title       = __( 'Return request received', 'brikpanel' );
			$this->description = __( 'Sent to the customer when their return request arrives. For an EU withdrawal it is the legally required acknowledgement and is always sent.', 'brikpanel' );
			add_action( 'brikpanel_return_created_notification', array( $this, 'trigger' ), 10, 1 );
			parent::__construct();
		}

		/** @return string */
		public function get_default_subject() {
			return __( 'We received your return request for order #{order_number}', 'brikpanel' );
		}

		/** @return string */
		public function get_default_heading() {
			return __( 'Return request received', 'brikpanel' );
		}

		/**
		 * A withdrawal gets its own words, whatever the store wrote for returns.
		 *
		 * @return string
		 */
		public function get_subject() {
			if ( $this->brikpanel_is_withdrawal() ) {
				return $this->format_string( __( 'Your withdrawal from order #{order_number}', 'brikpanel' ) );
			}
			return parent::get_subject();
		}

		/** @return string */
		public function get_heading() {
			if ( $this->brikpanel_is_withdrawal() ) {
				return $this->format_string( __( 'Withdrawal received', 'brikpanel' ) );
			}
			return parent::get_heading();
		}

		/**
		 * Art. 11a(4): the acknowledgement of a withdrawal cannot be switched off.
		 *
		 * @return bool
		 */
		public function is_enabled() {
			if ( $this->brikpanel_is_withdrawal() ) {
				return true;
			}
			return parent::is_enabled();
		}

		/** @return string */
		public function brikpanel_intro() {
			$moment = is_array( $this->ret ) ? brikpanel_returns_format_moment( $this->ret['submitted_at'] ) : '';
			if ( $this->brikpanel_is_withdrawal() ) {
				/* translators: %s: date and time */
				return sprintf( __( 'We received your withdrawal from the contract on %s. This email confirms it. Here is what you sent:', 'brikpanel' ), $moment );
			}
			/* translators: %s: date and time */
			return sprintf( __( 'We received your return request on %s. We will check it and let you know by email.', 'brikpanel' ), $moment );
		}
	}

	/**
	 * To the customer: approved.
	 */
	class Brikpanel_Email_Return_Approved extends Brikpanel_Email_Return_Base {

		public function __construct() {
			$this->brikpanel_setup( 'brikpanel_return_approved', true );
			$this->title       = __( 'Return approved', 'brikpanel' );
			$this->description = __( 'Sent to the customer when you approve a return, with your message (for example where to send the items).', 'brikpanel' );
			add_action( 'brikpanel_return_approved_notification', array( $this, 'trigger' ), 10, 1 );
			parent::__construct();
		}

		/** @return string */
		public function get_default_subject() {
			return __( 'Your return for order #{order_number} is approved', 'brikpanel' );
		}

		/** @return string */
		public function get_default_heading() {
			return __( 'Return approved', 'brikpanel' );
		}

		/** @return string */
		public function brikpanel_intro() {
			return __( 'Good news: your return is approved. Please follow the instructions below.', 'brikpanel' );
		}
	}

	/**
	 * To the customer: rejected.
	 */
	class Brikpanel_Email_Return_Rejected extends Brikpanel_Email_Return_Base {

		public function __construct() {
			$this->brikpanel_setup( 'brikpanel_return_rejected', true );
			$this->title       = __( 'Return rejected', 'brikpanel' );
			$this->description = __( 'Sent to the customer when you reject a return, with your reason.', 'brikpanel' );
			add_action( 'brikpanel_return_rejected_notification', array( $this, 'trigger' ), 10, 1 );
			parent::__construct();
		}

		/** @return string */
		public function get_default_subject() {
			return __( 'About your return for order #{order_number}', 'brikpanel' );
		}

		/** @return string */
		public function get_default_heading() {
			return __( 'Return not accepted', 'brikpanel' );
		}

		/** @return string */
		public function brikpanel_intro() {
			return __( 'We are sorry, we could not accept your return. Here is why:', 'brikpanel' );
		}
	}
}
