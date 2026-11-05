import test from 'node:test';
import assert from 'node:assert/strict';
import { encryptJson, decryptJson } from '../src/secure-store.js';

const key = Buffer.alloc(32, 9);

test('encrypts and decrypts JSON with AES-256-GCM', () => {
  const value = { eventId: 'event-id', joinLink: 'https://call.whatsapp.com/private' };
  const encrypted = encryptJson(value, key);
  assert.equal(encrypted.includes('call.whatsapp.com'), false);
  assert.deepEqual(decryptJson(encrypted, key), value);
});

test('rejects encrypted records modified after creation', () => {
  const encrypted = JSON.parse(encryptJson({ secret: 'value' }, key));
  encrypted.data = Buffer.from('tampered').toString('base64');
  assert.throws(() => decryptJson(JSON.stringify(encrypted), key));
});

test('rejects incorrect key sizes', () => {
  assert.throws(() => encryptJson({ value: true }, Buffer.alloc(16)), /32-byte/);
  assert.throws(() => decryptJson('{}', Buffer.alloc(16)), /32-byte/);
});
