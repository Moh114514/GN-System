<?php

namespace App\Modules\Customer\Application\Services;

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
    ) {}

    public function record(
        int $customerId,
        string $type,
        CarbonImmutable $followedUpOn,
        string $content,
        int $actorId,
        ?string $ipAddress,
    ): void {
        DB::transaction(function () use ($customerId, $type, $followedUpOn, $content, $actorId, $ipAddress): void {
            $customer = Customer::query()->findOrFail($customerId);
            $context = $this->access->forUser(User::query()->findOrFail($actorId));
            abort_unless($context->canViewCustomer(
                $customer->source_agent_id === null ? null : (int) $customer->source_agent_id,
                $customer->owner_id === null ? null : (int) $customer->owner_id,
            ), 404);
            abort_unless(
                $context->isSuperAdmin()
                || $context->isBdManager()
                || ($context->isCustomerService() && (int) $customer->owner_id === $actorId),
                403,
            );
            $followupId = $this->followups->record(new CustomerFollowupData(
                customerId: $customerId,
                type: trim($type),
                followedUpOn: $followedUpOn,
                content: trim($content),
                ownerId: $actorId,
            ));
            $this->audit->record(
                description: '登记客户跟进',
                properties: ['followup_id' => $followupId, 'type' => trim($type), 'followed_up_on' => $followedUpOn->toDateString()],
                causerId: $actorId,
                subject: $customer,
                logName: 'customer',
                event: 'followup_created',
                ipAddress: $ipAddress,
            );
        }, 3);
    }
}
