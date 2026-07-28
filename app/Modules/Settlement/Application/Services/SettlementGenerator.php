<?php

namespace App\Modules\Settlement\Application\Services;

use App\Modules\Agent\Application\Contracts\SettlementAgentGateway;
use App\Modules\Order\Application\Contracts\SettlementOrderReader;
use App\Modules\Settlement\Infrastructure\Models\OrderCommission;
use App\Modules\Settlement\Infrastructure\Models\Settlement;
use App\Modules\Settlement\Infrastructure\Models\SettlementGradeSuggestion;
use App\Modules\Settlement\Infrastructure\Models\SettlementRun;
use App\Modules\Settlement\Jobs\SendSettlementNotification;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

final readonly class SettlementGenerator
{
    public function __construct(
        private SettlementOrderReader $orders,
        private SettlementAgentGateway $agents,
    ) {}

    public function generate(string $runId, int $agentId): void
    {
        DB::transaction(function () use ($runId, $agentId): void {
            $run = SettlementRun::query()->lockForUpdate()->findOrFail($runId);
            $existing = Settlement::query()
                ->where('agent_id', $agentId)
                ->whereDate('period_start', $run->period_start)
                ->whereDate('period_end', $run->period_end)
                ->lockForUpdate()
                ->first();
            if ($existing !== null && $existing->settlement_run_id !== $runId) {
                throw new DomainException('该代理商周期已存在历史或其他月结记录，禁止覆盖。');
            }
            if ($existing !== null && $existing->status !== 'rejected') {
                return;
            }

            $periodStart = CarbonImmutable::parse($run->period_start);
            $periodEnd = CarbonImmutable::parse($run->period_end);
            $agent = $this->agents->forMonth($agentId, $periodEnd);
            $orders = $this->orders->completedForAgent($agentId, $periodStart, $periodEnd);
            $orderIds = array_map(fn ($order): int => $order->orderId, $orders);
            $commissions = OrderCommission::query()
                ->whereIn('order_id', $orderIds)
                ->get()
                ->keyBy('order_id');
            foreach ($orders as $order) {
                if (! $commissions->has($order->orderId)) {
                    throw new DomainException("已完成订单 {$order->orderId} 缺少推广费快照。");
                }
            }

            $totalConsumption = array_sum(array_map(fn ($order): int => $order->amountKrw, $orders));
            $totalCommission = (int) $commissions->sum('amount_krw');
            $snapshot = [
                'source' => 'phase_five_generation',
                'agent' => ['id' => $agent->id, 'code' => $agent->code, 'name' => $agent->name],
                'policy_system_id' => $agent->policySystemId,
                'policy_grade' => ['id' => $agent->currentGradeId, 'name' => $agent->currentGradeName],
                'generated_at' => now()->toIso8601String(),
            ];
            $settlement = Settlement::query()->updateOrCreate(
                ['agent_id' => $agentId, 'period_start' => $periodStart, 'period_end' => $periodEnd],
                [
                    'settlement_run_id' => $runId,
                    'total_consumption_krw' => $totalConsumption,
                    'total_commission_krw' => $totalCommission,
                    'payout_amount_cny_fen' => 0,
                    'status' => 'pending_review',
                    'snapshot' => $snapshot,
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
                        'order' => [
                            'id' => $order->orderId,
                            'customer_id' => $order->customerId,
                            'project_name' => $order->projectName,
                            'completed_on' => $order->completedOn->toDateString(),
                        ],
                    ], JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $recommended = $this->agents->recommendation($agentId, $periodEnd, $totalCommission);
            SettlementGradeSuggestion::query()->where('settlement_id', $settlement->id)->delete();
            if ($recommended->currentGradeId !== $agent->currentGradeId) {
                SettlementGradeSuggestion::query()->create([
                    'settlement_id' => $settlement->id,
                    'agent_id' => $agentId,
                    'current_grade_id' => $agent->currentGradeId,
                    'recommended_grade_id' => $recommended->currentGradeId,
                    'monthly_commission_krw' => $totalCommission,
                    'status' => 'pending',
                ]);
            }

            $this->markProcessed($run, $agentId, $totalConsumption, $totalCommission);
        }, 3);
    }

    public function markFailed(string $runId, int $agentId, Throwable $exception): void
    {
        DB::transaction(function () use ($runId, $agentId, $exception): void {
            $run = SettlementRun::query()->lockForUpdate()->findOrFail($runId);
            $errors = $run->errors ?? [];
            if (! array_key_exists((string) $agentId, $errors)) {
                $run->failed_agents++;
            }
            $errors[(string) $agentId] = $exception->getMessage();
            $run->errors = $errors;
            $run->save();
            $this->finishIfComplete($run);
        });
    }

    private function markProcessed(SettlementRun $run, int $agentId, int $consumption, int $commission): void
    {
        $errors = $run->errors ?? [];
        if (array_key_exists((string) $agentId, $errors)) {
            $errors = array_diff_key($errors, [(string) $agentId => true]);
            $run->failed_agents = max(0, $run->failed_agents - 1);
        }
        $run->processed_agents++;
        $run->total_consumption_krw += $consumption;
        $run->total_commission_krw += $commission;
        $run->errors = $errors === [] ? null : $errors;
        $run->save();
        Cache::put($run->progress_key, [
            'total' => $run->total_agents,
            'processed' => $run->processed_agents,
            'failed' => $run->failed_agents,
        ], now()->addDays(7));
        $this->finishIfComplete($run);
    }

    private function finishIfComplete(SettlementRun $run): void
    {
        if ($run->processed_agents + $run->failed_agents < $run->total_agents) {
            return;
        }
        $run->status = $run->failed_agents > 0 ? 'partial_failed' : 'completed';
        $run->completed_at = now();
        $run->save();
        if ($run->failed_agents === 0 && $run->notification_status === 'pending') {
            $run->update(['notification_status' => 'queued']);
            SendSettlementNotification::dispatch($run->id)->afterCommit();
        }
    }
}
