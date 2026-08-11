<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('settlement_runs')->orderBy('id')->each(function (object $run): void {
            $members = DB::table('settlement_run_members')
                ->where('settlement_run_id', $run->id)
                ->get(['agent_id', 'outcome', 'error_message_key', 'error_parameters']);
            $existingAgentIds = $members
                ->where('outcome', 'existing')
                ->pluck('agent_id')
                ->map(static fn ($agentId): int => (int) $agentId)
                ->sort()
                ->values()
                ->all();
            $errors = [];
            foreach ($members->where('outcome', 'failed') as $member) {
                $parameters = is_string($member->error_parameters)
                    ? (json_decode($member->error_parameters, true) ?: [])
                    : ($member->error_parameters ?? []);
                $errors[(string) $member->agent_id] = [
                    'message_key' => $member->error_message_key ?? 'settlements.failure_reasons.legacy_unknown',
                    'parameters' => is_array($parameters) ? $parameters : [],
                ];
            }

            $summary = DB::table('settlement_run_members as member')
                ->leftJoin('settlements', 'settlements.id', '=', 'member.settlement_id')
                ->where('member.settlement_run_id', $run->id)
                ->selectRaw('COUNT(*) as total_agents')
                ->selectRaw("COUNT(*) FILTER (WHERE member.outcome = 'generated') as processed_agents")
                ->selectRaw("COUNT(*) FILTER (WHERE member.outcome = 'existing') as existing_agents")
                ->selectRaw("COUNT(*) FILTER (WHERE member.outcome = 'failed') as failed_agents")
                ->selectRaw('COALESCE(SUM(settlements.total_consumption_krw), 0) as total_consumption_krw')
                ->selectRaw('COALESCE(SUM(settlements.total_commission_krw), 0) as total_commission_krw')
                ->first();

            $hasPending = $members->contains(static fn ($member): bool => $member->outcome === 'pending');
            $hasFailed = $members->contains(static fn ($member): bool => $member->outcome === 'failed');
            $memberCount = $members->count();
            $legacyJobsMayStillBeQueued = in_array($run->status, ['queued', 'running'], true)
                && (int) $run->total_agents > $memberCount;
            $status = $legacyJobsMayStillBeQueued
                ? 'running'
                : ($hasPending ? 'running' : ($hasFailed ? 'partial_failed' : 'completed'));
            $completedAt = $status === 'running' ? null : ($run->completed_at ?? now());
            $totalAgents = $legacyJobsMayStillBeQueued
                ? max((int) $run->total_agents, (int) $summary->total_agents)
                : (int) $summary->total_agents;

            DB::table('settlement_runs')->where('id', $run->id)->update([
                'status' => $status,
                'total_agents' => $totalAgents,
                'processed_agents' => (int) $summary->processed_agents,
                'existing_agents' => (int) $summary->existing_agents,
                'existing_agent_ids' => json_encode($existingAgentIds, JSON_THROW_ON_ERROR),
                'failed_agents' => (int) $summary->failed_agents,
                'total_consumption_krw' => (int) $summary->total_consumption_krw,
                'total_commission_krw' => (int) $summary->total_commission_krw,
                'errors' => $errors === [] ? null : json_encode($errors, JSON_THROW_ON_ERROR),
                'completed_at' => $completedAt,
                'updated_at' => now(),
            ]);
        });
    }

    public function down(): void
    {
        // Projection repair is intentionally forward-only.
    }
};
