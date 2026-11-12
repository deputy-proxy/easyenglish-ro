import test from 'node:test';
import assert from 'node:assert/strict';
import { OpenWaProvider } from '../src/provider.js';

test('normalizes an E.164 phone number to the documented OpenWA chat ID', async () => {
  let received;
  const provider = new OpenWaProvider({ async sendText(chatId, text) { received = { chatId, text }; return 'true_40712345678@c.us_message-id'; } });
  const result = await provider.sendText('+40712345678', 'Verification code');
  assert.deepEqual(received, { chatId: '40712345678@c.us', text: 'Verification code' });
  assert.equal(result.providerMessageId, 'true_40712345678@c.us_message-id');
});

test('preserves a definitive OpenWA rejection as safe-to-retry only after operator handling', async () => {
  const error = Object.assign(new Error('A licence is required to start a new chat.'), { name: 'SendTextError', code: 'LICENSE_REQUIRED', outcome: 'not_sent' });
  const provider = new OpenWaProvider({ async sendText() { throw error; } });
  await assert.rejects(provider.sendText('+40712345678', 'Verification code'), (caught) => {
    assert.equal(caught.code, 'LICENSE_REQUIRED');
    assert.equal(caught.outcome, 'not_sent');
    assert.equal(caught.uncertain, false);
    return true;
  });
});

test('preserves an unknown OpenWA send outcome as uncertain', async () => {
  const error = Object.assign(new Error('The result could not be confirmed.'), { name: 'SendTextError', code: 'SEND_FAILED', outcome: 'unknown' });
  const provider = new OpenWaProvider({ async sendText() { throw error; } });
  await assert.rejects(provider.sendText('+40712345678', 'Verification code'), (caught) => {
    assert.equal(caught.code, 'SEND_FAILED');
    assert.equal(caught.outcome, 'unknown');
    assert.equal(caught.uncertain, true);
    return true;
  });
});


test('keeps the observed WhatsApp Web isRegularUser runtime failure ambiguous', async () => {
  const error = Object.assign(new Error('[puppeteer:evaluate] t.isRegularUser is not a function'), { name: 'SendTextError', code: 'SEND_FAILED', outcome: 'unknown' });
  const provider = new OpenWaProvider({ async sendText() { throw error; } });
  await assert.rejects(provider.sendText('+40712345678', 'Booking rescheduled'), (caught) => {
    assert.equal(caught.code, 'SEND_FAILED');
    assert.equal(caught.outcome, 'unknown');
    assert.equal(caught.uncertain, true);
    return true;
  });
});
