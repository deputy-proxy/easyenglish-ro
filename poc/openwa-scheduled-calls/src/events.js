import { normalizeE164, normalizeEventTimes, validateTimeZone } from './config.js';
const defaultSleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
export function validateCallEvent(input, options = {}) {
  if (!input || typeof input !== 'object' || Array.isArray(input)) throw new Error('A JSON object is required.');
  if (typeof input.externalBookingReference !== 'string' || !/^[A-Za-z0-9_-]{1,100}$/.test(input.externalBookingReference)) throw new Error('externalBookingReference must contain 1 to 100 letters, numbers, underscores, or hyphens.');
  const destinationPhone = normalizeE164(input.destinationPhone);
  const { startTime, endTime } = normalizeEventTimes(input.startAt, input.endAt, options.now);
  const timezone = validateTimeZone(input.timezone);
  if (typeof input.title !== 'string' || input.title.trim().length < 1 || input.title.trim().length > 120) throw new Error('title must contain 1 to 120 characters.');
  if (input.callType !== 'video') throw new Error('Only video call events are supported.');
  return { externalBookingReference: input.externalBookingReference, destinationPhone, startAt: new Date(startTime * 1000).toISOString(), endAt: new Date(endTime * 1000).toISOString(), startTime, endTime, timezone, title: input.title.trim(), callType: 'video' };
}
export async function createScheduledCall(client, input, options = {}) {
  const validated = validateCallEvent(input, options), sleep = options.sleep || defaultSleep;
  const chatId = validated.destinationPhone.slice(1) + '@c.us';
  let eventId;
  try { eventId = await client.sendEvent(chatId, { name: validated.title, startTime: validated.startTime, endTime: validated.endTime, callType: 'video', isScheduledCall: true, extraGuestsAllowed: true }); }
  catch (cause) { const error = new Error('OpenWA event submission failed with an uncertain provider outcome. Do not retry with a new idempotency key.'); error.cause = cause; error.uncertain = true; throw error; }
  if (typeof eventId !== 'string' || !eventId) { const error = new Error('OpenWA did not return a usable event ID.'); error.uncertain = true; throw error; }
  let event = null, retrievalError = null;
  for (let attempt = 0; attempt < 6; attempt += 1) {
    try { event = await client.getEvent(eventId); if (event?.joinLink) break; } catch (error) { retrievalError = error; }
    if (attempt < 5) await sleep(1000);
  }
  if (!event?.joinLink || event.isCanceled) { const error = new Error('Event was submitted, but no active join URL was returned. Do not resubmit.'); error.providerEventId = eventId; error.cause = retrievalError; error.uncertain = true; throw error; }
  return { providerEventId: eventId, joinUrl: event.joinLink, status: 'scheduled', startAt: validated.startAt, endAt: validated.endAt, timezone: validated.timezone };
}
export async function retrieveScheduledCall(client, providerEventId) {
  if (typeof providerEventId !== 'string' || !/^[\w.@:-]{8,160}$/.test(providerEventId)) throw new Error('A valid provider event ID is required.');
  const event = await client.getEvent(providerEventId);
  if (!event) return null;
  return { providerEventId, joinUrl: typeof event.joinLink === 'string' ? event.joinLink : null, status: event.isCanceled ? 'cancelled' : 'scheduled' };
}
export async function cancelScheduledCall(client, providerEventId) {
  if (typeof providerEventId !== 'string' || !/^[\w.@:-]{8,160}$/.test(providerEventId)) throw new Error('A valid provider event ID is required.');
  return Boolean(await client.cancelEvent(providerEventId));
}
