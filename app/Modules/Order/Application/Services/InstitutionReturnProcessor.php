<?php

namespace App\Modules\Order\Application\Services;

use App\Infrastructure\Time\BusinessClock;
use App\Modules\Agent\Application\Contracts\AgentBusinessAttributionReader;
use App\Modules\Agent\Application\Contracts\AgentReferenceReader;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Config\Application\Contracts\InstitutionReferenceReader;
use App\Modules\Customer\Application\Contracts\CustomerOrderReferenceReader;
use App\Modules\Customer\Application\Contracts\CustomerTreatmentCompletionGateway;
use App\Modules\Order\Application\Contracts\CustomerOrderGateway;
use App\Modules\Order\Application\Data\InstitutionReturnUploadData;
use App\Modules\Order\Infrastructure\InstitutionReturnStorage;
use App\Modules\Order\Infrastructure\Models\InstitutionFormTemplate;
use App\Modules\Order\Infrastructure\Models\InstitutionReturnFile;
use App\Modules\Order\Infrastructure\Models\Order;
use App\Modules\Order\Infrastructure\Models\OrderItem;
use App\Modules\Reminder\Application\Contracts\AppointmentReminderGateway;
use App\Modules\Reminder\Application\Contracts\TreatmentReminderGateway;
use App\Modules\Reminder\Application\Data\CompletedTreatmentData;
use App\Modules\Settlement\Application\Contracts\DailyCommissionGateway;
use App\Modules\Settlement\Application\Data\CompletedOrderCommissionData;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final readonly class InstitutionReturnProcessor
{
    public function __construct(
        private InstitutionReturnParser $parser,
        private InstitutionReturnStorage $storage,
        private InstitutionReferenceReader $institutions,
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
    ) {}

    public function upload(InstitutionReturnUploadData $data): int
    {
        if (! isset($this->institutions->activeInstitutions()[$data->institutionId])) {
            throw new DomainException(__('orders.errors.institution_unavailable'));
        }
        $customer = $this->customers->customerForOrder($data->customerId);
        $hash = hash('sha256', $data->contents);
        if (InstitutionReturnFile::query()->where('sha256', $hash)->exists()) {
            throw new DomainException(__('orders.errors.institution_return_duplicate_file'));
        }

        $returnFileId = (string) Str::uuid();
        $stored = $this->storage->store($returnFileId, $data->contents);
        $storedPath = (string) $stored['path'];
        $returnFile = null;
        try {
            try {
                $returnFile = InstitutionReturnFile::query()->create([
                    'id' => $returnFileId,
                    'institution_id' => $data->institutionId,
                    'original_name' => $data->originalName,
                    'extension' => strtolower($data->extension),
                    'mime_type' => $data->mimeType,
                    'size_bytes' => $stored['size'],
                    'sha256' => $stored['sha256'],
                    'encrypted_path' => $stored['path'],
                    'status' => 'uploaded',
                    'uploaded_by' => $data->actorId,
                    'uploaded_at' => $this->clock->now(),
                ]);
            } catch (QueryException $exception) {
                $this->storage->delete($storedPath);
                throw new DomainException(__('orders.errors.institution_return_duplicate_form'), previous: $exception);
            } catch (Throwable $exception) {
                $this->storage->delete($storedPath);
                throw $exception;
            }

            $parsed = $this->parser->parse($data->contents, $data->extension, [
                'institution_id' => $data->institutionId,
                'customer_id' => $data->customerId,
                'customer_code' => (string) $customer['code'],
                'customer_name' => (string) $customer['name'],
            ]);
            $agent = $this->agents->agentById((int) $customer['source_agent_id']);
            if ($agent['cooperation_status'] !== 'active') {
                throw new DomainException(__('orders.errors.agent_inactive_save'));
            }
            $template = InstitutionFormTemplate::query()
                ->where('institution_id', $data->institutionId)
                ->where('template_key', InstitutionFormSchema::TEMPLATE_KEY)
                ->where('version', (int) ($parsed['metadata']['template_version'] ?? 0))
                ->where('is_active', true)
                ->first();
            if ($template === null) {
                throw new DomainException(__('orders.errors.institution_template_inactive'));
            }

            $returnFile->update([
                'template_id' => $template->id,
                'customer_id' => $data->customerId,
                'form_uuid' => $parsed['form_uuid'],
                'metadata' => [
                    ...$parsed['metadata'],
                    'row_count' => count($parsed['items']),
                    'total_amount_krw' => $parsed['total_amount_krw'],
                    'occurred_on' => $parsed['occurred_on']->toDateString(),
                ],
                'integrity_signature' => $parsed['integrity_signature'],
                'status' => 'processing',
            ]);

            $orderId = DB::transaction(function () use ($data, $customer, $agent, $parsed, $returnFile, $template): int {
                $attribution = $this->attributions->forAgentOnDate(
                    (int) $customer['source_agent_id'],
                    $parsed['occurred_on'],
                );
                $order = Order::query()->create([
                    'customer_id' => $data->customerId,
                    'institution_id' => $data->institutionId,
                    'agent_id' => (int) $customer['source_agent_id'],
                    'project_name' => (string) $parsed['items'][0]['project_name'],
                    'amount_krw' => (int) $parsed['total_amount_krw'],
                    'occurred_on' => $parsed['occurred_on'],
                    'completed_on' => $parsed['occurred_on'],
                    'completed_at' => $parsed['occurred_on']->startOfDay(),
                    'completion_precision' => 'date',
                    'record_status' => 'active',
                    'status' => 'completed',
                    'owner_id' => $customer['owner_id'] ?? $data->actorId,
                    'source_return_file_id' => $returnFile->id,
                    'treatment_project_snapshot' => (string) $parsed['items'][0]['project_name'],
                    'business_attribution_snapshot' => [
                        'source' => 'institution_return',
                        'agent' => $agent,
                        'business_group' => $attribution,
                        'institution_id' => $data->institutionId,
                        'occurred_on' => $parsed['occurred_on']->toDateString(),
                        'template_key' => InstitutionFormSchema::TEMPLATE_KEY,
                        'template_version' => $template->version,
                    ],
                ]);

                foreach ($parsed['items'] as $item) {
                    OrderItem::query()->create([
                        'order_id' => $order->id,
                        'project_snapshot' => $item['project_name'],
                        'specification' => $item['specification'],
                        'quantity' => $item['quantity'],
                        'unit_price_krw' => $item['unit_price_krw'],
                        'amount_krw' => $item['amount_krw'],
                        'notes' => $item['notes'],
                    ]);
                }

                $this->commissions->recordForCompletedOrder(new CompletedOrderCommissionData(
                    orderId: (int) $order->id,
                    agentId: (int) $customer['source_agent_id'],
                    institutionId: $data->institutionId,
                    orderAmountKrw: (int) $parsed['total_amount_krw'],
                    completedOn: $parsed['occurred_on'],
                    actorId: $data->actorId,
                    ipAddress: $data->ipAddress,
                ));
                $this->customerCompletion->completeFromInstitutionReturn(
                    customerId: $data->customerId,
                    occurredOn: $parsed['occurred_on'],
                    actorId: $data->actorId,
                    ipAddress: $data->ipAddress,
                );
                $appointmentId = $this->appointments->completeAppointmentForCustomer($data->customerId, $data->institutionId);
                if ($appointmentId !== null) {
                    $this->appointmentReminders->cancelForAppointment($appointmentId, $data->actorId, 'institution_return_completed');
                }
                $this->reminders->schedule(new CompletedTreatmentData(
                    orderId: (int) $order->id,
                    customerId: $data->customerId,
                    projectName: (string) $parsed['items'][0]['project_name'],
                    completedOn: $parsed['occurred_on'],
                    ownerId: isset($customer['owner_id']) ? (int) $customer['owner_id'] : $data->actorId,
                    actorId: $data->actorId,
                ));

                $this->audit->record(
                    description: '机构回传原子生成订单',
                    properties: [
                        'return_file_id' => $returnFile->id,
                        'order_id' => $order->id,
                        'occurred_on' => $parsed['occurred_on']->toDateString(),
                        'item_count' => count($parsed['items']),
                    ],
                    causerId: $data->actorId,
                    subject: $order,
                    logName: 'order',
                    event: 'institution_return_completed',
                    ipAddress: $data->ipAddress,
                );

                return (int) $order->id;
            }, 3);

            $returnFile->update([
                'status' => 'processed',
                'processed_at' => $this->clock->now(),
            ]);

            return $orderId;
        } catch (QueryException $exception) {
            $this->markFailure($returnFile, 'duplicate_or_constraint', $exception->getMessage());
            throw new DomainException(__('orders.errors.institution_return_duplicate_form'), previous: $exception);
        } catch (Throwable $exception) {
            $this->markFailure($returnFile, 'processing_failed', $exception->getMessage());
            throw $exception;
        }
    }

    private function markFailure(?InstitutionReturnFile $returnFile, string $code, string $reason): void
    {
        $returnFile?->update([
            'status' => 'failed',
            'failure_code' => $code,
            'failure_reason' => mb_substr($reason, 0, 2000),
        ]);
    }
}
