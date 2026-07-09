# Mainnet crypto payments suspended pending CASP/MiCA licensing (2026-07-09)

[Back to Documentation](README.md) · Related: [DEPLOYMENTS.md](../DEPLOYMENTS.md), [Smart Contracts](smart-contracts.md)

**Effective 2026-07-09**, GembaPay is temporarily suspending its cryptocurrency payment service for merchants and
their customers. The mainnet `PaymentGateway` / `GembaPayEuro` protocols on **Ethereum, BNB Smart Chain, and
Polygon** are paused while the CASP (Crypto-Asset Service Provider) / MiCA (Markets in Crypto-Assets) licensing
procedure is in progress.

## What this means

- Crypto checkout (ETH/BNB/POL native tokens and USDC/USDT/EURC stablecoins) is **not available** on mainnet for
  merchants or customers for the duration of the licensing process.
- Card payments (Stripe) and PayPal are **unaffected** and continue to operate normally.
- The mainnet blockchain event listener (`gembapay-listener`, watching Ethereum/BSC/Polygon) is stopped for the
  duration of the suspension.
- No changes have been made to the deployed mainnet contracts themselves — see [DEPLOYMENTS.md](../DEPLOYMENTS.md)
  for the current addresses. The protocols are paused operationally (payment intake disabled), not decommissioned.

## What is NOT affected — testnets remain fully operational

**Testnets continue to work exactly as before.** Sepolia, BSC Testnet, and Polygon Amoy (plus the Gemba testnet)
are unaffected by this suspension, and the testnet listener (`listener-testnet.gembapay`) keeps running normally.

Developers can keep building against GembaPay without interruption:
- Integrate and test full crypto checkout flows end-to-end on testnets.
- Continue to `git commit` and `git push` against this repository as usual.
- Existing testnet integration guides ([Integration Guide](integration.md), [API Reference](api-reference.md)) remain
  accurate and unaffected.

## Status

This suspension will be lifted once the CASP/MiCA license is granted. This document will be updated when mainnet
crypto payments resume.
