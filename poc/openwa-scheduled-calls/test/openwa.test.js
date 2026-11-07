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

test('OpenWA uses a persistent browser profile instead of the failing portable session-file restore path', () => {
  const options = buildOpenWaOptions({ sessionId: 'easyenglish', userDataDir: '/data/profile/easyenglish', chromeExecutable: '/usr/bin/chromium', chromeNoSandbox: true });
  assert.equal(options.userDataDir, '/data/profile/easyenglish');
  assert.equal('sessionDataPath' in options, false);
  assert.equal('sessionDataEncryptionKeyFile' in options, false);
  assert.equal(options.skipSessionSave, false);
  assert.equal(options.ephemeral, false);
  assert.equal(options.executablePath, '/usr/bin/chromium');
  assert.deepEqual(options.chromiumArgs, ['--no-sandbox', '--disable-setuid-sandbox']);
});
