<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bundle_entitlements', function (Blueprint $table): void {
            $table->string('bundle_name_snapshot', 160)->nullable()->after('bundle_definition_id');
            $table->text('bundle_description_snapshot')->nullable()->after('bundle_name_snapshot');
            $table->unsignedSmallInteger('included_free_credits_snapshot')->default(0)->after('bundle_description_snapshot');
            $table->string('bundle_snapshot_source', 24)->default('legacy_backfill')->after('included_free_credits_snapshot');
        });

        $definitions = DB::table('bundle_definitions')->get()->keyBy('id');

        DB::table('bundle_entitlements')->orderBy('id')->chunkById(100, function ($entitlements) use ($definitions): void {
            foreach ($entitlements as $entitlement) {
                $definition = $definitions->get($entitlement->bundle_definition_id);
                if (! $definition) {
                    continue;
                }

                $locale = $entitlement->locale === 'en' ? 'en' : 'ro';
                $name = $locale === 'en'
                    ? ($definition->name_en ?: $definition->name_ro ?: $definition->name)
                    : ($definition->name_ro ?: $definition->name_en ?: $definition->name);
                $descriptions = is_string($definition->short_description)
                    ? json_decode($definition->short_description, true)
                    : $definition->short_description;
                $descriptions = is_array($descriptions) ? $descriptions : [];
                $description = $descriptions[$locale] ?? $descriptions['ro'] ?? $descriptions['en'] ?? null;

                DB::table('bundle_entitlements')->where('id', $entitlement->id)->update([
                    'bundle_name_snapshot' => $name,
                    'bundle_description_snapshot' => is_string($description) ? $description : null,
                    'included_free_credits_snapshot' => (int) $definition->included_free_credits,
                    'bundle_snapshot_source' => 'legacy_backfill',
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('bundle_entitlements', function (Blueprint $table): void {
            $table->dropColumn([
                'bundle_name_snapshot',
                'bundle_description_snapshot',
                'included_free_credits_snapshot',
                'bundle_snapshot_source',
            ]);
        });
    }
};
