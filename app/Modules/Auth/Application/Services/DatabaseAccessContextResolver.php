<?php

namespace App\Modules\Auth\Application\Services;

use App\Infrastructure\Time\BusinessClock;
use App\Models\User;
use App\Modules\Agent\Application\Contracts\AgentAccessScopeReader;
use App\Modules\Auth\Application\Contracts\AccessContextResolver;
use App\Modules\Auth\Application\Data\AccessContext;
use App\Modules\Auth\Domain\UserRole;
use App\Modules\Auth\Infrastructure\Models\BusinessGroupMembership;
use Closure;
use Illuminate\Support\Facades\Auth;

final class DatabaseAccessContextResolver implements AccessContextResolver
{
    private ?AccessContext $override = null;

    public function __construct(
        private readonly AgentAccessScopeReader $agents,
        private readonly BusinessClock $clock,
    ) {}

    public function current(): AccessContext
    {
        if ($this->override !== null) {
            return $this->override;
        }

        $user = Auth::user();
        if (! $user instanceof User) {
            return $this->unrestricted(null, 'console');
        }

        return $this->forUser($user);
    }

    public function forUser(User $user): AccessContext
    {
        $role = $user->roleValue();
        if ($user->isSuperAdmin() || $role === UserRole::SuperAdmin) {
            return $this->unrestricted((int) $user->id, $role->value);
        }

        if (! $user->is_active || $user->invitation_status !== 'accepted') {
            return $this->make((int) $user->id, $role->value, [], [], [], false);
        }

        $date = $this->clock->now()->toDateString();
        $groupIds = BusinessGroupMembership::query()
            ->where('user_id', $user->id)
            ->where('member_role', $role->value)
            ->whereDate('effective_from', '<=', $date)
            ->where(function ($query) use ($date): void {
                $query->whereNull('effective_until')->orWhereDate('effective_until', '>=', $date);
            })
            ->whereHas('businessGroup', fn ($query) => $query->where('is_active', true))
            ->pluck('business_group_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
        $groupUserIds = $groupIds === []
            ? []
            : BusinessGroupMembership::query()
                ->whereIn('business_group_id', $groupIds)
                ->whereDate('effective_from', '<=', $date)
                ->where(function ($query) use ($date): void {
                    $query->whereNull('effective_until')->orWhereDate('effective_until', '>=', $date);
                })
                ->pluck('user_id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->unique()
                ->values()
                ->all();
        $agentIds = $this->agents->agentIdsForBusinessGroups($groupIds, $date);

        return $this->make((int) $user->id, $role->value, $groupIds, $agentIds, $groupUserIds, false);
    }

    public function fromSnapshot(array $snapshot): AccessContext
    {
        $context = AccessContext::fromSnapshot($snapshot);
        if ($context->fingerprint !== '') {
            return $context;
        }

        return $this->make(
            $context->userId,
            $context->role,
            $context->businessGroupIds,
            $context->agentIds,
            $context->groupUserIds,
            $context->unrestricted,
        );
    }

    public function using(AccessContext $context, Closure $callback): mixed
    {
        $previous = $this->override;
        $this->override = $context;
        try {
            return $callback();
        } finally {
            $this->override = $previous;
        }
    }

    private function unrestricted(?int $userId, string $role): AccessContext
    {
        return $this->make($userId, $role, [], [], [], true);
    }

    /**
     * @param  list<int>  $groupIds
     * @param  list<int>  $agentIds
     * @param  list<int>  $groupUserIds
     */
    private function make(int|string|null $userId, string $role, array $groupIds, array $agentIds, array $groupUserIds, bool $unrestricted): AccessContext
    {
        $userId = $userId === null ? null : (int) $userId;
        sort($groupIds);
        sort($agentIds);
        sort($groupUserIds);
        $fingerprint = hash('sha256', json_encode([
            'user_id' => $userId,
            'role' => $role,
            'groups' => $groupIds,
            'agents' => $agentIds,
            'group_users' => $groupUserIds,
            'unrestricted' => $unrestricted,
        ], JSON_THROW_ON_ERROR));

        return new AccessContext($userId, $role, $groupIds, $agentIds, $groupUserIds, $unrestricted, $fingerprint);
    }
}
