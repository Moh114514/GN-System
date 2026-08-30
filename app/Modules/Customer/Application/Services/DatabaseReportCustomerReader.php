<?php

namespace App\Modules\Customer\Application\Services;

use App\Modules\Auth\Application\Contracts\AccessContextResolver;
use App\Modules\Customer\Application\Contracts\ReportCustomerReader;
use App\Modules\Customer\Domain\BlindIndex;
use App\Modules\Customer\Domain\CustomerLabelLocalizer;
use App\Modules\Customer\Infrastructure\Models\Customer;
use App\Modules\Customer\Infrastructure\Models\CustomerContact;
use App\Modules\Customer\Infrastructure\Models\CustomerIdentityDocument;
use App\Modules\Customer\Infrastructure\Models\CustomerTransferRequest;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final readonly class DatabaseReportCustomerReader implements ReportCustomerReader
{
    public function __construct(
        private BlindIndex $blindIndex,
        private CustomerLabelLocalizer $labels,
        private AccessContextResolver $access,
    ) {}

    public function globalSearch(string $query, int $limit): array
    {
        $query = trim($query);
        if ($query === '') {
            return ['total' => 0, 'items' => []];
        }

        $hash = $this->blindIndex->for($query);
        $contactCustomerIds = $hash === null
            ? []
            : CustomerContact::query()->where('lookup_hash', $hash)->pluck('customer_id')->all();
        $customers = Customer::query()
            ->leftJoin('customer_statuses as status', 'status.id', '=', 'customers.current_status_id')
            ->where(function ($builder) use ($query, $contactCustomerIds): void {
                $builder->where('customers.name', 'ilike', '%'.$query.'%')
                    ->orWhere('customers.code', 'ilike', '%'.strtoupper($query).'%');
                if ($contactCustomerIds !== []) {
                    $builder->orWhereIn('customers.id', $contactCustomerIds);
                }
            });
        $this->applyScope($customers);
        $total = (clone $customers)->count('customers.id');
        $items = $customers
            ->orderBy('customers.name')
            ->orderBy('customers.id')
            ->limit(max(1, $limit))
            ->get(['customers.id', 'customers.code', 'customers.name', 'status.key as status_key', 'status.name as status_name'])
            ->map(fn (Customer $customer): array => [
                'id' => (int) $customer->id,
                'code' => (string) $customer->code,
                'name' => (string) $customer->name,
                'status' => $customer->getAttribute('status_name') === null
                    ? __('customers.fallback.unset')
                    : $this->labels->status((string) $customer->getAttribute('status_key'), (string) $customer->getAttribute('status_name')),
            ])
            ->all();

        return ['total' => $total, 'items' => $items];
    }

    public function customerIdForPassport(string $passport): ?int
    {
        $hash = $this->blindIndex->for($passport);
        if ($hash === null) {
            return null;
        }

        $id = CustomerIdentityDocument::query()
            ->where('type', 'passport_or_residence_card')
            ->where('lookup_hash', $hash)
            ->whereIn('customer_id', $this->scopedCustomerIds())
            ->value('customer_id');

        return $id === null ? null : (int) $id;
    }

    public function namesByIds(array $ids): array
    {
        return $this->scoped(Customer::query())
            ->whereKey(array_values(array_unique($ids)))
            ->pluck('name', 'id')
            ->mapWithKeys(fn ($name, $id): array => [(int) $id => (string) $name])
            ->all();
    }

    public function idsOrderedByName(): array
    {
        return $this->scoped(Customer::query())->orderBy('name')->orderBy('id')->pluck('id')
            ->map(fn ($id): int => (int) $id)->all();
    }

    public function customerOptions(): array
    {
        return $this->scoped(Customer::query())->orderBy('name')->orderBy('id')->get(['id', 'name'])
            ->map(fn (Customer $customer): array => [
                'id' => (int) $customer->id,
                'name' => (string) $customer->name,
            ])->all();
    }

    public function dashboard(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $newCustomersQuery = $this->scoped(Customer::query())->whereBetween('created_at', [$from, $to]);
        $newCustomers = $newCustomersQuery->count();
        $totalCustomers = $this->scoped(Customer::query())->where('created_at', '<=', $to)->count();
        $arrivedCustomers = DB::table('customers')
            ->join('customer_statuses as status', 'status.id', '=', 'customers.current_status_id')
            ->join('customer_lifecycle_stages as stage', 'stage.id', '=', 'status.stage_id')
            ->where('customers.created_at', '<=', $to)
            ->whereIn('status.key', ['arrived', 'treatment_completed'])
            ->whereIn('customers.id', $this->scopedCustomerIds())
            ->distinct('customers.id')
            ->count('customers.id');
        $activeCustomers = $this->scoped(Customer::query())
            ->where('created_at', '<=', $to)
            ->count();
        $statusCounts = DB::table('customers')
            ->leftJoin('customer_statuses as status', 'status.id', '=', 'customers.current_status_id')
            ->where('customers.created_at', '<=', $to)
            ->whereIn('customers.id', $this->scopedCustomerIds())
            ->selectRaw("COALESCE(status.key, 'unset') as status_key, COUNT(*)::int as value")
            ->groupByRaw("COALESCE(status.key, 'unset')")
            ->pluck('value', 'status_key')
            ->map(fn ($value): int => (int) $value)
            ->all();
        $sourceDistribution = $this->scoped(Customer::query())
            ->whereBetween('customers.created_at', [$from, $to])
            ->select([
                'customers.source_agent_id',
            ])
            ->selectRaw('COUNT(*)::int as value')
            ->groupBy(['customers.source_agent_id'])
            ->orderBy('customers.source_agent_id')
            ->get()
            ->map(fn (Customer $row): array => [
                'source_type' => 'agent',
                'source_id' => (int) $row->source_agent_id,
                'key' => '',
                'value' => (int) $row->getAttribute('value'),
            ])
            ->all();
        $recentCustomers = $this->scoped(Customer::query())
            ->where('customers.created_at', '<=', $to)
            ->leftJoin('customer_statuses as status', 'status.id', '=', 'customers.current_status_id')
            ->orderByDesc('customers.created_at')
            ->orderByDesc('customers.id')
            ->limit(5)
            ->get([
                'customers.id',
                'customers.code',
                'customers.name',
                'customers.source_agent_id',
                'customers.owner_id',
                'customers.created_at',
                'status.key as status_key',
                'status.name as status_name',
            ])
            ->map(fn (Customer $customer): array => [
                'id' => (int) $customer->id,
                'code' => (string) $customer->code,
                'name' => (string) $customer->name,
                'source_type' => 'agent',
                'source_id' => (int) $customer->source_agent_id,
                'source_name' => '',
                'status_key' => (string) ($customer->getAttribute('status_key') ?: 'booked'),
                'status_name' => (string) ($customer->getAttribute('status_name') ?? ''),
                'status_translation_key' => $customer->getAttribute('status_name') === null
                    ? 'customers.timeline.booked'
                    : $this->labels->statusTranslationKey(
                        (string) $customer->getAttribute('status_key'),
                        (string) $customer->getAttribute('status_name'),
                    ),
                'owner_id' => (int) ($customer->owner_id ?? 0),
                'created_on' => $customer->created_at?->setTimezone('Asia/Shanghai')->toDateString() ?? '',
            ])
            ->all();

        return [
            'new_customers' => $newCustomers,
            'active_customers' => $activeCustomers,
            'total_customers' => $totalCustomers,
            'arrived_customers' => $arrivedCustomers,
            'status_counts' => $statusCounts,
            'source_distribution' => $sourceDistribution,
            'recent_customers' => $recentCustomers,
        ];
    }

    public function teamOverview(array $ownerIds, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $ownerIds = array_values(array_unique(array_filter(array_map('intval', $ownerIds), fn (int $id): bool => $id > 0)));
        $base = $this->scoped(Customer::query())->where('customers.created_at', '<=', $to);
        $total = (clone $base)->count('customers.id');
        $new = (clone $base)->whereBetween('customers.created_at', [$from, $to])->count('customers.id');
        $unassigned = (clone $base)->whereNull('customers.owner_id')->count('customers.id');
        $owners = [];

        if ($ownerIds !== []) {
            $statusRows = (clone $base)
                ->whereIn('customers.owner_id', $ownerIds)
                ->leftJoin('customer_statuses as status', 'status.id', '=', 'customers.current_status_id')
                ->selectRaw("customers.owner_id::int as owner_id, COALESCE(status.key, 'unset') as status_key, COUNT(*)::int as value")
                ->groupBy('customers.owner_id')
                ->groupByRaw("COALESCE(status.key, 'unset')")
                ->get();
            $newRows = (clone $base)
                ->whereIn('customers.owner_id', $ownerIds)
                ->whereBetween('customers.created_at', [$from, $to])
                ->selectRaw('customers.owner_id::int as owner_id, COUNT(*)::int as value')
                ->groupBy('customers.owner_id')
                ->get();
            $newByOwner = $newRows->mapWithKeys(fn ($row): array => [
                (int) $row->getAttribute('owner_id') => (int) $row->getAttribute('value'),
            ])->all();

            foreach ($ownerIds as $ownerId) {
                $owners[$ownerId] = [
                    'customers' => 0,
                    'new_customers' => $newByOwner[$ownerId] ?? 0,
                    'unset' => 0,
                    'booked' => 0,
                    'arrived' => 0,
                    'treatment_completed' => 0,
                ];
            }
            foreach ($statusRows as $row) {
                $ownerId = (int) $row->owner_id;
                $status = (string) $row->getAttribute('status_key');
                if (! isset($owners[$ownerId])) {
                    continue;
                }
                $owners[$ownerId]['customers'] += (int) $row->getAttribute('value');
                if (array_key_exists($status, $owners[$ownerId])) {
                    $owners[$ownerId][$status] = (int) $row->getAttribute('value');
                }
            }
        }

        $pendingTransfers = $ownerIds === []
            ? 0
            : CustomerTransferRequest::query()->where('status', 'pending')->whereIn('to_owner_id', $ownerIds)->count();

        return [
            'total_customers' => $total,
            'new_customers' => $new,
            'unassigned_customers' => $unassigned,
            'pending_transfer_requests' => $pendingTransfers,
            'owners' => $owners,
        ];
    }

    /**
     * @param  Builder<Customer>  $query
     * @return Builder<Customer>
     */
    private function scoped(Builder $query): Builder
    {
        $this->applyScope($query);

        return $query;
    }

    /** @param Builder<Customer>|\Illuminate\Database\Query\Builder $query */
    private function applyScope($query): void
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
                $scope->where('customers.owner_id', $context->userId);
            }
            if ($context->agentIds !== []) {
                $scope->orWhereIn('customers.source_agent_id', $context->agentIds);
            }
        });
    }

    /** @return list<int> */
    private function scopedCustomerIds(): array
    {
        $query = $this->scoped(Customer::query());

        return $query->pluck('customers.id')->map(fn ($id): int => (int) $id)->all();
    }
}
