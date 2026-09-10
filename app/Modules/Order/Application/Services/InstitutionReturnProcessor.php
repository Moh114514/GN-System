<?php

namespace App\Modules\Order\Application\Services;

use App\Infrastructure\Time\BusinessClock;
use App\Modules\Agent\Application\Contracts\AgentReferenceReader;
use App\Modules\Config\Application\Contracts\InstitutionReferenceReader;
use App\Modules\Customer\Application\Contracts\CustomerOrderReferenceReader;
use App\Modules\Order\Application\Data\CompletedOrderItemData;
use App\Modules\Order\Application\Data\CompletedOrderRegistrationData;
use App\Modules\Order\Application\Data\InstitutionReturnUploadData;
use App\Modules\Order\Infrastructure\InstitutionReturnStorage;
use App\Modules\Order\Infrastructure\Models\InstitutionFormTemplate;
use App\Modules\Order\Infrastructure\Models\InstitutionReturnFile;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\QueryException;
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
        private BusinessClock $clock,
        private CompletedOrderRegistrar $registrar,
    ) {}

    public function upload(InstitutionReturnUploadData $data): int
    {
        if (! isset($this->institutions->activeInstitutions()[$data->institutionId])) {
            throw new DomainException(__('orders.errors.institution_unavailable'));
        }
        $customer = $this->customers->customerForOrder($data->customerId);
        if (($customer['current_status_key'] ?? null) !== 'arrived' || ($customer['arrived_at'] ?? null) === null) {
            throw new DomainException(__('orders.errors.customer_not_arrived'));
        }
        $arrivedOn = CarbonImmutable::parse((string) $customer['arrived_at'])->startOfDay();
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
                'arrived_on' => $arrivedOn,
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

            $orderId = $this->registrar->register(new CompletedOrderRegistrationData(
                customerId: $data->customerId,
                institutionId: $data->institutionId,
                agentId: (int) $customer['source_agent_id'],
                items: array_map(
                    static fn (array $item): CompletedOrderItemData => new CompletedOrderItemData(
                        projectName: (string) $item['project_name'],
                        amountKrw: (int) $item['amount_krw'],
                        unitPriceKrw: (int) $item['unit_price_krw'],
                        quantity: (string) $item['quantity'],
                        specification: $item['specification'],
                        notes: $item['notes'],
                    ),
                    $parsed['items'],
                ),
                occurredOn: $parsed['occurred_on'],
                actorId: $data->actorId,
                ipAddress: $data->ipAddress,
                ownerId: isset($customer['owner_id']) ? (int) $customer['owner_id'] : $data->actorId,
                source: 'institution_return',
                sourceReturnFileId: $returnFile->id,
                sourceMetadata: [
                    'return_file_id' => $returnFile->id,
                    'template_key' => InstitutionFormSchema::TEMPLATE_KEY,
                    'template_version' => $template->version,
                ],
                requireArrived: true,
            ));

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
