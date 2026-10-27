<?php

namespace App\Http\Controllers;

use App\Services\Notifications\WhatsAppWebhookService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class WhatsAppWebhookController extends Controller
{
    public function verify(Request $request): Response
    {
        $verifyToken = config('services.whatsapp.verify_token');
        $mode = $request->query('hub.mode', $request->query('hub_mode'));
        $token = $request->query('hub.verify_token', $request->query('hub_verify_token'));
        $challenge = $request->query('hub.challenge', $request->query('hub_challenge'));
        if ($mode === 'subscribe' && is_string($verifyToken) && $verifyToken !== ''
            && is_string($token) && hash_equals($verifyToken, $token) && is_string($challenge)) {
            return response($challenge, 200)->header('Content-Type', 'text/plain');
        }

        Log::warning('WhatsApp webhook verification rejected.', [
            'mode_subscribe' => $mode === 'subscribe',
            'verify_token_configured' => is_string($verifyToken) && $verifyToken !== '',
        ]);

        return response('Webhook verification failed.', 403)->header('Content-Type', 'text/plain');
    }

    public function receive(Request $request, WhatsAppWebhookService $webhooks): Response
    {
        $payload = $request->getContent();
        $signature = $request->header('X-Hub-Signature-256', '');

        Log::info('WhatsApp webhook request received.', [
            'method' => $request->method(),
            'signature_header_present' => $signature !== '',
            'payload_bytes' => strlen($payload),
        ]);

        if (! $webhooks->verifySignature($payload, $signature)) {
            Log::warning('WhatsApp webhook signature rejected.', [
                'signature_header_present' => $signature !== '',
                'app_secret_configured' => is_string(config('services.whatsapp.app_secret'))
                    && config('services.whatsapp.app_secret') !== '',
                'payload_bytes' => strlen($payload),
            ]);

            return response('Invalid webhook signature.', 401);
        }

        $data = json_decode($payload, true);
        if (! is_array($data) || ($data['object'] ?? null) !== 'whatsapp_business_account') {
            Log::warning('WhatsApp webhook payload rejected.', [
                'json_valid' => json_last_error() === JSON_ERROR_NONE,
                'object_valid' => is_array($data) && ($data['object'] ?? null) === 'whatsapp_business_account',
                'payload_bytes' => strlen($payload),
            ]);

            return response('Invalid webhook payload.', 400);
        }

        try {
            $summary = $webhooks->handle($data);
            Log::info('WhatsApp webhook processed.', $summary);
        } catch (\Throwable $exception) {
            Log::error('WhatsApp webhook processing failed.', [
                'exception' => $exception::class,
            ]);

            return response('Webhook processing failed.', 500);
        }

        return response('EVENT_RECEIVED', 200);
    }
}
