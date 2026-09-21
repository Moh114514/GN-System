<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Auth\Domain\UserRole;
use App\Modules\Config\Infrastructure\Models\Institution;
use App\Modules\Customer\Application\Data\CustomerProfileData;
use App\Modules\Customer\Application\Services\CustomerProfileManager;
use App\Modules\Customer\Application\Services\CustomerTransferManager;
use App\Modules\Customer\Infrastructure\Models\Customer;
use App\Modules\Customer\Infrastructure\Models\CustomerStatus;
use App\Modules\Order\Application\Contracts\OrderLifecycleGateway;
use App\Modules\Order\Application\Data\CompletedOrderItemData;
use App\Modules\Order\Application\Data\CompletedOrderRegistrationData;
use App\Modules\Order\Application\Services\CompletedOrderRegistrar;
use App\Modules\Settlement\Application\Contracts\DirectCommissionConfigurationGateway;
use Carbon\CarbonImmutable;
use Database\Seeders\PhaseTwoReferenceDataSeeder;
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
