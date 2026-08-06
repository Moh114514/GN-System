<?php

namespace App\Modules\Auth\Application\Services;

use App\Models\User;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

final readonly class AdminMaintenanceService
{
    public function __construct(private AuditRecorder $audit) {}

    public function enable(string $identifier, string $reason, string $operator): void
    {
        $admin = $this->resolve($identifier);

        DB::transaction(function () use ($admin, $reason, $operator): void {
            $locked = User::query()->whereKey($admin->id)->lockForUpdate()->firstOrFail();
            $locked->forceFill(['is_active' => true, 'disabled_at' => null, 'disabled_by' => null])->save();
            $this->audit->record(
                description: 'Super administrator enabled',
                properties: ['user_id' => $locked->id, 'reason' => $reason, 'operator' => $operator],
                subject: $locked,
                logName: 'security',
                event: 'admin_enabled',
            );
        });
    }

    public function disable(string $identifier, string $reason, string $operator): void
    {
        $admin = $this->resolve($identifier);

        DB::transaction(function () use ($admin, $reason, $operator): void {
            $activeSuperAdmins = User::query()
                ->where('is_super_admin', true)
                ->where('is_active', true)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $locked = User::query()->whereKey($admin->id)->lockForUpdate()->firstOrFail();
            if (! $locked->is_active) {
                throw new InvalidArgumentException('The administrator is already disabled.');
            }
            if ($activeSuperAdmins->count() <= 1) {
                throw new InvalidArgumentException('The last active super administrator cannot be disabled.');
            }

            $locked->forceFill([
                'is_active' => false,
                'disabled_at' => now(),
                'disabled_by' => null,
                'session_version' => $locked->session_version + 1,
                'remember_token' => null,
            ])->save();
            if (Schema::hasTable('sessions')) {
                DB::table('sessions')->where('user_id', $locked->id)->delete();
            }

            $this->audit->record(
                description: 'Super administrator disabled',
                properties: ['user_id' => $locked->id, 'reason' => $reason, 'operator' => $operator],
                subject: $locked,
                logName: 'security',
                event: 'admin_disabled',
            );
        });
    }

    public function resetPassword(
        string $identifier,
        string $password,
        bool $clearTwoFactor,
        string $reason,
        string $operator,
    ): void {
        $admin = $this->resolve($identifier);

        DB::transaction(function () use ($admin, $password, $clearTwoFactor, $reason, $operator): void {
            $locked = User::query()->whereKey($admin->id)->lockForUpdate()->firstOrFail();
            $attributes = [
                'password' => Hash::make($password),
                'session_version' => $locked->session_version + 1,
                'remember_token' => null,
            ];
            if ($clearTwoFactor) {
                $attributes += [
                    'two_factor_secret' => null,
                    'two_factor_recovery_codes' => null,
                    'two_factor_confirmed_at' => null,
                ];
            }
            $locked->forceFill($attributes)->save();
            if (Schema::hasTable('sessions')) {
                DB::table('sessions')->where('user_id', $locked->id)->delete();
            }
            $this->audit->record(
                description: 'Super administrator password reset',
                properties: ['user_id' => $locked->id, 'reason' => $reason, 'clear_2fa' => $clearTwoFactor, 'operator' => $operator],
                subject: $locked,
                logName: 'security',
                event: 'admin_password_reset',
            );
        });
    }

    private function resolve(string $identifier): User
    {
        $query = User::query()->where('is_super_admin', true);

        if (ctype_digit($identifier)) {
            $query->whereKey((int) $identifier);
        } else {
            $query->whereRaw('lower(email) = ?', [mb_strtolower($identifier)]);
        }

        return $query->firstOrFail();
    }
}
