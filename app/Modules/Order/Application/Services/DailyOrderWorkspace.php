<?php

namespace App\Modules\Order\Application\Services;

use App\Modules\Agent\Application\Contracts\AgentReferenceReader;
use App\Modules\Config\Application\Contracts\InstitutionReferenceReader;
use App\Modules\Config\Application\Contracts\OrderDictionaryReader;
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
        private OrderDictionaryReader $dictionary,
        private DailyOrderGateway $orders,
    ) {}

    /** @return array<string, mixed> */
    public function context(int $customerId): array
    {
        return [
            'customer' => $this->customers->customerForOrder($customerId),
            'agents' => array_values($this->agents->activeAgents()),
            'institutions' => array_values($this->institutions->activeInstitutions()),
            'treatment_projects' => $this->dictionary->activeItems('treatment_project'),
            'translator_languages' => $this->dictionary->activeItems('translator_language'),
            'orders' => array_map(
                fn (OrderSummaryData $order): array => get_object_vars($order),
                $this->orders->forCustomer($customerId),
            ),
        ];
    }

    public function create(DailyOrderData $data): int
    {
        $project = $data->treatmentProjectId === null
            ? null
            : $this->dictionary->activeItem($data->treatmentProjectId, 'treatment_project');
        $language = $data->translatorLanguageId === null
            ? null
            : $this->dictionary->activeItem($data->translatorLanguageId, 'translator_language');

        return $this->orders->create(new DailyOrderData(
            customerId: $data->customerId,
            institutionId: $data->institutionId,
            agentId: $data->agentId,
            projectName: $project['name'] ?? $data->projectName,
            amountKrw: $data->amountKrw,
            status: $data->status,
            completedOn: $data->completedOn,
            translatorName: $data->translatorName,
            notes: $data->notes,
            ownerId: $data->ownerId,
            ipAddress: $data->ipAddress,
            treatmentProjectId: $project['id'] ?? null,
            translatorLanguageId: $language['id'] ?? null,
            translatorLanguageName: $language['name'] ?? null,
        ));
    }

    public function complete(int $orderId, CarbonImmutable $completedOn, int $actorId, ?string $ipAddress): int
    {
        return $this->orders->complete($orderId, $completedOn, $actorId, $ipAddress);
    }
}
