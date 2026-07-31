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
            ->assertSee('数据看板')
            ->assertSee('id="global-search"', false)
            ->assertSee('action="'.route('global-search').'"', false)
            ->assertSee('name="q"', false)
            ->assertSee('href="'.route('reports.search').'"', false)
            ->assertSee('href="'.route('reminders.index').'"', false)
            ->assertSee('name="date"', false)
            ->assertDontSee('PNG')
            ->assertSee('新增客户')
            ->assertSee('月度营收与订单趋势')
            ->assertSee('客户生命周期概览')
            ->assertSee('最近客户记录')
            ->assertDontSee('演示数据');
    }

    public function test_topbar_date_opens_the_selected_day_on_the_dashboard(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('dashboard', ['date' => '2026-07-15']));

        $response
            ->assertOk()
            ->assertSee('value="2026-07-15"', false)
            ->assertSee('起始日期')
            ->assertSee('终止日期')
            ->assertSee('2026-07-15 至 2026-07-15');
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
            ->assertSee('数据看板');
    }
}
