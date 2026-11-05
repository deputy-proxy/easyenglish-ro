import { create, ev } from '@open-wa/wa-automate';

export async function startOpenWa(config, callbacks) {
  ev.on('launch.auth.qr.generated', ({ qr }) => callbacks.onQr(qr));
  ev.on('launch.auth.qr.scanned', () => callbacks.onQrScanned());
  ev.on('launch.auth.qr.expired', () => callbacks.onQrExpired());

  const client = await create({
    sessionId: config.sessionId,
    sessionDataPath: config.sessionDataPath,
    sessionDataEncryptionKeyFile: config.encryptionKeyFile,
    headless: true,
    qrTimeout: 0,
    qrLogSkip: true,
    qrFormat: 'png',
    qrMax: 10,
    ephemeral: true,
    skipSessionSave: false,
    logConsole: false,
    logConsoleErrors: false,
    blockCrashLogs: true,
    safeMode: true,
    ...(config.chromeExecutable ? { executablePath: config.chromeExecutable } : {}),
  });

  callbacks.onAuthenticated(client);
  return client;
}
