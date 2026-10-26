<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\BundleDefinition;
use App\Models\BundleEntitlement;
use App\Models\ContactVerificationChallenge;
use App\Models\WhatsAppContact;
use App\Services\Booking\AvailabilityService;
use App\Services\Booking\BookingService;
use App\Services\Booking\BundleEntitlementService;
use App\Services\Booking\CancellationPolicyService;
use App\Services\Payments\StripeCheckoutService;
use App\Services\Verification\ContactVerificationService;
use App\Support\PhoneNumber;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PublicSiteController extends Controller
{
    public function home(Request $request): View
    {
        $this->applyLocale($request);

        return view('public.home', [
            'bundles' => BundleDefinition::query()->where('is_active', true)->orderBy('price_ron')->get(),
            'weekStart' => CarbonImmutable::now(AvailabilityService::TIMEZONE)->startOfWeek(CarbonImmutable::MONDAY),
        ]);
    }

    public function setLocale(Request $request): RedirectResponse
    {
        $validated = $request->validate(['locale' => ['required', 'in:ro,en']]);
        $request->session()->put('locale', $validated['locale']);

        $referer = (string) $request->headers->get('referer', '');
        $refererHost = parse_url($referer, PHP_URL_HOST);
        $appHost = parse_url((string) config('app.url'), PHP_URL_HOST);
        if ($referer === '' || (is_string($refererHost) && is_string($appHost) && ! hash_equals($appHost, $refererHost))) {
            return redirect()->route('home');
        }

        $path = parse_url($referer, PHP_URL_PATH) ?: '/';
        $query = parse_url($referer, PHP_URL_QUERY);

        return redirect($path.($query ? '?'.$query : ''));
    }

    public function availability(Request $request, AvailabilityService $availability): JsonResponse
    {
        $this->applyLocale($request);
        $validated = $request->validate(['week' => ['nullable', 'date_format:Y-m-d']]);
        $timezone = AvailabilityService::TIMEZONE;
        /** @var CarbonImmutable $week */
        $week = isset($validated['week'])
            ? CarbonImmutable::parse($validated['week'], $timezone)->startOfWeek(CarbonImmutable::MONDAY)
            : CarbonImmutable::now($timezone)->startOfWeek(CarbonImmutable::MONDAY);

        if ($week->lessThan(CarbonImmutable::now($timezone)->startOfWeek(CarbonImmutable::MONDAY))) {
            throw ValidationException::withMessages(['week' => __('public.week_in_past')]);
        }

        CarbonImmutable::setLocale(app()->getLocale());
        $days = [];
        for ($offset = 0; $offset < 7; $offset++) {
            $day = $week->addDays($offset);
            $days[] = [
                'date' => $day->format('Y-m-d'),
                'label' => $day->translatedFormat('D'),
                'day' => $day->format('j'),
                'slots' => $availability->slotsForDate($day->format('Y-m-d'))->map(fn (array $slot): array => [
                    'starts_at' => $slot['starts_at']->toIso8601String(),
                    'time' => $slot['starts_at']->format('H:i'),
                ])->values()->all(),
            ];
        }

        return response()->json(['week_start' => $week->format('Y-m-d'), 'timezone' => $timezone, 'days' => $days]);
    }

    public function bookingForm(Request $request): View|RedirectResponse
    {
        $this->applyLocale($request);
        $type = $request->query('type', 'intro');
        abort_unless(in_array($type, ['intro', 'bundle'], true), 404);

        $entitlement = null;
        if ($type === 'bundle') {
            abort_unless($request->hasValidSignature(false) && $request->query('expires') !== null, 403);
            $entitlement = BundleEntitlement::query()->with('definition')->findOrFail((int) $request->query('entitlement'));
            if ($entitlement->status !== 'active' || $entitlement->expires_at === null || $entitlement->expires_at->lessThanOrEqualTo(now()) || ($entitlement->credits_remaining + $entitlement->free_credits_remaining) < 1) {
                return redirect()->route('home')->withErrors(['bundle' => __('public.no_bundle_credits')]);
            }
        }

        $selectedSlot = $request->query('slot');
        try {
            $weekStart = $selectedSlot
                ? CarbonImmutable::parse($selectedSlot)->setTimezone(AvailabilityService::TIMEZONE)->startOfWeek(CarbonImmutable::MONDAY)
                : CarbonImmutable::now(AvailabilityService::TIMEZONE)->startOfWeek(CarbonImmutable::MONDAY);
        } catch (\Throwable $exception) {
            $weekStart = CarbonImmutable::now(AvailabilityService::TIMEZONE)->startOfWeek(CarbonImmutable::MONDAY);
        }

        $verificationId = $request->query('verification_id', $request->session()->get('contact_verification_id'));
        $verification = is_string($verificationId) ? ContactVerificationChallenge::query()->whereKey($verificationId)->first() : null;
        $oldEmail = $request->old('email');
        if ($verification && (! $verification->isUsable() || $verification->customer_email !== mb_strtolower(is_string($oldEmail) ? $oldEmail : $verification->customer_email))) {
            $verification = null;
        }
        if ($verification) {
            $request->session()->put('contact_verification_id', $verification->id);
            $savedForm = $request->session()->get('contact_verification_form_data');
            if ($verification->return_to === 'booking' && is_array($savedForm)
                && is_string($savedForm['email'] ?? null) && mb_strtolower($savedForm['email']) === $verification->customer_email
                && is_string($savedForm['phone'] ?? null) && PhoneNumber::normalize($savedForm['phone']) === $verification->customer_phone) {
                $request->session()->flash('_old_input', $savedForm);
            }
        }

        return view('public.booking', [
            'type' => $type,
            'verification' => $type === 'intro' ? $verification : null,
            'entitlement' => $entitlement,
            'weekStart' => $weekStart,
            'policyVersion' => config('easyenglish.policy_version'),
            'formAction' => $type === 'bundle'
                ? URL::signedRoute('booking.submit', ['type' => 'bundle', 'entitlement' => $entitlement->id], now()->addMinutes(30))
                : route('booking.submit'),
        ]);
    }

    public function startContactVerification(Request $request, ContactVerificationService $verificationService): RedirectResponse
    {
        $this->applyLocale($request);
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:254'],
            'phone' => ['required', 'string', 'max:32', 'regex:/^\\+?[0-9\\s().-]{7,32}$/'],
            'whatsapp_service_consent' => [config('services.contact_verification.mode', 'email_and_whatsapp') === 'email_only' ? 'nullable' : 'accepted'],
            'return_to' => ['required', 'in:booking,purchase'],
            'bundle_id' => ['nullable', 'integer', 'required_if:return_to,purchase'],
        ]);
        $digits = preg_replace('/\D+/', '', $validated['phone']) ?? '';
        if (strlen($digits) < 7 || strlen($digits) > 15) {
            throw ValidationException::withMessages(['phone' => __('public.phone_invalid')]);
        }
        $bundleId = null;
        if ($validated['return_to'] === 'purchase') {
            $bundle = BundleDefinition::query()->where('is_active', true)->findOrFail((int) $validated['bundle_id']);
            $bundleId = $bundle->id;
        }
        try {
            if (($validated['whatsapp_service_consent'] ?? null) === '1') {
                WhatsAppContact::recordConsent($validated['phone'], 'contact_verification');
            }
            $challenge = $verificationService->start($validated['email'], $validated['phone'], app()->getLocale(), $validated['return_to'], $bundleId);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            $reason = $exception->getMessage();
            $accessToken = config('services.whatsapp.access_token');
            if (is_string($accessToken) && $accessToken !== '') {
                $reason = str_replace($accessToken, '[REDACTED]', $reason);
            }

            Log::warning('Contact verification delivery could not be started.', [
                'exception' => $exception::class,
                'reason' => mb_substr($reason, 0, 500),
            ]);

            return back()->withInput()->withErrors(['verification' => __('public.verification_delivery_failed')]);
        }
        $request->session()->put('contact_verification_id', $challenge->id);
        $request->session()->put('contact_verification_form_data', $request->only([
            'name', 'email', 'phone', 'english_level', 'session_goal', 'notes', 'starts_at',
            'accepted_policy', 'whatsapp_service_consent', 'purchase_token', 'type', 'bundle_id', 'return_to',
        ]));

        return back()->withInput()->with('status', __('public.verification_sent'));
    }

    public function confirmPhoneVerification(Request $request, ContactVerificationService $verificationService): RedirectResponse
    {
        $this->applyLocale($request);
        $validated = $request->validate([
            'verification_id' => ['required', 'uuid'],
            'phone_code' => ['required', 'digits:6'],
        ]);
        $challenge = ContactVerificationChallenge::query()->whereKey((string) $validated['verification_id'])->firstOrFail();
        abort_unless(hash_equals((string) $request->session()->get('contact_verification_id'), $challenge->id), 403);
        $verificationService->confirmPhone($challenge->id, $validated['phone_code']);

        return back()->withInput()->with('status', __('public.verification_phone_success'));
    }

    public function submitBooking(Request $request, AvailabilityService $availability, BookingService $bookings): RedirectResponse
    {
        $this->applyLocale($request);
        $validated = $request->validate([
            'type' => ['required', 'in:intro,bundle'],
            'starts_at' => ['required', 'date'],
            'name' => ['required', 'string', 'max:160'],
            'email' => ['required', 'email', 'max:254'],
            'phone' => ['required', 'string', 'max:32', 'regex:/^\+?[0-9\s().-]{7,32}$/', function (string $attribute, mixed $value, Closure $fail): void {
                $digits = preg_replace('/\D+/', '', (string) $value) ?? '';
                if (strlen($digits) < 7 || strlen($digits) > 15) {
                    $fail(__('public.phone_invalid'));
                }
            }],
            'english_level' => ['required', 'in:A1,A2,B1,B2,C1,C2'],
            'session_goal' => ['required', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'accepted_policy' => ['accepted'],
            'whatsapp_service_consent' => [config('services.contact_verification.mode', 'email_and_whatsapp') === 'email_only' ? 'nullable' : 'accepted'],
            'entitlement' => ['nullable', 'integer', 'required_if:type,bundle'],
            'verification_id' => ['nullable', 'uuid'],
        ]);

        if ($validated['type'] === 'bundle') {
            abort_unless($request->hasValidSignature(false) && $request->query('expires') !== null && $request->query('type') === 'bundle' && (int) $request->query('entitlement') === (int) $validated['entitlement'], 403);
        }

        $startsAt = CarbonImmutable::parse($validated['starts_at'])->setTimezone(AvailabilityService::TIMEZONE);
        $availability->assertBookable($startsAt);

        $verificationChallenge = null;
        if ($validated['type'] === 'intro') {
            $challengeId = $validated['verification_id'] ?? $request->session()->get('contact_verification_id');
            $verificationChallenge = is_string($challengeId) ? ContactVerificationChallenge::query()->find($challengeId) : null;
            if (! $verificationChallenge || ! $verificationChallenge->isUsable() || ! $verificationChallenge->isComplete()
                || $verificationChallenge->customer_email !== mb_strtolower(trim($validated['email']))
                || $verificationChallenge->customer_phone !== PhoneNumber::normalize($validated['phone'])) {
                throw ValidationException::withMessages(['verification' => __('public.verification_required')]);
            }
        }

        $entitlement = null;
        if ($validated['type'] === 'bundle') {
            $entitlement = BundleEntitlement::query()->with('definition')->findOrFail((int) $validated['entitlement']);
            if ($entitlement->status !== 'active' || $entitlement->expires_at === null || $entitlement->expires_at->lessThanOrEqualTo($startsAt->utc())) {
                throw ValidationException::withMessages(['entitlement' => __('public.no_bundle_credits')]);
            }
        }

        if (($validated['whatsapp_service_consent'] ?? null) === '1') {
            WhatsAppContact::recordConsent($validated['phone'], 'booking_form');
        }
        $booking = $bookings->holdSlot([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'],
            'english_level' => $validated['english_level'],
            'session_goal' => $validated['session_goal'],
            'notes' => $validated['notes'] ?? null,
            'accepted_policy_version' => config('easyenglish.policy_version'),
            'locale' => app()->getLocale(),
        ], $startsAt, $entitlement?->id, $validated['type'] === 'intro', $verificationChallenge?->email_verified_at !== null, $verificationChallenge?->phone_verified_at !== null);
        $bookings->confirm($booking->id);
        if ($verificationChallenge) {
            $verificationChallenge->update(['consumed_at' => now()]);
            $request->session()->forget(['contact_verification_id', 'contact_verification_form_data']);
        }

        return redirect()->to(URL::signedRoute('booking.confirmed', ['reference' => $booking->public_reference], null));
    }

    public function confirmEmailVerification(Request $request, string $challenge, string $token, ContactVerificationService $verificationService): RedirectResponse
    {
        $this->applyLocale($request);
        if (! $request->hasValidSignature()) {
            return redirect()->route('booking.create', ['type' => 'intro'])
                ->withErrors(['verification' => __('public.verification_email_invalid')]);
        }
        $verified = $verificationService->confirmEmail($challenge, $token);
        $request->session()->put('locale', $verified->locale);
        app()->setLocale($verified->locale);
        $request->session()->put('contact_verification_id', $verified->id);
        if ($verified->return_to === 'purchase' && $verified->return_bundle_id !== null) {
            $bundle = BundleDefinition::query()->where('is_active', true)->find($verified->return_bundle_id);
            if ($bundle) {
                return redirect()->route('purchase', ['bundle' => $bundle, 'verification_id' => $verified->id])
                    ->with('status', __('public.verification_email_success'));
            }
        }

        $savedForm = $request->session()->get('contact_verification_form_data', []);
        $slot = is_array($savedForm) && is_string($savedForm['starts_at'] ?? null) ? $savedForm['starts_at'] : null;

        return redirect()->route('booking.create', array_filter([
            'type' => 'intro',
            'verification_id' => $verified->id,
            'slot' => $verified->return_to === 'booking' ? $slot : null,
        ]))->with('status', __('public.verification_email_success'));
    }

    public function confirmed(Request $request, string $reference): View
    {
        abort_unless($request->hasValidSignature(false), 403);
        $booking = Booking::query()->where('public_reference', $reference)->where('status', 'confirmed')->firstOrFail();
        $this->applyLocale($request);

        return view('public.confirmed', ['booking' => $booking]);
    }

    public function manageBooking(Request $request, string $reference, CancellationPolicyService $policy): View
    {
        $this->applyLocale($request);
        $booking = Booking::query()->where('public_reference', $reference)->firstOrFail();
        $actions = $policy->customerActions($booking);
        $expiresAt = $booking->starts_at->addDay();
        if ($expiresAt->lessThanOrEqualTo(now())) {
            $expiresAt = CarbonImmutable::now()->addDays(7);
        }

        return view('public.booking-manage', [
            'booking' => $booking,
            'actions' => $actions,
            'cancelUrl' => URL::signedRoute('booking.cancel', ['reference' => $reference], $expiresAt),
            'rescheduleUrl' => URL::signedRoute('booking.reschedule.form', ['reference' => $reference], $expiresAt),
        ]);
    }

    public function cancelBooking(Request $request, string $reference, BookingService $bookings): RedirectResponse
    {
        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);
        $booking = Booking::query()->where('public_reference', $reference)->firstOrFail();
        $bookings->cancelByCustomer($booking->id, $validated['reason'] ?? 'Customer cancellation request.');
        $expiresAt = $booking->starts_at->addDay();

        return redirect()->to(URL::signedRoute('booking.manage', ['reference' => $reference], $expiresAt))
            ->with('status', __('public.booking_cancelled'));
    }

    public function rescheduleForm(Request $request, string $reference, AvailabilityService $availability, CancellationPolicyService $policy): View
    {
        $this->applyLocale($request);
        $booking = Booking::query()->where('public_reference', $reference)->firstOrFail();
        abort_unless($policy->customerActions($booking)['can_reschedule'], 403);

        $weekStart = $booking->starts_at->setTimezone(AvailabilityService::TIMEZONE)->startOfWeek(CarbonImmutable::MONDAY);
        $days = [];
        for ($offset = 0; $offset < 7; $offset++) {
            $day = $weekStart->addDays($offset);
            $slots = $availability->slotsForDate($day->format('Y-m-d'), $booking->id)
                ->reject(fn (array $slot): bool => $slot['starts_at']->utc()->equalTo($booking->starts_at->utc()))
                ->map(fn (array $slot): array => [
                    'starts_at' => $slot['starts_at']->utc()->toIso8601String(),
                    'time' => $slot['starts_at']->format('H:i'),
                ])->values()->all();
            $days[] = ['date' => $day->format('Y-m-d'), 'label' => $day->translatedFormat('D'), 'day' => $day->format('j'), 'slots' => $slots];
        }
        $expiresAt = $booking->starts_at->addDay();

        return view('public.booking-reschedule', [
            'booking' => $booking,
            'days' => $days,
            'submitUrl' => URL::signedRoute('booking.reschedule', ['reference' => $reference], $expiresAt),
        ]);
    }

    public function rescheduleBooking(Request $request, string $reference, BookingService $bookings): RedirectResponse
    {
        $validated = $request->validate(['starts_at' => ['required', 'date']]);
        $booking = Booking::query()->where('public_reference', $reference)->firstOrFail();
        $newStartsAt = CarbonImmutable::parse($validated['starts_at'])->setTimezone(AvailabilityService::TIMEZONE);
        $bookings->rescheduleByCustomer($booking->id, $newStartsAt);
        $updated = $booking->fresh();
        $expiresAt = $updated->starts_at->addDay();

        return redirect()->to(URL::signedRoute('booking.manage', ['reference' => $reference], $expiresAt))
            ->with('status', __('public.booking_rescheduled'));
    }

    public function purchase(Request $request, BundleDefinition $bundle): View
    {
        $this->applyLocale($request);
        abort_unless($bundle->is_active, 404);
        $checkoutReady = (bool) config('services.stripe.checkout_enabled')
            && is_string(config('services.stripe.secret')) && config('services.stripe.secret') !== ''
            && is_string(config('services.stripe.webhook_secret')) && config('services.stripe.webhook_secret') !== '';
        $token = null;
        if ($checkoutReady) {
            $sessionKey = 'bundle_checkout_tokens.'.$bundle->id;
            $token = $request->session()->get($sessionKey);
            if (! is_string($token) || ! preg_match('/^[A-Za-z0-9]{40,80}$/', $token)) {
                $token = Str::random(48);
                $request->session()->put($sessionKey, $token);
            }
        }

        $verificationId = $request->query('verification_id', $request->session()->get('contact_verification_id'));
        $verification = is_string($verificationId) ? ContactVerificationChallenge::query()->whereKey($verificationId)->first() : null;
        $oldEmail = $request->old('email');
        $oldPhone = $request->old('phone');
        if ($verification && (! $verification->isUsable()
            || $verification->customer_email !== mb_strtolower(is_string($oldEmail) ? $oldEmail : $verification->customer_email)
            || $verification->customer_phone !== PhoneNumber::normalize(is_string($oldPhone) ? $oldPhone : $verification->customer_phone))) {
            $verification = null;
        }
        if ($verification) {
            $request->session()->put('contact_verification_id', $verification->id);
            $savedForm = $request->session()->get('contact_verification_form_data');
            if ($verification->return_to === 'purchase' && $verification->return_bundle_id === $bundle->id && is_array($savedForm)
                && is_string($savedForm['email'] ?? null) && mb_strtolower($savedForm['email']) === $verification->customer_email
                && is_string($savedForm['phone'] ?? null) && PhoneNumber::normalize($savedForm['phone']) === $verification->customer_phone) {
                $request->session()->flash('_old_input', $savedForm);
            }
        }

        return view('public.purchase', ['bundle' => $bundle, 'checkoutReady' => $checkoutReady, 'purchaseToken' => $token, 'verification' => $verification]);
    }

    public function startCheckout(Request $request, BundleDefinition $bundle, StripeCheckoutService $checkout, BundleEntitlementService $entitlements): RedirectResponse
    {
        $this->applyLocale($request);
        abort_unless($bundle->is_active, 404);
        $validated = $request->validate([
            'purchase_token' => ['required', 'string', 'min:40', 'max:80', 'regex:/^[A-Za-z0-9]+$/'],
            'name' => ['required', 'string', 'max:160'],
            'email' => ['required', 'email', 'max:254'],
            'phone' => ['required', 'string', 'max:32', 'regex:/^\+?[0-9\s().-]{7,32}$/', function (string $attribute, mixed $value, Closure $fail): void {
                $digits = preg_replace('/\D+/', '', (string) $value) ?? '';
                if (strlen($digits) < 7 || strlen($digits) > 15) {
                    $fail(__('public.phone_invalid'));
                }
            }],
            'whatsapp_service_consent' => [config('services.contact_verification.mode', 'email_and_whatsapp') === 'email_only' ? 'nullable' : 'accepted'],
            'verification_id' => ['nullable', 'uuid'],
        ]);
        $expectedToken = $request->session()->get('bundle_checkout_tokens.'.$bundle->id);
        abort_unless(is_string($expectedToken) && hash_equals($expectedToken, $validated['purchase_token']), 403);

        if (! (bool) config('services.stripe.checkout_enabled')
            || ! is_string(config('services.stripe.secret')) || config('services.stripe.secret') === ''
            || ! is_string(config('services.stripe.webhook_secret')) || config('services.stripe.webhook_secret') === '') {
            return back()->withInput()->withErrors(['checkout' => __('public.checkout_unavailable_text')]);
        }

        $verificationChallenge = null;
        $verificationId = $validated['verification_id'] ?? $request->session()->get('contact_verification_id');
        if (is_string($verificationId)) {
            $candidate = ContactVerificationChallenge::query()->find($verificationId);
            if ($candidate && $candidate->isUsable() && $candidate->isComplete()
                && $candidate->customer_email === mb_strtolower(trim($validated['email']))
                && $candidate->customer_phone === PhoneNumber::normalize($validated['phone'])) {
                $verificationChallenge = $candidate;
            }
        }

        try {
            if (($validated['whatsapp_service_consent'] ?? null) === '1') {
                WhatsAppContact::recordConsent($validated['phone'], 'bundle_checkout');
            }
            $payment = $checkout->start($bundle, [
                'name' => $validated['name'], 'email' => $validated['email'], 'phone' => $validated['phone'],
            ], $validated['purchase_token'], app()->getLocale(), $verificationChallenge);
            if ($verificationChallenge !== null && $payment->status === 'succeeded') {
                $entitlements->grantIncludedFreeCreditIfEligible($payment->bundle_entitlement_id);
            }
        } catch (\Throwable $exception) {
            Log::warning('Stripe Checkout could not be started.', ['bundle_id' => $bundle->id, 'exception' => $exception::class]);

            return back()->withInput()->withErrors(['checkout' => __('public.checkout_error')]);
        }

        if ($payment->status === 'succeeded' && $payment->provider_checkout_id) {
            $request->session()->forget('contact_verification_form_data');

            return redirect()->route('purchase.success', ['session_id' => $payment->provider_checkout_id]);
        }
        if ($payment->checkout_url === null && is_string($payment->provider_checkout_id)) {
            $request->session()->forget('contact_verification_form_data');

            return redirect()->route('purchase.success', ['session_id' => $payment->provider_checkout_id]);
        }
        if (! is_string($payment->checkout_url) || $payment->checkout_url === '') {
            return back()->withInput()->withErrors(['checkout' => __('public.checkout_pending')]);
        }

        $host = parse_url($payment->checkout_url, PHP_URL_HOST);
        if (parse_url((string) $payment->checkout_url, PHP_URL_SCHEME) !== 'https' || $host !== 'checkout.stripe.com') {
            Log::error('Stripe returned an unexpected Checkout URL.', ['payment_id' => $payment->id]);

            return back()->withInput()->withErrors(['checkout' => __('public.checkout_error')]);
        }

        $request->session()->forget('contact_verification_form_data');

        return redirect()->away((string) $payment->checkout_url);
    }

    public function purchaseSuccess(Request $request, StripeCheckoutService $checkout): View
    {
        $this->applyLocale($request);
        $validated = $request->validate(['session_id' => ['required', 'string', 'max:255', 'regex:/^cs_[A-Za-z0-9_]+$/']]);
        $payment = null;
        try {
            $payment = $checkout->reconcileSessionById($validated['session_id']);
        } catch (\Throwable $exception) {
            Log::notice('Stripe return page could not reconcile checkout yet.', ['session_hash' => substr(hash('sha256', $validated['session_id']), 0, 16), 'exception' => $exception::class]);
        }

        $entitlement = $payment?->entitlement()->with('definition')->first();
        $bookingUrl = null;
        if ($payment?->status === 'succeeded' && $entitlement?->status === 'active'
            && $entitlement->expires_at?->isFuture()
            && ($entitlement->credits_remaining + $entitlement->free_credits_remaining) > 0) {
            $bookingUrl = URL::signedRoute('booking.create', ['type' => 'bundle', 'entitlement' => $entitlement->id], now()->addDays(7));
        }

        return view('public.purchase-result', [
            'payment' => $payment,
            'entitlement' => $entitlement,
            'bookingUrl' => $bookingUrl,
            'sessionId' => $validated['session_id'],
        ]);
    }

    public function purchaseCancel(Request $request, BundleDefinition $bundle, StripeCheckoutService $checkout): RedirectResponse
    {
        $this->applyLocale($request);
        $validated = $request->validate([
            'token' => ['required', 'string', 'min:40', 'max:80', 'regex:/^[A-Za-z0-9]+$/'],
            'session_id' => ['nullable', 'string', 'max:255', 'regex:/^cs_[A-Za-z0-9_]+$/'],
        ]);
        try {
            $payment = $checkout->cancelSession($bundle->id, $validated['token'], $validated['session_id'] ?? null);
        } catch (\Throwable $exception) {
            Log::notice('Stripe checkout cancellation requires reconciliation.', ['bundle_id' => $bundle->id, 'exception' => $exception::class]);

            return redirect()->route('purchase', $bundle)->withErrors(['checkout' => __('public.checkout_cancel_error')]);
        }

        if (in_array($payment?->status, ['succeeded', 'pending'], true) && is_string($payment->provider_checkout_id)) {
            return redirect()->route('purchase.success', ['session_id' => $payment->provider_checkout_id]);
        }

        return redirect()->route('purchase', $bundle)->with('checkout_cancelled', __('public.purchase_cancelled_notice'));
    }

    public function policy(Request $request, string $page): View
    {
        $this->applyLocale($request);
        abort_unless(in_array($page, ['privacy', 'terms'], true), 404);

        return view('public.policy', ['page' => $page]);
    }

    private function applyLocale(Request $request): void
    {
        app()->setLocale($request->session()->get('locale', 'ro'));
    }
}
