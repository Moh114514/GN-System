<?php

namespace App\Modules\Agent\Application\Contracts;

use App\Modules\Agent\Application\Data\AgentImportData;
use DateTimeInterface;

interface AgentImportGateway
{
    /** @return array<int, array{code: string, name: string}> */
    public function activeAgentTypes(): array;

    public function normalizeAgentCode(string $code): string;

    public function normalizeCustomerCode(string $code): string;

    public function resolveAgentId(string $codeOrName): ?int;

    public function upsertAgent(AgentImportData $data): int;

    /** @return array<int, string> */
    public function rollbackBlockers(string $batchId, DateTimeInterface $completedAt): array;

    public function deleteImportedByBatch(string $batchId): int;
}
