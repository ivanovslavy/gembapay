# GembaPay for Shopify

**Unified payment gateway for crypto, cards, and PayPal in Shopify.**

Accept ETH, BNB, POL, USDC, USDT, credit cards (via Stripe), Apple Pay, Google Pay, and PayPal through a single checkout. Non-custodial crypto payments — funds go directly to your wallet via smart contracts.

[![Shopify](https://img.shields.io/badge/Shopify-App-96BF48)](https://apps.shopify.com)
[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](https://opensource.org/licenses/MIT)

---

## Features

- **Unified checkout** — Crypto + Cards + PayPal in one payment page
- **Non-custodial crypto** — Smart contract payments direct to your wallet
- **86+ currencies** — Support customers worldwide
- **Automatic order updates** — Webhooks keep Shopify orders in sync
- **Test mode** — Develop with testnets before going live
- **Shopify App Bridge** — Native embedded admin experience

## Installation

### From Shopify App Store

1. Visit the [GembaPay app listing](https://apps.shopify.com) on Shopify App Store
2. Click "Add app" and authorize
3. Enter your GembaPay API key in the app settings
4. Enable GembaPay payments

### For Development

```bash
# Clone the repository
git clone https://github.com/ivanovslavy/gembapay.git
cd gembapay/shopify-app

# Install dependencies
npm install

# Start development server
npm run dev
```

## Configuration

1. Open the GembaPay app in your Shopify admin
2. Enter your **API Key** from [Merchant Dashboard](https://merchant-dashboard.gembapay.com)
3. Enter your **Webhook Secret**
4. Check "Enable GembaPay payments"
5. Click Save

### Webhook URL

Set this in your GembaPay Merchant Dashboard:

```
https://your-app-url.com/api/webhook
```

## Test Mode

Use your test API key (`gembapay_test_...`) for development:

| Method | Test Environment |
|--------|-----------------|
| Crypto | Sepolia, BSC Testnet, Polygon Amoy |
| Stripe | Test card `4242 4242 4242 4242` |
| PayPal | Sandbox accounts |

## Payment Flow

1. Customer adds items to cart and proceeds to checkout
2. Customer selects "Pay with GembaPay" 
3. Customer is redirected to GembaPay's unified checkout page
4. Customer chooses: Crypto, Card, or PayPal
5. Payment is processed
6. GembaPay sends webhook → Shopify order is marked as paid
7. Customer returns to your store's confirmation page

## Fee Structure

| Method | Fee |
|--------|-----|
| Crypto (ETH, BNB, POL, USDC, USDT) | 1% |
| Stripe (Cards, Apple Pay, Google Pay) | 1% + €0.20 + Stripe fees |
| PayPal (Balance, Bank, Pay Later) | 1% + €0.20 + PayPal fees |

## Project Structure

```
gembapay-shopify/
├── shopify.app.toml          # Shopify app configuration
├── package.json
├── app/
│   ├── routes/
│   │   ├── app._index.jsx    # Admin settings page
│   │   ├── api.create-payment.js   # Payment creation
│   │   └── api.webhook.js    # Webhook handler
│   └── lib/
│       └── gembapay.server.js # GembaPay API client
├── extensions/
│   └── gembapay-payments/    # Shopify checkout extension
│       ├── shopify.extension.toml
│       └── src/
└── README.md
```

## Requirements

- Node.js >= 18.0.0
- Shopify Partner account
- GembaPay merchant account with approved KYC

## Deployment

```bash
# Deploy to Shopify
npm run deploy
```

For production hosting, deploy the app to a hosting provider (Fly.io, Railway, Vercel, etc.) and update the `application_url` in `shopify.app.toml`.

## Links

- [Documentation](https://gembapay.com/docs)
- [Merchant Dashboard](https://merchant-dashboard.gembapay.com)
- [Integration Guide](https://gembapay.com/integration)
- [GitHub](https://github.com/ivanovslavy/gembapay)
- [npm Package](https://www.npmjs.com/package/gembapay)

## Support

- Email: contacts@gembapay.com
- GitHub Issues: [github.com/ivanovslavy/gembapay/issues](https://github.com/ivanovslavy/gembapay/issues)

## License

[MIT](LICENSE) © GEMBA EOOD
