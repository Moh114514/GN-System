<?php

namespace App\Modules\Agent\Application\Services;

use App\Modules\Agent\Application\Contracts\AgentImportGateway;
use App\Modules\Agent\Application\Data\AgentImportData;
use App\Modules\Agent\Application\Data\ResolvedAgentImportReference;
use App\Modules\Agent\Domain\AgentCodeNormalizer;
use App\Modules\Agent\Infrastructure\Models\Agent;
use App\Modules\Agent\Infrastructure\Models\AgentTypeCode;
use App\Modules\Agent\Infrastructure\Models\PolicyGrade;
use App\Modules\Agent\Infrastructure\Models\PolicySystem;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

final readonly class DatabaseAgentImportGateway implements AgentImportGateway
{
    public function __construct(private AgentCodeNormalizer $normalizer) {}

    public function activeAgentTypes(): array
    {
        return AgentTypeCode::query()
            ->where('is_active', true)
            ->orderBy('code')
            ->get(['code', 'name'])
            ->map(fn (AgentTypeCode $type): array => [
                'code' => $type->code,
                'name' => $type->name,
            ])
            ->all();
    }

    public function normalizeAgentCode(string $code): string
    {
        return $this->normalizer->agent($code);
    }

    public function normalizeCustomerCode(string $code): string
    {
        return $this->normalizer->customer($code);
    }

    public function resolveAgentId(string $codeOrName): ?int
    {
        return $this->resolveAgentReference($codeOrName)?->id;
    }

    public function resolveAgentReference(string $codeOrName): ?ResolvedAgentImportReference
    {
        $value = trim($codeOrName);
        if ($value === '') {
            return null;
        }

        try {
            $code = $this->normalizer->agent($value);
        } catch (\InvalidArgumentException) {
            $code = null;
        }

        /** @var array<string, Agent> $candidates */
        $candidates = [];
        $addCandidates = static function (iterable $agents) use (&$candidates): void {
            foreach ($agents as $agent) {
                $candidates[(string) $agent->id] = $agent;
            }
        };

        if ($code !== null) {
            $addCandidates(Agent::query()->where('code', $code)->get(['id', 'code', 'legacy_code', 'name']));
        }

        $addCandidates(Agent::query()->where('legacy_code', strtoupper($value))->get(['id', 'code', 'legacy_code', 'name']));
        $addCandidates(Agent::query()->where('name', $value)->get(['id', 'code', 'legacy_code', 'name']));

        $normalizedName = $this->normalizeAgentName($value);
        if ($normalizedName !== '') {
            $normalizedMatches = Agent::query()
                ->get(['id', 'code', 'legacy_code', 'name'])
                ->filter(fn (Agent $agent): bool => $this->normalizeAgentName($agent->name) === $normalizedName);
            $addCandidates($normalizedMatches);
        }

        if (count($candidates) > 1) {
            throw new \InvalidArgumentException("代理商匹配不唯一：{$value}");
        }

        return $candidates === [] ? null : $this->reference(array_values($candidates)[0]);
    }

    private function reference(Agent $agent): ResolvedAgentImportReference
    {
        return new ResolvedAgentImportReference(
            id: (int) $agent->id,
            code: (string) $agent->code,
            name: (string) $agent->name,
            legacyCode: $agent->legacy_code !== null ? (string) $agent->legacy_code : null,
        );
    }

    private function normalizeAgentName(string $value): string
    {
        $value = trim($value);
        if (class_exists(\Normalizer::class)) {
            $value = \Normalizer::normalize($value, \Normalizer::FORM_KC) ?: $value;
        }

        $value = str_replace(['（', '）'], ['(', ')'], $value);

        return strtoupper((string) preg_replace('/\s+/u', ' ', $value));
    }

    public function upsertAgent(AgentImportData $data): int
    {
        $normalizedCode = $this->normalizer->agent($data->code);
        $legacyCode = strtoupper(trim($data->code)) !== $normalizedCode
            ? strtoupper(trim($data->code))
            : null;
        $typeCode = str($normalizedCode)->afterLast('-')->toString();
        $type = AgentTypeCode::query()->where('code', $typeCode)->firstOrFail();

        $agent = Agent::query()->updateOrCreate(
            ['code' => $normalizedCode],
            [
                'agent_type_code_id' => $type->id,
                'legacy_code' => $legacyCode,
                'name' => trim($data->name),
                'business_role' => trim($data->businessRole),
                'contact_name' => $data->contactName,
                'contact_value' => $data->contactValue,
                'cooperation_started_on' => $data->cooperationStartedOn,
                'cooperation_status' => $data->cooperationStatus,
                'notes' => $data->notes,
                'import_batch_id' => $data->importBatchId,
            ],
        );

        if ($data->contractNumber !== null || $data->contractValidFrom !== null) {
            DB::table('agent_contracts')->updateOrInsert(
                ['agent_id' => $agent->id, 'number' => $data->contractNumber],
                [
                    'status' => $data->contractNumber === null ? 'pending' : 'active',
                    'valid_from' => $data->contractValidFrom,
                    'valid_until' => $data->contractValidUntil,
                    'import_batch_id' => $data->importBatchId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        }

        if ($data->policySystem !== null && $data->policyGrade !== null && $data->gradeEffectiveMonth !== null) {
            $system = PolicySystem::query()->firstOrCreate(
                ['name' => $data->policySystem],
                ['is_active' => true, 'import_batch_id' => $data->importBatchId],
            );
            $grade = PolicyGrade::query()->firstOrCreate(
                ['policy_system_id' => $system->id, 'name' => $data->policyGrade],
                ['is_active' => true, 'import_batch_id' => $data->importBatchId],
            );
            DB::table('agent_grade_assignments')->updateOrInsert(
                ['agent_id' => $agent->id, 'effective_month' => $data->gradeEffectiveMonth->startOfMonth()],
                [
                    'policy_grade_id' => $grade->id,
                    'reason' => '历史导入',
                    'import_batch_id' => $data->importBatchId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        }

        return $agent->id;
    }

    public function deleteImportedByBatch(string $batchId): int
    {
        DB::table('agent_grade_assignments')->where('import_batch_id', $batchId)->delete();
        DB::table('agent_contracts')->where('import_batch_id', $batchId)->delete();
        PolicyGrade::query()->where('import_batch_id', $batchId)->delete();
        PolicySystem::query()->where('import_batch_id', $batchId)->delete();

        return Agent::query()->where('import_batch_id', $batchId)->delete();
    }

    public function rollbackBlockers(string $batchId, DateTimeInterface $completedAt): array
    {
        $blockers = [];
        foreach ([
            'agents',
            'agent_contracts',
            'policy_systems',
            'policy_grades',
            'agent_grade_assignments',
        ] as $table) {
            $ids = DB::table($table)
                ->where('import_batch_id', $batchId)
                ->where('updated_at', '>', $completedAt)
                ->pluck('id');

            foreach ($ids as $id) {
                $blockers[] = "{$table}:{$id}";
            }
        }

        return $blockers;
    }
}
