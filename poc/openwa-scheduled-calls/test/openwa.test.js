import test from 'node:test';
import assert from 'node:assert/strict';
import { buildOpenWaOptions, extractQrPayload } from '../src/openwa.js';

test('extracts the QR pairing string from the current OpenWA lifecycle event shape', () => {
  assert.equal(extractQrPayload({ details: { qr: 'pairing-payload' } }), 'pairing-payload');
});

test('does not accept missing or malformed QR event payloads', () => {
  assert.equal(extractQrPayload({ qr: 'legacy-shape' }), null);
  assert.equal(extractQrPayload({ details: {} }), null);
  assert.equal(extractQrPayload({ details: { qr: '  ' } }), null);
  assert.equal(extractQrPayload(null), null);
});

test('configures OpenWA compact-session persistence on the Railway volume', () => {
  const options = buildOpenWaOptions({
    sessionId: 'easyenglish-openwa-poc',
    sessionDataPath: '/data/session/session.data.json',
    encryptionKeyFile: '/data/keys/session-encryption.key',
    chromeExecutable: '/usr/bin/chromium',
    chromeNoSandbox: true,
  });
  assert.equal(options.sessionDataPath, '/data/session/session.data.json');
  assert.equal(options.sessionDataEncryptionKeyFile, '/data/keys/session-encryption.key');
  assert.equal(options.skipSessionSave, false);
  assert.equal('userDataDir' in options, false);
  assert.equal('ephemeral' in options, false);
  assert.equal(options.executablePath, '/usr/bin/chromium');
  assert.deepEqual(options.chromiumArgs, ['--no-sandbox', '--disable-setuid-sandbox']);
});
