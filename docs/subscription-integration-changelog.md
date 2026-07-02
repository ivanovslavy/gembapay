# Subscription Integration — change log (for the end-of-work git reconciliation)
Files edited (backups in /home/slavy/gembapay-secfix-backups/20260702/ unless noted).
GembaPay backend = /gembapay.com/backend (NOT git). GembaKitchen = /gembakitchen.com (own git).

## STAGE 1 — GembaPay enabler

### 1a — subscription.payment webhook (Stripe cycle) [DONE, verified: syntax + api healthy]
- FILE: /gembapay.com/backend/src/services/subscription.service.js (root-owned; backup .pre-webhook)
  - added `const webhookService = require('./webhook.service')`
  - added method `_notifyMerchant(sub, eventType, extra)` — signed webhook, no secrets, idempotent by refId
  - `_recordStripeCycle`: emits `subscription.payment` (amount, currency, refId=invoice.id) on each cycle
- restarted gembapay-api; /health 200. (Live webhook delivery to be tested via a Stripe test subscription.)
- TODO next: subscription.activated/canceled/payment_failed in _upsertStripeSub/_markPastDue; PayPal equivalents in
  subscriptionPaypal.service.js; then Stage 1b (API plan mgmt) + 1c (first-charge coupon).

### 1a — Stripe + PayPal lifecycle events [DONE, verified: syntax + api healthy]
- FILE subscription.service.js (root; backups .pre-webhook, .pre-lifecycle): _upsertStripeSub emits
  subscription.activated (transition to active/trialing) / subscription.canceled; _markPastDue emits
  subscription.payment_failed. _recordStripeCycle emits subscription.payment (done earlier).
- FILE subscriptionPaypal.service.js (root; backup .pre-webhook): added webhookService import + _notifyMerchant
  method; _upsert emits activated/canceled/payment_failed on transition; _recordCycle emits subscription.payment.
- Event set (both providers): subscription.{payment, activated, canceled, payment_failed}. Payload: {event,
  subscriptionId, providerSubscriptionId, planId, planToken, customerEmail, status, isTestMode, timestamp, +amount/currency}.
  HMAC-signed via webhookService.sendWebhook; no secrets; idempotent by refId (invoice/sale id).
- restarted gembapay-api; /health 200. STAGE 1a COMPLETE. Next: 1b API plan mgmt, 1c first-charge coupon.

### 1b — API plan management [DONE, verified: migration + syntax + api healthy + 401 no-auth]
- DB: pg_dump pre-externalref; migration 20260702190000_subscription_plan_external_ref adds
  subscription_plans.external_ref + UNIQUE(merchant_id, external_ref) (NULLs allowed). Prisma generated.
- subscription.service.js createPlan (backup .pre-idempotent): accepts body.externalRef → create-or-return-existing
  by (merchantId, externalRef); externalRef stored on the plan.
- subscription.routes.js (root; backup .pre-apikey): combined dashOrApiKey middleware (gembapay_ prefix -> API key,
  else JWT; both tenant-scope req.merchant) on POST / + GET /. Verified 401 without auth.
- STAGE 1b COMPLETE. Next: 1c first-charge coupon.

### 1c — first-charge coupon capability [DONE, verified: syntax + api healthy]
- subscription.service.js subscribeStripe (backup .pre-coupon): signature +opts; if opts.firstChargeDiscount.percent
  (CLAMPED 0-100 server-side), create-or-reuse a Stripe coupon `gk_once_<pct>` (duration:once) on the connected
  account and add `discounts:[{coupon}]` to the checkout session → discounts the FIRST invoice only.
- SECURITY: the PUBLIC subscribe endpoint does NOT pass opts (no client-controlled discount = no free-sub hole);
  the authenticated server-to-server discount path (GembaKitchen validates its voucher) is wired in Stage 2.
- PayPal first-charge discount = follow-up (PayPal has no clean once-coupon; use a discounted first cycle or Stripe-only).
- STAGE 1 (GembaPay enabler) COMPLETE: 1a webhooks + 1b API plan mgmt + 1c coupon. Next: STAGE 2 GembaKitchen.
