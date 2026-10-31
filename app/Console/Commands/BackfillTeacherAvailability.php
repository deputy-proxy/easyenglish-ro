<?php

namespace App\Console\Commands;

use App\Models\AvailabilityException;
use App\Models\AvailabilityRule;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillTeacherAvailability extends Command
{
    protected $signature = 'teachers:backfill-availability {--teacher= : Limit the backfill to one teacher user ID}';

    protected $description = 'Copy the legacy centrally managed availability rules and exceptions to teacher schedules without deleting the originals';

    public function handle(): int
    {
        $teachers = User::query()->where('is_teacher', true)->when($this->option('teacher'), fn ($query, $id) => $query->whereKey((int) $id))->orderBy('id')->get();
        $copied = 0;
        $skipped = 0;

        $legacyRules = AvailabilityRule::query()->whereNull('user_id')->get();
        $legacyExceptions = AvailabilityException::query()->whereNull('user_id')->get();

        foreach ($teachers as $teacher) {
            if ($teacher->availabilityRules()->exists() || $teacher->availabilityExceptions()->exists()) {
                $skipped++;

                continue;
            }

            DB::transaction(function () use ($teacher, $legacyRules, $legacyExceptions, &$copied): void {
                foreach ($legacyRules as $rule) {
                    AvailabilityRule::query()->create([
                        'user_id' => $teacher->id,
                        'weekday' => $rule->weekday,
                        'starts_at' => $rule->starts_at,
                        'ends_at' => $rule->ends_at,
                        'is_active' => $rule->is_active,
                    ]);
                    $copied++;
                }
                foreach ($legacyExceptions as $exception) {
                    AvailabilityException::query()->create([
                        'user_id' => $teacher->id,
                        'kind' => $exception->kind,
                        'starts_at' => $exception->starts_at,
                        'ends_at' => $exception->ends_at,
                        'reason' => $exception->reason,
                        'created_by' => $exception->created_by,
                    ]);
                    $copied++;
                }
            });
        }

        $this->info("Copied {$copied} legacy availability record(s); skipped {$skipped} teacher(s) with existing schedules.");

        return self::SUCCESS;
    }
}
