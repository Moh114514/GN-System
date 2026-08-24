<?php

namespace App\Modules\Auth\Application\Services;

use App\Models\User;
use App\Modules\Auth\Application\Contracts\BusinessGroupMembershipReader;
use Carbon\CarbonImmutable;

final class DatabaseBusinessGroupMembershipReader implements BusinessGroupMembershipReader
{
    /**
     * @param  list<int>|null  $businessGroupIds
     * @return list<int>
     */
    public function activeCustomerServiceUserIds(?array $businessGroupIds = null, ?string $onDate = null): array
    {
        $date = $this->date($onDate);
        $query = User::query()
            ->where('is_active', true)
            ->where('invitation_status', 'accepted')
            ->where('is_super_admin', false)
            ->where('role', 'customer_service');

        if ($businessGroupIds !== null) {
            if ($businessGroupIds === []) {
                return [];
            }
            $query->whereIn('id', function ($membership) use ($businessGroupIds, $date): void {
                $membership->select('user_id')
                    ->from('business_group_memberships')
                    ->whereIn('business_group_id', $businessGroupIds)
                    ->where('member_role', 'customer_service')
                    ->whereDate('effective_from', '<=', $date)
                    ->where(function ($range) use ($date): void {
                        $range->whereNull('effective_until')->orWhereDate('effective_until', '>=', $date);
                    });
            });
        }

        return $query->orderBy('name')->pluck('id')->map(fn ($id): int => (int) $id)->all();
    }

    /** @param list<int>|null $businessGroupIds */
    public function isActiveCustomerServiceInGroups(int $userId, ?array $businessGroupIds = null, ?string $onDate = null): bool
    {
        return in_array($userId, $this->activeCustomerServiceUserIds($businessGroupIds, $onDate), true);
    }

    private function date(?string $value): string
    {
        if ($value !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
            try {
                $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);
                if ($date->format('Y-m-d') === $value) {
                    return $value;
                }
            } catch (\Throwable) {
                // Fall through to the business date.
            }
        }

        return now()->toDateString();
    }
}
