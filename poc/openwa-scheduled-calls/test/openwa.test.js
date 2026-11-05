import test from 'node:test';
import assert from 'node:assert/strict';
import { buildOpenWaOptions } from '../src/openwa.js';
test('OpenWA uses the durable encrypted session path and does not disable session saving', () => {
  const options = buildOpenWaOptions({ sessionId: 'easyenglish', sessionDataPath: '/data/session/session.data.json', encryptionKeyFile: '/data/keys/session.key', chromeExecutable: '/usr/bin/chromium', chromeNoSandbox: true });
  assert.equal(options.sessionDataPath, '/data/session/session.data.json');
  assert.equal(options.sessionDataEncryptionKeyFile, '/data/keys/session.key');
  assert.equal(options.skipSessionSave, false);
  assert.equal(options.ephemeral, false);
  assert.equal(options.executablePath, '/usr/bin/chromium');
  assert.deepEqual(options.chromiumArgs, ['--no-sandbox', '--disable-setuid-sandbox']);
});
