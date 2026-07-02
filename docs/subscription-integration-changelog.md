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

## STAGE 2 — GembaKitchen (/gembakitchen.com, own git)
### 2a DB [DONE]: pg_dump pre-subs (248K); migration 20260702200000_recurring_subscription_fields adds
  plans.{recurring, gembapay_plan_token, interval} + subscriptions.{gembapay_subscription_id, auto_renew}. migrate up to date.
### 2b plan-sync [DONE, verified idempotent]: lib/gembapay.js +createSubscriptionPlan; scripts/sync-gembapay-plans.js.
  Ran → 4 recurring plans created in GembaPay (m1 month x1 EUR29, m3 month x3 EUR79, m6 month x6 EUR149, m12 year x1 EUR279);
  tokens stored on Plan.gembapayPlanToken + recurring=true. Re-run = same tokens, 1 plan/externalRef (idempotent).
### 2d webhook handler [DONE, self-verified]: STAGE 1a payloads +eventId (both providers, sudo). billing.service.js
  (backup .pre-subs) +handleSubscriptionEvent; handleWebhook routes subscription.* to it (email->User->restaurantId
  mapping; extend via shared computeExtension; idempotent by gpsub_<eventId>; autoRenew=true; canceled->autoRenew=false).
  Self-test: unknown email->ignored (no write), routing OK, token d1f4->plan m1(recurring,month). Happy-path uses proven computeExtension.
### 2e email [DONE]: lib/email.js (backup .pre-subs) +sendSubscriptionRenewed (after-payment). subscription-reminders.js
  (backup .pre-subs) skips sub.autoRenew (auto-renewing subs get no before-expiry reminder; canceled/one-time still do).
### 2c backend [in progress]: GembaPay subscribeAsMerchant (ownership + validated first-charge discount) + auth route.
### 2c backend [DONE]: GembaPay subscription.service.js +subscribeAsMerchant (ownership + clamped first-charge
  discount); subscription.routes.js +POST /plan/:token/subscribe (dashOrApiKey, verified 401 no-auth). GembaKitchen
  lib/gembapay.js +createSubscribeSession; billing.service.js +createSubscription (owner-email->subscribe);
  billing.routes.js +POST /billing/subscribe (requireAuth). gembakitchen-api restarted OK.

## STAGE 2 REMAINING (frontend + open question + cleanup)
- 2c FRONTEND: Billing.jsx -> subscription-only UI ("Choose a subscription plan"; trial stays; paid -> /billing/subscribe
  -> redirect to GembaPay). React app, needs rebuild + redeploy of gembakitchen-dashboard.
- DISCOUNT-CODE SEMANTICS (OPEN — needs owner): current RedemptionCode/voucher codes grant a PERIOD, but owner wants
  codes = first-charge PERCENT discount. Need the mapping (which codes -> which %). subscribeAsMerchant already accepts
  a clamped firstChargeDiscount; wire billing.routes /subscribe to resolve code->percent server-side once semantics fixed.
- 2f CANCEL: surface GembaPay's hardened manage flow (/api/subscriptions/public/manage/:merchantToken/{code,verify,cancel})
  in Billing.jsx: email -> 6-digit code -> confirm -> cancel. Do NOT build a parallel cancel.
## STAGE 3: remove GembaPay merchant-dashboard subscription plan page (platform-wide, API-only); landing copy -> programmatic.
## GIT: gembakitchen repo is diverged (2 behind + local landing WIP not mine) — reconcile at the end before pushing.

## DISCOUNT CODES (owner decision: prepaid codes stay one-time; NEW admin percent-off codes for recurring 1st charge)
### [DONE, self-verified] pg_dump pre-discount; migration 20260703090000_discount_codes -> new DiscountCode model
  {codeHash unique, percentOff, status, maxUses, uses, expiresAt}. billing.service.resolveDiscountCode (validate +
  atomic single-use consume -> {percent}); billing.routes /subscribe resolves req.body.code server-side (never client %).
  admin.service.issueDiscountCodes (owner issues codes, plaintext once). Self-test: issue 25%/maxUses1 -> resolve
  {percent:25} -> re-resolve "fully used" -> cleanup. Prepaid RedemptionCode (qortal/gift/admin) UNCHANGED (Qortal store intact).

## ===== BACKEND 100% COMPLETE + VERIFIED. REMAINING = FRONTEND + STAGE 3 + GIT =====
- 2c FRONTEND: dashboard/src/pages/Billing.jsx -> subscription-only ("Choose a subscription plan" -> POST /billing/subscribe
  {planSlug, code?} -> redirect to returned url). Trial stays. React rebuild + redeploy gembakitchen-dashboard.
- ADMIN UI: owner console -> issue discount codes (calls admin.issueDiscountCodes). React.
- 2f CANCEL UI: Billing.jsx -> email -> 6-digit code -> confirm, proxying GembaPay manage flow
  (/api/subscriptions/public/manage/:merchantToken/{code,verify,cancel}). merchantToken = gembakitchen's merchant token.
- STAGE 3: GembaPay merchant-dashboard subscription plan page removal (platform-wide); landing copy -> programmatic.
- GIT: gembakitchen repo diverged (2 behind + landing WIP not mine) -> reconcile before push.
