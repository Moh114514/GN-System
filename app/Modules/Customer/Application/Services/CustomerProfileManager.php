<?php

namespace App\Modules\Customer\Application\Services;

use App\Modules\Agent\Application\Contracts\AgentReferenceReader;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Customer\Application\Data\CustomerProfileData;
use App\Modules\Customer\Application\Exceptions\CustomerCodeChanged;
use App\Modules\Customer\Domain\BlindIndex;
use App\Modules\Customer\Infrastructure\Models\Customer;
use App\Modules\Customer\Infrastructure\Models\CustomerContact;
use App\Modules\Customer\Infrastructure\Models\CustomerIdentityDocument;
use App\Modules\Customer\Infrastructure\Models\CustomerNumberSequence;
use App\Modules\Customer\Infrastructure\Models\CustomerStatus;
use App\Modules\Customer\Infrastructure\Models\CustomerStatusHistory;
use App\Modules\Customer\Infrastructure\Models\DirectSalesSource;
use App\Modules\Order\Application\Contracts\CustomerOrderGateway;
use App\Modules\Order\Application\Data\CustomerAppointmentData;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class CustomerProfileManager
{
    public function __construct(
        private BlindIndex $blindIndex,
        private AgentReferenceReader $agents,
        private CustomerOrderGateway $orders,
        private AuditRecorder $audit,
    ) {}

    public function previewCode(string $channel, int $sourceId): string
    {
        [$prefix, $digits] = $this->prefixAndDigits($channel, $sourceId);
        $lastNumber = (int) (CustomerNumberSequence::query()->where('prefix', $prefix)->value('last_number') ?? 0);

        return sprintf("%s-%0{$digits}d", $prefix, $lastNumber + 1);
    }

    /** @return array<int, int> */
    public function duplicateCandidateIds(string $contact, string $identityDocument, ?int $exceptCustomerId = null): array
    {
        $ids = [];
        foreach ([
            [CustomerContact::class, $contact],
            [CustomerIdentityDocument::class, $identityDocument],
        ] as [$model, $value]) {
            $hash = $this->blindIndex->for($value);
            if ($hash !== null) {
                /** @var class-string<CustomerContact|CustomerIdentityDocument> $model */
                $query = $model::query()->where('lookup_hash', $hash);
                if ($exceptCustomerId !== null) {
                    $query->where('customer_id', '!=', $exceptCustomerId);
                }
                $ids = [...$ids, ...$query->pluck('customer_id')->map(fn ($id): int => (int) $id)->all()];
            }
        }

        return array_values(array_unique($ids));
    }

    public function create(
        CustomerProfileData $profile,
        int $institutionId,
        CarbonImmutable $arrivalDate,
        ?string $translatorName,
        int $actorId,
        string $confirmedCode,
        bool $automaticCode,
        ?string $ipAddress,
    ): int {
        return DB::transaction(function () use (
            $profile,
            $institutionId,
            $arrivalDate,
            $translatorName,
            $actorId,
            $confirmedCode,
            $automaticCode,
            $ipAddress,
        ): int {
            [$prefix, $digits] = $this->prefixAndDigits(
                $profile->originalChannel,
                $profile->sourceAgentId ?? $profile->sourceDirectSalesId ?? 0,
            );
            $sequence = CustomerNumberSequence::query()->where('prefix', $prefix)->lockForUpdate()->first();
            if ($sequence === null) {
                CustomerNumberSequence::query()->create(['prefix' => $prefix, 'last_number' => 0]);
                $sequence = CustomerNumberSequence::query()->where('prefix', $prefix)->lockForUpdate()->firstOrFail();
            }

            $expected = sprintf("%s-%0{$digits}d", $prefix, ((int) $sequence->last_number) + 1);
            $confirmedCode = strtoupper(trim($confirmedCode));
            if ($automaticCode && $confirmedCode !== $expected) {
                throw new CustomerCodeChanged('客户编号已被其他建档占用，请确认刷新后的编号。');
            }

            if (preg_match('/^'.preg_quote($prefix, '/').'-([0-9]{'.$digits.'})$/', $confirmedCode, $matches) !== 1) {
                throw ValidationException::withMessages(['confirmedCode' => '客户编号必须符合当前来源的编号规则。']);
            }
            if (Customer::query()->where('code', $confirmedCode)->exists()) {
                throw ValidationException::withMessages(['confirmedCode' => '客户编号已存在。']);
            }

            $status = CustomerStatus::query()->where('key', 'interested')->where('is_active', true)->first();
            if ($status === null) {
                throw ValidationException::withMessages(['status' => '默认客户状态“意向”未启用，请联系超级管理员。']);
            }

            $customer = Customer::query()->create([
                'code' => $confirmedCode,
                'name' => trim($profile->name),
                'gender' => $profile->gender,
                'birth_date' => $profile->birthDate,
                'original_channel' => $profile->originalChannel,
                'source_agent_id' => $profile->sourceAgentId,
                'source_direct_sales_id' => $profile->sourceDirectSalesId,
                'current_status_id' => $status->id,
                'project_intention' => trim($profile->projectIntention),
                'owner_id' => $actorId,
                'notes' => $profile->notes,
            ]);

            $this->saveSensitiveValues($customer->id, $profile->contactValue, $profile->identityDocument);
            CustomerStatusHistory::query()->create([
                'customer_id' => $customer->id,
                'to_status_id' => $status->id,
                'changed_by' => $actorId,
                'changed_at' => now(),
                'reason' => '客户建档',
            ]);
            $this->orders->createInitialAppointment(new CustomerAppointmentData(
                customerId: $customer->id,
                institutionId: $institutionId,
                scheduledAt: $arrivalDate->startOfDay(),
                projectName: trim($profile->projectIntention),
                translatorName: $translatorName,
                ownerId: $actorId,
                notes: $profile->notes,
            ));

            $sequence->update(['last_number' => max((int) $sequence->last_number, (int) $matches[1])]);
            $this->audit->record(
                description: '创建客户档案',
                properties: ['code' => $customer->code, 'automatic_code' => $automaticCode],
                causerId: $actorId,
                subject: $customer,
                logName: 'customer',
                event: 'created',
                ipAddress: $ipAddress,
            );

            return $customer->id;
        }, 3);
    }

    public function update(
        int $customerId,
        CustomerProfileData $profile,
        int $actorId,
        bool $sensitiveChangeConfirmed,
        ?string $ipAddress,
    ): void {
        DB::transaction(function () use ($customerId, $profile, $actorId, $sensitiveChangeConfirmed, $ipAddress): void {
            $customer = Customer::query()->lockForUpdate()->findOrFail($customerId);
            $contact = CustomerContact::query()->where('customer_id', $customerId)->where('is_primary', true)->first();
            $document = CustomerIdentityDocument::query()->where('customer_id', $customerId)->first();
            $sensitiveChanged = data_get($contact, 'value_encrypted', '') !== trim($profile->contactValue)
                || data_get($document, 'number_encrypted', '') !== trim($profile->identityDocument);

            if ($sensitiveChanged && ! $sensitiveChangeConfirmed) {
                throw ValidationException::withMessages(['sensitiveConfirmation' => '请先确认敏感信息变更差异。']);
            }

            $before = $customer->only([
                'name', 'gender', 'birth_date', 'original_channel', 'source_agent_id',
                'source_direct_sales_id', 'project_intention', 'notes',
            ]);
            $customer->update([
                'name' => trim($profile->name),
                'gender' => $profile->gender,
                'birth_date' => $profile->birthDate,
                'original_channel' => $profile->originalChannel,
                'source_agent_id' => $profile->sourceAgentId,
                'source_direct_sales_id' => $profile->sourceDirectSalesId,
                'project_intention' => trim($profile->projectIntention),
                'notes' => $profile->notes,
            ]);
            $this->saveSensitiveValues($customerId, $profile->contactValue, $profile->identityDocument);
            $this->audit->record(
                description: '更新客户档案',
                properties: [
                    'before' => $before,
                    'after' => $customer->fresh()?->only(array_keys($before)),
                    'sensitive_fields_changed' => $sensitiveChanged,
                ],
                causerId: $actorId,
                subject: $customer,
                logName: 'customer',
                event: 'updated',
                ipAddress: $ipAddress,
            );
        }, 3);
    }

    private function saveSensitiveValues(int $customerId, string $contact, string $identityDocument): void
    {
        CustomerContact::query()->updateOrCreate(
            ['customer_id' => $customerId, 'is_primary' => true],
            [
                'type' => 'phone_or_wechat',
                'value_encrypted' => trim($contact),
                'lookup_hash' => $this->blindIndex->for($contact),
            ],
        );
        CustomerIdentityDocument::query()->updateOrCreate(
            ['customer_id' => $customerId, 'type' => 'passport_or_residence_card'],
            [
                'number_encrypted' => trim($identityDocument),
                'lookup_hash' => $this->blindIndex->for($identityDocument),
            ],
        );
    }

    /** @return array{string, int} */
    private function prefixAndDigits(string $channel, int $sourceId): array
    {
        if ($channel === 'agent') {
            $agent = $this->agents->agentsByIds([$sourceId])[$sourceId] ?? null;
            if ($agent === null) {
                throw ValidationException::withMessages(['sourceId' => '所选代理商不存在或不可用。']);
            }

            return [$agent['code'], 4];
        }

        $source = DirectSalesSource::query()->whereKey($sourceId)->where('is_active', true)->first();
        if ($channel !== 'direct' || $source === null) {
            throw ValidationException::withMessages(['sourceId' => '所选直销来源不存在或不可用。']);
        }

        return [$source->code, 6];
    }
}
