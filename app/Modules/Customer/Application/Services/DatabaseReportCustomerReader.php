<?php

namespace App\Modules\Customer\Application\Services;

use App\Modules\Customer\Application\Contracts\ReportCustomerReader;
use App\Modules\Customer\Domain\BlindIndex;
use App\Modules\Customer\Domain\CustomerLabelLocalizer;
use App\Modules\Customer\Infrastructure\Models\Customer;
use App\Modules\Customer\Infrastructure\Models\CustomerContact;
use App\Modules\Customer\Infrastructure\Models\CustomerIdentityDocument;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final readonly class DatabaseReportCustomerReader implements ReportCustomerReader
{
    public function __construct(private BlindIndex $blindIndex, private CustomerLabelLocalizer $labels) {}

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
            ->value('customer_id');

        return $id === null ? null : (int) $id;
    }

    public function namesByIds(array $ids): array
    {
        return Customer::query()
            ->whereKey(array_values(array_unique($ids)))
            ->pluck('name', 'id')
            ->mapWithKeys(fn ($name, $id): array => [(int) $id => (string) $name])
            ->all();
    }

    public function idsOrderedByName(): array
    {
        return Customer::query()->orderBy('name')->orderBy('id')->pluck('id')
            ->map(fn ($id): int => (int) $id)->all();
    }

    public function customerOptions(): array
    {
        return Customer::query()->orderBy('name')->orderBy('id')->get(['id', 'name'])
            ->map(fn (Customer $customer): array => [
                'id' => (int) $customer->id,
                'name' => (string) $customer->name,
            ])->all();
    }

    public function dashboard(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $newCustomers = Customer::query()->whereBetween('created_at', [$from, $to])->count();
        $totalCustomers = Customer::query()->where('created_at', '<=', $to)->count();
        $arrivedCustomers = DB::table('customers')
            ->join('customer_statuses as status', 'status.id', '=', 'customers.current_status_id')
            ->join('customer_lifecycle_stages as stage', 'stage.id', '=', 'status.stage_id')
            ->where('customers.created_at', '<=', $to)
            ->where('stage.sort_order', '>=', 30)
            ->distinct('customers.id')
            ->count('customers.id');
        $lostStatusId = DB::table('customer_statuses')->where('key', 'lost')->value('id');
        $activeCustomers = Customer::query()
            ->where('created_at', '<=', $to)
            ->when($lostStatusId !== null, fn ($query) => $query->whereRaw(
                'COALESCE((
                    SELECT history.to_status_id
                    FROM customer_status_histories history
                    WHERE history.customer_id = customers.id
                      AND history.changed_at <= ?
                    ORDER BY history.changed_at DESC, history.id DESC
                    LIMIT 1
                ), customers.current_status_id) <> ?',
                [$to, $lostStatusId],
            ))
            ->count();
        $sourceDistribution = Customer::query()
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
        $recentCustomers = Customer::query()
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
                'status_key' => (string) ($customer->getAttribute('status_key') ?: 'registered'),
                'status_name' => (string) ($customer->getAttribute('status_name') ?? ''),
                'status_translation_key' => $customer->getAttribute('status_name') === null
                    ? 'customers.timeline.registered'
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
            'source_distribution' => $sourceDistribution,
            'recent_customers' => $recentCustomers,
        ];
    }
}
