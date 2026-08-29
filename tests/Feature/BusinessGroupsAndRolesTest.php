<?php

namespace Tests\Feature;

use App\Infrastructure\Time\BusinessClock;
use App\Models\User;
use App\Modules\Agent\Application\Contracts\AgentBusinessGroupAssignmentGateway;
use App\Modules\Agent\Infrastructure\Models\Agent;
use App\Modules\Agent\Infrastructure\Models\AgentBusinessGroupAssignment;
use App\Modules\Agent\Infrastructure\Models\AgentTypeCode;
use App\Modules\Auth\Application\Contracts\AccessContextResolver;
use App\Modules\Auth\Application\Contracts\BusinessGroupManagementGateway;
use App\Modules\Auth\Application\Contracts\BusinessGroupMembershipReader;
use App\Modules\Auth\Application\Contracts\InternalUserReferenceReader;
use App\Modules\Auth\Domain\UserRole;
use App\Modules\Auth\Infrastructure\Models\BusinessGroupMembership;
use App\Modules\Config\Application\Services\ConfigurationUserCoordinator;
use App\Modules\Config\Presentation\Livewire\UserManagement;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class BusinessGroupsAndRolesTest extends TestCase
{
    use RefreshDatabase;

    public function test_role_compatibility_backfills_and_keeps_super_admin_semantics(): void
    {
        $admin = User::factory()->create(['is_super_admin' => true]);
        $customerService = User::factory()->create();

        $this->assertSame(UserRole::SuperAdmin, $admin->fresh()->roleValue());
        $this->assertTrue($admin->fresh()->isSuperAdmin());
        $this->assertSame(UserRole::CustomerService, $customerService->fresh()->roleValue());
        $this->assertFalse($customerService->fresh()->canManageConfiguration());
    }

    public function test_business_group_memberships_enforce_one_bd_per_group_and_one_group_per_user(): void
    {
        $admin = User::factory()->superAdmin()->withTwoFactor()->create();
        $firstBd = User::factory()->create(['role' => UserRole::BdManager]);
        $secondBd = User::factory()->create(['role' => UserRole::BdManager]);
        $group = app(BusinessGroupManagementGateway::class)->create('NORTH', '北区业务组', $admin->id, null);
        $gateway = app(BusinessGroupManagementGateway::class);

        $gateway->assignMember($group['id'], $firstBd->id, '2026-08-01', null, '初始配置', $admin->id, null);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('同一业务组的有效 BD 经理期间不能重叠。');
        $gateway->assignMember($group['id'], $secondBd->id, '2026-08-15', null, '重复配置', $admin->id, null);
    }

    public function test_business_group_membership_rejects_overlapping_group_for_same_user_and_inactive_user(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $user = User::factory()->create();
        $inactive = User::factory()->create(['is_active' => false]);
        $gateway = app(BusinessGroupManagementGateway::class);
        $first = $gateway->create('NORTH', '北区业务组', $admin->id, null);
        $second = $gateway->create('SOUTH', '南区业务组', $admin->id, null);

        $gateway->assignMember($first['id'], $user->id, '2026-08-01', null, '初始配置', $admin->id, null);

        try {
            $gateway->assignMember($second['id'], $user->id, '2026-09-01', null, '重复配置', $admin->id, null);
            $this->fail('Expected overlapping user membership to be rejected.');
        } catch (DomainException $exception) {
            $this->assertSame('同一用户的有效业务组期间不能重叠。', $exception->getMessage());
        }

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('停用用户不能成为新成员。');
        $gateway->assignMember($second['id'], $inactive->id, '2026-08-01', null, '停用账号', $admin->id, null);
    }

    public function test_membership_role_is_derived_and_pending_invitation_can_be_preconfigured(): void
    {
        $admin = User::factory()->superAdmin()->withTwoFactor()->create();
        $pending = User::factory()->create([
            'name' => '待接受客服',
            'role' => UserRole::CustomerService,
            'invitation_status' => 'sent',
        ]);
        $group = app(BusinessGroupManagementGateway::class)->create('PRECONFIG', '预配置业务组', $admin->id, null);
        $gateway = app(BusinessGroupManagementGateway::class);

        $gateway->assignMember($group['id'], $pending->id, '2026-08-24', null, '邀请发送后提前配置', $admin->id, null);

        $this->assertDatabaseHas('business_group_memberships', [
            'business_group_id' => $group['id'],
            'user_id' => $pending->id,
            'member_role' => UserRole::CustomerService->value,
        ]);
        $this->assertEmpty(array_filter(
            app(InternalUserReferenceReader::class)->eligibleUsers(),
            static fn (array $user): bool => (int) $user['id'] === (int) $pending->id,
        ));
        $this->assertNotContains(
            $pending->id,
            app(BusinessGroupMembershipReader::class)->activeCustomerServiceUserIds([$group['id']], '2026-08-24'),
        );
        $context = app(AccessContextResolver::class)->forUser($pending);
        $this->assertSame([], $context->businessGroupIds);
        $this->assertSame([], $context->agentIds);

        $pending->update(['invitation_status' => 'accepted']);
        $this->assertContains(
            $pending->id,
            array_column(app(InternalUserReferenceReader::class)->eligibleUsers(), 'id'),
        );
        $this->assertContains(
            $pending->id,
            app(BusinessGroupMembershipReader::class)->activeCustomerServiceUserIds([$group['id']], '2026-08-24'),
        );
    }

    public function test_membership_form_hides_redundant_user_role_control_and_shows_pending_configuration_candidate(): void
    {
        $admin = User::factory()->superAdmin()->withTwoFactor()->create();
        $pending = User::factory()->create([
            'name' => '待接受 BD',
            'role' => UserRole::BdManager,
            'invitation_status' => 'sent',
        ]);

        $this->actingAs($admin)
            ->get(route('configuration.users-and-notifications'))
            ->assertOk()
            ->assertSee($pending->name)
            ->assertDontSee('当前角色');
    }

    public function test_member_candidates_include_current_users_and_configuration_uses_business_clock(): void
    {
        config([
            'app.deployment_environment' => 'testing',
            'app.time_travel_enabled' => true,
        ]);
        $admin = User::factory()->superAdmin()->withTwoFactor()->create();
        $user = User::factory()->create(['name' => '当前组内客服']);
        $group = app(BusinessGroupManagementGateway::class)->create('NORTH', '北区业务组', $admin->id, null);
        app(BusinessGroupManagementGateway::class)->assignMember(
            $group['id'],
            $user->id,
            '2026-08-01',
            null,
            '初始配置',
            $admin->id,
            null,
        );
        $type = AgentTypeCode::query()->create(['code' => 'CLK', 'name' => '时钟测试代理', 'is_system' => false, 'is_active' => true]);
        $agent = Agent::query()->create([
            'agent_type_code_id' => $type->id,
            'code' => 'CLK-001',
            'name' => '时钟测试代理商',
            'cooperation_status' => 'active',
        ]);
        app(AgentBusinessGroupAssignmentGateway::class)->assign(
            $agent->id,
            $group['id'],
            '2026-08-01',
            '2026-09-30',
            '时钟测试',
            $admin->id,
            null,
        );

        app(BusinessClock::class)->set(CarbonImmutable::parse('2026-10-01 09:00:00', 'Asia/Shanghai'), $admin->id);

        $candidates = app(BusinessGroupManagementGateway::class)->memberCandidates();
        $candidate = collect($candidates)->firstWhere('id', $user->id);

        $this->assertNotNull($candidate);
        $this->assertSame('NORTH', $candidate['current_group_code']);
        $this->assertCount(0, app(BusinessGroupManagementGateway::class)->unassignedUsers());
        $this->assertCount(1, app(AgentBusinessGroupAssignmentGateway::class)->unassignedAgents());

        $this->actingAs($admin)
            ->get(route('configuration.users-and-notifications'))
            ->assertOk()
            ->assertSee('当前组内客服')
            ->assertSee('当前：NORTH');

        Livewire::test(UserManagement::class)
            ->assertSet('membershipEffectiveFrom', '2026-10-01')
            ->assertSet('assignmentEffectiveFrom', '2026-10-01');
    }

    public function test_configuration_can_end_open_membership_and_agent_assignment(): void
    {
        $admin = User::factory()->superAdmin()->withTwoFactor()->create();
        $user = User::factory()->create();
        $group = app(BusinessGroupManagementGateway::class)->create('NORTH', '北区业务组', $admin->id, null);
        app(BusinessGroupManagementGateway::class)->assignMember($group['id'], $user->id, '2026-08-01', null, '初始配置', $admin->id, null);
        $membership = BusinessGroupMembership::query()->where('user_id', $user->id)->firstOrFail();

        $type = AgentTypeCode::query()->create(['code' => 'END', 'name' => '结束测试代理', 'is_system' => false, 'is_active' => true]);
        $agent = Agent::query()->create([
            'agent_type_code_id' => $type->id,
            'code' => 'END-001',
            'name' => '结束测试代理商',
            'cooperation_status' => 'active',
        ]);
        app(AgentBusinessGroupAssignmentGateway::class)->assign($agent->id, $group['id'], '2026-08-01', null, '初始配置', $admin->id, null);
        $assignment = AgentBusinessGroupAssignment::query()->where('agent_id', $agent->id)->firstOrFail();

        $this->actingAs($admin);
        Livewire::test(UserManagement::class)
            ->assertSee(__('config.user_management.actions.end_membership'))
            ->assertSee(__('config.user_management.actions.end_assignment'))
            ->call('beginMembershipEnd', $membership->id)
            ->set('membershipEndDate', '2026-08-31')
            ->set('membershipEndReason', '转组')
            ->call('endMembership')
            ->assertHasNoErrors()
            ->call('beginAssignmentEnd', $assignment->id)
            ->set('assignmentEndDate', '2026-08-31')
            ->set('assignmentEndReason', '转组')
            ->call('endAssignment')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('business_group_memberships', [
            'id' => $membership->id,
            'effective_until' => '2026-08-31',
        ]);
        $this->assertDatabaseHas('agent_business_group_assignments', [
            'id' => $assignment->id,
            'effective_until' => '2026-08-31',
        ]);
    }

    public function test_agent_business_group_assignment_rejects_overlapping_periods_and_reports_unmapped_agents(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $type = AgentTypeCode::query()->create(['code' => 'UAT', 'name' => 'UAT代理', 'is_system' => false, 'is_active' => true]);
        $agent = Agent::query()->create([
            'agent_type_code_id' => $type->id,
            'code' => 'UAT-001',
            'name' => 'UAT代理商',
            'cooperation_status' => 'active',
        ]);
        $group = app(BusinessGroupManagementGateway::class)->create('NORTH', '北区业务组', $admin->id, null);
        $gateway = app(AgentBusinessGroupAssignmentGateway::class);

        $gateway->assign($agent->id, $group['id'], '2026-08-01', '2026-08-31', '初始配置', $admin->id, null);
        $this->assertCount(1, $gateway->unassignedAgents('2026-09-01'));

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('同一代理商的有效业务组归属期间不能重叠。');
        $gateway->assign($agent->id, $group['id'], '2026-08-15', null, '重复配置', $admin->id, null);
    }

    public function test_business_group_can_be_renamed_viewed_and_have_bd_replaced_without_moving_other_members(): void
    {
        $admin = User::factory()->superAdmin()->withTwoFactor()->create();
        $oldBd = User::factory()->create(['name' => '原BD', 'role' => UserRole::BdManager]);
        $newBd = User::factory()->create(['name' => '新BD', 'role' => UserRole::BdManager]);
        $customerService = User::factory()->create(['name' => '组内客服', 'role' => UserRole::CustomerService]);
        $group = app(BusinessGroupManagementGateway::class)->create('NORTH', '北区业务组', $admin->id, null);
        $groups = app(BusinessGroupManagementGateway::class);
        $groups->assignMember($group['id'], $oldBd->id, '2026-08-01', null, '初始BD', $admin->id, null);
        $groups->assignMember($group['id'], $customerService->id, '2026-08-01', null, '初始客服', $admin->id, null);

        app(ConfigurationUserCoordinator::class)
            ->updateBusinessGroupName($group['id'], '北区重点业务组', $admin->id, null);
        $groups->replaceBd($group['id'], $newBd->id, '2026-09-01', '人员调整', $admin->id, null);

        $this->assertDatabaseHas('business_groups', ['id' => $group['id'], 'code' => 'NORTH', 'name' => '北区重点业务组']);
        $this->assertDatabaseHas('business_group_memberships', ['business_group_id' => $group['id'], 'user_id' => $oldBd->id, 'effective_until' => '2026-08-31']);
        $this->assertDatabaseHas('business_group_memberships', ['business_group_id' => $group['id'], 'user_id' => $newBd->id, 'effective_from' => '2026-09-01', 'member_role' => 'bd_manager']);
        $this->assertDatabaseHas('business_group_memberships', ['business_group_id' => $group['id'], 'user_id' => $customerService->id, 'effective_until' => null]);

        $details = app(ConfigurationUserCoordinator::class)->businessGroupDetails($group['id']);
        $this->assertSame('北区重点业务组', $details['group']['name']);
        $this->assertCount(3, $details['memberships']);

        $this->actingAs($admin)
            ->get(route('configuration.users-and-notifications'))
            ->assertOk()
            ->assertSee('查看')
            ->assertSee('编辑名称')
            ->assertSee('更换BD')
            ->assertSee('停用/解散');
    }

    public function test_business_group_deactivation_requires_agents_to_be_handled_and_preserves_history(): void
    {
        $admin = User::factory()->superAdmin()->withTwoFactor()->create();
        $group = app(BusinessGroupManagementGateway::class)->create('CLOSE', '待停用业务组', $admin->id, null);
        $customerService = User::factory()->create(['role' => UserRole::CustomerService]);
        app(BusinessGroupManagementGateway::class)->assignMember($group['id'], $customerService->id, '2026-08-01', null, '初始客服', $admin->id, null);
        $type = AgentTypeCode::query()->create(['code' => 'CLS', 'name' => '停用测试代理', 'is_system' => false, 'is_active' => true]);
        $agent = Agent::query()->create(['agent_type_code_id' => $type->id, 'code' => 'CLS-001', 'name' => '待处理代理商', 'cooperation_status' => 'active']);
        $assignmentGateway = app(AgentBusinessGroupAssignmentGateway::class);
        $assignmentGateway->assign($agent->id, $group['id'], '2026-08-01', null, '初始归属', $admin->id, null);

        $coordinator = app(ConfigurationUserCoordinator::class);
        try {
            $coordinator->deactivateBusinessGroup($group['id'], '业务终止', $admin->id, null);
            $this->fail('A group with a current agent assignment must not be deactivated.');
        } catch (DomainException $exception) {
            $this->assertSame('该业务组仍有当前代理商归属，请先结束或转移代理商归属。', $exception->getMessage());
        }

        $assignment = AgentBusinessGroupAssignment::query()->where('agent_id', $agent->id)->firstOrFail();
        $endDate = CarbonImmutable::now()->subDay()->toDateString();
        $assignmentGateway->endAssignment($assignment->id, $endDate, '业务终止', $admin->id, null);
        $coordinator->deactivateBusinessGroup($group['id'], '业务终止', $admin->id, null);

        $this->assertDatabaseHas('business_groups', ['id' => $group['id'], 'is_active' => false]);
        $this->assertDatabaseHas('agent_business_group_assignments', ['id' => $assignment->id, 'effective_until' => $endDate]);
        $this->assertDatabaseHas('business_group_memberships', ['business_group_id' => $group['id'], 'user_id' => $customerService->id, 'effective_until' => CarbonImmutable::now()->toDateString()]);
    }

    public function test_user_and_permission_page_renders_role_group_and_agent_mapping_controls_in_korean(): void
    {
        $admin = User::factory()->superAdmin()->withTwoFactor()->create(['preferred_locale' => 'ko_KR']);

        $this->actingAs($admin)
            ->get(route('configuration.users-and-notifications'))
            ->assertOk()
            ->assertSee('업무 그룹')
            ->assertSee('업무 그룹 구성원 이력')
            ->assertSee('에이전시 업무 그룹 소속')
            ->assertSee('매핑 무결성 검사')
            ->assertSee('슈퍼 관리자');
    }
}
