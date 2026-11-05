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
    if (status.phase === 'qr_ready') {
      qr.src = '/pairing/qr?t=' + Date.now();
      qr.hidden = false;
    } else {
      qr.hidden = true;
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
