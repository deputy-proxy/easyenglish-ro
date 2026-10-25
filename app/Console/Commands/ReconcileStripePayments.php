<?php

namespace App\Console\Commands;

use App\Models\Payment;
use App\Models\PaymentRefund;
use App\Services\Payments\StripeCheckoutService;
use App\Services\Payments\StripeRefundService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ReconcileStripePayments extends Command
{
    protected $signature = 'stripe:reconcile {--limit=100 : Maximum pending payments to inspect}';

    protected $description = 'Reconcile pending Stripe Checkout sessions and recover ambiguous provider responses';

    public function handle(StripeCheckoutService $checkout, StripeRefundService $refunds): int
    {
        if (! is_string(config('services.stripe.secret')) || config('services.stripe.secret') === '') {
            $this->warn('Stripe is not configured; reconciliation was skipped.');

            return self::SUCCESS;
        }

        $limit = max(1, min(1000, (int) $this->option('limit')));
        $processed = 0;
        $failures = 0;

        Payment::query()->where('status', 'pending')->where('created_at', '<=', now()->subMinutes(2))
            ->orderBy('id')->limit($limit)->get()->each(function (Payment $payment) use ($checkout, &$processed, &$failures): void {
                try {
                    $checkout->reconcilePendingPayment($payment);
                    $processed++;
                } catch (\Throwable $exception) {
                    $failures++;
                    Log::warning('Stripe payment reconciliation deferred; no ambiguous payment was released.', [
                        'payment_id' => $payment->id,
                        'exception' => $exception::class,
                    ]);
                }
            });

        PaymentRefund::query()->where('status', 'pending')->orderBy('id')->limit($limit)->get()->each(function (PaymentRefund $refund) use ($refunds, &$processed, &$failures): void {
            try {
                $refunds->retryPendingRefund($refund);
                $processed++;
            } catch (\Throwable $exception) {
                $failures++;
                Log::warning('Stripe refund reconciliation deferred; reserved credits remain unavailable until status is known.', [
                    'refund_id' => $refund->id,
                    'exception' => $exception::class,
                ]);
            }
        });

        $this->info("Reconciled {$processed} pending payment/refund record(s); {$failures} deferred for a later retry.");

        return self::SUCCESS;
    }
}
