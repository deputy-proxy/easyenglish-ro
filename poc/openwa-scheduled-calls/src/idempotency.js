import { createHash } from 'node:crypto';
export function hashRequest(input) { return createHash('sha256').update(JSON.stringify(input)).digest('hex'); }
export function decideEventRequest(records, key, requestHash) {
  const existing = records.find((record) => record.idempotencyKey === key);
  if (!existing) return { type: 'create' };
  if (existing.requestHash !== requestHash) return { type: 'key_conflict' };
  if (existing.status === 'scheduled') return { type: 'replay', record: existing };
  return { type: 'pending', record: existing };
}
