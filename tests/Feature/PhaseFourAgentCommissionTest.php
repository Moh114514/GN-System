<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Agent\Application\Data\AgentProfileData;
use App\Modules\Agent\Application\Services\AgentDirectory;
use App\Modules\Agent\Application\Services\AgentManager;
use App\Modules\Agent\Application\Services\DatabaseReferenceConfigurationImportGateway;
use App\Modules\Agent\Infrastructure\Models\Agent;
use App\Modules\Agent\Infrastructure\Models\AgentGradeAssignment;
use App\Modules\Agent\Infrastructure\Models\AgentTypeCode;
use App\Modules\Agent\Infrastructure\Models\PolicyGrade;
use App\Modules\Agent\Infrastructure\Models\PolicySystem;
use App\Modules\Agent\Presentation\Livewire\AgentList;
use App\Modules\Config\Infrastructure\Models\Institution;
use App\Modules\Customer\Infrastructure\Models\Customer;
use App\Modules\Customer\Infrastructure\Models\DirectSalesSource;
use App\Modules\Order\Application\Contracts\DailyOrderGateway;
use App\Modules\Order\Application\Data\DailyOrderData;
use App\Modules\Order\Infrastructure\Models\Order;
use App\Modules\Settlement\Application\Contracts\CommissionConfigurationGateway;
use App\Modules\Settlement\Infrastructure\Models\AgentCommissionOverride;
use App\Modules\Settlement\Infrastructure\Models\CommissionRule;
use App\Modules\Settlement\Infrastructure\Models\OrderCommission;
use Carbon\CarbonImmutable;
use Database\Seeders\PhaseTwoReferenceDataSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class PhaseFourAgentCommissionTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private User $admin;

    private Agent $agent;

    private Customer $customer;

    private Institution $institution;

    private PolicyGrade $grade;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-07-28 10:00:00');
        $this->seed(PhaseTwoReferenceDataSeeder::class);
        $this->user = User::factory()->create();
        $this->admin = User::factory()->superAdmin()->withTwoFactor()->create();
        $system = PolicySystem::query()->create(['name' => '代理商计划', 'is_active' => true]);
        $this->grade = PolicyGrade::query()->create([
            'policy_system_id' => $system->id,
            'name' => '黄金',
            'monthly_threshold_krw' => 0,
            'sort_order' => 10,
            'is_active' => true,
        ]);
        $type = AgentTypeCode::query()->where('code', 'JG')->firstOrFail();
        $this->agent = Agent::query()->create([
            'agent_type_code_id' => $type->id,
            'code' => 'TEST-JG',
            'name' => '测试代理商',
            'cooperation_started_on' => '2026-01-01',
            'cooperation_status' => 'active',
        ]);
        AgentGradeAssignment::query()->create([
            'agent_id' => $this->agent->id,
            'policy_grade_id' => $this->grade->id,
            'effective_month' => '2026-07-01',
            'approved_by' => $this->admin->id,
            'reason' => '测试等级',
        ]);
        $this->institution = Institution::query()->firstOrFail();
        $this->customer = Customer::query()->create([
            'code' => 'TEST-JG-0001',
            'name' => '订单测试客户',
            'original_channel' => 'agent',
            'source_agent_id' => $this->agent->id,
            'owner_id' => $this->user->id,
        ]);
        CommissionRule::query()->create([
            'policy_grade_id' => $this->grade->id,
            'institution_id' => $this->institution->id,
            'rate_bps' => 1250,
            'effective_month' => '2026-07-01',
            'is_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_completed_agent_order_is_atomic_audited_and_snapshotted(): void
    {
        $orderId = app(DailyOrderGateway::class)->create($this->orderData('agent', 'completed', 10005));
        $commission = OrderCommission::query()->where('order_id', $orderId)->firstOrFail();

        $this->assertSame(1251, (int) $commission->amount_krw);
        $this->assertSame(1250, (int) $commission->rate_bps);
        $this->assertSame('grade_institution_rule', $commission->rule_snapshot['rule_source']);
        $this->assertSame('黄金', $commission->rule_snapshot['policy_grade']['name']);
        $this->assertSame(10005, $commission->rule_snapshot['order_amount_krw']);
        $this->assertDatabaseHas('activity_log', ['log_name' => 'commission', 'subject_id' => $commission->id, 'event' => 'calculated']);
        $this->assertDatabaseHas('activity_log', ['log_name' => 'order', 'subject_id' => $orderId, 'event' => 'created']);

        CommissionRule::query()->firstOrFail()->update(['rate_bps' => 500]);
        $this->assertSame(1250, OrderCommission::query()->findOrFail($commission->id)->rule_snapshot['rate_bps']);

        AgentGradeAssignment::query()->where('agent_id', $this->agent->id)->delete();
        $manager = app(AgentManager::class);
        $manager->correctGrade($this->agent->id, $this->grade->id, CarbonImmutable::parse('2026-04-01'), '历史等级纠错', $this->admin->id, null);
        $unchanged = OrderCommission::query()->findOrFail($commission->id);
        $this->assertSame(1250, (int) $unchanged->rate_bps);
        $this->assertSame(1251, (int) $unchanged->amount_krw);
        $this->assertSame(1250, $unchanged->rule_snapshot['rate_bps']);
    }

    public function test_institution_override_precedes_global_override_and_grade_rule(): void
    {
        AgentCommissionOverride::query()->create([
            'agent_id' => $this->agent->id,
            'institution_id' => null,
            'rate_bps' => 1400,
            'effective_from' => '2026-07-01',
            'reason' => '全机构特批',
            'approved_by' => $this->admin->id,
        ]);
        $specific = AgentCommissionOverride::query()->create([
            'agent_id' => $this->agent->id,
            'institution_id' => $this->institution->id,
            'rate_bps' => 1600,
            'effective_from' => '2026-07-01',
            'reason' => '机构特批',
            'approved_by' => $this->admin->id,
        ]);

        $orderId = app(DailyOrderGateway::class)->create($this->orderData('agent', 'completed', 10000));
        $commission = OrderCommission::query()->where('order_id', $orderId)->firstOrFail();

        $this->assertSame(1600, (int) $commission->amount_krw);
        $this->assertSame('agent_institution_override', $commission->rule_snapshot['rule_source']);
        $this->assertSame($specific->id, $commission->rule_snapshot['rule_id']);
    }

    public function test_missing_rule_rolls_completion_back_and_duplicate_completion_is_idempotent(): void
    {
        $gateway = app(DailyOrderGateway::class);
        $pendingId = $gateway->create($this->orderData('agent', 'pending', 10000));
        CommissionRule::query()->delete();

        try {
            $gateway->complete($pendingId, CarbonImmutable::parse('2026-07-28'), $this->user->id, null);
            $this->fail('Expected missing commission rule.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('没有生效的推广费率', $exception->getMessage());
        }
        $this->assertSame('pending', Order::query()->findOrFail($pendingId)->status);
        $this->assertDatabaseMissing('order_commissions', ['order_id' => $pendingId]);

        CommissionRule::query()->create([
            'policy_grade_id' => $this->grade->id,
            'institution_id' => $this->institution->id,
            'rate_bps' => 1000,
            'effective_month' => '2026-07-01',
            'is_active' => true,
        ]);
        $gateway->complete($pendingId, CarbonImmutable::parse('2026-07-28'), $this->user->id, null);
        $gateway->complete($pendingId, CarbonImmutable::parse('2026-07-29'), $this->user->id, null);
        $this->assertDatabaseCount('order_commissions', 1);
        $this->assertSame('2026-07-28', Order::query()->findOrFail($pendingId)->completed_on?->format('Y-m-d'));
    }

    public function test_direct_order_has_no_commission_and_paused_agent_is_blocked(): void
    {
        $direct = DirectSalesSource::query()->create(['code' => 'WEB', 'name' => '官网', 'is_active' => true]);
        $directCustomer = Customer::query()->create([
            'code' => 'WEB-000001',
            'name' => '直销客户',
            'original_channel' => 'direct',
            'source_direct_sales_id' => $direct->id,
        ]);
        $orderId = app(DailyOrderGateway::class)->create(new DailyOrderData(
            customerId: $directCustomer->id,
            institutionId: $this->institution->id,
            channel: 'direct',
            agentId: null,
            directSalesSourceId: $direct->id,
            projectName: '直销项目',
            amountKrw: 10000,
            status: 'completed',
            completedOn: CarbonImmutable::parse('2026-07-28'),
            translatorName: null,
            notes: null,
            ownerId: $this->user->id,
            ipAddress: null,
        ));
        $this->assertDatabaseMissing('order_commissions', ['order_id' => $orderId]);

        $this->agent->update(['cooperation_status' => 'paused']);
        $this->expectException(DomainException::class);
        app(DailyOrderGateway::class)->create($this->orderData('agent', 'pending', 10000));
    }

    public function test_agent_number_is_immutable_and_termination_is_permanent(): void
    {
        $vip = AgentTypeCode::query()->create(['code' => 'VIP', 'name' => 'VIP 合伙人', 'is_active' => true]);
        $manager = app(AgentManager::class);
        $profile = new AgentProfileData(
            typeCodeId: $vip->id,
            codePrefix: 'LH',
            name: '刘会长',
            businessRole: null,
            contactName: '刘女士',
            contactValue: '010-123456',
            cooperationStartedOn: CarbonImmutable::parse('2026-07-28'),
            cooperationEndedOn: null,
            cooperationStatus: 'active',
            policyGradeId: $this->grade->id,
            notes: null,
        );
        $id = $manager->create($profile, $this->admin->id, null);
        $this->assertDatabaseHas('agents', ['id' => $id, 'code' => 'LH-VIP']);
        $manager->saveType($vip->id, 'V2P', '升级后的 VIP 合伙人', '仅影响后续新编号', $this->admin->id, null);
        $this->assertDatabaseHas('agents', ['id' => $id, 'code' => 'LH-VIP']);
        $this->assertDatabaseHas('agent_type_codes', ['id' => $vip->id, 'code' => 'V2P']);
        $this->assertSame(
            'audit.messages.agent_type_saved',
            Activity::query()->where('log_name', 'agent-configuration')->latest('id')->firstOrFail()->properties['message_key'],
        );

        $manager->update($id, new AgentProfileData(
            typeCodeId: AgentTypeCode::query()->where('code', 'JG')->value('id'),
            codePrefix: 'CHANGED',
            name: '刘会长',
            businessRole: null,
            contactName: null,
            contactValue: null,
            cooperationStartedOn: CarbonImmutable::parse('2026-07-28'),
            cooperationEndedOn: CarbonImmutable::parse('2026-07-28'),
            cooperationStatus: 'terminated',
            policyGradeId: $this->grade->id,
            notes: null,
        ), $this->admin->id, null);
        $this->assertDatabaseHas('agents', ['id' => $id, 'code' => 'LH-VIP', 'cooperation_status' => 'terminated']);

        app()->setLocale('ko_KR');
        try {
            $manager->update($id, $profile, $this->admin->id, null);
            $this->fail('Expected terminated agent update to fail.');
        } catch (DomainException $exception) {
            $this->assertSame(__('agents.validation.terminated_read_only'), $exception->getMessage());
        }
    }

    public function test_current_month_configuration_is_locked_after_creation(): void
    {
        $gateway = app(CommissionConfigurationGateway::class);
        $gateway->saveRule($this->grade->id, $this->institution->id, 1500, CarbonImmutable::parse('2026-08-01'), $this->admin->id, null);
        $this->assertDatabaseHas('commission_rules', ['effective_month' => '2026-08-01', 'rate_bps' => 1500]);

        $this->expectException(DomainException::class);
        $gateway->saveRule($this->grade->id, $this->institution->id, 1300, CarbonImmutable::parse('2026-07-01'), $this->admin->id, null);
    }

    public function test_missing_current_grade_requires_explicit_correction_and_becomes_effective_immediately(): void
    {
        $agent = Agent::query()->create([
            'agent_type_code_id' => AgentTypeCode::query()->where('code', 'JG')->value('id'),
            'code' => 'NO-GRADE-JG',
            'name' => '无等级代理商',
            'cooperation_started_on' => '2026-01-15',
            'cooperation_status' => 'active',
        ]);
        $manager = app(AgentManager::class);
        $data = new AgentProfileData(
            typeCodeId: (int) $agent->agent_type_code_id,
            codePrefix: 'NO-GRADE',
            name: $agent->name,
            businessRole: null,
            contactName: null,
            contactValue: null,
            cooperationStartedOn: CarbonImmutable::parse('2026-01-15'),
            cooperationEndedOn: null,
            cooperationStatus: 'active',
            policyGradeId: null,
            notes: null,
        );
        $manager->update($agent->id, $data, $this->admin->id, null);
        $this->assertDatabaseMissing('agent_grade_assignments', ['agent_id' => $agent->id]);

        $manager->correctGrade($agent->id, $this->grade->id, CarbonImmutable::parse('2026-04-01'), '历史等级补录', $this->admin->id, null);
        $this->assertDatabaseHas('agent_grade_assignments', [
            'agent_id' => $agent->id,
            'policy_grade_id' => $this->grade->id,
            'effective_month' => '2026-04-01',
            'reason' => '历史等级补录',
        ]);
        $profile = app(AgentDirectory::class)->profile($agent->id);
        $this->assertSame($this->grade->id, $profile['policy_grade_id']);
        $this->assertSame('2026-04-01', $profile['grade_effective_month']);
    }

    public function test_grade_correction_rejects_agent_that_already_has_current_grade(): void
    {
        $this->expectException(DomainException::class);
        app(AgentManager::class)->correctGrade(
            $this->agent->id,
            $this->grade->id,
            CarbonImmutable::parse('2026-04-01'),
            '不应绕过正常调级流程',
            $this->admin->id,
            null,
        );
    }

    public function test_existing_grade_change_remains_scheduled_for_next_month(): void
    {
        $next = PolicyGrade::query()->create([
            'policy_system_id' => $this->grade->policy_system_id,
            'name' => '白金',
            'monthly_threshold_krw' => 0,
            'sort_order' => 20,
            'is_active' => true,
        ]);
        app(AgentManager::class)->update($this->agent->id, new AgentProfileData(
            typeCodeId: (int) $this->agent->agent_type_code_id,
            codePrefix: 'TEST',
            name: $this->agent->name,
            businessRole: null,
            contactName: null,
            contactValue: null,
            cooperationStartedOn: CarbonImmutable::parse('2026-01-01'),
            cooperationEndedOn: null,
            cooperationStatus: 'active',
            policyGradeId: $next->id,
            notes: null,
        ), $this->admin->id, null);
        $this->assertDatabaseHas('agent_grade_assignments', [
            'agent_id' => $this->agent->id,
            'policy_grade_id' => $next->id,
            'effective_month' => '2026-08-01',
        ]);
        $this->assertSame($this->grade->id, app(AgentDirectory::class)->profile($this->agent->id)['policy_grade_id']);
    }

    public function test_normal_grade_import_never_overwrites_current_or_schedules_missing_grade(): void
    {
        $gateway = app(DatabaseReferenceConfigurationImportGateway::class);
        $row = [
            'agent_code' => $this->agent->code,
            'policy_system' => PolicySystem::query()->findOrFail($this->grade->policy_system_id)->name,
            'policy_grade' => $this->grade->name,
            'effective_month' => '2026-07-01',
            'reason' => '普通导入当前月',
        ];

        $this->expectException(DomainException::class);
        $gateway->upsertGradeAssignments([$row], $this->admin->id, 'normal-batch');
    }

    public function test_historical_grade_import_is_past_only_and_idempotent(): void
    {
        $agent = Agent::query()->create([
            'agent_type_code_id' => AgentTypeCode::query()->where('code', 'JG')->value('id'),
            'code' => 'HISTORY-JG',
            'name' => '历史等级代理商',
            'cooperation_started_on' => '2026-01-15',
            'cooperation_status' => 'active',
        ]);
        $row = [
            'agent_code' => $agent->code,
            'policy_system' => PolicySystem::query()->findOrFail($this->grade->policy_system_id)->name,
            'policy_grade' => $this->grade->name,
            'effective_month' => '2026-04-01',
            'reason' => '历史等级迁移',
        ];
        $gateway = app(DatabaseReferenceConfigurationImportGateway::class);
        $batchId = (string) Str::uuid();
        $gateway->importHistoricalGradeAssignments([$row], $this->admin->id, $batchId, null);
        $gateway->importHistoricalGradeAssignments([$row], $this->admin->id, $batchId, null);
        $this->assertSame(1, AgentGradeAssignment::query()->where('agent_id', $agent->id)->count());

        $this->expectException(DomainException::class);
        $gateway->importHistoricalGradeAssignments([['agent_code' => $agent->code, 'policy_system' => $row['policy_system'], 'policy_grade' => $row['policy_grade'], 'effective_month' => '2026-08-01', 'reason' => '未来']], $this->admin->id, $batchId, null);
    }

    public function test_phase_four_pages_enforce_roles_and_navigation(): void
    {
        $this->actingAs($this->user)->get(route('agents.index'))->assertForbidden();
        $this->actingAs($this->admin)->get(route('agents.index'))
            ->assertOk()
            ->assertSee('代理商管理')
            ->assertSee('新建代理商');
        $this->actingAs($this->admin)->get(route('agents.show', $this->agent->id))
            ->assertOk()
            ->assertSee('<dd class="mt-1 font-semibold">测试代理商</dd>', false)
            ->assertSee('代理商编号')
            ->assertSee('政策体系')
            ->assertSee('当前等级')
            ->assertSee('返回代理商管理')
            ->assertSee('href="'.route('agents.index').'"', false)
            ->assertSee(__('agents.detail.grade_history'));
        $this->actingAs($this->admin)->get(route('agent-configuration.index'))
            ->assertOk()
            ->assertSee('代理商与推广费配置')
            ->assertSee('返回配置中心')
            ->assertSee('href="'.route('configuration.index').'"', false);
        $this->actingAs($this->user)->get(route('customers.orders', $this->customer->id))
            ->assertOk()
            ->assertSee('<span class="font-semibold">订单测试客户</span>', false)
            ->assertSee('返回客户详情')
            ->assertSee('href="'.route('customers.show', $this->customer->id).'"', false)
            ->assertDontSee('completionDate');
    }

    public function test_korean_admin_sees_translated_agent_list_labels(): void
    {
        $this->admin->update(['preferred_locale' => 'ko_KR']);

        $this->actingAs($this->admin)->get(route('agents.index'))
            ->assertOk()
            ->assertSee('<html lang="ko-KR"', false)
            ->assertSee('에이전트 관리')
            ->assertSee('에이전트 추가');

        $this->actingAs($this->admin)->get(route('agent-configuration.index'))
            ->assertOk()
            ->assertSee('설정 센터로 돌아가기')
            ->assertSee('에이전시 및 수수료 설정')
            ->assertDontSee('代理商与推广费配置');
    }

    public function test_agent_list_filters_by_type_current_policy_system_and_current_grade(): void
    {
        $secondType = AgentTypeCode::query()->where('code', 'GT')->firstOrFail();
        $secondSystem = PolicySystem::query()->create(['name' => '合伙人计划', 'is_active' => true]);
        $secondGrade = PolicyGrade::query()->create([
            'policy_system_id' => $secondSystem->id,
            'name' => '白金合伙人',
            'monthly_threshold_krw' => 0,
            'sort_order' => 10,
            'is_active' => true,
        ]);
        $secondAgent = Agent::query()->create([
            'agent_type_code_id' => $secondType->id,
            'code' => 'SECOND-GT',
            'name' => '第二代理商',
            'cooperation_status' => 'active',
        ]);
        AgentGradeAssignment::query()->create([
            'agent_id' => $secondAgent->id,
            'policy_grade_id' => $secondGrade->id,
            'effective_month' => '2026-07-01',
            'approved_by' => $this->admin->id,
            'reason' => '筛选测试',
        ]);
        $directory = app(AgentDirectory::class);

        $byType = $directory->paginate('', '', $secondType->id);
        $this->assertSame([$secondAgent->id], array_column($byType->items(), 'id'));
        $bySystem = $directory->paginate('', '', null, $secondSystem->id);
        $this->assertSame([$secondAgent->id], array_column($bySystem->items(), 'id'));
        $byGrade = $directory->paginate('', '', null, null, $this->grade->id);
        $this->assertSame([$this->agent->id], array_column($byGrade->items(), 'id'));

        Livewire::actingAs($this->admin)
            ->test(AgentList::class)
            ->set('typeCodeId', (string) $secondType->id)
            ->set('policySystemId', (string) $secondSystem->id)
            ->set('policyGradeId', (string) $secondGrade->id)
            ->call('clearFilters')
            ->assertSet('typeCodeId', '')
            ->assertSet('policySystemId', '')
            ->assertSet('policyGradeId', '');
    }

    private function orderData(string $channel, string $status, int $amountKrw): DailyOrderData
    {
        return new DailyOrderData(
            customerId: $this->customer->id,
            institutionId: $this->institution->id,
            channel: $channel,
            agentId: $channel === 'agent' ? $this->agent->id : null,
            directSalesSourceId: null,
            projectName: '皮肤管理',
            amountKrw: $amountKrw,
            status: $status,
            completedOn: $status === 'completed' ? CarbonImmutable::parse('2026-07-28') : null,
            translatorName: null,
            notes: null,
            ownerId: $this->user->id,
            ipAddress: '127.0.0.1',
        );
    }
}
