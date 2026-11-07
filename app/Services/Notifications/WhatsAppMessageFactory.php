<?php

namespace App\Services\Notifications;

use App\Data\Notifications\WhatsAppTemplateMessage;
use App\Enums\NotificationEvent;
use App\Exceptions\NotificationNoLongerEligibleException;
use App\Models\Booking;
use App\Models\BookingCallEvent;
use App\Models\NotificationDelivery;
use App\Support\PhoneNumber;
use Illuminate\Support\Carbon;
use RuntimeException;

class WhatsAppMessageFactory
{
    public function make(NotificationDelivery $delivery): WhatsAppTemplateMessage
    {
        $delivery->loadMissing(['booking.teacher', 'booking.entitlement.definition', 'entitlement.definition', 'refund.payment.entitlement.definition', 'recipientUser', 'bookingCallEvent']);
        $event = NotificationEvent::tryFrom($delivery->event);
        if ($event === null) {
            throw new RuntimeException('Unsupported WhatsApp notification event.');
        }
        if (in_array($event, [NotificationEvent::TeacherBookingConfirmed, NotificationEvent::TeacherBookingRescheduled, NotificationEvent::CustomerCallLinkReady, NotificationEvent::TeacherCallLinkReady, NotificationEvent::TeacherBookingCancelled], true)) {
            return $this->makeCallEventMessage($delivery, $event);
        }
        $booking = $delivery->booking;
        $entitlement = $delivery->entitlement ?? $delivery->refund->payment->entitlement ?? $booking?->entitlement;
        $refund = $delivery->refund;
        $phone = $booking->customer_phone ?? $entitlement->customer_phone ?? null;
        if (! is_string($phone) || $phone === '') {
            throw new RuntimeException('WhatsApp notification has no customer phone number.');
        }

        $metadata = $delivery->metadata ?? [];
        $snapshot = $metadata['snapshot'] ?? [];
        $event = NotificationEvent::tryFrom($delivery->event);
        if ($event === null) {
            throw new RuntimeException('Unsupported WhatsApp notification event.');
        }
        $locale = in_array($booking->locale ?? $entitlement->locale ?? null, ['ro', 'en'], true) ? ($booking->locale ?? $entitlement->locale) : 'ro';
        $key = match ($event) {
            NotificationEvent::BundlePurchaseConfirmed => 'purchase_confirmation',
            NotificationEvent::IntroBookingConfirmed => 'intro_booking_confirmation',
            NotificationEvent::AppointmentConfirmed => 'appointment_confirmation',
            NotificationEvent::AppointmentReminder24Hours => 'appointment_reminder_24h',
            NotificationEvent::AppointmentReminder1Hour => 'appointment_reminder_1h',
            NotificationEvent::BookingCancelled => 'booking_cancelled',
            NotificationEvent::BookingRescheduled => 'booking_rescheduled',
            NotificationEvent::TeacherCancellation => 'teacher_cancellation',
            NotificationEvent::BookingNoShow => 'booking_no_show',
            NotificationEvent::RefundSucceeded => 'refund_succeeded',
            NotificationEvent::RefundFailed => 'refund_failed',
            NotificationEvent::TeacherBookingConfirmed => 'teacher_booking_confirmed',
            NotificationEvent::TeacherBookingRescheduled => 'teacher_booking_rescheduled',
            NotificationEvent::CustomerCallLinkReady => 'customer_call_link_ready',
            NotificationEvent::TeacherCallLinkReady => 'teacher_call_link_ready',
            NotificationEvent::TeacherBookingCancelled => 'teacher_booking_cancelled',
            NotificationEvent::BundleExpiry14Days => 'bundle_expiry_14d',
            NotificationEvent::BundleExpiry3Days => 'bundle_expiry_3d',
        };

        $parameters = match ($event) {
            NotificationEvent::BundlePurchaseConfirmed => [
                $entitlement->customer_name ?? '',
                $entitlement?->definition->localizedName($entitlement->locale) ?? '',
                $this->money($entitlement->paid_amount_ron ?? 0),
                $this->date($entitlement?->expires_at),
                $this->bundleStatus((string) ($entitlement->status ?? ''), $locale),
            ],
            NotificationEvent::IntroBookingConfirmed, NotificationEvent::AppointmentConfirmed,
            NotificationEvent::AppointmentReminder24Hours, NotificationEvent::AppointmentReminder1Hour => [
                $booking->customer_name ?? '', $this->date($booking?->starts_at), 'Europe/Bucharest',
            ],
            NotificationEvent::BookingCancelled => [$booking->customer_name ?? '', $this->date($booking?->starts_at), $this->cancellationCreditOutcome($delivery->metadata ?? [], $locale)],
            NotificationEvent::BookingRescheduled => [
                $booking->customer_name ?? '',
                $this->date(isset($delivery->metadata['previous_starts_at']) ? Carbon::parse((string) $delivery->metadata['previous_starts_at']) : null),
                $this->date($booking?->starts_at),
            ],
            NotificationEvent::TeacherCancellation => [$booking->customer_name ?? '', $this->date($booking?->starts_at), $this->date($delivery->metadata['validity_extended_to'] ?? null)],
            NotificationEvent::BookingNoShow => [$booking->customer_name ?? '', $this->date($booking?->starts_at), $this->noShowOutcome($delivery->metadata ?? [], $locale)],
            NotificationEvent::RefundSucceeded, NotificationEvent::RefundFailed => [
                $entitlement->customer_name ?? '', $this->money($refund->amount_ron ?? 0), $refund->reason ?? '',
            ],
            NotificationEvent::TeacherBookingConfirmed, NotificationEvent::TeacherBookingRescheduled,
            NotificationEvent::CustomerCallLinkReady, NotificationEvent::TeacherCallLinkReady, NotificationEvent::TeacherBookingCancelled => [],
            NotificationEvent::BundleExpiry14Days, NotificationEvent::BundleExpiry3Days => [
                $entitlement->customer_name ?? '',
                $entitlement?->definition->localizedName($entitlement->locale) ?? '',
                (string) (($entitlement->credits_remaining ?? 0) + ($entitlement->free_credits_remaining ?? 0)),
                $this->date($entitlement?->expires_at),
            ],
        };

        if (in_array($event, [
            NotificationEvent::IntroBookingConfirmed, NotificationEvent::AppointmentConfirmed,
            NotificationEvent::AppointmentReminder24Hours, NotificationEvent::AppointmentReminder1Hour,
            NotificationEvent::BookingRescheduled,
        ], true)) {
            $parameters[] = $booking?->teacher !== null
                ? trim($booking->teacher->name.' · '.$booking->teacher->email.($booking->teacher->phone ? ' · '.$booking->teacher->phone : ''))
                : 'EasyEnglish team';
        }

        if (in_array($event, [NotificationEvent::IntroBookingConfirmed, NotificationEvent::AppointmentConfirmed, NotificationEvent::AppointmentReminder24Hours, NotificationEvent::AppointmentReminder1Hour], true)) {
            if ($booking === null || $booking->status !== 'confirmed' || $booking->starts_at->lessThanOrEqualTo(now())) {
                throw new NotificationNoLongerEligibleException('The WhatsApp appointment message is no longer eligible.');
            }
            if (is_array($snapshot) && isset($snapshot['starts_at']) && Carbon::parse((string) $snapshot['starts_at'])->getTimestamp() !== $booking->starts_at->getTimestamp()) {
                throw new NotificationNoLongerEligibleException('The appointment changed after its WhatsApp message was queued.');
            }
        }
        if (in_array($event, [NotificationEvent::BundleExpiry14Days, NotificationEvent::BundleExpiry3Days], true)) {
            if ($entitlement === null || $entitlement->status !== 'active' || $entitlement->expires_at === null
                || $entitlement->expires_at->isPast() || ($entitlement->credits_remaining + $entitlement->free_credits_remaining) < 1) {
                throw new NotificationNoLongerEligibleException('The WhatsApp bundle expiry reminder is no longer eligible.');
            }
            if (is_array($snapshot) && isset($snapshot['expires_at']) && Carbon::parse((string) $snapshot['expires_at'])->getTimestamp() !== $entitlement->expires_at->getTimestamp()) {
                throw new NotificationNoLongerEligibleException('Bundle expiry changed after the WhatsApp reminder was queued.');
            }
        }
        if (in_array($event, [NotificationEvent::RefundSucceeded, NotificationEvent::RefundFailed], true)) {
            $expectedStatus = $event === NotificationEvent::RefundSucceeded ? 'succeeded' : 'failed';
            if ($refund === null || $refund->status !== $expectedStatus) {
                throw new NotificationNoLongerEligibleException('The WhatsApp refund message is no longer eligible.');
            }
        }

        $template = config('services.whatsapp.templates.'.$key);
        if (! is_string($template) || ! preg_match('/^[a-z0-9_]{1,128}$/', $template)) {
            throw new RuntimeException('Required WhatsApp template is not configured: '.$key.'.');
        }
        $language = config('services.whatsapp.template_languages.'.$locale, $locale === 'en' ? 'en_US' : 'ro');

        return new WhatsAppTemplateMessage($phone, $key, (string) $language, $parameters, [], 'easyenglish-delivery-'.$delivery->id);
    }

    private function makeCallEventMessage(NotificationDelivery $delivery, NotificationEvent $event): WhatsAppTemplateMessage
    {
        $booking = $delivery->booking;
        $teacher = $delivery->recipientUser;
        $callEvent = $delivery->bookingCallEvent;
        $metadata = $delivery->metadata ?? [];
        $snapshot = is_array($metadata['snapshot'] ?? null) ? $metadata['snapshot'] : [];
        if ($booking === null) {
            throw new RuntimeException('Call-event WhatsApp notification requires a booking record.');
        }

        if (in_array($event, [NotificationEvent::TeacherBookingConfirmed, NotificationEvent::TeacherBookingRescheduled], true)) {
            if ($delivery->recipient_type !== 'teacher' || $teacher === null || ! $teacher->isTeacher()
                || $teacher->id !== $booking->teacher_id || $booking->status !== 'confirmed'
                || $teacher->phone_verified_at === null) {
                throw new NotificationNoLongerEligibleException('The teacher booking WhatsApp notification is no longer eligible.');
            }
            $phone = $teacher->phone;
            if ($this->normalizedPhone($phone) === $this->normalizedPhone($booking->customer_phone)) {
                throw new NotificationNoLongerEligibleException('Teacher and customer share a WhatsApp number; send teacher preparation details by email.');
            }
            $locale = 'en';
            $bookingUrl = route('teacher.bookings.show', $booking->public_reference);
            if ($event === NotificationEvent::TeacherBookingConfirmed) {
                if (! isset($snapshot['starts_at'], $snapshot['ends_at'])
                    || Carbon::parse((string) $snapshot['starts_at'])->getTimestamp() !== $booking->starts_at->getTimestamp()
                    || Carbon::parse((string) $snapshot['ends_at'])->getTimestamp() !== $booking->ends_at->getTimestamp()) {
                    throw new NotificationNoLongerEligibleException('The appointment changed after the teacher confirmation was queued.');
                }
                $key = 'teacher_booking_confirmed';
                $parameters = [
                    $teacher->name, $booking->customer_name, $booking->customer_email, $booking->customer_phone,
                    $booking->english_level ?: 'Not provided', $booking->session_goal ?: 'Not provided', $booking->notes ?: 'None',
                    $this->dateOnly($booking->starts_at), $booking->starts_at->setTimezone('Europe/Bucharest')->format('H:i'),
                    (string) $booking->starts_at->diffInMinutes($booking->ends_at), 'Europe/Bucharest', $bookingUrl,
                ];
            } else {
                if ((int) ($snapshot['revision'] ?? 0) !== $booking->reschedule_count + 1
                    || ! isset($snapshot['starts_at'], $snapshot['ends_at'], $snapshot['previous_starts_at'])
                    || Carbon::parse((string) $snapshot['starts_at'])->getTimestamp() !== $booking->starts_at->getTimestamp()
                    || Carbon::parse((string) $snapshot['ends_at'])->getTimestamp() !== $booking->ends_at->getTimestamp()) {
                    throw new NotificationNoLongerEligibleException('The appointment changed after the teacher reschedule notification was queued.');
                }
                $key = 'teacher_booking_rescheduled';
                $previousStart = Carbon::parse((string) $snapshot['previous_starts_at']);
                $parameters = [
                    $teacher->name, $booking->customer_name, $booking->customer_email, $booking->customer_phone,
                    $this->dateOnly($previousStart), $previousStart->setTimezone('Europe/Bucharest')->format('H:i'),
                    $this->dateOnly($booking->starts_at), $booking->starts_at->setTimezone('Europe/Bucharest')->format('H:i'),
                    (string) $booking->starts_at->diffInMinutes($booking->ends_at), 'Europe/Bucharest', $bookingUrl,
                ];
            }
        } elseif ($event === NotificationEvent::CustomerCallLinkReady) {
            if ($delivery->recipient_type !== 'customer' || ! $this->isCurrentCallEvent($booking, $callEvent)) {
                throw new NotificationNoLongerEligibleException('The customer call-link WhatsApp message is no longer eligible.');
            }
            $phone = $booking->customer_phone;
            $locale = in_array($booking->locale, ['ro', 'en'], true) ? $booking->locale : 'ro';
            $key = 'customer_call_link_ready';
            $parameters = [
                $booking->customer_name,
                $this->date($booking->starts_at),
                $booking->starts_at->setTimezone('Europe/Bucharest')->format('H:i'),
                (string) $booking->starts_at->diffInMinutes($booking->ends_at),
                'Europe/Bucharest',
                (string) $callEvent->join_url,
                $locale === 'ro' ? 'Deschide linkul la ora programată și permite accesul la microfon și cameră.' : 'Open the link at the scheduled time and allow microphone and camera access.',
            ];
        } elseif ($event === NotificationEvent::TeacherCallLinkReady) {
            if ($delivery->recipient_type !== 'teacher' || $teacher === null || ! $teacher->isTeacher()
                || $teacher->id !== $booking->teacher_id || $teacher->phone_verified_at === null
                || ! $this->isCurrentCallEvent($booking, $callEvent)) {
                throw new NotificationNoLongerEligibleException('The teacher call-link WhatsApp message is no longer eligible.');
            }
            $phone = $teacher->phone;
            if ($this->normalizedPhone($phone) === $this->normalizedPhone($booking->customer_phone)) {
                throw new NotificationNoLongerEligibleException('Teacher and customer share a WhatsApp number; send the teacher preparation details by email.');
            }
            $locale = 'en';
            $key = 'teacher_call_link_ready';
            $parameters = [
                $teacher->name,
                $booking->customer_name,
                $booking->customer_email,
                $booking->customer_phone,
                $booking->english_level ?: 'Not provided',
                $booking->session_goal ?: 'Not provided',
                $booking->notes ?: 'None',
                $this->date($booking->starts_at),
                $booking->starts_at->setTimezone('Europe/Bucharest')->format('H:i'),
                (string) $booking->starts_at->diffInMinutes($booking->ends_at),
                'Europe/Bucharest',
                (string) $callEvent->join_url,
                route('teacher.bookings.show', $booking->public_reference),
            ];
        } else {
            if ($delivery->recipient_type !== 'teacher' || $teacher === null || ! $teacher->isTeacher() || $teacher->phone_verified_at === null) {
                throw new NotificationNoLongerEligibleException('The teacher cancellation WhatsApp message is no longer eligible.');
            }
            $phone = $teacher->phone;
            if ($this->normalizedPhone($phone) === $this->normalizedPhone($booking->customer_phone)) {
                throw new NotificationNoLongerEligibleException('Teacher and customer share a WhatsApp number; do not send teacher-only cancellation details to that number.');
            }
            $locale = 'en';
            $key = 'teacher_booking_cancelled';
            $reason = match ((string) ($metadata['reason'] ?? 'cancelled')) {
                'reassigned' => 'The booking has been reassigned to another teacher.',
                'teacher_cancelled' => 'You cancelled this booking.',
                default => 'The booking has been cancelled.',
            };
            $startsAt = isset($snapshot['starts_at']) ? Carbon::parse((string) $snapshot['starts_at']) : $booking->starts_at;
            $parameters = [$teacher->name, $booking->customer_name, $this->date($startsAt), $reason];
        }

        try {
            $recipient = PhoneNumber::normalize((string) $phone);
        } catch (\InvalidArgumentException) {
            throw new NotificationNoLongerEligibleException('The WhatsApp recipient phone number is invalid.');
        }

        $template = config('services.whatsapp.templates.'.$key);
        if (! is_string($template) || ! preg_match('/^[a-z0-9_]{1,128}$/', $template)) {
            throw new RuntimeException('Required WhatsApp template is not configured: '.$key.'.');
        }
        $language = config('services.whatsapp.template_languages.'.$locale, $locale === 'en' ? 'en_US' : 'ro');

        return new WhatsAppTemplateMessage($recipient, $key, (string) $language, $parameters, [], 'easyenglish-delivery-'.$delivery->id);
    }

    private function normalizedPhone(?string $phone): ?string
    {
        if (! is_string($phone) || trim($phone) === '') {
            return null;
        }

        try {
            return PhoneNumber::normalize($phone);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    private function isCurrentCallEvent(Booking $booking, ?BookingCallEvent $event): bool
    {
        return $booking->status === 'confirmed'
            && $booking->teacher_id !== null
            && $event !== null
            && $event->booking_id === $booking->id
            && $event->provider === 'openwa'
            && $event->status === 'scheduled'
            && $event->join_url !== null
            && $event->revision === $booking->reschedule_count + 1
            && $event->teacher_id === $booking->teacher_id
            && $event->scheduled_start_at->equalTo($booking->starts_at)
            && $event->scheduled_end_at->equalTo($booking->ends_at);
    }

    /** @param array<string, mixed> $metadata */
    private function noShowOutcome(array $metadata, string $locale): string
    {
        if (($metadata['credit_forfeited'] ?? false) === true) {
            return $locale === 'ro' ? 'credit pierdut' : 'session credit forfeited';
        }
        if (($metadata['free_introduction'] ?? false) === true) {
            return $locale === 'ro' ? 'oferta gratuită utilizată' : 'one-time free offer used';
        }

        return $locale === 'ro' ? 'înregistrat' : 'recorded';
    }

    /** @param array<string, mixed> $metadata */
    private function cancellationCreditOutcome(array $metadata, string $locale): string
    {
        if (($metadata['credit_refunded'] ?? false) === true) {
            return $locale === 'ro' ? 'credit rambursat' : 'credit refunded';
        }
        if (($metadata['credit_restored'] ?? false) === true) {
            return $locale === 'ro' ? 'credit restituit' : 'credit restored';
        }

        return $locale === 'ro' ? 'credit pierdut' : 'credit forfeited';
    }

    private function bundleStatus(string $status, string $locale): string
    {
        return match ([$locale, $status]) {
            ['en', 'active'] => 'active',
            ['en', 'refunded'] => 'refunded',
            ['en', 'pending_payment'] => 'awaiting payment',
            ['ro', 'active'] => 'activ',
            ['ro', 'refunded'] => 'rambursat',
            ['ro', 'pending_payment'] => 'în așteptarea plății',
            default => $status,
        };
    }

    private function dateOnly(mixed $date): string
    {
        if ($date === null) {
            return 'TBC';
        }

        return Carbon::parse($date)->setTimezone('Europe/Bucharest')->format('d.m.Y');
    }

    private function date(mixed $date): string
    {
        if ($date === null) {
            return 'TBC';
        }

        return Carbon::parse($date)->setTimezone('Europe/Bucharest')->format('d.m.Y H:i');
    }

    private function money(int $amount): string
    {
        return number_format($amount, 0, ',', '.').' RON';
    }
}
