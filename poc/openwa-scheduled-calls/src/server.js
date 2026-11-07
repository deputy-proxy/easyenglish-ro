import { constants } from 'node:fs';
import { mkdir, writeFile, chmod, copyFile, lstat, rm } from 'node:fs/promises';
import { readConfig } from './config.js';
import { startOpenWa } from './openwa.js';
import { OpenWaProvider } from './provider.js';
import { EncryptedEventStore } from './event-store.js';
import { EncryptedMessageStore } from './message-store.js';
import { InboundMessageForwarder } from './inbound-forwarder.js';
import { createApp } from './app.js';
import { applyConnectionState } from './lifecycle.js';
import { sendDisconnectionAlert } from './session-alerts.js';
import { inspectAndBackupSession } from './session-diagnostics.js';
const config = readConfig();
await mkdir(config.sessionDir, { recursive: true, mode: 0o700 }); await chmod(config.sessionDir, 0o700);
await mkdir(config.sessionKeyDir, { recursive: true, mode: 0o700 }); await chmod(config.sessionKeyDir, 0o700);
await writeFile(config.encryptionKeyFile, process.env.OPENWA_SESSION_ENCRYPTION_KEY, { mode: 0o600 }); await chmod(config.encryptionKeyFile, 0o600);
const sessionDiagnostics = await inspectAndBackupSession({ sessionDataPath: config.sessionDataPath, encryptionKey: config.encryptionKey });
logStartupSessionDiagnostics(sessionDiagnostics);
const store = await new EncryptedEventStore(config.eventStorePath, config.encryptionKey).load();
const messageStore = config.messagingEnabled ? await new EncryptedMessageStore(config.messageStorePath, config.encryptionKey).load() : null;
const state = { phase: config.allowLiveTest ? 'starting' : 'disabled', qr: null, provider: null, client: null, disconnectionAlerted: false, startedAt: new Date().toISOString(), metrics: { created: 0, providerErrors: 0, reconnects: 0 } };
const log = (level, event, fields = {}) => console[level](JSON.stringify({ timestamp: new Date().toISOString(), level, event, ...fields }));
function logStartupSessionDiagnostics(diagnostics) {
  console.info(JSON.stringify({ timestamp: new Date().toISOString(), level: 'info', event: 'openwa.session.persistence_diagnostics', keyFingerprint: diagnostics.keyFingerprint, checkpoint: diagnostics.checkpoint }));
}
async function alertDisconnected(reason) {
  if (stopping || state.disconnectionAlerted) return;
  state.disconnectionAlerted = true;
  await sendDisconnectionAlert(config, reason, { logger: { info: (event, fields) => log('info', event, fields), error: (event, fields) => log('error', event, fields) } });
}
const inboundForwarder = messageStore ? new InboundMessageForwarder({ config, store: messageStore, logger: { warn: (line) => console.warn(line) } }) : null;
const app = createApp({ config, state, store, messageStore, resetSession: resetOpenWaSession });
const inboundRetryTimer = inboundForwarder ? setInterval(() => inboundForwarder.dispatchPending().catch(() => log('warn', 'openwa.inbound.retry_failed')), 10000) : null;
inboundRetryTimer?.unref();
inboundForwarder?.dispatchPending().catch(() => log('warn', 'openwa.inbound.startup_retry_failed'));
const server = app.listen(config.port, config.host, () => console.log('OpenWA call-event service listening on ' + config.host + ':' + config.port));
let stopping = false;
async function stop() {
  if (stopping) return;
  stopping = true; state.phase = 'stopping';
  if (inboundRetryTimer) clearInterval(inboundRetryTimer);
  const httpClosed = new Promise((resolve) => server.close(resolve));
  if (state.client?.stop) await state.client.stop('service_shutdown').catch(() => {});
  await httpClosed;
  process.exit(0);
}
process.on('SIGTERM', stop); process.on('SIGINT', stop);
const openWaCallbacks = {
  onQr: (qr) => { state.qr = qr; state.phase = 'qr_ready'; },
  onQrScanned: () => { state.qr = null; state.phase = 'authenticating'; },
  onQrExpired: () => { state.qr = null; state.phase = 'starting'; },
  onAuthenticated: (client) => {
    state.disconnectionAlerted = false;
    state.client = client;
    inboundForwarder?.setPhoneResolver(async (linkedId) => {
      const contact = await client.getContact?.(linkedId);
      const candidates = [contact?.phone, contact?.number, contact?.phoneNumber, contact?.id];
      for (const candidate of candidates) {
        if (typeof candidate !== 'string' || /@lid$/i.test(candidate)) continue;
        const match = candidate.match(/^([1-9]\d{7,14})(?:@(?:c\.us|s\.whatsapp\.net))?$/);
        if (match) return match[1];
      }
      return null;
    });
    client.onMessage?.((message) => inboundForwarder?.receive(message).catch(() => log('warn', 'openwa.inbound.receive_failed')));
    state.provider = new OpenWaProvider(client);
    state.qr = null;
    state.phase = 'authenticated';
    log('info', 'openwa.session.authenticated');
  },
  onConnectionState: (connectionState) => {
    const previous = state.phase;
    applyConnectionState(state, connectionState);
    if (connectionState === 'DISCONNECTED') { log('warn', 'openwa.session.disconnected'); if (state.client) void alertDisconnected('connection_lost'); }
    else if (connectionState === 'STOPPED') { log('warn', 'openwa.session.stopped'); if (state.client) void alertDisconnected('connection_lost'); }
    else if (connectionState === 'READY') { state.disconnectionAlerted = false; if (previous !== 'disconnected') log('info', 'openwa.session.ready'); }
    else if (connectionState === 'STARTING' || connectionState === 'AUTHENTICATING') log('info', 'openwa.session.state_changed', { state: connectionState });
  },
  onReconnectStart: () => { state.phase = 'disconnected'; log('warn', 'openwa.session.reconnecting'); if (state.client) void alertDisconnected('connection_lost'); },
  onReconnectRecovered: (downtimeMs) => { state.metrics.reconnects += 1; log('info', 'openwa.session.reconnected', { downtimeMs: Number.isFinite(downtimeMs) ? downtimeMs : null }); },
  onLogout: () => { state.phase = 'logged_out'; state.provider = null; log('warn', 'openwa.session.logged_out'); void alertDisconnected('logged_out'); },
};
let openWaStartPromise = null;
async function startOpenWaClient() {
  openWaStartPromise = startOpenWa(config, openWaCallbacks).catch((error) => {
    state.phase = 'failed';
    void alertDisconnected('startup_failed');
    const code = error instanceof Error && /^SESSION_RESTORE_UNAVAILABLE_CHECKPOINT_PRESERVED$/.test(error.message)
      ? error.message : 'OPENWA_START_FAILED';
    log('error', code === 'SESSION_RESTORE_UNAVAILABLE_CHECKPOINT_PRESERVED'
      ? 'openwa.session.restore_failed' : 'openwa.session.start_failed', { code, errorType: error?.name || 'Error' });
  });
  return openWaStartPromise;
}
async function resetOpenWaSession() {
  if (state.phase === 'resetting') throw new Error('A session reset is already in progress.');
  state.phase = 'resetting';
  state.qr = null;
  state.provider = null;
  const client = state.client;
  state.client = null;
  if (client?.stop) await client.stop('manual_pairing_reset');
  let backupPath = null;
  try {
    const info = await lstat(config.sessionDataPath);
    if (!info.isFile() || info.isSymbolicLink() || info.size <= 0 || info.size > 64 * 1024 * 1024) {
      throw new Error('The saved session checkpoint is not a safe regular file; it was not changed.');
    }
    backupPath = config.sessionDataPath + '.manual-reset-' + Date.now() + '.enc';
    await copyFile(config.sessionDataPath, backupPath, constants.COPYFILE_EXCL);
    await chmod(backupPath, 0o600);
    await rm(config.sessionDataPath);
  } catch (error) {
    if (error?.code !== 'ENOENT') {
      state.phase = 'failed';
      throw error;
    }
  }
  log('warn', 'openwa.session.manual_pairing_reset', { checkpointBackedUp: Boolean(backupPath) });
  state.phase = 'starting';
  if (config.allowLiveTest) void startOpenWaClient();
  return { checkpointBackedUp: Boolean(backupPath) };
}
if (config.allowLiveTest) void startOpenWaClient();
