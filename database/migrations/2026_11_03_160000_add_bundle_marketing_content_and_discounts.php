<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bundle_definitions', function (Blueprint $table): void {
            $table->string('name_ro', 120)->nullable();
            $table->string('name_en', 120)->nullable();
            $table->json('short_description')->nullable();
            $table->json('tagline')->nullable();
            $table->json('features')->nullable();
            $table->json('discounts')->nullable();
            $table->boolean('featured')->default(false);
        });

        DB::table('bundle_definitions')->orderBy('sort_order')->orderBy('id')->get()->each(function (object $bundle, int $index): void {
            $total = (int) $bundle->paid_credits + 1;
            $paid = (int) $bundle->paid_credits;
            $freeRo = '1 sesiune gratuită';
            $freeEn = '1 free session';
            $paidRo = $paid === 1 ? '1 sesiune plătită' : $paid.' sesiuni plătite';
            $paidEn = $paid === 1 ? '1 paid session' : $paid.' paid sessions';

            DB::table('bundle_definitions')->where('id', $bundle->id)->update([
                'name_ro' => 'Pachet '.($index + 1),
                'name_en' => 'Bundle '.($index + 1),
                'short_description' => json_encode(['ro' => $freeRo.' + '.$paidRo, 'en' => $freeEn.' + '.$paidEn], JSON_THROW_ON_ERROR),
                'tagline' => json_encode(['ro' => $total.' sesiuni în total', 'en' => $total.' sessions in total'], JSON_THROW_ON_ERROR),
                'features' => json_encode([
                    ['type' => 'feature', 'data' => ['ro' => '15 minute pe sesiune', 'en' => '15 minutes per session']],
                    ['type' => 'feature', 'data' => ['ro' => 'Valabil 3 luni calendaristice de la activare', 'en' => 'Valid for 3 calendar months after activation']],
                    ['type' => 'feature', 'data' => ['ro' => 'Toate taxele aplicabile sunt incluse.', 'en' => 'All applicable taxes are included.']],
                ], JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('bundle_definitions', function (Blueprint $table): void {
            $table->dropColumn(['name_ro', 'name_en', 'short_description', 'tagline', 'features', 'discounts', 'featured']);
        });
    }
};
