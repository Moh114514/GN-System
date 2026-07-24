<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $response = $this->get(route('dashboard'));
        $response->assertRedirect(route('login'));
    }

    public function test_authenticated_users_can_visit_the_dashboard(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->get(route('dashboard'));
        $response
            ->assertOk()
            ->assertSee('GN-System')
            ->assertSee('当前展示为演示数据')
            ->assertSee('月度营收与订单趋势')
            ->assertSee('客户生命周期概览')
            ->assertSee('最近客户记录');
    }

    public function test_super_admin_without_two_factor_is_redirected_to_security_settings(): void
    {
        $user = User::factory()->superAdmin()->create();

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertRedirect(route('security.edit'));
    }

    public function test_super_admin_with_two_factor_can_visit_the_dashboard(): void
    {
        $user = User::factory()->superAdmin()->withTwoFactor()->create();

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response
            ->assertOk()
            ->assertSee('GN-System')
            ->assertSee('本月月结进度');
    }
}
