<?php

namespace App\Modules\Settlement\Application\Services;

use App\Modules\Settlement\Infrastructure\Models\Settlement;
use App\Modules\Settlement\Infrastructure\Models\SettlementAdjustment;
use App\Modules\Settlement\Infrastructure\Models\SettlementRunMember;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Throwable;

final readonly class SettlementGenerator
{
    public function __construct(
        private SettlementCalculationService $calculation,
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
            $calculation = $this->calculation->calculate((int) $member->agent_id, $periodStart, $periodEnd, lockAdjustments: true);
            $agent = $calculation['agent'];
            $orders = $calculation['orders'];
            $commissions = $calculation['commissions'];
            $adjustments = $calculation['adjustments'];
            $totalConsumption = $calculation['total_consumption_krw'];
            $totalCommission = $calculation['total_commission_krw'];
            $settlement = Settlement::query()->updateOrCreate(
                ['agent_id' => $member->agent_id, 'period_start' => $periodStart, 'period_end' => $periodEnd],
                [
                    'settlement_run_id' => $run->id,
                    'payment_due_on' => $periodEnd->addMonthNoOverflow()->startOfMonth()->addDays(9)->toDateString(),
                    'total_consumption_krw' => $totalConsumption,
                    'total_commission_krw' => $totalCommission,
                    'payout_amount_cny_fen' => 0,
                    'status' => 'pending_review',
                    'generation_status' => 'generated',
                    'generated_at' => now(),
                    'item_count' => $calculation['item_count'],
                    'snapshot' => [
                        'source' => 'phase_five_generation',
                        'agent' => ['id' => $agent->id, 'code' => $agent->code, 'name' => $agent->name],
                        'policy_system_id' => $agent->policySystemId,
                        'policy_grade' => ['id' => $agent->currentGradeId, 'name' => $agent->currentGradeName],
                        'generated_at' => now()->toIso8601String(),
                        'adjustment_count' => $adjustments->count(),
                        'adjustments' => $adjustments->map(static fn (SettlementAdjustment $adjustment): array => [
                            'id' => (int) $adjustment->id,
                            'key' => (string) $adjustment->adjustment_key,
                            'consumption_delta_krw' => (int) $adjustment->consumption_delta_krw,
                            'commission_delta_krw' => (int) $adjustment->commission_delta_krw,
                            'reason' => (string) $adjustment->reason,
                        ])->all(),
                    ],
                    'reviewed_by' => null,
                    'reviewed_at' => null,
                    'rejection_reason' => null,
                ],
            );
            if ($adjustments->isNotEmpty()) {
                SettlementAdjustment::query()
                    ->whereKey($adjustments->modelKeys())
                    ->update(['target_settlement_id' => $settlement->id]);
            }
            $this->updateCarryForward($settlement, $totalCommission, $periodEnd);
            DB::table('settlement_items')->where('settlement_id', $settlement->id)->delete();
            foreach ($orders as $order) {
                $commission = $commissions[$order->orderId];
                DB::table('settlement_items')->insert([
                    'settlement_id' => $settlement->id,
                    'order_commission_id' => $commission->id,
                    'consumption_krw' => $order->amountKrw,
                    'commission_krw' => $commission->amount_krw,
                    'rule_snapshot' => json_encode([
                        ...$commission->rule_snapshot,
                        'order' => ['id' => $order->orderId, 'customer_id' => $order->customerId, 'project_name' => $order->projectName, 'occurred_on' => $order->completedOn->toDateString(), 'completed_on' => $order->completedOn->toDateString()],
                    ], JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                    'updated_at' => now(),
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

    private function updateCarryForward(Settlement $settlement, int $netCommission, CarbonImmutable $periodEnd): void
    {
        $key = 'carry:settlement:'.$settlement->id;
        $carry = SettlementAdjustment::query()->where('adjustment_key', $key)->lockForUpdate()->first();
        $nextPeriodStart = $periodEnd->addDay()->startOfMonth()->toDateString();
        if ($carry !== null) {
            $target = $carry->target_settlement_id === null
                ? null
                : Settlement::query()->lockForUpdate()->find($carry->target_settlement_id);
            $expectedCarry = min(0, $netCommission);
            if ($target !== null && ! in_array($target->status, ['pending_review', 'rejected'], true)
                && (int) $carry->commission_delta_krw !== $expectedCarry) {
                throw new DomainException(__('settlements.errors.carry_forward_target_locked'));
            }
            if ((int) $carry->commission_delta_krw !== $expectedCarry
                || $carry->eligible_period_start->toDateString() !== $nextPeriodStart) {
                $carry->update([
                    'commission_delta_krw' => $expectedCarry,
                    'eligible_period_start' => $nextPeriodStart,
                    'snapshot' => [...(array) $carry->snapshot, 'balance_krw' => $expectedCarry],
                ]);
            }

            return;
        }
        if ($netCommission >= 0) {
            return;
        }

        SettlementAdjustment::query()->create([
            'agent_id' => $settlement->agent_id,
            'source_order_id' => null,
            'order_commission_id' => null,
            'original_settlement_id' => $settlement->id,
            'target_settlement_id' => null,
            'adjustment_key' => $key,
            'eligible_period_start' => $nextPeriodStart,
            'consumption_delta_krw' => 0,
            'commission_delta_krw' => $netCommission,
            'reason' => __('settlements.adjustments.negative_balance_carry'),
            'created_by' => null,
            'snapshot' => [
                'source' => 'negative_balance_carry',
                'source_settlement_id' => (int) $settlement->id,
                'balance_krw' => $netCommission,
            ],
        ]);
    }

    /** Supports the pre-member API during the compatibility window. */
    public function markFailed(string $memberIdOrRunId, int|Throwable $agentIdOrException, ?Throwable $legacyException = null): void
    {
        $exception = $legacyException ?? ($agentIdOrException instanceof Throwable ? $agentIdOrException : null);
        if (! $exception instanceof Throwable) {
            throw new DomainException('A settlement generation failure must include an exception.');
        }
        app(SettlementFailureRecorder::class)->record(
            $memberIdOrRunId,
            $exception,
            $legacyException === null ? null : (int) $agentIdOrException,
        );
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
}
