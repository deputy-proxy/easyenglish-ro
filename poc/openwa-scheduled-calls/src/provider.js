import { createScheduledCall, retrieveScheduledCall, cancelScheduledCall } from './events.js';
export class OpenWaProvider {
  constructor(client) { this.client = client; }
  createEvent(input) { return createScheduledCall(this.client, input); }
  getEvent(id) { return retrieveScheduledCall(this.client, id); }
  cancelEvent(id) { return cancelScheduledCall(this.client, id); }
  async sendText(recipient, text) {
    const chatId = recipient.replace(/^\+/, '') + '@c.us';
    let result;
    try {
      result = await this.client.sendText(chatId, text);
    } catch (error) {
      error.uncertain = true;
      throw error;
    }
    const messageId = typeof result === 'string' ? result : result?.id?._serialized || result?.id || result?.toString?.();
    if (typeof messageId !== 'string' || messageId.length === 0) throw Object.assign(new Error('OpenWA did not return a message identifier.'), { uncertain: true });
    return { providerMessageId: messageId };
  }
}
