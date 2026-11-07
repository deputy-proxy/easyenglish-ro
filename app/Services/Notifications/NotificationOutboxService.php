<?php

namespace App\Services\Notifications;

use App\Enums\NotificationEvent;
use App\Models\Booking;
use App\Models\BookingCallEvent;
use App\Models\BundleEntitlement;
use App\Models\NotificationDelivery;
use App\Models\PaymentRefund;
use App\Models\User;
use App\Models\WhatsAppContact;
use App\Support\PhoneNumber;
use Carbon\CarbonImmutable;
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

    public function enqueueTeacherBookingConfirmed(Booking $booking): ?NotificationDelivery
    {
        if ($booking->status !== 'confirmed' || $booking->teacher_id === null) {
            return null;
        }
        $teacher = User::query()->find($booking->teacher_id);
        if ($teacher === null || ! $teacher->isTeacher()) {
            return null;
        }

        $metadata = ['snapshot' => [
            'starts_at' => $booking->starts_at->toIso8601String(),
            'ends_at' => $booking->ends_at->toIso8601String(),
        ]];
        $delivery = $this->createRecipientDelivery(
            'email', NotificationEvent::TeacherBookingConfirmed, $booking, $teacher, null, $metadata,
            'email:teacher_booking_confirmed:booking:'.$booking->id.':teacher:'.$teacher->id,
        );
        $this->enqueueTeacherWhatsApp(
            NotificationEvent::TeacherBookingConfirmed, $booking, $teacher, $metadata,
            'whatsapp:teacher_booking_confirmed:booking:'.$booking->id.':teacher:'.$teacher->id,
        );

        return $delivery;
    }

    public function enqueueTeacherBookingRescheduled(Booking $booking, int $teacherId, CarbonImmutable $previousStart): ?NotificationDelivery
    {
        if ($booking->status !== 'confirmed') {
            return null;
        }
        $teacher = User::query()->find($teacherId);
        if ($teacher === null || ! $teacher->isTeacher() || $booking->teacher_id !== $teacher->id) {
            return null;
        }

        $metadata = ['snapshot' => [
            'previous_starts_at' => $previousStart->toIso8601String(),
            'previous_ends_at' => $previousStart->addMinutes($booking->duration_minutes)->toIso8601String(),
            'starts_at' => $booking->starts_at->toIso8601String(),
            'ends_at' => $booking->ends_at->toIso8601String(),
            'revision' => $booking->reschedule_count + 1,
        ]];
        $delivery = $this->createRecipientDelivery(
            'email', NotificationEvent::TeacherBookingRescheduled, $booking, $teacher, null, $metadata,
            'email:teacher_booking_rescheduled:booking:'.$booking->id.':revision:'.($booking->reschedule_count + 1).':teacher:'.$teacher->id,
        );
        $this->enqueueTeacherWhatsApp(
            NotificationEvent::TeacherBookingRescheduled, $booking, $teacher, $metadata,
            'whatsapp:teacher_booking_rescheduled:booking:'.$booking->id.':revision:'.($booking->reschedule_count + 1).':teacher:'.$teacher->id,
        );

        return $delivery;
    }

    /** @return list<NotificationDelivery> */
    public function enqueueCallLinkReady(Booking $booking, BookingCallEvent $event): array
    {
        if (! $this->isCurrentCallEvent($booking, $event)) {
            return [];
        }

        $metadata = ['snapshot' => [
            'starts_at' => $booking->starts_at->toIso8601String(),
            'ends_at' => $booking->ends_at->toIso8601String(),
            'revision' => $event->revision,
        ]];
        $deliveries = [];
        $deliveries[] = $this->createRecipientDelivery(
            'email', NotificationEvent::CustomerCallLinkReady, $booking, null, $event, $metadata,
            'email:customer_call_link_ready:booking:'.$booking->id.':revision:'.$event->revision,
        );
        $this->enqueueRecipientWhatsApp(
            NotificationEvent::CustomerCallLinkReady, $booking, null, $event, $metadata,
            'whatsapp:customer_call_link_ready:booking:'.$booking->id.':revision:'.$event->revision,
        );

        $teacher = User::query()->find($booking->teacher_id);
        if ($teacher === null || ! $teacher->isTeacher()) {
            return $deliveries;
        }

        $deliveries[] = $this->createRecipientDelivery(
            'email', NotificationEvent::TeacherCallLinkReady, $booking, $teacher, $event, $metadata,
            'email:teacher_call_link_ready:booking:'.$booking->id.':revision:'.$event->revision.':teacher:'.$teacher->id,
        );

        $customerPhone = $this->normalizedPhone($booking->customer_phone);
        $teacherPhone = $this->normalizedPhone($teacher->phone);
        if ($teacher->phone_verified_at !== null && $teacherPhone !== null && $teacherPhone !== $customerPhone) {
            $this->enqueueRecipientWhatsApp(
                NotificationEvent::TeacherCallLinkReady, $booking, $teacher, $event, $metadata,
                'whatsapp:teacher_call_link_ready:booking:'.$booking->id.':revision:'.$event->revision.':teacher:'.$teacher->id,
            );
        }

        return $deliveries;
    }

    /** @param array<string, mixed> $metadata
     * @return list<NotificationDelivery>
     */
    public function enqueueTeacherBookingCancelled(Booking $booking, int $teacherId, string $reason, array $metadata = []): array
    {
        $teacher = User::query()->find($teacherId);
        if ($teacher === null || ! $teacher->isTeacher()) {
            return [];
        }

        $metadata = array_merge([
            'reason' => $reason,
            'snapshot' => [
                'starts_at' => $booking->starts_at->toIso8601String(),
                'ends_at' => $booking->ends_at->toIso8601String(),
            ],
        ], $metadata);
        $keySuffix = ':teacher:'.$teacher->id.':reason:'.$reason;
        $deliveries = [$this->createRecipientDelivery(
            'email', NotificationEvent::TeacherBookingCancelled, $booking, $teacher, null, $metadata,
            'email:teacher_booking_cancelled:booking:'.$booking->id.':revision:'.$booking->reschedule_count.$keySuffix,
        )];

        $customerPhone = $this->normalizedPhone($booking->customer_phone);
        $teacherPhone = $this->normalizedPhone($teacher->phone);
        if ($teacher->phone_verified_at !== null && $teacherPhone !== null && $teacherPhone !== $customerPhone) {
            $this->enqueueRecipientWhatsApp(
                NotificationEvent::TeacherBookingCancelled, $booking, $teacher, null, $metadata,
                'whatsapp:teacher_booking_cancelled:booking:'.$booking->id.':revision:'.$booking->reschedule_count.$keySuffix,
            );
        }

        return $deliveries;
    }

    /** @param array<string, mixed> $metadata */
    public function enqueueEntitlementEvent(BundleEntitlement $entitlement, NotificationEvent|string $event, array $metadata = [], ?string $eventKey = null): ?NotificationDelivery
    {
        $eventValue = $this->eventValue($event);
        if (in_array($eventValue, [NotificationEvent::BundlePurchaseConfirmed->value, NotificationEvent::BundleExpiry14Days->value, NotificationEvent::BundleExpiry3Days->value], true)) {
            $entitlement->loadMissing('definition');
            $metadata = array_merge(['snapshot' => [
                'bundle_name' => $entitlement->definition->localizedName($entitlement->locale),
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
        if (! $this->whatsappSendingEnabled()) {
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

    /** @param array<string, mixed> $metadata */
    private function createRecipientDelivery(
        string $channel,
        NotificationEvent $event,
        Booking $booking,
        ?User $recipient,
        ?BookingCallEvent $callEvent,
        array $metadata,
        string $key,
    ): NotificationDelivery {
        return NotificationDelivery::query()->firstOrCreate(
            ['idempotency_key' => $key],
            [
                'booking_id' => $booking->id,
                'recipient_type' => $recipient === null ? 'customer' : 'teacher',
                'recipient_user_id' => $recipient?->id,
                'booking_call_event_id' => $callEvent?->id,
                'bundle_entitlement_id' => $booking->bundle_entitlement_id,
                'channel' => $channel,
                'event' => $event->value,
                'status' => 'pending',
                'attempts' => 0,
                'next_attempt_at' => now(),
                'metadata' => $metadata,
            ],
        );
    }

    /** @param array<string, mixed> $metadata */
    private function enqueueRecipientWhatsApp(
        NotificationEvent $event,
        Booking $booking,
        ?User $recipient,
        ?BookingCallEvent $callEvent,
        array $metadata,
        string $key,
    ): ?NotificationDelivery {
        if (! $this->whatsappSendingEnabled()) {
            return null;
        }

        $phone = $recipient === null ? $booking->customer_phone : $recipient->phone;
        $normalizedPhone = $this->normalizedPhone($phone);
        if ($normalizedPhone === null) {
            return null;
        }
        if ($recipient !== null && (! $recipient->isTeacher() || $recipient->phone_verified_at === null
            || $normalizedPhone === $this->normalizedPhone($booking->customer_phone))) {
            return null;
        }
        $contact = WhatsAppContact::query()->where('phone', $normalizedPhone)->first();
        if ($contact === null || ! $contact->canReceiveServiceMessages()) {
            return null;
        }

        return $this->createRecipientDelivery('whatsapp', $event, $booking, $recipient, $callEvent, $metadata, $key);
    }

    /** @param array<string, mixed> $metadata */
    private function enqueueTeacherWhatsApp(NotificationEvent $event, Booking $booking, User $teacher, array $metadata, string $key): ?NotificationDelivery
    {
        $customerPhone = $this->normalizedPhone($booking->customer_phone);
        $teacherPhone = $this->normalizedPhone($teacher->phone);
        if ($teacher->phone_verified_at === null || $teacherPhone === null || $teacherPhone === $customerPhone) {
            return null;
        }

        return $this->enqueueRecipientWhatsApp($event, $booking, $teacher, null, $metadata, $key);
    }

    private function whatsappSendingEnabled(): bool
    {
        return app(WhatsAppProviderConfiguration::class)->isConfigured();
    }

    private function normalizedPhone(?string $phone): ?string
    {
        if (! is_string($phone) || trim($phone) === '') {
            return null;
        }

        try {
            return PhoneNumber::normalize($phone);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    private function isCurrentCallEvent(Booking $booking, BookingCallEvent $event): bool
    {
        return $booking->status === 'confirmed'
            && $booking->teacher_id !== null
            && $event->booking_id === $booking->id
            && $event->provider === 'openwa'
            && $event->status === 'scheduled'
            && $event->join_url !== null
            && $event->revision === $booking->reschedule_count + 1
            && $event->teacher_id === $booking->teacher_id
            && $event->scheduled_start_at->equalTo($booking->starts_at)
            && $event->scheduled_end_at->equalTo($booking->ends_at);
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
