<?php

namespace App\Modules\Report\Application\Services;

use App\Modules\Agent\Application\Contracts\ReportAgentReader;
use App\Modules\Auth\Application\Contracts\AccessContextResolver;
use App\Modules\Customer\Application\Contracts\ReportCustomerReader;

final readonly class GlobalSearch
{
    public function __construct(
        private ReportCustomerReader $customers,
        private ReportAgentReader $agents,
        private ReportSearch $orders,
        private AccessContextResolver $access,
    ) {}

    /**
     * @return array{
     *   customers: array{
     *     total: int,
     *     items: array<int, array{id: int, code: string, name: string, status: string}>
     *   },
     *   orders: array{
     *     total: int,
     *     items: array<int, array<string, int|string|null>>
     *   },
     *   agents: array{
     *     total: int,
     *     items: array<int, array{id: int, code: string, name: string, status: string}>
     *   }|null
     * }
     */
    public function search(string $query, bool $includeAgents, int $limit = 10): array
    {
        $query = trim($query);
        if ($query === '') {
            return [
                'customers' => ['total' => 0, 'items' => []],
                'orders' => ['total' => 0, 'items' => []],
                'agents' => $includeAgents ? ['total' => 0, 'items' => []] : null,
            ];
        }

        $orders = $this->orders->paginate(['project_name' => $query], max(1, $limit), 1);

        return [
            'customers' => $this->customers->globalSearch($query, $limit),
            'orders' => [
                'total' => $orders['page']->total,
                'items' => $orders['rows'],
            ],
            'agents' => $includeAgents && ! $this->access->current()->isCustomerService()
                ? $this->agents->globalSearch($query, $limit)
                : ($includeAgents ? ['total' => 0, 'items' => []] : null),
        ];
    }
}
