import express from 'express';
import path from 'node:path';
import QRCode from 'qrcode';
import { createBasicAuth, createServiceAuth } from './auth.js';
import { validateCallEvent } from './events.js';
import { decideEventRequest, hashRequest } from './idempotency.js';
import { createHash } from 'node:crypto';

export function createApp({ config, state, store, messageStore = null, messageTimeoutMs = 15000, pairingDirectory = path.join(process.cwd(), 'public'), logger = console, providerTimeouts = { create: 25000, read: 10000, cancel: 10000 } }) {
  const app = express();
  let createQueue = Promise.resolve();
  let messageQueue = Promise.resolve();
  state.metrics ||= { created: 0, providerErrors: 0, reconnects: 0 };
  app.disable('x-powered-by');
  app.use(express.json({ limit: '8kb', strict: true }));
  app.use((_req, res, next) => {
    res.set({ 'Cache-Control': 'no-store, no-cache, must-revalidate, private', Pragma: 'no-cache', 'X-Content-Type-Options': 'nosniff', 'Referrer-Policy': 'no-referrer', 'Content-Security-Policy': "default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'" });
    next();
  });

  app.get('/health/live', (_req, res) => res.json({ status: 'ok' }));
  app.get('/health/ready', (_req, res) => {
    const ready = state.phase === 'authenticated' && Boolean(state.provider);
    return res.status(ready ? 200 : 503).json({ status: ready ? 'ready' : 'not_ready' });
  });

  app.use('/v1', createServiceAuth(config));
  app.get('/v1/metrics', (_req, res) => res.json({ ...state.metrics, connectionState: state.phase }));
  app.get('/v1/capabilities', (_req, res) => res.json({
    provider: 'openwa', messagingEnabled: Boolean(config.messagingEnabled),
    messagingReady: Boolean(config.messagingEnabled && messageStore && state.phase === 'authenticated' && state.provider),
    connectionState: state.phase,
  }));
  app.post('/v1/messages', (req, res) => {
    const operation = async () => {
      if (!config.messagingEnabled || !messageStore) return res.status(503).json({ error: 'OpenWA messaging is disabled.' });
      if (state.phase !== 'authenticated' || !state.provider) return res.status(503).json({ error: 'OpenWA messaging session is unavailable.' });
      const key = req.get('Idempotency-Key') || '';
      if (!/^[A-Za-z0-9:_-]{16,160}$/.test(key)) return res.status(400).json({ error: 'A valid Idempotency-Key header is required.' });
      const to = typeof req.body?.to === 'string' ? req.body.to : '';
      const text = typeof req.body?.text === 'string' ? req.body.text.trim() : '';
      if (!/^\+[1-9]\d{7,14}$/.test(to) || text.length < 1 || text.length > 4096) return res.status(400).json({ error: 'A valid E.164 recipient and message text are required.' });
      const requestHash = createHash('sha256').update(JSON.stringify({ to, text })).digest('hex');
      let record = messageStore.findByKey(key);
      if (record) {
        if (record.requestHash !== requestHash) return res.status(409).json({ error: 'The idempotency key was already used with a different request.' });
        if (record.status === 'accepted') return res.json({ providerMessageId: record.providerMessageId, status: 'accepted', replayed: true });
        if (record.status !== 'failed') return res.status(202).json({ status: record.status, retryable: false });
        record.status = 'sending';
        record.attempts = (record.attempts || 1) + 1;
        record.updatedAt = new Date().toISOString();
      } else {
        record = { idempotencyKey: key, requestHash, direction: 'outbound', status: 'sending', attempts: 1, createdAt: new Date().toISOString(), providerMessageId: null, toHash: createHash('sha256').update(to).digest('hex'), textHash: createHash('sha256').update(text).digest('hex') };
      }
      try { await messageStore.upsert(record); } catch { return res.status(503).json({ error: 'OpenWA message persistence is unavailable.' }); }
      let result;
      try {
        result = await withTimeout(state.provider.sendText(to, text), messageTimeoutMs, true);
      } catch (error) {
        record.status = error.uncertain ? 'submission_unconfirmed' : 'failed';
        await messageStore.upsert(record).catch(() => {});
        state.metrics.providerErrors += 1;
        log(logger, 'warn', 'message.send.failed', { uncertain: Boolean(error.uncertain), errorType: error.name || 'Error' });
        return error.uncertain ? res.status(202).json({ status: record.status, retryable: false }) : res.status(502).json({ error: 'OpenWA could not send the message.' });
      }
      record.status = 'accepted'; record.providerMessageId = result.providerMessageId; record.acceptedAt = new Date().toISOString();
      try {
        await messageStore.upsert(record);
      } catch {
        log(logger, 'error', 'message.send.acknowledgement_persist_failed');
        return res.status(202).json({ status: 'submission_unconfirmed', retryable: false });
      }
      state.metrics.messagesAccepted = (state.metrics.messagesAccepted || 0) + 1;
      return res.status(201).json({ providerMessageId: record.providerMessageId, status: 'accepted' });
    };
    const current = messageQueue.then(operation);
    messageQueue = current.catch(() => {});
    return current;
  });
  app.get('/v1/messages/:idempotencyKey', (req, res) => {
    if (!config.messagingEnabled || !messageStore) return res.status(503).json({ error: 'OpenWA messaging is disabled.' });
    const record = messageStore.findByKey(req.params.idempotencyKey);
    if (!record || record.direction !== 'outbound') return res.status(404).json({ error: 'Message record not found.' });
    return res.json({ status: record.status, providerMessageId: record.providerMessageId || null, attempts: record.attempts || 1, acceptedAt: record.acceptedAt || null });
  });
  app.post('/v1/call-events', async (req, res) => {
    const operation = async () => {
      if (state.phase !== 'authenticated' || !state.provider) return res.status(503).json({ error: 'Call-event provider is unavailable.' });
      const key = req.get('Idempotency-Key') || '';
      if (!/^[A-Za-z0-9_-]{16,128}$/.test(key)) return res.status(400).json({ error: 'A valid Idempotency-Key header is required.' });
      let input;
      try { input = validateCallEvent(req.body); } catch (error) { return res.status(400).json({ error: error.message }); }
      const requestHash = hashRequest(input), decision = decideEventRequest(store.all(), key, requestHash);
      if (decision.type === 'key_conflict') return res.status(409).json({ error: 'The idempotency key was already used with a different request.' });
      if (decision.type === 'replay') return res.json(toApiEvent(decision.record, true));
      if (decision.type === 'pending') return res.status(202).json({ status: decision.record.status, providerEventId: decision.record.providerEventId || null, retryable: false });
      const activeBooking = store.all().find((record) => record.externalBookingReference === input.externalBookingReference && ['creating', 'scheduled', 'submission_unconfirmed'].includes(record.status));
      if (activeBooking) return res.status(409).json({ error: 'An active call event already exists for this booking reference.' });
      const record = { idempotencyKey: key, requestHash, externalBookingReference: input.externalBookingReference, status: 'creating', createdAt: new Date().toISOString(), cancelledAt: null, providerEventId: null, joinUrl: null, input };
      try { await store.upsert(record); } catch { return res.status(503).json({ error: 'Call-event persistence is unavailable.' }); }
      let result;
      const startedAt = Date.now();
      try { result = await withTimeout(state.provider.createEvent(input), providerTimeouts.create, true); }
      catch (error) {
        record.status = error.uncertain ? 'submission_unconfirmed' : 'failed';
        record.providerEventId = error.providerEventId || null;
        record.updatedAt = new Date().toISOString();
        await store.upsert(record).catch(() => {});
        state.metrics.providerErrors += 1;
        log(logger, error.uncertain ? 'warn' : 'error', 'call_event.create.failed', { durationMs: Date.now() - startedAt, uncertain: Boolean(error.uncertain) });
        if (error.uncertain) return res.status(202).json({ status: record.status, providerEventId: record.providerEventId, retryable: false });
        return res.status(502).json({ error: 'The call-event provider rejected the request.', code: 'PROVIDER_ERROR' });
      }
      Object.assign(record, result, { status: 'scheduled', updatedAt: new Date().toISOString() });
      try { await store.upsert(record); } catch { log(logger, 'error', 'call_event.persist.failed', { durationMs: Date.now() - startedAt }); return res.status(202).json({ status: 'submission_unconfirmed', providerEventId: record.providerEventId, retryable: false }); }
      state.metrics.created += 1;
      log(logger, 'info', 'call_event.create.completed', { durationMs: Date.now() - startedAt });
      return res.status(201).json(toApiEvent(record));
    };
    const current = createQueue.then(operation);
    createQueue = current.catch(() => {});
    return current;
  });

  app.get('/v1/call-events/:providerEventId', async (req, res) => {
    if (state.phase !== 'authenticated' || !state.provider) return res.status(503).json({ error: 'Call-event provider is unavailable.' });
    const record = store.findById(req.params.providerEventId);
    if (!record) return res.status(404).json({ error: 'Call event not found.' });
    const startedAt = Date.now();
    try {
      const current = await withTimeout(state.provider.getEvent(req.params.providerEventId), providerTimeouts.read);
      if (!current) return res.status(404).json({ error: 'Call event not found at provider.' });
      Object.assign(record, current, { updatedAt: new Date().toISOString() });
      if (current.status === 'cancelled') record.cancelledAt ||= new Date().toISOString();
      await store.upsert(record);
      log(logger, 'info', 'call_event.retrieve.completed', { durationMs: Date.now() - startedAt });
      return res.json(toApiEvent(record));
    } catch { state.metrics.providerErrors += 1; log(logger, 'error', 'call_event.retrieve.failed', { durationMs: Date.now() - startedAt }); return res.status(502).json({ error: 'The call-event provider could not retrieve the event.', code: 'PROVIDER_ERROR' }); }
  });

  app.delete('/v1/call-events/:providerEventId', async (req, res) => {
    if (state.phase !== 'authenticated' || !state.provider) return res.status(503).json({ error: 'Call-event provider is unavailable.' });
    const record = store.findById(req.params.providerEventId);
    if (!record) return res.status(404).json({ error: 'Call event not found.' });
    if (record.status === 'cancelled') return res.json({ providerEventId: record.providerEventId, status: 'cancelled' });
    const startedAt = Date.now();
    try {
      const cancelled = await withTimeout(state.provider.cancelEvent(req.params.providerEventId), providerTimeouts.cancel);
      if (!cancelled) { state.metrics.providerErrors += 1; log(logger, 'error', 'call_event.cancel.unconfirmed', { durationMs: Date.now() - startedAt }); return res.status(502).json({ error: 'The provider did not confirm cancellation.', code: 'PROVIDER_ERROR' }); }
      record.status = 'cancelled'; record.cancelledAt = new Date().toISOString(); record.updatedAt = record.cancelledAt;
      record.joinUrl = null;
      delete record.input;
      delete record.externalBookingReference;
      await store.upsert(record);
      log(logger, 'info', 'call_event.cancel.completed', { durationMs: Date.now() - startedAt });
      return res.json({ providerEventId: record.providerEventId, status: 'cancelled' });
    } catch { state.metrics.providerErrors += 1; log(logger, 'error', 'call_event.cancel.failed', { durationMs: Date.now() - startedAt }); return res.status(502).json({ error: 'The call-event provider could not cancel the event.', code: 'PROVIDER_ERROR' }); }
  });

  if (config.pairingEnabled) {
    app.use(createBasicAuth(config));
    app.use(express.static(pairingDirectory, { index: 'index.html', etag: false, maxAge: 0 }));
    app.get('/pairing/status', (_req, res) => res.json({ phase: state.phase, startedAt: state.startedAt }));
    app.get('/pairing/qr', async (_req, res) => {
    if (state.phase !== 'qr_ready' || !state.qr) return res.status(404).end();
    try { return res.type('png').send(await QRCode.toBuffer(state.qr, { type: 'png', margin: 2, width: 360 })); }
    catch { return res.status(500).end(); }
    });
  }
  app.use((error, _req, res, _next) => {
    if (error instanceof SyntaxError && 'body' in error) return res.status(400).json({ error: 'Malformed JSON request body.' });
    return res.status(500).json({ error: 'Internal server error.' });
  });
  return app;
}
function withTimeout(promise, timeoutMs, uncertain = false) {
  let timer;
  return Promise.race([promise, new Promise((_, reject) => { timer = setTimeout(() => { const error = new Error('Provider request timed out.'); error.uncertain = uncertain; reject(error); }, timeoutMs); })]).finally(() => clearTimeout(timer));
}
function log(logger, level, event, fields = {}) {
  const entry = JSON.stringify({ timestamp: new Date().toISOString(), level, event, ...fields });
  (logger[level] || logger.log || (() => {})).call(logger, entry);
}
function toApiEvent(record, replayed = false) {
  return { providerEventId: record.providerEventId, joinUrl: record.joinUrl, status: record.status, createdAt: record.createdAt, ...(replayed ? { replayed: true } : {}) };
}
