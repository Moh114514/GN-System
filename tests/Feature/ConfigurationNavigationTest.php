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

        $response = $this->actingAs($admin)->get(route('configuration.index'))
            ->assertOk()
            ->assertSee('data-test="configuration-nav-group"', false)
            ->assertSee('data-test="configuration-nav-link"', false)
            ->assertSee('data-test="configuration-nav-toggle"', false)
            ->assertSee('class="crm-subnav-collapse"', false)
            ->assertSee('class="crm-subnav-collapse-inner"', false)
            ->assertSee('x-bind:aria-hidden="(!open).toString()"', false)
            ->assertSee('x-bind:inert="!open"', false)
            ->assertSee('配置总览')
            ->assertSee('机构与字典')
            ->assertSee('href="'.route('customer-statuses.index').'"', false)
            ->assertSee('href="'.route('configuration.catalog').'"', false)
            ->assertSee('href="'.route('direct-sales-sources.index').'"', false)
            ->assertSee('href="'.route('agent-configuration.index').'"', false)
            ->assertSee('href="'.route('reminder-configuration.index').'"', false)
            ->assertSee('href="'.route('configuration.users').'"', false)
            ->assertSee('href="'.route('configuration.history').'"', false)
            ->assertSee('href="'.route('reference-configuration-imports.index').'"', false)
            ->assertSee('aria-label="展开或收起配置中心"', false)
            ->assertSee('配置中心')
            ->assertSee('href="'.route('agent-configuration.index').'"', false)
            ->assertSee('class="crm-nav-group-head is-active"', false);

        $this->assertDoesNotMatchRegularExpression(
            '/<div\s+id="configuration-subnav"[^>]*x-show="open"/s',
            $response->getContent(),
        );
    }

    public function test_configuration_pages_return_to_center_and_keep_configuration_navigation_active(): void
    {
        $admin = User::factory()->superAdmin()->withTwoFactor()->create();

        $this->actingAs($admin)->get(route('customer-statuses.index'))
            ->assertOk()
            ->assertSee('返回配置中心')
            ->assertSee('href="'.route('configuration.index').'"', false)
            ->assertSee('class="crm-nav-group-head is-active"', false)
            ->assertSee('data-test="configuration-subnav-customer-statuses"', false)
            ->assertSee('class="crm-subnav-item is-active"', false);

        $this->actingAs($admin)->get(route('agent-configuration.index'))
            ->assertOk()
            ->assertSee('返回配置中心')
            ->assertSee('href="'.route('configuration.index').'"', false)
            ->assertSee('class="crm-nav-group-head is-active"', false)
            ->assertSee('data-test="configuration-subnav-agent"', false)
            ->assertSee('class="crm-subnav-item is-active"', false);
    }

    public function test_configuration_subpages_auto_expand_and_highlight_only_the_current_shortcut(): void
    {
        $admin = User::factory()->superAdmin()->withTwoFactor()->create();

        $response = $this->actingAs($admin)->get(route('agent-configuration.index'))
            ->assertOk()
            ->assertSee('x-data="{ open: true }"', false)
            ->assertSee('data-test="configuration-subnav-agent"', false)
            ->assertSee('data-test="configuration-subnav-customer-statuses"', false);

        $content = $response->getContent();

        $this->assertMatchesRegularExpression(
            '/<a\s+[^>]*class="crm-subnav-item is-active"[^>]*data-test="configuration-subnav-agent"/s',
            $content,
        );
        $this->assertDoesNotMatchRegularExpression(
            '/<a\s+[^>]*class="crm-subnav-item is-active"[^>]*data-test="configuration-subnav-customer-statuses"/s',
            $content,
        );
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
