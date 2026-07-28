<?php

namespace App\Modules\Customer\Application\Contracts;

interface AgentCustomerPortfolioReader
{
    /** @return array<int, array{id: int, code: string, name: string, created_at: string|null}> */
    public function customersForAgent(int $agentId): array;
}
