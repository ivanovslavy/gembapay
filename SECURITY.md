# Security Policy

[Back to README](README.md)

---

## Reporting Vulnerabilities

If you discover a security vulnerability in GembaPay, please report it responsibly.

**Contact:** https://gembapay.com/contact

**Guidelines:**
- Do not disclose vulnerabilities publicly until they have been addressed
- Provide detailed information to help us reproduce and fix the issue
- Allow reasonable time for us to investigate and patch the vulnerability

---

## Smart Contract Security

### Audits and Analysis

**Slither Static Analysis**
- Tool: Slither v0.10.x
- Result: Zero high-severity findings
- Full report: [docs/security-audit.md](docs/security-audit.md)

### Security Features

**Reentrancy Protection**
- OpenZeppelin ReentrancyGuard implementation
- All external calls follow checks-effects-interactions pattern

**Access Control**
- Ownable pattern for administrative functions
- Merchant whitelist system
- Function-level access restrictions

**Oracle Security**
- Dual-oracle price validation
- Staleness threshold: 3600 seconds (1 hour)
- Price deviation check: 5% maximum between oracles
- Fallback mechanisms for oracle failures

**Payment Security**
- Quote expiration enforcement (5 minutes)
- Minimum payment thresholds
- Amount validation before processing
- Quote-based locking for native token payments

**Emergency Controls**
- Pause functionality for emergency situations
- Owner-only pause/unpause capability
- Graceful handling of paused state

---

## API Security

**Authentication**
- JWT tokens for session management
- API key authentication for merchant requests
- Separate authentication for admin functions
- Optional two-factor authentication (2FA) for merchant dashboard login — authenticator app (TOTP) or 6-digit email code, with one-time backup codes; TOTP secrets are encrypted at rest

**Data Protection**
- TLS/HTTPS encryption for all traffic
- Password hashing using bcrypt
- Sensitive data encryption at rest

**Request Security**
- Rate limiting on all endpoints
- CORS policy enforcement
- Input validation and sanitization
- SQL injection prevention (Prisma ORM)

**Webhook Security**
- Signature verification for incoming webhooks
- Replay attack prevention
- Timeout handling

---

## Infrastructure Security

**Server Security**
- Regular security updates
- Firewall configuration
- DDoS protection (Cloudflare)
- Access logging and monitoring

**Database Security**
- Encrypted connections
- Regular backups
- Access control lists
- Query parameterization

---

## Known Limitations

**Blockchain Risks**
- Transaction finality depends on network confirmation times
- Gas price fluctuations may affect transaction costs
- Network congestion may delay transactions

**Oracle Risks**
- Price feed delays in extreme market conditions
- Potential for oracle manipulation (mitigated by dual-oracle validation)

**Smart Contract Risks**
- Immutable code after deployment
- Upgrade path requires contract migration

---

## Security Best Practices for Merchants

**Account Security**
- Enable two-factor authentication (2FA) on your dashboard account — authenticator app or email code
- Store your 2FA backup codes somewhere safe (each can be used once)
- Use a strong, unique password for your dashboard login

**API Key Management**
- Store API keys securely (environment variables, secrets manager)
- Rotate API keys periodically
- Use separate keys for development and production

**Webhook Verification**
- Always verify webhook signatures
- Implement idempotency for webhook handlers
- Log all webhook events

**Transaction Verification**
- Verify transaction status via API before fulfilling orders
- Do not rely solely on webhook notifications
- Implement timeout handling for pending transactions

---

## Compliance

**Data Protection**
- GDPR compliant data handling
- 5-year data retention for AML compliance
- User data deletion upon request (where legally permitted)

**KYC/AML**
- Mandatory KYC verification for all merchants
- Transaction monitoring for suspicious activity
- Sanctions screening

---

## Related Documentation

- [README](README.md) - Project overview
- [Security Audit](docs/security-audit.md) - Detailed audit report
- [Smart Contracts](docs/smart-contracts.md) - Contract documentation
