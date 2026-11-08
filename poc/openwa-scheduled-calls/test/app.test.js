import test from 'node:test';
import assert from 'node:assert/strict';
import { createServer } from 'node:http';
import { createApp } from '../src/app.js';
const config = { serviceToken: 's'.repeat(48), pairingUsername: 'operator', pairingPassword: 'p'.repeat(40), pairingEnabled: false };
const payload = { externalBookingReference: 'booking-123', destinationPhone: '+40712345678', startAt: '2026-12-06T10:00:00Z', endAt: '2026-12-06T10:15:00Z', timezone: 'Europe/Bucharest', title: 'EasyEnglish session', callType: 'video' };
function createStore() { const records = []; return { all: () => records, findById: (id) => records.find((record) => record.providerEventId === id) || null, async upsert(record) { const index = records.findIndex((item) => item.idempotencyKey === record.idempotencyKey); if (index < 0) records.push(record); else records[index] = record; } }; }
async function setup(providerOverrides = {}, phase = 'authenticated', providerTimeouts = { create: 25000, read: 10000, cancel: 10000 }, configOverrides = {}) {
  const calls = [], provider = { async createEvent(input) { calls.push(['create', input]); return { providerEventId: 'event-id-12345678', joinUrl: 'https://call.whatsapp.com/test-link', status: 'scheduled', createdAt: '2026-11-05T10:00:00.000Z' }; }, async getEvent(id) { calls.push(['get', id]); return { providerEventId: id, joinUrl: 'https://call.whatsapp.com/test-link', status: 'scheduled' }; }, async cancelEvent(id) { calls.push(['cancel', id]); return true; }, ...providerOverrides };
  const state = { phase, provider: phase === 'authenticated' ? provider : null, startedAt: new Date().toISOString() }, store = createStore();
  const server = createServer(createApp({ config: { ...config, ...configOverrides }, state, store, logger: { info() {}, warn() {}, error() {} }, providerTimeouts })); await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
  const base = 'http://127.0.0.1:' + server.address().port, auth = { Authorization: 'Bearer ' + config.serviceToken, 'Content-Type': 'application/json' };
  return { calls, store, server, base, auth, close: () => new Promise((resolve) => server.close(resolve)) };
}
test('health checks expose only non-sensitive readiness and liveness', async () => { const s = await setup({}, 'disabled'); try { assert.deepEqual(await (await fetch(s.base + '/health/live')).json(), { status: 'ok' }); const ready = await fetch(s.base + '/health/ready'); assert.equal(ready.status, 503); assert.deepEqual(await ready.json(), { status: 'not_ready' }); } finally { await s.close(); } });
test('requires service authentication for versioned API routes', async () => { const s = await setup(); try { assert.equal((await fetch(s.base + '/v1/call-events')).status, 401); assert.equal((await fetch(s.base + '/v1/call-events', { method: 'POST', headers: { ...s.auth, 'Idempotency-Key': 'request-key-00000001' }, body: JSON.stringify(payload) })).status, 201); } finally { await s.close(); } });
test('creates, replays, retrieves, and idempotently cancels a call event', async () => { const s = await setup(); const key = 'request-key-00000001'; try {
  const created = await fetch(s.base + '/v1/call-events', { method: 'POST', headers: { ...s.auth, 'Idempotency-Key': key }, body: JSON.stringify(payload) }); assert.equal(created.status, 201); const result = await created.json(); assert.equal(result.providerEventId, 'event-id-12345678'); assert.equal(result.joinUrl, 'https://call.whatsapp.com/test-link');
  const duplicateBooking = await fetch(s.base + '/v1/call-events', { method: 'POST', headers: { ...s.auth, 'Idempotency-Key': 'request-key-booking-02' }, body: JSON.stringify(payload) }); assert.equal(duplicateBooking.status, 409);
  const replay = await fetch(s.base + '/v1/call-events', { method: 'POST', headers: { ...s.auth, 'Idempotency-Key': key }, body: JSON.stringify(payload) }); assert.equal(replay.status, 200); assert.equal((await replay.json()).replayed, true); assert.equal(s.calls.filter((call) => call[0] === 'create').length, 1);
  const conflict = await fetch(s.base + '/v1/call-events', { method: 'POST', headers: { ...s.auth, 'Idempotency-Key': key }, body: JSON.stringify({ ...payload, title: 'Changed title' }) }); assert.equal(conflict.status, 409);
  assert.equal((await fetch(s.base + '/v1/call-events/event-id-12345678', { headers: s.auth })).status, 200);
  const metrics = await fetch(s.base + '/v1/metrics', { headers: s.auth }); assert.equal((await metrics.json()).created, 1);
  const cancelled = await fetch(s.base + '/v1/call-events/event-id-12345678', { method: 'DELETE', headers: s.auth }); assert.equal(cancelled.status, 200); assert.equal((await cancelled.json()).status, 'cancelled');
  assert.equal(s.store.all()[0].joinUrl, null); assert.equal(s.store.all()[0].input, undefined); assert.equal(s.store.all()[0].externalBookingReference, undefined);
  assert.equal((await fetch(s.base + '/v1/call-events/event-id-12345678', { method: 'DELETE', headers: s.auth })).status, 200); assert.equal(s.calls.filter((call) => call[0] === 'cancel').length, 1);
} finally { await s.close(); } });
test('rejects invalid phone numbers and missing idempotency keys before provider calls', async () => { const s = await setup(); try { assert.equal((await fetch(s.base + '/v1/call-events', { method: 'POST', headers: { ...s.auth, 'Idempotency-Key': 'request-key-00000002' }, body: JSON.stringify({ ...payload, destinationPhone: '0712345678' }) })).status, 400); assert.equal((await fetch(s.base + '/v1/call-events', { method: 'POST', headers: s.auth, body: JSON.stringify(payload) })).status, 400); assert.equal(s.calls.length, 0); } finally { await s.close(); } });
test('serializes concurrent duplicate requests to one provider submission', async () => { let count = 0; const s = await setup({ async createEvent() { count += 1; await new Promise((resolve) => setTimeout(resolve, 30)); return { providerEventId: 'event-id-12345678', joinUrl: 'https://call.whatsapp.com/test-link', status: 'scheduled', createdAt: new Date().toISOString() }; } }); try { const request = () => fetch(s.base + '/v1/call-events', { method: 'POST', headers: { ...s.auth, 'Idempotency-Key': 'request-key-00000003' }, body: JSON.stringify(payload) }); const responses = await Promise.all([request(), request()]); assert.deepEqual(responses.map((response) => response.status).sort((a, b) => a - b), [200, 201]); assert.equal(count, 1); } finally { await s.close(); } });
test('returns explicit unavailable and provider-failure responses', async () => { const unavailable = await setup({}, 'disabled'); try { const response = await fetch(unavailable.base + '/v1/call-events', { method: 'POST', headers: { ...unavailable.auth, 'Idempotency-Key': 'request-key-00000004' }, body: JSON.stringify(payload) }); assert.equal(response.status, 503); } finally { await unavailable.close(); } const failed = await setup({ async createEvent() { throw new Error('private provider detail'); } }); try { const response = await fetch(failed.base + '/v1/call-events', { method: 'POST', headers: { ...failed.auth, 'Idempotency-Key': 'request-key-00000005' }, body: JSON.stringify(payload) }); assert.equal(response.status, 502); assert.equal((await response.text()).includes('private provider detail'), false); } finally { await failed.close(); } });

test('marks provider timeouts uncertain and prevents automatic duplicate submissions', async () => { let count = 0; const s = await setup({ async createEvent() { count += 1; return new Promise(() => {}); } }, 'authenticated', { create: 25, read: 20, cancel: 20 }); try { const response = await fetch(s.base + '/v1/call-events', { method: 'POST', headers: { ...s.auth, 'Idempotency-Key': 'request-key-timeout-01' }, body: JSON.stringify(payload) }); assert.equal(response.status, 202); assert.equal((await response.json()).retryable, false); const retry = await fetch(s.base + '/v1/call-events', { method: 'POST', headers: { ...s.auth, 'Idempotency-Key': 'request-key-timeout-01' }, body: JSON.stringify(payload) }); assert.equal(retry.status, 202); assert.equal(count, 1); } finally { await s.close(); } });

test('pairing interface is not exposed unless the pairing feature is explicitly enabled', async () => { const s = await setup(); try { assert.equal((await fetch(s.base + '/pairing/status')).status, 404); assert.equal((await fetch(s.base + '/')).status, 404); } finally { await s.close(); } });

test('pairing routes require Basic authentication when the pairing window is explicitly enabled', async () => { const s = await setup({}, 'disabled', undefined, { pairingEnabled: true }); try { assert.equal((await fetch(s.base + '/pairing/status')).status, 401); const credentials = Buffer.from(config.pairingUsername + ':' + config.pairingPassword).toString('base64'); assert.equal((await fetch(s.base + '/pairing/status', { headers: { Authorization: 'Basic ' + credentials } })).status, 200); } finally { await s.close(); } });

test('manual pairing reset requires Basic auth and returns only safe reset status', async () => {
  let resets = 0;
  const s = await setup({}, 'failed', undefined, { pairingEnabled: true });
  await s.close();
  const server = createServer(createApp({
    config: { ...config, pairingEnabled: true },
    state: { phase: 'failed', qr: null, startedAt: new Date().toISOString() },
    store: createStore(),
    resetSession: async () => { resets += 1; return { checkpointBackedUp: true }; },
    logger: { info() {}, warn() {}, error() {} },
  }));
  await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
  const base = 'http://127.0.0.1:' + server.address().port;
  const credentials = Buffer.from(config.pairingUsername + ':' + config.pairingPassword).toString('base64');
  try {
    assert.equal((await fetch(base + '/pairing/reset', { method: 'POST' })).status, 401);
    const response = await fetch(base + '/pairing/reset', { method: 'POST', headers: { Authorization: 'Basic ' + credentials, 'Content-Type': 'application/json' }, body: '{}' });
    assert.equal(response.status, 202);
    assert.deepEqual(await response.json(), { status: 'pairing_restarted', checkpointBackedUp: true });
    assert.equal(resets, 1);
  } finally { await new Promise((resolve) => server.close(resolve)); }
});

test('pairing status reports QR availability without exposing the QR credential', async () => {
  const s = await setup({}, 'qr_ready', undefined, { pairingEnabled: true });
  s.server.close();
  const state = { phase: 'qr_ready', qr: 'easyenglish-test-pairing-payload', startedAt: '2026-11-07T13:00:00.000Z' };
  const server = createServer(createApp({ config: { ...config, pairingEnabled: true }, state, store: s.store, logger: { info() {}, warn() {}, error() {} } }));
  await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
  const base = 'http://127.0.0.1:' + server.address().port;
  const credentials = Buffer.from(config.pairingUsername + ':' + config.pairingPassword).toString('base64');
  const headers = { Authorization: 'Basic ' + credentials };
  try {
    const status = await fetch(base + '/pairing/status', { headers });
    assert.deepEqual(await status.json(), { phase: 'qr_ready', qrAvailable: true, startedAt: state.startedAt });
    const qr = await fetch(base + '/pairing/qr', { headers });
    assert.equal(qr.status, 200);
    assert.ok(qr.headers.get('content-type').includes('image/png'));
    assert.ok((await qr.arrayBuffer()).byteLength > 0);
  } finally { await new Promise((resolve) => server.close(resolve)); }
});

test('pairing status reports when QR data is missing instead of implying it is available', async () => {
  const s = await setup({}, 'qr_ready', undefined, { pairingEnabled: true });
  s.server.close();
  const state = { phase: 'qr_ready', qr: null, startedAt: '2026-11-07T13:00:00.000Z' };
  const server = createServer(createApp({ config: { ...config, pairingEnabled: true }, state, store: s.store, logger: { info() {}, warn() {}, error() {} } }));
  await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
  const base = 'http://127.0.0.1:' + server.address().port;
  const credentials = Buffer.from(config.pairingUsername + ':' + config.pairingPassword).toString('base64');
  try {
    const status = await fetch(base + '/pairing/status', { headers: { Authorization: 'Basic ' + credentials } });
    assert.deepEqual(await status.json(), { phase: 'qr_ready', qrAvailable: false, startedAt: state.startedAt });
    const qr = await fetch(base + '/pairing/qr', { headers: { Authorization: 'Basic ' + credentials } });
    assert.equal(qr.status, 404);
  } finally { await new Promise((resolve) => server.close(resolve)); }
});

test('sends OpenWA messages once with durable idempotency and rejects conflicting replays', async () => {
  const records = [], calls = [];
  const messageStore = {
    findByKey: (key) => records.find((record) => record.idempotencyKey === key) || null,
    async upsert(record) { const index = records.findIndex((item) => item.idempotencyKey === record.idempotencyKey); if (index < 0) records.push(record); else records[index] = record; },
    pendingInbound: () => [],
  };
  const s = await setup({ async sendText(to, text) { calls.push([to, text]); return { providerMessageId: 'openwa-message-123' }; } }, 'authenticated', undefined, { messagingEnabled: true });
  await s.close();
  const appServer = createServer(createApp({ config: { ...config, messagingEnabled: true }, state: { phase: 'authenticated', provider: { async sendText(to, text) { calls.push([to, text]); return { providerMessageId: 'openwa-message-123' }; } }, metrics: {} }, store: s.store, messageStore, messageTimeoutMs: 20, logger: { info() {}, warn() {}, error() {} } }));
  await new Promise((resolve) => appServer.listen(0, '127.0.0.1', resolve));
  const base = 'http://127.0.0.1:' + appServer.address().port;
  const headers = { ...s.auth, 'Idempotency-Key': 'contact-verification:12345678' };
  const body = JSON.stringify({ to: '+40712345678', text: 'Your verification code is 123456.' });
  try {
    const first = await fetch(base + '/v1/messages', { method: 'POST', headers, body });
    assert.equal(first.status, 201);
    assert.deepEqual(await first.json(), { providerMessageId: 'openwa-message-123', status: 'accepted' });
    const replay = await fetch(base + '/v1/messages', { method: 'POST', headers, body });
    assert.equal(replay.status, 200);
    assert.equal((await replay.json()).replayed, true);
    const conflict = await fetch(base + '/v1/messages', { method: 'POST', headers, body: JSON.stringify({ to: '+40712345678', text: 'Different message' }) });
    assert.equal(conflict.status, 409);
    assert.equal(calls.length, 1);
  } finally { await new Promise((resolve) => appServer.close(resolve)); }
});

test('does not retry an ambiguous OpenWA send automatically', async () => {
  const records = [], messageStore = { findByKey: (key) => records.find((record) => record.idempotencyKey === key) || null, async upsert(record) { const index = records.findIndex((item) => item.idempotencyKey === record.idempotencyKey); if (index < 0) records.push(record); else records[index] = record; }, pendingInbound: () => [] };
  let sends = 0;
  const server = createServer(createApp({ config: { ...config, messagingEnabled: true }, state: { phase: 'authenticated', provider: { async sendText() { sends++; return new Promise(() => {}); } }, metrics: {} }, store: createStore(), messageStore, messageTimeoutMs: 20, logger: { info() {}, warn() {}, error() {} } }));
  await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
  const base = 'http://127.0.0.1:' + server.address().port;
  const headers = { ...{ Authorization: 'Bearer ' + config.serviceToken, 'Content-Type': 'application/json' }, 'Idempotency-Key': 'ambiguous-send-key-0001' };
  try {
    const first = await fetch(base + '/v1/messages', { method: 'POST', headers, body: JSON.stringify({ to: '+40712345678', text: 'Hello' }) });
    assert.equal(first.status, 202);
    const replay = await fetch(base + '/v1/messages', { method: 'POST', headers, body: JSON.stringify({ to: '+40712345678', text: 'Hello' }) });
    assert.equal(replay.status, 202);
    assert.equal(sends, 1);
  } finally { await new Promise((resolve) => server.close(resolve)); }
});

test('serializes concurrent duplicate message submissions and exposes safe reconciliation status', async () => {
  const records = [], calls = [];
  const messageStore = {
    findByKey: (key) => records.find((record) => record.idempotencyKey === key) || null,
    async upsert(record) { const index = records.findIndex((item) => item.idempotencyKey === record.idempotencyKey); if (index < 0) records.push(record); else records[index] = record; },
    pendingInbound: () => [],
  };
  const provider = { async sendText(to, text) { calls.push([to, text]); await new Promise((resolve) => setTimeout(resolve, 10)); return { providerMessageId: 'wamid-concurrent-1' }; } };
  const server = createServer(createApp({ config: { ...config, messagingEnabled: true }, state: { phase: 'authenticated', provider, metrics: {} }, store: createStore(), messageStore, logger: { info() {}, warn() {}, error() {} } }));
  await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
  const base = 'http://127.0.0.1:' + server.address().port;
  const headers = { Authorization: 'Bearer ' + config.serviceToken, 'Content-Type': 'application/json', 'Idempotency-Key': 'concurrent-message-key-01' };
  const body = JSON.stringify({ to: '+40712345678', text: 'Hello once' });
  try {
    const results = await Promise.all([fetch(base + '/v1/messages', { method: 'POST', headers, body }), fetch(base + '/v1/messages', { method: 'POST', headers, body })]);
    assert.deepEqual(results.map((response) => response.status).sort(), [200, 201]);
    assert.equal(calls.length, 1);
    const status = await fetch(base + '/v1/messages/' + encodeURIComponent('concurrent-message-key-01'), { headers: { Authorization: 'Bearer ' + config.serviceToken } });
    assert.equal(status.status, 200);
    assert.deepEqual(await status.json(), { status: 'accepted', providerMessageId: 'wamid-concurrent-1', attempts: 1, acceptedAt: records[0].acceptedAt });
  } finally { await new Promise((resolve) => server.close(resolve)); }
});

test('does not replay a provider-accepted message when acknowledgement persistence fails', async () => {
  const records = [], calls = [];
  let rejectAcceptedWrite = true;
  const messageStore = {
    findByKey: (key) => records.find((record) => record.idempotencyKey === key) || null,
    async upsert(record) {
      if (record.status === 'accepted' && rejectAcceptedWrite) { rejectAcceptedWrite = false; throw new Error('disk unavailable'); }
      const index = records.findIndex((item) => item.idempotencyKey === record.idempotencyKey);
      if (index < 0) records.push({ ...record }); else records[index] = { ...record };
    },
    pendingInbound: () => [],
  };
  const provider = { async sendText() { calls.push('send'); return { providerMessageId: 'wamid-persist-failure' }; } };
  const server = createServer(createApp({ config: { ...config, messagingEnabled: true }, state: { phase: 'authenticated', provider, metrics: {} }, store: createStore(), messageStore, logger: { info() {}, warn() {}, error() {} } }));
  await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
  const base = 'http://127.0.0.1:' + server.address().port;
  const headers = { Authorization: 'Bearer ' + config.serviceToken, 'Content-Type': 'application/json', 'Idempotency-Key': 'accepted-persist-failure-1' };
  const body = JSON.stringify({ to: '+40712345678', text: 'Accepted once' });
  try {
    const first = await fetch(base + '/v1/messages', { method: 'POST', headers, body });
    assert.equal(first.status, 202);
    const replay = await fetch(base + '/v1/messages', { method: 'POST', headers, body });
    assert.equal(replay.status, 202);
    assert.equal(calls.length, 1);
  } finally { await new Promise((resolve) => server.close(resolve)); }
});


test('preserves useful OpenWA provider error details while redacting recipient and verification code', async () => {
  const records = [], logs = [];
  const messageStore = {
    findByKey: (key) => records.find((record) => record.idempotencyKey === key) || null,
    async upsert(record) { const index = records.findIndex((item) => item.idempotencyKey === record.idempotencyKey); if (index < 0) records.push(record); else records[index] = record; },
  };
  const state = {
    phase: 'authenticated',
    provider: { async sendText() { throw new Error('Evaluation failed: sendText rejected for +40712345678 with OTP 123456 due to missing chat model'); } },
    metrics: {},
  };
  const server = createServer(createApp({
    config: { ...config, messagingEnabled: true },
    state,
    store: createStore(),
    messageStore,
    logger: { info() {}, warn(line) { logs.push(line); }, error() {} },
  }));
  await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
  const base = 'http://127.0.0.1:' + server.address().port;
  try {
    const response = await fetch(base + '/v1/messages', {
      method: 'POST',
      headers: { ...{ Authorization: 'Bearer ' + config.serviceToken, 'Content-Type': 'application/json' }, 'Idempotency-Key': 'contact-verification:diagnostic01' },
      body: JSON.stringify({ to: '+40712345678', text: 'Your verification code is 123456.' }),
    });
    assert.equal(response.status, 202);
    const entry = JSON.parse(logs[0]);
    assert.match(entry.providerError, /Evaluation failed/);
    assert.match(entry.providerError, /missing chat model/);
    assert.doesNotMatch(entry.providerError, /\\+40712345678|123456/);
  } finally {
    await new Promise((resolve) => server.close(resolve));
  }
});
