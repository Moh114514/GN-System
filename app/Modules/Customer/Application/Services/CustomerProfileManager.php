<?php

namespace App\Modules\Customer\Application\Services;

use App\Infrastructure\Time\BusinessClock;
use App\Models\User;
use App\Modules\Agent\Application\Contracts\AgentReferenceReader;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Auth\Application\Contracts\AccessContextResolver;
use App\Modules\Auth\Application\Contracts\BusinessGroupMembershipReader;
use App\Modules\Auth\Application\Contracts\InternalUserReferenceReader;
use App\Modules\Customer\Application\Data\CustomerProfileData;
use App\Modules\Customer\Application\Exceptions\CustomerCodeChanged;
use App\Modules\Customer\Domain\BlindIndex;
use App\Modules\Customer\Infrastructure\Models\Customer;
use App\Modules\Customer\Infrastructure\Models\CustomerContact;
use App\Modules\Customer\Infrastructure\Models\CustomerIdentityDocument;
use App\Modules\Customer\Infrastructure\Models\CustomerNumberSequence;
use App\Modules\Customer\Infrastructure\Models\CustomerOwnerHistory;
use App\Modules\Customer\Infrastructure\Models\CustomerStatus;
use App\Modules\Customer\Infrastructure\Models\CustomerStatusHistory;
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
        private InternalUserReferenceReader $users,
        private CustomerOrderGateway $orders,
        private AuditRecorder $audit,
        private BusinessClock $clock,
        private AccessContextResolver $access,
        private BusinessGroupMembershipReader $memberships,
    ) {}

    public function previewCode(int $sourceAgentId): string
    {
        [$prefix, $digits] = $this->prefixAndDigits($sourceAgentId);
        $lastNumber = max(
            (int) (CustomerNumberSequence::query()->where('prefix', $prefix)->value('last_number') ?? 0),
            $this->maxCustomerNumber($prefix),
        );

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
        CarbonImmutable $arrivalAt,
        ?string $translatorName,
        int $actorId,
        int $ownerId,
        string $confirmedCode,
        bool $automaticCode,
        ?string $ipAddress,
    ): int {
        return DB::transaction(function () use (
            $profile,
            $institutionId,
            $arrivalAt,
            $translatorName,
            $actorId,
            $ownerId,
            $confirmedCode,
            $automaticCode,
            $ipAddress,
        ): int {
            $context = $this->access->forUser(User::query()->findOrFail($actorId));
            if (! $context->isSuperAdmin()
                && (! $context->isCustomerService() || $context->agentIds !== [])
                && ! $context->canViewAgent($profile->sourceAgentId)) {
                throw ValidationException::withMessages(['sourceId' => __('customers.form.validation.agent_unavailable')]);
            }
            if (! $context->isSuperAdmin()
                && $context->groupUserIds !== []
                && ! in_array($ownerId, $context->groupUserIds, true)
                && $ownerId !== $context->userId) {
                throw ValidationException::withMessages(['ownerId' => __('customers.form.validation.owner_unavailable')]);
            }
            $groupIds = $context->isSuperAdmin() || $context->businessGroupIds === []
                ? null
                : $context->businessGroupIds;
            if (! $this->users->isEligible($ownerId)
                || ! $this->memberships->isActiveCustomerServiceInGroups($ownerId, $groupIds, $this->clock->now()->toDateString())) {
                throw ValidationException::withMessages([
                    'ownerId' => __('customers.form.validation.owner_unavailable'),
                ]);
            }

            [$prefix, $digits] = $this->prefixAndDigits($profile->sourceAgentId);
            CustomerNumberSequence::query()->insertOrIgnore([
                'prefix' => $prefix,
                'last_number' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $sequence = CustomerNumberSequence::query()->where('prefix', $prefix)->lockForUpdate()->firstOrFail();

            $lastNumber = max((int) $sequence->last_number, $this->maxCustomerNumber($prefix));
            $expected = sprintf("%s-%0{$digits}d", $prefix, $lastNumber + 1);
            $confirmedCode = strtoupper(trim($confirmedCode));
            if ($automaticCode && $confirmedCode !== $expected) {
                throw new CustomerCodeChanged(__('customers.form.validation.code_changed'));
            }

            if (preg_match('/^'.preg_quote($prefix, '/').'-([0-9]{'.$digits.'})$/', $confirmedCode, $matches) !== 1) {
                throw ValidationException::withMessages(['confirmedCode' => __('customers.form.validation.code_format')]);
            }
            if (Customer::query()->where('code', $confirmedCode)->exists()) {
                throw ValidationException::withMessages(['confirmedCode' => __('customers.form.validation.code_exists')]);
            }

            $status = CustomerStatus::query()->where('key', 'booked')->where('is_active', true)->first();
            if ($status === null) {
                throw ValidationException::withMessages(['status' => __('customers.form.validation.default_status_inactive')]);
            }

            $customer = Customer::query()->create([
                'code' => $confirmedCode,
                'name' => trim($profile->name),
                'gender' => $profile->gender,
                'birth_date' => $profile->birthDate,
                'source_agent_id' => $profile->sourceAgentId,
                'current_status_id' => $status->id,
                'project_intention' => trim($profile->projectIntention),
                'owner_id' => $ownerId,
                'notes' => $profile->notes,
            ]);

            $this->saveSensitiveValues($customer->id, $profile->contactValue, $profile->identityDocument);
            CustomerStatusHistory::query()->create([
                'customer_id' => $customer->id,
                'to_status_id' => $status->id,
                'changed_by' => $actorId,
                'changed_at' => $this->clock->now(),
                'reason' => '客户建档',
            ]);
            CustomerOwnerHistory::query()->create([
                'customer_id' => $customer->id,
                'business_group_id' => $context->businessGroupIds[0] ?? null,
                'from_owner_id' => null,
                'to_owner_id' => $ownerId,
                'source' => 'initial',
                'changed_by' => $actorId,
                'reason' => '客户建档',
                'effective_at' => $this->clock->now(),
            ]);
            $this->orders->createInitialAppointment(new CustomerAppointmentData(
                customerId: $customer->id,
                institutionId: $institutionId,
                scheduledAt: $arrivalAt,
                projectName: trim($profile->projectIntention),
                translatorName: $translatorName,
                ownerId: $ownerId,
                notes: $profile->notes,
            ));

            $sequence->update(['last_number' => max($lastNumber, (int) $matches[1])]);
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
            $context = $this->access->forUser(User::query()->findOrFail($actorId));
            $customer = Customer::query()->lockForUpdate()->findOrFail($customerId);
            abort_unless($context->canViewCustomer(
                $customer->source_agent_id === null ? null : (int) $customer->source_agent_id,
                $customer->owner_id === null ? null : (int) $customer->owner_id,
            ), 404);
            abort_unless(
                $context->isSuperAdmin()
                || $context->isBdManager()
                || ($context->isCustomerService() && (int) $customer->owner_id === (int) $actorId),
                403,
            );
            if (! $context->isSuperAdmin()
                && (! $context->isCustomerService() || $context->agentIds !== [])
                && ! $context->canViewAgent($profile->sourceAgentId)) {
                throw ValidationException::withMessages(['sourceId' => __('customers.form.validation.agent_unavailable')]);
            }
            $contact = CustomerContact::query()->where('customer_id', $customerId)->where('is_primary', true)->first();
            $document = CustomerIdentityDocument::query()->where('customer_id', $customerId)->first();
            $sensitiveChanged = data_get($contact, 'value_encrypted', '') !== trim($profile->contactValue)
                || data_get($document, 'number_encrypted', '') !== trim($profile->identityDocument);

            if ($sensitiveChanged && ! $context->canDownloadSensitiveCustomerData($customer->owner_id === null ? null : (int) $customer->owner_id)) {
                throw ValidationException::withMessages(['sensitiveConfirmation' => __('customers.form.validation.sensitive_confirmation_required')]);
            }

            if ($sensitiveChanged && ! $sensitiveChangeConfirmed) {
                throw ValidationException::withMessages(['sensitiveConfirmation' => __('customers.form.validation.sensitive_confirmation_required')]);
            }

            $before = $customer->only([
                'name', 'gender', 'birth_date', 'source_agent_id', 'project_intention', 'notes',
            ]);
            $customer->update([
                'name' => trim($profile->name),
                'gender' => $profile->gender,
                'birth_date' => $profile->birthDate,
                'source_agent_id' => $profile->sourceAgentId,
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

    private function maxCustomerNumber(string $prefix): int
    {
        $prefixPattern = '^'.preg_quote($prefix, '/').'-';
        $codePattern = $prefixPattern.'\d+$';

        return (int) (Customer::query()
            ->whereRaw('code ~ ?', [$codePattern])
            ->selectRaw("MAX(CAST(regexp_replace(code, ?, '') AS BIGINT)) AS max_number", [$prefixPattern])
            ->value('max_number') ?? 0);
    }

    /** @return array{string, int} */
    private function prefixAndDigits(int $sourceId): array
    {
        $agent = $this->agents->agentsByIds([$sourceId])[$sourceId] ?? null;
        if ($agent === null) {
            throw ValidationException::withMessages(['sourceId' => __('customers.form.validation.agent_unavailable')]);
        }

        return [$agent['code'], 4];
    }
}
