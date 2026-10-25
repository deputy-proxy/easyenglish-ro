<?php

namespace App\Services\Payments;

use App\Contracts\Payments\StripeGateway;
use App\Models\BookingAudit;
use App\Models\Payment;
use App\Models\StripeWebhookEvent;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class StripeWebhookService
{
    public function __construct(
        private readonly StripePaymentProcessor $processor,
        private readonly StripeGateway $stripe,
        private readonly StripeRefundService $refunds,
    ) {}

    /** @param array<string, mixed> $event */
    public function handle(array $event, string $rawPayload): void
    {
        $eventId = $event['id'] ?? null;
        $eventType = $event['type'] ?? null;
        $object = $event['data']['object'] ?? null;
        if (! is_string($eventId) || $eventId === '' || ! is_string($eventType) || ! is_array($object)) {
            throw new RuntimeException('Stripe event payload is missing required fields.');
        }

        try {
            DB::transaction(function () use ($eventId, $eventType, $object, $rawPayload): void {
                $record = StripeWebhookEvent::query()->firstOrCreate(
                    ['provider_event_id' => $eventId],
                    ['event_type' => $eventType, 'payload_sha256' => hash('sha256', $rawPayload), 'status' => 'received'],
                );
                $record = StripeWebhookEvent::query()->lockForUpdate()->findOrFail($record->id);
                if ($record->payload_sha256 !== hash('sha256', $rawPayload)) {
                    throw new RuntimeException('Stripe event ID was reused with a different payload hash.');
                }
                if ($record->status === 'processed') {
                    return;
                }

                $record->update(['attempts' => $record->attempts + 1, 'status' => 'processing', 'last_error' => null]);
                $this->processEvent($eventId, $eventType, $object);
                $record->update(['status' => 'processed', 'processed_at' => now(), 'last_error' => null]);
            }, attempts: 3);
        } catch (QueryException $exception) {
            $record = StripeWebhookEvent::query()->where('provider_event_id', $eventId)->first();
            if ($record?->status === 'processed' && hash_equals((string) $record->payload_sha256, hash('sha256', $rawPayload))) {
                return;
            }
            $this->recordFailure($eventId, $eventType, $rawPayload, $exception->getMessage());
            throw $exception;
        } catch (\Throwable $exception) {
            $this->recordFailure($eventId, $eventType, $rawPayload, $exception->getMessage());
            throw $exception;
        }
    }

    /** @param array<string, mixed> $object */
    private function processEvent(string $eventId, string $eventType, array $object): void
    {
        if (in_array($eventType, ['checkout.session.completed', 'checkout.session.async_payment_succeeded'], true)) {
            $this->processor->reconcileSession($object);

            return;
        }

        if ($eventType === 'checkout.session.expired') {
            $this->processor->reconcileSession($object);

            return;
        }

        if ($eventType === 'checkout.session.async_payment_failed') {
            $this->processor->markAsyncPaymentFailed($object);

            return;
        }

        if ($eventType === 'payment_intent.payment_failed') {
            $metadata = is_array($object['metadata'] ?? null) ? $object['metadata'] : [];
            $paymentId = filter_var($metadata['payment_id'] ?? null, FILTER_VALIDATE_INT);
            if (is_int($paymentId) && $paymentId > 0) {
                $payment = Payment::query()->find($paymentId);
                if ($payment) {
                    BookingAudit::query()->create([
                        'bundle_entitlement_id' => $payment->bundle_entitlement_id,
                        'action' => 'payment.provider_failure_observed',
                        'metadata' => ['payment_id' => $payment->id, 'stripe_event_id' => $eventId],
                    ]);
                }
            }

            return;
        }

        if ($eventType === 'refund.updated' || $eventType === 'refund.created' || $eventType === 'refund.failed') {
            $refundId = $object['id'] ?? null;
            $currentRefund = is_string($refundId) ? $this->stripe->retrieveRefund($refundId) : $object;
            $this->refunds->reconcileProviderRefund($currentRefund, $eventId);

            return;
        }

        if ($eventType === 'charge.refunded') {
            $refunds = $object['refunds']['data'] ?? [];
            if (is_array($refunds)) {
                foreach ($refunds as $refund) {
                    if (is_array($refund)) {
                        $refundId = $refund['id'] ?? null;
                        $currentRefund = is_string($refundId) ? $this->stripe->retrieveRefund($refundId) : $refund;
                        $this->refunds->reconcileProviderRefund($currentRefund, $eventId);
                    }
                }
            }

            return;
        }

        // Record unrelated Stripe events as processed so delivery retries are not wasted.
    }

    private function recordFailure(string $eventId, string $eventType, string $rawPayload, string $message): void
    {
        try {
            DB::transaction(function () use ($eventId, $eventType, $rawPayload, $message): void {
                $record = StripeWebhookEvent::query()->where('provider_event_id', $eventId)->lockForUpdate()->first();
                if ($record?->status === 'processed') {
                    return;
                }
                if (! $record) {
                    $record = new StripeWebhookEvent(['provider_event_id' => $eventId, 'attempts' => 0]);
                }
                $record->event_type = $eventType;
                $record->payload_sha256 = hash('sha256', $rawPayload);
                $record->status = 'failed';
                $record->attempts = ((int) $record->attempts) + 1;
                $record->last_error = Str::limit($message, 2000);
                $record->save();
            });
        } catch (\Throwable) {
            // The webhook will return a failure response; Stripe will retry if persistence is unavailable.
        }
    }
}
