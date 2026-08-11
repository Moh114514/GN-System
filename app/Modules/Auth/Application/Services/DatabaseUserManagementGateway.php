<?php

namespace App\Modules\Auth\Application\Services;

use App\Infrastructure\Localization\SupportedLocale;
use App\Models\User;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Auth\Application\Contracts\UserManagementGateway;
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
                'is_super_admin' => (bool) $user->is_super_admin,
                'is_active' => (bool) $user->is_active,
                'invitation_status' => (string) $user->invitation_status,
                'invitation_sent_at' => $user->invitation_sent_at?->format('Y-m-d H:i'),
            ])->all();
    }

    public function invite(string $name, string $email, bool $isSuperAdmin, int $actorId, ?string $ipAddress): array
    {
        $inviter = User::query()->find($actorId);
        $preferredLocale = $inviter?->preferredLocale() ?? SupportedLocale::default()->value;

        $user = User::query()->create([
            'name' => trim($name),
            'email' => mb_strtolower(trim($email)),
            'password' => Str::password(48),
            'preferred_locale' => $preferredLocale,
            'is_super_admin' => $isSuperAdmin,
            'is_active' => true,
            'invitation_status' => 'pending',
        ]);
        $status = $this->sendInvitation($user);
        $this->audit->record(
            description: '内部用户已创建并发送邀请',
            properties: ['user_id' => $user->id, 'role' => $isSuperAdmin ? 'super_admin' : 'internal', 'invitation_status' => $status],
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
        DB::transaction(function () use ($userId, $isSuperAdmin, $actorId, $ipAddress): void {
            $user = User::query()->lockForUpdate()->findOrFail($userId);
            if ($user->is_super_admin && ! $isSuperAdmin && $user->is_active
                && $this->activeSuperAdminCountForUpdate() <= 1) {
                throw new DomainException(__('auth.errors.last_super_admin_role'));
            }
            $before = (bool) $user->is_super_admin;
            $user->update(['is_super_admin' => $isSuperAdmin]);
            $this->audit->record(
                description: '内部用户角色已调整',
                properties: ['before' => $before, 'after' => $isSuperAdmin],
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
            if ($user->is_super_admin && $user->is_active && ! $active
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
                ->where('is_super_admin', true)
                ->lockForUpdate()
                ->get(['id'])
                ->all(),
        );
    }
}
