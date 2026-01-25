# GembaPay API Reference

[Back to Documentation](README.md) | [Back to Main README](../README.md)

---

## Overview

GembaPay API enables merchants to create payment requests and receive cryptocurrency and fiat payments through a unified interface.

**Base URL:** `https://api.gembapay.com`

---

## Table of Contents

1. [Authentication](#authentication)
2. [Payment Requests](#payment-requests)
3. [Customer Endpoints](#customer-endpoints)
4. [Merchant Endpoints](#merchant-endpoints)
5. [Stripe Connect](#stripe-connect)
6. [PayPal Integration](#paypal-integration)
7. [Webhooks](#webhooks)
8. [Error Handling](#error-handling)

---

## Authentication

### API Key Authentication

All merchant API endpoints require an API key in the Authorization header.

**Header Format:**
```
Authorization: Bearer YOUR_API_KEY
```

**API Key Format:**
```
Production: gembapay_live_[64 hex characters]
Test:       gembapay_test_[64 hex characters]
```

**Example Request:**
```bash
curl -X POST https://api.gembapay.com/api/merchant/payment-request \
  -H "Authorization: Bearer gembapay_live_abc123..." \
  -H "Content-Type: application/json" \
  -d '{"amount": 100, "currency": "EUR", "orderId": "ORDER-123"}'
```

### JWT Authentication

Dashboard and Stripe/PayPal Connect endpoints use JWT tokens obtained through login.

**Header Format:**
```
Authorization: Bearer JWT_TOKEN
```

**Token Expiration:** 30 days

---

## Payment Requests

### Create Payment Request

Creates a new payment request for a customer.

**Endpoint:** `POST /api/merchant/payment-request`

**Authentication:** API Key (Bearer token)

**Request Body:**
```json
{
  "amount": 100.00,
  "currency": "EUR",
  "orderId": "ORDER-12345",
  "description": "Product purchase"
}
```

**Parameters:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| amount | number | Yes | Payment amount in specified currency |
| currency | string | No | ISO 4217 currency code (default: USD) |
| orderId | string | Yes | Unique order identifier from your system |
| description | string | No | Payment description shown to customer |

**Response:**
```json
{
  "success": true,
  "orderId": "ORDER-12345",
  "paymentUrl": "https://payment.gembapay.com/checkout/ORDER-12345",
  "amountUsd": "108.70",
  "amountOriginal": 100.00,
  "currencyOriginal": "EUR",
  "exchangeRate": 1.087,
  "allowedMethods": ["crypto", "stripe", "paypal"],
  "expiresAt": "2026-01-25T12:00:00.000Z"
}
```

**Response Fields:**

| Field | Description |
|-------|-------------|
| orderId | Your order identifier |
| paymentUrl | URL to redirect customer for payment |
| amountUsd | Amount converted to USD |
| amountOriginal | Original amount in requested currency |
| currencyOriginal | Original currency code |
| exchangeRate | Currency to USD exchange rate |
| allowedMethods | Enabled payment methods for this merchant |
| expiresAt | Payment request expiration time |

---

## Customer Endpoints

These endpoints are used by the payment page to process customer payments. No authentication required.

### Get Payment Details

**Endpoint:** `GET /api/customer/payment/:orderId`

**Response:**
```json
{
  "success": true,
  "payment": {
    "orderId": "ORDER-12345",
    "amount": 100.00,
    "currency": "EUR",
    "amountUsd": 108.70,
    "description": "Product purchase",
    "merchantName": "Example Store",
    "status": "pending",
    "paymentMethods": {
      "crypto": true,
      "stripe": true,
      "paypal": true
    }
  }
}
```

### Get Payment Status

**Endpoint:** `GET /api/customer/payment/:orderId/status`

**Response (Crypto):**
```json
{
  "orderId": "ORDER-12345",
  "status": "confirmed",
  "amountUsd": "108.70",
  "network": "bsc",
  "paymentProvider": "crypto",
  "payment": {
    "txHash": "0x84579c019dc334474a9421072b633bdb...",
    "tokenSymbol": "BNB",
    "tokenAmount": "0.157892",
    "confirmedAt": "2026-01-25T08:15:05.193Z",
    "explorerUrl": "https://bscscan.com/tx/0x..."
  }
}
```

**Response (Stripe/PayPal):**
```json
{
  "orderId": "ORDER-12345",
  "status": "completed",
  "amountUsd": "108.70",
  "network": "stripe",
  "paymentProvider": "stripe",
  "payment": {
    "providerPaymentId": "pi_3abc123...",
    "tokenAmount": "108.70",
    "confirmedAt": "2026-01-25T08:15:05.193Z"
  }
}
```

**Status Values:**

| Status | Description |
|--------|-------------|
| pending | Awaiting payment |
| processing | Payment in progress |
| confirmed | Crypto payment confirmed on blockchain |
| completed | Payment fully completed |
| failed | Payment failed |
| expired | Payment request expired |

### Lock Quote (Crypto Native Tokens)

**Endpoint:** `POST /api/customer/payment/:orderId/lock`

**Request Body:**
```json
{
  "network": "bsc",
  "tokenAddress": "0x0000000000000000000000000000000000000000",
  "customerAddress": "0xc45112B334822811f4418e2f13C2C80FF790C949"
}
```

**Response:**
```json
{
  "success": true,
  "quote": {
    "quoteId": "0x...",
    "tokenAmount": "0.157892",
    "expiresAt": "2026-01-25T08:20:00.000Z"
  }
}
```

### Get Supported Currencies

**Endpoint:** `GET /api/customer/currencies`

**Response:**
```json
{
  "success": true,
  "currencies": [
    { "code": "USD", "symbol": "$", "name": "US Dollar" },
    { "code": "EUR", "symbol": "€", "name": "Euro" },
    ...
  ]
}
```

### Convert Currency

**Endpoint:** `GET /api/customer/convert?amount=100&from=EUR&to=USD`

**Response:**
```json
{
  "success": true,
  "from": "EUR",
  "to": "USD",
  "amount": 100,
  "result": 108.70,
  "rate": 1.087
}
```

---

## Merchant Endpoints

These endpoints require API Key authentication.

### List Transactions

**Endpoint:** `GET /api/merchant/transactions`

**Query Parameters:**

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| page | number | 1 | Page number |
| limit | number | 20 | Items per page (max 100) |
| status | string | all | Filter by status |
| network | string | all | Filter by network |
| from | string | - | Start date (ISO 8601) |
| to | string | - | End date (ISO 8601) |

**Response:**
```json
{
  "success": true,
  "transactions": [...],
  "pagination": {
    "page": 1,
    "limit": 20,
    "total": 150,
    "pages": 8
  }
}
```

### Get Statistics

**Endpoint:** `GET /api/merchant/stats`

**Query Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| period | string | day, week, month, year, all |

**Response:**
```json
{
  "success": true,
  "stats": {
    "totalRevenue": 15420.50,
    "totalTransactions": 342,
    "byProvider": {
      "crypto": { "revenue": 8500.00, "count": 150 },
      "stripe": { "revenue": 4200.50, "count": 120 },
      "paypal": { "revenue": 2720.00, "count": 72 }
    },
    "byNetwork": {
      "ethereum": { "revenue": 3000.00, "count": 50 },
      "bsc": { "revenue": 3500.00, "count": 60 },
      "polygon": { "revenue": 2000.00, "count": 40 }
    }
  }
}
```

### Configure Webhook

**Endpoint:** `PUT /api/merchant/webhook`

**Request Body:**
```json
{
  "webhookUrl": "https://yoursite.com/webhooks/gembapay"
}
```

---

## Stripe Connect

### Create Connect Account

**Endpoint:** `POST /api/stripe/connect/create`

**Authentication:** JWT Token

**Response:**
```json
{
  "success": true,
  "accountId": "acct_..."
}
```

### Get Onboarding Link

**Endpoint:** `POST /api/stripe/connect/onboarding`

**Authentication:** JWT Token

**Response:**
```json
{
  "success": true,
  "url": "https://connect.stripe.com/setup/..."
}
```

### Check Connection Status

**Endpoint:** `GET /api/stripe/connect/status`

**Authentication:** JWT Token

**Response:**
```json
{
  "success": true,
  "connected": true,
  "onboardingCompleted": true,
  "accountId": "acct_..."
}
```

### Create Checkout Session

**Endpoint:** `POST /api/stripe/create-session`

**Request Body:**
```json
{
  "orderId": "ORDER-12345"
}
```

**Response:**
```json
{
  "success": true,
  "sessionId": "cs_...",
  "url": "https://checkout.stripe.com/c/pay/..."
}
```

---

## PayPal Integration

### Start Onboarding

**Endpoint:** `POST /api/paypal/onboard`

**Authentication:** JWT Token

**Response:**
```json
{
  "success": true,
  "onboardingUrl": "https://www.paypal.com/bizsignup/partner/entry?...",
  "referralId": "..."
}
```

### Check Merchant Status

**Endpoint:** `GET /api/paypal/merchant-status/:merchantId`

**Response:**
```json
{
  "success": true,
  "connected": true,
  "merchantId": "..."
}
```

### Create PayPal Order

**Endpoint:** `POST /api/paypal/create-order`

**Request Body:**
```json
{
  "orderId": "ORDER-12345"
}
```

**Response:**
```json
{
  "success": true,
  "paypalOrderId": "...",
  "approveUrl": "https://www.paypal.com/checkoutnow?token=...",
  "status": "CREATED"
}
```

### Capture Payment

**Endpoint:** `POST /api/paypal/capture/:orderId`

**Request Body:**
```json
{
  "paypalOrderId": "..."
}
```

**Response:**
```json
{
  "success": true,
  "status": "completed",
  "captureId": "..."
}
```

---

## Webhooks

GembaPay sends webhook notifications for payment events.

### Webhook Headers

```
Content-Type: application/json
X-GembaPay-Event: payment.completed
X-GembaPay-Signature: sha256=...
X-GembaPay-Merchant-Id: your-merchant-id
X-GembaPay-Timestamp: 2026-01-25T08:15:05.193Z
```

### Webhook Payload (Crypto)

```json
{
  "event": "payment.completed",
  "payment": {
    "id": "uuid",
    "orderId": "ORDER-12345",
    "txHash": "0x84579c019dc334474a9421072b633bdb...",
    "network": "bsc",
    "usdAmount": 108.70,
    "customerAddress": "0xc45112B334822811f4418e2f13C2C80FF790C949",
    "tokenAmount": "0.157892",
    "status": "confirmed"
  },
  "timestamp": "2026-01-25T08:15:05.193Z"
}
```

### Webhook Payload (Stripe/PayPal)

```json
{
  "event": "payment.completed",
  "payment": {
    "orderId": "ORDER-12345",
    "amount": 108.70,
    "usdAmount": 108.70,
    "currency": "USD",
    "status": "completed",
    "txHash": "pi_3abc123...",
    "network": "stripe",
    "paymentProvider": "stripe",
    "customerAddress": "customer@example.com",
    "tokenAmount": 108.70
  },
  "timestamp": "2026-01-25T08:15:05.193Z"
}
```

### Signature Verification

```javascript
const crypto = require('crypto');

function verifyWebhook(payload, signature, secret) {
  const expectedSignature = 'sha256=' + crypto
    .createHmac('sha256', secret)
    .update(JSON.stringify(payload))
    .digest('hex');
  
  return crypto.timingSafeEqual(
    Buffer.from(signature),
    Buffer.from(expectedSignature)
  );
}
```

### Event Types

| Event | Description |
|-------|-------------|
| payment.completed | Payment successfully processed |
| payment.failed | Payment failed |
| payment.expired | Payment request expired |

See [Webhooks Documentation](webhooks.md) for detailed information.

---

## Error Handling

### Error Response Format

```json
{
  "success": false,
  "error": "Error message description"
}
```

### HTTP Status Codes

| Code | Description |
|------|-------------|
| 200 | Success |
| 400 | Bad Request - Invalid parameters |
| 401 | Unauthorized - Invalid or missing API key |
| 403 | Forbidden - Insufficient permissions |
| 404 | Not Found - Resource does not exist |
| 409 | Conflict - Duplicate order or payment in progress |
| 410 | Gone - Quote expired |
| 429 | Too Many Requests - Rate limit exceeded |
| 500 | Internal Server Error |

### Common Errors

| Error | Description |
|-------|-------------|
| Invalid API key | API key is invalid or revoked |
| Order not found | Payment request not found |
| Payment already completed | Order has already been paid |
| Quote expired | Crypto quote has expired, create new one |
| Payment method not enabled | Stripe/PayPal not configured |

---

## Rate Limits

| Endpoint Type | Limit |
|---------------|-------|
| Payment creation | 100/minute |
| Payment status | 300/minute |
| Merchant endpoints | 60/minute |

Rate limit headers are included in responses:
```
X-RateLimit-Limit: 100
X-RateLimit-Remaining: 95
X-RateLimit-Reset: 1642680000
```

---

## Related Documentation

- [Integration Guide](integration.md)
- [Webhooks](webhooks.md)
- [Smart Contracts](smart-contracts.md)
