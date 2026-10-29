# GembaPay Webhooks

[Back to Documentation](README.md) | [Back to Main README](../README.md)

---

## Overview

GembaPay sends a webhook (an HTTPS `POST` with a JSON body) to your server when something happens to
a payment: it was paid, refunded, declined, it expired, or the customer disputed it. **You choose which
events you receive** in the Merchant Dashboard → **Webhooks**. Card, Apple Pay, Google Pay, Link and
PayPal payments all use the same format.

Every webhook is **signed**. Verify the signature before you trust the content — see
[Verifying the signature](#verifying-the-signature).

---

## Table of Contents

1. [Setup](#setup)
2. [Choosing events](#choosing-events)
3. [Delivery, headers and retries](#delivery-headers-and-retries)
4. [Verifying the signature](#verifying-the-signature)
5. [Common envelope](#common-envelope)
6. [Events](#events)
   - [payment.completed](#paymentcompleted)
   - [payment.refunded](#paymentrefunded)
   - [payment.failed](#paymentfailed)
   - [payment.expired](#paymentexpired)
   - [dispute.created](#disputecreated)
   - [dispute.closed](#disputeclosed)
   - [subscription.*](#subscription-events)
7. [Handling webhooks correctly](#handling-webhooks-correctly)
8. [Testing](#testing)
9. [Troubleshooting](#troubleshooting)

---

## Setup

1. Dashboard → **Settings** → **Webhook Settings**: enter your endpoint URL and press **Generate**
   for a secret (or keep the one shown), then save.
2. Dashboard → **Webhooks**: switch on the events your integration handles, press **Save events**.
3. Press **Send test** next to an event to see a sample arrive at your endpoint.

**Endpoint requirements**

- `https://` on the standard port 443, a public domain name (not an IP address, not `localhost`,
  not a private/internal network). URLs that do not meet this are refused when saved, and every
  delivery re-checks the address it connects to.
- Answers within **15 seconds** with an HTTP **2xx** status. Anything else counts as a failure.
- Redirects are **not** followed — point the URL at the final address.

---

## Choosing events

| Event | Group | Default | What it means |
|-------|-------|---------|---------------|
| `payment.completed` | Payments | **always on** | A payment succeeded. Fulfil the order. |
| `payment.refunded` | Payments | off | Money was returned — fully or partly — from the API, the dashboard or your Stripe/PayPal account. One webhook per refund. |
| `payment.failed` | Payments | off | A payment **attempt** was declined. Not final: the customer may try again. |
| `payment.expired` | Payments | off | The payment request expired unpaid. Final. |
| `dispute.created` | Disputes | off | The customer disputed a payment (chargeback). |
| `dispute.closed` | Disputes | off | A dispute was decided: `won` or `lost`. |
| `subscription.activated` | Subscriptions | on | A subscription became active. |
| `subscription.payment` | Subscriptions | on | A recurring cycle was paid. |
| `subscription.payment_failed` | Subscriptions | on | A recurring cycle failed (the provider retries). |
| `subscription.canceled` | Subscriptions | on | A subscription was canceled. |
| `subscription.plan_changed` | Subscriptions | on | A subscriber moved to another plan (it has taken effect). |
| `subscription.plan_change_scheduled` | Subscriptions | on | A cheaper plan is waiting for the renewal (`scheduled: true`), or a waiting change was called off (`scheduled: false`). |

The events that existed before October 2026 are on by default, so integrations that predate this
page see no change. New events stay off until you switch them on. `payment.completed` cannot be
switched off.

---

## Delivery, headers and retries

```http
POST /your-webhook-endpoint HTTP/1.1
Content-Type: application/json
User-Agent: GembaPay-Webhook/2.0
X-GembaPay-Event: payment.refunded
X-GembaPay-Event-Id: evt_refund_8b4313fb-32d2-4e06-b957-f5007c91f709
X-GembaPay-Merchant-Id: <your merchant id>
X-GembaPay-Signature-V2: t=1761040000,v1=5f2b7c…(64 hex)
X-GembaPay-Signature: 9f8b2c1a…(64 hex)          ← legacy, see below
X-GembaPay-Timestamp: 2026-10-21T07:15:42.000Z
```

**Retries.** If your endpoint does not answer 2xx: 3 immediate attempts (2 s and 4 s apart), then
**hourly for 72 hours**. You get an email when deliveries start failing, a reminder after 24 hours,
an email when they recover, and a final one if we give up. Every attempt is listed in the dashboard
(**Webhooks → Delivery log**). After the 72 hours, ask support to re-send a delivery.

**Order is not guaranteed.** Events are sent when they happen, and a retried event can arrive after a
later one. Decide by the state you store, not by arrival order (examples in
[Handling webhooks correctly](#handling-webhooks-correctly)).

**At-least-once.** The same event can arrive more than once (a retry after a timeout on your side).
Every event carries an **`eventId`** — process each `eventId` once.

---

## Verifying the signature

### Signature V2 (use this)

```
X-GembaPay-Signature-V2: t=<unix seconds>,v1=<hex>[,v1=<hex>]
v1 = HMAC-SHA256(webhook secret, "<t>.<raw request body>")   — lower-case hex
```

To verify:

1. Take the **raw request body bytes** exactly as received — not a re-serialised `JSON.stringify` copy.
2. Read `t` and every `v1` from the header.
3. **Reject if `t` is more than 300 seconds away from your clock.** The timestamp is signed, so a
   copied webhook cannot be replayed later.
4. Compute `HMAC-SHA256(secret, t + "." + rawBody)` and compare it with **any** `v1` using a
   constant-time comparison.

There can be **two `v1` values** for 24 hours after you rotate the secret (Dashboard → Webhooks →
**Rotate secret**): one signed with the new secret, one with the previous one. Accept the request if
either matches, then update the secret on your server within those 24 hours.

#### Node.js (Express)

```javascript
const crypto = require('crypto');
const TOLERANCE_SEC = 300;

function verifyGembaPay(rawBody, header, secret) {
  if (!header) return false;
  const parts = header.split(',').map((p) => p.trim());
  const t = Number((parts.find((p) => p.startsWith('t=')) || '').slice(2));
  const sigs = parts.filter((p) => p.startsWith('v1=')).map((p) => p.slice(3));
  if (!t || !sigs.length || Math.abs(Date.now() / 1000 - t) > TOLERANCE_SEC) return false;
  const expected = crypto.createHmac('sha256', secret).update(`${t}.${rawBody}`).digest('hex');
  return sigs.some((s) => s.length === expected.length &&
    crypto.timingSafeEqual(Buffer.from(s), Buffer.from(expected)));
}

// express.raw keeps the exact bytes GembaPay signed
app.post('/webhooks/gembapay', express.raw({ type: 'application/json' }), (req, res) => {
  if (!verifyGembaPay(req.body.toString('utf8'), req.get('X-GembaPay-Signature-V2'), process.env.GEMBAPAY_WEBHOOK_SECRET)) {
    return res.status(401).send('Invalid signature');
  }
  const event = JSON.parse(req.body.toString('utf8'));
  res.status(200).send('OK');          // answer first …
  handleEvent(event).catch(console.error); // … then process (see "Handling webhooks correctly")
});
```

The npm SDK does all of this for you: `gembapay.webhookHandler(fn)` / `gembapay.verifyWebhook(...)`
(see the [SDK README](../packages/npm/README.md)).

#### Python (Flask)

```python
import hmac, hashlib, time, json

def verify_gembapay(raw: bytes, header: str, secret: str, tolerance: int = 300) -> bool:
    if not header:
        return False
    parts = [p.strip() for p in header.split(',')]
    t = next((p[2:] for p in parts if p.startswith('t=')), '')
    sigs = [p[3:] for p in parts if p.startswith('v1=')]
    if not t.isdigit() or not sigs or abs(time.time() - int(t)) > tolerance:
        return False
    expected = hmac.new(secret.encode(), f'{t}.'.encode() + raw, hashlib.sha256).hexdigest()
    return any(hmac.compare_digest(s, expected) for s in sigs)

@app.route('/webhooks/gembapay', methods=['POST'])
def gembapay_webhook():
    raw = request.get_data()  # raw bytes, not request.json
    if not verify_gembapay(raw, request.headers.get('X-GembaPay-Signature-V2'), WEBHOOK_SECRET):
        return 'Invalid signature', 401
    handle_event(json.loads(raw))
    return 'OK', 200
```

#### PHP

```php
<?php
function verifyGembaPay(string $raw, ?string $header, string $secret, int $tolerance = 300): bool {
    if (!$header) return false;
    $t = 0; $sigs = [];
    foreach (array_map('trim', explode(',', $header)) as $part) {
        if (strpos($part, 't=') === 0)  $t = (int) substr($part, 2);
        if (strpos($part, 'v1=') === 0) $sigs[] = substr($part, 3);
    }
    if (!$t || !$sigs || abs(time() - $t) > $tolerance) return false;
    $expected = hash_hmac('sha256', $t . '.' . $raw, $secret);
    foreach ($sigs as $s) { if (hash_equals($expected, $s)) return true; }
    return false;
}

$raw = file_get_contents('php://input');
if (!verifyGembaPay($raw, $_SERVER['HTTP_X_GEMBAPAY_SIGNATURE_V2'] ?? null, $webhookSecret)) {
    http_response_code(401); exit('Invalid signature');
}
$event = json_decode($raw, true);
// … handle $event, then:
http_response_code(200); echo 'OK';
```

### Legacy signature (still sent)

`X-GembaPay-Signature` = `HMAC-SHA256(secret, raw body)` as bare hex (no `sha256=` prefix). It does
**not** cover a timestamp, so it cannot stop a replay. It keeps being sent so existing integrations do
not break; new integrations should verify V2. If you still use it, compare in constant time and
switch to V2 when you can.

---

## Common envelope

Every event has these top-level fields:

| Field | Type | Meaning |
|-------|------|---------|
| `event` | string | The event type, e.g. `payment.refunded`. Same as the `X-GembaPay-Event` header. |
| `eventId` | string | Unique per event; **your idempotency key**. Same as `X-GembaPay-Event-Id`. Re-deliveries of the same event keep the same `eventId`. |
| `testMode` | boolean | `true` for test-mode payments (test API key, test cards, PayPal sandbox). |
| `timestamp` | string (ISO 8601) | When GembaPay created the event. |
| `test` | boolean | Only on events sent with the dashboard **Send test** button. Their order ids start with `TEST-WEBHOOK-`. Never act on them. |

**`paymentMethod`** (in `payment`) — how the customer paid:

| Value | Meaning |
|-------|---------|
| `card` | A card typed into the checkout page |
| `apple_pay` | A card paid through Apple Pay |
| `google_pay` | A card paid through Google Pay |
| `link` | Link by Stripe |
| `null` | PayPal, or a payment recorded before 2026-10-21 |

Stripe may add values over time — treat an unknown value like `card`.

---

## Events

### payment.completed

**When:** a one-off payment succeeded (Stripe card / Apple Pay / Google Pay / Link, or PayPal).
Always on.

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

| Field | Meaning |
|-------|---------|
| `payment.orderId` | Your order id (from the payment request or payment link). |
| `payment.amountOriginal` / `currencyOriginal` | **What you asked for** — check these against your order. |
| `payment.usdAmount`, `exchangeRate` | The same amount in USD and the rate used (reporting). |
| `payment.txHash` | The provider's payment id: Stripe PaymentIntent `pi_…` or PayPal capture id. (Name kept for compatibility.) |
| `payment.paymentProvider` | `stripe` or `paypal`. |
| `payment.paymentMethod` | See the table above. |

**Handle:** mark the order paid and fulfil it — once per `orderId`. Check `amountOriginal` +
`currencyOriginal` against the order before you ship.

### payment.refunded

**When:** a refund was created — through the API (`POST /api/v1/refunds`), the dashboard, or directly
in your Stripe / PayPal account. **One webhook per refund**, also for partial refunds.

```json
{
  "event": "payment.refunded",
  "eventId": "evt_refund_dc1c54c1-9af6-440f-9524-adab0c4713f9",
  "testMode": false,
  "timestamp": "2026-10-21T07:40:41.536Z",
  "refund": {
    "id": "dc1c54c1-9af6-440f-9524-adab0c4713f9",
    "orderId": "ORDER-12345",
    "amount": 3,
    "currency": "EUR",
    "status": "succeeded",
    "reason": null,
    "source": "provider",
    "provider": "stripe",
    "providerRefundId": "re_3USu8UDUd5fD8ypl1a4wQ3ej",
    "failureReason": null,
    "testMode": false,
    "createdAt": "2026-10-21T07:40:41.519Z"
  },
  "payment": {
    "orderId": "ORDER-12345",
    "amount": 12,
    "currency": "EUR",
    "refundedTotal": 3,
    "remaining": 9,
    "fullyRefunded": false,
    "paymentProvider": "stripe",
    "paymentMethod": "card",
    "reference": "pi_3USu8UDUd5fD8ypl1ZzHMnKW"
  }
}
```

| Field | Meaning |
|-------|---------|
| `refund.id` | GembaPay refund id (also returned by the refunds API). |
| `refund.amount` / `currency` | This refund, in the payment currency. |
| `refund.status` | `succeeded`, or `pending` for methods that settle later. |
| `refund.source` | `api`, `dashboard`, or `provider` (made in your Stripe/PayPal account). |
| `payment.refundedTotal`, `remaining`, `fullyRefunded` | Totals **after** this refund. |

**Handle:** record the refund once per `refund.id`; restock / cancel the order when
`payment.fullyRefunded` is `true`, record a partial refund otherwise. Refunds made outside GembaPay
(Stripe Dashboard, PayPal) arrive here too, with `source: "provider"`.

### payment.failed

**When:** a payment attempt was declined — e.g. insufficient funds, a blocked card, PayPal denied the
capture.

```json
{
  "event": "payment.failed",
  "eventId": "evt_failed_evt_3UStkODUd5fD8ypl1ATNT3mG",
  "testMode": false,
  "timestamp": "2026-10-21T07:15:42.347Z",
  "payment": {
    "orderId": "ORDER-12345",
    "amount": 20,
    "currency": "EUR",
    "paymentProvider": "stripe",
    "paymentMethod": "card",
    "failureCode": "generic_decline",
    "failureMessage": "Your card was declined.",
    "final": false,
    "expiresAt": "2026-10-22T07:15:32.204Z"
  }
}
```

| Field | Meaning |
|-------|---------|
| `payment.failureCode` | Provider code, e.g. Stripe `insufficient_funds`, `card_declined`, `generic_decline`, `expired_card`; PayPal `DENIED`. |
| `payment.failureMessage` | Human-readable reason (may be shown to the customer). |
| `payment.final` | Always `false`: the customer can try again until `expiresAt`. |

**Handle:** **do not cancel the order.** Note the attempt, maybe tell the customer. A
`payment.completed` for the same `orderId` can still follow — it wins. To know when the order is
really lost, use `payment.expired`.

### payment.expired

**When:** the payment request passed `expiresAt` without being paid. Sent once, about **35–40
minutes after `expiresAt`** (a checkout that was already open is allowed to finish first).

```json
{
  "event": "payment.expired",
  "eventId": "evt_expired_12fc3e2a-e4a7-4d78-9c8a-fbb4752727f3",
  "testMode": false,
  "timestamp": "2026-10-21T07:29:54.684Z",
  "payment": {
    "orderId": "ORDER-12345",
    "amount": 9,
    "currency": "EUR",
    "status": "expired",
    "expiresAt": "2026-10-21T06:49:54.420Z"
  }
}
```

**Handle:** cancel the order and release reserved stock — **unless you already marked it paid**
(always check your stored state; ignore `payment.expired` for a paid order).

### dispute.created

**When:** the customer disputed a payment with their bank or PayPal (chargeback / PayPal dispute).

```json
{
  "event": "dispute.created",
  "eventId": "evt_dispute_e7d5dd76-68f6-45ca-859e-5780f3f7ee06_created",
  "testMode": false,
  "timestamp": "2026-10-21T07:45:07.269Z",
  "dispute": {
    "id": "e7d5dd76-68f6-45ca-859e-5780f3f7ee06",
    "provider": "stripe",
    "providerDisputeId": "du_1USuCqDUd5fD8yplcN5YV581",
    "orderId": "ORDER-12345",
    "amount": 15,
    "currency": "EUR",
    "reason": "fraudulent",
    "status": "needs_response",
    "outcome": null,
    "createdAt": "2026-10-21T07:45:07.256Z",
    "closedAt": null
  },
  "payment": {
    "orderId": "ORDER-12345",
    "amount": 15,
    "currency": "EUR",
    "paymentProvider": "stripe",
    "paymentMethod": "card",
    "reference": "pi_3USuCoDUd5fD8ypl3EG1nRti"
  }
}
```

| Field | Meaning |
|-------|---------|
| `dispute.reason` | Provider reason, e.g. Stripe `fraudulent`, `product_not_received`, `duplicate`; PayPal `MERCHANDISE_OR_SERVICE_NOT_RECEIVED`, … |
| `dispute.status` | Provider status, e.g. Stripe `needs_response`, `under_review`; PayPal `OPEN`, `WAITING_FOR_SELLER_RESPONSE`. |

**Handle:** stop or hold the order if it is not shipped yet, collect proof (delivery, communication),
and **respond in your Stripe Dashboard / PayPal Resolution Center before their deadline** — GembaPay
does not answer disputes for you. The disputed amount is usually withdrawn from your balance while
the dispute is open.

### dispute.closed

**When:** the dispute was decided. Same payload as `dispute.created`, with `outcome` and `closedAt`
set:

```json
{
  "event": "dispute.closed",
  "eventId": "evt_dispute_e7d5dd76-68f6-45ca-859e-5780f3f7ee06_closed",
  "dispute": { "id": "e7d5dd76-…", "status": "won", "outcome": "won", "closedAt": "2026-10-21T07:45:16.261Z", "…": "…" },
  "payment": { "orderId": "ORDER-12345", "…": "…" }
}
```

| `dispute.outcome` | Meaning |
|-------------------|---------|
| `won` | Decided for you — the money stays with you (returned to your balance). |
| `lost` | Decided for the customer — the money goes back to them. Treat like a full refund. |
| `other` | Closed another way (e.g. the customer withdrew it, warning closed). |

### Subscription events

Card subscriptions are billed by Stripe Billing, PayPal subscriptions by GembaPay's own engine. Subscription events have
their **own flat payload** — no `payment` wrapper and **no `orderId`**:

```json
{
  "event": "subscription.payment",
  "eventId": "in_1Pabc123…",
  "subscriptionId": "sub_local_7c1d8e2f",
  "providerSubscriptionId": "sub_1Pabc123…",
  "planId": "plan_1a2b3c",
  "planToken": "pln_9x8y7z",
  "customerEmail": "jane@example.com",
  "status": "active",
  "amount": 19.99,
  "currency": "EUR",
  "paymentMethod": "card",
  "isTestMode": false,
  "timestamp": "2026-06-29T10:00:05.193Z"
}
```

| Event | When | Handle |
|-------|------|--------|
| `subscription.activated` | The subscription became active | Grant access. |
| `subscription.payment` | A cycle was paid (`amount`, `currency`, `paymentMethod`, **`reason`**) | Extend access by one period, once per `eventId` — **unless `reason` is `plan_change`**, which is the prorated difference of an upgrade: money, but not a period. Treat `reason: 'cycle'` (or absent, for deliveries sent before 2026-10-29) as a renewal. |
| `subscription.payment_failed` | A cycle charge failed and will be retried (Stripe's retries; PayPal after 1, 3 and 5 days) | Warn the customer; keep access during the retry window. |
| `subscription.canceled` | The subscription ended | Remove access at the end of the paid period. |
| `subscription.plan_changed` | The subscriber is on another plan from now (`fromPlanToken`, `toPlanToken`, `fromAmount`, `toAmount`, `prorated`, `effectiveAt`) | Change what they may use **at this moment**. A dearer plan arrives here only after the prorated difference was actually paid. |
| `subscription.plan_change_scheduled` | A cheaper plan is parked for the renewal (`scheduled: true`, `effectiveAt`), or that was called off (`scheduled: false`) | Do **not** change their rights yet — they paid for the period they are in. Wait for `subscription.plan_changed`. |

🔴 **`reason` on `subscription.payment`** is `plan_change` for the prorated difference charged when a
subscriber moves to a dearer plan (both rails), and `cycle` for a renewal. A receiver that cannot tell
them apart extends the customer's paid-through date by a whole period and bills them a full cycle for a
part-period difference — so if you grant access or issue documents on this event, branch on it.

`paymentMethod` on `subscription.payment` is `card` / `apple_pay` / `google_pay` / `link`, or `null`
for PayPal. For PayPal, `eventId` is PayPal's capture id and `providerSubscriptionId` the id of the
saved PayPal. Subscription events use `isTestMode` (not `testMode`).

---

## Handling webhooks correctly

1. **Verify V2** (signature + timestamp) — reject with 401 otherwise.
2. **Answer 2xx fast** (within 15 s). Do slow work (emails, ERP calls) after answering or in a queue.
3. **Deduplicate on `eventId`.** Store processed ids; skip an id you have seen.
4. **Decide by state, not by arrival order.** Examples:
   - `payment.completed` after `payment.failed` → paid (the retry worked).
   - `payment.expired` for an order you already marked paid → ignore.
   - `payment.refunded` with `fullyRefunded: true` → refunded, even if a later retry of an earlier
     partial refund arrives.
5. **Check the amount.** On `payment.completed`, compare `amountOriginal` / `currencyOriginal` with
   your order.
6. **Ignore `"test": true`** events in production logic.
7. **When in doubt, ask the API**: `GET /api/merchant/payment/:orderId` (API key) returns the order,
   its payment and `payment.paymentMethod`; `GET /api/v1/refunds?orderId=…` lists its refunds.

```javascript
async function handleEvent(evt) {
  if (evt.test) return;                                   // dashboard "Send test"
  if (await db.processedEvents.has(evt.eventId)) return;  // at-least-once delivery
  switch (evt.event) {
    case 'payment.completed':  await orders.markPaid(evt.payment.orderId, evt.payment); break;
    case 'payment.failed':     await orders.noteFailedAttempt(evt.payment.orderId, evt.payment.failureCode); break;
    case 'payment.expired':    await orders.cancelIfUnpaid(evt.payment.orderId); break;
    case 'payment.refunded':   await orders.recordRefund(evt.refund, evt.payment.fullyRefunded); break;
    case 'dispute.created':    await orders.holdForDispute(evt.dispute); break;
    case 'dispute.closed':     await orders.closeDispute(evt.dispute.orderId, evt.dispute.outcome); break;
    case 'subscription.payment': await subs.extend(evt.subscriptionId, evt); break;
    default: break;                                       // unknown/new events: ignore, answer 200
  }
  await db.processedEvents.add(evt.eventId);
}
```

**Unknown events:** answer **200** and ignore them. New event types are only sent after you switch
them on, but a robust receiver never fails on an event it does not know.

---

## Testing

- **Send test** (Dashboard → Webhooks): sends a realistic sample of any event to your URL, with
  `"test": true` and an order id `TEST-WEBHOOK-…`. Test sends are not retried and not stored in the
  delivery log.
- **Real test payments:** create a payment request with a **test API key** (`gembapay_test_…`) and pay
  with Stripe test cards — `4242 4242 4242 4242` (success), `4000 0000 0000 0002` (decline →
  `payment.failed`), `4000 0000 0000 0259` (success, then `dispute.created`) — or a PayPal sandbox
  buyer. Refund it with `POST /api/v1/refunds` to receive `payment.refunded`. These events have
  `testMode: true`.
- **Local development:** expose your machine with a tunnel (e.g. `ngrok http 3000`) and use the
  public `https://` URL.

---

## Troubleshooting

**Webhook not received**
1. Is the event switched on (Dashboard → Webhooks)? `payment.completed` always is.
2. Look at the Delivery log — it shows every attempt with the HTTP status or the error.
3. Is the URL public `https://` on port 443 and does it answer within 15 s? Redirects are not followed.

**Signature fails**
1. Use the **raw body** (`express.raw()`, `request.get_data()`, `php://input`) — not a re-serialised copy.
2. Use the current webhook secret. Within 24 h after a rotation both secrets are valid (two `v1=`).
3. Check your server clock (NTP) — V2 rejects timestamps more than 5 minutes off.
4. The value is bare hex: no `sha256=` prefix.

**Duplicates** — expected with at-least-once delivery: deduplicate on `eventId`.

---

## Related Documentation

- [API Reference](api-reference.md) — refunds and payment links API (`/api/v1`)
- [Integration Guide](integration.md)
- [npm SDK](../packages/npm/README.md)
- [WordPress / WooCommerce plugin](wordpress-plugin.md)
