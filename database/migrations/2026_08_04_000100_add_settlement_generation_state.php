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
        Schema::table('settlements', function (Blueprint $table): void {
            $table->string('generation_status', 24)->default('pending')->after('status');
            $table->timestamp('generated_at')->nullable()->after('generation_status');
            $table->unsignedInteger('item_count')->default(0)->after('generated_at');
            $table->timestamp('exchange_rate_quote_attempted_at')->nullable()->after('exchange_rate_quoted_at');
        });

        $this->backfillExistingSettlements();
    }

    public function down(): void
    {
        Schema::table('settlements', function (Blueprint $table): void {
            $table->dropColumn([
                'generation_status',
                'generated_at',
                'item_count',
                'exchange_rate_quote_attempted_at',
            ]);
        });
    }

    private function backfillExistingSettlements(): void
    {
        $itemCounts = DB::table('settlement_items')
            ->select('settlement_id')
            ->selectRaw('COUNT(*) as item_count')
            ->groupBy('settlement_id')
            ->pluck('item_count', 'settlement_id');
        $documentDates = DB::table('settlement_documents')
            ->select('settlement_id')
            ->selectRaw('MAX(generated_at) as generated_at')
            ->groupBy('settlement_id')
            ->pluck('generated_at', 'settlement_id');
        $runDates = DB::table('settlement_runs')
            ->whereNotNull('completed_at')
            ->pluck('completed_at', 'id');

        DB::table('settlements')
            ->select([
                'id',
                'status',
                'snapshot',
                'import_batch_id',
                'settlement_run_id',
                'created_at',
            ])
            ->orderBy('id')
            ->each(function (object $settlement) use ($itemCounts, $documentDates, $runDates): void {
                $itemCount = (int) ($itemCounts[$settlement->id] ?? 0);
                $snapshot = is_string($settlement->snapshot)
                    ? json_decode($settlement->snapshot, true)
                    : $settlement->snapshot;
                $source = is_array($snapshot) ? ($snapshot['source'] ?? null) : null;
                $runExists = $settlement->settlement_run_id !== null
                    && $runDates->has($settlement->settlement_run_id);
                $isHistoricalImport = $settlement->import_batch_id !== null
                    || in_array($source, ['historical_import', 'demo_data'], true)
                    || (in_array($settlement->status, ['paid', 'reconciled'], true) && ! $runExists);
                $hasGenerationEvidence = $source === 'phase_five_generation'
                    || $itemCount > 0
                    || $documentDates->has($settlement->id)
                    || ($runExists && in_array($settlement->status, ['approved', 'settled'], true));
                $generationStatus = $isHistoricalImport
                    ? 'not_applicable'
                    : ($hasGenerationEvidence ? 'generated' : 'unverified');
                $generatedAt = null;
                if ($generationStatus === 'generated') {
                    $timestamps = array_filter([
                        is_array($snapshot) && is_string($snapshot['generated_at'] ?? null)
                            ? $snapshot['generated_at']
                            : null,
                        $runDates[$settlement->settlement_run_id] ?? null,
                        $documentDates[$settlement->id] ?? null,
                        $settlement->created_at,
                    ]);
                    foreach ($timestamps as $timestamp) {
                        try {
                            $generatedAt = CarbonImmutable::parse($timestamp)->toDateTimeString();
                            break;
                        } catch (Throwable) {
                            // Keep the other verifiable timestamps if a legacy snapshot is malformed.
                        }
                    }
                }

                DB::table('settlements')
                    ->where('id', $settlement->id)
                    ->update([
                        'generation_status' => $generationStatus,
                        'generated_at' => $generatedAt,
                        'item_count' => $itemCount,
                    ]);
            });
    }
};
