<?php

namespace App\Services\Notifications;

use App\Enums\NotificationEvent;
use App\Exceptions\NotificationNoLongerEligibleException;
use App\Models\NotificationDelivery;
use Illuminate\Support\Carbon;
use RuntimeException;

class WhatsAppMessageFactory
{
    /** @return array{recipient:string,template:string,language:string,components:list<array{type:string,parameters:list<array{type:string,text:string}>}>} */
    public function make(NotificationDelivery $delivery): array
    {
        $delivery->loadMissing(['booking.entitlement.definition', 'entitlement.definition', 'refund.payment.entitlement.definition']);
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
            NotificationEvent::BundleExpiry14Days => 'bundle_expiry_14d',
            NotificationEvent::BundleExpiry3Days => 'bundle_expiry_3d',
        };

        $parameters = match ($event) {
            NotificationEvent::BundlePurchaseConfirmed => [
                $entitlement->customer_name ?? '',
                $entitlement?->definition->name ?? '',
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
            NotificationEvent::BookingNoShow => [$booking->customer_name ?? '', $this->date($booking?->starts_at)],
            NotificationEvent::RefundSucceeded, NotificationEvent::RefundFailed => [
                $entitlement->customer_name ?? '', $this->money($refund->amount_ron ?? 0), $refund->reason ?? '',
            ],
            NotificationEvent::BundleExpiry14Days, NotificationEvent::BundleExpiry3Days => [
                $entitlement->customer_name ?? '',
                $entitlement?->definition->name ?? '',
                (string) (($entitlement->credits_remaining ?? 0) + ($entitlement->free_credits_remaining ?? 0)),
                $this->date($entitlement?->expires_at),
            ],
        };

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
        $textParameters = array_map(static fn (string $value): array => ['type' => 'text', 'text' => $value], $parameters);

        return [
            'recipient' => $phone,
            'template' => $template,
            'language' => (string) $language,
            'components' => [['type' => 'body', 'parameters' => $textParameters]],
        ];
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
