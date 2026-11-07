const statusEl = document.querySelector('#status');
const qr = document.querySelector('#qr');
const result = document.querySelector('#result');
const submitButton = document.querySelector('#eventForm button');
const idempotencyKey = crypto.randomUUID();

async function refresh() {
  try {
    const response = await fetch('/pairing/status', { cache: 'no-store' });
    const status = await response.json();
    statusEl.textContent = 'Status: ' + status.phase + (status.error ? ' · ' + status.error : '');
    if (status.phase === 'qr_ready' && status.qrAvailable) {
      qr.src = '/pairing/qr?t=' + Date.now();
      qr.hidden = false;
    } else {
      qr.hidden = true;
      if (status.phase === 'qr_ready') statusEl.textContent += ' · QR payload unavailable; waiting for a fresh code.';
    }
  } catch {
    statusEl.textContent = 'Service unavailable';
  }
  setTimeout(refresh, 2500);
}

document.querySelector('#eventForm').addEventListener('submit', async (event) => {
  event.preventDefault();
  submitButton.disabled = true;
  result.textContent = 'Creating event…';
  const payload = {
    customer: document.querySelector('#customer').value,
    teacher: document.querySelector('#teacher').value,
    startAt: document.querySelector('#startAt').value,
  };
  try {
    const response = await fetch('/events', {
      method: 'POST',
      headers: { 'content-type': 'application/json', 'Idempotency-Key': idempotencyKey },
      body: JSON.stringify(payload),
    });
    result.textContent = JSON.stringify(await response.json(), null, 2);
  } catch {
    result.textContent = 'Request failed. Retry only with the same browser page so the same idempotency key is reused.';
    submitButton.disabled = false;
  }
});

refresh();


qr.addEventListener('error', () => {
  qr.hidden = true;
  statusEl.textContent = 'QR image could not be loaded. Waiting for the next pairing code…';
});

const resetButton = document.querySelector('#resetPairing');
const resetStatus = document.querySelector('#resetStatus');
resetButton.addEventListener('click', async () => {
  const confirmed = window.confirm('Disconnect this WhatsApp session and start a fresh QR pairing? The current encrypted session will be backed up, but this device will need to be linked again.');
  if (!confirmed) return;
  resetButton.disabled = true;
  resetStatus.textContent = 'Backing up the current session and restarting pairing…';
  try {
    const response = await fetch('/pairing/reset', { method: 'POST', headers: { 'content-type': 'application/json' }, body: '{}' });
    const payload = await response.json();
    if (!response.ok) throw new Error(payload.error || 'Reset failed.');
    resetStatus.textContent = payload.checkpointBackedUp
      ? 'Previous session backed up. Waiting for a fresh QR code…'
      : 'No saved session was found. Waiting for a fresh QR code…';
  } catch (error) {
    resetStatus.textContent = error.message || 'Could not reset the session.';
    resetButton.disabled = false;
  }
});
