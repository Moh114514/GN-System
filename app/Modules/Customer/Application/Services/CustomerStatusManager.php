<?php

namespace App\Modules\Customer\Application\Services;

use App\Models\User;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Customer\Application\Contracts\ConfigurationHistoryGateway;
use App\Modules\Customer\Infrastructure\Models\Customer;
use App\Modules\Customer\Infrastructure\Models\CustomerLifecycleStage;
use App\Modules\Customer\Infrastructure\Models\CustomerStatus;
use App\Modules\Customer\Infrastructure\Models\CustomerStatusHistory;
use App\Modules\Customer\Infrastructure\Models\CustomerStatusTransition;
use App\Modules\Reminder\Application\Contracts\TreatmentReminderGateway;
use App\Modules\Reminder\Application\Data\CustomerTreatmentCompletedData;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class CustomerStatusManager
{
    public function __construct(
        private AuditRecorder $audit,
        private ConfigurationHistoryGateway $configurationHistory,
        private TreatmentReminderGateway $reminders,
    ) {}

    public function change(
        int $customerId,
        int $targetStatusId,
        string $reason,
        User $actor,
        ?string $ipAddress,
    ): void {
        DB::transaction(function () use ($customerId, $targetStatusId, $reason, $actor, $ipAddress): void {
            $customer = Customer::query()->lockForUpdate()->findOrFail($customerId);
            $current = $customer->current_status_id === null
                ? null
                : CustomerStatus::query()->findOrFail($customer->current_status_id);
            $target = CustomerStatus::query()->whereKey($targetStatusId)->where('is_active', true)->firstOrFail();

            if ($current !== null && $current->id === $target->id) {
                throw ValidationException::withMessages(['targetStatusId' => __('customers.validation.same_status')]);
            }

            $isBackward = $current !== null && $target->sort_order < $current->sort_order;
            if ($isBackward && ! $actor->is_super_admin) {
                throw ValidationException::withMessages(['targetStatusId' => __('customers.validation.rollback_requires_super_admin')]);
            }
            if ($current !== null && ! $isBackward && ! CustomerStatusTransition::query()
                ->where('from_status_id', $current->id)
                ->where('to_status_id', $target->id)
                ->where('is_active', true)
                ->exists()) {
                throw ValidationException::withMessages(['targetStatusId' => __('customers.validation.invalid_transition')]);
            }
            if (trim($reason) === '') {
                throw ValidationException::withMessages(['statusReason' => __('customers.validation.status_reason_required')]);
            }

            $completedAt = $target->key === 'treatment_completed'
                ? ($customer->treatment_completed_at?->toImmutable() ?? CarbonImmutable::now())
                : $customer->treatment_completed_at;
            $customer->update([
                'current_status_id' => $target->id,
                'treatment_completed_at' => $completedAt,
            ]);
            CustomerStatusHistory::query()->create([
                'customer_id' => $customer->id,
                'from_status_id' => $current?->id,
                'to_status_id' => $target->id,
                'changed_by' => $actor->id,
                'changed_at' => now(),
                'reason' => trim($reason),
            ]);
            $this->audit->record(
                description: '变更客户状态',
                properties: [
                    'from' => $current === null ? null : [
                        'id' => $current->id,
                        'key' => $current->key,
                        'name' => $current->name,
                    ],
                    'to' => ['id' => $target->id, 'key' => $target->key, 'name' => $target->name],
                    'reason' => trim($reason),
                ],
                causerId: $actor->id,
                subject: $customer,
                logName: 'customer',
                event: 'status_changed',
                ipAddress: $ipAddress,
            );
            if ($target->key === 'treatment_completed') {
                $this->reminders->scheduleForCustomer(new CustomerTreatmentCompletedData(
                    customerId: (int) $customer->id,
                    projectName: (string) ($customer->project_intention ?: '术后恢复'),
                    completedAt: $completedAt,
                    ownerId: $customer->owner_id === null ? null : (int) $customer->owner_id,
                    actorId: (int) $actor->id,
                ));
            }
        }, 3);
    }

    /** @return array<int, array<string, mixed>> */
    public function configuration(): array
    {
        $transitions = CustomerStatusTransition::query()->where('is_active', true)->get();

        return CustomerLifecycleStage::query()
            ->orderBy('sort_order')
            ->orderBy('key')
            ->get()
            ->map(function (CustomerLifecycleStage $stage) use ($transitions): array {
                $statuses = CustomerStatus::query()
                    ->where('stage_id', $stage->id)
                    ->orderBy('sort_order')
                    ->orderBy('key')
                    ->get();

                return [
                    'id' => $stage->id,
                    'key' => $stage->key,
                    'name' => $stage->name,
                    'sort_order' => $stage->sort_order,
                    'is_active' => $stage->is_active,
                    'statuses' => $statuses->map(fn (CustomerStatus $status): array => [
                        'id' => $status->id,
                        'key' => $status->key,
                        'name' => $status->name,
                        'sort_order' => $status->sort_order,
                        'is_active' => $status->is_active,
                        'to_status_ids' => $transitions
                            ->where('from_status_id', $status->id)
                            ->pluck('to_status_id')
                            ->map(fn ($id): int => (int) $id)
                            ->values()
                            ->all(),
                    ])->all(),
                ];
            })
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $stages
     * @param  array<int, array<string, mixed>>  $statuses
     */
    public function saveConfiguration(array $stages, array $statuses, User $actor, ?string $ipAddress): void
    {
        DB::transaction(function () use ($stages, $statuses, $actor, $ipAddress): void {
            $expectedStatusKeys = ['booked', 'arrived', 'treatment_completed'];
            $stage = CustomerLifecycleStage::query()->where('key', 'customer_lifecycle')->first();
            $canonicalStatuses = CustomerStatus::query()
                ->whereIn('key', $expectedStatusKeys)
                ->get()
                ->keyBy('key');
            $inputStage = $stages[0] ?? null;
            $inputStatuses = collect($statuses)->keyBy(static fn (array $input): string => (string) ($input['key'] ?? ''));

            if ($stage === null || $canonicalStatuses->count() !== count($expectedStatusKeys) || count($stages) !== 1 || count($statuses) !== count($expectedStatusKeys)) {
                throw ValidationException::withMessages(['configuration' => __('customers.validation.lifecycle_structure_locked')]);
            }
            if ((int) ($inputStage['id'] ?? 0) !== $stage->id
                || ($inputStage['key'] ?? null) !== $stage->key
                || trim((string) ($inputStage['name'] ?? '')) === '') {
                throw ValidationException::withMessages(['configuration' => __('customers.validation.lifecycle_structure_locked')]);
            }
            foreach ($expectedStatusKeys as $key) {
                $canonicalStatus = $canonicalStatuses->get($key);
                $input = $inputStatuses->get($key);
                if ($canonicalStatus === null
                    || ! is_array($input)
                    || (int) ($input['id'] ?? 0) !== $canonicalStatus->id
                    || (int) ($input['stage_id'] ?? 0) !== $stage->id
                    || trim((string) ($input['name'] ?? '')) === '') {
                    throw ValidationException::withMessages(['configuration' => __('customers.validation.lifecycle_structure_locked')]);
                }
            }

            $this->configurationHistory->capture((int) $actor->id);
            $stage->update(['name' => trim((string) $inputStage['name'])]);
            foreach ($expectedStatusKeys as $key) {
                $input = $inputStatuses->get($key);
                CustomerStatus::query()->whereKey((int) $input['id'])->update(['name' => trim((string) $input['name'])]);
            }

            $this->audit->record(
                description: '更新客户状态配置',
                properties: ['stage_count' => count($stages), 'status_count' => count($statuses), 'structure_locked' => true],
                causerId: $actor->id,
                logName: 'customer-configuration',
                event: 'updated',
                ipAddress: $ipAddress,
            );
        }, 3);
    }
}
