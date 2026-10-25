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

        return response('Webhook verification failed.', 403)->header('Content-Type', 'text/plain');
    }

    public function receive(Request $request, WhatsAppWebhookService $webhooks): Response
    {
        $payload = $request->getContent();
        if (! $webhooks->verifySignature($payload, $request->header('X-Hub-Signature-256', ''))) {
            return response('Invalid webhook signature.', 401);
        }
        $data = json_decode($payload, true);
        if (! is_array($data) || ($data['object'] ?? null) !== 'whatsapp_business_account') {
            return response('Invalid webhook payload.', 400);
        }

        try {
            $webhooks->handle($data);
        } catch (\Throwable $exception) {
            Log::error('WhatsApp webhook processing failed.', ['exception' => $exception::class]);

            return response('Webhook processing failed.', 500);
        }

        return response('EVENT_RECEIVED', 200);
    }
}
