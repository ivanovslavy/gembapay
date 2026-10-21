=== Ivanov Payment Gateway with GembaPay ===
Contributors: gembapay
Tags: woocommerce, payment gateway, apple pay, google pay, paypal
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept cards, Apple Pay, Google Pay and PayPal in WooCommerce with one GembaPay checkout. Refunds and disputes update your orders.

== Description ==

Ivanov Payment Gateway with GembaPay lets your WooCommerce shop accept cards, Apple Pay, Google Pay, Link and PayPal through a single, hosted checkout.

= Features =

* **One checkout** - Cards (incl. Apple Pay, Google Pay and Link) via Stripe, and PayPal
* **51+ currencies** - Set prices in your local currency
* **Direct settlement** - Funds settle straight into your own Stripe or PayPal account
* **Low fees** - 1% + €0.20 for cards and PayPal
* **Refunds from WooCommerce** - Full and partial refunds with the standard Refund button
* **Automatic order updates** - Paid, refunded, expired and disputed payments update the order through signed webhooks
* **Payment method on the order** - The order note, thank-you page and emails show how the customer paid (card, Apple Pay, Google Pay, PayPal)
* **Amount check** - A payment that does not match the order total or currency puts the order on hold instead of completing it

= How It Works =

1. The customer selects GembaPay at checkout
2. The customer is redirected to the secure GembaPay checkout page
3. The customer pays by card, Apple Pay, Google Pay or PayPal
4. The customer returns to your shop
5. The order is updated automatically by a signed webhook

= Requirements =

* WooCommerce 7.0 or higher
* PHP 8.0 or higher
* A GembaPay merchant account with approved KYC
* SSL certificate (HTTPS)

== Installation ==

= Automatic Installation =

1. Log in to your WordPress dashboard
2. Go to Plugins > Add New
3. Search for "Ivanov Payment Gateway with GembaPay"
4. Click "Install Now" and then "Activate"

= Manual Installation =

1. Download the plugin zip file
2. Go to Plugins > Add New > Upload Plugin
3. Choose the zip file, click "Install Now" and activate the plugin

= Configuration =

1. Go to WooCommerce > Settings > Payments and open "GembaPay"
2. Enable the payment method and enter your GembaPay API key and webhook secret
3. Copy the Webhook URL shown on that page into your GembaPay dashboard (Settings > Webhook Settings)
4. In the GembaPay dashboard under Webhooks, switch on payment.refunded, payment.expired, dispute.created and dispute.closed (payment.completed is always on)
5. To refund from WooCommerce, give your API key the "refunds:write" permission (GembaPay dashboard > API Keys > Permissions)

== Frequently Asked Questions ==

= Do I need a GembaPay account? =

Yes. Register at gembapay.com and complete KYC verification to get your API key.

= What currencies are supported? =

GembaPay supports 51+ currencies for pricing. Customers are charged in the currency supported by the chosen payment provider.

= Where does the money go? =

Payments settle directly into your own connected Stripe or PayPal account. GembaPay never holds your funds.

= What are the fees? =

* Cards, Apple Pay, Google Pay: 1% + €0.20 + Stripe fees
* PayPal: 1% + €0.20 + PayPal fees

= How do refunds work? =

Use the Refund button on the WooCommerce order, for the full amount or part of it. The refund is made through GembaPay and the money goes back to the customer's card or PayPal account. Refunds made in the GembaPay dashboard or directly in your Stripe or PayPal account are also added to the order automatically, once the payment.refunded webhook is switched on.

= What happens when a customer disputes a payment? =

With dispute webhooks switched on, a processing or completed order is put on hold with a note. Respond to the dispute in your Stripe or PayPal account. If you win, the order returns to its previous status; if you lose, a note says so.

= Is it secure? =

Yes. Card details never reach your shop: they are entered on the GembaPay / Stripe checkout page. Every webhook is signed with your webhook secret and a timestamp, and the plugin rejects unsigned, altered or replayed webhooks.

== External services ==

This plugin connects to the GembaPay API (https://api.gembapay.com), operated by GEMBA EOOD, to take payments and refunds. It is required for the plugin to work.

* **When a customer places an order with GembaPay**, the plugin sends the order ID, the order total, the currency and a short description ("Order #123") to GembaPay, which returns the checkout page address. The customer is then redirected to the GembaPay checkout page (https://payment.gembapay.com), where they enter their payment details for Stripe or PayPal.
* **When you refund an order from WooCommerce**, the plugin sends the GembaPay order ID, the refund amount and the reason you entered.
* **GembaPay sends webhooks to your shop** (the Webhook URL shown on the plugin settings page) when a payment is completed, refunded, expired or disputed.

Your API key is sent with each request to identify your merchant account. No other customer data is sent by the plugin.

GembaPay terms of service: https://gembapay.com/terms
GembaPay privacy policy: https://gembapay.com/privacy

Card payments are processed by Stripe (https://stripe.com/legal, https://stripe.com/privacy) and PayPal payments by PayPal (https://www.paypal.com/legalhub, https://www.paypal.com/privacy), under their own terms.

== Screenshots ==

1. Payment method on checkout page
2. GembaPay unified checkout
3. Plugin settings page
4. Order details with transaction info

== Changelog ==

= 1.3.0 =
* New: refunds from the WooCommerce order screen (full and partial) through the GembaPay API.
* New: refunds made in the GembaPay dashboard or directly in Stripe / PayPal are added to the order (payment.refunded webhook).
* New: expired payment requests cancel the unpaid order; declined attempts add an order note without failing the order.
* New: disputes put the order on hold with a note and restore it when the dispute is won.
* New: the order shows how the customer paid — card, Apple Pay, Google Pay, Link or PayPal.
* New: verifies the timestamped X-GembaPay-Signature-V2 header (replay protection); the old signature is only used when no V2 header is sent.
* New: a payment whose amount or currency does not match the order puts the order on hold instead of completing it.
* Fix: the provider transaction reference is now saved, so it shows on the order, the thank-you page and the emails.
* Fix: test webhooks, subscription events and orders that are not from this shop are acknowledged instead of being retried for 72 hours.
* Fix: no PHP 8.2 dynamic-property notices; log location text works with current WooCommerce.
* Tested with WordPress 7.1 and WooCommerce 11.2.

= 1.2.0 =
* Updated the plugin listing and gateway labels to the supported payment methods (Stripe cards and PayPal).
* Clarified that funds settle directly into the merchant's own connected account.

= 1.1.1 =
* Fixed webhook signature verification to match GembaPay's signing exactly: the signature is a bare HMAC-SHA256 hex string (no "sha256=" prefix) over the raw request body.

= 1.1.0 =
* Order detail / status lookups now use the authenticated merchant API endpoints.
* readme: aligned stated requirements to WooCommerce 7.0 / PHP 8.0.

= 1.0.0 =
* Initial release

== Upgrade Notice ==

= 1.3.0 =
Refunds from WooCommerce, automatic refund/expiry/dispute updates, payment method on the order and replay-protected webhooks. Recommended update.

= 1.1.0 =
Uses the authenticated GembaPay merchant endpoints for order lookups. Recommended update.
