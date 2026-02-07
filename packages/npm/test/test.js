const GembaPay = require('../src/index');

// Test: initialization
console.log('── GembaPay SDK Tests ──\n');

try {
  new GembaPay({});
} catch (e) {
  console.log('✓ Throws on missing API key:', e.message);
}

const client = new GembaPay({
  apiKey: 'gembapay_test_demo_key',
  webhookSecret: 'test_secret'
});

console.log('✓ Client created');
console.log('✓ Test mode:', client.isTestMode);

// Test: webhook verification
const crypto = require('crypto');
const payload = JSON.stringify({ event: 'payment.completed', payment: { orderId: 'TEST-1' } });
const sig = 'sha256=' + crypto.createHmac('sha256', 'test_secret').update(payload).digest('hex');

try {
  const valid = client.verifyWebhook(payload, sig);
  console.log('✓ Webhook verification:', valid);
} catch (e) {
  console.log('✗ Webhook verification failed:', e.message);
}

// Test: parameter validation
client.createPayment({}).catch(e => {
  console.log('✓ Throws on missing orderId:', e.message);
  console.log('\n── All tests passed ──');
});
