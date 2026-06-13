# GembaPay Integration Guide

[Back to Documentation](README.md) | [Back to Main README](../README.md)

---

## Overview

This guide walks you through integrating GembaPay into your website or application. Accept crypto (ETH, BNB, POL, USDC, USDT), Stripe, and PayPal payments through a unified checkout.

---

## Table of Contents

1. [Getting Started](#getting-started)
2. [Integration Methods](#integration-methods)
3. [API Integration](#api-integration)
4. [WordPress Integration](#wordpress-integration)
5. [Testing](#testing)
6. [Going Live](#going-live)

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
  "paymentUrl": "https://payment.gembapay.com/checkout/ORDER-12345",
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

app.post('/webhooks/gembapay', express.json(), (req, res) => {
  // Verify signature
  const signature = req.headers['x-gembapay-signature'];
  const expectedSig = 'sha256=' + crypto
    .createHmac('sha256', process.env.WEBHOOK_SECRET)
    .update(JSON.stringify(req.body))
    .digest('hex');
  
  if (signature !== expectedSig) {
    return res.status(401).send('Invalid signature');
  }
  
  // Process the event
  const { event, payment } = req.body;
  
  if (event === 'payment.completed') {
    console.log('Payment method:', payment.network);
    // network: 'ethereum', 'bsc', 'polygon', 'stripe', or 'paypal'
    fulfillOrder(payment.orderId);
  }
  
  res.status(200).send('OK');
});
```

### Check Payment Status

For additional verification, check payment status via API:

```bash
curl https://api.gembapay.com/api/customer/payment/ORDER-12345/status
```

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

Embed the unified checkout directly in your page:

```html
<iframe 
  src="https://payment.gembapay.com/checkout/ORDER-123"
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
