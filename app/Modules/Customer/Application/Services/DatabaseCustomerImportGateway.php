<?php

namespace App\Modules\Customer\Application\Services;

use App\Modules\Customer\Application\Contracts\CustomerImportGateway;
use App\Modules\Customer\Application\Data\CustomerImportData;
use App\Modules\Customer\Domain\BlindIndex;
use App\Modules\Customer\Infrastructure\Models\Customer;
use App\Modules\Customer\Infrastructure\Models\CustomerContact;
use App\Modules\Customer\Infrastructure\Models\CustomerIdentityDocument;
use App\Modules\Customer\Infrastructure\Models\CustomerStatus;
use App\Modules\Customer\Infrastructure\Models\DirectSalesSource;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

final readonly class DatabaseCustomerImportGateway implements CustomerImportGateway
{
    public function __construct(private BlindIndex $blindIndex) {}

    public function duplicateCandidateIds(?string $contact, ?string $identityDocument): array
    {
        $ids = [];
        $contactHash = $this->blindIndex->for($contact);
        $documentHash = $this->blindIndex->for($identityDocument);

        if ($contactHash !== null) {
            $ids = CustomerContact::query()->where('lookup_hash', $contactHash)->pluck('customer_id')->all();
        }

        if ($documentHash !== null) {
            $ids = [
                ...$ids,
                ...CustomerIdentityDocument::query()->where('lookup_hash', $documentHash)->pluck('customer_id')->all(),
            ];
        }

        return array_values(array_unique(array_map('intval', $ids)));
    }

    public function resolveCustomerId(string $code): ?int
    {
        return Customer::query()
            ->where('code', $code)
            ->orWhere('legacy_code', $code)
            ->value('id');
    }

    public function resolveDirectSalesSourceId(string $code): ?int
    {
        return DirectSalesSource::query()
            ->where('code', strtoupper(trim($code)))
            ->where('is_active', true)
            ->value('id');
    }

    public function upsertDirectSalesSource(string $code, string $name): int
    {
        $source = DirectSalesSource::query()->updateOrCreate(
            ['code' => strtoupper(trim($code))],
            ['name' => trim($name), 'is_active' => true],
        );

        return $source->id;
    }

    public function upsertCustomer(CustomerImportData $data): int
    {
        $statusId = $data->statusName === null
            ? null
            : CustomerStatus::query()->where('name', $data->statusName)->value('id');

        $customer = Customer::query()->updateOrCreate(
            ['code' => $data->code],
            [
                'legacy_code' => $data->legacyCode,
                'name' => $data->name,
                'gender' => $data->gender,
                'birth_date' => $data->birthDate,
                'original_channel' => $data->originalChannel,
                'source_agent_id' => $data->sourceAgentId,
                'source_direct_sales_id' => $data->sourceDirectSalesId,
                'current_status_id' => $statusId,
                'wechat_added_on' => $data->wechatAddedOn,
                'project_intention' => $data->projectIntention,
                'notes' => $data->notes,
                'import_batch_id' => $data->importBatchId,
            ],
        );

        $contactHash = $this->blindIndex->for($data->contactValue);
        if ($data->contactValue !== null && $contactHash !== null) {
            CustomerContact::query()->updateOrCreate(
                ['customer_id' => $customer->id, 'lookup_hash' => $contactHash],
                [
                    'type' => 'phone_or_wechat',
                    'value_encrypted' => $data->contactValue,
                    'is_primary' => true,
                    'import_batch_id' => $data->importBatchId,
                ],
            );
        }

        $documentHash = $this->blindIndex->for($data->identityDocument);
        if ($data->identityDocument !== null && $documentHash !== null) {
            CustomerIdentityDocument::query()->updateOrCreate(
                ['customer_id' => $customer->id, 'lookup_hash' => $documentHash],
                [
                    'type' => 'passport_or_residence_card',
                    'number_encrypted' => $data->identityDocument,
                    'import_batch_id' => $data->importBatchId,
                ],
            );
        }

        return $customer->id;
    }

    public function deleteImportedByBatch(string $batchId): int
    {
        DB::table('customer_status_histories')->where('import_batch_id', $batchId)->delete();
        CustomerIdentityDocument::query()->where('import_batch_id', $batchId)->delete();
        CustomerContact::query()->where('import_batch_id', $batchId)->delete();

        return Customer::query()->where('import_batch_id', $batchId)->delete();
    }

    public function rollbackBlockers(string $batchId, DateTimeInterface $completedAt): array
    {
        $blockers = [];
        foreach ([
            'customers',
            'customer_contacts',
            'customer_identity_documents',
            'customer_status_histories',
        ] as $table) {
            $ids = DB::table($table)
                ->where('import_batch_id', $batchId)
                ->where('updated_at', '>', $completedAt)
                ->pluck('id');

            foreach ($ids as $id) {
                $blockers[] = "{$table}:{$id}";
            }
        }

        return $blockers;
    }
}
