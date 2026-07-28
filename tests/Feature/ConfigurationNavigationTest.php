<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConfigurationNavigationTest extends TestCase
{
    use RefreshDatabase;

    public function test_configuration_center_is_restricted_and_lists_available_configuration_pages(): void
    {
        $user = User::factory()->create();
        $admin = User::factory()->superAdmin()->withTwoFactor()->create();

        $this->actingAs($user)->get(route('configuration.index'))->assertForbidden();

        $this->actingAs($admin)->get(route('configuration.index'))
            ->assertOk()
            ->assertSee('配置中心')
            ->assertSee('客户生命周期状态配置')
            ->assertSee('href="'.route('customer-statuses.index').'"', false)
            ->assertSee('代理商与推广费配置')
            ->assertSee('href="'.route('agent-configuration.index').'"', false)
            ->assertSee('href="'.route('configuration.index').'" class="crm-nav-item is-active"', false);
    }

    public function test_configuration_pages_return_to_center_and_keep_configuration_navigation_active(): void
    {
        $admin = User::factory()->superAdmin()->withTwoFactor()->create();
        $activeNavigation = 'href="'.route('configuration.index').'" class="crm-nav-item is-active"';

        $this->actingAs($admin)->get(route('customer-statuses.index'))
            ->assertOk()
            ->assertSee('返回配置中心')
            ->assertSee('href="'.route('configuration.index').'"', false)
            ->assertSee($activeNavigation, false);

        $this->actingAs($admin)->get(route('agent-configuration.index'))
            ->assertOk()
            ->assertSee('返回配置中心')
            ->assertSee('href="'.route('configuration.index').'"', false)
            ->assertSee($activeNavigation, false);
    }

    public function test_business_lists_keep_their_configuration_shortcuts(): void
    {
        $admin = User::factory()->superAdmin()->withTwoFactor()->create();

        $this->actingAs($admin)->get(route('customers.index'))
            ->assertOk()
            ->assertSee('状态配置')
            ->assertSee('href="'.route('customer-statuses.index').'"', false);

        $this->actingAs($admin)->get(route('agents.index'))
            ->assertOk()
            ->assertSee('代理商配置')
            ->assertSee('href="'.route('agent-configuration.index').'"', false);
    }
}
