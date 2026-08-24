<?php

namespace App\Modules\Order\Application\Services;

use App\Modules\Agent\Application\Contracts\AgentBusinessAttributionReader;
use App\Modules\Agent\Application\Contracts\AgentReferenceReader;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Auth\Application\Contracts\AccessContextResolver;
use App\Modules\Config\Application\Contracts\InstitutionReferenceReader;
use App\Modules\Order\Application\Contracts\OrderLifecycleGateway;
use App\Modules\Order\Application\Data\OrderUpdateData;
use App\Modules\Order\Infrastructure\Models\Order;
use App\Modules\Order\Infrastructure\Models\OrderItem;
use App\Modules\Reminder\Application\Contracts\TreatmentReminderGateway;
use App\Modules\Settlement\Application\Contracts\DailyCommissionGateway;
use App\Modules\Settlement\Application\Data\CompletedOrderCommissionData;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

final readonly class DatabaseOrderLifecycleGateway implements OrderLifecycleGateway
{
    public function __construct(
        private AgentReferenceReader $agents,
        private InstitutionReferenceReader $institutions,
        private DailyCommissionGateway $commissions,
        private TreatmentReminderGateway $reminders,
        private AuditRecorder $audit,
        private AccessContextResolver $access,
        private AgentBusinessAttributionReader $attributions,
    ) {}

    public function updatePending(OrderUpdateData $data, int $actorId, ?string $ipAddress): int
    {
        return DB::transaction(function () use ($data, $actorId, $ipAddress): int {
            $order = Order::query()->lockForUpdate()->findOrFail($data->orderId);
            $this->assertVisible($order);
            $context = $this->access->current();
            if (! $context->isSuperAdmin() && ! $context->isBdManager()) {
                throw new DomainException(__('orders.errors.bd_order_edit_only'));
            }
            if ($context->isBdManager() && ! $context->canViewAgent((int) $order->agent_id)) {
                throw new DomainException(__('orders.errors.bd_order_edit_only'));
            }
            if (! in_array($order->status, ['pending', 'completed'], true) || $order->trashed() || $order->record_status !== 'active') {
                throw new DomainException(__('orders.errors.only_editable_order'));
            }
            if ($data->expectedUpdatedAt === null || $order->updated_at === null
                || CarbonImmutable::parse($data->expectedUpdatedAt)->format('Y-m-d H:i:s.u')
                    !== CarbonImmutable::parse($order->updated_at)->format('Y-m-d H:i:s.u')) {
                throw new DomainException(__('orders.errors.optimistic_lock'));
            }
            if (trim((string) $data->reason) === '') {
                throw new DomainException(__('orders.errors.order_edit_reason_required'));
            }
            if ((int) $order->institution_id !== $data->institutionId || (int) $order->agent_id !== $data->agentId) {
                throw new DomainException(__('orders.errors.immutable_order_reference'));
            }
            $this->assertEditableReferences($data);

            $occurredOn = $data->occurredOn ?? ($order->occurred_on === null ? null : CarbonImmutable::parse($order->occurred_on));
            if ($order->status === 'completed' && $occurredOn === null) {
                throw new DomainException(__('orders.errors.occurred_on_required'));
            }
            $items = $this->normalizeItems($data);
            $totalAmount = array_sum(array_column($items, 'amount_krw'));
            if ($totalAmount !== $data->amountKrw) {
                throw new DomainException(__('orders.errors.order_items_amount_mismatch'));
            }

            if ($order->status === 'completed') {
                $this->commissions->rollbackForOrder((int) $order->id);
            }

            $beforeItems = $order->items()->orderBy('id')->get()->toArray();
            $before = $order->only([
                'customer_id', 'institution_id', 'agent_id', 'source_return_file_id', 'occurred_on',
                'amount_krw', 'treatment_project_id', 'treatment_project_snapshot',
                'translator_name', 'translator_language_id', 'translator_language_snapshot', 'notes',
                'business_attribution_snapshot',
            ]);
            $projectName = (string) ($items[0]['project_name'] ?? trim($data->projectName));
            $projectSnapshot = $projectName;
            if ($data->treatmentProjectId !== null && (int) $order->treatment_project_id === $data->treatmentProjectId) {
                $projectName = (string) $order->project_name;
                $projectSnapshot = (string) ($order->treatment_project_snapshot ?: $order->project_name);
            }
            $languageSnapshot = $data->translatorLanguageId === null
                ? null
                : $data->translatorLanguageName;
            if ($data->translatorLanguageId !== null && (int) $order->translator_language_id === $data->translatorLanguageId) {
                $languageSnapshot = $order->translator_language_snapshot;
            }
            $order->update([
                'project_name' => $projectName,
                'amount_krw' => $data->amountKrw,
                'treatment_project_snapshot' => $projectSnapshot,
                'treatment_project_id' => $data->treatmentProjectId,
                'translator_language_id' => $data->translatorLanguageId,
                'translator_language_snapshot' => $languageSnapshot,
                'translator_name' => $data->translatorName,
                'notes' => $data->notes,
                'occurred_on' => $occurredOn,
                'completed_on' => $order->status === 'completed' ? $occurredOn : $order->completed_on,
                'completed_at' => $order->status === 'completed'
                    ? ($order->completed_at?->setDate($occurredOn->year, $occurredOn->month, $occurredOn->day) ?? $occurredOn->startOfDay())
                    : $order->completed_at,
                'business_attribution_snapshot' => $occurredOn === null
                    ? $order->business_attribution_snapshot
                    : [
                        ...((array) $order->business_attribution_snapshot),
                        'business_group' => $this->attributions->forAgentOnDate($data->agentId, $occurredOn),
                        'occurred_on' => $occurredOn->toDateString(),
                    ],
            ]);
            $order->items()->delete();
            foreach ($items as $index => $item) {
                OrderItem::query()->create([
                    'order_id' => $order->id,
                    'treatment_project_id' => $index === 0 ? $data->treatmentProjectId : null,
                    'project_snapshot' => $item['project_name'],
                    'specification' => $item['specification'],
                    'quantity' => $item['quantity'],
                    'unit_price_krw' => $item['unit_price_krw'],
                    'amount_krw' => $item['amount_krw'],
                    'notes' => $item['notes'],
                ]);
            }

            if ($order->status === 'completed') {
                $this->commissions->recordForCompletedOrder(new CompletedOrderCommissionData(
                    orderId: (int) $order->id,
                    agentId: (int) $order->agent_id,
                    institutionId: (int) $order->institution_id,
                    orderAmountKrw: (int) $order->amount_krw,
                    completedOn: $occurredOn,
                    actorId: $actorId,
                    ipAddress: $ipAddress,
                ));
            }

            $after = [
                ...$order->only(array_keys($before)),
                'items' => $items,
            ];
            $this->audit->record(
                description: __('orders.audit.updated'),
                properties: ['before' => [...$before, 'items' => $beforeItems], 'after' => $after, 'reason' => trim((string) $data->reason)],
                causerId: $actorId,
                subject: $order,
                logName: 'order',
                event: 'updated',
                ipAddress: $ipAddress,
                messageKey: 'orders.audit.updated',
            );

            return (int) $order->id;
        });
    }

    public function cancel(int $orderId, int $actorId, string $reason, ?string $ipAddress): int
    {
        return DB::transaction(function () use ($orderId, $actorId, $reason, $ipAddress): int {
            $order = Order::query()->lockForUpdate()->findOrFail($orderId);
            $this->assertVisible($order);
            if ($order->status !== 'pending') {
                throw new DomainException(__('orders.errors.only_pending_cancel'));
            }
            $order->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'cancelled_by' => $actorId,
                'cancellation_reason' => trim($reason),
            ]);
            $this->audit->record(
                description: __('orders.audit.cancelled'),
                properties: ['reason' => trim($reason)],
                causerId: $actorId,
                subject: $order,
                logName: 'order',
                event: 'cancelled',
                ipAddress: $ipAddress,
                messageKey: 'orders.audit.cancelled',
            );

            return (int) $order->id;
        });
    }

    public function reopen(int $orderId, int $actorId, string $reason, ?string $ipAddress): int
    {
        return DB::transaction(function () use ($orderId, $actorId, $reason, $ipAddress): int {
            $order = Order::query()->lockForUpdate()->findOrFail($orderId);
            $this->assertVisible($order);
            if ($order->status !== 'cancelled' || $order->trashed()) {
                throw new DomainException(__('orders.errors.only_cancelled_reopen'));
            }
            $order->update([
                'status' => 'pending',
                'cancelled_at' => null,
                'cancelled_by' => null,
                'cancellation_reason' => null,
            ]);
            $this->audit->record(
                description: __('orders.audit.reopened'),
                properties: ['reason' => trim($reason)],
                causerId: $actorId,
                subject: $order,
                logName: 'order',
                event: 'reopened',
                ipAddress: $ipAddress,
                messageKey: 'orders.audit.reopened',
            );

            return (int) $order->id;
        });
    }

    public function rollbackCompleted(int $orderId, int $actorId, string $reason, ?string $ipAddress): int
    {
        return DB::transaction(function () use ($orderId, $actorId, $reason, $ipAddress): int {
            $order = Order::query()->lockForUpdate()->findOrFail($orderId);
            $this->assertVisible($order);
            if ($order->status !== 'completed') {
                throw new DomainException(__('orders.errors.only_completed_rollback'));
            }

            $before = $order->only(['status', 'completed_on', 'completed_at', 'completion_precision']);
            $this->commissions->rollbackForOrder($orderId);
            $this->reminders->cancelForOrder($orderId, $actorId, $reason);
            $order->update([
                'status' => 'pending',
                'completed_on' => null,
                'occurred_on' => null,
                'completed_at' => null,
                'completion_precision' => 'date',
            ]);
            $this->audit->record(
                description: __('orders.audit.rolled_back'),
                properties: [
                    'before' => $before,
                    'after' => $order->only(['status', 'completed_on', 'completed_at', 'completion_precision']),
                    'reason' => trim($reason),
                ],
                causerId: $actorId,
                subject: $order,
                logName: 'order',
                event: 'completion_rolled_back',
                ipAddress: $ipAddress,
                messageKey: 'orders.audit.rolled_back',
            );

            return (int) $order->id;
        });
    }

    public function softDelete(int $orderId, int $actorId, string $reason, ?string $ipAddress): int
    {
        return DB::transaction(function () use ($orderId, $actorId, $reason, $ipAddress): int {
            $order = Order::query()->lockForUpdate()->findOrFail($orderId);
            $this->assertVisible($order);
            if ($order->status !== 'cancelled') {
                throw new DomainException(__('orders.errors.only_cancelled_delete'));
            }
            $order->update(['deleted_by' => $actorId, 'deletion_reason' => trim($reason)]);
            $order->delete();
            $this->audit->record(
                description: __('orders.audit.soft_deleted'),
                properties: ['reason' => trim($reason)],
                causerId: $actorId,
                subject: $order,
                logName: 'order',
                event: 'deleted',
                ipAddress: $ipAddress,
                messageKey: 'orders.audit.soft_deleted',
            );

            return (int) $order->id;
        });
    }

    public function restore(int $orderId, int $actorId, ?string $ipAddress): int
    {
        return DB::transaction(function () use ($orderId, $actorId, $ipAddress): int {
            $order = Order::withTrashed()->lockForUpdate()->findOrFail($orderId);
            $this->assertVisible($order);
            if ($order->status !== 'cancelled' || ! $order->trashed()) {
                throw new DomainException(__('orders.errors.only_cancelled_restore'));
            }
            $order->restore();
            $order->update(['deleted_by' => null, 'deletion_reason' => null]);
            $this->audit->record(
                description: __('orders.audit.restored'),
                properties: [],
                causerId: $actorId,
                subject: $order,
                logName: 'order',
                event: 'restored',
                ipAddress: $ipAddress,
                messageKey: 'orders.audit.restored',
            );

            return (int) $order->id;
        });
    }

    private function assertEditableReferences(OrderUpdateData $data): void
    {
        if ($this->institutions->institutionsByIds([$data->institutionId]) === []) {
            throw new DomainException(__('orders.errors.institution_unavailable'));
        }
        if ($data->agentId < 1) {
            throw new DomainException(__('orders.errors.agent_required'));
        }
        $agent = $this->agents->agentById($data->agentId);
        if ($agent['cooperation_status'] !== 'active') {
            throw new DomainException(__('orders.errors.agent_inactive_save'));
        }
    }

    /** @return array<int, array{project_name: string, specification: string|null, quantity: string, unit_price_krw: int, amount_krw: int, notes: string|null}> */
    private function normalizeItems(OrderUpdateData $data): array
    {
        $items = $data->items;
        if ($items === []) {
            $items = [[
                'project_name' => $data->projectName,
                'specification' => null,
                'quantity' => '1',
                'unit_price_krw' => $data->amountKrw,
                'amount_krw' => $data->amountKrw,
                'notes' => $data->notes,
            ]];
        }

        return array_values(array_map(function (array $item): array {
            $projectName = trim((string) ($item['project_name'] ?? $item['projectName'] ?? ''));
            $quantity = (string) ($item['quantity'] ?? '1');
            $unitPrice = (int) ($item['unit_price_krw'] ?? $item['unitPriceKrw'] ?? 0);
            $amount = (int) ($item['amount_krw'] ?? $item['amountKrw'] ?? 0);
            if ($projectName === '' || $unitPrice < 0 || $amount < 0 || ! is_numeric($quantity) || (float) $quantity <= 0) {
                throw new DomainException(__('orders.errors.order_items_invalid'));
            }
            try {
                $expectedAmount = BigDecimal::of($quantity)
                    ->multipliedBy($unitPrice)
                    ->toScale(0, RoundingMode::HalfUp)
                    ->toInt();
            } catch (\Throwable $exception) {
                throw new DomainException(__('orders.errors.order_items_invalid'), previous: $exception);
            }
            if ($expectedAmount !== $amount) {
                throw new DomainException(__('orders.errors.order_item_amount_mismatch'));
            }

            return [
                'project_name' => $projectName,
                'specification' => isset($item['specification']) ? trim((string) $item['specification']) : null,
                'quantity' => $quantity,
                'unit_price_krw' => $unitPrice,
                'amount_krw' => $amount,
                'notes' => isset($item['notes']) ? trim((string) $item['notes']) : null,
            ];
        }, $items));
    }

    private function assertVisible(Order $order): void
    {
        abort_unless($this->access->current()->canViewOrder(
            $order->agent_id === null ? null : (int) $order->agent_id,
            $order->owner_id === null ? null : (int) $order->owner_id,
        ), 404);
    }
}
