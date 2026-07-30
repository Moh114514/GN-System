<?php

namespace App\Modules\Agent\Application\Contracts;

interface ReferenceConfigurationImportGateway
{
    /**
     * @return array{
     *     type_codes: array<int, string>,
     *     policy_systems: array<int, string>,
     *     policy_grades: array<int, string>,
     *     agent_codes: array<int, string>
     * }
     */
    public function referenceKeys(): array;

    /** @param array<int, array<string, mixed>> $rows */
    public function upsertAgentTypes(array $rows, string $batchId): void;

    /**
     * @param  array<int, array<string, mixed>>  $systems
     * @param  array<int, array<string, mixed>>  $grades
     * @return array<string, int>
     */
    public function upsertPolicies(array $systems, array $grades, string $batchId): array;

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, int>
     */
    public function upsertAgents(array $rows, string $batchId): array;

    /** @param array<int, array<string, mixed>> $rows */
    public function upsertGradeAssignments(array $rows, int $actorId, string $batchId): void;
}
