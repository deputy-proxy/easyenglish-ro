import { timingSafeEqual } from 'node:crypto';
function safeEqual(left, right) { const a = Buffer.from(left), b = Buffer.from(right); return a.length === b.length && timingSafeEqual(a, b); }
export function createServiceAuth(config) {
  return (req, res, next) => {
    if (!config.serviceToken) return res.status(503).json({ error: 'Service authentication is not configured; the API is disabled.' });
    const match = /^Bearer\s+(.+)$/i.exec(req.headers?.authorization || '');
    if (!match || !safeEqual(match[1], config.serviceToken)) return res.status(401).json({ error: 'Service authentication required.' });
    return next();
  };
}
export function createBasicAuth(config, options = {}) {
  const failures = new Map(), maxAttempts = options.maxAttempts || 10, windowMs = options.windowMs || 600000;
  return (req, res, next) => {
    const address = req.socket?.remoteAddress || req.ip || 'unknown', now = Date.now();
    const attempt = failures.get(address) || { count: 0, resetAt: now + windowMs };
    if (attempt.resetAt < now) { attempt.count = 0; attempt.resetAt = now + windowMs; }
    if (attempt.count >= maxAttempts) return res.status(429).send('Too many authentication attempts.');
    const match = /^Basic\s+(.+)$/i.exec(req.headers?.authorization || '');
    let username = '', password = '';
    if (match) { const parts = Buffer.from(match[1], 'base64').toString('utf8').split(/:(.*)/s, 2); username = parts[0] || ''; password = parts[1] || ''; }
    if (!safeEqual(username, config.pairingUsername) || !safeEqual(password, config.pairingPassword)) {
      attempt.count += 1; failures.set(address, attempt); res.set('WWW-Authenticate', 'Basic realm="EasyEnglish OpenWA pairing", charset="UTF-8"');
      return res.status(401).send('Authentication required.');
    }
    failures.delete(address); return next();
  };
}
