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

## API Security

**Authentication**
- JWT tokens for session management
- API key authentication for merchant requests, with separate test and live credentials
- Separate authentication for admin functions
- Optional two-factor authentication (2FA) for merchant dashboard login — authenticator app (TOTP) or 6-digit email code, with one-time backup codes; TOTP secrets are encrypted at rest

**Data Protection**
- TLS/HTTPS encryption for all traffic
- Password hashing using bcrypt
- Sensitive data encryption at rest
- Card details are never seen or stored by GembaPay — they are handled exclusively by our PCI-DSS certified payment processors

**Request Security**
- Rate limiting on all endpoints
- CORS policy enforcement
- Input validation and sanitization
- SQL injection prevention (Prisma ORM)

**Webhook Security**
- HMAC-SHA256 signature verification on every outgoing event
- Replay attack prevention
- Timeout handling and bounded retries

**Payment Link Integrity**
- Single-use links are atomically reserved for one payer while a checkout is in progress, so they cannot be paid twice — a concurrent checkout is rejected until the short reservation expires (it expires automatically if the payer does not complete)
- Duplicate-payment detection: the first completed payment claims a single-use link; any later payment is flagged as a refundable overpayment, excluded from the link's usage total, and the merchant is notified
- Multi-use links enforce their usage-count and total-amount limits

---

## Payment Handling

GembaPay never holds, controls, or has access to merchant or customer funds. Every payment is
executed by Stripe or PayPal — licensed payment institutions — and settles directly into the
merchant's own connected account. This means GembaPay cannot freeze, seize, or redirect a payment,
and a compromise of GembaPay cannot move merchant funds.

Refunds, reversals, and chargebacks are handled under the rules of the merchant's payment
provider.

---

## Infrastructure Security

**Server Security**
- Regular security updates
- Firewall configuration
- DDoS protection (Cloudflare)
- Access logging and monitoring

**Database Security**
- Encrypted connections
- Regular backups and streaming replication to a failover host
- Access control lists
- Query parameterization

---

## Known Limitations

**Third-Party Dependencies**
- Payment availability depends on the uptime of Stripe and PayPal
- Provider-side risk checks may decline an otherwise valid payment
- Changes to provider terms or APIs may require merchant-side updates

**Operational**
- Webhook delivery is retried, but merchants should treat the payment status endpoint as the
  authoritative source of truth
- Exchange rates for multi-currency pricing are taken at the moment of checkout and may move
  between quote and settlement
