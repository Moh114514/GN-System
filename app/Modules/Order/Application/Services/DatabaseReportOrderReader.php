<?php

namespace App\Modules\Order\Application\Services;

use App\Modules\Auth\Application\Contracts\AccessContextResolver;
use App\Modules\Order\Application\Contracts\ReportOrderReader;
use App\Modules\Order\Infrastructure\Models\Appointment;
use App\Modules\Order\Infrastructure\Models\Order;
use App\Modules\Report\Application\Data\InstitutionMonthlySalesAggregateData;
use App\Modules\Report\Application\Data\ReportOrderData;
use App\Modules\Report\Application\Data\ReportPageData;
use App\Modules\Report\Application\Data\ReportQueryData;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

final class DatabaseReportOrderReader implements ReportOrderReader
{
    public function __construct(private readonly AccessContextResolver $access) {}

    public function paginate(ReportQueryData $query, int $perPage, int $page): ReportPageData
    {
        $started = hrtime(true);
        $paginator = $this->query($query)->paginate($perPage, ['*'], 'page', max(1, $page));
        $items = $paginator->getCollection()
            ->map(fn (Order $order): ReportOrderData => $this->data($order))
            ->all();

        return new ReportPageData(
            items: $items,
            total: $paginator->total(),
            perPage: $paginator->perPage(),
            currentPage: $paginator->currentPage(),
            lastPage: $paginator->lastPage(),
            queryMilliseconds: round((hrtime(true) - $started) / 1_000_000, 2),
        );
    }

    public function count(ReportQueryData $query): int
    {
        return $this->query($query)->count();
    }

    public function rows(ReportQueryData $query): array
    {
        return $this->query($query)->get()
            ->map(fn (Order $order): ReportOrderData => $this->data($order))
            ->all();
    }

    public function completedOrderMonths(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $query = Order::query()
            ->where('status', 'completed')
            ->whereBetween('completed_at', [$from, $to]);
        $this->applyScope($query);

        return $query
            ->get(['id', 'completed_at'])
            ->mapWithKeys(fn (Order $order): array => [
                (int) $order->id => $order->completed_at?->setTimezone('Asia/Shanghai')->format('Y-m') ?? '',
            ])->all();
    }

    public function dashboard(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $base = Order::query()->where('status', 'completed')->whereBetween('completed_at', [$from, $to]);
        $this->applyScope($base);
        $amount = (int) (clone $base)->sum('amount_krw');
        $customerCounts = (clone $base)
            ->select('customer_id', DB::raw('COUNT(*)::int AS order_count'))
            ->groupBy('customer_id')
            ->pluck('order_count');
        $purchasers = $customerCounts->count();
        $repeaters = $customerCounts->filter(fn ($count): bool => (int) $count >= 2)->count();
        $monthly = (clone $base)
            ->selectRaw("TO_CHAR(DATE_TRUNC('month', completed_at), 'YYYY-MM') AS key")
            ->selectRaw('SUM(amount_krw)::bigint AS value')
            ->groupByRaw("DATE_TRUNC('month', completed_at)")
            ->orderByRaw("DATE_TRUNC('month', completed_at)")
            ->get()
            ->map(fn (Order $row): array => [
                'key' => (string) $row->getAttribute('key'),
                'value' => (int) $row->getAttribute('value'),
            ])->all();
        $monthlyOrders = (clone $base)
            ->selectRaw("TO_CHAR(DATE_TRUNC('month', completed_at), 'YYYY-MM') AS key")
            ->selectRaw('COUNT(*)::int AS value')
            ->groupByRaw("DATE_TRUNC('month', completed_at)")
            ->orderByRaw("DATE_TRUNC('month', completed_at)")
            ->get()
            ->map(fn (Order $row): array => [
                'key' => (string) $row->getAttribute('key'),
                'value' => (int) $row->getAttribute('value'),
            ])->all();
        $institutions = (clone $base)
            ->select('institution_id')
            ->selectRaw('SUM(amount_krw)::bigint AS value')
            ->groupBy('institution_id')
            ->orderByDesc('value')
            ->get()
            ->map(fn (Order $row): array => [
                'institution_id' => (int) $row->institution_id,
                'value' => (int) $row->getAttribute('value'),
            ])->all();

        return [
            'completed_amount' => $amount,
            'repurchase_rate' => $purchasers === 0 ? 0.0 : round($repeaters / $purchasers * 100, 2),
            'monthly_consumption' => $monthly,
            'monthly_orders' => $monthlyOrders,
            'institution_revenue' => $institutions,
            'lifecycle' => $this->lifecycle($to),
        ];
    }

    public function teamOverview(array $ownerIds, int $businessGroupId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $context = $this->access->current();
        abort_unless(
            $context->isSuperAdmin()
                || ($context->isBdManager() && in_array($businessGroupId, $context->businessGroupIds, true)),
            403,
        );
        $ownerIds = array_values(array_unique(array_filter(array_map('intval', $ownerIds), fn (int $id): bool => $id > 0)));

        $base = Order::query()
            ->where('status', 'completed')
            ->where('record_status', 'active')
            ->whereNotNull('occurred_on')
            ->whereBetween('occurred_on', [$from->toDateString(), $to->toDateString()])
            ->whereJsonContains('business_attribution_snapshot->business_group->business_group_id', $businessGroupId);
        $ownerRows = $ownerIds === []
            ? collect()
            : (clone $base)->whereIn('owner_id', $ownerIds)
                ->selectRaw('owner_id::int as owner_id, COUNT(*)::int as orders, SUM(amount_krw)::bigint as amount_krw')
                ->groupBy('owner_id')
                ->get();
        $owners = [];
        foreach ($ownerIds as $ownerId) {
            $owners[$ownerId] = ['orders' => 0, 'amount_krw' => 0];
        }
        foreach ($ownerRows as $row) {
            $ownerId = (int) $row->owner_id;
            if (isset($owners[$ownerId])) {
                $owners[$ownerId] = [
                    'orders' => (int) $row->getAttribute('orders'),
                    'amount_krw' => (int) $row->amount_krw,
                ];
            }
        }

        return [
            'orders' => (clone $base)->count(),
            'amount_krw' => (int) (clone $base)->sum('amount_krw'),
            'owners' => $owners,
        ];
    }

    /** @return list<InstitutionMonthlySalesAggregateData> */
    public function institutionMonthlySales(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $query = Order::query()
            ->where('status', 'completed')
            ->where('record_status', 'active')
            ->whereNotNull('occurred_on')
            ->whereBetween('occurred_on', [$from->toDateString(), $to->toDateString()]);
        $this->applyScope($query);

        return $query
            ->select('institution_id')
            ->selectRaw('COUNT(DISTINCT customer_id)::int AS customer_count')
            ->selectRaw('COUNT(*)::int AS order_count')
            ->selectRaw('COALESCE(SUM(amount_krw), 0)::bigint AS amount_krw')
            ->groupBy('institution_id')
            ->get()
            ->map(static fn (Order $row): InstitutionMonthlySalesAggregateData => new InstitutionMonthlySalesAggregateData(
                institutionId: (int) $row->institution_id,
                customerCount: (int) $row->getAttribute('customer_count'),
                orderCount: (int) $row->getAttribute('order_count'),
                amountKrw: (int) $row->getAttribute('amount_krw'),
            ))
            ->values()
            ->all();
    }

    /**
     * @return array{appointed_customers: int, repeat_customers: int}
     */
    private function lifecycle(CarbonImmutable $to): array
    {
        $repeatCustomerQuery = DB::table('orders')
            ->where('status', 'completed')
            ->where('completed_at', '<=', $to)
            ->select('customer_id')
            ->groupBy('customer_id')
            ->havingRaw('COUNT(*) >= 2');
        $this->applyScope($repeatCustomerQuery);
        $repeatCustomers = DB::query()
            ->fromSub($repeatCustomerQuery, 'repeat_customers')
            ->count();

        return [
            'appointed_customers' => $this->scopedAppointments()
                ->whereNotNull('scheduled_at')
                ->where('scheduled_at', '<=', $to)
                ->distinct('customer_id')
                ->count('customer_id'),
            'repeat_customers' => $repeatCustomers,
        ];
    }

    /** @return Builder<Order> */
    private function query(ReportQueryData $filters): Builder
    {
        $query = Order::query()->where('status', 'completed')->whereNotNull('completed_at');
        $this->applyScope($query);
        if ($filters->completedFrom !== null) {
            $query->where('completed_at', '>=', $filters->completedFrom);
        }
        if ($filters->completedTo !== null) {
            $query->where('completed_at', '<=', $filters->completedTo);
        }
        if ($filters->timeFrom !== null && $filters->timeFrom !== ''
            && $filters->timeTo !== null && $filters->timeTo !== ''
            && $filters->timeFrom > $filters->timeTo) {
            $query->where(function ($time) use ($filters): void {
                $time->whereRaw('completed_at::time >= ?', [$filters->timeFrom])
                    ->orWhereRaw('completed_at::time <= ?', [$filters->timeTo]);
            });
        } else {
            if ($filters->timeFrom !== null && $filters->timeFrom !== '') {
                $query->whereRaw('completed_at::time >= ?', [$filters->timeFrom]);
            }
            if ($filters->timeTo !== null && $filters->timeTo !== '') {
                $query->whereRaw('completed_at::time <= ?', [$filters->timeTo]);
            }
        }
        foreach ([
            'customer_id' => $filters->customerId,
            'agent_id' => $filters->agentId,
            'institution_id' => $filters->institutionId,
        ] as $column => $value) {
            if ($value !== null) {
                $query->where($column, $value);
            }
        }
        if ($filters->projectName !== null && $filters->projectName !== '') {
            $query->where('project_name', 'ilike', '%'.$filters->projectName.'%');
        }
        if ($filters->translatorName !== null && $filters->translatorName !== '') {
            $query->where('translator_name', 'ilike', '%'.$filters->translatorName.'%');
        }
        if ($filters->amountMin !== null) {
            $query->where('amount_krw', '>=', $filters->amountMin);
        }
        if ($filters->amountMax !== null) {
            $query->where('amount_krw', '<=', $filters->amountMax);
        }

        $sortColumns = [
            'completed_at' => 'completed_at',
            'customer' => 'customer_id',
            'agent' => 'agent_id',
            'project' => 'project_name',
            'institution' => 'institution_id',
            'amount' => 'amount_krw',
        ];
        $sortField = array_key_exists($filters->sortField, $sortColumns) ? $filters->sortField : 'completed_at';
        $direction = $filters->sortDirection === 'asc' ? 'asc' : 'desc';
        $column = $sortColumns[$sortField];
        if (in_array($sortField, ['customer', 'agent', 'institution'], true)
            && $filters->sortReferenceIds !== []) {
            $query->orderByRaw($this->referenceSortSql($column, $filters->sortReferenceIds, $direction));
        } else {
            $query->orderBy($column, $direction);
        }

        return $query->orderBy('id', $direction);
    }

    /** @param array<int, int> $ids */
    private function referenceSortSql(string $column, array $ids, string $direction): string
    {
        $cases = [];
        foreach (array_values(array_unique(array_map('intval', $ids))) as $rank => $id) {
            $cases[] = "WHEN {$id} THEN {$rank}";
        }

        return 'CASE '.$column.' '.implode(' ', $cases).' ELSE '.count($cases).' END '.$direction;
    }

    private function data(Order $order): ReportOrderData
    {
        return new ReportOrderData(
            id: (int) $order->id,
            customerId: (int) $order->customer_id,
            agentId: $order->agent_id === null ? null : (int) $order->agent_id,
            institutionId: (int) $order->institution_id,
            projectName: (string) ($order->treatment_project_snapshot ?: $order->project_name),
            translatorName: $order->translator_name === null ? null : (string) $order->translator_name,
            amountKrw: (int) $order->amount_krw,
            completedAt: $order->completed_at?->setTimezone('Asia/Shanghai')->format('Y-m-d H:i:s') ?? '',
            completionPrecision: (string) $order->completion_precision,
        );
    }

    /** @param Builder<Order>|QueryBuilder $query */
    private function applyScope(Builder|QueryBuilder $query): void
    {
        $context = $this->access->current();
        if ($context->isSuperAdmin()) {
            return;
        }

        if (! $context->hasEffectiveBusinessScope()) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where(function ($scope) use ($context): void {
            if ($context->userId !== null) {
                $scope->where('owner_id', $context->userId);
            }
            if ($context->agentIds !== []) {
                $scope->orWhereIn('agent_id', $context->agentIds);
            }
        });
    }

    /** @return Builder<Appointment> */
    private function scopedAppointments(): Builder
    {
        $context = $this->access->current();
        $query = Appointment::query();
        if (! $context->isSuperAdmin()) {
            if (! $context->hasEffectiveBusinessScope()) {
                return $query->whereRaw('1 = 0');
            }

            $query->where(function ($scope) use ($context): void {
                if ($context->userId !== null) {
                    $scope->where('owner_id', $context->userId);
                }
                if ($context->agentIds !== []) {
                    $scope->orWhereIn('owner_id', $context->groupUserIds);
                }
            });
        }

        return $query;
    }
}
