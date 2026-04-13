// SPDX-License-Identifier: MIT
pragma solidity ^0.8.27;

import "@openzeppelin/contracts/token/ERC20/IERC20.sol";
import "@openzeppelin/contracts/token/ERC20/utils/SafeERC20.sol";
import "@openzeppelin/contracts/access/Ownable.sol";
import "@openzeppelin/contracts/utils/ReentrancyGuard.sol";
import "@openzeppelin/contracts/utils/Pausable.sol";

/**
 * @title GembaPayEuro — EUR Stablecoin Payment Protocol
 * @author Slavcho Ivanov — Gemba EOOD (https://gembapay.com)
 * @notice Non-custodial payment gateway for EUR-pegged stablecoins on EVM chains
 *
 * @dev Core Design:
 * - EUR-only: Supports EURC (Circle) and any future EUR-pegged ERC20 stablecoin
 * - Hardcoded 1:1 peg — 1 token = 1 EUR, no oracles, no price feeds
 * - No native token (ETH/BNB/MATIC) support — stablecoins only
 * - No quote system — direct payment flow: Approve → Pay
 * - Amounts expressed in EUR cents (e.g., 10000 = €100.00)
 *
 * @dev Architecture:
 * - Tokens added manually by owner (address whitelist)
 * - All supported tokens treated as 1:1 EUR — no conversion
 * - Same fee system as GembaPay: basis points, per-merchant custom fees (incl. 0)
 * - Non-custodial: direct splits on-chain (merchant + feeCollector)
 *
 * @dev Security:
 * - OpenZeppelin ReentrancyGuard, Ownable, Pausable
 * - CEI pattern (Checks-Effects-Interactions)
 * - SafeERC20 for all token transfers
 * - Double-payment prevention via usedOrders mapping
 * - Emergency withdraw (only when paused)
 *
 * @dev Payment Flow:
 *   1. Frontend calculates eurAmount (cents)
 *   2. Customer approves contract for tokenAmount
 *   3. Customer calls processPayment(token, amount, merchant, orderId)
 *   4. Contract splits: merchantAmount + feeAmount → direct transfers
 *
 * @dev Multi-chain deployment targets:
 *   - Ethereum Mainnet  — EURC: 0x1aBaEA1f7C830bD89Acc67eC4af516284b1bC33c
 *   - Base              — EURC: 0x60a3E35Cc302bFA44Cb288Bc5a4F316Fdb1adb42
 *   - Polygon PoS       — EURC: 0x08210F9170F89Ab7658F0B5E3fF39b0E03C594D4
 *   - BSC               — No official EURC; add when available
 *
 * @custom:website  https://gembapay.com
 * @custom:github   https://github.com/ivanovslavy/gembapay
 * @custom:security https://gembapay.com/contact
 */

contract GembaPayEuro is Ownable, ReentrancyGuard, Pausable {
    using SafeERC20 for IERC20;

// ============ State Variables ============

    /// @notice Protocol fee in basis points (e.g., 50 = 0.5%)
    uint256 public feePercentage;

    /// @notice Address that collects protocol fees
    address public feeCollector;

    /// @notice Whitelisted EUR stablecoins (e.g. EURC)
    /// @dev All supported tokens are treated as 1 token = 1 EUR exactly
    mapping(address => bool) public supportedTokens;

    /// @notice Per-merchant custom fee in basis points (0 = use global feePercentage)
    /// @dev Set to special sentinel BASIS_POINTS + 1 to indicate "explicitly 0 fee"
    /// @dev Use setMerchantFee(merchant, 0) for zero-fee merchants
    mapping(address => uint256) public customMerchantFee;

    /// @notice Tracks whether a merchant has a custom fee set (including 0)
    mapping(address => bool) public hasMerchantFee;

    /// @notice Prevention of double-payment per orderId
    mapping(bytes32 => bool) public usedOrders;

    /// @notice Monotonic payment counter
    uint256 private paymentCounter;

// ============ Constants ============

    uint256 public constant BASIS_POINTS = 10_000;

// ============ Events ============

    /**
     * @notice Emitted for every successful EUR stablecoin payment
     * @param paymentId     Monotonic payment identifier
     * @param merchant      Recipient of funds (minus fee)
     * @param customer      Payer (msg.sender)
     * @param token         EUR stablecoin address used
     * @param totalAmount   Total token amount transferred (with decimals)
     * @param merchantAmount Amount received by merchant
     * @param feeAmount     Amount received by feeCollector
     * @param eurCents      EUR value in cents (e.g. 10000 = €100.00)
     * @param orderId       Merchant-supplied order identifier
     * @param blockNumber   Block at time of payment
     */
    event PaymentProcessed(
        uint256 indexed paymentId,
        address indexed merchant,
        address indexed customer,
        address token,
        uint256 totalAmount,
        uint256 merchantAmount,
        uint256 feeAmount,
        uint256 eurCents,
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

// ============ Errors ============

    error InvalidAmount();
    error InvalidAddress();
    error InvalidFeePercentage();
    error TokenNotSupported();
    error InsufficientAllowance();
    error OrderAlreadyPaid();

// ============ Constructor ============

    /**
     * @notice Deploy GembaPayEuro
     * @param initialOwner     Contract owner address
     * @param _feeCollector    Protocol fee recipient
     * @param _feePercentage   Initial fee in basis points (e.g. 50 = 0.5%)
     */
    constructor(
        address initialOwner,
        address _feeCollector,
        uint256 _feePercentage
    ) Ownable(initialOwner) {
        if (_feeCollector == address(0)) revert InvalidAddress();
        if (_feePercentage > BASIS_POINTS) revert InvalidFeePercentage();

        feeCollector = _feeCollector;
        feePercentage = _feePercentage;

        emit FeeCollectorUpdated(address(0), _feeCollector);
        emit FeeUpdated(0, _feePercentage);
    }

// ============ Payment Function ============

    /**
     * @notice Process a direct EUR stablecoin payment
     * @param token     EUR stablecoin address (must be whitelisted)
     * @param amount    Token amount to pay (with token decimals, e.g. 100e6 for €100 EURC)
     * @param merchant  Merchant wallet address
     * @param orderId   Unique order identifier from merchant system
     * @return paymentId Monotonic payment identifier
     *
     * @dev Caller must approve this contract for `amount` tokens before calling.
     * @dev 1 token = 1 EUR — no oracle, no price conversion.
     * @dev Each orderId is single-use. Re-use reverts with OrderAlreadyPaid.
     * @dev Fee is deducted from `amount` and sent directly to feeCollector.
     * @dev If merchant has fee = 0, full `amount` goes to merchant.
     */
    function processPayment(
        address token,
        uint256 amount,
        address merchant,
        string calldata orderId
    ) external nonReentrant whenNotPaused returns (uint256 paymentId) {

        // ============ CHECKS ============

        bytes32 orderHash = keccak256(abi.encodePacked(orderId));
        if (usedOrders[orderHash]) revert OrderAlreadyPaid();
        if (!supportedTokens[token]) revert TokenNotSupported();
        if (amount == 0) revert InvalidAmount();
        if (merchant == address(0)) revert InvalidAddress();

        IERC20 tokenContract = IERC20(token);
        if (tokenContract.allowance(msg.sender, address(this)) < amount) {
            revert InsufficientAllowance();
        }

        uint256 feeAmount = _calculateFee(amount, merchant);
        uint256 merchantAmount = amount - feeAmount;

        // EUR cents derived directly from token amount
        // For 6-decimal tokens (EURC): amount / 1e4 = cents
        // For 18-decimal tokens: amount / 1e16 = cents
        // Frontend passes the correct `amount` already; eurCents is informational in the event.
        uint256 eurCents = _toEurCents(token, amount);

        // ============ EFFECTS — CEI Pattern ============

        usedOrders[orderHash] = true;
        paymentId = ++paymentCounter;

        emit PaymentProcessed(
            paymentId,
            merchant,
            msg.sender,
            token,
            amount,
            merchantAmount,
            feeAmount,
            eurCents,
            orderId,
            block.number
        );

        // ============ INTERACTIONS ============

        tokenContract.safeTransferFrom(msg.sender, merchant, merchantAmount);
        if (feeAmount > 0) {
            tokenContract.safeTransferFrom(msg.sender, feeCollector, feeAmount);
        }

        return paymentId;
    }

// ============ Internal Helpers ============

    /**
     * @notice Calculate protocol fee for a payment, respecting per-merchant overrides
     * @param amount    Total token amount
     * @param merchant  Merchant address
     * @return Fee amount in token units
     */
    function _calculateFee(uint256 amount, address merchant) internal view returns (uint256) {
        uint256 effectiveFee = getEffectiveFee(merchant);
        return (amount * effectiveFee) / BASIS_POINTS;
    }

    /**
     * @notice Convert token amount to EUR cents for event logging
     * @dev Assumes 1 token = 1 EUR. Handles 6-decimal (EURC) and 18-decimal tokens.
     * @param token  Token address
     * @param amount Raw token amount
     * @return EUR amount in cents
     */
    function _toEurCents(address token, uint256 amount) internal view returns (uint256) {
        uint8 dec = IERC20Metadata(token).decimals();
        // Normalize to cents: multiply by 100, divide by 10^decimals
        if (dec >= 2) {
            return (amount * 100) / (10 ** dec);
        }
        // Edge case: tokens with < 2 decimals (unlikely for EURC)
        return amount * (10 ** (2 - dec));
    }

// ============ View Functions ============

    /**
     * @notice Get the effective fee for a given merchant
     * @param merchant  Merchant address
     * @return Fee in basis points
     */
    function getEffectiveFee(address merchant) public view returns (uint256) {
        if (hasMerchantFee[merchant]) {
            return customMerchantFee[merchant]; // can be 0
        }
        return feePercentage;
    }

    /**
     * @notice Check whether an orderId has already been paid
     * @param orderId  Order string from merchant system
     * @return True if paid
     */
    function isOrderPaid(string calldata orderId) external view returns (bool) {
        return usedOrders[keccak256(abi.encodePacked(orderId))];
    }

    /**
     * @notice Preview the split for a given amount and merchant
     * @param amount    Token amount (with decimals)
     * @param merchant  Merchant address
     * @return merchantAmount  Amount merchant receives
     * @return feeAmount       Amount feeCollector receives
     */
    function previewPayment(
        uint256 amount,
        address merchant
    ) external view returns (uint256 merchantAmount, uint256 feeAmount) {
        feeAmount = _calculateFee(amount, merchant);
        merchantAmount = amount - feeAmount;
    }

// ============ Admin — Token Management ============

    /**
     * @notice Add a EUR stablecoin to the supported list
     * @param token  ERC20 token address (e.g. EURC)
     * @dev Only owner. No price feed needed — all tokens are 1:1 EUR.
     */
    function addSupportedToken(address token) external onlyOwner {
        if (token == address(0)) revert InvalidAddress();
        supportedTokens[token] = true;
        emit TokenAdded(token);
    }

    /**
     * @notice Remove a EUR stablecoin from the supported list
     * @param token  Token address to remove
     */
    function removeSupportedToken(address token) external onlyOwner {
        supportedTokens[token] = false;
        emit TokenRemoved(token);
    }

// ============ Admin — Fee Management ============

    /**
     * @notice Update the global protocol fee
     * @param newFee  Fee in basis points (max BASIS_POINTS = 100%)
     */
    function updateFeePercentage(uint256 newFee) external onlyOwner {
        if (newFee > BASIS_POINTS) revert InvalidFeePercentage();
        uint256 old = feePercentage;
        feePercentage = newFee;
        emit FeeUpdated(old, newFee);
    }

    /**
     * @notice Update the fee collector address
     * @param newCollector  New recipient of protocol fees
     */
    function updateFeeCollector(address newCollector) external onlyOwner {
        if (newCollector == address(0)) revert InvalidAddress();
        address old = feeCollector;
        feeCollector = newCollector;
        emit FeeCollectorUpdated(old, newCollector);
    }

    /**
     * @notice Set a custom fee for a specific merchant (including 0 for free)
     * @param merchant  Merchant wallet address
     * @param feeBps    Fee in basis points (0 = no fee for this merchant)
     * @dev Max allowed custom fee is BASIS_POINTS (100%). Use 0 for whitelisted/free merchants.
     */
    function setMerchantFee(address merchant, uint256 feeBps) external onlyOwner {
        if (merchant == address(0)) revert InvalidAddress();
        if (feeBps > BASIS_POINTS) revert InvalidFeePercentage();

        customMerchantFee[merchant] = feeBps;
        hasMerchantFee[merchant] = true;

        emit MerchantFeeSet(merchant, feeBps);
    }

    /**
     * @notice Remove custom fee for a merchant (reverts to global feePercentage)
     * @param merchant  Merchant wallet address
     */
    function removeMerchantFee(address merchant) external onlyOwner {
        delete customMerchantFee[merchant];
        delete hasMerchantFee[merchant];
        emit MerchantFeeRemoved(merchant);
    }

// ============ Admin — Emergency ============

    /// @notice Pause all payments
    function pause() external onlyOwner {
        _pause();
    }

    /// @notice Resume payments
    function unpause() external onlyOwner {
        _unpause();
    }

    /**
     * @notice Emergency withdrawal of stuck ERC20 tokens (only when paused)
     * @param token   Token address to withdraw
     * @param amount  Amount to withdraw
     * @param to      Recipient address
     * @dev This contract never holds funds in normal operation.
     *      Use only to recover accidentally sent tokens.
     */
    function emergencyWithdraw(
        address token,
        uint256 amount,
        address to
    ) external onlyOwner whenPaused {
        if (to == address(0)) revert InvalidAddress();
        if (amount == 0) revert InvalidAmount();

        emit EmergencyWithdraw(token, amount, to);
        IERC20(token).safeTransfer(to, amount);
    }

// ============ Safety — Reject ETH ============

    /// @dev Rejects all direct ETH transfers. All payments are ERC20 only.
    // slither-disable-next-line locked-ether
    receive() external payable {
        revert("GembaPayEuro: ETH not accepted");
    }

    // slither-disable-next-line locked-ether
    fallback() external payable {
        revert("GembaPayEuro: ETH not accepted");
    }
}

// ============ Interface ============

/**
 * @notice Minimal ERC20 metadata interface for decimals()
 */
interface IERC20Metadata is IERC20 {
    function decimals() external view returns (uint8);
}
