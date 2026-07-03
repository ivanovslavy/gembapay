# GembaPay listeners — changes, incident & open items (2026-07-03)

[Back to Documentation](README.md) · Related: `remediation-checklist.md`, `security-remediation-report.md`

This records the crypto **event-listener** work on 2026-07-03: an incident we caused and fixed, the confirmation-delay
reversal, and what is still open. Two listeners exist and are now **1:1 in logic** (differing only by network):
`workers/event-listener.js` (MAINNET: ethereum, bsc, polygon; gemba mainnet not added yet) and
`workers/event-listener-testnet.js` (TESTNET: sepolia, bscTestnet, amoy, gembaTestnet). Both run as systemd services
(`gembapay-listener`, `listener-testnet.gembapay`), User=slavy, Restart=always → reload by SIGTERM to the MainPID.

---

## 1. INCIDENT (caused + fixed same day): B1 broke on-chain order matching → no webhooks

**Severity: CRITICAL (production).** For a window on 2026-07-03, **crypto payments for every merchant stopped
completing** — the order stayed `pending` and **no `payment.completed` webhook fired**. Card (Stripe) and PayPal were
unaffected (they match by session/metadata, not the on-chain orderId).

**Root cause — our B1 change.** B1 made the checkout URL carry the unguessable `accessToken` instead of the `orderId`
(`paymentUrl` → `/checkout/{accessToken}`). But the payment app reads the URL path segment via
`useParams().orderId` and passes **that same value** as the `orderId` string in the on-chain contract call
(`processPayment` / `processDirectPayment` / `processETHPaymentWithQuote`). So the contract emitted
`PaymentProcessed(orderId = <accessToken>)`, and the listener — which looks up `paymentRequest` by exact `orderId` —
found no match, so it never linked the payment, never completed the order, never sent the webhook. Confirmed live in
the logs: `Processing … order: 0368f3bb-…` (the token, not `TESTSHOP-…`).

**Why our earlier tests missed it.** The B1 tests validated that the `paymentUrl` carries a token and that the public
API resolver accepts token-or-orderId. They **never ran a real end-to-end on-chain payment** — the one path that
broke. Lesson recorded: **a real end-to-end payment (webhook delivered) is the only acceptable proof for
listener/payment changes.**

**Fix applied (both listeners).** At the top of each of the 3 event handlers (quote / direct / euro), normalize the
incoming on-chain id back to the canonical orderId before any lookup:

```js
// B1 HOTFIX: eventData.orderId may be the accessToken (payment app passes the URL param on-chain).
// Resolve it back to the canonical orderId so the paymentRequest lookup + webhook use the real orderId.
const _canon = await prisma.paymentRequest.findFirst({
  where: { OR: [{ orderId: eventData.orderId }, { accessToken: eventData.orderId }] },
  select: { orderId: true },
});
if (_canon && _canon.orderId !== eventData.orderId) eventData.orderId = _canon.orderId;
```

`accessToken` is `@unique`, so the match is exact. Falls through unchanged for a real orderId or a genuinely unknown
id. **Verified end-to-end on real money:** a testnet Sepolia payment AND a live $1 mainnet payment both resolved the
token → completed the order → `✅ Webhook delivered` to GembaKitchen on the first attempt.

**Cleaner long-term fix (OPEN, follow-up):** the payment app should send the **real `paymentRequest.orderId`** (from
the fetched order data) in the on-chain call, not the URL token. Then the on-chain data carries the canonical orderId
(as it always did pre-B1), and the listener normalization above can eventually be removed. Needs a payment-app
rebuild. Until then the listener hotfix fully covers it.

**Recovery of the stuck order(s):** payments made during the broken window created an on-chain event the listener has
already scanned past, so they won't auto-reprocess. Reconcile them manually (or replay the block range) — the on-chain
funds are real; only the DB link is missing.

---

## 2. Confirmation delay (C4 10-min wait) — REVERTED (owner decision)

The C4 security fix (2026-07-02) changed crediting from "up to the chain head" to "up to `currentBlock - safetyMargin`"
to avoid 0-confirmation crediting (a reorg dropping a just-credited tx). But it **reused the oversized `safetyMargin`
values** (originally a backfill/gap threshold): 50/100/150 blocks → **~10 min on Ethereum, ~5 min on BSC/Polygon**.
Those are exchange-grade depths for large deposits, wrong for a small-amount gateway.

**Decision (owner, 2026-07-03):** revert to crediting at the chain head (`confirmedHead = currentBlock`), instant, 1:1
with the testnet listener. Small-amount reorg risk is **accepted**. This does NOT reintroduce a bug beyond the
accepted tradeoff — it returns to the pre-2026-07-02 behavior that ran live for a long time.

---

## 3. Underpayment guards (H9) — now on BOTH listeners

Mainnet had H9 underpayment protection on the quote path (2026-07-02) + euro/direct (2026-07-03). To keep the two
listeners 1:1 (owner chose "add to testnet, keep protection"), the same 3 guards were added to the testnet listener.
Logic: `paid < requested × 0.90` → mark `underpaid`, do NOT complete, no webhook. **GMB discount respected** — expected
amount = `amountUsd × (1 − gmbDiscountPct)`, so a legitimately discounted GMB payment (up to 20%, GembaBlockchain only)
is accepted; direct-path stablecoins compared 1:1 to USD, unknown tokens skipped (fail-safe).

---

## 4. State now — both listeners 1:1

| Logic | mainnet | testnet |
|-------|---------|---------|
| Credit at chain head (instant, no wait) | ✅ | ✅ |
| B1 on-chain orderId hotfix (token→orderId) | ✅ (3 handlers) | ✅ (3 handlers) |
| H9 underpayment guards (quote/euro/direct) | ✅ (3) | ✅ (3) |
| Networks | eth/bsc/polygon (gemba mainnet: not yet) | sepolia/bscTestnet/amoy/gembaTestnet |

Backups of every step in `/home/slavy/gembapay-secfix-backups/2026-07-03-*` (leakfix, b1token, b1-retire-legacy,
underpay, group1, group2, b1-onchain-hotfix, mainnet-instant, testnet-underpay).

---

## 5. OPEN / TODO

1. **Payment-app clean fix** — send the real `orderId` on-chain (not the URL token); then the listener hotfix can be
   retired. Needs a payment-app rebuild + a real E2E test.
2. **Reconcile any orders stuck during the incident window** (pending order + real on-chain tx that carried the token).
3. **Amount-tiered confirmations (proper reorg model)** — replaces the reverted flat delay:
   - e.g. **< $100 → instant** (credit at head, as now); **$100–$1000 → a small confirmation depth**; **> $1000 →
     deeper depth**, tuned per chain (Polygon warrants more than eth/bsc given its deep-reorg history).
   - Pair with a **multi-phase status/webhook**: `payment.pending` (awaiting) → `payment.detected` (seen at 1 conf) →
     `payment.completed` (final). Customer gets instant feedback; large amounts still wait for finality.
   - **Big, breaking change** (new statuses + webhook event + frontend). Deferred by design — do NOT ship without a
     migration plan + merchant comms.
4. **Security remediation remainder** (from `remediation-checklist.md`): Group-2 leftovers (B2 API-key origin binding,
   PayPal trial-stacking, B12 `/db/query` read-only role) and Group-3 maintenance-window items (B7 origin lock, B10
   localhost bind, B8 full CSP, B5 RPC quorum/receipt, B6 KMS). None are money-theft vectors today.
5. **Owner action still open:** ROTATE any merchant secrets exposed by the earlier customer.routes leak
   (webhookSecret + dapp `.env`, apiKey, passwords, 2FA).
