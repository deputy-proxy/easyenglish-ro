export function decideEventRequest(record, idempotencyKey) {
  if (!record) return { type: 'create' };

  if (record.idempotencyKey === idempotencyKey) {
    if (record.status === 'submitted') return { type: 'replay', response: record.response };
    return { type: 'pending', status: record.status, eventId: record.eventId || null };
  }

  if (record.cancelled) return { type: 'create' };
  return { type: 'conflict' };
}
