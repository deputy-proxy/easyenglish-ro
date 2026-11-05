import { createScheduledCall, retrieveScheduledCall, cancelScheduledCall } from './events.js';
export class OpenWaProvider {
  constructor(client) { this.client = client; }
  createEvent(input) { return createScheduledCall(this.client, input); }
  getEvent(id) { return retrieveScheduledCall(this.client, id); }
  cancelEvent(id) { return cancelScheduledCall(this.client, id); }
}
