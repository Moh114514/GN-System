<?php

namespace App\Modules\Auth\Application\Services;

use App\Infrastructure\Time\BusinessClock;
use App\Models\User;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Auth\Application\Contracts\BusinessGroupManagementGateway;
use App\Modules\Auth\Domain\UserRole;
use App\Modules\Auth\Infrastructure\Models\BusinessGroup;
use App\Modules\Auth\Infrastructure\Models\BusinessGroupMembership;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final readonly class DatabaseBusinessGroupManagementGateway implements BusinessGroupManagementGateway
{
    public function __construct(
        private AuditRecorder $audit,
        private BusinessClock $clock,
    ) {}

    /** @return array<int, array{id: int, code: string, name: string, is_active: bool}> */
    public function businessGroups(): array
    {
        return BusinessGroup::query()
            ->orderByDesc('is_active')
            ->orderBy('code')
            ->get()
            ->map(fn (BusinessGroup $group): array => [
                'id' => (int) $group->id,
                'code' => (string) $group->code,
                'name' => (string) $group->name,
                'is_active' => (bool) $group->is_active,
            ])
            ->all();
    }

    public function exists(int $businessGroupId, bool $activeOnly = true): bool
    {
        return BusinessGroup::query()
            ->whereKey($businessGroupId)
            ->when($activeOnly, fn ($query) => $query->where('is_active', true))
            ->exists();
    }

    /** @return array<int, array<string, mixed>> */
    public function memberships(?string $onDate = null): array
    {
        $date = $this->parseDate($onDate ?? $this->clock->now()->toDateString());

        return BusinessGroupMembership::query()
            ->with(['businessGroup', 'user'])
            ->orderByDesc('effective_from')
            ->orderBy('id')
            ->get()
            ->map(function (BusinessGroupMembership $membership) use ($date): array {
                return [
                    'id' => (int) $membership->id,
                    'business_group_id' => (int) $membership->business_group_id,
                    'group_code' => (string) ($membership->businessGroup->code ?? ''),
                    'group_name' => (string) ($membership->businessGroup->name ?? ''),
                    'user_id' => (int) $membership->user_id,
                    'user_name' => (string) ($membership->user->name ?? ''),
                    'member_role' => (string) $membership->member_role,
                    'effective_from' => $membership->effective_from->format('Y-m-d'),
                    'effective_until' => $membership->effective_until?->format('Y-m-d'),
                    'reason' => (string) $membership->reason,
                    'is_current' => $this->covers($membership->effective_from, $membership->effective_until, $date),
                ];
            })
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    public function unassignedUsers(?string $onDate = null): array
    {
        $date = $this->parseDate($onDate ?? $this->clock->now()->toDateString())->toDateString();

        return User::query()
            ->where('is_active', true)
            ->where('is_super_admin', false)
            ->whereIn('role', [UserRole::BdManager->value, UserRole::CustomerService->value])
            ->whereNotExists(function ($query) use ($date): void {
                $query->selectRaw('1')
                    ->from('business_group_memberships')
                    ->whereColumn('business_group_memberships.user_id', 'users.id')
                    ->whereDate('effective_from', '<=', $date)
                    ->where(function ($range) use ($date): void {
                        $range->whereNull('effective_until')->orWhereDate('effective_until', '>=', $date);
                    });
            })
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'role'])
            ->map(fn (User $user): array => [
                'id' => (int) $user->id,
                'name' => (string) $user->name,
                'email' => (string) $user->email,
                'role' => $user->roleValue()->value,
            ])
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    public function memberCandidates(?string $onDate = null): array
    {
        $date = $this->parseDate($onDate ?? $this->clock->now()->toDateString())->toDateString();
        $currentMemberships = BusinessGroupMembership::query()
            ->with('businessGroup')
            ->whereDate('effective_from', '<=', $date)
            ->where(function ($query) use ($date): void {
                $query->whereNull('effective_until')->orWhereDate('effective_until', '>=', $date);
            })
            ->get()
            ->keyBy('user_id');

        return User::query()
            ->where('is_active', true)
            ->where('is_super_admin', false)
            ->whereIn('role', [UserRole::BdManager->value, UserRole::CustomerService->value])
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'role'])
            ->map(function (User $user) use ($currentMemberships): array {
                /** @var BusinessGroupMembership|null $membership */
                $membership = $currentMemberships->get($user->id);

                return [
                    'id' => (int) $user->id,
                    'name' => (string) $user->name,
                    'email' => (string) $user->email,
                    'role' => $user->roleValue()->value,
                    'current_group_code' => $membership?->businessGroup?->code,
                    'current_group_name' => $membership?->businessGroup?->name,
                ];
            })
            ->all();
    }

    /** @return array{id: int, code: string, name: string, is_active: bool} */
    public function create(string $code, string $name, int $actorId, ?string $ipAddress): array
    {
        $code = strtoupper(trim($code));
        $name = trim($name);
        if (preg_match('/^[A-Z0-9][A-Z0-9_-]{1,31}$/D', $code) !== 1) {
            throw new DomainException(__('auth.errors.business_group_code_invalid'));
        }
        if ($name === '') {
            throw new DomainException(__('auth.errors.business_group_name_required'));
        }
        if (BusinessGroup::query()->where('code', $code)->exists()) {
            throw new DomainException(__('auth.errors.business_group_code_duplicate'));
        }

        $group = BusinessGroup::query()->create([
            'code' => $code,
            'name' => $name,
            'is_active' => true,
            'created_by' => $actorId,
        ]);
        $this->audit->record(
            description: __('auth.audit.business_group_created'),
            properties: ['code' => $group->code, 'name' => $group->name],
            causerId: $actorId,
            subject: $group,
            logName: 'auth-business-groups',
            event: 'created',
            ipAddress: $ipAddress,
        );

        return [
            'id' => (int) $group->id,
            'code' => (string) $group->code,
            'name' => (string) $group->name,
            'is_active' => (bool) $group->is_active,
        ];
    }

    /** @return array{id: int, code: string, name: string, is_active: bool} */
    public function updateName(int $businessGroupId, string $name, int $actorId, ?string $ipAddress): array
    {
        $name = trim($name);
        if ($name === '') {
            throw new DomainException(__('auth.errors.business_group_name_required'));
        }
        if (mb_strlen($name) > 255) {
            throw new DomainException(__('auth.errors.business_group_name_too_long'));
        }

        return DB::transaction(function () use ($businessGroupId, $name, $actorId, $ipAddress): array {
            $group = BusinessGroup::query()->lockForUpdate()->findOrFail($businessGroupId);
            $before = ['name' => $group->name];
            $group->update(['name' => $name]);
            $this->audit->record(
                description: __('auth.audit.business_group_updated'),
                properties: ['before' => $before, 'after' => ['name' => $name]],
                causerId: $actorId,
                subject: $group,
                logName: 'auth-business-groups',
                event: 'updated',
                ipAddress: $ipAddress,
            );

            return [
                'id' => (int) $group->id,
                'code' => (string) $group->code,
                'name' => (string) $group->name,
                'is_active' => (bool) $group->is_active,
            ];
        });
    }

    public function replaceBd(
        int $businessGroupId,
        int $newBdUserId,
        string $effectiveFrom,
        string $reason,
        int $actorId,
        ?string $ipAddress,
    ): void {
        $from = $this->parseDate($effectiveFrom);
        $reason = trim($reason);
        if ($reason === '') {
            throw new DomainException(__('auth.errors.business_group_reason_required'));
        }

        try {
            DB::transaction(function () use ($businessGroupId, $newBdUserId, $from, $reason, $actorId, $ipAddress): void {
                $group = BusinessGroup::query()->lockForUpdate()->findOrFail($businessGroupId);
                if (! $group->is_active) {
                    throw new DomainException(__('auth.errors.business_group_inactive'));
                }
                $newBd = User::query()->lockForUpdate()->findOrFail($newBdUserId);
                if (! $newBd->is_active || $newBd->roleValue() !== UserRole::BdManager) {
                    throw new DomainException(__('auth.errors.business_group_bd_required'));
                }

                $oldMembership = BusinessGroupMembership::query()
                    ->where('business_group_id', $group->id)
                    ->where('member_role', UserRole::BdManager->value)
                    ->whereDate('effective_from', '<=', $from->toDateString())
                    ->where(function ($query) use ($from): void {
                        $query->whereNull('effective_until')->orWhereDate('effective_until', '>=', $from->toDateString());
                    })
                    ->lockForUpdate()
                    ->latest('effective_from')
                    ->latest('id')
                    ->first();
                if ($oldMembership !== null && (int) $oldMembership->user_id === (int) $newBd->id) {
                    throw new DomainException(__('auth.errors.business_group_bd_same'));
                }
                if ($oldMembership !== null && $oldMembership->effective_from->gte($from)) {
                    throw new DomainException(__('auth.errors.business_group_bd_change_date_invalid'));
                }

                $newUserOverlap = BusinessGroupMembership::query()
                    ->where('user_id', $newBd->id)
                    ->whereDate('effective_from', '<=', $from->toDateString())
                    ->where(function ($query) use ($from): void {
                        $query->whereNull('effective_until')->orWhereDate('effective_until', '>=', $from->toDateString());
                    })
                    ->lockForUpdate()
                    ->exists();
                if ($newUserOverlap) {
                    throw new DomainException(__('auth.errors.business_group_user_overlap'));
                }

                if ($oldMembership !== null) {
                    $oldMembership->update(['effective_until' => $from->subDay()->toDateString()]);
                }
                $newMembership = BusinessGroupMembership::query()->create([
                    'business_group_id' => $group->id,
                    'user_id' => $newBd->id,
                    'member_role' => UserRole::BdManager->value,
                    'effective_from' => $from->toDateString(),
                    'effective_until' => null,
                    'assigned_by' => $actorId,
                    'reason' => $reason,
                ]);
                $this->audit->record(
                    description: __('auth.audit.business_group_bd_replaced'),
                    properties: [
                        'business_group_id' => $group->id,
                        'previous_user_id' => $oldMembership?->user_id,
                        'new_user_id' => $newBd->id,
                        'effective_from' => $from->toDateString(),
                        'reason' => $reason,
                    ],
                    causerId: $actorId,
                    subject: $newMembership,
                    logName: 'auth-business-groups',
                    event: 'bd_replaced',
                    ipAddress: $ipAddress,
                );
            });
        } catch (QueryException $exception) {
            if ($this->isMembershipOverlapViolation($exception)) {
                throw new DomainException(__('auth.errors.business_group_user_overlap'), previous: $exception);
            }

            throw $exception;
        }
    }

    public function deactivate(int $businessGroupId, string $reason, int $actorId, ?string $ipAddress): void
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new DomainException(__('auth.errors.business_group_reason_required'));
        }

        DB::transaction(function () use ($businessGroupId, $reason, $actorId, $ipAddress): void {
            $group = BusinessGroup::query()->lockForUpdate()->findOrFail($businessGroupId);
            if (! $group->is_active) {
                throw new DomainException(__('auth.errors.business_group_inactive'));
            }
            $effectiveUntil = $this->clock->now()->toDateString();
            $activeMemberships = BusinessGroupMembership::query()
                ->where('business_group_id', $group->id)
                ->whereDate('effective_from', '<=', $effectiveUntil)
                ->where(function ($query) use ($effectiveUntil): void {
                    $query->whereNull('effective_until')->orWhereDate('effective_until', '>=', $effectiveUntil);
                })
                ->lockForUpdate()
                ->get();
            foreach ($activeMemberships as $membership) {
                $membership->update(['effective_until' => $effectiveUntil]);
            }
            $group->update(['is_active' => false]);
            $this->audit->record(
                description: __('auth.audit.business_group_deactivated'),
                properties: [
                    'business_group_id' => $group->id,
                    'reason' => $reason,
                    'effective_until' => $effectiveUntil,
                    'ended_membership_count' => $activeMemberships->count(),
                ],
                causerId: $actorId,
                subject: $group,
                logName: 'auth-business-groups',
                event: 'deactivated',
                ipAddress: $ipAddress,
            );
        });
    }

    public function assignMember(
        int $businessGroupId,
        int $userId,
        string $effectiveFrom,
        ?string $effectiveUntil,
        string $reason,
        int $actorId,
        ?string $ipAddress,
    ): void {
        $from = $this->parseDate($effectiveFrom);
        $until = $effectiveUntil === null || trim($effectiveUntil) === '' ? null : $this->parseDate($effectiveUntil);
        $reason = trim($reason);
        if ($until !== null && $until->lt($from)) {
            throw new DomainException(__('auth.errors.business_group_date_order_invalid'));
        }
        if ($reason === '') {
            throw new DomainException(__('auth.errors.business_group_reason_required'));
        }

        try {
            DB::transaction(function () use ($businessGroupId, $userId, $from, $until, $reason, $actorId, $ipAddress): void {
                $group = BusinessGroup::query()->lockForUpdate()->findOrFail($businessGroupId);
                if (! $group->is_active) {
                    throw new DomainException(__('auth.errors.business_group_inactive'));
                }
                $user = User::query()->lockForUpdate()->findOrFail($userId);
                if (! $user->is_active) {
                    throw new DomainException(__('auth.errors.business_group_user_inactive'));
                }
                $role = $user->roleValue();
                if (! $role->isBusinessRole()) {
                    throw new DomainException(__('auth.errors.business_group_member_role_invalid'));
                }

                $overlap = BusinessGroupMembership::query()
                    ->where(function ($query) use ($businessGroupId, $userId, $role): void {
                        $query->where('user_id', $userId);
                        if ($role === UserRole::BdManager) {
                            $query->orWhere(function ($groupQuery) use ($businessGroupId, $role): void {
                                $groupQuery->where('business_group_id', $businessGroupId)
                                    ->where('member_role', $role->value);
                            });
                        }
                    })
                    ->whereDate('effective_from', '<=', $until?->toDateString() ?? '9999-12-31')
                    ->where(function ($query) use ($from): void {
                        $query->whereNull('effective_until')->orWhereDate('effective_until', '>=', $from->toDateString());
                    })
                    ->lockForUpdate()
                    ->exists();
                if ($overlap) {
                    throw new DomainException($role === UserRole::BdManager
                        ? __('auth.errors.business_group_bd_overlap')
                        : __('auth.errors.business_group_user_overlap'));
                }

                $membership = BusinessGroupMembership::query()->create([
                    'business_group_id' => $group->id,
                    'user_id' => $user->id,
                    'member_role' => $role->value,
                    'effective_from' => $from->toDateString(),
                    'effective_until' => $until?->toDateString(),
                    'assigned_by' => $actorId,
                    'reason' => $reason,
                ]);
                $this->audit->record(
                    description: __('auth.audit.business_group_member_assigned'),
                    properties: [
                        'business_group_id' => $group->id,
                        'user_id' => $user->id,
                        'member_role' => $role->value,
                        'effective_from' => $from->toDateString(),
                        'effective_until' => $until?->toDateString(),
                        'reason' => $reason,
                    ],
                    causerId: $actorId,
                    subject: $membership,
                    logName: 'auth-business-groups',
                    event: 'member_assigned',
                    ipAddress: $ipAddress,
                );
            });
        } catch (QueryException $exception) {
            if ($this->isMembershipOverlapViolation($exception)) {
                throw new DomainException(
                    str_contains($exception->getMessage(), 'business_group_memberships_bd_overlap_exclude')
                        ? __('auth.errors.business_group_bd_overlap')
                        : __('auth.errors.business_group_user_overlap'),
                    previous: $exception,
                );
            }

            throw $exception;
        }
    }

    public function endMembership(int $membershipId, string $effectiveUntil, string $reason, int $actorId, ?string $ipAddress): void
    {
        $until = $this->parseDate($effectiveUntil);
        $reason = trim($reason);
        if ($reason === '') {
            throw new DomainException(__('auth.errors.business_group_reason_required'));
        }

        DB::transaction(function () use ($membershipId, $until, $reason, $actorId, $ipAddress): void {
            $membership = BusinessGroupMembership::query()->lockForUpdate()->findOrFail($membershipId);
            if ($until->lt($membership->effective_from)) {
                throw new DomainException(__('auth.errors.business_group_date_order_invalid'));
            }
            $membership->update(['effective_until' => $until->toDateString()]);
            $this->audit->record(
                description: __('auth.audit.business_group_member_ended'),
                properties: ['membership_id' => $membership->id, 'effective_until' => $until->toDateString(), 'reason' => $reason],
                causerId: $actorId,
                subject: $membership,
                logName: 'auth-business-groups',
                event: 'member_ended',
                ipAddress: $ipAddress,
            );
        });
    }

    private function parseDate(string $value): CarbonImmutable
    {
        $value = trim($value);
        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);
        } catch (\Throwable) {
            $date = false;
        }
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new DomainException(__('auth.errors.business_group_date_invalid'));
        }

        return $date;
    }

    private function covers(CarbonInterface $from, ?CarbonInterface $until, CarbonInterface $date): bool
    {
        return $from->lte($date) && ($until === null || $until->gte($date));
    }

    private function isMembershipOverlapViolation(QueryException $exception): bool
    {
        return $exception->getCode() === '23P01'
            && str_contains($exception->getMessage(), 'business_group_memberships_');
    }
}
