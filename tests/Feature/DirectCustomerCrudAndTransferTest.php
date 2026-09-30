<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Agent\Infrastructure\Models\Agent;
use App\Modules\Agent\Infrastructure\Models\AgentTypeCode;
use App\Modules\Auth\Domain\UserRole;
use App\Modules\Config\Infrastructure\Models\Institution;
use App\Modules\Customer\Application\Data\CustomerProfileData;
use App\Modules\Customer\Application\Services\CustomerDirectory;
use App\Modules\Customer\Application\Services\CustomerProfileManager;
use App\Modules\Customer\Application\Services\CustomerTransferManager;
use App\Modules\Customer\Infrastructure\Models\Customer;
use App\Modules\Customer\Infrastructure\Models\CustomerOwnerHistory;
use App\Modules\Customer\Infrastructure\Models\CustomerStatus;
use App\Modules\Customer\Presentation\Livewire\CustomerDetail;
use App\Modules\Customer\Presentation\Livewire\CustomerForm;
use App\Modules\Customer\Presentation\Livewire\CustomerList;
use App\Modules\Customer\Presentation\Livewire\DirectCustomerForm;
use App\Modules\Customer\Presentation\Livewire\DirectCustomerList;
use Carbon\CarbonImmutable;
use Database\Seeders\PhaseTwoReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class DirectCustomerCrudAndTransferTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $manager;

    private User $otherManager;

    private Institution $institution;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PhaseTwoReferenceDataSeeder::class);
        $this->admin = User::factory()->superAdmin()->withTwoFactor()->create(['name' => '直客超管']);
        $this->manager = User::factory()->create([
            'name' => '直客负责人 A',
            'role' => UserRole::DirectCustomerManager,
        ]);
        $this->otherManager = User::factory()->create([
            'name' => '直客负责人 B',
            'role' => UserRole::DirectCustomerManager,
        ]);
        $this->institution = Institution::query()->firstOrFail();
    }

    public function test_direct_manager_can_create_direct_customer_with_stable_dc_code_and_owner_default(): void
    {
        $manager = app(CustomerProfileManager::class);
        $channelId = (int) DB::table('direct_customer_channels')->where('code', 'wechat')->value('id');
        $customerId = $manager->create(
            profile: $this->profile('直客 A', $channelId),
            institutionId: $this->institution->id,
            arrivalAt: CarbonImmutable::parse('2026-09-20 10:00'),
            translatorName: null,
            actorId: $this->manager->id,
            ownerId: $this->otherManager->id,
            confirmedCode: $manager->previewDirectCode(),
            automaticCode: true,
            ipAddress: null,
        );

        $customer = Customer::query()->findOrFail($customerId);
        $this->assertSame('DC-000001', $customer->code);
        $this->assertSame('direct', $customer->source_type);
        $this->assertNull($customer->source_agent_id);
        $this->assertSame($channelId, $customer->direct_channel_id);
        $this->assertSame($this->manager->id, $customer->owner_id);
        $this->assertDatabaseHas('appointments', ['customer_id' => $customerId, 'owner_id' => $this->manager->id]);
    }

    public function test_direct_customer_list_is_owner_scoped_and_form_routes_are_available(): void
    {
        $customerId = $this->createCustomer($this->manager);
        $otherCustomerId = $this->createCustomer($this->otherManager, '直客 B');

        $this->actingAs($this->manager)->get(route('direct-customers.index'))
            ->assertOk()
            ->assertSee('直客 A')
            ->assertDontSee('直客 B')
            ->assertSee('href="'.route('direct-customers.show', $customerId).'"', false)
            ->assertSee(__('navigation.direct_customers'))
            ->assertSee(__('navigation.customer_management'))
            ->assertDontSee('data-test="customer-subnav-agent"')
            ->assertDontSee(route('customers.create'));
        $this->actingAs($this->manager)->get(route('direct-customers.edit', $customerId))
            ->assertOk()
            ->assertSee(__('customers.direct.form.edit_heading'));
        $this->actingAs($this->manager)->get(route('direct-customers.edit', $otherCustomerId))
            ->assertNotFound();
        $this->actingAs($this->admin)->get(route('direct-customers.create'))
            ->assertOk()
            ->assertSee(__('customers.direct.form.create_heading'))
            ->assertSee(__('navigation.customer_management'))
            ->assertSee(__('navigation.agent_customers'))
            ->assertSee(__('navigation.direct_customers'));

        Livewire::actingAs($this->manager)
            ->test(DirectCustomerList::class)
            ->assertSee('直客 A')
            ->assertDontSee('直客 B')
            ->assertDontSee('直客负责人 B')
            ->assertDontSee('不可见代理商选项');
        $this->assertArrayNotHasKey('users', Livewire::actingAs($this->manager)->test(DirectCustomerList::class)->get('options'));
        $this->assertArrayHasKey('users', Livewire::actingAs($this->admin)->test(DirectCustomerList::class)->get('options'));
    }

    public function test_agent_customer_list_excludes_direct_customers_and_detail_returns_to_matching_list(): void
    {
        $directCustomerId = $this->createCustomer($this->manager, '只在直客列表');
        $agent = Agent::query()->create([
            'agent_type_code_id' => AgentTypeCode::query()->firstOrFail()->id,
            'code' => 'AGENT-LIST-41',
            'name' => '列表代理商',
            'cooperation_status' => 'active',
        ]);
        $agentCustomer = Customer::query()->create([
            'code' => 'AGENT-CUSTOMER-41',
            'name' => '只在代理商客户列表',
            'source_type' => 'agent',
            'source_agent_id' => $agent->id,
            'owner_id' => $this->admin->id,
        ]);

        $this->actingAs($this->admin)->get(route('customers.index'))
            ->assertOk()
            ->assertSee('只在代理商客户列表');
        Livewire::actingAs($this->admin)
            ->test(CustomerList::class)
            ->assertSee('只在代理商客户列表')
            ->assertDontSee('只在直客列表');
        $this->get(route('customers.show', $directCustomerId))
            ->assertRedirect(route('direct-customers.show', $directCustomerId));
        $this->get(route('direct-customers.show', $directCustomerId))
            ->assertOk()
            ->assertSee('href="'.route('direct-customers.index').'"', false)
            ->assertSee(__('customers.direct.detail.back'));
        $this->get(route('customers.show', $agentCustomer))
            ->assertOk()
            ->assertSee('href="'.route('customers.index').'"', false)
            ->assertSee(__('customers.detail.back'));
        $this->assertSame('代理商客户管理', __('customers.title.list'));
        $this->get(route('direct-customers.show', $agentCustomer))->assertNotFound();
        $this->get(route('customers.edit', $directCustomerId))->assertNotFound();
    }

    public function test_direct_customer_details_keep_the_direct_submenu_active_and_use_direct_options(): void
    {
        $customerId = $this->createCustomer($this->manager);
        $otherCustomerId = $this->createCustomer($this->otherManager, '其他负责人直客');

        foreach ([$this->admin, $this->manager] as $user) {
            $response = $this->actingAs($user)->get(route('direct-customers.show', $customerId))
                ->assertOk()
                ->assertSee('href="'.route('direct-customers.index').'"', false)
                ->assertSee(__('customers.direct.detail.back'));
            $this->assertMatchesRegularExpression('/<a[^>]*class="crm-subnav-item is-active"[^>]*data-test="customer-subnav-direct"/', $response->getContent());
            $this->assertDoesNotMatchRegularExpression('/<a[^>]*class="crm-subnav-item is-active"[^>]*data-test="customer-subnav-agent"/', $response->getContent());
            $this->assertArrayNotHasKey('agents', Livewire::actingAs($user)->test(CustomerDetail::class, ['customer' => $customerId])->get('options'));
            $this->get(route('direct-customers.edit', $customerId))
                ->assertOk()->assertSee('href="'.route('direct-customers.show', $customerId).'"', false);
        }
        $this->actingAs($this->manager)->get(route('customers.show', $customerId))
            ->assertRedirect(route('direct-customers.show', $customerId));
        $this->get(route('direct-customers.show', $otherCustomerId))->assertNotFound();
        Livewire::actingAs($this->manager)->test(DirectCustomerForm::class, ['customer' => $customerId])
            ->set('notes', '更新直客备注')->call('save')->assertHasNoErrors()
            ->assertRedirect(route('direct-customers.show', $customerId));
    }

    public function test_direct_managers_cannot_open_or_mount_agent_customer_workspaces(): void
    {
        $customerId = $this->createCustomer($this->manager);
        $this->actingAs($this->manager);
        foreach (['customers.index', 'customers.create', 'customers.edit'] as $name) {
            $this->get(route($name, $name === 'customers.edit' ? $customerId : []))->assertForbidden();
        }
        Livewire::actingAs($this->manager)->test(CustomerList::class)->assertForbidden();
        Livewire::actingAs($this->manager)->test(CustomerForm::class)->assertForbidden();
        Livewire::actingAs($this->manager)->test(CustomerForm::class, ['customer' => $customerId])->assertForbidden();

        $this->expectException(HttpException::class);
        app(CustomerDirectory::class)->options();
    }

    public function test_direct_customer_status_filter_is_available_to_managers_and_owner_filter_only_to_admins(): void
    {
        $includedId = $this->createCustomer($this->manager, '已预约直客');
        $excludedId = $this->createCustomer($this->manager, '已到院直客');
        $booked = CustomerStatus::query()->where('key', 'booked')->firstOrFail();
        $arrived = CustomerStatus::query()->where('key', 'arrived')->firstOrFail();
        Customer::query()->whereKey($includedId)->update(['current_status_id' => $booked->id]);
        Customer::query()->whereKey($excludedId)->update(['current_status_id' => $arrived->id]);

        Livewire::actingAs($this->manager)->test(DirectCustomerList::class)
            ->assertSee(__('customers.direct.list.all_statuses'))
            ->assertDontSee(__('customers.direct.list.all_owners'))
            ->set('statusId', (string) $booked->id)
            ->assertSee('已预约直客')->assertDontSee('已到院直客');
        Livewire::actingAs($this->admin)->test(DirectCustomerList::class)
            ->assertSee(__('customers.direct.list.all_statuses'))
            ->assertSee(__('customers.direct.list.all_owners'))
            ->assertSee($this->otherManager->name);
    }

    public function test_non_direct_roles_cannot_open_direct_customer_pages(): void
    {
        $customerService = User::factory()->create(['role' => UserRole::CustomerService]);

        $this->actingAs($customerService)->get(route('direct-customers.index'))->assertForbidden();
        $this->actingAs($customerService)->get(route('direct-customers.create'))->assertForbidden();
        $customerId = $this->createCustomer($this->manager);
        $this->get(route('direct-customers.show', $customerId))->assertNotFound();
        $this->get(route('customers.show', $customerId))->assertNotFound();
    }

    public function test_direct_manager_transfer_requires_super_admin_approval_and_keeps_history(): void
    {
        $customerId = $this->createCustomer($this->manager);
        $transfers = app(CustomerTransferManager::class);

        $requestId = $transfers->request($customerId, $this->otherManager->id, '交接负责区域', $this->manager, null);
        $this->assertDatabaseHas('customer_transfer_requests', [
            'id' => $requestId,
            'status' => 'pending',
            'requested_by' => $this->manager->id,
        ]);

        $this->expectException(HttpException::class);
        try {
            $transfers->approve($requestId, '非超管不可审批', $this->otherManager, null);
        } finally {
            $transfers->approve($requestId, '超管审批', $this->admin, null);
        }
    }

    public function test_super_admin_can_adjust_direct_customer_owner_without_business_group(): void
    {
        $customerId = $this->createCustomer($this->manager);
        app(CustomerTransferManager::class)->direct($customerId, $this->otherManager->id, '管理员调整', $this->admin, null);

        $this->assertDatabaseHas('customers', ['id' => $customerId, 'owner_id' => $this->otherManager->id]);
        $this->assertDatabaseHas('customer_owner_histories', [
            'customer_id' => $customerId,
            'from_owner_id' => $this->manager->id,
            'to_owner_id' => $this->otherManager->id,
            'source' => 'admin_direct',
        ]);
        $this->assertSame(1, CustomerOwnerHistory::query()->where('customer_id', $customerId)->where('source', 'admin_direct')->count());
    }

    private function createCustomer(User $owner, string $name = '直客 A'): int
    {
        $manager = app(CustomerProfileManager::class);
        $channelId = (int) DB::table('direct_customer_channels')->where('code', 'wechat')->value('id');

        return $manager->create(
            profile: $this->profile($name, $channelId),
            institutionId: $this->institution->id,
            arrivalAt: CarbonImmutable::parse('2026-09-20 10:00'),
            translatorName: null,
            actorId: $this->admin->id,
            ownerId: $owner->id,
            confirmedCode: $manager->previewDirectCode(),
            automaticCode: true,
            ipAddress: null,
        );
    }

    private function profile(string $name, int $channelId): CustomerProfileData
    {
        return new CustomerProfileData(
            name: $name,
            gender: null,
            birthDate: CarbonImmutable::parse('1990-01-01'),
            sourceAgentId: null,
            contactValue: '1380000'.random_int(1000, 9999),
            identityDocument: 'P'.random_int(100000, 999999),
            projectIntention: '皮肤管理',
            notes: 'PR2 直客测试',
            sourceType: 'direct',
            directChannelId: $channelId,
        );
    }
}
