import test from 'node:test';
import assert from 'node:assert/strict';
import { mkdtemp, readFile, rm } from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import { EncryptedEventStore } from '../src/event-store.js';
test('persists encrypted event metadata and restores idempotency records', async () => {
  const dir = await mkdtemp(path.join(os.tmpdir(), 'openwa-store-')), file = path.join(dir, 'events.enc'), key = Buffer.alloc(32, 8);
  try {
    const store = await new EncryptedEventStore(file, key).load();
    await store.upsert({ idempotencyKey: 'request-key-00000001', providerEventId: 'event-id-12345678', joinUrl: 'https://call.whatsapp.com/private-link', status: 'scheduled' });
    const encrypted = await readFile(file, 'utf8');
    assert.equal(encrypted.includes('private-link'), false);
    const restored = await new EncryptedEventStore(file, key).load();
    assert.equal(restored.findById('event-id-12345678').status, 'scheduled');
  } finally { await rm(dir, { recursive: true, force: true }); }
});
test('fails closed when the encrypted store cannot be decrypted', async () => {
  const dir = await mkdtemp(path.join(os.tmpdir(), 'openwa-store-')), file = path.join(dir, 'events.enc');
  try { const store = await new EncryptedEventStore(file, Buffer.alloc(32, 8)).load(); await store.upsert({ idempotencyKey: 'request-key-00000001' }); await assert.rejects(new EncryptedEventStore(file, Buffer.alloc(32, 9)).load(), /cannot be read/); }
  finally { await rm(dir, { recursive: true, force: true }); }
});
