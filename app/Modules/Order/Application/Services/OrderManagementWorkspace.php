<?php

namespace App\Modules\Order\Application\Services;

use App\Modules\Agent\Application\Contracts\AgentReferenceReader;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Auth\Application\Contracts\AccessContextResolver;
use App\Modules\Config\Application\Contracts\InstitutionReferenceReader;
use App\Modules\Config\Application\Contracts\OrderDictionaryReader;
use App\Modules\Customer\Application\Contracts\CustomerOrderReferenceReader;
use App\Modules\Order\Application\Contracts\OrderLifecycleGateway;
use App\Modules\Order\Application\Data\OrderUpdateData;
use App\Modules\Order\Infrastructure\Models\Order;
use App\Modules\Reminder\Application\Contracts\OrderReminderReader;
use App\Modules\Settlement\Application\Contracts\OrderFinancialReader;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

final readonly class OrderManagementWorkspace
{
    public function __construct(
        private CustomerOrderReferenceReader $customers,
        private AgentReferenceReader $agents,
        private InstitutionReferenceReader $institutions,
        private OrderDictionaryReader $dictionary,
        private OrderLifecycleGateway $lifecycle,
        private AuditRecorder $audit,
        private OrderReminderReader $reminders,
        private OrderFinancialReader $financials,
        private AccessContextResolver $access,
    ) {}

    /** @return array<string, array<int, array<string, mixed>>> */
    public function options(): array
    {
        return [
            'agents' => array_values($this->agents->activeAgents()),
            'institutions' => array_values($this->institutions->activeInstitutions()),
            'treatment_projects' => $this->dictionary->activeItems('treatment_project'),
            'translator_languages' => $this->dictionary->activeItems('translator_language'),
        ];
    }

    /** @return array{id: int, code: string, name: string, source_agent_id: int, owner_id: int|null} */
    public function customer(int $customerId): array
    {
        return $this->customers->customerForOrder($customerId);
    }

    /** @return array<int, array{id: int, code: string, name: string, source_agent_id: int, owner_id: int|null}> */
    public function customerCandidates(string $search): array
    {
        return $this->customers->searchCustomersForOrder($search);
    }

    /**
     * @param  array{search?: string, status?: string, institution_id?: int|null, agent_id?: int|null}  $filters
     * @return LengthAwarePaginator<int, array{
     *     id: int,
     *     customer_id: int,
     *     customer_name: string,
     *     customer_code: string,
     *     institution: string,
     *     source: string,
     *     project_name: string,
     *     amount_krw: int,
     *     status: string,
     *     occurred_on: string|null,
     *     completed_at: string|null,
     *     created_at: string|null
     * }>
     */
    public function paginate(array $filters, int $perPage, bool $includeDeleted = false, bool $canViewDeleted = false): LengthAwarePaginator
    {
        $this->assertCanViewDeleted($includeDeleted, $canViewDeleted);
        $query = $includeDeleted ? Order::onlyTrashed() : Order::query();
        $this->applyScope($query);
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
        $institutionLabels = $this->institutions->institutionsByIds(
            $orders->pluck('institution_id')->map(fn ($id): int => (int) $id)->all(),
        );

        $items = $orders->map(function (Order $order) use ($customerLabels, $agentLabels, $institutionLabels): array {
            $customer = $customerLabels[(int) $order->customer_id] ?? null;

            return [
                'id' => (int) $order->id,
                'customer_id' => (int) $order->customer_id,
                'customer_name' => (string) ($customer['name'] ?? __('orders.values.unknown_customer')),
                'customer_code' => (string) ($customer['code'] ?? __('orders.values.empty')),
                'institution' => (string) ($institutionLabels[(int) $order->institution_id]['name'] ?? __('orders.values.unknown_institution')),
                'source' => (string) ($agentLabels[(int) $order->agent_id]['name'] ?? __('orders.values.unknown_agent')),
                'project_name' => (string) $order->project_name,
                'amount_krw' => (int) $order->amount_krw,
                'status' => (string) $order->status,
                'occurred_on' => $order->occurred_on?->format('Y-m-d'),
                'completed_at' => $order->completed_at?->format('Y-m-d H:i'),
                'created_at' => $order->created_at?->format('Y-m-d H:i'),
            ];
        });

        // PHPStan treats the inferred array shape as invariant even though it matches the declared shape.
        // @phpstan-ignore return.type
        return new LengthAwarePaginator(
            $items,
            $page->total(),
            $page->perPage(),
            $page->currentPage(),
            ['path' => request()->url(), 'query' => request()->query()],
        );
    }

    /** @return array<string, mixed> */
    public function detail(int $orderId, bool $canViewDeleted = false): array
    {
        $query = $canViewDeleted ? Order::withTrashed() : Order::query();
        $this->applyScope($query);
        $order = $query->findOrFail($orderId);
        $customer = $this->customers->customerForOrder((int) $order->customer_id);
        $institution = $this->institutions->institutionsByIds([(int) $order->institution_id])[(int) $order->institution_id] ?? null;
        $agent = $order->agent_id === null ? null : ($this->agents->agentsByIds([(int) $order->agent_id])[(int) $order->agent_id] ?? null);

        return [
            'id' => (int) $order->id,
            'customer' => $customer,
            'institution' => $institution,
            'agent' => $agent,
            'project_name' => (string) $order->project_name,
            'amount_krw' => (int) $order->amount_krw,
            'status' => (string) $order->status,
            'occurred_on' => $order->occurred_on?->format('Y-m-d'),
            'completed_at' => $order->completed_at?->format('Y-m-d H:i'),
            'created_at' => $order->created_at?->format('Y-m-d H:i'),
            'updated_at' => $order->updated_at === null ? null : (string) $order->updated_at,
            'cancelled_at' => $order->cancelled_at === null ? null : (string) $order->cancelled_at,
            'cancellation_reason' => $order->cancellation_reason,
            'deleted_at' => $order->deleted_at === null ? null : (string) $order->deleted_at,
            'deletion_reason' => $order->deletion_reason,
            'translator_name' => $order->translator_name,
            'translator_language' => $order->translator_language_snapshot,
            'translator_language_id' => $order->translator_language_id,
            'treatment_project_id' => $order->treatment_project_id,
            'notes' => $order->notes,
            'financial' => $this->financials->forOrder((int) $order->id),
            'reminders' => $this->reminders->forOrder((int) $order->id),
            'audit' => array_map(fn ($entry): array => [
                'description' => $entry->description,
                'event' => $entry->event,
                'properties' => $entry->properties,
                'causer_id' => $entry->causerId,
                'occurred_at' => $entry->occurredAt->format('Y-m-d H:i'),
            ], $this->audit->trail($order, 'order')),
        ];
    }

    public function updatePending(OrderUpdateData $data, int $actorId, ?string $ipAddress): int
    {
        $this->assertVisible($data->orderId);
        $context = $this->access->current();
        if (! $context->isSuperAdmin()
            && (! $context->isCustomerService() || $context->agentIds !== [])
            && ! $context->canViewAgent($data->agentId)) {
            abort(404);
        }
        $project = $data->treatmentProjectId === null
            ? null
            : $this->dictionary->activeItem($data->treatmentProjectId, 'treatment_project');
        $language = $data->translatorLanguageId === null
            ? null
            : $this->dictionary->activeItem($data->translatorLanguageId, 'translator_language');

        return $this->lifecycle->updatePending(new OrderUpdateData(
            orderId: $data->orderId,
            institutionId: $data->institutionId,
            agentId: $data->agentId,
            projectName: $project['name'] ?? $data->projectName,
            amountKrw: $data->amountKrw,
            translatorName: $data->translatorName,
            notes: $data->notes,
            treatmentProjectId: $project['id'] ?? null,
            translatorLanguageId: $language['id'] ?? null,
            translatorLanguageName: $language['name'] ?? null,
        ), $actorId, $ipAddress);
    }

    public function cancel(int $orderId, int $actorId, string $reason, ?string $ipAddress): int
    {
        $this->assertVisible($orderId);

        return $this->lifecycle->cancel($orderId, $actorId, $reason, $ipAddress);
    }

    public function reopen(int $orderId, int $actorId, string $reason, ?string $ipAddress): int
    {
        $this->assertVisible($orderId, true);

        return $this->lifecycle->reopen($orderId, $actorId, $reason, $ipAddress);
    }

    public function rollbackCompleted(int $orderId, int $actorId, string $reason, ?string $ipAddress): int
    {
        $this->assertVisible($orderId);

        return $this->lifecycle->rollbackCompleted($orderId, $actorId, $reason, $ipAddress);
    }

    public function softDelete(int $orderId, int $actorId, string $reason, ?string $ipAddress): int
    {
        $this->assertVisible($orderId);

        return $this->lifecycle->softDelete($orderId, $actorId, $reason, $ipAddress);
    }

    public function restore(int $orderId, int $actorId, ?string $ipAddress): int
    {
        $this->assertVisible($orderId, true);

        return $this->lifecycle->restore($orderId, $actorId, $ipAddress);
    }

    private function assertCanViewDeleted(bool $includeDeleted, bool $canViewDeleted): void
    {
        if ($includeDeleted && ! $canViewDeleted) {
            throw new AuthorizationException(__('orders.errors.recycle_bin_admin_only'));
        }
    }

    private function assertVisible(int $orderId, bool $withTrashed = false): void
    {
        $query = $withTrashed ? Order::withTrashed() : Order::query();
        $this->applyScope($query);
        $query->findOrFail($orderId);
    }

    /** @param Builder<Order> $query */
    private function applyScope(Builder $query): void
    {
        $context = $this->access->current();
        if ($context->isSuperAdmin()) {
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
}
