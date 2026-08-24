<?php

namespace App\Modules\Auth\Application\Services;

use App\Infrastructure\Localization\SupportedLocale;
use App\Models\User;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Auth\Application\Contracts\UserManagementGateway;
use App\Modules\Auth\Domain\UserRole;
use App\Modules\Auth\Infrastructure\Notifications\InternalUserInvitationNotification;
use App\Modules\Auth\Infrastructure\Notifications\UserPasswordResetNotification;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Throwable;

final readonly class DatabaseUserManagementGateway implements UserManagementGateway
{
    public function __construct(private AuditRecorder $audit) {}

    public function users(): array
    {
        return User::query()->orderByDesc('is_super_admin')->orderBy('name')->get()
            ->map(fn (User $user): array => [
                'id' => (int) $user->id,
                'name' => (string) $user->name,
                'email' => (string) $user->email,
                'dingtalk_mention_type' => $user->dingtalk_mention_type,
                'dingtalk_mention_value' => $user->dingtalk_mention_value,
                'role' => $user->roleValue()->value,
                'is_super_admin' => $user->isSuperAdmin(),
                'is_active' => (bool) $user->is_active,
                'invitation_status' => (string) $user->invitation_status,
                'invitation_sent_at' => $user->invitation_sent_at?->format('Y-m-d H:i'),
            ])->all();
    }

    public function invite(string $name, string $email, bool $isSuperAdmin, int $actorId, ?string $ipAddress): array
    {
        return $this->inviteWithRole(
            $name,
            $email,
            $isSuperAdmin ? UserRole::SuperAdmin->value : UserRole::CustomerService->value,
            $actorId,
            $ipAddress,
        );
    }

    public function inviteWithRole(string $name, string $email, string $role, int $actorId, ?string $ipAddress): array
    {
        $userRole = UserRole::tryFrom($role);
        if ($userRole === null) {
            throw new DomainException(__('auth.errors.user_role_invalid'));
        }
        $inviter = User::query()->find($actorId);
        $preferredLocale = $inviter?->preferredLocale() ?? SupportedLocale::default()->value;

        $user = User::query()->create([
            'name' => trim($name),
            'email' => mb_strtolower(trim($email)),
            'password' => Str::password(48),
            'preferred_locale' => $preferredLocale,
            'is_super_admin' => $userRole === UserRole::SuperAdmin,
            'role' => $userRole,
            'is_active' => true,
            'invitation_status' => 'pending',
        ]);
        $status = $this->sendInvitation($user);
        $this->audit->record(
            description: '内部用户已创建并发送邀请',
            properties: ['user_id' => $user->id, 'role' => $userRole->value, 'invitation_status' => $status],
            causerId: $actorId,
            subject: $user,
            logName: 'auth-user-management',
            event: 'created',
            ipAddress: $ipAddress,
        );

        return ['id' => (int) $user->id, 'invitation_status' => $status];
    }

    public function resendInvitation(int $userId, int $actorId, ?string $ipAddress): string
    {
        $user = User::query()->findOrFail($userId);
        if ($user->invitation_status === 'accepted') {
            throw new DomainException(__('auth.errors.invitation_already_completed'));
        }
        $status = $this->sendInvitation($user);
        $this->audit->record(
            description: '内部用户邀请已重发',
            properties: ['user_id' => $user->id, 'invitation_status' => $status],
            causerId: $actorId,
            subject: $user,
            logName: 'auth-user-management',
            event: 'invitation_resent',
            ipAddress: $ipAddress,
        );

        return $status;
    }

    public function sendPasswordResetLink(int $userId, int $actorId, ?string $ipAddress): string
    {
        $user = User::query()->findOrFail($userId);
        if ($user->invitation_status !== 'accepted') {
            throw new DomainException(__('auth.errors.password_reset_not_available'));
        }

        $status = 'failed';
        try {
            $token = Password::broker()->createToken($user);
            $user->notify(new UserPasswordResetNotification($token, initiatedByAdministrator: true));
            $status = 'sent';
        } catch (Throwable $exception) {
            report($exception);
        }

        $this->audit->record(
            description: $status === 'sent' ? __('audit.messages.internal_user_password_reset_sent') : __('audit.messages.internal_user_password_reset_failed'),
            properties: ['user_id' => $user->id, 'status' => $status],
            causerId: $actorId,
            subject: $user,
            logName: 'auth-user-management',
            event: $status === 'sent' ? 'password_reset_requested' : 'password_reset_failed',
            ipAddress: $ipAddress,
            messageKey: $status === 'sent'
                ? 'audit.messages.internal_user_password_reset_sent'
                : 'audit.messages.internal_user_password_reset_failed',
        );

        return $status;
    }

    public function changeRole(int $userId, bool $isSuperAdmin, int $actorId, ?string $ipAddress): void
    {
        $this->setRole($userId, $isSuperAdmin ? UserRole::SuperAdmin->value : UserRole::CustomerService->value, $actorId, $ipAddress);
    }

    public function setRole(int $userId, string $role, int $actorId, ?string $ipAddress): void
    {
        $newRole = UserRole::tryFrom($role);
        if ($newRole === null) {
            throw new DomainException(__('auth.errors.user_role_invalid'));
        }

        DB::transaction(function () use ($userId, $newRole, $actorId, $ipAddress): void {
            $user = User::query()->lockForUpdate()->findOrFail($userId);
            $currentRole = $user->roleValue();
            if ($currentRole === UserRole::SuperAdmin && $newRole !== UserRole::SuperAdmin && $user->is_active
                && $this->activeSuperAdminCountForUpdate() <= 1) {
                throw new DomainException(__('auth.errors.last_super_admin_role'));
            }
            if ($currentRole !== $newRole && DB::table('business_group_memberships')
                ->where('user_id', $user->id)
                ->where(function ($query): void {
                    $query->whereNull('effective_until')->orWhereDate('effective_until', '>=', now()->toDateString());
                })
                ->exists()) {
                throw new DomainException(__('auth.errors.user_role_has_active_membership'));
            }
            $before = $currentRole->value;
            $user->update([
                'role' => $newRole,
                'is_super_admin' => $newRole === UserRole::SuperAdmin,
            ]);
            $this->audit->record(
                description: '内部用户角色已调整',
                properties: ['before' => $before, 'after' => $newRole->value],
                causerId: $actorId,
                subject: $user,
                logName: 'auth-user-management',
                event: 'role_changed',
                ipAddress: $ipAddress,
            );
        });
    }

    public function setActive(int $userId, bool $active, int $actorId, ?string $ipAddress): void
    {
        DB::transaction(function () use ($userId, $active, $actorId, $ipAddress): void {
            if ($userId === $actorId && ! $active) {
                throw new DomainException(__('auth.errors.current_account_disable'));
            }
            $user = User::query()->lockForUpdate()->findOrFail($userId);
            if ($user->isSuperAdmin() && $user->is_active && ! $active
                && $this->activeSuperAdminCountForUpdate() <= 1) {
                throw new DomainException(__('auth.errors.last_super_admin_disable'));
            }
            $before = (bool) $user->is_active;
            $user->update([
                'is_active' => $active,
                'session_version' => $active ? $user->session_version : $user->session_version + 1,
                'disabled_at' => $active ? null : now(),
                'disabled_by' => $active ? null : $actorId,
                'remember_token' => $active ? $user->remember_token : null,
            ]);
            if (! $active) {
                DB::table('sessions')->where('user_id', $user->id)->delete();
            }
            $this->audit->record(
                description: $active ? '内部用户已启用' : '内部用户已停用',
                properties: ['before' => $before, 'after' => $active],
                causerId: $actorId,
                subject: $user,
                logName: 'auth-user-management',
                event: $active ? 'enabled' : 'disabled',
                ipAddress: $ipAddress,
            );
        });
    }

    public function setDingTalkMention(int $userId, ?string $dingtalkMentionType, ?string $dingtalkMentionValue, int $actorId, ?string $ipAddress): void
    {
        $dingtalkMentionType = trim((string) $dingtalkMentionType);
        $dingtalkMentionValue = trim((string) $dingtalkMentionValue);
        if ($dingtalkMentionType !== '' && ! in_array($dingtalkMentionType, ['user_id', 'mobile'], true)) {
            throw new DomainException(__('auth.errors.dingtalk_mention_type_invalid'));
        }
        if ($dingtalkMentionValue !== '' && $dingtalkMentionType === '') {
            throw new DomainException(__('auth.errors.dingtalk_mention_type_required'));
        }
        if ($dingtalkMentionValue !== '' && mb_strlen($dingtalkMentionValue) > 255) {
            throw new DomainException(__('auth.errors.dingtalk_mention_value_too_long'));
        }
        if ($dingtalkMentionValue !== '' && ! $this->isValidDingTalkMentionValue($dingtalkMentionType, $dingtalkMentionValue)) {
            throw new DomainException(__('auth.errors.dingtalk_mention_value_invalid'));
        }
        $user = User::query()->findOrFail($userId);
        $before = [
            'type' => $user->dingtalk_mention_type,
            'value' => $user->dingtalk_mention_value,
        ];
        $user->update([
            'dingtalk_mention_type' => $dingtalkMentionValue === '' ? null : $dingtalkMentionType,
            'dingtalk_mention_value' => $dingtalkMentionValue === '' ? null : $dingtalkMentionValue,
        ]);
        $this->audit->record(
            description: __('auth.audit.dingtalk_mention_updated'),
            properties: [
                'user_id' => $userId,
                'before' => $before,
                'after' => ['type' => $user->dingtalk_mention_type, 'value' => $user->dingtalk_mention_value],
            ],
            causerId: $actorId,
            subject: $user,
            logName: 'auth-user-management',
            event: 'dingtalk_mention_updated',
            ipAddress: $ipAddress,
        );
    }

    private function sendInvitation(User $user): string
    {
        try {
            $token = Password::broker()->createToken($user);
            $user->notify(new InternalUserInvitationNotification($token));
            $user->update(['invitation_status' => 'sent', 'invitation_sent_at' => now()]);

            return 'sent';
        } catch (Throwable $exception) {
            report($exception);
            $user->update(['invitation_status' => 'failed', 'invitation_sent_at' => now()]);

            return 'failed';
        }
    }

    private function activeSuperAdminCountForUpdate(): int
    {
        return count(
            User::query()
                ->where('is_active', true)
                ->where(function ($query): void {
                    $query->where('role', UserRole::SuperAdmin->value)->orWhere('is_super_admin', true);
                })
                ->lockForUpdate()
                ->get(['id'])
                ->all(),
        );
    }

    private function isValidDingTalkMentionValue(string $type, string $value): bool
    {
        if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            return false;
        }

        return $type === 'mobile'
            ? preg_match('/^\d{6,20}$/D', $value) === 1
            : preg_match('/^[A-Za-z0-9][A-Za-z0-9._:\/+-]{0,254}$/D', $value) === 1;
    }
}
