<?php

namespace App\Modules\Customer\Application\Services;

use App\Infrastructure\Time\BusinessClock;
use App\Models\User;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Auth\Application\Contracts\AccessContextResolver;
use App\Modules\Customer\Application\Contracts\CustomerTreatmentCompletionGateway;
use App\Modules\Customer\Infrastructure\Models\Customer;
use App\Modules\Customer\Infrastructure\Models\CustomerStatus;
use App\Modules\Customer\Infrastructure\Models\CustomerStatusHistory;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

final readonly class DatabaseCustomerTreatmentCompletionGateway implements CustomerTreatmentCompletionGateway
{
    public function __construct(
        private AccessContextResolver $access,
        private AuditRecorder $audit,
        private BusinessClock $clock,
    ) {}

    public function completeFromInstitutionReturn(
        int $customerId,
        CarbonImmutable $occurredOn,
        int $actorId,
        ?string $ipAddress,
    ): void {
        $actor = User::query()->findOrFail($actorId);
        $context = $this->access->forUser($actor);

        DB::transaction(function () use ($customerId, $occurredOn, $actor, $context, $ipAddress): void {
            $customer = Customer::query()->lockForUpdate()->findOrFail($customerId);
            abort_unless(
                $context->canViewCustomer(
                    $customer->source_agent_id === null ? null : (int) $customer->source_agent_id,
                    $customer->owner_id === null ? null : (int) $customer->owner_id,
                ),
                404,
            );

            $target = CustomerStatus::query()
                ->where('key', 'treatment_completed')
                ->where('is_active', true)
                ->first();
            if ($target === null) {
                throw new DomainException(__('orders.errors.customer_status_unavailable'));
            }

            $current = $customer->current_status_id === null
                ? null
                : CustomerStatus::query()->find($customer->current_status_id);
            $businessDate = $occurredOn->startOfDay();

            if ($current === null || $current->key === 'booked') {
                $arrived = CustomerStatus::query()
                    ->where('key', 'arrived')
                    ->where('is_active', true)
                    ->first();
                if ($arrived === null) {
                    throw new DomainException(__('orders.errors.customer_status_unavailable'));
                }

                $this->writeHistory($customer, $current, $arrived, $actor->id, '机构回传确认到院');
                $customer->update([
                    'current_status_id' => $arrived->id,
                    'arrived_at' => $customer->arrived_at ?? $businessDate,
                ]);
                $current = $arrived;
            }

            if ($current->id !== $target->id) {
                $this->writeHistory($customer, $current, $target, $actor->id, '机构回传确认施术完成');
            }
            $customer->update([
                'current_status_id' => $target->id,
                'arrived_at' => $customer->arrived_at ?? $businessDate,
                'treatment_completed_at' => $customer->treatment_completed_at ?? $businessDate,
            ]);

            $this->audit->record(
                description: '机构回传完成客户生命周期',
                properties: [
                    'customer_id' => $customer->id,
                    'occurred_on' => $businessDate->toDateString(),
                    'status' => $target->key,
                ],
                causerId: $actor->id,
                subject: $customer,
                logName: 'customer',
                event: 'institution_return_completed',
                ipAddress: $ipAddress,
            );
        }, 3);
    }

    private function writeHistory(
        Customer $customer,
        ?CustomerStatus $from,
        CustomerStatus $to,
        int $actorId,
        string $reason,
    ): void {
        CustomerStatusHistory::query()->create([
            'customer_id' => $customer->id,
            'from_status_id' => $from?->id,
            'to_status_id' => $to->id,
            'changed_by' => $actorId,
            'changed_at' => $this->clock->now(),
            'reason' => $reason,
        ]);
    }
}
