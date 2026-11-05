import { createHash } from 'node:crypto';
import { normalizeE164, normalizeStartTime } from './config.js';

const defaultSleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

export async function createScheduledCall(client, input, options = {}) {
  const now = options.now === undefined ? Date.now() : options.now;
  const sleep = options.sleep || defaultSleep;
  const customer = normalizeE164(input.customer, 'Customer number');
  const teacher = normalizeE164(input.teacher, 'Teacher number');
  if (customer === teacher) throw new Error('Customer and teacher must be different WhatsApp accounts.');

  const startTime = Math.floor(normalizeStartTime(input.startAt, now) / 1000);
  const endTime = startTime + 15 * 60;
  const customerChatId = customer.slice(1) + '@c.us';
  const teacherChatId = teacher.slice(1) + '@c.us';

  const hostAccount = await client.getMe();
  const creatorId = typeof hostAccount?.me === 'string' ? hostAccount.me : hostAccount?.me?._serialized;
  if (!creatorId) throw new Error('OpenWA did not expose its authenticated account ID; cannot verify three distinct test accounts.');
  if (creatorId === customerChatId || creatorId === teacherChatId) throw new Error('The OpenWA creator, customer, and teacher must be three distinct WhatsApp accounts.');

  let eventId;
  try {
    // OpenWA v5 returns a serialized message ID, which getEvent accepts as eventId.
    eventId = await client.sendEvent(customerChatId, {
      name: 'EasyEnglish introductory session',
      startTime,
      endTime,
      callType: 'video',
      isScheduledCall: true,
      extraGuestsAllowed: true,
    });
  } catch (cause) {
    const error = new Error('OpenWA event submission failed with an uncertain provider outcome. Inspect the creator account before retrying.');
    error.cause = cause;
    error.uncertain = true;
    throw error;
  }

  if (typeof eventId !== 'string' || eventId.length === 0) {
    const error = new Error('OpenWA did not return a usable event message ID. The submission outcome may be uncertain.');
    error.uncertain = true;
    throw error;
  }

  let event = null;
  let retrievalError = null;
  for (let attempt = 0; attempt < 6; attempt += 1) {
    try {
      event = await client.getEvent(eventId);
      if (event && event.joinLink) break;
    } catch (error) {
      retrievalError = error;
    }
    await sleep(1000);
  }
  if (!event || !event.joinLink) {
    const error = new Error('Event was submitted, but OpenWA did not expose a join URL. The event may already exist; do not resubmit.');
    error.eventId = eventId;
    error.cause = retrievalError;
    error.uncertain = true;
    throw error;
  }
  if (event.isCanceled) {
    const error = new Error('OpenWA returned a cancelled event. The event ID exists; do not resubmit automatically.');
    error.eventId = eventId;
    error.uncertain = true;
    throw error;
  }

  let teacherMessageId;
  try {
    teacherMessageId = await client.sendText(teacherChatId, event.joinLink);
  } catch (cause) {
    const error = new Error('The event exists, but teacher link delivery failed with an uncertain outcome. Do not create another event.');
    error.eventId = eventId;
    error.joinLink = event.joinLink;
    error.cause = cause;
    error.uncertain = true;
    throw error;
  }
  if (!teacherMessageId) {
    const error = new Error('The event exists, but teacher link submission was not confirmed. Do not create another event.');
    error.eventId = eventId;
    error.joinLink = event.joinLink;
    error.uncertain = true;
    throw error;
  }

  return {
    eventId,
    joinLink: event.joinLink,
    startTime,
    endTime,
    customerReference: createHash('sha256').update(customer).digest('hex').slice(0, 12),
    teacherReference: createHash('sha256').update(teacher).digest('hex').slice(0, 12),
    creatorReference: createHash('sha256').update(creatorId).digest('hex').slice(0, 12),
    teacherMessageId,
  };
}

export async function cancelScheduledCall(client, eventId) {
  if (typeof eventId !== 'string' || !/^[\w.@:-]{8,160}$/.test(eventId)) throw new Error('A valid OpenWA event ID is required.');
  return client.cancelEvent(eventId);
}
