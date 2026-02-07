'use strict';

const https = require('https');
const crypto = require('crypto');

const BASE_URL = 'https://api.gembapay.com';
const VERSION = '1.0.0';

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
   * @param {string} [params.currency='USD'] - Currency code (86+ supported)
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
   * Get payment details
   * @param {string} orderId - Order identifier
   * @returns {Promise<Object>} Payment details
   */
  async getPayment(orderId) {
    if (!orderId) throw new GembaPayError('orderId is required', null, 'missing_param');
    return this._request('GET', `/api/customer/payment/${encodeURIComponent(orderId)}`);
  }

  /**
   * Check payment status
   * @param {string} orderId - Order identifier
   * @returns {Promise<Object>} Payment status
   */
  async getPaymentStatus(orderId) {
    if (!orderId) throw new GembaPayError('orderId is required', null, 'missing_param');
    return this._request('GET', `/api/customer/payment/${encodeURIComponent(orderId)}/status`);
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
   * Verify webhook signature
   * @param {string|Buffer} payload - Raw request body
   * @param {string} signature - Value of x-gembapay-signature header
   * @returns {boolean} Whether the signature is valid
   */
  verifyWebhook(payload, signature) {
    if (!this.webhookSecret) {
      throw new GembaPayError('webhookSecret is required for signature verification', null, 'missing_webhook_secret');
    }

    const body = typeof payload === 'string' ? payload : JSON.stringify(payload);
    const expected = 'sha256=' + crypto
      .createHmac('sha256', this.webhookSecret)
      .update(body)
      .digest('hex');

    return crypto.timingSafeEqual(
      Buffer.from(signature || ''),
      Buffer.from(expected)
    );
  }

  /**
   * Parse and verify a webhook request
   * @param {Object} req - Express/HTTP request object
   * @returns {Object} Parsed webhook event { event, payment, testMode }
   */
  parseWebhook(req) {
    const signature = req.headers['x-gembapay-signature'];
    const body = req.body;

    if (!this.verifyWebhook(body, signature)) {
      throw new GembaPayError('Invalid webhook signature', 401, 'invalid_signature');
    }

    return body;
  }

  // ── Express Middleware ────────────────────────────────

  /**
   * Express middleware for webhook handling
   * @param {Function} handler - async function(event) called on valid webhooks
   * @returns {Function} Express middleware
   */
  webhookHandler(handler) {
    return async (req, res) => {
      try {
        const event = this.parseWebhook(req);
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

  // ── Internal ─────────────────────────────────────────

  _request(method, path, body) {
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
