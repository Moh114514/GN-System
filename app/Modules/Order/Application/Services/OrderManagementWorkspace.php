<?php

namespace App\Modules\Order\Application\Services;

use App\Modules\Agent\Application\Contracts\AgentReferenceReader;
use App\Modules\Config\Application\Contracts\InstitutionReferenceReader;
use App\Modules\Config\Application\Contracts\OrderDictionaryReader;
use App\Modules\Customer\Application\Contracts\CustomerOrderReferenceReader;
use App\Modules\Order\Infrastructure\Models\Order;
use Illuminate\Pagination\LengthAwarePaginator;

final readonly class OrderManagementWorkspace
{
    public function __construct(
        private CustomerOrderReferenceReader $customers,
        private AgentReferenceReader $agents,
        private InstitutionReferenceReader $institutions,
        private OrderDictionaryReader $dictionary,
    ) {}

    /** @return array<string, array<int, array<string, mixed>>> */
    public function options(): array
    {
        return [
            'agents' => array_values($this->agents->activeAgents()),
            'direct_sources' => $this->customers->activeDirectSalesSources(),
            'institutions' => array_values($this->institutions->activeInstitutions()),
            'treatment_projects' => $this->dictionary->activeItems('treatment_project'),
            'translator_languages' => $this->dictionary->activeItems('translator_language'),
        ];
    }

    /** @return array{id: int, code: string, name: string, original_channel: string, source_agent_id: int|null, source_direct_sales_id: int|null} */
    public function customer(int $customerId): array
    {
        return $this->customers->customerForOrder($customerId);
    }

    /** @return array<int, array{id: int, code: string, name: string, original_channel: string, source_agent_id: int|null, source_direct_sales_id: int|null}> */
    public function customerCandidates(string $search): array
    {
        return $this->customers->searchCustomersForOrder($search);
    }

    /**
     * @param  array{search?: string, status?: string, channel?: string, institution_id?: int|null, agent_id?: int|null}  $filters
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function paginate(array $filters, int $perPage): LengthAwarePaginator
    {
        $query = Order::query();
        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $customerIds = $this->customers->customerIdsForOrderSearch($search);
            $query->where(function ($inner) use ($search, $customerIds): void {
                $inner->where('project_name', 'ilike', '%'.$search.'%')
                    ->orWhere('id', ctype_digit($search) ? (int) $search : 0);
                if ($customerIds !== []) {
                    $inner->orWhereIn('customer_id', $customerIds);
                }
            });
        }
        if (($filters['status'] ?? '') !== '') {
            $query->where('status', $filters['status']);
        }
        if (($filters['channel'] ?? '') !== '') {
            $query->where('channel', $filters['channel']);
        }
        if (($filters['institution_id'] ?? null) !== null) {
            $query->where('institution_id', $filters['institution_id']);
        }
        if (($filters['agent_id'] ?? null) !== null) {
            $query->where('agent_id', $filters['agent_id']);
        }

        $page = $query->latest('id')->paginate($perPage);
        $orders = $page->getCollection();
        $customerLabels = $this->customers->customersForOrders(
            $orders->pluck('customer_id')->map(fn ($id): int => (int) $id)->all(),
        );
        $agentLabels = $this->agents->agentsByIds(
            $orders->pluck('agent_id')->filter()->map(fn ($id): int => (int) $id)->all(),
        );
        $directLabels = $this->customers->directSalesSourcesByIds(
            $orders->pluck('direct_sales_source_id')->filter()->map(fn ($id): int => (int) $id)->all(),
        );
        $institutionLabels = $this->institutions->institutionsByIds(
            $orders->pluck('institution_id')->map(fn ($id): int => (int) $id)->all(),
        );

        $items = $orders->map(function (Order $order) use ($customerLabels, $agentLabels, $directLabels, $institutionLabels): array {
            $customer = $customerLabels[(int) $order->customer_id] ?? null;

            return [
                'id' => (int) $order->id,
                'customer_id' => (int) $order->customer_id,
                'customer_name' => (string) ($customer['name'] ?? '未知客户'),
                'customer_code' => (string) ($customer['code'] ?? '—'),
                'institution' => (string) ($institutionLabels[(int) $order->institution_id]['name'] ?? '未知机构'),
                'channel' => (string) $order->channel,
                'source' => $order->channel === 'agent'
                    ? (string) ($agentLabels[(int) $order->agent_id]['name'] ?? '未知代理商')
                    : (string) ($directLabels[(int) $order->direct_sales_source_id]['name'] ?? '未知直销来源'),
                'project_name' => (string) $order->project_name,
                'amount_krw' => (int) $order->amount_krw,
                'status' => (string) $order->status,
                'completed_at' => $order->completed_at?->format('Y-m-d H:i'),
                'created_at' => $order->created_at?->format('Y-m-d H:i'),
            ];
        });

        return new LengthAwarePaginator(
            $items,
            $page->total(),
            $page->perPage(),
            $page->currentPage(),
            ['path' => request()->url(), 'query' => request()->query()],
        );
    }
}
