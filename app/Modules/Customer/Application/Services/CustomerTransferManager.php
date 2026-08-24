<?php

namespace App\Modules\Customer\Application\Services;

use App\Infrastructure\Time\BusinessClock;
use App\Models\User;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Auth\Application\Contracts\AccessContextResolver;
use App\Modules\Auth\Application\Contracts\BusinessGroupMembershipReader;
use App\Modules\Auth\Application\Contracts\InternalUserReferenceReader;
use App\Modules\Auth\Application\Data\AccessContext;
use App\Modules\Config\Application\Contracts\NotificationRecipientGateway;
use App\Modules\Customer\Infrastructure\Models\Customer;
use App\Modules\Customer\Infrastructure\Models\CustomerOwnerHistory;
use App\Modules\Customer\Infrastructure\Models\CustomerTransferRequest;
use App\Modules\Order\Application\Contracts\CustomerOrderGateway;
use App\Modules\Reminder\Application\Contracts\TreatmentReminderGateway;
use DomainException;
use Illuminate\Support\Facades\DB;

final readonly class CustomerTransferManager
{
    public function __construct(
        private AccessContextResolver $access,
        private BusinessGroupMembershipReader $memberships,
        private InternalUserReferenceReader $users,
        private CustomerOrderGateway $orders,
        private TreatmentReminderGateway $reminders,
        private NotificationRecipientGateway $notifications,
        private AuditRecorder $audit,
        private BusinessClock $clock,
    ) {}

    /** @return list<array{id: int, name: string}> */
    public function ownerCandidates(): array
    {
        $context = $this->access->current();
        $groupIds = $context->isSuperAdmin() || $context->businessGroupIds === []
            ? null
            : $context->businessGroupIds;
        $allowedIds = $this->memberships->activeCustomerServiceUserIds($groupIds, $this->clock->now()->toDateString());
        $names = collect($this->users->eligibleUsers())->keyBy('id');

        return array_values(array_map(
            static fn (int $id): array => ['id' => $id, 'name' => (string) ($names->get($id)['name'] ?? '')],
            array_filter($allowedIds, static fn (int $id): bool => $names->has($id)),
        ));
    }

    /** @return array<string, mixed>|null */
    public function pendingForCustomer(int $customerId): ?array
    {
        $customer = $this->customer($customerId);
        $this->assertVisible($customer);
        $request = CustomerTransferRequest::query()
            ->with(['toOwner:id,name'])
            ->where('customer_id', $customerId)
            ->where('status', 'pending')
            ->first();
        if ($request === null) {
            return null;
        }

        return [
            'id' => (int) $request->id,
            'from_owner_id' => $request->from_owner_id === null ? null : (int) $request->from_owner_id,
            'to_owner_id' => (int) $request->to_owner_id,
            'to_owner_name' => (string) ($request->toOwner->name ?? ''),
            'requested_by' => (int) $request->requested_by,
            'reason' => (string) $request->request_reason,
            'requested_at' => $request->requested_at->format('Y-m-d H:i'),
        ];
    }

    public function request(int $customerId, int $toOwnerId, string $reason, User $actor, ?string $ipAddress): int
    {
        return DB::transaction(function () use ($customerId, $toOwnerId, $reason, $actor, $ipAddress): int {
            $customer = $this->lockedCustomer($customerId);
            $context = $this->access->forUser($actor);
            $this->assertOwnerCanRequest($customer, $actor, $context);
            $this->assertTarget($toOwnerId, $context);
            $reason = $this->requiredReason($reason);
            if ((int) $customer->owner_id === $toOwnerId) {
                throw new DomainException(__('customers.transfer.errors.same_owner'));
            }
            if (CustomerTransferRequest::query()->where('customer_id', $customerId)->where('status', 'pending')->exists()) {
                throw new DomainException(__('customers.transfer.errors.pending_exists'));
            }

            $request = CustomerTransferRequest::query()->create([
                'customer_id' => $customer->id,
                'from_owner_id' => $customer->owner_id,
                'to_owner_id' => $toOwnerId,
                'requested_by' => $actor->id,
                'request_reason' => $reason,
                'status' => 'pending',
                'requested_at' => $this->clock->now(),
            ]);
            $this->audit->record(
                description: __('customers.transfer.audit.requested'),
                properties: ['request_id' => $request->id, 'to_owner_id' => $toOwnerId, 'reason' => $reason],
                causerId: $actor->id,
                subject: $customer,
                logName: 'customer-transfer',
                event: 'transfer_requested',
                ipAddress: $ipAddress,
            );

            return (int) $request->id;
        }, 3);
    }

    public function withdraw(int $requestId, User $actor, ?string $ipAddress): void
    {
        DB::transaction(function () use ($requestId, $actor, $ipAddress): void {
            $request = CustomerTransferRequest::query()->lockForUpdate()->findOrFail($requestId);
            $customer = $this->lockedCustomer((int) $request->customer_id);
            $context = $this->access->forUser($actor);
            $this->assertVisible($customer, $context);
            abort_unless($context->isCustomerService() && (int) $request->requested_by === (int) $actor->id, 403);
            if ($request->status !== 'pending') {
                throw new DomainException(__('customers.transfer.errors.not_pending'));
            }
            $request->update([
                'status' => 'withdrawn',
                'reviewed_by' => $actor->id,
                'reviewed_at' => $this->clock->now(),
                'review_reason' => __('customers.transfer.audit.withdrawn'),
            ]);
            $this->audit->record(
                description: __('customers.transfer.audit.withdrawn'),
                properties: ['request_id' => $request->id],
                causerId: $actor->id,
                subject: $customer,
                logName: 'customer-transfer',
                event: 'transfer_withdrawn',
                ipAddress: $ipAddress,
            );
        }, 3);
    }

    public function approve(int $requestId, string $reviewReason, User $actor, ?string $ipAddress): void
    {
        try {
            DB::transaction(function () use ($requestId, $reviewReason, $actor, $ipAddress): void {
                $request = CustomerTransferRequest::query()->lockForUpdate()->findOrFail($requestId);
                $customer = $this->lockedCustomer((int) $request->customer_id);
                $context = $this->access->forUser($actor);
                $this->assertReviewer($customer, $actor, $context);
                $this->assertPending($request->status);
                if ((int) ($customer->owner_id ?? 0) !== (int) ($request->from_owner_id ?? 0)) {
                    throw new DomainException(__('customers.transfer.errors.owner_changed'));
                }
                $this->assertTarget((int) $request->to_owner_id, $context);
                $this->applyTransfer($customer, (int) $request->to_owner_id, 'request', $request->id, $reviewReason, $actor, $ipAddress);
                $request->update([
                    'status' => 'approved',
                    'reviewed_by' => $actor->id,
                    'review_reason' => $this->requiredReason($reviewReason),
                    'reviewed_at' => $this->clock->now(),
                ]);
            }, 3);
        } catch (DomainException $exception) {
            if (in_array($exception->getMessage(), [
                __('customers.transfer.errors.owner_changed'),
                __('customers.transfer.errors.target_unavailable'),
            ], true)) {
                CustomerTransferRequest::query()
                    ->whereKey($requestId)
                    ->where('status', 'pending')
                    ->update([
                        'status' => 'expired',
                        'reviewed_by' => $actor->id,
                        'review_reason' => $exception->getMessage(),
                        'reviewed_at' => $this->clock->now(),
                    ]);
            }

            throw $exception;
        }
    }

    public function reject(int $requestId, string $reviewReason, User $actor, ?string $ipAddress): void
    {
        $this->review($requestId, $reviewReason, 'rejected', $actor, $ipAddress);
    }

    public function direct(int $customerId, int $toOwnerId, string $reason, User $actor, ?string $ipAddress): void
    {
        DB::transaction(function () use ($customerId, $toOwnerId, $reason, $actor, $ipAddress): void {
            $customer = $this->lockedCustomer($customerId);
            $context = $this->access->forUser($actor);
            $this->assertReviewer($customer, $actor, $context);
            $this->assertTarget($toOwnerId, $context);
            $reason = $this->requiredReason($reason);
            if ((int) $customer->owner_id === $toOwnerId) {
                throw new DomainException(__('customers.transfer.errors.same_owner'));
            }
            CustomerTransferRequest::query()
                ->where('customer_id', $customer->id)
                ->where('status', 'pending')
                ->update([
                    'status' => 'expired',
                    'reviewed_by' => $actor->id,
                    'reviewed_at' => $this->clock->now(),
                    'review_reason' => __('customers.transfer.errors.superseded'),
                ]);
            $this->applyTransfer($customer, $toOwnerId, $context->isSuperAdmin() && $this->crossGroupTarget($toOwnerId, $context) ? 'admin_cross_group' : 'bd_direct', null, $reason, $actor, $ipAddress);
        }, 3);
    }

    /** @param list<int> $customerIds */
    public function batch(array $customerIds, int $toOwnerId, string $reason, User $actor, ?string $ipAddress): void
    {
        $customerIds = array_values(array_unique(array_map('intval', $customerIds)));
        sort($customerIds);
        if ($customerIds === []) {
            throw new DomainException(__('customers.transfer.errors.no_customers'));
        }
        DB::transaction(function () use ($customerIds, $toOwnerId, $reason, $actor, $ipAddress): void {
            $context = $this->access->forUser($actor);
            $this->assertTarget($toOwnerId, $context);
            $reason = $this->requiredReason($reason);
            foreach ($customerIds as $customerId) {
                $customer = $this->lockedCustomer($customerId);
                $this->assertReviewer($customer, $actor, $context);
                if ((int) $customer->owner_id === $toOwnerId) {
                    throw new DomainException(__('customers.transfer.errors.same_owner'));
                }
                CustomerTransferRequest::query()
                    ->where('customer_id', $customer->id)
                    ->where('status', 'pending')
                    ->update([
                        'status' => 'expired',
                        'reviewed_by' => $actor->id,
                        'reviewed_at' => $this->clock->now(),
                        'review_reason' => __('customers.transfer.errors.superseded'),
                    ]);
                $this->applyTransfer($customer, $toOwnerId, 'batch', null, $reason, $actor, $ipAddress);
            }
        }, 3);
    }

    private function review(int $requestId, string $reviewReason, string $status, User $actor, ?string $ipAddress): void
    {
        DB::transaction(function () use ($requestId, $reviewReason, $status, $actor, $ipAddress): void {
            $request = CustomerTransferRequest::query()->lockForUpdate()->findOrFail($requestId);
            $customer = $this->lockedCustomer((int) $request->customer_id);
            $context = $this->access->forUser($actor);
            $this->assertReviewer($customer, $actor, $context);
            $this->assertPending($request->status);
            $reviewReason = $this->requiredReason($reviewReason);
            $request->update([
                'status' => $status,
                'reviewed_by' => $actor->id,
                'review_reason' => $reviewReason,
                'reviewed_at' => $this->clock->now(),
            ]);
            $this->audit->record(
                description: $status === 'rejected' ? __('customers.transfer.audit.rejected') : __('customers.transfer.audit.expired'),
                properties: ['request_id' => $request->id, 'reason' => $reviewReason],
                causerId: $actor->id,
                subject: $customer,
                logName: 'customer-transfer',
                event: 'transfer_'.$status,
                ipAddress: $ipAddress,
            );
        }, 3);
    }

    private function applyTransfer(Customer $customer, int $toOwnerId, string $source, ?int $requestId, string $reason, User $actor, ?string $ipAddress): void
    {
        $fromOwnerId = $customer->owner_id === null ? null : (int) $customer->owner_id;
        $customer->update(['owner_id' => $toOwnerId]);
        $this->orders->transferFutureAppointments((int) $customer->id, $toOwnerId, $this->clock->now());
        $this->reminders->transferForCustomer((int) $customer->id, $toOwnerId, (int) $actor->id);
        $context = $this->access->forUser($actor);
        $history = CustomerOwnerHistory::query()->create([
            'customer_id' => $customer->id,
            'business_group_id' => $context->businessGroupIds[0] ?? null,
            'from_owner_id' => $fromOwnerId,
            'to_owner_id' => $toOwnerId,
            'source' => $source,
            'transfer_request_id' => $requestId,
            'changed_by' => $actor->id,
            'reason' => $reason,
            'effective_at' => $this->clock->now(),
        ]);
        $this->notifications->notifyInternalUsers(
            'customer_transfer',
            'customer-transfer:'.$history->id,
            __('customers.transfer.notifications.title'),
            __('customers.transfer.notifications.body', ['customer' => $customer->name, 'reason' => $reason]),
            array_values(array_filter([$fromOwnerId, $toOwnerId, (int) $actor->id])),
            route('customers.show', $customer->id),
        );
        $this->audit->record(
            description: __('customers.transfer.audit.completed'),
            properties: ['source' => $source, 'from_owner_id' => $fromOwnerId, 'to_owner_id' => $toOwnerId, 'reason' => $reason],
            causerId: $actor->id,
            subject: $customer,
            logName: 'customer-transfer',
            event: 'owner_changed',
            ipAddress: $ipAddress,
        );
    }

    private function customer(int $customerId): Customer
    {
        return Customer::query()->findOrFail($customerId);
    }

    private function lockedCustomer(int $customerId): Customer
    {
        /** @var Customer $customer */
        $customer = Customer::query()->lockForUpdate()->findOrFail($customerId);

        return $customer;
    }

    private function assertVisible(Customer $customer, ?AccessContext $context = null): void
    {
        $context ??= $this->access->current();
        abort_unless($context->canViewCustomer(
            $customer->source_agent_id === null ? null : (int) $customer->source_agent_id,
            $customer->owner_id === null ? null : (int) $customer->owner_id,
        ), 404);
    }

    private function assertOwnerCanRequest(Customer $customer, User $actor, AccessContext $context): void
    {
        $this->assertVisible($customer, $context);
        abort_unless($context->isCustomerService() && (int) $customer->owner_id === (int) $actor->id, 403);
    }

    private function assertReviewer(Customer $customer, User $actor, AccessContext $context): void
    {
        $this->assertVisible($customer, $context);
        abort_unless($context->isSuperAdmin() || $context->isBdManager(), 403);
        abort_unless($context->isSuperAdmin() || $context->canViewCustomer(
            $customer->source_agent_id === null ? null : (int) $customer->source_agent_id,
            $customer->owner_id === null ? null : (int) $customer->owner_id,
        ), 404);
    }

    private function assertTarget(int $toOwnerId, AccessContext $context): void
    {
        $groupIds = $context->isSuperAdmin() || $context->businessGroupIds === [] ? null : $context->businessGroupIds;
        if (! $this->memberships->isActiveCustomerServiceInGroups($toOwnerId, $groupIds, $this->clock->now()->toDateString())) {
            throw new DomainException(__('customers.transfer.errors.target_unavailable'));
        }
    }

    private function crossGroupTarget(int $toOwnerId, AccessContext $context): bool
    {
        return $context->isSuperAdmin()
            && ! $this->memberships->isActiveCustomerServiceInGroups($toOwnerId, $context->businessGroupIds, $this->clock->now()->toDateString());
    }

    private function assertPending(string $status): void
    {
        if ($status !== 'pending') {
            throw new DomainException(__('customers.transfer.errors.not_pending'));
        }
    }

    private function requiredReason(string $reason): string
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new DomainException(__('customers.transfer.errors.reason_required'));
        }

        return $reason;
    }
}
