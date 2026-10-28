<?php

namespace Tests\Feature;

use App\Infrastructure\Time\BusinessClock;
use App\Models\User;
use App\Modules\Auth\Domain\UserRole;
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
            ->assertSee('data-test="configuration-subnav-employees"', false)
            ->assertSee('data-test="configuration-subnav-institutions"', false)
            ->assertSee('data-test="configuration-subnav-commissions"', false)
            ->assertSee('data-test="configuration-subnav-basics"', false)
            ->assertSee('data-test="configuration-nav-toggle"', false)
            ->assertSee('class="crm-subnav-collapse is-open"', false)
            ->assertSee('class="crm-subnav-collapse-inner"', false)
            ->assertSee('x-bind:aria-hidden="(!open).toString()"', false)
            ->assertSee('x-bind:inert="!open"', false)
            ->assertSee('员工与权限')
            ->assertSee('合作机构资料')
            ->assertSee('提成规则')
            ->assertSee('基础设置')
            ->assertSee('机构、字典与系统参数')
            ->assertSee('href="'.route('customer-statuses.index').'"', false)
            ->assertSee('href="'.route('configuration.catalog').'"', false)
            ->assertSee('href="'.route('agent-configuration.index').'"', false)
            ->assertSee('href="'.route('reminder-configuration.index').'"', false)
            ->assertSee('href="'.route('configuration.users-and-notifications').'"', false)
            ->assertSee('href="'.route('bd-commissions.index').'"', false)
            ->assertSee('href="'.route('configuration.index').'#configuration-employees"', false)
            ->assertSee('href="'.route('configuration.index').'#configuration-institutions"', false)
            ->assertSee('href="'.route('configuration.index').'#configuration-commissions"', false)
            ->assertSee('href="'.route('configuration.index').'#configuration-basics"', false)
            ->assertSee('id="configuration-employees"', false)
            ->assertSee('id="configuration-institutions"', false)
            ->assertSee('id="configuration-commissions"', false)
            ->assertSee('id="configuration-basics"', false)
            ->assertSee(__('config.center.groups.employees'))
            ->assertSee(__('config.center.groups.institutions'))
            ->assertSee(__('config.center.groups.commissions'))
            ->assertSee(__('config.center.groups.basics'))
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

        foreach (['总览', '线上直客', '线下渠道', '合作机构', '配置中心'] as $label) {
            $this->assertStringContainsString('<span>'.$label.'</span>', $adminNavigation);
        }
        foreach ([
            'online-direct-nav-group',
            'offline-channel-nav-group',
            'institution-nav-group',
            'configuration-nav-group',
            'online-direct-customers',
            'online-direct-team-overview',
            'online-direct-orders',
            'online-direct-commissions',
            'offline-channel-team-overview',
            'offline-channel-bd-commissions',
            'offline-channel-agents',
            'offline-channel-customers',
            'offline-channel-orders',
            'institution-monthly-sales',
            'institution-returns',
            'auxiliary-nav-group',
            'auxiliary-reminders',
            'auxiliary-reports',
            'auxiliary-settlements',
        ] as $testId) {
            $this->assertStringContainsString('data-test="'.$testId.'"', $adminNavigation);
        }
        $this->assertStringContainsString('href="'.route('orders.index', ['sourceTypeFilter' => 'direct']).'"', $adminNavigation);
        $this->assertStringContainsString('href="'.route('direct-team-overview.index').'"', $adminNavigation);
        $this->assertStringContainsString('href="'.route('orders.index', ['sourceTypeFilter' => 'agent']).'"', $adminNavigation);
        $this->assertLessThan(strpos($adminNavigation, 'data-test="offline-channel-nav-group"'), strpos($adminNavigation, 'data-test="online-direct-nav-group"'));
        $this->assertLessThan(strpos($adminNavigation, 'data-test="institution-nav-group"'), strpos($adminNavigation, 'data-test="offline-channel-nav-group"'));
        $this->assertLessThan(strpos($adminNavigation, 'data-test="configuration-nav-group"'), strpos($adminNavigation, 'data-test="institution-nav-group"'));
        $this->assertLessThan(strpos($adminNavigation, 'data-test="auxiliary-nav-group"'), strpos($adminNavigation, 'data-test="configuration-nav-group"'));
        $this->assertSame(1, substr_count($adminNavigation, 'data-test="auxiliary-reminders"'));
        $this->assertSame(1, substr_count($adminNavigation, 'data-test="auxiliary-reports"'));
        $this->assertStringContainsString('客户管理', $adminNavigation);
        $this->assertStringContainsString('代理商管理', $adminNavigation);
        $this->assertStringContainsString('客服绩效与提成', $adminNavigation);
        $this->assertStringContainsString('BD绩效与提成', $adminNavigation);
        $this->assertStringContainsString('月销售报表', $adminNavigation);
        $this->assertStringNotContainsString('数据迁移', $adminNavigation);

        foreach ([
            ['online-direct-team-overview', 'online-direct-customers', 'online-direct-orders', 'online-direct-commissions'],
            ['offline-channel-team-overview', 'offline-channel-agents', 'offline-channel-customers', 'offline-channel-orders', 'offline-channel-bd-commissions'],
        ] as $orderedLinks) {
            $visibleLinks = array_values(array_filter(
                $orderedLinks,
                fn (string $testId): bool => str_contains($adminNavigation, 'data-test="'.$testId.'"'),
            ));

            foreach (array_slice($visibleLinks, 1) as $index => $testId) {
                $this->assertLessThan(
                    strpos($adminNavigation, 'data-test="'.$testId.'"'),
                    strpos($adminNavigation, 'data-test="'.$visibleLinks[$index].'"'),
                );
            }
        }

        $customerService = User::factory()->create(['role' => UserRole::CustomerService]);
        $customerServiceContent = $this->actingAs($customerService)->get(route('dashboard'))->assertOk()->getContent();
        preg_match('/<nav class="crm-nav">(?<navigation>.*?)<\/nav>/s', $customerServiceContent, $customerServiceMatches);
        $customerServiceNavigation = $customerServiceMatches['navigation'] ?? '';
        $this->assertStringContainsString('data-test="offline-channel-nav-group"', $customerServiceNavigation);
        $this->assertStringContainsString('data-test="offline-channel-customers"', $customerServiceNavigation);
        $this->assertStringContainsString('data-test="auxiliary-reminders"', $customerServiceNavigation);
        $this->assertStringContainsString('data-test="auxiliary-reports"', $customerServiceNavigation);
        $this->assertStringNotContainsString('data-test="online-direct-nav-group"', $customerServiceNavigation);
        $this->assertStringNotContainsString('data-test="offline-channel-agents"', $customerServiceNavigation);
        $this->assertStringNotContainsString('data-test="offline-channel-bd-commissions"', $customerServiceNavigation);
        $this->assertStringNotContainsString('data-test="offline-channel-team-overview"', $customerServiceNavigation);
        $this->assertStringNotContainsString('data-test="institution-nav-group"', $customerServiceNavigation);
        $this->assertStringNotContainsString('data-test="configuration-nav-group"', $customerServiceNavigation);

        $bd = User::factory()->create(['role' => UserRole::BdManager]);
        $bdContent = $this->actingAs($bd)->get(route('dashboard'))->assertOk()->getContent();
        preg_match('/<nav class="crm-nav">(?<navigation>.*?)<\/nav>/s', $bdContent, $bdMatches);
        $bdNavigation = $bdMatches['navigation'] ?? '';
        $this->assertStringContainsString('data-test="offline-channel-nav-group"', $bdNavigation);
        $this->assertStringContainsString('data-test="offline-channel-team-overview"', $bdNavigation);
        $this->assertStringContainsString('data-test="offline-channel-agents"', $bdNavigation);
        $this->assertStringContainsString('data-test="offline-channel-bd-commissions"', $bdNavigation);
        $this->assertStringNotContainsString('data-test="institution-nav-group"', $bdNavigation);
        $this->assertStringNotContainsString('data-test="online-direct-nav-group"', $bdNavigation);
        $this->assertStringNotContainsString('data-test="configuration-nav-group"', $bdNavigation);

        $directManager = User::factory()->create(['role' => UserRole::DirectCustomerManager]);
        $directContent = $this->actingAs($directManager)->get(route('dashboard'))->assertOk()->getContent();
        preg_match('/<nav class="crm-nav">(?<navigation>.*?)<\/nav>/s', $directContent, $directMatches);
        $directNavigation = $directMatches['navigation'] ?? '';
        $this->assertStringContainsString('data-test="online-direct-nav-group"', $directNavigation);
        $this->assertStringContainsString('data-test="online-direct-customers"', $directNavigation);
        $this->assertStringContainsString('data-test="online-direct-team-overview"', $directNavigation);
        $this->assertStringContainsString('data-test="auxiliary-reminders"', $directNavigation);
        $this->assertStringContainsString('data-test="auxiliary-reports"', $directNavigation);
        $this->assertStringNotContainsString('data-test="institution-nav-group"', $directNavigation);
        $this->assertStringNotContainsString('data-test="offline-channel-nav-group"', $directNavigation);
        $this->assertStringNotContainsString('data-test="configuration-nav-group"', $directNavigation);
    }

    public function test_operations_director_gets_read_access_to_required_modules_but_not_super_admin_tools(): void
    {
        $operationsDirector = User::factory()->withTwoFactor()->create(['role' => UserRole::OperationsDirector]);
        $content = $this->actingAs($operationsDirector)->get(route('dashboard'))->assertOk()->getContent();
        preg_match('/<nav class="crm-nav">(?<navigation>.*?)<\/nav>/s', $content, $matches);
        $navigation = $matches['navigation'] ?? '';

        foreach ([
            'online-direct-nav-group',
            'offline-channel-nav-group',
            'institution-nav-group',
            'configuration-nav-group',
            'online-direct-customers',
            'offline-channel-team-overview',
            'offline-channel-bd-commissions',
            'offline-channel-agents',
            'offline-channel-customers',
            'institution-monthly-sales',
            'institution-returns',
            'configuration-subnav-employees',
            'configuration-subnav-institutions',
            'configuration-subnav-commissions',
            'configuration-subnav-basics',
        ] as $testId) {
            $this->assertStringContainsString('data-test="'.$testId.'"', $navigation);
        }
        $this->assertStringNotContainsString('configuration-subnav-data-maintenance', $navigation);
        $this->assertStringNotContainsString('configuration-subnav-time-travel', $navigation);

        foreach ([
            'direct-customers.index',
            'direct-commissions.index',
            'direct-team-overview.index',
            'orders.index',
            'customers.index',
            'team-overview.index',
            'agents.index',
            'bd-commissions.index',
            'reports.institution-sales',
            'reports.search',
            'institution-returns.index',
            'configuration.index',
            'configuration.users-and-notifications',
        ] as $routeName) {
            $response = $this->get(route($routeName));
            $this->assertSame(200, $response->getStatusCode(), "Operations director cannot read {$routeName}.");
        }

        $configurationContent = $this->get(route('configuration.index'))->assertOk()->getContent();
        $userManagementContent = $this->get(route('configuration.users-and-notifications'))->assertOk()->getContent();
        $this->assertStringNotContainsString('href="'.route('configuration.data-maintenance').'"', $configurationContent);
        $this->assertStringNotContainsString('href="'.route('audit-logs.index').'"', $userManagementContent);

        $this->get(route('configuration.data-maintenance'))->assertForbidden();
        $this->get(route('audit-logs.index'))->assertForbidden();
        if (app(BusinessClock::class)->isAvailable()) {
            $this->get(route('configuration.time-travel'))->assertForbidden();
        }
        $this->get(route('audit-logs.index'))->assertForbidden();
        $this->get(route('customers.create'))->assertForbidden();
        $this->get(route('direct-customers.create'))->assertForbidden();
    }

    public function test_configuration_pages_return_to_center_and_keep_configuration_navigation_active(): void
    {
        $admin = User::factory()->superAdmin()->withTwoFactor()->create();

        $this->actingAs($admin)->get(route('customer-statuses.index'))
            ->assertOk()
            ->assertSee('返回配置中心')
            ->assertSee('href="'.route('configuration.index').'"', false)
            ->assertSee('class="crm-nav-group-head is-active"', false)
            ->assertSee('data-test="configuration-subnav-basics"', false)
            ->assertSee('class="crm-subnav-item is-active"', false);

        $this->actingAs($admin)->get(route('agent-configuration.index'))
            ->assertOk()
            ->assertSee('返回配置中心')
            ->assertSee('href="'.route('configuration.index').'"', false)
            ->assertSee('class="crm-nav-group-head is-active"', false)
            ->assertSee('data-test="configuration-subnav-commissions"', false)
            ->assertSee('class="crm-subnav-item is-active"', false);

        $this->actingAs($admin)->get(route('configuration.data-maintenance'))
            ->assertOk()
            ->assertSee('返回配置中心')
            ->assertSee('href="'.route('configuration.index').'"', false)
            ->assertSee('class="crm-nav-group-head is-active"', false)
            ->assertSee('data-test="configuration-subnav-data-maintenance"', false)
            ->assertSee('class="crm-subnav-item is-active"', false);
    }

    public function test_sidebar_groups_share_full_row_toggles_and_accessible_collapsed_state(): void
    {
        $admin = User::factory()->superAdmin()->withTwoFactor()->create();
        $content = $this->actingAs($admin)->get(route('dashboard'))->assertOk()->getContent();

        foreach (['online-direct', 'offline-channel', 'institution', 'configuration'] as $group) {
            preg_match('/<button[^>]*data-test="'.$group.'-nav-toggle"[^>]*>(.*?)<\/button>/s', $content, $toggle);
            $this->assertNotEmpty($toggle);
            $this->assertStringContainsString('class="crm-nav-group-head ', $toggle[0]);
            $this->assertStringContainsString('@click="open = !open"', $toggle[0]);
            $this->assertStringContainsString('aria-expanded="false"', $toggle[0]);
            $this->assertStringContainsString(':aria-expanded="open"', $toggle[0]);
            $this->assertStringContainsString('aria-controls="'.$group.'-subnav"', $toggle[0]);
            $this->assertMatchesRegularExpression('/class="[^"]*\bcrm-nav-chevron\b[^"]*"/', $toggle[0]);
            $this->assertStringNotContainsString('<a ', $toggle[0]);

            preg_match('/<div\s+id="'.$group.'-subnav"[^>]*>/s', $content, $subnav);
            $this->assertNotEmpty($subnav);
            $this->assertStringContainsString('x-bind:class="{ \'is-open\': open }"', $subnav[0]);
            $this->assertStringContainsString('aria-hidden="true"', $subnav[0]);
            $this->assertMatchesRegularExpression('/\s+inert(?:\s|>)/', $subnav[0]);
            $this->assertStringContainsString('x-bind:inert="!open"', $subnav[0]);
        }

        foreach ([
            route('direct-customers.index'),
            route('direct-team-overview.index'),
            route('orders.index', ['sourceTypeFilter' => 'direct']),
            route('orders.index', ['sourceTypeFilter' => 'agent']),
            route('customers.index'),
            route('team-overview.index'),
            route('reports.institution-sales'),
            route('configuration.index').'#configuration-employees',
        ] as $url) {
            $this->assertStringContainsString('href="'.$url.'"', $content);
        }
    }

    public function test_shared_auxiliary_pages_do_not_activate_both_business_groups(): void
    {
        $admin = User::factory()->superAdmin()->withTwoFactor()->create();

        foreach ([
            ['url' => route('reminders.index'), 'testId' => 'auxiliary-reminders'],
            ['url' => route('reports.search'), 'testId' => 'auxiliary-reports'],
        ] as $page) {
            $content = $this->actingAs($admin)->get($page['url'])->assertOk()->getContent();
            $this->assertStringContainsString('class="crm-nav-item is-active" data-test="'.$page['testId'].'"', $content);

            foreach (['online-direct', 'offline-channel'] as $group) {
                preg_match('/<button[^>]*data-test="'.$group.'-nav-toggle"[^>]*>/', $content, $toggle);
                $this->assertNotEmpty($toggle);
                $this->assertStringNotContainsString('is-active', $toggle[0]);
            }
        }
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
            ->assertSee('data-test="configuration-subnav-commissions"', false)
            ->assertSee('data-test="configuration-subnav-basics"', false);

        $content = $response->getContent();

        $this->assertMatchesRegularExpression(
            '/<a\s+[^>]*class="crm-subnav-item is-active"[^>]*data-test="configuration-subnav-commissions"/s',
            $content,
        );
        $this->assertDoesNotMatchRegularExpression(
            '/<a\s+[^>]*class="crm-subnav-item is-active"[^>]*data-test="configuration-subnav-basics"/s',
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
