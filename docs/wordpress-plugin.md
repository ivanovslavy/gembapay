# GembaPay WordPress Plugin

[Back to Documentation](README.md) | [Back to Main README](../README.md)

---

## Overview

GembaPay for WooCommerce is a unified payment gateway enabling merchants to accept cryptocurrency, credit card (Stripe), and PayPal payments through a single integration.

**Version:** 1.1.0  
**License:** GPL v2 or later  
**Requires WordPress:** 5.8+  
**Requires WooCommerce:** 5.0+  
**Requires PHP:** 7.4+

---

## Table of Contents

1. [Features](#features)
2. [Installation](#installation)
3. [Configuration](#configuration)
4. [Payment Flow](#payment-flow)
5. [Webhook Setup](#webhook-setup)
6. [Order Management](#order-management)
7. [Troubleshooting](#troubleshooting)

---

## Features

**Triple Payment System**

| Method | Settlement | Fees |
|--------|------------|------|
| Crypto (ETH, BNB, MATIC, USDC, USDT) | Direct to wallet (P2P) | 1% GembaPay |
| Stripe (Cards, Apple Pay, Google Pay) | Stripe account | 1% GembaPay + Stripe fees |
| PayPal (Balance, Bank, Pay Later) | PayPal account | 1% GembaPay + PayPal fees |

**Key Benefits**

- Unified checkout for all payment methods
- Non-custodial crypto payments (P2P)
- 86+ fiat currencies supported
- Real-time Chainlink oracle rates
- Instant settlement
- Secure hosted payment page

---

## Installation

### Method 1: WordPress Admin

1. Download the plugin zip file
2. Navigate to WordPress Admin → Plugins → Add New
3. Click "Upload Plugin"
4. Select the zip file and click "Install Now"
5. Click "Activate Plugin"

### Method 2: FTP/SSH

```bash
# Upload to plugins directory
cd /var/www/html/your-site/wp-content/plugins/
unzip gembapay-woocommerce.zip
```

### Method 3: WP-CLI

```bash
wp plugin install /path/to/gembapay-woocommerce.zip --activate
```

### Verification

```bash
wp plugin list | grep gembapay
# Expected: gembapay-woocommerce    active    none    1.1.0
```

---

## Configuration

### Step 1: Access Plugin Settings

Navigate to: **WooCommerce → Settings → Payments → GembaPay**

### Step 2: Configure Settings

| Setting | Description | Example |
|---------|-------------|---------|
| Enable/Disable | Activate the payment gateway | Checked |
| Title | Payment method name at checkout | Pay with Crypto or Card |
| Description | Customer-facing description | Secure payment via crypto, card or PayPal |
| API URL | GembaPay API endpoint | https://api.gembapay.com |
| API Key | Your merchant API key | gembapay_live_xxxx... |
| Webhook Secret | Secret for signature verification | your_webhook_secret |

### Step 3: Obtain API Key

1. Login to Merchant Dashboard: https://merchant-dashboard.gembapay.com
2. Navigate to Settings → API Keys
3. Click "Create New Key"
4. Copy the key (shown only once)
5. Paste in plugin settings

### Step 4: Connect Payment Providers

In the GembaPay Merchant Dashboard:

1. **Crypto:** Configure wallet address for each network
2. **Stripe:** Complete Stripe Connect onboarding
3. **PayPal:** Complete PayPal Commerce Platform onboarding

---

## Payment Flow

### Customer Experience

```
1. WooCommerce Checkout
   └─► Customer fills shipping/billing info
   └─► Selects "Pay with Crypto, Card or PayPal"
   └─► Clicks "Place Order"

2. Redirect to GembaPay
   └─► Secure hosted payment page
   └─► Customer chooses payment method

3. Payment Processing
   ├─► Stripe: Enter card details, confirm
   ├─► PayPal: Login and approve
   └─► Crypto: Connect wallet, select network/token, confirm

4. Confirmation
   └─► Webhook sent to WooCommerce
   └─► Order status → "Processing"
   └─► Customer redirected to thank you page
```

### Technical Flow

```
WooCommerce ──► GembaPay API ──► Payment Page
                    │
      ┌─────────────┼─────────────┐
      │             │             │
      ▼             ▼             ▼
   Stripe        PayPal      Blockchain
      │             │             │
      └─────────────┼─────────────┘
                    │
                    ▼
              Webhook Handler
                    │
                    ▼
              Order Updated
```

---

## Webhook Setup

### Webhook URL

Register this URL in your GembaPay Merchant Dashboard:

```
https://your-store.com/wp-json/gembapay/v1/webhook
```

### Webhook Payload

**Crypto Payment:**
```json
{
  "event": "payment.completed",
  "payment": {
    "id": "uuid",
    "orderId": "WC-100-abc123",
    "txHash": "0x...",
    "usdAmount": 108.70,
    "network": "polygon",
    "customerAddress": "0x..."
  },
  "timestamp": "2026-01-22T12:00:00.000Z"
}
```

**Stripe Payment:**
```json
{
  "event": "payment.completed",
  "payment": {
    "id": "uuid",
    "orderId": "WC-101-def456",
    "txHash": "pi_...",
    "usdAmount": 108.70,
    "network": "stripe"
  },
  "timestamp": "2026-01-22T12:00:00.000Z"
}
```

**PayPal Payment:**
```json
{
  "event": "payment.completed",
  "payment": {
    "id": "uuid",
    "orderId": "WC-102-ghi789",
    "txHash": "5GP...",
    "usdAmount": 108.70,
    "network": "paypal"
  },
  "timestamp": "2026-01-22T12:00:00.000Z"
}
```

### Signature Verification

The plugin automatically verifies webhook signatures using HMAC-SHA256:

```php
$expected = 'sha256=' . hash_hmac('sha256', json_encode($payload), $webhook_secret);
if (!hash_equals($expected, $signature)) {
    return new WP_REST_Response(['error' => 'Invalid signature'], 401);
}
```

---

## Order Management

### Order Metadata

| Meta Key | Description |
|----------|-------------|
| _gembapay_order_id | GembaPay order identifier |
| _gembapay_tx_hash | Transaction hash/ID |
| _gembapay_network | Payment network (polygon/stripe/paypal) |
| _gembapay_payment_provider | Provider type (crypto/stripe/paypal) |
| _gembapay_amount | USD amount |

### Order Status Mapping

| GembaPay Event | WooCommerce Status |
|----------------|-------------------|
| Payment Created | Pending Payment |
| payment.completed | Processing |
| payment.failed | Failed |

### Admin Order View

Payment details are displayed in the order admin page with:
- Provider type (Crypto/Stripe/PayPal)
- Network/Method used
- Transaction hash with explorer link (for crypto)
- USD amount

---

## Troubleshooting

### Common Issues

| Issue | Cause | Solution |
|-------|-------|----------|
| "No API key" | API key not configured | Add API key in plugin settings |
| 404 on webhook | REST API disabled | Flush permalinks (Settings → Permalinks → Save) |
| Order not updating | Webhook not received | Verify webhook URL in GembaPay dashboard |
| "Invalid signature" | Wrong webhook secret | Ensure secret matches in both places |
| Payment method missing | Provider not connected | Connect provider in GembaPay Dashboard |

### Debug Mode

Enable WordPress debug logging:

```php
// wp-config.php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
```

View logs:

```bash
tail -f /var/www/html/wp-content/debug.log | grep GembaPay
```

### Verify Plugin Status

```bash
# Check if plugin is active
wp plugin list | grep gembapay

# Check webhook endpoint
curl -I https://your-store.com/wp-json/gembapay/v1/webhook
# Should return 200 or 401 (not 404)
```

---

## Customization

### Modify Payment Method Title

```php
add_filter('woocommerce_gateway_title', function($title, $gateway_id) {
    if ($gateway_id === 'gembapay') {
        return 'Card, PayPal or Crypto';
    }
    return $title;
}, 10, 2);
```

### Custom Order Note

```php
add_action('gembapay_payment_completed', function($order, $payment_data) {
    $network = $payment_data['network'] ?? 'unknown';
    $order->add_order_note(
        sprintf('Payment received via %s', ucfirst($network))
    );
}, 10, 2);
```

---

## Support

- Documentation: https://gembapay.com/docs
- Contact: https://gembapay.com/contact

---

## Related Documentation

- [Integration Guide](integration.md)
- [API Reference](api-reference.md)
- [Webhooks](webhooks.md)
