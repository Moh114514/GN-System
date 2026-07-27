<?php

namespace App\Modules\Reminder\Application\Contracts;

use App\Modules\Reminder\Application\Data\FollowupImportData;
use DateTimeInterface;

interface FollowupImportGateway
{
    public function record(FollowupImportData $data): int;

    /** @return array<int, string> */
    public function rollbackBlockers(string $batchId, DateTimeInterface $completedAt): array;

    public function deleteImportedByBatch(string $batchId): int;
}
