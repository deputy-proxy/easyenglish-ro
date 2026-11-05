import { randomUUID } from 'node:crypto';
export class FakeOpenWaProvider {
  constructor() { this.events = new Map(); }
  async createEvent(input) {
    const providerEventId = 'fake-' + randomUUID();
    const event = { providerEventId, joinUrl: 'https://call.whatsapp.com/fake-' + randomUUID(), status: 'scheduled', createdAt: new Date().toISOString(), input };
    this.events.set(providerEventId, event);
    return { providerEventId, joinUrl: event.joinUrl, status: event.status, createdAt: event.createdAt };
  }
  async getEvent(id) { const event = this.events.get(id); return event ? { providerEventId: id, joinUrl: event.joinUrl, status: event.status } : null; }
  async cancelEvent(id) { const event = this.events.get(id); if (!event) return false; event.status = 'cancelled'; return true; }
}
