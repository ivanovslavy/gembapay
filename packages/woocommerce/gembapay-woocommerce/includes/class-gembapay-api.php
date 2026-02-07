<?php
/**
 * GembaPay API Handler
 *
 * @package GembaPay
 */

defined('ABSPATH') || exit;

/**
 * GembaPay API Class
 */
class GembaPay_API {

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
     * @param string $api_key API Key
     */
    public function __construct($api_key) {
        $this->api_key = $api_key;
        $this->api_url = GEMBAPAY_API_URL;
    }

    /**
     * Create a payment request
     *
     * @param string $order_id Order ID
     * @param float  $amount   Amount
     * @param string $currency Currency code
     * @param string $description Description
     * @return array|WP_Error
     */
    public function create_payment($order_id, $amount, $currency = 'USD', $description = '') {
        $payload = array(
            'orderId'     => $order_id,
            'amount'      => floatval($amount),
            'currency'    => $currency,
            'description' => $description,
        );

        return $this->request('POST', '/api/merchant/payment-request', $payload);
    }

    /**
     * Get payment status
     *
     * @param string $order_id Order ID
     * @return array|WP_Error
     */
    public function get_payment_status($order_id) {
        return $this->request('GET', '/api/customer/payment/' . $order_id . '/status');
    }

    /**
     * Get payment details
     *
     * @param string $order_id Order ID
     * @return array|WP_Error
     */
    public function get_payment($order_id) {
        return $this->request('GET', '/api/customer/payment/' . $order_id);
    }

    /**
     * Make API request
     *
     * @param string $method   HTTP method
     * @param string $endpoint API endpoint
     * @param array  $data     Request data
     * @return array|WP_Error
     */
    private function request($method, $endpoint, $data = array()) {
        $url = $this->api_url . $endpoint;

        $args = array(
            'method'  => $method,
            'timeout' => 30,
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->api_key,
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
                'User-Agent'    => 'GembaPay-WooCommerce/' . GEMBAPAY_VERSION,
            ),
        );

        if ($method === 'POST' && !empty($data)) {
            $args['body'] = wp_json_encode($data);
        }

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $decoded = json_decode($body, true);

        if ($status_code < 200 || $status_code >= 300) {
            $message = isset($decoded['message']) ? $decoded['message'] : 'Unknown error';
            $message = isset($decoded['error']) ? $decoded['error'] : $message;
            
            return new WP_Error(
                'gembapay_api_error',
                $message,
                array('status' => $status_code, 'response' => $decoded)
            );
        }

        return $decoded;
    }

    /**
     * Verify webhook signature
     *
     * @param string $payload   Raw payload
     * @param string $signature Signature from header
     * @param string $secret    Webhook secret
     * @return bool
     */
    public static function verify_webhook_signature($payload, $signature, $secret) {
        if (empty($signature) || empty($secret)) {
            return false;
        }

        $expected = 'sha256=' . hash_hmac('sha256', $payload, $secret);

        return hash_equals($expected, $signature);
    }

    /**
     * Generate unique order ID for GembaPay
     *
     * @param int $wc_order_id WooCommerce order ID
     * @return string
     */
    public static function generate_order_id($wc_order_id) {
        return 'WC-' . $wc_order_id . '-' . time();
    }
}
