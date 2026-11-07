<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head>
<body style="margin:0;padding:32px 16px;background:#f5f5f4;color:#1c1917;font-family:Arial,sans-serif;">
  <div style="max-width:560px;margin:0 auto;padding:28px;background:#fff;border:1px solid #e7e5e4;border-radius:12px;">
    <p style="margin:0 0 12px;color:#b91c1c;font-size:12px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;">WhatsApp connection alert</p>
    <h1 style="margin:0 0 16px;font-size:22px;line-height:1.3;">The EasyEnglish WhatsApp session has disconnected</h1>
    <p style="margin:0 0 20px;line-height:1.6;">The OpenWA session is no longer connected. WhatsApp messages and scheduled call operations may be unavailable until the session reconnects.</p>
    <table role="presentation" style="width:100%;border-collapse:collapse;font-size:14px;">
      <tr><td style="padding:10px 0;color:#78716c;">Session</td><td style="padding:10px 0;text-align:right;font-weight:600;">{{ $sessionId }}</td></tr>
      <tr><td style="padding:10px 0;color:#78716c;">Detected</td><td style="padding:10px 0;text-align:right;font-weight:600;">{{ $disconnectedAt->toDayDateTimeString() }} UTC</td></tr>
      <tr><td style="padding:10px 0;color:#78716c;">Reason</td><td style="padding:10px 0;text-align:right;font-weight:600;">{{ str_replace('_', ' ', $reason) }}</td></tr>
    </table>
    <p style="margin:20px 0 0;line-height:1.6;">Check the OpenWA service and reconnect the WhatsApp device if necessary.</p>
  </div>
</body>
</html>
