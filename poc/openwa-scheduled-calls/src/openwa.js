import { create, ev } from '@open-wa/wa-automate';

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
    ephemeral: false,
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
  ev.on('launch.auth.qr.generated', ({ qr }) => callbacks.onQr(qr));
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
