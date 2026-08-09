<?php
/**
 * Plugin Name: Ivanov Payment Gateway with GembaPay
 * Plugin URI: https://gembapay.com/woocommerce
 * Description: Accept credit card and PayPal payments in WooCommerce with a unified checkout via GembaPay. Funds settle directly to the merchant.
 * Version: 1.2.0
 * Author: GEMBA EOOD
 * Author URI: https://gembapay.com
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: ivanov-payment-gateway-with-gembapay
 * Domain Path: /languages
 * Requires Plugins: woocommerce
 * Requires at least: 6.0
 * Tested up to: 6.9
 * Requires PHP: 8.0
 * WC requires at least: 7.0
 * WC tested up to: 9.5
 */

defined('ABSPATH') || exit;

// Plugin constants
define('GEMBAPAY_VERSION', '1.1.1');
define('GEMBAPAY_PLUGIN_FILE', __FILE__);
define('GEMBAPAY_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('GEMBAPAY_PLUGIN_URL', plugin_dir_url(__FILE__));
define('GEMBAPAY_API_URL', 'https://api.gembapay.com');

/**
 * Check if WooCommerce is active
 */
function gembapay_check_woocommerce() {
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', 'gembapay_woocommerce_missing_notice');
        return false;
    }
    return true;
}

/**
 * WooCommerce missing notice
 */
function gembapay_woocommerce_missing_notice() {
    ?>
    <div class="error">
        <p><?php esc_html_e('GembaPay requires WooCommerce to be installed and active.', 'ivanov-payment-gateway-with-gembapay'); ?></p>
    </div>
    <?php
}

/**
 * Initialize the plugin
 */
function gembapay_init() {
    if (!gembapay_check_woocommerce()) {
        return;
    }

    // Load text domain

    // Include required files
    require_once GEMBAPAY_PLUGIN_DIR . 'includes/class-gembapay-api.php';
    require_once GEMBAPAY_PLUGIN_DIR . 'includes/class-gembapay-gateway.php';
    require_once GEMBAPAY_PLUGIN_DIR . 'includes/class-gembapay-webhook.php';

    // Register the gateway
    add_filter('woocommerce_payment_gateways', 'gembapay_add_gateway');

    // Register webhook endpoint
    add_action('woocommerce_api_gembapay_webhook', array('GembaPay_Webhook', 'handle'));

    // Add settings link on plugin page
    add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'gembapay_plugin_links');
}
add_action('plugins_loaded', 'gembapay_init');

/**
 * Add the GembaPay gateway to WooCommerce
 */
function gembapay_add_gateway($gateways) {
    $gateways[] = 'GembaPay_Gateway';
    return $gateways;
}

/**
 * Add plugin action links
 */
function gembapay_plugin_links($links) {
    $plugin_links = array(
        '<a href="' . admin_url('admin.php?page=wc-settings&tab=checkout&section=gembapay') . '">' . __('Settings', 'ivanov-payment-gateway-with-gembapay') . '</a>',
        '<a href="https://gembapay.com/docs" target="_blank">' . __('Docs', 'ivanov-payment-gateway-with-gembapay') . '</a>',
    );
    return array_merge($plugin_links, $links);
}

/**
 * Declare HPOS compatibility
 */
add_action('before_woocommerce_init', function() {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

/**
 * Plugin activation
 */
function gembapay_activate() {
    // Flush rewrite rules for webhook endpoint
    flush_rewrite_rules();
}
register_activation_hook(__FILE__, 'gembapay_activate');

/**
 * Plugin deactivation
 */
function gembapay_deactivate() {
    flush_rewrite_rules();
}
register_deactivation_hook(__FILE__, 'gembapay_deactivate');
