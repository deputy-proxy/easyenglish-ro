<?php

namespace App\Http\Controllers;

use App\Services\Payments\StripeWebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;

class StripeWebhookController extends Controller
{
    public function __invoke(Request $request, StripeWebhookService $webhooks): JsonResponse
    {
        $secret = config('services.stripe.webhook_secret');
        if (! is_string($secret) || $secret === '') {
            return response()->json(['error' => 'Webhook endpoint is not configured.'], 503);
        }

        $payload = $request->getContent();
        $signature = $request->header('Stripe-Signature', '');
        try {
            $event = Webhook::constructEvent($payload, $signature, $secret);
        } catch (\UnexpectedValueException|SignatureVerificationException) {
            return response()->json(['error' => 'Invalid Stripe webhook signature or payload.'], 400);
        }

        try {
            $webhooks->handle($event->toArray(), $payload);
        } catch (\Throwable $exception) {
            Log::error('Stripe webhook processing failed; provider retry is required.', [
                'event_id' => $event->id,
                'event_type' => $event->type,
                'exception' => $exception::class,
            ]);

            return response()->json(['error' => 'Webhook processing failed.'], 500);
        }

        return response()->json(['received' => true]);
    }
}
