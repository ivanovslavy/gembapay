<?php
/**
 * GembaPay Payment Gateway
 *
 * @package GembaPay
 */

defined('ABSPATH') || exit;

/**
 * GembaPay Gateway Class
 */
class GembaPay_Gateway extends WC_Payment_Gateway {

    /**
     * API instance
     *
     * @var GembaPay_API
     */
    private $api;

    /**
     * Constructor
     */
    public function __construct() {
        $this->id                 = 'gembapay';
        $this->icon               = GEMBAPAY_PLUGIN_URL . 'assets/images/gembapay-logo.png';
        $this->has_fields         = false;
        $this->method_title       = __('GembaPay', 'ivanov-payment-gateway-with-gembapay');
        $this->method_description = __('Accept Credit Cards and PayPal payments with GembaPay unified checkout.', 'ivanov-payment-gateway-with-gembapay');
        $this->supports           = array(
            'products',
            'refunds',
        );

        // Load settings
        $this->init_form_fields();
        $this->init_settings();

        // Get settings
        $this->title        = $this->get_option('title');
        $this->description  = $this->get_option('description');
        $this->enabled      = $this->get_option('enabled');
        $this->api_key      = $this->get_option('api_key');
        $this->webhook_secret = $this->get_option('webhook_secret');

        // Initialize API
        if (!empty($this->api_key)) {
            $this->api = new GembaPay_API($this->api_key);
        }

        // Hooks
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        add_action('woocommerce_thankyou_' . $this->id, array($this, 'thankyou_page'));
        add_action('woocommerce_email_before_order_table', array($this, 'email_instructions'), 10, 3);
    }

    /**
     * Initialize Gateway Settings Form Fields
     */
    public function init_form_fields() {
        $this->form_fields = array(
            'enabled' => array(
                'title'   => __('Enable/Disable', 'ivanov-payment-gateway-with-gembapay'),
                'type'    => 'checkbox',
                'label'   => __('Enable GembaPay', 'ivanov-payment-gateway-with-gembapay'),
                'default' => 'no',
            ),
            'title' => array(
                'title'       => __('Title', 'ivanov-payment-gateway-with-gembapay'),
                'type'        => 'text',
                'description' => __('Payment method title shown to customers.', 'ivanov-payment-gateway-with-gembapay'),
                'default'     => __('Card & PayPal', 'ivanov-payment-gateway-with-gembapay'),
                'desc_tip'    => true,
            ),
            'description' => array(
                'title'       => __('Description', 'ivanov-payment-gateway-with-gembapay'),
                'type'        => 'textarea',
                'description' => __('Payment method description shown to customers.', 'ivanov-payment-gateway-with-gembapay'),
                'default'     => __('Pay securely with your credit card or PayPal.', 'ivanov-payment-gateway-with-gembapay'),
                'desc_tip'    => true,
            ),
            'api_key' => array(
                'title'       => __('API Key', 'ivanov-payment-gateway-with-gembapay'),
                'type'        => 'password',
                'description' => __('Your GembaPay API key from the merchant dashboard.', 'ivanov-payment-gateway-with-gembapay'),
                'default'     => '',
                'desc_tip'    => true,
            ),
            'webhook_secret' => array(
                'title'       => __('Webhook Secret', 'ivanov-payment-gateway-with-gembapay'),
                'type'        => 'password',
                'description' => __('Your webhook secret for verifying payment notifications.', 'ivanov-payment-gateway-with-gembapay'),
                'default'     => '',
                'desc_tip'    => true,
            ),
            'webhook_url' => array(
                'title'       => __('Webhook URL', 'ivanov-payment-gateway-with-gembapay'),
                'type'        => 'title',
                'description' => sprintf(
                    /* translators: %s: webhook URL */
                    __('Add this URL to your GembaPay merchant dashboard: %s', 'ivanov-payment-gateway-with-gembapay'),
                    '<br><code>' . esc_url(home_url('/wc-api/gembapay_webhook/')) . '</code>'
                ),
            ),
            'payment_methods' => array(
                'title'       => __('Accepted Payment Methods', 'ivanov-payment-gateway-with-gembapay'),
                'type'        => 'title',
                'description' => __('GembaPay supports: Credit/Debit Cards (via Stripe), and PayPal.', 'ivanov-payment-gateway-with-gembapay'),
            ),
            'debug' => array(
                'title'       => __('Debug Log', 'ivanov-payment-gateway-with-gembapay'),
                'type'        => 'checkbox',
                'label'       => __('Enable logging', 'ivanov-payment-gateway-with-gembapay'),
                'default'     => 'no',
                'description' => sprintf(
                    /* translators: %s: log file path */
                    __('Log GembaPay events inside %s', 'ivanov-payment-gateway-with-gembapay'),
                    '<code>' . WC_Log_Handler_File::get_log_file_path('gembapay') . '</code>'
                ),
            ),
        );
    }

    /**
     * Check if gateway is available
     *
     * @return bool
     */
    public function is_available() {
        if ('yes' !== $this->enabled) {
            return false;
        }

        if (empty($this->api_key)) {
            return false;
        }

        return true;
    }

    /**
     * Process the payment
     *
     * @param int $order_id Order ID
     * @return array
     */
    public function process_payment($order_id) {
        $order = wc_get_order($order_id);

        if (!$order) {
            wc_add_notice(__('Order not found.', 'ivanov-payment-gateway-with-gembapay'), 'error');
            return array('result' => 'failure');
        }

        // Generate GembaPay order ID
        $gembapay_order_id = GembaPay_API::generate_order_id($order_id);

        // Store the GembaPay order ID
        $order->update_meta_data('_gembapay_order_id', $gembapay_order_id);
        $order->save();

        // Create payment request
        $response = $this->api->create_payment(
            $gembapay_order_id,
            $order->get_total(),
            $order->get_currency(),
            sprintf(
                /* translators: %s: order number */
                __('Order #%s', 'ivanov-payment-gateway-with-gembapay'),
                $order->get_order_number()
            )
        );

        if (is_wp_error($response)) {
            $this->log('Payment creation failed: ' . $response->get_error_message());
            wc_add_notice(
                __('Payment error: ', 'ivanov-payment-gateway-with-gembapay') . $response->get_error_message(),
                'error'
            );
            return array('result' => 'failure');
        }

        if (empty($response['paymentUrl'])) {
            $this->log('Payment URL not received');
            wc_add_notice(__('Payment error: Could not create payment.', 'ivanov-payment-gateway-with-gembapay'), 'error');
            return array('result' => 'failure');
        }

        // Store payment URL
        $order->update_meta_data('_gembapay_payment_url', $response['paymentUrl']);
        $order->update_meta_data('_gembapay_amount_usd', $response['amountUsd'] ?? '');
        $order->save();

        // Mark as pending
        $order->update_status('pending', __('Awaiting GembaPay payment.', 'ivanov-payment-gateway-with-gembapay'));

        // Empty cart
        WC()->cart->empty_cart();

        $this->log('Payment created for order ' . $order_id . '. Redirecting to: ' . $response['paymentUrl']);

        // Redirect to GembaPay checkout
        return array(
            'result'   => 'success',
            'redirect' => $response['paymentUrl'],
        );
    }

    /**
     * Process refund
     *
     * @param int    $order_id Order ID
     * @param float  $amount   Amount to refund
     * @param string $reason   Refund reason
     * @return bool|WP_Error
     */
    public function process_refund($order_id, $amount = null, $reason = '') {
            // Refunds are handled by the payment provider that executed the charge
        // For Stripe/PayPal payments, refunds go through those platforms
        return new WP_Error(
            'gembapay_refund_error',
            __('Refunds must be processed manually through your payment provider (Stripe/PayPal).', 'ivanov-payment-gateway-with-gembapay')
        );
    }

    /**
     * Output for the order received page
     *
     * @param int $order_id Order ID
     */
    public function thankyou_page($order_id) {
        $order = wc_get_order($order_id);
        
        if (!$order) {
            return;
        }

        $network   = $order->get_meta('_gembapay_network');
        $reference = $order->get_meta('_gembapay_reference');

        if ($reference && $network) {
            echo '<h2>' . esc_html__('Payment Details', 'ivanov-payment-gateway-with-gembapay') . '</h2>';
            echo '<p><strong>' . esc_html__('Paid with:', 'ivanov-payment-gateway-with-gembapay') . '</strong> ' . esc_html(ucfirst($network)) . '</p>';
            echo '<p><strong>' . esc_html__('Reference:', 'ivanov-payment-gateway-with-gembapay') . '</strong> ' . esc_html($reference) . '</p>';
        }
    }

    /**
     * Add content to the WC emails
     *
     * @param WC_Order $order         Order object
     * @param bool     $sent_to_admin Sent to admin
     * @param bool     $plain_text    Plain text email
     */
    public function email_instructions($order, $sent_to_admin, $plain_text = false) {
        if ($order->get_payment_method() !== $this->id) {
            return;
        }

        $network   = $order->get_meta('_gembapay_network');
        $reference = $order->get_meta('_gembapay_reference');

        if ($reference && $network) {
            if ($plain_text) {
                echo "\n" . esc_html__('Paid with:', 'ivanov-payment-gateway-with-gembapay') . ' ' . esc_html(ucfirst($network)) . "\n";
                echo esc_html__('Reference:', 'ivanov-payment-gateway-with-gembapay') . ' ' . esc_html($reference) . "\n\n";
            } else {
                echo '<h2>' . esc_html__('Payment Details', 'ivanov-payment-gateway-with-gembapay') . '</h2>';
                echo '<p><strong>' . esc_html__('Paid with:', 'ivanov-payment-gateway-with-gembapay') . '</strong> ' . esc_html(ucfirst($network)) . '</p>';
                echo '<p><strong>' . esc_html__('Reference:', 'ivanov-payment-gateway-with-gembapay') . '</strong> ' . esc_html($reference) . '</p>';
            }
        }
    }

    /**
     * Log message
     *
     * @param string $message Message to log
     * @param string $level   Log level
     */
    public function log($message, $level = 'info') {
        if ('yes' === $this->get_option('debug')) {
            $logger = wc_get_logger();
            $logger->log($level, $message, array('source' => 'gembapay'));
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
