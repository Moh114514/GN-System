<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settlement_configurations', function (Blueprint $table): void {
            $table->unsignedTinyInteger('generation_day')->nullable()->default(10)->after('boundary_day');
        });

        $configurations = DB::table('settlement_configurations')
            ->orderBy('effective_from')
            ->get();

        if ($configurations->isEmpty()) {
            return;
        }

        // Existing rows remain legacy period definitions. A new version below
        // switches future generations to natural-month semantics without
        // rewriting any historical boundary or run.
        DB::table('settlement_configurations')->update([
            'generation_day' => null,
            'updated_at' => now(),
        ]);

        $latest = $configurations->last();
        $transitionFrom = CarbonImmutable::now('Asia/Shanghai')
            ->startOfMonth()
            ->addMonthNoOverflow()
            ->startOfDay();
        $latestEffectiveFrom = CarbonImmutable::parse((string) $latest->effective_from, 'Asia/Shanghai');
        if (! $latestEffectiveFrom->isBefore($transitionFrom)) {
            $transitionFrom = $latestEffectiveFrom->addDay()->startOfDay();
        }

        DB::table('settlement_configurations')->updateOrInsert(
            ['effective_from' => $transitionFrom->toDateString()],
            [
                'boundary_day' => (int) $latest->boundary_day,
                'generation_day' => 10,
                'trigger_time' => (string) $latest->trigger_time,
                'timezone' => (string) $latest->timezone,
                'created_by' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        // The transition row intentionally remains. Its boundary_day preserves
        // the pre-PR6 behavior if application code is rolled back as well.
        Schema::table('settlement_configurations', function (Blueprint $table): void {
            $table->dropColumn('generation_day');
        });
    }
};
