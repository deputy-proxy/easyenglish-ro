<?php

use App\Contracts\Notifications\WhatsAppGateway;
use App\Contracts\Payments\StripeGateway;
use App\Jobs\SendNotificationDelivery;
use App\Mail\TransactionalNotificationEmail;
use App\Models\Booking;
use App\Models\BookingAudit;
use App\Models\BookingCallEvent;
use App\Models\BundleDefinition;
use App\Models\BundleEntitlement;
use App\Models\CreditTransaction;
use App\Models\DiscountVoucher;
use App\Models\FreeSessionClaim;
use App\Models\NotificationDelivery;
use App\Models\Payment;
use App\Models\PaymentDispute;
use App\Models\StripeWebhookEvent;
use App\Models\User;
use App\Models\WhatsAppContact;
use App\Services\Notifications\NotificationMessageFactory;
use App\Services\Notifications\WhatsAppMessageFactory;
use App\Services\Payments\CustomerPurchasePresenter;
use App\Services\Payments\StripeCheckoutService;
use App\Services\Payments\StripePaymentProcessor;
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
            'displayed_price_ron' => $bundle->currentPriceRon(),
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

test('active bundle discounts are charged at the calculated price and stored as the purchase price', function () {
    $bundle = BundleDefinition::query()->where('price_ron', 156)->firstOrFail();
    $bundle->update(['discounts' => [['type' => 'discount', 'data' => [
        'title' => ['ro' => 'Ofertă de test', 'en' => 'Test offer'],
        'start_date' => now()->subDay()->toDateString(),
        'end_date' => now()->addDay()->toDateString(),
        'percentage' => 25,
    ]]]]);

    $purchase = startStripePurchase($this, str_repeat('D', 48));
    $priceData = app(FakeStripeGateway::class)->checkoutRequests[0]['parameters']['line_items'][0]['price_data'];

    expect($purchase['entitlement']->paid_amount_ron)->toBe(117)
        ->and($purchase['payment']->amount_ron)->toBe(117)
        ->and($priceData['unit_amount'])->toBe(11700);

    $paidSession = array_merge($purchase['session'], ['status' => 'complete', 'payment_status' => 'paid']);
    postStripeTestEvent($this, stripeTestEvent('evt_discounted_bundle', 'checkout.session.completed', $paidSession))
        ->assertOk();

    expect($purchase['entitlement']->fresh()->status)->toBe('active');
});

test('fixed bundle discounts are displayed as an amount and charged at the effective price', function () {
    $bundle = BundleDefinition::query()->where('price_ron', 156)->firstOrFail();
    $bundle->update(['discounts' => [['type' => 'discount', 'data' => [
        'title' => ['ro' => 'Reducere fixă', 'en' => 'Fixed saving'],
        'start_date' => now()->subDay()->toDateString(),
        'end_date' => now()->addDay()->toDateString(),
        'discount_type' => 'fixed',
        'amount_ron' => 36,
    ]]]]);

    $purchase = startStripePurchase($this, str_repeat('F', 48));
    $priceData = app(FakeStripeGateway::class)->checkoutRequests[0]['parameters']['line_items'][0]['price_data'];

    expect($purchase['entitlement']->paid_amount_ron)->toBe(120)
        ->and($purchase['entitlement']->original_price_ron)->toBe(156)
        ->and($purchase['entitlement']->discount_snapshot['discount_type'])->toBe('fixed')
        ->and($purchase['payment']->amount_ron)->toBe(120)
        ->and($priceData['unit_amount'])->toBe(12000);
});

test('pending purchase pages keep displaying the immutable price and discount snapshot', function () {
    $bundle = BundleDefinition::query()->where('price_ron', 156)->firstOrFail();
    $bundle->update(['discounts' => [['type' => 'discount', 'data' => [
        'title' => ['ro' => 'Ofertă salvată', 'en' => 'Saved offer'],
        'start_date' => now()->subDay()->toDateString(),
        'end_date' => now()->addDay()->toDateString(),
        'percentage' => 25,
    ]]]]);

    $purchase = startStripePurchase($this, str_repeat('P', 48));
    $bundle->update(['discounts' => []]);

    $this->withSession(['bundle_checkout_tokens' => [$bundle->id => $purchase['token']], 'locale' => 'en'])
        ->get(route('purchase', $bundle))
        ->assertOk()
        ->assertSee('name="displayed_price_ron" value="117"', false)
        ->assertSee('Saved offer')
        ->assertSee('156 RON')
        ->assertSee('117');
});

test('checkout rejects a stale displayed price without creating a purchase', function () {
    $bundle = BundleDefinition::query()->where('price_ron', 156)->firstOrFail();
    $token = str_repeat('S', 48);

    $this->withSession(['bundle_checkout_tokens' => [$bundle->id => $token]])
        ->get(route('purchase', $bundle))
        ->assertOk()
        ->assertSee('name="displayed_price_ron" value="156"', false);

    $bundle->update(['discounts' => [['type' => 'discount', 'data' => [
        'title' => ['ro' => 'Ofertă', 'en' => 'Offer'],
        'start_date' => now()->subDay()->toDateString(),
        'end_date' => now()->addDay()->toDateString(),
        'percentage' => 25,
    ]]]]);

    $this->withSession(['bundle_checkout_tokens' => [$bundle->id => $token]])
        ->post(route('purchase.checkout', $bundle), [
            'purchase_token' => $token,
            'displayed_price_ron' => 156,
            'name' => 'Stripe Test Customer',
            'email' => 'stripe-customer@example.com',
            'phone' => '+40722123456',
            'whatsapp_service_consent' => '1',
        ])
        ->assertRedirect()
        ->assertSessionHasErrors(['checkout' => __('public.bundle_price_changed')]);

    expect(BundleEntitlement::query()->count())->toBe(0)
        ->and(Payment::query()->count())->toBe(0)
        ->and(app(FakeStripeGateway::class)->checkoutRequests)->toBe([]);
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
                'displayed_price_ron' => $bundle->currentPriceRon(),
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
            'displayed_price_ron' => $bundle->currentPriceRon(),
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
            'purchase_token' => $purchase['token'], 'displayed_price_ron' => $bundle->currentPriceRon(), 'name' => 'Stripe Test Customer',
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
            'purchase_token' => str_repeat('A', 48), 'displayed_price_ron' => $bundle->currentPriceRon(), 'name' => 'Stripe Test Customer',
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
            'purchase_token' => $token, 'displayed_price_ron' => $bundle->currentPriceRon(), 'name' => 'Stripe Test Customer',
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

test('cancelled bundle checkout returns to the purchase page without creating a booking or activating an entitlement', function () {
    $purchase = startStripePurchase($this, locale: 'en');

    $this->get(route('purchase.cancel', [
        'bundle' => $purchase['bundle']->id,
        'token' => $purchase['token'],
        'session_id' => $purchase['payment']->provider_checkout_id,
    ]))->assertRedirect(route('purchase', $purchase['bundle']));

    $this->get(route('purchase', $purchase['bundle']))
        ->assertOk()
        ->assertSee('lang="en"', false)
        ->assertSee(__('public.purchase_cancelled_notice'));

    expect($purchase['payment']->fresh()->status)->toBe('cancelled')
        ->and($purchase['entitlement']->fresh()->status)->toBe('pending_payment')
        ->and($purchase['entitlement']->fresh()->credits_remaining)->toBe(0)
        ->and(Booking::query()->count())->toBe(0)
        ->and(FreeSessionClaim::query()->count())->toBe(0)
        ->and(BookingCallEvent::query()->count())->toBe(0);

    $retry = app(StripeCheckoutService::class)->start(
        $purchase['bundle'],
        ['name' => $purchase['entitlement']->customer_name, 'email' => $purchase['entitlement']->customer_email, 'phone' => $purchase['entitlement']->customer_phone],
        $purchase['token'],
        $purchase['entitlement']->paid_amount_ron,
        'en',
        null,
        true,
        auth()->user(),
    );

    expect($retry->id)->not->toBe($purchase['payment']->id)
        ->and($retry->status)->toBe('pending')
        ->and($purchase['payment']->fresh()->status)->toBe('cancelled')
        ->and($purchase['entitlement']->fresh()->payments()->count())->toBe(2);
});

test('unpaid checkout return stays pending and does not activate bundle credits', function () {
    $purchase = startStripePurchase($this);
    app(FakeStripeGateway::class)->setSessionStatus('cs_test_1', 'complete', 'unpaid');

    $this->get(route('purchase.success', ['session_id' => 'cs_test_1']))
        ->assertOk()
        ->assertSee(__('public.purchase_pending_title'))
        ->assertSee(__('public.purchase_pending_text'));

    expect($purchase['payment']->fresh()->status)->toBe('pending')
        ->and($purchase['entitlement']->fresh()->status)->toBe('pending_payment')
        ->and($purchase['entitlement']->fresh()->credits_remaining)->toBe(0)
        ->and(Booking::query()->count())->toBe(0)
        ->and(BookingCallEvent::query()->count())->toBe(0);
});

test('failed checkout return offers a retry without activating bundle credits', function () {
    $purchase = startStripePurchase($this);
    app(FakeStripeGateway::class)->setSessionStatus('cs_test_1', 'expired', 'unpaid');

    $this->get(route('purchase.success', ['session_id' => 'cs_test_1']))
        ->assertOk()
        ->assertSee(__('public.purchase_failed_title'))
        ->assertSee(route('purchase', $purchase['bundle']), false);

    expect($purchase['payment']->fresh()->status)->toBe('expired')
        ->and($purchase['entitlement']->fresh()->status)->toBe('pending_payment')
        ->and($purchase['entitlement']->fresh()->credits_remaining)->toBe(0)
        ->and(Booking::query()->count())->toBe(0)
        ->and(BookingCallEvent::query()->count())->toBe(0);
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
    $asyncSuccessEvent = stripeTestEvent('evt_async_paid_1', 'checkout.session.async_payment_succeeded', $fake->retrieveCheckoutSession('cs_test_1'));
    postStripeTestEvent($this, $asyncSuccessEvent)->assertOk();
    postStripeTestEvent($this, $asyncSuccessEvent)->assertOk();

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
    $bundle->update(['included_free_credits' => 1]);
    $token = str_repeat('V', 48);

    $this->withSession(['bundle_checkout_tokens' => [$bundle->id => $token]])
        ->post(route('purchase.checkout', $bundle), [
            'purchase_token' => $token,
            'displayed_price_ron' => $bundle->currentPriceRon(),
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'whatsapp_service_consent' => '1',
        ])->assertRedirect('https://checkout.stripe.com/c/pay/cs_test_1');

    $entitlement = BundleEntitlement::query()->where('checkout_request_key', $token)->firstOrFail();
    expect($entitlement->customer_user_id)->toBe($user->id)
        ->and($entitlement->customer_email_verified_at)->not->toBeNull()
        ->and($entitlement->customer_phone_verified_at)->not->toBeNull();

    $fake = app(FakeStripeGateway::class);
    $fake->setSessionStatus('cs_test_1', 'complete', 'paid', 'pi_test_verified_account');
    postStripeTestEvent($this, stripeTestEvent('evt_verified_account_bundle', 'checkout.session.completed', $fake->retrieveCheckoutSession('cs_test_1')))->assertOk();

    expect($entitlement->fresh()->status)->toBe('active')
        ->and($entitlement->fresh()->free_credits_granted)->toBe(0)
        ->and($entitlement->fresh()->free_credits_remaining)->toBe(0);
});

test('pending checkout keeps its purchase snapshots and activates from purchased credits after catalog edits', function () {
    $bundle = BundleDefinition::query()->where('price_ron', 156)->firstOrFail();
    $bundle->forceFill([
        'name_en' => 'Snapshot English Bundle',
        'short_description' => ['en' => 'Description captured at checkout.'],
        'paid_credits' => 4,
        'included_free_credits' => 1,
    ])->save();

    $purchase = startStripePurchase($this, locale: 'en');
    $entitlement = $purchase['entitlement']->refresh();

    expect($entitlement->bundle_name_snapshot)->toBe('Snapshot English Bundle')
        ->and($entitlement->bundle_description_snapshot)->toBe('Description captured at checkout.')
        ->and($entitlement->included_free_credits_snapshot)->toBe(1)
        ->and($entitlement->bundle_snapshot_source)->toBe('purchase');

    $bundle->forceFill([
        'name_en' => 'Renamed Current Catalog Bundle',
        'short_description' => ['en' => 'A newer catalog description.'],
        'price_ron' => 234,
        'paid_credits' => 8,
        'included_free_credits' => 3,
    ])->save();

    $paidSession = array_merge($purchase['session'], ['status' => 'complete', 'payment_status' => 'paid']);
    $payment = app(StripePaymentProcessor::class)->reconcileSession($paidSession);
    $entitlement->refresh();

    expect($payment?->status)->toBe('succeeded')
        ->and($entitlement->status)->toBe('active')
        ->and($entitlement->paid_amount_ron)->toBe(156)
        ->and($entitlement->paid_credits)->toBe(4)
        ->and($entitlement->credits_remaining)->toBe(4)
        ->and($entitlement->included_free_credits_snapshot)->toBe(1)
        ->and($entitlement->bundle_name_snapshot)->toBe('Snapshot English Bundle')
        ->and($entitlement->bundle_description_snapshot)->toBe('Description captured at checkout.');
});

test('Stripe dispute webhooks persist current chargeback status and recover from out-of-order events', function () {
    $purchase = startStripePurchase($this);
    $paymentIntent = [
        'id' => 'pi_dispute_test',
        'amount' => 15600,
        'amount_received' => 15600,
        'currency' => 'ron',
        'latest_charge' => 'ch_dispute_test',
        'metadata' => ['payment_id' => (string) $purchase['payment']->id],
    ];

    postStripeTestEvent($this, stripeTestEvent('evt_payment_intent_charge', 'payment_intent.succeeded', $paymentIntent))
        ->assertOk();

    expect($purchase['payment']->fresh()->provider_charge_id)->toBe('ch_dispute_test');

    $dispute = [
        'id' => 'dp_test_dispute',
        'object' => 'dispute',
        'payment_intent' => 'pi_dispute_test',
        'charge' => 'ch_dispute_test',
        'amount' => 15600,
        'currency' => 'ron',
        'status' => 'needs_response',
        'reason' => 'fraudulent',
        'created' => time(),
        'evidence_details' => ['due_by' => time() + 86400],
    ];
    $fake = app(FakeStripeGateway::class);
    $fake->setDispute($dispute);

    postStripeTestEvent($this, stripeTestEvent('evt_dispute_created', 'charge.dispute.created', $dispute))
        ->assertOk();

    $record = PaymentDispute::query()->where('provider_dispute_id', 'dp_test_dispute')->firstOrFail();
    $payment = $purchase['payment']->fresh()->load(['disputes', 'refunds']);

    expect($record->status)->toBe('needs_response')
        ->and($record->payment_id)->toBe($purchase['payment']->id)
        ->and(app(CustomerPurchasePresenter::class)->paymentStatus($payment))->toBe('chargeback_open');

    $underReview = array_merge($dispute, ['status' => 'under_review']);
    $fake->setDispute($underReview);
    postStripeTestEvent($this, stripeTestEvent('evt_dispute_updated', 'charge.dispute.updated', $underReview))
        ->assertOk();

    $won = array_merge($dispute, ['status' => 'won']);
    $fake->setDispute($won);
    postStripeTestEvent($this, stripeTestEvent('evt_dispute_closed', 'charge.dispute.closed', $won))
        ->assertOk();

    // A delayed earlier event must reconcile against Stripe's current state, not regress the record.
    postStripeTestEvent($this, stripeTestEvent('evt_dispute_old_delivery', 'charge.dispute.created', $dispute))
        ->assertOk();

    $record->refresh();
    $payment = $purchase['payment']->fresh()->load(['disputes', 'refunds']);

    expect($record->status)->toBe('won')
        ->and($record->closed_at_provider)->not->toBeNull()
        ->and(app(CustomerPurchasePresenter::class)->paymentStatus($payment))->toBe('chargeback_won');
});

test('voucher checkout charges the voucher price and redeems once after verified payment', function () {
    $bundle = BundleDefinition::query()->where('price_ron', 156)->firstOrFail();
    $voucher = DiscountVoucher::query()->create([
        'code' => 'STRIPE25',
        'name' => 'Stripe campaign',
        'bundle_definition_id' => $bundle->id,
        'discount_percentage' => 25,
        'max_redemptions' => 1,
        'is_active' => true,
    ]);
    $token = str_repeat('W', 48);

    $this->withSession([
        'bundle_checkout_tokens' => [$bundle->id => $token],
        'bundle_voucher_codes' => [$bundle->id => 'STRIPE25'],
    ])->post(route('purchase.checkout', $bundle), [
        'purchase_token' => $token,
        'displayed_price_ron' => 117,
        'name' => 'Stripe Test Customer',
        'email' => 'stripe-customer@example.com',
        'phone' => '+40722123456',
        'whatsapp_service_consent' => '1',
    ])->assertRedirect('https://checkout.stripe.com/c/pay/cs_test_1');

    $entitlement = BundleEntitlement::query()->where('checkout_request_key', $token)->firstOrFail();
    $payment = $entitlement->payments()->firstOrFail();
    $priceData = app(FakeStripeGateway::class)->checkoutRequests[0]['parameters']['line_items'][0]['price_data'];

    $this->withSession([
        'bundle_checkout_tokens' => [$bundle->id => $token],
        'bundle_voucher_codes' => [$bundle->id => 'STRIPE25'],
    ])->post(route('purchase.checkout', $bundle), [
        'purchase_token' => $token,
        'displayed_price_ron' => 117,
        'name' => 'Stripe Test Customer',
        'email' => 'stripe-customer@example.com',
        'phone' => '+40722123456',
        'whatsapp_service_consent' => '1',
    ])->assertRedirect('https://checkout.stripe.com/c/pay/cs_test_1');

    expect($priceData['unit_amount'])->toBe(11700)
        ->and($payment->amount_ron)->toBe(117)
        ->and($entitlement->original_price_ron)->toBe(156)
        ->and($entitlement->paid_amount_ron)->toBe(117)
        ->and($entitlement->voucher_code_snapshot)->toBe('STRIPE25')
        ->and($entitlement->discount_snapshot['percentage'])->toBe(25)
        ->and($voucher->fresh()->redemption_count)->toBe(0)
        ->and($voucher->redemptions()->where('status', 'reserved')->count())->toBe(1);

    $paidSession = array_merge($payment->provider_checkout_id ? app(FakeStripeGateway::class)->retrieveCheckoutSession($payment->provider_checkout_id) : [], [
        'status' => 'complete',
        'payment_status' => 'paid',
    ]);
    postStripeTestEvent($this, stripeTestEvent('evt_voucher_paid', 'checkout.session.completed', $paidSession))
        ->assertOk();
    postStripeTestEvent($this, stripeTestEvent('evt_voucher_paid_retry', 'checkout.session.completed', $paidSession))
        ->assertOk();

    $voucher->update(['name' => 'Renamed after purchase', 'discount_percentage' => 40, 'is_active' => false]);

    expect($entitlement->fresh()->status)->toBe('active')
        ->and($entitlement->fresh()->voucher_code_snapshot)->toBe('STRIPE25')
        ->and($entitlement->fresh()->discount_snapshot['percentage'])->toBe(25)
        ->and($entitlement->fresh()->paid_amount_ron)->toBe(117)
        ->and($voucher->fresh()->redemption_count)->toBe(1)
        ->and($voucher->redemptions()->where('status', 'redeemed')->count())->toBe(1);
});

test('voucher reservations are released when checkout expires and redemption is not consumed', function () {
    $bundle = BundleDefinition::query()->where('price_ron', 156)->firstOrFail();
    $voucher = DiscountVoucher::query()->create([
        'code' => 'EXPIRE25',
        'name' => 'Expiry campaign',
        'bundle_definition_id' => $bundle->id,
        'discount_percentage' => 25,
        'max_redemptions' => 1,
        'is_active' => true,
    ]);
    $token = str_repeat('X', 48);

    $this->withSession([
        'bundle_checkout_tokens' => [$bundle->id => $token],
        'bundle_voucher_codes' => [$bundle->id => 'EXPIRE25'],
    ])->post(route('purchase.checkout', $bundle), [
        'purchase_token' => $token,
        'displayed_price_ron' => 117,
        'name' => 'Stripe Test Customer',
        'email' => 'stripe-customer@example.com',
        'phone' => '+40722123456',
        'whatsapp_service_consent' => '1',
    ])->assertRedirect();

    $payment = Payment::query()->firstOrFail();
    $expiredSession = array_merge(app(FakeStripeGateway::class)->retrieveCheckoutSession((string) $payment->provider_checkout_id), [
        'status' => 'expired',
        'payment_status' => 'unpaid',
    ]);
    postStripeTestEvent($this, stripeTestEvent('evt_voucher_expired', 'checkout.session.expired', $expiredSession))
        ->assertOk();

    expect($voucher->fresh()->redemption_count)->toBe(0)
        ->and($voucher->redemptions()->where('status', 'reserved')->count())->toBe(0)
        ->and($voucher->redemptions()->where('status', 'released')->count())->toBe(1);
});

test('an older failed checkout attempt cannot release a voucher reserved by a newer pending attempt', function () {
    $bundle = BundleDefinition::query()->where('price_ron', 156)->firstOrFail();
    $voucher = DiscountVoucher::query()->create([
        'code' => 'RETRY25',
        'name' => 'Retry campaign',
        'bundle_definition_id' => $bundle->id,
        'discount_percentage' => 25,
        'max_redemptions' => 1,
        'is_active' => true,
    ]);
    $token = str_repeat('Y', 48);

    $this->withSession([
        'bundle_checkout_tokens' => [$bundle->id => $token],
        'bundle_voucher_codes' => [$bundle->id => 'RETRY25'],
    ])->post(route('purchase.checkout', $bundle), [
        'purchase_token' => $token,
        'displayed_price_ron' => 117,
        'name' => 'Stripe Test Customer',
        'email' => 'stripe-customer@example.com',
        'phone' => '+40722123456',
        'whatsapp_service_consent' => '1',
    ])->assertRedirect();

    $firstPayment = Payment::query()->firstOrFail();
    $secondPayment = $firstPayment->entitlement->payments()->create([
        'provider' => 'stripe',
        'checkout_request_key' => $token.':attempt:2',
        'amount_ron' => 117,
        'currency' => 'RON',
        'status' => 'pending',
    ]);
    $expiredSession = array_merge(app(FakeStripeGateway::class)->retrieveCheckoutSession((string) $firstPayment->provider_checkout_id), [
        'status' => 'expired',
        'payment_status' => 'unpaid',
    ]);

    postStripeTestEvent($this, stripeTestEvent('evt_old_voucher_attempt_expired', 'checkout.session.expired', $expiredSession))
        ->assertOk();

    expect($firstPayment->fresh()->status)->toBe('expired')
        ->and($secondPayment->fresh()->status)->toBe('pending')
        ->and($voucher->fresh()->redemption_count)->toBe(0)
        ->and($voucher->redemptions()->where('status', 'reserved')->count())->toBe(1)
        ->and($voucher->redemptions()->where('status', 'released')->count())->toBe(0);
});
