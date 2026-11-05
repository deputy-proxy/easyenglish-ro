import path from 'node:path';
export function readConfig(env = process.env) {
  const sessionDir = env.OPENWA_SESSION_DIR, sessionKeyDir = env.OPENWA_SESSION_KEY_DIR;
  const publicBaseUrl = env.OPENWA_PUBLIC_BASE_URL, encryptionKey = env.OPENWA_SESSION_ENCRYPTION_KEY;
  const pairingUsername = env.OPENWA_PAIRING_USERNAME, pairingPassword = env.OPENWA_PAIRING_PASSWORD;
  const serviceToken = env.OPENWA_SERVICE_TOKEN;
  const allowLiveTest = env.OPENWA_ALLOW_LIVE_TEST === 'true';
  const pairingEnabled = env.OPENWA_PAIRING_ENABLED === 'true';
  if (!sessionDir || !path.isAbsolute(sessionDir)) throw new Error('OPENWA_SESSION_DIR must be an absolute private path.');
  if (!sessionKeyDir || !path.isAbsolute(sessionKeyDir)) throw new Error('OPENWA_SESSION_KEY_DIR must be an absolute private path.');
  const resolvedSessionDir = path.resolve(sessionDir), resolvedKeyDir = path.resolve(sessionKeyDir);
  if (resolvedSessionDir === resolvedKeyDir || resolvedSessionDir.startsWith(resolvedKeyDir + path.sep) || resolvedKeyDir.startsWith(resolvedSessionDir + path.sep)) throw new Error('Session data and encryption-key directories must be separate.');
  if (!publicBaseUrl || new URL(publicBaseUrl).protocol !== 'https:') throw new Error('OPENWA_PUBLIC_BASE_URL must use HTTPS.');
  if (!pairingUsername || !pairingPassword || pairingPassword.length < 32) throw new Error('Pairing authentication requires a username and a password of at least 32 characters.');
  if (allowLiveTest && (!serviceToken || serviceToken.length < 32)) throw new Error('OPENWA_SERVICE_TOKEN must contain at least 32 characters before enabling the live provider.');
  if (serviceToken && serviceToken.length < 32) throw new Error('OPENWA_SERVICE_TOKEN must contain at least 32 characters when configured.');
  const decodedKey = Buffer.from(encryptionKey || '', 'base64');
  if (decodedKey.length !== 32 || decodedKey.toString('base64') !== encryptionKey) throw new Error('OPENWA_SESSION_ENCRYPTION_KEY must be a base64-encoded 32-byte key.');
  const appRoot = path.resolve(env.OPENWA_APP_ROOT || process.cwd());
  for (const candidate of [resolvedSessionDir, resolvedKeyDir]) if (candidate === appRoot || candidate.startsWith(appRoot + path.sep)) throw new Error('Session and key directories must be outside the Node application root.');
  const port = Number(env.PORT || 3100);
  if (!Number.isInteger(port) || port < 1 || port > 65535) throw new Error('PORT must be a valid TCP port.');
  return Object.freeze({ host: env.HOST || '0.0.0.0', port, sessionId: env.OPENWA_SESSION_ID || 'easyenglish-openwa', sessionDir: resolvedSessionDir, sessionKeyDir: resolvedKeyDir, sessionDataPath: path.join(resolvedSessionDir, 'session.data.json'), eventStorePath: path.join(resolvedSessionDir, 'call-events.enc'), encryptionKeyFile: path.join(resolvedKeyDir, 'session-encryption.key'), encryptionKey: decodedKey, pairingUsername, pairingPassword, pairingEnabled, serviceToken: serviceToken || null, publicBaseUrl, chromeExecutable: env.OPENWA_CHROME_EXECUTABLE || null, chromeNoSandbox: env.OPENWA_CHROME_NO_SANDBOX === 'true', allowLiveTest });
}
export function normalizeE164(value, label = 'Destination phone') {
  if (typeof value !== 'string' || !/^\+[1-9]\d{7,14}$/.test(value.trim())) throw new Error(label + ' must be a valid E.164 phone number, including country code.');
  return value.trim();
}
export function normalizeEventTimes(startAt, endAt, now = Date.now()) {
  if (typeof startAt !== 'string' || !/(?:Z|[+-]\d{2}:\d{2})$/i.test(startAt) || typeof endAt !== 'string' || !/(?:Z|[+-]\d{2}:\d{2})$/i.test(endAt)) throw new Error('Start and end timestamps must include an explicit UTC offset.');
  const start = Date.parse(startAt), end = Date.parse(endAt);
  if (!Number.isFinite(start) || start <= now + 5 * 60 * 1000) throw new Error('Start time must be at least five minutes in the future.');
  if (!Number.isFinite(end) || end <= start) throw new Error('End time must be later than start time.');
  if (end - start > 4 * 60 * 60 * 1000) throw new Error('Call events cannot exceed four hours.');
  return { startTime: Math.floor(start / 1000), endTime: Math.floor(end / 1000) };
}
export function validateTimeZone(value) {
  if (typeof value !== 'string' || value.length > 64) throw new Error('A valid IANA timezone is required.');
  try { new Intl.DateTimeFormat('en', { timeZone: value }); } catch { throw new Error('A valid IANA timezone is required.'); }
  return value;
}
