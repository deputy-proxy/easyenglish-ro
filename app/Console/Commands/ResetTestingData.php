<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ResetTestingData extends Command
{
    protected $signature = 'app:reset-testing-data
        {--execute : Perform the reset after showing the preview}
        {--force : Skip the interactive confirmation (also enables execution)}';

    protected $description = 'Preview or remove customer and transactional data while preserving site content and staff accounts.';

    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->components->error('This command is disabled in production.');

            return self::FAILURE;
        }

        $customerIds = $this->customerUserIds();
        $customerEmails = array_values(User::query()
            ->whereIn('id', $customerIds)
            ->pluck('email')
            ->map(fn ($email): string => (string) $email)
            ->values()
            ->all());
        $plan = $this->deletionPlan($customerIds, $customerEmails);

        $counts = [];
        foreach ($plan as $table => $query) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $counts[$table] = $query()->count();
        }

        $this->components->info('Testing data reset preview');
        $this->table(['Table', 'Rows'], collect($counts)
            ->map(fn (int $count, string $table): array => [$table, number_format($count)])
            ->values()
            ->all());

        $total = array_sum($counts);
        $this->line('Total rows scheduled for deletion: '.number_format($total));

        if (! $this->option('execute') && ! $this->option('force')) {
            $this->comment('Preview only. Re-run with --execute to delete these records.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm('Permanently delete the records listed above?')) {
            $this->components->warn('Reset cancelled.');

            return self::SUCCESS;
        }

        $deleted = DB::transaction(function () use ($plan): array {
            $results = [];

            foreach ($plan as $table => $query) {
                if (! Schema::hasTable($table)) {
                    continue;
                }

                $results[$table] = $query()->delete();
            }

            return $results;
        });

        $this->newLine();
        $this->components->info('Testing data reset completed.');
        $this->table(['Table', 'Deleted'], collect($deleted)
            ->map(fn (int $count, string $table): array => [$table, number_format($count)])
            ->values()
            ->all());
        $this->line('Total rows deleted: '.number_format(array_sum($deleted)));

        return self::SUCCESS;
    }

    /**
     * Customer accounts are users without any internal role. This intentionally
     * includes legacy customer accounts that do not have a student profile.
     *
     * @return list<int>
     */
    private function customerUserIds(): array
    {
        return array_values(User::query()
            ->where('is_admin', false)
            ->where('is_teacher', false)
            ->where('is_staff', false)
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all());
    }

    /**
     * The explicit allow-list is deliberate: content, staff, teacher calendars,
     * availability, bundle definitions, discount vouchers, and Laravel sessions
     * are not part of this reset.
     *
     * @param  list<int>  $customerIds
     * @param  list<string>  $customerEmails
     * @return array<string, \Closure(): Builder>
     */
    private function deletionPlan(array $customerIds, array $customerEmails): array
    {
        $customerNotifications = fn (): Builder => DB::table('notifications')
            ->where('notifiable_type', (new User)->getMorphClass())
            ->whereIn('notifiable_id', $customerIds);

        return [
            'booking_call_events' => fn (): Builder => DB::table('booking_call_events'),
            'session_feedback' => fn (): Builder => DB::table('session_feedback'),
            'progress_reports' => fn (): Builder => DB::table('progress_reports'),
            'notification_deliveries' => fn (): Builder => DB::table('notification_deliveries'),
            'booking_audits' => fn (): Builder => DB::table('booking_audits'),
            'credit_transactions' => fn (): Builder => DB::table('credit_transactions'),
            'discount_voucher_redemptions' => fn (): Builder => DB::table('discount_voucher_redemptions'),
            'payment_disputes' => fn (): Builder => DB::table('payment_disputes'),
            'payment_refunds' => fn (): Builder => DB::table('payment_refunds'),
            'payments' => fn (): Builder => DB::table('payments'),
            'free_session_claims' => fn (): Builder => DB::table('free_session_claims'),
            'bookings' => fn (): Builder => DB::table('bookings'),
            'bundle_entitlements' => fn (): Builder => DB::table('bundle_entitlements'),
            'notifications' => $customerNotifications,
            'contact_verification_challenges' => fn (): Builder => DB::table('contact_verification_challenges'),
            'password_reset_tokens' => fn (): Builder => DB::table('password_reset_tokens')->whereIn('email', $customerEmails),
            'passkeys' => fn (): Builder => DB::table('passkeys')->whereIn('user_id', $customerIds),
            'student_profiles' => fn (): Builder => DB::table('student_profiles')->whereIn('user_id', $customerIds),
            'enquiries' => fn (): Builder => DB::table('enquiries'),
            'messages' => fn (): Builder => DB::table('messages'),
            'whatsapp_contacts' => fn (): Builder => DB::table('whatsapp_contacts'),
            'whatsapp_webhook_events' => fn (): Builder => DB::table('whatsapp_webhook_events'),
            'stripe_webhook_events' => fn (): Builder => DB::table('stripe_webhook_events'),
            'users' => fn (): Builder => DB::table('users')->whereIn('id', $customerIds),
        ];
    }
}
