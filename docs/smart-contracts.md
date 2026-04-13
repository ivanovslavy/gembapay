# GembaPay Smart Contracts

[Back to Documentation](README.md) | [Back to Main README](../README.md)

---

## Overview

GembaPay operates two complementary smart contract protocols on EVM-compatible blockchains:

| Protocol | Contract | Currency | Oracles | Native Token |
|----------|----------|----------|---------|--------------|
| GembaPay | `Gemba.sol` | USD | Chainlink | ✅ ETH/BNB/MATIC |
| GembaPayEuro | `GembaPayEuro.sol` | EUR | None | ❌ ERC20 only |

Both implement a non-custodial model — funds transfer directly from customer to merchant with no intermediary custody.

---

## Table of Contents

1. [GembaPayEuro Contract](#gembapayeuro-contract)
2. [PaymentGateway Contract (USD)](#paymentgateway-contract)
3. [GiftNFT Contract](#giftnft-contract)
4. [Oracle System](#oracle-system)
5. [Security Features](#security-features)
6. [Deployed Addresses](#deployed-addresses)

---

## GembaPayEuro Contract

### Overview

EUR-only stablecoin payment protocol with hardcoded 1:1 peg. Designed for the European market using Circle's EURC and compatible EUR stablecoins. No oracles, no native token support, no quote system — direct ERC20 payments only.

```
                    EUR Payment Flow

Customer Wallet
       │
       │ 1. approve(gateway, amount)
       ▼
┌─────────────────────┐
│   GembaPayEuro      │
│                     │
│  1 EURC = 1 EUR     │◄── No oracle needed
│  (hardcoded peg)    │
│                     │
└──────────┬──────────┘
           │
           │ 2. processPayment()
           │
           ├──────────────────────────┐
           │                          │
           ▼                          ▼
   99.5% to Merchant           0.5% to GembaPay
      Wallet                     Fee Wallet
```

### Key Features

- EUR-only: EURC (Circle) and any EUR-pegged ERC20
- Hardcoded 1:1 peg — no oracle dependency
- No native token (ETH/BNB/MATIC) support
- No quote system — single `processPayment()` call
- Per-merchant custom fees including 0% (VIP)
- Multi-token: multiple EUR stablecoins supported simultaneously
- Double-payment prevention via `usedOrders` mapping
- Emergency pause + withdraw (owner only, paused state)

### Payment Flow

```
1. Frontend calculates eurAmount (in token units, e.g. 100e6 for €100 EURC)
2. Customer: approve(gatewayAddress, amount)
3. Customer: processPayment(token, amount, merchant, orderId)
4. Contract splits on-chain: merchantAmount → merchant, feeAmount → feeCollector
```

### Contract Functions

**Payment**

```solidity
// Process direct EUR stablecoin payment — single entry point
function processPayment(
    address token,      // EURC or any supported EUR stablecoin
    uint256 amount,     // Token amount (e.g. 100e6 for €100 with 6 decimals)
    address merchant,   // Merchant wallet
    string calldata orderId  // Unique order ID from merchant system
) external returns (uint256 paymentId)
```

**View Functions**

```solidity
// Preview payment split before sending
function previewPayment(uint256 amount, address merchant)
    external view returns (uint256 merchantAmount, uint256 feeAmount)

// Check if orderId has been paid (double-payment protection)
function isOrderPaid(string calldata orderId) external view returns (bool)

// Get effective fee for a merchant (custom or global)
function getEffectiveFee(address merchant) public view returns (uint256)
```

**Admin Functions**

```solidity
// Token management
function addSupportedToken(address token) external onlyOwner
function removeSupportedToken(address token) external onlyOwner

// Fee management
function updateFeePercentage(uint256 newFee) external onlyOwner
function updateFeeCollector(address newCollector) external onlyOwner
function setMerchantFee(address merchant, uint256 feeBps) external onlyOwner
function removeMerchantFee(address merchant) external onlyOwner

// Emergency
function pause() external onlyOwner
function unpause() external onlyOwner
function emergencyWithdraw(address token, uint256 amount, address to) external onlyOwner whenPaused
```

### Events

```solidity
event PaymentProcessed(
    uint256 indexed paymentId,
    address indexed merchant,
    address indexed customer,
    address token,
    uint256 totalAmount,
    uint256 merchantAmount,
    uint256 feeAmount,
    uint256 eurCents,       // Informational: amount in EUR cents
    string orderId,
    uint256 blockNumber
);

event TokenAdded(address indexed token);
event TokenRemoved(address indexed token);
event FeeUpdated(uint256 oldFee, uint256 newFee);
event FeeCollectorUpdated(address indexed oldCollector, address indexed newCollector);
event MerchantFeeSet(address indexed merchant, uint256 feeBps);
event MerchantFeeRemoved(address indexed merchant);
event EmergencyWithdraw(address indexed token, uint256 amount, address indexed to);
```

### Fee Structure

```solidity
// Global fee applies to all merchants without custom fee
uint256 public feePercentage; // e.g. 50 = 0.5%

// Per-merchant override — can be 0 for VIP merchants
mapping(address => uint256) public customMerchantFee;
mapping(address => bool)    public hasMerchantFee; // distinguishes "not set" from "set to 0"

// Effective fee calculation
function getEffectiveFee(address merchant) public view returns (uint256) {
    if (hasMerchantFee[merchant]) return customMerchantFee[merchant];
    return feePercentage;
}
```

### Supported EUR Stablecoins

| Token | Network | Address |
|-------|---------|---------|
| EURC (Circle) | Ethereum | `0x1aBaEA1f7C830bD89Acc67eC4af516284b1bC33c` |
| EURC (Circle) | Polygon | `0x08210F9170F89Ab7658F0B5E3fF39b0E03C594D4` |
| EURC (Circle) | Base | `0x60a3E35Cc302bFA44Cb288Bc5a4F316Fdb1adb42` |

### Technical Specifications

| Specification | Value |
|---------------|-------|
| Solidity | 0.8.27 |
| OpenZeppelin | 5.x |
| License | MIT |
| Oracles | None |
| Audit | Slither 0 findings, Sepolia live tested |

---

## PaymentGateway Contract

### Key Features

- Non-custodial payment processing
- Multi-token support (native + ERC20)
- Dual-oracle price validation (Chainlink primary + secondary)
- Quote-based payment locking
- Configurable fee structure
- Emergency pause functionality

### Contract Functions

**User Functions**

```solidity
// Get price quote without locking
function getPriceQuote(address token, uint256 usdAmount)
    external view
    returns (uint256 tokenAmount, uint256 tokenPriceUSD, uint256 validityDuration)

// Lock a price quote for native token payment
function lockPriceQuote(address token, uint256 usdAmount)
    external
    returns (bytes32 quoteId, uint256 tokenAmount, uint256 validUntil)

// Process payment with locked quote (native tokens)
function processETHPaymentWithQuote(
    bytes32 quoteId,
    address merchant,
    string calldata orderId
) external payable

// Process payment with locked quote (ERC20 tokens)
function processTokenPaymentWithQuote(
    bytes32 quoteId,
    address merchant,
    string calldata orderId
) external

// Direct stablecoin payment (no quote needed)
function processDirectPayment(
    address token,
    uint256 amount,
    address merchant,
    string calldata orderId
) external
```

**Admin Functions**

```solidity
function updateFeePercentage(uint256 newFeePercentage) external onlyOwner
function updateFeeCollector(address newFeeCollector) external onlyOwner
function addSupportedToken(address token, address priceFeed) external onlyOwner
function removeSupportedToken(address token) external onlyOwner
function pause() external onlyOwner
function unpause() external onlyOwner
function emergencyWithdraw(address token, uint256 amount, address to) external onlyOwner
```

### Events

```solidity
event PaymentProcessed(
    uint256 indexed paymentId,
    bytes32 indexed quoteId,
    address indexed merchant,
    address customer,
    address token,
    uint256 totalAmount,
    uint256 merchantAmount,
    uint256 feeAmount,
    uint256 usdAmount,
    string orderId,
    uint256 blockNumber
);

event DirectPaymentProcessed(
    uint256 indexed paymentId,
    address indexed merchant,
    address indexed customer,
    address token,
    uint256 amount,
    uint256 merchantAmount,
    uint256 feeAmount,
    string orderId,
    uint256 blockNumber
);
```

### Fee Structure

- Fee percentage stored in basis points (100 = 1%)
- Standard fee: 100 basis points (1%)
- High-risk merchant fee: up to 1000 basis points (10%)
- VIP merchant fee: 0 basis points (0%)

---

## GiftNFT Contract

### Overview

The GiftNFT contract allows customers to claim a free commemorative NFT after completing a crypto payment. NFTs are minted using a signature-based system to verify payment completion.

### Features

- ERC-721 compliant NFT
- Signature-based minting (gasless for platform)
- Limited supply per network (10,000)
- One NFT per payment
- Metadata stored on IPFS

### Functions

```solidity
function claimNFT(bytes32 paymentId, address recipient, bytes calldata signature) external
function hasClaimed(bytes32 paymentId) external view returns (bool)
function totalSupply() external view returns (uint256)
function remainingSupply() external view returns (uint256)
```

---

## Oracle System

> Applies to GembaPay (USD) only. GembaPayEuro has no oracle dependency.

### Chainlink Price Feeds

GembaPay uses Chainlink oracles for accurate price data:

**Token Price Feeds:** ETH/USD, BNB/USD, MATIC/USD

**Forex Feeds:** EUR/USD, GBP/USD, JPY/USD, CHF/USD, AUD/USD, CAD/USD

### Dual Oracle Validation

```solidity
uint256 primaryPrice   = getPrimaryOraclePrice(token);
uint256 secondaryPrice = getSecondaryOraclePrice(token);
uint256 deviation      = calculateDeviation(primaryPrice, secondaryPrice);
require(deviation <= maxPriceDeviation, "Price deviation too high");
```

### Staleness Protection

```solidity
(, int256 price, , uint256 updatedAt, ) = priceFeed.latestRoundData();
require(block.timestamp - updatedAt <= stalenessThreshold, "Stale price data");
```

**Thresholds:** Mainnet 3600s, Testnet 86400s

---

## Security Features

Both contracts implement:

- **ReentrancyGuard** — OpenZeppelin nonReentrant modifier
- **Ownable** — admin functions restricted to owner
- **Pausable** — emergency stop for all payments
- **SafeERC20** — safe token transfer wrappers
- **CEI Pattern** — Checks-Effects-Interactions on all state changes
- **usedOrders mapping** — prevents double-payment per orderId

GembaPay (USD) additionally implements:

- **Quote creator binding** — quotes locked to creator address
- **Quote expiration** — block-based validity window
- **Dual oracle validation** — deviation check between primary/secondary feeds

### Security Audit Results

| Contract | Slither | Mythril | Live Tests |
|----------|---------|---------|------------|
| Gemba.sol | 0 high/medium | 0 findings | ✅ |
| GembaPayEuro.sol | 0 findings | N/A (viaIR) | ✅ Sepolia 8/8 |

---

## Deployed Addresses

See [DEPLOYMENTS.md](../DEPLOYMENTS.md) for all contract addresses.

### Quick Reference

| Network | GembaPayEuro | GembaPay |
|---------|-------------|---------|
| Ethereum Mainnet | Pending | [View](../DEPLOYMENTS.md#ethereum-mainnet-chain-id-1) |
| BSC Mainnet | Pending | [View](../DEPLOYMENTS.md#bnb-smart-chain-chain-id-56) |
| Polygon Mainnet | Pending | [View](../DEPLOYMENTS.md#polygon-chain-id-137) |
| Sepolia | [View](../DEPLOYMENTS.md#testnet-deployments) | [View](../DEPLOYMENTS.md#testnet-deployments) |
| Amoy | [View](../DEPLOYMENTS.md#testnet-deployments) | — |
| BSC Testnet | [View](../DEPLOYMENTS.md#testnet-deployments) | — |

---

## Technical Specifications

| Specification | GembaPayEuro | GembaPay |
|---------------|-------------|---------|
| Solidity | 0.8.27 | 0.8.27 |
| OpenZeppelin | 5.x | 5.x |
| Chainlink | — | 1.2.0 |
| License | MIT | MIT |
| viaIR | Required | Required |

---

## Audit Status

- Static Analysis: Slither (0 findings on both contracts)
- Live Testing: Sepolia testnet (8/8 tests passing)
- Third-party Audit: Pending

See [Security Audit](security-audit.md) for detailed results.

---

## Related Documentation

- [Security Audit](security-audit.md)
- [Deployments](../DEPLOYMENTS.md)
- [Security Policy](../SECURITY.md)
