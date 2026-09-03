<?php

namespace App\Modules\Order\Application\Services;

use App\Modules\Config\Application\Contracts\InstitutionReferenceReader;
use App\Modules\Customer\Application\Contracts\CustomerOrderReferenceReader;

final readonly class InstitutionReturnAccess
{
    public function __construct(
        private InstitutionReferenceReader $institutions,
        private CustomerOrderReferenceReader $customers,
    ) {}

    /** @return array<int, array{id: int, code: string, name: string}> */
    public function activeInstitutions(): array
    {
        return $this->institutions->activeInstitutions();
    }

    /** @return array<int, array{id: int, code: string, name: string, source_agent_id: int, owner_id: int|null}> */
    public function searchCustomers(string $search): array
    {
        return $this->customers->searchCustomersForOrder($search);
    }

    /** @return array{id: int, code: string, name: string, source_agent_id: int, owner_id: int|null} */
    public function customer(int $customerId): array
    {
        return $this->customers->customerForOrder($customerId);
    }

    public function authorizeCustomerDownload(int $customerId): void
    {
        $this->customers->customerForOrder($customerId);
    }
}
