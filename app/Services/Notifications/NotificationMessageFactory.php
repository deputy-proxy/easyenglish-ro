<?php

namespace App\Services\Notifications;

use App\Enums\NotificationEvent;
use App\Exceptions\NotificationNoLongerEligibleException;
use App\Models\NotificationDelivery;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\URL;
use RuntimeException;

class NotificationMessageFactory
{
    /** @return array{recipient:string,locale:string,subject:string,heading:string,greeting:string,intro:string,details:list<array{label:string,value:string}>,ctaUrl:?string,ctaLabel:?string,footer:string} */
    public function make(NotificationDelivery $delivery): array
    {
        $delivery->loadMissing([
            'booking.entitlement.definition',
            'entitlement.definition',
            'refund.payment.entitlement.definition',
        ]);

        $locale = in_array($delivery->booking->locale ?? $delivery->entitlement->locale ?? $delivery->refund->payment->entitlement->locale, ['ro', 'en'], true)
            ? ($delivery->booking->locale ?? $delivery->entitlement->locale ?? $delivery->refund->payment->entitlement->locale)
            : 'ro';
        $recipient = $delivery->booking->customer_email ?? $delivery->entitlement->customer_email ?? $delivery->refund->payment->entitlement->customer_email;
        if (filter_var((string) $recipient, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException('Notification has no valid customer email recipient.');
        }

        $metadata = $delivery->metadata ?? [];
        $snapshot = is_array($metadata['snapshot'] ?? null) ? $metadata['snapshot'] : [];
        $event = NotificationEvent::tryFrom($delivery->event);
        if ($event === null) {
            throw new RuntimeException('Unsupported transactional email event.');
        }

        $prefix = 'events.'.$event->value;
        $replace = [];
        /** @var list<array{label:string,value:string}> $details */
        $details = [];
        $ctaUrl = null;
        $ctaLabel = null;
        $booking = $delivery->booking;
        $entitlement = $delivery->entitlement ?? $delivery->refund->payment->entitlement ?? $booking?->entitlement;
        $refund = $delivery->refund;

        if (in_array($event, [NotificationEvent::IntroBookingConfirmed, NotificationEvent::AppointmentConfirmed, NotificationEvent::AppointmentReminder24Hours, NotificationEvent::AppointmentReminder1Hour], true)) {
            if ($booking === null || $booking->status !== 'confirmed' || $booking->starts_at->lessThanOrEqualTo(now())) {
                throw new NotificationNoLongerEligibleException('The appointment email is no longer eligible.');
            }
            if (isset($snapshot['starts_at']) && Carbon::parse((string) $snapshot['starts_at'])->getTimestamp() !== $booking->starts_at->getTimestamp()) {
                throw new NotificationNoLongerEligibleException('The appointment changed after its email was queued.');
            }
        }

        if ($event === NotificationEvent::BundlePurchaseConfirmed) {
            if (! $entitlement || ! isset($snapshot['bundle_name'], $snapshot['amount_ron'], $snapshot['paid_credits'], $snapshot['expires_at'])) {
                throw new RuntimeException('Bundle purchase email is missing its event-time snapshot.');
            }
            $replace = ['name' => $entitlement->customer_name];
            $details = [
                $this->detail($locale, 'bundle', (string) $snapshot['bundle_name']),
                $this->detail($locale, 'amount_paid', $this->money((int) $snapshot['amount_ron'], $locale)),
                $this->detail($locale, 'paid_sessions', (string) $snapshot['paid_credits']),
                $this->detail($locale, 'free_sessions_available', (string) ($snapshot['free_credits_remaining'] ?? 0)),
                $this->detail($locale, 'bundle_status', $this->t($locale, 'values.'.$entitlement->status)),
                $this->detail($locale, 'bundle_expires', $this->date($snapshot['expires_at'], $locale)),
            ];
            if (($entitlement->credits_remaining + $entitlement->free_credits_remaining) > 0 && $entitlement->expires_at?->isFuture()) {
                $expiration = now()->addDays(7);
                if ($entitlement->expires_at->lessThan($expiration)) {
                    $expiration = $entitlement->expires_at;
                }
                $ctaUrl = URL::signedRoute('booking.create', ['type' => 'bundle', 'entitlement' => $entitlement->id], $expiration);
                $ctaLabel = $this->t($locale, 'actions.schedule_sessions');
            }
        } elseif (in_array($event, [NotificationEvent::IntroBookingConfirmed, NotificationEvent::AppointmentConfirmed, NotificationEvent::AppointmentReminder24Hours, NotificationEvent::AppointmentReminder1Hour, NotificationEvent::BookingCancelled, NotificationEvent::BookingRescheduled, NotificationEvent::TeacherCancellation, NotificationEvent::BookingNoShow], true)) {
            if (! $booking) {
                throw new RuntimeException('Appointment email requires a booking record.');
            }
            $replace = ['name' => $booking->customer_name];
            $appointmentDate = in_array($event, [NotificationEvent::AppointmentReminder24Hours, NotificationEvent::AppointmentReminder1Hour], true)
                ? $booking->starts_at
                : (isset($snapshot['starts_at']) ? Carbon::parse((string) $snapshot['starts_at']) : $booking->starts_at);
            $details[] = $this->detail($locale, 'appointment_date', $this->date($appointmentDate, $locale));
            $details[] = $this->detail($locale, 'duration', '15 '.$this->t($locale, 'labels.minutes'));
            $details[] = $this->detail($locale, 'timezone', 'Europe/Bucharest');
            if ($event === NotificationEvent::BookingRescheduled && isset($metadata['previous_starts_at'])) {
                $details[] = $this->detail($locale, 'previous_appointment_date', $this->date(Carbon::parse((string) $metadata['previous_starts_at']), $locale));
            }
            if ($event === NotificationEvent::BookingNoShow && ($metadata['credit_forfeited'] ?? false)) {
                $details[] = $this->detail($locale, 'credit_outcome', $this->t($locale, 'values.credit_forfeited'));\n            }\n            if ($event === NotificationEvent::BookingCancelled && isset($metadata['credit_refunded']) && $metadata['credit_refunded']) {\n                $details[] = $this->detail($locale, 'credit_outcome', $this->t($locale, 'values.credit_refunded'));\n            } elseif ($event === NotificationEvent::BookingCancelled && isset($metadata['credit_restored'])) {
                $details[] = $this->detail($locale, 'credit_outcome', $this->t($locale, $metadata['credit_restored'] ? 'values.credit_restored' : 'values.credit_forfeited'));
            }
            if ($event === NotificationEvent::TeacherCancellation && isset($metadata['validity_extended_to'])) {
                $details[] = $this->detail($locale, 'bundle_expires', $this->date(Carbon::parse((string) $metadata['validity_extended_to']), $locale));
            }
            if ($booking->bundle_entitlement_id !== null && $entitlement?->status === 'active'
                && $entitlement->expires_at?->isFuture() && ($entitlement->credits_remaining + $entitlement->free_credits_remaining) > 0
                && in_array($event, [NotificationEvent::AppointmentConfirmed, NotificationEvent::BookingCancelled, NotificationEvent::BookingRescheduled, NotificationEvent::TeacherCancellation], true)) {
                $expiration = now()->addDays(7);
                if ($entitlement->expires_at->lessThan($expiration)) {
                    $expiration = $entitlement->expires_at;
                }
                $ctaUrl = URL::signedRoute('booking.create', ['type' => 'bundle', 'entitlement' => $entitlement->id], $expiration);
                $ctaLabel = $this->t($locale, 'actions.manage_sessions');
            }
        } elseif (in_array($event, [NotificationEvent::RefundSucceeded, NotificationEvent::RefundFailed], true)) {
            if (! $refund || ! $entitlement) {
                throw new RuntimeException('Refund email requires its refund and entitlement records.');
            }
            $expectedStatus = $event === NotificationEvent::RefundSucceeded ? 'succeeded' : 'failed';
            if ($refund->status !== $expectedStatus) {
                throw new NotificationNoLongerEligibleException('The refund email is no longer eligible.');
            }
            $replace = ['name' => $entitlement->customer_name];
            $details = [
                $this->detail($locale, 'refund_amount', $this->money($refund->amount_ron, $locale)),
                $this->detail($locale, 'refund_status', $this->t($locale, 'values.'.$refund->status)),
                $this->detail($locale, 'refund_reason', $refund->reason),
            ];
        } elseif (in_array($event, [NotificationEvent::BundleExpiry14Days, NotificationEvent::BundleExpiry3Days], true)) {
            if (! $entitlement || $entitlement->status !== 'active' || $entitlement->expires_at?->isPast()
                || ($entitlement->credits_remaining + $entitlement->free_credits_remaining) < 1) {
                throw new NotificationNoLongerEligibleException('The bundle expiry reminder is no longer eligible.');
            }
            if (isset($snapshot['expires_at']) && Carbon::parse((string) $snapshot['expires_at'])->getTimestamp() !== $entitlement->expires_at?->getTimestamp()) {
                throw new NotificationNoLongerEligibleException('Bundle expiry changed after the email reminder was queued.');
            }
            $entitlement->loadMissing('definition');
            $replace = ['name' => $entitlement->customer_name, 'days' => $event === NotificationEvent::BundleExpiry14Days ? '14' : '3'];
            $details = [
                $this->detail($locale, 'bundle', (string) ($snapshot['bundle_name'] ?? $entitlement->definition->name)),
                $this->detail($locale, 'credits_remaining', (string) ((int) ($snapshot['credits_remaining'] ?? $entitlement->credits_remaining) + (int) ($snapshot['free_credits_remaining'] ?? $entitlement->free_credits_remaining))),
                $this->detail($locale, 'bundle_expires', $this->date($snapshot['expires_at'] ?? $entitlement->expires_at, $locale)),
            ];
            $expiration = now()->addDays(7);
            if ($entitlement->expires_at->lessThan($expiration)) {
                $expiration = $entitlement->expires_at;
            }
            $ctaUrl = URL::signedRoute('booking.create', ['type' => 'bundle', 'entitlement' => $entitlement->id], $expiration);
            $ctaLabel = $this->t($locale, 'actions.schedule_sessions');
        }

        return [
            'recipient' => $recipient,
            'locale' => $locale,
            'subject' => $this->t($locale, $prefix.'.subject', $replace),
            'heading' => $this->t($locale, $prefix.'.heading', $replace),
            'greeting' => $this->t($locale, 'greeting', $replace),
            'intro' => $this->t($locale, $prefix.'.intro', $replace),
            'details' => $details,
            'ctaUrl' => $ctaUrl,
            'ctaLabel' => $ctaLabel,
            'footer' => $this->t($locale, 'footer'),
        ];
    }

    /** @return array{label:string,value:string} */
    private function detail(string $locale, string $label, string $value): array
    {
        return ['label' => $this->t($locale, 'labels.'.$label), 'value' => $value];
    }

    /** @param array<string, string> $replace */
    private function t(string $locale, string $key, array $replace = []): string
    {
        return (string) Lang::get('email.'.$key, $replace, $locale);
    }

    private function date(mixed $date, string $locale): string
    {
        if ($date === null) {
            return $this->t($locale, 'values.not_available');
        }

        $dateTime = $date instanceof \DateTimeInterface ? Carbon::instance($date) : Carbon::parse((string) $date);

        return $dateTime->setTimezone('Europe/Bucharest')->format('d.m.Y, H:i');
    }

    private function money(int $amount, string $locale): string
    {
        return number_format($amount, 0, $locale === 'ro' ? ',' : '.', $locale === 'ro' ? '.' : ',').' RON';
    }
}
