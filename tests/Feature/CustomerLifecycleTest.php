<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Agent\Infrastructure\Models\Agent;
use App\Modules\Agent\Infrastructure\Models\AgentTypeCode;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Config\Infrastructure\Models\Institution;
use App\Modules\Customer\Application\Data\CustomerProfileData;
use App\Modules\Customer\Application\Exceptions\CustomerCodeChanged;
use App\Modules\Customer\Application\Services\CustomerDirectory;
use App\Modules\Customer\Application\Services\CustomerFollowupManager;
use App\Modules\Customer\Application\Services\CustomerProfileManager;
use App\Modules\Customer\Application\Services\CustomerStatusManager;
use App\Modules\Customer\Infrastructure\Models\Customer;
use App\Modules\Customer\Infrastructure\Models\CustomerLifecycleStage;
use App\Modules\Customer\Infrastructure\Models\CustomerStatus;
use App\Modules\Customer\Infrastructure\Models\CustomerStatusHistory;
use App\Modules\Customer\Infrastructure\Models\CustomerStatusTransition;
use App\Modules\Customer\Presentation\Livewire\CustomerDetail;
use App\Modules\Customer\Presentation\Livewire\CustomerList;
use Carbon\CarbonImmutable;
use Database\Seeders\PhaseTwoReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class CustomerLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Agent $agent;

    private Institution $institution;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PhaseTwoReferenceDataSeeder::class);
        $this->user = User::factory()->create();
        $type = AgentTypeCode::query()->where('code', 'JG')->firstOrFail();
        $this->agent = Agent::query()->create([
            'agent_type_code_id' => $type->id,
            'code' => 'TEST-JG',
            'name' => '测试代理商',
            'cooperation_status' => 'active',
        ]);
        $this->institution = Institution::query()->firstOrFail();
    }

    public function test_agent_customer_creation_is_atomic_and_audited(): void
    {
        $manager = app(CustomerProfileManager::class);
        $code = $manager->previewCode($this->agent->id);
        $customerId = $manager->create(
            profile: $this->profile(),
            institutionId: $this->institution->id,
            arrivalDate: CarbonImmutable::parse('2026-08-01'),
            translatorName: '金翻译',
            actorId: $this->user->id,
            confirmedCode: $code,
            automaticCode: true,
            ipAddress: '127.0.0.1',
        );

        $this->assertSame('TEST-JG-0001', $code);
        $this->assertDatabaseHas('customers', [
            'id' => $customerId,
            'code' => 'TEST-JG-0001',
            'owner_id' => $this->user->id,
        ]);
        $this->assertDatabaseHas('appointments', [
            'customer_id' => $customerId,
            'institution_id' => $this->institution->id,
            'status' => 'scheduled',
        ]);
        $this->assertDatabaseHas('customer_status_histories', [
            'customer_id' => $customerId,
            'reason' => '客户建档',
        ]);
        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'customer',
            'subject_id' => $customerId,
            'event' => 'created',
        ]);
    }

    public function test_stale_generated_code_requires_new_confirmation(): void
    {
        $manager = app(CustomerProfileManager::class);
        $staleCode = $manager->previewCode($this->agent->id);
        $manager->create(
            $this->profile(),
            $this->institution->id,
            CarbonImmutable::parse('2026-08-01'),
            null,
            $this->user->id,
            $staleCode,
            true,
            null,
        );

        $this->expectException(CustomerCodeChanged::class);
        $manager->create(
            $this->profile('第二位客户'),
            $this->institution->id,
            CarbonImmutable::parse('2026-08-02'),
            null,
            $this->user->id,
            $staleCode,
            true,
            null,
        );
    }

    public function test_duplicates_are_reported_but_not_merged(): void
    {
        $manager = app(CustomerProfileManager::class);
        $customerId = $this->createCustomer();

        $this->assertSame(
            [$customerId],
            $manager->duplicateCandidateIds('138-0000-5678', 'P-123456'),
        );
        $this->assertSame([], $manager->duplicateCandidateIds('13800005678', 'P123456', $customerId));
    }

    public function test_status_flow_rejects_skips_and_normal_user_rollbacks(): void
    {
        $customerId = $this->createCustomer();
        $manager = app(CustomerStatusManager::class);
        $quoted = CustomerStatus::query()->where('key', 'quoted')->firstOrFail();
        $booked = CustomerStatus::query()->where('key', 'booked')->firstOrFail();
        $interested = CustomerStatus::query()->where('key', 'interested')->firstOrFail();

        try {
            $manager->change($customerId, $booked->id, '尝试越级', $this->user, null);
            $this->fail('Expected a validation exception for a skipped transition.');
        } catch (ValidationException) {
            $this->assertDatabaseHas('customers', ['id' => $customerId, 'current_status_id' => $interested->id]);
        }

        $manager->change($customerId, $quoted->id, '客户已完成报价', $this->user, null);
        $this->assertDatabaseHas('customers', ['id' => $customerId, 'current_status_id' => $quoted->id]);

        $this->expectException(ValidationException::class);
        $manager->change($customerId, $interested->id, '普通用户尝试回退', $this->user, null);
    }

    public function test_status_flow_can_initialize_a_customer_without_a_current_status(): void
    {
        $customerId = $this->createCustomer();
        Customer::query()->whereKey($customerId)->update(['current_status_id' => null]);
        $target = CustomerStatus::query()->where('key', 'quoted')->firstOrFail();

        $this->actingAs($this->user)->get(route('customers.show', $customerId))->assertOk();
        Livewire::actingAs($this->user)
            ->test(CustomerDetail::class, ['customer' => $customerId])
            ->set('targetStatusId', (string) $target->id)
            ->set('statusReason', '补录历史客户状态')
            ->call('changeStatus')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('customers', [
            'id' => $customerId,
            'current_status_id' => $target->id,
        ]);
        $history = CustomerStatusHistory::query()
            ->where('customer_id', $customerId)
            ->where('to_status_id', $target->id)
            ->latest('id')
            ->firstOrFail();
        $this->assertNull($history->from_status_id);
        $this->assertSame('补录历史客户状态', $history->reason);
    }

    public function test_status_flow_marks_current_completed_and_available_nodes_without_edit_controls(): void
    {
        $customerId = $this->createCustomer();
        $directory = app(CustomerDirectory::class);
        $flow = $directory->statusFlow($customerId);

        $this->assertSame(5, count($flow['stages']));
        $this->assertSame(7, count($flow['statuses']));
        $this->assertSame(6, count($flow['transitions']));
        $this->assertSame(
            ['first_contact', 'booking', 'arrival', 'followup', 'operations'],
            collect($flow['stages'])->pluck('key')->all(),
        );
        $this->assertSame('current', collect($flow['statuses'])->firstWhere('key', 'interested')['state']);
        $this->assertSame('available', collect($flow['statuses'])->firstWhere('key', 'quoted')['state']);
        $this->assertSame('unavailable', collect($flow['statuses'])->firstWhere('key', 'booked')['state']);
        $this->assertContains(
            CustomerStatus::query()->where('key', 'quoted')->value('id'),
            $flow['available_next_status_ids'],
        );
        $this->assertFalse(collect($flow['transitions'])->firstWhere('to_status_id', CustomerStatus::query()->where('key', 'quoted')->value('id'))['visited']);

        CustomerStatus::query()->where('key', 'interested')->update(['is_active' => false]);
        $flow = $directory->statusFlow($customerId);
        $this->assertSame('current_inactive', collect($flow['statuses'])->firstWhere('key', 'interested')['state']);
        CustomerStatus::query()->where('key', 'interested')->update(['is_active' => true]);

        $quoted = CustomerStatus::query()->where('key', 'quoted')->firstOrFail();
        app(CustomerStatusManager::class)->change($customerId, $quoted->id, '报价完成', $this->user, null);
        $interested = CustomerStatus::query()->where('key', 'interested')->firstOrFail();
        $interestedToQuoted = CustomerStatusTransition::query()
            ->where('from_status_id', $interested->id)
            ->where('to_status_id', $quoted->id)
            ->firstOrFail();
        CustomerLifecycleStage::query()->where('key', 'booking')->update(['is_active' => false]);
        $flow = $directory->statusFlow($customerId);

        $this->assertSame($quoted->id, $flow['current_status_id']);
        $this->assertSame('completed', collect($flow['statuses'])->firstWhere('key', 'interested')['state']);
        $this->assertSame('current', collect($flow['statuses'])->firstWhere('key', 'quoted')['state']);
        $this->assertSame('available', collect($flow['statuses'])->firstWhere('key', 'booked')['state']);
        $this->assertSame('inactive', collect($flow['stages'])->firstWhere('key', 'booking')['state']);
        $this->assertTrue(collect($flow['transitions'])->firstWhere('to_status_id', $quoted->id)['visited']);

        $interestedToQuoted->update(['is_active' => false]);
        $flow = $directory->statusFlow($customerId);
        $historicalTransition = collect($flow['transitions'])->firstWhere('id', $interestedToQuoted->id);
        $this->assertFalse($historicalTransition['is_active']);
        $this->assertTrue($historicalTransition['visited']);

        CustomerStatus::query()->where('key', 'quoted')->update(['is_active' => false]);
        $flow = $directory->statusFlow($customerId);

        $this->assertSame('current_inactive', collect($flow['statuses'])->firstWhere('key', 'quoted')['state']);
        $this->assertSame('available', collect($flow['statuses'])->firstWhere('key', 'booked')['state']);

        $response = $this->actingAs($this->user)->get(route('customers.show', $customerId));
        $response->assertOk()
            ->assertSee(__('customers.detail.status_flow.heading'))
            ->assertSee('data-test="customer-status-flow"', false)
            ->assertSee('data-flow-layout', false)
            ->assertSee('data-status-key="quoted" data-status-state="current_inactive"', false)
            ->assertSee('data-status-key="booked" data-status-state="available"', false)
            ->assertSee('data-transition-visited="true"', false)
            ->assertSee('data-flow-history-transitions', false)
            ->assertDontSee('wire:click', false);
    }

    public function test_super_admin_can_rollback_and_configuration_is_protected(): void
    {
        $customerId = $this->createCustomer();
        $manager = app(CustomerStatusManager::class);
        $quoted = CustomerStatus::query()->where('key', 'quoted')->firstOrFail();
        $interested = CustomerStatus::query()->where('key', 'interested')->firstOrFail();
        $admin = User::factory()->superAdmin()->withTwoFactor()->create();

        $manager->change($customerId, $quoted->id, '报价完成', $this->user, null);
        $manager->change($customerId, $interested->id, '主管确认退回重新联系', $admin, null);
        $this->assertDatabaseHas('customer_status_histories', [
            'customer_id' => $customerId,
            'from_status_id' => $quoted->id,
            'to_status_id' => $interested->id,
            'changed_by' => $admin->id,
        ]);

        $this->actingAs($this->user)->get(route('customer-statuses.index'))->assertForbidden();
        $this->actingAs($admin)->get(route('customer-statuses.index'))
            ->assertOk()
            ->assertSee('生命周期状态配置')
            ->assertSee('返回配置中心')
            ->assertSee('href="'.route('configuration.index').'"', false);
    }

    public function test_customer_list_masks_sensitive_values_and_supports_exact_contact_search(): void
    {
        $customerId = $this->createCustomer();
        $directory = app(CustomerDirectory::class);
        $page = $directory->paginate(['search' => '13800005678'], 20);

        $this->assertSame($customerId, $page->items()[0]['id']);
        $response = $this->actingAs($this->user)->get(route('customers.index'));
        $response->assertOk()
            ->assertSee(__('customers.list.create'))
            ->assertSee('138****5678')
            ->assertDontSee('13800005678')
            ->assertDontSee('P123456');
        $this->assertSame(2, substr_count($response->getContent(), __('customers.list.all_statuses')));
        $this->assertSame(2, substr_count($response->getContent(), __('customers.list.all_agents')));
        $this->assertSame(2, substr_count($response->getContent(), __('customers.list.all_institutions')));
    }

    public function test_korean_locale_localizes_default_statuses_and_timeline_without_translating_custom_status_names(): void
    {
        $customerId = $this->createCustomer();
        app()->setLocale('ko_KR');
        $directory = app(CustomerDirectory::class);

        $this->assertSame('관심', $directory->profile($customerId)['current_status']);
        $this->assertSame('관심', collect($directory->options()['statuses'])->firstWhere('key', 'interested')['name']);

        $quoted = CustomerStatus::query()->where('key', 'quoted')->firstOrFail();
        app(CustomerStatusManager::class)->change($customerId, $quoted->id, '견적 전달', $this->user, null);
        $timeline = $directory->timeline($customerId, 'status');
        $changed = collect($timeline)->first(fn (array $event): bool => str_contains($event['content'], '견적 전달'));
        $this->assertSame('상태 변경', $changed['title']);
        $this->assertStringContainsString('관심 → 견적 완료', $changed['content']);

        CustomerStatus::query()->where('key', 'interested')->update(['name' => '自定义意向']);
        $this->assertSame('自定义意向', collect($directory->options()['statuses'])->firstWhere('key', 'interested')['name']);
    }

    public function test_korean_locale_localizes_customer_status_validation_errors(): void
    {
        app()->setLocale('ko_KR');
        $customerId = $this->createCustomer();
        $quoted = CustomerStatus::query()->where('key', 'quoted')->firstOrFail();

        try {
            app(CustomerStatusManager::class)->change($customerId, $quoted->id, '', $this->user, null);
            $this->fail('Expected a validation exception for an empty reason.');
        } catch (ValidationException $exception) {
            $this->assertSame(__('customers.validation.status_reason_required'), $exception->errors()['statusReason'][0]);
        }
    }

    public function test_compact_filters_can_be_cleared_together(): void
    {
        Livewire::actingAs($this->user)
            ->test(CustomerList::class)
            ->set('search', '测试')
            ->set('statusId', '1')
            ->set('agentId', (string) $this->agent->id)
            ->set('institutionId', (string) $this->institution->id)
            ->set('createdFrom', '2026-08-01')
            ->set('createdTo', '2026-08-31')
            ->set('perPage', 50)
            ->call('clearFilters')
            ->assertSet('search', '')
            ->assertSet('statusId', '')
            ->assertSet('agentId', '')
            ->assertSet('institutionId', '')
            ->assertSet('createdFrom', '')
            ->assertSet('createdTo', '')
            ->assertSet('perPage', 20);
    }

    public function test_customer_list_can_filter_by_creation_date_range(): void
    {
        $outside = $this->createCustomer('范围外客户');
        Customer::query()->findOrFail($outside)->update(['created_at' => CarbonImmutable::parse('2026-07-31 23:59:59', 'Asia/Shanghai')]);
        $inside = $this->createCustomer('范围内客户');
        Customer::query()->findOrFail($inside)->update(['created_at' => CarbonImmutable::parse('2026-08-01 00:00:00', 'Asia/Shanghai')]);

        $page = app(CustomerDirectory::class)->paginate([
            'created_from' => '2026-08-01',
            'created_to' => '2026-08-01',
        ], 20);

        $this->assertSame([$inside], $page->getCollection()->pluck('id')->all());
        Livewire::actingAs($this->user)
            ->test(CustomerList::class)
            ->set('createdFrom', '2026-08-01')
            ->set('createdTo', '2026-08-01')
            ->assertSee('范围内客户')
            ->assertDontSee('范围外客户');

        Livewire::actingAs($this->user)
            ->test(CustomerList::class)
            ->set('createdFrom', '2026-08-02')
            ->set('createdTo', '2026-08-01')
            ->assertSee(__('customers.list.validation.created_range'))
            ->assertSee(__('customers.list.empty'));
    }

    public function test_sensitive_edit_requires_confirmation_and_is_audited(): void
    {
        $customerId = $this->createCustomer();
        $manager = app(CustomerProfileManager::class);
        $changed = new CustomerProfileData(
            name: '修改后的客户',
            gender: '女',
            birthDate: CarbonImmutable::parse('1990-01-01'),
            sourceAgentId: $this->agent->id,
            contactValue: '13900001234',
            identityDocument: 'P654321',
            projectIntention: '皮肤管理',
            notes: '已确认修改',
        );

        try {
            $manager->update($customerId, $changed, $this->user->id, false, '127.0.0.1');
            $this->fail('Expected confirmation validation.');
        } catch (ValidationException) {
            $this->assertSame('测试客户', Customer::query()->findOrFail($customerId)->name);
        }

        $manager->update($customerId, $changed, $this->user->id, true, '127.0.0.1');
        $this->assertSame('修改后的客户', Customer::query()->findOrFail($customerId)->name);
        $this->assertDatabaseHas('activity_log', [
            'subject_id' => $customerId,
            'event' => 'updated',
        ]);
    }

    public function test_followup_is_written_by_owner_module_and_appears_on_timeline(): void
    {
        $customerId = $this->createCustomer();
        app(CustomerFollowupManager::class)->record(
            customerId: $customerId,
            type: '术后回访',
            followedUpOn: CarbonImmutable::parse('2026-08-03'),
            content: '恢复情况良好',
            actorId: $this->user->id,
            ipAddress: '127.0.0.1',
        );

        $this->assertDatabaseHas('followup_records', [
            'customer_id' => $customerId,
            'owner_id' => $this->user->id,
            'content' => '恢复情况良好',
        ]);
        $timeline = app(CustomerDirectory::class)->timeline($customerId, 'followup');
        $this->assertCount(1, $timeline);
        $this->assertSame('恢复情况良好', $timeline[0]['content']);
    }

    public function test_audit_failure_rolls_back_customer_and_appointment(): void
    {
        $audit = Mockery::mock(AuditRecorder::class);
        $audit->shouldReceive('record')->once()->andThrow(new RuntimeException('audit unavailable'));
        $this->app->instance(AuditRecorder::class, $audit);
        $manager = app(CustomerProfileManager::class);

        try {
            $manager->create(
                $this->profile(),
                $this->institution->id,
                CarbonImmutable::parse('2026-08-01'),
                null,
                $this->user->id,
                $manager->previewCode($this->agent->id),
                true,
                null,
            );
            $this->fail('Expected audit failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('audit unavailable', $exception->getMessage());
        }

        $this->assertDatabaseCount('customers', 0);
        $this->assertDatabaseCount('appointments', 0);
    }

    public function test_customer_pages_require_authentication_and_render_for_internal_users(): void
    {
        $customerId = $this->createCustomer();

        $this->get(route('customers.index'))->assertRedirect(route('login'));
        $this->actingAs($this->user)->get(route('customers.create'))
            ->assertOk()
            ->assertSee(__('customers.form.create_heading'))
            ->assertSee(__('customers.form.back_to_list'))
            ->assertSee('href="'.route('customers.index').'"', false);
        $this->actingAs($this->user)->get(route('customers.show', $customerId))
            ->assertOk()
            ->assertSee(__('customers.title.detail'))
            ->assertSee('<dd class="mt-1 font-semibold">测试客户</dd>', false)
            ->assertSee(__('customers.detail.profile.code'))
            ->assertSee(__('customers.detail.profile.created_at'))
            ->assertSee(__('customers.detail.status_flow.heading'))
            ->assertSee($this->user->name)
            ->assertSee(__('customers.detail.back'))
            ->assertSee('href="'.route('customers.index').'"', false);
        $this->actingAs($this->user)->get(route('customers.edit', $customerId))
            ->assertOk()
            ->assertSee(__('customers.form.edit_heading'))
            ->assertSee(__('customers.form.back_to_detail'))
            ->assertSee('href="'.route('customers.show', $customerId).'"', false);

        $koUser = User::factory()->create(['preferred_locale' => 'ko_KR']);
        $this->actingAs($koUser)->get(route('customers.index'))
            ->assertOk()
            ->assertSee('고객 관리');

        $koAdmin = User::factory()->superAdmin()->withTwoFactor()->create(['preferred_locale' => 'ko_KR']);
        $this->actingAs($koAdmin)->get(route('customer-statuses.index'))
            ->assertOk()
            ->assertSee('라이프사이클 상태 설정')
            ->assertSee('설정 센터로 돌아가기')
            ->assertDontSee('生命周期状态配置');
    }

    private function createCustomer(string $name = '测试客户'): int
    {
        $manager = app(CustomerProfileManager::class);

        return $manager->create(
            $this->profile($name),
            $this->institution->id,
            CarbonImmutable::parse('2026-08-01'),
            null,
            $this->user->id,
            $manager->previewCode($this->agent->id),
            true,
            null,
        );
    }

    private function profile(string $name = '测试客户'): CustomerProfileData
    {
        return new CustomerProfileData(
            name: $name,
            gender: '女',
            birthDate: CarbonImmutable::parse('1990-01-01'),
            sourceAgentId: $this->agent->id,
            contactValue: '13800005678',
            identityDocument: 'P123456',
            projectIntention: '皮肤管理',
            notes: '测试建档',
        );
    }
}
