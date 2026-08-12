# GembaPay

**Unified payment gateway for cards and PayPal.**

Accept credit cards (via Stripe) and PayPal through a single API. Funds settle directly into the merchant's own connected account — GembaPay never holds your money.

[![npm](https://img.shields.io/npm/v/gembapay)](https://www.npmjs.com/package/gembapay)
[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](https://opensource.org/licenses/MIT)

---

## Features

- **One API, two payment methods** — Stripe (cards/Apple Pay/Google Pay) and PayPal
- **Direct settlement** — Payments route straight into your own Stripe or PayPal account
- **51+ currencies** — Price in any supported currency
- **Test mode built-in** — Stripe and PayPal sandbox environments for development
- **TypeScript support** — Full type definitions included
- **Zero dependencies** — Uses only Node.js built-in modules

## Install

```bash
npm install gembapay
```

## Quick Start

```javascript
const GembaPay = require('gembapay');

const gembapay = new GembaPay({
  apiKey: 'gembapay_test_your_key',  // test key for development
  webhookSecret: 'your_webhook_secret'
});

// Create a payment
const payment = await gembapay.createPayment({
  orderId: 'ORDER-123',
  amount: 100.00,
  currency: 'EUR'
});

console.log(payment.paymentUrl);
// → https://payment.gembapay.com/checkout/3f9c1e7a-2b4d-4c8e-9f10-a2b3c4d5e6f7

console.log(payment.allowedMethods);
// → ['stripe', 'paypal']
```

## Usage

### Create Payment

```javascript
const payment = await gembapay.createPayment({
  orderId: 'ORDER-456',
  amount: 49.99,
  currency: 'USD',
  description: 'Premium Plan'
});

// Redirect customer to unified checkout
// Redirect to payment.paymentUrl as-is (unguessable token, not your orderId)
res.redirect(payment.paymentUrl);
```

### Check Status

```javascript
const status = await gembapay.getPaymentStatus('ORDER-456');

console.log(status.status);   // 'completed'
console.log(status.network);  // 'stripe', 'paypal'
```

### Webhook Verification

> **⚠️ SDK update required.** The live GembaPay backend signs webhooks as **bare hex** (no
> `sha256=` prefix) over the **raw request body**. The current `verifyWebhook`/`webhookHandler`
> in this SDK build expect a `sha256=` prefix and hash the parsed body, so they reject genuine
> webhooks until the SDK is updated. Until then, verify manually against the raw body per
> [docs/webhooks.md](../../docs/webhooks.md#signature-verification). Subscription cycles arrive as
> `subscription.payment` (flat payload, no `orderId`), not `payment.completed`.

```javascript
const express = require('express');
const app = express();

app.post('/webhooks/gembapay', express.json(), 
  gembapay.webhookHandler(async (event) => {
    if (event.event === 'payment.completed') {
      console.log(`✓ Order ${event.payment.orderId} paid`);
      console.log(`  $${event.payment.usdAmount} via ${event.payment.network}`);
      await fulfillOrder(event.payment.orderId);
    }
  })
);
```

Or verify manually:

```javascript
app.post('/webhooks/gembapay', express.json(), (req, res) => {
  const signature = req.headers['x-gembapay-signature'];
  
  if (!gembapay.verifyWebhook(req.body, signature)) {
    return res.status(401).send('Invalid signature');
  }
  
  const { event, payment, testMode } = req.body;
  // Process event...
  
  res.json({ received: true });
});
```

### Transactions & Stats

```javascript
const stats = await gembapay.getStats();
// const transactions = await gembapay.listTransactions();  // see note below
```

> **Note:** `listTransactions()` currently targets a **dashboard (JWT) endpoint** and returns
> `401` with an API key. Use `getStats()` / `getPaymentStatus()` programmatically, or view
> transactions in the dashboard, until the SDK/endpoint is aligned.

## Test Mode

Use test API keys (`gembapay_test_...`) for development. Test mode automatically uses:

| Method | Test Environment |
|--------|-----------------|
| Stripe | Test cards (`4242 4242 4242 4242`) |
| PayPal | Sandbox accounts |

```javascript
// SDK detects test mode from your API key
const gembapay = new GembaPay({
  apiKey: 'gembapay_test_your_key'
});

console.log(gembapay.isTestMode); // true
```

Claim free test tokens at [Developer Resources](https://gembapay.com/developers).

## Express.js Example

```javascript
const express = require('express');
const GembaPay = require('gembapay');
const app = express();

const gembapay = new GembaPay({
  apiKey: process.env.GEMBAPAY_API_KEY,
  webhookSecret: process.env.GEMBAPAY_WEBHOOK_SECRET
});

// Create payment endpoint
app.post('/api/checkout', express.json(), async (req, res) => {
  const { orderId, amount, currency } = req.body;
  
  try {
    const payment = await gembapay.createPayment({ orderId, amount, currency });
    res.json({ paymentUrl: payment.paymentUrl });
  } catch (err) {
    res.status(err.statusCode || 500).json({ error: err.message });
  }
});

// Webhook endpoint
app.post('/webhooks/gembapay', express.json(),
  gembapay.webhookHandler(async (event) => {
    if (event.event === 'payment.completed') {
      await fulfillOrder(event.payment.orderId);
    }
  })
);

app.listen(3000);
```

## API Reference

### `new GembaPay(options)`

| Option | Type | Required | Description |
|--------|------|----------|-------------|
| `apiKey` | `string` | ✓ | API key from [Merchant Dashboard](https://merchant-dashboard.gembapay.com) |
| `webhookSecret` | `string` | | Webhook signing secret |
| `baseUrl` | `string` | | Custom API URL (default: `https://api.gembapay.com`) |
| `timeout` | `number` | | Request timeout in ms (default: `30000`) |

### Methods

| Method | Description |
|--------|-------------|
| `createPayment(params)` | Create payment request → returns `paymentUrl` |
| `getPayment(orderId)` | Get payment details |
| `getPaymentStatus(orderId)` | Check payment status |
| `listTransactions(params?)` | List merchant transactions |
| `getStats()` | Get merchant statistics |
| `verifyWebhook(payload, signature)` | Verify webhook signature |
| `parseWebhook(req)` | Parse & verify Express request |
| `webhookHandler(fn)` | Express webhook middleware |

## Fee Structure

| Method | Fee |
|--------|-----|
| Stripe (Cards, Apple Pay, Google Pay) | 1% + €0.20 + Stripe fees |
| PayPal (Balance, Bank, Pay Later) | 1% + €0.20 + PayPal fees |

## Links

- [Documentation](https://gembapay.com/docs)
- [Merchant Dashboard](https://merchant-dashboard.gembapay.com)
- [Integration Guide](https://gembapay.com/integration)
- [GitHub](https://github.com/ivanovslavy/gembapay)
- [Developer Resources](https://gembapay.com/developers)

## Support

- Email: contacts@gembapay.com
- GitHub Issues: [github.com/ivanovslavy/gembapay/issues](https://github.com/ivanovslavy/gembapay/issues)

## License

[MIT](LICENSE) © GEMBA EOOD
