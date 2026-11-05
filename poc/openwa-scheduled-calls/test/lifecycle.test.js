import test from 'node:test';
import assert from 'node:assert/strict';
import { applyConnectionState } from '../src/lifecycle.js';
test('readiness follows OpenWA connection state', () => {
  const state = { phase: 'authenticated', provider: {} };
  assert.equal(applyConnectionState(state, 'DISCONNECTED'), 'disconnected');
  assert.equal(applyConnectionState(state, 'READY'), 'authenticated');
  assert.equal(applyConnectionState(state, 'STOPPED'), 'stopped');
  assert.equal(state.provider, null);
});
