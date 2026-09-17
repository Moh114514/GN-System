<?php

namespace App\Modules\Auth\Application\Services;

use App\Models\User;
use App\Modules\Auth\Application\Contracts\InternalUserReferenceReader;

final class DatabaseInternalUserReferenceReader implements InternalUserReferenceReader
{
    public function eligibleUsers(): array
    {
        return User::query()
            ->where('is_active', true)
            ->where('invitation_status', 'accepted')
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (User $user): array => [
                'id' => (int) $user->id,
                'name' => (string) $user->name,
            ])
            ->values()
            ->all();
    }

    public function isEligible(int $id): bool
    {
        return User::query()
            ->whereKey($id)
            ->where('is_active', true)
            ->where('invitation_status', 'accepted')
            ->exists();
    }
}
