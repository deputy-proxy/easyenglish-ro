<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('bundle_definitions', function (Blueprint $table): void {
            $table->unsignedSmallInteger('included_free_credits')->default(0)->change();
            $table->unsignedSmallInteger('sort_order')->default(0)->after('included_free_credits');
        });

        DB::table('bundle_definitions')->whereIn('name', [
            'Starter: 1 free + 4 paid',
            'Standard: 1 free + 10 paid',
            'Extended: 1 free + 20 paid',
        ])->get()->each(function (object $bundle): void {
            DB::table('bundle_definitions')->where('id', $bundle->id)->update([
                'name' => match ((int) $bundle->paid_credits) {
                    4 => '4 sessions',
                    10 => '10 sessions',
                    20 => '20 sessions',
                    default => $bundle->name,
                },
                'included_free_credits' => 0,
                'sort_order' => match ((int) $bundle->paid_credits) {
                    4 => 1,
                    10 => 2,
                    20 => 3,
                    default => 0,
                },
                'updated_at' => now(),
            ]);
        });
    }

    public function down(): void
    {
        DB::table('bundle_definitions')->whereIn('name', ['4 sessions', '10 sessions', '20 sessions'])->get()->each(function (object $bundle): void {
            DB::table('bundle_definitions')->where('id', $bundle->id)->update([
                'name' => match ((int) $bundle->paid_credits) {
                    4 => 'Starter: 1 free + 4 paid',
                    10 => 'Standard: 1 free + 10 paid',
                    20 => 'Extended: 1 free + 20 paid',
                    default => $bundle->name,
                },
                'included_free_credits' => 1,
                'updated_at' => now(),
            ]);
        });

        Schema::table('bundle_definitions', function (Blueprint $table): void {
            $table->dropColumn('sort_order');
            $table->unsignedSmallInteger('included_free_credits')->default(1)->change();
        });
    }
};
