<?php

namespace App\Modules\Customer\Application\Services;

use App\Infrastructure\Time\BusinessClock;
use App\Models\User;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Auth\Application\Contracts\AccessContextResolver;
use App\Modules\Auth\Application\Data\AccessContext;
use App\Modules\Customer\Infrastructure\Models\Customer;
use App\Modules\Customer\Infrastructure\Models\CustomerStatus;
use App\Modules\Customer\Infrastructure\Models\CustomerStatusChangeRequest;
use App\Modules\Order\Application\Contracts\CustomerOrderGateway;
use DomainException;
use Illuminate\Support\Facades\DB;

final readonly class CustomerStatusApprovalManager
{
    public function __construct(
        private AccessContextResolver $access,
        private CustomerOrderGateway $orders,
        private CustomerStatusManager $statuses,
        private BusinessClock $clock,
        private AuditRecorder $audit,
    ) {}

    /** @return array<string, mixed>|null */
    public function pendingForCustomer(int $customerId): ?array
    {
        $customer = Customer::query()->findOrFail($customerId);
        $this->assertVisible($customer);
        $request = CustomerStatusChangeRequest::query()->where('customer_id', $customerId)->where('status', 'pending')->first();
        if ($request === null) {
            return null;
        }

        return [
            'id' => (int) $request->id,
            'from_status_id' => $request->from_status_id === null ? null : (int) $request->from_status_id,
            'to_status_id' => (int) $request->to_status_id,
            'requested_by' => (int) $request->requested_by,
            'reason' => (string) $request->request_reason,
            'requested_at' => $request->requested_at->format('Y-m-d H:i'),
        ];
    }

    public function requestRollback(int $customerId, int $targetStatusId, string $reason, User $actor, ?string $ipAddress): int
    {
        return DB::transaction(function () use ($customerId, $targetStatusId, $reason, $actor, $ipAddress): int {
            $customer = $this->lockedCustomer($customerId);
            $context = $this->access->forUser($actor);
            $this->assertOwnerCanRequest($customer, $actor, $context);
            $current = $customer->current_status_id === null ? null : CustomerStatus::query()->findOrFail($customer->current_status_id);
            $target = CustomerStatus::query()->whereKey($targetStatusId)->where('is_active', true)->firstOrFail();
            if ($current === null || $target->sort_order >= $current->sort_order) {
                throw new DomainException(__('customers.status_approval.errors.not_rollback'));
            }
            if ($this->orders->hasAnyOrder($customerId)) {
                throw new DomainException(__('customers.status_approval.errors.order_exists'));
            }
            $reason = $this->reason($reason);
            if (CustomerStatusChangeRequest::query()->where('customer_id', $customerId)->where('status', 'pending')->exists()) {
                throw new DomainException(__('customers.status_approval.errors.pending_exists'));
            }

            $request = CustomerStatusChangeRequest::query()->create([
                'customer_id' => $customerId,
                'from_status_id' => $current->id,
                'to_status_id' => $target->id,
                'requested_by' => $actor->id,
                'request_reason' => $reason,
                'status' => 'pending',
                'requested_at' => $this->clock->now(),
            ]);
            $this->audit->record(
                description: __('customers.status_approval.audit.requested'),
                properties: ['request_id' => $request->id, 'to_status_id' => $target->id, 'reason' => $reason],
                causerId: $actor->id,
                subject: $customer,
                logName: 'customer-status-approval',
                event: 'status_rollback_requested',
                ipAddress: $ipAddress,
            );

            return (int) $request->id;
        }, 3);
    }

    public function withdraw(int $requestId, User $actor): void
    {
        DB::transaction(function () use ($requestId, $actor): void {
            $request = CustomerStatusChangeRequest::query()->lockForUpdate()->findOrFail($requestId);
            $customer = $this->lockedCustomer((int) $request->customer_id);
            $context = $this->access->forUser($actor);
            $this->assertVisible($customer, $context);
            abort_unless($context->isCustomerService() && (int) $request->requested_by === (int) $actor->id, 403);
            $this->assertPending($request->status);
            $request->update(['status' => 'withdrawn', 'reviewed_by' => $actor->id, 'reviewed_at' => $this->clock->now()]);
            $this->audit->record(
                description: __('customers.status_approval.audit.withdrawn'),
                properties: ['request_id' => $request->id],
                causerId: $actor->id,
                subject: $customer,
                logName: 'customer-status-approval',
                event: 'status_rollback_withdrawn',
            );
        }, 3);
    }

    public function approve(int $requestId, string $reviewReason, User $actor, ?string $ipAddress): void
    {
        try {
            DB::transaction(function () use ($requestId, $reviewReason, $actor, $ipAddress): void {
                $request = CustomerStatusChangeRequest::query()->lockForUpdate()->findOrFail($requestId);
                $customer = $this->lockedCustomer((int) $request->customer_id);
                $context = $this->access->forUser($actor);
                $this->assertReviewer($customer, $context);
                $this->assertPending($request->status);
                if ($this->orders->hasAnyOrder($customer->id)) {
                    throw new DomainException(__('customers.status_approval.errors.order_exists'));
                }
                if ((int) ($customer->current_status_id ?? 0) !== (int) ($request->from_status_id ?? 0)) {
                    throw new DomainException(__('customers.status_approval.errors.stale'));
                }
                $this->statuses->change(
                    customerId: (int) $customer->id,
                    targetStatusId: (int) $request->to_status_id,
                    reason: $request->request_reason,
                    actor: $actor,
                    ipAddress: $ipAddress,
                    approvedRollback: true,
                );
                $request->update([
                    'status' => 'approved',
                    'reviewed_by' => $actor->id,
                    'review_reason' => $this->reason($reviewReason),
                    'reviewed_at' => $this->clock->now(),
                ]);
                $this->audit->record(
                    description: __('customers.status_approval.audit.approved'),
                    properties: ['request_id' => $request->id, 'to_status_id' => $request->to_status_id],
                    causerId: $actor->id,
                    subject: $customer,
                    logName: 'customer-status-approval',
                    event: 'status_rollback_approved',
                    ipAddress: $ipAddress,
                );
            }, 3);
        } catch (DomainException $exception) {
            if (in_array($exception->getMessage(), [
                __('customers.status_approval.errors.order_exists'),
                __('customers.status_approval.errors.stale'),
            ], true)) {
                CustomerStatusChangeRequest::query()
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

    public function reject(int $requestId, string $reviewReason, User $actor): void
    {
        DB::transaction(function () use ($requestId, $reviewReason, $actor): void {
            $request = CustomerStatusChangeRequest::query()->lockForUpdate()->findOrFail($requestId);
            $customer = $this->lockedCustomer((int) $request->customer_id);
            $this->assertReviewer($customer, $this->access->forUser($actor));
            $this->assertPending($request->status);
            $request->update([
                'status' => 'rejected',
                'reviewed_by' => $actor->id,
                'review_reason' => $this->reason($reviewReason),
                'reviewed_at' => $this->clock->now(),
            ]);
            $this->audit->record(
                description: __('customers.status_approval.audit.rejected'),
                properties: ['request_id' => $request->id, 'reason' => $reviewReason],
                causerId: $actor->id,
                subject: $customer,
                logName: 'customer-status-approval',
                event: 'status_rollback_rejected',
            );
        }, 3);
    }

    private function lockedCustomer(int $customerId): Customer
    {
        return Customer::query()->lockForUpdate()->findOrFail($customerId);
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

    private function assertReviewer(Customer $customer, AccessContext $context): void
    {
        $this->assertVisible($customer, $context);
        abort_unless($context->isSuperAdmin() || $context->isBdManager(), 403);
    }

    private function assertPending(string $status): void
    {
        if ($status !== 'pending') {
            throw new DomainException(__('customers.status_approval.errors.not_pending'));
        }
    }

    private function reason(string $reason): string
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new DomainException(__('customers.status_approval.errors.reason_required'));
        }

        return $reason;
    }
}
