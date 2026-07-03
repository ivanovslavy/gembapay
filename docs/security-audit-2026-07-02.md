# GembaPay Security Audit — 2026-07-02 (Deep Re-Audit)

[Back to Documentation](README.md)

---

**Version:** 3.0
**Audit Date:** 2026-07-02
**Method:** 7 independent read-only code-review agents (auth/webhooks/secrets · IDOR/admin/injection ·
infra/deps/PCI · on-chain crypto rails · KYC/uploads/data-exposure · business-logic/money · frontend/Shopify/session)
**Status:** ⚠️ ACTION REQUIRED — findings below

> **This report supersedes `security-audit.md` (Version 2.0, February 2026), which reported "PASSED — Production
> Ready, 100% (82/82), 0 Critical/High/Medium."** That result reflected a checklist-style pass (SSL/TLS grade,
> Slither on the contracts, black-box "API tests passed") and did **not** include deep source review of the refund
> routes, the 2FA challenge flow, the on-chain event listeners, or the admin endpoints — where the material issues
> below actually live. No secret VALUES appear in this document.

## Overall

| Severity | Count |
|----------|-------|
| Critical | 4 |
| High     | 13 |
| Medium   | ~15 |
| Low      | ~15 (+ 2 latent, prod-mitigated) |

---

## 🔴 CRITICAL (4)

- **C1 — Unauthenticated PayPal refund.** `src/routes/paypal.routes.js:342` — `POST /api/paypal/refund` has NO
  `authMiddleware` (every other mutating route in the file has one). Refunds are issued by a body-supplied
  `captureId` with an attacker-controlled `amount`, against the merchant's PayPal account. Anyone on the internet
  can refund any capture → direct fund loss, mass griefing, `note_to_payer` injection.
  **Fix:** add `authMiddleware`; enforce `payment.merchantId === req.merchant.id`; cap `amount` to captured-minus-refunded.

- **C2 — Cross-merchant Stripe refund (IDOR).** `src/routes/stripe.routes.js:129` — authenticated but looks up the
  payment by `providerPaymentId` only, never checking ownership; `refundPayment` iterates both test AND live clients.
  Any merchant can refund any other merchant's Stripe payment.
  **Fix:** `where: { providerPaymentId, merchantId: req.merchant.id }`; validate amount; pin test/live to the payment's mode.

- **C3 — Testnet listener credits REAL mainnet orders.** `src/workers/event-listener-testnet.js:607,698` looks up
  `paymentRequest` by `orderId` with no `isTestMode` filter and writes the same `payments`/`payment_requests` tables
  (Payment has no `isTestMode` column). A payment made with free testnet tokens carrying a real `orderId` marks the
  real order paid and fires `payment.completed`.
  **Fix:** scope every listener query by the worker's mode; add `isTestMode` to Payment; ideally separate tables/DB per mode.

- **C4 — Zero-confirmation crediting.** `src/workers/event-listener.js:444` credits events up to the chain head;
  `safetyMargin` is only a backfill/gap threshold, not a confirmation delay, and no receipt/finality is checked. A
  reorg that drops the tx after crediting leaves the order paid with no funds (worst on BSC/Polygon).
  **Fix:** credit only up to `head − confirmationDepth`; re-verify `getTransactionReceipt` (status/to/amount/token).

---

## 🟠 HIGH (13)

- **H1 — Email-2FA fully bypassable.** `src/services/twofa.service.js:100-104` embeds `codeHash = sha256(6-digit)`
  (unsalted) inside the challenge JWT returned to the client (`auth.routes.js:263-270`); the hash is reversible
  offline over the 10^6 space, then submitted. Rate limits are irrelevant. (TOTP is unaffected — secret not in token.)
  **Fix:** never send the code/hash to the client — store the hashed code server-side keyed to a challenge id with an attempt counter (or HMAC with a server-only secret).
- **H2 — Cloudflare origin bypass.** The origin answers directly on `:443` (no `cf-ray`), bypassing WAF/DDoS/edge
  rate-limits. **Fix:** firewall `:443` to Cloudflare IP ranges and/or enable Authenticated Origin Pulls (mTLS).
- **H3 — Local root privilege escalation.** `gembapay-rpc-telemetry.service` runs `User=root` and executes a
  `slavy:slavy 644` script inside a `slavy`-writable tree; the internet-facing API runs as `slavy` → a `slavy`
  compromise overwrites the script → root on the next timer tick. **Fix:** `chown root:root` the script + move it out of the app tree; add `NoNewPrivileges=yes`.
- **H4 — systemd unit files owned by the service user** (`/etc/systemd/system/gembapay-*.service`, `slavy:slavy 644`)
  → the app user can rewrite `ExecStart`/`User`. **Fix:** `chown root:root` + 644.
- **H5 — Dependencies: 2 critical + 10 high** (`npm audit`): `basic-ftp`, `fast-xml-parser` (critical, transitive);
  `multer`, **`nodemailer ≤9` (CRLF header injection / SSRF)**, `path-to-regexp`, `ws`, `picomatch` (high).
  **Fix:** `npm audit fix` / bump multer, nodemailer, ethers, puppeteer in a maintenance window; retest.
- **H6 — Secret over-fetching.** Full `Merchant` rows (incl. `passwordHash`, `totpSecret`, `twoFactorBackupCodes`,
  `webhookSecret`, `apiKey`) are returned by `kyc.routes.js:293-352`, `admin.routes.js:447-593`, and the CSV export
  `admin.routes.js:1803` (`/reports/export?type=merchants` dumps every merchant's secrets). `req.merchant` also
  carries secrets (`auth.middleware.js:30`). **Fix:** Prisma `select` allowlist on every merchant read reaching a response.
- **H7 — Raw-SQL admin endpoints.** `admin.routes.js:1858` `/db/query` (`$queryRawUnsafe` + fragile denylist, reads
  any table) and `:1918` `/db/tables/:name/schema` (string-interpolated → SQL injection). **Fix:** remove from prod or run under a dedicated read-only DB role + parameterize.
- **H8 — Command injection (admin).** `admin.routes.js` `/invoices/generate-monthly` interpolates body `month` into
  `exec(... --month=${month})` → RCE as the service user. **Fix:** validate `^\d{4}-\d{2}$` / use `execFile` with an args array.
- **H9 — No on-chain amount verification.** Listeners mark orders `completed` on a matching `orderId` without
  comparing the paid amount to the requested amount → underpayment accepted. **Fix:** require paid ≥ requested (tolerance) before completing.
- **H10 — Single-RPC trust + `staticNetwork:true`.** Crediting relies on one RPC (public fallbacks) with no
  `getTransactionReceipt` cross-check and no chainId validation → a malicious/MITM RPC can fabricate a payment event.
  **Fix:** verify each event via a trusted/paid RPC (or 2-of-N quorum) with receipt + finality; validate chainId.
- **H11 — Hot-wallet private keys plaintext, used inline in the HTTP path** (`new ethers.Wallet(process.env.X)` in
  request handlers); `.env.bak-*` copies present; `backend/.gitignore` doesn't list `.env`. **Fix:** KMS/dedicated signer, gitignore `.env*`, purge backups, rotate keys.
- **H12 — API-key domain binding absent/bypassable.** The applied `apiKeyMiddleware` does no Origin/Referer check;
  the alternate check is skipped when both headers are absent (any non-browser client passes). **Fix:** enforce domain binding fail-closed in `apiKeyMiddleware`.
- **H13 — Refund state machine.** Both refund routes set `status:'refunded'` unconditionally → double-refund
  re-fires; a partial refund flips the whole payment to refunded; the platform/application fee is not reversed
  (`refund_application_fee` unset). **Fix:** gate on current status; track cumulative refunded amount; reverse the fee proportionally.

---

## 🟡 MEDIUM (~15)

30-day non-revocable JWT stored in `localStorage` (incl. `admin_token`); logout is a no-op → short access + refresh
+ server-side revocation, httpOnly cookie. · Subscription **manage-code** brute-force/replay/flood — attempt cap is
resettable, the cancel endpoint neither increments nor invalidates, no dedicated limiter, code uses `Math.random`. ·
**Subscription-cycle recording not idempotent** (findFirst-then-create, no unique on `providerPaymentId`) → double
revenue on at-least-once webhook retries. · **Recorded platform fee hardcoded 1%** (ignores custom/high-risk rate) →
under-invoicing + VAT drift. · **VAT reverse-charge granted on format-only, merchant-controlled VAT** (ignores
`vatValidated`) → VAT under-remittance. · **Invoice-number allocation race** (read-then-write; cron + manual). ·
**Upload validation trusts client MIME only** (no magic-byte / AV); signed URLs lack forced-download; admin
`documentType` unchecked → R2 path injection. · **merchant-dashboard framing open to any `*.myshopify.com`**
(X-Frame-Options unset) → clickjacking. · **No real CSP** (`server.js` `contentSecurityPolicy:false`) — XSS +
localStorage JWT = token theft. · PII (emails/IPs) logged in plaintext. · Verbose `error.message` returned to clients.
· Admin `PUT /settings` (no key allowlist), `POST /wallets` (no `ethers.isAddress`), `payment/fix` (credits any order
with an arbitrary txHash, no on-chain check). · Unauthenticated payment lookup by guessable `orderId`
(`customer.routes.js:111`) → enumeration. · CORS allows no-Origin with credentials. · Backend + Postgres bind
`0.0.0.0` (firewalled). · No systemd sandboxing. · `Payment` test/live commingled. · App-level idempotency only. ·
Unknown-token decimals default to 18.

---

## ⚪ LOW (~15) + Latent

`.env.bak-*` secret sprawl (`600 root`) · world-readable `*.bak` source · `trust proxy=1` (XFF spoof via origin) ·
HSTS absent on api/dashboard origin vhosts · JWT algorithm not pinned · subscriptions marked active before settle ·
trial-stacking / resubscribe · `/payment-request` no amount bound · KYC signed-URL 1h TTL · internal `r2://` path
returned to merchants · dead wallet-bearer route (payment-app) · unsanitized `dangerouslySetInnerHTML` (first-party
blog) · web3 nonce edge cases · app DB role holds DDL.

**Latent (mitigated in prod today — make fail-closed):**
- Hardcoded JWT fallback secret + `JWT_SECRET` optional in Joi (`auth.service.js`, `twofa.service.js`, `validation.js`).
  The TOTP-encryption key derives from `JWT_SECRET`, so this also protects the 2FA secrets.
- Webhook signature verification falls open if the secret is unset/placeholder (Stripe + PayPal). Both secrets ARE set in prod.

---

## ✅ Confirmed GOOD (keep)

PCI **SAQ-A** — no card data stored (hosted Stripe Checkout / PayPal PPCP). · Tight auth rate-limits (login 5/15m,
2FA 10/15m, register 3/hr). · Web3 nonce single-use + 5-min expiry. · Admin auth = single-use nonce +
`ethers.verifyMessage` + wallet allowlist + audit log (no merchant→admin escalation). · Webhooks mounted raw-body
before `express.json`, signature-verified when secrets are set. · **Shopify webhook HMAC verified**
(`crypto.timingSafeEqual`). · Payment-link single-use = atomic conditional `updateMany` (well-built). · Checkout
amounts read from the DB, not the client. · `customFeeRate` admin-only + validated 1–10%. · No server secrets in
client bundles. · No open redirects. · Cloudflare adds XFO/nosniff/HSTS/referrer/permissions on all vhosts. ·
Unguessable R2 object keys. · TOTP AES-256-GCM; API keys + backup codes SHA-256 hashed.

---

## Remediation priority

1. **Emergency financial:** C1, C2, H13 (refund auth/ownership/amount/state + fee reversal).
2. **Money-integrity:** C3, C4, H9, H10 (listener mode-scope, confirmation depth, amount + receipt verification).
3. **Auth:** H1 (server-side 2FA code), H12 (API-key binding), H6 (response `select` allowlists); make latent JWT/webhook fail-closed.
4. **Admin/injection:** H7, H8.
5. **Infra:** H2, H3, H4 (origin lock, root/unit perms, sandboxing), H5 (deps).
6. **Data/hardening:** uploads, framing, CSP, PII, idempotency `@unique`, VAT/fee correctness, manage-code, CORS, localhost binds, orderId→token.
