<?php

namespace App\Modules\Customer\Application\Services;

use App\Modules\Auth\Application\Contracts\AccessContextResolver;
use App\Modules\Customer\Application\Contracts\AgentCustomerPortfolioReader;
use App\Modules\Customer\Infrastructure\Models\Customer;

final class DatabaseAgentCustomerPortfolioReader implements AgentCustomerPortfolioReader
{
    public function __construct(private readonly AccessContextResolver $access) {}

    public function customersForAgent(int $agentId): array
    {
        abort_unless($this->access->current()->canViewAgent($agentId), 404);

        return Customer::query()
            ->where('source_agent_id', $agentId)
            ->latest('created_at')
            ->limit(100)
            ->get(['id', 'code', 'name', 'created_at'])
            ->map(fn (Customer $customer): array => [
                'id' => (int) $customer->id,
                'code' => (string) $customer->code,
                'name' => (string) $customer->name,
                'created_at' => $customer->created_at?->format('Y-m-d H:i'),
            ])
            ->all();
    }
}
