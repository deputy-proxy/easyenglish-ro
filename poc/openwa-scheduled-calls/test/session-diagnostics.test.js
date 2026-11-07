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
    assert.equal(result.checkpoint.payloadFormat, 'invalid-payload-shape');
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

test('recognizes the exact OpenWA auth/data checkpoint shape without exposing values', async () => {
  const dir = await mkdtemp(path.join(os.tmpdir(), 'openwa-session-'));
  try {
    const file = path.join(dir, 'session.data.json');
    await writeFile(file, JSON.stringify({ auth: 'encrypted-auth', data: 'encrypted-data' }));
    const result = await inspectAndBackupSession({ sessionDataPath: file, encryptionKey: Buffer.alloc(32, 1), now: 789 });
    assert.equal(result.checkpoint.payloadFormat, 'valid-auth-data-payload');
    assert.equal(JSON.stringify(result).includes('encrypted-auth'), false);
    assert.equal(JSON.stringify(result).includes('encrypted-data'), false);
  } finally { await rm(dir, { recursive: true, force: true }); }
});

test('recognizes base64-wrapped OpenWA payloads', async () => {
  const dir = await mkdtemp(path.join(os.tmpdir(), 'openwa-session-'));
  try {
    const file = path.join(dir, 'session.data.json');
    const payload = Buffer.from(JSON.stringify({ auth: 'opaque-auth', data: 'opaque-data' })).toString('base64');
    await writeFile(file, JSON.stringify(payload));
    const result = await inspectAndBackupSession({ sessionDataPath: file, encryptionKey: Buffer.alloc(32, 2), now: 790 });
    assert.equal(result.checkpoint.payloadFormat, 'valid-auth-data-payload');
  } finally { await rm(dir, { recursive: true, force: true }); }
});

test('distinguishes invalid JSON and logout markers', async () => {
  const dir = await mkdtemp(path.join(os.tmpdir(), 'openwa-session-'));
  try {
    const file = path.join(dir, 'session.data.json');
    await writeFile(file, 'LOGGED OUT');
    const loggedOut = await inspectAndBackupSession({ sessionDataPath: file, encryptionKey: Buffer.alloc(32, 3), now: 791 });
    assert.equal(loggedOut.checkpoint.payloadFormat, 'logged-out-marker');
    await writeFile(file, '{not-json');
    const invalid = await inspectAndBackupSession({ sessionDataPath: file, encryptionKey: Buffer.alloc(32, 3), now: 792 });
    assert.equal(invalid.checkpoint.payloadFormat, 'invalid-json');
  } finally { await rm(dir, { recursive: true, force: true }); }
});
