<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Agent\Infrastructure\Models\Agent;
use App\Modules\Agent\Infrastructure\Models\AgentTypeCode;
use App\Modules\Auth\Application\Contracts\AccessContextResolver;
use App\Modules\Auth\Application\Contracts\UserManagementGateway;
use App\Modules\Auth\Application\Data\AccessContext;
use App\Modules\Auth\Domain\UserRole;
use App\Modules\Config\Infrastructure\Models\Institution;
use App\Modules\Customer\Application\Contracts\CustomerOrderReferenceReader;
use App\Modules\Customer\Application\Data\CustomerProfileData;
use App\Modules\Customer\Application\Services\CustomerProfileManager;
use App\Modules\Customer\Application\Services\CustomerTransferManager;
use App\Modules\Customer\Infrastructure\Models\Customer;
use App\Modules\Customer\Infrastructure\Models\CustomerStatus;
use App\Modules\Order\Application\Contracts\DailyOrderGateway;
use App\Modules\Order\Application\Contracts\OrderLifecycleGateway;
use App\Modules\Order\Application\Data\CompletedOrderItemData;
use App\Modules\Order\Application\Data\CompletedOrderRegistrationData;
use App\Modules\Order\Application\Data\DailyOrderData;
use App\Modules\Order\Application\Services\CompletedOrderRegistrar;
use App\Modules\Order\Application\Services\OrderManagementWorkspace;
use App\Modules\Order\Infrastructure\Models\Order;
use App\Modules\Report\Application\Services\InstitutionMonthlySalesService;
use App\Modules\Report\Application\Services\ReportSearch;
use App\Modules\Settlement\Application\Contracts\DirectCommissionConfigurationGateway;
use Carbon\CarbonImmutable;
use Database\Seeders\PhaseTwoReferenceDataSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DirectCustomerOrderCommissionTest extends TestCase
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
        $this->admin = User::factory()->superAdmin()->withTwoFactor()->create(['name' => '直客提成超管']);
        $this->manager = User::factory()->create(['name' => '直客负责人 A', 'role' => UserRole::DirectCustomerManager]);
        $this->otherManager = User::factory()->create(['name' => '直客负责人 B', 'role' => UserRole::DirectCustomerManager]);
        $this->institution = Institution::query()->firstOrFail();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_completed_direct_order_uses_customer_owner_and_preserves_owner_and_rate_snapshots(): void
    {
        $this->actingAs($this->admin);
        $this->saveRate(300, '2026-09-20');
        $customerId = $this->createArrivedCustomer($this->manager, '直客提成客户 A', '2026-09-20');

        $firstOrderId = $this->register($customerId, 100000, '2026-09-20');
        $this->assertDatabaseHas('orders', [
            'id' => $firstOrderId,
            'source_type' => 'direct',
            'agent_id' => null,
            'owner_id' => $this->manager->id,
        ]);
        $firstCommission = DB::table('direct_order_commissions')->where('order_id', $firstOrderId)->first();
        $this->assertNotNull($firstCommission);
        $this->assertSame($this->manager->id, (int) $firstCommission->owner_id);
        $this->assertSame(300, (int) $firstCommission->rate_bps);
        $this->assertSame(3000, (int) $firstCommission->commission_amount_krw);

        app(CustomerTransferManager::class)->direct($customerId, $this->otherManager->id, '负责人交接', $this->admin, null);
        $this->resetAsArrived($customerId, '2026-09-21');
        $this->saveRate(500, '2026-09-21');
        $secondOrderId = $this->register($customerId, 200000, '2026-09-21');

        $this->assertDatabaseHas('direct_order_commissions', [
            'order_id' => $firstOrderId,
            'owner_id' => $this->manager->id,
            'rate_bps' => 300,
            'commission_amount_krw' => 3000,
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('direct_order_commissions', [
            'order_id' => $secondOrderId,
            'owner_id' => $this->otherManager->id,
            'rate_bps' => 500,
            'commission_amount_krw' => 10000,
            'status' => 'active',
        ]);
    }

    public function test_direct_commission_is_voided_when_completed_order_is_rolled_back(): void
    {
        $this->actingAs($this->admin);
        $this->saveRate(300, '2026-09-20');
        $customerId = $this->createArrivedCustomer($this->manager, '直客回退客户', '2026-09-20');
        $orderId = $this->register($customerId, 100000, '2026-09-20');

        app(OrderLifecycleGateway::class)
            ->rollbackCompleted($orderId, $this->admin->id, '金额复核', null);

        $this->assertDatabaseHas('direct_order_commissions', [
            'order_id' => $orderId,
            'status' => 'voided',
            'void_reason' => '金额复核',
        ]);
        $this->assertDatabaseHas('orders', ['id' => $orderId, 'status' => 'pending']);
    }

    public function test_actual_completion_time_selects_the_direct_commission_rate(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-23 14:30:00', 'Asia/Shanghai'));
        $this->actingAs($this->admin);
        $this->saveRate(300, '2026-09-20');
        $this->saveRate(500, '2026-09-22');
        $customerId = $this->createArrivedCustomer($this->manager, '按完成时点费率客户', '2026-09-20');

        $orderId = $this->register($customerId, 100000, '2026-09-20');

        $order = Order::query()->findOrFail($orderId);
        $this->assertSame('2026-09-20', $order->occurred_on->toDateString());
        $this->assertSame('2026-09-23 14:30:00', $order->completed_at?->format('Y-m-d H:i:s'));
        $this->assertSame('datetime', $order->completion_precision);
        $this->assertDatabaseHas('direct_order_commissions', [
            'order_id' => $orderId,
            'rate_bps' => 500,
            'commission_amount_krw' => 5000,
        ]);
    }

    public function test_daily_order_gateway_uses_business_clock_for_direct_completion_time(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-23 14:30:00', 'Asia/Shanghai'));
        $this->actingAs($this->admin);
        $this->saveRate(300, '2026-09-20');
        $this->saveRate(500, '2026-09-22');
        $customerId = $this->createArrivedCustomer($this->manager, '日常网关完成时间客户', '2026-09-20');

        $createCompletedId = app(DailyOrderGateway::class)->create(new DailyOrderData(
            customerId: $customerId,
            institutionId: $this->institution->id,
            agentId: null,
            projectName: '日常登记项目',
            amountKrw: 100000,
            status: 'completed',
            completedOn: CarbonImmutable::parse('2026-09-20', 'Asia/Shanghai'),
            translatorName: null,
            notes: null,
            ownerId: $this->manager->id,
            ipAddress: null,
        ));

        $createdOrder = Order::query()->findOrFail($createCompletedId);
        $this->assertSame('2026-09-20', $createdOrder->occurred_on?->toDateString());
        $this->assertSame('2026-09-23 14:30:00', $createdOrder->completed_at?->format('Y-m-d H:i:s'));
        $this->assertDatabaseHas('direct_order_commissions', [
            'order_id' => $createCompletedId,
            'rate_bps' => 500,
        ]);

        $pendingId = app(DailyOrderGateway::class)->create(new DailyOrderData(
            customerId: $customerId,
            institutionId: $this->institution->id,
            agentId: null,
            projectName: '日常补录项目',
            amountKrw: 200000,
            status: 'pending',
            completedOn: null,
            translatorName: null,
            notes: null,
            ownerId: $this->manager->id,
            ipAddress: null,
        ));
        app(DailyOrderGateway::class)->complete(
            $pendingId,
            CarbonImmutable::parse('2026-09-20', 'Asia/Shanghai'),
            $this->admin->id,
            null,
        );

        $completedOrder = Order::query()->findOrFail($pendingId);
        $this->assertSame('2026-09-20', $completedOrder->occurred_on?->toDateString());
        $this->assertSame('2026-09-23 14:30:00', $completedOrder->completed_at?->format('Y-m-d H:i:s'));
        $this->assertDatabaseHas('direct_order_commissions', [
            'order_id' => $pendingId,
            'rate_bps' => 500,
        ]);
    }

    public function test_backdated_rate_closes_at_the_next_existing_rate(): void
    {
        $this->actingAs($this->admin);
        $this->saveRate(500, '2026-10-01');
        $this->saveRate(300, '2026-09-01');

        $this->assertDatabaseHas('direct_commission_rates', [
            'effective_from' => '2026-09-01',
            'effective_until' => '2026-09-30',
        ]);
        $this->assertDatabaseHas('direct_commission_rates', [
            'effective_from' => '2026-10-01',
            'effective_until' => null,
        ]);
    }

    public function test_direct_order_visibility_follows_current_customer_owner_after_transfer(): void
    {
        $this->actingAs($this->admin);
        $this->saveRate(300, '2026-09-20');
        $customerId = $this->createArrivedCustomer($this->manager, '转移后历史订单客户', '2026-09-20');
        $orderId = $this->register($customerId, 100000, '2026-09-20');
        app(CustomerTransferManager::class)->direct($customerId, $this->otherManager->id, '负责人交接', $this->admin, null);

        $this->actingAs($this->manager)->get(route('orders.show', $orderId))->assertNotFound();
        $this->assertContains($customerId, app(CustomerOrderReferenceReader::class)->directCustomerIdsForOwner($this->otherManager->id));
        $this->actingAs($this->otherManager);
        $this->assertSame(1, app(OrderManagementWorkspace::class)->paginate([], 20)->total());
        $detail = app(OrderManagementWorkspace::class)->detail($orderId);
        $this->assertSame($customerId, $detail['customer']['id']);
        $this->assertFalse($detail['financial']['commission_visible']);
        $this->assertNull($detail['financial']['commission']);
        $this->get(route('orders.show', $orderId))
            ->assertOk()
            ->assertDontSee(__('orders.detail.direct_commission_description'))
            ->assertDontSee('3,000');

        $this->actingAs($this->admin);
        $adminDetail = app(OrderManagementWorkspace::class)->detail($orderId);
        $this->assertTrue($adminDetail['financial']['commission_visible']);
        $this->assertSame(3000, $adminDetail['financial']['commission']['amount_krw']);
        $this->get(route('orders.show', $orderId))
            ->assertOk()
            ->assertSee(__('orders.detail.direct_commission_title'))
            ->assertSee('3,000');
        $this->actingAs($this->otherManager);
        $summary = app(InstitutionMonthlySalesService::class)->summary('2026-09');
        $this->assertSame(1, $summary->totalOrders);
        $this->assertSame(100000, $summary->totalAmountKrw);

        $this->assertDatabaseHas('direct_order_commissions', [
            'order_id' => $orderId,
            'owner_id' => $this->manager->id,
            'status' => 'active',
        ]);
    }

    public function test_role_change_is_rejected_while_user_still_owns_direct_customers(): void
    {
        $this->actingAs($this->admin);
        $this->createArrivedCustomer($this->manager, '角色保护客户', '2026-09-20');

        try {
            app(UserManagementGateway::class)->setRole(
                $this->manager->id,
                UserRole::CustomerService->value,
                $this->admin->id,
                null,
            );
            $this->fail('Role change should be rejected while direct customers remain assigned.');
        } catch (DomainException $exception) {
            $this->assertSame(__('auth.errors.user_role_has_direct_customers', ['count' => 1]), $exception->getMessage());
        }
        $this->assertDatabaseHas('users', ['id' => $this->manager->id, 'role' => UserRole::DirectCustomerManager->value]);
    }

    public function test_role_scoped_agent_filter_options_do_not_leak_metadata(): void
    {
        $firstAgent = Agent::query()->create([
            'agent_type_code_id' => AgentTypeCode::query()->firstOrFail()->id,
            'code' => 'FILTER-AGENT',
            'name' => '不可见代理商选项',
            'cooperation_status' => 'active',
        ]);
        Agent::query()->create([
            'agent_type_code_id' => $firstAgent->agent_type_code_id,
            'code' => 'FILTER-OTHER',
            'name' => '另一代理商',
            'cooperation_status' => 'active',
        ]);
        $this->actingAs($this->manager);

        $this->assertSame([], app(OrderManagementWorkspace::class)->options()['agents']);
        $this->assertSame([], app(ReportSearch::class)->options()['agents']);
        $this->get(route('orders.index'))->assertOk()->assertDontSee('不可见代理商选项');
        $this->get(route('reports.search'))->assertOk()->assertDontSee('不可见代理商选项');

        Customer::query()->create([
            'code' => 'FILTER-CUSTOMER',
            'name' => '客服负责客户',
            'source_type' => 'agent',
            'source_agent_id' => $firstAgent->id,
            'owner_id' => $this->manager->id,
        ]);
        $customerServiceContext = new AccessContext(
            userId: $this->manager->id,
            role: UserRole::CustomerService->value,
            businessGroupIds: [1],
            agentIds: [$firstAgent->id, (int) Agent::query()->where('code', 'FILTER-OTHER')->value('id')],
            groupUserIds: [$this->manager->id],
        );
        app(AccessContextResolver::class)->using($customerServiceContext, function (): void {
            $this->assertSame(['FILTER-AGENT'], array_column(app(OrderManagementWorkspace::class)->options()['agents'], 'code'));
            $this->assertSame(
                [['id' => (int) Agent::query()->where('code', 'FILTER-AGENT')->value('id'), 'name' => '不可见代理商选项']],
                app(ReportSearch::class)->options()['agents'],
            );
        });
    }

    private function saveRate(int $rateBps, string $effectiveFrom): void
    {
        app(DirectCommissionConfigurationGateway::class)->saveRate(
            rateBps: $rateBps,
            effectiveFrom: CarbonImmutable::parse($effectiveFrom),
            effectiveUntil: null,
            reason: 'PR3 验收规则',
            actorId: $this->admin->id,
            ipAddress: null,
        );
    }

    private function register(int $customerId, int $amountKrw, string $occurredOn): int
    {
        return app(CompletedOrderRegistrar::class)->register(new CompletedOrderRegistrationData(
            customerId: $customerId,
            institutionId: $this->institution->id,
            agentId: null,
            items: [new CompletedOrderItemData('皮肤管理', $amountKrw)],
            occurredOn: CarbonImmutable::parse($occurredOn),
            actorId: $this->admin->id,
            ipAddress: null,
            ownerId: null,
            source: 'pr3_test',
        ));
    }

    private function createArrivedCustomer(User $owner, string $name, string $arrivedOn): int
    {
        $manager = app(CustomerProfileManager::class);
        $channelId = (int) DB::table('direct_customer_channels')->where('code', 'wechat')->value('id');
        $customerId = $manager->create(
            profile: new CustomerProfileData(
                name: $name,
                gender: null,
                birthDate: CarbonImmutable::parse('1990-01-01'),
                sourceAgentId: null,
                contactValue: '1380000'.random_int(1000, 9999),
                identityDocument: 'P'.random_int(100000, 999999),
                projectIntention: '皮肤管理',
                notes: 'PR3 直客提成测试',
                sourceType: 'direct',
                directChannelId: $channelId,
            ),
            institutionId: $this->institution->id,
            arrivalAt: CarbonImmutable::parse($arrivedOn.' 10:00'),
            translatorName: null,
            actorId: $this->admin->id,
            ownerId: $owner->id,
            confirmedCode: $manager->previewDirectCode(),
            automaticCode: true,
            ipAddress: null,
        );
        $this->resetAsArrived($customerId, $arrivedOn);

        return $customerId;
    }

    private function resetAsArrived(int $customerId, string $arrivedOn): void
    {
        $arrived = CustomerStatus::query()->where('key', 'arrived')->firstOrFail();
        Customer::query()->whereKey($customerId)->update([
            'current_status_id' => $arrived->id,
            'arrived_at' => CarbonImmutable::parse($arrivedOn.' 10:00'),
            'treatment_completed_at' => null,
        ]);
    }
}
