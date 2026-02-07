/**
 * GembaPay API client for Shopify app
 */

const BASE_URL = 'https://api.gembapay.com';

export class GembaPayClient {
  constructor(apiKey, webhookSecret) {
    this.apiKey = apiKey;
    this.webhookSecret = webhookSecret;
    this.isTestMode = apiKey?.startsWith('gembapay_test_') ?? false;
  }

  async createPayment({ orderId, amount, currency = 'USD', description }) {
    const body = { orderId, amount, currency };
    if (description) body.description = description;

    return this.request('POST', '/api/merchant/payment-request', body);
  }

  async getPaymentStatus(orderId) {
    return this.request('GET', `/api/customer/payment/${encodeURIComponent(orderId)}/status`);
  }

  verifyWebhook(payload, signature) {
    const crypto = require('crypto');
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

  async request(method, path, body) {
    const options = {
      method,
      headers: {
        'Authorization': `Bearer ${this.apiKey}`,
        'Content-Type': 'application/json',
        'User-Agent': 'gembapay-shopify/1.0.0',
      },
    };

    if (body) options.body = JSON.stringify(body);

    const response = await fetch(`${BASE_URL}${path}`, options);
    const data = await response.json();

    if (!response.ok) {
      throw new Error(data.message || data.error || `HTTP ${response.status}`);
    }

    return data;
  }
}

/**
 * Get stored GembaPay settings for a shop
 * In production, use a database (e.g., Prisma with SQLite/PostgreSQL)
 */
const shopSettings = new Map();

export function getSettings(shop) {
  return shopSettings.get(shop) || { apiKey: '', webhookSecret: '', enabled: false };
}

export function saveSettings(shop, settings) {
  shopSettings.set(shop, { ...getSettings(shop), ...settings });
}

export function getClient(shop) {
  const settings = getSettings(shop);
  if (!settings.apiKey) return null;
  return new GembaPayClient(settings.apiKey, settings.webhookSecret);
}
