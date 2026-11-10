{{ $locale === 'en' ? 'ACTION REQUIRED: EASYENGLISH WHATSAPP DISCONNECTED' : 'ACȚIUNE NECESARĂ: SESIUNEA WHATSAPP EASYENGLISH ESTE DECONECTATĂ' }}

{{ $locale === 'en' ? 'The OpenWA session is no longer connected. WhatsApp messages and scheduled call operations may be unavailable until the session reconnects.' : 'Sesiunea OpenWA nu mai este conectată. Mesajele WhatsApp și apelurile programate pot fi indisponibile până la reconectarea sesiunii.' }}

{{ $locale === 'en' ? 'Session identifier' : 'Identificator sesiune' }}: {{ $sessionId }}
{{ $locale === 'en' ? 'Detected at (UTC)' : 'Detectată la (UTC)' }}: {{ $disconnectedAt->toIso8601String() }}
{{ $locale === 'en' ? 'Disconnect reason' : 'Motivul deconectării' }}: {{ str_replace('_', ' ', $reason) }}

{{ $locale === 'en' ? 'Recommended action:' : 'Acțiuni recomandate:' }}
1. {{ $locale === 'en' ? 'Check the OpenWA service health and recent logs.' : 'Verifică starea serviciului OpenWA și jurnalele recente.' }}
2. {{ $locale === 'en' ? 'Confirm whether the WhatsApp device has been logged out.' : 'Verifică dacă dispozitivul WhatsApp a fost deconectat.' }}
3. {{ $locale === 'en' ? 'Reconnect the device by scanning its pairing QR code if required.' : 'Reconectează dispozitivul prin scanarea codului QR de asociere, dacă este necesar.' }}
4. {{ $locale === 'en' ? 'Verify the session is authenticated and send a controlled test message.' : 'Verifică autentificarea sesiunii și trimite un mesaj de test controlat.' }}
