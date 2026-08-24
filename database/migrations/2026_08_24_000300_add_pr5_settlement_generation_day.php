<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('settlement_configurations')) {
            return;
        }

        $latest = DB::table('settlement_configurations')->orderByDesc('effective_from')->first();
        $effectiveFrom = Carbon::now('Asia/Shanghai')->startOfMonth()->addMonthNoOverflow()->toDateString();
        if ($latest !== null && (string) $latest->effective_from > $effectiveFrom) {
            $effectiveFrom = Carbon::parse($latest->effective_from, 'Asia/Shanghai')->addDay()->toDateString();
        }

        DB::table('settlement_configurations')->updateOrInsert(
            ['effective_from' => $effectiveFrom],
            [
                'boundary_day' => (int) ($latest->boundary_day ?? 1),
                'generation_day' => 5,
                'trigger_time' => (string) ($latest->trigger_time ?? '09:00:00'),
                'timezone' => (string) ($latest->timezone ?? 'Asia/Shanghai'),
                'created_by' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        if (! Schema::hasTable('settlement_configurations')) {
            return;
        }

        $transition = Carbon::now('Asia/Shanghai')->startOfMonth()->addMonthNoOverflow()->toDateString();
        $transitionRow = DB::table('settlement_configurations')
            ->whereDate('effective_from', $transition)
            ->where('generation_day', 5)
            ->whereNull('created_by')
            ->first();
        if ($transitionRow !== null) {
            DB::table('settlement_configurations')->where('id', $transitionRow->id)->update(['generation_day' => 10, 'updated_at' => now()]);
        }
        DB::table('settlement_configurations')
            ->where('generation_day', 5)
            ->whereNull('created_by')
            ->whereDate('effective_from', '>', $transition)
            ->delete();
    }
};
