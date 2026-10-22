<?php

namespace App\Modules\Settlement\Application\Services;

use App\Modules\Agent\Application\Contracts\AgentCommissionContextReader;
use App\Modules\Agent\Application\Data\AgentCommissionContextData;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Settlement\Application\Contracts\DailyCommissionGateway;
use App\Modules\Settlement\Application\Data\CompletedOrderCommissionData;
use App\Modules\Settlement\Infrastructure\Models\AgentCommissionOverride;
use App\Modules\Settlement\Infrastructure\Models\CommissionRule;
use App\Modules\Settlement\Infrastructure\Models\OrderCommission;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

final readonly class DatabaseDailyCommissionGateway implements DailyCommissionGateway
{
    public function __construct(
        private AgentCommissionContextReader $agents,
        private AuditRecorder $audit,
    ) {}

    public function recordForCompletedOrder(CompletedOrderCommissionData $data): int
    {
        $existingId = OrderCommission::query()->where('order_id', $data->orderId)->value('id');
        if ($existingId !== null) {
            return (int) $existingId;
        }

        $resolved = $this->resolveRateForOrder($data->agentId, $data->institutionId, $data->completedOn);
        $context = $resolved['context'];
        $rateBps = $resolved['rate_bps'];
        $commissionAmount = BigDecimal::of($data->orderAmountKrw)
            ->multipliedBy($rateBps)
            ->dividedBy(10000, 0, RoundingMode::HalfUp)
            ->toInt();

        $commission = OrderCommission::query()->firstOrCreate(
            ['order_id' => $data->orderId],
            [
                'agent_id' => $data->agentId,
                'rate_bps' => $rateBps,
                'amount_krw' => $commissionAmount,
                'rule_snapshot' => [
                    'agent' => [
                        'id' => $context->agentId,
                        'code' => $context->agentCode,
                        'name' => $context->agentName,
                    ],
                    'policy_system' => [
                        'id' => $context->policySystemId,
                        'name' => $context->policySystemName,
                    ],
                    'policy_grade' => [
                        'id' => $context->policyGradeId,
                        'name' => $context->policyGradeName,
                        'assignment_id' => $context->assignmentId,
                        'effective_month' => $context->effectiveMonth->format('Y-m-d'),
                    ],
                    'institution_id' => $data->institutionId,
                    'rule_source' => $resolved['rule_source'],
                    'rule_id' => $resolved['rule_id'],
                    'rule_effective_month' => $resolved['effective_month'],
                    'rate_bps' => $rateBps,
                    'order_amount_krw' => $data->orderAmountKrw,
                    'commission_amount_krw' => $commissionAmount,
                    'calculated_at' => now()->toIso8601String(),
                ],
                'override_reason' => $resolved['override_reason'],
            ],
        );

        if ($commission->wasRecentlyCreated) {
            $this->audit->record(
                description: '推广费已核算',
                properties: $commission->rule_snapshot,
                causerId: $data->actorId,
                subject: $commission,
                logName: 'commission',
                event: 'calculated',
                ipAddress: $data->ipAddress,
            );
        }

        return (int) $commission->id;
    }

    public function previewForOrder(int $agentId, int $institutionId, CarbonImmutable $occurredOn): array
    {
        $resolved = $this->resolveRateForOrder($agentId, $institutionId, $occurredOn);
        $context = $resolved['context'];

        return [
            'agent_name' => $context->agentName,
            'grade_name' => $context->policyGradeName,
            'rate_bps' => $resolved['rate_bps'],
            'effective_month' => $resolved['effective_month'],
            'rule_source' => $resolved['rule_source'],
        ];
    }

    /** @return array{context: AgentCommissionContextData, rate_bps: int, rule_source: string, rule_id: int, effective_month: string, override_reason: string|null} */
    private function resolveRateForOrder(int $agentId, int $institutionId, CarbonImmutable $occurredOn): array
    {
        $context = $this->agents->forMonth($agentId, $occurredOn);
        $override = AgentCommissionOverride::query()
            ->where('agent_id', $agentId)
            ->where(function ($query) use ($institutionId): void {
                $query->where('institution_id', $institutionId)->orWhereNull('institution_id');
            })
            ->whereDate('effective_from', '<=', $occurredOn)
            ->where(function ($query) use ($occurredOn): void {
                $query->whereNull('effective_until')->orWhereDate('effective_until', '>=', $occurredOn);
            })
            ->orderByRaw('institution_id IS NULL')
            ->latest('effective_from')
            ->latest('id')
            ->first();
        $rule = null;
        if ($override === null) {
            $rule = CommissionRule::query()
                ->where('policy_grade_id', $context->policyGradeId)
                ->where('institution_id', $institutionId)
                ->where('is_active', true)
                ->whereDate('effective_month', '<=', $occurredOn->startOfMonth())
                ->latest('effective_month')
                ->latest('id')
                ->first();
        }
        if ($override === null && $rule === null) {
            throw new DomainException(__('settlements.errors.commission_rate_missing'));
        }
        if ($override !== null) {
            return [
                'context' => $context,
                'rate_bps' => (int) $override->rate_bps,
                'rule_source' => $override->institution_id === null ? 'agent_global_override' : 'agent_institution_override',
                'rule_id' => (int) $override->id,
                'effective_month' => $override->effective_from->startOfMonth()->format('Y-m-d'),
                'override_reason' => $override->reason,
            ];
        }

        return [
            'context' => $context,
            'rate_bps' => (int) $rule->rate_bps,
            'rule_source' => 'grade_institution_rule',
            'rule_id' => (int) $rule->id,
            'effective_month' => $rule->effective_month->format('Y-m-d'),
            'override_reason' => null,
        ];
    }

    public function rollbackForOrder(int $orderId): void
    {
        $commission = OrderCommission::query()->where('order_id', $orderId)->first();
        if ($commission === null) {
            return;
        }

        if (DB::table('settlement_items')->where('order_commission_id', $commission->id)->exists()) {
            throw new DomainException(__('settlements.errors.order_in_settlement'));
        }

        $commission->delete();
    }
}
