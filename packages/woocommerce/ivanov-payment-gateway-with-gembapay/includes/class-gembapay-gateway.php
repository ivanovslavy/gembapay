<?php
/**
 * GembaPay Payment Gateway
 *
 * @package GembaPay
 */

defined( 'ABSPATH' ) || exit;

/**
 * GembaPay Gateway Class
 */
class GembaPay_Gateway extends WC_Payment_Gateway {

	/**
	 * API instance
	 *
	 * @var GembaPay_API|null
	 */
	private $api = null;

	/**
	 * API key
	 *
	 * @var string
	 */
	public $api_key = '';

	/**
	 * Webhook secret
	 *
	 * @var string
	 */
	public $webhook_secret = '';

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->id                 = 'gembapay';
		$this->icon               = GEMBAPAY_PLUGIN_URL . 'assets/images/gembapay-logo.png';
		$this->has_fields         = false;
		$this->method_title       = __( 'GembaPay', 'ivanov-payment-gateway-with-gembapay' );
		$this->method_description = __( 'Accept cards (incl. Apple Pay and Google Pay) and PayPal with the GembaPay checkout. Refunds can be made from the order screen.', 'ivanov-payment-gateway-with-gembapay' );
		$this->supports           = array(
			'products',
			'refunds',
		);

		$this->init_form_fields();
		$this->init_settings();

		$this->title          = $this->get_option( 'title' );
		$this->description    = $this->get_option( 'description' );
		$this->enabled        = $this->get_option( 'enabled' );
		$this->api_key        = $this->get_option( 'api_key' );
		$this->webhook_secret = $this->get_option( 'webhook_secret' );

		if ( ! empty( $this->api_key ) ) {
			$this->api = new GembaPay_API( $this->api_key );
		}

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'thankyou_page' ) );
		add_action( 'woocommerce_email_before_order_table', array( $this, 'email_instructions' ), 10, 3 );
	}

	/**
	 * Initialize Gateway Settings Form Fields
	 */
	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'         => array(
				'title'   => __( 'Enable/Disable', 'ivanov-payment-gateway-with-gembapay' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable GembaPay', 'ivanov-payment-gateway-with-gembapay' ),
				'default' => 'no',
			),
			'title'           => array(
				'title'       => __( 'Title', 'ivanov-payment-gateway-with-gembapay' ),
				'type'        => 'text',
				'description' => __( 'Payment method title shown to customers.', 'ivanov-payment-gateway-with-gembapay' ),
				'default'     => __( 'Card, Apple Pay, Google Pay & PayPal', 'ivanov-payment-gateway-with-gembapay' ),
				'desc_tip'    => true,
			),
			'description'     => array(
				'title'       => __( 'Description', 'ivanov-payment-gateway-with-gembapay' ),
				'type'        => 'textarea',
				'description' => __( 'Payment method description shown to customers.', 'ivanov-payment-gateway-with-gembapay' ),
				'default'     => __( 'Pay securely with your card, Apple Pay, Google Pay or PayPal.', 'ivanov-payment-gateway-with-gembapay' ),
				'desc_tip'    => true,
			),
			'api_key'         => array(
				'title'       => __( 'API Key', 'ivanov-payment-gateway-with-gembapay' ),
				'type'        => 'password',
				'description' => __( 'Your GembaPay API key from the merchant dashboard. To refund from WooCommerce, give the key the "refunds:write" permission (GembaPay dashboard → API Keys → Permissions).', 'ivanov-payment-gateway-with-gembapay' ),
				'default'     => '',
				'desc_tip'    => false,
			),
			'webhook_secret'  => array(
				'title'       => __( 'Webhook Secret', 'ivanov-payment-gateway-with-gembapay' ),
				'type'        => 'password',
				'description' => __( 'Your webhook secret for verifying payment notifications.', 'ivanov-payment-gateway-with-gembapay' ),
				'default'     => '',
				'desc_tip'    => true,
			),
			'webhook_url'     => array(
				'title'       => __( 'Webhook URL', 'ivanov-payment-gateway-with-gembapay' ),
				'type'        => 'title',
				'description' => sprintf(
					/* translators: %s: webhook URL */
					__( 'Add this URL to your GembaPay merchant dashboard (Settings → Webhook Settings): %s In the dashboard under Webhooks, also switch on payment.refunded, payment.expired, dispute.created and dispute.closed so refunds, expired payments and disputes show up on the order.', 'ivanov-payment-gateway-with-gembapay' ),
					// WooCommerce builds the right URL for both pretty and plain permalinks.
					'<br><code>' . esc_url( WC()->api_request_url( 'gembapay_webhook' ) ) . '</code><br>'
				),
			),
			'payment_methods' => array(
				'title'       => __( 'Accepted Payment Methods', 'ivanov-payment-gateway-with-gembapay' ),
				'type'        => 'title',
				'description' => __( 'Cards, Apple Pay, Google Pay and Link (via Stripe), and PayPal.', 'ivanov-payment-gateway-with-gembapay' ),
			),
			'debug'           => array(
				'title'       => __( 'Debug Log', 'ivanov-payment-gateway-with-gembapay' ),
				'type'        => 'checkbox',
				'label'       => __( 'Enable logging', 'ivanov-payment-gateway-with-gembapay' ),
				'default'     => 'no',
				'description' => __( 'Logs GembaPay events to WooCommerce → Status → Logs (source: gembapay).', 'ivanov-payment-gateway-with-gembapay' ),
			),
		);
	}

	/**
	 * Check if gateway is available
	 *
	 * @return bool
	 */
	public function is_available() {
		if ( 'yes' !== $this->enabled || empty( $this->api_key ) ) {
			return false;
		}
		return parent::is_available();
	}

	/**
	 * Process the payment
	 *
	 * @param int $order_id Order ID.
	 * @return array
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );

		if ( ! $order || ! $this->api ) {
			wc_add_notice( __( 'Order not found.', 'ivanov-payment-gateway-with-gembapay' ), 'error' );
			return array( 'result' => 'failure' );
		}

		$gembapay_order_id = GembaPay_API::generate_order_id( $order_id );

		// Every GembaPay id this order ever had (a customer may start paying more than once).
		$all_ids   = array_filter( (array) $order->get_meta( '_gembapay_order_ids' ) );
		$all_ids[] = $gembapay_order_id;
		$order->update_meta_data( '_gembapay_order_id', $gembapay_order_id );
		$order->update_meta_data( '_gembapay_order_ids', array_slice( array_values( array_unique( $all_ids ) ), -20 ) );
		$order->save();

		$response = $this->api->create_payment(
			$gembapay_order_id,
			$order->get_total(),
			$order->get_currency(),
			sprintf(
				/* translators: %s: order number */
				__( 'Order #%s', 'ivanov-payment-gateway-with-gembapay' ),
				$order->get_order_number()
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->log( 'Payment creation failed: ' . $response->get_error_message() );
			wc_add_notice(
				__( 'Payment error: ', 'ivanov-payment-gateway-with-gembapay' ) . $response->get_error_message(),
				'error'
			);
			return array( 'result' => 'failure' );
		}

		if ( empty( $response['paymentUrl'] ) ) {
			$this->log( 'Payment URL not received' );
			wc_add_notice( __( 'Payment error: Could not create payment.', 'ivanov-payment-gateway-with-gembapay' ), 'error' );
			return array( 'result' => 'failure' );
		}

		$order->update_meta_data( '_gembapay_payment_url', esc_url_raw( $response['paymentUrl'] ) );
		$order->update_meta_data( '_gembapay_amount_usd', isset( $response['amountUsd'] ) ? sanitize_text_field( (string) $response['amountUsd'] ) : '' );
		$order->save();

		$order->update_status( 'pending', __( 'Awaiting GembaPay payment.', 'ivanov-payment-gateway-with-gembapay' ) );

		// No cart outside a browser checkout (REST, cron, CLI).
		if ( WC()->cart ) {
			WC()->cart->empty_cart();
		}

		$this->log( 'Payment created for order ' . $order_id );

		return array(
			'result'   => 'success',
			'redirect' => $response['paymentUrl'],
		);
	}

	/**
	 * Refund through the GembaPay API (full or partial). The API key needs "refunds:write".
	 * The GembaPay refund id is remembered on the order so the payment.refunded webhook for this
	 * same refund is not recorded a second time.
	 *
	 * @param int    $order_id Order ID.
	 * @param float  $amount   Amount to refund.
	 * @param string $reason   Refund reason.
	 * @return bool|WP_Error
	 */
	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || ! $this->api ) {
			return new WP_Error( 'gembapay_refund_error', __( 'GembaPay is not configured.', 'ivanov-payment-gateway-with-gembapay' ) );
		}
		$gembapay_order_id = $order->get_meta( '_gembapay_order_id' );
		// Whether the amount is still refundable is decided by GembaPay (it returns a clear error).
		if ( ! $gembapay_order_id ) {
			return new WP_Error( 'gembapay_refund_error', __( 'This order has no completed GembaPay payment to refund.', 'ivanov-payment-gateway-with-gembapay' ) );
		}

		// WooCommerce has already created the refund record when it calls us: the number of refunds
		// on the order + the amount make a key that is stable if the same request is retried.
		$idempotency_key = sprintf( 'wc-%d-%d-%s', $order->get_id(), count( $order->get_refunds() ), number_format( (float) $amount, 2, '.', '' ) );
		$refund          = $this->api->create_refund( $gembapay_order_id, null === $amount ? null : (float) $amount, (string) $reason, $idempotency_key );

		if ( is_wp_error( $refund ) ) {
			$data = $refund->get_error_data();
			$code = is_array( $data ) && isset( $data['code'] ) ? $data['code'] : '';
			$this->log( 'Refund failed for order ' . $order_id . ': ' . $refund->get_error_message(), 'error' );
			if ( 'missing_scope' === $code ) {
				return new WP_Error( 'gembapay_refund_error', __( 'Refund not allowed: give your GembaPay API key the "refunds:write" permission (GembaPay dashboard → API Keys → Permissions), then try again.', 'ivanov-payment-gateway-with-gembapay' ) );
			}
			/* translators: %s: error message from GembaPay */
			return new WP_Error( 'gembapay_refund_error', sprintf( __( 'GembaPay refund failed: %s', 'ivanov-payment-gateway-with-gembapay' ), $refund->get_error_message() ) );
		}

		$ids = array_filter( (array) $order->get_meta( '_gembapay_refund_ids' ) );
		if ( ! empty( $refund['id'] ) ) {
			$ids[] = sanitize_text_field( $refund['id'] );
			$order->update_meta_data( '_gembapay_refund_ids', array_values( array_unique( $ids ) ) );
		}
		$order->add_order_note(
			sprintf(
				/* translators: 1: amount, 2: currency, 3: refund status, 4: GembaPay refund id */
				__( 'Refunded %1$s %2$s via GembaPay (status: %3$s, refund ID: %4$s).', 'ivanov-payment-gateway-with-gembapay' ),
				isset( $refund['amount'] ) ? $refund['amount'] : $amount,
				isset( $refund['currency'] ) ? $refund['currency'] : $order->get_currency(),
				isset( $refund['status'] ) ? $refund['status'] : '',
				isset( $refund['id'] ) ? $refund['id'] : ''
			)
		);
		$order->save();
		$this->log( 'Refund ' . ( isset( $refund['id'] ) ? $refund['id'] : '' ) . ' created for order ' . $order_id );
		return true;
	}

	/**
	 * Human label for the way the customer paid.
	 *
	 * @param WC_Order $order Order object.
	 * @return string
	 */
	public static function payment_method_label( $order ) {
		$labels = array(
			'card'       => __( 'Card', 'ivanov-payment-gateway-with-gembapay' ),
			'apple_pay'  => 'Apple Pay',
			'google_pay' => 'Google Pay',
			'link'       => 'Link',
		);
		$method   = $order->get_meta( '_gembapay_payment_method' );
		$provider = $order->get_meta( '_gembapay_provider' );
		if ( 'paypal' === $provider ) {
			return 'PayPal';
		}
		if ( $method && isset( $labels[ $method ] ) ) {
			return $labels[ $method ];
		}
		return $provider ? ucfirst( $provider ) : '';
	}

	/**
	 * Output for the order received page
	 *
	 * @param int $order_id Order ID.
	 */
	public function thankyou_page( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		$label     = self::payment_method_label( $order );
		$reference = $order->get_meta( '_gembapay_reference' );

		if ( $reference && $label ) {
			echo '<h2>' . esc_html__( 'Payment Details', 'ivanov-payment-gateway-with-gembapay' ) . '</h2>';
			echo '<p><strong>' . esc_html__( 'Paid with:', 'ivanov-payment-gateway-with-gembapay' ) . '</strong> ' . esc_html( $label ) . '</p>';
			echo '<p><strong>' . esc_html__( 'Reference:', 'ivanov-payment-gateway-with-gembapay' ) . '</strong> ' . esc_html( $reference ) . '</p>';
		}
	}

	/**
	 * Add content to the WC emails
	 *
	 * @param WC_Order $order         Order object.
	 * @param bool     $sent_to_admin Sent to admin.
	 * @param bool     $plain_text    Plain text email.
	 */
	public function email_instructions( $order, $sent_to_admin, $plain_text = false ) {
		if ( $order->get_payment_method() !== $this->id ) {
			return;
		}
		$label     = self::payment_method_label( $order );
		$reference = $order->get_meta( '_gembapay_reference' );

		if ( $reference && $label ) {
			if ( $plain_text ) {
				echo "\n" . esc_html__( 'Paid with:', 'ivanov-payment-gateway-with-gembapay' ) . ' ' . esc_html( $label ) . "\n";
				echo esc_html__( 'Reference:', 'ivanov-payment-gateway-with-gembapay' ) . ' ' . esc_html( $reference ) . "\n\n";
			} else {
				echo '<h2>' . esc_html__( 'Payment Details', 'ivanov-payment-gateway-with-gembapay' ) . '</h2>';
				echo '<p><strong>' . esc_html__( 'Paid with:', 'ivanov-payment-gateway-with-gembapay' ) . '</strong> ' . esc_html( $label ) . '</p>';
				echo '<p><strong>' . esc_html__( 'Reference:', 'ivanov-payment-gateway-with-gembapay' ) . '</strong> ' . esc_html( $reference ) . '</p>';
			}
		}
	}

	/**
	 * Log message
	 *
	 * @param string $message Message to log.
	 * @param string $level   Log level.
	 */
	public function log( $message, $level = 'info' ) {
		if ( 'yes' === $this->get_option( 'debug' ) ) {
			wc_get_logger()->log( $level, $message, array( 'source' => 'gembapay' ) );
		}
	}

	/**
	 * Get webhook secret
	 *
	 * @return string
	 */
	public function get_webhook_secret() {
		return $this->webhook_secret;
	}
}
