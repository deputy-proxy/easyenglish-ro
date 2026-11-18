@php
    $formatMoney = fn ($amount) => number_format((float) $amount, 2, '.', ',').' RON';
    $statusStyles = [
        'confirmed' => 'bg-emerald-500/10 text-emerald-300 ring-1 ring-emerald-500/20',
        'succeeded' => 'bg-emerald-500/10 text-emerald-300 ring-1 ring-emerald-500/20',
        'pending' => 'bg-amber-500/10 text-amber-300 ring-1 ring-amber-500/20',
        'refund_pending' => 'bg-amber-500/10 text-amber-300 ring-1 ring-amber-500/20',
        'failed' => 'bg-rose-500/10 text-rose-300 ring-1 ring-rose-500/20',
        'refunded' => 'bg-sky-500/10 text-sky-300 ring-1 ring-sky-500/20',
    ];
@endphp

<x-filament-panels::page>
    <div class="space-y-6">
        <div class="flex flex-wrap items-center justify-end gap-4">
            <div class="inline-flex items-center gap-2 rounded-lg border border-white/10 bg-white/[0.03] px-4 py-2 text-sm text-gray-200">
                <x-filament::icon icon="heroicon-o-calendar-days" class="h-5 w-5 text-amber-400" />
                {{ now('Europe/Bucharest')->translatedFormat('D, j M Y') }}
            </div>
        </div>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 2xl:grid-cols-4">
            @foreach ([
                ['label' => 'Active customers', 'value' => number_format($activeCustomers), 'icon' => 'heroicon-o-users', 'hint' => 'With a session in the last 90 days', 'tone' => 'text-amber-400'],
                ['label' => 'Sessions today', 'value' => number_format($todayBookings->count()), 'icon' => 'heroicon-o-calendar-days', 'hint' => $todayBookings->where('status', 'confirmed')->count().' confirmed', 'tone' => 'text-amber-400'],
                ['label' => 'Revenue this month', 'value' => $formatMoney($monthlyRevenue), 'icon' => 'heroicon-o-credit-card', 'hint' => 'From successful payments', 'tone' => 'text-amber-400'],
                ['label' => 'Pending refunds', 'value' => number_format($pendingRefunds), 'icon' => 'heroicon-o-arrow-uturn-left', 'hint' => $pendingRefunds > 0 ? 'Needs attention' : 'Nothing waiting for review', 'tone' => $pendingRefunds > 0 ? 'text-amber-300' : 'text-emerald-300'],
            ] as $metric)
                <div class="rounded-xl border border-white/10 bg-white/[0.035] p-5 shadow-sm">
                    <div class="flex items-start justify-between gap-4">
                        <div class="min-w-0">
                            <p class="text-sm text-gray-400">{{ $metric['label'] }}</p>
                            <p class="mt-2 truncate text-2xl font-semibold tracking-tight text-white">{{ $metric['value'] }}</p>
                            <p class="mt-2 text-xs {{ $metric['tone'] }}">{{ $metric['hint'] }}</p>
                        </div>
                        <div class="rounded-xl bg-amber-400/10 p-3 text-amber-400">
                            <x-filament::icon :icon="$metric['icon']" class="h-6 w-6" />
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="grid grid-cols-1 gap-4 2xl:grid-cols-12">
            <section class="overflow-hidden rounded-xl border border-white/10 bg-white/[0.035] 2xl:col-span-7">
                <div class="flex items-center justify-between border-b border-white/10 px-5 py-4">
                    <div class="flex items-center gap-3">
                        <x-filament::icon icon="heroicon-o-calendar-days" class="h-5 w-5 text-amber-400" />
                        <h2 class="font-semibold text-white">Today's sessions</h2>
                    </div>
                    <a href="{{ $bookingsUrl }}" class="text-sm font-medium text-amber-400 hover:text-amber-300">View bookings ↗</a>
                </div>
                <div class="divide-y divide-white/10">
                    @forelse ($todayBookings as $booking)
                        <div class="grid grid-cols-[4.25rem_minmax(0,1fr)_auto] items-center gap-3 px-5 py-3">
                            <div class="rounded-lg bg-white/[0.06] px-2 py-2 text-center text-sm font-semibold tabular-nums text-white">
                                {{ $booking->starts_at->timezone('Europe/Bucharest')->format('H:i') }}
                            </div>
                            <div class="min-w-0">
                                <p class="truncate text-sm font-medium text-white">{{ $booking->customer_name }}</p>
                                <p class="mt-0.5 truncate text-xs text-gray-400">
                                    {{ $booking->duration_minutes }} min · Conversation (WhatsApp)
                                    <span class="text-gray-600">·</span>
                                    {{ $booking->teacher?->name ?? 'Teacher not assigned' }}
                                </p>
                            </div>
                            <span class="whitespace-nowrap rounded-md px-2.5 py-1 text-xs font-medium {{ $statusStyles[$booking->status] ?? 'bg-white/5 text-gray-300 ring-1 ring-white/10' }}">
                                {{ str($booking->status)->replace('_', ' ')->title() }}
                            </span>
                        </div>
                    @empty
                        <div class="px-5 py-10 text-center">
                            <p class="text-sm text-gray-300">No sessions scheduled for today.</p>
                            <p class="mt-1 text-xs text-gray-500">New bookings will appear here automatically.</p>
                        </div>
                    @endforelse
                </div>
            </section>

            <section class="rounded-xl border border-white/10 bg-white/[0.035] p-5 2xl:col-span-5">
                <div class="flex items-center justify-between gap-3">
                    <div class="flex items-center gap-3">
                        <x-filament::icon icon="heroicon-o-chart-bar" class="h-5 w-5 text-amber-400" />
                        <h2 class="font-semibold text-white">Revenue overview</h2>
                    </div>
                    <span class="text-xs text-gray-500">Last 7 days</span>
                </div>
                <div class="mt-6 flex h-52 items-end justify-between gap-3 border-b border-white/10 px-1">
                    @foreach ($revenueByDay as $day)
                        <div class="flex h-full min-w-0 flex-1 flex-col items-center justify-end gap-2">
                            <span class="text-[10px] tabular-nums text-gray-500">{{ $day['amount'] > 0 ? number_format($day['amount']) : '–' }}</span>
                            <div class="w-full max-w-10 rounded-t-md bg-gradient-to-t from-amber-600 to-amber-300" style="height: {{ $day['height'] }}%"></div>
                            <span class="text-xs text-gray-400">{{ $day['label'] }}</span>
                        </div>
                    @endforeach
                </div>
                <p class="mt-3 text-right text-[11px] text-gray-500">Successful payments · RON</p>
            </section>
        </div>

        <div class="grid grid-cols-1 gap-4 2xl:grid-cols-12">
            <section class="overflow-hidden rounded-xl border border-white/10 bg-white/[0.035] 2xl:col-span-7">
                <div class="flex items-center justify-between border-b border-white/10 px-5 py-4">
                    <div class="flex items-center gap-3">
                        <x-filament::icon icon="heroicon-o-credit-card" class="h-5 w-5 text-amber-400" />
                        <h2 class="font-semibold text-white">Recent payments</h2>
                    </div>
                    <a href="{{ $paymentsUrl }}" class="text-sm font-medium text-amber-400 hover:text-amber-300">View all payments ↗</a>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[34rem] text-left text-sm">
                        <thead class="border-b border-white/10 text-xs text-gray-400">
                            <tr>
                                <th class="px-5 py-3 font-medium">Customer</th>
                                <th class="px-3 py-3 font-medium">Date</th>
                                <th class="px-3 py-3 font-medium">Amount</th>
                                <th class="px-5 py-3 font-medium">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/10">
                            @forelse ($recentPayments as $payment)
                                <tr>
                                    <td class="px-5 py-3">
                                        <p class="font-medium text-white">{{ $payment->entitlement?->customer_name ?? 'Unknown customer' }}</p>
                                        <p class="mt-0.5 text-xs text-gray-500">{{ $payment->entitlement?->customer_email ?? '—' }}</p>
                                    </td>
                                    <td class="whitespace-nowrap px-3 py-3 text-gray-400">{{ $payment->created_at?->timezone('Europe/Bucharest')->format('M j, H:i') ?? '—' }}</td>
                                    <td class="whitespace-nowrap px-3 py-3 font-medium text-white">{{ $formatMoney($payment->amount_ron) }}</td>
                                    <td class="px-5 py-3">
                                        <span class="rounded-md px-2 py-1 text-xs font-medium {{ $statusStyles[$payment->status] ?? 'bg-white/5 text-gray-300 ring-1 ring-white/10' }}">
                                            {{ str($payment->status)->replace('_', ' ')->title() }}
                                        </span>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="px-5 py-8 text-center text-sm text-gray-500">No payments recorded yet.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="overflow-hidden rounded-xl border border-white/10 bg-white/[0.035] 2xl:col-span-5">
                <div class="flex items-center gap-3 border-b border-white/10 px-5 py-4">
                    <x-filament::icon icon="heroicon-o-exclamation-triangle" class="h-5 w-5 text-amber-400" />
                    <h2 class="font-semibold text-white">Needs attention</h2>
                </div>
                <div class="divide-y divide-white/10">
                    <a href="{{ $refundsUrl }}" class="flex items-center justify-between gap-3 px-5 py-4 transition hover:bg-white/[0.025]">
                        <div class="flex min-w-0 items-center gap-3">
                            <span class="rounded-lg bg-amber-400/10 p-2.5 text-amber-400"><x-filament::icon icon="heroicon-o-arrow-uturn-left" class="h-5 w-5" /></span>
                            <div class="min-w-0">
                                <p class="text-sm font-semibold text-white">{{ $pendingRefunds }} refund requests awaiting review</p>
                                <p class="mt-1 text-xs text-gray-400">Review and process refund requests.</p>
                            </div>
                        </div>
                        <span class="whitespace-nowrap text-sm font-medium text-amber-400">Review →</span>
                    </a>
                    <a href="{{ $progressReportsUrl }}" class="flex items-center justify-between gap-3 px-5 py-4 transition hover:bg-white/[0.025]">
                        <div class="flex min-w-0 items-center gap-3">
                            <span class="rounded-lg bg-amber-400/10 p-2.5 text-amber-400"><x-filament::icon icon="heroicon-o-chart-bar" class="h-5 w-5" /></span>
                            <div class="min-w-0">
                                <p class="text-sm font-semibold text-white">{{ $reportsDue }} progress reports to complete</p>
                                <p class="mt-1 text-xs text-gray-400">Past confirmed sessions without a report.</p>
                            </div>
                        </div>
                        <span class="whitespace-nowrap text-sm font-medium text-amber-400">Update →</span>
                    </a>
                    <a href="{{ $customersUrl }}" class="flex items-center justify-between gap-3 px-5 py-4 transition hover:bg-white/[0.025]">
                        <div class="flex min-w-0 items-center gap-3">
                            <span class="rounded-lg bg-amber-400/10 p-2.5 text-amber-400"><x-filament::icon icon="heroicon-o-user" class="h-5 w-5" /></span>
                            <div class="min-w-0">
                                <p class="text-sm font-semibold text-white">{{ $unverifiedCustomers }} customer accounts need verification</p>
                                <p class="mt-1 text-xs text-gray-400">Accounts with unverified email or phone number.</p>
                            </div>
                        </div>
                        <span class="whitespace-nowrap text-sm font-medium text-amber-400">Verify →</span>
                    </a>
                </div>
            </section>
        </div>
    </div>
</x-filament-panels::page>
