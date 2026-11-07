import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

test('production install applies a version-pinned checkpoint-preserving OpenWA recovery patch', async () => {
  const packageJson = JSON.parse(await readFile(new URL('../package.json', import.meta.url), 'utf8'));
  assert.equal(packageJson.dependencies['@open-wa/wa-automate'], '5.10.0');
  assert.equal(packageJson.scripts.postinstall, 'node scripts/patch-openwa-session-recovery.js');

  const patch = await readFile(new URL('../scripts/patch-openwa-session-recovery.js', import.meta.url), 'utf8');
  assert.match(patch, /packageInfo\.version !== '5\.10\.0'/);
  assert.match(patch, /SESSION_RESTORE_UNAVAILABLE_CHECKPOINT_PRESERVED/);
  assert.match(patch, /refusing an unsafe install/);
  assert.match(patch, /resetSessionForPairing/);
  assert.match(patch, /errorMessage/);
  assert.match(patch, /pageDiagnosticScript/);
  assert.match(patch, /evaluateScript\(\$\{JSON\.stringify\(pageDiagnosticScript\)\}/);
  assert.match(patch, /Object\.getOwnPropertyNames\(value\)/);
  assert.match(patch, /frames: Array\.isArray\(value\.frames\)/);
  assert.match(patch, /frameCount: Array\.isArray\(value\.frames\)/);
  assert.match(patch, /openwa_restore_frames_v2/);
  assert.match(patch, /source\.indexOf\('const startupDiagnostic = await this\.page\.evaluateScript\('/);
  assert.match(patch, /versioned/);
  assert.match(patch, /loggerMarker/);
  assert.match(patch, /Never log frame source text, URLs, query strings, arguments, or arbitrary properties/);
  assert.match(patch, /SESSION_RESTORE_UNAVAILABLE_CHECKPOINT_PRESERVED/);

  for (const filename of ['index.cjs', 'index.mjs']) {
    const installed = await readFile(new URL(`../node_modules/@open-wa/core/dist/${filename}`, import.meta.url), 'utf8');
    assert.match(installed, /openwa_restore_frames_v2/);
    assert.match(installed, /frameCount: Array\.isArray\(value\.frames\)/);
    assert.match(installed, /SESSION_RESTORE_UNAVAILABLE_CHECKPOINT_PRESERVED/);
  }
});

test('startup failure logging exposes only a safe restoration error code', async () => {
  const server = await readFile(new URL('../src/server.js', import.meta.url), 'utf8');
  assert.match(server, /SESSION_RESTORE_UNAVAILABLE_CHECKPOINT_PRESERVED/);
  assert.match(server, /openwa\.session\.restore_failed/);
  assert.doesNotMatch(server, /log\('error', 'openwa\.session\.start_failed', \{ error: error \}\)/);
});
