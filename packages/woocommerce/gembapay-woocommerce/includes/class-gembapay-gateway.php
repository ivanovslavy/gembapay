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
        $this->method_title       = __('GembaPay', 'gembapay-for-woocommerce');
        $this->method_description = __('Accept Crypto, Credit Cards, and PayPal payments with GembaPay unified checkout.', 'gembapay-for-woocommerce');
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
                'title'   => __('Enable/Disable', 'gembapay-for-woocommerce'),
                'type'    => 'checkbox',
                'label'   => __('Enable GembaPay', 'gembapay-for-woocommerce'),
                'default' => 'no',
            ),
            'title' => array(
                'title'       => __('Title', 'gembapay-for-woocommerce'),
                'type'        => 'text',
                'description' => __('Payment method title shown to customers.', 'gembapay-for-woocommerce'),
                'default'     => __('Crypto, Card & PayPal', 'gembapay-for-woocommerce'),
                'desc_tip'    => true,
            ),
            'description' => array(
                'title'       => __('Description', 'gembapay-for-woocommerce'),
                'type'        => 'textarea',
                'description' => __('Payment method description shown to customers.', 'gembapay-for-woocommerce'),
                'default'     => __('Pay securely with cryptocurrency, credit card, or PayPal.', 'gembapay-for-woocommerce'),
                'desc_tip'    => true,
            ),
            'api_key' => array(
                'title'       => __('API Key', 'gembapay-for-woocommerce'),
                'type'        => 'password',
                'description' => __('Your GembaPay API key from the merchant dashboard.', 'gembapay-for-woocommerce'),
                'default'     => '',
                'desc_tip'    => true,
            ),
            'webhook_secret' => array(
                'title'       => __('Webhook Secret', 'gembapay-for-woocommerce'),
                'type'        => 'password',
                'description' => __('Your webhook secret for verifying payment notifications.', 'gembapay-for-woocommerce'),
                'default'     => '',
                'desc_tip'    => true,
            ),
            'webhook_url' => array(
                'title'       => __('Webhook URL', 'gembapay-for-woocommerce'),
                'type'        => 'title',
                'description' => sprintf(
                    /* translators: %s: webhook URL */
                    __('Add this URL to your GembaPay merchant dashboard: %s', 'gembapay-for-woocommerce'),
                    '<br><code>' . esc_url(home_url('/wc-api/gembapay_webhook/')) . '</code>'
                ),
            ),
            'payment_methods' => array(
                'title'       => __('Accepted Payment Methods', 'gembapay-for-woocommerce'),
                'type'        => 'title',
                'description' => __('GembaPay supports: Cryptocurrency (ETH, BNB, POL, USDC, USDT), Credit/Debit Cards (via Stripe), and PayPal.', 'gembapay-for-woocommerce'),
            ),
            'debug' => array(
                'title'       => __('Debug Log', 'gembapay-for-woocommerce'),
                'type'        => 'checkbox',
                'label'       => __('Enable logging', 'gembapay-for-woocommerce'),
                'default'     => 'no',
                'description' => sprintf(
                    /* translators: %s: log file path */
                    __('Log GembaPay events inside %s', 'gembapay-for-woocommerce'),
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
            wc_add_notice(__('Order not found.', 'gembapay-for-woocommerce'), 'error');
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
                __('Order #%s', 'gembapay-for-woocommerce'),
                $order->get_order_number()
            )
        );

        if (is_wp_error($response)) {
            $this->log('Payment creation failed: ' . $response->get_error_message());
            wc_add_notice(
                __('Payment error: ', 'gembapay-for-woocommerce') . $response->get_error_message(),
                'error'
            );
            return array('result' => 'failure');
        }

        if (empty($response['paymentUrl'])) {
            $this->log('Payment URL not received');
            wc_add_notice(__('Payment error: Could not create payment.', 'gembapay-for-woocommerce'), 'error');
            return array('result' => 'failure');
        }

        // Store payment URL
        $order->update_meta_data('_gembapay_payment_url', $response['paymentUrl']);
        $order->update_meta_data('_gembapay_amount_usd', $response['amountUsd'] ?? '');
        $order->save();

        // Mark as pending
        $order->update_status('pending', __('Awaiting GembaPay payment.', 'gembapay-for-woocommerce'));

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
        // GembaPay crypto payments are non-custodial, refunds must be handled manually
        // For Stripe/PayPal payments, refunds go through those platforms
        return new WP_Error(
            'gembapay_refund_error',
            __('Refunds must be processed manually through your payment provider (Stripe/PayPal) or directly to the customer for crypto payments.', 'gembapay-for-woocommerce')
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

        $network = $order->get_meta('_gembapay_network');
        $tx_hash = $order->get_meta('_gembapay_tx_hash');

        if ($tx_hash && $network) {
            $explorer_url = $this->get_explorer_url($network, $tx_hash);
            echo '<h2>' . esc_html__('Payment Details', 'gembapay-for-woocommerce') . '</h2>';
            echo '<p><strong>' . esc_html__('Network:', 'gembapay-for-woocommerce') . '</strong> ' . esc_html(ucfirst($network)) . '</p>';
            if ($explorer_url) {
                echo '<p><strong>' . esc_html__('Transaction:', 'gembapay-for-woocommerce') . '</strong> ';
                echo '<a href="' . esc_url($explorer_url) . '" target="_blank" rel="noopener">' . esc_html(substr($tx_hash, 0, 20)) . '...</a></p>';
            }
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

        $network = $order->get_meta('_gembapay_network');
        $tx_hash = $order->get_meta('_gembapay_tx_hash');

        if ($tx_hash && $network) {
            if ($plain_text) {
                echo "\n" . esc_html__('Payment Network:', 'gembapay-for-woocommerce') . ' ' . esc_html(ucfirst($network)) . "\n";
                echo esc_html__('Transaction Hash:', 'gembapay-for-woocommerce') . ' ' . esc_html($tx_hash) . "\n\n";
            } else {
                $explorer_url = $this->get_explorer_url($network, $tx_hash);
                echo '<h2>' . esc_html__('Payment Details', 'gembapay-for-woocommerce') . '</h2>';
                echo '<p><strong>' . esc_html__('Network:', 'gembapay-for-woocommerce') . '</strong> ' . esc_html(ucfirst($network)) . '</p>';
                if ($explorer_url) {
                    echo '<p><strong>' . esc_html__('Transaction:', 'gembapay-for-woocommerce') . '</strong> ';
                    echo '<a href="' . esc_url($explorer_url) . '">' . esc_html(substr($tx_hash, 0, 20)) . '...</a></p>';
                }
            }
        }
    }

    /**
     * Get blockchain explorer URL
     *
     * @param string $network Network name
     * @param string $tx_hash Transaction hash
     * @return string|null
     */
    private function get_explorer_url($network, $tx_hash) {
        $explorers = array(
            'ethereum' => 'https://etherscan.io/tx/',
            'bsc'      => 'https://bscscan.com/tx/',
            'polygon'  => 'https://polygonscan.com/tx/',
        );

        if (isset($explorers[$network])) {
            return $explorers[$network] . $tx_hash;
        }

        return null;
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
