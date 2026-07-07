# GembaPay - Non-Custodial Payment Infrastructure

**Version:** 3.0  
**License:** MIT  
**Status:** Production (January 2026)

---

## Overview

GembaPay is a software-as-a-service (SaaS) payment infrastructure that enables merchants to accept cryptocurrency and fiat payments through a unified API. The system operates on a non-custodial model where all funds transfer directly from customer to merchant without intermediary custody.

**Website:** https://gembapay.com  
**Merchant Dashboard:** https://merchant-dashboard.gembapay.com

---

## Table of Contents

1. [Features](#features)
2. [Architecture](#architecture)
3. [Supported Networks](#supported-networks)
4. [Supported Tokens](#supported-tokens)
5. [Supported Currencies](#supported-currencies)
6. [Processing Fees](#processing-fees)
7. [Payment Links](#payment-links)
8. [Subscriptions](#subscriptions)
9. [Documentation](#documentation)
10. [Deployed Contracts](#deployed-contracts)
11. [Security](#security)
12. [License](#license)

---

## Features

**Non-Custodial Architecture**
- Direct peer-to-peer fund transfers via smart contracts
- No intermediate custody or escrow
- Merchants receive 99% of funds immediately to their wallet
- 1% processing fee automatically deducted on-chain

**Multi-Chain Support**
- Ethereum Mainnet
- BNB Smart Chain (BSC)
- Polygon Network

**Payment Methods**
- Cryptocurrency: ETH, BNB, POL, USDC, USDT
- Fiat: Stripe (Credit/Debit Cards, Apple Pay, Google Pay)
- Fiat: PayPal (Balance, Bank, Pay Later)
- Recurring subscriptions: auto-billing via Stripe and PayPal native subscriptions (no crypto subscriptions)

**Price Oracle Integration**
- Chainlink price feeds for native token valuations
- Chainlink forex feeds for 15 major fiat currencies
- API fallback for 86+ additional currencies
- Dual-oracle validation with deviation thresholds

**Merchant Tools**
- Dashboard with transaction analytics
- API key management
- Webhook configuration
- Multi-currency pricing (86+ fiat currencies)
- Real-time payment notifications
- Two-factor authentication for dashboard login (authenticator app or email code)
- Payment Links and QR codes — shareable, no-code payment pages (single-use or multi-use/donations)
- Subscriptions — recurring billing plans with hosted subscribe links and embeddable buttons (Stripe & PayPal; 1% per cycle)

**Security**
- Reentrancy protection (OpenZeppelin ReentrancyGuard)
- Access control (Ownable pattern)
- Emergency pause functionality
- Quote expiration enforcement
- Slither security analysis with zero high-severity findings

---

## Architecture

```
                                    GembaPay Architecture
                                    
┌─────────────────────────────────────────────────────────────────────────────┐
│                              Merchant Integration                            │
│                                                                             │
│   ┌────────────────┐         ┌──────────────────┐                          │
│   │  Merchant      │────────►│  GembaPay API    │                          │
│   │  Website/App   │  REST   │  (Node.js)       │                          │
│   └────────────────┘  API    └────────┬─────────┘                          │
│                                       │                                     │
└───────────────────────────────────────│─────────────────────────────────────┘
                                        │
          ┌─────────────────────────────┼─────────────────────────────┐
          │                             │                             │
          ▼                             ▼                             ▼
┌─────────────────┐          ┌─────────────────┐          ┌─────────────────┐
│  Database       │          │  Payment Page   │          │  Fiat Payments  │
│  (PostgreSQL)   │          │  (React)        │          │  Stripe/PayPal  │
└─────────────────┘          └────────┬────────┘          └─────────────────┘
                                      │
          ┌───────────────────────────┼───────────────────────────┐
          │                           │                           │
          ▼                           ▼                           ▼
┌─────────────────┐          ┌─────────────────┐          ┌─────────────────┐
│  Smart Contract │          │  Chainlink      │          │  NFT Gift       │
│  (Multi-chain)  │          │  Oracles        │          │  Contract       │
└────────┬────────┘          └─────────────────┘          └─────────────────┘
         │
         ▼
┌─────────────────┐
│  Customer       │
│  Wallet         │
└─────────────────┘
```

**Fund Flow (Crypto)**
```
Customer Wallet ──► Smart Contract ──► 99% Merchant Wallet
                                   └──► 1% GembaPay Fee Wallet
```

**Fund Flow (Fiat)**
```
Customer ──► Stripe/PayPal ──► 99% Merchant Account (automatic split)
                           └──► 1% GembaPay Account
```

---

## Supported Networks

| Network | Chain ID | Status |
|---------|----------|--------|
| Ethereum Mainnet | 1 | Production |
| BNB Smart Chain | 56 | Production |
| Polygon | 137 | Production |

---

## Supported Tokens

| Token | Networks | Type |
|-------|----------|------|
| ETH | Ethereum | Native |
| BNB | BSC | Native |
| POL | Polygon | Native |
| USDC | All Networks | Stablecoin |
| USDT | All Networks | Stablecoin |

**Stablecoin Advantage:** Direct transfers without quote-locking result in approximately 60% lower gas costs compared to native token payments.

---

## Supported Currencies

GembaPay supports 86+ fiat currencies for merchant pricing. Exchange rates are provided by Chainlink on-chain oracles (15 major currencies) and reliable API feeds (remaining currencies).

**Chainlink Oracle Currencies:**
USD, EUR, GBP, JPY, CHF, AUD, CAD, CNY, KRW, SGD, INR, BRL, TRY, ZAR, NZD, MXN

**API-Supported Currencies:**
All remaining world currencies including: AED, ARS, CLP, COP, CZK, DKK, EGP, HKD, HUF, IDR, ILS, KES, MYR, NGN, NOK, PEN, PHP, PKR, PLN, RON, RSD, RUB, SAR, SEK, THB, TWD, UAH, VND, and 50+ more.

---

## Processing Fees

| Payment Method | GembaPay Fee | Provider Fee | Total |
|----------------|--------------|--------------|-------|
| Crypto (ETH, BNB, POL) | 1% | Gas only | 1% + gas |
| Crypto (USDC, USDT) | 1% | Gas only | 1% + gas |
| Stripe | 1% | 2.9% + $0.30 | ~4% |
| PayPal | 1% | 2.9% + $0.30 | ~4% |

**Customer Fees:** Customers pay no GembaPay fees. All processing fees are deducted from the merchant's received amount.

**High-Risk Merchants:** Custom fee rates up to 10% may apply. Contact us before registration.

---

## Payment Links

Accept payments without a website or store. Create a Payment Link in the merchant dashboard, then share the link or its QR code — the customer opens it, pays with any method you have enabled (crypto, card, or PayPal), and funds settle directly to you (non-custodial). No code and no API key are required.

**Two modes:**
- **Single-use** — a one-off link for a specific product or service; closes automatically after it is paid.
- **Multi-use** — a reusable link/QR that many people can pay, ideal for donations; with optional limits on the number of uses and the total amount collected.

**Per-link settings:**
- Amount and currency (86+ supported) — set a fixed amount, or leave it empty so the payer chooses how much to pay ("pay what you want", ideal for donations)
- Description
- Which payment methods to offer (from the ones you have enabled)
- Validity period (expiry)
- Configurable customer fields — Name, Email, Phone, Note, each Off / Optional / Required, or collect nothing (e.g. for donations)
- Test or Live mode (each uses its own API keys; Live requires approved KYC)
- Email notifications to the customer and, optionally, to you on every payment
- Status and usage log (see when and how many times a link was paid)

Each link is hosted at `https://payment.gembapay.com/link/<token>`. See the [API Reference](docs/api-reference.md#payment-links) and the [Integration Guide](docs/integration.md#payment-links-no-code).

---

## Subscriptions

Charge customers automatically on a recurring schedule. Merchants create subscription **Plans** programmatically via the API (name, price in EUR, billing interval, accepted methods); each plan gets a hosted subscribe link and an embeddable button to add to the merchant's own website. A customer enters their email, pays once, and an auto-recurring subscription begins.

Recurring billing is powered by the **native subscription engines of Stripe and PayPal**, which auto-charge each cycle and handle retries and dunning. **Crypto subscriptions are not supported** — a wallet cannot be auto-charged without on-chain authorization for each charge.

- **Plans** — name, price (EUR), billing interval (weekly / monthly / yearly), accepted methods (`stripe`, `paypal`), optional trial days. Create as many tiers as you like (e.g. Basic / Pro / Ultimate).
- **Subscribe link + button** — each plan has a GembaPay-hosted subscribe page plus an embeddable button for the merchant's site.
- **Upgrades / downgrades** — upgrades take effect immediately (Stripe prorates the difference for the current cycle, then bills the full new price next renewal; PayPal cancels and replaces the plan with a catch-up charge). Downgrades take effect at the next renewal, with no refund for the current period.
- **Cancellation** — customers cancel anytime via the merchant's self-service Manage page: they enter their email, receive a 6-digit code by email, see their subscriptions with that merchant, and cancel. Cancellation is **cancel-at-period-end** (active until the paid period ends, then stops; no refund), and is **merchant-scoped** (the Manage link carries the merchant's token).
- **Fee** — GembaPay charges **1% per billing cycle**, collected automatically via the Stripe application fee or PayPal platform fee. EUR base currency.
- **Records & webhooks** — each paid cycle is recorded in the merchant's Transactions and fires the merchant's `payment.completed` webhook.

See the [API Reference](docs/api-reference.md#subscriptions) and the [Integration Guide](docs/integration.md#subscriptions-recurring-billing).

---

## Documentation

| Document | Description |
|----------|-------------|
| [API Reference](docs/api-reference.md) | REST API endpoints and authentication |
| [Integration Guide](docs/integration.md) | Step-by-step integration instructions |
| [WordPress Plugin](docs/wordpress-plugin.md) | WooCommerce installation and configuration |
| [Smart Contracts](docs/smart-contracts.md) | Contract architecture and functions |
| [Security Audit](docs/security-audit.md) | Slither analysis and security measures |
| [Deployments](DEPLOYMENTS.md) | Mainnet contract addresses |

---

## Deployed Contracts

See [DEPLOYMENTS.md](DEPLOYMENTS.md) for complete contract addresses.

**Quick Reference:**

| Network | PaymentGateway | GiftNFT |
|---------|----------------|---------|
| Ethereum | `0xD9c4169061B92970b86afBF32dad4Ecfd749179e` | `0xD24a89dc1686C2F88d33A70250473495459C564a` |
| BSC | `0xeE3d1CbD3cAF2D9194CbfC5B1bE8fdD5c3953eE1` | `0x8Fee75865E8D87cdB844Ef5676D2D6456262BA7A` |
| Polygon | `0x7cceCb66E7Fa6255244035533E31791bD1Fff254` | `0xD24a89dc1686C2F88d33A70250473495459C564a` |

All contracts are verified on their respective block explorers.

---

## Security

**Smart Contract Security**
- OpenZeppelin ReentrancyGuard for reentrancy protection
- Ownable pattern for access control
- Emergency pause functionality
- Oracle staleness validation (1-hour maximum)
- Quote expiration enforcement
- Slither static analysis: Zero high-severity findings

**API Security**
- JWT authentication with configurable expiration
- API key authentication for merchant requests
- Two-factor authentication (2FA) for dashboard login — authenticator app (TOTP) or email code, with one-time backup codes
- Password hashing (bcrypt)
- TLS/HTTPS encryption
- Rate limiting
- SQL injection prevention (Prisma ORM)
- CORS policy enforcement

**Reporting Vulnerabilities**

If you discover a security vulnerability, please report it through our contact form at https://gembapay.com/contact. Do not disclose vulnerabilities publicly until they have been addressed.

See [SECURITY.md](SECURITY.md) for our security policy.

---

## Integration

**Quick Start**

1. Register at https://merchant-dashboard.gembapay.com
2. Complete KYC verification
3. Generate API key from dashboard
4. Integrate using REST API, WordPress plugin, or JavaScript widget

**API Example**

```bash
curl -X POST https://api.gembapay.com/api/merchant/payment-request \
  -H "Authorization: Bearer YOUR_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "amount": 100.00,
    "currency": "EUR",
    "orderId": "ORDER-12345",
    "description": "Product purchase"
  }'
```

**Response:**
```json
{
  "success": true,
  "orderId": "ORDER-12345",
  "paymentUrl": "https://payment.gembapay.com/checkout/3f9c1e7a-2b4d-4c8e-9f10-a2b3c4d5e6f7",
  "amountUsd": "108.70",
  "amountOriginal": 100.00,
  "currencyOriginal": "EUR",
  "exchangeRate": 1.087,
  "allowedMethods": ["crypto", "stripe", "paypal"],
  "expiresAt": "2026-01-25T12:00:00.000Z"
}
```

See [Integration Guide](docs/integration.md) for complete documentation.

---

## Links

| Resource | URL |
|----------|-----|
| Website | https://gembapay.com |
| Merchant Dashboard | https://merchant-dashboard.gembapay.com |
| API Documentation | https://gembapay.com/docs |
| Terms of Service | https://gembapay.com/terms |
| Privacy Policy | https://gembapay.com/privacy |
| Contact | https://gembapay.com/contact |

---

## License

MIT License

Copyright (c) 2025-2026 Gemba EOOD

Permission is hereby granted, free of charge, to any person obtaining a copy of this software and associated documentation files (the "Software"), to deal in the Software without restriction, including without limitation the rights to use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies of the Software, and to permit persons to whom the Software is furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.

---

**Gemba EOOD**  
Bulgaria, European Union  
https://gembapay.com
