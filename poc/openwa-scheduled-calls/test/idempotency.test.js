import test from 'node:test';
import assert from 'node:assert/strict';
import { decideEventRequest } from '../src/idempotency.js';

test('first idempotency key can create an event', () => {
  assert.deepEqual(decideEventRequest(null, 'request-key-00000001'), { type: 'create' });
});

test('same key replays a completed event', () => {
  const response = { status: 'submitted', eventId: 'event-12345678' };
  assert.deepEqual(decideEventRequest({ idempotencyKey: 'request-key-00000001', status: 'submitted', response }, 'request-key-00000001'), { type: 'replay', response });
});

test('same key does not repeat an uncertain provider submission', () => {
  assert.deepEqual(decideEventRequest({ idempotencyKey: 'request-key-00000001', status: 'submitted_unconfirmed', eventId: 'event-12345678' }, 'request-key-00000001'), { type: 'pending', status: 'submitted_unconfirmed', eventId: 'event-12345678' });
});

test('different key is rejected while the previous event is active', () => {
  assert.deepEqual(decideEventRequest({ idempotencyKey: 'request-key-00000001', status: 'submitted', cancelled: false }, 'request-key-00000002'), { type: 'conflict' });
});

test('new key is allowed after the previous event is cancelled', () => {
  assert.deepEqual(decideEventRequest({ idempotencyKey: 'request-key-00000001', status: 'submitted', cancelled: true }, 'request-key-00000002'), { type: 'create' });
});
