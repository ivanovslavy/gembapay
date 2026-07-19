# GembaPay Webhooks

[Back to Documentation](README.md) | [Back to Main README](../README.md)

---

## Overview

GembaPay sends webhook notifications to your server when payment events occur. Webhooks allow you to automate order fulfillment and keep your system synchronized with payment status. All payment methods (Stripe, PayPal, and — where enabled — crypto) use the same webhook format.

> **Signature format (read first):** GembaPay signs each webhook with **HMAC-SHA256 as a bare
> hex string** (no `sha256=` prefix) in the `X-GembaPay-Signature` header, computed over the
> **raw request body bytes**. Verify against the raw body, not a re-serialized copy. All examples
> below use this exact scheme.

---

## Table of Contents

1. [Configuration](#configuration)
2. [Webhook Events](#webhook-events)
3. [Payload Format](#payload-format)
4. [Subscription Events](#subscription-events)
5. [Signature Verification](#signature-verification)
6. [Retry Policy](#retry-policy)
7. [Best Practices](#best-practices)
8. [Testing](#testing)

---

## Configuration

### Set Webhook URL

Configure your webhook URL in the Merchant Dashboard under Settings > Webhooks.

**Requirements:**
- HTTPS endpoint (required for production)
- Publicly accessible URL
- Responds within 15 seconds
- Returns HTTP 200-299 for success

### Webhook Secret

A webhook secret is automatically generated when you set your webhook URL. Use this secret to verify webhook signatures.

**Important:** Store your webhook secret securely. If compromised, regenerate it in the dashboard.

---

## Webhook Events

| Event | Description |
|-------|-------------|
| `payment.completed` | A one-off payment was successfully processed (Stripe, PayPal, or crypto) |
| `subscription.activated` | A subscription became active |
| `subscription.payment` | A recurring subscription cycle was paid |
| `subscription.payment_failed` | A subscription cycle charge failed |
| `subscription.canceled` | A subscription was cancelled |

> **Subscriptions fire their OWN event types** (`subscription.*`), **not** `payment.completed`, and
> their payload has a different shape (see [Subscription Events](#subscription-events)). If you sell
> subscriptions, handle those events explicitly.
>
> `webhook.test` is also sent when you press **Test Webhook** in the dashboard; treat it as a
> connectivity check, not a real payment.

---

## Payload Format

### Headers

```http
POST /your-webhook-endpoint HTTP/1.1
Host: yoursite.com
Content-Type: application/json
X-GembaPay-Event: payment.completed
X-GembaPay-Signature: 9f8b2c1a...   (bare HMAC-SHA256 hex, 64 chars, NO "sha256=" prefix)
X-GembaPay-Merchant-Id: your-merchant-id
X-GembaPay-Timestamp: 2026-01-25T08:15:05.193Z
```

### Crypto Payment Webhook

```json
{
  "event": "payment.completed",
  "payment": {
    "id": "uuid-payment-id",
    "orderId": "ORDER-12345",
    "txHash": "0x84579c019dc334474a9421072b633bdb981d0a1ab4aa4a2e48aba4189e05179d",
    "network": "bsc",
    "usdAmount": 108.70,
    "amountOriginal": 100.00,
    "currencyOriginal": "EUR",
    "exchangeRate": 1.0870,
    "customerAddress": "0xc45112B334822811f4418e2f13C2C80FF790C949",
    "tokenAmount": "0.157892",
    "tokenSymbol": "BNB",
    "status": "confirmed",
    "paymentProvider": "crypto"
  },
  "timestamp": "2026-01-25T08:15:05.193Z"
}
```

**Network values:** `ethereum`, `bsc`, `polygon`

### Stripe Payment Webhook

```json
{
  "event": "payment.completed",
  "payment": {
    "orderId": "ORDER-12345",
    "amount": 108.70,
    "usdAmount": 108.70,
    "amountOriginal": 100.00,
    "currencyOriginal": "EUR",
    "exchangeRate": 1.0870,
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

### PayPal Payment Webhook

```json
{
  "event": "payment.completed",
  "payment": {
    "orderId": "ORDER-12345",
    "amount": 108.70,
    "usdAmount": 108.70,
    "amountOriginal": 100.00,
    "currencyOriginal": "EUR",
    "exchangeRate": 1.0870,
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

> **Original amount and currency** - every `payment.completed` webhook (crypto, Stripe and
> PayPal) now also carries the amount and currency exactly as you requested it, next to the
> settled `usdAmount`:
>
> | Field | Meaning |
> |-------|---------|
> | `amountOriginal` | Amount in your original request currency (e.g. `100.00`). |
> | `currencyOriginal` | ISO code you charged in (e.g. `EUR`, `GBP`, `USD`). |
> | `exchangeRate` | Rate applied to convert `currencyOriginal` to USD at settlement. |
>
> Verify payments against `amountOriginal` + `currencyOriginal` (what you asked for), not
> only `usdAmount`.

> **Note:** `payment.failed` and `payment.expired` are **not currently emitted** by GembaPay.
> Do not build fulfilment logic around them; rely on `payment.completed` (success) and, for
> subscriptions, the `subscription.*` events below.

---

## Subscription Events

Subscriptions are billed recurringly through the **native subscription engines of Stripe and PayPal**. GembaPay receives the provider-side subscription events, reconciles them, and notifies your endpoint through **dedicated `subscription.*` events with their own payload shape** — these are **not** `payment.completed` and do **not** carry a `payment` wrapper or an `orderId`.

### Subscription events GembaPay sends to your endpoint

| Event | When |
|-------|------|
| `subscription.activated` | A subscription became active |
| `subscription.payment` | A recurring billing cycle was paid |
| `subscription.payment_failed` | A cycle charge failed |
| `subscription.canceled` | The subscription was cancelled |

### Subscription payload (flat — no `payment` wrapper, no `orderId`)

```json
{
  "event": "subscription.payment",
  "eventId": "evt_9f8b2c1a...",
  "subscriptionId": "sub_local_7c1d8e2f",
  "providerSubscriptionId": "sub_1Pabc123...",
  "planId": "plan_1a2b3c",
  "planToken": "pln_9x8y7z",
  "customerEmail": "jane@example.com",
  "status": "active",
  "amount": 19.99,
  "currency": "EUR",
  "isTestMode": false,
  "timestamp": "2026-06-29T10:00:05.193Z"
}
```

> **Idempotency:** use `eventId` (or `subscriptionId` + the cycle `timestamp`) as the idempotency
> key so a re-delivered subscription webhook fulfils a cycle only once. There is **no** `orderId`
> on subscription events.

### Provider events GembaPay consumes (internal)

For reference, GembaPay listens to and reconciles these provider-side webhooks; you never receive them directly. Handling is idempotent, so a provider re-sending an event never double-records a cycle or double-fires your webhook.

**Stripe:** `customer.subscription.created` · `customer.subscription.updated` · `customer.subscription.deleted` · `invoice.paid` (→ your `subscription.payment`) · `invoice.payment_failed` (→ your `subscription.payment_failed`).

**PayPal:** `BILLING.SUBSCRIPTION.ACTIVATED` (→ `subscription.activated`) · `BILLING.SUBSCRIPTION.UPDATED` · `BILLING.SUBSCRIPTION.CANCELLED` (→ `subscription.canceled`) · `BILLING.SUBSCRIPTION.SUSPENDED` · `PAYMENT.SALE.COMPLETED` (→ `subscription.payment`).

---

## Signature Verification

Always verify webhook signatures to ensure requests are from GembaPay.

### Node.js

Sign/verify with **HMAC-SHA256 as bare hex (no `sha256=` prefix)** over the **raw request body**.

```javascript
const crypto = require('crypto');

// `rawBody` is the exact bytes received (a Buffer or string), NOT JSON.stringify(req.body).
function verifyWebhookSignature(rawBody, signature, secret) {
  const expected = crypto
    .createHmac('sha256', secret)
    .update(rawBody)
    .digest('hex');                        // bare hex — no "sha256=" prefix
  const a = Buffer.from(signature || '', 'utf8');
  const b = Buffer.from(expected, 'utf8');
  return a.length === b.length && crypto.timingSafeEqual(a, b); // length-guard avoids a throw
}

// Express.js — capture the RAW body so the bytes match what GembaPay signed.
app.post('/webhooks/gembapay',
  express.raw({ type: 'application/json' }),
  (req, res) => {
    const signature = req.headers['x-gembapay-signature'];
    if (!verifyWebhookSignature(req.body, signature, WEBHOOK_SECRET)) {
      return res.status(401).send('Invalid signature');
    }
    const { event, payment } = JSON.parse(req.body.toString('utf8'));

    if (event === 'payment.completed') {
      fulfillOrder(payment.orderId);       // one-off payment (stripe / paypal / crypto)
    } else if (event === 'subscription.payment') {
      recordSubscriptionCycle(req.body);   // subscription cycle — no orderId, use eventId
    }
    res.status(200).send('OK');
  });
```

### Python

```python
import hmac, hashlib, json

def verify_webhook_signature(raw_body: bytes, signature: str, secret: str) -> bool:
    expected = hmac.new(secret.encode(), raw_body, hashlib.sha256).hexdigest()  # bare hex
    return hmac.compare_digest(signature or '', expected)

# Flask example — use the RAW body (request.get_data()), not request.json,
# so the bytes match what GembaPay signed.
@app.route('/webhooks/gembapay', methods=['POST'])
def handle_webhook():
    raw = request.get_data()
    signature = request.headers.get('X-GembaPay-Signature')
    if not verify_webhook_signature(raw, signature, WEBHOOK_SECRET):
        return 'Invalid signature', 401

    data = json.loads(raw)
    event = data['event']
    if event == 'payment.completed':
        fulfill_order(data['payment']['orderId'])
    elif event == 'subscription.payment':
        record_subscription_cycle(data)   # no orderId; use data['eventId']
    return 'OK', 200
```

### PHP

```php
<?php
function verifyWebhookSignature($rawBody, $signature, $secret) {
    // bare hex over the RAW body — no "sha256=" prefix, no re-encoding
    $expected = hash_hmac('sha256', $rawBody, $secret);
    return hash_equals($expected, (string) $signature);
}

// Usage — hash the raw input string, then decode a SEPARATE copy for reading.
$rawBody = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_GEMBAPAY_SIGNATURE'] ?? '';

if (!verifyWebhookSignature($rawBody, $signature, $webhookSecret)) {
    http_response_code(401);
    exit('Invalid signature');
}

$payload = json_decode($rawBody, true);
$event = $payload['event'];

if ($event === 'payment.completed') {
    fulfillOrder($payload['payment']['orderId']);
} elseif ($event === 'subscription.payment') {
    recordSubscriptionCycle($payload);   // no orderId; use $payload['eventId']
}

http_response_code(200);
echo 'OK';
```

---

## Retry Policy

If your endpoint fails to respond with HTTP 200-299, GembaPay retries in two phases:

1. **Immediate:** 3 attempts, with a 2s then 4s pause between them.
2. **Extended:** if all 3 immediate attempts fail, the delivery is queued and retried
   **hourly for up to 72 hours**, with a reminder email after 24 hours.

**Timeout per attempt:** 15 seconds.

After the 72-hour window is exhausted the delivery is marked `exhausted`. You can manually retry any delivery from the dashboard (a manual retry does not reopen the 72-hour window).

---

## Best Practices

### 1. Respond Quickly

Return HTTP 200 immediately, then process asynchronously:

```javascript
app.post('/webhooks/gembapay', (req, res) => {
  // Verify signature...
  
  // Respond immediately
  res.status(200).send('OK');
  
  // Process asynchronously
  processWebhookAsync(req.body);
});
```

### 2. Implement Idempotency

Handle duplicate webhooks gracefully:

```javascript
async function processWebhook(payload) {
  const { payment } = payload;
  
  // Check if already processed
  const existing = await db.orders.findUnique({
    where: { orderId: payment.orderId }
  });
  
  if (existing && existing.status === 'fulfilled') {
    return; // Already processed
  }
  
  // Process order...
}
```

### 3. Verify via API

For critical operations, double-check payment status:

> **Note:** For server-side polling, prefer the authenticated `GET /api/merchant/payment-status/:orderId` (with your API key) over the public status endpoint shown below.

```javascript
async function processPayment(orderId) {
  // Double-check via API
  const response = await fetch(
    `https://api.gembapay.com/api/customer/payment/${orderId}/status`
  );
  
  const data = await response.json();
  
  if (data.status !== 'completed' && data.status !== 'confirmed') {
    throw new Error('Payment not confirmed');
  }
  
  // Fulfill order...
}
```

### 4. Log Everything

Keep detailed logs for debugging:

```javascript
app.post('/webhooks/gembapay', (req, res) => {
  console.log('Webhook received:', {
    event: req.body.event,
    orderId: req.body.payment?.orderId,
    network: req.body.payment?.network,
    timestamp: new Date().toISOString()
  });
  
  // Process...
});
```

### 5. Handle All Event Types and Payment Methods

```javascript
function handleWebhook(payload) {
  const { event, payment } = payload;
  
  switch (event) {
    case 'payment.completed':
      // Handle based on payment method
      switch (payment.network) {
        case 'stripe':
          return handleStripePayment(payment);
        case 'paypal':
          return handlePayPalPayment(payment);
        case 'ethereum':
        case 'bsc':
        case 'polygon':
          return handleCryptoPayment(payment);
      }
      break;
    // Subscriptions use their own events + a flat payload (no `payment` wrapper):
    case 'subscription.payment':
      return recordSubscriptionCycle(payload);      // payload.eventId is the idempotency key
    case 'subscription.activated':
    case 'subscription.canceled':
    case 'subscription.payment_failed':
      return updateSubscriptionState(payload);
    case 'webhook.test':
      return; // dashboard connectivity check
    default:
      console.log('Unknown event:', event);
  }
}

function handleCryptoPayment(payment) {
  console.log(`Crypto payment: ${payment.tokenAmount} ${payment.tokenSymbol}`);
  console.log(`TX: ${payment.txHash}`);
  fulfillOrder(payment.orderId);
}

function handleStripePayment(payment) {
  console.log(`Stripe payment: $${payment.usdAmount}`);
  fulfillOrder(payment.orderId);
}

function handlePayPalPayment(payment) {
  console.log(`PayPal payment: $${payment.usdAmount}`);
  fulfillOrder(payment.orderId);
}
```

---

## Testing

### Test Webhook Endpoint

Use the "Test Webhook" feature in the dashboard to send a test payload.

### Local Testing

Use a tunnel service like ngrok for local development:

```bash
ngrok http 3000
# Use the ngrok URL as your webhook endpoint
```

### Test Payload

```json
{
  "event": "payment.completed",
  "payment": {
    "id": "test_pay_123",
    "orderId": "TEST-ORDER",
    "amount": 10.00,
    "usdAmount": 10.00,
    "network": "bsc",
    "paymentProvider": "crypto",
    "txHash": "0xtest...",
    "tokenAmount": "0.015",
    "tokenSymbol": "BNB",
    "status": "confirmed"
  },
  "timestamp": "2026-01-25T12:00:00.000Z"
}
```

---

## Troubleshooting

### Webhook Not Received

1. Check webhook URL is correct in dashboard
2. Verify endpoint is publicly accessible
3. Check server logs for incoming requests
4. Ensure HTTPS certificate is valid

### Signature Verification Failing

1. Verify you're using the correct webhook secret.
2. **Hash the RAW request body bytes** — not a re-parsed/re-serialized copy. `JSON.stringify(req.body)`
   (Node) or `json.dumps(request.json)` (Python) will not reliably reproduce the exact bytes GembaPay
   signed (key order / whitespace differ), and Python's `json.dumps` adds spaces by default. Use
   `express.raw()` / `request.get_data()` / `php://input`.
3. **Compare against the bare hex** — the signature is a plain 64-char HMAC-SHA256 hex string with
   **no `sha256=` prefix**. Do not prepend one.
4. Ensure a webhook secret is actually set for the merchant (without one, deliveries cannot be verified).

### Duplicate Webhooks

1. Implement idempotency checks
2. Use orderId as unique identifier
3. Track processed webhooks in database

---

## Related Documentation

- [API Reference](api-reference.md)
- [Integration Guide](integration.md)
