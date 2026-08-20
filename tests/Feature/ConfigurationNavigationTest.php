<?php

namespace Tests\Feature;

use App\Infrastructure\Time\BusinessClock;
use App\Models\User;
use Carbon\CarbonImmutable;
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
        $this->actingAs($user)->get(route('configuration.data-maintenance'))->assertForbidden();

        $response = $this->actingAs($admin)->get(route('configuration.index'))
            ->assertOk()
            ->assertSee('data-test="configuration-nav-group"', false)
            ->assertSee('data-test="configuration-nav-link"', false)
            ->assertSee('data-test="configuration-nav-toggle"', false)
            ->assertSee('class="crm-subnav-collapse is-open"', false)
            ->assertSee('class="crm-subnav-collapse-inner"', false)
            ->assertSee('x-bind:aria-hidden="(!open).toString()"', false)
            ->assertSee('x-bind:inert="!open"', false)
            ->assertSee('配置总览')
            ->assertSee('机构与字典')
            ->assertSee('href="'.route('customer-statuses.index').'"', false)
            ->assertSee('href="'.route('configuration.catalog').'"', false)
            ->assertSee('href="'.route('agent-configuration.index').'"', false)
            ->assertSee('href="'.route('reminder-configuration.index').'"', false)
            ->assertSee('href="'.route('configuration.users-and-notifications').'"', false)
            ->assertSee('href="'.route('configuration.history').'"', false)
            ->assertSee('数据导入与迁移')
            ->assertSee('href="'.route('configuration.data-maintenance').'"', false)
            ->assertDontSee('href="'.route('reference-configuration-imports.index').'"', false)
            ->assertSee('aria-label="展开或收起配置中心"', false)
            ->assertSee('配置中心')
            ->assertSee('href="'.route('agent-configuration.index').'"', false)
            ->assertSee('class="crm-nav-group-head is-active"', false);

        $this->assertDoesNotMatchRegularExpression(
            '/<div\s+id="configuration-subnav"[^>]*x-show="open"/s',
            $response->getContent(),
        );
    }

    public function test_configuration_center_and_user_management_render_korean_translations(): void
    {
        $admin = User::factory()->superAdmin()->withTwoFactor()->create(['preferred_locale' => 'ko_KR']);

        $this->actingAs($admin)->get(route('configuration.index'))
            ->assertOk()
            ->assertSee('<html lang="ko-KR"', false)
            ->assertSee('설정 센터')
            ->assertSee(__('config.center.cards.users.title'));

        $this->actingAs($admin)->get(route('configuration.users-and-notifications'))
            ->assertOk()
            ->assertSee('내부 사용자 관리')
            ->assertSee('설정 센터로 돌아가기')
            ->assertSee('href="'.route('configuration.index').'"', false)
            ->assertSee('wire:navigate', false)
            ->assertSee('수락됨')
            ->assertDontSee('accepted')
            ->assertDontSee('config.user_management.actions.save_dingtalk');
    }

    public function test_user_management_uses_a_localized_dingtalk_save_label(): void
    {
        $admin = User::factory()->superAdmin()->withTwoFactor()->create(['preferred_locale' => 'zh_CN']);

        $this->actingAs($admin)->get(route('configuration.users-and-notifications'))
            ->assertOk()
            ->assertSee('保存')
            ->assertDontSee('config.user_management.actions.save_dingtalk');
    }

    public function test_users_and_notifications_page_combines_the_two_configuration_tabs(): void
    {
        $user = User::factory()->create();
        $admin = User::factory()->superAdmin()->withTwoFactor()->create();

        $this->actingAs($user)->get(route('configuration.users-and-notifications'))->assertForbidden();

        $this->actingAs($admin)->get(route('configuration.users-and-notifications'))
            ->assertOk()
            ->assertSee('data-test="users-and-notifications-tab-users"', false)
            ->assertSee('data-test="users-and-notifications-tab-notifications"', false)
            ->assertSee(__('config.user_management.invite_heading'))
            ->assertSee(__('config.user_management.audit_link'))
            ->assertSee(__('config.back_to_configuration'))
            ->assertSee('href="'.route('configuration.index').'"', false);

        $this->actingAs($admin)->get(route('configuration.users-and-notifications', ['tab' => 'notifications']))
            ->assertOk()
            ->assertSee(__('config.notification_recipients.internal_heading'))
            ->assertSee(__('config.notification_recipients.dingtalk_heading'));

        $this->actingAs($admin)->get(route('configuration.users'))->assertRedirect(route('configuration.users-and-notifications', ['tab' => 'users']));
        $this->actingAs($admin)->get(route('configuration.notifications'))->assertRedirect(route('configuration.users-and-notifications', ['tab' => 'notifications']));
    }

    public function test_primary_navigation_is_ordered_and_hides_admin_links_for_normal_users(): void
    {
        $admin = User::factory()->superAdmin()->withTwoFactor()->create();
        $adminContent = $this->actingAs($admin)->get(route('dashboard'))->assertOk()->getContent();
        preg_match('/<nav class="crm-nav">(?<navigation>.*?)<\/nav>/s', $adminContent, $adminMatches);
        $adminNavigation = $adminMatches['navigation'] ?? '';

        $this->assertMatchesRegularExpression(
            '/<nav class="crm-nav">.*?<span>总览<\/span>.*?<span>主动提醒<\/span>.*?<span>客户管理<\/span>.*?<span>订单<\/span>.*?<span>多维查询<\/span>.*?<span>代理商<\/span>.*?<span>月结中心<\/span>.*?<span>配置中心<\/span>.*?<\/nav>/s',
            $adminContent,
        );
        $this->assertStringNotContainsString('数据迁移', $adminNavigation);

        $user = User::factory()->create();
        $userContent = $this->actingAs($user)->get(route('dashboard'))->assertOk()->getContent();
        preg_match('/<nav class="crm-nav">(?<navigation>.*?)<\/nav>/s', $userContent, $userMatches);
        $userNavigation = $userMatches['navigation'] ?? '';

        $this->assertMatchesRegularExpression(
            '/<nav class="crm-nav">.*?<span>总览<\/span>.*?<span>主动提醒<\/span>.*?<span>客户管理<\/span>.*?<span>订单<\/span>.*?<span>多维查询<\/span>.*?<\/nav>/s',
            $userContent,
        );
        $this->assertStringNotContainsString('代理商', $userNavigation);
        $this->assertStringNotContainsString('月结中心', $userNavigation);
        $this->assertStringNotContainsString('配置中心', $userNavigation);
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

        $this->actingAs($admin)->get(route('configuration.data-maintenance'))
            ->assertOk()
            ->assertSee('返回配置中心')
            ->assertSee('href="'.route('configuration.index').'"', false)
            ->assertSee('class="crm-nav-group-head is-active"', false)
            ->assertSee('data-test="configuration-subnav-data-maintenance"', false)
            ->assertSee('class="crm-subnav-item is-active"', false);
    }

    public function test_time_travel_page_is_admin_only_and_returns_to_configuration_center(): void
    {
        $user = User::factory()->create();
        $admin = User::factory()->superAdmin()->withTwoFactor()->create();

        $this->actingAs($user)->get(route('configuration.time-travel'))->assertForbidden();

        $this->actingAs($admin)->get(route('configuration.time-travel'))
            ->assertOk()
            ->assertSee(__('config.time_travel.title'))
            ->assertSee(__('config.time_travel.set_and_execute'))
            ->assertSee(__('config.back_to_configuration'))
            ->assertSee('href="'.route('configuration.index').'"', false)
            ->assertSee('type="date"', false)
            ->assertSee('type="time"', false)
            ->assertSee('data-test="configuration-subnav-time-travel"', false);
    }

    public function test_active_time_travel_is_visible_on_every_authenticated_page_with_restore_action(): void
    {
        $admin = User::factory()->superAdmin()->withTwoFactor()->create();
        $clock = app(BusinessClock::class);
        $clock->set(CarbonImmutable::parse('2026-09-10 10:00:00', 'Asia/Shanghai'));

        $this->actingAs($admin)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('data-test="business-clock-warning"', false)
            ->assertSee(__('navigation.restore_real_time'))
            ->assertSee('action="'.route('configuration.time-travel.disable').'"', false);

        $this->actingAs($admin)->post(route('configuration.time-travel.disable'))
            ->assertRedirect(route('configuration.time-travel'));
        $this->assertFalse($clock->isActive());
    }

    public function test_data_import_pages_keep_configuration_navigation_open_and_highlight_data_maintenance(): void
    {
        $admin = User::factory()->superAdmin()->withTwoFactor()->create();

        foreach ([
            route('configuration.data-maintenance'),
            route('reference-configuration-imports.index'),
            route('data-imports.index'),
        ] as $uri) {
            $response = $this->actingAs($admin)->get($uri)
                ->assertOk()
                ->assertSee('x-data="{ open: true }"', false)
                ->assertSee('class="crm-nav-group-head is-active"', false)
                ->assertSee('class="crm-subnav-collapse is-open"', false)
                ->assertSee('data-test="configuration-subnav-data-maintenance"', false);

            $this->assertMatchesRegularExpression(
                '/<a\s+[^>]*class="crm-subnav-item is-active"[^>]*data-test="configuration-subnav-data-maintenance"/s',
                $response->getContent(),
            );
        }
    }

    public function test_configuration_subpages_auto_expand_and_highlight_only_the_current_shortcut(): void
    {
        $admin = User::factory()->superAdmin()->withTwoFactor()->create();

        $response = $this->actingAs($admin)->get(route('agent-configuration.index'))
            ->assertOk()
            ->assertSee('x-data="{ open: true }"', false)
            ->assertSee('class="crm-subnav-collapse is-open"', false)
            ->assertSee('aria-hidden="false"', false)
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

    public function test_non_configuration_pages_render_the_configuration_subnav_collapsed(): void
    {
        $admin = User::factory()->superAdmin()->withTwoFactor()->create();

        $response = $this->actingAs($admin)->get(route('customers.index'))
            ->assertOk()
            ->assertSee('aria-hidden="true"', false)
            ->assertSee('inert', false);

        $this->assertDoesNotMatchRegularExpression(
            '/id="configuration-subnav"\s+class="crm-subnav-collapse is-open"/s',
            $response->getContent(),
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
