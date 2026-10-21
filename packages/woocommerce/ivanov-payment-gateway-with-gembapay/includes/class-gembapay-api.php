<?php
/**
 * GembaPay API Handler
 *
 * @package GembaPay
 */

defined( 'ABSPATH' ) || exit;

/**
 * GembaPay API Class
 */
class GembaPay_API {

	/**
	 * Allowed clock difference for Signature-V2 timestamps (seconds).
	 */
	const SIGNATURE_TOLERANCE = 300;

	/**
	 * API Key
	 *
	 * @var string
	 */
	private $api_key;

	/**
	 * API Base URL
	 *
	 * @var string
	 */
	private $api_url;

	/**
	 * Constructor
	 *
	 * @param string $api_key API Key.
	 */
	public function __construct( $api_key ) {
		$this->api_key = $api_key;
		$this->api_url = GEMBAPAY_API_URL;
	}

	/**
	 * Create a payment request
	 *
	 * @param string $order_id    Order ID.
	 * @param float  $amount      Amount.
	 * @param string $currency    Currency code.
	 * @param string $description Description.
	 * @return array|WP_Error
	 */
	public function create_payment( $order_id, $amount, $currency = 'USD', $description = '' ) {
		$payload = array(
			'orderId'     => $order_id,
			'amount'      => floatval( $amount ),
			'currency'    => $currency,
			'description' => $description,
		);

		return $this->request( 'POST', '/api/merchant/payment-request', $payload );
	}

	/**
	 * Refund a payment fully or partly (API key needs the "refunds:write" permission).
	 *
	 * @param string     $order_id        GembaPay order ID.
	 * @param float|null $amount          Amount in the order currency; null refunds everything left.
	 * @param string     $reason          Reason shown to the customer where supported.
	 * @param string     $idempotency_key Same key on a retry → the refund cannot happen twice.
	 * @return array|WP_Error The refund object.
	 */
	public function create_refund( $order_id, $amount, $reason, $idempotency_key ) {
		$payload = array( 'orderId' => $order_id );
		if ( null !== $amount ) {
			$payload['amount'] = number_format( (float) $amount, 2, '.', '' );
		}
		if ( '' !== (string) $reason ) {
			$payload['reason'] = substr( (string) $reason, 0, 500 );
		}

		$response = $this->request( 'POST', '/api/v1/refunds', $payload, array( 'Idempotency-Key' => $idempotency_key ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		return isset( $response['refund'] ) ? $response['refund'] : new WP_Error( 'gembapay_api_error', 'Unexpected refund response' );
	}

	/**
	 * Get payment status
	 *
	 * @param string $order_id Order ID.
	 * @return array|WP_Error
	 */
	public function get_payment_status( $order_id ) {
		return $this->request( 'GET', '/api/merchant/payment-status/' . rawurlencode( $order_id ) );
	}

	/**
	 * Get payment details
	 *
	 * @param string $order_id Order ID.
	 * @return array|WP_Error
	 */
	public function get_payment( $order_id ) {
		return $this->request( 'GET', '/api/merchant/payment/' . rawurlencode( $order_id ) );
	}

	/**
	 * Make API request
	 *
	 * @param string $method        HTTP method.
	 * @param string $endpoint      API endpoint.
	 * @param array  $data          Request data.
	 * @param array  $extra_headers Additional headers.
	 * @return array|WP_Error
	 */
	private function request( $method, $endpoint, $data = array(), $extra_headers = array() ) {
		$url = $this->api_url . $endpoint;

		$args = array(
			'method'  => $method,
			'timeout' => 30,
			'headers' => array_merge(
				array(
					'Authorization' => 'Bearer ' . $this->api_key,
					'Content-Type'  => 'application/json',
					'Accept'        => 'application/json',
					'User-Agent'    => 'GembaPay-WooCommerce/' . GEMBAPAY_VERSION,
				),
				$extra_headers
			),
		);

		if ( in_array( $method, array( 'POST', 'PATCH' ), true ) && ! empty( $data ) ) {
			$args['body'] = wp_json_encode( $data );
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body        = wp_remote_retrieve_body( $response );
		$decoded     = json_decode( $body, true );

		if ( $status_code < 200 || $status_code >= 300 ) {
			$message = isset( $decoded['message'] ) ? $decoded['message'] : 'Unknown error';
			$message = isset( $decoded['error'] ) ? $decoded['error'] : $message;

			return new WP_Error(
				'gembapay_api_error',
				$message,
				array(
					'status'   => $status_code,
					'code'     => isset( $decoded['code'] ) ? $decoded['code'] : null,
					'response' => $decoded,
				)
			);
		}

		return $decoded;
	}

	/**
	 * Verify the X-GembaPay-Signature-V2 header: "t=<unix>,v1=<hex>[,v1=<hex>]",
	 * v1 = HMAC-SHA256(secret, "<t>.<raw body>"). The timestamp is signed and must be within
	 * 5 minutes, so a captured webhook cannot be replayed. Any v1 may match (secret rotation).
	 *
	 * @param string $payload Raw request body.
	 * @param string $header  Header value.
	 * @param string $secret  Webhook secret.
	 * @return bool
	 */
	public static function verify_webhook_signature_v2( $payload, $header, $secret ) {
		if ( empty( $header ) || empty( $secret ) ) {
			return false;
		}
		$timestamp  = 0;
		$signatures = array();
		foreach ( array_map( 'trim', explode( ',', (string) $header ) ) as $part ) {
			if ( 0 === strpos( $part, 't=' ) ) {
				$timestamp = (int) substr( $part, 2 );
			} elseif ( 0 === strpos( $part, 'v1=' ) ) {
				$signatures[] = substr( $part, 3 );
			}
		}
		if ( $timestamp <= 0 || empty( $signatures ) || abs( time() - $timestamp ) > self::SIGNATURE_TOLERANCE ) {
			return false;
		}
		$expected = hash_hmac( 'sha256', $timestamp . '.' . $payload, $secret );
		foreach ( $signatures as $signature ) {
			if ( hash_equals( $expected, $signature ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Verify the legacy X-GembaPay-Signature header (bare hex HMAC-SHA256 over the raw body, no
	 * timestamp). Only used when a request carries no V2 header.
	 *
	 * @param string $payload   Raw payload.
	 * @param string $signature Signature from header.
	 * @param string $secret    Webhook secret.
	 * @return bool
	 */
	public static function verify_webhook_signature( $payload, $signature, $secret ) {
		if ( empty( $signature ) || empty( $secret ) ) {
			return false;
		}

		$expected = hash_hmac( 'sha256', $payload, $secret );

		return hash_equals( $expected, (string) $signature );
	}

	/**
	 * Generate unique order ID for GembaPay
	 *
	 * @param int $wc_order_id WooCommerce order ID.
	 * @return string
	 */
	public static function generate_order_id( $wc_order_id ) {
		return 'WC-' . $wc_order_id . '-' . time();
	}
}
