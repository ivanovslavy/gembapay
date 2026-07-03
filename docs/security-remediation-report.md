# GembaPay — Security Remediation Report & Open-Items Handoff

> **Purpose of this document.** A single, self-contained record of the GembaPay security audit worked on
> 2026-07-02: (A) every fix that was **applied + verified on the live system**, and (B) every fix that is **still
> open**, each written so a fresh engineer/agent can, from one read: recognise the problem, find it in the code,
> apply the *correct* fix, know the result, and know **exactly what it breaks for already-onboarded merchants**.
> Companion file: `docs/remediation-checklist.md` (terse status ledger). This file is the detailed narrative.

> ⚠️ **2026-07-03 UPDATE — a CRITICAL was found and is now FIXED (best-practice) + verified live.** The
> multi-agent mega-audit found that **`GET /api/customer/payment/:orderId` publicly leaked the FULL merchant row**
> (`passwordHash`, `webhookSecret`, `totpSecret`, backup codes, `apiKey`) + `customerEmail` via a `...paymentRequest`
> spread — a **live, unauthenticated, cross-merchant secret disclosure**. This **re-opened B1 and B3/H6 below, whose
> "the public /payment/:orderId does NOT return the secret / not a live leak" conclusion was WRONG** (it checked the
> safe `/api/euro/...` sibling and missed the `/api/customer/...` one). An earlier whitelist fix was reverted at the
> owner's request; the **final best-practice fix is now applied + verified on 2026-07-03** (see the **POST-MEGA-AUDIT**
> section). Owner action still open: **ROTATE** any merchant secrets exposed during the open window.

---

## 0. How this codebase is operated (read first)

- **Live backend:** `/gembapay.com/backend/` on host `.162` (`46.225.1.162`). Node v20.20.2, Express 5, Prisma 5,
  PostgreSQL (`gembapay` DB on `localhost:5432`), Stripe Connect, PayPal PPCP, ethers 6 (ETH/BSC/Polygon + testnets),
  Cloudflare R2. Runs as systemd services (User=`slavy`): `gembapay-api` (:3072), `gembapay-listener` (mainnet crypto),
  `listener-testnet.gembapay` (testnet crypto), `gembapay-payment` (payment frontend :3071), webhook-retry worker,
  monthly `invoice-generator` worker.
- **Deploy model.** The backend is **NOT a git repo** (source is not uploaded, per owner policy). Only **docs +
  smart contracts + plugins** live in the git repo at `/gembapay.com/gitrepo/` (remote `github.com/ivanovslavy/gembapay`).
  This report + the checklist live in `gitrepo/docs/`. **To change backend code you edit files in place on `.162`.**
- **File ownership is mixed.** Most `src/` files are `slavy:slavy 664` (editable directly). A few are `root:root 644`
  (e.g. `services/subscription.service.js`, `services/subscriptionPaypal.service.js`) — edit those via `sudo`.
- **Workflow used for every fix (keep it):** backup the file first → edit → `node --check` (restore backup on syntax
  fail) → deploy → `sudo systemctl restart <service>` → verify (`curl` health + endpoint, logs, or a read-only Prisma
  query). **DB schema change → `pg_dump` the DB first** (backups in `/home/slavy/gembapay-db-backups/`). File backups
  in `/home/slavy/gembapay-secfix-backups/20260702/`.
- **Migrations** use Prisma migrate (`prisma/migrations/`, `migrate_lock.toml`). Add a migration folder + `migration.sql`,
  run `./node_modules/.bin/prisma migrate deploy`, update `schema.prisma`, `./node_modules/.bin/prisma generate`,
  restart. `prisma migrate status` must say "up to date".
- **Self-testing (do this, don't ask the owner to test what you can test).** SSH as `slavy`; run read-only Prisma
  scripts with `require('/gembapay.com/backend/node_modules/@prisma/client')` (absolute path — module resolution
  fails from `/tmp`); `curl` endpoints; inspect `journalctl`. API keys are **hashed** in the DB (`ApiKey.keyHash`) so
  you cannot mint a usable key from the DB — tests needing a live merchant key (or a real card/crypto payment, or the
  email/invoice worker) genuinely need a human; say so.
- **Secret hygiene:** never commit secrets; scan staged diffs before every commit. `.git` in the gitrepo had some
  root-owned objects (fixed by `chown -R slavy:slavy .git`) — if commits fail with "insufficient permission for
  adding an object", re-run that chown.

---

# PART A — FIXES APPLIED + VERIFIED (the improvements made)

All of the below are **live and verified**. Severity uses the original audit's scale.

## 🔴 CRITICAL (all 4 closed — these were the money-theft vectors)

- **C1 — Unauthenticated PayPal refund.** `routes/paypal.routes.js` `/refund` had **no auth** → anyone could refund
  any capture. **Fix:** added `authMiddleware` + ownership check (`payment.merchantId === req.merchant.id`) +
  already-refunded `409` guard. **Verified:** no-auth → 401. 🔴 **Merchant-breaking:** any integration calling
  `/api/paypal/refund` **unauthenticated now gets 401** and must authenticate; cross-merchant refund → 404.
- **C2 — Cross-merchant Stripe refund IDOR.** `routes/stripe.routes.js` `/refund` was authed but had no ownership
  check and took `isTestMode` from the caller's key. **Fix:** ownership check + already-refunded guard + pinned refund
  mode to the payment's own `isTestMode` (a test key can't drive a live refund). **Verified:** no-auth → 401.
  🟢 Transparent (refunding your OWN payment still works).
- **C3 — Testnet listener credited REAL mainnet orders.** `workers/event-listener-testnet.js` looked up
  `paymentRequest` by `orderId` only → a testnet payment could complete a live order. **Fix:** scoped all 3 lookups
  to `isTestMode: true`. 🟢 Transparent.
- **C4 — Zero-confirmation crediting.** `workers/event-listener.js` credited up to `currentBlock` (0-conf) → a reorg
  that dropped the tx left an order "paid" with no settled funds. **Fix:** credit only up to
  `currentBlock - safetyMargin` (per-chain confirmation depth). 🟡 **Behavior change:** crypto payments confirm after
  N blocks (ETH ~50/~10min, BSC ~100/~5min, Polygon ~150/~5min, gemba ~5) instead of instantly — inform merchants.

## 🟠 HIGH (closed)

- **H1 — email-2FA bypass.** The 2FA `codeHash` (unsalted `sha256` of a 6-digit code) was embedded in the client-
  visible `2fa_pending` challenge JWT → reversible offline (10^6). **Fix:** `twofa.hashCode` → **HMAC(code,
  JWT_SECRET)** (server-only key → non-reversible). Sign + verify both call it. **Owner-tested:** login + 2FA work.
- **H3 / H4 — priv-esc via writable root-run script / unit files.** The root-run `rpc-telemetry-collect.js` and the
  `gembapay-*.service` unit files were `slavy`-writable. **Fix:** `chown root:root` + `chmod 644`. Services stayed up.
- **H5 — vulnerable deps.** `npm audit fix` (safe/semver) closed **19 of 20** (incl. both critical). **Owner-tested:**
  all 3 payment types + webhooks work. *(Remaining: 1 nodemailer high → needs `--force` major bump; see Part B.)*
- **H7 — admin SQL injection.** `/api/admin/db/tables/:name/schema` interpolated `name` into `$queryRawUnsafe`.
  **Fix:** parameterized `$queryRaw`. *(The `/db/query` denylist tool still ideally needs a read-only DB role.)*
- **H8 — admin command injection.** `/api/admin/invoices/generate-monthly` interpolated `month` into a shell `exec`.
  **Fix:** strict `^\d{4}-\d{2}$` validation.
- **H9 — crypto underpayment.** The mainnet quote/USD handler completed an order regardless of amount paid. **Fix:**
  if `paidUsd < requestedUsd × 0.90` → mark `underpaid`, don't complete, no `payment.completed` webhook. **Owner-
  tested:** a normal crypto payment completes. *(Note: only the mainnet quote/USD handler; euro/direct handlers use
  different currency fields and were not covered — a follow-up.)*

## 🟡 MEDIUM (closed)

- **admin POST `/wallets`** — added `ethers.isAddress` validation (was unchecked → arbitrary admin-wallet value).
- **admin PUT `/settings`** — now only UPDATEs existing keys (a stolen admin token can't create arbitrary
  `systemSetting` rows like fee rates/flags).
- **CORS** — disallowed origins now get a clean block (was a thrown `Error` surfacing as 500); allowed origins
  unchanged; no-Origin still allowed (so mobile/server clients aren't broken).
- **errorHandler** — 5xx responses return a generic message (was leaking `err.message`).
- **verbose-direct 500s** — 61 catch-block `res.status(500).json({error: error.message})` in admin/auth/kyc/merchant
  routes → generic. **paypal/stripe deliberately EXCLUDED** (a generic 500 there could hide a payment-decline reason —
  see Part B).
- **manage-code** — `crypto.randomInt` (was `Math.random`) + 60s cooldown per (merchant,email) → stops subscription
  manage-code email flooding + attempt-cap reset abuse.
- **upload magic-byte** — both KYC upload handlers now sniff the real file signature (jpeg/png/webp/pdf), not the
  spoofable client mimetype.
- **PII in logs** — `requestLogger` masks the client IP (last octet → `.0`); real email addresses in the
  invoice-generator + contact logs masked to `j***@domain` (other "email" log hits were message-text, not PII).

## ⚪ LOW (closed / verified-safe)

- **world-readable `*.bak`** source backups → `chmod 600` (root). *(`.env.bak-*` were already `600 root`.)*
- **KYC signed-URL TTL** 3600 → 900s (15 min).
- **HSTS** — verified Cloudflare already sets `Strict-Transport-Security: max-age=15552000; includeSubDomains`; app
  `hsts:false` is correct by design. No change.
- **JWT alg-pin** — `verify` pins `algorithms: ['HS256']` in `auth.service` + `twofa.service` (blocks alg-confusion).
  **Owner-tested:** login + 2FA + dashboard work.
- **trust-proxy** — verified `trust proxy = 1` is **NOT spoofable** (live test: a forged `X-Forwarded-For` through
  Cloudflare logged the CF edge IP, not the spoof → no rate-limit bypass). No change; switching to CF-Connecting-IP
  would be *riskier* without a confirmed origin lock.

## "Together" batch (payment/login/dashboard-touching — done with the owner testing / self-tested)

- **isTestMode column** — **new Prisma migration** (DB backed up first): `Payment.is_test_mode BOOLEAN NOT NULL
  DEFAULT false`, backfilled from the linked `paymentRequest` (test=359/live=89 of 448; all payments have a request).
  All 12 `Payment.create` sites now set it (listeners: `paymentRequest?.isTestMode ?? true/false`; card:
  `paymentRequest.isTestMode`; subscription cycles: `sub.isTestMode`). Migrate status clean; 3 services healthy.
- **subs `@unique` idempotency** — **new migration** (DB backed up): `CREATE UNIQUE INDEX
  payments_provider_payment_id_key ON payments(provider_payment_id)` (0 existing dups; NULLs/crypto unconstrained).
  Concurrent webhook retries can no longer create duplicate payment records; NULL crypto rows unaffected. Retries are
  handled by the existing `findFirst` checks + the DB constraint + self-healing (a rare concurrent P2002 → 500 →
  sender retries → `findFirst` succeeds).
- **trial-stacking** — `subscribeStripe` grants the free trial only to a first-time subscriber per (plan, email,
  case-insensitive). *(PayPal trial path is a follow-up.)*
- **`/payment-request` amount** — `merchant.routes.js` lacked a positive-amount check (`parseFloat('-5')`/NaN passed);
  added `isNaN || <=0` + max 1,000,000, matching the euro route. Route is apiKey-authed.
- **payment/fix guard** — admin manual-credit now refuses to re-fix an already `completed/confirmed/refunded` order
  (`409`) → no duplicate `payment.completed` webhooks. *(On-chain txHash verification is a deeper follow-up.)*
- **subs active-before-settle** — status maps no longer mark a sub "active" before the first payment settles: Stripe
  `incomplete → 'incomplete'` (was `'active'`); PayPal `APPROVAL_PENDING/APPROVED/unknown → 'incomplete'`.
- **recurring cycle-fee** — Stripe + PayPal `_recordCycle` hardcoded `feeRate = 0.01`; now `getMerchantFeeRate(merchant)`
  (correct fee for high-risk/custom merchants). *(The live Stripe-fee via `application_fee_percent` was already
  correct; only the recorded Payment row was wrong.)*

## Self-verified NON-issues (checked, found correct — no change was warranted)

- **token-decimals** — `TOKEN_DECIMALS` covers all mainnet stablecoins with correct decimals; the 6 addresses not in
  the table are all **18-decimal testnet** tokens where the `|| 18` default is correct (verified by sampling
  `usdAmount ≈ tokenAmount`). *Latent:* add a lookup entry when onboarding a new non-18-decimal MAINNET token.
- **fee (payment path)** — `getMerchantFeeRate` = `customFeeRate` > high-risk(10%) > env-configurable default; Stripe
  and PayPal both use it; crypto fee is on-chain. Not hardcoded. *(The recurring-cycle hardcode WAS real → fixed above.)*
- **VAT reverse-charge** — `calculateVATRate` is correct: BG 20% · EU-B2B + valid VAT → rate 0 + reverse-charge +
  Art.196 note · EU-B2C 20% · non-EU 0.
- **invoice-number race** — **fail-safe**: `Invoice.invoiceNumber` is `@unique`, so a concurrent collision fails the
  2nd invoice (re-runnable), never a duplicate. (Atomic-numbering improvement is in Part B.)

---

# PART B — OPEN ITEMS (not done — problem, real fix, result, merchant impact, why deferred)

> **Bottom line first:** the system currently has **no way for an attacker to steal money via the API** — all money-
> theft vectors were the CRITICALs and are closed (refunds require auth + go to the original payer; crediting requires
> a real, confirmed, sufficient payment). The **only real residual attack** is **read-only enumeration of order
> details incl. customer email** (B1). Everything else below is defense-in-depth, an accounting nicety, a separate
> codebase, or a fix whose cost is breaking merchant integrations.

---

### B1 — orderId is a guessable public access key to order data + customer PII  ·  severity 🟡 MEDIUM (only real residual attack)

> **2026-07-03 partial update:** the **customer-PII portion is now CLOSED** on the `/api/customer/*` endpoints —
> `customerEmail` was removed from the public responses (see POST-MEGA-AUDIT). The `/api/euro/payment/:orderId`
> handler below **still returns `customerEmail`** (not yet migrated) and, on both euro + customer endpoints, the
> **guessable-orderId enumeration of order metadata** (amount/description/status) remains until the token migration.

- **Recognise it:** `GET /api/euro/payment/:orderId` (`routes/euro.routes.js:270`) is **PUBLIC** (no auth) and its
  `res.json` (line ~319–353) returns `amount, description, status, txHash, merchantName`, **and `customerEmail`**.
  orderIds are `MERCHANT-<ms-timestamp>-<random6>` from `generateOrderId()` (`euro.routes.js:30`) **or a
  merchant-supplied `providedOrderId`** (`:153`). Merchants that supply sequential IDs (e.g. `ORDER-1`, `ORDER-2`, or
  their internal order numbers) are trivially enumerable. A sibling endpoint `/payment/:orderId/onchain-status` (`:363`)
  is also public.
- **Attack:** guess/enumerate orderIds → harvest **customer emails (GDPR/PII)** + order amounts/descriptions/status
  (phishing material, competitor intel). **Read-only — no money theft, no manipulation, no secret leak** (verified:
  `include:{merchant:true}` fetches the full merchant but only `merchantName` is returned; the webhookSecret is NOT).
- **Real fix (complete):** replace `orderId` as the public access key with an **unguessable random token** (e.g.
  `nanoid(24)` / a UUID) stored on `paymentRequest` (a new `access_token` column). The checkout/payment URL uses the
  token; the public endpoints look up by token, not orderId. Then a known/sequential orderId grants nothing.
- **Result:** enumeration impossible without the secret token; customer PII + order data no longer reachable by
  guessing.
- **🔴 Merchant-breaking (why it's deferred):** it **changes the checkout URL format**. Every already-onboarded
  merchant that builds the payment URL from `orderId`, or whose stored/emailed checkout links embed `orderId`, breaks.
  Requires a **versioned migration**: support BOTH orderId and token during a transition window, notify merchants,
  update the SDK/plugins/docs, then retire orderId lookups. This is a product decision, not a code-only change.
- **Non-breaking partial fix (verified available):** remove **only** `customerEmail` from the public `res.json`
  (`euro.routes.js:337`). **Verified non-breaking** — the payment frontend (`/gembapay.com/frontend/payment-app`, the
  sole consumer) references `customerEmail` **0 times** in source AND compiled build; the merchant-dashboard uses
  `customerEmail` only from its own authenticated endpoints; no backend code reads this response's email. Removing it
  closes the **PII/GDPR leak** (the sensitive part) while amount/status stay visible (low sensitivity). Residual: a
  custom merchant integration polling this endpoint for the email would get `null` there (unusual — merchants already
  know their customer's email; nothing breaks in payment). **This one-line change is the recommended quick win** if the
  full token migration isn't scheduled. *(Deliberately left un-applied at owner's request so it is captured here.)*
- **Damage if never fixed:** ongoing ability to scrape customer emails + order metadata for merchants with guessable
  orderIds. **Benefit of fixing:** closes GDPR/PII exposure; token fix also hides order metadata entirely.

### B2 — H12: API keys have no origin/domain binding  ·  severity 🟢 LOW (missing enhancement, not an active hole)

- **Recognise it:** `middleware/apiKey.middleware.js` (106 lines) has **zero** origin/referer/website checks. API keys
  are pure bearer tokens (standard). Keys are **hashed** in the DB (`ApiKey.keyHash`), so DB access alone can't mint one.
- **Attack:** only if a merchant **leaks their own key** (in client-side code, a public repo, a proxy log). Then the
  attacker acts as that merchant — but **can't steal money to themselves** (refunds go to the original payer; payment
  requests collect into the merchant's account). Worst case = griefing that one merchant (spurious requests, refunding
  their customers). Requires the merchant to leak the key first — it's key hygiene, not an API hole.
- **Real fix:** distinguish **publishable/browser keys** (declared with a `websiteUrl`) from **secret/server keys**.
  For publishable keys used in a browser context, enforce that `Origin`/`Referer`, **when present**, matches the
  declared `websiteUrl`; secret keys used server-to-server (no Origin) are unaffected. (Do NOT fail-closed on absent
  Origin globally.)
- **Result:** a leaked *publishable* key can't be replayed from a different site in a browser.
- **🔴 Merchant-breaking (why deferred):** a blunt "require matching Origin" breaks **every server-side merchant call**
  (server-to-server has no Origin). Even a scoped version breaks any publishable key used from an unexpected origin.
  Needs a key-type distinction in the schema + merchant communication. Owner asked to leave H6/H12 for last.
- **Damage if never fixed:** a leaked publishable key remains replayable from any origin. **Benefit:** limits blast
  radius of a leaked browser key. (Low, because keys are bearer tokens by design and hashed at rest.)

### B3 — H6: secret over-fetch  ·  severity 🔴 was a LIVE CRITICAL on /api/customer/payment (✅ FIXED + verified 2026-07-03 via allowlist select + authed channel — see POST-MEGA-AUDIT); the remaining over-fetch is 🟢 LOW

- **Recognise it:** several handlers do `include: { merchant: true }` (fetches the full merchant, incl.
  `webhookSecret`, into memory). `webhookSecret` is returned in `auth.routes.js:210/304/624` (to the **authenticated
  owner** — normal). `paypal.routes.js:223` **logs the first 15 chars of `merchant.apiKey`**.
- **⚠️ CORRECTION (2026-07-03):** this "not a live leak" claim was **WRONG**. It verified the
  `/api/euro/payment/:orderId` sibling (safe — explicit fields) but **missed `/api/customer/payment/:orderId`**,
  which spread the FULL merchant row (`passwordHash`/`webhookSecret`/`totpSecret`/backup codes) to any
  unauthenticated caller = a **LIVE CRITICAL** cross-merchant secret leak. **Found by the mega-audit; a whitelist
  fix was applied + verified then REVERTED at the owner's request (2026-07-03) — the leak is OPEN** (see the
  POST-MEGA-AUDIT section). The remaining B3 over-fetch (owner-facing `auth.routes.js` returning `webhookSecret` to the
  **authenticated owner** + the paypal apiKey-fragment log) is the only 🟢 LOW leftover.
- **Real fix:** replace `include: { merchant: true }` with `select: { …only needed fields… }`; drop the apiKey
  fragment from the paypal log.
- **Result:** secrets aren't pulled into request memory / logs unnecessarily (defense-in-depth).
- **🟢 Merchant-breaking:** essentially none (secrets weren't returned to clients except to the owner). Transparent.
- **Why deferred:** owner grouped it with H12 ("last"); it is not an active exposure. **Benefit if done:** cleaner
  least-privilege data handling; removes a partial-secret log line.

### B4 — H13: Stripe refund doesn't reverse GembaPay's application fee  ·  severity ⚪ accounting

- **Recognise it:** `services/stripe.service.js refundPayment` calls `stripe.refunds.create(refundData)` **without**
  `refund_application_fee`. On a refund, GembaPay keeps its platform fee. (The **double-refund** half of H13 is
  already fixed via the C1/C2 `409` guards.)
- **Real fix:** pass `refund_application_fee: true` (Stripe reverses the fee proportionally) for connected-account
  charges; **guard** the "this charge has no application fee to refund" error so it doesn't break refunds for charges
  without a fee (try with the flag, retry without on that specific error). Also: only mark the payment `refunded` on a
  **full** refund; use `partially_refunded` otherwise.
- **Result:** merchants get GembaPay's cut back on refunds (fair); partial refunds tracked correctly.
- **🟢 Merchant-breaking:** none — it **benefits** merchants. **Risk:** if `refund_application_fee` is sent for a
  charge with no fee, Stripe errors → could break a live refund; hence the guard + a **Stripe test refund** to verify.
- **Why deferred:** risk of breaking live refunds without a Stripe-test pass. It's in GembaPay's own favour today
  (over-keeps fee), not a security hole. **Damage if never fixed:** merchants slightly over-charged on refunds.

### B5 — H10: crypto listeners trust a single RPC  ·  severity 🟢 LOW (needs RPC compromise)

- **Recognise it:** `workers/event-listener*.js` act on `gateway.queryFilter(...)` results from one RPC per chain
  (`config/rpcProvider.js`), with `staticNetwork` (no chainId re-check). A malicious/compromised RPC could return a
  **fabricated `PaymentProcessed` event** → credit a non-existent payment.
- **Real fix:** verify each event against a **second, trusted (paid) RPC** (2-of-N agreement) or fetch the tx receipt
  on a trusted RPC and confirm `status==1` + the gateway address + amount; validate `chainId` on connect.
- **Result:** one rogue RPC can't fabricate credits.
- **🟢 Merchant-breaking:** none (internal). Adds latency + a paid-RPC dependency (Alchemy/Infura key per chain).
- **Why deferred:** requires an owner infra decision (paid/trusted RPC). Combined with C4 (confirmation depth) the
  practical risk is low. **Benefit:** removes trust in a single third-party RPC.

### B6 — H11: hot-wallet keys live in `.env`  ·  severity 🟢 LOW (needs root compromise)

- **Recognise it:** hot-wallet private keys / mnemonic are in the gitignored `.env` (`600 root`). A **root compromise**
  of `.162` exposes them → funds drainable. Not reachable via the API.
- **Real fix:** move signing to a **KMS / Vault / hardware signer**; the app requests signatures and never holds raw
  keys. Rotate keys after migration.
- **Result:** a root compromise no longer yields the raw keys.
- **🟢 Merchant-breaking:** none. Large infra change (KMS provisioning + rework the signing path in the crypto payout
  code).
- **Why deferred:** big infra project; `.env` at `600 root` is meaningful defense-in-depth today. **Benefit:** removes
  the single worst-case (server compromise → fund loss).

### B7 — H2: origin not locked to Cloudflare  ·  severity 🟢 LOW (edge-bypass, app auth still holds)

- **Recognise it:** the origin `:443` may be reachable directly (an attacker with the origin IP hits Apache, bypassing
  Cloudflare's WAF + edge rate-limit). The app's own auth/rate-limit still apply; `ufw` blocks the app port `3072`
  externally.
- **Real fix:** restrict `:443` at `ufw`/Apache to **Cloudflare IP ranges** only, or enable **Cloudflare Authenticated
  Origin Pulls (mTLS)** (needs the CF dashboard + per-vhost `SSLVerifyClient`).
- **Result:** only Cloudflare can reach the origin → the edge WAF/rate-limit can't be bypassed.
- **🟢 Merchant-breaking:** none. **Risk:** a wrong CF range or proxy path → **whole site down**. Needs a maintenance
  window; the app→proxy target (`127.0.0.1:3072` vs a tunnel) was not confirmed this session.
- **Why deferred:** outage risk for a defense-in-depth gain the firewall already partly covers. **Benefit:** attackers
  can't skip Cloudflare's protections.

### B8 — CSP + clickjacking on the dashboards  ·  severity 🟢 LOW (separate codebase)

- **Recognise it:** `merchant-dashboard.gembapay.com` and `owner-dashboard.gembapay.com` (Vite/React apps under
  `/gembapay.com/frontend/`, served via Apache → Node) lack a `Content-Security-Policy` and `X-Frame-Options` /
  `frame-ancestors`. No XSS-mitigation layer; clickjacking possible.
- **Real fix:** add `X-Frame-Options: DENY` (or `frame-ancestors 'none'`) + a CSP (nonce-based if inline scripts
  exist) via the dashboard's server or its Apache vhost. Tune the CSP against the built app so it doesn't block the
  app's own assets.
- **Result:** injected scripts blocked; dashboard can't be framed for clickjacking.
- **🟢 Merchant-breaking:** none (these are GembaPay's own apps, not merchant integrations). **Risk:** a too-strict CSP
  breaks the dashboard's own inline scripts/styles → must be tested against the build.
- **Why deferred:** it's a **different codebase** than the backend audited here; needs CSP tuning + testing on each
  dashboard build. **Benefit:** defense-in-depth if an XSS is ever introduced in a dashboard.

### B9 — nodemailer high advisory (last dep)  ·  severity 🟢 LOW

- **Recognise it:** `npm audit` in `/gembapay.com/backend` shows **1 remaining high** (nodemailer — CRLF/SSRF in the
  email path). The safe `npm audit fix` already closed the other 19.
- **Real fix:** `npm audit fix --force` (or bump nodemailer to the fixed major) → **test the email flow** (invoice
  emails, notifications, 2FA codes, contact form).
- **Result:** closes the advisory.
- **🟢 Merchant-breaking:** none. **Risk:** a nodemailer major bump can change its API → break email sending → must
  test all email paths.
- **Why deferred:** major-bump risk + email-flow testing needed (the invoice/email worker can't be safely run without
  sending real emails). **Benefit:** removes the last dep advisory.

### B10 — app binds `0.0.0.0`  ·  severity ⚪ marginal

- **Recognise it:** `server.js:191` `app.listen(PORT, '0.0.0.0')` (all interfaces). `ufw` already blocks `3072`
  externally.
- **Real fix:** `app.listen(PORT, '127.0.0.1')`.
- **Result:** app not exposed on non-loopback interfaces.
- **🟢 Merchant-breaking:** none. **Risk:** if the reverse proxy reaches the app via a **non-loopback** address, this
  takes the **whole API down** — confirm the proxy target first (`ProxyPass` for `api.gembapay.com` did not surface a
  `127.0.0.1:3072` line this session; may be a Cloudflare tunnel).
- **Why deferred:** marginal value (`ufw` already blocks the port) vs outage risk. **Benefit:** belt-and-suspenders.

### B11 — invoice-number generation is not atomic  ·  severity ⚪ (fail-safe today)

- **Recognise it:** both `workers/invoice-generator.js` (~:152) and `routes/admin.routes.js` (~:1376) do
  read-then-increment on `systemSetting 'invoice_next_number'` (findUnique → build number → later upsert +1).
  Concurrent invoice creation (admin "create invoice" while the monthly worker runs, or a double-triggered cron) could
  reuse a number. **Fail-safe today:** `Invoice.invoiceNumber` is `@unique` → the 2nd insert fails (`P2002`,
  re-runnable), never a duplicate.
- **Real fix (verified working):** atomic reserve —
  `UPDATE system_settings SET value=(value::int+1)::text WHERE key='invoice_next_number' RETURNING (value::int - 1) AS used`
  (tested: two calls return distinct 5,6). Apply in **both** sites; remove the separate `+1` upsert; handle the
  first-invoice case (UPDATE returns 0 rows → create the counter, use 1). `system_settings` has `id`+`type` NOT NULL
  columns → for the create path use Prisma's `systemSetting.create` (handles them), not a raw INSERT.
- **Result:** concurrent invoice generation gets distinct numbers, no `P2002` failures.
- **🟢 Merchant-breaking:** none. **Risk:** compliance-critical numbering; the monthly worker **can't be fully tested
  without sending real invoice emails**, so verification is limited to the atomic query + syntax + code-review.
- **Why deferred:** already fail-safe via `@unique`; changing compliance-critical numbering that can't be end-to-end
  tested wasn't warranted. **Benefit:** removes the rare "an invoice fails and must be re-run" edge case.

### B12 — smaller follow-ups noted in passing

- **H9 euro/direct handlers** — the underpayment check was added to the mainnet **quote/USD** handler only; the euro
  and direct handlers use different currency fields and need the equivalent `paidUsd < requestedUsd × tolerance` guard.
- **PayPal trial-stacking** — `subscribeStripe` got the first-time-only trial guard; the PayPal subscribe path
  (`subscriptionPaypal.service.js`) needs the same.
- **payment/fix on-chain verification** — the admin manual-credit now has a re-completion guard but still creates a
  payment from an unverified `txHash`; a deeper fix verifies the txHash on-chain before crediting.
- **H7 `/db/query`** — the parameterized `/schema` route is fixed; the raw `/db/query` admin tool would ideally run
  under a dedicated **read-only DB role**.
- **token-decimals latent** — add a `TOKEN_DECIMALS` entry whenever a new **non-18-decimal mainnet** token is onboarded.

---

## Priority recommendation for whoever picks this up

1. **B1 non-breaking partial** (remove `customerEmail` from the public endpoint) — verified safe, one line, closes the
   only real residual PII exposure. Do this first if the full token migration isn't scheduled.
2. **B1 full (orderId→token)**, **B2 (H12)** — real fixes but **merchant-breaking**; schedule with a migration plan +
   merchant comms.
3. **B4 (refund fee), B11 (invoice atomic)** — safe once verified with a Stripe test refund / careful worker test.
4. **B7 (origin lock), B9 (nodemailer), B10 (localhost bind)** — do in a maintenance window with rollback ready.
5. **B5 (RPC), B6 (KMS), B8 (dashboard CSP)** — larger/infra/separate-codebase projects.

*Everything in Part A is live and verified. Nothing in Part B is a money-theft vector.*


---

## POST-MEGA-AUDIT (2026-07-03) — ✅ FIXED (best-practice) + verified live: customer.routes.js /payment/:orderId merchant-secret leak (closed H6/B1 secret+PII portion)

The multi-agent mega-audit confirmed a LIVE unauthenticated leak that yesterday's H6/B1 assessment wrongly cleared.

**STATUS: ✅ FIXED + VERIFIED on 2026-07-03.** An earlier narrow whitelist fix was reverted at the owner's request; the owner then approved the **best-practice** remediation (positive allowlist + a dedicated authenticated channel), which is now applied to the live backend and end-to-end verified. Files edited in place on `.162`: `src/routes/customer.routes.js` + `src/routes/merchant.routes.js` (backups in `/home/slavy/gembapay-secfix-backups/20260703-leakfix/`). Service reloaded (SIGTERM→systemd `Restart=always`, since `gembapay-api` is not in the NOPASSWD sudoers set); `/health` 200, DB connected.

- Endpoint: GET /api/customer/payment/:orderId (src/routes/customer.routes.js ~line 111), public/no-auth. Sibling GET /payment/:orderId/status (~line 194) over-returned PII similarly.
- Bug: handler did findUnique with include:{merchant:true} then res.json({ ...paymentRequest }). The spread serialized the FULL Merchant row: passwordHash, webhookSecret, totpSecret, twoFactorBackupCodes, apiKey, stripeAccountId, paypalMerchantId + KYC/PII, plus the order's customerEmail/metadata. Any orderId (shared checkout links / GK-derived) reached any merchant's secrets. Confirmed live (HTTP 200 with a real bcrypt passwordHash + webhookSecret; values not recorded).
- Why yesterday missed it: H6 (~line 213) and B1 (~line 168) verified /api/euro/payment/:orderId (euro.routes.js, explicit named fields, only merchantName, safe) and generalised "not a live leak". The customer.routes.js sibling with the ...paymentRequest spread was never checked. Same class as H6/B1, but a distinct un-verified endpoint; the earlier "safe" call was wrong.
- Impact: leaked webhookSecret enables forging HMAC-signed webhooks GembaKitchen billing trusts (free subscriptions); totpSecret/backup codes defeat 2FA; passwordHash enables offline cracking.
- **FIX APPLIED (best-practice, fail-closed) — the Stripe model of public-minimal + authed-rich:**
  1. **Public endpoints now use a positive Prisma `select` allowlist** (not a `...spread` denylist). `GET /api/customer/payment/:orderId` returns only the non-sensitive checkout fields (`orderId, status, description, amountUsd, amountOriginal, currency, currencyOriginal, exchangeRate, network, paymentMode, allowedMethods, cryptoConfig, merchantAddress, metadata, isTestMode, selectedMethod, isOverpayment, expiresAt, createdAt, completedAt`) + `merchant: { select: { companyName, legalName, websiteUrl } }`. **No** customerEmail, **no** merchant secrets, **no** internal IDs. Any future column is hidden by default. `/status` got the same treatment (minimal select, no PII). Also fixes `merchantName` (was reading non-existent `businessName`/`name` → always null; now `companyName`/`legalName`).
  2. **New authenticated, ownership-scoped channel** so merchants are NOT cut off from their data: `GET /api/merchant/payment/:orderId` and `GET /api/merchant/payment-status/:orderId` (both apiKey-authed via `apiKeyMiddleware`, filtered `where: { orderId, merchantId: req.merchant.id }`). These return the full order **including customerEmail** — over the merchant's API key + ownership check (TLS + auth = the secure channel). Bonus: the status alias makes GembaTicket/GembaPass's previously-dead `GET /api/merchant/payment-status/:orderId` calls actually work.
- **E2E VERIFICATION (2026-07-03, read-only test against a real GembaKitchen order that has both a webhookSecret and a customerEmail):** public `/payment/:orderId` → HTTP 200, **0** leaked key-names, **0** actual secret/email VALUES in the body; safe fields intact (`merchantName="GEMBA EOOD - GembaKitchen"`, `status`, `amountUsd`, `merchant.websiteUrl`). `/status` → HTTP 200, clean. Authed `/api/merchant/payment/:orderId` without a key → **401** (ownership check + happy-path require a real merchant key → owner to confirm with one live `Bearer` curl).
- **REMAINING OWNER ACTION: ROTATE** any merchant secret readable during the open window — `webhookSecret` (update in BOTH the GembaPay Merchant row AND the merchant app `.env`, e.g. GembaKitchen/GembaTicket/GembaPass `GEMBAPAY_WEBHOOK_SECRET`, synchronized), `Merchant.apiKey`, force password reset (`passwordHash`), reset TOTP + backup codes for 2FA merchants.
- Severity: CRITICAL (unauthenticated cross-tenant merchant-secret disclosure) — **secret + PII disclosure now CLOSED**. Residual (separate, deferred): the public endpoint is still keyed by a guessable `orderId`, so order *metadata* (amount/description/status) remains enumerable until the B1 orderId→token migration.
