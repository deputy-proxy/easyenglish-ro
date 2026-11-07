import test from 'node:test';
import assert from 'node:assert/strict';
import { createHmac } from 'node:crypto';
import { InboundMessageForwarder } from '../src/inbound-forwarder.js';

function memoryStore() {
  const records = [];
  return {
    records,
    findByKey: (key) => records.find((record) => record.idempotencyKey === key) || null,
    pendingInbound: (now) => records.filter((record) => record.direction === 'inbound'
      && record.status === 'callback_pending'
      && (!record.nextAttemptAt || new Date(record.nextAttemptAt).getTime() <= now)),
    async upsert(record) {
      const index = records.findIndex((item) => item.idempotencyKey === record.idempotencyKey);
      if (index < 0) records.push(record); else records[index] = record;
    },
  };
}

test('durably retries signed inbound callbacks and deduplicates provider message events', async () => {
  const store = memoryStore();
  const secret = 'callback-secret-'.repeat(3);
  let now = Date.parse('2026-11-07T10:00:00.000Z');
  let requests = 0;
  const signaturesValid = [];
  const forwarder = new InboundMessageForwarder({
    config: {
      messagingEnabled: true,
      inboundCallbackUrl: 'https://easyenglish.example.com/webhooks/openwa',
      inboundCallbackSecret: secret,
    },
    store,
    clock: () => now,
    logger: { warn() {} },
    async fetchImpl(_url, options) {
      requests += 1;
      signaturesValid.push(options.headers['X-OpenWA-Signature'] === 'sha256='
        + createHmac('sha256', secret).update(options.body).digest('hex'));
      return { ok: requests > 1 };
    },
  });

  const message = {
    id: { _serialized: 'wamid-inbound-123' },
    from: '40722123456@c.us',
    to: '40700000000@c.us',
    t: 1794045600,
    type: 'chat',
    body: 'STOP',
  };
  await forwarder.receive(message);
  assert.equal(store.records[0].status, 'callback_pending');
  assert.equal(store.records[0].payload.text, 'STOP');
  assert.equal(store.records[0].payload.from, '+40722123456');
  assert.equal(store.records[0].payload.to, '+40700000000');
  assert.equal(requests, 1);

  now += 11000;
  await forwarder.dispatchPending();
  assert.equal(store.records[0].status, 'forwarded');
  assert.equal(store.records[0].payload, null);
  assert.deepEqual(signaturesValid, [true, true]);

  await forwarder.receive(message);
  assert.equal(requests, 2);
  assert.equal(store.records.length, 1);
});

test('ignores outbound and group messages for inbound callback processing', async () => {
  const store = memoryStore();
  const forwarder = new InboundMessageForwarder({
    config: { messagingEnabled: true, inboundCallbackUrl: 'https://easyenglish.example.com/callback', inboundCallbackSecret: 'x'.repeat(40) },
    store,
    fetchImpl: async () => ({ ok: true }),
  });
  await forwarder.receive({ id: 'outbound', from: '40722123456@c.us', fromMe: true, body: 'test' });
  await forwarder.receive({ id: 'group', from: '123@g.us', isGroupMsg: true, body: 'test' });
  assert.equal(store.records.length, 0);
});
