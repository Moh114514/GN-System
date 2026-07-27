<?php

namespace App\Modules\Reminder\Application\Services;

use App\Modules\Reminder\Application\Contracts\FollowupImportGateway;
use App\Modules\Reminder\Application\Data\FollowupImportData;
use App\Modules\Reminder\Infrastructure\Models\FollowupRecord;
use DateTimeInterface;

final class DatabaseFollowupImportGateway implements FollowupImportGateway
{
    public function record(FollowupImportData $data): int
    {
        return FollowupRecord::query()->create([
            'customer_id' => $data->customerId,
            'order_id' => $data->orderId,
            'type' => $data->type,
            'followed_up_on' => $data->followedUpOn,
            'content' => $data->content,
            'import_batch_id' => $data->importBatchId,
        ])->id;
    }

    public function deleteImportedByBatch(string $batchId): int
    {
        return FollowupRecord::query()->where('import_batch_id', $batchId)->delete();
    }

    public function rollbackBlockers(string $batchId, DateTimeInterface $completedAt): array
    {
        return FollowupRecord::query()
            ->where('import_batch_id', $batchId)
            ->where('updated_at', '>', $completedAt)
            ->pluck('id')
            ->map(fn (int $id): string => "followup_records:{$id}")
            ->all();
    }
}
