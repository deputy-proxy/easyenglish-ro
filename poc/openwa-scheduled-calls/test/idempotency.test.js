import test from 'node:test';
import assert from 'node:assert/strict';
import { decideEventRequest, hashRequest } from '../src/idempotency.js';
const key = 'request-key-00000001', payload = { booking: '123' }, hash = hashRequest(payload);
test('first idempotency key can create an event', () => assert.deepEqual(decideEventRequest([], key, hash), { type: 'create' }));
test('same key and same payload replays a completed event', () => { const record = { idempotencyKey: key, requestHash: hash, status: 'scheduled', providerEventId: 'event-12345678' }; assert.deepEqual(decideEventRequest([record], key, hash), { type: 'replay', record }); });
test('same key with a different payload is a conflict', () => { const record = { idempotencyKey: key, requestHash: hash, status: 'scheduled' }; assert.deepEqual(decideEventRequest([record], key, hashRequest({ booking: '456' })), { type: 'key_conflict' }); });
test('same key does not repeat an uncertain provider submission', () => { const record = { idempotencyKey: key, requestHash: hash, status: 'submission_unconfirmed' }; assert.deepEqual(decideEventRequest([record], key, hash), { type: 'pending', record }); });
