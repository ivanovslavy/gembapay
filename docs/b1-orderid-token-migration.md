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

## ✅ DONE — Phase 3 (docs + landing page reconciled 2026-07-03)

All integration docs now describe the post-fix method — "always redirect to the returned `paymentUrl` (unguessable
token, not your orderId); use the authenticated `GET /api/merchant/payment/:orderId` for full data incl. customer
email." Applied consistently across:
- **git docs** (committed + pushed): `integration.md`, `api-reference.md`, `webhooks.md`, `DEV_SUPPORT.md`,
  `README.md`, `packages/npm/README.md` — example URLs now show a token, added the "use returned paymentUrl" rule and
  the authed-endpoint note; iframe examples use the returned `paymentUrl`.
- **landing / marketing** (rebuilt + redeployed `gembapay-marketing`): `Integration.jsx`, `Docs.jsx` + i18n
  `en/bg/es` (keys `integration.apiIntegration.flowStep2`, `docs.endpoints.getPaymentDetails`,
  `integration.apiReference.getDetails`).
- **merchant-dashboard** (rebuilt + redeployed): `ApiKeys.jsx` example URL → token.

## ✅ DONE — Phase 4 (SDK + WooCommerce moved to authed endpoints, 2026-07-03)

- **npm SDK bumped to v1.1.0** (`packages/npm`): `getPayment()` → authed `GET /api/merchant/payment/:orderId`
  (returns the full order incl. `customerEmail`, ownership-scoped; unwrapped from `{order}`); `getPaymentStatus()`
  → authed `GET /api/merchant/payment-status/:orderId`. E2E-verified against production with a live key: own order
  200 (customerEmail present), foreign order 404. **Owner still to do: `npm publish` the new version.**
- **WooCommerce plugin** (`packages/woocommerce`): `get_payment` / `get_payment_status` point at the same authed
  endpoints. (These methods were unused — the plugin is webhook-driven — so this is correctness, not a functional
  fix; no plugin re-release urgency.)

## ✅ DONE — Phase 5 (legacy orderId RETIRED — enumeration fully closed, 2026-07-03)

The `router.param('orderId')` resolvers on **customer.routes** and **euro.routes** now resolve **only** the
`accessToken`; a raw/guessed orderId (or any unknown value) returns **404**. Order-metadata enumeration via the
public endpoints is fully closed — the access token is the only public key. **Authenticated merchant endpoints are
unaffected** (they key on orderId, in merchant.routes, outside this resolver). Done immediately at the owner's
request rather than after a transition window — risk verified negligible first: 0 pending orders created in the last
24h (all 137 pending are stale/expired), and the SDK + WooCommerce already use the authed endpoints. E2E-verified:
public by-token 200 / by-orderId 404 (customer + euro + status), garbage 404, authed by-orderId 200.

**B1 core is now CLOSED.** Files backed up in `/home/slavy/gembapay-secfix-backups/20260703-b1-retire-legacy/`.

## 🔜 TO DO

### GembaPay (owner) — remaining
1. **Publish** the npm SDK v1.1.0 (`npm publish`); optionally re-release the WooCommerce plugin (see the update
   steps the owner was given). Merchants on old SDK/plugin versions that polled the public status endpoint by orderId
   should upgrade — those calls now 404.
2. *(optional)* **Notify merchants** that any hand-built `/checkout/{orderId}` links or public status polling by
   orderId no longer work — use the returned `paymentUrl` and the authed endpoints (docs already updated).
3. *(minor)* PayPal `return_url` (`/api/paypal/return/:orderId`, a backend callback) and the dead
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
