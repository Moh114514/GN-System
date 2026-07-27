<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DataImportAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_and_normal_users_are_forbidden(): void
    {
        $this->get(route('data-imports.index'))->assertRedirect(route('login'));

        $this->actingAs(User::factory()->create())
            ->get(route('data-imports.index'))
            ->assertForbidden();
    }

    public function test_confirmed_super_admin_can_open_import_manager(): void
    {
        $user = User::factory()->superAdmin()->withTwoFactor()->create();

        $this->actingAs($user)
            ->get(route('data-imports.index'))
            ->assertOk()
            ->assertSee('历史数据导入')
            ->assertSee('加密上传并预演');
    }
}
