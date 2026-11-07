import test from 'node:test';
import assert from 'node:assert/strict';
import { sendDisconnectionAlert } from '../src/session-alerts.js';

test('sends an authenticated disconnection callback with a unique event id', async () => {
  let request;
  const result = await sendDisconnectionAlert({
    statusCallbackUrl: 'https://easyenglish.example.test/webhooks/openwa/status',
    serviceToken: 'x'.repeat(40),
    sessionId: 'easyenglish-openwa-poc',
  }, 'connection_lost', {
    fetchImpl: async (url, options) => { request = { url, options }; return { ok: true, status: 202 }; },
    clock: () => new Date('2026-11-07T12:00:00.000Z'),
    logger: { info() {}, error() {} },
  });

  assert.equal(result, true);
  assert.equal(request.url, 'https://easyenglish.example.test/webhooks/openwa/status');
  assert.equal(request.options.headers.Authorization, `Bearer ${'x'.repeat(40)}`);
  const body = JSON.parse(request.options.body);
  assert.match(body.eventId, /^[0-9a-f-]{36}$/);
  assert.equal(body.reason, 'connection_lost');
  assert.equal(body.sessionId, 'easyenglish-openwa-poc');
  assert.equal(body.disconnectedAt, '2026-11-07T12:00:00.000Z');
});

test('retries transient callback failures and stops after success', async () => {
  let attempts = 0;
  const result = await sendDisconnectionAlert({ statusCallbackUrl: 'https://easyenglish.example.test/webhooks/openwa/status', serviceToken: 'x'.repeat(40), sessionId: 'session' }, 'logged_out', {
    fetchImpl: async () => ({ ok: ++attempts === 2, status: attempts === 1 ? 503 : 202 }),
    wait: async () => {},
    logger: { info() {}, error() {} },
  });
  assert.equal(result, true);
  assert.equal(attempts, 2);
});

test('does not retry permanent callback rejection', async () => {
  let attempts = 0;
  const result = await sendDisconnectionAlert({ statusCallbackUrl: 'https://easyenglish.example.test/webhooks/openwa/status', serviceToken: 'x'.repeat(40), sessionId: 'session' }, 'logged_out', {
    fetchImpl: async () => { attempts++; return { ok: false, status: 401 }; },
    wait: async () => {},
    logger: { info() {}, error() {} },
  });
  assert.equal(result, false);
  assert.equal(attempts, 1);
});
