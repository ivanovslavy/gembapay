# B1 — orderId → access-token migration: TO-DO (who does what)

[Back to Documentation](README.md) · Companion: `security-remediation-report.md` (B1), `remediation-checklist.md`

**Why:** the public checkout endpoints were keyed by a **guessable `orderId`** (merchants can even supply
sequential ones like `ORDER-1`), so order metadata (amount/description/status) — and, on the euro endpoint, the
customer email — could be enumerated by guessing IDs. Fix: a **random, unguessable `accessToken`** becomes the public
key; the guessable `orderId` stays only as the merchant-side (authenticated) key.

---

## ✅ DONE — Phase 1 (live + E2E-verified 2026-07-03, the `/api/customer/*` + `/checkout/` flow)

- **DB:** `payment_requests.access_token` added — `NOT NULL`, `UNIQUE`, DB default `gen_random_uuid()`; all 587
  existing rows backfilled with distinct tokens (Prisma migration `20260703110000_add_payment_request_access_token`,
  DB backed up first). Every new payment request gets a token automatically.
- **New checkout URLs carry the token, not the orderId:** `POST /api/merchant/payment-request` `paymentUrl`,
  payment-link `checkoutPath`, and the Stripe/PayPal `success_url` + `cancel_url` now use `accessToken`.
- **Public lookup resolves the token:** `customer.routes.js` has a `router.param('orderId')` resolver that accepts
  **either** the access token **or** the legacy orderId and maps it to the canonical orderId, so every
  `/api/customer/payment/:orderId*` endpoint works with the new token URLs. Legacy orderId still resolves during the
  transition (nothing breaks yet).
- **Authenticated merchant endpoints are unchanged** — they still key on `orderId` (safe: scoped to the owning
  merchant, not publicly enumerable). Verified: create → paymentUrl is a UUID token → public lookup by token = 200,
  no PII/secret leak → legacy orderId still 200 → authed endpoint 200.

## ✅ DONE — Phase 2 (euro path, live + E2E-verified 2026-07-03)

- `GET /api/euro/payment/:orderId` no longer returns `customerEmail` and its merchant include is narrowed to
  `companyName/legalName` (merchantName no longer falls back to the merchant's login email). Its `/pay/${orderId}`
  URL now carries the `accessToken`, and euro.routes got the same `router.param('orderId')` token/orderId resolver
  (covers `/onchain-status` too). Verified: euro-by-orderId = 200 with no `customerEmail` key and no secret values;
  euro-by-token = 200 resolving to the right order. Rich euro order data (incl. customer email) is available via the
  authenticated `GET /api/merchant/payment/:orderId`.

## 🔜 TO DO

### GembaPay (owner) — remaining platform work
1. **SDK + plugins** — no change needed for checkout (they already redirect to the returned `paymentUrl`). **Do**
   move status polling off the public endpoint: `npm` SDK `getPaymentStatus()` and the WooCommerce plugin currently
   call the public `GET /api/customer/payment/:orderId/status`; point them at the **authenticated**
   `GET /api/merchant/payment-status/:orderId` (API key). Ship a new SDK/plugin version.
3. **Docs + landing page** — describe the current integration: build from the returned `paymentUrl` (never construct
   `/checkout/{orderId}` yourself); use the authed endpoints for order detail / status. *(Phase C — in progress.)*
4. **Notify merchants** of the transition + a retirement date for legacy orderId public lookups.
5. **Retire the legacy branch (final, closes enumeration):** after the transition window, remove the `orderId`
   fallback from the `router.param` resolvers (customer + euro) so the public endpoints accept **only** the token →
   guessing an orderId returns nothing.
6. *(minor)* PayPal `return_url` (`/api/paypal/return/:orderId`, a backend callback) and the dead
   `merchant.controller.js` URL builder still use orderId — switch when convenient.

### What each merchant must do after the change
- **Redirects to the returned `paymentUrl`** (GembaKitchen, GembaTicket, GembaPass, WooCommerce, npm-SDK users):
  **nothing** — they automatically receive token URLs.
- **Polls payment status via the public endpoint by orderId** (SDK `getPaymentStatus`, WooCommerce, custom code):
  switch to the authenticated `GET /api/merchant/payment-status/:orderId` (or `GET /api/merchant/payment/:orderId`
  for full detail) with the API key. Update to the new SDK/plugin version. *(Keeps working until step 5 — the
  legacy-orderId retirement — so this is do-before-the-deadline, not an emergency.)*
- **Has old `/checkout/{orderId}` links already emailed/stored:** they keep working during the transition
  (dual-lookup); re-issue links from the new `paymentUrl` before the retirement date.
- **Builds the checkout URL manually from `orderId`:** stop — use the `paymentUrl` returned by
  `POST /api/merchant/payment-request`.
