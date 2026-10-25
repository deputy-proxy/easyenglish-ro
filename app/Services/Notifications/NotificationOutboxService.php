<?php

namespace App\Services\Notifications;

use App\Enums\NotificationEvent;
use App\Models\Booking;
use App\Models\BundleEntitlement;
use App\Models\NotificationDelivery;
use App\Models\PaymentRefund;
use App\Models\WhatsAppContact;
use InvalidArgumentException;

class NotificationOutboxService
{
    public function enqueueBookingConfirmation(Booking $booking): ?NotificationDelivery
    {
        if ($booking->status !== 'confirmed') {
            return null;
        }

        $event = $booking->bundle_entitlement_id === null
            ? NotificationEvent::IntroBookingConfirmed
            : NotificationEvent::AppointmentConfirmed;

        return $this->enqueueBookingEvent($booking, $event);
    }

    /** @param array<string, mixed> $metadata */
    public function enqueueBookingEvent(Booking $booking, NotificationEvent|string $event, array $metadata = [], ?string $eventKey = null): NotificationDelivery
    {
        $eventValue = $this->eventValue($event);
        $metadata = array_merge(['snapshot' => [
            'starts_at' => $booking->starts_at->toIso8601String(),
            'ends_at' => $booking->ends_at->toIso8601String(),
        ]], $metadata);
        $key = 'email:'.$eventValue.':booking:'.$booking->id.($eventKey !== null ? ':'.substr(hash('sha256', $eventKey), 0, 24) : '');

        $delivery = NotificationDelivery::query()->firstOrCreate(
            ['idempotency_key' => $key],
            [
                'booking_id' => $booking->id,
                'bundle_entitlement_id' => $booking->bundle_entitlement_id,
                'channel' => 'email',
                'event' => $eventValue,
                'status' => 'pending',
                'attempts' => 0,
                'next_attempt_at' => now(),
                'metadata' => $metadata,
            ],
        );
        $this->enqueueWhatsApp($eventValue, $booking->customer_phone, $booking->id, $booking->bundle_entitlement_id, null, $metadata, $eventKey);

        return $delivery;
    }

    /** @param array<string, mixed> $metadata */
    public function enqueueEntitlementEvent(BundleEntitlement $entitlement, NotificationEvent|string $event, array $metadata = [], ?string $eventKey = null): ?NotificationDelivery
    {
        $eventValue = $this->eventValue($event);
        if (in_array($eventValue, [NotificationEvent::BundlePurchaseConfirmed->value, NotificationEvent::BundleExpiry14Days->value, NotificationEvent::BundleExpiry3Days->value], true)) {
            $entitlement->loadMissing('definition');
            $metadata = array_merge(['snapshot' => [
                'bundle_name' => $entitlement->definition->name,
                'amount_ron' => $entitlement->paid_amount_ron,
                'paid_credits' => $entitlement->paid_credits,
                'free_credits_remaining' => $entitlement->free_credits_remaining,
                'credits_remaining' => $entitlement->credits_remaining,
                'expires_at' => $entitlement->expires_at?->toIso8601String(),
            ]], $metadata);
        }
        if ($eventValue === NotificationEvent::BundlePurchaseConfirmed->value
            && ($entitlement->status !== 'active' || ! $entitlement->payments()->where('status', 'succeeded')->exists())) {
            return null;
        }
        if (in_array($eventValue, [NotificationEvent::BundleExpiry14Days->value, NotificationEvent::BundleExpiry3Days->value], true)
            && ($entitlement->status !== 'active' || $entitlement->expires_at === null || $entitlement->expires_at->isPast()
                || $entitlement->credits_remaining + $entitlement->free_credits_remaining < 1)) {
            return null;
        }

        $key = 'email:'.$eventValue.':entitlement:'.$entitlement->id.($eventKey !== null ? ':'.substr(hash('sha256', $eventKey), 0, 24) : '');

        $delivery = NotificationDelivery::query()->firstOrCreate(
            ['idempotency_key' => $key],
            [
                'bundle_entitlement_id' => $entitlement->id,
                'channel' => 'email',
                'event' => $eventValue,
                'status' => 'pending',
                'attempts' => 0,
                'next_attempt_at' => now(),
                'metadata' => $metadata,
            ],
        );
        $this->enqueueWhatsApp($eventValue, $entitlement->customer_phone, null, $entitlement->id, null, $metadata, $eventKey);

        return $delivery;
    }

    public function enqueueRefundOutcome(PaymentRefund $refund): ?NotificationDelivery
    {
        $event = match ($refund->status) {
            'succeeded' => NotificationEvent::RefundSucceeded,
            'failed' => NotificationEvent::RefundFailed,
            default => null,
        };
        if ($event === null) {
            return null;
        }

        $entitlementId = $refund->payment()->value('bundle_entitlement_id');
        if (! is_int($entitlementId) && ! ctype_digit((string) $entitlementId)) {
            return null;
        }

        $key = 'email:'.$event->value.':refund:'.$refund->id;

        $delivery = NotificationDelivery::query()->firstOrCreate(
            ['idempotency_key' => $key],
            [
                'bundle_entitlement_id' => (int) $entitlementId,
                'payment_refund_id' => $refund->id,
                'channel' => 'email',
                'event' => $event->value,
                'status' => 'pending',
                'attempts' => 0,
                'next_attempt_at' => now(),
                'metadata' => [],
            ],
        );
        $phone = $refund->payment->entitlement->customer_phone;
        $this->enqueueWhatsApp($event->value, $phone, null, (int) $entitlementId, $refund->id, [], null);

        return $delivery;
    }

    public function queueDueReminders(): int
    {
        $created = 0;
        $now = now();
        foreach ([
            [NotificationEvent::AppointmentReminder24Hours, 24],
            [NotificationEvent::AppointmentReminder1Hour, 1],
        ] as [$event, $hours]) {
            $window = [$now->copy()->addHours($hours)->subMinutes(15), $now->copy()->addHours($hours)->addMinutes(15)];
            Booking::query()->with('entitlement')
                ->where('status', 'confirmed')
                ->whereBetween('starts_at', $window)
                ->orderBy('id')
                ->chunkById(100, function ($bookings) use (&$created, $event): void {
                    foreach ($bookings as $booking) {
                        if ($booking->bundle_entitlement_id !== null
                            && ($booking->entitlement === null || $booking->entitlement->status !== 'active'
                                || $booking->entitlement->expires_at === null
                                || $booking->entitlement->expires_at->lessThanOrEqualTo($booking->starts_at))) {
                            continue;
                        }
                        $delivery = $this->enqueueBookingEvent($booking, $event, [], $booking->starts_at->toIso8601String());
                        if ($delivery->wasRecentlyCreated) {
                            $created++;
                        }
                    }
                });
        }

        foreach ([
            [NotificationEvent::BundleExpiry14Days, 14],
            [NotificationEvent::BundleExpiry3Days, 3],
        ] as [$event, $days]) {
            $window = [$now->copy()->addDays($days)->subHour(), $now->copy()->addDays($days)->addHour()];
            BundleEntitlement::query()->where('status', 'active')
                ->where('expires_at', '>', $now)
                ->whereBetween('expires_at', $window)
                ->whereRaw('(credits_remaining + free_credits_remaining) > 0')
                ->orderBy('id')
                ->chunkById(100, function ($entitlements) use (&$created, $event): void {
                    foreach ($entitlements as $entitlement) {
                        $delivery = $this->enqueueEntitlementEvent($entitlement, $event);
                        if ($delivery?->wasRecentlyCreated) {
                            $created++;
                        }
                    }
                });
        }

        return $created;
    }

    /** @param array<string, mixed> $metadata */
    private function enqueueWhatsApp(string $event, string $phone, ?int $bookingId, ?int $entitlementId, ?int $refundId, array $metadata, ?string $eventKey): ?NotificationDelivery
    {
        if (! (bool) config('services.whatsapp.enabled')) {
            return null;
        }
        $contact = WhatsAppContact::query()->where('phone', $phone)->first();
        if ($contact === null || ! $contact->canReceiveServiceMessages()) {
            return null;
        }
        $key = 'whatsapp:'.$event.':'.($bookingId !== null ? 'booking:'.$bookingId : 'entitlement:'.$entitlementId).($refundId !== null ? ':refund:'.$refundId : '').($eventKey !== null ? ':'.substr(hash('sha256', $eventKey), 0, 24) : '');

        return NotificationDelivery::query()->firstOrCreate(
            ['idempotency_key' => $key],
            [
                'booking_id' => $bookingId,
                'bundle_entitlement_id' => $entitlementId,
                'payment_refund_id' => $refundId,
                'channel' => 'whatsapp',
                'event' => $event,
                'status' => 'pending',
                'attempts' => 0,
                'next_attempt_at' => now(),
                'metadata' => $metadata,
            ],
        );
    }

    private function eventValue(NotificationEvent|string $event): string
    {
        if ($event instanceof NotificationEvent) {
            return $event->value;
        }
        if (NotificationEvent::tryFrom($event) === null) {
            throw new InvalidArgumentException('Unsupported transactional notification event.');
        }

        return $event;
    }
}
