<?php

namespace App\Services\Payments;

use App\Contracts\Payments\StripeGateway;
use App\Enums\PaymentStatus;
use App\Models\BookingAudit;
use App\Models\BundleDefinition;
use App\Models\BundleEntitlement;
use App\Models\ContactVerificationChallenge;
use App\Models\Payment;
use App\Services\Booking\PaymentStateService;
use App\Support\PhoneNumber;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class StripeCheckoutService
{
    public function __construct(
        private readonly StripeGateway $stripe,
        private readonly PaymentStateService $paymentStates,
        private readonly StripePaymentProcessor $processor,
    ) {}

    /** @param array{name:string,email:string,phone:string} $customer */
    public function start(BundleDefinition $bundle, array $customer, string $requestKey, string $locale, ?ContactVerificationChallenge $verification = null, bool $accountContactsVerified = false): Payment
    {
        if (! $bundle->is_active) {
            throw ValidationException::withMessages(['bundle' => 'This bundle is not available for purchase.']);
        }
        if (! (bool) config('services.stripe.checkout_enabled')
            || ! is_string(config('services.stripe.secret')) || config('services.stripe.secret') === ''
            || ! is_string(config('services.stripe.webhook_secret')) || config('services.stripe.webhook_secret') === '') {
            throw new RuntimeException('Stripe Checkout is not enabled or is missing required credentials.');
        }
        if (strlen($requestKey) < 40 || strlen($requestKey) > 80 || ! preg_match('/^[A-Za-z0-9]+$/', $requestKey)) {
            throw ValidationException::withMessages(['purchase_token' => 'Refresh the purchase page and try again.']);
        }

        $customer['name'] = trim($customer['name']);
        $customer['email'] = mb_strtolower(trim($customer['email']));
        $customer['phone'] = PhoneNumber::normalize($customer['phone']);
        if ($verification !== null && (! $verification->isUsable() || ! $verification->isComplete()
            || $verification->customer_email !== $customer['email'] || $verification->customer_phone !== $customer['phone'])) {
            throw ValidationException::withMessages(['verification' => __('public.verification_required')]);
        }

        $entitlement = BundleEntitlement::query()->where('checkout_request_key', $requestKey)->first();
        if (! $entitlement) {
            $entitlement = BundleEntitlement::query()->firstOrCreate(
                ['checkout_request_key' => $requestKey],
                [
                    'bundle_definition_id' => $bundle->id,
                    'locale' => in_array($locale, ['ro', 'en'], true) ? $locale : 'ro',
                    'customer_name' => $customer['name'],
                    'customer_email' => $customer['email'],
                    'customer_phone' => $customer['phone'],
                    'customer_email_verified_at' => $verification !== null ? $verification->email_verified_at : ($accountContactsVerified ? now() : null),
                    'customer_phone_verified_at' => $verification !== null ? $verification->phone_verified_at : ($accountContactsVerified ? now() : null),
                    'status' => 'pending_payment',
                    'paid_amount_ron' => $bundle->price_ron,
                    'currency' => 'RON',
                    'paid_credits' => $bundle->paid_credits,
                    'free_credits_granted' => 0,
                    'credits_remaining' => 0,
                    'free_credits_remaining' => 0,
                ],
            );
        }

        if ($entitlement->bundle_definition_id !== $bundle->id
            || mb_strtolower($entitlement->customer_email) !== $customer['email']
            || PhoneNumber::normalize($entitlement->customer_phone) !== $customer['phone']
            || $entitlement->customer_name !== $customer['name']) {
            throw ValidationException::withMessages(['purchase_token' => 'This purchase form has already been used with different customer details. Refresh the page to start a separate purchase.']);
        }

        if ($verification !== null || $accountContactsVerified) {
            $emailVerifiedAt = $verification?->email_verified_at;
            $phoneVerifiedAt = $verification?->phone_verified_at;
            if ($accountContactsVerified && $verification === null) {
                $emailVerifiedAt = now();
                $phoneVerifiedAt = now();
            }
            $entitlement->forceFill([
                'customer_email_verified_at' => $emailVerifiedAt,
                'customer_phone_verified_at' => $phoneVerifiedAt,
            ])->save();
        }

        if ($entitlement->status === 'active') {
            return $entitlement->payments()->where('status', PaymentStatus::Succeeded->value)->latest('id')->firstOrFail();
        }
        if ($entitlement->status !== 'pending_payment') {
            throw ValidationException::withMessages(['bundle' => 'This purchase is no longer pending. Refresh the page to start a new purchase.']);
        }

        $payment = $entitlement->payments()->latest('id')->first();
        if ($payment?->status === PaymentStatus::Succeeded->value) {
            return $payment;
        }

        if ($payment?->status === PaymentStatus::Pending->value && $payment->provider_checkout_id !== null) {
            $session = $this->stripe->retrieveCheckoutSession($payment->provider_checkout_id);
            $this->processor->reconcileSession($session);
            $payment->refresh();
            if ($payment->status === PaymentStatus::Succeeded->value) {
                return $payment;
            }
            if ($payment->status === PaymentStatus::Pending->value && ($session['status'] ?? null) === 'complete' && ($session['payment_status'] ?? null) === 'unpaid') {
                $payment->forceFill(['checkout_url' => null])->save();

                return $payment->refresh();
            }
            if ($payment->status === PaymentStatus::Pending->value && ($session['status'] ?? null) === 'open') {
                if (($payment->checkout_expires_at?->isFuture() ?? false) && is_string($payment->checkout_url) && $payment->checkout_url !== '') {
                    return $payment;
                }
                $expired = $this->stripe->expireCheckoutSession($payment->provider_checkout_id);
                if (($expired['status'] ?? null) !== 'expired') {
                    throw new RuntimeException('Stripe has not confirmed checkout expiry; the existing payment session is preserved for reconciliation.');
                }
                $this->paymentStates->transitionAfterVerifiedProviderEvent($payment, PaymentStatus::Expired);
                $payment->refresh();
            }
        }

        if (! $payment || in_array($payment->status, [PaymentStatus::Failed->value, PaymentStatus::Expired->value], true)) {
            $attempt = ((int) $entitlement->payments()->max('id')) + 1;
            $payment = $entitlement->payments()->create([
                'provider' => 'stripe',
                'checkout_request_key' => $requestKey.':attempt:'.$attempt,
                'amount_ron' => $bundle->price_ron,
                'currency' => 'RON',
                'status' => PaymentStatus::Pending->value,
            ]);
        }

        if ($payment->provider_checkout_id !== null && $payment->checkout_url) {
            return $payment;
        }

        $created = $this->createOrRecoverCheckoutSession($payment, $entitlement, $bundle, $locale);
        if (! in_array($created->status, [PaymentStatus::Pending->value, PaymentStatus::Succeeded->value], true)) {
            throw new RuntimeException('The previous checkout attempt has expired or failed. Submit again to start a new secure checkout.');
        }

        return $created;
    }

    public function reconcileSessionById(string $sessionId): ?Payment
    {
        if (! is_string(config('services.stripe.secret')) || config('services.stripe.secret') === '') {
            throw new RuntimeException('Stripe Checkout is not configured.');
        }

        $session = $this->stripe->retrieveCheckoutSession($sessionId);

        return $this->processor->reconcileSession($session);
    }

    public function cancelSession(int $bundleId, string $requestKey, ?string $sessionId = null): ?Payment
    {
        $entitlement = BundleEntitlement::query()
            ->where('checkout_request_key', $requestKey)
            ->where('bundle_definition_id', $bundleId)
            ->firstOrFail();

        $query = $entitlement->payments()
            ->whereNotNull('provider_checkout_id')
            ->whereIn('status', [PaymentStatus::Pending->value, PaymentStatus::Succeeded->value])
            ->latest('id');
        if ($sessionId !== null) {
            $query->where('provider_checkout_id', $sessionId);
        }

        $payment = $query->first();
        if (! $payment || ! is_string($payment->provider_checkout_id)) {
            return null;
        }

        $sessionId = $payment->provider_checkout_id;
        $session = $this->stripe->retrieveCheckoutSession($sessionId);
        $reconciled = $this->processor->reconcileSession($session);
        $payment = $reconciled ?? $payment->refresh();

        if ($payment->status !== PaymentStatus::Pending->value || ($session['status'] ?? null) !== 'open') {
            return $payment;
        }

        $expired = $this->stripe->expireCheckoutSession($sessionId);
        if (($expired['status'] ?? null) === 'expired') {
            $payment = $this->paymentStates->transitionAfterVerifiedProviderEvent($payment, PaymentStatus::Expired);
        }

        return $payment->refresh();
    }

    public function createOrRecoverCheckoutSession(Payment $payment, BundleEntitlement $entitlement, BundleDefinition $bundle, string $locale): Payment
    {
        if (! (bool) config('services.stripe.checkout_enabled')
            || ! is_string(config('services.stripe.secret')) || config('services.stripe.secret') === ''
            || ! is_string(config('services.stripe.webhook_secret')) || config('services.stripe.webhook_secret') === '') {
            throw new RuntimeException('Stripe Checkout is not enabled or is missing required credentials.');
        }

        $successUrl = route('purchase.success', [], true).'?session_id={CHECKOUT_SESSION_ID}';
        $cancelUrl = route('purchase.cancel', ['bundle' => $bundle->id], true)
            .'?token='.urlencode((string) $entitlement->checkout_request_key);
        $metadata = [
            'payment_id' => (string) $payment->id,
            'entitlement_id' => (string) $entitlement->id,
            'bundle_definition_id' => (string) $bundle->id,
        ];

        // Persist the expiry before contacting Stripe so a retry sends identical
        // parameters with the same idempotency key.
        if ($payment->checkout_expires_at === null || $payment->checkout_expires_at->lessThanOrEqualTo(now())) {
            $payment->forceFill(['checkout_expires_at' => now()->addMinutes(35)])->save();
        }

        $parameters = [
            'mode' => 'payment',
            'expires_at' => $payment->checkout_expires_at->timestamp,
            'payment_method_types' => ['card'],
            'customer_email' => $entitlement->customer_email,
            'client_reference_id' => (string) $payment->id,
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'locale' => in_array($locale, ['ro', 'en'], true) ? $locale : 'auto',
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => 'ron',
                    'tax_behavior' => 'inclusive',
                    'unit_amount' => $bundle->price_ron * 100,
                    'product_data' => [
                        'name' => $bundle->name,
                        'description' => '15-minute English conversation sessions. All applicable taxes are included.',
                    ],
                ],
            ]],
            'metadata' => $metadata,
            'payment_intent_data' => ['metadata' => $metadata],
        ];

        try {
            $session = $this->stripe->createCheckoutSession($parameters, (string) $payment->checkout_request_key);
        } catch (\Throwable $exception) {
            // Recover payments created by the previous implementation, which used a
            // moving expires_at value with a stable key. Stripe rejects that key.
            if ((str_contains(mb_strtolower($exception->getMessage()), 'idempotency')
                    || str_contains(mb_strtolower($exception->getMessage()), 'idempotent'))
                && str_contains(mb_strtolower($exception->getMessage()), 'same parameters')) {
                $payment->forceFill([
                    'checkout_request_key' => Str::random(64),
                    'checkout_expires_at' => now()->addMinutes(35),
                ])->save();
                $parameters['expires_at'] = $payment->checkout_expires_at->timestamp;

                try {
                    $session = $this->stripe->createCheckoutSession($parameters, (string) $payment->checkout_request_key);
                } catch (\Throwable $retryException) {
                    $payment->forceFill(['last_error' => Str::limit($retryException->getMessage(), 2000)])->save();
                    throw new RuntimeException('Secure checkout could not be started. No payment has been confirmed; retry or contact support if the problem persists.', previous: $retryException);
                }
            } else {
                $payment->forceFill(['last_error' => Str::limit($exception->getMessage(), 2000)])->save();
                throw new RuntimeException('Secure checkout could not be started. No payment has been confirmed; retry or contact support if the problem persists.', previous: $exception);
            }
        }

        if (! isset($session['id'], $session['url']) || ! is_string($session['id']) || ! is_string($session['url'])) {
            $payment->forceFill(['last_error' => 'Stripe did not return a checkout session ID and URL.'])->save();
            throw new RuntimeException('Secure checkout could not be started. No payment has been confirmed.');
        }

        $payment->forceFill([
            'provider_checkout_id' => $session['id'],
            'checkout_url' => $session['url'],
            'checkout_expires_at' => isset($session['expires_at']) ? now()->setTimestamp((int) $session['expires_at']) : $payment->checkout_expires_at,
            'last_error' => null,
        ])->save();

        BookingAudit::query()->create([
            'bundle_entitlement_id' => $entitlement->id,
            'action' => 'payment.checkout_session_created',
            'metadata' => ['payment_id' => $payment->id, 'checkout_session_id' => $session['id'], 'expires_at' => $session['expires_at'] ?? null],
        ]);

        $this->processor->reconcileSession($session);

        return $payment->refresh();
    }

    public function reconcilePendingPayment(Payment $payment): void
    {
        $payment->loadMissing('entitlement.definition');
        try {
            if ($payment->provider_checkout_id === null) {
                if (! (bool) config('services.stripe.checkout_enabled')) {
                    return;
                }
                $this->createOrRecoverCheckoutSession($payment, $payment->entitlement, $payment->entitlement->definition, 'ro');

                return;
            }

            $session = $this->stripe->retrieveCheckoutSession($payment->provider_checkout_id);
            $payment = $this->processor->reconcileSession($session) ?? $payment;
            if (($session['status'] ?? null) === 'open' && $payment->status === PaymentStatus::Pending->value
                && $payment->checkout_expires_at !== null && $payment->checkout_expires_at->lessThanOrEqualTo(now())) {
                $expired = $this->stripe->expireCheckoutSession($payment->provider_checkout_id);
                $payment = $this->processor->reconcileSession($expired) ?? $payment;
                if (($expired['status'] ?? null) !== 'expired') {
                    throw new RuntimeException('Stripe did not confirm checkout expiry.');
                }
            }

            $payment->forceFill(['last_reconciled_at' => now(), 'last_error' => null])->save();
        } catch (\Throwable $exception) {
            $payment->forceFill(['last_reconciled_at' => now(), 'last_error' => Str::limit($exception->getMessage(), 2000)])->save();
            throw $exception;
        }
    }
}
