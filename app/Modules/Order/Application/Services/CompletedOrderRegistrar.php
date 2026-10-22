<?php

namespace App\Modules\Order\Application\Services;

use App\Infrastructure\Time\BusinessClock;
use App\Modules\Agent\Application\Contracts\AgentBusinessAttributionReader;
use App\Modules\Agent\Application\Contracts\AgentReferenceReader;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Customer\Application\Contracts\CustomerOrderReferenceReader;
use App\Modules\Customer\Application\Contracts\CustomerTreatmentCompletionGateway;
use App\Modules\Order\Application\Contracts\CustomerOrderGateway;
use App\Modules\Order\Application\Contracts\OrderRegistrationDraftGateway;
use App\Modules\Order\Application\Data\CompletedOrderItemData;
use App\Modules\Order\Application\Data\CompletedOrderRegistrationData;
use App\Modules\Order\Application\Data\OrderEvidenceUploadData;
use App\Modules\Order\Infrastructure\Models\Order;
use App\Modules\Order\Infrastructure\Models\OrderEvidenceFile;
use App\Modules\Order\Infrastructure\Models\OrderItem;
use App\Modules\Order\Infrastructure\OrderEvidenceStorage;
use App\Modules\Reminder\Application\Contracts\AppointmentReminderGateway;
use App\Modules\Reminder\Application\Contracts\TreatmentReminderGateway;
use App\Modules\Reminder\Application\Data\CompletedTreatmentData;
use App\Modules\Settlement\Application\Contracts\DailyCommissionGateway;
use App\Modules\Settlement\Application\Contracts\DirectOrderCommissionGateway;
use App\Modules\Settlement\Application\Data\CompletedOrderCommissionData;
use App\Modules\Settlement\Application\Data\DirectOrderCommissionData;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final readonly class CompletedOrderRegistrar
{
    public function __construct(
        private CustomerOrderReferenceReader $customers,
        private AgentReferenceReader $agents,
        private AgentBusinessAttributionReader $attributions,
        private CustomerTreatmentCompletionGateway $customerCompletion,
        private DailyCommissionGateway $commissions,
        private DirectOrderCommissionGateway $directCommissions,
        private TreatmentReminderGateway $reminders,
        private CustomerOrderGateway $appointments,
        private AppointmentReminderGateway $appointmentReminders,
        private AuditRecorder $audit,
        private BusinessClock $clock,
        private OrderEvidenceStorage $evidenceStorage,
        private OrderRegistrationDraftGateway $drafts,
    ) {}

    public function register(CompletedOrderRegistrationData $data): int
    {
        $customer = $this->customers->customerForOrder($data->customerId);
        $occurredOn = $data->occurredOn->startOfDay();
        $idempotencyKey = $data->sourceReturnFileId ?? $data->idempotencyKey;
        if ($idempotencyKey !== null && ! Str::isUuid($idempotencyKey)) {
            throw new DomainException(__('orders.errors.idempotency_conflict'));
        }
        $fingerprint = $this->fingerprint($data, $occurredOn);
        if ($occurredOn->gt($this->clock->now()->startOfDay())) {
            throw new DomainException(__('orders.errors.business_date_in_future'));
        }
        if ($idempotencyKey !== null) {
            $existing = Order::withTrashed()->where('registration_key', $idempotencyKey)->first();
            if ($existing !== null) {
                if ($existing->trashed() || ! hash_equals((string) $existing->registration_fingerprint, $fingerprint)) {
                    throw new DomainException(__('orders.errors.idempotency_conflict'));
                }

                return (int) $existing->id;
            }
        }
        $this->assertCustomerCanBeCompleted($customer, $data);
        $sourceType = (string) $customer['source_type'];
        if ($sourceType === 'direct') {
            if (($customer['owner_id'] ?? null) === null) {
                throw new DomainException(__('orders.errors.direct_customer_owner_required'));
            }
            if ($data->agentId !== null) {
                throw new DomainException(__('orders.errors.agent_not_allowed_for_direct_customer'));
            }
        } else {
            $this->assertAgent($data->agentId, (int) $customer['source_agent_id'], $occurredOn);
        }

        $items = $this->normalizeItems($data->items);
        $totalAmount = array_sum(array_map(static fn (CompletedOrderItemData $item): int => $item->amountKrw, $items));
        if ($totalAmount < 1) {
            throw new DomainException(__('orders.errors.order_items_invalid'));
        }

        $storedPaths = [];
        try {
            return DB::transaction(function () use ($data, $customer, $sourceType, $items, $totalAmount, $idempotencyKey, $fingerprint, &$storedPaths): int {
                $occurredOn = $data->occurredOn->startOfDay();
                $completedAt = $this->clock->now();
                if ($data->requireArrived) {
                    $visit = $data->appointmentId === null
                        ? null
                        : $this->appointments->registrationVisit($data->customerId, $data->appointmentId, $data->institutionId);
                    if ($visit === null || ! CarbonImmutable::parse($visit['arrived_at'])->startOfDay()->equalTo($occurredOn)) {
                        throw new DomainException(__('orders.errors.customer_not_arrived'));
                    }
                }
                $firstOrderForVisit = $data->appointmentId === null
                    || ! Order::query()->where('appointment_id', $data->appointmentId)->exists();
                $agent = $sourceType === 'agent' && $data->agentId !== null
                    ? $this->agents->agentById($data->agentId)
                    : null;
                $attribution = $sourceType === 'agent' && $data->agentId !== null
                    ? $this->attributions->forAgentOnDate($data->agentId, $occurredOn)
                    : null;
                $ownerId = $sourceType === 'direct'
                    ? (int) $customer['owner_id']
                    : ($data->ownerId ?? $customer['owner_id'] ?? null);
                $order = Order::query()->create([
                    'customer_id' => $data->customerId,
                    'institution_id' => $data->institutionId,
                    'appointment_id' => $data->appointmentId,
                    'source_type' => $sourceType,
                    'agent_id' => $data->agentId,
                    'project_name' => $items[0]->projectName,
                    'amount_krw' => $totalAmount,
                    'occurred_on' => $occurredOn,
                    'completed_on' => $occurredOn,
                    'completed_at' => $completedAt,
                    'completion_precision' => 'datetime',
                    'record_status' => 'active',
                    'status' => 'completed',
                    'owner_id' => $ownerId,
                    'source_return_file_id' => $data->sourceReturnFileId,
                    'registration_key' => $idempotencyKey,
                    'registration_fingerprint' => $fingerprint,
                    'treatment_project_snapshot' => $items[0]->projectName,
                    'business_attribution_snapshot' => [
                        'source' => $data->source,
                        'agent' => $agent,
                        'business_group' => $attribution,
                        'direct_channel' => $sourceType === 'direct' ? [
                            'id' => $customer['direct_channel_id'] ?? null,
                            'name' => $customer['direct_channel_name'] ?? null,
                        ] : null,
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

                if ($sourceType === 'direct') {
                    $this->directCommissions->recordForCompletedOrder(new DirectOrderCommissionData(
                        orderId: (int) $order->id,
                        ownerId: (int) $ownerId,
                        orderAmountKrw: $totalAmount,
                        completedAt: $completedAt,
                        directChannel: [
                            'id' => $customer['direct_channel_id'] ?? null,
                            'name' => $customer['direct_channel_name'] ?? null,
                        ],
                        actorId: $data->actorId,
                        ipAddress: $data->ipAddress,
                    ));
                } else {
                    $this->commissions->recordForCompletedOrder(new CompletedOrderCommissionData(
                        orderId: (int) $order->id,
                        agentId: (int) $data->agentId,
                        institutionId: $data->institutionId,
                        orderAmountKrw: $totalAmount,
                        completedOn: $occurredOn,
                        actorId: $data->actorId,
                        ipAddress: $data->ipAddress,
                    ));
                }
                if ($firstOrderForVisit) {
                    $this->customerCompletion->completeFromOrder(
                        customerId: $data->customerId,
                        occurredOn: $occurredOn,
                        actorId: $data->actorId,
                        ipAddress: $data->ipAddress,
                    );
                    $appointmentId = $this->appointments->completeAppointmentForCustomer(
                        $data->customerId,
                        $data->institutionId,
                        $data->appointmentId,
                    );
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
                        sourceType: $sourceType,
                        visitId: $data->appointmentId,
                    ));
                }
                if ($data->draftId !== null) {
                    $this->drafts->complete($data->draftId, $data->customerId, (int) $order->id, $data->appointmentId);
                }

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
        } catch (QueryException $exception) {
            foreach ($storedPaths as $path) {
                $this->evidenceStorage->delete($path);
            }

            if ($idempotencyKey !== null) {
                $existing = Order::withTrashed()->where('registration_key', $idempotencyKey)->first();
                if ($existing !== null && ! $existing->trashed()
                    && hash_equals((string) $existing->registration_fingerprint, $fingerprint)) {
                    return (int) $existing->id;
                }
            }

            throw $exception;
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
        if ($data->requireEvidence && $data->evidence === []) {
            throw new DomainException(__('orders.errors.order_evidence_required'));
        }

        if (! $data->requireArrived) {
            return;
        }

        if (! in_array(($customer['current_status_key'] ?? null), ['arrived', 'treatment_completed'], true)
            || $data->appointmentId === null) {
            throw new DomainException(__('orders.errors.customer_not_arrived'));
        }

        $visit = $this->appointments->registrationVisit($data->customerId, $data->appointmentId, $data->institutionId);
        if ($visit === null
            || ! CarbonImmutable::parse($visit['arrived_at'])->startOfDay()->equalTo($data->occurredOn->startOfDay())) {
            throw new DomainException(__('orders.errors.occurred_on_arrival_mismatch'));
        }
    }

    private function fingerprint(CompletedOrderRegistrationData $data, CarbonImmutable $occurredOn): string
    {
        $items = array_map(static fn (CompletedOrderItemData $item): array => [
            'project_name' => trim($item->projectName),
            'treatment_project_id' => $item->treatmentProjectId,
            'amount_krw' => $item->amountKrw,
            'unit_price_krw' => $item->unitPriceKrw,
            'quantity' => $item->quantity,
            'specification' => $item->specification,
            'notes' => $item->notes,
        ], $this->normalizeItems($data->items));

        return hash('sha256', json_encode([
            'customer_id' => $data->customerId,
            'institution_id' => $data->institutionId,
            'appointment_id' => $data->appointmentId,
            'agent_id' => $data->agentId,
            'occurred_on' => $occurredOn->toDateString(),
            'items' => array_values($items),
            'source' => $data->source,
            'source_metadata' => $data->sourceMetadata,
            'evidence' => array_map(static fn (OrderEvidenceUploadData $evidence): array => [
                'type' => $evidence->type,
                'original_name' => $evidence->originalName,
                'sha256' => hash('sha256', $evidence->contents),
            ], $data->evidence),
        ], JSON_THROW_ON_ERROR));
    }

    private function assertAgent(?int $agentId, int $customerAgentId, CarbonImmutable $occurredOn): void
    {
        if ($agentId === null || $agentId < 1 || $agentId !== $customerAgentId) {
            throw new DomainException(__('orders.errors.agent_required'));
        }
        $eligibility = $this->agents->orderEligibility($agentId);
        $cooperationStartedOn = $eligibility['cooperation_started_on'] === null
            ? null
            : CarbonImmutable::parse($eligibility['cooperation_started_on'])->startOfDay();
        $cooperationEndedOn = $eligibility['cooperation_ended_on'] === null
            ? null
            : CarbonImmutable::parse($eligibility['cooperation_ended_on'])->startOfDay();
        if (($cooperationStartedOn !== null && $occurredOn->lt($cooperationStartedOn))
            || ($cooperationEndedOn !== null && $occurredOn->gt($cooperationEndedOn))) {
            throw new DomainException(__('agents.validation.ineligible_for_order_month'));
        }
        if (! $occurredOn->lt($this->clock->now()->startOfDay()) && $eligibility['cooperation_status'] !== 'active') {
            throw new DomainException(__('orders.errors.agent_inactive_save'));
        }
    }
}
