<?php

namespace App\Modules\Customer\Application\Services;

use App\Modules\Customer\Application\Contracts\CustomerOrderReferenceReader;
use App\Modules\Customer\Infrastructure\Models\Customer;
use App\Modules\Customer\Infrastructure\Models\DirectSalesSource;

final class DatabaseCustomerOrderReferenceReader implements CustomerOrderReferenceReader
{
    public function customerForOrder(int $customerId): array
    {
        $customer = Customer::query()->findOrFail($customerId);

        return [
            'id' => (int) $customer->id,
            'code' => (string) $customer->code,
            'name' => (string) $customer->name,
            'original_channel' => (string) $customer->original_channel,
            'source_agent_id' => $customer->source_agent_id === null ? null : (int) $customer->source_agent_id,
            'source_direct_sales_id' => $customer->source_direct_sales_id === null ? null : (int) $customer->source_direct_sales_id,
        ];
    }

    public function activeDirectSalesSources(): array
    {
        return DirectSalesSource::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'code', 'name'])
            ->map(fn (DirectSalesSource $source): array => [
                'id' => (int) $source->id,
                'code' => (string) $source->code,
                'name' => (string) $source->name,
            ])
            ->all();
    }
}
