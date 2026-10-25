<?php

namespace App\Services\Notifications;

use App\Enums\NotificationEvent;
use App\Models\Booking;
use App\Models\BundleEntitlement;
use App\Models\NotificationDelivery;
use App\Models\PaymentRefund;
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

        return NotificationDelivery::query()->firstOrCreate(
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

        return NotificationDelivery::query()->firstOrCreate(
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

        return NotificationDelivery::query()->firstOrCreate(
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
    }

    public function queueDueReminders(): int
    {
        $created = 0;
        $now = now();
        $appointmentWindow = [$now->copy()->addHours(24)->subMinutes(30), $now->copy()->addHours(24)->addMinutes(30)];

        Booking::query()->with('entitlement')
            ->where('status', 'confirmed')
            ->whereBetween('starts_at', $appointmentWindow)
            ->orderBy('id')
            ->chunkById(100, function ($bookings) use (&$created): void {
                foreach ($bookings as $booking) {
                    if ($booking->bundle_entitlement_id !== null
                        && ($booking->entitlement === null || $booking->entitlement->status !== 'active'
                            || $booking->entitlement->expires_at === null
                            || $booking->entitlement->expires_at->lessThanOrEqualTo($booking->starts_at))) {
                        continue;
                    }
                    $delivery = $this->enqueueBookingEvent($booking, NotificationEvent::AppointmentReminder24Hours);
                    if ($delivery->wasRecentlyCreated) {
                        $created++;
                    }
                }
            });

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
