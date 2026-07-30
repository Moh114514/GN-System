<?php

namespace App\Modules\Customer\Application\Services;

use App\Modules\Customer\Application\Contracts\ReportCustomerReader;
use App\Modules\Customer\Domain\BlindIndex;
use App\Modules\Customer\Infrastructure\Models\Customer;
use App\Modules\Customer\Infrastructure\Models\CustomerIdentityDocument;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final readonly class DatabaseReportCustomerReader implements ReportCustomerReader
{
    public function __construct(private BlindIndex $blindIndex) {}

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
            ->leftJoin('direct_sales_sources as source', 'source.id', '=', 'customers.source_direct_sales_id')
            ->select([
                'customers.original_channel',
                'customers.source_agent_id',
                'customers.source_direct_sales_id',
                'source.name as direct_source_name',
            ])
            ->selectRaw('COUNT(*)::int as value')
            ->groupBy([
                'customers.original_channel',
                'customers.source_agent_id',
                'customers.source_direct_sales_id',
                'source.name',
            ])
            ->orderBy('customers.original_channel')
            ->get()
            ->map(fn (Customer $row): array => [
                'source_type' => (string) $row->original_channel,
                'source_id' => (int) ($row->source_agent_id ?? $row->source_direct_sales_id),
                'key' => $row->original_channel === 'direct'
                    ? (string) ($row->getAttribute('direct_source_name') ?: '未知直销来源')
                    : '',
                'value' => (int) $row->getAttribute('value'),
            ])
            ->all();

        return [
            'new_customers' => $newCustomers,
            'active_customers' => $activeCustomers,
            'source_distribution' => $sourceDistribution,
        ];
    }
}
