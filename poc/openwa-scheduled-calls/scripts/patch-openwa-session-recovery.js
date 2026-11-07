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
const pageDiagnosticScript = String.raw`(() => {
  const diagnostic = globalThis.OpenWA_PortableSession?.startupDiagnostic?.();
  if (!diagnostic) return null;
  const clean = (value, limit = 120) => typeof value === 'string'
    ? value.replace(/[^A-Za-z0-9_$ .<>-]/g, '').slice(0, limit) : null;
  const basename = value => typeof value === 'string'
    ? value.split('/').pop().split(/[?#]/)[0].replace(/[^A-Za-z0-9_.-]/g, '').slice(0, 80) : null;
  const describeFrame = frame => {
    if (typeof frame === 'string') {
      const text = frame.trim().startsWith('at ') ? frame.trim().slice(3) : frame.trim();
      const open = text.lastIndexOf('(');
      const location = open >= 0 && text.endsWith(')') ? text.slice(open + 1, -1) : text;
      const parts = location.split(':');
      const column = Number(parts.pop());
      const line = Number(parts.pop());
      const file = parts.join(':');
      return Number.isSafeInteger(line) && Number.isSafeInteger(column) && file
        ? { functionName: open >= 0 ? clean(text.slice(0, open).trim()) : null, file: basename(file), line, column }
        : { frameType: 'string', parseable: false };
    }
    if (!frame || typeof frame !== 'object') return { frameType: typeof frame };
    const safe = {};
    const methods = { functionName: ['getFunctionName', 'getMethodName'], file: ['getFileName', 'getScriptNameOrSourceURL'], line: ['getLineNumber'], column: ['getColumnNumber'] };
    for (const [target, names] of Object.entries(methods)) {
      for (const name of names) {
        try {
          const value = typeof frame[name] === 'function' ? frame[name]() : undefined;
          if (target === 'file' && typeof value === 'string') safe.file = basename(value);
          else if (target === 'functionName' && typeof value === 'string') safe.functionName = clean(value);
          else if ((target === 'line' || target === 'column') && Number.isSafeInteger(value) && value >= 0) safe[target] = value;
          if (safe[target] !== undefined) break;
        } catch {}
      }
    }
    for (const key of ['functionName', 'methodName', 'name']) if (safe.functionName === undefined && typeof frame[key] === 'string') safe.functionName = clean(frame[key]);
    for (const key of ['fileName', 'file', 'sourceURL']) if (safe.file === undefined && typeof frame[key] === 'string') safe.file = basename(frame[key]);
    for (const key of ['lineNumber', 'line']) if (safe.line === undefined && Number.isSafeInteger(frame[key]) && frame[key] >= 0) safe.line = frame[key];
    for (const key of ['columnNumber', 'column']) if (safe.column === undefined && Number.isSafeInteger(frame[key]) && frame[key] >= 0) safe.column = frame[key];
    if (Object.keys(safe).length) return safe;
    return { frameType: 'object', properties: Object.getOwnPropertyNames(frame).filter(key => /^[A-Za-z][A-Za-z0-9_]{0,31}$/.test(key)).slice(0, 8) };
  };
  const describe = value => {
    if (value == null) return null;
    const message = typeof value === 'string' ? value : typeof value.message === 'string' ? value.message : String(value);
    const frames = value.frames;
    // Never log frame source text, URLs, query strings, arguments, or arbitrary properties.
    const frameValues = Array.isArray(frames) ? frames.slice(0, 8)
      : frames && typeof frames === 'object' ? Object.keys(frames).slice(0, 8).map(key => frames[key]) : [];
    const stackFrames = typeof value.stack === 'string' ? value.stack.split(String.fromCharCode(10)).slice(0, 8).map(describeFrame) : [];
    return {
      errorType: typeof value === 'object' && typeof value.name === 'string' ? clean(value.name, 40) : typeof value,
      errorCode: typeof value === 'object' && typeof value.code === 'string' && /^[A-Z][A-Z0-9_]{2,80}$/.test(value.code) ? value.code : null,
      errorMessage: message.replace(/[^A-Za-z0-9 _.:()-]/g, '?').slice(0, 160),
      errorProperties: typeof value === 'object' ? Object.getOwnPropertyNames(value).filter(key => /^[A-Za-z][A-Za-z0-9_]{0,31}$/.test(key)).slice(0, 8) : [],
      framesType: Array.isArray(frames) ? 'array' : frames === null ? 'null' : typeof frames,
      frameCount: Array.isArray(frames) ? frames.length : frameValues.length,
      frames: frameValues.map(describeFrame),
      stackFrames,
    };
  };
  return {
    phase: diagnostic.phase,
    diagnosticProperties: Object.getOwnPropertyNames(diagnostic).filter(key => /^[A-Za-z][A-Za-z0-9_]{0,31}$/.test(key)).slice(0, 12),
    error: describe(diagnostic.error),
    commitError: describe(diagnostic.commitError),
  };
})()`;
const diagnosticPatch = `const startupDiagnostic = await this.page.evaluateScript(${JSON.stringify(pageDiagnosticScript)}).catch(() => null);
      this.logger.warn('portable_session_startup_diagnostic', {
        phase: typeof startupDiagnostic?.phase === 'string' && /^[a-z][a-z0-9_-]{0,40}$/.test(startupDiagnostic.phase) ? startupDiagnostic.phase : 'unknown',
        error: startupDiagnostic?.error ?? null,
        commitError: startupDiagnostic?.commitError ?? null,
      });
      `;

const cleanupPatch = `/* openwa_detached_frame_cleanup_v1 */
async resetPageRuntimeBridgeState(reason) {
  const page = this.page;
  if (!page || page.isClosed?.()) return;
  const frame = page.mainFrame?.();
  const rawFrame = frame?.unwrap?.();
  if (typeof rawFrame?.isDetached === "function" && rawFrame.isDetached()) return;
  const cleanupPromise = page.evaluate(({ reason: resetReason }) => {
    const root = globalThis;
    const state = root.__OPENWA_RUNTIME_BRIDGE__;
    if (!state) return;
    for (const listener of Object.values(state.listeners ?? {})) try { listener.dispose?.(); } catch {}
    state.runtimeId = null;
    state.runtimeRef = null;
    state.listeners = {};
    state.lastResetAt = Date.now();
    state.lastResetReason = resetReason;
    root.__OPENWA_RUNTIME_BRIDGE__ = state;
  }, { reason });
  try {
    let cleanupError;
    const guardedCleanupPromise = cleanupPromise.catch(error => { cleanupError = error; });
    await Promise.race([guardedCleanupPromise, new Promise((_, reject) => {
      setTimeout(() => reject(new Error("runtime_bridge_reset_timeout:" + this.runtimeBridgeResetTimeoutMs)), this.runtimeBridgeResetTimeoutMs);
    })]);
    if (cleanupError) throw cleanupError;
  } catch (error) {
    const message = error instanceof Error ? error.message : String(error);
    if (message.includes("detached Frame") || message.includes("Execution context was destroyed") || message.includes("Cannot find context with specified id")) {
      this.logger.debug("injection_controller_runtime_bridge_reset_skipped", { reason, code: "PAGE_CONTEXT_CHANGED" });
      return;
    }
    this.logger.warn("injection_controller_runtime_bridge_reset_failed", { reason, error: message.slice(0, 160) });
  }
}
`;

for (const filename of ['index.cjs', 'index.mjs']) {
  const target = path.join(packageRoot, 'dist', filename);
  let source = await readFile(target, 'utf8');
  if (!source.includes(safe)) {
    if (!source.includes(original)) throw new Error(`Expected OpenWA recovery branch not found in ${filename}; refusing an unsafe install.`);
    source = source.replace(original, safe);
  }
  // OpenWA 5.10.0 may ship either its native minimal logger or an older
  // diagnostic patch. Handle both shapes and always install our versioned
  // sanitised frame logger. The native marker alone is not evidence of this patch.
  if (!source.includes('openwa_restore_frames_v2')) {
    const oldDiagnosticStart = source.indexOf('const startupDiagnostic = await this.page.evaluateScript(');
    const loggerMarker = 'this.logger.warn("portable_session_bootstrap_failed"';
    const loggerStart = source.indexOf(loggerMarker, Math.max(0, oldDiagnosticStart));
    if (loggerStart < 0) {
      throw new Error(`Expected OpenWA bootstrap failure logger not found in ${filename}; refusing an unsafe diagnostic patch.`);
    }
    if (oldDiagnosticStart >= 0) {
      const loggerEnd = source.indexOf(');', loggerStart) + 2;
      if (loggerEnd < 2) throw new Error(`Could not locate the end of the OpenWA diagnostic block in ${filename}.`);
      source = source.slice(0, oldDiagnosticStart) + `/* openwa_restore_frames_v2 */\n      ${diagnosticPatch}this.logger.warn("portable_session_bootstrap_failed", { code: /^[A-Z_]+$/.test(portableFailure) ? portableFailure : "SESSION_STARTUP_FAILED" });` + source.slice(loggerEnd);
    } else {
      source = source.slice(0, loggerStart) + `/* openwa_restore_frames_v2 */\n      ${diagnosticPatch}` + source.slice(loggerStart);
    }
  }
  if (!source.includes('openwa_detached_frame_cleanup_v1')) {
    const cleanupStart = source.indexOf('\tasync resetPageRuntimeBridgeState(reason) {');
    const cleanupEndMarker = '\n\trefreshPersistentRegistrationState()';
    const cleanupEnd = source.indexOf(cleanupEndMarker, cleanupStart);
    if (cleanupStart < 0 || cleanupEnd < 0) {
      throw new Error(`Expected OpenWA runtime bridge cleanup method not found in ${filename}; refusing an unsafe cleanup patch.`);
    }
    source = source.slice(0, cleanupStart) + cleanupPatch + source.slice(cleanupEnd + 1);
  }
  await writeFile(target, source, 'utf8');
}
console.log('Applied OpenWA checkpoint-preserving recovery, expanded startup diagnostics, and detached-frame cleanup patches.');
