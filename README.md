# GembaPay — Payment Infrastructure for Merchants

**Version:** 3.0  
**License:** MIT  
**Status:** Production

---

## Overview

GembaPay is software-as-a-service payment infrastructure that lets merchants accept card and
PayPal payments through a single unified API. Payments are executed by Stripe and PayPal, which
are licensed payment institutions, and settle **directly into the merchant's own connected
account**. Gemba EOOD never holds, controls, or has access to merchant or customer funds.

**Website:** https://gembapay.com  
**Merchant Dashboard:** https://merchant-dashboard.gembapay.com  
**API Documentation:** https://gembapay.com/docs

---

## Table of Contents

1. [Features](#features)
2. [Architecture](#architecture)
3. [Supported Currencies](#supported-currencies)
4. [Processing Fees](#processing-fees)
5. [Payment Links](#payment-links)
6. [Subscriptions](#subscriptions)
7. [Integration Packages](#integration-packages)
8. [Documentation](#documentation)
9. [Security](#security)
10. [Regulatory Position](#regulatory-position)
11. [License](#license)

---

## Features

**Direct Settlement**

- Funds settle straight into the merchant's own Stripe or PayPal account
- No intermediate custody, no escrow, no holding period imposed by GembaPay
- The 1% platform fee is collected automatically at the time of the transaction

**Payment Methods**

- Stripe — credit and debit cards, Apple Pay, Google Pay
- PayPal — PayPal balance, linked bank, Pay Later
- Recurring subscriptions — auto-billing via the native Stripe and PayPal subscription engines

**Merchant Tools**

- Dashboard with transaction analytics
- API key management
- Webhook configuration with signed events
- Multi-currency pricing (86+ currencies)
- Real-time payment notifications
- Two-factor authentication for dashboard login (authenticator app or email code)
- Payment Links and QR codes — shareable, no-code payment pages (single-use or multi-use/donations)
- Subscriptions — recurring billing plans with hosted subscribe links and embeddable buttons

**Security**

- TLS everywhere, HSTS, strict security headers
- Signed webhooks (HMAC-SHA256)
- Scoped API keys, separate test and live credentials
- Rate limiting and abuse protection
- Failover infrastructure with PostgreSQL streaming replication

---

## Architecture

```
                              GembaPay Architecture

┌─────────────────────────────────────────────────────────────────────────────┐
│                             Merchant Integration                             │
│                                                                              │
│   ┌────────────────┐         ┌──────────────────┐                            │
│   │  Merchant      │────────►│  GembaPay API    │                            │
│   │  Website/App   │  REST   │  (Node.js)       │                            │
│   └────────────────┘  API    └────────┬─────────┘                            │
│                                       │                                      │
└───────────────────────────────────────│──────────────────────────────────────┘
                                        │
                ┌───────────────────────┼───────────────────────┐
                │                       │                       │
                ▼                       ▼                       ▼
      ┌─────────────────┐     ┌─────────────────┐     ┌─────────────────┐
      │  Database       │     │  Payment Page   │     │  Payment        │
      │  (PostgreSQL)   │     │  (React)        │     │  Providers      │
      └─────────────────┘     └────────┬────────┘     │  Stripe/PayPal  │
                                       │              └────────┬────────┘
                                       │                       │
                                       └───────────┬───────────┘
                                                   │
                                                   ▼
                                        ┌─────────────────────┐
                                        │  Merchant's own     │
                                        │  Stripe / PayPal    │
                                        │  account (payout)   │
                                        └─────────────────────┘
```

GembaPay sits between the merchant's system and the payment providers. It creates the payment,
hosts the unified checkout page, records the order, and notifies the merchant. It is never in the
path of the money itself.

---

## Supported Currencies

Merchants set prices in their own local currency. GembaPay supports **86+ currencies** for
pricing; the customer is charged in the currency supported by the chosen payment provider, using
live exchange rates at the moment of checkout.

---

## Processing Fees

| Method            | GembaPay fee | Provider fee                     |
| ----------------- | ------------ | -------------------------------- |
| Cards via Stripe  | 1% + €0.20   | charged separately by Stripe     |
| PayPal            | 1% + €0.20   | charged separately by PayPal     |

No setup fees. No monthly fees. No withdrawal fees. Customers never pay a GembaPay fee.

---

## Payment Links

Create a shareable payment link with a QR code from the dashboard — no website required.

- **Single-use** links — one customer, one payment
- **Multi-use** links — many payers on the same link, ideal for donations
- **Open amount** — let the payer choose how much to pay
- Optional expiry, customer confirmation email, and full status tracking in the dashboard

---

## Subscriptions

Merchants create subscription plans programmatically via the API using their merchant key. Each
plan gets a hosted subscribe link and an embeddable button.

Recurring billing is powered by the **native subscription engines of Stripe and PayPal**, which
auto-charge each cycle and handle retries and dunning for failed payments. GembaPay charges 1% per
billing cycle. Customers can cancel at any time from the merchant's self-service Manage page using
a 6-digit email code; cancellation is cancel-at-period-end.

---

## Integration Packages

| Package                                      | Location                    |
| -------------------------------------------- | --------------------------- |
| Node.js SDK (`gembapay-sdk`)                  | `packages/npm/`             |
| WooCommerce plugin                            | `packages/woocommerce/`     |

---

## Documentation

| Document                                    | Description                              |
| ------------------------------------------- | ---------------------------------------- |
| [`docs/README.md`](docs/README.md)          | Documentation index                      |
| [`docs/api-reference.md`](docs/api-reference.md) | REST API reference                  |
| [`docs/integration.md`](docs/integration.md) | Integration guide                       |
| [`docs/webhooks.md`](docs/webhooks.md)      | Webhook events and signature verification |
| [`docs/wordpress-plugin.md`](docs/wordpress-plugin.md) | WooCommerce plugin guide      |

Full API documentation is also published at https://gembapay.com/docs.

---

## Security

Security issues can be reported privately — see [`SECURITY.md`](SECURITY.md).

---

## Regulatory Position

GembaPay is operated by **Gemba EOOD**, a company registered in Bulgaria, European Union
(EIK: 208656371).

Gemba EOOD is **not** a payment institution, electronic money institution, or credit institution,
and does not itself execute, acquire, or settle payment transactions. All regulated payment
activity is carried out by Stripe and PayPal as licensed payment institutions, under the
merchant's own agreement with those providers. GembaPay is a software layer only.

The following merchant categories are **not supported** on GembaPay: forex/CFD trading, binary
options, cryptocurrency exchanges and crypto-to-fiat conversion services, custodial wallet
services, and token issuance platforms. See the
[Terms of Service](https://gembapay.com/terms) for the full list.

---

## License

MIT — see [`LICENSE`](LICENSE).
