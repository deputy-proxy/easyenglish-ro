ACTION REQUIRED: EASYENGLISH WHATSAPP DISCONNECTED

The OpenWA session is no longer connected. WhatsApp messages and scheduled call operations may be unavailable until the session reconnects.

Session identifier: {{ $sessionId }}
Detected at (UTC): {{ $disconnectedAt->toIso8601String() }}
Disconnect reason: {{ str_replace('_', ' ', $reason) }}

Recommended action:
1. Check the OpenWA service health and recent logs.
2. Confirm whether the WhatsApp device has been logged out.
3. Reconnect the device by scanning its pairing QR code if required.
4. Verify the session is authenticated and send a controlled test message.
