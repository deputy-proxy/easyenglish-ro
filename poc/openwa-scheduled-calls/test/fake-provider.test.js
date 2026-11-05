import test from 'node:test';
import assert from 'node:assert/strict';
import { FakeOpenWaProvider } from '../src/fake-provider.js';
test('fake provider implements create, retrieve, and cancel without WhatsApp credentials', async () => {
  const provider = new FakeOpenWaProvider();
  const created = await provider.createEvent({ externalBookingReference: 'booking-1' });
  assert.equal(created.status, 'scheduled');
  assert.equal((await provider.getEvent(created.providerEventId)).joinUrl, created.joinUrl);
  assert.equal(await provider.cancelEvent(created.providerEventId), true);
  assert.equal((await provider.getEvent(created.providerEventId)).status, 'cancelled');
});
