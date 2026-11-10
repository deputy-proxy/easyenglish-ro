@php
    $english = $locale === 'en';
    $subject = $english ? 'Action required: EasyEnglish WhatsApp disconnected' : 'Acțiune necesară: sesiunea WhatsApp EasyEnglish este deconectată';
@endphp
@include('emails.partials.shell-start', ['locale' => $locale, 'subject' => $subject])
<tr><td style="padding:34px 30px 30px;">
<p style="margin:0 0 12px;color:#B42318;font-size:12px;font-weight:700;letter-spacing:1px;text-align:center;text-transform:uppercase;">{{ $english ? 'Operational alert' : 'Alertă operațională' }}</p>
<h1 style="margin:0 0 18px;color:#14234B;font-size:26px;line-height:1.2;text-align:center;">{{ $english ? 'The EasyEnglish WhatsApp session has disconnected' : 'Sesiunea WhatsApp EasyEnglish s-a deconectat' }}</h1>
<p style="margin:0 0 22px;color:#53617B;font-size:15px;line-height:1.65;">{{ $english ? 'The OpenWA session is no longer connected. WhatsApp messages and scheduled call operations may be unavailable until the session reconnects.' : 'Sesiunea OpenWA nu mai este conectată. Mesajele WhatsApp și apelurile programate pot fi indisponibile până la reconectarea sesiunii.' }}</p>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#F7F8FC;border:1px solid #E1E7F0;border-radius:12px;">
<tr><td style="padding:12px 16px;color:#53617B;font-size:13px;border-bottom:1px solid #E1E7F0;">{{ $english ? 'Session identifier' : 'Identificator sesiune' }}</td><td style="padding:12px 16px;color:#14234B;font-size:14px;font-weight:700;overflow-wrap:anywhere;border-bottom:1px solid #E1E7F0;">{{ $sessionId }}</td></tr>
<tr><td style="padding:12px 16px;color:#53617B;font-size:13px;border-bottom:1px solid #E1E7F0;">{{ $english ? 'Detected at (UTC)' : 'Detectată la (UTC)' }}</td><td style="padding:12px 16px;color:#14234B;font-size:14px;font-weight:700;border-bottom:1px solid #E1E7F0;">{{ $disconnectedAt->toIso8601String() }}</td></tr>
<tr><td style="padding:12px 16px;color:#53617B;font-size:13px;">{{ $english ? 'Disconnect reason' : 'Motivul deconectării' }}</td><td style="padding:12px 16px;color:#14234B;font-size:14px;font-weight:700;">{{ str_replace('_', ' ', $reason) }}</td></tr>
</table>
<h2 style="margin:24px 0 10px;color:#14234B;font-size:17px;">{{ $english ? 'Recommended action' : 'Acțiuni recomandate' }}</h2>
<ol style="margin:0;padding-left:22px;color:#53617B;font-size:14px;line-height:1.8;">
<li>{{ $english ? 'Check the OpenWA service health and recent logs.' : 'Verifică starea serviciului OpenWA și jurnalele recente.' }}</li>
<li>{{ $english ? 'Confirm whether the WhatsApp device has been logged out.' : 'Verifică dacă dispozitivul WhatsApp a fost deconectat.' }}</li>
<li>{{ $english ? 'Reconnect the device by scanning its pairing QR code if required.' : 'Reconectează dispozitivul prin scanarea codului QR de asociere, dacă este necesar.' }}</li>
<li>{{ $english ? 'Verify the session is authenticated and send a controlled test message.' : 'Verifică autentificarea sesiunii și trimite un mesaj de test controlat.' }}</li>
</ol>
</td></tr>
@include('emails.partials.shell-end')
