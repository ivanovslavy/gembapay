// GembaPay SDK tests — plain Node, no dependencies. Exits 1 on the first failure.
const assert = require('assert');
const crypto = require('crypto');
const GembaPay = require('../src/index');
const { GembaPayError } = GembaPay;

let passed = 0;
async function test(name, fn) {
  try { await fn(); passed++; console.log('✓', name); }
  catch (e) { console.error('✗', name, '\n ', e.message); process.exit(1); }
}

const SECRET = 'whsec_test_secret';
const client = new GembaPay({ apiKey: 'gembapay_test_demo_key', webhookSecret: SECRET });
const body = JSON.stringify({ event: 'payment.completed', eventId: 'evt_1', payment: { orderId: 'TEST-1' } });
const now = () => Math.floor(Date.now() / 1000);
const v1 = (secret, t, b = body) => crypto.createHmac('sha256', secret).update(`${t}.${b}`).digest('hex');
const legacy = (secret, b = body) => crypto.createHmac('sha256', secret).update(b).digest('hex');

(async () => {
  console.log('── GembaPay SDK tests ──\n');

  await test('throws without apiKey', () => assert.throws(() => new GembaPay({}), GembaPayError));
  await test('test mode from key prefix', () => assert.strictEqual(client.isTestMode, true));

  // ── Signature V2 ──
  await test('V2: valid signature', () => assert.strictEqual(client.verifyWebhookV2(body, `t=${now()},v1=${v1(SECRET, now())}`), true));
  await test('V2: Buffer body', () => assert.strictEqual(client.verifyWebhookV2(Buffer.from(body), `t=${now()},v1=${v1(SECRET, now())}`), true));
  await test('V2: rotation — second v1 matches', () => {
    const t = now();
    assert.strictEqual(client.verifyWebhookV2(body, `t=${t},v1=${v1('whsec_other', t)},v1=${v1(SECRET, t)}`), true);
  });
  await test('V2: stale timestamp rejected (> 300 s)', () => { const t = now() - 301; assert.strictEqual(client.verifyWebhookV2(body, `t=${t},v1=${v1(SECRET, t)}`), false); });
  await test('V2: future timestamp rejected (> 300 s)', () => { const t = now() + 301; assert.strictEqual(client.verifyWebhookV2(body, `t=${t},v1=${v1(SECRET, t)}`), false); });
  await test('V2: tampered body rejected', () => { const t = now(); assert.strictEqual(client.verifyWebhookV2(body.replace('TEST-1', 'TEST-2'), `t=${t},v1=${v1(SECRET, t)}`), false); });
  await test('V2: wrong secret rejected', () => { const t = now(); assert.strictEqual(client.verifyWebhookV2(body, `t=${t},v1=${v1('whsec_wrong', t)}`), false); });
  await test('V2: malformed headers rejected', () => {
    for (const h of ['', 'garbage', `v1=${v1(SECRET, now())}`, `t=${now()}`, `t=abc,v1=${v1(SECRET, now())}`]) assert.strictEqual(client.verifyWebhookV2(body, h), false, h);
  });
  await test('V2: custom tolerance', () => { const t = now() - 100; assert.strictEqual(client.verifyWebhookV2(body, `t=${t},v1=${v1(SECRET, t)}`, { toleranceSec: 60 }), false); });
  await test('V2: needs webhookSecret', () => assert.throws(() => new GembaPay({ apiKey: 'gembapay_test_x' }).verifyWebhookV2(body, 't=1,v1=a'), GembaPayError));

  // ── Legacy ──
  await test('legacy: valid', () => assert.strictEqual(client.verifyWebhook(body, legacy(SECRET)), true));
  await test('legacy: "sha256=" prefix rejected (bare hex only)', () => assert.strictEqual(client.verifyWebhook(body, 'sha256=' + legacy(SECRET)), false));

  // ── parseWebhook / webhookHandler ──
  const req = (headers, b = body) => ({ headers, body: Buffer.from(b) });
  await test('parseWebhook: V2 used when present', () => {
    const t = now();
    const evt = client.parseWebhook(req({ 'x-gembapay-signature-v2': `t=${t},v1=${v1(SECRET, t)}`, 'x-gembapay-signature': 'wrong' }));
    assert.strictEqual(evt.eventId, 'evt_1');
  });
  await test('parseWebhook: invalid V2 is NOT rescued by a valid legacy signature', () => {
    assert.throws(() => client.parseWebhook(req({ 'x-gembapay-signature-v2': `t=${now() - 3600},v1=${v1(SECRET, now() - 3600)}`, 'x-gembapay-signature': legacy(SECRET) })), /Invalid webhook signature/);
  });
  await test('parseWebhook: legacy accepted only without V2', () => assert.strictEqual(client.parseWebhook(req({ 'x-gembapay-signature': legacy(SECRET) })).event, 'payment.completed'));
  await test('parseWebhook: requireV2 refuses legacy-only', () => assert.throws(() => client.parseWebhook(req({ 'x-gembapay-signature': legacy(SECRET) }), { requireV2: true }), /Invalid/));

  const run = async (headers) => {
    let status = null, got = null;
    const res = { status(c) { status = c; return this; }, json() { return this; } };
    await client.webhookHandler(async (e) => { got = e; })(req(headers), res);
    return { status, got };
  };
  await test('webhookHandler: valid V2 → 200 + handler called', async () => {
    const t = now(); const r = await run({ 'x-gembapay-signature-v2': `t=${t},v1=${v1(SECRET, t)}` });
    assert.strictEqual(r.status, 200); assert.ok(r.got);
  });
  await test('webhookHandler: forged → 401, handler not called', async () => {
    const r = await run({ 'x-gembapay-signature-v2': `t=${now()},v1=${'a'.repeat(64)}` });
    assert.strictEqual(r.status, 401); assert.strictEqual(r.got, null);
  });

  // ── API methods build the right requests (transport mocked) ──
  const calls = [];
  client._request = async (method, path, b, headers) => { calls.push({ method, path, b, headers }); return { refund: { id: 'r1' }, refunds: [], paymentLink: { id: 'l1' }, paymentLinks: [] }; };
  await test('createRefund: POST /api/v1/refunds with Idempotency-Key', async () => {
    await client.createRefund({ orderId: 'O-1', amount: '5.00', reason: 'x' }, { idempotencyKey: 'key-12345678' });
    const c = calls.pop(); assert.strictEqual(c.method, 'POST'); assert.strictEqual(c.path, '/api/v1/refunds');
    assert.deepStrictEqual(c.b, { orderId: 'O-1', amount: '5.00', reason: 'x' }); assert.strictEqual(c.headers['Idempotency-Key'], 'key-12345678');
  });
  await test('createRefund: generates an Idempotency-Key when none given', async () => {
    await client.createRefund({ orderId: 'O-1' }); assert.match(calls.pop().headers['Idempotency-Key'], /^[0-9a-f-]{36}$/);
  });
  await test('createRefund: orderId required', () => assert.rejects(client.createRefund({}), /orderId is required/));
  await test('listRefunds: query string', async () => { await client.listRefunds({ orderId: 'O 1', limit: 5 }); assert.strictEqual(calls.pop().path, '/api/v1/refunds?orderId=O+1&limit=5'); });
  await test('getRefund: path encoded', async () => { await client.getRefund('a/b'); assert.strictEqual(calls.pop().path, '/api/v1/refunds/a%2Fb'); });
  await test('createPaymentLink: POST with Idempotency-Key', async () => {
    await client.createPaymentLink({ amount: 10 }); const c = calls.pop();
    assert.strictEqual(c.path, '/api/v1/payment-links'); assert.ok(c.headers['Idempotency-Key']);
  });
  await test('setPaymentLinkStatus: validates status', () => assert.rejects(client.setPaymentLinkStatus('l1', 'paused'), /status must be/));
  await test('setPaymentLinkStatus: PATCH', async () => { await client.setPaymentLinkStatus('l1', 'disabled'); const c = calls.pop(); assert.strictEqual(c.method, 'PATCH'); assert.deepStrictEqual(c.b, { status: 'disabled' }); });
  await test('deletePaymentLink: DELETE', async () => { await client.deletePaymentLink('l1'); assert.strictEqual(calls.pop().method, 'DELETE'); });
  await test('createPayment: validates params', () => assert.rejects(client.createPayment({}), /orderId is required/));

  console.log(`\n── All ${passed} tests passed ──`);
})();
