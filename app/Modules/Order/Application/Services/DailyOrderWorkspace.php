<?php

namespace App\Modules\Order\Application\Services;

use App\Modules\Agent\Application\Contracts\AgentReferenceReader;
use App\Modules\Config\Application\Contracts\InstitutionReferenceReader;
use App\Modules\Customer\Application\Contracts\CustomerOrderReferenceReader;
use App\Modules\Order\Application\Contracts\DailyOrderGateway;
use App\Modules\Order\Application\Data\DailyOrderData;
use App\Modules\Order\Application\Data\OrderSummaryData;
use Carbon\CarbonImmutable;

final readonly class DailyOrderWorkspace
{
    public function __construct(
        private CustomerOrderReferenceReader $customers,
        private AgentReferenceReader $agents,
        private InstitutionReferenceReader $institutions,
        private DailyOrderGateway $orders,
    ) {}

    /** @return array<string, mixed> */
    public function context(int $customerId): array
    {
        return [
            'customer' => $this->customers->customerForOrder($customerId),
            'agents' => array_values($this->agents->activeAgents()),
            'direct_sources' => $this->customers->activeDirectSalesSources(),
            'institutions' => array_values($this->institutions->activeInstitutions()),
            'orders' => array_map(
                fn (OrderSummaryData $order): array => get_object_vars($order),
                $this->orders->forCustomer($customerId),
            ),
        ];
    }

    public function create(DailyOrderData $data): int
    {
        return $this->orders->create($data);
    }

    public function complete(int $orderId, CarbonImmutable $completedOn, int $actorId, ?string $ipAddress): int
    {
        return $this->orders->complete($orderId, $completedOn, $actorId, $ipAddress);
    }
}
