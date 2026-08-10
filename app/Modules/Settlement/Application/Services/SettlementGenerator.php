<?php

namespace App\Modules\Settlement\Application\Services;

use App\Modules\Agent\Application\Contracts\SettlementAgentGateway;
use App\Modules\Order\Application\Contracts\SettlementOrderReader;
use App\Modules\Settlement\Application\Exceptions\StructuredSettlementFailure;
use App\Modules\Settlement\Infrastructure\Models\OrderCommission;
use App\Modules\Settlement\Infrastructure\Models\Settlement;
use App\Modules\Settlement\Infrastructure\Models\SettlementGradeSuggestion;
use App\Modules\Settlement\Infrastructure\Models\SettlementRunMember;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

final readonly class SettlementGenerator
{
    public function __construct(
        private SettlementOrderReader $orders,
        private SettlementAgentGateway $agents,
        private SettlementRunSummaryUpdater $summary,
    ) {}

    /** Generate the settlement represented by one run member. */
    public function generate(string $memberId, ?int $legacyAgentId = null): void
    {
        $member = $this->resolveMember($memberId, $legacyAgentId, false);
        if (in_array($member->outcome, ['generated', 'existing'], true)) {
            return;
        }
        $member->increment('attempt_count');

        DB::transaction(function () use ($memberId, $legacyAgentId): void {
            $member = $this->resolveMember($memberId, $legacyAgentId, true);
            if (in_array($member->outcome, ['generated', 'existing'], true)) {
                return;
            }

            $run = $member->run()->lockForUpdate()->firstOrFail();
            $existing = Settlement::query()
                ->where('agent_id', $member->agent_id)
                ->whereDate('period_start', $run->period_start)
                ->whereDate('period_end', $run->period_end)
                ->lockForUpdate()
                ->first();

            if ($existing !== null && $existing->settlement_run_id !== $run->id) {
                $this->markExisting($member, $existing);

                return;
            }
            if ($existing !== null && $existing->generation_status === 'not_applicable') {
                $this->markExisting($member, $existing);

                return;
            }

            if ($existing !== null && (! in_array($existing->generation_status, ['pending', 'unverified'], true)
                || ! in_array($existing->status, ['pending_review', 'rejected'], true))) {
                return;
            }

            $periodStart = CarbonImmutable::parse($run->period_start);
            $periodEnd = CarbonImmutable::parse($run->period_end);
            $agent = $this->agents->forMonth((int) $member->agent_id, $periodEnd);
            $orders = $this->orders->completedForAgent((int) $member->agent_id, $periodStart, $periodEnd);
            $orderIds = array_map(fn ($order): int => $order->orderId, $orders);
            $commissions = OrderCommission::query()->whereIn('order_id', $orderIds)->get()->keyBy('order_id');
            foreach ($orders as $order) {
                if (! $commissions->has($order->orderId)) {
                    throw new StructuredSettlementFailure('settlements.failure_reasons.missing_commission_snapshot', ['order_id' => $order->orderId]);
                }
            }

            $totalConsumption = array_sum(array_map(fn ($order): int => $order->amountKrw, $orders));
            $totalCommission = (int) $commissions->sum('amount_krw');
            $settlement = Settlement::query()->updateOrCreate(
                ['agent_id' => $member->agent_id, 'period_start' => $periodStart, 'period_end' => $periodEnd],
                [
                    'settlement_run_id' => $run->id,
                    'total_consumption_krw' => $totalConsumption,
                    'total_commission_krw' => $totalCommission,
                    'payout_amount_cny_fen' => 0,
                    'status' => 'pending_review',
                    'generation_status' => 'generated',
                    'generated_at' => now(),
                    'item_count' => count($orders),
                    'snapshot' => [
                        'source' => 'phase_five_generation',
                        'agent' => ['id' => $agent->id, 'code' => $agent->code, 'name' => $agent->name],
                        'policy_system_id' => $agent->policySystemId,
                        'policy_grade' => ['id' => $agent->currentGradeId, 'name' => $agent->currentGradeName],
                        'generated_at' => now()->toIso8601String(),
                    ],
                    'reviewed_by' => null,
                    'reviewed_at' => null,
                    'rejection_reason' => null,
                ],
            );
            DB::table('settlement_items')->where('settlement_id', $settlement->id)->delete();
            foreach ($orders as $order) {
                $commission = $commissions->get($order->orderId);
                DB::table('settlement_items')->insert([
                    'settlement_id' => $settlement->id,
                    'order_commission_id' => $commission->id,
                    'consumption_krw' => $order->amountKrw,
                    'commission_krw' => $commission->amount_krw,
                    'rule_snapshot' => json_encode([
                        ...$commission->rule_snapshot,
                        'order' => ['id' => $order->orderId, 'customer_id' => $order->customerId, 'project_name' => $order->projectName, 'completed_on' => $order->completedOn->toDateString()],
                    ], JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $recommended = $this->agents->recommendation((int) $member->agent_id, $periodEnd, $totalCommission);
            SettlementGradeSuggestion::query()->where('settlement_id', $settlement->id)->delete();
            if ($recommended->currentGradeId !== $agent->currentGradeId) {
                SettlementGradeSuggestion::query()->create([
                    'settlement_id' => $settlement->id,
                    'agent_id' => $member->agent_id,
                    'current_grade_id' => $agent->currentGradeId,
                    'recommended_grade_id' => $recommended->currentGradeId,
                    'monthly_commission_krw' => $totalCommission,
                    'status' => 'pending',
                ]);
            }

            $member->update([
                'settlement_id' => $settlement->id,
                'outcome' => 'generated',
                'error_message_key' => null,
                'error_parameters' => null,
                'processed_at' => now(),
            ]);
            $this->summary->update($run);
        }, 3);
    }

    /** Supports the pre-member API during the compatibility window. */
    public function markFailed(string $memberIdOrRunId, int|Throwable $agentIdOrException, ?Throwable $legacyException = null): void
    {
        $exception = $legacyException ?? ($agentIdOrException instanceof Throwable ? $agentIdOrException : null);
        if (! $exception instanceof Throwable) {
            throw new DomainException('A settlement generation failure must include an exception.');
        }
        $member = $legacyException === null
            ? SettlementRunMember::query()->findOrFail((int) $memberIdOrRunId)
            : $this->resolveMember($memberIdOrRunId, (int) $agentIdOrException, false);
        $run = $member->run()->firstOrFail();

        DB::transaction(function () use ($member, $run, $exception): void {
            $member->refresh();
            if (in_array($member->outcome, ['generated', 'existing'], true)) {
                return;
            }
            if ($exception instanceof DomainException) {
                Log::warning('Settlement generation rejected by business rule.', ['run_id' => $run->id, 'agent_id' => $member->agent_id, 'message' => $exception->getMessage()]);
                $failure = $this->structuredFailure($exception);
            } else {
                $reference = (string) Str::uuid();
                Log::error('Settlement generation failed.', ['reference' => $reference, 'run_id' => $run->id, 'agent_id' => $member->agent_id, 'exception' => $exception]);
                $failure = ['message_key' => 'settlements.failure_reasons.unexpected', 'parameters' => ['reference' => $reference]];
            }
            $member->update([
                'outcome' => 'failed',
                'error_message_key' => $failure['message_key'],
                'error_parameters' => $failure['parameters'],
                'processed_at' => now(),
            ]);
            $this->summary->update($run);
        }, 3);
    }

    private function resolveMember(string $memberIdOrRunId, ?int $legacyAgentId, bool $forUpdate): SettlementRunMember
    {
        if ($legacyAgentId === null) {
            $query = SettlementRunMember::query();
            if ($forUpdate) {
                $query->lockForUpdate();
            }

            return $query->findOrFail((int) $memberIdOrRunId);
        }
        $query = SettlementRunMember::query()
            ->where('settlement_run_id', $memberIdOrRunId)
            ->where('agent_id', $legacyAgentId);
        if ($forUpdate) {
            $query->lockForUpdate();
        }

        return $query->first() ?? SettlementRunMember::query()->create([
            'settlement_run_id' => $memberIdOrRunId,
            'agent_id' => $legacyAgentId,
            'outcome' => 'pending',
        ]);
    }

    private function markExisting(SettlementRunMember $member, Settlement $settlement): void
    {
        $member->update([
            'settlement_id' => $settlement->id,
            'outcome' => 'existing',
            'error_message_key' => null,
            'error_parameters' => null,
            'processed_at' => now(),
        ]);
        $this->summary->update($member->run()->firstOrFail());
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
