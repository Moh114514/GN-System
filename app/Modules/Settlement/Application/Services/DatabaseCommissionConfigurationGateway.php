<?php

namespace App\Modules\Settlement\Application\Services;

use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Settlement\Application\Contracts\CommissionConfigurationGateway;
use App\Modules\Settlement\Application\Contracts\ConfigurationHistoryGateway;
use App\Modules\Settlement\Application\Data\HistoricalCommissionRuleData;
use App\Modules\Settlement\Infrastructure\Models\AgentCommissionOverride;
use App\Modules\Settlement\Infrastructure\Models\CommissionRule;
use Carbon\CarbonImmutable;
use DomainException;

final readonly class DatabaseCommissionConfigurationGateway implements CommissionConfigurationGateway
{
    public function __construct(
        private AuditRecorder $audit,
        private ConfigurationHistoryGateway $configurationHistory,
    ) {}

    public function configuration(): array
    {
        return [
            'rules' => CommissionRule::query()->orderByDesc('effective_month')->orderBy('policy_grade_id')->get()->toArray(),
            'overrides' => AgentCommissionOverride::query()->orderByDesc('effective_from')->orderBy('agent_id')->get()->toArray(),
        ];
    }

    public function saveRule(
        int $policyGradeId,
        int $institutionId,
        int $rateBps,
        CarbonImmutable $effectiveMonth,
        int $actorId,
        ?string $ipAddress,
        bool $isActive = true,
    ): void {
        $month = $this->validateMonthAndRate($effectiveMonth, $rateBps);
        $this->configurationHistory->capture($actorId);
        $rule = CommissionRule::query()->where([
            'policy_grade_id' => $policyGradeId,
            'institution_id' => $institutionId,
            'effective_month' => $month,
        ])->first();
        if ($rule !== null && $month->isCurrentMonth()) {
            throw new DomainException(__('settlements.errors.institution_rate_month_locked'));
        }
        $before = $rule?->only(['rate_bps', 'effective_month', 'is_active']);
        $rule ??= new CommissionRule([
            'policy_grade_id' => $policyGradeId,
            'institution_id' => $institutionId,
            'effective_month' => $month,
        ]);
        $rule->fill(['rate_bps' => $rateBps, 'is_active' => $isActive])->save();
        $this->audit->record(
            description: '机构推广费率已保存',
            properties: ['before' => $before, 'after' => $rule->only(['rate_bps', 'effective_month', 'is_active'])],
            causerId: $actorId,
            subject: $rule,
            logName: 'commission-configuration',
            event: $before === null ? 'created' : 'updated',
            ipAddress: $ipAddress,
        );
    }

    public function saveOverride(
        int $agentId,
        ?int $institutionId,
        int $rateBps,
        CarbonImmutable $effectiveMonth,
        string $reason,
        int $actorId,
        ?string $ipAddress,
    ): void {
        $month = $this->validateMonthAndRate($effectiveMonth, $rateBps);
        $this->configurationHistory->capture($actorId);
        $query = AgentCommissionOverride::query()->where('agent_id', $agentId);
        $institutionId === null ? $query->whereNull('institution_id') : $query->where('institution_id', $institutionId);
        $override = $query->whereDate('effective_from', $month)->first();
        if ($override !== null && $month->isCurrentMonth()) {
            throw new DomainException(__('settlements.errors.agent_override_month_locked'));
        }
        $before = $override?->only(['rate_bps', 'effective_from', 'effective_until', 'reason']);
        if ($override === null) {
            $previousQuery = AgentCommissionOverride::query()
                ->where('agent_id', $agentId)
                ->whereDate('effective_from', '<', $month)
                ->where(function ($builder) use ($month): void {
                    $builder->whereNull('effective_until')->orWhereDate('effective_until', '>=', $month);
                });
            $institutionId === null
                ? $previousQuery->whereNull('institution_id')
                : $previousQuery->where('institution_id', $institutionId);
            $previous = $previousQuery->latest('effective_from')->first();
            if ($previous !== null) {
                $previousBefore = $previous->only(['rate_bps', 'effective_from', 'effective_until', 'reason']);
                $previous->update(['effective_until' => $month->subDay()]);
                $this->audit->record(
                    description: '代理商推广费特批有效期已截止',
                    properties: ['before' => $previousBefore, 'after' => $previous->only(['rate_bps', 'effective_from', 'effective_until', 'reason'])],
                    causerId: $actorId,
                    subject: $previous,
                    logName: 'commission-configuration',
                    event: 'updated',
                    ipAddress: $ipAddress,
                );
            }

            $override = new AgentCommissionOverride([
                'agent_id' => $agentId,
                'institution_id' => $institutionId,
                'effective_from' => $month,
            ]);
        }
        $override->fill([
            'rate_bps' => $rateBps,
            'effective_until' => null,
            'reason' => trim($reason),
            'approved_by' => $actorId,
        ])->save();
        $this->audit->record(
            description: '代理商推广费特批已保存',
            properties: ['before' => $before, 'after' => $override->only(['rate_bps', 'effective_from', 'effective_until', 'reason'])],
            causerId: $actorId,
            subject: $override,
            logName: 'commission-configuration',
            event: $before === null ? 'created' : 'updated',
            ipAddress: $ipAddress,
        );
    }

    public function importHistoricalCorrectionRule(HistoricalCommissionRuleData $data): void
    {
        $month = $this->validateRate($data->rateBps, $data->effectiveMonth);
        if (! $month->lt(CarbonImmutable::now()->startOfMonth())) {
            throw new DomainException(__('settlements.errors.historical_rate_month_invalid'));
        }
        $rule = CommissionRule::query()->where([
            'policy_grade_id' => $data->policyGradeId,
            'institution_id' => $data->institutionId,
            'effective_month' => $month,
        ])->lockForUpdate()->first();

        if ($rule !== null) {
            if ((int) $rule->rate_bps === $data->rateBps && (bool) $rule->is_active === $data->isActive) {
                return;
            }

            throw new DomainException(__('settlements.errors.historical_rate_conflict'));
        }

        $this->configurationHistory->capture($data->actorId);
        $rule = CommissionRule::query()->create([
            'policy_grade_id' => $data->policyGradeId,
            'institution_id' => $data->institutionId,
            'rate_bps' => $data->rateBps,
            'effective_month' => $month,
            'is_active' => $data->isActive,
            'import_batch_id' => $data->importBatchId,
        ]);
        $this->audit->record(
            description: __('settlements.audit.historical_rate_imported'),
            properties: [
                'import_batch_id' => $data->importBatchId,
                'reason' => $data->reason,
                'after' => $rule->only(['policy_grade_id', 'institution_id', 'rate_bps', 'effective_month', 'is_active', 'import_batch_id']),
            ],
            causerId: $data->actorId,
            subject: $rule,
            logName: 'commission-configuration',
            event: 'historical_correction_imported',
            ipAddress: $data->ipAddress,
            messageKey: 'settlements.audit.historical_rate_imported',
        );
    }

    private function validateMonthAndRate(CarbonImmutable $effectiveMonth, int $rateBps): CarbonImmutable
    {
        $month = $this->validateRate($rateBps, $effectiveMonth);
        if ($month->lt(CarbonImmutable::now()->startOfMonth())) {
            throw new DomainException(__('settlements.errors.closed_month_rate_locked'));
        }

        return $month;
    }

    private function validateRate(int $rateBps, CarbonImmutable $effectiveMonth): CarbonImmutable
    {
        if ($rateBps < 0 || $rateBps > 10000) {
            throw new DomainException(__('settlements.errors.rate_out_of_range'));
        }

        return $effectiveMonth->startOfMonth();
    }
}
