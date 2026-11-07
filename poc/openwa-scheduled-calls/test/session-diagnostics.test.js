import assert from 'node:assert/strict';
import { mkdtemp, readFile, readdir, rm, writeFile, stat } from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import test from 'node:test';
import { inspectAndBackupSession } from '../src/session-diagnostics.js';

test('backs up an existing encrypted checkpoint without changing its contents', async () => {
  const dir = await mkdtemp(path.join(os.tmpdir(), 'openwa-session-'));
  try {
    const file = path.join(dir, 'session.data.json');
    await writeFile(file, '{"encrypted":"opaque"}', { mode: 0o600 });
    const result = await inspectAndBackupSession({ sessionDataPath: file, encryptionKey: Buffer.alloc(32, 7), now: 123 });
    assert.equal(result.checkpoint.exists, true);
    assert.equal(result.checkpoint.sizeBytes, 22);
    assert.equal(await readFile(result.checkpoint.backupPath, 'utf8'), '{"encrypted":"opaque"}');
    assert.equal((await stat(result.checkpoint.backupPath)).mode & 0o777, 0o600);
    assert.equal(result.keyFingerprint.length, 16);
    assert.deepEqual((await readdir(dir)).sort(), ['session.data.json', 'session.data.json.startup-backup-123.enc']);
  } finally { await rm(dir, { recursive: true, force: true }); }
});

test('reports a missing checkpoint without creating one', async () => {
  const dir = await mkdtemp(path.join(os.tmpdir(), 'openwa-session-'));
  try {
    const file = path.join(dir, 'missing.data.json');
    const result = await inspectAndBackupSession({ sessionDataPath: file, encryptionKey: Buffer.alloc(32, 8) });
    assert.equal(result.checkpoint.exists, false);
    assert.deepEqual(await readdir(dir), []);
  } finally { await rm(dir, { recursive: true, force: true }); }
});

test('never overwrites an existing startup backup', async () => {
  const dir = await mkdtemp(path.join(os.tmpdir(), 'openwa-session-'));
  try {
    const file = path.join(dir, 'session.data.json');
    await writeFile(file, 'checkpoint');
    const backup = file + '.startup-backup-456.enc';
    await writeFile(backup, 'previous-backup');
    await assert.rejects(inspectAndBackupSession({ sessionDataPath: file, encryptionKey: Buffer.alloc(32, 9), now: 456 }), { code: 'EEXIST' });
    assert.equal(await readFile(backup, 'utf8'), 'previous-backup');
  } finally { await rm(dir, { recursive: true, force: true }); }
});
