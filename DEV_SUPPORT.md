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
9. [NFT Gift System](#nft-gift-system)
10. [Multi-Currency Support](#multi-currency-support)
11. [Network and Token Support](#network-and-token-support)
12. [Stripe Integration](#stripe-integration)
13. [PayPal Integration](#paypal-integration)
14. [Error Handling](#error-handling)
15. [Complete Flow Diagram](#complete-flow-diagram)
16. [Best Practices](#best-practices)
17. [Support](#support)

---

## Overview

GembaPay enables merchants to accept cryptocurrency, credit card (Stripe), and PayPal payments through a unified API.

### Key Features

**Payment Methods:**
- Cryptocurrency payments (ETH, BNB, POL, USDC, USDT)
- Credit card payments via Stripe Connect
- PayPal payments via Commerce Platform
- Unified webhook notifications for all payment types

**Technical Capabilities:**
- Non-custodial settlement (P2P wallet-to-wallet)
- Multi-chain support (Ethereum, BSC, Polygon Mainnet)
- Multi-currency pricing (86+ fiat currencies with automatic USD conversion)
- Direct stablecoin transfers (~60% lower gas costs)
- Chainlink oracle price validation
- NFT gift rewards for successful crypto payments
- Payment Links and QR codes — no-code shareable payment pages (single-use or multi-use/donations)

**Authentication Options:**
- Email/password registration and login
- Web3 wallet authentication (MetaMask, Coinbase Wallet)
- Wallet linking to existing accounts
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
// Customer chooses: Crypto, Stripe, or PayPal

// 4. Receive webhook when payment completes
// POST https://yourstore.com/webhooks/gembapay
```

---

## Merchant Registration

### Option 1: Email/Password Registration

```bash
curl -X POST https://api.gembapay.com/api/auth/register \
  -H "Content-Type: application/json" \
  -d '{
    "email": "merchant@example.com",
    "password": "SecurePassword123!",
    "companyName": "My Store"
  }'
```

### Option 2: Web3 Wallet Registration

**Step 1: Get Nonce**
```bash
curl -X POST https://api.gembapay.com/api/auth/web3/nonce \
  -H "Content-Type: application/json" \
  -d '{"walletAddress": "0x..."}'
```

**Step 2: Sign Message (Frontend)**
```javascript
const message = `Sign in to GembaPay Merchant Dashboard\n\nNonce: ${nonce}\nAddress: ${walletAddress}`;
const signature = await signer.signMessage(message);
```

**Step 3: Register**
```bash
curl -X POST https://api.gembapay.com/api/auth/web3/register \
  -H "Content-Type: application/json" \
  -d '{
    "walletAddress": "0x...",
    "signature": "0x...",
    "nonce": "abc123...",
    "companyName": "My Store",
    "email": "optional@email.com"
  }'
```

### Option 3: Dashboard UI

Visit https://merchant-dashboard.gembapay.com/register

- Choose Email or Web3 registration
- Web3: Connect MetaMask → Fill company details
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
  "paymentUrl": "https://payment.gembapay.com/checkout/ORDER-123",
  "amountUsd": "108.70",
  "amountOriginal": 100.00,
  "currencyOriginal": "EUR",
  "exchangeRate": 1.087,
  "allowedMethods": ["crypto", "stripe", "paypal"],
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
        Accepts: Crypto • Cards • PayPal
      </p>
    </div>
  );
}

export default CheckoutButton;
```

### Iframe Embed

```html
<!-- Embed checkout directly in your page -->
<iframe 
  src="https://payment.gembapay.com/checkout/ORDER-123"
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
- **Cryptocurrency** - ETH, BNB, POL, USDC, USDT (non-custodial P2P)
- **Stripe** - Credit/Debit cards, Apple Pay, Google Pay
- **PayPal** - PayPal balance, bank account, Pay Later

### Cryptocurrency Flow

1. Connect wallet (MetaMask or Coinbase Wallet)
2. Select network (Ethereum, BSC, or Polygon)
3. Select token (Native or Stablecoin)
4. For native tokens: Lock quote → Confirm within time window
5. For stablecoins: Direct transfer (lower gas)
6. Payment confirmed on blockchain
7. Webhook sent to merchant
8. Optional: Claim free NFT gift

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

app.post('/webhooks/gembapay', express.json(), async (req, res) => {
  // 1. Verify signature
  const signature = req.headers['x-gembapay-signature'];
  const payload = JSON.stringify(req.body);
  
  const expected = 'sha256=' + crypto
    .createHmac('sha256', process.env.WEBHOOK_SECRET)
    .update(payload)
    .digest('hex');

  if (signature !== expected) {
    console.error('Invalid webhook signature');
    return res.status(401).send('Invalid signature');
  }

  // 2. Process event
  const { event, payment } = req.body;

  console.log(`Webhook received: ${event} for order ${payment.orderId}`);

  switch (event) {
    case 'payment.completed':
      await handlePaymentCompleted(payment);
      break;
    case 'payment.failed':
      await handlePaymentFailed(payment);
      break;
    case 'payment.expired':
      await handlePaymentExpired(payment);
      break;
    default:
      console.log('Unknown event:', event);
  }

  // 3. Respond quickly
  res.status(200).json({ received: true });
});

async function handlePaymentCompleted(payment) {
  // Update order in database
  await db.orders.update(
    { orderId: payment.orderId },
    { 
      status: 'paid',
      txHash: payment.txHash,
      network: payment.network,
      paymentProvider: payment.paymentProvider,
      usdAmount: payment.usdAmount,
      paidAt: new Date()
    }
  );

  // Fulfill order
  await fulfillOrder(payment.orderId);
  
  // Send confirmation email
  await sendConfirmationEmail(payment.orderId);
}

async function handlePaymentFailed(payment) {
  await db.orders.update(
    { orderId: payment.orderId },
    { status: 'failed', failureReason: payment.failureReason }
  );
}

async function handlePaymentExpired(payment) {
  await db.orders.update(
    { orderId: payment.orderId },
    { status: 'expired' }
  );
}

app.listen(3000);
```

### Webhook Headers

```
Content-Type: application/json
X-GembaPay-Event: payment.completed
X-GembaPay-Signature: sha256=abc123...
X-GembaPay-Merchant-Id: your-merchant-id
X-GembaPay-Timestamp: 2026-01-25T08:15:05.193Z
```

### Webhook Payload - Crypto Payment

```json
{
  "event": "payment.completed",
  "payment": {
    "id": "uuid-payment-id",
    "orderId": "ORDER-123",
    "txHash": "0x84579c019dc334474a9421072b633bdb981d0a1ab4aa4a2e48aba4189e05179d",
    "network": "bsc",
    "paymentProvider": "crypto",
    "usdAmount": 108.70,
    "customerAddress": "0xc45112B334822811f4418e2f13C2C80FF790C949",
    "tokenAmount": "0.157892",
    "tokenSymbol": "BNB",
    "status": "confirmed"
  },
  "timestamp": "2026-01-25T08:15:05.193Z"
}
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
    "txHash": "pi_3abc123xyz...",
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
    "txHash": "5GP12345ABC789...",
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
| `payment.completed` | Payment successfully processed |
| `payment.failed` | Payment failed |
| `payment.expired` | Payment request expired (24h) |

### Retry Policy

If your endpoint fails (non-2xx response), GembaPay retries:

| Attempt | Delay |
|---------|-------|
| 1 | Immediate |
| 2 | 2 seconds |
| 3 | 4 seconds |
| 4 | 8 seconds |

**Total:** 4 attempts | **Timeout:** 15 seconds per attempt

---

## Payment Status Polling

As an alternative to webhooks, you can poll for payment status:

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
| `confirmed` | Crypto payment confirmed on blockchain |
| `completed` | Payment fully completed |
| `failed` | Payment failed |
| `expired` | Payment request expired |

---

## NFT Gift System

Every successful **crypto** payment includes a free commemorative NFT gift.

### Claiming NFT (Customer)

After payment confirmation, customers see a "Claim NFT" button:

```javascript
// Frontend - after payment confirmed
const claimNFT = async (orderId) => {
  // Connect wallet if not connected
  const provider = new ethers.BrowserProvider(window.ethereum);
  const signer = await provider.getSigner();
  
  // Call claim endpoint
  const response = await fetch(
    `https://api.gembapay.com/api/customer/payment/${orderId}/claim-nft`,
    {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        claimAddress: await signer.getAddress()
      })
    }
  );
  
  const data = await response.json();
  
  if (data.success) {
    console.log('NFT claimed! TX:', data.txHash);
    console.log('Token ID:', data.tokenId);
  }
};
```

### NFT Contract Details

| Network | Contract Address | Max Supply |
|---------|------------------|------------|
| Ethereum | `0xD24a89dc1686C2F88d33A70250473495459C564a` | 10,000 |
| BSC | `0x8Fee75865E8D87cdB844Ef5676D2D6456262BA7A` | 10,000 |
| Polygon | `0xD24a89dc1686C2F88d33A70250473495459C564a` | 10,000 |

---

## Multi-Currency Support

### Supported Currencies (86+)

GembaPay supports 86+ fiat currencies for merchant pricing:

**Chainlink Oracle Currencies (On-chain rates):**
USD, EUR, GBP, JPY, CHF, AUD, CAD, CNY, KRW, SGD, INR, BRL, TRY, ZAR, NZD, MXN

**API-Supported Currencies:**
AED, ARS, BGN, BDT, BOB, CLP, COP, CZK, DKK, DOP, EGP, FJD, GEL, GHS, GTQ, HKD, HNL, HRK, HUF, IDR, ILS, IQD, ISK, JMD, JOD, KES, KGS, KHR, KWD, KZT, LAK, LBP, LKR, MAD, MDL, MKD, MMK, MNT, MYR, NGN, NOK, NPR, OMR, PEN, PGK, PHP, PKR, PLN, PYG, QAR, RON, RSD, RUB, SAR, SEK, THB, TTD, TWD, TZS, UAH, UGX, UYU, UZS, VES, VND, XAF, XOF, and more.

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

## Network and Token Support

### Production Networks

| Network | Chain ID | Native Token | Status |
|---------|----------|--------------|--------|
| Ethereum Mainnet | 1 | ETH | ✅ Production |
| BNB Smart Chain | 56 | BNB | ✅ Production |
| Polygon | 137 | POL | ✅ Production |

### Supported Tokens

| Token | Type | Networks | Gas Cost |
|-------|------|----------|----------|
| ETH | Native | Ethereum | Higher |
| BNB | Native | BSC | Higher |
| POL | Native | Polygon | Higher |
| USDC | Stablecoin | All | ~60% lower |
| USDT | Stablecoin | All | ~60% lower |

### Smart Contract Addresses

**Payment Gateway (Mainnet):**

| Network | Address | Explorer |
|---------|---------|----------|
| Ethereum | `0xD9c4169061B92970b86afBF32dad4Ecfd749179e` | [Etherscan](https://etherscan.io/address/0xD9c4169061B92970b86afBF32dad4Ecfd749179e) |
| BSC | `0xeE3d1CbD3cAF2D9194CbfC5B1bE8fdD5c3953eE1` | [BscScan](https://bscscan.com/address/0xeE3d1CbD3cAF2D9194CbfC5B1bE8fdD5c3953eE1) |
| Polygon | `0x7cceCb66E7Fa6255244035533E31791bD1Fff254` | [PolygonScan](https://polygonscan.com/address/0x7cceCb66E7Fa6255244035533E31791bD1Fff254) |

**NFT Gift Contract (Mainnet):**

| Network | Address | Explorer |
|---------|---------|----------|
| Ethereum | `0xD24a89dc1686C2F88d33A70250473495459C564a` | [Etherscan](https://etherscan.io/address/0xD24a89dc1686C2F88d33A70250473495459C564a) |
| BSC | `0x8Fee75865E8D87cdB844Ef5676D2D6456262BA7A` | [BscScan](https://bscscan.com/address/0x8Fee75865E8D87cdB844Ef5676D2D6456262BA7A) |
| Polygon | `0xD24a89dc1686C2F88d33A70250473495459C564a` | [PolygonScan](https://polygonscan.com/address/0xD24a89dc1686C2F88d33A70250473495459C564a) |

**Stablecoin Addresses:**

| Token | Ethereum | BSC | Polygon |
|-------|----------|-----|---------|
| USDC | `0xA0b86991c6218b36c1d19D4a2e9Eb0cE3606eB48` | `0x8AC76a51cc950d9822D68b83fE1Ad97B32Cd580d` | `0x3c499c542cEF5E3811e1192ce70d8cC03d5c3359` |
| USDT | `0xdAC17F958D2ee523a2206206994597C13D831ec7` | `0x55d398326f99059fF775485246999027B3197955` | `0xc2132D05D31c914a87C6611C10748AEb04B58e8F` |

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
│     payment.gembapay.com/checkout/ORDER-123                  │
└──────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌──────────────────────────────────────────────────────────────┐
│  4. Customer: Select payment method on unified checkout      │
└──────────────────────────────────────────────────────────────┘
                              │
            ┌─────────────────┼─────────────────┐
            ▼                 ▼                 ▼
      ┌──────────┐      ┌──────────┐      ┌──────────┐
      │  Crypto  │      │  Stripe  │      │  PayPal  │
      │ MetaMask │      │ Checkout │      │ Checkout │
      │ Coinbase │      │          │      │          │
      └────┬─────┘      └────┬─────┘      └────┬─────┘
           │                 │                 │
           │ P2P to         │ Card            │ PayPal
           │ Merchant       │ Payment         │ Payment
           │ Wallet         │                 │
           │                 │                 │
           └─────────────────┼─────────────────┘
                             ▼
┌──────────────────────────────────────────────────────────────┐
│  5. GembaPay → Merchant: Webhook notification                │
│     POST /webhooks/gembapay                                  │
│     { event: "payment.completed", payment: {...} }           │
│     Network: "ethereum" | "bsc" | "polygon" | "stripe" | "paypal" │
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
│  7. Customer (Crypto only): Claim free NFT gift              │
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

- **Show payment methods upfront** - Display crypto/card/PayPal options
- **Provide transaction hash** - Show blockchain TX hash for crypto payments
- **Link to block explorer** - Let customers verify their transactions
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
| GET | `/api/merchant/transactions` | List transactions |
| GET | `/api/merchant/stats` | Get statistics |
| PUT | `/api/merchant/webhook` | Configure webhook |
| POST | `/api/merchant/apikeys` | Create API key |

### Customer Endpoints (Public)

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/customer/payment/:orderId` | Get payment details |
| GET | `/api/customer/payment/:orderId/status` | Check payment status |
| POST | `/api/customer/payment/:orderId/lock` | Lock quote (crypto native) |
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
| Crypto | 1% | Gas only | 1% + gas |
| Stripe | 1% | ~2.9% + $0.30 | ~4% |
| PayPal | 1% | ~2.9% + $0.30 | ~4% |

---

**Last Updated:** January 2026  
**Document Version:** 3.0  
**Author:** Slavcho Ivanov

**© 2025-2026 Gemba EOOD - Bulgaria, European Union**
