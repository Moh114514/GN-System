<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Agent\Application\Contracts\AgentBusinessGroupAssignmentGateway;
use App\Modules\Agent\Infrastructure\Models\Agent;
use App\Modules\Agent\Infrastructure\Models\AgentTypeCode;
use App\Modules\Auth\Application\Contracts\AccessContextResolver;
use App\Modules\Auth\Application\Contracts\BusinessGroupManagementGateway;
use App\Modules\Auth\Application\Contracts\BusinessGroupMembershipReader;
use App\Modules\Auth\Application\Contracts\InternalUserReferenceReader;
use App\Modules\Auth\Domain\UserRole;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $admin = User::factory()->superAdmin()->create();
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

    public function test_membership_form_uses_readonly_user_role_and_shows_pending_configuration_candidate(): void
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
            ->assertDontSee('wire:model="membershipRole"', false)
            ->assertSee('readonly', false);
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
