=== Ivanov Payment Gateway with GembaPay ===
Contributors: gembapay
Tags: woocommerce, payment gateway, stripe, paypal, credit card
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 8.0
Stable tag: 1.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept credit card and PayPal payments in WooCommerce through a unified checkout experience with GembaPay.

== Description ==

Ivanov Payment Gateway with GembaPay enables you to accept credit card and PayPal payments through a single, unified checkout experience.

= Features =

* **Unified Checkout** - Single integration for Stripe and PayPal
* **Fiat Payments** - Credit/Debit Cards, Apple Pay, Google Pay via Stripe
* **PayPal** - PayPal Balance, Bank Account, Pay Later
* **86+ Currencies** - Set prices in your local currency
* **Direct Settlement** - Funds settle straight into your own Stripe or PayPal account
* **Low Fees** - Just 1% + €0.20 for cards and PayPal
* **Automatic Order Updates** - Orders are updated via webhooks
* **Transaction Details** - Provider reference shown on the order

= How It Works =

1. Customer selects GembaPay at checkout
2. Customer is redirected to GembaPay's secure checkout page
3. Customer chooses their preferred payment method (Card or PayPal)
4. Payment is processed and customer is returned to your store
5. Order status is automatically updated via webhook

= Requirements =

* WooCommerce 7.0 or higher
* PHP 8.0 or higher
* GembaPay merchant account with approved KYC
* SSL certificate (HTTPS)

= Getting Started =

1. Sign up at [gembapay.com](https://gembapay.com)
2. Complete KYC verification
3. Get your API key from the merchant dashboard
4. Install and configure this plugin
5. Start accepting payments!

== Installation ==

= Automatic Installation =

1. Log in to your WordPress dashboard
2. Go to Plugins > Add New
3. Search for "Ivanov Payment Gateway with GembaPay"
4. Click "Install Now" and then "Activate"

= Manual Installation =

1. Download the plugin zip file
2. Log in to your WordPress dashboard
3. Go to Plugins > Add New > Upload Plugin
4. Choose the zip file and click "Install Now"
5. Activate the plugin

= Configuration =

1. Go to WooCommerce > Settings > Payments
2. Click on "GembaPay" to configure
3. Enable the payment method
4. Enter your API Key from GembaPay dashboard
5. Enter your Webhook Secret
6. Copy the Webhook URL and add it to your GembaPay dashboard
7. Save changes

== Frequently Asked Questions ==

= Do I need a GembaPay account? =

Yes, you need to register at gembapay.com and complete KYC verification to get your API credentials.

= What currencies are supported? =

GembaPay supports 86+ currencies for pricing. Customers are charged in the currency supported by the chosen payment provider, using live exchange rates at checkout.

= Where does the money go? =

Payments settle directly into your own connected Stripe or PayPal account. GembaPay never holds your funds.

= What are the fees? =

* Credit/Debit Cards: 1% + €0.20 + Stripe fees
* PayPal: 1% + €0.20 + PayPal fees

= How do refunds work? =

For card and PayPal payments, refunds are processed through those platforms.

= Is it secure? =

Yes! GembaPay uses industry-standard security practices. Card payments are processed by Stripe. All API communications use TLS encryption.

== Screenshots ==

1. Payment method on checkout page
2. GembaPay unified checkout
3. Plugin settings page
4. Order details with transaction info

== Changelog ==

= 1.2.0 =
* Updated the plugin listing and gateway labels to the supported payment methods (Stripe cards and PayPal).
* Clarified that funds settle directly into the merchant's own connected account.

= 1.1.1 =
* Fixed webhook signature verification to match GembaPay's signing exactly: the
  signature is a bare HMAC-SHA256 hex string (no "sha256=" prefix) over the raw
  request body. Previous versions prepended "sha256=" and rejected valid webhooks.
  Update to 1.1.1 so order-paid webhooks are accepted.

= 1.1.0 =
* Order detail / status lookups now use the authenticated merchant API endpoints
  (GET /api/merchant/payment/:orderId and /payment-status/:orderId) instead of the
  public customer endpoints. Checkout and webhooks are unchanged.
* readme: aligned stated requirements to WooCommerce 7.0 / PHP 8.0.

= 1.0.0 =
* Initial release
* Stripe integration (Cards, Apple Pay, Google Pay)
* PayPal integration
* Webhook support for automatic order updates
* Transaction details on order page
* Debug logging

== Upgrade Notice ==

= 1.1.0 =
Uses the authenticated GembaPay merchant endpoints for order lookups. Recommended update.

= 1.0.0 =
Initial release of Ivanov Payment Gateway with GembaPay.
