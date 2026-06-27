# GembaPay Contract Deployments

This document contains the deployed contract addresses for GembaPay on all supported networks.

[Back to README](README.md)

---

## GembaPayEuro — EUR Stablecoin Protocol

> Non-custodial payment gateway for EUR-pegged stablecoins (EURC and compatible).
> Hardcoded 1:1 EUR peg — no oracles, no native token support, direct ERC20 payments only.

### Testnet Deployments

| Network | Contract | Address | Verified |
|---------|----------|---------|----------|
| Sepolia (11155111) | GembaPayEuro | `0x2AAa51F2a8Fe3604E1248215fE576b2826Eb1031` | [Etherscan](https://sepolia.etherscan.io/address/0x2AAa51F2a8Fe3604E1248215fE576b2826Eb1031) |
| Sepolia (11155111) | MockEURC | `0x2FCb50B9cA1cf336a287C1b9987720FC541260B4` | [Etherscan](https://sepolia.etherscan.io/address/0x2FCb50B9cA1cf336a287C1b9987720FC541260B4) |
| Amoy (80002) | GembaPayEuro | `0xF24F3f2c9054d6aCc679805889b5119e0555F862` | [PolygonScan](https://amoy.polygonscan.com/address/0xF24F3f2c9054d6aCc679805889b5119e0555F862) |
| Amoy (80002) | MockEURC | `0xAFc00eBB7C15176c9cE5D0e7f0917dd6A71FD109` | [PolygonScan](https://amoy.polygonscan.com/address/0xAFc00eBB7C15176c9cE5D0e7f0917dd6A71FD109) |
| BSC Testnet (97) | GembaPayEuro | `0x13F8382187c69248f580CA547a87317DD352e467` | [BscScan](https://testnet.bscscan.com/address/0x13F8382187c69248f580CA547a87317DD352e467) |
| BSC Testnet (97) | MockEURC | `0x01764F3A69f554778638964Fdf3c74A598f746D5` | [BscScan](https://testnet.bscscan.com/address/0x01764F3A69f554778638964Fdf3c74A598f746D5) |

**Deployment Configuration:**
- Fee Collector: `0x45c56da734b9bf124ca4447dbfaafe6cf6e29c53`
- Fee: 50 bps (0.5%)
- Deployed: April 2026

### Mainnet Deployments

> Live since 2026-06-27. EURC is supported on **Ethereum and Polygon only** (Circle does not issue EURC on BSC). Source-verified on Sourcify (exact match).

| Network | Contract | Address | EURC token |
|---------|----------|---------|------------|
| Ethereum (1) | GembaPayEuro | `0x8E55746Bb16bb5893C3997b80fF2E6A42C1D1cAb` | `0x1aBaEA1f7C830bD89Acc67eC4af516284b1bC33c` |
| Polygon (137) | GembaPayEuro | `0x5AA9ae8CDd4277c1b60BA4cF047De5F7A7B931d9` | `0x08210F9170F89Ab7658F0B5E3fF39b0E03C594D4` |

- Owner: `0xc45112B334822811f4418e2f13C2C80FF790C949` · Fee Collector: `0x8eB8Bf106EbC9834a2586D04F73866C7436Ce298` · Fee: 100 bps (1%)
- 1 token = 1 EUR (no oracle); each `orderId` single-use. EURC whitelisted on both gateways.
- Verify: [Sourcify ETH](https://sourcify.dev/#/lookup/0x8E55746Bb16bb5893C3997b80fF2E6A42C1D1cAb) · [Sourcify POL](https://sourcify.dev/#/lookup/0x5AA9ae8CDd4277c1b60BA4cF047De5F7A7B931d9)

> EURC is **not** available on BSC — Circle does not issue it there — so GembaPayEuro is intentionally not deployed on BSC.

---

## GembaPay — Multi-Currency Protocol (USD)

> Non-custodial payment gateway supporting native tokens (ETH/BNB/MATIC) and USD stablecoins (USDC/USDT) with Chainlink oracle pricing.

### Mainnet Deployments

#### Ethereum Mainnet (Chain ID: 1)

| Contract | Address | Verified |
|----------|---------|----------|
| PaymentGateway | `0xD9c4169061B92970b86afBF32dad4Ecfd749179e` | [Etherscan](https://etherscan.io/address/0xD9c4169061B92970b86afBF32dad4Ecfd749179e) |
| GiftNFT | `0xD24a89dc1686C2F88d33A70250473495459C564a` | [Etherscan](https://etherscan.io/address/0xD24a89dc1686C2F88d33A70250473495459C564a) |

**Supported Tokens:**

| Token | Address |
|-------|---------|
| USDC | `0xA0b86991c6218b36c1d19D4a2e9Eb0cE3606eB48` |
| USDT | `0xdAC17F958D2ee523a2206206994597C13D831ec7` |

**Chainlink Oracles:**

| Feed | Address |
|------|---------|
| ETH/USD | `0x5f4eC3Df9cbd43714FE2740f5E3616155c5b8419` |
| EUR/USD | `0xb49f677943BC038e9857d61E7d053CaA2C1734C1` |

---

#### BNB Smart Chain (Chain ID: 56)

| Contract | Address | Verified |
|----------|---------|----------|
| PaymentGateway | `0xeE3d1CbD3cAF2D9194CbfC5B1bE8fdD5c3953eE1` | [BscScan](https://bscscan.com/address/0xeE3d1CbD3cAF2D9194CbfC5B1bE8fdD5c3953eE1) |
| GiftNFT | `0x8Fee75865E8D87cdB844Ef5676D2D6456262BA7A` | [BscScan](https://bscscan.com/address/0x8Fee75865E8D87cdB844Ef5676D2D6456262BA7A) |

**Supported Tokens:**

| Token | Address |
|-------|---------|
| USDC | `0x8AC76a51cc950d9822D68b83fE1Ad97B32Cd580d` |
| USDT | `0x55d398326f99059fF775485246999027B3197955` |

**Chainlink Oracles:**

| Feed | Address |
|------|---------|
| BNB/USD | `0x0567F2323251f0Aab15c8dFb1967E4e8A7D42aeE` |
| EUR/USD | `0x0bf79F617988C472DcA68ff41eFe1338955b9A80` |

---

#### Polygon (Chain ID: 137)

| Contract | Address | Verified |
|----------|---------|----------|
| PaymentGateway | `0x7cceCb66E7Fa6255244035533E31791bD1Fff254` | [PolygonScan](https://polygonscan.com/address/0x7cceCb66E7Fa6255244035533E31791bD1Fff254) |
| GiftNFT | `0xD24a89dc1686C2F88d33A70250473495459C564a` | [PolygonScan](https://polygonscan.com/address/0xD24a89dc1686C2F88d33A70250473495459C564a) |

**Supported Tokens:**

| Token | Address |
|-------|---------|
| USDC | `0x3c499c542cEF5E3811e1192ce70d8cC03d5c3359` |
| USDT | `0xc2132D05D31c914a87C6611C10748AEb04B58e8F` |

**Chainlink Oracles:**

| Feed | Address |
|------|---------|
| POL/USD | `0xAB594600376Ec9fD91F8e885dADF0CE036862dE0` |
| EUR/USD | `0x73366Fe0AA0Ded304479862808e02506FE556a98` |

---

## Contract Configuration

### GembaPayEuro

| Parameter | Value |
|-----------|-------|
| Standard Fee | 50 bps (0.5%) |
| VIP Fee | 0 bps (0%) |
| Custom Merchant Fee | Configurable per merchant |
| Peg | 1 token = 1 EUR (hardcoded) |
| Oracles | None |
| Native Token | Not supported |

### GembaPay (USD)

| Parameter | Value |
|-----------|-------|
| Standard Fee | 100 bps (1%) |
| High-Risk Fee | Up to 1000 bps (10%) |
| VIP Fee | 0 bps (0%) |
| Oracle Staleness | 3600 seconds (1 hour) |
| Price Deviation | 5% max |
| Quote Validity | 300 seconds (5 minutes) |
| Minimum Payment | $1 USD equivalent |

---

## Verification

All contracts are verified and open source. Source code available in the [contracts](contracts/) directory.

```bash
# Clone repository
git clone https://github.com/ivanovslavy/gembapay.git
cd gembapay

# Verify GembaPayEuro on Sepolia
npx hardhat verify --network sepolia 0x2AAa51F2a8Fe3604E1248215fE576b2826Eb1031 \
  "0x8eB8Bf106EbC9834a2586D04F73866C7436Ce298" \
  "0x45c56da734b9bf124ca4447dbfaafe6cf6e29c53" \
  50
```

---

## Related Documentation

- [README](README.md) - Project overview
- [Smart Contracts](docs/smart-contracts.md) - Contract architecture
- [Security Audit](docs/security-audit.md) - Security analysis
