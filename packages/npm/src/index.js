'use strict';

const https = require('https');
const crypto = require('crypto');

const BASE_URL = 'https://api.gembapay.com';
const VERSION = '1.4.0';
const SIGNATURE_TOLERANCE_SEC = 300;

class GembaPayError extends Error {
  constructor(message, statusCode, code) {
    super(message);
    this.name = 'GembaPayError';
    this.statusCode = statusCode;
    this.code = code;
  }
}

class GembaPay {
  /**
   * Initialize GembaPay client
   * @param {Object} options
   * @param {string} options.apiKey - Your API key (gembapay_test_... or gembapay_live_...)
   * @param {string} [options.webhookSecret] - Webhook signing secret for signature verification
   * @param {string} [options.baseUrl] - Custom base URL (default: https://api.gembapay.com)
   * @param {number} [options.timeout] - Request timeout in ms (default: 30000)
   */
  constructor({ apiKey, webhookSecret, baseUrl, timeout } = {}) {
    if (!apiKey) {
      throw new GembaPayError('API key is required. Get yours at https://merchant.gembapay.com', null, 'missing_api_key');
    }

    this.apiKey = apiKey;
    this.webhookSecret = webhookSecret || null;
    this.baseUrl = (baseUrl || BASE_URL).replace(/\/$/, '');
    this.timeout = timeout || 30000;
    this.isTestMode = apiKey.startsWith('gembapay_test_');
  }

  // ── Payments ─────────────────────────────────────────

  /**
   * Create a payment request
   * @param {Object} params
   * @param {string} params.orderId - Your unique order identifier
   * @param {number} params.amount - Payment amount
   * @param {string} [params.currency='USD'] - Currency code (51+ supported)
   * @param {string} [params.description] - Payment description
   * @returns {Promise<Object>} Payment request with paymentUrl
   */
  async createPayment({ orderId, amount, currency = 'USD', description } = {}) {
    if (!orderId) throw new GembaPayError('orderId is required', null, 'missing_param');
    if (!amount || amount <= 0) throw new GembaPayError('amount must be greater than 0', null, 'invalid_param');

    const body = { orderId, amount, currency };
    if (description) body.description = description;

    return this._request('POST', '/api/merchant/payment-request', body);
  }

  /**
   * Get full order details for one of YOUR orders (authenticated, ownership-scoped).
   * Includes customerEmail + metadata. Returns 404 for orders that aren't yours.
   * (The public /api/customer/payment endpoint is for the checkout page and omits customer PII.)
   * @param {string} orderId - Order identifier
   * @returns {Promise<Object>} The order (orderId, status, amountUsd, customerEmail, metadata, payment, …)
   */
  async getPayment(orderId) {
    if (!orderId) throw new GembaPayError('orderId is required', null, 'missing_param');
    const res = await this._request('GET', `/api/merchant/payment/${encodeURIComponent(orderId)}`);
    return res.order || res;
  }

  /**
   * Check payment status for one of YOUR orders (authenticated, ownership-scoped).
   * @param {string} orderId - Order identifier
   * @returns {Promise<Object>} Payment status ({ status, orderId, amountUsd, network, completedAt, isTestMode })
   */
  async getPaymentStatus(orderId) {
    if (!orderId) throw new GembaPayError('orderId is required', null, 'missing_param');
    return this._request('GET', `/api/merchant/payment-status/${encodeURIComponent(orderId)}`);
  }

  // ── Merchant ─────────────────────────────────────────

  /**
   * List merchant transactions
   * @param {Object} [params] - Query parameters
   * @returns {Promise<Object>} Transaction list
   */
  async listTransactions(params = {}) {
    const query = new URLSearchParams(params).toString();
    const path = '/api/merchant/transactions' + (query ? `?${query}` : '');
    return this._request('GET', path);
  }

  /**
   * Get merchant statistics
   * @returns {Promise<Object>} Payment statistics
   */
  async getStats() {
    return this._request('GET', '/api/merchant/stats');
  }

  // ── Webhooks ─────────────────────────────────────────

  /**
   * Verify a webhook's Signature-V2 header (recommended).
   * Header: "t=<unix>,v1=<hex>[,v1=<hex>]", v1 = HMAC-SHA256(secret, "<t>.<raw body>").
   * Rejects timestamps more than `toleranceSec` away from now (replay protection) and accepts any v1
   * (two are sent for 24 h after a secret rotation).
   * @param {string|Buffer} rawBody - Raw request body, exactly as received
   * @param {string} header - Value of the X-GembaPay-Signature-V2 header
   * @param {Object} [options]
   * @param {number} [options.toleranceSec=300]
   * @returns {boolean}
   */
  verifyWebhookV2(rawBody, header, { toleranceSec = SIGNATURE_TOLERANCE_SEC } = {}) {
    if (!this.webhookSecret) {
      throw new GembaPayError('webhookSecret is required for signature verification', null, 'missing_webhook_secret');
    }
    if (typeof header !== 'string' || !header) return false;
    const parts = header.split(',').map((p) => p.trim());
    const t = Number((parts.find((p) => p.startsWith('t=')) || '').slice(2));
    const sigs = parts.filter((p) => p.startsWith('v1=')).map((p) => p.slice(3));
    if (!Number.isInteger(t) || t <= 0 || !sigs.length) return false;
    if (Math.abs(Math.floor(Date.now() / 1000) - t) > toleranceSec) return false;
    const body = Buffer.isBuffer(rawBody) ? rawBody.toString('utf8') : String(rawBody);
    const expected = Buffer.from(crypto.createHmac('sha256', this.webhookSecret).update(`${t}.${body}`).digest('hex'), 'utf8');
    return sigs.some((s) => {
      const got = Buffer.from(s, 'utf8');
      return got.length === expected.length && crypto.timingSafeEqual(got, expected);
    });
  }

  /**
   * Verify the legacy X-GembaPay-Signature header (no timestamp → no replay protection).
   * Prefer verifyWebhookV2 / parseWebhook, which use Signature-V2 whenever it is present.
   * @param {string|Buffer} payload - Raw request body
   * @param {string} signature - Value of x-gembapay-signature header
   * @returns {boolean} Whether the signature is valid
   */
  verifyWebhook(payload, signature) {
    if (!this.webhookSecret) {
      throw new GembaPayError('webhookSecret is required for signature verification', null, 'missing_webhook_secret');
    }

    // `payload` must be the RAW request body (string or Buffer) — the exact bytes GembaPay
    // signed. If an already-parsed object is passed, we best-effort re-serialize (less reliable;
    // mount the webhook route with express.raw and pass the raw body instead).
    const body = Buffer.isBuffer(payload) || typeof payload === 'string'
      ? payload
      : JSON.stringify(payload);
    const expected = crypto
      .createHmac('sha256', this.webhookSecret)
      .update(body)
      .digest('hex'); // bare hex — GembaPay does NOT prefix with "sha256="

    const a = Buffer.from(signature || '', 'utf8');
    const b = Buffer.from(expected, 'utf8');
    // Length-guard first: timingSafeEqual throws on unequal-length buffers.
    return a.length === b.length && crypto.timingSafeEqual(a, b);
  }

  /**
   * Parse and verify a webhook request
   * @param {Object} req - Express/HTTP request object
   * @returns {Object} Parsed webhook event { event, payment, testMode }
   */
  parseWebhook(req, { requireV2 = false } = {}) {
    const signature = req.headers['x-gembapay-signature'];
    const signatureV2 = req.headers['x-gembapay-signature-v2'];
    // Prefer the RAW body — mount the route with express.raw({ type: 'application/json' }).
    // Falls back to req.rawBody, then a re-serialized parsed body (least reliable).
    const raw = Buffer.isBuffer(req.body) ? req.body
      : (req.rawBody != null ? req.rawBody
        : (typeof req.body === 'string' ? req.body : JSON.stringify(req.body)));

    // Signature-V2 (timestamped) whenever GembaPay sent it; the legacy header only as a fallback.
    const valid = signatureV2 ? this.verifyWebhookV2(raw, signatureV2)
      : (!requireV2 && this.verifyWebhook(raw, signature));
    if (!valid) {
      throw new GembaPayError('Invalid webhook signature', 401, 'invalid_signature');
    }

    return (Buffer.isBuffer(raw) || typeof raw === 'string') ? JSON.parse(raw.toString('utf8')) : raw;
  }

  // ── Express Middleware ────────────────────────────────

  /**
   * Express middleware for webhook handling
   * @param {Function} handler - async function(event) called on valid webhooks
   * @returns {Function} Express middleware
   */
  webhookHandler(handler, options = {}) {
    return async (req, res) => {
      try {
        const event = this.parseWebhook(req, options);
        await handler(event);
        res.status(200).json({ received: true });
      } catch (err) {
        if (err.code === 'invalid_signature') {
          res.status(401).json({ error: 'Invalid signature' });
        } else {
          res.status(500).json({ error: 'Webhook processing failed' });
        }
      }
    };
  }

  // ── Refunds (API v1) ─────────────────────────────────

  /**
   * Refund a completed card or PayPal payment — fully or partly. The API key needs the
   * "refunds:write" permission (Dashboard → API Keys).
   * @param {Object} params
   * @param {string} params.orderId - Your order id
   * @param {number|string} [params.amount] - In the payment currency; omit to refund everything left
   * @param {string} [params.reason]
   * @param {Object} [options]
   * @param {string} [options.idempotencyKey] - Pass the SAME key when you retry after a timeout, so the
   *   refund cannot happen twice. Default: a new random key (no retry protection).
   * @returns {Promise<Object>} The refund
   */
  async createRefund({ orderId, amount, reason } = {}, { idempotencyKey } = {}) {
    if (!orderId) throw new GembaPayError('orderId is required', null, 'missing_param');
    const body = { orderId };
    if (amount !== undefined && amount !== null) body.amount = amount;
    if (reason) body.reason = reason;
    const res = await this._request('POST', '/api/v1/refunds', body, { 'Idempotency-Key': idempotencyKey || crypto.randomUUID() });
    return res.refund;
  }

  /**
   * List refunds (newest first; this key's mode only).
   * @param {Object} [params] - { orderId, limit }
   * @returns {Promise<Object[]>}
   */
  async listRefunds({ orderId, limit } = {}) {
    const q = new URLSearchParams();
    if (orderId) q.set('orderId', orderId);
    if (limit) q.set('limit', String(limit));
    const res = await this._request('GET', '/api/v1/refunds' + (q.toString() ? `?${q}` : ''));
    return res.refunds;
  }

  /** @param {string} refundId @returns {Promise<Object>} */
  async getRefund(refundId) {
    if (!refundId) throw new GembaPayError('refundId is required', null, 'missing_param');
    const res = await this._request('GET', `/api/v1/refunds/${encodeURIComponent(refundId)}`);
    return res.refund;
  }

  // ── Payment links (API v1) ───────────────────────────

  /**
   * Create a payment link (paid at https://payment.gembapay.com/link/<token>). The API key needs the
   * "payment_links:write" permission. Test keys create test links, live keys live links.
   * @param {Object} params - { amount?, currency?, description?, methods?, multiUse?, maxUses?,
   *   maxTotalAmount?, expiresAt?, customerFields?, merchantNotify? }
   * @param {Object} [options] - { idempotencyKey }
   * @returns {Promise<Object>} The payment link
   */
  async createPaymentLink(params = {}, { idempotencyKey } = {}) {
    const res = await this._request('POST', '/api/v1/payment-links', params, { 'Idempotency-Key': idempotencyKey || crypto.randomUUID() });
    return res.paymentLink;
  }

  /** @returns {Promise<Object[]>} Payment links of this key's mode */
  async listPaymentLinks() {
    const res = await this._request('GET', '/api/v1/payment-links');
    return res.paymentLinks;
  }

  /** @param {string} id @returns {Promise<Object>} The link with its completed payments (uses) */
  async getPaymentLink(id) {
    if (!id) throw new GembaPayError('id is required', null, 'missing_param');
    const res = await this._request('GET', `/api/v1/payment-links/${encodeURIComponent(id)}`);
    return res.paymentLink;
  }

  /** @param {string} id @param {'active'|'disabled'} status @returns {Promise<Object>} */
  async setPaymentLinkStatus(id, status) {
    if (!id) throw new GembaPayError('id is required', null, 'missing_param');
    if (!['active', 'disabled'].includes(status)) throw new GembaPayError('status must be "active" or "disabled"', null, 'invalid_param');
    const res = await this._request('PATCH', `/api/v1/payment-links/${encodeURIComponent(id)}`, { status });
    return res.paymentLink;
  }

  /** Delete a link (its payment history is kept). @param {string} id */
  async deletePaymentLink(id) {
    if (!id) throw new GembaPayError('id is required', null, 'missing_param');
    return this._request('DELETE', `/api/v1/payment-links/${encodeURIComponent(id)}`);
  }

  // ── Internal ─────────────────────────────────────────

  _request(method, path, body, extraHeaders = {}) {
    return new Promise((resolve, reject) => {
      const url = new URL(this.baseUrl + path);

      const options = {
        hostname: url.hostname,
        port: url.port || 443,
        path: url.pathname + url.search,
        method,
        headers: {
          'Authorization': `Bearer ${this.apiKey}`,
          'Content-Type': 'application/json',
          'User-Agent': `gembapay-node/${VERSION}`,
          ...extraHeaders,
        },
      };

      const req = https.request(options, (res) => {
        let data = '';
        res.on('data', (chunk) => data += chunk);
        res.on('end', () => {
          try {
            const parsed = JSON.parse(data);
            if (res.statusCode >= 400) {
              reject(new GembaPayError(
                parsed.message || parsed.error || `HTTP ${res.statusCode}`,
                res.statusCode,
                parsed.code
              ));
            } else {
              resolve(parsed);
            }
          } catch {
            reject(new GembaPayError(`Invalid response: ${data}`, res.statusCode, 'parse_error'));
          }
        });
      });

      req.on('error', (err) => reject(new GembaPayError(err.message, null, 'network_error')));
      req.setTimeout(this.timeout, () => {
        req.destroy();
        reject(new GembaPayError('Request timeout', null, 'timeout'));
      });

      if (body) req.write(JSON.stringify(body));
      req.end();
    });
  }
}

module.exports = GembaPay;
module.exports.GembaPay = GembaPay;
module.exports.GembaPayError = GembaPayError;
module.exports.default = GembaPay;
