<?php

namespace App\Modules\Report\Application\Services;

use App\Modules\Agent\Application\Contracts\ReportAgentReader;
use App\Modules\Customer\Application\Contracts\ReportCustomerReader;
use App\Modules\Order\Application\Contracts\ReportOrderReader;
use Carbon\CarbonImmutable;
use DomainException;

final readonly class InstitutionMonthlySalesDetailService
{
    public function __construct(
        private InstitutionMonthlySalesService $summary,
        private ReportOrderReader $orders,
        private ReportAgentReader $agents,
        private ReportCustomerReader $customers,
    ) {}

    /** @return array<string, mixed> */
    public function detail(
        string $month,
        int $institutionId,
        ?int $agentId = null,
        string $search = '',
        string $sort = 'occurred_on',
        string $direction = 'desc',
        int $perPage = 20,
        int $page = 1,
    ): array {
        $month = $this->summary->normalizeMonth($month);
        $from = CarbonImmutable::createFromFormat('!Y-m-d', $month.'-01', (string) config('app.timezone'));
        $to = $from->endOfMonth();
        $monthly = $this->summary->summary($month, $institutionId);
        $row = $monthly->rows[0] ?? null;
        if ($row === null) {
            throw new DomainException(__('institution_sales.errors.institution_unavailable'));
        }

        $agentRows = $this->orders->institutionMonthlySalesAgents($from, $to, $institutionId);
        $agentNames = $this->agents->namesByIds(array_values(array_filter(array_map(
            static fn ($agent): ?int => $agent->agentId,
            $agentRows,
        ))));
        $agents = array_map(function ($agent) use ($agentNames, $row): array {
            $name = $agent->agentId === null
                ? __('institution_sales.detail.unassigned_agent')
                : ($agentNames[$agent->agentId] ?? __('institution_sales.fallbacks.missing_agent'));

            return [
                'id' => $agent->agentId,
                'name' => $name,
                'customer_count' => $agent->customerCount,
                'order_count' => $agent->orderCount,
                'amount_krw' => $agent->amountKrw,
                'share' => $row->amountKrw === 0 ? 0.0 : round($agent->amountKrw / $row->amountKrw * 100, 2),
            ];
        }, $agentRows);
        usort($agents, static fn (array $left, array $right): int => [
            mb_strtolower((string) $left['name']),
            (int) ($left['id'] ?? 0),
        ] <=> [
            mb_strtolower((string) $right['name']),
            (int) ($right['id'] ?? 0),
        ]);

        $orders = $this->orders->institutionMonthlySalesOrders(
            from: $from,
            to: $to,
            institutionId: $institutionId,
            agentId: $agentId,
            search: $search,
            sort: $sort,
            direction: $direction,
            perPage: $perPage,
            page: $page,
        );
        $customerNames = $this->customers->namesByIds(array_map(
            static fn ($order): int => $order->customerId,
            $orders->items,
        ));
        $orderAgentIds = array_values(array_filter(array_map(
            static fn ($order): ?int => $order->agentId,
            $orders->items,
        )));
        $orderAgentNames = $this->agents->namesByIds($orderAgentIds);
        $orderRows = array_map(static function ($order) use ($customerNames, $orderAgentNames): array {
            return [
                'id' => $order->id,
                'occurred_on' => $order->occurredOn,
                'customer_id' => $order->customerId,
                'customer' => $customerNames[$order->customerId] ?? __('institution_sales.fallbacks.missing_customer'),
                'agent_id' => $order->agentId,
                'agent' => $order->agentId === null
                    ? __('institution_sales.detail.unassigned_agent')
                    : ($orderAgentNames[$order->agentId] ?? __('institution_sales.fallbacks.missing_agent')),
                'project_name' => $order->projectName,
                'amount_krw' => $order->amountKrw,
            ];
        }, $orders->items);

        return [
            'month' => $month,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'institution_id' => $row->institutionId,
            'institution' => $row->institutionName,
            'customer_count' => $row->customerCount,
            'order_count' => $row->orderCount,
            'agent_count' => count(array_filter($agents, static fn (array $agent): bool => $agent['id'] !== null)),
            'amount_krw' => $row->amountKrw,
            'agents' => $agents,
            'orders' => $orderRows,
            'orders_total' => $orders->total,
            'orders_per_page' => $orders->perPage,
            'orders_current_page' => $orders->currentPage,
            'orders_last_page' => max(1, $orders->lastPage),
        ];
    }
}
