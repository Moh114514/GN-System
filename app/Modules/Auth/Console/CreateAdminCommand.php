<?php

namespace App\Modules\Auth\Console;

use App\Models\User;
use App\Modules\Auth\Domain\UserRole;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class CreateAdminCommand extends Command
{
    protected $signature = 'app:create-admin';

    protected $description = 'Interactively create the initial GN-System super administrator';

    public function handle(): int
    {
        $name = (string) $this->ask('管理员姓名');
        $email = mb_strtolower((string) $this->ask('管理员邮箱'));
        $password = (string) $this->secret('管理员密码（至少 12 位，包含大小写字母、数字和符号）');
        $confirmation = (string) $this->secret('再次输入密码');

        $validator = Validator::make(
            compact('name', 'email', 'password', 'confirmation'),
            [
                'name' => ['required', 'string', 'max:100'],
                'email' => ['required', 'email', 'max:255', 'unique:users,email'],
                'password' => [
                    'required',
                    'same:confirmation',
                    Password::min(12)->mixedCase()->letters()->numbers()->symbols(),
                ],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $admin = User::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
            'is_super_admin' => true,
            'role' => UserRole::SuperAdmin,
        ]);
        $admin->markEmailAsVerified();

        $this->info('超级管理员已创建。首次登录后必须启用双因素认证。');

        return self::SUCCESS;
    }
}
