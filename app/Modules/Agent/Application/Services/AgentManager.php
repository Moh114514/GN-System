<?php

namespace App\Modules\Agent\Application\Services;

use App\Modules\Agent\Application\Contracts\ConfigurationHistoryGateway;
use App\Modules\Agent\Application\Data\AgentProfileData;
use App\Modules\Agent\Domain\AgentCodeNormalizer;
use App\Modules\Agent\Infrastructure\Models\Agent;
use App\Modules\Agent\Infrastructure\Models\AgentGradeAssignment;
use App\Modules\Agent\Infrastructure\Models\AgentTypeCode;
use App\Modules\Agent\Infrastructure\Models\PolicyGrade;
use App\Modules\Agent\Infrastructure\Models\PolicySystem;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

final readonly class AgentManager
{
    public function __construct(
        private AgentCodeNormalizer $normalizer,
        private AuditRecorder $audit,
        private ConfigurationHistoryGateway $configurationHistory,
    ) {}

    public function create(AgentProfileData $data, int $actorId, ?string $ipAddress): int
    {
        return DB::transaction(function () use ($data, $actorId, $ipAddress): int {
            $type = AgentTypeCode::query()->where('is_active', true)->findOrFail($data->typeCodeId);
            if ($data->policyGradeId === null) {
                throw new DomainException(__('agents.validation.grade_required'));
            }
            $grade = PolicyGrade::query()->where('is_active', true)->findOrFail($data->policyGradeId);
            PolicySystem::query()->where('is_active', true)->findOrFail($grade->policy_system_id);
            $code = $this->normalizer->agent($data->codePrefix.'-'.$type->code, (string) $type->code);
            $agent = Agent::query()->create([
                'agent_type_code_id' => $type->id,
                'code' => $code,
                'name' => trim($data->name),
                'business_role' => $this->nullable($data->businessRole),
                'contact_name' => $this->nullable($data->contactName),
                'contact_value' => $this->nullable($data->contactValue),
                'cooperation_started_on' => $data->cooperationStartedOn,
                'cooperation_ended_on' => $data->cooperationEndedOn,
                'cooperation_status' => $data->cooperationStatus,
                'notes' => $this->nullable($data->notes),
            ]);
            AgentGradeAssignment::query()->create([
                'agent_id' => $agent->id,
                'policy_grade_id' => $grade->id,
                'effective_month' => CarbonImmutable::now()->startOfMonth(),
                'approved_by' => $actorId,
                'reason' => '代理商建档初始等级',
            ]);
            $this->audit->record(
                description: '代理商档案已创建',
                properties: ['after' => $agent->only(['code', 'name', 'agent_type_code_id', 'cooperation_status']), 'policy_grade_id' => $grade->id],
                causerId: $actorId,
                subject: $agent,
                logName: 'agent',
                event: 'created',
                ipAddress: $ipAddress,
            );

            return (int) $agent->id;
        });
    }

    public function update(int $agentId, AgentProfileData $data, int $actorId, ?string $ipAddress): void
    {
        DB::transaction(function () use ($agentId, $data, $actorId, $ipAddress): void {
            $agent = Agent::query()->lockForUpdate()->findOrFail($agentId);
            if ($agent->cooperation_status === 'terminated') {
                throw new DomainException(__('agents.validation.terminated_read_only'));
            }
            $type = AgentTypeCode::query()->where('is_active', true)->findOrFail($data->typeCodeId);
            $grade = $data->policyGradeId === null
                ? null
                : PolicyGrade::query()->where('is_active', true)->findOrFail($data->policyGradeId);
            $before = $agent->only(['name', 'agent_type_code_id', 'business_role', 'contact_name', 'cooperation_started_on', 'cooperation_ended_on', 'cooperation_status', 'notes']);
            $agent->update([
                'agent_type_code_id' => $type->id,
                'name' => trim($data->name),
                'business_role' => $this->nullable($data->businessRole),
                'contact_name' => $this->nullable($data->contactName),
                'contact_value' => $this->nullable($data->contactValue),
                'cooperation_started_on' => $data->cooperationStartedOn,
                'cooperation_ended_on' => $data->cooperationStatus === 'terminated' ? $data->cooperationEndedOn : null,
                'cooperation_status' => $data->cooperationStatus,
                'notes' => $this->nullable($data->notes),
            ]);
            $current = AgentGradeAssignment::query()
                ->where('agent_id', $agentId)
                ->whereDate('effective_month', '<=', CarbonImmutable::now()->startOfMonth())
                ->latest('effective_month')
                ->first();
            if ($current === null && $grade !== null) {
                throw new DomainException(__('agents.validation.grade_missing_correction_required'));
            }
            if ($current !== null && $grade === null) {
                throw new DomainException(__('agents.validation.grade_required'));
            }
            if ($current !== null && (int) $current->policy_grade_id !== (int) $grade->id) {
                AgentGradeAssignment::query()->updateOrCreate(
                    ['agent_id' => $agentId, 'effective_month' => CarbonImmutable::now()->addMonthNoOverflow()->startOfMonth()],
                    ['policy_grade_id' => $grade->id, 'approved_by' => $actorId, 'reason' => '代理商档案调整等级'],
                );
            }
            $this->audit->record(
                description: '代理商档案已更新',
                properties: ['before' => $before, 'after' => $agent->only(array_keys($before)), 'next_policy_grade_id' => $grade?->id],
                causerId: $actorId,
                subject: $agent,
                logName: 'agent',
                event: 'updated',
                ipAddress: $ipAddress,
            );
        });
    }

    public function correctGrade(
        int $agentId,
        int $policyGradeId,
        CarbonImmutable $effectiveMonth,
        string $reason,
        int $actorId,
        ?string $ipAddress,
    ): void {
        DB::transaction(function () use ($agentId, $policyGradeId, $effectiveMonth, $reason, $actorId, $ipAddress): void {
            $agent = Agent::query()->lockForUpdate()->findOrFail($agentId);
            if ($agent->cooperation_status === 'terminated') {
                throw new DomainException(__('agents.validation.terminated_read_only'));
            }
            $grade = PolicyGrade::query()->where('is_active', true)->findOrFail($policyGradeId);
            $month = $effectiveMonth->startOfMonth();
            $currentMonth = CarbonImmutable::now()->startOfMonth();
            if ($month->gt($currentMonth)) {
                throw new DomainException(__('agents.validation.correction_effective_month_invalid'));
            }
            $cooperationMonth = CarbonImmutable::parse($agent->cooperation_started_on)->startOfMonth();
            if ($month->lt($cooperationMonth)) {
                throw new DomainException(__('agents.validation.correction_before_cooperation'));
            }
            $reason = trim($reason);
            if ($reason === '') {
                throw new DomainException(__('agents.validation.correction_reason_required'));
            }
            $current = AgentGradeAssignment::query()
                ->where('agent_id', $agentId)
                ->whereDate('effective_month', '<=', $currentMonth)
                ->latest('effective_month')
                ->lockForUpdate()
                ->first();
            if ($current !== null) {
                throw new DomainException(__('historical_correction.agents.grade_correction_requires_missing_current'));
            }
            $assignment = AgentGradeAssignment::query()
                ->where('agent_id', $agentId)
                ->whereDate('effective_month', $month)
                ->lockForUpdate()
                ->first();
            if ($assignment !== null) {
                if ((int) $assignment->policy_grade_id === $grade->id && trim((string) $assignment->reason) === $reason) {
                    return;
                }
                throw new DomainException(__('agents.validation.grade_correction_conflict'));
            }
            $assignment = AgentGradeAssignment::query()->create([
                'agent_id' => $agentId,
                'policy_grade_id' => $grade->id,
                'effective_month' => $month,
                'approved_by' => $actorId,
                'reason' => $reason,
            ]);
            $this->audit->record(
                description: __('agents.audit.historical_grade_corrected'),
                properties: [
                    'agent_id' => $agentId,
                    'policy_grade_id' => $grade->id,
                    'effective_month' => $month->toDateString(),
                    'reason' => $reason,
                    'operation' => 'historical_grade_correction',
                ],
                causerId: $actorId,
                subject: $assignment,
                logName: 'agent-grade-history',
                event: 'historical_grade_corrected',
                ipAddress: $ipAddress,
                messageKey: 'agents.audit.historical_grade_corrected',
            );
        });
    }

    public function saveType(?int $id, string $code, string $name, ?string $description, int $actorId, ?string $ipAddress): void
    {
        $normalizedCode = strtoupper(trim($code));
        if (preg_match('/^[A-Z0-9]{2,4}$/', $normalizedCode) !== 1) {
            throw new DomainException(__('agents.validation.type_code_format'));
        }
        $type = $id === null ? new AgentTypeCode(['is_system' => false, 'is_active' => true]) : AgentTypeCode::query()->findOrFail($id);
        if ($type->exists && $type->is_system && $type->code !== $normalizedCode) {
            throw new DomainException(__('agents.validation.system_type_code_immutable'));
        }
        $this->configurationHistory->capture($actorId);
        $before = $type->exists ? $type->only(['code', 'name', 'description', 'is_active']) : null;
        $type->fill(['code' => $normalizedCode, 'name' => trim($name), 'description' => $this->nullable($description)])->save();
        $this->recordConfiguration('代理商类型代码已保存', 'audit.messages.agent_type_saved', $type, $before, $actorId, $ipAddress);
    }

    /** @return array{id: int, code: string, name: string, description: string|null} */
    public function type(int $id): array
    {
        $type = AgentTypeCode::query()->findOrFail($id);

        return [
            'id' => (int) $type->id,
            'code' => (string) $type->code,
            'name' => (string) $type->name,
            'description' => $type->description,
        ];
    }

    public function toggleType(int $id, int $actorId, ?string $ipAddress): void
    {
        $this->configurationHistory->capture($actorId);
        $type = AgentTypeCode::query()->findOrFail($id);
        $before = $type->only(['is_active']);
        $type->update(['is_active' => ! $type->is_active]);
        $this->recordConfiguration('代理商类型代码状态已变更', 'audit.messages.agent_type_status_changed', $type, $before, $actorId, $ipAddress);
    }

    public function savePolicy(?int $id, string $name, int $actorId, ?string $ipAddress): void
    {
        $this->configurationHistory->capture($actorId);
        $system = $id === null ? new PolicySystem(['is_active' => true]) : PolicySystem::query()->findOrFail($id);
        $before = $system->exists ? $system->only(['name', 'is_active']) : null;
        $system->fill(['name' => trim($name)])->save();
        $this->recordConfiguration('政策体系已保存', 'audit.messages.agent_policy_saved', $system, $before, $actorId, $ipAddress);
    }

    /** @return array{id: int, name: string} */
    public function policy(int $id): array
    {
        $system = PolicySystem::query()->findOrFail($id);

        return ['id' => (int) $system->id, 'name' => (string) $system->name];
    }

    public function togglePolicy(int $id, int $actorId, ?string $ipAddress): void
    {
        $this->configurationHistory->capture($actorId);
        $system = PolicySystem::query()->findOrFail($id);
        $before = $system->only(['is_active']);
        $system->update(['is_active' => ! $system->is_active]);
        $this->recordConfiguration('政策体系状态已变更', 'audit.messages.agent_policy_status_changed', $system, $before, $actorId, $ipAddress);
    }

    public function saveGrade(?int $id, int $policySystemId, string $name, int $thresholdKrw, int $sortOrder, int $actorId, ?string $ipAddress): void
    {
        PolicySystem::query()->findOrFail($policySystemId);
        $this->configurationHistory->capture($actorId);
        $grade = $id === null ? new PolicyGrade(['is_active' => true]) : PolicyGrade::query()->findOrFail($id);
        $before = $grade->exists ? $grade->only(['policy_system_id', 'name', 'monthly_threshold_krw', 'sort_order', 'is_active']) : null;
        $grade->fill([
            'policy_system_id' => $policySystemId,
            'name' => trim($name),
            'monthly_threshold_krw' => $thresholdKrw,
            'sort_order' => $sortOrder,
        ])->save();
        $this->recordConfiguration('政策等级已保存', 'audit.messages.agent_grade_saved', $grade, $before, $actorId, $ipAddress);
    }

    /** @return array{id: int, policy_system_id: int, name: string, monthly_threshold_krw: int, sort_order: int} */
    public function grade(int $id): array
    {
        $grade = PolicyGrade::query()->findOrFail($id);

        return [
            'id' => (int) $grade->id,
            'policy_system_id' => (int) $grade->policy_system_id,
            'name' => (string) $grade->name,
            'monthly_threshold_krw' => (int) $grade->monthly_threshold_krw,
            'sort_order' => (int) $grade->sort_order,
        ];
    }

    public function toggleGrade(int $id, int $actorId, ?string $ipAddress): void
    {
        $this->configurationHistory->capture($actorId);
        $grade = PolicyGrade::query()->findOrFail($id);
        $before = $grade->only(['is_active']);
        $grade->update(['is_active' => ! $grade->is_active]);
        $this->recordConfiguration('政策等级状态已变更', 'audit.messages.agent_grade_status_changed', $grade, $before, $actorId, $ipAddress);
    }

    /** @param array<string, mixed>|null $before */
    private function recordConfiguration(string $description, string $messageKey, Model $model, ?array $before, int $actorId, ?string $ipAddress): void
    {
        $this->audit->record(
            description: $description,
            properties: ['before' => $before, 'after' => $model->getAttributes()],
            causerId: $actorId,
            subject: $model,
            logName: 'agent-configuration',
            event: $before === null ? 'created' : 'updated',
            ipAddress: $ipAddress,
            messageKey: $messageKey,
        );
    }

    private function nullable(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
