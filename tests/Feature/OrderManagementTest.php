<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Agent\Application\Contracts\AgentBusinessGroupAssignmentGateway;
use App\Modules\Agent\Infrastructure\Models\Agent;
use App\Modules\Agent\Infrastructure\Models\AgentGradeAssignment;
use App\Modules\Agent\Infrastructure\Models\AgentTypeCode;
use App\Modules\Agent\Infrastructure\Models\PolicyGrade;
use App\Modules\Agent\Infrastructure\Models\PolicySystem;
use App\Modules\Auth\Application\Contracts\BusinessGroupManagementGateway;
use App\Modules\Auth\Domain\UserRole;
use App\Modules\Config\Infrastructure\Models\DictionaryItem;
use App\Modules\Config\Infrastructure\Models\Institution;
use App\Modules\Customer\Infrastructure\Models\Customer;
use App\Modules\Order\Infrastructure\Models\Order;
use App\Modules\Order\Presentation\Livewire\OrderCenter;
use App\Modules\Order\Presentation\Livewire\OrderDetail;
use App\Modules\Order\Presentation\Livewire\OrderEdit;
use App\Modules\Settlement\Infrastructure\Models\CommissionRule;
use Database\Seeders\PhaseTwoReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class OrderManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_internal_user_can_open_order_center_from_primary_navigation(): void
    {
        $user = User::factory()->superAdmin()->withTwoFactor()->create();

        $this->actingAs($user)->get(route('orders.index'))
            ->assertOk()
            ->assertSee('订单管理')
            ->assertSee('机构表单回传')
            ->assertDontSee('新建订单')
            ->assertDontSee('标记完成')
            ->assertSee('href="'.route('orders.index').'"', false)
            ->assertDontSee('功能将在后续阶段开放');
    }

    public function test_order_center_clamps_long_project_names_without_changing_the_source_text(): void
    {
        $this->seed(PhaseTwoReferenceDataSeeder::class);
        $user = User::factory()->create();
        $institution = Institution::query()->firstOrFail();
        $agent = $this->agent();
        $customer = $this->customer($agent, $user->id);
        $projectName = '超声刀400 240万韩元，皮秒祛斑（Pico toning）20万韩元，Lituo 1V，面部提升及长期护理组合项目';
        $order = Order::query()->create([
            'customer_id' => $customer->id,
            'institution_id' => $institution->id,
            'agent_id' => $agent->id,
            'project_name' => $projectName,
            'amount_krw' => 2400000,
            'status' => 'pending',
            'owner_id' => $user->id,
        ]);

        Livewire::actingAs($user)->test(OrderCenter::class)
            ->assertSee($projectName)
            ->assertSee('w-[32rem] max-w-[32rem]', false)
            ->assertSee('line-clamp-2', false)
            ->assertSee('title="'.$projectName.'"', false)
            ->assertSee('#'.$order->id);
    }

    public function test_korean_users_see_translated_order_center_labels(): void
    {
        $user = User::factory()->create(['preferred_locale' => 'ko_KR']);

        $this->actingAs($user)->get(route('orders.index'))
            ->assertOk()
            ->assertSee('주문 관리')
            ->assertSee('기관 양식 회신')
            ->assertDontSee('새 주문')
            ->assertDontSee('완료로 표시')
            ->assertSee('주문 번호, 고객 또는 프로젝트 검색');
    }

    public function test_order_center_routes_formal_orders_through_institution_return_page(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get(route('institution-returns.index'))
            ->assertOk()
            ->assertSee('机构表单回传')
            ->assertSee('下载固定模板')
            ->assertSee('校验并生成订单');
    }

    public function test_pending_order_can_be_edited_and_detail_page_has_parent_navigation(): void
    {
        $this->seed(PhaseTwoReferenceDataSeeder::class);
        $user = User::factory()->superAdmin()->withTwoFactor()->create();
        $institution = Institution::query()->firstOrFail();
        $agent = $this->agent();
        $customer = Customer::query()->create([
            'code' => 'TEST-JG-0003',
            'name' => '订单详情客户',
            'source_agent_id' => $agent->id,
            'owner_id' => $user->id,
        ]);

        $order = Order::query()->create([
            'customer_id' => $customer->id,
            'institution_id' => $institution->id,
            'agent_id' => $agent->id,
            'project_name' => '初始项目',
            'amount_krw' => 100000,
            'status' => 'pending',
            'owner_id' => $user->id,
        ]);
        $this->actingAs($user)->get(route('orders.show', $order))
            ->assertOk()
            ->assertSee('订单详情')
            ->assertSee('返回订单管理')
            ->assertSee('href="'.route('orders.index').'"', false);

        $this->actingAs($user)->get(route('orders.edit', $order))
            ->assertOk()
            ->assertSee('编辑订单状态')
            ->assertSee('href="'.route('orders.show', $order).'#status-editor"', false);

        Livewire::actingAs($user)
            ->test(OrderEdit::class, ['order' => $order->id])
            ->set('projectName', '更新后的项目')
            ->set('amountKrw', '120000')
            ->set('unitPriceKrw', '120000')
            ->set('reason', '修正订单项目和金额')
            ->call('save')
            ->assertHasNoErrors();

        $project = DictionaryItem::query()->create([
            'type' => 'treatment_project',
            'code' => 'LASER',
            'name' => '激光治疗',
            'is_active' => true,
        ]);
        $language = DictionaryItem::query()->create([
            'type' => 'translator_language',
            'code' => 'KO',
            'name' => '韩语',
            'is_active' => true,
        ]);

        Livewire::actingAs($user)
            ->test(OrderEdit::class, ['order' => $order->id])
            ->set('treatmentProjectId', (string) $project->id)
            ->set('projectName', '手工篡改名称')
            ->set('translatorLanguageId', (string) $language->id)
            ->set('reason', '改用标准项目和语种')
            ->call('save')
            ->assertHasNoErrors();

        $project->update(['name' => '重命名项目']);
        $language->update(['name' => '重命名语种']);
        Livewire::actingAs($user)
            ->test(OrderEdit::class, ['order' => $order->id])
            ->set('notes', '仅修改备注')
            ->set('reason', '补充订单备注')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'project_name' => '激光治疗',
            'treatment_project_id' => $project->id,
            'treatment_project_snapshot' => '激光治疗',
            'translator_language_id' => $language->id,
            'translator_language_snapshot' => '韩语',
            'amount_krw' => 120000,
            'status' => 'pending',
        ]);
        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'order',
            'subject_id' => $order->id,
            'event' => 'updated',
        ]);
        $latestAudit = DB::table('activity_log')->where('log_name', 'order')->where('subject_id', $order->id)->where('event', 'updated')->latest('id')->value('properties');
        $this->assertStringContainsString('treatment_project_id', (string) $latestAudit);
        $this->assertStringContainsString('treatment_project_snapshot', (string) $latestAudit);
    }

    public function test_customer_service_cannot_open_order_edit_page(): void
    {
        $this->seed(PhaseTwoReferenceDataSeeder::class);
        $user = User::factory()->create();
        $institution = Institution::query()->firstOrFail();
        $agent = $this->agent();
        $customer = Customer::query()->create([
            'code' => 'TEST-JG-CS-EDIT',
            'name' => '客服编辑权限客户',
            'source_agent_id' => $agent->id,
            'owner_id' => $user->id,
        ]);
        $order = Order::query()->create([
            'customer_id' => $customer->id,
            'institution_id' => $institution->id,
            'agent_id' => $agent->id,
            'project_name' => '客服不可编辑订单',
            'amount_krw' => 10000,
            'status' => 'pending',
            'owner_id' => $user->id,
        ]);

        $this->actingAs($user)->get(route('orders.edit', $order))->assertNotFound();
    }

    public function test_bd_can_edit_only_orders_for_assigned_agents(): void
    {
        $this->seed(PhaseTwoReferenceDataSeeder::class);
        $admin = User::factory()->superAdmin()->withTwoFactor()->create();
        $bd = User::factory()->create(['role' => UserRole::BdManager]);
        $institution = Institution::query()->firstOrFail();
        $assignedAgent = $this->agent();
        $otherAgent = Agent::query()->create([
            'agent_type_code_id' => $assignedAgent->agent_type_code_id,
            'code' => 'JG-OUT-OF-SCOPE',
            'name' => '范围外代理商',
            'cooperation_status' => 'active',
        ]);
        $groups = app(BusinessGroupManagementGateway::class);
        $groupId = $groups->create('PR5-SCOPE', 'PR5 scope', $admin->id, null)['id'];
        $groups->assignMember($groupId, $bd->id, '2026-01-01', null, 'PR5 scope', $admin->id, null);
        app(AgentBusinessGroupAssignmentGateway::class)->assign($assignedAgent->id, $groupId, '2026-01-01', null, 'PR5 scope', $admin->id, null);
        $customer = Customer::query()->create([
            'code' => 'PR5-SCOPE-CUSTOMER',
            'name' => 'PR5 scope customer',
            'source_agent_id' => $assignedAgent->id,
            'owner_id' => $bd->id,
        ]);
        $assignedOrder = Order::query()->create([
            'customer_id' => $customer->id,
            'institution_id' => $institution->id,
            'agent_id' => $assignedAgent->id,
            'project_name' => 'assigned order',
            'amount_krw' => 10000,
            'status' => 'pending',
            'owner_id' => $bd->id,
        ]);
        $outOfScopeOrder = Order::query()->create([
            'customer_id' => $customer->id,
            'institution_id' => $institution->id,
            'agent_id' => $otherAgent->id,
            'project_name' => 'out of scope order',
            'amount_krw' => 10000,
            'status' => 'pending',
            'owner_id' => $bd->id,
        ]);

        $this->actingAs($bd)->get(route('orders.edit', $assignedOrder))->assertOk();
        $this->actingAs($bd)->get(route('orders.edit', $outOfScopeOrder))->assertNotFound();
    }

    public function test_admin_can_cancel_soft_delete_restore_and_reopen_pending_order(): void
    {
        $this->seed(PhaseTwoReferenceDataSeeder::class);
        $user = User::factory()->create();
        $admin = User::factory()->create(['is_super_admin' => true, 'two_factor_confirmed_at' => now()]);
        $institution = Institution::query()->firstOrFail();
        $agent = $this->agent();
        $this->scope($agent, $user->id);
        $customer = Customer::query()->create([
            'code' => 'TEST-JG-0004',
            'name' => '订单生命周期客户',
            'source_agent_id' => $agent->id,
            'owner_id' => $user->id,
        ]);
        $order = Order::query()->create([
            'customer_id' => $customer->id,
            'institution_id' => $institution->id,
            'agent_id' => $agent->id,
            'project_name' => '生命周期项目',
            'amount_krw' => 200000,
            'status' => 'pending',
            'owner_id' => $user->id,
        ]);

        Livewire::actingAs($user)
            ->test(OrderDetail::class, ['order' => $order->id])
            ->set('reason', '普通用户不应执行取消')
            ->set('statusSelection', 'cancelled')
            ->call('changeStatus')
            ->assertStatus(403);

        Livewire::actingAs($admin)
            ->test(OrderDetail::class, ['order' => $order->id])
            ->set('reason', '客户主动取消')
            ->set('statusSelection', 'cancelled')
            ->call('changeStatus')
            ->assertHasNoErrors()
            ->set('reason', '取消订单归档')
            ->call('softDelete')
            ->assertHasNoErrors();

        $this->assertSoftDeleted('orders', ['id' => $order->id]);
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => 'cancelled',
            'cancellation_reason' => '客户主动取消',
            'deletion_reason' => '取消订单归档',
        ]);

        Livewire::actingAs($admin)
            ->test(OrderDetail::class, ['order' => $order->id])
            ->call('restore')
            ->assertHasNoErrors()
            ->set('reason', '确认客户仍需继续办理')
            ->call('reopen')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => 'pending',
            'deleted_at' => null,
            'cancelled_at' => null,
        ]);
        $this->assertDatabaseHas('activity_log', ['log_name' => 'order', 'subject_id' => $order->id, 'event' => 'cancelled']);
        $this->assertDatabaseHas('activity_log', ['log_name' => 'order', 'subject_id' => $order->id, 'event' => 'deleted']);
        $this->assertDatabaseHas('activity_log', ['log_name' => 'order', 'subject_id' => $order->id, 'event' => 'restored']);
        $this->assertDatabaseHas('activity_log', ['log_name' => 'order', 'subject_id' => $order->id, 'event' => 'reopened']);
    }

    public function test_super_admin_can_rollback_completed_order_with_reason(): void
    {
        $this->seed(PhaseTwoReferenceDataSeeder::class);
        $user = User::factory()->create();
        $admin = User::factory()->create(['is_super_admin' => true, 'two_factor_confirmed_at' => now()]);
        $institution = Institution::query()->firstOrFail();
        $agent = $this->agent();
        $customer = $this->customer($agent, $user->id);
        $order = Order::query()->create([
            'customer_id' => $customer->id,
            'institution_id' => $institution->id,
            'agent_id' => $agent->id,
            'project_name' => '回退项目',
            'amount_krw' => 100000,
            'status' => 'completed',
            'completed_on' => '2026-08-03',
            'completed_at' => '2026-08-03 14:00:00',
            'completion_precision' => 'datetime',
            'owner_id' => $user->id,
        ]);

        Livewire::actingAs($admin)
            ->test(OrderDetail::class, ['order' => $order->id])
            ->set('statusSelection', 'pending')
            ->set('reason', '客户要求重新确认成交信息')
            ->call('changeStatus')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => 'pending',
            'completed_on' => null,
            'completed_at' => null,
            'completion_precision' => 'date',
        ]);
        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'order',
            'subject_id' => $order->id,
            'event' => 'completion_rolled_back',
        ]);
    }

    public function test_non_admin_cannot_read_recycle_bin_or_deleted_order_detail(): void
    {
        $this->seed(PhaseTwoReferenceDataSeeder::class);
        $admin = User::factory()->create(['is_super_admin' => true, 'two_factor_confirmed_at' => now()]);
        $user = User::factory()->create();
        $institution = Institution::query()->firstOrFail();
        $agent = $this->agent();
        $customer = $this->customer($agent, $user->id);
        $order = Order::query()->create([
            'customer_id' => $customer->id,
            'institution_id' => $institution->id,
            'agent_id' => $agent->id,
            'project_name' => '待删除订单',
            'amount_krw' => 100000,
            'status' => 'cancelled',
            'owner_id' => $user->id,
            'cancellation_reason' => '测试取消',
        ]);

        Livewire::actingAs($admin)
            ->test(OrderDetail::class, ['order' => $order->id])
            ->set('reason', '测试回收站')
            ->call('softDelete')
            ->assertHasNoErrors();

        $this->actingAs($user)->get(route('orders.show', $order->id))->assertNotFound();
        $this->actingAs($user)->get(route('orders.recycle-bin'))->assertForbidden();
        $this->actingAs($admin)->get(route('orders.index'))
            ->assertOk()
            ->assertSee('回收站')
            ->assertSee('href="'.route('orders.recycle-bin').'"', false);
        $this->actingAs($admin)->get(route('orders.recycle-bin'))
            ->assertOk()
            ->assertSee('订单回收站')
            ->assertSee('返回订单管理')
            ->assertSee('待删除订单');
        $this->actingAs($admin)->get(route('orders.show', $order->id))->assertOk();
    }

    public function test_order_lifecycle_migration_refuses_rollback_when_business_data_exists(): void
    {
        $this->seed(PhaseTwoReferenceDataSeeder::class);
        $user = User::factory()->create();
        $institution = Institution::query()->firstOrFail();
        $agent = $this->agent();
        $customer = $this->customer($agent, $user->id);
        $order = Order::query()->create([
            'customer_id' => $customer->id,
            'institution_id' => $institution->id,
            'agent_id' => $agent->id,
            'project_name' => '回滚测试订单',
            'amount_krw' => 100000,
            'status' => 'pending',
            'owner_id' => $user->id,
        ]);
        $order->update([
            'status' => 'cancelled',
            'cancellation_reason' => '保留业务事实',
        ]);

        $migration = require database_path('migrations/2026_08_03_000500_add_order_lifecycle_management.php');
        $this->expectException(\RuntimeException::class);
        $migration->down();
    }

    private function agent(): Agent
    {
        $agent = Agent::query()->firstOrCreate(
            ['code' => 'TEST-JG'],
            [
                'agent_type_code_id' => AgentTypeCode::query()->where('code', 'JG')->value('id'),
                'name' => '测试代理商',
                'cooperation_started_on' => '2026-01-01',
                'cooperation_status' => 'active',
            ],
        );

        $system = PolicySystem::query()->firstOrCreate(['name' => '测试政策'], ['is_active' => true]);
        $grade = PolicyGrade::query()->firstOrCreate(
            ['policy_system_id' => $system->id, 'name' => '测试等级'],
            ['sort_order' => 10, 'is_active' => true],
        );
        AgentGradeAssignment::query()->firstOrCreate(
            ['agent_id' => $agent->id, 'policy_grade_id' => $grade->id, 'effective_month' => now()->startOfMonth()],
            ['reason' => '订单测试'],
        );
        CommissionRule::query()->firstOrCreate(
            ['policy_grade_id' => $grade->id, 'institution_id' => Institution::query()->firstOrFail()->id, 'effective_month' => now()->startOfMonth()],
            ['rate_bps' => 1000, 'is_active' => true],
        );

        return $agent;
    }

    private function customer(Agent $agent, int $ownerId): Customer
    {
        $this->scope($agent, $ownerId);

        return Customer::query()->create([
            'code' => 'TEST-JG-0001',
            'name' => '测试订单客户',
            'source_agent_id' => $agent->id,
            'owner_id' => $ownerId,
        ]);
    }

    private function scope(Agent $agent, int $ownerId): void
    {
        $groups = app(BusinessGroupManagementGateway::class);
        $groupId = $groups->create('ORDER-'.$ownerId.'-'.$agent->id, 'Order test scope', $ownerId, null)['id'];
        $groups->assignMember($groupId, $ownerId, '2026-01-01', null, 'Order test scope', $ownerId, null);
        app(AgentBusinessGroupAssignmentGateway::class)->assign($agent->id, $groupId, '2026-01-01', null, 'Order test scope', $ownerId, null);
    }
}
