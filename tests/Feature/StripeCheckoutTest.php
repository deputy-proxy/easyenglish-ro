<?php

use App\Contracts\Notifications\WhatsAppGateway;
use App\Contracts\Payments\StripeGateway;
use App\Jobs\SendNotificationDelivery;
use App\Mail\TransactionalNotificationEmail;
use App\Models\Booking;
use App\Models\BookingAudit;
use App\Models\BundleDefinition;
use App\Models\BundleEntitlement;
use App\Models\CreditTransaction;
use App\Models\FreeSessionClaim;
use App\Models\NotificationDelivery;
use App\Models\Payment;
use App\Models\StripeWebhookEvent;
use App\Models\User;
use App\Models\WhatsAppContact;
use App\Services\Notifications\NotificationMessageFactory;
use App\Services\Notifications\WhatsAppMessageFactory;
use App\Services\Payments\StripeRefundService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\TestCase as LaravelTestCase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Stripe\WebhookSignature;
use Tests\Support\FakeStripeGateway;

beforeEach(function (): void {
    $this->actingAs(User::factory()->create(['email' => 'stripe-customer@example.com']));
    config(['services.stripe.secret' => 'sk_test_fake', 'services.stripe.webhook_secret' => 'whsec_test', 'services.stripe.checkout_enabled' => true]);
    $fake = new FakeStripeGateway;
    app()->instance(StripeGateway::class, $fake);
    app()->instance(FakeStripeGateway::class, $fake);
});

/** @return array{bundle:BundleDefinition,token:string,entitlement:BundleEntitlement,payment:Payment,session:array<string,mixed>} */
function startStripePurchase(LaravelTestCase $test, string $token = 'AbCdEf0123456789AbCdEf0123456789AbCdEf0123456789', string $locale = 'ro'): array
{
    $bundle = BundleDefinition::query()->where('price_ron', 156)->firstOrFail();
    $test->withSession(['bundle_checkout_tokens' => [$bundle->id => $token], 'locale' => $locale])
        ->post(route('purchase.checkout', $bundle), [
            'purchase_token' => $token,
            'name' => 'Stripe Test Customer',
            'email' => 'stripe-customer@example.com',
            'phone' => '+40722123456', 'whatsapp_service_consent' => '1',
        ])->assertRedirect('https://checkout.stripe.com/c/pay/cs_test_1');

    $entitlement = BundleEntitlement::query()->where('checkout_request_key', $token)->firstOrFail();
    $payment = $entitlement->payments()->firstOrFail();
    $session = app(FakeStripeGateway::class)->retrieveCheckoutSession((string) $payment->provider_checkout_id);

    return compact('bundle', 'token', 'entitlement', 'payment', 'session');
}

/** @param array<string, mixed> $session */
function stripeTestEvent(string $eventId, string $type, array $session): array
{
    return ['id' => $eventId, 'object' => 'event', 'type' => $type, 'created' => time(), 'data' => ['object' => $session]];
}

/** @param array<string, mixed> $event */
function postStripeTestEvent(LaravelTestCase $test, array $event): TestResponse
{
    $payload = json_encode($event, JSON_THROW_ON_ERROR);
    $signature = WebhookSignature::generateSignatureHeader($payload, 'whsec_test');

    return $test->call('POST', '/stripe/webhook', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => $signature], $payload);
}

/** @return array{entitlement:BundleEntitlement,payment:Payment,actor:User} */
function makeActiveStripeBundle(int $paidBalance = 4, int $freeBalance = 0): array
{
    $bundle = BundleDefinition::query()->where('price_ron', 156)->firstOrFail();
    $entitlement = BundleEntitlement::query()->create([
        'bundle_definition_id' => $bundle->id,
        'customer_name' => 'Refund Customer',
        'customer_email' => 'refund-customer@example.com',
        'customer_phone' => '+40722123457',
        'status' => 'active', 'paid_amount_ron' => 156, 'currency' => 'RON', 'paid_credits' => 4,
        'free_credits_granted' => $freeBalance, 'credits_remaining' => $paidBalance, 'free_credits_remaining' => $freeBalance,
        'activated_at' => now(), 'expires_at' => now()->addMonths(3),
    ]);
    $payment = Payment::query()->create([
        'bundle_entitlement_id' => $entitlement->id, 'provider' => 'stripe',
        'provider_checkout_id' => 'cs_test_paid_'.$entitlement->id, 'provider_payment_id' => 'pi_test_paid_'.$entitlement->id,
        'amount_ron' => 156, 'currency' => 'RON', 'status' => 'succeeded', 'confirmed_at' => now(), 'refunded_amount_ron' => 0,
    ]);
    $actor = User::factory()->create(['is_staff' => true]);

    if ($freeBalance > 0) {
        FreeSessionClaim::query()->create([
            'customer_email' => $entitlement->customer_email, 'customer_phone' => $entitlement->customer_phone,
            'status' => 'bundle_credit_granted', 'bundle_entitlement_id' => $entitlement->id, 'verified_at' => now(),
        ]);
    }

    return compact('entitlement', 'payment', 'actor');
}

test('checkout creates a pending purchase using server-controlled tax-inclusive bundle pricing', function () {
    $purchase = startStripePurchase($this);
    $fake = app(FakeStripeGateway::class);
    $priceData = $fake->checkoutRequests[0]['parameters']['line_items'][0]['price_data'];
    $checkoutParameters = $fake->checkoutRequests[0]['parameters'];

    expect($checkoutParameters)->not->toHaveKey('payment_method_types')
        ->and($priceData['unit_amount'])->toBe(15600)
        ->and($priceData['currency'])->toBe('ron')
        ->and($priceData['tax_behavior'])->toBe('inclusive')
        ->and($purchase['entitlement']->status)->toBe('pending_payment')
        ->and($purchase['entitlement']->credits_remaining)->toBe(0)
        ->and($purchase['payment']->status)->toBe('pending')
        ->and($purchase['payment']->provider_checkout_id)->toBe('cs_test_1')
        ->and(WhatsAppContact::query()->where('phone', '+40722123456')->firstOrFail()->consented_at)->not->toBeNull()
        ->and(NotificationDelivery::query()->count())->toBe(0);
});

test('all three server-configured bundle prices are sent to Stripe in RON minor units', function () {
    $expected = [156 => 15600, 390 => 39000, 780 => 78000];
    foreach ($expected as $priceRon => $minorUnits) {
        $bundle = BundleDefinition::query()->where('price_ron', $priceRon)->firstOrFail();
        $token = str_repeat(match ($priceRon) {
            156 => 'A', 390 => 'B', default => 'C'
        }, 48);
        $this->withSession(['bundle_checkout_tokens' => [$bundle->id => $token]])
            ->post(route('purchase.checkout', $bundle), [
                'purchase_token' => $token,
                'name' => 'Stripe Test Customer '.$priceRon,
                'email' => 'stripe-customer-'.$priceRon.'@example.com',
                'phone' => '+40722123456', 'whatsapp_service_consent' => '1',
            ])->assertRedirect();
        $request = app(FakeStripeGateway::class)->checkoutRequests[array_key_last(app(FakeStripeGateway::class)->checkoutRequests)];
        expect($request['parameters']['line_items'][0]['price_data']['unit_amount'])->toBe($minorUnits)
            ->and($request['parameters']['line_items'][0]['price_data']['currency'])->toBe('ron');
    }
});

test('checkout retries reuse stable parameters and recover legacy idempotency conflicts', function () {
    $fake = app(FakeStripeGateway::class);
    $fake->nextCheckoutException = new RuntimeException('Keys for idempotent requests can only be used with the same parameters.');
    $bundle = BundleDefinition::query()->where('price_ron', 156)->firstOrFail();
    $token = str_repeat('R', 48);

    $this->withSession(['bundle_checkout_tokens' => [$bundle->id => $token]])
        ->post(route('purchase.checkout', $bundle), [
            'purchase_token' => $token,
            'name' => 'Stripe Test Customer',
            'email' => 'stripe-customer@example.com',
            'phone' => '+40722123456',
            'whatsapp_service_consent' => '1',
        ])->assertRedirect('https://checkout.stripe.com/c/pay/cs_test_1');

    expect($fake->checkoutRequests)->toHaveCount(2)
        ->and($fake->checkoutRequests[0]['idempotency_key'])->not->toBe($fake->checkoutRequests[1]['idempotency_key'])
        ->and($fake->checkoutRequests[1]['parameters']['expires_at'])->toBeGreaterThan(now()->timestamp);
});

test('duplicate checkout submissions reuse the same provider session and do not create another entitlement', function () {
    $purchase = startStripePurchase($this);
    $bundle = $purchase['bundle'];
    $this->withSession(['bundle_checkout_tokens' => [$bundle->id => $purchase['token']]])
        ->post(route('purchase.checkout', $bundle), [
            'purchase_token' => $purchase['token'], 'name' => 'Stripe Test Customer',
            'email' => 'stripe-customer@example.com', 'phone' => '+40722123456', 'whatsapp_service_consent' => '1',
        ])->assertRedirect('https://checkout.stripe.com/c/pay/cs_test_1');

    expect(BundleEntitlement::query()->count())->toBe(1)
        ->and(Payment::query()->count())->toBe(1)
        ->and(count(app(FakeStripeGateway::class)->checkoutRequests))->toBe(1);
});

test('checkout remains disabled without both Stripe API and webhook credentials and creates no order', function () {
    config(['services.stripe.secret' => null, 'services.stripe.webhook_secret' => null]);
    $bundle = BundleDefinition::query()->where('price_ron', 156)->firstOrFail();
    $this->get(route('purchase', $bundle))->assertOk()->assertSee('Plata online nu este încă activată');
    $this->withSession(['bundle_checkout_tokens' => [$bundle->id => str_repeat('A', 48)]])
        ->post(route('purchase.checkout', $bundle), [
            'purchase_token' => str_repeat('A', 48), 'name' => 'Stripe Test Customer',
            'email' => 'stripe-customer@example.com', 'phone' => '+40722123456', 'whatsapp_service_consent' => '1',
        ])->assertRedirect()->assertSessionHasErrors('checkout');

    expect(BundleEntitlement::query()->count())->toBe(0)->and(Payment::query()->count())->toBe(0);
});

test('explicit checkout feature flag prevents session creation even when credentials exist', function () {
    config(['services.stripe.checkout_enabled' => false]);
    $bundle = BundleDefinition::query()->where('price_ron', 156)->firstOrFail();
    $token = str_repeat('Z', 48);
    $this->get(route('purchase', $bundle))->assertOk()->assertSee('Plata online nu este încă activată');
    $this->withSession(['bundle_checkout_tokens' => [$bundle->id => $token]])
        ->post(route('purchase.checkout', $bundle), [
            'purchase_token' => $token, 'name' => 'Stripe Test Customer',
            'email' => 'stripe-disabled@example.com', 'phone' => '+40722123456', 'whatsapp_service_consent' => '1',
        ])->assertRedirect()->assertSessionHasErrors('checkout');

    expect(BundleEntitlement::query()->count())->toBe(0)
        ->and(Payment::query()->count())->toBe(0)
        ->and(app(FakeStripeGateway::class)->checkoutRequests)->toBe([]);
});

test('return page reconciles with Stripe before activating a bundle', function () {
    $purchase = startStripePurchase($this);
    $fake = app(FakeStripeGateway::class);
    $fake->setSessionStatus('cs_test_1', 'complete', 'paid', 'pi_test_return');

    $this->get(route('purchase.success', ['session_id' => 'cs_test_1']))
        ->assertRedirect(route('dashboard'));

    $this->get(route('dashboard'))->assertOk()->assertSee('Plata a fost confirmată');

    expect($purchase['entitlement']->fresh()->status)->toBe('active')
        ->and($purchase['entitlement']->fresh()->credits_remaining)->toBe(4)
        ->and($purchase['payment']->fresh()->status)->toBe('succeeded')
        ->and($purchase['payment']->fresh()->provider_payment_id)->toBe('pi_test_return');
});

test('signed checkout webhook activates once and duplicate delivery is idempotent', function () {
    $purchase = startStripePurchase($this);
    $fake = app(FakeStripeGateway::class);
    $fake->setSessionStatus('cs_test_1', 'complete', 'paid', 'pi_test_webhook');
    $event = stripeTestEvent('evt_checkout_paid_1', 'checkout.session.completed', $fake->retrieveCheckoutSession('cs_test_1'));

    postStripeTestEvent($this, $event)->assertOk()->assertJson(['received' => true]);
    postStripeTestEvent($this, $event)->assertOk()->assertJson(['received' => true]);

    expect($purchase['payment']->fresh()->status)->toBe('succeeded')
        ->and($purchase['entitlement']->fresh()->status)->toBe('active')
        ->and($purchase['entitlement']->fresh()->credits_remaining)->toBe(4)
        ->and(CreditTransaction::query()->where('idempotency_key', 'bundle-paid-grant:'.$purchase['entitlement']->id)->count())->toBe(1)
        ->and(StripeWebhookEvent::query()->where('provider_event_id', 'evt_checkout_paid_1')->count())->toBe(1)
        ->and(NotificationDelivery::query()->where('event', 'bundle_purchase_confirmed')->count())->toBe(1)
        ->and(NotificationDelivery::query()->where('event', 'bundle_purchase_confirmed')->value('channel'))->toBe('email');
});

test('verified bundle purchase queues a Romanian or English transactional email using the checkout locale', function () {
    Mail::fake();
    $purchase = startStripePurchase($this, str_repeat('Q', 48), 'en');
    expect($purchase['entitlement']->locale)->toBe('en')
        ->and(NotificationDelivery::query()->count())->toBe(0);

    $fake = app(FakeStripeGateway::class);
    $fake->setSessionStatus('cs_test_1', 'complete', 'paid', 'pi_test_email_locale');
    postStripeTestEvent($this, stripeTestEvent('evt_bundle_email_locale', 'checkout.session.completed', $fake->retrieveCheckoutSession('cs_test_1')))->assertOk();
    $delivery = NotificationDelivery::query()->where('event', 'bundle_purchase_confirmed')->firstOrFail();
    $delivery->update(['status' => 'queued']);
    (new SendNotificationDelivery($delivery->id))->handle(app(NotificationMessageFactory::class), app(WhatsAppMessageFactory::class), app(WhatsAppGateway::class));

    expect($delivery->fresh()->status)->toBe('sent');
    Mail::assertSent(TransactionalNotificationEmail::class, fn (TransactionalNotificationEmail $mail): bool => $mail->messageData['locale'] === 'en'
        && $mail->messageData['subject'] === 'Your Easy English bundle purchase is confirmed'
        && str_contains($mail->render(), 'appointments are not scheduled automatically'));
});

test('a processed Stripe event ID cannot be replayed with a different payload', function () {
    $purchase = startStripePurchase($this);
    $fake = app(FakeStripeGateway::class);
    $fake->setSessionStatus('cs_test_1', 'complete', 'paid', 'pi_event_hash');
    $event = stripeTestEvent('evt_payload_hash_1', 'checkout.session.completed', $fake->retrieveCheckoutSession('cs_test_1'));
    postStripeTestEvent($this, $event)->assertOk();

    $altered = $event;
    $altered['data']['object']['amount_total'] += 100;
    postStripeTestEvent($this, $altered)->assertStatus(500);

    expect(StripeWebhookEvent::query()->where('provider_event_id', 'evt_payload_hash_1')->value('status'))->toBe('processed')
        ->and($purchase['payment']->fresh()->status)->toBe('succeeded')
        ->and($purchase['entitlement']->fresh()->credits_remaining)->toBe(4);
});

test('an out-of-order unpaid completion followed by verified asynchronous success activates credits exactly once', function () {
    $purchase = startStripePurchase($this);
    $fake = app(FakeStripeGateway::class);
    $session = $fake->retrieveCheckoutSession('cs_test_1');
    $session['status'] = 'complete';
    $session['payment_status'] = 'unpaid';
    postStripeTestEvent($this, stripeTestEvent('evt_async_unpaid_1', 'checkout.session.completed', $session))->assertOk();
    expect($purchase['payment']->fresh()->status)->toBe('pending')
        ->and($purchase['entitlement']->fresh()->status)->toBe('pending_payment');

    $fake->setSessionStatus('cs_test_1', 'complete', 'paid', 'pi_async_paid');
    postStripeTestEvent($this, stripeTestEvent('evt_async_paid_1', 'checkout.session.async_payment_succeeded', $fake->retrieveCheckoutSession('cs_test_1')))->assertOk();
    postStripeTestEvent($this, stripeTestEvent('evt_async_paid_1', 'checkout.session.async_payment_succeeded', $fake->retrieveCheckoutSession('cs_test_1')))->assertOk();

    expect($purchase['payment']->fresh()->status)->toBe('succeeded')
        ->and($purchase['entitlement']->fresh()->status)->toBe('active')
        ->and($purchase['entitlement']->fresh()->credits_remaining)->toBe(4)
        ->and(CreditTransaction::query()->where('idempotency_key', 'bundle-paid-grant:'.$purchase['entitlement']->id)->count())->toBe(1);
});

test('a verified late success after local expiry activates the purchase without double granting credits', function () {
    $purchase = startStripePurchase($this);
    $purchase['payment']->forceFill(['status' => 'expired'])->save();
    $fake = app(FakeStripeGateway::class);
    $fake->setSessionStatus('cs_test_1', 'complete', 'paid', 'pi_late_paid');

    postStripeTestEvent($this, stripeTestEvent('evt_late_paid_1', 'checkout.session.completed', $fake->retrieveCheckoutSession('cs_test_1')))->assertOk();

    expect($purchase['payment']->fresh()->status)->toBe('succeeded')
        ->and($purchase['entitlement']->fresh()->status)->toBe('active')
        ->and(CreditTransaction::query()->where('idempotency_key', 'bundle-paid-grant:'.$purchase['entitlement']->id)->count())->toBe(1)
        ->and(BookingAudit::query()->where('action', 'payment.late_success_reconciled')->count())->toBe(1);
});

test('invalid Stripe webhook signatures are rejected without changing payment state', function () {
    $purchase = startStripePurchase($this);
    $event = stripeTestEvent('evt_invalid_sig', 'checkout.session.completed', $purchase['session']);
    $payload = json_encode($event, JSON_THROW_ON_ERROR);

    $this->call('POST', '/stripe/webhook', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => 't='.time().',v1=invalid'], $payload)
        ->assertBadRequest();

    expect($purchase['payment']->fresh()->status)->toBe('pending')
        ->and($purchase['entitlement']->fresh()->status)->toBe('pending_payment')
        ->and(StripeWebhookEvent::query()->count())->toBe(0);
});

test('wrong amount in a signed provider event cannot activate the bundle', function () {
    $purchase = startStripePurchase($this);
    $session = $purchase['session'];
    $session['status'] = 'complete';
    $session['payment_status'] = 'paid';
    $session['payment_intent'] = 'pi_wrong_amount';
    $session['amount_total'] += 100;
    $event = stripeTestEvent('evt_wrong_amount', 'checkout.session.completed', $session);

    postStripeTestEvent($this, $event)->assertStatus(500);

    expect($purchase['payment']->fresh()->status)->toBe('pending')
        ->and($purchase['entitlement']->fresh()->status)->toBe('pending_payment')
        ->and(StripeWebhookEvent::query()->where('provider_event_id', 'evt_wrong_amount')->value('status'))->toBe('failed');
});

test('reconciliation expires only provider-confirmed expired checkout sessions', function () {
    $purchase = startStripePurchase($this);
    $fake = app(FakeStripeGateway::class);
    $fake->setSessionStatus('cs_test_1', 'expired');
    $purchase['payment']->forceFill(['created_at' => now()->subMinutes(3)])->save();

    $this->artisan('stripe:reconcile')->assertExitCode(0);

    expect($purchase['payment']->fresh()->status)->toBe('expired')
        ->and($purchase['entitlement']->fresh()->status)->toBe('pending_payment');
});

test('successful full refund reserves then revokes paid and unused included free credits with an audit trail', function () {
    $records = makeActiveStripeBundle(4, 1);
    $fake = app(FakeStripeGateway::class);
    $refund = app(StripeRefundService::class)->refundUnusedPaidCredits($records['entitlement'], $records['actor']->id, 'Customer requested a full unused bundle refund');

    expect($refund->status)->toBe('succeeded')
        ->and($refund->amount_ron)->toBe(156)
        ->and($records['payment']->fresh()->status)->toBe('refunded')
        ->and($records['payment']->fresh()->refunded_amount_ron)->toBe(156)
        ->and($records['entitlement']->fresh()->status)->toBe('refunded')
        ->and($records['entitlement']->fresh()->credits_remaining)->toBe(0)
        ->and($records['entitlement']->fresh()->free_credits_remaining)->toBe(0)
        ->and(FreeSessionClaim::query()->where('bundle_entitlement_id', $records['entitlement']->id)->value('status'))->toBe('revoked')
        ->and(CreditTransaction::query()->where('idempotency_key', 'refund-reserved-paid-credits:'.$refund->id)->value('type'))->toBe('refunded')
        ->and(BookingAudit::query()->where('action', 'payment.refund_succeeded')->count())->toBe(1)
        ->and(NotificationDelivery::query()->where('event', 'refund_succeeded')->count())->toBe(1)
        ->and($fake->refundRequests[0]['parameters']['amount'])->toBe(15600);
});

test('partial refund is limited to unused paid credits and does not revoke unrelated free credits', function () {
    $records = makeActiveStripeBundle(2, 1);
    $refund = app(StripeRefundService::class)->refundUnusedPaidCredits($records['entitlement'], $records['actor']->id, 'Customer requested refund for remaining paid sessions');

    expect($refund->status)->toBe('succeeded')
        ->and($refund->amount_ron)->toBe(78)
        ->and($refund->credits_to_revoke)->toBe(2)
        ->and($refund->free_credits_to_revoke)->toBe(0)
        ->and($records['payment']->fresh()->status)->toBe('succeeded')
        ->and($records['payment']->fresh()->refunded_amount_ron)->toBe(78)
        ->and($records['entitlement']->fresh()->credits_remaining)->toBe(0)
        ->and($records['entitlement']->fresh()->free_credits_remaining)->toBe(1);
});

test('failed Stripe refund restores reserved credits and keeps the payment refundable', function () {
    $records = makeActiveStripeBundle(4, 0);
    app(FakeStripeGateway::class)->nextRefundStatus = 'failed';
    $refund = app(StripeRefundService::class)->refundUnusedPaidCredits($records['entitlement'], $records['actor']->id, 'Customer requested a refund that Stripe rejected');

    expect($refund->status)->toBe('failed')
        ->and($records['entitlement']->fresh()->credits_remaining)->toBe(4)
        ->and($records['payment']->fresh()->status)->toBe('succeeded')
        ->and($records['payment']->fresh()->refunded_amount_ron)->toBe(0)
        ->and(CreditTransaction::query()->where('idempotency_key', 'refund-release-paid-credits:'.$refund->id)->exists())->toBeTrue()
        ->and(NotificationDelivery::query()->where('event', 'refund_failed')->count())->toBe(1);
});

test('ambiguous refund stays reserved until idempotent reconciliation confirms provider status', function () {
    $records = makeActiveStripeBundle(4, 0);
    $fake = app(FakeStripeGateway::class);
    $fake->nextRefundStatus = 'pending';
    $refund = app(StripeRefundService::class)->refundUnusedPaidCredits($records['entitlement'], $records['actor']->id, 'Customer requested a refund with delayed provider confirmation');

    expect($refund->status)->toBe('pending')->and($records['entitlement']->fresh()->credits_remaining)->toBe(0);
    $fake->setRefundStatus((string) $refund->provider_refund_id, 'succeeded');
    app(StripeRefundService::class)->retryPendingRefund($refund);

    expect($refund->fresh()->status)->toBe('succeeded')
        ->and($records['entitlement']->fresh()->credits_remaining)->toBe(0)
        ->and($records['payment']->fresh()->status)->toBe('refunded')
        ->and(CreditTransaction::query()->where('idempotency_key', 'refund-reserved-paid-credits:'.$refund->id)->count())->toBe(1);
});

test('checkout session expiry stays above Stripe minimum without holding an appointment slot', function () {
    $purchase = startStripePurchase($this);
    $parameters = app(FakeStripeGateway::class)->checkoutRequests[0]['parameters'];
    $expiry = (int) $parameters['expires_at'];

    expect($expiry)->toBeGreaterThanOrEqual(now()->addMinutes(30)->timestamp)
        ->and($expiry)->toBeLessThanOrEqual(now()->addMinutes(40)->timestamp)
        ->and(Booking::query()->count())->toBe(0)
        ->and($purchase['entitlement']->status)->toBe('pending_payment');
});

test('only staff can initiate a bundle refund', function () {
    $purchase = makeActiveStripeBundle();
    $nonStaff = User::factory()->create(['is_staff' => false]);
    $service = app(StripeRefundService::class);

    expect(fn () => $service->refundUnusedPaidCredits($purchase['entitlement'], $nonStaff->id, 'Customer requested a refund'))
        ->toThrow(AuthorizationException::class);
    expect($purchase['entitlement']->fresh()->credits_remaining)->toBe(4)
        ->and(app(FakeStripeGateway::class)->refundRequests)->toBe([]);
});

function makeFutureRefundBooking(BundleEntitlement $entitlement, string $creditType = 'paid'): Booking
{
    $startsAt = CarbonImmutable::now('UTC')->addDays(2);

    return Booking::query()->create([
        'public_reference' => (string) Str::uuid(),
        'bundle_entitlement_id' => $entitlement->id,
        'customer_name' => $entitlement->customer_name,
        'customer_email' => $entitlement->customer_email,
        'customer_phone' => $entitlement->customer_phone,
        'english_level' => 'B1',
        'session_goal' => 'Practice speaking',
        'accepted_policy_version' => '2026-10-25',
        'starts_at' => $startsAt,
        'ends_at' => $startsAt->addMinutes(15),
        'duration_minutes' => 15,
        'status' => 'confirmed',
        'credit_type' => $creditType,
        'locale' => 'en',
        'confirmed_at' => now(),
    ]);
}

test('successful full refund cancels future paid appointments and revokes the included free credit', function () {
    $bundle = makeActiveStripeBundle(3, 1);
    $booking = makeFutureRefundBooking($bundle['entitlement'], 'paid');

    $refund = app(StripeRefundService::class)->refundUnusedPaidCredits($bundle['entitlement'], $bundle['actor']->id, 'Customer requested a full unused-bundle refund.');

    expect($refund->status)->toBe('succeeded')
        ->and($refund->amount_ron)->toBe(156)
        ->and($refund->credits_to_revoke)->toBe(4)
        ->and($refund->free_credits_to_revoke)->toBe(1)
        ->and($booking->fresh()->status)->toBe('cancelled')
        ->and($booking->fresh()->cancelled_by)->toBe('refund')
        ->and($bundle['entitlement']->fresh()->credits_remaining)->toBe(0)
        ->and($bundle['entitlement']->fresh()->free_credits_remaining)->toBe(0)
        ->and($bundle['entitlement']->fresh()->status)->toBe('refunded')
        ->and(BookingAudit::query()->where('booking_id', $booking->id)->where('action', 'booking.cancelled_by_refund')->exists())->toBeTrue()
        ->and(NotificationDelivery::query()->where('booking_id', $booking->id)->where('event', 'booking_cancelled')->exists())->toBeTrue();
});

test('refund assessment includes future paid appointments even when the available balance is zero', function () {
    $bundle = makeActiveStripeBundle(0, 1);
    $booking = makeFutureRefundBooking($bundle['entitlement'], 'paid');

    $refund = app(StripeRefundService::class)->refundUnusedPaidCredits($bundle['entitlement'], $bundle['actor']->id, 'Refund the unused booked paid session.');

    expect($refund->status)->toBe('succeeded')
        ->and($refund->amount_ron)->toBe(39)
        ->and($refund->credits_to_revoke)->toBe(1)
        ->and($refund->free_credits_to_revoke)->toBe(0)
        ->and($booking->fresh()->status)->toBe('cancelled')
        ->and($bundle['entitlement']->fresh()->credits_remaining)->toBe(0)
        ->and($bundle['entitlement']->fresh()->free_credits_remaining)->toBe(1)
        ->and($bundle['entitlement']->fresh()->status)->toBe('active');
});

test('failed refund restores available credits and returns refund-pending appointments to confirmed', function () {
    $bundle = makeActiveStripeBundle(3, 1);
    $booking = makeFutureRefundBooking($bundle['entitlement'], 'paid');
    app(FakeStripeGateway::class)->nextRefundStatus = 'failed';

    $refund = app(StripeRefundService::class)->refundUnusedPaidCredits($bundle['entitlement'], $bundle['actor']->id, 'Customer requested a refund.');

    expect($refund->status)->toBe('failed')
        ->and($booking->fresh()->status)->toBe('confirmed')
        ->and($bundle['entitlement']->fresh()->credits_remaining)->toBe(3)
        ->and($bundle['entitlement']->fresh()->free_credits_remaining)->toBe(1)
        ->and(BookingAudit::query()->where('booking_id', $booking->id)->where('action', 'booking.refund_cancel_reverted')->exists())->toBeTrue()
        ->and(NotificationDelivery::query()->where('event', 'refund_failed')->exists())->toBeTrue();
});

test('ambiguous refund keeps affected appointments blocked until provider reconciliation', function () {
    $bundle = makeActiveStripeBundle(3, 1);
    $booking = makeFutureRefundBooking($bundle['entitlement'], 'paid');
    $fake = app(FakeStripeGateway::class);
    $fake->nextRefundStatus = 'pending';

    $refund = app(StripeRefundService::class)->refundUnusedPaidCredits($bundle['entitlement'], $bundle['actor']->id, 'Customer requested a refund.');

    expect($refund->status)->toBe('pending')
        ->and($booking->fresh()->status)->toBe('refund_pending')
        ->and($bundle['entitlement']->fresh()->credits_remaining)->toBe(0);

    $providerRefund = $fake->refunds[$refund->provider_refund_id];
    $providerRefund['status'] = 'succeeded';
    app(StripeRefundService::class)->reconcileProviderRefund($providerRefund, 'evt_refund_lifecycle_succeeded');

    expect($booking->fresh()->status)->toBe('cancelled')
        ->and($refund->fresh()->status)->toBe('succeeded');
});

test('already verified customer accounts can purchase a bundle without repeating contact verification', function () {
    $user = User::factory()->create([
        'name' => 'Verified Stripe Customer',
        'email' => 'verified-stripe@example.com',
        'phone' => '+40722123456',
        'email_verified_at' => now(),
        'phone_verified_at' => now(),
    ]);
    $this->actingAs($user);
    $bundle = BundleDefinition::query()->where('price_ron', 156)->firstOrFail();
    $token = str_repeat('V', 48);

    $this->withSession(['bundle_checkout_tokens' => [$bundle->id => $token]])
        ->post(route('purchase.checkout', $bundle), [
            'purchase_token' => $token,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'whatsapp_service_consent' => '1',
        ])->assertRedirect('https://checkout.stripe.com/c/pay/cs_test_1');

    $entitlement = BundleEntitlement::query()->where('checkout_request_key', $token)->firstOrFail();
    expect($entitlement->customer_email_verified_at)->not->toBeNull()
        ->and($entitlement->customer_phone_verified_at)->not->toBeNull();

    $fake = app(FakeStripeGateway::class);
    $fake->setSessionStatus('cs_test_1', 'complete', 'paid', 'pi_test_verified_account');
    postStripeTestEvent($this, stripeTestEvent('evt_verified_account_bundle', 'checkout.session.completed', $fake->retrieveCheckoutSession('cs_test_1')))->assertOk();

    expect($entitlement->fresh()->status)->toBe('active')
        ->and($entitlement->fresh()->free_credits_granted)->toBe(1)
        ->and($entitlement->fresh()->free_credits_remaining)->toBe(1);
});
