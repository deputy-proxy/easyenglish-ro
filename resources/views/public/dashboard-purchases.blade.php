<section class="space-y-8">
    <header class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <h2 class="text-xl font-semibold text-zinc-900 dark:text-white">{{ __('public.dashboard_purchases') }}</h2>
            <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-300">{{ __('public.dashboard_purchases_help') }}</p>
        </div>
        <a href="{{ route('home') }}#bundles" class="inline-flex min-h-10 items-center rounded-full bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary-hover">{{ __('public.dashboard_purchase_more') }}</a>
    </header>

    <section class="space-y-4">
        <div>
            <h3 class="text-lg font-semibold text-zinc-900 dark:text-white">{{ __('public.dashboard_purchase_history') }}</h3>
            <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-300">{{ __('public.dashboard_purchase_history_help') }}</p>
        </div>

        @if ($purchaseEntitlements->isEmpty())
            <div class="rounded-2xl border border-dashed border-zinc-300 bg-white p-8 text-center dark:border-zinc-700 dark:bg-zinc-900">
                <h4 class="font-semibold text-zinc-900 dark:text-white">{{ __('public.dashboard_purchase_empty_title') }}</h4>
                <p class="mx-auto mt-2 max-w-xl text-sm text-zinc-600 dark:text-zinc-300">{{ __('public.dashboard_purchase_empty_text') }}</p>
                <a href="{{ route('home') }}#bundles" class="mt-4 inline-flex min-h-10 items-center rounded-full bg-primary px-4 py-2 text-sm font-semibold text-white">{{ __('public.dashboard_purchase_more') }}</a>
            </div>
        @else
            <div class="space-y-4">
                @foreach ($purchaseEntitlements as $purchase)
                    @php
                        $purchaseStatus = $purchaseStatuses[$purchase->id] ?? $purchase->status;
                        $purchaseRemaining = $purchase->credits_remaining + $purchase->free_credits_remaining;
                        $purchaseStatusLabel = __('public.dashboard_purchase_status_'.$purchaseStatus);
                        $purchaseStatusLabel = $purchaseStatusLabel === 'public.dashboard_purchase_status_'.$purchaseStatus ? ucfirst(str_replace('_', ' ', $purchaseStatus)) : $purchaseStatusLabel;
                    @endphp
                    <article class="dashboard-panel">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                            <div class="min-w-0">
                                <p class="text-xs font-semibold uppercase tracking-wide text-emerald-700 dark:text-emerald-400">{{ __('public.dashboard_purchase_date') }} · {{ $purchase->created_at->timezone('Europe/Bucharest')->format('d.m.Y H:i') }}</p>
                                <h4 class="mt-1 text-lg font-semibold text-zinc-900 dark:text-white">{{ $purchase->bundle_name_snapshot ?: __('public.dashboard_purchase_bundle_name_unavailable') }}</h4>
                                @if ($purchase->bundle_description_snapshot)
                                    <p class="mt-1 text-sm leading-5 text-zinc-600 dark:text-zinc-300">{{ $purchase->bundle_description_snapshot }}</p>
                                @endif
                            </div>
                            <span class="w-fit rounded-full bg-zinc-100 px-3 py-1 text-xs font-semibold text-zinc-700 dark:bg-zinc-800 dark:text-zinc-200">{{ $purchaseStatusLabel }}</span>
                        </div>

                        <dl class="mt-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                            <div>
                                <dt class="text-xs text-zinc-500">{{ __('public.dashboard_purchase_amount_paid') }}</dt>
                                <dd class="mt-1 font-semibold text-zinc-900 dark:text-white">{{ number_format($purchase->paid_amount_ron, 0, ',', '.') }} {{ $purchase->currency }}</dd>
                                <p class="mt-1 text-xs text-zinc-500">{{ __('public.dashboard_purchase_tax_inclusive') }}</p>
                            </div>
                            <div>
                                <dt class="text-xs text-zinc-500">{{ __('public.dashboard_purchase_paid_credits') }}</dt>
                                <dd class="mt-1 font-semibold text-zinc-900 dark:text-white">{{ $purchase->paid_credits }}</dd>
                                <p class="mt-1 text-xs text-zinc-500">{{ __('public.dashboard_purchase_paid_credits_remaining') }}: {{ $purchase->credits_remaining }}</p>
                            </div>
                            <div>
                                <dt class="text-xs text-zinc-500">{{ __('public.dashboard_purchase_free_credits_included') }}</dt>
                                <dd class="mt-1 font-semibold text-zinc-900 dark:text-white">{{ $purchase->included_free_credits_snapshot }}</dd>
                                <p class="mt-1 text-xs text-zinc-500">{{ __('public.dashboard_purchase_free_credits_granted') }}: {{ $purchase->free_credits_granted }} · {{ __('public.dashboard_purchase_free_credits_remaining') }}: {{ $purchase->free_credits_remaining }}</p>
                            </div>
                            <div>
                                <dt class="text-xs text-zinc-500">{{ __('public.dashboard_purchase_credits_total_remaining') }}</dt>
                                <dd class="mt-1 font-semibold text-zinc-900 dark:text-white">{{ $purchaseRemaining }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs text-zinc-500">{{ __('public.dashboard_purchase_activation_date') }}</dt>
                                <dd class="mt-1 text-sm font-medium text-zinc-900 dark:text-white">{{ $purchase->activated_at?->timezone('Europe/Bucharest')->format('d.m.Y H:i') ?? __('public.dashboard_purchase_not_activated') }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs text-zinc-500">{{ __('public.dashboard_purchase_expiry_date') }}</dt>
                                <dd class="mt-1 text-sm font-medium text-zinc-900 dark:text-white">{{ $purchase->expires_at?->timezone('Europe/Bucharest')->format('d.m.Y') ?? __('public.dashboard_purchase_no_expiry') }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs text-zinc-500">{{ __('public.dashboard_purchase_entitlement_id') }}</dt>
                                <dd class="mt-1 text-sm font-medium text-zinc-900 dark:text-white">BND-{{ str_pad((string) $purchase->id, 6, '0', STR_PAD_LEFT) }}</dd>
                            </div>
                        </dl>

                        @if ($purchase->bundle_snapshot_source === 'legacy_backfill')
                            <p class="mt-4 rounded-lg bg-amber-50 p-3 text-xs leading-5 text-amber-900 dark:bg-amber-950 dark:text-amber-100">{{ __('public.dashboard_purchase_legacy_snapshot_note') }}</p>
                        @endif

                        @if (isset($creditReconciliation[$purchase->id]) && $creditReconciliation[$purchase->id] === 'legacy_gap')
                            <p class="mt-4 rounded-lg bg-amber-50 p-3 text-xs leading-5 text-amber-900 dark:bg-amber-950 dark:text-amber-100">{{ __('public.dashboard_purchase_credit_ledger_gap') }}</p>
                        @else
                            <p class="mt-4 text-xs text-emerald-800 dark:text-emerald-300">{{ __('public.dashboard_purchase_credit_ledger_matched') }}</p>
                        @endif
                    </article>
                @endforeach
            </div>
            <div>{{ $purchaseEntitlements->links() }}</div>
        @endif
    </section>

    <section class="space-y-4">
        <div>
            <h3 class="text-lg font-semibold text-zinc-900 dark:text-white">{{ __('public.dashboard_payment_history') }}</h3>
            <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-300">{{ __('public.dashboard_payment_history_help') }}</p>
        </div>
        @forelse ($paymentHistory as $payment)
            @php
                $paymentStatus = $paymentStatuses[$payment->id] ?? $payment->status;
                $paymentStatusLabel = __('public.payment_status_'.$paymentStatus);
                $paymentStatusLabel = $paymentStatusLabel === 'public.payment_status_'.$paymentStatus ? ucfirst(str_replace('_', ' ', $paymentStatus)) : $paymentStatusLabel;
            @endphp
            <article class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-zinc-500">PAY-{{ str_pad((string) $payment->id, 6, '0', STR_PAD_LEFT) }}</p>
                        <h4 class="mt-1 font-semibold text-zinc-900 dark:text-white">{{ $payment->entitlement?->bundle_name_snapshot ?: __('public.dashboard_purchase_bundle_name_unavailable') }}</h4>
                        <p class="mt-1 text-xs text-zinc-500">{{ $payment->confirmed_at ? __('public.dashboard_payment_paid_at') : __('public.dashboard_payment_attempted_at') }} · {{ ($payment->confirmed_at ?? $payment->created_at)->timezone('Europe/Bucharest')->format('d.m.Y H:i') }}</p>
                    </div>
                    <div class="sm:text-right">
                        <p class="font-semibold text-zinc-900 dark:text-white">{{ number_format($payment->amount_ron, 0, ',', '.') }} {{ $payment->currency }}</p>
                        <span class="mt-1 inline-flex rounded-full bg-zinc-100 px-3 py-1 text-xs font-semibold text-zinc-700 dark:bg-zinc-800 dark:text-zinc-200">{{ $paymentStatusLabel }}</span>
                    </div>
                </div>

                @if ($payment->refunded_amount_ron > 0)
                    <p class="mt-3 text-sm text-zinc-600 dark:text-zinc-300">{{ __('public.dashboard_payment_refunded_amount') }}: {{ number_format($payment->refunded_amount_ron, 0, ',', '.') }} {{ $payment->currency }}</p>
                @endif

                @if ($payment->disputes->isNotEmpty())
                    <div class="mt-3 space-y-2 border-t border-zinc-100 pt-3 dark:border-zinc-800">
                        <p class="text-xs font-semibold text-zinc-500">{{ __('public.dashboard_payment_disputes') }}</p>
                        @foreach ($payment->disputes as $dispute)
                            @php
                                $disputeStatusKey = 'public.dispute_status_'.$dispute->status;
                                $disputeStatusLabel = __($disputeStatusKey) !== $disputeStatusKey ? __($disputeStatusKey) : ucfirst(str_replace('_', ' ', $dispute->status));
                            @endphp
                            <div class="flex flex-wrap items-center justify-between gap-2 text-sm">
                                <span class="text-zinc-700 dark:text-zinc-200">DSP-{{ str_pad((string) $dispute->id, 6, '0', STR_PAD_LEFT) }} · {{ $disputeStatusLabel }}</span>
                                <span class="font-medium text-zinc-900 dark:text-white">{{ number_format($dispute->amount_minor / 100, 2, ',', '.') }} {{ $dispute->currency }}</span>
                            </div>
                            @if ($dispute->evidence_due_at)
                                <p class="text-xs text-zinc-500">{{ __('public.dashboard_dispute_evidence_due') }}: {{ $dispute->evidence_due_at->timezone('Europe/Bucharest')->format('d.m.Y H:i') }}</p>
                            @endif
                        @endforeach
                    </div>
                @endif

                @if ($payment->refunds->isNotEmpty())
                    <div class="mt-3 space-y-2 border-t border-zinc-100 pt-3 dark:border-zinc-800">
                        <p class="text-xs font-semibold text-zinc-500">{{ __('public.dashboard_payment_refunds') }}</p>
                        @foreach ($payment->refunds as $refund)
                            <div class="flex flex-wrap items-center justify-between gap-2 text-sm">
                                <span class="text-zinc-700 dark:text-zinc-200">REF-{{ str_pad((string) $refund->id, 6, '0', STR_PAD_LEFT) }} · {{ __('public.refund_status_'.$refund->status) }}</span>
                                <span class="font-medium text-zinc-900 dark:text-white">{{ number_format($refund->amount_ron, 0, ',', '.') }} {{ $refund->currency }} · {{ ($refund->confirmed_at ?? $refund->created_at)->timezone('Europe/Bucharest')->format('d.m.Y') }}</span>
                            </div>
                        @endforeach
                    </div>
                @endif

                <p class="mt-3 text-xs text-zinc-500">{{ __('public.dashboard_receipt_unavailable') }}</p>
            </article>
        @empty
            <p class="rounded-xl border border-dashed border-zinc-300 p-5 text-sm text-zinc-600 dark:border-zinc-700 dark:text-zinc-300">{{ __('public.dashboard_payment_empty') }}</p>
        @endforelse
        @if ($paymentHistory->hasPages())
            <div>{{ $paymentHistory->links() }}</div>
        @endif
    </section>

    <section class="space-y-4">
        <div>
            <h3 class="text-lg font-semibold text-zinc-900 dark:text-white">{{ __('public.dashboard_credit_activity') }}</h3>
            <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-300">{{ __('public.dashboard_credit_activity_help') }}</p>
        </div>
        @forelse ($creditHistory as $transaction)
            @php
                $movementKey = 'public.credit_transaction_'.$transaction->type;
                $movementLabel = __($movementKey) !== $movementKey ? __($movementKey) : ucfirst(str_replace('_', ' ', $transaction->type));
                $quantity = (int) $transaction->quantity;
                $quantityLabel = $quantity > 0 ? '+'.$quantity : (string) $quantity;
                $booking = $transaction->booking;
                $bookingUrl = null;
                if ($booking && $booking->bundle_entitlement_id === $transaction->bundle_entitlement_id
                    && ($booking->customer_user_id === null || $booking->customer_user_id === auth()->id())) {
                    $bookingExpiresAt = $booking->starts_at->addDay();
                    if ($bookingExpiresAt->lessThanOrEqualTo(now())) {
                        $bookingExpiresAt = now()->addDays(7);
                    }
                    $bookingUrl = URL::signedRoute('booking.manage', ['reference' => $booking->public_reference], $bookingExpiresAt);
                }
            @endphp
            <article class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <p class="font-semibold text-zinc-900 dark:text-white">{{ $movementLabel }}</p>
                        <p class="mt-1 text-xs text-zinc-500">{{ $transaction->created_at->timezone('Europe/Bucharest')->format('d.m.Y H:i') }} · {{ __('public.dashboard_credit_type_'.$transaction->credit_type) }}</p>
                        @if ($bookingUrl)
                            <a href="{{ $bookingUrl }}" class="mt-2 inline-flex text-xs font-semibold text-primary-strong hover:underline dark:text-blue-300">{{ __('public.dashboard_credit_view_booking') }} · {{ $booking->session_goal }}</a>
                        @endif
                    </div>
                    <div class="sm:text-right">
                        <p class="text-lg font-semibold text-zinc-900 dark:text-white">{{ $quantityLabel }}</p>
                        <p class="mt-1 text-xs text-zinc-500">{{ __('public.dashboard_credit_balance_after') }}: {{ $transaction->balance_after }}</p>
                    </div>
                </div>
            </article>
        @empty
            <p class="rounded-xl border border-dashed border-zinc-300 p-5 text-sm text-zinc-600 dark:border-zinc-700 dark:text-zinc-300">{{ __('public.dashboard_credit_empty') }}</p>
        @endforelse
        @if ($creditHistory->hasPages())
            <div>{{ $creditHistory->links() }}</div>
        @endif
    </section>

    <aside class="rounded-xl border border-zinc-200 bg-zinc-50 p-4 text-xs leading-5 text-zinc-600 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-300">
        {{ __('public.dashboard_chargeback_tracking_note') }}
    </aside>
</section>
