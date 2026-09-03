<?php

namespace App\Modules\Auth\Application\Services;

use App\Infrastructure\Time\BusinessClock;
use App\Models\User;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Auth\Domain\UserRole;
use App\Modules\Auth\Infrastructure\Models\BusinessGroupMembership;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

final class UserImpersonationService
{
    private const OWNER_SESSION_KEY = 'auth.impersonation.owner_user_id';

    private const TARGET_SESSION_KEY = 'auth.impersonation.target_user_id';

    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly BusinessClock $clock,
    ) {}

    public function isAvailable(): bool
    {
        return (bool) config('app.impersonation_enabled', false)
            && in_array((string) config('app.deployment_environment'), ['local', 'development', 'testing', 'uat'], true);
    }

    public function hasState(): bool
    {
        return $this->sessionId(self::OWNER_SESSION_KEY) !== null
            || $this->sessionId(self::TARGET_SESSION_KEY) !== null;
    }

    public function isActive(): bool
    {
        return $this->isAvailable()
            && $this->canImpersonate()
            && $this->targetUser() !== null;
    }

    public function canImpersonate(): bool
    {
        $owner = $this->realUser();

        return $this->isAvailable()
            && $owner instanceof User
            && $owner->is_active
            && $owner->invitation_status === 'accepted'
            && $owner->isSuperAdmin();
    }

    public function realUser(): ?User
    {
        $ownerId = $this->sessionId(self::OWNER_SESSION_KEY);

        return $ownerId === null
            ? Auth::user()
            : User::query()->find($ownerId);
    }

    public function targetUser(): ?User
    {
        if (! $this->isAvailable()) {
            return null;
        }

        $targetId = $this->sessionId(self::TARGET_SESSION_KEY);
        if ($targetId === null) {
            return null;
        }

        $target = User::query()->find($targetId);

        return $target instanceof User
            && $target->is_active
            && $target->invitation_status === 'accepted'
            && ! $target->isSuperAdmin()
            ? $target
            : null;
    }

    /**
     * @return array<string, list<array{id: int, name: string, email: string, groups: list<string>, role: string}>>
     */
    public function availableTargets(): array
    {
        if (! $this->canImpersonate()) {
            return [];
        }

        $date = $this->clock->now()->toDateString();
        $users = User::query()
            ->where('is_active', true)
            ->where('invitation_status', 'accepted')
            ->where('is_super_admin', false)
            ->where('role', '!=', UserRole::SuperAdmin->value)
            ->with(['businessGroupMemberships' => function ($query) use ($date): void {
                $query
                    ->whereDate('effective_from', '<=', $date)
                    ->where(function ($query) use ($date): void {
                        $query->whereNull('effective_until')->orWhereDate('effective_until', '>=', $date);
                    })
                    ->whereHas('businessGroup', fn ($query) => $query->where('is_active', true))
                    ->with('businessGroup:id,name');
            }])
            ->orderBy('role')
            ->orderBy('name')
            ->get();

        return $users
            ->map(function (User $user): array {
                $role = $user->roleValue()->value;
                $groups = $user->businessGroupMemberships
                    ->map(fn (BusinessGroupMembership $membership): ?string => $membership->businessGroup?->name)
                    ->filter()
                    ->unique()
                    ->values()
                    ->all();

                return [
                    'id' => (int) $user->id,
                    'name' => (string) $user->name,
                    'email' => (string) $user->email,
                    'groups' => $groups,
                    'role' => $role,
                ];
            })
            ->groupBy('role')
            ->map(fn (Collection $targets): array => $targets->values()->all())
            ->all();
    }

    public function start(User $target, ?string $ipAddress = null): void
    {
        if (! $this->canImpersonate()) {
            throw new AuthorizationException;
        }

        $owner = $this->realUser();
        if (! $owner instanceof User || $target->isSuperAdmin() || ! $target->is_active || $target->invitation_status !== 'accepted') {
            throw new AuthorizationException;
        }

        $this->session()->put([
            self::OWNER_SESSION_KEY => (int) $owner->id,
            self::TARGET_SESSION_KEY => (int) $target->id,
        ]);

        $this->audit->record(
            description: __('auth.audit.impersonation_started'),
            properties: [
                'real_user_id' => (int) $owner->id,
                'target_user_id' => (int) $target->id,
                'target_role' => $target->roleValue()->value,
            ],
            causerId: (int) $owner->id,
            subject: $target,
            logName: 'auth-impersonation',
            event: 'started',
            ipAddress: $ipAddress,
            messageKey: 'auth.audit.impersonation_started',
        );
    }

    public function stop(?string $ipAddress = null): void
    {
        if (! $this->isActive()) {
            throw new AuthorizationException;
        }

        $owner = $this->realUser();
        $target = $this->targetUser();
        if (! $owner instanceof User || ! $target instanceof User) {
            throw new AuthorizationException;
        }

        $this->audit->record(
            description: __('auth.audit.impersonation_stopped'),
            properties: [
                'real_user_id' => (int) $owner->id,
                'target_user_id' => (int) $target->id,
                'target_role' => $target->roleValue()->value,
            ],
            causerId: (int) $owner->id,
            subject: $target,
            logName: 'auth-impersonation',
            event: 'stopped',
            ipAddress: $ipAddress,
            messageKey: 'auth.audit.impersonation_stopped',
        );

        $this->clear();
        Auth::setUser($owner);
    }

    public function clear(): void
    {
        if (function_exists('request') && request()->hasSession()) {
            request()->session()->forget([
                self::OWNER_SESSION_KEY,
                self::TARGET_SESSION_KEY,
            ]);
        }
    }

    private function sessionId(string $key): ?int
    {
        if (! function_exists('request') || ! request()->hasSession()) {
            return null;
        }

        $value = request()->session()->get($key);

        return is_int($value) || (is_string($value) && ctype_digit($value))
            ? ((int) $value > 0 ? (int) $value : null)
            : null;
    }

    private function session(): Session
    {
        return request()->session();
    }
}
