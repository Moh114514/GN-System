<?php

namespace App\Modules\Auth\Application\Services;

use App\Models\User;
use App\Modules\Auth\Application\Contracts\BusinessGroupBdAttributionReader;
use App\Modules\Auth\Infrastructure\Models\BusinessGroupMembership;
use Carbon\CarbonImmutable;

final readonly class DatabaseBusinessGroupBdAttributionReader implements BusinessGroupBdAttributionReader
{
    public function forGroupOnDate(int $businessGroupId, CarbonImmutable $date): ?array
    {
        $membership = BusinessGroupMembership::query()
            ->with('user')
            ->where('business_group_id', $businessGroupId)
            ->where('member_role', 'bd_manager')
            ->whereDate('effective_from', '<=', $date->toDateString())
            ->where(function ($query) use ($date): void {
                $query->whereNull('effective_until')
                    ->orWhereDate('effective_until', '>=', $date->toDateString());
            })
            ->latest('effective_from')
            ->latest('id')
            ->first();
        if ($membership === null || ! $membership->user instanceof User) {
            return null;
        }

        return [
            'membership_id' => (int) $membership->id,
            'user_id' => (int) $membership->user_id,
            'user_name' => (string) $membership->user->name,
            'business_group_id' => $businessGroupId,
            'effective_from' => $membership->effective_from->format('Y-m-d'),
            'effective_until' => $membership->effective_until?->format('Y-m-d'),
            'occurred_on' => $date->toDateString(),
            'source' => 'business_group_membership',
        ];
    }
}
