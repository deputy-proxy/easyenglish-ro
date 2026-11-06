<?php

namespace App\Console\Commands;

use App\Models\Booking;
use App\Models\BundleEntitlement;
use App\Models\User;
use App\Support\PhoneNumber;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;

class ReconcileCustomerRecordOwnership extends Command
{
    protected $signature = 'customers:reconcile-record-ownership
        {--apply : Persist only unambiguous verified matches}
        {--report= : Optional path for a JSON reconciliation report}';

    protected $description = 'Report or conservatively backfill customer ownership on bookings and bundle entitlements.';

    /** @var array<string, array{linked:int, unmatched:int, ambiguous:int, linked_ids:list<int>, unmatched_ids:list<int>, ambiguous_ids:list<int>}> */
    private array $report = [];

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        $usersByIdentity = collect(User::query()
            ->whereNotNull('email_verified_at')
            ->whereNotNull('phone_verified_at')
            ->whereNotNull('phone')
            ->get(['id', 'email', 'phone', 'email_verified_at', 'phone_verified_at'])
            ->groupBy(fn (User $user): string => mb_strtolower(trim($user->email)).'|'.PhoneNumber::normalize((string) $user->phone))->all());

        $this->reconcile(Booking::query()->whereNull('customer_user_id')->lazyById(), 'bookings', false, $apply, $usersByIdentity);
        $this->reconcile(BundleEntitlement::query()->whereNull('customer_user_id')->lazyById(), 'bundle_entitlements', true, $apply, $usersByIdentity);

        foreach ($this->report as $table => $counts) {
            $this->table(
                ['Aggregate', 'Linked'.($apply ? '' : ' (would link)'), 'Unmatched', 'Ambiguous'],
                [[$table, $counts['linked'], $counts['unmatched'], $counts['ambiguous']]],
            );
        }

        $this->info($apply ? 'Ownership reconciliation applied for unambiguous verified matches.' : 'Dry run only. No records were changed. Use --apply after reviewing the report.');

        $reportPath = $this->option('report');
        if (is_string($reportPath) && $reportPath !== '') {
            File::ensureDirectoryExists(dirname($reportPath));
            File::put($reportPath, json_encode([
                'mode' => $apply ? 'apply' : 'dry-run',
                'generated_at' => now()->toIso8601String(),
                'aggregates' => $this->report,
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            $this->line('Report written to '.$reportPath);
        }

        return self::SUCCESS;
    }

    /**
     * @param  iterable<Booking|BundleEntitlement>  $records
     * @param  Collection<string, EloquentCollection<int, User>>  $usersByIdentity
     */
    private function reconcile(iterable $records, string $table, bool $requireSnapshotVerification, bool $apply, Collection $usersByIdentity): void
    {
        $linked = 0;
        $unmatched = 0;
        $ambiguous = 0;
        /** @var list<int> $linkedIds */
        $linkedIds = [];
        /** @var list<int> $unmatchedIds */
        $unmatchedIds = [];
        /** @var list<int> $ambiguousIds */
        $ambiguousIds = [];

        foreach ($records as $record) {
            if ($requireSnapshotVerification && $record instanceof BundleEntitlement && ($record->customer_email_verified_at === null || $record->customer_phone_verified_at === null)) {
                $unmatched++;
                $unmatchedIds[] = $record->id;

                continue;
            }

            $email = mb_strtolower(trim((string) $record->customer_email));
            $phone = PhoneNumber::normalize((string) $record->customer_phone);
            $candidates = $usersByIdentity->get($email.'|'.$phone);

            if ($candidates?->count() > 1) {
                $ambiguous++;
                $ambiguousIds[] = $record->id;

                continue;
            }

            $candidate = $candidates?->first();
            if (! $candidate instanceof User) {
                $unmatched++;
                $unmatchedIds[] = $record->id;

                continue;
            }

            $linked++;
            $linkedIds[] = $record->id;

            if ($apply) {
                $record->forceFill(['customer_user_id' => $candidate->id])->save();
            }
        }

        $this->report[$table] = ['linked' => $linked, 'unmatched' => $unmatched, 'ambiguous' => $ambiguous, 'linked_ids' => $linkedIds, 'unmatched_ids' => $unmatchedIds, 'ambiguous_ids' => $ambiguousIds];
    }
}
