<?php

namespace App\Modules\Order\Application\Contracts;

use App\Modules\Order\Application\Data\OrderImportData;
use DateTimeInterface;

interface OrderImportGateway
{
    public function upsertOrder(OrderImportData $data): int;

    /** @return array<int, string> */
    public function rollbackBlockers(string $batchId, DateTimeInterface $completedAt): array;

    public function deleteImportedByBatch(string $batchId): int;
}
