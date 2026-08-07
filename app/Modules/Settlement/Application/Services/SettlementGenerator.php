<?php

namespace App\Modules\Settlement\Application\Services;

use App\Infrastructure\Localization\SupportedLocale;
use App\Models\User;
use App\Modules\Agent\Application\Contracts\SettlementAgentGateway;
use App\Modules\Order\Application\Contracts\SettlementOrderReader;
use App\Modules\Settlement\Application\Exceptions\StructuredSettlementFailure;
use App\Modules\Settlement\Infrastructure\Models\OrderCommission;
use App\Modules\Settlement\Infrastructure\Models\Settlement;
use App\Modules\Settlement\Infrastructure\Models\SettlementGradeSuggestion;
use App\Modules\Settlement\Infrastructure\Models\SettlementRun;
use App\Modules\Settlement\Jobs\SendSettlementNotification;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
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
                throw new StructuredSettlementFailure('settlements.failure_reasons.existing_settlement');
            }
            $rebuild = false;
            if ($existing !== null) {
                if (! in_array($existing->generation_status, ['pending', 'unverified'], true)
                    || ! in_array($existing->status, ['pending_review', 'rejected'], true)) {
                    return;
                }
                $rebuild = true;
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
                    throw new StructuredSettlementFailure(
                        'settlements.failure_reasons.missing_commission_snapshot',
                        ['order_id' => $order->orderId],
                    );
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
                    'generation_status' => 'generated',
                    'generated_at' => now(),
                    'item_count' => count($orders),
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

            if (! $rebuild || $run->trigger_source === 'recovery') {
                $this->markProcessed($run, $agentId, $totalConsumption, $totalCommission);
            }
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
            if ($exception instanceof DomainException) {
                Log::warning('Settlement generation rejected by business rule.', [
                    'run_id' => $runId,
                    'agent_id' => $agentId,
                    'message' => $exception->getMessage(),
                ]);
                $errorMessage = $this->structuredFailure($exception);
            } else {
                $reference = (string) Str::uuid();
                Log::error('Settlement generation failed.', [
                    'reference' => $reference,
                    'run_id' => $runId,
                    'agent_id' => $agentId,
                    'exception' => $exception,
                ]);
                $errorMessage = [
                    'message_key' => 'settlements.failure_reasons.unexpected',
                    'parameters' => ['reference' => $reference],
                ];
            }
            $errors[(string) $agentId] = $errorMessage;
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

    /** @return array{message_key: string, parameters: array<string, scalar>} */
    private function structuredFailure(DomainException $exception): array
    {
        if ($exception instanceof StructuredSettlementFailure) {
            return [
                'message_key' => $exception->messageKey,
                'parameters' => $exception->parameters,
            ];
        }

        if (in_array($exception->getMessage(), [
            __('agents.validation.no_effective_policy_grade', [], 'zh_CN'),
            __('agents.validation.no_effective_policy_grade', [], 'ko_KR'),
        ], true)) {
            return [
                'message_key' => 'settlements.failure_reasons.agent_policy_missing',
                'parameters' => [],
            ];
        }

        return [
            'message_key' => 'settlements.failure_reasons.business_rule',
            'parameters' => [],
        ];
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
            $user = $run->initiated_by === null ? null : User::query()->find($run->initiated_by);
            $locale = (SupportedLocale::fromCandidate($user?->preferred_locale) ?? SupportedLocale::default())->value;
            SendSettlementNotification::dispatch($run->id, $locale)->afterCommit();
        }
    }
}
