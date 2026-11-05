import express from 'express';
import path from 'node:path';
import QRCode from 'qrcode';
import { createBasicAuth, createServiceAuth } from './auth.js';
import { validateCallEvent } from './events.js';
import { decideEventRequest, hashRequest } from './idempotency.js';

export function createApp({ config, state, store, pairingDirectory = path.join(process.cwd(), 'public'), logger = console, providerTimeouts = { create: 25000, read: 10000, cancel: 10000 } }) {
  const app = express();
  let createQueue = Promise.resolve();
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
