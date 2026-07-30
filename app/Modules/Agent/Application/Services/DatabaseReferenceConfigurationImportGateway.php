<?php

namespace App\Modules\Agent\Application\Services;

use App\Modules\Agent\Application\Contracts\ReferenceConfigurationImportGateway;
use App\Modules\Agent\Domain\AgentCodeNormalizer;
use App\Modules\Agent\Infrastructure\Models\Agent;
use App\Modules\Agent\Infrastructure\Models\AgentGradeAssignment;
use App\Modules\Agent\Infrastructure\Models\AgentTypeCode;
use App\Modules\Agent\Infrastructure\Models\PolicyGrade;
use App\Modules\Agent\Infrastructure\Models\PolicySystem;
use Carbon\CarbonImmutable;
use RuntimeException;

final readonly class DatabaseReferenceConfigurationImportGateway implements ReferenceConfigurationImportGateway
{
    public function __construct(private AgentCodeNormalizer $normalizer) {}

    public function referenceKeys(): array
    {
        return [
            'type_codes' => AgentTypeCode::query()->pluck('code')->all(),
            'policy_systems' => PolicySystem::query()->pluck('name')->all(),
            'policy_grades' => PolicyGrade::query()
                ->join('policy_systems', 'policy_systems.id', '=', 'policy_grades.policy_system_id')
                ->get(['policy_systems.name as system_name', 'policy_grades.name as grade_name'])
                ->map(fn (PolicyGrade $grade): string => $grade->getAttribute('system_name').'|'.$grade->getAttribute('grade_name'))
                ->all(),
            'agent_codes' => Agent::query()->pluck('code')->all(),
        ];
    }

    public function upsertAgentTypes(array $rows, string $batchId): void
    {
        foreach ($rows as $row) {
            $type = AgentTypeCode::query()->firstOrNew(['code' => $row['code']]);
            $type->fill([
                'name' => $row['name'],
                'description' => $row['description'],
                'is_active' => $row['is_active'],
            ]);
            $type->is_system ??= false;
            $type->save();
        }
    }

    public function upsertPolicies(array $systems, array $grades, string $batchId): array
    {
        foreach ($systems as $row) {
            $system = PolicySystem::query()->firstOrNew(['name' => $row['name']]);
            $system->is_active = $row['is_active'];
            if (! $system->exists) {
                $system->import_batch_id = $batchId;
            }
            $system->save();
        }

        foreach ($grades as $row) {
            $system = PolicySystem::query()->where('name', $row['policy_system'])->firstOrFail();
            $grade = PolicyGrade::query()->firstOrNew([
                'policy_system_id' => $system->id,
                'name' => $row['name'],
            ]);
            $grade->fill([
                'monthly_threshold_krw' => $row['monthly_threshold_krw'],
                'sort_order' => $row['sort_order'],
                'is_active' => $row['is_active'],
            ]);
            if (! $grade->exists) {
                $grade->import_batch_id = $batchId;
            }
            $grade->save();
        }

        return PolicyGrade::query()
            ->join('policy_systems', 'policy_systems.id', '=', 'policy_grades.policy_system_id')
            ->get(['policy_grades.id', 'policy_systems.name as system_name', 'policy_grades.name as grade_name'])
            ->mapWithKeys(fn (PolicyGrade $grade): array => [
                $grade->getAttribute('system_name').'|'.$grade->getAttribute('grade_name') => $grade->id,
            ])
            ->all();
    }

    public function upsertAgents(array $rows, string $batchId): array
    {
        foreach ($rows as $row) {
            $code = $this->normalizer->agent($row['code']);
            $type = AgentTypeCode::query()->where('code', $row['type_code'])->firstOrFail();
            if (! str_ends_with($code, '-'.$type->code)) {
                throw new RuntimeException("代理商编号 {$code} 与类型 {$type->code} 不一致。");
            }

            $agent = Agent::query()->firstOrNew(['code' => $code]);
            $agent->fill([
                'agent_type_code_id' => $type->id,
                'legacy_code' => $row['legacy_code'],
                'name' => $row['name'],
                'business_role' => $row['business_role'],
                'contact_name' => $row['contact_name'],
                'contact_value' => $row['contact_value'],
                'cooperation_started_on' => $row['cooperation_started_on'],
                'cooperation_status' => $row['cooperation_status'],
                'notes' => $row['notes'],
            ]);
            if (! $agent->exists) {
                $agent->import_batch_id = $batchId;
            }
            $agent->save();
        }

        return Agent::query()->pluck('id', 'code')->all();
    }

    public function upsertGradeAssignments(array $rows, int $actorId, string $batchId): void
    {
        foreach ($rows as $row) {
            $agent = Agent::query()->where('code', $this->normalizer->agent($row['agent_code']))->firstOrFail();
            $system = PolicySystem::query()->where('name', $row['policy_system'])->firstOrFail();
            $grade = PolicyGrade::query()
                ->where('policy_system_id', $system->id)
                ->where('name', $row['policy_grade'])
                ->firstOrFail();
            $month = CarbonImmutable::parse($row['effective_month'])->startOfMonth();
            $assignment = AgentGradeAssignment::query()->firstOrNew([
                'agent_id' => $agent->id,
                'effective_month' => $month,
            ]);
            $assignment->fill([
                'policy_grade_id' => $grade->id,
                'approved_by' => $actorId,
                'reason' => $row['reason'],
            ]);
            if (! $assignment->exists) {
                $assignment->import_batch_id = $batchId;
            }
            $assignment->save();
        }
    }
}
