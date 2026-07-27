<?php

namespace App\Modules\Reminder\Application\Services;

use App\Modules\Reminder\Application\Contracts\CustomerFollowupGateway;
use App\Modules\Reminder\Application\Data\CustomerFollowupData;
use App\Modules\Reminder\Infrastructure\Models\FollowupRecord;
use Carbon\CarbonImmutable;

final class DatabaseCustomerFollowupGateway implements CustomerFollowupGateway
{
    public function record(CustomerFollowupData $data): int
    {
        return FollowupRecord::query()->create([
            'customer_id' => $data->customerId,
            'type' => $data->type,
            'followed_up_on' => $data->followedUpOn,
            'content' => $data->content,
            'owner_id' => $data->ownerId,
        ])->id;
    }

    public function timelineForCustomer(int $customerId): array
    {
        return FollowupRecord::query()
            ->where('customer_id', $customerId)
            ->orderByDesc('followed_up_on')
            ->get()
            ->map(fn (FollowupRecord $record): array => [
                'type' => 'followup',
                'occurred_at' => $record->followed_up_on === null
                    ? $record->created_at?->toIso8601String()
                    : CarbonImmutable::parse($record->followed_up_on)->startOfDay()->toIso8601String(),
                'title' => '客户跟进',
                'content' => $record->content,
                'owner_id' => $record->owner_id === null ? null : (int) $record->owner_id,
                'meta' => ['followup_type' => $record->type],
            ])
            ->all();
    }
}
