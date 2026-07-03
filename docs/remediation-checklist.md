# GembaPay — Security Remediation Checklist (started 2026-07-02)

Fixes applied to the LIVE backend (`/gembapay.com/backend`, not git-tracked — backend source is not uploaded per
owner policy; only docs + contracts + plugins live in this repo). Workflow per fix: **backup → edit → syntax-check
→ deploy → restart `gembapay-api` → e2e verify**. Full findings: `docs/security-audit-2026-07-02.md`.

**Legend:** `[x]` done+verified · `[ ]` pending · 🔴 BREAKING (merchant must update integration) · 🟡 behavior-change
(no merchant code change, but experience/amounts/timing differ — notify) · 🟢 transparent.

## 🔴 CRITICAL
- [x] **C1 — Unauthenticated PayPal refund** (`routes/paypal.routes.js`). Added `authMiddleware` + ownership
  (`payment.merchantId === req.merchant.id`) + already-refunded (409) guard. **Verified:** no-auth → 401, service healthy.
  🔴 **BREAKING** — see migration notes.
- [x] **C2 — Cross-merchant Stripe refund IDOR** (`routes/stripe.routes.js`). Added ownership check + already-refunded
  guard + pinned refund mode to the payment (not the caller's key → no test-drives-live). **Verified:** no-auth → 401.
  🟢 Transparent — refunding your OWN payment still works; only cross-merchant abuse blocked.
- [x] **C3 — Testnet listener credits REAL mainnet orders** (`workers/event-listener-testnet.js`). Scoped all 3
  order lookups to `isTestMode: true`. **Verified:** testnet listener restarted, pollers active, no crash.
  🟢 Transparent (fixes a bug; testnet payments can no longer resolve/complete live mainnet orders).
- [x] **C4 — Zero-confirmation crediting** (`workers/event-listener.js`). Credit only up to
  `currentBlock - safetyMargin` (per-chain confirmation depth) + guard. **Verified:** mainnet listener restarted,
  all pollers active, no crash. 🟡 **BEHAVIOR-CHANGE** — see migration notes.

## 🟠 HIGH
- [x] **H1 email-2FA bypass** (`hashCode` → keyed HMAC(code, JWT_SECRET) so the codeHash in the challenge JWT is
  not reversible offline; **owner-tested: login works**) · [ ] H2 CF origin-lock (deferred — whole-site outage risk;
  do in a maintenance window) · [x] **H3 root priv-esc** (rpc-telemetry script → root:root 644) ·
  [x] **H4 unit-file perms** (all `gembapay-*.service` → root:root 644; daemon-reload; services stayed active) · [x] **H5 deps** (`npm audit fix` safe/semver → **19 of 20 fixed incl. both
  critical**; app restarted clean, **owner-tested: all 3 payments + webhooks work**. Remaining: nodemailer 1 high
  needs `--force` major bump → deferred, needs email-flow test) ·
  [x] **H6 secret over-fetch — CRITICAL sub-case FIXED 2026-07-03** (the public `/api/customer/payment/:orderId` +
  `/status` leaked the FULL merchant row incl. `webhookSecret`/`passwordHash`/`totpSecret`/`apiKey` + `customerEmail`
  via a `...paymentRequest` spread → replaced with a positive Prisma `select` allowlist; added authed ownership-scoped
  `GET /api/merchant/payment/:orderId` + `/payment-status/:orderId` so merchants keep access to their own full data.
  **E2E-verified: 0 secrets/PII on the public endpoints, safe fields intact, authed route 401 without key.** Owner
  action: ROTATE exposed secrets. Remaining owner-facing over-fetch [auth.routes webhookSecret to the owner + paypal
  apiKey-fragment log] = 🟢 LOW, still open) · [x] **H7 raw-SQL admin** (schema route SQLi → parameterized `$queryRaw`; `/db/query`
  denylist still needs a read-only DB role — noted) · [x] **H8 cmd-injection** (`generate-monthly` `month` → strict
  `YYYY-MM` validation) · [x] **H9 amount-verify** (main quote/USD handler: paid < requested×0.90 → `underpaid`,
  not completed, no webhook; **owner-tested: normal crypto payment completes + webhook arrives**. Euro/direct
  handlers not yet covered — different currency fields) · [ ] H10 RPC-verify ·
  [ ] H11 hot-wallet keys · [ ] H12 API-key domain binding (LAST — merchant-breaking) ·
  [~] **H13 refund state** — the exploitable **double-refund is CLOSED** (already-refunded `409` guard added with
  C1/C2). Fee-reversal (`refund_application_fee`) + partial-refund status **DEFERRED**: they risk breaking live
  refunds for edge-case charges and need a Stripe test refund to verify — accounting refinement, not a security hole.

## 🟡 MEDIUM
- [ ] 30-day JWT/revocation · [x] **manage-code** (`crypto.randomInt` not `Math.random`; 60s cooldown per
  (merchant,email) stops email flood + attempt-cap reset) · [ ] subscription-cycle idempotency · [ ] fee-hardcoded-1% ·
  [x] **VAT reverse-charge** (self-verified CORRECT: `calculateVATRate` → BG 20% · EU-B2B+validVAT → rate 0 +
  reverse-charge + Art.196 note · EU-B2C 20% · non-EU 0; not a bug) · [~] **invoice-number race** (FAIL-SAFE: `invoiceNumber`
  is `@unique` → duplicates impossible; concurrent race → 2nd invoice fails `P2002`, re-runnable, no corruption.
  Both worker + admin do read-increment numbering; atomic fix `UPDATE system_settings SET value=(value::int+1) RETURNING
  value-1` verified working but DEFERRED — compliance-critical, can't fully test worker without sending real invoice emails) ·
  [x] **upload magic-byte** (both KYC upload handlers now sniff
  the real file signature — jpeg/png/webp/pdf — not the spoofable client mimetype) · [ ] dashboard clickjacking-scope ·
  [ ] real CSP · [x] **PII logs** (central `requestLogger` IP masked → `.0`; real email addresses in
  invoice-generator + contact logs masked → `j***@domain`; other "email" log hits were message-text, not PII) ·
  [x] **verbose errors** (central `errorHandler` generic-5xx + **61 direct catch-block 500s**
  genericized in admin/auth/kyc/merchant; paypal/stripe deferred to "together" — a generic 500 could hide a payment
  decline reason) · [x] **admin POST /wallets + PUT /settings DONE**
  (payment/fix txHash-check DEFERRED — touches crediting) · [~] **orderId→token (B1) — Phase 1 SHIPPED 2026-07-03**
  (`access_token` uuid column + backfill; checkout/success/cancel URLs now carry the token; `customer.routes` **and**
  `euro.routes` resolvers accept token or legacy orderId; euro endpoint also stripped of `customerEmail`; authed
  endpoints keep orderId. **E2E-verified (customer + euro).** Remaining: move SDK/plugin status-polling to the authed
  endpoint + retire the legacy-orderId branch after the merchant transition — see `b1-orderid-token-migration.md`) ·
  [x] **CORS** (disallowed origin → clean block not 500; no-Origin allow kept so mobile/server clients aren't broken) ·
  [~] 0.0.0.0→localhost (deferred — ufw already blocks the port; proxy-target unconfirmed → outage risk) ·
  [x] **Payment isTestMode** (migration + backfill + 12 sites — done) ·
  [x] **idempotency @unique** (DB backed up; `CREATE UNIQUE INDEX payments_provider_payment_id_key`; 0 dups; NULLs
  unconstrained; findFirst + DB constraint + self-healing retries; migrate status clean) ·
  [x] **token decimals** (self-verified NOT a bug: all mainnet stablecoins in `TOKEN_DECIMALS` with correct decimals;
  6 unlisted addresses are all 18-dec TESTNET tokens where default-18 is correct. Latent: add a lookup entry when
  onboarding a new non-18-decimal MAINNET token).

**"Together" batch (touch payments/login/dashboard) — progress:** [x] JWT alg-pin (tested) · [x] trial-stacking ·
[x] token-decimals (not-a-bug) · [x] fee-1% (not-a-bug) · [x] /payment-request amount · [x] **payment/fix**
(re-completion 409 guard; audit-logged; on-chain txHash verify = deeper follow-up) · remaining below:
**Deferred MEDIUM that touch payments/login/dashboard (do together, per owner):** ~~payment/fix~~,
subscription-cycle `@unique` (billing + migration), ~~fee-hardcoded-1%~~ (self-verified NOT a bug: `getMerchantFeeRate` = customFeeRate > high-risk(10%) > env-configurable
default; Stripe+PayPal both use it; crypto fee is on-chain) + VAT + invoice-race (invoicing/amounts),
~~`Payment.isTestMode` column~~ **(DONE — DB backed up first; Prisma migration ADD COLUMN + backfill from linked
request [test=359/live=89]; all 12 `Payment.create` sites now set it from the right source; migrate status clean; 3
services restarted healthy)**, ~~token-decimals~~ (not-a-bug), real CSP (dashboard rendering),
clickjacking-scope (Shopify embed), orderId→token (BREAKING checkout), 0.0.0.0→localhost (bind/restart).

## ⚪ LOW / latent
- [~] `.env.bak-*` sprawl (already `600 root` — not exposed; owner may purge for retention) ·
  [x] **world-readable `*.bak`** (all source backups → `600 root`) · [x] **trust proxy** (self-verified SAFE, no change: `trust proxy = 1` is NOT spoofable — live test with a
  forged `X-Forwarded-For: 88.88.88.88` through CF logged the CF edge IP, not the spoof → no rate-limit bypass.
  Switching to CF-Connecting-IP would be *riskier* without a confirmed origin lock) ·
  [x] **HSTS** (verified: Cloudflare sets `max-age=15552000; includeSubDomains`; app `hsts:false` correct by design) ·
  [x] **JWT alg pin** (verify pins `algorithms: ['HS256']` in auth.service + twofa.service; **owner-tested: login + 2FA + dashboard all work**) ·
  [x] **subs active-before-settle** (Stripe `incomplete`→'incomplete'; PayPal APPROVAL_PENDING/APPROVED/default→'incomplete'
  — not marked active until first payment settles) · [x] **recurring cycle-fee** (Stripe+PayPal `_recordCycle` hardcoded
  `feeRate=0.01` → `getMerchantFeeRate`; payment path was fine, only recurring record was wrong) · [x] **trial-stacking** (Stripe `subscribeStripe`: trial only for first-time subscriber per plan+email,
  case-insensitive; **self-verified** via read-only prisma query — valid, no runtime error; PayPal path noted TODO) · [x] **`/payment-request` amount
  bound** (merchant.routes lacked positive-check — parseFloat(-5)/NaN passed; added `isNaN||<=0` + max 1M, parity
  with euro route; route confirmed apiKey-authed; live bad-amount test needs a merchant key = hashed/unavailable) ·
  [x] **KYC signed-URL TTL** (3600→900s, 15 min) ·
  [~] `r2://` leak (private bucket, low-sensitivity — marginal) · [x] dead wallet-bearer route (searched — not present) ·
  [~] blog `dangerouslySetInnerHTML` (frontend, not backend) · [~] web3 nonce (crypto → together) · [~] DB DDL (needs read-only role — owner) ·
  [ ] latent JWT fallback fail-closed · [ ] latent webhook fail-open → fail-closed.

---

## 📣 MERCHANT MIGRATION NOTES (breaking / behavior changes — for the merchant-facing docs)
- **C1 (🔴 BREAKING):** `POST /api/paypal/refund` now **requires merchant authentication** (was open to anyone) and
  only refunds payments belonging to the calling merchant. Any integration calling this endpoint **unauthenticated
  will now receive `401`** and must authenticate (merchant session). Refunding another merchant's capture is blocked (`404`).
- **C4 (🟡 behavior-change, no code change):** crypto payments are now confirmed only after a per-chain
  **confirmation depth** (Ethereum ~50 blocks/~10 min, BSC ~100/~5 min, Polygon ~150/~5 min, gemba ~5). Orders are
  marked paid **slightly later** (after finality) instead of at 0 confirmations — this prevents reorg-based false
  credits. No merchant code change; inform merchants/customers that on-chain confirmation now takes a few minutes.
- *(more will be appended as fixes land — esp. H12 API-key domain binding, orderId→token, JWT lifetime.)*
