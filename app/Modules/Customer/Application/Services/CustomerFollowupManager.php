<?php

namespace App\Modules\Customer\Application\Services;

use App\Infrastructure\Time\BusinessClock;
use App\Models\User;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Auth\Application\Contracts\AccessContextResolver;
use App\Modules\Customer\Infrastructure\Models\Customer;
use App\Modules\Reminder\Application\Contracts\CustomerFollowupGateway;
use App\Modules\Reminder\Application\Data\CustomerFollowupData;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final readonly class CustomerFollowupManager
{
    public function __construct(
        private CustomerFollowupGateway $followups,
        private AuditRecorder $audit,
        private AccessContextResolver $access,
        private BusinessClock $clock,
    ) {}

    public function record(
        int $customerId,
        string $type,
        CarbonImmutable $followedUpOn,
        string $content,
        int $actorId,
        ?string $ipAddress,
        string $result = '',
        ?int $reminderId = null,
        ?CarbonImmutable $nextFollowupAt = null,
    ): void {
        if ($nextFollowupAt !== null && $nextFollowupAt->lessThanOrEqualTo($this->clock->now())) {
            throw new \DomainException(__('customers.detail.followup.next_must_be_future'));
        }

        DB::transaction(function () use ($customerId, $type, $followedUpOn, $content, $result, $reminderId, $nextFollowupAt, $actorId, $ipAddress): void {
            $customer = Customer::query()->findOrFail($customerId);
            $context = $this->access->forUser(User::query()->findOrFail($actorId));
            abort_unless($context->canViewCustomer(
                sourceType: (string) $customer->source_type,
                sourceAgentId: $customer->source_agent_id === null ? null : (int) $customer->source_agent_id,
                ownerId: $customer->owner_id === null ? null : (int) $customer->owner_id,
            ), 404);
            abort_unless(
                $context->isSuperAdmin()
                || $context->isBdManager()
                || ($context->isCustomerService() && (int) $customer->owner_id === $actorId)
                || ($context->isDirectCustomerManager() && (string) $customer->source_type === 'direct' && (int) $customer->owner_id === $actorId),
                403,
            );
            $followupId = $this->followups->record(new CustomerFollowupData(
                customerId: $customerId,
                type: trim($type),
                followedUpOn: $followedUpOn,
                content: trim($content),
                ownerId: $actorId,
                result: trim($result),
                reminderId: $reminderId,
                nextFollowupAt: $nextFollowupAt,
            ));
            $this->audit->record(
                description: '登记客户跟进',
                properties: ['followup_id' => $followupId, 'type' => trim($type), 'followed_up_on' => $followedUpOn->toDateString(), 'reminder_id' => $reminderId],
                causerId: $actorId,
                subject: $customer,
                logName: 'customer',
                event: 'followup_created',
                ipAddress: $ipAddress,
            );
        }, 3);
    }
}
