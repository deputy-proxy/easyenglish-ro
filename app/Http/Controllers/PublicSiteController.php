<?php

namespace App\Http\Controllers;

use App\Actions\Fortify\CreateNewUser;
use App\Models\Booking;
use App\Models\BundleDefinition;
use App\Models\BundleEntitlement;
use App\Models\ContactVerificationChallenge;
use App\Models\CreditTransaction;
use App\Models\Faq;
use App\Models\FreeSessionClaim;
use App\Models\Payment;
use App\Models\ProgressReport;
use App\Models\Testimonial;
use App\Models\User;
use App\Models\WhatsAppContact;
use App\Services\Booking\AvailabilityService;
use App\Services\Booking\BookingService;
use App\Services\Booking\CancellationPolicyService;
use App\Services\Payments\CustomerPurchasePresenter;
use App\Services\Payments\StripeCheckoutService;
use App\Services\Progress\ProgressChartData;
use App\Services\Verification\ContactVerificationService;
use App\Services\Verification\FreeSessionEligibilityService;
use App\Support\PhoneNumber;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PublicSiteController extends Controller
{
    public function home(Request $request, FreeSessionEligibilityService $eligibilityService): View
    {
        $this->applyLocale($request);

        $user = $request->user();
        $verificationId = $request->session()->get('contact_verification_id');
        $verification = is_string($verificationId)
            ? ContactVerificationChallenge::query()->whereKey($verificationId)->first()
            : null;

        if ($verification && (! $verification->isUsable() || $verification->customer_email !== mb_strtolower((string) $user?->email))) {
            $verification = null;
        }

        $emailOnly = config('services.contact_verification.mode', 'email_and_whatsapp') === 'email_only';
        $formData = $request->session()->get('contact_verification_form_data', []);
        $oldPhone = $request->old('phone');
        $phone = is_string($oldPhone)
            ? $oldPhone
            : ($verification->customer_phone ?? (is_array($formData) && is_string($formData['phone'] ?? null) ? $formData['phone'] : (string) data_get($user, 'phone', '')));
        $phoneAlreadyVerified = $user !== null && $user->phone_verified_at !== null
            && PhoneNumber::normalize((string) $user->phone) === PhoneNumber::normalize($phone);
        $whatsappConsent = $user !== null && WhatsAppContact::query()
            ->where('phone', PhoneNumber::normalize($phone))
            ->first()?->canReceiveServiceMessages() === true;
        $contactsVerified = $user !== null
            && $user->email_verified_at !== null
            && ($emailOnly || $phoneAlreadyVerified);
        $eligibilityPhone = $verification->customer_phone ?? $phone;
        $eligibility = $contactsVerified
            ? $eligibilityService->check((string) $user->email, $eligibilityPhone)
            : null;

        return view('public.home', [
            'bundles' => BundleDefinition::query()->where('is_active', true)->orderBy('sort_order')->orderBy('price_ron')->get(),
            'teachers' => User::query()->where('is_teacher', true)->where('teacher_active', true)->orderBy('name')->get(['id', 'name', 'teacher_photo_path', 'teacher_bio']),
            'faqs' => Faq::query()->publiclyVisible()->where('locale', app()->getLocale())->orderBy('sort_order')->orderBy('id')->get(['id', 'question', 'answer', 'category']),
            'testimonials' => Testimonial::query()->publiclyVisible()->where('locale', app()->getLocale())->orderBy('sort_order')->orderByDesc('updated_at')->get(['id', 'quote', 'author_name', 'author_context']),
            'weekStart' => CarbonImmutable::now(AvailabilityService::TIMEZONE)->startOfWeek(CarbonImmutable::MONDAY),
            'contactsVerified' => $contactsVerified,
            'verification' => $verification,
            'eligibility' => $eligibility,
            'phoneAlreadyVerified' => $phoneAlreadyVerified,
            'whatsappConsent' => $whatsappConsent,
            'bookingFormData' => $formData,
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

        if (is_string($request->query('slot')) && ! $request->user()) {
            return redirect()->guest(route('login'));
        }

        $entitlement = null;
        if ($type === 'bundle') {
            abort_unless(URL::hasValidSignature($request, true, ['slot']) && $request->query('expires') !== null, 403);
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

        $user = $request->user();
        $contactsVerified = $user !== null
            && $user->email_verified_at !== null
            && $user->phone_verified_at !== null
            && PhoneNumber::normalize((string) $user->phone) === PhoneNumber::normalize(is_string($request->old('phone')) ? $request->old('phone') : (string) $user->phone);

        return view('public.booking', [
            'type' => $type,
            'contactsVerified' => $contactsVerified,
            'verification' => $type === 'intro' ? $verification : null,
            'entitlement' => $entitlement,
            'weekStart' => $weekStart,
            'policyVersion' => config('easyenglish.policy_version'),
            'formAction' => $type === 'bundle'
                ? URL::signedRoute('booking.submit', ['type' => 'bundle', 'entitlement' => $entitlement->id], now()->addMinutes(30))
                : route('booking.submit'),
        ]);
    }

    public function startContactVerification(
        Request $request,
        ContactVerificationService $verificationService,
        FreeSessionEligibilityService $eligibilityService,
    ): RedirectResponse|JsonResponse {
        $this->applyLocale($request);
        $user = $request->user();
        abort_unless($user !== null, 401);

        $validated = $request->validate([
            'email' => ['required', 'email', 'max:254'],
            'phone' => ['required', 'string', 'max:32', 'regex:/^\\+?[0-9\\s().-]{7,32}$/'],
            'return_to' => ['required', 'in:booking,purchase'],
            'bundle_id' => ['nullable', 'integer', 'required_if:return_to,purchase'],
        ]);

        $email = mb_strtolower(trim((string) $user->email));
        if ($email !== mb_strtolower(trim($validated['email']))) {
            $exception = ValidationException::withMessages(['email' => __('public.verification_contact_changed')]);
            if ($request->expectsJson()) {
                return $this->verificationJsonError($exception);
            }
            throw $exception;
        }

        $phone = PhoneNumber::normalize($validated['phone']);
        $digits = preg_replace('/\\D+/', '', $validated['phone']) ?? '';
        if (strlen($digits) < 7 || strlen($digits) > 15) {
            throw ValidationException::withMessages(['phone' => __('public.phone_invalid')]);
        }

        $emailOnly = config('services.contact_verification.mode', 'email_and_whatsapp') === 'email_only';
        $emailAlreadyVerified = $user->email_verified_at !== null;
        $phoneAlreadyVerified = $user->phone_verified_at !== null
            && PhoneNumber::normalize((string) $user->phone) === $phone;

        $hasWhatsAppConsent = WhatsAppContact::query()->where('phone', $phone)->first()?->canReceiveServiceMessages() === true;
        $consent = $request->validate([
            'whatsapp_service_consent' => [$emailOnly || $phoneAlreadyVerified || $hasWhatsAppConsent ? 'nullable' : 'accepted'],
        ]);

        if ($validated['return_to'] === 'booking') {
            $request->validate([
                'type' => ['required', 'in:intro'],
                'starts_at' => ['required', 'date'],
                'english_level' => ['required', 'in:A1,A2,B1,B2,C1,C2'],
                'session_goal' => ['required', 'string', 'max:500'],
                'notes' => ['nullable', 'string', 'max:5000'],
                'accepted_policy' => ['accepted'],
            ]);
        }

        $bundleId = null;
        if ($validated['return_to'] === 'purchase') {
            $bundle = BundleDefinition::query()->where('is_active', true)->findOrFail((int) $validated['bundle_id']);
            $bundleId = $bundle->id;
        }

        if (($consent['whatsapp_service_consent'] ?? null) === '1') {
            WhatsAppContact::recordConsent($phone, 'contact_verification');
        }

        try {
            $challenge = $verificationService->start(
                $email,
                $phone,
                app()->getLocale(),
                $validated['return_to'],
                $bundleId,
                $emailAlreadyVerified,
                $phoneAlreadyVerified,
            );
        } catch (ValidationException $exception) {
            if ($request->expectsJson()) {
                return $this->verificationJsonError($exception);
            }

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

            if ($request->expectsJson()) {
                return response()->json([
                    'status' => 'delivery_failed',
                    'message' => __('public.verification_delivery_failed'),
                ], 503);
            }

            return back()->withInput()->withErrors(['verification' => __('public.verification_delivery_failed')]);
        }

        $request->session()->put('contact_verification_id', $challenge->id);
        $request->session()->put('contact_verification_form_data', $request->only([
            'email', 'phone', 'english_level', 'session_goal', 'notes', 'starts_at',
            'accepted_policy', 'whatsapp_service_consent', 'type', 'bundle_id', 'return_to',
        ]));
        $request->session()->flash('_old_input', ['phone' => $phone]);

        if ($request->expectsJson() && $validated['return_to'] === 'booking') {
            $message = $challenge->isComplete()
                ? __('public.verification_all_complete')
                : __('public.verification_sent');

            return $this->bookingVerificationStepResponse($request, $challenge, $eligibilityService, $message);
        }

        return back()->withInput()->with('status', __('public.verification_sent'));
    }

    public function confirmPhoneVerification(
        Request $request,
        ContactVerificationService $verificationService,
        FreeSessionEligibilityService $eligibilityService,
    ): RedirectResponse|JsonResponse {
        $this->applyLocale($request);
        $validated = $request->validate([
            'verification_id' => ['required', 'uuid'],
            'phone_code' => ['required', 'digits:6'],
            'phone' => ['required', 'string', 'max:32'],
        ]);
        $challenge = ContactVerificationChallenge::query()->whereKey((string) $validated['verification_id'])->firstOrFail();
        $user = $request->user();
        abort_unless($user !== null && hash_equals((string) $request->session()->get('contact_verification_id'), $challenge->id), 403);
        abort_unless($challenge->customer_email === mb_strtolower($user->email), 403);
        if ($challenge->customer_phone !== PhoneNumber::normalize($validated['phone'])) {
            $exception = ValidationException::withMessages(['phone' => __('public.verification_contact_changed')]);
            if ($request->expectsJson()) {
                return $this->verificationJsonError($exception);
            }
            throw $exception;
        }

        try {
            $verified = $verificationService->confirmPhone($challenge->id, $validated['phone_code']);
        } catch (ValidationException $exception) {
            if ($request->expectsJson()) {
                return $this->verificationJsonError($exception);
            }

            throw $exception;
        }
        $user->forceFill(['phone' => $verified->customer_phone, 'phone_verified_at' => now()])->save();
        $request->session()->flash('_old_input', ['phone' => $verified->customer_phone]);

        if ($request->expectsJson()) {
            return $this->bookingVerificationStepResponse($request, $verified, $eligibilityService, __('public.verification_phone_success'));
        }

        return $this->verificationFallbackRedirect($request, $challenge, __('public.verification_phone_success'));
    }

    public function confirmEmailCodeVerification(
        Request $request,
        ContactVerificationService $verificationService,
        FreeSessionEligibilityService $eligibilityService,
    ): RedirectResponse|JsonResponse {
        $this->applyLocale($request);
        $validated = $request->validate([
            'verification_id' => ['required', 'uuid'],
            'email_code' => ['required', 'digits:6'],
            'phone' => ['required', 'string', 'max:32'],
        ]);
        $challenge = ContactVerificationChallenge::query()->whereKey((string) $validated['verification_id'])->firstOrFail();
        $user = $request->user();
        abort_unless($user !== null && hash_equals((string) $request->session()->get('contact_verification_id'), $challenge->id), 403);
        abort_unless($challenge->customer_email === mb_strtolower($user->email), 403);
        if ($challenge->customer_phone !== PhoneNumber::normalize($validated['phone'])) {
            $exception = ValidationException::withMessages(['verification' => __('public.verification_contact_changed')]);
            if ($request->expectsJson()) {
                return $this->verificationJsonError($exception);
            }
            throw $exception;
        }

        try {
            $verified = $verificationService->confirmEmailCode($challenge->id, $validated['email_code']);
        } catch (ValidationException $exception) {
            if ($request->expectsJson()) {
                return $this->verificationJsonError($exception);
            }

            throw $exception;
        }
        $user->forceFill(['email_verified_at' => now()])->save();
        $request->session()->flash('_old_input', ['phone' => $verified->customer_phone]);

        if ($request->expectsJson()) {
            return $this->bookingVerificationStepResponse($request, $verified, $eligibilityService, __('public.verification_email_success'));
        }

        return $this->verificationFallbackRedirect($request, $verified, __('public.verification_email_success'));
    }

    public function confirmEmailVerification(
        Request $request,
        string $challenge,
        string $token,
        ContactVerificationService $verificationService,
        FreeSessionEligibilityService $eligibilityService,
    ): RedirectResponse {
        $this->applyLocale($request);
        if (! $request->hasValidSignature()) {
            return redirect()->route('home')->withErrors(['verification' => __('public.verification_email_invalid')]);
        }

        $user = $request->user();
        abort_unless($user !== null && hash_equals((string) $request->session()->get('contact_verification_id'), $challenge), 403);
        $candidate = ContactVerificationChallenge::query()->findOrFail($challenge);
        abort_unless($candidate->customer_email === mb_strtolower($user->email), 403);
        $verified = $verificationService->confirmEmail($challenge, $token);
        $user->forceFill(['email_verified_at' => now()])->save();
        $request->session()->put('locale', $verified->locale);
        app()->setLocale($verified->locale);
        $savedForm = $request->session()->get('contact_verification_form_data', []);
        $slot = is_array($savedForm) && is_string($savedForm['starts_at'] ?? null) ? $savedForm['starts_at'] : null;
        $message = __('public.verification_email_success');

        if ($verified->return_to === 'purchase' && $verified->return_bundle_id !== null) {
            $bundle = BundleDefinition::query()->where('is_active', true)->find($verified->return_bundle_id);
            if ($bundle) {
                return redirect()->route('purchase', ['bundle' => $bundle, 'verification_id' => $verified->id])
                    ->with('status', $message);
            }
        }

        if ($verified->isComplete()) {
            $phoneMatches = config('services.contact_verification.mode', 'email_and_whatsapp') === 'email_only'
                || ($user->phone_verified_at !== null && PhoneNumber::normalize((string) $user->phone) === $verified->customer_phone);
            if ($phoneMatches) {
                $eligibility = $eligibilityService->check((string) $user->email, $verified->customer_phone);
                $message = __($eligibility['eligible'] ? 'public.verification_eligible' : 'public.verification_already_used');
            }
        }

        if (is_array($savedForm)) {
            $request->session()->flash('_old_input', $savedForm);
        }

        return redirect()->route('home', is_string($slot) ? ['slot' => $slot] : [])
            ->with('status', $message);
    }

    private function verificationJsonError(ValidationException $exception): JsonResponse
    {
        $errors = $exception->errors();
        $field = array_key_first($errors) ?? 'verification';
        $message = $errors[$field][0] ?? __('public.verification_generic_error');
        $status = 'validation_error';
        $httpStatus = 422;

        if ($message === __('public.verification_rate_limited')) {
            $status = 'rate_limited';
            $httpStatus = 429;
        } elseif ($message === __('public.verification_resend_wait')) {
            $status = 'resend_wait';
            $httpStatus = 429;
        } elseif ($field === 'challenge' && str_contains(mb_strtolower($message), 'expir')) {
            $status = 'expired';
        } elseif ($field === 'challenge' && (str_contains(mb_strtolower($message), 'already been used') || str_contains(mb_strtolower($message), 'deja folosit'))) {
            $status = 'challenge_used';
        } elseif ($field === 'challenge' && $message === __('public.verification_channel_already_verified')) {
            $status = 'already_verified';
        } elseif (in_array($message, [__('public.verification_email_locked'), __('public.verification_phone_locked')], true)) {
            $status = 'attempts_locked';
        } elseif (in_array($field, ['email_code', 'phone_code'], true)
            && ! in_array($message, [__('public.verification_email_locked'), __('public.verification_phone_locked')], true)) {
            $status = 'invalid_code';
        } elseif ($message === __('public.verification_contact_changed')) {
            $status = 'contact_changed';
        }

        return response()->json([
            'status' => $status,
            'errors' => $errors,
            'message' => $message,
        ], $httpStatus);
    }

    private function verificationFallbackRedirect(Request $request, ContactVerificationChallenge $challenge, string $message): RedirectResponse
    {
        if ($challenge->return_to === 'purchase' && $challenge->return_bundle_id !== null) {
            $bundle = BundleDefinition::query()->where('is_active', true)->find($challenge->return_bundle_id);
            if ($bundle) {
                return redirect()->route('purchase', ['bundle' => $bundle, 'verification_id' => $challenge->id])
                    ->with('status', $message);
            }
        }

        $slot = $request->session()->get('contact_verification_form_data.starts_at');

        return redirect()->route('home', is_string($slot) ? ['slot' => $slot] : [])
            ->with('status', $message);
    }

    private function bookingVerificationStepResponse(
        Request $request,
        ?ContactVerificationChallenge $challenge,
        FreeSessionEligibilityService $eligibilityService,
        string $message,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user !== null, 401);
        $emailOnly = config('services.contact_verification.mode', 'email_and_whatsapp') === 'email_only';
        $phone = $challenge->customer_phone ?? (string) $user->phone;
        $phoneAlreadyVerified = $user->phone_verified_at !== null
            && PhoneNumber::normalize((string) $user->phone) === PhoneNumber::normalize($phone);
        $whatsappConsent = WhatsAppContact::query()->where('phone', PhoneNumber::normalize($phone))
            ->first()?->canReceiveServiceMessages() === true;
        $contactsVerified = $user->email_verified_at !== null && ($emailOnly || $phoneAlreadyVerified);
        $eligibility = $contactsVerified ? $eligibilityService->check((string) $user->email, $phone) : null;
        $verification = $challenge && $challenge->isUsable()
            && $challenge->customer_email === mb_strtolower($user->email)
            ? $challenge
            : null;
        $form = $request->session()->get('contact_verification_form_data', []);
        $form = is_array($form) ? $form : [];
        $slot = is_string($form['starts_at'] ?? null) ? $form['starts_at'] : '';
        $request->session()->flash('_old_input', array_merge($form, ['phone' => $phone]));
        $html = view('public.partials.booking-authenticated-form', [
            'contactsVerified' => $contactsVerified,
            'verification' => $verification,
            'bookingSlot' => $slot,
            'eligibility' => $eligibility,
            'bundles' => BundleDefinition::query()->where('is_active', true)->orderBy('sort_order')->orderBy('price_ron')->get(),
            'phoneAlreadyVerified' => $phoneAlreadyVerified,
            'whatsappConsent' => $whatsappConsent,
            'bookingFormData' => $form,
        ])->render();

        return response()->json([
            'status' => $eligibility['status'] ?? 'verification_required',
            'next_step' => $eligibility['status'] ?? 'contact_verification',
            'slot' => $slot,
            'locale' => app()->getLocale(),
            'message' => $message,
            'eligibility' => $eligibility,
            'html' => $html,
        ]);
    }

    public function registerForFreeBooking(Request $request, CreateNewUser $users, FreeSessionEligibilityService $eligibilityService): RedirectResponse|JsonResponse
    {
        $this->applyLocale($request);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'phone' => ['required', 'string', 'max:32', 'regex:/^\+?[0-9\s().-]{7,32}$/'],
            'english_level' => ['required', 'in:A1,A2,B1,B2,C1,C2'],
            'session_goal' => ['required', 'string', 'max:500'],
            'session_notes' => ['nullable', 'string', 'max:5000'],
            'password' => ['required', 'string', Password::default(), 'confirmed'],
            'starts_at' => ['required', 'date'],
            'accepted_policy' => ['accepted'],
            'whatsapp_service_consent' => [config('services.contact_verification.mode', 'email_and_whatsapp') === 'email_only' ? 'nullable' : 'accepted'],
        ]);

        $validated['password_confirmation'] = $request->input('password_confirmation');

        try {
            $user = $users->create($validated);
        } catch (QueryException $exception) {
            $email = mb_strtolower(trim($validated['email']));
            if (! User::query()->whereRaw('LOWER(email) = ?', [$email])->exists()) {
                throw $exception;
            }

            throw ValidationException::withMessages(['email' => __('validation.unique', ['attribute' => 'email'])]);
        }

        if (config('services.contact_verification.mode', 'email_and_whatsapp') !== 'email_only'
            && ($validated['whatsapp_service_consent'] ?? null) === '1') {
            WhatsAppContact::recordConsent($validated['phone'], 'registration');
        }

        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->put('contact_verification_form_data', [
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'english_level' => $user->english_level,
            'session_goal' => $user->session_goal,
            'notes' => $user->session_notes,
            'starts_at' => $validated['starts_at'],
            'accepted_policy' => $validated['accepted_policy'],
            'whatsapp_service_consent' => $validated['whatsapp_service_consent'] ?? null,
            'type' => 'intro',
            'return_to' => 'booking',
        ]);

        if ($request->expectsJson()) {
            return $this->authenticatedBookingStepResponse(
                $request,
                $validated['starts_at'],
                __('public.account_created_continue_booking'),
                $eligibilityService,
                201,
            );
        }

        unset($validated['password'], $validated['password_confirmation']);

        return redirect()->route('home')->withInput($validated)->with('status', __('public.account_created_continue_booking'));
    }

    public function loginForFreeBooking(Request $request, FreeSessionEligibilityService $eligibilityService): RedirectResponse|JsonResponse
    {
        $this->applyLocale($request);
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:254'],
            'password' => ['required', 'string'],
            'starts_at' => ['required', 'date'],
        ]);

        $email = mb_strtolower(trim($validated['email']));
        $existingUser = User::query()->where('email', $email)->first();

        if ($existingUser?->two_factor_secret !== null) {
            $request->session()->put('url.intended', route('home').'?'.http_build_query(['slot' => $validated['starts_at']], '', '&', PHP_QUERY_RFC3986));

            if ($request->expectsJson()) {
                return response()->json([
                    'status' => 'two_factor_required',
                    'message' => __('public.booking_two_factor_login_redirect'),
                    'redirect_url' => route('login'),
                ], 409);
            }

            return redirect()->route('login')
                ->withInput(['email' => $email])
                ->with('status', __('public.booking_two_factor_login_redirect'));
        }

        if (! Auth::attempt(['email' => $email, 'password' => $validated['password']], true)) {
            if ($request->expectsJson()) {
                return response()->json([
                    'status' => 'authentication_failed',
                    'errors' => ['email' => [__('auth.failed')]],
                    'message' => __('auth.failed'),
                ], 422);
            }

            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }

        $request->session()->regenerate();
        $request->session()->forget('contact_verification_id');
        $request->session()->put('contact_verification_form_data', [
            'name' => Auth::user()->name,
            'email' => Auth::user()->email,
            'phone' => Auth::user()->phone,
            'english_level' => Auth::user()->english_level,
            'session_goal' => Auth::user()->session_goal,
            'notes' => Auth::user()->session_notes,
            'starts_at' => $validated['starts_at'],
            'type' => 'intro',
            'return_to' => 'booking',
        ]);

        if ($request->expectsJson()) {
            return $this->authenticatedBookingStepResponse(
                $request,
                $validated['starts_at'],
                __('public.booking_signed_in_continue_booking'),
                $eligibilityService,
            );
        }

        return redirect()->route('home')->withInput(['starts_at' => $validated['starts_at']]);
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

        $validated['name'] = (string) $request->user()->name;
        $validated['email'] = (string) $request->user()->email;

        if ($validated['type'] === 'bundle') {
            abort_unless($request->hasValidSignature() && $request->query('expires') !== null && $request->query('type') === 'bundle' && (int) $request->query('entitlement') === (int) $validated['entitlement'], 403);
        }

        $startsAt = CarbonImmutable::parse($validated['starts_at'])->setTimezone(AvailabilityService::TIMEZONE);
        $availability->assertBookable($startsAt);

        $verificationChallenge = null;
        $accountContactsVerified = $request->user()->email_verified_at !== null
            && (config('services.contact_verification.mode', 'email_and_whatsapp') === 'email_only'
                || ($request->user()->phone_verified_at !== null
                    && PhoneNumber::normalize((string) $request->user()->phone) === PhoneNumber::normalize($validated['phone'])));
        if ($validated['type'] === 'intro') {
            $challengeId = $validated['verification_id'] ?? $request->session()->get('contact_verification_id');
            $candidate = is_string($challengeId) ? ContactVerificationChallenge::query()->find($challengeId) : null;
            if ($candidate && $candidate->isUsable() && $candidate->isComplete()
                && $candidate->customer_email === mb_strtolower(trim($validated['email']))
                && $candidate->customer_phone === PhoneNumber::normalize($validated['phone'])) {
                $verificationChallenge = $candidate;
            } elseif (! $accountContactsVerified) {
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
        ], $startsAt, $entitlement?->id, $validated['type'] === 'intro', ($verificationChallenge?->email_verified_at !== null) || $accountContactsVerified, ($verificationChallenge?->phone_verified_at !== null) || $accountContactsVerified, $request->user()->id);
        $bookings->confirm($booking->id);
        if ($verificationChallenge) {
            $verificationChallenge->update(['consumed_at' => now()]);
            $request->session()->forget(['contact_verification_id', 'contact_verification_form_data']);
        }

        return redirect()->route('dashboard')->with('status', __('public.booking_confirmed_dashboard'));
    }

    public function confirmed(Request $request, string $reference): View
    {
        abort_unless($request->hasValidSignature(false), 403);
        $booking = Booking::query()->with('teacher')->where('public_reference', $reference)->where('status', 'confirmed')->firstOrFail();
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

    public function purchase(Request $request, BundleDefinition $bundle): View|RedirectResponse
    {
        $this->applyLocale($request);
        if (! $request->user()) {
            return redirect()->guest(route('login'));
        }
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

        $user = $request->user();
        $contactsVerified = $user->email_verified_at !== null
            && $user->phone_verified_at !== null
            && PhoneNumber::normalize((string) $user->phone) === PhoneNumber::normalize(is_string($request->old('phone')) ? $request->old('phone') : (string) $user->phone);

        return view('public.purchase', ['bundle' => $bundle, 'checkoutReady' => $checkoutReady, 'purchaseToken' => $token, 'verification' => $verification, 'contactsVerified' => $contactsVerified]);
    }

    public function startCheckout(Request $request, BundleDefinition $bundle, StripeCheckoutService $checkout): RedirectResponse
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
        $validated['name'] = (string) $request->user()->name;
        $validated['email'] = (string) $request->user()->email;

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
            $accountContactsVerified = $request->user()->email_verified_at !== null
                && $request->user()->phone_verified_at !== null
                && PhoneNumber::normalize((string) $request->user()->phone) === PhoneNumber::normalize($validated['phone']);
            $payment = $checkout->start($bundle, [
                'name' => $validated['name'], 'email' => $validated['email'], 'phone' => $validated['phone'],
            ], $validated['purchase_token'], app()->getLocale(), $verificationChallenge, $accountContactsVerified, $request->user());
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

    public function purchaseSuccess(Request $request, StripeCheckoutService $checkout): View|RedirectResponse
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
            if ($request->user() && mb_strtolower($request->user()->email) === mb_strtolower($entitlement->customer_email)) {
                return redirect()->route('dashboard')->with('status', __('public.bundle_payment_confirmed_dashboard'));
            }
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

    public function dashboard(Request $request, ProgressChartData $progressChartData, CancellationPolicyService $cancellationPolicy, CustomerPurchasePresenter $purchasePresenter): Response
    {
        $this->applyLocale($request);
        $customer = $request->user();
        $activeView = $request->query('view', 'overview');
        if (! in_array($activeView, ['overview', 'calendar', 'progress', 'sessions', 'purchases', 'profile'], true)) {
            $activeView = 'overview';
        }
        $now = CarbonImmutable::now(AvailabilityService::TIMEZONE)->utc();
        $bookings = Booking::query()
            ->with(['teacher:id,name,teacher_photo_path,teacher_bio', 'callEvents' => fn ($query) => $query->where('provider', 'openwa')->orderByDesc('revision')])
            ->ownedBy($customer)
            ->whereIn('status', ['confirmed', 'pending'])
            ->where('starts_at', '>=', $now)
            ->orderBy('starts_at')
            ->limit(20)
            ->get();
        $usableEntitlementsQuery = fn () => BundleEntitlement::query()
            ->ownedBy($customer)
            ->where('status', 'active')
            ->where(function ($query): void {
                $query->where('credits_remaining', '>', 0)->orWhere('free_credits_remaining', '>', 0);
            })
            ->where(function ($query) use ($now): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', $now);
            });
        $remainingCredits = (int) $usableEntitlementsQuery()
            ->selectRaw('COALESCE(SUM(credits_remaining + free_credits_remaining), 0) as usable_credits')
            ->value('usable_credits');
        $activeBundleCount = $usableEntitlementsQuery()->count();
        $usableEntitlements = $usableEntitlementsQuery()
            ->orderByDesc('created_at')
            ->limit(2)
            ->get(['id', 'bundle_name_snapshot', 'credits_remaining', 'free_credits_remaining']);
        $nearestExpiringEntitlement = $usableEntitlementsQuery()
            ->whereNotNull('expires_at')
            ->orderBy('expires_at')
            ->first(['id', 'bundle_name_snapshot', 'expires_at']);
        $calendarEntitlement = $usableEntitlementsQuery()
            ->orderByDesc('created_at')
            ->first(['id']);
        $hasActiveBundle = $activeBundleCount > 0;
        $ownedBookingIds = Booking::query()->ownedBy($customer)->select('id');
        $ownedEntitlementIds = BundleEntitlement::query()->ownedBy($customer)->select('id');
        $hasUsedFreeOffer = FreeSessionClaim::query()
            ->where(function ($query) use ($customer, $ownedBookingIds, $ownedEntitlementIds): void {
                $query->whereIn('booking_id', $ownedBookingIds)
                    ->orWhereIn('bundle_entitlement_id', $ownedEntitlementIds);

                if ($customer->email_verified_at !== null && $customer->phone_verified_at !== null && $customer->phone !== null) {
                    $query->orWhere(function ($legacy) use ($customer): void {
                        $legacy->whereNull('booking_id')
                            ->whereNull('bundle_entitlement_id')
                            ->whereRaw('LOWER(customer_email) = ?', [mb_strtolower($customer->email)])
                            ->where('customer_phone', $customer->phone);
                    });
                }
            })
            ->exists();
        $calendarBookingUrl = $calendarEntitlement
            ? URL::signedRoute('booking.create', ['type' => 'bundle', 'entitlement' => $calendarEntitlement->id], now()->addDays(7))
            : (! $hasUsedFreeOffer ? route('booking.create', ['type' => 'intro']) : route('home').'#bundles');
        $metricKeys = ProgressChartData::METRIC_KEYS;
        $feedback = collect();
        $progressChart = [
            'series' => [],
            'assessment_count' => 0,
            'partial_count' => 0,
            'strongest_improvement' => null,
            'baseline_scores' => [],
        ];
        $latestProgressReport = null;
        $assessmentHistory = null;
        $assessmentSummaries = [];
        $progressReports = null;
        $progressReportSessionUrls = [];
        $selectedProgressReport = null;
        $selectedProgressReportSessionUrl = null;
        $nextStepFeedback = collect();

        if (in_array($activeView, ['overview', 'progress'], true)) {
            $progressChart = $progressChartData->buildForCustomer($customer, $metricKeys);
        }

        if ($activeView === 'progress') {
            $feedback = $progressChartData->latestEntriesForCustomer($customer, $metricKeys);
            $nextStepFeedback = $progressChartData->latestFeedbackNotesForCustomer($customer, $metricKeys);

            $latestProgressReport = ProgressReport::query()
                ->with('author:id,name')
                ->whereNotNull('shared_at')
                ->whereIn('booking_id', $ownedBookingIds)
                ->orderByDesc('shared_at')
                ->orderByDesc('id')
                ->first();

            $assessmentContent = fn ($query) => $query->where(function ($content): void {
                $content->whereNotNull('current_score')
                    ->orWhereNotNull('potential_score')
                    ->orWhere(fn ($text) => $text->whereNotNull('feedback')->where('feedback', '<>', ''));
            });

            $assessmentHistory = Booking::query()
                ->ownedBy($customer)
                ->whereHas('sessionFeedback', $assessmentContent)
                ->with(['sessionFeedback' => fn ($query) => $assessmentContent($query)->orderBy('metric_key')])
                ->orderByDesc('starts_at')
                ->orderByDesc('id')
                ->paginate(10, ['*'], 'assessments_page')
                ->withQueryString();

            foreach ($assessmentHistory as $assessmentBooking) {
                $feedbackByMetric = $assessmentBooking->sessionFeedback->keyBy('metric_key');
                $scoreCount = collect($metricKeys)
                    ->filter(fn (string $metric): bool => $feedbackByMetric->get($metric)?->current_score !== null)
                    ->count();
                $metricDetails = [];

                foreach ($metricKeys as $metric) {
                    $item = $feedbackByMetric->get($metric);
                    $metricDetails[$metric] = [
                        'baseline' => $progressChart['baseline_scores'][$metric] ?? null,
                        'current' => $item?->current_score,
                        'potential' => $item?->potential_score,
                        'feedback' => $item?->feedback,
                    ];
                }

                $assessmentSummaries[$assessmentBooking->id] = [
                    'complete' => $scoreCount === count($metricKeys),
                    'score_count' => $scoreCount,
                    'metrics' => $metricDetails,
                    'overall_feedback' => $feedbackByMetric->get('overall')?->feedback,
                    'session_url' => $this->signedBookingDetailsUrl($assessmentBooking),
                ];
            }

            $progressReports = ProgressReport::query()
                ->with(['booking:id,public_reference,starts_at,duration_minutes,status', 'author:id,name'])
                ->whereNotNull('shared_at')
                ->whereIn('booking_id', $ownedBookingIds)
                ->orderByDesc('shared_at')
                ->orderByDesc('id')
                ->paginate(10, ['*'], 'reports_page')
                ->withQueryString();

            foreach ($progressReports as $progressReport) {
                $progressReportSessionUrls[$progressReport->id] = $this->signedBookingDetailsUrl($progressReport->booking);
            }

            if ($request->filled('report')) {
                $selectedProgressReport = ProgressReport::query()
                    ->with(['booking:id,public_reference,starts_at,duration_minutes,status', 'author:id,name'])
                    ->whereKey($request->query('report'))
                    ->whereNotNull('shared_at')
                    ->whereIn('booking_id', $ownedBookingIds)
                    ->firstOrFail();

                $selectedProgressReportSessionUrl = $this->signedBookingDetailsUrl($selectedProgressReport->booking);
            }
        }

        $latestAssessedProgressReport = null;
        if (in_array($activeView, ['overview', 'profile'], true)) {
            $latestAssessedProgressReport = ProgressReport::query()
                ->whereNotNull('shared_at')
                ->whereNotNull('overall_level')
                ->whereIn('booking_id', Booking::query()->ownedBy($customer)->select('id'))
                ->orderByDesc('shared_at')
                ->orderByDesc('id')
                ->first();
        }

        $learnerProfileLevel = $latestAssessedProgressReport->overall_level ?? $customer->english_level;
        $learnerProfileLevelSource = $latestAssessedProgressReport !== null
            ? 'assessed'
            : ($customer->english_level !== null ? 'self_reported' : 'unset');

        $nextSession = $bookings->first();
        $nextSessionUrl = $nextSession instanceof Booking ? $this->signedBookingDetailsUrl($nextSession) : null;
        $progressHighlight = null;
        if ($activeView === 'overview' && $progressChart['strongest_improvement'] !== null) {
            $latestCompleteAssessment = collect($progressChart['series'])
                ->where('type', 'assessment')
                ->last();

            if ($latestCompleteAssessment !== null) {
                $progressHighlight = [
                    'metric' => __('public.progress_metric_'.$progressChart['strongest_improvement']['metric']),
                    'points' => $progressChart['strongest_improvement']['points'],
                    'date' => $latestCompleteAssessment['date'],
                ];
            }
        }

        $recentActivity = collect();
        if ($activeView === 'overview') {
            $recentBookings = Booking::query()
                ->ownedBy($customer)
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->limit(5)
                ->get(['id', 'public_reference', 'starts_at', 'duration_minutes', 'status', 'created_at']);

            foreach ($recentBookings as $recentBooking) {
                $statusKey = 'public.booking_status_'.$recentBooking->status;
                $statusLabel = __($statusKey);
                if ($statusLabel === $statusKey) {
                    $statusLabel = ucfirst(str_replace('_', ' ', $recentBooking->status));
                }

                $recentActivity->push([
                    'key' => 'booking:'.$recentBooking->id,
                    'type' => 'session',
                    'title' => __('public.dashboard_activity_session'),
                    'description' => __('public.dashboard_activity_session_description', [
                        'date' => $recentBooking->starts_at->timezone(AvailabilityService::TIMEZONE)->translatedFormat('D, j M Y'),
                        'duration' => $recentBooking->duration_minutes,
                        'status' => $statusLabel,
                    ]),
                    'occurred_at' => $recentBooking->created_at,
                    'url' => $this->signedBookingDetailsUrl($recentBooking),
                ]);
            }

            $recentPayments = Payment::query()
                ->whereIn('bundle_entitlement_id', $ownedEntitlementIds)
                ->whereNotNull('confirmed_at')
                ->with('entitlement:id,bundle_name_snapshot')
                ->orderByDesc('confirmed_at')
                ->orderByDesc('id')
                ->limit(5)
                ->get();

            foreach ($recentPayments as $recentPayment) {
                $recentActivity->push([
                    'key' => 'payment:'.$recentPayment->id,
                    'type' => 'payment',
                    'title' => __('public.dashboard_activity_payment'),
                    'description' => __('public.dashboard_activity_payment_description', [
                        'bundle' => $recentPayment->entitlement->bundle_name_snapshot ?: __('public.dashboard_purchase_bundle_name_unavailable'),
                        'amount' => number_format($recentPayment->amount_ron, 0, '.', ','),
                        'currency' => $recentPayment->currency,
                    ]),
                    'occurred_at' => $recentPayment->confirmed_at,
                    'url' => route('dashboard', ['view' => 'purchases']),
                ]);
            }

            $recentReports = ProgressReport::query()
                ->whereNotNull('shared_at')
                ->whereIn('booking_id', $ownedBookingIds)
                ->orderByDesc('shared_at')
                ->orderByDesc('id')
                ->limit(5)
                ->get(['id', 'title', 'shared_at']);

            foreach ($recentReports as $recentReport) {
                $recentActivity->push([
                    'key' => 'report:'.$recentReport->id,
                    'type' => 'report',
                    'title' => __('public.dashboard_activity_report'),
                    'description' => $recentReport->title,
                    'occurred_at' => $recentReport->shared_at,
                    'url' => route('dashboard', ['view' => 'progress', 'report' => $recentReport->id]),
                ]);
            }

            $recentActivity = $recentActivity
                ->sortByDesc(fn (array $event): int => $event['occurred_at']->getTimestamp())
                ->take(6)
                ->values();
        }

        $sessionGroup = $request->query('session_group', 'upcoming');
        if (! in_array($sessionGroup, ['upcoming', 'history', 'cancelled', 'all'], true)) {
            $sessionGroup = 'upcoming';
        }

        $sessionBookings = null;
        $sessionActions = [];
        if ($activeView === 'sessions') {
            $sessionQuery = Booking::query()
                ->ownedBy($customer)
                ->with([
                    'teacher:id,name,teacher_photo_path,teacher_bio',
                    'callEvents' => fn ($query) => $query->where('provider', 'openwa')->orderByDesc('revision'),
                ]);

            if ($sessionGroup === 'upcoming') {
                $sessionQuery->whereIn('status', ['pending', 'confirmed'])
                    ->where('starts_at', '>=', $now)
                    ->orderBy('starts_at');
            } elseif ($sessionGroup === 'history') {
                $sessionQuery->where(function ($query) use ($now): void {
                    $query->where('status', 'completed')
                        ->orWhere(function ($past) use ($now): void {
                            $past->where('status', 'confirmed')->where('starts_at', '<', $now);
                        });
                })->orderByDesc('starts_at');
            } elseif ($sessionGroup === 'cancelled') {
                $sessionQuery->whereIn('status', ['cancelled', 'expired', 'no_show', 'refund_pending'])
                    ->orderByDesc('starts_at');
            } else {
                $sessionQuery
                    ->orderByRaw('CASE WHEN starts_at >= ? THEN 0 ELSE 1 END', [$now])
                    ->orderByRaw('CASE WHEN starts_at >= ? THEN starts_at END ASC', [$now])
                    ->orderByDesc('starts_at');
            }

            $sessionBookings = $sessionQuery->paginate(10, ['*'], 'sessions_page')
                ->appends(['view' => 'sessions', 'session_group' => $sessionGroup]);

            foreach ($sessionBookings as $sessionBooking) {
                $actions = $cancellationPolicy->customerActions($sessionBooking);
                $expiresAt = $sessionBooking->starts_at->addDay();
                if ($expiresAt->lessThanOrEqualTo(CarbonImmutable::now('UTC'))) {
                    $expiresAt = CarbonImmutable::now('UTC')->addDays(7);
                }

                $sessionActions[$sessionBooking->id] = [
                    'can_cancel' => $actions['can_cancel'],
                    'can_reschedule' => $actions['can_reschedule'],
                    'manage_url' => $this->signedBookingDetailsUrl($sessionBooking),
                    'reschedule_url' => $actions['can_reschedule']
                        ? URL::signedRoute('booking.reschedule.form', ['reference' => $sessionBooking->public_reference], $expiresAt)
                        : null,
                ];
            }
        }

        $purchaseEntitlements = null;
        $paymentHistory = null;
        $creditHistory = null;
        $purchaseStatuses = [];
        $paymentStatuses = [];
        $creditReconciliation = [];

        if ($activeView === 'purchases') {
            $ownedEntitlementIds = fn () => BundleEntitlement::query()->ownedBy($customer)->select('id');

            $purchaseEntitlements = BundleEntitlement::query()
                ->ownedBy($customer)
                ->with('payments.refunds')
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->paginate(10, ['*'], 'purchases_page')
                ->withQueryString();

            foreach ($purchaseEntitlements as $purchaseEntitlement) {
                $purchaseStatuses[$purchaseEntitlement->id] = $purchasePresenter->entitlementStatus($purchaseEntitlement);
            }

            $paymentHistory = Payment::query()
                ->whereIn('bundle_entitlement_id', $ownedEntitlementIds())
                ->with(['entitlement:id,bundle_name_snapshot', 'refunds', 'disputes'])
                ->orderByRaw('CASE WHEN confirmed_at IS NULL THEN 1 ELSE 0 END')
                ->orderByDesc('confirmed_at')
                ->orderByDesc('created_at')
                ->paginate(10, ['*'], 'payments_page')
                ->withQueryString();

            foreach ($paymentHistory as $payment) {
                $paymentStatuses[$payment->id] = $purchasePresenter->paymentStatus($payment);
            }

            $creditHistory = CreditTransaction::query()
                ->whereIn('bundle_entitlement_id', $ownedEntitlementIds())
                ->with([
                    'booking:id,customer_user_id,bundle_entitlement_id,public_reference,session_goal,starts_at,status',
                    'entitlement:id,bundle_name_snapshot',
                ])
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->paginate(15, ['*'], 'credits_page')
                ->withQueryString();

            $purchaseIds = $purchaseEntitlements->getCollection()->pluck('id');
            if ($purchaseIds->isNotEmpty()) {
                $latestTransactionIds = CreditTransaction::query()
                    ->selectRaw('MAX(id)')
                    ->whereIn('bundle_entitlement_id', $purchaseIds)
                    ->groupBy('bundle_entitlement_id', 'credit_type');

                $latestTransactions = CreditTransaction::query()
                    ->whereIn('id', $latestTransactionIds)
                    ->get(['bundle_entitlement_id', 'credit_type', 'balance_after'])
                    ->groupBy('bundle_entitlement_id');

                foreach ($purchaseEntitlements as $purchaseEntitlement) {
                    $latest = $latestTransactions->get($purchaseEntitlement->id, collect())->keyBy('credit_type');
                    $paidLatest = $latest->get('paid');
                    $freeLatest = $latest->get('free');
                    $paidMatches = $paidLatest !== null
                        ? (int) $paidLatest->balance_after === $purchaseEntitlement->credits_remaining
                        : $purchaseEntitlement->credits_remaining === 0;
                    $freeMatches = $freeLatest !== null
                        ? (int) $freeLatest->balance_after === $purchaseEntitlement->free_credits_remaining
                        : $purchaseEntitlement->free_credits_remaining === 0;

                    $creditReconciliation[$purchaseEntitlement->id] = $paidMatches && $freeMatches ? 'matched' : 'legacy_gap';
                }
            }
        }

        return response()->view('dashboard', [
            'bookings' => $bookings,
            'sessionBookings' => $sessionBookings,
            'sessionActions' => $sessionActions,
            'sessionGroup' => $sessionGroup,
            'usableEntitlements' => $usableEntitlements,
            'purchaseEntitlements' => $purchaseEntitlements,
            'paymentHistory' => $paymentHistory,
            'creditHistory' => $creditHistory,
            'purchaseStatuses' => $purchaseStatuses,
            'paymentStatuses' => $paymentStatuses,
            'creditReconciliation' => $creditReconciliation,
            'remainingCredits' => $remainingCredits,
            'nextSession' => $nextSession,
            'nextSessionUrl' => $nextSessionUrl,
            'activeBundleCount' => $activeBundleCount,
            'nearestExpiringEntitlement' => $nearestExpiringEntitlement,
            'progressHighlight' => $progressHighlight,
            'recentActivity' => $recentActivity,
            'activeView' => $activeView,
            'calendarBookingUrl' => $calendarBookingUrl,
            'calendarWeekStart' => CarbonImmutable::now(AvailabilityService::TIMEZONE)->startOfWeek(CarbonImmutable::MONDAY),
            'feedback' => $feedback,
            'progressChart' => $progressChart,
            'latestProgressReport' => $latestProgressReport,
            'assessmentHistory' => $assessmentHistory,
            'assessmentSummaries' => $assessmentSummaries,
            'progressReports' => $progressReports,
            'progressReportSessionUrls' => $progressReportSessionUrls,
            'selectedProgressReport' => $selectedProgressReport,
            'selectedProgressReportSessionUrl' => $selectedProgressReportSessionUrl,
            'nextStepFeedback' => $nextStepFeedback,
            'latestAssessedProgressReport' => $latestAssessedProgressReport,
            'learnerProfileLevel' => $learnerProfileLevel,
            'learnerProfileLevelSource' => $learnerProfileLevelSource,
            'hasActiveBundle' => $hasActiveBundle,
        ])->header('Cache-Control', 'private, no-store, max-age=0')
            ->header('Referrer-Policy', 'no-referrer');
    }

    public function policy(Request $request, string $page): View
    {
        $this->applyLocale($request);
        abort_unless(in_array($page, ['privacy', 'terms'], true), 404);

        return view('public.policy', ['page' => $page]);
    }

    private function authenticatedBookingStepResponse(
        Request $request,
        string $startsAt,
        string $message,
        FreeSessionEligibilityService $eligibilityService,
        int $statusCode = 200,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $verificationId = $request->session()->get('contact_verification_id');
        $verification = is_string($verificationId)
            ? ContactVerificationChallenge::query()->whereKey($verificationId)->first()
            : null;
        if ($verification && (! $verification->isUsable() || $verification->customer_email !== mb_strtolower($user->email))) {
            $verification = null;
        }

        $formData = $request->session()->get('contact_verification_form_data', []);
        $oldPhone = $request->old('phone');
        $eligibilityPhone = $verification->customer_phone
            ?? (is_array($formData) && is_string($formData['phone'] ?? null) ? $formData['phone'] : (is_string($oldPhone) ? $oldPhone : (string) $user->phone));
        $emailOnly = config('services.contact_verification.mode', 'email_and_whatsapp') === 'email_only';
        $phoneAlreadyVerified = $user->phone_verified_at !== null
            && PhoneNumber::normalize((string) $user->phone) === PhoneNumber::normalize($eligibilityPhone);
        $contactsVerified = $user->email_verified_at !== null && ($emailOnly || $phoneAlreadyVerified);
        $whatsappConsent = WhatsAppContact::query()->where('phone', PhoneNumber::normalize($eligibilityPhone))
            ->first()?->canReceiveServiceMessages() === true;
        $eligibility = $contactsVerified ? $eligibilityService->check((string) $user->email, $eligibilityPhone) : null;
        $html = view('public.partials.booking-authenticated-form', [
            'contactsVerified' => $contactsVerified,
            'verification' => $verification,
            'eligibility' => $eligibility,
            'bookingSlot' => $startsAt,
            'phoneAlreadyVerified' => $phoneAlreadyVerified,
            'whatsappConsent' => $whatsappConsent,
            'bookingFormData' => $formData,
        ])->render();

        return response()->json([
            'status' => 'authenticated',
            'next_step' => $eligibility['status'] ?? 'contact_verification',
            'slot' => $startsAt,
            'locale' => app()->getLocale(),
            'message' => $message,
            'html' => $html,
        ], $statusCode);
    }

    private function signedBookingDetailsUrl(Booking $booking): string
    {
        $expiresAt = $booking->starts_at->addDay();
        if ($expiresAt->lessThanOrEqualTo(CarbonImmutable::now('UTC'))) {
            $expiresAt = CarbonImmutable::now('UTC')->addDays(7);
        }

        return URL::signedRoute('booking.manage', ['reference' => $booking->public_reference], $expiresAt);
    }

    private function applyLocale(Request $request): void
    {
        $locale = $request->input('locale');
        if (is_string($locale) && in_array($locale, ['ro', 'en'], true)) {
            $request->session()->put('locale', $locale);
        }

        app()->setLocale($request->session()->get('locale', 'ro'));
    }
}
