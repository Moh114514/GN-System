<?php

namespace App\Modules\Report\Application\Services;

use App\Infrastructure\Time\BusinessClock;
use App\Modules\Auth\Application\Contracts\AccessContextResolver;
use App\Modules\Config\Application\Contracts\ReportConfigReader;
use App\Modules\Order\Application\Contracts\ReportOrderReader;
use App\Modules\Report\Application\Data\InstitutionMonthlySalesRowData;
use App\Modules\Report\Application\Data\InstitutionMonthlySalesSummaryData;
use Carbon\CarbonImmutable;
use DomainException;

final readonly class InstitutionMonthlySalesService
{
    public function __construct(
        private ReportOrderReader $orders,
        private ReportConfigReader $config,
        private BusinessClock $clock,
        private AccessContextResolver $access,
    ) {}

    public function currentMonth(): string
    {
        return $this->clock->now()->format('Y-m');
    }

    public function normalizeMonth(string $month): string
    {
        $month = trim($month);
        if (preg_match('/^(\d{4})-(\d{2})$/D', $month, $parts) !== 1
            || ! checkdate((int) $parts[2], 1, (int) $parts[1])) {
            throw new DomainException(__('institution_sales.errors.invalid_month'));
        }

        return $month;
    }

    public function summary(string $month, ?int $institutionId = null): InstitutionMonthlySalesSummaryData
    {
        $month = $this->normalizeMonth($month);
        $from = CarbonImmutable::createFromFormat('!Y-m-d', $month.'-01', (string) config('app.timezone'));
        $to = $from->endOfMonth();
        $allowedInstitutionIds = array_column($this->institutionOptions(), 'id');
        if ($institutionId !== null && ! in_array($institutionId, $allowedInstitutionIds, true)) {
            throw new DomainException(__('institution_sales.errors.institution_unavailable'));
        }
        $aggregates = $this->orders->institutionMonthlySales($from, $to, $institutionId);
        $names = $this->config->institutionNamesByIds(array_map(
            static fn ($row): int => $row->institutionId,
            $aggregates,
        ));
        $rows = [];
        foreach ($aggregates as $aggregate) {
            $name = $names[$aggregate->institutionId] ?? __('institution_sales.fallbacks.missing_institution');
            $rows[] = new InstitutionMonthlySalesRowData(
                institutionId: $aggregate->institutionId,
                institutionName: $name,
                customerCount: $aggregate->customerCount,
                orderCount: $aggregate->orderCount,
                amountKrw: $aggregate->amountKrw,
            );
        }
        usort($rows, static function (InstitutionMonthlySalesRowData $left, InstitutionMonthlySalesRowData $right): int {
            return [mb_strtolower($left->institutionName), $left->institutionId]
                <=> [mb_strtolower($right->institutionName), $right->institutionId];
        });

        return new InstitutionMonthlySalesSummaryData(
            month: $month,
            from: $from,
            to: $to,
            rows: $rows,
            totalCustomers: array_sum(array_map(static fn (InstitutionMonthlySalesRowData $row): int => $row->customerCount, $rows)),
            totalOrders: array_sum(array_map(static fn (InstitutionMonthlySalesRowData $row): int => $row->orderCount, $rows)),
            totalAmountKrw: array_sum(array_map(static fn (InstitutionMonthlySalesRowData $row): int => $row->amountKrw, $rows)),
        );
    }

    /** @return list<array{id: int, name: string}> */
    public function institutionOptions(): array
    {
        $institutions = $this->config->activeInstitutions();
        if ($this->access->current()->isSuperAdmin()) {
            return $institutions;
        }

        $visibleIds = $this->orders->visibleInstitutionIds();

        return array_values(array_filter(
            $institutions,
            static fn (array $institution): bool => in_array($institution['id'], $visibleIds, true),
        ));
    }
}
