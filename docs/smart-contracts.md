# GembaPay Smart Contracts

[Back to Documentation](README.md) | [Back to Main README](../README.md)

---

## Overview

GembaPay uses smart contracts deployed on EVM-compatible blockchains to process cryptocurrency payments. The contracts implement a non-custodial payment model where funds transfer directly from customer to merchant.

---

## Table of Contents

1. [Architecture](#architecture)
2. [PaymentGateway Contract](#paymentgateway-contract)
3. [GiftNFT Contract](#giftnft-contract)
4. [Oracle System](#oracle-system)
5. [Security Features](#security-features)
6. [Deployed Addresses](#deployed-addresses)

---

## Architecture

```
                    Payment Flow
                    
Customer Wallet ──────────────────────────────────┐
       │                                          │
       │ 1. Approve (ERC20 only)                  │
       ▼                                          │
┌─────────────────┐                               │
│ PaymentGateway  │◄─── 2. Lock Quote             │
│    Contract     │     (native tokens)           │
│                 │                               │
│  ┌───────────┐  │                               │
│  │ Chainlink │  │◄─── Get Price                 │
│  │  Oracle   │  │                               │
│  └───────────┘  │                               │
│                 │                               │
└────────┬────────┘                               │
         │                                        │
         │ 3. Process Payment                     │
         │                                        │
         ├─────────────────────────┐              │
         │                         │              │
         ▼                         ▼              │
   99% to Merchant           1% to GembaPay       │
      Wallet                   Fee Wallet         │
```

---

## PaymentGateway Contract

### Key Features

- Non-custodial payment processing
- Multi-token support (native + ERC20)
- Dual-oracle price validation
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
function processDirectStablecoinPayment(
    address token,
    uint256 amount,
    address merchant,
    string calldata orderId
) external
```

**Admin Functions**

```solidity
// Update fee percentage (basis points)
function updateFeePercentage(uint256 newFeePercentage) external onlyOwner

// Update fee collector address
function updateFeeCollector(address newFeeCollector) external onlyOwner

// Add supported token with price feed
function addSupportedToken(address token, address priceFeed) external onlyOwner

// Remove supported token
function removeSupportedToken(address token) external onlyOwner

// Emergency pause
function pause() external onlyOwner
function unpause() external onlyOwner

// Emergency withdrawal
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

event QuoteLocked(
    bytes32 indexed quoteId,
    address indexed creator,
    address token,
    uint256 tokenAmount,
    uint256 usdAmount,
    uint256 validUntil
);
```

### Fee Structure

- Fee percentage stored in basis points (100 = 1%)
- Standard fee: 100 basis points (1%)
- High-risk merchant fee: up to 1000 basis points (10%)
- VIP merchant fee: 0 basis points (0%)

```solidity
uint256 merchantAmount = totalAmount * (10000 - feePercentage) / 10000;
uint256 feeAmount = totalAmount - merchantAmount;
```

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
// Claim NFT with valid signature
function claimNFT(
    bytes32 paymentId,
    address recipient,
    bytes calldata signature
) external

// Check if payment has claimed NFT
function hasClaimed(bytes32 paymentId) external view returns (bool)

// Get total supply
function totalSupply() external view returns (uint256)

// Get remaining supply
function remainingSupply() external view returns (uint256)
```

### Signature Verification

```javascript
// Backend generates signature
const messageHash = ethers.solidityPackedKeccak256(
  ['bytes32', 'address', 'address'],
  [paymentId, recipient, nftContractAddress]
);

const signature = await signer.signMessage(ethers.getBytes(messageHash));
```

---

## Oracle System

### Chainlink Price Feeds

GembaPay uses Chainlink oracles for accurate price data:

**Token Price Feeds:**
- ETH/USD
- BNB/USD
- MATIC/USD

**Forex Feeds:**
- EUR/USD, GBP/USD, JPY/USD
- CHF/USD, AUD/USD, CAD/USD
- And 9 more major currencies

### Dual Oracle Validation

The contract validates prices from two sources:

```solidity
// Get primary oracle price
uint256 primaryPrice = getPrimaryOraclePrice(token);

// Get secondary oracle price
uint256 secondaryPrice = getSecondaryOraclePrice(token);

// Validate deviation
uint256 deviation = calculateDeviation(primaryPrice, secondaryPrice);
require(deviation <= maxPriceDeviation, "Price deviation too high");
```

### Staleness Protection

Oracle data is validated for freshness:

```solidity
(, int256 price, , uint256 updatedAt, ) = priceFeed.latestRoundData();

require(block.timestamp - updatedAt <= stalenessThreshold, "Stale price data");
```

**Staleness Thresholds:**
- Mainnet: 3600 seconds (1 hour)
- Testnet: 3600 seconds (1 hour)

---

## Security Features

### Reentrancy Protection

```solidity
import "@openzeppelin/contracts/utils/ReentrancyGuard.sol";

contract PaymentGateway is ReentrancyGuard {
    function processPayment(...) external nonReentrant {
        // Payment logic
    }
}
```

### Quote Creator Binding

Quotes are bound to the address that created them:

```solidity
struct Quote {
    address creator;
    // ...
}

function processPayment(bytes32 quoteId) external {
    require(quotes[quoteId].creator == msg.sender, "UnauthorizedQuoteUse");
}
```

### Quote Expiration

Quotes have a limited validity period:

```solidity
function lockPriceQuote(...) external returns (...) {
    uint256 validUntil = block.timestamp + quoteValidityDuration;
    // Default: 300 seconds (5 minutes)
}

function processPayment(bytes32 quoteId) external {
    require(block.timestamp <= quotes[quoteId].validUntil, "QuoteExpired");
}
```

### Quote Usage Tracking

Each quote can only be used once:

```solidity
function processPayment(bytes32 quoteId) external {
    require(!quotes[quoteId].isUsed, "QuoteAlreadyUsed");
    quotes[quoteId].isUsed = true;
}
```

### Emergency Controls

```solidity
import "@openzeppelin/contracts/utils/Pausable.sol";

contract PaymentGateway is Pausable {
    function pause() external onlyOwner {
        _pause();
    }
    
    function processPayment(...) external whenNotPaused {
        // ...
    }
}
```

---

## Deployed Addresses

See [DEPLOYMENTS.md](../DEPLOYMENTS.md) for complete contract addresses on all networks.

### Quick Reference

| Network | PaymentGateway | GiftNFT |
|---------|----------------|---------|
| Ethereum | [View](../DEPLOYMENTS.md#ethereum-mainnet) | [View](../DEPLOYMENTS.md#ethereum-mainnet) |
| BSC | [View](../DEPLOYMENTS.md#bnb-smart-chain) | [View](../DEPLOYMENTS.md#bnb-smart-chain) |
| Polygon | [View](../DEPLOYMENTS.md#polygon) | [View](../DEPLOYMENTS.md#polygon) |

---

## Technical Specifications

| Specification | Value |
|---------------|-------|
| Solidity Version | 0.8.27 |
| OpenZeppelin Version | 5.0.0 |
| Chainlink Version | 1.2.0 |
| License | MIT |

---

## Audit Status

- Static Analysis: Slither (0 high-severity findings)
- Security Testing: 100% pass rate
- Third-party Audit: Pending

See [Security Audit](security-audit.md) for detailed results.

---

## Related Documentation

- [Security Audit](security-audit.md)
- [Deployments](../DEPLOYMENTS.md)
- [Security Policy](../SECURITY.md)
