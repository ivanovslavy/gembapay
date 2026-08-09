<?php
/**
 * GembaPay Webhook Handler
 *
 * @package GembaPay
 */

defined('ABSPATH') || exit;

/**
 * GembaPay Webhook Class
 */
class GembaPay_Webhook {

    /**
     * Handle incoming webhook
     */
    public static function handle() {
        $gateway = self::get_gateway();

        if (!$gateway) {
            self::send_response(500, 'Gateway not configured');
            return;
        }

        // Get raw payload
        $payload = file_get_contents('php://input');

        if (empty($payload)) {
            self::log('Empty webhook payload received');
            self::send_response(400, 'Empty payload');
            return;
        }

        // Get signature
        $signature = isset($_SERVER['HTTP_X_GEMBAPAY_SIGNATURE']) 
            ? sanitize_text_field(wp_unslash($_SERVER['HTTP_X_GEMBAPAY_SIGNATURE'])) 
            : '';

        // Verify signature
        $webhook_secret = $gateway->get_webhook_secret();
        
        if (!GembaPay_API::verify_webhook_signature($payload, $signature, $webhook_secret)) {
            self::log('Invalid webhook signature');
            self::send_response(401, 'Invalid signature');
            return;
        }

        // Parse payload
        $data = json_decode($payload, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            self::log('Invalid JSON payload');
            self::send_response(400, 'Invalid JSON');
            return;
        }

        self::log('Webhook received: ' . wp_json_encode($data));

        // Process event
        $event = isset($data['event']) ? $data['event'] : '';
        $payment = isset($data['payment']) ? $data['payment'] : array();

        if (empty($payment['orderId'])) {
            self::log('Missing orderId in webhook');
            self::send_response(400, 'Missing orderId');
            return;
        }

        // Find WooCommerce order by GembaPay order ID
        $order = self::get_order_by_gembapay_id($payment['orderId']);

        if (!$order) {
            self::log('Order not found for GembaPay ID: ' . $payment['orderId']);
            self::send_response(404, 'Order not found');
            return;
        }

        // Process based on event type
        switch ($event) {
            case 'payment.completed':
                self::handle_payment_completed($order, $payment);
                break;

            case 'payment.failed':
                self::handle_payment_failed($order, $payment);
                break;

            case 'payment.expired':
                self::handle_payment_expired($order, $payment);
                break;

            default:
                self::log('Unknown event type: ' . $event);
                break;
        }

        self::send_response(200, 'OK');
    }

    /**
     * Handle payment completed event
     *
     * @param WC_Order $order   Order object
     * @param array    $payment Payment data
     */
    private static function handle_payment_completed($order, $payment) {
        // Check if already processed
        if ($order->is_paid()) {
            self::log('Order ' . $order->get_id() . ' already paid');
            return;
        }

        // Store payment details
        if (isset($payment['network'])) {
            $order->update_meta_data('_gembapay_network', sanitize_text_field($payment['network']));
        }

        if (isset($payment['txHash'])) {
            $order->update_meta_data('_gembapay_tx_hash', sanitize_text_field($payment['txHash']));
        }

        if (isset($payment['usdAmount'])) {
            $order->update_meta_data('_gembapay_usd_amount', sanitize_text_field($payment['usdAmount']));
        }

        if (isset($payment['tokenAmount'])) {
            $order->update_meta_data('_gembapay_token_amount', sanitize_text_field($payment['tokenAmount']));
        }

        if (isset($payment['paymentProvider'])) {
            $order->update_meta_data('_gembapay_provider', sanitize_text_field($payment['paymentProvider']));
        }

        $order->save();

        // Build order note
        $note = __('GembaPay payment completed.', 'ivanov-payment-gateway-with-gembapay');
        
        if (isset($payment['network'])) {
            $network = ucfirst($payment['network']);
            $note .= ' ' . sprintf(
                /* translators: %s: payment network name */
                __('Network: %s.', 'ivanov-payment-gateway-with-gembapay'),
                $network
            );
        }

        if (isset($payment['txHash'])) {
            $note .= ' ' . sprintf(
                /* translators: %s: transaction hash */
                __('TX: %s', 'ivanov-payment-gateway-with-gembapay'),
                substr($payment['txHash'], 0, 20) . '...'
            );
        }

        if (isset($payment['usdAmount'])) {
            $note .= ' ' . sprintf(
                /* translators: %s: USD amount */
                __('Amount: $%s USD', 'ivanov-payment-gateway-with-gembapay'),
                $payment['usdAmount']
            );
        }

        // Complete the order
        $order->payment_complete($payment['txHash'] ?? '');
        $order->add_order_note($note);

        self::log('Order ' . $order->get_id() . ' marked as paid');
    }

    /**
     * Handle payment failed event
     *
     * @param WC_Order $order   Order object
     * @param array    $payment Payment data
     */
    private static function handle_payment_failed($order, $payment) {
        if ($order->has_status(array('completed', 'processing'))) {
            self::log('Order ' . $order->get_id() . ' already completed, ignoring failed event');
            return;
        }

        $order->update_status('failed', __('GembaPay payment failed.', 'ivanov-payment-gateway-with-gembapay'));
        
        self::log('Order ' . $order->get_id() . ' marked as failed');
    }

    /**
     * Handle payment expired event
     *
     * @param WC_Order $order   Order object
     * @param array    $payment Payment data
     */
    private static function handle_payment_expired($order, $payment) {
        if ($order->has_status(array('completed', 'processing'))) {
            self::log('Order ' . $order->get_id() . ' already completed, ignoring expired event');
            return;
        }

        $order->update_status('cancelled', __('GembaPay payment expired.', 'ivanov-payment-gateway-with-gembapay'));
        
        self::log('Order ' . $order->get_id() . ' marked as cancelled (expired)');
    }

    /**
     * Get WooCommerce order by GembaPay order ID
     *
     * @param string $gembapay_order_id GembaPay order ID
     * @return WC_Order|null
     */
    private static function get_order_by_gembapay_id($gembapay_order_id) {
        // Try to extract WC order ID from GembaPay order ID (format: WC-{id}-{timestamp})
        if (preg_match('/^WC-(\d+)-/', $gembapay_order_id, $matches)) {
            $order = wc_get_order((int) $matches[1]);
            if ($order) {
                // Verify the GembaPay order ID matches
                $stored_id = $order->get_meta('_gembapay_order_id');
                if ($stored_id === $gembapay_order_id) {
                    return $order;
                }
            }
        }

        // Fallback: search by meta
        $orders = wc_get_orders(array(
            'meta_key'   => '_gembapay_order_id',
            'meta_value' => $gembapay_order_id,
            'limit'      => 1,
        ));

        return !empty($orders) ? $orders[0] : null;
    }

    /**
     * Get gateway instance
     *
     * @return GembaPay_Gateway|null
     */
    private static function get_gateway() {
        $gateways = WC()->payment_gateways()->payment_gateways();
        return isset($gateways['gembapay']) ? $gateways['gembapay'] : null;
    }

    /**
     * Send JSON response
     *
     * @param int    $status  HTTP status code
     * @param string $message Response message
     */
    private static function send_response($status, $message) {
        status_header($status);
        header('Content-Type: application/json');
        echo wp_json_encode(array(
            'status'  => $status,
            'message' => $message,
        ));
        exit;
    }

    /**
     * Log message
     *
     * @param string $message Message to log
     */
    private static function log($message) {
        $gateway = self::get_gateway();
        if ($gateway) {
            $gateway->log($message);
        }
    }
}
