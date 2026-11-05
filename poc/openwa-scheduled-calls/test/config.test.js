import test from 'node:test';
import assert from 'node:assert/strict';
import { normalizeE164, normalizeStartTime, readConfig } from '../src/config.js';

const base = {
  OPENWA_SESSION_DIR: '/private/openwa/session',
  OPENWA_SESSION_KEY_DIR: '/private/openwa/keys',
  OPENWA_PUBLIC_BASE_URL: 'https://openwa.example.com',
  OPENWA_SESSION_ENCRYPTION_KEY: Buffer.alloc(32, 7).toString('base64'),
  OPENWA_PAIRING_USERNAME: 'operator',
  OPENWA_PAIRING_PASSWORD: 'a'.repeat(40),
};

test('requires HTTPS pairing URL', () => assert.throws(() => readConfig({ ...base, OPENWA_PUBLIC_BASE_URL: 'http://openwa.example.com' }), /HTTPS/));
test('requires absolute private session directory', () => assert.throws(() => readConfig({ ...base, OPENWA_SESSION_DIR: 'sessions' }), /absolute path/));
test('requires a separate encryption-key directory', () => {
  assert.throws(() => readConfig({ ...base, OPENWA_SESSION_KEY_DIR: base.OPENWA_SESSION_DIR }), /must be separate/);
  assert.throws(() => readConfig({ ...base, OPENWA_SESSION_KEY_DIR: base.OPENWA_SESSION_DIR + '/keys' }), /must be separate/);
});
test('requires session and key directories outside application root', () => assert.throws(() => readConfig({ ...base, OPENWA_APP_ROOT: '/private/openwa' }), /outside the Node application root/));
test('requires a valid 32-byte base64 encryption key', () => assert.throws(() => readConfig({ ...base, OPENWA_SESSION_ENCRYPTION_KEY: 'short' }), /32-byte/));
test('requires strong pairing password', () => assert.throws(() => readConfig({ ...base, OPENWA_PAIRING_PASSWORD: 'weak' }), /32 characters/));
test('accepts valid config and resolves separate private paths', () => {
  const config = readConfig(base);
  assert.equal(config.sessionDataPath, '/private/openwa/session/session.data.json');
  assert.equal(config.encryptionKeyFile, '/private/openwa/keys/session-encryption.key');
  assert.equal(config.encryptionKey.length, 32);
});
test('validates E.164 participant numbers', () => {
  assert.equal(normalizeE164('+40712345678', 'Customer'), '+40712345678');
  assert.throws(() => normalizeE164('0712345678', 'Customer'), /E.164/);
});
test('requires future start time with a five-minute margin', () => {
  assert.throws(() => normalizeStartTime('2020-01-01T10:00:00Z'), /five minutes/);
  assert.ok(normalizeStartTime('2026-11-06T10:00:00Z') > Date.now());
});
