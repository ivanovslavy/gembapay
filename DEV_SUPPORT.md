# Developer Support Guide (DEV_SUPPORT)

**Version:** 3.0  
**Author:** Slavcho Ivanov  
**Last Updated:** January 2026

Comprehensive technical guide for integrating **GembaPay** into merchant applications.

---

## Table of Contents

1. [Overview](#overview)
2. [Quick Start](#quick-start)
3. [Merchant Registration](#merchant-registration)
4. [Backend Integration](#backend-integration)
5. [Frontend Integration](#frontend-integration)
6. [Customer Payment Flow](#customer-payment-flow)
7. [Webhook Integration](#webhook-integration)
8. [Payment Status Polling](#payment-status-polling)
10. [Multi-Currency Support](#multi-currency-support)
12. [Stripe Integration](#stripe-integration)
13. [PayPal Integration](#paypal-integration)
14. [Error Handling](#error-handling)
15. [Complete Flow Diagram](#complete-flow-diagram)
16. [Best Practices](#best-practices)
17. [Support](#support)

---

## Overview

GembaPay enables merchants to accept credit card (Stripe) and PayPal payments through a unified API.

### Key Features

**Payment Methods:**
- Credit card payments via Stripe Connect
- PayPal payments via Commerce Platform
- Unified webhook notifications for all payment types

**Technical Capabilities:**
- Direct settlement into the merchant's own provider account
- Multi-currency pricing (51+ fiat currencies with automatic USD conversion)
- Live exchange rates for multi-currency pricing
- NFT gift rewards for successful payments
- Payment Links and QR codes — no-code shareable payment pages (single-use or multi-use/donations); fixed amount or payer-chosen ("pay what you want")

**Authentication Options:**
- Email/password registration and login
- API key authentication for integrations

---

## Quick Start

### Five-Minute Integration

```javascript
// 1. Install dependencies
npm install axios

// 2. Create payment request
const axios = require('axios');

const response = await axios.post(
  'https://api.gembapay.com/api/merchant/payment-request',
  {
    orderId: 'ORDER-123',
    amount: 100.00,
    currency: 'EUR',
    description: 'Product purchase'
  },
  {
    headers: {
      'Authorization': 'Bearer gembapay_live_your_api_key',
      'Content-Type': 'application/json'
    }
  }
);

// 3. Redirect customer to unified checkout
window.location.href = response.data.paymentUrl;
// Customer chooses: Stripe or PayPal

// 4. Receive webhook when payment completes
// POST https://yourstore.com/webhooks/gembapay
```

---

## Merchant Registration

### Registration

```bash
curl -X POST https://api.gembapay.com/api/auth/register \
  -H "Content-Type: application/json" \
  -d '{
    "email": "merchant@example.com",
    "password": "SecurePassword123!",
    "companyName": "My Store"
  }'
```

### Option 3: Dashboard UI

Visit https://merchant-dashboard.gembapay.com/register

- Register with your email address
- Email: Fill all fields → Submit
- Complete KYC verification

### API Key Generation

**Via Dashboard:**
1. Login to https://merchant-dashboard.gembapay.com
2. Navigate to Settings → API Keys
3. Click "Create New Key"
4. Copy key immediately (shown only once!)

**Via API:**
```bash
# Login to get JWT token
TOKEN=$(curl -s -X POST https://api.gembapay.com/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"merchant@example.com","password":"SecurePassword123!"}' | jq -r '.token')

# Create API key
curl -X POST https://api.gembapay.com/api/merchant/apikeys \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"name": "Production API Key"}'
```

**Response:**
```json
{
  "success": true,
  "apiKey": "gembapay_live_abc123def456...",
  "keyPrefix": "gembapay_live_abc1",
  "name": "Production API Key"
}
```

**API Key Format:**
- Production: `gembapay_live_[64 hex characters]`
- Test: `gembapay_test_[64 hex characters]`

---

## Backend Integration

### Node.js Express Example

```javascript
const express = require('express');
const axios = require('axios');
require('dotenv').config();

const app = express();
app.use(express.json());

const GEMBAPAY_API_URL = 'https://api.gembapay.com';
const GEMBAPAY_API_KEY = process.env.GEMBAPAY_API_KEY;

// Create payment request
app.post('/api/checkout', async (req, res) => {
  const { productId, customerEmail, currency, quantity } = req.body;

  try {
    const product = await db.products.findOne({ id: productId });
    const totalAmount = product.price * quantity;
    const orderId = `ORDER-${Date.now()}-${productId}`;

    const response = await axios.post(
      `${GEMBAPAY_API_URL}/api/merchant/payment-request`,
      {
        orderId: orderId,
        amount: totalAmount,
        currency: currency || 'USD',
        description: `${quantity}x ${product.name}`
      },
      {
        headers: {
          'Authorization': `Bearer ${GEMBAPAY_API_KEY}`,
          'Content-Type': 'application/json'
        }
      }
    );

    // Save to database
    await db.payments.insert({
      orderId: orderId,
      amountUsd: response.data.amountUsd,
      amountOriginal: response.data.amountOriginal,
      currencyOriginal: response.data.currencyOriginal,
      status: 'pending',
      paymentUrl: response.data.paymentUrl,
      allowedMethods: response.data.allowedMethods
    });

    res.json({
      success: true,
      orderId: orderId,
      paymentUrl: response.data.paymentUrl,
      allowedMethods: response.data.allowedMethods
    });

  } catch (error) {
    console.error('Payment creation failed:', error.response?.data || error.message);
    res.status(500).json({ error: 'Payment creation failed' });
  }
});

app.listen(3000);
```

### Python Example

```python
import requests
import os
from datetime import datetime

GEMBAPAY_API_URL = 'https://api.gembapay.com'
GEMBAPAY_API_KEY = os.environ['GEMBAPAY_API_KEY']

def create_payment(amount, currency, product_name):
    order_id = f"ORDER-{int(datetime.now().timestamp())}"
    
    response = requests.post(
        f'{GEMBAPAY_API_URL}/api/merchant/payment-request',
        json={
            'orderId': order_id,
            'amount': amount,
            'currency': currency,
            'description': product_name
        },
        headers={
            'Authorization': f'Bearer {GEMBAPAY_API_KEY}',
            'Content-Type': 'application/json'
        }
    )
    
    data = response.json()
    return {
        'orderId': order_id,
        'paymentUrl': data['paymentUrl'],
        'allowedMethods': data['allowedMethods']
    }

# Usage
payment = create_payment(99.99, 'EUR', 'Premium Subscription')
print(f"Redirect to: {payment['paymentUrl']}")
print(f"Methods: {payment['allowedMethods']}")
```

### PHP Example

```php
<?php
$apiKey = getenv('GEMBAPAY_API_KEY');
$apiUrl = 'https://api.gembapay.com';

function createPayment($amount, $currency, $description) {
    global $apiKey, $apiUrl;
    
    $orderId = 'ORDER-' . time() . '-' . rand(1000, 9999);
    
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
            'orderId' => $orderId,
            'amount' => $amount,
            'currency' => $currency,
            'description' => $description
        ])
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) {
        throw new Exception("Payment creation failed: $response");
    }
    
    return json_decode($response, true);
}

// Usage
$payment = createPayment(99.99, 'EUR', 'Product Purchase');
header("Location: " . $payment['paymentUrl']);
```

### Payment Request Parameters

**Required:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `orderId` | string | Unique order identifier (max 100 chars) |
| `amount` | number | Payment amount in specified currency |

**Optional:**

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `currency` | string | `USD` | ISO 4217 currency code |
| `description` | string | - | Payment description (max 500 chars) |

**Response:**
```json
{
  "success": true,
  "orderId": "ORDER-123",
  "paymentUrl": "https://payment.gembapay.com/checkout/3f9c1e7a-2b4d-4c8e-9f10-a2b3c4d5e6f7",
  "amountUsd": "108.70",
  "amountOriginal": 100.00,
  "currencyOriginal": "EUR",
  "exchangeRate": 1.087,
  "allowedMethods": ["stripe", "paypal"],
  "expiresAt": "2026-01-25T12:00:00.000Z"
}
```

---

## Frontend Integration

### React Checkout Component

```javascript
import { useState } from 'react';

function CheckoutButton({ product }) {
  const [loading, setLoading] = useState(false);
  const [currency, setCurrency] = useState('USD');
  
  const currencies = ['USD', 'EUR', 'GBP', 'JPY', 'CAD', 'AUD', 'CHF', 'CNY'];
  
  const handleCheckout = async () => {
    setLoading(true);
    
    try {
      const response = await fetch('/api/checkout', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          productId: product.id,
          quantity: 1,
          currency: currency
        })
      });
      
      const data = await response.json();
      
      if (data.success) {
        // Redirect to GembaPay unified checkout
        window.location.href = data.paymentUrl;
      } else {
        alert('Payment creation failed');
      }
    } catch (error) {
      console.error('Checkout error:', error);
      alert('Checkout failed');
    } finally {
      setLoading(false);
    }
  };
  
  return (
    <div className="checkout-container">
      <h3>{product.name}</h3>
      <p>${product.price}</p>
      
      <select 
        value={currency} 
        onChange={(e) => setCurrency(e.target.value)}
      >
        {currencies.map(c => (
          <option key={c} value={c}>{c}</option>
        ))}
      </select>
      
      <button 
        onClick={handleCheckout} 
        disabled={loading}
      >
        {loading ? 'Processing...' : 'Proceed to Payment'}
      </button>
      
      <p className="payment-methods">
        Accepts: Cards • PayPal
      </p>
    </div>
  );
}

export default CheckoutButton;
```

### Iframe Embed

Use the `paymentUrl` returned by the API as the iframe `src` — never construct the checkout URL yourself from your orderId. It now contains an unguessable token.

```html
<!-- Embed checkout directly in your page -->
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

## Customer Payment Flow

### Payment Methods

Customer chooses between:
- **Stripe** - Credit/Debit cards, Apple Pay, Google Pay
- **PayPal** - PayPal balance, bank account, Pay Later

### Stripe Flow

1. Select "Pay with Card" on checkout
2. Redirect to Stripe Checkout
3. Enter card details / Use Apple Pay / Google Pay
4. Complete 3D Secure if required
5. Payment processed
6. Webhook sent to merchant
7. Redirect to success page

### PayPal Flow

1. Select "Pay with PayPal" on checkout
2. Redirect to PayPal
3. Login and approve payment
4. Select payment source (balance, bank, Pay Later)
5. Confirm payment
6. Webhook sent to merchant
7. Redirect to success page

---

## Webhook Integration

### Configure Webhook

**Via Dashboard:**
Settings → Webhooks → Enter URL → Save

**Via API:**
```bash
curl -X PUT https://api.gembapay.com/api/merchant/webhook \
  -H "Authorization: Bearer YOUR_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "webhookUrl": "https://yourstore.com/webhooks/gembapay"
  }'
```

### Webhook Handler

```javascript
const crypto = require('crypto');
const express = require('express');
const app = express();

// Use express.raw so the signed bytes are preserved (see docs/webhooks.md).
app.post('/webhooks/gembapay', express.raw({ type: 'application/json' }), async (req, res) => {
  // 1. Verify signature — BARE hex (no "sha256=" prefix) over the RAW body.
  const signature = req.headers['x-gembapay-signature'] || '';
  const expected = crypto
    .createHmac('sha256', process.env.WEBHOOK_SECRET)
    .update(req.body)                 // req.body is a Buffer of the raw bytes
    .digest('hex');
  const a = Buffer.from(signature, 'utf8');
  const b = Buffer.from(expected, 'utf8');
  if (a.length !== b.length || !crypto.timingSafeEqual(a, b)) {
    console.error('Invalid webhook signature');
    return res.status(401).send('Invalid signature');
  }

  // 2. Process event
  const body = JSON.parse(req.body.toString('utf8'));
  const { event, payment } = body;

  switch (event) {
    case 'payment.completed':
      await handlePaymentCompleted(payment);       // one-off: stripe / paypal
      break;
    case 'subscription.payment':
      await recordSubscriptionCycle(body);         // flat payload, no orderId → use body.eventId
      break;
    case 'subscription.activated':
    case 'subscription.canceled':
    case 'subscription.payment_failed':
      await updateSubscriptionState(body);
      break;
    case 'webhook.test':
      break;                                       // dashboard connectivity check
    default:
      console.log('Unknown event:', event);
  }

  // 3. Respond quickly
  res.status(200).json({ received: true });
});

async function handlePaymentCompleted(payment) {
  await db.orders.update(
    { orderId: payment.orderId },
    { status: 'paid', reference: payment.reference, network: payment.network,
      paymentProvider: payment.paymentProvider, usdAmount: payment.usdAmount, paidAt: new Date() }
  );
  await fulfillOrder(payment.orderId);
  await sendConfirmationEmail(payment.orderId);
}

app.listen(3000);
```

### Webhook Headers

```
Content-Type: application/json
X-GembaPay-Event: payment.completed
X-GembaPay-Signature: 9f8b2c1a...   (bare HMAC-SHA256 hex, no "sha256=" prefix)
X-GembaPay-Merchant-Id: your-merchant-id
X-GembaPay-Timestamp: 2026-01-25T08:15:05.193Z
```

### Webhook Payload - Stripe Payment

```json
{
  "event": "payment.completed",
  "payment": {
    "orderId": "ORDER-123",
    "amount": 108.70,
    "usdAmount": 108.70,
    "currency": "USD",
    "status": "completed",
    "reference": "pi_3abc123xyz...",
    "network": "stripe",
    "paymentProvider": "stripe",
    "customerAddress": "customer@example.com",
    "tokenAmount": 108.70
  },
  "timestamp": "2026-01-25T08:15:05.193Z"
}
```

### Webhook Payload - PayPal Payment

```json
{
  "event": "payment.completed",
  "payment": {
    "orderId": "ORDER-123",
    "amount": 108.70,
    "usdAmount": 108.70,
    "currency": "USD",
    "status": "completed",
    "reference": "5GP12345ABC789...",
    "network": "paypal",
    "paymentProvider": "paypal",
    "customerAddress": "payer@example.com",
    "tokenAmount": 108.70
  },
  "timestamp": "2026-01-25T08:15:05.193Z"
}
```

### Webhook Events

| Event | Description |
|-------|-------------|
| `payment.completed` | A one-off payment was processed (stripe / paypal) |
| `subscription.activated` | A subscription became active |
| `subscription.payment` | A recurring subscription cycle was paid |
| `subscription.payment_failed` | A subscription cycle charge failed |
| `subscription.canceled` | A subscription was cancelled |

> `payment.failed` / `payment.expired` are **not currently emitted**. Subscription events use a
> flat payload with **no `orderId`** (idempotency key = `eventId`). See `docs/webhooks.md`.

### Retry Policy

If your endpoint fails (non-2xx response), GembaPay retries in two phases:

1. **Immediate:** 3 attempts, with a 2s then 4s pause between them.
2. **Extended:** if all 3 fail, the delivery is retried **hourly for up to 72 hours** (reminder
   email after 24h), then marked `exhausted`.

**Timeout:** 15 seconds per attempt. Manual retry available from the dashboard.

---

## Payment Status Polling

As an alternative to webhooks, you can poll for payment status:

> **Note:** For server-side polling, prefer the authenticated `GET /api/merchant/payment-status/:orderId` (with your API key) over the public status endpoint shown below.

```javascript
async function pollPaymentStatus(orderId, maxAttempts = 60) {
  for (let i = 0; i < maxAttempts; i++) {
    const response = await fetch(
      `https://api.gembapay.com/api/customer/payment/${orderId}/status`
    );
    const data = await response.json();
    
    if (data.status === 'confirmed' || data.status === 'completed') {
      return { success: true, payment: data };
    }
    
    if (data.status === 'failed' || data.status === 'expired') {
      return { success: false, status: data.status };
    }
    
    // Wait 5 seconds before next poll
    await new Promise(resolve => setTimeout(resolve, 5000));
  }
  
  return { success: false, status: 'timeout' };
}
```

**Status Values:**

| Status | Description |
|--------|-------------|
| `pending` | Awaiting payment |
| `processing` | Payment in progress |
| `confirmed` | Payment confirmed by the provider |
| `completed` | Payment fully completed |
| `failed` | Payment failed |
| `expired` | Payment request expired |

---

## Multi-Currency Support

### Supported Currencies (51+)

GembaPay supports 51+ fiat currencies for merchant pricing:

**Core Currencies (live reference rates):**
USD, EUR, GBP, JPY, CHF, AUD, CAD, CNY, KRW, SGD, INR, BRL, TRY, ZAR, NZD, MXN

**API-Supported Currencies:**
AED, ARS, BDT, BOB, CLP, COP, CZK, DKK, DOP, EGP, FJD, GEL, GHS, GTQ, HKD, HNL, HRK, HUF, IDR, ILS, IQD, ISK, JMD, JOD, KES, KGS, KHR, KWD, KZT, LAK, LBP, LKR, MAD, MDL, MKD, MMK, MNT, MYR, NGN, NOK, NPR, OMR, PEN, PGK, PHP, PKR, PLN, PYG, QAR, RON, RSD, RUB, SAR, SEK, THB, TTD, TWD, TZS, UAH, UGX, UYU, UZS, VES, VND, XAF, XOF, and more.

### Currency Conversion Example

```javascript
const response = await axios.post(
  'https://api.gembapay.com/api/merchant/payment-request',
  {
    orderId: 'ORDER-EUR-123',
    amount: 100.00,
    currency: 'EUR'
  },
  { headers: { 'Authorization': 'Bearer YOUR_API_KEY' } }
);

// Response:
// amountOriginal: 100.00
// currencyOriginal: "EUR"
// amountUsd: "108.70"
// exchangeRate: 1.087
```

### Get Available Currencies

```bash
curl https://api.gembapay.com/api/customer/currencies
```

### Convert Currency

```bash
curl "https://api.gembapay.com/api/customer/convert?amount=100&from=EUR&to=USD"
```

---

## Stripe Integration

### Connect Stripe Account (Merchant)

**Via Dashboard:**
Settings → Payment Methods → Stripe → Connect

**Via API:**
```javascript
// 1. Create Connect account
const createResponse = await axios.post(
  'https://api.gembapay.com/api/stripe/connect/create',
  {},
  { headers: { 'Authorization': `Bearer ${JWT_TOKEN}` } }
);

// 2. Get onboarding link
const onboardingResponse = await axios.post(
  'https://api.gembapay.com/api/stripe/connect/onboarding',
  {},
  { headers: { 'Authorization': `Bearer ${JWT_TOKEN}` } }
);

// 3. Redirect merchant to Stripe onboarding
window.location.href = onboardingResponse.data.url;
```

### Check Stripe Connection Status

```javascript
const statusResponse = await axios.get(
  'https://api.gembapay.com/api/stripe/connect/status',
  { headers: { 'Authorization': `Bearer ${JWT_TOKEN}` } }
);

// Response:
// { connected: true, onboardingCompleted: true, accountId: "acct_..." }
```

---

## PayPal Integration

### Connect PayPal Account (Merchant)

**Via Dashboard:**
Settings → Payment Methods → PayPal → Connect

**Via API:**
```javascript
// 1. Start onboarding
const onboardResponse = await axios.post(
  'https://api.gembapay.com/api/paypal/onboard',
  {},
  { headers: { 'Authorization': `Bearer ${JWT_TOKEN}` } }
);

// 2. Redirect merchant to PayPal
window.location.href = onboardResponse.data.onboardingUrl;
```

### Check PayPal Connection Status

```javascript
const statusResponse = await axios.get(
  `https://api.gembapay.com/api/paypal/merchant-status/${merchantId}`,
  { headers: { 'Authorization': `Bearer ${JWT_TOKEN}` } }
);

// Response:
// { connected: true, merchantId: "..." }
```

---

## Error Handling

### HTTP Status Codes

| Code | Description |
|------|-------------|
| 200 | Success |
| 400 | Bad Request - Invalid parameters |
| 401 | Unauthorized - Invalid or missing API key |
| 403 | Forbidden - Insufficient permissions |
| 404 | Not Found - Resource does not exist |
| 409 | Conflict - Duplicate order ID or payment in progress |
| 410 | Gone - Quote expired |
| 429 | Too Many Requests - Rate limit exceeded |
| 500 | Internal Server Error |

### Error Response Format

```json
{
  "success": false,
  "error": "Error message description"
}
```

### Common Errors and Solutions

| Error | Cause | Solution |
|-------|-------|----------|
| `Invalid API key` | Wrong or revoked API key | Check API key in dashboard |
| `Order not found` | Invalid orderId | Verify orderId exists |
| `Duplicate order ID` | orderId already used | Use unique orderId for each request |
| `Quote expired` | Native token quote timeout | Create new quote |
| `Payment method not enabled` | Stripe/PayPal not connected | Complete onboarding in dashboard |
| `Invalid currency` | Unsupported currency code | Use supported ISO 4217 code |

### Error Handling Example

```javascript
try {
  const response = await axios.post(
    'https://api.gembapay.com/api/merchant/payment-request',
    paymentData,
    { headers: { 'Authorization': `Bearer ${API_KEY}` } }
  );
  
  return response.data;
  
} catch (error) {
  if (error.response) {
    const status = error.response.status;
    const message = error.response.data?.error || 'Unknown error';
    
    switch (status) {
      case 401:
        console.error('Invalid API key');
        // Refresh API key or alert admin
        break;
      case 409:
        console.error('Duplicate order ID:', message);
        // Generate new orderId and retry
        break;
      case 429:
        console.error('Rate limited, retrying...');
        await sleep(1000);
        // Retry request
        break;
      default:
        console.error(`Error ${status}: ${message}`);
    }
  } else {
    console.error('Network error:', error.message);
  }
  
  throw error;
}
```

---

## Complete Flow Diagram

```
┌──────────────────────────────────────────────────────────────┐
│  1. Customer → Merchant: Initiate checkout                   │
│     Customer clicks "Buy Now" on merchant website            │
└──────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌──────────────────────────────────────────────────────────────┐
│  2. Merchant → GembaPay API: Create payment request          │
│     POST /api/merchant/payment-request                       │
│     Response: { paymentUrl, allowedMethods, amountUsd }      │
└──────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌──────────────────────────────────────────────────────────────┐
│  3. Merchant → Customer: Redirect to payment page            │
│     payment.gembapay.com/checkout/3f9c1e7a-...-e6f7          │
└──────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌──────────────────────────────────────────────────────────────┐
│  4. Customer: Select payment method on unified checkout      │
└──────────────────────────────────────────────────────────────┘
                              │
                  ┌─────────────────┴─────────────────┐
                  ▼                                   ▼
            ┌──────────┐                        ┌──────────┐
            │  Stripe  │                        │  PayPal  │
            │ Checkout │                        │ Checkout │
            └────┬─────┘                        └────┬─────┘
                 │                                   │
                 │ Card                              │ PayPal
                 │ Payment                           │ Payment
                 │                                   │
                 └─────────────────┬─────────────────┘
                                   ▼
┌──────────────────────────────────────────────────────────────┐
│  5. GembaPay → Merchant: Webhook notification                │
│     POST /webhooks/gembapay                                  │
│     { event: "payment.completed", payment: {...} }           │
│     Provider: "stripe" | "paypal"                            │
└──────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌──────────────────────────────────────────────────────────────┐
│  6. Merchant: Fulfill order                                  │
│     - Verify webhook signature                               │
│     - Update database                                        │
│     - Send confirmation email                                │
│     - Deliver product/service                                │
└──────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌──────────────────────────────────────────────────────────────┐
│  7. Customer: Claim the free commemorative NFT gift          │
│     POST /api/customer/payment/:orderId/claim-nft            │
└──────────────────────────────────────────────────────────────┘
```

---

## Best Practices

### Security

- **Store API keys securely** - Use environment variables, never commit to git
- **Verify webhook signatures** - Always validate X-GembaPay-Signature header
- **Use HTTPS only** - All endpoints and webhooks must use HTTPS
- **Rotate API keys periodically** - Create new keys and deprecate old ones
- **Implement idempotency** - Handle duplicate webhooks gracefully

### Performance

- **Respond to webhooks quickly** - Process asynchronously, respond with 200 immediately
- **Cache exchange rates** - If displaying prices, cache rates (5-minute TTL)
- **Index order IDs** - Ensure database indexes on orderId for fast lookups
- **Implement retry logic** - Use exponential backoff for API failures

### User Experience

- **Show payment methods upfront** - Display card and PayPal options
- **Show the provider reference** - Let customers match the charge on their statement
- **Mobile-responsive checkout** - Ensure checkout works on all devices
- **Clear error messages** - Help customers understand and recover from errors

### Code Quality

```javascript
// Good: Environment variables for sensitive data
const API_KEY = process.env.GEMBAPAY_API_KEY;

// Good: Unique order IDs
const orderId = `ORDER-${Date.now()}-${crypto.randomUUID().slice(0, 8)}`;

// Good: Webhook signature verification
const isValid = verifySignature(payload, signature, secret);
if (!isValid) return res.status(401).send('Invalid');

// Good: Idempotent webhook handling
const existing = await db.orders.findOne({ orderId, status: 'paid' });
if (existing) return res.status(200).json({ received: true }); // Already processed
```

---

## API Reference Summary

### Merchant Endpoints (API Key Auth)

| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/api/merchant/payment-request` | Create payment request |
| GET | `/api/merchant/transactions` | List transactions (**dashboard/JWT auth only — not API key**) |
| GET | `/api/merchant/stats` | Get statistics |
| PUT | `/api/merchant/webhook` | Configure webhook |
| POST | `/api/merchant/apikeys` | Create API key |

### Customer Endpoints (Public)

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/customer/payment/:orderId` | Get payment details |
| GET | `/api/customer/payment/:orderId/status` | Check payment status |
| POST | `/api/customer/payment/:orderId/claim-nft` | Claim NFT gift |
| GET | `/api/customer/currencies` | List supported currencies |
| GET | `/api/customer/convert` | Convert currency |

### Payment Link Endpoints (Public, token-based)

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/payment-links/public/:token` | Resolve a payment link |
| POST | `/api/payment-links/public/:token/checkout` | Create a payment from the link |

Payment Links are created and managed from the Merchant Dashboard (Dashboard → Payment Links).

### Stripe Endpoints (JWT Auth)

| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/api/stripe/connect/create` | Create Connect account |
| POST | `/api/stripe/connect/onboarding` | Get onboarding link |
| GET | `/api/stripe/connect/status` | Check connection status |

### PayPal Endpoints (JWT Auth)

| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/api/paypal/onboard` | Start onboarding |
| GET | `/api/paypal/merchant-status/:merchantId` | Check status |

---

## Support

### Resources

| Resource | URL |
|----------|-----|
| Website | https://gembapay.com |
| API Documentation | https://gembapay.com/docs |
| Integration Guide | https://gembapay.com/integration |
| Merchant Dashboard | https://merchant-dashboard.gembapay.com |
| GitHub | https://github.com/ivanovslavy/gembapay |
| Contact | https://gembapay.com/contact |

### Rate Limits

| Endpoint Type | Limit |
|---------------|-------|
| Payment creation | 100/minute |
| Payment status | 300/minute |
| Merchant endpoints | 60/minute |

### Fee Structure

| Payment Method | GembaPay Fee | Provider Fee | Total |
|----------------|--------------|--------------|-------|
| Stripe | 1% | ~2.9% + $0.30 | ~4% |
| PayPal | 1% | ~2.9% + $0.30 | ~4% |

---

**Last Updated:** January 2026  
**Document Version:** 3.0  
**Author:** Slavcho Ivanov

**© 2025-2026 Gemba EOOD - Bulgaria, European Union**
