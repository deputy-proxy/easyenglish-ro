import { readFile, writeFile } from 'node:fs/promises';
import { createRequire } from 'node:module';
import path from 'node:path';

const require = createRequire(import.meta.url);
const entryPath = require.resolve('@open-wa/core');
const packageRoot = path.resolve(path.dirname(entryPath), '..');
const packageInfo = JSON.parse(await readFile(path.join(packageRoot, 'package.json'), 'utf8'));
if (packageInfo.version !== '5.10.0') {
  throw new Error(`Review the OpenWA session recovery patch for @open-wa/core ${packageInfo.version} before installing.`);
}

const original = `if (this.portable.hasCheckpoint) {
\t\t\t\tawait this.resetSessionForPairing(portableFailure);
\t\t\t\tawait this.navigate();
\t\t\t\treturn true;
\t\t\t}`;
const safe = `if (this.portable.hasCheckpoint) {
\t\t\t\t// Preserve the only durable credentials. Pairing reset is an explicit operator action.
\t\t\t\tthrow new Error("SESSION_RESTORE_UNAVAILABLE_CHECKPOINT_PRESERVED");
\t\t\t}`;
const diagnosticMarker = 'portable_session_startup_diagnostic';
const diagnosticPatch = `const startupDiagnostic = await this.page.evaluateScript('globalThis.OpenWA_PortableSession?.startupDiagnostic() ?? null').catch(() => null);
      const safeCode = value => {
        if (typeof value !== 'string') return null;
        const match = value.match(/\\b[A-Z][A-Z0-9_]{2,80}\\b/);
        return match ? match[0] : null;
      };
      const safeError = value => ({
        errorType: value && typeof value === 'object' && typeof value.name === 'string' ? value.name.slice(0, 40) : typeof value,
        errorCode: safeCode(typeof value === 'string' ? value : value?.code),
      });
      this.logger.warn('portable_session_startup_diagnostic', {
        phase: typeof startupDiagnostic?.phase === 'string' && /^[a-z][a-z0-9_-]{0,40}$/.test(startupDiagnostic.phase) ? startupDiagnostic.phase : 'unknown',
        error: safeError(startupDiagnostic?.error),
        commitError: safeError(startupDiagnostic?.commitError),
      });
      `;

for (const filename of ['index.cjs', 'index.mjs']) {
  const target = path.join(packageRoot, 'dist', filename);
  let source = await readFile(target, 'utf8');
  if (!source.includes(safe)) {
    if (!source.includes(original)) throw new Error(`Expected OpenWA recovery branch not found in ${filename}; refusing an unsafe install.`);
    source = source.replace(original, safe);
  }
  if (!source.includes(diagnosticMarker)) {
    const needle = /this\.logger\.warn\\((['"])portable_session_bootstrap_failed\\1,\\s*\\{/;
    if (!needle.test(source)) throw new Error(`Expected OpenWA bootstrap failure logger not found in ${filename}; refusing an unsafe diagnostic patch.`);
    source = source.replace(needle, `${diagnosticPatch}this.logger.warn("portable_session_bootstrap_failed", {`);
  }
  await writeFile(target, source, 'utf8');
}
console.log('Applied OpenWA checkpoint-preserving recovery guard and sanitised startup diagnostics.');
