<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('settlement_runs')->orderBy('id')->each(function (object $run): void {
            $now = now();
            $members = [];

            DB::table('settlements')
                ->where('settlement_run_id', $run->id)
                ->pluck('id', 'agent_id')
                ->each(function ($settlementId, $agentId) use (&$members, $run, $now): void {
                    $members[] = [
                        'settlement_run_id' => $run->id,
                        'agent_id' => (int) $agentId,
                        'settlement_id' => (int) $settlementId,
                        'outcome' => 'generated',
                        'attempt_count' => 1,
                        'processed_at' => $now,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                });

            foreach (array_map('intval', is_string($run->existing_agent_ids) ? (json_decode($run->existing_agent_ids, true) ?: []) : ($run->existing_agent_ids ?? [])) as $agentId) {
                $settlementId = DB::table('settlements')
                    ->where('agent_id', $agentId)
                    ->whereDate('period_start', $run->period_start)
                    ->whereDate('period_end', $run->period_end)
                    ->value('id');
                if ($settlementId === null) {
                    continue;
                }
                $members[] = [
                    'settlement_run_id' => $run->id,
                    'agent_id' => $agentId,
                    'settlement_id' => (int) $settlementId,
                    'outcome' => 'existing',
                    'attempt_count' => 0,
                    'processed_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            $errors = is_string($run->errors) ? (json_decode($run->errors, true) ?: []) : ($run->errors ?? []);
            foreach ($errors as $agentId => $error) {
                $messageKey = is_array($error) ? ($error['message_key'] ?? 'settlements.failure_reasons.legacy_unknown') : 'settlements.failure_reasons.legacy_unknown';
                $parameters = is_array($error) && is_array($error['parameters'] ?? null) ? $error['parameters'] : [];
                if (DB::table('settlement_run_members')->where(['settlement_run_id' => $run->id, 'agent_id' => (int) $agentId])->exists()) {
                    continue;
                }
                $members[] = [
                    'settlement_run_id' => $run->id,
                    'agent_id' => (int) $agentId,
                    'outcome' => 'failed',
                    'attempt_count' => 1,
                    'error_message_key' => $messageKey,
                    'error_parameters' => json_encode($parameters, JSON_THROW_ON_ERROR),
                    'processed_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            foreach ($members as $member) {
                DB::table('settlement_run_members')->updateOrInsert(
                    ['settlement_run_id' => $member['settlement_run_id'], 'agent_id' => $member['agent_id']],
                    $member,
                );
            }
        });
    }

    public function down(): void
    {
        // Relationship backfill is intentionally retained when the table is rolled back.
    }
};
