import test from 'node:test';
import assert from 'node:assert/strict';
import { createScheduledCall, cancelScheduledCall } from '../src/events.js';

function fakeClient(overrides = {}) {
  const calls = [];
  return {
    calls,
    async getMe() { return { me: { _serialized: '40700000000@c.us' } }; },
    async sendEvent(to, event) { calls.push(['sendEvent', to, event]); return 'event-message-id-123456'; },
    async getEvent(id) { calls.push(['getEvent', id]); return { id, joinLink: 'https://call.whatsapp.com/test-link', isCanceled: false }; },
    async sendText(to, content) { calls.push(['sendText', to, content]); return 'teacher-message-id'; },
    async cancelEvent(id) { calls.push(['cancelEvent', id]); return true; },
    ...overrides,
  };
}

test('creates a 15-minute event, retrieves its link, and shares it with teacher', async () => {
  const client = fakeClient();
  const result = await createScheduledCall(client, {
    customer: '+40712345678', teacher: '+40787654321', startAt: '2026-11-06T10:00:00Z',
  }, { now: Date.parse('2026-11-05T10:00:00Z'), sleep: async () => {} });
  assert.equal(result.eventId, 'event-message-id-123456');
  assert.equal(result.endTime - result.startTime, 900);
  assert.equal(client.calls[0][1], '40712345678@c.us');
  assert.equal(client.calls[0][2].callType, 'video');
  assert.equal(client.calls[1][1], result.eventId);
  assert.equal(client.calls[2][1], '40787654321@c.us');
  assert.equal(client.calls[2][2], result.joinLink);
});

test('rejects same customer and teacher account before contacting OpenWA', async () => {
  const client = fakeClient();
  await assert.rejects(createScheduledCall(client, {
    customer: '+40712345678', teacher: '+40712345678', startAt: '2026-11-06T10:00:00Z',
  }, { now: Date.parse('2026-11-05T10:00:00Z') }), /different WhatsApp accounts/);
  assert.equal(client.calls.length, 0);
});

test('rejects a participant that is also the paired OpenWA creator', async () => {
  const client = fakeClient();
  await assert.rejects(createScheduledCall(client, {
    customer: '+40700000000', teacher: '+40787654321', startAt: '2026-11-06T10:00:00Z',
  }, { now: Date.parse('2026-11-05T10:00:00Z') }), /three distinct WhatsApp accounts/);
  assert.equal(client.calls.length, 0);
});

test('does not retry event creation if join URL is not returned', async () => {
  let submissions = 0;
  const client = fakeClient({
    async sendEvent() { submissions += 1; return 'event-message-id-123456'; },
    async getEvent() { return null; },
  });
  await assert.rejects(createScheduledCall(client, {
    customer: '+40712345678', teacher: '+40787654321', startAt: '2026-11-06T10:00:00Z',
  }, { now: Date.parse('2026-11-05T10:00:00Z'), sleep: async () => {} }), (error) => error.uncertain === true);
  assert.equal(submissions, 1);
});

test('validates and invokes event cancellation', async () => {
  await assert.rejects(cancelScheduledCall({ cancelEvent: async () => true }, 'x'), /valid OpenWA event ID/);
  let cancelledId = null;
  const result = await cancelScheduledCall({ async cancelEvent(id) { cancelledId = id; return true; } }, 'event-message-id-123456');
  assert.equal(result, true);
  assert.equal(cancelledId, 'event-message-id-123456');
});

test('treats a sendEvent timeout as uncertain and does not attempt another submission', async () => {
  let submissions = 0;
  const client = fakeClient({ async sendEvent() { submissions += 1; throw new Error('provider timeout'); } });
  await assert.rejects(createScheduledCall(client, {
    customer: '+40712345678', teacher: '+40787654321', startAt: '2026-11-06T10:00:00Z',
  }, { now: Date.parse('2026-11-05T10:00:00Z'), sleep: async () => {} }), (error) => error.uncertain === true);
  assert.equal(submissions, 1);
});
