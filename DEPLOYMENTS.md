# GembaPay Contract Deployments

This document contains the deployed contract addresses for GembaPay on all supported networks.

[Back to README](README.md)

---

## Mainnet Deployments

### Ethereum Mainnet (Chain ID: 1)

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

### BNB Smart Chain (Chain ID: 56)

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

### Polygon (Chain ID: 137)

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

**Fee Structure:**
- Standard Fee: 1% (100 basis points)
- High-Risk Fee: Up to 10% (configurable per merchant)
- VIP Fee: 0% (special merchants)

**Oracle Settings:**
- Staleness Threshold: 3600 seconds (1 hour)
- Price Deviation Threshold: 5%

**Quote Settings:**
- Quote Validity: 300 seconds (5 minutes)
- Minimum Payment: $1 USD equivalent

---

## Verification

All contracts are verified and open source. Source code is available in the [contracts](contracts/) directory.

To verify contract source code matches deployment:

```bash
# Clone repository
git clone https://github.com/ivanovslavy/gembapay.git
cd GembaPay

# Install dependencies
cd contracts
npm install

# Verify on Etherscan (example)
npx hardhat verify --network mainnet CONTRACT_ADDRESS
```

---

## Related Documentation

- [README](README.md) - Project overview
- [Smart Contracts](docs/smart-contracts.md) - Contract architecture
- [Security Audit](docs/security-audit.md) - Security analysis
