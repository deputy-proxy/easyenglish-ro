<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\BundleDefinition;
use App\Models\BundleEntitlement;
use App\Services\Booking\AvailabilityService;
use App\Services\Booking\BookingService;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
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
        } catch (\Throwable) {
            $weekStart = CarbonImmutable::now(AvailabilityService::TIMEZONE)->startOfWeek(CarbonImmutable::MONDAY);
        }

        return view('public.booking', [
            'type' => $type,
            'entitlement' => $entitlement,
            'weekStart' => $weekStart,
            'policyVersion' => config('easyenglish.policy_version'),
            'formAction' => $type === 'bundle'
                ? URL::signedRoute('booking.submit', ['type' => 'bundle', 'entitlement' => $entitlement->id], now()->addMinutes(30), false)
                : route('booking.submit'),
        ]);
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
            'entitlement' => ['nullable', 'integer', 'required_if:type,bundle'],
        ]);

        if ($validated['type'] === 'bundle') {
            abort_unless($request->hasValidSignature(false) && $request->query('expires') !== null && $request->query('type') === 'bundle' && (int) $request->query('entitlement') === (int) $validated['entitlement'], 403);
        }

        $startsAt = CarbonImmutable::parse($validated['starts_at'])->setTimezone(AvailabilityService::TIMEZONE);
        $availability->assertBookable($startsAt);

        if ($validated['type'] === 'intro') {
            // Do not bypass verified-email-and-phone checks while delivery is still a launch dependency.
            throw ValidationException::withMessages(['verification' => __('public.verification_required')]);
        }

        $entitlement = BundleEntitlement::query()->with('definition')->findOrFail((int) $validated['entitlement']);
        if ($entitlement->status !== 'active' || $entitlement->expires_at === null || $entitlement->expires_at->lessThanOrEqualTo($startsAt->utc())) {
            throw ValidationException::withMessages(['entitlement' => __('public.no_bundle_credits')]);
        }

        $booking = $bookings->holdSlot([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'],
            'english_level' => $validated['english_level'],
            'session_goal' => $validated['session_goal'],
            'notes' => $validated['notes'] ?? null,
            'accepted_policy_version' => config('easyenglish.policy_version'),
        ], $startsAt, $entitlement->id);
        $bookings->confirm($booking->id);

        return redirect()->to(URL::signedRoute('booking.confirmed', ['reference' => $booking->public_reference], null, false));
    }

    public function confirmed(Request $request, string $reference): View
    {
        abort_unless($request->hasValidSignature(false), 403);
        $booking = Booking::query()->where('public_reference', $reference)->where('status', 'confirmed')->firstOrFail();
        $this->applyLocale($request);

        return view('public.confirmed', ['booking' => $booking]);
    }

    public function purchase(Request $request, BundleDefinition $bundle): View
    {
        $this->applyLocale($request);
        abort_unless($bundle->is_active, 404);

        return view('public.purchase', ['bundle' => $bundle]);
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
