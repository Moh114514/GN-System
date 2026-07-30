<?php

namespace App\Modules\Report\Application\Services;

use App\Modules\Agent\Application\Contracts\ReportAgentReader;
use App\Modules\Config\Application\Contracts\ReportConfigReader;
use App\Modules\Customer\Application\Contracts\ReportCustomerReader;
use App\Modules\Order\Application\Contracts\ReportOrderReader;
use App\Modules\Report\Application\Data\ReportPageData;
use App\Modules\Report\Application\Data\ReportQueryData;
use Carbon\CarbonImmutable;

final readonly class ReportSearch
{
    public function __construct(
        private ReportOrderReader $orders,
        private ReportCustomerReader $customers,
        private ReportAgentReader $agents,
        private ReportConfigReader $config,
    ) {}

    /** @return array<string, array<int, array{id: int, name: string}>> */
    public function options(): array
    {
        return [
            'customers' => $this->customers->customerOptions(),
            'agents' => $this->agents->activeAgents(),
            'institutions' => $this->config->activeInstitutions(),
        ];
    }

    public function defaultPerPage(): int
    {
        return $this->config->integerParameter('report_default_per_page', 50);
    }

    /**
     * @param  array<string, int|string|null>  $criteria
     * @return array{page: ReportPageData, rows: array<int, array<string, int|string|null>>}
     */
    public function paginate(array $criteria, int $perPage, int $page): array
    {
        $result = $this->orders->paginate($this->queryData($criteria), $perPage, $page);

        return ['page' => $result, 'rows' => $this->decorate($result->items)];
    }

    /**
     * @param  array<string, int|string|null>  $criteria
     * @return array<int, array<string, int|string|null>>
     */
    public function rows(array $criteria): array
    {
        return $this->decorate($this->orders->rows($this->queryData($criteria)));
    }

    /** @param array<string, int|string|null> $criteria */
    public function queryData(array $criteria): ReportQueryData
    {
        $passport = trim((string) ($criteria['passport'] ?? ''));
        $customerId = $this->nullableInt($criteria['customer_id'] ?? null);
        if ($passport !== '') {
            $customerId = $this->customers->customerIdForPassport($passport) ?? 0;
        }
        $sortField = in_array(
            $criteria['sort_field'] ?? null,
            ['completed_at', 'customer', 'agent', 'project', 'institution', 'amount'],
            true,
        ) ? (string) $criteria['sort_field'] : 'completed_at';
        $sortIds = match ($sortField) {
            'customer' => $this->customers->idsOrderedByName(),
            'agent' => $this->agents->idsOrderedByName(),
            'institution' => $this->config->institutionIdsOrderedByName(),
            default => [],
        };

        return new ReportQueryData(
            completedFrom: $this->localDate($criteria['completed_from'] ?? null, false),
            completedTo: $this->localDate($criteria['completed_to'] ?? null, true),
            timeFrom: $this->nullableString($criteria['time_from'] ?? null),
            timeTo: $this->nullableString($criteria['time_to'] ?? null),
            customerId: $customerId,
            agentId: $this->nullableInt($criteria['agent_id'] ?? null),
            institutionId: $this->nullableInt($criteria['institution_id'] ?? null),
            projectName: $this->nullableString($criteria['project_name'] ?? null),
            translatorName: $this->nullableString($criteria['translator_name'] ?? null),
            amountMin: $this->nullableInt($criteria['amount_min'] ?? null),
            amountMax: $this->nullableInt($criteria['amount_max'] ?? null),
            sortField: $sortField,
            sortDirection: ($criteria['sort_direction'] ?? null) === 'asc' ? 'asc' : 'desc',
            sortReferenceIds: $sortIds,
        );
    }

    /**
     * @param  array<int, \App\Modules\Report\Application\Data\ReportOrderData>  $orders
     * @return array<int, array<string, int|string|null>>
     */
    private function decorate(array $orders): array
    {
        $customerNames = $this->customers->namesByIds(array_map(fn ($row): int => $row->customerId, $orders));
        $agentNames = $this->agents->namesByIds(array_values(array_filter(
            array_map(fn ($row): ?int => $row->agentId, $orders),
            fn (?int $id): bool => $id !== null,
        )));
        $institutionNames = $this->config->institutionNamesByIds(array_map(fn ($row): int => $row->institutionId, $orders));

        return array_map(fn ($order): array => [
            'id' => $order->id,
            'customer_id' => $order->customerId,
            'customer' => $customerNames[$order->customerId] ?? '未知客户',
            'agent' => $order->agentId === null ? '直销' : ($agentNames[$order->agentId] ?? '未知代理商'),
            'project' => $order->projectName,
            'institution' => $institutionNames[$order->institutionId] ?? '未知机构',
            'translator' => $order->translatorName,
            'amount_krw' => $order->amountKrw,
            'completed_at' => $order->completedAt,
            'completion_precision' => $order->completionPrecision,
        ], $orders);
    }

    private function nullableInt(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function localDate(mixed $value, bool $endOfDay): ?CarbonImmutable
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        $date = CarbonImmutable::parse($value, 'Asia/Shanghai');

        return $endOfDay ? $date->endOfDay() : $date->startOfDay();
    }
}
