import path from 'node:path';

export function readConfig(env = process.env) {
  const sessionDir = env.OPENWA_SESSION_DIR;
  const sessionKeyDir = env.OPENWA_SESSION_KEY_DIR;
  const publicBaseUrl = env.OPENWA_PUBLIC_BASE_URL;
  const encryptionKey = env.OPENWA_SESSION_ENCRYPTION_KEY;
  const pairingUsername = env.OPENWA_PAIRING_USERNAME;
  const pairingPassword = env.OPENWA_PAIRING_PASSWORD;

  if (!sessionDir || !path.isAbsolute(sessionDir)) throw new Error('OPENWA_SESSION_DIR must be an absolute path outside the public web root.');
  if (!sessionKeyDir || !path.isAbsolute(sessionKeyDir)) throw new Error('OPENWA_SESSION_KEY_DIR must be an absolute private path separate from the session-data directory.');
  const resolvedSessionDir = path.resolve(sessionDir);
  const resolvedKeyDir = path.resolve(sessionKeyDir);
  if (resolvedSessionDir === resolvedKeyDir || resolvedSessionDir.startsWith(resolvedKeyDir + path.sep) || resolvedKeyDir.startsWith(resolvedSessionDir + path.sep)) {
    throw new Error('Session data and encryption-key directories must be separate.');
  }
  if (!publicBaseUrl || new URL(publicBaseUrl).protocol !== 'https:') throw new Error('OPENWA_PUBLIC_BASE_URL must use HTTPS.');
  if (!pairingUsername || !pairingPassword || pairingPassword.length < 32) throw new Error('Pairing authentication requires a username and a password of at least 32 characters.');

  const decodedKey = Buffer.from(encryptionKey || '', 'base64');
  if (decodedKey.length !== 32 || decodedKey.toString('base64') !== encryptionKey) throw new Error('OPENWA_SESSION_ENCRYPTION_KEY must be a base64-encoded 32-byte key.');

  const appRoot = path.resolve(env.OPENWA_APP_ROOT || process.cwd());
  for (const candidate of [resolvedSessionDir, resolvedKeyDir]) {
    if (candidate === appRoot || candidate.startsWith(appRoot + path.sep)) {
      throw new Error('Session and key directories must be outside the Node application root.');
    }
  }

  const port = Number(env.PORT || 3100);
  if (!Number.isInteger(port) || port < 1 || port > 65535) throw new Error('PORT must be a valid TCP port.');

  return Object.freeze({
    host: env.HOST || '0.0.0.0',
    port,
    sessionId: env.OPENWA_SESSION_ID || 'easyenglish-openwa-poc',
    sessionDir: resolvedSessionDir,
    sessionKeyDir: resolvedKeyDir,
    sessionDataPath: path.join(resolvedSessionDir, 'session.data.json'),
    encryptionKeyFile: path.join(resolvedKeyDir, 'session-encryption.key'),
    encryptionKey: decodedKey,
    pairingUsername,
    pairingPassword,
    publicBaseUrl,
    chromeExecutable: env.OPENWA_CHROME_EXECUTABLE || null,
    chromeNoSandbox: env.OPENWA_CHROME_NO_SANDBOX === 'true',
    allowLiveTest: env.OPENWA_ALLOW_LIVE_TEST === 'true',
  });
}

export function normalizeE164(value, label) {
  if (typeof value !== 'string' || !/^\+[1-9]\d{7,14}$/.test(value.trim())) throw new Error(label + ' must be a valid E.164 phone number, including country code.');
  return value.trim();
}

export function normalizeStartTime(value, now = Date.now()) {
  const timestamp = Date.parse(value);
  if (!Number.isFinite(timestamp) || timestamp <= now + 5 * 60 * 1000) throw new Error('Start time must be at least five minutes in the future.');
  return timestamp;
}
