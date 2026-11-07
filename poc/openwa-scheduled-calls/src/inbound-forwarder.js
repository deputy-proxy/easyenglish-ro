import { createHash, createHmac } from 'node:crypto';

export class InboundMessageForwarder {
  constructor({ config, store, fetchImpl = fetch, logger = console, clock = () => Date.now() }) {
    this.config = config;
    this.store = store;
    this.fetchImpl = fetchImpl;
    this.logger = logger;
    this.clock = clock;
    this.resolvePhone = null;
    this.dispatching = false;
  }

  setPhoneResolver(resolvePhone) {
    this.resolvePhone = typeof resolvePhone === 'function' ? resolvePhone : null;
  }

  async receive(message) {
    if (!this.config.messagingEnabled || !this.config.inboundCallbackUrl || !this.config.inboundCallbackSecret
      || !message || message.fromMe || message.isGroupMsg) return;
    const id = typeof message.id === 'string' ? message.id : message.id?._serialized;
    const fromRaw = message.from;
    if (typeof id !== 'string' || typeof fromRaw !== 'string') return;
    const isLinkedId = /@lid$/i.test(fromRaw);
    let from = null;
    let senderId = null;
    if (isLinkedId) {
      senderId = fromRaw;
      try {
        const resolved = await this.resolvePhone?.(fromRaw);
        const digits = typeof resolved === 'string' ? resolved.replace(/\D/g, '') : '';
        if (/^[1-9]\d{7,14}$/.test(digits)) from = '+' + digits;
      } catch {
        // Keep the original LID when WhatsApp has not exposed its phone mapping.
      }
      from ??= fromRaw;
    } else {
      const fromDigits = fromRaw.replace(/@(?:c\.us|s\.whatsapp\.net)$/, '').replace(/\D/g, '');
      if (!/^[1-9]\d{7,14}$/.test(fromDigits)) return;
      from = '+' + fromDigits;
    }
    const type = typeof message.type === 'string' ? message.type : 'unknown';
    const payload = {
      id,
      from,
      ...(senderId ? { senderId } : {}),
      to: typeof message.to === 'string' && /@(?:c\.us|s\.whatsapp\.net)$/.test(message.to)
        ? '+' + message.to.replace(/@(?:c\.us|s\.whatsapp\.net)$/, '').replace(/\D/g, '')
        : null,
      timestamp: Number.isFinite(Number(message.t)) ? String(message.t) : String(Math.floor(this.clock() / 1000)),
      type,
      text: type === 'chat' || type === 'text' ? (typeof message.body === 'string' ? message.body : '') : null,
    };
    const serialized = JSON.stringify(payload);
    const idempotencyKey = 'inbound-event-' + id;
    const existing = this.store.findByKey(idempotencyKey);
    if (!existing) {
      await this.store.upsert({
        idempotencyKey,
        requestHash: createHash('sha256').update(serialized).digest('hex'),
        direction: 'inbound',
        status: 'callback_pending',
        payload,
        attempts: 0,
        createdAt: new Date(this.clock()).toISOString(),
        nextAttemptAt: new Date(this.clock()).toISOString(),
      });
    }
    await this.dispatchPending();
  }

  async dispatchPending() {
    if (this.dispatching || !this.config.messagingEnabled || !this.config.inboundCallbackUrl || !this.config.inboundCallbackSecret) return;
    this.dispatching = true;
    try {
      for (const record of this.store.pendingInbound(this.clock())) {
        record.status = 'callback_sending';
        record.attempts += 1;
        record.lastAttemptAt = new Date(this.clock()).toISOString();
        await this.store.upsert(record);
        try {
          const body = JSON.stringify(record.payload);
          const signature = 'sha256=' + createHmac('sha256', this.config.inboundCallbackSecret).update(body).digest('hex');
          const response = await this.fetchImpl(this.config.inboundCallbackUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-OpenWA-Signature': signature },
            body,
            signal: AbortSignal.timeout(10000),
          });
          if (!response.ok) throw new Error('Callback rejected.');
          record.status = 'forwarded';
          record.payload = null;
          record.forwardedAt = new Date(this.clock()).toISOString();
          record.nextAttemptAt = null;
          await this.store.upsert(record);
        } catch (error) {
          record.status = 'callback_pending';
          record.nextAttemptAt = new Date(this.clock() + Math.min(3600, 10 * (2 ** Math.min(record.attempts - 1, 8))) * 1000).toISOString();
          await this.store.upsert(record);
          this.logger.warn(JSON.stringify({ event: 'openwa.inbound.forward_failed', errorType: error.name || 'Error' }));
        }
      }
    } finally {
      this.dispatching = false;
    }
  }
}
