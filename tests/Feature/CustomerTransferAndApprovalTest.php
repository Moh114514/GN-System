<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Agent\Application\Contracts\AgentBusinessGroupAssignmentGateway;
use App\Modules\Agent\Infrastructure\Models\Agent;
use App\Modules\Agent\Infrastructure\Models\AgentTypeCode;
use App\Modules\Auth\Application\Contracts\BusinessGroupManagementGateway;
use App\Modules\Auth\Domain\UserRole;
use App\Modules\Config\Infrastructure\Models\Institution;
use App\Modules\Customer\Application\Data\CustomerProfileData;
use App\Modules\Customer\Application\Services\CustomerDirectory;
use App\Modules\Customer\Application\Services\CustomerFollowupManager;
use App\Modules\Customer\Application\Services\CustomerProfileManager;
use App\Modules\Customer\Application\Services\CustomerStatusApprovalManager;
use App\Modules\Customer\Application\Services\CustomerStatusManager;
use App\Modules\Customer\Application\Services\CustomerTransferManager;
use App\Modules\Customer\Infrastructure\Models\Customer;
use App\Modules\Customer\Infrastructure\Models\CustomerStatus;
use App\Modules\Order\Infrastructure\Models\Appointment;
use App\Modules\Order\Infrastructure\Models\Order;
use App\Modules\Reminder\Infrastructure\Models\Reminder;
use Carbon\CarbonImmutable;
use Database\Seeders\PhaseTwoReferenceDataSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CustomerTransferAndApprovalTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $bd;

    private User $owner;

    private User $targetOwner;

    private Agent $agent;

    private Institution $institution;

    private int $groupId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PhaseTwoReferenceDataSeeder::class);
        $this->admin = User::factory()->superAdmin()->create(['name' => 'PR3 Admin']);
        $this->bd = User::factory()->create(['name' => 'PR3 BD', 'role' => UserRole::BdManager]);
        $this->owner = User::factory()->create(['name' => 'PR3 Owner']);
        $this->targetOwner = User::factory()->create(['name' => 'PR3 Target']);

        $groups = app(BusinessGroupManagementGateway::class);
        $this->groupId = $groups->create('PR3', 'PR3 Group', $this->admin->id, null)['id'];
        $groups->assignMember($this->groupId, $this->bd->id, UserRole::BdManager->value, '2026-08-01', null, 'PR3 fixture', $this->admin->id, null);
        $groups->assignMember($this->groupId, $this->owner->id, UserRole::CustomerService->value, '2026-08-01', null, 'PR3 fixture', $this->admin->id, null);
        $groups->assignMember($this->groupId, $this->targetOwner->id, UserRole::CustomerService->value, '2026-08-01', null, 'PR3 fixture', $this->admin->id, null);

        $type = AgentTypeCode::query()->where('code', 'JG')->firstOrFail();
        $this->agent = Agent::query()->create([
            'agent_type_code_id' => $type->id,
            'code' => 'PR3-AGENT',
            'name' => 'PR3 Agent',
            'cooperation_status' => 'active',
        ]);
        app(AgentBusinessGroupAssignmentGateway::class)->assign($this->agent->id, $this->groupId, '2026-08-01', null, 'PR3 fixture', $this->admin->id, null);
        $this->institution = Institution::query()->firstOrFail();
    }

    public function test_transfer_request_keeps_old_owner_until_approval_and_moves_only_open_work(): void
    {
        $customerId = $this->createCustomer();
        $futureAppointment = Appointment::query()->create([
            'customer_id' => $customerId,
            'institution_id' => $this->institution->id,
            'scheduled_at' => CarbonImmutable::now()->addDay(),
            'owner_id' => $this->owner->id,
            'status' => 'scheduled',
        ]);
        $pastAppointment = Appointment::query()->create([
            'customer_id' => $customerId,
            'institution_id' => $this->institution->id,
            'scheduled_at' => CarbonImmutable::now()->subDay(),
            'owner_id' => $this->owner->id,
            'status' => 'scheduled',
        ]);
        $reminder = Reminder::query()->create([
            'customer_id' => $customerId,
            'assigned_to' => $this->owner->id,
            'created_by' => $this->owner->id,
            'source_type' => 'manual',
            'reminder_type' => 'followup',
            'title' => 'PR3 reminder',
            'due_at' => CarbonImmutable::now()->addDay(),
            'status' => 'pending',
            'notification_status' => 'pending',
            'dedupe_key' => str_repeat('a', 64),
        ]);
        app(CustomerFollowupManager::class)->record($customerId, 'call', CarbonImmutable::now(), '历史跟进', $this->owner->id, null);

        $transfers = app(CustomerTransferManager::class);
        $requestId = $transfers->request($customerId, $this->targetOwner->id, '客户交接', $this->owner, null);

        $this->assertDatabaseHas('customers', ['id' => $customerId, 'owner_id' => $this->owner->id]);
        $this->assertDatabaseHas('customer_transfer_requests', ['id' => $requestId, 'status' => 'pending']);
        $this->expectException(DomainException::class);
        $transfers->request($customerId, $this->targetOwner->id, '重复申请', $this->owner, null);
    }

    public function test_approved_transfer_moves_future_appointments_and_reminders_but_preserves_history_creators(): void
    {
        $customerId = $this->createCustomer();
        $futureAppointment = Appointment::query()->create([
            'customer_id' => $customerId,
            'institution_id' => $this->institution->id,
            'scheduled_at' => CarbonImmutable::now()->addDay(),
            'owner_id' => $this->owner->id,
            'status' => 'scheduled',
        ]);
        $pastAppointment = Appointment::query()->create([
            'customer_id' => $customerId,
            'institution_id' => $this->institution->id,
            'scheduled_at' => CarbonImmutable::now()->subDay(),
            'owner_id' => $this->owner->id,
            'status' => 'scheduled',
        ]);
        $reminder = Reminder::query()->create([
            'customer_id' => $customerId,
            'assigned_to' => $this->owner->id,
            'created_by' => $this->owner->id,
            'source_type' => 'manual',
            'reminder_type' => 'followup',
            'title' => 'PR3 reminder',
            'due_at' => CarbonImmutable::now()->addDay(),
            'status' => 'pending',
            'notification_status' => 'pending',
            'dedupe_key' => str_repeat('b', 64),
        ]);
        app(CustomerFollowupManager::class)->record($customerId, 'call', CarbonImmutable::now(), '历史跟进', $this->owner->id, null);
        $requestId = app(CustomerTransferManager::class)->request($customerId, $this->targetOwner->id, '客户交接', $this->owner, null);

        app(CustomerTransferManager::class)->approve($requestId, '审核通过', $this->bd, null);

        $this->assertDatabaseHas('customers', ['id' => $customerId, 'owner_id' => $this->targetOwner->id]);
        $this->assertDatabaseHas('customer_transfer_requests', ['id' => $requestId, 'status' => 'approved']);
        $this->assertDatabaseHas('customer_owner_histories', [
            'customer_id' => $customerId,
            'source' => 'request',
            'transfer_request_id' => $requestId,
            'from_owner_id' => $this->owner->id,
            'to_owner_id' => $this->targetOwner->id,
        ]);
        $this->assertDatabaseHas('appointments', ['id' => $futureAppointment->id, 'owner_id' => $this->targetOwner->id]);
        $this->assertDatabaseHas('appointments', ['id' => $pastAppointment->id, 'owner_id' => $this->owner->id]);
        $this->assertDatabaseHas('reminders', ['id' => $reminder->id, 'assigned_to' => $this->targetOwner->id, 'status' => 'transferred']);
        $this->assertDatabaseHas('followup_records', ['customer_id' => $customerId, 'owner_id' => $this->owner->id, 'content' => '历史跟进']);
    }

    public function test_invalid_target_expires_request_and_batch_transfer_is_atomic(): void
    {
        $customerId = $this->createCustomer();
        $transfers = app(CustomerTransferManager::class);
        $requestId = $transfers->request($customerId, $this->targetOwner->id, '客户交接', $this->owner, null);
        $this->targetOwner->update(['is_active' => false]);

        try {
            $transfers->approve($requestId, '审核', $this->bd, null);
            $this->fail('Expected inactive target to invalidate the request.');
        } catch (DomainException $exception) {
            $this->assertSame(__('customers.transfer.errors.target_unavailable'), $exception->getMessage());
        }
        $this->assertDatabaseHas('customer_transfer_requests', ['id' => $requestId, 'status' => 'expired']);
        $this->targetOwner->update(['is_active' => true]);

        $first = $this->createCustomer('PR3 Batch First');
        $second = $this->createCustomer('PR3 Batch Second');
        $transfers->batch([$first, $second], $this->targetOwner->id, '批量交接', $this->bd, null);
        $this->assertDatabaseHas('customers', ['id' => $first, 'owner_id' => $this->targetOwner->id]);
        $this->assertDatabaseHas('customers', ['id' => $second, 'owner_id' => $this->targetOwner->id]);

        $atomicFirst = $this->createCustomer('PR3 Atomic First');
        $atomicSecond = $this->createCustomer('PR3 Atomic Second');
        Customer::query()->whereKey($atomicSecond)->update(['owner_id' => $this->targetOwner->id]);
        try {
            $transfers->batch([$atomicFirst, $atomicSecond], $this->targetOwner->id, '原子批量交接', $this->bd, null);
            $this->fail('Expected same-owner item to abort the whole batch.');
        } catch (DomainException $exception) {
            $this->assertSame(__('customers.transfer.errors.same_owner'), $exception->getMessage());
        }
        $this->assertDatabaseHas('customers', ['id' => $atomicFirst, 'owner_id' => $this->owner->id]);
    }

    public function test_super_admin_can_transfer_across_groups_but_bd_cannot(): void
    {
        $otherOwner = User::factory()->create(['name' => 'PR3 Other Group']);
        $groups = app(BusinessGroupManagementGateway::class);
        $otherGroupId = $groups->create('PR3-OTHER', 'PR3 Other Group', $this->admin->id, null)['id'];
        $groups->assignMember($otherGroupId, $otherOwner->id, UserRole::CustomerService->value, '2026-08-01', null, 'PR3 fixture', $this->admin->id, null);
        $customerId = $this->createCustomer();

        try {
            app(CustomerTransferManager::class)->direct($customerId, $otherOwner->id, '跨组交接', $this->bd, null);
            $this->fail('Expected a BD cross-group transfer to be rejected.');
        } catch (DomainException $exception) {
            $this->assertSame(__('customers.transfer.errors.target_unavailable'), $exception->getMessage());
        }
        app(CustomerTransferManager::class)->direct($customerId, $otherOwner->id, '超级管理员跨组交接', $this->admin, null);
        $this->assertDatabaseHas('customers', ['id' => $customerId, 'owner_id' => $otherOwner->id]);
    }

    public function test_arrived_timestamp_and_status_history_survive_approved_rollback(): void
    {
        $customerId = $this->createCustomer();
        $booked = CustomerStatus::query()->where('key', 'booked')->firstOrFail();
        $arrived = CustomerStatus::query()->where('key', 'arrived')->firstOrFail();
        $statuses = app(CustomerStatusManager::class);
        $statuses->change($customerId, $arrived->id, '首次到院', $this->owner, null);
        $firstArrivedAt = Customer::query()->findOrFail($customerId)->arrived_at;
        $historyCount = Customer::query()->findOrFail($customerId)->statusHistories()->count();

        $requestId = app(CustomerStatusApprovalManager::class)->requestRollback($customerId, $booked->id, '需要重新评估', $this->owner, null);
        app(CustomerStatusApprovalManager::class)->approve($requestId, '允许回退', $this->bd, null);
        $flow = app(CustomerDirectory::class)->statusFlow($customerId);
        $bookedNode = collect($flow['statuses'])->firstWhere('id', $booked->id);
        $arrivedNode = collect($flow['statuses'])->firstWhere('id', $arrived->id);

        $this->assertSame('current', $bookedNode['state']);
        $this->assertSame('available', $arrivedNode['state']);
        $this->assertDatabaseCount('customer_status_change_requests', 1);
        $this->assertDatabaseHas('customer_status_change_requests', ['id' => $requestId, 'status' => 'approved']);
        $this->assertGreaterThanOrEqual($historyCount + 1, Customer::query()->findOrFail($customerId)->statusHistories()->count());

        $statuses->change($customerId, $arrived->id, '再次到院', $this->owner, null);
        $customer = Customer::query()->findOrFail($customerId);
        $this->assertNotNull($firstArrivedAt);
        $this->assertNotNull($customer->arrived_at);
        $this->assertGreaterThanOrEqual($historyCount + 2, $customer->statusHistories()->count());
        $this->assertDatabaseCount('customer_owner_histories', 1);
    }

    public function test_normal_status_rollback_is_blocked_after_an_order_exists(): void
    {
        $customerId = $this->createCustomer();
        $arrived = CustomerStatus::query()->where('key', 'arrived')->firstOrFail();
        $booked = CustomerStatus::query()->where('key', 'booked')->firstOrFail();
        app(CustomerStatusManager::class)->change($customerId, $arrived->id, '到院', $this->owner, null);
        $rollbackRequestId = app(CustomerStatusApprovalManager::class)->requestRollback($customerId, $booked->id, '订单前申请回退', $this->owner, null);
        Order::query()->create([
            'customer_id' => $customerId,
            'institution_id' => $this->institution->id,
            'agent_id' => $this->agent->id,
            'project_name' => 'PR3 order',
            'amount_krw' => 1000,
            'owner_id' => $this->owner->id,
            'status' => 'completed',
        ]);

        try {
            app(CustomerStatusManager::class)->change($customerId, $booked->id, '订单后回退', $this->owner, null);
            $this->fail('Expected ordinary rollback to be blocked after an order exists.');
        } catch (ValidationException) {
            // Expected business validation.
        }

        try {
            app(CustomerStatusApprovalManager::class)->approve($rollbackRequestId, '订单后拒绝', $this->bd, null);
            $this->fail('Expected a pending rollback to expire after an order was created.');
        } catch (DomainException $exception) {
            $this->assertSame(__('customers.status_approval.errors.order_exists'), $exception->getMessage());
        }
        $this->assertDatabaseHas('customer_status_change_requests', ['id' => $rollbackRequestId, 'status' => 'expired']);
    }

    private function createCustomer(string $name = 'PR3 Customer'): int
    {
        $manager = app(CustomerProfileManager::class);

        return $manager->create(
            profile: new CustomerProfileData(
                name: $name,
                gender: 'female',
                birthDate: CarbonImmutable::parse('1990-01-01'),
                sourceAgentId: $this->agent->id,
                contactValue: '138'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
                identityDocument: 'PR3-'.random_int(100000, 999999),
                projectIntention: 'PR3 project',
                notes: 'PR3 fixture',
            ),
            institutionId: $this->institution->id,
            arrivalAt: CarbonImmutable::now()->addDay(),
            translatorName: null,
            actorId: $this->owner->id,
            ownerId: $this->owner->id,
            confirmedCode: $manager->previewCode($this->agent->id),
            automaticCode: true,
            ipAddress: null,
        );
    }
}
