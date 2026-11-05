import test from 'node:test';
import assert from 'node:assert/strict';
import { createBasicAuth } from '../src/auth.js';

function response() {
  return {
    statusCode: 200,
    headers: {},
    body: null,
    set(name, value) { this.headers[name] = value; return this; },
    status(code) { this.statusCode = code; return this; },
    send(body) { this.body = body; return this; },
  };
}
function request(authorization, ip = '127.0.0.1') {
  return { headers: authorization ? { authorization } : {}, socket: { remoteAddress: ip } };
}
const config = { pairingUsername: 'operator', pairingPassword: 'a'.repeat(40) };
const valid = 'Basic ' + Buffer.from(config.pairingUsername + ':' + config.pairingPassword).toString('base64');

test('requires Basic authentication and sends a browser challenge', () => {
  const auth = createBasicAuth(config);
  const res = response();
  let passed = false;
  auth(request(null), res, () => { passed = true; });
  assert.equal(passed, false);
  assert.equal(res.statusCode, 401);
  assert.match(res.headers['WWW-Authenticate'], /^Basic realm=/);
});

test('accepts correct Basic credentials', () => {
  const auth = createBasicAuth(config);
  const res = response();
  let passed = false;
  auth(request(valid), res, () => { passed = true; });
  assert.equal(passed, true);
  assert.equal(res.statusCode, 200);
});

test('rate limits repeated invalid credentials per source address', () => {
  const auth = createBasicAuth(config, { maxAttempts: 2 });
  const first = response();
  const second = response();
  const third = response();
  auth(request('Basic invalid'), first, () => {});
  auth(request('Basic invalid'), second, () => {});
  auth(request('Basic invalid'), third, () => {});
  assert.equal(first.statusCode, 401);
  assert.equal(second.statusCode, 401);
  assert.equal(third.statusCode, 429);
});
