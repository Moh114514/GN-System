<?php

namespace App\Modules\Customer\Application\Services;

use App\Modules\Audit\Application\Contracts\AuditRecorder;
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
