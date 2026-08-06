<?php

namespace App\Modules\Auth\Console;

use App\Models\User;
use Illuminate\Console\Command;

final class ListAdminsCommand extends Command
{
    protected $signature = 'app:list-admins';

    protected $description = 'List super administrators without exposing credentials';

    public function handle(): int
    {
        $rows = User::query()
            ->where('is_super_admin', true)
            ->orderBy('id')
            ->get()
            ->map(static fn (User $admin): array => [
                'id' => $admin->id,
                'name' => $admin->name,
                'email' => $admin->email,
                'active' => $admin->is_active ? 'yes' : 'no',
                'disabled_at' => $admin->disabled_at?->toIso8601String() ?? '-',
                'session_version' => $admin->session_version,
                '2fa' => $admin->two_factor_confirmed_at !== null ? 'yes' : 'no',
            ])
            ->all();

        $this->table(['ID', 'Name', 'Email', 'Active', 'Disabled at', 'Session version', '2FA'], $rows);

        return self::SUCCESS;
    }
}
