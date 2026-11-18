<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Bookings\BookingResource;
use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\Payments\PaymentResource;
use App\Filament\Resources\ProgressReports\ProgressReportResource;
use App\Filament\Resources\Refunds\RefundResource;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\PaymentRefund;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Pages\Dashboard as BaseDashboard;
use Illuminate\Database\Eloquent\Builder;

class Dashboard extends BaseDashboard
{
    protected static string $view = 'filament.pages.dashboard';

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $timezone = 'Europe/Bucharest';
        $today = CarbonImmutable::now($timezone);
        $dayStart = $today->startOfDay()->utc();
        $dayEnd = $today->endOfDay()->utc();

        $activeCustomers = User::query()
            ->where('is_staff', false)
            ->where('is_teacher', false)
            ->whereHas('customerBookings', fn (Builder $query): Builder => $query
                ->where('starts_at', '>=', $today->subDays(90)->utc())
                ->whereNotIn('status', ['cancelled', 'expired']))
            ->count();

        $todayBookings = Booking::query()
            ->with('teacher:id,name')
            ->whereBetween('starts_at', [$dayStart, $dayEnd])
            ->whereIn('status', ['confirmed', 'pending', 'refund_pending'])
            ->orderBy('starts_at')
            ->get();

        $monthStart = $today->startOfMonth()->utc();
        $monthEnd = $today->endOfMonth()->utc();

        $monthlyRevenue = (int) Payment::query()
            ->where('status', 'succeeded')
            ->whereBetween('confirmed_at', [$monthStart, $monthEnd])
            ->sum('amount_ron');

        $pendingRefunds = PaymentRefund::query()->where('status', 'pending')->count();

        $recentPayments = Payment::query()
            ->with('entitlement:id,customer_name,customer_email')
            ->latest()
            ->limit(4)
            ->get();

        $chartStart = $today->subDays(6)->startOfDay();
        $chartPayments = Payment::query()
            ->where('status', 'succeeded')
            ->whereBetween('confirmed_at', [$chartStart->utc(), $today->endOfDay()->utc()])
            ->get(['amount_ron', 'confirmed_at'])
            ->groupBy(fn (Payment $payment): string => $payment->confirmed_at
                ? $payment->confirmed_at->timezone($timezone)->format('Y-m-d')
                : '');

        $revenueByDay = collect(range(6, 0))->map(function (int $daysAgo) use ($today, $chartPayments): array {
            $date = $today->subDays($daysAgo);
            $amount = (int) ($chartPayments->get($date->format('Y-m-d'))?->sum('amount_ron') ?? 0);

            return [
                'label' => $date->format('D'),
                'amount' => $amount,
            ];
        });
        $maxDailyRevenue = max(1, (int) $revenueByDay->max('amount'));
        $revenueByDay = $revenueByDay->map(fn (array $day): array => [
            ...$day,
            'height' => max(4, (int) round(($day['amount'] / $maxDailyRevenue) * 100)),
        ]);

        $reportsDue = Booking::query()
            ->where('status', 'confirmed')
            ->where('ends_at', '<', now('UTC'))
            ->whereNotExists(fn ($query) => $query->selectRaw('1')
                ->from('progress_reports')
                ->whereColumn('progress_reports.booking_id', 'bookings.id'))
            ->count();

        $unverifiedCustomers = User::query()
            ->where('is_staff', false)
            ->where('is_teacher', false)
            ->where(fn (Builder $query): Builder => $query
                ->whereNull('email_verified_at')
                ->orWhereNull('phone_verified_at'))
            ->count();

        return [
            'activeCustomers' => $activeCustomers,
            'todayBookings' => $todayBookings,
            'monthlyRevenue' => $monthlyRevenue,
            'pendingRefunds' => $pendingRefunds,
            'recentPayments' => $recentPayments,
            'revenueByDay' => $revenueByDay,
            'reportsDue' => $reportsDue,
            'unverifiedCustomers' => $unverifiedCustomers,
            'customersUrl' => CustomerResource::getUrl('index'),
            'bookingsUrl' => BookingResource::getUrl('index'),
            'paymentsUrl' => PaymentResource::getUrl('index'),
            'refundsUrl' => RefundResource::getUrl('index'),
            'progressReportsUrl' => ProgressReportResource::getUrl('index'),
        ];
    }

    public function getTitle(): string
    {
        return 'Dashboard';
    }

    public function getSubheading(): string
    {
        return 'Your EasyEnglish overview for today.';
    }
}
