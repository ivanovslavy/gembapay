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
- [ ] H1 email-2FA bypass · [ ] H2 CF origin-lock · [x] **H3 root priv-esc** (rpc-telemetry script → root:root 644) ·
  [x] **H4 unit-file perms** (all `gembapay-*.service` → root:root 644; daemon-reload; services stayed active) · [ ] H5 deps ·
  [ ] H6 secret over-fetch · [ ] H7 raw-SQL admin · [ ] H8 cmd-injection · [ ] H9 amount-verify · [ ] H10 RPC-verify ·
  [ ] H11 hot-wallet keys · [ ] H12 API-key domain binding · [ ] H13 refund state (double/partial/fee-reversal).

## 🟡 MEDIUM
- [ ] 30-day JWT/revocation · [ ] manage-code brute/flood · [ ] subscription-cycle idempotency · [ ] fee-hardcoded-1% ·
  [ ] VAT reverse-charge · [ ] invoice-number race · [ ] upload magic-byte/AV · [ ] dashboard clickjacking-scope ·
  [ ] real CSP · [ ] PII logs · [ ] verbose errors · [ ] admin PUT/settings + wallets + payment/fix · [ ] orderId→token ·
  [ ] CORS no-Origin · [ ] 0.0.0.0→localhost · [ ] Payment isTestMode · [ ] idempotency @unique · [ ] token decimals.

## ⚪ LOW / latent
- [ ] `.env.bak-*` sprawl · [ ] world-readable `*.bak` · [ ] trust proxy · [ ] HSTS origin · [ ] JWT alg pin ·
  [ ] subs active-before-settle · [ ] trial-stacking · [ ] `/payment-request` amount bound · [ ] KYC signed-URL TTL ·
  [ ] `r2://` leak · [ ] dead wallet-bearer route · [ ] blog `dangerouslySetInnerHTML` · [ ] web3 nonce · [ ] DB DDL ·
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
