@include('emails.partials.shell-start', ['locale' => 'en', 'subject' => 'Action required: EasyEnglish WhatsApp disconnected'])
<tr><td style="padding:34px 30px 30px;">
<p style="margin:0 0 12px;color:#B42318;font-size:12px;font-weight:700;letter-spacing:1px;text-align:center;text-transform:uppercase;">Operational alert</p>
<h1 style="margin:0 0 18px;color:#14234B;font-size:26px;line-height:1.2;text-align:center;">The EasyEnglish WhatsApp session has disconnected</h1>
<p style="margin:0 0 22px;color:#53617B;font-size:15px;line-height:1.65;">The OpenWA session is no longer connected. WhatsApp messages and scheduled call operations may be unavailable until the session reconnects.</p>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#F7F8FC;border:1px solid #E1E7F0;border-radius:12px;">
<tr><td style="padding:12px 16px;color:#53617B;font-size:13px;border-bottom:1px solid #E1E7F0;">Session identifier</td><td style="padding:12px 16px;color:#14234B;font-size:14px;font-weight:700;overflow-wrap:anywhere;border-bottom:1px solid #E1E7F0;">{{ $sessionId }}</td></tr>
<tr><td style="padding:12px 16px;color:#53617B;font-size:13px;border-bottom:1px solid #E1E7F0;">Detected at (UTC)</td><td style="padding:12px 16px;color:#14234B;font-size:14px;font-weight:700;border-bottom:1px solid #E1E7F0;">{{ $disconnectedAt->toIso8601String() }}</td></tr>
<tr><td style="padding:12px 16px;color:#53617B;font-size:13px;">Disconnect reason</td><td style="padding:12px 16px;color:#14234B;font-size:14px;font-weight:700;">{{ str_replace('_', ' ', $reason) }}</td></tr>
</table>
<h2 style="margin:24px 0 10px;color:#14234B;font-size:17px;">Recommended action</h2>
<ol style="margin:0;padding-left:22px;color:#53617B;font-size:14px;line-height:1.8;"><li>Check the OpenWA service health and recent logs.</li><li>Confirm whether the WhatsApp device has been logged out.</li><li>Reconnect the device by scanning its pairing QR code if required.</li><li>Verify the session is authenticated and send a controlled test message.</li></ol>
</td></tr>
@include('emails.partials.shell-end')
