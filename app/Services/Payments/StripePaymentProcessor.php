<?php

namespace App\Services\Payments;

use App\Enums\PaymentStatus;
use App\Models\BookingAudit;
use App\Models\Payment;
use App\Services\Booking\PaymentStateService;
use Illuminate\Validation\ValidationException;

class StripePaymentProcessor
{
    public function __construct(private readonly PaymentStateService $paymentStates) {}

    /** @param array<string, mixed> $session */
    public function reconcileSession(array $session): ?Payment
    {
        $sessionId = $session['id'] ?? null;
        $metadata = is_array($session['metadata'] ?? null) ? $session['metadata'] : [];
        $paymentId = filter_var($metadata['payment_id'] ?? $session['client_reference_id'] ?? null, FILTER_VALIDATE_INT);
        if (! is_string($sessionId) || ! is_int($paymentId) || $paymentId < 1) {
            throw ValidationException::withMessages(['payment' => 'Stripe Checkout session is missing its internal correlation reference.']);
        }

        $payment = Payment::query()->with('entitlement.definition')->findOrFail($paymentId);
        $entitlement = $payment->entitlement;
        $expectedMetadata = (string) $entitlement->id;
        if (($metadata['entitlement_id'] ?? null) !== $expectedMetadata
            || (string) ($metadata['bundle_definition_id'] ?? '') !== (string) $entitlement->bundle_definition_id
            || (string) ($session['client_reference_id'] ?? '') !== (string) $payment->id
            || ($session['mode'] ?? null) !== 'payment'
            || strtolower((string) ($session['currency'] ?? '')) !== 'ron'
            || (int) ($session['amount_total'] ?? -1) !== $payment->amount_ron * 100
            || $payment->currency !== 'RON'
            || $payment->amount_ron !== $entitlement->paid_amount_ron
            || $payment->amount_ron !== $entitlement->definition->price_ron
            || (is_string($session['customer_email'] ?? null) && mb_strtolower($session['customer_email']) !== mb_strtolower($entitlement->customer_email))) {
            throw ValidationException::withMessages(['payment' => 'Stripe Checkout amount, currency, or metadata does not match the stored bundle purchase.']);
        }

        if ($payment->provider_checkout_id !== null && $payment->provider_checkout_id !== $sessionId) {
            throw ValidationException::withMessages(['payment' => 'Stripe Checkout session does not match the stored payment attempt.']);
        }

        $payment->forceFill([
            'provider_checkout_id' => $sessionId,
            'provider_payment_id' => is_string($session['payment_intent'] ?? null)
                ? $session['payment_intent']
                : (is_array($session['payment_intent'] ?? null) ? ($session['payment_intent']['id'] ?? null) : $payment->provider_payment_id),
            'checkout_url' => is_string($session['url'] ?? null) ? $session['url'] : $payment->checkout_url,
            'checkout_expires_at' => isset($session['expires_at']) ? now()->setTimestamp((int) $session['expires_at']) : $payment->checkout_expires_at,
            'last_reconciled_at' => now(),
            'last_error' => null,
        ])->save();

        $status = $session['status'] ?? null;
        $paymentStatus = $session['payment_status'] ?? null;
        if ($status === 'complete' && $paymentStatus === 'paid') {
            $current = PaymentStatus::from($payment->status);
            if ($current !== PaymentStatus::Succeeded && $current !== PaymentStatus::Refunded) {
                $wasTerminal = in_array($current, [PaymentStatus::Expired, PaymentStatus::Failed], true);
                $payment = $this->paymentStates->transitionAfterVerifiedProviderEvent($payment, PaymentStatus::Succeeded);
                if ($wasTerminal) {
                    BookingAudit::query()->create([
                        'bundle_entitlement_id' => $entitlement->id,
                        'action' => 'payment.late_success_reconciled',
                        'metadata' => ['payment_id' => $payment->id, 'checkout_session_id' => $sessionId],
                    ]);
                }
            }
        } elseif ($status === 'expired' && $payment->status === PaymentStatus::Pending->value) {
            $payment = $this->paymentStates->transitionAfterVerifiedProviderEvent($payment, PaymentStatus::Expired);
        }

        return $payment->refresh();
    }

    /** @param array<string, mixed> $session */
    public function markAsyncPaymentFailed(array $session): ?Payment
    {
        $payment = $this->reconcileSessionWithoutStatusTransition($session);
        if ($payment && $payment->status === PaymentStatus::Pending->value) {
            $payment = $this->paymentStates->transitionAfterVerifiedProviderEvent($payment, PaymentStatus::Failed);
            BookingAudit::query()->create([
                'bundle_entitlement_id' => $payment->bundle_entitlement_id,
                'action' => 'payment.async_payment_failed',
                'metadata' => ['payment_id' => $payment->id, 'checkout_session_id' => $payment->provider_checkout_id],
            ]);
        }

        return $payment;
    }

    /** @param array<string, mixed> $session */
    private function reconcileSessionWithoutStatusTransition(array $session): ?Payment
    {
        $status = $session['status'] ?? null;
        if ($status === 'complete' && ($session['payment_status'] ?? null) === 'paid') {
            return $this->reconcileSession($session);
        }
        $session['status'] = 'open';
        $session['payment_status'] = 'unpaid';

        return $this->reconcileSession($session);
    }
}
