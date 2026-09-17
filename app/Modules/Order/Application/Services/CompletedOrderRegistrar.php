<?php

namespace App\Modules\Order\Application\Services;

use App\Infrastructure\Time\BusinessClock;
use App\Modules\Agent\Application\Contracts\AgentBusinessAttributionReader;
use App\Modules\Agent\Application\Contracts\AgentReferenceReader;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Customer\Application\Contracts\CustomerOrderReferenceReader;
use App\Modules\Customer\Application\Contracts\CustomerTreatmentCompletionGateway;
use App\Modules\Order\Application\Contracts\CustomerOrderGateway;
use App\Modules\Order\Application\Data\CompletedOrderItemData;
use App\Modules\Order\Application\Data\CompletedOrderRegistrationData;
use App\Modules\Order\Infrastructure\Models\Order;
use App\Modules\Order\Infrastructure\Models\OrderEvidenceFile;
use App\Modules\Order\Infrastructure\Models\OrderItem;
use App\Modules\Order\Infrastructure\OrderEvidenceStorage;
use App\Modules\Reminder\Application\Contracts\AppointmentReminderGateway;
use App\Modules\Reminder\Application\Contracts\TreatmentReminderGateway;
use App\Modules\Reminder\Application\Data\CompletedTreatmentData;
use App\Modules\Settlement\Application\Contracts\DailyCommissionGateway;
use App\Modules\Settlement\Application\Data\CompletedOrderCommissionData;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Throwable;

final readonly class CompletedOrderRegistrar
{
    public function __construct(
        private CustomerOrderReferenceReader $customers,
        private AgentReferenceReader $agents,
        private AgentBusinessAttributionReader $attributions,
        private CustomerTreatmentCompletionGateway $customerCompletion,
        private DailyCommissionGateway $commissions,
        private TreatmentReminderGateway $reminders,
        private CustomerOrderGateway $appointments,
        private AppointmentReminderGateway $appointmentReminders,
        private AuditRecorder $audit,
        private BusinessClock $clock,
        private OrderEvidenceStorage $evidenceStorage,
    ) {}

    public function register(CompletedOrderRegistrationData $data): int
    {
        $customer = $this->customers->customerForOrder($data->customerId);
        $this->assertCustomerCanBeCompleted($customer, $data);
        $this->assertAgent($data->agentId, (int) $customer['source_agent_id']);

        $items = $this->normalizeItems($data->items);
        $totalAmount = array_sum(array_map(static fn (CompletedOrderItemData $item): int => $item->amountKrw, $items));
        if ($totalAmount < 1) {
            throw new DomainException(__('orders.errors.order_items_invalid'));
        }

        $storedPaths = [];
        try {
            return DB::transaction(function () use ($data, $customer, $items, $totalAmount, &$storedPaths): int {
                $occurredOn = $data->occurredOn->startOfDay();
                $attribution = $this->attributions->forAgentOnDate($data->agentId, $occurredOn);
                $order = Order::query()->create([
                    'customer_id' => $data->customerId,
                    'institution_id' => $data->institutionId,
                    'agent_id' => $data->agentId,
                    'project_name' => $items[0]->projectName,
                    'amount_krw' => $totalAmount,
                    'occurred_on' => $occurredOn,
                    'completed_on' => $occurredOn,
                    'completed_at' => $occurredOn,
                    'completion_precision' => 'date',
                    'record_status' => 'active',
                    'status' => 'completed',
                    'owner_id' => $data->ownerId ?? ($customer['owner_id'] ?? $data->actorId),
                    'source_return_file_id' => $data->sourceReturnFileId,
                    'treatment_project_snapshot' => $items[0]->projectName,
                    'business_attribution_snapshot' => [
                        'source' => $data->source,
                        'agent' => $this->agents->agentById($data->agentId),
                        'business_group' => $attribution,
                        'institution_id' => $data->institutionId,
                        'occurred_on' => $occurredOn->toDateString(),
                        ...$data->sourceMetadata,
                    ],
                ]);

                foreach ($items as $item) {
                    OrderItem::query()->create([
                        'order_id' => $order->id,
                        'treatment_project_id' => $item->treatmentProjectId,
                        'project_snapshot' => $item->projectName,
                        'specification' => $item->specification,
                        'quantity' => $item->quantity,
                        'unit_price_krw' => $item->unitPriceKrw ?? $item->amountKrw,
                        'amount_krw' => $item->amountKrw,
                        'notes' => $item->notes,
                    ]);
                }

                foreach ($data->evidence as $evidence) {
                    $stored = $this->evidenceStorage->store((int) $order->id, $evidence->contents);
                    $storedPaths[] = $stored['path'];
                    OrderEvidenceFile::query()->create([
                        'order_id' => $order->id,
                        'type' => $evidence->type,
                        'original_name' => $evidence->originalName,
                        'mime_type' => $evidence->mimeType,
                        'size_bytes' => $stored['size'],
                        'sha256' => $stored['sha256'],
                        'encrypted_path' => $stored['path'],
                        'uploaded_by' => $data->actorId,
                        'uploaded_at' => $this->clock->now(),
                    ]);
                }

                $this->commissions->recordForCompletedOrder(new CompletedOrderCommissionData(
                    orderId: (int) $order->id,
                    agentId: $data->agentId,
                    institutionId: $data->institutionId,
                    orderAmountKrw: $totalAmount,
                    completedOn: $occurredOn,
                    actorId: $data->actorId,
                    ipAddress: $data->ipAddress,
                ));
                $this->customerCompletion->completeFromOrder(
                    customerId: $data->customerId,
                    occurredOn: $occurredOn,
                    actorId: $data->actorId,
                    ipAddress: $data->ipAddress,
                );
                $appointmentId = $this->appointments->completeAppointmentForCustomer($data->customerId, $data->institutionId);
                if ($appointmentId !== null) {
                    $this->appointmentReminders->cancelForAppointment($appointmentId, $data->actorId, 'completed_order_registered');
                }
                $this->reminders->schedule(new CompletedTreatmentData(
                    orderId: (int) $order->id,
                    customerId: $data->customerId,
                    projectName: $items[0]->projectName,
                    completedOn: $occurredOn,
                    ownerId: $order->owner_id === null ? null : (int) $order->owner_id,
                    actorId: $data->actorId,
                ));

                $this->audit->record(
                    description: __('orders.audit.created'),
                    properties: [
                        'source' => $data->source,
                        'order_id' => $order->id,
                        'occurred_on' => $occurredOn->toDateString(),
                        'item_count' => count($items),
                        'evidence_count' => count($data->evidence),
                    ],
                    causerId: $data->actorId,
                    subject: $order,
                    logName: 'order',
                    event: 'created',
                    ipAddress: $data->ipAddress,
                );

                return (int) $order->id;
            }, 3);
        } catch (Throwable $exception) {
            foreach ($storedPaths as $path) {
                $this->evidenceStorage->delete($path);
            }

            throw $exception;
        }
    }

    /**
     * @param  array<int, mixed>  $items
     * @return array<int, CompletedOrderItemData>
     */
    private function normalizeItems(array $items): array
    {
        if ($items === []) {
            throw new DomainException(__('orders.errors.order_items_invalid'));
        }

        foreach ($items as $item) {
            if (! $item instanceof CompletedOrderItemData || trim($item->projectName) === '' || $item->amountKrw < 1) {
                throw new DomainException(__('orders.errors.order_items_invalid'));
            }
        }

        return array_values($items);
    }

    /** @param array<string, mixed> $customer */
    private function assertCustomerCanBeCompleted(array $customer, CompletedOrderRegistrationData $data): void
    {
        $types = array_map(static fn ($evidence): string => $evidence->type, $data->evidence);
        if ($data->requireEvidence && (! in_array('communication_screenshot', $types, true) || ! in_array('settlement_receipt', $types, true))) {
            throw new DomainException(__('orders.errors.order_evidence_required'));
        }

        if (! $data->requireArrived) {
            return;
        }

        if (($customer['current_status_key'] ?? null) !== 'arrived' || ($customer['arrived_at'] ?? null) === null) {
            throw new DomainException(__('orders.errors.customer_not_arrived'));
        }

        $arrivedOn = CarbonImmutable::parse((string) $customer['arrived_at'])->startOfDay();
        if (! $arrivedOn->equalTo($data->occurredOn->startOfDay())) {
            throw new DomainException(__('orders.errors.occurred_on_arrival_mismatch'));
        }
    }

    private function assertAgent(int $agentId, int $customerAgentId): void
    {
        if ($agentId < 1 || $agentId !== $customerAgentId) {
            throw new DomainException(__('orders.errors.agent_required'));
        }
        if ($this->agents->agentById($agentId)['cooperation_status'] !== 'active') {
            throw new DomainException(__('orders.errors.agent_inactive_save'));
        }
    }
}
