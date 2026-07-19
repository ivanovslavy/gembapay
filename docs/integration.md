# GembaPay Integration Guide

[Back to Documentation](README.md) | [Back to Main README](../README.md)

---

## Overview

This guide walks you through integrating GembaPay into your website or application. Accept crypto (ETH, BNB, POL, USDC, USDT), Stripe, and PayPal payments through a unified checkout.

---

## Table of Contents

1. [Getting Started](#getting-started)
2. [Integration Methods](#integration-methods)
3. [Payment Links (No-Code)](#payment-links-no-code)
4. [Subscriptions (Recurring Billing)](#subscriptions-recurring-billing)
5. [API Integration](#api-integration)
6. [WordPress Integration](#wordpress-integration)
7. [Testing](#testing)
8. [Going Live](#going-live)

---

## Getting Started

### Step 1: Register as a Merchant

1. Visit https://merchant-dashboard.gembapay.com
2. Create an account with email or Web3 wallet
3. Complete KYC verification
4. Wait for account approval

### Step 2: Complete KYC Verification

**Basic KYC (Required at Registration):**
- Government-issued ID
- Selfie verification
- Business information
- Wallet address

**Limits with Basic KYC:**
- 2,000 EUR per day
- 60,000 EUR per month

**Full KYC (For Higher Limits):**
- Enhanced verification
- Proof of address
- Business registration documents
- Source of funds declaration
- Unlimited processing

### Step 3: Configure Payment Methods

In your merchant dashboard:

1. **Crypto Payments:** Add your wallet address (works immediately)
2. **Stripe:** Complete Stripe Connect onboarding (Settings → Payment Methods → Stripe)
3. **PayPal:** Complete PayPal Commerce Platform onboarding (Settings → Payment Methods → PayPal)

### Step 4: Generate API Key

1. Go to Settings > API Keys
2. Click "Create New Key"
3. Copy and securely store the key (shown only once)
4. Use this key for API authentication

---

## Integration Methods

| Method | Best For | Complexity |
|--------|----------|------------|
| Payment Links | Sellers with no website or store | None (no-code) |
| Subscriptions | Recurring / membership billing | None (no-code) |
| REST API | Custom applications | Medium |
| WordPress Plugin | WooCommerce stores | Low |
| JavaScript Widget | Any website | Low |
| Redirect | Simple integration | Very Low |

---

## Payment Links (No-Code)

If you do not have a website or online store, you can accept payments without writing any code. Create a Payment Link in the Merchant Dashboard and share it — no API key or integration required.

### Create and Share

1. Go to **Dashboard → Payment Links → New**
2. Set the currency and description, and either set a fixed amount or **leave the amount empty so the payer chooses how much to pay** ("pay what you want", ideal for donations)
3. Choose which payment methods to offer (from the ones you have enabled)
4. Choose **single-use** (one-off) or **multi-use** (reusable, e.g. for donations)
5. *(Optional)* Set an expiry, a maximum number of uses, or a total-amount limit
6. Choose which customer details to collect — Name, Email, Phone, Note — each **Off / Optional / Required**, or none at all (e.g. for donations)
7. Pick **Test** or **Live** mode (Live requires approved KYC)
8. Save — you get a shareable URL and a QR code

The link is hosted at `https://payment.gembapay.com/link/<token>`. Share the URL or print the QR code; the customer opens it, fills in any required details, and pays with any enabled method. Funds settle directly to you (non-custodial), and you can track status and usage in the dashboard.

### Single-Use vs Multi-Use

| Type | Behavior | Typical use |
|------|----------|-------------|
| Single-use | Closes automatically after one successful payment | One-off invoice for a product or service |
| Multi-use | Stays open; many people can pay; optional max-uses and max-total limits | Donations, recurring collection, tip jar |

Developers who want to read a link's details or start checkout programmatically can use the public [Payment Links endpoints](api-reference.md#payment-links).

---

## Subscriptions (Recurring Billing)

Charge your customers automatically on a recurring schedule — for memberships, SaaS plans, or any service billed per period. You create plans **programmatically via the GembaPay API**, then share a hosted subscribe link or paste an embeddable button on your own website.

Recurring billing is handled by the **native subscription engines of Stripe and PayPal**, which automatically charge each cycle and manage retries and dunning for failed payments. **Crypto subscriptions are not supported**, because a wallet cannot be auto-charged without on-chain authorization for each individual charge.

### Create a Plan

Plans are created **programmatically via the API** — `POST /api/subscriptions` with your merchant API key. Provide the plan **name** (e.g. Basic, Pro, Ultimate), **price** (EUR), **billing interval** (weekly, monthly, or yearly), the methods to accept (**Stripe**, **PayPal**, or both), and optional free **trial days**. The response includes the plan’s hosted **subscribe link** and an **embeddable button**. See [Create a Plan](api-reference.md#create-a-plan) for the full request and response.

Pass an `externalRef` to make creation **idempotent** — re-running your provisioning code returns the existing plan instead of creating a duplicate. Create as many tiers as you like and manage them via the API.

### Share or Embed

- **Subscribe link** — share `the plan's hosted subscribe page` directly (email, chat, social).
- **Embeddable button** — use the button snippet returned by the API and paste it onto your own website. Clicking it opens the GembaPay-hosted subscribe page.

On the subscribe page the customer enters their email, chooses a method, and pays the first cycle. The auto-recurring subscription then begins immediately.

> **First-charge discounts are Stripe-only.** If you start a subscription with a first-charge discount, the customer must pay via **Stripe** — PayPal cannot apply a per-subscription discount (its only discount path is a plan-level trial cycle, which is not per-subscriber), so a PayPal subscribe carrying a discount is rejected rather than silently charged full price.

### Subscription Lifecycle

```
Customer clicks subscribe link / button
        │
        ▼
Enters email, pays first cycle (Stripe or PayPal)
        │
        ▼
Subscription ACTIVE — provider auto-charges each cycle
        │
        ├──► each paid cycle  → recorded in Transactions + subscription.payment webhook
        │
        ├──► upgrade  → effective immediately (proration / catch-up charge)
        ├──► downgrade → effective at next renewal (no refund)
        │
        ▼
Customer cancels via Manage page (cancel-at-period-end)
        │
        ▼
Active until end of paid period, then STOPS
```

### Upgrades and Downgrades

- **Upgrade** (to a higher-priced plan), mid-cycle: with **Stripe**, the prorated difference for the remainder of the current cycle is charged immediately, and the full new price applies at the next renewal. With **PayPal**, the existing subscription is cancelled and replaced by the new plan, with a catch-up charge.
- **Downgrade** (to a lower-priced plan): takes effect at the **next renewal**. No refund is issued for the current, already-paid period.

### How Customers Cancel

Cancellation is **self-service** — no customer account or password. Each merchant has a **Manage page**:

1. The customer opens the merchant's Manage page and enters their **email address**
2. They receive a **6-digit code by email**
3. They enter the code and see the subscription(s) they hold **with that merchant**
4. They cancel

Cancellation is **cancel-at-period-end**: the subscription stays active until the end of the period already paid for, then stops — **no refund** for the current period. The Manage page is **merchant-scoped**: the manage link carries the merchant's token, so the same email used at different merchants only ever shows that merchant's subscriptions. Identity is proven by the 6-digit email code.

### Fees and Records

- GembaPay charges **1% per billing cycle**, collected automatically as a Stripe application fee or a PayPal platform fee. EUR is the base currency.
- Each successful billing cycle is recorded in the merchant's **Transactions** and fires the merchant's **`subscription.payment`** webhook. Subscriptions use dedicated `subscription.*` events with a flat payload (no `orderId`), **not** `payment.completed` — see [Webhooks](webhooks.md#subscription-events).

### For Developers

Plans are created and managed with the merchant API (dashboard JWT); the hosted subscribe and manage pages use public endpoints. See the [Subscriptions API endpoints](api-reference.md#subscriptions).

---

## API Integration

### Basic Payment Flow

```
1. Customer ready to pay
       │
       ▼
2. Your server creates payment request (POST /api/merchant/payment-request)
       │
       ▼
3. Redirect customer to paymentUrl
       │
       ▼
4. Customer selects payment method (Crypto, Stripe, or PayPal)
       │
       ▼
5. Customer completes payment
       │
       ▼
6. GembaPay sends webhook to your server
       │
       ▼
7. You fulfill the order
```

### Create Payment Request

```bash
curl -X POST https://api.gembapay.com/api/merchant/payment-request \
  -H "Authorization: Bearer YOUR_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "amount": 49.99,
    "currency": "EUR",
    "orderId": "ORDER-12345",
    "description": "Premium Subscription"
  }'
```

**Response:**
```json
{
  "success": true,
  "orderId": "ORDER-12345",
  "paymentUrl": "https://payment.gembapay.com/checkout/3f9c1e7a-2b4d-4c8e-9f10-a2b3c4d5e6f7",
  "amountUsd": "54.35",
  "amountOriginal": 49.99,
  "currencyOriginal": "EUR",
  "exchangeRate": 1.087,
  "allowedMethods": ["crypto", "stripe", "paypal"],
  "expiresAt": "2026-01-25T12:00:00.000Z"
}
```

### Redirect Customer

Redirect the customer to the `paymentUrl`:

> **Important:** Always redirect the customer to the `paymentUrl` returned by the API — never construct the checkout URL yourself from your orderId. It now contains an unguessable token.

```javascript
// Frontend JavaScript
window.location.href = paymentRequest.paymentUrl;
```

Or open in a new window:

```javascript
window.open(paymentRequest.paymentUrl, '_blank');
```

### Handle Webhook

```javascript
// Express.js example
const crypto = require('crypto');

// Use the RAW body (express.raw) and compare against BARE hex (no "sha256=" prefix),
// computed over the exact bytes received. See docs/webhooks.md for the full contract.
app.post('/webhooks/gembapay',
  express.raw({ type: 'application/json' }),
  (req, res) => {
    const signature = req.headers['x-gembapay-signature'] || '';
    const expected = crypto
      .createHmac('sha256', process.env.WEBHOOK_SECRET)
      .update(req.body)                       // req.body is a Buffer of the raw bytes
      .digest('hex');
    const a = Buffer.from(signature, 'utf8');
    const b = Buffer.from(expected, 'utf8');
    if (a.length !== b.length || !crypto.timingSafeEqual(a, b)) {
      return res.status(401).send('Invalid signature');
    }

    const { event, payment } = JSON.parse(req.body.toString('utf8'));
    if (event === 'payment.completed') {
      // network: 'stripe', 'paypal', or (when enabled) 'ethereum' / 'bsc' / 'polygon'
      fulfillOrder(payment.orderId);
    } else if (event === 'subscription.payment') {
      recordSubscriptionCycle(JSON.parse(req.body.toString('utf8'))); // no orderId; use eventId
    }
    res.status(200).send('OK');
  });
```

### Check Payment Status

For additional verification, check payment status via API:

```bash
curl https://api.gembapay.com/api/customer/payment/ORDER-12345/status
```

> **Note:** This public endpoint returns only non-sensitive status fields. For full order details (including `customerEmail`), use the authenticated `GET /api/merchant/payment/:orderId` with your API key.

---

## Code Examples

### Node.js

```javascript
const axios = require('axios');

const GEMBAPAY_API_KEY = process.env.GEMBAPAY_API_KEY;
const GEMBAPAY_API_URL = 'https://api.gembapay.com';

async function createPayment(amount, currency, orderId, description) {
  const response = await axios.post(
    `${GEMBAPAY_API_URL}/api/merchant/payment-request`,
    {
      amount,
      currency,
      orderId,
      description
    },
    {
      headers: {
        'Authorization': `Bearer ${GEMBAPAY_API_KEY}`,
        'Content-Type': 'application/json'
      }
    }
  );
  
  return response.data;
}

// Usage
const payment = await createPayment(99.99, 'EUR', 'ORDER-001', 'Product Purchase');
console.log('Redirect to:', payment.paymentUrl);
console.log('Allowed methods:', payment.allowedMethods);
```

### Python

```python
import requests
import os

GEMBAPAY_API_KEY = os.environ['GEMBAPAY_API_KEY']
GEMBAPAY_API_URL = 'https://api.gembapay.com'

def create_payment(amount, currency, order_id, description):
    response = requests.post(
        f'{GEMBAPAY_API_URL}/api/merchant/payment-request',
        json={
            'amount': amount,
            'currency': currency,
            'orderId': order_id,
            'description': description
        },
        headers={
            'Authorization': f'Bearer {GEMBAPAY_API_KEY}',
            'Content-Type': 'application/json'
        }
    )
    
    return response.json()

# Usage
payment = create_payment(99.99, 'EUR', 'ORDER-001', 'Product Purchase')
print(f"Redirect to: {payment['paymentUrl']}")
print(f"Allowed methods: {payment['allowedMethods']}")
```

### PHP

```php
<?php
$apiKey = getenv('GEMBAPAY_API_KEY');
$apiUrl = 'https://api.gembapay.com';

function createPayment($amount, $currency, $orderId, $description) {
    global $apiKey, $apiUrl;
    
    $ch = curl_init();
    
    curl_setopt_array($ch, [
        CURLOPT_URL => "$apiUrl/api/merchant/payment-request",
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer $apiKey",
            "Content-Type: application/json"
        ],
        CURLOPT_POSTFIELDS => json_encode([
            'amount' => $amount,
            'currency' => $currency,
            'orderId' => $orderId,
            'description' => $description
        ])
    ]);
    
    $response = curl_exec($ch);
    curl_close($ch);
    
    return json_decode($response, true);
}

// Usage
$payment = createPayment(99.99, 'EUR', 'ORDER-001', 'Product Purchase');
echo "Redirect to: " . $payment['paymentUrl'] . "\n";
echo "Allowed methods: " . implode(', ', $payment['allowedMethods']) . "\n";
```

### React Frontend

```javascript
import { useState } from 'react';

function CheckoutButton({ product }) {
  const [loading, setLoading] = useState(false);
  const [currency, setCurrency] = useState('USD');
  
  const currencies = ['USD', 'EUR', 'GBP', 'JPY', 'CAD', 'AUD'];
  
  const handleCheckout = async () => {
    setLoading(true);
    
    try {
      const response = await fetch('/api/create-payment', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          productId: product.id,
          amount: product.price,
          currency: currency
        })
      });
      
      const data = await response.json();
      
      if (data.success) {
        // Redirect to GembaPay unified checkout
        // Customer chooses Crypto, Stripe, or PayPal there
        window.location.href = data.paymentUrl;
      }
    } catch (error) {
      console.error('Payment error:', error);
    } finally {
      setLoading(false);
    }
  };
  
  return (
    <div className="checkout-container">
      <select 
        value={currency} 
        onChange={(e) => setCurrency(e.target.value)}
        className="currency-select"
      >
        {currencies.map(c => (
          <option key={c} value={c}>{c}</option>
        ))}
      </select>
      
      <button 
        onClick={handleCheckout} 
        disabled={loading}
        className="checkout-button"
      >
        {loading ? 'Processing...' : 'Proceed to Payment'}
      </button>
      
      <p className="payment-methods">
        Accepts: Crypto • Cards • PayPal
      </p>
    </div>
  );
}
```

---

## WordPress Integration

For WooCommerce stores, use the GembaPay WordPress plugin.

See [WordPress Plugin Documentation](wordpress-plugin.md) for installation and configuration.

**Quick Install:**
1. Download plugin from Merchant Dashboard → API Keys
2. Upload to WordPress: Plugins → Add New → Upload Plugin
3. Activate and configure with your API key
4. Enable in WooCommerce → Settings → Payments

---

## Testing

### Test Environment

Use test API keys (prefixed with `gembapay_test_`) during development.

**Test Networks:**
- Ethereum Sepolia
- BSC Testnet
- Polygon Amoy

### Test Tokens

Get test USDC and USDT from faucets or contact support.

### Test Checklist

- [ ] Payment request creation
- [ ] Customer redirect to payment page
- [ ] Crypto payment completion
- [ ] Stripe payment completion
- [ ] PayPal payment completion
- [ ] Webhook receipt and verification
- [ ] Order fulfillment
- [ ] Error handling

---

## Going Live

### Pre-Launch Checklist

- [ ] Complete Full KYC verification
- [ ] Add production wallet address
- [ ] Connect production Stripe account
- [ ] Connect production PayPal account
- [ ] Generate production API key
- [ ] Update webhook URL to production
- [ ] Test with small real payments
- [ ] Review Terms of Service compliance

### Switch to Production

1. Replace test API key with production key
2. Verify webhook URL points to production server
3. Verify webhook signatures with production secret
4. Monitor first transactions in dashboard

---

## Iframe Embed

Embed the unified checkout directly in your page. Use the `paymentUrl` returned by the API as the iframe `src` — never construct the checkout URL yourself from your orderId. It now contains an unguessable token.

```html
<!-- src is the paymentUrl returned by POST /api/merchant/payment-request -->
<iframe 
  src="https://payment.gembapay.com/checkout/3f9c1e7a-2b4d-4c8e-9f10-a2b3c4d5e6f7"
  width="100%"
  height="700"
  frameborder="0"
  style="border-radius: 12px; border: 1px solid #333;"
></iframe>
```

---

## Support

For technical support:
- Documentation: https://gembapay.com/docs
- Contact: https://gembapay.com/contact

---

## Related Documentation

- [API Reference](api-reference.md)
- [Webhooks](webhooks.md)
- [WordPress Plugin](wordpress-plugin.md)
