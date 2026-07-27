<?php

namespace App\Modules\Settlement\Application\Contracts;

use App\Modules\Settlement\Application\Data\CommissionImportData;
use App\Modules\Settlement\Application\Data\SettlementImportData;
use DateTimeInterface;

interface SettlementImportGateway
{
    public function recordCommission(CommissionImportData $data): int;

    public function upsertSettlement(SettlementImportData $data): int;

    /** @return array<int, string> */
    public function rollbackBlockers(string $batchId, DateTimeInterface $completedAt): array;

    public function deleteImportedByBatch(string $batchId): int;
}
