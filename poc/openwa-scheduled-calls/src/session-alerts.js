import { randomUUID } from 'node:crypto';

export async function sendDisconnectionAlert(config, reason, {
  fetchImpl = fetch,
  logger = console,
  clock = () => new Date(),
  wait = (milliseconds) => new Promise((resolve) => setTimeout(resolve, milliseconds)),
} = {}) {
  if (!config.statusCallbackUrl || !config.serviceToken) {
    logger.error('openwa.session.alert_not_configured');
    return false;
  }

  const payload = JSON.stringify({
    eventId: randomUUID(),
    sessionId: config.sessionId,
    reason,
    disconnectedAt: clock().toISOString(),
  });

  for (const delay of [0, 250, 1000]) {
    if (delay > 0) await wait(delay);
    try {
      const response = await fetchImpl(config.statusCallbackUrl, {
        method: 'POST',
        headers: {
          Authorization: `Bearer ${config.serviceToken}`,
          'Content-Type': 'application/json',
          Accept: 'application/json',
        },
        body: payload,
        signal: AbortSignal.timeout(5000),
      });
      if (response.ok) {
        logger.info('openwa.session.alert_delivered', { status: response.status });
        return true;
      }
      if (response.status < 500 && response.status !== 429) {
        logger.error('openwa.session.alert_rejected', { status: response.status });
        return false;
      }
    } catch {
      // Retry transient network errors without logging credentials or callback payloads.
    }
  }

  logger.error('openwa.session.alert_delivery_failed');
  return false;
}
