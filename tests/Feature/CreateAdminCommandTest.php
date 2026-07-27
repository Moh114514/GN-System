<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreateAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_creates_a_normalized_admin_who_can_log_in_and_is_guided_to_two_factor_setup(): void
    {
        $this->artisan('app:create-admin')
            ->expectsQuestion('管理员姓名', '系统管理员')
            ->expectsQuestion('管理员邮箱', 'Admin@Example.COM')
            ->expectsQuestion('管理员密码（至少 12 位，包含大小写字母、数字和符号）', 'StrongPass!123')
            ->expectsQuestion('再次输入密码', 'StrongPass!123')
            ->expectsOutput('超级管理员已创建。首次登录后必须启用双因素认证。')
            ->assertSuccessful();

        $admin = User::query()->where('email', 'admin@example.com')->firstOrFail();

        $this->assertTrue($admin->is_super_admin);
        $this->assertNotNull($admin->email_verified_at);

        $login = $this->post(route('login.store'), [
            'email' => 'Admin@Example.COM',
            'password' => 'StrongPass!123',
        ]);

        $login
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('dashboard', absolute: false));
        $this->assertAuthenticatedAs($admin);

        $this->get(route('dashboard'))
            ->assertRedirect(route('security.edit'))
            ->assertSessionHas(
                'status',
                '请先启用并确认双因素认证，然后再继续。',
            );
    }
}
