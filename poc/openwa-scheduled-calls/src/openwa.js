import { create, ev } from '@open-wa/wa-automate';

export function extractQrPayload(event) {
  const qr = event?.details?.qr;
  return typeof qr === 'string' && qr.trim() ? qr.trim() : null;
}

export function buildOpenWaOptions(config) {
  return {
    sessionId: config.sessionId,
    sessionDataPath: config.sessionDataPath,
    sessionDataEncryptionKeyFile: config.encryptionKeyFile,
    headless: true,
    qrTimeout: 0,
    qrLogSkip: true,
    qrFormat: 'png',
    qrMax: 10,
    skipSessionSave: false,
    logConsole: false,
    logConsoleErrors: false,
    blockCrashLogs: true,
    safeMode: true,
    ...(config.chromeExecutable ? { executablePath: config.chromeExecutable } : {}),
    ...(config.chromeNoSandbox ? { chromiumArgs: ['--no-sandbox', '--disable-setuid-sandbox'], allowDangerousBrowserArgs: true } : {}),
  };
}

export async function startOpenWa(config, callbacks) {
  ev.on('launch.auth.qr.generated', (event) => {
    const qr = extractQrPayload(event);
    if (qr) callbacks.onQr(qr);
  });
  ev.on('launch.auth.qr.scanned', () => callbacks.onQrScanned());
  ev.on('launch.auth.qr.expired', () => callbacks.onQrExpired());
  ev.on('session.connection.reconnecting', () => callbacks.onReconnectStart?.());
  ev.on('session.connection.reconnected', ({ downtimeMs } = {}) => callbacks.onReconnectRecovered?.(downtimeMs));
  const client = await create(buildOpenWaOptions(config));
  client.onStateChanged((connectionState) => callbacks.onConnectionState?.(connectionState));
  client.onLogout(() => callbacks.onLogout?.());
  callbacks.onAuthenticated(client);
  return client;
}
