<?php
/**
 * GembaPay Webhook Handler
 *
 * @package GembaPay
 */

defined( 'ABSPATH' ) || exit;

/**
 * GembaPay Webhook Class
 *
 * Endpoint: /wc-api/gembapay_webhook/
 * Handles: payment.completed, payment.refunded, payment.failed, payment.expired,
 *          dispute.created, dispute.closed. Everything else is acknowledged and ignored.
 */
class GembaPay_Webhook {

	/**
	 * How many processed event ids are remembered per order (duplicate deliveries are skipped).
	 */
	const MAX_EVENT_IDS = 50;

	/**
	 * Handle incoming webhook
	 */
	public static function handle() {
		$gateway = self::get_gateway();

		if ( ! $gateway ) {
			self::send_response( 500, 'Gateway not configured' );
			return;
		}

		// Raw body exactly as received — the signature covers these bytes.
		$payload = file_get_contents( 'php://input' );

		if ( empty( $payload ) ) {
			self::log( 'Empty webhook payload received' );
			self::send_response( 400, 'Empty payload' );
			return;
		}

		$signature_v2 = isset( $_SERVER['HTTP_X_GEMBAPAY_SIGNATURE_V2'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_GEMBAPAY_SIGNATURE_V2'] ) ) : '';
		$signature    = isset( $_SERVER['HTTP_X_GEMBAPAY_SIGNATURE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_GEMBAPAY_SIGNATURE'] ) ) : '';
		$secret       = $gateway->get_webhook_secret();

		// Signature-V2 (timestamped, replay-protected) whenever GembaPay sent it; legacy only without it.
		$valid = '' !== $signature_v2
			? GembaPay_API::verify_webhook_signature_v2( $payload, $signature_v2, $secret )
			: GembaPay_API::verify_webhook_signature( $payload, $signature, $secret );

		if ( ! $valid ) {
			self::log( 'Invalid webhook signature' );
			self::send_response( 401, 'Invalid signature' );
			return;
		}

		$data = json_decode( $payload, true );

		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $data ) ) {
			self::log( 'Invalid JSON payload' );
			self::send_response( 400, 'Invalid JSON' );
			return;
		}

		$event    = isset( $data['event'] ) ? sanitize_text_field( $data['event'] ) : '';
		$event_id = isset( $data['eventId'] ) ? sanitize_text_field( $data['eventId'] ) : '';
		$payment  = isset( $data['payment'] ) && is_array( $data['payment'] ) ? $data['payment'] : array();

		self::log( 'Webhook received: ' . $event . ' ' . $event_id );

		// Dashboard "Send test" samples: acknowledge, never touch an order.
		if ( ! empty( $data['test'] ) ) {
			self::send_response( 200, 'Test event received' );
			return;
		}

		// Events without a one-off payment (e.g. subscription.*) are not handled by this plugin.
		if ( empty( $payment['orderId'] ) ) {
			self::send_response( 200, 'Ignored: no order' );
			return;
		}

		$gembapay_order_id = sanitize_text_field( $payment['orderId'] );

		// Orders created by this plugin are "WC-<id>-<time>". Other orders of the same GembaPay account
		// (another shop, an invoice, a payment link) are acknowledged, so GembaPay does not retry them.
		if ( ! preg_match( '/^WC-\d+-/', $gembapay_order_id ) ) {
			self::send_response( 200, 'Ignored: not a WooCommerce order' );
			return;
		}

		$order = self::get_order_by_gembapay_id( $gembapay_order_id );

		if ( ! $order ) {
			self::log( 'Order not found for GembaPay ID: ' . $gembapay_order_id );
			self::send_response( 404, 'Order not found' );
			return;
		}

		// At-least-once delivery: skip an event that was already processed for this order.
		$seen = array_filter( (array) $order->get_meta( '_gembapay_event_ids' ) );
		if ( '' !== $event_id && in_array( $event_id, $seen, true ) ) {
			self::send_response( 200, 'Already processed' );
			return;
		}

		switch ( $event ) {
			case 'payment.completed':
				self::handle_payment_completed( $order, $payment );
				break;
			case 'payment.refunded':
				self::handle_payment_refunded( $order, $data );
				break;
			case 'payment.failed':
				self::handle_payment_failed( $order, $payment );
				break;
			case 'payment.expired':
				self::handle_payment_expired( $order );
				break;
			case 'dispute.created':
				self::handle_dispute_created( $order, isset( $data['dispute'] ) ? (array) $data['dispute'] : array() );
				break;
			case 'dispute.closed':
				self::handle_dispute_closed( $order, isset( $data['dispute'] ) ? (array) $data['dispute'] : array() );
				break;
			default:
				self::log( 'Unhandled event type: ' . $event );
				break;
		}

		if ( '' !== $event_id ) {
			$seen[] = $event_id;
			$order->update_meta_data( '_gembapay_event_ids', array_slice( array_values( array_unique( $seen ) ), -self::MAX_EVENT_IDS ) );
			$order->save();
		}

		self::send_response( 200, 'OK' );
	}

	/**
	 * Payment completed: store the details, check the amount, mark the order paid.
	 *
	 * @param WC_Order $order   Order object.
	 * @param array    $payment Payment data.
	 */
	private static function handle_payment_completed( $order, $payment ) {
		if ( $order->is_paid() ) {
			self::log( 'Order ' . $order->get_id() . ' already paid' );
			return;
		}

		$reference = '';
		if ( ! empty( $payment['txHash'] ) ) {
			$reference = sanitize_text_field( $payment['txHash'] );
		} elseif ( ! empty( $payment['reference'] ) ) {
			$reference = sanitize_text_field( $payment['reference'] );
		}

		$fields = array(
			'_gembapay_reference'      => $reference,
			'_gembapay_network'        => isset( $payment['network'] ) ? sanitize_text_field( $payment['network'] ) : '',
			'_gembapay_provider'       => isset( $payment['paymentProvider'] ) ? sanitize_text_field( $payment['paymentProvider'] ) : '',
			'_gembapay_payment_method' => isset( $payment['paymentMethod'] ) ? sanitize_text_field( (string) $payment['paymentMethod'] ) : '',
			'_gembapay_usd_amount'     => isset( $payment['usdAmount'] ) ? sanitize_text_field( (string) $payment['usdAmount'] ) : '',
		);
		foreach ( $fields as $key => $value ) {
			if ( '' !== $value ) {
				$order->update_meta_data( $key, $value );
			}
		}
		$order->save();

		$paid_amount   = isset( $payment['amountOriginal'] ) ? (float) $payment['amountOriginal'] : ( isset( $payment['amount'] ) ? (float) $payment['amount'] : 0.0 );
		$paid_currency = strtoupper( sanitize_text_field( isset( $payment['currencyOriginal'] ) ? (string) $payment['currencyOriginal'] : ( isset( $payment['currency'] ) ? (string) $payment['currency'] : '' ) ) );
		$label         = GembaPay_Gateway::payment_method_label( $order );

		// Never ship for less than the order total or in another currency.
		if ( $paid_currency !== strtoupper( $order->get_currency() ) || $paid_amount + 0.01 < (float) $order->get_total() ) {
			$order->update_status(
				'on-hold',
				sprintf(
					/* translators: 1: paid amount, 2: paid currency, 3: order total, 4: order currency */
					__( 'GembaPay payment received but the amount does not match: paid %1$s %2$s, order total %3$s %4$s. Check before fulfilling.', 'ivanov-payment-gateway-with-gembapay' ),
					$paid_amount,
					$paid_currency,
					$order->get_total(),
					$order->get_currency()
				)
			);
			self::log( 'Order ' . $order->get_id() . ' amount mismatch — on hold' );
			return;
		}

		$order->payment_complete( $reference );
		$order->add_order_note(
			sprintf(
				/* translators: 1: payment method (e.g. Google Pay), 2: amount, 3: currency, 4: provider reference */
				__( 'GembaPay payment completed via %1$s: %2$s %3$s. Reference: %4$s.', 'ivanov-payment-gateway-with-gembapay' ),
				$label ? $label : __( 'GembaPay', 'ivanov-payment-gateway-with-gembapay' ),
				$paid_amount,
				$paid_currency,
				$reference
			)
		);
		self::log( 'Order ' . $order->get_id() . ' marked as paid' );
	}

	/**
	 * Refund (full or partial) — from GembaPay, the GembaPay dashboard or the Stripe/PayPal account.
	 * Refunds started from this WooCommerce shop are already recorded and are skipped.
	 *
	 * @param WC_Order $order Order object.
	 * @param array    $data  Event data.
	 */
	private static function handle_payment_refunded( $order, $data ) {
		$refund  = isset( $data['refund'] ) && is_array( $data['refund'] ) ? $data['refund'] : array();
		$payment = isset( $data['payment'] ) && is_array( $data['payment'] ) ? $data['payment'] : array();
		$id      = isset( $refund['id'] ) ? sanitize_text_field( $refund['id'] ) : '';
		$amount  = isset( $refund['amount'] ) ? (float) $refund['amount'] : 0.0;
		$known   = array_filter( (array) $order->get_meta( '_gembapay_refund_ids' ) );

		if ( '' === $id || in_array( $id, $known, true ) ) {
			return;
		}

		$reason = isset( $refund['reason'] ) && $refund['reason'] ? sanitize_text_field( $refund['reason'] ) : __( 'Refunded via GembaPay', 'ivanov-payment-gateway-with-gembapay' );
		$wc     = null;
		if ( $amount > 0 && $amount <= (float) $order->get_remaining_refund_amount() + 0.0001 ) {
			$wc = wc_create_refund(
				array(
					'order_id'       => $order->get_id(),
					'amount'         => $amount,
					'reason'         => $reason,
					'refund_payment' => false, // the money has already been returned by GembaPay.
					'restock_items'  => false,
				)
			);
		}

		$known[] = $id;
		$order   = wc_get_order( $order->get_id() ); // reload after the refund.
		$order->update_meta_data( '_gembapay_refund_ids', array_values( array_unique( $known ) ) );
		$order->add_order_note(
			sprintf(
				/* translators: 1: amount, 2: currency, 3: where the refund was made, 4: refund id */
				__( 'GembaPay refund of %1$s %2$s (made in: %3$s, refund ID: %4$s).', 'ivanov-payment-gateway-with-gembapay' ),
				$amount,
				isset( $refund['currency'] ) ? sanitize_text_field( $refund['currency'] ) : $order->get_currency(),
				isset( $refund['source'] ) ? sanitize_text_field( $refund['source'] ) : '',
				$id
			) . ( is_wp_error( $wc ) || null === $wc ? ' ' . __( 'Could not be recorded as a WooCommerce refund automatically — please check the order.', 'ivanov-payment-gateway-with-gembapay' ) : '' )
		);
		if ( ! empty( $payment['fullyRefunded'] ) && ! $order->has_status( 'refunded' ) && ( null === $wc || is_wp_error( $wc ) ) ) {
			$order->update_status( 'refunded' );
		}
		$order->save();
		self::log( 'Order ' . $order->get_id() . ' refund ' . $id . ' recorded' );
	}

	/**
	 * Declined attempt: not final (the customer can try again), so only a note.
	 *
	 * @param WC_Order $order   Order object.
	 * @param array    $payment Payment data.
	 */
	private static function handle_payment_failed( $order, $payment ) {
		if ( $order->is_paid() ) {
			return;
		}
		$order->add_order_note(
			sprintf(
				/* translators: %s: decline reason */
				__( 'GembaPay: a payment attempt was declined (%s). The customer can still pay until the payment request expires.', 'ivanov-payment-gateway-with-gembapay' ),
				sanitize_text_field( ! empty( $payment['failureMessage'] ) ? $payment['failureMessage'] : ( ! empty( $payment['failureCode'] ) ? $payment['failureCode'] : 'declined' ) )
			)
		);
	}

	/**
	 * Payment request expired unpaid: cancel the order (never a paid one).
	 *
	 * @param WC_Order $order Order object.
	 */
	private static function handle_payment_expired( $order ) {
		if ( $order->is_paid() || $order->has_status( array( 'cancelled', 'refunded' ) ) ) {
			return;
		}
		$order->update_status( 'cancelled', __( 'GembaPay payment request expired without payment.', 'ivanov-payment-gateway-with-gembapay' ) );
		self::log( 'Order ' . $order->get_id() . ' cancelled (expired)' );
	}

	/**
	 * Dispute opened: note + hold the order (remember the status to restore if the dispute is won).
	 *
	 * @param WC_Order $order   Order object.
	 * @param array    $dispute Dispute data.
	 */
	private static function handle_dispute_created( $order, $dispute ) {
		$note = sprintf(
			/* translators: 1: amount, 2: currency, 3: reason, 4: provider */
			__( 'GembaPay: the customer disputed this payment (%1$s %2$s, reason: %3$s). Respond in your %4$s account before the deadline.', 'ivanov-payment-gateway-with-gembapay' ),
			isset( $dispute['amount'] ) ? (float) $dispute['amount'] : '',
			isset( $dispute['currency'] ) ? sanitize_text_field( (string) $dispute['currency'] ) : '',
			isset( $dispute['reason'] ) ? sanitize_text_field( (string) $dispute['reason'] ) : '',
			( isset( $dispute['provider'] ) && 'paypal' === $dispute['provider'] ) ? 'PayPal' : 'Stripe'
		);
		$order->update_meta_data( '_gembapay_dispute_id', isset( $dispute['id'] ) ? sanitize_text_field( $dispute['id'] ) : '' );
		if ( $order->has_status( array( 'processing', 'completed' ) ) ) {
			$order->update_meta_data( '_gembapay_status_before_dispute', $order->get_status() );
			$order->save();
			$order->update_status( 'on-hold', $note );
		} else {
			$order->add_order_note( $note );
			$order->save();
		}
	}

	/**
	 * Dispute decided: restore the status when won, note the result when lost.
	 *
	 * @param WC_Order $order   Order object.
	 * @param array    $dispute Dispute data.
	 */
	private static function handle_dispute_closed( $order, $dispute ) {
		$outcome = isset( $dispute['outcome'] ) ? sanitize_text_field( (string) $dispute['outcome'] ) : 'other';
		$before  = $order->get_meta( '_gembapay_status_before_dispute' );
		if ( 'won' === $outcome ) {
			$note = __( 'GembaPay: dispute won — the money stays with you.', 'ivanov-payment-gateway-with-gembapay' );
			if ( $before && $order->has_status( 'on-hold' ) ) {
				$order->update_status( $before, $note );
			} else {
				$order->add_order_note( $note );
			}
		} elseif ( 'lost' === $outcome ) {
			$order->add_order_note( __( 'GembaPay: dispute lost — the money was returned to the customer.', 'ivanov-payment-gateway-with-gembapay' ) );
		} else {
			$order->add_order_note( __( 'GembaPay: dispute closed.', 'ivanov-payment-gateway-with-gembapay' ) );
		}
		$order->delete_meta_data( '_gembapay_status_before_dispute' );
		$order->save();
	}

	/**
	 * Get WooCommerce order by GembaPay order ID
	 *
	 * @param string $gembapay_order_id GembaPay order ID.
	 * @return WC_Order|null
	 */
	private static function get_order_by_gembapay_id( $gembapay_order_id ) {
		// Format: WC-{id}-{timestamp}; the stored id must match exactly.
		if ( preg_match( '/^WC-(\d+)-/', $gembapay_order_id, $matches ) ) {
			$order = wc_get_order( (int) $matches[1] );
			if ( $order && ( $order->get_meta( '_gembapay_order_id' ) === $gembapay_order_id || in_array( $gembapay_order_id, array_filter( (array) $order->get_meta( '_gembapay_order_ids' ) ), true ) ) ) {
				return $order;
			}
		}
		return null;
	}

	/**
	 * Get gateway instance
	 *
	 * @return GembaPay_Gateway|null
	 */
	private static function get_gateway() {
		$gateways = WC()->payment_gateways()->payment_gateways();
		return isset( $gateways['gembapay'] ) ? $gateways['gembapay'] : null;
	}

	/**
	 * Send JSON response
	 *
	 * @param int    $status  HTTP status code.
	 * @param string $message Response message.
	 */
	private static function send_response( $status, $message ) {
		status_header( $status );
		header( 'Content-Type: application/json' );
		echo wp_json_encode(
			array(
				'status'  => $status,
				'message' => $message,
			)
		);
		exit;
	}

	/**
	 * Log message
	 *
	 * @param string $message Message to log.
	 */
	private static function log( $message ) {
		$gateway = self::get_gateway();
		if ( $gateway ) {
			$gateway->log( $message );
		}
	}
}
