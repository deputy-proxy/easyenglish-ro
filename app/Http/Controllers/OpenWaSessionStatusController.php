<?php

namespace App\Http\Controllers;

use App\Mail\OpenWaSessionDisconnected;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\HttpFoundation\Response;

class OpenWaSessionStatusController extends Controller
{
    public function disconnected(Request $request): Response
    {
        $serviceToken = config('services.openwa.service_token');
        $providedToken = $request->bearerToken();
        if (! is_string($serviceToken) || strlen($serviceToken) < 32
            || ! is_string($providedToken) || ! hash_equals($serviceToken, $providedToken)) {
            return response('Unauthorised.', 401);
        }

        $validated = $request->validate([
            'eventId' => ['required', 'string', 'uuid'],
            'sessionId' => ['required', 'string', 'max:80'],
            'reason' => ['required', 'string', 'in:connection_lost,logged_out,startup_failed'],
            'disconnectedAt' => ['required', 'date'],
        ]);

        $eventKey = hash('sha256', 'openwa-session-disconnected|'.$validated['eventId']);
        $claimed = DB::table('whatsapp_webhook_events')->insertOrIgnore([
            'event_key' => $eventKey,
            'event_type' => 'openwa_session_disconnected',
            'processed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]) === 1;

        if (! $claimed) {
            return response()->json(['status' => 'duplicate'], 202);
        }

        $recipients = User::query()->where('is_staff', true)->whereNotNull('email')->pluck('email')->all();
        if ($recipients === []) {
            Log::error('OpenWA disconnection alert has no staff email recipients.', [
                'session_id' => $validated['sessionId'],
                'reason' => $validated['reason'],
            ]);

            return response()->json(['status' => 'accepted', 'emails_sent' => 0], 202);
        }

        $disconnectedAt = CarbonImmutable::parse($validated['disconnectedAt'])->utc();
        try {
            Mail::to($recipients)->send(new OpenWaSessionDisconnected(
                sessionId: $validated['sessionId'],
                reason: $validated['reason'],
                disconnectedAt: $disconnectedAt,
                messageLocale: app()->getLocale(),
            ));
        } catch (\Throwable $exception) {
            DB::table('whatsapp_webhook_events')->where('event_key', $eventKey)->delete();
            Log::error('OpenWA disconnection alert email delivery failed.', [
                'exception' => $exception::class,
                'session_id' => $validated['sessionId'],
            ]);

            return response('Alert email delivery failed.', 503);
        }

        Log::warning('OpenWA session disconnection alert emailed to staff.', [
            'session_id' => $validated['sessionId'],
            'reason' => $validated['reason'],
            'recipient_count' => count($recipients),
        ]);

        return response()->json(['status' => 'sent', 'recipient_count' => count($recipients)], 202);
    }
}
