<?php

namespace App\Modules\Settlement\Application\Services;

use App\Modules\Settlement\Application\Exceptions\StructuredSettlementFailure;
use App\Modules\Settlement\Infrastructure\Models\SettlementRunMember;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Records queue-level settlement failures without resolving the normal
 * settlement generation dependency graph.
 */
final readonly class SettlementFailureRecorder
{
    public function __construct(private SettlementRunSummaryUpdater $summary) {}

    public function record(string $memberIdOrRunId, Throwable $exception, ?int $legacyAgentId = null): void
    {
        $member = $legacyAgentId === null
            ? SettlementRunMember::query()->findOrFail((int) $memberIdOrRunId)
            : $this->resolveLegacyMember($memberIdOrRunId, $legacyAgentId);
        $run = $member->run()->firstOrFail();

        DB::transaction(function () use ($member, $run, $exception): void {
            $member->refresh();
            if (in_array($member->outcome, ['generated', 'existing'], true)) {
                return;
            }

            if ($exception instanceof DomainException) {
                Log::warning('Settlement generation rejected by business rule.', [
                    'run_id' => $run->id,
                    'agent_id' => $member->agent_id,
                    'message' => $exception->getMessage(),
                ]);
                $failure = $this->structuredFailure($exception);
            } else {
                $reference = (string) Str::uuid();
                Log::error('Settlement generation failed.', [
                    'reference' => $reference,
                    'run_id' => $run->id,
                    'agent_id' => $member->agent_id,
                    'exception' => $exception,
                ]);
                $failure = [
                    'message_key' => 'settlements.failure_reasons.unexpected',
                    'parameters' => ['reference' => $reference],
                ];
            }

            $member->update([
                'outcome' => 'failed',
                'error_message_key' => $failure['message_key'],
                'error_parameters' => $failure['parameters'],
                'processed_at' => CarbonImmutable::now(),
            ]);
            $this->summary->update($run);
        }, 3);
    }

    private function resolveLegacyMember(string $runId, int $agentId): SettlementRunMember
    {
        return SettlementRunMember::query()
            ->where('settlement_run_id', $runId)
            ->where('agent_id', $agentId)
            ->firstOrCreate([
                'settlement_run_id' => $runId,
                'agent_id' => $agentId,
            ], [
                'outcome' => 'pending',
            ]);
    }

    /** @return array{message_key: string, parameters: array<string, scalar>} */
    private function structuredFailure(DomainException $exception): array
    {
        if ($exception instanceof StructuredSettlementFailure) {
            return ['message_key' => $exception->messageKey, 'parameters' => $exception->parameters];
        }
        if (in_array($exception->getMessage(), [
            __('agents.validation.no_effective_policy_grade', [], 'zh_CN'),
            __('agents.validation.no_effective_policy_grade', [], 'ko_KR'),
        ], true)) {
            return ['message_key' => 'settlements.failure_reasons.agent_policy_missing', 'parameters' => []];
        }

        return ['message_key' => 'settlements.failure_reasons.business_rule', 'parameters' => []];
    }
}
