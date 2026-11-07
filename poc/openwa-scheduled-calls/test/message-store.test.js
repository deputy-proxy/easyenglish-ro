import test from 'node:test';
import assert from 'node:assert/strict';
import { mkdtemp, readFile, rm } from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import { EncryptedMessageStore } from '../src/message-store.js';

const key = Buffer.alloc(32, 7);

test('message store encrypts records and quarantines in-flight outbound submissions after restart', async () => {
  const directory = await mkdtemp(path.join(os.tmpdir(), 'openwa-message-store-'));
  const file = path.join(directory, 'messages.enc');
  try {
    const first = await new EncryptedMessageStore(file, key).load();
    await first.upsert({ idempotencyKey: 'easyenglish-delivery-12', direction: 'outbound', status: 'sending', requestHash: 'hash', toHash: 'phone-hash', textHash: 'text-hash', attempts: 1 });
    const encrypted = await readFile(file, 'utf8');
    assert.equal(encrypted.includes('easyenglish-delivery-12'), false);
    const recovered = await new EncryptedMessageStore(file, key).load();
    assert.equal(recovered.findByKey('easyenglish-delivery-12').status, 'submission_unconfirmed');
    assert.equal(recovered.findByKey('easyenglish-delivery-12').textHash, 'text-hash');
  } finally {
    await rm(directory, { recursive: true, force: true });
  }
});
