import { mkdir, writeFile, chmod } from 'node:fs/promises';
import { readConfig } from './config.js';
import { startOpenWa } from './openwa.js';
import { OpenWaProvider } from './provider.js';
import { EncryptedEventStore } from './event-store.js';
import { createApp } from './app.js';
import { applyConnectionState } from './lifecycle.js';
const config = readConfig();
await mkdir(config.sessionDir, { recursive: true, mode: 0o700 }); await chmod(config.sessionDir, 0o700);
await mkdir(config.sessionKeyDir, { recursive: true, mode: 0o700 }); await chmod(config.sessionKeyDir, 0o700);
await writeFile(config.encryptionKeyFile, process.env.OPENWA_SESSION_ENCRYPTION_KEY, { mode: 0o600 }); await chmod(config.encryptionKeyFile, 0o600);
const store = await new EncryptedEventStore(config.eventStorePath, config.encryptionKey).load();
const state = { phase: config.allowLiveTest ? 'starting' : 'disabled', qr: null, provider: null, client: null, startedAt: new Date().toISOString(), metrics: { created: 0, providerErrors: 0, reconnects: 0 } };
const log = (level, event, fields = {}) => console[level](JSON.stringify({ timestamp: new Date().toISOString(), level, event, ...fields }));
const app = createApp({ config, state, store });
const server = app.listen(config.port, config.host, () => console.log('OpenWA call-event service listening on ' + config.host + ':' + config.port));
let stopping = false;
async function stop() {
  if (stopping) return;
  stopping = true; state.phase = 'stopping';
  const httpClosed = new Promise((resolve) => server.close(resolve));
  if (state.client?.stop) await state.client.stop('service_shutdown').catch(() => {});
  await httpClosed;
  process.exit(0);
}
process.on('SIGTERM', stop); process.on('SIGINT', stop);
if (config.allowLiveTest) {
  startOpenWa(config, {
    onQr: (qr) => { state.qr = qr; state.phase = 'qr_ready'; },
    onQrScanned: () => { state.qr = null; state.phase = 'authenticating'; },
    onQrExpired: () => { state.qr = null; state.phase = 'starting'; },
    onAuthenticated: (client) => { state.client = client; state.provider = new OpenWaProvider(client); state.qr = null; state.phase = 'authenticated'; log('info', 'openwa.session.authenticated'); },
    onConnectionState: (connectionState) => {
      const previous = state.phase;
      applyConnectionState(state, connectionState);
      if (connectionState === 'DISCONNECTED') log('warn', 'openwa.session.disconnected');
      else if (connectionState === 'STOPPED') log('warn', 'openwa.session.stopped');
      else if (connectionState === 'STARTING' || connectionState === 'AUTHENTICATING') log('info', 'openwa.session.state_changed', { state: connectionState });
      else if (connectionState === 'READY' && previous !== 'disconnected') log('info', 'openwa.session.ready');
    },
    onReconnectStart: () => { state.phase = 'disconnected'; log('warn', 'openwa.session.reconnecting'); },
    onReconnectRecovered: (downtimeMs) => { state.metrics.reconnects += 1; log('info', 'openwa.session.reconnected', { downtimeMs: Number.isFinite(downtimeMs) ? downtimeMs : null }); },
    onLogout: () => { state.phase = 'logged_out'; state.provider = null; log('warn', 'openwa.session.logged_out'); },
  }).catch((error) => { state.phase = 'failed'; log('error', 'openwa.session.start_failed', { errorType: error.name || 'Error' }); });
}
