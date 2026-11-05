import { timingSafeEqual } from 'node:crypto';

export function createBasicAuth(config, options = {}) {
  const failures = new Map();
  const maxAttempts = options.maxAttempts || 10;
  const windowMs = options.windowMs || 10 * 60 * 1000;

  return function authenticate(req, res, next) {
    const address = req.socket?.remoteAddress || req.ip || 'unknown';
    const now = Date.now();
    const attempt = failures.get(address) || { count: 0, resetAt: now + windowMs };
    if (attempt.resetAt < now) {
      attempt.count = 0;
      attempt.resetAt = now + windowMs;
    }
    if (attempt.count >= maxAttempts) return res.status(429).send('Too many authentication attempts.');

    const match = /^Basic\s+(.+)$/i.exec(req.headers?.authorization || '');
    let username = '';
    let password = '';
    if (match) {
      const parts = Buffer.from(match[1], 'base64').toString('utf8').split(/:(.*)/s, 2);
      username = parts[0] || '';
      password = parts[1] || '';
    }

    const user = Buffer.from(username);
    const expectedUser = Buffer.from(config.pairingUsername);
    const pass = Buffer.from(password);
    const expectedPass = Buffer.from(config.pairingPassword);
    const validUser = user.length === expectedUser.length && timingSafeEqual(user, expectedUser);
    const validPass = pass.length === expectedPass.length && timingSafeEqual(pass, expectedPass);

    if (!validUser || !validPass) {
      attempt.count += 1;
      failures.set(address, attempt);
      res.set('WWW-Authenticate', 'Basic realm="EasyEnglish OpenWA PoC", charset="UTF-8"');
      return res.status(401).send('Authentication required.');
    }

    failures.delete(address);
    return next();
  };
}
