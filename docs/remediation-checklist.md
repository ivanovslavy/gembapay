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
  [ ] H6 secret over-fetch · [x] **H7 raw-SQL admin** (schema route SQLi → parameterized `$queryRaw`; `/db/query`
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
  [ ] VAT reverse-charge · [ ] invoice-number race · [x] **upload magic-byte** (both KYC upload handlers now sniff
  the real file signature — jpeg/png/webp/pdf — not the spoofable client mimetype) · [ ] dashboard clickjacking-scope ·
  [ ] real CSP · [x] **PII logs** (central `requestLogger` IP masked → `.0`; real email addresses in
  invoice-generator + contact logs masked → `j***@domain`; other "email" log hits were message-text, not PII) ·
  [x] **verbose errors** (central `errorHandler` generic-5xx + **61 direct catch-block 500s**
  genericized in admin/auth/kyc/merchant; paypal/stripe deferred to "together" — a generic 500 could hide a payment
  decline reason) · [x] **admin POST /wallets + PUT /settings DONE**
  (payment/fix txHash-check DEFERRED — touches crediting) · [ ] orderId→token (BREAKING — with H6/H12) ·
  [x] **CORS** (disallowed origin → clean block not 500; no-Origin allow kept so mobile/server clients aren't broken) ·
  [ ] 0.0.0.0→localhost · [ ] Payment isTestMode · [ ] idempotency @unique · [ ] token decimals.

**Deferred MEDIUM that touch payments/login/dashboard (do together, per owner):** payment/fix on-chain check,
subscription-cycle `@unique` (billing + migration), fee-hardcoded-1% + VAT + invoice-race (invoicing/amounts),
`Payment.isTestMode` column (migration), token-decimals (crypto crediting), real CSP (dashboard rendering),
clickjacking-scope (Shopify embed), orderId→token (BREAKING checkout), 0.0.0.0→localhost (bind/restart).

## ⚪ LOW / latent
- [~] `.env.bak-*` sprawl (already `600 root` — not exposed; owner may purge for retention) ·
  [x] **world-readable `*.bak`** (all source backups → `600 root`) · [~] trust proxy (login-adjacent → together) ·
  [x] **HSTS** (verified: Cloudflare sets `max-age=15552000; includeSubDomains`; app `hsts:false` correct by design) ·
  [x] **JWT alg pin** (verify pins `algorithms: ['HS256']` in auth.service + twofa.service; **owner-tested: login + 2FA + dashboard all work**) ·
  [~] subs active-before-settle (payment → together) · [ ] trial-stacking (subscription) · [~] `/payment-request` amount
  bound (payment → together) · [x] **KYC signed-URL TTL** (3600→900s, 15 min) ·
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
