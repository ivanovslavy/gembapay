# GembaPay API Reference

[Back to Documentation](README.md) | [Back to Main README](../README.md)

---

## Overview

GembaPay API enables merchants to create payment requests and receive card and PayPal payments through a unified interface.

**Base URL:** `https://api.gembapay.com`

---

## Table of Contents

1. [Authentication](#authentication)
2. [Payment Requests](#payment-requests)
3. [Payment Links](#payment-links)
4. [Subscriptions](#subscriptions)
5. [Customer Endpoints](#customer-endpoints)
6. [Merchant Endpoints](#merchant-endpoints)
7. [Stripe Connect](#stripe-connect)
8. [PayPal Integration](#paypal-integration)
9. [Merchant API v1 — Refunds and Payment Links](#merchant-api-v1--refunds-and-payment-links)
10. [Webhooks](#webhooks)
11. [Error Handling](#error-handling)

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

**Whole-unit currencies.** JPY, KRW, VND, CLP, PYG, ISK and UGX have no decimal places: send a whole
amount (`1000`, not `1000.50`) — otherwise the request is refused with `400`. Card payments are not
available in KWD, BHD, OMR, JOD, GHS and VES (the checkout then offers PayPal only, charged in EUR).
| orderId | string | Yes | Unique order identifier from your system — unique within **your** GembaPay account (another merchant may use the same value). Reusing one of your own orderIds answers `409 Order ID already exists` |
| description | string | No | Payment description shown to customer |

**Response:**
```json
{
  "success": true,
  "orderId": "ORDER-12345",
  "paymentUrl": "https://payment.gembapay.com/checkout/3f9c1e7a-2b4d-4c8e-9f10-a2b3c4d5e6f7",
  "amountUsd": "108.70",
  "amountOriginal": 100.00,
  "currencyOriginal": "EUR",
  "exchangeRate": 1.087,
  "allowedMethods": ["stripe", "paypal"],
  "expiresAt": "2026-01-25T12:00:00.000Z"
}
```

**Response Fields:**

| Field | Description |
|-------|-------------|
| orderId | Your order identifier |
| paymentUrl | URL to redirect customer for payment (redirect the customer here as-is; contains an unguessable token, not your orderId) |
| amountUsd | Amount converted to USD |
| amountOriginal | Original amount in requested currency |
| currencyOriginal | Original currency code |
| exchangeRate | Currency to USD exchange rate |
| allowedMethods | Enabled payment methods for this merchant |
| expiresAt | Payment request expiration time |

---

### Get Order Detail (Authenticated)

Full order record for one of **your** orders, including customer email and metadata. Requires your API key
(`Authorization: Bearer <key>`); only returns orders that belong to your merchant account (404 otherwise). Use this
instead of the public `GET /api/customer/payment/:orderId`, which deliberately omits customer email and secrets.

**Endpoint:** `GET /api/merchant/payment/:orderId`

```bash
curl https://api.gembapay.com/api/merchant/payment/ORDER-12345 \
  -H "Authorization: Bearer gembapay_live_..."
```

**Response:**
```json
{
  "success": true,
  "order": {
    "orderId": "ORDER-12345",
    "status": "completed",
    "amountUsd": "108.70",
    "amountOriginal": "100.00",
    "currency": "USD",
    "currencyOriginal": "EUR",
    "description": "Product purchase",
    "customerEmail": "buyer@example.com",
    "metadata": { "viaPaymentLink": true },
    "network": "stripe",
    "paymentMode": "stripe",
    "merchantAddress": "0x...",
    "isTestMode": false,
    "createdAt": "2026-01-25T12:00:00.000Z",
    "completedAt": "2026-01-25T12:05:00.000Z",
    "expiresAt": "2026-01-25T13:00:00.000Z",
    "payment": {
      "reference": "pi_3abc123...",
      "blockNumber": "45123456",
      "status": "confirmed",
      "paymentMethod": "google_pay",
      "confirmedAt": "2026-01-25T12:05:00.000Z"
    }
  }
}
```

`payment.paymentMethod` is how the customer paid: `card`, `apple_pay`, `google_pay`, `link`, or
`null` (PayPal, or a payment recorded before 2026-10-21). Same values as in the webhook.

**Status-only variant:** `GET /api/merchant/payment-status/:orderId` (same auth + ownership scoping) returns
`{ success, orderId, status, amountUsd, network, completedAt, isTestMode }`.

---

## Payment Links

Payment Links let a merchant accept a payment without integrating the API — a shareable hosted page with a QR code, created and managed from the Merchant Dashboard (Dashboard → Payment Links). A link can be **single-use** (closes after one payment) or **multi-use** (reusable, e.g. for donations, with optional usage and total-amount limits). The merchant configures the amount (a fixed amount, or an **open amount** where the payer chooses how much to pay — "pay what you want", ideal for donations), currency, accepted methods, expiry, which customer fields to collect (or none), Test/Live mode, and email notifications.

Each link is hosted at:

```
https://payment.gembapay.com/link/<token>
```

The endpoints below are public (no authentication) and are consumed by the hosted payment page. Links are created and managed in the dashboard, or from your own systems with an API key via [`/api/v1/payment-links`](#payment-links-api).

### Resolve a Payment Link

**Endpoint:** `GET /api/payment-links/public/:token`

**Authentication:** None

**Response:**
```json
{
  "token": "a1b2c3d4...",
  "merchantName": "Example Store",
  "amount": 25.00,
  "openAmount": false,
  "currency": "EUR",
  "description": "Logo design",
  "methods": ["stripe", "paypal"],
  "customerFields": {
    "name":  { "enabled": true,  "required": true },
    "email": { "enabled": true,  "required": true },
    "phone": { "enabled": false, "required": false },
    "note":  { "enabled": true,  "required": false }
  },
  "expiresAt": "2026-07-01T12:00:00.000Z",
  "isTestMode": false,
  "mode": "live",
  "available": true,
  "unavailableReason": null,
  "lockedUntil": null
}
```

When `available` is `false`, `unavailableReason` explains why: `disabled`, `expired`, `used`, `max_uses`, `max_total`, or `locked`.

`locked` is specific to **single-use** links: while one payer is checking out, the link is reserved for a few minutes so it cannot be paid twice. The response then also includes a `lockedUntil` timestamp (ISO 8601) — the link becomes available again automatically after it passes (or sooner, if that payment completes). Multi-use links are never locked.

When the link has an **open amount** (the payer chooses), `openAmount` is `true` and `amount` is `null`.

### Check Out a Payment Link

Creates a payment request from the link and returns where to send the customer. Only the customer fields the merchant enabled are accepted; for a donation-style link that collects nothing, send an empty body. Required fields that are missing are rejected with `400`.

For an **open-amount** link (`openAmount: true`), include a positive `amount` in the body — this is the amount the payer chose to pay. For a fixed-amount link, any `amount` sent is ignored.

For a **single-use** link, this call reserves the link for the payer; a concurrent checkout while it is reserved returns `409` with `link_unavailable:locked`. The reservation expires automatically, so the link reopens if the payer does not complete.

**Endpoint:** `POST /api/payment-links/public/:token/checkout`

**Authentication:** None

**Request Body:**
```json
{
  "name": "Jane Doe",
  "email": "jane@example.com",
  "note": "Invoice #42"
}
```

**Open-amount link** — the payer's chosen amount is required (a donation link that collects no fields needs only this):
```json
{
  "amount": 20.00
}
```

**Response:**
```json
{
  "orderId": "LNK-7f3a91c2d4e8",
  "checkoutPath": "/checkout/LNK-7f3a91c2d4e8",
  "allowedMethods": ["stripe", "paypal"]
}
```

The customer is then taken to `https://payment.gembapay.com{checkoutPath}` and completes payment through the standard checkout flow (see [Customer Endpoints](#customer-endpoints)). Payment status and webhooks work exactly as for any other payment request.

---

## Subscriptions

Subscriptions let a merchant bill customers automatically on a recurring schedule. A merchant creates **plans** (price, billing interval, accepted methods); each plan exposes a hosted subscribe link and an embeddable button. Recurring charges are executed by the **native subscription engines of Stripe and PayPal**.

Plan management endpoints require **merchant authentication** — an **API key** (for programmatic provisioning, recommended) or a dashboard JWT. The subscribe and manage flows used by the hosted pages are **public** (no authentication). Each successful billing cycle is recorded in the merchant's transactions and triggers the merchant's `subscription.payment` webhook — subscriptions use dedicated `subscription.*` events with a flat payload (no `orderId`), not `payment.completed` (see [Webhooks](webhooks.md#subscription-events)).

### Create a Plan

**Endpoint:** `POST /api/subscriptions`

**Authentication:** Merchant API key (or dashboard JWT)

**Request Body:**
```json
{
  "name": "Pro",
  "amount": 19.99,
  "currency": "EUR",
  "interval": "month",
  "intervalCount": 1,
  "description": "Pro plan — monthly",
  "allowedMethods": ["stripe", "paypal"],
  "trialDays": 14
}
```

**Parameters:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| name | string | Yes | Plan name shown to customers (e.g. Basic, Pro, Ultimate) |
| amount | number | Yes | Price per billing cycle |
| currency | string | No | ISO 4217 currency code (base currency EUR; default EUR) |
| interval | string | Yes | Billing interval: `week`, `month`, or `year` |
| intervalCount | number | No | Number of intervals per cycle (default 1) |
| description | string | No | Plan description shown on the subscribe page |
| allowedMethods | string[] | Yes | Accepted methods: any of `stripe`, `paypal` |
| trialDays | number | No | Free trial length in days |
| externalRef | string | No | Your own plan ID; creating a plan again with the same `externalRef` returns the same plan (idempotent sync) |
| isTestMode | boolean | No | **Dashboard only.** With an API key the key decides: a `gembapay_test_` key creates test plans, a `gembapay_live_` key live plans |

**Test and live plans are separate.** An API key only lists, subscribes, re-prices and cancels plans of its
own mode — a plan of the other mode answers `404`. An `externalRef` already used by a plan of the other mode
answers `409`. Whole-unit currencies (JPY, KRW, …) need a whole `amount`.

**Response:**
```json
{
  "success": true,
  "plan": {
    "id": "sub_plan_8f2a...",
    "name": "Pro",
    "amount": 19.99,
    "currency": "EUR",
    "interval": "month",
    "intervalCount": 1,
    "allowedMethods": ["stripe", "paypal"],
    "trialDays": 14,
    "status": "active",
    "token": "p_a1b2c3d4...",
    "subscribeUrl": "https://payment.gembapay.com/subscribe/p_a1b2c3d4...",
    "createdAt": "2026-06-29T10:00:00.000Z"
  }
}
```

### List Plans

**Endpoint:** `GET /api/subscriptions`

**Authentication:** Merchant API key (or dashboard JWT)

**Response:**
```json
{
  "success": true,
  "plans": [
    { "id": "sub_plan_8f2a...", "name": "Basic", "amount": 9.99, "currency": "EUR", "interval": "month", "status": "active" },
    { "id": "sub_plan_9g3b...", "name": "Pro", "amount": 19.99, "currency": "EUR", "interval": "month", "status": "active" }
  ]
}
```

### Get a Plan

**Endpoint:** `GET /api/subscriptions/:id`

**Authentication:** Merchant API key (or dashboard JWT)

**Response:** the plan object (same shape as in [Create a Plan](#create-a-plan)).

### Update a Plan

**Endpoint:** `PATCH /api/subscriptions/:id`

**Authentication:** Merchant API key (or dashboard JWT)

Update a plan's status (for example, to pause new sign-ups or archive it).

**Request Body:**
```json
{
  "status": "paused"
}
```

| Field | Type | Description |
|-------|------|-------------|
| status | string | `active`, `paused`, or `archived` |

**Response:**
```json
{
  "success": true,
  "plan": { "id": "sub_plan_8f2a...", "status": "paused" }
}
```

### List Subscribers

**Endpoint:** `GET /api/subscriptions/subscribers`

**Authentication:** Merchant API key (or dashboard JWT)

**Response:**
```json
{
  "success": true,
  "subscribers": [
    {
      "id": "sub_7c1d...",
      "planId": "sub_plan_8f2a...",
      "planName": "Pro",
      "email": "jane@example.com",
      "provider": "stripe",
      "status": "active",
      "currentPeriodEnd": "2026-07-29T10:00:00.000Z",
      "createdAt": "2026-06-29T10:00:00.000Z"
    }
  ]
}
```

### Subscription Metrics

**Endpoint:** `GET /api/subscriptions/metrics`

**Authentication:** Merchant API key (or dashboard JWT)

**Response:**
```json
{
  "success": true,
  "metrics": {
    "mrr": 1240.50,
    "currency": "EUR",
    "activeSubscribers": 86,
    "churnRate": 0.031
  }
}
```

| Field | Description |
|-------|-------------|
| mrr | Monthly recurring revenue |
| activeSubscribers | Count of currently active subscriptions |
| churnRate | Cancellation rate over the trailing period |

### Cancel a Subscriber (Merchant-Initiated)

**Endpoint:** `POST /api/subscriptions/subscribers/:id/cancel`

**Authentication:** Merchant API key (or dashboard JWT)

Cancels a subscriber's subscription at period end (the provider stops billing at the next cycle). The customer can also cancel themselves via the public manage flow below.

**Response:**
```json
{
  "success": true,
  "subscriber": {
    "id": "sub_7c1d...",
    "status": "cancel_at_period_end",
    "currentPeriodEnd": "2026-07-29T10:00:00.000Z"
  }
}
```

### Public: Resolve a Plan

Used by the hosted subscribe page to render plan details. No authentication.

**Endpoint:** `GET /api/subscriptions/public/plan/:token`

**Response:**
```json
{
  "token": "p_a1b2c3d4...",
  "merchantName": "Example Store",
  "name": "Pro",
  "amount": 19.99,
  "currency": "EUR",
  "interval": "month",
  "intervalCount": 1,
  "description": "Pro plan — monthly",
  "methods": ["stripe", "paypal"],
  "trialDays": 14,
  "available": true
}
```

### Merchant: Subscribe (with optional first-charge discount)

Server-to-server subscribe for one of your **own** plans — for platforms that provision subscriptions in code (e.g. after validating a customer's promo code). Enforces plan ownership.

**Endpoint:** `POST /api/subscriptions/plan/:token/subscribe`

**Authentication:** Merchant API key (or dashboard JWT)

**Request Body:**
```json
{
  "email": "jane@example.com",
  "method": "stripe",
  "firstChargeDiscount": { "percent": 20 }
}
```

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| email | string | Yes | Customer's email (billing + cancellation flow) |
| method | string | No | `stripe` (default) or `paypal` |
| firstChargeDiscount | object | No | `{ "percent": 1-100 }` — discount applied to the **first cycle only**. **Stripe only**: passing it with `method: "paypal"` returns `400`. |

**Response:** same as [Public: Subscribe](#public-subscribe) — a `url` to redirect the customer to.

> **First-charge discounts are Stripe-only (platform-wide).** They use Stripe's one-time coupon (`duration: once`), applied per subscriber to the first invoice. PayPal has no equivalent — its only way to discount a first cycle is a plan-level `TRIAL` billing cycle baked into the plan, which cannot be applied per subscriber. So if a merchant wants to offer a first-charge discount, the customer must subscribe via **Stripe**; a `paypal` subscribe carrying a discount is rejected with `400` (never silently charged full price).

### Merchant: Re-price a plan

Change a subscription plan's recurring price. Stripe Prices are immutable, so this creates a **new** Stripe Price on the plan's product, points the plan at it, and **migrates every active subscription** to the new price with **no proration** — the new price takes effect at each subscriber's next renewal. Enforces plan ownership. (PayPal subscriptions are not re-priced.)

**Endpoint:** `POST /api/subscriptions/plan/:token/reprice`

**Authentication:** Merchant API key (or dashboard JWT)

**Request Body:**
```json
{ "amount": 34.99 }
```

**Response:**
```json
{ "token": "...", "amount": 34.99, "stripePriceId": "price_...", "migrated": 12, "failed": 0, "total": 12 }
```

`migrated`/`failed`/`total` report the subscription migration. If the plan has no Stripe product yet (no subscribers), it returns `400` — set the price on the plan and it applies when the first customer subscribes.

### Public: Subscribe

Starts a subscription for a customer and returns where to redirect them to authorize and pay the first cycle (Stripe Checkout or PayPal approval). No authentication.

**Endpoint:** `POST /api/subscriptions/public/plan/:token/subscribe`

**Request Body:**
```json
{
  "email": "jane@example.com",
  "method": "stripe"
}
```

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| email | string | Yes | Customer's email (used for billing and for the cancellation flow) |
| method | string | Yes | `stripe` or `paypal` (must be one of the plan's allowed methods) |

**Response:**
```json
{
  "url": "https://checkout.stripe.com/c/pay/cs_..."
}
```

Redirect the customer to `url`. After they authorize the recurring payment, the subscription becomes active and the provider charges each cycle automatically.

### Public: Manage / Cancel

Self-service cancellation for customers — no account or password. The customer verifies ownership of their email with a 6-digit code, then cancels. The flow is **merchant-scoped**: `:merchantToken` ties the request to one merchant, so the same email only ever reveals the subscriptions held with that merchant. No authentication.

**Step 1 — Request a code**

**Endpoint:** `POST /api/subscriptions/public/manage/:merchantToken/code`

**Request Body:**
```json
{ "email": "jane@example.com" }
```

**Response:**
```json
{ "success": true, "message": "A 6-digit code has been sent to your email." }
```

**Step 2 — Verify the code and list subscriptions**

**Endpoint:** `POST /api/subscriptions/public/manage/:merchantToken/verify`

**Request Body:**
```json
{ "email": "jane@example.com", "code": "482913" }
```

**Response:**
```json
{
  "success": true,
  "subscriptions": [
    {
      "subscriptionId": "sub_7c1d...",
      "planName": "Pro",
      "amount": 19.99,
      "currency": "EUR",
      "interval": "month",
      "status": "active",
      "currentPeriodEnd": "2026-07-29T10:00:00.000Z"
    }
  ]
}
```

**Step 3 — Cancel**

**Endpoint:** `POST /api/subscriptions/public/manage/:merchantToken/cancel`

**Request Body:**
```json
{
  "email": "jane@example.com",
  "code": "482913",
  "subscriptionId": "sub_7c1d..."
}
```

**Response:**
```json
{
  "success": true,
  "subscription": {
    "subscriptionId": "sub_7c1d...",
    "status": "cancel_at_period_end",
    "currentPeriodEnd": "2026-07-29T10:00:00.000Z"
  }
}
```

Cancellation is **cancel-at-period-end**: the subscription stays active until `currentPeriodEnd`, then stops with no further charges. No refund is issued for the current period.

---

## Customer Endpoints

These endpoints are used by the payment page to process customer payments. No authentication required.

> **Security note (2026-07-03):** the public customer endpoints return **only non-sensitive checkout data**. They do
> **not** return the customer's email, merchant secrets, or internal IDs. If your integration needs the full order
> record (including `customerEmail`), use the **authenticated** merchant endpoint
> [`GET /api/merchant/payment/:orderId`](#get-order-detail-authenticated) with your API key — it returns the full
> order, scoped to orders that belong to your account.

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
      "stripe": true,
      "stripe": true,
      "paypal": true
    }
  }
}
```

### Get Payment Status

**Endpoint:** `GET /api/customer/payment/:orderId/status`

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
| confirmed | Payment confirmed by the provider |
| completed | Payment fully completed |
| failed | Payment failed |
| expired | Payment request expired |

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

> **Auth note:** unlike the other merchant endpoints, this one currently requires a **dashboard
> (JWT) session**, not an API key — an API-key request returns `401`. Use `GET /api/merchant/stats`
> and `GET /api/merchant/payment-status/:orderId` (both API-key) for programmatic access, or view
> transactions in the dashboard.

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
      "stripe": { "revenue": 8500.00, "count": 150 },
      "stripe": { "revenue": 4200.50, "count": 120 },
      "paypal": { "revenue": 2720.00, "count": 72 }
    },
    "byNetwork": {
      "card": { "revenue": 3000.00, "count": 50 },
      "paypal": { "revenue": 3500.00, "count": 60 },
      "paypal": { "revenue": 2000.00, "count": 40 }
    }
  }
}
```

### Webhook settings (dashboard session)

These endpoints back the dashboard pages **Settings → Webhook Settings** and **Webhooks**. They use
the dashboard login (JWT), not an API key.

| Method & path | What it does |
|---------------|--------------|
| `PUT /api/merchant/webhook` | `{ "webhookUrl": "https://…", "webhookSecret"?: "whsec_…" }` — the URL must be public `https://` on port 443 (no IP, no localhost/private network); a secret (32–128 chars) is generated when none is set. `webhookUrl: null` switches webhooks off. |
| `GET /api/merchant/webhook/settings` | URL, whether a secret is set, rotation state, and every event with `enabled`, `defaultOn`, `locked`. |
| `PUT /api/merchant/webhook/events` | `{ "events": ["payment.completed", "payment.refunded", …] }` — which events to receive. `payment.completed` is always kept on. |
| `POST /api/merchant/webhook/rotate-secret` | New secret (returned once). The previous one keeps signing for 24 h (a second `v1=` in `X-GembaPay-Signature-V2`). |
| `POST /api/merchant/webhook/test` | `{ "event": "payment.refunded" }` — sends a sample with `"test": true` to your URL; returns the HTTP status your endpoint answered. |

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

## Merchant API v1 — Refunds and Payment Links

Base path `https://api.gembapay.com/api/v1`, authenticated with your **API key**
(`Authorization: Bearer gembapay_live_…` or `gembapay_test_…`).

### Rules for every v1 call

- **Mode comes from the key.** A test key sees and changes only test-mode objects, a live key only
  live ones; an object of the other mode answers `404`.
- **Permissions (scopes).** Reading is allowed for every key. Writing needs a permission on the key,
  set in Dashboard → **API Keys** (when creating a key, or later with **Permissions**):

  | Permission | Allows |
  |------------|--------|
  | `refunds:write` | `POST /api/v1/refunds` |
  | `payment_links:write` | `POST`, `PATCH`, `DELETE /api/v1/payment-links` |

  Keys created before October 2026 have no permissions until you add them. A missing permission
  answers `403` with `"code": "missing_scope"`.
- **`Idempotency-Key` is required on every `POST`** (8–255 characters: letters, digits, `_ - : .`;
  use a new UUID per operation). Repeating a request with the same key returns the **first response**
  (header `Idempotent-Replayed: true`) — so a timeout can be retried safely without refunding twice.
  The same key with a different body answers `422`; while the first request is still running, `409`.
  Keys are kept for 24 hours.
- **Rate limit:** 60 requests per minute per API key (`429` with `"code": "rate_limited"`), in
  addition to the general per-IP limit.
- **Errors** are `{ "error": "<human message>", "code": "<machine code>" }`.

### Refunds API

#### Create a refund

`POST /api/v1/refunds` — permission `refunds:write`, header `Idempotency-Key`.

```bash
curl -X POST https://api.gembapay.com/api/v1/refunds \
  -H "Authorization: Bearer gembapay_live_..." \
  -H "Idempotency-Key: 3f0c7c2e-6a51-4c0e-9f4b-2b1f2f7d9a10" \
  -H "Content-Type: application/json" \
  -d '{ "orderId": "ORDER-12345", "amount": "5.00", "reason": "One item returned" }'
```

| Field | Required | Meaning |
|-------|----------|---------|
| `orderId` | yes | Your order id of a **completed** card (Stripe) or PayPal payment. |
| `amount` | no | Amount in the **payment currency**, as a string or number with at most 2 decimals (none for zero-decimal currencies such as JPY). **Omit it to refund everything still refundable.** |
| `reason` | no | Up to 500 characters; shown to the customer where the provider supports it. |

Partial refunds are allowed until the payment is fully refunded. The amount is checked against what
is still refundable at the moment of the request, so two parallel requests can never refund more than
was paid.

Response `201`:

```json
{
  "success": true,
  "refund": {
    "id": "8b4313fb-32d2-4e06-b957-f5007c91f709",
    "orderId": "ORDER-12345",
    "amount": 5,
    "currency": "EUR",
    "status": "succeeded",
    "reason": "One item returned",
    "source": "api",
    "provider": "stripe",
    "providerRefundId": "re_3UStkODUd5fD8ypl1zZx4kEM",
    "failureReason": null,
    "testMode": false,
    "createdAt": "2026-10-21T07:15:52.104Z"
  }
}
```

`status` is `succeeded`, or `pending` for methods that settle later. A `payment.refunded` webhook is
sent for every refund (when you have that event switched on).

| Status / `code` | When |
|-----------------|------|
| `400 order_id_required`, `invalid_amount`, `invalid_reason` | Bad input. |
| `400 amount_too_large` | More than the refundable remainder (the message says how much is left). |
| `400 unsupported_provider` | Not a card or PayPal payment. |
| `403 missing_scope` | The key lacks `refunds:write`. |
| `404 payment_not_found` | No such order for this merchant **in this key's mode**. |
| `409 already_refunded`, `not_refundable` | Fully refunded already, or the payment is not completed. |
| `422 idempotency_key_reused` | The `Idempotency-Key` was used with a different body. |
| `502 provider_error` | Stripe / PayPal refused the refund (the message has their reason). Nothing was refunded; you may retry with a **new** `Idempotency-Key`. |

#### List refunds

`GET /api/v1/refunds?orderId=ORDER-12345&limit=50` → `{ "success": true, "refunds": [ … ] }` — newest
first, max 100, only this key's mode. `orderId` is optional.

#### Get a refund

`GET /api/v1/refunds/:id` → `{ "success": true, "refund": { … } }`.

### Payment Links API

The same links as in Dashboard → Payment Links, created from your own systems (CRM, invoicing, chat).
A link is paid at `https://payment.gembapay.com/link/<token>`.

#### Create a link

`POST /api/v1/payment-links` — permission `payment_links:write`, header `Idempotency-Key`. The mode is
the key's mode (a `mode` in the body is ignored).

```json
{
  "amount": 49.90,
  "currency": "EUR",
  "description": "Workshop ticket",
  "methods": ["stripe", "paypal"],
  "multiUse": true,
  "maxUses": 100,
  "maxTotalAmount": null,
  "expiresAt": "2026-12-31T23:59:59Z",
  "customerFields": {
    "name":  { "enabled": true,  "required": true },
    "email": { "enabled": true,  "required": true },
    "phone": { "enabled": false, "required": false },
    "note":  { "enabled": true,  "required": false }
  },
  "merchantNotify": true
}
```

| Field | Meaning |
|-------|---------|
| `amount` | Fixed amount, or omit / `null` to let the payer choose ("pay what you want"). |
| `currency` | ISO code, default your account currency. |
| `methods` | Subset of `stripe` (card, Apple Pay, Google Pay, Link) and `paypal` that is enabled on your account; default all of them. |
| `multiUse`, `maxUses`, `maxTotalAmount` | Single-use (default) or reusable with optional limits. |
| `expiresAt` | Optional, must be in the future. |
| `customerFields` | Which customer details to collect — for each of `name`, `email`, `phone`, `note`: `{ "enabled", "required" }`. Omitted: name + email required, note optional, phone off. |
| `merchantNotify` | Email you on every payment. |

Response `201` — `{ "success": true, "paymentLink": { "id", "token", "amount", "currency", "description",
"methods", "multiUse", "maxUses", "maxTotalAmount", "expiresAt", "isTestMode", "mode", "status",
"rawStatus", "usedCount", "totalCollected", "createdAt", … } }`.

#### List, get, disable, delete

| Call | Permission | Result |
|------|------------|--------|
| `GET /api/v1/payment-links` | — | `{ "paymentLinks": [ … ] }` (this key's mode only) |
| `GET /api/v1/payment-links/:id` | — | The link plus its completed payments (`uses`). |
| `PATCH /api/v1/payment-links/:id` `{ "status": "disabled" \| "active" }` | `payment_links:write` | The updated link. |
| `DELETE /api/v1/payment-links/:id` | `payment_links:write` | `{ "ok": true }` — payment history is kept. |

---

## Webhooks

GembaPay sends signed webhooks for payment, refund, dispute and subscription events. **Which events
you receive is your choice** (Dashboard → Webhooks). Full guide with every payload, field and the
right way to handle each event: **[webhooks.md](webhooks.md)**.

### Headers

```
Content-Type: application/json
X-GembaPay-Event: payment.completed
X-GembaPay-Event-Id: evt_585aff3b-…          (your idempotency key, also in the body as eventId)
X-GembaPay-Merchant-Id: your-merchant-id
X-GembaPay-Signature-V2: t=<unix>,v1=<hex>  (verify this one)
X-GembaPay-Signature: <hex>                 (legacy, no timestamp)
X-GembaPay-Timestamp: 2026-10-21T07:45:06.000Z
```

### Signature V2

`v1 = HMAC-SHA256(secret, "<t>.<raw body>")`, hex. Reject when `t` is more than 300 s from your
clock; compare in constant time; accept any `v1` (two during a secret rotation). Node, Python and PHP
code: [webhooks.md → Verifying the signature](webhooks.md#verifying-the-signature).

### Event types

| Event | Default | Description |
|-------|---------|-------------|
| `payment.completed` | always on | A one-off payment succeeded. |
| `payment.refunded` | off | A full or partial refund (API, dashboard or provider dashboard). |
| `payment.failed` | off | A payment attempt was declined; not final. |
| `payment.expired` | off | The payment request expired unpaid; final. |
| `dispute.created` | off | The customer opened a dispute / chargeback. |
| `dispute.closed` | off | The dispute was decided (`won` / `lost`). |
| `subscription.activated` | on | A subscription became active. |
| `subscription.payment` | on | A recurring cycle was paid. |
| `subscription.payment_failed` | on | A recurring cycle failed. |
| `subscription.canceled` | on | A subscription ended. |

### `payment.completed` payload

```json
{
  "event": "payment.completed",
  "eventId": "evt_585aff3b-b4a3-4334-bd79-bc3bc2034367",
  "testMode": false,
  "timestamp": "2026-10-21T07:45:06.425Z",
  "payment": {
    "orderId": "ORDER-12345",
    "amount": 15,
    "currency": "EUR",
    "amountOriginal": 15,
    "currencyOriginal": "EUR",
    "usdAmount": 16.84,
    "exchangeRate": 0.891,
    "status": "completed",
    "txHash": "pi_3USuCoDUd5fD8ypl3EG1nRti",
    "network": "stripe",
    "paymentProvider": "stripe",
    "paymentMethod": "google_pay",
    "customerAddress": "stripe-customer",
    "timestamp": "2026-10-21T07:45:06.425Z"
  }
}
```

`paymentMethod`: `card` | `apple_pay` | `google_pay` | `link` | `null` (PayPal / before 2026-10-21).
`txHash` is the provider's payment id (Stripe `pi_…` / PayPal capture id).

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
| Payment method not enabled | Stripe/PayPal not configured |

---

## Rate Limits

| Endpoint Type | Limit |
|---------------|-------|
| Payment creation | 100/minute |
| Payment status | 300/minute |
| Merchant endpoints | 60/minute |
| Merchant API v1 (`/api/v1/*`) | 60/minute **per API key** |

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
