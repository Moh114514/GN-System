<?php

namespace App\Modules\Customer\Application\Services;

use App\Models\User;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Customer\Infrastructure\Models\Customer;
use App\Modules\Customer\Infrastructure\Models\CustomerLifecycleStage;
use App\Modules\Customer\Infrastructure\Models\CustomerStatus;
use App\Modules\Customer\Infrastructure\Models\CustomerStatusHistory;
use App\Modules\Customer\Infrastructure\Models\CustomerStatusTransition;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class CustomerStatusManager
{
    public function __construct(private AuditRecorder $audit) {}

    public function change(
        int $customerId,
        int $targetStatusId,
        string $reason,
        User $actor,
        ?string $ipAddress,
    ): void {
        DB::transaction(function () use ($customerId, $targetStatusId, $reason, $actor, $ipAddress): void {
            $customer = Customer::query()->lockForUpdate()->findOrFail($customerId);
            $current = CustomerStatus::query()->findOrFail($customer->current_status_id);
            $target = CustomerStatus::query()->whereKey($targetStatusId)->where('is_active', true)->firstOrFail();

            if ($current->id === $target->id) {
                throw ValidationException::withMessages(['targetStatusId' => '目标状态与当前状态相同。']);
            }

            $isBackward = $target->sort_order < $current->sort_order;
            if ($isBackward && ! $actor->is_super_admin) {
                throw ValidationException::withMessages(['targetStatusId' => '只有超级管理员可以回退客户状态。']);
            }
            if (! $isBackward && ! CustomerStatusTransition::query()
                ->where('from_status_id', $current->id)
                ->where('to_status_id', $target->id)
                ->where('is_active', true)
                ->exists()) {
                throw ValidationException::withMessages(['targetStatusId' => '不能越级或使用未启用的状态流转。']);
            }
            if (trim($reason) === '') {
                throw ValidationException::withMessages(['statusReason' => '请填写状态变更原因。']);
            }

            $customer->update(['current_status_id' => $target->id]);
            CustomerStatusHistory::query()->create([
                'customer_id' => $customer->id,
                'from_status_id' => $current->id,
                'to_status_id' => $target->id,
                'changed_by' => $actor->id,
                'changed_at' => now(),
                'reason' => trim($reason),
            ]);
            $this->audit->record(
                description: '变更客户状态',
                properties: [
                    'from' => ['id' => $current->id, 'key' => $current->key, 'name' => $current->name],
                    'to' => ['id' => $target->id, 'key' => $target->key, 'name' => $target->name],
                    'reason' => trim($reason),
                ],
                causerId: $actor->id,
                subject: $customer,
                logName: 'customer',
                event: 'status_changed',
                ipAddress: $ipAddress,
            );
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
     * @param  array<int, array{id: int, name: string, sort_order: int, is_active: bool}>  $stages
     * @param  array<int, array{id: int, name: string, stage_id: int, sort_order: int, is_active: bool, to_status_ids: array<int, int>}>  $statuses
     */
    public function saveConfiguration(array $stages, array $statuses, User $actor, ?string $ipAddress): void
    {
        DB::transaction(function () use ($stages, $statuses, $actor, $ipAddress): void {
            foreach ($stages as $input) {
                CustomerLifecycleStage::query()->whereKey($input['id'])->update([
                    'name' => trim($input['name']),
                    'sort_order' => $input['sort_order'],
                    'is_active' => $input['is_active'],
                ]);
            }

            $defaultStatus = CustomerStatus::query()->where('key', 'interested')->firstOrFail();
            foreach ($statuses as $input) {
                if ($input['id'] === $defaultStatus->id && ! $input['is_active']) {
                    throw ValidationException::withMessages(['configuration' => '默认状态“意向”不能停用。']);
                }
                CustomerStatus::query()->whereKey($input['id'])->update([
                    'name' => trim($input['name']),
                    'stage_id' => $input['stage_id'],
                    'sort_order' => $input['sort_order'],
                    'is_active' => $input['is_active'],
                ]);
            }

            CustomerStatusTransition::query()->update(['is_active' => false]);
            $activeStatusIds = CustomerStatus::query()->where('is_active', true)->pluck('id')->map(fn ($id): int => (int) $id)->all();
            foreach ($statuses as $input) {
                foreach (array_unique($input['to_status_ids']) as $toStatusId) {
                    if ($input['id'] === $toStatusId
                        || ! in_array($input['id'], $activeStatusIds, true)
                        || ! in_array($toStatusId, $activeStatusIds, true)) {
                        continue;
                    }
                    CustomerStatusTransition::query()->updateOrCreate(
                        ['from_status_id' => $input['id'], 'to_status_id' => $toStatusId],
                        ['is_active' => true],
                    );
                }
            }

            $this->audit->record(
                description: '更新客户状态配置',
                properties: ['stage_count' => count($stages), 'status_count' => count($statuses)],
                causerId: $actor->id,
                logName: 'customer-configuration',
                event: 'updated',
                ipAddress: $ipAddress,
            );
        }, 3);
    }
}
