<?php

namespace App\Modules\Customer\Application\Services;

use App\Modules\Agent\Application\Contracts\AgentReferenceReader;
use App\Modules\Customer\Application\Contracts\ReminderCustomerReader;
use App\Modules\Customer\Application\Data\ReminderCustomerData;
use App\Modules\Customer\Infrastructure\Models\Customer;
use App\Modules\Customer\Infrastructure\Models\CustomerStatusHistory;
use Carbon\CarbonImmutable;

final readonly class DatabaseReminderCustomerReader implements ReminderCustomerReader
{
    public function __construct(private AgentReferenceReader $agents) {}

    public function candidates(): array
    {
        return Customer::query()->orderBy('id')->get()->map(fn (Customer $customer): ReminderCustomerData => $this->data($customer))->all();
    }

    public function byId(int $customerId): ReminderCustomerData
    {
        return $this->data(Customer::query()->findOrFail($customerId));
    }

    private function data(Customer $customer): ReminderCustomerData
    {
        $status = CustomerStatusHistory::query()->where('customer_id', $customer->id)->latest('changed_at')->first();
        $agentStatus = null;
        if ($customer->source_agent_id !== null) {
            $agentStatus = (string) $this->agents->agentById((int) $customer->source_agent_id)['cooperation_status'];
        }

        return new ReminderCustomerData(
            id: (int) $customer->id,
            name: (string) $customer->name,
            birthDate: $customer->birth_date === null ? null : CarbonImmutable::parse($customer->birth_date),
            wechatAddedOn: $customer->wechat_added_on === null ? null : CarbonImmutable::parse($customer->wechat_added_on),
            createdAt: CarbonImmutable::parse($customer->created_at),
            ownerId: $customer->owner_id === null ? null : (int) $customer->owner_id,
            sourceAgentId: $customer->source_agent_id === null ? null : (int) $customer->source_agent_id,
            agentStatus: $agentStatus,
            statusId: $customer->current_status_id === null ? null : (int) $customer->current_status_id,
            statusChangedAt: $status === null ? null : CarbonImmutable::parse($status->changed_at),
            projectIntention: $customer->project_intention,
        );
    }
}
