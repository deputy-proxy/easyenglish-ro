import express from 'express';
import { mkdir, writeFile, chmod, readFile, unlink, rename } from 'node:fs/promises';
import path from 'node:path';
import QRCode from 'qrcode';
import { readConfig } from './config.js';
import { createBasicAuth } from './auth.js';
import { encryptJson, decryptJson } from './secure-store.js';
import { startOpenWa } from './openwa.js';
import { createScheduledCall, cancelScheduledCall } from './events.js';
import { decideEventRequest } from './idempotency.js';

const config = readConfig();

await mkdir(config.sessionDir, { recursive: true, mode: 0o700 });
await chmod(config.sessionDir, 0o700);
await mkdir(config.sessionKeyDir, { recursive: true, mode: 0o700 });
await chmod(config.sessionKeyDir, 0o700);
await writeFile(config.encryptionKeyFile, process.env.OPENWA_SESSION_ENCRYPTION_KEY, { mode: 0o600 });
await chmod(config.encryptionKeyFile, 0o600);

const app = express();
app.disable('x-powered-by');
app.use(express.json({ limit: '8kb' }));
app.use((_req, res, next) => {
  res.set({
    'Cache-Control': 'no-store, no-cache, must-revalidate, private',
    'Pragma': 'no-cache',
    'X-Content-Type-Options': 'nosniff',
    'Referrer-Policy': 'no-referrer',
    'Content-Security-Policy': "default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'",
  });
  next();
});

const authenticate = createBasicAuth(config);

const state = {
  phase: config.allowLiveTest ? 'starting' : 'disabled',
  qr: null,
  client: null,
  error: config.allowLiveTest ? null : 'Pairing is disabled until the Hostinger preflight and security review pass.',
  startedAt: new Date().toISOString(),
};
const resultsPath = path.join(config.sessionDir, 'poc-results.enc');
const temporaryResultsPath = resultsPath + '.tmp';
let eventRecord = null;
try {
  eventRecord = decryptJson(await readFile(resultsPath, 'utf8'), config.encryptionKey);
} catch (error) {
  if (error.code !== 'ENOENT') throw new Error('The encrypted PoC event record cannot be read. Refusing to create another event.');
}
async function persistEventRecord() {
  if (eventRecord) {
    await writeFile(temporaryResultsPath, encryptJson(eventRecord, config.encryptionKey), { mode: 0o600 });
    await chmod(temporaryResultsPath, 0o600);
    await rename(temporaryResultsPath, resultsPath);
  } else {
    await unlink(resultsPath).catch(() => {});
    await unlink(temporaryResultsPath).catch(() => {});
  }
}

app.get('/health/live', (_req, res) => res.json({ status: 'ok' }));
app.use(authenticate);
app.use(express.static(path.join(process.cwd(), 'public'), { index: 'index.html', etag: false, maxAge: 0 }));

app.get('/pairing/status', (_req, res) => res.json({ phase: state.phase, error: state.error, startedAt: state.startedAt }));
app.get('/pairing/qr', async (_req, res) => {
  if (state.phase !== 'qr_ready' || !state.qr) return res.status(404).end();
  try {
    const image = await QRCode.toBuffer(state.qr, { type: 'png', margin: 2, width: 360 });
    res.type('png').send(image);
  } catch {
    res.status(500).end();
  }
});

app.post('/events', async (req, res) => {
  if (state.phase !== 'authenticated' || !state.client) return res.status(503).json({ error: 'OpenWA is not authenticated.' });
  const idempotencyKey = req.get('Idempotency-Key') || '';
  if (!/^[a-zA-Z0-9_-]{16,128}$/.test(idempotencyKey)) return res.status(400).json({ error: 'A valid Idempotency-Key header is required.' });

  const decision = decideEventRequest(eventRecord, idempotencyKey);
  if (decision.type === 'replay') return res.json({ ...decision.response, replayed: true });
  if (decision.type === 'pending') {
    return res.status(202).json({ status: decision.status, eventId: decision.eventId, doNotRetryAutomatically: true });
  }
  if (decision.type === 'conflict') {
    return res.status(409).json({ error: 'A PoC event is already recorded. Reuse its idempotency key or cancel it before starting another test.' });
  }

  eventRecord = { idempotencyKey, status: 'creating', createdAt: new Date().toISOString() };
  await persistEventRecord();
  try {
    const result = await createScheduledCall(state.client, req.body);
    const response = {
      status: 'submitted',
      eventId: result.eventId,
      joinLink: result.joinLink,
      teacherLinkSubmitted: true,
      startTime: result.startTime,
      endTime: result.endTime,
    };
    eventRecord = {
      idempotencyKey,
      status: 'submitted',
      eventId: result.eventId,
      joinLink: result.joinLink,
      startTime: result.startTime,
      endTime: result.endTime,
      customerReference: result.customerReference,
      teacherReference: result.teacherReference,
      creatorReference: result.creatorReference,
      teacherMessageId: result.teacherMessageId,
      createdAt: new Date().toISOString(),
      response,
      cancelled: false,
    };
    await persistEventRecord();
    return res.json(response);
  } catch (error) {
    if (error.uncertain) {
      eventRecord = {
        idempotencyKey,
        status: 'submitted_unconfirmed',
        eventId: error.eventId || null,
        joinLink: error.joinLink || null,
        createdAt: new Date().toISOString(),
        cancelled: false,
      };
      await persistEventRecord();
    } else {
      eventRecord = null;
      await persistEventRecord();
    }
    return res.status(error.uncertain ? 202 : 400).json({
      status: error.uncertain ? 'submitted_unconfirmed' : 'failed',
      error: error.message,
      eventId: error.eventId || null,
      joinLink: error.joinLink || null,
      doNotRetryAutomatically: Boolean(error.uncertain),
    });
  }
});

app.post('/events/:eventId/cancel', async (req, res) => {
  if (state.phase !== 'authenticated' || !state.client) return res.status(503).json({ error: 'OpenWA is not authenticated.' });
  try {
    const cancelled = await cancelScheduledCall(state.client, req.params.eventId);
    if (eventRecord && eventRecord.eventId === req.params.eventId) {
      eventRecord.cancelled = Boolean(cancelled);
      await persistEventRecord();
    }
    return res.json({ cancelled: Boolean(cancelled) });
  } catch (error) {
    return res.status(400).json({ error: error.message });
  }
});

const server = app.listen(config.port, config.host, () => console.log('OpenWA PoC interface listening on ' + config.host + ':' + config.port));
let stopping = false;
async function stop() {
  if (stopping) return;
  stopping = true;
  state.phase = 'stopping';
  server.close();
  if (state.client && state.client.kill) await state.client.kill().catch(() => {});
  process.exit(0);
}
process.on('SIGTERM', stop);
process.on('SIGINT', stop);

if (config.allowLiveTest) {
  startOpenWa(config, {
    onQr: (qr) => { state.qr = qr; state.phase = 'qr_ready'; },
    onQrScanned: () => { state.qr = null; state.phase = 'authenticating'; },
    onQrExpired: () => { state.qr = null; state.phase = 'starting'; },
    onAuthenticated: (client) => { state.client = client; state.qr = null; state.phase = 'authenticated'; },
  }).catch((error) => {
    state.phase = 'failed';
    state.error = 'OpenWA startup/authentication failed. Inspect restricted server logs.';
    console.error('OpenWA startup failed: ' + error.message);
  });
}
