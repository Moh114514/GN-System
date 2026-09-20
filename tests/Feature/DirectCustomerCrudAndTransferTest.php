<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Auth\Domain\UserRole;
use App\Modules\Config\Infrastructure\Models\Institution;
use App\Modules\Customer\Application\Data\CustomerProfileData;
use App\Modules\Customer\Application\Services\CustomerProfileManager;
use App\Modules\Customer\Application\Services\CustomerTransferManager;
use App\Modules\Customer\Infrastructure\Models\Customer;
use App\Modules\Customer\Infrastructure\Models\CustomerOwnerHistory;
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
            ->assertDontSee('直客 B');
        $this->actingAs($this->manager)->get(route('direct-customers.edit', $customerId))
            ->assertOk()
            ->assertSee(__('customers.direct.form.edit_heading'));
        $this->actingAs($this->manager)->get(route('direct-customers.edit', $otherCustomerId))
            ->assertNotFound();
        $this->actingAs($this->admin)->get(route('direct-customers.create'))
            ->assertOk()
            ->assertSee(__('customers.direct.form.create_heading'));

        Livewire::actingAs($this->manager)
            ->test(DirectCustomerList::class)
            ->assertSee('直客 A')
            ->assertDontSee('直客 B');
    }

    public function test_non_direct_roles_cannot_open_direct_customer_pages(): void
    {
        $customerService = User::factory()->create(['role' => UserRole::CustomerService]);

        $this->actingAs($customerService)->get(route('direct-customers.index'))->assertForbidden();
        $this->actingAs($customerService)->get(route('direct-customers.create'))->assertForbidden();
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
