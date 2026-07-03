# GembaPay Webhooks

[Back to Documentation](README.md) | [Back to Main README](../README.md)

---

## Overview

GembaPay sends webhook notifications to your server when payment events occur. Webhooks allow you to automate order fulfillment and keep your system synchronized with payment status. All payment methods (Crypto, Stripe, PayPal) use the same webhook format.

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
| `payment.completed` | Payment successfully processed (also fired for each paid subscription cycle) |
| `payment.failed` | Payment failed |
| `payment.expired` | Payment request expired |

> **Subscriptions:** GembaPay does not introduce new outbound event types for subscriptions. Each successful recurring billing cycle fires a standard **`payment.completed`** webhook to your endpoint. See [Subscription Events](#subscription-events).

---

## Payload Format

### Headers

```http
POST /your-webhook-endpoint HTTP/1.1
Host: yoursite.com
Content-Type: application/json
X-GembaPay-Event: payment.completed
X-GembaPay-Signature: sha256=abc123...
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

### payment.failed

```json
{
  "event": "payment.failed",
  "payment": {
    "orderId": "ORDER-12345",
    "status": "failed",
    "failureReason": "insufficient_funds",
    "network": "stripe",
    "paymentProvider": "stripe",
    "failedAt": "2026-01-25T08:15:05.193Z"
  },
  "timestamp": "2026-01-25T08:15:05.193Z"
}
```

### payment.expired

```json
{
  "event": "payment.expired",
  "payment": {
    "orderId": "ORDER-12345",
    "status": "expired",
    "expiredAt": "2026-01-25T08:15:05.193Z"
  },
  "timestamp": "2026-01-25T08:15:05.193Z"
}
```

---

## Subscription Events

Subscriptions are billed recurringly through the **native subscription engines of Stripe and PayPal**. GembaPay receives the provider-side subscription events, reconciles them, and notifies merchants through the **same `payment.completed` webhook** used for one-off payments — you do not need to handle a separate set of subscription event types.

### Provider events GembaPay consumes (internal)

GembaPay listens to and processes the following provider webhooks. Handling is **idempotent** (duplicate deliveries are de-duplicated), so a provider re-sending an event never double-records a cycle or double-fires your webhook.

**Stripe:**

| Stripe event | What it means |
|--------------|---------------|
| `customer.subscription.created` | A new subscription started |
| `customer.subscription.updated` | Plan/quantity/status changed (e.g. upgrade, downgrade scheduled) |
| `customer.subscription.deleted` | Subscription ended/cancelled |
| `invoice.paid` | A billing cycle was paid → recorded + your `payment.completed` fires |
| `invoice.payment_failed` | A cycle charge failed (Stripe retries / dunning) |

**PayPal:**

| PayPal event | What it means |
|--------------|---------------|
| `BILLING.SUBSCRIPTION.ACTIVATED` | A new subscription became active |
| `BILLING.SUBSCRIPTION.UPDATED` | Subscription changed |
| `BILLING.SUBSCRIPTION.CANCELLED` | Subscription cancelled |
| `BILLING.SUBSCRIPTION.SUSPENDED` | Subscription suspended (e.g. failed payments) |
| `PAYMENT.SALE.COMPLETED` | A billing cycle was paid → recorded + your `payment.completed` fires |

> These are inbound provider events that GembaPay handles for you. As a merchant you only need to handle the **`payment.completed`** webhook you receive from GembaPay.

### What your endpoint receives per cycle

Each paid subscription cycle is recorded in your Transactions and delivered to your webhook as a standard `payment.completed` event. The payment method (`stripe` or `paypal`) appears in `network` / `paymentProvider`, and the subscriber's email in `customerAddress`, exactly as for a one-off Stripe/PayPal payment:

```json
{
  "event": "payment.completed",
  "payment": {
    "orderId": "SUB-7c1d8e2f-0001",
    "amount": 19.99,
    "usdAmount": 21.73,
    "currency": "EUR",
    "status": "completed",
    "txHash": "in_1Pabc123...",
    "network": "stripe",
    "paymentProvider": "stripe",
    "customerAddress": "jane@example.com",
    "tokenAmount": 19.99
  },
  "timestamp": "2026-06-29T10:00:05.193Z"
}
```

> The `orderId` for a subscription cycle identifies the subscription and the billing cycle. Treat it as the idempotency key (see [Implement Idempotency](#2-implement-idempotency)) so a re-delivered webhook fulfils the cycle only once.

---

## Signature Verification

Always verify webhook signatures to ensure requests are from GembaPay.

### Node.js

```javascript
const crypto = require('crypto');

function verifyWebhookSignature(payload, signature, secret) {
  const expectedSignature = 'sha256=' + crypto
    .createHmac('sha256', secret)
    .update(JSON.stringify(payload))
    .digest('hex');
  
  return crypto.timingSafeEqual(
    Buffer.from(signature),
    Buffer.from(expectedSignature)
  );
}

// Express.js middleware
app.post('/webhooks/gembapay', express.json(), (req, res) => {
  const signature = req.headers['x-gembapay-signature'];
  
  if (!verifyWebhookSignature(req.body, signature, WEBHOOK_SECRET)) {
    return res.status(401).send('Invalid signature');
  }
  
  // Process webhook
  const { event, payment } = req.body;
  
  if (event === 'payment.completed') {
    console.log('Payment completed via:', payment.network);
    // network: ethereum, bsc, polygon, stripe, or paypal
    fulfillOrder(payment.orderId);
  }
  
  res.status(200).send('OK');
});
```

### Python

```python
import hmac
import hashlib
import json

def verify_webhook_signature(payload, signature, secret):
    expected = 'sha256=' + hmac.new(
        secret.encode(),
        json.dumps(payload).encode(),
        hashlib.sha256
    ).hexdigest()
    
    return hmac.compare_digest(signature, expected)

# Flask example
@app.route('/webhooks/gembapay', methods=['POST'])
def handle_webhook():
    signature = request.headers.get('X-GembaPay-Signature')
    
    if not verify_webhook_signature(request.json, signature, WEBHOOK_SECRET):
        return 'Invalid signature', 401
    
    event = request.json['event']
    payment = request.json['payment']
    
    if event == 'payment.completed':
        print(f"Payment completed via: {payment['network']}")
        fulfill_order(payment['orderId'])
    
    return 'OK', 200
```

### PHP

```php
<?php
function verifyWebhookSignature($payload, $signature, $secret) {
    $expected = 'sha256=' . hash_hmac('sha256', json_encode($payload), $secret);
    return hash_equals($expected, $signature);
}

// Usage
$payload = json_decode(file_get_contents('php://input'), true);
$signature = $_SERVER['HTTP_X_GEMBAPAY_SIGNATURE'];

if (!verifyWebhookSignature($payload, $signature, $webhookSecret)) {
    http_response_code(401);
    exit('Invalid signature');
}

$event = $payload['event'];
$payment = $payload['payment'];

if ($event === 'payment.completed') {
    error_log("Payment completed via: " . $payment['network']);
    fulfillOrder($payment['orderId']);
}

http_response_code(200);
echo 'OK';
```

---

## Retry Policy

If your endpoint fails to respond with HTTP 200-299, GembaPay will retry:

| Attempt | Delay |
|---------|-------|
| 1 | Immediate |
| 2 | 2 seconds |
| 3 | 4 seconds |
| 4 | 8 seconds |

**Total attempts:** 4
**Timeout per attempt:** 15 seconds

After all retries are exhausted, the webhook is marked as failed. You can manually retry failed webhooks from the dashboard.

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
        case 'ethereum':
        case 'bsc':
        case 'polygon':
          return handleCryptoPayment(payment);
        case 'stripe':
          return handleStripePayment(payment);
        case 'paypal':
          return handlePayPalPayment(payment);
      }
      break;
    case 'payment.failed':
      return notifyPaymentFailed(payment);
    case 'payment.expired':
      return handleExpiredPayment(payment);
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

1. Verify you're using the correct webhook secret
2. Check that payload is not modified before verification
3. Ensure JSON encoding is consistent
4. Check for whitespace or encoding issues

### Duplicate Webhooks

1. Implement idempotency checks
2. Use orderId as unique identifier
3. Track processed webhooks in database

---

## Related Documentation

- [API Reference](api-reference.md)
- [Integration Guide](integration.md)
