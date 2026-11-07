import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import vm from 'node:vm';

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
  assert.match(patch, /framesType: Array\.isArray\(frames\)/);
  assert.match(patch, /frameCount: Array\.isArray\(frames\)/);
  assert.match(patch, /openwa_restore_frames_v2/);
  assert.match(patch, /getFunctionName/);
  assert.match(patch, /stackFrames/);
  assert.match(patch, /openwa_detached_frame_cleanup_v1/);
  assert.match(patch, /PAGE_CONTEXT_CHANGED/);
  assert.match(patch, /openwa_identity_set_me_pn_compat_v1/);
  assert.match(patch, /_0x56aad0\.setMePn/);
  assert.match(patch, /source\.indexOf\('const startupDiagnostic = await this\.page\.evaluateScript\('/);
  assert.match(patch, /versioned/);
  assert.match(patch, /loggerMarker/);
  assert.match(patch, /Never log frame source text, URLs, query strings, arguments, or arbitrary properties/);
  assert.match(patch, /SESSION_RESTORE_UNAVAILABLE_CHECKPOINT_PRESERVED/);

  for (const filename of ['index.cjs', 'index.mjs']) {
    const installed = await readFile(new URL(`../node_modules/@open-wa/core/dist/${filename}`, import.meta.url), 'utf8');
    assert.match(installed, /openwa_restore_frames_v2/);
    assert.match(installed, /frameCount: Array\.isArray\(frames\)/);
    assert.match(installed, /SESSION_RESTORE_UNAVAILABLE_CHECKPOINT_PRESERVED/);
    assert.match(installed, /openwa_detached_frame_cleanup_v1/);
    assert.match(installed, /PAGE_CONTEXT_CHANGED/);
  }
});

test('startup failure logging exposes only a safe restoration error code', async () => {
  const server = await readFile(new URL('../src/server.js', import.meta.url), 'utf8');
  assert.match(server, /SESSION_RESTORE_UNAVAILABLE_CHECKPOINT_PRESERVED/);
  assert.match(server, /openwa\.session\.restore_failed/);
  assert.doesNotMatch(server, /log\('error', 'openwa\.session\.start_failed', \{ error: error \}\)/);
});


test('startup diagnostics safely extract bounded frame metadata', async () => {
  const patch = await readFile(new URL('../scripts/patch-openwa-session-recovery.js', import.meta.url), 'utf8');
  const template = patch.match(/const pageDiagnosticScript = String\.raw`([\s\S]*?)`;/)?.[1];
  assert.ok(template);
  const diagnosticScript = vm.runInNewContext('String.raw`' + template + '`');
  const result = vm.runInNewContext(diagnosticScript, {
    OpenWA_PortableSession: {
      startupDiagnostic: () => ({
        phase: 'launch-failed',
        error: Object.assign(new TypeError('restore failed'), {
          code: 'UNCLASSIFIED_STARTUP_ERROR',
          frames: [{
            getFunctionName: () => 'restoreAuth',
            getFileName: () => '/private/pre_init.js',
            getLineNumber: () => 42,
            getColumnNumber: () => 7,
          }],
        }),
      }),
    },
  });
  assert.equal(result.phase, 'launch-failed');
  assert.deepEqual({ ...result.error.frames[0] }, { functionName: 'restoreAuth', file: 'pre_init.js', line: 42, column: 7 });
  assert.equal(result.error.errorMessage, 'restore failed');
});


test('detached-frame cleanup races are skipped without failing runtime cleanup', async () => {
  const patch = await readFile(new URL('../scripts/patch-openwa-session-recovery.js', import.meta.url), 'utf8');
  const template = patch.match(/const cleanupPatch = `([\s\S]*?)`;/)?.[1];
  assert.ok(template);
  const PatchedTransport = new Function(`return class PatchedTransport { ${template} }`)();
  const calls = [];
  const transport = new PatchedTransport();
  transport.runtimeBridgeResetTimeoutMs = 100;
  transport.logger = {
    debug: (...args) => calls.push(['debug', ...args]),
    warn: (...args) => calls.push(['warn', ...args]),
  };
  transport.page = {
    isClosed: () => false,
    mainFrame: () => ({ unwrap: () => ({ isDetached: () => false }) }),
    evaluate: () => Promise.reject(new Error("Attempted to use detached Frame 'frame-id'")),
  };
  await transport.resetPageRuntimeBridgeState('runtime_cleanup:test');
  assert.equal(calls.length, 1);
  assert.equal(calls[0][0], 'debug');
  assert.equal(calls[0][1], 'injection_controller_runtime_bridge_reset_skipped');
  assert.deepEqual(calls[0][2], { reason: 'runtime_cleanup:test', code: 'PAGE_CONTEXT_CHANGED' });
});


test('portable identity import uses the current WhatsApp setMePn API when legacy setMe is absent', async () => {
  const preInit = await readFile(new URL('../node_modules/@open-wa/core/dist/transport/assets/pre_init.js', import.meta.url), 'utf8');
  assert.match(preInit, /openwa_identity_set_me_pn_compat_v1/);
  assert.match(preInit, /_0x56aad0\.setMePn/);
  assert.doesNotMatch(preInit, /openwa_identity_import_diagnostic_v1/);
  assert.doesNotMatch(preInit, /openwa_identity_import_set_me_failed_v1/);
});


test('identity compatibility prefers setMe and falls back to setMePn without changing the receiver', async () => {
  const patch = await readFile(new URL('../scripts/patch-openwa-session-recovery.js', import.meta.url), 'utf8');
  const template = patch.match(/const identitySetMeCompatCall = `([\s\S]*?)`;/)?.[1];
  assert.ok(template);
  const invoke = new Function('_0x56aad0', '_0x1cb772', `return ${template.replace(/^,/, '')};`);
  const identity = { wid: 'test' };
  const legacy = { setMe(value) { this.method = 'setMe'; this.value = value; }, setMePn() { throw new Error('should not be used'); } };
  invoke(legacy, identity);
  assert.equal(legacy.method, 'setMe');
  assert.equal(legacy.value, identity);
  const current = { setMePn(value) { this.method = 'setMePn'; this.value = value; } };
  invoke(current, identity);
  assert.equal(current.method, 'setMePn');
  assert.equal(current.value, identity);
  assert.throws(() => invoke({}, identity), /OPENWA_IDENTITY_SET_ME_METHOD_UNAVAILABLE/);
});
